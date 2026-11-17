<?php
/**
 * Tests the Style Engine value type checks.
 *
 * @package WordPress
 * @subpackage StyleEngine
 * @since 7.3.0
 */

/**
 * Tests the `rotate` and `translate` values the style engine outputs.
 *
 * @group style-engine
 *
 * @covers ::wp_style_engine_get_styles
 * @covers ::_wp_style_engine_get_single_css_token
 */
class Tests_Style_Engine_wpStyleEngineValueTypes extends WP_UnitTestCase {
	/**
	 * @ticket 66468
	 * @dataProvider data_rotate_values
	 *
	 * @param mixed  $value    `rotate` value.
	 * @param string $expected Expected CSS.
	 */
	public function test_rotate_value( $value, $expected ) {
		$this->assertSame(
			$expected,
			wp_style_engine_get_styles( array( 'transform' => array( 'rotate' => $value ) ) )['css'] ?? ''
		);
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public function data_rotate_values() {
		return array(
			'degrees'                        => array( '45deg', 'rotate:45deg;' ),
			'negative'                       => array( '-15deg', 'rotate:-15deg;' ),
			'leading plus sign'              => array( '+1deg', 'rotate:+1deg;' ),
			'no integer part'                => array( '.5turn', 'rotate:.5turn;' ),
			'radians'                        => array( '1.5rad', 'rotate:1.5rad;' ),
			'gradians'                       => array( '100grad', 'rotate:100grad;' ),
			'uppercase unit'                 => array( '1DEG', 'rotate:1DEG;' ),
			'zero with unit'                 => array( '0deg', 'rotate:0deg;' ),
			'exponent'                       => array( '1e2deg', 'rotate:1e2deg;' ),
			'hex escape in unit'             => array( '1\64 eg', 'rotate:1deg;' ),
			'identity escape in unit'        => array( '1de\g', 'rotate:1deg;' ),
			'number 0'                       => array( '0', '' ),
			'zero with a decimal point'      => array( '0.0', '' ),
			'negative zero'                  => array( '-0', '' ),
			'number other than 0'            => array( '45', '' ),
			'length'                         => array( '1px', '' ),
			'percentage'                     => array( '50%', '' ),
			'unit after a trailing dot'      => array( '1.deg', '' ),
			'space before the unit'          => array( '45 deg', '' ),
			'leading space'                  => array( ' 45deg', '' ),
			'trailing space'                 => array( '45deg ', '' ),
			'trailing newline'               => array( "45deg\n", '' ),
			'leading comment'                => array( '/**/45deg', '' ),
			'trailing comment'               => array( '45deg/**/', '' ),
			'escape that decodes to a space' => array( '1deg\20 ', '' ),
			'axis and angle'                 => array( 'x 45deg', '' ),
			'two angles'                     => array( '1deg 2deg', '' ),
			'calc()'                         => array( 'calc(10deg + 5deg)', '' ),
			'var()'                          => array( 'var(--angle)', '' ),
			'keyword'                        => array( 'none', '' ),
			'unit with a trailing null byte' => array( "1deg\0", '' ),
			'empty string'                   => array( '', '' ),
			'integer'                        => array( 45, '' ),
			'null'                           => array( null, '' ),
			'array'                          => array( array( '45deg' ), '' ),
		);
	}

	/**
	 * @ticket 66467
	 * @dataProvider data_translate_axis_values
	 *
	 * @param mixed  $value    `translate` x axis value.
	 * @param string $expected Expected CSS.
	 */
	public function test_translate_axis_value( $value, $expected ) {
		$this->assertSame(
			$expected,
			wp_style_engine_get_styles( array( 'transform' => array( 'translate' => array( 'x' => $value ) ) ) )['css'] ?? ''
		);
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public function data_translate_axis_values() {
		return array(
			'pixels'                 => array( '10px', 'translate:10px 0;' ),
			'negative percentage'    => array( '-50%', 'translate:-50% 0;' ),
			'leading plus sign'      => array( '+.5em', 'translate:+.5em 0;' ),
			'uppercase unit'         => array( '1PX', 'translate:1PX 0;' ),
			'dynamic viewport unit'  => array( '10DVH', 'translate:10DVH 0;' ),
			'container unit'         => array( '2cqi', 'translate:2cqi 0;' ),
			'quarter millimeters'    => array( '1Q', 'translate:1Q 0;' ),
			'number 0'               => array( '0', 'translate:0 0;' ),
			'exponent'               => array( '1e1px', 'translate:1e1px 0;' ),
			'hex escape in unit'     => array( '1\70 x', 'translate:1px 0;' ),
			'number other than 0'    => array( '10', '' ),
			'angle'                  => array( '5deg', '' ),
			'resolution'             => array( '1dppx', '' ),
			'two lengths'            => array( '1px 2px', '' ),
			'percentage then length' => array( '50%2px', '' ),
			'leading space'          => array( ' 1px', '' ),
			'leading no-break space' => array( "\u{00A0}1px", '' ),
			'fullwidth unit'         => array( "1\u{FF50}\u{FF58}", '' ),
			'comment'                => array( '1px/**/', '' ),
			'calc()'                 => array( 'calc(1px + 2px)', '' ),
			'keyword'                => array( 'none', '' ),
			'empty string'           => array( '', '' ),
			'integer'                => array( 0, '' ),
		);
	}
}
