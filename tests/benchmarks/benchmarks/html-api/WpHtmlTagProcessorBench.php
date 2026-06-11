<?php
/**
 * Benchmarks for WP_HTML_Tag_Processor.
 *
 * @package WordPress
 */

declare(strict_types=1);

use PhpBench\Attributes as Bench;

class WpHtmlTagProcessorBench {
	/**
	 * Processor instance for benchmarks.
	 */
	private WP_HTML_Tag_Processor|WP_HTML_Processor|null $processor = null;

	public function clean_up_processor(): void {
		$this->processor = null;
	}

	/**
	 * Benchmark normalizing simple Unix paths.
	 * @param array{0: string} $params
	 */
	#[Bench\Warmup( 2 )]
	#[Bench\Iterations( 50 )]
	#[Bench\Revs( 10 )]
	#[Bench\BeforeMethods( 'set_up_script_tag_processor' )]
	#[Bench\AfterMethods( 'clean_up_processor' )]
	#[Bench\ParamProviders( 'provide_script_tag_contents' )]
	public function bench_javascript_custom_escape( array $params ): void {
		[ $source_text] = $params;
		assert( $this->processor->set_modifiable_text( $source_text ), 'Failed to set modifiable text.' );
	}

	public function set_up_script_tag_processor(): void {
		$this->processor = new WP_HTML_Tag_Processor( '<script></script>' );
		$this->processor->next_tag();
	}

	/**
	 * @return iterable<array{0: string}>
	 */
	public static function provide_script_tag_contents(): iterable {
		yield 'empty' => array( '' );

		yield 'short' => array( 'console.log("Hello, World!");' );

		yield 'many replacements' => array(
			<<<'JS'
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
			JS,
		);
	}

	/**
	 * Benchmark HTML parsing.
	 * @param array{0: string} $params
	 */
	#[Bench\Warmup( 2 )]
	#[Bench\Iterations( 20 )]
	#[Bench\Revs( 5 )]
	#[Bench\ParamProviders( 'provide_html' )]
	#[Bench\BeforeMethods( 'set_up_html_tag_processor' )]
	#[Bench\AfterMethods( 'clean_up_processor' )]
	public function bench_tag_processor_html_parsing( array $params ): void {
		while ( $this->processor->next_token() ) {
			// No-op.
		}
	}

	public function set_up_html_tag_processor( array $params ): void {
		$this->processor = new WP_HTML_Tag_Processor( file_get_contents( $params[0] ) );
	}

	/**
	 * Benchmark HTML parsing.
	 * @param array{0: string} $params
	 */
	#[Bench\Warmup( 2 )]
	#[Bench\Iterations( 20 )]
	#[Bench\Revs( 3 )]
	#[Bench\ParamProviders( 'provide_html' )]
	#[Bench\BeforeMethods( 'set_up_html_fragment_processor' )]
	#[Bench\AfterMethods( 'clean_up_processor' )]
	public function bench_html_fragment_parsing( array $params ): void {
		while ( $this->processor->next_token() ) {
			// No-op.
		}
	}

	public function set_up_html_fragment_processor( array $params ): void {
		$this->processor = WP_HTML_Processor::create_fragment( file_get_contents( $params[0] ) );
	}

	/**
	 * Benchmark HTML parsing.
	 * @param array{0: string} $params
	 */
	#[Bench\Warmup( 2 )]
	#[Bench\Iterations( 20 )]
	#[Bench\Revs( 3 )]
	#[Bench\ParamProviders( 'provide_html' )]
	#[Bench\BeforeMethods( 'set_up_html_full_parser' )]
	#[Bench\AfterMethods( 'clean_up_processor' )]
	public function bench_html_full_parsing( array $params ): void {
		while ( $this->processor->next_token() ) {
			// No-op.
		}
	}

	public function set_up_html_full_parser( array $params ): void {
		$this->processor = WP_HTML_Processor::create_full_parser( file_get_contents( $params[0] ) );
	}

	public static function provide_html(): iterable {
		yield 'Empty string' => array( 'data:text/html,' );
		yield 'Short doc' => array( 'data:text/html,' . rawurlencode( '<h1>Hello, world!</h1>' ) );
		yield 'HTML Standard' => array( 'file://' . DIR_BENCHMARKDATA . '/html-standard.html' );
	}
}
