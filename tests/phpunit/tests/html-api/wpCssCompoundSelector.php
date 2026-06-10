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
		$offset = 0;
		$sel    = WP_CSS_Compound_Selector::parse( $input, $offset );

		$this->assertSame( 'el', $sel->type_selector->type );
		$this->assertSame( 3, count( $sel->subclass_selectors ) );
		$this->assertSame( 'foo', $sel->subclass_selectors[0]->class_name, 'foo' );
		$this->assertSame( 'bar', $sel->subclass_selectors[1]->id, 'bar' );
		$this->assertSame( 'baz', $sel->subclass_selectors[2]->name, 'baz' );
		$this->assertSame( WP_CSS_Attribute_Selector::MATCH_EXACT, $sel->subclass_selectors[2]->matcher );
		$this->assertSame( 'quux', $sel->subclass_selectors[2]->value );
		$this->assertSame( ' > .child', substr( $input, $offset ) );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_empty_selector() {
		$input  = '';
		$offset = 0;
		$result = WP_CSS_Compound_Selector::parse( $input, $offset );
		$this->assertNull( $result );
		$this->assertSame( 0, $offset );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_complex_compound_selector() {
		$input  = 'div#main.container.large[data-test="value"][role="button"][aria-expanded="false"]';
		$offset = 0;
		$sel    = WP_CSS_Compound_Selector::parse( $input, $offset );

		$this->assertNotNull( $sel );
		$this->assertSame( 'div', $sel->type_selector->type );
		$this->assertSame( 6, count( $sel->subclass_selectors ) );

		// Check ID selector
		$this->assertSame( 'main', $sel->subclass_selectors[0]->id );

		// Check class selectors
		$this->assertSame( 'container', $sel->subclass_selectors[1]->class_name );
		$this->assertSame( 'large', $sel->subclass_selectors[2]->class_name );

		// Check attribute selectors
		$this->assertSame( 'data-test', $sel->subclass_selectors[3]->name );
		$this->assertSame( 'value', $sel->subclass_selectors[3]->value );
		$this->assertSame( 'role', $sel->subclass_selectors[4]->name );
		$this->assertSame( 'button', $sel->subclass_selectors[4]->value );
		$this->assertSame( 'aria-expanded', $sel->subclass_selectors[5]->name );
		$this->assertSame( 'false', $sel->subclass_selectors[5]->value );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_selector_with_only_subclass_selectors() {
		$input  = '.class1.class2#id[attr="value"]';
		$offset = 0;
		$sel    = WP_CSS_Compound_Selector::parse( $input, $offset );

		$this->assertNotNull( $sel );
		$this->assertNull( $sel->type_selector );
		$this->assertSame( 4, count( $sel->subclass_selectors ) );
	}

	/**
	 * @ticket 62653
	 */
	public function test_parse_universal_selector_with_subclass() {
		$input  = '*.class#id[attr]';
		$offset = 0;
		$sel    = WP_CSS_Compound_Selector::parse( $input, $offset );

		$this->assertNotNull( $sel );
		$this->assertSame( '*', $sel->type_selector->type );
		$this->assertSame( 3, count( $sel->subclass_selectors ) );
	}

	/**
	 * @ticket 62653
	 * @dataProvider data_unsupported_pseudo_selectors
	 */
	public function test_parse_unsupported_pseudo_selectors( $input, $expected_type, $expected_offset ) {
		$offset = 0;
		$sel    = WP_CSS_Compound_Selector::parse( $input, $offset );

		if ( null === $expected_type ) {
			$this->assertNull( $sel );
		} else {
			$this->assertNotNull( $sel );
			$this->assertSame( $expected_type, $sel->type_selector->type );
		}
		$this->assertSame( $expected_offset, $offset );
	}

	/**
	 * Data provider for unsupported pseudo-selectors.
	 *
	 * @return array
	 */
	public static function data_unsupported_pseudo_selectors(): array {
		return array(
			// Pseudo-classes that should be rejected
			'pseudo-class :hover'           => array( 'a:hover', 'a', 1 ),
			'pseudo-class :focus'           => array( 'input:focus', 'input', 5 ),
			'pseudo-class :active'          => array( 'button:active', 'button', 6 ),
			'pseudo-class :visited'         => array( 'a:visited', 'a', 1 ),
			'pseudo-class :nth-child'       => array( 'p:nth-child(2)', 'p', 1 ),
			'pseudo-class :first-child'     => array( 'li:first-child', 'li', 2 ),
			'pseudo-class :last-child'      => array( 'li:last-child', 'li', 2 ),
			'pseudo-class :not'             => array( 'div:not(.class)', 'div', 3 ),
			'pseudo-class :is'              => array( 'div:is(.class)', 'div', 3 ),
			'pseudo-class :where'           => array( 'div:where(.class)', 'div', 3 ),
			'pseudo-class :has'             => array( 'div:has(.class)', 'div', 3 ),
			'pseudo-class :root'            => array( 'html:root', 'html', 4 ),
			'pseudo-class :empty'           => array( 'div:empty', 'div', 3 ),
			'pseudo-class :target'          => array( 'div:target', 'div', 3 ),
			'pseudo-class :lang'            => array( 'div:lang(en)', 'div', 3 ),
			'pseudo-class :dir'             => array( 'div:dir(ltr)', 'div', 3 ),
			'pseudo-class :checked'         => array( 'input:checked', 'input', 5 ),
			'pseudo-class :disabled'        => array( 'input:disabled', 'input', 5 ),
			'pseudo-class :enabled'         => array( 'input:enabled', 'input', 5 ),
			'pseudo-class :required'        => array( 'input:required', 'input', 5 ),
			'pseudo-class :optional'        => array( 'input:optional', 'input', 5 ),
			'pseudo-class :valid'           => array( 'input:valid', 'input', 5 ),
			'pseudo-class :invalid'         => array( 'input:invalid', 'input', 5 ),

			// Pseudo-elements that should be rejected
			'pseudo-element ::before'       => array( 'div::before', 'div', 3 ),
			'pseudo-element ::after'        => array( 'div::after', 'div', 3 ),
			'pseudo-element ::first-line'   => array( 'p::first-line', 'p', 1 ),
			'pseudo-element ::first-letter' => array( 'p::first-letter', 'p', 1 ),
			'pseudo-element ::selection'    => array( 'p::selection', 'p', 1 ),
			'pseudo-element ::backdrop'     => array( 'dialog::backdrop', 'dialog', 6 ),
			'pseudo-element ::placeholder'  => array( 'input::placeholder', 'input', 5 ),
			'pseudo-element ::marker'       => array( 'li::marker', 'li', 2 ),
			'pseudo-element ::cue'          => array( 'video::cue', 'video', 5 ),
			'pseudo-element ::slotted'      => array( 'slot::slotted(.class)', 'slot', 4 ),

			// Legacy single-colon pseudo-elements
			'legacy :before'                => array( 'div:before', 'div', 3 ),
			'legacy :after'                 => array( 'div:after', 'div', 3 ),
			'legacy :first-line'            => array( 'p:first-line', 'p', 1 ),
			'legacy :first-letter'          => array( 'p:first-letter', 'p', 1 ),

			// Invalid pseudo-selectors
			'invalid ::'                    => array( 'div::', 'div', 3 ),
			'invalid : alone'               => array( 'div: ', 'div', 3 ),
			'invalid :123'                  => array( 'div:123', 'div', 3 ),
			'invalid :@#$'                  => array( 'div:@#$', 'div', 3 ),
		);
	}
}
