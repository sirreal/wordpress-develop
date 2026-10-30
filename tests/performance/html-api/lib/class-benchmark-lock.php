<?php
/**
 * Machine-wide lock held for the whole of a timed run.
 *
 * Part of the HTML API parsing benchmark. Dev tool only; not shipped.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

/**
 * Serializes timed runs on one machine: two runs at once slow each other and
 * corrupt both results. `bench.php` and `with-lock.php` hold the same lock file.
 */
class Benchmark_Lock {
	/**
	 * Path of the lock file. A fixed path so that every user session and process sees the same file.
	 *
	 * @return string Path.
	 */
	public static function path(): string {
		$dir = is_dir( '/tmp' ) ? '/tmp' : sys_get_temp_dir();
		return "{$dir}/wp-html-api-bench.lock";
	}

	/**
	 * Takes the lock, waiting for any other holder, and keeps it until the process exits.
	 *
	 * @param string $who Description of the holder, written into the lock file for anyone waiting.
	 * @return resource Lock handle; keep a reference for as long as the lock is needed.
	 */
	public static function acquire( string $who ) {
		$path   = self::path();
		$handle = fopen( $path, 'c+' );
		if ( false === $handle ) {
			throw new RuntimeException( "Could not open the lock file {$path}" );
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			$holder = trim( (string) stream_get_contents( $handle ) );
			fwrite( STDERR, "Waiting for another timed run to finish ({$path}" . ( '' === $holder ? '' : ": {$holder}" ) . ")\n" );
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new RuntimeException( "Could not lock {$path}" );
			}
		}
		ftruncate( $handle, 0 );
		rewind( $handle );
		fwrite( $handle, sprintf( "pid %d since %s: %s\n", getmypid(), gmdate( 'Y-m-d\TH:i:s\Z' ), $who ) );
		fflush( $handle );
		return $handle;
	}
}
