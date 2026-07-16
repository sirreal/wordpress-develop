#!/usr/bin/env php
<?php

namespace CcAnalyzer\Analysis {
	class HtmlAnalysisInput {
		public function __construct(
			public string $recordId,
			public string $targetUri,
			public int $responseCode,
			public string $contentType,
			public ?string $transportCharset,
			public string $body,
			public string $inputStateKey,
			public ?int $rangeStart = null,
			public ?int $rangeLength = null
		) {}

		public function byteLength(): int {
			return strlen( $this->body );
		}
	}
}

namespace {
	function html_api_fuzz_commoncrawl_smoke_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	function html_api_fuzz_assert_descendant_stopped( string $state_dir, string $label ): void {
		$pid = (int) file_get_contents( $state_dir . '/descendant-pid' );
		html_api_fuzz_commoncrawl_smoke_assert( $pid > 1, "Expected {$label} descendant PID." );
		$heartbeat_path   = $state_dir . '/descendant-heartbeat';
		$heartbeat_before = is_file( $heartbeat_path ) ? file_get_contents( $heartbeat_path ) : null;
		$deadline         = microtime( true ) + 1.0;
		while ( posix_kill( $pid, 0 ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		usleep( 100000 );
		$heartbeat_after = is_file( $heartbeat_path ) ? file_get_contents( $heartbeat_path ) : null;
		html_api_fuzz_commoncrawl_smoke_assert( ! posix_kill( $pid, 0 ), "Expected {$label} descendant process to be gone." );
		html_api_fuzz_commoncrawl_smoke_assert( $heartbeat_before === $heartbeat_after, "Expected {$label} descendant heartbeat to stop." );
	}

	$work_dir = sys_get_temp_dir() . '/html-api-fuzz-commoncrawl-' . getmypid();
	putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $work_dir );
	putenv( 'HTML_API_CC_ORACLE=php-dom' );
	putenv( 'HTML_API_CC_REQUIRE_UTF8=1' );
	putenv( 'HTML_API_CC_RETAIN_ALL=1' );
	putenv( 'HTML_API_CC_MAX_INPUT_BYTES=4096' );

	/** @var callable $analysis */
	$analysis = require dirname( __DIR__ ) . '/commoncrawl-analysis.php';

	$analysis(
		new \CcAnalyzer\Analysis\HtmlAnalysisInput(
			'urn:uuid:passing-document',
			'https://example.com/',
			200,
			'text/html; charset=UTF-8',
			'UTF-8',
			'<!doctype html><html><head><title>T</title></head><body><p>Hello</p></body></html>',
			'fixture:1'
		)
	);
	$analysis(
		new \CcAnalyzer\Analysis\HtmlAnalysisInput(
			'urn:uuid:invalid-utf8',
			'https://example.com/invalid',
			200,
			'text/html',
			null,
			"<p>bad \xFF byte</p>",
			'fixture:2'
		)
	);

	$summary_path = $work_dir . '/commoncrawl-summary.ndjson';
	html_api_fuzz_commoncrawl_smoke_assert( is_file( $summary_path ), 'Expected the summary stream.' );
	$lines = file( $summary_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	html_api_fuzz_commoncrawl_smoke_assert( is_array( $lines ) && 2 === count( $lines ), 'Expected two Common Crawl summaries.' );
	$passing = json_decode( $lines[0], true );
	$skipped = json_decode( $lines[1], true );
	html_api_fuzz_commoncrawl_smoke_assert( true === ( $passing['ok'] ?? null ), 'Expected the UTF-8 fixture to pass.' );
	html_api_fuzz_commoncrawl_smoke_assert( true === ( $passing['differentialCovered'] ?? null ), 'Expected differential coverage for the UTF-8 fixture.' );
	html_api_fuzz_commoncrawl_smoke_assert( true === ( $passing['artifactsRetained'] ?? null ), 'Expected retain-all to preserve the passing fixture.' );
	html_api_fuzz_commoncrawl_smoke_assert( is_file( $passing['artifactDir'] . '/input.bin' ), 'Expected a retained byte-exact input.' );
	html_api_fuzz_commoncrawl_smoke_assert( is_file( $passing['artifactDir'] . '/replay.json' ), 'Expected a retained replay manifest.' );
	html_api_fuzz_commoncrawl_smoke_assert( is_file( $passing['artifactDir'] . '/.complete' ), 'Expected an atomically published completion marker.' );
	html_api_fuzz_commoncrawl_smoke_assert( is_string( $passing['runId'] ?? null ), 'Expected every summary to identify its run.' );
	html_api_fuzz_commoncrawl_smoke_assert( is_string( $passing['configHash'] ?? null ), 'Expected every summary to identify its configuration.' );
	html_api_fuzz_commoncrawl_smoke_assert( 'skipped-invalid-utf8' === ( $skipped['status'] ?? null ), 'Expected invalid UTF-8 to be skipped by default policy.' );
	html_api_fuzz_commoncrawl_smoke_assert( false === ( $skipped['differentialCovered'] ?? null ), 'Expected no differential coverage for a skipped fixture.' );

	$coverage = json_decode( (string) file_get_contents( $work_dir . '/coverage.json' ), true );
	html_api_fuzz_commoncrawl_smoke_assert( 2 === ( $coverage['total'] ?? null ), 'Expected run-level coverage counters.' );
	html_api_fuzz_commoncrawl_smoke_assert( 1 === ( $coverage['covered'] ?? null ), 'Expected one differentially covered document.' );

	file_put_contents( $work_dir . '/coverage.json', '{corrupt snapshot' );
	$duplicate_runner = \HtmlApiFuzz\CommonCrawlRunner::from_environment();
	$duplicate = $duplicate_runner->analyze_document(
		new \CcAnalyzer\Analysis\HtmlAnalysisInput(
			'urn:uuid:passing-document',
			'https://example.com/',
			200,
			'text/html; charset=UTF-8',
			'UTF-8',
			'<!doctype html><html><head><title>T</title></head><body><p>Hello</p></body></html>',
			'fixture:1'
		)
	);
	html_api_fuzz_commoncrawl_smoke_assert( $passing['artifactDir'] === $duplicate['artifactDir'], 'Expected duplicate input publication to be idempotent.' );
	$recovered_coverage = json_decode( (string) file_get_contents( $work_dir . '/coverage.json' ), true );
	html_api_fuzz_commoncrawl_smoke_assert( 3 === ( $recovered_coverage['total'] ?? null ), 'Expected corrupt coverage snapshot recovery from the append-only summary.' );
	html_api_fuzz_commoncrawl_smoke_assert( 2 === ( $recovered_coverage['covered'] ?? null ), 'Expected rebuilt differential coverage count.' );

	// Reusing a run directory with different semantics must fail loudly.
	putenv( 'HTML_API_CC_MAX_TOKENS=4097' );
	$config_mismatch = false;
	try {
		\HtmlApiFuzz\CommonCrawlRunner::from_environment();
	} catch ( \RuntimeException $e ) {
		$config_mismatch = false !== strpos( $e->getMessage(), 'configuration mismatch' );
	}
	html_api_fuzz_commoncrawl_smoke_assert( $config_mismatch, 'Expected configuration mismatch detection.' );
	putenv( 'HTML_API_CC_MAX_TOKENS' );

	// A hung parser child must become a replayable finding, not hang the callback.
	$timeout_dir          = $work_dir . '-timeout';
	$original_state_dir   = $work_dir . '-descendant-original';
	$original_memory_file = $work_dir . '-memory-original';
	\HtmlApiFuzz\ensure_dir( $original_state_dir );
	putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $timeout_dir );
	putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=150' );
	putenv( 'HTML_API_CC_ORACLE_TIMEOUT_MS=50' );
	putenv( 'HTML_API_CC_WORKER_SCRIPT=' . __DIR__ . '/fixtures/commoncrawl-timeout-worker.php' );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $original_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_MEMORY_MARKER=' . $original_memory_file );
	$timeout_runner = \HtmlApiFuzz\CommonCrawlRunner::from_environment();
	$timeout = $timeout_runner->analyze_document(
		new \CcAnalyzer\Analysis\HtmlAnalysisInput(
			'urn:uuid:timeout-document',
			'https://example.com/timeout',
			200,
			'text/html',
			'UTF-8',
			'<p>persist me before starting the worker</p>',
			'fixture:timeout'
		)
	);
	html_api_fuzz_commoncrawl_smoke_assert( 'worker-timeout' === ( $timeout['failureClass'] ?? null ), 'Expected a supervised worker timeout.' );
	html_api_fuzz_commoncrawl_smoke_assert( is_file( $timeout['artifactDir'] . '/input.bin' ), 'Expected timeout input retention.' );
	html_api_fuzz_commoncrawl_smoke_assert( is_file( $timeout['artifactDir'] . '/.complete' ), 'Expected timeout finding atomic publication.' );
	html_api_fuzz_commoncrawl_smoke_assert( '<p>persist me before starting the worker</p>' === file_get_contents( $timeout['artifactDir'] . '/input.bin' ), 'Expected byte-exact timeout input.' );
	html_api_fuzz_commoncrawl_smoke_assert( true === ( $timeout['process']['stdoutTruncated'] ?? null ), 'Expected bounded capture of noisy worker output.' );
	html_api_fuzz_commoncrawl_smoke_assert( true === ( $timeout['process']['processGroupIsolated'] ?? null ), 'Expected process-group isolation.' );
	html_api_fuzz_assert_descendant_stopped( $original_state_dir, 'original timeout' );
	html_api_fuzz_commoncrawl_smoke_assert( '256M' === file_get_contents( $original_memory_file ), 'Expected original Worker to receive configured memory limit.' );

	// A Worker can crash immediately after forking. Its detached descendant is
	// still in the isolated group and must be reaped on the natural-exit path.
	$fast_crash_state_dir = $work_dir . '-descendant-fast-crash';
	\HtmlApiFuzz\ensure_dir( $fast_crash_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $fast_crash_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_CRASH_AFTER_FORK=1' );
	$fast_crash_process = \HtmlApiFuzz\run_php_process(
		array(
			__DIR__ . '/fixtures/commoncrawl-timeout-worker.php',
			'--output-dir', $timeout_dir . '/fast-crash-worker',
		),
		\HtmlApiFuzz\repo_root(),
		5000,
		$timeout_dir . '/fast-crash-worker.log',
		1048576,
		true
	);
	putenv( 'HTML_API_FUZZ_TEST_CRASH_AFTER_FORK' );
	html_api_fuzz_commoncrawl_smoke_assert( 42 === $fast_crash_process['code'] && false === $fast_crash_process['timedOut'], 'Expected deterministic fast Worker crash.' );
	html_api_fuzz_commoncrawl_smoke_assert( true === $fast_crash_process['processGroupIsolated'], 'Expected fast-crash process-group isolation to be observed.' );
	html_api_fuzz_commoncrawl_smoke_assert( false === $fast_crash_process['processGroupCleanupFailed'], 'Expected fast-crash process group cleanup to be verified.' );
	html_api_fuzz_assert_descendant_stopped( $fast_crash_state_dir, 'fast-crash' );
	$fast_crash_group = (int) file_get_contents( $fast_crash_state_dir . '/process-group-id' );
	html_api_fuzz_commoncrawl_smoke_assert( $fast_crash_group > 1 && ! posix_kill( -$fast_crash_group, 0 ), 'Expected no surviving fast-crash process group.' );

	$timeout_replay = json_decode( (string) file_get_contents( $timeout['artifactDir'] . '/replay.json' ), true );
	html_api_fuzz_commoncrawl_smoke_assert( is_string( $timeout_replay['inputBase64'] ?? null ), 'Expected timeout replay to embed exact input.' );
	html_api_fuzz_commoncrawl_smoke_assert( 'php-dom' === ( $timeout_replay['options']['domOracle'] ?? null ), 'Expected timeout replay to preserve its oracle.' );
	html_api_fuzz_commoncrawl_smoke_assert( 150 === ( $timeout_replay['options']['processTimeoutMs'] ?? null ), 'Expected timeout replay to record process timeout.' );
	html_api_fuzz_commoncrawl_smoke_assert( '256M' === ( $timeout_replay['options']['memoryLimit'] ?? null ), 'Expected timeout replay to record memory limit.' );
	$malformed_policy_cases = array();
	$malformed_options = $timeout_replay;
	$malformed_options['options'] = 'not-an-object';
	$malformed_policy_cases['non-array-options'] = $malformed_options;
	$fractional_timeout = $timeout_replay;
	$fractional_timeout['options']['processTimeoutMs'] = 1.5;
	$malformed_policy_cases['fractional-timeout'] = $fractional_timeout;
	$null_worker = $timeout_replay;
	$null_worker['options']['workerScript'] = null;
	$malformed_policy_cases['null-worker'] = $null_worker;
	$empty_worker = $timeout_replay;
	$empty_worker['options']['workerScript'] = '';
	$malformed_policy_cases['empty-worker'] = $empty_worker;
	$invalid_checks = $timeout_replay;
	$invalid_checks['options']['checks'] = 1;
	$malformed_policy_cases['invalid-checks'] = $invalid_checks;
	$invalid_memory = $timeout_replay;
	$invalid_memory['options']['memoryLimit'] = null;
	$malformed_policy_cases['invalid-memory'] = $invalid_memory;
	$fractional_chrome_startup = $timeout_replay;
	$fractional_chrome_startup['options']['chromeStartupTimeoutMs'] = 1.5;
	$malformed_policy_cases['fractional-chrome-startup'] = $fractional_chrome_startup;
	$fractional_oracle_timeout = $timeout_replay;
	$fractional_oracle_timeout['options']['oracleTimeoutMs'] = 1.5;
	$malformed_policy_cases['fractional-oracle-timeout'] = $fractional_oracle_timeout;
	$empty_chrome_script = $timeout_replay;
	$empty_chrome_script['options']['chromeOracleScript'] = '';
	$malformed_policy_cases['empty-chrome-script'] = $empty_chrome_script;
	foreach ( $malformed_policy_cases as $case_name => $malformed_policy_replay ) {
		$malformed_policy_path = $timeout_dir . '/malformed-policy-' . $case_name . '.json';
		$malformed_policy_output = $timeout_dir . '/malformed-policy-' . $case_name;
		\HtmlApiFuzz\write_json_file_atomic( $malformed_policy_path, $malformed_policy_replay );
		$malformed_policy_proc = \HtmlApiFuzz\run_php_process(
			array(
				dirname( __DIR__ ) . '/replay.php',
				'--replay', $malformed_policy_path,
				'--output-dir', $malformed_policy_output,
			),
			\HtmlApiFuzz\repo_root(),
			5000
		);
		html_api_fuzz_commoncrawl_smoke_assert( 1 === $malformed_policy_proc['code'], "Expected {$case_name} recorded policy to be rejected." );
		html_api_fuzz_commoncrawl_smoke_assert( ! is_dir( $malformed_policy_output ), "Expected {$case_name} rejection before output creation or claim." );
	}
	foreach ( array( 'memory-limit', 'timeout-ms', 'worker-script', 'checks' ) as $bare_option ) {
		$bare_option_output = $timeout_dir . '/bare-option-' . $bare_option;
		$bare_option_proc = \HtmlApiFuzz\run_php_process(
			array(
				dirname( __DIR__ ) . '/replay.php',
				'--replay', $timeout['artifactDir'] . '/replay.json',
				'--output-dir', $bare_option_output,
				'--' . $bare_option,
			),
			\HtmlApiFuzz\repo_root(),
			5000
		);
		html_api_fuzz_commoncrawl_smoke_assert( 1 === $bare_option_proc['code'], "Expected bare --{$bare_option} to be rejected." );
		html_api_fuzz_commoncrawl_smoke_assert( ! is_dir( $bare_option_output ), "Expected bare --{$bare_option} rejection before output creation or claim." );
	}
	$replay_state_dir   = $work_dir . '-descendant-replay';
	$replay_memory_file = $work_dir . '-memory-replay';
	\HtmlApiFuzz\ensure_dir( $replay_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $replay_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_MEMORY_MARKER=' . $replay_memory_file );
	$replay_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $timeout['artifactDir'] . '/replay.json',
			'--output-dir', $timeout_dir . '/replayed',
		),
		\HtmlApiFuzz\repo_root(),
		5000,
		$timeout_dir . '/replay-command.log'
	);
	html_api_fuzz_commoncrawl_smoke_assert( 2 === $replay_proc['code'], 'Expected replay to reproduce the recorded timeout without CLI overrides.' );
	$replayed_timeout = \HtmlApiFuzz\read_json_file( $timeout_dir . '/replayed/result.json' );
	html_api_fuzz_commoncrawl_smoke_assert( 'worker-timeout' === ( $replayed_timeout['failureClass'] ?? null ), 'Expected replayed timeout failure class.' );
	html_api_fuzz_commoncrawl_smoke_assert( ( $timeout['signature']['hash'] ?? null ) === ( $replayed_timeout['signature']['hash'] ?? null ), 'Expected replayed timeout signature.' );
	html_api_fuzz_commoncrawl_smoke_assert( '256M' === file_get_contents( $replay_memory_file ), 'Expected replay to consume recorded memory limit.' );
	html_api_fuzz_assert_descendant_stopped( $replay_state_dir, 'replay timeout' );
	$replayed_manifest = \HtmlApiFuzz\read_json_file( $timeout_dir . '/replayed/replay.json' );
	html_api_fuzz_commoncrawl_smoke_assert( 150 === ( $replayed_manifest['options']['processTimeoutMs'] ?? null ), 'Expected replay output to preserve the effective timeout.' );
	html_api_fuzz_commoncrawl_smoke_assert( '256M' === ( $replayed_manifest['options']['memoryLimit'] ?? null ), 'Expected replay output to preserve the effective memory limit.' );

	// Never reuse an output directory containing evidence from another attempt.
	$stale_replay_dir = $timeout_dir . '/stale-replay';
	\HtmlApiFuzz\ensure_dir( $stale_replay_dir );
	\HtmlApiFuzz\write_file_atomic( $stale_replay_dir . '/result.json', "stale evidence\n" );
	$stale_replay_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $timeout['artifactDir'] . '/replay.json',
			'--output-dir', $stale_replay_dir,
		),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	html_api_fuzz_commoncrawl_smoke_assert( 1 === $stale_replay_proc['code'], 'Expected replay to reject a stale output directory.' );
	html_api_fuzz_commoncrawl_smoke_assert( "stale evidence\n" === file_get_contents( $stale_replay_dir . '/result.json' ), 'Expected replay not to clobber stale evidence.' );
	html_api_fuzz_commoncrawl_smoke_assert( ! is_file( $stale_replay_dir . '/.replay-attempt' ), 'Expected a rejected stale directory not to remain claimed.' );

