<?php
/**
 * Runs fuzz cases in-process for one seed and reports ndjson to stdout.
 *
 *     php tools/encoding-fuzz/worker.php --seed 1 --cases 1000
 *
 * Every case is fully determined by `(seed, case index)`: the case PRNG
 * is keyed on both, so any single case can be re-derived without
 * replaying the ones before it.
 *
 * Exit codes: 0 all cases passed, 1 failures found, 2 harness error.
 */

namespace EncodingFuzz;

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
		'max-bytes'      => 65536,
		'external'       => 'auto',
		'output-dir'     => '',
		'progress-every' => 500,
	)
);

Bootstrap::load_targets();

$oracles = Oracles::build( Cli::resolve_externals( $options['external'] ) );
foreach ( $oracles->drain_events() as $event ) {
	Cli::emit( array( 'type' => 'oracle-event' ) + $event );
}

if ( ! $oracles->has_required() ) {
	Cli::emit(
		array(
			'type'   => 'fatal',
			'reason' => 'mbstring oracle unavailable or failed the battery; cannot fuzz without a primary oracle',
		)
	);
	exit( 2 );
}

$checks     = new Checks( $oracles );
$mb_valid   = $oracles->validity_oracles()['mb'];
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

$seed       = (string) $options['seed'];
$start      = $options['start-case'];
$end        = $start + $options['cases'];
$stats      = array(
	'cases'        => 0,
	'failures'     => 0,
	'valid_inputs' => 0,
	'bytes'        => 0,
	'by_strategy'  => array(),
);
$started_at = microtime( true );

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
	$generator = new Generator( $prng, $options['max-bytes'] );
	$generated = $generator->generate();
	$input     = $generated['bytes'];
	$strategy  = $generated['strategy'];

	$failures = $checks->run( $input );

	++$stats['cases'];
	$stats['bytes']                   += strlen( $input );
	$stats['by_strategy'][ $strategy ] = ( $stats['by_strategy'][ $strategy ] ?? 0 ) + 1;
	if ( $mb_valid( $input ) ) {
		++$stats['valid_inputs'];
	}

	foreach ( $oracles->drain_events() as $event ) {
		Cli::emit( array( 'type' => 'oracle-event', 'case' => $case ) + $event );
	}

	if ( array() !== $failures ) {
		$stats['failures'] += count( $failures );

		$record = array(
			'type'       => 'failure',
			'seed'       => $seed,
			'case'       => $case,
			'strategy'   => $strategy,
			'input_size' => strlen( $input ),
			'signatures' => array_values( array_unique( array_column( $failures, 'signature' ) ) ),
			'failures'   => $failures,
		);

		if ( strlen( $input ) <= 4096 ) {
			$record['input_base64'] = base64_encode( $input );
		}

		if ( '' !== $output_dir ) {
			$case_dir = "{$output_dir}/failure-seed{$seed}-case{$case}";
			if ( ! is_dir( $case_dir ) ) {
				mkdir( $case_dir, 0777, true );
			}
			file_put_contents( "{$case_dir}/input.bin", $input );

			$artifact                 = $record;
			$artifact['input_base64'] = base64_encode( $input );
			$artifact['environment']  = Cli::environment_metadata( $oracles );
			$artifact['git']          = Cli::git_metadata( Bootstrap::repo_root() );
			file_put_contents(
				"{$case_dir}/failure.json",
				json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			);
			$record['artifact_dir'] = $case_dir;
		}

		Cli::emit( $record );
	}

	if ( 0 === ( $stats['cases'] % max( 1, $options['progress-every'] ) ) ) {
		$elapsed = microtime( true ) - $started_at;
		Cli::emit(
			array(
				'type'           => 'progress',
				'seed'           => $seed,
				'case'           => $case,
				'cases_done'     => $stats['cases'],
				'failures'       => $stats['failures'],
				'cases_per_sec'  => $elapsed > 0 ? round( $stats['cases'] / $elapsed, 1 ) : null,
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

$oracles->shutdown();
exit( $stats['failures'] > 0 ? 1 : 0 );
