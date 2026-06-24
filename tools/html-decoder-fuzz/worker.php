<?php
/**
 * Runs decoder fuzz cases in-process for one seed and reports ndjson.
 *
 *     php tools/html-decoder-fuzz/worker.php --seed 1 --cases 1000
 *
 * Exit codes: 0 clean, 1 findings, 2 harness error.
 */

namespace HtmlDecoderFuzz;

require __DIR__ . '/lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
ini_set( 'memory_limit', '512M' );

$options = Cli::parse_args(
	$argv,
	array(
		'seed'           => 1,
		'cases'          => 1000,
		'start-case'     => 0,
		'max-bytes'      => 4096,
		'mode'           => 'oracle',
		'output-dir'     => '',
		'progress-every' => 500,
	)
);

Cli::require_int_at_least( $options, 'cases', 1 );
Cli::require_int_at_least( $options, 'start-case', 0 );
Cli::require_int_at_least( $options, 'max-bytes', 1 );
Cli::require_int_at_least( $options, 'progress-every', 1 );
Cli::require_one_of( $options, 'mode', Cli::valid_modes() );

Bootstrap::load_targets();

$oracles = Oracles::build();
foreach ( $oracles->drain_events() as $event ) {
	Cli::emit( array( 'type' => 'oracle-event' ) + $event );
}

if ( Cli::mode_uses_oracle( $options['mode'] ) && ! $oracles->has_required() ) {
	Cli::emit(
		array(
			'type'   => 'fatal',
			'reason' => 'required oracle unavailable or failed the battery',
		)
	);
	exit( 2 );
}

$coverage = null;
if ( 'coverage' === $options['mode'] ) {
	if ( ! CoverageGuidance::available() ) {
		Cli::emit(
			array(
				'type'   => 'fatal',
				'reason' => CoverageGuidance::unavailable_reason(),
			)
		);
		exit( 2 );
	}
	$coverage = new CoverageGuidance();
}

$output_dir = $options['output-dir'];
if ( '' !== $output_dir && ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) ) {
	Cli::emit(
		array(
			'type'   => 'fatal',
			'reason' => "cannot create output dir {$output_dir}",
		)
	);
	exit( 2 );
}

$checks          = new Checks( $oracles );
$reference_names = Bootstrap::named_reference_names();
$seed            = (string) $options['seed'];
$start           = $options['start-case'];
$end             = $start + $options['cases'];
$stderr_bytes_per_case = max( 0, (int) getenv( 'HTML_DECODER_FUZZ_STDERR_BYTES_PER_CASE' ) );
$stats           = array(
	'cases'       => 0,
	'failures'    => 0,
	'bytes'       => 0,
	'by_strategy' => array(),
	'by_context'  => array(),
);
if ( null !== $coverage ) {
	$stats['coverage_new_edges'] = 0;
	$stats['coverage_payloads']  = 0;
}
$started_at      = microtime( true );

Cli::emit(
	array(
		'type'        => 'start',
		'seed'        => $seed,
		'start_case'  => $start,
		'cases'       => $options['cases'],
		'max_bytes'   => $options['max-bytes'],
		'mode'        => $options['mode'],
		'environment' => Cli::environment_metadata( $oracles ),
	)
);

