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
	echo "  --stability-check=<off|warn|strict>              Host stability check mode. Default: off.\n";
	echo "  --stability-wait-seconds=<n>                     Seconds to wait for an idle host. Default: 0.\n";
	echo "  --min-host-idle=<ratio>                          Minimum host CPU idle ratio. Default: 0.75.\n";
	echo "  --max-load-ratio=<ratio>                         Maximum 1-minute load per CPU. Default: 0.50.\n";
	echo "  --min-cpu-ratio=<ratio>                          Minimum sample CPU/wall time ratio. Default: 0.95.\n";
	echo "  --max-sample-retries=<n>                         Retries for noisy samples in stability mode. Default: 3.\n";
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

	return $stdout;
}

/**
 * Builds a benchmark.php command.
 *
 * @param string $target   WordPress source root.
 * @param string $output   Artifact directory.
 * @param array  $options  Parsed options.
 * @param array  $case_ids Case IDs.
 * @param bool   $quiet    Whether to suppress benchmark output.
 * @return string Benchmark command.
 */
function wp_html_api_benchmark_compare_build_benchmark_command( $target, $output, $options, $case_ids = array(), $quiet = false ) {
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
		'stability-check',
		'stability-wait-seconds',
		'min-host-idle',
		'max-load-ratio',
		'min-cpu-ratio',
		'max-sample-retries',
		'php-binary',
	);

	$command .= ' --target=' . escapeshellarg( $target );
	$command .= ' --output=' . escapeshellarg( $output );

	foreach ( $forward as $name ) {
		if ( isset( $options[ $name ] ) ) {
			$command .= ' --' . $name . '=' . escapeshellarg( $options[ $name ] );
		}
	}

	foreach ( $case_ids as $case_id ) {
		$command .= ' --case=' . escapeshellarg( $case_id );
	}

	if ( $quiet ) {
		$command .= ' --quiet';
	}

	return $command;
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
	$case_ids = isset( $options['case'] ) ? $options['case'] : array();
	$command  = wp_html_api_benchmark_compare_build_benchmark_command( $target, $options['output'], $options, $case_ids, $quiet );

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
 * Runs benchmark.php for one target and one case, returning the result entry.
 *
 * @param string $target     WordPress source root.
 * @param string $role       Target role.
 * @param string $prefix     Result file prefix.
 * @param string $case_id    Benchmark case ID.
 * @param int    $case_order Case order.
 * @param int    $run_order  Run order.
 * @param array  $options    Parsed options.
 * @param bool   $quiet      Whether to suppress command output.
 * @return array<string,mixed> Benchmark result entry.
 */
function wp_html_api_benchmark_compare_run_case( $target, $role, $prefix, $case_id, $case_order, $run_order, $options, $quiet ) {
	$output      = rtrim( sys_get_temp_dir(), '/\\' ) . '/html-api-benchmark-' . getmypid() . '-' . str_replace( '.', '-', uniqid( '', true ) );
	$command     = wp_html_api_benchmark_compare_build_benchmark_command( $target, $output, $options, array( $case_id ), $quiet );
	$environment = getenv();
	$environment = is_array( $environment ) ? $environment : $_ENV;

	if ( '' === $prefix ) {
		unset( $environment['TEST_RESULTS_PREFIX'] );
	} else {
		$environment['TEST_RESULTS_PREFIX'] = $prefix;
	}

	$started_at = gmdate( 'c' );
	wp_html_api_benchmark_compare_run_command( $command, $environment, $quiet );
	$ended_at = gmdate( 'c' );

	$file = rtrim( $output, '/\\' ) . '/' . ( '' === $prefix ? '' : $prefix . '-' ) . 'performance-results.json';
	if ( ! file_exists( $file ) ) {
		wp_html_api_benchmark_fail( "Benchmark result file not found: {$file}" );
	}

	$entries = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $entries ) || 1 !== count( $entries ) ) {
		wp_html_api_benchmark_fail( "Unexpected benchmark result shape: {$file}" );
	}

	$entry                        = $entries[0];
	$entry['metadata']['compare'] = array(
		'role'      => $role,
		'target'    => $target,
		'caseOrder' => $case_order,
		'runOrder'  => $run_order,
		'startedAt' => $started_at,
		'endedAt'   => $ended_at,
	);

	return $entry;
}

/**
 * Runs stable comparison benchmarks interleaved by case.
 *
 * @param array $options Parsed options.
 * @param bool  $quiet   Whether to suppress command output.
 */
