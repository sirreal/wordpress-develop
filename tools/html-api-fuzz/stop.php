#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

/**
 * Requests a graceful stop of a fuzz run by creating RUN_DIR/STOP. Each
 * runner lane finishes its current batch and exits; the watcher performs a
 * final scan once all lanes have stopped; the codex orchestrator stops
 * launching and exits when its running jobs finish.
 */
function html_api_fuzz_stop_usage(): void {
	echo "Usage: php tools/html-api-fuzz/stop.php [--run-dir DIR] [--artifacts-dir DIR]\n";
	echo "Without --run-dir, targets the most recently active unfinished run under artifacts/html-api-fuzz (or --artifacts-dir).\n";
}

/**
 * Describes one candidate run directory: whether any of its runners or its
 * launcher still looks unfinished, and how recently its state files changed.
 * Directory mtimes are useless here — lanes write into subdirectories.
 */
function html_api_fuzz_stop_inspect_run_dir( string $path ): ?array {
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
	foreach ( $state_paths as $state_path ) {
		$latest_mtime = max( $latest_mtime, (int) filemtime( $state_path ) );
		try {
			$state = \HtmlApiFuzz\read_json_file( $state_path );
		} catch ( \RuntimeException $e ) {
			// Mid-write: something is alive in there.
			$active = true;
			continue;
		}
		if ( ! is_array( $state ) ) {
			continue;
		}
		if ( 'html-api-fuzz-launcher-state' === ( $state['kind'] ?? null ) && false === ( $state['finished'] ?? null ) ) {
			$active = true;
		}
		if ( 'html-api-fuzz-runner-state' === ( $state['kind'] ?? null ) && null === ( $state['stopReason'] ?? 'missing' ) ) {
			$active = true;
		}
	}

	return array(
		'path'   => $path,
		'active' => $active,
		'mtime'  => $latest_mtime,
	);
}

/**
 * The most recently active unfinished run, falling back to the most recently
 * active run of any state.
 */
function html_api_fuzz_stop_latest_run_dir( string $artifacts_dir ): ?array {
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
		$candidate = html_api_fuzz_stop_inspect_run_dir( $path );
		if ( null === $candidate ) {
			continue;
		}
		if (
			null === $best
			|| ( $candidate['active'] && ! $best['active'] )
			|| ( $candidate['active'] === $best['active'] && $candidate['mtime'] > $best['mtime'] )
		) {
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
$looks_finished = false;
if ( null === $run_dir ) {
	$artifacts_dir = \HtmlApiFuzz\option_string( $options, 'artifacts-dir', \HtmlApiFuzz\repo_root() . '/artifacts/html-api-fuzz' );
	$candidate     = html_api_fuzz_stop_latest_run_dir( $artifacts_dir );
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

if ( ! is_dir( $run_dir ) ) {
	fwrite( STDERR, "Not a directory: {$run_dir}\n" );
	exit( 1 );
}

$stop_file = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/STOP';
$already   = is_file( $stop_file );
if ( ! $already ) {
	try {
		\HtmlApiFuzz\write_json_file(
			$stop_file,
			array(
				'kind'        => 'html-api-fuzz-stop-request',
				'requestedAt' => gmdate( 'c' ),
			)
		);
	} catch ( \Throwable $e ) {
		fwrite( STDERR, "Could not write {$stop_file}: {$e->getMessage()}\n" );
		exit( 1 );
	}
	// write_json_file does not check the file_put_contents result; the file's
	// existence is the truth condition the runner acts on.
	if ( ! is_file( $stop_file ) ) {
		fwrite( STDERR, "Could not write {$stop_file}.\n" );
		exit( 1 );
	}
}

echo \HtmlApiFuzz\json_encode_safe(
	array(
		'ok'               => true,
		'runDir'           => $run_dir,
		'stopFile'         => $stop_file,
		'alreadyRequested' => $already,
		'looksFinished'    => $looks_finished,
	)
) . "\n";
