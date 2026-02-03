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
		$t      = T::from( '<p>Hello, </%name>!</p>' );
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
		$replacements    = array( 'placeholder' => 'Alice & Bob' );

		$t      = T::from( $template_string );
		$result = $t->render( $replacements );
		$this->assertSame( $result, T::sprintf( $template_string, $replacements ) );

		$expected =
			<<<'HTML'
			<p>Hello, Alice &amp; Bob!</p>
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	public function test_3() {
		$template_string = '<p>Hello, </%0>, </% 0 >, </%1>, & </%1>!</p>';
		$replacements    = array( 'Alice', 'Bob' );

		$t      = T::from( $template_string );
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
		$replacements    = array( 'html' => T::from( '<i>Alice</i> & <i>Bob</i>' ) );

		$t      = T::from( $template_string );
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
		$replacements    = array(
			'n' => 'the name',
			'c' => 'the "content" & whatever else',
		);

		$t      = T::from( $template_string );
		$result = $t->render( $replacements );
		$this->assertSame( $result, T::sprintf( $template_string, $replacements ) );

		$expected =
			<<<'HTML'
			<meta name="the name" content="the &quot;content&quot; &amp; whatever else">
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	public function test_attribute_with_spaces() {
		$template_string = "<meta name='</%\tn\n>' content='</% c\r\f>'>";
		$replacements    = array(
			'n' => 'the name',
			'c' => 'the "content" & whatever else',
		);

		$t      = T::from( $template_string );
		$result = $t->render( $replacements );
		$this->assertSame( $result, T::sprintf( $template_string, $replacements ) );

		$expected =
			<<<'HTML'
			<meta name="the name" content="the &quot;content&quot; &amp; whatever else">
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_attr_rejects_html() {
		$template_string = '<meta name="not-allowed" description="</%html>">';
		$replacements    = array(
			'html' => T::from( '<strong>This is not allowed!</strong>' ),
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
			'Basic template'                  => array(
				'<p>Hi!</p>',
				array(),
				'<p>Hi!</p>',
			),

			'HTML text replacement (basic)'   => array(
				'<p>Hello, </%name>!</p>',
				array( 'name' => 'World!' ),
				'<p>Hello, World!</p>',
			),

			'HTML text replacement (escaped)' => array(
				'<p>Hello, </%name>!</p>',
				array( 'name' => '<little-bobby-tags>' ),
				'<p>Hello, &lt;little-bobby-tags&gt;</p>',
			),

			'HTML replacement with template'  => array(
				'<p>Hello, </%name>!</p>',
				array(
					'name' => WP_HTML_Template::from( '<i>World</i>' ),
				),
				'<p>Hello, <i>World</i>!</p>',
			),
		);
	}

	/**
	 * Test real-world patterns from WordPress core.
	 *
	 * @dataProvider data_real_world_examples
	 *
	 * @ticket 60229
	 * @covers ::sprintf
	 */
	public function test_real_world_examples( string $template_string, array $replacements, string $expected ) {
		$result = WP_HTML_Template::sprintf( $template_string, $replacements );
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Data provider with real-world patterns from WordPress core.
	 *
	 * Each test case is based on actual code patterns found in WordPress core
	 * that could benefit from the WP_HTML_Template API.
	 *
	 * @return array[]
	 */
	public static function data_real_world_examples() {
		/*
		 * Group 1: Simple sprintf patterns with manual escaping.
		 *
		 * These patterns currently require developers to manually choose
		 * the correct escape function (esc_url, esc_attr, esc_html).
		 */

		// src/wp-includes/formatting.php:3476 - Smiley image
		yield 'formatting.php:3476 - smiley image tag' => array(
			<<<'HTML'
			<img src="</%src>" alt="</%alt>" class="wp-smiley" style="height: 1em; max-height: 1em;" />
			HTML,
			array(
				'src' => 'https://example.com/smilies/:).png',
				'alt' => ':)',
			),
			<<<'HTML'
			<img src="https://example.com/smilies/:).png" alt=":)" class="wp-smiley" style="height: 1em; max-height: 1em;" />
			HTML,
		);

		// src/wp-includes/blocks/post-title.php:41 - Post title link
		yield 'blocks/post-title.php:41 - post title link' => array(
			'<a href="</%url>" target="</%target>"></%title></a>',
			array(
				'url'    => 'https://example.com/hello-world/',
				'target' => '_blank',
				'title'  => 'Hello World',
			),
			'<a href="https://example.com/hello-world/" target="_blank">Hello World</a>',
		);

		// Same pattern with escaping needed
		yield 'blocks/post-title.php:41 - post title link with special chars' => array(
			'<a href="</%url>" target="</%target>"></%title></a>',
			array(
				'url'    => 'https://example.com/hello-world/?foo=1&bar=2',
				'target' => '_blank',
				'title'  => <<<'TEXT'
				Hello <World> & "Friends"
				TEXT,
			),
			<<<'HTML'
			<a href="https://example.com/hello-world/?foo=1&amp;bar=2" target="_blank">Hello &lt;World&gt; &amp; "Friends"</a>
			HTML,
		);

		/*
		 * Group 2: Translation patterns with embedded HTML.
		 *
		 * These patterns have HTML directly in translatable strings.
		 */

		// src/wp-includes/functions.php:1620 - Error message (static, no placeholders)
		yield 'functions.php:1620 - static error message' => array(
			'<strong>Error:</strong> This is not a valid feed template.',
			array(),
			'<strong>Error:</strong> This is not a valid feed template.',
		);

		// src/wp-includes/functions.php:1844-1845 - Database repair link
		yield 'functions.php:1844 - database repair link' => array(
			<<<'HTML'
			One or more database tables are unavailable. The database may need to be <a href="</%url>">repaired</a>.
			HTML,
			array(
				'url' => 'maint/repair.php?referrer=is_blog_installed',
			),
			<<<'HTML'
			One or more database tables are unavailable. The database may need to be <a href="maint/repair.php?referrer=is_blog_installed">repaired</a>.
			HTML,
		);

		// src/wp-admin/edit-form-advanced.php:185 - Scheduled post date
		yield 'edit-form-advanced.php:185 - scheduled post date' => array(
			'Post scheduled for: <strong></%date></strong>.',
			array(
				'date' => 'March 15, 2025 at 10:30 am',
			),
			'Post scheduled for: <strong>March 15, 2025 at 10:30 am</strong>.',
		);

		// src/wp-includes/blocks/latest-posts.php:164-166 - Read more link with nested elements
		yield 'blocks/latest-posts.php:164 - read more link with screen reader text' => array(
			<<<'HTML'
			… <a class="wp-block-latest-posts__read-more" href="</%url>" rel="noopener noreferrer">Read more<span class="screen-reader-text">: </%title></span></a>
			HTML,
			array(
				'url'   => 'https://example.com/my-post/',
				'title' => 'My Amazing Post',
			),
			<<<'HTML'
			… <a class="wp-block-latest-posts__read-more" href="https://example.com/my-post/" rel="noopener noreferrer">Read more<span class="screen-reader-text">: My Amazing Post</span></a>
			HTML,
		);

		// Same pattern with escaping needed
		yield 'blocks/latest-posts.php:164 - read more with XSS attempt' => array(
			<<<'HTML'
			… <a class="wp-block-latest-posts__read-more" href="</%url>" rel="noopener noreferrer">Read more<span class="screen-reader-text">: </%title></span></a>
			HTML,
			array(
				'url'   => 'javascript:alert("xss")',
				'title' => '<script>alert("xss")</script>',
			),
			<<<'HTML'
			… <a class="wp-block-latest-posts__read-more" href="javascript:alert(&quot;xss&quot;)" rel="noopener noreferrer">Read more<span class="screen-reader-text">: &lt;script&gt;alert("xss")&lt;/script&gt;</span></a>
			HTML,
		);

		// src/wp-includes/theme.php:978-979 - Theme error with name
		yield 'theme.php:978 - theme error message' => array(
			<<<'HTML'
			<strong>Error:</strong> Current WordPress and PHP versions do not meet minimum requirements for </%theme_name>.
			HTML,
			array(
				'theme_name' => 'Twenty Twenty-Five',
			),
			<<<'HTML'
			<strong>Error:</strong> Current WordPress and PHP versions do not meet minimum requirements for Twenty Twenty-Five.
			HTML,
		);

		// src/wp-admin/includes/privacy-tools.php:404 - Code tag in error
		yield 'privacy-tools.php:404 - code in error message' => array(
			'The <code></%meta_key></code> post meta must be an array.',
			array(
				'meta_key' => '_export_data_grouped',
			),
			'The <code>_export_data_grouped</code> post meta must be an array.',
		);

		/*
		 * Group 3: Edge cases.
		 */

		// Placeholder reuse (same placeholder multiple times)
		yield 'placeholder reuse' => array(
			'<label for="</%id>">Name:</label> <input id="</%id>" name="</%id>" type="text" />',
			array(
				'id' => 'user_name',
			),
			'<label for="user_name">Name:</label> <input id="user_name" name="user_name" type="text" />',
		);

		// Numeric placeholders like sprintf
		yield 'numeric placeholders' => array(
			'<a href="</%0>"></%1></a> by <a href="</%2>"></%3></a>',
			array(
				'https://example.com/post/',
				'Post Title',
				'https://example.com/author/',
				'Author Name',
			),
			'<a href="https://example.com/post/">Post Title</a> by <a href="https://example.com/author/">Author Name</a>',
		);

		// Nested template (pre-escaped HTML)
		yield 'nested template for complex structure' => array(
			'<div class="error"></%icon> </%message></div>',
			array(
				'icon'    => WP_HTML_Template::from( '<span class="dashicons dashicons-warning"></span>' ),
				'message' => 'Something went wrong.',
			),
			'<div class="error"><span class="dashicons dashicons-warning"></span> Something went wrong.</div>',
		);

		// Empty replacement
		yield 'empty replacement value' => array(
			'<p>Hello</%suffix></p>',
			array(
				'suffix' => '',
			),
			'<p>Hello</p>',
		);

		// HTML entities in template (should be preserved)
		yield 'HTML entities in template' => array(
			'<p>&#8220;</%quote>&#8221;</p>',
			array(
				'quote' => 'Hello World',
			),
			'<p>&#8220;Hello World&#8221;</p>',
		);

		// Multiple attributes on same element
		yield 'multiple attributes on element' => array(
			'<input type="</%type>" name="</%name>" value="</%value>" placeholder="</%placeholder>" />',
			array(
				'type'        => 'text',
				'name'        => 'user_email',
				'value'       => 'test@example.com',
				'placeholder' => 'Enter your email',
			),
			'<input type="text" name="user_email" value="test@example.com" placeholder="Enter your email" />',
		);

		// Attribute value with quotes and special characters
		yield 'attribute with quotes and ampersands' => array(
			'<a href="</%url>" title="</%title>">Link</a>',
			array(
				'url'   => 'https://example.com/?a=1&b=2',
				'title' => <<<'TEXT'
				Click "here" for Tom & Jerry
				TEXT,
			),
			<<<'HTML'
			<a href="https://example.com/?a=1&amp;b=2" title="Click &quot;here&quot; for Tom &amp; Jerry">Link</a>
			HTML,
		);

		// Self-closing void element
		yield 'self-closing meta tag' => array(
			'<meta name="</%name>" content="</%content>">',
			array(
				'name'    => 'description',
				'content' => <<<'TEXT'
				A page about "cats" & dogs
				TEXT,
			),
			<<<'HTML'
			<meta name="description" content="A page about &quot;cats&quot; &amp; dogs">
			HTML,
		);

		// src/wp-includes/blocks/avatar.php:68 - Complex link with aria-label
		yield 'blocks/avatar.php:68 - avatar link' => array(
			<<<'HTML'
			<a href="</%url>" target="</%target>" aria-label="</%aria_label>" class="wp-block-avatar__link"></%inner></a>
			HTML,
			array(
				'url'        => 'https://example.com/author/johndoe/',
				'target'     => '_blank',
				'aria_label' => '(John Doe author archive, opens in a new tab)',
				'inner'      => WP_HTML_Template::from( '<img src="https://example.com/avatar.jpg" alt="John Doe" />' ),
			),
			<<<'HTML'
			<a href="https://example.com/author/johndoe/" target="_blank" aria-label="(John Doe author archive, opens in a new tab)" class="wp-block-avatar__link"><img src="https://example.com/avatar.jpg" alt="John Doe" /></a>
			HTML,
		);
	}
}
