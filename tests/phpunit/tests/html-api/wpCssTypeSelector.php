<?php
/**
 * Unit tests covering WP_CSS_Type_Selector functionality.
 *
 * @package WordPress
 *
 * @subpackage HTML-API
 *
 * @since {WP_VERSION}
 *
 * @group html-api
 *
 * @coversDefaultClass WP_CSS_Type_Selector
 */
class Tests_HtmlApi_WpCssTypeSelector extends WP_UnitTestCase {
	/**
	 * @ticket 62653
	 *
	 * @dataProvider data_type_selectors
	 */
	public function test_parse_type( string $input, ?string $expected = null, ?string $rest = null ) {
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( $input, WP_CSS_Type_Selector::class );
		$result = WP_CSS_Type_Selector::parse( $tokens );
		if ( null === $expected ) {
			$this->assertNull( $result );
		} else {
			$this->assertSame( $expected, $result->type );
			$this->assertSame( $rest, $tokens->get_remaining_text() );
		}
	}

	/**
	 * @ticket 62653
	 *
	 * @dataProvider data_escaped_asterisk_type_selectors
	 */
	public function test_escaped_asterisk_is_type_selector_not_universal( string $input ) {
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( $input, WP_CSS_Type_Selector::class );
		$result = WP_CSS_Type_Selector::parse( $tokens );

		$this->assertInstanceOf( WP_CSS_Type_Selector::class, $result );
		$this->assertSame( '*', $result->type );
		$this->assertFalse( $result->matches_tag( 'DIV' ) );
		$this->assertSame( '', $tokens->get_remaining_text() );
	}

	/**
	 * @ticket 62653
	 */
	public function test_literal_asterisk_is_universal_selector() {
		$tokens = WP_CSS_Selector_Token_Stream::from_selectors( '*', WP_CSS_Type_Selector::class );
		$result = WP_CSS_Type_Selector::parse( $tokens );

		$this->assertInstanceOf( WP_CSS_Type_Selector::class, $result );
		$this->assertSame( '*', $result->type );
		$this->assertTrue( $result->matches_tag( 'DIV' ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public static function data_escaped_asterisk_type_selectors(): array {
		return array(
			'identity escape'       => array( '\\*' ),
			'lowercase hex escape' => array( '\\2a' ),
			'uppercase hex escape' => array( '\\2A' ),
			'padded hex escape'    => array( '\\00002A' ),
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public static function data_type_selectors(): array {
		return array(
			'any *'                   => array( '* .class', '*', ' .class' ),
			'a'                       => array( 'a', 'a', '' ),
			'div.class'               => array( 'div.class', 'div', '.class' ),
			'custom-type#id'          => array( 'custom-type#id', 'custom-type', '#id' ),
			'escape at EOF foo\\'     => array( 'foo\\', "foo\u{fffd}", '' ),

			// Invalid
			'Invalid: (empty string)' => array( '' ),
			'Invalid: #id'            => array( '#id' ),
			'Invalid: .class'         => array( '.class' ),
			'Invalid: [attr]'         => array( '[attr]' ),
		);
	}
}
