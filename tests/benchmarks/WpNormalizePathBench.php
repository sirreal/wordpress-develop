<?php
/**
 * Benchmarks for wp_normalize_path().
 *
 * @package WordPress
 */

use PhpBench\Attributes as Bench;

/**
 * Benchmarks for the wp_normalize_path() function.
 *
 * Run with: vendor/bin/phpbench run tests/benchmarks/WpNormalizePathBench.php --report=default
 */
#[Bench\Warmup(3)]
#[Bench\Iterations(10)]
#[Bench\Revs(100)]
class WpNormalizePathBench {

	/**
	 * Benchmark normalizing simple Unix paths.
	 */
	#[Bench\ParamProviders('provideSimplePaths')]
	public function benchSimplePath( array $params ): void {
		wp_normalize_path( $params['path'] );
	}

	/**
	 * Provide simple Unix-style paths.
	 */
	public function provideSimplePaths(): iterable {
		yield 'short_path' => ['path' => '/var/www/html'];
		yield 'long_path' => ['path' => '/var/www/html/wp-content/themes/twentytwentyfive/templates/single.html'];
		yield 'relative_path' => ['path' => 'wp-content/uploads/2025/01/image.jpg'];
	}

	/**
	 * Benchmark normalizing Windows-style paths with backslashes.
	 */
	#[Bench\ParamProviders('provideWindowsPaths')]
	public function benchWindowsPath( array $params ): void {
		wp_normalize_path( $params['path'] );
	}

	/**
	 * Provide Windows-style paths.
	 */
	public function provideWindowsPaths(): iterable {
		yield 'windows_short' => ['path' => 'C:\\xampp\\htdocs'];
		yield 'windows_long' => ['path' => 'C:\\Users\\Developer\\Projects\\wordpress\\wp-content\\themes\\theme'];
		yield 'windows_lowercase_drive' => ['path' => 'c:\\xampp\\htdocs\\wordpress'];
		yield 'mixed_slashes' => ['path' => 'C:\\xampp/htdocs\\wordpress/wp-content'];
	}

	/**
	 * Benchmark normalizing paths with multiple consecutive slashes.
	 */
	#[Bench\ParamProviders('provideMultipleSlashPaths')]
	public function benchMultipleSlashes( array $params ): void {
		wp_normalize_path( $params['path'] );
	}

	/**
	 * Provide paths with multiple consecutive slashes.
	 */
	public function provideMultipleSlashPaths(): iterable {
		yield 'double_slashes' => ['path' => '/var//www//html'];
		yield 'triple_slashes' => ['path' => '/var///www///html'];
		yield 'many_slashes' => ['path' => '/var/////www/////html/////wordpress'];
		yield 'network_share' => ['path' => '//server/share/folder'];
	}

	/**
	 * Benchmark normalizing stream wrapper paths.
	 */
	#[Bench\ParamProviders('provideStreamPaths')]
	public function benchStreamPath( array $params ): void {
		wp_normalize_path( $params['path'] );
	}

	/**
	 * Provide stream wrapper paths.
	 */
	public function provideStreamPaths(): iterable {
		yield 'php_stream' => ['path' => 'php://input'];
		yield 'file_stream' => ['path' => 'file:///var/www/html/test.php'];
		yield 's3_stream' => ['path' => 's3://bucket/path/to/file.jpg'];
		yield 'phar_stream' => ['path' => 'phar:///path/to/archive.phar/internal/file.php'];
		yield 'stream_with_backslashes' => ['path' => 'file://C:\\xampp\\htdocs\\file.php'];
	}

	/**
	 * Benchmark with paths that are already normalized.
	 * This tests the "best case" scenario.
	 */
	#[Bench\ParamProviders('provideNormalizedPaths')]
	public function benchAlreadyNormalized( array $params ): void {
		wp_normalize_path( $params['path'] );
	}

	/**
	 * Provide already-normalized paths.
	 */
	public function provideNormalizedPaths(): iterable {
		yield 'normalized_short' => ['path' => '/var/www/html'];
		yield 'normalized_long' => ['path' => '/var/www/html/wp-content/plugins/akismet/class.akismet.php'];
		yield 'normalized_windows' => ['path' => 'C:/xampp/htdocs/wordpress'];
	}
}
