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

$stop_tool = dirname( __DIR__ ) . '/stop.php';
$repo_root = \HtmlApiFuzz\repo_root();
$work_dir  = sys_get_temp_dir() . '/html-api-fuzz-stop-' . \HtmlApiFuzz\timestamp();

/*
 * Discovery must prefer an unfinished run over a more recently created but
 * already finished one.
 */
$finished_run = $work_dir . '/run-finished';
\HtmlApiFuzz\ensure_dir( $finished_run );
\HtmlApiFuzz\write_json_file(
	$finished_run . '/state.json',
	array(
		'kind'       => 'html-api-fuzz-runner-state',
		'stopReason' => 'max-seeds',
	)
);

$active_run = $work_dir . '/run-active';
\HtmlApiFuzz\ensure_dir( $active_run . '/lane-00' );
\HtmlApiFuzz\write_json_file(
	$active_run . '/launcher-state.json',
	array(
		'kind'     => 'html-api-fuzz-launcher-state',
		'finished' => false,
	)
);
\HtmlApiFuzz\write_json_file(
	$active_run . '/lane-00/state.json',
	array(
		'kind'       => 'html-api-fuzz-runner-state',
		'stopReason' => null,
	)
);
// Make the finished run the more recently touched one.
touch( $finished_run . '/state.json', time() + 5 );

$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $work_dir ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && is_array( $report ), 'Expected stop.php discovery to succeed: ' . substr( $proc['output'], -500 ) );
html_api_fuzz_smoke_assert( $active_run === ( $report['runDir'] ?? null ), 'Expected discovery to prefer the unfinished run.' );
html_api_fuzz_smoke_assert( is_file( $active_run . '/STOP' ), 'Expected the stop file in the active run.' );
html_api_fuzz_smoke_assert( ! is_file( $finished_run . '/STOP' ), 'Expected no stop file in the finished run.' );
html_api_fuzz_smoke_assert( false === ( $report['looksFinished'] ?? null ), 'Expected the chosen run not to look finished.' );

// A second invocation reports the existing request instead of failing.
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $work_dir ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && true === ( $report['alreadyRequested'] ?? null ), 'Expected a repeat stop request to be reported as already requested.' );

// With every run stopped, discovery still works but warns.
unlink( $active_run . '/STOP' );
\HtmlApiFuzz\write_json_file(
	$active_run . '/launcher-state.json',
	array(
		'kind'     => 'html-api-fuzz-launcher-state',
		'finished' => true,
	)
);
\HtmlApiFuzz\write_json_file(
	$active_run . '/lane-00/state.json',
	array(
		'kind'       => 'html-api-fuzz-runner-state',
		'stopReason' => 'stop-requested',
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $work_dir ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && true === ( $report['looksFinished'] ?? null ), 'Expected a finished-only artifacts dir to be reported as looksFinished.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'already looks stopped' ), 'Expected a warning when only finished runs exist.' );

// An empty artifacts dir is an error, not a silent success.
$empty_dir = $work_dir . '/empty';
\HtmlApiFuzz\ensure_dir( $empty_dir );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $empty_dir ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'], 'Expected stop.php to fail when no run directory exists.' );

\HtmlApiFuzz\remove_dir_recursive( $work_dir );

echo "OK stop-smoke\n";
