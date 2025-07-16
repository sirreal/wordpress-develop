<?php
/**
 * Benchmark tests for CSS selector parsing - focused on memory and throughput.
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since {WP_VERSION}
 * @group html-api
 * @group benchmark
 */
class Tests_HtmlApi_WpCssSelectorBenchmark extends WP_UnitTestCase {

	/**
	 * Measures memory usage and execution time for a callback.
	 *
	 * @param callable $callback Function to measure.
	 * @param int      $iterations Number of iterations.
	 * @return array Performance metrics.
	 */
	private function benchmark( $callback, $iterations = 10000 ) {
		// Force garbage collection before measurement
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		$memory_start = memory_get_usage( true );
		$peak_memory_start = memory_get_peak_usage( true );
		$time_start = microtime( true );

		for ( $i = 0; $i < $iterations; $i++ ) {
			$callback();
		}

		$time_end = microtime( true );
		$memory_end = memory_get_usage( true );
		$peak_memory_end = memory_get_peak_usage( true );

		return array(
			'iterations'       => $iterations,
			'total_time_ms'    => ( $time_end - $time_start ) * 1000,
			'avg_time_ms'      => ( ( $time_end - $time_start ) / $iterations ) * 1000,
			'memory_used_kb'   => ( $memory_end - $memory_start ) / 1024,
			'peak_memory_kb'   => ( $peak_memory_end - $peak_memory_start ) / 1024,
			'ops_per_second'   => $iterations / ( $time_end - $time_start ),
		);
	}

	/**
	 * Logs benchmark results in a standardized format.
	 *
	 * @param string $test_name Name of the benchmark test.
	 * @param array  $results Results from benchmark().
	 */
	private function log_benchmark_results( $test_name, $results ) {
		$message = sprintf(
			'[BENCHMARK] %s: %.2f ops/sec, %.4fms avg, %.2fKB memory, %.2fKB peak',
			$test_name,
			$results['ops_per_second'],
			$results['avg_time_ms'],
			$results['memory_used_kb'],
			$results['peak_memory_kb']
		);

		error_log( $message );
		
		// Also output to test result for CI visibility
		$this->addToAssertionCount( 1 );
		echo "\n" . $message . "\n";
	}

