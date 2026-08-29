<?php

require_once __DIR__ . '/bootstrap-css-api.php';

if ( ! defined( 'DIR_TESTDATA' ) ) {
	define( 'DIR_TESTDATA', __DIR__ . '/../../data' );
}

require_once __DIR__ . '/../../../../src/wp-includes/class-wp-block-parser.php';
require_once __DIR__ . '/../../includes/build-visual-html-tree.php';

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	class WP_UnitTestCase extends PHPUnit\Framework\TestCase {
		public function setExpectedIncorrectUsage( $doing_it_wrong ) {
		}

		public function setUp(): void {
			parent::setUp();
			$this->set_up();
		}

		public function set_up() {
		}

		/**
		 * Check HTML markup (including blocks) for semantic equivalence.
		 *
		 * Given two markup strings, assert that they translate to the same semantic HTML tree,
		 * normalizing tag names, attribute names, and attribute order. Furthermore, attributes
		 * and class names are sorted and deduplicated, and whitespace in style attributes
		 * is normalized. Finally, block delimiter comments are recognized and normalized,
		 * applying the same principles.
		 *
		 * @since 6.9.0
		 *
		 * @param string      $expected         The expected HTML.
		 * @param string      $actual           The actual HTML.
		 * @param string|null $fragment_context Optional. The fragment context, for example "<td>" expected HTML
		 *                                      must occur within "<table><tr>" fragment context. Default "<body>".
		 *                                      Only "<body>" or `null` are supported at this time.
		 *                                      Set to `null` to parse a full HTML document.
		 * @param string|null $message          Optional. The assertion error message.
		 */
		public function assertEqualHTML( string $expected, string $actual, ?string $fragment_context = '<body>', $message = 'HTML markup was not equivalent.' ): void {
			try {
				$tree_expected = build_visual_html_tree( $expected, $fragment_context );
				$tree_actual   = build_visual_html_tree( $actual, $fragment_context );
			} catch ( Exception $e ) {
				// For PHP 8.4+, we can retry, using the built-in DOM\HTMLDocument parser.
				if ( class_exists( 'DOM\HtmlDocument' ) ) {
					$dom_expected  = DOM\HtmlDocument::createFromString( $expected, LIBXML_NOERROR );
					$tree_expected = build_visual_html_tree( $dom_expected->saveHtml(), $fragment_context );
					$dom_actual    = DOM\HtmlDocument::createFromString( $actual, LIBXML_NOERROR );
					$tree_actual   = build_visual_html_tree( $dom_actual->saveHtml(), $fragment_context );
				} else {
					throw $e;
				}
			}

			$this->assertSame( $tree_expected, $tree_actual, $message );
		}
	}
}
