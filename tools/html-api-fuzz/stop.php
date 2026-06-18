#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

/**
 * Requests a graceful stop of a fuzz run by creating the stop file watched by
 * the run. Each runner lane finishes its current batch and exits; the watcher
 * performs a final scan once all lanes have stopped; the codex orchestrator
 * stops launching and exits when its running jobs finish.
 */
function html_api_fuzz_stop_usage(): void {
	echo "Usage: php tools/html-api-fuzz/stop.php [--run-dir DIR] [--artifacts-dir DIR] [--stop-file PATH] [--stop-stale-seconds N]\n";
	echo "Without --run-dir, targets the most recently active unfinished run under artifacts/html-api-fuzz (or --artifacts-dir), unless --stop-file is passed by itself to write a known stop file directly.\n";
}

function html_api_fuzz_stop_add_stop_file( array &$stop_files, string $stop_file ): void {
	if ( '' !== $stop_file && ! in_array( $stop_file, $stop_files, true ) ) {
		$stop_files[] = $stop_file;
	}
}

function html_api_fuzz_stop_path_is_absolute( string $path ): bool {
	if ( '' === $path ) {
		return false;
	}
	if ( '/' === $path[0] ) {
		return true;
	}

	return '\\' === DIRECTORY_SEPARATOR && ( '\\' === $path[0] || ( strlen( $path ) > 2 && ':' === $path[1] && ( '/' === $path[2] || '\\' === $path[2] ) ) );
}

