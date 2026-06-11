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
		'lanes'                       => 4,
		'duration-seconds'            => 60,
		'max-cases'                   => 0,
		'cases-per-batch'             => 2000,
		'seed-base'                   => 0,
		'max-bytes'                   => 4096,
		'mode'                        => 'oracle',
		'output-dir'                  => '',
		'stall-timeout'               => 120,
		'artifact-retention'          => 'bounded',
		'max-artifacts-per-signature' => 5,
		'summary-mode'                => 'failures',
		'max-stderr-bytes'            => 65536,
	)
);

Cli::require_int_at_least( $options, 'lanes', 1 );
Cli::require_int_at_least( $options, 'duration-seconds', 0 );
Cli::require_int_at_least( $options, 'max-cases', 0 );
Cli::require_int_at_least( $options, 'cases-per-batch', 1 );
Cli::require_int_at_least( $options, 'seed-base', 0 );
Cli::require_int_at_least( $options, 'max-bytes', 1 );
Cli::require_int_at_least( $options, 'stall-timeout', 1 );
Cli::require_int_at_least( $options, 'max-artifacts-per-signature', 0 );
Cli::require_int_at_least( $options, 'max-stderr-bytes', 0 );
Cli::require_one_of( $options, 'mode', Cli::valid_modes() );
Cli::require_one_of( $options, 'artifact-retention', array( 'bounded', 'all', 'none' ) );
Cli::require_one_of( $options, 'summary-mode', array( 'all', 'failures', 'none' ) );

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
if ( ! is_readable( $output_dir ) ) {
	fwrite( STDERR, "Output dir is not readable: {$output_dir}\n" );
	exit( 2 );
}

$seed_base = $options['seed-base'];
if ( 0 === $seed_base ) {
	$seed_base = (int) ( microtime( true ) * 1000 ) % 1000000000;
}

$stderr_bytes_by_lane = array();
$stderr_truncated_lanes = array();
$startup_truncated_stderr_logs = array();
$stderr_lane_ids = array();
for ( $lane_id = 0; $lane_id < max( 1, $options['lanes'] ); $lane_id++ ) {
	$stderr_lane_ids[ $lane_id ] = true;
}
$output_items = new \FilesystemIterator( $output_dir, \FilesystemIterator::SKIP_DOTS );
foreach ( $output_items as $output_item ) {
	if ( 1 === preg_match( '/^lane-(\d+)-stderr\.log$/', $output_item->getBasename(), $match ) ) {
		$stderr_lane_ids[ (int) $match[1] ] = true;
	}
}
ksort( $stderr_lane_ids, SORT_NUMERIC );

foreach ( array_keys( $stderr_lane_ids ) as $lane_id ) {
	$stderr_path = "{$output_dir}/lane-{$lane_id}-stderr.log";
	if ( Cli::is_linked_file( $stderr_path ) ) {
		fwrite( STDERR, "Lane stderr log is a linked file: {$stderr_path}\n" );
		exit( 2 );
	}
	if ( ! is_file( $stderr_path ) ) {
		continue;
	}

	$stderr_size = filesize( $stderr_path );
	if ( ! is_int( $stderr_size ) ) {
		fwrite( STDERR, "Cannot stat lane stderr log {$stderr_path}\n" );
		exit( 2 );
	}
	if ( $stderr_size > $options['max-stderr-bytes'] ) {
		$truncated = $options['max-stderr-bytes'] > 0
			? file_get_contents( $stderr_path, false, null, 0, $options['max-stderr-bytes'] )
			: '';
		if ( ! is_string( $truncated ) || ! Cli::write_file( $stderr_path, $truncated ) ) {
			fwrite( STDERR, "Cannot truncate lane stderr log {$stderr_path}\n" );
			exit( 2 );
		}
		$startup_truncated_stderr_logs[] = array(
			'lane'      => $lane_id,
			'bytes'     => $options['max-stderr-bytes'],
			'was_bytes' => $stderr_size,
		);
		$stderr_size = $options['max-stderr-bytes'];
	}

	$stderr_bytes_by_lane[ $lane_id ] = $stderr_size;
}

