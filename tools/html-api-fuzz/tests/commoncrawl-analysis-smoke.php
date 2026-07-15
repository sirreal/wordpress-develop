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
	$timeout_dir = $work_dir . '-timeout';
	putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $timeout_dir );
	putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=150' );
	putenv( 'HTML_API_CC_ORACLE_TIMEOUT_MS=50' );
	putenv( 'HTML_API_CC_WORKER_SCRIPT=' . __DIR__ . '/fixtures/commoncrawl-timeout-worker.php' );
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
	$heartbeat_path = $timeout['artifactDir'] . '/descendant-heartbeat';
	$heartbeat_before = is_file( $heartbeat_path ) ? file_get_contents( $heartbeat_path ) : null;
	usleep( 100000 );
	$heartbeat_after = is_file( $heartbeat_path ) ? file_get_contents( $heartbeat_path ) : null;
	html_api_fuzz_commoncrawl_smoke_assert( $heartbeat_before === $heartbeat_after, 'Expected no descendant to survive the worker timeout.' );

	$timeout_replay = json_decode( (string) file_get_contents( $timeout['artifactDir'] . '/replay.json' ), true );
	html_api_fuzz_commoncrawl_smoke_assert( is_string( $timeout_replay['inputBase64'] ?? null ), 'Expected timeout replay to embed exact input.' );
	html_api_fuzz_commoncrawl_smoke_assert( 'php-dom' === ( $timeout_replay['options']['domOracle'] ?? null ), 'Expected timeout replay to preserve its oracle.' );
	$replay_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $timeout['artifactDir'] . '/replay.json',
			'--output-dir', $timeout_dir . '/replayed',
			'--timeout-ms', '10000',
		),
		\HtmlApiFuzz\repo_root(),
		15000,
		$timeout_dir . '/replay-command.log'
	);
	html_api_fuzz_commoncrawl_smoke_assert( 0 === $replay_proc['code'], 'Expected documented timeout-artifact replay command to succeed.' );

	// Kill the real Worker while its Lexbor descendant is hung. Worker receives
	// pending/input.bin as both source and destination; it must not truncate the
	// parent's only crash evidence, and its replay replacement must be atomic.
	$evidence_dir  = $work_dir . '-evidence';
	$evidence_body = '<!doctype html><p>byte-exact crash evidence</p>';
	$oracle_started = $work_dir . '-oracle-started';
	putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $evidence_dir );
	putenv( 'HTML_API_CC_WORKER_SCRIPT' );
	putenv( 'HTML_API_CC_ORACLE=lexbor-source' );
	putenv( 'HTML_API_FUZZ_LEXBOR_ORACLE=' . __DIR__ . '/fixtures/hanging-lexbor-oracle.php' );
	putenv( 'HTML_API_FUZZ_TEST_ORACLE_STARTED=' . $oracle_started );
	putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=1000' );
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
	\HtmlApiFuzz\remove_dir_recursive( $fatal_dir );
	@unlink( $oracle_started );
	html_api_fuzz_commoncrawl_smoke_assert( ! is_dir( $work_dir ), 'Expected smoke artifacts to be cleaned up.' );

	echo "OK commoncrawl-analysis-smoke\n";
}
