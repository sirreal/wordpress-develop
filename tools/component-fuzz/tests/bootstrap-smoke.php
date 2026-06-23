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
	'create_initial_post_types',
	'create_initial_taxonomies',
	'get_post',
	'wp_insert_post',
	'wp_update_post',
	'wp_trash_post',
	'wp_delete_post',
	'get_term',
	'get_terms',
	'term_exists',
	'wp_insert_term',
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
	'get_user_by',
	'get_userdata',
	'wp_insert_user',
	'wp_generate_password',
	'wp_fast_hash',
	'wp_verify_fast_hash',
	'wp_hash_password',
	'wp_check_password',
	'wp_mail',
	'wp_staticize_emoji_for_email',
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
	'get_comment',
	'wp_insert_comment',
	'wp_new_comment',
	'wp_delete_comment',
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
	'home_url',
	'get_site_url',
	'get_admin_url',
	'network_site_url',
	'network_home_url',
	'get_site',
	'get_sites',
	'get_network',
	'get_networks',
	'wp_normalize_site_data',
	'get_network_option',
	'add_network_option',
	'update_network_option',
	'delete_network_option',
	'switch_to_blog',
	'restore_current_blog',
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
	'status_header',
	'add_menu_page',
	'add_submenu_page',
	'remove_menu_page',
	'remove_submenu_page',
	'menu_page_url',
	'get_admin_page_parent',
	'get_admin_page_title',
	'get_plugin_page_hook',
	'get_plugin_page_hookname',
	'_get_list_table',
	'register_column_headers',
	'print_column_headers',
	'get_hidden_columns',
);

