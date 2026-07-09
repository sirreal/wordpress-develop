<?php
/**
 * Unit tests covering WP_HTML_Processor functionality for replacing
 * the inner content of an element.
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @since 7.1.0
 */

/**
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Processor
 */
class Tests_HtmlApi_WpHtmlProcessor_SetInnerHtml extends WP_UnitTestCase {
	/**
	 * Ensures that inner content is replaced with the serialization of the
	 * content parsed as a fragment in the context of the matched element.
	 *
	 * The content is interpreted the way the DOM `innerHTML` setter would
	 * interpret it: syntax which parses to nothing is dropped, unclosed
	 * elements are closed, and text may be re-encoded.
	 *
	 * @covers ::set_inner_html
	 *
	 * @dataProvider data_content_replaced_within_context_element
	 *
	 * @param string $html      Input HTML document.
	 * @param string $target    Tag name of the element whose content is replaced.
	 * @param string $new_html  Content passed to `set_inner_html()`.
	 * @param string $expected  Expected document after the replacement.
	 */
	public function test_replaces_inner_html( string $html, string $target, string $new_html, string $expected ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertTrue(
			$processor->next_tag( $target ),
			"Could not find {$target} element in test document: check test setup."
		);

		$this->assertTrue(
			$processor->set_inner_html( $new_html ),
			'Should have accepted content which is fully contained within the context element.'
		);

		$this->assertSame(
			$expected,
			$processor->get_updated_html(),
			'Should have replaced the inner content of the context element and nothing else.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_content_replaced_within_context_element(): array {
		return array(
			'Plain text'                    => array( '<div>old content</div>after', 'DIV', 'fresh', '<div>fresh</div>after' ),
			'Empty content'                 => array( '<div>full of stuff</div>', 'DIV', '', '<div></div>' ),
			'Element markup'                => array( '<div>a<span>b</span>c</div>', 'DIV', '<p>replaced</p>', '<div><p>replaced</p></div>' ),
			'Same element nested'           => array( '<div>x</div>', 'DIV', '<div>nested</div>', '<div><div>nested</div></div>' ),
			'A nested in non-A context'     => array( '<div>x</div>', 'DIV', '<a>nested</a>', '<div><a>nested</a></div>' ),
			'A with phrasing content'       => array( '<a href="/wp/">WordPress</a>', 'A', 'the <em>best</em> CMS', '<a href="/wp/">the <em>best</em> CMS</a>' ),
			'Implicitly-closed LI'          => array( '<ul><li>one<li>two</ul>', 'LI', 'replaced', '<ul><li>replaced<li>two</ul>' ),
			'Unclosed element at end'       => array( '<div><p>dangling', 'P', 'replaced', '<div><p>replaced' ),
			'Comment'                       => array( '<div>x</div>', 'DIV', '<!-- note -->', '<div><!-- note --></div>' ),
			'TD cell content'               => array( '<table><tbody><tr><td>old</td></tr></tbody></table>', 'TD', '<span>new</span>', '<table><tbody><tr><td><span>new</span></td></tr></tbody></table>' ),
			'TEMPLATE content'              => array( '<template><p>x</p></template><span>y</span>', 'TEMPLATE', '<em>t</em>', '<template><em>t</em></template><span>y</span>' ),

			// The document receives the serialization of the parsed content, not the given text.
			'Implied closers made explicit' => array( '<div>x</div><p>kept</p>', 'DIV', '<p>one<p>two', '<div><p>one</p><p>two</p></div><p>kept</p>' ),
			'Unclosed formatting closed'    => array( '<div>x</div><span>after</span>', 'DIV', '<b>bold', '<div><b>bold</b></div><span>after</span>' ),
			'Implied TBODY made explicit'   => array( '<table><tbody><tr><td>a</td></tr></tbody></table>', 'TABLE', '<tr><td>b</td></tr>', '<table><tbody><tr><td>b</td></tr></tbody></table>' ),
			'Implied OPTION closers'        => array( '<select><option>a</option></select>', 'SELECT', '<option>x<option>y', '<select><option>x</option><option>y</option></select>' ),
			'SVG foreign content'           => array( '<svg><circle r="1"></circle></svg>', 'SVG', '<rect width="4"/>', '<svg><rect width="4" /></svg>' ),
			'Text is re-encoded'            => array( '<div>x</div>', 'DIV', 'a & b < c', '<div>a &amp; b &lt; c</div>' ),

			// Syntax which fragment parsing ignores is dropped, as the DOM innerHTML setter drops it.
			'Context closer dropped'        => array( '<div>x</div>y', 'DIV', '</div><p>escaped', '<div><p>escaped</p></div>y' ),
			'BODY tag dropped'              => array( '<div>x</div>', 'DIV', '<body class="never">y', '<div>y</div>' ),
			'HTML tag dropped'              => array( '<div>x</div>', 'DIV', '<html lang="en">y', '<div>y</div>' ),
			'Stray TD dropped'              => array( '<div>x</div>', 'DIV', '<td>y', '<div>y</div>' ),
			'Nested FORM dropped'           => array( '<form>x</form>', 'FORM', '<form>y</form>', '<form>y</form>' ),
			'FRAMESET dropped'              => array( '<div>x</div>', 'DIV', '<frameset>', '<div></div>' ),
			'BODY closer dropped'           => array( '<div>x</div><p>y</p>', 'DIV', 'z</body><!-- c -->', '<div>z<!-- c --></div><p>y</p>' ),
		);
	}

	/**
	 * Ensures that content is rejected and the document unmodified when the
	 * parsed content cannot be represented in HTML text at this location
	 * without modifying the structure outside of the context element.
	 *
	 * Fragment parsing accepts this content and produces a tree — a DIV
	 * assigned `<a>link</a>` via DOM `innerHTML` inside an A element holds a
	 * nested A element — but no HTML text reproduces that tree in this
	 * position: parsing the updated document would close the context element
	 * or restructure the document around it.
	 *
	 * @covers ::set_inner_html
	 *
	 * @dataProvider data_content_escaping_context_element
	 *
	 * @param string $html     Input HTML document.
	 * @param string $target   Tag name of the element whose content would be replaced.
	 * @param string $new_html Content passed to `set_inner_html()`.
	 */
	public function test_rejects_content_escaping_context_element( string $html, string $target, string $new_html ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertTrue(
			$processor->next_tag( $target ),
			"Could not find {$target} element in test document: check test setup."
		);

		$this->assertFalse(
			$processor->set_inner_html( $new_html ),
			'Should have rejected content which would modify the structure outside of the context element.'
		);

		$this->assertSame(
			$html,
			$processor->get_updated_html(),
			'Should not have modified the document when rejecting the content.'
		);

		$this->assertNull(
			$processor->get_last_error(),
			'Should not have entered a failed state when rejecting the content.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_content_escaping_context_element(): array {
		return array(
			'A inside A'                 => array( '<a href="/wp/">WordPress</a>', 'A', '<a>links cannot nest</a>' ),
			'LI inside LI'               => array( '<ul><li>one</li><li>two</li></ul>', 'LI', '<li>lists collapse' ),
			'P inside P'                 => array( '<p>one</p>', 'P', '<p>paragraphs close paragraphs</p>' ),
			'Heading inside heading'     => array( '<h1>title</h1>', 'H1', '<h2>headings close headings</h2>' ),
			'BUTTON inside BUTTON'       => array( '<button>x</button>', 'BUTTON', '<button>buttons close buttons' ),
			'OPTION inside OPTION'       => array( '<select><option>a</option></select>', 'OPTION', '<option>options close options' ),
			/*
			 * Rejected today because the HTML Processor cannot parse text inside
			 * a TABLE. This must remain rejected once foster parenting is
			 * supported, because the text would be re-parented outside of the
			 * context element.
			 */
			'Text directly inside TABLE' => array( '<table><tbody><tr><td>a</td></tr></tbody></table>', 'TABLE', 'text would foster-parent' ),
			'Foreign content breakout'   => array( '<svg><circle r="1"></circle></svg>text', 'SVG', '</svg><b>escaped' ),
		);
	}

	/**
	 * Ensures that content is rejected in SELECT contexts except for tokens
	 * which parse identically under both revisions of SELECT parsing rules.
	 *
	 * CANARY: the HTML Processor parses SELECT content under rules which
	 * predate the "customizable select element" changes to HTML, ignoring
	 * tags that up-to-date parsers preserve. Rather than silently applying
	 * outdated rules, such content is rejected. When SELECT parsing is
	 * updated these cases should be reevaluated and the restriction removed.
	 * See https://core.trac.wordpress.org/ticket/63736.
	 *
	 * @covers ::set_inner_html
	 *
	 * @dataProvider data_content_restricted_in_select_context
	 *
	 * @param string $html     Input HTML document.
	 * @param string $target   Tag name of the element whose content would be replaced.
	 * @param string $new_html Content passed to `set_inner_html()`.
	 */
	public function test_rejects_restricted_content_in_select_context( string $html, string $target, string $new_html ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertTrue(
			$processor->next_tag( $target ),
			"Could not find {$target} element in test document: check test setup."
		);

		$this->assertFalse(
			$processor->set_inner_html( $new_html ),
			'Should have rejected content whose parsing inside a SELECT element differs across revisions of HTML.'
		);

		$this->assertSame(
			$html,
			$processor->get_updated_html(),
			'Should not have modified the document when rejecting the content.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_content_restricted_in_select_context(): array {
		return array(
			'A inside SELECT'             => array( '<select><option>a</option></select>', 'SELECT', '<a>link' ),
			'DIV inside SELECT'           => array( '<select><option>a</option></select>', 'SELECT', '<div>block' ),
			'A inside OPTION in SELECT'   => array( '<select><option>a</option></select>', 'OPTION', '<a>link' ),
			'SELECT closer inside OPTION' => array( '<select><option>a</option></select>', 'OPTION', '</select>escaped' ),
			'A in SELECT within content'  => array( '<div>x</div>after', 'DIV', '<select><a>link' ),
		);
	}

	/**
	 * Ensures that content which cannot be parsed as a fragment is rejected
	 * and leaves the processor and document untouched.
	 *
	 * CANARY: these rejections pin current parser limitations, not the
	 * intended contract. Stray formatting-element closers require adoption
	 * agency support which the HTML Processor does not implement for the
	 * "any other end tag" case. Fragment parsing drops these tokens, so when
	 * support is added these cases should become accepted with the stray
	 * closer removed from the content.
	 *
	 * @covers ::set_inner_html
	 *
	 * @dataProvider data_content_currently_unparseable
	 *
	 * @param string $html     Input HTML document.
	 * @param string $target   Tag name of the element whose content would be replaced.
	 * @param string $new_html Content passed to `set_inner_html()`.
	 */
	public function test_rejects_content_it_cannot_parse( string $html, string $target, string $new_html ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertTrue(
			$processor->next_tag( $target ),
			"Could not find {$target} element in test document: check test setup."
		);

		$this->assertFalse(
			$processor->set_inner_html( $new_html ),
			'Should have rejected content which cannot currently be parsed as a fragment.'
		);

		$this->assertSame(
			$html,
			$processor->get_updated_html(),
			'Should not have modified the document when rejecting the content.'
		);

		$this->assertNull(
			$processor->get_last_error(),
			'Should not have entered a failed state when rejecting the content.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_content_currently_unparseable(): array {
		return array(
			'Stray A closer'               => array( '<a href="/">x</a>y', 'A', '</a>I closed the container' ),
			'Stray B closer in formatting' => array( '<b><div>x</div>y</b>z', 'DIV', 'w</b>v' ),
		);
	}

	/**
	 * Ensures that elements which cannot contain HTML content reject all content.
	 *
	 * @covers ::set_inner_html
	 *
	 * @dataProvider data_unsupported_context_elements
	 *
	 * @param string $html   Input HTML document.
	 * @param string $target Tag name of the unsupported context element.
	 */
	public function test_rejects_unsupported_context_elements( string $html, string $target ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertTrue(
			$processor->next_tag( $target ),
			"Could not find {$target} element in test document: check test setup."
		);

		$this->assertFalse(
			$processor->set_inner_html( 'anything' ),
			"Should have rejected content for unsupported context element {$target}."
		);

		$this->assertSame(
			$html,
			$processor->get_updated_html(),
			'Should not have modified the document when rejecting the content.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_unsupported_context_elements(): array {
		return array(
			'Void IMG'                => array( '<img src="a.png">', 'IMG' ),
			'Void BR'                 => array( '<br>', 'BR' ),
			'Void INPUT'              => array( '<input type="text">', 'INPUT' ),
			'Self-contained SCRIPT'   => array( '<script>let a;</script>', 'SCRIPT' ),
			'Self-contained STYLE'    => array( '<style>p {}</style>', 'STYLE' ),
			'Self-contained TEXTAREA' => array( '<textarea>text</textarea>', 'TEXTAREA' ),
			'Self-contained TITLE'    => array( '<title>text</title>', 'TITLE' ),
			'Self-contained IFRAME'   => array( '<iframe src="/"></iframe>', 'IFRAME' ),
			'Self-contained XMP'      => array( '<xmp>text</xmp>', 'XMP' ),
		);
	}

	/**
	 * Ensures that content is rejected when the processor is not paused on
	 * the opening tag of an element found in the input HTML text.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_rejects_when_not_on_a_tag_opener() {
		$processor = WP_HTML_Processor::create_fragment( '<div>x</div>' );
		$this->assertFalse(
			$processor->set_inner_html( 'new' ),
			'Should have rejected content before the processor started scanning.'
		);

		$processor = WP_HTML_Processor::create_fragment( 'just text' );
		$processor->next_token();
		$this->assertSame( '#text', $processor->get_token_type(), 'Should have matched a text node: check test setup.' );
		$this->assertFalse(
			$processor->set_inner_html( 'new' ),
			'Should have rejected content when matched on a text node.'
		);

		$processor = WP_HTML_Processor::create_fragment( '<div>x</div>' );
		$processor->next_tag(
			array(
				'tag_name'    => 'DIV',
				'tag_closers' => 'visit',
			)
		);
		$processor->next_tag(
			array(
				'tag_name'    => 'DIV',
				'tag_closers' => 'visit',
			)
		);
		$this->assertTrue( $processor->is_tag_closer(), 'Should have matched the DIV closer: check test setup.' );
		$this->assertFalse(
			$processor->set_inner_html( 'new' ),
			'Should have rejected content when matched on a tag closer.'
		);

		$processor = WP_HTML_Processor::create_fragment( '<div>x</div>' );
		while ( $processor->next_token() ) {
			continue;
		}
		$this->assertFalse(
			$processor->set_inner_html( 'new' ),
			'Should have rejected content after the document was fully processed.'
		);
	}

	/**
	 * Ensures that content is rejected on elements which do not appear in the
	 * input HTML text, because there is no place in the text for the content.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_rejects_virtual_nodes() {
		$processor = WP_HTML_Processor::create_fragment( '<table><tr><td>x</td></tr></table>' );
		$this->assertTrue(
			$processor->next_tag( 'TBODY' ),
			'Could not find implied TBODY element in test document: check test setup.'
		);

		$this->assertFalse(
			$processor->set_inner_html( '<tr><td>y</td></tr>' ),
			'Should have rejected content on a TBODY element which does not appear in the input HTML text.'
		);
	}

	/**
	 * Ensures that the processor remains paused on the opening tag of the
	 * context element after a replacement and continues into the new content.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_remains_on_context_element_and_parses_new_content() {
		$processor = WP_HTML_Processor::create_fragment( '<div>old</div><span>after</span>' );
		$processor->next_tag( 'DIV' );
		$this->assertTrue( $processor->set_inner_html( '<p>new' ), 'Should have accepted contained content.' );

		$this->assertSame( 'DIV', $processor->get_tag(), 'Should have remained paused on the context element.' );
		$this->assertFalse( $processor->is_tag_closer(), 'Should have remained paused on the opening tag.' );

		$this->assertTrue( $processor->next_token(), 'Should have found the newly-set content.' );
		$this->assertSame( 'P', $processor->get_tag(), 'Should have parsed the new content in place.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'DIV', 'P' ),
			$processor->get_breadcrumbs(),
			'Should have found the new content inside the context element.'
		);

		$this->assertTrue( $processor->next_tag( 'SPAN' ), 'Should have continued into the content following the context element.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'SPAN' ),
			$processor->get_breadcrumbs(),
			'Should have left the structure after the context element unmodified.'
		);
	}

	/**
	 * Ensures that a processor remains usable after rejecting content.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_remains_usable_after_rejecting_content() {
		$processor = WP_HTML_Processor::create_fragment( '<a>x</a><p>tail</p>' );
		$processor->next_tag( 'A' );
		$this->assertFalse( $processor->set_inner_html( '<a>nested</a>' ), 'Should have rejected an A element inside an A element.' );

		$this->assertSame( 'A', $processor->get_tag(), 'Should have remained paused on the context element.' );
		$this->assertTrue( $processor->next_tag( 'P' ), 'Should have continued scanning after rejecting content.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'P' ),
			$processor->get_breadcrumbs(),
			'Should have preserved proper structure after rejecting content.'
		);
	}

	/**
	 * Ensures that replacements are rejected when the document following the
	 * context element contains markup the HTML Processor cannot verify.
	 *
	 * CANARY: this pins conservative behavior tied to current parser support.
	 * The content itself is safe; the rejection occurs because the document
	 * following the context element cannot be reparsed for verification.
	 * When the HTML Processor learns to parse this document, the replacement
	 * should be accepted and this test updated.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_rejects_when_following_content_cannot_be_verified() {
		$html      = '<div>x</div><table>text-needs-foster-parenting';
		$processor = WP_HTML_Processor::create_fragment( $html );
		$processor->next_tag( 'DIV' );

		$this->assertFalse(
			$processor->set_inner_html( 'safe content' ),
			'Should have rejected content when the following document cannot be verified.'
		);

		$this->assertNull(
			$processor->get_last_error(),
			'Should not have entered a failed state when the verification parse failed.'
		);

		$this->assertSame( $html, $processor->get_updated_html(), 'Should not have modified the document.' );
	}

	/**
	 * Ensures that enqueued attribute updates on the context element are
	 * preserved when replacing its inner content.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_combines_with_attribute_updates() {
		$processor = WP_HTML_Processor::create_fragment( '<div>old</div>' );
		$processor->next_tag( 'DIV' );
		$processor->set_attribute( 'class', 'updated' );

		$this->assertTrue( $processor->set_inner_html( 'new' ), 'Should have accepted contained content.' );
		$this->assertSame(
			'<div class="updated">new</div>',
			$processor->get_updated_html(),
			'Should have applied both the attribute update and the content replacement.'
		);
	}

	/**
	 * Ensures that bookmarks into the replaced content are released while
	 * bookmarks outside of it survive and remain usable.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_releases_bookmarks_into_replaced_content() {
		$processor = WP_HTML_Processor::create_fragment( '<div><p>inner</p></div><span>after</span>' );
		$processor->next_tag( 'DIV' );
		$processor->set_bookmark( 'target' );
		$processor->next_tag( 'P' );
		$processor->set_bookmark( 'inside' );
		$processor->next_tag( 'SPAN' );
		$processor->set_bookmark( 'outside' );
		$processor->seek( 'target' );

		$this->assertTrue( $processor->set_inner_html( 'replaced' ), 'Should have accepted contained content.' );

		$this->assertFalse(
			$processor->has_bookmark( 'inside' ),
			'Should have released the bookmark pointing into the replaced content.'
		);

		$this->assertTrue(
			$processor->has_bookmark( 'outside' ),
			'Should have preserved the bookmark pointing after the context element.'
		);

		$this->assertTrue( $processor->seek( 'outside' ), 'Should have sought to the preserved bookmark.' );
		$this->assertSame( 'SPAN', $processor->get_tag(), 'Should have found the originally-bookmarked element.' );

		$this->assertSame(
			'<div>replaced</div><span>after</span>',
			$processor->get_updated_html(),
			'Should have replaced the inner content of the context element and nothing else.'
		);
	}

	/**
	 * Ensures that inner content may be replaced in full documents.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_replaces_inner_html_in_full_parser() {
		$processor = WP_HTML_Processor::create_full_parser(
			'<!DOCTYPE html><html><body><div>x</div><p>y</p></body></html>'
		);
		$processor->next_tag( 'DIV' );

		$this->assertTrue( $processor->set_inner_html( '<em>new</em>' ), 'Should have accepted contained content.' );
		$this->assertSame(
			'<!DOCTYPE html><html><body><div><em>new</em></div><p>y</p></body></html>',
			$processor->get_updated_html(),
			'Should have replaced the inner content of the context element and nothing else.'
		);
	}

	/**
	 * Ensures that a BODY closer inside content is dropped in a full document,
	 * where it could otherwise re-target following content to the HTML element.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_drops_body_closer_in_full_parser() {
		$processor = WP_HTML_Processor::create_full_parser(
			'<!DOCTYPE html><html><body><div>x</div><p>y</p></body></html>'
		);
		$processor->next_tag( 'DIV' );

		$this->assertTrue(
			$processor->set_inner_html( 'z</body><!-- c -->' ),
			'Should have accepted content whose ignored BODY closer is dropped.'
		);
		$this->assertSame(
			'<!DOCTYPE html><html><body><div>z<!-- c --></div><p>y</p></body></html>',
			$processor->get_updated_html(),
			'Should have dropped the BODY closer and contained the comment inside the context element.'
		);
	}
}
