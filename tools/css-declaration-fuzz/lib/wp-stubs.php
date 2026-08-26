<?php
/** Minimal WordPress stubs used by the standalone CSS declaration fuzzer. */

$GLOBALS['css_declaration_fuzz_doing_it_wrong'] = array();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		$GLOBALS['css_declaration_fuzz_doing_it_wrong'][] = array(
			'function' => (string) $function_name,
			'message'  => (string) $message,
		);
	}
}

if ( ! function_exists( 'wp_trigger_error' ) ) {
	function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
		$GLOBALS['css_declaration_fuzz_doing_it_wrong'][] = array(
			'function' => (string) $function_name,
			'message'  => (string) $message,
		);
	}
}
