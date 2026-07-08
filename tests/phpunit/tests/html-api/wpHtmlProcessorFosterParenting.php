<?php
/**
 * Unit tests covering WP_HTML_Processor foster parenting support.
 *
 * When content appears inside a table context where it isn't allowed, the
 * parser inserts it at a location in the document before the table. The
 * HTML Processor visits such nodes where they were found in the input HTML
 * while reporting the document ancestry a browser would report.
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @since 7.1.0
 *
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Processor
 */
class Tests_HtmlApi_WpHtmlProcessorFosterParenting extends WP_UnitTestCase {
	/**
	 * Ensures that text found directly inside a table context is reported
	 * with the document ancestry of its fostered location.
	 *
	 * @ticket TBD
	 *
	 * @covers ::is_foster_parented
	 * @covers ::get_breadcrumbs
	 */
	public function test_fosters_text_found_inside_table() {
		$processor = WP_HTML_Processor::create_fragment( '<table>lost<td>found' );
		$processor->enable_source_order_foster_parenting();

		$this->assertTrue( $processor->next_token(), 'Failed to find the TABLE.' );
		$this->assertSame( 'TABLE', $processor->get_token_name(), 'Should have found the TABLE first.' );
		$this->assertFalse( $processor->is_foster_parented(), 'Should not have reported the TABLE as foster-parented.' );

		$this->assertTrue( $processor->next_token(), 'Failed to find the fostered text.' );
		$this->assertSame( '#text', $processor->get_token_name(), 'Should have found the fostered text.' );
		$this->assertSame( 'lost', $processor->get_modifiable_text(), 'Should have found the mis-nested text content.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the text as foster-parented.' );
		$this->assertSame(
			array( 'HTML', 'BODY', '#text' ),
			$processor->get_breadcrumbs(),
			'Should have reported the fostered document ancestry, bypassing the TABLE.'
		);

		$this->assertTrue( $processor->next_tag( 'TD' ), 'Failed to find the TD.' );
		$this->assertFalse( $processor->is_foster_parented(), 'Should not have reported the TD as foster-parented.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ),
			$processor->get_breadcrumbs(),
			'Should have reported table breadcrumbs for content in the cell.'
		);
	}

