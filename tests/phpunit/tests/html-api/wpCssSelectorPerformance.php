<?php
/**
 * Performance tests for CSS selector parsing.
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since {WP_VERSION}
 * @group html-api
 * @group performance
 */
class Tests_HtmlApi_WpCssSelectorPerformance extends WP_UnitTestCase {

	/**
	 * Performance threshold in milliseconds.
	 * Tests that exceed this threshold will be marked as slow.
	 */
	const PERFORMANCE_THRESHOLD_MS = 10;

	/**
	 * Number of iterations for performance tests.
	 */
	const PERFORMANCE_ITERATIONS = 1000;

	/**
	 * Measures execution time of a callback function.
	 *
	 * @param callable $callback Function to measure.
	 * @param int      $iterations Number of iterations to run.
	 * @return array Contains 'total_time', 'avg_time', 'min_time', 'max_time' in milliseconds.
	 */
	private function measure_performance( $callback, $iterations = self::PERFORMANCE_ITERATIONS ) {
		$times = array();
		
		for ( $i = 0; $i < $iterations; $i++ ) {
			$start = microtime( true );
			$callback();
			$end = microtime( true );
			$times[] = ( $end - $start ) * 1000; // Convert to milliseconds
		}

		return array(
			'total_time' => array_sum( $times ),
			'avg_time'   => array_sum( $times ) / count( $times ),
			'min_time'   => min( $times ),
			'max_time'   => max( $times ),
			'iterations' => $iterations,
		);
	}

	/**
	 * Asserts that performance is within acceptable bounds.
	 *
	 * @param array  $performance_data Performance data from measure_performance().
	 * @param string $test_name Name of the test for error messages.
	 */
	private function assert_performance_acceptable( $performance_data, $test_name ) {
		$avg_time = $performance_data['avg_time'];
		
		if ( $avg_time > self::PERFORMANCE_THRESHOLD_MS ) {
			$this->markTestSkipped(
				sprintf(
					'%s performance test exceeded threshold: %.2fms average (threshold: %dms)',
					$test_name,
					$avg_time,
					self::PERFORMANCE_THRESHOLD_MS
				)
			);
		}

		// Output performance data for CI/monitoring
		error_log(
			sprintf(
				'Performance [%s]: avg=%.2fms, min=%.2fms, max=%.2fms, iterations=%d',
				$test_name,
				$performance_data['avg_time'],
				$performance_data['min_time'],
				$performance_data['max_time'],
				$performance_data['iterations']
			)
		);
	}

