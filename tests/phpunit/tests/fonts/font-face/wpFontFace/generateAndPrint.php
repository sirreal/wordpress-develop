<?php
/**
 * Test case for WP_Font_Face::generate_and_print().
 *
 * @package    WordPress
 * @subpackage Fonts
 *
 * @since 6.4.0
 *
 * @group fonts
 * @group fontface
 *
 * @covers WP_Font_Face::generate_and_print
 */
class Tests_Fonts_WPFontFace_GenerateAndPrint extends WP_UnitTestCase {
	use WP_Font_Face_Tests_Datasets;

	public function test_should_not_generate_and_print_when_no_fonts() {
		$font_face = new WP_Font_Face();
		$fonts     = array();

		$this->expectOutputString( '' );
		$font_face->generate_and_print( $fonts );
	}

	/**
	 * @dataProvider data_should_print_given_fonts
	 *
	 * @param array  $fonts Prepared fonts.
	 * @param string $expected Expected CSS.
	 */
	public function test_should_generate_and_print_given_fonts( array $fonts, $expected ) {
		$font_face       = new WP_Font_Face();
		$style_element   = "<style class='wp-fonts-local'>\n%s\n</style>\n";
		$expected_output = sprintf( $style_element, $expected );

		$output = get_echo( array( $font_face, 'generate_and_print' ), array( $fonts ) );
		$this->assertEqualHTML( $expected_output, $output );
	}

	/**
	 * @ticket 66103
	 *
	 * @dataProvider data_should_print_font_variation_settings
	 *
	 * @param array       $settings Font variation settings, keyed by axis tag.
	 * @param string|null $expected Expected descriptor value, or null if the descriptor is omitted.
	 */
	public function test_should_print_font_variation_settings( array $settings, $expected ) {
		$font_face = new WP_Font_Face();
		$fonts     = array(
			array(
				array(
					'font-family'             => 'Inter',
					'src'                     => array( 'https://example.org/font.woff2' ),
					'font-variation-settings' => $settings,
				),
			),
		);

		$output = get_echo( array( $font_face, 'generate_and_print' ), array( $fonts ) );

		if ( null === $expected ) {
			$this->assertStringNotContainsString( 'font-variation-settings', $output );
		} else {
			$this->assertStringContainsString( 'font-variation-settings:' . $expected . ';}', $output );
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_print_font_variation_settings() {
		return array(
			'registered axes'                        => array(
				array(
					'wght' => 400,
					'slnt' => -10,
				),
				'"wght" 400, "slnt" -10',
			),
			'a custom axis'                          => array( array( 'GRAD' => 0 ), '"GRAD" 0' ),
			'a float value'                          => array( array( 'wght' => 412.5 ), '"wght" 412.5' ),
			'a numeric string with whitespace'       => array( array( 'wght' => ' 400 ' ), '"wght" 400' ),
			'a numeric tag'                          => array( array( '1234' => 1 ), '"1234" 1' ),
			'a tag with a quotation mark is escaped' => array( array( 'a"b;' => 1 ), '"a\\22 b\\3b " 1' ),
			'a tag with an angle bracket is escaped' => array( array( '</a>' => 1 ), '"\\3c /a\\3e " 1' ),
			'a tag that is not four characters'      => array(
				array(
					'wdth'   => 100,
					'weight' => 400,
				),
				'"wdth" 100',
			),
			'a tag with a non-ASCII character'       => array( array( 'wgh€' => 400 ), null ),
			'a value with a unit'                    => array( array( 'wght' => '400px' ), null ),
			'a value with a second declaration'      => array( array( 'wght' => '400;color:red' ), null ),
			'a value with a function'                => array( array( 'wght' => 'calc(400)' ), null ),
			'a value that is not a scalar'           => array( array( 'wght' => array( 400 ) ), null ),
			'a value that is not a number'           => array(
				array(
					'wght' => 'bold',
					'wdth' => 100,
				),
				'"wdth" 100',
			),
			'a value that is infinite'               => array( array( 'wght' => INF ), null ),
		);
	}
}
