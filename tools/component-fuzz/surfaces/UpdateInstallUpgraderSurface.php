<?php
namespace ComponentFuzz\Surfaces;

final class UpdateInstallUpgraderSurface {
	public const NAME = 'update-install-upgrader';

	private const MAX_FAILURES = 12;
	private const PREVIEW_BYTES = 220;

	private static array $site_transients = array();
	private static array $site_options    = array();
	private static ?bool $file_mod_allowed = null;
	private static ?bool $vcs_checkout = null;
	private static ?bool $automatic_updater_disabled = null;
	private static ?bool $plugins_auto_update_enabled = null;
	private static ?bool $themes_auto_update_enabled = null;
	private static array $auto_update_overrides = array();
	private static array $granted_caps = array();
	private static int $http_request_count = 0;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'update-install-upgrader.bootstrap-apis-available',
					'Required WordPress update/install/upgrader APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot  = self::snapshot_state();
		$rows      = array();
		$temp_root = null;
		$cleanup   = null;

		try {
			$temp_root = self::make_temp_root( $ctx );
			self::prepare_temp_filesystem( $temp_root );
			self::install_filters();

			$case = self::case_for_context( $ctx, $temp_root );
			self::write_case_files( $case );
			self::prime_update_state( $case );

			$rows[] = self::check_upgrader_skins( $ctx, $case );
			$rows[] = self::check_update_transient_helpers( $ctx, $case );
			$rows[] = self::check_download_package_paths( $ctx, $case );
			$rows[] = self::check_install_package_lifecycle( $ctx, $case );
			$rows[] = self::check_plugin_theme_package_validation( $ctx, $case );
			$rows[] = self::check_upgrade_no_update_paths( $ctx, $case );
			$rows[] = self::check_auto_update_decisions( $ctx, $case );
			$rows[] = self::check_core_update_decisions( $ctx, $case );
			$rows[] = self::check_maintenance_mode_guard( $ctx, $case );
			$rows[] = $ctx->result(
				'update-install-upgrader.no-network-attempts',
				0 === self::$http_request_count,
				array( 'httpRequestCount' => self::$http_request_count )
			);
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'update-install-upgrader.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_static_state();
			if ( null !== $temp_root ) {
				$cleanup = self::remove_dir_recursive( $temp_root );
			}
			self::restore_state( $snapshot );
		}

		if ( null !== $temp_root ) {
			$rows[] = $ctx->result(
				'update-install-upgrader.temp-sandbox-cleaned',
				true === $cleanup && ! file_exists( $temp_root ),
				array(
					'root'    => self::preview( $temp_root ),
					'cleaned' => true === $cleanup,
					'exists'  => file_exists( $temp_root ),
				)
			);
		}

