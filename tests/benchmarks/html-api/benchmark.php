<?php
/**
 * Runs HTML API benchmarks.
 *
 * @package WordPress
 * @subpackage Benchmark
 */

require __DIR__ . '/includes.php';

/**
 * Prints benchmark usage details.
 */
function wp_html_api_benchmark_print_usage() {
	$script    = 'php tests/benchmarks/html-api/benchmark.php';
	$documents = implode( '|', wp_html_api_benchmark_document_ids() );

	echo "Usage: {$script} [options]\n\n";
	echo "Options:\n";
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
	echo "  --target=<path>                                  WordPress source root. Default: src.\n";
	echo "  --output=<path>                                  Artifact directory. Default: artifacts.\n";
	echo "  --php-binary=<path>                              PHP binary for workers. Default: current PHP.\n";
	echo "  --quiet                                         Suppress per-case console output.\n";
	echo "  --help                                          Show this help text.\n";
}

/**
 * Runs one worker process.
 *
 * @param string $case_id     Benchmark case ID.
 * @param int    $revolutions Number of revolutions to run.
 * @param int    $warmup_runs Number of warmup runs.
 * @param array  $options     Runner options.
 * @return array<string,mixed> Worker result.
 */
function wp_html_api_benchmark_run_worker( $case_id, $revolutions, $warmup_runs, $options ) {
	$php_binary = $options['php-binary'];
	$worker     = __DIR__ . '/worker.php';

	$args = array(
		'--case=' . $case_id,
		'--revs=' . $revolutions,
		'--warmup-runs=' . $warmup_runs,
		'--target=' . $options['target'],
	);

	$command = escapeshellarg( $php_binary ) .
		' -d xdebug.mode=off -d opcache.enable_cli=1 -d opcache.jit=0 ' .
		escapeshellarg( $worker );

	foreach ( $args as $arg ) {
		$command .= ' ' . escapeshellarg( $arg );
	}

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$environment = getenv();
	$environment = is_array( $environment ) ? $environment : $_ENV;
	$environment = array_merge(
		$environment,
		array(
			'XDEBUG_MODE' => 'off',
		)
	);

	$logical_cpu_count = wp_html_api_benchmark_logical_cpu_count();
	if ( null !== $logical_cpu_count ) {
		$environment['HTML_API_BENCHMARK_CPU_COUNT'] = (string) $logical_cpu_count;
	}

	$process = proc_open( $command, $descriptors, $pipes, null, $environment );

	if ( ! is_resource( $process ) ) {
		wp_html_api_benchmark_fail( 'Failed to start benchmark worker process.' );
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$status = proc_close( $process );
	$result = json_decode( $stdout, true );

	if ( 0 !== $status || ! is_array( $result ) || empty( $result['ok'] ) ) {
		$message = "Benchmark worker failed for {$case_id}.";
		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			$message .= ' ' . $result['error'];
		}
		if ( '' !== trim( $stderr ) ) {
			$message .= "\n" . trim( $stderr );
		}
		wp_html_api_benchmark_fail( $message );
	}

	return $result;
}

/**
 * Returns one raw sample entry from a worker result.
 *
 * @param array $result      Worker result.
 * @param int   $revolutions Number of revolutions in the sample.
 * @return array<string,mixed> Raw sample entry.
 */
function wp_html_api_benchmark_worker_sample_entry( $result, $revolutions ) {
	$cpu_time_ratio = isset( $result['cpu_ns'] ) && $result['elapsed_ns'] > 0 ? $result['cpu_ns'] / $result['elapsed_ns'] : 0;

	return array(
		'durationMs'                 => ( $result['elapsed_ns'] / 1000000 ) / $revolutions,
		'elapsedNs'                  => $result['elapsed_ns'],
		'cpuNs'                      => isset( $result['cpu_ns'] ) ? $result['cpu_ns'] : null,
		'cpuTimeRatio'               => $cpu_time_ratio,
		'voluntaryContextSwitches'   => isset( $result['voluntary_context_switches'] ) ? $result['voluntary_context_switches'] : null,
		'involuntaryContextSwitches' => isset( $result['involuntary_context_switches'] ) ? $result['involuntary_context_switches'] : null,
		'majorPageFaults'            => isset( $result['major_page_faults'] ) ? $result['major_page_faults'] : null,
		'tokens'                     => $result['tokens'],
		'work'                       => $result['work'],
		'checksum'                   => $result['checksum'],
		'accepted'                   => true,
	);
}

