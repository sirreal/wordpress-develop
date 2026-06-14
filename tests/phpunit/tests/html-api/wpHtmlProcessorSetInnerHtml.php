<?php
/**
 * Unit tests covering WP_HTML_Processor inner HTML updates.
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Processor
 */
class Tests_HtmlApi_WpHtmlProcessorSetInnerHtml extends WP_UnitTestCase {
	/**
	 * Ensures that inner HTML can be replaced on a normal HTML element.
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 */
	public function test_set_inner_html_replaces_inner_html(): void {
		$processor = WP_HTML_Processor::create_fragment( '<div>Old <em>old</em></div>' );

		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the DIV opener.' );
		$this->assertTrue( $processor->set_inner_html( '<p>New</p>' ), 'Failed to set inner HTML.' );
		$this->assertSame(
			'<div><p>New</p></div>',
			$processor->get_updated_html(),
			'Should have replaced the inner HTML.'
		);
	}

	/**
	 * Ensures that inner HTML can be replaced in a full document.
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 */
	public function test_set_inner_html_replaces_inner_html_in_full_document(): void {
		$html      = '<!DOCTYPE html><html><body><main>Old</main></body></html>';
		$processor = WP_HTML_Processor::create_full_parser( $html );

		$this->assertTrue( $processor->next_tag( 'BODY' ), 'Failed to find the BODY opener.' );
		$this->assertTrue( $processor->set_inner_html( '<main>New</main>' ), 'Failed to set BODY inner HTML.' );
		$this->assertSame(
			'<!DOCTYPE html><html><body><main>New</main></body></html>',
			$processor->get_updated_html(),
			'Should have replaced the BODY inner HTML.'
		);
	}

	/**
	 * Ensures BODY replacement ignores BODY-looking syntax inside TEMPLATE content.
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 */
	public function test_set_inner_html_replaces_body_with_body_closer_in_template(): void {
		$html      = '<!DOCTYPE html><html><body><template></body></template><p>After</p></body></html>';
		$processor = WP_HTML_Processor::create_full_parser( $html );

		$this->assertTrue( $processor->next_tag( 'BODY' ), 'Failed to find the BODY opener.' );
		$this->assertTrue( $processor->set_inner_html( '<main>New</main>' ), 'Failed to set BODY inner HTML.' );
		$this->assertSame(
			'<!DOCTYPE html><html><body><main>New</main></body></html>',
			$processor->get_updated_html(),
			'Should have replaced the full BODY contents instead of stopping inside TEMPLATE content.'
		);
	}

	/**
	 * Ensures that inner HTML cannot be set when not paused on a token.
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 */
	public function test_set_inner_html_rejects_when_not_paused_on_token(): void {
		$html      = '<div>Old</div>';
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertFalse( $processor->set_inner_html( '<p>New</p>' ), 'Should not set inner HTML before matching a tag.' );
		$this->assertSame( $html, $processor->get_updated_html(), 'HTML should be unchanged.' );
	}

	/**
	 * Ensures that inner HTML cannot be set on tag closers.
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 */
	public function test_set_inner_html_rejects_tag_closers(): void {
		$html      = '<div>Old</div>';
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertTrue(
			$processor->next_tag(
				array(
					'tag_name'    => 'DIV',
					'tag_closers' => 'visit',
				)
			),
			'Failed to find the DIV opener.'
		);
		$this->assertTrue(
			$processor->next_tag(
				array(
					'tag_name'    => 'DIV',
					'tag_closers' => 'visit',
				)
			),
			'Failed to find the DIV closer.'
		);

		$this->assertTrue( $processor->is_tag_closer(), 'Should be paused on the DIV closer.' );
		$this->assertFalse( $processor->set_inner_html( '<p>New</p>' ), 'Should not set inner HTML on a closer.' );
		$this->assertSame( $html, $processor->get_updated_html(), 'HTML should be unchanged.' );
	}

