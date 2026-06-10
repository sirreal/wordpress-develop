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

function html_api_fuzz_smoke_write_runner_state( string $path, array $overrides = array() ): void {
	\HtmlApiFuzz\write_json_file(
		$path,
		array_merge(
			array(
				'kind'       => 'html-api-fuzz-runner-state',
				'updatedAt'  => gmdate( 'c' ),
				'stopFile'   => dirname( $path ) . '/STOP',
				'stopReason' => null,
			),
			$overrides
		)
	);
}

function html_api_fuzz_smoke_touch( string $path, int $mtime ): void {
	html_api_fuzz_smoke_assert( touch( $path, $mtime ), "Expected touch to succeed for {$path}." );
	clearstatcache( true, $path );
	html_api_fuzz_smoke_assert( $mtime === (int) filemtime( $path ), "Expected mtime {$mtime} for {$path}." );
}

$stop_tool = dirname( __DIR__ ) . '/stop.php';
$runner_tool = dirname( __DIR__ ) . '/runner.php';
$repo_root = \HtmlApiFuzz\repo_root();
$work_dir  = sys_get_temp_dir() . '/html-api-fuzz-stop-' . \HtmlApiFuzz\timestamp();
$repo_artifacts_dir = $repo_root . '/artifacts';
$repo_fuzz_artifacts_dir = $repo_artifacts_dir . '/html-api-fuzz';
$had_repo_artifacts_dir = is_dir( $repo_artifacts_dir );
$had_repo_fuzz_artifacts_dir = is_dir( $repo_fuzz_artifacts_dir );
$repo_relative_run_dir = $repo_fuzz_artifacts_dir . '/run-stop-smoke-' . basename( $work_dir );

register_shutdown_function(
	static function () use ( $work_dir, $repo_relative_run_dir, $repo_fuzz_artifacts_dir, $repo_artifacts_dir, $had_repo_fuzz_artifacts_dir, $had_repo_artifacts_dir ): void {
		\HtmlApiFuzz\remove_dir_recursive( $work_dir );
		\HtmlApiFuzz\remove_dir_recursive( $repo_relative_run_dir );
		if ( ! $had_repo_fuzz_artifacts_dir ) {
			@rmdir( $repo_fuzz_artifacts_dir );
		}
		if ( ! $had_repo_artifacts_dir ) {
			@rmdir( $repo_artifacts_dir );
		}
	}
);

/*
 * Discovery must prefer an unfinished launcher run over a more recently
 * touched but already finished one.
 */
$launcher_artifacts = $work_dir . '/launcher-discovery';
$finished_run       = $launcher_artifacts . '/run-finished';
\HtmlApiFuzz\ensure_dir( $finished_run );
html_api_fuzz_smoke_write_runner_state(
	$finished_run . '/state.json',
	array(
		'stopReason' => 'max-seeds',
	)
);

$active_run = $launcher_artifacts . '/run-active';
\HtmlApiFuzz\ensure_dir( $active_run );
\HtmlApiFuzz\write_json_file(
	$active_run . '/launcher-state.json',
	array(
		'kind'      => 'html-api-fuzz-launcher-state',
		'finished'  => false,
		'updatedAt' => gmdate( 'c' ),
	)
);
// Make the finished run the more recently touched one.
html_api_fuzz_smoke_touch( $finished_run . '/state.json', time() + 5 );

$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $launcher_artifacts ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && is_array( $report ), 'Expected stop.php launcher discovery to succeed: ' . substr( $proc['output'], -500 ) );
html_api_fuzz_smoke_assert( $active_run === ( $report['runDir'] ?? null ), 'Expected discovery to prefer the unfinished launcher run.' );
html_api_fuzz_smoke_assert( is_file( $active_run . '/STOP' ), 'Expected the stop file in the active launcher run.' );
html_api_fuzz_smoke_assert( ! is_file( $finished_run . '/STOP' ), 'Expected no stop file in the finished run.' );
html_api_fuzz_smoke_assert( false === ( $report['looksFinished'] ?? null ), 'Expected the chosen launcher run not to look finished.' );

