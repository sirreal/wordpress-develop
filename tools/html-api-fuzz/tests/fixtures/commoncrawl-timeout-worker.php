#!/usr/bin/env php
<?php

// Deliberately produces no result so the Common Crawl supervisor must retain
// the already-persisted input and synthesize a worker-timeout finding. It also
// emits more than the supervisor capture cap and starts a heartbeat child;
// the test verifies bounded capture and whole-process-group termination.
$output_dir = null;
for ( $i = 1; $i + 1 < $argc; ++$i ) {
	if ( '--output-dir' === $argv[ $i ] ) {
		$output_dir = $argv[ $i + 1 ];
		break;
	}
}
if ( is_string( $output_dir ) ) {
	$state_dir = getenv( 'HTML_API_FUZZ_TEST_DESCENDANT_STATE_DIR' );
	if ( ! is_string( $state_dir ) || '' === $state_dir ) {
		$state_dir = $output_dir;
	}
	if ( ! is_dir( $state_dir ) && ! mkdir( $state_dir, 0777, true ) && ! is_dir( $state_dir ) ) {
		exit( 1 );
	}
	$heartbeat = $state_dir . '/descendant-heartbeat';
	if ( '1' === getenv( 'HTML_API_FUZZ_TEST_CRASH_AFTER_FORK' ) ) {
		$child_pid = pcntl_fork();
		if ( -1 === $child_pid ) {
			exit( 1 );
		}
		if ( 0 === $child_pid ) {
			@fclose( STDIN );
			@fclose( STDOUT );
			@fclose( STDERR );
			for ( $iteration = 0; $iteration < 500; ++$iteration ) {
				file_put_contents( $heartbeat, (string) $iteration );
				usleep( 10000 );
			}
			exit( 0 );
		}
		file_put_contents( $state_dir . '/descendant-pid', (string) $child_pid );
		file_put_contents( $state_dir . '/process-group-id', (string) posix_getpgrp() );
		$heartbeat_deadline = microtime( true ) + 1.0;
		while ( ! is_file( $heartbeat ) && microtime( true ) < $heartbeat_deadline ) {
			usleep( 10000 );
		}
		exit( is_file( $heartbeat ) ? 42 : 1 );
	}
	$code      = '$path=$argv[1]; for($i=0;$i<500;$i++){file_put_contents($path, (string)$i); usleep(10000);}';
	$null      = '/dev/null';
	$child     = proc_open(
		array( PHP_BINARY, '-r', $code, $heartbeat ),
		array( 0 => array( 'file', $null, 'r' ), 1 => array( 'file', $null, 'w' ), 2 => array( 'file', $null, 'w' ) ),
		$pipes
	);
	if ( is_resource( $child ) ) {
		$status = proc_get_status( $child );
		file_put_contents( $state_dir . '/descendant-pid', (string) ( $status['pid'] ?? 0 ) );
	}
}
$memory_marker = getenv( 'HTML_API_FUZZ_TEST_MEMORY_MARKER' );
if ( is_string( $memory_marker ) && '' !== $memory_marker ) {
	file_put_contents( $memory_marker, (string) ini_get( 'memory_limit' ) );
}
if ( '1' === getenv( 'HTML_API_FUZZ_TEST_CORRUPT_OUTPUT' ) && is_string( $output_dir ) ) {
	file_put_contents( $output_dir . '/result.json', '{corrupt result' );
	file_put_contents( $output_dir . '/replay.json', '{corrupt replay' );
}
fwrite( STDOUT, str_repeat( 'x', 2 * 1024 * 1024 ) );
usleep( 500000 );
