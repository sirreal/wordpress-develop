<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes safe Site Editor REST controller paths without live DB or export side effects.
 */
final class RestSiteEditorSurface {
	public const NAME = 'rest-site-editor';
	private const LIVE_EXPORT_STDOUT_SENTINEL = "__COMPONENT_FUZZ_REST_SITE_EDITOR_EXPORT__\n";

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_endpoint_classes();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'rest-site-editor.bootstrap-apis-available',
					'Required Site Editor REST APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot           = self::snapshot_state();
		$before_fingerprint = self::state_fingerprint();
		$rows               = array();
		$ob_level           = ob_get_level();
		$temp_paths         = array();

		try {
			self::prepare_runtime();

			$case       = self::case_for_context( $ctx );
			$temp_paths = self::prepare_theme_fixture( $case );
			$fixtures   = self::seed_fixtures( $case );

			$rows[] = self::check_route_registration_and_normalization( $ctx, $case );
			$rows[] = self::check_global_styles_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_global_styles_revisions( $ctx, $case, $fixtures );
			$rows[] = self::check_templates_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_template_item_route_dispatch( $ctx, $case, $fixtures );
			$rows[] = self::check_template_lookup_fallback_dispatch( $ctx, $case );
			$rows[] = self::check_template_revisions_autosaves( $ctx, $case, $fixtures );
			$rows[] = self::check_navigation_fallback_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_edit_site_export_controller( $ctx );
			$rows[] = self::check_block_templates_export_generator( $ctx, $case );
			$rows[] = self::check_live_edit_site_export_controller( $ctx, $case );
			$rows[] = self::check_template_collection_dispatch_guards( $ctx, $case );
			$rows[] = self::check_template_mutation_lifecycle( $ctx, $case, $fixtures );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'rest-site-editor.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}

			$cleanup = self::cleanup_paths( $temp_paths );
			self::restore_state( $snapshot );

			$rows[] = self::row(
				$ctx,
				'rest-site-editor.temp-fixtures-cleaned',
				$cleanup['ok'],
				$cleanup
			);

			$after_fingerprint = self::state_fingerprint();
			$rows[]            = self::row(
				$ctx,
				'rest-site-editor.state-restored',
				$before_fingerprint === $after_fingerprint,
				array(
					'before'     => $before_fingerprint,
					'after'      => $after_fingerprint,
					'difference' => $before_fingerprint === $after_fingerprint
						? null
						: self::first_difference( $before_fingerprint, $after_fingerprint ),
				)
			);
		}