// Lane runner state alone is also enough to mark a launch run unfinished.
$lane_artifacts = $work_dir . '/lane-discovery';
$lane_run       = $lane_artifacts . '/run-lane-active';
\HtmlApiFuzz\ensure_dir( $lane_run . '/lane-00' );
html_api_fuzz_smoke_write_runner_state( $lane_run . '/lane-00/state.json' );
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $lane_artifacts ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && $lane_run === ( $report['runDir'] ?? null ), 'Expected lane runner state to mark a launch run unfinished.' );
html_api_fuzz_smoke_assert( is_file( $lane_run . '/STOP' ), 'Expected the stop file in the lane-active run.' );

/*
 * A standalone runner writes root state.json and may advertise a custom
 * stopFile. A newer malformed runner state without stopReason must not be
 * treated as unfinished.
 */
$standalone_artifacts = $work_dir . '/standalone-discovery';
$missing_run          = $standalone_artifacts . '/run-missing-stop-reason';
\HtmlApiFuzz\ensure_dir( $missing_run );
\HtmlApiFuzz\write_json_file(
	$missing_run . '/state.json',
	array(
		'kind'      => 'html-api-fuzz-runner-state',
		'updatedAt' => gmdate( 'c' ),
	)
);
html_api_fuzz_smoke_touch( $missing_run . '/state.json', time() + 10 );

$standalone_run = $standalone_artifacts . '/run-standalone-active';
$custom_stop    = $standalone_artifacts . '/custom-stop/STOP';
\HtmlApiFuzz\ensure_dir( $standalone_run );
html_api_fuzz_smoke_write_runner_state(
	$standalone_run . '/state.json',
	array(
		'stopFile' => $custom_stop,
	)
);

$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $standalone_artifacts ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && is_array( $report ), 'Expected stop.php standalone discovery to succeed: ' . substr( $proc['output'], -500 ) );
html_api_fuzz_smoke_assert( $standalone_run === ( $report['runDir'] ?? null ), 'Expected discovery to prefer the unfinished standalone run.' );
html_api_fuzz_smoke_assert( $standalone_run . '/STOP' === ( $report['stopFile'] ?? null ), 'Expected discovery to report the run-dir stop file as the primary stop file.' );
html_api_fuzz_smoke_assert( in_array( $custom_stop, $report['stopFiles'] ?? array(), true ), 'Expected discovery to include the standalone runner custom stop file.' );
html_api_fuzz_smoke_assert( is_file( $custom_stop ), 'Expected the custom stop file to be created.' );
html_api_fuzz_smoke_assert( is_file( $standalone_run . '/STOP' ), 'Expected the run-dir stop file to be created for watcher and orchestrator paths.' );
html_api_fuzz_smoke_assert( ! is_file( $missing_run . '/STOP' ), 'Expected no stop file in the malformed runner-state run.' );
html_api_fuzz_smoke_assert( false === ( $report['looksFinished'] ?? null ), 'Expected the standalone run not to look finished.' );

// A second invocation reports the existing request instead of failing.
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $standalone_artifacts ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && true === ( $report['alreadyRequested'] ?? null ), 'Expected a repeat stop request to be reported as already requested.' );

// Explicit --run-dir inspection also honors the custom stop file.
unlink( $custom_stop );
unlink( $standalone_run . '/STOP' );
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $standalone_run ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && $standalone_run . '/STOP' === ( $report['stopFile'] ?? null ), 'Expected --run-dir to report the run-dir stop file as primary.' );
html_api_fuzz_smoke_assert( in_array( $custom_stop, $report['stopFiles'] ?? array(), true ), 'Expected --run-dir to include the standalone runner custom stop file.' );
html_api_fuzz_smoke_assert( is_file( $custom_stop ), 'Expected --run-dir to create the custom stop file.' );
html_api_fuzz_smoke_assert( is_file( $standalone_run . '/STOP' ), 'Expected --run-dir to create the run-dir stop file too.' );

