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

$runner    = dirname( __DIR__ ) . '/runner.php';
$stop_tool = dirname( __DIR__ ) . '/stop.php';
$replay    = dirname( __DIR__ ) . '/replay.php';
$repo_root = \HtmlApiFuzz\repo_root();
$work_dir  = sys_get_temp_dir() . '/html-api-fuzz-runner-retention-' . \HtmlApiFuzz\timestamp();

/*
 * 1. A short bounded run: every attempt must land in results.sqlite, and
 * seed directories may remain on disk only for retained failure exemplars.
 */
$run_dir = $work_dir . '/run';
$proc    = \HtmlApiFuzz\run_php_process(
	array(
		$runner,
		'--output-dir',
		$run_dir,
		'--max-seeds',
		'6',
		'--duration-seconds',
		'0',
		'--batch-size',
		'3',
		'--max-input-bytes',
		'512',
	),
	$repo_root,
	180000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected runner to exit cleanly: ' . substr( $proc['output'], -1000 ) );

$state = \HtmlApiFuzz\read_json_file( $run_dir . '/state.json' );
html_api_fuzz_smoke_assert( 'max-seeds' === ( $state['stopReason'] ?? null ), 'Expected stopReason max-seeds.' );

$db_path = $run_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME;
html_api_fuzz_smoke_assert( is_file( $db_path ), 'Expected results.sqlite to exist.' );

$db = new SQLite3( $db_path, SQLITE3_OPEN_READONLY );
html_api_fuzz_smoke_assert( 6 === (int) $db->querySingle( 'SELECT COUNT(*) FROM attempts' ), 'Expected six recorded attempts.' );
html_api_fuzz_smoke_assert( 0 === (int) $db->querySingle( 'SELECT COUNT(*) FROM attempts WHERE ok = 1 AND artifacts_retained = 1' ), 'Expected no retained artifacts for passing attempts.' );
$rows = $db->query( 'SELECT seed, artifacts_retained FROM attempts' );
while ( false !== ( $row = $rows->fetchArray( SQLITE3_ASSOC ) ) ) {
	html_api_fuzz_smoke_assert(
		is_dir( $run_dir . '/seed-' . $row['seed'] ) === (bool) $row['artifacts_retained'],
		"Expected seed {$row['seed']} directory presence to match artifacts_retained={$row['artifacts_retained']}."
	);
}
$db->close();

/*
 * 2. The failure path, deterministically: --fail-unsupported turns the many
 * unsupported fragment contexts into failures with repeating signatures, so
 * a cap of 1 must prune repeats while archiving their replay documents.
 */
$cap          = 1;
$fail_run_dir = $work_dir . '/fail-run';
$proc         = \HtmlApiFuzz\run_php_process(
	array(
		$runner,
		'--output-dir',
		$fail_run_dir,
		'--max-seeds',
		'40',
		'--duration-seconds',
		'0',
		'--batch-size',
		'10',
		'--max-input-bytes',
		'512',
		'--fail-unsupported',
		'--max-keep-per-signature',
		(string) $cap,
	),
	$repo_root,
	300000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected failure-path runner to exit cleanly: ' . substr( $proc['output'], -1000 ) );

$db = new SQLite3( $fail_run_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME, SQLITE3_OPEN_READONLY );
// Precondition guard: this run must actually exercise pruning. If generator
// or signature changes stop producing repeated signatures here, fail loudly
// so the test can be re-tuned instead of silently going vacuous.
$pruned_failures = (int) $db->querySingle( 'SELECT COUNT(*) FROM attempts WHERE ok = 0 AND artifacts_retained = 0' );
html_api_fuzz_smoke_assert( $pruned_failures > 0, 'Expected the failure-path run to prune at least one over-cap failure; re-tune the seed range.' );
html_api_fuzz_smoke_assert( 0 === (int) $db->querySingle( 'SELECT COUNT(*) FROM attempts WHERE ok = 0 AND summary_json IS NULL' ), 'Expected failures to store their summary JSON.' );
html_api_fuzz_smoke_assert( 0 === (int) $db->querySingle( 'SELECT COUNT(*) FROM attempts WHERE ok = 0 AND artifacts_retained = 0 AND replay_json IS NULL' ), 'Expected pruned failures to archive their replay JSON.' );

$max_retained_per_signature = (int) $db->querySingle(
	'SELECT COALESCE( MAX( n ), 0 ) FROM ( SELECT COUNT(*) AS n FROM attempts WHERE artifacts_retained = 1 AND signature_hash IS NOT NULL GROUP BY signature_hash )'
);
html_api_fuzz_smoke_assert( $max_retained_per_signature <= $cap, 'Expected retained exemplars per signature to respect the cap.' );

// Every signature with failures keeps its first exemplar on disk.
$sig_rows = $db->query( 'SELECT signature_hash, MAX(artifacts_retained) AS retained FROM attempts WHERE ok = 0 AND signature_hash IS NOT NULL GROUP BY signature_hash' );
while ( false !== ( $row = $sig_rows->fetchArray( SQLITE3_ASSOC ) ) ) {
	html_api_fuzz_smoke_assert( 1 === (int) $row['retained'], "Expected signature {$row['signature_hash']} to retain its first exemplar." );
}

$rows = $db->query( 'SELECT seed, artifacts_retained FROM attempts' );
while ( false !== ( $row = $rows->fetchArray( SQLITE3_ASSOC ) ) ) {
	html_api_fuzz_smoke_assert(
		is_dir( $fail_run_dir . '/seed-' . $row['seed'] ) === (bool) $row['artifacts_retained'],
		"Expected seed {$row['seed']} directory presence to match artifacts_retained={$row['artifacts_retained']}."
	);
}

$pruned = $db->querySingle( 'SELECT seed, signature_hash FROM attempts WHERE ok = 0 AND artifacts_retained = 0 LIMIT 1', true );
$db->close();

// A pruned failure must be reproducible from the store alone.
$proc = \HtmlApiFuzz\run_php_process(
	array(
		$replay,
		'--store',
		$fail_run_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME,
		'--seed',
		(string) $pruned['seed'],
		'--output-dir',
		$work_dir . '/store-replay',
	),
	$repo_root,
	60000
);
$replay_report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( is_array( $replay_report ), 'Expected replay --store to produce a JSON report: ' . substr( $proc['output'], -1000 ) );
html_api_fuzz_smoke_assert( false === ( $replay_report['ok'] ?? true ), 'Expected the store replay to reproduce a failure.' );
html_api_fuzz_smoke_assert(
	( $replay_report['signature']['hash'] ?? null ) === $pruned['signature_hash'],
	'Expected the store replay to reproduce the original signature.'
);

/*
 * 3. A pre-existing stop file must refuse to start rather than silently
 * succeed with zero seeds.
 */
$stop_run_dir = $work_dir . '/stop-run';
\HtmlApiFuzz\ensure_dir( $stop_run_dir );
file_put_contents( $stop_run_dir . '/STOP', "{}\n" );
$proc = \HtmlApiFuzz\run_php_process(
	array( $runner, '--output-dir', $stop_run_dir, '--max-seeds', '0', '--duration-seconds', '0' ),
	$repo_root,
	60000
);
html_api_fuzz_smoke_assert( 0 !== $proc['code'], 'Expected runner to refuse to start over a pre-existing stop file.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['output'], 'Stop file already exists' ), 'Expected a clear stale stop file message.' );
html_api_fuzz_smoke_assert( ! is_file( $stop_run_dir . '/state.json' ), 'Expected no state to be written when refusing to start.' );

/*
 * 4. Mid-run graceful stop: an indefinite runner must finish its in-flight
 * batch, record it, and exit with stopReason stop-requested.
 */
$mid_run_dir = $work_dir . '/mid-run';
$spec        = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
$process     = proc_open(
	array( PHP_BINARY, $runner, '--output-dir', $mid_run_dir, '--max-seeds', '0', '--duration-seconds', '0', '--batch-size', '5', '--max-input-bytes', '512' ),
	$spec,
	$pipes,
	$repo_root
);
html_api_fuzz_smoke_assert( is_resource( $process ), 'Expected the indefinite runner to start.' );
fclose( $pipes[0] );
stream_set_blocking( $pipes[1], false );
stream_set_blocking( $pipes[2], false );

$deadline = microtime( true ) + 120.0;
$progress = false;
while ( microtime( true ) < $deadline ) {
	$mid_state = is_file( $mid_run_dir . '/state.json' ) ? @json_decode( (string) @file_get_contents( $mid_run_dir . '/state.json' ), true ) : null;
	$attempted = is_array( $mid_state )
		? (int) ( $mid_state['successes'] ?? 0 ) + (int) ( $mid_state['failures'] ?? 0 ) + (int) ( $mid_state['unsupported'] ?? 0 )
			+ (int) ( $mid_state['oracleParseErrors'] ?? 0 ) + (int) ( $mid_state['oracleUnsupported'] ?? 0 ) + (int) ( $mid_state['oracleTolerated'] ?? 0 )
		: 0;
	if ( $attempted > 0 ) {
		$progress = true;
		break;
	}
	usleep( 100000 );
}
html_api_fuzz_smoke_assert( $progress, 'Expected the indefinite runner to record progress before the stop request.' );

// Request the stop through the stop tool to cover its run-dir path.
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $mid_run_dir ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected stop.php to succeed: ' . substr( $proc['output'], -500 ) );
html_api_fuzz_smoke_assert( is_file( $mid_run_dir . '/STOP' ), 'Expected stop.php to create the stop file.' );

$deadline = microtime( true ) + 120.0;
$exited   = false;
$code     = null;
while ( microtime( true ) < $deadline ) {
	stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	$status = proc_get_status( $process );
	if ( ! $status['running'] ) {
		$exited = true;
		$code   = $status['exitcode'];
		break;
	}
	usleep( 100000 );
}
if ( ! $exited ) {
	proc_terminate( $process, 9 );
}
fclose( $pipes[1] );
fclose( $pipes[2] );
proc_close( $process );
html_api_fuzz_smoke_assert( $exited, 'Expected the runner to exit after the stop request.' );
html_api_fuzz_smoke_assert( 0 === $code, 'Expected the stopped runner to exit cleanly.' );

$mid_state = \HtmlApiFuzz\read_json_file( $mid_run_dir . '/state.json' );
html_api_fuzz_smoke_assert( 'stop-requested' === ( $mid_state['stopReason'] ?? null ), 'Expected stopReason stop-requested after a mid-run stop.' );

$db = new SQLite3( $mid_run_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME, SQLITE3_OPEN_READONLY );
html_api_fuzz_smoke_assert( 0 < (int) $db->querySingle( 'SELECT COUNT(*) FROM attempts' ), 'Expected the in-flight batch to be recorded before stopping.' );
$db->close();

\HtmlApiFuzz\remove_dir_recursive( $work_dir );

echo "OK runner-retention-smoke\n";
