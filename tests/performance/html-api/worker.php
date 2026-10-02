<?php
/**
 * HTML API benchmark worker.
 *
 * Loads one checkout's HTML API, then answers one JSON request per stdin line
 * with one JSON reply per stdout line. Nothing else is written to stdout.
 *
 * Usage: php [ini flags] worker.php --checkout <root>
 *
 * @package WordPress
 * @subpackage HTML-API
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/lib/class-benchmark-bootstrap.php';

/**
 * Writes one JSON line to stdout.
 *
 * @param array $reply Reply payload.
 */
function benchmark_worker_reply( array $reply ): void {
	fwrite( STDOUT, json_encode( $reply, JSON_UNESCAPED_SLASHES ) . "\n" );
	fflush( STDOUT );
}

/**
 * Writes a diagnostic line to stderr.
 *
 * @param string $message Message.
 */
function benchmark_worker_log( string $message ): void {
	fwrite( STDERR, "worker: {$message}\n" );
}

/**
 * Parses one document with the Tag Processor.
 *
 * @param string $html Document.
 * @return array{tokens:int, bailed:null}
 */
function benchmark_worker_parse_tag( string $html ): array {
	$tokens    = 0;
	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_token() ) {
		++$tokens;
	}
	return array(
		'tokens' => $tokens,
		'bailed' => null,
	);
}

/**
 * Parses one document with the HTML Processor.
 *
 * @param string $html Document.
 * @return array{tokens:int, bailed:string|null}
 */
function benchmark_worker_parse_html( string $html ): array {
	$tokens = 0;
	if ( method_exists( 'WP_HTML_Processor', 'create_full_parser' ) ) {
		$processor = WP_HTML_Processor::create_full_parser( $html );
	} else {
		$processor = WP_HTML_Processor::create_fragment( $html );
	}
	if ( null === $processor ) {
		return array(
			'tokens' => 0,
			'bailed' => 'processor could not be created',
		);
	}
	while ( $processor->next_token() ) {
		++$tokens;
	}

	$bailed = null;
	$error  = $processor->get_last_error();
	if ( null !== $error ) {
		$bailed = $error;
		if ( method_exists( $processor, 'get_unsupported_exception' ) ) {
			$exception = $processor->get_unsupported_exception();
			if ( null !== $exception ) {
				$bailed .= ': ' . $exception->getMessage();
			}
		}
	}

	return array(
		'tokens' => $tokens,
		'bailed' => $bailed,
	);
}

/**
 * Returns the parser function for a parser id.
 *
 * @throws InvalidArgumentException On an unknown parser id.
 *
 * @param string $parser 'tag' or 'html'.
 * @return callable
 */
function benchmark_worker_parser( string $parser ): callable {
	switch ( $parser ) {
		case 'tag':
			return 'benchmark_worker_parse_tag';
		case 'html':
			return 'benchmark_worker_parse_html';
	}
	throw new InvalidArgumentException( "Unknown parser: {$parser}" );
}

/**
 * Reads the --checkout argument.
 *
 * @param string[] $argv Arguments.
 * @return string|null Checkout root.
 */
function benchmark_worker_checkout_arg( array $argv ): ?string {
	$count = count( $argv );
	for ( $i = 1; $i < $count; $i++ ) {
		if ( '--checkout' === $argv[ $i ] && isset( $argv[ $i + 1 ] ) ) {
			return $argv[ $i + 1 ];
		}
		if ( 0 === strpos( $argv[ $i ], '--checkout=' ) ) {
			return substr( $argv[ $i ], strlen( '--checkout=' ) );
		}
	}
	return null;
}

/**
 * Returns the short git head of a checkout, or null.
 *
 * @param string $checkout Checkout root.
 * @return string|null
 */
function benchmark_worker_git_head( string $checkout ): ?string {
	$output = array();
	$status = 1;
	exec( 'git -C ' . escapeshellarg( $checkout ) . ' rev-parse --short HEAD 2>/dev/null', $output, $status );
	if ( 0 !== $status || empty( $output ) ) {
		return null;
	}
	return trim( $output[0] );
}

/**
 * Reports the JIT mode actually in effect, or null when the JIT is off.
 *
 * @param bool $opcache_enabled Whether opcache is enabled for this process.
 * @return string|null
 */
