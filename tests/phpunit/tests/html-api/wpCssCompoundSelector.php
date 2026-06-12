<?php
/**
 * Unit tests covering WP_CSS_Compound_Selector functionality.
 *
 * @package WordPress
 *
 * @subpackage HTML-API
 *
 * @since {WP_VERSION}
 *
 * @group html-api
 *
 * @coversDefaultClass WP_CSS_Compound_Selector
 */
class Tests_HtmlApi_WpCssCompoundSelector extends WP_UnitTestCase {
	/**
	 * @ticket 62653
	 */
	public function test_parse_selector() {
		$input  = 'el.foo#bar[baz=quux] > .child';
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( $input, WP_CSS_Compound_Selector::class );
		$sel    = WP_CSS_Compound_Selector::parse( $tokens );

		$this->assertSame( 'el', $sel->type_selector->type );
		$this->assertSame( 3, count( $sel->subclass_selectors ) );
		$this->assertSame( 'foo', $sel->subclass_selectors[0]->class_name, 'foo' );
		$this->assertSame( 'bar', $sel->subclass_selectors[1]->id, 'bar' );
		$this->assertSame( 'baz', $sel->subclass_selectors[2]->name, 'baz' );
		$this->assertSame( WP_CSS_Attribute_Selector::MATCH_EXACT, $sel->subclass_selectors[2]->matcher );
		$this->assertSame( 'quux', $sel->subclass_selectors[2]->value );
		$this->assertSame( ' > .child', $tokens->get_remaining_text() );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_empty_selector() {
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( '', WP_CSS_Compound_Selector::class );
		$result = WP_CSS_Compound_Selector::parse( $tokens );
		$this->assertNull( $result );
		$this->assertSame( '', $tokens->get_remaining_text() );
	}
}