	/**
	 * @ticket 62653
	 */
	public function test_simple_selector_parsing_throughput() {
		$selectors = array( 'div', 'span', 'p', 'a', 'h1', 'section', 'article', 'nav', 'header', 'footer' );
		$selector_count = count( $selectors );

		$results = $this->benchmark( function() use ( $selectors, $selector_count ) {
			$selector = $selectors[ array_rand( $selectors ) ];
			$offset = 0;
			WP_CSS_Type_Selector::parse( $selector, $offset );
		} );

		$this->log_benchmark_results( 'Simple Selector Parsing', $results );
		$this->assertGreaterThan( 50000, $results['ops_per_second'], 'Simple selector parsing should exceed 50K ops/sec' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_compound_selector_parsing_throughput() {
		$selectors = array(
			'div.class',
			'p#id',
			'span[attr]',
			'article.post#main',
			'section.content[role]',
			'nav.menu[aria-label]',
			'div.container.fluid',
			'button.btn.primary[type="submit"]',
		);

		$results = $this->benchmark( function() use ( $selectors ) {
			$selector = $selectors[ array_rand( $selectors ) ];
			$offset = 0;
			WP_CSS_Compound_Selector::parse( $selector, $offset );
		} );

		$this->log_benchmark_results( 'Compound Selector Parsing', $results );
		$this->assertGreaterThan( 10000, $results['ops_per_second'], 'Compound selector parsing should exceed 10K ops/sec' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_complex_selector_parsing_throughput() {
		$selectors = array(
			'div p',
			'article > header',
			'nav ul li',
			'main > section > article',
			'div.container > section.content',
			'nav.menu > ul > li > a',
			'article.post > header.post-header',
			'main.site-main > div.container > section.content > article.post',
		);

		$results = $this->benchmark( function() use ( $selectors ) {
			$selector = $selectors[ array_rand( $selectors ) ];
			$offset = 0;
			WP_CSS_Complex_Selector::parse( $selector, $offset );
		} );

		$this->log_benchmark_results( 'Complex Selector Parsing', $results );
		$this->assertGreaterThan( 5000, $results['ops_per_second'], 'Complex selector parsing should exceed 5K ops/sec' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_selector_list_parsing_throughput() {
		$selector_lists = array(
			'div, p, span',
			'article.post, section.content',
			'nav > ul, div > p',
			'div.class1, p#id, span[attr]',
			'article.post > header, section.content > p',
			'nav.menu > ul > li, div.content > article > p',
		);

		$results = $this->benchmark( function() use ( $selector_lists ) {
			$selector_list = $selector_lists[ array_rand( $selector_lists ) ];
			WP_CSS_Complex_Selector_List::from_selectors( $selector_list );
		} );

		$this->log_benchmark_results( 'Selector List Parsing', $results );
		$this->assertGreaterThan( 3000, $results['ops_per_second'], 'Selector list parsing should exceed 3K ops/sec' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_attribute_selector_parsing_throughput() {
		$selectors = array(
			'[href]',
			'[data-test]',
			'[href="value"]',
			'[href^="https"]',
			'[href$=".html"]',
			'[href*="example"]',
			'[href~="word"]',
			'[href|="en"]',
			'[data-test="complex-value"]',
			'[aria-label="Accessibility text"]',
		);

		$results = $this->benchmark( function() use ( $selectors ) {
			$selector = $selectors[ array_rand( $selectors ) ];
			$offset = 0;
			WP_CSS_Attribute_Selector::parse( $selector, $offset );
		} );

		$this->log_benchmark_results( 'Attribute Selector Parsing', $results );
		$this->assertGreaterThan( 8000, $results['ops_per_second'], 'Attribute selector parsing should exceed 8K ops/sec' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_unicode_selector_parsing_throughput() {
		$selectors = array(
			'.café',
			'#résumé',
			'[title="Élément"]',
			'div.наименование',
			'p.العربية',
			'span.中文',
			'article.日本語',
			'section.한국어',
			'nav.επιλογή',
			'div.элемент',
		);

		$results = $this->benchmark( function() use ( $selectors ) {
			$selector = $selectors[ array_rand( $selectors ) ];
			$offset = 0;
			if ( $selector[0] === '.' ) {
				WP_CSS_Class_Selector::parse( $selector, $offset );
			} elseif ( $selector[0] === '#' ) {
				WP_CSS_ID_Selector::parse( $selector, $offset );
			} elseif ( $selector[0] === '[' ) {
				WP_CSS_Attribute_Selector::parse( $selector, $offset );
			} else {
				WP_CSS_Type_Selector::parse( $selector, $offset );
			}
		} );

		$this->log_benchmark_results( 'Unicode Selector Parsing', $results );
		$this->assertGreaterThan( 15000, $results['ops_per_second'], 'Unicode selector parsing should exceed 15K ops/sec' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_memory_usage_scaling() {
		$sizes = array( 10, 100, 1000 );
		$results = array();

		foreach ( $sizes as $size ) {
			// Generate selectors of varying complexity
			$selectors = array();
			for ( $i = 0; $i < $size; $i++ ) {
				$selectors[] = "div.class{$i}#id{$i}[data-test=\"value{$i}\"]";
			}

			$benchmark_result = $this->benchmark( function() use ( $selectors ) {
				foreach ( $selectors as $selector ) {
					$offset = 0;
					WP_CSS_Compound_Selector::parse( $selector, $offset );
				}
			}, 100 ); // Fewer iterations for scaling test

			$results[ $size ] = $benchmark_result;
			$this->log_benchmark_results( "Memory Scaling ({$size} selectors)", $benchmark_result );
		}

		// Assert that memory usage scales reasonably
		$memory_10 = $results[10]['memory_used_kb'];
		$memory_100 = $results[100]['memory_used_kb'];
		$memory_1000 = $results[1000]['memory_used_kb'];

		// Memory usage should scale roughly linearly, not exponentially
		$scaling_factor_100 = $memory_100 / max( $memory_10, 1 );
		$scaling_factor_1000 = $memory_1000 / max( $memory_100, 1 );

		$this->assertLessThan( 50, $scaling_factor_100, 'Memory usage should scale reasonably from 10 to 100 selectors' );
		$this->assertLessThan( 50, $scaling_factor_1000, 'Memory usage should scale reasonably from 100 to 1000 selectors' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parser_error_handling_performance() {
		$invalid_selectors = array(
			'div.',
			'div#',
			'div[',
			'div[attr',
			'div[attr=',
			'div[attr="',
			'div[attr="value',
			'div >',
			'div > ',
			'div +',
			'div ~',
			'div[attr="value"i',
			'div[attr=value i',
			'div[attr=="value"]',
			'div[attr~=]',
			'div[attr^=]',
			'div[attr$=]',
			'div[attr*=]',
			'div[attr|=]',
		);

		$results = $this->benchmark( function() use ( $invalid_selectors ) {
			$selector = $invalid_selectors[ array_rand( $invalid_selectors ) ];
			$offset = 0;
			// These should all return null quickly without throwing exceptions
			WP_CSS_Complex_Selector::parse( $selector, $offset );
		} );

		$this->log_benchmark_results( 'Error Handling Performance', $results );
		$this->assertGreaterThan( 20000, $results['ops_per_second'], 'Error handling should exceed 20K ops/sec' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_real_world_selector_performance() {
		// Real-world selectors from popular frameworks and themes
		$real_world_selectors = array(
			// Bootstrap-style selectors
			'.container .row .col-md-6',
			'.navbar .navbar-nav .nav-item .nav-link',
			'.btn.btn-primary[type="submit"]',
			'.form-group .form-control',
			'.card .card-header .card-title',
			
			// WordPress theme selectors
			'.site-header .site-navigation .menu-item',
			'.site-content .entry-content p',
			'.widget-area .widget .widget-title',
			'article.post .entry-meta .posted-on',
			'.comment-list .comment .comment-meta',
			
			// Modern CSS selectors
			'main[role="main"] > section.content',
			'nav[aria-label="Main navigation"] ul',
			'button[aria-expanded="false"]',
			'input[type="email"][required]',
			'div[data-testid="component"]',
		);

		$results = $this->benchmark( function() use ( $real_world_selectors ) {
			$selector = $real_world_selectors[ array_rand( $real_world_selectors ) ];
			$offset = 0;
			WP_CSS_Complex_Selector::parse( $selector, $offset );
		} );

		$this->log_benchmark_results( 'Real World Selector Performance', $results );
		$this->assertGreaterThan( 5000, $results['ops_per_second'], 'Real world selector parsing should exceed 5K ops/sec' );
	}
}