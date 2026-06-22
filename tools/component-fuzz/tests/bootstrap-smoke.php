<?php
require_once __DIR__ . '/../lib/autoload.php';

\ComponentFuzz\WpBootstrap::load();

$required_functions = array(
	'wp_kses_post',
	'wp_kses_bad_protocol',
	'safecss_filter_attr',
	'esc_url',
	'wp_parse_url',
	'wp_normalize_path',
	'validate_file',
	'sanitize_file_name',
	'wp_check_filetype',
	'parse_blocks',
	'serialize_blocks',
	'shortcode_parse_atts',
	'force_balance_tags',
	'sanitize_title_with_dashes',
	'sanitize_post_field',
	'sanitize_term_field',
	'maybe_serialize',
	'maybe_unserialize',
	'register_meta',
	'register_post_meta',
	'unregister_meta_key',
	'get_registered_meta_keys',
	'get_registered_metadata',
	'sanitize_meta',
	'get_metadata_default',
	'is_protected_meta',
	'metadata_exists',
	'get_metadata_raw',
	'get_metadata',
	'add_metadata',
	'update_metadata',
	'delete_metadata',
	'rest_validate_value_from_schema',
	'rest_sanitize_value_from_schema',
	'sanitize_user',
	'sanitize_email',
	'is_email',
	'wp_generate_password',
	'wp_fast_hash',
	'wp_verify_fast_hash',
	'wp_hash_password',
	'wp_check_password',
	'wp_recovery_mode',
	'wp_interactivity_process_directives',
	'wp_interactivity_state',
	'wp_interactivity_config',
	'wp_interactivity_data_wp_context',
	'get_self_link',
	'wp_register_ability',
	'wp_get_abilities',
	'wp_print_font_faces',
	'wp_register_font_collection',
	'wp_get_font_dir',
	'wp_supports_ai',
	'wp_is_connector_registered',
	'wp_get_connector',
	'wp_get_connectors',
	'register_nav_menu',
	'wp_nav_menu',
	'walk_nav_menu_tree',
	'wp_oembed_ensure_format',
	'wp_embed_defaults',
	'feed_content_type',
	'show_admin_bar',
	'is_admin_bar_showing',
	'wp_admin_bar_render',
	'wp_is_post_revision',
	'wp_get_user_request',
	'wp_user_request_action_description',
	'wp_validate_user_request_key',
	'wp_privacy_generate_personal_data_export_group_html',
	'wp_privacy_process_personal_data_export_page',
	'wp_privacy_process_personal_data_erasure_page',
	'wp_privacy_anonymize_ip',
	'wp_privacy_anonymize_data',
	'wp_add_privacy_policy_content',
	'get_body_class',
	'body_class',
	'get_post_format',
	'get_language_attributes',
	'language_attributes',
	'wp_get_document_title',
	'wp_resource_hints',
	'wp_preload_resources',
	'get_pagenum_link',
	'paginate_links',
	'get_search_link',
	'get_feed_link',
	'get_home_url',
	'get_site_url',
	'get_admin_url',
	'get_preview_post_link',
	'get_edit_post_link',
	'get_delete_post_link',
	'get_permalink',
	'wp_get_shortlink',
	'get_bookmark',
	'get_bookmark_field',
	'wp_list_bookmarks',
	'get_core_updates',
	'get_plugin_updates',
	'get_theme_updates',
	'wp_get_update_data',
	'wp_is_using_https',
	'wp_is_home_url_using_https',
	'wp_is_site_url_using_https',
	'wp_should_replace_insecure_home_url',
	'wp_replace_insecure_home_url',
	'wp_get_https_detection_errors',
);

