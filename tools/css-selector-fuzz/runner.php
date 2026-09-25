#!/usr/bin/env php
<?php
/**
 * CSS selector fuzzer runner.
 *
 * Spawns worker processes over chunks of the seed space so that fatal
 * errors and hangs in the target code are isolated and attributed to a
 * specific seed.
 *
 * Usage:
 *   php tools/css-selector-fuzz/runner.php \
 *       [--start-seed N] [--max-seeds N] [--duration-seconds N] \
 *       [--chunk-size N] [--timeout-ms N] [--output-dir DIR] \
 *       [--stop-on-failure]
 *
 * Artifacts ( kept intentionally small ):
 *   OUTPUT_DIR/state.json       — run state and counters
 *   OUTPUT_DIR/failures.ndjson  — one line per invariant failure
 */

require_once __DIR__ . '/lib/autoload.php';

use function CssSelectorFuzz\append_ndjson;
use function CssSelectorFuzz\ensure_dir;
use function CssSelectorFuzz\git_metadata;
use function CssSelectorFuzz\json_encode_safe;
use function CssSelectorFuzz\option_bool;
use function CssSelectorFuzz\option_int;
use function CssSelectorFuzz\option_string;
use function CssSelectorFuzz\parse_cli_options;
use function CssSelectorFuzz\repo_root;
use function CssSelectorFuzz\timestamp;
use function CssSelectorFuzz\write_json_file;

/**
 * Runs a PHP child process with a wall-clock timeout.
 *
 * @return array{code: int|null, timedOut: bool, stdout: string, stderr: string, durationMs: int}
 */
function css_selector_fuzz_run_php( array $args, int $timeout_ms ): array {
	$command = array_merge(
		array( PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=stderr', '-d', 'memory_limit=256M' ),
		$args
	);

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$started = microtime( true );
	$proc    = proc_open( $command, $descriptors, $pipes, repo_root() );
	if ( ! is_resource( $proc ) ) {
		return array(
			'code'       => null,
			'timedOut'   => false,
			'stdout'     => '',
			'stderr'     => 'proc_open failed',
			'durationMs' => 0,
		);
	}

	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stdout    = '';
	$stderr    = '';
	$timed_out = false;
	$deadline  = $started + $timeout_ms / 1000;

	while ( true ) {
		$status  = proc_get_status( $proc );
		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );

		if ( ! $status['running'] ) {
			$code = $status['exitcode'];
			break;
		}
		if ( microtime( true ) > $deadline ) {
			$timed_out = true;
			proc_terminate( $proc, 9 );
			$code = null;
			break;
		}
		usleep( 10000 );
	}

	$stdout .= (string) stream_get_contents( $pipes[1] );
	$stderr .= (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );

	return array(
		'code'       => $code,
		'timedOut'   => $timed_out,
		'stdout'     => $stdout,
		'stderr'     => $stderr,
		'durationMs' => (int) round( 1000 * ( microtime( true ) - $started ) ),
	);
}

/** Extracts the batch summary from worker stdout, or null. */
function css_selector_fuzz_worker_summary( string $stdout ): ?array {
	foreach ( array_reverse( explode( "\n", trim( $stdout ) ) ) as $line ) {
		$decoded = json_decode( $line, true );
		if ( is_array( $decoded ) && 'css-selector-fuzz-batch-summary' === ( $decoded['kind'] ?? null ) ) {
			return $decoded;
		}
	}
	return null;
}

/** Merges per-bucket/per-target match assertion counts. */
function css_selector_fuzz_merge_match_stats( array &$target, array $source ): void {
	foreach ( $source as $bucket => $targets ) {
		foreach ( $targets as $match_target => $stats ) {
			if ( ! isset( $target[ $bucket ][ $match_target ] ) ) {
				$target[ $bucket ][ $match_target ] = array(
					'assertions' => 0,
					'nonVacuous' => 0,
				);
			}
			$target[ $bucket ][ $match_target ]['assertions'] += (int) ( $stats['assertions'] ?? 0 );
			$target[ $bucket ][ $match_target ]['nonVacuous'] += (int) ( $stats['nonVacuous'] ?? 0 );
		}
	}
}

