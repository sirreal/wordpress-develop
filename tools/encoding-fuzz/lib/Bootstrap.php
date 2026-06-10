<?php
namespace EncodingFuzz;

/**
 * Loads the WordPress UTF-8 functions under test into a bare PHP process.
 *
 * Only `compat-utf8.php` and `utf8.php` are loaded. `utf8.php` calls
 * `_wp_can_use_pcre_u()` at load time, which normally lives in
 * `compat.php`; a minimal stand-in from `wp-stubs.php` covers it so the
 * rest of WordPress stays out of the fuzzer process.
 */
class Bootstrap {
	public static function repo_root(): string {
		return dirname( __DIR__, 3 );
	}

	public static function load_targets(): void {
		if ( function_exists( 'wp_is_valid_utf8' ) ) {
			return;
		}

		require_once __DIR__ . '/wp-stubs.php';

		$root = self::repo_root();
		require_once $root . '/src/wp-includes/compat-utf8.php';
		require_once $root . '/src/wp-includes/utf8.php';

		/*
		 * `wp_scrub_utf8()` saves and restores the global substitute character,
		 * so the restored value should already be the one oracles expect.
		 */
		if ( function_exists( 'mb_substitute_character' ) ) {
			mb_substitute_character( 0xFFFD );
		}
	}
}
