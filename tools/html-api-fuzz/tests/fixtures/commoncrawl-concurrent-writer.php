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
	require_once dirname( __DIR__, 2 ) . '/lib/autoload.php';

	$worker_mode    = getenv( 'HTML_API_FUZZ_CONCURRENT_WORKER_MODE' );
	$worker_control = getenv( 'HTML_API_FUZZ_CONCURRENT_WORKER_CONTROL' );
	if ( '1' === getenv( 'HTML_API_FUZZ_CONCURRENT_REAL_WORKER' ) ) {
		if ( ! is_string( $worker_control ) || '' === $worker_control ) {
			fwrite( STDERR, "Missing concurrent real-Worker control directory.\n" );
			exit( 71 );
		}
		$pid = getmypid();
		\HtmlApiFuzz\write_json_file_atomic(
			$worker_control . '/real-workers/' . $pid . '.json',
			array(
				'pid'         => $pid,
				'pgid'        => posix_getpgid( $pid ),
				'memoryLimit' => ini_get( 'memory_limit' ),
			)
		);
		return;
	}
	if ( in_array( $worker_mode, array( 'pass-proxy', 'failure' ), true ) ) {
		if ( ! is_string( $worker_control ) || '' === $worker_control ) {
			fwrite( STDERR, "Missing concurrent Worker control directory.\n" );
			exit( 71 );
		}
		$pid = getmypid();
		\HtmlApiFuzz\write_json_file_atomic(
			$worker_control . '/worker-groups/' . $pid . '.json',
			array(
				'pid'  => $pid,
				'pgid' => posix_getpgid( $pid ),
				'mode' => $worker_mode,
			)
		);
		if ( 'failure' === $worker_mode ) {
			fwrite( STDERR, "deterministic concurrent worker failure\n" );
			exit( 42 );
		}

		$memory_limit = (string) ini_get( 'memory_limit' );
		if ( ! preg_match( '/^(?:-1|[1-9][0-9]*[KMG]?)$/i', $memory_limit ) ) {
			fwrite( STDERR, "Invalid inherited Worker memory limit.\n" );
			exit( 71 );
		}
		putenv( 'HTML_API_FUZZ_CONCURRENT_REAL_WORKER=1' );
		$real_worker = dirname( __DIR__, 2 ) . '/worker.php';
		$args = array(
			'-d', 'memory_limit=' . $memory_limit,
			'-d', 'auto_prepend_file=' . __FILE__,
			$real_worker,
		);
		$args = array_merge( $args, array_slice( $argv, 1 ) );
		pcntl_exec( PHP_BINARY, $args );
		fwrite( STDERR, "Could not execute real concurrent Worker.\n" );
		exit( 71 );
	}

	if ( 8 !== $argc || '--writer' !== $argv[1] ) {
		fwrite( STDERR, "Usage: commoncrawl-concurrent-writer.php --writer OUTPUT CONTROL ID WRITERS UNIQUE pass|cap\n" );
		exit( 64 );
	}

	$output_dir   = $argv[2];
	$control_dir  = $argv[3];
	$writer_id    = filter_var( $argv[4], FILTER_VALIDATE_INT );
	$writer_count = filter_var( $argv[5], FILTER_VALIDATE_INT );
	$unique_count = filter_var( $argv[6], FILTER_VALIDATE_INT );
	$mode         = $argv[7];
	if (
		false === $writer_id || $writer_id < 0 ||
		false === $writer_count || $writer_count < 1 ||
		false === $unique_count || $unique_count < 1 ||
		! in_array( $mode, array( 'pass', 'cap' ), true )
	) {
		fwrite( STDERR, "Invalid concurrent writer arguments.\n" );
		exit( 64 );
	}

	$stop_requested = false;
	if ( ! function_exists( 'pcntl_async_signals' ) || ! function_exists( 'pcntl_signal' ) ) {
		fwrite( STDERR, "Concurrent writer requires PCNTL signal handling.\n" );
		exit( 69 );
	}
	pcntl_async_signals( true );
	$record_stop = static function () use ( &$stop_requested ): void {
		$stop_requested = true;
	};
	pcntl_signal( SIGTERM, $record_stop );
	pcntl_signal( SIGINT, $record_stop );

	$wait_until = static function ( callable $ready, string $label ) use ( &$stop_requested ): void {
		$deadline = microtime( true ) + 20.0;
		while ( ! $ready() ) {
			if ( $stop_requested ) {
				exit( 143 );
			}
			if ( microtime( true ) >= $deadline ) {
				fwrite( STDERR, "Timed out waiting for {$label}.\n" );
				exit( 70 );
			}
			usleep( 10000 );
		}
	};

	\HtmlApiFuzz\ensure_dir( $control_dir );
	\HtmlApiFuzz\write_file_atomic( $control_dir . '/pid-' . $writer_id, (string) getmypid() . "\n" );
	\HtmlApiFuzz\write_file_atomic( $control_dir . '/ready-' . $writer_id, "ready\n" );
	$wait_until( static fn(): bool => is_file( $control_dir . '/go' ), 'start barrier' );

	putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $output_dir );
	putenv( 'HTML_API_CC_RUN_ID=concurrent-' . $mode );
	putenv( 'HTML_API_CC_ORACLE=php-dom' );
	putenv( 'HTML_API_CC_ORACLE_TIMEOUT_MS=2000' );
	putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=5000' );
	putenv( 'HTML_API_CC_MEMORY_LIMIT=128M' );
	putenv( 'HTML_API_CC_CHECKS=baseline' );
	putenv( 'HTML_API_CC_FULL_SAMPLE_PERCENT=0' );
	putenv( 'HTML_API_CC_REQUIRE_UTF8=1' );
	putenv( 'HTML_API_CC_MAX_INPUT_BYTES=4096' );
	putenv( 'HTML_API_CC_MAX_TOKENS=500' );
	putenv( 'HTML_API_CC_MAX_NODES=500' );
	putenv( 'HTML_API_CC_MAX_DEPTH=64' );
	putenv( 'HTML_API_CC_MAX_TREE_BYTES=1048576' );
	putenv( 'HTML_API_CC_RETAIN_ALL=' . ( 'pass' === $mode ? '1' : '0' ) );
	putenv( 'HTML_API_CC_MAX_KEEP_PER_SIGNATURE=' . ( 'pass' === $mode ? '64' : '2' ) );
	putenv( 'CC_ANALYZER_VERSION' );
	putenv( 'CC_ANALYZER_CRAWL' );
	putenv( 'CC_ANALYZER_INVOCATION' );
	putenv( 'HTML_API_CC_WORKER_SCRIPT=' . __FILE__ );
	putenv( 'HTML_API_FUZZ_CONCURRENT_WORKER_MODE=' . ( 'cap' === $mode ? 'failure' : 'pass-proxy' ) );
	putenv( 'HTML_API_FUZZ_CONCURRENT_WORKER_CONTROL=' . $control_dir );
	putenv( 'HTML_API_FUZZ_CONCURRENT_REAL_WORKER' );

	$runner = \HtmlApiFuzz\CommonCrawlRunner::from_environment();
	for ( $document_id = 0; $document_id < $unique_count; ++$document_id ) {
		$body = '<!doctype html><p>concurrent ' . $mode . ' ' . $writer_id . ' ' . $document_id . '</p>';
		$summary = $runner->analyze_document(
			new \CcAnalyzer\Analysis\HtmlAnalysisInput(
				'urn:uuid:concurrent-' . $mode . '-' . $writer_id . '-' . $document_id,
				'https://example.com/concurrent/' . $mode . '/' . $writer_id . '/' . $document_id,
				200,
				'text/html; charset=UTF-8',
				'UTF-8',
				$body,
				'fixture:' . $mode . ':' . $writer_id . ':' . $document_id
			)
		);
		$expected = 'pass' === $mode
			? true === ( $summary['ok'] ?? null ) && 'passed' === ( $summary['status'] ?? null )
			: false === ( $summary['ok'] ?? null ) && 'worker-crash' === ( $summary['failureClass'] ?? null );
		if ( ! $expected ) {
			fwrite( STDERR, "Unexpected {$mode} summary for writer {$writer_id}, document {$document_id}.\n" );
			exit( 2 );
		}
		if ( $stop_requested ) {
			exit( 143 );
		}
	}

	if ( 'pass' === $mode ) {
		\HtmlApiFuzz\write_file_atomic( $control_dir . '/shared-ready-' . $writer_id, "ready\n" );
		$wait_until(
			static fn(): bool => $writer_count === count( glob( $control_dir . '/shared-ready-*' ) ?: array() ),
			'shared-document barrier'
		);
		$shared = $runner->analyze_document(
			new \CcAnalyzer\Analysis\HtmlAnalysisInput(
				'urn:uuid:concurrent-shared',
				'https://example.com/concurrent/shared',
				200,
				'text/html; charset=UTF-8',
				'UTF-8',
				'<!doctype html><p>same document from every writer</p>',
				'fixture:shared:' . $writer_id
			)
		);
		if ( true !== ( $shared['ok'] ?? null ) || 'passed' !== ( $shared['status'] ?? null ) ) {
			fwrite( STDERR, "Unexpected shared summary for writer {$writer_id}.\n" );
			exit( 2 );
		}
	}

	if ( $stop_requested ) {
		exit( 143 );
	}
	\HtmlApiFuzz\write_file_atomic( $control_dir . '/done-' . $writer_id, "done\n" );
	exit( 0 );
}