/** Adds derived rates after all count aggregation is finished. */
function css_selector_fuzz_finalize_match_stats( array $stats ): array {
	foreach ( $stats as $bucket => $targets ) {
		foreach ( $targets as $match_target => $counts ) {
			$assertions  = (int) ( $counts['assertions'] ?? 0 );
			$non_vacuous = (int) ( $counts['nonVacuous'] ?? 0 );
			$vacuous     = max( 0, $assertions - $non_vacuous );

			$stats[ $bucket ][ $match_target ]['vacuous']         = $vacuous;
			$stats[ $bucket ][ $match_target ]['nonVacuousRate'] = $assertions > 0 ? round( $non_vacuous / $assertions, 4 ) : 0.0;
			$stats[ $bucket ][ $match_target ]['vacuousRate']    = $assertions > 0 ? round( $vacuous / $assertions, 4 ) : 0.0;
		}
	}
	return $stats;
}

function css_selector_fuzz_write_state( string $state_path, array $state ): void {
	$state['matchStats'] = css_selector_fuzz_finalize_match_stats( $state['matchStats'] ?? array() );
	write_json_file( $state_path, $state );
}

function css_selector_fuzz_state_for_output( array $state ): array {
	$state['matchStats'] = css_selector_fuzz_finalize_match_stats( $state['matchStats'] ?? array() );
	return $state;
}

$options = parse_cli_options( $argv );
if ( option_bool( $options, 'help', false ) || option_bool( $options, 'h', false ) ) {
	echo "Usage: php tools/css-selector-fuzz/runner.php [--start-seed N] [--max-seeds N] [--duration-seconds N] [--chunk-size N] [--timeout-ms N] [--output-dir DIR] [--stop-on-failure]\n";
	exit( 0 );
}

$start_seed       = option_int( $options, 'start-seed', 1 );
$max_seeds        = option_int( $options, 'max-seeds', 1000 );
$duration_seconds = option_int( $options, 'duration-seconds', 120 );
$chunk_size       = max( 1, option_int( $options, 'chunk-size', 200 ) );
$timeout_ms       = option_int( $options, 'timeout-ms', 0 );
$stop_on_failure  = option_bool( $options, 'stop-on-failure', false );
$output_dir       = option_string( $options, 'output-dir', repo_root() . '/artifacts/css-selector-fuzz/run-' . timestamp() );

if ( $max_seeds < 1 ) {
	fwrite( STDERR, "--max-seeds must be at least 1; refusing to run unbounded.\n" );
	exit( 1 );
}
if ( 0 === $timeout_ms ) {
	// Generous per-chunk budget: ~50ms per case plus startup.
	$timeout_ms = $chunk_size * 50 + 10000;
}

ensure_dir( $output_dir );
$failures_path = $output_dir . '/failures.ndjson';
$state_path    = $output_dir . '/state.json';
$worker_script = __DIR__ . '/worker.php';

$state = array(
	'kind'             => 'css-selector-fuzz-runner-state',
	'startedAt'        => gmdate( 'c' ),
	'updatedAt'        => gmdate( 'c' ),
	'git'              => git_metadata(),
	'phpVersion'       => PHP_VERSION,
	'outputDir'        => $output_dir,
	'startSeed'        => $start_seed,
	'maxSeeds'         => $max_seeds,
	'durationSeconds'  => $duration_seconds,
	'chunkSize'        => $chunk_size,
	'casesCompleted'   => 0,
	'failures'         => 0,
	'crashes'          => 0,
	'buckets'          => array(),
	'signatures'       => array(),
	'lexbor'           => array(),
	'matchStats'       => array(),
	'nextSeed'         => $start_seed,
	'stopReason'       => null,
);
css_selector_fuzz_write_state( $state_path, $state );

$deadline = $duration_seconds > 0 ? microtime( true ) + $duration_seconds : null;
$seed     = $start_seed;
$end_seed = $start_seed + $max_seeds;

