<?php
/**
 * Runs HTML API benchmarks against current and baseline source trees.
 *
 * @package WordPress
 * @subpackage Benchmark
 */

require __DIR__ . '/includes.php';

/**
 * Prints benchmark comparison usage details.
 */
function wp_html_api_benchmark_compare_print_usage() {
	$script    = 'php tests/benchmarks/html-api/compare.php';
	$documents = implode( '|', wp_html_api_benchmark_document_ids() );

	echo "Usage: {$script} --baseline-target=<path> [options]\n\n";
	echo "Runs the benchmark harness from this checkout against a baseline WordPress\n";
	echo "source tree and the current source tree on the same host.\n\n";
	echo "Options:\n";
	echo "  --baseline-target=<path>                         Baseline WordPress source root.\n";
	echo "  --target=<path>                                  Current WordPress source root. Default: src.\n";
	echo "  --output=<path>                                  Artifact directory. Default: artifacts.\n";
	echo "  --summary=<path>                                 Optional markdown summary path.\n";
	echo "  --skip-report                                    Do not run tests/performance/compare-results.js.\n";
	echo "  --processor=<all|tag-processor|html-processor>  Processor to benchmark. Default: all.\n";
	echo "  --operation=<all|parse|attribute-names|attribute-values|modifiable-text|token-getters>\n";
	echo "                                                   Operation to benchmark. Default: all.\n";
	echo "  --document=<all|document-id[,document-id]>       Document fixture to benchmark. Default: all.\n";
	echo "                                                   Documents: {$documents}\n";
	echo "  --case=<case-id>                                 Exact case ID to run. May be repeated.\n";
	echo "  --iterations=<n>                                 Measured samples per case. Default: 15.\n";
	echo "  --warmup-runs=<n>                                Warmup runs per measured sample. Default: 1.\n";
	echo "  --min-sample-ms=<n>                              Minimum target duration per sample. Default: 50.\n";
	echo "  --max-revs=<n>                                   Maximum calibrated revolutions. Default: 10000.\n";
	echo "  --variance-threshold=<ratio>                     MAD/median warning threshold. Default: 0.10.\n";
	echo "  --php-binary=<path>                              PHP binary for workers. Default: current PHP.\n";
	echo "  --quiet                                         Suppress benchmark command output.\n";
	echo "  --help                                          Show this help text.\n";
}

/**
 * Runs one command and exits on failure.
 *
 * @param string $command     Command to run.
 * @param array  $environment Environment overrides.
 * @param bool   $quiet       Whether to suppress command output.
 */