// The README-documented relative command works from the repo root.
$repo_relative_run_arg = 'artifacts/html-api-fuzz/' . basename( $repo_relative_run_dir );
\HtmlApiFuzz\ensure_dir( $repo_relative_run_dir );
\HtmlApiFuzz\write_json_file(
	$repo_relative_run_dir . '/launcher-state.json',
	array(
		'kind'      => 'html-api-fuzz-launcher-state',
		'finished'  => false,
		'updatedAt' => gmdate( 'c' ),
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( 'tools/html-api-fuzz/stop.php', '--run-dir', $repo_relative_run_arg ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && $repo_relative_run_arg === ( $report['runDir'] ?? null ), 'Expected README-style relative --run-dir to succeed: ' . substr( $proc['output'], -500 ) );
html_api_fuzz_smoke_assert( $repo_relative_run_arg . '/STOP' === ( $report['stopFile'] ?? null ), 'Expected README-style relative --run-dir to report a relative run-dir stop file.' );
html_api_fuzz_smoke_assert( is_file( $repo_relative_run_dir . '/STOP' ), 'Expected README-style relative --run-dir to create RUN_DIR/STOP.' );

// Explicit --stop-file is added to the discovered stop files.
unlink( $custom_stop );
unlink( $standalone_run . '/STOP' );
$override_stop = $standalone_artifacts . '/override-stop/STOP';
$proc          = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $standalone_run, '--stop-file', $override_stop ), $repo_root, 30000 );
$report        = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && $standalone_run . '/STOP' === ( $report['stopFile'] ?? null ), 'Expected --run-dir --stop-file to report the run-dir stop file as primary.' );
html_api_fuzz_smoke_assert( in_array( $override_stop, $report['stopFiles'] ?? array(), true ), 'Expected --stop-file to be included in stopFiles.' );
html_api_fuzz_smoke_assert( is_file( $override_stop ), 'Expected --stop-file to create the override stop file.' );
html_api_fuzz_smoke_assert( is_file( $custom_stop ), 'Expected --run-dir --stop-file to create the advertised custom stop file too.' );
html_api_fuzz_smoke_assert( is_file( $standalone_run . '/STOP' ), 'Expected --run-dir --stop-file to create the run-dir stop file too.' );

// Explicit --stop-file also works as a direct write without run discovery.
$direct_stop = $work_dir . '/direct-stop/STOP';
$proc        = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--stop-file', $direct_stop ), $repo_root, 30000 );
$report      = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && is_array( $report ), 'Expected direct --stop-file to succeed without run discovery: ' . substr( $proc['output'], -500 ) );
html_api_fuzz_smoke_assert( null === ( $report['runDir'] ?? null ), 'Expected direct --stop-file to report no run directory.' );
html_api_fuzz_smoke_assert( is_file( $direct_stop ), 'Expected direct --stop-file to create the requested file.' );

// A run directory without state can only be stopped unambiguously with --stop-file.
$no_state_run = $work_dir . '/no-state-run';
\HtmlApiFuzz\ensure_dir( $no_state_run );
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $no_state_run ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected --run-dir without state to report warning status.' );
$no_state_custom_stop = $work_dir . '/no-state-custom-stop/STOP';
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $no_state_run, '--stop-file', $no_state_custom_stop ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && is_file( $no_state_custom_stop ) && is_file( $no_state_run . '/STOP' ), 'Expected --run-dir --stop-file without state to write both stop files.' );

