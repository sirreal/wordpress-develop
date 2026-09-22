<?php
/**
 * Unit tests covering WP_CSS_Complex_Selector functionality.
 *
 * @package WordPress
 *
 * @subpackage HTML-API
 *
 * @since {WP_VERSION}
 *
 * @group html-api
 *
 * @coversDefaultClass WP_CSS_Complex_Selector
 */
class Tests_HtmlApi_WpCssComplexSelector extends WP_UnitTestCase {
	/**
	 * @ticket 62653
	 */
	public function test_parse_complex_selector() {
		$input  = 'el1 el2 > .child#bar[baz=quux] , rest';
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( $input, WP_CSS_Complex_Selector::class );

		/** @var WP_CSS_Complex_Selector|null */
		$sel = WP_CSS_Complex_Selector::parse( $tokens );

		$this->assertSame( 2, count( $sel->context_selectors ) );

		// Relative selectors should be reverse ordered.
		$this->assertSame( 'el2', $sel->context_selectors[0][0]->type );
		$this->assertSame( WP_CSS_Complex_Selector::COMBINATOR_CHILD, $sel->context_selectors[0][1] );

		$this->assertSame( 'el1', $sel->context_selectors[1][0]->type );
		$this->assertSame( WP_CSS_Complex_Selector::COMBINATOR_DESCENDANT, $sel->context_selectors[1][1] );

		$this->assertSame( 3, count( $sel->self_selector->subclass_selectors ) );
		$this->assertNull( $sel->self_selector->type_selector );
		$this->assertSame( 'child', $sel->self_selector->subclass_selectors[0]->class_name );

		$this->assertSame( ', rest', $tokens->get_remaining_text() );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_invalid_complex_selector() {
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( 'el.foo#bar[baz=quux] > , rest', WP_CSS_Complex_Selector::class );
		$result = WP_CSS_Complex_Selector::parse( $tokens );
		$this->assertNull( $result );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_invalid_complex_selector_nonfinal_subclass() {
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( 'el.foo#bar[baz=quux] > final, rest', WP_CSS_Complex_Selector::class );
		$result = WP_CSS_Complex_Selector::parse( $tokens );
		$this->assertNull( $result );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_empty_complex_selector() {
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( '', WP_CSS_Complex_Selector::class );
		$result = WP_CSS_Complex_Selector::parse( $tokens );
		$this->assertNull( $result );
	}
}
