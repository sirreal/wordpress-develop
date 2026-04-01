<?php
/**
 * Test WP_Font_Utils::normalize_css_font_face_font_family().
 *
 * @package WordPress
 * @subpackage Font Library
 *
 * @group fonts
 * @group font-library
 *
 * @covers WP_Font_Utils::normalize_css_font_face_font_family
 */
class Tests_Fonts_WpFontUtils_normalizeCssFontFaceFontFamily extends WP_UnitTestCase {
	/**
	 * @ticket TBD
	 *
	 * @dataProvider data_provider
	 */
	public function test_normalize_css_font_face_font_family( string $input, ?string $expected ): void {
		$result = WP_Font_Utils::normalize_css_font_face_font_family( $input );
		$this->assertSame( $expected, $result );

		// Idempotency check.
		if ( null !== $result ) {
			$this->assertSame( $result, WP_Font_Utils::normalize_css_font_face_font_family( $result ) );
		}
	}

	/**
	 * Data provider.
	 */
	public static function data_provider(): Generator {
		// Valid CSS font-family
		yield 'Already normalized' => array( '"Font"', '"Font"' );
		yield 'Unquoted' => array( 'Font', '"Font"' );
		yield 'Multiple idents' => array( 'Font Name', '"Font Name"' );
		yield 'Idents and whitespace normalized' => array( "A\nB\rC\r\nD\tE\fF", '"A B C D E F"' );
		yield 'Ident escapes normalized' => array( 'F\\o\\n\\74  \\34 2\\21', '"Font 42!"' );
		yield 'String escapes normalized' => array( '"F\\o\\n\\74  \\"\\34 2\\21\\""', '"Font \\22 42!\\22 "' );

		// Discard excess
		yield 'String + ident' => array( '"string"strip', '"string"' );
		yield 'String + number' => array( '"string"0', '"string"' );
		yield 'String + ,' => array( '"string",', '"string"' );
		yield 'Ident + ,' => array( 'ident,', '"ident"' );

		// Invalid CSS font-family is treated as a plain string.
		yield 'Input with stray single quote' => array( "O'er the rainbow", '"O\27 er the rainbow"' );
		yield 'Input with stray double quote' => array( 'Oop"sie', '"Oop\22 sie"' );
		yield 'Number' => array( 'Libre Barcode 128 Text', '"Libre Barcode 128 Text"' );
		yield 'PX unit number' => array( '10px rest', '"10px rest"' );
		yield '% unit number' => array( '10px rest', '"10px rest"' );
		yield 'Weird unit number' => array( '20xYz rest', '"20xYz rest"' );
		yield 'Negative number' => array( '-30deg rest', '"-30deg rest"' );
		yield 'Positive number' => array( '+40rad rest', '"+40rad rest"' );
		yield 'Whitespace is trimmed' => array( " \t\n\r\f Oh no 42 \t\f\r\n", '"Oh no 42"' );
	}
}
