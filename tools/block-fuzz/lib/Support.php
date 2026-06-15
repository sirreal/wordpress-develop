<?php
/**
 * Shared helpers for the block fuzzer.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

namespace BlockFuzz;

/**
 * Returns the repository root.
 *
 * @return string Repository root.
 */
function repo_root() {
	return dirname( __DIR__, 3 );
}

/**
 * Parses long CLI options.
 *
 * @param array $argv CLI arguments.
 * @return array Parsed options.
 */
function parse_cli_options( $argv ) {
	$options = array( '_' => array() );
	$count   = count( $argv );

	for ( $i = 1; $i < $count; ++$i ) {
		$arg = $argv[ $i ];
		if ( 0 !== strpos( $arg, '--' ) ) {
			$options['_'][] = $arg;
			continue;
		}

		$arg = substr( $arg, 2 );
		if ( false !== strpos( $arg, '=' ) ) {
			list( $name, $value ) = explode( '=', $arg, 2 );
			$options[ $name ]     = $value;
			continue;
		}

		if ( $i + 1 < $count && 0 !== strpos( $argv[ $i + 1 ], '--' ) ) {
			$options[ $arg ] = $argv[ ++$i ];
		} else {
			$options[ $arg ] = true;
		}
	}

	return $options;
}

/**
 * Reads a string option.
 *
 * @param array       $options  Parsed options.
 * @param string      $name     Option name.
 * @param string|null $fallback Fallback value.
 * @return string|null Option value.
 */
function option_string( $options, $name, $fallback = null ) {
	return array_key_exists( $name, $options ) && true !== $options[ $name ] ? (string) $options[ $name ] : $fallback;
}

/**
 * Reads a boolean option.
 *
 * @param array  $options  Parsed options.
 * @param string $name     Option name.
 * @param bool   $fallback Fallback value.
 * @return bool Option value.
 */
function option_bool( $options, $name, $fallback = false ) {
	if ( ! array_key_exists( $name, $options ) ) {
		return $fallback;
	}

	$value = $options[ $name ];
	if ( true === $value ) {
		return true;
	}

	return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Reads an integer option.
 *
 * @param array  $options  Parsed options.
 * @param string $name     Option name.
 * @param int    $fallback Fallback value.
 * @return int Option value.
 */
function option_int( $options, $name, $fallback ) {
	if ( ! array_key_exists( $name, $options ) || true === $options[ $name ] ) {
		return $fallback;
	}

	$value = filter_var( $options[ $name ], FILTER_VALIDATE_INT );
	if ( false === $value ) {
		throw new \InvalidArgumentException( "Expected --{$name} to be an integer." );
	}

	return (int) $value;
}

/**
 * Creates a directory if needed.
 *
 * @param string $path Directory path.
 */
function ensure_dir( $path ) {
	if ( ! is_dir( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
		throw new \RuntimeException( "Could not create directory: {$path}" );
	}
}

/**
 * Removes a directory tree if it exists.
 *
 * @param string $path Directory path.
 */
function remove_dir_recursive( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$entries = scandir( $path );
	if ( false === $entries ) {
		throw new \RuntimeException( "Could not list directory: {$path}" );
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$child = $path . DIRECTORY_SEPARATOR . $entry;
		if ( is_dir( $child ) && ! is_link( $child ) ) {
			remove_dir_recursive( $child );
			continue;
		}

		if ( ! unlink( $child ) && file_exists( $child ) ) {
			throw new \RuntimeException( "Could not remove file: {$child}" );
		}
	}

	if ( ! rmdir( $path ) && is_dir( $path ) ) {
		throw new \RuntimeException( "Could not remove directory: {$path}" );
	}
}

/**
 * Encodes JSON with stable flags.
 *
 * @param mixed $value Value to encode.
 * @return string JSON.
 */
function json_encode_safe( $value ) {
	$json = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $json ) {
		throw new \RuntimeException( 'JSON encode failed: ' . json_last_error_msg() );
	}

	return $json;
}

/**
 * Writes a JSON file.
 *
 * @param string $path  File path.
 * @param mixed  $value Value to write.
 */
function write_json_file( $path, $value ) {
	ensure_dir( dirname( $path ) );
	file_put_contents( $path, json_encode_safe( $value ) . "\n" );
}

/**
 * Reads a JSON file.
 *
 * @param string $path File path.
 * @return mixed Parsed JSON.
 */
function read_json_file( $path ) {
	$text = file_get_contents( $path );
	if ( false === $text ) {
		throw new \RuntimeException( "Could not read {$path}." );
	}

	$value = json_decode( $text, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		throw new \RuntimeException( "Could not parse {$path}: " . json_last_error_msg() );
	}

	return $value;
}

/**
 * Appends an NDJSON record.
 *
 * @param string $path  File path.
 * @param mixed  $value Record.
 */
function append_ndjson( $path, $value ) {
	ensure_dir( dirname( $path ) );
	file_put_contents( $path, json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n", FILE_APPEND );
}

/**
 * Returns a compact UTC timestamp.
 *
 * @return string Timestamp.
 */
function timestamp() {
	$now      = microtime( true );
	$seconds  = (int) $now;
	$fraction = max( 0, (int) round( ( $now - $seconds ) * 1000000 ) );
	if ( 1000000 === $fraction ) {
		++$seconds;
		$fraction = 0;
	}

	return gmdate( 'Ymd\THis', $seconds ) . sprintf( '%06dZ', $fraction );
}

/**
 * Returns a printable preview for bytes.
 *
 * @param string $bytes Bytes.
 * @param int    $limit Maximum bytes to preview.
 * @return string Preview.
 */
function preview_bytes( $bytes, $limit = 240 ) {
	$slice = substr( $bytes, 0, $limit );
	$shown = json_encode( $slice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $shown ) {
		$shown = base64_encode( $slice );
	}

	return strlen( $bytes ) > $limit ? $shown . '...' : $shown;
}

/**
 * Returns a stable signature for a failure.
 *
 * @param array $failure Failure record.
 * @return array Signature record.
 */
function failure_signature( $failure ) {
	$basis = array(
		'oracle' => $failure['oracle'] ?? null,
		'reason' => $failure['reason'] ?? null,
		'detail' => $failure['detail'] ?? null,
	);

	return array(
		'hash'  => substr( hash( 'sha256', json_encode( $basis ) ), 0, 16 ),
		'basis' => $basis,
	);
}
