<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes REST widget, widget type, and sidebar controllers.
 */
final class RestWidgetsSidebarsSurface {
	public const NAME = 'rest-widgets-sidebars';

	private const PREVIEW_BYTES = 180;

	/** @var array<int,array<string,mixed>> */
	private static array $legacy_widget_events = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'rest-widgets-sidebars.bootstrap-apis-available',
					'Required WordPress REST widget APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot           = self::snapshot_state();
		$before_fingerprint = self::state_fingerprint();
		$rows               = array();
		$ob_level           = ob_get_level();

		try {
			self::reset_runtime();

			$case = self::widget_case( $ctx );
			self::seed_widgets( $case );

			$rows[] = self::check_route_and_schema_contracts( $ctx->fork( 'routes' ), $case );
			$rows[] = self::check_public_read_filters( $ctx->fork( 'public-read' ), $case );
			$rows[] = self::check_widget_type_encode_and_projection( $ctx->fork( 'widget-types' ), $case );
			$rows[] = self::check_widget_crud_instance_roundtrip( $ctx->fork( 'widget-crud' ), $case );
			$rows[] = self::check_sidebar_reorder_and_visibility( $ctx->fork( 'sidebars' ), $case );
			$rows[] = self::check_legacy_widget_form_data_and_delete_hooks( $ctx->fork( 'legacy' ), $case );
			$rows[] = self::check_head_short_circuit_and_field_projection( $ctx->fork( 'head-fields' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'rest-widgets-sidebars.surface-no-throw',
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
				'rest-widgets-sidebars.state-restored',
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

	public static function legacy_widget_callback( array $args = array() ): void {
		$settings = \get_option(
			'widget_cfz_legacy',
			array(
				'id'    => 'Default id',
				'title' => 'Default title',
			)
		);

		self::$legacy_widget_events[] = array(
			'type'    => 'render',
			'id'      => $settings['id'] ?? null,
			'title'   => $settings['title'] ?? null,
			'sidebar' => $args['id'] ?? null,
		);

		echo '<strong class="cfz-legacy-id">' . \esc_html( (string) ( $settings['id'] ?? '' ) ) . '</strong>';
		echo '<span class="cfz-legacy-title">' . \esc_html( (string) ( $settings['title'] ?? '' ) ) . '</span>';
	}

	public static function legacy_control_callback(): void {
		self::$legacy_widget_events[] = array(
			'type'       => 'control',
			'hasUpdate'  => isset( $_POST['update_cfz_legacy'] ),
			'postFields' => array_keys( $_POST ),
		);

		if ( isset( $_POST['update_cfz_legacy'] ) ) {
			\update_option(
				'widget_cfz_legacy',
				array(
					'id'    => \sanitize_text_field( \wp_unslash( $_POST['cfz_legacy_id'] ?? '' ) ),
					'title' => \sanitize_text_field( \wp_unslash( $_POST['cfz_legacy_title'] ?? '' ) ),
				)
			);
		}

		echo '<input name="cfz_legacy_id" value="">';
		echo '<input name="cfz_legacy_title" value="">';
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Error',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Server',
				'WP_REST_Sidebars_Controller',
				'WP_REST_Widget_Types_Controller',
				'WP_REST_Widgets_Controller',
				'WP_Widget',
				'WP_Widget_Factory',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'current_user_can',
				'get_option',
				'has_filter',
				'is_wp_error',
				'register_rest_route',
				'register_sidebar',
				'remove_action',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_filter_response_fields',
				'rest_get_server',
				'rest_is_field_included',
				'rest_url',
				'sanitize_key',
				'sanitize_text_field',
				'update_option',
				'wp_assign_widget_to_sidebar',
				'wp_cache_flush',
				'wp_find_widgets_sidebar',
				'wp_get_sidebars_widgets',
				'wp_parse_str',
				'wp_register_sidebar_widget',
				'wp_register_widget_control',
				'wp_render_widget',
				'wp_render_widget_control',
				'wp_set_current_user',
				'wp_set_sidebars_widgets',
				'wp_slash',
				'wp_unslash',
				'wp_widgets_init',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_route_and_schema_contracts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$server   = self::fresh_rest_server();

		$widgets_controller      = new \WP_REST_Widgets_Controller();
		$widget_types_controller = new \WP_REST_Widget_Types_Controller();
		$sidebars_controller     = new \WP_REST_Sidebars_Controller();
		$widgets_controller->register_routes();
		$widget_types_controller->register_routes();
		$sidebars_controller->register_routes();

		$routes          = $server->get_routes();
		$widgets_schema  = $widgets_controller->get_item_schema();
		$types_schema    = $widget_types_controller->get_item_schema();
		$sidebars_schema = $sidebars_controller->get_item_schema();

		self::collect_failure(
			$failures,
			isset(
				$routes['/wp/v2/widgets'],
				$routes['/wp/v2/widgets/(?P<id>[\w\-]+)'],
				$routes['/wp/v2/widget-types'],
				$routes['/wp/v2/widget-types/(?P<id>[a-zA-Z0-9_-]+)'],
				$routes['/wp/v2/widget-types/(?P<id>[a-zA-Z0-9_-]+)/encode'],
				$routes['/wp/v2/widget-types/(?P<id>[a-zA-Z0-9_-]+)/render'],
				$routes['/wp/v2/sidebars'],
				$routes['/wp/v2/sidebars/(?P<id>[\w-]+)']
			)
				&& self::route_has_methods( $routes['/wp/v2/widgets'], array( 'GET', 'POST' ) )
				&& self::route_has_methods( $routes['/wp/v2/widgets/(?P<id>[\w\-]+)'], array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) )
				&& self::route_has_methods( $routes['/wp/v2/widget-types/(?P<id>[a-zA-Z0-9_-]+)/encode'], array( 'POST' ) )
				&& self::route_has_methods( $routes['/wp/v2/sidebars/(?P<id>[\w-]+)'], array( 'GET', 'POST', 'PUT', 'PATCH' ) ),
			'controllers register expected REST route matrix',
			array( 'routes' => array_keys( $routes ) )
		);

		self::collect_failure(
			$failures,
			self::schema_has_properties( $widgets_schema, array( 'id', 'id_base', 'sidebar', 'rendered', 'rendered_form', 'instance', 'form_data' ) )
				&& self::schema_has_properties( $types_schema, array( 'id', 'name', 'description', 'is_multi', 'classname' ) )
				&& self::schema_has_properties( $sidebars_schema, array( 'id', 'name', 'description', 'status', 'widgets' ) )
				&& 'wp_inactive_widgets' === ( $widgets_schema['properties']['sidebar']['default'] ?? null )
				&& array( 'active', 'inactive' ) === ( $sidebars_schema['properties']['status']['enum'] ?? null ),
			'controller item schemas preserve widget, type, and sidebar contracts',
			array(
				'widgetProperties'  => array_keys( $widgets_schema['properties'] ?? array() ),
				'typeProperties'    => array_keys( $types_schema['properties'] ?? array() ),
				'sidebarProperties' => array_keys( $sidebars_schema['properties'] ?? array() ),
				'case'              => self::case_summary( $case ),
			)
		);

		return self::result( $ctx, 'rest-widgets-sidebars.routes.schemas', $failures );
	}

	private static function check_public_read_filters( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		\wp_set_current_user( 0 );

		$widgets_controller  = new \WP_REST_Widgets_Controller();
		$sidebars_controller = new \WP_REST_Sidebars_Controller();

		$widget_response = $widgets_controller->get_items( self::request( 'GET', '/wp/v2/widgets', array( 'context' => 'view' ) ) );
		$widget_data     = $widget_response instanceof \WP_REST_Response ? $widget_response->get_data() : array();
		$sidebar_response = $sidebars_controller->get_items( self::request( 'GET', '/wp/v2/sidebars', array( 'context' => 'view' ) ) );
		$sidebar_data     = $sidebar_response instanceof \WP_REST_Response ? $sidebar_response->get_data() : array();
		$hidden_request   = self::request( 'GET', '/wp/v2/widgets/' . $case['hiddenTextId'], array( 'context' => 'view' ), array( 'id' => $case['hiddenTextId'] ) );
		$hidden_check     = $widgets_controller->get_item_permissions_check( $hidden_request );
		$public_request   = self::request( 'GET', '/wp/v2/widgets/' . $case['publicTextId'], array( 'context' => 'view' ), array( 'id' => $case['publicTextId'] ) );
		$public_check     = $widgets_controller->get_item_permissions_check( $public_request );

		self::collect_failure(
			$failures,
			$widget_response instanceof \WP_REST_Response
				&& array( $case['publicTextId'], $case['legacyId'] ) === self::pluck_ids( $widget_data )
				&& ! self::contains_id( $widget_data, $case['hiddenTextId'] )
				&& true === $public_check
				&& self::error_matches( $hidden_check, 'rest_cannot_manage_widgets', 401 ),
			'public widget reads include only show_in_rest sidebars without manage capability',
			array(
				'widgets'     => $widget_data,
				'publicCheck' => $public_check,
				'hiddenCheck' => self::describe_error( $hidden_check ),
			)
		);

		self::collect_failure(
			$failures,
			$sidebar_response instanceof \WP_REST_Response
				&& array( $case['publicSidebar'] ) === self::pluck_ids( $sidebar_data )
				&& ! self::contains_id( $sidebar_data, $case['hiddenSidebar'] ),
			'public sidebar reads filter non show_in_rest sidebars without manage capability',
			array( 'sidebars' => $sidebar_data )
		);

		return self::result( $ctx, 'rest-widgets-sidebars.permissions.public-read', $failures );
	}

	private static function check_widget_type_encode_and_projection( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$filter_calls = array();
		$form_calls   = array();
		$type_filter  = static function ( $response, array $widget_type, \WP_REST_Request $request ) use ( &$filter_calls ) {
			$filter_calls[] = array(
				'id'     => $widget_type['id'] ?? null,
				'method' => $request->get_method(),
				'fields' => $request->get_param( '_fields' ),
			);
			return $response;
		};
		$form_filter  = static function ( $instance, $widget_object ) use ( &$form_calls ) {
			$form_calls[] = array(
				'idBase'   => $widget_object->id_base ?? null,
				'instance' => $instance,
			);
			return $instance;
		};

		\add_filter( 'rest_prepare_widget_type', $type_filter, 10, 3 );
		\add_filter( 'widget_form_callback', $form_filter, 10, 2 );
		$cap_filter = self::install_cap_filter( array( 'edit_theme_options' ) );
		try {
			$type_controller = new \WP_REST_Widget_Types_Controller();
			$items          = $type_controller->get_items( self::request( 'GET', '/wp/v2/widget-types', array( 'context' => 'view' ) ) );
			$item           = $type_controller->get_item(
				self::request(
					'GET',
					'/wp/v2/widget-types/text',
					array(
						'context' => 'edit',
						'_fields' => 'id,name,is_multi,_links',
					),
					array( 'id' => 'text' )
				)
			);
			$encoded        = $type_controller->encode_form_data(
				self::request(
					'POST',
					'/wp/v2/widget-types/search/encode',
					array(
						'form_data' => array(
							'widget-search' => array(
								-1 => array( 'title' => $case['searchTitle'] ),
							),
						),
						'number'    => $case['searchSeedNumber'],
					),
					array( 'id' => 'search' )
				)
			);
			$bad_hash       = $type_controller->encode_form_data(
				self::request(
					'POST',
					'/wp/v2/widget-types/search/encode',
					array(
						'instance' => array(
							'encoded' => base64_encode( serialize( array( 'title' => $case['searchTitle'] ) ) ),
							'hash'    => 'not-the-right-hash',
						),
					),
					array( 'id' => 'search' )
				)
			);
		} finally {
			self::remove_cap_filter( $cap_filter );
			\remove_filter( 'widget_form_callback', $form_filter, 10 );
			\remove_filter( 'rest_prepare_widget_type', $type_filter, 10 );
		}

		$item_data     = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links    = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		$items_data    = $items instanceof \WP_REST_Response ? $items->get_data() : array();
		$encoded_data  = $encoded instanceof \WP_REST_Response ? $encoded->get_data() : array();
		$decoded       = self::decode_instance_payload( $encoded_data['instance'] ?? null );
		$sorted_type_ids = self::pluck_ids( $items_data );
		$expected_sorted = $sorted_type_ids;
		sort( $expected_sorted );

		self::collect_failure(
			$failures,
			$items instanceof \WP_REST_Response
				&& count( $items_data ) >= 2
				&& $expected_sorted === $sorted_type_ids
				&& count( array_unique( $sorted_type_ids ) ) === count( $sorted_type_ids )
				&& in_array( 'text', $sorted_type_ids, true )
				&& in_array( 'search', $sorted_type_ids, true ),
			'widget type collection is sorted, deduplicated by id_base, and includes seeded core types',
			array( 'ids' => $sorted_type_ids )
		);

		self::collect_failure(
			$failures,
			$item instanceof \WP_REST_Response
				&& self::projected_keys_match( $item_data, array( 'id', 'is_multi', 'name' ) )
				&& 'text' === ( $item_data['id'] ?? null )
				&& true === ( $item_data['is_multi'] ?? null )
				&& isset( $item_links['collection'][0]['href'], $item_links['self'][0]['href'] )
				&& str_ends_with( $item_links['self'][0]['href'], '/wp/v2/widget-types/text' ),
			'widget type item respects _fields projection while preserving requested links',
			array( 'item' => $item_data, 'links' => $item_links )
		);

		self::collect_failure(
			$failures,
			$encoded instanceof \WP_REST_Response
				&& isset( $encoded_data['form'], $encoded_data['preview'], $encoded_data['instance']['encoded'], $encoded_data['instance']['hash'] )
				&& array( 'title' => $case['searchTitle'] ) === $decoded
				&& str_contains( $encoded_data['form'], 'widget-search-' . $case['searchSeedNumber'] . '-title' )
				&& str_contains( $encoded_data['preview'], 'widget_search' )
				&& str_contains( $encoded_data['preview'], \esc_html( $case['searchTitle'] ) )
				&& 1 === count( $form_calls )
				&& 'search' === ( $form_calls[0]['idBase'] ?? null )
				&& self::error_matches( $bad_hash, 'rest_invalid_widget', 400 ),
			'encode_form_data updates form payloads, returns verifiable instance hashes, and rejects bad hashes',
			array(
				'encoded'   => self::preview_encoded_response( $encoded_data ),
				'decoded'   => $decoded,
				'badHash'   => self::describe_error( $bad_hash ),
				'formCalls' => $form_calls,
			)
		);

		self::collect_failure(
			$failures,
			count( $filter_calls ) >= count( $items_data ) + 1
				&& false === \has_filter( 'rest_prepare_widget_type', $type_filter )
				&& false === \has_filter( 'widget_form_callback', $form_filter ),
			'widget type prepare and form filters fire and are removed',
			array( 'filterCalls' => array_slice( $filter_calls, 0, 8 ) )
		);

		return self::result( $ctx, 'rest-widgets-sidebars.widget-types.encode-projection', $failures );
	}

	private static function check_widget_crud_instance_roundtrip( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$save_calls = array();
		$save_action = static function ( string $id, string $sidebar_id, \WP_REST_Request $request, bool $creating ) use ( &$save_calls ): void {
			$save_calls[] = array(
				'id'       => $id,
				'sidebar'  => $sidebar_id,
				'creating' => $creating,
				'method'   => $request->get_method(),
			);
		};

		\add_action( 'rest_after_save_widget', $save_action, 10, 4 );
		$cap_filter = self::install_cap_filter( array( 'edit_theme_options', 'unfiltered_html' ) );
		try {
			$controller = new \WP_REST_Widgets_Controller();
			self::reset_widget_update_guard( 'text' );
			$created    = $controller->create_item(
				self::request(
					'POST',
					'/wp/v2/widgets',
					array(
						'id_base'  => 'text',
						'sidebar'  => $case['publicSidebar'],
						'instance' => array( 'raw' => $case['createInstance'] ),
					)
				)
			);
			$created_data = $created instanceof \WP_REST_Response ? $created->get_data() : array();
			$created_id   = isset( $created_data['id'] ) ? (string) $created_data['id'] : '';
			$created_number = self::widget_number( $created_id );
			$sidebar_after_create = '' !== $created_id ? \wp_find_widgets_sidebar( $created_id ) : null;
			$instance_payload = $created_data['instance'] ?? null;
			$decoded_created  = self::decode_instance_payload( $instance_payload );

			$projected_request = self::request(
				'GET',
				'/wp/v2/widgets/' . $created_id,
				array(
					'context' => 'edit',
					'_fields' => 'id,sidebar,instance,_links',
				),
				array( 'id' => $created_id )
			);
			$projected = self::apply_response_fields( $controller->get_item( $projected_request ), $projected_request );
			$projected_data  = $projected instanceof \WP_REST_Response ? $projected->get_data() : array();
			$projected_links = $projected instanceof \WP_REST_Response ? $projected->get_links() : array();

			self::reset_widget_update_guard( 'text' );
			$updated = $controller->update_item(
				self::request(
					'POST',
					'/wp/v2/widgets/' . $created_id,
					array(
						'instance' => array(
							'encoded' => base64_encode( serialize( $case['updateInstance'] ) ),
							'hash'    => \wp_hash( serialize( $case['updateInstance'] ) ),
						),
						'sidebar'  => $case['hiddenSidebar'],
					),
					array( 'id' => $created_id )
				)
			);
			$updated_data    = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
			$decoded_updated = self::decode_instance_payload( $updated_data['instance'] ?? null );

			$bad_raw_support = null;
			global $wp_widget_factory;
			if ( isset( $wp_widget_factory->widgets['WP_Widget_Text'] ) ) {
				$original_show = $wp_widget_factory->widgets['WP_Widget_Text']->widget_options['show_instance_in_rest'] ?? null;
				$wp_widget_factory->widgets['WP_Widget_Text']->widget_options['show_instance_in_rest'] = false;
				self::reset_widget_update_guard( 'text' );
				$bad_raw_support = $controller->create_item(
					self::request(
						'POST',
						'/wp/v2/widgets',
						array(
							'id_base'  => 'text',
							'sidebar'  => $case['publicSidebar'],
							'instance' => array( 'raw' => $case['updateInstance'] ),
						)
					)
				);
				$wp_widget_factory->widgets['WP_Widget_Text']->widget_options['show_instance_in_rest'] = $original_show;
			}
		} finally {
			self::remove_cap_filter( $cap_filter );
			\remove_action( 'rest_after_save_widget', $save_action, 10 );
		}

		$text_settings = \get_option( 'widget_text', array() );

		self::collect_failure(
			$failures,
			$created instanceof \WP_REST_Response
				&& 201 === $created->get_status()
				&& str_starts_with( $created_id, 'text-' )
				&& $case['publicSidebar'] === ( $created_data['sidebar'] ?? null )
				&& $created_number > 0
				&& is_array( $decoded_created )
				&& self::instance_contains_text( $decoded_created, $case['createInstance']['text'] )
				&& $case['publicSidebar'] === $sidebar_after_create,
			'created text widget has generated id, public sidebar, and decodable instance payload',
			array(
				'created'            => self::preview_widget_data( $created_data ),
				'decoded'            => $decoded_created,
				'sidebarAfterCreate' => $sidebar_after_create,
			)
		);

		self::collect_failure(
			$failures,
			$created_id !== ''
				&& \wp_find_widgets_sidebar( $created_id ) === $case['hiddenSidebar']
				&& isset( $text_settings[ $created_number ] )
				&& self::instance_contains_text( $text_settings[ $created_number ], $case['updateInstance']['text'] )
				&& $updated instanceof \WP_REST_Response
				&& $case['hiddenSidebar'] === ( $updated_data['sidebar'] ?? null )
				&& is_array( $decoded_updated )
				&& self::instance_contains_text( $decoded_updated, $case['updateInstance']['text'] ),
			'update_item accepts encoded instances, updates widget option, and reassigns sidebars',
			array(
				'updated'      => self::preview_widget_data( $updated_data ),
				'decoded'      => $decoded_updated,
				'sidebarMap'   => self::sidebar_map(),
				'saveCalls'    => $save_calls,
				'textSettings' => $text_settings,
			)
		);

		self::collect_failure(
			$failures,
			$projected instanceof \WP_REST_Response
				&& self::projected_keys_match( $projected_data, array( 'id', 'instance', 'sidebar' ) )
				&& isset(
					$projected_links['self'][0]['href'],
					$projected_links['collection'][0]['href'],
					$projected_links['about'][0]['href'],
					$projected_links['https://api.w.org/sidebar'][0]['href']
				)
				&& str_ends_with( $projected_links['about'][0]['href'], '/wp/v2/widget-types/text' ),
			'widget item projection keeps requested links and omits unrequested render fields',
			array( 'projected' => $projected_data, 'links' => $projected_links )
		);

		self::collect_failure(
			$failures,
			count( $save_calls ) >= 2
				&& true === ( $save_calls[0]['creating'] ?? null )
				&& false === ( $save_calls[1]['creating'] ?? null )
				&& self::error_matches( $bad_raw_support, 'rest_invalid_widget', 400 )
				&& false === \has_filter( 'rest_after_save_widget', $save_action ),
			'save hooks distinguish create/update and raw instances obey show_instance_in_rest',
			array(
				'saveCalls'     => $save_calls,
				'badRawSupport' => self::describe_error( $bad_raw_support ),
			)
		);

		return self::result( $ctx, 'rest-widgets-sidebars.widgets.crud-roundtrip', $failures );
	}

	private static function check_sidebar_reorder_and_visibility( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures   = array();
		$save_calls = array();
		$save_action = static function ( $sidebar, \WP_REST_Request $request ) use ( &$save_calls ): void {
			$save_calls[] = array(
				'id'      => is_array( $sidebar ) ? ( $sidebar['id'] ?? null ) : null,
				'widgets' => is_array( $sidebar ) ? ( $sidebar['widgets'] ?? null ) : null,
				'method'  => $request->get_method(),
			);
		};

		\add_action( 'rest_save_sidebar', $save_action, 10, 2 );
		$cap_filter = self::install_cap_filter( array( 'edit_theme_options' ) );
		try {
			$controller = new \WP_REST_Sidebars_Controller();
			$before_request = self::request(
				'GET',
				'/wp/v2/sidebars/' . $case['publicSidebar'],
				array(
					'context' => 'edit',
					'_fields' => 'id,status,widgets,_links',
				),
				array( 'id' => $case['publicSidebar'] )
			);
			$before     = self::apply_response_fields( $controller->get_item( $before_request ), $before_request );
			$updated    = $controller->update_item(
				self::request(
					'POST',
					'/wp/v2/sidebars/' . $case['publicSidebar'],
					array( 'widgets' => array( $case['hiddenTextId'], $case['publicTextId'] ) ),
					array( 'id' => $case['publicSidebar'] )
				)
			);
			$missing    = $controller->get_item(
				self::request( 'GET', '/wp/v2/sidebars/missing-sidebar', array(), array( 'id' => 'missing-sidebar' ) )
			);
		} finally {
			self::remove_cap_filter( $cap_filter );
			\remove_action( 'rest_save_sidebar', $save_action, 10 );
		}

		$before_data   = $before instanceof \WP_REST_Response ? $before->get_data() : array();
		$before_links  = $before instanceof \WP_REST_Response ? $before->get_links() : array();
		$updated_data  = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		$sidebars      = \wp_get_sidebars_widgets();

		self::collect_failure(
			$failures,
			$before instanceof \WP_REST_Response
				&& self::projected_keys_match( $before_data, array( 'id', 'status', 'widgets' ) )
				&& array( $case['publicTextId'], $case['legacyId'] ) === ( $before_data['widgets'] ?? null )
				&& isset(
					$before_links['self'][0]['href'],
					$before_links['collection'][0]['href'],
					$before_links['https://api.w.org/widget'][0]['href']
				)
				&& str_contains( $before_links['https://api.w.org/widget'][0]['href'], 'sidebar=' . rawurlencode( $case['publicSidebar'] ) ),
			'sidebar projection returns widget ids and widget collection link',
			array( 'before' => $before_data, 'links' => $before_links )
		);

		self::collect_failure(
			$failures,
			$updated instanceof \WP_REST_Response
				&& array( $case['hiddenTextId'], $case['publicTextId'] ) === ( $updated_data['widgets'] ?? null )
				&& array( $case['hiddenTextId'], $case['publicTextId'] ) === array_values( $sidebars[ $case['publicSidebar'] ] ?? array() )
				&& ! in_array( $case['hiddenTextId'], $sidebars[ $case['hiddenSidebar'] ] ?? array(), true )
				&& in_array( $case['legacyId'], $sidebars['wp_inactive_widgets'] ?? array(), true ),
			'update_item reorders target sidebar, steals widgets from other sidebars, and inactivates omitted widgets',
			array( 'updated' => $updated_data, 'sidebars' => $sidebars )
		);

		self::collect_failure(
			$failures,
			self::error_matches( $missing, 'rest_sidebar_not_found', 404 )
				&& 1 === count( $save_calls )
				&& $case['publicSidebar'] === ( $save_calls[0]['id'] ?? null )
				&& false === \has_filter( 'rest_save_sidebar', $save_action ),
			'sidebar missing errors and save action behavior are stable',
			array(
				'missing'   => self::describe_error( $missing ),
				'saveCalls' => $save_calls,
			)
		);

		return self::result( $ctx, 'rest-widgets-sidebars.sidebars.reorder', $failures );
	}

	private static function check_legacy_widget_form_data_and_delete_hooks( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures     = array();
		$delete_calls = array();
		$delete_action = static function ( string $widget_id, string $sidebar_id, $response, \WP_REST_Request $request ) use ( &$delete_calls ): void {
			$delete_calls[] = array(
				'widget'  => $widget_id,
				'sidebar' => $sidebar_id,
				'force'   => (bool) $request['force'],
				'status'  => $response instanceof \WP_REST_Response ? $response->get_status() : null,
			);
		};

		\add_action( 'rest_delete_widget', $delete_action, 10, 4 );
		$cap_filter = self::install_cap_filter( array( 'edit_theme_options' ) );
		try {
			$controller = new \WP_REST_Widgets_Controller();
			$updated    = $controller->update_item(
				self::request(
					'POST',
					'/wp/v2/widgets/' . $case['legacyId'],
					array(
						'form_data' => array(
							'cfz_legacy_id'     => $case['legacyUpdateId'],
							'cfz_legacy_title'  => $case['legacyUpdateTitle'],
							'update_cfz_legacy' => '1',
						),
					),
					array( 'id' => $case['legacyId'] )
				)
			);
			$soft_delete = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/widgets/' . $case['legacyId'],
					array( 'force' => false ),
					array( 'id' => $case['legacyId'] )
				)
			);
			$sidebars_after_soft_delete = \wp_get_sidebars_widgets();

			self::seed_legacy_widget_again( $case );
			$force_delete = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/widgets/' . $case['legacyId'],
					array( 'force' => true ),
					array( 'id' => $case['legacyId'] )
				)
			);
		} finally {
			self::remove_cap_filter( $cap_filter );
			\remove_action( 'rest_delete_widget', $delete_action, 10 );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		$stored       = \get_option( 'widget_cfz_legacy', array() );
		$soft_data    = $soft_delete instanceof \WP_REST_Response ? $soft_delete->get_data() : array();
		$force_data   = $force_delete instanceof \WP_REST_Response ? $force_delete->get_data() : array();
		$sidebars_after_soft_delete = $sidebars_after_soft_delete ?? array();

		self::collect_failure(
			$failures,
			$updated instanceof \WP_REST_Response
				&& null === ( $updated_data['instance'] ?? null )
				&& $case['legacyId'] === ( $updated_data['id'] ?? null )
				&& $case['legacyUpdateId'] === ( $stored['id'] ?? null )
				&& $case['legacyUpdateTitle'] === ( $stored['title'] ?? null )
				&& self::legacy_event_seen( 'control' ),
			'legacy form_data updates non-WP_Widget callbacks through sanitized POST payloads',
			array(
				'updated' => self::preview_widget_data( $updated_data ),
				'stored'  => $stored,
				'events'  => self::$legacy_widget_events,
			)
		);

		self::collect_failure(
			$failures,
			$soft_delete instanceof \WP_REST_Response
				&& $case['legacyId'] === ( $soft_data['id'] ?? null )
				&& 'wp_inactive_widgets' === ( $soft_data['sidebar'] ?? null )
				&& in_array( $case['legacyId'], $sidebars_after_soft_delete['wp_inactive_widgets'] ?? array(), true ),
			'soft delete moves legacy widgets into inactive sidebar',
			array( 'softDelete' => self::preview_widget_data( $soft_data ), 'sidebars' => $sidebars_after_soft_delete )
		);

		self::collect_failure(
			$failures,
			$force_delete instanceof \WP_REST_Response
				&& true === ( $force_data['deleted'] ?? null )
				&& isset( $force_data['previous']['id'] )
				&& $case['legacyId'] === $force_data['previous']['id']
				&& ! in_array( $case['legacyId'], \wp_get_sidebars_widgets()[ $case['publicSidebar'] ] ?? array(), true )
				&& 2 === count( $delete_calls )
				&& false === ( $delete_calls[0]['force'] ?? null )
				&& true === ( $delete_calls[1]['force'] ?? null )
				&& false === \has_filter( 'rest_delete_widget', $delete_action ),
			'force delete returns previous widget payload and delete hook records force mode',
			array(
				'forceDelete' => self::preview_force_delete_data( $force_data ),
				'deleteCalls' => $delete_calls,
				'events'      => self::$legacy_widget_events,
			)
		);

		return self::result( $ctx, 'rest-widgets-sidebars.widgets.legacy-form-delete', $failures );
	}

	private static function check_head_short_circuit_and_field_projection( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$calls    = array(
			'widget'  => 0,
			'type'    => 0,
			'sidebar' => 0,
		);
		$widget_filter = static function ( $response ) use ( &$calls ) {
			++$calls['widget'];
			return $response;
		};
		$type_filter = static function ( $response ) use ( &$calls ) {
			++$calls['type'];
			return $response;
		};
		$sidebar_filter = static function ( $response ) use ( &$calls ) {
			++$calls['sidebar'];
			return $response;
		};

		\add_filter( 'rest_prepare_widget', $widget_filter );
		\add_filter( 'rest_prepare_widget_type', $type_filter );
		\add_filter( 'rest_prepare_sidebar', $sidebar_filter );
		$cap_filter = self::install_cap_filter( array( 'edit_theme_options' ) );
		try {
			$widgets_controller      = new \WP_REST_Widgets_Controller();
			$widget_types_controller = new \WP_REST_Widget_Types_Controller();
			$sidebars_controller     = new \WP_REST_Sidebars_Controller();

			$widget_head = $widgets_controller->get_item(
				self::request( 'HEAD', '/wp/v2/widgets/' . $case['publicTextId'], array( '_fields' => 'id' ), array( 'id' => $case['publicTextId'] ) )
			);
			$type_head = $widget_types_controller->get_item(
				self::request( 'HEAD', '/wp/v2/widget-types/text', array( '_fields' => 'id' ), array( 'id' => 'text' ) )
			);
			$sidebar_head = $sidebars_controller->get_item(
				self::request( 'HEAD', '/wp/v2/sidebars/' . $case['publicSidebar'], array( '_fields' => 'id' ), array( 'id' => $case['publicSidebar'] ) )
			);
			$widgets_collection_head = $widgets_controller->get_items( self::request( 'HEAD', '/wp/v2/widgets', array( '_fields' => 'id' ) ) );
			$types_collection_head   = $widget_types_controller->get_items( self::request( 'HEAD', '/wp/v2/widget-types', array( '_fields' => 'id' ) ) );
			$sidebars_collection_head = $sidebars_controller->get_items( self::request( 'HEAD', '/wp/v2/sidebars', array( '_fields' => 'id' ) ) );
		} finally {
			self::remove_cap_filter( $cap_filter );
			\remove_filter( 'rest_prepare_sidebar', $sidebar_filter );
			\remove_filter( 'rest_prepare_widget_type', $type_filter );
			\remove_filter( 'rest_prepare_widget', $widget_filter );
		}

		self::collect_failure(
			$failures,
			self::empty_head_response( $widget_head )
				&& self::empty_head_response( $type_head )
				&& self::empty_head_response( $sidebar_head )
				&& self::empty_head_response( $widgets_collection_head )
				&& self::empty_head_response( $types_collection_head )
				&& self::empty_head_response( $sidebars_collection_head ),
			'HEAD requests return empty response bodies for item and collection endpoints',
			array(
				'widgetHead'             => self::response_summary( $widget_head ),
				'typeHead'               => self::response_summary( $type_head ),
				'sidebarHead'            => self::response_summary( $sidebar_head ),
				'widgetsCollectionHead'  => self::response_summary( $widgets_collection_head ),
				'typesCollectionHead'    => self::response_summary( $types_collection_head ),
				'sidebarsCollectionHead' => self::response_summary( $sidebars_collection_head ),
			)
		);

		self::collect_failure(
			$failures,
			array( 'widget' => 1, 'type' => 1, 'sidebar' => 1 ) === $calls
				&& false === \has_filter( 'rest_prepare_widget', $widget_filter )
				&& false === \has_filter( 'rest_prepare_widget_type', $type_filter )
				&& false === \has_filter( 'rest_prepare_sidebar', $sidebar_filter ),
			'HEAD item preparation filters run once while collection endpoints short-circuit before preparation',
			array( 'calls' => $calls )
		);

		return self::result( $ctx, 'rest-widgets-sidebars.head.short-circuit', $failures );
	}

	private static function reset_runtime(): void {
		self::$legacy_widget_events = array();

		$GLOBALS['wp_registered_sidebars']        = array();
		$GLOBALS['wp_registered_widgets']         = array();
		$GLOBALS['wp_registered_widget_controls'] = array();
		$GLOBALS['wp_registered_widget_updates']  = array();
		$GLOBALS['_wp_sidebars_widgets']          = array();
		$GLOBALS['sidebars_widgets']              = array();
		$GLOBALS['wp_query']                      = new \WP_Query();
		$GLOBALS['wp_the_query']                  = $GLOBALS['wp_query'];

		if ( isset( $GLOBALS['wp_widget_factory'] ) && $GLOBALS['wp_widget_factory'] instanceof \WP_Widget_Factory ) {
			$GLOBALS['wp_widget_factory']->widgets = array();
		} else {
			$GLOBALS['wp_widget_factory'] = new \WP_Widget_Factory();
		}

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'blog_charset'     => 'UTF-8',
					'home'             => 'http://example.test',
					'siteurl'          => 'http://example.test',
					'widget_cfz_legacy' => array(),
					'sidebars_widgets' => array(),
				)
			);
		} else {
			\update_option( 'home', 'http://example.test' );
			\update_option( 'siteurl', 'http://example.test' );
			\update_option( 'blog_charset', 'UTF-8' );
			\update_option( 'sidebars_widgets', array() );
		}

		\wp_cache_flush();
		\wp_set_current_user( 0 );
		\wp_widgets_init();
	}

	private static function seed_widgets( array $case ): void {
		global $wp_widget_factory;

		self::setup_widget(
			'text',
			$case['publicTextNumber'],
			array(
				'title'  => $case['publicTitle'],
				'text'   => $case['publicText'],
				'filter' => false,
				'visual' => false,
			)
		);
		self::setup_widget(
			'text',
			$case['hiddenTextNumber'],
			array(
				'title'  => $case['hiddenTitle'],
				'text'   => $case['hiddenText'],
				'filter' => false,
				'visual' => false,
			)
		);
		self::setup_widget(
			'search',
			$case['searchSeedNumber'],
			array( 'title' => $case['searchTitle'] )
		);

		if ( isset( $wp_widget_factory->widgets['WP_Widget_Text'] ) ) {
			$wp_widget_factory->widgets['WP_Widget_Text']->updated = false;
			$wp_widget_factory->widgets['WP_Widget_Text']->widget_options['show_instance_in_rest'] = true;
		}
		if ( isset( $wp_widget_factory->widgets['WP_Widget_Search'] ) ) {
			$wp_widget_factory->widgets['WP_Widget_Search']->updated = false;
			$wp_widget_factory->widgets['WP_Widget_Search']->widget_options['show_instance_in_rest'] = true;
		}

		\update_option(
			'widget_cfz_legacy',
			array(
				'id'    => $case['legacyInitialId'],
				'title' => $case['legacyInitialTitle'],
			)
		);
		\wp_register_widget_control(
			$case['legacyId'],
			'Component Fuzz Legacy',
			array( __CLASS__, 'legacy_control_callback' ),
			100,
			200
		);
		\wp_register_sidebar_widget(
			$case['legacyId'],
			'Component Fuzz Legacy',
			array( __CLASS__, 'legacy_widget_callback' ),
			array( 'description' => 'A generated legacy REST widget.' )
		);

		self::setup_sidebar(
			$case['publicSidebar'],
			array(
				'name'         => $case['publicSidebarName'],
				'description'  => $case['publicSidebarDescription'],
				'class'        => 'cfz-public',
				'show_in_rest' => true,
			),
			array( $case['publicTextId'], $case['legacyId'] )
		);
		self::setup_sidebar(
			$case['hiddenSidebar'],
			array(
				'name'         => $case['hiddenSidebarName'],
				'description'  => $case['hiddenSidebarDescription'],
				'class'        => 'cfz-hidden',
				'show_in_rest' => false,
			),
			array( $case['hiddenTextId'], $case['searchSeedId'] )
		);
	}

	private static function seed_legacy_widget_again( array $case ): void {
		\wp_register_widget_control(
			$case['legacyId'],
			'Component Fuzz Legacy',
			array( __CLASS__, 'legacy_control_callback' ),
			100,
			200
		);
		\wp_register_sidebar_widget(
			$case['legacyId'],
			'Component Fuzz Legacy',
			array( __CLASS__, 'legacy_widget_callback' ),
			array( 'description' => 'A generated legacy REST widget.' )
		);
		\wp_assign_widget_to_sidebar( $case['legacyId'], $case['publicSidebar'] );
	}

	private static function setup_widget( string $id_base, int $number, array $settings ): void {
		global $wp_widget_factory;

		$option_name = "widget_{$id_base}";
		$all         = \get_option( $option_name, array() );
		$all[ $number ] = $settings;
		\update_option( $option_name, $all );

		$widget_object = $wp_widget_factory->get_widget_object( $id_base );
		if ( $widget_object ) {
			$widget_object->_set( $number );
			$widget_object->_register_one( $number );
			$widget_object->updated = false;
		}
	}

	private static function setup_sidebar( string $id, array $attrs, array $widgets ): void {
		\register_sidebar(
			array_merge(
				array(
					'id'            => $id,
					'name'          => $id,
					'description'   => '',
					'before_widget' => '',
					'after_widget'  => '',
					'before_title'  => '',
					'after_title'   => '',
				),
				$attrs
			)
		);

		$sidebars = \get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			$sidebars = array();
		}
		$sidebars[ $id ] = $widgets;
		if ( ! isset( $sidebars['wp_inactive_widgets'] ) ) {
			$sidebars['wp_inactive_widgets'] = array();
		}

		\wp_set_sidebars_widgets( $sidebars );
	}

	private static function reset_widget_update_guard( string $id_base ): void {
		global $wp_registered_widget_updates, $wp_widget_factory;

		if ( $wp_widget_factory instanceof \WP_Widget_Factory ) {
			$widget_object = $wp_widget_factory->get_widget_object( $id_base );
			if ( $widget_object instanceof \WP_Widget ) {
				$widget_object->updated = false;
			}
		}

		$callback = $wp_registered_widget_updates[ $id_base ]['callback'] ?? null;
		if ( is_array( $callback ) && isset( $callback[0] ) && $callback[0] instanceof \WP_Widget ) {
			$callback[0]->updated = false;
		}
	}

	private static function fresh_rest_server(): \WP_REST_Server {
		$server = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		return $server;
	}

	private static function request( string $method, string $route, array $params = array(), array $route_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( array() !== $route_params ) {
			$request->set_url_params( $route_params );
		}

		return $request;
	}

	private static function widget_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$public_number = $ctx->int( 20, 49 );
		$hidden_number = $ctx->int( 50, 79 );
		$search_number = $ctx->int( 80, 99 );
		$token         = strtolower( substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
		$text          = self::bounded_text( $ctx->fork( 'public-text' ), 8, 56 );
		$hidden_text   = self::bounded_text( $ctx->fork( 'hidden-text' ), 8, 56 );
		$updated_text  = self::bounded_text( $ctx->fork( 'updated-text' ), 8, 56 );

		return array(
			'token'                    => $token,
			'publicSidebar'            => 'cfz-public-' . $token,
			'hiddenSidebar'            => 'cfz-hidden-' . $token,
			'publicSidebarName'        => 'Public Sidebar ' . $token,
			'hiddenSidebarName'        => 'Hidden Sidebar ' . $token,
			'publicSidebarDescription' => '<iframe></iframe>Public <b>' . \esc_html( $token ) . '</b><script></script>',
			'hiddenSidebarDescription' => 'Hidden <b>' . \esc_html( $token ) . '</b>',
			'publicTextNumber'         => $public_number,
			'hiddenTextNumber'         => $hidden_number,
			'searchSeedNumber'         => $search_number,
			'publicTextId'             => 'text-' . $public_number,
			'hiddenTextId'             => 'text-' . $hidden_number,
			'searchSeedId'             => 'search-' . $search_number,
			'legacyId'                 => 'cfz_legacy',
			'publicTitle'              => 'Public ' . $token,
			'hiddenTitle'              => 'Hidden ' . $token,
			'searchTitle'              => 'Search ' . $token,
			'publicText'               => $text,
			'hiddenText'               => $hidden_text,
			'createInstance'           => array(
				'title'  => 'Created ' . $token,
				'text'   => '<b>' . \esc_html( $text ) . '</b>',
				'filter' => false,
				'visual' => false,
			),
			'updateInstance'           => array(
				'title'  => 'Updated ' . $token,
				'text'   => '<em>' . \esc_html( $updated_text ) . '</em>',
				'filter' => false,
				'visual' => false,
			),
			'legacyInitialId'          => 'Legacy initial ' . $token,
			'legacyInitialTitle'       => 'Legacy title ' . $token,
			'legacyUpdateId'           => 'Legacy updated ' . $token,
			'legacyUpdateTitle'        => 'Legacy form title ' . $token,
		);
	}

	private static function bounded_text( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): string {
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $ctx->text( $min, $max ) );
		$text = trim( (string) $text );

		return '' === $text ? 'component fuzz text' : $text;
	}

	private static function install_cap_filter( array $granted_caps ): callable {
		$granted = array_fill_keys( $granted_caps, true );
		$filter  = static function ( array $allcaps ) use ( $granted ): array {
			foreach ( $granted as $cap => $allowed ) {
				$allcaps[ $cap ] = $allowed;
			}

			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, PHP_INT_MAX, 4 );

		return $filter;
	}

	private static function remove_cap_filter( callable $filter ): bool {
		return \remove_filter( 'user_has_cap', $filter, PHP_INT_MAX );
	}

	private static function route_has_methods( array $handlers, array $expected ): bool {
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

		foreach ( $expected as $method ) {
			if ( ! in_array( $method, $methods, true ) ) {
				return false;
			}
		}

		return true;
	}

	private static function schema_has_properties( array $schema, array $properties ): bool {
		foreach ( $properties as $property ) {
			if ( ! isset( $schema['properties'][ $property ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function pluck_ids( array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$ids[] = (string) $item['id'];
			}
		}

		return $ids;
	}

	private static function contains_id( array $items, string $id ): bool {
		return in_array( $id, self::pluck_ids( $items ), true );
	}

	private static function projected_keys_match( array $data, array $expected ): bool {
		$actual = array_keys( $data );
		sort( $actual );
		sort( $expected );

		return $expected === $actual;
	}

	private static function empty_head_response( $response ): bool {
		return $response instanceof \WP_REST_Response
			&& 200 === $response->get_status()
			&& array() === $response->get_data();
	}

	private static function apply_response_fields( $response, \WP_REST_Request $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		return \rest_filter_response_fields( $response, \rest_get_server(), $request );
	}

	private static function decode_instance_payload( $payload ) {
		if ( ! is_array( $payload ) || ! isset( $payload['encoded'], $payload['hash'] ) ) {
			return null;
		}

		$serialized = base64_decode( (string) $payload['encoded'], true );
		if ( ! is_string( $serialized ) || ! hash_equals( \wp_hash( $serialized ), (string) $payload['hash'] ) ) {
			return null;
		}

		return @unserialize( $serialized );
	}

	private static function widget_number( string $widget_id ): int {
		$parts = \wp_parse_widget_id( $widget_id );

		return isset( $parts['number'] ) ? (int) $parts['number'] : 0;
	}

	private static function instance_contains_text( array $instance, string $expected ): bool {
		return isset( $instance['text'] ) && html_entity_decode( wp_strip_all_tags( (string) $instance['text'] ), ENT_QUOTES, 'UTF-8' )
			=== html_entity_decode( wp_strip_all_tags( $expected ), ENT_QUOTES, 'UTF-8' );
	}

	private static function sidebar_map(): array {
		$sidebars = \wp_get_sidebars_widgets();
		foreach ( $sidebars as $id => $widgets ) {
			$sidebars[ $id ] = array_values( (array) $widgets );
		}

		return $sidebars;
	}

	private static function legacy_event_seen( string $type ): bool {
		foreach ( self::$legacy_widget_events as $event ) {
			if ( $type === ( $event['type'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function response_summary( $response ): array {
		if ( ! $response instanceof \WP_REST_Response ) {
			return array( 'type' => is_object( $response ) ? get_class( $response ) : gettype( $response ) );
		}

		return array(
			'status' => $response->get_status(),
			'data'   => $response->get_data(),
		);
	}

	private static function preview_encoded_response( array $data ): array {
		$out = $data;
		if ( isset( $out['form'] ) ) {
			$out['form'] = self::preview( (string) $out['form'] );
		}
		if ( isset( $out['preview'] ) ) {
			$out['preview'] = self::preview( (string) $out['preview'] );
		}

		return $out;
	}

	private static function preview_widget_data( array $data ): array {
		$out = $data;
		foreach ( array( 'rendered', 'rendered_form' ) as $field ) {
			if ( isset( $out[ $field ] ) && is_string( $out[ $field ] ) ) {
				$out[ $field ] = self::preview( $out[ $field ] );
			}
		}

		return $out;
	}

	private static function preview_force_delete_data( array $data ): array {
		if ( isset( $data['previous'] ) && is_array( $data['previous'] ) ) {
			$data['previous'] = self::preview_widget_data( $data['previous'] );
		}

		return $data;
	}

	private static function preview( string $value ): string {
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', $value );
		if ( strlen( $value ) <= self::PREVIEW_BYTES ) {
			return $value;
		}

		return substr( $value, 0, self::PREVIEW_BYTES ) . '...';
	}

	private static function case_summary( array $case ): array {
		return array(
			'token'         => $case['token'],
			'publicSidebar' => $case['publicSidebar'],
			'hiddenSidebar' => $case['hiddenSidebar'],
			'publicTextId'  => $case['publicTextId'],
			'hiddenTextId'  => $case['hiddenTextId'],
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failures'] = array_slice( $failures, 0, 8 );

		return self::row( $ctx, $invariant, array() === $failures, $data );
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ctx->result( $invariant, $ok, $data );
	}

	private static function skip( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		return $ctx->skip( $invariant, $reason, $data );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function error_matches( $value, string $code, ?int $status = null ): bool {
		if ( ! \is_wp_error( $value ) || $code !== $value->get_error_code() ) {
			return false;
		}

		if ( null === $status ) {
			return true;
		}

		$data = $value->get_error_data();

		return is_array( $data ) && $status === (int) ( $data['status'] ?? 0 );
	}

	private static function describe_error( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return $value;
		}

		return array(
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'line'    => $e->getLine(),
			'fileBase' => basename( $e->getFile() ),
			'file'    => $e->getFile(),
		);
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'_wp_sidebars_widgets',
				'_wp_theme_features',
				'current_screen',
				'current_user',
				'pagenow',
				'sidebars_widgets',
				'user_ID',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_query',
				'wp_registered_sidebars',
				'wp_registered_widgets',
				'wp_registered_widget_controls',
				'wp_registered_widget_updates',
				'wp_rest_additional_fields',
				'wp_rest_server',
				'wp_the_query',
				'wp_widget_factory',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'events'  => self::$legacy_widget_events,
			'globals' => $globals,
			'options' => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
			'post'    => $_POST,
			'request' => $_REQUEST,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::$legacy_widget_events = $snapshot['events'];

		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		$_POST    = $snapshot['post'];
		$_REQUEST = $snapshot['request'];
	}

	private static function state_fingerprint(): array {
		$globals = array();
		foreach (
			array(
				'_wp_sidebars_widgets',
				'current_user',
				'sidebars_widgets',
				'user_ID',
				'wp_filter',
				'wp_query',
				'wp_registered_sidebars',
				'wp_registered_widgets',
				'wp_registered_widget_controls',
				'wp_registered_widget_updates',
				'wp_rest_server',
				'wp_the_query',
				'wp_widget_factory',
			) as $name
		) {
			$globals[ $name ] = self::global_signature( $name );
		}

		return array(
			'globals'     => $globals,
			'optionsHash' => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? self::stable_hash( $GLOBALS['wpdb']->component_fuzz_get_options() )
				: null,
			'postHash'    => self::stable_hash( $_POST ),
			'requestHash' => self::stable_hash( $_REQUEST ),
		);
	}

	private static function first_difference( array $before, array $after ): ?array {
		foreach ( $before as $key => $value ) {
			if ( ! array_key_exists( $key, $after ) ) {
				return array(
					'key'    => $key,
					'before' => $value,
					'after'  => null,
				);
			}

			if ( is_array( $value ) && is_array( $after[ $key ] ) ) {
				$nested = self::first_difference( $value, $after[ $key ] );
				if ( null !== $nested ) {
					$nested['key'] = $key . '.' . $nested['key'];
					return $nested;
				}
				continue;
			}

			if ( $after[ $key ] !== $value ) {
				return array(
					'key'    => $key,
					'before' => $value,
					'after'  => $after[ $key ],
				);
			}
		}

		foreach ( $after as $key => $value ) {
			if ( ! array_key_exists( $key, $before ) ) {
				return array(
					'key'   => $key,
					'after' => $value,
				);
			}
		}

		return null;
	}

	private static function global_signature( string $name ): array {
		if ( ! array_key_exists( $name, $GLOBALS ) ) {
			return array( 'exists' => false );
		}

		return array(
			'exists'   => true,
			'signature' => self::value_signature( $GLOBALS[ $name ] ),
		);
	}

	private static function value_signature( $value ): array {
		if ( is_array( $value ) ) {
			$hook_counts = array();
			if ( class_exists( 'WP_Hook', false ) ) {
				foreach ( $value as $key => $item ) {
					if ( $item instanceof \WP_Hook ) {
						$hook_counts[ (string) $key ] = self::hook_callback_count( $item );
					}
				}
			}

			if ( array() !== $hook_counts ) {
				return array(
					'type'       => 'array',
					'count'      => count( $value ),
					'keys'       => array_map( 'strval', array_keys( $value ) ),
					'hookCounts' => $hook_counts,
				);
			}

			return array(
				'type' => 'array',
				'hash' => self::stable_hash( $value ),
			);
		}

		if ( is_object( $value ) ) {
			$signature = array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);

			if ( $value instanceof \WP_Widget_Factory ) {
				$signature['widgets'] = array_keys( $value->widgets );
			}

			if ( $value instanceof \WP_REST_Server ) {
				$signature['routes'] = array_keys( $value->get_routes() );
			}

			if ( class_exists( 'WP_Hook', false ) && $value instanceof \WP_Hook ) {
				$signature['hash'] = self::stable_hash( $value->callbacks );
			}

			return $signature;
		}

		return array(
			'type'  => gettype( $value ),
			'value' => is_scalar( $value ) || null === $value ? $value : null,
		);
	}

	private static function clone_value( $value ) {
		if ( is_object( $value ) ) {
			return clone $value;
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::clone_value( $item );
			}
			return $out;
		}

		return $value;
	}

	private static function hook_callback_count( \WP_Hook $hook ): int {
		$count = 0;
		foreach ( $hook->callbacks as $callbacks ) {
			$count += is_array( $callbacks ) ? count( $callbacks ) : 0;
		}

		return $count;
	}

	private static function stable_hash( $value ): string {
		return hash( 'sha256', serialize( $value ) );
	}
}
