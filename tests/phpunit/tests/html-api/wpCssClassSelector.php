<?php
/**
 * Unit tests covering WP_CSS_Class_Selector functionality.
 *
 * @package WordPress
 *
 * @subpackage HTML-API
 *
 * @since {WP_VERSION}
 *
 * @group html-api
 *
 * @coversDefaultClass WP_CSS_Class_Selector
 */
class Tests_HtmlApi_WpCssClassSelector extends WP_UnitTestCase {
	/**
	 * @ticket 62653
	 *
	 * @dataProvider data_class_selectors
	 */
	public function test_parse_class( string $input, ?string $expected = null, ?string $rest = null ) {
		$offset = 0;
		$result = WP_CSS_Class_Selector::parse( $input, $offset );
		if ( null === $expected ) {
			$this->assertNull( $result );
		} else {
			$this->assertSame( $expected, $result->class_name );
			$this->assertSame( $rest, substr( $input, $offset ) );
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public static function data_class_selectors(): array {
		return array(
			'valid ._-foo123'             => array( '._-foo123', '_-foo123', '' ),
			'valid .foo.bar'              => array( '.foo.bar', 'foo', '.bar' ),
			'escaped .\31 23'             => array( '.\\31 23', '123', '' ),
			'with descendant .\31 23 div' => array( '.\\31 23 div', '123', ' div' ),

			// Additional edge cases
			'multiple dots .a.b'          => array( '.a.b', 'a', '.b' ),
			'escaped dot .\\2e class'     => array( '.\\2e class', '.class', '' ),
			'unicode class .café'         => array( '.café', 'café', '' ),
			'hyphen class .my-class'      => array( '.my-class', 'my-class', '' ),
			'underscore class .my_class'  => array( '.my_class', 'my_class', '' ),
			'long class name'             => array( '.very-long-class-name-with-many-hyphens', 'very-long-class-name-with-many-hyphens', '' ),

			'not class foo'               => array( 'foo' ),
			'not class #bar'              => array( '#bar' ),
			'not valid .1foo'             => array( '.1foo' ),
			'empty after dot'             => array( '.' ),
			'space after dot'             => array( '. ' ),
			'invalid after dot'           => array( '.@invalid' ),
		);
	}
}