$required_classes = array(
	'WP_HTML_Processor',
	'WP_HTML_Tag_Processor',
	'WP_Interactivity_API',
	'WP_Interactivity_API_Directives_Processor',
	'WP_Block_Parser',
	'WP_REST_Request',
	'WP_REST_Server',
	'WP_REST_Response',
	'WP_Date_Query',
	'WP_Ability',
	'WP_Abilities_Registry',
	'WP_Ability_Category',
	'WP_Ability_Categories_Registry',
	'WP_Font_Face',
	'WP_Font_Library',
	'WP_Font_Collection',
	'WP_Font_Utils',
	'WP_Connector_Registry',
	'WP_Icons_Registry',
	'WP_Speculation_Rules',
	'WordPress\AiClient\AiClient',
	'Walker_Nav_Menu',
	'WP_Application_Passwords',
	'WP_User_Request',
	'WP_Metadata_Lazyloader',
	'WP_Recovery_Mode_Key_Service',
	'WP_Recovery_Mode_Cookie_Service',
	'WP_Recovery_Mode',
	'WP_Paused_Extensions_Storage',
	'WP_Embed',
	'WP_oEmbed',
	'WP_Admin_Bar',
	'IXR_Base64',
	'IXR_Date',
	'IXR_Error',
	'IXR_Message',
	'IXR_Request',
	'IXR_Server',
	'IXR_Value',
	'wp_xmlrpc_server',
	'WP_Customize_Manager',
	'WP_Customize_Setting',
	'WP_Customize_Control',
	'WP_Customize_Section',
	'WP_Customize_Panel',
	'WP_Customize_Selective_Refresh',
	'WP_Customize_Partial',
	'WP_Privacy_Policy_Content',
	'WP_Site_Health',
	'WP_Query',
	'WP_Rewrite',
	'WP_Post',
);

$missing = array();
foreach ( $required_functions as $function ) {
	if ( ! function_exists( $function ) ) {
		$missing[] = "function {$function}";
	}
}
foreach ( $required_classes as $class ) {
	if ( ! class_exists( $class ) ) {
		$missing[] = "class {$class}";
	}
}

if ( $missing ) {
	fwrite( STDERR, "Missing bootstrap symbols:\n- " . implode( "\n- ", $missing ) . "\n" );
	exit( 1 );
}

try {
	do_action( 'init' );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Bootstrap init smoke invariant failed: ' . get_class( $e ) . ': ' . $e->getMessage() . "\n" );
	exit( 1 );
}

$sample_html = wp_kses_post( '<script>alert(1)</script><p onclick="x">ok</p>' );
if ( false !== stripos( $sample_html, '<script' ) || false !== stripos( $sample_html, 'onclick' ) ) {
	fwrite( STDERR, "KSES smoke invariant failed: {$sample_html}\n" );
	exit( 1 );
}

$request = new WP_REST_Request( 'POST', '/component-fuzz/v1/item/123' );
$request->set_query_params( array( 'id' => 'query' ) );
$request->set_body_params( array( 'id' => 'body' ) );
if ( 'body' !== $request->get_param( 'id' ) ) {
	fwrite( STDERR, "REST request precedence smoke invariant failed.\n" );
	exit( 1 );
}

$blocks = parse_blocks( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );
if ( 1 !== count( $blocks ) || 'core/paragraph' !== $blocks[0]['blockName'] ) {
	fwrite( STDERR, "Block parser smoke invariant failed.\n" );
	exit( 1 );
}

$hash = wp_hash_password( 'component-fuzz' );
if ( ! wp_check_password( 'component-fuzz', $hash ) ) {
	fwrite( STDERR, "Password hash smoke invariant failed.\n" );
	exit( 1 );
}

$app_hash = WP_Application_Passwords::hash_password( 'component-fuzz-app' );
if (
	'abcd ef12' !== WP_Application_Passwords::chunk_password( 'abcd-ef!!12' )
	|| ! str_starts_with( $app_hash, '$generic$' )
	|| ! WP_Application_Passwords::check_password( 'component-fuzz-app', $app_hash )
	|| WP_Application_Passwords::check_password( 'wrong-component-fuzz-app', $app_hash )
) {
	fwrite( STDERR, "Application password smoke invariant failed.\n" );
	exit( 1 );
}

if ( ! defined( 'RECOVERY_MODE_COOKIE' ) || ! ( wp_recovery_mode() instanceof WP_Recovery_Mode ) ) {
	fwrite( STDERR, "Recovery mode smoke invariant failed.\n" );
	exit( 1 );
}

