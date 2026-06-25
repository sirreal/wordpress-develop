<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes safe Site Editor REST controller paths without live DB or export side effects.
 */
final class RestSiteEditorSurface {
	public const NAME = 'rest-site-editor';

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
			$rows[] = self::check_template_revisions_autosaves( $ctx, $case, $fixtures );
			$rows[] = self::check_navigation_fallback_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_edit_site_export_controller( $ctx );
			$rows[] = self::check_block_templates_export_generator( $ctx, $case );
			$rows[] = self::skip(
				$ctx,
				'rest-site-editor.live-export.skipped',
				'WP_REST_Edit_Site_Export_Controller::export() generates a zip, streams it, unlinks it, and exits;'
					. ' this surface covers permission and route guards only.',
				array( 'controller' => 'WP_REST_Edit_Site_Export_Controller' )
			);
			$rows[] = self::skip(
				$ctx,
				'rest-site-editor.broad-template-dispatch.skipped',
				'Full REST dispatch and broad template collection queries are skipped because they can traverse'
					. ' theme/template CPT query paths outside the bounded fixtures.',
				array(
					'controllers' => array(
						'WP_REST_Templates_Controller',
						'WP_REST_Template_Revisions_Controller',
						'WP_REST_Template_Autosaves_Controller',
					),
				)
			);
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
				'add_filter',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'get_block_template',
				'get_post',
				'get_post_type_object',
				'get_stylesheet',
				'get_the_terms',
				'is_wp_error',
				'post_type_supports',
				'register_rest_route',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_ensure_response',
				'rest_get_route_for_post',
				'rest_sanitize_value_from_schema',
				'rest_url',
				'rest_validate_value_from_schema',
				'sanitize_title',
				'wp_cache_flush',
				'wp_clean_theme_json_cache',
				'wp_generate_block_templates_export_file',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_json_encode',
				'wp_set_current_user',
				'wp_set_object_terms',
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
				$opened = true === $zip->open( $filename );

				self::collect_failure(
					$failures,
					file_exists( $filename )
						&& filesize( $filename ) > 0
						&& self::path_is_within( $filename, \get_temp_dir() )
						&& $opened,
					'export ZIP is created inside the expected temp directory and can be opened',
					array(
						'filename' => self::preview( $filename ),
						'tempDir'  => self::preview( \get_temp_dir() ),
						'opened'   => $opened,
					)
				);

				if ( $opened ) {
					self::inspect_block_template_export_zip( $zip, $case, $failures );
				}
			}

			self::collect_failure(
				$failures,
				array(
					array(
						'type'  => 'wp_template',
						'query' => array(),
					),
					array(
						'type'  => 'wp_template_part',
						'query' => array(),
					),
				) === $filter_log,
				'export generator retrieves only the scoped template and template-part collections',
				array( 'filterLog' => $filter_log )
			);
		} finally {
			\remove_filter( 'pre_get_block_templates', $filter, 10 );
			if ( $zip instanceof \ZipArchive ) {
				$zip->close();
			}
			if ( is_string( $filename ) && '' !== $filename && file_exists( $filename ) ) {
				@unlink( $filename );
			}
		}

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
			'blockStyles' => self::stable_hash(
				self::summarize_for_hash(
					self::registry_property( 'WP_Block_Styles_Registry', 'registered_block_styles' )
				)
			),
		);
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
