<?php
namespace ComponentFuzz\Surfaces;

final class PluginThemeLifecycleSurface {
	public const NAME = 'plugin-theme-lifecycle';

	private const MAX_FAILURES = 12;
	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'plugin-theme-lifecycle.bootstrap-apis-available',
					'Required WordPress plugin/theme lifecycle APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();
		$case     = null;
		$cleanup  = null;

		try {
			$case = self::case_for_context( $ctx );
			self::prepare_sandbox( $case );
			self::write_case_files( $case );
			self::install_theme_root( $case );
			self::clear_runtime_caches();

			$rows[] = self::check_plugin_header_path_hook_invariants( $ctx, $case );
			$rows[] = self::check_plugin_validation_requirements( $ctx, $case );
			$rows[] = self::check_plugin_activation_deactivation( $ctx, $case );
			$rows[] = self::check_plugin_network_delete_paths( $ctx, $case );
			$rows[] = self::check_theme_root_stylesheet_template_normalization( $ctx, $case );
			$rows[] = self::check_theme_enumeration_requirements( $ctx, $case );
			$rows[] = self::check_theme_switch_support_globals( $ctx, $case );
			$rows[] = self::check_theme_delete_paths( $ctx, $case );
			$rows[] = self::check_rest_plugin_theme_read_paths( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'plugin-theme-lifecycle.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $case ) {
				self::clear_runtime_caches();
				$cleanup = self::cleanup_case_files( $case );
			}
			self::restore_state( $snapshot );
		}

		if ( null !== $case ) {
			$rows[] = $ctx->result(
				'plugin-theme-lifecycle.temp-fixtures-cleaned',
				true === $cleanup,
				array(
					'pluginRoot' => self::preview( $case['paths']['pluginRoot'] ),
					'themeRoot'  => self::preview( $case['paths']['themeRoot'] ),
					'cleaned'    => true === $cleanup,
					'leftovers'  => self::fixture_leftovers( $case ),
				)
			);
		}

