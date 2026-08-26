<?php
namespace CssDeclarationFuzz;

class Bootstrap {
	private static $loaded = false;

	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}

		require_once __DIR__ . '/wp-stubs.php';

		$root  = repo_root();
		$files = array(
			'src/wp-includes/compat-utf8.php',
			'src/wp-includes/utf8.php',
			'src/wp-includes/html-api/class-wp-html-decoder.php',
			'src/wp-includes/css-api/class-wp-css-builder.php',
			'src/wp-includes/css-api/class-wp-css-token-processor.php',
			'src/wp-includes/html-api/class-wp-html-style-attribute-processor.php',
		);

		foreach ( $files as $file ) {
			$path = $root . DIRECTORY_SEPARATOR . $file;
			if ( ! is_file( $path ) ) {
				throw new \RuntimeException( "Required target file is missing: {$file}" );
			}
			require_once $path;
		}

		self::$loaded = true;
	}
}
