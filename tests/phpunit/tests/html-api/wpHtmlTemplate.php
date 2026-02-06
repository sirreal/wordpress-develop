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
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_escapes_text_adjacent_to_angle_brackets() {
		$template_string = 'a<</%tag-name>>s';
		$replacements    = array( 'tag-name' => 'i' );
		$result          = T::from( $template_string )->bind( $replacements )->render();

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
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_replaces_only_in_first_duplicate_attribute() {
		$template_string = '<meta a="</%replace></%replace-2>" a="</% no-replace >">';
		$replacements    = array(
			'replace'   => 'O',
			'replace-2' => 'K',
		);

		$result = T::from( $template_string )->bind( $replacements )->render();

		$expected = '<meta a="OK">';
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that attribute replacement is not recursive.
	 *
	 * @ticket 60229
	 *
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_attribute_replacement_is_not_recursive() {
		$template_string = '<div a="<%/replace>"><%/replace></div>';
		$replacements    = array(
			'replace' => '<%/replace>',
		);

		$result = T::from( $template_string )->bind( $replacements )->render();

		$expected = '<div a="&lt;/%replace&gt;">&lt;/%replace&gt;</div>';
		$this->assertEqualHTML( $expected, $result );
	}

	/**
	 * Verifies that placeholder names allow surrounding whitespace.
	 *
	 * @ticket 60229
	 *
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_placeholder_names_allow_surrounding_whitespace() {
		$template_string = "<meta name='</%\tn\n>' content='</% c\r\f>'>";
		$replacements    = array(
			'n' => 'the name',
			'c' => 'the "content" & whatever else',
		);

		$result = T::from( $template_string )->bind( $replacements )->render();

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
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_escapes_ampersand_to_prevent_character_reference_injection() {
		$template_string = '<meta name="&</% placeholder >;">';
		$replacements    = array( 'placeholder' => 'not' );
		$result          = T::from( $template_string )->bind( $replacements )->render();

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
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::bind
	 */
	public function test_rejects_nested_template_in_attribute_value() {
		$template_string = '<meta name="not-allowed" description="</%html>">';
		$replacements    = array(
			'html' => T::from( '<strong>This is not allowed!</strong>' ),
		);
		$this->assertFalse( T::from( $template_string )->bind( $replacements )->render() );
	}

	/**
	 * @dataProvider data_template
	 *
	 * @ticket 60229
	 *
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_template( string $template_string, array $replacements, string $expected ) {
		$result = T::from( $template_string )->bind( $replacements )->render();
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

			'numeric placeholders'               => array(
				'<p>Hello, </%0> and </%1>!</p>',
				array( 'Alice', 'Bob' ),
				'<p>Hello, Alice and Bob!</p>',
			),

			'repeated placeholders'              => array(
				'<p></%0>, </% 0 >, </%name>, & </%name>!</p>',
				array(
					'Alice',
					'name' => 'Bob',
				),
				'<p>Alice, Alice, Bob, &amp; Bob!</p>',
			),

			'nested template replacement'        => array(
				'<p>Hello, </%html>',
				array( 'html' => WP_HTML_Template::from( '<i>Alice</i> & <i>Bob</i>' ) ),
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
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_real_world_examples( string $template_string, array $replacements, string $expected ) {
		$result = WP_HTML_Template::from( $template_string )->bind( $replacements )->render();
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
				'title'  => WP_HTML_Template::from( '\'<i></%italic></i>\' & <b>"</%bold>"</b>' )
					->bind(
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

	/**
	 * Verifies nested templates work correctly in a definition list.
	 *
	 * @ticket 60229
	 *
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_nested_templates_in_definition_list() {
		$row_template = WP_HTML_Template::from( "<dt></%term></dt>\n<dd></%definition></dd>" );

		$row_replacements = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$row_replacements[ "row-{$i}" ] = $row_template->bind(
				array(
					'term'       => "Term \"{$i}\"",
					'definition' => WP_HTML_Template::from( '<abbr title="</%expansion>">IYKYK</abbr>: </%i>' )
						->bind(
							array(
								'i'         => (string) $i,
								'expansion' => '"If You Know You Know"',
							)
						),
				)
			);
		}

		$result = WP_HTML_Template::from(
			<<<'HTML'
			<dl>
			</%row-1>
			</%row-2>
			</%row-3>
			</dl>
			HTML
		)->bind( $row_replacements )->render();

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
	 * Verifies table templates are not yet supported.
	 *
	 * @ticket 60229
	 *
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_table_templates_not_yet_supported() {
		$this->markTestSkipped( 'IN TABLE templates are not supported yet.' );
		$header_tpl = WP_HTML_Template::from( '<tr><th></% ID ><th></% name ><th></% value ><th></% link >' )
			->bind(
				array(
					'ID'    => 'ID',
					'name'  => 'Name',
					'value' => 'Value',
					'link'  => 'Link',
				)
			);
		$row_tpl    = WP_HTML_Template::from( '<tr><td></% ID ><td></% name ><td></% value ><td></% link >' );

		$row_gen = ( function () {
			static $i = 1;
			yield array(
				'ID'    => $i,
				'name'  => 'Name {$i}',
				'value' => WP_HTML_Template::from( 'Value <b>{$i}</b>' )->bind( array( 'i' => $i ) ),
				'link'  => WP_HTML_Template::from( '<a href="</%url>"></%link-name></a>' )
					->bind(
						array(
							'url'       => '/example/1',
							'link-name' => 'Click here',
						)
					),
			);
		} )();

		$result = WP_HTML_Template::from(
			<<<'HTML'
			<table>
			<thead></%header>
			<tbody>
			</%row-1>
			</%row-2>
			</%row-3>
			HTML
		)->bind(
			array(
				'header' => $header_tpl,
				'row-1'  => $row_tpl->bind( $row_gen->next() ),
				'row-2'  => $row_tpl->bind( $row_gen->next() ),
				'row-3'  => $row_tpl->bind( $row_gen->next() ),
			)
		)->render();

		$expected =
			<<<'HTML'
			HTML;
		$this->assertEqualHTML( $expected, $result );
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
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_atomic_element_attributes_are_replaced( string $template_string, array $replacements, string $expected ) {
		$result = T::from( $template_string )->bind( $replacements )->render();
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
	 * @covers ::from
	 * @covers ::render
	 */
	public function test_special_element_content_placeholder_behavior( string $template_string, string $expected ) {
		$result = T::from( $template_string )->render();
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_atomic_element_content_placeholders() {
		return array(
			// RAWTEXT elements (SCRIPT, STYLE): Content is truly skipped, placeholders preserved literally.
			'SCRIPT content placeholder preserved'        => array(
				'<script>var x = "</%name>";</script>',
				'<script>var x = "</%name>";</script>',
			),

			'STYLE content placeholder preserved'         => array(
				'<style>.foo { content: "</%content>"; }</style>',
				'<style>.foo { content: "</%content>"; }</style>',
			),

			// RCDATA elements (TITLE, TEXTAREA): Content is processed but placeholder
			// patterns are not recognized - they're treated as literal text and escaped.
			'TITLE content placeholder escaped'           => array(
				'<title>Hello </%name></title>',
				'<title>Hello &lt;/%name&gt;</title>',
			),

			'TEXTAREA content placeholder escaped'        => array(
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
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_pre_element_leading_newline_behavior( string $template_string, array $replacements, string $expected ) {
		$this->markTestSkipped( 'PRE newline handling is not yet correct.' );

		$result = T::from( $template_string )->bind( $replacements )->render();
		$this->assertEqualHTML( $expected, $result );
	}

	public static function data_pre_element_leading_newline() {
		return array(
			'PRE without newline'        => array(
				"<pre></%code></pre>",
				array( 'code' => "line1\nline2"),
				"<pre>line1\nline2</pre>",
			),

			'PRE with newline' => array(
				"<pre>\n</%code></pre>",
				array( 'code' =>  "line1\nline2"),
				"<pre>line1\nline2</pre>",
			),

			'PRE with newline in replacement' => array(
				"<pre>\n</%code></pre>",
				array( 'code' =>   "line1\nline2"),
				"<pre>line1\nline2</pre>",
			),

			'PRE with newline and newline in replacement' => array(
				"<pre>\n</%code></pre>",
				array( 'code' => "\nline1\nline2" ),
				"<pre>\n\nline1\nline2</pre>",
			),

			'PRE with newline, newline replacement, and additional contents' => array(
				"<pre>\n</%code><!--c--></pre>",
				array( 'code' => "\nline1" ),
				"<pre>\n\nline1<!--c--></pre>",
			),
		);
	}

	/**
	 * Verifies bind() warns on missing replacement key.
	 *
	 * @ticket 60229
	 *
	 * @covers ::bind
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::bind
	 */
	public function test_bind_warns_on_missing_key() {
		$template = T::from( '<p></%name> </%age></p>' );
		$template->bind( array( 'name' => 'Alice' ) );
	}

	/**
	 * Verifies bind() warns on unused replacement key.
	 *
	 * @ticket 60229
	 *
	 * @covers ::bind
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::bind
	 */
	public function test_bind_warns_on_unused_key() {
		$template = T::from( '<p></%name></p>' );
		$template->bind( array( 'name' => 'Alice', 'extra' => 'ignored' ) );
	}

	/**
	 * Verifies bind() warns when template used in attribute context.
	 *
	 * @ticket 60229
	 *
	 * @covers ::bind
	 *
	 * @expectedIncorrectUsage WP_HTML_Template::bind
	 */
	public function test_bind_warns_on_template_in_attribute_context() {
		$template = T::from( '<meta content="</%html>">' );
		$template->bind( array( 'html' => T::from( '<b>nested</b>' ) ) );
	}

	/**
	 * Verifies that get_placeholders returns placeholder metadata.
	 *
	 * @ticket 60229
	 *
	 * @covers ::get_placeholders
	 */
	public function test_get_placeholders_returns_metadata() {
		$template = T::from( '<p class="</%class>"></%content></p>' );

		$placeholders = $template->get_placeholders();

		$this->assertArrayHasKey( 'class', $placeholders );
		$this->assertArrayHasKey( 'content', $placeholders );
		$this->assertSame( 'attribute', $placeholders['class']['context'] );
		$this->assertSame( 'text', $placeholders['content']['context'] );
	}

	/**
	 * Verifies text placeholders are extracted with correct offsets.
	 *
	 * @ticket 60229
	 *
	 * @covers ::get_placeholders
	 */
	public function test_extracts_text_placeholders_with_offsets() {
		$template = T::from( '<p></%name></p>' );

		$placeholders = $template->get_placeholders();

		$this->assertArrayHasKey( 'name', $placeholders );
		$this->assertSame( 'text', $placeholders['name']['context'] );
		$this->assertCount( 1, $placeholders['name']['offsets'] );
		// <p> is 3 chars, so </%name> starts at offset 3
		$this->assertSame( 3, $placeholders['name']['offsets'][0][0] );
		// </%name> is 8 chars
		$this->assertSame( 8, $placeholders['name']['offsets'][0][1] );
	}

	/**
	 * Verifies repeated text placeholders are captured.
	 *
	 * @ticket 60229
	 *
	 * @covers ::get_placeholders
	 */
	public function test_extracts_repeated_text_placeholders() {
		$template = T::from( '<p></%name> and </%name></p>' );

		$placeholders = $template->get_placeholders();

		$this->assertArrayHasKey( 'name', $placeholders );
		$this->assertCount( 2, $placeholders['name']['offsets'] );
	}

	/**
	 * Verifies attribute placeholders are extracted.
	 *
	 * @ticket 60229
	 *
	 * @covers ::get_placeholders
	 */
	public function test_extracts_attribute_placeholders() {
		$template = T::from( '<meta name="</%n>" content="</%c>">' );

		$placeholders = $template->get_placeholders();

		$this->assertArrayHasKey( 'n', $placeholders );
		$this->assertArrayHasKey( 'c', $placeholders );
		$this->assertSame( 'attribute', $placeholders['n']['context'] );
		$this->assertSame( 'attribute', $placeholders['c']['context'] );
	}

	/**
	 * Verifies context promotion from text to attribute.
	 *
	 * When a placeholder appears in both text and attribute contexts,
	 * the attribute context takes precedence (more restrictive escaping).
	 *
	 * @ticket 60229
	 *
	 * @covers ::get_placeholders
	 */
	/**
	 * Verifies that static text around placeholders in attributes is escaped.
	 *
	 * @ticket 60229
	 *
	 * @covers ::from
	 * @covers ::bind
	 * @covers ::render
	 */
	public function test_escapes_static_text_around_placeholder_in_attribute() {
		// Leading static text (prefix before placeholder)
		$result = T::from( '<a href="/path/</%slug>">Link</a>' )
			->bind( array( 'slug' => 'hello' ) )
			->render();
		$this->assertEqualHTML( '<a href="/path/hello">Link</a>', $result );

		// Trailing static text (suffix after placeholder)
		$result = T::from( '<a href="</%slug>/page">Link</a>' )
			->bind( array( 'slug' => 'hello' ) )
			->render();
		$this->assertEqualHTML( '<a href="hello/page">Link</a>', $result );

		// Ampersand in trailing static text must be escaped
		$result = T::from( '<a href="</%base>&amp;extra=1">Link</a>' )
			->bind( array( 'base' => '/search?q=test' ) )
			->render();
		$this->assertEqualHTML( '<a href="/search?q=test&amp;extra=1">Link</a>', $result );

		// Ampersand entity in leading static text must not be double-escaped
		$result = T::from( '<a href="/search?a=1&amp;b=</%val>">Link</a>' )
			->bind( array( 'val' => '2' ) )
			->render();
		$this->assertEqualHTML( '<a href="/search?a=1&amp;b=2">Link</a>', $result );

		// Character reference in trailing static text is preserved (not double-escaped)
		$result = T::from( '<meta name="</%placeholder>&not;">' )
			->bind( array( 'placeholder' => '' ) )
			->render();
		$this->assertEqualHTML( '<meta name="¬">', $result );
	}

	public function test_context_promotion_text_to_attribute() {
		$template = T::from( '<a href="</%url>"></%url></a>' );

		$placeholders = $template->get_placeholders();

		$this->assertArrayHasKey( 'url', $placeholders );
		// Both occurrences should use attribute context
		$this->assertSame( 'attribute', $placeholders['url']['context'] );
		$this->assertCount( 2, $placeholders['url']['offsets'] );
	}
}