	/**
	 * Ensures that an element found directly inside a table context is
	 * reported with the document ancestry of its fostered location, and
	 * that its contents follow it there.
	 *
	 * @ticket TBD
	 *
	 * @covers ::is_foster_parented
	 * @covers ::get_breadcrumbs
	 */
	public function test_fosters_element_and_its_contents() {
		$processor = WP_HTML_Processor::create_fragment( '<table><div>inside<td>' );
		$processor->enable_source_order_foster_parenting();

		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the DIV.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the DIV as foster-parented.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'DIV' ),
			$processor->get_breadcrumbs(),
			'Should have reported the fostered document ancestry for the DIV.'
		);
		$this->assertSame( 3, $processor->get_current_depth(), 'Depth should reflect the fostered ancestry.' );

		$this->assertTrue( $processor->next_token(), 'Failed to find the text inside the DIV.' );
		$this->assertSame( '#text', $processor->get_token_name(), 'Should have found the text inside the DIV.' );
		$this->assertFalse( $processor->is_foster_parented(), 'Content inside a fostered element is not itself fostered.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'DIV', '#text' ),
			$processor->get_breadcrumbs(),
			'Text inside the fostered DIV should be inside of it in the document.'
		);

		$this->assertTrue( $processor->next_tag( 'TD' ), 'Failed to find the TD.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ),
			$processor->get_breadcrumbs(),
			'Should have restored the table context after the fostered DIV closed.'
		);
	}

	/**
	 * Ensures that comments inside a table context are never fostered:
	 * they belong inside the table and are visited in document order.
	 *
	 * @ticket TBD
	 *
	 * @covers ::is_foster_parented
	 * @covers ::get_breadcrumbs
	 */
	public function test_comments_in_table_are_not_fostered() {
		$processor = WP_HTML_Processor::create_fragment( '<table><!-- comment --><tr><!-- another --><td>' );

		$this->assertTrue( $processor->next_token(), 'Failed to find the TABLE.' );

		$this->assertTrue( $processor->next_token(), 'Failed to find the first comment.' );
		$this->assertSame( '#comment', $processor->get_token_name(), 'Should have found the first comment.' );
		$this->assertFalse( $processor->is_foster_parented(), 'Should not have fostered a comment.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'TABLE', '#comment' ),
			$processor->get_breadcrumbs(),
			'The comment should remain inside the TABLE.'
		);

		$this->assertTrue( $processor->next_tag( 'TR' ), 'Failed to find the TR.' );

		$this->assertTrue( $processor->next_token(), 'Failed to find the second comment.' );
		$this->assertSame( '#comment', $processor->get_token_name(), 'Should have found the second comment.' );
		$this->assertFalse( $processor->is_foster_parented(), 'Should not have fostered a comment in a row.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', '#comment' ),
			$processor->get_breadcrumbs(),
			'The comment should remain inside the TR.'
		);
	}

	/**
	 * Ensures that whitespace-only text inside a table context remains in
	 * place, while whitespace directly followed by other content in the same
	 * run of text is fostered together with it, as the pending table
	 * character tokens list demands.
	 *
	 * @ticket TBD
	 *
	 * @dataProvider data_table_whitespace
	 *
	 * @covers ::is_foster_parented
	 *
	 * @param string   $html                Input HTML.
	 * @param string   $text                Content of the first text node in the document.
	 * @param bool     $is_fostered         Whether that text node is foster-parented.
	 * @param string[] $expected_breadcrumbs Breadcrumbs of that text node.
	 */
	public function test_table_whitespace_handling( string $html, string $text, bool $is_fostered, array $expected_breadcrumbs ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$processor->enable_source_order_foster_parenting();

		while ( $processor->next_token() && '#text' !== $processor->get_token_name() ) {
			continue;
		}

		$this->assertSame( '#text', $processor->get_token_name(), 'Failed to find a text node.' );
		$this->assertSame( $text, $processor->get_modifiable_text(), 'Found the wrong text node.' );
		$this->assertSame( $is_fostered, $processor->is_foster_parented(), 'Wrongly reported whether the text is foster-parented.' );
		$this->assertSame( $expected_breadcrumbs, $processor->get_breadcrumbs(), 'Reported the wrong ancestry for the text.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_table_whitespace() {
		return array(
			'Whitespace alone stays in the table'        => array( "<table> \n\t<td>", " \n\t", false, array( 'HTML', 'BODY', 'TABLE', '#text' ) ),
			'Whitespace before a tag stays in the table' => array( '<table> <tr><td>x', ' ', false, array( 'HTML', 'BODY', 'TABLE', '#text' ) ),
			'Whitespace followed by text is fostered'    => array( '<table> abc<td>', ' ', true, array( 'HTML', 'BODY', '#text' ) ),
			'Whitespace entity followed by text fosters' => array( '<table>&#32;abc<td>', ' ', true, array( 'HTML', 'BODY', '#text' ) ),
			'Non-whitespace text is fostered'            => array( '<table>abc<td>', 'abc', true, array( 'HTML', 'BODY', '#text' ) ),
		);
	}

	/**
	 * Ensures that content fostered inside a TEMPLATE element is placed
	 * within the template contents rather than before the table.
	 *
	 * @ticket TBD
	 *
	 * @covers ::is_foster_parented
	 * @covers ::get_breadcrumbs
	 */
	public function test_fosters_into_template_contents() {
		$processor = WP_HTML_Processor::create_fragment( '<table><template><tbody>lost' );
		$processor->enable_source_order_foster_parenting();

		while ( $processor->next_token() && '#text' !== $processor->get_token_name() ) {
			continue;
		}

		$this->assertSame( '#text', $processor->get_token_name(), 'Failed to find the fostered text.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the text as foster-parented.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'TABLE', 'TEMPLATE', '#text' ),
			$processor->get_breadcrumbs(),
			'Text fostered inside a TEMPLATE belongs to its template contents, not to a location before the table.'
		);
	}

	/**
	 * Ensures that content fostered out of an inner table lands before that
	 * inner table, inside the cell of the outer table.
	 *
	 * @ticket TBD
	 *
	 * @covers ::is_foster_parented
	 * @covers ::get_breadcrumbs
	 */
	public function test_fosters_before_the_nearest_table() {
		$processor = WP_HTML_Processor::create_fragment( '<table><td><table>lost' );
		$processor->enable_source_order_foster_parenting();

		while ( $processor->next_token() && '#text' !== $processor->get_token_name() ) {
			continue;
		}

		$this->assertSame( '#text', $processor->get_token_name(), 'Failed to find the fostered text.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the text as foster-parented.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ),
			$processor->get_breadcrumbs(),
			'Text fostered out of the inner table belongs inside the outer table cell.'
		);
	}

	/**
	 * Ensures that a formatting element reconstructed inside a table context
	 * is fostered, and that its contents report the fostered ancestry.
	 *
	 * @ticket TBD
	 *
	 * @covers ::is_foster_parented
	 * @covers ::get_breadcrumbs
	 */
	public function test_fosters_reconstructed_formatting_elements() {
		$processor = WP_HTML_Processor::create_fragment( '<table><b>bold<tr>reopened' );
		$processor->enable_source_order_foster_parenting();

		// The B is fostered before the table.
		$this->assertTrue( $processor->next_tag( 'B' ), 'Failed to find the B.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the B as foster-parented.' );
		$this->assertSame( array( 'HTML', 'BODY', 'B' ), $processor->get_breadcrumbs(), 'The B belongs before the table.' );

		// The TR clears the fostered B off of the stack of open elements…
		$this->assertTrue( $processor->next_tag( 'TR' ), 'Failed to find the TR.' );

		// …so the text after it reconstructs a B clone, fostered before the table.
		$this->assertTrue( $processor->next_token(), 'Failed to find the reconstructed B.' );
		$this->assertSame( 'B', $processor->get_token_name(), 'Should have reconstructed the B.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have fostered the reconstructed B.' );
		$this->assertSame( array( 'HTML', 'BODY', 'B' ), $processor->get_breadcrumbs(), 'The reconstructed B belongs before the table.' );

		$this->assertTrue( $processor->next_token(), 'Failed to find the text in the reconstructed B.' );
		$this->assertSame( '#text', $processor->get_token_name(), 'Should have found the text inside the reconstructed B.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'B', '#text' ),
			$processor->get_breadcrumbs(),
			'The text belongs inside the reconstructed B, before the table.'
		);
	}

	/**
	 * Ensures that when the adoption agency algorithm places its "last node"
	 * via foster parenting, content which follows inside of it reports the
	 * ancestor chain a browser would report.
	 *
	 * @ticket TBD
	 *
	 * @covers ::get_breadcrumbs
	 */
	public function test_adoption_agency_fosters_last_node() {
		$processor = WP_HTML_Processor::create_fragment( '<table><a>1<p>2</a>3' );
		$processor->enable_source_order_foster_parenting();

		while ( $processor->next_token() && '3' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertSame( '#text', $processor->get_token_name(), 'Failed to find the text after the adoption.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'P', '#text' ),
			$processor->get_breadcrumbs(),
			'After the adoption agency algorithm, the P is fostered before the table and holds the following text.'
		);
	}

	/**
	 * Ensures that breadcrumb queries match fostered content at its document
	 * location, not at the place its syntax appears in the input HTML.
	 *
	 * @ticket TBD
	 *
	 * @covers ::next_tag
	 */
	public function test_next_tag_matches_fostered_breadcrumbs() {
		$processor = WP_HTML_Processor::create_fragment( '<table><img loc="fostered"><td><img loc="cell">' );
		$processor->enable_source_order_foster_parenting();

		$this->assertTrue(
			$processor->next_tag( array( 'breadcrumbs' => array( 'BODY', 'IMG' ) ) ),
			'Failed to find the fostered IMG as a child of BODY.'
		);
		$this->assertSame( 'fostered', $processor->get_attribute( 'loc' ), 'Matched the wrong IMG as a child of BODY.' );

		$processor = WP_HTML_Processor::create_fragment( '<table><img loc="fostered"><td><img loc="cell">' );
		$processor->enable_source_order_foster_parenting();

		$this->assertTrue(
			$processor->next_tag( array( 'breadcrumbs' => array( 'TD', 'IMG' ) ) ),
			'Failed to find the in-table IMG inside the TD.'
		);
		$this->assertSame( 'cell', $processor->get_attribute( 'loc' ), 'Matched the wrong IMG inside the TD.' );
	}

	/**
	 * Ensures that fostered elements remain modifiable: they are real tokens
	 * in the input HTML even though their document location is elsewhere.
	 *
	 * @ticket TBD
	 *
	 * @covers ::set_attribute
	 */
	public function test_fostered_elements_can_be_modified() {
		$processor = WP_HTML_Processor::create_fragment( '<table><div>lost</div><td>found' );
		$processor->enable_source_order_foster_parenting();

		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the DIV.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the DIV as foster-parented.' );
		$this->assertTrue( $processor->set_attribute( 'class', 'fostered' ), 'Failed to set an attribute on the fostered DIV.' );

		$this->assertSame(
			'<table><div class="fostered">lost</div><td>found',
			$processor->get_updated_html(),
			'Should have updated the DIV tag at its location in the input HTML.'
		);
	}

	/**
	 * Ensures that seeking backwards across a fostered node replays the
	 * fostered ancestry correctly.
	 *
	 * @ticket TBD
	 *
	 * @covers ::seek
	 */
	public function test_seek_across_fostered_content() {
		$processor = WP_HTML_Processor::create_fragment( '<table><div>lost</div><td>found' );
		$processor->enable_source_order_foster_parenting();

		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the DIV.' );
		$this->assertTrue( $processor->set_bookmark( 'div' ), 'Failed to set a bookmark on the DIV.' );

		$this->assertTrue( $processor->next_tag( 'TD' ), 'Failed to find the TD.' );
		$this->assertTrue( $processor->seek( 'div' ), 'Failed to seek back to the DIV.' );

		$this->assertSame( 'DIV', $processor->get_tag(), 'Should have returned to the DIV.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the DIV as foster-parented after seeking.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'DIV' ),
			$processor->get_breadcrumbs(),
			'Should have reported the fostered ancestry after seeking.'
		);
	}

	/**
	 * Ensures that foreign elements whose tag names match HTML table-part
	 * elements do not confuse the algorithms which clear the stack of open
	 * elements back to a table context.
	 *
	 * Foster-parented foreign content places elements like an SVG TEMPLATE
	 * directly in table contexts, where a namespace-blind name comparison
	 * would wrongly treat them as their HTML counterparts.
	 *
	 * @ticket TBD
	 *
	 * @covers WP_HTML_Open_Elements::clear_to_table_row_context
	 */
	public function test_foreign_table_part_names_do_not_terminate_table_context_clearing() {
		$processor = WP_HTML_Processor::create_fragment( '<table><tr><svg><template></tr><caption>x' );
		$processor->enable_source_order_foster_parenting();

		$this->assertTrue( $processor->next_tag( 'CAPTION' ), 'Failed to find the CAPTION.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'TABLE', 'CAPTION' ),
			$processor->get_breadcrumbs(),
			'The TR end tag must clear the foreign elements, including the SVG TEMPLATE, off of the stack of open elements.'
		);
	}

	/**
	 * Ensures that documents containing foster-parented content serialize to
	 * HTML which parses into the same document.
	 *
	 * The serialized output visits tokens where they appear in the input
	 * HTML, so fostered content is printed inside its table context; parsing
	 * the output fosters it again to the same document location.
	 *
	 * @ticket TBD
	 *
	 * @dataProvider data_fostered_documents
	 *
	 * @covers ::serialize
	 *
	 * @param string $html Input HTML containing content which requires foster parenting.
	 */
	public function test_serialize_round_trips( string $html ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$processor->enable_source_order_foster_parenting();
		$once = $processor->serialize();
		$this->assertNotNull( $once, 'Failed to serialize the document.' );

		$reprocessor = WP_HTML_Processor::create_fragment( $once );
		$reprocessor->enable_source_order_foster_parenting();
		$twice = $reprocessor->serialize();
		$this->assertNotNull( $twice, 'Failed to serialize the serialized document.' );

		$this->assertSame( $once, $twice, 'Serializing should be idempotent.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_fostered_documents() {
		return array(
			'Fostered text'               => array( '<table>lost<td>found' ),
			'Fostered element'            => array( '<table><div>lost</div><tr><td>found' ),
			'Fostered formatting'         => array( '<table><b>lost<tr>reopened<td>found' ),
			'Fostered before inner table' => array( '<table><td><table>lost<td>found' ),
			'Fostered whitespace run'     => array( '<table> lost <td> found ' ),
			'Adoption in table'           => array( '<table><a>1<p>2</a>3' ),
			'Fostered foreign content'    => array( '<table><svg><circle r="1"></svg><td>found' ),
		);
	}

	/**
	 * Ensures that by default the processor presents every node in document
	 * order: foster-parented content is visited before the TABLE element it
	 * precedes in the document.
	 *
	 * @ticket TBD
	 *
	 * @covers ::next_token
	 * @covers ::is_foster_parented
	 */
	public function test_default_mode_presents_fostered_content_in_document_order() {
		$processor = WP_HTML_Processor::create_fragment( 'a<table>b<td>c</table>d' );

		$expected_stream = array(
			array( '#text', 'a', false, array( 'HTML', 'BODY', '#text' ) ),
			array( '#text', 'b', true, array( 'HTML', 'BODY', '#text' ) ),
			array( 'TABLE', null, false, array( 'HTML', 'BODY', 'TABLE' ) ),
			array( 'TBODY', null, false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
			array( 'TR', null, false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
			array( 'TD', null, false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
			array( '#text', 'c', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
		);

		foreach ( $expected_stream as $at => list( $token_name, $text, $is_fostered, $breadcrumbs ) ) {
			$this->assertTrue( $processor->next_token(), "Failed to find token {$at} ({$token_name})." );
			$this->assertSame( $token_name, $processor->get_token_name(), "Found the wrong token at position {$at}." );
			if ( isset( $text ) ) {
				$this->assertSame( $text, $processor->get_modifiable_text(), "Found the wrong text at position {$at}." );
			}
			$this->assertSame( $is_fostered, $processor->is_foster_parented(), "Wrongly reported foster parenting at position {$at}." );
			$this->assertSame( $breadcrumbs, $processor->get_breadcrumbs(), "Reported the wrong ancestry at position {$at}." );
		}

		// The rest of the document follows in order: the table's closers, then the trailing text.
		$suffix = array();
		while ( $processor->next_token() ) {
			$suffix[] = '#text' === $processor->get_token_name()
				? $processor->get_modifiable_text()
				: ( $processor->is_tag_closer() ? '/' : '' ) . $processor->get_token_name();
		}
		$this->assertNull( $processor->get_last_error(), 'Should have processed the entire document.' );
		$this->assertSame( array( '/TD', '/TR', '/TBODY', '/TABLE', 'd' ), $suffix, 'The document should end in order.' );
	}

	/**
	 * Ensures that content fostered before a nested table and into template
	 * contents is presented at its document position.
	 *
	 * @ticket TBD
	 *
	 * @dataProvider data_document_order_streams
	 *
	 * @covers ::next_token
	 *
	 * @param string   $html            Input HTML.
	 * @param string[] $expected_stream Expected visited tokens, closers prefixed with "/", text nodes by their content.
	 */
	public function test_default_mode_document_positions( string $html, array $expected_stream ) {
		$processor = WP_HTML_Processor::create_fragment( $html );

		$actual_stream = array();
		while ( $processor->next_token() ) {
			$actual_stream[] = '#text' === $processor->get_token_name()
				? '"' . $processor->get_modifiable_text() . '"'
				: ( $processor->is_tag_closer() ? '/' : '' ) . $processor->get_token_name();
		}

		$this->assertNull( $processor->get_last_error(), 'Should have processed the entire document.' );
		$this->assertSame( $expected_stream, $actual_stream, 'Presented the document out of document order.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_document_order_streams() {
		return array(
			'Fostered before a nested table'     => array(
				'<table><td><table>lost<td>found',
				array( 'TABLE', 'TBODY', 'TR', 'TD', '"lost"', 'TABLE', 'TBODY', 'TR', 'TD', '"found"', '/TD', '/TR', '/TBODY', '/TABLE', '/TD', '/TR', '/TBODY', '/TABLE' ),
			),
			'Fostered into template contents'    => array(
				'<table><template><tbody>lost<tr>',
				array( 'TABLE', 'TEMPLATE', 'TBODY', 'TR', '/TR', '/TBODY', '"lost"', '/TEMPLATE', '/TABLE' ),
			),
			'Fostered formatting reconstruction' => array(
				'<table><b>x<tr>y</table>',
				array( 'B', '"x"', '/B', 'B', '"y"', '/B', 'TABLE', 'TBODY', 'TR', '/TR', '/TBODY', '/TABLE' ),
			),
		);
	}

	/**
	 * Ensures that a table larger than the deferral bound parses completely
	 * when it is well-formed, that mis-nested content discovered within the
	 * bound is still presented in document order, and that mis-nested
	 * content discovered beyond the bound aborts the parse.
	 *
	 * @ticket TBD
	 *
	 * @covers ::next_token
	 */
	public function test_deferral_bound() {
		$rows = str_repeat( '<tr><td>x</td></tr>', intdiv( WP_HTML_Processor::MAX_BUFFERED_TABLE_EVENTS, 3 ) );

		$processor = WP_HTML_Processor::create_fragment( "<table>{$rows}</table>after" );
		$last_text = null;
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_name() ) {
				$last_text = $processor->get_modifiable_text();
			}
		}
		$this->assertNull( $processor->get_last_error(), 'A well-formed table of any size must parse.' );
		$this->assertSame( 'after', $last_text, 'Should have processed the entire document.' );

		$processor = WP_HTML_Processor::create_fragment( "<table><div>rescued</div>{$rows}</table>" );
		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the fostered DIV.' );
		$this->assertTrue( $processor->is_foster_parented(), 'Should have reported the DIV as foster-parented.' );
		$this->assertSame(
			array( 'HTML', 'BODY', 'DIV' ),
			$processor->get_breadcrumbs(),
			'Mis-nested content within the bound is presented at its document position.'
		);
		while ( $processor->next_token() ) {
			continue;
		}
		$this->assertNull( $processor->get_last_error(), 'Should have processed the oversized table after its fostered content.' );

		$processor = WP_HTML_Processor::create_fragment( "<table>{$rows}<div>too late</div></table>" );
		while ( $processor->next_token() ) {
			continue;
		}
		$this->assertSame(
			WP_HTML_Processor::ERROR_UNSUPPORTED,
			$processor->get_last_error(),
			'Mis-nested content beyond the bound cannot be presented in document order and must abort.'
		);
	}

	/**
	 * Ensures that a fostered run which exceeds the deferral bound while the
	 * document's unclosed elements unwind aborts through the error interface
	 * instead of letting the internal exception escape.
	 *
	 * @ticket TBD
	 *
	 * @covers ::next_token
	 */
	public function test_oversized_fostered_run_aborts_cleanly() {
		$processor = WP_HTML_Processor::create_fragment(
			'<table>' . str_repeat( '<div>', WP_HTML_Processor::MAX_BUFFERED_TABLE_EVENTS - 2 )
		);

		while ( $processor->next_token() ) {
			continue;
		}

		$this->assertSame(
			WP_HTML_Processor::ERROR_UNSUPPORTED,
			$processor->get_last_error(),
			'An oversized fostered run must abort the parse through get_last_error().'
		);
	}

	/**
	 * Ensures that in-place modifications apply to fostered nodes visited
	 * before their table and to deferred table contents alike, and that
	 * bookmarks may be sought across the reordering in both directions.
	 *
	 * @ticket TBD
	 *
	 * @covers ::seek
	 * @covers ::set_bookmark
	 */
	public function test_default_mode_mutations_and_seeking() {
		$processor = WP_HTML_Processor::create_fragment( '<table><div>lost</div><td>found' );

		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the fostered DIV.' );
		$this->assertTrue( $processor->set_attribute( 'class', 'rescued' ), 'Failed to modify the fostered DIV.' );
		$this->assertTrue( $processor->set_bookmark( 'div' ), 'Failed to bookmark the fostered DIV.' );

		$this->assertTrue( $processor->next_tag( 'TD' ), 'Failed to find the TD.' );
		$this->assertTrue( $processor->set_attribute( 'id', 'cell' ), 'Failed to modify the deferred TD.' );

		$this->assertSame(
			'<table><div class="rescued">lost</div><td id="cell">found',
			$processor->get_updated_html(),
			'Modifications must apply to each token at its place in the input HTML.'
		);

		$this->assertTrue( $processor->seek( 'div' ), 'Failed to seek back to the fostered DIV.' );
		$this->assertSame( 'DIV', $processor->get_tag(), 'Should have returned to the DIV.' );
		$this->assertSame( 'rescued', $processor->get_attribute( 'class' ), 'Should have read the earlier modification.' );

		$this->assertTrue( $processor->next_tag( 'TD' ), 'Failed to walk forward to the TD again.' );
		$this->assertSame( 'cell', $processor->get_attribute( 'id' ), 'Should have found the TD modification after seeking.' );
	}

	/**
	 * Ensures that normalization relocates fostered content to its document
	 * position: the output requires no foster parenting when re-parsed.
	 *
	 * @ticket TBD
	 *
	 * @covers ::normalize
	 */
	public function test_normalize_relocates_fostered_content() {
		$this->assertSame(
			'lost<table><tbody><tr><td>found</td></tr></tbody></table>',
			WP_HTML_Processor::normalize( '<table>lost<td>found' ),
			'Normalization must move fostered content before the table.'
		);
	}

	/**
	 * Ensures that foster parenting support cannot be enabled once the
	 * processor has started scanning: a seek backwards must replay the
	 * document exactly as it was first parsed.
	 *
	 * @ticket TBD
	 *
	 * @covers ::enable_source_order_foster_parenting
	 */
	public function test_cannot_enable_source_order_foster_parenting_after_scanning_starts() {
		$processor = WP_HTML_Processor::create_fragment( '<table>lost<td>found' );

		$this->assertTrue( $processor->next_token(), 'Failed to find the TABLE.' );
		$this->assertFalse( $processor->enable_source_order_foster_parenting(), 'Should have refused to enable foster parenting mid-scan.' );
	}
}