		$rows[] = $ctx->result(
			'plugin-theme-lifecycle.state-restored',
			self::state_restored( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedStatics' => array_keys( $snapshot['statics'] ),
				'optionsTracked' => count( $snapshot['options'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'get_plugins',
				'get_plugin_data',
				'plugin_basename',
				'plugin_dir_path',
				'validate_plugin',
				'validate_plugin_requirements',
				'is_plugin_active',
				'is_plugin_active_for_network',
				'is_network_only_plugin',
				'activate_plugin',
				'deactivate_plugins',
				'delete_plugins',
				'plugin_sandbox_scrape',
				'wp_clean_plugins_cache',
				'wp_get_theme',
				'wp_get_themes',
				'validate_theme_requirements',
				'switch_theme',
				'delete_theme',
				'register_theme_directory',
				'search_theme_directories',
				'get_raw_theme_root',
				'get_theme_root',
				'wp_clean_themes_cache',
				'wp_set_template_globals',
				'add_theme_support',
				'remove_theme_support',
				'current_theme_supports',
				'get_theme_support',
				'get_stylesheet_directory',
				'get_template_directory',
				'get_stylesheet',
				'get_template',
				'update_option',
				'delete_option',
				'get_option',
				'update_site_option',
				'get_site_option',
				'delete_site_option',
				'is_wp_error',
				'wp_normalize_path',
				'wp_cache_delete',
				'add_filter',
				'remove_filter',
				'add_action',
				'has_action',
				'remove_action',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach (
			array(
				'WP_Error',
				'WP_Theme',
				'WP_Plugin_Dependencies',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Plugins_Controller',
				'WP_REST_Themes_Controller',
				'WP_Filesystem_Direct',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_plugin_header_path_hook_invariants( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::clear_runtime_caches();

		$good     = $case['plugins']['good'];
		$implicit = $case['plugins']['implicitTextDomain'];
		$late     = $case['plugins']['lateHeader'];

		$good_data     = \get_plugin_data( $good['path'], false, false );
		$implicit_data = \get_plugin_data( $implicit['path'], false, false );
		$late_data     = \get_plugin_data( $late['path'], false, false );
		$installed     = \get_plugins();

		if (
			( $good_data['Name'] ?? null ) !== $good['name']
			|| ( $good_data['Version'] ?? null ) !== $good['version']
			|| ( $good_data['TextDomain'] ?? null ) !== $good['slug']
			|| ( $good_data['Title'] ?? null ) !== $good['name']
			|| ( $good_data['AuthorName'] ?? null ) !== 'Component Fuzz'
		) {
			self::record_failure(
				$failures,
				'get_plugin_data.raw-header-fields-match-temp-plugin',
				array(
					'expectedName'    => $good['name'],
					'expectedVersion' => $good['version'],
					'expectedDomain'  => $good['slug'],
					'actual'          => $good_data,
				)
			);
		}

		if (
			( $implicit_data['Name'] ?? null ) !== $implicit['name']
			|| ( $implicit_data['TextDomain'] ?? null ) !== $implicit['slug']
			|| ! isset( $installed[ $implicit['file'] ] )
		) {
			self::record_failure(
				$failures,
				'get_plugin_data.falls-back-to-directory-text-domain',
				array(
					'plugin' => $implicit['file'],
					'data'   => $implicit_data,
				)
			);
		}

		if (
			'' !== ( $late_data['Name'] ?? '' )
			|| isset( $installed[ $late['file'] ] )
		) {
			self::record_failure(
				$failures,
				'get_plugin_data.ignores-plugin-header-after-initial-read-window',
				array(
					'plugin'    => $late['file'],
					'data'      => $late_data,
					'installed' => isset( $installed[ $late['file'] ] ),
				)
			);
		}
		self::expect_plugin_validation_code( $failures, 'late-header-plugin', $late['file'], 'no_plugin_header' );

		$backslash_path = str_replace( '/', '\\', $good['path'] );
		if (
			\plugin_basename( $good['path'] ) !== $good['file']
			|| \plugin_basename( $backslash_path ) !== $good['file']
			|| \plugin_dir_path( $good['path'] ) !== \trailingslashit( $good['dir'] )
		) {
			self::record_failure(
				$failures,
				'plugin-path-helpers.normalize-absolute-and-backslash-paths',
				array(
					'plugin'         => $good['file'],
					'basename'       => \plugin_basename( $good['path'] ),
					'backslashBase'  => \plugin_basename( $backslash_path ),
					'dirPath'        => \plugin_dir_path( $good['path'] ),
					'expectedDirPath' => \trailingslashit( $good['dir'] ),
				)
			);
		}

		$activation_hook      = 'activate_' . $good['file'];
		$deactivation_hook    = 'deactivate_' . $good['file'];
		$absolute_activation  = 'activate_' . $good['path'];
		$absolute_deactivate  = 'deactivate_' . $good['path'];
		$activation_callback  = static function (): void {};
		$deactivation_callback = static function (): void {};

		\register_activation_hook( $good['path'], $activation_callback );
		\register_deactivation_hook( $good['path'], $deactivation_callback );
		try {
			if (
				10 !== \has_action( $activation_hook, $activation_callback )
				|| 10 !== \has_action( $deactivation_hook, $deactivation_callback )
				|| false !== \has_action( $absolute_activation, $activation_callback )
				|| false !== \has_action( $absolute_deactivate, $deactivation_callback )
			) {
				self::record_failure(
					$failures,
					'register-plugin-lifecycle-hooks.use-plugin-basename-hook-locality',
					array(
						'activationHook'        => $activation_hook,
						'activationPriority'    => \has_action( $activation_hook, $activation_callback ),
						'deactivationHook'      => $deactivation_hook,
						'deactivationPriority'  => \has_action( $deactivation_hook, $deactivation_callback ),
						'absoluteActivationHit' => \has_action( $absolute_activation, $activation_callback ),
						'absoluteDeactivateHit' => \has_action( $absolute_deactivate, $deactivation_callback ),
					)
				);
			}
		} finally {
			\remove_action( $activation_hook, $activation_callback, 10 );
			\remove_action( $deactivation_hook, $deactivation_callback, 10 );
		}

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.plugin.header-path-and-hook-invariants',
			$failures,
			array(
				'plugin'        => $good['file'],
				'implicitDomain' => $implicit['file'],
				'lateHeader'    => $late['file'],
				'installedCount' => count( $installed ),
			)
		);
	}

	private static function check_plugin_validation_requirements( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::clear_runtime_caches();

		$installed = \get_plugins();
		foreach ( array( 'good', 'dependency', 'dependent', 'futureWp', 'futurePhp' ) as $key ) {
			if ( ! isset( $installed[ $case['plugins'][ $key ]['file'] ] ) ) {
				self::record_failure(
					$failures,
					"get_plugins.includes-{$key}-fixture",
					array(
						'plugin' => $case['plugins'][ $key ]['file'],
						'keys'   => array_keys( $installed ),
					)
				);
			}
		}

		if ( isset( $installed[ $case['plugins']['headerless']['file'] ] ) ) {
			self::record_failure(
				$failures,
				'get_plugins.skips-plugin-without-header',
				array( 'plugin' => $case['plugins']['headerless']['file'] )
			);
		}
		if ( isset( $installed[ $case['plugins']['lateHeader']['file'] ] ) ) {
			self::record_failure(
				$failures,
				'get_plugins.skips-plugin-with-header-after-initial-read-window',
				array( 'plugin' => $case['plugins']['lateHeader']['file'] )
			);
		}

		self::expect_plugin_validation_code( $failures, 'valid-plugin', $case['plugins']['good']['file'], 0 );
		self::expect_plugin_validation_code( $failures, 'missing-plugin', $case['plugins']['missingFile'], 'plugin_not_found' );
		self::expect_plugin_validation_code( $failures, 'invalid-relative-path', '../outside.php', 'plugin_invalid' );
		self::expect_plugin_validation_code( $failures, 'headerless-plugin', $case['plugins']['headerless']['file'], 'no_plugin_header' );

		self::expect_plugin_requirement_code( $failures, 'valid-plugin', $case['plugins']['good']['file'], true );
		self::expect_plugin_requirement_code( $failures, 'future-wp-plugin', $case['plugins']['futureWp']['file'], 'plugin_wp_incompatible' );
		self::expect_plugin_requirement_code( $failures, 'future-php-plugin', $case['plugins']['futurePhp']['file'], 'plugin_php_incompatible' );

		$dependent_requirements = \validate_plugin_requirements( $case['plugins']['dependent']['file'] );
		self::expect_wp_error_code(
			$failures,
			'dependent-plugin-reports-missing-and-inactive-dependencies',
			$dependent_requirements,
			'plugin_missing_dependencies'
		);
		if ( \is_wp_error( $dependent_requirements ) ) {
			$error_data = $dependent_requirements->get_error_data( 'plugin_missing_dependencies' );
			$dep_slug   = $case['plugins']['dependency']['slug'];
			$missing    = $case['plugins']['dependent']['missingDependencySlug'];
			if (
				! is_array( $error_data )
				|| ! isset( $error_data['inactive'][ $dep_slug ] )
				|| ! isset( $error_data['not_installed'][ $missing ] )
			) {
				self::record_failure(
					$failures,
					'validate_plugin_requirements.dependency-error-data-partitions-state',
					array(
						'dependency' => $dep_slug,
						'missing'    => $missing,
						'errorData'  => $error_data,
					)
				);
			}
		}

		$dependent_activate = \activate_plugin( $case['plugins']['dependent']['file'], '', false, true );
		self::expect_wp_error_code(
			$failures,
			'activate-dependent-plugin-fails-before-activation',
			$dependent_activate,
			'plugin_missing_dependencies'
		);
		if ( \is_plugin_active( $case['plugins']['dependent']['file'] ) ) {
			self::record_failure(
				$failures,
				'activate_plugin.dependency-failure-does-not-mark-dependent-active',
				array( 'plugin' => $case['plugins']['dependent']['file'] )
			);
		}

		$activate_dependency = \activate_plugin( $case['plugins']['dependency']['file'], '', false, true );
		if ( null !== $activate_dependency || ! \is_plugin_active( $case['plugins']['dependency']['file'] ) ) {
			self::record_failure(
				$failures,
				'activate_plugin.installed-dependency-can-activate',
				array(
					'result' => self::describe_wp_error( $activate_dependency ),
					'active' => \is_plugin_active( $case['plugins']['dependency']['file'] ),
				)
			);
		}

		$dependent_after_dependency = \validate_plugin_requirements( $case['plugins']['dependent']['file'] );
		self::expect_wp_error_code(
			$failures,
			'dependent-plugin-still-reports-missing-dependency',
			$dependent_after_dependency,
			'plugin_missing_dependencies'
		);
		if ( \is_wp_error( $dependent_after_dependency ) ) {
			$error_data = $dependent_after_dependency->get_error_data( 'plugin_missing_dependencies' );
			$dep_slug   = $case['plugins']['dependency']['slug'];
			$missing    = $case['plugins']['dependent']['missingDependencySlug'];
			if (
				! is_array( $error_data )
				|| isset( $error_data['inactive'][ $dep_slug ] )
				|| ! isset( $error_data['not_installed'][ $missing ] )
			) {
				self::record_failure(
					$failures,
					'validate_plugin_requirements.active-dependency-no-longer-inactive',
					array(
						'dependency' => $dep_slug,
						'missing'    => $missing,
						'errorData'  => $error_data,
					)
				);
			}
		}

		\deactivate_plugins( $case['plugins']['dependency']['file'], true );

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.plugin.validation-requirements-and-dependencies',
			$failures,
			array(
				'installedCount' => count( $installed ),
				'good'           => $case['plugins']['good']['file'],
				'dependent'      => $case['plugins']['dependent']['file'],
				'dependency'     => $case['plugins']['dependency']['file'],
			)
		);
	}

	private static function check_plugin_activation_deactivation( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$plugin   = $case['plugins']['good']['file'];

		\update_option( 'active_plugins', array() );
		\delete_option( $case['plugins']['good']['activationOption'] );
		\delete_option( $case['plugins']['good']['deactivationOption'] );

		if ( \is_plugin_active( $plugin ) ) {
			self::record_failure( $failures, 'is_plugin_active.false-before-activation', array( 'plugin' => $plugin ) );
		}

		$activate = \activate_plugin( $plugin, '', false, false );
		$current  = \get_option( 'active_plugins', array() );
		if (
			null !== $activate
			|| ! \is_plugin_active( $plugin )
			|| 1 !== count( array_keys( $current, $plugin, true ) )
			|| 'site' !== \get_option( $case['plugins']['good']['activationOption'] )
		) {
			self::record_failure(
				$failures,
				'activate_plugin.success-updates-active-list-and-hook-state',
				array(
					'result'           => self::describe_wp_error( $activate ),
					'activePlugins'    => $current,
					'isActive'         => \is_plugin_active( $plugin ),
					'activationOption' => \get_option( $case['plugins']['good']['activationOption'], null ),
				)
			);
		}

		$activate_again = \activate_plugin( $plugin, '', false, false );
		$current_again  = \get_option( 'active_plugins', array() );
		if ( null !== $activate_again || 1 !== count( array_keys( $current_again, $plugin, true ) ) ) {
			self::record_failure(
				$failures,
				'activate_plugin.already-active-is-idempotent',
				array(
					'result'        => self::describe_wp_error( $activate_again ),
					'activePlugins' => $current_again,
				)
			);
		}

		\deactivate_plugins( $plugin, false );
		if (
			\is_plugin_active( $plugin )
			|| in_array( $plugin, \get_option( 'active_plugins', array() ), true )
			|| 'site' !== \get_option( $case['plugins']['good']['deactivationOption'] )
		) {
			self::record_failure(
				$failures,
				'deactivate_plugins.removes-active-plugin-and-runs-hook',
				array(
					'activePlugins'      => \get_option( 'active_plugins', array() ),
					'isActive'           => \is_plugin_active( $plugin ),
					'deactivationOption' => \get_option( $case['plugins']['good']['deactivationOption'], null ),
				)
			);
		}

		$output_plugin = $case['plugins']['output']['file'];
		$output_result = \activate_plugin( $output_plugin, '', false, true );
		self::expect_wp_error_code(
			$failures,
			'activate-plugin-captures-unexpected-output',
			$output_result,
			'unexpected_output'
		);
		if ( \is_plugin_active( $output_plugin ) ) {
			\deactivate_plugins( $output_plugin, true );
		}

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.plugin.activate-deactivate-success-and-output-failure',
			$failures,
			array(
				'plugin'       => $plugin,
				'outputPlugin' => $output_plugin,
				'activeAfter'  => \get_option( 'active_plugins', array() ),
			)
		);
	}

	private static function check_plugin_network_delete_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$plugin   = $case['plugins']['network']['file'];
		$shape    = array(
			$plugin                             => time(),
			$case['plugins']['good']['file']    => 'not-a-timestamp',
			'not-a-plugin-' . $case['id'] . '.php' => 0,
		);

		\update_site_option( 'active_sitewide_plugins', $shape );
		$network_option = \get_site_option( 'active_sitewide_plugins', array() );

		if ( $network_option !== $shape ) {
			self::record_failure(
				$failures,
				'active_sitewide_plugins.option-shape-round-trips-through-site-option-api',
				array(
					'expected' => $shape,
					'actual'   => $network_option,
				)
			);
		}

		$network_active = \is_plugin_active_for_network( $plugin );
		if ( \is_multisite() && ! $network_active ) {
			self::record_failure(
				$failures,
				'is_plugin_active_for_network.honors-sitewide-option-on-multisite',
				array( 'option' => $network_option )
			);
		}
		if ( ! \is_multisite() && $network_active ) {
			self::record_failure(
				$failures,
				'is_plugin_active_for_network.ignores-sitewide-option-when-not-multisite',
				array( 'option' => $network_option )
			);
		}

		if ( ! \is_network_only_plugin( $plugin ) ) {
			self::record_failure(
				$failures,
				'is_network_only_plugin.reads-network-header',
				array( 'plugin' => $plugin )
			);
		}

		$empty_delete = \delete_plugins( array() );
		if ( false !== $empty_delete ) {
			self::record_failure(
				$failures,
				'delete_plugins.empty-list-returns-false',
				array( 'actual' => self::describe_value( $empty_delete ) )
			);
		}

		$delete_plugin = $case['plugins']['delete']['file'];
		self::with_direct_filesystem(
			static function () use ( &$failures, $delete_plugin, $case ): void {
				$result = \delete_plugins( array( $delete_plugin ) );
				\wp_clean_plugins_cache( false );

				if ( true !== $result || file_exists( $case['plugins']['delete']['dir'] ) ) {
					self::record_failure(
						$failures,
						'delete_plugins.removes-inactive-temp-plugin-directory',
						array(
							'result' => self::describe_wp_error( $result ),
							'dir'    => self::preview( $case['plugins']['delete']['dir'] ),
							'exists' => file_exists( $case['plugins']['delete']['dir'] ),
						)
					);
				}
			}
		);

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.plugin.network-option-shapes-and-delete-validation',
			$failures,
			array(
				'plugin'        => $plugin,
				'isMultisite'   => \is_multisite(),
				'networkActive' => $network_active,
				'deletePlugin'  => $delete_plugin,
			)
		);
	}

	private static function check_theme_root_stylesheet_template_normalization( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::clear_runtime_caches();

		$theme_root = $case['paths']['themeRoot'];
		$parent     = $case['themes']['parent']['slug'];
		$child      = $case['themes']['child']['slug'];

		$registered       = \register_theme_directory( \trailingslashit( $theme_root ) );
		$missing_register = \register_theme_directory( $theme_root . '/' . $case['themes']['missingSlug'] );
		$directories      = array_values( (array) $GLOBALS['wp_theme_directories'] );

		if (
			true !== $registered
			|| false !== $missing_register
			|| array( $theme_root ) !== $directories
		) {
			self::record_failure(
				$failures,
				'register_theme_directory.untrails-existing-root-and-rejects-missing-root',
				array(
					'registered'      => $registered,
					'missingRegister' => $missing_register,
					'directories'     => $directories,
					'expected'        => array( $theme_root ),
				)
			);
		}

		$found          = \search_theme_directories( true );
		$raw_child_root = \get_raw_theme_root( $child, true );
		$child_root     = \get_theme_root( $child );
		$parent_root    = \get_theme_root( $parent );
		$child_theme    = \wp_get_theme( $child, $theme_root );

		if (
			! is_array( $found )
			|| ! isset( $found[ $child ], $found[ $parent ] )
			|| ( $found[ $child ]['theme_root'] ?? null ) !== $theme_root
			|| ( $found[ $parent ]['theme_root'] ?? null ) !== $theme_root
			|| '/themes' !== $raw_child_root
			|| $theme_root !== $child_root
			|| $theme_root !== $parent_root
			|| ! $child_theme->exists()
			|| $child_theme->get_stylesheet() !== $child
			|| $child_theme->get_template() !== $parent
			|| $child_theme->get_stylesheet_directory() !== $case['themes']['child']['dir']
			|| $child_theme->get_template_directory() !== $case['themes']['parent']['dir']
		) {
			self::record_failure(
				$failures,
				'theme-root-helpers.resolve-stylesheet-template-and-root-consistently',
				array(
					'found'                => is_array( $found ) ? self::compact_data( $found ) : self::describe_value( $found ),
					'rawChildRoot'         => $raw_child_root,
					'childRoot'            => $child_root,
					'parentRoot'           => $parent_root,
					'childExists'          => $child_theme->exists(),
					'childStylesheet'      => $child_theme->get_stylesheet(),
					'childTemplate'        => $child_theme->get_template(),
					'stylesheetDirectory'  => $child_theme->get_stylesheet_directory(),
					'templateDirectory'    => $child_theme->get_template_directory(),
					'expectedStylesheetDir' => $case['themes']['child']['dir'],
					'expectedTemplateDir'  => $case['themes']['parent']['dir'],
				)
			);
		}

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.theme.root-stylesheet-template-normalization',
			$failures,
			array(
				'themeRoot'    => self::preview( $theme_root ),
				'parent'       => $parent,
				'child'        => $child,
				'rawChildRoot' => $raw_child_root,
			)
		);
	}

