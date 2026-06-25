<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-request-dispatch admin user preference helpers.
 */
final class UserPreferencesSurface {
	public const NAME = 'user-preferences';

	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'user-preferences.bootstrap-apis-available',
					'Required user preference APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_user_setting_serialization( $ctx->fork( 'settings' ) );
			$rows[] = self::check_admin_color_serialization( $ctx->fork( 'admin-color' ) );
			$rows[] = self::check_user_option_preference_storage( $ctx->fork( 'user-options' ) );
			$rows[] = self::check_hidden_column_preferences( $ctx->fork( 'hidden-columns' ) );
			$rows[] = self::check_hidden_meta_box_preferences( $ctx->fork( 'hidden-metaboxes' ) );
			$rows[] = self::check_meta_box_order_preferences( $ctx->fork( 'metabox-order' ) );
			$rows[] = self::check_screen_option_registration( $ctx->fork( 'screen-options' ) );
			$rows[] = self::check_screen_layout_rendering( $ctx->fork( 'screen-layout' ) );
			$rows[] = $ctx->skip(
				'user-preferences.exiting-request-handlers',
				'set_screen_options() redirects and AJAX preference handlers call wp_die(); lower-level helpers are covered directly.'
			);
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'user-preferences.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'user-preferences.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'contentCounts'  => self::content_counts(),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Screen', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_screen_option',
				'convert_to_screen',
				'delete_all_user_settings',
				'delete_user_option',
				'delete_user_setting',
				'get_all_user_settings',
				'get_current_screen',
				'get_hidden_columns',
				'get_hidden_meta_boxes',
				'get_user_meta',
				'get_user_option',
				'get_user_setting',
				'has_filter',
				'is_wp_error',
				'postbox_classes',
				'remove_filter',
				'set_current_screen',
				'set_user_setting',
				'update_user_option',
				'wp_cache_flush',
				'wp_insert_user',
				'wp_set_all_user_settings',
				'wp_set_current_user',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_user_setting_serialization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx );
		\wp_set_current_user( $user_id );

		$cookie_name = 'wp-settings-' . $user_id;
		$cookie      = 'alpha=one&dash-key=value-with-dash&bad<script>=two&semi=x;y&empty=';
		$_COOKIE[ $cookie_name ] = $cookie;
		unset( $GLOBALS['_updated_user_settings'] );

		$from_cookie = \get_all_user_settings();
		$cookie_safe = self::settings_are_ascii_pairs( $from_cookie );
		unset( $_COOKIE[ $cookie_name ], $GLOBALS['_updated_user_settings'] );

		$stored_option = 'stored=fromdb&stored-dash=ok&empty=';
		\update_user_option( $user_id, 'user-settings', $stored_option, false );
		\wp_set_current_user( $user_id );
		$from_option = \get_all_user_settings();
		unset( $GLOBALS['_updated_user_settings'] );

		$cases          = self::user_setting_cases( $ctx->fork( 'setting-cases' ) );
		$batch_settings = array();
		$expected_batch = array();
		foreach ( $cases as $case ) {
			$batch_settings[ $case['rawName'] ] = $case['rawValue'];
			if ( '' !== $case['expectedName'] ) {
				$expected_batch[ $case['expectedName'] ] = $case['expectedValue'];
			}
		}

		$set_all_result   = \wp_set_all_user_settings( $batch_settings );
		$expected_string  = self::settings_query_string( $expected_batch );
		$saved_after_all  = \get_user_option( 'user-settings', $user_id );
		$cached_after_all = $GLOBALS['_updated_user_settings'] ?? null;
		$read_after_all   = \get_all_user_settings();

		$raw_name       = 'mode<script>' . $ctx->identifier( 3, 8 );
		$raw_value      = 'grid<script>&x=' . $ctx->identifier( 3, 8 );
		$expected_name  = self::sanitize_user_setting_token( $raw_name );
		$expected_value = self::sanitize_user_setting_token( $raw_value );
		$default_value  = 'fallback-' . self::slug( $ctx, 'missing' );

		$set_result       = \set_user_setting( $raw_name, $raw_value );
		$saved_after_set  = \get_user_option( 'user-settings', $user_id );
		$read_back        = \get_user_setting( $expected_name, 'missing' );
		$missing_readback = \get_user_setting( 'not-present-' . $ctx->identifier( 3, 8 ), $default_value );

		$batch_delete_name = array_key_first( $expected_batch );
		$delete_result     = \delete_user_setting(
			array(
				$expected_name,
				$batch_delete_name,
				'not-present-' . $ctx->identifier( 3, 8 ),
			)
		);
		$after_delete       = \get_user_setting( $expected_name, 'deleted' );
		$after_batch_delete = \get_user_setting( $batch_delete_name, 'deleted' );
		$missing_delete     = \delete_user_setting( 'not-present-' . $ctx->identifier( 3, 8 ) );

		\delete_all_user_settings();
		$after_delete_all = \get_user_option( 'user-settings', $user_id );
		unset( $GLOBALS['_updated_user_settings'] );
		$all_after_delete_all = \get_all_user_settings();

		\wp_set_current_user( 0 );
		unset( $GLOBALS['_updated_user_settings'] );
		$logged_out_set = \set_user_setting( 'logged-out', 'value' );

		self::collect_failure(
			$failures,
			isset( $from_cookie['alpha'], $from_cookie['dash-key'] )
				&& 'one' === $from_cookie['alpha']
				&& 'value-with-dash' === $from_cookie['dash-key']
				&& 'two' === ( $from_cookie['badscript'] ?? null )
				&& 'xy' === ( $from_cookie['semi'] ?? null )
				&& '' === ( $from_cookie['empty'] ?? null )
				&& $cookie_safe,
			'get_all_user_settings() parses only sanitized cookie key/value pairs',
			array(
				'cookie'     => $cookie,
				'fromCookie' => $from_cookie,
			)
		);
		self::collect_failure(
			$failures,
			array(
				'stored'      => 'fromdb',
				'stored-dash' => 'ok',
				'empty'       => '',
			) === $from_option,
			'get_all_user_settings() falls back to the persisted user-settings option when no cookie is present',
			array(
				'storedOption' => $stored_option,
				'fromOption'   => $from_option,
			)
		);
		self::collect_failure(
			$failures,
			true === $set_all_result
				&& $expected_string === $saved_after_all
				&& $expected_batch === $cached_after_all
				&& $expected_batch === $read_after_all
				&& ! str_contains( $saved_after_all, '<script' )
				&& ! str_contains( $saved_after_all, '&x=' )
				&& ! str_contains( $saved_after_all, 'ignored' ),
			'wp_set_all_user_settings() serializes generated settings after stripping disallowed bytes and omitting empty names',
			array(
				'cases'          => $cases,
				'expectedString' => $expected_string,
				'savedString'    => $saved_after_all,
				'cached'         => $cached_after_all,
				'readBack'       => $read_after_all,
			)
		);
		self::collect_failure(
			$failures,
			true === $set_result
				&& $expected_value === $read_back
				&& $default_value === $missing_readback
				&& is_string( $saved_after_set )
				&& str_contains( $saved_after_set, $expected_name . '=' . $expected_value )
				&& ! str_contains( $saved_after_set, '<script' )
				&& ! str_contains( $saved_after_set, '&x=' ),
			'set_user_setting() strips disallowed bytes and get_user_setting() returns explicit defaults for missing names',
			array(
				'rawName'       => $raw_name,
				'rawValue'      => $raw_value,
				'expectedName'  => $expected_name,
				'expectedValue' => $expected_value,
				'savedString'   => $saved_after_set,
				'readBack'      => $read_back,
				'missing'       => $missing_readback,
			)
		);
		self::collect_failure(
			$failures,
			true === $delete_result
				&& 'deleted' === $after_delete
				&& 'deleted' === $after_batch_delete
				&& false === $missing_delete
				&& '' === $after_delete_all
				&& array() === $all_after_delete_all
				&& false === $logged_out_set,
			'user setting delete helpers distinguish existing arrays, missing names, all settings, and logged-out cases',
			array(
				'deleteResult'      => $delete_result,
				'afterDelete'       => $after_delete,
				'afterBatchDelete'  => $after_batch_delete,
				'missingDelete'     => $missing_delete,
				'afterDeleteAll'    => $after_delete_all,
				'allAfterDeleteAll' => $all_after_delete_all,
				'loggedOutSet'      => $logged_out_set,
			)
		);

		return self::result(
			$ctx,
			'user-preferences.user-settings.sanitize-persist-delete',
			$failures,
			array( 'userId' => $user_id )
		);
	}

	private static function check_admin_color_serialization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$raw_color     = 'modern<script> ' . self::slug( $ctx, 'scheme' ) . '.night@beta!';
		$expected      = preg_replace( '|[^a-z0-9 _.\-@]|i', '', $raw_color );
		$user_id       = self::seed_user(
			$ctx,
			array(
				'admin_color' => $raw_color,
			)
		);
		$stored_color  = \get_user_option( 'admin_color', $user_id );
		$default_id    = self::seed_user(
			$ctx->fork( 'default-admin-color' ),
			array(
				'admin_color' => '',
			)
		);
		$default_color = \get_user_option( 'admin_color', $default_id );

		self::collect_failure(
			$failures,
			$expected === $stored_color
				&& ! str_contains( (string) $stored_color, '<' )
				&& ! str_contains( (string) $stored_color, '>' )
				&& ! str_contains( (string) $stored_color, '!' )
				&& 'modern' === $default_color,
			'wp_insert_user() stores admin_color using the same serialization constraints as profile preferences',
			array(
				'rawColor'     => $raw_color,
				'expected'     => $expected,
				'storedColor'  => $stored_color,
				'defaultColor' => $default_color,
			)
		);

		return self::result(
			$ctx,
			'user-preferences.admin-color.sanitize-and-default',
			$failures,
			array( 'userId' => $user_id )
		);
	}

	private static function check_user_option_preference_storage( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb;

		$failures          = array();
		$user_id           = self::seed_user( $ctx );
		$option_name       = 'cfz_pref_' . self::slug( $ctx, 'option' );
		$prefixed_name     = $wpdb->get_blog_prefix() . $option_name;
		$blog_value        = array(
			'mode'    => 'list-' . self::slug( $ctx, 'blog-mode' ),
			'perPage' => $ctx->int( 1, 200 ),
			'flags'   => array(
				'compact' => 1 === $ctx->int( 0, 1 ),
				'pinned'  => 'box_' . self::slug( $ctx, 'blog-box' ),
			),
		);
		$global_value      = array(
			'mode'    => 'grid-' . self::slug( $ctx, 'global-mode' ),
			'perPage' => $ctx->int( 201, 400 ),
			'flags'   => array(
				'compact' => 1 === $ctx->int( 0, 1 ),
				'pinned'  => 'box_' . self::slug( $ctx, 'global-box' ),
			),
		);
		$filter_events     = array();
		$filter_hook       = 'get_user_option_' . $option_name;
		$user_option_probe = static function ( $result, string $option, \WP_User $user ) use ( &$filter_events ) {
			$filter_events[] = array(
				'result' => $result,
				'option' => $option,
				'userId' => (int) $user->ID,
			);

			return $result;
		};

		\add_filter( $filter_hook, $user_option_probe, 10, 3 );
		try {
			\wp_set_current_user( $user_id );

			$missing_before_count = count( $filter_events );
			$missing_current      = \get_user_option( $option_name );
			$missing_before_event = array_slice( $filter_events, $missing_before_count );

			$invalid_before_count = count( $filter_events );
			$invalid_user_option  = \get_user_option( $option_name, $user_id + 100000 );
			$invalid_event_count  = count( $filter_events ) - $invalid_before_count;

			$empty_name_update = \update_user_option( $user_id, '', 'ignored', true );
			$blog_add_result   = \update_user_option( $user_id, $option_name, $blog_value, false );
			$blog_same_result  = \update_user_option( $user_id, $option_name, $blog_value, false );
			$global_add_result = \update_user_option( $user_id, $option_name, $global_value, true );
			\wp_set_current_user( $user_id );

			$raw_blog_value   = \get_user_meta( $user_id, $prefixed_name, true );
			$raw_global_value = \get_user_meta( $user_id, $option_name, true );

			$current_user_value         = \get_user_option( $option_name );
			$explicit_value             = \get_user_option( $option_name, $user_id );
			$blog_delete_result         = \delete_user_option( $user_id, $option_name, false );
			$missing_blog_delete_result = \delete_user_option( $user_id, $option_name, false );
			\wp_set_current_user( $user_id );
			$fallback_value       = \get_user_option( $option_name, $user_id );
			$global_delete_result = \delete_user_option( $user_id, $option_name, true );
			\wp_set_current_user( $user_id );
			$after_delete_value = \get_user_option( $option_name, $user_id );

			\wp_set_current_user( 0 );
			$logged_out_before_count = count( $filter_events );
			$logged_out_value        = \get_user_option( $option_name );
			$logged_out_event_count  = count( $filter_events ) - $logged_out_before_count;
		} finally {
			\remove_filter( $filter_hook, $user_option_probe, 10 );
		}

		self::collect_failure(
			$failures,
			false === $missing_current
				&& 1 === count( $missing_before_event )
				&& false === ( $missing_before_event[0]['result'] ?? null )
				&& 0 === $invalid_event_count
				&& false === $invalid_user_option
				&& false === $logged_out_value
				&& 0 === $logged_out_event_count,
			'get_user_option() returns false for missing, invalid-user, and logged-out lookups with the expected filter boundary',
			array(
				'missing'             => $missing_current,
				'missingEvents'       => $missing_before_event,
				'invalidUserOption'   => $invalid_user_option,
				'invalidEventCount'   => $invalid_event_count,
				'loggedOutValue'      => $logged_out_value,
				'loggedOutEventCount' => $logged_out_event_count,
			)
		);
		self::collect_failure(
			$failures,
			false === $empty_name_update
				&& is_int( $blog_add_result )
				&& false === $blog_same_result
				&& is_int( $global_add_result )
				&& $blog_value === $raw_blog_value
				&& $global_value === $raw_global_value,
			'update_user_option() prefixes blog-local preference keys, stores global keys unprefixed, rejects empty global names, and reports unchanged values',
			array(
				'option'          => $option_name,
				'prefixed'        => $prefixed_name,
				'emptyNameUpdate' => $empty_name_update,
				'blogAddResult'   => $blog_add_result,
				'blogSameResult'  => $blog_same_result,
				'globalAddResult' => $global_add_result,
				'rawBlogValue'    => $raw_blog_value,
				'rawGlobalValue'  => $raw_global_value,
			)
		);
		self::collect_failure(
			$failures,
			$blog_value === $current_user_value
				&& $blog_value === $explicit_value
				&& true === $blog_delete_result
				&& false === $missing_blog_delete_result
				&& $global_value === $fallback_value
				&& true === $global_delete_result
				&& false === $after_delete_value,
			'get_user_option() prefers blog-local preferences, falls back to global preferences after deletion, and delete_user_option() distinguishes missing keys',
			array(
				'currentUserValue'         => $current_user_value,
				'explicitValue'            => $explicit_value,
				'blogDeleteResult'         => $blog_delete_result,
				'missingBlogDeleteResult'  => $missing_blog_delete_result,
				'fallbackValue'            => $fallback_value,
				'globalDeleteResult'       => $global_delete_result,
				'afterDeleteValue'         => $after_delete_value,
			)
		);
		self::collect_failure(
			$failures,
			array(
				array(
					'result' => false,
					'option' => $option_name,
					'userId' => $user_id,
				),
				array(
					'result' => $blog_value,
					'option' => $option_name,
					'userId' => $user_id,
				),
				array(
					'result' => $blog_value,
					'option' => $option_name,
					'userId' => $user_id,
				),
				array(
					'result' => $global_value,
					'option' => $option_name,
					'userId' => $user_id,
				),
				array(
					'result' => false,
					'option' => $option_name,
					'userId' => $user_id,
				),
			) === $filter_events
				&& false === \has_filter( $filter_hook, $user_option_probe ),
			'get_user_option() dynamic filters observe resolved missing/blog/global values and are removed after the check',
			array(
				'filterHook'   => $filter_hook,
				'filterEvents' => $filter_events,
				'hasFilter'    => \has_filter( $filter_hook, $user_option_probe ),
			)
		);

		return self::result(
			$ctx,
			'user-preferences.user-options.prefix-precedence-delete-and-filters',
			$failures,
			array(
				'userId'      => $user_id,
				'option'      => $option_name,
				'prefixedKey' => $prefixed_name,
			)
		);
	}

	private static function check_hidden_column_preferences( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx );
		\wp_set_current_user( $user_id );

		$screen                = \convert_to_screen( 'edit-post' );
		$other_screen          = \convert_to_screen( 'edit-page' );
		$default_marker        = 'cfz_default_' . self::slug( $ctx, 'column' );
		$hidden_default_marker = 'cfz_hidden_default_' . self::slug( $ctx, 'hidden-default' );
		$hidden_saved_marker   = 'cfz_hidden_saved_' . self::slug( $ctx, 'hidden-saved' );
		$events                = array(
			'default' => array(),
			'hidden'  => array(),
		);

		$default_filter = static function ( array $hidden, \WP_Screen $seen_screen ) use ( &$events, $default_marker ): array {
			$events['default'][] = $seen_screen->id;
			$hidden[]            = $default_marker;
			return $hidden;
		};
		$hidden_filter  = static function ( array $hidden, \WP_Screen $seen_screen, bool $use_defaults ) use ( &$events, $hidden_default_marker, $hidden_saved_marker ): array {
			$events['hidden'][] = array(
				'id'          => $seen_screen->id,
				'useDefaults' => $use_defaults,
				'hidden'      => $hidden,
			);
			$hidden[] = $use_defaults ? $hidden_default_marker : $hidden_saved_marker;
			return $hidden;
		};

		\add_filter( 'default_hidden_columns', $default_filter, 10, 2 );
		\add_filter( 'hidden_columns', $hidden_filter, 10, 3 );
		try {
			$default_hidden = \get_hidden_columns( $screen );
			$string_hidden  = \get_hidden_columns( $screen->id );

			\update_user_option( $user_id, 'manage' . $screen->id . 'columnshidden', array(), false );
			\wp_set_current_user( $user_id );
			$empty_saved_hidden = \get_hidden_columns( $screen );

			$saved = array( 'author', 'comments', 'cfz_' . self::slug( $ctx, 'saved' ) );
			\update_user_option( $user_id, 'manage' . $screen->id . 'columnshidden', $saved, false );
			\wp_set_current_user( $user_id );
			$saved_hidden = \get_hidden_columns( $screen );

			$other_saved = array( 'date', 'cfz_' . self::slug( $ctx, 'other-saved' ) );
			\update_user_option( $user_id, 'manage' . $other_screen->id . 'columnshidden', $other_saved, false );
			\wp_set_current_user( $user_id );
			$other_hidden = \get_hidden_columns( $other_screen );
		} finally {
			\remove_filter( 'hidden_columns', $hidden_filter, 10 );
			\remove_filter( 'default_hidden_columns', $default_filter, 10 );
		}

		self::collect_failure(
			$failures,
			2 === count( $events['default'] )
				&& 5 === count( $events['hidden'] )
				&& true === $events['hidden'][0]['useDefaults']
				&& true === $events['hidden'][1]['useDefaults']
				&& false === $events['hidden'][2]['useDefaults']
				&& false === $events['hidden'][3]['useDefaults']
				&& false === $events['hidden'][4]['useDefaults']
				&& in_array( $default_marker, $default_hidden, true )
				&& in_array( $hidden_default_marker, $default_hidden, true )
				&& in_array( $hidden_default_marker, $string_hidden, true )
				&& ! in_array( $default_marker, $empty_saved_hidden, true )
				&& in_array( $hidden_saved_marker, $empty_saved_hidden, true )
				&& in_array( $hidden_saved_marker, $saved_hidden, true )
				&& array() === array_diff( $saved, $events['hidden'][3]['hidden'] ?? array() )
				&& array() === array_diff( $other_saved, $events['hidden'][4]['hidden'] ?? array() )
				&& array() === array_intersect( $saved, $events['hidden'][4]['hidden'] ?? array() ),
			'get_hidden_columns() distinguishes defaults, explicit empty settings, saved settings, string screens, and screen-local options',
			array(
				'events'           => $events,
				'defaultHidden'    => $default_hidden,
				'stringHidden'     => $string_hidden,
				'emptySavedHidden' => $empty_saved_hidden,
				'savedHidden'      => $saved_hidden,
				'otherHidden'      => $other_hidden,
				'saved'            => $saved,
				'otherSaved'       => $other_saved,
			)
		);
		self::collect_failure(
			$failures,
			false === \has_filter( 'default_hidden_columns', $default_filter )
				&& false === \has_filter( 'hidden_columns', $hidden_filter ),
			'hidden column filters are removed after the check'
		);

		return self::result(
			$ctx,
			'user-preferences.hidden-columns.defaults-saved-and-filters',
			$failures,
			array( 'screenId' => $screen->id )
		);
	}

	private static function check_hidden_meta_box_preferences( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx );
		\wp_set_current_user( $user_id );

		$screen                = \convert_to_screen( 'post' );
		$other_screen          = \convert_to_screen( 'page' );
		$default_marker        = 'cfz_default_box_' . self::slug( $ctx, 'box' );
		$hidden_default_marker = 'cfz_meta_default_' . self::slug( $ctx, 'meta-default' );
		$hidden_saved_marker   = 'cfz_meta_saved_' . self::slug( $ctx, 'meta-saved' );
		$events                = array(
			'default' => array(),
			'hidden'  => array(),
		);

		$default_filter = static function ( array $hidden, \WP_Screen $seen_screen ) use ( &$events, $default_marker ): array {
			$events['default'][] = $seen_screen->id;
			$hidden[]            = $default_marker;
			return $hidden;
		};
		$hidden_filter  = static function ( array $hidden, \WP_Screen $seen_screen, bool $use_defaults ) use ( &$events, $hidden_default_marker, $hidden_saved_marker ): array {
			$events['hidden'][] = array(
				'id'          => $seen_screen->id,
				'useDefaults' => $use_defaults,
				'hidden'      => $hidden,
			);
			$hidden[] = $use_defaults ? $hidden_default_marker : $hidden_saved_marker;
			return $hidden;
		};

		\add_filter( 'default_hidden_meta_boxes', $default_filter, 10, 2 );
		\add_filter( 'hidden_meta_boxes', $hidden_filter, 10, 3 );
		try {
			$default_hidden = \get_hidden_meta_boxes( $screen );
			$string_hidden  = \get_hidden_meta_boxes( $screen->id );

			\update_user_option( $user_id, 'metaboxhidden_' . $screen->id, array(), false );
			\wp_set_current_user( $user_id );
			$empty_saved_hidden = \get_hidden_meta_boxes( $screen );

			$saved = array( 'slugdiv', 'postcustom', 'cfzbox_' . self::slug( $ctx, 'saved-box' ) );
			\update_user_option( $user_id, 'metaboxhidden_' . $screen->id, $saved, false );
			\wp_set_current_user( $user_id );
			$saved_hidden = \get_hidden_meta_boxes( $screen );

			$other_saved = array( 'pageparentdiv', 'cfzbox_' . self::slug( $ctx, 'other-box' ) );
			\update_user_option( $user_id, 'metaboxhidden_' . $other_screen->id, $other_saved, false );
			\wp_set_current_user( $user_id );
			$other_hidden = \get_hidden_meta_boxes( $other_screen );
		} finally {
			\remove_filter( 'hidden_meta_boxes', $hidden_filter, 10 );
			\remove_filter( 'default_hidden_meta_boxes', $default_filter, 10 );
		}

		self::collect_failure(
			$failures,
			in_array( 'slugdiv', $default_hidden, true )
				&& in_array( $default_marker, $default_hidden, true )
				&& in_array( $hidden_default_marker, $default_hidden, true )
				&& in_array( $hidden_default_marker, $string_hidden, true )
				&& ! in_array( 'slugdiv', $empty_saved_hidden, true )
				&& in_array( $hidden_saved_marker, $empty_saved_hidden, true )
				&& in_array( $hidden_saved_marker, $saved_hidden, true )
				&& array() === array_diff( $saved, $events['hidden'][3]['hidden'] ?? array() )
				&& array() === array_diff( $other_saved, $events['hidden'][4]['hidden'] ?? array() )
				&& array() === array_intersect( $saved, $events['hidden'][4]['hidden'] ?? array() )
				&& true === ( $events['hidden'][0]['useDefaults'] ?? null )
				&& true === ( $events['hidden'][1]['useDefaults'] ?? null )
				&& false === ( $events['hidden'][2]['useDefaults'] ?? null )
				&& false === ( $events['hidden'][3]['useDefaults'] ?? null )
				&& false === ( $events['hidden'][4]['useDefaults'] ?? null ),
			'get_hidden_meta_boxes() distinguishes post defaults, explicit empty settings, saved settings, string screens, and screen-local options',
			array(
				'events'           => $events,
				'defaultHidden'    => $default_hidden,
				'stringHidden'     => $string_hidden,
				'emptySavedHidden' => $empty_saved_hidden,
				'savedHidden'      => $saved_hidden,
				'otherHidden'      => $other_hidden,
				'saved'            => $saved,
				'otherSaved'       => $other_saved,
			)
		);
		self::collect_failure(
			$failures,
			false === \has_filter( 'default_hidden_meta_boxes', $default_filter )
				&& false === \has_filter( 'hidden_meta_boxes', $hidden_filter ),
			'hidden meta box filters are removed after the check'
		);

		return self::result(
			$ctx,
			'user-preferences.hidden-metaboxes.defaults-saved-and-filters',
			$failures,
			array( 'screenId' => $screen->id )
		);
	}

	private static function check_meta_box_order_preferences( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx );
		\wp_set_current_user( $user_id );

		$screen        = \convert_to_screen( 'post' );
		$box_a         = 'cfzbox_' . self::slug( $ctx, 'box-a' );
		$box_b         = 'cfzbox_' . self::slug( $ctx, 'box-b' );
		$box_c         = 'cfzbox_' . self::slug( $ctx, 'box-c' );
		$order         = array(
			'normal'   => $box_a . ',' . $box_b,
			'side'     => $box_c,
			'advanced' => '',
		);
		$closed        = array( $box_b, 'cfzclosed_' . self::slug( $ctx, 'closed' ) );
		$layout        = $ctx->int( 1, 4 );
		$dynamic_hook  = "postbox_classes_{$screen->id}_{$box_b}";
		$filter_events = array();
		$class_filter  = static function ( array $classes ) use ( &$filter_events ): array {
			$filter_events[] = $classes;
			$classes[]       = 'cfz-filtered';
			return $classes;
		};

		\update_user_option( $user_id, 'meta-box-order_' . $screen->id, $order, false );
		\update_user_option( $user_id, 'closedpostboxes_' . $screen->id, $closed, false );
		\update_user_option( $user_id, 'screen_layout_' . $screen->id, $layout, false );
		\wp_set_current_user( $user_id );

		$stored_order  = \get_user_option( 'meta-box-order_' . $screen->id, $user_id );
		$stored_closed = \get_user_option( 'closedpostboxes_' . $screen->id, $user_id );
		$stored_layout = \get_user_option( 'screen_layout_' . $screen->id, $user_id );
		$open_class    = \postbox_classes( $box_a, $screen->id );
		$closed_class  = \postbox_classes( $box_b, $screen->id );

		$_GET['edit'] = $box_b;
		$edit_class   = \postbox_classes( $box_b, $screen->id );
		unset( $_GET['edit'] );

		\add_filter( $dynamic_hook, $class_filter, 10, 1 );
		try {
			$filtered_class = \postbox_classes( $box_b, $screen->id );
		} finally {
			unset( $_GET['edit'] );
			\remove_filter( $dynamic_hook, $class_filter, 10 );
		}

		$custom_screen = 'cfz-postboxes-' . self::slug( $ctx, 'custom-screen' );
		\update_user_option( $user_id, 'closedpostboxes_' . $custom_screen, 'not-an-array', false );
		\wp_set_current_user( $user_id );
		$invalid_closed_class = \postbox_classes( $box_a, $custom_screen );

		self::collect_failure(
			$failures,
			$order === $stored_order
				&& $closed === $stored_closed
				&& $layout === $stored_layout
				&& '' === $open_class
				&& self::contains_class( $closed_class, 'closed' )
				&& '' === $edit_class
				&& self::contains_class( $filtered_class, 'closed' )
				&& self::contains_class( $filtered_class, 'cfz-filtered' )
				&& array( array( 'closed' ) ) === $filter_events
				&& '' === $invalid_closed_class,
			'meta box order, closed state, layout columns, edit override, and postbox class filters remain screen-local helper behavior',
			array(
				'screenId'           => $screen->id,
				'order'              => $order,
				'storedOrder'        => $stored_order,
				'closed'             => $closed,
				'storedClosed'       => $stored_closed,
				'layout'             => $layout,
				'storedLayout'       => $stored_layout,
				'openClass'          => $open_class,
				'closedClass'        => $closed_class,
				'editClass'          => $edit_class,
				'filteredClass'      => $filtered_class,
				'invalidClosedClass' => $invalid_closed_class,
				'filterEvents'       => $filter_events,
			)
		);
		self::collect_failure(
			$failures,
			false === \has_filter( $dynamic_hook, $class_filter )
				&& ! isset( $_GET['edit'] ),
			'meta box order check removes postbox class filters and restores $_GET edits'
		);

		return self::result(
			$ctx,
			'user-preferences.metabox-order.closed-layout-and-filters',
			$failures,
			array( 'screenId' => $screen->id )
		);
	}

	private static function check_screen_option_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures             = array();
		$user_id              = self::seed_user( $ctx );
		$screen_id            = 'cfz_user_prefs_' . self::slug( $ctx, 'screen' );
		$per_page             = $ctx->int( 5, 99 );
		$saved_per_page       = $ctx->int( 100, 150 );
		$columns              = $ctx->int( 1, 4 );
		$per_page_option      = 'cfz_items_per_page';
		$filtered_adjustment  = $ctx->int( 1, 5 );
		$per_page_events      = array();
		$show_events          = array();
		$submit_filter_before = \has_filter( 'screen_options_show_submit', '__return_true' );
		$per_page_filter      = static function ( $value ) use ( &$per_page_events, $filtered_adjustment ): int {
			$per_page_events[] = $value;
			return (int) $value + $filtered_adjustment;
		};
		$show_filter          = static function ( bool $show_screen, \WP_Screen $seen_screen ) use ( &$show_events ): bool {
			$show_events[] = array(
				'id'   => $seen_screen->id,
				'show' => $show_screen,
			);
			return ! $show_screen;
		};

		\wp_set_current_user( $user_id );
		\set_current_screen( $screen_id );
		$screen = \get_current_screen();
		\add_screen_option(
			'per_page',
			array(
				'label'   => 'Component Fuzz Per Page',
				'default' => $per_page,
				'option'  => $per_page_option,
			)
		);
		\add_screen_option(
			'layout_columns',
			array(
				'max'     => 4,
				'default' => $columns,
			)
		);
		$missing_before = $screen->get_option( 'not_registered' );
		$options        = $screen->get_options();

		\add_filter( $per_page_option, $per_page_filter, 10, 1 );
		try {
			$default_output = self::capture_output(
				static function () use ( $screen ): void {
					$screen->render_per_page_options();
				}
			);

			\update_user_option( $user_id, $per_page_option, $saved_per_page, false );
			\wp_set_current_user( $user_id );
			$saved_output = self::capture_output(
				static function () use ( $screen ): void {
					$screen->render_per_page_options();
				}
			);
		} finally {
			\remove_filter( $per_page_option, $per_page_filter, 10 );
			if ( false === $submit_filter_before ) {
				\remove_filter( 'screen_options_show_submit', '__return_true' );
			}
		}

		$default_value = self::screen_option_input_value( $default_output, $per_page_option );
		$saved_value   = self::screen_option_input_value( $saved_output, $per_page_option );

		$show_screen = \convert_to_screen( 'cfz_user_prefs_show_' . self::slug( $ctx, 'show-screen' ) );
		\add_filter( 'screen_options_show_screen', $show_filter, 10, 2 );
		try {
			$show_result = $show_screen->show_screen_options();
		} finally {
			\remove_filter( 'screen_options_show_screen', $show_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$screen instanceof \WP_Screen
				&& $screen_id === $screen->id
				&& $per_page === $screen->get_option( 'per_page', 'default' )
				&& $per_page_option === $screen->get_option( 'per_page', 'option' )
				&& $columns === $screen->get_option( 'layout_columns', 'default' )
				&& null === $missing_before
				&& isset( $options['per_page'], $options['layout_columns'] ),
			'add_screen_option() stores options on the current WP_Screen with keyed lookups',
			array(
				'screenId'      => $screen_id,
				'options'       => $options,
				'missingBefore' => $missing_before,
			)
		);
		self::collect_failure(
			$failures,
			array( $per_page, $saved_per_page ) === $per_page_events
				&& (string) ( $per_page + $filtered_adjustment ) === $default_value
				&& (string) ( $saved_per_page + $filtered_adjustment ) === $saved_value
				&& false === \has_filter( $per_page_option, $per_page_filter )
				&& $submit_filter_before === \has_filter( 'screen_options_show_submit', '__return_true' ),
			'render_per_page_options() uses registered defaults, saved user options, dynamic filters, and restores render-added submit filters',
			array(
				'perPageEvents'      => $per_page_events,
				'defaultValue'       => $default_value,
				'savedValue'         => $saved_value,
				'defaultOutput'      => $default_output,
				'savedOutput'        => $saved_output,
				'submitFilterBefore' => $submit_filter_before,
				'submitFilterAfter'  => \has_filter( 'screen_options_show_submit', '__return_true' ),
			)
		);
		self::collect_failure(
			$failures,
			true === $show_result
				&& array(
					array(
						'id'   => $show_screen->id,
						'show' => false,
					),
				) === $show_events
				&& false === \has_filter( 'screen_options_show_screen', $show_filter ),
			'WP_Screen::show_screen_options() exposes default visibility to filters and restores them after use',
			array(
				'showResult' => $show_result,
				'showEvents' => $show_events,
			)
		);

		return self::result(
			$ctx,
			'user-preferences.screen-options.registration-defaults-and-filters',
			$failures,
			array( 'screenId' => $screen_id )
		);
	}

	private static function check_screen_layout_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$user_id        = self::seed_user( $ctx );
		$max_columns    = 4;
		$default_column = $ctx->int( 1, $max_columns );
		$saved_column   = $default_column === $max_columns ? 1 : $max_columns;
		$registered_id  = 'cfz_user_prefs_layout_' . self::slug( $ctx, 'registered' );
		$saved_id       = 'cfz_user_prefs_saved_layout_' . self::slug( $ctx, 'saved' );
		$legacy_id      = 'cfz_user_prefs_legacy_layout_' . self::slug( $ctx, 'legacy' );
		$legacy_max     = 3;
		$legacy_saved   = $ctx->int( 1, $legacy_max );
		$legacy_events  = array();
		$legacy_filter  = static function ( array $columns, string $screen_id, \WP_Screen $screen ) use ( &$legacy_events, $legacy_id, $legacy_max ): array {
			$legacy_events[] = array(
				'id'     => $screen_id,
				'screen' => $screen->id,
			);

			if ( $legacy_id === $screen_id ) {
				$columns[ $legacy_id ] = $legacy_max;
			}

			return $columns;
		};

		\wp_set_current_user( $user_id );

		\set_current_screen( $registered_id );
		$registered_screen = \get_current_screen();
		\add_screen_option(
			'layout_columns',
			array(
				'max'     => $max_columns,
				'default' => $default_column,
			)
		);
		$registered_output  = self::capture_output(
			static function () use ( $registered_screen ): void {
				$registered_screen->render_screen_meta();
			}
		);
		$registered_columns = $registered_screen->get_columns();

		\update_user_option( $user_id, 'screen_layout_' . $saved_id, $saved_column, false );
		\wp_set_current_user( $user_id );
		\set_current_screen( $saved_id );
		$saved_screen = \get_current_screen();
		\add_screen_option(
			'layout_columns',
			array(
				'max'     => $max_columns,
				'default' => $default_column,
			)
		);
		$saved_output  = self::capture_output(
			static function () use ( $saved_screen ): void {
				$saved_screen->render_screen_meta();
			}
		);
		$saved_columns = $saved_screen->get_columns();

		\update_user_option( $user_id, 'screen_layout_' . $legacy_id, $legacy_saved, false );
		\wp_set_current_user( $user_id );
		\add_filter( 'screen_layout_columns', $legacy_filter, 10, 3 );
		try {
			\set_current_screen( $legacy_id );
			$legacy_screen = \get_current_screen();
			$legacy_output = self::capture_output(
				static function () use ( $legacy_screen ): void {
					$legacy_screen->render_screen_meta();
				}
			);
			$legacy_columns = $legacy_screen->get_columns();
		} finally {
			\remove_filter( 'screen_layout_columns', $legacy_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$registered_screen instanceof \WP_Screen
				&& $default_column === $registered_columns
				&& range( 1, $max_columns ) === self::screen_layout_radio_values( $registered_output )
				&& array( $default_column ) === self::screen_layout_checked_values( $registered_output )
				&& str_contains( $registered_output, "class='columns-prefs'" )
				&& ! str_contains( $registered_output, '<script' ),
			'WP_Screen::render_screen_meta() renders registered layout column defaults with bounded radio choices',
			array(
				'screenId'   => $registered_id,
				'default'    => $default_column,
				'columns'    => $registered_columns,
				'values'     => self::screen_layout_radio_values( $registered_output ),
				'checked'    => self::screen_layout_checked_values( $registered_output ),
			)
		);
		self::collect_failure(
			$failures,
			$saved_column === $saved_columns
				&& range( 1, $max_columns ) === self::screen_layout_radio_values( $saved_output )
				&& array( $saved_column ) === self::screen_layout_checked_values( $saved_output ),
			'WP_Screen::render_screen_meta() prefers saved user layout columns over registered defaults',
			array(
				'screenId' => $saved_id,
				'default'  => $default_column,
				'saved'    => $saved_column,
				'columns'  => $saved_columns,
				'values'   => self::screen_layout_radio_values( $saved_output ),
				'checked'  => self::screen_layout_checked_values( $saved_output ),
			)
		);
		self::collect_failure(
			$failures,
			array( $legacy_saved ) === self::screen_layout_checked_values( $legacy_output )
				&& range( 1, $legacy_max ) === self::screen_layout_radio_values( $legacy_output )
				&& $legacy_saved === $legacy_columns
				&& array( array( 'id' => $legacy_id, 'screen' => $legacy_id ) ) === $legacy_events
				&& false === \has_filter( 'screen_layout_columns', $legacy_filter ),
			'legacy screen_layout_columns filters provision layout columns and are removed after rendering',
			array(
				'screenId' => $legacy_id,
				'saved'    => $legacy_saved,
				'columns'  => $legacy_columns,
				'values'   => self::screen_layout_radio_values( $legacy_output ),
				'checked'  => self::screen_layout_checked_values( $legacy_output ),
				'events'   => $legacy_events,
			)
		);

		return self::result(
			$ctx,
			'user-preferences.screen-layout.rendering-and-legacy-filter',
			$failures,
			array(
				'userId'       => $user_id,
				'registeredId' => $registered_id,
				'savedId'      => $saved_id,
				'legacyId'     => $legacy_id,
			)
		);
	}

	private static function seed_user( \ComponentFuzz\FuzzContext $ctx, array $overrides = array() ): int {
		$login = 'cfz_pref_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 ) . '_' . self::slug( $ctx, 'user' );
		$id    = \wp_insert_user(
			array_merge(
				array(
					'user_login'   => $login,
					'user_pass'    => 'component-fuzz-pass',
					'user_email'   => $login . '@example.test',
					'user_nicename' => $login,
					'display_name' => 'Component Fuzz ' . $login,
				),
				$overrides
			)
		);

		if ( \is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Could not seed user: ' . $id->get_error_message() );
		}

		return (int) $id;
	}

	private static function reset_runtime(): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		\wp_set_current_user( 0 );
		unset( $GLOBALS['_updated_user_settings'] );
		$_GET     = array();
		$_COOKIE = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	private static function settings_are_ascii_pairs( array $settings ): bool {
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				return false;
			}
			if ( ! preg_match( '/^[A-Za-z0-9_-]*$/', $key ) || ! preg_match( '/^[A-Za-z0-9_-]*$/', $value ) ) {
				return false;
			}
		}

		return true;
	}

	private static function sanitize_user_setting_token( string $value ): string {
		return preg_replace( '/[^A-Za-z0-9_-]+/', '', $value );
	}

	private static function user_setting_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$raw_cases = array(
			array(
				'rawName'  => 'cfz_alpha_' . self::slug( $ctx, 'alpha' ),
				'rawValue' => 'one_' . self::slug( $ctx, 'one' ),
			),
			array(
				'rawName'  => 'cfz-dash_' . self::slug( $ctx, 'dash' ),
				'rawValue' => 'value-with-dash_' . self::slug( $ctx, 'dash-value' ),
			),
			array(
				'rawName'  => 'cfz<script>bad_' . self::slug( $ctx, 'bad-name' ),
				'rawValue' => 'grid<script>&x=' . self::slug( $ctx, 'bad-value' ),
			),
			array(
				'rawName'  => 'cfz space/slash ' . self::slug( $ctx, 'space-name' ),
				'rawValue' => "tabs\tnew\nline " . self::slug( $ctx, 'space-value' ),
			),
			array(
				'rawName'  => '',
				'rawValue' => 'ignored_' . self::slug( $ctx, 'ignored' ),
			),
		);

		foreach ( $raw_cases as $index => $case ) {
			$raw_cases[ $index ]['expectedName']  = self::sanitize_user_setting_token( $case['rawName'] );
			$raw_cases[ $index ]['expectedValue'] = self::sanitize_user_setting_token( $case['rawValue'] );
		}

		return $raw_cases;
	}

	private static function settings_query_string( array $settings ): string {
		$serialized = array();
		foreach ( $settings as $name => $value ) {
			$serialized[] = $name . '=' . $value;
		}

		return implode( '&', $serialized );
	}

	private static function capture_output( callable $callback ): string {
		\ob_start();
		try {
			$callback();
			return (string) \ob_get_clean();
		} catch ( \Throwable $e ) {
			\ob_end_clean();
			throw $e;
		}
	}

	private static function screen_option_input_value( string $html, string $input_id ): ?string {
		if ( preg_match( '/<input[^>]+id="' . preg_quote( $input_id, '/' ) . '"[^>]+value="([^"]*)"/', $html, $matches ) ) {
			return html_entity_decode( $matches[1], ENT_QUOTES );
		}

		return null;
	}

	private static function screen_layout_radio_values( string $html ): array {
		if ( ! preg_match_all( "/<input[^>]+name='screen_columns'[^>]+>/", $html, $matches ) ) {
			return array();
		}

		$values = array();
		foreach ( $matches[0] as $tag ) {
			if ( preg_match( "/value='([0-9]+)'/", $tag, $value_match ) ) {
				$values[] = (int) $value_match[1];
			}
		}

		return $values;
	}

	private static function screen_layout_checked_values( string $html ): array {
		if ( ! preg_match_all( "/<input[^>]+name='screen_columns'[^>]+>/", $html, $matches ) ) {
			return array();
		}

		$values = array();
		foreach ( $matches[0] as $tag ) {
			if (
				( str_contains( $tag, "checked='checked'" ) || str_contains( $tag, 'checked="checked"' ) )
				&& preg_match( "/value='([0-9]+)'/", $tag, $value_match )
			) {
				$values[] = (int) $value_match[1];
			}
		}

		return $values;
	}

	private static function contains_class( string $class_string, string $class ): bool {
		return in_array( $class, preg_split( '/\s+/', trim( $class_string ) ), true );
	}

	private static function snapshot_state(): array {
		$options = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return array(
			'globals' => self::snapshot_globals(
				array(
					'_GET',
					'_COOKIE',
					'_POST',
					'_REQUEST',
					'_updated_user_settings',
					'current_screen',
					'current_user',
					'screen_layout_columns',
					'taxnow',
					'typenow',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
				)
			),
			'options' => $options,
			'counts'  => self::content_counts(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
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
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		$options = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return $snapshot['options'] === $options
			&& $snapshot['counts'] === self::content_counts()
			&& self::globals_match( $snapshot['globals'] );
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

	private static function globals_match( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
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

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
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
			try {
				return clone $value;
			} catch ( \Throwable $e ) {
				return $value;
			}
		}

		return $value;
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => self::preview( $data ),
		);
	}

	private static function preview( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > self::PREVIEW_BYTES ? substr( $value, 0, self::PREVIEW_BYTES ) . '...' : $value;
		}
		if ( is_array( $value ) ) {
			$json = \wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			return false === $json ? '[array]' : self::preview( $json );
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		return $value;
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $fallback ): string {
		$value = preg_replace( '/[^A-Za-z0-9_-]+/', '-', strtolower( $ctx->identifier( 3, 12 ) ) );
		$value = trim( (string) $value, '-' );

		return '' === $value ? $fallback : $value;
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
