<?php
/**
 * Standalone bootstrap for executing HTML API code without WordPress.
 *
 * Loads the html-api classes directly with minimal shims for the few
 * WordPress functions they reference. Candidate and reference
 * implementations always run under this same bootstrap, so shim
 * divergence from real WordPress cancels out in comparisons.
 */

error_reporting( E_ALL );

$GLOBALS['harness_doing_it_wrong'] = array();
$GLOBALS['harness_trigger_error']  = array();

function __( $text, $domain = 'default' ) {
	return $text;
}

function _doing_it_wrong( $function_name, $message, $version ) {
	$GLOBALS['harness_doing_it_wrong'][] = array(
		'function' => $function_name,
		'message'  => $message,
		'version'  => $version,
	);
}

function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {
	$GLOBALS['harness_trigger_error'][] = array(
		'function' => $function_name,
		'message'  => $message,
		'level'    => $error_level,
	);
}

// Copy of the core list, without the filter.
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

/**
 * Minimal shim: HTML-escape URL attributes without WordPress' protocol
 * filtering or other URL normalization.
 */
function esc_url( $url, $protocols = null, $_context = 'display' ) {
	return strtr(
		(string) $url,
		array(
			'<' => '&lt;',
			'>' => '&gt;',
			'&' => '&amp;',
			'"' => '&quot;',
			"'" => '&apos;',
		)
	);
}

$wp_includes = dirname( __DIR__, 2 ) . '/src/wp-includes';

require_once $wp_includes . '/utf8.php'; // Standalone: wp_is_valid_utf8(), wp_has_noncharacters(), etc.

require_once $wp_includes . '/class-wp-token-map.php';
require_once $wp_includes . '/html-api/html5-named-character-references.php';
require_once $wp_includes . '/html-api/class-wp-html-attribute-token.php';
require_once $wp_includes . '/html-api/class-wp-html-span.php';
require_once $wp_includes . '/html-api/class-wp-html-text-replacement.php';
require_once $wp_includes . '/html-api/class-wp-html-decoder.php';
require_once $wp_includes . '/html-api/class-wp-html-doctype-info.php';
require_once $wp_includes . '/html-api/class-wp-html-tag-processor.php';
require_once $wp_includes . '/html-api/class-wp-html-unsupported-exception.php';
require_once $wp_includes . '/html-api/class-wp-html-token.php';
require_once $wp_includes . '/html-api/class-wp-html-stack-event.php';
require_once $wp_includes . '/html-api/class-wp-html-open-elements.php';
require_once $wp_includes . '/html-api/class-wp-html-active-formatting-elements.php';
require_once $wp_includes . '/html-api/class-wp-html-processor-state.php';
require_once $wp_includes . '/html-api/class-wp-html-processor.php';