$admin_bar = new WP_Admin_Bar();
$admin_bar->add_node(
	array(
		'id'    => 'component-fuzz-smoke',
		'title' => 'Smoke <strong>Admin Bar</strong>',
		'href'  => 'https://example.test/component-fuzz/admin-bar-smoke',
	)
);
ob_start();
$admin_bar->render();
$admin_bar_html = (string) ob_get_clean();
if (
	! str_contains( $admin_bar_html, 'id="wpadminbar"' )
	|| ! str_contains( $admin_bar_html, "id='wp-admin-bar-component-fuzz-smoke'" )
	|| ! str_contains( $admin_bar_html, "href='https://example.test/component-fuzz/admin-bar-smoke'" )
) {
	fwrite( STDERR, "Admin bar render smoke invariant failed: {$admin_bar_html}\n" );
	exit( 1 );
}

$customizer_components = static function () {
	return array();
};
add_filter( 'customize_loaded_components', $customizer_components, 1000 );
$customizer = new WP_Customize_Manager(
	array(
		'changeset_uuid'     => wp_generate_uuid4(),
		'settings_previewed' => false,
	)
);
remove_filter( 'customize_loaded_components', $customizer_components, 1000 );
foreach ( array( '_changeset_data', '_post_values' ) as $customizer_cache_property ) {
	$customizer_cache_reflection = new ReflectionProperty( WP_Customize_Manager::class, $customizer_cache_property );
	if ( PHP_VERSION_ID < 80100 ) {
		$customizer_cache_reflection->setAccessible( true );
	}
	$customizer_cache_reflection->setValue( $customizer, array() );
}
$customizer_setting = $customizer->add_setting(
	'component_fuzz_smoke',
	array(
		'type'              => 'component_fuzz_smoke',
		'default'           => 'fallback',
		'sanitize_callback' => static function ( $value ) {
			return 'smoke:' . sanitize_key( $value );
		},
	)
);
$customizer->set_post_value( 'component_fuzz_smoke', 'Custom Value!' );
$customizer_caps = static function ( array $allcaps ) {
	$allcaps['customize']          = true;
	$allcaps['edit_theme_options'] = true;
	return $allcaps;
};
add_filter( 'user_has_cap', $customizer_caps, 10, 4 );
$customizer_post_value = $customizer_setting->post_value();
remove_filter( 'user_has_cap', $customizer_caps, 10 );
if (
	! ( $customizer->selective_refresh instanceof WP_Customize_Selective_Refresh )
	|| $customizer_setting !== $customizer->get_setting( 'component_fuzz_smoke' )
	|| 'smoke:customvalue' !== $customizer_post_value
) {
	fwrite( STDERR, "Customizer smoke invariant failed.\n" );
	exit( 1 );
}

$interactivity = new WP_Interactivity_API();
$interactivity->state( 'component-fuzz', array( 'text' => 'ok' ) );
$processed = $interactivity->process_directives( '<div data-wp-interactive="component-fuzz"><span data-wp-text="state.text">x</span></div>' );
if ( ! str_contains( $processed, '>ok</span>' ) ) {
	fwrite( STDERR, "Interactivity smoke invariant failed: {$processed}\n" );
	exit( 1 );
}

$_SERVER['REQUEST_URI'] = '/component-fuzz/router-smoke?x=1';
$router = new WP_Interactivity_API();
$GLOBALS['wp_interactivity'] = $router;
$router_region = wp_interactivity_process_directives( '<main data-wp-interactive="core/router" data-wp-router-region>body</main>' );
$router_state  = wp_interactivity_state( 'core/router' );
if (
	! str_contains( $router_region, 'data-wp-router-region' )
	|| 'http://example.test/component-fuzz/router-smoke?x=1' !== ( $router_state['url'] ?? null )
	|| false === has_action( 'wp_footer', array( $router, 'print_router_markup' ) )
) {
	fwrite( STDERR, "Interactivity router smoke invariant failed: {$router_region}\n" );
	exit( 1 );
}