function html_api_fuzz_stop_add_advertised_stop_file( array &$stop_files, array &$warnings, string $stop_file, array $state ): void {
	if ( '' === $stop_file ) {
		$warnings[] = 'runner stopFile is empty; wrote the run-dir stop file, but the exact watched file may be unknown.';
		return;
	}

	if ( html_api_fuzz_stop_path_is_absolute( $stop_file ) ) {
		html_api_fuzz_stop_add_stop_file( $stop_files, $stop_file );
		return;
	}

	if ( is_string( $state['cwd'] ?? null ) && '' !== $state['cwd'] && html_api_fuzz_stop_path_is_absolute( $state['cwd'] ) ) {
		html_api_fuzz_stop_add_stop_file( $stop_files, rtrim( $state['cwd'], DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $stop_file );
		return;
	}

	// Older or malformed runner state did not record an absolute cwd; the
	// exact relative path is unknowable from another process.
	html_api_fuzz_stop_add_stop_file( $stop_files, $stop_file );
	$warnings[] = 'relative runner stopFile has no recorded absolute cwd; wrote a caller-cwd candidate, but the exact watched file may be unknown.';
}

function html_api_fuzz_stop_state_is_stale( array $state, float $stale_seconds, int $fallback_mtime = 0 ): bool {
	$updated_at = strtotime( (string) ( $state['updatedAt'] ?? '' ) );
	if ( false === $updated_at && $fallback_mtime > 0 ) {
		$updated_at = $fallback_mtime;
	}

	return false !== $updated_at && ( time() - $updated_at ) > $stale_seconds;
}

function html_api_fuzz_stop_runner_is_active( array $state, float $stale_seconds, int $state_mtime ): bool {
	if ( ! array_key_exists( 'stopReason', $state ) || null !== $state['stopReason'] ) {
		return false;
	}

	$runner_stale_seconds = max( $stale_seconds, 2.0 * ( (int) ( $state['batchBudgetMs'] ?? 0 ) ) / 1000.0 );

	return ! html_api_fuzz_stop_state_is_stale( $state, $runner_stale_seconds, $state_mtime );
}

function html_api_fuzz_stop_state_looks_runner_like( array $state ): bool {
	$kind = $state['kind'] ?? null;
	if ( 'html-api-fuzz-runner-state' === $kind ) {
		return true;
	}
	if ( 'html-api-fuzz-launcher-state' === $kind ) {
		return false;
	}

	return array_key_exists( 'stopFile', $state ) || array_key_exists( 'stopReason', $state ) || array_key_exists( 'batchBudgetMs', $state );
}

function html_api_fuzz_stop_read_state_with_retry( string $state_path, int $attempts = 3 ) {
	$last_exception = null;
	for ( $i = 0; $i < $attempts; ++$i ) {
		try {
			return \HtmlApiFuzz\read_json_file( $state_path );
		} catch ( \RuntimeException $e ) {
			$last_exception = $e;
			usleep( 50000 );
		}
	}

	if ( null !== $last_exception ) {
		throw $last_exception;
	}

	return null;
}

/**
 * Describes one candidate run directory: whether any of its runners or its
 * launcher still looks unfinished, and how recently its state files changed.
 * Directory mtimes are useless here — lanes write into subdirectories.
 */
function html_api_fuzz_stop_inspect_run_dir( string $path, float $stale_seconds ): ?array {
	$default_stop_file = rtrim( $path, DIRECTORY_SEPARATOR ) . '/STOP';
	$state_paths = array_merge(
		is_file( $path . '/launcher-state.json' ) ? array( $path . '/launcher-state.json' ) : array(),
		is_file( $path . '/state.json' ) ? array( $path . '/state.json' ) : array(),
		glob( $path . '/lane-*/state.json' ) ?: array()
	);
	if ( array() === $state_paths ) {
		return null;
	}

	$active       = false;
	$latest_mtime = 0;
	$stop_files   = array();
	$warnings     = array();
	foreach ( $state_paths as $state_path ) {
		$mtime = filemtime( $state_path );
		$state_mtime = false !== $mtime ? (int) $mtime : 0;
		if ( false !== $mtime ) {
			$latest_mtime = max( $latest_mtime, (int) $mtime );
		}
		try {
			$state = html_api_fuzz_stop_read_state_with_retry( $state_path );
		} catch ( \RuntimeException $e ) {
			// Mid-write or corrupt state: the advertised stop file is unknowable.
			$is_stale = 0 !== $state_mtime && ( time() - $state_mtime ) > $stale_seconds;
			if ( ! $is_stale ) {
				$active = true;
			}
			html_api_fuzz_stop_add_stop_file( $stop_files, $default_stop_file );
			$warnings[] = "could not read {$state_path}; writing only the run-dir stop file for that state.";
			continue;
		}
		if ( ! is_array( $state ) ) {
			$is_stale = 0 !== $state_mtime && ( time() - $state_mtime ) > $stale_seconds;
			if ( ! $is_stale ) {
				$active = true;
			}
			html_api_fuzz_stop_add_stop_file( $stop_files, $default_stop_file );
			$warnings[] = "could not read {$state_path}; writing only the run-dir stop file for that state.";
			continue;
		}
			$kind = $state['kind'] ?? null;
			if ( 'html-api-fuzz-launcher-state' === $kind && false === ( $state['finished'] ?? null ) && ! html_api_fuzz_stop_state_is_stale( $state, $stale_seconds, $state_mtime ) ) {
				$active = true;
				html_api_fuzz_stop_add_stop_file( $stop_files, $default_stop_file );
			}
			if ( html_api_fuzz_stop_state_looks_runner_like( $state ) ) {
				if ( 'html-api-fuzz-runner-state' !== $kind ) {
					$warnings[] = "runner-like state {$state_path} has missing or unknown kind; treating it as runner state.";
				}
				$runner_active  = html_api_fuzz_stop_runner_is_active( $state, $stale_seconds, $state_mtime );
				$runner_unknown = ! array_key_exists( 'stopReason', $state );
				if ( array_key_exists( 'stopFile', $state ) && is_string( $state['stopFile'] ) ) {
					html_api_fuzz_stop_add_advertised_stop_file( $stop_files, $warnings, $state['stopFile'], $state );
				} elseif ( $runner_active || $runner_unknown ) {
					html_api_fuzz_stop_add_stop_file( $stop_files, $default_stop_file );
					$warnings[] = 'runner stopFile is missing or malformed; wrote the run-dir stop file, but the exact watched file may be unknown.';
			} else {
				html_api_fuzz_stop_add_stop_file( $stop_files, $default_stop_file );
			}
			if ( $runner_active ) {
				$active = true;
				html_api_fuzz_stop_add_stop_file( $stop_files, $default_stop_file );
			}
		}
	}
	html_api_fuzz_stop_add_stop_file( $stop_files, $default_stop_file );

	return array(
		'path'      => $path,
		'active'    => $active,
		'mtime'     => $latest_mtime,
		'stopFiles' => $stop_files,
		'warnings'  => $warnings,
	);
}

/**
 * The most recently active unfinished run, falling back to the most recently
 * active run of any state.
 */
function html_api_fuzz_stop_candidate_is_better( array $candidate, ?array $best ): bool {
	if ( null === $best ) {
		return true;
	}
	if ( $candidate['active'] !== $best['active'] ) {
		return $candidate['active'];
	}
	if ( $candidate['mtime'] !== $best['mtime'] ) {
		return $candidate['mtime'] > $best['mtime'];
	}

	return strcmp( $candidate['path'], $best['path'] ) > 0;
}

function html_api_fuzz_stop_latest_run_dir( string $artifacts_dir, float $stale_seconds ): ?array {
	$items = @scandir( $artifacts_dir );
	if ( false === $items ) {
		return null;
	}

	$best = null;
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $artifacts_dir . DIRECTORY_SEPARATOR . $item;
		if ( ! is_dir( $path ) ) {
			continue;
		}
		$candidate = html_api_fuzz_stop_inspect_run_dir( $path, $stale_seconds );
		if ( null === $candidate ) {
			continue;
		}
		if ( html_api_fuzz_stop_candidate_is_better( $candidate, $best ) ) {
			$best = $candidate;
		}
	}

	return $best;
}

