<?php
namespace CssSelectorFuzz;

/**
 * Adapter for the lexbor differential harness ( lexbor/harness.c ): a
 * persistent child process fed one {html, selector} case per line, which
 * answers with lexbor's element tree rows and match list.
 *
 * lexbor is the THIRD, independent matching opinion ( alongside the WP
 * implementation under test and the fuzzer's ReferenceMatcher ). Verdicts:
 *
 *   reference != lexbor             => fuzzer-oracle problem ( investigate
 *                                      the fuzzer, 'lexbor-divergence' ).
 *   reference == lexbor != WP       => high-confidence WP finding ( the
 *                                      regular match-mismatch-html failure
 *                                      with no accompanying divergence ).
 *
 * Known bug compensated for: lexbor #368 — class and #id selectors match
 * ASCII case-insensitively even in no-quirks mode ( attribute selectors
 * like [id=x] are correctly case-sensitive ). Detected by probe at startup;
 * when present, lexbor is compared against the reference matcher run with
 * quirks-style class/ID folding. Quirks documents are compared only when
 * the probe also confirms class and #id selectors fold in quirks mode.
 */
class LexborOracle {

	const READ_TIMEOUT_SECONDS = 5;

	/** @var resource|null */
	private static $process = null;
	/** @var array|null */
	private static $pipes = null;
	/** @var bool|null */
	private static $available = null;
	/** @var bool */
	private static $issue368 = false;
	/** @var bool */
	private static $quirks_class_id_reliable = false;

	public static function harness_path(): string {
		return dirname( __DIR__ ) . '/lexbor/harness';
	}

	/** Whether the harness is built, starts, and answered the probes. */
	public static function available(): bool {
		if ( null !== self::$available ) {
			return self::$available;
		}

		self::$available = false;
		if ( ! is_executable( self::harness_path() ) || ! self::start() ) {
			return false;
		}

		// Probe: sanity plus class/#id case-sensitivity behavior.
		$sane = self::query( '<!DOCTYPE html><div class="a" data-fid="x"></div>', 'div.a' );
		if ( null === $sane || array( 'x' ) !== $sane['matches'] ) {
			self::stop();
			return false;
		}

		$no_quirks_class = self::query( '<!DOCTYPE html><div class="a" data-fid="x"></div>', '.A' );
		$no_quirks_id    = self::query( '<!DOCTYPE html><div id="a" data-fid="x"></div>', '#A' );
		$quirks_class    = self::query( '<div class="a" data-fid="x"></div>', '.A' );
		$quirks_id       = self::query( '<div id="a" data-fid="x"></div>', '#A' );
		foreach ( array( $no_quirks_class, $no_quirks_id, $quirks_class, $quirks_id ) as $probe ) {
			if ( null === $probe || null !== $probe['error'] ) {
				self::stop();
				return false;
			}
		}

		self::$issue368 = array( 'x' ) === $no_quirks_class['matches']
			|| array( 'x' ) === $no_quirks_id['matches'];
		self::$quirks_class_id_reliable = ! self::$issue368
			&& array() === $no_quirks_class['matches']
			&& array() === $no_quirks_id['matches']
			&& array( 'x' ) === $quirks_class['matches']
			&& array( 'x' ) === $quirks_id['matches'];
		self::$available = true;
		return true;
	}

	/** Whether the built lexbor exhibits issue #368 ( class/ID case folding ). */
	public static function has_issue_368(): bool {
		return self::$issue368;
	}

	/** Whether lexbor can be trusted on quirks class/#id case folding. */
	public static function quirks_class_id_reliable(): bool {
		return self::$quirks_class_id_reliable;
	}

	/**
	 * Runs one case through lexbor.
	 *
	 * @return array{
	 *     rows: array<int, array{tag: string, fid: string, ancestorTags: string[]}>,
	 *     matches: string[],
	 *     error: string|null,
	 * }|null Null when the harness is unavailable or misbehaved ( the
	 *        harness is stopped; the caller should skip the differential ).
	 */
	public static function query( string $html, string $selector ): ?array {
		if ( null === self::$process && ! self::start() ) {
			return null;
		}

		$line    = base64_encode( $html ) . "\t" . base64_encode( $selector ) . "\n";
		$written = fwrite( self::$pipes[0], $line );
		fflush( self::$pipes[0] );
		if ( strlen( $line ) !== $written ) {
			self::stop();
			self::$available = false;
			return null;
		}

		$rows    = array();
		$matches = array();
		$error   = null;

		while ( true ) {
			$response = self::read_line();
			if ( null === $response ) {
				self::stop();
				self::$available = false;
				return null;
			}
			if ( 'D' === $response ) {
				break;
			}

			$parts = explode( "\t", $response );
			switch ( $parts[0] ) {
				case 'R':
					$rows[] = array(
						'tag'          => $parts[1] ?? '',
						'fid'          => $parts[2] ?? '',
						'ancestorTags' => '' === ( $parts[3] ?? '' ) ? array() : explode( ',', $parts[3] ),
					);
					break;
				case 'M':
					$matches[] = $parts[1] ?? '';
					break;
				case 'X':
					$error = $parts[1] ?? 'unknown';
					break;
			}
		}

		return array(
			'rows'    => $rows,
			'matches' => $matches,
			'error'   => $error,
		);
	}

	private static function start(): bool {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'file', '/dev/null', 'w' ),
		);

		$process = proc_open( array( self::harness_path() ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return false;
		}

		self::$process = $process;
		self::$pipes   = $pipes;
		stream_set_blocking( $pipes[1], false );
		return true;
	}

	private static function stop(): void {
		if ( null === self::$process ) {
			return;
		}
		@fclose( self::$pipes[0] );
		@fclose( self::$pipes[1] );
		@proc_terminate( self::$process, 9 );
		@proc_close( self::$process );
		self::$process = null;
		self::$pipes   = null;
	}

	/** Reads one newline-terminated line with a timeout; null on failure. */
	private static function read_line(): ?string {
		$line     = '';
		$deadline = microtime( true ) + self::READ_TIMEOUT_SECONDS;

		while ( true ) {
			$read   = array( self::$pipes[1] );
			$write  = null;
			$except = null;
			$left   = $deadline - microtime( true );
			if ( $left <= 0 ) {
				return null;
			}
			$ready = stream_select( $read, $write, $except, 0, (int) ( $left * 1e6 ) );
			if ( false === $ready || 0 === $ready ) {
				return null;
			}
			$chunk = fgets( self::$pipes[1] );
			if ( false === $chunk ) {
				return null;
			}
			$line .= $chunk;
			if ( str_ends_with( $line, "\n" ) ) {
				return substr( $line, 0, -1 );
			}
		}
	}
}
