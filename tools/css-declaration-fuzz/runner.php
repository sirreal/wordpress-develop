#!/usr/bin/env php
<?php
/** Process-isolated runner for the CSS declaration-list fuzzer. */

require_once __DIR__ . '/lib/autoload.php';

use function CssDeclarationFuzz\append_ndjson;
use function CssDeclarationFuzz\ensure_dir;
use function CssDeclarationFuzz\git_metadata;
use function CssDeclarationFuzz\json_encode_safe;
use function CssDeclarationFuzz\option_bool;
use function CssDeclarationFuzz\option_int;
use function CssDeclarationFuzz\option_string;
use function CssDeclarationFuzz\parse_cli_options;
use function CssDeclarationFuzz\repo_root;
use function CssDeclarationFuzz\write_json_file;

/** @return array{code:int|null,timedOut:bool,stdout:string,stderr:string,durationMs:int} */
function css_declaration_fuzz_run_php( array $args, int $timeout_ms ): array {
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
		return array( 'code' => null, 'timedOut' => false, 'stdout' => '', 'stderr' => 'proc_open failed', 'durationMs' => 0 );
	}

	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	$stdout    = '';
	$stderr    = '';
	$timed_out = false;
	$deadline  = $started + $timeout_ms / 1000;
	$code      = null;

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

function css_declaration_fuzz_summary( string $stdout ): ?array {
	foreach ( array_reverse( explode( "\n", trim( $stdout ) ) ) as $line ) {
		$decoded = json_decode( $line, true );
		if ( is_array( $decoded ) && 'css-declaration-fuzz-batch-summary' === ( $decoded['kind'] ?? null ) ) {
			return $decoded;
		}
	}
	return null;
}

function css_declaration_fuzz_merge_counts( array &$target, array $source ): void {
	foreach ( $source as $key => $count ) {
		$target[ $key ] = ( $target[ $key ] ?? 0 ) + (int) $count;
	}
}

$options = parse_cli_options( $argv );
if ( option_bool( $options, 'help', false ) || option_bool( $options, 'h', false ) ) {
	echo "Usage: php tools/css-declaration-fuzz/runner.php [--start-seed N] [--max-seeds N] [--duration-seconds N] [--chunk-size N] [--timeout-ms N] [--output-dir DIR] [--stop-on-failure]\n";
	exit( 0 );
}

$start_seed       = option_int( $options, 'start-seed', 1 );
$max_seeds        = option_int( $options, 'max-seeds', 1000 );
$duration_seconds = option_int( $options, 'duration-seconds', 120 );
$chunk_size       = max( 1, option_int( $options, 'chunk-size', 100 ) );
$timeout_ms       = option_int( $options, 'timeout-ms', $chunk_size * 100 + 10000 );
$stop_on_failure  = option_bool( $options, 'stop-on-failure', false );
$output_dir       = option_string( $options, 'output-dir', repo_root() . '/artifacts/css-declaration-fuzz/run-' . gmdate( 'Ymd-His' ) );

if ( $max_seeds < 1 ) {
	fwrite( STDERR, "--max-seeds must be at least 1; refusing to run unbounded.\n" );
	exit( 1 );
}

ensure_dir( $output_dir );
$failures_path = $output_dir . '/failures.ndjson';
$state_path    = $output_dir . '/state.json';
$worker_script = __DIR__ . '/worker.php';
$state         = array(
	'kind'            => 'css-declaration-fuzz-runner-state',
	'startedAt'       => gmdate( 'c' ),
	'updatedAt'       => gmdate( 'c' ),
	'git'             => git_metadata(),
	'phpVersion'      => PHP_VERSION,
	'outputDir'       => $output_dir,
	'startSeed'       => $start_seed,
	'maxSeeds'        => $max_seeds,
	'durationSeconds' => $duration_seconds,
	'chunkSize'       => $chunk_size,
	'casesCompleted'  => 0,
	'failures'        => 0,
	'crashes'         => 0,
	'buckets'         => array(),
	'signatures'      => array(),
	'tokenTypes'      => array(),
	'operations'      => array(),
	'nextSeed'        => $start_seed,
	'stopReason'      => null,
);
write_json_file( $state_path, $state );

