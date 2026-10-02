<?php
/**
 * Fetches the real-document corpus for the HTML API parsing benchmark.
 *
 * Reads corpus/manifest.json, downloads each document with curl into
 * corpus/real/<id>.html, and verifies the sha256 of the bytes as served
 * (after transfer decoding, no re-encoding).
 *
 * Usage:
 *   php fetch-corpus.php [--out <dir>] [--jobs <n>] [--include-optional] [--force]
 *                        [--update-manifest] [--check-parse] [--manifest <file>]
 *
 * Options:
 *   --out <dir>          Output directory. Default: corpus/real next to this script.
 *   --jobs <n>           Parallel curl processes. Default: 4.
 *   --include-optional   Also fetch entries marked "optional": true.
 *   --force              Refetch entries whose file is already present and correct.
 *   --update-manifest    Refetch everything and rewrite bytes, sha256, fetched
 *                        (and bails, utf8 via --check-parse) in the manifest.
 *   --check-parse        Run WP_HTML_Processor::create_full_parser() over each
 *                        document from this checkout and report whether it bails.
 *                        Implied by --update-manifest.
 *   --manifest <file>    Manifest path. Default: corpus/manifest.json next to this script.
 *
 * A sha256 mismatch on a "pinned": true entry is an error: the file is removed
 * and the exit code is non-zero. A mismatch on a "pinned": false entry is a
 * warning: the file is kept and the new hash is printed.
 *
 * @package WordPress
 * @subpackage Performance
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script runs from the command line only.\n" );
	exit( 1 );
}

const FETCH_CORPUS_USER_AGENT = 'wordpress-develop html-api benchmark corpus fetch (+https://github.com/WordPress/wordpress-develop)';

/**
 * Parses command-line options of the forms --name, --name=value and --name value.
 *
 * @param string[] $argv  Raw arguments.
 * @param string[] $flags Option names that take no value.
 * @return array<string, string|bool> Options.
 */
function fetch_corpus_parse_args( array $argv, array $flags ): array {
	$options = array();
	$count   = count( $argv );
	for ( $i = 1; $i < $count; $i++ ) {
		$arg = $argv[ $i ];
		if ( 0 !== strpos( $arg, '--' ) ) {
			fwrite( STDERR, "Unexpected argument: {$arg}\n" );
			exit( 2 );
		}
		$arg = substr( $arg, 2 );
		if ( false !== strpos( $arg, '=' ) ) {
			list( $name, $value ) = explode( '=', $arg, 2 );
			$options[ $name ]     = $value;
			continue;
		}
		if ( in_array( $arg, $flags, true ) ) {
			$options[ $arg ] = true;
			continue;
		}
		if ( $i + 1 >= $count ) {
			fwrite( STDERR, "Option --{$arg} needs a value.\n" );
			exit( 2 );
		}
		$options[ $arg ] = $argv[ ++$i ];
	}
	return $options;
}

/**
 * Finds the checkout root by walking up from this script to the directory
 * containing src/wp-includes/html-api.
 *
 * @return string|null Absolute path or null when not found.
 */
function fetch_corpus_find_checkout_root(): ?string {
	$dir = __DIR__;
	while ( true ) {
		if ( is_dir( $dir . '/src/wp-includes/html-api' ) ) {
			return $dir;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			return null;
		}
		$dir = $parent;
	}
}

/**
 * Loads the HTML API from a checkout root with stubs for the WordPress
 * functions it calls.
 *
 * @param string $root Checkout root.
 */