	// A Worker can die halfway through JSON publication. Replay must replace both
	// malformed artifacts with the canonical supervised-process result.
	$corrupt_state_dir   = $work_dir . '-descendant-corrupt-replay';
	$corrupt_memory_file = $work_dir . '-memory-corrupt-replay';
	$corrupt_replay_dir  = $timeout_dir . '/corrupt-replay';
	\HtmlApiFuzz\ensure_dir( $corrupt_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $corrupt_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_MEMORY_MARKER=' . $corrupt_memory_file );
	putenv( 'HTML_API_FUZZ_TEST_CORRUPT_OUTPUT=1' );
	$corrupt_replay_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $timeout['artifactDir'] . '/replay.json',
			'--output-dir', $corrupt_replay_dir,
		),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	putenv( 'HTML_API_FUZZ_TEST_CORRUPT_OUTPUT' );
	html_api_fuzz_commoncrawl_smoke_assert( 2 === $corrupt_replay_proc['code'], 'Expected malformed Worker artifacts to become a replayed timeout.' );
	$corrupt_result = \HtmlApiFuzz\read_json_file( $corrupt_replay_dir . '/result.json' );
	$corrupt_manifest = \HtmlApiFuzz\read_json_file( $corrupt_replay_dir . '/replay.json' );
	html_api_fuzz_commoncrawl_smoke_assert( 'worker-timeout' === ( $corrupt_result['failureClass'] ?? null ), 'Expected malformed result JSON to be replaced with the canonical timeout.' );
	html_api_fuzz_commoncrawl_smoke_assert( ( $timeout['signature']['hash'] ?? null ) === ( $corrupt_result['signature']['hash'] ?? null ), 'Expected malformed result recovery to preserve the timeout signature.' );
	html_api_fuzz_commoncrawl_smoke_assert( 150 === ( $corrupt_manifest['options']['processTimeoutMs'] ?? null ), 'Expected malformed replay JSON recovery to preserve effective policy.' );
	html_api_fuzz_assert_descendant_stopped( $corrupt_state_dir, 'malformed-output replay' );