$required_classes = array(
	'WP_HTML_Processor',
	'WP_HTML_Tag_Processor',
	'WP_Interactivity_API',
	'WP_Interactivity_API_Directives_Processor',
	'WP_Block_Template',
	'WP_Block_Templates_Registry',
	'WP_Block_Parser',
	'WP_REST_Block_Pattern_Categories_Controller',
	'WP_REST_Block_Patterns_Controller',
	'WP_REST_Block_Types_Controller',
	'WP_REST_Controller',
	'WP_REST_Post_Statuses_Controller',
	'WP_REST_Post_Types_Controller',
	'WP_REST_Request',
	'WP_REST_Response',
	'WP_REST_Server',
	'WP_REST_Settings_Controller',
	'WP_REST_Taxonomies_Controller',
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
	'WP_PHPMailer',
	'PHPMailer\PHPMailer\PHPMailer',
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
	'WP_List_Table',
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
	'WP',
	'WP_Query',
	'WP_Rewrite',
	'WP_Term',
	'WP_User',
	'WP_Comment',
	'Component_Fuzz_WPDB_Stub',
	'WP_Site',
	'WP_Network',
	'WP_Site_Query',
	'WP_Network_Query',
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

$admin_workflows_snapshot = component_fuzz_smoke_snapshot_globals(
	array(
		'_GET',
		'_POST',
		'_REQUEST',
		'admin_page_hooks',
		'hook_suffix',
		'menu',
		'pagenow',
		'parent_file',
		'plugin_page',
		'submenu',
		'title',
		'typenow',
		'_parent_pages',
		'_registered_pages',
		'_wp_menu_nopriv',
		'_wp_real_parent_file',
		'_wp_submenu_nopriv',
	)
);
$admin_workflows_cap_filter = static function ( array $allcaps ): array {
	$allcaps['manage_options'] = true;
	return $allcaps;
};

add_filter( 'user_has_cap', $admin_workflows_cap_filter, 10, 4 );

try {
	$GLOBALS['menu']                 = array();
	$GLOBALS['submenu']              = array();
	$GLOBALS['admin_page_hooks']     = array();
	$GLOBALS['_registered_pages']    = array();
	$GLOBALS['_parent_pages']        = array();
	$GLOBALS['_wp_real_parent_file'] = array();
	$GLOBALS['_wp_submenu_nopriv']   = array();
	$GLOBALS['_wp_menu_nopriv']      = array();
	$GLOBALS['pagenow']              = 'admin.php';
	$_GET                            = array();
	$_POST                           = array();
	$_REQUEST                        = array( 'paged' => 1 );

	$top_hook = add_menu_page(
		'Component Fuzz Smoke',
		'Component Fuzz',
		'manage_options',
		'cfz-smoke.php',
		'',
		'dashicons-admin-tools',
		65
	);
	$sub_hook = add_submenu_page(
		'cfz-smoke.php',
		'Component Fuzz Submenu',
		'Submenu',
		'manage_options',
		'cfz-smoke-sub',
		'',
		1
	);
	$url      = menu_page_url( 'cfz-smoke-sub', false );

	if ( ! is_string( $top_hook ) || '' === $top_hook || ! is_string( $sub_hook ) || '' === $sub_hook || '' === $url ) {
		throw new RuntimeException( 'Admin menu helpers did not register smoke pages.' );
	}

	$screen = convert_to_screen( 'cfz-smoke-list' );
	$table  = new class( $screen ) extends WP_List_Table {
		public function __construct( WP_Screen $screen ) {
			parent::__construct(
				array(
					'ajax'     => false,
					'plural'   => 'cfz_smoke_items',
					'screen'   => $screen,
					'singular' => 'cfz_smoke_item',
				)
			);
		}

		public function get_columns(): array {
			return array(
				'cb'    => '<span class="screen-reader-text">Select</span>',
				'title' => 'Title',
			);
		}

		public function prepare_items(): void {
			$this->items = array(
				array(
					'id'    => 1,
					'title' => 'Smoke Item',
				),
			);
			$this->set_pagination_args(
				array(
					'per_page'    => 1,
					'total_items' => 1,
					'total_pages' => 1,
				)
			);
		}

		protected function column_cb( $item ): string {
			return '<input type="checkbox" name="cfz_smoke_item[]" value="' . esc_attr( $item['id'] ) . '" />';
		}

		public function column_title( $item ): string {
			return esc_html( $item['title'] );
		}
	};

	$table->prepare_items();
	ob_start();
	$table->display();
	$table_html = (string) ob_get_clean();

	remove_filter( "manage_{$screen->id}_columns", array( $table, 'get_columns' ), 0 );

	if (
		! str_contains( $table_html, 'wp-list-table' )
		|| ! str_contains( $table_html, 'Smoke Item' )
		|| ! str_contains( $table_html, 'name="_wpnonce"' )
	) {
		throw new RuntimeException( 'Synthetic list table smoke path did not render expected markup.' );
	}
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Admin workflows smoke invariant failed: ' . get_class( $e ) . ': ' . $e->getMessage() . "\n" );
	exit( 1 );
} finally {
	remove_filter( 'user_has_cap', $admin_workflows_cap_filter, 10 );
	component_fuzz_smoke_restore_globals( $admin_workflows_snapshot );
}

function component_fuzz_smoke_snapshot_globals( array $names ): array {
	$snapshot = array();

	foreach ( $names as $name ) {
		$snapshot[ $name ] = array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => array_key_exists( $name, $GLOBALS ) ? component_fuzz_smoke_clone_value( $GLOBALS[ $name ] ) : null,
		);
	}

	return $snapshot;
}

function component_fuzz_smoke_restore_globals( array $snapshot ): void {
	foreach ( $snapshot as $name => $entry ) {
		if ( $entry['exists'] ) {
			$GLOBALS[ $name ] = component_fuzz_smoke_clone_value( $entry['value'] );
		} else {
			unset( $GLOBALS[ $name ] );
		}
	}
}

function component_fuzz_smoke_clone_value( $value ) {
	if ( is_array( $value ) ) {
		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = component_fuzz_smoke_clone_value( $item );
		}
		return $copy;
	}

	if ( is_object( $value ) ) {
		return clone $value;
	}

	return $value;
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

