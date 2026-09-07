<?php
/**
 * Configures the dependency-only Code Reference site.
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$active_plugins = array(
	'code-syntax-block/index.php',
	'phpdoc-parser/plugin.php',
	'posts-to-posts/posts-to-posts.php',
);

// Writing active_plugins directly would skip a bundle that failed to unpack.
foreach ( $active_plugins as $plugin ) {
	$result = validate_plugin( $plugin );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
}

update_option( 'active_plugins', $active_plugins );
switch_theme( 'wporg-developer-2023' );
update_option( 'permalink_structure', '/%year%/%monthnum%/%postname%/' );

$create_page = static function ( $slug, $title ) {
	$result = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_name'   => $slug,
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	return $result;
};

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $create_page( 'home', 'Home' ) );
$create_page( 'reference', 'Reference' );

// The theme templates reference this navigation menu by its wordpress.org ID,
// so the menu has to be created with that exact post ID.
$result = wp_insert_post(
	array(
		'import_id'    => 148843,
		'post_title'   => 'Reference API Menu',
		'post_name'    => 'reference-api-menu',
		'post_type'    => 'wp_navigation',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:navigation-link {"label":"Code Reference","type":"custom","url":"/reference/","kind":"custom","isTopLevelLink":true} /-->',
	),
	true
);
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}

flush_rewrite_rules( false );
