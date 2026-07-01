<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes registry-backed WordPress REST endpoint controllers without live DB data.
 */
final class RestControllersSurface {
	public const NAME = 'rest-controllers';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_block_pattern_functions();
		self::load_block_renderer_controller();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'rest-controllers.bootstrap-apis-available',
					'Required WordPress REST controller APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot          = self::snapshot_state();
		$before_fingerprint = self::state_fingerprint();
		$rows              = array();
		$ob_level          = ob_get_level();

		try {
			self::reset_runtime_state();

			$rows[] = self::check_post_types_controller( $ctx );
			$rows[] = self::check_post_statuses_controller( $ctx );
			$rows[] = self::check_taxonomies_controller( $ctx );
			$rows[] = self::check_additional_fields_controller_callbacks( $ctx );
			$rows[] = self::check_namespace_route_contracts( $ctx );
			$rows[] = self::check_settings_controller( $ctx );
			$rows[] = self::check_block_types_controller( $ctx );
			$rows[] = self::check_block_renderer_controller( $ctx->fork( 'block-renderer' ) );
			$rows[] = self::check_block_patterns_controller( $ctx );
			$rows[] = self::check_block_pattern_remote_loaders( $ctx );
			$rows[] = self::check_block_pattern_theme_file_loader( $ctx );
			$rows[] = self::check_search_controller( $ctx );
			$rows[] = self::check_route_registry_behavior( $ctx );
			$rows[] = self::check_plugin_theme_controller_contracts( $ctx->fork( 'plugin-theme-controllers' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'rest-controllers.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}

			self::restore_state( $snapshot );

			$after_fingerprint = self::state_fingerprint();
			$rows[]            = self::row(
				$ctx,
				'rest-controllers.state-restored',
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

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Block_Pattern_Categories_Registry',
				'WP_Block_Patterns_Registry',
				'WP_Block_Styles_Registry',
				'WP_Block_Type',
				'WP_Block_Type_Registry',
				'WP_Error',
				'WP_Post_Type',
				'WP_REST_Block_Pattern_Categories_Controller',
				'WP_REST_Block_Patterns_Controller',
				'WP_REST_Block_Renderer_Controller',
				'WP_REST_Block_Types_Controller',
				'WP_REST_Controller',
				'WP_REST_Plugins_Controller',
				'WP_REST_Post_Format_Search_Handler',
				'WP_REST_Post_Statuses_Controller',
				'WP_REST_Post_Search_Handler',
				'WP_REST_Post_Types_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Search_Controller',
				'WP_REST_Search_Handler',
				'WP_REST_Server',
				'WP_REST_Settings_Controller',
				'WP_REST_Taxonomies_Controller',
				'WP_REST_Term_Search_Handler',
				'WP_REST_Themes_Controller',
				'WP_Taxonomy',
				'WP_Theme',
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
				'add_theme_support',
				'add_filter',
				'add_query_arg',
				'apply_filters',
				'current_user_can',
				'delete_site_transient',
				'delete_option',
				'get_file_data',
				'get_theme_support',
				'get_registered_theme_features',
				'get_object_taxonomies',
				'get_option',
				'get_dynamic_block_names',
				'get_post',
				'get_post_format_link',
				'get_post_format_string',
				'get_post_format_strings',
				'get_post_stati',
				'get_post_status_object',
				'get_post_type_object',
				'get_post_types',
				'get_registered_settings',
				'get_site_transient',
				'get_taxonomies',
				'get_taxonomy',
				'get_the_title',
				'has_filter',
				'is_post_type_viewable',
				'is_wp_error',
				'plugin_basename',
				'register_block_style',
				'register_block_pattern',
				'register_block_type',
				'register_post_status',
				'register_post_type',
				'register_rest_field',
				'register_rest_route',
				'register_setting',
				'register_taxonomy',
				'remove_filter',
				'render_block',
				'rest_authorization_required_code',
				'rest_default_additional_properties_to_false',
				'rest_do_request',
				'rest_ensure_response',
				'rest_filter_response_by_context',
				'rest_get_route_for_post_type_items',
				'rest_get_route_for_taxonomy_items',
				'rest_is_field_included',
				'rest_parse_request_arg',
				'rest_sanitize_value_from_schema',
				'rest_url',
				'rest_validate_request_arg',
				'rest_validate_value_from_schema',
				'sanitize_key',
				'sanitize_title',
				'sanitize_text_field',
				'serialize_blocks',
				'setup_postdata',
				'set_site_transient',
				'trailingslashit',
				'unregister_block_type',
				'update_option',
				'validate_file',
				'wp_cache_delete',
				'urlencode_deep',
				'_register_theme_block_patterns',
				'wp_clean_theme_json_cache',
				'wp_get_active_and_valid_themes',
				'wp_get_theme',
				'wp_is_development_mode',
				'wp_json_encode',
				'wp_parse_args',
				'wp_parse_list',
				'wp_parse_slug_list',
				'wp_theme_has_theme_json',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function load_block_pattern_functions(): void {
		if ( function_exists( 'register_block_pattern' ) || ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		$file = ABSPATH . WPINC . '/block-patterns.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	private static function load_block_renderer_controller(): void {
		if ( class_exists( 'WP_REST_Block_Renderer_Controller' ) || ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		$file = ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-block-renderer-controller.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	private static function check_post_types_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::post_type_case( $ctx->fork( 'post-types' ) );
		$controller = new \WP_REST_Post_Types_Controller();
		$failures   = array();
		$additional_field_calls = array();

		$registered = \register_post_type(
			$case['postType'],
			array(
				'label'          => $case['label'],
				'description'    => $case['description'],
				'public'         => true,
				'show_in_rest'   => true,
				'rest_base'      => $case['restBase'],
				'rest_namespace' => 'wp/v2',
				'rewrite'        => false,
				'query_var'      => false,
				'show_ui'        => true,
				'supports'       => array( 'title', 'editor', 'thumbnail' ),
				'menu_icon'      => 'dashicons-media-text',
			)
		);
		$hidden     = \register_post_type(
			$case['hiddenPostType'],
			array(
				'label'        => 'Hidden ' . $case['label'],
				'public'       => true,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);

		\register_taxonomy(
			$case['taxonomy'],
			$case['postType'],
			array(
				'label'        => 'Visible ' . $case['label'],
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => $case['taxonomyRestBase'],
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		\register_taxonomy(
			$case['hiddenTaxonomy'],
			$case['postType'],
			array(
				'label'        => 'Hidden ' . $case['label'],
				'public'       => true,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		\register_rest_field(
			'type',
			$case['additionalField'],
			array(
				'get_callback' => static function ( array $prepared, string $field_name, \WP_REST_Request $request, string $object_type ) use ( $case, &$additional_field_calls ): string {
					$additional_field_calls[] = array(
						'field'      => $field_name,
						'objectType' => $object_type,
						'context'    => $request['context'],
						'slug'       => $prepared['slug'] ?? null,
					);
					return $case['additionalValue'];
				},
				'schema'       => array(
					'description' => 'Generated REST controller type field.',
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);

		self::collect_failure(
			$failures,
			$registered instanceof \WP_Post_Type && $hidden instanceof \WP_Post_Type,
			'post type fixtures register without DB writes',
			array( 'case' => $case, 'registered' => $registered, 'hidden' => $hidden )
		);

		$params = $controller->get_collection_params();
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params ) && array( 'context' ) === array_keys( $params ),
			'post type collection params expose sanitized view context only',
			array( 'params' => self::param_summary( $params ) )
		);

		$view_items = $controller->get_items( self::request( 'GET', '/wp/v2/types', array( 'context' => 'view' ) ) );
		$view_data  = $view_items instanceof \WP_REST_Response ? $view_items->get_data() : array();
		self::collect_failure(
			$failures,
			$view_items instanceof \WP_REST_Response
				&& isset( $view_data[ $case['postType'] ] )
				&& ! isset( $view_data[ $case['hiddenPostType'] ] )
				&& $case['restBase'] === ( $view_data[ $case['postType'] ]['rest_base'] ?? null )
				&& ! array_key_exists( 'capabilities', $view_data[ $case['postType'] ] )
				&& ! array_key_exists( $case['additionalField'], $view_data[ $case['postType'] ] )
				&& in_array( $case['taxonomy'], $view_data[ $case['postType'] ]['taxonomies'] ?? array(), true )
				&& ! in_array( $case['hiddenTaxonomy'], $view_data[ $case['postType'] ]['taxonomies'] ?? array(), true ),
			'post type collection returns only show_in_rest types and view-context fields',
			array( 'case' => $case, 'viewData' => $view_data )
		);

		$cap_filter = self::install_cap_filter( array( $registered instanceof \WP_Post_Type ? $registered->cap->edit_posts : 'edit_posts' ) );
		try {
			$edit_item = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/types/' . $case['postType'],
					array( 'context' => 'edit' ),
					array( 'type' => $case['postType'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$edit_data = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		$links     = $edit_item instanceof \WP_REST_Response ? $edit_item->get_links() : array();
		$schema    = $controller->get_item_schema();
		$additional_schema_valid = isset( $schema['properties'][ $case['additionalField'] ] )
			&& true === \rest_validate_value_from_schema(
				$edit_data[ $case['additionalField'] ] ?? null,
				$schema['properties'][ $case['additionalField'] ],
				$case['additionalField']
			);
		self::collect_failure(
			$failures,
			$edit_item instanceof \WP_REST_Response
				&& isset( $edit_data['capabilities'], $edit_data['supports'], $edit_data['visibility'] )
				&& $additional_schema_valid
				&& $case['additionalValue'] === ( $edit_data[ $case['additionalField'] ] ?? null )
				&& $case['postType'] === ( $edit_data['slug'] ?? null )
				&& $case['restBase'] === ( $edit_data['rest_base'] ?? null )
				&& array(
					array(
						'field'      => $case['additionalField'],
						'objectType' => 'type',
						'context'    => 'edit',
						'slug'       => $case['postType'],
					),
				) === $additional_field_calls
				&& \rest_url( '/wp/v2/types' ) === self::link_href( $links, 'collection' )
				&& \rest_url( '/wp/v2/' . $case['restBase'] ) === self::link_href( $links, 'https://api.w.org/items' ),
			'post type item edit response exposes edit-context fields and stable rest_base links',
			array(
				'case'                 => $case,
				'editData'             => $edit_data,
				'links'                => $links,
				'additionalFieldCalls' => $additional_field_calls,
				'additionalSchema'     => $schema['properties'][ $case['additionalField'] ] ?? null,
			)
		);

		$embed_item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/types/' . $case['postType'],
				array( 'context' => 'embed' ),
				array( 'type' => $case['postType'] )
			)
		);
		$embed_data = $embed_item instanceof \WP_REST_Response ? $embed_item->get_data() : array();
		self::collect_failure(
			$failures,
			$embed_item instanceof \WP_REST_Response
				&& isset( $embed_data['name'], $embed_data['slug'], $embed_data['rest_base'] )
				&& ! isset( $embed_data['description'], $embed_data['capabilities'], $embed_data['supports'], $embed_data['taxonomies'], $embed_data[ $case['additionalField'] ] ),
			'post type embed context filters view/edit-only schema fields',
			array( 'embedData' => $embed_data )
		);

		$head_items = $controller->get_items( self::request( 'HEAD', '/wp/v2/types', array( 'context' => 'view' ) ) );
		$hidden_item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/types/' . $case['hiddenPostType'],
				array( 'context' => 'view' ),
				array( 'type' => $case['hiddenPostType'] )
			)
		);
		$missing_item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/types/missing',
				array( 'context' => 'view' ),
				array( 'type' => 'missing-' . $case['postType'] )
			)
		);
		self::collect_failure(
			$failures,
			$head_items instanceof \WP_REST_Response
				&& array() === $head_items->get_data()
				&& self::wp_error_ok( $hidden_item, 'rest_cannot_read_type', \rest_authorization_required_code() )
				&& self::wp_error_ok( $missing_item, 'rest_type_invalid', 404 ),
			'post type HEAD and invalid item paths return represented results',
			array(
				'headData'      => $head_items instanceof \WP_REST_Response ? $head_items->get_data() : $head_items,
				'hiddenError'   => $hidden_item,
				'missingError'  => $missing_item,
			)
		);

		return self::row(
			$ctx,
			'rest-controllers.post-types.registry-context-links',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_statuses_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::post_type_case( $ctx->fork( 'statuses' ) );
		$controller = new \WP_REST_Post_Statuses_Controller();
		$failures   = array();

		$post_type = \register_post_type(
			'post',
			array(
				'label'        => 'Posts',
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'posts',
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		\register_post_status( 'trash', array( 'label' => 'Trash', 'internal' => true ) );
		\register_post_status( $case['publicStatus'], array( 'label' => $case['publicStatusLabel'], 'public' => true, 'date_floating' => $case['dateFloating'] ) );
		\register_post_status( $case['privateStatus'], array( 'label' => $case['privateStatusLabel'], 'private' => true ) );
		\register_post_status( $case['hiddenStatus'], array( 'label' => $case['hiddenStatusLabel'], 'internal' => true ) );

		$params = $controller->get_collection_params();
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params ) && array( 'context' ) === array_keys( $params ),
			'post status collection params expose sanitized view context only',
			array( 'params' => self::param_summary( $params ) )
		);

		$view_items = $controller->get_items( self::request( 'GET', '/wp/v2/statuses', array( 'context' => 'view' ) ) );
		$view_data  = $view_items instanceof \WP_REST_Response ? $view_items->get_data() : array();
		self::collect_failure(
			$failures,
			$view_items instanceof \WP_REST_Response
				&& isset( $view_data[ $case['publicStatus'] ] )
				&& ! isset( $view_data[ $case['privateStatus'] ], $view_data[ $case['hiddenStatus'] ], $view_data['trash'] )
				&& ! array_key_exists( 'private', $view_data[ $case['publicStatus'] ] )
				&& $case['publicStatus'] === ( $view_data[ $case['publicStatus'] ]['slug'] ?? null ),
			'post status collection exposes public statuses to view context without edit fields',
			array( 'viewData' => $view_data )
		);

		$denied = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/statuses', array( 'context' => 'edit' ) ) );
		$cap_filter = self::install_cap_filter( array( $post_type instanceof \WP_Post_Type ? $post_type->cap->edit_posts : 'edit_posts' ) );
		try {
			$allowed    = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/statuses', array( 'context' => 'edit' ) ) );
			$edit_items = $controller->get_items( self::request( 'GET', '/wp/v2/statuses', array( 'context' => 'edit' ) ) );
			$edit_item  = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/statuses/' . $case['privateStatus'],
					array( 'context' => 'edit' ),
					array( 'status' => $case['privateStatus'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$edit_data      = $edit_items instanceof \WP_REST_Response ? $edit_items->get_data() : array();
		$edit_item_data = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		self::collect_failure(
			$failures,
			self::wp_error_ok( $denied, 'rest_cannot_view', \rest_authorization_required_code() )
				&& true === $allowed
				&& $edit_items instanceof \WP_REST_Response
				&& isset( $edit_data[ $case['publicStatus'] ], $edit_data[ $case['privateStatus'] ], $edit_data['trash'] )
				&& ! isset( $edit_data[ $case['hiddenStatus'] ] )
				&& $edit_item instanceof \WP_REST_Response
				&& isset( $edit_item_data['private'], $edit_item_data['show_in_list'] )
				&& true === $edit_item_data['private'],
			'post status edit permission gates non-public statuses and edit-context fields',
			array(
				'denied'       => $denied,
				'allowed'      => $allowed,
				'editData'     => $edit_data,
				'editItemData' => $edit_item_data,
			)
		);

		$hidden_permission = $controller->get_item_permissions_check(
			self::request(
				'GET',
				'/wp/v2/statuses/' . $case['hiddenStatus'],
				array( 'context' => 'view' ),
				array( 'status' => $case['hiddenStatus'] )
			)
		);
		$missing_item      = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/statuses/missing',
				array( 'context' => 'view' ),
				array( 'status' => 'missing-' . $case['publicStatus'] )
			)
		);
		self::collect_failure(
			$failures,
			self::wp_error_ok( $hidden_permission, 'rest_cannot_read_status', \rest_authorization_required_code() )
				&& self::wp_error_ok( $missing_item, 'rest_status_invalid', 404 ),
			'post status hidden and invalid values return WP_Error without throwing',
			array( 'hiddenPermission' => $hidden_permission, 'missingItem' => $missing_item )
		);

		return self::row(
			$ctx,
			'rest-controllers.post-statuses.visibility-permissions',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_taxonomies_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::taxonomy_case( $ctx->fork( 'taxonomies' ) );
		$controller = new \WP_REST_Taxonomies_Controller();
		$failures   = array();
		$additional_field_calls = array();

		$post_type = \register_post_type(
			$case['postType'],
			array(
				'label'        => $case['postType'],
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		\register_post_type(
			$case['otherPostType'],
			array(
				'label'        => $case['otherPostType'],
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		$registered = \register_taxonomy(
			$case['taxonomy'],
			$case['postType'],
			array(
				'label'             => $case['label'],
				'description'       => $case['description'],
				'public'            => true,
				'show_in_rest'      => true,
				'rest_base'         => $case['restBase'],
				'rest_namespace'    => 'wp/v2',
				'hierarchical'      => $case['hierarchical'],
				'show_admin_column' => true,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
		\register_taxonomy(
			$case['hiddenTaxonomy'],
			$case['postType'],
			array(
				'label'        => 'Hidden ' . $case['label'],
				'public'       => true,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		\register_taxonomy(
			$case['otherTaxonomy'],
			$case['otherPostType'],
			array(
				'label'        => 'Other ' . $case['label'],
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		\register_rest_field(
			'taxonomy',
			$case['additionalField'],
			array(
				'get_callback' => static function ( array $prepared, string $field_name, \WP_REST_Request $request, string $object_type ) use ( $case, &$additional_field_calls ): string {
					$additional_field_calls[] = array(
						'field'      => $field_name,
						'objectType' => $object_type,
						'context'    => $request['context'],
						'slug'       => $prepared['slug'] ?? null,
					);
					return $case['additionalValue'];
				},
				'schema'       => array(
					'description' => 'Generated REST controller taxonomy field.',
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);

		self::collect_failure(
			$failures,
			$post_type instanceof \WP_Post_Type && $registered instanceof \WP_Taxonomy,
			'taxonomy fixtures register without DB writes',
			array( 'case' => $case, 'postType' => $post_type, 'taxonomy' => $registered )
		);

		$params = $controller->get_collection_params();
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& isset( $params['type'] )
				&& 'string' === ( $params['type']['type'] ?? null ),
			'taxonomy collection params expose sanitized context and string type filter',
			array( 'params' => self::param_summary( $params ) )
		);

		$view_items = $controller->get_items( self::request( 'GET', '/wp/v2/taxonomies', array( 'context' => 'view' ) ) );
		$view_data  = $view_items instanceof \WP_REST_Response ? $view_items->get_data() : array();
		$type_items = $controller->get_items(
			self::request(
				'GET',
				'/wp/v2/taxonomies',
				array( 'context' => 'view', 'type' => $case['postType'] )
			)
		);
		$type_data  = $type_items instanceof \WP_REST_Response ? $type_items->get_data() : array();
		self::collect_failure(
			$failures,
			$view_items instanceof \WP_REST_Response
				&& isset( $view_data[ $case['taxonomy'] ], $view_data[ $case['otherTaxonomy'] ] )
				&& ! isset( $view_data[ $case['hiddenTaxonomy'] ] )
				&& $type_items instanceof \WP_REST_Response
				&& isset( $type_data[ $case['taxonomy'] ] )
				&& ! isset( $type_data[ $case['otherTaxonomy'] ], $type_data[ $case['hiddenTaxonomy'] ] )
				&& $case['restBase'] === ( $type_data[ $case['taxonomy'] ]['rest_base'] ?? null )
				&& ! array_key_exists( $case['additionalField'], $type_data[ $case['taxonomy'] ] )
				&& ! array_key_exists( 'capabilities', $type_data[ $case['taxonomy'] ] ),
			'taxonomy collection filters hidden taxonomies and optional object type',
			array( 'viewData' => $view_data, 'typeData' => $type_data )
		);

		$cap_filter = self::install_cap_filter( array( $registered instanceof \WP_Taxonomy ? $registered->cap->assign_terms : 'assign_terms' ) );
		try {
			$edit_item = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/taxonomies/' . $case['taxonomy'],
					array( 'context' => 'edit' ),
					array( 'taxonomy' => $case['taxonomy'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$edit_data = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		$links     = $edit_item instanceof \WP_REST_Response ? $edit_item->get_links() : array();
		$schema    = $controller->get_item_schema();
		$additional_schema_valid = isset( $schema['properties'][ $case['additionalField'] ] )
			&& true === \rest_validate_value_from_schema(
				$edit_data[ $case['additionalField'] ] ?? null,
				$schema['properties'][ $case['additionalField'] ],
				$case['additionalField']
			);
		self::collect_failure(
			$failures,
			$edit_item instanceof \WP_REST_Response
				&& isset( $edit_data['capabilities'], $edit_data['labels'], $edit_data['visibility'], $edit_data['show_cloud'] )
				&& $additional_schema_valid
				&& $case['additionalValue'] === ( $edit_data[ $case['additionalField'] ] ?? null )
				&& $case['taxonomy'] === ( $edit_data['slug'] ?? null )
				&& $case['restBase'] === ( $edit_data['rest_base'] ?? null )
				&& array( $case['postType'] ) === ( $edit_data['types'] ?? null )
				&& array(
					array(
						'field'      => $case['additionalField'],
						'objectType' => 'taxonomy',
						'context'    => 'edit',
						'slug'       => $case['taxonomy'],
					),
				) === $additional_field_calls
				&& \rest_url( '/wp/v2/taxonomies' ) === self::link_href( $links, 'collection' )
				&& \rest_url( '/wp/v2/' . $case['restBase'] ) === self::link_href( $links, 'https://api.w.org/items' ),
			'taxonomy edit item exposes edit fields and stable rest_base links',
			array(
				'editData'             => $edit_data,
				'links'                => $links,
				'additionalFieldCalls' => $additional_field_calls,
				'additionalSchema'     => $schema['properties'][ $case['additionalField'] ] ?? null,
			)
		);

		$embed_item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/taxonomies/' . $case['taxonomy'],
				array( 'context' => 'embed' ),
				array( 'taxonomy' => $case['taxonomy'] )
			)
		);
		$embed_data = $embed_item instanceof \WP_REST_Response ? $embed_item->get_data() : array();
		$hidden_permission = $controller->get_item_permissions_check(
			self::request(
				'GET',
				'/wp/v2/taxonomies/' . $case['hiddenTaxonomy'],
				array( 'context' => 'view' ),
				array( 'taxonomy' => $case['hiddenTaxonomy'] )
			)
		);
		$missing_item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/taxonomies/missing',
				array( 'context' => 'view' ),
				array( 'taxonomy' => 'missing-' . $case['taxonomy'] )
			)
		);
		self::collect_failure(
			$failures,
			$embed_item instanceof \WP_REST_Response
				&& isset( $embed_data['name'], $embed_data['slug'], $embed_data['rest_base'] )
				&& ! isset( $embed_data['description'], $embed_data['capabilities'], $embed_data['types'], $embed_data['visibility'], $embed_data[ $case['additionalField'] ] )
				&& false === $hidden_permission
				&& self::wp_error_ok( $missing_item, 'rest_taxonomy_invalid', 404 ),
			'taxonomy embed context and invalid item paths are represented',
			array( 'embedData' => $embed_data, 'hiddenPermission' => $hidden_permission, 'missingItem' => $missing_item )
		);

		return self::row(
			$ctx,
			'rest-controllers.taxonomies.registry-context-links',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_additional_fields_controller_callbacks( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case         = self::additional_field_case( $ctx->fork( 'additional-fields' ) );
		$controller   = new \WP_REST_Post_Types_Controller();
		$failures     = array();
		$get_calls    = array();
		$update_calls = array();

		$registered = \register_post_type(
			$case['postType'],
			array(
				'label'        => $case['label'],
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => $case['restBase'],
				'rewrite'      => false,
				'query_var'    => false,
				'show_ui'      => true,
			)
		);

		\register_rest_field(
			'type',
			$case['field'],
			array(
				'get_callback'    => static function ( array $prepared, string $field_name, \WP_REST_Request $request, string $object_type ) use ( $case, &$get_calls ): array {
					$get_calls[] = array(
						'field'      => $field_name,
						'objectType' => $object_type,
						'context'    => $request['context'],
						'slug'       => $prepared['slug'] ?? null,
					);

					return array(
						'label'  => $case['readLabel'],
						'nested' => array(
							'editOnly' => $case['nestedEdit'],
							'viewOnly' => $case['nestedView'],
						),
					);
				},
				'update_callback' => static function ( $value, object $object, string $field_name, \WP_REST_Request $request, string $object_type ) use ( $case, &$update_calls ) {
					$update_calls[] = array(
						'field'      => $field_name,
						'objectType' => $object_type,
						'context'    => $request['context'],
						'objectName' => $object->name ?? null,
						'value'      => $value,
					);

					if ( is_array( $value ) && $case['invalidUpdateLabel'] === ( $value['label'] ?? null ) ) {
						return new \WP_Error(
							'rest_component_fuzz_additional_field_rejected',
							'Generated REST additional field rejected.',
							array( 'status' => 400 )
						);
					}

					return true;
				},
				'schema'          => array(
					'description'          => 'Generated REST controller additional field.',
					'type'                 => 'object',
					'context'              => array( 'edit' ),
					'properties'           => array(
						'label'  => array(
							'type'    => 'string',
							'context' => array( 'edit' ),
						),
						'nested' => array(
							'type'                 => 'object',
							'context'              => array( 'edit' ),
							'properties'           => array(
								'editOnly' => array(
									'type'    => 'string',
									'context' => array( 'edit' ),
								),
								'viewOnly' => array(
									'type'    => 'string',
									'context' => array( 'view' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'additionalProperties' => false,
				),
			)
		);

		$schema       = $controller->get_item_schema();
		$field_schema = $schema['properties'][ $case['field'] ] ?? array();
		self::collect_failure(
			$failures,
			$registered instanceof \WP_Post_Type
				&& array( 'edit' ) === ( $field_schema['context'] ?? null )
				&& false === ( $field_schema['additionalProperties'] ?? null )
				&& false === ( $field_schema['properties']['nested']['additionalProperties'] ?? null )
				&& array( 'edit' ) === ( $field_schema['properties']['nested']['properties']['editOnly']['context'] ?? null )
				&& array( 'view' ) === ( $field_schema['properties']['nested']['properties']['viewOnly']['context'] ?? null ),
			'additional REST field schema is registered with nested context and closed object contracts',
			array(
				'case'        => $case,
				'fieldSchema' => $field_schema,
				'registered'  => $registered,
			)
		);

		$cap_filter = self::install_cap_filter( array( $registered instanceof \WP_Post_Type ? $registered->cap->edit_posts : 'edit_posts' ) );
		try {
			$limited_item = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/types/' . $case['postType'],
					array(
						'context' => 'edit',
						'_fields' => 'slug',
					),
					array( 'type' => $case['postType'] )
				)
			);
			$limited_get_calls = $get_calls;

			$edit_item = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/types/' . $case['postType'],
					array( 'context' => 'edit' ),
					array( 'type' => $case['postType'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$limited_data = $limited_item instanceof \WP_REST_Response ? $limited_item->get_data() : array();
		$limited_keys = array_keys( $limited_data );
		sort( $limited_keys );
		$edit_data    = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		$field_value  = $edit_data[ $case['field'] ] ?? null;
		$filtered_view = \rest_filter_response_by_context(
			array(
				'slug'         => $case['postType'],
				$case['field'] => array(
					'label'  => $case['readLabel'],
					'nested' => array(
						'editOnly' => $case['nestedEdit'],
						'viewOnly' => $case['nestedView'],
					),
				),
			),
			$schema,
			'view'
		);
		$filtered_edit = \rest_filter_response_by_context(
			array(
				'slug'         => $case['postType'],
				$case['field'] => array(
					'label'  => $case['readLabel'],
					'nested' => array(
						'editOnly' => $case['nestedEdit'],
						'viewOnly' => $case['nestedView'],
					),
				),
			),
			$schema,
			'edit'
		);
		self::collect_failure(
			$failures,
			$limited_item instanceof \WP_REST_Response
				&& array( 'slug' ) === $limited_keys
				&& array() === $limited_get_calls
				&& $edit_item instanceof \WP_REST_Response
				&& array(
					array(
						'field'      => $case['field'],
						'objectType' => 'type',
						'context'    => 'edit',
						'slug'       => $case['postType'],
					),
				) === $get_calls
				&& is_array( $field_value )
				&& $case['readLabel'] === ( $field_value['label'] ?? null )
				&& $case['nestedEdit'] === ( $field_value['nested']['editOnly'] ?? null )
				&& ! isset( $field_value['nested']['viewOnly'] )
				&& ! isset( $filtered_view[ $case['field'] ] )
				&& $case['nestedEdit'] === ( $filtered_edit[ $case['field'] ]['nested']['editOnly'] ?? null )
				&& ! isset( $filtered_edit[ $case['field'] ]['nested']['viewOnly'] ),
			'additional REST field get callbacks respect _fields and schema context pruning',
			array(
				'limitedData'     => $limited_data,
				'limitedGetCalls' => $limited_get_calls,
				'editData'        => $edit_data,
				'getCalls'        => $get_calls,
				'filteredView'    => $filtered_view,
				'filteredEdit'    => $filtered_edit,
			)
		);

		$valid_schema = true === \rest_validate_value_from_schema(
			$field_value,
			$field_schema,
			$case['field']
		);
		$extra_property_invalid = \rest_validate_value_from_schema(
			array(
				'label'  => $case['readLabel'],
				'nested' => array(
					'editOnly' => $case['nestedEdit'],
				),
				'extra'  => 'not allowed',
			),
			$field_schema,
			$case['field']
		);

		$data_object = $registered instanceof \WP_Post_Type ? $registered : (object) array( 'name' => $case['postType'] );
		$update_value = array(
			'label'  => $case['updateLabel'],
			'nested' => array(
				'editOnly' => $case['nestedUpdate'],
			),
		);
		$update_request = self::request(
			'PUT',
			'/wp/v2/types/' . $case['postType'],
			array( 'context' => 'edit' ),
			array( 'type' => $case['postType'] )
		);
		$update_request->set_body_params( array( $case['field'] => $update_value ) );
		$update_result = self::invoke_object_method(
			$controller,
			'update_additional_fields_for_object',
			array( $data_object, $update_request )
		);

		$missing_update_request = self::request(
			'PUT',
			'/wp/v2/types/' . $case['postType'],
			array( 'context' => 'edit' ),
			array( 'type' => $case['postType'] )
		);
		$missing_update_result = self::invoke_object_method(
			$controller,
			'update_additional_fields_for_object',
			array( $data_object, $missing_update_request )
		);

		$invalid_update_value = array(
			'label'  => $case['invalidUpdateLabel'],
			'nested' => array(
				'editOnly' => $case['nestedUpdate'],
			),
		);
		$invalid_update_request = self::request(
			'PUT',
			'/wp/v2/types/' . $case['postType'],
			array( 'context' => 'edit' ),
			array( 'type' => $case['postType'] )
		);
		$invalid_update_request->set_body_params( array( $case['field'] => $invalid_update_value ) );
		$invalid_update_result = self::invoke_object_method(
			$controller,
			'update_additional_fields_for_object',
			array( $data_object, $invalid_update_request )
		);

		self::collect_failure(
			$failures,
			$valid_schema
				&& $extra_property_invalid instanceof \WP_Error
				&& true === $update_result
				&& true === $missing_update_result
				&& self::wp_error_ok( $invalid_update_result, 'rest_component_fuzz_additional_field_rejected', 400 )
				&& array(
					array(
						'field'      => $case['field'],
						'objectType' => 'type',
						'context'    => 'edit',
						'objectName' => $case['postType'],
						'value'      => $update_value,
					),
					array(
						'field'      => $case['field'],
						'objectType' => 'type',
						'context'    => 'edit',
						'objectName' => $case['postType'],
						'value'      => $invalid_update_value,
					),
				) === $update_calls,
			'additional REST field schema validation and update callbacks preserve success, absence, and WP_Error results',
			array(
				'validSchema'          => $valid_schema,
				'extraPropertyInvalid' => $extra_property_invalid,
				'updateResult'         => $update_result,
				'missingUpdateResult'  => $missing_update_result,
				'invalidUpdateResult'  => $invalid_update_result,
				'updateCalls'          => $update_calls,
			)
		);

		return self::row(
			$ctx,
			'rest-controllers.additional-fields.context-update-schema',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_namespace_route_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::namespace_route_case( $ctx->fork( 'namespace-routes' ) );
		$post_types = new \WP_REST_Post_Types_Controller();
		$taxonomies = new \WP_REST_Taxonomies_Controller();
		$failures   = array();

		$post_type = \register_post_type(
			$case['postType'],
			array(
				'label'          => $case['postTypeLabel'],
				'public'         => true,
				'show_in_rest'   => true,
				'rest_base'      => $case['postRestBase'],
				'rest_namespace' => $case['postNamespace'],
				'rewrite'        => false,
				'query_var'      => false,
				'show_ui'        => true,
			)
		);
		\register_post_type(
			$case['hiddenPostType'],
			array(
				'label'          => 'Hidden ' . $case['postTypeLabel'],
				'public'         => true,
				'show_in_rest'   => false,
				'rest_base'      => $case['hiddenPostRestBase'],
				'rest_namespace' => $case['postNamespace'],
				'rewrite'        => false,
				'query_var'      => false,
			)
		);
		$taxonomy = \register_taxonomy(
			$case['taxonomy'],
			$case['postType'],
			array(
				'label'          => $case['taxonomyLabel'],
				'public'         => true,
				'show_in_rest'   => true,
				'rest_base'      => $case['taxonomyRestBase'],
				'rest_namespace' => $case['taxonomyNamespace'],
				'rewrite'        => false,
				'query_var'      => false,
			)
		);
		\register_taxonomy(
			$case['hiddenTaxonomy'],
			$case['postType'],
			array(
				'label'          => 'Hidden ' . $case['taxonomyLabel'],
				'public'         => true,
				'show_in_rest'   => false,
				'rest_base'      => $case['hiddenTaxonomyRestBase'],
				'rest_namespace' => $case['taxonomyNamespace'],
				'rewrite'        => false,
				'query_var'      => false,
			)
		);

		$post_item = $post_types->get_item(
			self::request(
				'GET',
				'/wp/v2/types/' . $case['postType'],
				array(
					'context' => 'view',
					'_fields' => 'slug,taxonomies,rest_base,rest_namespace,_links',
				),
				array( 'type' => $case['postType'] )
			)
		);
		$post_data = $post_item instanceof \WP_REST_Response ? $post_item->get_data() : array();
		$post_keys = array_keys( $post_data );
		sort( $post_keys );
		$post_links = $post_item instanceof \WP_REST_Response ? $post_item->get_links() : array();
		$post_schema = $post_types->get_item_schema();

		self::collect_failure(
			$failures,
			$post_type instanceof \WP_Post_Type
				&& $post_item instanceof \WP_REST_Response
				&& array( 'rest_base', 'rest_namespace', 'slug', 'taxonomies' ) === $post_keys
				&& $case['postType'] === ( $post_data['slug'] ?? null )
				&& $case['postRestBase'] === ( $post_data['rest_base'] ?? null )
				&& $case['postNamespace'] === ( $post_data['rest_namespace'] ?? null )
				&& array( $case['taxonomy'] ) === ( $post_data['taxonomies'] ?? null )
				&& true === \rest_validate_value_from_schema(
					$post_data['rest_namespace'] ?? null,
					$post_schema['properties']['rest_namespace'] ?? array(),
					'rest_namespace'
				)
				&& \rest_url( '/wp/v2/types' ) === self::link_href( $post_links, 'collection' )
				&& \rest_url( '/' . $case['postNamespace'] . '/' . $case['postRestBase'] ) === self::link_href( $post_links, 'https://api.w.org/items' )
				&& '/' . $case['postNamespace'] . '/' . $case['postRestBase'] === \rest_get_route_for_post_type_items( $case['postType'] )
				&& '' === \rest_get_route_for_post_type_items( $case['hiddenPostType'] ),
			'post type rest_namespace data and item links follow generated object routes',
			array(
				'postData'          => $post_data,
				'postLinks'         => $post_links,
				'visibleRoute'      => \rest_get_route_for_post_type_items( $case['postType'] ),
				'hiddenRoute'       => \rest_get_route_for_post_type_items( $case['hiddenPostType'] ),
				'restNamespaceSpec' => $post_schema['properties']['rest_namespace'] ?? null,
			)
		);

		$taxonomy_item = $taxonomies->get_item(
			self::request(
				'GET',
				'/wp/v2/taxonomies/' . $case['taxonomy'],
				array(
					'context' => 'view',
					'_fields' => 'slug,types,rest_base,rest_namespace,_links',
				),
				array( 'taxonomy' => $case['taxonomy'] )
			)
		);
		$taxonomy_data = $taxonomy_item instanceof \WP_REST_Response ? $taxonomy_item->get_data() : array();
		$taxonomy_keys = array_keys( $taxonomy_data );
		sort( $taxonomy_keys );
		$taxonomy_links = $taxonomy_item instanceof \WP_REST_Response ? $taxonomy_item->get_links() : array();
		$taxonomy_schema = $taxonomies->get_item_schema();

		self::collect_failure(
			$failures,
			$taxonomy instanceof \WP_Taxonomy
				&& $taxonomy_item instanceof \WP_REST_Response
				&& array( 'rest_base', 'rest_namespace', 'slug', 'types' ) === $taxonomy_keys
				&& $case['taxonomy'] === ( $taxonomy_data['slug'] ?? null )
				&& $case['taxonomyRestBase'] === ( $taxonomy_data['rest_base'] ?? null )
				&& $case['taxonomyNamespace'] === ( $taxonomy_data['rest_namespace'] ?? null )
				&& array( $case['postType'] ) === ( $taxonomy_data['types'] ?? null )
				&& true === \rest_validate_value_from_schema(
					$taxonomy_data['rest_namespace'] ?? null,
					$taxonomy_schema['properties']['rest_namespace'] ?? array(),
					'rest_namespace'
				)
				&& \rest_url( '/wp/v2/taxonomies' ) === self::link_href( $taxonomy_links, 'collection' )
				&& \rest_url( '/' . $case['taxonomyNamespace'] . '/' . $case['taxonomyRestBase'] ) === self::link_href( $taxonomy_links, 'https://api.w.org/items' )
				&& '/' . $case['taxonomyNamespace'] . '/' . $case['taxonomyRestBase'] === \rest_get_route_for_taxonomy_items( $case['taxonomy'] )
				&& '' === \rest_get_route_for_taxonomy_items( $case['hiddenTaxonomy'] ),
			'taxonomy rest_namespace data and item links follow generated object routes',
			array(
				'taxonomyData'      => $taxonomy_data,
				'taxonomyLinks'     => $taxonomy_links,
				'visibleRoute'      => \rest_get_route_for_taxonomy_items( $case['taxonomy'] ),
				'hiddenRoute'       => \rest_get_route_for_taxonomy_items( $case['hiddenTaxonomy'] ),
				'restNamespaceSpec' => $taxonomy_schema['properties']['rest_namespace'] ?? null,
			)
		);

		return self::row(
			$ctx,
			'rest-controllers.namespace-route-links',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_settings_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::settings_case( $ctx->fork( 'settings' ) );
		$controller = new \WP_REST_Settings_Controller();
		$failures   = array();

		\register_setting(
			'component_fuzz',
			$case['stringOption'],
			array(
				'type'         => 'string',
				'label'        => 'String setting',
				'description'  => 'Generated string REST setting.',
				'default'      => $case['stringDefault'],
				'show_in_rest' => array(
					'name'   => $case['stringName'],
					'schema' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 48,
						'pattern'   => '^[A-Za-z0-9 _-]+$',
					),
				),
			)
		);
		\register_setting(
			'component_fuzz',
			$case['integerOption'],
			array(
				'type'         => 'integer',
				'default'      => $case['integerDefault'],
				'show_in_rest' => array(
					'name'   => $case['integerName'],
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
					),
				),
			)
		);
		\register_setting(
			'component_fuzz',
			$case['objectOption'],
			array(
				'type'         => 'object',
				'default'      => array(
					'flag'  => false,
					'label' => $case['objectDefaultLabel'],
				),
				'show_in_rest' => array(
					'name'   => $case['objectName'],
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'flag'  => array( 'type' => 'boolean' ),
							'label' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
		\register_setting(
			'component_fuzz',
			$case['arrayOption'],
			array(
				'type'         => 'array',
				'default'      => array( $case['arrayDefault'] ),
				'show_in_rest' => array(
					'name'   => $case['arrayName'],
					'schema' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 3,
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^item-[a-z0-9]+$',
						),
					),
				),
			)
		);
		\register_setting(
			'component_fuzz',
			$case['invalidStoredOption'],
			array(
				'type'         => 'object',
				'default'      => array(
					'flag' => false,
				),
				'show_in_rest' => array(
					'name'   => $case['invalidStoredName'],
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'flag' => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);
		\register_setting(
			'component_fuzz',
			$case['hiddenOption'],
			array(
				'type'         => 'string',
				'default'      => 'hidden',
				'show_in_rest' => false,
			)
		);
		\register_setting(
			'component_fuzz',
			$case['invalidTypeOption'],
			array(
				'type'         => 'not-a-json-type',
				'default'      => 'invalid',
				'show_in_rest' => true,
			)
		);

		\update_option( $case['stringOption'], $case['storedString'] );
		\update_option( $case['integerOption'], $case['storedInteger'] );
		\update_option( $case['arrayOption'], array( $case['storedArrayItem'] ) );
		\update_option( $case['invalidStoredOption'], $case['invalidStoredValue'] );
		\update_option(
			$case['objectOption'],
			array(
				'flag'  => false,
				'label' => $case['storedObjectLabel'],
			)
		);

		$denied = $controller->get_item_permissions_check( self::request( 'GET', '/wp/v2/settings' ) );
		$cap_filter = self::install_cap_filter( array( 'manage_options' ) );
		try {
			$allowed = $controller->get_item_permissions_check( self::request( 'GET', '/wp/v2/settings' ) );
			$item    = $controller->get_item( self::request( 'GET', '/wp/v2/settings' ) );
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$response = \rest_ensure_response( $item );
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$schema   = $controller->get_item_schema();
		$public_schema = $controller->get_public_item_schema();
		self::collect_failure(
			$failures,
			false === $denied
				&& true === $allowed
				&& $response instanceof \WP_REST_Response
				&& $case['storedString'] === ( $data[ $case['stringName'] ] ?? null )
				&& $case['storedInteger'] === ( $data[ $case['integerName'] ] ?? null )
				&& array( $case['storedArrayItem'] ) === ( $data[ $case['arrayName'] ] ?? null )
				&& isset( $data[ $case['objectName'] ]['flag'], $data[ $case['objectName'] ]['label'] )
				&& array_key_exists( $case['invalidStoredName'], $data )
				&& null === $data[ $case['invalidStoredName'] ]
				&& ! isset( $data[ $case['hiddenOption'] ], $data[ $case['invalidTypeOption'] ] )
				&& isset( $schema['properties'][ $case['stringName'] ]['arg_options']['sanitize_callback'] )
				&& ! isset( $public_schema['properties'][ $case['stringName'] ]['arg_options'] )
				&& false === ( $schema['properties'][ $case['objectName'] ]['additionalProperties'] ?? null ),
			'settings schema and get_item expose only REST-visible registered settings',
			array(
				'denied' => $denied,
				'allowed' => $allowed,
				'data' => $data,
				'schemaKeys' => array_keys( $schema['properties'] ?? array() ),
				'objectSchema' => $schema['properties'][ $case['objectName'] ] ?? null,
			)
		);

		$args      = $controller->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE );
		$sanitize_request = self::request( 'PUT', '/wp/v2/settings' );
		$sanitize_request->set_attributes( array( 'args' => $args ) );
		$valid_string = $controller->sanitize_callback( $case['updatedString'], $sanitize_request, $case['stringName'] );
		$valid_integer = $controller->sanitize_callback( (string) $case['updatedInteger'], $sanitize_request, $case['integerName'] );
		$valid_array = $controller->sanitize_callback( array( $case['updatedArrayItem'] ), $sanitize_request, $case['arrayName'] );
		$invalid_string = $controller->sanitize_callback( 'bad/value!', $sanitize_request, $case['stringName'] );
		$invalid_integer = $controller->sanitize_callback( 101, $sanitize_request, $case['integerName'] );
		$invalid_array = $controller->sanitize_callback( array( 'bad value!' ), $sanitize_request, $case['arrayName'] );

		$captured_filter_update = null;
		$pre_update_filter      = static function ( $updated, string $name, $value, array $args ) use ( $case, &$captured_filter_update ) {
			if ( $case['objectName'] !== $name ) {
				return $updated;
			}

			$captured_filter_update = array(
				'name'       => $name,
				'value'      => $value,
				'optionName' => $args['option_name'] ?? null,
			);
			return true;
		};

		\add_filter( 'rest_pre_update_setting', $pre_update_filter, 10, 4 );
		try {
			$update_request = self::request( 'PUT', '/wp/v2/settings' );
			$update_request->set_body_params(
				array(
					$case['stringName']  => $valid_string,
					$case['integerName'] => $valid_integer,
					$case['arrayName']   => $valid_array,
					$case['objectName']  => array(
						'flag'  => true,
						'label' => $case['filteredObjectLabel'],
					),
				)
			);
			$updated = $controller->update_item( $update_request );
		} finally {
			\remove_filter( 'rest_pre_update_setting', $pre_update_filter, 10 );
		}

		$updated_response = \rest_ensure_response( $updated );
		$updated_data     = $updated_response instanceof \WP_REST_Response ? $updated_response->get_data() : array();
		$invalid_stored_update_request = self::request( 'PUT', '/wp/v2/settings' );
		$invalid_stored_update_request->set_body_params(
			array(
				$case['invalidStoredName'] => null,
			)
		);
		$invalid_stored_update = $controller->update_item( $invalid_stored_update_request );
		self::collect_failure(
			$failures,
			$valid_string === $case['updatedString']
				&& $valid_integer === $case['updatedInteger']
				&& array( $case['updatedArrayItem'] ) === $valid_array
				&& $invalid_string instanceof \WP_Error
				&& $invalid_integer instanceof \WP_Error
				&& $invalid_array instanceof \WP_Error
				&& $updated_response instanceof \WP_REST_Response
				&& $case['updatedString'] === \get_option( $case['stringOption'] )
				&& $case['updatedInteger'] === \get_option( $case['integerOption'] )
				&& array( $case['updatedArrayItem'] ) === \get_option( $case['arrayOption'] )
				&& $case['updatedString'] === ( $updated_data[ $case['stringName'] ] ?? null )
				&& $case['updatedInteger'] === ( $updated_data[ $case['integerName'] ] ?? null )
				&& array( $case['updatedArrayItem'] ) === ( $updated_data[ $case['arrayName'] ] ?? null )
				&& $captured_filter_update === array(
					'name'       => $case['objectName'],
					'value'      => array(
						'flag'  => true,
						'label' => $case['filteredObjectLabel'],
					),
					'optionName' => $case['objectOption'],
				)
				&& array(
					'flag'  => false,
					'label' => $case['storedObjectLabel'],
				) === \get_option( $case['objectOption'] )
				&& false === \has_filter( 'rest_pre_update_setting', $pre_update_filter )
				&& self::wp_error_ok( $invalid_stored_update, 'rest_invalid_stored_value', 500 )
				&& $case['invalidStoredValue'] === \get_option( $case['invalidStoredOption'] ),
			'settings sanitize_callback validates invalid values and update_item uses option stub or pre-update filters',
			array(
				'validString' => $valid_string,
				'validInteger' => $valid_integer,
				'validArray' => $valid_array,
				'invalidString' => $invalid_string,
				'invalidInteger' => $invalid_integer,
				'invalidArray' => $invalid_array,
				'invalidStoredUpdate' => $invalid_stored_update,
				'updatedData' => $updated_data,
				'capturedFilterUpdate' => $captured_filter_update,
				'storedOptions' => array(
					'string' => \get_option( $case['stringOption'] ),
					'integer' => \get_option( $case['integerOption'] ),
					'array' => \get_option( $case['arrayOption'] ),
					'object' => \get_option( $case['objectOption'] ),
					'invalidStored' => \get_option( $case['invalidStoredOption'] ),
				),
			)
		);

		return self::row(
			$ctx,
			'rest-controllers.settings.schema-sanitize-update-permissions',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_block_types_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::block_type_case( $ctx->fork( 'block-types' ) );
		$controller = new \WP_REST_Block_Types_Controller();
		$failures   = array();

		$block = \register_block_type(
			$case['blockName'],
			array(
				'api_version'      => 3,
				'title'            => $case['title'],
				'description'      => $case['description'],
				'category'         => 'widgets',
				'attributes'       => array(
					'message' => array(
						'type'    => 'string',
						'default' => $case['message'],
					),
				),
				'supports'         => array( 'align' => array( 'wide', 'full' ) ),
				'uses_context'     => array( 'postId', 'componentFuzz/context' ),
				'provides_context' => array( 'componentFuzz/message' => 'message' ),
				'selectors'        => array( 'root' => '.wp-block-' . str_replace( '/', '-', $case['blockName'] ) ),
				'render_callback'  => static function ( array $attributes ): string {
					return '<div>' . esc_html( (string) ( $attributes['message'] ?? '' ) ) . '</div>';
				},
			)
		);
		\register_block_type(
			$case['otherBlockName'],
			array(
				'title'       => 'Other block',
				'api_version' => 3,
			)
		);
		\register_block_style(
			$case['blockName'],
			array(
				'name'         => $case['styleName'],
				'label'        => $case['styleLabel'],
				'inline_style' => '.is-style-' . $case['styleName'] . '{outline:1px solid currentColor;}',
			)
		);

		$params = $controller->get_collection_params();
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& isset( $params['namespace'] )
				&& 'string' === ( $params['namespace']['type'] ?? null ),
			'block type collection params expose sanitized context and string namespace filter',
			array( 'params' => self::param_summary( $params ) )
		);

		$denied = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/block-types' ) );
		$cap_filter = self::install_cap_filter( array( 'edit_posts' ) );
		try {
			$allowed = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/block-types' ) );
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$list = $controller->get_items(
			self::request(
				'GET',
				'/wp/v2/block-types/' . $case['namespace'],
				array( 'context' => 'view', 'namespace' => $case['namespace'] )
			)
		);
		$list_data = $list instanceof \WP_REST_Response ? $list->get_data() : array();
		$list_names = array();
		foreach ( $list_data as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) ) {
				$list_names[] = $item['name'];
			}
		}
		self::collect_failure(
			$failures,
			self::wp_error_ok( $denied, 'rest_block_type_cannot_view', \rest_authorization_required_code() )
				&& true === $allowed
				&& $list instanceof \WP_REST_Response
				&& array( $case['blockName'] ) === $list_names,
			'block type permissions are capability-gated and namespace collection filters registered blocks',
			array( 'denied' => $denied, 'allowed' => $allowed, 'listNames' => $list_names )
		);

		$item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/block-types/' . $case['blockName'],
				array(
					'context' => 'view',
					'_fields' => 'name,title,styles,is_dynamic,_links',
				),
				array(
					'namespace' => $case['namespace'],
					'name'      => $case['name'],
				)
			)
		);
		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_keys = array_keys( $item_data );
		sort( $item_keys );
		$style_names = array();
		foreach ( $item_data['styles'] ?? array() as $style ) {
			if ( is_array( $style ) && isset( $style['name'] ) ) {
				$style_names[] = $style['name'];
			}
		}
		$links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		self::collect_failure(
			$failures,
			$block instanceof \WP_Block_Type
				&& $item instanceof \WP_REST_Response
				&& array( 'is_dynamic', 'name', 'styles', 'title' ) === $item_keys
				&& $case['blockName'] === $item_data['name']
				&& true === $item_data['is_dynamic']
				&& in_array( $case['styleName'], $style_names, true )
				&& \rest_url( '/wp/v2/block-types' ) === self::link_href( $links, 'collection' )
				&& \rest_url( '/wp/v2/block-types/' . $case['blockName'] ) === self::link_href( $links, 'self' )
				&& \rest_url( '/wp/v2/block-types/' . $case['namespace'] ) === self::link_href( $links, 'up' )
				&& null !== self::link_href( $links, 'https://api.w.org/render-block' ),
			'block type item respects _fields, merges styles, and emits stable links',
			array( 'itemData' => $item_data, 'links' => $links )
		);

		$head = $controller->get_items( self::request( 'HEAD', '/wp/v2/block-types' ) );
		$invalid = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/block-types/' . $case['namespace'] . '/missing',
				array( 'context' => 'view' ),
				array(
					'namespace' => $case['namespace'],
					'name'      => 'missing',
				)
			)
		);
		self::collect_failure(
			$failures,
			$head instanceof \WP_REST_Response
				&& array() === $head->get_data()
				&& self::wp_error_ok( $invalid, 'rest_block_type_invalid', 404 ),
			'block type HEAD and invalid item paths return represented results',
			array( 'headData' => $head instanceof \WP_REST_Response ? $head->get_data() : $head, 'invalid' => $invalid )
		);

		return self::row(
			$ctx,
			'rest-controllers.block-types.registry-fields-links',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_block_renderer_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::block_renderer_case( $ctx );
		$controller = new \WP_REST_Block_Renderer_Controller();
		$server     = new \WP_REST_Server();
		$failures   = array();
		$route      = '/wp/v2/block-renderer/(?P<name>[a-z0-9-]+/[a-z0-9-]+)';
		$post       = self::block_renderer_post( $ctx->fork( 'post' ), $case );

		$previous_server = $GLOBALS['wp_rest_server'] ?? null;
		$previous_post   = $GLOBALS['post'] ?? null;
		$render_calls    = array();
		$pre_render_calls = array();

		$GLOBALS['wp_rest_server'] = $server;
		self::seed_post_storage( $post );
		\register_post_status( 'publish', array( 'public' => true ) );
		\register_post_type(
			'post',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);

		$dynamic_block = \register_block_type(
			$case['blockName'],
			array(
				'attributes'      => array(
					'message' => array(
						'type'    => 'string',
						'default' => $case['defaultMessage'],
					),
					'count'   => array(
						'type'    => 'integer',
						'default' => $case['defaultCount'],
					),
					'items'   => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array( 1, 2 ),
					),
					'enabled' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => static function ( array $attributes ) use ( &$render_calls ): string {
					$payload = array(
						'attributes' => $attributes,
						'postTitle'  => \get_the_title(),
					);
					$render_calls[] = $payload;

					return \wp_json_encode( $payload );
				},
			)
		);
		$boolean_block = \register_block_type(
			$case['booleanBlockName'],
			array(
				'attributes'      => array(
					'flag' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => static function ( array $attributes ): string {
					return \wp_json_encode( array( 'attributes' => $attributes ) );
				},
			)
		);
		$static_block  = \register_block_type( $case['staticBlockName'] );

		$controller->register_routes();

		$routes     = $server->get_routes();
		$route_args = isset( $routes[ $route ] ) ? self::handler_args_for_method( $routes[ $route ], 'GET' ) : array();
		$schema     = $controller->get_item_schema();
		$dynamic_names = \get_dynamic_block_names();

		self::collect_failure(
			$failures,
			$dynamic_block instanceof \WP_Block_Type
				&& $boolean_block instanceof \WP_Block_Type
				&& $static_block instanceof \WP_Block_Type
				&& isset( $routes[ $route ] )
				&& array( 'GET', 'POST' ) === self::route_methods( $routes[ $route ] )
				&& array() === array_values( array_diff( array( 'name', 'context', 'attributes', 'post_id' ), array_keys( $route_args ) ) )
				&& isset( $route_args['attributes']['validate_callback'], $route_args['attributes']['sanitize_callback'] )
				&& is_callable( $route_args['attributes']['validate_callback'] )
				&& is_callable( $route_args['attributes']['sanitize_callback'] )
				&& in_array( $case['blockName'], $dynamic_names, true )
				&& in_array( $case['booleanBlockName'], $dynamic_names, true )
				&& ! in_array( $case['staticBlockName'], $dynamic_names, true )
				&& 'rendered-block' === ( $schema['title'] ?? null )
				&& array( 'edit' ) === ( $schema['properties']['rendered']['context'] ?? null ),
			'block renderer registers GET/POST dynamic render route, route args, schema, and dynamic block registry state',
			array(
				'routeMethods' => isset( $routes[ $route ] ) ? self::route_methods( $routes[ $route ] ) : array(),
				'routeArgs'    => self::param_summary( $route_args ),
				'dynamicNames' => $dynamic_names,
				'schema'       => $schema,
			)
		);

		$denied_response = $server->dispatch(
			self::request(
				'GET',
				'/wp/v2/block-renderer/' . $case['blockName'],
				array( 'context' => 'edit' )
			)
		);

		$map_meta_cap_filter = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $post ): array {
			unset( $user_id );

			if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $post->ID === (int) $args[0] ) {
				return array( 'edit_posts' );
			}

			return $caps;
		};

		$cap_filter = self::install_cap_filter( array( 'edit_posts', 'edit_published_posts', 'read' ) );
		\add_filter( 'map_meta_cap', $map_meta_cap_filter, 10, 4 );
		try {
			$missing_context = $server->dispatch(
				self::request( 'GET', '/wp/v2/block-renderer/' . $case['blockName'] )
			);
			$invalid_block = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/block-renderer/' . $case['namespace'] . '/missing',
					array( 'context' => 'edit' )
				)
			);
			$non_dynamic = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/block-renderer/' . $case['staticBlockName'],
					array( 'context' => 'edit' )
				)
			);

			$invalid_attribute = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/block-renderer/' . $case['blockName'],
					array(
						'context'    => 'edit',
						'attributes' => array( 'count' => array( 'not-an-integer' ) ),
					)
				)
			);
			$unknown_attribute = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/block-renderer/' . $case['blockName'],
					array(
						'context'    => 'edit',
						'attributes' => array( 'unknown' => 'yes' ),
					)
				)
			);

			$normal_response = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/block-renderer/' . $case['blockName'],
					array(
						'context'    => 'edit',
						'attributes' => array(
							'message' => $case['message'],
							'count'   => (string) $case['count'],
							'items'   => array_map( 'strval', $case['items'] ),
							'enabled' => 'false',
						),
					)
				)
			);

			$boolean_response = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/block-renderer/' . $case['booleanBlockName'],
					array(
						'context'    => 'edit',
						'attributes' => array( 'flag' => 'false' ),
					)
				)
			);

			$post_request = self::request(
				'POST',
				'/wp/v2/block-renderer/' . $case['blockName'],
				array( 'context' => 'edit' )
			);
			$post_request->set_header( 'Content-Type', 'application/json' );
			$post_request->set_body(
				\wp_json_encode(
					array(
						'attributes' => array(
							'message' => $case['postMessage'],
							'count'   => (string) $case['postCount'],
							'items'   => array_map( 'strval', $case['postItems'] ),
							'enabled' => true,
						),
						'post_id'    => $post->ID,
					)
				)
			);
			$post_response = $server->dispatch( $post_request );

			$pre_render_filter = static function ( $output, array $parsed_block ) use ( $case, &$pre_render_calls ) {
				$pre_render_calls[] = array(
					'blockName' => $parsed_block['blockName'] ?? null,
					'attrs'     => $parsed_block['attrs'] ?? array(),
					'output'    => $output,
				);

				if ( $case['blockName'] === ( $parsed_block['blockName'] ?? null ) ) {
					return '<p data-cfz-render="' . \esc_attr( $case['marker'] ) . '">Alternate content.</p>';
				}

				return $output;
			};

			\add_filter( 'pre_render_block', $pre_render_filter, 10, 2 );
			try {
				$filtered_response = $server->dispatch(
					self::request(
						'GET',
						'/wp/v2/block-renderer/' . $case['blockName'],
						array(
							'context'    => 'edit',
							'attributes' => array(
								'message' => $case['filterMessage'],
								'count'   => (string) $case['count'],
							),
						)
					)
				);
			} finally {
				\remove_filter( 'pre_render_block', $pre_render_filter, 10 );
			}
		} finally {
			\remove_filter( 'map_meta_cap', $map_meta_cap_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$normal_data = $normal_response instanceof \WP_REST_Response ? $normal_response->get_data() : array();
		$normal_rendered = isset( $normal_data['rendered'] ) && is_string( $normal_data['rendered'] )
			? json_decode( $normal_data['rendered'], true )
			: null;
		$boolean_data = $boolean_response instanceof \WP_REST_Response ? $boolean_response->get_data() : array();
		$boolean_rendered = isset( $boolean_data['rendered'] ) && is_string( $boolean_data['rendered'] )
			? json_decode( $boolean_data['rendered'], true )
			: null;
		$post_data = $post_response instanceof \WP_REST_Response ? $post_response->get_data() : array();
		$post_rendered = isset( $post_data['rendered'] ) && is_string( $post_data['rendered'] )
			? json_decode( $post_data['rendered'], true )
			: null;
		$filtered_data = $filtered_response instanceof \WP_REST_Response ? $filtered_response->get_data() : array();

		self::collect_failure(
			$failures,
			self::response_error_ok( $denied_response, 'block_cannot_read', \rest_authorization_required_code() )
				&& self::response_error_ok( $missing_context, 'rest_invalid_param', 400 )
				&& self::response_error_ok( $invalid_block, 'block_invalid', 404 )
				&& self::response_error_ok( $non_dynamic, 'block_invalid', 404 )
				&& self::response_error_ok( $invalid_attribute, 'rest_invalid_param', 400 )
				&& self::response_error_ok( $unknown_attribute, 'rest_invalid_param', 400 ),
			'block renderer dispatch rejects unauthorized, invalid context, missing/non-dynamic blocks, invalid attributes, and unknown attributes',
			array(
				'denied'           => $denied_response,
				'missingContext'   => $missing_context,
				'invalidBlock'     => $invalid_block,
				'nonDynamic'       => $non_dynamic,
				'invalidAttribute' => $invalid_attribute,
				'unknownAttribute' => $unknown_attribute,
			)
		);

		self::collect_failure(
			$failures,
			$normal_response instanceof \WP_REST_Response
				&& 200 === $normal_response->get_status()
				&& is_array( $normal_rendered )
				&& array(
					'message' => $case['message'],
					'count'   => $case['count'],
					'items'   => $case['items'],
					'enabled' => false,
				) === ( $normal_rendered['attributes'] ?? null )
				&& '' === ( $normal_rendered['postTitle'] ?? null )
				&& $boolean_response instanceof \WP_REST_Response
				&& is_array( $boolean_rendered )
				&& array( 'flag' => false ) === ( $boolean_rendered['attributes'] ?? null )
				&& $post_response instanceof \WP_REST_Response
				&& is_array( $post_rendered )
				&& array(
					'message' => $case['postMessage'],
					'count'   => $case['postCount'],
					'items'   => $case['postItems'],
					'enabled' => true,
				) === ( $post_rendered['attributes'] ?? null )
				&& $case['postTitle'] === ( $post_rendered['postTitle'] ?? null ),
			'block renderer sanitizes typed attributes, handles JSON POST bodies, and exposes post context to dynamic render callbacks',
			array(
				'normalRendered'  => $normal_rendered,
				'booleanRendered' => $boolean_rendered,
				'postRendered'    => $post_rendered,
				'renderCalls'     => $render_calls,
			)
		);

		self::collect_failure(
			$failures,
			$filtered_response instanceof \WP_REST_Response
				&& 200 === $filtered_response->get_status()
				&& '<p data-cfz-render="' . \esc_attr( $case['marker'] ) . '">Alternate content.</p>' === ( $filtered_data['rendered'] ?? null )
				&& 1 === count( $pre_render_calls )
				&& $case['blockName'] === ( $pre_render_calls[0]['blockName'] ?? null )
				&& $case['filterMessage'] === ( $pre_render_calls[0]['attrs']['message'] ?? null )
				&& false === \has_filter( 'pre_render_block', $pre_render_filter, 10 )
				&& false === \has_filter( 'map_meta_cap', $map_meta_cap_filter, 10 )
				&& false === \has_filter( 'user_has_cap', $cap_filter, 10 ),
			'block renderer uses render_block filters with parsed block payloads and removes temporary filters',
			array(
				'filteredData'   => $filtered_data,
				'preRenderCalls' => $pre_render_calls,
				'preFilter'      => \has_filter( 'pre_render_block', $pre_render_filter, 10 ),
				'mapCapFilter'   => \has_filter( 'map_meta_cap', $map_meta_cap_filter, 10 ),
				'capFilter'      => \has_filter( 'user_has_cap', $cap_filter, 10 ),
			)
		);

		\unregister_block_type( $case['blockName'] );
		\unregister_block_type( $case['booleanBlockName'] );
		\unregister_block_type( $case['staticBlockName'] );
		self::delete_post_storage( $post->ID );
		if ( null === $previous_server ) {
			unset( $GLOBALS['wp_rest_server'] );
		} else {
			$GLOBALS['wp_rest_server'] = $previous_server;
		}
		if ( null === $previous_post ) {
			unset( $GLOBALS['post'] );
		} else {
			$GLOBALS['post'] = $previous_post;
		}

		return self::row(
			$ctx,
			'rest-controllers.block-renderer.dispatch-validation',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_block_patterns_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::block_pattern_case( $ctx->fork( 'patterns' ) );
		$patterns   = new \WP_REST_Block_Patterns_Controller();
		$categories = new \WP_REST_Block_Pattern_Categories_Controller();
		self::set_object_property( $patterns, 'remote_patterns_loaded', true );
		$failures = array();

		$category_registry = \WP_Block_Pattern_Categories_Registry::get_instance();
		$pattern_registry  = \WP_Block_Patterns_Registry::get_instance();
		$category_registered = $category_registry->register(
			$case['categoryName'],
			array(
				'label'       => $case['categoryLabel'],
				'description' => $case['categoryDescription'],
			)
		);
		$pattern_registered = $pattern_registry->register(
			$case['patternName'],
			array(
				'title'         => $case['patternTitle'],
				'content'       => $case['content'],
				'description'   => $case['patternDescription'],
				'viewportWidth' => $case['viewportWidth'],
				'inserter'      => true,
				'categories'    => array( 'buttons', $case['categoryName'] ),
				'keywords'      => array( $case['keyword'], 'fuzz' ),
				'blockTypes'    => array( 'core/paragraph' ),
				'postTypes'     => array( 'post' ),
				'templateTypes' => array( 'front-page' ),
				'source'        => 'plugin',
			)
		);

		$denied = $patterns->get_items_permissions_check( self::request( 'GET', '/wp/v2/block-patterns/patterns' ) );
		$cap_filter = self::install_cap_filter( array( 'edit_posts' ) );
		try {
			$allowed = $patterns->get_items_permissions_check( self::request( 'GET', '/wp/v2/block-patterns/patterns' ) );
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$pattern_items = $patterns->get_items(
			self::request(
				'GET',
				'/wp/v2/block-patterns/patterns',
				array(
					'context' => 'view',
					'_fields' => 'name,categories,content,source',
				)
			)
		);
		$pattern_data = $pattern_items instanceof \WP_REST_Response ? $pattern_items->get_data() : array();
		$pattern_entry = $pattern_data[0] ?? array();
		$pattern_keys  = array_keys( $pattern_entry );
		sort( $pattern_keys );
		self::collect_failure(
			$failures,
			true === $category_registered
				&& true === $pattern_registered
				&& self::wp_error_ok( $denied, 'rest_cannot_view', \rest_authorization_required_code() )
				&& true === $allowed
				&& $pattern_items instanceof \WP_REST_Response
				&& array( 'categories', 'content', 'name', 'source' ) === $pattern_keys
				&& $case['patternName'] === ( $pattern_entry['name'] ?? null )
				&& in_array( 'call-to-action', $pattern_entry['categories'] ?? array(), true )
				&& in_array( $case['categoryName'], $pattern_entry['categories'] ?? array(), true )
				&& str_contains( $pattern_entry['content'] ?? '', '<!-- wp:paragraph -->' )
				&& 'plugin' === ( $pattern_entry['source'] ?? null ),
			'block pattern controller uses registry data, _fields, category migration, and permission gates',
			array(
				'denied' => $denied,
				'allowed' => $allowed,
				'patternEntry' => $pattern_entry,
			)
		);

		$source_invalid = \rest_validate_value_from_schema( null, $patterns->get_item_schema()['properties']['source'], 'source' );
		$category_items = $categories->get_items(
			self::request(
				'GET',
				'/wp/v2/block-patterns/categories',
				array(
					'context' => 'view',
					'_fields' => 'name,label',
				)
			)
		);
		$category_data = $category_items instanceof \WP_REST_Response ? $category_items->get_data() : array();
		$category_entry = $category_data[0] ?? array();
		$category_keys  = array_keys( $category_entry );
		sort( $category_keys );
		$head_categories = $categories->get_items( self::request( 'HEAD', '/wp/v2/block-patterns/categories' ) );
		self::collect_failure(
			$failures,
			$source_invalid instanceof \WP_Error
				&& $category_items instanceof \WP_REST_Response
				&& array( 'label', 'name' ) === $category_keys
				&& $case['categoryName'] === ( $category_entry['name'] ?? null )
				&& $case['categoryLabel'] === ( $category_entry['label'] ?? null )
				&& $head_categories instanceof \WP_REST_Response
				&& array() === $head_categories->get_data(),
			'block pattern category controller respects registry data, _fields, HEAD, and invalid schema values',
			array(
				'sourceInvalid' => $source_invalid,
				'categoryEntry' => $category_entry,
				'headData' => $head_categories instanceof \WP_REST_Response ? $head_categories->get_data() : $head_categories,
			)
		);

		return self::row(
			$ctx,
			'rest-controllers.block-patterns.registry-fields-permissions',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
				'skipped'  => array(
					'remotePatterns' => 'Remote and current-theme pattern loaders are not invoked; the private loaded flag is set before get_items().',
				),
			)
		);
	}

	private static function check_block_pattern_remote_loaders( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case              = self::block_pattern_case( $ctx->fork( 'remote-patterns' ) );
		$controller        = new \WP_REST_Block_Patterns_Controller();
		$failures          = array();
		$token             = substr( hash( 'sha1', 'remote-patterns:' . $ctx->seed() ), 0, 8 );
		$core_title        = 'Core Remote Pattern ' . $token;
		$featured_title    = 'Featured Remote Pattern ' . $token;
		$theme_title       = 'Theme Remote Pattern ' . $token;
		$theme_slug        = 'theme-pattern-' . $token;
		$stylesheet        = 'cfz-pattern-theme-' . $token;
		$temp_theme_json   = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'component-fuzz-rest-patterns-' . getmypid() . '-' . $token . '.json';
		$dispatch_log      = array();
		$theme_file_hits   = array();

		$core_pattern      = self::remote_pattern(
			$core_title,
			self::pattern_content( 'core remote ' . $token ),
			array( 'buttons' ),
			array( 'core', $case['keyword'] ),
			array( 'core/paragraph' ),
			640
		);
		$featured_duplicate = self::remote_pattern(
			$core_title,
			self::pattern_content( 'featured duplicate ' . $token ),
			array( 'columns' ),
			array( 'duplicate' ),
			array( 'core/group' ),
			700
		);
		$featured_pattern  = self::remote_pattern(
			$featured_title,
			self::pattern_content( 'featured remote ' . $token ),
			array( 'columns' ),
			array( 'featured' ),
			array( 'core/group' ),
			720
		);
		$theme_pattern     = self::remote_pattern(
			$theme_title,
			self::pattern_content( 'theme remote ' . $token ),
			array( 'query' ),
			array( 'theme' ),
			array( 'core/query' ),
			880
		);

		$theme_json = \wp_json_encode(
			array(
				'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
				'patterns' => array( $theme_slug ),
			),
			JSON_UNESCAPED_SLASHES
		);
		$json_written = false !== file_put_contents( $temp_theme_json, false === $theme_json ? '{}' : $theme_json );

		$theme_file_filter = static function ( string $path, string $file ) use ( $temp_theme_json, &$theme_file_hits ): string {
			if ( 'theme.json' === $file ) {
				$theme_file_hits[] = $path;
				return $temp_theme_json;
			}
			return $path;
		};
		$rest_filter       = static function ( $result, \WP_REST_Server $server, \WP_REST_Request $request ) use (
			$core_pattern,
			$featured_duplicate,
			$featured_pattern,
			$theme_pattern,
			$theme_slug,
			&$dispatch_log
		) {
			unset( $server );
			if ( '/wp/v2/pattern-directory/patterns' !== $request->get_route() ) {
				return $result;
			}

			$params         = $request->get_params();
			$dispatch_log[] = array(
				'keyword'  => $params['keyword'] ?? null,
				'category' => $params['category'] ?? null,
				'slug'     => $params['slug'] ?? null,
			);

			if ( 11 === (int) ( $params['keyword'] ?? 0 ) ) {
				return new \WP_REST_Response( array( $core_pattern ), 200 );
			}

			if ( 26 === (int) ( $params['category'] ?? 0 ) ) {
				return new \WP_REST_Response( array( $featured_duplicate, $featured_pattern ), 200 );
			}

			$slugs = (array) ( $params['slug'] ?? array() );
			if ( in_array( $theme_slug, $slugs, true ) ) {
				return new \WP_REST_Response( array( $theme_pattern ), 200 );
			}

			return new \WP_REST_Response( array(), 200 );
		};
		$remote_off_filter = static function (): bool {
			return false;
		};

		\add_filter( 'theme_file_path', $theme_file_filter, 10, 2 );
		\add_filter( 'rest_pre_dispatch', $rest_filter, 10, 3 );
		\add_theme_support( 'core-block-patterns' );
		\update_option( 'stylesheet', $stylesheet );
		\update_option( 'template', $stylesheet );
		\update_option( 'current_theme', 'Component Fuzz Pattern Theme ' . $token );
		self::clean_theme_json_runtime_cache();

		try {
			$response = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/block-patterns/patterns',
					array(
						'context' => 'view',
						'_fields' => 'name,title,categories,source,viewport_width,block_types,content',
					)
				)
			);
			$data     = $response instanceof \WP_REST_Response ? $response->get_data() : array();
			$count_after_first_dispatch = count( $dispatch_log );

			$second_response = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/block-patterns/patterns',
					array(
						'context' => 'view',
						'_fields' => 'name,source',
					)
				)
			);
			$second_data     = $second_response instanceof \WP_REST_Response ? $second_response->get_data() : array();

			self::reset_block_registries();
			$blocked_controller = new \WP_REST_Block_Patterns_Controller();
			$blocked_dispatch_count = count( $dispatch_log );
			\add_filter( 'should_load_remote_block_patterns', $remote_off_filter );
			try {
				$blocked_response = $blocked_controller->get_items(
					self::request( 'GET', '/wp/v2/block-patterns/patterns', array( 'context' => 'view' ) )
				);
			} finally {
				\remove_filter( 'should_load_remote_block_patterns', $remote_off_filter );
			}
			$blocked_data = $blocked_response instanceof \WP_REST_Response ? $blocked_response->get_data() : array();
		} finally {
			\remove_filter( 'rest_pre_dispatch', $rest_filter, 10 );
			\remove_filter( 'theme_file_path', $theme_file_filter, 10 );
			self::clean_theme_json_runtime_cache();
			@unlink( $temp_theme_json );
		}

		$core_entry     = self::pattern_entry_by_name( $data, 'core/' . \sanitize_title( $core_title ) );
		$featured_entry = self::pattern_entry_by_name( $data, \sanitize_title( $featured_title ) );
		$theme_entry    = self::pattern_entry_by_name( $data, \sanitize_title( $theme_title ) );
		$duplicate      = self::pattern_entry_by_name( $data, \sanitize_title( $core_title ) );

		self::collect_failure(
			$failures,
			$json_written
				&& $response instanceof \WP_REST_Response
				&& $core_entry
				&& $featured_entry
				&& $theme_entry
				&& null === $duplicate
				&& 'pattern-directory/core' === ( $core_entry['source'] ?? null )
				&& 'pattern-directory/featured' === ( $featured_entry['source'] ?? null )
				&& 'pattern-directory/theme' === ( $theme_entry['source'] ?? null )
				&& 640 === (int) ( $core_entry['viewport_width'] ?? 0 )
				&& array( 'core/paragraph' ) === ( $core_entry['block_types'] ?? null )
				&& in_array( 'call-to-action', $core_entry['categories'] ?? array(), true )
				&& in_array( 'text', $featured_entry['categories'] ?? array(), true )
				&& in_array( 'posts', $theme_entry['categories'] ?? array(), true )
				&& str_contains( $theme_entry['content'] ?? '', 'theme remote' ),
			'block pattern collection loads core, featured, and theme remote patterns through intercepted REST responses',
			array(
				'jsonWritten' => $json_written,
				'data'        => $data,
				'dispatchLog' => $dispatch_log,
				'themeFileHits' => $theme_file_hits,
			)
		);

		self::collect_failure(
			$failures,
			array(
				array(
					'keyword'  => 11,
					'category' => null,
					'slug'     => null,
				),
				array(
					'keyword'  => null,
					'category' => 26,
					'slug'     => null,
				),
				array(
					'keyword'  => null,
					'category' => null,
					'slug'     => array( $theme_slug ),
				),
			) === array_slice( $dispatch_log, 0, 3 )
				&& 3 === $count_after_first_dispatch
				&& 3 === count( $dispatch_log )
				&& $second_response instanceof \WP_REST_Response
				&& count( $data ) === count( $second_data )
				&& $blocked_response instanceof \WP_REST_Response
				&& array() === $blocked_data
				&& $blocked_dispatch_count === count( $dispatch_log )
				&& false === \has_filter( 'rest_pre_dispatch', $rest_filter )
				&& false === \has_filter( 'theme_file_path', $theme_file_filter )
				&& false === \has_filter( 'should_load_remote_block_patterns', $remote_off_filter )
				&& ! file_exists( $temp_theme_json ),
			'block pattern remote loaders dispatch once, honor the remote-load filter, and clean temporary hooks and files',
			array(
				'dispatchLog'   => $dispatch_log,
				'firstDispatchCount' => $count_after_first_dispatch,
				'blockedDispatchCount' => $blocked_dispatch_count,
				'secondCount'   => is_array( $second_data ) ? count( $second_data ) : null,
				'blockedData'   => $blocked_data,
				'tempExists'    => file_exists( $temp_theme_json ),
			)
		);

		return self::row(
			$ctx,
			'rest-controllers.block-patterns.remote-loaders',
			array() === $failures,
			array(
				'loadedNames' => array_values(
					array_filter(
						array_map(
							static fn ( $pattern ) => is_array( $pattern ) ? ( $pattern['name'] ?? null ) : null,
							$data
						)
					)
				),
				'dispatchLog' => $dispatch_log,
				'failures'    => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_block_pattern_theme_file_loader( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$local_snapshot        = self::snapshot_state();
		$case                  = self::theme_pattern_file_case( $ctx->fork( 'theme-pattern-files' ) );
		$controller            = new \WP_REST_Block_Patterns_Controller();
		$registry              = \WP_Block_Patterns_Registry::get_instance();
		$failures              = array();
		$temp_root             = self::theme_pattern_temp_root( $ctx );
		$theme_root            = $temp_root . DIRECTORY_SEPARATOR . 'themes';
		$theme_dir             = $theme_root . DIRECTORY_SEPARATOR . $case['stylesheet'];
		$patterns_dir          = $theme_dir . DIRECTORY_SEPARATOR . 'patterns';
		$cache_key             = 'wp_theme_files_patterns-' . md5( $theme_root . DIRECTORY_SEPARATOR . $case['stylesheet'] );
		$parsed_patterns       = array();
		$cache_value           = false;
		$active_themes         = array();
		$raw_before_rest       = array();
		$response              = null;
		$data                  = array();
		$first_register_count  = null;
		$second_register_count = null;
		$theme_exists          = false;
		$cleanup_ok            = true;
		$theme                 = null;
		$pattern_file_log      = array();
		$cache_ttl_log         = array();
		$doing_it_wrong_log    = array();
		$theme_development_mode = \wp_is_development_mode( 'theme' );

		$pattern_files_filter = static function ( array $files, string $dirpath ) use ( &$pattern_file_log ): array {
			$basenames = array_map( 'basename', $files );
			sort( $basenames );
			$pattern_file_log[] = array(
				'dirpath'   => $dirpath,
				'basenames' => $basenames,
			);
			return $files;
		};
		$cache_ttl_filter     = static function ( int $ttl, string $cache_type ) use ( &$cache_ttl_log ): int {
			$cache_ttl_log[] = array(
				'inputTtl'  => $ttl,
				'returnTtl' => 120,
				'cacheType' => $cache_type,
			);
			return 120;
		};
		$doing_it_wrong_filter = static function ( string $function_name, string $message, string $version ) use ( &$doing_it_wrong_log ): void {
			$doing_it_wrong_log[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};
		$suppress_doing_it_wrong = static function (): bool {
			return false;
		};
		$remote_off_filter       = static function (): bool {
			return false;
		};
		$stylesheet_filter       = static function () use ( $case ): string {
			return $case['stylesheet'];
		};
		$template_filter         = static function () use ( $case ): string {
			return $case['stylesheet'];
		};
		$theme_root_filter       = static function () use ( $theme_root ): string {
			return $theme_root;
		};
		$theme_root_uri_filter   = static function () use ( $temp_root ): string {
			return 'http://example.test/wp-content/component-fuzz-pattern-files/' . basename( $temp_root );
		};
		$stylesheet_root_filter  = static function () use ( $theme_root ): string {
			return $theme_root;
		};

		try {
			self::write_theme_pattern_fixture( $case, $theme_dir, $patterns_dir );

			if ( ! isset( $GLOBALS['wp_theme_directories'] ) || ! is_array( $GLOBALS['wp_theme_directories'] ) ) {
				$GLOBALS['wp_theme_directories'] = array();
			}
			$GLOBALS['wp_theme_directories'] = array_values(
				array_unique(
					array_merge(
						$GLOBALS['wp_theme_directories'],
						array( WP_CONTENT_DIR . '/themes', $theme_root )
					)
				)
			);
			$GLOBALS['wp_stylesheet_path'] = $theme_dir;
			$GLOBALS['wp_template_path']   = $theme_dir;

			\update_option( 'stylesheet', $case['stylesheet'] );
			\update_option( 'template', $case['stylesheet'] );
			\update_option( 'current_theme', 'Component Fuzz Pattern Files ' . $case['token'] );
			\update_option( 'stylesheet_root', $theme_root );
			\update_option( 'template_root', $theme_root );
			\delete_site_transient( 'theme_roots' );
			\wp_cache_delete( 'theme_roots', 'site-transient' );

			\add_filter( 'stylesheet', $stylesheet_filter );
			\add_filter( 'template', $template_filter );
			\add_filter( 'theme_root', $theme_root_filter );
			\add_filter( 'theme_root_uri', $theme_root_uri_filter );
			\add_filter( 'pre_option_stylesheet', $stylesheet_filter );
			\add_filter( 'pre_option_template', $template_filter );
			\add_filter( 'pre_option_stylesheet_root', $stylesheet_root_filter );
			\add_filter( 'pre_option_template_root', $stylesheet_root_filter );
			\add_filter( 'theme_block_pattern_files', $pattern_files_filter, 10, 2 );
			\add_filter( 'wp_theme_files_cache_ttl', $cache_ttl_filter, 10, 2 );
			\add_filter( 'doing_it_wrong_run', $doing_it_wrong_filter, 10, 3 );
			\add_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10, 4 );
			\add_filter( 'should_load_remote_block_patterns', $remote_off_filter );

			$pre_registered = \register_block_pattern(
				$case['duplicateSlug'],
				array(
					'title'      => $case['duplicatePreTitle'],
					'content'    => self::pattern_content( $case['duplicatePreMarker'] ),
					'categories' => array( 'text' ),
					'keywords'   => array( 'preexisting' ),
					'source'     => 'plugin',
				)
			);

			$theme           = \wp_get_theme();
			$theme_exists    = $theme instanceof \WP_Theme && $theme->exists();
			$active_themes   = \wp_get_active_and_valid_themes();
			$parsed_patterns = $theme_exists ? $theme->get_block_patterns() : array();
			$cache_value     = \get_site_transient( $cache_key );

			\_register_theme_block_patterns();
			$raw_before_rest = self::get_object_property( $registry, 'registered_patterns' );
			$first_register_count = is_array( $raw_before_rest ) ? count( $raw_before_rest ) : null;
			\_register_theme_block_patterns();
			$raw_after_second      = self::get_object_property( $registry, 'registered_patterns' );
			$second_register_count = is_array( $raw_after_second ) ? count( $raw_after_second ) : null;

			self::set_object_property( $controller, 'remote_patterns_loaded', true );
			$response = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/block-patterns/patterns',
					array(
						'context' => 'view',
						'_fields' => 'name,title,description,categories,keywords,block_types,post_types,template_types,viewport_width,inserter,content,source',
					)
				)
			);
			$data     = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		} finally {
			\remove_filter( 'stylesheet', $stylesheet_filter );
			\remove_filter( 'template', $template_filter );
			\remove_filter( 'theme_root', $theme_root_filter );
			\remove_filter( 'theme_root_uri', $theme_root_uri_filter );
			\remove_filter( 'pre_option_stylesheet', $stylesheet_filter );
			\remove_filter( 'pre_option_template', $template_filter );
			\remove_filter( 'pre_option_stylesheet_root', $stylesheet_root_filter );
			\remove_filter( 'pre_option_template_root', $stylesheet_root_filter );
			\remove_filter( 'theme_block_pattern_files', $pattern_files_filter, 10 );
			\remove_filter( 'wp_theme_files_cache_ttl', $cache_ttl_filter, 10 );
			\remove_filter( 'doing_it_wrong_run', $doing_it_wrong_filter, 10 );
			\remove_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10 );
			\remove_filter( 'should_load_remote_block_patterns', $remote_off_filter );

			if ( $theme instanceof \WP_Theme ) {
				$theme->delete_pattern_cache();
			}
			\delete_site_transient( $cache_key );
			\delete_site_transient( 'theme_roots' );
			\wp_cache_delete( 'theme_roots', 'site-transient' );
			$cleanup_ok = self::remove_dir_recursive( $temp_root );
			self::restore_state( $local_snapshot );
		}

		$parsed_valid       = $parsed_patterns[ $case['validFile'] ] ?? null;
		$parsed_duplicate   = $parsed_patterns[ $case['duplicateFile'] ] ?? null;
		$parsed_missing_slug = $parsed_patterns[ $case['missingSlugFile'] ] ?? null;
		$parsed_missing_title = $parsed_patterns[ $case['missingTitleFile'] ] ?? null;
		$raw_valid          = is_array( $raw_before_rest ) ? ( $raw_before_rest[ $case['validSlug'] ] ?? null ) : null;
		$raw_duplicate      = is_array( $raw_before_rest ) ? ( $raw_before_rest[ $case['duplicateSlug'] ] ?? null ) : null;
		$valid_entry        = self::pattern_entry_by_name( $data, $case['validSlug'] );
		$duplicate_entry    = self::pattern_entry_by_name( $data, $case['duplicateSlug'] );
		$missing_slug_entry = self::pattern_entry_by_name( $data, $case['missingSlugValue'] );
		$missing_title_entry = self::pattern_entry_by_name( $data, $case['missingTitleSlug'] );
		$doing_it_wrong_messages = array_map(
			static fn ( $entry ) => is_array( $entry ) ? ( $entry['message'] ?? '' ) : '',
			$doing_it_wrong_log
		);
		$logged_missing_slug = (bool) array_filter(
			$doing_it_wrong_messages,
			static fn ( string $message ): bool => str_contains( $message, 'Slug' ) && str_contains( $message, 'missing' )
		);
		$logged_missing_title = (bool) array_filter(
			$doing_it_wrong_messages,
			static fn ( string $message ): bool => str_contains( $message, 'Title' ) && str_contains( $message, 'missing' )
		);
		$scanned_basenames = $pattern_file_log[0]['basenames'] ?? array();
		$cache_ok          = $theme_development_mode
			? false === $cache_value
			: is_array( $cache_value )
				&& ( $cache_value['patterns'][ $case['validFile'] ] ?? null ) === $parsed_valid
				&& 1 === count(
					array_filter(
						$cache_ttl_log,
						static fn ( array $entry ): bool => 120 === ( $entry['returnTtl'] ?? null )
							&& 'theme_block_patterns' === ( $entry['cacheType'] ?? null )
					)
				);

		self::collect_failure(
			$failures,
			$theme_exists
				&& array( $theme_dir ) === $active_themes
				&& isset( $pattern_file_log[0] )
				&& $patterns_dir === ( $pattern_file_log[0]['dirpath'] ?? null )
				&& in_array( $case['validFile'], $scanned_basenames, true )
				&& in_array( $case['duplicateFile'], $scanned_basenames, true )
				&& ! in_array( $case['ignoredFile'], $scanned_basenames, true )
				&& is_array( $parsed_valid )
				&& is_array( $parsed_duplicate )
				&& null === $parsed_missing_slug
				&& null === $parsed_missing_title
				&& $case['validTitle'] === ( $parsed_valid['title'] ?? null )
				&& $case['validSlug'] === ( $parsed_valid['slug'] ?? null )
				&& $case['validDescription'] === ( $parsed_valid['description'] ?? null )
				&& $case['validViewport'] === ( $parsed_valid['viewportWidth'] ?? null )
				&& false === ( $parsed_valid['inserter'] ?? null )
				&& array( 'buttons', 'gallery' ) === ( $parsed_valid['categories'] ?? null )
				&& $case['keywords'] === ( $parsed_valid['keywords'] ?? null )
				&& array( 'core/paragraph', 'core/group' ) === ( $parsed_valid['blockTypes'] ?? null )
				&& array( 'post', 'page' ) === ( $parsed_valid['postTypes'] ?? null )
				&& array( 'front-page', 'single' ) === ( $parsed_valid['templateTypes'] ?? null )
				&& $cache_ok
				&& $logged_missing_slug
				&& $logged_missing_title,
			'WP_Theme::get_block_patterns scans local PHP pattern files, parses metadata types, caches results, and rejects missing required headers',
			array(
				'case'            => self::theme_pattern_file_summary( $case ),
				'themeExists'     => $theme_exists,
				'activeThemes'    => $active_themes,
				'patternFileLog'  => $pattern_file_log,
				'parsedValid'     => $parsed_valid,
				'parsedDuplicate' => $parsed_duplicate,
				'cacheValue'      => $cache_value,
				'cacheTtlLog'     => $cache_ttl_log,
				'doingItWrong'    => $doing_it_wrong_log,
			)
		);

		self::collect_failure(
			$failures,
			true === ( $pre_registered ?? false )
				&& $response instanceof \WP_REST_Response
				&& is_array( $raw_valid )
				&& is_array( $raw_duplicate )
				&& $case['validFilePath'] === ( $raw_valid['filePath'] ?? null )
				&& ! array_key_exists( 'content', $raw_valid )
				&& $case['duplicatePreTitle'] === ( $raw_duplicate['title'] ?? null )
				&& isset( $raw_duplicate['content'] )
				&& $second_register_count === $first_register_count
				&& is_array( $valid_entry )
				&& is_array( $duplicate_entry )
				&& null === $missing_slug_entry
				&& null === $missing_title_entry
				&& $case['validSlug'] === ( $valid_entry['name'] ?? null )
				&& $case['validTitle'] === ( $valid_entry['title'] ?? null )
				&& $case['validDescription'] === ( $valid_entry['description'] ?? null )
				&& in_array( 'call-to-action', $valid_entry['categories'] ?? array(), true )
				&& in_array( 'gallery', $valid_entry['categories'] ?? array(), true )
				&& $case['keywords'] === ( $valid_entry['keywords'] ?? null )
				&& array( 'core/paragraph', 'core/group' ) === ( $valid_entry['block_types'] ?? null )
				&& array( 'post', 'page' ) === ( $valid_entry['post_types'] ?? null )
				&& array( 'front-page', 'single' ) === ( $valid_entry['template_types'] ?? null )
				&& $case['validViewport'] === (int) ( $valid_entry['viewport_width'] ?? 0 )
				&& false === ( $valid_entry['inserter'] ?? null )
				&& str_contains( $valid_entry['content'] ?? '', $case['validMarker'] )
				&& ! array_key_exists( 'source', $valid_entry )
				&& $case['duplicatePreTitle'] === ( $duplicate_entry['title'] ?? null )
				&& 'plugin' === ( $duplicate_entry['source'] ?? null )
				&& str_contains( $duplicate_entry['content'] ?? '', $case['duplicatePreMarker'] ),
			'_register_theme_block_patterns registers local file patterns lazily, preserves pre-registered duplicates, and REST exposes parsed fields/content',
			array(
				'case'                => self::theme_pattern_file_summary( $case ),
				'rawValid'            => $raw_valid,
				'rawDuplicate'        => $raw_duplicate,
				'firstRegisterCount'  => $first_register_count ?? null,
				'secondRegisterCount' => $second_register_count,
				'validEntry'          => $valid_entry,
				'duplicateEntry'      => $duplicate_entry,
				'data'                => $data,
			)
		);

		self::collect_failure(
			$failures,
			$cleanup_ok
				&& ! file_exists( $temp_root )
				&& false === \has_filter( 'stylesheet', $stylesheet_filter )
				&& false === \has_filter( 'template', $template_filter )
				&& false === \has_filter( 'theme_root', $theme_root_filter )
				&& false === \has_filter( 'theme_root_uri', $theme_root_uri_filter )
				&& false === \has_filter( 'pre_option_stylesheet', $stylesheet_filter )
				&& false === \has_filter( 'pre_option_template', $template_filter )
				&& false === \has_filter( 'pre_option_stylesheet_root', $stylesheet_root_filter )
				&& false === \has_filter( 'pre_option_template_root', $stylesheet_root_filter )
				&& false === \has_filter( 'theme_block_pattern_files', $pattern_files_filter )
				&& false === \has_filter( 'wp_theme_files_cache_ttl', $cache_ttl_filter )
				&& false === \has_filter( 'doing_it_wrong_run', $doing_it_wrong_filter )
				&& false === \has_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong )
				&& false === \has_filter( 'should_load_remote_block_patterns', $remote_off_filter )
				&& false === \get_site_transient( $cache_key ),
			'theme pattern file loader cleanup removes temp files, cache entries, and scoped filters',
			array(
				'cleanupOk'     => $cleanup_ok,
				'tempExists'    => file_exists( $temp_root ),
				'cacheAfter'    => \get_site_transient( $cache_key ),
				'hasFileFilter' => \has_filter( 'theme_block_pattern_files', $pattern_files_filter ),
				'hasTtlFilter'  => \has_filter( 'wp_theme_files_cache_ttl', $cache_ttl_filter ),
			)
		);

		return self::row(
			$ctx,
			'rest-controllers.block-patterns.theme-files',
			array() === $failures,
			array(
				'stylesheet' => $case['stylesheet'],
				'validSlug'  => $case['validSlug'],
				'failures'   => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_route_registry_behavior( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$post_case     = self::post_type_case( $ctx->fork( 'routes-post-types' ) );
		$taxonomy_case = self::taxonomy_case( $ctx->fork( 'routes-taxonomies' ) );
		$settings_case = self::settings_case( $ctx->fork( 'routes-settings' ) );
		$block_case    = self::block_type_case( $ctx->fork( 'routes-block-types' ) );
		$pattern_case  = self::block_pattern_case( $ctx->fork( 'routes-patterns' ) );
		$failures      = array();

		$previous_server = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions  = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions = $GLOBALS['wp_actions'] ?? null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		try {
			$post_type = \register_post_type(
				$post_case['postType'],
				array(
					'label'          => $post_case['label'],
					'public'         => true,
					'show_in_rest'   => true,
					'rest_base'      => $post_case['restBase'],
					'rest_namespace' => 'wp/v2',
					'rewrite'        => false,
					'query_var'      => false,
				)
			);
			$taxonomy  = \register_taxonomy(
				$taxonomy_case['taxonomy'],
				$post_case['postType'],
				array(
					'label'          => $taxonomy_case['label'],
					'public'         => true,
					'show_in_rest'   => true,
					'rest_base'      => $taxonomy_case['restBase'],
					'rest_namespace' => 'wp/v2',
					'rewrite'        => false,
					'query_var'      => false,
				)
			);
			\register_post_status( 'trash', array( 'label' => 'Trash', 'internal' => true ) );
			\register_post_status( $post_case['publicStatus'], array( 'label' => $post_case['publicStatusLabel'], 'public' => true ) );
			\register_setting(
				'component_fuzz',
				$settings_case['stringOption'],
				array(
					'type'         => 'string',
					'default'      => $settings_case['stringDefault'],
					'show_in_rest' => array(
						'name'   => $settings_case['stringName'],
						'schema' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
					),
				)
			);
			\update_option( $settings_case['stringOption'], $settings_case['storedString'] );

			$block = \register_block_type(
				$block_case['blockName'],
				array(
					'api_version'     => 3,
					'title'           => $block_case['title'],
					'description'     => $block_case['description'],
					'category'        => 'widgets',
					'render_callback' => static function (): string {
						return '<p>component fuzz</p>';
					},
				)
			);

			\WP_Block_Pattern_Categories_Registry::get_instance()->register(
				$pattern_case['categoryName'],
				array(
					'label'       => $pattern_case['categoryLabel'],
					'description' => $pattern_case['categoryDescription'],
				)
			);
			\WP_Block_Patterns_Registry::get_instance()->register(
				$pattern_case['patternName'],
				array(
					'title'      => $pattern_case['patternTitle'],
					'content'    => $pattern_case['content'],
					'categories' => array( $pattern_case['categoryName'] ),
					'source'     => 'plugin',
				)
			);

			$post_types_controller = new \WP_REST_Post_Types_Controller();
			$statuses_controller   = new \WP_REST_Post_Statuses_Controller();
			$taxonomies_controller = new \WP_REST_Taxonomies_Controller();
			$settings_controller   = new \WP_REST_Settings_Controller();
			$block_types_controller = new \WP_REST_Block_Types_Controller();
			$patterns_controller   = new \WP_REST_Block_Patterns_Controller();
			$categories_controller = new \WP_REST_Block_Pattern_Categories_Controller();
			self::set_object_property( $patterns_controller, 'remote_patterns_loaded', true );

			foreach (
				array(
					$post_types_controller,
					$statuses_controller,
					$taxonomies_controller,
					$settings_controller,
					$block_types_controller,
					$patterns_controller,
					$categories_controller,
				) as $controller
			) {
				$controller->register_routes();
			}

			$routes          = $server->get_routes( 'wp/v2' );
			$registered_keys = array_keys( $routes );
			sort( $registered_keys );
			$expected_routes = array(
				'/wp/v2/block-patterns/categories',
				'/wp/v2/block-patterns/patterns',
				'/wp/v2/block-types',
				'/wp/v2/block-types/(?P<namespace>[a-zA-Z0-9_-]+)',
				'/wp/v2/block-types/(?P<namespace>[a-zA-Z0-9_-]+)/(?P<name>[a-zA-Z0-9_-]+)',
				'/wp/v2/settings',
				'/wp/v2/statuses',
				'/wp/v2/statuses/(?P<status>[\w-]+)',
				'/wp/v2/taxonomies',
				'/wp/v2/taxonomies/(?P<taxonomy>[\w-]+)',
				'/wp/v2/types',
				'/wp/v2/types/(?P<type>[\w-]+)',
			);
			$missing_routes  = array_values( array_diff( $expected_routes, $registered_keys ) );
			$settings_methods = self::route_methods( $routes['/wp/v2/settings'] ?? array() );
			$types_methods    = self::route_methods( $routes['/wp/v2/types'] ?? array() );

			self::collect_failure(
				$failures,
				$post_type instanceof \WP_Post_Type
					&& $taxonomy instanceof \WP_Taxonomy
					&& $block instanceof \WP_Block_Type
					&& in_array( 'wp/v2', $server->get_namespaces(), true )
					&& array() === $missing_routes
					&& in_array( 'GET', $types_methods, true )
					&& in_array( 'GET', $settings_methods, true )
					&& in_array( 'POST', $settings_methods, true )
					&& in_array( 'PATCH', $settings_methods, true )
					&& in_array( 'PUT', $settings_methods, true )
					&& is_callable( $server->get_route_options( '/wp/v2/types' )['schema'] ?? null )
					&& is_callable( $server->get_route_options( '/wp/v2/settings' )['schema'] ?? null ),
				'controllers register expected wp/v2 read/edit route handlers and schemas',
				array(
					'expectedRoutes'   => $expected_routes,
					'registeredRoutes' => $registered_keys,
					'missingRoutes'    => $missing_routes,
					'typesMethods'     => $types_methods,
					'settingsMethods'  => $settings_methods,
				)
			);

			$cap_filter = self::install_cap_filter( array( 'edit_posts', 'manage_options' ) );
			try {
				$type_request = self::request(
					'GET',
					'/wp/v2/types/' . $post_case['postType'],
					array( 'context' => 'edit' )
				);
				$type_response = $server->dispatch( $type_request );

				$taxonomy_request = self::request(
					'GET',
					'/wp/v2/taxonomies/' . $taxonomy_case['taxonomy'],
					array( 'context' => 'edit' )
				);
				$taxonomy_response = $server->dispatch( $taxonomy_request );

				$settings_response = $server->dispatch( self::request( 'GET', '/wp/v2/settings' ) );

				$block_request = self::request(
					'GET',
					'/wp/v2/block-types/' . $block_case['blockName'],
					array(
						'context' => 'view',
						'_fields' => 'name,title,is_dynamic',
					)
				);
				$block_response = $server->dispatch( $block_request );

				$pattern_response = $server->dispatch(
					self::request(
						'GET',
						'/wp/v2/block-patterns/patterns',
						array(
							'context' => 'view',
							'_fields' => 'name,source',
						)
					)
				);
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}

			$type_data     = $type_response instanceof \WP_REST_Response ? $type_response->get_data() : array();
			$taxonomy_data = $taxonomy_response instanceof \WP_REST_Response ? $taxonomy_response->get_data() : array();
			$settings_data = $settings_response instanceof \WP_REST_Response ? $settings_response->get_data() : array();
			$block_data    = $block_response instanceof \WP_REST_Response ? $block_response->get_data() : array();
			$pattern_data  = $pattern_response instanceof \WP_REST_Response ? $pattern_response->get_data() : array();
			$pattern_entry = $pattern_data[0] ?? array();

			self::collect_failure(
				$failures,
				$type_response instanceof \WP_REST_Response
					&& 200 === $type_response->get_status()
					&& $post_case['postType'] === ( $type_data['slug'] ?? null )
					&& $post_case['postType'] === ( $type_request->get_url_params()['type'] ?? null )
					&& $taxonomy_response instanceof \WP_REST_Response
					&& 200 === $taxonomy_response->get_status()
					&& $taxonomy_case['taxonomy'] === ( $taxonomy_data['slug'] ?? null )
					&& $taxonomy_case['taxonomy'] === ( $taxonomy_request->get_url_params()['taxonomy'] ?? null )
					&& $settings_response instanceof \WP_REST_Response
					&& 200 === $settings_response->get_status()
					&& $settings_case['storedString'] === ( $settings_data[ $settings_case['stringName'] ] ?? null )
					&& $block_response instanceof \WP_REST_Response
					&& 200 === $block_response->get_status()
					&& $block_case['blockName'] === ( $block_data['name'] ?? null )
					&& array(
						'namespace' => $block_case['namespace'],
						'name'      => $block_case['name'],
					) === $block_request->get_url_params()
					&& $pattern_response instanceof \WP_REST_Response
					&& 200 === $pattern_response->get_status()
					&& $pattern_case['patternName'] === ( $pattern_entry['name'] ?? null )
					&& 'plugin' === ( $pattern_entry['source'] ?? null ),
				'registered routes dispatch to controller callbacks with URL params and no DB-backed objects',
				array(
					'typeData'      => $type_data,
					'typeUrlParams' => $type_request->get_url_params(),
					'taxonomyData'  => $taxonomy_data,
					'settingsData'  => $settings_data,
					'blockData'     => $block_data,
					'blockUrlParams' => $block_request->get_url_params(),
					'patternEntry'  => $pattern_entry,
				)
			);

			$head_response     = $server->dispatch( self::request( 'HEAD', '/wp/v2/types/' . $post_case['postType'] ) );
			$missing_response  = $server->dispatch( self::request( 'GET', '/wp/v2/not-a-controller' ) );
			$readonly_response = $server->dispatch( self::request( 'DELETE', '/wp/v2/types' ) );

			self::collect_failure(
				$failures,
				$head_response instanceof \WP_REST_Response
					&& 200 === $head_response->get_status()
					&& array() === $head_response->get_data()
					&& self::response_error_ok( $missing_response, 'rest_no_route', 404 )
					&& self::response_error_ok( $readonly_response, 'rest_no_route', 404 ),
				'registered route dispatch preserves HEAD fallback and represented no-route error shapes',
				array(
					'headData'      => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
					'missingRoute'  => $missing_response,
					'readonlyRoute' => $readonly_response,
				)
			);
		} finally {
			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $previous_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}

			if ( null !== $previous_server ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		return self::row(
			$ctx,
			'rest-controllers.route-registry-dispatch-errors',
			array() === $failures,
			array(
				'cases'    => array(
					'postType' => $post_case,
					'taxonomy' => $taxonomy_case,
					'settings' => $settings_case,
					'block'    => $block_case,
					'pattern'  => $pattern_case,
				),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_plugin_theme_controller_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$plugin_slug      = self::route_token( $ctx->fork( 'plugin' ), 'cfz-plugin' );
		$theme_stylesheet = self::route_token( $ctx->fork( 'theme' ), 'cfz-theme' );
		$failures         = array();

		$previous_server  = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions   = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions = $GLOBALS['wp_actions'] ?? null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		try {
			$plugins = new \WP_REST_Plugins_Controller();
			$themes  = new \WP_REST_Themes_Controller();

			$plugins->register_routes();
			$themes->register_routes();

			$routes          = $server->get_routes( 'wp/v2' );
			$registered_keys = array_keys( $routes );
			sort( $registered_keys );

			$plugin_collection_route = '/wp/v2/plugins';
			$plugin_item_route       = '/wp/v2/plugins/(?P<plugin>[^.\/]+(?:\/[^.\/]+)?)';
			$theme_collection_route  = '/wp/v2/themes';
			$theme_item_route        = '/wp/v2/themes/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)';
			$expected_routes         = array(
				$plugin_collection_route,
				$plugin_item_route,
				$theme_collection_route,
				$theme_item_route,
			);
			$missing_routes          = array_values( array_diff( $expected_routes, $registered_keys ) );

			$plugin_collection_methods = self::route_methods( $routes[ $plugin_collection_route ] ?? array() );
			$plugin_item_methods       = self::route_methods( $routes[ $plugin_item_route ] ?? array() );
			$theme_collection_methods  = self::route_methods( $routes[ $theme_collection_route ] ?? array() );
			$theme_item_methods        = self::route_methods( $routes[ $theme_item_route ] ?? array() );

			self::collect_failure(
				$failures,
				array() === $missing_routes
					&& array( 'GET', 'POST' ) === $plugin_collection_methods
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $plugin_item_methods
					&& array( 'GET' ) === $theme_collection_methods
					&& array( 'GET' ) === $theme_item_methods
					&& is_callable( $server->get_route_options( $plugin_collection_route )['schema'] ?? null )
					&& is_callable( $server->get_route_options( $plugin_item_route )['schema'] ?? null )
					&& is_callable( $server->get_route_options( $theme_collection_route )['schema'] ?? null )
					&& is_callable( $server->get_route_options( $theme_item_route )['schema'] ?? null ),
				'plugin and theme controllers register expected wp/v2 route methods and schema callbacks',
				array(
					'expectedRoutes'           => $expected_routes,
					'registeredRoutes'         => $registered_keys,
					'missingRoutes'            => $missing_routes,
					'pluginCollectionMethods'  => $plugin_collection_methods,
					'pluginItemMethods'        => $plugin_item_methods,
					'themeCollectionMethods'   => $theme_collection_methods,
					'themeItemMethods'         => $theme_item_methods,
				)
			);

			$plugin_collection_params = $plugins->get_collection_params();
			$theme_collection_params  = $themes->get_collection_params();
			$plugin_create_args       = self::handler_args_for_method( $routes[ $plugin_collection_route ] ?? array(), 'POST' );
			$plugin_item_args         = self::handler_args_for_method( $routes[ $plugin_item_route ] ?? array(), 'GET' );
			$theme_item_args          = self::handler_args_for_method( $routes[ $theme_item_route ] ?? array(), 'GET' );
			$plugin_status_enum       = $plugin_collection_params['status']['items']['enum'] ?? array();
			$theme_status_enum        = $theme_collection_params['status']['items']['enum'] ?? array();
			$plugin_item_pattern      = $plugin_item_args['plugin']['pattern'] ?? null;
			$theme_status_sorted      = is_array( $theme_status_enum ) ? $theme_status_enum : array();
			sort( $theme_status_sorted );

			self::collect_failure(
				$failures,
				self::collection_context_param_ok( $plugin_collection_params )
					&& isset( $plugin_collection_params['search'] )
					&& ! isset( $plugin_collection_params['page'], $plugin_collection_params['per_page'] )
					&& 'array' === ( $plugin_collection_params['status']['type'] ?? null )
					&& is_array( $plugin_status_enum )
					&& in_array( 'inactive', $plugin_status_enum, true )
					&& in_array( 'active', $plugin_status_enum, true )
					&& array() === array_diff( $plugin_status_enum, array( 'inactive', 'active', 'network-active' ) )
					&& true === ( $plugin_create_args['slug']['required'] ?? null )
					&& '[\w\-]+' === ( $plugin_create_args['slug']['pattern'] ?? null )
					&& 'inactive' === ( $plugin_create_args['status']['default'] ?? null )
					&& is_callable( $plugin_item_args['plugin']['validate_callback'] ?? null )
					&& is_callable( $plugin_item_args['plugin']['sanitize_callback'] ?? null )
					&& '[^.\/]+(?:\/[^.\/]+)?' === $plugin_item_pattern
					&& 'view' === ( $plugin_item_args['context']['default'] ?? null )
					&& 'array' === ( $theme_collection_params['status']['type'] ?? null )
					&& array( 'active', 'inactive' ) === $theme_status_sorted
					&& is_callable( $theme_item_args['stylesheet']['sanitize_callback'] ?? null ),
				'collection and route params expose bounded status, slug, plugin-file, and stylesheet contracts',
				array(
					'pluginCollectionParams' => self::param_summary( $plugin_collection_params ),
					'themeCollectionParams'  => self::param_summary( $theme_collection_params ),
					'pluginCreateArgs'       => self::param_summary( $plugin_create_args ),
					'pluginItemArgs'         => self::param_summary( $plugin_item_args ),
					'themeItemArgs'          => self::param_summary( $theme_item_args ),
				)
			);

			$plugin_schema          = $plugins->get_item_schema();
			$theme_schema           = $themes->get_item_schema();
			$plugin_properties      = $plugin_schema['properties'] ?? array();
			$theme_properties       = $theme_schema['properties'] ?? array();
			$plugin_property_names  = array_keys( $plugin_properties );
			$theme_property_names   = array_keys( $theme_properties );
			$expected_plugin_props  = array(
				'plugin',
				'status',
				'name',
				'plugin_uri',
				'author',
				'author_uri',
				'description',
				'version',
				'network_only',
				'requires_wp',
				'requires_php',
				'textdomain',
			);
			$expected_theme_props   = array(
				'stylesheet',
				'stylesheet_uri',
				'template',
				'template_uri',
				'author',
				'author_uri',
				'description',
				'is_block_theme',
				'name',
				'requires_php',
				'requires_wp',
				'screenshot',
				'tags',
				'textdomain',
				'theme_supports',
				'theme_uri',
				'version',
				'status',
				'default_template_types',
				'default_template_part_areas',
			);
			$missing_plugin_props   = array_values( array_diff( $expected_plugin_props, $plugin_property_names ) );
			$missing_theme_props    = array_values( array_diff( $expected_theme_props, $theme_property_names ) );
			$schema_plugin_statuses = $plugin_properties['status']['enum'] ?? array();
			$schema_theme_statuses  = $theme_properties['status']['enum'] ?? array();
			$schema_theme_sorted    = is_array( $schema_theme_statuses ) ? $schema_theme_statuses : array();
			sort( $schema_theme_sorted );

			self::collect_failure(
				$failures,
				'plugin' === ( $plugin_schema['title'] ?? null )
					&& 'object' === ( $plugin_schema['type'] ?? null )
					&& array() === $missing_plugin_props
					&& 'string' === ( $plugin_properties['plugin']['type'] ?? null )
					&& '[^.\/]+(?:\/[^.\/]+)?' === ( $plugin_properties['plugin']['pattern'] ?? null )
					&& 'boolean' === ( $plugin_properties['network_only']['type'] ?? null )
					&& is_array( $schema_plugin_statuses )
					&& in_array( 'inactive', $schema_plugin_statuses, true )
					&& in_array( 'active', $schema_plugin_statuses, true )
					&& array() === array_diff( $schema_plugin_statuses, array( 'inactive', 'active', 'network-active' ) )
					&& 'theme' === ( $theme_schema['title'] ?? null )
					&& 'object' === ( $theme_schema['type'] ?? null )
					&& array() === $missing_theme_props
					&& 'boolean' === ( $theme_properties['is_block_theme']['type'] ?? null )
					&& 'object' === ( $theme_properties['theme_supports']['type'] ?? null )
					&& array( 'active', 'inactive' ) === $schema_theme_sorted
					&& 'array' === ( $theme_properties['default_template_types']['type'] ?? null ),
				'public schemas preserve plugin and theme identity, status, metadata, and capability fields',
				array(
					'missingPluginProperties' => $missing_plugin_props,
					'missingThemeProperties'  => $missing_theme_props,
					'pluginStatusEnum'        => $schema_plugin_statuses,
					'themeStatusEnum'         => $schema_theme_statuses,
					'pluginPropertyCount'     => count( $plugin_property_names ),
					'themePropertyCount'      => count( $theme_property_names ),
				)
			);

			$plugin_file              = $plugin_slug . '/' . $plugin_slug . '.php';
			$encoded_theme_stylesheet = rawurlencode( $theme_stylesheet . '/child' );

			self::collect_failure(
				$failures,
				true === $plugins->validate_plugin_param( $plugin_slug )
					&& true === $plugins->validate_plugin_param( $plugin_slug . '/' . $plugin_slug )
					&& false === $plugins->validate_plugin_param( '../' . $plugin_slug )
					&& false === $plugins->validate_plugin_param( array() )
					&& $plugin_slug . '.php' === $plugins->sanitize_plugin_param( $plugin_slug )
					&& $plugin_file === $plugins->sanitize_plugin_param( $plugin_slug . '/' . $plugin_slug )
					&& $theme_stylesheet . '/child' === $themes->_sanitize_stylesheet_callback( $encoded_theme_stylesheet ),
				'public sanitizers and validators normalize plugin files and encoded theme stylesheets without filesystem reads',
				array(
					'pluginSlug'              => $plugin_slug,
					'pluginFile'              => $plugin_file,
					'encodedThemeStylesheet'  => $encoded_theme_stylesheet,
				)
			);

			$plugin_denied_list           = $plugins->get_items_permissions_check( self::request( 'GET', $plugin_collection_route ) );
			$plugin_denied_create         = $plugins->create_item_permissions_check( self::request( 'POST', $plugin_collection_route, array( 'status' => 'inactive' ) ) );
			$theme_denied_list            = $themes->get_items_permissions_check( self::request( 'GET', $theme_collection_route ) );
			$theme_denied_active_list     = $themes->get_items_permissions_check( self::request( 'GET', $theme_collection_route, array( 'status' => array( 'active' ) ) ) );
			$plugin_denied_activate       = null;
			$plugin_allowed_list          = null;
			$plugin_allowed_active_create = null;
			$theme_allowed_active_list    = null;
			$theme_allowed_list           = null;
			$theme_allowed_item           = null;

			$cap_filter = self::install_cap_filter( array( 'install_plugins' ) );
			try {
				$plugin_denied_activate = $plugins->create_item_permissions_check( self::request( 'POST', $plugin_collection_route, array( 'status' => 'active' ) ) );
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}

			$cap_filter = self::install_cap_filter( array( 'activate_plugins', 'install_plugins' ) );
			try {
				$plugin_allowed_list          = $plugins->get_items_permissions_check( self::request( 'GET', $plugin_collection_route ) );
				$plugin_allowed_active_create = $plugins->create_item_permissions_check( self::request( 'POST', $plugin_collection_route, array( 'status' => 'active' ) ) );
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}

			$cap_filter = self::install_cap_filter( array( 'edit_posts' ) );
			try {
				$theme_allowed_active_list = $themes->get_items_permissions_check( self::request( 'GET', $theme_collection_route, array( 'status' => array( 'active' ) ) ) );
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}

			$cap_filter = self::install_cap_filter( array( 'switch_themes' ) );
			try {
				$theme_allowed_list = $themes->get_items_permissions_check( self::request( 'GET', $theme_collection_route ) );
				$theme_allowed_item = $themes->get_item_permissions_check(
					self::request(
						'GET',
						$theme_item_route,
						array(),
						array( 'stylesheet' => $theme_stylesheet )
					)
				);
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}

			self::collect_failure(
				$failures,
				self::wp_error_ok( $plugin_denied_list, 'rest_cannot_view_plugins', \rest_authorization_required_code() )
					&& self::wp_error_ok( $plugin_denied_create, 'rest_cannot_install_plugin', \rest_authorization_required_code() )
					&& self::wp_error_ok( $plugin_denied_activate, 'rest_cannot_activate_plugin', \rest_authorization_required_code() )
					&& true === $plugin_allowed_list
					&& true === $plugin_allowed_active_create
					&& self::wp_error_ok( $theme_denied_list, 'rest_cannot_view_themes', \rest_authorization_required_code() )
					&& self::wp_error_ok( $theme_denied_active_list, 'rest_cannot_view_active_theme', \rest_authorization_required_code() )
					&& true === $theme_allowed_active_list
					&& true === $theme_allowed_list
					&& true === $theme_allowed_item,
				'permission callbacks gate plugin collection/create and theme collection/item access before lifecycle callbacks run',
				array(
					'pluginDeniedList'          => $plugin_denied_list,
					'pluginDeniedCreate'        => $plugin_denied_create,
					'pluginDeniedActivate'      => $plugin_denied_activate,
					'pluginAllowedList'         => $plugin_allowed_list,
					'pluginAllowedActiveCreate' => $plugin_allowed_active_create,
					'themeDeniedList'           => $theme_denied_list,
					'themeDeniedActiveList'     => $theme_denied_active_list,
					'themeAllowedActiveList'    => $theme_allowed_active_list,
					'themeAllowedList'          => $theme_allowed_list,
					'themeAllowedItem'          => $theme_allowed_item,
				)
			);
		} finally {
			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $previous_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}

			if ( null !== $previous_server ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		return self::row(
			$ctx,
			'rest-controllers.plugin-theme-controller-contracts',
			array() === $failures,
			array(
				'cases'    => array(
					'pluginSlug'      => $plugin_slug,
					'themeStylesheet' => $theme_stylesheet,
				),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_search_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case              = self::search_case( $ctx->fork( 'search' ) );
		$handler           = self::make_search_handler( $case['type'], $case['subtypes'], $case['items'], $case['handlerIds'], $case['handlerTotal'] );
		$secondary_handler = self::make_search_handler( $case['secondaryType'], $case['secondarySubtypes'], array(), array(), 0 );
		$failures          = array();
		$doing_it_wrong    = array();

		$doing_it_wrong_filter = static function ( string $function_name, string $message, string $version ) use ( &$doing_it_wrong ): void {
			$doing_it_wrong[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};

		\add_filter( 'doing_it_wrong_run', $doing_it_wrong_filter, 10, 3 );
		try {
			$controller = new \WP_REST_Search_Controller(
				array(
					(object) array( 'not' => 'a search handler' ),
					$handler,
					$secondary_handler,
				)
			);
		} finally {
			\remove_filter( 'doing_it_wrong_run', $doing_it_wrong_filter, 10 );
		}

		$registered_handlers = self::get_object_property( $controller, 'search_handlers' );
		self::collect_failure(
			$failures,
			is_array( $registered_handlers )
				&& array( $case['type'], $case['secondaryType'] ) === array_keys( $registered_handlers )
				&& $registered_handlers[ $case['type'] ] === $handler
				&& $registered_handlers[ $case['secondaryType'] ] === $secondary_handler
				&& array(
					array(
						'function' => 'WP_REST_Search_Controller::__construct',
						'version'  => '5.0.0',
					),
				) === array_map(
					static function ( array $call ): array {
						return array(
							'function' => $call['function'],
							'version'  => $call['version'],
						);
					},
					$doing_it_wrong
				),
			'search controller constructor rejects invalid handlers and registers valid handler types',
			array(
				'registeredTypes' => is_array( $registered_handlers ) ? array_keys( $registered_handlers ) : $registered_handlers,
				'doingItWrong'    => $doing_it_wrong,
			)
		);

		$params        = $controller->get_collection_params();
		$subtype_enum  = $params[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['items']['enum'] ?? array();
		$expected_enum = array_merge( $case['subtypes'], $case['secondarySubtypes'], array( \WP_REST_Search_Controller::TYPE_ANY ) );
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& array( $case['type'], $case['secondaryType'] ) === ( $params[ \WP_REST_Search_Controller::PROP_TYPE ]['enum'] ?? null )
				&& $case['type'] === ( $params[ \WP_REST_Search_Controller::PROP_TYPE ]['default'] ?? null )
				&& 'string' === ( $params[ \WP_REST_Search_Controller::PROP_TYPE ]['type'] ?? null )
				&& \WP_REST_Search_Controller::TYPE_ANY === ( $params[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['default'] ?? null )
				&& 'array' === ( $params[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['type'] ?? null )
				&& $expected_enum === $subtype_enum
				&& is_callable( $params[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['sanitize_callback'] ?? null )
				&& array() === ( $params['include']['default'] ?? null )
				&& array() === ( $params['exclude']['default'] ?? null )
				&& 'integer' === ( $params['include']['items']['type'] ?? null )
				&& 'integer' === ( $params['exclude']['items']['type'] ?? null ),
			'search collection params expose type, subtype, include, exclude, and view context contracts',
			array( 'params' => self::param_summary( $params ) )
		);

		$sanitize_request = self::request(
			'GET',
			'/wp/v2/search',
			array(
				\WP_REST_Search_Controller::PROP_TYPE => $case['type'],
			)
		);
		$sanitize_request->set_attributes( array( 'args' => $params ) );

		$any_subtypes = $controller->sanitize_subtypes(
			array( $case['subtypes'][0], \WP_REST_Search_Controller::TYPE_ANY ),
			$sanitize_request,
			\WP_REST_Search_Controller::PROP_SUBTYPE
		);
		$intersected_subtypes = $controller->sanitize_subtypes(
			array( $case['subtypes'][1], $case['secondarySubtypes'][0] ),
			$sanitize_request,
			\WP_REST_Search_Controller::PROP_SUBTYPE
		);
		$invalid_type_request = self::request(
			'GET',
			'/wp/v2/search',
			array(
				\WP_REST_Search_Controller::PROP_TYPE => $case['invalidType'],
			)
		);
		$invalid_type_request->set_attributes( array( 'args' => $params ) );
		$invalid_type_subtypes = $controller->sanitize_subtypes(
			array( $case['subtypes'][0] ),
			$invalid_type_request,
			\WP_REST_Search_Controller::PROP_SUBTYPE
		);
		$empty_subtype_controller = new \WP_REST_Search_Controller(
			array(
				self::make_search_handler( $case['emptySubtypeType'], array(), array(), array(), 0 ),
			)
		);
		$empty_subtype_params     = $empty_subtype_controller->get_collection_params();
		$empty_subtype_request    = self::request(
			'GET',
			'/wp/v2/search',
			array(
				\WP_REST_Search_Controller::PROP_TYPE => $case['emptySubtypeType'],
			)
		);
		$empty_subtype_request->set_attributes( array( 'args' => $empty_subtype_params ) );
		$invalid_subtype_subtypes = $empty_subtype_controller->sanitize_subtypes(
			array( $case['invalidSubtype'] ),
			$empty_subtype_request,
			\WP_REST_Search_Controller::PROP_SUBTYPE
		);

		self::collect_failure(
			$failures,
			array( \WP_REST_Search_Controller::TYPE_ANY ) === $any_subtypes
				&& array( $case['subtypes'][1] ) === array_values( $intersected_subtypes )
				&& self::wp_error_ok( $invalid_type_subtypes, 'rest_search_invalid_type', 400 )
				&& $invalid_subtype_subtypes instanceof \WP_Error
				&& 'rest_not_in_enum' === $invalid_subtype_subtypes->get_error_code(),
			'search sanitize_subtypes applies any dominance, selected-handler intersection, invalid-type errors, and invalid-subtype schema errors',
			array(
				'any'            => $any_subtypes,
				'intersected'    => $intersected_subtypes,
				'invalidType'    => $invalid_type_subtypes,
				'invalidSubtype' => $invalid_subtype_subtypes,
				'invalidSubtypeEnum' => $empty_subtype_params[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['items']['enum'] ?? null,
			)
		);

		$query = array(
			'context'                                      => 'view',
			'page'                                         => 2,
			'per_page'                                     => 2,
			'search'                                       => $case['search'],
			\WP_REST_Search_Controller::PROP_TYPE          => $case['type'],
			\WP_REST_Search_Controller::PROP_SUBTYPE       => array( \WP_REST_Search_Controller::TYPE_ANY ),
			'include'                                      => $case['include'],
			'exclude'                                      => $case['exclude'],
		);
		$get_request = self::request( 'GET', '/wp/v2/search', $query );
		$get_items   = $controller->get_items( $get_request );
		$expected_ids = $case['handlerIds'];
		$get_headers  = $get_items instanceof \WP_REST_Response ? $get_items->get_headers() : array();
		$link_header  = (string) ( $get_headers['Link'] ?? '' );
		$base_link    = \add_query_arg( \urlencode_deep( $get_request->get_query_params() ), \rest_url( 'wp/v2/search' ) );
		$expected_prev = \add_query_arg( 'page', 1, $base_link );
		$expected_next = \add_query_arg( 'page', 3, $base_link );

		self::collect_failure(
			$failures,
			$get_items instanceof \WP_REST_Response
				&& $expected_ids === self::search_response_ids( $get_items )
				&& $case['handlerTotal'] === (int) ( $get_headers['X-WP-Total'] ?? 0 )
				&& 3 === $get_headers['X-WP-TotalPages']
				&& str_contains( $link_header, '<' . $expected_prev . '>; rel="prev"' )
				&& str_contains( $link_header, '<' . $expected_next . '>; rel="next"' )
				&& array(
					array(
						'method'   => 'GET',
						'type'     => $case['type'],
						'subtype'  => array( \WP_REST_Search_Controller::TYPE_ANY ),
						'include'  => $case['include'],
						'exclude'  => $case['exclude'],
						'page'     => 2,
						'per_page' => 2,
						'search'   => $case['search'],
					),
				) === $handler->search_calls,
			'search get_items uses handler results, headers, include/exclude request state, and prev/next links',
			array(
				'expectedIds' => $expected_ids,
				'pageIds'     => self::search_response_ids( $get_items ),
				'headers'     => $get_headers,
				'linkHeader'  => $link_header,
				'searchCalls' => $handler->search_calls,
			)
		);

		$prepare_count_before_head = count( $handler->prepare_calls );
		$head_items                = $controller->get_items(
			self::request(
				'HEAD',
				'/wp/v2/search',
				array(
					'context'                                      => 'view',
					'page'                                         => 1,
					'per_page'                                     => 4,
					'search'                                       => $case['search'],
					\WP_REST_Search_Controller::PROP_TYPE          => $case['type'],
					\WP_REST_Search_Controller::PROP_SUBTYPE       => array( \WP_REST_Search_Controller::TYPE_ANY ),
					'include'                                      => $case['include'],
					'exclude'                                      => $case['exclude'],
				)
			)
		);
		$head_headers = $head_items instanceof \WP_REST_Response ? $head_items->get_headers() : array();
		self::collect_failure(
			$failures,
			$head_items instanceof \WP_REST_Response
				&& array() === $head_items->get_data()
				&& $case['handlerTotal'] === (int) ( $head_headers['X-WP-Total'] ?? 0 )
				&& 2 === $head_headers['X-WP-TotalPages']
				&& $prepare_count_before_head === count( $handler->prepare_calls ),
			'search HEAD returns only headers and does not prepare item bodies',
			array(
				'headData'      => $head_items instanceof \WP_REST_Response ? $head_items->get_data() : $head_items,
				'headHeaders'   => $head_headers,
				'prepareBefore' => $prepare_count_before_head,
				'prepareAfter'  => count( $handler->prepare_calls ),
			)
		);

		$invalid_page = $controller->get_items(
			self::request(
				'GET',
				'/wp/v2/search',
				array(
					'context'                                      => 'view',
					'page'                                         => 4,
					'per_page'                                     => 2,
					'search'                                       => $case['search'],
					\WP_REST_Search_Controller::PROP_TYPE          => $case['type'],
					\WP_REST_Search_Controller::PROP_SUBTYPE       => array( \WP_REST_Search_Controller::TYPE_ANY ),
					'include'                                      => $case['include'],
					'exclude'                                      => $case['exclude'],
				)
			)
		);
		$malformed_handler = self::make_search_handler( $case['malformedType'], $case['subtypes'], $case['items'], $case['handlerIds'], $case['handlerTotal'], true );
		$malformed_items   = ( new \WP_REST_Search_Controller( array( $malformed_handler ) ) )->get_items(
			self::request(
				'GET',
				'/wp/v2/search',
				array(
					'context'                                      => 'view',
					'page'                                         => 1,
					'per_page'                                     => 2,
					\WP_REST_Search_Controller::PROP_TYPE          => $case['malformedType'],
					\WP_REST_Search_Controller::PROP_SUBTYPE       => array( \WP_REST_Search_Controller::TYPE_ANY ),
				)
			)
		);
		self::collect_failure(
			$failures,
			self::wp_error_ok( $invalid_page, 'rest_search_invalid_page_number', 400 )
				&& self::wp_error_ok( $malformed_items, 'rest_search_handler_error', 500 ),
			'search get_items represents invalid page and malformed handler results as WP_Error codes',
			array(
				'invalidPage' => $invalid_page,
				'malformed'   => $malformed_items,
			)
		);

		$route_dispatch = self::check_search_route_dispatch( $case );
		self::collect_failure(
			$failures,
			true === $route_dispatch['ok'],
			'search registered route applies defaults, schema validation, HEAD, and links',
			$route_dispatch
		);

		$field_request = self::request(
			'GET',
			'/wp/v2/search',
			array(
				'context'                                      => 'view',
				'_fields'                                      => 'title,_links',
				\WP_REST_Search_Controller::PROP_TYPE          => $case['type'],
				\WP_REST_Search_Controller::PROP_SUBTYPE       => array( \WP_REST_Search_Controller::TYPE_ANY ),
			)
		);
		$prepared_item = $controller->prepare_item_for_response( $case['items'][0]['id'], $field_request );
		$prepared_data = $prepared_item instanceof \WP_REST_Response ? $prepared_item->get_data() : array();
		$prepared_keys = array_keys( $prepared_data );
		sort( $prepared_keys );
		$prepared_links = $prepared_item instanceof \WP_REST_Response ? $prepared_item->get_links() : array();
		$last_prepare   = end( $handler->prepare_calls );
		$prepared_fields = is_array( $last_prepare ) ? $last_prepare['fields'] : array();
		sort( $prepared_fields );
		self::collect_failure(
			$failures,
			$prepared_item instanceof \WP_REST_Response
				&& array( 'id', 'title' ) === $prepared_keys
				&& $case['items'][0]['id'] === ( $prepared_data['id'] ?? null )
				&& $case['items'][0]['title'] === ( $prepared_data['title'] ?? null )
				&& array( '_links', 'id', 'title' ) === $prepared_fields
				&& 'http://example.test/component-fuzz/search/' . $case['items'][0]['id'] === self::link_href( $prepared_links, 'self' )
				&& 'http://example.test/component-fuzz/search/about/' . $case['type'] === self::link_href( $prepared_links, 'about' )
				&& \rest_url( 'wp/v2/search' ) === self::link_href( $prepared_links, 'collection' ),
			'search prepare_item_for_response trims fields and merges handler links with collection link',
			array(
				'preparedData'   => $prepared_data,
				'preparedFields' => $prepared_fields,
				'links'          => $prepared_links,
			)
		);

		$post_format = self::check_post_format_search_handler( $case );
		self::collect_failure(
			$failures,
			true === $post_format['ok'],
			'post format search handler filters query locally and returns only linked formats',
			$post_format
		);

		return self::row(
			$ctx,
			'rest-controllers.search-controller.handlers-params-pagination-links',
			array() === $failures,
			array(
				'case'     => $case,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_search_route_dispatch( array $case ): array {
		$handler    = self::make_search_handler( $case['type'], $case['subtypes'], $case['items'], $case['handlerIds'], $case['handlerTotal'] );
		$controller = new \WP_REST_Search_Controller( array( $handler ) );

		$previous_server  = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions   = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions = $GLOBALS['wp_actions'] ?? null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$default_request          = self::request( 'GET', '/wp/v2/search', array( 'page' => 2, 'per_page' => 2 ) );
		$head_request             = self::request( 'HEAD', '/wp/v2/search', array( 'page' => 1, 'per_page' => 4 ) );
		$invalid_include_request  = self::request( 'GET', '/wp/v2/search', array( 'include' => array( 'not-an-int' ) ) );
		$invalid_type_request     = self::request(
			'GET',
			'/wp/v2/search',
			array( \WP_REST_Search_Controller::PROP_TYPE => $case['invalidType'] )
		);
		$routes                   = array();
		$route_options            = array();
		$route_schema             = array();
		$default_response         = null;
		$head_response            = null;
		$invalid_include_response = null;
		$invalid_type_response    = null;
		$prepare_count_before_head = 0;

		try {
			$controller->register_routes();
			$routes                   = $server->get_routes( 'wp/v2' );
			$route_options            = $server->get_route_options( '/wp/v2/search' );
			$route_schema             = is_callable( $route_options['schema'] ?? null )
				? (array) call_user_func( $route_options['schema'] )
				: array();
			$default_response         = $server->dispatch( $default_request );
			$prepare_count_before_head = count( $handler->prepare_calls );
			$head_response            = $server->dispatch( $head_request );
			$invalid_include_response = $server->dispatch( $invalid_include_request );
			$invalid_type_response    = $server->dispatch( $invalid_type_request );
		} finally {
			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $previous_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}

			if ( null !== $previous_server ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		$default_headers = $default_response instanceof \WP_REST_Response ? $default_response->get_headers() : array();
		$head_headers    = $head_response instanceof \WP_REST_Response ? $head_response->get_headers() : array();
		$link_header     = (string) ( $default_headers['Link'] ?? '' );
		$base_link       = \add_query_arg( \urlencode_deep( $default_request->get_query_params() ), \rest_url( 'wp/v2/search' ) );
		$expected_prev   = \add_query_arg( 'page', 1, $base_link );
		$expected_next   = \add_query_arg( 'page', 3, $base_link );
		$route_methods   = self::route_methods( $routes['/wp/v2/search'] ?? array() );
		$first_call      = $handler->search_calls[0] ?? array();
		$head_call       = $handler->search_calls[1] ?? array();
		$server_restored = null !== $previous_server ? ( $GLOBALS['wp_rest_server'] ?? null ) === $previous_server : ! array_key_exists( 'wp_rest_server', $GLOBALS );
		$actions_restored = $had_wp_actions ? ( $GLOBALS['wp_actions'] ?? null ) === $previous_actions : ! array_key_exists( 'wp_actions', $GLOBALS );
		$prepare_count_after_head = count( $handler->prepare_calls );
		$invalid_subtype_route = self::check_search_invalid_subtype_route_dispatch( $case );

		return array(
			'ok'                     => isset( $routes['/wp/v2/search'] )
				&& in_array( 'GET', $route_methods, true )
				&& self::search_route_schema_matches( $route_schema, $case )
				&& $default_response instanceof \WP_REST_Response
				&& 200 === $default_response->get_status()
				&& $case['handlerIds'] === self::search_response_ids( $default_response )
				&& $case['handlerTotal'] === (int) ( $default_headers['X-WP-Total'] ?? 0 )
				&& 3 === (int) ( $default_headers['X-WP-TotalPages'] ?? 0 )
				&& str_contains( $link_header, '<' . $expected_prev . '>; rel="prev"' )
				&& str_contains( $link_header, '<' . $expected_next . '>; rel="next"' )
				&& array(
					'method'   => 'GET',
					'type'     => $case['type'],
					'subtype'  => array( \WP_REST_Search_Controller::TYPE_ANY ),
					'include'  => array(),
					'exclude'  => array(),
					'page'     => 2,
					'per_page' => 2,
					'search'   => '',
				) === $first_call
				&& $head_response instanceof \WP_REST_Response
				&& 200 === $head_response->get_status()
				&& array() === $head_response->get_data()
				&& $case['handlerTotal'] === (int) ( $head_headers['X-WP-Total'] ?? 0 )
				&& 2 === (int) ( $head_headers['X-WP-TotalPages'] ?? 0 )
				&& is_array( $head_call )
				&& 'HEAD' === ( $head_call['method'] ?? null )
				&& $case['type'] === ( $head_call['type'] ?? null )
				&& 1 === ( $head_call['page'] ?? null )
				&& 4 === ( $head_call['per_page'] ?? null )
				&& $prepare_count_before_head === $prepare_count_after_head
				&& self::response_error_ok( $invalid_include_response, 'rest_invalid_param', 400 )
				&& self::response_error_ok( $invalid_type_response, 'rest_invalid_param', 400 )
				&& true === $invalid_subtype_route['ok']
				&& $server_restored
				&& $actions_restored,
			'routes'                 => array_keys( $routes ),
			'routeMethods'           => $route_methods,
			'routeSchema'            => self::search_schema_summary( $route_schema ),
			'defaultIds'             => self::search_response_ids( $default_response ),
			'defaultHeaders'         => $default_headers,
			'defaultLinkHeader'      => $link_header,
			'searchCalls'            => $handler->search_calls,
			'headData'               => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
			'headHeaders'            => $head_headers,
			'prepareBeforeHead'      => $prepare_count_before_head,
			'prepareAfterHead'       => $prepare_count_after_head,
			'invalidIncludeResponse' => $invalid_include_response,
			'invalidTypeResponse'    => $invalid_type_response,
			'invalidSubtypeRoute'    => $invalid_subtype_route,
			'serverRestored'         => $server_restored,
			'actionsRestored'        => $actions_restored,
		);
	}

	private static function check_search_invalid_subtype_route_dispatch( array $case ): array {
		$handler    = self::make_search_handler( $case['emptySubtypeType'], array(), array(), array(), 0 );
		$controller = new \WP_REST_Search_Controller( array( $handler ) );

		$previous_server  = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions   = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions = $GLOBALS['wp_actions'] ?? null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$response = null;
		$routes   = array();

		try {
			$controller->register_routes();
			$routes   = $server->get_routes( 'wp/v2' );
			$response = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/search',
					array( \WP_REST_Search_Controller::PROP_SUBTYPE => array( $case['invalidSubtype'] ) )
				)
			);
		} finally {
			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $previous_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}

			if ( null !== $previous_server ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		$server_restored = null !== $previous_server ? ( $GLOBALS['wp_rest_server'] ?? null ) === $previous_server : ! array_key_exists( 'wp_rest_server', $GLOBALS );
		$actions_restored = $had_wp_actions ? ( $GLOBALS['wp_actions'] ?? null ) === $previous_actions : ! array_key_exists( 'wp_actions', $GLOBALS );

		return array(
			'ok'              => isset( $routes['/wp/v2/search'] )
				&& self::response_error_ok( $response, 'rest_invalid_param', 400 )
				&& $server_restored
				&& $actions_restored,
			'routes'          => array_keys( $routes ),
			'response'        => $response,
			'serverRestored'  => $server_restored,
			'actionsRestored' => $actions_restored,
		);
	}

	private static function search_route_schema_matches( array $schema, array $case ): bool {
		$properties = $schema['properties'] ?? null;
		if ( ! is_array( $properties ) ) {
			return false;
		}

		return 'search-result' === ( $schema['title'] ?? null )
			&& 'object' === ( $schema['type'] ?? null )
			&& array( 'integer', 'string' ) === ( $properties[ \WP_REST_Search_Controller::PROP_ID ]['type'] ?? null )
			&& array( 'view', 'embed' ) === ( $properties[ \WP_REST_Search_Controller::PROP_ID ]['context'] ?? null )
			&& true === ( $properties[ \WP_REST_Search_Controller::PROP_ID ]['readonly'] ?? null )
			&& 'string' === ( $properties[ \WP_REST_Search_Controller::PROP_TITLE ]['type'] ?? null )
			&& array( 'view', 'embed' ) === ( $properties[ \WP_REST_Search_Controller::PROP_TITLE ]['context'] ?? null )
			&& true === ( $properties[ \WP_REST_Search_Controller::PROP_TITLE ]['readonly'] ?? null )
			&& 'string' === ( $properties[ \WP_REST_Search_Controller::PROP_URL ]['type'] ?? null )
			&& 'uri' === ( $properties[ \WP_REST_Search_Controller::PROP_URL ]['format'] ?? null )
			&& array( 'view', 'embed' ) === ( $properties[ \WP_REST_Search_Controller::PROP_URL ]['context'] ?? null )
			&& true === ( $properties[ \WP_REST_Search_Controller::PROP_URL ]['readonly'] ?? null )
			&& 'string' === ( $properties[ \WP_REST_Search_Controller::PROP_TYPE ]['type'] ?? null )
			&& array( $case['type'] ) === ( $properties[ \WP_REST_Search_Controller::PROP_TYPE ]['enum'] ?? null )
			&& array( 'view', 'embed' ) === ( $properties[ \WP_REST_Search_Controller::PROP_TYPE ]['context'] ?? null )
			&& true === ( $properties[ \WP_REST_Search_Controller::PROP_TYPE ]['readonly'] ?? null )
			&& 'string' === ( $properties[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['type'] ?? null )
			&& $case['subtypes'] === ( $properties[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['enum'] ?? null )
			&& array( 'view', 'embed' ) === ( $properties[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['context'] ?? null )
			&& true === ( $properties[ \WP_REST_Search_Controller::PROP_SUBTYPE ]['readonly'] ?? null );
	}

	private static function search_schema_summary( array $schema ): array {
		$properties = $schema['properties'] ?? array();
		$summary    = array(
			'title'      => $schema['title'] ?? null,
			'type'       => $schema['type'] ?? null,
			'properties' => array(),
		);

		foreach ( array( 'id', 'title', 'url', 'type', 'subtype' ) as $property ) {
			$summary['properties'][ $property ] = array_intersect_key(
				is_array( $properties[ $property ] ?? null ) ? $properties[ $property ] : array(),
				array(
					'type'     => true,
					'format'   => true,
					'enum'     => true,
					'context'  => true,
					'readonly' => true,
				)
			);
		}

		return $summary;
	}

	private static function check_post_format_search_handler( array $case ): array {
		$linked_formats    = $case['linkedFormats'];
		$format_search     = $case['formatSearch'];
		$query_calls       = array();
		$term_query_calls  = array();
		$term_link_calls   = array();
		$filter_cleanup    = false;
		$result            = null;
		$paged_result      = null;
		$prepared          = null;

		\register_post_type(
			'post',
			array(
				'label'        => 'Posts',
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
		\register_taxonomy(
			'post_format',
			'post',
			array(
				'label'        => 'Post formats',
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => false,
				'query_var'    => 'post_format',
			)
		);

		$query_filter = static function ( array $query_args, \WP_REST_Request $request ) use ( &$query_calls, $format_search ): array {
			$query_calls[]       = array(
				'before' => $query_args,
				'route'  => $request->get_route(),
				'type'   => $request[ \WP_REST_Search_Controller::PROP_TYPE ],
			);
			$query_args['search'] = $format_search;
			return $query_args;
		};
		$terms_filter = static function ( $terms, \WP_Term_Query $query ) use ( $linked_formats, &$term_query_calls ) {
			$query_vars = $query->query_vars;
			$taxonomies = (array) ( $query_vars['taxonomy'] ?? array() );
			if ( ! in_array( 'post_format', $taxonomies, true ) ) {
				return $terms;
			}

			$slugs              = array_values( array_filter( (array) ( $query_vars['slug'] ?? array() ), 'is_string' ) );
			$term_query_calls[] = array(
				'slugs'  => $slugs,
				'fields' => $query_vars['fields'] ?? null,
			);

			foreach ( $slugs as $slug ) {
				if ( ! str_starts_with( $slug, 'post-format-' ) ) {
					continue;
				}

				$format = substr( $slug, strlen( 'post-format-' ) );
				if ( in_array( $format, $linked_formats, true ) ) {
					return array( self::post_format_term( $format ) );
				}
			}

			return array();
		};
		$term_link_filter = static function ( string $link, \WP_Term $term, string $taxonomy ) use ( &$term_link_calls ): string {
			if ( 'post_format' !== $taxonomy ) {
				return $link;
			}

			$format            = str_replace( 'post-format-', '', $term->slug );
			$term_link_calls[] = array(
				'format' => $format,
				'link'   => $link,
			);
			return 'http://example.test/component-fuzz/formats/' . $format;
		};

		\add_filter( 'rest_post_format_search_query', $query_filter, 10, 2 );
		\add_filter( 'terms_pre_query', $terms_filter, 10, 2 );
		\add_filter( 'term_link', $term_link_filter, 10, 3 );
		try {
			$handler = new \WP_REST_Post_Format_Search_Handler();
			$request = self::request(
				'GET',
				'/wp/v2/search',
				array(
					'context'                                      => 'view',
					'page'                                         => 1,
					'per_page'                                     => 20,
					'search'                                       => 'before-filter',
					\WP_REST_Search_Controller::PROP_TYPE          => 'post-format',
					\WP_REST_Search_Controller::PROP_SUBTYPE       => array( \WP_REST_Search_Controller::TYPE_ANY ),
				)
			);
			$result  = $handler->search_items( $request );
			$paged_request = self::request(
				'GET',
				'/wp/v2/search',
				array(
					'context'                                      => 'view',
					'page'                                         => 2,
					'per_page'                                     => 1,
					'search'                                       => 'before-filter',
					\WP_REST_Search_Controller::PROP_TYPE          => 'post-format',
					\WP_REST_Search_Controller::PROP_SUBTYPE       => array( \WP_REST_Search_Controller::TYPE_ANY ),
				)
			);
			$paged_result = $handler->search_items( $paged_request );
			$prepared = $handler->prepare_item(
				$linked_formats[0],
				array(
					\WP_REST_Search_Controller::PROP_ID,
					\WP_REST_Search_Controller::PROP_TITLE,
					\WP_REST_Search_Controller::PROP_URL,
					\WP_REST_Search_Controller::PROP_TYPE,
				)
			);
		} finally {
			\remove_filter( 'rest_post_format_search_query', $query_filter, 10 );
			\remove_filter( 'terms_pre_query', $terms_filter, 10 );
			\remove_filter( 'term_link', $term_link_filter, 10 );
			$filter_cleanup = false === \has_filter( 'rest_post_format_search_query', $query_filter )
				&& false === \has_filter( 'terms_pre_query', $terms_filter )
				&& false === \has_filter( 'term_link', $term_link_filter );
		}

		$expected_ids = self::expected_post_format_ids( $linked_formats, $format_search );
		$actual_ids   = is_array( $result ) ? ( $result[ \WP_REST_Search_Handler::RESULT_IDS ] ?? null ) : null;
		$total        = is_array( $result ) ? ( $result[ \WP_REST_Search_Handler::RESULT_TOTAL ] ?? null ) : null;
		$paged_ids    = is_array( $paged_result ) ? ( $paged_result[ \WP_REST_Search_Handler::RESULT_IDS ] ?? null ) : null;
		$paged_total  = is_array( $paged_result ) ? ( $paged_result[ \WP_REST_Search_Handler::RESULT_TOTAL ] ?? null ) : null;

		return array(
			'ok'              => $expected_ids === $actual_ids
				&& count( $expected_ids ) === $total
				&& array_slice( $expected_ids, 1, 1 ) === $paged_ids
				&& count( $expected_ids ) === $paged_total
				&& array( $linked_formats[0], \get_post_format_string( $linked_formats[0] ), 'http://example.test/component-fuzz/formats/' . $linked_formats[0], 'post-format' ) === array(
					$prepared[ \WP_REST_Search_Controller::PROP_ID ] ?? null,
					$prepared[ \WP_REST_Search_Controller::PROP_TITLE ] ?? null,
					$prepared[ \WP_REST_Search_Controller::PROP_URL ] ?? null,
					$prepared[ \WP_REST_Search_Controller::PROP_TYPE ] ?? null,
				)
				&& array(
					array(
						'before' => array( 'search' => 'before-filter' ),
						'route'  => '/wp/v2/search',
						'type'   => 'post-format',
					),
					array(
						'before' => array( 'search' => 'before-filter' ),
						'route'  => '/wp/v2/search',
						'type'   => 'post-format',
					),
				) === $query_calls
				&& array() === array_values( array_diff( $actual_ids ?? array(), $linked_formats ) )
				&& self::post_format_term_queries_cover( $term_query_calls, $expected_ids )
				&& self::post_format_link_calls_cover( $term_link_calls, array_unique( array_merge( $expected_ids, array( $linked_formats[0] ) ) ) )
				&& $filter_cleanup,
			'expectedIds'     => $expected_ids,
			'actualIds'       => $actual_ids,
			'total'           => $total,
			'pagedIds'        => $paged_ids,
			'pagedTotal'      => $paged_total,
			'prepared'        => $prepared,
			'queryCalls'      => $query_calls,
			'termQueryCalls'  => $term_query_calls,
			'termLinkCalls'   => $term_link_calls,
			'filterCleanup'   => $filter_cleanup,
			'linkedFormats'   => $linked_formats,
			'formatSearch'    => $format_search,
		);
	}

	private static function post_type_case( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'postType'           => self::name_token( $ctx->fork( 'post-type' ), 'cfzpt', 20 ),
			'hiddenPostType'     => self::name_token( $ctx->fork( 'hidden-post-type' ), 'cfzhpt', 20 ),
			'restBase'           => self::route_token( $ctx->fork( 'rest-base' ), 'cfz-rest' ),
			'taxonomy'           => self::name_token( $ctx->fork( 'taxonomy' ), 'cfztax', 28 ),
			'hiddenTaxonomy'     => self::name_token( $ctx->fork( 'hidden-taxonomy' ), 'cfzhtax', 28 ),
			'taxonomyRestBase'   => self::route_token( $ctx->fork( 'tax-rest-base' ), 'cfz-tax' ),
			'additionalField'    => self::name_token( $ctx->fork( 'additional-field' ), 'cfzfield', 32 ),
			'additionalValue'    => 'field-' . substr( hash( 'crc32b', (string) $ctx->fork( 'additional-value' )->seed() ), 0, 8 ),
			'label'              => 'Fuzz Type ' . $ctx->int( 1, 999 ),
			'description'        => 'Generated REST controller post type.',
			'publicStatus'       => self::name_token( $ctx->fork( 'public-status' ), 'cfzpub', 20 ),
			'privateStatus'      => self::name_token( $ctx->fork( 'private-status' ), 'cfzpriv', 20 ),
			'hiddenStatus'       => self::name_token( $ctx->fork( 'hidden-status' ), 'cfzhid', 20 ),
			'publicStatusLabel'  => 'Public Status ' . $ctx->int( 1, 999 ),
			'privateStatusLabel' => 'Private Status ' . $ctx->int( 1, 999 ),
			'hiddenStatusLabel'  => 'Hidden Status ' . $ctx->int( 1, 999 ),
			'dateFloating'       => $ctx->bool(),
		);
	}

	private static function taxonomy_case( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'postType'        => self::name_token( $ctx->fork( 'post-type' ), 'cfzpt', 20 ),
			'otherPostType'   => self::name_token( $ctx->fork( 'other-post-type' ), 'cfzopt', 20 ),
			'taxonomy'        => self::name_token( $ctx->fork( 'taxonomy' ), 'cfztax', 28 ),
			'hiddenTaxonomy'  => self::name_token( $ctx->fork( 'hidden-taxonomy' ), 'cfzhtax', 28 ),
			'otherTaxonomy'   => self::name_token( $ctx->fork( 'other-taxonomy' ), 'cfzotax', 28 ),
			'restBase'        => self::route_token( $ctx->fork( 'rest-base' ), 'cfz-terms' ),
			'additionalField' => self::name_token( $ctx->fork( 'additional-field' ), 'cfztaxfield', 32 ),
			'additionalValue' => 'tax-field-' . substr( hash( 'crc32b', (string) $ctx->fork( 'additional-value' )->seed() ), 0, 8 ),
			'label'           => 'Fuzz Taxonomy ' . $ctx->int( 1, 999 ),
			'description'     => 'Generated REST controller taxonomy.',
			'hierarchical'    => $ctx->bool(),
		);
	}

	private static function additional_field_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		return array(
			'postType'           => self::name_token( $ctx->fork( 'post-type' ), 'cfzpt', 20 ),
			'restBase'           => self::route_token( $ctx->fork( 'rest-base' ), 'cfz-rest' ),
			'field'              => self::name_token( $ctx->fork( 'field' ), 'cfzfield', 32 ),
			'label'              => 'Additional Field Type ' . $ctx->int( 1, 999 ),
			'readLabel'          => 'read-' . $token,
			'nestedEdit'         => 'edit-' . substr( hash( 'crc32b', $token . '-edit' ), 0, 8 ),
			'nestedView'         => 'view-' . substr( hash( 'crc32b', $token . '-view' ), 0, 8 ),
			'updateLabel'        => 'update-' . substr( hash( 'crc32b', $token . '-update' ), 0, 8 ),
			'nestedUpdate'       => 'updated-' . substr( hash( 'crc32b', $token . '-nested-update' ), 0, 8 ),
			'invalidUpdateLabel' => 'reject-' . substr( hash( 'crc32b', $token . '-reject' ), 0, 8 ),
		);
	}

	private static function namespace_route_case( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'postType'                => self::name_token( $ctx->fork( 'post-type' ), 'cfzpt', 20 ),
			'hiddenPostType'          => self::name_token( $ctx->fork( 'hidden-post-type' ), 'cfzhpt', 20 ),
			'taxonomy'                => self::name_token( $ctx->fork( 'taxonomy' ), 'cfztax', 28 ),
			'hiddenTaxonomy'          => self::name_token( $ctx->fork( 'hidden-taxonomy' ), 'cfzhtax', 28 ),
			'postRestBase'            => self::route_token( $ctx->fork( 'post-rest-base' ), 'cfz-posts' ),
			'hiddenPostRestBase'      => self::route_token( $ctx->fork( 'hidden-post-rest-base' ), 'cfz-hidden-posts' ),
			'taxonomyRestBase'        => self::route_token( $ctx->fork( 'taxonomy-rest-base' ), 'cfz-terms' ),
			'hiddenTaxonomyRestBase'  => self::route_token( $ctx->fork( 'hidden-taxonomy-rest-base' ), 'cfz-hidden-terms' ),
			'postNamespace'           => self::route_token( $ctx->fork( 'post-namespace' ), 'cfz-post-ns' ) . '/v' . $ctx->fork( 'post-version' )->int( 1, 9 ),
			'taxonomyNamespace'       => self::route_token( $ctx->fork( 'taxonomy-namespace' ), 'cfz-tax-ns' ) . '/v' . $ctx->fork( 'taxonomy-version' )->int( 1, 9 ),
			'postTypeLabel'           => 'Namespace Type ' . $ctx->int( 1, 999 ),
			'taxonomyLabel'           => 'Namespace Taxonomy ' . $ctx->int( 1, 999 ),
		);
	}

	private static function settings_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token         = substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		$array_token   = substr( hash( 'crc32b', (string) $ctx->fork( 'array' )->seed() ), 0, 8 );
		$invalid_token = substr( hash( 'crc32b', (string) $ctx->fork( 'invalid-stored' )->seed() ), 0, 8 );
		return array(
			'stringOption'        => 'cfz_string_' . $token,
			'integerOption'       => 'cfz_integer_' . $token,
			'objectOption'        => 'cfz_object_' . $token,
			'arrayOption'         => 'cfz_array_' . $token,
			'invalidStoredOption' => 'cfz_invalid_stored_' . $token,
			'hiddenOption'        => 'cfz_hidden_' . $token,
			'invalidTypeOption'   => 'cfz_invalid_' . $token,
			'stringName'          => 'cfzString' . $token,
			'integerName'         => 'cfzInteger' . $token,
			'objectName'          => 'cfzObject' . $token,
			'arrayName'           => 'cfzArray' . $token,
			'invalidStoredName'   => 'cfzInvalidStored' . $token,
			'stringDefault'       => 'Default ' . $ctx->int( 1, 99 ),
			'storedString'        => 'Stored ' . $ctx->int( 1, 99 ),
			'updatedString'       => 'Updated ' . $ctx->int( 100, 999 ),
			'integerDefault'      => $ctx->int( 0, 10 ),
			'storedInteger'       => $ctx->int( 11, 50 ),
			'updatedInteger'      => $ctx->int( 51, 100 ),
			'arrayDefault'        => 'item-' . $array_token,
			'storedArrayItem'     => 'item-' . substr( hash( 'crc32b', $array_token . '-stored' ), 0, 8 ),
			'updatedArrayItem'    => 'item-' . substr( hash( 'crc32b', $array_token . '-updated' ), 0, 8 ),
			'invalidStoredValue'  => 'not-object-' . $invalid_token,
			'objectDefaultLabel'  => 'default-' . $token,
			'storedObjectLabel'   => 'stored-' . $token,
			'filteredObjectLabel' => 'filtered-' . $token,
		);
	}

	private static function block_type_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$namespace       = self::block_slug( $ctx->fork( 'namespace' ), 'cfzns' );
		$name            = self::block_slug( $ctx->fork( 'name' ), 'block' );
		$other_namespace = self::block_slug( $ctx->fork( 'other-namespace' ), 'otherns' );
		$other_name      = self::block_slug( $ctx->fork( 'other-name' ), 'block' );

		return array(
			'namespace'      => $namespace,
			'name'           => $name,
			'blockName'      => $namespace . '/' . $name,
			'otherBlockName' => $other_namespace . '/' . $other_name,
			'title'          => 'Fuzz Block ' . $ctx->int( 1, 999 ),
			'description'    => 'Generated REST controller block type.',
			'message'        => 'message-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ),
			'styleName'      => self::block_slug( $ctx->fork( 'style' ), 'style' ),
			'styleLabel'     => 'Style ' . $ctx->int( 1, 99 ),
		);
	}

	private static function block_renderer_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$namespace         = self::block_slug( $ctx->fork( 'namespace' ), 'cfzbr' );
		$name              = self::block_slug( $ctx->fork( 'name' ), 'dynamic' );
		$boolean_namespace = self::block_slug( $ctx->fork( 'boolean-namespace' ), 'cfzbool' );
		$boolean_name      = self::block_slug( $ctx->fork( 'boolean-name' ), 'dynamic' );
		$static_namespace  = self::block_slug( $ctx->fork( 'static-namespace' ), 'cfzstatic' );
		$static_name       = self::block_slug( $ctx->fork( 'static-name' ), 'block' );

		return array(
			'namespace'        => $namespace,
			'blockName'        => $namespace . '/' . $name,
			'booleanBlockName' => $boolean_namespace . '/' . $boolean_name,
			'staticBlockName'  => $static_namespace . '/' . $static_name,
			'defaultMessage'   => 'default-' . substr( hash( 'crc32b', (string) $ctx->fork( 'default-message' )->seed() ), 0, 8 ),
			'defaultCount'     => $ctx->fork( 'default-count' )->int( 1, 9 ),
			'message'          => 'message-' . substr( hash( 'crc32b', (string) $ctx->fork( 'message' )->seed() ), 0, 8 ),
			'count'            => $ctx->fork( 'count' )->int( 10, 99 ),
			'items'            => array(
				$ctx->fork( 'item-a' )->int( 1, 20 ),
				$ctx->fork( 'item-b' )->int( 21, 40 ),
			),
			'postMessage'      => 'post-message-' . substr( hash( 'crc32b', (string) $ctx->fork( 'post-message' )->seed() ), 0, 8 ),
			'postCount'        => $ctx->fork( 'post-count' )->int( 100, 199 ),
			'postItems'        => array(
				$ctx->fork( 'post-item-a' )->int( 41, 60 ),
				$ctx->fork( 'post-item-b' )->int( 61, 80 ),
			),
			'postTitle'        => 'REST Block Renderer Post ' . $ctx->fork( 'post-title' )->int( 1, 999 ),
			'filterMessage'    => 'filter-message-' . substr( hash( 'crc32b', (string) $ctx->fork( 'filter-message' )->seed() ), 0, 8 ),
			'marker'           => substr( hash( 'sha1', 'block-renderer:' . $ctx->seed() ), 0, 12 ),
		);
	}

	private static function block_renderer_post( \ComponentFuzz\FuzzContext $ctx, array $case ): \WP_Post {
		$id = 51000 + ( $ctx->seed() % 1000 );

		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => 0,
				'post_date'             => '2026-06-24 12:00:00',
				'post_date_gmt'         => '2026-06-24 10:00:00',
				'post_content'          => '<!-- wp:paragraph --><p>REST block renderer context.</p><!-- /wp:paragraph -->',
				'post_title'            => $case['postTitle'],
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'rest-block-renderer-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ),
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-24 12:00:00',
				'post_modified_gmt'     => '2026-06-24 10:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://example.test/?p=' . $id,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	private static function seed_post_storage( \WP_Post $post ): void {
		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
			$wpdb->insert( $wpdb->posts, $post->to_array() );
		}
		\wp_cache_set( $post->ID, (object) $post->to_array(), 'posts' );
	}

	private static function delete_post_storage( int $post_id ): void {
		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
			$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
		}
		\wp_cache_delete( $post_id, 'posts' );
	}

	private static function block_pattern_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$namespace = self::block_slug( $ctx->fork( 'namespace' ), 'cfzpat' );
		$name      = self::block_slug( $ctx->fork( 'name' ), 'pattern' );
		$keyword   = self::block_slug( $ctx->fork( 'keyword' ), 'keyword' );

		return array(
			'patternName'         => $namespace . '/' . $name,
			'patternTitle'        => 'Pattern ' . $ctx->int( 1, 999 ),
			'patternDescription'  => 'Generated REST controller pattern.',
			'content'             => '<!-- wp:paragraph --><p>' . esc_html( $keyword ) . '</p><!-- /wp:paragraph -->',
			'viewportWidth'       => $ctx->int( 320, 960 ),
			'keyword'             => $keyword,
			'categoryName'        => 'cfz-' . self::block_slug( $ctx->fork( 'category' ), 'cat' ),
			'categoryLabel'       => 'Category ' . $ctx->int( 1, 999 ),
			'categoryDescription' => 'Generated REST controller pattern category.',
		);
	}

	private static function theme_pattern_file_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$stylesheet         = self::block_slug( $ctx->fork( 'theme' ), 'cfz-pattern-theme' );
		$valid_file_slug    = self::block_slug( $ctx->fork( 'valid-file' ), 'local-pattern' );
		$duplicate_file_slug = self::block_slug( $ctx->fork( 'duplicate-file' ), 'duplicate-pattern' );
		$missing_slug_file  = self::block_slug( $ctx->fork( 'missing-slug' ), 'missing-slug' ) . '.php';
		$missing_title_file = self::block_slug( $ctx->fork( 'missing-title' ), 'missing-title' ) . '.php';
		$valid_file         = $valid_file_slug . '.php';
		$duplicate_file     = $duplicate_file_slug . '.php';
		$valid_slug         = $stylesheet . '/' . $valid_file_slug;
		$duplicate_slug     = $stylesheet . '/' . $duplicate_file_slug;
		$token              = substr( hash( 'sha1', 'theme-pattern-files:' . $ctx->seed() ), 0, 8 );

		return array(
			'token'             => $token,
			'stylesheet'        => $stylesheet,
			'validFile'         => $valid_file,
			'validFileSlug'     => $valid_file_slug,
			'validSlug'         => $valid_slug,
			'validTitle'        => 'Local Pattern ' . $ctx->int( 100, 999 ),
			'validDescription'  => 'Local pattern fixture ' . $token,
			'validViewport'     => $ctx->int( 480, 960 ),
			'validMarker'       => 'theme local pattern ' . $token,
			'keywords'          => array(
				self::block_slug( $ctx->fork( 'keyword-a' ), 'keyword' ),
				self::block_slug( $ctx->fork( 'keyword-b' ), 'keyword' ),
			),
			'duplicateFile'     => $duplicate_file,
			'duplicateSlug'     => $duplicate_slug,
			'duplicateTitle'    => 'Duplicate File Pattern ' . $ctx->int( 100, 999 ),
			'duplicateMarker'   => 'duplicate file pattern ' . $token,
			'duplicatePreTitle' => 'Preexisting Duplicate Pattern ' . $ctx->int( 100, 999 ),
			'duplicatePreMarker' => 'preexisting duplicate ' . $token,
			'missingSlugFile'   => $missing_slug_file,
			'missingSlugValue'  => $stylesheet . '/' . self::block_slug( $ctx->fork( 'missing-slug-value' ), 'missing-slug-pattern' ),
			'missingTitleFile'  => $missing_title_file,
			'missingTitleSlug'  => $stylesheet . '/' . self::block_slug( $ctx->fork( 'missing-title-value' ), 'missing-title-pattern' ),
			'ignoredFile'       => self::block_slug( $ctx->fork( 'ignored' ), 'ignored-pattern' ) . '.txt',
		);
	}

	private static function write_theme_pattern_fixture( array &$case, string $theme_dir, string $patterns_dir ): void {
		self::ensure_dir( $patterns_dir );

		$case['validFilePath']      = $patterns_dir . DIRECTORY_SEPARATOR . $case['validFile'];
		$case['duplicateFilePath']  = $patterns_dir . DIRECTORY_SEPARATOR . $case['duplicateFile'];
		$case['missingSlugPath']    = $patterns_dir . DIRECTORY_SEPARATOR . $case['missingSlugFile'];
		$case['missingTitlePath']   = $patterns_dir . DIRECTORY_SEPARATOR . $case['missingTitleFile'];
		$case['ignoredFilePath']    = $patterns_dir . DIRECTORY_SEPARATOR . $case['ignoredFile'];

		self::write_temp_file(
			$theme_dir . DIRECTORY_SEPARATOR . 'style.css',
			"/*\n"
			. 'Theme Name: Component Fuzz Pattern Files ' . $case['token'] . "\n"
			. 'Version: 1.' . substr( $case['token'], 0, 3 ) . "\n"
			. 'Text Domain: ' . $case['stylesheet'] . "\n"
			. "*/\n"
		);
		self::write_temp_file( $theme_dir . DIRECTORY_SEPARATOR . 'index.php', "<?php\n// Component fuzz pattern file theme.\n" );
		self::write_temp_file(
			$case['validFilePath'],
			self::theme_pattern_file_contents(
				array(
					'Title'          => $case['validTitle'],
					'Slug'           => $case['validSlug'],
					'Description'    => $case['validDescription'],
					'Categories'     => 'buttons, gallery',
					'Keywords'       => implode( ', ', $case['keywords'] ),
					'Block Types'    => 'core/paragraph, core/group',
					'Post Types'     => 'post, page',
					'Template Types' => 'front-page, single',
					'Viewport Width' => (string) $case['validViewport'],
					'Inserter'       => 'no',
				),
				$case['validMarker']
			)
		);
		self::write_temp_file(
			$case['duplicateFilePath'],
			self::theme_pattern_file_contents(
				array(
					'Title'       => $case['duplicateTitle'],
					'Slug'        => $case['duplicateSlug'],
					'Description' => 'Duplicate file should not replace a pre-registered pattern.',
					'Categories'  => 'columns',
					'Keywords'    => 'duplicate',
				),
				$case['duplicateMarker']
			)
		);
		self::write_temp_file(
			$case['missingSlugPath'],
			self::theme_pattern_file_contents(
				array(
					'Title'       => 'Missing Slug Pattern ' . $case['token'],
					'Description' => 'This file intentionally has no slug header.',
				),
				'missing slug ' . $case['token']
			)
		);
		self::write_temp_file(
			$case['missingTitlePath'],
			self::theme_pattern_file_contents(
				array(
					'Slug'        => $case['missingTitleSlug'],
					'Description' => 'This file intentionally has no title header.',
				),
				'missing title ' . $case['token']
			)
		);
		self::write_temp_file( $case['ignoredFilePath'], 'Ignored non-PHP pattern fixture ' . $case['token'] );
	}

	private static function theme_pattern_file_contents( array $headers, string $marker ): string {
		$lines = array( '<?php', '/**' );
		foreach ( $headers as $header => $value ) {
			$lines[] = ' * ' . $header . ': ' . $value;
		}
		$lines[] = ' */';
		$lines[] = '?>';
		$lines[] = self::pattern_content( $marker );
		return implode( "\n", $lines ) . "\n";
	}

	private static function theme_pattern_temp_root( \ComponentFuzz\FuzzContext $ctx ): string {
		$path = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'component-fuzz-block-pattern-files-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . getmypid();

		if ( file_exists( $path ) && ! self::remove_dir_recursive( $path ) ) {
			throw new \RuntimeException( 'Could not clear stale temp root: ' . $path );
		}

		self::ensure_dir( $path );
		return $path;
	}

	private static function ensure_dir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create directory: ' . $dir );
		}
	}

	private static function write_temp_file( string $path, string $contents ): void {
		self::ensure_dir( dirname( $path ) );
		if ( false === file_put_contents( $path, $contents ) ) {
			throw new \RuntimeException( 'Could not write file: ' . $path );
		}
	}

	private static function remove_dir_recursive( string $dir ): bool {
		if ( ! file_exists( $dir ) ) {
			return true;
		}
		if ( ! is_dir( $dir ) ) {
			return @unlink( $dir );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
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
	}

	private static function theme_pattern_file_summary( array $case ): array {
		return array(
			'stylesheet'       => $case['stylesheet'],
			'validFile'        => $case['validFile'],
			'validSlug'        => $case['validSlug'],
			'duplicateFile'    => $case['duplicateFile'],
			'duplicateSlug'    => $case['duplicateSlug'],
			'missingSlugFile'  => $case['missingSlugFile'],
			'missingTitleFile' => $case['missingTitleFile'],
			'ignoredFile'      => $case['ignoredFile'],
		);
	}

	private static function remote_pattern(
		string $title,
		string $content,
		array $categories,
		array $keywords,
		array $block_types,
		int $viewport_width
	): array {
		return array(
			'title'          => $title,
			'content'        => $content,
			'description'    => 'Remote pattern fixture for ' . $title,
			'categories'     => $categories,
			'keywords'       => $keywords,
			'block_types'    => $block_types,
			'viewport_width' => $viewport_width,
		);
	}

	private static function pattern_content( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	private static function pattern_entry_by_name( array $patterns, string $name ): ?array {
		foreach ( $patterns as $pattern ) {
			if ( is_array( $pattern ) && $name === ( $pattern['name'] ?? null ) ) {
				return $pattern;
			}
		}

		return null;
	}

	private static function clean_theme_json_runtime_cache(): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			\wp_clean_theme_json_cache();
		}
	}

	private static function search_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$type       = self::route_token( $ctx->fork( 'type' ), 'cfz-search' );
		$subtypes   = array(
			self::route_token( $ctx->fork( 'subtype-a' ), 'cfz-alpha' ),
			self::route_token( $ctx->fork( 'subtype-b' ), 'cfz-beta' ),
			self::route_token( $ctx->fork( 'subtype-c' ), 'cfz-gamma' ),
		);
		$items      = array(
			array(
				'id'      => 101,
				'title'   => 'Alpha relay ' . $ctx->int( 10, 99 ),
				'url'     => 'http://example.test/component-fuzz/items/101',
				'subtype' => $subtypes[0],
			),
			array(
				'id'      => 102,
				'title'   => 'Beta relay ' . $ctx->int( 10, 99 ),
				'url'     => 'http://example.test/component-fuzz/items/102',
				'subtype' => $subtypes[1],
			),
			array(
				'id'      => 103,
				'title'   => 'Gamma relay ' . $ctx->int( 10, 99 ),
				'url'     => 'http://example.test/component-fuzz/items/103',
				'subtype' => $subtypes[2],
			),
			array(
				'id'      => 104,
				'title'   => 'Alpha relay ' . $ctx->int( 100, 199 ),
				'url'     => 'http://example.test/component-fuzz/items/104',
				'subtype' => $subtypes[0],
			),
			array(
				'id'      => 105,
				'title'   => 'Beta relay ' . $ctx->int( 100, 199 ),
				'url'     => 'http://example.test/component-fuzz/items/105',
				'subtype' => $subtypes[1],
			),
			array(
				'id'      => 106,
				'title'   => 'Gamma relay ' . $ctx->int( 100, 199 ),
				'url'     => 'http://example.test/component-fuzz/items/106',
				'subtype' => $subtypes[2],
			),
			array(
				'id'      => 107,
				'title'   => 'Excluded relay ' . $ctx->int( 200, 299 ),
				'url'     => 'http://example.test/component-fuzz/items/107',
				'subtype' => $subtypes[0],
			),
		);

		return array(
			'type'              => $type,
			'secondaryType'     => self::route_token( $ctx->fork( 'secondary-type' ), 'cfz-search-other' ),
			'malformedType'     => self::route_token( $ctx->fork( 'malformed-type' ), 'cfz-search-bad' ),
			'emptySubtypeType'  => self::route_token( $ctx->fork( 'empty-subtype-type' ), 'cfz-search-empty' ),
			'invalidType'       => self::route_token( $ctx->fork( 'invalid-type' ), 'cfz-search-missing' ),
			'invalidSubtype'    => self::route_token( $ctx->fork( 'invalid-subtype' ), 'cfz-missing-subtype' ),
			'subtypes'          => $subtypes,
			'secondarySubtypes' => array( self::route_token( $ctx->fork( 'secondary-subtype' ), 'cfz-delta' ) ),
			'items'             => $items,
			'handlerIds'        => array( 103, 104 ),
			'handlerTotal'      => 6,
			'search'            => 'relay',
			'include'           => array( 101, 102, 103, 104, 105, 106, 107 ),
			'exclude'           => array( 107 ),
			'linkedFormats'     => array( 'aside', 'gallery', 'image' ),
			'formatSearch'      => 'a',
		);
	}

	private static function make_search_handler( string $type, array $subtypes, array $items, array $result_ids = array(), int $result_total = 0, bool $malformed_result = false ): \WP_REST_Search_Handler {
		return new class( $type, $subtypes, $items, $result_ids, $result_total, $malformed_result ) extends \WP_REST_Search_Handler {
			/** @var array<int,array<string,mixed>> */
			public array $search_calls = array();

			/** @var array<int,array<string,mixed>> */
			public array $prepare_calls = array();

			/** @var array<int,array<string,mixed>> */
			private array $fixture_items = array();

			/** @var array<int,int> */
			private array $result_ids;

			private int $result_total;

			private bool $malformed_result;

			public function __construct( string $type, array $subtypes, array $items, array $result_ids, int $result_total, bool $malformed_result ) {
				$this->type             = $type;
				$this->subtypes         = array_values( $subtypes );
				$this->result_ids       = array_values( array_map( 'intval', $result_ids ) );
				$this->result_total     = $result_total;
				$this->malformed_result = $malformed_result;

				foreach ( $items as $item ) {
					if ( is_array( $item ) && isset( $item['id'] ) ) {
						$this->fixture_items[ (int) $item['id'] ] = $item;
					}
				}
			}

			public function search_items( \WP_REST_Request $request ) {
				$subtypes = (array) $request[ \WP_REST_Search_Controller::PROP_SUBTYPE ];
				$include  = array_values( array_map( 'intval', (array) $request['include'] ) );
				$exclude  = array_values( array_map( 'intval', (array) $request['exclude'] ) );

				$this->search_calls[] = array(
					'method'   => $request->get_method(),
					'type'     => $request[ \WP_REST_Search_Controller::PROP_TYPE ],
					'subtype'  => $subtypes,
					'include'  => $include,
					'exclude'  => $exclude,
					'page'     => (int) $request['page'],
					'per_page' => (int) $request['per_page'],
					'search'   => (string) $request['search'],
				);

				if ( $this->malformed_result ) {
					return array(
						self::RESULT_IDS => 'not-an-array',
					);
				}

				return array(
					self::RESULT_IDS   => $this->result_ids,
					self::RESULT_TOTAL => $this->result_total,
				);
			}

			public function prepare_item( $id, array $fields ) {
				$this->prepare_calls[] = array(
					'id'     => (int) $id,
					'fields' => array_values( $fields ),
				);

				$item = $this->fixture_items[ (int) $id ] ?? null;
				if ( null === $item ) {
					return array();
				}

				$data = array();
				if ( in_array( \WP_REST_Search_Controller::PROP_ID, $fields, true ) ) {
					$data[ \WP_REST_Search_Controller::PROP_ID ] = (int) $item['id'];
				}
				if ( in_array( \WP_REST_Search_Controller::PROP_TITLE, $fields, true ) ) {
					$data[ \WP_REST_Search_Controller::PROP_TITLE ] = $item['title'];
				}
				if ( in_array( \WP_REST_Search_Controller::PROP_URL, $fields, true ) ) {
					$data[ \WP_REST_Search_Controller::PROP_URL ] = $item['url'];
				}
				if ( in_array( \WP_REST_Search_Controller::PROP_TYPE, $fields, true ) ) {
					$data[ \WP_REST_Search_Controller::PROP_TYPE ] = $this->type;
				}
				if ( in_array( \WP_REST_Search_Controller::PROP_SUBTYPE, $fields, true ) ) {
					$data[ \WP_REST_Search_Controller::PROP_SUBTYPE ] = $item['subtype'];
				}

				return $data;
			}

			public function prepare_item_links( $id ) {
				return array(
					'self'  => array(
						'href'       => 'http://example.test/component-fuzz/search/' . (int) $id,
						'embeddable' => true,
					),
					'about' => array(
						'href' => 'http://example.test/component-fuzz/search/about/' . $this->type,
					),
				);
			}
		};
	}

	private static function search_response_ids( $response ): array {
		if ( ! $response instanceof \WP_REST_Response || ! is_array( $response->get_data() ) ) {
			return array();
		}

		$ids = array();
		foreach ( $response->get_data() as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$ids[] = (int) $item['id'];
			}
		}

		return $ids;
	}

	private static function expected_post_format_ids( array $linked_formats, string $search ): array {
		$expected = array();
		$search   = strtolower( $search );

		foreach ( \get_post_format_strings() as $slug => $label ) {
			if ( ! in_array( $slug, $linked_formats, true ) ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $slug, $search ) && false === stripos( $label, $search ) ) {
				continue;
			}

			$expected[] = $slug;
		}

		return $expected;
	}

	private static function post_format_term_queries_cover( array $term_query_calls, array $formats ): bool {
		$expected_slugs = array();
		foreach ( $formats as $format ) {
			$expected_slugs[] = 'post-format-' . $format;
		}

		$seen_slugs = array();
		foreach ( $term_query_calls as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}

			foreach ( (array) ( $call['slugs'] ?? array() ) as $slug ) {
				if ( ! is_string( $slug ) ) {
					continue;
				}

				if ( '' !== $slug && ! str_starts_with( $slug, 'post-format-' ) ) {
					return false;
				}

				$seen_slugs[] = $slug;
			}
		}

		return array() === array_values( array_diff( $expected_slugs, array_unique( $seen_slugs ) ) );
	}

	private static function post_format_link_calls_cover( array $term_link_calls, array $formats ): bool {
		$seen_formats = array();
		foreach ( $term_link_calls as $call ) {
			if ( is_array( $call ) && isset( $call['format'] ) && is_string( $call['format'] ) ) {
				$seen_formats[] = $call['format'];
			}
		}

		return array() === array_values( array_diff( array_values( $formats ), array_unique( $seen_formats ) ) );
	}

	private static function post_format_term( string $format ): \WP_Term {
		$term_id = 5000 + (int) ( hexdec( substr( sha1( $format ), 0, 6 ) ) % 1000 );

		return new \WP_Term(
			(object) array(
				'term_id'          => $term_id,
				'name'             => \get_post_format_string( $format ),
				'slug'             => 'post-format-' . $format,
				'term_group'       => 0,
				'term_taxonomy_id' => $term_id + 10000,
				'taxonomy'         => 'post_format',
				'description'      => '',
				'parent'           => 0,
				'count'            => 1,
				'filter'           => 'raw',
			)
		);
	}

	private static function request( string $method, string $route, array $query_params = array(), array $url_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( array() !== $query_params ) {
			$request->set_query_params( $query_params );
		}
		if ( array() !== $url_params ) {
			$request->set_url_params( $url_params );
		}
		return $request;
	}

	private static function collection_context_param_ok( array $params ): bool {
		if ( ! isset( $params['context'] ) || ! is_array( $params['context'] ) ) {
			return false;
		}

		$context = $params['context'];
		$sanitize = $context['sanitize_callback'] ?? null;
		$enum     = $context['enum'] ?? array();

		return 'view' === ( $context['default'] ?? null )
			&& is_callable( $sanitize )
			&& 'view' === call_user_func( $sanitize, 'View!!' )
			&& is_array( $enum )
			&& in_array( 'view', $enum, true );
	}

	private static function install_cap_filter( array $granted_caps ): callable {
		$granted_caps = array_fill_keys( $granted_caps, true );
		$filter       = static function ( array $allcaps ) use ( $granted_caps ): array {
			foreach ( $granted_caps as $cap => $grant ) {
				$allcaps[ $cap ] = $grant;
			}
			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, 10, 4 );
		return $filter;
	}

	private static function name_token( \ComponentFuzz\FuzzContext $ctx, string $prefix, int $max_length ): string {
		$name = \sanitize_key( $prefix . '_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 14 ) );
		return substr( $name, 0, $max_length );
	}

	private static function route_token( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return strtolower( $prefix . '-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
	}

	private static function block_slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return strtolower( $prefix . '-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
	}

	private static function link_href( array $links, string $rel ): ?string {
		if ( ! isset( $links[ $rel ][0]['href'] ) ) {
			return null;
		}

		return $links[ $rel ][0]['href'];
	}

	private static function route_methods( array $handlers ): array {
		$methods = array();
		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
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

	private static function handler_args_for_method( array $handlers, string $method ): array {
		foreach ( $handlers as $handler ) {
			if (
				is_array( $handler )
				&& ! empty( $handler['methods'][ $method ] )
				&& isset( $handler['args'] )
				&& is_array( $handler['args'] )
			) {
				return $handler['args'];
			}
		}

		return array();
	}

	private static function wp_error_ok( $error, string $code, int $status ): bool {
		if ( ! $error instanceof \WP_Error || $code !== $error->get_error_code() ) {
			return false;
		}

		$data = $error->get_error_data();
		return is_array( $data ) && $status === ( $data['status'] ?? null );
	}

	private static function response_error_ok( $response, string $code, int $status ): bool {
		if ( ! $response instanceof \WP_REST_Response || $status !== $response->get_status() ) {
			return false;
		}

		$data = $response->get_data();
		return is_array( $data )
			&& $code === ( $data['code'] ?? null )
			&& isset( $data['data'] )
			&& is_array( $data['data'] )
			&& $status === ( $data['data']['status'] ?? null );
	}

	private static function reset_runtime_state(): void {
		self::reset_global_registries();
		self::reset_block_registries();

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'home'    => 'http://example.test',
					'siteurl' => 'http://example.test',
				)
			);
		}
	}

	private static function reset_global_registries(): void {
		$GLOBALS['wp_post_types']              = array();
		$GLOBALS['wp_post_statuses']           = array();
		$GLOBALS['_wp_post_type_features']     = array();
		$GLOBALS['post_type_meta_caps']        = array();
		$GLOBALS['wp_taxonomies']              = array();
		$GLOBALS['wp_registered_settings']     = array();
		$GLOBALS['new_allowed_options']        = array();
		$GLOBALS['new_whitelist_options']      = &$GLOBALS['new_allowed_options'];
		$GLOBALS['wp_registered_setting_types'] = array();
		$GLOBALS['wp_rest_additional_fields']  = array();
		$GLOBALS['wp_rest_server']             = new \WP_REST_Server();

		if ( class_exists( 'WP' ) ) {
			$GLOBALS['wp']                    = new \WP();
			$GLOBALS['wp']->public_query_vars = array();
		}
		if ( class_exists( 'WP_Rewrite' ) ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}
	}

	private static function reset_block_registries(): void {
		$block_registry = \WP_Block_Type_Registry::get_instance();
		self::set_object_property( $block_registry, 'registered_block_types', array() );

		$style_registry = \WP_Block_Styles_Registry::get_instance();
		self::set_object_property( $style_registry, 'registered_block_styles', array() );

		$pattern_registry = \WP_Block_Patterns_Registry::get_instance();
		self::set_object_property( $pattern_registry, 'registered_patterns', array() );
		self::set_object_property( $pattern_registry, 'registered_patterns_outside_init', array() );

		$category_registry = \WP_Block_Pattern_Categories_Registry::get_instance();
		self::set_object_property( $category_registry, 'registered_categories', array() );
		self::set_object_property( $category_registry, 'registered_categories_outside_init', array() );
	}

	private static function snapshot_state(): array {
		$block_registry    = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		$style_registry    = self::get_static_property( 'WP_Block_Styles_Registry', 'instance' );
		$pattern_registry  = self::get_static_property( 'WP_Block_Patterns_Registry', 'instance' );
		$category_registry = self::get_static_property( 'WP_Block_Pattern_Categories_Registry', 'instance' );

		return array(
			'globals'                    => self::snapshot_globals(
				array(
					'_wp_post_type_features',
					'_wp_theme_features',
					'current_user',
					'new_allowed_options',
					'new_whitelist_options',
					'post_type_meta_caps',
					'wp',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_post_statuses',
					'wp_post_types',
					'wp_registered_setting_types',
					'wp_registered_settings',
					'wp_rest_additional_fields',
					'wp_rest_server',
					'wp_rewrite',
					'wp_taxonomies',
					'wp_template_path',
					'wp_theme_directories',
					'wp_stylesheet_path',
				)
			),
			'wpdbOptions'                => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
			'blockRegistry'              => $block_registry,
			'blockTypes'                 => $block_registry instanceof \WP_Block_Type_Registry
				? self::get_object_property( $block_registry, 'registered_block_types' )
				: null,
			'styleRegistry'              => $style_registry,
			'blockStyles'                => $style_registry instanceof \WP_Block_Styles_Registry
				? self::get_object_property( $style_registry, 'registered_block_styles' )
				: null,
			'patternRegistry'            => $pattern_registry,
			'patterns'                   => $pattern_registry instanceof \WP_Block_Patterns_Registry
				? self::get_object_property( $pattern_registry, 'registered_patterns' )
				: null,
			'patternsOutsideInit'        => $pattern_registry instanceof \WP_Block_Patterns_Registry
				? self::get_object_property( $pattern_registry, 'registered_patterns_outside_init' )
				: null,
			'categoryRegistry'           => $category_registry,
			'patternCategories'          => $category_registry instanceof \WP_Block_Pattern_Categories_Registry
				? self::get_object_property( $category_registry, 'registered_categories' )
				: null,
			'categoriesOutsideInit'      => $category_registry instanceof \WP_Block_Pattern_Categories_Registry
				? self::get_object_property( $category_registry, 'registered_categories_outside_init' )
				: null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( array_key_exists( 'new_allowed_options', $GLOBALS ) ) {
			$GLOBALS['new_whitelist_options'] = &$GLOBALS['new_allowed_options'];
		}

		if ( null !== $snapshot['wpdbOptions'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['wpdbOptions'] );
		}

		if ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) {
			self::set_object_property( $snapshot['blockRegistry'], 'registered_block_types', $snapshot['blockTypes'] );
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', $snapshot['blockRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', null );
		}

		if ( $snapshot['styleRegistry'] instanceof \WP_Block_Styles_Registry ) {
			self::set_object_property( $snapshot['styleRegistry'], 'registered_block_styles', $snapshot['blockStyles'] );
			self::set_static_property( 'WP_Block_Styles_Registry', 'instance', $snapshot['styleRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Styles_Registry', 'instance', null );
		}

		if ( $snapshot['patternRegistry'] instanceof \WP_Block_Patterns_Registry ) {
			self::set_object_property( $snapshot['patternRegistry'], 'registered_patterns', $snapshot['patterns'] );
			self::set_object_property( $snapshot['patternRegistry'], 'registered_patterns_outside_init', $snapshot['patternsOutsideInit'] );
			self::set_static_property( 'WP_Block_Patterns_Registry', 'instance', $snapshot['patternRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Patterns_Registry', 'instance', null );
		}

		if ( $snapshot['categoryRegistry'] instanceof \WP_Block_Pattern_Categories_Registry ) {
			self::set_object_property( $snapshot['categoryRegistry'], 'registered_categories', $snapshot['patternCategories'] );
			self::set_object_property( $snapshot['categoryRegistry'], 'registered_categories_outside_init', $snapshot['categoriesOutsideInit'] );
			self::set_static_property( 'WP_Block_Pattern_Categories_Registry', 'instance', $snapshot['categoryRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Pattern_Categories_Registry', 'instance', null );
		}
	}

	private static function state_fingerprint(): array {
		$globals = array();
		foreach (
			array(
				'_wp_theme_features',
				'current_user',
				'new_allowed_options',
				'post_type_meta_caps',
				'wp_filter',
				'wp_post_statuses',
				'wp_post_types',
				'wp_registered_settings',
				'wp_rest_additional_fields',
				'wp_taxonomies',
				'wp_template_path',
				'wp_theme_directories',
				'wp_stylesheet_path',
			) as $name
		) {
			$globals[ $name ] = self::global_signature( $name );
		}

		return array(
			'globals'           => $globals,
			'optionsHash'       => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? self::stable_hash( $GLOBALS['wpdb']->component_fuzz_get_options() )
				: null,
			'blockTypes'        => self::registry_keys( 'WP_Block_Type_Registry', 'registered_block_types' ),
			'blockStylesHash'   => self::registry_hash( 'WP_Block_Styles_Registry', 'registered_block_styles' ),
			'patterns'          => self::registry_keys( 'WP_Block_Patterns_Registry', 'registered_patterns' ),
			'patternCategories' => self::registry_keys( 'WP_Block_Pattern_Categories_Registry', 'registered_categories' ),
		);
	}

	private static function global_signature( string $name ): array {
		if ( ! array_key_exists( $name, $GLOBALS ) ) {
			return array( 'exists' => false );
		}

		$value = $GLOBALS[ $name ];
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			sort( $keys );
			return array(
				'exists' => true,
				'type'   => 'array',
				'count'  => count( $value ),
				'keys'   => array_slice( array_map( 'strval', $keys ), 0, 40 ),
				'hash'   => self::stable_hash( self::summarize_for_hash( $value ) ),
			);
		}

		return array(
			'exists' => true,
			'type'   => is_object( $value ) ? get_class( $value ) : gettype( $value ),
		);
	}

	private static function registry_keys( string $class, string $property ): array {
		$instance = self::get_static_property( $class, 'instance' );
		if ( ! is_object( $instance ) ) {
			return array();
		}

		$value = self::get_object_property( $instance, $property );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$keys = array_keys( $value );
		sort( $keys );
		return array_values( array_map( 'strval', $keys ) );
	}

	private static function registry_hash( string $class, string $property ): ?string {
		$instance = self::get_static_property( $class, 'instance' );
		if ( ! is_object( $instance ) ) {
			return null;
		}

		return self::stable_hash( self::summarize_for_hash( self::get_object_property( $instance, $property ) ) );
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

	private static function invoke_object_method( object $object, string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invokeArgs( $object, $args );
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		self::make_reflection_accessible( $reflection );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		self::make_reflection_accessible( $reflection );
		$reflection->setValue( null, $value );
	}

	private static function get_object_property( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		self::make_reflection_accessible( $reflection );
		return $reflection->getValue( $object );
	}

	private static function set_object_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		self::make_reflection_accessible( $reflection );
		$reflection->setValue( $object, $value );
	}

	private static function make_reflection_accessible( \ReflectionProperty $reflection ): void {
		unset( $reflection );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array(), ?string $status = null ): array {
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

	private static function skip( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		$data['reason'] = $reason;
		return self::row( $ctx, $invariant, true, $data, 'skipped' );
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
				return array( 'WP_Error' => $value->get_error_code() );
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array( 'WP_REST_Response' => self::summarize_for_hash( $value->get_data(), $depth + 1 ) );
			}
			if ( $value instanceof \WP_Post_Type || $value instanceof \WP_Taxonomy || $value instanceof \WP_Block_Type ) {
				return array( get_class( $value ) => $value->name ?? null );
			}
			return array( 'object' => get_class( $value ) );
		}

		return $value;
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			$value = preg_replace_callback(
				'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
				static function ( array $matches ): string {
					return sprintf( '\\x%02X', ord( $matches[0] ) );
				},
				$value
			);
			return strlen( $value ) > 220 ? substr( $value, 0, 220 ) . '...' : $value;
		}

		if ( is_array( $value ) ) {
			if ( $depth >= 4 ) {
				return array(
					'type'  => 'array',
					'count' => count( $value ),
				);
			}

			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 20 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : (string) $key ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}
			if ( $value instanceof \WP_Error ) {
				return array(
					'type'    => 'WP_Error',
					'code'    => $value->get_error_code(),
					'message' => $value->get_error_message(),
				);
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array(
					'type'   => 'WP_REST_Response',
					'status' => $value->get_status(),
					'data'   => self::describe_value( $value->get_data(), $depth + 1 ),
				);
			}
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
				'name'  => $value->name ?? null,
			);
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
}
