#!/usr/bin/env php
<?php

// Become a new session/process-group leader, then replace this process with
// the requested PHP command. Descendants inherit the group, allowing the
// Common Crawl supervisor to terminate the whole tree on timeout.
if ( $argc < 3 || ! function_exists( 'posix_setsid' ) || ! function_exists( 'pcntl_exec' ) ) {
	fwrite( STDERR, "Process-group launcher requires a program, arguments, POSIX, and PCNTL.\n" );
	exit( 125 );
}
if ( -1 === posix_setsid() ) {
	fwrite( STDERR, "Could not create an isolated process session.\n" );
	exit( 125 );
}

$program = $argv[1];
pcntl_exec( $program, array_slice( $argv, 2 ) );
fwrite( STDERR, "Could not execute isolated child process.\n" );
exit( 125 );
