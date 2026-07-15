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
	putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=25' );
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

	require_once dirname( __DIR__ ) . '/lib/autoload.php';
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
	\HtmlApiFuzz\remove_dir_recursive( $timeout_dir );
	html_api_fuzz_commoncrawl_smoke_assert( ! is_dir( $work_dir ), 'Expected smoke artifacts to be cleaned up.' );

	echo "OK commoncrawl-analysis-smoke\n";
}
