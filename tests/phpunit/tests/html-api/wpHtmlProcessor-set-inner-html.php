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
	 * Ensures that inner content is replaced when the new content remains
	 * fully contained within the context element.
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
			'Markup with implied closers'   => array( '<div>x</div><p>kept</p>', 'DIV', '<p>one<p>two', '<div><p>one<p>two</div><p>kept</p>' ),
			'Same element nested'           => array( '<div>x</div>', 'DIV', '<div>nested</div>', '<div><div>nested</div></div>' ),
			'Identical content'             => array( '<div><em>same</em></div>', 'DIV', '<em>same</em>', '<div><em>same</em></div>' ),
			'A with phrasing content'       => array( '<a href="/wp/">WordPress</a>', 'A', 'the <em>best</em> CMS', '<a href="/wp/">the <em>best</em> CMS</a>' ),
			'Implicitly-closed LI'          => array( '<ul><li>one<li>two</ul>', 'LI', 'replaced', '<ul><li>replaced<li>two</ul>' ),
			'Implicitly-closed P'           => array( '<p>one<p>two', 'P', 'styled <em>text</em>', '<p>styled <em>text</em><p>two' ),
			'Unclosed element at end'       => array( '<div><p>dangling', 'P', 'replaced', '<div><p>replaced' ),
			'Comment'                       => array( '<div>x</div>', 'DIV', '<!-- note -->', '<div><!-- note --></div>' ),
			'TD cell content'               => array( '<table><tbody><tr><td>old</td></tr></tbody></table>', 'TD', '<span>new</span>', '<table><tbody><tr><td><span>new</span></td></tr></tbody></table>' ),
			'TABLE rows with implied TBODY' => array( '<table><tbody><tr><td>a</td></tr></tbody></table>', 'TABLE', '<tr><td>b</td></tr>', '<table><tr><td>b</td></tr></table>' ),
			'SELECT options'                => array( '<select><option>a</option></select>', 'SELECT', '<option>x<option>y', '<select><option>x<option>y</select>' ),
			'SVG foreign content'           => array( '<svg><circle r="1"></circle></svg>', 'SVG', '<rect width="4"/>', '<svg><rect width="4"/></svg>' ),
			'Closed formatting element'     => array( '<div>x</div><span>after</span>', 'DIV', '<b>bold</b> text', '<div><b>bold</b> text</div><span>after</span>' ),
			'Closed SELECT within content'  => array( '<div>x</div>', 'DIV', '<select><option>a</select><p>after', '<div><select><option>a</select><p>after</div>' ),
		);
	}

	/**
	 * Ensures that content is rejected and the document unmodified when accepting
	 * the content would modify the structure outside of the context element.
	 *
	 * The HTML Processor has HTML text as input and output. Unlike the DOM,
	 * some trees cannot be represented in HTML text, e.g. an A element nested
	 * directly inside another A element. Content whose HTML would escape the
	 * context element when the document is parsed again must be rejected.
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
			'Closer for context A'       => array( '<a href="/">x</a>y', 'A', '</a>I closed the container' ),
			'Closer for context DIV'     => array( '<div>x</div>y', 'DIV', '</div><p>escaped' ),
			'LI inside LI'               => array( '<ul><li>one</li><li>two</li></ul>', 'LI', '<li>lists collapse' ),
			'P inside P'                 => array( '<p>one</p>', 'P', '<p>paragraphs close paragraphs</p>' ),
			'Heading inside heading'     => array( '<h1>title</h1>', 'H1', '<h2>headings close headings</h2>' ),
			'BUTTON inside BUTTON'       => array( '<button>x</button>', 'BUTTON', '<button>buttons close buttons' ),
			'FORM inside FORM'           => array( '<form>x</form>', 'FORM', '<form>y</form>' ),
			'SELECT inside SELECT'       => array( '<select><option>a</option></select>', 'SELECT', '<select><option>b' ),
			'OPTION inside OPTION'       => array( '<select><option>a</option></select>', 'OPTION', '<option>options close options' ),
			'Unclosed B before content'  => array( '<div>x</div><span>after</span>', 'DIV', '<b>would embolden what follows' ),
			'Text directly inside TABLE' => array( '<table><tbody><tr><td>a</td></tr></tbody></table>', 'TABLE', 'text would foster-parent' ),
			'Closer for foreign context' => array( '<svg><circle r="1"></circle></svg>text', 'SVG', '</svg><b>escaped' ),
			'BODY start tag'             => array( '<div>x</div>', 'DIV', '<body class="x">y' ),
			'HTML start tag'             => array( '<div>x</div>', 'DIV', '<html lang="en">y' ),
			'A inside SELECT'            => array( '<select><option>a</option></select>', 'SELECT', '<a>updated SELECT parsing may move this' ),
			'DIV inside SELECT'          => array( '<select><option>a</option></select>', 'SELECT', '<div>updated SELECT parsing may move this' ),
			'A inside OPTION in SELECT'  => array( '<select><option>a</option></select>', 'OPTION', '<a>updated SELECT parsing may move this' ),
			'SELECT closer in OPTION'    => array( '<select><option>a</option></select>', 'OPTION', '</select>escaped' ),
			'A in SELECT within content' => array( '<div>x</div>after', 'DIV', '<select><a>link' ),
		);
	}

	/**
	 * Ensures that the safety of content depends on its position in the document.
	 *
	 * An unclosed B element is contained inside a DIV at the end of a document,
	 * but in the middle of a document it would wrap the following content in
	 * bold text once the document is parsed again.
	 *
	 * @covers ::set_inner_html
	 */
	public function test_containment_depends_on_document_position() {
		$processor = WP_HTML_Processor::create_fragment( '<div>x</div>' );
		$processor->next_tag( 'DIV' );
		$this->assertTrue(
			$processor->set_inner_html( '<b>unclosed' ),
			'Should have accepted an unclosed formatting element when no content follows the context element.'
		);
		$this->assertSame(
			'<div><b>unclosed</div>',
			$processor->get_updated_html(),
			'Should have replaced the inner content of the context element.'
		);

		$processor = WP_HTML_Processor::create_fragment( '<div>x</div>text after' );
		$processor->next_tag( 'DIV' );
		$this->assertFalse(
			$processor->set_inner_html( '<b>unclosed' ),
			'Should have rejected an unclosed formatting element which would reopen and wrap the content following the context element.'
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
}