function wp_html_api_benchmark_compare_run_interleaved( $options, $quiet ) {
	$selected_cases = wp_html_api_benchmark_select_cases( $options );
	$before_results = array();
	$after_results  = array();
	$case_order     = 0;
	$run_order      = 0;

	foreach ( $selected_cases as $case_id => $case ) {
		if ( ! $quiet ) {
			echo "\nRunning interleaved HTML API benchmarks for {$case['title']}...\n";
		}

		$runs = 0 === $case_order % 2
			? array( 'baseline', 'current' )
			: array( 'current', 'baseline' );

		foreach ( $runs as $role ) {
			++$run_order;
			if ( 'baseline' === $role ) {
				$before_results[] = wp_html_api_benchmark_compare_run_case( $options['baseline-target'], 'baseline', 'before', $case_id, $case_order, $run_order, $options, $quiet );
			} else {
				$after_results[] = wp_html_api_benchmark_compare_run_case( $options['target'], 'current', '', $case_id, $case_order, $run_order, $options, $quiet );
			}
		}

		++$case_order;
	}

	wp_html_api_benchmark_write_json_file(
		rtrim( $options['output'], '/\\' ) . '/before-performance-results.json',
		$before_results
	);
	wp_html_api_benchmark_write_json_file(
		rtrim( $options['output'], '/\\' ) . '/performance-results.json',
		$after_results
	);
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
	'processor'              => 'all',
	'operation'              => 'all',
	'document'               => 'all',
	'iterations'             => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_ITERATIONS', 15 ),
	'warmup-runs'            => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_WARMUP_RUNS', 1 ),
	'min-sample-ms'          => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MIN_SAMPLE_MS', 50 ),
	'max-revs'               => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MAX_REVS', 10000 ),
	'variance-threshold'     => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_VARIANCE_THRESHOLD', 0.10 ),
	'stability-check'        => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_STABILITY_CHECK', 'off' ),
	'stability-wait-seconds' => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_STABILITY_WAIT_SECONDS', 0 ),
	'min-host-idle'          => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MIN_HOST_IDLE', 0.75 ),
	'max-load-ratio'         => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MAX_LOAD_RATIO', 0.50 ),
	'min-cpu-ratio'          => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MIN_CPU_RATIO', 0.95 ),
	'max-sample-retries'     => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_MAX_SAMPLE_RETRIES', 3 ),
	'target'                 => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_TARGET', getcwd() . '/src' ),
	'baseline-target'        => wp_html_api_benchmark_getenv_or_default( 'HTML_API_BENCHMARK_BASELINE_TARGET', '' ),
	'output'                 => wp_html_api_benchmark_getenv_or_default( 'WP_ARTIFACTS_PATH', getcwd() . '/artifacts' ),
	'php-binary'             => PHP_BINARY,
	'summary'                => '',
	'quiet'                  => false,
	'skip-report'            => false,
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
wp_html_api_benchmark_assert_allowed_value( $options['stability-check'], array( 'off', 'warn', 'strict' ), 'stability-check' );
wp_html_api_benchmark_assert_non_negative_int( $options['stability-wait-seconds'], 'stability-wait-seconds' );
wp_html_api_benchmark_assert_ratio( $options['min-host-idle'], 'min-host-idle' );
wp_html_api_benchmark_assert_non_negative_number( $options['max-load-ratio'], 'max-load-ratio' );
wp_html_api_benchmark_assert_ratio( $options['min-cpu-ratio'], 'min-cpu-ratio' );
wp_html_api_benchmark_assert_non_negative_int( $options['max-sample-retries'], 'max-sample-retries' );

if ( 'off' === $options['stability-check'] ) {
	if ( empty( $options['quiet'] ) ) {
		echo "Running baseline HTML API benchmarks...\n";
	}
	wp_html_api_benchmark_compare_run_benchmark( $options['baseline-target'], 'before', $options, ! empty( $options['quiet'] ) );

	if ( empty( $options['quiet'] ) ) {
		echo "\nRunning current HTML API benchmarks...\n";
	}
	wp_html_api_benchmark_compare_run_benchmark( $options['target'], '', $options, ! empty( $options['quiet'] ) );
} else {
	wp_html_api_benchmark_compare_run_interleaved( $options, ! empty( $options['quiet'] ) );
}

if ( empty( $options['skip-report'] ) ) {
	if ( empty( $options['quiet'] ) ) {
		echo "\nComparing HTML API benchmark results...\n";
	}
	wp_html_api_benchmark_compare_run_report( $options, false );
}
