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
	'get_block_theme_folders',
	'register_block_template',
	'unregister_block_template',
	'get_block_file_template',
	'_build_block_template_result_from_file',
	'get_block_templates',
	'get_block_template',
	'locate_block_template',
	'resolve_block_template',
	'locate_template',
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
	'get_current_screen',
	'set_current_screen',
	'convert_to_screen',
	'get_column_headers',
	'add_settings_section',
	'add_settings_field',
	'do_settings_sections',
	'do_settings_fields',
	'settings_fields',
	'register_setting',
	'unregister_setting',
	'add_meta_box',
	'do_meta_boxes',
	'remove_meta_box',
	'do_accordion_sections',
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
	'wp_get_image_editor',
	'wp_image_editor_supports',
	'wp_get_image_editor_output_format',
	'image_make_intermediate_size',
	'wp_create_image_subsizes',
	'wp_generate_attachment_metadata',
	'wp_get_attachment_metadata',
	'wp_update_attachment_metadata',
	'get_attached_file',
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
	'WP_Block_Template',
	'WP_Block_Templates_Registry',
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
	'WP_Screen',
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
	'WP_Image_Editor',
	'WP_Image_Editor_GD',
	'WP_Image_Editor_Imagick',
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

$screen_globals = array();
foreach ( array( 'current_screen', 'typenow', 'taxnow' ) as $screen_global ) {
	$screen_globals[ $screen_global ] = array(
		'exists' => array_key_exists( $screen_global, $GLOBALS ),
		'value'  => $GLOBALS[ $screen_global ] ?? null,
	);
}
$screen = convert_to_screen( 'post-new.php' );
set_current_screen( $screen );
$columns_filter = static function () {
	return array( 'component_fuzz' => 'Component Fuzz Column' );
};
add_filter( 'manage_component-fuzz-smoke_columns', $columns_filter );
$columns = get_column_headers( 'component-fuzz-smoke' );
remove_filter( 'manage_component-fuzz-smoke_columns', $columns_filter );
foreach ( $screen_globals as $screen_global => $entry ) {
	if ( $entry['exists'] ) {
		$GLOBALS[ $screen_global ] = $entry['value'];
	} else {
		unset( $GLOBALS[ $screen_global ] );
	}
}
if (
	! ( $screen instanceof WP_Screen )
	|| 'post' !== $screen->id
	|| 'post' !== $screen->base
	|| 'post' !== $screen->post_type
	|| 'add' !== $screen->action
	|| get_current_screen() !== ( $screen_globals['current_screen']['value'] ?? null )
	|| array( 'component_fuzz' => 'Component Fuzz Column' ) !== $columns
) {
	fwrite( STDERR, "Admin screen smoke invariant failed.\n" );
	exit( 1 );
}

$setting_group = 'component_fuzz_smoke_group';
$setting_name  = 'component_fuzz_smoke_option';
$sanitize_seen = array();
$sanitize      = static function ( $value ) use ( &$sanitize_seen ) {
	$sanitize_seen[] = $value;
	return 'smoke:' . sanitize_key( $value );
};
register_setting(
	$setting_group,
	$setting_name,
	array(
		'type'              => 'string',
		'label'             => 'Smoke <em>Setting</em>',
		'description'       => 'Smoke setting description',
		'sanitize_callback' => $sanitize,
		'default'           => 'smoke-default',
		'show_in_rest'      => false,
	)
);
$settings_registry = get_registered_settings();
$sanitized_setting = sanitize_option( $setting_name, 'Raw Value!' );
ob_start();
settings_fields( $setting_group );
$settings_fields_html = (string) ob_get_clean();
unregister_setting( $setting_group, $setting_name );
if (
	! isset( $settings_registry[ $setting_name ] )
	|| 'smoke:rawvalue' !== $sanitized_setting
	|| array( 'Raw Value!' ) !== $sanitize_seen
	|| ! str_contains( $settings_fields_html, "name='option_page'" )
	|| ! str_contains( $settings_fields_html, 'name="_wpnonce"' )
	|| isset( get_registered_settings()[ $setting_name ] )
) {
	fwrite( STDERR, "Settings registry smoke invariant failed: {$settings_fields_html}\n" );
	exit( 1 );
}

