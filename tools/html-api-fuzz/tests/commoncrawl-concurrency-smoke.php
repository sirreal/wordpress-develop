#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_concurrency_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function html_api_fuzz_record_processes( array $children, array $control_dirs, array &$observed_pids, array &$nested_groups ): void {
	foreach ( $children as $child ) {
		if ( ( $child['pid'] ?? 0 ) > 1 ) {
			$observed_pids[ $child['pid'] ] = true;
		}
	}
	$own_group = function_exists( 'posix_getpgrp' ) ? posix_getpgrp() : -1;
	foreach ( $control_dirs as $control_dir ) {
		foreach ( glob( $control_dir . '/worker-groups/*.json' ) ?: array() as $marker_path ) {
			try {
				$marker = \HtmlApiFuzz\read_json_file( $marker_path );
			} catch ( Throwable $ignored ) {
				continue;
			}
			$pid  = is_int( $marker['pid'] ?? null ) ? $marker['pid'] : 0;
			$pgid = is_int( $marker['pgid'] ?? null ) ? $marker['pgid'] : 0;
			if ( $pid > 1 ) {
				$observed_pids[ $pid ] = true;
			}
			if ( $pgid > 1 && $pgid !== $own_group ) {
				$nested_groups[ $pgid ] = true;
			}
		}
	}
}

function html_api_fuzz_poll_children( array &$children, array $control_dirs, array &$observed_pids, array &$nested_groups ): bool {
	html_api_fuzz_record_processes( $children, $control_dirs, $observed_pids, $nested_groups );
	$all_finished = true;
	foreach ( $children as &$child ) {
		if ( null !== $child['exitCode'] || ! is_resource( $child['process'] ) ) {
			continue;
		}
		$status = proc_get_status( $child['process'] );
		if ( $status['running'] ) {
			$all_finished = false;
		} else {
			$child['exitCode'] = (int) $status['exitcode'];
		}
	}
	unset( $child );
	return $all_finished;
}

function html_api_fuzz_live_nested_groups( array $nested_groups ): array {
	return array_filter(
		array_keys( $nested_groups ),
		static fn( int $process_group ): bool => $process_group > 1 && @posix_kill( -$process_group, 0 )
	);
}

function html_api_fuzz_stop_children( array &$children, array $control_dirs, array &$observed_pids, array &$nested_groups ): void {
	$outers_finished = html_api_fuzz_poll_children( $children, $control_dirs, $observed_pids, $nested_groups );
	$live_groups = html_api_fuzz_live_nested_groups( $nested_groups );
	if ( $outers_finished && empty( $live_groups ) ) {
		return;
	}
	if ( $outers_finished ) {
		foreach ( $live_groups as $process_group ) {
			@posix_kill( -$process_group, SIGKILL );
		}
	} else {
		foreach ( $children as $child ) {
			if ( null === $child['exitCode'] && ( $child['pid'] ?? 0 ) > 1 ) {
				@posix_kill( -$child['pid'], SIGTERM );
			}
		}
	}
	$grace_deadline = microtime( true ) + 7.0;
	while ( microtime( true ) < $grace_deadline ) {
		$outers_finished = html_api_fuzz_poll_children( $children, $control_dirs, $observed_pids, $nested_groups );
		$live_groups = html_api_fuzz_live_nested_groups( $nested_groups );
		if ( $outers_finished && empty( $live_groups ) ) {
			return;
		}
		usleep( 20000 );
	}
	html_api_fuzz_record_processes( $children, $control_dirs, $observed_pids, $nested_groups );
	foreach ( html_api_fuzz_live_nested_groups( $nested_groups ) as $process_group ) {
		@posix_kill( -$process_group, SIGKILL );
	}
	foreach ( $children as $child ) {
		if ( null === $child['exitCode'] && ( $child['pid'] ?? 0 ) > 1 ) {
			@posix_kill( -$child['pid'], SIGKILL );
		}
	}
	$kill_deadline = microtime( true ) + 2.0;
	while ( microtime( true ) < $kill_deadline ) {
		$outers_finished = html_api_fuzz_poll_children( $children, $control_dirs, $observed_pids, $nested_groups );
		if ( $outers_finished && empty( html_api_fuzz_live_nested_groups( $nested_groups ) ) ) {
			break;
		}
		usleep( 20000 );
	}
}

function html_api_fuzz_close_children( array &$children ): void {
	foreach ( $children as &$child ) {
		if ( is_resource( $child['process'] ) ) {
			$close_code = proc_close( $child['process'] );
			if ( null === $child['exitCode'] && $close_code >= 0 ) {
				$child['exitCode'] = $close_code;
			}
		}
		$child['process'] = null;
	}
	unset( $child );
}

