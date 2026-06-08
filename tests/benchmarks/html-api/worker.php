<?php
/**
 * Isolated worker for one HTML API benchmark sample.
 *
 * @package WordPress
 * @subpackage Benchmark
 */

require __DIR__ . '/includes.php';

$defaults = array(
	'revs'        => 1,
	'warmup-runs' => 0,
	'target'      => getcwd() . '/src',
);

$options = wp_html_api_benchmark_parse_options( $argv, $defaults );

try {
	if ( empty( $options['case'] ) || ! is_array( $options['case'] ) ) {
		throw new RuntimeException( 'Missing --case option.' );
	}

	$case_id = $options['case'][0];
	$cases   = wp_html_api_benchmark_cases();

	if ( ! isset( $cases[ $case_id ] ) ) {
		throw new RuntimeException( "Unknown benchmark case: {$case_id}" );
	}

	wp_html_api_benchmark_assert_positive_int( $options['revs'], 'revs' );
	wp_html_api_benchmark_assert_non_negative_int( $options['warmup-runs'], 'warmup-runs' );

	$case        = $cases[ $case_id ];
	$revs        = (int) $options['revs'];
	$warmup_runs = (int) $options['warmup-runs'];
	$html        = wp_html_api_benchmark_document( $case['document'] );

	wp_html_api_benchmark_load_html_api( $options['target'] );

	for ( $i = 0; $i < $warmup_runs; $i++ ) {
		for ( $j = 0; $j < $revs; $j++ ) {
			wp_html_api_benchmark_run_revolution( $case, $html );
		}
	}

	$tokens   = 0;
	$work     = 0;
	$checksum = 0;
	$start    = hrtime( true );

	for ( $i = 0; $i < $revs; $i++ ) {
		$result    = wp_html_api_benchmark_run_revolution( $case, $html );
		$tokens   += $result['tokens'];
		$work     += $result['work'];
		$checksum += $result['checksum'];
	}

	$elapsed_ns = hrtime( true ) - $start;

	echo json_encode(
		array(
			'ok'          => true,
			'elapsed_ns'  => $elapsed_ns,
			'revolutions' => $revs,
			'tokens'      => $tokens,
			'work'        => $work,
			'checksum'    => $checksum,
			'environment' => wp_html_api_benchmark_environment(),
		),
		JSON_UNESCAPED_SLASHES
	);
} catch ( Throwable $error ) {
	echo json_encode(
		array(
			'ok'    => false,
			'error' => $error->getMessage(),
		),
		JSON_UNESCAPED_SLASHES
	);
	exit( 1 );
}
