<?php

/**
 * Tests for the WP_CSS_Builder class.
 *
 * @group css-api
 *
 * @coversDefaultClass WP_CSS_Builder
 */
class Tests_CssApi_WpCssBuilder extends WP_UnitTestCase {
	/**
	 * @dataProvider data_string_escaping
	 *
	 * @covers ::string
	 */
	public function test_string_escaping( string $input, string $expected ): void {
		$this->assertSame( $expected, WP_CSS_Builder::string( $input ) );
	}

	/**
	 * Data provider for individual escape mappings and basic cases.
	 *
	 * @return array
	 */
	public static function data_string_escaping(): array {
		return array(
			// Basic behavior.
			'empty string'          => array( '', '""' ),
			'simple ASCII'          => array( 'hello', '"hello"' ),
			'spaces preserved'      => array( 'hello world', '"hello world"' ),
			'numbers pass through'  => array( '12345', '"12345"' ),
			'non-ASCII passthrough' => array( 'café', '"café"' ),

			// Backslash escaping.
			'single backslash'      => array( '\\', '"\5C "' ),
			'double backslash'      => array( '\\\\', '"\5C \5C "' ),

			// NULL byte.
			'null byte'             => array( "\0", "\"\u{FFFD}\"" ),

			// Newline variants.
			'LF'                    => array( "\n", '"\A "' ),
			'CR'                    => array( "\r", '"\A "' ),
			'CRLF'                  => array( "\r\n", '"\A "' ),
			'form feed'             => array( "\f", '"\A "' ),

			// HTML-problematic characters.
			'less than'             => array( '<', '"\3C "' ),
			'greater than'          => array( '>', '"\3E "' ),
			'ampersand'             => array( '&', '"\26 "' ),

			// CSS-problematic characters.
			'comma'                 => array( ',', '"\2C "' ),
			'semicolon'             => array( ';', '"\3B "' ),
			'open brace'            => array( '{', '"\7B "' ),
			'close brace'           => array( '}', '"\7D "' ),
			'double quote'          => array( '"', '"\22 "' ),
			'single quote'          => array( "'", '"\27 "' ),
		);
	}

	/**
	 * Tests the example from the class docblock.
	 *
	 * @covers ::string
	 */
	public function test_docblock_example(): void {
		$value    = 'CSS & a "<style>" tag\'s strings';
		$expected = '"CSS \26  a \22 \3C style\3E \22  tag\27 s strings"';

		$this->assertSame( $expected, WP_CSS_Builder::string( $value ) );
	}

	/**
	 * Tests a string containing mixed newline types.
	 *
	 * @covers ::string
	 */
	public function test_mixed_newlines(): void {
		$input    = "line1\nline2\rline3\r\nline4\fline5";
		$expected = '"line1\A line2\A line3\A line4\A line5"';

		$this->assertSame( $expected, WP_CSS_Builder::string( $input ) );
	}
}