/**
 * Calibrates the number of revolutions for a case.
 *
 * @param string $case_id Benchmark case ID.
 * @param array  $options Runner options.
 * @return array<string,mixed> Calibration metadata.
 */
function wp_html_api_benchmark_calibrate_revolutions( $case_id, $options ) {
	$min_sample_ms     = (float) $options['min-sample-ms'];
	$max_revs          = (int) $options['max-revs'];
	$max_retries       = (int) $options['max-sample-retries'];
	$min_cpu_ratio     = (float) $options['min-cpu-ratio'];
	$revs              = 1;
	$rejected_attempts = 0;
	$attempts          = array();
	$warnings          = array();

	while ( true ) {
		$result         = wp_html_api_benchmark_run_worker( $case_id, $revs, 0, $options );
		$cpu_time_ratio = isset( $result['cpu_ns'] ) && $result['elapsed_ns'] > 0 ? $result['cpu_ns'] / $result['elapsed_ns'] : 0;
		$elapsed_ms     = $result['elapsed_ns'] / 1000000;
		$attempt        = array(
			'revolutions'  => $revs,
			'elapsedMs'    => $elapsed_ms,
			'cpuTimeRatio' => $cpu_time_ratio,
			'accepted'     => true,
		);

		if ( 'off' !== $options['stability-check'] && $cpu_time_ratio < $min_cpu_ratio ) {
			$attempt['accepted'] = false;
			$attempts[]          = $attempt;

			if ( $rejected_attempts < $max_retries ) {
				++$rejected_attempts;
				wp_html_api_benchmark_wait_for_stable_host( $options );
				continue;
			}

			$warnings[] = sprintf(
				'Calibration CPU/wall time ratio is %.2f, below the configured %.2f threshold.',
				$cpu_time_ratio,
				$min_cpu_ratio
			);

			if ( 'strict' === $options['stability-check'] ) {
				wp_html_api_benchmark_fail( "Benchmark case {$case_id} calibration is not stable enough for strict mode. " . implode( ' ', $warnings ) );
			}
		} else {
			$attempts[] = $attempt;
		}

		if ( $elapsed_ms >= $min_sample_ms || $revs >= $max_revs ) {
			return array(
				'revolutions'      => $revs,
				'attempts'         => $attempts,
				'rejectedAttempts' => $rejected_attempts,
				'warnings'         => $warnings,
			);
		}

		$estimated_revs = (int) ceil( $revs * $min_sample_ms / max( $elapsed_ms, 0.001 ) );
		$revs           = min( $max_revs, max( $revs * 2, $estimated_revs ) );
	}
}

/**
 * Runs a benchmark case and returns a performance result entry.
 *
 * @param string $case_id Benchmark case ID.
 * @param array  $benchmark_case Benchmark case.
 * @param array  $options        Runner options.
 * @return array<string,mixed> Performance result entry.
 */
