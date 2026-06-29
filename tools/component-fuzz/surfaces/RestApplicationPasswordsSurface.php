<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes the REST application passwords controller against synthetic users and app-password storage.
 */
final class RestApplicationPasswordsSurface {
	public const NAME = 'rest-application-passwords';

	private const SAMPLE_BYTES = 220;

	/** @var array<int,array<int,array<string,mixed>>> */
	private static array $application_passwords = array();
	private static bool $application_passwords_available = true;
	private static bool $application_passwords_available_for_user = true;

	/** @var array<string,bool> */
	private static array $denied_caps = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'rest-application-passwords.bootstrap-apis-available',
					'Required WordPress REST application password APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot           = self::snapshot_state();
		$before_fingerprint = self::state_fingerprint();
		$rows               = array();
		$ob_level           = ob_get_level();

		try {
			self::reset_runtime_state();
			self::reset_static_state();
			self::install_scoped_filters();

			$rows[] = self::check_route_and_schema_contracts( $ctx->fork( 'routes' ) );
			$rows[] = self::check_crud_hooks_and_projection( $ctx->fork( 'crud' ) );
			$rows[] = self::check_permission_and_availability_errors( $ctx->fork( 'errors' ) );
			$rows[] = self::check_introspection_paths( $ctx->fork( 'introspection' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'rest-application-passwords.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}

			self::reset_static_state();
			self::restore_state( $snapshot );

			$after_fingerprint = self::state_fingerprint();
			$rows[]            = self::row(
				$ctx,
				'rest-application-passwords.state-restored',
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

	public static function filter_get_user_metadata( $value, int $user_id, string $meta_key, bool $single, string $meta_type ) {
		if ( 'user' !== $meta_type || \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key ) {
			return $value;
		}

		$passwords = self::$application_passwords[ $user_id ] ?? array();
		return $single ? array( $passwords ) : array( $passwords );
	}

	public static function filter_update_user_metadata( $check, int $user_id, string $meta_key, $meta_value, $prev_value ) {
		unset( $check, $prev_value );

		if ( \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS !== $meta_key ) {
			return null;
		}

		self::$application_passwords[ $user_id ] = is_array( $meta_value ) ? array_values( $meta_value ) : array();
		return true;
	}

	public static function filter_application_passwords_available(): bool {
		return self::$application_passwords_available;
	}

	public static function filter_application_passwords_available_for_user( $available, $user ): bool {
		unset( $available );
		return $user instanceof \WP_User && $user->exists() && self::$application_passwords_available_for_user;
	}

	public static function filter_map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		unset( $user_id, $args );

		$app_password_caps = array(
			'create_app_password',
			'delete_app_password',
			'delete_app_passwords',
			'edit_app_password',
			'list_app_passwords',
			'read_app_password',
		);

		if ( isset( self::$denied_caps[ $cap ] ) ) {
			return array( 'do_not_allow' );
		}

		if ( in_array( $cap, $app_password_caps, true ) ) {
			return array( 'exist' );
		}

		return $caps;
	}

	public static function filter_user_has_cap( array $allcaps, array $caps = array() ): array {
		foreach ( $caps as $cap ) {
			if ( 'do_not_allow' !== $cap ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Application_Passwords',
				'WP_Error',
				'WP_REST_Application_Passwords_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Server',
				'WP_User',
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
				'get_current_user_id',
				'get_userdata',
				'get_user_meta',
				'has_filter',
				'is_user_logged_in',
				'is_wp_error',
				'register_rest_route',
				'remove_action',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_get_authenticated_app_password',
				'rest_is_field_included',
				'rest_url',
				'sanitize_text_field',
				'update_user_meta',
				'wp_cache_flush',
				'wp_generate_password',
				'wp_insert_user',
				'wp_is_application_passwords_available',
				'wp_is_application_passwords_available_for_user',
				'wp_set_current_user',
				'wp_slash',
				'wp_unslash',
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

	private static function check_route_and_schema_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$server   = self::fresh_server();
		( new \WP_REST_Application_Passwords_Controller() )->register_routes();

		$routes          = $server->get_routes( 'wp/v2' );
		$registered_keys = array_keys( $routes );
		sort( $registered_keys );

		$collection = '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords';
		$introspect = '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords/introspect';
		$item       = '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords/(?P<uuid>[\w\-]+)';

		$collection_data = $server->get_data_for_route( $collection, $routes[ $collection ] ?? array(), 'help' );
		$item_data       = $server->get_data_for_route( $item, $routes[ $item ] ?? array(), 'help' );
		$schema          = ( new \WP_REST_Application_Passwords_Controller() )->get_item_schema();

		self::collect_failure(
			$failures,
			in_array( 'wp/v2', $server->get_namespaces(), true )
				&& isset( $routes[ $collection ], $routes[ $introspect ], $routes[ $item ] )
				&& array( 'DELETE', 'GET', 'POST' ) === self::route_methods( $routes[ $collection ] )
				&& array( 'GET' ) === self::route_methods( $routes[ $introspect ] )
				&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === self::route_methods( $routes[ $item ] )
				&& self::schema_has_properties( $schema, array( 'app_id', 'created', 'last_ip', 'last_used', 'name', 'password', 'uuid' ) )
				&& self::route_data_has_endpoint_args( $collection_data, array( 'GET' ), array( 'context' ) )
				&& self::route_data_has_endpoint_args( $collection_data, array( 'POST' ), array( 'app_id', 'name' ) )
				&& self::route_data_has_endpoint_args( $item_data, array( 'GET' ), array( 'context' ) )
				&& self::route_data_schema_has_properties( $collection_data, array( 'app_id', 'name', 'uuid' ) )
				&& self::route_data_schema_contexts_match( $collection_data, 'password', array( 'edit' ) )
				&& self::route_data_schema_contexts_match( $collection_data, 'uuid', array( 'embed', 'edit', 'view' ) ),
			'application password routes expose expected methods, endpoint args, and schema contexts',
			array(
				'registeredRoutes' => $registered_keys,
				'collectionData'   => $collection_data,
				'itemData'         => $item_data,
				'schemaProperties' => array_keys( $schema['properties'] ?? array() ),
			)
		);

		return self::row(
			$ctx,
			'rest-application-passwords.routes-schema-contracts',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_crud_hooks_and_projection( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::case_for_context( $ctx );

		self::reset_runtime_state();
		self::reset_static_state();
		$users  = self::seed_users( $case );
		$server = self::fresh_server();
		( new \WP_REST_Application_Passwords_Controller() )->register_routes();

		$pre_insert_calls = array();
		$after_calls      = array();
		$prepare_calls    = array();
		$pre_filter       = static function ( $prepared, \WP_REST_Request $request ) use ( &$pre_insert_calls ) {
			$pre_insert_calls[] = array(
				'method' => $request->get_method(),
				'name'   => isset( $prepared->name ) ? (string) $prepared->name : null,
				'appId'  => isset( $prepared->app_id ) ? (string) $prepared->app_id : null,
				'uuid'   => $request['uuid'],
			);
			return $prepared;
		};
		$after_action     = static function ( array $item, \WP_REST_Request $request, bool $creating ) use ( &$after_calls ): void {
			$after_calls[] = array(
				'creating' => $creating,
				'method'   => $request->get_method(),
				'uuid'     => $item['uuid'] ?? null,
				'name'     => $item['name'] ?? null,
			);
		};
		$prepare_filter   = static function ( \WP_REST_Response $response, array $item, \WP_REST_Request $request ) use ( &$prepare_calls ): \WP_REST_Response {
			$prepare_calls[] = array(
				'context' => $request['context'],
				'method'  => $request->get_method(),
				'uuid'    => $item['uuid'] ?? null,
				'fields'  => $request['_fields'],
			);
			return $response;
		};

		\add_filter( 'rest_pre_insert_application_password', $pre_filter, 10, 2 );
		\add_action( 'rest_after_insert_application_password', $after_action, 10, 3 );
		\add_filter( 'rest_prepare_application_password', $prepare_filter, 10, 3 );

		try {
			$create_response = self::dispatch( $server,
				self::request(
					'POST',
					'/wp/v2/users/me/application-passwords',
					array(),
					array(),
					array(
						'app_id'  => $case['appId'],
						'context' => 'edit',
						'name'    => $case['nameRaw'],
					)
				)
			);
			$create_data     = $create_response instanceof \WP_REST_Response ? $create_response->get_data() : array();
			$created_uuid    = is_array( $create_data ) ? (string) ( $create_data['uuid'] ?? '' ) : '';
			$created_item    = '' !== $created_uuid ? \WP_Application_Passwords::get_user_application_password( $users['primary'], $created_uuid ) : null;
			$headers         = $create_response instanceof \WP_REST_Response ? $create_response->get_headers() : array();
			$created_plain   = is_array( $create_data ) ? (string) ( $create_data['password'] ?? '' ) : '';
			$unchunked       = str_replace( ' ', '', $created_plain );

			self::collect_failure(
				$failures,
				$create_response instanceof \WP_REST_Response
					&& 201 === $create_response->get_status()
					&& '' !== $created_uuid
					&& $case['appId'] === ( $create_data['app_id'] ?? null )
					&& $case['nameSanitized'] === ( $create_data['name'] ?? null )
					&& is_string( $create_data['password'] ?? null )
					&& \WP_Application_Passwords::PW_LENGTH === strlen( $unchunked )
					&& is_array( $created_item )
					&& \WP_Application_Passwords::check_password( $unchunked, (string) $created_item['password'] )
					&& isset( $headers['Location'] )
					&& is_string( $headers['Location'] )
					&& str_contains( $headers['Location'], $created_uuid ),
				'POST dispatch creates an item, returns the one-time password, Location header, and stored hash match',
				array(
					'createStatus' => $create_response instanceof \WP_REST_Response ? $create_response->get_status() : null,
					'createData'   => $create_data,
					'headers'      => $headers,
					'storedItem'   => $created_item,
				)
			);

			$collection_response = self::dispatch( $server,
				self::request(
					'GET',
					'/wp/v2/users/' . $users['primary'] . '/application-passwords',
					array(
						'context' => 'edit',
						'_fields' => 'uuid,name,app_id,password',
					)
				)
			);
			$collection_data     = $collection_response instanceof \WP_REST_Response ? $collection_response->get_data() : array();
			$collection_item     = is_array( $collection_data ) && isset( $collection_data[0] ) && is_array( $collection_data[0] ) ? $collection_data[0] : array();

			self::collect_failure(
				$failures,
				$collection_response instanceof \WP_REST_Response
					&& 200 === $collection_response->get_status()
					&& 1 === count( $collection_data )
					&& $created_uuid === ( $collection_item['uuid'] ?? null )
					&& $case['nameSanitized'] === ( $collection_item['name'] ?? null )
					&& $case['appId'] === ( $collection_item['app_id'] ?? null )
					&& ! array_key_exists( 'password', $collection_item ),
				'GET collection lists stored items without leaking the one-time password field',
				array(
					'collectionStatus' => $collection_response instanceof \WP_REST_Response ? $collection_response->get_status() : null,
					'collectionData'   => $collection_data,
				)
			);

			$item_response = self::dispatch( $server,
				self::request(
					'GET',
					'/wp/v2/users/me/application-passwords/' . $created_uuid,
					array(
						'context' => 'edit',
						'_fields' => 'uuid,name,app_id,_links',
					)
				)
			);
			$item_data     = $item_response instanceof \WP_REST_Response ? $item_response->get_data() : array();
			$item_links    = $item_response instanceof \WP_REST_Response ? $item_response->get_links() : array();

			self::collect_failure(
				$failures,
				$item_response instanceof \WP_REST_Response
					&& 200 === $item_response->get_status()
					&& array( 'app_id', 'name', 'uuid' ) === self::sorted_keys( $item_data )
					&& $created_uuid === ( $item_data['uuid'] ?? null )
					&& str_contains( (string) self::link_href( $item_links, 'self' ), '/wp/v2/users/' . $users['primary'] . '/application-passwords/' . $created_uuid ),
				'GET item honors _fields projection while preserving requested REST links',
				array(
					'itemData'  => $item_data,
					'itemLinks' => $item_links,
				)
			);

			$update_response = self::dispatch( $server,
				self::request(
					'PATCH',
					'/wp/v2/users/me/application-passwords/' . $created_uuid,
					array(),
					array(),
					array(
						'app_id'  => $case['updatedAppId'],
						'context' => 'edit',
						'name'    => $case['updatedNameRaw'],
					)
				)
			);
			$update_data     = $update_response instanceof \WP_REST_Response ? $update_response->get_data() : array();
			$updated_item    = \WP_Application_Passwords::get_user_application_password( $users['primary'], $created_uuid );

			self::collect_failure(
				$failures,
				$update_response instanceof \WP_REST_Response
					&& 200 === $update_response->get_status()
					&& $case['updatedNameSanitized'] === ( $update_data['name'] ?? null )
					&& $case['appId'] === ( $update_data['app_id'] ?? null )
					&& is_array( $updated_item )
					&& $case['updatedNameSanitized'] === ( $updated_item['name'] ?? null )
					&& $case['appId'] === ( $updated_item['app_id'] ?? null ),
				'PATCH updates only mutable fields and ignores app_id changes on existing passwords',
				array(
					'updateStatus' => $update_response instanceof \WP_REST_Response ? $update_response->get_status() : null,
					'updateData'   => $update_data,
					'storedItem'   => $updated_item,
				)
			);

			$delete_response = self::dispatch( $server, self::request( 'DELETE', '/wp/v2/users/me/application-passwords/' . $created_uuid ) );
			$delete_data     = $delete_response instanceof \WP_REST_Response ? $delete_response->get_data() : array();
			$missing_after   = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/me/application-passwords/' . $created_uuid ) );

			self::collect_failure(
				$failures,
				$delete_response instanceof \WP_REST_Response
					&& 200 === $delete_response->get_status()
					&& true === ( $delete_data['deleted'] ?? null )
					&& $created_uuid === ( $delete_data['previous']['uuid'] ?? null )
					&& $case['updatedNameSanitized'] === ( $delete_data['previous']['name'] ?? null )
					&& self::response_error_ok( $missing_after, 'rest_application_password_not_found', 404 ),
				'DELETE item returns the previous representation and subsequent reads fail with not-found',
				array(
					'deleteData'   => $delete_data,
					'missingAfter' => $missing_after,
				)
			);

			$first_extra  = self::create_password_fixture( $users['primary'], $case['bulkNameA'], self::uuid_from_token( $case['token'] . 'bulk-a' ) );
			$second_extra = self::create_password_fixture( $users['primary'], $case['bulkNameB'], self::uuid_from_token( $case['token'] . 'bulk-b' ) );
			$bulk_delete  = self::dispatch( $server, self::request( 'DELETE', '/wp/v2/users/me/application-passwords' ) );
			$bulk_data    = $bulk_delete instanceof \WP_REST_Response ? $bulk_delete->get_data() : array();

			self::collect_failure(
				$failures,
				is_array( $first_extra )
					&& is_array( $second_extra )
					&& $bulk_delete instanceof \WP_REST_Response
					&& 200 === $bulk_delete->get_status()
					&& true === ( $bulk_data['deleted'] ?? null )
					&& 2 === (int) ( $bulk_data['count'] ?? -1 )
					&& array() === \WP_Application_Passwords::get_user_application_passwords( $users['primary'] ),
				'DELETE collection removes all passwords for the selected user and reports the exact count',
				array(
					'firstExtra' => $first_extra,
					'secondExtra' => $second_extra,
					'bulkData'   => $bulk_data,
				)
			);

			self::collect_failure(
				$failures,
				2 === count( $pre_insert_calls )
					&& true === ( $after_calls[0]['creating'] ?? null )
					&& false === ( $after_calls[1]['creating'] ?? null )
					&& $created_uuid === ( $after_calls[0]['uuid'] ?? null )
					&& $created_uuid === ( $after_calls[1]['uuid'] ?? null )
					&& count( $prepare_calls ) >= 5,
				'REST pre-insert, after-insert, and prepare hooks fire with create/update distinctions',
				array(
					'preInsertCalls' => $pre_insert_calls,
					'afterCalls'    => $after_calls,
					'prepareCalls'  => array_slice( $prepare_calls, 0, 12 ),
				)
			);
		} finally {
			\remove_filter( 'rest_pre_insert_application_password', $pre_filter, 10 );
			\remove_action( 'rest_after_insert_application_password', $after_action, 10 );
			\remove_filter( 'rest_prepare_application_password', $prepare_filter, 10 );
		}

		return self::row(
			$ctx,
			'rest-application-passwords.crud-hooks-projection',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_permission_and_availability_errors( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::case_for_context( $ctx );

		self::reset_runtime_state();
		self::reset_static_state();
		$users  = self::seed_users( $case );
		$server = self::fresh_server();
		( new \WP_REST_Application_Passwords_Controller() )->register_routes();

		$item = self::create_password_fixture( $users['primary'], $case['nameSanitized'], $case['appId'] );
		$uuid = is_array( $item ) ? (string) ( $item['uuid'] ?? '' ) : '';

		$permission_cases = array(
			array( 'cap' => 'list_app_passwords', 'method' => 'GET', 'route' => '/wp/v2/users/me/application-passwords', 'code' => 'rest_cannot_list_application_passwords' ),
			array( 'cap' => 'create_app_password', 'method' => 'POST', 'route' => '/wp/v2/users/me/application-passwords', 'code' => 'rest_cannot_create_application_passwords', 'body' => array( 'name' => $case['nameRaw'], 'app_id' => $case['appId'] ) ),
			array( 'cap' => 'read_app_password', 'method' => 'GET', 'route' => '/wp/v2/users/me/application-passwords/' . $uuid, 'code' => 'rest_cannot_read_application_password' ),
			array( 'cap' => 'edit_app_password', 'method' => 'PATCH', 'route' => '/wp/v2/users/me/application-passwords/' . $uuid, 'code' => 'rest_cannot_edit_application_password', 'body' => array( 'name' => $case['updatedNameRaw'] ) ),
			array( 'cap' => 'delete_app_password', 'method' => 'DELETE', 'route' => '/wp/v2/users/me/application-passwords/' . $uuid, 'code' => 'rest_cannot_delete_application_password' ),
			array( 'cap' => 'delete_app_passwords', 'method' => 'DELETE', 'route' => '/wp/v2/users/me/application-passwords', 'code' => 'rest_cannot_delete_application_passwords' ),
		);

		$permission_results = array();
		foreach ( $permission_cases as $permission_case ) {
			self::$denied_caps = array( $permission_case['cap'] => true );
			$response          = self::dispatch( $server,
				self::request(
					$permission_case['method'],
					$permission_case['route'],
					array(),
					array(),
					$permission_case['body'] ?? array()
				)
			);
			self::$denied_caps = array();

			$permission_results[] = array(
				'cap'      => $permission_case['cap'],
				'response' => $response,
			);
			self::collect_failure(
				$failures,
				self::response_error_ok( $response, $permission_case['code'], 403 ),
				'denied app-password capability maps to the documented REST error',
				array(
					'case'     => $permission_case,
					'response' => $response,
				)
			);
		}

		$invalid_user = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/0/application-passwords' ) );

		\wp_set_current_user( 0 );
		$logged_out_me = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/me/application-passwords' ) );
		\wp_set_current_user( $users['primary'] );

		self::$application_passwords_available = false;
		$site_disabled                         = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/' . $users['primary'] . '/application-passwords' ) );
		self::$application_passwords_available = true;

		self::$application_passwords_available_for_user = false;
		$user_disabled                                  = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/' . $users['primary'] . '/application-passwords' ) );
		self::$application_passwords_available_for_user = true;

		self::collect_failure(
			$failures,
			self::response_error_ok( $invalid_user, 'rest_user_invalid_id', 404 )
				&& self::response_error_ok( $logged_out_me, 'rest_not_logged_in', 401 )
				&& self::response_error_ok( $site_disabled, 'application_passwords_disabled', 501 )
				&& self::response_error_ok( $user_disabled, 'application_passwords_disabled_for_user', 501 ),
			'user resolution and availability errors fail closed with stable REST codes',
			array(
				'invalidUser'  => $invalid_user,
				'loggedOutMe'  => $logged_out_me,
				'siteDisabled' => $site_disabled,
				'userDisabled' => $user_disabled,
			)
		);

		return self::row(
			$ctx,
			'rest-application-passwords.permission-availability-errors',
			array() === $failures,
			array(
				'case'              => self::case_summary( $case ),
				'permissionResults' => $permission_results,
				'failures'          => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_introspection_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::case_for_context( $ctx );

		self::reset_runtime_state();
		self::reset_static_state();
		$users  = self::seed_users( $case );
		$server = self::fresh_server();
		( new \WP_REST_Application_Passwords_Controller() )->register_routes();

		$item = self::create_password_fixture( $users['primary'], $case['nameSanitized'], $case['appId'] );
		$uuid = is_array( $item ) ? (string) ( $item['uuid'] ?? '' ) : '';

		$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
		$introspected                                = self::dispatch( $server,
			self::request(
				'GET',
				'/wp/v2/users/me/application-passwords/introspect',
				array( '_fields' => 'uuid,name,app_id' )
			)
		);
		$introspected_data                           = $introspected instanceof \WP_REST_Response ? $introspected->get_data() : array();

		$GLOBALS['wp_rest_application_password_uuid'] = null;
		$no_authenticated                            = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/me/application-passwords/introspect' ) );

		$GLOBALS['wp_rest_application_password_uuid'] = $uuid;
		$wrong_user                                  = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/' . $users['other'] . '/application-passwords/introspect' ) );

		$GLOBALS['wp_rest_application_password_uuid'] = self::uuid_from_token( $case['token'] . '-missing' );
		$missing_authenticated                       = self::dispatch( $server, self::request( 'GET', '/wp/v2/users/me/application-passwords/introspect' ) );

		self::collect_failure(
			$failures,
			$introspected instanceof \WP_REST_Response
				&& 200 === $introspected->get_status()
				&& array( 'app_id', 'name', 'uuid' ) === self::sorted_keys( $introspected_data )
				&& $uuid === ( $introspected_data['uuid'] ?? null )
				&& self::response_error_ok( $no_authenticated, 'rest_no_authenticated_app_password', 404 )
				&& self::response_error_ok( $wrong_user, 'rest_cannot_introspect_app_password_for_non_authenticated_user', 403 )
				&& self::response_error_ok( $missing_authenticated, 'rest_application_password_not_found', 500 ),
			'introspection returns the authenticated password only for the current user and distinguishes missing auth state',
			array(
				'introspected'          => $introspected_data,
				'noAuthenticated'       => $no_authenticated,
				'wrongUser'             => $wrong_user,
				'missingAuthenticated'  => $missing_authenticated,
				'authenticatedPassword' => $uuid,
			)
		);

		return self::row(
			$ctx,
			'rest-application-passwords.introspection-current-user',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function install_scoped_filters(): void {
		\add_filter( 'get_user_metadata', array( __CLASS__, 'filter_get_user_metadata' ), 10, 5 );
		\add_filter( 'update_user_metadata', array( __CLASS__, 'filter_update_user_metadata' ), 10, 5 );
		\add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'filter_application_passwords_available' ) );
		\add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_application_passwords_available_for_user' ), 10, 2 );
		\add_filter( 'map_meta_cap', array( __CLASS__, 'filter_map_meta_cap' ), 10, 4 );
		\add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 10, 4 );
	}

	private static function reset_runtime_state(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/rest-application-passwords';
		$_SERVER['HTTPS']           = 'on';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.42';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-json/wp/v2/users/me/application-passwords';
		$_SERVER['SERVER_PORT']     = '443';

		unset( $GLOBALS['wp_rest_application_password_status'], $GLOBALS['wp_rest_application_password_uuid'] );

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function reset_static_state(): void {
		self::$application_passwords                    = array();
		self::$application_passwords_available          = true;
		self::$application_passwords_available_for_user = true;
		self::$denied_caps                             = array();
	}

	private static function fresh_server(): \WP_REST_Server {
		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		if ( function_exists( 'rest_api_default_filters' ) ) {
			\rest_api_default_filters();
		}
		return $server;
	}

	private static function dispatch( \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		$response = \rest_ensure_response( $server->dispatch( $request ) );
		if ( \is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			return new \WP_REST_Response(
				array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
					'data'    => array( 'status' => $status ),
				),
				$status
			);
		}

		return \apply_filters( 'rest_post_dispatch', $response, $server, $request );
	}

	private static function seed_users( array $case ): array {
		$primary = \wp_insert_user(
			array(
				'display_name' => $case['primaryName'],
				'user_email'   => $case['primaryEmail'],
				'user_login'   => $case['primaryLogin'],
				'user_pass'    => 'pass-' . $case['token'] . '-primary',
			)
		);
		if ( \is_wp_error( $primary ) ) {
			throw new \RuntimeException( 'Could not create primary REST app-password user: ' . $primary->get_error_code() );
		}

		$other = \wp_insert_user(
			array(
				'display_name' => $case['otherName'],
				'user_email'   => $case['otherEmail'],
				'user_login'   => $case['otherLogin'],
				'user_pass'    => 'pass-' . $case['token'] . '-other',
			)
		);
		if ( \is_wp_error( $other ) ) {
			throw new \RuntimeException( 'Could not create secondary REST app-password user: ' . $other->get_error_code() );
		}

		\wp_set_current_user( (int) $primary );
		self::$application_passwords[ (int) $primary ] = array();
		self::$application_passwords[ (int) $other ]   = array();

		return array(
			'primary' => (int) $primary,
			'other'   => (int) $other,
		);
	}

	private static function request( string $method, string $route, array $query_params = array(), array $url_params = array(), array $body_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $query_params as $key => $value ) {
			$request->set_query_params( array_merge( $request->get_query_params(), array( $key => $value ) ) );
		}
		foreach ( $url_params as $key => $value ) {
			$request->set_url_params( array_merge( $request->get_url_params(), array( $key => $value ) ) );
		}
		if ( array() !== $body_params ) {
			$request->set_body_params( $body_params );
		}
		return $request;
	}

	private static function create_password_fixture( int $user_id, string $name, string $app_id ): ?array {
		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'app_id' => $app_id,
				'name'   => $name,
			)
		);

		return is_array( $created ) && isset( $created[1] ) && is_array( $created[1] ) ? $created[1] : null;
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token            = substr( sha1( $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );
		$name_raw         = 'Component Fuzz App ' . $token . " <b>\xC3\xA9</b>\n";
		$updated_name_raw = "Updated\tComponent Fuzz App {$token}<script>x</script>";

		return array(
			'token'                => $token,
			'appId'                => self::uuid_from_token( $token . '-app' ),
			'updatedAppId'         => self::uuid_from_token( $token . '-updated-app' ),
			'nameRaw'              => $name_raw,
			'nameSanitized'        => \sanitize_text_field( $name_raw ),
			'updatedNameRaw'       => $updated_name_raw,
			'updatedNameSanitized' => \sanitize_text_field( $updated_name_raw ),
			'bulkNameA'            => 'Bulk App A ' . $token,
			'bulkNameB'            => 'Bulk App B ' . $token,
			'primaryLogin'         => 'cfz_rest_app_' . $token,
			'primaryEmail'         => 'rest-app-' . $token . '@example.test',
			'primaryName'          => 'REST App User ' . $ctx->int( 10, 999 ),
			'otherLogin'           => 'cfz_rest_app_other_' . $token,
			'otherEmail'           => 'rest-app-other-' . $token . '@example.test',
			'otherName'            => 'REST Other User ' . $ctx->int( 10, 999 ),
		);
	}

	private static function uuid_from_token( string $token ): string {
		$hex = substr( sha1( $token ), 0, 32 );
		return sprintf(
			'%s-%s-4%s-%s%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 13, 3 ),
			dechex( 8 + ( hexdec( $hex[16] ) % 4 ) ),
			substr( $hex, 17, 3 ),
			substr( $hex, 20, 12 )
		);
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

	private static function schema_has_properties( array $schema, array $properties ): bool {
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return false;
		}
		foreach ( $properties as $property ) {
			if ( ! array_key_exists( $property, $schema['properties'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function route_data_has_endpoint_args( ?array $data, array $methods, array $args ): bool {
		$endpoint = self::route_data_endpoint_for_methods( $data, $methods );
		if ( null === $endpoint || ! isset( $endpoint['args'] ) || ! is_array( $endpoint['args'] ) ) {
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
		if ( null === $data || ! isset( $data['schema']['properties'] ) || ! is_array( $data['schema']['properties'] ) ) {
			return false;
		}
		foreach ( $properties as $property ) {
			if ( ! array_key_exists( $property, $data['schema']['properties'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function route_data_schema_contexts_match( ?array $data, string $property, array $contexts ): bool {
		if ( null === $data || ! isset( $data['schema']['properties'][ $property ] ) ) {
			return false;
		}

		$actual_contexts = $data['schema']['properties'][ $property ]['context'] ?? null;
		if ( ! is_array( $actual_contexts ) ) {
			return false;
		}

		sort( $actual_contexts );
		sort( $contexts );
		return $contexts === $actual_contexts;
	}

	private static function route_data_endpoint_for_methods( ?array $data, array $methods ): ?array {
		if ( null === $data || ! isset( $data['endpoints'] ) || ! is_array( $data['endpoints'] ) ) {
			return null;
		}

		sort( $methods );
		foreach ( $data['endpoints'] as $endpoint ) {
			if ( ! isset( $endpoint['methods'] ) || ! is_array( $endpoint['methods'] ) ) {
				continue;
			}
			$endpoint_methods = array_values( array_unique( array_map( 'strval', $endpoint['methods'] ) ) );
			sort( $endpoint_methods );
			if ( $methods === $endpoint_methods ) {
				return $endpoint;
			}
		}
		return null;
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
			&& $status === (int) ( $data['data']['status'] ?? 0 );
	}

	private static function sorted_keys( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$keys = array_keys( $value );
		sort( $keys );
		return $keys;
	}

	private static function link_href( array $links, string $rel ): ?string {
		return isset( $links[ $rel ][0]['href'] ) ? (string) $links[ $rel ][0]['href'] : null;
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

	private static function case_summary( array $case ): array {
		return array(
			'token'        => $case['token'],
			'appId'        => $case['appId'],
			'primaryLogin' => $case['primaryLogin'],
		);
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'authordata',
					'current_user',
					'user_ID',
					'userdata',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_rest_application_password_status',
					'wp_rest_application_password_uuid',
					'wp_rest_server',
				)
			),
			'request' => array(
				'_GET'     => $_GET,
				'_POST'    => $_POST,
				'_REQUEST' => $_REQUEST,
				'_SERVER'  => $_SERVER,
			),
			'wpdb'    => self::snapshot_wpdb(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_wpdb( $snapshot['wpdb'] );
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		self::restore_globals( $snapshot['globals'] );
		$_GET     = $snapshot['request']['_GET'];
		$_POST    = $snapshot['request']['_POST'];
		$_REQUEST = $snapshot['request']['_REQUEST'];
		$_SERVER  = $snapshot['request']['_SERVER'];
	}

	private static function state_fingerprint(): array {
		return array(
			'globals' => self::stable_hash(
				self::summarize_for_hash(
					self::snapshot_globals(
						array(
							'current_user',
							'user_ID',
							'wp_filter',
							'wp_rest_application_password_status',
							'wp_rest_application_password_uuid',
							'wp_rest_server',
						)
					)
				)
			),
			'request' => self::stable_hash(
				self::summarize_for_hash(
					array(
						'_GET'     => $_GET,
						'_POST'    => $_POST,
						'_REQUEST' => $_REQUEST,
						'_SERVER'  => $_SERVER,
					)
				)
			),
			'wpdb'    => self::stable_hash( self::summarize_for_hash( self::snapshot_wpdb() ) ),
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
			if ( $value instanceof \Closure ) {
				return array( 'Closure' => true );
			}
			return array( 'object' => get_class( $value ) );
		}

		return $value;
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

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > self::SAMPLE_BYTES ? substr( self::escape_string( $value ), 0, self::SAMPLE_BYTES ) . '...' : self::escape_string( $value );
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
				if ( $i >= 16 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_string( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
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
					'type' => 'WP_Error',
					'code' => $value->get_error_code(),
					'data' => self::describe_value( $value->get_error_data(), $depth + 1 ),
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
			);
		}

		return $value;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_string( $e->getMessage() ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function escape_string( string $value ): string {
		return (string) preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/',
			static function ( array $matches ): string {
				return sprintf( '\\x%02X', ord( $matches[0] ) );
			},
			$value
		);
	}
}
