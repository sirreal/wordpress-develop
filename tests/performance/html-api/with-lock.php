<?php
/**
 * Runs a command while holding the benchmark lock.
 *
 * Usage: php tests/performance/html-api/with-lock.php <command> [args...]
 *
 * Use it for any timing or profiling outside bench.php, so that it never runs
 * at the same time as a benchmark run. Exits with the command's exit code.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

require_once __DIR__ . '/lib/class-benchmark-lock.php';

$command = array_slice( $argv, 1 );
if ( empty( $command ) ) {
	fwrite( STDERR, "usage: php with-lock.php <command> [args...]\n" );
	exit( 2 );
}

$lock = Benchmark_Lock::acquire( 'with-lock.php ' . implode( ' ', $command ) );
// An empty spec lets the child inherit stdin, stdout and stderr; passing STDOUT or STDERR makes
// PHP seek a redirected file's shared offset, and the outputs then overwrite each other.
$process = proc_open( $command, array(), $pipes );
if ( false === $process ) {
	fwrite( STDERR, 'with-lock.php: could not start ' . $command[0] . "\n" );
	exit( 2 );
}
exit( proc_close( $process ) );
