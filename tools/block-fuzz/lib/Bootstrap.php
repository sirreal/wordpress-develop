<?php
/**
 * Lightweight WordPress bootstrap for the block fuzzer.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

namespace BlockFuzz;

/**
 * Loads only the dependencies needed by the block parser, serializer,
 * processor, and KSES-backed block filtering functions.
 */
class Bootstrap {
	/**
	 * Whether the bootstrap has loaded.
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * Loads the WordPress APIs needed by the fuzzer.
	 */
	public static function load() {
		if ( self::$loaded ) {
			return;
		}

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', repo_root() . '/src/' );
		}

		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}

		$files = array(
			'wp-includes/compat.php',
			'wp-includes/compat-utf8.php',
			'wp-includes/class-wp-token-map.php',
			'wp-includes/plugin.php',
			'wp-includes/functions.php',
			'wp-includes/html-api/html5-named-character-references.php',
			'wp-includes/html-api/class-wp-html-attribute-token.php',
			'wp-includes/html-api/class-wp-html-span.php',
			'wp-includes/html-api/class-wp-html-text-replacement.php',
			'wp-includes/html-api/class-wp-html-decoder.php',
			'wp-includes/html-api/class-wp-html-tag-processor.php',
			'wp-includes/kses.php',
			'wp-includes/class-wp-block-parser-block.php',
			'wp-includes/class-wp-block-parser-frame.php',
			'wp-includes/class-wp-block-parser.php',
			'wp-includes/class-wp-block-processor.php',
			'wp-includes/blocks.php',
		);

		foreach ( $files as $file ) {
			require_once ABSPATH . $file;
		}

		self::$loaded = true;
	}
}