$options = \HtmlApiFuzz\parse_cli_options( $argv );
if ( \HtmlApiFuzz\option_bool( $options, 'help', false ) || \HtmlApiFuzz\option_bool( $options, 'h', false ) ) {
	html_api_fuzz_stop_usage();
	exit( 0 );
}

$run_dir = \HtmlApiFuzz\option_string( $options, 'run-dir', $options['_'][0] ?? null );
if ( array_key_exists( 'run-dir', $options ) && ( true === $options['run-dir'] || null === $run_dir || '' === $run_dir ) ) {
	fwrite( STDERR, "Expected --run-dir to be a non-empty path.\n" );
	exit( 1 );
}
$stop_file_override = \HtmlApiFuzz\option_string( $options, 'stop-file', null );
if ( array_key_exists( 'stop-file', $options ) && ( true === $options['stop-file'] || null === $stop_file_override || '' === $stop_file_override ) ) {
	fwrite( STDERR, "Expected --stop-file to be a non-empty path.\n" );
	exit( 1 );
}
if ( array_key_exists( 'stop-stale-seconds', $options ) && true === $options['stop-stale-seconds'] ) {
	fwrite( STDERR, "Expected --stop-stale-seconds to be numeric.\n" );
	exit( 1 );
}
if ( array_key_exists( 'stop-stale-seconds', $options ) && ! is_numeric( $options['stop-stale-seconds'] ) ) {
	fwrite( STDERR, "Expected --stop-stale-seconds to be numeric.\n" );
	exit( 1 );
}
if ( array_key_exists( 'artifacts-dir', $options ) && ( true === $options['artifacts-dir'] || '' === $options['artifacts-dir'] ) ) {
	fwrite( STDERR, "Expected --artifacts-dir to be a non-empty path.\n" );
	exit( 1 );
}
if ( null === $run_dir && null !== $stop_file_override && array_key_exists( 'artifacts-dir', $options ) ) {
	fwrite( STDERR, "Pass --run-dir with --artifacts-dir --stop-file, or pass only --stop-file to write a known stop file directly.\n" );
	exit( 1 );
}

$looks_finished       = false;
$stale_seconds        = max( 10.0, \HtmlApiFuzz\option_float( $options, 'stop-stale-seconds', 120.0 ) );
$candidate            = null;
$direct_stop_file_only = null === $run_dir && null !== $stop_file_override;
if ( null === $run_dir && ! $direct_stop_file_only ) {
	$artifacts_dir = \HtmlApiFuzz\option_string( $options, 'artifacts-dir', \HtmlApiFuzz\repo_root() . '/artifacts/html-api-fuzz' );
	$candidate     = html_api_fuzz_stop_latest_run_dir( $artifacts_dir, $stale_seconds );
	if ( null === $candidate ) {
		fwrite( STDERR, "No run directory found under {$artifacts_dir}; pass --run-dir.\n" );
		exit( 1 );
	}
	$run_dir        = $candidate['path'];
	$looks_finished = ! $candidate['active'];
	if ( $looks_finished ) {
		fwrite( STDERR, "Warning: no unfinished run found; targeting {$run_dir}, which already looks stopped.\n" );
	}
}

