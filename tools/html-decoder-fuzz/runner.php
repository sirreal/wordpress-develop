<?php
/**
 * Orchestrates parallel decoder-fuzzer worker lanes.
 *
 *     php tools/html-decoder-fuzz/runner.php --lanes 4 --duration-seconds 60
 *
 * Exit codes: 0 clean, 1 findings or stalls, 2 harness error.
 */

namespace HtmlDecoderFuzz;

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
		'max-bytes'        => 4096,
		'output-dir'       => '',
		'stall-timeout'    => 120,
	)
);

Cli::require_int_at_least( $options, 'lanes', 1 );
Cli::require_int_at_least( $options, 'duration-seconds', 0 );
Cli::require_int_at_least( $options, 'max-cases', 0 );
Cli::require_int_at_least( $options, 'cases-per-batch', 1 );
Cli::require_int_at_least( $options, 'seed-base', 0 );
Cli::require_int_at_least( $options, 'max-bytes', 1 );
Cli::require_int_at_least( $options, 'stall-timeout', 1 );

$repo_root  = Bootstrap::repo_root();
$output_dir = $options['output-dir'];
if ( '' === $output_dir ) {
	$now        = microtime( true );
	$output_dir = sprintf(
		'%s/artifacts/html-decoder-fuzz/run-%s-%06d-p%d',
		$repo_root,
		gmdate( 'Ymd-His', (int) $now ),
		(int) ( ( $now - floor( $now ) ) * 1000000 ),
		getmypid()
	);
}
if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create output dir {$output_dir}\n" );
	exit( 2 );
}
if ( ! is_writable( $output_dir ) ) {
	fwrite( STDERR, "Output dir is not writable: {$output_dir}\n" );
	exit( 2 );
}

$seed_base = $options['seed-base'];
if ( 0 === $seed_base ) {
	$seed_base = (int) ( microtime( true ) * 1000 ) % 1000000000;
}

$summary_path = "{$output_dir}/summary.ndjson";
$summary      = fopen( $summary_path, 'ab' );
if ( false === $summary ) {
	fwrite( STDERR, "Cannot open summary file {$summary_path}\n" );
	exit( 2 );
}
$started_at   = microtime( true );
$deadline     = $options['duration-seconds'] > 0 ? $started_at + $options['duration-seconds'] : null;

$state = array(
	'started_at'    => gmdate( 'c' ),
	'seed_base'     => $seed_base,
	'options'       => $options,
	'git'           => Cli::git_metadata( $repo_root ),
	'cases'         => 0,
	'failures'      => 0,
	'bytes'         => 0,
	'by_strategy'   => array(),
	'by_context'    => array(),
	'failure_seeds' => array(),
	'stalled_seeds' => array(),
	'worker_errors' => array(),
	'harness_errors' => 0,
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
	if ( ! is_resource( $process ) || ! isset( $pipes[1] ) || ! is_resource( $pipes[1] ) ) {
		fwrite( STDERR, "Cannot spawn worker lane {$lane_id}\n" );
		exit( 2 );
	}

	stream_set_blocking( $pipes[1], false );

	return array(
		'id'          => $lane_id,
		'seed'        => $seed,
		'process'     => $process,
			'stdout'            => $pipes[1],
			'buffer'            => '',
			'last_output'       => microtime( true ),
			'reported_failures' => 0,
		);
	};

$write_state = static function () use ( &$state, $output_dir, $started_at ): bool {
	$state['elapsed_sec'] = round( microtime( true ) - $started_at, 1 );
	$state_json = json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $state_json ) {
		return false;
	}

	return Cli::write_file( "{$output_dir}/state.json", $state_json );
};

$stop_requested = false;
$summary_write_failed = false;

