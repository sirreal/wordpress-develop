<?php
/**
 * Unit tests for the HTML API reconstruct active formatting elements algorithm.
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @since 6.8.0
 *
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Processor
 */
class Tests_HtmlApi_WpHtmlProcessorReconstructActiveFormattingElements extends WP_UnitTestCase {

	/**
	 * Verifies that a single formatting element is reconstructed across an
	 * implicit paragraph close.
	 *
	 * When `<b>` is implicitly closed by the second `<p>`, it should be
	 * reconstructed when processing subsequent content in the new paragraph.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstructs_single_formatting_element_across_paragraph_boundary() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>Bold<p>Still bold<span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'The B element should have been reconstructed in the second paragraph.'
		);
	}

	/**
	 * Verifies that multiple formatting elements are reconstructed in order.
	 *
	 * When multiple formatting elements are implicitly closed, they should all
	 * be reconstructed in the same order they were originally opened.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstructs_multiple_formatting_elements_in_order() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b><i>Bold italic<p>Still both<span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'I', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'Both B and I elements should have been reconstructed in order.'
		);
	}

	/**
	 * Verifies that deeply nested formatting elements are properly reconstructed.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstructs_deeply_nested_formatting_elements() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b><i><u><s>Formatted<p><span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'I', 'U', 'S', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'All formatting elements should have been reconstructed.'
		);
	}

	/**
	 * Verifies that reconstruction stops at a scope marker.
	 *
	 * When a scope marker (e.g., from a BUTTON element) is present in the
	 * active formatting elements list, reconstruction should not proceed
	 * past it. However, elements added after the marker are still active
	 * and will be reconstructed.
	 *
	 * In this test, the B is before the button (added to list), then a marker
	 * is pushed for the button, then I is added inside. When the button closes,
	 * the marker is removed. But the I is still in the active formatting list
	 * (it was never closed), so both B and I get reconstructed.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstruction_includes_elements_from_closed_scopes() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>Bold<button><i>Italic</button><p><span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		// Both B and I are in active formatting elements and need reconstruction.
		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'I', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'Both B and I should be reconstructed; I persisted after button closed.'
		);
	}

	/**
	 * Verifies that no reconstruction occurs when the last entry is already
	 * in the stack of open elements.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_no_reconstruction_when_entry_already_in_stack() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>Bold<span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'B element is already open, no reconstruction needed.'
		);
	}

	/**
	 * Verifies that reconstruction works correctly with multiple paragraphs.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstructs_across_multiple_paragraph_boundaries() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>One<p>Two<p>Three<p><span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'B element should be reconstructed even after multiple paragraph boundaries.'
		);
	}

	/**
	 * Verifies that reconstruction handles the adoption agency algorithm interaction.
	 *
	 * When a formatting element is closed by an end tag, it should be removed
	 * from the active formatting elements and not reconstructed.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_closed_formatting_element_not_reconstructed() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>Bold</b><p><span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'B element was properly closed and should not be reconstructed.'
		);
	}

	/**
	 * Verifies that reconstruction bails when an element has attributes.
	 *
	 * Verifies that attributes are cloned from the original formatting element
	 * to the reconstructed element.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstructed_element_preserves_attributes() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b class="bold">Bold<p><span target>' );

		// Navigate past the first paragraph.
		$this->assertTrue( $processor->next_tag( 'P' ), 'Failed to find first P.' );
		$this->assertTrue( $processor->next_tag( 'B' ), 'Failed to find original B.' );
		$this->assertSame( 'bold', $processor->get_attribute( 'class' ), 'Original B should have class attribute.' );

		// Navigate to second paragraph (triggers reconstruction).
		$this->assertTrue( $processor->next_tag( 'P' ), 'Failed to find second P.' );

		// Navigate to the span inside the reconstructed formatting.
		$this->assertTrue( $processor->next_tag( 'SPAN' ), 'Failed to find SPAN.' );

		// Breadcrumbs should show the reconstructed B.
		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'Breadcrumbs should include reconstructed B.'
		);
	}

	/**
	 * Verifies that elements opened in a previous paragraph are properly
	 * reconstructed when text nodes are encountered.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstructs_on_text_node() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b>Bold<p>Text here' );

		// Move through the tokens to find the text node in the second paragraph.
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() && 'Text here' === $processor->get_modifiable_text() ) {
				break;
			}
		}

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B', '#text' ),
			$processor->get_breadcrumbs(),
			'B element should be reconstructed before the text node.'
		);
	}

	/**
	 * Verifies reconstruction with interleaved block and formatting elements.
	 *
	 * When a formatting element is opened before block elements, the HTML5
	 * parsing algorithm places it in the DOM at its original location.
	 * The `<b>` is a direct child of BODY, and the DIV is a sibling to B.
	 * When entering the P, the B is still in active formatting and gets
	 * reconstructed inside the P.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_reconstructs_with_interleaved_elements() {
		$processor = WP_HTML_Processor::create_fragment( '<b>Bold<div><p>In div<span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		// The B starts as sibling of DIV, then gets reconstructed inside P.
		$this->assertSame(
			array( 'HTML', 'BODY', 'B', 'DIV', 'P', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'B element should remain in its original position in the tree.'
		);
	}

	/**
	 * Verifies that the algorithm handles empty active formatting elements list.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_handles_empty_active_formatting_elements() {
		$processor = WP_HTML_Processor::create_fragment( '<p>No formatting<p><span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'No formatting elements to reconstruct.'
		);
	}

	/**
	 * Verifies proper breadcrumbs when visiting reconstructed elements via step().
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::reconstruct_active_formatting_elements
	 */
	public function test_breadcrumbs_correct_during_stepping() {
		$processor = WP_HTML_Processor::create_fragment( '<p><em>First<p>Second</em>' );

		// Find the text "Second" which triggers reconstruction.
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() && 'Second' === $processor->get_modifiable_text() ) {
				break;
			}
		}

		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'EM', '#text' ),
			$processor->get_breadcrumbs(),
			'Breadcrumbs should show reconstructed EM element.'
		);
	}

	/**
	 * Verifies that get_attribute() returns the correct value for reconstructed elements.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::get_attribute
	 */
	public function test_get_attribute_works_for_reconstructed_element() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b class="bold">text<p>more' );

		// Navigate past the first paragraph.
		$this->assertTrue( $processor->next_tag( 'P' ), 'Failed to find first P.' );
		$this->assertTrue( $processor->next_tag( 'B' ), 'Failed to find original B.' );
		$this->assertSame( 'bold', $processor->get_attribute( 'class' ), 'Original B should have class attribute.' );

		// Navigate to second paragraph (triggers reconstruction).
		$this->assertTrue( $processor->next_tag( 'P' ), 'Failed to find second P.' );

		// Find the reconstructed B and verify its attribute.
		$this->assertTrue( $processor->next_tag( 'B' ), 'Failed to find reconstructed B.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'P', 'B' ),
			$processor->get_breadcrumbs(),
			'Should be inside the second P with reconstructed B.'
		);
		$this->assertSame( 'bold', $processor->get_attribute( 'class' ), 'Reconstructed B should have class attribute.' );
		$this->assertNull( $processor->get_attribute( 'nonexistent' ), 'Nonexistent attribute should return null.' );
	}

	/**
	 * Verifies that get_attribute() returns correct values for reconstructed elements with multiple attributes.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::get_attribute
	 */
	public function test_get_attribute_works_for_reconstructed_element_with_multiple_attributes() {
		$processor = WP_HTML_Processor::create_fragment( '<p><font size="4" color="red">text<p>more' );

		// Navigate past the first paragraph.
		$processor->next_tag( 'P' );
		$processor->next_tag( 'FONT' );

		// Navigate to second paragraph (triggers reconstruction).
		$processor->next_tag( 'P' );

		// Find the reconstructed FONT and verify its attributes.
		$this->assertTrue( $processor->next_tag( 'FONT' ), 'Failed to find reconstructed FONT.' );
		$this->assertSame( '4', $processor->get_attribute( 'size' ), 'Reconstructed FONT should have size attribute.' );
		$this->assertSame( 'red', $processor->get_attribute( 'color' ), 'Reconstructed FONT should have color attribute.' );
	}
}
