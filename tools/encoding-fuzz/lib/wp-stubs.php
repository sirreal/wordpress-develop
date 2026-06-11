<?php
/**
 * Minimal global stand-ins so `src/wp-includes/utf8.php` can load
 * without pulling in the rest of WordPress.
 */

if ( ! function_exists( '_wp_can_use_pcre_u' ) ) {
	function _wp_can_use_pcre_u( $set = null ): bool {
		static $utf8_pcre = null;
		if ( null === $utf8_pcre ) {
			$forced = getenv( 'ENCODING_FUZZ_FORCE_PCRE_U' );
			if ( false !== $forced && in_array( strtolower( $forced ), array( '0', 'false', 'no', 'off' ), true ) ) {
				$utf8_pcre = false;
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$utf8_pcre = false !== @preg_match( '/^./u', 'a' );
			}
		}
		return (bool) $utf8_pcre;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return 'blog_charset' === $option ? 'UTF-8' : $default_value;
	}
}

if ( ! function_exists( '_deprecated_function' ) ) {
	function _deprecated_function( $function_name, $version, $replacement = '' ): void {}
}
