<?php
namespace ComponentFuzz\Surfaces;

final class ErrorProtectionSurface {
	public const NAME = 'error-protection';

	private const SAMPLE_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'error-protection.bootstrap-apis-available',
					'Required WordPress error protection APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime( $ctx );

			$rows[] = self::check_paused_extension_source_and_storage( $ctx->fork( 'paused-extension-source' ) );
			$rows[] = self::check_recovery_key_validation( $ctx->fork( 'recovery-key-validation' ) );
			$rows[] = self::check_recovery_cookie_validation( $ctx->fork( 'recovery-cookie-validation' ) );
			$rows[] = self::check_recovery_link_generation( $ctx->fork( 'recovery-link-generation' ) );
			$rows[] = self::check_recovery_email_payload( $ctx->fork( 'recovery-email-payload' ) );
			$rows[] = self::check_fatal_error_handler_oracles( $ctx->fork( 'fatal-error-handler' ) );
			$rows[] = self::check_handler_and_endpoint_gates( $ctx->fork( 'handler-endpoint-gates' ) );
			$rows[] = $ctx->skip(
				'error-protection.process-control-paths-explicitly-skipped',
				'Real fatal/shutdown dispatch, recovery-mode redirects, process exits, and site-health loopback checks are intentionally not invoked by this pure PHP surface.'
			);
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'error-protection.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'error-protection.global-state-restored',
			self::state_matches( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot['globals'] ) )
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Error',
				'WP_Fatal_Error_Handler',
				'WP_Paused_Extensions_Storage',
				'WP_Recovery_Mode',
				'WP_Recovery_Mode_Cookie_Service',
				'WP_Recovery_Mode_Email_Service',
				'WP_Recovery_Mode_Key_Service',
				'WP_Recovery_Mode_Link_Service',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_query_arg',
				'delete_option',
				'get_option',
				'has_filter',
				'home_url',
				'human_time_diff',
				'is_protected_endpoint',
				'is_wp_error',
				'remove_filter',
				'update_option',
				'wp_cache_delete',
				'wp_fast_hash',
				'wp_get_extension_error_description',
				'wp_is_fatal_error_handler_enabled',
				'wp_login_url',
				'wp_mail',
				'wp_normalize_path',
				'wp_paused_plugins',
				'wp_paused_themes',
				'wp_parse_url',
				'wp_recovery_mode',
				'wp_strip_all_tags',
				'wp_verify_fast_hash',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach (
			array(
				'AUTH_KEY',
				'AUTH_SALT',
				'COOKIE_DOMAIN',
				'COOKIEPATH',
				'DAY_IN_SECONDS',
				'RECOVERY_MODE_COOKIE',
				'SITECOOKIEPATH',
				'WEEK_IN_SECONDS',
				'WP_CONTENT_DIR',
				'WP_PLUGIN_DIR',
				'YEAR_IN_SECONDS',
			) as $constant
		) {
			if ( ! defined( $constant ) ) {
				$missing[] = "constant {$constant}";
			}
		}

		return $missing;
	}

	private static function check_paused_extension_source_and_storage( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_theme_directories;

		$failures      = array();
		$mode_snapshot = self::snapshot_recovery_mode_state();
		$session_id    = 'cf_error_protection_' . self::token( $ctx->fork( 'session' ), 18 );
		$option_name   = $session_id . '_paused_extensions';

		try {
			self::set_recovery_mode_state( true, true, $session_id );
			\delete_option( $option_name );
			\wp_cache_delete( $option_name, 'options' );

			$theme_root = WP_CONTENT_DIR . '/themes';
			if ( ! is_dir( $theme_root ) ) {
				mkdir( $theme_root, 0777, true );
			}
			$wp_theme_directories = array( $theme_root );

			$mode       = \wp_recovery_mode();
			$get_source = new \ReflectionMethod( \WP_Recovery_Mode::class, 'get_extension_for_error' );
			$store      = new \ReflectionMethod( \WP_Recovery_Mode::class, 'store_error' );

			$plugin_slug         = 'plugin-' . self::token( $ctx->fork( 'plugin' ), 10 );
			$windows_plugin_slug = 'windows-' . self::token( $ctx->fork( 'windows-plugin' ), 8 );
			$theme_slug          = 'theme-' . self::token( $ctx->fork( 'theme' ), 10 );

			$plugin_error         = self::extension_error_case( $ctx->fork( 'plugin-error' ), WP_PLUGIN_DIR . '/' . $plugin_slug . '/includes/fatal.php' );
			$windows_plugin_error = self::extension_error_case(
				$ctx->fork( 'windows-plugin-error' ),
				str_replace( '/', '\\', WP_PLUGIN_DIR . '/' . $windows_plugin_slug . '/main.php' )
			);
			$theme_error          = self::extension_error_case( $ctx->fork( 'theme-error' ), $theme_root . '/' . $theme_slug . '/functions.php' );
			$outside_error        = self::extension_error_case( $ctx->fork( 'outside-error' ), WP_CONTENT_DIR . '/uploads/' . $theme_slug . '/not-paused.php' );
			$missing_file_error   = array(
				'type'    => E_ERROR,
				'line'    => 1,
				'message' => 'missing file key',
			);

			$plugin_source         = $get_source->invoke( $mode, $plugin_error );
			$windows_plugin_source = $get_source->invoke( $mode, $windows_plugin_error );
			$theme_source          = $get_source->invoke( $mode, $theme_error );
			$outside_source        = $get_source->invoke( $mode, $outside_error );
			$missing_file_source   = $get_source->invoke( $mode, $missing_file_error );

			$stored_plugin      = $store->invoke( $mode, $plugin_error );
			$after_first_store  = \get_option( $option_name, array() );
			$stored_plugin_same = $store->invoke( $mode, $plugin_error );
			$after_same_store   = \get_option( $option_name, array() );
			$stored_windows     = $store->invoke( $mode, $windows_plugin_error );
			$stored_theme       = $store->invoke( $mode, $theme_error );
			$stored_outside     = $store->invoke( $mode, $outside_error );
			$stored_missing     = $store->invoke( $mode, $missing_file_error );

			$plugin_errors = \wp_paused_plugins()->get_all();
			$theme_errors  = \wp_paused_themes()->get_all();

			self::collect_failure(
				$failures,
				array( 'type' => 'plugin', 'slug' => $plugin_slug ) === $plugin_source
					&& array( 'type' => 'plugin', 'slug' => $windows_plugin_slug ) === $windows_plugin_source
					&& array( 'type' => 'theme', 'slug' => $theme_slug ) === $theme_source
					&& false === $outside_source
					&& false === $missing_file_source,
				'extension source detection normalizes paths to plugin/theme slugs and rejects unrelated files',
				array(
					'pluginSource'        => $plugin_source,
					'windowsPluginSource' => $windows_plugin_source,
					'themeSource'         => $theme_source,
					'outsideSource'       => $outside_source,
					'missingFileSource'   => $missing_file_source,
				)
			);

			self::collect_failure(
				$failures,
				true === $stored_plugin
					&& true === $stored_plugin_same
					&& $after_first_store === $after_same_store
					&& true === $stored_windows
					&& true === $stored_theme
					&& false === $stored_outside
					&& false === $stored_missing
					&& isset( $plugin_errors[ $plugin_slug ], $plugin_errors[ $windows_plugin_slug ], $theme_errors[ $theme_slug ] )
					&& $plugin_error === $plugin_errors[ $plugin_slug ]
					&& $windows_plugin_error === $plugin_errors[ $windows_plugin_slug ]
					&& $theme_error === $theme_errors[ $theme_slug ]
					&& ! isset( $plugin_errors[ $theme_slug ], $theme_errors[ $plugin_slug ] ),
				'store_error records normalized paused-extension keys once, isolates plugin/theme buckets, and fails closed for malformed sources',
				array(
					'optionName'       => $option_name,
					'storedPlugin'     => $stored_plugin,
					'storedPluginSame' => $stored_plugin_same,
					'storedWindows'    => $stored_windows,
					'storedTheme'      => $stored_theme,
					'storedOutside'    => $stored_outside,
					'storedMissing'    => $stored_missing,
					'pluginErrors'     => $plugin_errors,
					'themeErrors'      => $theme_errors,
				)
			);

			$delete_missing = \wp_paused_plugins()->delete( 'missing-' . self::token( $ctx->fork( 'missing-delete' ), 8 ) );
			$delete_plugin  = \wp_paused_plugins()->delete( $plugin_slug );
			$after_delete   = \get_option( $option_name, array() );

			self::collect_failure(
				$failures,
				true === $delete_missing
					&& true === $delete_plugin
					&& ! isset( $after_delete['plugin'][ $plugin_slug ] )
					&& isset( $after_delete['plugin'][ $windows_plugin_slug ], $after_delete['theme'][ $theme_slug ] ),
				'paused-extension deletion is idempotent and scoped to the exact normalized key',
				array(
					'deleteMissing' => $delete_missing,
					'deletePlugin'  => $delete_plugin,
					'afterDelete'   => $after_delete,
				)
			);
		} finally {
			self::restore_recovery_mode_state( $mode_snapshot );
			\delete_option( $option_name );
			\wp_cache_delete( $option_name, 'options' );
		}

		return self::row(
			$ctx,
			'error-protection.paused-extensions.source-normalization-and-storage',
			array() === $failures,
			array(
				'sessionId' => $session_id,
				'failures'  => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_recovery_key_validation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$service  = new \WP_Recovery_Mode_Key_Service();
		$ttl      = 90 + $ctx->int( 0, 3600 );
		$token    = 'token|' . self::token( $ctx->fork( 'token' ), 14 );

		\delete_option( 'recovery_keys' );
		\wp_cache_delete( 'recovery_keys', 'options' );

		$generated_token = $service->generate_recovery_mode_token();
		self::collect_failure(
			$failures,
			22 === strlen( $generated_token ) && ctype_alnum( $generated_token ),
			'generated recovery-mode tokens are 22-character alphanumeric identifiers',
			array( 'generatedToken' => $generated_token )
		);

		$key     = $service->generate_and_store_recovery_mode_key( $token );
		$records = self::recovery_key_records();

		self::collect_failure(
			$failures,
			is_string( $key )
				&& 22 === strlen( $key )
				&& ctype_alnum( $key )
				&& isset( $records[ $token ]['hashed_key'], $records[ $token ]['created_at'] )
				&& str_starts_with( (string) $records[ $token ]['hashed_key'], '$generic$' )
				&& \wp_verify_fast_hash( $key, (string) $records[ $token ]['hashed_key'] ),
			'generated recovery-mode keys are stored as generic hashes under the supplied token',
			array(
				'token'   => $token,
				'key'     => $key,
				'records' => $records,
			)
		);

		$valid_once   = $service->validate_recovery_mode_key( $token, $key, $ttl );
		$valid_twice  = $service->validate_recovery_mode_key( $token, $key, $ttl );
		$after_valid  = self::recovery_key_records();
		$wrong_token  = $token . '|wrong';
		$wrong_key    = $service->generate_and_store_recovery_mode_key( $wrong_token );
		$wrong_result = $service->validate_recovery_mode_key( $wrong_token, $wrong_key . 'x', $ttl );
		$after_wrong  = self::recovery_key_records();

		self::collect_failure(
			$failures,
			true === $valid_once
				&& self::is_error_code( $valid_twice, 'token_not_found' )
				&& ! isset( $after_valid[ $token ] )
				&& self::is_error_code( $wrong_result, 'hash_mismatch' )
				&& ! isset( $after_wrong[ $wrong_token ] ),
			'recovery-mode keys validate once, and wrong keys fail closed while consuming their token',
			array(
				'validOnce'   => $valid_once,
				'validTwice'  => $valid_twice,
				'afterValid'  => $after_valid,
				'wrongResult' => $wrong_result,
				'afterWrong'  => $after_wrong,
			)
		);

		$expired_token    = $token . '|expired';
		$malformed_token  = $token . '|malformed';
		$missing_created  = $token . '|missing-created';
		$fresh_token      = $token . '|fresh';
		$old_clean_token  = $token . '|old-clean';
		$empty_ttl_token  = $token . '|empty-ttl';
		$expired_key      = 'expired-key-' . self::token( $ctx->fork( 'expired-key' ), 8 );
		$empty_ttl_key    = 'empty-ttl-key-' . self::token( $ctx->fork( 'empty-ttl-key' ), 8 );
		$current_time     = time();

		\update_option(
			'recovery_keys',
			array(
				$expired_token   => array(
					'hashed_key' => \wp_fast_hash( $expired_key ),
					'created_at' => $current_time - $ttl - 5,
				),
				$malformed_token => 'not-an-array',
				$missing_created => array(
					'hashed_key' => \wp_fast_hash( 'missing-created-key' ),
				),
				$fresh_token     => array(
					'hashed_key' => \wp_fast_hash( 'fresh-key' ),
					'created_at' => $current_time,
				),
				$old_clean_token => array(
					'hashed_key' => \wp_fast_hash( 'old-clean-key' ),
					'created_at' => $current_time - $ttl - 1,
				),
				$empty_ttl_token => array(
					'hashed_key' => \wp_fast_hash( $empty_ttl_key ),
					'created_at' => $current_time,
				),
			),
			false
		);

		$expired_result  = $service->validate_recovery_mode_key( $expired_token, $expired_key, $ttl );
		$malformed_result = $service->validate_recovery_mode_key( $malformed_token, 'anything', $ttl );
		$empty_ttl_result = $service->validate_recovery_mode_key( $empty_ttl_token, $empty_ttl_key, 0 );
		$after_validates = self::recovery_key_records();

		$service->clean_expired_keys( $ttl );
		$after_clean = self::recovery_key_records();

		self::collect_failure(
			$failures,
			self::is_error_code( $expired_result, 'key_expired' )
				&& self::is_error_code( $malformed_result, 'invalid_recovery_key_format' )
				&& true === $empty_ttl_result
				&& ! isset( $after_validates[ $expired_token ], $after_validates[ $malformed_token ], $after_validates[ $empty_ttl_token ] )
				&& isset( $after_clean[ $fresh_token ] )
				&& ! isset( $after_clean[ $missing_created ], $after_clean[ $old_clean_token ] ),
			'expired, malformed, and cleanup paths remove invalid key records while preserving fresh records',
			array(
				'expiredResult'   => $expired_result,
				'malformedResult' => $malformed_result,
				'emptyTtlResult'  => $empty_ttl_result,
				'afterValidates'  => $after_validates,
				'afterClean'      => $after_clean,
			)
		);

		\delete_option( 'recovery_keys' );
		\wp_cache_delete( 'recovery_keys', 'options' );

		return self::row(
			$ctx,
			'error-protection.recovery-keys.single-use-and-malformed-records',
			array() === $failures,
			array(
				'ttl'      => $ttl,
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_recovery_cookie_validation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$service  = new \WP_Recovery_Mode_Cookie_Service();

		$generate_cookie = new \ReflectionMethod( \WP_Recovery_Mode_Cookie_Service::class, 'generate_cookie' );
		$recovery_hash   = new \ReflectionMethod( \WP_Recovery_Mode_Cookie_Service::class, 'recovery_mode_hash' );

		unset( $_COOKIE[ RECOVERY_MODE_COOKIE ] );

		$cookie  = (string) $generate_cookie->invoke( $service );
		$decoded = base64_decode( $cookie, true );
		$parts   = is_string( $decoded ) ? explode( '|', $decoded ) : array();

		self::collect_failure(
			$failures,
			is_string( $decoded )
				&& 4 === count( $parts )
				&& 'recovery_mode' === $parts[0]
				&& ctype_digit( $parts[1] )
				&& '' !== $parts[2]
				&& 1 === preg_match( '/\A[a-f0-9]{40}\z/', $parts[3] )
				&& true === $service->validate_cookie( $cookie )
				&& sha1( $parts[2] ) === $service->get_session_id_from_cookie( $cookie ),
			'generated recovery cookies have four signed parts and resolve to the random session id',
			array(
				'decoded' => is_string( $decoded ) ? $decoded : '',
				'parts'   => $parts,
			)
		);

		$_COOKIE[ RECOVERY_MODE_COOKIE ] = $cookie;
		$superglobal_valid              = $service->validate_cookie();
		$superglobal_session            = $service->get_session_id_from_cookie();
		unset( $_COOKIE[ RECOVERY_MODE_COOKIE ] );

		self::collect_failure(
			$failures,
			true === $superglobal_valid
				&& sha1( $parts[2] ?? '' ) === $superglobal_session
				&& ! $service->is_cookie_set(),
			'cookie service validates from RECOVERY_MODE_COOKIE and leaves absent cookies unset after cleanup',
			array(
				'superglobalValid'   => $superglobal_valid,
				'superglobalSession' => $superglobal_session,
				'cookieSetAfter'     => $service->is_cookie_set(),
			)
		);

		$created_at        = (string) time();
		$random            = 'rand-' . self::token( $ctx->fork( 'random' ), 14 );
		$to_sign           = sprintf( 'recovery_mode|%s|%s', $created_at, $random );
		$signature         = (string) $recovery_hash->invoke( $service, $to_sign );
		$signed_cookie     = base64_encode( "{$to_sign}|{$signature}" );
		$tampered_cookie   = base64_encode( "{$to_sign}|" . self::mutate_hex( $signature ) );
		$expired_created   = (string) ( time() - WEEK_IN_SECONDS - 5 );
		$expired_random    = 'expired-' . self::token( $ctx->fork( 'expired-random' ), 12 );
		$expired_to_sign   = sprintf( 'recovery_mode|%s|%s', $expired_created, $expired_random );
		$expired_signature = (string) $recovery_hash->invoke( $service, $expired_to_sign );
		$expired_cookie    = base64_encode( "{$expired_to_sign}|{$expired_signature}" );
		$invalid_format    = base64_encode( 'one|two|three' );
		$invalid_created   = base64_encode( 'recovery_mode|not-digits|random|signature' );
		$not_base64        = '%%%not-base64%%%';

		$no_cookie_result       = $service->validate_cookie();
		$signed_result          = $service->validate_cookie( $signed_cookie );
		$tampered_result        = $service->validate_cookie( $tampered_cookie );
		$expired_result         = $service->validate_cookie( $expired_cookie );
		$format_result          = $service->validate_cookie( $invalid_format );
		$created_result         = $service->validate_cookie( $invalid_created );
		$not_base64_result      = $service->validate_cookie( $not_base64 );
		$malformed_session      = $service->get_session_id_from_cookie( $invalid_format );
		$tampered_session_id    = $service->get_session_id_from_cookie( $tampered_cookie );
		$wrong_prefix_accepted  = null;

		if ( 4 === count( $parts ) ) {
			$wrong_prefix_parts    = $parts;
			$wrong_prefix_parts[0] = 'not_recovery_mode';
			$wrong_prefix_accepted = true === $service->validate_cookie( base64_encode( implode( '|', $wrong_prefix_parts ) ) );
		}

		self::collect_failure(
			$failures,
			self::is_error_code( $no_cookie_result, 'no_cookie' )
				&& true === $signed_result
				&& self::is_error_code( $tampered_result, 'signature_mismatch' )
				&& self::is_error_code( $expired_result, 'expired' )
				&& self::is_error_code( $format_result, 'invalid_format' )
				&& self::is_error_code( $created_result, 'invalid_created_at' )
				&& self::is_error_code( $not_base64_result, 'invalid_format' )
				&& self::is_error_code( $malformed_session, 'invalid_format' )
				&& sha1( $random ) === $tampered_session_id,
			'cookie validation rejects absent, tampered, expired, and malformed cookies; session-id parsing remains format-only',
			array(
				'noCookie'            => $no_cookie_result,
				'signedResult'        => $signed_result,
				'tamperedResult'      => $tampered_result,
				'expiredResult'       => $expired_result,
				'formatResult'        => $format_result,
				'createdResult'       => $created_result,
				'notBase64Result'     => $not_base64_result,
				'malformedSession'    => $malformed_session,
				'tamperedSessionId'   => $tampered_session_id,
				'wrongPrefixAccepted' => $wrong_prefix_accepted,
			)
		);

		return self::row(
			$ctx,
			'error-protection.recovery-cookies.signed-shape-and-fail-closed-validation',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_recovery_link_generation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$key_service    = new \WP_Recovery_Mode_Key_Service();
		$cookie_service = new \WP_Recovery_Mode_Cookie_Service();
		$link_service   = new \WP_Recovery_Mode_Link_Service( $cookie_service, $key_service );
		$marker         = 'link-' . self::token( $ctx->fork( 'marker' ), 8 );
		$captured       = array();
		$ttl            = DAY_IN_SECONDS + $ctx->int( 0, 3600 );

		\delete_option( 'recovery_keys' );
		\wp_cache_delete( 'recovery_keys', 'options' );

		$url_filter = static function ( string $url, string $token, string $key ) use ( &$captured, $marker ): string {
			$captured[] = array(
				'url'   => $url,
				'token' => $token,
				'key'   => $key,
			);

			return \add_query_arg( 'component_fuzz_marker', $marker, $url );
		};

		\add_filter( 'recovery_mode_begin_url', $url_filter, 10, 3 );
		try {
			$url = $link_service->generate_url();
		} finally {
			\remove_filter( 'recovery_mode_begin_url', $url_filter, 10 );
		}

		$parts = \wp_parse_url( $url );
		$query = array();
		if ( is_array( $parts ) && isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		$token   = (string) ( $query['rm_token'] ?? '' );
		$key     = (string) ( $query['rm_key'] ?? '' );
		$records = self::recovery_key_records();
		$valid   = '' !== $token && '' !== $key ? $key_service->validate_recovery_mode_key( $token, $key, $ttl ) : null;
		$reused  = '' !== $token && '' !== $key ? $key_service->validate_recovery_mode_key( $token, $key, $ttl ) : null;

		self::collect_failure(
			$failures,
			1 === count( $captured )
				&& is_array( $parts )
				&& isset( $parts['host'], $parts['path'] )
				&& 'example.test' === $parts['host']
				&& str_ends_with( $parts['path'], 'wp-login.php' )
				&& \WP_Recovery_Mode_Link_Service::LOGIN_ACTION_ENTER === ( $query['action'] ?? null )
				&& $marker === ( $query['component_fuzz_marker'] ?? null )
				&& 22 === strlen( $token )
				&& 22 === strlen( $key )
				&& ctype_alnum( $token )
				&& ctype_alnum( $key )
				&& isset( $records[ $token ]['hashed_key'], $records[ $token ]['created_at'] )
				&& true === $valid
				&& self::is_error_code( $reused, 'token_not_found' )
				&& false === \has_filter( 'recovery_mode_begin_url', $url_filter ),
			'generated recovery links carry a one-use token/key pair through the documented login action query string',
			array(
				'url'      => $url,
				'captured' => $captured,
				'query'    => $query,
				'records'  => $records,
				'valid'    => $valid,
				'reused'   => $reused,
			)
		);

		\delete_option( 'recovery_keys' );
		\wp_cache_delete( 'recovery_keys', 'options' );

		return self::row(
			$ctx,
			'error-protection.recovery-links.generate-parse-and-consume',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_recovery_email_payload( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$key_service    = new \WP_Recovery_Mode_Key_Service();
		$cookie_service = new \WP_Recovery_Mode_Cookie_Service();
		$link_service   = new \WP_Recovery_Mode_Link_Service( $cookie_service, $key_service );
		$email_service  = new \WP_Recovery_Mode_Email_Service( $link_service );
		$token          = 'email-' . self::token( $ctx->fork( 'token' ), 8 );
		$admin_email    = 'admin+' . self::token( $ctx->fork( 'admin' ), 8 ) . '@example.test';
		$blog_name      = 'Component Fuzz Recovery ' . $token;
		$plugin_slug    = 'mail-plugin-' . self::token( $ctx->fork( 'plugin' ), 8 );
		$rate_limit     = 120 + $ctx->int( 0, 3600 );
		$captured_mail  = array();
		$captured_email = array();

		\delete_option( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION );
		\delete_option( 'recovery_keys' );
		\update_option( 'admin_email', $admin_email, false );
		\update_option( 'blogname', $blog_name, false );
		\wp_cache_delete( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION, 'options' );
		\wp_cache_delete( 'recovery_keys', 'options' );
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php?page=' . rawurlencode( $token );

		$error     = self::extension_error_case( $ctx->fork( 'email-error' ), WP_PLUGIN_DIR . '/' . $plugin_slug . '/main.php' );
		$extension = array(
			'type' => 'plugin',
			'slug' => $plugin_slug,
		);

		$support_filter = static function ( string $message ) use ( $token ): string {
			return $message . "\nSupport token: {$token}";
		};
		$debug_filter   = static function ( array $debug ) use ( $token ): array {
			$debug['component_fuzz'] = 'Debug token: ' . $token;
			return $debug;
		};
		$email_filter   = static function ( array $email, string $url ) use ( &$captured_email, $token ): array {
			$captured_email[] = array(
				'email' => $email,
				'url'   => $url,
			);

			$email['subject'] .= ' [' . $token . ']';
			$email['message'] .= "\n\nFiltered token: {$token}";
			$email['headers']  = array( 'X-Recovery-Fuzz: ' . $token );

			return $email;
		};
		$pre_mail       = static function ( $pre, array $atts ) use ( &$captured_mail ) {
			$captured_mail[] = $atts;
			return true;
		};

		\add_filter( 'recovery_email_support_info', $support_filter );
		\add_filter( 'recovery_email_debug_info', $debug_filter );
		\add_filter( 'recovery_mode_email', $email_filter, 10, 2 );
		\add_filter( 'pre_wp_mail', $pre_mail, 10, 2 );
		try {
			$sent                  = $email_service->maybe_send_recovery_mode_email( $rate_limit, $error, $extension );
			$last_sent_after_first = \get_option( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION, false );
			\update_option( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION, time(), false );
			\wp_cache_delete( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION, 'options' );
			$rate_limited  = $email_service->maybe_send_recovery_mode_email( $rate_limit, $error, $extension );
			$cleared_limit = $email_service->clear_rate_limit();
		} finally {
			\remove_filter( 'recovery_email_support_info', $support_filter );
			\remove_filter( 'recovery_email_debug_info', $debug_filter );
			\remove_filter( 'recovery_mode_email', $email_filter, 10 );
			\remove_filter( 'pre_wp_mail', $pre_mail, 10 );
		}

		$mail        = $captured_mail[0] ?? array();
		$email_url   = (string) ( $captured_email[0]['url'] ?? '' );
		$url_parts   = \wp_parse_url( $email_url );
		$url_query   = array();
		if ( is_array( $url_parts ) && isset( $url_parts['query'] ) ) {
			parse_str( $url_parts['query'], $url_query );
		}
		$mail_message = (string) ( $mail['message'] ?? '' );
		$records      = self::recovery_key_records();
		$url_token    = (string) ( $url_query['rm_token'] ?? '' );

		self::collect_failure(
			$failures,
			true === $sent
				&& 1 === count( $captured_mail )
				&& 1 === count( $captured_email )
				&& ( false === ( $mail['to'] ?? null ) || ( is_string( $mail['to'] ?? null ) && str_contains( (string) $mail['to'], '@' ) ) ),
			'recovery email is intercepted before real mail and has a bounded recipient target',
			array(
				'sent'          => $sent,
				'mailCount'     => count( $captured_mail ),
				'capturedCount' => count( $captured_email ),
				'mailTo'        => $mail['to'] ?? null,
				'adminEmail'    => $admin_email,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $mail['subject'] ?? null )
				&& str_contains( (string) $mail['subject'], '[' . $blog_name . ']' )
				&& str_contains( (string) $mail['subject'], $token )
				&& str_contains( $mail_message, $email_url )
				&& str_contains( $mail_message, 'Support token: ' . $token )
				&& str_contains( $mail_message, 'Debug token: ' . $token )
				&& str_contains( $mail_message, 'Filtered token: ' . $token )
				&& str_contains( $mail_message, 'Error Details' )
				&& str_contains( $mail_message, WP_PLUGIN_DIR . '/' . $plugin_slug . '/main.php' )
				&& ! str_contains( $mail_message, '<code>' )
				&& in_array( 'X-Recovery-Fuzz: ' . $token, (array) ( $mail['headers'] ?? array() ), true ),
			'recovery email payload is filterable and includes generated support/debug/error details',
			array(
				'subject'  => $mail['subject'] ?? null,
				'headers'  => $mail['headers'] ?? null,
				'message'  => $mail_message,
				'emailUrl' => $email_url,
			)
		);

		self::collect_failure(
			$failures,
			\WP_Recovery_Mode_Link_Service::LOGIN_ACTION_ENTER === ( $url_query['action'] ?? null )
				&& isset( $url_query['rm_token'], $url_query['rm_key'], $records[ $url_token ] ),
			'recovery email URL carries a stored recovery token/key pair',
			array(
				'urlQuery' => $url_query,
				'records'  => $records,
			)
		);

		self::collect_failure(
			$failures,
			self::is_error_code( $rate_limited, 'email_sent_already' )
				&& true === $cleared_limit
				&& false === \get_option( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION, false )
				&& 1 === count( $captured_mail ),
			'recovery email repeat call is rate-limited and clear_rate_limit removes the shared option',
			array(
				'rateLimited'        => $rate_limited,
				'rateLimitedCode'    => \is_wp_error( $rate_limited ) ? $rate_limited->get_error_code() : null,
				'clearedLimit'       => $cleared_limit,
				'optionAfterClear'   => \get_option( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION, false ),
				'mailCount'          => count( $captured_mail ),
				'lastSentAfterFirst' => $last_sent_after_first,
			)
		);

		\delete_option( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION );
		\delete_option( 'recovery_keys' );
		\wp_cache_delete( \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION, 'options' );
		\wp_cache_delete( 'recovery_keys', 'options' );

		return self::row(
			$ctx,
			'error-protection.recovery-email.filterable-payload-no-real-mail',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_fatal_error_handler_oracles( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$handler  = new \WP_Fatal_Error_Handler();
		$should   = new \ReflectionMethod( \WP_Fatal_Error_Handler::class, 'should_handle_error' );
		$display  = new \ReflectionMethod( \WP_Fatal_Error_Handler::class, 'display_default_error_template' );
		$marker   = 'fatal-' . self::token( $ctx->fork( 'marker' ), 8 );
		$error    = self::extension_error_case( $ctx->fork( 'fatal-error' ), WP_PLUGIN_DIR . '/fatal-' . self::token( $ctx->fork( 'plugin' ), 6 ) . '/main.php' );
		$error['type'] = E_PARSE;

		$core_types_ok = true;
		foreach ( array( E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ) as $type ) {
			$core_types_ok = $core_types_ok && true === $should->invoke(
				$handler,
				array(
					'type'    => $type,
					'file'    => $error['file'],
					'line'    => $error['line'],
					'message' => $error['message'],
				)
			);
		}

		$warning_default = $should->invoke(
			$handler,
			array(
				'type'    => E_WARNING,
				'file'    => $error['file'],
				'line'    => $error['line'],
				'message' => 'ordinary warning',
			)
		);

		$filter_calls = 0;
		$error_filter = static function ( bool $should_handle, array $filtered_error ) use ( &$filter_calls, $marker ): bool {
			++$filter_calls;
			return $should_handle || str_contains( (string) ( $filtered_error['message'] ?? '' ), $marker );
		};

		\add_filter( 'wp_should_handle_php_error', $error_filter, 10, 2 );
		try {
			$warning_filtered = $should->invoke(
				$handler,
				array(
					'type'    => E_WARNING,
					'file'    => $error['file'],
					'line'    => $error['line'],
					'message' => 'filtered ' . $marker,
				)
			);
			$fatal_filtered   = $should->invoke(
				$handler,
				array(
					'type'    => E_ERROR,
					'file'    => $error['file'],
					'line'    => $error['line'],
					'message' => 'fatal ' . $marker,
				)
			);
		} finally {
			\remove_filter( 'wp_should_handle_php_error', $error_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$core_types_ok
				&& false === $warning_default
				&& true === $warning_filtered
				&& true === $fatal_filtered
				&& 1 === $filter_calls
				&& false === \has_filter( 'wp_should_handle_php_error', $error_filter ),
			'fatal handler recognizes core fatal types and only consults the extension filter for non-core error types',
			array(
				'coreTypesOk'     => $core_types_ok,
				'warningDefault'  => $warning_default,
				'warningFiltered' => $warning_filtered,
				'fatalFiltered'   => $fatal_filtered,
				'filterCalls'     => $filter_calls,
			)
		);

		$description = \wp_get_extension_error_description( $error );
		$unknown     = $error;
		$unknown['type'] = 123456;
		$unknown_description = \wp_get_extension_error_description( $unknown );

		self::collect_failure(
			$failures,
			str_contains( $description, '<code>E_PARSE</code>' )
				&& str_contains( $description, '<code>' . $error['line'] . '</code>' )
				&& str_contains( $description, '<code>' . $error['file'] . '</code>' )
				&& str_contains( $description, '<code>' . $error['message'] . '</code>' )
				&& str_contains( $unknown_description, '<code>123456</code>' ),
			'extension error descriptions map known PHP constants and preserve unknown numeric types',
			array(
				'description'        => $description,
				'unknownDescription' => $unknown_description,
			)
		);

		$die_calls      = array();
		$message_filter = static function ( string $message, array $display_error ) use ( $marker, &$die_calls ): string {
			$die_calls['message_filter_error'] = $display_error;
			return $message . '<p data-component-fuzz="' . $marker . '">filtered</p>';
		};
		$args_filter    = static function ( array $args, array $display_error ) use ( $marker, &$die_calls ): array {
			$die_calls['args_filter_error'] = $display_error;
			$args['response']               = 599;
			$args['exit']                   = false;
			$args['component_fuzz_marker']  = $marker;
			return $args;
		};
		$die_handler    = static function ( $message, string $title, array $args ) use ( &$die_calls ): void {
			$die_calls['handler'][] = array(
				'message' => $message,
				'title'   => $title,
				'args'    => $args,
			);
		};
		$die_filter     = static function () use ( $die_handler ): callable {
			return $die_handler;
		};

		self::set_recovery_mode_state( false, false, '' );
		\add_filter( 'wp_php_error_message', $message_filter, 10, 2 );
		\add_filter( 'wp_php_error_args', $args_filter, 10, 2 );
		\add_filter( 'wp_die_handler', $die_filter );
		ob_start();
		try {
			$display->invoke( $handler, $error, false );
			$output = ob_get_clean();
		} finally {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			\remove_filter( 'wp_php_error_message', $message_filter, 10 );
			\remove_filter( 'wp_php_error_args', $args_filter, 10 );
			\remove_filter( 'wp_die_handler', $die_filter );
		}

		$handler_call = $die_calls['handler'][0] ?? array();
		$wp_error     = $handler_call['message'] ?? null;

		self::collect_failure(
			$failures,
			'' === $output
				&& $wp_error instanceof \WP_Error
				&& 'internal_server_error' === $wp_error->get_error_code()
				&& $error === ( $wp_error->get_error_data()['error'] ?? null )
				&& str_contains( $wp_error->get_error_message(), $marker )
				&& 599 === ( $handler_call['args']['response'] ?? null )
				&& false === ( $handler_call['args']['exit'] ?? true )
				&& $marker === ( $handler_call['args']['component_fuzz_marker'] ?? null )
				&& $error === ( $die_calls['message_filter_error'] ?? null )
				&& $error === ( $die_calls['args_filter_error'] ?? null )
				&& false === \has_filter( 'wp_die_handler', $die_filter ),
			'default fatal error template delegates a WP_Error to wp_die with filterable message and non-exiting args',
			array(
				'output'      => $output,
				'handlerCall' => $handler_call,
				'dieCalls'    => $die_calls,
			)
		);

		return self::row(
			$ctx,
			'error-protection.fatal-handler.detection-description-and-template',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_handler_and_endpoint_gates( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$marker   = 'endpoint-' . self::token( $ctx->fork( 'marker' ), 8 );

		$default_enabled = \wp_is_fatal_error_handler_enabled();
		$enabled_filter  = static function () use ( $marker ): bool {
			return 'never-' . $marker === $marker;
		};

		\add_filter( 'wp_fatal_error_handler_enabled', $enabled_filter );
		try {
			$filtered_enabled = \wp_is_fatal_error_handler_enabled();
		} finally {
			\remove_filter( 'wp_fatal_error_handler_enabled', $enabled_filter );
		}

		unset( $GLOBALS['current_screen'] );
		$GLOBALS['pagenow'] = 'index.php';
		$_REQUEST           = array();
		$plain_endpoint     = \is_protected_endpoint();

		$GLOBALS['pagenow'] = 'wp-login.php';
		$login_endpoint     = \is_protected_endpoint();

		$GLOBALS['pagenow'] = 'index.php';
		$endpoint_filter    = static function () use ( $marker ): bool {
			return str_starts_with( $marker, 'endpoint-' );
		};
		\add_filter( 'is_protected_endpoint', $endpoint_filter );
		try {
			$filtered_endpoint = \is_protected_endpoint();
		} finally {
			\remove_filter( 'is_protected_endpoint', $endpoint_filter );
		}

		$noop_handler = new class() extends \WP_Fatal_Error_Handler {
			public int $detect_calls  = 0;
			public int $display_calls = 0;

			protected function detect_error() {
				++$this->detect_calls;
				return null;
			}

			protected function display_error_template( $error, $handled ) {
				unset( $error, $handled );
				++$this->display_calls;
			}
		};
		$noop_handler->handle();
		$noop_handler->handle();

		self::collect_failure(
			$failures,
			true === $default_enabled
				&& false === $filtered_enabled
				&& false === \has_filter( 'wp_fatal_error_handler_enabled', $enabled_filter )
				&& false === $plain_endpoint
				&& true === $login_endpoint
				&& true === $filtered_endpoint
				&& false === \has_filter( 'is_protected_endpoint', $endpoint_filter )
				&& 2 === $noop_handler->detect_calls
				&& 0 === $noop_handler->display_calls,
			'fatal-handler and protected-endpoint gates are filterable, restored, and no-error shutdown handling is idempotent',
			array(
				'defaultEnabled'   => $default_enabled,
				'filteredEnabled'  => $filtered_enabled,
				'plainEndpoint'    => $plain_endpoint,
				'loginEndpoint'    => $login_endpoint,
				'filteredEndpoint' => $filtered_endpoint,
				'detectCalls'      => $noop_handler->detect_calls,
				'displayCalls'     => $noop_handler->display_calls,
			)
		);

		return self::row(
			$ctx,
			'error-protection.handler-gates.endpoint-classification-and-noop-idempotence',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function reset_runtime( \ComponentFuzz\FuzzContext $ctx ): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['PHP_SELF']        = '/wp-admin/index.php';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.' . $ctx->int( 1, 254 );
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-admin/index.php?page=component-fuzz-error-protection';
		$_SERVER['SERVER_PORT']     = '80';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/error-protection';

		unset( $GLOBALS['current_screen'] );
		$GLOBALS['pagenow']              = 'index.php';
		$GLOBALS['wp_theme_directories'] = array( WP_CONTENT_DIR . '/themes' );

		\update_option( 'home', 'http://example.test', false );
		\update_option( 'siteurl', 'http://example.test', false );
		\update_option( 'admin_email', 'admin@example.test', false );
		\update_option( 'blogname', 'Component Fuzz', false );

		foreach ( array( 'recovery_keys', \WP_Recovery_Mode_Email_Service::RATE_LIMIT_OPTION ) as $option ) {
			\delete_option( $option );
			\wp_cache_delete( $option, 'options' );
		}

		self::set_recovery_mode_state( false, false, '' );
	}

	private static function extension_error_case( \ComponentFuzz\FuzzContext $ctx, string $file ): array {
		return array(
			'type'    => $ctx->choice( array( E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ) ),
			'file'    => $file,
			'line'    => $ctx->int( 1, 5000 ),
			'message' => 'Component fuzz fatal ' . self::token( $ctx->fork( 'message' ), 10 ),
		);
	}

	private static function recovery_key_records(): array {
		$records = \get_option( 'recovery_keys', array() );
		return is_array( $records ) ? $records : array();
	}

	private static function is_error_code( $value, string $code ): bool {
		return \is_wp_error( $value ) && $code === $value->get_error_code();
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

	private static function token( \ComponentFuzz\FuzzContext $ctx, int $length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';

		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $ctx->int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $out;
	}

	private static function mutate_hex( string $hex ): string {
		if ( '' === $hex ) {
			return '0';
		}

		return ( '0' === $hex[0] ? '1' : '0' ) . substr( $hex, 1 );
	}

	private static function snapshot_recovery_mode_state(): array {
		$mode  = \wp_recovery_mode();
		$state = array();

		foreach ( array( 'is_initialized', 'is_active', 'session_id' ) as $property ) {
			$reflection         = new \ReflectionProperty( \WP_Recovery_Mode::class, $property );
			$state[ $property ] = $reflection->getValue( $mode );
		}

		return $state;
	}

	private static function set_recovery_mode_state( bool $initialized, bool $active, string $session_id ): void {
		$mode = \wp_recovery_mode();

		foreach (
			array(
				'is_initialized' => $initialized,
				'is_active'      => $active,
				'session_id'     => $active ? $session_id : '',
			) as $property => $value
		) {
			$reflection = new \ReflectionProperty( \WP_Recovery_Mode::class, $property );
			$reflection->setValue( $mode, $value );
		}
	}

	private static function restore_recovery_mode_state( array $state ): void {
		$mode = \wp_recovery_mode();

		foreach ( $state as $property => $value ) {
			$reflection = new \ReflectionProperty( \WP_Recovery_Mode::class, $property );
			$reflection->setValue( $mode, $value );
		}
	}

	private static function snapshot_state(): array {
		$snapshot = array(
			'_GET'     => $_GET,
			'_POST'    => $_POST,
			'_REQUEST' => $_REQUEST,
			'_COOKIE'  => $_COOKIE,
			'_SERVER'  => $_SERVER,
			'globals'  => array(),
			'recovery' => self::snapshot_recovery_mode_state(),
		);

		foreach (
			array(
				'_paused_plugins',
				'_paused_themes',
				'current_screen',
				'pagenow',
				'phpmailer',
				'wpdb',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_locale',
				'wp_locale_switcher',
				'wp_object_cache',
				'wp_plugin_paths',
				'wp_theme_directories',
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
		$_GET     = $snapshot['_GET'];
		$_POST    = $snapshot['_POST'];
		$_REQUEST = $snapshot['_REQUEST'];
		$_COOKIE  = $snapshot['_COOKIE'];
		$_SERVER  = $snapshot['_SERVER'];

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		self::restore_recovery_mode_state( $snapshot['recovery'] );
	}

	private static function state_matches( array $snapshot ): bool {
		if ( $_GET !== $snapshot['_GET'] || $_POST !== $snapshot['_POST'] || $_REQUEST !== $snapshot['_REQUEST'] || $_COOKIE !== $snapshot['_COOKIE'] || $_SERVER !== $snapshot['_SERVER'] ) {
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

		return self::snapshot_recovery_mode_state() === $snapshot['recovery'];
	}

	private static function clone_value( $value ) {
		if ( is_object( $value ) ) {
			if ( $value instanceof \Closure ) {
				return $value;
			}

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

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
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

				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
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
					'type'    => 'WP_Error',
					'code'    => $value->get_error_code(),
					'message' => $value->get_error_message(),
					'data'    => self::describe_value( $value->get_error_data(), $depth + 1 ),
				);
			}

			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'type'    => 'string',
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => self::escape_bytes( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function escape_bytes( string $value, int $limit = self::SAMPLE_BYTES ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; ++$i ) {
			$byte = ord( $value[ $i ] );
			if ( 0x5C === $byte ) {
				$out .= '\\\\';
			} elseif ( $byte >= 0x20 && $byte <= 0x7E ) {
				$out .= chr( $byte );
			} elseif ( 0x0A === $byte ) {
				$out .= '\\n';
			} elseif ( 0x0D === $byte ) {
				$out .= '\\r';
			} elseif ( 0x09 === $byte ) {
				$out .= '\\t';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $shown ) {
			$out .= '...';
		}

		return $out;
	}
}