// Bare and ambiguous CLI invocations fail.
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--stop-file' ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'] && false !== strpos( $proc['stderr'], 'non-empty path' ), 'Expected bare --stop-file to fail with a path error.' );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', '--stop-file', $work_dir . '/bare-run-dir-stop/STOP' ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'] && false !== strpos( $proc['stderr'], 'non-empty path' ), 'Expected bare --run-dir to fail with a path error.' );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--stop-stale-seconds' ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'] && false !== strpos( $proc['stderr'], 'numeric' ), 'Expected bare --stop-stale-seconds to fail with a numeric error.' );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--stop-stale-seconds', 'nope' ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'] && false !== strpos( $proc['stderr'], 'numeric' ), 'Expected non-numeric --stop-stale-seconds to fail with a numeric error.' );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir' ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'] && false !== strpos( $proc['stderr'], 'non-empty path' ), 'Expected bare --artifacts-dir to fail with a path error.' );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $launcher_artifacts, '--stop-file', $work_dir . '/ambiguous-stop/STOP' ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'] && false !== strpos( $proc['stderr'], 'Pass --run-dir' ), 'Expected --artifacts-dir --stop-file without --run-dir to fail as ambiguous.' );
$proc = \HtmlApiFuzz\run_php_process( array( $runner_tool, '--max-seeds', '1', '--stop-file=' ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'] && false !== strpos( $proc['stderr'], 'non-empty path' ), 'Expected runner --stop-file= to fail with a path error.' );

// Relative advertised stop files are resolved from real runner cwd, not stop.php's cwd.
$relative_artifacts = $work_dir . '/relative-discovery';
$relative_run       = $relative_artifacts . '/run-relative-stop';
$relative_cwd       = $work_dir . '/relative-cwd';
$relative_invoke_cwd = $work_dir . '/relative-invoke-cwd';
$relative_stop      = 'custom-relative-stop/STOP';
\HtmlApiFuzz\ensure_dir( $relative_cwd );
\HtmlApiFuzz\ensure_dir( $relative_invoke_cwd );
$relative_cwd_real = realpath( $relative_cwd );
html_api_fuzz_smoke_assert( is_string( $relative_cwd_real ), 'Expected relative runner cwd realpath.' );
$proc = \HtmlApiFuzz\run_php_process(
	array(
		$runner_tool,
		'--output-dir',
		$relative_run,
		'--max-seeds',
		'1',
		'--stop-file',
		$relative_stop,
	),
	$relative_cwd,
	30000
);
html_api_fuzz_smoke_assert( 0 === $proc['code'], 'Expected real runner with relative stop file to finish: ' . substr( $proc['output'], -500 ) );
$runner_state = \HtmlApiFuzz\read_json_file( $relative_run . '/state.json' );
html_api_fuzz_smoke_assert( is_array( $runner_state ) && $relative_cwd_real === ( $runner_state['cwd'] ?? null ), 'Expected runner state to record its cwd.' );
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $relative_run ), $relative_invoke_cwd, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
$expected_relative_stop = rtrim( $relative_cwd_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative_stop;
html_api_fuzz_smoke_assert( 0 === $proc['code'] && in_array( $expected_relative_stop, $report['stopFiles'] ?? array(), true ), 'Expected relative advertised stopFile to resolve from runner cwd.' );
html_api_fuzz_smoke_assert( is_file( $expected_relative_stop ), 'Expected the runner-cwd-relative stop file to be created.' );
html_api_fuzz_smoke_assert( ! is_file( $relative_invoke_cwd . '/' . $relative_stop ), 'Expected no stop file relative to stop.php invocation cwd.' );

// On POSIX, a leading backslash is still relative to runner cwd.
if ( '\\' !== DIRECTORY_SEPARATOR ) {
	$backslash_run  = $work_dir . '/backslash-relative-stop';
	$backslash_cwd  = $work_dir . '/backslash-cwd';
	$backslash_stop = '\\custom-backslash-stop/STOP';
	\HtmlApiFuzz\ensure_dir( $backslash_run );
	\HtmlApiFuzz\ensure_dir( $backslash_cwd );
	html_api_fuzz_smoke_write_runner_state(
		$backslash_run . '/state.json',
		array(
			'stopFile' => $backslash_stop,
			'cwd'      => $backslash_cwd,
		)
	);
	$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $backslash_run ), $relative_invoke_cwd, 30000 );
	$report = json_decode( trim( $proc['stdout'] ), true );
	$expected_backslash_stop = rtrim( $backslash_cwd, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $backslash_stop;
	html_api_fuzz_smoke_assert( 0 === $proc['code'] && in_array( $expected_backslash_stop, $report['stopFiles'] ?? array(), true ), 'Expected POSIX leading-backslash stopFile to resolve from runner cwd.' );
	html_api_fuzz_smoke_assert( is_file( $expected_backslash_stop ), 'Expected POSIX leading-backslash stop file to be created under runner cwd.' );
}

// Legacy active relative stopFile state without cwd warns because the watched file is ambiguous.
$legacy_relative_run  = $work_dir . '/legacy-relative-stop';
$legacy_relative_cwd = $work_dir . '/legacy-relative-cwd';
$legacy_relative_stop = 'legacy-relative-caller-cwd/STOP';
\HtmlApiFuzz\ensure_dir( $legacy_relative_run );
\HtmlApiFuzz\ensure_dir( $legacy_relative_cwd );
html_api_fuzz_smoke_write_runner_state(
	$legacy_relative_run . '/state.json',
	array(
		'stopFile' => $legacy_relative_stop,
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $legacy_relative_run ), $legacy_relative_cwd, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected legacy relative stopFile without cwd to report warning status.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'exact watched file may be unknown' ), 'Expected legacy relative stopFile without absolute cwd to warn.' );
html_api_fuzz_smoke_assert( is_file( $legacy_relative_run . '/STOP' ), 'Expected legacy relative stopFile warning path to write RUN_DIR/STOP.' );
html_api_fuzz_smoke_assert( is_file( $legacy_relative_cwd . '/' . $legacy_relative_stop ), 'Expected legacy relative stopFile warning path to write a caller-cwd candidate.' );