	private static function check_theme_enumeration_requirements( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::clear_runtime_caches();

		$all_themes   = \wp_get_themes( array( 'errors' => null ) );
		$valid_themes = \wp_get_themes( array( 'errors' => false ) );
		$error_themes = \wp_get_themes( array( 'errors' => true ) );

		foreach ( array( 'parent', 'child', 'futureWp', 'futurePhp' ) as $key ) {
			$slug = $case['themes'][ $key ]['slug'];
			if ( ! isset( $all_themes[ $slug ] ) || ! $all_themes[ $slug ] instanceof \WP_Theme ) {
				self::record_failure(
					$failures,
					"wp_get_themes.includes-{$key}-fixture",
					array(
						'slug' => $slug,
						'keys' => array_keys( $all_themes ),
					)
				);
			}
		}

		if (
			! isset( $valid_themes[ $case['themes']['child']['slug'] ], $valid_themes[ $case['themes']['parent']['slug'] ] )
			|| isset( $valid_themes[ $case['themes']['broken']['slug'] ] )
			|| ! isset( $error_themes[ $case['themes']['broken']['slug'] ] )
		) {
			self::record_failure(
				$failures,
				'wp_get_themes.errors-filter-partitions-valid-and-broken-themes',
				array(
					'validKeys' => array_keys( $valid_themes ),
					'errorKeys' => array_keys( $error_themes ),
					'broken'    => $case['themes']['broken']['slug'],
				)
			);
		}

		$child_theme = \wp_get_theme( $case['themes']['child']['slug'] );
		if (
			! $child_theme->exists()
			|| $child_theme->get_template() !== $case['themes']['parent']['slug']
			|| $child_theme->get_stylesheet() !== $case['themes']['child']['slug']
			|| $child_theme->get_theme_root() !== $case['paths']['themeRoot']
		) {
			self::record_failure(
				$failures,
				'wp_get_theme.child-theme-resolves-from-temp-theme-root',
				array(
					'exists'            => $child_theme->exists(),
					'template'          => $child_theme->get_template(),
					'stylesheet'        => $child_theme->get_stylesheet(),
					'themeRoot'         => self::preview( $child_theme->get_theme_root() ),
					'expectedTemplate'  => $case['themes']['parent']['slug'],
					'expectedStylesheet' => $case['themes']['child']['slug'],
				)
			);
		}

		self::expect_theme_requirement_code( $failures, 'valid-child-theme', $case['themes']['child']['slug'], true );
		self::expect_theme_requirement_code( $failures, 'future-wp-theme', $case['themes']['futureWp']['slug'], 'theme_wp_incompatible' );
		self::expect_theme_requirement_code( $failures, 'future-php-theme', $case['themes']['futurePhp']['slug'], 'theme_php_incompatible' );

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.theme.enumeration-and-requirements',
			$failures,
			array(
				'themeRoot'  => self::preview( $case['paths']['themeRoot'] ),
				'allCount'   => count( $all_themes ),
				'validCount' => count( $valid_themes ),
				'errorCount' => count( $error_themes ),
			)
		);
	}

	private static function check_theme_switch_support_globals( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$parent   = $case['themes']['parent']['slug'];
		$child    = $case['themes']['child']['slug'];

		\update_option( 'template', $parent );
		\update_option( 'stylesheet', $parent );
		\update_option( 'current_theme', $case['themes']['parent']['name'] );
		\delete_option( 'template_root' );
		\delete_option( 'stylesheet_root' );
		\wp_set_template_globals();

		\add_theme_support( 'post-thumbnails', array( 'post', 'page' ) );
		\add_theme_support(
			'custom-logo',
			array(
				'height'      => $case['themes']['supportHeight'],
				'width'       => $case['themes']['supportWidth'],
				'flex-height' => true,
			)
		);

		$post_thumbnails = \current_theme_supports( 'post-thumbnails', 'post' );
		$logo_support    = \get_theme_support( 'custom-logo' );
		if (
			! $post_thumbnails
			|| ! is_array( $logo_support )
			|| ( $logo_support[0]['height'] ?? null ) !== $case['themes']['supportHeight']
			|| ( $logo_support[0]['width'] ?? null ) !== $case['themes']['supportWidth']
		) {
			self::record_failure(
				$failures,
				'theme-support.registration-and-query-round-trip',
				array(
					'postThumbnails' => $post_thumbnails,
					'logoSupport'    => $logo_support,
				)
			);
		}

		$switch_events = array();
		$switch_action = static function ( string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme ) use ( &$switch_events ): void {
			$switch_events[] = array(
				'newName'       => $new_name,
				'newStylesheet' => $new_theme->get_stylesheet(),
				'oldStylesheet' => $old_theme->get_stylesheet(),
			);
		};
		\add_action( 'switch_theme', $switch_action, 10, 3 );

		try {
			\switch_theme( $child );
		} finally {
			\remove_action( 'switch_theme', $switch_action, 10 );
		}

		$stylesheet_dir = \get_stylesheet_directory();
		$template_dir   = \get_template_directory();
		$stylesheet     = \get_stylesheet();
		$template       = \get_template();

		if (
			$stylesheet !== $child
			|| $template !== $parent
			|| \get_option( 'stylesheet' ) !== $child
			|| \get_option( 'template' ) !== $parent
			|| \get_option( 'current_theme' ) !== $case['themes']['child']['name']
			|| \get_option( 'theme_switched' ) !== $parent
			|| $stylesheet_dir !== $case['themes']['child']['dir']
			|| $template_dir !== $case['themes']['parent']['dir']
			|| ( $GLOBALS['wp_stylesheet_path'] ?? null ) !== $case['themes']['child']['dir']
			|| ( $GLOBALS['wp_template_path'] ?? null ) !== $case['themes']['parent']['dir']
			|| 1 !== count( $switch_events )
			|| ( $switch_events[0]['newStylesheet'] ?? null ) !== $child
			|| ( $switch_events[0]['oldStylesheet'] ?? null ) !== $parent
		) {
			self::record_failure(
				$failures,
				'switch_theme.valid-child-updates-options-globals-and-action',
				array(
					'stylesheet'       => $stylesheet,
					'template'         => $template,
					'currentTheme'     => \get_option( 'current_theme' ),
					'themeSwitched'    => \get_option( 'theme_switched' ),
					'stylesheetDir'    => self::preview( $stylesheet_dir ),
					'templateDir'      => self::preview( $template_dir ),
					'wpStylesheetPath' => self::preview( (string) ( $GLOBALS['wp_stylesheet_path'] ?? '' ) ),
					'wpTemplatePath'   => self::preview( (string) ( $GLOBALS['wp_template_path'] ?? '' ) ),
					'switchEvents'     => $switch_events,
				)
			);
		}

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.theme.switch-theme-support-and-template-globals',
			$failures,
			array(
				'parent'         => $parent,
				'child'          => $child,
				'stylesheetDir'  => self::preview( $stylesheet_dir ),
				'templateDir'    => self::preview( $template_dir ),
				'supportFeature' => 'custom-logo',
			)
		);
	}

	private static function check_theme_delete_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$empty = \delete_theme( '' );
		if ( false !== $empty ) {
			self::record_failure(
				$failures,
				'delete_theme.empty-stylesheet-returns-false',
				array( 'actual' => self::describe_value( $empty ) )
			);
		}

		$delete_theme = $case['themes']['delete']['slug'];
		self::with_direct_filesystem(
			static function () use ( &$failures, $delete_theme, $case ): void {
				$result = \delete_theme( $delete_theme );
				\wp_clean_themes_cache( false );

				if ( true !== $result || file_exists( $case['themes']['delete']['dir'] ) || \wp_get_theme( $delete_theme )->exists() ) {
					self::record_failure(
						$failures,
						'delete_theme.removes-inactive-temp-theme-directory',
						array(
							'result' => self::describe_wp_error( $result ),
							'dir'    => self::preview( $case['themes']['delete']['dir'] ),
							'exists' => file_exists( $case['themes']['delete']['dir'] ),
						)
					);
				}
			}
		);

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.theme.delete-validation-paths',
			$failures,
			array(
				'deleteTheme' => $delete_theme,
				'emptyResult' => $empty,
			)
		);
	}

	private static function check_rest_plugin_theme_read_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::clear_runtime_caches();

		$cap_filter = static function ( array $allcaps ): array {
			foreach (
				array(
					'activate_plugins',
					'activate_plugin',
					'deactivate_plugin',
					'delete_plugins',
					'install_plugins',
					'switch_themes',
					'manage_network_plugins',
					'manage_network_themes',
					'edit_posts',
					'export',
				) as $cap
			) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		};
		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );

		try {
			\update_option( 'active_plugins', array( $case['plugins']['good']['file'] ) );

			$plugin_controller = new \WP_REST_Plugins_Controller();
			$plugin_request    = new \WP_REST_Request( 'GET', '/wp/v2/plugins' );
			$plugin_request->set_query_params(
				array(
					'_fields' => 'plugin,status,name,version,network_only,requires_wp,requires_php',
					'status'  => array( 'active', 'inactive' ),
				)
			);
			$plugin_items = $plugin_controller->get_items( $plugin_request );

			$plugin_item_request = new \WP_REST_Request( 'GET', '/wp/v2/plugins/' . substr( $case['plugins']['good']['file'], 0, -4 ) );
			$plugin_item_request->set_query_params( array( '_fields' => 'plugin,status,name,version,network_only,requires_wp,requires_php' ) );
			$plugin_item_request->set_url_params( array( 'plugin' => $case['plugins']['good']['file'] ) );
			$plugin_item = $plugin_controller->get_item( $plugin_item_request );

			$missing_plugin_request = new \WP_REST_Request( 'GET', '/wp/v2/plugins/missing' );
			$missing_plugin_request->set_url_params( array( 'plugin' => $case['plugins']['missingFile'] ) );
			$missing_plugin = $plugin_controller->get_item( $missing_plugin_request );

			if (
				! ( $plugin_items instanceof \WP_REST_Response )
				|| ! ( $plugin_item instanceof \WP_REST_Response )
				|| ! \is_wp_error( $missing_plugin )
				|| 'rest_plugin_not_found' !== $missing_plugin->get_error_code()
			) {
				self::record_failure(
					$failures,
					'WP_REST_Plugins_Controller.read-methods-return-response-or-not-found-error',
					array(
						'items'   => self::describe_value( $plugin_items ),
						'item'    => self::describe_value( $plugin_item ),
						'missing' => self::describe_wp_error( $missing_plugin ),
					)
				);
			} elseif ( ! self::rest_plugin_collection_contains( $plugin_items, $case['plugins']['good']['file'], 'active' ) ) {
				self::record_failure(
					$failures,
					'WP_REST_Plugins_Controller.collection-reflects-active-temp-plugin',
					array(
						'plugin' => $case['plugins']['good']['file'],
						'data'   => $plugin_items->get_data(),
					)
				);
			} else {
				$item_data = $plugin_item->get_data();
				if (
					( $item_data['plugin'] ?? null ) !== substr( $case['plugins']['good']['file'], 0, -4 )
					|| ( $item_data['status'] ?? null ) !== 'active'
					|| ( $item_data['name'] ?? null ) !== $case['plugins']['good']['name']
				) {
					self::record_failure(
						$failures,
						'WP_REST_Plugins_Controller.item-data-matches-plugin-state',
						array(
							'expectedPlugin' => substr( $case['plugins']['good']['file'], 0, -4 ),
							'expectedName'   => $case['plugins']['good']['name'],
							'data'           => $item_data,
						)
					);
				}
			}

			if (
				! $plugin_controller->validate_plugin_param( $case['plugins']['good']['slug'] )
				|| $plugin_controller->validate_plugin_param( '../bad' )
				|| $case['plugins']['good']['file'] !== $plugin_controller->sanitize_plugin_param( $case['plugins']['good']['slug'] . '/' . $case['plugins']['good']['slug'] )
			) {
				self::record_failure(
					$failures,
					'WP_REST_Plugins_Controller.plugin-param-validation-and-sanitization',
					array(
						'slug'      => $case['plugins']['good']['slug'],
						'sanitized' => $plugin_controller->sanitize_plugin_param( $case['plugins']['good']['slug'] . '/' . $case['plugins']['good']['slug'] ),
					)
				);
			}

			$theme_controller = new \WP_REST_Themes_Controller();
			$theme_request    = new \WP_REST_Request( 'GET', '/wp/v2/themes' );
			$theme_request->set_query_params(
				array(
					'_fields' => 'stylesheet,template,status,name,version,requires_wp,requires_php,is_block_theme,stylesheet_uri,template_uri',
					'status'  => array( 'active', 'inactive' ),
				)
			);
			$theme_items = $theme_controller->get_items( $theme_request );

			$theme_item_request = new \WP_REST_Request( 'GET', '/wp/v2/themes/' . $case['themes']['child']['slug'] );
			$theme_item_request->set_query_params( array( '_fields' => 'stylesheet,template,status,name,version,requires_wp,requires_php,is_block_theme' ) );
			$theme_item_request->set_url_params( array( 'stylesheet' => $case['themes']['child']['slug'] ) );
			$theme_item = $theme_controller->get_item( $theme_item_request );

			$missing_theme_request = new \WP_REST_Request( 'GET', '/wp/v2/themes/missing' );
			$missing_theme_request->set_url_params( array( 'stylesheet' => $case['themes']['missingSlug'] ) );
			$missing_theme = $theme_controller->get_item( $missing_theme_request );

			if (
				! ( $theme_items instanceof \WP_REST_Response )
				|| ! ( $theme_item instanceof \WP_REST_Response )
				|| ! \is_wp_error( $missing_theme )
				|| 'rest_theme_not_found' !== $missing_theme->get_error_code()
			) {
				self::record_failure(
					$failures,
					'WP_REST_Themes_Controller.read-methods-return-response-or-not-found-error',
					array(
						'items'   => self::describe_value( $theme_items ),
						'item'    => self::describe_value( $theme_item ),
						'missing' => self::describe_wp_error( $missing_theme ),
					)
				);
			} elseif ( ! self::rest_theme_collection_contains( $theme_items, $case['themes']['child']['slug'], 'active' ) ) {
				self::record_failure(
					$failures,
					'WP_REST_Themes_Controller.collection-reflects-active-temp-theme',
					array(
						'theme' => $case['themes']['child']['slug'],
						'data'  => $theme_items->get_data(),
					)
				);
			} else {
				$theme_data = $theme_item->get_data();
				if (
					( $theme_data['stylesheet'] ?? null ) !== $case['themes']['child']['slug']
					|| ( $theme_data['template'] ?? null ) !== $case['themes']['parent']['slug']
					|| ( $theme_data['status'] ?? null ) !== 'active'
				) {
					self::record_failure(
						$failures,
						'WP_REST_Themes_Controller.item-data-matches-active-child-theme',
						array(
							'expectedStylesheet' => $case['themes']['child']['slug'],
							'expectedTemplate'   => $case['themes']['parent']['slug'],
							'data'               => $theme_data,
						)
					);
				}
			}

			$encoded = rawurlencode( $case['themes']['child']['slug'] );
			if ( $case['themes']['child']['slug'] !== $theme_controller->_sanitize_stylesheet_callback( $encoded ) ) {
				self::record_failure(
					$failures,
					'WP_REST_Themes_Controller.stylesheet-sanitizer-decodes-route-segment',
					array(
						'encoded' => $encoded,
						'actual'  => $theme_controller->_sanitize_stylesheet_callback( $encoded ),
					)
				);
			}
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		return self::row(
			$ctx,
			'plugin-theme-lifecycle.rest.plugin-theme-read-controllers',
			$failures,
			array(
				'plugin' => $case['plugins']['good']['file'],
				'theme'  => $case['themes']['child']['slug'],
			)
		);
	}

	private static function expect_plugin_validation_code( array &$failures, string $label, string $plugin, $expected ): void {
		$result = \validate_plugin( $plugin );

		if ( 0 === $expected ) {
			if ( 0 !== $result ) {
				self::record_failure(
					$failures,
					"validate_plugin.{$label}.expected-success",
					array(
						'plugin' => $plugin,
						'result' => self::describe_wp_error( $result ),
					)
				);
			}
			return;
		}

		self::expect_wp_error_code( $failures, "validate_plugin.{$label}.expected-error", $result, $expected );
	}

	private static function expect_plugin_requirement_code( array &$failures, string $label, string $plugin, $expected ): void {
		$result = \validate_plugin_requirements( $plugin );

		if ( true === $expected ) {
			if ( true !== $result ) {
				self::record_failure(
					$failures,
					"validate_plugin_requirements.{$label}.expected-true",
					array(
						'plugin' => $plugin,
						'result' => self::describe_wp_error( $result ),
					)
				);
			}
			return;
		}

		self::expect_wp_error_code( $failures, "validate_plugin_requirements.{$label}.expected-error", $result, $expected );
	}

	private static function expect_theme_requirement_code( array &$failures, string $label, string $stylesheet, $expected ): void {
		$result = \validate_theme_requirements( $stylesheet );

		if ( true === $expected ) {
			if ( true !== $result ) {
				self::record_failure(
					$failures,
					"validate_theme_requirements.{$label}.expected-true",
					array(
						'stylesheet' => $stylesheet,
						'result'     => self::describe_wp_error( $result ),
					)
				);
			}
			return;
		}

		self::expect_wp_error_code( $failures, "validate_theme_requirements.{$label}.expected-error", $result, $expected );
	}

	private static function expect_wp_error_code( array &$failures, string $check, $result, string $expected_code ): void {
		if ( ! \is_wp_error( $result ) || $expected_code !== $result->get_error_code() ) {
			self::record_failure(
				$failures,
				$check,
				array(
					'expectedCode' => $expected_code,
					'actual'       => self::describe_wp_error( $result ),
				)
			);
		}
	}

	private static function rest_plugin_collection_contains( \WP_REST_Response $response, string $plugin_file, string $status ): bool {
		$expected_plugin = substr( $plugin_file, 0, -4 );

		foreach ( $response->get_data() as $item ) {
			if ( is_array( $item ) && ( $item['plugin'] ?? null ) === $expected_plugin && ( $item['status'] ?? null ) === $status ) {
				return true;
			}
		}

		return false;
	}

	private static function rest_theme_collection_contains( \WP_REST_Response $response, string $stylesheet, string $status ): bool {
		foreach ( $response->get_data() as $item ) {
			if ( is_array( $item ) && ( $item['stylesheet'] ?? null ) === $stylesheet && ( $item['status'] ?? null ) === $status ) {
				return true;
			}
		}

		return false;
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$id         = $ctx->seed() . '-' . $ctx->iteration() . '-' . getmypid();
		$used       = array();
		$plugin_ctx = $ctx->fork( 'plugins' );
		$theme_ctx  = $ctx->fork( 'themes' );

		$plugin_keys = array( 'good', 'output', 'headerless', 'lateHeader', 'implicitTextDomain', 'futureWp', 'futurePhp', 'dependency', 'dependent', 'network', 'delete' );
		$plugins     = array();
		foreach ( $plugin_keys as $key ) {
			$slug            = self::slug( $plugin_ctx, 'cfz-' . $key, $used );
			$plugins[ $key ] = self::plugin_case( $slug, self::plugin_name( $key, $plugin_ctx ), '1.' . $plugin_ctx->int( 0, 9 ) . '.' . $plugin_ctx->int( 0, 99 ) );
		}

		$plugins['good']['activationOption']   = 'cfz_lifecycle_activated_' . str_replace( '-', '_', $plugins['good']['slug'] );
		$plugins['good']['deactivationOption'] = 'cfz_lifecycle_deactivated_' . str_replace( '-', '_', $plugins['good']['slug'] );
		$plugins['missingFile']                = self::slug( $plugin_ctx, 'cfz-missing', $used ) . '/missing.php';
		$plugins['dependent']['missingDependencySlug'] = self::slug( $plugin_ctx, 'cfz-missing-dependency', $used );

		$theme_root = \trailingslashit( WP_CONTENT_DIR ) . 'themes';
		$theme_keys = array( 'parent', 'child', 'futureWp', 'futurePhp', 'broken', 'delete' );
		$themes     = array();
		foreach ( $theme_keys as $key ) {
			$slug           = self::slug( $theme_ctx, 'cfz-' . $key, $used );
			$themes[ $key ] = array(
				'slug'    => $slug,
				'name'    => self::theme_name( $key, $theme_ctx ),
				'version' => '1.' . $theme_ctx->int( 0, 9 ) . '.' . $theme_ctx->int( 0, 99 ),
				'dir'     => $theme_root . '/' . $slug,
			);
		}

		$themes['child']['template']     = $themes['parent']['slug'];
		$themes['missingSlug']           = self::slug( $theme_ctx, 'cfz-missing-theme', $used );
		$themes['supportHeight']         = $theme_ctx->int( 64, 512 );
		$themes['supportWidth']          = $theme_ctx->int( 64, 512 );

		return array(
			'id'      => $id,
			'paths'   => array(
				'contentRoot' => WP_CONTENT_DIR,
				'pluginRoot'  => WP_PLUGIN_DIR,
				'themeRoot'   => $theme_root,
				'langRoot'    => WP_LANG_DIR,
			),
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	private static function plugin_case( string $slug, string $name, string $version ): array {
		return array(
			'slug'    => $slug,
			'name'    => $name,
			'version' => $version,
			'file'    => $slug . '/' . $slug . '.php',
			'dir'     => \trailingslashit( WP_PLUGIN_DIR ) . $slug,
			'path'    => \trailingslashit( WP_PLUGIN_DIR ) . $slug . '/' . $slug . '.php',
		);
	}

	private static function prepare_sandbox( array $case ): void {
		foreach (
			array(
				$case['paths']['contentRoot'],
				$case['paths']['pluginRoot'],
				$case['paths']['themeRoot'],
				$case['paths']['langRoot'],
				$case['paths']['langRoot'] . '/plugins',
				$case['paths']['langRoot'] . '/themes',
			) as $dir
		) {
			self::ensure_dir( $dir );
		}

		foreach ( self::fixture_paths( $case ) as $path ) {
			if ( file_exists( $path ) ) {
				self::remove_dir_recursive( $path );
			}
		}

		\update_option( 'active_plugins', array() );
		\update_site_option( 'active_sitewide_plugins', array() );
	}

	private static function install_theme_root( array $case ): void {
		$GLOBALS['wp_theme_directories'] = array( $case['paths']['themeRoot'] );
	}

	private static function write_case_files( array $case ): void {
		self::write_plugin(
			$case['plugins']['good'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			),
			self::activation_plugin_body(
				$case['plugins']['good']['activationOption'],
				$case['plugins']['good']['deactivationOption']
			)
		);

		self::write_plugin(
			$case['plugins']['output'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			),
			"echo 'component fuzz unexpected output';\n"
		);

		self::ensure_dir( $case['plugins']['headerless']['dir'] );
		self::write_file( $case['plugins']['headerless']['path'], "<?php\n// Deliberately no plugin header.\n" );

		self::write_late_header_plugin( $case['plugins']['lateHeader'] );

		self::write_plugin(
			$case['plugins']['implicitTextDomain'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
				'TextDomain'  => '',
			),
			"// Plugin fixture that relies on get_plugin_data() text domain fallback.\n"
		);

		self::write_plugin(
			$case['plugins']['futureWp'],
			array(
				'RequiresWP'  => '999.0',
				'RequiresPHP' => '5.6',
			),
			"// Future WordPress requirement fixture.\n"
		);

		self::write_plugin(
			$case['plugins']['futurePhp'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '999.0',
			),
			"// Future PHP requirement fixture.\n"
		);

		self::write_plugin(
			$case['plugins']['dependency'],
			array(
				'RequiresWP'      => '5.0',
				'RequiresPHP'     => '5.6',
				'RequiresPlugins' => '',
			),
			"// Dependency fixture.\n"
		);

		self::write_plugin(
			$case['plugins']['dependent'],
			array(
				'RequiresWP'      => '5.0',
				'RequiresPHP'     => '5.6',
				'RequiresPlugins' => $case['plugins']['dependency']['slug'] . ', ' . $case['plugins']['dependent']['missingDependencySlug'],
			),
			"// Dependent fixture.\n"
		);

		self::write_plugin(
			$case['plugins']['network'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
				'Network'     => 'true',
			),
			"// Network-only fixture.\n"
		);

		self::write_plugin(
			$case['plugins']['delete'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			),
			"// Deletion fixture.\n"
		);

		self::write_theme(
			$case['themes']['parent'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			)
		);
		self::write_theme(
			$case['themes']['child'],
			array(
				'Template'    => $case['themes']['parent']['slug'],
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			)
		);
		self::write_theme(
			$case['themes']['futureWp'],
			array(
				'RequiresWP'  => '999.0',
				'RequiresPHP' => '5.6',
			)
		);
		self::write_theme(
			$case['themes']['futurePhp'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '999.0',
			)
		);
		self::write_theme(
			$case['themes']['delete'],
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			)
		);

		self::ensure_dir( $case['themes']['broken']['dir'] );
		self::write_file( $case['themes']['broken']['dir'] . '/readme.txt', "Broken theme fixture without style.css.\n" );
	}

	private static function write_plugin( array $plugin, array $headers, string $body ): void {
		self::ensure_dir( $plugin['dir'] );
		self::write_file( $plugin['path'], self::plugin_header( $plugin, $headers ) . $body );
	}

	private static function plugin_header( array $plugin, array $headers ): string {
		$headers = array_merge(
			array(
				'PluginURI'       => 'https://example.test/plugins/' . $plugin['slug'],
				'Description'     => 'Component fuzz lifecycle plugin fixture.',
				'Author'          => 'Component Fuzz',
				'AuthorURI'       => 'https://example.test/',
				'TextDomain'      => $plugin['slug'],
				'Network'         => '',
				'RequiresWP'      => '5.0',
				'RequiresPHP'     => '5.6',
				'RequiresPlugins' => '',
			),
			$headers
		);

		$lines = array(
			"<?php",
			"/**",
			" * Plugin Name: {$plugin['name']}",
			" * Plugin URI: {$headers['PluginURI']}",
			" * Description: {$headers['Description']}",
			" * Version: {$plugin['version']}",
			" * Author: {$headers['Author']}",
			" * Author URI: {$headers['AuthorURI']}",
			" * Text Domain: {$headers['TextDomain']}",
			" * Requires at least: {$headers['RequiresWP']}",
			" * Requires PHP: {$headers['RequiresPHP']}",
		);

		if ( '' !== $headers['RequiresPlugins'] ) {
			$lines[] = " * Requires Plugins: {$headers['RequiresPlugins']}";
		}
		if ( '' !== $headers['Network'] ) {
			$lines[] = " * Network: {$headers['Network']}";
		}

		$lines[] = " */";

		return implode( "\n", $lines ) . "\n";
	}

	private static function activation_plugin_body( string $activation_option, string $deactivation_option ): string {
		return 'register_activation_hook( __FILE__, static function ( $network_wide ) { update_option( '
			. var_export( $activation_option, true )
			. ', $network_wide ? '
			. var_export( 'network', true )
			. ' : '
			. var_export( 'site', true )
			. ' ); } );'
			. "\n"
			. 'register_deactivation_hook( __FILE__, static function ( $network_wide ) { update_option( '
			. var_export( $deactivation_option, true )
			. ', $network_wide ? '
			. var_export( 'network', true )
			. ' : '
			. var_export( 'site', true )
			. ' ); } );'
			. "\n";
	}

	private static function write_theme( array $theme, array $headers ): void {
		self::ensure_dir( $theme['dir'] );
		self::write_file( $theme['dir'] . '/style.css', self::theme_header( $theme, $headers ) );
		self::write_file( $theme['dir'] . '/index.php', "<?php\n// Theme index fixture.\n" );
		self::ensure_dir( $theme['dir'] . '/templates' );
		self::write_file( $theme['dir'] . '/templates/index.html', "<!-- wp:paragraph --><p>Theme fixture</p><!-- /wp:paragraph -->\n" );
	}

	private static function write_late_header_plugin( array $plugin ): void {
		self::ensure_dir( $plugin['dir'] );
		$header = self::plugin_header(
			$plugin,
			array(
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			)
		);
		$header = (string) preg_replace( '/^<\?php\n/', '', $header );
		$prefix = "<?php\n/*\n"
			. str_repeat( " * Padding before delayed header discovery.\n", 260 )
			. " */\n";

		self::write_file( $plugin['path'], $prefix . $header . "// Header appears after the get_file_data() read window.\n" );
	}

	private static function theme_header( array $theme, array $headers ): string {
		$headers = array_merge(
			array(
				'ThemeURI'    => 'https://example.test/themes/' . $theme['slug'],
				'Description' => 'Component fuzz lifecycle theme fixture.',
				'Author'      => 'Component Fuzz',
				'AuthorURI'   => 'https://example.test/',
				'Template'    => '',
				'RequiresWP'  => '5.0',
				'RequiresPHP' => '5.6',
			),
			$headers
		);

		$lines = array(
			"/*",
			"Theme Name: {$theme['name']}",
			"Theme URI: {$headers['ThemeURI']}",
			"Description: {$headers['Description']}",
			"Version: {$theme['version']}",
			"Author: {$headers['Author']}",
			"Author URI: {$headers['AuthorURI']}",
			"Requires at least: {$headers['RequiresWP']}",
			"Requires PHP: {$headers['RequiresPHP']}",
		);

		if ( '' !== $headers['Template'] ) {
			$lines[] = "Template: {$headers['Template']}";
		}

		$lines[] = "*/";

		return implode( "\n", $lines ) . "\n";
	}

	private static function clear_runtime_caches(): void {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			\wp_clean_plugins_cache( false );
		} else {
			\wp_cache_delete( 'plugins', 'plugins' );
		}

		\wp_cache_delete( 'theme_roots', 'site-transient' );
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			\wp_clean_themes_cache( false );
		}

		self::reset_plugin_dependency_state();
	}

	private static function cleanup_case_files( array $case ): bool {
		$ok = true;
		foreach ( self::fixture_paths( $case ) as $path ) {
			if ( file_exists( $path ) && ! self::remove_dir_recursive( $path ) ) {
				$ok = false;
			}
		}

		return $ok && array() === self::fixture_leftovers( $case );
	}

	private static function fixture_paths( array $case ): array {
		$paths = array();

		foreach ( $case['plugins'] as $plugin ) {
			if ( is_array( $plugin ) && isset( $plugin['dir'] ) ) {
				$paths[] = $plugin['dir'];
			}
		}

		foreach ( $case['themes'] as $theme ) {
			if ( is_array( $theme ) && isset( $theme['dir'] ) ) {
				$paths[] = $theme['dir'];
			}
		}

		return array_values( array_unique( $paths ) );
	}

	private static function fixture_leftovers( array $case ): array {
		$leftovers = array();
		foreach ( self::fixture_paths( $case ) as $path ) {
			if ( file_exists( $path ) ) {
				$leftovers[] = self::preview( $path );
			}
		}

		return $leftovers;
	}

	private static function with_direct_filesystem( callable $callback ): void {
		$credentials_filter = static function () {
			return true;
		};
		$method_filter      = static function (): string {
			return 'direct';
		};

		\add_filter( 'request_filesystem_credentials', $credentials_filter, 10, 7 );
		\add_filter( 'filesystem_method', $method_filter, 10, 4 );
		try {
			$callback();
		} finally {
			\remove_filter( 'request_filesystem_credentials', $credentials_filter, 10 );
			\remove_filter( 'filesystem_method', $method_filter, 10 );
		}
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix, array &$used ): string {
		do {
			$slug = strtolower( $prefix . '-' . $ctx->identifier( 4, 9 ) . '-' . $ctx->int( 100, 999 ) );
			$slug = (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug );
			$slug = (string) preg_replace( '/-+/', '-', $slug );
			$slug = trim( $slug, '-' );
		} while ( '' === $slug || isset( $used[ $slug ] ) );

		$used[ $slug ] = true;
		return $slug;
	}

	private static function plugin_name( string $key, \ComponentFuzz\FuzzContext $ctx ): string {
		return 'Component Fuzz ' . ucwords( str_replace( '-', ' ', $key ) ) . ' Plugin ' . $ctx->int( 100, 999 );
	}

	private static function theme_name( string $key, \ComponentFuzz\FuzzContext $ctx ): string {
		return 'Component Fuzz ' . ucwords( str_replace( '-', ' ', $key ) ) . ' Theme ' . $ctx->int( 100, 999 );
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

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'_GET',
					'_POST',
					'_REQUEST',
					'_wp_filesystem_direct_method',
					'_wp_theme_features',
					'pagenow',
					'sidebars_widgets',
					'wp_actions',
					'wp_current_filter',
					'wp_filesystem',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_plugin_paths',
					'wp_registered_sidebars',
					'wp_stylesheet_path',
					'wp_template_path',
					'wp_theme_directories',
				)
			),
			'options' => self::snapshot_options(),
			'statics' => self::snapshot_statics(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		self::restore_options( $snapshot['options'] );

		foreach ( $snapshot['statics'] as $entry ) {
			self::set_static_property( $entry['class'], $entry['property'], $entry['value'] );
		}
	}

	private static function state_restored( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		if ( self::snapshot_options() !== $snapshot['options'] ) {
			return false;
		}

		foreach ( $snapshot['statics'] as $entry ) {
			if ( self::get_static_property( $entry['class'], $entry['property'] ) !== $entry['value'] ) {
				return false;
			}
		}

		return true;
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

	private static function snapshot_options(): array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return self::clone_value( $GLOBALS['wpdb']->component_fuzz_get_options() );
		}

		return array();
	}

	private static function restore_options( array $options ): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $options );
		}
	}

	private static function snapshot_statics(): array {
		$statics = array();
		foreach ( self::plugin_dependency_static_properties() as $property ) {
			if ( property_exists( 'WP_Plugin_Dependencies', $property ) ) {
				$statics[ 'WP_Plugin_Dependencies::' . $property ] = array(
					'class'    => 'WP_Plugin_Dependencies',
					'property' => $property,
					'value'    => self::get_static_property( 'WP_Plugin_Dependencies', $property ),
				);
			}
		}

		foreach ( array( 'persistently_cache', 'cache_expiration' ) as $property ) {
			if ( property_exists( 'WP_Theme', $property ) ) {
				$statics[ 'WP_Theme::' . $property ] = array(
					'class'    => 'WP_Theme',
					'property' => $property,
					'value'    => self::get_static_property( 'WP_Theme', $property ),
				);
			}
		}

		return $statics;
	}

	private static function reset_plugin_dependency_state(): void {
		if ( ! class_exists( 'WP_Plugin_Dependencies' ) ) {
			return;
		}

		foreach ( self::plugin_dependency_static_properties() as $property ) {
			if ( property_exists( 'WP_Plugin_Dependencies', $property ) ) {
				self::set_static_property( 'WP_Plugin_Dependencies', $property, null );
			}
		}
		if ( property_exists( 'WP_Plugin_Dependencies', 'initialized' ) ) {
			self::set_static_property( 'WP_Plugin_Dependencies', 'initialized', false );
		}
	}

	private static function plugin_dependency_static_properties(): array {
		return array(
			'plugins',
			'plugin_dirnames',
			'dependencies',
			'dependency_slugs',
			'dependent_slugs',
			'dependency_api_data',
			'dependency_filepaths',
			'circular_dependencies_pairs',
			'circular_dependencies_slugs',
			'initialized',
		);
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
			return null;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		return self::clone_value( $reflection->getValue() );
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
			return;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
	}

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) ) {
			return clone $value;
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

	private static function record_failure( array &$failures, string $check, array $data = array() ): void {
		if ( count( $failures ) >= self::MAX_FAILURES ) {
			return;
		}

		$failures[] = array(
			'check' => $check,
			'data'  => self::compact_data( $data ),
		);
	}

	private static function describe_wp_error( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return self::describe_value( $value );
		}

		return array(
			'codes'    => $value->get_error_codes(),
			'messages' => $value->get_error_messages(),
			'data'     => self::compact_data( $value->get_all_error_data() ),
		);
	}

	private static function describe_value( $value ) {
		if ( is_array( $value ) ) {
			return array(
				'type'  => 'array',
				'count' => count( $value ),
				'value' => self::compact_data( $value ),
			);
		}
		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
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
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = self::preview( $value );
			} elseif ( is_object( $value ) ) {
				$data[ $key ] = self::describe_value( $value );
			}
		}

		return $data;
	}

	private static function preview( string $value, int $limit = self::PREVIEW_BYTES ): string {
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