function wp_html_api_benchmark_compare_run_command( $command, $environment, $quiet ) {
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( $command, $descriptors, $pipes, null, $environment );

	if ( ! is_resource( $process ) ) {
		wp_html_api_benchmark_fail( "Failed to start command: {$command}" );
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$status = proc_close( $process );

	if ( ! $quiet && '' !== $stdout ) {
		echo $stdout;
	}

	if ( '' !== $stderr ) {
		fwrite( STDERR, $stderr );
	}

	if ( 0 !== $status ) {
		wp_html_api_benchmark_fail( "Command failed with status {$status}: {$command}" );
	}
}

/**
 * Runs benchmark.php for one target tree.
 *
 * @param string $target      WordPress source root.
 * @param string $prefix      Result file prefix.
 * @param array  $options     Parsed options.
 * @param bool   $quiet       Whether to suppress command output.
 */
function wp_html_api_benchmark_compare_run_benchmark( $target, $prefix, $options, $quiet ) {
	$benchmark = __DIR__ . '/benchmark.php';
	$command   = escapeshellarg( $options['php-binary'] ) . ' ' . escapeshellarg( $benchmark );
	$forward   = array(
		'processor',
		'operation',
		'document',
		'iterations',
		'warmup-runs',
		'min-sample-ms',
		'max-revs',
		'variance-threshold',
		'php-binary',
	);

	$command .= ' --target=' . escapeshellarg( $target );
	$command .= ' --output=' . escapeshellarg( $options['output'] );

	foreach ( $forward as $name ) {
		if ( isset( $options[ $name ] ) ) {
			$command .= ' --' . $name . '=' . escapeshellarg( $options[ $name ] );
		}
	}

	if ( isset( $options['case'] ) ) {
		foreach ( $options['case'] as $case_id ) {
			$command .= ' --case=' . escapeshellarg( $case_id );
		}
	}

	if ( $quiet ) {
		$command .= ' --quiet';
	}

	$environment = getenv();
	$environment = is_array( $environment ) ? $environment : $_ENV;

	if ( '' === $prefix ) {
		unset( $environment['TEST_RESULTS_PREFIX'] );
	} else {
		$environment['TEST_RESULTS_PREFIX'] = $prefix;
	}

	wp_html_api_benchmark_compare_run_command( $command, $environment, $quiet );
}

/**
 * Runs the existing performance comparison report.
 *
 * @param array $options Parsed options.
 * @param bool  $quiet   Whether to suppress command output.
 */
function wp_html_api_benchmark_compare_run_report( $options, $quiet ) {
	$script = dirname( __DIR__, 2 ) . '/performance/compare-results.js';

	if ( ! file_exists( $script ) ) {
		wp_html_api_benchmark_fail( "Comparison script not found: {$script}" );
	}

	$command = 'node ' . escapeshellarg( $script );
	if ( ! empty( $options['summary'] ) ) {
		$command .= ' ' . escapeshellarg( $options['summary'] );
	}

	$environment                      = getenv();
	$environment                      = is_array( $environment ) ? $environment : $_ENV;
	$environment['WP_ARTIFACTS_PATH'] = $options['output'];

	wp_html_api_benchmark_compare_run_command( $command, $environment, $quiet );
}

$defaults = array(
	'processor'          => 'all',
	'operation'          => 'all',
	'document'           => 'all',
	'iterations'         => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_ITERATIONS', 15 ),
	'warmup-runs'        => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_WARMUP_RUNS', 1 ),
	'min-sample-ms'      => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MIN_SAMPLE_MS', 50 ),
	'max-revs'           => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MAX_REVS', 10000 ),
	'variance-threshold' => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_VARIANCE_THRESHOLD', 0.10 ),
	'target'             => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_TARGET', getcwd() . '/src' ),
	'baseline-target'    => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_BASELINE_TARGET', '' ),
	'output'             => wp_html_api_benchmark_getenv_or_default( 'WP_ARTIFACTS_PATH', getcwd() . '/artifacts' ),
	'php-binary'         => PHP_BINARY,
	'summary'            => '',
	'quiet'              => false,
	'skip-report'        => false,
);

$options = wp_html_api_benchmark_parse_options( $argv, $defaults );

if ( ! empty( $options['help'] ) ) {
	wp_html_api_benchmark_compare_print_usage();
	exit( 0 );
}

if ( empty( $options['baseline-target'] ) ) {
	wp_html_api_benchmark_fail( 'Option --baseline-target is required.' );
}

wp_html_api_benchmark_assert_positive_int( $options['iterations'], 'iterations' );
wp_html_api_benchmark_assert_non_negative_int( $options['warmup-runs'], 'warmup-runs' );
wp_html_api_benchmark_assert_positive_number( $options['min-sample-ms'], 'min-sample-ms' );
wp_html_api_benchmark_assert_positive_int( $options['max-revs'], 'max-revs' );
wp_html_api_benchmark_assert_positive_number( $options['variance-threshold'], 'variance-threshold' );

if ( empty( $options['quiet'] ) ) {
	echo "Running baseline HTML API benchmarks...\n";
}
wp_html_api_benchmark_compare_run_benchmark( $options['baseline-target'], 'before', $options, ! empty( $options['quiet'] ) );

if ( empty( $options['quiet'] ) ) {
	echo "\nRunning current HTML API benchmarks...\n";
}
wp_html_api_benchmark_compare_run_benchmark( $options['target'], '', $options, ! empty( $options['quiet'] ) );

if ( empty( $options['skip-report'] ) ) {
	if ( empty( $options['quiet'] ) ) {
		echo "\nComparing HTML API benchmark results...\n";
	}
	wp_html_api_benchmark_compare_run_report( $options, false );
}
