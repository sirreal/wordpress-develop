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
	/**
	 * Verifies that replacement text adjacent to angle brackets is escaped.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_escapes_text_adjacent_to_angle_brackets() {
		$template_string = 'a<</%tag-name>>s';
		$replacements    = array( 'tag-name' => 'i' );
		$result          = T::render( $template_string, $replacements );

		$expected = 'a&lt;i&gt;s';
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that only the first of duplicate attributes is replaced.
	 *
	 * Note: Duplicate attributes are stripped per HTML spec, so placeholders
	 * in duplicate attributes are ignored. Providing a replacement for such
	 * placeholders would be an unused key error.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_replaces_only_in_first_duplicate_attribute() {
		$template_string = '<meta a="</%replace></%replace-2>" a="</% no-replace >">';
		$replacements    = array(
			'replace'   => 'O',
			'replace-2' => 'K',
		);

		$result = T::render( $template_string, $replacements );

		$expected = '<meta a="OK">';
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that attribute replacement is not recursive.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_attribute_replacement_is_not_recursive() {
		$template_string = '<div a="</%replace>"></%replace></div>';
		$replacements    = array(
			'replace' => '</%replace>',
		);

		$result = T::render( $template_string, $replacements );

		$expected = '<div a="&lt;/%replace&gt;">&lt;/%replace&gt;</div>';
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that placeholder names allow surrounding whitespace.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_placeholder_names_allow_surrounding_whitespace() {
		$template_string = "<meta name='</%\tn\n>' content='</% c\r\f>'>";
		$replacements    = array(
			'n' => 'the name',
			'c' => 'the "content" & whatever else',
		);

		$result = T::render( $template_string, $replacements );

		$expected =
			<<<'HTML'
			<meta name="the name" content="the &quot;content&quot; &amp; whatever else">
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that ampersands are escaped to prevent character reference injection.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_escapes_ampersand_to_prevent_character_reference_injection() {
		$template_string = '<meta name="&</% placeholder >;">';
		$replacements    = array( 'placeholder' => 'not' );
		$result          = T::render( $template_string, $replacements );

		$expected =
			<<<'HTML'
			<meta name="&amp;not;">
			HTML;
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that nested templates are rejected in attribute values.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 * @covers ::template
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_rejects_nested_template_in_attribute_value() {
		$template_string = '<meta name="not-allowed" description="</%html>">';
		$replacements    = array(
			'html' => T::template( '<strong>This is not allowed!</strong>' ),
		);
		$this->assertFalse( T::render( $template_string, $replacements ) );
	}

	/**
	 * @dataProvider data_template
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 * @covers ::template
	 */
	public function test_template( string $template_string, array $replacements, string $expected ) {
		$result = T::render( $template_string, $replacements );
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_template() {
		return array(
			'basic template (no placeholders)'   => array(
				'<p>Hi!</p>',
				array(),
				'<p>Hi!</p>',
			),

			'basic text replacement'             => array(
				'<p>Hello, </%name>!</p>',
				array( 'name' => 'World' ),
				'<p>Hello, World!</p>',
			),

			'escapes special characters in text' => array(
				'<p>Hello, </%placeholder>!</p>',
				array( 'placeholder' => 'Alice & Bob' ),
				'<p>Hello, Alice &amp; Bob!</p>',
			),

			'escapes angle brackets in text'     => array(
				'<p>Hello, </%name>!</p>',
				array( 'name' => '<little-bobby-tags>' ),
				'<p>Hello, &lt;little-bobby-tags&gt;!</p>',
			),

			'repeated placeholders'              => array(
				'<p></%a>, </% a >, </%name>, & </%name>!</p>',
				array(
					'a'    => 'Alice',
					'name' => 'Bob',
				),
				'<p>Alice, Alice, Bob, &amp; Bob!</p>',
			),

			'nested template replacement'        => array(
				'<p>Hello, </%html>',
				array( 'html' => WP_HTML_Template::template( '<i>Alice</i> & <i>Bob</i>' ) ),
				'<p>Hello, <i>Alice</i> &amp; <i>Bob</i></p>',
			),

			'replaces attribute values'          => array(
				'<meta name="</%n>" content="</%c>">',
				array(
					'n' => 'the name',
					'c' => 'the content',
				),
				'<meta name="the name" content="the content">',
			),

			'escapes attribute values'           => array(
				'<meta content="</%c>">',
				array(
					'c' => 'the "content" & whatever else',
				),
				'<meta content="the &quot;content&quot; &amp; whatever else">',
			),
		);
	}

	/**
	 * Test real-world patterns from WordPress core.
	 *
	 * @dataProvider data_real_world_examples
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 * @covers ::template
	 */
	public function test_real_world_examples( string $template_string, array $replacements, string $expected ) {
		$result = WP_HTML_Template::render( $template_string, $replacements );
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
			"<a href='</%url>' target='</%target>'>\n</%title>\n</a>",
			array(
				'url'    => 'https://example.com/hello-world/?foo=1&bar=2',
				'target' => '_blank',
				'title'  => WP_HTML_Template::template(
					'\'<i></%italic></i>\' & <b>"</%bold>"</b>',
					array(
						'italic' => 'This',
						'bold'   => 'That',
					)
				),
			),
			<<<'HTML'
			<a href="https://example.com/hello-world/?foo=1&amp;bar=2" target="_blank">
			&apos;<i>This</i>' &amp; <b>&quot;That"</b>
			</a>
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

		// Named placeholders
		yield 'named placeholders' => array(
			'<a href="</%postUrl>"></%postTitle></a> by <a href="</%authorUrl>"></%authorName></a>',
			array(
				'postUrl'    => 'https://example.com/post/',
				'postTitle'  => 'Post Title',
				'authorUrl'  => 'https://example.com/author/',
				'authorName' => 'Author Name',
			),
			'<a href="https://example.com/post/">Post Title</a> by <a href="https://example.com/author/">Author Name</a>',
		);

		// Nested template (pre-escaped HTML)
		yield 'nested template for complex structure' => array(
			'<div class="error"></%icon> </%message></div>',
			array(
				'icon'    => WP_HTML_Template::template( '<span class="dashicons dashicons-warning"></span>' ),
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
				'inner'      => WP_HTML_Template::template( '<img src="https://example.com/avatar.jpg" alt="John Doe" />' ),
			),
			<<<'HTML'
			<a href="https://example.com/author/johndoe/" target="_blank" aria-label="(John Doe author archive, opens in a new tab)" class="wp-block-avatar__link"><img src="https://example.com/avatar.jpg" alt="John Doe" /></a>
			HTML,
		);
	}

	/**
	 * Verifies nested templates work correctly in a definition list.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 * @covers ::template
	 */
	public function test_nested_templates_in_definition_list() {
		$row_replacements = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$row_replacements[ "row-{$i}" ] = WP_HTML_Template::template(
				"<dt></%term></dt>\n<dd></%definition></dd>",
				array(
					'term'       => "Term \"{$i}\"",
					'definition' => WP_HTML_Template::template(
						'<abbr title="</%expansion>">IYKYK</abbr>: </%i>',
						array(
							'i'         => (string) $i,
							'expansion' => '"If You Know You Know"',
						)
					),
				)
			);
		}

		$result = WP_HTML_Template::render(
			<<<'HTML'
			<dl>
			</%row-1>
			</%row-2>
			</%row-3>
			</dl>
			HTML,
			$row_replacements
		);

		$expected =
			<<<'HTML'
			<dl>
			<dt>Term &quot;1&quot;</dt>
			<dd><abbr title="&quot;If You Know You Know&quot;">IYKYK</abbr>: 1</dd>
			<dt>Term &quot;2&quot;</dt>
			<dd><abbr title="&quot;If You Know You Know&quot;">IYKYK</abbr>: 2</dd>
			<dt>Term &quot;3&quot;</dt>
			<dd><abbr title="&quot;If You Know You Know&quot;">IYKYK</abbr>: 3</dd>
			</dl>
			HTML;

		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that table row templates work when nested in table context.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 * @covers ::template
	 */
	public function test_table_row_template_with_placeholders() {
		$row = WP_HTML_Template::template(
			'<tr><td></%cell1></td><td></%cell2></td></tr>',
			array(
				'cell1' => 'Hello',
				'cell2' => 'World',
			)
		);

		$result = WP_HTML_Template::render(
			'<table><tbody></%row></tbody></table>',
			array( 'row' => $row )
		);

		// Use assertStringContainsString to verify table elements aren't discarded.
		// assertEqualHTML would pass incorrectly because both expected and actual
		// get parsed in BODY context where <tr>/<td> are discarded.
		$this->assertStringContainsString( '<tr>', $result, 'Table row element should be preserved.' );
		$this->assertStringContainsString( '<td>Hello</td>', $result );
		$this->assertStringContainsString( '<td>World</td>', $result );
	}

	/**
	 * Verifies table templates work with thead and tbody.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 * @covers ::template
	 */
	public function test_table_templates_with_thead_and_tbody() {
		$header = WP_HTML_Template::template(
			'<tr><th></%col1></th><th></%col2></th></tr>',
			array(
				'col1' => 'Name',
				'col2' => 'Value',
			)
		);

		$row = WP_HTML_Template::template(
			'<tr><td></%name></td><td></%value></td></tr>',
			array(
				'name'  => 'Alice',
				'value' => '42',
			)
		);

		$result = WP_HTML_Template::render(
			'<table><thead></%header></thead><tbody></%row></tbody></table>',
			array(
				'header' => $header,
				'row'    => $row,
			)
		);

		$this->assertStringContainsString( '<thead><tr><th>Name</th><th>Value</th></tr></thead>', $result );
		$this->assertStringContainsString( '<tbody><tr><td>Alice</td><td>42</td></tr></tbody>', $result );
	}

	/**
	 * Verifies that attributes are replaced in atomic elements (SCRIPT, STYLE, TITLE).
	 *
	 * These elements have special parsing rules that skip their content,
	 * but attributes should still be processed normally.
	 *
	 * @ticket 60229
	 *
	 * @dataProvider data_atomic_element_attributes
	 *
	 * @covers ::render
	 */
	public function test_atomic_element_attributes_are_replaced( string $template_string, array $replacements, string $expected ) {
		$result = T::render( $template_string, $replacements );
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_atomic_element_attributes() {
		return array(
			'SCRIPT element attributes'   => array(
				'<script src="</%src>">console.log("hi")</script>',
				array( 'src' => '/js/app.js' ),
				'<script src="/js/app.js">console.log("hi")</script>',
			),

			'STYLE element attributes'    => array(
				'<style media="</%media>">.foo { color: red; }</style>',
				array( 'media' => 'screen' ),
				'<style media="screen">.foo { color: red; }</style>',
			),

			'TITLE element attributes'    => array(
				'<title lang="</%lang>">Page Title</title>',
				array( 'lang' => 'en' ),
				'<title lang="en">Page Title</title>',
			),

			'TEXTAREA element attributes' => array(
				'<textarea name="</%name>">Some content</textarea>',
				array( 'name' => 'my-textarea' ),
				'<textarea name="my-textarea">Some content</textarea>',
			),
		);
	}

	/**
	 * Verifies content placeholder behavior in elements with special parsing.
	 *
	 * - RAWTEXT elements (SCRIPT, STYLE): Content is skipped, placeholders preserved literally.
	 * - RCDATA elements (TITLE, TEXTAREA): Content is processed but placeholders are not
	 *   recognized - they're treated as literal text and HTML-escaped.
	 *
	 * With strict validation, providing a replacement for a placeholder that won't be
	 * processed (inside SCRIPT/STYLE/TITLE/TEXTAREA) is an unused key error.
	 *
	 * @ticket 60229
	 *
	 * @dataProvider data_atomic_element_content_placeholders
	 *
	 * @todo Implement correct handling of atomic elements.
	 *
	 * @covers ::render
	 */
	public function test_special_element_content_placeholder_behavior( string $template_string, string $expected ) {
		$result = T::render( $template_string );
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_atomic_element_content_placeholders() {
		return array(
			// RAWTEXT elements (SCRIPT, STYLE): Content is truly skipped, placeholders preserved literally.
			'SCRIPT content placeholder preserved' => array(
				'<script>var x = "</%name>";</script>',
				'<script>var x = "</%name>";</script>',
			),

			'STYLE content placeholder preserved'  => array(
				'<style>.foo { content: "</%content>"; }</style>',
				'<style>.foo { content: "</%content>"; }</style>',
			),

			// RCDATA elements (TITLE, TEXTAREA): Content is processed but placeholder
			// patterns are not recognized - they're treated as literal text and escaped.
			'TITLE content placeholder escaped'    => array(
				'<title>Hello </%name></title>',
				'<title>Hello &lt;/%name&gt;</title>',
			),

			'TEXTAREA content placeholder escaped' => array(
				'<textarea></%placeholder></textarea>',
				'<textarea>&lt;/%placeholder&gt;</textarea>',
			),
		);
	}

	/**
	 * Verifies leading newline behavior in PRE elements.
	 *
	 * HTML5 specifies that a single leading newline immediately after the
	 * <pre> start tag is ignored. This test documents the template behavior.
	 *
	 * @ticket 60229
	 *
	 * @dataProvider data_pre_element_leading_newline
	 *
	 * @covers ::render
	 */
	public function test_pre_element_leading_newline_behavior( string $template_string, array $replacements, string $expected ) {
		if (
			"\n" === $replacements['replacement'][0] ||
			"\r" === $replacements['replacement'][0]
		) {
			$this->markTestSkipped( 'PRE leading newline handling is not yet correct.' );
		}

		$result = T::render( $template_string, $replacements );
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_pre_element_leading_newline() {
		return array(
			'PRE without newline'                         => array(
				'<pre></%replacement></pre>',
				array( 'replacement' => "line1\nline2" ),
				"<pre>line1\nline2</pre>",
			),

			'PRE with newline'                            => array(
				"<pre>\n</%replacement></pre>",
				array( 'replacement' => "line1\nline2" ),
				"<pre>line1\nline2</pre>",
			),

			'PRE with newline in replacement'             => array(
				"<pre>\n</%replacement></pre>",
				array( 'replacement' => "line1\nline2" ),
				"<pre>line1\nline2</pre>",
			),

			'PRE with newline and newline in replacement' => array(
				"<pre>\n</%replacement></pre>",
				array( 'replacement' => "\nline1\nline2" ),
				"<pre>\n\nline1\nline2</pre>",
			),

			'PRE with newline, newline replacement, and additional contents' => array(
				"<pre>\n</%replacement><!--c--></pre>",
				array( 'replacement' => "\nline1" ),
				"<pre>\n\nline1<!--c--></pre>",
			),
		);
	}

	/**
	 * Verifies render() warns on missing replacement key.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_warns_on_missing_key() {
		T::render( '<p></%name> </%age></p>', array( 'name' => 'Alice' ) );
	}

	/**
	 * Verifies render() warns on unused replacement key.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_warns_on_unused_key() {
		T::render(
			'<p></%name></p>',
			array(
				'name'  => 'Alice',
				'extra' => 'ignored',
			)
		);
	}

	/**
	 * Verifies render() warns when template used in attribute context.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 * @covers ::template
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_warns_on_template_in_attribute_context() {
		T::render(
			'<meta content="</%html>">',
			array( 'html' => T::template( '<b>nested</b>' ) )
		);
	}

	/**
	 * Verifies that static text around placeholders in attributes is escaped.
	 *
	 * @ticket 60229
	 *
	 * @dataProvider data_escapes_static_text_around_placeholder_in_attribute
	 *
	 * @covers ::render
	 */
	public function test_escapes_static_text_around_placeholder_in_attribute( string $template_string, array $replacements, string $expected ) {
		$result = T::render( $template_string, $replacements );
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_escapes_static_text_around_placeholder_in_attribute() {
		return array(
			'leading static text (prefix before placeholder)' => array(
				'<a href="/path/</%slug>">Link</a>',
				array( 'slug' => 'hello' ),
				'<a href="/path/hello">Link</a>',
			),

			'trailing static text (suffix after placeholder)' => array(
				'<a href="</%slug>/page">Link</a>',
				array( 'slug' => 'hello' ),
				'<a href="hello/page">Link</a>',
			),

			'ampersand in trailing static text must be escaped' => array(
				'<a href="</%base>&amp;extra=1">Link</a>',
				array( 'base' => '/search?q=test' ),
				'<a href="/search?q=test&amp;extra=1">Link</a>',
			),

			'ampersand entity in leading static text not double-escaped' => array(
				'<a href="/search?a=1&amp;b=</%val>">Link</a>',
				array( 'val' => '2' ),
				'<a href="/search?a=1&amp;b=2">Link</a>',
			),

			'character reference in trailing static text preserved' => array(
				'<meta name="</%placeholder>&not;">',
				array( 'placeholder' => '' ),
				'<meta name="¬">',
			),

			'two placeholders in href'               => array(
				'<a href="</%base>/</%slug>">link</a>',
				array(
					'base' => '/posts',
					'slug' => 'hello-world',
				),
				'<a href="/posts/hello-world">link</a>',
			),

			'three placeholders building URL'        => array(
				'<a href="</%scheme>://</%host>/</%path>">link</a>',
				array(
					'scheme' => 'https',
					'host'   => 'example.com',
					'path'   => 'page',
				),
				'<a href="https://example.com/page">link</a>',
			),

			'adjacent placeholders (no separator)'   => array(
				'<meta content="</%a></%b>">',
				array(
					'a' => 'Hello',
					'b' => 'World',
				),
				'<meta content="HelloWorld">',
			),

			'placeholders with static text between'  => array(
				'<a href="</%base>?page=</%page>&sort=</%sort>">link</a>',
				array(
					'base' => '/search',
					'page' => '2',
					'sort' => 'date',
				),
				'<a href="/search?page=2&amp;sort=date">link</a>',
			),

			'same placeholder repeated in attribute' => array(
				'<meta content="</%val>-</%val>">',
				array( 'val' => 'test' ),
				'<meta content="test-test">',
			),

			'escaping in multiple placeholders'      => array(
				'<a href="</%base>?q=</%query>">link</a>',
				array(
					'base'  => '/search',
					'query' => 'a&b<c>"d',
				),
				'<a href="/search?q=a&amp;b&lt;c&gt;&quot;d">link</a>',
			),

			'multiple placeholders across multiple attributes' => array(
				'<a href="</%url>" title="</%a> &amp; </%b>">link</a>',
				array(
					'url' => '/page',
					'a'   => 'Alice',
					'b'   => 'Bob',
				),
				'<a href="/page" title="Alice &amp; Bob">link</a>',
			),
		);
	}

	/**
	 * @ticket 60229
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_warns_on_unrecognized_replacements() {
		T::render( '<meta>', array( 'extra' => 'oops' ) );
	}

	/**
	 * @ticket 60229
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_warns_on_omit_replacement() {
		T::render( '<p></% omitted ></p>', array( 'other' => 'value' ) );
	}

	/**
	 * Verifies that boolean true creates a boolean attribute.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_boolean_true_creates_boolean_attribute() {
		$result = T::render(
			'<input disabled="</%disabled>">',
			array( 'disabled' => true )
		);
		$this->assertEqualHTML( '<input disabled>', $result );
	}

	/**
	 * Verifies that boolean false removes the attribute.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_boolean_false_removes_attribute() {
		$result = T::render(
			'<input disabled="</%disabled>" type="text">',
			array( 'disabled' => false )
		);
		$this->assertEqualHTML( '<input type="text">', $result );
	}

	/**
	 * Verifies that null removes the attribute (same as false).
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_null_removes_attribute() {
		$result = T::render(
			'<input class="</%class>" type="text">',
			array( 'class' => null )
		);
		$this->assertEqualHTML( '<input type="text">', $result );
	}

	/**
	 * Verifies that boolean with partial placeholder returns false.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_partial_placeholder_rejects_boolean() {
		$result = T::render(
			'<input class="prefix-</%suffix>">',
			array( 'suffix' => true )
		);
		$this->assertFalse( $result );
	}

	/**
	 * @ticket 60229
	 *
	 * @dataProvider data_boolean_attribute_handling
	 *
	 * @covers ::render
	 */
	public function test_boolean_attribute_handling( string $template, array $replacements, string $expected ) {
		$result = T::render( $template, $replacements );
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_boolean_attribute_handling() {
		return array(
			'true creates boolean attribute'        => array(
				'<input disabled="</%disabled>">',
				array( 'disabled' => true ),
				'<input disabled>',
			),

			'false removes attribute'               => array(
				'<input disabled="</%disabled>" type="text">',
				array( 'disabled' => false ),
				'<input type="text">',
			),

			'null removes attribute'                => array(
				'<input class="</%class>" type="text">',
				array( 'class' => null ),
				'<input type="text">',
			),

			'empty string keeps attribute with empty value' => array(
				'<input value="</%value>">',
				array( 'value' => '' ),
				'<input value="">',
			),

			'mixed boolean and string replacements' => array(
				'<input disabled="</%d>" value="</%v>">',
				array(
					'd' => true,
					'v' => 'test',
				),
				'<input disabled value="test">',
			),

			'multiple attributes, one removed'      => array(
				'<input class="</%c>" id="</%i>" name="field">',
				array(
					'c' => false,
					'i' => 'my-id',
				),
				'<input id="my-id" name="field">',
			),

			'single-quoted attribute with boolean'  => array(
				"<input disabled='</%disabled>'>",
				array( 'disabled' => true ),
				'<input disabled>',
			),
		);
	}

	/**
	 * Verifies render() returns false for integer replacement value.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_returns_false_for_integer_replacement() {
		$result = T::render( '<p></%val></p>', array( 'val' => 123 ) );
		$this->assertFalse( $result );
	}

	/**
	 * Verifies render() returns false for array replacement value.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_returns_false_for_array_replacement() {
		$result = T::render( '<p></%val></p>', array( 'val' => array( 'a', 'b' ) ) );
		$this->assertFalse( $result );
	}

	/**
	 * Verifies render() returns false for object replacement value without __toString.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_returns_false_for_object_replacement() {
		$result = T::render( '<p></%val></p>', array( 'val' => new stdClass() ) );
		$this->assertFalse( $result );
	}

	/**
	 * Verifies render() returns false for null replacement value.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_returns_false_for_null_replacement() {
		$result = T::render( '<p></%val></p>', array( 'val' => null ) );
		$this->assertFalse( $result );
	}

	/**
	 * Verifies render() returns false for boolean replacement value.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::render
	 */
	public function test_render_returns_false_for_boolean_replacement() {
		$result = T::render( '<p></%val></p>', array( 'val' => true ) );
		$this->assertFalse( $result );
	}

	/**
	 * Verifies that duplicate attributes after the placeholder are removed with false.
	 *
	 * HTML may contain duplicate attributes (e.g., from user error or generated HTML).
	 * When false removes the placeholder attribute, duplicates should also be removed.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_duplicate_attribute_removed_with_false() {
		// The "disabled" attribute appears twice: once with placeholder, once as boolean.
		$result = T::render(
			'<input disabled="</%d>" disabled type="text">',
			array( 'd' => false )
		);
		// Both occurrences of "disabled" should be removed.
		$this->assertEqualHTML( '<input type="text">', $result );
	}

	/**
	 * Verifies that duplicate attributes after the placeholder are removed with null.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_duplicate_attribute_removed_with_null() {
		$result = T::render(
			'<input class="</%c>" class="extra" type="text">',
			array( 'c' => null )
		);
		$this->assertEqualHTML( '<input type="text">', $result );
	}

	/**
	 * Verifies that multiple duplicate attributes are all removed with false.
	 *
	 * When an attribute appears more than twice, all occurrences should be removed.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_multiple_duplicate_attributes_removed() {
		// Three occurrences of "disabled": placeholder + two duplicates.
		$result = T::render(
			'<input disabled="</%d>" disabled disabled type="text">',
			array( 'd' => false )
		);
		$this->assertEqualHTML( '<input type="text">', $result );
	}

	/**
	 * Test that a template replacement cannot template structure HTML.
	 *
	 * @ticket 60229
	 *
	 * @covers ::render
	 */
	public function test_replacements_cannot_modify_template_structure() {
		$this->markTestSkipped( 'Template HTML structure protection is not implemented.' );

		// Three occurrences of "disabled": placeholder + two duplicates.
		$result = T::render(
			'<a></%link-text></a>',
			array( 'link-text' => WP_HTML_Template::template( '<a>A elements cannot nest in HTML</a>' ) )
		);
		$this->assertFalse( $result, 'Should have rejected the template with an invalid replacement.' );
	}
}