if ( null !== $run_dir && ! is_dir( $run_dir ) ) {
	fwrite( STDERR, "Not a directory: {$run_dir}\n" );
	exit( 1 );
}

if ( null !== $run_dir && null === $candidate ) {
	$candidate = html_api_fuzz_stop_inspect_run_dir( $run_dir, $stale_seconds );
	if ( null === $candidate && null === $stop_file_override ) {
		$candidate = array(
			'path'      => $run_dir,
			'active'    => true,
			'mtime'     => 0,
			'stopFiles' => array(),
			'warnings'  => array( 'no run state found; writing only the run-dir stop file.' ),
		);
	}
}

$warnings = array();
foreach ( $candidate['warnings'] ?? array() as $warning ) {
	if ( is_string( $warning ) ) {
		$warnings[] = $warning;
	}
}

$stop_files = array();
if ( null !== $stop_file_override ) {
	html_api_fuzz_stop_add_stop_file( $stop_files, $stop_file_override );
}
foreach ( $candidate['stopFiles'] ?? array() as $stop_file ) {
	if ( is_string( $stop_file ) ) {
		html_api_fuzz_stop_add_stop_file( $stop_files, $stop_file );
	}
}
if ( array() === $stop_files && null !== $run_dir ) {
	html_api_fuzz_stop_add_stop_file( $stop_files, rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/STOP' );
}
if ( null !== $run_dir ) {
	html_api_fuzz_stop_add_stop_file( $stop_files, rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/STOP' );
}
if ( array() === $stop_files ) {
	fwrite( STDERR, "No stop file could be determined.\n" );
	exit( 1 );
}

$write_stop_files = $stop_files;
$primary_stop_file = $stop_files[0];
if ( null !== $run_dir ) {
	$run_stop_file    = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/STOP';
	$primary_stop_file = $run_stop_file;
	$write_stop_files = array( $run_stop_file );
	foreach ( $stop_files as $stop_file ) {
		html_api_fuzz_stop_add_stop_file( $write_stop_files, $stop_file );
	}
}

$already            = true;
foreach ( $write_stop_files as $stop_file ) {
	$already = $already && is_file( $stop_file );
}

$write_failures = array();
foreach ( $write_stop_files as $stop_file ) {
	if ( is_file( $stop_file ) ) {
		continue;
	}
	try {
		\HtmlApiFuzz\write_json_file(
			$stop_file,
			array(
				'kind'        => 'html-api-fuzz-stop-request',
				'requestedAt' => gmdate( 'c' ),
			)
		);
	} catch ( \Throwable $e ) {
		$write_failures[] = "Could not write {$stop_file}: {$e->getMessage()}";
		continue;
	}
	// write_json_file does not check the file_put_contents result; the file's
	// existence is the truth condition the runner acts on.
	if ( ! is_file( $stop_file ) ) {
		$write_failures[] = "Could not write {$stop_file}.";
	}
}
if ( array() !== $write_failures ) {
	foreach ( $write_failures as $failure ) {
		fwrite( STDERR, "{$failure}\n" );
	}
	exit( 1 );
}

$ok = array() === $warnings;
foreach ( $warnings as $warning ) {
	fwrite( STDERR, "Warning: {$warning}\n" );
}

echo \HtmlApiFuzz\json_encode_safe(
	array(
		'ok'               => $ok,
		'runDir'           => $run_dir,
		'stopFile'         => $primary_stop_file,
		'stopFiles'        => $write_stop_files,
		'alreadyRequested' => $already,
		'looksFinished'    => $looks_finished,
		'warnings'         => $warnings,
	)
) . "\n";
exit( $ok ? 0 : 2 );
