<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB modern registry-style APIs.
 */
final class RegistriesSurface {
	public const NAME = 'registries';

	private const CONNECTOR_CASES = 5;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'registries.bootstrap-apis-available',
					'Required registry APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();

		try {
			return array(
				self::check_connector_registry_lifecycle( $ctx->fork( 'connectors-lifecycle' ) ),
				self::check_connector_helpers( $ctx->fork( 'connectors-helpers' ) ),
				self::check_icons_registry( $ctx->fork( 'icons-registry' ) ),
				self::check_speculation_helper_allowlists( $ctx->fork( 'speculation-helpers' ) ),
				self::check_speculation_rule_matrix( $ctx->fork( 'speculation-rules' ) ),
			);
		} catch ( \Throwable $e ) {
			return array(
				$ctx->fail(
					'registries.surface-no-throw',
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
				'WP_Speculation_Rules',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_connectors_get_api_key_source',
				'_wp_connectors_mask_api_key',
				'add_action',
				'add_filter',
				'remove_action',
				'remove_filter',
				'wp_get_connector',
				'wp_get_connectors',
				'wp_is_connector_registered',
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
		$registry = new \WP_Connector_Registry();
		self::set_connector_registry( $registry );

		self::collect_failure(
			$failures,
			! \wp_is_connector_registered( 'missing-connector' )
				&& null === \wp_get_connector( 'missing-connector' )
				&& array() === \wp_get_connectors(),
			'public connector helpers return empty values before registration',
			array(
				'isRegistered' => \wp_is_connector_registered( 'missing-connector' ),
				'connector'    => \wp_get_connector( 'missing-connector' ),
				'connectors'   => \wp_get_connectors(),
			)
		);

		$cases      = self::connector_cases( $ctx->fork( 'cases' ) );
		$registered = array();

		foreach ( $cases as $index => $case ) {
			$registered[ $case['id'] ] = $registry->register( $case['id'], $case['args'] );
			$looked_up                 = $registry->get_registered( $case['id'] );
			$public                    = \wp_get_connector( $case['id'] );
			$all                       = \wp_get_connectors();
			$auth                      = is_array( $registered[ $case['id'] ] ) ? $registered[ $case['id'] ]['authentication'] : array();
			$plugin                    = is_array( $registered[ $case['id'] ] ) ? $registered[ $case['id'] ]['plugin'] : array();
			$is_active                 = isset( $plugin['is_active'] ) && is_callable( $plugin['is_active'] )
				? (bool) call_user_func( $plugin['is_active'] )
				: null;

			self::collect_failure(
				$failures,
				is_array( $registered[ $case['id'] ] )
					&& $registered[ $case['id'] ] === $looked_up
					&& $registered[ $case['id'] ] === $public
					&& $registry->is_registered( $case['id'] )
					&& \wp_is_connector_registered( $case['id'] )
					&& isset( $all[ $case['id'] ] )
					&& $registered[ $case['id'] ] === $all[ $case['id'] ]
					&& $case['args']['name'] === $registered[ $case['id'] ]['name']
					&& $case['args']['type'] === $registered[ $case['id'] ]['type']
					&& $case['expectedDescription'] === $registered[ $case['id'] ]['description']
					&& $case['expectedAuth'] === $auth
					&& ( $plugin['file'] ?? null ) === $case['expectedPluginFile']
					&& $case['expectedActive'] === $is_active,
				"connector registration stores normalized connector data case {$index}",
				array(
					'case'       => $case,
					'registered' => self::describe_value( $registered[ $case['id'] ] ),
				)
			);
		}

		self::collect_failure(
			$failures,
			$registered === \wp_get_connectors(),
			'public connector aggregate returns the complete normalized registry',
			array(
				'expectedKeys' => array_keys( $registered ),
				'actualKeys'   => array_keys( \wp_get_connectors() ),
			)
		);

		$before_invalid  = $registry->get_all_registered();
		$duplicate       = self::capture_doing_it_wrong(
			static fn() => $registry->register( $cases[0]['id'], $cases[0]['args'] )
		);
		$invalid_id      = self::capture_doing_it_wrong(
			static fn() => $registry->register( 'Bad_ID', $cases[0]['args'] )
		);
		$missing_name    = self::capture_doing_it_wrong(
			static fn() => $registry->register(
				self::connector_id( $ctx->fork( 'missing-name' ), 'missing-name' ),
				array(
					'type'           => 'search',
					'authentication' => array( 'method' => 'none' ),
				)
			)
		);
		$invalid_auth    = self::capture_doing_it_wrong(
			static fn() => $registry->register(
				self::connector_id( $ctx->fork( 'invalid-auth' ), 'invalid-auth' ),
				array(
					'name'           => 'Invalid auth',
					'type'           => 'search',
					'authentication' => array( 'method' => 'token' ),
				)
			)
		);
		$invalid_setting = self::capture_doing_it_wrong(
			static fn() => $registry->register(
				self::connector_id( $ctx->fork( 'invalid-setting' ), 'invalid-setting' ),
				array(
					'name'           => 'Invalid setting',
					'type'           => 'search',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => '',
					),
				)
			)
		);
		$invalid_plugin  = self::capture_doing_it_wrong(
			static fn() => $registry->register(
				self::connector_id( $ctx->fork( 'invalid-plugin' ), 'invalid-plugin' ),
				array(
					'name'           => 'Invalid plugin',
					'type'           => 'search',
					'authentication' => array( 'method' => 'none' ),
					'plugin'         => array( 'is_active' => 'component_fuzz_missing_callback' ),
				)
			)
		);

		self::collect_failure(
			$failures,
			null === $duplicate['value']
				&& null === $invalid_id['value']
				&& null === $missing_name['value']
				&& null === $invalid_auth['value']
				&& null === $invalid_setting['value']
				&& null === $invalid_plugin['value']
				&& self::has_warning( $duplicate )
				&& self::has_warning( $invalid_id )
				&& self::has_warning( $missing_name )
				&& self::has_warning( $invalid_auth )
				&& self::has_warning( $invalid_setting )
				&& self::has_warning( $invalid_plugin )
				&& $before_invalid === $registry->get_all_registered(),
			'invalid and duplicate connector registrations warn and do not mutate registry',
			array(
				'duplicate'      => $duplicate,
				'invalidId'      => $invalid_id,
				'missingName'    => $missing_name,
				'invalidAuth'    => $invalid_auth,
				'invalidSetting' => $invalid_setting,
				'invalidPlugin'  => $invalid_plugin,
			)
		);

		$removed      = $registry->unregister( $cases[0]['id'] );
		$missing_get  = self::capture_doing_it_wrong(
			static fn() => $registry->get_registered( $cases[0]['id'] )
		);
		$missing_drop = self::capture_doing_it_wrong(
			static fn() => $registry->unregister( self::connector_id( $ctx->fork( 'missing-unregister' ), 'missing' ) )
		);

		self::collect_failure(
			$failures,
			$removed === $registered[ $cases[0]['id'] ]
				&& ! $registry->is_registered( $cases[0]['id'] )
				&& ! \wp_is_connector_registered( $cases[0]['id'] )
				&& null === $missing_get['value']
				&& null === $missing_drop['value']
				&& self::has_warning( $missing_get )
				&& self::has_warning( $missing_drop ),
			'unregister returns the removed connector and missing lookups warn',
			array(
				'removed'      => self::describe_value( $removed ),
				'missingGet'   => $missing_get,
				'missingUnreg' => $missing_drop,
			)
		);

		$override_args                   = is_array( $removed ) ? $removed : array();
		$override_args['name']           = 'Override ' . $ctx->identifier( 4, 10 );
		$override_args['description']    = 'Re-registered connector replacement.';
		$override_args['authentication'] = array( 'method' => 'none' );
		$override_args['plugin']         = array(
			'is_active' => static fn(): bool => false,
		);
		$override_registered             = $registry->register( $cases[0]['id'], $override_args );
		$override_public                 = \wp_get_connector( $cases[0]['id'] );
		$after_override                  = \wp_get_connectors();
		$override_plugin                 = is_array( $override_registered ) ? $override_registered['plugin'] : array();
		$override_is_active              = isset( $override_plugin['is_active'] ) && is_callable( $override_plugin['is_active'] )
			? (bool) call_user_func( $override_plugin['is_active'] )
			: null;

		self::collect_failure(
			$failures,
			is_array( $override_registered )
				&& $override_registered === $override_public
				&& isset( $after_override[ $cases[0]['id'] ] )
				&& $override_registered === $after_override[ $cases[0]['id'] ]
				&& count( $registered ) === count( $after_override )
				&& array_key_last( $after_override ) === $cases[0]['id']
				&& $override_args['name'] === $override_registered['name']
				&& $override_args['description'] === $override_registered['description']
				&& array( 'method' => 'none' ) === $override_registered['authentication']
				&& false === $override_is_active,
			'unregistered connector data can be modified and re-registered as a replacement',
			array(
				'id'         => $cases[0]['id'],
				'override'   => self::describe_value( $override_registered ),
				'public'     => self::describe_value( $override_public ),
				'keys'       => array_keys( $after_override ),
				'isActive'   => $override_is_active,
				'registered' => count( $registered ),
				'actual'     => count( $after_override ),
			)
		);

		self::set_connector_registry( null );
		self::collect_failure(
			$failures,
			! \wp_is_connector_registered( $cases[1]['id'] )
				&& null === \wp_get_connector( $cases[1]['id'] )
				&& array() === \wp_get_connectors(),
			'public connector helpers tolerate a missing singleton',
			array(
				'id'           => $cases[1]['id'],
				'isRegistered' => \wp_is_connector_registered( $cases[1]['id'] ),
				'connector'    => \wp_get_connector( $cases[1]['id'] ),
				'connectors'   => \wp_get_connectors(),
			)
		);

		return self::result(
			$ctx,
			'registries.connectors.lifecycle-and-validation',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_connector_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$key      = 'sk-' . strtolower( $ctx->identifier( 4, 10 ) ) . '-' . dechex( $ctx->seed() );

		$mask_cases = array(
			''          => '',
			'abcd'      => 'abcd',
			'abcde'     => "\u{2022}" . 'bcde',
			$key        => str_repeat( "\u{2022}", min( strlen( $key ) - 4, 16 ) ) . substr( $key, -4 ),
			$key . $key => str_repeat( "\u{2022}", 16 ) . substr( $key . $key, -4 ),
		);

		foreach ( $mask_cases as $plain => $expected ) {
			$masked = \_wp_connectors_mask_api_key( $plain );
			self::collect_failure(
				$failures,
				$expected === $masked,
				'API key masking preserves short keys and caps the hidden prefix',
				array(
					'plainLength' => strlen( $plain ),
					'expected'    => $expected,
					'actual'      => $masked,
				)
			);
		}

		$setting_name = 'component_fuzz_' . str_replace( '-', '_', self::connector_id( $ctx->fork( 'setting' ), 'setting' ) ) . '_api_key';
		$env_name     = 'COMPONENT_FUZZ_' . strtoupper( dechex( $ctx->seed() ) ) . '_API_KEY';
		$old_env      = getenv( $env_name );
		$db_filter    = static function () use ( $key ): string {
			return $key . '-db';
		};

		try {
			putenv( $env_name );

			$none = \_wp_connectors_get_api_key_source( $setting_name, '', '' );

			\add_filter( "pre_option_{$setting_name}", $db_filter );
			$database = \_wp_connectors_get_api_key_source( $setting_name, '', '' );
			$constant = \_wp_connectors_get_api_key_source( $setting_name, '', 'AUTH_KEY' );

			putenv( $env_name . '=' . $key . '-env' );
			$env = \_wp_connectors_get_api_key_source( $setting_name, $env_name, 'AUTH_KEY' );
			\remove_filter( "pre_option_{$setting_name}", $db_filter );

			$env_without_db = \_wp_connectors_get_api_key_source( $setting_name, $env_name, '' );
			putenv( $env_name );
			$constant_without_db = \_wp_connectors_get_api_key_source( $setting_name, '', 'AUTH_KEY' );

			self::collect_failure(
				$failures,
				'none' === $none
					&& 'database' === $database
					&& 'constant' === $constant
					&& 'env' === $env
					&& 'env' === $env_without_db
					&& 'constant' === $constant_without_db,
				'API key source precedence is env, constant, database, then none',
				array(
					'none'              => $none,
					'database'          => $database,
					'constant'          => $constant,
					'env'               => $env,
					'envWithoutDb'      => $env_without_db,
					'constantWithoutDb' => $constant_without_db,
				)
			);
		} finally {
			\remove_filter( "pre_option_{$setting_name}", $db_filter );
			if ( false === $old_env ) {
				putenv( $env_name );
			} else {
				putenv( $env_name . '=' . $old_env );
			}
		}

		return self::result(
			$ctx,
			'registries.connectors.private-helper-oracles',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_icons_registry( \ComponentFuzz\FuzzContext $ctx ): array {
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
			$search     = str_contains( $first_name, '/' ) ? substr( $first_name, strpos( $first_name, '/' ) + 1, 4 ) : 'word';
			$matches    = $registry->get_registered_icons( strtoupper( $search ) );

			self::collect_failure(
				$failures,
				0 < count( $all_icons )
					&& is_array( $first_icon )
					&& '' !== $first_name
					&& $registry->is_registered( $first_name )
					&& $registry->get_registered_icon( $first_name )['name'] === $first_name
					&& 0 < count( $matches )
					&& self::icons_all_match_search( $matches, strtoupper( $search ) )
					&& array() === $registry->get_registered_icons( 'component-fuzz-no-such-icon-' . dechex( $ctx->seed() ) ),
				'core icon manifest loads registered icons and search filters by icon name',
				array(
					'count'      => count( $all_icons ),
					'firstName'  => $first_name,
					'search'     => $search,
					'matchCount' => count( $matches ),
				)
			);

			$safe_svg     = '<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 24 24"><path fill="currentColor" d="M0 0h24v24H0z"/></svg>';
			$unsafe_svg   = '<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 24 24" onload="alert(1)"><path d="M1 1h2v2z" onclick="bad()"/><script>alert(1)</script></svg>';
			$safe_sanitized = $sanitize->invoke( $registry, $safe_svg );
			$sanitized      = $sanitize->invoke( $registry, $unsafe_svg );
			$content_id   = self::icon_name( $ctx->fork( 'content' ), 'content' );
			$registered   = $register->invoke(
				$registry,
				$content_id,
				array(
					'label'   => 'Fuzz content icon',
					'content' => $safe_svg,
				)
			);
			$content_icon         = $registry->get_registered_icon( $content_id );
			$unsafe_content_id    = self::icon_name( $ctx->fork( 'unsafe-content' ), 'unsafe-content' );
			$unsafe_registered    = $register->invoke(
				$registry,
				$unsafe_content_id,
				array(
					'label'   => 'Fuzz unsafe content icon',
					'content' => $unsafe_svg,
				)
			);
			$unsafe_content_icon = $registry->get_registered_icon( $unsafe_content_id );

			self::collect_failure(
				$failures,
				true === $registered
					&& true === $unsafe_registered
					&& is_array( $content_icon )
					&& is_array( $unsafe_content_icon )
					&& $content_id === $content_icon['name']
					&& $safe_sanitized === $content_icon['content']
					&& '' !== $sanitized
					&& str_contains( $sanitized, '<svg' )
					&& ! str_contains( $sanitized, '<script' )
					&& ! str_contains( $sanitized, 'onload' )
					&& ! str_contains( $sanitized, 'onclick' )
					&& $unsafe_content_id === $unsafe_content_icon['name']
					&& $sanitized === $unsafe_content_icon['content'],
				'protected icon registration stores sanitized SVG content',
				array(
					'contentId'       => $content_id,
					'unsafeContentId' => $unsafe_content_id,
					'safeSanitized'   => $safe_sanitized,
					'sanitized'       => $sanitized,
					'icon'            => self::describe_value( $content_icon ),
					'unsafeIcon'      => self::describe_value( $unsafe_content_icon ),
				)
			);

			$file_path    = self::write_temp_svg( $ctx, $unsafe_svg );
			$temp_files[] = $file_path;
			$file_id      = self::icon_name( $ctx->fork( 'file' ), 'file' );
			$file_reg     = $register->invoke(
				$registry,
				$file_id,
				array(
					'label'    => 'Fuzz file icon',
					'filePath' => $file_path,
				)
			);

			$stored_before = self::get_object_property( $registry, 'registered_icons' );
			$had_content   = isset( $stored_before[ $file_id ]['content'] );
			$first_file    = $registry->get_registered_icon( $file_id );
			file_put_contents(
				$file_path,
				'<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 24 24"><path d="M9 9h9v9H9z"/></svg>'
			);
			$second_file = $registry->get_registered_icon( $file_id );

			self::collect_failure(
				$failures,
				true === $file_reg
					&& false === $had_content
					&& is_array( $first_file )
					&& is_array( $second_file )
					&& $first_file['content'] === $second_file['content']
					&& ! str_contains( $first_file['content'], '<script' )
					&& ! str_contains( $first_file['content'], 'onload' )
					&& ! str_contains( $first_file['content'], 'onclick' )
					&& str_contains( $first_file['content'], '<svg' )
					&& str_contains( $first_file['content'], 'M1 1h2v2z' ),
				'filePath icon content is sanitized once and cached lazily',
				array(
					'fileId'     => $file_id,
					'hadContent' => $had_content,
					'firstFile'  => self::describe_value( $first_file ),
					'secondFile' => self::describe_value( $second_file ),
				)
			);

			$before_invalid = self::get_object_property( $registry, 'registered_icons' );
			$invalids       = array(
				'uppercase'       => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'Fuzz/upper',
						array(
							'label'   => 'Uppercase',
							'content' => $safe_svg,
						)
					)
				),
				'noNamespace'     => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						'nonamespace',
						array(
							'label'   => 'No namespace',
							'content' => $safe_svg,
						)
					)
				),
				'duplicate'       => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						$content_id,
						array(
							'label'   => 'Duplicate',
							'content' => $safe_svg,
						)
					)
				),
				'invalidProperty' => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						self::icon_name( $ctx->fork( 'bad-property' ), 'bad-property' ),
						array(
							'label'   => 'Bad property',
							'content' => $safe_svg,
							'width'   => 24,
						)
					)
				),
				'missingLabel'    => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						self::icon_name( $ctx->fork( 'missing-label' ), 'missing-label' ),
						array( 'content' => $safe_svg )
					)
				),
				'bothSources'     => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						self::icon_name( $ctx->fork( 'both-sources' ), 'both-sources' ),
						array(
							'label'    => 'Both sources',
							'content'  => $safe_svg,
							'filePath' => $file_path,
						)
					)
				),
				'noSource'        => self::capture_doing_it_wrong(
					static fn() => $register->invoke(
						$registry,
						self::icon_name( $ctx->fork( 'no-source' ), 'no-source' ),
						array( 'label' => 'No source' )
					)
				),
			);

			$all_invalid_failed = true;
			foreach ( $invalids as $invalid ) {
				$all_invalid_failed = $all_invalid_failed
					&& false === $invalid['value']
					&& self::has_warning( $invalid );
			}

			self::collect_failure(
				$failures,
				$all_invalid_failed
				&& self::get_object_property( $registry, 'registered_icons' ) === $before_invalid,
				'invalid icon registration paths warn and leave registry unchanged',
				array( 'invalids' => $invalids )
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
			'registries.icons.manifest-sanitization-and-caching',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_speculation_helper_allowlists( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$modes = array(
			'prefetch'         => true,
			'prerender'        => true,
			'fetch'            => false,
			'PREfetch'         => false,
			''                 => false,
			$ctx->identifier() => false,
		);
		foreach ( $modes as $mode => $expected ) {
			self::collect_failure(
				$failures,
				\WP_Speculation_Rules::is_valid_mode( $mode ) === $expected,
				'speculation mode helper exactly matches the mode allowlist',
				array(
					'mode'     => $mode,
					'expected' => $expected,
					'actual'   => \WP_Speculation_Rules::is_valid_mode( $mode ),
				)
			);
		}

		$eagerness_values = array(
			'immediate'    => true,
			'eager'        => true,
			'moderate'     => true,
			'conservative' => true,
			'lazy'         => false,
			'Eager'        => false,
			''             => false,
		);
		foreach ( $eagerness_values as $eagerness => $expected ) {
			self::collect_failure(
				$failures,
				\WP_Speculation_Rules::is_valid_eagerness( $eagerness ) === $expected,
				'speculation eagerness helper exactly matches the eagerness allowlist',
				array(
					'eagerness' => $eagerness,
					'expected'  => $expected,
					'actual'    => \WP_Speculation_Rules::is_valid_eagerness( $eagerness ),
				)
			);
		}

		$sources = array(
			'list'     => true,
			'document' => true,
			'url'      => false,
			'LIST'     => false,
			''         => false,
		);
		foreach ( $sources as $source => $expected ) {
			self::collect_failure(
				$failures,
				\WP_Speculation_Rules::is_valid_source( $source ) === $expected,
				'speculation source helper exactly matches the source allowlist',
				array(
					'source'   => $source,
					'expected' => $expected,
					'actual'   => \WP_Speculation_Rules::is_valid_source( $source ),
				)
			);
		}

		return self::result(
			$ctx,
			'registries.speculation.helper-allowlists',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_speculation_rule_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$rules    = new \WP_Speculation_Rules();
		$ids      = array(
			'doc'     => self::speculation_id( $ctx->fork( 'doc' ), 'doc' ),
			'list'    => self::speculation_id( $ctx->fork( 'list' ), 'list' ),
			'where'   => self::speculation_id( $ctx->fork( 'where' ), 'where' ),
			'invalid' => self::speculation_id( $ctx->fork( 'invalid' ), 'invalid' ),
		);

		$document_rule = array(
			'source'    => 'document',
			'where'     => array( 'href_matches' => '/*' . dechex( $ctx->seed() & 0xff ) . '*' ),
			'eagerness' => 'moderate',
		);
		$list_rule     = array(
			'source'    => 'list',
			'urls'      => array( '/alpha-' . dechex( $ctx->seed() & 0xff ), '/beta' ),
			'eagerness' => 'immediate',
		);
		$implicit_doc  = array(
			'where'     => array( 'selector_matches' => 'a[data-fuzz]' ),
			'eagerness' => 'conservative',
		);

		$add_document = $rules->add_rule( 'prerender', $ids['doc'], $document_rule );
		$add_list     = $rules->add_rule( 'prefetch', $ids['list'], $list_rule );
		$add_where    = $rules->add_rule( 'prefetch', $ids['where'], $implicit_doc );
		$serialized   = $rules->jsonSerialize();

		self::collect_failure(
			$failures,
			true === $add_document
				&& true === $add_list
				&& true === $add_where
				&& $rules->has_rule( 'prerender', $ids['doc'] )
				&& $rules->has_rule( 'prefetch', $ids['list'] )
				&& $rules->has_rule( 'prefetch', $ids['where'] )
				&& array( 'prerender', 'prefetch' ) === array_keys( $serialized )
				&& array( $document_rule ) === $serialized['prerender']
				&& array( $list_rule, $implicit_doc ) === $serialized['prefetch']
				&& array( 0 ) === array_keys( $serialized['prerender'] )
				&& array( 0, 1 ) === array_keys( $serialized['prefetch'] ),
			'valid speculation rules preserve mode/rule insertion order and strip IDs for JSON',
			array(
				'ids'        => $ids,
				'serialized' => $serialized,
			)
		);

		$invalid_matrix = array(
			'invalidMode'        => array( 'navigate', self::speculation_id( $ctx->fork( 'bad-mode' ), 'bad-mode' ), $list_rule ),
			'invalidId'          => array( 'prefetch', '1bad', $list_rule ),
			'duplicateId'        => array( 'prefetch', $ids['list'], $list_rule ),
			'missingWhereUrls'   => array( 'prefetch', self::speculation_id( $ctx->fork( 'missing-keys' ), 'missing' ), array( 'source' => 'list' ) ),
			'bothWhereUrls'      => array(
				'prefetch',
				self::speculation_id( $ctx->fork( 'both-keys' ), 'both' ),
				array(
					'where' => array( 'href_matches' => '/*' ),
					'urls'  => array( '/both' ),
				),
			),
			'invalidSource'      => array(
				'prefetch',
				self::speculation_id( $ctx->fork( 'bad-source' ), 'bad-source' ),
				array(
					'source' => 'bad-source',
					'urls'   => array( '/bad-source' ),
				),
			),
			'listWithWhere'      => array(
				'prefetch',
				self::speculation_id( $ctx->fork( 'list-where' ), 'list-where' ),
				array(
					'source' => 'list',
					'where'  => array( 'href_matches' => '/*' ),
				),
			),
			'documentWithUrls'   => array(
				'prefetch',
				self::speculation_id( $ctx->fork( 'doc-urls' ), 'doc-urls' ),
				array(
					'source' => 'document',
					'urls'   => array( '/doc-urls' ),
				),
			),
			'invalidEagerness'   => array(
				'prefetch',
				self::speculation_id( $ctx->fork( 'bad-eagerness' ), 'bad-eagerness' ),
				array(
					'urls'      => array( '/bad-eagerness' ),
					'eagerness' => 'fast',
				),
			),
			'immediateWithWhere' => array(
				'prefetch',
				self::speculation_id( $ctx->fork( 'immediate-where' ), 'immediate-where' ),
				array(
					'where'     => array( 'href_matches' => '/*' ),
					'eagerness' => 'immediate',
				),
			),
		);

		$before_invalid  = $rules->jsonSerialize();
		$invalid_results = array();
		foreach ( $invalid_matrix as $label => $invalid ) {
			$invalid_results[ $label ] = self::capture_doing_it_wrong(
				static fn() => $rules->add_rule( $invalid[0], $invalid[1], $invalid[2] )
			);
		}

		$all_invalid_failed = true;
		foreach ( $invalid_results as $invalid ) {
			$all_invalid_failed = $all_invalid_failed
				&& false === $invalid['value']
				&& self::has_warning( $invalid );
		}

		self::collect_failure(
			$failures,
			$all_invalid_failed
				&& $before_invalid === $rules->jsonSerialize()
				&& false === $rules->has_rule( 'prefetch', $ids['invalid'] ),
			'invalid speculation rule matrix warns and leaves serialized rules unchanged',
			array( 'invalidResults' => $invalid_results )
		);

		return self::result(
			$ctx,
			'registries.speculation.rule-validation-and-serialization',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function connector_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		for ( $i = 0; $i < self::CONNECTOR_CASES; $i++ ) {
			$case_ctx        = $ctx->fork( 'connector-' . $i );
			$id              = self::connector_id( $case_ctx, 'connector-' . $i );
			$type            = self::slug( $case_ctx->fork( 'type' ), $case_ctx->bool() ? 'type-with-hyphen' : 'type_under' );
			$method          = $case_ctx->bool( 70 ) ? 'api_key' : 'none';
			$description     = $case_ctx->bool( 75 ) ? 'Description ' . $case_ctx->identifier( 3, 10 ) : '';
			$has_plugin      = $case_ctx->bool( 65 );
			$has_is_active   = $has_plugin && $case_ctx->bool( 55 );
			$expected_active = ! $has_is_active || $case_ctx->bool();
			$args            = array(
				'name'           => 'Connector ' . $case_ctx->identifier( 3, 10 ),
				'description'    => $description,
				'type'           => $type,
				'authentication' => array( 'method' => $method ),
			);

			$expected_auth = array( 'method' => $method );
			if ( 'api_key' === $method ) {
				if ( $case_ctx->bool( 50 ) ) {
					$args['authentication']['credentials_url'] = 'https://example.test/keys/' . rawurlencode( $id );
					$expected_auth['credentials_url']          = $args['authentication']['credentials_url'];
				}
				if ( $case_ctx->bool( 40 ) ) {
					$args['authentication']['setting_name'] = 'explicit_' . str_replace( '-', '_', $id ) . '_api_key';
				}
				$expected_auth['setting_name'] = $args['authentication']['setting_name']
					?? str_replace( '-', '_', "connectors_{$type}_{$id}_api_key" );
				if ( $case_ctx->bool( 35 ) ) {
					$args['authentication']['constant_name'] = 'COMPONENT_FUZZ_' . strtoupper( str_replace( '-', '_', $id ) ) . '_KEY';
					$expected_auth['constant_name']          = $args['authentication']['constant_name'];
				}
				if ( $case_ctx->bool( 35 ) ) {
					$args['authentication']['env_var_name'] = 'COMPONENT_FUZZ_' . strtoupper( str_replace( '-', '_', $id ) ) . '_ENV';
					$expected_auth['env_var_name']          = $args['authentication']['env_var_name'];
				}
			}

			$expected_plugin_file = null;
			if ( $has_plugin ) {
				$args['plugin'] = array();
				if ( $case_ctx->bool( 70 ) ) {
					$expected_plugin_file   = $id . '/' . $id . '.php';
					$args['plugin']['file'] = $expected_plugin_file;
				}
				if ( $has_is_active ) {
					$args['plugin']['is_active'] = static fn(): bool => $expected_active;
				}
			}

			$cases[] = array(
				'id'                  => $id,
				'args'                => $args,
				'expectedDescription' => $description,
				'expectedAuth'        => $expected_auth,
				'expectedPluginFile'  => $expected_plugin_file,
				'expectedActive'      => $expected_active,
			);
		}

		return $cases;
	}

	private static function connector_id( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return self::slug( $ctx, $prefix ) . '-' . substr( dechex( $ctx->seed() ), -6 );
	}

	private static function icon_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return 'fuzz-' . self::icon_slug( $ctx, 'ns-' . $prefix ) . '/' . self::icon_slug( $ctx->fork( 'icon' ), 'icon-' . $prefix );
	}

	private static function speculation_id( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return 'r' . self::slug( $ctx, $prefix ) . '-' . substr( dechex( $ctx->seed() ), -6 );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$slug = strtolower( $prefix . '-' . $ctx->identifier( 3, 10 ) );
		$slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', (string) $slug );
		$slug = trim( $slug, '-_' );

		return '' === $slug ? 'fuzz-' . substr( dechex( $ctx->seed() ), -4 ) : $slug;
	}

	private static function icon_slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$slug = strtolower( $prefix . '-' . $ctx->identifier( 3, 10 ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', (string) $slug );
		$slug = trim( $slug, '-' );

		return '' === $slug ? 'fuzz-' . substr( dechex( $ctx->seed() ), -4 ) : $slug;
	}

	private static function write_temp_svg( \ComponentFuzz\FuzzContext $ctx, string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'cf-reg-icons-' . substr( dechex( $ctx->seed() ), -4 ) . '-' );
		if ( false === $path ) {
			throw new \RuntimeException( 'Could not create temporary SVG file.' );
		}
		file_put_contents( $path, $content );
		return $path;
	}

	private static function icons_all_match_search( array $icons, string $search ): bool {
		foreach ( $icons as $icon ) {
			if ( ! is_array( $icon ) || ! isset( $icon['name'] ) || false === stripos( (string) $icon['name'], $search ) ) {
				return false;
			}
		}
		return true;
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
					'wp_filter',
					'wp_filters',
					'wp_actions',
					'wp_current_filter',
					'wp_object_cache',
					'wp_registered_settings',
					'new_allowed_options',
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
