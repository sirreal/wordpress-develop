<?php

require_once __DIR__ . '/src/wp-includes/compat.php';
require_once __DIR__ . '/src/wp-includes/utf8.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-doctype-info.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-attribute-token.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-span.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-text-replacement.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-tag-processor.php';

// HTML Processor
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-stack-event.php';
require_once __DIR__ . '/src/wp-includes/class-wp-token-map.php';
require_once __DIR__ . '/src/wp-includes/html-api/html5-named-character-references.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-decoder.php';

require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-unsupported-exception.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-active-formatting-elements.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-open-elements.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-token.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-processor-state.php';
require_once __DIR__ . '/src/wp-includes/html-api/class-wp-html-processor.php';

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) {
		return str_replace( array( '<', '>', '"' ), array( '&lt;', '&gt;', '&quot;' ), $s );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $s ) {
		return $s;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $message ) {
		trigger_error( $message );
	}
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	function wp_kses_uri_attributes() {
		return array();
	}
}
