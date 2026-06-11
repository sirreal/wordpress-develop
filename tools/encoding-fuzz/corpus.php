<?php
/**
 * Runs deterministic fixed corpora that complement the random generator.
 *
 *     php tools/encoding-fuzz/corpus.php --external none
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
		'external'       => 'auto',
		'output-dir'     => '',
		'progress-every' => 0,
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
			'reason' => 'mbstring oracle unavailable or failed the battery; cannot run corpus without a primary oracle',
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

$checks     = new Checks( $oracles );
$mb_valid   = $oracles->validity_oracles()['mb'];
$cases      = Corpus::short_boundary_cases();
$stats      = array(
	'cases'        => 0,
	'failures'     => 0,
	'valid_inputs' => 0,
	'bytes'        => 0,
	'by_strategy'  => array(
		'short-boundary-corpus' => 0,
	),
);
$started_at = microtime( true );

Cli::emit(
	array(
		'type'        => 'start',
		'corpus'      => 'short-boundary',
		'cases'       => count( $cases ),
		'environment' => Cli::environment_metadata( $oracles ),
	)
);

foreach ( $cases as $case => $entry ) {
	$input    = $entry['bytes'];
	$label    = $entry['label'];
	$failures = $checks->run( $input );

	++$stats['cases'];
	++$stats['by_strategy']['short-boundary-corpus'];
	$stats['bytes'] += strlen( $input );
	if ( $mb_valid( $input ) ) {
		++$stats['valid_inputs'];
	}

	foreach ( $oracles->drain_events() as $event ) {
		Cli::emit( array( 'type' => 'oracle-event', 'case' => $case, 'corpus_label' => $label ) + $event );
	}

	if ( array() !== $failures ) {
		$stats['failures'] += count( $failures );

		$record = array(
			'type'         => 'failure',
			'corpus'       => 'short-boundary',
			'case'         => $case,
			'corpus_label' => $label,
			'strategy'     => 'short-boundary-corpus',
			'input_size'   => strlen( $input ),
			'signatures'   => array_values( array_unique( array_column( $failures, 'signature' ) ) ),
			'failures'     => $failures,
			'input_base64' => base64_encode( $input ),
		);

		if ( '' !== $output_dir ) {
			$case_dir = "{$output_dir}/failure-corpus-short-boundary-case{$case}";
			if ( ! is_dir( $case_dir ) && ! mkdir( $case_dir, 0777, true ) ) {
				Cli::emit(
					array(
						'type'   => 'fatal',
						'reason' => "cannot create artifact dir {$case_dir}",
					)
				);
				$oracles->shutdown();
				exit( 2 );
			}
			if ( false === file_put_contents( "{$case_dir}/input.bin", $input ) ) {
				Cli::emit(
					array(
						'type'   => 'fatal',
						'reason' => "cannot write {$case_dir}/input.bin",
					)
				);
				$oracles->shutdown();
				exit( 2 );
			}

			$artifact                = $record;
			$artifact['environment'] = Cli::environment_metadata( $oracles );
			$artifact['git']         = Cli::git_metadata( Bootstrap::repo_root() );
			if ( false === file_put_contents(
				"{$case_dir}/failure.json",
				json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			) ) {
				Cli::emit(
					array(
						'type'   => 'fatal',
						'reason' => "cannot write {$case_dir}/failure.json",
					)
				);
				$oracles->shutdown();
				exit( 2 );
			}
			$record['artifact_dir'] = $case_dir;
		}

		Cli::emit( $record );
	}

	if (
		$options['progress-every'] > 0 &&
		0 === ( $stats['cases'] % max( 1, $options['progress-every'] ) )
	) {
		$elapsed = microtime( true ) - $started_at;
		Cli::emit(
			array(
				'type'          => 'progress',
				'corpus'        => 'short-boundary',
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
		'corpus'        => 'short-boundary',
		'stats'         => $stats,
		'elapsed_sec'   => round( $elapsed, 2 ),
		'cases_per_sec' => $elapsed > 0 ? round( $stats['cases'] / $elapsed, 1 ) : null,
	)
);

$oracles->shutdown();
exit( $stats['failures'] > 0 ? 1 : 0 );
