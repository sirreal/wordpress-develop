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
	$heartbeat = $output_dir . '/descendant-heartbeat';
	$code      = '$path=$argv[1]; for($i=0;$i<500;$i++){file_put_contents($path, (string)$i); usleep(10000);}';
	$null      = '/dev/null';
	$child     = proc_open(
		array( PHP_BINARY, '-r', $code, $heartbeat ),
		array( 0 => array( 'file', $null, 'r' ), 1 => array( 'file', $null, 'w' ), 2 => array( 'file', $null, 'w' ) ),
		$pipes
	);
}
fwrite( STDOUT, str_repeat( 'x', 2 * 1024 * 1024 ) );
usleep( 500000 );