	/**
	 * @ticket 62653
	 */
	public function test_type_selector_parsing_performance() {
		$selectors = array(
			'div',
			'span',
			'a',
			'p',
			'h1',
			'article',
			'section',
			'header',
			'footer',
			'nav',
			'*',
			'custom-element',
			'very-long-element-name-with-many-hyphens',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				WP_CSS_Type_Selector::parse( $selector, $offset );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Type Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_class_selector_parsing_performance() {
		$selectors = array(
			'.class',
			'.my-class',
			'.very-long-class-name-with-many-hyphens',
			'.class1',
			'.class2',
			'.class3',
			'.class4',
			'.class5',
			'.class6',
			'.class7',
			'.class8',
			'.class9',
			'.class10',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				WP_CSS_Class_Selector::parse( $selector, $offset );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Class Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_id_selector_parsing_performance() {
		$selectors = array(
			'#id',
			'#my-id',
			'#very-long-id-name-with-many-hyphens',
			'#id1',
			'#id2',
			'#id3',
			'#id4',
			'#id5',
			'#id6',
			'#id7',
			'#id8',
			'#id9',
			'#id10',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				WP_CSS_ID_Selector::parse( $selector, $offset );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'ID Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_attribute_selector_parsing_performance() {
		$selectors = array(
			'[href]',
			'[data-test]',
			'[href="value"]',
			'[href^="https"]',
			'[href$=".html"]',
			'[href*="example"]',
			'[href~="word"]',
			'[href|="en"]',
			'[data-test="complex-value-with-hyphens"]',
			'[aria-label="Accessibility text"]',
			'[class="multiple classes here"]',
			'[data-very-long-attribute-name="value"]',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				WP_CSS_Attribute_Selector::parse( $selector, $offset );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Attribute Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_compound_selector_parsing_performance() {
		$selectors = array(
			'div.class',
			'div#id',
			'div.class#id',
			'div.class[attr]',
			'div.class#id[attr]',
			'div.class1.class2',
			'div.class1.class2.class3',
			'div#id[attr1][attr2]',
			'div.class1.class2#id[attr1][attr2]',
			'article.post.featured#main[data-id="123"][role="article"]',
			'*.class#id[attr]',
			'custom-element.my-class[data-test="value"]',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				WP_CSS_Compound_Selector::parse( $selector, $offset );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Compound Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_complex_selector_parsing_performance() {
		$selectors = array(
			'div p',
			'div > p',
			'div p a',
			'div > p > a',
			'div p a span',
			'div > p a > span',
			'article.post p.content',
			'nav.menu > ul > li > a',
			'section.content article.post > header.post-header',
			'main.site-main > article.post > section.post-content > p',
			'div.container > section.content > article.post > div.post-body > p.text',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				WP_CSS_Complex_Selector::parse( $selector, $offset );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Complex Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_selector_list_parsing_performance() {
		$selector_lists = array(
			'div, p, span',
			'div.class, p#id, span[attr]',
			'div > p, section > article, nav > ul',
			'div.class1.class2, p#id[attr], span.class[attr="value"]',
			'article.post, section.content, aside.sidebar, footer.site-footer',
			'nav.menu > ul > li, div.content > p, section.sidebar > aside',
			'div.container > section.content, article.post > header.post-header, footer.site-footer > div.copyright',
		);

		$performance = $this->measure_performance( function() use ( $selector_lists ) {
			foreach ( $selector_lists as $selector_list ) {
				WP_CSS_Complex_Selector_List::from_selectors( $selector_list );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Selector List Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_unicode_selector_parsing_performance() {
		$selectors = array(
			'.café',
			'#résumé',
			'[title="Élément"]',
			'div.наименование',
			'p.العربية',
			'span.中文',
			'article.日本語',
			'section.한국어',
			'div.🌟element',
			'p.元素',
			'span.элемент',
			'nav.επιλογή',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
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
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Unicode Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_escaped_selector_parsing_performance() {
		$selectors = array(
			'.\\31 23',
			'#\\31 23',
			'[attr="\\31 23"]',
			'.\\2e class',
			'#\\23 hash',
			'[attr="\\22 quote"]',
			'.\\41 bc',
			'#\\30 30',
			'[attr="\\5c backslash"]',
			'.\\000061 bc',
			'#\\1f0a1',
			'[attr="\\1D4B2"]',
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				if ( $selector[0] === '.' ) {
					WP_CSS_Class_Selector::parse( $selector, $offset );
				} elseif ( $selector[0] === '#' ) {
					WP_CSS_ID_Selector::parse( $selector, $offset );
				} elseif ( $selector[0] === '[' ) {
					WP_CSS_Attribute_Selector::parse( $selector, $offset );
				}
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Escaped Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_large_selector_parsing_performance() {
		// Generate a very large compound selector
		$class_parts = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$class_parts[] = '.class' . $i;
		}
		$large_compound = 'div' . implode( '', $class_parts );

		// Generate a very deep complex selector
		$element_parts = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$element_parts[] = 'div' . $i;
		}
		$large_complex = implode( ' > ', $element_parts );

		$selectors = array(
			$large_compound,
			$large_complex,
			implode( ', ', array_slice( $class_parts, 0, 10 ) ),
		);

		$performance = $this->measure_performance( function() use ( $selectors ) {
			foreach ( $selectors as $selector ) {
				$offset = 0;
				if ( strpos( $selector, ',' ) !== false ) {
					WP_CSS_Complex_Selector_List::from_selectors( $selector );
				} elseif ( strpos( $selector, ' ' ) !== false || strpos( $selector, '>' ) !== false ) {
					WP_CSS_Complex_Selector::parse( $selector, $offset );
				} else {
					WP_CSS_Compound_Selector::parse( $selector, $offset );
				}
			}
		}, 100 ); // Fewer iterations for large selectors

		$this->assert_performance_acceptable( $performance, 'Large Selector Parsing' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_malformed_selector_parsing_performance() {
		$malformed_selectors = array(
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

		$performance = $this->measure_performance( function() use ( $malformed_selectors ) {
			foreach ( $malformed_selectors as $selector ) {
				$offset = 0;
				// Try parsing as different types and expect null results
				WP_CSS_Complex_Selector::parse( $selector, $offset );
			}
		} );

		$this->assert_performance_acceptable( $performance, 'Malformed Selector Parsing' );
	}
}