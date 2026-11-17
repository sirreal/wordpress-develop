<?php
/**
 * Tests the Style Engine value type checks.
 *
 * @package WordPress
 * @subpackage StyleEngine
 * @since 7.3.0
 */

/**
 * Tests for WP_Style_Engine::is_angle() and WP_Style_Engine::is_length_percentage().
 *
 * @group style-engine
 *
 * @coversDefaultClass WP_Style_Engine
 */
class Tests_Style_Engine_wpStyleEngineValueTypes extends WP_UnitTestCase {
	/**
	 * @ticket 66468
	 * @covers ::is_angle
	 * @dataProvider data_angles
	 *
	 * @param mixed $value    Value to check.
	 * @param bool  $expected Whether the value is a single angle.
	 */
	public function test_is_angle( $value, $expected ) {
		$this->assertSame( $expected, WP_Style_Engine::is_angle( $value ) );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function data_angles() {
		return array(
			'degrees'                        => array( '45deg', true ),
			'negative'                       => array( '-15deg', true ),
			'leading plus sign'              => array( '+1deg', true ),
			'no integer part'                => array( '.5turn', true ),
			'negative with no integer part'  => array( '-.5turn', true ),
			'radians'                        => array( '1.5rad', true ),
			'gradians'                       => array( '100grad', true ),
			'uppercase unit'                 => array( '1DEG', true ),
			'mixed case unit'                => array( '100GrAd', true ),
			'zero with unit'                 => array( '0deg', true ),
			'number 0'                       => array( '0', true ),
			'exponent'                       => array( '1e2deg', true ),
			'uppercase exponent and unit'    => array( '1E2DEG', true ),
			'negative exponent'              => array( '1e-1turn', true ),
			'hex escape in unit'             => array( '1\64 eg', true ),
			'identity escape in unit'        => array( '1de\g', true ),
			'number other than 0'            => array( '45', false ),
			'zero with a decimal point'      => array( '0.0', false ),
			'negative zero'                  => array( '-0', false ),
			'length'                         => array( '1px', false ),
			'percentage'                     => array( '50%', false ),
			'unit after a trailing dot'      => array( '1.deg', false ),
			'space before the unit'          => array( '45 deg', false ),
			'leading space'                  => array( ' 45deg', false ),
			'trailing space'                 => array( '45deg ', false ),
			'trailing newline'               => array( "45deg\n", false ),
			'leading comment'                => array( '/**/45deg', false ),
			'trailing comment'               => array( '45deg/**/', false ),
			'escape that decodes to a space' => array( '1deg\20 ', false ),
			'axis and angle'                 => array( 'x 45deg', false ),
			'two angles'                     => array( '1deg 2deg', false ),
			'calc()'                         => array( 'calc(10deg + 5deg)', false ),
			'var()'                          => array( 'var(--angle)', false ),
			'keyword'                        => array( 'none', false ),
			'unit with a trailing null byte' => array( "1deg\0", false ),
			'empty string'                   => array( '', false ),
			'integer'                        => array( 45, false ),
			'null'                           => array( null, false ),
			'array'                          => array( array( '45deg' ), false ),
		);
	}

	/**
	 * @ticket 66468
	 * @covers ::is_angle
	 */
	public function test_is_angle_accepts_a_token() {
		$processor = new WP_CSS_Token_Processor( '45deg' );
		$processor->next_token();

		$this->assertTrue( WP_Style_Engine::is_angle( $processor->get_token() ) );
	}

	/**
	 * @ticket 66467
	 * @covers ::is_length_percentage
	 * @dataProvider data_lengths_and_percentages
	 *
	 * @param mixed $value    Value to check.
	 * @param bool  $expected Whether the value is a single length or percentage.
	 */
	public function test_is_length_percentage( $value, $expected ) {
		$this->assertSame( $expected, WP_Style_Engine::is_length_percentage( $value ) );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function data_lengths_and_percentages() {
		return array(
			'pixels'                      => array( '10px', true ),
			'negative percentage'         => array( '-50%', true ),
			'leading plus sign'           => array( '+.5em', true ),
			'no integer part'             => array( '.5em', true ),
			'uppercase unit'              => array( '1PX', true ),
			'dynamic viewport unit'       => array( '10DVH', true ),
			'small viewport unit'         => array( '1svmax', true ),
			'container unit'              => array( '2cqi', true ),
			'root font unit'              => array( '1rcap', true ),
			'quarter millimeters'         => array( '1Q', true ),
			'number 0'                    => array( '0', true ),
			'exponent'                    => array( '1e1px', true ),
			'uppercase exponent and unit' => array( '1E1PX', true ),
			'hex escape in unit'          => array( '1\70 x', true ),
			'number other than 0'         => array( '10', false ),
			'zero with a decimal point'   => array( '0.0', false ),
			'angle'                       => array( '5deg', false ),
			'resolution'                  => array( '1dppx', false ),
			'flex'                        => array( '1fr', false ),
			'two lengths'                 => array( '1px 2px', false ),
			'percentage then length'      => array( '50%2px', false ),
			'leading space'               => array( ' 1px', false ),
			'leading no-break space'      => array( "\u{00A0}1px", false ),
			'fullwidth unit'              => array( "1\u{FF50}\u{FF58}", false ),
			'comment'                     => array( '1px/**/', false ),
			'calc()'                      => array( 'calc(1px + 2px)', false ),
			'keyword'                     => array( 'none', false ),
			'empty string'                => array( '', false ),
			'integer'                     => array( 0, false ),
			'null'                        => array( null, false ),
		);
	}

	/**
	 * The style engine output writes an escaped unit as plain text.
	 *
	 * @ticket 66468
	 */
	public function test_escaped_unit_is_written_decoded() {
		$this->assertSame(
			'rotate:1deg;',
			wp_style_engine_get_styles( array( 'transform' => array( 'rotate' => '1\64 eg' ) ) )['css']
		);
		$this->assertSame(
			'translate:1px 0;',
			wp_style_engine_get_styles( array( 'transform' => array( 'translate' => array( 'x' => '1\70 x' ) ) ) )['css']
		);
	}
}
