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
	 * Data provider for string escaping cases.
	 *
	 * @return array
	 */
	public static function data_string_escaping(): array {
		return array(
			// Passthrough — no escaping needed, only quoting.
			'empty string'            => array( '', '""' ),
			'simple ASCII'            => array( 'Arial', '"Arial"' ),
			'spaces preserved'        => array( 'Exo 2', '"Exo 2"' ),
			'leading/trailing spaces' => array( '  Arial  ', '"  Arial  "' ),
			'whitespace-only'         => array( '   ', '"   "' ),
			'numbers pass through'    => array( '12345', '"12345"' ),
			'non-ASCII passthrough'   => array( 'café', '"café"' ),

			// Backslash escaping — must happen first to prevent double-escaping.
			'backslash'               => array( 'Back\\Slash', '"Back\5C Slash"' ),
			'double backslash'        => array( '\\\\', '"\5C \5C "' ),
			'backslash before quote'  => array( "a\\'b", '"a\5C \27 b"' ),

			// NULL byte → U+FFFD replacement character.
			'null byte'               => array( "a\0b", "\"a\u{FFFD}b\"" ),

			// Newline normalization — all variants become \A escape.
			'LF'                      => array( "a\nb", '"a\A b"' ),
			'CR'                      => array( "a\rb", '"a\A b"' ),
			'CRLF as single escape'   => array( "a\r\nb", '"a\A b"' ),
			'form feed'               => array( "a\fb", '"a\A b"' ),

			// HTML-problematic characters.
			'HTML characters < > &'   => array( 'a<b>c&d', '"a\3C b\3E c\26 d"' ),

			// CSS-problematic characters.
			'CSS syntax , ; { }'      => array( 'a,b;c{d}', '"a\2C b\3B c\7B d\7D "' ),
			'single quote'            => array( "CSS's strings", '"CSS\27 s strings"' ),
			'double quote'            => array( 'Say "Hi"', '"Say \22 Hi\22 "' ),
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