$summary_path = "{$output_dir}/summary.ndjson";
if ( 'none' !== $options['summary-mode'] && Cli::is_linked_file( $summary_path ) ) {
	fwrite( STDERR, "Summary file is a linked file: {$summary_path}\n" );
	exit( 2 );
}
$summary      = 'none' === $options['summary-mode'] ? null : fopen( $summary_path, 'ab' );
if ( false === $summary ) {
	fwrite( STDERR, "Cannot open summary file {$summary_path}\n" );
	exit( 2 );
}
$started_at   = microtime( true );
$deadline     = $options['duration-seconds'] > 0 ? $started_at + $options['duration-seconds'] : null;

$retained_artifacts_by_signature = array();
$retained_artifact_dirs          = array();
$startup_pruned_artifacts         = 0;
$startup_pruned_partial_artifacts = 0;
$startup_verification_unavailable = false;
$existing_artifacts_by_signature  = array();
$unverified_artifact_signatures    = array();
$partial_artifact_dirs            = array();
$startup_checks                   = array();
$startup_checks_available         = array();
$is_replayable_failure_manifest   = static function ( $manifest ): bool {
	if ( ! is_array( $manifest ) || ! isset( $manifest['signatures'], $manifest['payload_base64'], $manifest['context'], $manifest['failures'], $manifest['input_size'] ) ) {
		return false;
	}
	if ( ! is_array( $manifest['signatures'] ) || array() === $manifest['signatures'] || ! is_string( $manifest['payload_base64'] ) ) {
		return false;
	}
	if ( ! in_array( $manifest['context'], array( 'text', 'attribute', 'both' ), true ) ) {
		return false;
	}
	if ( isset( $manifest['mode'] ) && ! in_array( $manifest['mode'], Cli::valid_modes(), true ) ) {
		return false;
	}
	$payload = base64_decode( $manifest['payload_base64'], true );
	if ( ! is_string( $payload ) || '' === $payload || ! is_int( $manifest['input_size'] ) || strlen( $payload ) !== $manifest['input_size'] ) {
		return false;
	}
	if ( ! is_array( $manifest['failures'] ) || array() === $manifest['failures'] ) {
		return false;
	}
	$failure_signatures = array();
	foreach ( $manifest['failures'] as $failure ) {
		if ( ! is_array( $failure ) || ! isset( $failure['signature'] ) || ! is_string( $failure['signature'] ) ) {
			return false;
		}
		$failure_signatures[] = $failure['signature'];
	}
	$expected = array_values( array_unique( array_map( 'strval', $manifest['signatures'] ) ) );
	$actual   = array_values( array_unique( $failure_signatures ) );
	sort( $expected, SORT_STRING );
	sort( $actual, SORT_STRING );
	return $expected === $actual;
};
$startup_verifier_available = static function ( string $mode ) use ( &$startup_checks, &$startup_checks_available ): bool {
	if ( ! isset( $startup_checks_available[ $mode ] ) ) {
		Bootstrap::load_targets();
		$oracles                  = Oracles::build();
		$startup_checks_available[ $mode ] = ! Cli::mode_uses_oracle( $mode ) || $oracles->has_required();
		$startup_checks[ $mode ]           = $startup_checks_available[ $mode ] ? new Checks( $oracles ) : null;
	}

	return $startup_checks_available[ $mode ] && null !== $startup_checks[ $mode ];
};
$failure_manifest_reproduces = static function ( array $manifest ) use ( &$startup_checks, $startup_verifier_available ): ?bool {
	$mode = $manifest['mode'] ?? 'oracle';
	if ( ! $startup_verifier_available( $mode ) ) {
		return null;
	}

	$payload = base64_decode( $manifest['payload_base64'], true );
	if ( ! is_string( $payload ) ) {
		return null;
	}

	$actual = array_values(
		array_unique(
			array_map(
				static fn( array $failure ): string => $failure['signature'],
				'bytes' === $mode
					? $startup_checks[ $mode ]->run_without_oracle( $manifest['context'], $payload )
					: $startup_checks[ $mode ]->run( $manifest['context'], $payload )
			)
		)
	);
	$expected = array_values( array_unique( array_map( 'strval', $manifest['signatures'] ) ) );
	sort( $actual, SORT_STRING );
	sort( $expected, SORT_STRING );

	return $expected === $actual;
};
$startup_artifact_dirs = array();
$output_items = new \FilesystemIterator( $output_dir, \FilesystemIterator::SKIP_DOTS );
foreach ( $output_items as $output_item ) {
	if ( 0 !== strncmp( $output_item->getBasename(), 'failure-', 8 ) ) {
		continue;
	}

	if ( $output_item->isLink() ) {
		$partial_artifact_dirs[] = $output_item->getPathname();
		continue;
	}

	if ( $output_item->isDir() ) {
		$startup_artifact_dirs[] = $output_item->getPathname();
	}
}
sort( $startup_artifact_dirs, SORT_STRING );
foreach ( $startup_artifact_dirs as $artifact_dir ) {
	$failure_file = "{$artifact_dir}/failure.json";
	if ( ! is_file( $failure_file ) ) {
		$partial_artifact_dirs[] = $artifact_dir;
		continue;
	}

	$manifest = json_decode( (string) file_get_contents( $failure_file ), true );
	if ( $is_replayable_failure_manifest( $manifest ) ) {
		$reproduces = $failure_manifest_reproduces( $manifest );
		$signature_key = Cli::failure_signature_key( $manifest['signatures'], $manifest['mode'] ?? 'oracle' );
		if ( null === $reproduces ) {
			$startup_verification_unavailable = true;
			$unverified_artifact_signatures[ $signature_key ] = true;
		}
		if ( false !== $reproduces ) {
			$existing_artifacts_by_signature[ $signature_key ][] = $artifact_dir;
			continue;
		}
	}

	$partial_artifact_dirs[] = $artifact_dir;
}