$rest_controller_smoke_globals = array();
foreach ( array( 'wp_post_types', '_wp_post_type_features', 'post_type_meta_caps', 'wp', 'wp_rewrite', 'wp_rest_server' ) as $global_name ) {
	$rest_controller_smoke_globals[ $global_name ] = array(
		'exists' => array_key_exists( $global_name, $GLOBALS ),
		'value'  => array_key_exists( $global_name, $GLOBALS ) ? $GLOBALS[ $global_name ] : null,
	);
}

$rest_controller_smoke_error = null;
try {
	$GLOBALS['wp_post_types']          = array();
	$GLOBALS['_wp_post_type_features'] = array();
	$GLOBALS['post_type_meta_caps']    = array();
	$GLOBALS['wp_rest_server']         = new WP_REST_Server();

	if ( class_exists( 'WP' ) ) {
		$GLOBALS['wp']                    = new WP();
		$GLOBALS['wp']->public_query_vars = array();
	}
	if ( class_exists( 'WP_Rewrite' ) ) {
		$GLOBALS['wp_rewrite'] = new WP_Rewrite();
	}

	register_post_type(
		'cfz_smoke_type',
		array(
			'label'        => 'Component Fuzz Smoke',
			'public'       => true,
			'show_in_rest' => true,
			'rest_base'    => 'cfz-smoke-types',
			'rewrite'      => false,
			'query_var'    => false,
		)
	);
	register_post_type(
		'cfz_smoke_hidden',
		array(
			'label'        => 'Component Fuzz Hidden Smoke',
			'public'       => true,
			'show_in_rest' => false,
			'rewrite'      => false,
			'query_var'    => false,
		)
	);

	$rest_controller = new WP_REST_Post_Types_Controller();
	$rest_collection = $rest_controller->get_items( new WP_REST_Request( 'GET', '/wp/v2/types' ) );
	$rest_item_request = new WP_REST_Request( 'GET', '/wp/v2/types/cfz_smoke_type' );
	$rest_item_request->set_url_params( array( 'type' => 'cfz_smoke_type' ) );
	$rest_item = $rest_controller->get_item( $rest_item_request );

	if (
		! ( $rest_collection instanceof WP_REST_Response )
		|| ! ( $rest_item instanceof WP_REST_Response )
		|| ! isset( $rest_collection->get_data()['cfz_smoke_type'] )
		|| isset( $rest_collection->get_data()['cfz_smoke_hidden'] )
		|| 'cfz-smoke-types' !== ( $rest_item->get_data()['rest_base'] ?? null )
		|| rest_url( '/wp/v2/cfz-smoke-types' ) !== ( $rest_item->get_links()['https://api.w.org/items'][0]['href'] ?? null )
	) {
		$rest_controller_smoke_error = 'REST post type controller smoke invariant failed.';
	}
} catch ( Throwable $e ) {
	$rest_controller_smoke_error = 'REST post type controller smoke invariant failed: ' . get_class( $e ) . ': ' . $e->getMessage();
} finally {
	foreach ( $rest_controller_smoke_globals as $global_name => $entry ) {
		if ( $entry['exists'] ) {
			$GLOBALS[ $global_name ] = $entry['value'];
		} else {
			unset( $GLOBALS[ $global_name ] );
		}
	}
}

