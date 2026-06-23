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
			$rows[] = self::check_hidden_column_preferences( $ctx->fork( 'hidden-columns' ) );
			$rows[] = self::check_hidden_meta_box_preferences( $ctx->fork( 'hidden-metaboxes' ) );
			$rows[] = self::check_screen_option_registration( $ctx->fork( 'screen-options' ) );
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
				'delete_user_setting',
				'get_all_user_settings',
				'get_current_screen',
				'get_hidden_columns',
				'get_hidden_meta_boxes',
				'get_user_option',
				'get_user_setting',
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

		$raw_name       = 'mode<script>' . $ctx->identifier( 3, 8 );
		$raw_value      = 'grid<script>&x=' . $ctx->identifier( 3, 8 );
		$expected_name  = preg_replace( '/[^A-Za-z0-9_-]+/', '', $raw_name );
		$expected_value = preg_replace( '/[^A-Za-z0-9_-]+/', '', $raw_value );

		$set_result   = \set_user_setting( $raw_name, $raw_value );
		$saved_string = \get_user_option( 'user-settings', $user_id );
		$read_back    = \get_user_setting( $expected_name, 'missing' );

		$delete_result  = \delete_user_setting( $expected_name );
		$after_delete   = \get_user_setting( $expected_name, 'deleted' );
		$missing_delete = \delete_user_setting( 'not-present-' . $ctx->identifier( 3, 8 ) );

		\delete_all_user_settings();
		$after_delete_all = \get_user_option( 'user-settings', $user_id );

		\wp_set_current_user( 0 );
		unset( $GLOBALS['_updated_user_settings'] );
		$logged_out_set = \set_user_setting( 'logged-out', 'value' );

		self::collect_failure(
			$failures,
			isset( $from_cookie['alpha'], $from_cookie['dash-key'] )
				&& 'one' === $from_cookie['alpha']
				&& 'value-with-dash' === $from_cookie['dash-key']
				&& $cookie_safe,
			'get_all_user_settings() parses only sanitized cookie key/value pairs',
			array(
				'cookie'     => $cookie,
				'fromCookie' => $from_cookie,
			)
		);
		self::collect_failure(
			$failures,
			true === $set_result
				&& $expected_value === $read_back
				&& is_string( $saved_string )
				&& str_contains( $saved_string, $expected_name . '=' . $expected_value )
				&& ! str_contains( $saved_string, '<script' )
				&& ! str_contains( $saved_string, '&x=' ),
			'set_user_setting() strips disallowed bytes before persisting user settings',
			array(
				'rawName'       => $raw_name,
				'rawValue'      => $raw_value,
				'expectedName'  => $expected_name,
				'expectedValue' => $expected_value,
				'savedString'   => $saved_string,
				'readBack'      => $read_back,
			)
		);
		self::collect_failure(
			$failures,
			true === $delete_result
				&& 'deleted' === $after_delete
				&& false === $missing_delete
				&& '' === $after_delete_all
				&& false === $logged_out_set,
			'user setting delete helpers distinguish existing, missing, all, and logged-out cases',
			array(
				'deleteResult'     => $delete_result,
				'afterDelete'      => $after_delete,
				'missingDelete'    => $missing_delete,
				'afterDeleteAll'   => $after_delete_all,
				'loggedOutSet'     => $logged_out_set,
			)
		);

		return self::result(
			$ctx,
			'user-preferences.user-settings.sanitize-persist-delete',
			$failures,
			array( 'userId' => $user_id )
		);
	}

	private static function check_hidden_column_preferences( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx );
		\wp_set_current_user( $user_id );

		$screen = \convert_to_screen( 'edit-post' );
		$events = array(
			'default' => array(),
			'hidden'  => array(),
		);

		$default_filter = static function ( array $hidden, \WP_Screen $seen_screen ) use ( &$events, $ctx ): array {
			$events['default'][] = $seen_screen->id;
			$hidden[]            = 'cfz_default_' . self::slug( $ctx, 'column' );
			return $hidden;
		};
		$hidden_filter  = static function ( array $hidden, \WP_Screen $seen_screen, bool $use_defaults ) use ( &$events ): array {
			$events['hidden'][] = array(
				'id'          => $seen_screen->id,
				'useDefaults' => $use_defaults,
				'hidden'      => $hidden,
			);
			$hidden[] = $use_defaults ? 'cfz_hidden_default' : 'cfz_hidden_saved';
			return $hidden;
		};

		\add_filter( 'default_hidden_columns', $default_filter, 10, 2 );
		\add_filter( 'hidden_columns', $hidden_filter, 10, 3 );
		try {
			$default_hidden = \get_hidden_columns( $screen );

			$saved = array( 'author', 'comments', 'cfz_' . self::slug( $ctx, 'saved' ) );
			\update_user_option( $user_id, 'manage' . $screen->id . 'columnshidden', $saved, false );
			\wp_set_current_user( $user_id );
			$saved_hidden = \get_hidden_columns( $screen );
		} finally {
			\remove_filter( 'hidden_columns', $hidden_filter, 10 );
			\remove_filter( 'default_hidden_columns', $default_filter, 10 );
		}

		self::collect_failure(
			$failures,
			1 === count( $events['default'] )
				&& 2 === count( $events['hidden'] )
				&& true === $events['hidden'][0]['useDefaults']
				&& false === $events['hidden'][1]['useDefaults']
				&& in_array( 'cfz_hidden_default', $default_hidden, true )
				&& in_array( 'cfz_hidden_saved', $saved_hidden, true )
				&& array() === array_diff( $saved, $events['hidden'][1]['hidden'] ),
			'get_hidden_columns() selects default filters only without a saved user option',
			array(
				'events'        => $events,
				'defaultHidden' => $default_hidden,
				'savedHidden'   => $saved_hidden,
				'saved'         => $saved,
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

		$screen = \convert_to_screen( 'post' );
		$events = array(
			'default' => array(),
			'hidden'  => array(),
		);

		$default_filter = static function ( array $hidden, \WP_Screen $seen_screen ) use ( &$events, $ctx ): array {
			$events['default'][] = $seen_screen->id;
			$hidden[]            = 'cfz_default_box_' . self::slug( $ctx, 'box' );
			return $hidden;
		};
		$hidden_filter  = static function ( array $hidden, \WP_Screen $seen_screen, bool $use_defaults ) use ( &$events ): array {
			$events['hidden'][] = array(
				'id'          => $seen_screen->id,
				'useDefaults' => $use_defaults,
				'hidden'      => $hidden,
			);
			$hidden[] = $use_defaults ? 'cfz_meta_default' : 'cfz_meta_saved';
			return $hidden;
		};

		\add_filter( 'default_hidden_meta_boxes', $default_filter, 10, 2 );
		\add_filter( 'hidden_meta_boxes', $hidden_filter, 10, 3 );
		try {
			$default_hidden = \get_hidden_meta_boxes( $screen );

			$saved = array( 'slugdiv', 'postcustom', 'cfzbox_' . self::slug( $ctx, 'saved-box' ) );
			\update_user_option( $user_id, 'metaboxhidden_' . $screen->id, $saved, false );
			\wp_set_current_user( $user_id );
			$saved_hidden = \get_hidden_meta_boxes( $screen );
		} finally {
			\remove_filter( 'hidden_meta_boxes', $hidden_filter, 10 );
			\remove_filter( 'default_hidden_meta_boxes', $default_filter, 10 );
		}

		self::collect_failure(
			$failures,
			in_array( 'slugdiv', $default_hidden, true )
				&& in_array( 'cfz_meta_default', $default_hidden, true )
				&& in_array( 'cfz_meta_saved', $saved_hidden, true )
				&& array() === array_diff( $saved, $events['hidden'][1]['hidden'] ?? array() )
				&& true === ( $events['hidden'][0]['useDefaults'] ?? null )
				&& false === ( $events['hidden'][1]['useDefaults'] ?? null ),
			'get_hidden_meta_boxes() uses post defaults until explicit user preferences exist',
			array(
				'events'        => $events,
				'defaultHidden' => $default_hidden,
				'savedHidden'   => $saved_hidden,
				'saved'         => $saved,
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

	private static function check_screen_option_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$screen_id = 'cfz_user_prefs_' . self::slug( $ctx, 'screen' );
		$per_page  = $ctx->int( 5, 99 );
		$columns   = $ctx->int( 1, 4 );

		\set_current_screen( $screen_id );
		$screen = \get_current_screen();
		\add_screen_option(
			'per_page',
			array(
				'label'   => 'Component Fuzz Per Page',
				'default' => $per_page,
				'option'  => 'cfz_items_per_page',
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

		self::collect_failure(
			$failures,
			$screen instanceof \WP_Screen
				&& $screen_id === $screen->id
				&& $per_page === $screen->get_option( 'per_page', 'default' )
				&& 'cfz_items_per_page' === $screen->get_option( 'per_page', 'option' )
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

		return self::result(
			$ctx,
			'user-preferences.screen-options.registration',
			$failures,
			array( 'screenId' => $screen_id )
		);
	}

	private static function seed_user( \ComponentFuzz\FuzzContext $ctx ): int {
		$login = 'cfz_pref_' . self::slug( $ctx, 'user' );
		$id    = \wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => 'component-fuzz-pass',
				'user_email'   => $login . '@example.test',
				'user_nicename' => $login,
				'display_name' => 'Component Fuzz ' . $login,
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
		$_COOKIE = array();
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

	private static function snapshot_state(): array {
		$options = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return array(
			'globals' => self::snapshot_globals(
				array(
					'_COOKIE',
					'_POST',
					'_REQUEST',
					'_updated_user_settings',
					'current_screen',
					'current_user',
					'user_ID',
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
