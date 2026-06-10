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
		$offset = 0;

		/** @var WP_CSS_Complex_Selector|null */
		$sel = WP_CSS_Complex_Selector::parse( $input, $offset );

		$this->assertSame( 2, count( $sel->context_selectors ) );

		// Relative selectors should be reverse ordered.
		$this->assertSame( 'el2', $sel->context_selectors[0][0]->type );
		$this->assertSame( WP_CSS_Complex_Selector::COMBINATOR_CHILD, $sel->context_selectors[0][1] );

		$this->assertSame( 'el1', $sel->context_selectors[1][0]->type );
		$this->assertSame( WP_CSS_Complex_Selector::COMBINATOR_DESCENDANT, $sel->context_selectors[1][1] );

		$this->assertSame( 3, count( $sel->self_selector->subclass_selectors ) );
		$this->assertNull( $sel->self_selector->type_selector );
		$this->assertSame( 'child', $sel->self_selector->subclass_selectors[0]->class_name );

		$this->assertSame( ', rest', substr( $input, $offset ) );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_invalid_complex_selector() {
		$input  = 'el.foo#bar[baz=quux] > , rest';
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );
		$this->assertNull( $result );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_invalid_complex_selector_nonfinal_subclass() {
		$input  = 'el.foo#bar[baz=quux] > final, rest';
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );
		$this->assertNull( $result );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_empty_complex_selector() {
		$input  = '';
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );
		$this->assertNull( $result );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_unsupported_next_sibling_combinator() {
		$input  = 'h1 + p';
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );
		$this->assertNull( $result, 'Next sibling combinator (+) should not be supported' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_unsupported_subsequent_sibling_combinator() {
		$input  = 'h1 ~ p';
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );
		$this->assertNull( $result, 'Subsequent sibling combinator (~) should not be supported' );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_complex_selector_with_multiple_combinators() {
		$input  = 'div > ul li > a.link';
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );

		$this->assertNotNull( $result );
		$this->assertSame( 3, count( $result->context_selectors ) );

		$this->assertInstanceOf( WP_CSS_Compound_Selector::class, $result->self_selector );
		$this->assertSame( 'a', $result->self_selector->type_selector->type );
		$this->assertSame( 'link', $result->self_selector->subclass_selectors[0]->class_name );

		// Check context selectors are in reverse order
		$this->assertSame( 3, count( $result->context_selectors ) );

		$this->assertSame( 'li', $result->context_selectors[0][0]->type );
		$this->assertSame( WP_CSS_Complex_Selector::COMBINATOR_CHILD, $result->context_selectors[0][1] );

		$this->assertSame( 'ul', $result->context_selectors[1][0]->type );
		$this->assertSame( WP_CSS_Complex_Selector::COMBINATOR_DESCENDANT, $result->context_selectors[1][1] );

		$this->assertSame( 'div', $result->context_selectors[2][0]->type );
		$this->assertSame( WP_CSS_Complex_Selector::COMBINATOR_CHILD, $result->context_selectors[2][1] );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_complex_selector_with_whitespace_variations() {
		$input  = "div\n>\t\rul   \f li\r\n>\na.link";
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );

		$this->assertNotNull( $result );
		$this->assertSame( 3, count( $result->context_selectors ) );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_invalid_trailing_combinator() {
		$input  = 'div > ul >';
		$offset = 0;
		$result = WP_CSS_Complex_Selector::parse( $input, $offset );
		$this->assertNull( $result, 'Trailing combinator should make selector invalid' );
	}
}