	// Diagnostic overrides become the concrete policy recorded by the replay.
	// Replaying that output without overrides must consume the same policy.
	$override_state_dir_1   = $work_dir . '-descendant-override-1';
	$override_memory_file_1 = $work_dir . '-memory-override-1';
	$override_replay_dir_1  = $timeout_dir . '/override-replay-1';
	\HtmlApiFuzz\ensure_dir( $override_state_dir_1 );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $override_state_dir_1 );
	putenv( 'HTML_API_FUZZ_TEST_MEMORY_MARKER=' . $override_memory_file_1 );
	$override_replay_proc_1 = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $timeout['artifactDir'] . '/replay.json',
			'--output-dir', $override_replay_dir_1,
			'--memory-limit', '192M',
			'--timeout-ms', '180',
			'--checks', 'full',
		),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	html_api_fuzz_commoncrawl_smoke_assert( 2 === $override_replay_proc_1['code'], 'Expected replay with explicit policy overrides to reproduce the timeout.' );
	$override_manifest_1 = \HtmlApiFuzz\read_json_file( $override_replay_dir_1 . '/replay.json' );
	html_api_fuzz_commoncrawl_smoke_assert( '192M' === file_get_contents( $override_memory_file_1 ), 'Expected Worker to receive the memory override.' );
	html_api_fuzz_commoncrawl_smoke_assert( '192M' === ( $override_manifest_1['options']['memoryLimit'] ?? null ), 'Expected replay output to record the memory override.' );
	html_api_fuzz_commoncrawl_smoke_assert( 180 === ( $override_manifest_1['options']['processTimeoutMs'] ?? null ), 'Expected replay output to record the timeout override.' );
	html_api_fuzz_commoncrawl_smoke_assert( 'full' === ( $override_manifest_1['options']['checks'] ?? null ), 'Expected replay output to record the check override.' );
	html_api_fuzz_commoncrawl_smoke_assert( realpath( __DIR__ . '/fixtures/commoncrawl-timeout-worker.php' ) === ( $override_manifest_1['options']['workerScript'] ?? null ), 'Expected replay output to record the resolved Worker path.' );
	html_api_fuzz_assert_descendant_stopped( $override_state_dir_1, 'override replay' );

	$override_state_dir_2   = $work_dir . '-descendant-override-2';
	$override_memory_file_2 = $work_dir . '-memory-override-2';
	$override_replay_dir_2  = $timeout_dir . '/override-replay-2';
	\HtmlApiFuzz\ensure_dir( $override_state_dir_2 );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $override_state_dir_2 );
	putenv( 'HTML_API_FUZZ_TEST_MEMORY_MARKER=' . $override_memory_file_2 );
	$override_replay_proc_2 = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $override_replay_dir_1 . '/replay.json',
			'--output-dir', $override_replay_dir_2,
		),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	html_api_fuzz_commoncrawl_smoke_assert( 2 === $override_replay_proc_2['code'], 'Expected replay-of-replay to consume the prior effective policy.' );
	$override_manifest_2 = \HtmlApiFuzz\read_json_file( $override_replay_dir_2 . '/replay.json' );
	html_api_fuzz_commoncrawl_smoke_assert( '192M' === file_get_contents( $override_memory_file_2 ), 'Expected replay-of-replay to consume the recorded memory override.' );
	html_api_fuzz_commoncrawl_smoke_assert( '192M' === ( $override_manifest_2['options']['memoryLimit'] ?? null ), 'Expected replay-of-replay to retain the effective memory policy.' );
	html_api_fuzz_commoncrawl_smoke_assert( 180 === ( $override_manifest_2['options']['processTimeoutMs'] ?? null ), 'Expected replay-of-replay to retain the effective timeout policy.' );
	html_api_fuzz_commoncrawl_smoke_assert( 'full' === ( $override_manifest_2['options']['checks'] ?? null ), 'Expected replay-of-replay to retain the effective check policy.' );
	html_api_fuzz_assert_descendant_stopped( $override_state_dir_2, 'replay-of-replay' );

	// A replay moved between checkouts may name a Worker that no longer exists.
	// Its valid recorded path must remain replaceable by an explicit override.
	$moved_worker_replay = $timeout_replay;
	$moved_worker_replay['options']['workerScript'] = '/checkout-that-moved/tools/html-api-fuzz/worker.php';
	$moved_worker_path = $timeout_dir . '/moved-worker-replay.json';
	$moved_worker_dir  = $timeout_dir . '/moved-worker-replay';
	$moved_worker_state_dir = $work_dir . '-descendant-moved-worker';
	\HtmlApiFuzz\write_json_file_atomic( $moved_worker_path, $moved_worker_replay );
	\HtmlApiFuzz\ensure_dir( $moved_worker_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $moved_worker_state_dir );
	$moved_worker_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $moved_worker_path,
			'--output-dir', $moved_worker_dir,
			'--worker-script', __DIR__ . '/fixtures/commoncrawl-timeout-worker.php',
		),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	html_api_fuzz_commoncrawl_smoke_assert( 2 === $moved_worker_proc['code'], 'Expected an explicit Worker override to replace a valid stale recorded path.' );
	$moved_worker_manifest = \HtmlApiFuzz\read_json_file( $moved_worker_dir . '/replay.json' );
	html_api_fuzz_commoncrawl_smoke_assert( realpath( __DIR__ . '/fixtures/commoncrawl-timeout-worker.php' ) === ( $moved_worker_manifest['options']['workerScript'] ?? null ), 'Expected moved replay to record its effective Worker override.' );
	html_api_fuzz_assert_descendant_stopped( $moved_worker_state_dir, 'moved replay Worker override' );

	// Legacy manifests have no recorded memory limit. Resolve the actual child
	// PHP default under its inherited configuration instead of inventing one.
	$legacy_timeout_replay = $timeout_replay;
	unset( $legacy_timeout_replay['options']['memoryLimit'] );
	$legacy_timeout_path = $timeout_dir . '/legacy-timeout-replay.json';
	\HtmlApiFuzz\write_json_file_atomic( $legacy_timeout_path, $legacy_timeout_replay );
	$legacy_php_ini_dir = $work_dir . '-legacy-php-ini';
	$legacy_state_dir   = $work_dir . '-descendant-legacy-replay';
	$legacy_memory_file = $work_dir . '-memory-legacy-replay';
	$legacy_replay_dir  = $timeout_dir . '/legacy-replay';
	\HtmlApiFuzz\ensure_dir( $legacy_php_ini_dir );
	\HtmlApiFuzz\ensure_dir( $legacy_state_dir );
	\HtmlApiFuzz\write_file_atomic( $legacy_php_ini_dir . '/php.ini', "memory_limit=384M\n" );
	$previous_phprc = getenv( 'PHPRC' );
	putenv( 'PHPRC=' . $legacy_php_ini_dir . '/php.ini' );
	putenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR=' . $legacy_state_dir );
	putenv( 'HTML_API_FUZZ_TEST_MEMORY_MARKER=' . $legacy_memory_file );
	$legacy_replay_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $legacy_timeout_path,
			'--output-dir', $legacy_replay_dir,
		),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	if ( false === $previous_phprc ) {
		putenv( 'PHPRC' );
	} else {
		putenv( 'PHPRC=' . $previous_phprc );
	}
	html_api_fuzz_commoncrawl_smoke_assert( 2 === $legacy_replay_proc['code'], 'Expected legacy replay to use the inherited child PHP policy.' );
	$legacy_manifest = \HtmlApiFuzz\read_json_file( $legacy_replay_dir . '/replay.json' );
	html_api_fuzz_commoncrawl_smoke_assert( '384M' === file_get_contents( $legacy_memory_file ), 'Expected legacy replay Worker to receive the probed child PHP memory limit.' );
	html_api_fuzz_commoncrawl_smoke_assert( '384M' === ( $legacy_manifest['options']['memoryLimit'] ?? null ), 'Expected legacy replay output to record the probed child PHP memory limit.' );
	html_api_fuzz_assert_descendant_stopped( $legacy_state_dir, 'legacy replay' );

	// Kill the real Worker while its Lexbor descendant is hung. Worker receives
	// pending/input.bin as both source and destination; it must not truncate the
	// parent's only crash evidence, and its replay replacement must be atomic.
	$evidence_dir  = $work_dir . '-evidence';
	$evidence_body = '<!doctype html><p>byte-exact crash evidence</p>';
	$oracle_started = $work_dir . '-oracle-started';
	$hanging_oracle_dir = $work_dir . '-hanging-oracle';
	$hanging_oracle = $hanging_oracle_dir . '/hanging-lexbor-oracle.php';
	\HtmlApiFuzz\ensure_dir( $hanging_oracle_dir );
	html_api_fuzz_commoncrawl_smoke_assert( copy( __DIR__ . '/fixtures/hanging-lexbor-oracle.php', $hanging_oracle ), 'Expected a private hanging-oracle fixture copy.' );
	html_api_fuzz_commoncrawl_smoke_assert( chmod( $hanging_oracle, 0500 ), 'Expected the hanging-oracle fixture to be executable.' );
	\HtmlApiFuzz\write_json_file(
		$hanging_oracle_dir . '/build-manifest.json',
		array(
			'kind'           => 'html-api-fuzz-lexbor-build',
			'requestedRef'   => 'test-hanging-oracle',
			'resolvedCommit' => str_repeat( '0', 40 ),
			'upstream'       => 'https://github.com/lexbor/lexbor.git',
			'builtAt'        => gmdate( 'c' ),
			'binarySha256'   => hash_file( 'sha256', $hanging_oracle ),
			'compiler'       => 'test fixture',
			'cmake'          => 'test fixture',
		)
	);
	putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $evidence_dir );
	putenv( 'HTML_API_CC_WORKER_SCRIPT' );
	putenv( 'HTML_API_CC_ORACLE=lexbor-source' );
	putenv( 'HTML_API_FUZZ_LEXBOR_ORACLE=' . $hanging_oracle );
	putenv( 'HTML_API_FUZZ_TEST_ORACLE_STARTED=' . $oracle_started );
	putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=3000' );
	putenv( 'HTML_API_CC_ORACLE_TIMEOUT_MS=5000' );
	$evidence_runner = \HtmlApiFuzz\CommonCrawlRunner::from_environment();
	$evidence = $evidence_runner->analyze_document(
		new \CcAnalyzer\Analysis\HtmlAnalysisInput(
			'urn:uuid:crash-evidence',
			'https://example.com/evidence',
			200,
			'text/html',
			'UTF-8',
			$evidence_body,
			'fixture:evidence'
		)
	);
	html_api_fuzz_commoncrawl_smoke_assert( 'worker-timeout' === ( $evidence['failureClass'] ?? null ), 'Expected the real Worker to be killed while its oracle hangs.' );
	html_api_fuzz_commoncrawl_smoke_assert( "started\n" === file_get_contents( $oracle_started ), 'Expected proof that Worker reached the hanging oracle before timeout.' );
	html_api_fuzz_commoncrawl_smoke_assert( $evidence_body === file_get_contents( $evidence['artifactDir'] . '/input.bin' ), 'Expected killed Worker to preserve exact parent input.' );
	$evidence_replay = json_decode( (string) file_get_contents( $evidence['artifactDir'] . '/replay.json' ), true );
	html_api_fuzz_commoncrawl_smoke_assert( is_array( $evidence_replay ), 'Expected killed Worker to leave valid replay JSON.' );
	html_api_fuzz_commoncrawl_smoke_assert( $evidence_body === base64_decode( $evidence_replay['inputBase64'] ?? '', true ), 'Expected killed Worker replay to preserve exact input.' );

	// The CLI catch/fallback path is also a worker result publication path.
	$fatal_dir = $work_dir . '-fatal-worker';
	$fatal_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/worker.php',
			'--input-base64', 'not-valid-base64%',
			'--output-dir', $fatal_dir,
		),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	html_api_fuzz_commoncrawl_smoke_assert( 1 === $fatal_proc['code'], 'Expected forced Worker fatal fallback.' );
	$fatal_result = json_decode( (string) file_get_contents( $fatal_dir . '/result.json' ), true );
	html_api_fuzz_commoncrawl_smoke_assert( 'worker-fatal' === ( $fatal_result['status'] ?? null ), 'Expected complete fatal fallback result JSON.' );
	html_api_fuzz_commoncrawl_smoke_assert( array() === glob( $fatal_dir . '/.result.json.tmp-*' ), 'Expected no abandoned fatal-result publication temp file.' );

	require_once dirname( __DIR__ ) . '/lib/autoload.php';
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
	\HtmlApiFuzz\remove_dir_recursive( $timeout_dir );
	\HtmlApiFuzz\remove_dir_recursive( $evidence_dir );
	\HtmlApiFuzz\remove_dir_recursive( $hanging_oracle_dir );
	\HtmlApiFuzz\remove_dir_recursive( $fatal_dir );
	\HtmlApiFuzz\remove_dir_recursive( $original_state_dir );
	\HtmlApiFuzz\remove_dir_recursive( $replay_state_dir );
	\HtmlApiFuzz\remove_dir_recursive( $fast_crash_state_dir );
	\HtmlApiFuzz\remove_dir_recursive( $corrupt_state_dir );
	\HtmlApiFuzz\remove_dir_recursive( $override_state_dir_1 );
	\HtmlApiFuzz\remove_dir_recursive( $override_state_dir_2 );
	\HtmlApiFuzz\remove_dir_recursive( $moved_worker_state_dir );
	\HtmlApiFuzz\remove_dir_recursive( $legacy_php_ini_dir );
	\HtmlApiFuzz\remove_dir_recursive( $legacy_state_dir );
	@unlink( $oracle_started );
	@unlink( $original_memory_file );
	@unlink( $replay_memory_file );
	@unlink( $corrupt_memory_file );
	@unlink( $override_memory_file_1 );
	@unlink( $override_memory_file_2 );
	@unlink( $legacy_memory_file );
	html_api_fuzz_commoncrawl_smoke_assert( ! is_dir( $work_dir ), 'Expected smoke artifacts to be cleaned up.' );

	echo "OK commoncrawl-analysis-smoke\n";
}
