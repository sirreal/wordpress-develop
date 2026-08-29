<?php
/**
 * Orchestrates parallel worker lanes with duration and case budgets.
 *
 *     php tools/encoding-fuzz/runner.php --lanes 4 --duration-seconds 60
 *     php tools/encoding-fuzz/runner.php --lanes 8 --duration-seconds 0 --max-cases 0   # indefinitely
 *
 * Each lane runs `worker.php` batches with sequentially assigned seeds.
 * Worker ndjson is appended to `summary.ndjson`; aggregate counters and
 * stop reason land in `state.json`. A lane producing no output for
 * `--stall-timeout` seconds is killed and recorded, and its seed is
 * reported so the hang can be reproduced.
 *
 * Exit codes: 0 clean, 1 failures found, 2 harness error.
 */

namespace EncodingFuzz;

require __DIR__ . '/lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

$options = Cli::parse_args(
	$argv,
	array(
		'lanes'            => 4,
		'duration-seconds' => 60,
		'max-cases'        => 0,
		'cases-per-batch'  => 2000,
		'seed-base'        => 0,
		'max-bytes'        => 65536,
		'external'         => 'auto',
		'output-dir'       => '',
		'stall-timeout'    => 120,
	)
);

$repo_root  = Bootstrap::repo_root();
$output_dir = $options['output-dir'];
if ( '' === $output_dir ) {
	$output_dir = $repo_root . '/artifacts/encoding-fuzz/run-' . gmdate( 'Ymd-His' );
}
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create output dir {$output_dir}\n" );
	exit( 2 );
}

$seed_base = $options['seed-base'];
if ( 0 === $seed_base ) {
	// Time-derived so repeated runs explore new seeds by default.
	$seed_base = (int) ( microtime( true ) * 1000 ) % 1000000000;
}

$summary_path = "{$output_dir}/summary.ndjson";
$summary      = fopen( $summary_path, 'ab' );
$started_at   = microtime( true );
$deadline     = $options['duration-seconds'] > 0 ? $started_at + $options['duration-seconds'] : null;

$state = array(
	'started_at'    => gmdate( 'c' ),
	'seed_base'     => $seed_base,
	'options'       => $options,
	'git'           => Cli::git_metadata( $repo_root ),
	'cases'         => 0,
	'failures'      => 0,
	'valid_inputs'  => 0,
	'bytes'         => 0,
	'by_strategy'   => array(),
	'failure_seeds' => array(),
	'stalled_seeds' => array(),
	'oracle_events' => array(),
	'batches'       => 0,
	'stop_reason'   => null,
);

$next_seed = $seed_base;
$lanes     = array();

$spawn_lane = static function ( int $lane_id ) use ( &$next_seed, $options, $output_dir ): array {
	$seed    = $next_seed++;
	$command = array(
		PHP_BINARY,
		__DIR__ . '/worker.php',
		'--seed',
		(string) $seed,
		'--cases',
		(string) $options['cases-per-batch'],
		'--max-bytes',
		(string) $options['max-bytes'],
		'--external',
		$options['external'],
		'--output-dir',
		$output_dir,
		'--progress-every',
		'500',
	);

	$process = proc_open(
		$command,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'file', "{$output_dir}/lane-{$lane_id}-stderr.log", 'a' ),
		),
		$pipes
	);

	stream_set_blocking( $pipes[1], false );

	return array(
		'id'          => $lane_id,
		'seed'        => $seed,
		'process'     => $process,
		'stdout'      => $pipes[1],
		'buffer'      => '',
		'last_output' => microtime( true ),
	);
};

$write_state = static function () use ( &$state, $output_dir, $started_at ): void {
	$state['elapsed_sec'] = round( microtime( true ) - $started_at, 1 );
	file_put_contents(
		"{$output_dir}/state.json",
		json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	);
};

