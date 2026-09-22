<?php
/**
 * Unit tests covering WP_HTML_Processor modifiable text functionality.
 *
 * @package WordPress
 * @subpackage HTML-API
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Processor
 */
class Tests_HtmlApi_WpHtmlProcessorModifiableText extends WP_UnitTestCase {
	/**
	 * Ensures that bookmarked text remains seekable after updating the same text node.
	 */
	public function test_seeks_to_bookmarked_text_after_modifiable_text_update(): void {
		$processor = WP_HTML_Processor::create_fragment( "<pre>\nabc</pre><span>" );

		$this->assertTrue( $processor->next_token(), 'Should have found the PRE opener.' );
		$this->assertSame( 'PRE', $processor->get_token_name(), 'Should have found the PRE opener: check test setup.' );

		while ( $processor->next_token() && 'abc' !== $processor->get_modifiable_text() ) {
			continue;
		}

		$this->assertSame( '#text', $processor->get_token_name(), 'Should have found the PRE text node: check test setup.' );
		$this->assertSame( 'abc', $processor->get_modifiable_text(), 'Should have stripped the leading newline from the PRE text on first traversal.' );
		$this->assertTrue( $processor->set_bookmark( 'text' ), 'Should have bookmarked the PRE text node.' );

		$this->assertTrue( $processor->set_modifiable_text( 'xyz' ), 'Should have updated the PRE text node.' );
		$this->assertTrue( $processor->next_token(), 'Should have advanced away from the bookmarked text node.' );
		$this->assertSame( 'PRE', $processor->get_token_name(), 'Should have advanced to the PRE closer: check test setup.' );
		$this->assertSame( "<pre>\nxyz</pre><span>", $processor->get_updated_html(), 'Should have updated the PRE text node.' );

		$this->assertTrue( $processor->seek( 'text' ), 'Should have sought back to the updated PRE text node.' );
		$this->assertSame( '#text', $processor->get_token_name(), 'Should have sought back to the text node.' );
		$this->assertSame(
			'xyz',
			$processor->get_modifiable_text(),
			'Should have replayed the updated PRE text node after seeking.'
		);
	}

	/**
	 * TEXTAREA elements ignore the first newline in their content.
	 * Setting the modifiable text with a leading newline (or carriage return variants)
	 * should ensure that the leading newline is present in the resulting TEXTAREA.
	 *
	 * TEXTAREA are treated as atomic tags by the tag processor, so `set_modifiable_text()`
	 * is called directly on the TEXTAREA token.
	 *
	 * @ticket 64609
	 *
	 * @dataProvider data_modifiable_text_special_textarea
	 *
	 * @param string $set_text         Text to set.
	 * @param string $expected_html    Expected HTML output.
	 */
	public function test_modifiable_text_special_textarea( string $set_text, string $expected_html ): void {
		$processor = WP_HTML_Processor::create_fragment( '<textarea></textarea>' );
		$processor->next_token();
		$processor->set_modifiable_text( $set_text );
		$this->assertSame(
			strtr(
				$set_text,
				array(
					"\r\n" => "\n",
					"\r"   => "\n",
				)
			),
			$processor->get_modifiable_text(),
			'Should have preserved or normalized the leading newline in the TEXTAREA content.'
		);
		$this->assertEqualHTML(
			$expected_html,
			$processor->get_updated_html(),
			'<body>',
			'Should have correctly output the TEXTAREA HTML.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function data_modifiable_text_special_textarea(): array {
		return array(
			'Leading newline'                   => array(
				"\nAFTER NEWLINE",
				"<textarea>\n\nAFTER NEWLINE</textarea>",
			),
			'Leading carriage return'           => array(
				"\rCR",
				"<textarea>\n\nCR</textarea>",
			),
			'Leading carriage return + newline' => array(
				"\r\nCR-N",
				"<textarea>\n\nCR-N</textarea>",
			),
		);
	}

	/**
	 * Ensures that `set_modifiable_text()` returns false for elements that are not special "atomic" elements.
	 *
	 * This includes atomic-like foreign elements (`<svg><textarea>`) as well as arbitrary HTML
	 * elements (`<div>`).
	 *
	 * @ticket 64751
	 * @dataProvider data_set_modifiable_fails_non_atomic_tags
	 *
	 * @expectedIncorrectUsage WP_HTML_Tag_Processor::set_modifiable_text
	 */
	public function test_set_modifiable_fails_non_atomic_tags(
		string $html,
		string $target_tag
	): void {
		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertNotNull( $processor, 'Failed to create a processor.' );
		$this->assertTrue( $processor->next_tag( $target_tag ), 'Failed to find target tag.' );
		$this->assertFalse(
			$processor->set_modifiable_text( 'test' ),
			"set_modifiable_text() should return false on {$processor->get_namespace()}:{$processor->get_qualified_tag_name()}."
		);
		$this->assertSame(
			$html,
			$processor->get_updated_html(),
			'HTML should be unchanged after rejected set_modifiable_text().'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function data_set_modifiable_fails_non_atomic_tags(): array {
		return array(
			// Plain HTML tags.
			'html DIV'                   => array( '<div>', 'DIV' ),

			// Foreign elements with non-atomic tags.
			'svg PATH'                   => array( '<svg><path></path></svg>', 'PATH' ),
			'svg PATH (self-closing)'    => array( '<svg><path /></svg>', 'PATH' ),
			'math MTEXT'                 => array( '<math><mtext></mtext></math>', 'MTEXT' ),
			'math MSPACE (self-closing)' => array( '<math><mspace /></math>', 'MSPACE' ),

			// Foreign elements with atomic-like tags.
			'svg TEXTAREA'               => array( '<svg><textarea></textarea></svg>', 'TEXTAREA' ),
			'svg TITLE'                  => array( '<svg><title></title></svg>', 'TITLE' ),
			'svg STYLE'                  => array( '<svg><style></style></svg>', 'STYLE' ),
			'svg SCRIPT'                 => array( '<svg><script></script></svg>', 'SCRIPT' ),
			'math TEXTAREA'              => array( '<math><textarea></textarea></math>', 'TEXTAREA' ),
			'math TITLE'                 => array( '<math><title></title></math>', 'TITLE' ),
			'math STYLE'                 => array( '<math><style></style></math>', 'STYLE' ),
			'math SCRIPT'                => array( '<math><script></script></math>', 'SCRIPT' ),
		);
	}
}
