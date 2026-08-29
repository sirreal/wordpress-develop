<?php
namespace CssSelectorFuzz;

class Bootstrap {
	private static $loaded = false;

	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}

		require_once __DIR__ . '/wp-stubs.php';

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
			'src/wp-includes/html-api/css/class-wp-css-selector-parser-matcher.php',
			'src/wp-includes/html-api/css/class-wp-css-type-selector.php',
			'src/wp-includes/html-api/css/class-wp-css-id-selector.php',
			'src/wp-includes/html-api/css/class-wp-css-class-selector.php',
			'src/wp-includes/html-api/css/class-wp-css-attribute-selector.php',
			'src/wp-includes/html-api/css/class-wp-css-compound-selector.php',
			'src/wp-includes/html-api/css/class-wp-css-compound-selector-list.php',
			'src/wp-includes/html-api/css/class-wp-css-complex-selector.php',
			'src/wp-includes/html-api/css/class-wp-css-complex-selector-list.php',
		);

		foreach ( $files as $file ) {
			$path = $root . DIRECTORY_SEPARATOR . $file;
			if ( is_file( $path ) ) {
				require_once $path;
			}
		}

		self::$loaded = true;
	}

	public static function reset_doing_it_wrong(): void {
		$GLOBALS['css_selector_fuzz_doing_it_wrong'] = array();
	}

	/** @return array<int, array{function: string, message: string}> */
	public static function doing_it_wrong_calls(): array {
		return $GLOBALS['css_selector_fuzz_doing_it_wrong'];
	}
}
