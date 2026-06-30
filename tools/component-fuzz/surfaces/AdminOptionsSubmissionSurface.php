<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes the wp-admin/options.php settings-submission update branch.
 */
final class AdminOptionsSubmissionSurface {
	public const NAME = 'admin-options-submission';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_new_admin_email_support();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-options-submission.bootstrap-apis-available',
					'Required admin settings submission APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_registered_settings_submission( $ctx->fork( 'registered' ) );
			$rows[] = self::check_general_options_submission_branches( $ctx->fork( 'general' ) );
			$rows[] = self::check_new_admin_email_pending_change( $ctx->fork( 'new-admin-email' ) );
			$rows[] = self::check_legacy_options_page_submission( $ctx->fork( 'legacy' ) );
			$rows[] = self::check_failure_paths( $ctx->fork( 'failures' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'admin-options-submission.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'admin-options-submission.global-state-restored',
			self::state_matches( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot['globals'] ) )
		);

		return $rows;
	}

	private static function load_new_admin_email_support(): void {
		$misc_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/misc.php' : '';
		if ( ! function_exists( 'update_option_new_admin_email' ) && $misc_file && file_exists( $misc_file ) ) {
			require_once $misc_file;
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'add_filter',
				'add_query_arg',
				'add_action',
				'add_option',
				'add_settings_error',
				'admin_url',
				'apply_filters',
				'apply_filters_deprecated',
				'check_admin_referer',
				'current_user_can',
				'delete_transient',
				'esc_url',
				'esc_html',
				'get_current_user_id',
				'get_settings_errors',
				'get_transient',
				'get_user_locale',
				'get_option',
				'has_action',
				'has_filter',
				'home_url',
				'is_email',
				'is_wp_error',
				'load_default_textdomain',
				'option_update_filter',
				'restore_previous_locale',
				'remove_action',
				'register_setting',
				'remove_filter',
				'sanitize_key',
				'sanitize_email',
				'sanitize_text_field',
				'sanitize_textarea_field',
				'sanitize_option',
				'set_transient',
				'unregister_setting',
				'update_option',
				'update_option_new_admin_email',
				'wp_cache_flush',
				'wp_create_nonce',
				'wp_die',
				'wp_get_current_user',
				'wp_get_referer',
				'wp_insert_user',
				'wp_mail',
				'wp_rand',
				'wp_redirect',
				'wp_set_current_user',
				'wp_slash',
				'wp_specialchars_decode',
				'wp_unslash',
				'self_admin_url',
				'switch_to_user_locale',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'Component_Fuzz_WPDB_Stub', false ) ) {
			$missing[] = 'class Component_Fuzz_WPDB_Stub';
		}
		if ( ! class_exists( 'WP_User', false ) ) {
			$missing[] = 'class WP_User';
		}

		return $missing;
	}

	private static function check_registered_settings_submission( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures     = array();
		$group        = self::id( $ctx->fork( 'group' ), 'cfz_settings_group', 40 );
		$text_option  = self::id( $ctx->fork( 'text' ), 'cfz_settings_text', 40 );
		$array_option = self::id( $ctx->fork( 'array' ), 'cfz_settings_array', 40 );
		$missing_option = self::id( $ctx->fork( 'missing' ), 'cfz_settings_missing', 40 );
		$error_option = self::id( $ctx->fork( 'error' ), 'cfz_settings_error', 40 );
		$intruder     = self::id( $ctx->fork( 'intruder' ), 'cfz_settings_intruder', 40 );
		$raw_text     = "  Raw <b>" . $ctx->identifier( 4, 8 ) . "</b> \\\"quoted\\\"  ";
		$raw_error    = 'Needs review <script>bad()</script>';
		$array_raw    = array(
			' First Value ',
			'<b>' . $ctx->identifier( 3, 7 ) . '</b>',
			'third\\value',
		);
		$sanitize_calls = array();

		\add_option( $text_option, 'previous text' );
		\add_option( $array_option, array( 'previous-array' ) );
		\add_option( $missing_option, 'previous missing' );
		\add_option( $error_option, 'previous error' );

		$text_sanitize = static function ( $value ) use ( &$sanitize_calls ): string {
			$sanitize_calls['text'][] = $value;
			return 'text:' . \sanitize_text_field( (string) $value );
		};
		$array_sanitize = static function ( $value ) use ( &$sanitize_calls ): array {
			$sanitize_calls['array'][] = $value;
			return array_map(
				static fn( $item ): string => \sanitize_key( \sanitize_text_field( (string) $item ) ),
				(array) $value
			);
		};
		$missing_sanitize = static function ( $value ) use ( &$sanitize_calls ): string {
			$sanitize_calls['missing'][] = $value;
			return null === $value ? 'missing-was-null' : 'missing:' . \sanitize_text_field( (string) $value );
		};
		$error_sanitize = static function ( $value ) use ( &$sanitize_calls, $error_option ): string {
			$sanitize_calls['error'][] = $value;
			\add_settings_error( $error_option, 'component_fuzz_rejected', 'Generated setting requires review.', 'error' );
			return 'error:' . \sanitize_text_field( (string) $value );
		};

		\register_setting( $group, $text_option, array( 'sanitize_callback' => $text_sanitize ) );
		\register_setting( $group, $array_option, array( 'sanitize_callback' => $array_sanitize ) );
		\register_setting( $group, $missing_option, array( 'sanitize_callback' => $missing_sanitize ) );
		\register_setting( $group, $error_option, array( 'sanitize_callback' => $error_sanitize ) );

		$cap_events = array();
		$cap_filter = self::install_cap_filter(
			array( 'manage_options', 'manage_component_fuzz_settings' ),
			$cap_events
		);
		$capability_filter = static function ( string $capability ) use ( &$cap_events ): string {
			$cap_events[] = array(
				'type'       => 'option-page-capability',
				'capability' => $capability,
			);
			return 'manage_component_fuzz_settings';
		};

		\add_filter( "option_page_capability_{$group}", $capability_filter );
		\add_filter( 'allowed_options', 'option_update_filter' );

		try {
			$result = self::dispatch_options_update(
				array(
					'post'    => array(
						'action'      => 'update',
						'option_page' => $group,
						'_wpnonce'    => \wp_create_nonce( $group . '-options' ),
						$text_option  => $raw_text,
						$array_option => $array_raw,
						$error_option => $raw_error,
						$intruder     => 'posted but not allowlisted',
					),
					'referer' => 'http://example.test/wp-admin/options-general.php?page=' . rawurlencode( $group ) . '&tab=main',
				)
			);
		} finally {
			\remove_filter( 'allowed_options', 'option_update_filter' );
			\remove_filter( "option_page_capability_{$group}", $capability_filter );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\unregister_setting( $group, $text_option );
			\unregister_setting( $group, $array_option );
			\unregister_setting( $group, $missing_option );
			\unregister_setting( $group, $error_option );
		}

		$errors    = $result['settingsErrors'] ?? array();
		$transient = $result['settingsTransient'] ?? array();
		$expected_text = 'text:' . \sanitize_text_field( (string) trim( $raw_text ) );
		$expected_array = array_map(
			static fn( $item ): string => \sanitize_key( \sanitize_text_field( (string) $item ) ),
			$array_raw
		);
		$expected_error = 'error:' . \sanitize_text_field( (string) trim( $raw_error ) );

		self::collect_failure(
			$failures,
			'completed' === ( $result['status'] ?? null )
				&& false === ( $result['redirectResult'] ?? null )
				&& self::redirect_has_settings_updated( $result['redirect']['location'] ?? '' )
				&& 302 === ( $result['redirect']['status'] ?? null )
				&& array( trim( $raw_text ) ) === ( $sanitize_calls['text'] ?? null )
				&& array( $array_raw ) === ( $sanitize_calls['array'] ?? null )
				&& array( null ) === ( $sanitize_calls['missing'] ?? null )
				&& array( trim( $raw_error ) ) === ( $sanitize_calls['error'] ?? null )
				&& $expected_text === \get_option( $text_option )
				&& $expected_array === \get_option( $array_option )
				&& 'missing-was-null' === \get_option( $missing_option )
				&& $expected_error === \get_option( $error_option )
				&& '__missing__' === \get_option( $intruder, '__missing__' )
				&& in_array( $text_option, $result['allowedOptions'][ $group ] ?? array(), true )
				&& in_array( $array_option, $result['updatedOptions'] ?? array(), true )
				&& self::has_settings_error( $errors, $error_option, 'component_fuzz_rejected', 'error' )
				&& $errors === $transient
				&& ! self::has_settings_error( $errors, 'general', 'settings_updated', 'success' )
				&& self::nonce_event_seen( $result, $group . '-options', 1 )
				&& self::capability_event_seen( $cap_events, 'manage_component_fuzz_settings' )
				&& false === \has_filter( 'allowed_options', 'option_update_filter' )
				&& false === \has_filter( "option_page_capability_{$group}", $capability_filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'registered Settings API submission updates only allowlisted options, runs sanitize callbacks, persists errors, and redirects back',
			array(
				'group'          => $group,
				'result'         => self::summarize_dispatch_result( $result ),
				'sanitizeCalls'  => self::describe_value( $sanitize_calls ),
				'capEvents'      => array_slice( $cap_events, 0, 8 ),
				'stored'         => array(
					'text'      => \get_option( $text_option ),
					'array'     => \get_option( $array_option ),
					'missing'   => \get_option( $missing_option ),
					'error'     => \get_option( $error_option ),
					'intruder'  => \get_option( $intruder, '__missing__' ),
				),
				'errors'         => $errors,
			)
		);

		return $ctx->result(
			'admin-options-submission.registered-settings-update',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 3 ) )
		);
	}

	private static function check_general_options_submission_branches( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cap_events = array();
		$cap_filter = self::install_cap_filter( array( 'manage_options' ), $cap_events );
		$email_filter = static function ( $is_email, string $email ) {
			return 'admin@example.com' === $email ? $email : $is_email;
		};
		$sanitize_email_filter = static function ( string $sanitized, string $email ): string {
			return 'admin@example.com' === $email ? $email : $sanitized;
		};
		\add_filter( 'is_email', $email_filter, 10, 2 );
		\add_filter( 'sanitize_email', $sanitize_email_filter, 10, 2 );

		try {
			self::reset_runtime(
				array(
					'timezone_string' => 'Europe/Madrid',
					'gmt_offset'      => '1',
					'date_format'     => 'Y-m-d',
					'time_format'     => 'H:i',
				)
			);

			$invalid_tz = self::dispatch_options_update(
				array(
					'post'    => array_merge(
						self::general_post_defaults(),
						array(
							'action'             => 'update',
							'option_page'        => 'general',
							'_wpnonce'           => \wp_create_nonce( 'general-options' ),
							'date_format'        => '\c\u\s\t\o\m',
							'date_format_custom' => 'Y/m/d <b>' . $ctx->identifier( 3, 6 ) . '</b>',
							'time_format'        => '\c\u\s\t\o\m',
							'time_format_custom' => 'H:i:s <i>' . $ctx->identifier( 3, 6 ) . '</i>',
							'timezone_string'    => 'Mars/Olympus Mons',
							'gmt_offset'         => '1',
							'blogname'           => 'General <b>Branch</b>',
							'blogdescription'    => 'Description <i>Branch</i>',
						)
					),
					'referer' => 'http://example.test/wp-admin/options-general.php',
				)
			);

			$invalid_values = array(
				'date_format'     => \get_option( 'date_format' ),
				'time_format'     => \get_option( 'time_format' ),
				'timezone_string' => \get_option( 'timezone_string' ),
				'gmt_offset'      => \get_option( 'gmt_offset' ),
				'blogname'        => \get_option( 'blogname' ),
				'blogdescription' => \get_option( 'blogdescription' ),
			);

			self::reset_runtime(
				array(
					'timezone_string' => 'Europe/Madrid',
					'gmt_offset'      => '1',
				)
			);
			$utc_offset = self::dispatch_options_update(
				array(
					'post'    => array_merge(
						self::general_post_defaults(),
						array(
							'action'          => 'update',
							'option_page'     => 'general',
							'_wpnonce'        => \wp_create_nonce( 'general-options' ),
							'timezone_string' => 'UTC+5.5',
							'gmt_offset'      => '1',
							'date_format'     => 'Y-m-d',
							'time_format'     => 'H:i',
							'blogname'        => 'UTC Branch',
							'blogdescription' => 'UTC Description',
						)
					),
					'referer' => 'http://example.test/wp-admin/options-general.php',
				)
			);
			$utc_values = array(
				'timezone_string' => \get_option( 'timezone_string' ),
				'gmt_offset'      => \get_option( 'gmt_offset' ),
			);
		} finally {
			\remove_filter( 'sanitize_email', $sanitize_email_filter, 10 );
			\remove_filter( 'is_email', $email_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			'completed' === ( $invalid_tz['status'] ?? null )
				&& self::redirect_has_settings_updated( $invalid_tz['redirect']['location'] ?? '' )
				&& str_contains( $invalid_values['date_format'], 'Y/m/d' )
				&& ! str_contains( $invalid_values['date_format'], '<' )
				&& str_contains( $invalid_values['time_format'], 'H:i:s' )
				&& ! str_contains( $invalid_values['time_format'], '<' )
				&& 'Europe/Madrid' === $invalid_values['timezone_string']
				&& '1' === (string) $invalid_values['gmt_offset']
				&& 'General &lt;b&gt;Branch&lt;/b&gt;' === $invalid_values['blogname']
				&& 'Description &lt;i&gt;Branch&lt;/i&gt;' === $invalid_values['blogdescription']
				&& self::has_settings_error( $invalid_tz['settingsErrors'] ?? array(), 'general', 'settings_updated', 'error' )
				&& ! self::has_settings_error( $invalid_tz['settingsErrors'] ?? array(), 'general', 'settings_updated', 'success' )
				&& self::nonce_event_seen( $invalid_tz, 'general-options', 1 ),
			'general options submission applies custom date/time formats, rejects invalid timezones, preserves current timezone, and stores error transient',
			array(
				'result' => self::summarize_dispatch_result( $invalid_tz ),
				'values' => $invalid_values,
			)
		);

		self::collect_failure(
			$failures,
			'completed' === ( $utc_offset['status'] ?? null )
				&& '' === $utc_values['timezone_string']
				&& '5.5' === (string) $utc_values['gmt_offset']
				&& self::has_settings_error( $utc_offset['settingsErrors'] ?? array(), 'general', 'settings_updated', 'success' )
				&& ! self::has_settings_error( $utc_offset['settingsErrors'] ?? array(), 'general', 'settings_updated', 'error' )
				&& self::nonce_event_seen( $utc_offset, 'general-options', 1 )
				&& false === \has_filter( 'sanitize_email', $sanitize_email_filter )
				&& false === \has_filter( 'is_email', $email_filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'general options submission maps UTC offsets to gmt_offset and records the default success settings error',
			array(
				'result' => self::summarize_dispatch_result( $utc_offset ),
				'values' => $utc_values,
				'capEvents' => array_slice( $cap_events, 0, 8 ),
			)
		);

		return $ctx->result(
			'admin-options-submission.general-date-timezone-branches',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_new_admin_email_pending_change( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$token          = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 6, 12 ) ) );
		$token          = '' === $token ? 'cfz' . substr( md5( (string) $ctx->seed() ), 0, 8 ) : $token;
		$current_email  = 'current-admin-' . $token . '@example.com';
		$pending_email  = 'pending-admin-' . $token . '@example.com';
		$invalid_email  = 'not-an-email-' . $token;
		$site_title     = 'Admin Email ' . $token;
		$site_url       = 'https://example.com/component-fuzz-' . $token;
		$user_login     = 'cfz_admin_email_' . $token;
		$user_email     = $user_login . '@example.com';
		$mail_events    = array();
		$content_events = array();
		$subject_events = array();
		$cap_events      = array();
		$cap_filter      = self::install_cap_filter( array( 'manage_options' ), $cap_events );
		$accepted_emails = array_fill_keys( array( $current_email, $pending_email, $user_email ), true );
		$email_filter    = static function ( $is_email, string $email ) use ( $accepted_emails ) {
			return isset( $accepted_emails[ $email ] ) ? $email : $is_email;
		};
		$sanitize_email_filter = static function ( string $sanitized, string $email ) use ( $accepted_emails ): string {
			return isset( $accepted_emails[ $email ] ) ? $email : $sanitized;
		};
		$pre_mail_filter = static function ( $pre, array $atts ) use ( &$mail_events ) {
			unset( $pre );
			$mail_events[] = $atts;
			return true;
		};
		$content_filter = static function ( string $content, array $new_admin_email ) use ( &$content_events ): string {
			$content_events[] = $new_admin_email;
			return $content;
		};
		$subject_filter = static function ( string $subject ) use ( &$subject_events ): string {
			$subject_events[] = $subject;
			return '[cfz] ' . $subject;
		};
		$added_add_hook = false;
		$added_update_hook = false;

		\add_filter( 'is_email', $email_filter, 10, 2 );
		\add_filter( 'sanitize_email', $sanitize_email_filter, 10, 2 );
		\add_filter( 'pre_wp_mail', $pre_mail_filter, 10, 2 );
		\add_filter( 'new_admin_email_content', $content_filter, 10, 2 );
		\add_filter( 'new_admin_email_subject', $subject_filter, 10 );

		if ( false === \has_action( 'add_option_new_admin_email', 'update_option_new_admin_email' ) ) {
			\add_action( 'add_option_new_admin_email', 'update_option_new_admin_email', 10, 2 );
			$added_add_hook = true;
		}
		if ( false === \has_action( 'update_option_new_admin_email', 'update_option_new_admin_email' ) ) {
			\add_action( 'update_option_new_admin_email', 'update_option_new_admin_email', 10, 2 );
			$added_update_hook = true;
		}

		try {
			$user = self::prepare_admin_email_runtime( $current_email, $site_title, $site_url, $user_login );
			$valid = self::dispatch_options_update(
				array(
					'post'    => self::general_admin_email_post( $pending_email, $site_title, $site_url ),
					'referer' => 'http://example.test/wp-admin/options-general.php',
				)
			);
			$valid_adminhash = \get_option( 'adminhash', '__missing__' );
			$valid_mail      = $mail_events[0] ?? null;
			$valid_message   = is_array( $valid_mail ) ? (string) ( $valid_mail['message'] ?? '' ) : '';
			$valid_subject   = is_array( $valid_mail ) ? (string) ( $valid_mail['subject'] ?? '' ) : '';

			self::collect_failure(
				$failures,
				'completed' === ( $valid['status'] ?? null )
					&& self::redirect_has_settings_updated( $valid['redirect']['location'] ?? '' )
					&& self::nonce_event_seen( $valid, 'general-options', 1 )
					&& self::has_settings_error( $valid['settingsErrors'] ?? array(), 'general', 'settings_updated', 'success' )
					&& $current_email === \get_option( 'admin_email' )
					&& $pending_email === \get_option( 'new_admin_email' )
					&& is_array( $valid_adminhash )
					&& $pending_email === ( $valid_adminhash['newemail'] ?? null )
					&& is_string( $valid_adminhash['hash'] ?? null )
					&& 1 === preg_match( '/^[a-f0-9]{32}$/', (string) ( $valid_adminhash['hash'] ?? '' ) )
					&& in_array( 'new_admin_email', $valid['updatedOptions'] ?? array(), true )
					&& 1 === count( $mail_events )
					&& is_array( $valid_mail )
					&& $pending_email === ( $valid_mail['to'] ?? null )
					&& str_starts_with( $valid_subject, '[cfz] ' )
					&& str_contains( $subject_events[0] ?? '', $site_title )
					&& isset( $content_events[0]['hash'], $content_events[0]['newemail'] )
					&& ( $valid_adminhash['hash'] ?? null ) === $content_events[0]['hash']
					&& $pending_email === $content_events[0]['newemail']
					&& str_contains( $valid_message, $pending_email )
					&& str_contains( $valid_message, (string) $user->user_login )
					&& str_contains( $valid_message, 'options.php?adminhash=' . (string) ( $valid_adminhash['hash'] ?? '' ) )
					&& str_contains( $valid_message, (string) ( $valid_adminhash['hash'] ?? '' ) )
					&& ! str_contains( $valid_message, '###' )
					&& self::capability_event_seen( $cap_events, 'manage_options' ),
				'changed General Settings new_admin_email stores pending adminhash and sends one confirmation email without changing admin_email',
				array(
					'result'       => self::summarize_dispatch_result( $valid ),
					'adminhash'    => $valid_adminhash,
					'mailEvents'   => array_slice( $mail_events, 0, 3 ),
					'contentEvents' => $content_events,
					'subjectEvents' => $subject_events,
					'capEvents'    => array_slice( $cap_events, 0, 8 ),
				)
			);

			$mail_events = array();
			$content_events = array();
			$subject_events = array();
			self::prepare_admin_email_runtime( $current_email, $site_title, $site_url, $user_login );
			$same_current = self::dispatch_options_update(
				array(
					'post'    => self::general_admin_email_post( $current_email, $site_title, $site_url ),
					'referer' => 'http://example.test/wp-admin/options-general.php',
				)
			);
			$same_adminhash = \get_option( 'adminhash', '__missing__' );

			self::collect_failure(
				$failures,
				'completed' === ( $same_current['status'] ?? null )
					&& self::nonce_event_seen( $same_current, 'general-options', 1 )
					&& self::has_settings_error( $same_current['settingsErrors'] ?? array(), 'general', 'settings_updated', 'success' )
					&& $current_email === \get_option( 'admin_email' )
					&& $current_email === \get_option( 'new_admin_email' )
					&& '__missing__' === $same_adminhash
					&& array() === $mail_events
					&& array() === $content_events
					&& array() === $subject_events,
				'same-current new_admin_email persists the submitted option but does not create adminhash or send confirmation mail',
				array(
					'result'    => self::summarize_dispatch_result( $same_current ),
					'adminhash' => $same_adminhash,
					'mailEvents' => $mail_events,
				)
			);

			$mail_events = array();
			$content_events = array();
			$subject_events = array();
			self::prepare_admin_email_runtime( $current_email, $site_title, $site_url, $user_login );
			$invalid = self::dispatch_options_update(
				array(
					'post'    => self::general_admin_email_post( $invalid_email, $site_title, $site_url ),
					'referer' => 'http://example.test/wp-admin/options-general.php',
				)
			);
			$invalid_adminhash = \get_option( 'adminhash', '__missing__' );

			self::collect_failure(
				$failures,
				'completed' === ( $invalid['status'] ?? null )
					&& self::nonce_event_seen( $invalid, 'general-options', 1 )
					&& self::has_settings_error( $invalid['settingsErrors'] ?? array(), 'new_admin_email', 'invalid_new_admin_email', 'error' )
					&& $current_email === \get_option( 'admin_email' )
					&& '__missing__' === $invalid_adminhash
					&& array() === $mail_events
					&& array() === $content_events
					&& array() === $subject_events,
				'invalid new_admin_email records the sanitization error but does not create adminhash or send confirmation mail',
				array(
					'result'    => self::summarize_dispatch_result( $invalid ),
					'adminhash' => $invalid_adminhash,
					'mailEvents' => $mail_events,
				)
			);
		} finally {
			if ( $added_update_hook ) {
				\remove_action( 'update_option_new_admin_email', 'update_option_new_admin_email', 10 );
			}
			if ( $added_add_hook ) {
				\remove_action( 'add_option_new_admin_email', 'update_option_new_admin_email', 10 );
			}
			\remove_filter( 'new_admin_email_subject', $subject_filter, 10 );
			\remove_filter( 'new_admin_email_content', $content_filter, 10 );
			\remove_filter( 'pre_wp_mail', $pre_mail_filter, 10 );
			\remove_filter( 'sanitize_email', $sanitize_email_filter, 10 );
			\remove_filter( 'is_email', $email_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_wp_mail', $pre_mail_filter )
				&& false === \has_filter( 'new_admin_email_content', $content_filter )
				&& false === \has_filter( 'new_admin_email_subject', $subject_filter )
				&& false === \has_filter( 'sanitize_email', $sanitize_email_filter )
				&& false === \has_filter( 'is_email', $email_filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter )
				&& ( ! $added_add_hook || false === \has_action( 'add_option_new_admin_email', 'update_option_new_admin_email' ) )
				&& ( ! $added_update_hook || false === \has_action( 'update_option_new_admin_email', 'update_option_new_admin_email' ) ),
			'new_admin_email pending-change filters and locally installed dynamic option hooks are removed after dispatches',
			array(
				'preMail'    => \has_filter( 'pre_wp_mail', $pre_mail_filter ),
				'content'    => \has_filter( 'new_admin_email_content', $content_filter ),
				'subject'    => \has_filter( 'new_admin_email_subject', $subject_filter ),
				'sanitize'   => \has_filter( 'sanitize_email', $sanitize_email_filter ),
				'isEmail'    => \has_filter( 'is_email', $email_filter ),
				'capability' => \has_filter( 'user_has_cap', $cap_filter ),
				'addHook'    => \has_action( 'add_option_new_admin_email', 'update_option_new_admin_email' ),
				'updateHook' => \has_action( 'update_option_new_admin_email', 'update_option_new_admin_email' ),
			)
		);

		return $ctx->result(
			'admin-options-submission.new-admin-email-pending-change',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_legacy_options_page_submission( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();
		$first    = self::id( $ctx->fork( 'first' ), 'cfz_legacy_first', 36 );
		$second   = self::id( $ctx->fork( 'second' ), 'cfz_legacy_second', 36 );
		$missing  = self::id( $ctx->fork( 'missing' ), 'cfz_legacy_missing', 36 );
		$intruder = self::id( $ctx->fork( 'intruder' ), 'cfz_legacy_intruder', 36 );
		$cap_events = array();
		$cap_filter = self::install_cap_filter( array( 'manage_options' ), $cap_events );

		try {
			$result = self::dispatch_options_update(
				array(
					'post'    => array(
						'action'       => 'update',
						'_wpnonce'     => \wp_create_nonce( 'update-options' ),
						'page_options' => implode( ',', array( $first, ' ' . $second . ' ', $missing ) ),
						$first         => "  Alpha <b>" . $ctx->identifier( 3, 8 ) . "</b>  ",
						$second        => array( ' one ', 'two\\three' ),
						$intruder      => 'not-listed',
					),
					'referer' => 'http://example.test/wp-admin/options.php',
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			'completed' === ( $result['status'] ?? null )
				&& self::redirect_has_settings_updated( $result['redirect']['location'] ?? '' )
				&& str_starts_with( \get_option( $first ), 'Alpha <b>' )
				&& array( ' one ', 'two\\three' ) === \get_option( $second )
				&& '' === \get_option( $missing, '__not-null__' )
				&& '__missing__' === \get_option( $intruder, '__missing__' )
				&& array( $first, $second, $missing ) === ( $result['updatedOptions'] ?? null )
				&& self::has_settings_error( $result['settingsErrors'] ?? array(), 'general', 'settings_updated', 'success' )
				&& self::nonce_event_seen( $result, 'update-options', 1 )
				&& self::capability_event_seen( $cap_events, 'manage_options' )
				&& false === \has_filter( 'user_has_cap', $cap_filter ),
			'legacy options page submission updates only page_options, trims scalar values, preserves array values after wp_unslash, and stores success transient',
			array(
				'result'    => self::summarize_dispatch_result( $result ),
				'stored'    => array(
					'first'   => \get_option( $first ),
					'second'  => \get_option( $second ),
					'missing' => \get_option( $missing, '__not-null__' ),
					'intruder'=> \get_option( $intruder, '__missing__' ),
				),
				'capEvents' => array_slice( $cap_events, 0, 8 ),
			)
		);

		return $ctx->result(
			'admin-options-submission.legacy-options-page',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 3 ) )
		);
	}

	private static function check_failure_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_runtime();
		$unknown_group = self::id( $ctx->fork( 'unknown' ), 'cfz_unknown_group', 36 );
		$unknown_option = self::id( $ctx->fork( 'unknown-option' ), 'cfz_unknown_option', 36 );
		$unknown_cap_events = array();
		$unknown_cap_filter = self::install_cap_filter( array( 'manage_options' ), $unknown_cap_events );
		try {
			$unknown = self::dispatch_options_update(
				array(
					'post'    => array(
						'action'      => 'update',
						'option_page' => $unknown_group,
						'_wpnonce'    => \wp_create_nonce( $unknown_group . '-options' ),
						$unknown_option => 'should not persist',
					),
					'referer' => 'http://example.test/wp-admin/options-general.php?page=unknown',
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $unknown_cap_filter, 10 );
		}
		$unknown_stored = \get_option( $unknown_option, '__missing__' );

		self::reset_runtime();
		$denied_group = self::id( $ctx->fork( 'denied' ), 'cfz_denied_group', 36 );
		$denied_option = self::id( $ctx->fork( 'denied-option' ), 'cfz_denied_option', 36 );
		$denied_cap_filter = static function ( string $capability ): string {
			unset( $capability );
			return 'manage_component_fuzz_denied';
		};
		\register_setting( $denied_group, $denied_option, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		\add_filter( 'allowed_options', 'option_update_filter' );
		\add_filter( "option_page_capability_{$denied_group}", $denied_cap_filter );
		try {
			$denied = self::dispatch_options_update(
				array(
					'post'    => array(
						'action'      => 'update',
						'option_page' => $denied_group,
						'_wpnonce'    => \wp_create_nonce( $denied_group . '-options' ),
						$denied_option => 'denied',
					),
					'referer' => 'http://example.test/wp-admin/options-general.php?page=denied',
				)
			);
		} finally {
			\remove_filter( "option_page_capability_{$denied_group}", $denied_cap_filter );
			\remove_filter( 'allowed_options', 'option_update_filter' );
			\unregister_setting( $denied_group, $denied_option );
		}
		$denied_stored = \get_option( $denied_option, '__missing__' );

		self::reset_runtime();
		$nonce_group = self::id( $ctx->fork( 'nonce' ), 'cfz_nonce_group', 36 );
		$nonce_option = self::id( $ctx->fork( 'nonce-option' ), 'cfz_nonce_option', 36 );
		$nonce_cap_events = array();
		$nonce_cap_filter = self::install_cap_filter( array( 'manage_options' ), $nonce_cap_events );
		\register_setting( $nonce_group, $nonce_option, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		\add_filter( 'allowed_options', 'option_update_filter' );
		try {
			$bad_nonce = self::dispatch_options_update(
				array(
					'post'    => array(
						'action'      => 'update',
						'option_page' => $nonce_group,
						'_wpnonce'    => 'not-a-valid-nonce-' . $ctx->identifier( 3, 8 ),
						$nonce_option => 'bad nonce',
					),
					'referer' => 'http://example.test/wp-admin/options-general.php?page=nonce',
				)
			);
		} finally {
			\remove_filter( 'allowed_options', 'option_update_filter' );
			\remove_filter( 'user_has_cap', $nonce_cap_filter, 10 );
			\unregister_setting( $nonce_group, $nonce_option );
		}
		$nonce_stored = \get_option( $nonce_option, '__missing__' );

		self::collect_failure(
			$failures,
			'died' === ( $unknown['status'] ?? null )
				&& str_contains( (string) ( $unknown['die']['message'] ?? '' ), 'options page is not in the allowed options list' )
				&& self::nonce_event_seen( $unknown, $unknown_group . '-options', 1 )
				&& '__missing__' === $unknown_stored
				&& null === ( $unknown['redirect'] ?? null )
				&& false === \has_filter( 'user_has_cap', $unknown_cap_filter ),
			'unknown option_page dies after nonce validation and before mutating submitted options',
			array(
				'result' => self::summarize_dispatch_result( $unknown ),
				'stored' => $unknown_stored,
			)
		);

		self::collect_failure(
			$failures,
			'died' === ( $denied['status'] ?? null )
				&& str_contains( (string) ( $denied['die']['message'] ?? '' ), 'higher level of permission' )
				&& array() === ( $denied['nonceEvents'] ?? array() )
				&& '__missing__' === $denied_stored
				&& null === ( $denied['redirect'] ?? null )
				&& false === \has_filter( "option_page_capability_{$denied_group}", $denied_cap_filter )
				&& false === \has_filter( 'allowed_options', 'option_update_filter' ),
			'denied option_page capability dies before nonce validation and option mutation',
			array(
				'result' => self::summarize_dispatch_result( $denied ),
				'stored' => $denied_stored,
			)
		);

		self::collect_failure(
			$failures,
			'died' === ( $bad_nonce['status'] ?? null )
				&& self::nonce_event_seen( $bad_nonce, $nonce_group . '-options', false )
				&& '__missing__' === $nonce_stored
				&& null === ( $bad_nonce['redirect'] ?? null )
				&& false === \has_filter( 'user_has_cap', $nonce_cap_filter )
				&& false === \has_filter( 'allowed_options', 'option_update_filter' ),
			'invalid nonce dies before allowed-options lookup and option mutation',
			array(
				'result' => self::summarize_dispatch_result( $bad_nonce ),
				'stored' => $nonce_stored,
				'capEvents' => array_slice( $nonce_cap_events, 0, 8 ),
			)
		);

		return $ctx->result(
			'admin-options-submission.failure-paths',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function dispatch_options_update( array $request ): array {
		$post    = is_array( $request['post'] ?? null ) ? $request['post'] : array();
		$get     = is_array( $request['get'] ?? null ) ? $request['get'] : array();
		$referer = (string) ( $request['referer'] ?? 'http://example.test/wp-admin/options-general.php' );
		$nonce_events = array();
		$redirect     = null;
		$updated      = array();
		$slashed_get  = \wp_slash( $get );
		$slashed_post = \wp_slash( $post );

		$_GET     = $slashed_get;
		$_POST    = $slashed_post;
		$_REQUEST = array_merge( $slashed_get, $slashed_post );
		$_SERVER  = array_merge(
			$_SERVER,
			array(
				'REQUEST_METHOD' => 'POST',
				'REQUEST_URI'    => '/wp-admin/options.php',
				'PHP_SELF'       => '/wp-admin/options.php',
				'SCRIPT_NAME'    => '/wp-admin/options.php',
				'HTTP_HOST'      => 'example.test',
				'HTTP_REFERER'   => $referer,
			)
		);

		$nonce_action = static function ( string $action, $result ) use ( &$nonce_events ): void {
			$nonce_events[] = array(
				'action' => $action,
				'result' => $result,
			);
		};
		$redirect_filter = static function ( $location, int $status ) use ( &$redirect ) {
			$redirect = array(
				'location' => (string) $location,
				'status'   => $status,
			);
			return false;
		};
		$die_filter = static function (): callable {
			return static function ( $message = '', $title = '', $args = array() ): void {
				throw new AdminOptionsSubmissionSurface_DieCaptured( $message, $title, $args );
			};
		};

		\add_action( 'check_admin_referer', $nonce_action, 10, 2 );
		\add_filter( 'wp_redirect', $redirect_filter, 10, 2 );
		\add_filter( 'wp_die_handler', $die_filter, PHP_INT_MAX );

		$result = array(
			'status'      => 'completed',
			'redirect'    => null,
			'redirectResult' => null,
			'nonceEvents' => array(),
			'allowedOptions' => array(),
			'updatedOptions' => array(),
			'settingsErrors' => array(),
			'settingsTransient' => false,
			'die'         => null,
		);

		try {
			$action      = ! empty( $_REQUEST['action'] ) ? \sanitize_text_field( $_REQUEST['action'] ) : '';
			$option_page = ! empty( $_REQUEST['option_page'] ) ? \sanitize_text_field( $_REQUEST['option_page'] ) : '';
			if ( empty( $option_page ) ) {
				$option_page = 'options';
			}

			$capability = \apply_filters( "option_page_capability_{$option_page}", 'manage_options' );
			if ( ! \current_user_can( $capability ) ) {
				\wp_die(
					'<h1>' . __( 'You need a higher level of permission.' ) . '</h1>' .
					'<p>' . __( 'Sorry, you are not allowed to manage options for this site.' ) . '</p>',
					403
				);
			}

			$allowed_options = self::allowed_options_for_submission();
			$result['allowedOptions'] = $allowed_options;

			if ( 'update' === $action ) {
				if ( 'options' === $option_page && ! isset( $_POST['option_page'] ) ) {
					$unregistered = true;
					\check_admin_referer( 'update-options' );
				} else {
					$unregistered = false;
					\check_admin_referer( $option_page . '-options' );
				}

				if ( ! isset( $allowed_options[ $option_page ] ) ) {
					\wp_die(
						sprintf(
							__( '<strong>Error:</strong> The %s options page is not in the allowed options list.' ),
							'<code>' . esc_html( $option_page ) . '</code>'
						)
					);
				}

				if ( 'options' === $option_page ) {
					$options = isset( $_POST['page_options'] ) ? explode( ',', \wp_unslash( $_POST['page_options'] ) ) : null;
				} else {
					$options = $allowed_options[ $option_page ];
				}

				if ( 'general' === $option_page ) {
					self::normalize_general_post_values();
				}

				if ( $options ) {
					$user_language_old = \get_user_locale();

					foreach ( $options as $option ) {
						if ( $unregistered ) {
							_deprecated_argument(
								'options.php',
								'2.7.0',
								sprintf(
									__( 'The %1$s setting is unregistered. See %2$s.' ),
									'<code>' . esc_html( (string) $option ) . '</code>',
									'https://developer.wordpress.org/plugins/settings/settings-api/'
								)
							);
						}

						$option = trim( (string) $option );
						$value  = null;
						if ( isset( $_POST[ $option ] ) ) {
							$value = $_POST[ $option ];
							if ( ! is_array( $value ) ) {
								$value = trim( (string) $value );
							}
							$value = \wp_unslash( $value );
						}

						\update_option( $option, $value );
						$updated[] = $option;
					}

					unset( $GLOBALS['locale'] );
					$user_language_new = \get_user_locale();
					if ( $user_language_old !== $user_language_new ) {
						\load_default_textdomain( $user_language_new );
					}
				} else {
					\add_settings_error( 'general', 'settings_updated', __( 'Settings save failed.' ), 'error' );
				}

				if ( ! count( \get_settings_errors() ) ) {
					\add_settings_error( 'general', 'settings_updated', __( 'Settings saved.' ), 'success' );
				}

				\set_transient( 'settings_errors', \get_settings_errors(), 30 );
				$goback = \add_query_arg( 'settings-updated', 'true', \wp_get_referer() );
				$result['redirectResult'] = \wp_redirect( $goback );
			}
		} catch ( AdminOptionsSubmissionSurface_DieCaptured $e ) {
			$result['status'] = 'died';
			$result['die']    = $e->payload;
		} finally {
			$result['redirect']          = $redirect;
			$result['nonceEvents']       = $nonce_events;
			$result['updatedOptions']    = $updated;
			$result['settingsErrors']    = \get_settings_errors();
			$result['settingsTransient'] = \get_transient( 'settings_errors' );

			\remove_action( 'check_admin_referer', $nonce_action, 10 );
			\remove_filter( 'wp_redirect', $redirect_filter, 10 );
			\remove_filter( 'wp_die_handler', $die_filter, PHP_INT_MAX );
		}

		return $result;
	}

	private static function allowed_options_for_submission(): array {
		$allowed_options = array(
			'general'    => array(
				'blogname',
				'blogdescription',
				'site_icon',
				'gmt_offset',
				'date_format',
				'time_format',
				'start_of_week',
				'timezone_string',
				'WPLANG',
				'new_admin_email',
				'siteurl',
				'home',
				'users_can_register',
				'default_role',
			),
			'discussion' => array(
				'default_pingback_flag',
				'default_ping_status',
				'default_comment_status',
				'comments_notify',
				'moderation_notify',
				'comment_moderation',
				'require_name_email',
				'comment_previously_approved',
				'comment_max_links',
				'moderation_keys',
				'disallowed_keys',
				'show_avatars',
				'avatar_rating',
				'avatar_default',
				'close_comments_for_old_posts',
				'close_comments_days_old',
				'thread_comments',
				'thread_comments_depth',
				'page_comments',
				'comments_per_page',
				'default_comments_page',
				'comment_order',
				'comment_registration',
				'show_comments_cookies_opt_in',
				'wp_notes_notify',
			),
			'media'      => array(
				'thumbnail_size_w',
				'thumbnail_size_h',
				'thumbnail_crop',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
				'image_default_size',
				'image_default_align',
				'image_default_link_type',
				'uploads_use_yearmonth_folders',
			),
			'reading'    => array(
				'posts_per_page',
				'posts_per_rss',
				'rss_use_excerpt',
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				'blog_public',
			),
			'writing'    => array(
				'default_category',
				'default_email_category',
				'default_link_category',
				'default_post_format',
				'mailserver_url',
				'mailserver_port',
				'mailserver_login',
				'mailserver_pass',
			),
			'misc'       => array(),
			'options'    => array(),
			'privacy'    => array(),
		);

		$allowed_options = \apply_filters_deprecated(
			'whitelist_options',
			array( $allowed_options ),
			'5.5.0',
			'allowed_options',
			__( 'Please consider writing more inclusive code.' )
		);

		return \apply_filters( 'allowed_options', $allowed_options );
	}

	private static function general_post_defaults(): array {
		return array(
			'blogname'           => 'Component Fuzz',
			'blogdescription'    => 'Component Fuzz Settings',
			'site_icon'          => '0',
			'gmt_offset'         => '0',
			'date_format'        => 'Y-m-d',
			'time_format'        => 'H:i',
			'start_of_week'      => '1',
			'timezone_string'    => '',
			'WPLANG'             => '',
			'new_admin_email'    => 'admin@example.com',
			'siteurl'            => 'http://example.test',
			'home'               => 'http://example.test',
			'users_can_register' => '0',
			'default_role'       => 'subscriber',
		);
	}

	private static function general_admin_email_post( string $new_admin_email, string $site_title, string $site_url ): array {
		return array_merge(
			self::general_post_defaults(),
			array(
				'action'          => 'update',
				'option_page'     => 'general',
				'_wpnonce'        => \wp_create_nonce( 'general-options' ),
				'blogname'        => $site_title,
				'blogdescription' => 'Admin email pending change',
				'gmt_offset'      => '1',
				'new_admin_email' => $new_admin_email,
				'siteurl'         => $site_url,
				'home'            => $site_url,
				'timezone_string' => 'Europe/Madrid',
			)
		);
	}

	private static function normalize_general_post_values(): void {
		if ( ! empty( $_POST['date_format'] ) && isset( $_POST['date_format_custom'] )
			&& '\c\u\s\t\o\m' === \wp_unslash( $_POST['date_format'] )
		) {
			$_POST['date_format'] = $_POST['date_format_custom'];
		}

		if ( ! empty( $_POST['time_format'] ) && isset( $_POST['time_format_custom'] )
			&& '\c\u\s\t\o\m' === \wp_unslash( $_POST['time_format'] )
		) {
			$_POST['time_format'] = $_POST['time_format_custom'];
		}

		if ( ! empty( $_POST['timezone_string'] ) && preg_match( '/^UTC[+-]/', (string) $_POST['timezone_string'] ) ) {
			$_POST['gmt_offset']      = $_POST['timezone_string'];
			$_POST['gmt_offset']      = preg_replace( '/UTC\+?/', '', (string) $_POST['gmt_offset'] );
			$_POST['timezone_string'] = '';
		} elseif ( isset( $_POST['timezone_string'] ) && ! in_array( $_POST['timezone_string'], timezone_identifiers_list( \DateTimeZone::ALL_WITH_BC ), true ) ) {
			$current_timezone_string = \get_option( 'timezone_string' );

			if ( ! empty( $current_timezone_string ) ) {
				$_POST['timezone_string'] = $current_timezone_string;
			} else {
				$_POST['gmt_offset']      = \get_option( 'gmt_offset' );
				$_POST['timezone_string'] = '';
			}

			\add_settings_error(
				'general',
				'settings_updated',
				__( 'The timezone you have entered is not valid. Please select a valid timezone.' ),
				'error'
			);
		}
	}

	private static function install_cap_filter( array $granted_caps, array &$events ): callable {
		$grant_map = array_fill_keys( $granted_caps, true );
		$filter = static function ( array $allcaps, array $caps, array $args = array(), $user = null ) use ( $grant_map, &$events ): array {
			$events[] = array(
				'type' => 'user-has-cap',
				'caps' => $caps,
				'args' => $args,
				'userId' => is_object( $user ) ? (int) ( $user->ID ?? 0 ) : null,
			);
			foreach ( $grant_map as $cap => $grant ) {
				$allcaps[ $cap ] = $grant;
			}
			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, 10, 4 );
		return $filter;
	}

	private static function reset_runtime( array $options = array() ): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		$defaults = array(
			'admin_email'         => 'admin@example.test',
			'blog_charset'        => 'UTF-8',
			'blogdescription'     => 'Component Fuzz Settings',
			'blogname'            => 'Component Fuzz',
			'blog_public'         => '0',
			'date_format'         => 'Y-m-d',
			'default_role'        => 'subscriber',
			'gmt_offset'          => '0',
			'home'                => 'http://example.test',
			'html_type'           => 'text/html',
			'siteurl'             => 'http://example.test',
			'start_of_week'       => '1',
			'time_format'         => 'H:i',
			'timezone_string'     => '',
			'users_can_register'  => '0',
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( array_merge( $defaults, $options ) );
		}

		\wp_cache_flush();

		$GLOBALS['new_allowed_options']    = array();
		$GLOBALS['new_whitelist_options']  = &$GLOBALS['new_allowed_options'];
		$GLOBALS['current_user']           = new \WP_User( 0 );
		$GLOBALS['wp_registered_settings'] = array();
		$GLOBALS['wp_settings_errors']     = array();
		unset( $GLOBALS['locale'] );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_SERVER  = array_merge(
			$_SERVER,
			array(
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => '/wp-admin/options.php',
				'PHP_SELF'       => '/wp-admin/options.php',
				'SCRIPT_NAME'    => '/wp-admin/options.php',
				'HTTP_HOST'      => 'example.test',
				'HTTP_REFERER'   => 'http://example.test/wp-admin/options-general.php',
			)
		);
	}

	private static function prepare_admin_email_runtime( string $admin_email, string $site_title, string $site_url, string $user_login ): \WP_User {
		self::reset_runtime(
			array(
				'admin_email' => $admin_email,
				'blogname'    => $site_title,
				'home'        => $site_url,
				'siteurl'     => $site_url,
			)
		);

		$user_id = \wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_pass'    => 'component-fuzz-admin-email-pass',
				'user_email'   => $user_login . '@example.com',
				'user_nicename' => $user_login,
				'display_name' => 'Component Fuzz Admin Email',
				'locale'       => '',
			)
		);
		if ( \is_wp_error( $user_id ) ) {
			throw new \RuntimeException( 'Unable to seed admin email current user: ' . $user_id->get_error_message() );
		}

		\wp_set_current_user( (int) $user_id );
		return \wp_get_current_user();
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'_GET',
					'_POST',
					'_REQUEST',
					'_SERVER',
					'current_user',
					'locale',
					'new_allowed_options',
					'new_whitelist_options',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_registered_settings',
					'wp_settings_errors',
				)
			),
			'options' => self::option_store_snapshot(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		self::restore_globals( $snapshot['globals'] );

		if ( array_key_exists( 'new_allowed_options', $GLOBALS ) ) {
			$GLOBALS['new_whitelist_options'] = &$GLOBALS['new_allowed_options'];
		}

		\wp_cache_flush();
	}

	private static function state_matches( array $snapshot ): bool {
		if ( self::option_store_snapshot() !== $snapshot['options'] ) {
			return false;
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] != $entry['value'] ) {
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
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function option_store_snapshot(): array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return array();
	}

	private static function id( \ComponentFuzz\FuzzContext $ctx, string $prefix, int $max_len ): string {
		$suffix = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '_', $ctx->identifier( 4, 14 ) ) );
		$id     = $prefix . '_' . $suffix;

		return substr( $id, 0, $max_len );
	}

	private static function redirect_has_settings_updated( string $location ): bool {
		return str_contains( $location, 'settings-updated=true' );
	}

	private static function has_settings_error( array $errors, string $setting, string $code, string $type ): bool {
		foreach ( $errors as $error ) {
			if (
				is_array( $error )
				&& $setting === ( $error['setting'] ?? null )
				&& $code === ( $error['code'] ?? null )
				&& $type === ( $error['type'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function nonce_event_seen( array $result, string $action, $expected_result ): bool {
		foreach ( $result['nonceEvents'] ?? array() as $event ) {
			if ( is_array( $event ) && $action === ( $event['action'] ?? null ) && $expected_result === ( $event['result'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function capability_event_seen( array $events, string $capability ): bool {
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			if ( in_array( $capability, $event['caps'] ?? array(), true ) ) {
				return true;
			}
			if ( $capability === ( $event['capability'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function summarize_dispatch_result( array $result ): array {
		if ( isset( $result['allowedOptions'] ) && is_array( $result['allowedOptions'] ) ) {
			$result['allowedOptions'] = array_map( 'array_values', $result['allowedOptions'] );
		}

		return $result;
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 180 ) {
			return substr( $value, 0, 180 ) . '...';
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 8, true ) as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			if ( count( $value ) > 8 ) {
				$out['__truncated__'] = count( $value ) - 8;
			}
			return $out;
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
}

final class AdminOptionsSubmissionSurface_DieCaptured extends \RuntimeException {
	public array $payload;

	public function __construct( $message, $title, $args ) {
		$this->payload = array(
			'message' => is_scalar( $message ) ? (string) $message : get_debug_type( $message ),
			'title'   => is_scalar( $title ) ? (string) $title : get_debug_type( $title ),
			'args'    => $args,
		);

		parent::__construct( $this->payload['message'] );
	}
}
