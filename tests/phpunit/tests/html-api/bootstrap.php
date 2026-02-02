<?php

//require_once '/Users/jonsurrell/jon/plugin-sirreal-dev/_require_.php';

if ( ! defined( 'DIR_TESTDATA' ) ) {
	define( 'DIR_TESTDATA', __DIR__ . '/../../data' );
}

require_once __DIR__ . '/../../../../src/wp-includes/class-wp-block-parser.php';
require_once __DIR__ . '/../../includes/build-visual-html-tree.php';

require_once __DIR__ . '/../../../../src/wp-includes/compat.php';
require_once __DIR__ . '/../../../../src/wp-includes/utf8.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-doctype-info.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-attribute-token.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-span.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-text-replacement.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-tag-processor.php';

// HTML Processor
require_once __DIR__ . '/../../../../src/wp-includes/class-wp-token-map.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/html5-named-character-references.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-decoder.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-stack-event.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-unsupported-exception.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-active-formatting-elements.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-open-elements.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-token.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-processor-state.php';
require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-processor.php';


// HTML Templating #60229
if ( file_exists( __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-template.php' ) ) {
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-html-template.php';
}


// CSS Processor
if ( file_exists( __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-complex-selector-list.php' ) ) {
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-selector-parser-matcher.php';
	// require_once __DIR__ . '/../../../../src/wp-includes/html-api/interface-wp-css-html-tag-processor-matcher.php';
	// require_once __DIR__ . '/../../../../src/wp-includes/html-api/interface-wp-css-html-processor-matcher.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-attribute-selector.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-class-selector.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-id-selector.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-type-selector.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-compound-selector.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-complex-selector.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-compound-selector-list.php';
	require_once __DIR__ . '/../../../../src/wp-includes/html-api/class-wp-css-complex-selector-list.php';
}

/**/

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

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	function wp_kses_uri_attributes() {
		return array(
			'action',
			'archive',
			'background',
			'cite',
			'classid',
			'codebase',
			'data',
			'formaction',
			'href',
			'icon',
			'longdesc',
			'manifest',
			'poster',
			'profile',
			'src',
			'usemap',
			'xmlns',
		);
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $s ) {
		return $s;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( ...$args ) {}
}
