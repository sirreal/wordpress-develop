<?php
namespace HtmlDecoderFuzz;

/**
 * Shared helpers for command line entry points.
 */
class Cli {
	private const VALID_MODES = array( 'oracle', 'bytes', 'names', 'legacy-followers', 'prefix-families', 'numeric-boundaries', 'corpus', 'token-map', 'coverage' );

	/**
	 * @return string[]
	 */
	public static function valid_modes(): array {
		return self::VALID_MODES;
	}

	public static function mode_uses_oracle( string $mode ): bool {
		return 'bytes' !== $mode;
	}

	public static function mode_uses_start_case_windows( string $mode ): bool {
		return in_array( $mode, array( 'names', 'legacy-followers', 'prefix-families', 'numeric-boundaries', 'corpus', 'token-map' ), true );
	}

	/**
	 * @param string[] $argv
	 * @param array<string, mixed> $defaults
	 * @return array<string, mixed>
	 */
	public static function parse_args( array $argv, array $defaults ): array {
		$options = $defaults;
		$count   = count( $argv );

		for ( $i = 1; $i < $count; $i++ ) {
			$arg = $argv[ $i ];
			if ( 0 !== strncmp( $arg, '--', 2 ) ) {
				fwrite( STDERR, "Unexpected argument: {$arg}\n" );
				exit( 2 );
			}

			$body = substr( $arg, 2 );
			if ( false !== strpos( $body, '=' ) ) {
				list( $name, $value ) = explode( '=', $body, 2 );
			} else {
				$name = $body;
				if ( $i + 1 >= $count ) {
					fwrite( STDERR, "Missing value for --{$name}\n" );
					exit( 2 );
				}
				$value = $argv[ ++$i ];
			}

			if ( ! array_key_exists( $name, $defaults ) ) {
				fwrite( STDERR, "Unknown option --{$name}\n" );
				exit( 2 );
			}

			if ( is_int( $defaults[ $name ] ) ) {
				if ( 1 !== preg_match( '/^-?\d+$/', $value ) ) {
					fwrite( STDERR, "--{$name} must be an integer\n" );
					exit( 2 );
				}
				$digits = '-' === $value[0] ? substr( $value, 1 ) : $value;
				$digits = ltrim( $digits, '0' );
				$digits = '' === $digits ? '0' : $digits;
				$max    = (string) PHP_INT_MAX;
				if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
					fwrite( STDERR, "--{$name} is outside the supported integer range\n" );
					exit( 2 );
				}
				$options[ $name ] = (int) $value;
			} else {
				$options[ $name ] = $value;
			}
		}

		return $options;
	}

	public static function emit( array $record ): void {
		$json = json_encode( $record, JSON_UNESCAPED_SLASHES );
		if ( false === $json || ! self::write_stream( STDOUT, $json . "\n" ) ) {
			fwrite( STDERR, "Cannot write worker event\n" );
			exit( 2 );
		}
	}

	/**
	 * @param resource $stream
	 */
	public static function write_stream( $stream, string $contents ): bool {
		$written = fwrite( $stream, $contents );
		return is_int( $written ) && strlen( $contents ) === $written;
	}

	public static function write_file( string $path, string $contents ): bool {
		if ( self::is_linked_file( $path ) ) {
			return false;
		}

		$written = file_put_contents( $path, $contents );
		return is_int( $written ) && strlen( $contents ) === $written;
	}

	public static function append_file( string $path, string $contents ): bool {
		if ( '' === $contents ) {
			return true;
		}
		if ( self::is_linked_file( $path ) ) {
			return false;
		}

		$written = file_put_contents( $path, $contents, FILE_APPEND );
		return is_int( $written ) && strlen( $contents ) === $written;
	}

	public static function is_linked_file( string $path ): bool {
		if ( is_link( $path ) ) {
			return true;
		}
		if ( ! file_exists( $path ) || is_dir( $path ) ) {
			return false;
		}
		if ( ! is_file( $path ) ) {
			return true;
		}

		$stat = @lstat( $path );
		return is_array( $stat ) && isset( $stat['nlink'] ) && $stat['nlink'] > 1;
	}

	public static function failure_signature_key( array $signatures, string $mode = 'oracle' ): string {
		$normalized = array_map( 'strval', $signatures );
		sort( $normalized, SORT_STRING );
		return hash( 'sha256', $mode . "\0" . implode( "\0", $normalized ) );
	}

	public static function remove_tree( string $path, string $root ): bool {
		if ( is_link( $path ) || is_file( $path ) ) {
			$real_root = realpath( $root );
			$real_parent = realpath( dirname( $path ) );
			if ( false === $real_root || false === $real_parent ) {
				return false;
			}

			$root_prefix = rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( $real_parent !== $real_root && 0 !== strncmp( $real_parent . DIRECTORY_SEPARATOR, $root_prefix, strlen( $root_prefix ) ) ) {
				return false;
			}

			return @unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}

		$real_path = realpath( $path );
		$real_root = realpath( $root );
		if ( false === $real_path || false === $real_root || $real_path === $real_root ) {
			return false;
		}

		$root_prefix = rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strncmp( $real_path . DIRECTORY_SEPARATOR, $root_prefix, strlen( $root_prefix ) ) ) {
			return false;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real_path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$pathname = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				if ( ! @rmdir( $pathname ) ) {
					return false;
				}
			} elseif ( ! @unlink( $pathname ) ) {
				return false;
			}
		}

		return @rmdir( $real_path );
	}

	public static function require_int_at_least( array $options, string $name, int $minimum ): void {
		if ( ! isset( $options[ $name ] ) || ! is_int( $options[ $name ] ) || $options[ $name ] < $minimum ) {
			fwrite( STDERR, "--{$name} must be at least {$minimum}\n" );
			exit( 2 );
		}
	}

	/**
	 * @param string[] $allowed
	 */
	public static function require_one_of( array $options, string $name, array $allowed ): void {
		if ( ! isset( $options[ $name ] ) || ! in_array( $options[ $name ], $allowed, true ) ) {
			fwrite( STDERR, "--{$name} must be one of: " . implode( ', ', $allowed ) . "\n" );
			exit( 2 );
		}
	}

	public static function git_metadata( string $repo_root ): array {
		$run = static function ( array $command ) use ( $repo_root ): ?string {
			$process = @proc_open(
				$command,
				array(
					0 => array( 'file', '/dev/null', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'file', '/dev/null', 'a' ),
				),
				$pipes,
				$repo_root
			);
			if ( ! is_resource( $process ) ) {
				return null;
			}
			$out = stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			$code = proc_close( $process );
			return 0 === $code ? trim( (string) $out ) : null;
		};

		$commit = $run( array( 'git', 'rev-parse', 'HEAD' ) );
		$branch = $run( array( 'git', 'rev-parse', '--abbrev-ref', 'HEAD' ) );
		$status = $run( array( 'git', 'status', '--porcelain', '--untracked-files=no' ) );

		return array(
			'commit' => $commit,
			'branch' => $branch,
			'dirty'  => null === $status ? null : '' !== $status,
		);
	}

	public static function environment_metadata( Oracles $oracles ): array {
		return array(
			'php'     => PHP_VERSION,
			'os'      => PHP_OS_FAMILY,
			'oracles' => $oracles->names(),
		);
	}

	public static function payload_preview( string $payload ): array {
		return array(
			'bytes'  => strlen( $payload ),
			'sha256' => hash( 'sha256', $payload ),
			'hex'    => bin2hex( substr( $payload, 0, 80 ) ) . ( strlen( $payload ) > 80 ? '...' : '' ),
		);
	}
}
