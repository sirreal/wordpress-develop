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

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-result-store-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$db_path = $work_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME;

$store = new \HtmlApiFuzz\ResultStore( $db_path );

$pass_summary = array(
	'kind'              => 'attempt',
	'ok'                => true,
	'status'            => 'passed',
	'failureClass'      => null,
	'seed'              => 11,
	'profile'           => 'document',
	'mode'              => 'document',
	'payloadPolicy'     => 'utf8',
	'inputSource'       => 'generated',
	'inputSha1'         => sha1( 'pass' ),
	'inputLength'       => 4,
	'signature'         => null,
	'artifactsRetained' => false,
	'resultPath'        => null,
	'replayPath'        => null,
	'logPath'           => null,
	'durationMs'        => 12,
	'workerCode'        => 0,
	'workerTimedOut'    => false,
);
$store->record_attempt( $pass_summary );

$failure_summary = array(
	'kind'              => 'failure',
	'ok'                => false,
	'status'            => 'failed',
	'failureClass'      => 'tree-mismatch',
	'seed'              => 12,
	'profile'           => 'document',
	'mode'              => 'document',
	'payloadPolicy'     => 'utf8',
	'inputSource'       => 'generated',
	'inputSha1'         => sha1( 'fail' ),
	'inputLength'       => 8,
	'signature'         => array(
		'hash'      => 'abc123def456',
		'familyKey' => 'fam456789abc',
	),
	'artifactsRetained' => true,
	'resultPath'        => $work_dir . '/seed-12/primary/result.json',
	'replayPath'        => $work_dir . '/seed-12/primary/replay.json',
	'logPath'           => null,
	'durationMs'        => 30,
	'workerCode'        => 2,
	'workerTimedOut'    => false,
);
$failure_result = array(
	'ok'           => false,
	'status'       => 'failed',
	'failureClass' => 'tree-mismatch',
	'signature'    => array( 'hash' => 'abc123def456' ),
);
$failure_replay = array(
	'kind'        => 'html-api-fuzz-replay',
	'seed'        => 12,
	'inputBase64' => base64_encode( '<i>fail</i>' ),
);
$store->record_attempt( $failure_summary, $failure_result, $failure_replay );

$pruned_summary                      = $failure_summary;
$pruned_summary['seed']              = 13;
$pruned_summary['artifactsRetained'] = false;
$pruned_summary['resultPath']        = null;
$pruned_summary['replayPath']        = null;
$store->record_attempt( $pruned_summary, $failure_result, $failure_replay );

html_api_fuzz_smoke_assert( 3 === $store->count_attempts(), 'Expected three recorded attempts.' );
html_api_fuzz_smoke_assert( array( 12 ) === $store->retained_seeds( 'abc123def456' ), 'Expected seed 12 as the retained exemplar for the signature.' );
html_api_fuzz_smoke_assert( array() === $store->retained_seeds( 'unseen' ), 'Expected no retained exemplars for an unseen signature.' );
html_api_fuzz_smoke_assert( $store->seed_artifacts_retained( 12 ), 'Expected seed 12 to be marked as retained.' );
html_api_fuzz_smoke_assert( ! $store->seed_artifacts_retained( 13 ), 'Expected seed 13 not to be marked as retained.' );
html_api_fuzz_smoke_assert( 3 === $store->max_id(), 'Expected max id of three.' );

$stored_replay = $store->replay_for_seed( 13 );
html_api_fuzz_smoke_assert( is_array( $stored_replay ) && base64_encode( '<i>fail</i>' ) === ( $stored_replay['inputBase64'] ?? null ), 'Expected the pruned failure replay to be retrievable from the store.' );
html_api_fuzz_smoke_assert( null === $store->replay_for_seed( 11 ), 'Expected no stored replay for a passing seed.' );

$failures = $store->failures_after( 0, $store->max_id() );
html_api_fuzz_smoke_assert( 2 === count( $failures ), 'Expected two failure rows.' );
html_api_fuzz_smoke_assert( 12 === ( $failures[0]['record']['seed'] ?? null ), 'Expected the first failure record to be seed 12.' );
html_api_fuzz_smoke_assert( 'abc123def456' === ( $failures[0]['record']['signature']['hash'] ?? null ), 'Expected the failure record to carry its signature.' );

$tail = $store->failures_after( $failures[0]['id'], $store->max_id() );
html_api_fuzz_smoke_assert( 1 === count( $tail ) && 13 === ( $tail[0]['record']['seed'] ?? null ), 'Expected incremental reads to resume after an offset.' );

$store->close();

// Reopen read-only as the watcher does and confirm persistence.
$reader = new \HtmlApiFuzz\ResultStore( $db_path, true );
html_api_fuzz_smoke_assert( 3 === $reader->count_attempts(), 'Expected attempts to persist across reopen.' );
html_api_fuzz_smoke_assert( 2 === count( $reader->failures_after( 0, $reader->max_id() ) ), 'Expected failures to persist across reopen.' );
$reader->close();

// The grouping columns must be queryable without json_extract.
$raw = new SQLite3( $db_path, SQLITE3_OPEN_READONLY );
html_api_fuzz_smoke_assert( 2 === (int) $raw->querySingle( "SELECT COUNT(*) FROM attempts WHERE family_key = 'fam456789abc'" ), 'Expected family_key to be stored per failure row.' );
$raw->close();

\HtmlApiFuzz\remove_dir_recursive( $work_dir );
html_api_fuzz_smoke_assert( ! is_dir( $work_dir ), 'Expected remove_dir_recursive to delete the work directory.' );

echo "OK result-store-smoke\n";
