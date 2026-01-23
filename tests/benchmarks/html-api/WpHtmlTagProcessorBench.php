<?php
/**
 * Benchmarks for WP_HTML_Tag_Processor.
 *
 * @package WordPress
 */

declare(strict_types=1);

use PhpBench\Attributes as Bench;

#[Bench\Warmup( 3 )]
#[Bench\Iterations( 10 )]
#[Bench\Revs( 100 )]
class WpHtmlTagProcessorBench {

	/**
	 * Benchmark normalizing simple Unix paths.
	 * @param array{0: WP_HTML_Tag_Processor, 1: string} $params
	 */
	#[Bench\ParamProviders( 'provide_script_tag_processor' )]
	public function bench_javascript_custom_escape( array $params ): void {
		[$processor, $source_text] = $params;
		assert( $processor->set_modifiable_text( $source_text ), 'Failed to set modifiable text.' );
	}

	/**
	 * @return iterable<array{0: WP_HTML_Tag_Processor, 1: string}>
	 */
	public static function provide_script_tag_processor(): iterable {
		foreach ( self::provide_javascript() as $name => $source_text ) {
			$processor = new WP_HTML_Tag_Processor( '<script></script>' );
			$processor->next_tag();
			yield $name => array( $processor, $source_text );
		}
	}

	/**
	 * Provide simple Unix-style paths.
	 * @return iterable<string>
	 */
	public static function provide_javascript(): iterable {
		yield 'empty' => '';

		yield 'short' => 'console.log("Hello, World!");';

		// yield 'tinymce' => file_get_contents( dirname(__DIR__, 2) . 'src/js/_enqueues/vendor/tinymce/tinymce.js' );

		yield 'many replacements' => <<<'JS'
			/* <!-- and <script> is bad news in JS land! */
			const templateString = `
				But can't we talk about <script> and </script> tags without breaking everything?
				</script>
				</SCRIPT>
				</SCRIPT	>
				</SCRIPT
				>
				</SCRIPT/>
				</script/>
				</script ignored attributes />
				</script	/>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
				<script></script>
			`;
			/* Good luck! */
			JS;
	}


	/**
	 * Benchmark HTML parsing.
	 * @param array{0: WP_HTML_Tag_Processor} $params
	 */
	#[Bench\ParamProviders( 'provide_html' )]
	public function bench_tag_processor_html_parsing( array $params ): void {
		$processor = new WP_HTML_Tag_Processor( $params[0] );
		while ( $processor->next_token() ) {
			// No-op.
		}
	}

	/**
	 * Benchmark HTML parsing.
	 * @param array{0: WP_HTML_Tag_Processor} $params
	 */
	#[Bench\ParamProviders( 'provide_html' )]
	public function bench_html_fragment_parsing( array $params ): void {
		$processor = WP_HTML_Processor::create_fragment( $params[0] );
		while ( $processor->next_token() ) {
			// No-op.
		}
	}

	/**
	 * Benchmark HTML parsing.
	 * @param array{0: WP_HTML_Tag_Processor} $params
	 */
	#[Bench\ParamProviders( 'provide_html' )]
	public function bench_html_full_parsing( array $params ): void {
		$processor = WP_HTML_Processor::create_full_parser( $params[0] );
		while ( $processor->next_token() ) {
			// No-op.
		}
	}

	public static function provide_html(): iterable {
		yield 'Empty string' => array( '' );
		yield 'Short doc' => array( '<h1>Hello, world!</h1>' );
		yield 'HTML Standard' => array( file_get_contents( DIR_TESTDATA . '/html-api/html-standard.html' ) );
	}
}
