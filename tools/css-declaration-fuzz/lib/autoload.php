<?php
namespace CssDeclarationFuzz;

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Prng.php';

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);
