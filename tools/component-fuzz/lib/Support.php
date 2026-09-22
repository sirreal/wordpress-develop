<?php
namespace ComponentFuzz;

function repo_root(): string {
	$dir = __DIR__;
	while ( '/' !== $dir ) {
		if ( is_dir( $dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'wp-includes' ) ) {
			return $dir;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}

	throw new \RuntimeException( 'Could not locate repository root.' );
}

function ensure_dir( string $dir ): void {
	if ( is_dir( $dir ) ) {
		return;
	}

	if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new \RuntimeException( "Could not create directory: {$dir}" );
	}
}

function write_json_file( string $path, array $data ): void {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $json ) {
		throw new \RuntimeException( 'Could not encode JSON: ' . json_last_error_msg() );
	}
	file_put_contents( $path, $json . "\n" );
}

function append_ndjson( string $path, array $data ): void {
	$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $json ) {
		throw new \RuntimeException( 'Could not encode NDJSON row: ' . json_last_error_msg() );
	}
	file_put_contents( $path, $json . "\n", FILE_APPEND );
}

function preview_value( $value, int $limit = 180 ) {
	if ( is_string( $value ) ) {
		$printable = preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
			static function ( array $m ): string {
				return sprintf( '\\x%02X', ord( $m[0] ) );
			},
			$value
		);

		if ( strlen( $printable ) > $limit ) {
			return substr( $printable, 0, $limit ) . '...';
		}

		return $printable;
	}

	if ( is_array( $value ) ) {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? '[array]' : preview_value( $json, $limit );
	}

	if ( is_object( $value ) ) {
		return '[object ' . get_class( $value ) . ']';
	}

	return $value;
}

function cli_options( array $argv ): array {
	$options = array();
	$count   = count( $argv );

	for ( $i = 1; $i < $count; $i++ ) {
		$arg = $argv[ $i ];
		if ( ! str_starts_with( $arg, '--' ) ) {
			continue;
		}

		$arg = substr( $arg, 2 );
		if ( str_contains( $arg, '=' ) ) {
			list( $key, $value ) = explode( '=', $arg, 2 );
			$options[ $key ]    = $value;
			continue;
		}

		$next = $argv[ $i + 1 ] ?? null;
		if ( null !== $next && ! str_starts_with( $next, '--' ) ) {
			$options[ $arg ] = $next;
			$i++;
		} else {
			$options[ $arg ] = true;
		}
	}

	return $options;
}

function option_string( array $options, string $key, ?string $default = null ): ?string {
	if ( ! array_key_exists( $key, $options ) ) {
		return $default;
	}

	return (string) $options[ $key ];
}

function option_int( array $options, string $key, int $default ): int {
	if ( ! array_key_exists( $key, $options ) ) {
		return $default;
	}

	return (int) $options[ $key ];
}

function option_bool( array $options, string $key, bool $default = false ): bool {
	if ( ! array_key_exists( $key, $options ) ) {
		return $default;
	}

	$value = $options[ $key ];
	if ( true === $value ) {
		return true;
	}

	return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
}
