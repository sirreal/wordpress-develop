<?php
/**
 * Unit tests covering WP_HTML_Processor handling of active formatting elements:
 * their reconstruction and the adoption agency algorithm.
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @since 7.1.0
 *
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Processor
 */
class Tests_HtmlApi_WpHtmlProcessorActiveFormattingElements extends WP_UnitTestCase {
	/**
	 * Ensures that active formats are properly reconstructed when visiting text nodes,
	 * verifying that the proper breadcrumbs are maintained when scanning through HTML.
	 *
	 * The use of the SOURCE element is important here because most elements
	 * will also trigger reconstruction, which would conflate the test results
	 * with the text node triggering reconstruction. The SOURCE element won't
	 * do this, making it neutral. Therefore, the implicitly-closed B element
	 * will only be reconstructed by the text node.
	 *
	 * @ticket 60455
	 *
	 * @covers ::reconstruct_active_formatting_elements
	 */
	public function test_reconstructs_active_formats_on_text_nodes() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>One<p><source>Two<source>' );

		// The SOURCE element doesn't trigger reconstruction, and this test asserts that.
		$this->assertTrue(
			$processor->next_tag( 'SOURCE' ),
			'Should have found the first SOURCE element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'SOURCE' ),
			$processor->get_breadcrumbs(),
			'Should have closed formatting element at first P element.'
		);