function benchmark_worker_jit_mode( bool $opcache_enabled ): ?string {
	if ( ! $opcache_enabled ) {
		return null;
	}
	$buffer = (string) ini_get( 'opcache.jit_buffer_size' );
	$jit    = (string) ini_get( 'opcache.jit' );
	if ( '' === $buffer || '0' === $buffer || '' === $jit || '0' === $jit || 'off' === $jit || 'disable' === $jit ) {
		return null;
	}
	return $jit;
}

$checkout = benchmark_worker_checkout_arg( $argv );
if ( null === $checkout ) {
	benchmark_worker_log( 'usage: php worker.php --checkout <root>' );
	exit( 2 );
}

try {
	Benchmark_Bootstrap::load( $checkout );
} catch ( Throwable $e ) {
	benchmark_worker_log( $e->getMessage() );
	exit( 2 );
}

$documents = array();

// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
while ( false !== ( $line = fgets( STDIN ) ) ) {
	$line = trim( $line );
	if ( '' === $line ) {
		continue;
	}

	$request = json_decode( $line, true );
	if ( ! is_array( $request ) || ! isset( $request['op'] ) ) {
		benchmark_worker_reply(
			array(
				'ok'    => false,
				'error' => 'malformed request',
			)
		);
		continue;
	}

	try {
		switch ( $request['op'] ) {
			case 'hello':
				$opcache_enabled = extension_loaded( 'Zend OPcache' ) && filter_var( ini_get( 'opcache.enable_cli' ), FILTER_VALIDATE_BOOLEAN ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
				benchmark_worker_reply(
					array(
						'ok'       => true,
						'php'      => PHP_VERSION,
						'opcache'  => $opcache_enabled,
						'jit'      => benchmark_worker_jit_mode( $opcache_enabled ),
						'checkout' => false === realpath( $checkout ) ? $checkout : realpath( $checkout ),
						'head'     => benchmark_worker_git_head( $checkout ),
					)
				);
				break;

			case 'load':
				if ( ! isset( $request['id'] ) ) {
					throw new InvalidArgumentException( 'load requires id' );
				}
				if ( isset( $request['path'] ) ) {
					$html = file_get_contents( $request['path'] );
					if ( false === $html ) {
						throw new RuntimeException( "could not read {$request['path']}" );
					}
				} elseif ( isset( $request['html'] ) ) {
					$html = (string) $request['html'];
				} else {
					throw new InvalidArgumentException( 'load requires path or html' );
				}
				$documents[ $request['id'] ] = $html;
				benchmark_worker_reply(
					array(
						'ok'    => true,
						'id'    => $request['id'],
						'bytes' => strlen( $html ),
					)
				);
				break;

			case 'calibrate':
			case 'run':
				if ( ! isset( $request['id'], $documents[ $request['id'] ] ) ) {
					throw new InvalidArgumentException( 'unknown document id' );
				}
				$html       = $documents[ $request['id'] ];
				$parse      = benchmark_worker_parser( (string) ( $request['parser'] ?? '' ) );
				$iterations = 'run' === $request['op'] ? max( 1, (int) ( $request['iterations'] ?? 1 ) ) : 1;

				gc_collect_cycles();
				if ( function_exists( 'memory_reset_peak_usage' ) ) {
					memory_reset_peak_usage();
				}
				$memory_before = memory_get_peak_usage( true );
				$alloc_before  = memory_get_peak_usage( false );

				$result = null;
				$start  = hrtime( true );
				for ( $i = 0; $i < $iterations; $i++ ) {
					$result = $parse( $html );
				}
				$ns = hrtime( true ) - $start;

				$memory_after = memory_get_peak_usage( true );
				$alloc_after  = memory_get_peak_usage( false );

				$reply = array(
					'ok'     => true,
					'ns'     => $ns,
					'tokens' => $result['tokens'],
					'bailed' => $result['bailed'],
				);
				if ( 'run' === $request['op'] ) {
					$reply['iterations']       = $iterations;
					$reply['peak_bytes']       = max( 0, $memory_after - $memory_before );
					$reply['peak_alloc_bytes'] = max( 0, $alloc_after - $alloc_before );
				}
				benchmark_worker_reply( $reply );
				break;

			case 'quit':
				exit( 0 );

			default:
				throw new InvalidArgumentException( "unknown op: {$request['op']}" );
		}
	} catch ( Throwable $e ) {
		benchmark_worker_reply(
			array(
				'ok'    => false,
				'error' => get_class( $e ) . ': ' . $e->getMessage(),
			)
		);
	}
}

exit( 0 );
