#!/usr/bin/env php
<?php

if ( in_array( '--version', $argv, true ) ) {
	echo '{"status":"ok","oracle":{"kind":"lexbor-source","lexborCommit":"0000000000000000000000000000000000000000","lexborVersion":"test"}}' . "\n";
	exit( 0 );
}

// The outer Common Crawl supervisor must kill both Worker and this descendant
// while preserving the parent's byte-exact input and a valid replay manifest.
$started_marker = getenv( 'HTML_API_FUZZ_TEST_ORACLE_STARTED' );
if ( is_string( $started_marker ) && '' !== $started_marker ) {
	$written = file_put_contents( $started_marker, "started\n" );
	if ( 8 !== $written ) {
		fwrite( STDERR, "Could not write oracle-started marker.\n" );
		exit( 1 );
	}
}
usleep( 5000000 );