$finished_legacy_relative_run  = $work_dir . '/finished-legacy-relative-stop';
$finished_legacy_relative_cwd = $work_dir . '/finished-legacy-relative-cwd';
$finished_legacy_relative_stop = 'finished-legacy-relative-caller-cwd/STOP';
\HtmlApiFuzz\ensure_dir( $finished_legacy_relative_run );
\HtmlApiFuzz\ensure_dir( $finished_legacy_relative_cwd );
html_api_fuzz_smoke_write_runner_state(
	$finished_legacy_relative_run . '/state.json',
	array(
		'stopFile'   => $finished_legacy_relative_stop,
		'stopReason' => 'max-seeds',
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $finished_legacy_relative_run ), $finished_legacy_relative_cwd, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected finished legacy relative stopFile without cwd to report warning status.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'exact watched file may be unknown' ), 'Expected finished legacy relative stopFile without cwd to warn.' );
html_api_fuzz_smoke_assert( is_file( $finished_legacy_relative_run . '/STOP' ), 'Expected finished legacy relative stopFile warning path to write RUN_DIR/STOP.' );
html_api_fuzz_smoke_assert( is_file( $finished_legacy_relative_cwd . '/' . $finished_legacy_relative_stop ), 'Expected finished legacy relative stopFile warning path to write a caller-cwd candidate.' );