		return $rows;
	}

	private static function load_endpoint_classes(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach (
			array(
				'wp-includes/rest-api/endpoints/class-wp-rest-autosaves-controller.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-templates-controller.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-global-styles-controller.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-template-revisions-controller.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-template-autosaves-controller.php',
				'wp-includes/class-wp-navigation-fallback.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-navigation-fallback-controller.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-edit-site-export-controller.php',
			) as $file
		) {
			$path = ABSPATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Block_Template',
				'WP_Error',
				'WP_Http',
				'WP_Post',
				'WP_REST_Autosaves_Controller',
				'WP_REST_Controller',
				'WP_REST_Edit_Site_Export_Controller',
				'WP_REST_Global_Styles_Controller',
				'WP_REST_Navigation_Fallback_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Revisions_Controller',
				'WP_REST_Server',
				'WP_REST_Template_Autosaves_Controller',
				'WP_REST_Template_Revisions_Controller',
				'WP_REST_Templates_Controller',
				'WP_Rewrite',
				'WP_Theme_JSON',
				'WP_Theme_JSON_Resolver',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_build_block_template_result_from_post',
				'add_action',
				'add_filter',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'get_block_template',
				'get_block_templates',
				'get_post',
				'get_post_meta',
				'get_post_type_object',
				'get_stylesheet',
				'get_template_hierarchy',
				'get_the_terms',
				'is_wp_error',
				'post_type_supports',
				'register_rest_route',
				'remove_action',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_ensure_response',
				'rest_get_route_for_post',
				'rest_sanitize_value_from_schema',
				'rest_url',
				'rest_validate_value_from_schema',
				'resolve_block_template',
				'sanitize_title',
				'wp_cache_flush',
				'wp_clean_theme_json_cache',
				'wp_delete_post',
				'wp_generate_block_templates_export_file',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_json_encode',
				'wp_set_current_user',
				'wp_set_object_terms',
				'wp_trash_post',
				'wp_update_post',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_route_registration_and_normalization(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		$failures = array();

		$global_styles_controller = new \WP_REST_Global_Styles_Controller();
		$templates_controller     = new \WP_REST_Templates_Controller( 'wp_template' );
		$template_part_controller = new \WP_REST_Templates_Controller( 'wp_template_part' );
		$revisions_controller     = new \WP_REST_Template_Revisions_Controller( 'wp_template' );
		$autosaves_controller     = new \WP_REST_Template_Autosaves_Controller( 'wp_template' );
		$navigation_controller    = new \WP_REST_Navigation_Fallback_Controller();
		$export_controller        = new \WP_REST_Edit_Site_Export_Controller();

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();

		$global_styles_controller->register_routes();
		$templates_controller->register_routes();
		$template_part_controller->register_routes();
		$revisions_controller->register_routes();
		$autosaves_controller->register_routes();
		$navigation_controller->register_routes();
		$export_controller->register_routes();

		$routes = $GLOBALS['wp_rest_server']->get_routes();
		$keys   = array_keys( $routes );

		self::collect_failure(
			$failures,
			self::route_exists( $keys, '/wp/v2/global-styles/themes/(?P<stylesheet>' )
				&& self::route_exists( $keys, '/wp/v2/global-styles/(?P<id>' )
				&& self::route_exists( $keys, '/wp/v2/templates' )
				&& self::route_exists( $keys, '/wp/v2/template-parts' )
				&& self::route_exists( $keys, '/wp/v2/templates/(?P<parent>' )
				&& self::route_exists( $keys, '/wp/v2/templates/(?P<id>' )
				&& self::route_exists( $keys, '/wp-block-editor/v1/navigation-fallback' )
				&& self::route_exists( $keys, '/wp-block-editor/v1/export' ),
			'Site Editor controller routes register expected route families',
			array( 'routes' => self::matching_routes( $keys ) )
		);

		$encoded_theme     = rawurlencode( $case['themeSlug'] . ' child' );
		$encoded_template  = rawurlencode( $case['themeSlug'] ) . '/' . rawurlencode( $case['templateSlug'] );
		$sanitized_theme   = $global_styles_controller->_sanitize_global_styles_callback( $encoded_theme );
		$sanitized_id      = $templates_controller->_sanitize_template_id( $encoded_template );
		$already_sanitized = $templates_controller->_sanitize_template_id( $case['templateId'] );
		$no_slash          = $templates_controller->_sanitize_template_id( $case['templateSlug'] );

		self::collect_failure(
			$failures,
			$case['themeSlug'] . ' child' === $sanitized_theme
				&& $case['templateId'] === $sanitized_id
				&& $case['templateId'] === $already_sanitized
				&& $case['templateSlug'] === $no_slash,
			'global styles and template route sanitizers normalize encoded IDs',
			array(
				'encodedTheme'     => $encoded_theme,
				'sanitizedTheme'   => $sanitized_theme,
				'encodedTemplate'  => $encoded_template,
				'sanitizedId'      => $sanitized_id,
				'alreadySanitized' => $already_sanitized,
				'noSlash'          => $no_slash,
			)
		);

		$template_params = $templates_controller->get_collection_params();
		$template_schema = $templates_controller->get_item_schema();
		$global_schema   = $global_styles_controller->get_item_schema();
		$nav_schema      = $navigation_controller->get_item_schema();

		$slug_validation = \rest_validate_value_from_schema(
			'',
			$template_schema['properties']['slug'],
			'slug'
		);
		$nav_id          = \rest_sanitize_value_from_schema(
			(string) $case['navigationId'],
			$nav_schema['properties']['id'],
			'id'
		);

		self::collect_failure(
			$failures,
			self::context_param_ok( $template_params )
				&& isset( $template_params['wp_id'], $template_params['area'], $template_params['post_type'] )
				&& isset( $template_schema['properties']['content']['properties']['raw'] )
				&& isset( $template_schema['properties']['modified'] )
				&& isset( $global_schema['properties']['styles'], $global_schema['properties']['settings'] )
				&& array( 'object', 'string' ) === $global_schema['properties']['title']['type']
				&& $slug_validation instanceof \WP_Error
				&& 'rest_too_short' === $slug_validation->get_error_code()
				&& $case['navigationId'] === $nav_id,
			'Site Editor schemas expose expected context, fields, and validation contracts',
			array(
				'templateParams' => self::param_summary( $template_params ),
				'slugValidation' => $slug_validation,
				'navId'          => $nav_id,
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.routes-schema-normalization',
			$failures,
			array( 'case' => self::case_summary( $case ) )
		);
	}

	private static function check_global_styles_controller(
		\ComponentFuzz\FuzzContext $ctx,
		array $case,
		array $fixtures
	): array {
		$controller = new \WP_REST_Global_Styles_Controller();
		$failures   = array();

		$invalid_permissions = $controller->get_item_permissions_check(
			self::request(
				'GET',
				'/wp/v2/global-styles/0',
				array( 'context' => 'edit' ),
				array( 'id' => 0 )
			)
		);
		$edit_denied         = $controller->get_item_permissions_check(
			self::request(
				'GET',
				'/wp/v2/global-styles/' . $fixtures['globalStyles'],
				array( 'context' => 'edit' ),
				array( 'id' => $fixtures['globalStyles'] )
			)
		);
		$cap_filter          = self::install_cap_filter( array( 'read', 'read_post', 'edit_post', 'edit_theme_options' ) );
		try {
			$edit_allowed   = $controller->get_item_permissions_check(
				self::request(
					'GET',
					'/wp/v2/global-styles/' . $fixtures['globalStyles'],
					array( 'context' => 'edit' ),
					array( 'id' => $fixtures['globalStyles'] )
				)
			);
			$update_allowed = $controller->update_item_permissions_check(
				self::request(
					'PUT',
					'/wp/v2/global-styles/' . $fixtures['globalStyles'],
					array( 'context' => 'edit' ),
					array( 'id' => $fixtures['globalStyles'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$invalid_permissions instanceof \WP_Error
				&& 'rest_global_styles_not_found' === $invalid_permissions->get_error_code()
				&& $edit_denied instanceof \WP_Error
				&& 'rest_forbidden_context' === $edit_denied->get_error_code()
				&& true === $edit_allowed
				&& true === $update_allowed,
			'global styles item permissions distinguish invalid IDs, edit context, and update caps',
			array(
				'invalid' => $invalid_permissions,
				'denied'  => $edit_denied,
				'allowed' => $edit_allowed,
				'update'  => $update_allowed,
			)
		);

		$valid_css   = $case['validCustomCss'];
		$invalid_css = $case['invalidCustomCss'];
		$valid_check = self::invoke_method( $controller, 'validate_custom_css', array( $valid_css ) );
		$bad_check   = self::invoke_method( $controller, 'validate_custom_css', array( $invalid_css ) );
		$prepared    = self::invoke_method(
			$controller,
			'prepare_item_for_database',
			array(
				self::request(
					'PUT',
					'/wp/v2/global-styles/' . $fixtures['globalStyles'],
					array(),
					array( 'id' => $fixtures['globalStyles'] ),
					array(
						'styles'   => array(
							'css'   => $valid_css,
							'color' => array(
								'text' => $case['textColor'],
							),
						),
						'settings' => array(
							'color' => array(
								'custom' => true,
							),
						),
						'title'    => array(
							'raw' => $case['globalStylesTitleUpdated'],
						),
					)
				),
			)
		);
		$bad_prepare = self::invoke_method(
			$controller,
			'prepare_item_for_database',
			array(
				self::request(
					'PUT',
					'/wp/v2/global-styles/' . $fixtures['globalStyles'],
					array(),
					array( 'id' => $fixtures['globalStyles'] ),
					array(
						'styles' => array(
							'css' => $invalid_css,
						),
					)
				),
			)
		);

		$prepared_config = $prepared instanceof \stdClass && isset( $prepared->post_content )
			? json_decode( $prepared->post_content, true )
			: null;

		self::collect_failure(
			$failures,
			true === $valid_check
				&& $bad_check instanceof \WP_Error
				&& 'rest_custom_css_illegal_markup' === $bad_check->get_error_code()
				&& 400 === (int) ( $bad_check->get_error_data()['status'] ?? 0 )
				&& $bad_prepare instanceof \WP_Error
				&& 'rest_custom_css_illegal_markup' === $bad_prepare->get_error_code()
				&& $prepared instanceof \stdClass
				&& $fixtures['globalStyles'] === (int) $prepared->ID
				&& $case['globalStylesTitleUpdated'] === ( $prepared->post_title ?? null )
				&& is_array( $prepared_config )
				&& true === ( $prepared_config['isGlobalStylesUserThemeJSON'] ?? false )
				&& \WP_Theme_JSON::LATEST_SCHEMA === ( $prepared_config['version'] ?? null )
				&& $valid_css === ( $prepared_config['styles']['css'] ?? null )
				&& $case['textColor'] === ( $prepared_config['styles']['color']['text'] ?? null ),
			'global styles prepare validates custom CSS and emits user theme.json post_content',
			array(
				'validCss'       => $valid_css,
				'invalidCss'     => $invalid_css,
				'badCheck'       => $bad_check,
				'badPrepare'     => $bad_prepare,
				'preparedConfig' => $prepared_config,
			)
		);

		$response_request = self::request(
			'GET',
			'/wp/v2/global-styles/' . $fixtures['globalStyles'],
			array(
				'context' => 'edit',
				'_fields' => 'id,title,settings,styles,_links',
			),
			array( 'id' => $fixtures['globalStyles'] )
		);
		$response         = $controller->prepare_item_for_response(
			\get_post( $fixtures['globalStyles'] ),
			$response_request
		);
		$data             = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$links            = $response instanceof \WP_REST_Response ? $response->get_links() : array();

		self::collect_failure(
			$failures,
			$response instanceof \WP_REST_Response
				&& $fixtures['globalStyles'] === (int) ( $data['id'] ?? 0 )
				&& $case['globalStylesTitle'] === ( $data['title']['raw'] ?? null )
				&& $case['existingCustomCss'] === ( $data['styles']['css'] ?? null )
				&& isset( $data['settings'], $links['self'], $links['about'], $links['version-history'] )
				&& self::link_href( $links, 'self' ) === \rest_url( 'wp/v2/global-styles/' . $fixtures['globalStyles'] )
				&& isset( $links['version-history'][0]['attributes']['count'] ),
			'global styles response respects context, _fields, links, and revision link shape',
			array(
				'data'  => $data,
				'links' => $links,
			)
		);

		$theme_denied  = $controller->get_theme_item_permissions_check(
			self::request(
				'GET',
				'/wp/v2/global-styles/themes/' . rawurlencode( $case['themeSlug'] ),
				array( 'context' => 'view' ),
				array( 'stylesheet' => $case['themeSlug'] )
			)
		);
		$cap_filter    = self::install_cap_filter( array( 'edit_posts' ) );
		try {
			$theme_allowed = $controller->get_theme_item_permissions_check(
				self::request(
					'GET',
					'/wp/v2/global-styles/themes/' . rawurlencode( $case['themeSlug'] ),
					array( 'context' => 'view' ),
					array( 'stylesheet' => $case['themeSlug'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$wrong_theme = $controller->get_theme_item(
			self::request(
				'GET',
				'/wp/v2/global-styles/themes/not-active',
				array( 'context' => 'view' ),
				array( 'stylesheet' => $case['themeSlug'] . '-missing' )
			)
		);

		$theme_item = $controller->get_theme_item(
			self::request(
				'GET',
				'/wp/v2/global-styles/themes/' . rawurlencode( $case['themeSlug'] ),
				array(
					'context' => 'view',
					'_fields' => 'settings,styles,_links',
				),
				array( 'stylesheet' => $case['themeSlug'] )
			)
		);
		$theme_data = $theme_item instanceof \WP_REST_Response ? $theme_item->get_data() : array();

		self::collect_failure(
			$failures,
			$theme_denied instanceof \WP_Error
				&& 'rest_cannot_read_global_styles' === $theme_denied->get_error_code()
				&& true === $theme_allowed
				&& $wrong_theme instanceof \WP_Error
				&& 'rest_theme_not_found' === $wrong_theme->get_error_code()
				&& $theme_item instanceof \WP_REST_Response
				&& isset( $theme_data['settings'], $theme_data['styles'] ),
			'global styles theme endpoints gate active-theme reads and expose bounded theme data',
			array(
				'denied'     => $theme_denied,
				'wrongTheme' => $wrong_theme,
				'themeData'  => $theme_data,
			)
		);

		$variations = $controller->get_theme_items(
			self::request(
				'GET',
				'/wp/v2/global-styles/themes/' . rawurlencode( $case['themeSlug'] ) . '/variations',
				array( 'context' => 'view' ),
				array( 'stylesheet' => $case['themeSlug'] )
			)
		);
		$variation_data = $variations instanceof \WP_REST_Response ? $variations->get_data() : array();

		self::collect_failure(
			$failures,
			$variations instanceof \WP_REST_Response
				&& is_array( $variation_data )
				&& self::variation_present( $variation_data, $case['variationTitle'] ),
			'global styles variations are loaded from the temp theme fixture only',
			array(
				'variationTitle' => $case['variationTitle'],
				'variationCount' => is_array( $variation_data ) ? count( $variation_data ) : null,
				'variations'     => $variation_data,
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.global-styles',
			$failures,
			array(
				'case'             => self::case_summary( $case ),
				'globalStylesPost' => $fixtures['globalStyles'],
			)
		);
	}

	private static function check_global_styles_revisions(
		\ComponentFuzz\FuzzContext $ctx,
		array $case,
		array $fixtures
	): array {
		$controller = new \WP_REST_Revisions_Controller( 'wp_global_styles' );
		$failures   = array();
		$schema     = $controller->get_item_schema();
		$request    = self::request(
			'GET',
			'/wp/v2/global-styles/' . $fixtures['globalStyles'] . '/revisions/' . $fixtures['globalStylesRevision'],
			array(
				'context' => 'edit',
				'_fields' => 'id,parent,title,_links',
			),
			array(
				'parent' => $fixtures['globalStyles'],
				'id'     => $fixtures['globalStylesRevision'],
			)
		);
		$response   = $controller->prepare_item_for_response(
			\get_post( $fixtures['globalStylesRevision'] ),
			$request
		);
		$data       = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$links      = $response instanceof \WP_REST_Response ? $response->get_links() : array();

		self::collect_failure(
			$failures,
			isset( $schema['properties']['parent'], $schema['properties']['title'] )
				&& $response instanceof \WP_REST_Response
				&& $fixtures['globalStylesRevision'] === (int) ( $data['id'] ?? 0 )
				&& $fixtures['globalStyles'] === (int) ( $data['parent'] ?? 0 )
				&& $case['globalStylesRevisionTitle'] === ( $data['title']['raw'] ?? null )
				&& self::link_href( $links, 'parent' ) === \rest_url( 'wp/v2/global-styles/' . $fixtures['globalStyles'] ),
			'global styles revisions expose generic revision schema, parent relation, and response shape',
			array(
				'data'  => $data,
				'links' => $links,
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.global-styles-revisions',
			$failures,
			array( 'revision' => $fixtures['globalStylesRevision'] )
		);
	}

	private static function check_templates_controller(
		\ComponentFuzz\FuzzContext $ctx,
		array $case,
		array $fixtures
	): array {
		$controller      = new \WP_REST_Templates_Controller( 'wp_template' );
		$part_controller = new \WP_REST_Templates_Controller( 'wp_template_part' );
		$failures        = array();

		$read_denied = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/templates' ) );
		$cap_filter  = self::install_cap_filter( array( 'edit_posts' ) );
		try {
			$read_allowed = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/templates' ) );
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$write_denied = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/templates' ) );
		$cap_filter   = self::install_cap_filter( array( 'edit_theme_options' ) );
		try {
			$write_allowed = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/templates' ) );
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$read_denied instanceof \WP_Error
				&& 'rest_cannot_manage_templates' === $read_denied->get_error_code()
				&& true === $read_allowed
				&& $write_denied instanceof \WP_Error
				&& 'rest_cannot_manage_templates' === $write_denied->get_error_code()
				&& true === $write_allowed,
			'template permissions distinguish read and management capabilities',
			array(
				'readDenied'   => $read_denied,
				'readAllowed'  => $read_allowed,
				'writeDenied'  => $write_denied,
				'writeAllowed' => $write_allowed,
			)
		);

		$head = $controller->get_items( self::request( 'HEAD', '/wp/v2/templates' ) );
		self::collect_failure(
			$failures,
			$head instanceof \WP_REST_Response && array() === $head->get_data(),
			'template collection HEAD returns an empty response without querying templates',
			array( 'head' => $head )
		);

		$new_template_request = self::request(
			'POST',
			'/wp/v2/templates',
			array(),
			array(),
			array(
				'slug'        => $case['createdTemplateSlug'],
				'theme'       => $case['themeSlug'],
				'content'     => array( 'raw' => $case['createdTemplateContent'] ),
				'title'       => $case['createdTemplateTitle'],
				'description' => $case['createdTemplateDescription'],
				'author'      => $fixtures['author'],
			)
		);
		$prepared_new         = self::invoke_method(
			$controller,
			'prepare_item_for_database',
			array( $new_template_request )
		);
		$invalid_author       = self::invoke_method(
			$controller,
			'prepare_item_for_database',
			array(
				self::request(
					'POST',
					'/wp/v2/templates',
					array(),
					array(),
					array(
						'slug'   => $case['createdTemplateSlug'],
						'author' => 987654321,
					)
				),
			)
		);

		self::collect_failure(
			$failures,
			$prepared_new instanceof \stdClass
				&& 'wp_template' === ( $prepared_new->post_type ?? null )
				&& 'publish' === ( $prepared_new->post_status ?? null )
				&& $case['themeSlug'] === ( $prepared_new->tax_input['wp_theme'] ?? null )
				&& $case['createdTemplateContent'] === ( $prepared_new->post_content ?? null )
				&& $case['createdTemplateTitle'] === ( $prepared_new->post_title ?? null )
				&& $case['createdTemplateDescription'] === ( $prepared_new->post_excerpt ?? null )
				&& $fixtures['author'] === (int) ( $prepared_new->post_author ?? 0 )
				&& $invalid_author instanceof \WP_Error
				&& 'rest_invalid_author' === $invalid_author->get_error_code(),
			'template prepare normalizes create payloads and rejects invalid authors',
			array(
				'prepared'      => $prepared_new,
				'invalidAuthor' => $invalid_author,
			)
		);

		$template = self::template_object(
			$case,
			array(
				'wp_id'   => $fixtures['template'],
				'author'  => $fixtures['author'],
				'content' => $case['templateContent'],
			)
		);
		$filter   = self::install_template_filter( $case['templateId'], 'wp_template', $template );
		try {
			$response = $controller->prepare_item_for_response(
				$template,
				self::request(
					'GET',
					'/wp/v2/templates/' . $case['templateId'],
					array(
						'context' => 'edit',
						'_fields' => 'id,slug,theme,content,title,status,wp_id,source,origin,description,'
							. 'has_theme_file,is_custom,author,modified,original_source,_links',
					),
					array( 'id' => $case['templateId'] )
				)
			);
		} finally {
			\remove_filter( 'pre_get_block_template', $filter, 10 );
		}

		$data  = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$links = $response instanceof \WP_REST_Response ? $response->get_links() : array();

		self::collect_failure(
			$failures,
			$response instanceof \WP_REST_Response
				&& $case['templateId'] === ( $data['id'] ?? null )
				&& $case['templateSlug'] === ( $data['slug'] ?? null )
				&& $case['themeSlug'] === ( $data['theme'] ?? null )
				&& $case['templateContent'] === ( $data['content']['raw'] ?? null )
				&& 1 === (int) ( $data['content']['block_version'] ?? 0 )
				&& $case['templateTitle'] === ( $data['title']['raw'] ?? null )
				&& $fixtures['template'] === (int) ( $data['wp_id'] ?? 0 )
				&& 'user' === ( $data['original_source'] ?? null )
				&& self::link_href( $links, 'self' ) === \rest_url( 'wp/v2/templates/' . $case['templateId'] )
				&& isset( $links['collection'], $links['about'] ),
			'template response exposes context fields, block version, original source, and links',
			array(
				'data'  => $data,
				'links' => $links,
			)
		);

		$part = self::template_object(
			$case,
			array(
				'id'      => $case['templatePartId'],
				'slug'    => $case['templatePartSlug'],
				'type'    => 'wp_template_part',
				'title'   => $case['templatePartTitle'],
				'area'    => 'header',
				'wp_id'   => $fixtures['templatePart'],
				'content' => $case['templatePartContent'],
			)
		);
		$part_response = $part_controller->prepare_item_for_response(
			$part,
			self::request(
				'GET',
				'/wp/v2/template-parts/' . $case['templatePartId'],
				array(
					'context' => 'edit',
					'_fields' => 'id,slug,type,area,title,content,wp_id',
				),
				array( 'id' => $case['templatePartId'] )
			)
		);
		$part_data     = $part_response instanceof \WP_REST_Response ? $part_response->get_data() : array();

		self::collect_failure(
			$failures,
			$part_response instanceof \WP_REST_Response
				&& $case['templatePartId'] === ( $part_data['id'] ?? null )
				&& 'wp_template_part' === ( $part_data['type'] ?? null )
				&& 'header' === ( $part_data['area'] ?? null )
				&& $case['templatePartContent'] === ( $part_data['content']['raw'] ?? null ),
			'template part response includes area-specific fields',
			array( 'partData' => $part_data )
		);

		return self::result(
			$ctx,
			'rest-site-editor.templates',
			$failures,
			array(
				'templateId'     => $case['templateId'],
				'templatePartId' => $case['templatePartId'],
			)
		);
	}

	private static function check_template_mutation_lifecycle(
		\ComponentFuzz\FuzzContext $ctx,
		array $case,
		array $fixtures
	): array {
		$controller      = new \WP_REST_Templates_Controller( 'wp_template' );
		$part_controller = new \WP_REST_Templates_Controller( 'wp_template_part' );
		$failures        = array();

		$created_slug        = 'mutated-' . substr( $case['token'], 0, 6 );
		$created_id          = $case['themeSlug'] . '//' . $created_slug;
		$created_content     = self::paragraph_block( 'Mutated created template ' . $case['token'] );
		$created_title       = 'Mutated Created Template ' . substr( $case['token'], 0, 5 );
		$created_description = 'Mutated created template description ' . $case['token'];

		$part_slug    = 'mutated-part-' . substr( $case['token'], 0, 6 );
		$part_id      = $case['themeSlug'] . '//' . $part_slug;
		$part_content = '<!-- wp:group --><div class="wp-block-group">Mutated footer '
			. esc_html( $case['token'] )
			. '</div><!-- /wp:group -->';
		$part_title   = 'Mutated Footer Part ' . substr( $case['token'], 0, 5 );

		$updated_content     = self::paragraph_block( 'Updated custom template ' . $case['token'] );
		$updated_title       = 'Updated Custom Template ' . substr( $case['token'], 0, 5 );
		$updated_description = 'Updated custom template description ' . $case['token'];

		$theme_file_slug        = 'theme-file-' . substr( $case['token'], 0, 6 );
		$theme_file_id          = $case['themeSlug'] . '//' . $theme_file_slug;
		$theme_file_content     = self::paragraph_block( 'Theme file template ' . $case['token'] );
		$theme_file_title       = 'Theme File Template ' . substr( $case['token'], 0, 5 );
		$theme_file_description = 'Theme file template description ' . $case['token'];
		$promoted_content       = self::paragraph_block( 'Promoted theme file template ' . $case['token'] );
		$promoted_title         = 'Promoted Theme File Template ' . substr( $case['token'], 0, 5 );
		$promoted_description   = 'Promoted theme file template description ' . $case['token'];
		$theme_file_template    = self::template_object(
			$case,
			array(
				'id'             => $theme_file_id,
				'slug'           => $theme_file_slug,
				'content'        => $theme_file_content,
				'title'          => $theme_file_title,
				'description'    => $theme_file_description,
				'source'         => 'theme',
				'origin'         => 'theme',
				'wp_id'          => 0,
				'has_theme_file' => true,
				'is_custom'      => false,
				'author'         => 0,
			)
		);

		$template_insert_log = array();
		$part_insert_log     = array();
		$wp_after_log        = array();
		$theme_file_log      = array();

		$template_insert_hook = static function ( \WP_Post $post, \WP_REST_Request $request, bool $creating ) use ( &$template_insert_log ): void {
			$template_insert_log[] = array(
				'postId'    => (int) $post->ID,
				'postType'  => $post->post_type,
				'creating'  => $creating,
				'requestId' => $request['id'] ?? null,
				'source'    => $request['source'] ?? null,
				'context'   => $request['context'] ?? null,
			);
		};
		$part_insert_hook     = static function ( \WP_Post $post, \WP_REST_Request $request, bool $creating ) use ( &$part_insert_log ): void {
			$part_insert_log[] = array(
				'postId'    => (int) $post->ID,
				'postType'  => $post->post_type,
				'creating'  => $creating,
				'requestId' => $request['id'] ?? null,
				'area'      => $request['area'] ?? null,
				'context'   => $request['context'] ?? null,
			);
		};
		$wp_after_hook        = static function ( int $post_id, \WP_Post $post, bool $update, $post_before ) use ( &$wp_after_log ): void {
			$wp_after_log[] = array(
				'postId'       => $post_id,
				'postType'     => $post->post_type,
				'update'       => $update,
				'postBeforeId' => $post_before instanceof \WP_Post ? (int) $post_before->ID : null,
			);
		};
		$theme_file_filter    = static function ( $block_template, string $requested_id, string $requested_type ) use (
			$case,
			$theme_file_id,
			$theme_file_slug,
			$theme_file_template,
			&$theme_file_log
		) {
			if ( $theme_file_id !== $requested_id || 'wp_template' !== $requested_type ) {
				return $block_template;
			}

			$custom_post_id   = self::template_post_id_for_slug( $case['themeSlug'], $theme_file_slug, 'wp_template' );
			$theme_file_log[] = array(
				'id'           => $requested_id,
				'type'         => $requested_type,
				'customPostId' => $custom_post_id,
			);

			return 0 === $custom_post_id ? clone $theme_file_template : $block_template;
		};

		\wp_set_current_user( $fixtures['author'] );
		\add_action( 'rest_after_insert_wp_template', $template_insert_hook, 10, 3 );
		\add_action( 'rest_after_insert_wp_template_part', $part_insert_hook, 10, 3 );
		\add_action( 'wp_after_insert_post', $wp_after_hook, 10, 4 );
		\add_filter( 'pre_get_block_template', $theme_file_filter, 10, 3 );
		$cap_filter = self::install_cap_filter( array( 'edit_posts', 'edit_theme_options' ) );

		try {
			$create_template_response = $controller->create_item(
				self::request(
					'POST',
					'/wp/v2/templates',
					array(
						'context' => 'edit',
						'_fields' => 'id,slug,theme,type,source,origin,content,title,status,wp_id,description,author,_links',
					),
					array(),
					array(
						'slug'        => $created_slug,
						'theme'       => $case['themeSlug'],
						'content'     => array( 'raw' => $created_content ),
						'title'       => $created_title,
						'description' => $created_description,
						'author'      => $fixtures['author'],
					)
				)
			);
			$create_template_data     = $create_template_response instanceof \WP_REST_Response
				? $create_template_response->get_data()
				: array();
			$created_post_id          = (int) ( $create_template_data['wp_id'] ?? 0 );
			$created_post             = $created_post_id > 0 ? \get_post( $created_post_id ) : null;
			$created_theme_terms      = $created_post_id > 0 ? self::term_names( $created_post_id, 'wp_theme' ) : array();

			$create_part_response = $part_controller->create_item(
				self::request(
					'POST',
					'/wp/v2/template-parts',
					array(
						'context' => 'edit',
						'_fields' => 'id,slug,theme,type,source,content,title,status,wp_id,area,author,_links',
					),
					array(),
					array(
						'slug'    => $part_slug,
						'theme'   => $case['themeSlug'],
						'content' => $part_content,
						'title'   => array( 'raw' => $part_title ),
						'area'    => 'footer',
						'author'  => $fixtures['author'],
					)
				)
			);
			$create_part_data     = $create_part_response instanceof \WP_REST_Response
				? $create_part_response->get_data()
				: array();
			$created_part_post_id = (int) ( $create_part_data['wp_id'] ?? 0 );
			$created_part_post    = $created_part_post_id > 0 ? \get_post( $created_part_post_id ) : null;
			$part_theme_terms     = $created_part_post_id > 0 ? self::term_names( $created_part_post_id, 'wp_theme' ) : array();
			$part_area_terms      = $created_part_post_id > 0 ? self::term_names( $created_part_post_id, 'wp_template_part_area' ) : array();

			$update_response = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/templates/' . $case['templateId'],
					array(
						'context' => 'edit',
						'_fields' => 'id,slug,theme,source,content,title,status,wp_id,description,author',
					),
					array( 'id' => $case['templateId'] ),
					array(
						'content'     => array( 'raw' => $updated_content ),
						'title'       => array( 'raw' => $updated_title ),
						'description' => $updated_description,
						'author'      => $fixtures['author'],
					)
				)
			);
			$update_data     = $update_response instanceof \WP_REST_Response ? $update_response->get_data() : array();
			$updated_post    = \get_post( $fixtures['template'] );

			$theme_delete_response = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/templates/' . $theme_file_id,
					array(),
					array( 'id' => $theme_file_id ),
					array( 'force' => true )
				)
			);

			$promote_response = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/templates/' . $theme_file_id,
					array(
						'context' => 'edit',
						'_fields' => 'id,slug,theme,source,origin,original_source,content,title,status,wp_id,description,has_theme_file,is_custom,author',
					),
					array( 'id' => $theme_file_id ),
					array(
						'content'     => $promoted_content,
						'title'       => $promoted_title,
						'description' => $promoted_description,
						'author'      => $fixtures['author'],
					)
				)
			);
			$promote_data     = $promote_response instanceof \WP_REST_Response ? $promote_response->get_data() : array();
			$promoted_post_id = (int) ( $promote_data['wp_id'] ?? 0 );
			$promoted_post    = $promoted_post_id > 0 ? \get_post( $promoted_post_id ) : null;
			$promoted_origin  = $promoted_post_id > 0 ? \get_post_meta( $promoted_post_id, 'origin', true ) : null;
			$promoted_terms   = $promoted_post_id > 0 ? self::term_names( $promoted_post_id, 'wp_theme' ) : array();

			$reset_response = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/templates/' . $theme_file_id,
					array(
						'context' => 'edit',
						'_fields' => 'id,slug,theme,source,content,title,status,wp_id,has_theme_file,is_custom',
					),
					array( 'id' => $theme_file_id ),
					array( 'source' => 'theme' )
				)
			);
			$reset_data     = $reset_response instanceof \WP_REST_Response ? $reset_response->get_data() : array();
			$post_after_reset = $promoted_post_id > 0 ? \get_post( $promoted_post_id ) : null;

			$trash_response = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/templates/' . $created_id,
					array(),
					array( 'id' => $created_id ),
					array( 'force' => false )
				)
			);
			$trash_data     = $trash_response instanceof \WP_REST_Response ? $trash_response->get_data() : array();
			$trashed_post   = $created_post_id > 0 ? \get_post( $created_post_id ) : null;
			$trashed_id     = $case['themeSlug'] . '//' . $created_slug . '__trashed';

			$already_trashed = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/templates/' . $trashed_id,
					array(),
					array( 'id' => $trashed_id ),
					array( 'force' => false )
				)
			);

			$force_part_response = $part_controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/template-parts/' . $part_id,
					array(),
					array( 'id' => $part_id ),
					array( 'force' => true )
				)
			);
			$force_part_data     = $force_part_response instanceof \WP_REST_Response
				? $force_part_response->get_data()
				: array();
			$part_after_delete   = $created_part_post_id > 0 ? \get_post( $created_part_post_id ) : null;
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'pre_get_block_template', $theme_file_filter, 10 );
			\remove_action( 'wp_after_insert_post', $wp_after_hook, 10 );
			\remove_action( 'rest_after_insert_wp_template_part', $part_insert_hook, 10 );
			\remove_action( 'rest_after_insert_wp_template', $template_insert_hook, 10 );
		}

		self::collect_failure(
			$failures,
			$create_template_response instanceof \WP_REST_Response
				&& 201 === $create_template_response->get_status()
				&& self::response_header( $create_template_response, 'Location' ) === \rest_url( 'wp/v2/templates/' . $created_id )
				&& $created_post instanceof \WP_Post
				&& 'wp_template' === $created_post->post_type
				&& 'publish' === $created_post->post_status
				&& $created_slug === $created_post->post_name
				&& $created_content === $created_post->post_content
				&& $created_title === $created_post->post_title
				&& $created_description === $created_post->post_excerpt
				&& $fixtures['author'] === (int) $created_post->post_author
				&& in_array( $case['themeSlug'], $created_theme_terms, true )
				&& $created_id === ( $create_template_data['id'] ?? null )
				&& 'custom' === ( $create_template_data['source'] ?? null )
				&& $created_content === ( $create_template_data['content']['raw'] ?? null )
				&& $created_title === ( $create_template_data['title']['raw'] ?? null )
				&& $fixtures['author'] === (int) ( $create_template_data['author'] ?? 0 )
				&& self::hook_log_contains( $template_insert_log, $created_post_id, 'creating', true )
				&& self::hook_log_contains( $wp_after_log, $created_post_id, 'update', false ),
			'template create_item persists a custom template, assigns theme taxonomy, emits 201 Location, and fires insert hooks',
			array(
				'response'   => $create_template_response,
				'data'       => $create_template_data,
				'post'       => self::describe_post( $created_post ),
				'themeTerms' => $created_theme_terms,
			)
		);

		self::collect_failure(
			$failures,
			$create_part_response instanceof \WP_REST_Response
				&& 201 === $create_part_response->get_status()
				&& self::response_header( $create_part_response, 'Location' ) === \rest_url( 'wp/v2/template-parts/' . $part_id )
				&& $created_part_post instanceof \WP_Post
				&& 'wp_template_part' === $created_part_post->post_type
				&& 'publish' === $created_part_post->post_status
				&& $part_slug === $created_part_post->post_name
				&& $part_content === $created_part_post->post_content
				&& $part_title === $created_part_post->post_title
				&& in_array( $case['themeSlug'], $part_theme_terms, true )
				&& in_array( 'footer', $part_area_terms, true )
				&& $part_id === ( $create_part_data['id'] ?? null )
				&& 'wp_template_part' === ( $create_part_data['type'] ?? null )
				&& 'footer' === ( $create_part_data['area'] ?? null )
				&& $part_content === ( $create_part_data['content']['raw'] ?? null )
				&& self::hook_log_contains( $part_insert_log, $created_part_post_id, 'creating', true )
				&& self::hook_log_contains( $wp_after_log, $created_part_post_id, 'update', false ),
			'template-part create_item persists area taxonomy, response shape, and insert hooks',
			array(
				'response'  => $create_part_response,
				'data'      => $create_part_data,
				'post'      => self::describe_post( $created_part_post ),
				'themeTerms' => $part_theme_terms,
				'areaTerms' => $part_area_terms,
			)
		);

		self::collect_failure(
			$failures,
			$update_response instanceof \WP_REST_Response
				&& 200 === $update_response->get_status()
				&& $updated_post instanceof \WP_Post
				&& $fixtures['template'] === (int) ( $update_data['wp_id'] ?? 0 )
				&& $case['templateId'] === ( $update_data['id'] ?? null )
				&& $updated_content === $updated_post->post_content
				&& $updated_title === $updated_post->post_title
				&& $updated_description === $updated_post->post_excerpt
				&& $updated_content === ( $update_data['content']['raw'] ?? null )
				&& $updated_title === ( $update_data['title']['raw'] ?? null )
				&& self::hook_log_contains( $template_insert_log, $fixtures['template'], 'creating', false )
				&& self::hook_log_contains( $wp_after_log, $fixtures['template'], 'update', true ),
			'template update_item mutates the existing custom post in place and exposes edit-context response data',
			array(
				'response' => $update_response,
				'data'     => $update_data,
				'post'     => self::describe_post( $updated_post ),
			)
		);

		self::collect_failure(
			$failures,
			$theme_delete_response instanceof \WP_Error
				&& 'rest_invalid_template' === $theme_delete_response->get_error_code()
				&& $promote_response instanceof \WP_REST_Response
				&& 200 === $promote_response->get_status()
				&& $promoted_post instanceof \WP_Post
				&& 'wp_template' === $promoted_post->post_type
				&& $theme_file_slug === $promoted_post->post_name
				&& $promoted_content === $promoted_post->post_content
				&& $promoted_title === $promoted_post->post_title
				&& $promoted_description === $promoted_post->post_excerpt
				&& 'theme' === $promoted_origin
				&& in_array( $case['themeSlug'], $promoted_terms, true )
				&& $theme_file_id === ( $promote_data['id'] ?? null )
				&& 'custom' === ( $promote_data['source'] ?? null )
				&& 'theme' === ( $promote_data['origin'] ?? null )
				&& 'user' === ( $promote_data['original_source'] ?? null )
				&& false === ( $promote_data['has_theme_file'] ?? null )
				&& true === ( $promote_data['is_custom'] ?? null )
				&& self::hook_log_contains( $template_insert_log, $promoted_post_id, 'creating', false )
				&& self::hook_log_contains( $wp_after_log, $promoted_post_id, 'update', false ),
			'theme-file templates reject delete, then update_item promotes them to custom posts with origin metadata',
			array(
				'deleteError' => $theme_delete_response,
				'response'    => $promote_response,
				'data'        => $promote_data,
				'post'        => self::describe_post( $promoted_post ),
				'origin'      => $promoted_origin,
				'themeTerms'  => $promoted_terms,
				'filterLog'   => $theme_file_log,
			)
		);

		self::collect_failure(
			$failures,
			$reset_response instanceof \WP_REST_Response
				&& 200 === $reset_response->get_status()
				&& ! ( $post_after_reset instanceof \WP_Post )
				&& $theme_file_id === ( $reset_data['id'] ?? null )
				&& 'theme' === ( $reset_data['source'] ?? null )
				&& $theme_file_content === ( $reset_data['content']['raw'] ?? null )
				&& $theme_file_title === ( $reset_data['title']['raw'] ?? null )
				&& 0 === (int) ( $reset_data['wp_id'] ?? -1 )
				&& true === ( $reset_data['has_theme_file'] ?? null )
				&& false === ( $reset_data['is_custom'] ?? null ),
			'update_item with source=theme deletes the custom override and returns the theme-file template',
			array(
				'response'       => $reset_response,
				'data'           => $reset_data,
				'postAfterReset' => self::describe_post( $post_after_reset ),
				'filterLog'      => $theme_file_log,
			)
		);

		self::collect_failure(
			$failures,
			$trash_response instanceof \WP_REST_Response
				&& $trashed_post instanceof \WP_Post
				&& 'trash' === $trashed_post->post_status
				&& $created_slug . '__trashed' === $trashed_post->post_name
				&& $created_slug === \get_post_meta( $created_post_id, '_wp_desired_post_slug', true )
				&& 'trash' === ( $trash_data['status'] ?? null )
				&& $already_trashed instanceof \WP_Error
				&& 'rest_template_already_trashed' === $already_trashed->get_error_code()
				&& 410 === (int) ( $already_trashed->get_error_data()['status'] ?? 0 )
				&& $force_part_response instanceof \WP_REST_Response
				&& true === ( $force_part_data['deleted'] ?? null )
				&& $created_part_post_id === (int) ( $force_part_data['previous']['wp_id'] ?? 0 )
				&& ! ( $part_after_delete instanceof \WP_Post ),
			'delete_item trashes custom templates, reports already-trashed state, and force-deletes template parts with previous data',
			array(
				'trashResponse'     => $trash_response,
				'trashData'         => $trash_data,
				'alreadyTrashed'    => $already_trashed,
				'alreadyTrashedId'  => $trashed_id,
				'forcePartResponse' => $force_part_response,
				'forcePartData'     => $force_part_data,
			)
		);

		self::collect_failure(
			$failures,
			count( $template_insert_log ) >= 3
				&& 1 === count( $part_insert_log )
				&& false === \has_filter( 'rest_after_insert_wp_template', $template_insert_hook )
				&& false === \has_filter( 'rest_after_insert_wp_template_part', $part_insert_hook )
				&& false === \has_filter( 'wp_after_insert_post', $wp_after_hook )
				&& false === \has_filter( 'pre_get_block_template', $theme_file_filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'template mutation lifecycle uses bounded hooks and removes every temporary filter',
			array(
				'templateInsertLog' => $template_insert_log,
				'partInsertLog'     => $part_insert_log,
				'wpAfterLog'        => $wp_after_log,
				'themeFileLog'      => $theme_file_log,
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.template-mutation-lifecycle',
			$failures,
			array(
				'createdId'        => $created_id,
				'partId'           => $part_id,
				'themeFileId'      => $theme_file_id,
				'templateHooks'    => count( $template_insert_log ),
				'partHooks'        => count( $part_insert_log ),
				'wpAfterHooks'     => count( $wp_after_log ),
				'themeFileLookups' => count( $theme_file_log ),
			)
		);
	}

	private static function check_template_item_route_dispatch(
		\ComponentFuzz\FuzzContext $ctx,
		array $case,
		array $fixtures
	): array {
		$failures        = array();
		$previous_server = $GLOBALS['wp_rest_server'] ?? null;
		$server_existed  = array_key_exists( 'wp_rest_server', $GLOBALS );
		$server          = new \WP_REST_Server();
		$template        = self::template_object(
			$case,
			array(
				'wp_id'   => $fixtures['template'],
				'author'  => $fixtures['author'],
				'content' => $case['templateContent'],
			)
		);
		$template_part   = self::template_object(
			$case,
			array(
				'id'      => $case['templatePartId'],
				'slug'    => $case['templatePartSlug'],
				'type'    => 'wp_template_part',
				'title'   => $case['templatePartTitle'],
				'area'    => 'header',
				'wp_id'   => $fixtures['templatePart'],
				'content' => $case['templatePartContent'],
			)
		);
		$filter_log      = array();
		$filter          = static function ( $block_template, $requested_id, $requested_type ) use (
			$template,
			$template_part,
			&$filter_log
		) {
			$filter_log[] = array(
				'id'   => $requested_id,
				'type' => $requested_type,
			);

			if ( $requested_id === $template->id && 'wp_template' === $requested_type ) {
				return clone $template;
			}
			if ( $requested_id === $template_part->id && 'wp_template_part' === $requested_type ) {
				return clone $template_part;
			}

			return $block_template;
		};

		$template_route = '/wp/v2/templates/' . rawurlencode( $case['themeSlug'] ) . '/' . rawurlencode( $case['templateSlug'] );
		$part_route     = '/wp/v2/template-parts/' . rawurlencode( $case['themeSlug'] ) . '/' . rawurlencode( $case['templatePartSlug'] );

		try {
			$GLOBALS['wp_rest_server'] = $server;
			( new \WP_REST_Templates_Controller( 'wp_template' ) )->register_routes();
			( new \WP_REST_Templates_Controller( 'wp_template_part' ) )->register_routes();

			\add_filter( 'pre_get_block_template', $filter, 10, 3 );
			$denied_response = $server->dispatch(
				self::request(
					'GET',
					$template_route,
					array(
						'context' => 'edit',
						'_fields' => 'id',
					)
				)
			);
			$denied_filter_log = $filter_log;

			$cap_filter = self::install_cap_filter( array( 'edit_posts' ) );
			try {
				$template_response = $server->dispatch(
					self::request(
						'GET',
						$template_route,
						array(
							'context' => 'edit',
							'_fields' => 'id,slug,theme,content,wp_id,original_source,_links',
						)
					)
				);
				$part_response     = $server->dispatch(
					self::request(
						'GET',
						$part_route,
						array(
							'context' => 'edit',
							'_fields' => 'id,slug,type,area,content,wp_id,_links',
						)
					)
				);
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
				\remove_filter( 'pre_get_block_template', $filter, 10 );
			}
		} finally {
			if ( $server_existed ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		$expected_filter_log = array(
			array(
				'id'   => $case['templateId'],
				'type' => 'wp_template',
			),
			array(
				'id'   => $case['templateId'],
				'type' => 'wp_template',
			),
			array(
				'id'   => $case['templatePartId'],
				'type' => 'wp_template_part',
			),
			array(
				'id'   => $case['templatePartId'],
				'type' => 'wp_template_part',
			),
		);
		$denied_data         = $denied_response instanceof \WP_REST_Response ? $denied_response->get_data() : array();
		$template_data       = $template_response instanceof \WP_REST_Response ? $template_response->get_data() : array();
		$template_links      = $template_response instanceof \WP_REST_Response ? $template_response->get_links() : array();
		$part_data           = $part_response instanceof \WP_REST_Response ? $part_response->get_data() : array();
		$part_links          = $part_response instanceof \WP_REST_Response ? $part_response->get_links() : array();
		$template_keys       = array_keys( $template_data );
		$part_keys           = array_keys( $part_data );
		sort( $template_keys );
		sort( $part_keys );
		$template_content_keys = isset( $template_data['content'] ) && is_array( $template_data['content'] )
			? array_keys( $template_data['content'] )
			: array();
		$part_content_keys     = isset( $part_data['content'] ) && is_array( $part_data['content'] )
			? array_keys( $part_data['content'] )
			: array();
		sort( $template_content_keys );
		sort( $part_content_keys );

		self::collect_failure(
			$failures,
			$denied_response instanceof \WP_REST_Response
				&& 403 === $denied_response->get_status()
				&& 'rest_cannot_manage_templates' === ( $denied_data['code'] ?? null )
				&& array() === $denied_filter_log,
			'template item dispatch denies access before template lookup when capabilities are absent',
			array(
				'status'    => $denied_response instanceof \WP_REST_Response ? $denied_response->get_status() : null,
				'data'      => $denied_data,
				'filterLog' => $denied_filter_log,
			)
		);

		self::collect_failure(
			$failures,
			$template_response instanceof \WP_REST_Response
				&& 200 === $template_response->get_status()
				&& $case['templateId'] === ( $template_data['id'] ?? null )
				&& $case['templateSlug'] === ( $template_data['slug'] ?? null )
				&& $case['themeSlug'] === ( $template_data['theme'] ?? null )
				&& $case['templateContent'] === ( $template_data['content']['raw'] ?? null )
				&& $fixtures['template'] === (int) ( $template_data['wp_id'] ?? 0 )
				&& 'user' === ( $template_data['original_source'] ?? null )
				&& self::link_href( $template_links, 'self' ) === \rest_url( 'wp/v2/templates/' . $case['templateId'] )
				&& array( 'content', 'id', 'original_source', 'slug', 'theme', 'wp_id' ) === $template_keys
				&& array( 'block_version', 'raw' ) === $template_content_keys,
			'template item dispatch sanitizes single-slash route IDs and applies REST field filtering',
			array(
				'route'       => $template_route,
				'data'        => $template_data,
				'links'       => $template_links,
				'status'      => $template_response instanceof \WP_REST_Response ? $template_response->get_status() : null,
				'dataKeys'    => $template_keys,
				'contentKeys' => $template_content_keys,
			)
		);

		self::collect_failure(
			$failures,
			$part_response instanceof \WP_REST_Response
				&& 200 === $part_response->get_status()
				&& $case['templatePartId'] === ( $part_data['id'] ?? null )
				&& $case['templatePartSlug'] === ( $part_data['slug'] ?? null )
				&& 'wp_template_part' === ( $part_data['type'] ?? null )
				&& 'header' === ( $part_data['area'] ?? null )
				&& $case['templatePartContent'] === ( $part_data['content']['raw'] ?? null )
				&& $fixtures['templatePart'] === (int) ( $part_data['wp_id'] ?? 0 )
				&& self::link_href( $part_links, 'self' ) === \rest_url( 'wp/v2/template-parts/' . $case['templatePartId'] )
				&& array( 'area', 'content', 'id', 'slug', 'type', 'wp_id' ) === $part_keys
				&& array( 'block_version', 'raw' ) === $part_content_keys,
			'template-part item dispatch returns area-specific data through the registered route',
			array(
				'route'       => $part_route,
				'data'        => $part_data,
				'links'       => $part_links,
				'status'      => $part_response instanceof \WP_REST_Response ? $part_response->get_status() : null,
				'dataKeys'    => $part_keys,
				'contentKeys' => $part_content_keys,
			)
		);

		self::collect_failure(
			$failures,
			$expected_filter_log === $filter_log
				&& false === \has_filter( 'pre_get_block_template', $filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'template item dispatch uses only the expected bounded template lookups and removes filters',
			array(
				'filterLog'             => $filter_log,
				'templateFilterRemoved' => false === \has_filter( 'pre_get_block_template', $filter ),
				'capFilterRemoved'      => false === \has_filter( 'user_has_cap', $cap_filter ),
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.template-item-route-dispatch',
			$failures,
			array(
				'templateRoute'     => $template_route,
				'templatePartRoute' => $part_route,
				'filterLog'         => $filter_log,
			)
		);
	}

	private static function check_template_collection_dispatch_guards(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		$failures        = array();
		$previous_server = $GLOBALS['wp_rest_server'] ?? null;
		$server_existed  = array_key_exists( 'wp_rest_server', $GLOBALS );
		$server          = new \WP_REST_Server();
		$filter_log      = array();
		$head_log        = array();
		$collection_log  = array();
		$template_collection = null;
		$part_collection = null;
		$collection_template = self::template_object(
			$case,
			array(
				'id'        => $case['templateId'],
				'slug'      => $case['templateSlug'],
				'type'      => 'wp_template',
				'content'   => $case['templateContent'],
				'wp_id'     => 0,
				'is_custom' => true,
			)
		);
		$collection_part     = self::template_object(
			$case,
			array(
				'id'      => $case['templatePartId'],
				'slug'    => $case['templatePartSlug'],
				'type'    => 'wp_template_part',
				'title'   => $case['templatePartTitle'],
				'area'    => 'header',
				'content' => $case['templatePartContent'],
				'wp_id'   => 0,
			)
		);
		$template_filter = static function ( $block_templates, array $query, string $template_type ) use (
			&$filter_log,
			$collection_template,
			$collection_part
		) {
			$filter_log[] = array(
				'hook'  => 'pre_get_block_templates',
				'type'  => $template_type,
				'query' => $query,
			);

			if ( 'wp_template' === $template_type && 'page' === ( $query['post_type'] ?? null ) ) {
				return array( clone $collection_template );
			}
			if ( 'wp_template_part' === $template_type && 'header' === ( $query['area'] ?? null ) ) {
				return array( clone $collection_part );
			}

			return array();
		};
		$item_filter     = static function ( $block_template, string $requested_id, string $template_type ) use ( &$filter_log ) {
			$filter_log[] = array(
				'hook' => 'pre_get_block_template',
				'id'   => $requested_id,
				'type' => $template_type,
			);
			return $block_template;
		};

		try {
			$GLOBALS['wp_rest_server'] = $server;
			( new \WP_REST_Templates_Controller( 'wp_template' ) )->register_routes();
			( new \WP_REST_Templates_Controller( 'wp_template_part' ) )->register_routes();
			( new \WP_REST_Template_Revisions_Controller( 'wp_template' ) )->register_routes();
			( new \WP_REST_Template_Autosaves_Controller( 'wp_template' ) )->register_routes();

			$routes          = $server->get_routes();
			$template_route  = '/wp/v2/templates';
			$part_route      = '/wp/v2/template-parts';
			$revision_route  = self::route_key_for_parts(
				$routes,
				array( '/wp/v2/templates/(?P<parent>', '/revisions' ),
				array( '/revisions/(?P<id>' )
			);
			$autosaves_route = self::route_key_for_parts(
				$routes,
				array( '/wp/v2/templates/(?P<id>', '/autosaves' ),
				array( '/autosaves/(?P<id>' )
			);

			$template_data  = $server->get_data_for_route( $template_route, $routes[ $template_route ] ?? array(), 'help' );
			$part_data      = $server->get_data_for_route( $part_route, $routes[ $part_route ] ?? array(), 'help' );
			$revision_data  = null === $revision_route
				? null
				: $server->get_data_for_route( $revision_route, $routes[ $revision_route ] ?? array(), 'help' );
			$autosaves_data = null === $autosaves_route
				? null
				: $server->get_data_for_route( $autosaves_route, $routes[ $autosaves_route ] ?? array(), 'help' );

			self::collect_failure(
				$failures,
				array( 'GET', 'POST' ) === self::route_methods( $routes[ $template_route ] ?? array() )
					&& array( 'GET', 'POST' ) === self::route_methods( $routes[ $part_route ] ?? array() )
					&& null !== $revision_route
					&& array( 'GET' ) === self::route_methods( $routes[ $revision_route ] ?? array() )
					&& null !== $autosaves_route
					&& array( 'GET', 'POST' ) === self::route_methods( $routes[ $autosaves_route ] ?? array() ),
				'template, template-part, revision, and autosave collection routes expose bounded methods',
				array(
					'templateMethods'  => self::route_methods( $routes[ $template_route ] ?? array() ),
					'partMethods'      => self::route_methods( $routes[ $part_route ] ?? array() ),
					'revisionRoute'    => $revision_route,
					'revisionMethods'  => null === $revision_route ? array() : self::route_methods( $routes[ $revision_route ] ?? array() ),
					'autosavesRoute'   => $autosaves_route,
					'autosavesMethods' => null === $autosaves_route ? array() : self::route_methods( $routes[ $autosaves_route ] ?? array() ),
				)
			);

			self::collect_failure(
				$failures,
				self::route_data_has_methods( $template_data, array( 'GET', 'POST' ) )
					&& self::route_data_has_methods( $part_data, array( 'GET', 'POST' ) )
					&& self::route_data_has_methods( $revision_data, array( 'GET' ) )
					&& self::route_data_has_methods( $autosaves_data, array( 'GET', 'POST' ) )
					&& self::route_data_has_endpoint_args( $template_data, array( 'GET' ), array( 'area', 'context', 'post_type', 'wp_id' ) )
					&& self::route_data_has_endpoint_args( $template_data, array( 'POST' ), array( 'content', 'slug', 'theme', 'title' ) )
					&& self::route_data_has_endpoint_args( $part_data, array( 'GET' ), array( 'area', 'context', 'post_type', 'wp_id' ) )
					&& self::route_data_has_endpoint_args( $part_data, array( 'POST' ), array( 'area', 'content', 'slug', 'theme', 'title' ) )
					&& self::route_data_has_endpoint_args( $revision_data, array( 'GET' ), array( 'context', 'offset', 'page', 'per_page' ) )
					&& self::route_data_has_endpoint_args( $autosaves_data, array( 'GET' ), array( 'context' ) )
					&& self::route_data_has_endpoint_args( $autosaves_data, array( 'POST' ), array( 'content', 'slug', 'theme', 'title' ) )
					&& self::route_data_schema_has_properties( $template_data, array( 'id', 'slug', 'theme', 'content', 'is_custom' ) )
					&& self::route_data_schema_has_properties( $part_data, array( 'id', 'slug', 'theme', 'content', 'area' ) )
					&& self::route_data_schema_has_properties( $revision_data, array( 'id', 'parent', 'content', 'wp_id' ) )
					&& self::route_data_schema_has_properties( $autosaves_data, array( 'id', 'parent', 'content', 'wp_id' ) ),
				'template collection route index data exposes methods, args, and schemas without dispatching broad queries',
				array(
					'templateData'  => $template_data,
					'partData'      => $part_data,
					'revisionRoute' => $revision_route,
					'revisionData'  => $revision_data,
					'autosavesRoute' => $autosaves_route,
					'autosavesData' => $autosaves_data,
				)
			);

			\add_filter( 'pre_get_block_templates', $template_filter, 10, 3 );
			\add_filter( 'pre_get_block_template', $item_filter, 10, 3 );

			$denied_template = $server->dispatch(
				self::request(
					'GET',
					$template_route,
					array(
						'context' => 'edit',
						'_fields' => 'id',
					)
				)
			);
			$denied_part     = $server->dispatch(
				self::request(
					'GET',
					$part_route,
					array(
						'context' => 'edit',
						'_fields' => 'id',
					)
				)
			);
			$denied_lookup   = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/templates/lookup',
					array(
						'context'   => 'edit',
						'is_custom' => true,
						'slug'      => 'denied-' . substr( $case['token'], 0, 6 ),
						'_fields'   => 'id',
					)
				)
			);
			$denied_log      = $filter_log;

			$cap_filter = self::install_cap_filter( array( 'edit_posts' ) );
			try {
				$head_template = $server->dispatch(
					self::request(
						'HEAD',
						$template_route,
						array(
							'context' => 'edit',
							'_fields' => 'id,slug',
						)
					)
				);
				$head_part     = $server->dispatch(
					self::request(
						'HEAD',
						$part_route,
						array(
							'context' => 'edit',
							'_fields' => 'id,slug,area',
						)
					)
				);
				$head_log      = $filter_log;
				$template_collection = $server->dispatch(
					self::request(
						'GET',
						$template_route,
						array(
							'context'   => 'edit',
							'post_type' => 'page',
							'_fields'   => 'id,slug,theme,type,content,wp_id,is_custom',
						)
					)
				);
				$part_collection     = $server->dispatch(
					self::request(
						'GET',
						$part_route,
						array(
							'context' => 'edit',
							'area'    => 'header',
							'_fields' => 'id,slug,type,area,content,wp_id',
						)
					)
				);
				$collection_log      = array_slice( $filter_log, count( $head_log ) );
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
				\remove_filter( 'pre_get_block_templates', $template_filter, 10 );
				\remove_filter( 'pre_get_block_template', $item_filter, 10 );
			}
		} finally {
			if ( $server_existed ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		$denied_status        = \rest_authorization_required_code();
		$denied_template_data = $denied_template instanceof \WP_REST_Response ? $denied_template->get_data() : array();
		$denied_part_data     = $denied_part instanceof \WP_REST_Response ? $denied_part->get_data() : array();
		$denied_lookup_data   = $denied_lookup instanceof \WP_REST_Response ? $denied_lookup->get_data() : array();
		$head_template_data   = $head_template instanceof \WP_REST_Response ? $head_template->get_data() : null;
		$head_part_data       = $head_part instanceof \WP_REST_Response ? $head_part->get_data() : null;
		$template_collection_data = $template_collection instanceof \WP_REST_Response ? $template_collection->get_data() : null;
		$part_collection_data     = $part_collection instanceof \WP_REST_Response ? $part_collection->get_data() : null;

		self::collect_failure(
			$failures,
			$denied_template instanceof \WP_REST_Response
				&& $denied_status === $denied_template->get_status()
				&& 'rest_cannot_manage_templates' === ( $denied_template_data['code'] ?? null )
				&& $denied_part instanceof \WP_REST_Response
				&& $denied_status === $denied_part->get_status()
				&& 'rest_cannot_manage_templates' === ( $denied_part_data['code'] ?? null )
				&& $denied_lookup instanceof \WP_REST_Response
				&& $denied_status === $denied_lookup->get_status()
				&& 'rest_cannot_manage_templates' === ( $denied_lookup_data['code'] ?? null )
				&& array() === $denied_log,
			'template collection and lookup dispatch deny access before template lookup when capabilities are absent',
			array(
				'deniedStatus' => $denied_status,
				'templateStatus' => $denied_template instanceof \WP_REST_Response ? $denied_template->get_status() : null,
				'templateData' => $denied_template_data,
				'partStatus'   => $denied_part instanceof \WP_REST_Response ? $denied_part->get_status() : null,
				'partData'     => $denied_part_data,
				'lookupStatus' => $denied_lookup instanceof \WP_REST_Response ? $denied_lookup->get_status() : null,
				'lookupData'   => $denied_lookup_data,
				'filterLog'    => $denied_log,
			)
		);

		self::collect_failure(
			$failures,
			$head_template instanceof \WP_REST_Response
				&& 200 === $head_template->get_status()
				&& array() === $head_template_data
				&& $head_part instanceof \WP_REST_Response
				&& 200 === $head_part->get_status()
				&& array() === $head_part_data
				&& array() === $head_log
				&& false === \has_filter( 'pre_get_block_templates', $template_filter )
				&& false === \has_filter( 'pre_get_block_template', $item_filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'template collection HEAD dispatch returns empty data and does not call template lookup filters',
			array(
				'templateStatus' => $head_template instanceof \WP_REST_Response ? $head_template->get_status() : null,
				'templateData'   => $head_template_data,
				'partStatus'     => $head_part instanceof \WP_REST_Response ? $head_part->get_status() : null,
				'partData'       => $head_part_data,
				'filterLog'      => $head_log,
				'templateFilterRemoved' => false === \has_filter( 'pre_get_block_templates', $template_filter ),
				'itemFilterRemoved' => false === \has_filter( 'pre_get_block_template', $item_filter ),
				'capFilterRemoved' => false === \has_filter( 'user_has_cap', $cap_filter ),
			)
		);

		self::collect_failure(
			$failures,
			$template_collection instanceof \WP_REST_Response
				&& 200 === $template_collection->get_status()
				&& is_array( $template_collection_data )
				&& 1 === count( $template_collection_data )
				&& array(
					'id',
					'theme',
					'content',
					'slug',
					'type',
					'wp_id',
					'is_custom',
				) === array_keys( $template_collection_data[0] ?? array() )
				&& $case['templateId'] === ( $template_collection_data[0]['id'] ?? null )
				&& $case['templateSlug'] === ( $template_collection_data[0]['slug'] ?? null )
				&& $case['themeSlug'] === ( $template_collection_data[0]['theme'] ?? null )
				&& 'wp_template' === ( $template_collection_data[0]['type'] ?? null )
				&& $case['templateContent'] === ( $template_collection_data[0]['content']['raw'] ?? null )
				&& 1 === (int) ( $template_collection_data[0]['content']['block_version'] ?? 0 )
				&& 0 === (int) ( $template_collection_data[0]['wp_id'] ?? -1 )
				&& true === ( $template_collection_data[0]['is_custom'] ?? null )
				&& $part_collection instanceof \WP_REST_Response
				&& 200 === $part_collection->get_status()
				&& is_array( $part_collection_data )
				&& 1 === count( $part_collection_data )
				&& array(
					'id',
					'content',
					'slug',
					'type',
					'wp_id',
					'area',
				) === array_keys( $part_collection_data[0] ?? array() )
				&& $case['templatePartId'] === ( $part_collection_data[0]['id'] ?? null )
				&& $case['templatePartSlug'] === ( $part_collection_data[0]['slug'] ?? null )
				&& 'wp_template_part' === ( $part_collection_data[0]['type'] ?? null )
				&& 'header' === ( $part_collection_data[0]['area'] ?? null )
				&& $case['templatePartContent'] === ( $part_collection_data[0]['content']['raw'] ?? null )
				&& array(
					array(
						'hook'  => 'pre_get_block_templates',
						'type'  => 'wp_template',
						'query' => array( 'post_type' => 'page' ),
					),
					array(
						'hook'  => 'pre_get_block_templates',
						'type'  => 'wp_template_part',
						'query' => array( 'area' => 'header' ),
					),
				) === $collection_log
				&& false === \has_filter( 'pre_get_block_templates', $template_filter )
				&& false === \has_filter( 'pre_get_block_template', $item_filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'template and template-part collection GET dispatch uses bounded filters, query params, and _fields projection',
			array(
				'templateStatus' => $template_collection instanceof \WP_REST_Response ? $template_collection->get_status() : null,
				'templateData'   => $template_collection_data,
				'partStatus'     => $part_collection instanceof \WP_REST_Response ? $part_collection->get_status() : null,
				'partData'       => $part_collection_data,
				'collectionLog'  => $collection_log,
				'templateFilterRemoved' => false === \has_filter( 'pre_get_block_templates', $template_filter ),
				'itemFilterRemoved' => false === \has_filter( 'pre_get_block_template', $item_filter ),
				'capFilterRemoved' => false === \has_filter( 'user_has_cap', $cap_filter ),
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.template-collection-dispatch-guards',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'filterLog'  => $filter_log,
				'notCovered' => 'Revision/autosave collection callbacks after permission success, template CPT queries, and filesystem-backed template traversal remain intentionally outside this bounded dispatch guard.',
			)
		);
	}

	private static function check_template_lookup_fallback_dispatch(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		$failures        = array();
		$previous_server = $GLOBALS['wp_rest_server'] ?? null;
		$server_existed  = array_key_exists( 'wp_rest_server', $GLOBALS );
		$server          = new \WP_REST_Server();
		$custom_slug     = 'custom-' . substr( $case['token'], 0, 6 );
		$missing_slug    = 'missing-' . substr( $case['token'], 0, 6 );
		$fallback_markup = self::paragraph_block( 'Fallback lookup ' . $case['token'] );
		$page_template   = self::template_object(
			$case,
			array(
				'id'        => $case['themeSlug'] . '//page',
				'content'   => '',
				'slug'      => 'page',
				'title'     => 'Empty Page Fallback',
				'is_custom' => false,
			)
		);
		$singular        = self::template_object(
			$case,
			array(
				'id'        => $case['themeSlug'] . '//singular',
				'content'   => $fallback_markup,
				'slug'      => 'singular',
				'title'     => 'Singular Fallback',
				'is_custom' => false,
			)
		);
		$templates       = array(
			'page'     => $page_template,
			'singular' => $singular,
		);
		$filter_log      = array();
		$filter          = static function ( $block_templates, array $query, string $template_type ) use (
			$templates,
			&$filter_log
		) {
			$slugs        = array_values( $query['slug__in'] ?? array() );
			$filter_log[] = array(
				'type'     => $template_type,
				'slug__in' => $slugs,
			);

			if ( 'wp_template' !== $template_type ) {
				return array();
			}

			$matches = array();
			foreach ( $slugs as $slug ) {
				if ( isset( $templates[ $slug ] ) ) {
					$matches[] = clone $templates[ $slug ];
				}
			}
			return $matches;
		};

		try {
			$GLOBALS['wp_rest_server'] = $server;
			$controller                = new \WP_REST_Templates_Controller( 'wp_template' );
			$controller->register_routes();
			$routes = $server->get_routes();
			$route  = $routes['/wp/v2/templates/lookup'][0] ?? array();

			\add_filter( 'pre_get_block_templates', $filter, 10, 3 );
			$cap_filter = self::install_cap_filter( array( 'edit_posts' ) );
			try {
				$found_response = $server->dispatch(
					self::request(
						'GET',
						'/wp/v2/templates/lookup',
						array(
							'slug'      => $custom_slug,
							'is_custom' => true,
							'context'   => 'edit',
							'_fields'   => 'id,slug,theme,content,source,is_custom',
						)
					)
				);
				$empty_response = $server->dispatch(
					self::request(
						'GET',
						'/wp/v2/templates/lookup',
						array(
							'slug'      => $missing_slug,
							'is_custom' => false,
							'context'   => 'edit',
							'_fields'   => 'id,slug,content',
						)
					)
				);
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
				\remove_filter( 'pre_get_block_templates', $filter, 10 );
			}
		} finally {
			if ( $server_existed ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		$found_data       = $found_response instanceof \WP_REST_Response ? $found_response->get_data() : array();
		$empty_data       = $empty_response instanceof \WP_REST_Response ? $empty_response->get_data() : null;
		$expected_queries = array(
			array(
				'type'     => 'wp_template',
				'slug__in' => array( 'page', 'singular', 'index' ),
			),
			array(
				'type'     => 'wp_template',
				'slug__in' => array( 'singular', 'index' ),
			),
			array(
				'type'     => 'wp_template',
				'slug__in' => array( $missing_slug, 'index' ),
			),
			array(
				'type'     => 'wp_template',
				'slug__in' => array( 'index' ),
			),
		);

		self::collect_failure(
			$failures,
			isset( $route['callback'], $route['permission_callback'], $route['methods']['GET'] )
				&& 'WP_REST_Templates_Controller::get_template_fallback' === self::callback_summary( $route['callback'] )
				&& 'WP_REST_Templates_Controller::get_item_permissions_check' === self::callback_summary( $route['permission_callback'] ),
			'template lookup route registers the fallback controller callback and read permission guard',
			array( 'route' => self::route_summary( $route ) )
		);

		self::collect_failure(
			$failures,
			$found_response instanceof \WP_REST_Response
				&& 200 === $found_response->get_status()
				&& $case['themeSlug'] . '//singular' === ( $found_data['id'] ?? null )
				&& 'singular' === ( $found_data['slug'] ?? null )
				&& $case['themeSlug'] === ( $found_data['theme'] ?? null )
				&& $fallback_markup === ( $found_data['content']['raw'] ?? null )
				&& 'custom' === ( $found_data['source'] ?? null )
				&& false === ( $found_data['is_custom'] ?? null ),
			'template lookup dispatch skips empty higher-priority fallbacks and returns populated fallback content',
			array(
				'status' => $found_response instanceof \WP_REST_Response ? $found_response->get_status() : null,
				'data'   => $found_data,
			)
		);

		self::collect_failure(
			$failures,
			$empty_response instanceof \WP_REST_Response
				&& 200 === $empty_response->get_status()
				&& $empty_data instanceof \stdClass
				&& array() === get_object_vars( $empty_data ),
			'template lookup dispatch preserves the empty-object no-fallback response contract',
			array(
				'status' => $empty_response instanceof \WP_REST_Response ? $empty_response->get_status() : null,
				'data'   => $empty_data,
			)
		);

		self::collect_failure(
			$failures,
			$expected_queries === $filter_log
				&& false === \has_filter( 'pre_get_block_templates', $filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'template lookup dispatch uses bounded hierarchy queries and removes filters',
			array(
				'filterLog'             => $filter_log,
				'templateFilterRemoved' => false === \has_filter( 'pre_get_block_templates', $filter ),
				'capFilterRemoved'      => false === \has_filter( 'user_has_cap', $cap_filter ),
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.template-lookup-fallback-dispatch',
			$failures,
			array(
				'customSlug'  => $custom_slug,
				'missingSlug' => $missing_slug,
				'filterLog'   => $filter_log,
			)
		);
	}

	private static function check_template_revisions_autosaves(
		\ComponentFuzz\FuzzContext $ctx,
		array $case,
		array $fixtures
	): array {
		$revision_controller = new \WP_REST_Template_Revisions_Controller( 'wp_template' );
		$autosave_controller = new \WP_REST_Template_Autosaves_Controller( 'wp_template' );
		$failures            = array();

		$revision_schema = $revision_controller->get_item_schema();
		$autosave_schema = $autosave_controller->get_item_schema();

		self::collect_failure(
			$failures,
			isset( $revision_schema['properties']['parent'], $revision_schema['properties']['content'] )
				&& isset( $autosave_schema['properties']['parent'], $autosave_schema['properties']['content'] ),
			'template revision and autosave schemas inherit template fields and parent IDs',
			array(
				'revisionKeys' => array_keys( $revision_schema['properties'] ?? array() ),
				'autosaveKeys' => array_keys( $autosave_schema['properties'] ?? array() ),
			)
		);

		$missing_parent = self::invoke_method(
			$revision_controller,
			'get_parent',
			array( $case['themeSlug'] . '//missing' )
		);
		$file_template  = self::template_object(
			$case,
			array(
				'source' => 'theme',
				'wp_id'  => 0,
			)
		);
		$file_filter    = self::install_template_filter( $case['templateId'], 'wp_template', $file_template );
		try {
			$file_parent = self::invoke_method( $revision_controller, 'get_parent', array( $case['templateId'] ) );
		} finally {
			\remove_filter( 'pre_get_block_template', $file_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$missing_parent instanceof \WP_Error
				&& 'rest_post_invalid_parent' === $missing_parent->get_error_code()
				&& $file_parent instanceof \WP_Error
				&& 'rest_invalid_template' === $file_parent->get_error_code(),
			'template revision parent lookup distinguishes missing and theme-file templates',
			array(
				'missingParent' => $missing_parent,
				'fileParent'    => $file_parent,
			)
		);

		$revision_request = self::request(
			'GET',
			'/wp/v2/templates/' . $case['templateId'] . '/revisions/' . $fixtures['templateRevision'],
			array(
				'context' => 'edit',
				'_fields' => 'id,parent,content,slug,wp_id,_links',
			),
			array(
				'parent' => $case['templateId'],
				'id'     => $fixtures['templateRevision'],
			)
		);
		$revision         = $revision_controller->prepare_item_for_response(
			\get_post( $fixtures['templateRevision'] ),
			$revision_request
		);
		$revision_data    = $revision instanceof \WP_REST_Response ? $revision->get_data() : array();
		$revision_links   = $revision instanceof \WP_REST_Response ? $revision->get_links() : array();

		self::collect_failure(
			$failures,
			$revision instanceof \WP_REST_Response
				&& $case['templateId'] === ( $revision_data['id'] ?? null )
				&& $fixtures['template'] === (int) ( $revision_data['parent'] ?? 0 )
				&& $fixtures['templateRevision'] === (int) ( $revision_data['wp_id'] ?? 0 )
				&& $case['templateRevisionContent'] === ( $revision_data['content']['raw'] ?? null )
				&& self::link_href( $revision_links, 'parent' ) === \rest_url( 'wp/v2/templates/' . $case['templateId'] ),
			'template revision response maps revision posts back to template-shaped data',
			array(
				'data'  => $revision_data,
				'links' => $revision_links,
			)
		);

		$template_filter = self::install_template_filter(
			$case['templateId'],
			'wp_template',
			self::template_object(
				$case,
				array(
					'wp_id' => $fixtures['template'],
				)
			)
		);
		try {
			$autosave = $autosave_controller->get_item(
				self::request(
					'GET',
					'/wp/v2/templates/' . $case['templateId'] . '/autosaves/' . $fixtures['templateAutosave'],
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,content,wp_id,_links',
					),
					array(
						'parent' => $case['templateId'],
						'id'     => $fixtures['templateAutosave'],
					)
				)
			);
		} finally {
			\remove_filter( 'pre_get_block_template', $template_filter, 10 );
		}

		$autosave_data  = $autosave instanceof \WP_REST_Response ? $autosave->get_data() : array();
		$autosave_links = $autosave instanceof \WP_REST_Response ? $autosave->get_links() : array();

		self::collect_failure(
			$failures,
			$autosave instanceof \WP_REST_Response
				&& $case['templateId'] === ( $autosave_data['id'] ?? null )
				&& $fixtures['template'] === (int) ( $autosave_data['parent'] ?? 0 )
				&& $fixtures['templateAutosave'] === (int) ( $autosave_data['wp_id'] ?? 0 )
				&& $case['templateAutosaveContent'] === ( $autosave_data['content']['raw'] ?? null )
				&& self::link_href( $autosave_links, 'parent' ) === \rest_url( 'wp/v2/templates/' . $case['templateId'] ),
			'template autosave get_item uses bounded parent filter and returns template-shaped data',
			array(
				'data'  => $autosave_data,
				'links' => $autosave_links,
			)
		);

		$delete_denied = $revision_controller->delete_item_permissions_check(
			self::request(
				'DELETE',
				'/wp/v2/templates/' . $case['templateId'] . '/revisions/' . $fixtures['templateRevision'],
				array(),
				array(
					'parent' => $case['templateId'],
					'id'     => $fixtures['templateRevision'],
				)
			)
		);

		self::collect_failure(
			$failures,
			$delete_denied instanceof \WP_Error
				&& in_array( $delete_denied->get_error_code(), array( 'rest_cannot_delete', 'rest_post_invalid_parent' ), true ),
			'template revision delete permission fails closed without destructive delete calls',
			array( 'deleteDenied' => $delete_denied )
		);

		return self::result(
			$ctx,
			'rest-site-editor.template-revisions-autosaves',
			$failures,
			array(
				'revision' => $fixtures['templateRevision'],
				'autosave' => $fixtures['templateAutosave'],
			)
		);
	}

	private static function check_navigation_fallback_controller(
		\ComponentFuzz\FuzzContext $ctx,
		array $case,
		array $fixtures
	): array {
		$controller = new \WP_REST_Navigation_Fallback_Controller();
		$failures   = array();

		$denied = $controller->get_item_permissions_check(
			self::request(
				'GET',
				'/wp-block-editor/v1/navigation-fallback',
				array( 'context' => 'edit' )
			)
		);
		$cap_filter = self::install_cap_filter( array( 'edit_theme_options', 'edit_posts' ) );
		try {
			$allowed = $controller->get_item_permissions_check(
				self::request(
					'GET',
					'/wp-block-editor/v1/navigation-fallback',
					array( 'context' => 'edit' )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$denied instanceof \WP_Error
				&& 'rest_cannot_create' === $denied->get_error_code()
				&& true === $allowed,
			'navigation fallback permissions require navigation creation, theme edit, and post edit caps',
			array(
				'denied'  => $denied,
				'allowed' => $allowed,
			)
		);

		$schema   = $controller->get_item_schema();
		$response = $controller->prepare_item_for_response(
			\get_post( $fixtures['navigation'] ),
			self::request(
				'GET',
				'/wp-block-editor/v1/navigation-fallback',
				array(
					'context' => 'edit',
					'_fields' => 'id,_links',
				)
			)
		);
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$links    = $response instanceof \WP_REST_Response ? $response->get_links() : array();

		self::collect_failure(
			$failures,
			isset( $schema['properties']['id'] )
				&& $response instanceof \WP_REST_Response
				&& $fixtures['navigation'] === (int) ( $data['id'] ?? 0 )
				&& self::link_href( $links, 'self' ) === \rest_url( 'wp/v2/navigation/' . $fixtures['navigation'] ),
			'navigation fallback response exposes only fallback ID and self link',
			array(
				'data'  => $data,
				'links' => $links,
			)
		);

		$creation_filter = static function (): bool {
			return false;
		};
		$query_filter    = static function ( $posts, $query ) use ( $fixtures ) {
			if ( is_object( $query ) && method_exists( $query, 'get' ) && 'wp_navigation' === $query->get( 'post_type' ) ) {
				return array( \get_post( $fixtures['navigation'] ) );
			}

			return $posts;
		};
		\add_filter( 'wp_navigation_should_create_fallback', $creation_filter );
		\add_filter( 'posts_pre_query', $query_filter, 10, 2 );
		try {
			$item = $controller->get_item(
				self::request(
					'GET',
					'/wp-block-editor/v1/navigation-fallback',
					array(
						'context' => 'view',
						'_fields' => 'id',
					)
				)
			);
		} finally {
			\remove_filter( 'posts_pre_query', $query_filter, 10 );
			\remove_filter( 'wp_navigation_should_create_fallback', $creation_filter );
		}
		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();

		self::collect_failure(
			$failures,
			$item instanceof \WP_REST_Response
				&& $fixtures['navigation'] === (int) ( $item_data['id'] ?? 0 ),
			'navigation fallback get_item can be bounded by query and no-create filters',
			array( 'itemData' => $item_data )
		);

		return self::result(
			$ctx,
			'rest-site-editor.navigation-fallback',
			$failures,
			array(
				'navigationId' => $case['navigationId'],
				'postId'       => $fixtures['navigation'],
			)
		);
	}

	private static function check_edit_site_export_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		$controller = new \WP_REST_Edit_Site_Export_Controller();
		$failures   = array();

		$denied = $controller->permissions_check();
		$filter = self::install_cap_filter( array( 'export' ) );
		try {
			$allowed = $controller->permissions_check();
		} finally {
			\remove_filter( 'user_has_cap', $filter, 10 );
		}

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		$controller->register_routes();
		$routes = $GLOBALS['wp_rest_server']->get_routes();
		$route  = $routes['/wp-block-editor/v1/export'][0] ?? array();

		self::collect_failure(
			$failures,
			$denied instanceof \WP_Error
				&& 'rest_cannot_export_templates' === $denied->get_error_code()
				&& true === $allowed
				&& isset( $route['callback'], $route['permission_callback'] )
				&& array( $controller, 'export' ) === $route['callback']
				&& array( $controller, 'permissions_check' ) === $route['permission_callback']
				&& isset( $route['methods']['GET'] ),
			'edit-site export route and permission guard are covered without streaming a zip',
			array(
				'denied'  => $denied,
				'allowed' => $allowed,
				'route'   => self::route_summary( $route ),
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.edit-site-export-guards',
			$failures
		);
	}

	private static function check_block_templates_export_generator(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::skip(
				$ctx,
				'rest-site-editor.block-templates-export-generator.skipped',
				'ZipArchive is unavailable, so the direct block template ZIP generator cannot run.',
				array( 'extension' => 'zip' )
			);
		}

		$failures   = array();
		$filter_log = array();
		$filter     = self::install_export_template_filter( $case, $filter_log );
		$filename   = null;
		$zip        = null;
		$zip_opened = false;
		$zip_close_error = null;
		$unlink_result = null;
		$zip_cleanup_ok = true;

		try {
			self::reset_runtime_caches();
			$filename = \wp_generate_block_templates_export_file();

			if ( \is_wp_error( $filename ) ) {
				self::collect_failure(
					$failures,
					false,
					'block template export generator returns a ZIP filename',
					array( 'error' => $filename )
				);
			} elseif ( ! is_string( $filename ) || '' === $filename ) {
				self::collect_failure(
					$failures,
					false,
					'block template export generator returns a non-empty filename string',
					array( 'filename' => $filename )
				);
			} else {
				$zip = new \ZipArchive();
				$zip_opened = true === $zip->open( $filename );

				self::collect_failure(
					$failures,
					file_exists( $filename )
						&& filesize( $filename ) > 0
						&& self::path_is_within( $filename, \get_temp_dir() )
						&& $zip_opened,
					'export ZIP is created inside the expected temp directory and can be opened',
					array(
						'filename' => self::preview( $filename ),
						'tempDir'  => self::preview( \get_temp_dir() ),
						'opened'   => $zip_opened,
					)
				);

				if ( $zip_opened ) {
					self::inspect_block_template_export_zip( $zip, $case, $failures );
				}
			}

			self::collect_failure(
				$failures,
				self::expected_export_template_filter_log() === $filter_log,
				'export generator retrieves only the scoped template and template-part collections',
				array( 'filterLog' => $filter_log )
			);
		} finally {
			\remove_filter( 'pre_get_block_templates', $filter, 10 );
			if ( $zip instanceof \ZipArchive && $zip_opened ) {
				try {
					$zip->close();
				} catch ( \Throwable $e ) {
					$zip_close_error = self::describe_throwable( $e );
				}
			}
			if ( is_string( $filename ) && '' !== $filename && file_exists( $filename ) ) {
				$unlink_result  = @unlink( $filename );
				$zip_cleanup_ok = ! file_exists( $filename );
			}
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_get_block_templates', $filter )
				&& null === $zip_close_error
				&& $zip_cleanup_ok,
			'export generator cleanup removes scoped filters, closes opened archives, and deletes the temporary ZIP',
			array(
				'filterRemoved' => false === \has_filter( 'pre_get_block_templates', $filter ),
				'zipOpened'     => $zip_opened,
				'closeError'    => $zip_close_error,
				'unlinkResult'  => $unlink_result,
				'zipRemaining'  => is_string( $filename ) && '' !== $filename && file_exists( $filename ),
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.block-templates-export-generator',
			$failures,
			array(
				'case'      => self::case_summary( $case ),
				'filterLog' => $filter_log,
				'zipFile'   => is_string( $filename ) ? self::preview( basename( $filename ) ) : $filename,
			)
		);
	}

	private static function check_live_edit_site_export_controller(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		$missing = self::missing_live_edit_site_export_requirements();
		if ( array() !== $missing ) {
			return self::skip(
				$ctx,
				'rest-site-editor.live-export.skipped',
				'Required isolated live export subprocess APIs are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$run                  = self::run_child_live_edit_site_export( $case );
		$failures             = array();
		$metadata             = is_array( $run['metadata'] ) ? $run['metadata'] : array();
		$zip_base64           = is_string( $metadata['zipBase64'] ?? null ) ? $metadata['zipBase64'] : '';
		$decoded_zip          = '' !== $zip_base64 ? base64_decode( $zip_base64, true ) : false;
		$streamed_zip         = is_string( $decoded_zip ) ? $decoded_zip : '';
		$streamed_bytes       = strlen( $streamed_zip );
		$zip_path             = null;
		$zip                  = null;
		$zip_open_result      = null;
		$zip_opened           = false;
		$zip_close_error      = null;
		$zip_cleanup_ok       = true;
		$headers_observable   = true === ( $metadata['headersObservable'] ?? false );
		$expected_filter_log  = self::expected_export_template_filter_log();
		$filter_log           = is_array( $metadata['filterLog'] ?? null ) ? $metadata['filterLog'] : null;
		$new_zip_files_after  = is_array( $metadata['newZipFilesAfterExport'] ?? null )
			? $metadata['newZipFilesAfterExport']
			: null;
		$parent_pid           = getmypid();
		$child_pid            = isset( $metadata['pid'] ) ? (int) $metadata['pid'] : null;
		$metadata_parent_pid  = isset( $metadata['parentPid'] ) ? (int) $metadata['parentPid'] : null;

		try {
			self::collect_failure(
				$failures,
				0 === $run['exitCode']
					&& is_array( $run['metadata'] )
					&& 'stdout' === $run['metadataSource']
					&& '' === $run['unexpectedOutput']
					&& true === ( $metadata['ok'] ?? null )
					&& false === ( $metadata['exportReturned'] ?? true )
					&& true === ( $metadata['exportStarted'] ?? null ),
				'isolated edit-site export child exits cleanly through export() and writes structured stdout metadata',
				array(
					'exitCode'         => $run['exitCode'],
					'metadata'         => self::live_export_metadata_summary( $metadata ),
					'metadataSource'   => $run['metadataSource'],
					'metadataRaw'      => self::preview( $run['metadataRaw'] ),
					'unexpectedOutput' => self::preview( $run['unexpectedOutput'] ),
					'stdoutPreview'    => self::preview( $run['stdout'] ),
					'stderrPreview'    => self::preview( $run['stderr'] ),
					'metadataPath'     => self::preview( $run['metadataPath'] ),
				)
			);

			self::collect_failure(
				$failures,
				'' === $run['stderr'],
				'live export child emits no stderr diagnostics during the successful export path',
				array( 'stderr' => self::preview( $run['stderr'] ) )
			);

			self::collect_failure(
				$failures,
				is_string( $decoded_zip ) && $streamed_bytes > 0 && 'PK' === substr( $streamed_zip, 0, 2 ),
				'live export streams non-empty ZIP bytes through the structured stdout protocol',
				array(
					'streamedBytes' => $streamed_bytes,
					'zipBase64Set'  => '' !== $zip_base64,
					'zipPrefix'     => self::preview( substr( $streamed_zip, 0, 32 ) ),
				)
			);

			if ( $streamed_bytes > 0 ) {
				$zip_path = self::write_live_export_stream_zip( $streamed_zip );
				$zip      = new \ZipArchive();

				$zip_open_result = $zip->open( $zip_path );
				$zip_opened      = true === $zip_open_result;

				self::collect_failure(
					$failures,
					file_exists( $zip_path )
						&& filesize( $zip_path ) === $streamed_bytes
						&& $zip_opened,
					'live export streamed bytes can be reopened as the exported ZIP archive',
					array(
						'zipPath'        => self::preview( $zip_path ),
						'streamedBytes'  => $streamed_bytes,
						'writtenBytes'   => file_exists( $zip_path ) ? filesize( $zip_path ) : null,
						'zipOpenResult'  => $zip_open_result,
					)
				);

				if ( $zip_opened ) {
					self::inspect_block_template_export_zip( $zip, $case, $failures );
				}
			}

			self::collect_failure(
				$failures,
				$expected_filter_log === $filter_log,
				'live export generator retrieves only scoped template and template-part collections',
				array(
					'expectedFilterLog' => $expected_filter_log,
					'filterLog'         => $filter_log,
				)
			);

			self::collect_failure(
				$failures,
				true === ( $metadata['zipFilesRestored'] ?? null )
					&& array() === $new_zip_files_after,
				'live export unlinks the generated temporary ZIP before process shutdown',
				array(
					'beforeZipFiles'         => $metadata['beforeZipFiles'] ?? null,
					'afterZipFiles'          => $metadata['afterZipFiles'] ?? null,
					'newZipFilesAfterExport' => $new_zip_files_after,
					'zipFilesRestored'       => $metadata['zipFilesRestored'] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				true === ( $metadata['filterRemovedAtShutdown'] ?? null )
					&& is_int( $child_pid )
					&& is_int( $metadata_parent_pid )
					&& $parent_pid === $metadata_parent_pid
					&& $child_pid !== $parent_pid,
				'live export child removes scoped filters at shutdown and runs isolated from parent process state',
				array(
					'parentPid'               => $parent_pid,
					'metadataParentPid'       => $metadata_parent_pid,
					'childPid'                => $child_pid,
					'filterRemovedAtShutdown' => $metadata['filterRemovedAtShutdown'] ?? null,
					'contentDirRemaining'     => $metadata['contentDirRemainingAtShutdown'] ?? null,
					'themeFixtureRemaining'   => $metadata['themeFixtureRemainingAtShutdown'] ?? null,
				)
			);

			if ( $headers_observable ) {
				self::collect_failure(
					$failures,
					self::header_value( $metadata['headers'] ?? array(), 'Content-Type' ) === 'application/zip'
						&& self::header_value( $metadata['headers'] ?? array(), 'Content-Disposition' )
							=== 'attachment; filename=' . basename( $case['themeSlug'] ) . '.zip'
						&& self::header_value( $metadata['headers'] ?? array(), 'Content-Length' ) === (string) $streamed_bytes,
					'observable live export headers describe the streamed ZIP payload',
					array(
						'headers'        => $metadata['headers'] ?? array(),
						'expectedLength' => $streamed_bytes,
						'expectedName'   => basename( $case['themeSlug'] ) . '.zip',
					)
				);
			}
		} finally {
			if ( $zip instanceof \ZipArchive && $zip_opened ) {
				try {
					$zip->close();
				} catch ( \Throwable $e ) {
					$zip_close_error = self::describe_throwable( $e );
				}
			}

			if ( is_string( $zip_path ) && '' !== $zip_path && file_exists( $zip_path ) ) {
				@unlink( $zip_path );
			}
			$zip_cleanup_ok = ! is_string( $zip_path ) || '' === $zip_path || ! file_exists( $zip_path );
		}

		self::collect_failure(
			$failures,
			null === $zip_close_error && $zip_cleanup_ok,
			'live export parent closes and deletes the temporary streamed ZIP inspection copy',
			array(
				'zipOpened'    => $zip_opened,
				'closeError'   => $zip_close_error,
				'zipPath'      => self::preview( $zip_path ),
				'cleanupOk'    => $zip_cleanup_ok,
			)
		);

		return self::result(
			$ctx,
			'rest-site-editor.live-export',
			$failures,
			array(
				'case'              => self::case_summary( $case ),
				'exitCode'          => $run['exitCode'],
				'streamedBytes'     => $streamed_bytes,
				'headersObservable' => $headers_observable,
				'filterLog'         => $filter_log,
				'childPid'          => $child_pid,
			)
		);
	}

	private static function missing_live_edit_site_export_requirements(): array {
		$missing = array();

		if ( ! class_exists( 'ZipArchive' ) ) {
			$missing[] = 'class ZipArchive';
		}

		foreach (
			array(
				'base64_decode',
				'base64_encode',
				'json_decode',
				'json_encode',
				'proc_close',
				'proc_open',
				'random_bytes',
				'stream_get_contents',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}

		return $missing;
	}

	private static function run_child_live_edit_site_export( array $case ): array {
		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'component-fuzz-rest-site-editor-live-export';
		\ComponentFuzz\ensure_dir( $dir );

		$token    = getmypid() . '-' . bin2hex( random_bytes( 6 ) );
		$script   = $dir . DIRECTORY_SEPARATOR . 'live-export-child-' . $token . '.php';
		$metadata = $dir . DIRECTORY_SEPARATOR . 'live-export-child-' . $token . '.json';
		file_put_contents( $script, self::live_edit_site_export_child_program() );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates export(), which exits.
		$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			@unlink( $script );
			@unlink( $metadata );
			return array(
				'ok'               => false,
				'exitCode'         => -1,
				'stdout'           => '',
				'stderr'           => 'proc_open failed',
				'metadata'         => null,
				'metadataRaw'      => '',
				'metadataSource'   => 'none',
				'unexpectedOutput' => '',
				'metadataPath'     => $metadata,
			);
		}

		$payload = json_encode(
			array(
				'repoRoot'     => \ComponentFuzz\repo_root(),
				'case'         => $case,
				'metadataPath' => $metadata,
				'parentPid'    => getmypid(),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
		fwrite( $pipes[0], false === $payload ? '{}' : $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code    = proc_close( $process );
		$stdout_parse = self::parse_live_export_child_stdout( (string) $stdout );
		$metadata_raw = $stdout_parse['metadataRaw'];
		$result       = json_decode( $metadata_raw, true );
		$source       = 'stdout';

		if ( ! is_array( $result ) && file_exists( $metadata ) ) {
			$metadata_raw = (string) file_get_contents( $metadata );
			$result       = json_decode( $metadata_raw, true );
			$source       = 'sidecar';
		}

		@unlink( $script );
		@unlink( $metadata );

		return array(
			'ok'           => 0 === $exit_code && is_array( $result ) && ! empty( $result['ok'] ),
			'exitCode'     => $exit_code,
			'stdout'       => (string) $stdout,
			'stderr'       => (string) $stderr,
			'metadata'     => is_array( $result ) ? $result : null,
			'metadataRaw'  => $metadata_raw,
			'metadataSource' => $source,
			'unexpectedOutput' => $stdout_parse['unexpectedOutput'],
			'metadataPath' => $metadata,
		);
	}

	private static function parse_live_export_child_stdout( string $stdout ): array {
		if ( ! str_starts_with( $stdout, self::LIVE_EXPORT_STDOUT_SENTINEL ) ) {
			return array(
				'metadataRaw'       => '',
				'unexpectedOutput'  => $stdout,
			);
		}

		return array(
			'metadataRaw'      => substr( $stdout, strlen( self::LIVE_EXPORT_STDOUT_SENTINEL ) ),
			'unexpectedOutput' => '',
		);
	}

	private static function live_export_metadata_summary( array $metadata ): array {
		if ( isset( $metadata['zipBase64'] ) ) {
			$metadata['zipBase64'] = '[base64 bytes ' . strlen( (string) $metadata['zipBase64'] ) . ']';
		}

		return $metadata;
	}

	private static function write_live_export_stream_zip( string $bytes ): string {
		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'component-fuzz-rest-site-editor-live-export';
		\ComponentFuzz\ensure_dir( $dir );

		$path = $dir . DIRECTORY_SEPARATOR . 'streamed-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		file_put_contents( $path, $bytes );
		return $path;
	}

	private static function expected_export_template_filter_log(): array {
		return array(
			array(
				'type'  => 'wp_template',
				'query' => array(),
			),
			array(
				'type'  => 'wp_template_part',
				'query' => array(),
			),
		);
	}

	private static function header_value( array $headers, string $name ): ?string {
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) || ! str_contains( $header, ':' ) ) {
				continue;
			}

			list( $header_name, $value ) = explode( ':', $header, 2 );
			if ( 0 === strcasecmp( trim( $header_name ), $name ) ) {
				return trim( $value );
			}
		}

		return null;
	}

	private static function live_edit_site_export_child_program(): string {
		return <<<'PHP'
<?php
ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

function component_fuzz_rest_site_editor_private_call( string $method, array $args = array() ) {
	$callback = \Closure::bind(
		static function ( string $method, array $args ) {
			return \ComponentFuzz\Surfaces\RestSiteEditorSurface::$method( ...$args );
		},
		null,
		\ComponentFuzz\Surfaces\RestSiteEditorSurface::class
	);

	return $callback( $method, $args );
}

function component_fuzz_rest_site_editor_zip_files( string $temp_dir, string $theme_name ): array {
	$files = glob( rtrim( $temp_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $theme_name . '*.zip' );
	if ( false === $files ) {
		return array();
	}

	sort( $files );
	return array_map( 'basename', $files );
}

function component_fuzz_rest_site_editor_describe_value( $value ) {
	if ( $value instanceof \WP_Error ) {
		return array(
			'class'   => 'WP_Error',
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
		);
	}

	if ( is_object( $value ) ) {
		return array( 'class' => get_class( $value ) );
	}

	return $value;
}

function component_fuzz_rest_site_editor_write_metadata( string $metadata_path, array $metadata ): void {
	$json = json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	file_put_contents( $metadata_path, false === $json ? '{"ok":false,"error":"metadata json encode failed"}' : $json );
}

$component_fuzz_rest_site_editor_raw     = stream_get_contents( STDIN );
$component_fuzz_rest_site_editor_payload = json_decode( $component_fuzz_rest_site_editor_raw, true );

if (
	! is_array( $component_fuzz_rest_site_editor_payload )
	|| empty( $component_fuzz_rest_site_editor_payload['repoRoot'] )
	|| empty( $component_fuzz_rest_site_editor_payload['metadataPath'] )
	|| ! is_array( $component_fuzz_rest_site_editor_payload['case'] ?? null )
) {
	fwrite( STDERR, "Invalid Rest Site Editor live export payload.\n" );
	exit( 1 );
}

require_once $component_fuzz_rest_site_editor_payload['repoRoot'] . '/tools/component-fuzz/lib/autoload.php';

\ComponentFuzz\WpBootstrap::load();

$component_fuzz_rest_site_editor_case            = $component_fuzz_rest_site_editor_payload['case'];
$component_fuzz_rest_site_editor_metadata_path   = (string) $component_fuzz_rest_site_editor_payload['metadataPath'];
$component_fuzz_rest_site_editor_filter_log      = array();
$component_fuzz_rest_site_editor_filter          = null;
$component_fuzz_rest_site_editor_temp_paths      = array();
$component_fuzz_rest_site_editor_before_zip      = array();
$component_fuzz_rest_site_editor_theme_name      = '';
$component_fuzz_rest_site_editor_temp_dir        = '';
$component_fuzz_rest_site_editor_export_started  = false;
$component_fuzz_rest_site_editor_export_returned = false;
$component_fuzz_rest_site_editor_export_ob_level = null;
$component_fuzz_rest_site_editor_return_value    = null;
$component_fuzz_rest_site_editor_uncaught        = null;
$component_fuzz_rest_site_editor_metadata        = array(
	'ok'            => false,
	'pid'           => getmypid(),
	'parentPid'     => (int) ( $component_fuzz_rest_site_editor_payload['parentPid'] ?? 0 ),
	'exportStarted' => false,
	'exportReturned' => false,
);

register_shutdown_function(
	static function () use (
		&$component_fuzz_rest_site_editor_metadata,
		$component_fuzz_rest_site_editor_metadata_path,
		&$component_fuzz_rest_site_editor_filter_log,
		&$component_fuzz_rest_site_editor_filter,
		&$component_fuzz_rest_site_editor_temp_paths,
		&$component_fuzz_rest_site_editor_before_zip,
		&$component_fuzz_rest_site_editor_theme_name,
		&$component_fuzz_rest_site_editor_temp_dir,
		&$component_fuzz_rest_site_editor_export_started,
		&$component_fuzz_rest_site_editor_export_returned,
		&$component_fuzz_rest_site_editor_export_ob_level,
		&$component_fuzz_rest_site_editor_return_value,
		&$component_fuzz_rest_site_editor_uncaught
	): void {
		$streamed_zip = '';
		if (
			is_int( $component_fuzz_rest_site_editor_export_ob_level )
			&& ob_get_level() > $component_fuzz_rest_site_editor_export_ob_level
		) {
			$streamed_zip = (string) ob_get_clean();
		}

		if ( is_callable( $component_fuzz_rest_site_editor_filter ) && function_exists( 'remove_filter' ) ) {
			\remove_filter( 'pre_get_block_templates', $component_fuzz_rest_site_editor_filter, 10 );
		}

		$after_zip_files = '' === $component_fuzz_rest_site_editor_theme_name
			? array()
			: component_fuzz_rest_site_editor_zip_files(
				$component_fuzz_rest_site_editor_temp_dir,
				$component_fuzz_rest_site_editor_theme_name
			);

		$headers      = headers_list();
		$fatal_error  = error_get_last();
		$theme_path   = $component_fuzz_rest_site_editor_temp_paths[0] ?? null;
		$new_zip_file = array_values( array_diff( $after_zip_files, $component_fuzz_rest_site_editor_before_zip ) );

		$component_fuzz_rest_site_editor_metadata = array_merge(
			$component_fuzz_rest_site_editor_metadata,
			array(
				'ok'                              => $component_fuzz_rest_site_editor_export_started
					&& ! $component_fuzz_rest_site_editor_export_returned
					&& null === $component_fuzz_rest_site_editor_uncaught
					&& null === $fatal_error,
				'exportStarted'                   => $component_fuzz_rest_site_editor_export_started,
				'exportReturned'                  => $component_fuzz_rest_site_editor_export_returned,
				'returnValue'                     => component_fuzz_rest_site_editor_describe_value(
					$component_fuzz_rest_site_editor_return_value
				),
				'uncaught'                        => $component_fuzz_rest_site_editor_uncaught,
				'fatalError'                      => $fatal_error,
				'filterLog'                       => $component_fuzz_rest_site_editor_filter_log,
				'filterRemovedAtShutdown'         => is_callable( $component_fuzz_rest_site_editor_filter )
					? false === \has_filter( 'pre_get_block_templates', $component_fuzz_rest_site_editor_filter )
					: null,
				'beforeZipFiles'                  => $component_fuzz_rest_site_editor_before_zip,
				'afterZipFiles'                   => $after_zip_files,
				'newZipFilesAfterExport'          => $new_zip_file,
				'zipFilesRestored'                => $component_fuzz_rest_site_editor_before_zip === $after_zip_files,
				'headersObservable'               => array() !== $headers,
				'headers'                         => $headers,
				'zipBase64'                       => base64_encode( $streamed_zip ),
				'streamedBytes'                   => strlen( $streamed_zip ),
				'contentDirRemainingAtShutdown'   => defined( 'WP_CONTENT_DIR' ) ? file_exists( WP_CONTENT_DIR ) : null,
				'themeFixtureRemainingAtShutdown' => is_string( $theme_path ) ? file_exists( $theme_path ) : null,
				'obLevelAtShutdown'               => ob_get_level(),
			)
		);

		component_fuzz_rest_site_editor_write_metadata(
			$component_fuzz_rest_site_editor_metadata_path,
			$component_fuzz_rest_site_editor_metadata
		);
		echo "__COMPONENT_FUZZ_REST_SITE_EDITOR_EXPORT__\n";
		echo json_encode(
			$component_fuzz_rest_site_editor_metadata,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}
);

try {
	component_fuzz_rest_site_editor_private_call( 'load_endpoint_classes' );
	component_fuzz_rest_site_editor_private_call( 'prepare_runtime' );
	$component_fuzz_rest_site_editor_temp_paths = component_fuzz_rest_site_editor_private_call(
		'prepare_theme_fixture',
		array( $component_fuzz_rest_site_editor_case )
	);
	component_fuzz_rest_site_editor_private_call(
		'seed_fixtures',
		array( $component_fuzz_rest_site_editor_case )
	);

	if ( function_exists( 'update_option' ) ) {
		\update_option( 'current_theme', $component_fuzz_rest_site_editor_case['themeName'] ?? '' );
		\update_option( 'stylesheet', $component_fuzz_rest_site_editor_case['themeSlug'] ?? '' );
		\update_option( 'template', $component_fuzz_rest_site_editor_case['themeSlug'] ?? '' );
	}

	$component_fuzz_rest_site_editor_filter = component_fuzz_rest_site_editor_private_call(
		'install_export_template_filter',
		array( $component_fuzz_rest_site_editor_case, &$component_fuzz_rest_site_editor_filter_log )
	);

	$component_fuzz_rest_site_editor_theme_name = basename( \get_stylesheet() );
	$component_fuzz_rest_site_editor_temp_dir   = \get_temp_dir();
	$component_fuzz_rest_site_editor_before_zip = component_fuzz_rest_site_editor_zip_files(
		$component_fuzz_rest_site_editor_temp_dir,
		$component_fuzz_rest_site_editor_theme_name
	);

	component_fuzz_rest_site_editor_private_call( 'reset_runtime_caches' );

	$component_fuzz_rest_site_editor_controller     = new \WP_REST_Edit_Site_Export_Controller();
	$component_fuzz_rest_site_editor_export_started = true;
	$component_fuzz_rest_site_editor_export_ob_level = ob_get_level();
	ob_start();
	$component_fuzz_rest_site_editor_return_value   = $component_fuzz_rest_site_editor_controller->export();
	$component_fuzz_rest_site_editor_export_returned = true;

	exit( 2 );
} catch ( \Throwable $e ) {
	$component_fuzz_rest_site_editor_uncaught = array(
		'class'   => get_class( $e ),
		'message' => $e->getMessage(),
		'file'    => $e->getFile(),
		'line'    => $e->getLine(),
	);

	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
PHP;
	}

	private static function prepare_runtime(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'admin_email'   => 'admin@example.test',
					'blog_charset'  => 'UTF-8',
					'blogname'      => 'Component Fuzz',
					'home'          => 'http://example.test',
					'permalink_structure' => '',
					'siteurl'       => 'http://example.test',
					'stylesheet'    => 'component-fuzz-empty-theme',
					'template'      => 'component-fuzz-empty-theme',
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		$GLOBALS['_wp_post_type_features']    = array();
		$GLOBALS['post_type_meta_caps']       = array();
		$GLOBALS['wp_meta_keys']              = array();
		$GLOBALS['wp_post_statuses']          = array();
		$GLOBALS['wp_post_types']             = array();
		$GLOBALS['wp_registered_settings']    = array();
		$GLOBALS['wp_rest_additional_fields'] = array();
		$GLOBALS['wp_rest_server']            = new \WP_REST_Server();
		$GLOBALS['wp_rewrite']                = new \WP_Rewrite();
		$GLOBALS['wp_taxonomies']             = array();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz RestSiteEditor';
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/rest-site-editor/';

		self::reset_runtime_caches();
		self::reset_block_style_registry();
	}

	private static function reset_runtime_caches(): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			\wp_clean_theme_json_cache();
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			\wp_clean_themes_cache( false );
		}
	}

	private static function prepare_theme_fixture( array $case ): array {
		$theme_root = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'themes';
		$theme_dir  = $theme_root . DIRECTORY_SEPARATOR . $case['themeSlug'];
		$styles_dir = $theme_dir . DIRECTORY_SEPARATOR . 'styles';

		self::ensure_dir( $styles_dir );

		file_put_contents(
			$theme_dir . DIRECTORY_SEPARATOR . 'style.css',
			"/*\nTheme Name: " . $case['themeName'] . "\n*/\n"
		);
		file_put_contents(
			$theme_dir . DIRECTORY_SEPARATOR . 'theme.json',
			\wp_json_encode(
				array(
					'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => array(
						'color' => array(
							'palette' => array(
								array(
									'slug'  => 'fuzz-text',
									'color' => $case['textColor'],
									'name'  => 'Fuzz Text',
								),
							),
						),
					),
					'styles'   => array(
						'color' => array(
							'text' => $case['textColor'],
						),
					),
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		file_put_contents(
			$styles_dir . DIRECTORY_SEPARATOR . 'variation.json',
			\wp_json_encode(
				array(
					'version' => \WP_Theme_JSON::LATEST_SCHEMA,
					'title'   => $case['variationTitle'],
					'styles'  => array(
						'color' => array(
							'background' => $case['backgroundColor'],
						),
					),
				),
				JSON_UNESCAPED_SLASHES
			)
		);

		if ( function_exists( 'update_option' ) ) {
			\update_option( 'stylesheet', $case['themeSlug'] );
			\update_option( 'template', $case['themeSlug'] );
		}

		self::reset_runtime_caches();

		return array( $theme_dir );
	}

	private static function seed_fixtures( array $case ): array {
		$author = \wp_insert_user(
			array(
				'user_login'   => $case['authorLogin'],
				'user_email'   => $case['authorEmail'],
				'user_pass'    => 'component-fuzz-pass-' . $case['token'],
				'display_name' => $case['authorName'],
			)
		);
		if ( \is_wp_error( $author ) ) {
			throw new \RuntimeException( 'Could not create author fixture: ' . $author->get_error_code() );
		}
		$author = (int) $author;
		\wp_set_current_user( $author );

		$global_styles = self::insert_post_fixture(
			array(
				'post_author'  => $author,
				'post_content' => self::global_styles_content(
					$case['existingCustomCss'],
					$case['textColor'],
					array( 'custom' => false )
				),
				'post_name'    => 'wp-global-styles-' . $case['themeSlug'],
				'post_status'  => 'publish',
				'post_title'   => $case['globalStylesTitle'],
				'post_type'    => 'wp_global_styles',
			)
		);
		$global_revision = self::insert_post_fixture(
			array(
				'post_author'  => $author,
				'post_content' => self::global_styles_content(
					$case['globalStylesRevisionCss'],
					$case['backgroundColor'],
					array( 'custom' => true )
				),
				'post_name'    => $global_styles . '-revision-v1',
				'post_parent'  => $global_styles,
				'post_status'  => 'inherit',
				'post_title'   => $case['globalStylesRevisionTitle'],
				'post_type'    => 'revision',
			)
		);

		$template = self::insert_post_fixture(
			array(
				'post_author'  => $author,
				'post_content' => $case['templateContent'],
				'post_excerpt' => $case['templateDescription'],
				'post_name'    => $case['templateSlug'],
				'post_status'  => 'publish',
				'post_title'   => $case['templateTitle'],
				'post_type'    => 'wp_template',
			)
		);
		self::assign_template_theme_term( $template, $case['themeSlug'] );

		$template_revision = self::insert_post_fixture(
			array(
				'post_author'  => $author,
				'post_content' => $case['templateRevisionContent'],
				'post_excerpt' => $case['templateDescription'],
				'post_name'    => $template . '-revision-v1',
				'post_parent'  => $template,
				'post_status'  => 'inherit',
				'post_title'   => $case['templateRevisionTitle'],
				'post_type'    => 'revision',
			)
		);
		$template_autosave = self::insert_post_fixture(
			array(
				'post_author'  => $author,
				'post_content' => $case['templateAutosaveContent'],
				'post_excerpt' => $case['templateDescription'],
				'post_name'    => $template . '-autosave-v1',
				'post_parent'  => $template,
				'post_status'  => 'inherit',
				'post_title'   => $case['templateAutosaveTitle'],
				'post_type'    => 'revision',
			)
		);

		$template_part = self::insert_post_fixture(
			array(
				'post_author'  => $author,
				'post_content' => $case['templatePartContent'],
				'post_name'    => $case['templatePartSlug'],
				'post_status'  => 'publish',
				'post_title'   => $case['templatePartTitle'],
				'post_type'    => 'wp_template_part',
			)
		);
		self::assign_template_theme_term( $template_part, $case['themeSlug'] );

		$navigation = self::insert_post_fixture(
			array(
				'post_author'  => $author,
				'post_content' => $case['navigationContent'],
				'post_name'    => $case['navigationSlug'],
				'post_status'  => 'publish',
				'post_title'   => $case['navigationTitle'],
				'post_type'    => 'wp_navigation',
			)
		);

		return array(
			'author'               => $author,
			'globalStyles'         => $global_styles,
			'globalStylesRevision' => $global_revision,
			'navigation'           => $navigation,
			'template'             => $template,
			'templateAutosave'     => $template_autosave,
			'templatePart'         => $template_part,
			'templateRevision'     => $template_revision,
		);
	}

	private static function assign_template_theme_term( int $post_id, string $theme_slug ): void {
		$term = \wp_insert_term( $theme_slug, 'wp_theme' );
		if ( \is_wp_error( $term ) && 'term_exists' !== $term->get_error_code() ) {
			throw new \RuntimeException( 'Could not create wp_theme term: ' . $term->get_error_code() );
		}

		$assigned = \wp_set_object_terms( $post_id, $theme_slug, 'wp_theme' );
		if ( \is_wp_error( $assigned ) ) {
			throw new \RuntimeException( 'Could not assign wp_theme term: ' . $assigned->get_error_code() );
		}
	}

	private static function insert_post_fixture( array $postarr ): int {
		$postarr = array_merge(
			array(
				'post_date'         => '2026-06-23 10:00:00',
				'post_date_gmt'     => '2026-06-23 08:00:00',
				'post_modified'     => '2026-06-23 10:05:00',
				'post_modified_gmt' => '2026-06-23 08:05:00',
			),
			$postarr
		);

		$post_id = \wp_insert_post( $postarr, true, false );
		if ( \is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Could not create post fixture: ' . $post_id->get_error_code() );
		}

		return (int) $post_id;
	}

	private static function global_styles_content( string $css, string $text_color, array $settings ): string {
		return \wp_json_encode(
			array(
				'isGlobalStylesUserThemeJSON' => true,
				'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
				'styles'                      => array(
					'css'   => $css,
					'color' => array(
						'text' => $text_color,
					),
				),
				'settings'                    => array(
					'color' => $settings,
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
		);
	}

	private static function template_object( array $case, array $overrides = array() ): \WP_Block_Template {
		$template = new \WP_Block_Template();
		$values   = array_merge(
			array(
				'id'             => $case['templateId'],
				'theme'          => $case['themeSlug'],
				'content'        => $case['templateContent'],
				'slug'           => $case['templateSlug'],
				'source'         => 'custom',
				'origin'         => '',
				'type'           => 'wp_template',
				'description'    => $case['templateDescription'],
				'title'          => $case['templateTitle'],
				'status'         => 'publish',
				'wp_id'          => 0,
				'has_theme_file' => false,
				'is_custom'      => true,
				'author'         => 0,
				'area'           => '',
				'modified'       => '2026-06-23 10:05:00',
			),
			$overrides
		);

		foreach ( $values as $property => $value ) {
			$template->{$property} = $value;
		}

		return $template;
	}

	private static function install_template_filter( string $id, string $type, \WP_Block_Template $template ): callable {
		$filter = static function ( $block_template, $requested_id, $requested_type ) use ( $id, $type, $template ) {
			if ( $requested_id === $id && $requested_type === $type ) {
				return clone $template;
			}

			return $block_template;
		};

		\add_filter( 'pre_get_block_template', $filter, 10, 3 );
		return $filter;
	}

	private static function install_export_template_filter( array $case, array &$filter_log ): callable {
		$template = self::template_object(
			$case,
			array(
				'content' => self::export_template_content( $case ),
			)
		);
		$part     = self::template_object(
			$case,
			array(
				'id'      => $case['templatePartId'],
				'slug'    => $case['templatePartSlug'],
				'type'    => 'wp_template_part',
				'title'   => $case['templatePartTitle'],
				'area'    => 'header',
				'content' => $case['templatePartContent'],
			)
		);

		$filter = static function ( $block_templates, array $query, string $template_type ) use (
			$template,
			$part,
			&$filter_log
		) {
			$filter_log[] = array(
				'type'  => $template_type,
				'query' => $query,
			);

			if ( 'wp_template' === $template_type ) {
				return array( clone $template );
			}
			if ( 'wp_template_part' === $template_type ) {
				return array( clone $part );
			}

			return $block_templates;
		};

		\add_filter( 'pre_get_block_templates', $filter, 10, 3 );
		return $filter;
	}

	private static function export_template_content( array $case ): string {
		return '<!-- wp:template-part '
			. \wp_json_encode(
				array(
					'slug'  => $case['templatePartSlug'],
					'theme' => $case['themeSlug'],
					'area'  => 'header',
				),
				JSON_UNESCAPED_SLASHES
			)
			. ' /-->'
			. self::paragraph_block( 'Export template ' . $case['token'] );
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token            = substr( hash( 'sha1', self::NAME . ':' . $ctx->seed() ), 0, 10 );
		$theme_slug       = 'cfz-site-' . $token;
		$template_slug    = 'home-' . substr( $token, 0, 6 );
		$template_part    = 'header-' . substr( $token, 0, 6 );
		$navigation_slug  = 'nav-' . substr( $token, 0, 6 );
		$text_color       = self::color( $ctx->fork( 'text-color' ) );
		$background_color = self::color( $ctx->fork( 'background-color' ) );
		$dangerous_suffix = $ctx->choice( array( '</style>', '</Style ', '</style', '</sty', '</', '<' ) );

		return array(
			'authorEmail'               => 'rest-site-editor-' . $token . '@example.test',
			'authorLogin'               => 'cfz_site_editor_' . $token,
			'authorName'                => 'Site Editor Author ' . $ctx->int( 10, 999 ),
			'backgroundColor'           => $background_color,
			'createdTemplateContent'    => self::paragraph_block( 'Created template ' . $token ),
			'createdTemplateDescription' => 'Created template description ' . $token,
			'createdTemplateSlug'       => 'created-' . substr( $token, 0, 6 ),
			'createdTemplateTitle'      => 'Created Template ' . $ctx->int( 10, 999 ),
			'existingCustomCss'         => '.wp-site-blocks{color:' . $text_color . ';}',
			'globalStylesRevisionCss'   => 'body{background:' . $background_color . ';}',
			'globalStylesRevisionTitle' => 'Global Styles Revision ' . $ctx->int( 10, 999 ),
			'globalStylesTitle'         => 'Global Styles ' . $ctx->int( 10, 999 ),
			'globalStylesTitleUpdated'  => 'Updated Global Styles ' . $ctx->int( 10, 999 ),
			'invalidCustomCss'          => '.bad{color:red}' . $dangerous_suffix,
			'navigationContent'         => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
			'navigationId'              => 10000 + $ctx->int( 1, 5000 ),
			'navigationSlug'            => $navigation_slug,
			'navigationTitle'           => 'Navigation ' . $ctx->int( 10, 999 ),
			'templateAutosaveContent'   => self::paragraph_block( 'Autosave template ' . $token ),
			'templateAutosaveTitle'     => 'Autosave Template ' . $ctx->int( 10, 999 ),
			'templateContent'           => self::paragraph_block( 'Template content ' . $token ),
			'templateDescription'       => 'Template description ' . $token,
			'templateId'                => $theme_slug . '//' . $template_slug,
			'templatePartContent'       => '<!-- wp:group --><div class="wp-block-group">Header '
				. $token
				. '</div><!-- /wp:group -->',
			'templatePartId'            => $theme_slug . '//' . $template_part,
			'templatePartSlug'          => $template_part,
			'templatePartTitle'         => 'Header Part ' . $ctx->int( 10, 999 ),
			'templateRevisionContent'   => self::paragraph_block( 'Revision template ' . $token ),
			'templateRevisionTitle'     => 'Revision Template ' . $ctx->int( 10, 999 ),
			'templateSlug'              => $template_slug,
			'templateTitle'             => 'Template ' . $ctx->int( 10, 999 ),
			'textColor'                 => $text_color,
			'themeName'                 => 'Component Fuzz Site ' . $ctx->int( 10, 999 ),
			'themeSlug'                 => $theme_slug,
			'token'                     => $token,
			'validCustomCss'            => '.wp-site-blocks a{color:' . $text_color . ';}',
			'variationTitle'            => 'Variation ' . $ctx->int( 10, 999 ),
		);
	}

	private static function paragraph_block( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	private static function color( \ComponentFuzz\FuzzContext $ctx ): string {
		return sprintf( '#%02x%02x%02x', $ctx->int( 0, 255 ), $ctx->int( 0, 255 ), $ctx->int( 0, 255 ) );
	}

	private static function request(
		string $method,
		string $route,
		array $query_params = array(),
		array $url_params = array(),
		array $body_params = array()
	): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( array() !== $query_params ) {
			$request->set_query_params( $query_params );
		}
		if ( array() !== $url_params ) {
			$request->set_url_params( $url_params );
		}
		if ( array() !== $body_params ) {
			$request->set_body_params( $body_params );
		}
		return $request;
	}

	private static function invoke_method( object $object, string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invokeArgs( $object, $args );
	}

	private static function install_cap_filter( array $granted_caps ): callable {
		$granted_caps = array_fill_keys( $granted_caps, true );
		$filter       = static function ( array $allcaps, array $caps = array() ) use ( $granted_caps ): array {
			foreach ( $granted_caps as $cap => $grant ) {
				$allcaps[ $cap ] = $grant;
			}
			foreach ( $caps as $cap ) {
				if ( 'do_not_allow' !== $cap ) {
					$allcaps[ $cap ] = true;
				}
			}

			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, 10, 4 );
		return $filter;
	}

	private static function context_param_ok( array $params ): bool {
		if ( ! isset( $params['context'] ) || ! is_array( $params['context'] ) ) {
			return false;
		}

		$context  = $params['context'];
		$sanitize = $context['sanitize_callback'] ?? null;

		return 'view' === ( $context['default'] ?? null )
			&& is_callable( $sanitize )
			&& 'edit' === call_user_func( $sanitize, 'Edit!!' )
			&& in_array( 'view', $context['enum'] ?? array(), true )
			&& in_array( 'edit', $context['enum'] ?? array(), true );
	}

	private static function variation_present( array $variations, string $title ): bool {
		foreach ( $variations as $variation ) {
			if ( is_array( $variation ) && $title === ( $variation['title'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function route_exists( array $routes, string $prefix ): bool {
		foreach ( $routes as $route ) {
			if ( str_starts_with( $route, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private static function route_key_for_parts( array $routes, array $required_parts, array $excluded_parts = array() ): ?string {
		foreach ( array_keys( $routes ) as $route ) {
			foreach ( $required_parts as $part ) {
				if ( ! str_contains( $route, $part ) ) {
					continue 2;
				}
			}
			foreach ( $excluded_parts as $part ) {
				if ( str_contains( $route, $part ) ) {
					continue 2;
				}
			}
			return $route;
		}

		return null;
	}

	private static function route_methods( array $handlers ): array {
		$methods = array();
		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || ! is_array( $handler['methods'] ?? null ) ) {
				continue;
			}
			foreach ( $handler['methods'] as $method => $enabled ) {
				if ( $enabled ) {
					$methods[] = (string) $method;
				}
			}
		}

		$methods = array_values( array_unique( $methods ) );
		sort( $methods );

		return $methods;
	}

	private static function route_data_has_methods( ?array $data, array $expected_methods ): bool {
		if ( ! is_array( $data['methods'] ?? null ) ) {
			return false;
		}

		$methods = array_values( array_map( 'strval', $data['methods'] ) );
		sort( $methods );
		sort( $expected_methods );

		return $expected_methods === $methods;
	}

	private static function route_data_has_endpoint_args( ?array $data, array $methods, array $args ): bool {
		$endpoint = self::route_data_endpoint_for_methods( $data, $methods );
		if ( null === $endpoint || ! is_array( $endpoint['args'] ?? null ) ) {
			return false;
		}

		foreach ( $args as $arg ) {
			if ( ! array_key_exists( $arg, $endpoint['args'] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function route_data_schema_has_properties( ?array $data, array $properties ): bool {
		if ( ! is_array( $data['schema']['properties'] ?? null ) ) {
			return false;
		}

		foreach ( $properties as $property ) {
			if ( ! array_key_exists( $property, $data['schema']['properties'] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function route_data_endpoint_for_methods( ?array $data, array $methods ): ?array {
		if ( ! is_array( $data['endpoints'] ?? null ) ) {
			return null;
		}

		sort( $methods );
		foreach ( $data['endpoints'] as $endpoint ) {
			if ( ! is_array( $endpoint['methods'] ?? null ) ) {
				continue;
			}
			$endpoint_methods = array_values( array_map( 'strval', $endpoint['methods'] ) );
			sort( $endpoint_methods );
			if ( $methods === $endpoint_methods ) {
				return $endpoint;
			}
		}

		return null;
	}

	private static function matching_routes( array $routes ): array {
		$matched = array();
		foreach ( $routes as $route ) {
			if (
				str_contains( $route, 'global-styles' )
				|| str_contains( $route, 'templates' )
				|| str_contains( $route, 'template-parts' )
				|| str_contains( $route, 'navigation-fallback' )
				|| str_contains( $route, 'export' )
			) {
				$matched[] = $route;
			}
		}

		sort( $matched );
		return $matched;
	}

	private static function link_href( array $links, string $rel ): ?string {
		return $links[ $rel ][0]['href'] ?? null;
	}

	private static function response_header( \WP_REST_Response $response, string $name ): ?string {
		foreach ( $response->get_headers() as $header => $value ) {
			if ( 0 === strcasecmp( (string) $header, $name ) ) {
				return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}

		return null;
	}

	private static function term_names( int $post_id, string $taxonomy ): array {
		$terms = \get_the_terms( $post_id, $taxonomy );
		if ( \is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->name ) ) {
				$names[] = (string) $term->name;
			}
		}

		sort( $names );
		return $names;
	}

	private static function template_post_id_for_slug( string $theme_slug, string $slug, string $post_type ): int {
		$templates = \get_block_templates(
			array(
				'slug__in' => array( $slug ),
			),
			$post_type
		);

		foreach ( $templates as $template ) {
			if (
				$template instanceof \WP_Block_Template
				&& $theme_slug === $template->theme
				&& $slug === $template->slug
				&& (int) $template->wp_id > 0
			) {
				return (int) $template->wp_id;
			}
		}

		return 0;
	}

	private static function hook_log_contains( array $log, int $post_id, string $key, $expected ): bool {
		foreach ( $log as $entry ) {
			if (
				is_array( $entry )
				&& $post_id === (int) ( $entry['postId'] ?? 0 )
				&& array_key_exists( $key, $entry )
				&& $expected === $entry[ $key ]
			) {
				return true;
			}
		}

		return false;
	}

	private static function inspect_block_template_export_zip(
		\ZipArchive $zip,
		array $case,
		array &$failures
	): void {
		$template_entry      = 'templates/' . $case['templateSlug'] . '.html';
		$template_part_entry = 'parts/' . $case['templatePartSlug'] . '.html';
		$template_html       = $zip->getFromName( $template_entry );
		$template_part_html  = $zip->getFromName( $template_part_entry );
		$theme_json          = $zip->getFromName( 'theme.json' );
		$theme_data          = is_string( $theme_json ) ? json_decode( $theme_json, true ) : null;
		$zip_entries         = self::zip_entry_names( $zip );
		$unsafe_entries      = array_values(
			array_filter(
				$zip_entries,
				static function ( string $entry ): bool {
					return self::zip_entry_leaks_path( $entry );
				}
			)
		);

		self::collect_failure(
			$failures,
			false !== $zip->locateName( 'templates/' )
				&& false !== $zip->locateName( 'parts/' )
				&& false !== $zip->locateName( $template_entry )
				&& false !== $zip->locateName( $template_part_entry ),
			'export ZIP contains template and template-part directories and generated HTML entries',
			array(
				'templateEntry'     => $template_entry,
				'templatePartEntry' => $template_part_entry,
				'entries'           => $zip_entries,
			)
		);

		self::collect_failure(
			$failures,
			false !== $zip->locateName( 'style.css' )
				&& false !== $zip->locateName( 'theme.json' )
				&& false !== $zip->locateName( 'styles/variation.json' ),
			'export ZIP copies active theme fixture files',
			array(
				'styleCss'  => false !== $zip->locateName( 'style.css' ),
				'themeJson' => false !== $zip->locateName( 'theme.json' ),
				'variation' => false !== $zip->locateName( 'styles/variation.json' ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $template_html )
				&& str_contains( $template_html, '"slug":"' . $case['templatePartSlug'] . '"' )
				&& str_contains( $template_html, '"area":"header"' )
				&& ! str_contains( $template_html, '"theme":"' . $case['themeSlug'] . '"' )
				&& $case['templatePartContent'] === $template_part_html,
			'exported template HTML removes template-part theme attributes and preserves slug, area, and part content',
			array(
				'templateHtml'     => $template_html,
				'templatePartHtml' => $template_part_html,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $theme_data )
				&& 'https://schemas.wp.org/wp/' . substr( \wp_get_wp_version(), 0, 3 ) . '/theme.json' === ( $theme_data['$schema'] ?? null )
				&& \WP_Theme_JSON::LATEST_SCHEMA === ( $theme_data['version'] ?? null )
				&& $case['textColor'] === self::palette_color( $theme_data, 'fuzz-text' )
				&& $case['existingCustomCss'] === ( $theme_data['styles']['css'] ?? null )
				&& $case['textColor'] === ( $theme_data['styles']['color']['text'] ?? null ),
			'exported theme.json is valid merged JSON with schema, palette, and user style data',
			array(
				'themeJson' => $theme_data,
				'jsonError' => is_string( $theme_json ) ? json_last_error_msg() : 'missing theme.json',
			)
		);

		self::collect_failure(
			$failures,
			array() === $unsafe_entries,
			'export ZIP entries are relative paths that cannot escape the archive root',
			array( 'unsafeEntries' => $unsafe_entries )
		);
	}

	private static function zip_entry_names( \ZipArchive $zip ): array {
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== $name ) {
				$names[] = $name;
			}
		}

		sort( $names );
		return $names;
	}

	private static function zip_entry_leaks_path( string $entry ): bool {
		if ( '' === $entry || str_starts_with( $entry, '/' ) || str_contains( $entry, '\\' ) ) {
			return true;
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', $entry ) ) {
			return true;
		}

		return 1 === preg_match( '#^[A-Za-z]:/#', $entry );
	}

	private static function path_is_within( string $path, string $directory ): bool {
		$real_path      = realpath( $path );
		$real_directory = realpath( $directory );
		if ( false === $real_path || false === $real_directory ) {
			return false;
		}

		$real_path      = \wp_normalize_path( $real_path );
		$real_directory = rtrim( \wp_normalize_path( $real_directory ), '/' ) . '/';

		return str_starts_with( $real_path, $real_directory );
	}

	private static function palette_color( array $theme_data, string $slug ): ?string {
		foreach ( $theme_data['settings']['color']['palette'] ?? array() as $color ) {
			if ( is_array( $color ) && $slug === ( $color['slug'] ?? null ) ) {
				return $color['color'] ?? null;
			}
		}

		return null;
	}

	private static function param_summary( array $params ): array {
		$summary = array();
		foreach ( $params as $name => $param ) {
			$summary[ $name ] = array(
				'type'    => $param['type'] ?? null,
				'default' => $param['default'] ?? null,
				'enum'    => $param['enum'] ?? null,
			);
		}
		return $summary;
	}

	private static function route_summary( array $route ): array {
		return array(
			'methods'             => array_keys( $route['methods'] ?? array() ),
			'callback'            => self::callback_summary( $route['callback'] ?? null ),
			'permission_callback' => self::callback_summary( $route['permission_callback'] ?? null ),
		);
	}

	private static function callback_summary( $callback ): string {
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] )
				. '::'
				. (string) $callback[1];
		}

		if ( is_string( $callback ) ) {
			return $callback;
		}

		return gettype( $callback );
	}

	private static function collect_failure(
		array &$failures,
		bool $condition,
		string $label,
		array $details = array()
	): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function result(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		array $failures,
		array $data = array()
	): array {
		return self::row(
			$ctx,
			$invariant,
			array() === $failures,
			array_merge(
				$data,
				array(
					'failureCount' => count( $failures ),
					'failures'     => array_slice( $failures, 0, 8 ),
				)
			)
		);
	}

	private static function row(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		bool $ok,
		array $data = array(),
		?string $status = null
	): array {
		return array(
			'ok'        => $ok,
			'status'    => $status ?? ( $ok ? 'passed' : 'failed' ),
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'data'      => self::describe_value( $data ),
		);
	}

	private static function skip(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		string $reason,
		array $data = array()
	): array {
		$data['reason'] = $reason;
		return self::row( $ctx, $invariant, true, $data, 'skipped' );
	}

	private static function snapshot_state(): array {
		$block_style_registry = self::get_static_property( 'WP_Block_Styles_Registry', 'instance' );

		return array(
			'globals'                    => self::snapshot_globals(
				array(
					'_wp_post_type_features',
					'_wp_current_template_content',
					'_wp_current_template_id',
					'_wp_theme_features',
					'authordata',
					'current_user',
					'id',
					'post',
					'post_type_meta_caps',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_meta_keys',
					'wp_post_statuses',
					'wp_post_types',
					'wp_registered_settings',
					'wp_rest_additional_fields',
					'wp_rest_server',
					'wp_rewrite',
					'wp_taxonomies',
					'wp_theme_directories',
					'wp_stylesheet_path',
					'wp_template_path',
				)
			),
			'statics'                    => self::snapshot_static_properties(
				array(
					array( 'WP_Block_Templates_Registry', 'instance' ),
					array( 'WP_Theme', 'persistently_cache' ),
					array( 'WP_Theme', 'cache_expiration' ),
				)
			),
			'server'                     => self::snapshot_server(
				array(
					'HTTP_HOST',
					'HTTP_USER_AGENT',
					'REMOTE_ADDR',
					'REQUEST_URI',
				)
			),
			'wpdb'                       => self::snapshot_wpdb(),
			'blockStyleRegistry'         => $block_style_registry,
			'blockStyles'                => $block_style_registry instanceof \WP_Block_Styles_Registry
				? self::get_object_property( $block_style_registry, 'registered_block_styles' )
				: null,
			'blockStylesOutsideInitOnly' => $block_style_registry instanceof \WP_Block_Styles_Registry
				? self::get_object_property( $block_style_registry, 'registered_block_styles_outside_init' )
				: null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_wpdb( $snapshot['wpdb'] );
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		self::reset_runtime_caches();

		self::restore_globals( $snapshot['globals'] );
		self::restore_server( $snapshot['server'] );
		foreach ( $snapshot['statics'] as $entry ) {
			self::set_static_property( $entry['class'], $entry['property'], $entry['value'] );
		}
		if ( $snapshot['blockStyleRegistry'] instanceof \WP_Block_Styles_Registry ) {
			self::set_object_property( $snapshot['blockStyleRegistry'], 'registered_block_styles', $snapshot['blockStyles'] );
			if ( null !== $snapshot['blockStylesOutsideInitOnly'] ) {
				self::set_object_property(
					$snapshot['blockStyleRegistry'],
					'registered_block_styles_outside_init',
					$snapshot['blockStylesOutsideInitOnly']
				);
			}
			self::set_static_property( 'WP_Block_Styles_Registry', 'instance', $snapshot['blockStyleRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Styles_Registry', 'instance', null );
		}

		self::restore_wpdb( $snapshot['wpdb'] );
	}

	private static function state_fingerprint(): array {
		return array(
			'globals'     => self::stable_hash(
				self::summarize_for_hash(
					self::snapshot_globals(
						array(
							'_wp_post_type_features',
							'_wp_current_template_content',
							'_wp_current_template_id',
							'_wp_theme_features',
							'current_user',
							'post_type_meta_caps',
							'user_ID',
							'wp_filter',
							'wp_meta_keys',
							'wp_post_statuses',
							'wp_post_types',
							'wp_registered_settings',
							'wp_rest_additional_fields',
							'wp_taxonomies',
							'wp_theme_directories',
							'wp_stylesheet_path',
							'wp_template_path',
						)
					)
				)
			),
			'server'      => self::stable_hash(
				self::snapshot_server(
					array(
						'HTTP_HOST',
						'HTTP_USER_AGENT',
						'REMOTE_ADDR',
						'REQUEST_URI',
					)
				)
			),
			'wpdb'        => self::stable_hash( self::summarize_for_hash( self::snapshot_wpdb() ) ),
			'statics'     => self::stable_hash(
				self::summarize_for_hash(
					self::snapshot_static_properties(
						array(
							array( 'WP_Block_Templates_Registry', 'instance' ),
							array( 'WP_Theme', 'persistently_cache' ),
							array( 'WP_Theme', 'cache_expiration' ),
						)
					)
				)
			),
			'blockStyles' => self::stable_hash(
				self::summarize_for_hash(
					self::registry_property( 'WP_Block_Styles_Registry', 'registered_block_styles' )
				)
			),
		);
	}

	private static function snapshot_static_properties( array $properties ): array {
		$statics = array();
		foreach ( $properties as $property ) {
			if ( ! isset( $property[0], $property[1] ) || ! class_exists( $property[0] ) ) {
				continue;
			}

			$statics[ $property[0] . '::' . $property[1] ] = array(
				'class'    => $property[0],
				'property' => $property[1],
				'value'    => self::get_static_property( $property[0], $property[1] ),
			);
		}

		return $statics;
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
			);
		}
		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function snapshot_server( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => array_key_exists( $name, $_SERVER ) ? $_SERVER[ $name ] : null,
			);
		}
		return $snapshot;
	}

	private static function restore_server( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}
	}

	private static function snapshot_wpdb(): ?array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return null;
		}

		$wpdb       = $GLOBALS['wpdb'];
		$reflection = new \ReflectionClass( $wpdb );
		$state      = array(
			'public'  => array(
				'insert_id'     => $wpdb->insert_id,
				'last_error'    => $wpdb->last_error,
				'last_query'    => $wpdb->last_query,
				'num_rows'      => $wpdb->num_rows,
				'rows_affected' => $wpdb->rows_affected,
			),
			'private' => array(),
		);

		foreach ( $reflection->getProperties() as $property ) {
			$name = $property->getName();
			if ( str_starts_with( $name, 'component_fuzz_' ) ) {
				$state['private'][ $name ] = $property->getValue( $wpdb );
			}
		}

		return $state;
	}

	private static function restore_wpdb( ?array $snapshot ): void {
		if ( null === $snapshot || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return;
		}

		$wpdb = $GLOBALS['wpdb'];
		foreach ( $snapshot['public'] as $name => $value ) {
			$wpdb->{$name} = $value;
		}

		$reflection = new \ReflectionClass( $wpdb );
		foreach ( $snapshot['private'] as $name => $value ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}
			$property = $reflection->getProperty( $name );
			$property->setValue( $wpdb, $value );
		}
	}

	private static function reset_block_style_registry(): void {
		if ( ! class_exists( 'WP_Block_Styles_Registry' ) ) {
			return;
		}

		$registry = \WP_Block_Styles_Registry::get_instance();
		self::set_object_property( $registry, 'registered_block_styles', array() );
		if ( self::object_has_property( $registry, 'registered_block_styles_outside_init' ) ) {
			self::set_object_property( $registry, 'registered_block_styles_outside_init', array() );
		}
	}

	private static function registry_property( string $class, string $property ) {
		$instance = self::get_static_property( $class, 'instance' );
		if ( ! is_object( $instance ) || ! self::object_has_property( $instance, $property ) ) {
			return null;
		}

		return self::get_object_property( $instance, $property );
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$reflection = new \ReflectionClass( $class );
		if ( ! $reflection->hasProperty( $property ) ) {
			return null;
		}

		return $reflection->getProperty( $property )->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}

		$reflection = new \ReflectionClass( $class );
		if ( ! $reflection->hasProperty( $property ) ) {
			return;
		}

		$reflection->getProperty( $property )->setValue( null, $value );
	}

	private static function object_has_property( object $object, string $property ): bool {
		$reflection = new \ReflectionClass( $object );
		return $reflection->hasProperty( $property );
	}

	private static function get_object_property( object $object, string $property ) {
		$reflection = new \ReflectionClass( $object );
		if ( ! $reflection->hasProperty( $property ) ) {
			return null;
		}

		return $reflection->getProperty( $property )->getValue( $object );
	}

	private static function set_object_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionClass( $object );
		if ( ! $reflection->hasProperty( $property ) ) {
			return;
		}

		$reflection->getProperty( $property )->setValue( $object, $value );
	}

	private static function ensure_dir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create temp directory: ' . $dir );
		}
	}

	private static function cleanup_paths( array $paths ): array {
		$remaining = array();
		foreach ( $paths as $path ) {
			self::remove_dir_recursive( $path );
			if ( file_exists( $path ) ) {
				$remaining[] = $path;
			}
		}

		return array(
			'ok'        => array() === $remaining,
			'paths'     => array_map( array( self::class, 'preview' ), $paths ),
			'remaining' => array_map( array( self::class, 'preview' ), $remaining ),
		);
	}

	private static function remove_dir_recursive( string $dir ): void {
		if ( '' === $dir || ! file_exists( $dir ) ) {
			return;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			@unlink( $dir );
			return;
		}

		$items = scandir( $dir );
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				self::remove_dir_recursive( $dir . DIRECTORY_SEPARATOR . $item );
			}
		}
		@rmdir( $dir );
	}

	private static function first_difference( $before, $after, string $path = '' ) {
		if ( $before === $after ) {
			return null;
		}

		if ( is_array( $before ) && is_array( $after ) ) {
			$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
			foreach ( $keys as $key ) {
				$next_path = '' === $path ? (string) $key : $path . '.' . $key;
				if ( ! array_key_exists( $key, $before ) || ! array_key_exists( $key, $after ) ) {
					return array(
						'path'   => $next_path,
						'before' => array_key_exists( $key, $before ) ? $before[ $key ] : '[missing]',
						'after'  => array_key_exists( $key, $after ) ? $after[ $key ] : '[missing]',
					);
				}
				$diff = self::first_difference( $before[ $key ], $after[ $key ], $next_path );
				if ( null !== $diff ) {
					return $diff;
				}
			}
		}

		return array(
			'path'   => $path,
			'before' => self::describe_value( $before ),
			'after'  => self::describe_value( $after ),
		);
	}

	private static function stable_hash( $value ): ?string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? null : sha1( $json );
	}

	private static function summarize_for_hash( $value, int $depth = 0 ) {
		if ( $depth > 4 ) {
			return is_array( $value ) ? array( 'array' => count( $value ) ) : gettype( $value );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ (string) $key ] = self::summarize_for_hash( $item, $depth + 1 );
			}
			ksort( $out );
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \WP_Error ) {
				return array(
					'WP_Error' => array(
						'codes' => $value->get_error_codes(),
						'data'  => $value->get_all_error_data(),
					),
				);
			}

			if ( $value instanceof \WP_Post ) {
				return array(
					'WP_Post' => array(
						'ID'          => (int) $value->ID,
						'post_name'   => (string) $value->post_name,
						'post_parent' => (int) $value->post_parent,
						'post_type'   => (string) $value->post_type,
					),
				);
			}

			return array( 'object' => get_class( $value ) );
		}

		return $value;
	}

	private static function describe_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return self::describe_value( $post );
		}

		return array(
			'ID'           => (int) $post->ID,
			'post_author'  => (int) $post->post_author,
			'post_content' => self::preview( $post->post_content ),
			'post_excerpt' => self::preview( $post->post_excerpt ),
			'post_name'    => (string) $post->post_name,
			'post_parent'  => (int) $post->post_parent,
			'post_status'  => (string) $post->post_status,
			'post_title'   => (string) $post->post_title,
			'post_type'    => (string) $post->post_type,
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( $depth > 4 ) {
			return is_array( $value ) ? array( 'array' => count( $value ) ) : self::preview( $value );
		}

		if ( $value instanceof \WP_Error ) {
			return array(
				'WP_Error' => array(
					'codes'    => $value->get_error_codes(),
					'messages' => $value->get_error_messages(),
					'data'     => $value->get_all_error_data(),
				),
			);
		}

		if ( $value instanceof \WP_REST_Response ) {
			return array(
				'WP_REST_Response' => array(
					'status'  => $value->get_status(),
					'data'    => self::describe_value( $value->get_data(), $depth + 1 ),
					'headers' => $value->get_headers(),
					'links'   => $value->get_links(),
				),
			);
		}

		if ( $value instanceof \WP_Post ) {
			return array(
				'WP_Post' => array(
					'ID'          => (int) $value->ID,
					'post_name'   => (string) $value->post_name,
					'post_parent' => (int) $value->post_parent,
					'post_status' => (string) $value->post_status,
					'post_type'   => (string) $value->post_type,
				),
			);
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \stdClass ) {
				return self::describe_value( get_object_vars( $value ), $depth + 1 );
			}

			return array( 'object' => get_class( $value ) );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 40, true ) as $key => $item ) {
				$out[ $key ] = self::describe_value( $item, $depth + 1 );
			}
			if ( count( $value ) > 40 ) {
				$out['...'] = count( $value ) - 40;
			}
			return $out;
		}

		return self::preview( $value );
	}

	private static function preview( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > 220 ? substr( $value, 0, 220 ) . '...' : $value;
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		return gettype( $value );
	}

	private static function case_summary( array $case ): array {
		return array(
			'themeSlug'      => $case['themeSlug'],
			'templateId'     => $case['templateId'],
			'templatePartId' => $case['templatePartId'],
			'token'          => $case['token'],
		);
	}
}
