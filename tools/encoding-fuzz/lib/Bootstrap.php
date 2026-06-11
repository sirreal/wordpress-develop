<?php
namespace EncodingFuzz;

/**
 * Loads the WordPress UTF-8 functions under test into a bare PHP process.
 *
 * Only the UTF-8 files under test are loaded. A few private UTF-8 helpers
 * live in `compat.php`, so their function bodies are extracted from that
 * source file without loading the rest of WordPress compatibility glue.
 */
class Bootstrap {
	public static function repo_root(): string {
		return dirname( __DIR__, 3 );
	}

	public static function load_targets(): void {
		if ( function_exists( 'wp_is_valid_utf8' ) ) {
			return;
		}

		$root = self::repo_root();
		require_once __DIR__ . '/wp-stubs.php';
		require_once $root . '/src/wp-includes/compat-utf8.php';
		self::load_compat_functions( $root . '/src/wp-includes/compat.php', array( '_is_utf8_charset', '_mb_chr', '_mb_ord', '_mb_substr' ) );
		require_once $root . '/src/wp-includes/utf8.php';

		/*
		 * `wp_scrub_utf8()` saves and restores the global substitute character,
		 * so the restored value should already be the one oracles expect.
		 */
		if ( function_exists( 'mb_substitute_character' ) ) {
			mb_substitute_character( 0xFFFD );
		}
	}

	/**
	 * Loads selected top-level function definitions from `compat.php`.
	 *
	 * The full file has unrelated bootstrap assumptions (for example,
	 * sodium and deprecation helpers). The fuzzer only needs these
	 * private UTF-8 polyfills, and evaluating the source definitions keeps
	 * the tested code tied to WordPress without widening the harness.
	 *
	 * @param string   $path      Source file path.
	 * @param string[] $functions Function names to load.
	 */
	private static function load_compat_functions( string $path, array $functions ): void {
		$source = file_get_contents( $path );
		if ( false === $source ) {
			throw new \RuntimeException( "Unable to read {$path}" );
		}

		foreach ( $functions as $function_name ) {
			if ( function_exists( $function_name ) ) {
				continue;
			}

			eval( self::extract_function_definition( $source, $function_name ) );
		}
	}

	private static function extract_function_definition( string $source, string $function_name ): string {
		$pattern = '/function\s+' . preg_quote( $function_name, '/' ) . '\s*\(/';
		if ( 1 !== preg_match( $pattern, $source, $match, PREG_OFFSET_CAPTURE ) ) {
			throw new \RuntimeException( "Unable to find function {$function_name}" );
		}

		$tokens    = token_get_all( '<?php ' . substr( $source, $match[0][1] ) );
		$code      = '';
		$depth     = 0;
		$in_body   = false;
		$skip_open = true;

		foreach ( $tokens as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;

			if ( $skip_open ) {
				$skip_open = false;
				continue;
			}

			$code .= $text;

			if ( '{' === $text ) {
				++$depth;
				$in_body = true;
			} elseif ( '}' === $text && $in_body ) {
				--$depth;
				if ( 0 === $depth ) {
					return $code;
				}
			}
		}

		throw new \RuntimeException( "Unable to close function body for {$function_name}" );
	}
}