if ( 'all' !== $options['artifact-retention'] ) {
	foreach ( $partial_artifact_dirs as $artifact_dir ) {
		if ( ! Cli::remove_tree( $artifact_dir, $output_dir ) ) {
			fwrite( STDERR, "Cannot prune partial failure artifact {$artifact_dir}\n" );
			exit( 2 );
		}
		++$startup_pruned_artifacts;
		++$startup_pruned_partial_artifacts;
	}
}

foreach ( $existing_artifacts_by_signature as $signature_key => $artifact_dirs ) {
	sort( $artifact_dirs, SORT_STRING );
	$keep = count( $artifact_dirs );
	if ( 'none' === $options['artifact-retention'] ) {
		$keep = 0;
	} elseif ( 'bounded' === $options['artifact-retention'] && isset( $unverified_artifact_signatures[ $signature_key ] ) ) {
		$keep = count( $artifact_dirs );
	} elseif ( 'bounded' === $options['artifact-retention'] ) {
		$keep = min( $keep, $options['max-artifacts-per-signature'] );
	}

	foreach ( $artifact_dirs as $index => $artifact_dir ) {
		if ( $index < $keep ) {
			$retained_artifacts_by_signature[ $signature_key ] = ( $retained_artifacts_by_signature[ $signature_key ] ?? 0 ) + 1;
			$artifact_key = realpath( $artifact_dir );
			$retained_artifact_dirs[ false === $artifact_key ? $artifact_dir : $artifact_key ] = $signature_key;
			continue;
		}

		if ( ! Cli::remove_tree( $artifact_dir, $output_dir ) ) {
			fwrite( STDERR, "Cannot prune existing failure artifact {$artifact_dir}\n" );
			exit( 2 );
		}
		++$startup_pruned_artifacts;
	}
}

$state = array(
	'started_at'         => gmdate( 'c' ),
	'seed_base'          => $seed_base,
	'options'            => $options,
	'git'                => Cli::git_metadata( $repo_root ),
	'cases'              => 0,
	'failures'           => 0,
	'bytes'              => 0,
	'by_strategy'        => array(),
	'by_context'         => array(),
	'failure_seeds'      => array(),
	'stalled_seeds'      => array(),
	'worker_errors'      => array(),
	'worker_stderr_truncated' => array(),
	'worker_stderr_startup_truncated' => $startup_truncated_stderr_logs,
	'harness_errors'     => 0,
	'oracle_events'      => array(),
	'batches'            => 0,
	'coverage'           => array(
		'edges'                    => 0,
		'payloads'                 => 0,
		'pruned_duplicate_payloads' => 0,
		'by_file'                  => array(),
		'edge_keys'                => array(),
		'corpus'                   => array(),
	),
	'artifact_retention' => array(
		'mode'                  => $options['artifact-retention'],
		'max_per_signature'     => $options['max-artifacts-per-signature'],
		'retained_by_signature' => $retained_artifacts_by_signature,
		'pruned'                => $startup_pruned_artifacts,
		'startup_pruned'        => $startup_pruned_artifacts,
		'startup_pruned_partial' => $startup_pruned_partial_artifacts,
		'startup_verification_unavailable' => $startup_verification_unavailable,
	),
	'stop_reason'        => null,
);