$settings_page    = 'component_fuzz_smoke_page';
$settings_section = 'component_fuzz_smoke_section';
$settings_field   = 'component_fuzz_smoke_field';
add_settings_section(
	$settings_section,
	'Smoke Section',
	static function ( $section ) {
		echo '<p class="component-fuzz-smoke-section">' . esc_html( $section['id'] ) . '</p>';
	},
	$settings_page,
	array(
		'before_section' => '<section><script>alert(1)</script>',
		'after_section'  => '</section>',
	)
);
add_settings_field(
	$settings_field,
	'Smoke Field',
	static function ( $args ) {
		echo '<input class="component-fuzz-smoke-field" id="' . esc_attr( $args['label_for'] ) . '" />';
	},
	$settings_page,
	$settings_section,
	array( 'label_for' => 'component_fuzz_smoke_input" onclick="bad' )
);
ob_start();
do_settings_sections( $settings_page );
$settings_sections_html = (string) ob_get_clean();
if (
	! str_contains( $settings_sections_html, 'component-fuzz-smoke-section' )
	|| ! str_contains( $settings_sections_html, 'component-fuzz-smoke-field' )
	|| str_contains( strtolower( $settings_sections_html ), '<script' )
	|| str_contains( $settings_sections_html, ' onclick="' )
) {
	fwrite( STDERR, "Settings rendering smoke invariant failed: {$settings_sections_html}\n" );
	exit( 1 );
}