function html_api_fuzz_spawn_writers( string $output_dir, string $control_dir, string $mode, int $writer_count, int $unique_count, array &$all_children ): void {
	\HtmlApiFuzz\ensure_dir( $control_dir . '/logs' );
	$fixture = __DIR__ . '/fixtures/commoncrawl-concurrent-writer.php';
	$launcher = dirname( __DIR__ ) . '/process-group.php';
	for ( $writer_id = 0; $writer_id < $writer_count; ++$writer_id ) {
		$stdout_path = $control_dir . '/logs/writer-' . $writer_id . '.out';
		$stderr_path = $control_dir . '/logs/writer-' . $writer_id . '.err';
		$process = proc_open(
			array(
				PHP_BINARY, $launcher, PHP_BINARY, $fixture,
				'--writer', $output_dir, $control_dir, (string) $writer_id,
				(string) $writer_count, (string) $unique_count, $mode,
			),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', $stdout_path, 'wb' ),
				2 => array( 'file', $stderr_path, 'wb' ),
			),
			$pipes,
			\HtmlApiFuzz\repo_root()
		);
		html_api_fuzz_concurrency_assert( is_resource( $process ), "Could not start concurrent {$mode} writer {$writer_id}." );
		$status = proc_get_status( $process );
		$all_children[] = array(
			'process'    => $process,
			'pid'        => (int) ( $status['pid'] ?? 0 ),
			'exitCode'   => null,
			'stderrPath' => $stderr_path,
		);
	}
}

function html_api_fuzz_run_writers( string $output_dir, string $control_dir, string $mode, int $writer_count, int $unique_count, array &$all_children, array &$control_dirs, array &$observed_pids, array &$nested_groups ): void {
	$control_dirs[ $control_dir ] = $control_dir;
	$offset = count( $all_children );
	html_api_fuzz_spawn_writers( $output_dir, $control_dir, $mode, $writer_count, $unique_count, $all_children );
	$ready_deadline = microtime( true ) + 10.0;
	while ( $writer_count !== count( glob( $control_dir . '/ready-*' ) ?: array() ) ) {
		html_api_fuzz_poll_children( $all_children, $control_dirs, $observed_pids, $nested_groups );
		foreach ( array_slice( $all_children, $offset ) as $child ) {
			html_api_fuzz_concurrency_assert( null === $child['exitCode'], "Concurrent {$mode} writer exited before the start barrier." );
		}
		html_api_fuzz_concurrency_assert( microtime( true ) < $ready_deadline, "Timed out waiting for concurrent {$mode} writers." );
		usleep( 10000 );
	}
	\HtmlApiFuzz\write_file_atomic( $control_dir . '/go', "go\n" );

	$run_deadline = microtime( true ) + 45.0;
	while ( true ) {
		$finished = html_api_fuzz_poll_children( $all_children, $control_dirs, $observed_pids, $nested_groups );
		$run_children_finished = true;
		foreach ( array_slice( $all_children, $offset ) as $child ) {
			if ( null === $child['exitCode'] ) {
				$run_children_finished = false;
				break;
			}
		}
		if ( $finished || $run_children_finished ) {
			break;
		}
		html_api_fuzz_concurrency_assert( microtime( true ) < $run_deadline, "Timed out running concurrent {$mode} writers." );
		usleep( 10000 );
	}
	for ( $index = $offset; $index < count( $all_children ); ++$index ) {
		$child = $all_children[ $index ];
		$error = is_file( $child['stderrPath'] ) ? trim( (string) file_get_contents( $child['stderrPath'] ) ) : '';
		html_api_fuzz_concurrency_assert( 0 === $child['exitCode'], "Concurrent {$mode} writer failed with {$child['exitCode']}: {$error}" );
	}
}

function html_api_fuzz_complete_artifacts( string $output_dir ): array {
	$artifacts = array();
	$findings = $output_dir . '/findings';
	if ( ! is_dir( $findings ) ) {
		return $artifacts;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $findings, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && '.complete' === $file->getFilename() ) {
			$artifacts[] = dirname( $file->getPathname() );
		}
	}
	sort( $artifacts );
	return $artifacts;
}