$handle_line = static function ( string $line, int $lane_id ) use ( &$state, &$stop_requested, &$summary_write_failed, $summary, $summary_path ): ?string {
	$summary_line = $line . "\n";
	if ( ! Cli::write_stream( $summary, $summary_line ) && ! $summary_write_failed ) {
		$summary_write_failed   = true;
		++$state['harness_errors'];
		$state['stop_reason'] = 'harness-error';
		$stop_requested       = true;
		fwrite( STDERR, "Cannot write summary file {$summary_path}\n" );
	}

	$record = json_decode( $line, true );
	if ( ! is_array( $record ) ) {
		++$state['harness_errors'];
		$state['stop_reason'] = 'harness-error';
		$stop_requested       = true;
		fwrite( STDERR, "invalid worker output on lane {$lane_id}\n" );
		return 'invalid';
	}

	switch ( $record['type'] ?? '' ) {
		case 'failure':
			if ( ! isset( $record['seed'], $record['case'], $record['context'], $record['signatures'] ) || ! is_array( $record['signatures'] ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed failure record on lane {$lane_id}\n" );
				return 'invalid';
			}
			++$state['failures'];
			$state['failure_seeds'][] = array(
				'seed'       => $record['seed'],
				'case'       => $record['case'],
				'context'    => $record['context'],
				'signatures' => $record['signatures'],
				'artifact'   => $record['artifact_dir'] ?? null,
			);
			fwrite( STDERR, "FAILURE lane {$lane_id} seed {$record['seed']} case {$record['case']}: " . implode( ', ', $record['signatures'] ) . "\n" );
			return 'failure';

		case 'oracle-event':
			$state['oracle_events'][] = $record;
			$oracle = $record['oracle'] ?? 'unknown';
			$detail = $record['detail'] ?? 'no detail';
			fwrite( STDERR, "oracle event: {$oracle}: {$detail}\n" );
			return 'oracle-event';

		case 'fatal':
			++$state['harness_errors'];
			$state['oracle_events'][] = $record;
			$state['stop_reason']     = 'harness-error';
			$stop_requested           = true;
			$reason = $record['reason'] ?? 'unknown';
			fwrite( STDERR, "worker fatal: {$reason}\n" );
			return 'fatal';

		case 'done':
			if ( ! isset( $record['stats'] ) || ! is_array( $record['stats'] ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed done record on lane {$lane_id}\n" );
				return 'invalid';
			}
			$stats            = $record['stats'];
			if ( ! isset( $stats['cases'], $stats['bytes'], $stats['by_strategy'], $stats['by_context'] ) || ! is_array( $stats['by_strategy'] ) || ! is_array( $stats['by_context'] ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed done stats on lane {$lane_id}\n" );
				return 'invalid';
			}
			$state['cases']  += $stats['cases'];
			$state['bytes']  += $stats['bytes'];
			foreach ( $stats['by_strategy'] as $strategy => $count ) {
				$state['by_strategy'][ $strategy ] = ( $state['by_strategy'][ $strategy ] ?? 0 ) + $count;
			}
			foreach ( $stats['by_context'] as $context => $count ) {
				$state['by_context'][ $context ] = ( $state['by_context'][ $context ] ?? 0 ) + $count;
			}
			return 'done';

		case 'progress':
		case 'start':
			return $record['type'];
	}

	++$state['harness_errors'];
	$state['stop_reason'] = 'harness-error';
	$stop_requested       = true;
	fwrite( STDERR, "unknown worker record type on lane {$lane_id}\n" );
	return 'invalid';
};

for ( $i = 0; $i < max( 1, $options['lanes'] ); $i++ ) {
	$lanes[ $i ] = $spawn_lane( $i );
	++$state['batches'];
}

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
				if ( '' !== $line && 'failure' === $handle_line( $line, $lane_id ) ) {
					++$lane['reported_failures'];
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

		$rest = stream_get_contents( $lane['stdout'] );
		if ( false === $rest ) {
			++$state['harness_errors'];
			$state['stop_reason'] = 'harness-error';
			$stop_requested       = true;
			fwrite( STDERR, "cannot read remaining worker output on lane {$lane_id}\n" );
			$rest = '';
		}
		$tail = $lane['buffer'] . $rest;
		if ( '' !== $tail ) {
			foreach ( explode( "\n", $tail ) as $line ) {
				if ( '' !== $line && 'failure' === $handle_line( $line, $lane_id ) ) {
					++$lane['reported_failures'];
				}
			}
		}
		fclose( $lane['stdout'] );
		$close_code = proc_close( $lane['process'] );
		$exit_code  = $status['exitcode'] ?? $close_code;
		if ( -1 === $exit_code ) {
			$exit_code = $close_code;
		}
		$accepted_failure_exit = 1 === $exit_code && $lane['reported_failures'] > 0;
		if ( 0 !== $exit_code && ! $accepted_failure_exit && ! in_array( $lane['seed'], $state['stalled_seeds'], true ) ) {
			$state['worker_errors'][] = array(
				'lane' => $lane_id,
				'seed' => $lane['seed'],
				'exit_code' => $exit_code,
			);
			++$state['harness_errors'];
			$state['stop_reason'] = 'harness-error';
			$stop_requested       = true;
			fwrite( STDERR, "worker error lane {$lane_id} seed {$lane['seed']}: exit {$exit_code}\n" );
		}
		unset( $lanes[ $lane_id ] );

		if ( ! $stop_requested ) {
			$lanes[ $lane_id ] = $spawn_lane( $lane_id );
			++$state['batches'];
		}
	}

	if ( microtime( true ) - $last_state_write > 5 ) {
		if ( ! $write_state() ) {
			++$state['harness_errors'];
			$state['stop_reason'] = 'harness-error';
			$stop_requested       = true;
			fwrite( STDERR, "Cannot write state file {$output_dir}/state.json\n" );
		}
		$last_state_write = microtime( true );
	}
}

if ( null === $state['stop_reason'] ) {
	$state['stop_reason'] = 'lanes-exited';
}
$state['finished_at'] = gmdate( 'c' );
if ( ! $write_state() ) {
	fwrite( STDERR, "Cannot write state file {$output_dir}/state.json\n" );
	fclose( $summary );
	exit( 2 );
}
fclose( $summary );

$elapsed = round( microtime( true ) - $started_at, 1 );
fwrite(
	STDERR,
	sprintf(
		"Done: %d cases, %d failures, %d stalled, %s bytes in %ss. Artifacts: %s\n",
		$state['cases'],
		$state['failures'],
		count( $state['stalled_seeds'] ),
		number_format( $state['bytes'] ),
		$elapsed,
		$output_dir
	)
);

if ( $state['harness_errors'] > 0 || array() !== $state['worker_errors'] ) {
	exit( 2 );
}

exit( ( $state['failures'] > 0 || array() !== $state['stalled_seeds'] ) ? 1 : 0 );
