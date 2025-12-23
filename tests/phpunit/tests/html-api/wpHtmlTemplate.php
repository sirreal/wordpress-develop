<?php
/**
 * Unit tests covering WP_HTML_Template functionality.
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @since 7.0.0
 *
 * @group html-api
 * @group TBD
 *
 * @coversDefaultClass WP_HTML_Template
 */

use WP_HTML_Template as T;

class Tests_HtmlApi_WpHtmlTemplate extends WP_UnitTestCase {
	public function test_1() {
		$t = T::from( '<p>Hello, </%name>!</p>' );
		$result = $t->render( array( 'name' => 'World' ) );
		$this->assertSame( $result, T::sprintf( '<p>Hello, </%name>!</p>', array( 'name' => 'World' ) ) );

		$expected =
			<<<'HTML'
			<p>Hello, World!</p>
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	public function test_2() {
		$template_string = '<p>Hello, </%placeholder>!</p>';
		$replacements = array( 'placeholder' => 'Alice & Bob' );

		$t = T::from( $template_string );
		$result = $t->render( $replacements );
		$this->assertSame( $result, T::sprintf( $template_string, $replacements ) );

		$expected =
			<<<'HTML'
			<p>Hello, Alice &amp; Bob!</p>
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	public function test_3() {
		$template_string = '<p>Hello, </%1>, </%1>, </%2>, & </%2>!</p>';
		$replacements = array( 'Alice', 'Bob' );

		$t = T::from( $template_string );
		$result = $t->render( $replacements );
		$this->assertSame( $result, T::sprintf( $template_string, $replacements ) );

		$expected =
			<<<'HTML'
			<p>Hello, Alice, Alice, Bob, &amp; Bob!</p>
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	public function test_4() {
		$template_string = '<p>Hello, </%html>';
		$replacements = array( 'html' => T::from( '<i>Alice</i> & <i>Bob</i>' ) );

		$t = T::from( $template_string );
		$result = $t->render( $replacements );
		$this->assertSame( $result, T::sprintf( $template_string, $replacements ) );

		$expected =
			<<<'HTML'
			<p>Hello, <i>Alice</i> &amp; <i>Bob</i></p>
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	public function test_attr() {
		$template_string = '<meta name="</%n>" content="</%c>">';
		$replacements = array(
			'n' => 'the name',
			'c' => 'the "content" & whatever else',
		);

		$t = T::from( $template_string );
		$result = $t->render( $replacements );
		$this->assertSame( $result, T::sprintf( $template_string, $replacements ) );

		$expected =
			<<<'HTML'
			<meta name="name" content="the &quot;content&quot; &amp; whatever else">
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_attr_rejects_html() {
		$template_string = '<meta name="not-allowed" description="</%html>">';
		$replacements = array(
			'html' => T::from( '<strong>This is not allowed!</strong>') ,
		);
		$this->assertFalse( T::sprintf( $template_string, $replacements ) );
	}

	/**
	 * @dataProvider data_template
	 *
	 * @ticket 60229
	 * @covers ::from
	 * @covers ::render
	 */
	public function xtest_template( string $template_string, array $replacements, string $expected ) {
		$result = WP_HTML_Template::sprintf( $template_string, $replacements );
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_template() {
		return array(
			'Basic template' => array(
				'<p>Hi!</p>',
				array(),
				'<p>Hi!</p>',
			),

			'HTML text replacement (basic)' => array(
				'<p>Hello, </%name>!</p>',
				array( 'name' => 'World!' ),
				'<p>Hello, World!</p>',
			),

			'HTML text replacement (escaped)' => array(
				'<p>Hello, </%name>!</p>',
				array( 'name' => '<little-bobby-tags>' ),
				'<p>Hello, &lt;little-bobby-tags&gt;</p>',
			),

			'HTML replacement with template' => array(
				'<p>Hello, </%name>!</p>',
				array(
					'name' => WP_HTML_Template::from( '<i>World</i>' )
				),
				'<p>Hello, <i>World</i>!</p>',
			),
		);
	}
}
