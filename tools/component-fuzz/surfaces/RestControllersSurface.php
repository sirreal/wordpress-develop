<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes registry-backed WordPress REST endpoint controllers without live DB data.
 */
final class RestControllersSurface {
	public const NAME = 'rest-controllers';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
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
			$rows[] = self::check_settings_controller( $ctx );
			$rows[] = self::check_block_types_controller( $ctx );
			$rows[] = self::check_block_patterns_controller( $ctx );
			$rows[] = self::skip(
				$ctx,
				'rest-controllers.themes-controller.skipped',
				'WP_REST_Themes_Controller is skipped because it inspects installed/current theme filesystem state and theme support.',
				array( 'controller' => 'WP_REST_Themes_Controller' )
			);
			$rows[] = self::skip(
				$ctx,
				'rest-controllers.plugins-controller.skipped',
				'WP_REST_Plugins_Controller is skipped because safe coverage would need plugin filesystem and activation/update side effects.',
				array( 'controller' => 'WP_REST_Plugins_Controller' )
			);
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
				'WP_REST_Block_Types_Controller',
				'WP_REST_Controller',
				'WP_REST_Post_Statuses_Controller',
				'WP_REST_Post_Types_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Server',
				'WP_REST_Settings_Controller',
				'WP_REST_Taxonomies_Controller',
				'WP_Taxonomy',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'current_user_can',
				'delete_option',
				'get_object_taxonomies',
				'get_option',
				'get_post_stati',
				'get_post_status_object',
				'get_post_type_object',
				'get_post_types',
				'get_registered_settings',
				'get_taxonomies',
				'get_taxonomy',
				'is_post_type_viewable',
				'is_wp_error',
				'register_block_style',
				'register_block_type',
				'register_post_status',
				'register_post_type',
				'register_setting',
				'register_taxonomy',
				'remove_filter',
				'rest_default_additional_properties_to_false',
				'rest_ensure_response',
				'rest_filter_response_by_context',
				'rest_get_route_for_post_type_items',
				'rest_get_route_for_taxonomy_items',
				'rest_sanitize_value_from_schema',
				'rest_url',
				'rest_validate_value_from_schema',
				'sanitize_key',
				'serialize_blocks',
				'unregister_block_type',
				'update_option',
				'wp_parse_args',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_post_types_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$case       = self::post_type_case( $ctx->fork( 'post-types' ) );
		$controller = new \WP_REST_Post_Types_Controller();
		$failures   = array();

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
		self::collect_failure(
			$failures,
			$edit_item instanceof \WP_REST_Response
				&& isset( $edit_data['capabilities'], $edit_data['supports'], $edit_data['visibility'] )
				&& $case['postType'] === ( $edit_data['slug'] ?? null )
				&& $case['restBase'] === ( $edit_data['rest_base'] ?? null )
				&& \rest_url( '/wp/v2/types' ) === self::link_href( $links, 'collection' )
				&& \rest_url( '/wp/v2/' . $case['restBase'] ) === self::link_href( $links, 'https://api.w.org/items' ),
			'post type item edit response exposes edit-context fields and stable rest_base links',
			array( 'case' => $case, 'editData' => $edit_data, 'links' => $links )
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
				&& ! isset( $embed_data['description'], $embed_data['capabilities'], $embed_data['supports'], $embed_data['taxonomies'] ),
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
				&& $hidden_item instanceof \WP_Error
				&& 'rest_cannot_read_type' === $hidden_item->get_error_code()
				&& $missing_item instanceof \WP_Error
				&& 'rest_type_invalid' === $missing_item->get_error_code(),
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
			$denied instanceof \WP_Error
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
			$hidden_permission instanceof \WP_Error
				&& 'rest_cannot_read_status' === $hidden_permission->get_error_code()
				&& $missing_item instanceof \WP_Error
				&& 'rest_status_invalid' === $missing_item->get_error_code(),
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
		self::collect_failure(
			$failures,
			$edit_item instanceof \WP_REST_Response
				&& isset( $edit_data['capabilities'], $edit_data['labels'], $edit_data['visibility'], $edit_data['show_cloud'] )
				&& $case['taxonomy'] === ( $edit_data['slug'] ?? null )
				&& $case['restBase'] === ( $edit_data['rest_base'] ?? null )
				&& array( $case['postType'] ) === ( $edit_data['types'] ?? null )
				&& \rest_url( '/wp/v2/taxonomies' ) === self::link_href( $links, 'collection' )
				&& \rest_url( '/wp/v2/' . $case['restBase'] ) === self::link_href( $links, 'https://api.w.org/items' ),
			'taxonomy edit item exposes edit fields and stable rest_base links',
			array( 'editData' => $edit_data, 'links' => $links )
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
				&& ! isset( $embed_data['description'], $embed_data['capabilities'], $embed_data['types'], $embed_data['visibility'] )
				&& false === $hidden_permission
				&& $missing_item instanceof \WP_Error
				&& 'rest_taxonomy_invalid' === $missing_item->get_error_code(),
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
				&& isset( $data[ $case['objectName'] ]['flag'], $data[ $case['objectName'] ]['label'] )
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
		$invalid_string = $controller->sanitize_callback( 'bad/value!', $sanitize_request, $case['stringName'] );
		$invalid_integer = $controller->sanitize_callback( 101, $sanitize_request, $case['integerName'] );

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
		self::collect_failure(
			$failures,
			$valid_string === $case['updatedString']
				&& $valid_integer === $case['updatedInteger']
				&& $invalid_string instanceof \WP_Error
				&& $invalid_integer instanceof \WP_Error
				&& $updated_response instanceof \WP_REST_Response
				&& $case['updatedString'] === \get_option( $case['stringOption'] )
				&& $case['updatedInteger'] === \get_option( $case['integerOption'] )
				&& $case['updatedString'] === ( $updated_data[ $case['stringName'] ] ?? null )
				&& $case['updatedInteger'] === ( $updated_data[ $case['integerName'] ] ?? null )
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
				) === \get_option( $case['objectOption'] ),
			'settings sanitize_callback validates invalid values and update_item uses option stub or pre-update filters',
			array(
				'validString' => $valid_string,
				'validInteger' => $valid_integer,
				'invalidString' => $invalid_string,
				'invalidInteger' => $invalid_integer,
				'updatedData' => $updated_data,
				'capturedFilterUpdate' => $captured_filter_update,
				'storedOptions' => array(
					'string' => \get_option( $case['stringOption'] ),
					'integer' => \get_option( $case['integerOption'] ),
					'object' => \get_option( $case['objectOption'] ),
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
			$denied instanceof \WP_Error
				&& 'rest_block_type_cannot_view' === $denied->get_error_code()
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
				&& $invalid instanceof \WP_Error
				&& 'rest_block_type_invalid' === $invalid->get_error_code(),
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
				&& $denied instanceof \WP_Error
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

	private static function post_type_case( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'postType'           => self::name_token( $ctx->fork( 'post-type' ), 'cfzpt', 20 ),
			'hiddenPostType'     => self::name_token( $ctx->fork( 'hidden-post-type' ), 'cfzhpt', 20 ),
			'restBase'           => self::route_token( $ctx->fork( 'rest-base' ), 'cfz-rest' ),
			'taxonomy'           => self::name_token( $ctx->fork( 'taxonomy' ), 'cfztax', 28 ),
			'hiddenTaxonomy'     => self::name_token( $ctx->fork( 'hidden-taxonomy' ), 'cfzhtax', 28 ),
			'taxonomyRestBase'   => self::route_token( $ctx->fork( 'tax-rest-base' ), 'cfz-tax' ),
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
			'postType'       => self::name_token( $ctx->fork( 'post-type' ), 'cfzpt', 20 ),
			'otherPostType'  => self::name_token( $ctx->fork( 'other-post-type' ), 'cfzopt', 20 ),
			'taxonomy'       => self::name_token( $ctx->fork( 'taxonomy' ), 'cfztax', 28 ),
			'hiddenTaxonomy' => self::name_token( $ctx->fork( 'hidden-taxonomy' ), 'cfzhtax', 28 ),
			'otherTaxonomy'  => self::name_token( $ctx->fork( 'other-taxonomy' ), 'cfzotax', 28 ),
			'restBase'       => self::route_token( $ctx->fork( 'rest-base' ), 'cfz-terms' ),
			'label'          => 'Fuzz Taxonomy ' . $ctx->int( 1, 999 ),
			'description'    => 'Generated REST controller taxonomy.',
			'hierarchical'   => $ctx->bool(),
		);
	}

	private static function settings_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		return array(
			'stringOption'        => 'cfz_string_' . $token,
			'integerOption'       => 'cfz_integer_' . $token,
			'objectOption'        => 'cfz_object_' . $token,
			'hiddenOption'        => 'cfz_hidden_' . $token,
			'invalidTypeOption'   => 'cfz_invalid_' . $token,
			'stringName'          => 'cfzString' . $token,
			'integerName'         => 'cfzInteger' . $token,
			'objectName'          => 'cfzObject' . $token,
			'stringDefault'       => 'Default ' . $ctx->int( 1, 99 ),
			'storedString'        => 'Stored ' . $ctx->int( 1, 99 ),
			'updatedString'       => 'Updated ' . $ctx->int( 100, 999 ),
			'integerDefault'      => $ctx->int( 0, 10 ),
			'storedInteger'       => $ctx->int( 11, 50 ),
			'updatedInteger'      => $ctx->int( 51, 100 ),
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
				'current_user',
				'new_allowed_options',
				'post_type_meta_caps',
				'wp_filter',
				'wp_post_statuses',
				'wp_post_types',
				'wp_registered_settings',
				'wp_rest_additional_fields',
				'wp_taxonomies',
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
