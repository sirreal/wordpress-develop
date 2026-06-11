#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_smoke_fail( $message );
	}
}

$watcher   = dirname( __DIR__ ) . '/watcher.php';
$repo_root = \HtmlApiFuzz\repo_root();
$work_dir  = sys_get_temp_dir() . '/html-api-fuzz-watcher-sqlite-' . \HtmlApiFuzz\timestamp();
$run_dir   = $work_dir . '/run';
$lane_dir  = $run_dir . '/lane-00';
\HtmlApiFuzz\ensure_dir( $lane_dir );

// Fabricate a lane store containing one failure, as a runner lane would write.
$store   = new \HtmlApiFuzz\ResultStore( $lane_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME );
$summary = array(
	'kind'              => 'failure',
	'ok'                => false,
	'status'            => 'failed',
	'failureClass'      => 'tree-mismatch',
	'seed'              => 7,
	'profile'           => 'document',
	'mode'              => 'document',
	'payloadPolicy'     => 'utf8',
	'generator'         => array( 'features' => array( 'tables' ) ),
	'inputSource'       => 'generated',
	'inputSha1'         => sha1( 'watcher' ),
	'inputLength'       => 16,
	'signature'         => array( 'hash' => 'feedfacecafe' ),
	'artifactsRetained' => false,
	'resultPath'        => null,
	'replayPath'        => null,
	'logPath'           => null,
	'durationMs'        => 21,
	'workerCode'        => 2,
	'workerTimedOut'    => false,
);
$store->record_attempt( $summary, array( 'ok' => false ), array( 'seed' => 7 ) );
$oracle_summary = array(
	'kind'              => 'oracle-finding',
	'ok'                => true,
	'status'            => 'oracle-tolerated',
	'failureClass'      => 'oracle-tolerated',
	'seed'              => 8,
	'profile'           => 'document',
	'mode'              => 'document',
	'payloadPolicy'     => 'utf8',
	'generator'         => array( 'features' => array( 'foreign-content' ) ),
	'inputSource'       => 'generated',
	'inputSha1'         => sha1( 'oracle-watcher' ),
	'inputLength'       => 12,
	'signature'         => null,
	'oracleFinding'     => array(
		'classification' => 'oracle-bug',
		'type'           => 'dom-xlink-dropped-local-name-after-xlink',
		'suspectedOwner' => 'Lexbor/PHP DOM',
		'upstream'       => array(
			'issueUrl' => 'https://github.com/lexbor/lexbor/issues/372',
		),
		'signature'      => array(
			'hash'      => 'oracle-feedface',
			'familyKey' => 'oracle-family',
		),
	),
	'artifactsRetained' => false,
	'resultPath'        => null,
	'replayPath'        => null,
	'logPath'           => null,
	'durationMs'        => 22,
	'workerCode'        => 0,
	'workerTimedOut'    => false,
);
$store->record_attempt( $oracle_summary, array( 'ok' => true, 'oracleFinding' => $oracle_summary['oracleFinding'] ), array( 'seed' => 8 ) );
$store->close();

// A stopped runner state lets the watcher treat its STOP-file scan as final.
\HtmlApiFuzz\write_json_file(
	$lane_dir . '/state.json',
	array(
		'kind'       => 'html-api-fuzz-runner-state',
		'stopReason' => 'stop-requested',
	)
);
file_put_contents( $run_dir . '/STOP', "{}\n" );

// A second lane with a corrupt store (e.g. truncated by a crashed lane, or a
// schemaless file from a lane killed mid-initialization) must not kill the
// watcher or block ingestion from healthy lanes.
$corrupt_lane = $run_dir . '/lane-01';
\HtmlApiFuzz\ensure_dir( $corrupt_lane );
file_put_contents( $corrupt_lane . '/' . \HtmlApiFuzz\ResultStore::FILENAME, 'this is not a sqlite database' );
\HtmlApiFuzz\write_json_file(
	$corrupt_lane . '/state.json',
	array(
		'kind'       => 'html-api-fuzz-runner-state',
		'stopReason' => 'stop-requested',
	)
);

// A crashed lane never records a stop reason; once its state goes stale it
// must be presumed dead instead of blocking the watcher's exit forever.
$dead_lane = $run_dir . '/lane-02';
\HtmlApiFuzz\ensure_dir( $dead_lane );
\HtmlApiFuzz\write_json_file(
	$dead_lane . '/state.json',
	array(
		'kind'       => 'html-api-fuzz-runner-state',
		'stopReason' => null,
		'updatedAt'  => gmdate( 'c', time() - 3600 ),
	)
);