$handle_line = static function ( string $line, int $lane_id ) use ( &$state, $summary ): void {
	fwrite( $summary, $line . "\n" );

	$record = json_decode( $line, true );
	if ( ! is_array( $record ) ) {
		return;
	}

	switch ( $record['type'] ?? '' ) {
		case 'failure':
			++$state['failures'];
			$state['failure_seeds'][] = array(
				'seed'       => $record['seed'],
				'case'       => $record['case'],
				'signatures' => $record['signatures'],
				'artifact'   => $record['artifact_dir'] ?? null,
			);
			fwrite( STDERR, "FAILURE lane {$lane_id} seed {$record['seed']} case {$record['case']}: " . implode( ', ', $record['signatures'] ) . "\n" );
			break;

		case 'oracle-event':
			$state['oracle_events'][] = $record;
			fwrite( STDERR, "oracle event: {$record['oracle']}: {$record['detail']}\n" );
			break;

		case 'fatal':
			$state['oracle_events'][] = $record;
			fwrite( STDERR, "worker fatal: {$record['reason']}\n" );
			break;

		case 'done':
			$stats                  = $record['stats'];
			$state['cases']        += $stats['cases'];
			$state['valid_inputs'] += $stats['valid_inputs'];
			$state['bytes']        += $stats['bytes'];
			foreach ( $stats['by_strategy'] as $strategy => $count ) {
				$state['by_strategy'][ $strategy ] = ( $state['by_strategy'][ $strategy ] ?? 0 ) + $count;
			}
			break;
	}
};

for ( $i = 0; $i < max( 1, $options['lanes'] ); $i++ ) {
	$lanes[ $i ] = $spawn_lane( $i );
	++$state['batches'];
}

$stop_requested = false;
$last_state_write = 0.0;

while ( array() !== $lanes ) {
	$now = microtime( true );

	if ( ! $stop_requested && null !== $deadline && $now >= $deadline ) {
		$state['stop_reason'] = 'duration';
		$stop_requested       = true;
	}

	if ( ! $stop_requested && $options['max-cases'] > 0 && $state['cases'] >= $options['max-cases'] ) {
		$state['stop_reason'] = 'max-cases';
		$stop_requested       = true;
	}

	$streams = array();
	foreach ( $lanes as $lane_id => $lane ) {
		$streams[ $lane_id ] = $lane['stdout'];
	}

	$read   = array_values( $streams );
	$write  = null;
	$except = null;
	if ( stream_select( $read, $write, $except, 0, 250000 ) > 0 ) {
		foreach ( $lanes as $lane_id => &$lane ) {
			$chunk = stream_get_contents( $lane['stdout'] );
			if ( false === $chunk || '' === $chunk ) {
				continue;
			}

			$lane['last_output'] = microtime( true );
			$lane['buffer']     .= $chunk;

			while ( false !== ( $newline = strpos( $lane['buffer'], "\n" ) ) ) {
				$line           = substr( $lane['buffer'], 0, $newline );
				$lane['buffer'] = substr( $lane['buffer'], $newline + 1 );
				if ( '' !== $line ) {
					$handle_line( $line, $lane_id );
				}
			}
		}
		unset( $lane );
	}

	foreach ( $lanes as $lane_id => $lane ) {
		$status  = proc_get_status( $lane['process'] );
		$stalled = ( microtime( true ) - $lane['last_output'] ) > $options['stall-timeout'];

		if ( $status['running'] && $stalled ) {
			proc_terminate( $lane['process'], 9 );
			$state['stalled_seeds'][] = $lane['seed'];
			fwrite( STDERR, "STALL lane {$lane_id} seed {$lane['seed']}: no output for {$options['stall-timeout']}s, killed\n" );
		} elseif ( $status['running'] ) {
			continue;
		}

		// Lane finished (or was just killed): flush remaining output.
		$rest = stream_get_contents( $lane['stdout'] );
		if ( is_string( $rest ) && '' !== $rest ) {
			foreach ( explode( "\n", $lane['buffer'] . $rest ) as $line ) {
				if ( '' !== $line ) {
					$handle_line( $line, $lane_id );
				}
			}
		}
		fclose( $lane['stdout'] );
		proc_close( $lane['process'] );
		unset( $lanes[ $lane_id ] );

		if ( ! $stop_requested ) {
			$lanes[ $lane_id ] = $spawn_lane( $lane_id );
			++$state['batches'];
		}
	}

	if ( microtime( true ) - $last_state_write > 5 ) {
		$write_state();
		$last_state_write = microtime( true );
	}
}

if ( null === $state['stop_reason'] ) {
	$state['stop_reason'] = 'lanes-exited';
}
$state['finished_at'] = gmdate( 'c' );
$write_state();
fclose( $summary );

$elapsed = round( microtime( true ) - $started_at, 1 );
fwrite(
	STDERR,
	sprintf(
		"Done: %d cases (%d valid inputs), %d failures, %d stalled, %s bytes in %ss. Artifacts: %s\n",
		$state['cases'],
		$state['valid_inputs'],
		$state['failures'],
		count( $state['stalled_seeds'] ),
		number_format( $state['bytes'] ),
		$elapsed,
		$output_dir
	)
);

exit( ( $state['failures'] > 0 || array() !== $state['stalled_seeds'] ) ? 1 : 0 );