while ( $seed < $end_seed ) {
	if ( null !== $deadline && microtime( true ) > $deadline ) {
		$state['stopReason'] = 'duration-elapsed';
		break;
	}

	$count = min( $chunk_size, $end_seed - $seed );
	$args  = array(
		$worker_script,
		'--start-seed',
		(string) $seed,
		'--count',
		(string) $count,
		'--failures-out',
		$failures_path,
		'--progress-file',
		$output_dir . '/progress.txt',
	);

	$proc    = css_selector_fuzz_run_php( $args, $timeout_ms );
	$summary = css_selector_fuzz_worker_summary( $proc['stdout'] );

	if ( null === $summary ) {
		/*
		 * The worker crashed, hung, or died fatally. Re-run each seed of the
		 * chunk in its own process to attribute the crash.
		 */
		fwrite( STDERR, "chunk seed={$seed} count={$count}: worker crashed/hung; isolating…\n" );
		for ( $isolated = $seed; $isolated < $seed + $count; $isolated++ ) {
			$single = css_selector_fuzz_run_php(
				array(
					$worker_script,
					'--start-seed',
					(string) $isolated,
					'--count',
					'1',
					'--failures-out',
					$failures_path,
					'--determinism-every',
					'0',
				),
				max( 5000, (int) ( $timeout_ms / $count ) + 5000 )
			);
			$single_summary = css_selector_fuzz_worker_summary( $single['stdout'] );
			if ( null === $single_summary ) {
				++$state['crashes'];
				++$state['failures'];
				append_ndjson(
					$failures_path,
					array(
						'kind'       => 'css-selector-fuzz-failure',
						'seed'       => $isolated,
						'invariant'  => $single['timedOut'] ? 'worker-timeout' : 'worker-crash',
						'signature'  => $single['timedOut'] ? 'worker-timeout' : 'worker-crash',
						'exitCode'   => $single['code'],
						'stderrTail' => substr( $single['stderr'], -2000 ),
					)
				);
				$key                         = $single['timedOut'] ? 'worker-timeout' : 'worker-crash';
				$state['signatures'][ $key ] = ( $state['signatures'][ $key ] ?? 0 ) + 1;
			} else {
				++$state['casesCompleted'];
				$state['failures'] += $single_summary['failures'];
				foreach ( $single_summary['buckets'] as $bucket => $bucket_count ) {
					$state['buckets'][ $bucket ] = ( $state['buckets'][ $bucket ] ?? 0 ) + $bucket_count;
				}
				foreach ( $single_summary['signatures'] as $signature => $signature_count ) {
					$state['signatures'][ $signature ] = ( $state['signatures'][ $signature ] ?? 0 ) + $signature_count;
				}
				foreach ( $single_summary['lexbor'] ?? array() as $lexbor_state => $lexbor_count ) {
					$state['lexbor'][ $lexbor_state ] = ( $state['lexbor'][ $lexbor_state ] ?? 0 ) + $lexbor_count;
				}
				css_selector_fuzz_merge_match_stats( $state['matchStats'], $single_summary['matchStats'] ?? array() );
			}
		}
	} else {
		$state['casesCompleted'] += array_sum( $summary['buckets'] );
		$state['failures']       += $summary['failures'];
		foreach ( $summary['buckets'] as $bucket => $bucket_count ) {
			$state['buckets'][ $bucket ] = ( $state['buckets'][ $bucket ] ?? 0 ) + $bucket_count;
		}
		foreach ( $summary['signatures'] as $signature => $signature_count ) {
			$state['signatures'][ $signature ] = ( $state['signatures'][ $signature ] ?? 0 ) + $signature_count;
		}
		foreach ( $summary['lexbor'] ?? array() as $lexbor_state => $lexbor_count ) {
			$state['lexbor'][ $lexbor_state ] = ( $state['lexbor'][ $lexbor_state ] ?? 0 ) + $lexbor_count;
		}
		css_selector_fuzz_merge_match_stats( $state['matchStats'], $summary['matchStats'] ?? array() );
	}

	$seed             += $count;
	$state['nextSeed'] = $seed;
	$state['updatedAt'] = gmdate( 'c' );
	css_selector_fuzz_write_state( $state_path, $state );

	if ( $stop_on_failure && $state['failures'] > 0 ) {
		$state['stopReason'] = 'stop-on-failure';
		break;
	}
}

if ( null === $state['stopReason'] ) {
	$state['stopReason'] = 'max-seeds';
}
$state['updatedAt'] = gmdate( 'c' );
css_selector_fuzz_write_state( $state_path, $state );

/*
 * The lexbor differential is the third oracle. If it ever ran ( 'compared' )
 * it was built and live; any 'unavailable' or 'error' tally then means it
 * was missing for some cases or died mid-run, so part of the run had only
 * two oracles. Surface that loudly rather than letting a green run hide it.
 */
$lexbor       = $state['lexbor'];
$lexbor_ran   = ( $lexbor['compared'] ?? 0 ) > 0;
$lexbor_lost  = ( $lexbor['unavailable'] ?? 0 ) + ( $lexbor['error'] ?? 0 );
if ( $lexbor_ran && $lexbor_lost > 0 ) {
	fwrite( STDERR, "WARNING: lexbor third oracle was unavailable/errored for {$lexbor_lost} case(s); those ran with two oracles.\n" );
} elseif ( ! $lexbor_ran ) {
	fwrite( STDERR, "NOTE: lexbor third oracle never ran (harness not built?); run `sh tools/css-selector-fuzz/lexbor/build.sh` for the differential.\n" );
}

echo json_encode_safe( css_selector_fuzz_state_for_output( $state ) ) . "\n";
exit( 0 === $state['failures'] ? 0 : 2 );
