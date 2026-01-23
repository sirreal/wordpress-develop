<?php
/**
 * Benchmarks for WP_HTML_Tag_Processor.
 *
 * @package WordPress
 */

use PhpBench\Attributes as Bench;

#[Bench\Warmup( 3 )]
#[Bench\Iterations( 10 )]
#[Bench\Revs( 100 )]
class WpHtmlTagProcessorBench {

	/**
	 * Benchmark normalizing simple Unix paths.
	 */
	#[Bench\ParamProviders( 'provide_processor' )]
	public function bench_javascript_custom_escape( array $params  ): void {
		[$processor, $source_text] = $params;
		assert( $processor->set_modifiable_text( $source_text ), 'Failed to set modifiable text.' );
	}

	/**
	 * @return iterable<array{0: WP_HTML_Tag_Processor, 1: string}>
	 */
	public static function provide_processor(): iterable {
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
}
