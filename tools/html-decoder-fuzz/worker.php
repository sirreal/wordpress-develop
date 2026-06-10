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
		'output-dir'     => '',
		'progress-every' => 500,
	)
);

Cli::require_int_at_least( $options, 'cases', 1 );
Cli::require_int_at_least( $options, 'start-case', 0 );
Cli::require_int_at_least( $options, 'max-bytes', 1 );
Cli::require_int_at_least( $options, 'progress-every', 1 );

Bootstrap::load_targets();

$oracles = Oracles::build();
foreach ( $oracles->drain_events() as $event ) {
	Cli::emit( array( 'type' => 'oracle-event' ) + $event );
}

if ( ! $oracles->has_required() ) {
	Cli::emit(
		array(
			'type'   => 'fatal',
			'reason' => 'required DOM or mbstring oracle unavailable or failed the battery',
		)
	);
	exit( 2 );
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
$stats           = array(
	'cases'       => 0,
	'failures'    => 0,
	'bytes'       => 0,
	'by_strategy' => array(),
	'by_context'  => array(),
);
$started_at      = microtime( true );

Cli::emit(
	array(
		'type'        => 'start',
		'seed'        => $seed,
		'start_case'  => $start,
		'cases'       => $options['cases'],
		'max_bytes'   => $options['max-bytes'],
		'environment' => Cli::environment_metadata( $oracles ),
	)
);

for ( $case = $start; $case < $end; $case++ ) {
	$prng      = new Prng( "{$seed}:{$case}" );
	$generator = new Generator( $prng, $options['max-bytes'], $reference_names );
	$generated = $generator->generate();
	$payload   = $generated['payload'];
	$context   = $generated['context'];
	$strategy  = $generated['strategy'];

	$failures = $checks->run( $context, $payload );

	++$stats['cases'];
	$stats['bytes']                     += strlen( $payload );
	$stats['by_strategy'][ $strategy ]   = ( $stats['by_strategy'][ $strategy ] ?? 0 ) + 1;
	$stats['by_context'][ $context ]     = ( $stats['by_context'][ $context ] ?? 0 ) + 1;

	if ( array() !== $failures ) {
		$stats['failures'] += count( $failures );

		$record = array(
			'type'       => 'failure',
			'seed'       => $seed,
			'case'       => $case,
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
			$case_dir = "{$output_dir}/failure-seed{$seed}-case{$case}";
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
