<?php
namespace HtmlApiFuzz;

class HtmlApiBootstrap {
	private static $loaded = false;

	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}

		if ( ! function_exists( '__' ) ) {
			require_once __DIR__ . '/wp-stubs.php';
		}

		if ( ! function_exists( '_doing_it_wrong' ) ) {
			require_once __DIR__ . '/wp-stubs.php';
		}

		if ( ! function_exists( 'wp_trigger_error' ) ) {
			require_once __DIR__ . '/wp-stubs.php';
		}

		$root  = repo_root();
		$files = array(
			'src/wp-includes/compat.php',
			'src/wp-includes/compat-utf8.php',
			'src/wp-includes/utf8.php',
			'src/wp-includes/class-wp-token-map.php',
			'src/wp-includes/html-api/html5-named-character-references.php',
			'src/wp-includes/html-api/class-wp-html-attribute-token.php',
			'src/wp-includes/html-api/class-wp-html-span.php',
			'src/wp-includes/html-api/class-wp-html-doctype-info.php',
			'src/wp-includes/html-api/class-wp-html-text-replacement.php',
			'src/wp-includes/html-api/class-wp-html-decoder.php',
			'src/wp-includes/html-api/class-wp-html-tag-processor.php',
			'src/wp-includes/html-api/class-wp-html-unsupported-exception.php',
			'src/wp-includes/html-api/class-wp-html-active-formatting-elements.php',
			'src/wp-includes/html-api/class-wp-html-open-elements.php',
			'src/wp-includes/html-api/class-wp-html-token.php',
			'src/wp-includes/html-api/class-wp-html-stack-event.php',
			'src/wp-includes/html-api/class-wp-html-processor-state.php',
			'src/wp-includes/html-api/class-wp-html-processor.php',
		);

		foreach ( $files as $file ) {
			require_once $root . DIRECTORY_SEPARATOR . $file;
		}

		self::$loaded = true;
	}
}
