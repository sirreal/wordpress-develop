<?php
/**
 * Minimal WordPress function stubs so the HTML API can run standalone.
 *
 * `_doing_it_wrong` calls are recorded so the fuzzer can assert exactly
 * when the selector API reports unsupported/invalid input.
 */

$GLOBALS['css_selector_fuzz_doing_it_wrong'] = array();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		$GLOBALS['css_selector_fuzz_doing_it_wrong'][] = array(
			'function' => (string) $function_name,
			'message'  => (string) $message,
		);
	}
}

if ( ! function_exists( '_deprecated_argument' ) ) {
	function _deprecated_argument( $function_name, $version, $message = '' ) {
	}
}

if ( ! function_exists( 'wp_trigger_error' ) ) {
	function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
		$GLOBALS['css_selector_fuzz_doing_it_wrong'][] = array(
			'function' => (string) $function_name,
			'message'  => (string) $message,
		);
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
