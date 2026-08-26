<?php
namespace CssDeclarationFuzz;

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

function option_bool( array $options, string $name, bool $default ): bool {
	if ( ! array_key_exists( $name, $options ) ) {
		return $default;
	}
	$value = $options[ $name ];
	return true === $value || in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
}

function ensure_dir( string $dir ): void {
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new \RuntimeException( "Could not create directory: {$dir}" );
	}
}

function json_encode_safe( $value ): string {
	$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	return false === $encoded ? '{"jsonError":"' . json_last_error_msg() . '"}' : $encoded;
}

function append_ndjson( string $path, array $value ): void {
	file_put_contents( $path, json_encode_safe( $value ) . "\n", FILE_APPEND | LOCK_EX );
}

function write_json_file( string $path, array $value ): void {
	file_put_contents( $path, json_encode_safe( $value ) . "\n" );
}

function printable_bytes( string $bytes, int $max_length = 4096 ): string {
	$out       = '';
	$truncated = strlen( $bytes ) > $max_length;
	$bytes     = substr( $bytes, 0, $max_length );
	for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
		$ord = ord( $bytes[ $i ] );
		if ( $ord >= 0x20 && $ord <= 0x7e ) {
			$out .= '\\' === $bytes[ $i ] ? '\\\\' : $bytes[ $i ];
		} else {
			$out .= sprintf( '\\x%02X', $ord );
		}
	}
	return $out . ( $truncated ? '…(truncated)' : '' );
}

function git_metadata(): array {
	$root   = escapeshellarg( repo_root() );
	$head   = trim( (string) shell_exec( "git -C {$root} rev-parse HEAD 2>/dev/null" ) );
	$branch = trim( (string) shell_exec( "git -C {$root} rev-parse --abbrev-ref HEAD 2>/dev/null" ) );
	return array(
		'head'   => '' === $head ? null : $head,
		'branch' => '' === $branch ? null : $branch,
	);
}

/** Converts reportable PHP warnings/notices into replayable worker failures. */
function throw_on_php_error(): void {
	set_error_handler(
		static function ( int $severity, string $message, string $file, int $line ): bool {
			if ( 0 === ( error_reporting() & $severity ) ) {
				return false;
			}
			throw new \ErrorException( $message, 0, $severity, $file, $line );
		}
	);
}
