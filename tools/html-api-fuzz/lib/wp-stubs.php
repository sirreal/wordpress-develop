<?php

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
	}
}

if ( ! function_exists( '_deprecated_argument' ) ) {
	function _deprecated_argument( $function_name, $version, $message = '' ) {
	}
}

if ( ! function_exists( 'wp_trigger_error' ) ) {
	function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
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

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url, $protocols = null, $_context = 'display' ) {
		return (string) $url;
	}
}