$triage_dir = $run_dir . '/triage';
$proc       = \HtmlApiFuzz\run_php_process(
	array(
		$watcher,
		'--run-dir',
		$run_dir,
		'--state-dir',
		$triage_dir,
		'--no-minimize',
		'--stop-stale-seconds',
		'10',
		// No --once: exiting depends on STOP-file handling.
	),
	$repo_root,
	30000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'] && ! $proc['timedOut'], 'Expected watcher to exit via the stop request: ' . substr( $proc['output'], -1000 ) );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'presuming dead runner' ), 'Expected the stale lane to be presumed dead.' );

$triage_state = \HtmlApiFuzz\read_json_file( $triage_dir . '/state.json' );
html_api_fuzz_smoke_assert( isset( $triage_state['signatures']['feedfacecafe'] ), 'Expected the sqlite failure signature to be triaged.' );
html_api_fuzz_smoke_assert( 1 === (int) ( $triage_state['signatures']['feedfacecafe']['seenCount'] ?? 0 ), 'Expected the failure to be seen exactly once.' );
html_api_fuzz_smoke_assert( ! isset( $triage_state['signatures']['oracle-feedface'] ), 'Expected oracle findings to be ignored without --triage-oracle-findings.' );

// A later opt-in scan must still ingest oracle findings even though the
// default failure offset already advanced past the row.
$proc = \HtmlApiFuzz\run_php_process(
	array( $watcher, '--run-dir', $run_dir, '--state-dir', $triage_dir, '--no-minimize', '--triage-oracle-findings' ),
	$repo_root,
	30000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected second watcher pass to exit cleanly.' );
$second_scan = json_decode( $proc['stdout'], true );
html_api_fuzz_smoke_assert( 0 === ( $second_scan['failuresSeen'] ?? null ), 'Expected the second watcher pass not to reread failures.' );
html_api_fuzz_smoke_assert( 1 === ( $second_scan['oracleFindingsSeen'] ?? null ), 'Expected the second watcher pass to ingest the oracle finding.' );
$triage_state = \HtmlApiFuzz\read_json_file( $triage_dir . '/state.json' );
html_api_fuzz_smoke_assert( 1 === (int) ( $triage_state['signatures']['feedfacecafe']['seenCount'] ?? 0 ), 'Expected no duplicate ingestion across scans.' );
html_api_fuzz_smoke_assert( 1 === (int) ( $triage_state['signatures']['oracle-feedface']['seenCount'] ?? 0 ), 'Expected the oracle finding to be seen exactly once.' );
html_api_fuzz_smoke_assert( 'oracle-finding' === ( $triage_state['signatures']['oracle-feedface']['triageKind'] ?? null ), 'Expected oracle finding triage kind.' );
html_api_fuzz_smoke_assert( 'Lexbor/PHP DOM' === ( $triage_state['signatures']['oracle-feedface']['suspectedOwner'] ?? null ), 'Expected oracle finding suspected owner to be preserved.' );

// A third scan with oracle triage enabled must not re-ingest the same finding.
$proc = \HtmlApiFuzz\run_php_process(
	array( $watcher, '--run-dir', $run_dir, '--state-dir', $triage_dir, '--no-minimize', '--triage-oracle-findings' ),
	$repo_root,
	30000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected third watcher pass to exit cleanly.' );
$third_scan = json_decode( $proc['stdout'], true );
html_api_fuzz_smoke_assert( 0 === ( $third_scan['oracleFindingsSeen'] ?? null ), 'Expected no duplicate oracle ingestion across scans.' );

$summary_run_dir = $work_dir . '/summary-run';
\HtmlApiFuzz\ensure_dir( $summary_run_dir );
$summary_state_dir = $work_dir . '/summary-triage';
$summary_record = $oracle_summary;
$summary_record['resultPath'] = null;
$summary_record['replayPath'] = null;
$summary_record['oracleFinding']['signature']['hash'] = 'oracle-summary-one';
\HtmlApiFuzz\append_ndjson( $summary_run_dir . '/summary.ndjson', $summary_record );
$proc = \HtmlApiFuzz\run_php_process(
	array( $watcher, '--run-dir', $summary_run_dir, '--state-dir', $summary_state_dir, '--no-minimize', '--triage-oracle-findings', '--once' ),
	$repo_root,
	30000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected summary watcher pass to exit cleanly.' );
$summary_scan = json_decode( $proc['stdout'], true );
html_api_fuzz_smoke_assert( 1 === ( $summary_scan['oracleFindingsSeen'] ?? null ), 'Expected first pathless summary oracle finding to be ingested.' );

$summary_record['seed'] = 9;
$summary_record['oracleFinding']['signature']['hash'] = 'oracle-summary-two';
\HtmlApiFuzz\append_ndjson( $summary_run_dir . '/summary.ndjson', $summary_record );
$proc = \HtmlApiFuzz\run_php_process(
	array( $watcher, '--run-dir', $summary_run_dir, '--state-dir', $summary_state_dir, '--no-minimize', '--triage-oracle-findings', '--once' ),
	$repo_root,
	30000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected second summary watcher pass to exit cleanly.' );
$summary_scan = json_decode( $proc['stdout'], true );
html_api_fuzz_smoke_assert( 1 === ( $summary_scan['oracleFindingsSeen'] ?? null ), 'Expected appended pathless summary oracle finding to avoid fallback-key collision.' );
$summary_state = \HtmlApiFuzz\read_json_file( $summary_state_dir . '/state.json' );
html_api_fuzz_smoke_assert( isset( $summary_state['signatures']['oracle-summary-one'], $summary_state['signatures']['oracle-summary-two'] ), 'Expected both pathless summary oracle signatures to be triaged.' );

\HtmlApiFuzz\remove_dir_recursive( $work_dir );

echo "OK watcher-sqlite-smoke\n";