$font_dir = wp_get_font_dir();
if ( ! str_ends_with( $font_dir['basedir'], '/uploads/fonts' ) || ! str_ends_with( $font_dir['baseurl'], '/uploads/fonts' ) ) {
	fwrite( STDERR, 'Font dir smoke invariant failed: ' . wp_json_encode( $font_dir ) . "\n" );
	exit( 1 );
}

ob_start();
wp_print_font_faces(
	array(
		array(
			array(
				'font-family' => 'Component Fuzz Smoke',
				'src'         => 'https://example.test/fonts/component-fuzz-smoke.woff2',
			),
		),
	)
);
$font_css = (string) ob_get_clean();
if ( ! str_contains( $font_css, '@font-face{' ) || ! str_contains( $font_css, 'Component Fuzz Smoke' ) ) {
	fwrite( STDERR, "Font face smoke invariant failed: {$font_css}\n" );
	exit( 1 );
}

$meta_key = 'component_fuzz_smoke_meta';
$GLOBALS['wp_meta_keys'] = array();
if (
	! register_meta(
		'post',
		$meta_key,
		array(
			'type'    => 'string',
			'single'  => true,
			'default' => 'component-fuzz-default',
		)
	)
	|| ! isset( get_registered_meta_keys( 'post' )[ $meta_key ] )
) {
	fwrite( STDERR, "Metadata registration smoke invariant failed.\n" );
	exit( 1 );
}
wp_cache_set( 90901, array( 'component_fuzz_smoke_sentinel' => array( '1' ) ), 'post_meta' );
if (
	'component-fuzz-default' !== get_metadata_default( 'post', 90901, $meta_key, true )
	|| 'component-fuzz-default' !== get_metadata( 'post', 90901, $meta_key, true )
	|| 'component-fuzz-default' !== get_registered_metadata( 'post', 90901, $meta_key )
	|| ! unregister_meta_key( 'post', $meta_key )
) {
	fwrite( STDERR, "Metadata default smoke invariant failed.\n" );
	exit( 1 );
}

$lazyloader = new WP_Metadata_Lazyloader();
$lazyloader->queue_objects( 'comment', array( 90902, 90903 ) );
if ( false === has_filter( 'get_comment_metadata', array( $lazyloader, 'lazyload_meta_callback' ) ) ) {
	fwrite( STDERR, "Metadata lazyloader queue smoke invariant failed.\n" );
	exit( 1 );
}
$lazyloader->reset_queue( 'comment' );
if ( false !== has_filter( 'get_comment_metadata', array( $lazyloader, 'lazyload_meta_callback' ) ) ) {
	fwrite( STDERR, "Metadata lazyloader reset smoke invariant failed.\n" );
	exit( 1 );
}

$GLOBALS['wp_query']    = new WP_Query();
$GLOBALS['wp_rewrite']  = new WP_Rewrite();
$_SERVER['HTTP_HOST']   = 'example.test';
$_SERVER['REQUEST_URI'] = '/component-fuzz/template-links-smoke/?paged=2&unsafe=<tag>';

$language_attributes = get_language_attributes( 'xhtml' );
if ( ! str_contains( $language_attributes, 'lang="en-US"' ) || ! str_contains( $language_attributes, 'xml:lang="en-US"' ) ) {
	fwrite( STDERR, "Template language attributes smoke invariant failed: {$language_attributes}\n" );
	exit( 1 );
}

$pagination = paginate_links(
	array(
		'base'      => 'https://example.test/archive/%_%',
		'format'    => 'page/%#%/',
		'total'     => 3,
		'current'   => 2,
		'type'      => 'array',
		'add_args'  => array( 'unsafe' => '<tag>' ),
		'prev_text' => 'Previous',
		'next_text' => 'Next',
	)
);
if (
	! is_array( $pagination )
	|| count( $pagination ) < 3
	|| ! str_contains( implode( "\n", $pagination ), 'unsafe=%3Ctag%3E' )
	|| str_contains( strtolower( implode( "\n", $pagination ) ), '<tag>' )
) {
	fwrite( STDERR, 'Template pagination smoke invariant failed: ' . wp_json_encode( $pagination ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "component-fuzz bootstrap smoke passed\n" );