$next_seed = $seed_base;
$next_start_case = 0;
$lanes     = array();

$spawn_lane = static function ( int $lane_id ) use ( &$next_seed, &$next_start_case, &$stderr_bytes_by_lane, &$stderr_truncated_lanes, $seed_base, $options, $output_dir ): array {
	if ( Cli::mode_uses_start_case_windows( $options['mode'] ) ) {
		$seed       = $seed_base;
		$start_case = $next_start_case;
		$next_start_case += $options['cases-per-batch'];
	} else {
		$seed       = $next_seed++;
		$start_case = 0;
	}

	$command = array(
		PHP_BINARY,
		__DIR__ . '/worker.php',
		'--seed',
		(string) $seed,
		'--start-case',
		(string) $start_case,
		'--cases',
		(string) $options['cases-per-batch'],
		'--max-bytes',
		(string) $options['max-bytes'],
		'--mode',
		$options['mode'],
		'--output-dir',
		$output_dir,
		'--progress-every',
		'500',
	);

	$stderr_path = "{$output_dir}/lane-{$lane_id}-stderr.log";
	if ( Cli::is_linked_file( $stderr_path ) ) {
		fwrite( STDERR, "Lane stderr log is a linked file: {$stderr_path}\n" );
		exit( 2 );
	}

	$process = proc_open(
		$command,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	if ( ! is_resource( $process ) || ! isset( $pipes[1], $pipes[2] ) || ! is_resource( $pipes[1] ) || ! is_resource( $pipes[2] ) ) {
		fwrite( STDERR, "Cannot spawn worker lane {$lane_id}\n" );
		exit( 2 );
	}

	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	return array(
		'id'                => $lane_id,
		'seed'              => $seed,
		'start_case'        => $start_case,
		'process'           => $process,
		'stdout'            => $pipes[1],
		'stderr'            => $pipes[2],
		'stderr_path'       => $stderr_path,
		'stderr_bytes'      => $stderr_bytes_by_lane[ $lane_id ] ?? 0,
		'stderr_truncated'  => isset( $stderr_truncated_lanes[ $lane_id ] ),
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

$write_summary_record = static function ( array $record ) use ( &$state, &$stop_requested, &$summary_write_failed, $summary, $summary_path ): bool {
	if ( null === $summary ) {
		return true;
	}

	$summary_line = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $summary_line || ! Cli::write_stream( $summary, $summary_line . "\n" ) ) {
		if ( $summary_write_failed ) {
			return false;
		}
		$summary_write_failed   = true;
		++$state['harness_errors'];
		$state['stop_reason'] = 'harness-error';
		$stop_requested       = true;
		fwrite( STDERR, "Cannot write summary file {$summary_path}\n" );
		return false;
	}

	return true;
};

$summarize_record = static function ( array $record ) use ( $options, $write_summary_record ): bool {
	if ( 'none' === $options['summary-mode'] ) {
		return true;
	}
	if ( 'all' === $options['summary-mode'] ) {
		return $write_summary_record( $record );
	}

	$type = $record['type'] ?? '';
	if ( 'failure' === $type ) {
		if ( ! empty( $record['artifact_retained'] ) && empty( $record['artifact_reused'] ) ) {
			return $write_summary_record( $record );
		}
		return true;
	}
	if ( 'coverage' === $type ) {
		if ( ! empty( $record['coverage_retained'] ) ) {
			return $write_summary_record( $record );
		}
		return true;
	}
	if ( in_array( $type, array( 'fatal', 'oracle-event', 'invalid-worker-output', 'malformed-worker-record', 'unknown-worker-record' ), true ) ) {
		return $write_summary_record( $record );
	}

	return true;
};

$drain_lane_stderr = static function ( array &$lane ) use ( &$state, &$stop_requested, &$stderr_bytes_by_lane, &$stderr_truncated_lanes, $options ): void {
	$chunk = stream_get_contents( $lane['stderr'] );
	if ( false === $chunk || '' === $chunk ) {
		return;
	}

	$remaining = $options['max-stderr-bytes'] - $lane['stderr_bytes'];
	if ( $remaining <= 0 ) {
		$lane['stderr_truncated'] = true;
		$stderr_truncated_lanes[ $lane['id'] ] = true;
		return;
	}

	$write = substr( $chunk, 0, $remaining );
	if ( strlen( $chunk ) > strlen( $write ) ) {
		$lane['stderr_truncated'] = true;
		$stderr_truncated_lanes[ $lane['id'] ] = true;
	}
	if ( ! Cli::append_file( $lane['stderr_path'], $write ) ) {
		++$state['harness_errors'];
		$state['stop_reason'] = 'harness-error';
		$stop_requested       = true;
		fwrite( STDERR, "Cannot write lane stderr log {$lane['stderr_path']}\n" );
		return;
	}

	$lane['stderr_bytes'] += strlen( $write );
	$stderr_bytes_by_lane[ $lane['id'] ] = $lane['stderr_bytes'];
};

$apply_artifact_retention = static function ( array &$record ) use ( &$state, &$retained_artifact_dirs, $options ): ?string {
	$signature_key = Cli::failure_signature_key( $record['signatures'], $record['mode'] ?? 'oracle' );
	$record['signature_key'] = $signature_key;

	$artifact_dir = $record['artifact_dir'] ?? null;
	if ( ! is_string( $artifact_dir ) || '' === $artifact_dir || ! is_dir( $artifact_dir ) ) {
		$record['artifact_retained'] = false;
		$record['artifact_pruned']   = false;
		return null;
	}

	$artifact_key = realpath( $artifact_dir );
	$artifact_key = false === $artifact_key ? $artifact_dir : $artifact_key;
	if ( isset( $retained_artifact_dirs[ $artifact_key ] ) ) {
		if ( $signature_key === $retained_artifact_dirs[ $artifact_key ] ) {
			$record['artifact_retained'] = true;
			$record['artifact_pruned']   = false;
			$record['artifact_reused']   = true;
			return null;
		}

		$previous_signature_key = $retained_artifact_dirs[ $artifact_key ];
		$state['artifact_retention']['retained_by_signature'][ $previous_signature_key ] =
			max( 0, ( $state['artifact_retention']['retained_by_signature'][ $previous_signature_key ] ?? 1 ) - 1 );
		if ( 0 === $state['artifact_retention']['retained_by_signature'][ $previous_signature_key ] ) {
			unset( $state['artifact_retention']['retained_by_signature'][ $previous_signature_key ] );
		}
		unset( $retained_artifact_dirs[ $artifact_key ] );
		$record['artifact_replaced_signature_key'] = $previous_signature_key;
	}

	$retain = 'all' === $options['artifact-retention'];
	if ( 'bounded' === $options['artifact-retention'] ) {
		$retained = $state['artifact_retention']['retained_by_signature'][ $signature_key ] ?? 0;
		$retain   = $retained < $options['max-artifacts-per-signature'];
	}

	if ( $retain ) {
		$record['artifact_retained'] = true;
		$record['artifact_pruned']   = false;
		$state['artifact_retention']['retained_by_signature'][ $signature_key ] =
			( $state['artifact_retention']['retained_by_signature'][ $signature_key ] ?? 0 ) + 1;
		$retained_artifact_dirs[ $artifact_key ] = $signature_key;
		return null;
	}

	$record['artifact_dir']      = null;
	$record['artifact_retained'] = false;
	$record['artifact_pruned']   = true;
	return $artifact_dir;
};

$handle_line = static function ( string $line, int $lane_id ) use ( &$state, &$stop_requested, $summarize_record, $apply_artifact_retention, $output_dir ): ?string {
	$record = json_decode( $line, true );
	if ( ! is_array( $record ) ) {
		++$state['harness_errors'];
		$state['stop_reason'] = 'harness-error';
		$stop_requested       = true;
		fwrite( STDERR, "invalid worker output on lane {$lane_id}\n" );
		$summarize_record(
			array(
				'type'       => 'invalid-worker-output',
				'lane'       => $lane_id,
				'raw_base64' => base64_encode( $line ),
			)
		);
		return 'invalid';
	}

	$record['lane'] = $lane_id;

	switch ( $record['type'] ?? '' ) {
		case 'failure':
			if ( ! isset( $record['seed'], $record['case'], $record['context'], $record['signatures'] ) || ! is_array( $record['signatures'] ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed failure record on lane {$lane_id}\n" );
				$record['type'] = 'malformed-worker-record';
				$summarize_record( $record );
				return 'invalid';
			}
			$record['mode'] = $record['mode'] ?? 'oracle';
			if ( ! in_array( $record['mode'], Cli::valid_modes(), true ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed failure mode on lane {$lane_id}\n" );
				$record['type'] = 'malformed-worker-record';
				$summarize_record( $record );
				return 'invalid';
			}
			$prune_artifact_dir = $apply_artifact_retention( $record );
			++$state['failures'];
			fwrite( STDERR, "FAILURE lane {$lane_id} seed {$record['seed']} case {$record['case']}: " . implode( ', ', $record['signatures'] ) . "\n" );
			$summary_written = $summarize_record( $record );
			if ( null !== $prune_artifact_dir ) {
				if ( $summary_written && Cli::remove_tree( $prune_artifact_dir, $output_dir ) ) {
					++$state['artifact_retention']['pruned'];
				} else {
					if ( $summary_written ) {
						++$state['harness_errors'];
						$state['stop_reason'] = 'harness-error';
						$stop_requested       = true;
						fwrite( STDERR, "Cannot prune failure artifact {$prune_artifact_dir}\n" );
					}
					$record['artifact_dir']      = $prune_artifact_dir;
					$record['artifact_retained'] = true;
					$record['artifact_pruned']   = false;
					$state['artifact_retention']['retained_by_signature'][ $record['signature_key'] ] =
						( $state['artifact_retention']['retained_by_signature'][ $record['signature_key'] ] ?? 0 ) + 1;
				}
			}
			if ( ! empty( $record['artifact_retained'] ) && empty( $record['artifact_reused'] ) ) {
				$state['failure_seeds'][] = array(
					'seed'              => $record['seed'],
					'case'              => $record['case'],
					'mode'              => $record['mode'],
					'context'           => $record['context'],
					'signatures'        => $record['signatures'],
					'signature_key'     => $record['signature_key'],
					'artifact'          => $record['artifact_dir'] ?? null,
					'artifact_retained' => $record['artifact_retained'],
					'artifact_pruned'   => $record['artifact_pruned'],
				);
			}
			return 'failure';

		case 'coverage':
			if ( ! isset( $record['seed'], $record['case'], $record['context'], $record['strategy'], $record['new_edges'] ) || ! is_array( $record['new_edges'] ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed coverage record on lane {$lane_id}\n" );
				$record['type'] = 'malformed-worker-record';
				$summarize_record( $record );
				return 'invalid';
			}
			$record['mode'] = $record['mode'] ?? 'coverage';
			if ( 'coverage' !== $record['mode'] ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed coverage mode on lane {$lane_id}\n" );
				$record['type'] = 'malformed-worker-record';
				$summarize_record( $record );
				return 'invalid';
			}

			$global_new_edges = array();
			foreach ( $record['new_edges'] as $edge ) {
				if ( ! is_array( $edge ) || ! isset( $edge['key'], $edge['file'], $edge['line'] ) || ! is_string( $edge['key'] ) || ! is_string( $edge['file'] ) || ! is_int( $edge['line'] ) ) {
					++$state['harness_errors'];
					$state['stop_reason'] = 'harness-error';
					$stop_requested       = true;
					fwrite( STDERR, "malformed coverage edge on lane {$lane_id}\n" );
					$record['type'] = 'malformed-worker-record';
					$summarize_record( $record );
					return 'invalid';
				}
				if ( isset( $state['coverage']['edge_keys'][ $edge['key'] ] ) ) {
					continue;
				}

				$state['coverage']['edge_keys'][ $edge['key'] ] = true;
				$state['coverage']['by_file'][ $edge['file'] ] = ( $state['coverage']['by_file'][ $edge['file'] ] ?? 0 ) + 1;
				$global_new_edges[] = $edge;
			}

			$artifact_dir = $record['artifact_dir'] ?? null;
			if ( array() === $global_new_edges ) {
				$record['new_edges']          = array();
				$record['new_edge_count']     = 0;
				$record['coverage_retained']  = false;
				$record['coverage_duplicate'] = true;
				$record['coverage_pruned']    = false;
				if ( is_string( $artifact_dir ) && '' !== $artifact_dir && is_dir( $artifact_dir ) ) {
					if ( Cli::remove_tree( $artifact_dir, $output_dir ) ) {
						$record['artifact_dir']      = null;
						$record['artifact_pruned']   = true;
						$record['coverage_pruned']   = true;
						++$state['coverage']['pruned_duplicate_payloads'];
					} else {
						++$state['harness_errors'];
						$state['stop_reason'] = 'harness-error';
						$stop_requested       = true;
						fwrite( STDERR, "Cannot prune duplicate coverage artifact {$artifact_dir}\n" );
					}
				}
				$summarize_record( $record );
				return 'coverage';
			}

			$record['new_edges']      = $global_new_edges;
			$record['new_edge_count'] = count( $global_new_edges );
			$state['coverage']['edges'] += count( $global_new_edges );
			$record['coverage_duplicate'] = false;
			$record['coverage_pruned']    = false;
			$record['coverage_retained']  = is_string( $artifact_dir ) && '' !== $artifact_dir && is_dir( $artifact_dir ) && ! is_link( $artifact_dir );
			if ( $record['coverage_retained'] ) {
				$payload = isset( $record['payload_base64'] ) && is_string( $record['payload_base64'] )
					? base64_decode( $record['payload_base64'], true )
					: null;
				++$state['coverage']['payloads'];
				$state['coverage']['corpus'][] = array(
					'seed'       => $record['seed'],
					'case'       => $record['case'],
					'context'    => $record['context'],
					'strategy'   => $record['strategy'],
					'edges'      => count( $global_new_edges ),
					'artifact'   => $artifact_dir,
					'sha256'     => is_string( $payload ) ? hash( 'sha256', $payload ) : null,
				);
			}
			$summarize_record( $record );
			return 'coverage';

		case 'oracle-event':
			$state['oracle_events'][] = $record;
			$oracle = $record['oracle'] ?? 'unknown';
			$detail = $record['detail'] ?? 'no detail';
			fwrite( STDERR, "oracle event: {$oracle}: {$detail}\n" );
			$summarize_record( $record );
			return 'oracle-event';

		case 'fatal':
			++$state['harness_errors'];
			$state['oracle_events'][] = $record;
			$state['stop_reason']     = 'harness-error';
			$stop_requested           = true;
			$reason = $record['reason'] ?? 'unknown';
			fwrite( STDERR, "worker fatal: {$reason}\n" );
			$summarize_record( $record );
			return 'fatal';

		case 'done':
			if ( ! isset( $record['stats'] ) || ! is_array( $record['stats'] ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed done record on lane {$lane_id}\n" );
				$record['type'] = 'malformed-worker-record';
				$summarize_record( $record );
				return 'invalid';
			}
			$stats            = $record['stats'];
			if ( ! isset( $stats['cases'], $stats['bytes'], $stats['by_strategy'], $stats['by_context'] ) || ! is_array( $stats['by_strategy'] ) || ! is_array( $stats['by_context'] ) ) {
				++$state['harness_errors'];
				$state['stop_reason'] = 'harness-error';
				$stop_requested       = true;
				fwrite( STDERR, "malformed done stats on lane {$lane_id}\n" );
				$record['type'] = 'malformed-worker-record';
				$summarize_record( $record );
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
			$summarize_record( $record );
			return 'done';

		case 'progress':
		case 'start':
			$summarize_record( $record );
			return $record['type'];
	}

	++$state['harness_errors'];
	$state['stop_reason'] = 'harness-error';
	$stop_requested       = true;
	fwrite( STDERR, "unknown worker record type on lane {$lane_id}\n" );
	$record['type'] = 'unknown-worker-record';
	$summarize_record( $record );
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
		$streams[ "{$lane_id}:stdout" ] = $lane['stdout'];
		$streams[ "{$lane_id}:stderr" ] = $lane['stderr'];
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

	foreach ( $lanes as &$lane ) {
		$drain_lane_stderr( $lane );
	}
	unset( $lane );

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
		$drain_lane_stderr( $lane );
		fclose( $lane['stdout'] );
		fclose( $lane['stderr'] );
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
		if ( $lane['stderr_truncated'] ) {
			$state['worker_stderr_truncated'][ $lane_id ] = array(
				'lane' => $lane_id,
				'seed' => $lane['seed'],
				'bytes' => $lane['stderr_bytes'],
			);
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
	if ( is_resource( $summary ) ) {
		fclose( $summary );
	}
	exit( 2 );
}
if ( is_resource( $summary ) ) {
	fclose( $summary );
}

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
