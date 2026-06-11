<?php
namespace EncodingFuzz;

/**
 * Small shared helpers for the CLI entry points.
 */
class Cli {
	/**
	 * Parses `--name value` and `--name=value` pairs.
	 *
	 * @param string[] $argv
	 * @param array<string, mixed> $defaults Option name => default value.
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

			$options[ $name ] = is_int( $defaults[ $name ] ) ? (int) $value : $value;
		}

		return $options;
	}

	/**
	 * Resolves an `--external` option value to a list of oracle names.
	 *
	 * @return string[]
	 */
	public static function resolve_externals( string $option ): array {
		if ( 'none' === $option ) {
			return array();
		}

		if ( 'auto' === $option ) {
			return array( 'python3', 'node' );
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $option ) ) ) );
	}

	public static function emit( array $record ): void {
		fwrite( STDOUT, json_encode( $record, JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	/**
	 * Compact Git metadata, collected once per process.
	 */
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
			$out  = stream_get_contents( $pipes[1] );
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
		$forced_pcre_u = getenv( 'ENCODING_FUZZ_FORCE_PCRE_U' );
		$pcre_override = false !== $forced_pcre_u && in_array( strtolower( $forced_pcre_u ), array( '0', 'false', 'no', 'off' ), true )
			? 'off'
			: null;

		return array(
			'php'             => PHP_VERSION,
			'os'              => PHP_OS_FAMILY,
			'oracles'         => $oracles->names(),
			// Which environment branch of utf8.php loaded (PCRE vs fallback).
			'pcre_u'          => function_exists( '_wp_can_use_pcre_u' ) ? _wp_can_use_pcre_u() : null,
			'pcre_u_override' => $pcre_override,
			// Mark fault-injected artifacts so they can never be mistaken
			// for real findings.
			'fault'           => getenv( 'ENCODING_FUZZ_FAULT' ) ?: null,
		);
	}
}