		$this->assertTrue(
			$processor->next_tag( 'SOURCE' ),
			'Should have found the second SOURCE element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'SOURCE' ),
			$processor->get_breadcrumbs(),
			'Should have reconstructed the implicitly-closed B element for the text node.'
		);
	}

	/**
	 * Ensures that reconstructed formatting elements report the attributes
	 * of the tag which created the element being reconstructed.
	 *
	 * @ticket 58517
	 *
	 * @covers ::get_attribute
	 * @covers ::get_attribute_names_with_prefix
	 * @covers ::has_class
	 * @covers ::class_list
	 */
	public function test_reconstructed_formatting_element_reports_original_attributes() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b class="bold" data-test="1&amp;2">inside</p>outside' );

		$this->assertTrue( $processor->next_tag( 'B' ), 'Should have found the original B element.' );
		$this->assertTrue( $processor->next_tag( 'B' ), 'Should have found the reconstructed B element.' );

		$this->assertSame(
			array( 'HTML', 'BODY', 'B' ),
			$processor->get_breadcrumbs(),
			'Should have reconstructed the B element outside of the closed P element.'
		);

		$this->assertSame(
			'bold',
			$processor->get_attribute( 'class' ),
			'Should have read the "class" attribute from the source tag of the reconstructed element.'
		);

		$this->assertSame(
			'1&2',
			$processor->get_attribute( 'data-test' ),
			'Should have decoded the attribute value from the source tag of the reconstructed element.'
		);

		$this->assertSame(
			array( 'class', 'data-test' ),
			$processor->get_attribute_names_with_prefix( '' ),
			'Should have listed the attribute names from the source tag of the reconstructed element.'
		);

		$this->assertTrue(
			$processor->has_class( 'bold' ),
			'Should have found the class name on the reconstructed element.'
		);

		$this->assertSame(
			array( 'bold' ),
			iterator_to_array( $processor->class_list() ),
			'Should have listed the class names of the reconstructed element.'
		);
	}

	/**
	 * Ensures that reconstructed formatting elements cannot be modified.
	 *
	 * Reconstructed elements don't exist in the input HTML: there is no tag
	 * to modify. Writing to one could otherwise corrupt the source tag of
	 * the original element, which is a distinct node.
	 *
	 * @ticket 58517
	 *
	 * @covers ::set_attribute
	 */
	public function test_reconstructed_formatting_element_cannot_be_modified() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b class="bold">inside</p>outside' );

		$this->assertTrue( $processor->next_tag( 'B' ), 'Should have found the original B element.' );
		$this->assertTrue( $processor->next_tag( 'B' ), 'Should have found the reconstructed B element.' );

		$this->assertFalse(
			$processor->set_attribute( 'id', 'not-writable' ),
			'Should have refused to set an attribute on a reconstructed element.'
		);

		$this->assertFalse(
			$processor->remove_attribute( 'class' ),
			'Should have refused to remove an attribute from a reconstructed element.'
		);
	}

	/**
	 * Ensures that the "Noah's Ark clause" limits reconstruction to three
	 * equivalent formatting elements.
	 *
	 * @ticket 58517
	 *
	 * @covers ::push_onto_active_formatting_elements
	 */
	public function test_noahs_ark_clause_limits_equivalent_formatting_elements() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b><b><b><b>first<p>second' );

		while ( $processor->next_token() && 'second' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'B', 'B', '#text' ),
			$processor->get_breadcrumbs(),
			'Should have reconstructed only three of the four equivalent B elements.'
		);
	}

	/**
	 * Ensures that the "Noah's Ark clause" compares attributes and does not
	 * remove formatting elements whose attributes differ.
	 *
	 * @ticket 58517
	 *
	 * @covers ::push_onto_active_formatting_elements
	 */
	public function test_noahs_ark_clause_compares_attributes() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b class="a"><b class="b"><b class="c"><b class="d">first<p>second' );

		while ( $processor->next_token() && 'second' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'B', 'B', 'B', '#text' ),
			$processor->get_breadcrumbs(),
			'Should have reconstructed all four B elements since their attributes differ.'
		);
	}

	/**
	 * Ensures that the adoption agency algorithm closes and reopens formatting
	 * elements when a formatting element is closed while non-formatting elements
	 * remain open, and that content which follows is reported with the ancestor
	 * chain a browser would report.
	 *
	 * @ticket 58517
	 *
	 * @covers ::run_adoption_agency_algorithm
	 */
	public function test_adoption_agency_no_furthest_block() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>1<i>2</b>3' );

		while ( $processor->next_token() && '3' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'I', '#text' ),
			$processor->get_breadcrumbs(),
			'Should have closed the B element and reconstructed the I element around the following text.'
		);
	}

	/**
	 * Ensures that the adoption agency algorithm handles the "furthest block"
	 * case: content following the misnested closing tag must be found in the
	 * same ancestor chain a browser would report for it.
	 *
	 * @ticket 58517
	 *
	 * @covers ::run_adoption_agency_algorithm
	 */
	public function test_adoption_agency_with_furthest_block() {
		$processor = WP_HTML_Processor::create_fragment( '<b>1<p>2</b>3' );

		while ( $processor->next_token() && '3' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', '#text' ),
			$processor->get_breadcrumbs(),
			'Should have adopted the P element so that following text is inside it, outside the closed B.'
		);
	}

	/**
	 * Ensures that content following a deeply-misnested formatting element is
	 * reported with the ancestor chain a browser would report for it.
	 *
	 * In this document, closing the A element adopts the inner DIV: browsers
	 * re-parent it under clones of the formatting elements U, I, and CODE.
	 * Content following the misnesting must be found at the same path.
	 *
	 * @ticket 58517
	 *
	 * @covers ::run_adoption_agency_algorithm
	 */
	public function test_adoption_agency_deep_misnesting() {
		$processor = WP_HTML_Processor::create_fragment( '<div><a><b><u><i><code><div></a>x' );

		while ( $processor->next_token() && 'x' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertSame(
			array( 'HTML', 'BODY', 'DIV', 'U', 'I', 'CODE', 'DIV', '#text' ),
			$processor->get_breadcrumbs(),
			'Should have reported following text with the ancestor chain a browser would produce.'
		);
	}

	/**
	 * Ensures that a closing tag for a formatting element which is not an
	 * active format is ignored, as directed by the "any other end tag"
	 * fallback of the adoption agency algorithm.
	 *
	 * @ticket 58517
	 *
	 * @covers ::run_adoption_agency_algorithm
	 * @covers ::in_body_any_other_end_tag
	 */
	public function test_adoption_agency_ignores_unopened_formatting_end_tag() {
		$processor = WP_HTML_Processor::create_fragment( '<p>text</b>more' );

		while ( $processor->next_token() && 'more' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertNull( $processor->get_last_error(), 'Should have parsed the entire document without error.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'P', '#text' ),
			$processor->get_breadcrumbs(),
			'Should have ignored the stray closing tag and continued inside the P element.'
		);
	}

	/**
	 * Ensures that the adoption agency algorithm expresses its rearrangement
	 * of the stack of open elements as a properly-nested stream of tokens.
	 *
	 * A browser parsing this document produces the following tree, in which
	 * the P element is re-parented out of the B element it started in, and a
	 * clone of the B element wraps the P element's earlier content:
	 *
	 *     <b>1</b><p><b>2</b>3</p>
	 *
	 * A single-pass parser cannot re-parent content it has already reported.
	 * Instead, when the misnesting is discovered at the closing B tag, the
	 * open elements are closed and reopened so that every token which follows
	 * is reported with browser-accurate breadcrumbs. This test pins down that
	 * event stream.
	 *
	 * @ticket 58517
	 *
	 * @covers ::run_adoption_agency_algorithm
	 */
	public function test_adoption_agency_event_stream_remains_properly_nested() {
		$processor = WP_HTML_Processor::create_fragment( '<b>1<p>2</b>3' );

		$events = array();
		while ( $processor->next_token() ) {
			$events[] = array(
				( $processor->is_tag_closer() ? '-' : '+' ) . $processor->get_token_name(),
				implode( ' ', $processor->get_breadcrumbs() ),
			);
		}

		$this->assertNull( $processor->get_last_error(), 'Should have parsed the entire document without error.' );
		$this->assertSame(
			array(
				array( '+B', 'HTML BODY B' ),
				array( '+#text', 'HTML BODY B #text' ),
				array( '+P', 'HTML BODY B P' ),
				array( '+#text', 'HTML BODY B P #text' ),
				array( '-P', 'HTML BODY B' ),
				array( '-B', 'HTML BODY' ),
				array( '+P', 'HTML BODY P' ),
				array( '+B', 'HTML BODY P B' ),
				array( '-B', 'HTML BODY P' ),
				array( '+#text', 'HTML BODY P #text' ),
				array( '-P', 'HTML BODY' ),
			),
			$events,
			'Should have expressed the adoption as a properly-nested stream of opening and closing events.'
		);
	}

	/**
	 * Ensures that a new A element implicitly closes an open A element, even
	 * when the open element cannot be reached by generating end tags.
	 *
	 * @ticket 58517
	 *
	 * @covers ::run_adoption_agency_algorithm
	 */
	public function test_a_implicitly_closes_open_a() {
		$processor = WP_HTML_Processor::create_fragment( '<a href="/first">1<a href="/second">2' );

		$this->assertTrue( $processor->next_tag( 'A' ), 'Should have found the first A element.' );
		$this->assertTrue( $processor->next_tag( 'A' ), 'Should have found the second A element.' );

		$this->assertSame(
			array( 'HTML', 'BODY', 'A' ),
			$processor->get_breadcrumbs(),
			'Should have closed the first A element before opening the second.'
		);

		$this->assertSame(
			'/second',
			$processor->get_attribute( 'href' ),
			'Should have matched the second A element.'
		);
	}

	/**
	 * Ensures that formatting elements are reconstructed with stable breadcrumbs
	 * when seeking backwards and forwards across an adoption boundary.
	 *
	 * @ticket 58517
	 *
	 * @covers ::seek
	 */
	public function test_seeking_across_adoption_produces_stable_breadcrumbs() {
		$processor = WP_HTML_Processor::create_fragment( '<b>1<p bookmark-target>2</b>3' );

		$this->assertTrue( $processor->next_tag( 'P' ), 'Should have found the P element.' );
		$this->assertTrue( $processor->set_bookmark( 'p' ), 'Should have set a bookmark on the P element.' );

		$first_pass = array();
		while ( $processor->next_token() ) {
			$first_pass[] = array( $processor->get_token_name(), $processor->get_breadcrumbs() );
		}
		$this->assertNull( $processor->get_last_error(), 'Should have parsed the entire document without error.' );

		$this->assertTrue( $processor->seek( 'p' ), 'Should have sought back to the P element.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'B', 'P' ),
			$processor->get_breadcrumbs(),
			'Should have restored the original breadcrumbs at the bookmarked element.'
		);

		$second_pass = array();
		while ( $processor->next_token() ) {
			$second_pass[] = array( $processor->get_token_name(), $processor->get_breadcrumbs() );
		}

		$this->assertSame( $first_pass, $second_pass, 'Should have reported identical tokens after seeking back.' );
	}
}