// Active malformed advertised stop files must not report unqualified success.
$bad_advertised_run = $work_dir . '/bad-advertised-stop';
\HtmlApiFuzz\ensure_dir( $bad_advertised_run );
html_api_fuzz_smoke_write_runner_state(
	$bad_advertised_run . '/state.json',
	array(
		'stopFile' => '',
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $bad_advertised_run ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && is_file( $bad_advertised_run . '/STOP' ), 'Expected RUN_DIR/STOP to be written with warning status when advertised stopFile is empty.' );
html_api_fuzz_smoke_assert( false === ( $report['ok'] ?? null ) && false !== strpos( $proc['stderr'], 'exact watched file may be unknown' ), 'Expected empty advertised stopFile to warn.' );

$unknown_kind_run = $work_dir . '/unknown-kind-runner-state';
$unknown_kind_stop = $work_dir . '/unknown-kind-stop/STOP';
\HtmlApiFuzz\ensure_dir( $unknown_kind_run );
\HtmlApiFuzz\write_json_file(
	$unknown_kind_run . '/state.json',
	array(
		'updatedAt'  => gmdate( 'c' ),
		'stopFile'   => $unknown_kind_stop,
		'stopReason' => null,
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $unknown_kind_run ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected runner-like state with missing kind to report warning status.' );
html_api_fuzz_smoke_assert( is_file( $unknown_kind_stop ), 'Expected runner-like state with missing kind to write the advertised custom stop file.' );
html_api_fuzz_smoke_assert( is_file( $unknown_kind_run . '/STOP' ), 'Expected runner-like state with missing kind to write RUN_DIR/STOP.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'missing or unknown kind' ), 'Expected runner-like state with missing kind to warn.' );

$missing_stop_file_run = $work_dir . '/missing-stop-file';
\HtmlApiFuzz\ensure_dir( $missing_stop_file_run );
\HtmlApiFuzz\write_json_file(
	$missing_stop_file_run . '/state.json',
	array(
		'kind'       => 'html-api-fuzz-runner-state',
		'updatedAt'  => gmdate( 'c' ),
		'stopReason' => null,
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $missing_stop_file_run ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected active runner state missing stopFile to report warning status.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'missing or malformed' ), 'Expected active runner state missing stopFile to warn.' );

$relative_cwd_run = $work_dir . '/relative-cwd-stop';
$relative_bad_cwd = $work_dir . '/relative-bad-cwd';
\HtmlApiFuzz\ensure_dir( $relative_cwd_run );
\HtmlApiFuzz\ensure_dir( $relative_bad_cwd );
html_api_fuzz_smoke_write_runner_state(
	$relative_cwd_run . '/state.json',
	array(
		'stopFile' => 'relative-cwd-caller-cwd/STOP',
		'cwd'      => 'not-absolute',
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $relative_cwd_run ), $relative_bad_cwd, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected relative recorded cwd to report warning status.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'no recorded absolute cwd' ), 'Expected relative recorded cwd to warn.' );
html_api_fuzz_smoke_assert( is_file( $relative_bad_cwd . '/relative-cwd-caller-cwd/STOP' ), 'Expected relative recorded cwd warning path to write a caller-cwd candidate.' );

$unknown_runner_run = $work_dir . '/unknown-runner-stop-file';
$unknown_runner_cwd = $work_dir . '/unknown-runner-cwd';
\HtmlApiFuzz\ensure_dir( $unknown_runner_run );
\HtmlApiFuzz\ensure_dir( $unknown_runner_cwd );
\HtmlApiFuzz\write_json_file(
	$unknown_runner_run . '/state.json',
	array(
		'kind'      => 'html-api-fuzz-runner-state',
		'updatedAt' => gmdate( 'c' ),
		'stopFile'  => 'unknown-runner-caller-cwd/STOP',
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $unknown_runner_run ), $unknown_runner_cwd, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected runner state without stopReason and relative stopFile to warn.' );

// Unreadable state warns and still writes RUN_DIR/STOP.
if ( '\\' !== DIRECTORY_SEPARATOR ) {
	$unreadable_run = $work_dir . '/unreadable-state';
	\HtmlApiFuzz\ensure_dir( $unreadable_run );
	$unreadable_state = $unreadable_run . '/state.json';
	html_api_fuzz_smoke_write_runner_state( $unreadable_state );
	html_api_fuzz_smoke_assert( chmod( $unreadable_state, 0000 ), 'Expected chmod to make state unreadable.' );
	if ( ! is_readable( $unreadable_state ) ) {
		$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $unreadable_run ), $repo_root, 30000 );
		chmod( $unreadable_state, 0600 );
		$report = json_decode( trim( $proc['stdout'] ), true );
		html_api_fuzz_smoke_assert( 2 === $proc['code'] && false === ( $report['ok'] ?? null ), 'Expected unreadable state to report warning status.' );
		html_api_fuzz_smoke_assert( is_file( $unreadable_run . '/STOP' ), 'Expected unreadable state fallback to write RUN_DIR/STOP.' );
	} else {
		chmod( $unreadable_state, 0600 );
	}
}

// Unreadable in-progress state warns and still writes RUN_DIR/STOP.
$corrupt_run = $work_dir . '/corrupt-state';
\HtmlApiFuzz\ensure_dir( $corrupt_run );
file_put_contents( $corrupt_run . '/state.json', "{not-json\n" );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--run-dir', $corrupt_run ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 2 === $proc['code'] && is_file( $corrupt_run . '/STOP' ), 'Expected corrupt state fallback to write RUN_DIR/STOP with warning status.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'could not read' ), 'Expected corrupt state fallback to warn.' );

