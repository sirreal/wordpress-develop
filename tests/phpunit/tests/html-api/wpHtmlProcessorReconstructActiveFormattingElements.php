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

	/**
	 * Verifies that get_attribute_names_with_prefix() returns correct values for reconstructed elements.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::get_attribute_names_with_prefix
	 */
	public function test_get_attribute_names_with_prefix_works_for_reconstructed_element() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b id="x" class="y" data-test="z">text<p>more' );

		// Navigate past the first paragraph.
		$processor->next_tag( 'P' );
		$processor->next_tag( 'B' );

		// Navigate to second paragraph (triggers reconstruction).
		$processor->next_tag( 'P' );

		// Find the reconstructed B and verify its attribute names.
		$this->assertTrue( $processor->next_tag( 'B' ), 'Failed to find reconstructed B.' );

		// All attributes (empty prefix).
		$all_attributes = $processor->get_attribute_names_with_prefix( '' );
		$this->assertIsArray( $all_attributes, 'Should return array of attribute names.' );
		$this->assertCount( 3, $all_attributes, 'Should have 3 attributes.' );
		$this->assertContains( 'id', $all_attributes, 'Should contain id attribute.' );
		$this->assertContains( 'class', $all_attributes, 'Should contain class attribute.' );
		$this->assertContains( 'data-test', $all_attributes, 'Should contain data-test attribute.' );

		// Prefix filter.
		$data_attributes = $processor->get_attribute_names_with_prefix( 'data-' );
		$this->assertIsArray( $data_attributes, 'Should return array for data- prefix.' );
		$this->assertCount( 1, $data_attributes, 'Should have 1 data- attribute.' );
		$this->assertContains( 'data-test', $data_attributes, 'Should contain data-test attribute.' );

		// Non-matching prefix.
		$aria_attributes = $processor->get_attribute_names_with_prefix( 'aria-' );
		$this->assertIsArray( $aria_attributes, 'Should return array for aria- prefix.' );
		$this->assertCount( 0, $aria_attributes, 'Should have 0 aria- attributes.' );
	}

	/**
	 * Verifies that get_qualified_attribute_name() returns correct values for reconstructed elements.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Processor::get_qualified_attribute_name
	 */
	public function test_get_qualified_attribute_name_works_for_reconstructed_element() {
		$processor = WP_HTML_Processor::create_fragment( '<p><b id="x" class="y" DATA-TEST="z">text<p>more' );

		// Navigate past the first paragraph.
		$processor->next_tag( 'P' );
		$processor->next_tag( 'B' );

		// Navigate to second paragraph (triggers reconstruction).
		$processor->next_tag( 'P' );

		// Find the reconstructed B and verify its qualified attribute names.
		$this->assertTrue( $processor->next_tag( 'B' ), 'Failed to find reconstructed B.' );

		// Attribute names should be lowercase.
		$this->assertSame( 'id', $processor->get_qualified_attribute_name( 'id' ), 'Should return lowercase attribute name.' );
		$this->assertSame( 'class', $processor->get_qualified_attribute_name( 'class' ), 'Should return lowercase attribute name.' );
		$this->assertSame( 'data-test', $processor->get_qualified_attribute_name( 'DATA-TEST' ), 'Should return lowercase attribute name.' );

		// Non-existent attribute should return null.
		$this->assertNull( $processor->get_qualified_attribute_name( 'nonexistent' ), 'Non-existent attribute should return null.' );
	}

	/**
	 * Verifies that Noah's Ark clause limits identical elements to 3.
	 *
	 * When more than 3 identical formatting elements are pushed to the active
	 * formatting elements list, the earliest duplicate should be removed.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Active_Formatting_Elements::push
	 */
	public function test_noahs_ark_limits_identical_elements_to_three() {
		// Four identical <b> tags, only 3 should be reconstructed.
		$processor = WP_HTML_Processor::create_fragment( '<p><b><b><b><b><p><span target>' );

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		// Breadcrumbs should show only 3 B elements reconstructed.
		$breadcrumbs = $processor->get_breadcrumbs();
		$b_count     = count( array_filter( $breadcrumbs, fn( $tag ) => 'B' === $tag ) );

		$this->assertSame( 3, $b_count, "Noah's Ark should limit to 3 identical formatting elements." );
	}

	/**
	 * Verifies that elements with different attributes are not considered identical.
	 *
	 * The Noah's Ark clause only removes duplicate elements with the same
	 * tag name, namespace, and attributes. Elements with different attributes
	 * should all be preserved.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Active_Formatting_Elements::push
	 */
	public function test_noahs_ark_different_attributes_are_different_elements() {
		// Four <b> elements with different classes - all should be reconstructed.
		$processor = WP_HTML_Processor::create_fragment(
			'<p><b class="a"><b class="b"><b class="c"><b class="d"><p><span target>'
		);

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		// All 4 should be reconstructed since they have different attributes.
		$breadcrumbs = $processor->get_breadcrumbs();
		$b_count     = count( array_filter( $breadcrumbs, fn( $tag ) => 'B' === $tag ) );

		$this->assertSame( 4, $b_count, 'Elements with different attributes should all be reconstructed.' );
	}

	/**
	 * Verifies that Noah's Ark respects markers in the active formatting elements list.
	 *
	 * When a marker is present (while inside BUTTON, TD, etc.), Noah's Ark only
	 * considers elements after the last marker. This test verifies the behavior
	 * by having identical elements both inside and outside a scoped element.
	 *
	 * Note: When the button closes, the marker is removed via clear_up_to_last_marker(),
	 * so after the button, all elements are considered together again.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Active_Formatting_Elements::push
	 */
	public function test_noahs_ark_respects_markers() {
		// Two <b> elements inside a BUTTON (marker separates them during push).
		// Inside the button, only those 2 count toward Noah's Ark limit.
		// Then 2 more <b> after the button. After button closes, marker is gone,
		// so all 4 identical B elements are counted, and Noah's Ark reduces to 3.
		$processor = WP_HTML_Processor::create_fragment(
			'<p><b><b><button></button><b><b><p><span target>'
		);

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		// After button closes, marker is removed, so Noah's Ark sees all 4 identical B elements.
		// It removes the earliest, leaving 3.
		$breadcrumbs = $processor->get_breadcrumbs();
		$b_count     = count( array_filter( $breadcrumbs, fn( $tag ) => 'B' === $tag ) );

		$this->assertSame( 3, $b_count, "After button closes, marker is removed, so Noah's Ark limits all identical elements to 3." );
	}

	/**
	 * Verifies that attribute order does not affect Noah's Ark comparison.
	 *
	 * Two elements with the same attributes in different order should be
	 * considered identical for Noah's Ark purposes.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Active_Formatting_Elements::push
	 */
	public function test_noahs_ark_attribute_order_independent() {
		// Four <b> elements with same attributes but different order - should be limited to 3.
		$processor = WP_HTML_Processor::create_fragment(
			'<p><b class="x" id="y"><b id="y" class="x"><b class="x" id="y"><b id="y" class="x"><p><span target>'
		);

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		// Only 3 should be reconstructed since they are identical.
		$breadcrumbs = $processor->get_breadcrumbs();
		$b_count     = count( array_filter( $breadcrumbs, fn( $tag ) => 'B' === $tag ) );

		$this->assertSame( 3, $b_count, 'Same attributes in different order should be considered identical.' );
	}

	/**
	 * Verifies that different attribute values make elements non-identical.
	 *
	 * @ticket 62357
	 *
	 * @covers WP_HTML_Active_Formatting_Elements::push
	 */
	public function test_noahs_ark_different_attribute_values_are_different_elements() {
		// Four <b> elements with same attribute name but different values.
		$processor = WP_HTML_Processor::create_fragment(
			'<p><b class="a"><b class="A"><b class="a "><b class=""><p><span target>'
		);

		$this->assertTrue(
			$processor->next_tag( array( 'tag_name' => 'SPAN' ) ),
			'Should have found the target SPAN element.'
		);

		// All 4 should be reconstructed since they have different attribute values.
		$breadcrumbs = $processor->get_breadcrumbs();
		$b_count     = count( array_filter( $breadcrumbs, fn( $tag ) => 'B' === $tag ) );

		$this->assertSame( 4, $b_count, 'Elements with different attribute values should all be reconstructed.' );
	}
}