function fetch_corpus_load_html_api( string $root ): void {
	if ( ! function_exists( '__' ) ) {
		/**
		 * Identity translation stub.
		 *
		 * @param string $text Text.
		 * @return string Same text.
		 */
		function __( $text ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore
			return $text;
		}
	}
	if ( ! function_exists( '_doing_it_wrong' ) ) {
		/**
		 * No-op stub.
		 *
		 * @param string $function_name Function.
		 * @param string $message       Message.
		 * @param string $version       Version.
		 */
		function _doing_it_wrong( $function_name, $message, $version ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	}
	if ( ! function_exists( '_deprecated_argument' ) ) {
		/**
		 * No-op stub.
		 *
		 * @param string $function_name Function.
		 * @param string $version       Version.
		 * @param string $message       Message.
		 */
		function _deprecated_argument( $function_name, $version, $message = '' ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	}
	if ( ! function_exists( 'wp_trigger_error' ) ) {
		/**
		 * No-op stub.
		 *
		 * @param string $function_name Function.
		 * @param string $message       Message.
		 * @param int    $error_level   Level.
		 */
		function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	}
	if ( ! function_exists( 'esc_url' ) ) {
		/**
		 * Identity stub.
		 *
		 * @param string $url       URL.
		 * @param mixed  $protocols Unused.
		 * @param string $_context  Unused.
		 * @return string Same URL.
		 */
		function esc_url( $url, $protocols = null, $_context = 'display' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return (string) $url;
		}
	}
	if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
		/**
		 * The usual list of URI attributes.
		 *
		 * @return string[] Attribute names.
		 */
		function wp_kses_uri_attributes() {
			return array( 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns' );
		}
	}

	$required = array(
		'src/wp-includes/compat.php',
		'src/wp-includes/class-wp-token-map.php',
		'src/wp-includes/html-api/html5-named-character-references.php',
		'src/wp-includes/html-api/class-wp-html-attribute-token.php',
		'src/wp-includes/html-api/class-wp-html-span.php',
		'src/wp-includes/html-api/class-wp-html-doctype-info.php',
		'src/wp-includes/html-api/class-wp-html-text-replacement.php',
		'src/wp-includes/html-api/class-wp-html-decoder.php',
		'src/wp-includes/html-api/class-wp-html-tag-processor.php',
		'src/wp-includes/html-api/class-wp-html-unsupported-exception.php',
		'src/wp-includes/html-api/class-wp-html-active-formatting-elements.php',
		'src/wp-includes/html-api/class-wp-html-open-elements.php',
		'src/wp-includes/html-api/class-wp-html-token.php',
		'src/wp-includes/html-api/class-wp-html-stack-event.php',
		'src/wp-includes/html-api/class-wp-html-processor-state.php',
		'src/wp-includes/html-api/class-wp-html-processor.php',
	);
	$optional = array(
		'src/wp-includes/compat-utf8.php',
		'src/wp-includes/utf8.php',
	);

	require_once $root . '/' . array_shift( $required );
	foreach ( $optional as $file ) {
		if ( file_exists( $root . '/' . $file ) ) {
			require_once $root . '/' . $file;
		}
	}
	if ( ! function_exists( 'wp_scrub_utf8' ) ) {
		/**
		 * Identity stub for checkouts without utf8.php.
		 *
		 * @param string $text Text.
		 * @return string Same text.
		 */
		function wp_scrub_utf8( $text ) {
			return $text;
		}
	}
	foreach ( $required as $file ) {
		$path = $root . '/' . $file;
		if ( ! file_exists( $path ) ) {
			fwrite( STDERR, "Missing HTML API file in checkout: {$path}\n" );
			exit( 1 );
		}
		require_once $path;
	}
}

/**
 * Runs the HTML Processor over a document and reports whether it bailed.
 *
 * @param string $html Document.
 * @return string|null Bail reason or null when the parse reached the end.
 */
function fetch_corpus_check_parse( string $html ): ?string {
	if ( method_exists( 'WP_HTML_Processor', 'create_full_parser' ) ) {
		$processor = WP_HTML_Processor::create_full_parser( $html );
	} else {
		$processor = WP_HTML_Processor::create_fragment( $html );
	}
	if ( null === $processor ) {
		return 'create_full_parser() returned null';
	}
	while ( $processor->next_token() ) {
		// Parse to the end.
	}
	$error = $processor->get_last_error();
	if ( null === $error ) {
		return null;
	}
	$exception = $processor->get_unsupported_exception();
	if ( null !== $exception ) {
		return $error . ': ' . $exception->getMessage();
	}
	return $error;
}

/**
 * Starts a curl process for one manifest entry.
 *
 * @param array  $doc      Manifest entry.
 * @param string $tmp_path Download target.
 * @return array{proc: resource, pipes: array, out: string, err: string}
 */
function fetch_corpus_start_curl( array $doc, string $tmp_path ): array {
	$command = array(
		'curl',
		'--silent',
		'--show-error',
		'--compressed',
		'--location',
		'--max-time',
		'300',
		'--user-agent',
		FETCH_CORPUS_USER_AGENT,
		'--output',
		$tmp_path,
		'--write-out',
		'%{http_code}\t%{url_effective}',
		'--',
		$doc['url'],
	);
	$spec    = array(
		0 => array( 'file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$pipes   = array();
	$proc    = proc_open( $command, $spec, $pipes );
	if ( ! is_resource( $proc ) ) {
		fwrite( STDERR, "Could not start curl for {$doc['id']}.\n" );
		exit( 1 );
	}
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	return array(
		'proc'  => $proc,
		'pipes' => $pipes,
		'out'   => '',
		'err'   => '',
	);
}

/**
 * Fetches a set of manifest entries in parallel.
 *
 * @param array  $docs Manifest entries keyed by id.
 * @param string $out  Output directory.
 * @param int    $jobs Parallel processes.
 * @return array<string, array{code: int, final_url: string, error: string|null, path: string}> Results keyed by id.
 */
function fetch_corpus_fetch_all( array $docs, string $out, int $jobs ): array {
	$queue   = array_values( $docs );
	$running = array();
	$results = array();

	while ( count( $queue ) > 0 || count( $running ) > 0 ) {
		while ( count( $running ) < $jobs && count( $queue ) > 0 ) {
			$doc                   = array_shift( $queue );
			$tmp                   = $out . '/' . $doc['id'] . '.html.part';
			$job                   = fetch_corpus_start_curl( $doc, $tmp );
			$job['doc']            = $doc;
			$job['tmp']            = $tmp;
			$running[ $doc['id'] ] = $job;
			fwrite( STDERR, "fetching {$doc['id']}: {$doc['url']}\n" );
		}

		foreach ( $running as $id => &$job ) {
			$job['out'] .= (string) stream_get_contents( $job['pipes'][1] );
			$job['err'] .= (string) stream_get_contents( $job['pipes'][2] );
			$status      = proc_get_status( $job['proc'] );
			if ( $status['running'] ) {
				continue;
			}
			$job['out'] .= (string) stream_get_contents( $job['pipes'][1] );
			$job['err'] .= (string) stream_get_contents( $job['pipes'][2] );
			fclose( $job['pipes'][1] );
			fclose( $job['pipes'][2] );
			proc_close( $job['proc'] );

			$parts     = explode( "\t", trim( $job['out'] ), 2 );
			$code      = (int) $parts[0];
			$final_url = $parts[1] ?? '';
			$error     = null;
			if ( 0 !== $status['exitcode'] ) {
				$error = 'curl exit ' . $status['exitcode'] . ': ' . trim( $job['err'] );
			} elseif ( 200 !== $code ) {
				$error = "HTTP {$code}";
			}
			$results[ $id ] = array(
				'code'      => $code,
				'final_url' => $final_url,
				'error'     => $error,
				'path'      => $job['tmp'],
			);
			unset( $running[ $id ] );
		}
		unset( $job );

		if ( count( $running ) > 0 ) {
			usleep( 50000 );
		}
	}

	return $results;
}

$options = fetch_corpus_parse_args( $argv, array( 'include-optional', 'force', 'update-manifest', 'check-parse', 'help' ) );

if ( isset( $options['help'] ) ) {
	fwrite( STDOUT, "Usage: php fetch-corpus.php [--out <dir>] [--jobs <n>] [--include-optional] [--force] [--update-manifest] [--check-parse] [--manifest <file>]\n" );
	exit( 0 );
}

$manifest_path    = (string) ( $options['manifest'] ?? __DIR__ . '/corpus/manifest.json' );
$out              = rtrim( (string) ( $options['out'] ?? __DIR__ . '/corpus/real' ), '/' );
$jobs             = max( 1, (int) ( $options['jobs'] ?? 4 ) );
$include_optional = isset( $options['include-optional'] );
$force            = isset( $options['force'] );
$update_manifest  = isset( $options['update-manifest'] );
$check_parse      = $update_manifest || isset( $options['check-parse'] );
$today            = gmdate( 'Y-m-d' );

$manifest_json = file_get_contents( $manifest_path );
if ( false === $manifest_json ) {
	fwrite( STDERR, "Cannot read manifest: {$manifest_path}\n" );
	exit( 1 );
}
$manifest = json_decode( $manifest_json, true );
if ( ! is_array( $manifest ) || ! isset( $manifest['documents'] ) || ! is_array( $manifest['documents'] ) ) {
	fwrite( STDERR, "Manifest is not valid JSON with a \"documents\" array: {$manifest_path}\n" );
	exit( 1 );
}

if ( ! is_dir( $out ) && ! mkdir( $out, 0777, true ) && ! is_dir( $out ) ) {
	fwrite( STDERR, "Cannot create output directory: {$out}\n" );
	exit( 1 );
}

$checkout_root = null;
if ( $check_parse ) {
	$checkout_root = fetch_corpus_find_checkout_root();
	if ( null === $checkout_root ) {
		fwrite( STDERR, "Cannot find a checkout root (a directory containing src/wp-includes/html-api) above {$manifest_path}.\n" );
		exit( 1 );
	}
	ini_set( 'memory_limit', '2G' );
	fetch_corpus_load_html_api( $checkout_root );
}

/*
 * Decide what to fetch.
 */
$rows      = array();
$to_fetch  = array();
$exit_code = 0;

foreach ( $manifest['documents'] as $index => $doc ) {
	$id   = (string) $doc['id'];
	$path = $out . '/' . $id . '.html';

	if ( ! empty( $doc['optional'] ) && ! $include_optional ) {
		$rows[ $id ] = array(
			'bytes'  => $doc['bytes'] ?? null,
			'status' => 'skipped (optional)',
		);
		continue;
	}

	if ( ! $force && ! $update_manifest && file_exists( $path ) && ! empty( $doc['sha256'] ) ) {
		if ( hash_file( 'sha256', $path ) === $doc['sha256'] ) {
			$rows[ $id ] = array(
				'bytes'  => filesize( $path ),
				'status' => 'present',
			);
			continue;
		}
	}

	$to_fetch[ $id ] = $doc;
}

$results = fetch_corpus_fetch_all( $to_fetch, $out, $jobs );

/*
 * Verify each fetched file against the manifest and, with --update-manifest,
 * rewrite the entry.
 */
foreach ( $manifest['documents'] as $index => &$doc ) {
	$id = (string) $doc['id'];
	if ( ! isset( $results[ $id ] ) ) {
		continue;
	}
	$result = $results[ $id ];
	$path   = $out . '/' . $id . '.html';
	$pinned = ! empty( $doc['pinned'] );

	if ( null !== $result['error'] ) {
		if ( file_exists( $result['path'] ) ) {
			unlink( $result['path'] );
		}
		$rows[ $id ] = array(
			'bytes'  => null,
			'status' => 'error: ' . $result['error'],
		);
		$exit_code   = 1;
		continue;
	}

	$bytes  = filesize( $result['path'] );
	$sha256 = hash_file( 'sha256', $result['path'] );

	if ( 0 === $bytes ) {
		unlink( $result['path'] );
		$rows[ $id ] = array(
			'bytes'  => 0,
			'status' => 'error: empty response',
		);
		$exit_code   = 1;
		continue;
	}

	$expected = $doc['sha256'] ?? null;
	$matches  = empty( $expected ) || $expected === $sha256;

	if ( $update_manifest ) {
		if ( $pinned && ! $matches ) {
			fwrite( STDERR, "warning: pinned {$id} changed since {$doc['fetched']}: {$expected} -> {$sha256}\n" );
		}
		$doc['bytes']  = $bytes;
		$doc['sha256'] = $sha256;
		if ( ! $pinned || ! $matches || empty( $doc['fetched'] ) ) {
			$doc['fetched'] = $today;
		}
		if ( '' !== $result['final_url'] && $result['final_url'] !== $doc['url'] ) {
			$doc['final_url'] = $result['final_url'];
		} else {
			unset( $doc['final_url'] );
		}
		rename( $result['path'], $path );
		$rows[ $id ] = array(
			'bytes'  => $bytes,
			'status' => $matches ? 'fetched' : 'fetched (hash updated)',
		);
		continue;
	}

	if ( $matches ) {
		rename( $result['path'], $path );
		$rows[ $id ] = array(
			'bytes'  => $bytes,
			'status' => 'fetched',
		);
		continue;
	}

	if ( $pinned ) {
		unlink( $result['path'] );
		fwrite( STDERR, "error: pinned {$id} hash mismatch: expected {$expected}, got {$sha256}; file removed\n" );
		$rows[ $id ] = array(
			'bytes'  => $bytes,
			'status' => 'error: pinned hash mismatch',
		);
		$exit_code   = 1;
		continue;
	}

	rename( $result['path'], $path );
	fwrite( STDERR, "warning: {$id} drifted since {$doc['fetched']}: manifest {$expected}, fetched {$sha256}; file kept\n" );
	$rows[ $id ] = array(
		'bytes'  => $bytes,
		'status' => 'fetched (drifted since ' . $doc['fetched'] . ')',
	);
}
unset( $doc );

/*
 * Parse check.
 */
if ( $check_parse ) {
	foreach ( $manifest['documents'] as &$doc ) {
		$id   = (string) $doc['id'];
		$path = $out . '/' . $id . '.html';
		if ( ! file_exists( $path ) || 0 === strpos( $rows[ $id ]['status'] ?? '', 'error' ) || 0 === strpos( $rows[ $id ]['status'] ?? '', 'skipped' ) ) {
			continue;
		}
		$html                 = (string) file_get_contents( $path );
		$utf8                 = 1 === preg_match( '//u', $html );
		$start                = hrtime( true );
		$bails                = fetch_corpus_check_parse( $html );
		$ms                   = (int) round( ( hrtime( true ) - $start ) / 1e6 );
		$rows[ $id ]['utf8']  = $utf8;
		$rows[ $id ]['bails'] = $bails;
		fwrite( STDERR, sprintf( "parsed %s in %d ms: %s\n", $id, $ms, null === $bails ? 'ok' : 'bails: ' . $bails ) );
		if ( $update_manifest ) {
			$doc['utf8']  = $utf8;
			$doc['bails'] = $bails;
		}
	}
	unset( $doc );
}

if ( $update_manifest ) {
	$manifest['generated'] = $today;
	$json                  = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json || false === file_put_contents( $manifest_path, $json . "\n" ) ) {
		fwrite( STDERR, "Cannot write manifest: {$manifest_path}\n" );
		exit( 1 );
	}
}

/*
 * Summary table.
 */
$total_bytes = 0;
$id_width    = max( 2, ...array_map( 'strlen', array_keys( $rows ) ) );
fwrite( STDOUT, sprintf( "%-{$id_width}s  %10s  %s\n", 'id', 'bytes', $check_parse ? 'status  (utf8, bails)' : 'status' ) );
foreach ( $rows as $id => $row ) {
	$bytes = $row['bytes'];
	if ( is_int( $bytes ) && 0 !== strpos( $row['status'], 'skipped' ) && 0 !== strpos( $row['status'], 'error' ) ) {
		$total_bytes += $bytes;
	}
	$extra = '';
	if ( $check_parse && array_key_exists( 'bails', $row ) ) {
		$extra = sprintf( '  (utf8: %s, bails: %s)', $row['utf8'] ? 'yes' : 'no', null === $row['bails'] ? 'no' : $row['bails'] );
	}
	fwrite( STDOUT, sprintf( "%-{$id_width}s  %10s  %s%s\n", $id, null === $bytes ? '-' : number_format( (int) $bytes ), $row['status'], $extra ) );
}
fwrite( STDOUT, sprintf( "%-{$id_width}s  %10s  on disk\n", 'total', number_format( $total_bytes ) ) );

exit( $exit_code );