// Stale corrupt state is not preferred during discovery.
$stale_corrupt_artifacts = $work_dir . '/stale-corrupt-discovery';
$stale_corrupt_run       = $stale_corrupt_artifacts . '/run-stale-corrupt';
$recent_finished_run     = $stale_corrupt_artifacts . '/run-recent-finished';
\HtmlApiFuzz\ensure_dir( $stale_corrupt_run );
file_put_contents( $stale_corrupt_run . '/state.json', "{not-json\n" );
html_api_fuzz_smoke_touch( $stale_corrupt_run . '/state.json', time() - 3600 );
\HtmlApiFuzz\ensure_dir( $recent_finished_run );
html_api_fuzz_smoke_write_runner_state(
	$recent_finished_run . '/state.json',
	array(
		'stopReason' => 'max-seeds',
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $stale_corrupt_artifacts, '--stop-stale-seconds', '10' ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && $recent_finished_run === ( $report['runDir'] ?? null ), 'Expected stale corrupt state not to be preferred during discovery.' );
html_api_fuzz_smoke_assert( true === ( $report['looksFinished'] ?? null ), 'Expected recent finished fallback to report looksFinished.' );
html_api_fuzz_smoke_assert( is_file( $recent_finished_run . '/STOP' ), 'Expected recent finished fallback to create STOP.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'already looks stopped' ), 'Expected recent finished fallback to warn.' );

// A stale runner state does not count as unfinished and therefore warns.
$stale_artifacts = $work_dir . '/stale-discovery';
$stale_run       = $stale_artifacts . '/run-stale';
$stale_custom    = $stale_artifacts . '/custom-stale-stop/STOP';
\HtmlApiFuzz\ensure_dir( $stale_run );
html_api_fuzz_smoke_write_runner_state(
	$stale_run . '/state.json',
	array(
		'updatedAt'     => gmdate( 'c', time() - 3600 ),
		'batchBudgetMs' => 0,
		'stopFile'      => $stale_custom,
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $stale_artifacts, '--stop-stale-seconds', '10' ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && true === ( $report['looksFinished'] ?? null ), 'Expected a stale-only artifacts dir to be reported as looksFinished.' );
html_api_fuzz_smoke_assert( is_file( $stale_custom ), 'Expected stale custom stop file to be created for the targeted run.' );
html_api_fuzz_smoke_assert( is_file( $stale_run . '/STOP' ), 'Expected stale run-dir stop file to be created for watcher and orchestrator paths.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'already looks stopped' ), 'Expected a warning when only stale runs exist.' );

// Missing updatedAt falls back to state file mtime for stale detection.
$mtime_stale_artifacts = $work_dir . '/mtime-stale-discovery';
$mtime_stale_run       = $mtime_stale_artifacts . '/run-mtime-stale';
\HtmlApiFuzz\ensure_dir( $mtime_stale_run );
\HtmlApiFuzz\write_json_file(
	$mtime_stale_run . '/state.json',
	array(
		'kind'          => 'html-api-fuzz-runner-state',
		'batchBudgetMs' => 0,
		'stopReason'    => null,
	)
);
html_api_fuzz_smoke_touch( $mtime_stale_run . '/state.json', time() - 3600 );
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $mtime_stale_artifacts, '--stop-stale-seconds', '10' ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && true === ( $report['looksFinished'] ?? null ), 'Expected missing updatedAt to use stale file mtime.' );

// A large batch budget floors the stale threshold so long-running batches still look active.
$budget_artifacts = $work_dir . '/batch-budget-discovery';
$budget_run       = $budget_artifacts . '/run-budget-active';
\HtmlApiFuzz\ensure_dir( $budget_run );
html_api_fuzz_smoke_write_runner_state(
	$budget_run . '/state.json',
	array(
		'updatedAt'     => gmdate( 'c', time() - 30 ),
		'batchBudgetMs' => 600000,
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $budget_artifacts, '--stop-stale-seconds', '10' ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && false === ( $report['looksFinished'] ?? null ), 'Expected batch budget floor to keep the old runner state active.' );

// A stale launcher state does not count as unfinished and therefore warns.
$stale_launcher_artifacts = $work_dir . '/stale-launcher-discovery';
$stale_launcher_run       = $stale_launcher_artifacts . '/run-stale-launcher';
\HtmlApiFuzz\ensure_dir( $stale_launcher_run );
\HtmlApiFuzz\write_json_file(
	$stale_launcher_run . '/launcher-state.json',
	array(
		'kind'      => 'html-api-fuzz-launcher-state',
		'finished'  => false,
		'updatedAt' => gmdate( 'c', time() - 3600 ),
	)
);
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $stale_launcher_artifacts, '--stop-stale-seconds', '10' ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && true === ( $report['looksFinished'] ?? null ), 'Expected a stale launcher-only artifacts dir to be reported as looksFinished.' );
html_api_fuzz_smoke_assert( false !== strpos( $proc['stderr'], 'already looks stopped' ), 'Expected a warning when only stale launcher state exists.' );

// Same-second active runs are ordered deterministically by the run path.
$tie_artifacts = $work_dir . '/tie-discovery';
$first_tie_run = $tie_artifacts . '/run-20260101T000000000001Z';
$next_tie_run  = $tie_artifacts . '/run-20260101T000000000002Z';
\HtmlApiFuzz\ensure_dir( $first_tie_run );
\HtmlApiFuzz\ensure_dir( $next_tie_run );
html_api_fuzz_smoke_write_runner_state( $first_tie_run . '/state.json' );
html_api_fuzz_smoke_write_runner_state( $next_tie_run . '/state.json' );
$same_mtime = time() + 20;
html_api_fuzz_smoke_touch( $first_tie_run . '/state.json', $same_mtime );
html_api_fuzz_smoke_touch( $next_tie_run . '/state.json', $same_mtime );
$proc   = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $tie_artifacts ), $repo_root, 30000 );
$report = json_decode( trim( $proc['stdout'] ), true );
html_api_fuzz_smoke_assert( 0 === $proc['code'] && $next_tie_run === ( $report['runDir'] ?? null ), 'Expected same-second active runs to prefer the later path.' );

// An empty artifacts dir is an error, not a silent success.
$empty_dir = $work_dir . '/empty';
\HtmlApiFuzz\ensure_dir( $empty_dir );
$proc = \HtmlApiFuzz\run_php_process( array( $stop_tool, '--artifacts-dir', $empty_dir ), $repo_root, 30000 );
html_api_fuzz_smoke_assert( 0 !== $proc['code'], 'Expected stop.php to fail when no run directory exists.' );

echo "OK stop-smoke\n";
