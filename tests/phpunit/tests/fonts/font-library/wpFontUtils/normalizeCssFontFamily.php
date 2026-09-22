<?php
/**
 * Test WP_Font_Utils::normalize_css_font_family().
 *
 * @package WordPress
 * @subpackage Font Library
 *
 * @group fonts
 * @group font-library
 *
 * @covers WP_Font_Utils::normalize_css_font_family
 */
class Tests_Fonts_WpFontUtils_normalizeCssFontFamily extends WP_UnitTestCase {
	/**
	 * @ticket TBD
	 *
	 * @dataProvider data_provider
	 */
	public function test_normalize_css_font_family( string $input, string $expected ): void {
		$result = WP_Font_Utils::normalize_css_font_family( $input );
		$this->assertSame( $expected, $result );

		// Idempotency check — normalizing the result should produce the same result.
		$this->assertSame( $result, WP_Font_Utils::normalize_css_font_family( $result ), 'Normalization must be idempotent.' );
	}

	/**
	 * Data provider.
	 */
	public static function data_provider(): Generator {
		// Single idents.
		yield 'Simple ident' => array( 'serif', 'serif' );
		yield 'Hyphenated ident' => array( 'sans-serif', 'sans-serif' );
		yield 'Custom ident' => array( 'Font', 'Font' );
		yield 'CamelCase ident' => array( 'MyFont', 'MyFont' );
		yield 'Ident with underscore' => array( 'My_Font', 'My_Font' );
		yield 'Ident with backslash escape' => array( 'Font\\\'s', 'Font\\27 s' );
		yield 'Ident with hex escape' => array( 'F\\6fnt', 'Font' );

		// Multi-ident sequences (each ident normalized, joined by single space).
		yield 'Two idents' => array( 'Font Name', 'Font Name' );
		yield 'Three idents' => array( 'Times New Roman', 'Times New Roman' );
		yield 'Extra whitespace between idents' => array( 'Font  Name', 'Font Name' );

		// Strings (re-encoded via WP_CSS_Builder::string()).
		yield 'Double-quoted string' => array( '"Font"', '"Font"' );
		yield 'Double-quoted string with spaces' => array( '"Times New Roman"', '"Times New Roman"' );
		yield 'Single-quoted string' => array( "'Font'", '"Font"' );

		// generic() function.
		yield 'generic function' => array( 'generic(fangsong)', 'generic(fangsong)' );
		yield 'generic function with whitespace' => array( 'generic( fangsong )', 'generic(fangsong)' );

		// Comma-separated lists.
		yield 'Two generic families' => array( 'serif, sans-serif', 'serif, sans-serif' );
		yield 'String and generic' => array( '"Times New Roman", serif', '"Times New Roman", serif' );
		yield 'Idents and generic' => array( 'Times New Roman, serif', 'Times New Roman, serif' );
		yield 'Whitespace around commas' => array( '  serif  ,  sans-serif  ', 'serif, sans-serif' );
		yield 'Mixed types' => array( 'serif, "My Font", generic(fangsong)', 'serif, "My Font", generic(fangsong)' );
		yield 'Escaped ident in list' => array( 'Font\\\'s, serif', 'Font\\27 s, serif' );

		// Invalid inputs — return empty string.
		yield 'Empty string' => array( '', '' );
		yield 'Whitespace only' => array( '   ', '' );
		yield 'Trailing comma' => array( 'serif,', '' );
		yield 'Leading comma' => array( ',serif', '' );
		yield 'Double comma' => array( 'serif,,sans-serif', '' );
		yield 'Empty generic function' => array( 'generic()', '' );
	}
}