$meta_screen = convert_to_screen( 'component-fuzz-meta-smoke' );
$meta_calls  = array();
$meta_box    = static function ( $object, $box ) use ( &$meta_calls ) {
	unset( $object );

	$meta_calls[] = $box['id'];
	echo '<span class="component-fuzz-meta-box">' . esc_html( $box['args']['payload'] ) . '</span>';
};
add_meta_box(
	'component_fuzz_meta_high',
	'Smoke High',
	$meta_box,
	$meta_screen,
	'normal',
	'high',
	array( 'payload' => '<b>high</b>' )
);
add_meta_box(
	'component_fuzz_meta_removed',
	'Smoke Removed',
	$meta_box,
	$meta_screen,
	'normal',
	'low',
	array( 'payload' => '<b>removed</b>' )
);
remove_meta_box( 'component_fuzz_meta_removed', $meta_screen, 'normal' );
ob_start();
$meta_count = do_meta_boxes( $meta_screen, 'normal', (object) array( 'ID' => 90904 ) );
$meta_html  = (string) ob_get_clean();
add_meta_box(
	'component_fuzz_accordion',
	'Accordion <script>bad</script>',
	$meta_box,
	$meta_screen,
	'side',
	'high',
	array( 'payload' => '<b>accordion</b>' )
);
ob_start();
$accordion_count = do_accordion_sections( $meta_screen, 'side', (object) array( 'ID' => 90905 ) );
$accordion_html  = (string) ob_get_clean();
if (
	1 !== $meta_count
	|| array( 'component_fuzz_meta_high', 'component_fuzz_accordion' ) !== $meta_calls
	|| ! str_contains( $meta_html, 'component-fuzz-meta-box' )
	|| str_contains( $meta_html, 'component_fuzz_meta_removed' )
	|| 1 !== $accordion_count
	|| ! str_contains( $accordion_html, 'accordion-container' )
	|| str_contains( strtolower( $accordion_html ), '<script' )
) {
	fwrite( STDERR, "Meta box smoke invariant failed: {$meta_html}\n{$accordion_html}\n" );
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

$default_image_output_format = wp_get_image_editor_output_format( '/tmp/component-fuzz-smoke.heic', 'image/heic' );
$image_output_format_filter  = static function ( array $formats, string $filename, string $mime_type ): array {
	if ( 'image/jpeg' === $mime_type && str_contains( $filename, 'component-fuzz-smoke' ) ) {
		$formats['image/jpeg'] = 'image/webp';
	}

	return $formats;
};
add_filter( 'image_editor_output_format', $image_output_format_filter, 10, 3 );
$filtered_image_output_format = wp_get_image_editor_output_format( '/tmp/component-fuzz-smoke.jpg', 'image/jpeg' );
remove_filter( 'image_editor_output_format', $image_output_format_filter, 10 );
if (
	'image/jpeg' !== ( $default_image_output_format['image/heic'] ?? null )
	|| 'image/webp' !== ( $filtered_image_output_format['image/jpeg'] ?? null )
	|| ! is_bool( wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ) )
) {
	fwrite( STDERR, "Image editor output/support smoke invariant failed.\n" );
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

$attachment_smoke_id       = 90904;
$attachment_smoke_relative = '2026/06/component-fuzz-smoke.jpg';
$attachment_smoke_meta     = array(
	'width'          => 64,
	'height'         => 48,
	'file'           => $attachment_smoke_relative,
	'original_image' => 'component-fuzz-smoke-original.jpg',
	'sizes'          => array(
		'thumbnail' => array(
			'file'      => 'component-fuzz-smoke-32x24.jpg',
			'width'     => 32,
			'height'    => 24,
			'mime-type' => 'image/jpeg',
		),
	),
);
$attachment_smoke_uploads  = static function ( array $uploads ): array {
	$uploads['basedir'] = sys_get_temp_dir() . '/component-fuzz-smoke-uploads';
	$uploads['baseurl'] = 'http://example.test/component-fuzz-smoke-uploads';
	$uploads['path']    = $uploads['basedir'];
	$uploads['url']     = $uploads['baseurl'];
	$uploads['subdir']  = '';
	$uploads['error']   = false;
	return $uploads;
};
wp_cache_set(
	$attachment_smoke_id,
	(object) array(
		'ID'             => $attachment_smoke_id,
		'post_type'      => 'attachment',
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'component-fuzz-smoke',
		'post_status'    => 'inherit',
		'filter'         => 'raw',
	),
	'posts'
);
wp_cache_set(
	$attachment_smoke_id,
	array(
		'_wp_attached_file'       => array( $attachment_smoke_relative ),
		'_wp_attachment_metadata' => array( $attachment_smoke_meta ),
	),
	'post_meta'
);
add_filter( 'upload_dir', $attachment_smoke_uploads );
$attachment_smoke_file     = get_attached_file( $attachment_smoke_id, true );
$attachment_smoke_read     = wp_get_attachment_metadata( $attachment_smoke_id, true );
$attachment_smoke_is_image = wp_attachment_is_image( $attachment_smoke_id );
remove_filter( 'upload_dir', $attachment_smoke_uploads );
wp_cache_delete( $attachment_smoke_id, 'posts' );
wp_cache_delete( $attachment_smoke_id, 'post_meta' );
if (
	! str_ends_with( $attachment_smoke_file, '/2026/06/component-fuzz-smoke.jpg' )
	|| $attachment_smoke_read !== $attachment_smoke_meta
	|| ! $attachment_smoke_is_image
) {
	fwrite( STDERR, "Attachment metadata smoke invariant failed.\n" );
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

$block_template_smoke_remove = static function ( string $dir ) use ( &$block_template_smoke_remove ): bool {
	if ( ! file_exists( $dir ) ) {
		return true;
	}
	if ( ! is_dir( $dir ) ) {
		return @unlink( $dir );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$path = $item->getPathname();
		if ( $item->isDir() && ! $item->isLink() ) {
			if ( ! @rmdir( $path ) ) {
				return false;
			}
		} elseif ( ! @unlink( $path ) ) {
			return false;
		}
	}

	return @rmdir( $dir );
};

$block_template_smoke_root   = sys_get_temp_dir() . '/component-fuzz-block-template-smoke-' . getmypid();
$block_template_theme_root   = $block_template_smoke_root . '/themes';
$block_template_child_slug   = 'component-fuzz-smoke-child';
$block_template_parent_slug  = 'component-fuzz-smoke-parent';
$block_template_child_dir    = $block_template_theme_root . '/' . $block_template_child_slug;
$block_template_parent_dir   = $block_template_theme_root . '/' . $block_template_parent_slug;
$block_template_smoke_marker = 'component-fuzz-block-template-smoke';

$block_template_smoke_remove( $block_template_smoke_root );
foreach ( array( $block_template_child_dir . '/templates', $block_template_parent_dir . '/templates' ) as $block_template_smoke_dir ) {
	if ( ! mkdir( $block_template_smoke_dir, 0777, true ) && ! is_dir( $block_template_smoke_dir ) ) {
		fwrite( STDERR, "Block template smoke could not create directory: {$block_template_smoke_dir}\n" );
		exit( 1 );
	}
}
file_put_contents( $block_template_parent_dir . '/style.css', "/*\nTheme Name: Component Fuzz Smoke Parent\n*/\n" );
file_put_contents( $block_template_child_dir . '/style.css', "/*\nTheme Name: Component Fuzz Smoke Child\nTemplate: {$block_template_parent_slug}\n*/\n" );
file_put_contents( $block_template_child_dir . '/theme.json', wp_json_encode( array( 'version' => 3 ), JSON_PRETTY_PRINT ) . "\n" );
file_put_contents( $block_template_child_dir . '/templates/index.html', '<!-- wp:paragraph --><p>' . $block_template_smoke_marker . '</p><!-- /wp:paragraph -->' );

$block_template_stylesheet = static function () use ( $block_template_child_slug ): string {
	return $block_template_child_slug;
};
$block_template_template = static function () use ( $block_template_parent_slug ): string {
	return $block_template_parent_slug;
};
$block_template_theme_root_filter = static function () use ( $block_template_theme_root ): string {
	return $block_template_theme_root;
};
$block_template_posts_pre_query = static function ( $posts, $query ) {
	if ( is_object( $query ) && method_exists( $query, 'get' ) ) {
		$post_type = $query->get( 'post_type' );
		if ( in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
			return array();
		}
	}
	return $posts;
};

if ( ! isset( $GLOBALS['wp_theme_directories'] ) || ! is_array( $GLOBALS['wp_theme_directories'] ) ) {
	$GLOBALS['wp_theme_directories'] = array();
}
$GLOBALS['wp_theme_directories'] = array_values(
	array_unique(
		array_merge(
			$GLOBALS['wp_theme_directories'],
			array( WP_CONTENT_DIR . '/themes', $block_template_theme_root )
		)
	)
);

add_filter( 'stylesheet', $block_template_stylesheet );
add_filter( 'template', $block_template_template );
add_filter( 'theme_root', $block_template_theme_root_filter );
add_filter( 'pre_option_stylesheet', $block_template_stylesheet );
add_filter( 'pre_option_template', $block_template_template );
add_filter( 'pre_option_stylesheet_root', $block_template_theme_root_filter );
add_filter( 'pre_option_template_root', $block_template_theme_root_filter );
add_filter( 'posts_pre_query', $block_template_posts_pre_query, 10, 2 );
add_theme_support( 'block-templates' );
wp_clean_theme_json_cache();

$registered_block_template = register_block_template(
	'component-fuzz-smoke//registered',
	array(
		'title'      => 'Component Fuzz Smoke Registered',
		'content'    => '<!-- wp:paragraph --><p>registered smoke</p><!-- /wp:paragraph -->',
		'post_types' => array( 'page' ),
	)
);
$file_block_template       = get_block_file_template( $block_template_child_slug . '//index', 'wp_template' );
$listed_block_templates    = get_block_templates( array( 'slug__in' => array( 'index' ) ), 'wp_template' );
$resolved_block_template   = resolve_block_template( 'index', array( 'index.php' ), '' );
$located_block_template    = locate_block_template( '', 'index', array( 'index.php' ) );

if (
	! ( $registered_block_template instanceof WP_Block_Template )
	|| 'plugin' !== $registered_block_template->source
	|| ! ( unregister_block_template( 'component-fuzz-smoke//registered' ) instanceof WP_Block_Template )
	|| ! ( $file_block_template instanceof WP_Block_Template )
	|| $block_template_child_slug . '//index' !== $file_block_template->id
	|| 'theme' !== $file_block_template->source
	|| ! str_contains( $file_block_template->content, $block_template_smoke_marker )
	|| 1 !== count( $listed_block_templates )
	|| ! ( $resolved_block_template instanceof WP_Block_Template )
	|| 'index' !== $resolved_block_template->slug
	|| ABSPATH . WPINC . '/template-canvas.php' !== $located_block_template
) {
	fwrite( STDERR, "Block template smoke invariant failed.\n" );
	exit( 1 );
}

remove_filter( 'stylesheet', $block_template_stylesheet );
remove_filter( 'template', $block_template_template );
remove_filter( 'theme_root', $block_template_theme_root_filter );
remove_filter( 'pre_option_stylesheet', $block_template_stylesheet );
remove_filter( 'pre_option_template', $block_template_template );
remove_filter( 'pre_option_stylesheet_root', $block_template_theme_root_filter );
remove_filter( 'pre_option_template_root', $block_template_theme_root_filter );
remove_filter( 'posts_pre_query', $block_template_posts_pre_query, 10 );
wp_clean_theme_json_cache();
$block_template_smoke_remove( $block_template_smoke_root );

fwrite( STDOUT, "component-fuzz bootstrap smoke passed\n" );