function wp_html_api_benchmark_run_case( $case_id, $benchmark_case, $options ) {
	$iterations          = (int) $options['iterations'];
	$warmup_runs         = (int) $options['warmup-runs'];
	$variance_threshold  = (float) $options['variance-threshold'];
	$stability           = wp_html_api_benchmark_wait_for_stable_host( $options );
	$document            = wp_html_api_benchmark_document( $benchmark_case['document'] );
	$calibration         = wp_html_api_benchmark_calibrate_revolutions( $case_id, $options );
	$revolutions         = $calibration['revolutions'];
	$samples_ms          = array();
	$raw_samples         = array();
	$rejected_samples    = array();
	$tokens_per_run      = array();
	$work_per_run        = array();
	$checksums_per_run   = array();
	$cpu_time_ratios     = array();
	$first_environment   = null;
	$calibration_warning = null;
	$warnings            = array_merge( $stability['warnings'], $calibration['warnings'] );
	$max_rejections      = $iterations * (int) $options['max-sample-retries'];

	while ( count( $samples_ms ) < $iterations ) {
		$result         = wp_html_api_benchmark_run_worker( $case_id, $revolutions, $warmup_runs, $options );
		$sample         = wp_html_api_benchmark_worker_sample_entry( $result, $revolutions );
		$cpu_time_ratio = $sample['cpuTimeRatio'];

		if ( 'off' !== $options['stability-check'] && $cpu_time_ratio < (float) $options['min-cpu-ratio'] ) {
			$sample['accepted'] = false;

			if ( count( $rejected_samples ) < $max_rejections ) {
				$rejected_samples[] = $sample;
				wp_html_api_benchmark_wait_for_stable_host( $options );
				continue;
			}

			$message = sprintf(
				'Unable to collect %d stable samples after rejecting %d noisy attempts.',
				$iterations,
				count( $rejected_samples )
			);

			if ( 'strict' === $options['stability-check'] ) {
				wp_html_api_benchmark_fail( "Benchmark case {$case_id} is not stable enough for strict mode. {$message}" );
			}

			$warnings[] = $message;
		}

		$samples_ms[]        = $sample['durationMs'];
		$tokens_per_run[]    = $result['tokens'] / $revolutions;
		$work_per_run[]      = $result['work'] / $revolutions;
		$checksums_per_run[] = $result['checksum'] / $revolutions;
		$cpu_time_ratios[]   = $cpu_time_ratio;

		if ( null === $first_environment && isset( $result['environment'] ) ) {
			$first_environment = $result['environment'];
		}

		$raw_samples[] = $sample;
	}

	$stats                 = wp_html_api_benchmark_statistics( $samples_ms );
	$tokens                = (int) round( wp_html_api_benchmark_median( $tokens_per_run ) );
	$work                  = (int) round( wp_html_api_benchmark_median( $work_per_run ) );
	$checksum              = (int) round( wp_html_api_benchmark_median( $checksums_per_run ) );
	$relative_mad          = $stats['median'] > 0 ? $stats['mad'] / $stats['median'] : 0;
	$min_cpu_time_ratio    = count( $cpu_time_ratios ) ? min( $cpu_time_ratios ) : 0;
	$median_cpu_time_ratio = wp_html_api_benchmark_median( $cpu_time_ratios );

	if ( $relative_mad > $variance_threshold ) {
		$warnings[] = sprintf(
			'MAD is %.2f%% of the median, above the configured %.2f%% threshold.',
			$relative_mad * 100,
			$variance_threshold * 100
		);
	}

	if ( 1 < count( array_unique( array_map( 'intval', $tokens_per_run ) ) ) ) {
		$warnings[] = 'Token count varied across samples.';
	}

	if ( 1 < count( array_unique( array_map( 'intval', $work_per_run ) ) ) ) {
		$warnings[] = 'Operation count varied across samples.';
	}

	if ( 1 < count( array_unique( array_map( 'intval', $checksums_per_run ) ) ) ) {
		$warnings[] = 'Checksum varied across samples.';
	}

	if ( ( $stats['median'] * $revolutions ) < (float) $options['min-sample-ms'] ) {
		$calibration_warning = 'The measured sample duration did not reach min-sample-ms.';
		$warnings[]          = $calibration_warning;
	}

	if ( 'off' !== $options['stability-check'] && $min_cpu_time_ratio < (float) $options['min-cpu-ratio'] ) {
		$warnings[] = sprintf(
			'Minimum sample CPU/wall time ratio is %.2f, below the configured %.2f threshold.',
			$min_cpu_time_ratio,
			(float) $options['min-cpu-ratio']
		);
	}

	if ( 'strict' === $options['stability-check'] && ! empty( $warnings ) ) {
		wp_html_api_benchmark_fail( "Benchmark case {$case_id} is not stable enough for strict mode. " . implode( ' ', $warnings ) );
	}

	return array(
		'file'     => 'tests/benchmarks/html-api/benchmark.php',
		'title'    => $benchmark_case['title'],
		'results'  => array(
			array(
				'durationMs' => $samples_ms,
			),
		),
		'metadata' => array(
			'schema'             => 'wordpress-html-api-benchmark/v1',
			'case'               => $case_id,
			'processor'          => $benchmark_case['processor'],
			'operation'          => $benchmark_case['operation'],
			'document'           => $benchmark_case['document'],
			'bytes'              => strlen( $document ),
			'tokensPerRun'       => $tokens,
			'operationsPerRun'   => $work,
			'checksumPerRun'     => $checksum,
			'revolutions'        => $revolutions,
			'iterations'         => $iterations,
			'warmupRuns'         => $warmup_runs,
			'minSampleMs'        => (float) $options['min-sample-ms'],
			'statistics'         => $stats,
			'samples'            => $raw_samples,
			'environment'        => $first_environment,
			'stability'          => array(
				'check'                    => $options['stability-check'],
				'host'                     => $stability['snapshot'],
				'hostWarnings'             => $stability['warnings'],
				'minCpuTimeRatio'          => $min_cpu_time_ratio,
				'medianCpuTimeRatio'       => $median_cpu_time_ratio,
				'minCpuTimeRatioThreshold' => (float) $options['min-cpu-ratio'],
				'maxSampleRetries'         => (int) $options['max-sample-retries'],
				'rejectedSampleCount'      => count( $rejected_samples ),
				'rejectedSamples'          => $rejected_samples,
				'minHostIdle'              => (float) $options['min-host-idle'],
				'maxLoadRatio'             => (float) $options['max-load-ratio'],
				'stabilityWaitSeconds'     => (int) $options['stability-wait-seconds'],
			),
			'calibration'        => $calibration,
			'calibrationWarning' => $calibration_warning,
			'warnings'           => $warnings,
		),
	);
}

