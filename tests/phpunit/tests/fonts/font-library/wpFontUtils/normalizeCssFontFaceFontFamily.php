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
		yield 'Already normalized' => array( '"Font"', '"Font"' );
		yield 'Unquoted' => array( 'Font', '"Font"' );
		yield 'Multiple idents' => array( 'Font Name', '"Font Name"' );
		yield 'Unitless number?' => array( 'Libre Barcode 128 Text', null );
		yield 'Unitful number?' => array( '10px Size', null );
	}
}
