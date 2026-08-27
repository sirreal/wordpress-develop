<?php
namespace CssSelectorFuzz;

function repo_root(): string {
	return dirname( __DIR__, 3 );
}

function parse_cli_options( array $argv ): array {
	$options = array( '_' => array() );
	$count   = count( $argv );
	for ( $i = 1; $i < $count; $i++ ) {
		$arg = $argv[ $i ];
		if ( 0 === strpos( $arg, '--' ) ) {
			$name = substr( $arg, 2 );
			if ( false !== strpos( $name, '=' ) ) {
				list( $name, $value ) = explode( '=', $name, 2 );
				$options[ $name ]     = $value;
			} elseif ( $i + 1 < $count && 0 !== strpos( $argv[ $i + 1 ], '--' ) ) {
				$options[ $name ] = $argv[ ++$i ];
			} else {
				$options[ $name ] = true;
			}
		} else {
			$options['_'][] = $arg;
		}
	}
	return $options;
}

function option_string( array $options, string $name, ?string $default = null ): ?string {
	if ( ! array_key_exists( $name, $options ) || true === $options[ $name ] ) {
		return $default;
	}
	return (string) $options[ $name ];
}

function option_int( array $options, string $name, int $default ): int {
	$value = option_string( $options, $name, null );
	return null === $value ? $default : (int) $value;
}

function option_float( array $options, string $name, float $default ): float {
	$value = option_string( $options, $name, null );
	return null === $value ? $default : (float) $value;
}

function option_bool( array $options, string $name, bool $default ): bool {
	if ( ! array_key_exists( $name, $options ) ) {
		return $default;
	}
	$value = $options[ $name ];
	if ( true === $value ) {
		return true;
	}
	return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
}

function ensure_dir( string $dir ): void {
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new \RuntimeException( "Could not create directory: {$dir}" );
	}
}

function json_encode_safe( $value ): string {
	$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $encoded ) {
		$encoded = json_encode( array( 'jsonError' => json_last_error_msg() ) );
	}
	return $encoded;
}

function write_json_file( string $path, $value ): void {
	file_put_contents( $path, json_encode_safe( $value ) . "\n" );
}

function read_json_file( string $path ): ?array {
	if ( ! is_file( $path ) ) {
		return null;
	}
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : null;
}

function append_ndjson( string $path, array $value ): void {
	file_put_contents( $path, json_encode_safe( $value ) . "\n", FILE_APPEND | LOCK_EX );
}

function timestamp(): string {
	return gmdate( 'Ymd-His' );
}

/**
 * Renders bytes for human inspection: printable ASCII passes through,
 * everything else becomes \xHH.
 */
function printable_bytes( string $bytes, int $max_length = 4096 ): string {
	$out       = '';
	$truncated = strlen( $bytes ) > $max_length;
	$bytes     = substr( $bytes, 0, $max_length );
	for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
		$c = $bytes[ $i ];
		$o = ord( $c );
		if ( $o >= 0x20 && $o <= 0x7E ) {
			$out .= '\\' === $c ? '\\\\' : $c;
		} else {
			$out .= sprintf( '\\x%02X', $o );
		}
	}
	return $out . ( $truncated ? '…(truncated)' : '' );
}

function git_metadata(): array {
	$head   = trim( (string) shell_exec( 'git -C ' . escapeshellarg( repo_root() ) . ' rev-parse HEAD 2>/dev/null' ) );
	$branch = trim( (string) shell_exec( 'git -C ' . escapeshellarg( repo_root() ) . ' rev-parse --abbrev-ref HEAD 2>/dev/null' ) );
	return array(
		'head'   => '' !== $head ? $head : null,
		'branch' => '' !== $branch ? $branch : null,
	);
}

/** Whether every string anywhere in a nested array is valid UTF-8. */
function ast_strings_are_utf8( $node ): bool {
	if ( is_string( $node ) ) {
		return (bool) preg_match( '//u', $node );
	}
	if ( is_array( $node ) ) {
		foreach ( $node as $child ) {
			if ( ! ast_strings_are_utf8( $child ) ) {
				return false;
			}
		}
	}
	return true;
}

function ascii_strtolower( string $input ): string {
	return strtr( $input, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
}

function ascii_strtoupper( string $input ): string {
	return strtr( $input, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' );
}

/** Flips the case of each ASCII letter independently with 50% probability. */
function str_shuffle_case( string $input, Prng $prng ): string {
	$out = '';
	for ( $i = 0; $i < strlen( $input ); $i++ ) {
		$byte = $input[ $i ];
		if ( $prng->chance( 50 ) ) {
			$byte = ctype_lower( $byte ) ? ascii_strtoupper( $byte ) : ascii_strtolower( $byte );
		}
		$out .= $byte;
	}
	return $out;
}

/**
 * Splits a valid UTF-8 string into codepoints.
 *
 * @return array<int, array{0: string, 1: int}> Pairs of ( utf8 bytes, codepoint value ).
 */
function utf8_codepoints( string $input ): array {
	$out = array();
	$len = strlen( $input );
	$i   = 0;
	while ( $i < $len ) {
		$byte = ord( $input[ $i ] );
		if ( $byte < 0x80 ) {
			$size = 1;
			$cp   = $byte;
		} elseif ( 0xC0 === ( $byte & 0xE0 ) ) {
			$size = 2;
			$cp   = $byte & 0x1F;
		} elseif ( 0xE0 === ( $byte & 0xF0 ) ) {
			$size = 3;
			$cp   = $byte & 0x0F;
		} else {
			$size = 4;
			$cp   = $byte & 0x07;
		}
		$size = min( $size, $len - $i );
		for ( $j = 1; $j < $size; $j++ ) {
			$cp = ( $cp << 6 ) | ( ord( $input[ $i + $j ] ) & 0x3F );
		}
		$out[] = array( substr( $input, $i, $size ), $cp );
		$i    += $size;
	}
	return $out;
}