if ( null !== $rest_controller_smoke_error ) {
	fwrite( STDERR, $rest_controller_smoke_error . "\n" );
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

$mail_seen   = array();
$mail_filter = static function ( array $atts ) use ( &$mail_seen ): array {
	$atts['subject'] .= ' [filtered]';
	$mail_seen[]      = $atts;
	return $atts;
};
$pre_mail    = static function ( $pre, array $atts ) use ( &$mail_seen ) {
	$mail_seen[] = $atts;
	return 'component-fuzz-short-circuit';
};
add_filter( 'wp_mail', $mail_filter );
add_filter( 'pre_wp_mail', $pre_mail, 10, 2 );
$mail_result = wp_mail( 'smoke@example.test', 'Smoke Mail', 'body' );
remove_filter( 'wp_mail', $mail_filter );
remove_filter( 'pre_wp_mail', $pre_mail, 10 );
if (
	'component-fuzz-short-circuit' !== $mail_result
	|| 2 !== count( $mail_seen )
	|| 'Smoke Mail [filtered]' !== ( $mail_seen[0]['subject'] ?? null )
	|| 'Smoke Mail [filtered]' !== ( $mail_seen[1]['subject'] ?? null )
) {
	fwrite( STDERR, "Mail short-circuit smoke invariant failed.\n" );
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

$multisite_smoke_site_data = wp_normalize_site_data(
	array(
		'domain'     => 'Sub Domain!.Example.TEST',
		'path'       => 'team/site',
		'network_id' => '1',
		'public'     => '1',
		'archived'   => '0',
		'mature'     => '0',
		'spam'       => '0',
		'deleted'    => '0',
	)
);
$multisite_smoke_site      = new WP_Site(
	(object) array(
		'blog_id'      => '90931',
		'domain'       => $multisite_smoke_site_data['domain'],
		'path'         => $multisite_smoke_site_data['path'],
		'site_id'      => (string) $multisite_smoke_site_data['network_id'],
		'registered'   => '2026-06-22 00:00:00',
		'last_updated' => '2026-06-22 00:00:00',
		'public'       => (string) $multisite_smoke_site_data['public'],
		'archived'     => (string) $multisite_smoke_site_data['archived'],
		'mature'       => (string) $multisite_smoke_site_data['mature'],
		'spam'         => (string) $multisite_smoke_site_data['spam'],
		'deleted'      => (string) $multisite_smoke_site_data['deleted'],
		'lang_id'      => '0',
	)
);
$multisite_smoke_network   = new WP_Network(
	(object) array(
		'id'            => '1',
		'domain'        => 'https://www.example.test',
		'path'          => '/',
		'blog_id'       => '90931',
		'cookie_domain' => '',
		'site_name'     => 'Smoke Network',
	)
);

$multisite_site_pre_query = static function ( $site_data, WP_Site_Query $query ) use ( $multisite_smoke_site ) {
	unset( $site_data );

	$query->found_sites   = 1;
	$query->max_num_pages = 1;

	if ( ! empty( $query->query_vars['count'] ) ) {
		return 1;
	}

	if ( 'ids' === $query->query_vars['fields'] ) {
		return array( $multisite_smoke_site->id );
	}

	return array( $multisite_smoke_site );
};
$multisite_network_pre_query = static function ( $network_data, WP_Network_Query $query ) use ( $multisite_smoke_network ) {
	unset( $network_data );

	$query->found_networks = 1;
	$query->max_num_pages  = 1;

	if ( ! empty( $query->query_vars['count'] ) ) {
		return 1;
	}

	if ( 'ids' === $query->query_vars['fields'] ) {
		return array( $multisite_smoke_network->id );
	}

	return array( $multisite_smoke_network );
};

add_filter( 'sites_pre_query', $multisite_site_pre_query, 10, 2 );
add_filter( 'networks_pre_query', $multisite_network_pre_query, 10, 2 );
$multisite_site_ids        = get_sites(
	array(
		'fields'                 => 'ids',
		'number'                 => 1,
		'update_site_meta_cache' => false,
	)
);
$multisite_site_objects    = get_sites( array( 'number' => 1 ) );
$multisite_network_ids     = get_networks(
	array(
		'fields' => 'ids',
		'number' => 1,
	)
);
$multisite_network_objects = get_networks( array( 'number' => 1 ) );
remove_filter( 'sites_pre_query', $multisite_site_pre_query, 10 );
remove_filter( 'networks_pre_query', $multisite_network_pre_query, 10 );

$multisite_option_name = 'component_fuzz_smoke_network_option';
delete_network_option( null, $multisite_option_name );
$multisite_option_added   = add_network_option( null, $multisite_option_name, 'first' );
$multisite_option_first   = get_network_option( null, $multisite_option_name );
$multisite_option_updated = update_network_option( null, $multisite_option_name, 'second' );
$multisite_option_second  = get_network_option( null, $multisite_option_name );
$multisite_option_deleted = delete_network_option( null, $multisite_option_name );
$multisite_option_gone    = get_network_option( null, $multisite_option_name, 'fallback' );

$multisite_original_blog_id = get_current_blog_id();
$multisite_original_stack   = $GLOBALS['_wp_switched_stack'];
$multisite_original_switched = $GLOBALS['switched'];
$multisite_same_switch      = switch_to_blog( $multisite_original_blog_id );
$multisite_stack_after_same = $GLOBALS['_wp_switched_stack'];
$multisite_restore_same     = restore_current_blog();
$multisite_restore_empty    = restore_current_blog();

if (
	'SubDomain.Example.TEST' !== $multisite_smoke_site_data['domain']
	|| '/team/site/' !== $multisite_smoke_site_data['path']
	|| 90931 !== $multisite_smoke_site->id
	|| 1 !== $multisite_smoke_site->network_id
	|| ! is_array( $multisite_smoke_site->to_array() )
	|| 1 !== $multisite_smoke_network->id
	|| 90931 !== $multisite_smoke_network->site_id
	|| 'example.test' !== $multisite_smoke_network->cookie_domain
	|| array( 90931 ) !== $multisite_site_ids
	|| 1 !== count( $multisite_site_objects )
	|| ! ( $multisite_site_objects[0] instanceof WP_Site )
	|| array( 1 ) !== $multisite_network_ids
	|| 1 !== count( $multisite_network_objects )
	|| ! ( $multisite_network_objects[0] instanceof WP_Network )
	|| true !== $multisite_option_added
	|| 'first' !== $multisite_option_first
	|| true !== $multisite_option_updated
	|| 'second' !== $multisite_option_second
	|| true !== $multisite_option_deleted
	|| 'fallback' !== $multisite_option_gone
	|| true !== $multisite_same_switch
	|| array( $multisite_original_blog_id ) !== $multisite_stack_after_same
	|| true !== $multisite_restore_same
	|| false !== $multisite_restore_empty
	|| $multisite_original_blog_id !== get_current_blog_id()
	|| $multisite_original_stack !== $GLOBALS['_wp_switched_stack']
	|| $multisite_original_switched !== $GLOBALS['switched']
	|| ! str_contains( network_site_url( 'wp-admin/network.php', 'https' ), 'wp-admin/network.php' )
	|| ! str_contains( network_home_url( 'dashboard/', 'http' ), 'dashboard/' )
) {
	fwrite( STDERR, "Multisite smoke invariant failed.\n" );
	exit( 1 );
}

$request_lifecycle_globals = array();
foreach ( array( 'wp_rewrite' ) as $request_lifecycle_global ) {
	$request_lifecycle_globals[ $request_lifecycle_global ] = array(
		'exists' => array_key_exists( $request_lifecycle_global, $GLOBALS ),
		'value'  => $GLOBALS[ $request_lifecycle_global ] ?? null,
	);
}
$request_lifecycle_server = array();
foreach ( array( 'HTTP_HOST', 'PHP_SELF', 'REQUEST_METHOD', 'REQUEST_URI', 'PATH_INFO' ) as $request_lifecycle_server_key ) {
	$request_lifecycle_server[ $request_lifecycle_server_key ] = array(
		'exists' => array_key_exists( $request_lifecycle_server_key, $_SERVER ),
		'value'  => $_SERVER[ $request_lifecycle_server_key ] ?? null,
	);
}
$request_lifecycle_get  = $_GET;
$request_lifecycle_post = $_POST;

$request_lifecycle_rules = static function () {
	return array( '^smoke/([^/]+)/?$' => 'index.php?component_fuzz_smoke=$matches[1]&page=3' );
};
$request_lifecycle_home = static function () {
	return 'http://example.test/site-base';
};
add_filter( 'pre_option_rewrite_rules', $request_lifecycle_rules );
add_filter( 'pre_option_home', $request_lifecycle_home );

$request_lifecycle_wp = new WP();
$request_lifecycle_wp->add_query_var( 'component_fuzz_smoke' );
$GLOBALS['wp_rewrite']                      = new WP_Rewrite();
$GLOBALS['wp_rewrite']->permalink_structure = '/%postname%/';
$GLOBALS['wp_rewrite']->front               = '/';
$GLOBALS['wp_rewrite']->root                = '';
$_SERVER['HTTP_HOST']                       = 'example.test';
$_SERVER['PHP_SELF']                        = '/site-base/index.php';
$_SERVER['REQUEST_METHOD']                  = 'GET';
$_SERVER['REQUEST_URI']                     = '/site-base/smoke/value/';
$_SERVER['PATH_INFO']                       = '';
$_GET                                      = array();
$_POST                                     = array();
$request_lifecycle_parsed                  = $request_lifecycle_wp->parse_request();

remove_filter( 'pre_option_rewrite_rules', $request_lifecycle_rules );
remove_filter( 'pre_option_home', $request_lifecycle_home );
foreach ( $request_lifecycle_globals as $request_lifecycle_global => $entry ) {
	if ( $entry['exists'] ) {
		$GLOBALS[ $request_lifecycle_global ] = $entry['value'];
	} else {
		unset( $GLOBALS[ $request_lifecycle_global ] );
	}
}
foreach ( $request_lifecycle_server as $request_lifecycle_server_key => $entry ) {
	if ( $entry['exists'] ) {
		$_SERVER[ $request_lifecycle_server_key ] = $entry['value'];
	} else {
		unset( $_SERVER[ $request_lifecycle_server_key ] );
	}
}
$_GET  = $request_lifecycle_get;
$_POST = $request_lifecycle_post;

if (
	true !== $request_lifecycle_parsed
	|| '^smoke/([^/]+)/?$' !== $request_lifecycle_wp->matched_rule
	|| 'value' !== ( $request_lifecycle_wp->query_vars['component_fuzz_smoke'] ?? null )
	|| '3' !== ( $request_lifecycle_wp->query_vars['page'] ?? null )
) {
	fwrite( STDERR, "Request lifecycle parse smoke invariant failed.\n" );
	exit( 1 );
}

$lifecycle_globals = array();
foreach ( array( 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'wp_rewrite', 'current_user', 'user_ID' ) as $lifecycle_global ) {
	$lifecycle_globals[ $lifecycle_global ] = array(
		'exists' => array_key_exists( $lifecycle_global, $GLOBALS ),
		'value'  => $GLOBALS[ $lifecycle_global ] ?? null,
	);
}

$lifecycle_server = array();
foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_SOFTWARE' ) as $server_key ) {
	$lifecycle_server[ $server_key ] = array(
		'exists' => array_key_exists( $server_key, $_SERVER ),
		'value'  => $_SERVER[ $server_key ] ?? null,
	);
}

$lifecycle_options = $GLOBALS['wpdb']->component_fuzz_get_options();
$GLOBALS['wpdb']->component_fuzz_reset_content();
$GLOBALS['wpdb']->component_fuzz_reset_options(
	array(
		'admin_email'            => 'admin@example.test',
		'blog_charset'           => 'UTF-8',
		'blogname'               => 'Component Fuzz Smoke',
		'comment_max_links'      => 2,
		'comment_moderation'     => 0,
		'comment_registration'   => 0,
		'default_category'       => 0,
		'default_comment_status' => 'open',
		'default_ping_status'    => 'closed',
		'default_role'           => 'subscriber',
		'disallowed_keys'        => '',
		'home'                   => 'http://example.test',
		'moderation_keys'        => '',
		'permalink_structure'    => '',
		'require_name_email'     => 0,
		'siteurl'                => 'http://example.test',
	)
);
wp_cache_flush();

$GLOBALS['wp_rewrite']       = new WP_Rewrite();
$GLOBALS['wp_post_types']    = array();
$GLOBALS['wp_post_statuses'] = array();
$GLOBALS['wp_taxonomies']    = array();
create_initial_post_types();
create_initial_taxonomies();
wp_set_current_user( 0 );

$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz bootstrap smoke';
$_SERVER['REQUEST_URI']     = '/component-fuzz/bootstrap-lifecycle/';
$_SERVER['HTTP_HOST']       = 'example.test';
$_SERVER['SERVER_SOFTWARE'] = 'ComponentFuzz';

$lifecycle_approve = static function () {
	return 1;
};
add_filter( 'pre_comment_approved', $lifecycle_approve, 10, 2 );

$lifecycle_user_id = wp_insert_user(
	array(
		'user_login'   => 'component_fuzz_smoke_user',
		'user_pass'    => 'component-fuzz-smoke-pass',
		'user_email'   => 'component-fuzz-smoke-user@example.test',
		'display_name' => 'Component Fuzz Smoke User',
		'role'         => 'subscriber',
	)
);
$lifecycle_term    = wp_insert_term(
	'Component Fuzz Smoke Category',
	'category',
	array(
		'slug'        => 'component-fuzz-smoke-category',
		'description' => 'Smoke term description',
	)
);
$lifecycle_post_id = wp_insert_post(
	wp_slash(
		array(
			'post_type'      => 'page',
			'post_title'     => 'Component Fuzz Smoke Post',
			'post_content'   => '<p>Smoke lifecycle content</p>',
			'post_status'    => 'draft',
			'post_author'    => $lifecycle_user_id,
			'post_name'      => 'component-fuzz-smoke-post',
			'comment_status' => 'open',
		)
	),
	true,
	false
);
$lifecycle_update  = wp_update_post(
	wp_slash(
		array(
			'ID'           => $lifecycle_post_id,
			'post_title'   => 'Component Fuzz Smoke Post Updated',
			'post_status'  => 'publish',
			'post_content' => 'Updated lifecycle smoke content',
		)
	),
	true,
	false
);
$lifecycle_comment = wp_insert_comment(
	array(
		'comment_post_ID'      => $lifecycle_post_id,
		'comment_author'       => 'Smoke Commenter',
		'comment_author_email' => 'commenter@example.test',
		'comment_author_url'   => 'http://example.test/commenter',
		'comment_content'      => 'Direct smoke comment',
		'comment_approved'     => '1',
		'comment_type'         => 'comment',
	)
);
$lifecycle_new_comment = wp_new_comment(
	array(
		'comment_post_ID'      => $lifecycle_post_id,
		'comment_author'       => 'Smoke New Commenter',
		'comment_author_email' => 'new-commenter@example.test',
		'comment_author_url'   => 'http://example.test/new-commenter',
		'comment_content'      => 'wp_new_comment smoke comment',
	),
	true
);

$lifecycle_user       = get_userdata( $lifecycle_user_id );
$lifecycle_user_email = get_user_by( 'email', 'component-fuzz-smoke-user@example.test' );
$lifecycle_term_obj   = is_array( $lifecycle_term ) ? get_term( $lifecycle_term['term_id'], 'category' ) : null;
$lifecycle_terms      = is_array( $lifecycle_term )
	? get_terms(
		array(
			'taxonomy'               => 'category',
			'include'                => array( (int) $lifecycle_term['term_id'] ),
			'hide_empty'             => false,
			'update_term_meta_cache' => false,
		)
	)
	: array();
$lifecycle_exists     = is_array( $lifecycle_term ) ? term_exists( (int) $lifecycle_term['term_id'], 'category' ) : null;
$lifecycle_post       = get_post( $lifecycle_post_id );
$lifecycle_comment_1  = get_comment( $lifecycle_comment );
$lifecycle_comment_2  = get_comment( $lifecycle_new_comment );
$lifecycle_count_2    = $lifecycle_post instanceof WP_Post ? (int) get_post( $lifecycle_post_id )->comment_count : null;
$lifecycle_delete_1   = wp_delete_comment( $lifecycle_comment, true );
$lifecycle_count_1    = $lifecycle_post instanceof WP_Post ? (int) get_post( $lifecycle_post_id )->comment_count : null;
$lifecycle_delete_2   = wp_delete_comment( $lifecycle_new_comment, true );
$lifecycle_count_0    = $lifecycle_post instanceof WP_Post ? (int) get_post( $lifecycle_post_id )->comment_count : null;
$lifecycle_trash      = wp_trash_post( $lifecycle_post_id );
$lifecycle_after_trash = get_post( $lifecycle_post_id );
$lifecycle_delete_post = wp_delete_post( $lifecycle_post_id, true );
$lifecycle_after_delete = get_post( $lifecycle_post_id );

remove_filter( 'pre_comment_approved', $lifecycle_approve, 10 );

if (
	! is_int( $lifecycle_user_id )
	|| ! ( $lifecycle_user instanceof WP_User )
	|| ! ( $lifecycle_user_email instanceof WP_User )
	|| $lifecycle_user_id !== $lifecycle_user_email->ID
	|| ! is_array( $lifecycle_term )
	|| ! ( $lifecycle_term_obj instanceof WP_Term )
	|| 'component-fuzz-smoke-category' !== $lifecycle_term_obj->slug
	|| ! is_array( $lifecycle_terms )
	|| 1 !== count( $lifecycle_terms )
	|| ! is_array( $lifecycle_exists )
	|| ! is_int( $lifecycle_post_id )
	|| $lifecycle_update !== $lifecycle_post_id
	|| ! ( $lifecycle_post instanceof WP_Post )
	|| 'publish' !== $lifecycle_post->post_status
	|| ! ( $lifecycle_comment_1 instanceof WP_Comment )
	|| ! ( $lifecycle_comment_2 instanceof WP_Comment )
	|| 2 !== $lifecycle_count_2
	|| true !== $lifecycle_delete_1
	|| 1 !== $lifecycle_count_1
	|| true !== $lifecycle_delete_2
	|| 0 !== $lifecycle_count_0
	|| ! ( $lifecycle_trash instanceof WP_Post )
	|| ! ( $lifecycle_after_trash instanceof WP_Post )
	|| 'trash' !== $lifecycle_after_trash->post_status
	|| ! ( $lifecycle_delete_post instanceof WP_Post )
	|| null !== $lifecycle_after_delete
) {
	fwrite( STDERR, "Lifecycle CRUD smoke invariant failed.\n" );
	exit( 1 );
}

$GLOBALS['wpdb']->component_fuzz_reset_content();
$GLOBALS['wpdb']->component_fuzz_reset_options( $lifecycle_options );
wp_cache_flush();
foreach ( $lifecycle_globals as $lifecycle_global => $entry ) {
	if ( $entry['exists'] ) {
		$GLOBALS[ $lifecycle_global ] = $entry['value'];
	} else {
		unset( $GLOBALS[ $lifecycle_global ] );
	}
}
foreach ( $lifecycle_server as $server_key => $entry ) {
	if ( $entry['exists'] ) {
		$_SERVER[ $server_key ] = $entry['value'];
	} else {
		unset( $_SERVER[ $server_key ] );
	}
}

fwrite( STDOUT, "component-fuzz bootstrap smoke passed\n" );
