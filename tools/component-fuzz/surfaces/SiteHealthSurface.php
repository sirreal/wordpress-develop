<?php
namespace ComponentFuzz\Surfaces;

final class SiteHealthSurface {
	public const NAME = 'site-health';

	private const MAX_FAILURES = 12;

	private static array $site_transients = array();
	private static array $site_options    = array();
	private static array $options         = array();
	private static array $granted_caps    = array();
	private static ?array $site_status_tests_case = null;
	private static array $site_status_filter_seen = array();
	private static array $site_status_result_filter_seen = array();
	private static array $generated_test_calls = array();
	private static ?\WP_Error $https_detection_error = null;
	private static ?bool $replace_override           = null;
	private static int $http_request_count           = 0;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'site-health.bootstrap-apis-available',
					'Required WordPress Site Health/update/HTTPS APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::install_filters();

			$case = self::case_for_context( $ctx );
			self::prime_update_state( $case );

			$rows[] = self::check_core_updates( $ctx, $case );
			$rows[] = self::check_plugin_updates( $ctx, $case );
			$rows[] = self::check_theme_updates( $ctx, $case );
			$rows[] = self::check_update_data( $ctx, $case );
			$rows[] = self::check_https_url_helpers( $ctx, $case );
			$rows[] = self::check_https_migration( $ctx, $case );
			$rows[] = self::check_https_detection_short_circuit( $ctx, $case );
			$rows[] = self::check_site_health_direct_tests( $ctx, $case );
			$rows[] = self::check_persistent_object_cache_direct_test( $ctx, $case );
			$rows[] = self::check_site_status_test_registry( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'site-health.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_static_state();
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'site-health.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'timezone'       => date_default_timezone_get(),
			)
		);

		return $rows;
	}

	public static function filter_site_transient( $pre_site_transient, string $transient ) {
		unset( $pre_site_transient );

		if ( array_key_exists( $transient, self::$site_transients ) ) {
			return self::$site_transients[ $transient ];
		}

		return false;
	}

	public static function filter_site_option(
		$pre_site_option,
		string $option = '',
		$network_id = null,
		$default_value = false
	) {
		unset( $pre_site_option, $network_id, $default_value );

		if ( array_key_exists( $option, self::$site_options ) ) {
			return self::$site_options[ $option ];
		}

		return false;
	}

	public static function filter_option( $pre_option, string $option = '', $default_value = false ) {
		unset( $pre_option, $default_value );

		if ( array_key_exists( $option, self::$options ) ) {
			return self::$options[ $option ];
		}

		return false;
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

	public static function filter_https_detection_errors( $pre ) {
		if ( self::$https_detection_error instanceof \WP_Error ) {
			return self::$https_detection_error;
		}

		return $pre;
	}

	public static function filter_pre_http_request( $preempt, array $parsed_args, string $url ) {
		unset( $preempt, $parsed_args, $url );

		++self::$http_request_count;
		return new \WP_Error( 'component_fuzz_network_blocked', 'Component fuzz blocked a remote HTTP request.' );
	}

	public static function filter_should_replace_insecure_home_url( bool $should_replace ): bool {
		return null === self::$replace_override ? $should_replace : self::$replace_override;
	}

	public static function filter_site_status_tests( array $tests ): array {
		if ( null === self::$site_status_tests_case ) {
			return $tests;
		}

		self::$site_status_filter_seen[] = array(
			'directKeys'                => array_keys( is_array( $tests['direct'] ?? null ) ? $tests['direct'] : array() ),
			'asyncKeys'                 => array_keys( is_array( $tests['async'] ?? null ) ? $tests['async'] : array() ),
			'hasAuthorizationHeader'    => isset( $tests['async']['authorization_header'] ),
			'hasPageCache'              => isset( $tests['async']['page_cache'] ),
			'hasPersistentObjectCache'  => isset( $tests['direct']['persistent_object_cache'] ),
			'environmentType'           => \wp_get_environment_type(),
			'basicAuthProtected'        => \wp_is_site_protected_by_basic_auth(),
		);

		$generated = self::generated_site_status_tests( self::$site_status_tests_case );
		$mode      = self::$site_status_tests_case['mode'];

		if ( 'append' === $mode ) {
			$tests['direct'] = array_merge( $tests['direct'] ?? array(), $generated['direct'] );
			$tests['async']  = array_merge( $tests['async'] ?? array(), $generated['async'] );
			return $tests;
		}

		if ( 'direct-only' === $mode ) {
			return array(
				'direct' => $generated['direct'],
			);
		}

		if ( 'async-only' === $mode ) {
			return array(
				'async' => $generated['async'],
			);
		}

		return $generated;
	}

	public static function filter_site_status_test_result( array $result ): array {
		if ( null === self::$site_status_tests_case ) {
			return $result;
		}

		$test = $result['test'] ?? null;
		if ( ! is_string( $test ) || ! isset( self::$site_status_tests_case['results'][ $test ] ) ) {
			return $result;
		}

		$expected = self::$site_status_tests_case['results'][ $test ];

		self::$site_status_result_filter_seen[] = $test;

		$result['status']         = $expected['filteredStatus'];
		$result['badge']['color'] = $expected['filteredBadgeColor'];
		$result['actions']        = $expected['filteredActions'];

		return $result;
	}

	public static function filter_false(): bool {
		return false;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Error', 'WP_Site_Health', 'WP_Theme', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'current_user_can',
				'get_core_updates',
				'get_plugin_updates',
				'get_site_option',
				'get_site_transient',
				'get_theme_updates',
				'home_url',
				'is_wp_error',
				'remove_filter',
				'rest_url',
				'site_url',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
				'wp_convert_hr_to_bytes',
				'wp_get_https_detection_errors',
				'wp_get_translation_updates',
				'wp_get_update_data',
				'wp_get_environment_type',
				'wp_is_home_url_using_https',
				'wp_is_site_url_using_https',
				'wp_is_site_protected_by_basic_auth',
				'wp_is_using_https',
				'wp_load_alloptions',
				'wp_parse_url',
				'wp_replace_insecure_home_url',
				'wp_should_replace_insecure_home_url',
				'wp_using_ext_object_cache',
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
		\add_filter( 'pre_option', array( self::class, 'filter_option' ), 10, 3 );
		\add_filter( 'user_has_cap', array( self::class, 'filter_user_has_cap' ), 10, 4 );
		\add_filter( 'pre_wp_get_https_detection_errors', array( self::class, 'filter_https_detection_errors' ) );
		\add_filter( 'pre_http_request', array( self::class, 'filter_pre_http_request' ), 10, 3 );
		\add_filter( 'wp_should_replace_insecure_home_url', array( self::class, 'filter_should_replace_insecure_home_url' ) );
		\add_filter( 'got_rewrite', array( self::class, 'filter_false' ) );
		\add_filter( 'site_status_tests', array( self::class, 'filter_site_status_tests' ) );
		\add_filter( 'site_status_test_result', array( self::class, 'filter_site_status_test_result' ) );

		$GLOBALS['current_user'] = new \WP_User( 0 );
	}

	private static function prime_update_state( array $case ): void {
		self::$site_transients = array(
			'update_core'    => $case['updates']['coreTransient'],
			'update_plugins' => $case['updates']['pluginTransient'],
			'update_themes'  => $case['updates']['themeTransient'],
		);
		self::$site_options    = array(
			'dismissed_update_core' => $case['updates']['dismissedCore'],
		);
		self::$granted_caps    = $case['updates']['caps'];
	}

	private static function check_core_updates( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures  = array();
		$updates   = $case['updates']['coreUpdates'];
		$dismissed = $case['updates']['dismissedCore'];

		$available_actual = \get_core_updates();
		$dismissed_actual = \get_core_updates(
			array(
				'available' => false,
				'dismissed' => true,
			)
		);
		$both_actual      = \get_core_updates(
			array(
				'available' => true,
				'dismissed' => true,
			)
		);
		$none_actual      = \get_core_updates(
			array(
				'available' => false,
				'dismissed' => false,
			)
		);

		$available_expected = self::expected_core_updates( $updates, $dismissed, true, false );
		$dismissed_expected = self::expected_core_updates( $updates, $dismissed, false, true );
		$both_expected      = self::expected_core_updates( $updates, $dismissed, true, true );

		self::collect_failure(
			$failures,
			is_array( $available_actual )
				&& is_array( $dismissed_actual )
				&& is_array( $both_actual )
				&& is_array( $none_actual ),
			'get_core_updates returns arrays for valid transient shape',
			array(
				'available' => self::describe_value( $available_actual ),
				'dismissed' => self::describe_value( $dismissed_actual ),
				'both'      => self::describe_value( $both_actual ),
				'none'      => self::describe_value( $none_actual ),
			)
		);

		if (
			is_array( $available_actual )
			&& is_array( $dismissed_actual )
			&& is_array( $both_actual )
			&& is_array( $none_actual )
		) {
			self::collect_failure(
				$failures,
				self::core_update_keys( $available_expected ) === self::core_update_keys( $available_actual )
					&& self::core_update_keys( $dismissed_expected ) === self::core_update_keys( $dismissed_actual )
					&& self::core_update_keys( $both_expected ) === self::core_update_keys( $both_actual )
					&& array() === $none_actual,
				'available/dismissed core update partitions match dismissed option',
				array(
					'availableExpected' => self::core_update_keys( $available_expected ),
					'availableActual'   => self::core_update_keys( $available_actual ),
					'dismissedExpected' => self::core_update_keys( $dismissed_expected ),
					'dismissedActual'   => self::core_update_keys( $dismissed_actual ),
					'bothExpected'      => self::core_update_keys( $both_expected ),
					'bothActual'        => self::core_update_keys( $both_actual ),
				)
			);

			self::collect_failure(
				$failures,
				self::core_dismissed_flags_are_expected( $available_actual, false )
					&& self::core_dismissed_flags_are_expected( $dismissed_actual, true )
					&& count( $both_actual ) === count( $available_actual ) + count( $dismissed_actual ),
				'core dismissed flags and partition sizes are consistent',
				array(
					'availableFlags' => self::core_dismissed_flags( $available_actual ),
					'dismissedFlags' => self::core_dismissed_flags( $dismissed_actual ),
					'bothCount'      => count( $both_actual ),
				)
			);
		}

		return $ctx->result(
			'site-health.updates.core-dismissed-partition',
			array() === $failures,
			array(
				'generated' => count( $updates ),
				'dismissed' => array_keys( $dismissed ),
				'failures'  => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_plugin_updates( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		\wp_cache_set( 'plugins', array( '' => $case['updates']['plugins'] ), 'plugins' );
		$actual = \get_plugin_updates();

		$expected_keys = array_values(
			array_intersect(
				array_keys( $case['updates']['pluginResponses'] ),
				array_keys( $case['updates']['plugins'] )
			)
		);
		sort( $expected_keys );

		$actual_keys = array_keys( $actual );
		sort( $actual_keys );

		self::collect_failure(
			$failures,
			$expected_keys === $actual_keys,
			'get_plugin_updates returns installed plugins with response entries only',
			array(
				'expected' => $expected_keys,
				'actual'   => $actual_keys,
			)
		);

		foreach ( $expected_keys as $plugin_file ) {
			$plugin = $actual[ $plugin_file ] ?? null;
			self::collect_failure(
				$failures,
				is_object( $plugin )
					&& isset( $plugin->update )
					&& $plugin->update === $case['updates']['pluginResponses'][ $plugin_file ]
					&& $plugin->Name === $case['updates']['plugins'][ $plugin_file ]['Name']
					&& $plugin->Version === $case['updates']['plugins'][ $plugin_file ]['Version'],
				"plugin update object shape for {$plugin_file}",
				array(
					'plugin' => $plugin_file,
					'actual' => self::describe_value( $plugin ),
				)
			);
		}

		return $ctx->result(
			'site-health.updates.plugin-response-shape',
			array() === $failures,
			array(
				'installed' => count( $case['updates']['plugins'] ),
				'updates'   => count( $case['updates']['pluginResponses'] ),
				'failures'  => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_theme_updates( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$actual   = \get_theme_updates();

		$expected_keys = array_keys( $case['updates']['themeResponses'] );
		sort( $expected_keys );

		$actual_keys = array_keys( $actual );
		sort( $actual_keys );

		self::collect_failure(
			$failures,
			$expected_keys === $actual_keys,
			'get_theme_updates returns response keys exactly',
			array(
				'expected' => $expected_keys,
				'actual'   => $actual_keys,
			)
		);

		foreach ( $case['updates']['themeResponses'] as $stylesheet => $response ) {
			$theme = $actual[ $stylesheet ] ?? null;
			self::collect_failure(
				$failures,
				$theme instanceof \WP_Theme
					&& isset( $theme->update )
					&& $theme->update === $response,
				"theme update object shape for {$stylesheet}",
				array(
					'theme'  => $stylesheet,
					'actual' => self::describe_value( $theme ),
				)
			);
		}

		return $ctx->result(
			'site-health.updates.theme-response-shape',
			array() === $failures,
			array(
				'updates'  => count( $case['updates']['themeResponses'] ),
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_update_data( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$actual   = \wp_get_update_data();
		$expected = self::expected_update_data( $case );

		self::collect_failure(
			$failures,
			is_array( $actual )
				&& isset( $actual['counts'], $actual['title'] )
				&& $expected['counts'] === $actual['counts']
				&& $expected['title'] === $actual['title'],
			'wp_get_update_data counts and title match generated transients/caps',
			array(
				'expected' => $expected,
				'actual'   => $actual,
				'caps'     => $case['updates']['caps'],
			)
		);

		if ( is_array( $actual ) && isset( $actual['counts'] ) && is_array( $actual['counts'] ) ) {
			$count_sum = (int) $actual['counts']['plugins']
				+ (int) $actual['counts']['themes']
				+ (int) $actual['counts']['wordpress']
				+ (int) $actual['counts']['translations'];

			self::collect_failure(
				$failures,
				$count_sum === (int) $actual['counts']['total'],
				'wp_get_update_data total equals component count sum',
				array(
					'sum'    => $count_sum,
					'counts' => $actual['counts'],
				)
			);
		}

		return $ctx->result(
			'site-health.updates.aggregate-counts-title',
			array() === $failures,
			array(
				'counts'   => $actual['counts'] ?? null,
				'title'    => $actual['title'] ?? null,
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_https_url_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		self::apply_https_case( $case['https'] );

		$expected_home  = 'https' === \wp_parse_url( $case['https']['home'], PHP_URL_SCHEME );
		$expected_site  = 'https' === \wp_parse_url( $case['https']['siteurl'], PHP_URL_SCHEME );
		$expected_using = $expected_home && $expected_site;

		self::collect_failure(
			$failures,
			$expected_home === \wp_is_home_url_using_https()
				&& $expected_site === \wp_is_site_url_using_https()
				&& $expected_using === \wp_is_using_https(),
			'HTTPS booleans match generated home/siteurl schemes',
			array(
				'home'         => $case['https']['home'],
				'siteurl'      => $case['https']['siteurl'],
				'expectedHome' => $expected_home,
				'actualHome'   => \wp_is_home_url_using_https(),
				'expectedSite' => $expected_site,
				'actualSite'   => \wp_is_site_url_using_https(),
				'actualUsing'  => \wp_is_using_https(),
			)
		);

		return $ctx->result(
			'site-health.https.option-booleans',
			array() === $failures,
			array(
				'home'     => $case['https']['home'],
				'siteurl'  => $case['https']['siteurl'],
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_https_migration( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		self::apply_https_case( $case['https'] );

		$natural_should = self::expected_should_replace_insecure_home_url( $case['https'] );
		$content        = $case['https']['migrationContent'];
		$expected       = self::expected_replaced_content( $content );

		self::$replace_override = null;
		$actual_should          = \wp_should_replace_insecure_home_url();
		$actual_content         = \wp_replace_insecure_home_url( $content );

		self::collect_failure(
			$failures,
			$natural_should === $actual_should
				&& ( $natural_should ? $expected : $content ) === $actual_content,
			'HTTPS migration replacement follows exact option condition',
			array(
				'naturalShould' => $natural_should,
				'actualShould'  => $actual_should,
				'expected'      => self::preview( $natural_should ? $expected : $content ),
				'actual'        => self::preview( $actual_content ),
			)
		);

		self::$replace_override = false;
		self::collect_failure(
			$failures,
			false === \wp_should_replace_insecure_home_url()
				&& $content === \wp_replace_insecure_home_url( $content ),
			'wp_should_replace_insecure_home_url filter can force disabled migration',
			array()
		);

		self::$replace_override = true;
		self::collect_failure(
			$failures,
			true === \wp_should_replace_insecure_home_url()
				&& $expected === \wp_replace_insecure_home_url( $content ),
			'wp_should_replace_insecure_home_url filter can force enabled migration',
			array( 'expectedForced' => self::preview( $expected ) )
		);
		self::$replace_override = null;

		return $ctx->result(
			'site-health.https.migration-replacement',
			array() === $failures,
			array(
				'migrationRequired' => $case['https']['migrationRequired'],
				'sameHost'          => $case['https']['sameHost'],
				'failures'          => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_https_detection_short_circuit( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$error    = self::wp_error_from_case( $case['httpsDetectionErrors'] );

		self::$https_detection_error = $error;
		self::$http_request_count    = 0;

		$actual = \wp_get_https_detection_errors();

		self::collect_failure(
			$failures,
			$error->errors === $actual && 0 === self::$http_request_count,
			'wp_get_https_detection_errors returns pre-filter errors without HTTP requests',
			array(
				'expectedErrors' => $error->errors,
				'actualErrors'   => $actual,
				'httpRequests'   => self::$http_request_count,
			)
		);

		self::$https_detection_error = new \WP_Error();
		self::$http_request_count    = 0;
		$empty_actual                = \wp_get_https_detection_errors();

		self::collect_failure(
			$failures,
			array() === $empty_actual && 0 === self::$http_request_count,
			'empty WP_Error short-circuits HTTPS detection as supported',
			array(
				'actualErrors' => $empty_actual,
				'httpRequests' => self::$http_request_count,
			)
		);

		self::$https_detection_error = null;

		return $ctx->result(
			'site-health.https.detection-short-circuit',
			array() === $failures,
			array(
				'errorCodes' => array_keys( $error->errors ),
				'failures'   => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_site_health_direct_tests( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures    = array();
		$site_health = self::site_health_without_constructor();

		self::apply_https_case( $case['https'] );
		self::$https_detection_error = self::wp_error_from_case( $case['siteHealthHttpsErrors'] );
		self::$http_request_count    = 0;
		self::$granted_caps['update_https'] = $case['siteHealthUpdateHttpsCap'];

		date_default_timezone_set( $case['siteHealthTimezone'] );
		self::apply_authorization_case( $case['authorization'] );
		self::$options['blog_public'] = $case['blogPublic'];

		$debug_result      = $site_health->get_test_is_in_debug_mode();
		$https_result      = $site_health->get_test_https_status();
		$timezone_result   = $site_health->get_test_php_default_timezone();
		$auth_result       = $site_health->get_test_authorization_header();
		$visibility_result = $site_health->get_test_search_engine_visibility();
		$uploads_result    = $site_health->get_test_file_uploads();

		self::assert_site_health_result( $failures, $debug_result, 'is_in_debug_mode', 'good' );
		self::assert_site_health_result(
			$failures,
			$https_result,
			'https_status',
			\wp_is_using_https() ? 'good' : 'recommended'
		);
		self::assert_site_health_result(
			$failures,
			$timezone_result,
			'php_default_timezone',
			'UTC' === $case['siteHealthTimezone'] ? 'good' : 'critical'
		);
		self::assert_site_health_result(
			$failures,
			$auth_result,
			'authorization_header',
			'valid' === $case['authorization']['mode'] ? 'good' : 'recommended'
		);
		self::assert_site_health_result(
			$failures,
			$visibility_result,
			'search_engine_visibility',
			$case['blogPublic'] ? 'good' : 'recommended'
		);
		self::assert_site_health_result( $failures, $uploads_result, 'file_uploads', self::expected_file_upload_status() );

		self::collect_failure(
			$failures,
			0 === self::$http_request_count,
			'selected Site Health direct tests do not make HTTP requests',
			array( 'httpRequests' => self::$http_request_count )
		);

		self::$https_detection_error = null;

		return $ctx->result(
			'site-health.direct-tests.result-shape-status',
			array() === $failures,
			array(
				'statuses' => array(
					'debug'      => $debug_result['status'] ?? null,
					'https'      => $https_result['status'] ?? null,
					'timezone'   => $timezone_result['status'] ?? null,
					'auth'       => $auth_result['status'] ?? null,
					'visibility' => $visibility_result['status'] ?? null,
					'uploads'    => $uploads_result['status'] ?? null,
				),
				'debugConstants' => array(
					'WP_DEBUG'         => defined( 'WP_DEBUG' ) ? WP_DEBUG : null,
					'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : null,
					'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null,
				),
				'failures'       => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_persistent_object_cache_direct_test( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures    = array();
		$site_health = self::site_health_without_constructor();
		$statuses    = array();

		self::$http_request_count = 0;

		foreach ( self::persistent_object_cache_scenarios( $case['persistentObjectCache'] ) as $scenario ) {
			$result             = null;
			$alloptions_calls   = 0;
			$short_circuit_calls = 0;
			$notes_filter_seen  = array();
			$services_seen      = array();
			$ext_snapshot       = self::snapshot_runtime_global( '_wp_using_ext_object_cache' );
			$wpdb_snapshot      = self::snapshot_runtime_global( 'wpdb' );
			$alloptions_cache   = self::snapshot_cache_slot( 'alloptions', 'options' );
			$wpdb               = new SiteHealthPersistentObjectCacheWpdbDouble(
				$GLOBALS['wpdb'] ?? null,
				$scenario['tableRows']
			);

			$action_url_filter = static function () use ( $scenario ): string {
				return $scenario['actionUrl'];
			};
			$short_circuit_filter = static function ( $suggest ) use ( $scenario, &$short_circuit_calls ) {
				unset( $suggest );

				++$short_circuit_calls;
				return $scenario['shortCircuit'];
			};
			$threshold_filter = static function () use ( $scenario ): array {
				return $scenario['thresholds'];
			};
			$alloptions_filter = static function ( $alloptions, bool $force_cache ) use ( $scenario, &$alloptions_calls ): array {
				unset( $alloptions, $force_cache );

				++$alloptions_calls;
				return $scenario['alloptions'];
			};
			$services_filter = static function ( array $services ) use ( $scenario, &$services_seen ): array {
				$services_seen[] = $services;
				return $scenario['services'];
			};
			$notes_filter = static function ( string $notes, array $available_services ) use ( $scenario, &$notes_filter_seen ): string {
				$notes_filter_seen[] = array(
					'notes'    => $notes,
					'services' => $available_services,
				);

				if ( '' === $scenario['unsafeNote'] ) {
					return $notes;
				}

				return $notes . ' ' . $scenario['unsafeNote'];
			};

			try {
				\add_filter( 'site_status_persistent_object_cache_url', $action_url_filter );
				\add_filter( 'site_status_should_suggest_persistent_object_cache', $short_circuit_filter );
				\add_filter( 'site_status_persistent_object_cache_thresholds', $threshold_filter );
				\add_filter( 'pre_wp_load_alloptions', $alloptions_filter, 10, 2 );
				\add_filter( 'site_status_available_object_cache_services', $services_filter );
				\add_filter( 'site_status_persistent_object_cache_notes', $notes_filter, 10, 2 );

				$GLOBALS['wpdb'] = $wpdb;
				\wp_using_ext_object_cache( $scenario['extObjectCache'] );

				$result = $site_health->get_test_persistent_object_cache();
			} finally {
				\remove_filter( 'site_status_persistent_object_cache_url', $action_url_filter );
				\remove_filter( 'site_status_should_suggest_persistent_object_cache', $short_circuit_filter );
				\remove_filter( 'site_status_persistent_object_cache_thresholds', $threshold_filter );
				\remove_filter( 'pre_wp_load_alloptions', $alloptions_filter, 10 );
				\remove_filter( 'site_status_available_object_cache_services', $services_filter );
				\remove_filter( 'site_status_persistent_object_cache_notes', $notes_filter, 10 );
				self::restore_runtime_global( 'wpdb', $wpdb_snapshot );
				self::restore_runtime_global( '_wp_using_ext_object_cache', $ext_snapshot );
				self::restore_cache_slot( 'alloptions', 'options', $alloptions_cache );
			}

			$statuses[ $scenario['name'] ] = is_array( $result ) ? ( $result['status'] ?? null ) : null;

			self::assert_site_health_result( $failures, $result, 'persistent_object_cache', $scenario['expectedStatus'] );

			self::collect_failure(
				$failures,
				is_array( $result )
					&& self::html_contains_url( (string) ( $result['actions'] ?? '' ), $scenario['actionUrl'] ),
				"persistent object cache action URL propagates for {$scenario['name']}",
				array(
					'actionUrl' => $scenario['actionUrl'],
					'actions'   => is_array( $result ) ? self::preview( (string) ( $result['actions'] ?? '' ) ) : null,
				)
			);

			if ( '' !== $scenario['labelContains'] ) {
				self::collect_failure(
					$failures,
					is_array( $result )
						&& false !== stripos( (string) ( $result['label'] ?? '' ), $scenario['labelContains'] ),
					"persistent object cache label invariant for {$scenario['name']}",
					array(
						'expectedContains' => $scenario['labelContains'],
						'label'            => is_array( $result ) ? ( $result['label'] ?? null ) : null,
					)
				);
			}

			self::collect_failure(
				$failures,
				$scenario['expectedShortCircuitCalls'] === $short_circuit_calls,
				"persistent object cache short-circuit call count for {$scenario['name']}",
				array(
					'expected' => $scenario['expectedShortCircuitCalls'],
					'actual'   => $short_circuit_calls,
				)
			);

			self::collect_failure(
				$failures,
				$scenario['expectedAlloptionsCalls'] === $alloptions_calls,
				"persistent object cache alloptions call count for {$scenario['name']}",
				array(
					'expected' => $scenario['expectedAlloptionsCalls'],
					'actual'   => $alloptions_calls,
				)
			);

			self::collect_failure(
				$failures,
				$scenario['expectedDbQueries'] === $wpdb->get_results_calls
					&& $scenario['expectedDbQueries'] === $wpdb->prepare_calls,
				"persistent object cache information_schema query count for {$scenario['name']}",
				array(
					'expected' => $scenario['expectedDbQueries'],
					'prepared' => $wpdb->prepared_queries,
					'queries'  => $wpdb->result_queries,
				)
			);

			if ( $scenario['expectedDbQueries'] > 0 ) {
				self::collect_failure(
					$failures,
					OBJECT_K === $wpdb->last_output
						&& false !== strpos( $wpdb->last_query, 'information_schema.TABLES' )
						&& isset( $wpdb->prepared_queries[0]['args'][0] )
						&& DB_NAME === $wpdb->prepared_queries[0]['args'][0]
						&& $wpdb->expected_table_names() === $wpdb->last_requested_tables,
					"persistent object cache table-row threshold uses prepared information_schema OBJECT_K query for {$scenario['name']}",
					array(
						'expectedTables'  => $wpdb->expected_table_names(),
						'requestedTables' => $wpdb->last_requested_tables,
						'lastOutput'      => $wpdb->last_output,
						'lastQuery'       => self::preview( $wpdb->last_query ),
						'prepared'        => $wpdb->prepared_queries,
					)
				);
			}

			if ( $scenario['expectFilteredNotes'] ) {
				$description = is_array( $result ) ? (string) ( $result['description'] ?? '' ) : '';
				$notes_seen  = $notes_filter_seen[0] ?? null;

				self::collect_failure(
					$failures,
					is_array( $notes_seen )
						&& $scenario['services'] === $notes_seen['services']
						&& self::all_strings_contained( $description, $scenario['services'] )
						&& self::all_strings_contained( (string) $notes_seen['notes'], $scenario['services'] ),
					'persistent object cache filtered services are passed to notes and rendered',
					array(
						'services'    => $scenario['services'],
						'notesSeen'   => $notes_seen,
						'description' => self::preview( $description ),
						'servicesFilterSeen' => $services_seen,
					)
				);

				self::collect_failure(
					$failures,
					false !== strpos( $description, $scenario['safeNoteToken'] )
						&& false !== strpos( $description, '<strong>allowed-note</strong>' )
						&& false === strpos( $description, '<script' )
						&& false === strpos( $description, '</script>' )
						&& false === strpos( $description, '<img' )
						&& false === strpos( $description, 'onerror' ),
					'persistent object cache notes allow safe markup and remove unsafe markup',
					array(
						'safeNoteToken' => $scenario['safeNoteToken'],
						'description'   => self::preview( $description ),
					)
				);
			}

			self::collect_failure(
				$failures,
				self::runtime_global_matches( '_wp_using_ext_object_cache', $ext_snapshot )
					&& self::runtime_global_matches( 'wpdb', $wpdb_snapshot )
					&& self::cache_slot_matches( 'alloptions', 'options', $alloptions_cache ),
				"persistent object cache scenario restores ext-cache/wpdb/alloptions state for {$scenario['name']}",
				array(
					'extObjectCacheRestored' => self::runtime_global_matches( '_wp_using_ext_object_cache', $ext_snapshot ),
					'wpdbRestored'           => self::runtime_global_matches( 'wpdb', $wpdb_snapshot ),
					'alloptionsRestored'     => self::cache_slot_matches( 'alloptions', 'options', $alloptions_cache ),
				)
			);
		}

		self::collect_failure(
			$failures,
			0 === self::$http_request_count,
			'persistent object cache direct test does not make HTTP requests',
			array( 'httpRequests' => self::$http_request_count )
		);

		return $ctx->result(
			'site-health.direct-tests.persistent-object-cache-thresholds',
			array() === $failures,
			array(
				'statuses'      => $statuses,
				'alloptionsMode' => $case['persistentObjectCache']['alloptionsMode'],
				'tableThreshold' => $case['persistentObjectCache']['tableThreshold'],
				'failures'      => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_site_status_test_registry( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures    = array();
		$registry    = $case['siteStatusTests'];
		$site_health = self::site_health_without_constructor();

		self::set_site_health_instance( $site_health );
		self::apply_https_case( $case['https'] );
		self::apply_basic_auth_protection( $registry['basicAuthProtected'] );

		self::$site_status_tests_case          = $registry;
		self::$site_status_filter_seen         = array();
		self::$site_status_result_filter_seen  = array();
		self::$generated_test_calls            = array();
		self::$http_request_count              = 0;

		$tests = \WP_Site_Health::get_tests();

		self::collect_failure(
			$failures,
			is_array( $tests )
				&& isset( $tests['direct'], $tests['async'] )
				&& is_array( $tests['direct'] )
				&& is_array( $tests['async'] ),
			'WP_Site_Health::get_tests normalizes direct and async groups after filtering',
			array(
				'mode' => $registry['mode'],
				'keys' => is_array( $tests ) ? array_keys( $tests ) : array(),
			)
		);

		$seen = self::$site_status_filter_seen[0] ?? null;
		self::collect_failure(
			$failures,
			1 === count( self::$site_status_filter_seen )
				&& is_array( $seen )
				&& ( ! $registry['basicAuthProtected'] ) === $seen['hasAuthorizationHeader']
				&& ( 'production' === $seen['environmentType'] ) === $seen['hasPageCache']
				&& ( 'production' === $seen['environmentType'] ) === $seen['hasPersistentObjectCache'],
			'site_status_tests filter observes environment and basic-auth gated defaults',
			array(
				'mode'                  => $registry['mode'],
				'basicAuthProtected'    => $registry['basicAuthProtected'],
				'observationCount'      => count( self::$site_status_filter_seen ),
				'observed'              => $seen,
			)
		);

		if ( is_array( $tests ) && isset( $tests['direct'], $tests['async'] ) ) {
			$expected_keys = self::expected_site_status_registry_keys( $registry, $seen );
			$actual_keys   = array(
				'direct' => array_keys( $tests['direct'] ),
				'async'  => array_keys( $tests['async'] ),
			);

			self::collect_failure(
				$failures,
				$expected_keys === $actual_keys,
				'filtered Site Health test registry keys match generated mode',
				array(
					'mode'     => $registry['mode'],
					'expected' => $expected_keys,
					'actual'   => $actual_keys,
				)
			);

			self::assert_generated_direct_tests( $failures, $site_health, $tests['direct'], $registry );
			self::assert_generated_async_tests( $failures, $site_health, $tests['async'], $registry );
		}

		self::collect_failure(
			$failures,
			self::expected_performed_site_status_tests( $registry ) === self::$site_status_result_filter_seen,
			'site_status_test_result filter runs once for each performed generated test',
			array(
				'expected' => self::expected_performed_site_status_tests( $registry ),
				'actual'   => self::$site_status_result_filter_seen,
			)
		);

		self::collect_failure(
			$failures,
			self::expected_generated_test_call_counts( $registry ) === self::$generated_test_calls,
			'generated Site Health callbacks run exactly once when directly performed',
			array(
				'expected' => self::expected_generated_test_call_counts( $registry ),
				'actual'   => self::$generated_test_calls,
			)
		);

		self::collect_failure(
			$failures,
			0 === self::$http_request_count,
			'Site Health test registry invariant does not make HTTP requests',
			array( 'httpRequests' => self::$http_request_count )
		);

		self::$site_status_tests_case = null;

		return $ctx->result(
			'site-health.tests.registry-filter-direct-callbacks',
			array() === $failures,
			array(
				'mode'         => $registry['mode'],
				'directCount'  => count( $registry['direct'] ),
				'asyncCount'   => count( $registry['async'] ),
				'performed'    => self::expected_performed_site_status_tests( $registry ),
				'failures'     => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'updates'                 => self::updates_case( $ctx->fork( 'updates' ) ),
			'https'                  => self::https_case( $ctx->fork( 'https' ) ),
			'httpsDetectionErrors'   => self::https_error_case( $ctx->fork( 'https-detection' ) ),
			'siteHealthHttpsErrors'  => $ctx->bool() ? array() : self::https_error_case( $ctx->fork( 'site-health-https' ) ),
			'siteHealthTimezone'     => $ctx->choice( array( 'UTC', 'Europe/Madrid', 'America/New_York' ) ),
			'siteHealthUpdateHttpsCap' => $ctx->bool(),
			'authorization'          => self::authorization_case( $ctx->fork( 'authorization' ) ),
			'blogPublic'             => $ctx->bool() ? 1 : 0,
			'persistentObjectCache'  => self::persistent_object_cache_case( $ctx->fork( 'persistent-object-cache' ) ),
			'siteStatusTests'        => self::site_status_tests_case( $ctx->fork( 'site-status-tests' ) ),
		);
	}

	private static function updates_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$plugin_ctx       = $ctx->fork( 'plugins' );
		$theme_ctx        = $ctx->fork( 'themes' );
		$core_ctx         = $ctx->fork( 'core' );
		$translation_ctx  = $ctx->fork( 'translations' );
		$plugins          = array();
		$plugin_responses = array();
		$plugin_checked   = array();
		$used             = array();
		$plugin_count     = $plugin_ctx->int( 1, 4 );

		for ( $i = 0; $i < $plugin_count; ++$i ) {
			$slug        = self::slug( $plugin_ctx->fork( 'slug-' . $i ), 'plugin', $used );
			$plugin_file = "{$slug}/{$slug}.php";
			$version     = self::version( $plugin_ctx->fork( 'version-' . $i ) );

			$plugins[ $plugin_file ] = array(
				'Name'        => 'Component Fuzz ' . $slug,
				'PluginURI'   => "https://example.test/plugins/{$slug}",
				'Version'     => $version,
				'Description' => 'Generated plugin update fixture.',
				'Author'      => 'Component Fuzz',
				'AuthorURI'   => 'https://example.test/',
				'TextDomain'  => $slug,
				'DomainPath'  => '',
				'Network'     => false,
				'RequiresWP'  => '6.0',
				'RequiresPHP' => '7.4',
				'UpdateURI'   => '',
			);

			$plugin_checked[ $plugin_file ] = $version;
			if ( $plugin_ctx->bool( 70 ) || ( 0 === $i && array() === $plugin_responses ) ) {
				$plugin_responses[ $plugin_file ] = (object) array(
					'id'            => "w.org/plugins/{$slug}",
					'slug'          => $slug,
					'plugin'        => $plugin_file,
					'new_version'   => self::version( $plugin_ctx->fork( 'new-version-' . $i ), 7 ),
					'url'           => "https://wordpress.org/plugins/{$slug}/",
					'package'       => "https://downloads.wordpress.org/plugin/{$slug}.zip",
					'icons'         => array(),
					'banners'       => array(),
					'banners_rtl'   => array(),
					'requires'      => '6.0',
					'tested'        => '6.9',
					'requires_php'  => '7.4',
					'compatibility' => new \stdClass(),
				);
			}
		}

		$themes          = array();
		$theme_responses = array();
		$theme_checked   = array();
		$used            = array();
		$theme_count     = $theme_ctx->int( 1, 4 );

		for ( $i = 0; $i < $theme_count; ++$i ) {
			$stylesheet                 = self::slug( $theme_ctx->fork( 'slug-' . $i ), 'theme', $used );
			$themes[]                   = $stylesheet;
			$theme_checked[ $stylesheet ] = self::version( $theme_ctx->fork( 'version-' . $i ) );
			if ( $theme_ctx->bool( 70 ) || ( 0 === $i && array() === $theme_responses ) ) {
				$theme_responses[ $stylesheet ] = array(
					'theme'        => $stylesheet,
					'new_version'  => self::version( $theme_ctx->fork( 'new-version-' . $i ), 7 ),
					'url'          => "https://wordpress.org/themes/{$stylesheet}/",
					'package'      => "https://downloads.wordpress.org/theme/{$stylesheet}.zip",
					'requires'     => '6.0',
					'requires_php' => '7.4',
				);
			}
		}

		$core_updates = array();
		$dismissed    = array();
		$core_count   = $core_ctx->int( 3, 6 );
		$responses    = array( 'upgrade', 'latest', 'development', 'autoupdate' );
		$locales      = array( 'en_US', 'es_ES', 'fr_FR', 'de_DE', 'en_GB' );

		for ( $i = 0; $i < $core_count; ++$i ) {
			$update = (object) array(
				'response' => $responses[ $core_ctx->int( 0, count( $responses ) - 1 ) ],
				'current'  => self::version( $core_ctx->fork( 'current-' . $i ), 6 + $i ),
				'locale'   => $locales[ $core_ctx->int( 0, count( $locales ) - 1 ) ],
				'packages' => (object) array(
					'full' => 'https://downloads.wordpress.org/release.zip',
				),
			);
			$core_updates[] = $update;

			if ( 'autoupdate' !== $update->response && $core_ctx->bool( 35 ) ) {
				$dismissed[ $update->current . '|' . $update->locale ] = true;
			}
		}

		$core_translations = self::translation_cases(
			$translation_ctx->fork( 'core' ),
			$translation_ctx->int( 0, 2 ),
			'core'
		);
		$plugin_translations = self::translation_cases(
			$translation_ctx->fork( 'plugins' ),
			$translation_ctx->int( 0, 2 ),
			'plugin'
		);
		$theme_translations = self::translation_cases(
			$translation_ctx->fork( 'themes' ),
			$translation_ctx->int( 0, 2 ),
			'theme'
		);

		return array(
			'plugins'          => $plugins,
			'pluginResponses'  => $plugin_responses,
			'themes'           => $themes,
			'themeResponses'   => $theme_responses,
			'coreUpdates'      => $core_updates,
			'dismissedCore'    => $dismissed,
			'caps'             => array(
				'update_core'    => $ctx->bool( 80 ),
				'update_plugins' => $ctx->bool( 80 ),
				'update_themes'  => $ctx->bool( 80 ),
				'update_https'   => $ctx->bool(),
			),
			'coreTransient'    => (object) array(
				'last_checked'    => 1700000000,
				'version_checked' => '6.9',
				'updates'         => $core_updates,
				'translations'    => $core_translations,
			),
			'pluginTransient'  => (object) array(
				'last_checked' => 1700000000,
				'checked'      => $plugin_checked,
				'response'     => $plugin_responses,
				'no_update'    => array(),
				'translations' => $plugin_translations,
			),
			'themeTransient'   => (object) array(
				'last_checked' => 1700000000,
				'checked'      => $theme_checked,
				'response'     => $theme_responses,
				'no_update'    => array(),
				'translations' => $theme_translations,
			),
		);
	}

	private static function https_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$hosts     = array(
			'example.test',
			'www.example.test',
			'updates.example.test',
			'fuzz-' . $ctx->int( 10, 999 ) . '.test',
		);
		$home_host = $hosts[ $ctx->int( 0, count( $hosts ) - 1 ) ];
		$same_host = $ctx->bool();
		$site_host = $same_host ? $home_host : $hosts[ $ctx->int( 0, count( $hosts ) - 1 ) ];
		if ( ! $same_host && $site_host === $home_host ) {
			$site_host = 'alt-' . $home_host;
		}

		$home_scheme = $ctx->choice( array( 'http', 'https' ) );
		$site_scheme = $ctx->choice( array( 'http', 'https' ) );
		$home_path   = $ctx->choice( array( '', '/site', '/wp/home-' . $ctx->int( 1, 9 ) ) );
		$site_path   = $ctx->choice( array( '', '/wp', '/core-' . $ctx->int( 1, 9 ) ) );
		$home        = "{$home_scheme}://{$home_host}{$home_path}";
		$siteurl     = "{$site_scheme}://{$site_host}{$site_path}";

		$https_home  = preg_replace( '#^http://#', 'https://', $home );
		$http_home   = preg_replace( '#^https://#', 'http://', $https_home );
		$escaped     = str_replace( '/', '\/', $http_home );
		$other_host  = 'http://not-' . $home_host . $home_path;
		$content     = implode(
			"\n",
			array(
				'plain=' . $http_home,
				'json="' . $escaped . '"',
				'other=' . $other_host,
				'already=' . $https_home,
				'path=' . $http_home . '/child',
			)
		);

		return array(
			'home'               => $home,
			'siteurl'            => $siteurl,
			'sameHost'           => $same_host,
			'migrationRequired'  => $ctx->bool() ? 1 : 0,
			'migrationContent'   => $content,
			'requestIsSsl'       => false,
		);
	}

	private static function https_error_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$errors = array();
		$count  = $ctx->int( 1, 3 );

		for ( $i = 0; $i < $count; ++$i ) {
			$code            = $ctx->choice(
				array(
					'https_request_failed',
					'ssl_verification_failed',
					'bad_response_code',
					'bad_response_source',
				)
			) . '_' . $i;
			$errors[ $code ] = 'Synthetic HTTPS detection error ' . $ctx->int( 1, 999 );
		}

		return $errors;
	}

	private static function authorization_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$mode = $ctx->choice( array( 'valid', 'missing', 'invalid' ) );

		return array(
			'mode' => $mode,
			'user' => 'invalid' === $mode ? 'user-' . $ctx->int( 1, 99 ) : 'user',
			'pass' => 'invalid' === $mode ? 'pwd-' . $ctx->int( 1, 99 ) : 'pwd',
		);
	}

	private static function site_status_tests_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$direct_ctx  = $ctx->fork( 'direct' );
		$async_ctx   = $ctx->fork( 'async' );
		$result_ctx  = $ctx->fork( 'results' );
		$direct      = array();
		$async       = array();
		$results     = array();
		$direct_count = $direct_ctx->int( 1, 3 );
		$async_count = $async_ctx->int( 1, 3 );

		for ( $i = 0; $i < $direct_count; ++$i ) {
			$key            = 'component_fuzz_direct_' . strtolower( $direct_ctx->identifier( 4, 10 ) ) . '_' . $i;
			$key            = preg_replace( '/[^a-z0-9_]+/', '_', $key );
			$direct[ $key ] = array(
				'label'    => 'Component Fuzz Direct ' . $i,
				'skipCron' => $direct_ctx->bool(),
			);
			$results[ $key ] = self::site_status_result_case( $result_ctx->fork( $key ), $key, $direct[ $key ]['label'] );
		}

		for ( $i = 0; $i < $async_count; ++$i ) {
			$key                  = 'component_fuzz_async_' . strtolower( $async_ctx->identifier( 4, 10 ) ) . '_' . $i;
			$key                  = preg_replace( '/[^a-z0-9_]+/', '_', $key );
			$has_rest             = $async_ctx->bool();
			$has_async_direct     = 0 === $i || $async_ctx->bool( 65 );
			$async[ $key ]        = array(
				'label'              => 'Component Fuzz Async ' . $i,
				'test'               => $has_rest
					? '/wp-json/component-fuzz/v1/site-health/' . $key
					: 'component_fuzz_ajax_' . $key,
				'hasRest'            => $has_rest,
				'headers'            => self::site_status_headers_case( $async_ctx->fork( 'headers-' . $i ) ),
				'skipCron'           => $async_ctx->bool(),
				'hasAsyncDirectTest' => $has_async_direct,
			);
			$results[ $key ] = self::site_status_result_case( $result_ctx->fork( $key ), $key, $async[ $key ]['label'] );
		}

		return array(
			'mode'               => $ctx->choice( array( 'append', 'replace', 'direct-only', 'async-only' ) ),
			'basicAuthProtected' => $ctx->bool(),
			'direct'             => $direct,
			'async'              => $async,
			'results'            => $results,
		);
	}

	private static function persistent_object_cache_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$services = array();
		$used     = array();
		$pool     = array( 'Redis', 'Memcached', 'APCu', 'Relay', 'SQLite Object Cache' );
		$count    = $ctx->int( 1, 3 );

		for ( $i = 0; $i < $count; ++$i ) {
			$service = $pool[ $ctx->int( 0, count( $pool ) - 1 ) ];
			if ( isset( $used[ $service ] ) ) {
				$service = 'Generated Cache ' . $ctx->int( 10, 99 );
			}

			$used[ $service ] = true;
			$services[]       = $service;
		}

		return array(
			'actionUrl'          => 'https://example.test/persistent-object-cache/' . strtolower( $ctx->identifier( 5, 10 ) ),
			'alloptionsMode'     => $ctx->choice( array( 'alloptions_count', 'alloptions_bytes' ) ),
			'alloptionsCount'    => $ctx->int( 4, 9 ),
			'alloptionsPayload'  => $ctx->int( 96, 256 ),
			'tableThreshold'     => $ctx->choice(
				array(
					'comments_count',
					'options_count',
					'posts_count',
					'terms_count',
					'users_count',
				)
			),
			'tableLimit'         => $ctx->int( 8, 40 ),
			'services'           => $services,
			'safeNoteToken'      => 'component-fuzz-note-' . strtolower( $ctx->identifier( 4, 8 ) ),
		);
	}

	private static function persistent_object_cache_scenarios( array $case ): array {
		$low_alloptions    = array(
			'component_fuzz_small' => '1',
			'component_fuzz_flag'  => 'yes',
		);
		$high_alloptions   = self::persistent_object_cache_alloptions_fixture(
			$case['alloptionsCount'],
			$case['alloptionsPayload']
		);
		$high_serialized   = strlen( serialize( $high_alloptions ) );
		$base_thresholds   = self::persistent_object_cache_thresholds( 1000 );
		$table_thresholds  = self::persistent_object_cache_thresholds( $case['tableLimit'] );
		$table_rows_low    = self::persistent_object_cache_table_rows( $table_thresholds, null );
		$table_rows_trigger = self::persistent_object_cache_table_rows( $table_thresholds, $case['tableThreshold'] );
		$exact_thresholds  = $table_thresholds;
		$exact_thresholds['alloptions_count'] = count( $low_alloptions );
		$exact_thresholds['alloptions_bytes'] = strlen( serialize( $low_alloptions ) );
		$unsafe_note       = $case['safeNoteToken']
			. ' <strong>allowed-note</strong> <script>alert(1)</script><img src="x" onerror="alert(1)">';
		$alloptions_thresholds = $base_thresholds;

		if ( 'alloptions_count' === $case['alloptionsMode'] ) {
			$alloptions_thresholds['alloptions_count'] = count( $high_alloptions ) - 1;
			$alloptions_thresholds['alloptions_bytes'] = $high_serialized + 1000;
		} else {
			$alloptions_thresholds['alloptions_count'] = count( $high_alloptions ) + 1000;
			$alloptions_thresholds['alloptions_bytes'] = max( 0, $high_serialized - 1 );
		}

		return array(
			array(
				'name'                      => 'external-object-cache',
				'extObjectCache'            => true,
				'shortCircuit'              => null,
				'thresholds'                => $base_thresholds,
				'alloptions'                => $high_alloptions,
				'tableRows'                 => $table_rows_trigger,
				'services'                  => array(),
				'actionUrl'                 => $case['actionUrl'],
				'unsafeNote'                => '',
				'safeNoteToken'             => '',
				'expectedStatus'            => 'good',
				'labelContains'             => 'being used',
				'expectedShortCircuitCalls' => 0,
				'expectedAlloptionsCalls'   => 0,
				'expectedDbQueries'         => 0,
				'expectFilteredNotes'       => false,
			),
			array(
				'name'                      => 'short-circuit-false',
				'extObjectCache'            => false,
				'shortCircuit'              => false,
				'thresholds'                => $base_thresholds,
				'alloptions'                => $high_alloptions,
				'tableRows'                 => $table_rows_trigger,
				'services'                  => array(),
				'actionUrl'                 => $case['actionUrl'],
				'unsafeNote'                => '',
				'safeNoteToken'             => '',
				'expectedStatus'            => 'good',
				'labelContains'             => 'not required',
				'expectedShortCircuitCalls' => 1,
				'expectedAlloptionsCalls'   => 0,
				'expectedDbQueries'         => 0,
				'expectFilteredNotes'       => false,
			),
			array(
				'name'                      => 'short-circuit-true-filters',
				'extObjectCache'            => false,
				'shortCircuit'              => true,
				'thresholds'                => $base_thresholds,
				'alloptions'                => $low_alloptions,
				'tableRows'                 => $table_rows_low,
				'services'                  => $case['services'],
				'actionUrl'                 => $case['actionUrl'],
				'unsafeNote'                => $unsafe_note,
				'safeNoteToken'             => $case['safeNoteToken'],
				'expectedStatus'            => 'recommended',
				'labelContains'             => '',
				'expectedShortCircuitCalls' => 1,
				'expectedAlloptionsCalls'   => 0,
				'expectedDbQueries'         => 0,
				'expectFilteredNotes'       => true,
			),
			array(
				'name'                      => 'natural-below-thresholds',
				'extObjectCache'            => false,
				'shortCircuit'              => null,
				'thresholds'                => $exact_thresholds,
				'alloptions'                => $low_alloptions,
				'tableRows'                 => $table_rows_low,
				'services'                  => array(),
				'actionUrl'                 => $case['actionUrl'],
				'unsafeNote'                => '',
				'safeNoteToken'             => '',
				'expectedStatus'            => 'good',
				'labelContains'             => 'not required',
				'expectedShortCircuitCalls' => 1,
				'expectedAlloptionsCalls'   => 1,
				'expectedDbQueries'         => 1,
				'expectFilteredNotes'       => false,
			),
			array(
				'name'                      => 'alloptions-threshold',
				'extObjectCache'            => false,
				'shortCircuit'              => null,
				'thresholds'                => $alloptions_thresholds,
				'alloptions'                => $high_alloptions,
				'tableRows'                 => $table_rows_low,
				'services'                  => array(),
				'actionUrl'                 => $case['actionUrl'],
				'unsafeNote'                => '',
				'safeNoteToken'             => '',
				'expectedStatus'            => 'recommended',
				'labelContains'             => '',
				'expectedShortCircuitCalls' => 1,
				'expectedAlloptionsCalls'   => 1,
				'expectedDbQueries'         => 0,
				'expectFilteredNotes'       => false,
			),
			array(
				'name'                      => 'table-row-threshold',
				'extObjectCache'            => false,
				'shortCircuit'              => null,
				'thresholds'                => $table_thresholds,
				'alloptions'                => $low_alloptions,
				'tableRows'                 => $table_rows_trigger,
				'services'                  => array(),
				'actionUrl'                 => $case['actionUrl'],
				'unsafeNote'                => '',
				'safeNoteToken'             => '',
				'expectedStatus'            => 'recommended',
				'labelContains'             => '',
				'expectedShortCircuitCalls' => 1,
				'expectedAlloptionsCalls'   => 1,
				'expectedDbQueries'         => 1,
				'expectFilteredNotes'       => false,
			),
		);
	}

	private static function persistent_object_cache_thresholds( int $table_limit ): array {
		return array(
			'alloptions_count' => 500,
			'alloptions_bytes' => 100000,
			'comments_count'   => $table_limit,
			'options_count'    => $table_limit,
			'posts_count'      => $table_limit,
			'terms_count'      => $table_limit,
			'users_count'      => $table_limit,
		);
	}

	private static function persistent_object_cache_table_rows( array $thresholds, ?string $trigger ): array {
		$rows = array();

		foreach ( array( 'comments_count', 'options_count', 'posts_count', 'terms_count', 'users_count' ) as $threshold ) {
			$rows[ $threshold ] = max( 0, (int) $thresholds[ $threshold ] - 1 );
		}

		if ( null !== $trigger ) {
			$rows[ $trigger ] = (int) $thresholds[ $trigger ];
		}

		return $rows;
	}

	private static function persistent_object_cache_alloptions_fixture( int $count, int $payload_size ): array {
		$alloptions = array();

		for ( $i = 0; $i < $count; ++$i ) {
			$alloptions[ 'component_fuzz_poc_' . $i ] = str_repeat( chr( 97 + ( $i % 26 ) ), $payload_size + $i );
		}

		return $alloptions;
	}

	private static function site_status_headers_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$headers = array();

		if ( $ctx->bool( 70 ) ) {
			$headers['X-Component-Fuzz'] = 'site-health-' . $ctx->int( 1, 999 );
		}

		if ( $ctx->bool( 45 ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( 'user-' . $ctx->int( 1, 9 ) . ':pwd-' . $ctx->int( 1, 9 ) );
		}

		if ( $ctx->bool( 35 ) ) {
			$headers['Accept'] = $ctx->choice( array( 'application/json', 'application/problem+json' ) );
		}

		return $headers;
	}

	private static function site_status_result_case( \ComponentFuzz\FuzzContext $ctx, string $key, string $label ): array {
		$statuses = array( 'good', 'recommended', 'critical' );
		$colors   = array( 'blue', 'green', 'orange', 'red', 'purple' );

		return array(
			'label'              => $label,
			'status'             => $ctx->choice( $statuses ),
			'filteredStatus'     => $ctx->choice( $statuses ),
			'badgeLabel'         => 'Generated',
			'badgeColor'         => $ctx->choice( $colors ),
			'filteredBadgeColor' => $ctx->choice( $colors ),
			'description'        => '<p>Generated Site Health registry result for ' . $key . '.</p>',
			'actions'            => '',
			'filteredActions'    => $ctx->bool()
				? '<p><a href="https://example.test/site-health/' . rawurlencode( $key ) . '">Generated action</a></p>'
				: '',
		);
	}

	private static function translation_cases( \ComponentFuzz\FuzzContext $ctx, int $count, string $type ): array {
		$translations = array();
		$used         = array();

		for ( $i = 0; $i < $count; ++$i ) {
			$slug           = self::slug( $ctx->fork( 'slug-' . $i ), $type, $used );
			$translations[] = array(
				'type'       => $type,
				'slug'       => $slug,
				'language'   => $ctx->choice( array( 'es_ES', 'fr_FR', 'de_DE', 'en_GB' ) ),
				'version'    => self::version( $ctx->fork( 'version-' . $i ) ),
				'updated'    => '2026-01-' . str_pad( (string) $ctx->int( 1, 28 ), 2, '0', STR_PAD_LEFT ) . ' 00:00:00',
				'package'    => "https://downloads.wordpress.org/translation/{$slug}.zip",
				'autoupdate' => $ctx->bool(),
			);
		}

		return $translations;
	}

	private static function expected_core_updates(
		array $updates,
		array $dismissed,
		bool $available,
		bool $include_dismissed
	): array {
		$expected = array();

		foreach ( $updates as $update ) {
			if ( 'autoupdate' === $update->response ) {
				continue;
			}

			$is_dismissed = array_key_exists( $update->current . '|' . $update->locale, $dismissed );
			if ( $is_dismissed && $include_dismissed ) {
				$expected[] = $update;
			} elseif ( ! $is_dismissed && $available ) {
				$expected[] = $update;
			}
		}

		return $expected;
	}

	private static function expected_update_data( array $case ): array {
		$updates = $case['updates'];
		$counts  = array(
			'plugins'      => $updates['caps']['update_plugins'] ? count( $updates['pluginResponses'] ) : 0,
			'themes'       => $updates['caps']['update_themes'] ? count( $updates['themeResponses'] ) : 0,
			'wordpress'    => 0,
			'translations' => 0,
		);

		if ( $updates['caps']['update_core'] ) {
			$core_updates = self::expected_core_updates( $updates['coreUpdates'], $updates['dismissedCore'], true, false );
			if ( ! empty( $core_updates ) && ! in_array( $core_updates[0]->response, array( 'development', 'latest' ), true ) ) {
				$counts['wordpress'] = 1;
			}
		}

		$has_update_caps = $updates['caps']['update_core']
			|| $updates['caps']['update_plugins']
			|| $updates['caps']['update_themes'];
		if ( $has_update_caps && self::has_translation_updates( $updates ) ) {
			$counts['translations'] = 1;
		}

		$counts['total'] = $counts['plugins'] + $counts['themes'] + $counts['wordpress'] + $counts['translations'];
		$titles          = array();

		if ( $counts['wordpress'] ) {
			$titles[] = sprintf( '%d WordPress Update', $counts['wordpress'] );
		}
		if ( $counts['plugins'] ) {
			$titles[] = sprintf( 1 === $counts['plugins'] ? '%d Plugin Update' : '%d Plugin Updates', $counts['plugins'] );
		}
		if ( $counts['themes'] ) {
			$titles[] = sprintf( 1 === $counts['themes'] ? '%d Theme Update' : '%d Theme Updates', $counts['themes'] );
		}
		if ( $counts['translations'] ) {
			$titles[] = 'Translation Updates';
		}

		return array(
			'counts' => $counts,
			'title'  => implode( ', ', $titles ),
		);
	}

	private static function has_translation_updates( array $updates ): bool {
		return ! empty( $updates['coreTransient']->translations )
			|| ! empty( $updates['pluginTransient']->translations )
			|| ! empty( $updates['themeTransient']->translations );
	}

	private static function apply_https_case( array $case ): void {
		self::$options['home']                     = $case['home'];
		self::$options['siteurl']                  = $case['siteurl'];
		self::$options['https_migration_required'] = $case['migrationRequired'];
		self::$options['stylesheet']               = 'component-fuzz-theme';
		self::$options['template']                 = 'component-fuzz-theme';

		$_SERVER['HTTPS']                  = $case['requestIsSsl'] ? 'on' : 'off';
		$_SERVER['SERVER_PORT']            = $case['requestIsSsl'] ? '443' : '80';
		$_SERVER['HTTP_HOST']              = \wp_parse_url( $case['home'], PHP_URL_HOST ) ?: 'example.test';
		$_SERVER['REQUEST_URI']            = '/component-fuzz/site-health';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = '';
	}

	private static function apply_authorization_case( array $case ): void {
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );

		if ( 'missing' === $case['mode'] ) {
			return;
		}

		$_SERVER['PHP_AUTH_USER'] = $case['user'];
		$_SERVER['PHP_AUTH_PW']   = $case['pass'];
	}

	private static function apply_basic_auth_protection( bool $protected ): void {
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );

		if ( ! $protected ) {
			return;
		}

		$_SERVER['PHP_AUTH_USER'] = 'component-fuzz-user';
		$_SERVER['PHP_AUTH_PW']   = 'component-fuzz-password';
	}

	private static function expected_should_replace_insecure_home_url( array $case ): bool {
		return 'https' === \wp_parse_url( $case['home'], PHP_URL_SCHEME )
			&& 'https' === \wp_parse_url( $case['siteurl'], PHP_URL_SCHEME )
			&& (bool) $case['migrationRequired']
			&& \wp_parse_url( $case['home'], PHP_URL_HOST ) === \wp_parse_url( $case['siteurl'], PHP_URL_HOST );
	}

	private static function expected_replaced_content( string $content ): string {
		$https_url         = \home_url( '', 'https' );
		$http_url          = str_replace( 'https://', 'http://', $https_url );
		$escaped_https_url = str_replace( '/', '\/', $https_url );
		$escaped_http_url  = str_replace( '/', '\/', $http_url );

		return str_replace(
			array(
				$http_url,
				$escaped_http_url,
			),
			array(
				$https_url,
				$escaped_https_url,
			),
			$content
		);
	}

	private static function wp_error_from_case( array $errors ): \WP_Error {
		$wp_error = new \WP_Error();

		foreach ( $errors as $code => $message ) {
			$wp_error->add( $code, $message );
		}

		return $wp_error;
	}

	private static function site_health_without_constructor(): \WP_Site_Health {
		$reflection = new \ReflectionClass( \WP_Site_Health::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	private static function site_health_instance() {
		$reflection = new \ReflectionClass( \WP_Site_Health::class );
		$property   = $reflection->getProperty( 'instance' );

		return $property->getValue();
	}

	private static function set_site_health_instance( $instance ): void {
		$reflection = new \ReflectionClass( \WP_Site_Health::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setValue( null, $instance );
	}

	private static function assert_site_health_result(
		array &$failures,
		$result,
		string $expected_test,
		string $expected_status
	): void {
		$valid_statuses = array( 'good', 'recommended', 'critical' );
		$has_shape      = is_array( $result )
			&& isset(
				$result['label'],
				$result['status'],
				$result['badge'],
				$result['description'],
				$result['actions'],
				$result['test']
			)
			&& is_string( $result['label'] )
			&& '' !== $result['label']
			&& in_array( $result['status'], $valid_statuses, true )
			&& is_array( $result['badge'] )
			&& isset( $result['badge']['label'], $result['badge']['color'] )
			&& is_string( $result['badge']['label'] )
			&& is_string( $result['badge']['color'] )
			&& is_string( $result['description'] )
			&& '' !== $result['description']
			&& is_string( $result['actions'] )
			&& $expected_test === $result['test'];

		self::collect_failure(
			$failures,
			$has_shape && $expected_status === ( $result['status'] ?? null ),
			"Site Health {$expected_test} result shape/status",
			array(
				'expectedStatus' => $expected_status,
				'actual'         => self::describe_value( $result ),
			)
		);
	}

	private static function generated_site_status_tests( array $registry ): array {
		$tests = array(
			'direct' => array(),
			'async'  => array(),
		);

		foreach ( $registry['direct'] as $key => $entry ) {
			$test_key                 = (string) $key;
			$tests['direct'][ $key ] = array(
				'label'     => $entry['label'],
				'test'      => static function () use ( $test_key ): array {
					return self::generated_site_status_test( $test_key );
				},
				'skip_cron' => $entry['skipCron'],
			);
		}

		foreach ( $registry['async'] as $key => $entry ) {
			$test_key                = (string) $key;
			$tests['async'][ $key ] = array(
				'label'     => $entry['label'],
				'test'      => $entry['test'],
				'has_rest'  => $entry['hasRest'],
				'headers'   => $entry['headers'],
				'skip_cron' => $entry['skipCron'],
			);

			if ( $entry['hasAsyncDirectTest'] ) {
				$tests['async'][ $key ]['async_direct_test'] = static function () use ( $test_key ): array {
					return self::generated_site_status_test( $test_key );
				};
			}
		}

		return $tests;
	}

	private static function generated_site_status_test( string $test ): array {
		if ( ! isset( self::$generated_test_calls[ $test ] ) ) {
			self::$generated_test_calls[ $test ] = 0;
		}

		++self::$generated_test_calls[ $test ];

		$case = self::$site_status_tests_case['results'][ $test ] ?? array(
			'label'       => 'Missing generated Site Health result',
			'status'      => 'critical',
			'badgeLabel'  => 'Generated',
			'badgeColor'  => 'red',
			'description' => '<p>Missing generated Site Health result.</p>',
			'actions'     => '',
		);

		return array(
			'label'       => $case['label'],
			'status'      => $case['status'],
			'badge'       => array(
				'label' => $case['badgeLabel'],
				'color' => $case['badgeColor'],
			),
			'description' => $case['description'],
			'actions'     => $case['actions'],
			'test'        => $test,
		);
	}

	private static function expected_site_status_registry_keys( array $registry, ?array $seen ): array {
		$generated_direct = array_keys( $registry['direct'] );
		$generated_async  = array_keys( $registry['async'] );
		$default_direct   = is_array( $seen ) ? $seen['directKeys'] : array();
		$default_async    = is_array( $seen ) ? $seen['asyncKeys'] : array();

		if ( 'append' === $registry['mode'] ) {
			return array(
				'direct' => array_merge( $default_direct, $generated_direct ),
				'async'  => array_merge( $default_async, $generated_async ),
			);
		}

		if ( 'direct-only' === $registry['mode'] ) {
			return array(
				'direct' => $generated_direct,
				'async'  => array(),
			);
		}

		if ( 'async-only' === $registry['mode'] ) {
			return array(
				'direct' => array(),
				'async'  => $generated_async,
			);
		}

		return array(
			'direct' => $generated_direct,
			'async'  => $generated_async,
		);
	}

	private static function expected_performed_site_status_tests( array $registry ): array {
		$performed = array();

		if ( in_array( $registry['mode'], array( 'append', 'replace', 'direct-only' ), true ) ) {
			$performed = array_merge( $performed, array_keys( $registry['direct'] ) );
		}

		if ( in_array( $registry['mode'], array( 'append', 'replace', 'async-only' ), true ) ) {
			foreach ( $registry['async'] as $key => $entry ) {
				if ( $entry['hasAsyncDirectTest'] ) {
					$performed[] = $key;
				}
			}
		}

		return $performed;
	}

	private static function expected_generated_test_call_counts( array $registry ): array {
		$counts = array();

		foreach ( self::expected_performed_site_status_tests( $registry ) as $test ) {
			$counts[ $test ] = 1;
		}

		return $counts;
	}

	private static function assert_generated_direct_tests(
		array &$failures,
		\WP_Site_Health $site_health,
		array $tests,
		array $registry
	): void {
		if ( ! in_array( $registry['mode'], array( 'append', 'replace', 'direct-only' ), true ) ) {
			return;
		}

		foreach ( $registry['direct'] as $key => $expected ) {
			$entry = $tests[ $key ] ?? null;

			self::collect_failure(
				$failures,
				is_array( $entry )
					&& ( $entry['label'] ?? null ) === $expected['label']
					&& ( $entry['skip_cron'] ?? null ) === $expected['skipCron']
					&& isset( $entry['test'] )
					&& is_callable( $entry['test'] ),
				"generated direct Site Health test {$key} is registered as callable metadata",
				array(
					'expected' => array(
						'label'    => $expected['label'],
						'skipCron' => $expected['skipCron'],
					),
					'actual'   => self::summarize_site_status_test_entry( $entry ),
				)
			);

			if ( is_array( $entry ) && isset( $entry['test'] ) && is_callable( $entry['test'] ) ) {
				$result = self::perform_site_health_test( $site_health, $entry['test'] );
				self::assert_generated_site_status_result( $failures, $result, $key, $registry );
			}
		}
	}

	private static function assert_generated_async_tests(
		array &$failures,
		\WP_Site_Health $site_health,
		array $tests,
		array $registry
	): void {
		if ( ! in_array( $registry['mode'], array( 'append', 'replace', 'async-only' ), true ) ) {
			return;
		}

		foreach ( $registry['async'] as $key => $expected ) {
			$entry = $tests[ $key ] ?? null;

			self::collect_failure(
				$failures,
				is_array( $entry )
					&& ( $entry['label'] ?? null ) === $expected['label']
					&& ( $entry['test'] ?? null ) === $expected['test']
					&& ( $entry['has_rest'] ?? null ) === $expected['hasRest']
					&& ( $entry['headers'] ?? array() ) === $expected['headers']
					&& ( $entry['skip_cron'] ?? null ) === $expected['skipCron'],
				"generated async Site Health test {$key} preserves REST/header/cron metadata",
				array(
					'expected' => array(
						'label'    => $expected['label'],
						'test'     => $expected['test'],
						'hasRest'  => $expected['hasRest'],
						'headers'  => $expected['headers'],
						'skipCron' => $expected['skipCron'],
					),
					'actual'   => self::summarize_site_status_test_entry( $entry ),
				)
			);

			if ( $expected['hasAsyncDirectTest'] ) {
				self::collect_failure(
					$failures,
					is_array( $entry )
						&& isset( $entry['async_direct_test'] )
						&& is_callable( $entry['async_direct_test'] ),
					"generated async Site Health test {$key} exposes callable async_direct_test",
					array( 'actual' => self::summarize_site_status_test_entry( $entry ) )
				);

				if ( is_array( $entry ) && isset( $entry['async_direct_test'] ) && is_callable( $entry['async_direct_test'] ) ) {
					$result = self::perform_site_health_test( $site_health, $entry['async_direct_test'] );
					self::assert_generated_site_status_result( $failures, $result, $key, $registry );
				}
			} else {
				self::collect_failure(
					$failures,
					is_array( $entry ) && empty( $entry['async_direct_test'] ),
					"generated async Site Health test {$key} without direct callback remains metadata-only",
					array( 'actual' => self::summarize_site_status_test_entry( $entry ) )
				);
			}
		}
	}

	private static function assert_generated_site_status_result(
		array &$failures,
		$result,
		string $key,
		array $registry
	): void {
		$expected = $registry['results'][ $key ];

		self::assert_site_health_result( $failures, $result, $key, $expected['filteredStatus'] );

		self::collect_failure(
			$failures,
			is_array( $result )
				&& ( $result['label'] ?? null ) === $expected['label']
				&& ( $result['badge']['color'] ?? null ) === $expected['filteredBadgeColor']
				&& ( $result['actions'] ?? null ) === $expected['filteredActions'],
			"generated Site Health test {$key} result filter mutations are applied",
			array(
				'expected' => array(
					'label'      => $expected['label'],
					'status'     => $expected['filteredStatus'],
					'badgeColor' => $expected['filteredBadgeColor'],
					'actions'    => self::preview( $expected['filteredActions'] ),
				),
				'actual'   => self::summarize_site_health_result( $result ),
			)
		);
	}

	private static function perform_site_health_test( \WP_Site_Health $site_health, $callback ) {
		$method = new \ReflectionMethod( \WP_Site_Health::class, 'perform_test' );

		return $method->invoke( $site_health, $callback );
	}

	private static function summarize_site_status_test_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return self::describe_value( $entry );
		}

		return array(
			'label'          => $entry['label'] ?? null,
			'test'           => isset( $entry['test'] ) && is_callable( $entry['test'] ) ? '[callable]' : ( $entry['test'] ?? null ),
			'has_rest'       => $entry['has_rest'] ?? null,
			'headers'        => $entry['headers'] ?? null,
			'skip_cron'      => $entry['skip_cron'] ?? null,
			'async_direct'   => isset( $entry['async_direct_test'] ) && is_callable( $entry['async_direct_test'] ),
		);
	}

	private static function summarize_site_health_result( $result ) {
		if ( ! is_array( $result ) ) {
			return self::describe_value( $result );
		}

		return array(
			'label'      => $result['label'] ?? null,
			'status'     => $result['status'] ?? null,
			'badgeColor' => $result['badge']['color'] ?? null,
			'actions'    => self::preview( $result['actions'] ?? '' ),
			'test'       => $result['test'] ?? null,
		);
	}

	private static function expected_file_upload_status(): string {
		if ( ! function_exists( 'ini_get' ) ) {
			return 'critical';
		}

		if ( empty( ini_get( 'file_uploads' ) ) ) {
			return 'critical';
		}

		$post_max_size       = \wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$upload_max_filesize = \wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );

		if ( $post_max_size < $upload_max_filesize ) {
			return 'recommended';
		}

		return 'good';
	}

	private static function core_update_keys( array $updates ): array {
		return array_map(
			static function ( $update ): string {
				return $update->current . '|' . $update->locale . '|' . $update->response;
			},
			$updates
		);
	}

	private static function core_dismissed_flags_are_expected( array $updates, bool $expected ): bool {
		foreach ( $updates as $update ) {
			if ( ! isset( $update->dismissed ) || $expected !== $update->dismissed ) {
				return false;
			}
		}

		return true;
	}

	private static function core_dismissed_flags( array $updates ): array {
		$flags = array();

		foreach ( $updates as $update ) {
			$flags[ $update->current . '|' . $update->locale ] = $update->dismissed ?? null;
		}

		return $flags;
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

	private static function version( \ComponentFuzz\FuzzContext $ctx, int $major = 1 ): string {
		return $major . '.' . $ctx->int( 0, 9 ) . '.' . $ctx->int( 0, 20 );
	}

	private static function collect_failure( array &$failures, bool $ok, string $label, array $data ): void {
		if ( $ok ) {
			return;
		}

		$data['label'] = $label;
		$failures[]    = $data;
	}

	private static function snapshot_state(): array {
		$snapshot = array(
			'timezone'          => date_default_timezone_get(),
			'outputBufferLevel' => ob_get_level(),
			'siteHealthInstance' => self::site_health_instance(),
			'globals'           => array(),
		);

		$globals = array(
			'wp_filter',
			'wp_filters',
			'wp_actions',
			'wp_current_filter',
			'wp_object_cache',
			'wpdb',
			'_wp_using_ext_object_cache',
			'current_user',
			'_SERVER',
			'_ENV',
			'_GET',
		);

		foreach ( $globals as $name ) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_state( array $snapshot ): void {
		date_default_timezone_set( $snapshot['timezone'] );
		self::set_site_health_instance( $snapshot['siteHealthInstance'] );

		while ( ob_get_level() > $snapshot['outputBufferLevel'] ) {
			ob_end_clean();
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
		if ( date_default_timezone_get() !== $snapshot['timezone'] ) {
			return false;
		}

		if ( ob_get_level() !== $snapshot['outputBufferLevel'] ) {
			return false;
		}

		if ( self::site_health_instance() !== $snapshot['siteHealthInstance'] ) {
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

	private static function snapshot_runtime_global( string $name ): array {
		return array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => $GLOBALS[ $name ] ?? null,
		);
	}

	private static function restore_runtime_global( string $name, array $snapshot ): void {
		if ( $snapshot['exists'] ) {
			$GLOBALS[ $name ] = $snapshot['value'];
		} else {
			unset( $GLOBALS[ $name ] );
		}
	}

	private static function runtime_global_matches( string $name, array $snapshot ): bool {
		$exists = array_key_exists( $name, $GLOBALS );

		return $exists === $snapshot['exists']
			&& ( ! $exists || $GLOBALS[ $name ] === $snapshot['value'] );
	}

	private static function snapshot_cache_slot( string $key, string $group ): array {
		$found = false;
		$value = \wp_cache_get( $key, $group, false, $found );

		return array(
			'found' => $found,
			'value' => $value,
		);
	}

	private static function restore_cache_slot( string $key, string $group, array $snapshot ): void {
		if ( $snapshot['found'] ) {
			\wp_cache_set( $key, $snapshot['value'], $group );
		} else {
			\wp_cache_delete( $key, $group );
		}
	}

	private static function cache_slot_matches( string $key, string $group, array $snapshot ): bool {
		$found = false;
		$value = \wp_cache_get( $key, $group, false, $found );

		return $found === $snapshot['found']
			&& ( ! $found || $value === $snapshot['value'] );
	}

	private static function html_contains_url( string $html, string $url ): bool {
		return false !== strpos( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ), $url );
	}

	private static function all_strings_contained( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false === strpos( $haystack, (string) $needle ) ) {
				return false;
			}
		}

		return true;
	}

	private static function reset_static_state(): void {
		self::$site_transients       = array();
		self::$site_options          = array();
		self::$options               = array();
		self::$granted_caps          = array();
		self::$site_status_tests_case = null;
		self::$site_status_filter_seen = array();
		self::$site_status_result_filter_seen = array();
		self::$generated_test_calls = array();
		self::$https_detection_error = null;
		self::$replace_override      = null;
		self::$http_request_count    = 0;
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

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function describe_value( $value ) {
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		if ( is_array( $value ) ) {
			return self::preview( wp_json_encode( $value ) );
		}

		return $value;
	}

	private static function preview( ?string $value, int $limit = 220 ): string {
		$value = (string) $value;

		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, $limit ) . '...';
	}
}

final class SiteHealthPersistentObjectCacheWpdbDouble {
	public int $num_rows = 0;
	public string $last_query = '';
	public $last_output = null;
	public int $prepare_calls = 0;
	public int $get_results_calls = 0;
	public array $prepared_queries = array();
	public array $result_queries = array();
	public array $last_requested_tables = array();
	public string $comments = 'wp_comments';
	public string $options = 'wp_options';
	public string $posts = 'wp_posts';
	public string $terms = 'wp_terms';
	public string $users = 'wp_users';

	private $delegate;
	private array $rows_by_table = array();

	public function __construct( $delegate, array $rows_by_threshold ) {
		$this->delegate = is_object( $delegate ) ? $delegate : null;

		foreach ( array( 'comments', 'options', 'posts', 'terms', 'users' ) as $property ) {
			if ( is_object( $delegate ) && isset( $delegate->{$property} ) ) {
				$this->{$property} = (string) $delegate->{$property};
			}
		}

		$map = array(
			'comments_count' => $this->comments,
			'options_count'  => $this->options,
			'posts_count'    => $this->posts,
			'terms_count'    => $this->terms,
			'users_count'    => $this->users,
		);

		foreach ( $map as $threshold => $table ) {
			$this->rows_by_table[ $table ] = (int) ( $rows_by_threshold[ $threshold ] ?? 0 );
		}
	}

	public function expected_table_names(): array {
		return array(
			$this->comments,
			$this->options,
			$this->posts,
			$this->terms,
			$this->users,
		);
	}

	public function prepare( $query, ...$args ): string {
		++$this->prepare_calls;
		$query                    = (string) $query;
		$this->prepared_queries[] = array(
			'query' => $query,
			'args'  => $args,
		);

		if ( isset( $args[0] ) ) {
			return str_replace( '%s', "'" . addslashes( (string) $args[0] ) . "'", $query );
		}

		return $query;
	}

	public function get_results( $query = null, $output = OBJECT ): array {
		++$this->get_results_calls;
		$query                = (string) $query;
		$this->last_query     = $query;
		$this->last_output    = $output;
		$this->result_queries[] = array(
			'query'  => $query,
			'output' => $output,
		);

		if ( false === strpos( $query, 'information_schema.TABLES' ) ) {
			if ( is_object( $this->delegate ) && method_exists( $this->delegate, 'get_results' ) ) {
				return $this->delegate->get_results( $query, $output );
			}

			return array();
		}

		$this->last_requested_tables = $this->requested_table_names( $query );
		$rows = array();
		foreach ( $this->last_requested_tables as $table ) {
			if ( ! array_key_exists( $table, $this->rows_by_table ) ) {
				continue;
			}

			$table_rows = $this->rows_by_table[ $table ];
			$row = (object) array(
				'table' => $table,
				'rows'  => $table_rows,
				'bytes' => $table_rows * 128,
			);

			if ( OBJECT_K === $output ) {
				$rows[ $table ] = $row;
			} else {
				$rows[] = $row;
			}
		}

		$this->num_rows = count( $rows );
		return $rows;
	}

	private function requested_table_names( string $query ): array {
		if ( ! preg_match( "/TABLE_NAME\s+IN\s*\(([^)]*)\)/i", $query, $match ) ) {
			return array();
		}

		if ( ! preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $matches ) ) {
			return array();
		}

		return array_map( 'stripslashes', $matches[1] );
	}
}
