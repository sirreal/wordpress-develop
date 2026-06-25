<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes the no-DB Icons and Connectors APIs.
 */
final class IconsConnectorsSurface {
	public const NAME = 'icons-connectors';

	private const CONNECTOR_CASES = 6;
	private const ICON_CASES      = 4;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_rest_icons_controller();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'icons-connectors.bootstrap-apis-available',
					'Required icons/connectors APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();

		try {
			return array(
				self::check_connector_registry_lifecycle( $ctx->fork( 'connector-registry' ) ),
				self::check_connector_init_settings_and_serialization( $ctx->fork( 'connector-init-settings' ) ),
				self::check_connector_rest_ai_key_validation( $ctx->fork( 'connector-rest-ai-validation' ) ),
				self::check_connector_masking_and_file_mod_flags( $ctx->fork( 'connector-mask-filemods' ) ),
				self::check_icon_registry_lifecycle( $ctx->fork( 'icon-registry' ) ),
				self::check_rest_icons_controller( $ctx->fork( 'rest-icons' ) ),
			);
		} catch ( \Throwable $e ) {
			return array(
				$ctx->fail(
					'icons-connectors.surface-no-throw',
					array( 'throwable' => self::describe_throwable( $e ) )
				),
			);
		} finally {
			self::restore_state( $snapshot );
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Connector_Registry',
				'WP_Icons_Registry',
				'WP_Error',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Server',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_connectors_get_api_key_source',
				'_wp_connectors_get_connector_script_module_data',
				'_wp_connectors_init',
				'_wp_connectors_is_ai_api_key_valid',
				'_wp_connectors_mask_api_key',
				'_wp_connectors_resolve_ai_provider_logo_url',
				'_wp_connectors_rest_settings_dispatch',
				'_wp_register_default_connector_settings',
				'add_action',
				'add_filter',
				'current_user_can',
				'get_option',
				'get_registered_settings',
				'has_filter',
				'is_wp_error',
				'register_setting',
				'remove_action',
				'remove_filter',
				'rest_ensure_response',
				'update_option',
				'wp_get_connector',
				'wp_get_connectors',
				'wp_is_connector_registered',
				'wp_is_file_mod_allowed',
				'wp_json_encode',
				'wp_kses',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_connector_registry_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::set_connector_registry( null );
		self::collect_failure(
			$failures,
			array() === \wp_get_connectors()
				&& null === \wp_get_connector( 'missing-connector' )
				&& false === \wp_is_connector_registered( 'missing-connector' ),
			'public connector helpers return empty values before singleton initialization',
			array(
				'connectors'   => \wp_get_connectors(),
				'connector'    => \wp_get_connector( 'missing-connector' ),
				'isRegistered' => \wp_is_connector_registered( 'missing-connector' ),
			)
		);

		$registry = new \WP_Connector_Registry();
		self::set_connector_registry( $registry );
		$cases      = self::connector_cases( $ctx->fork( 'cases' ) );
		$registered = array();

		foreach ( $cases as $index => $case ) {
			$registered[ $case['id'] ] = $registry->register( $case['id'], $case['args'] );
			$stored                    = $registered[ $case['id'] ];
			$plugin                    = is_array( $stored ) ? $stored['plugin'] : array();
			$is_active                 = isset( $plugin['is_active'] ) && is_callable( $plugin['is_active'] )
				? (bool) call_user_func( $plugin['is_active'] )
				: null;

			self::collect_failure(
				$failures,
				is_array( $stored )
					&& $stored === $registry->get_registered( $case['id'] )
					&& $stored === \wp_get_connector( $case['id'] )
					&& isset( \wp_get_connectors()[ $case['id'] ] )
					&& true === $registry->is_registered( $case['id'] )
					&& true === \wp_is_connector_registered( $case['id'] )
					&& $case['args']['name'] === $stored['name']
					&& $case['expectedDescription'] === $stored['description']
					&& $case['args']['type'] === $stored['type']
					&& $case['expectedAuth'] === $stored['authentication']
					&& $case['expectedLogoUrl'] === ( $stored['logo_url'] ?? null )
					&& $case['expectedPluginFile'] === ( $plugin['file'] ?? null )
					&& $case['expectedActive'] === $is_active,
				"connector registration stores normalized data case {$index}",
				array(
					'case'   => $case,
					'stored' => self::describe_value( $stored ),
				)
			);
		}

		self::collect_failure(
			$failures,
			array_keys( $registered ) === array_keys( \wp_get_connectors() )
				&& $registered === \wp_get_connectors(),
			'connector aggregate preserves insertion order and exact normalized entries',
			array(
				'expectedKeys' => array_keys( $registered ),
				'actualKeys'   => array_keys( \wp_get_connectors() ),
			)
		);

		$before_invalid = $registry->get_all_registered();
		$invalids       = array(
			'duplicate'       => self::capture_doing_it_wrong(
				static fn() => $registry->register( $cases[0]['id'], $cases[0]['args'] )
			),
			'uppercaseId'     => self::capture_doing_it_wrong(
				static fn() => $registry->register( 'Bad_ID', $cases[0]['args'] )
			),
			'slashId'         => self::capture_doing_it_wrong(
				static fn() => $registry->register( 'bad/id', $cases[0]['args'] )
			),
			'missingName'     => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'missing-name' ), 'missing-name' ),
					array(
						'type'           => 'search',
						'authentication' => array( 'method' => 'none' ),
					)
				)
			),
			'missingType'     => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'missing-type' ), 'missing-type' ),
					array(
						'name'           => 'Missing Type',
						'authentication' => array( 'method' => 'none' ),
					)
				)
			),
			'missingAuth'     => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'missing-auth' ), 'missing-auth' ),
					array(
						'name' => 'Missing Auth',
						'type' => 'search',
					)
				)
			),
			'invalidAuth'     => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'invalid-auth' ), 'invalid-auth' ),
					array(
						'name'           => 'Invalid Auth',
						'type'           => 'search',
						'authentication' => array( 'method' => 'oauth' ),
					)
				)
			),
			'emptySetting'    => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'empty-setting' ), 'empty-setting' ),
					array(
						'name'           => 'Empty Setting',
						'type'           => 'search',
						'authentication' => array(
							'method'       => 'api_key',
							'setting_name' => '',
						),
					)
				)
			),
			'emptyConstant'   => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'empty-constant' ), 'empty-constant' ),
					array(
						'name'           => 'Empty Constant',
						'type'           => 'search',
						'authentication' => array(
							'method'        => 'api_key',
							'constant_name' => '',
						),
					)
				)
			),
			'emptyEnv'        => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'empty-env' ), 'empty-env' ),
					array(
						'name'           => 'Empty Env',
						'type'           => 'search',
						'authentication' => array(
							'method'       => 'api_key',
							'env_var_name' => '',
						),
					)
				)
			),
			'invalidCallback' => self::capture_doing_it_wrong(
				static fn() => $registry->register(
					self::connector_id( $ctx->fork( 'invalid-plugin' ), 'invalid-plugin' ),
					array(
						'name'           => 'Invalid Plugin',
						'type'           => 'search',
						'authentication' => array( 'method' => 'none' ),
						'plugin'         => array( 'is_active' => 'component_fuzz_missing_callback' ),
					)
				)
			),
		);

		$all_invalid_failed = true;
		foreach ( $invalids as $invalid ) {
			$all_invalid_failed = $all_invalid_failed
				&& null === $invalid['value']
				&& self::has_warning( $invalid );
		}

		self::collect_failure(
			$failures,
			$all_invalid_failed && $before_invalid === $registry->get_all_registered(),
			'invalid and duplicate connector registrations warn and leave registry unchanged',
			array( 'invalids' => $invalids )
		);

		$before_ai = $registry->get_all_registered();
		$ai_filter = static fn(): bool => false;
		\add_filter( 'wp_supports_ai', $ai_filter );
		try {
			$ai_disabled = $registry->register(
				self::connector_id( $ctx->fork( 'ai-disabled' ), 'ai-disabled' ),
				array(
					'name'           => 'AI Disabled',
					'type'           => 'ai_provider',
					'authentication' => array( 'method' => 'none' ),
				)
			);
		} finally {
			\remove_filter( 'wp_supports_ai', $ai_filter );
		}

		self::collect_failure(
			$failures,
			null === $ai_disabled && $before_ai === $registry->get_all_registered(),
			'ai_provider connectors fail closed without mutation when AI support is disabled',
			array( 'aiDisabled' => self::describe_value( $ai_disabled ) )
		);

		$removed  = $registry->unregister( $cases[0]['id'] );
		$override = is_array( $removed ) ? $removed : array();
		if ( array() !== $override ) {
			$override['description'] = 'Overridden ' . substr( dechex( $ctx->seed() ), -6 );
		}
		$re_registered = $registry->register( $cases[0]['id'], $override );
		$missing_get   = self::capture_doing_it_wrong(
			static fn() => $registry->get_registered( self::connector_id( $ctx->fork( 'missing-get' ), 'missing-get' ) )
		);
		$missing_drop  = self::capture_doing_it_wrong(
			static fn() => $registry->unregister( self::connector_id( $ctx->fork( 'missing-drop' ), 'missing-drop' ) )
		);

		self::collect_failure(
			$failures,
			$removed === $registered[ $cases[0]['id'] ]
				&& is_array( $re_registered )
				&& $override['description'] === $registry->get_registered( $cases[0]['id'] )['description']
				&& null === $missing_get['value']
				&& null === $missing_drop['value']
				&& self::has_warning( $missing_get )
				&& self::has_warning( $missing_drop ),
			'unregister returns exact connector data and supports explicit override workflows',
			array(
				'removed'      => self::describe_value( $removed ),
				'reRegistered' => self::describe_value( $re_registered ),
				'missingGet'   => $missing_get,
				'missingDrop'  => $missing_drop,
			)
		);

		self::set_connector_registry( null );
		self::collect_failure(
			$failures,
			array() === \wp_get_connectors()
				&& null === \wp_get_connector( $cases[1]['id'] )
				&& false === \wp_is_connector_registered( $cases[1]['id'] ),
			'public connector helpers tolerate a missing singleton after mutation',
			array(
				'id'           => $cases[1]['id'],
				'connectors'   => \wp_get_connectors(),
				'connector'    => \wp_get_connector( $cases[1]['id'] ),
				'isRegistered' => \wp_is_connector_registered( $cases[1]['id'] ),
			)
		);

		return self::result(
			$ctx,
			'icons-connectors.connectors.registry-lifecycle-validation',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_connector_init_settings_and_serialization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::set_connector_registry( null );
		$outside_registry = new \WP_Connector_Registry();
		$outside_guard    = self::capture_doing_it_wrong(
			static fn() => \WP_Connector_Registry::set_instance( $outside_registry )
		);

		self::collect_failure(
			$failures,
			null === \WP_Connector_Registry::get_instance()
				&& null === $outside_guard['value']
				&& self::has_warning( $outside_guard ),
			'set_instance guard rejects calls outside init',
			array( 'outsideGuard' => $outside_guard )
		);

		$custom_id          = self::connector_id( $ctx->fork( 'hooked' ), 'hooked' );
		$override_text      = 'Fuzz override ' . substr( dechex( $ctx->seed() ), -6 );
		$hook_seen          = 0;
		$hook_registry_seen = null;
		$hook               = static function ( \WP_Connector_Registry $registry ) use ( &$hook_seen, &$hook_registry_seen, $custom_id, $override_text ): void {
			++$hook_seen;
			$hook_registry_seen = $registry;
			$registry->register(
				$custom_id,
				array(
					'name'           => 'Hooked Connector',
					'description'    => 'Registered from wp_connectors_init.',
					'type'           => 'hooked_service',
					'authentication' => array( 'method' => 'none' ),
				)
			);

			if ( $registry->is_registered( 'akismet' ) ) {
				$akismet                = $registry->unregister( 'akismet' );
				$akismet['description'] = $override_text;
				$registry->register( 'akismet', $akismet );
			}
		};
		$disable_ai         = static fn(): bool => false;

		\add_filter( 'wp_supports_ai', $disable_ai );
		\add_action( 'wp_connectors_init', $hook );
		try {
			self::with_doing_action(
				'init',
				static function (): void {
					\_wp_connectors_init();
				}
			);
		} finally {
			\remove_action( 'wp_connectors_init', $hook );
			\remove_filter( 'wp_supports_ai', $disable_ai );
		}

		$instance   = \WP_Connector_Registry::get_instance();
		$connectors = \wp_get_connectors();
		self::collect_failure(
			$failures,
			$instance instanceof \WP_Connector_Registry
				&& $hook_registry_seen === $instance
				&& 1 === $hook_seen
				&& isset( $connectors['akismet'], $connectors[ $custom_id ] )
				&& $override_text === $connectors['akismet']['description']
				&& ! isset( $connectors['anthropic'], $connectors['google'], $connectors['openai'] )
				&& true === \wp_is_connector_registered( $custom_id ),
			'connector initializer registers defaults, fires discovery hook, and honors AI support filter',
			array(
				'hookSeen'     => $hook_seen,
				'connectorIds' => array_keys( $connectors ),
				'customId'     => $custom_id,
			)
		);

		$plugin_logo = self::write_temp_file( WP_PLUGIN_DIR . '/cf-icons-connectors/logo-' . substr( dechex( $ctx->seed() ), -5 ) . '.svg', '<svg></svg>' );
		$mu_logo     = self::write_temp_file( WPMU_PLUGIN_DIR . '/cf-icons-connectors/logo-' . substr( dechex( $ctx->seed() ), -5 ) . '.svg', '<svg></svg>' );
		$outside     = self::write_temp_file( sys_get_temp_dir() . '/cf-icons-connectors-outside-' . getmypid() . '-' . substr( dechex( $ctx->seed() ), -5 ) . '.svg', '<svg></svg>' );
		try {
			$plugin_url      = \_wp_connectors_resolve_ai_provider_logo_url( $plugin_logo );
			$mu_url          = \_wp_connectors_resolve_ai_provider_logo_url( $mu_logo );
			$missing_url     = \_wp_connectors_resolve_ai_provider_logo_url( $outside . '.missing' );
			$outside_warning = self::capture_doing_it_wrong(
				static fn() => \_wp_connectors_resolve_ai_provider_logo_url( $outside )
			);

			self::collect_failure(
				$failures,
				is_string( $plugin_url )
					&& str_contains( $plugin_url, '/plugins/cf-icons-connectors/' )
					&& is_string( $mu_url )
					&& str_contains( $mu_url, '/mu-plugins/cf-icons-connectors/' )
					&& null === $missing_url
					&& null === $outside_warning['value']
					&& self::has_warning( $outside_warning ),
				'connector logo path resolver accepts plugin roots and rejects outside paths',
				array(
					'pluginUrl'      => $plugin_url,
					'muUrl'          => $mu_url,
					'missingUrl'     => $missing_url,
					'outsideWarning' => $outside_warning,
				)
			);
		} finally {
			foreach ( array( $plugin_logo, $mu_logo, $outside ) as $path ) {
				if ( is_string( $path ) && file_exists( $path ) ) {
					unlink( $path );
				}
			}
		}

		$registry = new \WP_Connector_Registry();
		self::set_connector_registry( $registry );

		$active_id        = self::connector_id( $ctx->fork( 'active' ), 'active' );
		$inactive_id      = self::connector_id( $ctx->fork( 'inactive' ), 'inactive' );
		$none_id          = self::connector_id( $ctx->fork( 'none' ), 'none' );
		$auto_id          = self::connector_id( $ctx->fork( 'auto' ), 'auto' );
		$active_setting   = 'cfuzz_active_' . str_replace( '-', '_', $active_id ) . '_api_key';
		$inactive_setting = 'cfuzz_inactive_' . str_replace( '-', '_', $inactive_id ) . '_api_key';
		$auto_setting     = str_replace( '-', '_', "connectors_service-extra_{$auto_id}_api_key" );
		$env_name         = 'CFUZZ_CONNECTOR_' . strtoupper( substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
		$secret           = 'sk-' . strtolower( $ctx->identifier( 4, 9 ) ) . '-' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 12 );

		$registry->register(
			$inactive_id,
			array(
				'name'           => 'Inactive Connector',
				'type'           => 'service',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => $inactive_setting,
				),
				'plugin'         => array(
					'file'      => 'inactive/inactive.php',
					'is_active' => static fn(): bool => false,
				),
			)
		);
		$registry->register(
			$active_id,
			array(
				'name'           => 'Active Connector',
				'type'           => 'service',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => $active_setting,
					'env_var_name' => $env_name,
				),
				'plugin'         => array(
					'file'      => 'active/active.php',
					'is_active' => static fn(): bool => true,
				),
			)
		);
		$registry->register(
			$none_id,
			array(
				'name'           => 'No Auth Connector',
				'type'           => 'service',
				'authentication' => array( 'method' => 'none' ),
			)
		);
		$registry->register(
			$auto_id,
			array(
				'name'           => 'Auto Setting Connector',
				'type'           => 'service-extra',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://example.test/keys/' . rawurlencode( $auto_id ),
				),
			)
		);

		\_wp_register_default_connector_settings();
		$registered_settings = \get_registered_settings();
		self::collect_failure(
			$failures,
			isset( $registered_settings[ $active_setting ], $registered_settings[ $auto_setting ] )
				&& ! isset( $registered_settings[ $inactive_setting ] )
				&& 'string' === ( $registered_settings[ $active_setting ]['type'] ?? null )
				&& true === ( $registered_settings[ $active_setting ]['show_in_rest'] ?? null )
				&& 'sanitize_text_field' === ( $registered_settings[ $active_setting ]['sanitize_callback'] ?? null ),
			'default connector settings register only active API-key connectors and normalized auto settings',
			array(
				'activeSetting'   => $registered_settings[ $active_setting ] ?? null,
				'autoSetting'     => $registered_settings[ $auto_setting ] ?? null,
				'inactiveSetting' => $registered_settings[ $inactive_setting ] ?? null,
			)
		);

		$old_env   = getenv( $env_name );
		$db_filter = static function () use ( $secret ): string {
			return $secret . '-db';
		};
		try {
			putenv( $env_name );
			$none_source = \_wp_connectors_get_api_key_source( $active_setting, '', '' );
			\add_filter( "pre_option_{$active_setting}", $db_filter );
			$db_source       = \_wp_connectors_get_api_key_source( $active_setting, '', '' );
			$constant_source = \_wp_connectors_get_api_key_source( $active_setting, '', 'AUTH_KEY' );
			putenv( $env_name . '=' . $secret . '-env' );
			$env_source = \_wp_connectors_get_api_key_source( $active_setting, $env_name, 'AUTH_KEY' );
			\remove_filter( "pre_option_{$active_setting}", $db_filter );
			$env_without_db = \_wp_connectors_get_api_key_source( $active_setting, $env_name, '' );

			self::collect_failure(
				$failures,
				'none' === $none_source
					&& 'database' === $db_source
					&& 'constant' === $constant_source
					&& 'env' === $env_source
					&& 'env' === $env_without_db,
				'API key source precedence is env, constant, database, then none',
				array(
					'none'         => $none_source,
					'database'     => $db_source,
					'constant'     => $constant_source,
					'env'          => $env_source,
					'envWithoutDb' => $env_without_db,
				)
			);

			$server      = new \WP_REST_Server();
			$response    = new \WP_REST_Response(
				array(
					$active_setting   => $secret,
					$inactive_setting => substr( $secret, -4 ),
					$auto_setting     => '',
					'unrelated'       => $secret,
				)
			);
			$masked_data = \_wp_connectors_rest_settings_dispatch( $response, $server, self::request( 'GET', '/wp/v2/settings' ) )->get_data();
			$post_data   = \_wp_connectors_rest_settings_dispatch(
				new \WP_REST_Response( array( $active_setting => $secret ) ),
				$server,
				self::request( 'POST', '/wp/v2/settings' )
			)->get_data();
			$wrong_data  = \_wp_connectors_rest_settings_dispatch(
				new \WP_REST_Response( array( $active_setting => $secret ) ),
				$server,
				self::request( 'GET', '/wp/v2/posts' )
			)->get_data();

			self::collect_failure(
				$failures,
				\_wp_connectors_mask_api_key( $secret ) === ( $masked_data[ $active_setting ] ?? null )
					&& substr( $secret, -4 ) === ( $masked_data[ $inactive_setting ] ?? null )
					&& '' === ( $masked_data[ $auto_setting ] ?? null )
					&& $secret === ( $masked_data['unrelated'] ?? null )
					&& \_wp_connectors_mask_api_key( $secret ) === ( $post_data[ $active_setting ] ?? null )
					&& $secret === ( $wrong_data[ $active_setting ] ?? null ),
				'REST settings dispatch masks connector keys only on settings responses',
				array(
					'maskedData' => $masked_data,
					'postData'   => $post_data,
					'wrongData'  => $wrong_data,
				)
			);

			$module_data  = \_wp_connectors_get_connector_script_module_data( array( 'existing' => 'kept' ) );
			$connectors   = $module_data['connectors'] ?? array();
			$module_json  = \wp_json_encode( $module_data );
			$sorted_ids   = array_keys( $connectors );
			$expected_ids = $sorted_ids;
			sort( $expected_ids, SORT_STRING );

			self::collect_failure(
				$failures,
				'kept' === ( $module_data['existing'] ?? null )
					&& is_bool( $module_data['isFileModDisabled'] ?? null )
					&& $expected_ids === $sorted_ids
					&& 'env' === ( $connectors[ $active_id ]['authentication']['keySource'] ?? null )
					&& true === ( $connectors[ $active_id ]['authentication']['isConnected'] ?? null )
					&& true === ( $connectors[ $active_id ]['plugin']['isActivated'] ?? null )
					&& false === ( $connectors[ $inactive_id ]['plugin']['isActivated'] ?? null )
					&& 'none' === ( $connectors[ $inactive_id ]['authentication']['keySource'] ?? null )
					&& 'none' === ( $connectors[ $none_id ]['authentication']['method'] ?? null )
					&& ! str_contains( (string) $module_json, $secret ),
				'connector script module data is sorted, camel-cased, status-normalized, and key-safe',
				array(
					'ids'        => $sorted_ids,
					'active'     => $connectors[ $active_id ] ?? null,
					'inactive'   => $connectors[ $inactive_id ] ?? null,
					'moduleJson' => $module_json,
				)
			);
		} finally {
			\remove_filter( "pre_option_{$active_setting}", $db_filter );
			if ( false === $old_env ) {
				putenv( $env_name );
			} else {
				putenv( $env_name . '=' . $old_env );
			}
		}

		return self::result(
			$ctx,
			'icons-connectors.connectors.init-settings-rest-module-data',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_connector_rest_ai_key_validation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$registry = new \WP_Connector_Registry();
		self::set_connector_registry( $registry );

		$token           = substr( hash( 'sha256', (string) $ctx->seed() . ':' . (string) $ctx->iteration() ), 0, 10 );
		$ai_id           = 'cfuzz-ai-' . $token;
		$service_id      = 'cfuzz-service-' . $token;
		$ai_setting      = 'cfuzz_ai_' . str_replace( '-', '_', $token ) . '_api_key';
		$service_setting = 'cfuzz_service_' . str_replace( '-', '_', $token ) . '_api_key';
		$secret          = 'sk-' . strtolower( $ctx->identifier( 5, 12 ) ) . '-' . substr( hash( 'sha256', (string) $ctx->seed() ), 0, 20 );
		$masked_secret   = \_wp_connectors_mask_api_key( $secret );
		$server          = new \WP_REST_Server();
		$enable_ai       = static fn(): bool => true;

		\add_filter( 'wp_supports_ai', $enable_ai );
		try {
			$registered_ai = $registry->register(
				$ai_id,
				array(
					'name'           => 'Fuzz AI Provider',
					'type'           => 'ai_provider',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => $ai_setting,
					),
				)
			);
		} finally {
			\remove_filter( 'wp_supports_ai', $enable_ai );
		}

		$registered_service = $registry->register(
			$service_id,
			array(
				'name'           => 'Fuzz Service Connector',
				'type'           => 'service',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => $service_setting,
				),
			)
		);

		\update_option( $ai_setting, $secret );
		\update_option( $service_setting, $secret );

		$get_response = \_wp_connectors_rest_settings_dispatch(
			new \WP_REST_Response(
				array(
					$ai_setting      => $secret,
					$service_setting => $secret,
				)
			),
			$server,
			self::request( 'GET', '/wp/v2/settings' )
		);
		$get_data     = $get_response instanceof \WP_REST_Response ? $get_response->get_data() : array();
		$after_get    = \get_option( $ai_setting, '__missing__' );

		$post_capture  = self::capture_doing_it_wrong(
			static fn() => \_wp_connectors_rest_settings_dispatch(
				new \WP_REST_Response(
					array(
						$ai_setting      => $secret,
						$service_setting => $secret,
					)
				),
				$server,
				self::request( 'POST', '/wp/v2/settings' )
			)
		);
		$post_response = $post_capture['value'];
		$post_data     = $post_response instanceof \WP_REST_Response ? $post_response->get_data() : array();
		$after_post    = \get_option( $ai_setting, '__missing__' );

		\update_option( $ai_setting, $secret );
		$put_capture  = self::capture_doing_it_wrong(
			static fn() => \_wp_connectors_rest_settings_dispatch(
				new \WP_REST_Response( array( $ai_setting => $masked_secret ) ),
				$server,
				self::request( 'PUT', '/wp/v2/settings' )
			)
		);
		$put_response = $put_capture['value'];
		$put_data     = $put_response instanceof \WP_REST_Response ? $put_response->get_data() : array();
		$after_put    = \get_option( $ai_setting, '__missing__' );

		$serialized_updates = \wp_json_encode(
			array(
				'get'  => $get_data,
				'post' => $post_data,
				'put'  => $put_data,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $registered_ai )
				&& is_array( $registered_service )
				&& $masked_secret === ( $get_data[ $ai_setting ] ?? null )
				&& $masked_secret === ( $get_data[ $service_setting ] ?? null )
				&& $secret === $after_get
				&& $post_response instanceof \WP_REST_Response
				&& '' === ( $post_data[ $ai_setting ] ?? null )
				&& $masked_secret === ( $post_data[ $service_setting ] ?? null )
				&& '' === $after_post
				&& self::has_warning( $post_capture )
				&& $put_response instanceof \WP_REST_Response
				&& '' === ( $put_data[ $ai_setting ] ?? null )
				&& '' === $after_put
				&& self::has_warning( $put_capture )
				&& is_string( $serialized_updates )
				&& ! str_contains( $serialized_updates, $secret ),
			'REST settings update validation masks GET responses but clears unconfigured AI provider keys on update',
			array(
				'aiId'                => $ai_id,
				'serviceId'           => $service_id,
				'secretLength'        => strlen( $secret ),
				'maskedSecret'        => $masked_secret,
				'getDataKeys'         => array_keys( $get_data ),
				'postData'            => $post_data,
				'putData'             => $put_data,
				'afterGetPreserved'   => $secret === $after_get,
				'afterPostCleared'    => '' === $after_post,
				'afterPutCleared'     => '' === $after_put,
				'postWarnings'        => $post_capture['warnings'] ?? array(),
				'putWarnings'         => $put_capture['warnings'] ?? array(),
				'serializedHasSecret' => is_string( $serialized_updates ) && str_contains( $serialized_updates, $secret ),
			)
		);

		return self::result(
			$ctx,
			'icons-connectors.connectors.rest-ai-key-validation-fail-closed',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_connector_masking_and_file_mod_flags( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$token         = strtolower( $ctx->identifier( 4, 10 ) );
		$long_secret   = 'sk-' . $token . '-' . substr( hash( 'sha256', (string) $ctx->seed() ), 0, 28 );
		$edge_secret   = substr( $token . 'abcde', 0, 5 );
		$short_secrets = array(
			'',
			substr( $token . 'a', 0, 1 ),
			substr( $token . 'abcd', 0, 4 ),
		);

		foreach ( $short_secrets as $secret ) {
			self::collect_failure(
				$failures,
				$secret === \_wp_connectors_mask_api_key( $secret ),
				'short connector API keys are not masked or expanded',
				array(
					'secretLength' => strlen( $secret ),
					'masked'       => \_wp_connectors_mask_api_key( $secret ),
				)
			);
		}

		foreach ( array( $edge_secret, $long_secret ) as $secret ) {
			$masked       = \_wp_connectors_mask_api_key( $secret );
			$bullet_count = substr_count( $masked, "\u{2022}" );
			self::collect_failure(
				$failures,
				substr( $secret, -4 ) === substr( $masked, -4 )
					&& min( strlen( $secret ) - 4, 16 ) === $bullet_count
					&& str_starts_with( $masked, "\u{2022}" )
					&& ! str_contains( $masked, $secret ),
				'connector API key masking preserves only the final four bytes and caps mask width',
				array(
					'secretLength' => strlen( $secret ),
					'masked'       => $masked,
					'bulletCount'  => $bullet_count,
					'suffix'       => substr( $secret, -4 ),
				)
			);
		}

		$contexts        = array();
		$file_mod_filter = static function ( bool $allowed, string $context ) use ( &$contexts ): bool {
			$contexts[] = $context;
			return 'install_plugins' === $context ? false : $allowed;
		};

		\add_filter( 'file_mod_allowed', $file_mod_filter, 10, 2 );
		try {
			$disabled_data = \_wp_connectors_get_connector_script_module_data( array() );
		} finally {
			\remove_filter( 'file_mod_allowed', $file_mod_filter, 10 );
		}
		$default_data = \_wp_connectors_get_connector_script_module_data( array() );

		self::collect_failure(
			$failures,
			array( 'install_plugins' ) === $contexts
				&& true === ( $disabled_data['isFileModDisabled'] ?? null )
				&& false === ( $default_data['isFileModDisabled'] ?? null )
				&& false === \has_filter( 'file_mod_allowed', $file_mod_filter ),
			'connector module data reports file modification policy from the install_plugins context',
			array(
				'contexts'     => $contexts,
				'disabledData' => $disabled_data,
				'defaultData'  => $default_data,
				'hasFilter'    => \has_filter( 'file_mod_allowed', $file_mod_filter ),
			)
		);

		return self::result(
			$ctx,
			'icons-connectors.connectors.masking-file-mod-policy',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_icon_registry_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$temp_files = array();

		self::set_static_property( 'WP_Icons_Registry', 'instance', null );
		$registry = \WP_Icons_Registry::get_instance();
		$register = self::method( 'WP_Icons_Registry', 'register' );
		$sanitize = self::method( 'WP_Icons_Registry', 'sanitize_icon_content' );

		try {
			$all_icons  = $registry->get_registered_icons();
			$first_icon = $all_icons[0] ?? null;
			$first_name = is_array( $first_icon ) ? (string) ( $first_icon['name'] ?? '' ) : '';
			$search     = str_contains( $first_name, '/' ) ? substr( $first_name, strpos( $first_name, '/' ) + 1, 4 ) : 'core';
			$matches    = $registry->get_registered_icons( strtoupper( $search ) );

			self::collect_failure(
				$failures,
				0 < count( $all_icons )
					&& is_array( $first_icon )
					&& '' !== $first_name
					&& true === $registry->is_registered( $first_name )
					&& $first_name === ( $registry->get_registered_icon( $first_name )['name'] ?? null )
					&& 0 < count( $matches )
					&& self::icons_all_match_search( $matches, strtoupper( $search ) )
					&& array() === $registry->get_registered_icons( 'cfuzz-no-such-icon-' . substr( dechex( $ctx->seed() ), -6 ) ),
				'core icon manifest loads icons and search filters case-insensitively by icon name',
				array(
					'coreCount'  => count( $all_icons ),
					'firstName'  => $first_name,
					'search'     => $search,
					'matchCount' => count( $matches ),
				)
			);

			$token        = 'cfz' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
			$safe_svg     = self::safe_svg( $ctx->fork( 'safe' ) );
			$unsafe_svg   = self::unsafe_svg( $ctx->fork( 'unsafe' ) );
			$sanitized    = $sanitize->invoke( $registry, $unsafe_svg );
			$safe_clean   = $sanitize->invoke( $registry, $safe_svg );
			$content_name = 'cfzicons/' . $token . '-content';

			$content_registered = $register->invoke(
				$registry,
				$content_name,
				array(
					'label'   => 'Content Icon <script>label</script>',
					'content' => $unsafe_svg,
				)
			);
			$content_icon       = $registry->get_registered_icon( $content_name );

			self::collect_failure(
				$failures,
				true === $content_registered
					&& is_array( $content_icon )
					&& $content_name === ( $content_icon['name'] ?? null )
					&& 'Content Icon <script>label</script>' === ( $content_icon['label'] ?? null )
					&& $sanitized === ( $content_icon['content'] ?? null )
					&& '' !== $sanitized
					&& ! str_contains( strtolower( $sanitized ), '<script' )
					&& ! str_contains( strtolower( $sanitized ), 'onload' )
					&& ! str_contains( strtolower( $sanitized ), 'onclick' )
					&& ! str_contains( strtolower( $sanitized ), '<circle' )
					&& str_contains( $sanitized, '<svg' )
					&& str_contains( $sanitized, '<path' ),
				'icon content registration sanitizes SVG while preserving label metadata',
				array(
					'name'      => $content_name,
					'sanitized' => $sanitized,
					'icon'      => self::describe_value( $content_icon ),
				)
			);

			$file_path    = self::write_temp_file( sys_get_temp_dir() . '/cf-icons-' . getmypid() . '-' . $token . '.svg', $unsafe_svg );
			$temp_files[] = $file_path;
			$file_name    = 'cfzicons/' . $token . '-file';
			$file_result  = $register->invoke(
				$registry,
				$file_name,
				array(
					'label'    => 'File Icon',
					'filePath' => $file_path,
				)
			);
			$stored_before = self::get_object_property( $registry, 'registered_icons' );
			$had_content   = isset( $stored_before[ $file_name ]['content'] );
			$first_file    = $registry->get_registered_icon( $file_name );
			file_put_contents( $file_path, $safe_svg );
			$second_file = $registry->get_registered_icon( $file_name );

			self::collect_failure(
				$failures,
				true === $file_result
					&& false === $had_content
					&& is_array( $first_file )
					&& is_array( $second_file )
					&& $first_file['content'] === $second_file['content']
					&& $sanitized === $first_file['content']
					&& $safe_clean !== $second_file['content'],
				'filePath icon content is lazily sanitized and cached after first read',
				array(
					'fileName'   => $file_name,
					'hadContent' => $had_content,
					'firstFile'  => self::describe_value( $first_file ),
					'secondFile' => self::describe_value( $second_file ),
				)
			);

			$custom_names = array( $content_name, $file_name );
			for ( $i = 0; $i < self::ICON_CASES; $i++ ) {
				$name           = 'cfzicons/' . $token . '-case-' . $i;
				$custom_names[] = $name;
				$register->invoke(
					$registry,
					$name,
					array(
						'label'   => 'Case ' . $i,
						'content' => self::safe_svg( $ctx->fork( 'case-' . $i ) ),
					)
				);
			}
			$custom_matches = $registry->get_registered_icons( strtoupper( $token ) );
			$match_names    = array_map(
				static fn( array $icon ): string => (string) $icon['name'],
				$custom_matches
			);

			self::collect_failure(
				$failures,
				count( $custom_names ) === count( $custom_matches )
					&& array() === array_diff( $custom_names, $match_names )
					&& self::icons_all_match_search( $custom_matches, strtoupper( $token ) ),
				'icon search discovery returns all and only matching custom icons',
				array(
					'expected' => $custom_names,
					'actual'   => $match_names,
				)
			);

			$before_invalid = self::get_object_property( $registry, 'registered_icons' );
			$invalids       = array(
				'uppercaseName'    => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'Cfzicons/upper',
						array(
							'label'   => 'Upper',
							'content' => $safe_svg,
						)
					)
				),
				'noNamespace'      => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'nonamespace',
						array(
							'label'   => 'No Namespace',
							'content' => $safe_svg,
						)
					)
				),
				'badUnderscore'    => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'cfzicons/bad_icon',
						array(
							'label'   => 'Bad Underscore',
							'content' => $safe_svg,
						)
					)
				),
				'duplicate'        => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						$content_name,
						array(
							'label'   => 'Duplicate',
							'content' => $safe_svg,
						)
					)
				),
				'invalidProperty'  => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'cfzicons/' . $token . '-bad-property',
						array(
							'label'   => 'Bad Property',
							'content' => $safe_svg,
							'width'   => 24,
						)
					)
				),
				'missingLabel'     => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'cfzicons/' . $token . '-missing-label',
						array( 'content' => $safe_svg )
					)
				),
				'nonStringContent' => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'cfzicons/' . $token . '-non-string',
						array(
							'label'   => 'Non String',
							'content' => array( 'svg' ),
						)
					)
				),
				'emptySanitized'   => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'cfzicons/' . $token . '-empty',
						array(
							'label'   => 'Empty',
							'content' => '<circle cx="5" cy="5" r="4"></circle>',
						)
					)
				),
				'bothSources'      => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'cfzicons/' . $token . '-both',
						array(
							'label'    => 'Both',
							'content'  => $safe_svg,
							'filePath' => $file_path,
						)
					)
				),
				'noSource'         => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'cfzicons/' . $token . '-none',
						array( 'label' => 'None' )
					)
				),
			);

			$all_invalid_failed = true;
			foreach ( $invalids as $invalid ) {
				$all_invalid_failed = $all_invalid_failed
					&& false === $invalid['value']
					&& self::has_warning( $invalid );
			}

			$serialized_content = \wp_json_encode( $content_icon['content'] ?? '' );
			$decoded_content    = is_string( $serialized_content ) ? json_decode( $serialized_content, true ) : null;
			self::collect_failure(
				$failures,
				$all_invalid_failed
					&& self::get_object_property( $registry, 'registered_icons' ) === $before_invalid
					&& is_string( $serialized_content )
					&& $decoded_content === ( $content_icon['content'] ?? '' )
					&& is_string( $decoded_content )
					&& ! str_contains( strtolower( $decoded_content ), '<script' )
					&& ! str_contains( strtolower( $decoded_content ), 'onclick' )
					&& str_contains( $decoded_content, '<svg' ),
				'invalid icon registration paths warn, do not mutate, and serialized SVG content stays sanitized',
				array(
					'invalids'          => $invalids,
					'serializedContent' => $serialized_content,
				)
			);
		} finally {
			foreach ( $temp_files as $temp_file ) {
				if ( is_string( $temp_file ) && file_exists( $temp_file ) ) {
					unlink( $temp_file );
				}
			}
		}

		return self::result(
			$ctx,
			'icons-connectors.icons.registry-sanitization-search-cache-validation',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_rest_icons_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! class_exists( 'WP_REST_Icons_Controller' ) ) {
			return $ctx->skip(
				'icons-connectors.icons.rest-controller',
				'REST icons controller is not available in this checkout.'
			);
		}

		$failures = array();
		self::set_static_property( 'WP_Icons_Registry', 'instance', null );
		$registry = \WP_Icons_Registry::get_instance();
		$register = self::method( 'WP_Icons_Registry', 'register' );

		$token     = 'r' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		$icon_name = 'cfzicons/' . $token . '-rest';
		$register->invoke(
			$registry,
			$icon_name,
			array(
				'label'   => 'REST Icon ' . $token,
				'content' => self::unsafe_svg( $ctx->fork( 'rest-svg' ) ),
			)
		);

		$controller = new \WP_REST_Icons_Controller();
		$schema     = $controller->get_item_schema();
		$params     = $controller->get_collection_params();

		self::collect_failure(
			$failures,
			'icon' === ( $schema['title'] ?? null )
				&& array( 'name', 'label', 'content' ) === array_keys( $schema['properties'] ?? array() )
				&& true === ( $schema['properties']['name']['readonly'] ?? null )
				&& true === ( $schema['properties']['label']['readonly'] ?? null )
				&& true === ( $schema['properties']['content']['readonly'] ?? null )
				&& 'view' === ( $params['context']['default'] ?? null )
				&& isset( $params['search'] )
				&& 'string' === ( $params['search']['type'] ?? null ),
			'REST icons schema and collection params expose read-only icon fields and search',
			array(
				'schemaKeys' => array_keys( $schema['properties'] ?? array() ),
				'paramKeys'  => array_keys( $params ),
			)
		);

		$denied = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/icons' ) );
		$grant  = self::install_cap_filter( array( 'edit_posts' ) );
		try {
			$allowed        = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/icons' ) );
			$item_allowed   = $controller->get_item_permissions_check(
				self::request( 'GET', '/wp/v2/icons/' . $icon_name, array(), array( 'name' => $icon_name ) )
			);
			$collection     = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/icons',
					array(
						'search'  => strtoupper( $token ),
						'_fields' => 'name,label',
					)
				)
			);
			$item_response  = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/icons/' . $icon_name,
					array( '_fields' => 'name,content' ),
					array( 'name' => $icon_name )
				)
			);
			$prepared_embed = $controller->prepare_item_for_response(
				$registry->get_registered_icon( $icon_name ),
				self::request(
					'GET',
					'/wp/v2/icons/' . $icon_name,
					array(
						'context' => 'embed',
						'_fields' => 'name',
					),
					array( 'name' => $icon_name )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $grant, 10 );
		}

		$collection_data = $collection instanceof \WP_REST_Response ? $collection->get_data() : array();
		$item_data       = $item_response instanceof \WP_REST_Response ? $item_response->get_data() : array();
		$embed_data      = $prepared_embed instanceof \WP_REST_Response ? $prepared_embed->get_data() : array();
		$collection_hit  = self::find_icon_in_rest_collection( $collection_data, $icon_name );
		$missing_icon    = $controller->get_icon( 'cfzicons/' . $token . '-missing' );
		$missing_item    = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/icons/cfzicons/' . $token . '-missing',
				array(),
				array( 'name' => 'cfzicons/' . $token . '-missing' )
			)
		);

		self::collect_failure(
			$failures,
			$denied instanceof \WP_Error
				&& 'rest_cannot_view' === $denied->get_error_code()
				&& in_array( $denied->get_error_data()['status'] ?? null, array( 401, 403 ), true )
				&& true === $allowed
				&& true === $item_allowed
				&& $collection instanceof \WP_REST_Response
				&& is_array( $collection_hit )
				&& array( 'name', 'label' ) === array_keys( $collection_hit )
				&& $icon_name === ( $collection_hit['name'] ?? null )
				&& $item_response instanceof \WP_REST_Response
				&& array( 'name', 'content' ) === array_keys( $item_data )
				&& $icon_name === ( $item_data['name'] ?? null )
				&& ! str_contains( strtolower( (string) ( $item_data['content'] ?? '' ) ), '<script' )
				&& ! str_contains( strtolower( (string) ( $item_data['content'] ?? '' ) ), 'onload' )
				&& array( 'name' ) === array_keys( $embed_data )
				&& $missing_icon instanceof \WP_Error
				&& 'rest_icon_not_found' === $missing_icon->get_error_code()
				&& 404 === ( $missing_icon->get_error_data()['status'] ?? null )
				&& $missing_item instanceof \WP_Error
				&& 'rest_icon_not_found' === $missing_item->get_error_code(),
			'REST icons controller enforces permissions, field filtering, search, sanitization, and 404 contracts',
			array(
				'denied'     => self::describe_error( $denied ),
				'collection' => $collection_data,
				'item'       => $item_data,
				'embed'      => $embed_data,
				'missing'    => self::describe_error( $missing_icon ),
			)
		);

		return self::result(
			$ctx,
			'icons-connectors.icons.rest-controller-schema-permissions-errors',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function connector_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		for ( $i = 0; $i < self::CONNECTOR_CASES; $i++ ) {
			$case_ctx             = $ctx->fork( 'case-' . $i );
			$id                   = self::connector_id( $case_ctx, 'case-' . $i );
			$type                 = self::connector_slug( $case_ctx->fork( 'type' ), 0 === $i % 2 ? 'search-service' : 'media_service' );
			$method               = 0 === $i % 3 ? 'none' : 'api_key';
			$description          = $case_ctx->bool( 70 ) ? 'Description ' . $case_ctx->identifier( 4, 10 ) : '';
			$expected_logo_url    = null;
			$expected_plugin_file = null;
			$expected_active      = true;
			$args                 = array(
				'name'           => 'Connector ' . $case_ctx->identifier( 4, 10 ),
				'type'           => $type,
				'authentication' => array( 'method' => $method ),
			);

			if ( $case_ctx->bool( 80 ) ) {
				$args['description'] = $description;
			} else {
				$description = '';
			}

			if ( $case_ctx->bool( 45 ) ) {
				$expected_logo_url = 'https://example.test/logos/' . rawurlencode( $id ) . '.svg';
				$args['logo_url']  = $expected_logo_url;
			}

			$expected_auth = array( 'method' => $method );
			if ( 'api_key' === $method ) {
				if ( $case_ctx->bool( 55 ) ) {
					$args['authentication']['credentials_url'] = 'https://example.test/keys/' . rawurlencode( $id );
					$expected_auth['credentials_url']          = $args['authentication']['credentials_url'];
				}
				if ( $case_ctx->bool( 50 ) ) {
					$args['authentication']['setting_name'] = 'explicit_' . str_replace( '-', '_', $id ) . '_api_key';
				}
				$expected_auth['setting_name'] = $args['authentication']['setting_name']
					?? str_replace( '-', '_', "connectors_{$type}_{$id}_api_key" );
				if ( $case_ctx->bool( 40 ) ) {
					$args['authentication']['constant_name'] = 'CFUZZ_' . strtoupper( str_replace( '-', '_', $id ) ) . '_KEY';
					$expected_auth['constant_name']          = $args['authentication']['constant_name'];
				}
				if ( $case_ctx->bool( 40 ) ) {
					$args['authentication']['env_var_name'] = 'CFUZZ_' . strtoupper( str_replace( '-', '_', $id ) ) . '_ENV';
					$expected_auth['env_var_name']          = $args['authentication']['env_var_name'];
				}
			}

			if ( $case_ctx->bool( 65 ) ) {
				$args['plugin'] = array();
				if ( $case_ctx->bool( 75 ) ) {
					$expected_plugin_file   = $id . '/' . $id . '.php';
					$args['plugin']['file'] = $expected_plugin_file;
				}
				if ( $case_ctx->bool( 55 ) ) {
					$expected_active             = $case_ctx->bool();
					$args['plugin']['is_active'] = static fn(): bool => $expected_active;
				}
			}

			$cases[] = array(
				'id'                  => $id,
				'args'                => $args,
				'expectedActive'      => $expected_active,
				'expectedAuth'        => $expected_auth,
				'expectedDescription' => $description,
				'expectedLogoUrl'     => $expected_logo_url,
				'expectedPluginFile'  => $expected_plugin_file,
			);
		}

		return $cases;
	}

	private static function connector_id( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return self::connector_slug( $ctx, $prefix ) . '-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
	}

	private static function connector_slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$slug = strtolower( $prefix . '-' . $ctx->identifier( 3, 10 ) );
		$slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', (string) $slug );
		$slug = trim( $slug, '-_' );

		return '' === $slug ? 'cfuzz-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) : $slug;
	}

	private static function safe_svg( \ComponentFuzz\FuzzContext $ctx ): string {
		$size = $ctx->int( 16, 32 );
		$x    = $ctx->int( 0, 4 );
		$y    = $ctx->int( 0, 4 );
		$w    = $ctx->int( 8, 20 );
		$h    = $ctx->int( 8, 20 );

		return '<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 ' . $size . ' ' . $size . '" role="img" focusable="false"><path fill="currentColor" d="M' . $x . ' ' . $y . 'h' . $w . 'v' . $h . 'H' . $x . 'z"/></svg>';
	}

	private static function unsafe_svg( \ComponentFuzz\FuzzContext $ctx ): string {
		$size = $ctx->int( 18, 30 );
		$x    = $ctx->int( 1, 5 );
		$y    = $ctx->int( 1, 5 );

		return '<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 ' . $size . ' ' . $size . '" onload="alert(1)" style="color:red"><path d="M' . $x . ' ' . $y . 'h8v8H' . $x . 'z" onclick="bad()" data-x="nope"/><circle cx="5" cy="5" r="4"/><script>alert(1)</script></svg>';
	}

	private static function write_temp_file( string $path, string $contents ): string {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create temp directory: ' . $dir );
		}
		file_put_contents( $path, $contents );
		return $path;
	}

	private static function load_rest_icons_controller(): void {
		if ( class_exists( 'WP_REST_Icons_Controller' ) ) {
			return;
		}

		$path = \ComponentFuzz\repo_root() . '/src/wp-includes/rest-api/endpoints/class-wp-rest-icons-controller.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
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

	private static function with_doing_action( string $action, callable $callback ) {
		if ( ! isset( $GLOBALS['wp_current_filter'] ) || ! is_array( $GLOBALS['wp_current_filter'] ) ) {
			$GLOBALS['wp_current_filter'] = array();
		}

		$GLOBALS['wp_current_filter'][] = $action;
		try {
			return $callback();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
	}

	private static function icons_all_match_search( array $icons, string $search ): bool {
		foreach ( $icons as $icon ) {
			if ( ! is_array( $icon ) || ! isset( $icon['name'] ) || false === stripos( (string) $icon['name'], $search ) ) {
				return false;
			}
		}
		return true;
	}

	private static function find_icon_in_rest_collection( array $collection, string $name ): ?array {
		foreach ( $collection as $item ) {
			if ( is_array( $item ) && $name === ( $item['name'] ?? null ) ) {
				return $item;
			}
		}
		return null;
	}

	private static function set_connector_registry( ?\WP_Connector_Registry $registry ): void {
		self::set_static_property( 'WP_Connector_Registry', 'instance', $registry );
	}

	private static function capture_doing_it_wrong( callable $callback ): array {
		$warnings = array();
		$listener = static function ( $function_name, $message, $version ) use ( &$warnings ): void {
			$warnings[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};

		\add_action( 'doing_it_wrong_run', $listener, 10, 3 );
		try {
			$value = $callback();
			return array(
				'threw'    => false,
				'value'    => $value,
				'warnings' => $warnings,
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
				'warnings'  => $warnings,
			);
		} finally {
			\remove_action( 'doing_it_wrong_run', $listener, 10 );
		}
	}

	private static function has_warning( array $capture ): bool {
		return empty( $capture['threw'] ) && ! empty( $capture['warnings'] );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function snapshot_state(): array {
		$connector_registry = self::get_static_property( 'WP_Connector_Registry', 'instance' );
		$icons_registry     = self::get_static_property( 'WP_Icons_Registry', 'instance' );

		return array(
			'globals'              => self::snapshot_globals(
				array(
					'current_user',
					'new_allowed_options',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_registered_settings',
					'wp_registered_setting_types',
				)
			),
			'connectorRegistry'    => $connector_registry,
			'registeredConnectors' => $connector_registry instanceof \WP_Connector_Registry
				? self::get_object_property( $connector_registry, 'registered_connectors' )
				: null,
			'iconsRegistry'        => $icons_registry,
			'registeredIcons'      => $icons_registry instanceof \WP_Icons_Registry
				? self::get_object_property( $icons_registry, 'registered_icons' )
				: null,
			'wpdbOptions'          => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( $snapshot['connectorRegistry'] instanceof \WP_Connector_Registry ) {
			self::set_object_property( $snapshot['connectorRegistry'], 'registered_connectors', $snapshot['registeredConnectors'] );
			self::set_static_property( 'WP_Connector_Registry', 'instance', $snapshot['connectorRegistry'] );
		} else {
			self::set_static_property( 'WP_Connector_Registry', 'instance', null );
		}

		if ( $snapshot['iconsRegistry'] instanceof \WP_Icons_Registry ) {
			self::set_object_property( $snapshot['iconsRegistry'], 'registered_icons', $snapshot['registeredIcons'] );
			self::set_static_property( 'WP_Icons_Registry', 'instance', $snapshot['iconsRegistry'] );
		} else {
			self::set_static_property( 'WP_Icons_Registry', 'instance', null );
		}

		if ( null !== $snapshot['wpdbOptions'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['wpdbOptions'] );
		}
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
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

	private static function get_static_property( string $class_name, string $property ) {
		$reflection = new \ReflectionProperty( $class_name, $property );
		self::make_reflection_accessible( $reflection );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class_name, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $class_name, $property );
		self::make_reflection_accessible( $reflection );
		$reflection->setValue( null, $value );
	}

	private static function get_object_property( object $target, string $property ) {
		$reflection = new \ReflectionProperty( $target, $property );
		self::make_reflection_accessible( $reflection );
		return $reflection->getValue( $target );
	}

	private static function set_object_property( object $target, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $target, $property );
		self::make_reflection_accessible( $reflection );
		$reflection->setValue( $target, $value );
	}

	private static function method( string $class_name, string $method ): \ReflectionMethod {
		$reflection = new \ReflectionMethod( $class_name, $method );
		self::make_reflection_accessible( $reflection );
		return $reflection;
	}

	private static function make_reflection_accessible( $reflection ): void {
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
	}

	private static function clone_value( $value ) {
		if ( $value instanceof \Closure ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		return $value;
	}

	private static function describe_error( $error ): array {
		if ( ! $error instanceof \WP_Error ) {
			return array( 'value' => self::describe_value( $error ) );
		}

		return array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $error->get_error_data(),
		);
	}

	private static function describe_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Closure ) {
				return '[closure]';
			}
			if ( $value instanceof \WP_Error ) {
				return self::describe_error( $value );
			}
			return '[object ' . get_class( $value ) . ']';
		}

		if ( is_string( $value ) && strlen( $value ) > 240 ) {
			return substr( $value, 0, 240 ) . '...';
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