for ( $case = $start; $case < $end; $case++ ) {
	if ( $stderr_bytes_per_case > 0 ) {
		fwrite( STDERR, str_repeat( 'E', $stderr_bytes_per_case ) . "\n" );
	}

	$prng      = new Prng( "{$seed}:{$case}" );
	$generator = new Generator( $prng, $options['max-bytes'], $reference_names );
	if ( 'bytes' === $options['mode'] ) {
		$generated = $generator->generate_bytes();
	} elseif ( 'names' === $options['mode'] ) {
		$generated = $generator->generate_name_sweep( $case );
	} elseif ( 'legacy-followers' === $options['mode'] ) {
		$generated = $generator->generate_legacy_follower_sweep( $case );
	} elseif ( 'prefix-families' === $options['mode'] ) {
		$generated = $generator->generate_prefix_family_sweep( $case );
	} elseif ( 'numeric-boundaries' === $options['mode'] ) {
		$generated = $generator->generate_numeric_boundary_sweep( $case );
	} elseif ( 'corpus' === $options['mode'] ) {
		$generated = $generator->generate_corpus_mutation( $case );
	} elseif ( 'token-map' === $options['mode'] ) {
		$generated = $generator->generate_token_map_sweep( $case );
	} elseif ( 'coverage' === $options['mode'] ) {
		$generated = $generator->generate();
	} else {
		$generated = $generator->generate();
	}
	$payload   = $generated['payload'];
	$context   = $generated['context'];
	$strategy  = $generated['strategy'];

	if ( null !== $coverage ) {
		$coverage->begin_case();
	}
	$failures = 'bytes' === $options['mode']
		? $checks->run_without_oracle( $context, $payload )
		: $checks->run( $context, $payload );
	$coverage_edges = null === $coverage ? array() : $coverage->finish_case( $payload, $context, $strategy );

	++$stats['cases'];
	$stats['bytes']                     += strlen( $payload );
	$stats['by_strategy'][ $strategy ]   = ( $stats['by_strategy'][ $strategy ] ?? 0 ) + 1;
	$stats['by_context'][ $context ]     = ( $stats['by_context'][ $context ] ?? 0 ) + 1;

	if ( null !== $coverage ) {
		$new_edges = $coverage->new_edges( $coverage_edges );
		if ( array() !== $new_edges ) {
			$stats['coverage_new_edges'] += count( $new_edges );
			++$stats['coverage_payloads'];

			try {
				$coverage_artifact = $coverage->retain_payload( $output_dir, $seed, $case, $generated, $payload, $new_edges );
			} catch ( \RuntimeException $exception ) {
				Cli::emit(
					array(
						'type'   => 'fatal',
						'reason' => $exception->getMessage(),
					)
				);
				exit( 2 );
			}

			$coverage_record = array(
				'type'              => 'coverage',
				'seed'              => $seed,
				'case'              => $case,
				'mode'              => $options['mode'],
				'context'           => $context,
				'strategy'          => $strategy,
				'input_size'        => strlen( $payload ),
				'coverage_provider' => $coverage->provider(),
				'edge_count'        => count( $coverage_edges ),
				'seen_edge_count'   => $coverage->seen_edge_count(),
				'new_edge_count'    => count( $new_edges ),
				'new_edges'         => $new_edges,
			) + $coverage_artifact;
			if ( strlen( $payload ) <= 4096 ) {
				$coverage_record['payload_base64'] = base64_encode( $payload );
			}
			Cli::emit( $coverage_record );
		}
	}

	if ( array() !== $failures ) {
		$stats['failures'] += count( $failures );

		$record = array(
			'type'       => 'failure',
			'seed'       => $seed,
			'case'       => $case,
			'mode'       => $options['mode'],
			'context'    => $context,
			'strategy'   => $strategy,
			'input_size' => strlen( $payload ),
			'signatures' => array_values( array_unique( array_column( $failures, 'signature' ) ) ),
			'failures'   => $failures,
		);

		if ( strlen( $payload ) <= 4096 ) {
			$record['payload_base64'] = base64_encode( $payload );
		}

		if ( '' !== $output_dir ) {
			$signature_key = Cli::failure_signature_key( $record['signatures'], $record['mode'] );
			$base_case_dir = "{$output_dir}/failure-seed{$seed}-case{$case}";
			$case_dir      = $base_case_dir;
			$dir_matches_signature = static function ( string $dir ) use ( $signature_key ): bool {
				if ( is_link( $dir ) ) {
					return false;
				}

				$manifest = json_decode( (string) @file_get_contents( "{$dir}/failure.json" ), true );
				$manifest_mode = $manifest['mode'] ?? 'oracle';
				return is_array( $manifest ) &&
					isset( $manifest['signatures'] ) &&
					is_array( $manifest['signatures'] ) &&
					is_string( $manifest_mode ) &&
					in_array( $manifest_mode, Cli::valid_modes(), true ) &&
					$signature_key === Cli::failure_signature_key( $manifest['signatures'], $manifest_mode );
			};

			if ( is_link( $case_dir ) || ( is_dir( $case_dir ) && ! $dir_matches_signature( $case_dir ) ) ) {
				$suffix   = substr( $signature_key, 0, 12 );
				$case_dir = "{$base_case_dir}-sig{$suffix}";
				$attempt  = 2;
				while ( is_link( $case_dir ) || ( is_dir( $case_dir ) && ! $dir_matches_signature( $case_dir ) ) ) {
					$case_dir = "{$base_case_dir}-sig{$suffix}-{$attempt}";
					++$attempt;
				}
			}

			if ( ! is_dir( $case_dir ) && ! mkdir( $case_dir, 0777, true ) ) {
				Cli::emit(
					array(
						'type'   => 'fatal',
						'reason' => "cannot create failure artifact dir {$case_dir}",
					)
				);
				exit( 2 );
			}
			if ( ! Cli::write_file( "{$case_dir}/payload.txt", $payload ) ) {
				Cli::emit(
					array(
						'type'   => 'fatal',
						'reason' => "cannot write failure payload under {$case_dir}",
					)
				);
				exit( 2 );
			}

			$artifact                   = $record;
			$artifact['payload_base64'] = base64_encode( $payload );
			$artifact['environment']    = Cli::environment_metadata( $oracles );
			$artifact['git']            = Cli::git_metadata( Bootstrap::repo_root() );
			$artifact_json = json_encode(
				$artifact,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
			if ( false === $artifact_json || ! Cli::write_file( "{$case_dir}/failure.json", $artifact_json ) ) {
				Cli::emit(
					array(
						'type'   => 'fatal',
						'reason' => "cannot write failure manifest under {$case_dir}",
					)
				);
				exit( 2 );
			}
			$record['artifact_dir'] = $case_dir;
		}

		if ( getenv( 'HTML_DECODER_FUZZ_CORRUPT_FAILURE_EVENT' ) ) {
			if ( ! Cli::write_stream( STDOUT, "{\"type\":\"failure\"\n" ) ) {
				fwrite( STDERR, "Cannot write corrupted failure event\n" );
				exit( 2 );
			}
		} else {
			if ( getenv( 'HTML_DECODER_FUZZ_BOGUS_FAILURE_MODE' ) ) {
				$record['mode'] = 'bogus';
			}
			Cli::emit( $record );
		}
	}

	if ( 0 === ( $stats['cases'] % max( 1, $options['progress-every'] ) ) ) {
		$elapsed = microtime( true ) - $started_at;
		Cli::emit(
			array(
				'type'          => 'progress',
				'seed'          => $seed,
				'case'          => $case,
				'cases_done'    => $stats['cases'],
				'failures'      => $stats['failures'],
				'cases_per_sec' => $elapsed > 0 ? round( $stats['cases'] / $elapsed, 1 ) : null,
			)
		);
	}
}

$elapsed = microtime( true ) - $started_at;
Cli::emit(
	array(
		'type'          => 'done',
		'seed'          => $seed,
		'stats'         => $stats,
		'elapsed_sec'   => round( $elapsed, 2 ),
		'cases_per_sec' => $elapsed > 0 ? round( $stats['cases'] / $elapsed, 1 ) : null,
	)
);

exit( $stats['failures'] > 0 ? 1 : 0 );