function html_api_fuzz_validate_concurrent_run( string $output_dir, string $control_dir, string $mode, int $expected_attempts, int $expected_artifacts ): void {
	$summary_path = $output_dir . '/commoncrawl-summary.ndjson';
	$lines = file( $summary_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	html_api_fuzz_concurrency_assert( is_array( $lines ) && $expected_attempts === count( $lines ), "Expected {$expected_attempts} complete {$mode} summaries." );
	$summaries = array();
	foreach ( $lines as $line ) {
		$summary = json_decode( $line, true );
		html_api_fuzz_concurrency_assert( JSON_ERROR_NONE === json_last_error() && is_array( $summary ), "Expected valid complete {$mode} NDJSON records." );
		$summaries[] = $summary;
	}

	$configuration = \HtmlApiFuzz\read_json_file( $output_dir . '/configuration.json' );
	html_api_fuzz_concurrency_assert( is_array( $configuration ), "Expected valid {$mode} configuration." );
	$config_hash = $configuration['configHash'] ?? null;
	$run_id      = $configuration['runId'] ?? null;
	$artifact_paths = array();
	$signature_hashes = array();
	$shared_artifacts = array();
	foreach ( $summaries as $summary ) {
		html_api_fuzz_concurrency_assert( $config_hash === ( $summary['configHash'] ?? null ) && $run_id === ( $summary['runId'] ?? null ), "Expected one {$mode} run identity." );
		if ( 'pass' === $mode ) {
			html_api_fuzz_concurrency_assert( true === ( $summary['ok'] ?? null ) && 'passed' === ( $summary['status'] ?? null ), 'Expected passing concurrent summary.' );
		} else {
			html_api_fuzz_concurrency_assert( false === ( $summary['ok'] ?? null ) && 'worker-crash' === ( $summary['failureClass'] ?? null ), 'Expected deterministic concurrent failure summary.' );
			$signature_hashes[ $summary['signature']['hash'] ?? '' ] = true;
		}
		if ( is_string( $summary['artifactDir'] ?? null ) ) {
			$artifact_paths[ $summary['artifactDir'] ] = true;
		}
		if ( 'urn:uuid:concurrent-shared' === ( $summary['commonCrawl']['recordId'] ?? null ) ) {
			$shared_artifacts[ $summary['artifactDir'] ?? '' ] = true;
		}
	}
	if ( 'pass' === $mode ) {
		html_api_fuzz_concurrency_assert( 4 === count( array_filter( $summaries, static fn( array $summary ): bool => 'urn:uuid:concurrent-shared' === ( $summary['commonCrawl']['recordId'] ?? null ) ) ), 'Expected four shared-document attempts.' );
		html_api_fuzz_concurrency_assert( 1 === count( $shared_artifacts ) && ! isset( $shared_artifacts[''] ), 'Expected shared attempts to publish one idempotent artifact.' );
	} else {
		html_api_fuzz_concurrency_assert( 1 === count( $signature_hashes ) && ! isset( $signature_hashes[''] ), 'Expected one deterministic failure signature.' );
		html_api_fuzz_concurrency_assert( $expected_artifacts === count( array_filter( $summaries, static fn( array $summary ): bool => is_string( $summary['artifactDir'] ?? null ) ) ), 'Expected only capped failures to retain artifacts.' );
	}

	$artifacts = html_api_fuzz_complete_artifacts( $output_dir );
	html_api_fuzz_concurrency_assert( $expected_artifacts === count( $artifacts ), "Expected {$expected_artifacts} complete {$mode} artifacts." );
	html_api_fuzz_concurrency_assert( $expected_artifacts === count( $artifact_paths ), "Expected summary/artifact cardinality agreement for {$mode}." );
	foreach ( $artifacts as $artifact ) {
		html_api_fuzz_concurrency_assert( isset( $artifact_paths[ $artifact ] ), "Expected {$mode} artifact to be referenced by a summary." );
		html_api_fuzz_concurrency_assert( is_file( $artifact . '/input.bin' ) && is_file( $artifact . '/.complete' ), "Expected complete {$mode} artifact evidence." );
		html_api_fuzz_concurrency_assert( is_array( \HtmlApiFuzz\read_json_file( $artifact . '/result.json' ) ), "Expected valid {$mode} result JSON." );
		html_api_fuzz_concurrency_assert( is_array( \HtmlApiFuzz\read_json_file( $artifact . '/replay.json' ) ), "Expected valid {$mode} replay JSON." );
	}

	$coverage = \HtmlApiFuzz\read_json_file( $output_dir . '/coverage.json' );
	clearstatcache( true, $summary_path );
	html_api_fuzz_concurrency_assert( is_array( $coverage ), "Expected valid {$mode} coverage snapshot." );
	html_api_fuzz_concurrency_assert( $expected_attempts === ( $coverage['total'] ?? null ), "Expected exact {$mode} coverage total." );
	html_api_fuzz_concurrency_assert( ( 'pass' === $mode ? $expected_attempts : 0 ) === ( $coverage['covered'] ?? null ), "Expected exact {$mode} covered count." );
	html_api_fuzz_concurrency_assert( ( 'cap' === $mode ? $expected_attempts : 0 ) === ( $coverage['failures'] ?? null ), "Expected exact {$mode} failure count." );
	$status = 'pass' === $mode ? 'passed' : 'crashed';
	html_api_fuzz_concurrency_assert( $expected_attempts === ( $coverage['statuses'][ $status ] ?? null ), "Expected exact {$mode} status count." );
	html_api_fuzz_concurrency_assert( filesize( $summary_path ) === ( $coverage['summaryBytes'] ?? null ), "Expected {$mode} coverage cursor at summary EOF." );
	html_api_fuzz_concurrency_assert( $config_hash === ( $coverage['configHash'] ?? null ) && $run_id === ( $coverage['runId'] ?? null ), "Expected {$mode} coverage identity." );
	$worker_markers = glob( $control_dir . '/worker-groups/*.json' ) ?: array();
	html_api_fuzz_concurrency_assert( $expected_attempts === count( $worker_markers ), "Expected one recorded {$mode} Worker group per attempt." );
	$real_worker_markers = glob( $control_dir . '/real-workers/*.json' ) ?: array();
	html_api_fuzz_concurrency_assert( ( 'pass' === $mode ? $expected_attempts : 0 ) === count( $real_worker_markers ), "Expected exact {$mode} real-Worker marker count." );
	foreach ( $real_worker_markers as $marker_path ) {
		$marker = \HtmlApiFuzz\read_json_file( $marker_path );
		html_api_fuzz_concurrency_assert( '128M' === ( $marker['memoryLimit'] ?? null ), 'Expected real Worker to retain the configured 128M memory limit.' );
		html_api_fuzz_concurrency_assert( ( $marker['pid'] ?? null ) === ( $marker['pgid'] ?? null ), 'Expected real Worker to remain its nested process-group leader.' );
	}

	$pending = $output_dir . '/pending';
	$pending_entries = is_dir( $pending ) ? array_values( array_diff( scandir( $pending ) ?: array(), array( '.', '..' ) ) ) : array();
	html_api_fuzz_concurrency_assert( array() === $pending_entries, "Expected empty {$mode} pending directory." );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $output_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		html_api_fuzz_concurrency_assert( false === strpos( $file->getFilename(), '.tmp-' ), "Expected no abandoned {$mode} atomic temp files." );
	}
}

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-commoncrawl-concurrency-' . getmypid();
$all_children = array();
$control_dirs = array();
$observed_pids = array();
$nested_groups = array();
$exit_code = 0;
$failure_message = null;
try {
	html_api_fuzz_run_writers( $work_dir . '/pass', $work_dir . '/pass-control', 'pass', 4, 3, $all_children, $control_dirs, $observed_pids, $nested_groups );
	html_api_fuzz_validate_concurrent_run( $work_dir . '/pass', $work_dir . '/pass-control', 'pass', 16, 13 );
	html_api_fuzz_run_writers( $work_dir . '/cap', $work_dir . '/cap-control', 'cap', 4, 3, $all_children, $control_dirs, $observed_pids, $nested_groups );
	html_api_fuzz_validate_concurrent_run( $work_dir . '/cap', $work_dir . '/cap-control', 'cap', 12, 2 );
} catch ( Throwable $throwable ) {
	$exit_code = 1;
	$failure_message = $throwable->getMessage();
} finally {
	html_api_fuzz_stop_children( $all_children, $control_dirs, $observed_pids, $nested_groups );
	html_api_fuzz_close_children( $all_children );
	$gone_deadline = microtime( true ) + 2.0;
	do {
		$alive_pids = array_filter( array_keys( $observed_pids ), static fn( int $pid ): bool => $pid > 1 && @posix_kill( $pid, 0 ) );
		$alive_groups = html_api_fuzz_live_nested_groups( $nested_groups );
		if ( empty( $alive_pids ) && empty( $alive_groups ) ) {
			break;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $gone_deadline );
	if ( ! empty( $alive_pids ) || ! empty( $alive_groups ) ) {
		$exit_code = 1;
		$survivors = array();
		if ( ! empty( $alive_pids ) ) {
			$survivors[] = 'PIDs ' . implode( ', ', $alive_pids );
		}
		if ( ! empty( $alive_groups ) ) {
			$survivors[] = 'groups ' . implode( ', ', $alive_groups );
		}
		$failure_message = ( $failure_message ? $failure_message . '; ' : '' ) . 'Concurrent fixture or Worker descendants survived cleanup: ' . implode( '; ', $survivors );
	} else {
		\HtmlApiFuzz\remove_dir_recursive( $work_dir );
	}
}

if ( 0 !== $exit_code ) {
	fwrite( STDERR, 'FAIL: ' . $failure_message . "\n" );
	exit( $exit_code );
}
html_api_fuzz_concurrency_assert( ! is_dir( $work_dir ), 'Expected concurrent smoke artifacts to be cleaned up.' );
echo "OK commoncrawl-concurrency-smoke\n";