	/**
	 * Ensures that inner HTML cannot be set on atomic elements.
	 *
	 * @dataProvider data_set_inner_html_rejects_atomic_elements
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 *
	 * @param string $html       HTML containing an atomic target.
	 * @param string $target_tag Target tag to find.
	 */
	public function test_set_inner_html_rejects_atomic_elements( string $html, string $target_tag ): void {
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertTrue( $processor->next_tag( $target_tag ), "Failed to find {$target_tag}." );
		$this->assertFalse( $processor->set_inner_html( '<p>New</p>' ), "Should not set inner HTML on {$target_tag}." );
		$this->assertSame( $html, $processor->get_updated_html(), 'HTML should be unchanged.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function data_set_inner_html_rejects_atomic_elements(): array {
		return array(
			'SCRIPT'                            => array( '<script>old</script>', 'SCRIPT' ),
			'STYLE'                             => array( '<style>old</style>', 'STYLE' ),
			'TEXTAREA'                          => array( '<textarea>old</textarea>', 'TEXTAREA' ),
			'IMG'                               => array( '<img alt="old">', 'IMG' ),
			'SVG TITLE integration'             => array( '<svg><title>old</title></svg>', 'TITLE' ),
			'SVG DESC integration'              => array( '<svg><desc>old</desc></svg>', 'DESC' ),
			'SVG FOREIGNOBJECT integration'     => array( '<svg><foreignObject><p>old</p></foreignObject></svg>', 'FOREIGNOBJECT' ),
			'MathML MI integration'             => array( '<math><mi>old</mi></math>', 'MI' ),
			'MathML ANNOTATION-XML integration' => array( '<math><annotation-xml encoding="text/html"><p>old</p></annotation-xml></math>', 'ANNOTATION-XML' ),
		);
	}

	/**
	 * Ensures that replacements are rejected when they alter the tree outside the target.
	 *
	 * @dataProvider data_set_inner_html_rejects_tree_leaks
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 *
	 * @param string $html        Original HTML.
	 * @param string $target_tag  Target tag to update.
	 * @param string $replacement Proposed inner HTML.
	 */
	public function test_set_inner_html_rejects_tree_leaks( string $html, string $target_tag, string $replacement ): void {
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertTrue( $processor->next_tag( $target_tag ), "Failed to find {$target_tag}." );
		$this->assertFalse( $processor->set_inner_html( $replacement ), 'Should have rejected leaking inner HTML.' );
		$this->assertSame( $html, $processor->get_updated_html(), 'HTML should be unchanged after a rejected replacement.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function data_set_inner_html_rejects_tree_leaks(): array {
		return array(
			'original BODY attributes would be removed' => array(
				'<div><body add-class>Old</div><span>After</span>',
				'DIV',
				'<p>New</p>',
			),
			'original HTML attributes would be removed' => array(
				'<div><html lang="en">Old</div><span>After</span>',
				'DIV',
				'<p>New</p>',
			),
			'nested A closes target A'                  => array(
				'<a>Old</a><span>After</span>',
				'A',
				'<a>New</a>',
			),
			'explicit closer escapes target'            => array(
				'<div>Old</div><span>After</span>',
				'DIV',
				'</div><p>Leaked</p>',
			),
			'BODY attributes after escaped target'      => array(
				'<div>Old</div><span>After</span>',
				'DIV',
				'</div><body add-class>',
			),
			'HTML attributes after escaped target'      => array(
				'<div>Old</div><span>After</span>',
				'DIV',
				'</div><html lang="en">',
			),
			'active formatting reconstructs outside'    => array(
				'<div>Old</div><span>After</span>',
				'DIV',
				'<b>New',
			),
			'active formatting reconstructs before textarea outside' => array(
				'<section>Old</section><textarea>After</textarea>',
				'SECTION',
				'<b>New',
			),
			'BODY attributes can be hoisted outside'    => array(
				'<main>Old</main><span>After</span>',
				'MAIN',
				'<body add-class>New',
			),
			'HTML attributes can be hoisted outside'    => array(
				'<main>Old</main><span>After</span>',
				'MAIN',
				'<html lang="en">New',
			),
		);
	}

	/**
	 * Ensures that BODY and HTML attribute hoisting is rejected in full documents.
	 *
	 * @dataProvider data_set_inner_html_rejects_full_document_attribute_hoisting
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 *
	 * @param string $replacement Proposed inner HTML.
	 */
	public function test_set_inner_html_rejects_full_document_attribute_hoisting( string $replacement ): void {
		$html      = '<!DOCTYPE html><html><body><main>Old</main><span>After</span></body></html>';
		$processor = WP_HTML_Processor::create_full_parser( $html );

		$this->assertTrue( $processor->next_tag( 'MAIN' ), 'Failed to find MAIN.' );
		$this->assertFalse( $processor->set_inner_html( $replacement ), 'Should reject attribute hoisting outside the target.' );
		$this->assertSame( $html, $processor->get_updated_html(), 'HTML should be unchanged after a rejected replacement.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function data_set_inner_html_rejects_full_document_attribute_hoisting(): array {
		return array(
			'BODY attributes' => array( '<body add-class>New' ),
			'HTML attributes' => array( '<html lang="en">New' ),
		);
	}

	/**
	 * Ensures BODY and HTML-looking tags are allowed when they do not affect the outer tree.
	 *
	 * @dataProvider data_set_inner_html_allows_unhoisted_body_and_html_tags
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 *
	 * @param string $html          Original HTML.
	 * @param string $target_tag    Target tag to update.
	 * @param string $replacement   Proposed inner HTML.
	 * @param string $expected_html Expected updated HTML.
	 */
	public function test_set_inner_html_allows_unhoisted_body_and_html_tags(
		string $html,
		string $target_tag,
		string $replacement,
		string $expected_html
	): void {
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertTrue( $processor->next_tag( $target_tag ), "Failed to find {$target_tag}." );
		$this->assertTrue( $processor->set_inner_html( $replacement ), 'Should allow BODY/HTML-looking tags that remain inside the target.' );
		$this->assertSame( $expected_html, $processor->get_updated_html(), 'Should preserve the safe replacement.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function data_set_inner_html_allows_unhoisted_body_and_html_tags(): array {
		return array(
			'foreign HTML element'      => array(
				'<svg><title>Old</title></svg><span>After</span>',
				'SVG',
				'<html lang="fr"></html>',
				'<svg><html lang="fr"></html></svg><span>After</span>',
			),
			'TEMPLATE ignores BODY tag' => array(
				'<div>Old</div><span>After</span>',
				'DIV',
				'<template><body add-class>New</template>',
				'<div><template><body add-class>New</template></div><span>After</span>',
			),
		);
	}

	/**
	 * Ensures rejected replacements do not poison the live processor state.
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 */
	public function test_set_inner_html_failure_does_not_poison_processor_state(): void {
		$html      = '<div><a><strong>Click <span><a><big>Here</big></a></strong></a></span></div><p>After</p>';
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the DIV opener.' );
		$this->assertFalse( $processor->set_inner_html( '<p>New</p>' ), 'Should reject when the target end cannot be safely found.' );
		$this->assertNull( $processor->get_last_error(), 'Rejected replacement should not poison the live processor.' );
		$this->assertSame( $html, $processor->get_updated_html(), 'HTML should be unchanged.' );
	}

	/**
	 * Ensures safe parser repairs inside the target are accepted.
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 */
	public function test_set_inner_html_allows_repairs_inside_target(): void {
		$processor = WP_HTML_Processor::create_fragment( '<div>Old</div><span>After</span>' );

		$this->assertTrue( $processor->next_tag( 'DIV' ), 'Failed to find the DIV opener.' );
		$this->assertTrue( $processor->set_inner_html( '<p>One<p>Two' ), 'Should set inner HTML when repairs stay inside the target.' );
		$this->assertSame(
			'<div><p>One<p>Two</div><span>After</span>',
			$processor->get_updated_html(),
			'Should preserve the raw inner HTML replacement.'
		);
	}

	/**
	 * Ensures inner HTML replacement works when the target closer is implicit.
	 *
	 * @dataProvider data_set_inner_html_with_implicit_closer
	 *
	 * @covers WP_HTML_Processor::set_inner_html
	 *
	 * @param string $html          Original HTML.
	 * @param string $target_tag    Target tag to update.
	 * @param string $replacement   Proposed inner HTML.
	 * @param string $expected_html Expected updated HTML.
	 */
	public function test_set_inner_html_with_implicit_closer(
		string $html,
		string $target_tag,
		string $replacement,
		string $expected_html
	): void {
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertTrue( $processor->next_tag( $target_tag ), "Failed to find {$target_tag}." );
		$this->assertTrue( $processor->set_inner_html( $replacement ), 'Should set inner HTML before the implicit closer.' );
		$this->assertSame( $expected_html, $processor->get_updated_html(), 'Should replace only the implicit target inner span.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function data_set_inner_html_with_implicit_closer(): array {
		return array(
			'closed by following P opener' => array(
				'<p>Old<p>After',
				'P',
				'<em>New</em>',
				'<p><em>New</em><p>After',
			),
			'closed by EOF'                => array(
				'<div>Old',
				'DIV',
				'<span>New</span>',
				'<div><span>New</span>',
			),
			'self-closing flag ignored'    => array(
				'<div />Old',
				'DIV',
				'<span>New</span>',
				'<div /><span>New</span>',
			),
		);
	}
}