/**
 * Prints one benchmark result summary.
 *
 * @param array $entry Performance result entry.
 */
function wp_html_api_benchmark_print_result( $entry ) {
	$meta        = $entry['metadata'];
	$stats       = $meta['statistics'];
	$median      = $stats['median'];
	$tokens      = $meta['tokensPerRun'];
	$operations  = $meta['operationsPerRun'];
	$tokens_sec  = $median > 0 ? ( $tokens / ( $median / 1000 ) ) : 0;
	$warnings    = $meta['warnings'];
	$warning_msg = $warnings ? ' warnings: ' . implode( ' ', $warnings ) : '';

	printf(
		"%s\n  %d revs, %d samples, %.4f ms median, %.4f ms MAD, %d tokens/run, %d operations/run, %.0f tokens/s%s\n",
		$entry['title'],
		$meta['revolutions'],
		$meta['iterations'],
		$median,
		$stats['mad'],
		$tokens,
		$operations,
		$tokens_sec,
		$warning_msg
	);
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
	'output'                 => wp_html_api_benchmark_getenv_or_default( 'WP_ARTIFACTS_PATH', getcwd() . '/artifacts' ),
	'php-binary'             => PHP_BINARY,
	'quiet'                  => false,
);

$options = wp_html_api_benchmark_parse_options( $argv, $defaults );

if ( ! empty( $options['help'] ) ) {
	wp_html_api_benchmark_print_usage();
	exit( 0 );
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

$selected_cases = wp_html_api_benchmark_select_cases( $options );

if ( empty( $selected_cases ) ) {
	wp_html_api_benchmark_fail( 'No benchmark cases selected.' );
}

$results = array();

foreach ( $selected_cases as $case_id => $case ) {
	$entry     = wp_html_api_benchmark_run_case( $case_id, $case, $options );
	$results[] = $entry;

	if ( empty( $options['quiet'] ) ) {
		wp_html_api_benchmark_print_result( $entry );
	}
}

$prefix           = getenv( 'TEST_RESULTS_PREFIX' );
$file_name_prefix = $prefix ? $prefix . '-' : '';
$output_file      = rtrim( $options['output'], '/\\' ) . '/' . $file_name_prefix . 'performance-results.json';

wp_html_api_benchmark_write_json_file( $output_file, $results );

if ( empty( $options['quiet'] ) ) {
	echo "\nWrote {$output_file}\n";
}
