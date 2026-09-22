<?php

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

require_once __DIR__ . '/../../../../src/wp-includes/compat-utf8.php';
require_once __DIR__ . '/../../../../src/wp-includes/css-api/class-wp-css-builder.php';
require_once __DIR__ . '/../../../../src/wp-includes/css-api/class-wp-css-token-processor.php';
require_once __DIR__ . '/../../../../src/wp-includes/css-api/class-wp-css-processor.php';


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