		$rows[] = $ctx->result(
			'update-install-upgrader.global-state-restored',
			self::state_matches( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot['globals'] ) )
		);

		return $rows;
	}

	public static function filter_site_transient( $pre_site_transient, string $transient ) {
		unset( $pre_site_transient );

		return array_key_exists( $transient, self::$site_transients ) ? self::$site_transients[ $transient ] : false;
	}

	public static function filter_site_option(
		$pre_site_option,
		string $option = '',
		$network_id = null,
		$default_value = false
	) {
		unset( $pre_site_option, $network_id, $default_value );

		return array_key_exists( $option, self::$site_options ) ? self::$site_options[ $option ] : false;
	}

	public static function filter_pre_http_request( $preempt, array $parsed_args, string $url ) {
		unset( $preempt, $parsed_args, $url );

		++self::$http_request_count;
		return new \WP_Error( 'component_fuzz_network_blocked', 'Component fuzz blocked a remote HTTP request.' );
	}

	public static function filter_request_filesystem_credentials() {
		return true;
	}

	public static function filter_filesystem_method( $method ) {
		unset( $method );

		return 'direct';
	}

	public static function filter_file_mod_allowed( bool $allowed, string $context ): bool {
		unset( $context );

		return null === self::$file_mod_allowed ? $allowed : self::$file_mod_allowed;
	}

	public static function filter_automatic_updater_disabled( bool $disabled ): bool {
		return null === self::$automatic_updater_disabled ? $disabled : self::$automatic_updater_disabled;
	}

	public static function filter_vcs_checkout( bool $checkout, string $context ): bool {
		unset( $context );

		return null === self::$vcs_checkout ? $checkout : self::$vcs_checkout;
	}

	public static function filter_plugins_auto_update_enabled( bool $enabled ): bool {
		return null === self::$plugins_auto_update_enabled ? $enabled : self::$plugins_auto_update_enabled;
	}

	public static function filter_themes_auto_update_enabled( bool $enabled ): bool {
		return null === self::$themes_auto_update_enabled ? $enabled : self::$themes_auto_update_enabled;
	}

	public static function filter_auto_update_plugin( $update, $item ) {
		unset( $item );

		return array_key_exists( 'plugin', self::$auto_update_overrides ) ? self::$auto_update_overrides['plugin'] : $update;
	}

	public static function filter_auto_update_theme( $update, $item ) {
		unset( $item );

		return array_key_exists( 'theme', self::$auto_update_overrides ) ? self::$auto_update_overrides['theme'] : $update;
	}

	public static function filter_user_has_cap( array $allcaps, array $caps, array $args = array(), $user = null ): array {
		unset( $caps, $args, $user );

		foreach ( self::$granted_caps as $cap => $grant ) {
			if ( $grant ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Error',
				'WP_Filesystem_Direct',
				'WP_Upgrader',
				'WP_Upgrader_Skin',
				'Automatic_Upgrader_Skin',
				'Plugin_Upgrader',
				'Theme_Upgrader',
				'Core_Upgrader',
				'WP_Automatic_Updater',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'apply_filters',
				'copy_dir',
				'get_core_updates',
				'get_plugin_data',
				'get_plugin_updates',
				'get_site_option',
				'get_site_transient',
				'get_theme_updates',
				'is_wp_error',
				'remove_filter',
				'trailingslashit',
				'wp_clean_plugins_cache',
				'wp_clean_themes_cache',
				'wp_get_update_data',
				'wp_get_wp_version',
				'wp_installing',
				'wp_is_auto_update_enabled_for_type',
				'wp_is_file_mod_allowed',
				'wp_kses',
				'wp_normalize_path',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function install_filters(): void {
		foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $transient ) {
			\add_filter( "pre_site_transient_{$transient}", array( self::class, 'filter_site_transient' ), 10, 2 );
		}

		\add_filter( 'pre_site_option', array( self::class, 'filter_site_option' ), 10, 4 );
		\add_filter( 'pre_http_request', array( self::class, 'filter_pre_http_request' ), 10, 3 );
		\add_filter( 'request_filesystem_credentials', array( self::class, 'filter_request_filesystem_credentials' ), 10, 7 );
		\add_filter( 'filesystem_method', array( self::class, 'filter_filesystem_method' ), 10, 4 );
		\add_filter( 'file_mod_allowed', array( self::class, 'filter_file_mod_allowed' ), 10, 2 );
		\add_filter( 'automatic_updater_disabled', array( self::class, 'filter_automatic_updater_disabled' ) );
		\add_filter( 'automatic_updates_is_vcs_checkout', array( self::class, 'filter_vcs_checkout' ), 10, 2 );
		\add_filter( 'plugins_auto_update_enabled', array( self::class, 'filter_plugins_auto_update_enabled' ) );
		\add_filter( 'themes_auto_update_enabled', array( self::class, 'filter_themes_auto_update_enabled' ) );
		\add_filter( 'auto_update_plugin', array( self::class, 'filter_auto_update_plugin' ), 10, 2 );
		\add_filter( 'auto_update_theme', array( self::class, 'filter_auto_update_theme' ), 10, 2 );
		\add_filter( 'user_has_cap', array( self::class, 'filter_user_has_cap' ), 10, 4 );
	}

	private static function prime_update_state( array $case ): void {
		self::$site_transients = array(
			'update_core'    => $case['updates']['coreTransient'],
			'update_plugins' => $case['updates']['pluginTransient'],
			'update_themes'  => $case['updates']['themeTransient'],
		);
		self::$site_options    = array(
			'auto_update_plugins'     => $case['autoUpdate']['sitePluginOptIns'],
			'auto_update_themes'      => $case['autoUpdate']['siteThemeOptIns'],
			'auto_update_core_major'  => $case['autoUpdate']['coreMajorOption'],
			'auto_update_core_minor'  => $case['autoUpdate']['coreMinorOption'],
			'auto_update_core_dev'    => $case['autoUpdate']['coreDevOption'],
			'auto_core_update_failed' => false,
			'admin_email'             => 'component-fuzz@example.test',
		);
		self::$granted_caps    = array(
			'update_core'    => true,
			'update_plugins' => true,
			'update_themes'  => true,
		);
	}

	private static function check_upgrader_skins( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$skin_title = 'Update Skin ' . $case['labels']['suffix'];
		$skin       = new \WP_Upgrader_Skin(
			array(
				'title' => $skin_title,
				'url'   => 'https://example.test/wp-admin/update.php',
				'nonce' => 'component-fuzz-upgrader',
			)
		);
		$upgrader   = self::initialized_upgrader( \WP_Upgrader::class, $skin );
		$result     = new \WP_Error( 'component_fuzz_skin_result', 'Skin result' );

		$skin->set_result( $result );
		ob_start();
		$skin->header();
		$skin->header();
		$skin->footer();
		$skin->footer();
		$html = (string) ob_get_clean();

		self::record_failure_if(
			$failures,
			! $skin->done_header || ! $skin->done_footer || $skin->upgrader !== $upgrader || $skin->result !== $result,
			'WP_Upgrader_Skin.header-footer-result-state',
			array(
				'doneHeader' => $skin->done_header,
				'doneFooter' => $skin->done_footer,
				'hasUpgrader' => $skin->upgrader === $upgrader,
			)
		);
		self::record_failure_if(
			$failures,
			1 !== substr_count( $html, '<div class="wrap">' ) || 1 !== substr_count( $html, '</div>' ) || ! str_contains( $html, $skin_title ),
			'WP_Upgrader_Skin.header-footer-idempotent-output',
			array( 'html' => $html )
		);

		$automatic_skin = new \Automatic_Upgrader_Skin();
		self::initialized_upgrader( \WP_Upgrader::class, $automatic_skin );
		$automatic_skin->feedback( '<strong>Allowed</strong><script>alert(1)</script><a href="https://example.test/">Link</a>' );
		$automatic_skin->feedback( array( 'ignored' ) );
		$automatic_skin->feedback( new \WP_Error( 'component_fuzz_auto_skin', 'Error <em>message</em><script>x</script>' ) );
		$messages = $automatic_skin->get_upgrade_messages();

		self::record_failure_if(
			$failures,
			2 !== count( $messages )
				|| ! str_contains( $messages[0], '<strong>Allowed</strong>' )
				|| ! str_contains( $messages[0], '<a href="https://example.test/">Link</a>' )
				|| str_contains( strtolower( implode( "\n", $messages ) ), '<script' ),
			'Automatic_Upgrader_Skin.feedback-sanitizes-and-captures',
			array( 'messages' => $messages )
		);

		return self::row(
			$ctx,
			'update-install-upgrader.skins.capture-and-sanitize-output',
			$failures,
			array(
				'title'         => $skin_title,
				'messageCount'  => count( $messages ),
				'genericString' => $upgrader->strings['download_failed'] ?? null,
			)
		);
	}

	private static function check_update_transient_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$core_updates   = \get_core_updates(
			array(
				'available' => true,
				'dismissed' => true,
			)
		);
		\wp_cache_set(
			'plugins',
			array(
				'' => array(
					$case['plugin']['file'] => array(
						'Name'    => $case['plugin']['name'],
						'Version' => $case['plugin']['version'],
					),
				),
			),
			'plugins'
		);
		$plugin_updates = \get_plugin_updates();
		$theme_updates  = \get_theme_updates();
		$update_data    = \wp_get_update_data();

		self::record_failure_if(
			$failures,
			! is_array( $core_updates ) || self::count_visible_core_updates( $case['updates']['coreTransient']->updates ) !== count( $core_updates ),
			'get_core_updates.generated-transient-count',
			array(
				'expected' => self::count_visible_core_updates( $case['updates']['coreTransient']->updates ),
				'actual'   => self::describe_value( $core_updates ),
			)
		);

		self::record_failure_if(
			$failures,
			! is_array( $plugin_updates ) || array_keys( $plugin_updates ) !== array( $case['plugin']['file'] ),
			'get_plugin_updates.response-keys-match-installed-plugins',
			array(
				'expected' => $case['plugin']['file'],
				'actual'   => array_keys( is_array( $plugin_updates ) ? $plugin_updates : array() ),
			)
		);

		self::record_failure_if(
			$failures,
			! is_array( $theme_updates ) || array_keys( $theme_updates ) !== array( $case['theme']['slug'] ),
			'get_theme_updates.response-keys-match-transient',
			array(
				'expected' => $case['theme']['slug'],
				'actual'   => array_keys( is_array( $theme_updates ) ? $theme_updates : array() ),
			)
		);

		$expected_counts = array(
			'wordpress' => empty( $core_updates ) ? 0 : 1,
			'plugins'   => 1,
			'themes'    => 1,
			'total'     => ( empty( $core_updates ) ? 0 : 1 ) + 2,
		);
		foreach ( $expected_counts as $key => $expected ) {
			$actual = $update_data['counts'][ $key ] ?? null;
			self::record_failure_if(
				$failures,
				$actual !== $expected,
				"wp_get_update_data.{$key}-count",
				array(
					'expected' => $expected,
					'actual'   => $actual,
					'counts'   => $update_data['counts'] ?? null,
				)
			);
		}

		self::record_failure_if(
			$failures,
			! is_array( $update_data ) || ! isset( $update_data['title'] ) || ! is_string( $update_data['title'] ) || '' === $update_data['title'],
			'wp_get_update_data.title-is-nonempty-string',
			array( 'updateData' => $update_data )
		);

		return self::row(
			$ctx,
			'update-install-upgrader.update-transients.aggregate-shapes',
			$failures,
			array(
				'plugin' => $case['plugin']['file'],
				'theme'  => $case['theme']['slug'],
				'counts' => $update_data['counts'] ?? null,
			)
		);
	}

	private static function check_download_package_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = self::initialized_upgrader( \WP_Upgrader::class, $skin );

		$local_package = $case['paths']['localPackage'];
		$local_result  = self::call(
			static function () use ( $upgrader, $local_package ) {
				return $upgrader->download_package( $local_package, false, array( 'type' => 'plugin' ) );
			}
		);
		self::record_failure_if(
			$failures,
			$local_result['threw'] || $local_result['value'] !== $local_package,
			'WP_Upgrader.download_package.local-file-returned-unchanged',
			array( 'call' => self::describe_call( $local_result ) )
		);

		$empty_result = self::call(
			static function () use ( $upgrader ) {
				return $upgrader->download_package( '', false, array( 'type' => 'theme' ) );
			}
		);
		self::record_failure_if(
			$failures,
			$empty_result['threw'] || ! self::wp_error_has_code( $empty_result['value'], 'no_package' ),
			'WP_Upgrader.download_package.empty-package-error',
			array( 'call' => self::describe_call( $empty_result ) )
		);

		$seen_hook_extra = null;
		$pre_download   = static function ( $reply, string $package, $filter_upgrader, array $hook_extra ) use ( $local_package, &$seen_hook_extra ) {
			unset( $reply, $filter_upgrader );

			$seen_hook_extra = $hook_extra + array( 'package' => $package );
			return $local_package;
		};
		\add_filter( 'upgrader_pre_download', $pre_download, 10, 4 );
		try {
			$remote_result = self::call(
				static function () use ( $upgrader, $case ) {
					return $upgrader->download_package( $case['paths']['remotePackage'], false, array( 'type' => 'core', 'component_fuzz' => true ) );
				}
			);
		} finally {
			\remove_filter( 'upgrader_pre_download', $pre_download, 10 );
		}

		self::record_failure_if(
			$failures,
			$remote_result['threw'] || $remote_result['value'] !== $local_package || true !== ( $seen_hook_extra['component_fuzz'] ?? null ),
			'WP_Upgrader.download_package.pre-download-short-circuit-preserves-hook-extra',
			array(
				'call'      => self::describe_call( $remote_result ),
				'hookExtra' => $seen_hook_extra,
			)
		);

		return self::row(
			$ctx,
			'update-install-upgrader.download-package.local-and-filtered-paths',
			$failures,
			array(
				'localPackage' => $local_package,
				'remote'       => $case['paths']['remotePackage'],
			)
		);
	}

	private static function check_install_package_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$events   = array();
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = self::initialized_upgrader( \WP_Upgrader::class, $skin );

		$pre_install = static function ( $response, array $hook_extra ) use ( &$events ) {
			$events[] = 'pre:' . ( $hook_extra['type'] ?? 'missing' ) . ':' . ( $hook_extra['action'] ?? 'missing' );
			return $response;
		};
		$source_selection = static function ( $source, string $remote_source, $filter_upgrader, array $hook_extra ) use ( $case, &$events ) {
			unset( $source, $filter_upgrader );

			$events[] = 'source:' . basename( rtrim( $remote_source, '/\\' ) ) . ':' . ( $hook_extra['type'] ?? 'missing' );
			return $case['paths']['installSelectedSource'];
		};
		$clear_destination = static function ( $removed, string $local_destination, string $remote_destination, array $hook_extra ) use ( &$events ) {
			$events[] = 'clear:' . ( \is_wp_error( $removed ) ? $removed->get_error_code() : ( true === $removed ? 'true' : 'other' ) ) . ':' . ( $hook_extra['action'] ?? 'missing' );
			return $removed;
		};
		$post_install = static function ( $response, array $hook_extra, array $result ) use ( &$events ) {
			$events[] = 'post:' . ( $hook_extra['type'] ?? 'missing' ) . ':' . basename( $result['destination'] ?? '' );
			return $response;
		};

		\add_filter( 'upgrader_pre_install', $pre_install, 10, 2 );
		\add_filter( 'upgrader_source_selection', $source_selection, 10, 4 );
		\add_filter( 'upgrader_clear_destination', $clear_destination, 10, 4 );
		\add_filter( 'upgrader_post_install', $post_install, 10, 3 );
		try {
			$result = self::call(
				static function () use ( $upgrader, $case ) {
					return $upgrader->install_package(
						array(
							'source'                      => $case['paths']['installRemoteSource'],
							'destination'                 => $case['paths']['installDestination'],
							'clear_destination'           => true,
							'clear_working'               => false,
							'abort_if_destination_exists' => false,
							'hook_extra'                  => array(
								'type'   => 'plugin',
								'action' => 'install',
							),
						)
					);
				}
			);
		} finally {
			\remove_filter( 'upgrader_pre_install', $pre_install, 10 );
			\remove_filter( 'upgrader_source_selection', $source_selection, 10 );
			\remove_filter( 'upgrader_clear_destination', $clear_destination, 10 );
			\remove_filter( 'upgrader_post_install', $post_install, 10 );
		}

		$installed_file = $case['paths']['installDestination'] . '/installed.php';
		$old_file       = $case['paths']['installDestination'] . '/old.txt';
		self::record_failure_if(
			$failures,
			$result['threw'] || ! is_array( $result['value'] ),
			'WP_Upgrader.install_package.success-return-array',
			array( 'call' => self::describe_call( $result ) )
		);
		if ( ! $result['threw'] && is_array( $result['value'] ) ) {
			self::record_failure_if(
				$failures,
				\trailingslashit( $result['value']['source'] ) !== \trailingslashit( $case['paths']['installSelectedSource'] )
					|| \untrailingslashit( $result['value']['destination'] ) !== \untrailingslashit( $case['paths']['installDestination'] )
					|| true !== $result['value']['clear_destination'],
				'WP_Upgrader.install_package.result-metadata-matches-source-selection',
				array( 'result' => $result['value'] )
			);
		}
		self::record_failure_if(
			$failures,
			! file_exists( $installed_file ) || file_exists( $old_file ),
			'WP_Upgrader.install_package.copies-selected-source-and-clears-destination',
			array(
				'installedExists' => file_exists( $installed_file ),
				'oldExists'       => file_exists( $old_file ),
			)
		);
		self::record_failure_if(
			$failures,
			array(
				'pre:plugin:install',
				'source:' . basename( $case['paths']['installRemoteSource'] ) . ':plugin',
				'clear:true:install',
				'post:plugin:' . basename( $case['paths']['installDestination'] ),
			) !== $events,
			'WP_Upgrader.install_package.hook-order-and-context',
			array( 'events' => $events )
		);

		$bad_request = self::call(
			static function () use ( $upgrader, $case ) {
				return $upgrader->install_package(
					array(
						'source'      => ' ' . $case['paths']['installRemoteSource'],
						'destination' => $case['paths']['installDestination'],
					)
				);
			}
		);
		self::record_failure_if(
			$failures,
			$bad_request['threw'] || ! self::wp_error_has_code( $bad_request['value'], 'bad_request' ),
			'WP_Upgrader.install_package.rejects-trimmed-or-empty-paths',
			array( 'call' => self::describe_call( $bad_request ) )
		);

		$empty_source = self::call(
			static function () use ( $upgrader, $case ) {
				return $upgrader->install_package(
					array(
						'source'                      => $case['paths']['emptySource'],
						'destination'                 => $case['paths']['emptyDestination'],
						'abort_if_destination_exists' => false,
					)
				);
			}
		);
		self::record_failure_if(
			$failures,
			$empty_source['threw'] || ! self::wp_error_has_code( $empty_source['value'], 'incompatible_archive_empty' ),
			'WP_Upgrader.install_package.empty-source-error',
			array( 'call' => self::describe_call( $empty_source ) )
		);

		return self::row(
			$ctx,
			'update-install-upgrader.install-package.hooks-copy-and-errors',
			$failures,
			array(
				'events'      => $events,
				'destination' => $case['paths']['installDestination'],
			)
		);
	}

	private static function check_plugin_theme_package_validation( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$plugin_upgrader = self::initialized_upgrader( \Plugin_Upgrader::class, new \Automatic_Upgrader_Skin() );
		$plugin_upgrader->install_strings();
		$plugin_valid = self::call(
			static function () use ( $plugin_upgrader, $case ) {
				return $plugin_upgrader->check_package( \trailingslashit( $case['paths']['pluginValidSource'] ) );
			}
		);
		self::record_failure_if(
			$failures,
			$plugin_valid['threw']
				|| ! is_string( $plugin_valid['value'] )
				|| \trailingslashit( $plugin_valid['value'] ) !== \trailingslashit( $case['paths']['pluginValidSource'] )
				|| ( $plugin_upgrader->new_plugin_data['Name'] ?? null ) !== $case['plugin']['name'],
			'Plugin_Upgrader.check_package.valid-plugin-source',
			array(
				'call' => self::describe_call( $plugin_valid ),
				'data' => $plugin_upgrader->new_plugin_data,
			)
		);

		$plugin_invalid = self::call(
			static function () use ( $plugin_upgrader, $case ) {
				return $plugin_upgrader->check_package( \trailingslashit( $case['paths']['pluginInvalidSource'] ) );
			}
		);
		self::record_failure_if(
			$failures,
			$plugin_invalid['threw'] || ! self::wp_error_has_code( $plugin_invalid['value'], 'incompatible_archive_no_plugins' ),
			'Plugin_Upgrader.check_package.invalid-plugin-source-error',
			array( 'call' => self::describe_call( $plugin_invalid ) )
		);

		$plugin_incompatible = self::call(
			static function () use ( $plugin_upgrader, $case ) {
				return $plugin_upgrader->check_package( \trailingslashit( $case['paths']['pluginIncompatibleSource'] ) );
			}
		);
		self::record_failure_if(
			$failures,
			$plugin_incompatible['threw'] || ! self::wp_error_has_code( $plugin_incompatible['value'], 'incompatible_php_required_version' ),
			'Plugin_Upgrader.check_package.incompatible-php-error',
			array( 'call' => self::describe_call( $plugin_incompatible ) )
		);

		$theme_upgrader = self::initialized_upgrader( \Theme_Upgrader::class, new \Automatic_Upgrader_Skin() );
		$theme_upgrader->install_strings();
		$theme_valid = self::call(
			static function () use ( $theme_upgrader, $case ) {
				return $theme_upgrader->check_package( \trailingslashit( $case['paths']['themeValidSource'] ) );
			}
		);
		self::record_failure_if(
			$failures,
			$theme_valid['threw']
				|| ! is_string( $theme_valid['value'] )
				|| \trailingslashit( $theme_valid['value'] ) !== \trailingslashit( $case['paths']['themeValidSource'] )
				|| ( $theme_upgrader->new_theme_data['Name'] ?? null ) !== $case['theme']['name'],
			'Theme_Upgrader.check_package.valid-theme-source',
			array(
				'call' => self::describe_call( $theme_valid ),
				'data' => $theme_upgrader->new_theme_data,
			)
		);

		$theme_missing_style = self::call(
			static function () use ( $theme_upgrader, $case ) {
				return $theme_upgrader->check_package( \trailingslashit( $case['paths']['themeMissingStyleSource'] ) );
			}
		);
		self::record_failure_if(
			$failures,
			$theme_missing_style['threw'] || ! self::wp_error_has_code( $theme_missing_style['value'], 'incompatible_archive_theme_no_style' ),
			'Theme_Upgrader.check_package.missing-style-error',
			array( 'call' => self::describe_call( $theme_missing_style ) )
		);

		$theme_missing_index = self::call(
			static function () use ( $theme_upgrader, $case ) {
				return $theme_upgrader->check_package( \trailingslashit( $case['paths']['themeMissingIndexSource'] ) );
			}
		);
		self::record_failure_if(
			$failures,
			$theme_missing_index['threw'] || ! self::wp_error_has_code( $theme_missing_index['value'], 'incompatible_archive_theme_no_index' ),
			'Theme_Upgrader.check_package.parent-theme-requires-index-or-template',
			array( 'call' => self::describe_call( $theme_missing_index ) )
		);

		$theme_child = self::call(
			static function () use ( $theme_upgrader, $case ) {
				return $theme_upgrader->check_package( \trailingslashit( $case['paths']['themeChildSource'] ) );
			}
		);
		self::record_failure_if(
			$failures,
			$theme_child['threw']
				|| ! is_string( $theme_child['value'] )
				|| \trailingslashit( $theme_child['value'] ) !== \trailingslashit( $case['paths']['themeChildSource'] )
				|| ( $theme_upgrader->new_theme_data['Template'] ?? null ) !== $case['theme']['parentSlug'],
			'Theme_Upgrader.check_package.child-theme-template-header-allows-no-index',
			array(
				'call' => self::describe_call( $theme_child ),
				'data' => $theme_upgrader->new_theme_data,
			)
		);

		return self::row(
			$ctx,
			'update-install-upgrader.plugin-theme-package-validation.errors-and-successes',
			$failures,
			array(
				'pluginName' => $case['plugin']['name'],
				'themeName'  => $case['theme']['name'],
			)
		);
	}

	private static function check_upgrade_no_update_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$old_installing = \wp_installing( true );
		try {
			$plugin_skin     = new \Automatic_Upgrader_Skin();
			$plugin_upgrader = new \Plugin_Upgrader( $plugin_skin );
			$plugin_result   = self::call(
				static function () use ( $plugin_upgrader, $case ) {
					return $plugin_upgrader->upgrade( $case['plugin']['missingFile'], array( 'clear_update_cache' => false ) );
				}
			);

			$theme_skin     = new \Automatic_Upgrader_Skin();
			$theme_upgrader = new \Theme_Upgrader( $theme_skin );
			$theme_result   = self::call(
				static function () use ( $theme_upgrader, $case ) {
					return $theme_upgrader->upgrade( $case['theme']['missingSlug'], array( 'clear_update_cache' => false ) );
				}
			);

			$core_upgrader = new \Core_Upgrader( new \Automatic_Upgrader_Skin() );
			$core_result   = self::call(
				static function () use ( $core_upgrader ) {
					return $core_upgrader->upgrade(
						(object) array(
							'response' => 'latest',
							'current'  => \wp_get_wp_version(),
						),
						array( 'pre_check_md5' => false )
					);
				}
			);
		} finally {
			\wp_installing( $old_installing );
		}

		self::record_failure_if(
			$failures,
			$plugin_result['threw'] || false !== $plugin_result['value'] || array() === $plugin_skin->get_upgrade_messages(),
			'Plugin_Upgrader.upgrade.no-response-is-up-to-date-false',
			array(
				'call'     => self::describe_call( $plugin_result ),
				'messages' => $plugin_skin->get_upgrade_messages(),
			)
		);
		self::record_failure_if(
			$failures,
			$theme_result['threw'] || false !== $theme_result['value'] || array() === $theme_skin->get_upgrade_messages(),
			'Theme_Upgrader.upgrade.no-response-is-up-to-date-false',
			array(
				'call'     => self::describe_call( $theme_result ),
				'messages' => $theme_skin->get_upgrade_messages(),
			)
		);
		self::record_failure_if(
			$failures,
			$core_result['threw'] || ! self::wp_error_has_code( $core_result['value'], 'up_to_date' ),
			'Core_Upgrader.upgrade.latest-offer-is-up-to-date-error',
			array( 'call' => self::describe_call( $core_result ) )
		);

		return self::row(
			$ctx,
			'update-install-upgrader.upgrade.no-update-paths-avoid-filesystem-work',
			$failures,
			array(
				'plugin' => $case['plugin']['missingFile'],
				'theme'  => $case['theme']['missingSlug'],
			)
		);
	}

	private static function check_auto_update_decisions( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$updater  = new \WP_Automatic_Updater();

		$old_installing = \wp_installing( false );
		try {
			self::$file_mod_allowed              = false;
			self::$automatic_updater_disabled   = false;
			$file_mod_disabled                  = $updater->is_disabled();
			self::$file_mod_allowed              = true;
			\wp_installing( true );
			$installing_disabled                = $updater->is_disabled();
			\wp_installing( false );
			self::$automatic_updater_disabled   = true;
			$filter_disabled                    = $updater->is_disabled();
			self::$automatic_updater_disabled   = false;
			self::$vcs_checkout                 = false;
			self::$plugins_auto_update_enabled  = true;
			self::$themes_auto_update_enabled   = true;
			self::$auto_update_overrides        = array();

			$plugin_item = (object) array(
				'plugin'       => $case['plugin']['file'],
				'slug'         => $case['plugin']['slug'],
				'new_version'  => $case['plugin']['newVersion'],
				'package'      => $case['paths']['remotePackage'],
				'autoupdate'   => false,
				'requires_php' => '5.6',
			);
			$theme_item  = (object) array(
				'theme'        => $case['theme']['slug'],
				'new_version'  => $case['theme']['newVersion'],
				'package'      => $case['paths']['remotePackage'],
				'autoupdate'   => false,
				'requires_php' => '5.6',
			);

			$plugin_site_opt_in = self::call(
				static function () use ( $updater, $plugin_item, $case ) {
					return $updater->should_update( 'plugin', $plugin_item, $case['paths']['wpContent'] );
				}
			);
			$theme_site_opt_in  = self::call(
				static function () use ( $updater, $theme_item, $case ) {
					return $updater->should_update( 'theme', $theme_item, $case['paths']['wpContent'] );
				}
			);

			$disabled_plugin_item                       = clone $plugin_item;
			$disabled_plugin_item->disable_autoupdate   = true;
			self::$auto_update_overrides['plugin']      = false;
			$plugin_filter_denied                       = self::call(
				static function () use ( $updater, $disabled_plugin_item, $case ) {
					return $updater->should_update( 'plugin', $disabled_plugin_item, $case['paths']['wpContent'] );
				}
			);
			self::$auto_update_overrides['plugin']      = true;
			$plugin_filter_overrode_disable             = self::call(
				static function () use ( $updater, $disabled_plugin_item, $case ) {
					return $updater->should_update( 'plugin', $disabled_plugin_item, $case['paths']['wpContent'] );
				}
			);
			$incompatible_plugin_item                   = clone $plugin_item;
			$incompatible_plugin_item->autoupdate       = true;
			$incompatible_plugin_item->requires_php     = '99.0';
			self::$auto_update_overrides['plugin']      = true;
			$plugin_php_incompatible                    = self::call(
				static function () use ( $updater, $incompatible_plugin_item, $case ) {
					return $updater->should_update( 'plugin', $incompatible_plugin_item, $case['paths']['wpContent'] );
				}
			);
			self::$auto_update_overrides                = array();
		} finally {
			\wp_installing( $old_installing );
		}

		self::record_failure_if(
			$failures,
			true !== $file_mod_disabled || true !== $installing_disabled || true !== $filter_disabled,
			'WP_Automatic_Updater.is_disabled.file-mod-installing-filter-guards',
			array(
				'fileMod'    => $file_mod_disabled,
				'installing' => $installing_disabled,
				'filter'     => $filter_disabled,
			)
		);
		self::record_failure_if(
			$failures,
			$plugin_site_opt_in['threw'] || true !== $plugin_site_opt_in['value'],
			'WP_Automatic_Updater.should_update.plugin-site-option-opt-in',
			array( 'call' => self::describe_call( $plugin_site_opt_in ) )
		);
		self::record_failure_if(
			$failures,
			$theme_site_opt_in['threw'] || true !== $theme_site_opt_in['value'],
			'WP_Automatic_Updater.should_update.theme-site-option-opt-in',
			array( 'call' => self::describe_call( $theme_site_opt_in ) )
		);
		self::record_failure_if(
			$failures,
			$plugin_filter_denied['threw'] || false !== $plugin_filter_denied['value'],
			'WP_Automatic_Updater.should_update.dynamic-filter-can-deny',
			array( 'call' => self::describe_call( $plugin_filter_denied ) )
		);
		self::record_failure_if(
			$failures,
			$plugin_filter_overrode_disable['threw'] || true !== $plugin_filter_overrode_disable['value'],
			'WP_Automatic_Updater.should_update.dynamic-filter-can-override-disable-flag',
			array( 'call' => self::describe_call( $plugin_filter_overrode_disable ) )
		);
		self::record_failure_if(
			$failures,
			$plugin_php_incompatible['threw'] || false !== $plugin_php_incompatible['value'],
			'WP_Automatic_Updater.should_update.php-requirement-still-gates-filter-allow',
			array( 'call' => self::describe_call( $plugin_php_incompatible ) )
		);

		return self::row(
			$ctx,
			'update-install-upgrader.auto-update.decision-filters-and-guards',
			$failures,
			array(
				'plugin' => $case['plugin']['file'],
				'theme'  => $case['theme']['slug'],
			)
		);
	}

	private static function check_core_update_decisions( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures   = array();
		$wp_version = \wp_get_wp_version();
		$parts      = preg_split( '/[.-]/', $wp_version );
		$major      = max( 1, (int) ( $parts[0] ?? 6 ) );
		$minor      = max( 0, (int) ( $parts[1] ?? 0 ) );
		$patch      = max( 0, (int) ( $parts[2] ?? 0 ) );
		$same       = $wp_version;
		$older      = max( 1, $major - 1 ) . '.0.0';
		$next_minor = $major . '.' . $minor . '.' . ( $patch + 1 );
		$next_major = ( $major + 1 ) . '.0.0';

		self::$site_options['auto_update_core_minor'] = 'enabled';
		self::$site_options['auto_update_core_major'] = 'disabled';
		self::$site_options['auto_update_core_dev']   = 'enabled';

		$same_result       = self::call(
			static function () use ( $same ) {
				return \Core_Upgrader::should_update_to_version( $same );
			}
		);
		$older_result      = self::call(
			static function () use ( $older ) {
				return \Core_Upgrader::should_update_to_version( $older );
			}
		);
		$minor_result      = self::call(
			static function () use ( $next_minor ) {
				return \Core_Upgrader::should_update_to_version( $next_minor );
			}
		);
		$major_denied      = self::call(
			static function () use ( $next_major ) {
				return \Core_Upgrader::should_update_to_version( $next_major );
			}
		);
		$allow_major       = static function (): bool {
			return true;
		};
		\add_filter( 'allow_major_auto_core_updates', $allow_major );
		try {
			$major_allowed = self::call(
				static function () use ( $next_major ) {
					return \Core_Upgrader::should_update_to_version( $next_major );
				}
			);
		} finally {
			\remove_filter( 'allow_major_auto_core_updates', $allow_major );
		}

		self::record_failure_if(
			$failures,
			$same_result['threw'] || false !== $same_result['value'],
			'Core_Upgrader.should_update_to_version.same-version-false',
			array( 'call' => self::describe_call( $same_result ) )
		);
		self::record_failure_if(
			$failures,
			$older_result['threw'] || false !== $older_result['value'],
			'Core_Upgrader.should_update_to_version.older-version-false',
			array( 'call' => self::describe_call( $older_result ) )
		);
		self::record_failure_if(
			$failures,
			$minor_result['threw'] || true !== $minor_result['value'],
			'Core_Upgrader.should_update_to_version.minor-enabled-true',
			array(
				'offered' => $next_minor,
				'call'    => self::describe_call( $minor_result ),
			)
		);
		self::record_failure_if(
			$failures,
			$major_denied['threw'] || false !== $major_denied['value'],
			'Core_Upgrader.should_update_to_version.major-disabled-false',
			array(
				'offered' => $next_major,
				'call'    => self::describe_call( $major_denied ),
			)
		);
		self::record_failure_if(
			$failures,
			$major_allowed['threw'] || true !== $major_allowed['value'],
			'Core_Upgrader.should_update_to_version.major-filter-allow-true',
			array(
				'offered' => $next_major,
				'call'    => self::describe_call( $major_allowed ),
			)
		);

		return self::row(
			$ctx,
			'update-install-upgrader.core-auto-update.version-policy',
			$failures,
			array(
				'current'   => $wp_version,
				'minorNext' => $next_minor,
				'majorNext' => $next_major,
			)
		);
	}

	private static function check_maintenance_mode_guard( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = self::initialized_upgrader( \WP_Upgrader::class, $skin );
		$file     = $case['paths']['tempRoot'] . '/.maintenance';

		$enable = self::call(
			static function () use ( $upgrader ) {
				$upgrader->maintenance_mode( true );
				return true;
			}
		);
		$exists_after_enable   = file_exists( $file );
		$contents_after_enable = $exists_after_enable ? (string) file_get_contents( $file ) : '';
		$disable               = self::call(
			static function () use ( $upgrader ) {
				$upgrader->maintenance_mode( false );
				return true;
			}
		);

		self::record_failure_if(
			$failures,
			$enable['threw'] || ! $exists_after_enable || ! str_contains( $contents_after_enable, '$upgrading' ),
			'WP_Upgrader.maintenance_mode.enable-writes-temp-maintenance-file',
			array(
				'enable'   => self::describe_call( $enable ),
				'exists'   => $exists_after_enable,
				'contents' => $contents_after_enable,
			)
		);
		self::record_failure_if(
			$failures,
			$disable['threw'] || file_exists( $file ),
			'WP_Upgrader.maintenance_mode.disable-removes-temp-maintenance-file',
			array(
				'disable' => self::describe_call( $disable ),
				'exists'  => file_exists( $file ),
			)
		);
		self::record_failure_if(
			$failures,
			file_exists( \ABSPATH . '.maintenance' ),
			'WP_Upgrader.maintenance_mode.does-not-touch-real-abspath',
			array( 'realPath' => \ABSPATH . '.maintenance' )
		);

		return self::row(
			$ctx,
			'update-install-upgrader.maintenance-mode.temp-filesystem-only',
			$failures,
			array( 'maintenanceFile' => $file )
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$used        = array();
		$plugin_slug = self::slug( $ctx->fork( 'plugin-slug' ), 'cfz-plugin', $used );
		$theme_slug  = self::slug( $ctx->fork( 'theme-slug' ), 'cfz-theme', $used );
		$parent_slug = self::slug( $ctx->fork( 'parent-theme-slug' ), 'cfz-parent', $used );
		$suffix      = $ctx->identifier( 4, 10 ) . '-' . $ctx->int( 10, 99 );

		$plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';
		$wp_content  = $temp_root . '/wp-content';
		$paths       = array(
			'tempRoot'                => $temp_root,
			'wpContent'               => $wp_content,
			'localPackage'            => $temp_root . '/packages/' . $plugin_slug . '.zip',
			'remotePackage'           => 'https://downloads.example.test/' . $plugin_slug . '.zip',
			'installRemoteSource'     => $temp_root . '/install/remote-source',
			'installSelectedSource'   => $temp_root . '/install/selected-source',
			'installDestination'      => $temp_root . '/install/destination',
			'emptySource'             => $temp_root . '/install/empty-source',
			'emptyDestination'        => $temp_root . '/install/empty-destination',
			'pluginValidSource'       => $temp_root . '/validation/plugin-valid',
			'pluginInvalidSource'     => $temp_root . '/validation/plugin-invalid',
			'pluginIncompatibleSource' => $temp_root . '/validation/plugin-incompatible',
			'themeValidSource'        => $temp_root . '/validation/theme-valid',
			'themeMissingStyleSource' => $temp_root . '/validation/theme-missing-style',
			'themeMissingIndexSource' => $temp_root . '/validation/theme-missing-index',
			'themeChildSource'        => $temp_root . '/validation/theme-child',
		);

		$plugin_name = 'Component Fuzz Plugin ' . $suffix;
		$theme_name  = 'Component Fuzz Theme ' . $suffix;

		return array(
			'labels'     => array(
				'suffix' => $suffix,
			),
			'plugin'    => array(
				'slug'        => $plugin_slug,
				'file'        => $plugin_file,
				'missingFile' => $plugin_slug . '/missing.php',
				'name'        => $plugin_name,
				'version'     => self::version( $ctx->fork( 'plugin-version' ), 1 ),
				'newVersion'  => self::version( $ctx->fork( 'plugin-new-version' ), 2 ),
			),
			'theme'     => array(
				'slug'        => $theme_slug,
				'missingSlug' => $theme_slug . '-missing',
				'parentSlug'  => $parent_slug,
				'name'        => $theme_name,
				'version'     => self::version( $ctx->fork( 'theme-version' ), 1 ),
				'newVersion'  => self::version( $ctx->fork( 'theme-new-version' ), 2 ),
			),
			'paths'     => $paths,
			'updates'   => self::update_case( $ctx, $plugin_file, $plugin_slug, $theme_slug, $plugin_name, $theme_name ),
			'autoUpdate' => array(
				'sitePluginOptIns' => array( $plugin_file ),
				'siteThemeOptIns'  => array( $theme_slug ),
				'coreMajorOption'  => 'disabled',
				'coreMinorOption'  => 'enabled',
				'coreDevOption'    => 'enabled',
			),
		);
	}

	private static function update_case(
		\ComponentFuzz\FuzzContext $ctx,
		string $plugin_file,
		string $plugin_slug,
		string $theme_slug,
		string $plugin_name,
		string $theme_name
	): array {
		$current_version = \wp_get_wp_version();
		$core_updates    = array(
			(object) array(
				'response'        => 'upgrade',
				'download'        => 'https://downloads.example.test/wordpress.zip',
				'locale'          => 'en_US',
				'packages'        => (object) array(
					'full'        => 'https://downloads.example.test/wordpress-full.zip',
					'no_content'  => false,
					'new_bundled' => false,
					'partial'     => false,
					'rollback'    => false,
				),
				'current'         => self::future_wp_version( $current_version, 0, 0, 1 ),
				'version'         => self::future_wp_version( $current_version, 0, 0, 1 ),
				'php_version'     => '5.6',
				'mysql_version'   => '5.5',
				'new_bundled'     => false,
				'partial_version' => $current_version,
			),
		);
		if ( $ctx->bool( 35 ) ) {
			$core_updates[] = (object) array(
				'response'        => 'autoupdate',
				'download'        => 'https://downloads.example.test/wordpress-auto.zip',
				'locale'          => 'en_US',
				'packages'        => (object) array(
					'full'        => 'https://downloads.example.test/wordpress-auto-full.zip',
					'no_content'  => false,
					'new_bundled' => false,
					'partial'     => false,
					'rollback'    => false,
				),
				'current'         => self::future_wp_version( $current_version, 0, 1, 0 ),
				'version'         => self::future_wp_version( $current_version, 0, 1, 0 ),
				'php_version'     => '5.6',
				'mysql_version'   => '5.5',
				'new_bundled'     => false,
				'partial_version' => $current_version,
				'notify_email'    => false,
			);
		}

		return array(
			'coreTransient'   => (object) array(
				'last_checked' => time(),
				'version_checked' => $current_version,
				'updates'      => $core_updates,
			),
			'pluginTransient' => (object) array(
				'last_checked' => time(),
				'checked'      => array(
					$plugin_file => '1.0.0',
				),
				'response'     => array(
					$plugin_file => (object) array(
						'id'            => 'w.org/plugins/' . $plugin_slug,
						'slug'          => $plugin_slug,
						'plugin'        => $plugin_file,
						'new_version'   => '2.' . $ctx->int( 0, 9 ) . '.0',
						'url'           => 'https://example.test/plugins/' . $plugin_slug,
						'package'       => 'https://downloads.example.test/' . $plugin_slug . '.zip',
						'requires'      => '5.0',
						'requires_php'  => '5.6',
						'icons'         => array(),
						'banners'       => array(),
						'banners_rtl'   => array(),
						'tested'        => $current_version,
						'component_fuzz_name' => $plugin_name,
					),
				),
				'no_update'    => array(),
			),
			'themeTransient'  => (object) array(
				'last_checked' => time(),
				'checked'      => array(
					$theme_slug => '1.0.0',
				),
				'response'     => array(
					$theme_slug => array(
						'theme'        => $theme_slug,
						'new_version'  => '2.' . $ctx->int( 0, 9 ) . '.0',
						'url'          => 'https://example.test/themes/' . $theme_slug,
						'package'      => 'https://downloads.example.test/' . $theme_slug . '.zip',
						'requires'     => '5.0',
						'requires_php' => '5.6',
						'component_fuzz_name' => $theme_name,
					),
				),
				'no_update'    => array(),
			),
		);
	}

	private static function count_visible_core_updates( array $updates ): int {
		$count = 0;

		foreach ( $updates as $update ) {
			if ( isset( $update->response ) && 'autoupdate' === $update->response ) {
				continue;
			}

			++$count;
		}

		return $count;
	}

	private static function write_case_files( array $case ): void {
		$paths = $case['paths'];

		foreach (
			array(
				$paths['wpContent'],
				$paths['wpContent'] . '/plugins',
				$paths['wpContent'] . '/themes',
				$paths['wpContent'] . '/upgrade',
				$paths['installDestination'],
				$paths['emptySource'],
				$paths['emptyDestination'],
				$paths['themeMissingStyleSource'],
			) as $dir
		) {
			self::ensure_dir( $dir );
		}

		self::write_file( $paths['localPackage'], 'component fuzz package placeholder' );
		self::write_file( $paths['installRemoteSource'] . '/outer/ignored.php', "<?php\n// ignored by source-selection filter\n" );
		self::write_file( $paths['installSelectedSource'] . '/installed.php', "<?php\n// installed by component fuzz\n" );
		self::write_file( $paths['installSelectedSource'] . '/assets/readme.txt', "selected source\n" );
		self::write_file( $paths['installDestination'] . '/old.txt', "old destination content\n" );

		self::write_file(
			$paths['pluginValidSource'] . '/' . $case['plugin']['slug'] . '.php',
			self::plugin_header( $case['plugin']['name'], $case['plugin']['version'], '5.6', '5.0' )
		);
		self::write_file( $paths['pluginInvalidSource'] . '/readme.txt', "No plugin headers here.\n" );
		self::write_file(
			$paths['pluginIncompatibleSource'] . '/' . $case['plugin']['slug'] . '-future.php',
			self::plugin_header( $case['plugin']['name'] . ' Future', $case['plugin']['version'], '99.0', '5.0' )
		);

		self::write_file(
			$paths['themeValidSource'] . '/style.css',
			self::theme_header( $case['theme']['name'], $case['theme']['version'], '', '5.6', '5.0' )
		);
		self::write_file( $paths['themeValidSource'] . '/templates/index.html', '<!-- wp:paragraph --><p>Theme</p><!-- /wp:paragraph -->' );

		self::write_file(
			$paths['themeMissingIndexSource'] . '/style.css',
			self::theme_header( $case['theme']['name'] . ' No Index', $case['theme']['version'], '', '5.6', '5.0' )
		);

		self::write_file(
			$paths['themeChildSource'] . '/style.css',
			self::theme_header( $case['theme']['name'] . ' Child', $case['theme']['version'], $case['theme']['parentSlug'], '5.6', '5.0' )
		);
	}

	private static function plugin_header( string $name, string $version, string $requires_php, string $requires_wp ): string {
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: {$name}\n"
			. " * Version: {$version}\n"
			. " * Requires at least: {$requires_wp}\n"
			. " * Requires PHP: {$requires_php}\n"
			. " */\n";
	}

	private static function theme_header(
		string $name,
		string $version,
		string $template,
		string $requires_php,
		string $requires_wp
	): string {
		$header = "/*\n"
			. "Theme Name: {$name}\n"
			. "Version: {$version}\n"
			. "Requires at least: {$requires_wp}\n"
			. "Requires PHP: {$requires_php}\n";
		if ( '' !== $template ) {
			$header .= "Template: {$template}\n";
		}
		return $header . "*/\n";
	}

	private static function initialized_upgrader( string $class, ?\WP_Upgrader_Skin $skin = null ): \WP_Upgrader {
		$skin     = $skin ?? new \Automatic_Upgrader_Skin();
		$upgrader = new $class( $skin );

		$old_installing = \wp_installing( true );
		try {
			$upgrader->init();
		} finally {
			\wp_installing( $old_installing );
		}

		return $upgrader;
	}

	private static function prepare_temp_filesystem( string $temp_root ): void {
		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', 0755 );
		}
		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', 0644 );
		}

		self::ensure_dir( $temp_root . '/wp-content/plugins' );
		self::ensure_dir( $temp_root . '/wp-content/themes' );
		self::ensure_dir( $temp_root . '/wp-content/upgrade' );

		$GLOBALS['wp_filesystem'] = new class( $temp_root ) extends \WP_Filesystem_Direct {
			private string $root;

			public function __construct( string $root ) {
				parent::__construct( null );
				$this->root = \trailingslashit( \wp_normalize_path( $root ) );
			}

			public function abspath() {
				return $this->root;
			}

			public function wp_content_dir() {
				return $this->root . 'wp-content/';
			}

			public function wp_plugins_dir() {
				return $this->root . 'wp-content/plugins/';
			}

			public function wp_themes_dir( $theme = false ) {
				unset( $theme );

				return $this->root . 'wp-content/themes/';
			}
		};
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): string {
		$base = \sys_get_temp_dir() . '/component-fuzz-update-install-upgrader-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . getmypid();
		if ( file_exists( $base ) && ! self::remove_dir_recursive( $base ) ) {
			throw new \RuntimeException( 'Could not clear stale temp root: ' . $base );
		}

		self::ensure_dir( $base );
		return $base;
	}

	private static function ensure_dir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create directory: ' . $dir );
		}
	}

	private static function write_file( string $path, string $contents ): void {
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

	private static function reset_static_state(): void {
		self::$site_transients                = array();
		self::$site_options                   = array();
		self::$file_mod_allowed               = null;
		self::$vcs_checkout                   = null;
		self::$automatic_updater_disabled     = null;
		self::$plugins_auto_update_enabled    = null;
		self::$themes_auto_update_enabled     = null;
		self::$auto_update_overrides          = array();
		self::$granted_caps                   = array();
		self::$http_request_count             = 0;

		if ( function_exists( 'wp_cache_delete' ) ) {
			\wp_cache_delete( 'plugins', 'plugins' );
			\wp_cache_delete( 'themes', 'themes' );
			\wp_cache_delete( 'theme_roots', 'site-transient' );
		}
	}

	private static function snapshot_state(): array {
		$snapshot = array(
			'installing' => function_exists( 'wp_installing' ) ? \wp_installing() : null,
			'globals'    => array(),
		);

		foreach (
			array(
				'wp_filter',
				'wp_filters',
				'wp_actions',
				'wp_current_filter',
				'wp_object_cache',
				'wp_filesystem',
				'wp_theme_directories',
				'_SERVER',
				'_GET',
				'_POST',
				'_REQUEST',
				'pagenow',
			) as $name
		) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_state( array $snapshot ): void {
		if ( function_exists( 'wp_installing' ) && null !== $snapshot['installing'] ) {
			\wp_installing( (bool) $snapshot['installing'] );
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		if ( function_exists( 'wp_installing' ) && null !== $snapshot['installing'] && \wp_installing() !== (bool) $snapshot['installing'] ) {
			return false;
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function clone_value( $value ) {
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

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failureCount' => count( $failures ),
				'failures'     => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function record_failure_if( array &$failures, bool $failed, string $check, array $data = array() ): void {
		if ( ! $failed || count( $failures ) >= self::MAX_FAILURES ) {
			return;
		}

		$failures[] = array(
			'check' => $check,
			'data'  => self::compact_data( $data ),
		);
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
			);
		}
	}

	private static function wp_error_has_code( $value, string $code ): bool {
		return \is_wp_error( $value ) && in_array( $code, $value->get_error_codes(), true );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix, array &$used ): string {
		for ( $attempt = 0; $attempt < 20; ++$attempt ) {
			$slug = strtolower( $prefix . '-' . $ctx->identifier( 3, 12 ) . '-' . $ctx->int( 1, 999 ) );
			$slug = preg_replace( '/[^a-z0-9-]+/', '-', $slug );
			$slug = trim( (string) $slug, '-' );

			if ( '' !== $slug && ! isset( $used[ $slug ] ) ) {
				$used[ $slug ] = true;
				return $slug;
			}
		}

		$slug          = $prefix . '-' . count( $used );
		$used[ $slug ] = true;
		return $slug;
	}

	private static function version( \ComponentFuzz\FuzzContext $ctx, int $major ): string {
		return $major . '.' . $ctx->int( 0, 9 ) . '.' . $ctx->int( 0, 20 );
	}

	private static function future_wp_version( string $current, int $major_delta, int $minor_delta, int $patch_delta ): string {
		$parts = preg_split( '/[.-]/', $current );
		$major = max( 1, (int) ( $parts[0] ?? 6 ) ) + $major_delta;
		$minor = max( 0, (int) ( $parts[1] ?? 0 ) ) + $minor_delta;
		$patch = max( 0, (int) ( $parts[2] ?? 0 ) ) + $patch_delta;

		return $major . '.' . $minor . '.' . $patch;
	}

	private static function describe_call( array $call ): array {
		return array(
			'threw'     => (bool) ( $call['threw'] ?? false ),
			'value'     => self::describe_value( $call['value'] ?? null ),
			'throwable' => $call['throwable'] ?? null,
		);
	}

	private static function describe_value( $value ) {
		if ( \is_wp_error( $value ) ) {
			return array(
				'type'     => 'WP_Error',
				'codes'    => $value->get_error_codes(),
				'messages' => $value->get_error_messages(),
			);
		}
		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}
		if ( is_array( $value ) ) {
			return self::compact_data( $value );
		}
		if ( is_string( $value ) ) {
			return self::preview( $value );
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

	private static function compact_data( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::compact_data( $value );
			} elseif ( is_object( $value ) ) {
				$data[ $key ] = self::describe_value( $value );
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = self::preview( $value );
			}
		}

		return $data;
	}

	private static function preview( ?string $value, int $limit = self::PREVIEW_BYTES ): string {
		$value = (string) $value;

		$printable = preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
			static function ( array $m ): string {
				return sprintf( '\\x%02X', ord( $m[0] ) );
			},
			$value
		);

		if ( strlen( $printable ) > $limit ) {
			return substr( $printable, 0, $limit ) . '...';
		}

		return $printable;
	}
}