$deadline = $duration_seconds > 0 ? microtime( true ) + $duration_seconds : null;
$seed     = $start_seed;
$end_seed = $start_seed + $max_seeds;

while ( $seed < $end_seed ) {
	if ( null !== $deadline && microtime( true ) >= $deadline ) {
		$state['stopReason'] = 'duration-elapsed';
		break;
	}

	$count   = min( $chunk_size, $end_seed - $seed );
	$process = css_declaration_fuzz_run_php(
		array( $worker_script, '--start-seed', (string) $seed, '--count', (string) $count, '--failures-out', $failures_path, '--max-failures', (string) ( $count * 32 ) ),
		$timeout_ms
	);
	$summary = css_declaration_fuzz_summary( $process['stdout'] );

	if ( null === $summary ) {
		fwrite( STDERR, "chunk seed={$seed} count={$count}: worker crashed or timed out; isolating seeds\n" );
		for ( $isolated = $seed; $isolated < $seed + $count; $isolated++ ) {
			$single         = css_declaration_fuzz_run_php(
				array( $worker_script, '--start-seed', (string) $isolated, '--count', '1', '--failures-out', $failures_path ),
				max( 5000, (int) ( $timeout_ms / $count ) + 5000 )
			);
			$single_summary = css_declaration_fuzz_summary( $single['stdout'] );
			if ( null === $single_summary ) {
				$signature = $single['timedOut'] ? 'worker-timeout' : 'worker-crash';
				++$state['casesCompleted'];
				++$state['failures'];
				++$state['crashes'];
				$state['signatures'][ $signature ] = ( $state['signatures'][ $signature ] ?? 0 ) + 1;
				append_ndjson(
					$failures_path,
					array(
						'kind'       => 'css-declaration-fuzz-failure',
						'seed'       => $isolated,
						'invariant'  => $signature,
						'signature'  => $signature,
						'exitCode'   => $single['code'],
						'stderrTail' => substr( $single['stderr'], -2000 ),
					)
				);
				continue;
			}
			$state['casesCompleted'] += (int) $single_summary['cases'];
			$state['failures']       += (int) $single_summary['failures'];
			css_declaration_fuzz_merge_counts( $state['buckets'], $single_summary['buckets'] );
			css_declaration_fuzz_merge_counts( $state['signatures'], $single_summary['signatures'] );
			css_declaration_fuzz_merge_counts( $state['tokenTypes'], $single_summary['tokenTypes'] );
			css_declaration_fuzz_merge_counts( $state['operations'], $single_summary['operations'] );
		}
	} else {
		$state['casesCompleted'] += (int) $summary['cases'];
		$state['failures']       += (int) $summary['failures'];
		css_declaration_fuzz_merge_counts( $state['buckets'], $summary['buckets'] );
		css_declaration_fuzz_merge_counts( $state['signatures'], $summary['signatures'] );
		css_declaration_fuzz_merge_counts( $state['tokenTypes'], $summary['tokenTypes'] );
		css_declaration_fuzz_merge_counts( $state['operations'], $summary['operations'] );
	}

	$seed               += $count;
	$state['nextSeed']   = $seed;
	$state['updatedAt']  = gmdate( 'c' );
	write_json_file( $state_path, $state );
	if ( $stop_on_failure && $state['failures'] > 0 ) {
		$state['stopReason'] = 'stop-on-failure';
		break;
	}
}

if ( null === $state['stopReason'] ) {
	$state['stopReason'] = 'max-seeds';
}
$state['updatedAt'] = gmdate( 'c' );
write_json_file( $state_path, $state );
echo json_encode_safe( $state ) . "\n";
exit( 0 === $state['failures'] ? 0 : 2 );
