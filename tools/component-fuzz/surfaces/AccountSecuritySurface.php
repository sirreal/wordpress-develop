<?php
namespace ComponentFuzz\Surfaces;

final class AccountSecuritySurface {
	public const NAME = 'account-security';

	private const GENERATED_STRING_CASES = 6;
	private const SAMPLE_BYTES           = 160;

	/** @var array<int,array<int,array<string,mixed>>> */
	private static array $application_passwords = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'account-security.bootstrap-apis-available',
					'Required WordPress account security APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_runtime();
			self::reset_static_state();
			self::install_scoped_filters();

			$rows[] = self::check_application_password_lifecycle( $ctx->fork( 'application-password-lifecycle' ) );
			$rows[] = self::check_application_password_chunking_and_hashes( $ctx->fork( 'application-password-hashes' ) );
			$rows[] = self::check_application_password_authentication( $ctx->fork( 'application-password-authentication' ) );
			$rows[] = self::check_password_reset_key_lifecycle( $ctx->fork( 'password-reset-key-lifecycle' ) );
			$rows[] = self::check_reset_password_composition( $ctx->fork( 'reset-password-composition' ) );
			$rows[] = self::check_recovery_key_service( $ctx->fork( 'recovery-key-service' ) );
			$rows[] = self::check_recovery_cookie_service( $ctx->fork( 'recovery-cookie-service' ) );
			$rows[] = self::check_paused_extension_storage( $ctx->fork( 'paused-extension-storage' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'account-security.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_static_state();
			self::restore_globals( $snapshot );
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

		self::$application_passwords[ $user_id ] = is_array( $meta_value ) ? $meta_value : array();

		return true;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'PasswordHash',
				'WP_Application_Passwords',
				'WP_Error',
				'WP_Paused_Extensions_Storage',
				'WP_Recovery_Mode',
				'WP_Recovery_Mode_Cookie_Service',
				'WP_Recovery_Mode_Key_Service',
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
				'check_password_reset_key',
				'current_filter',
				'delete_option',
				'get_userdata',
				'get_password_reset_key',
				'get_user_by',
				'get_network_option',
				'get_option',
				'get_user_meta',
				'has_filter',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'reset_password',
				'sanitize_text_field',
				'update_network_option',
				'update_option',
				'update_user_meta',
				'wp_check_password',
				'wp_authenticate_application_password',
				'wp_cache_delete',
				'wp_fast_hash',
				'wp_generate_password',
				'wp_generate_uuid4',
				'wp_hash_password',
				'wp_insert_user',
				'wp_is_application_passwords_available',
				'wp_is_application_passwords_available_for_user',
				'wp_is_password_reset_allowed_for_user',
				'wp_recovery_mode',
				'wp_set_password',
				'wp_update_user',
				'wp_validate_application_password',
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
				'YEAR_IN_SECONDS',
			) as $constant
		) {
			if ( ! defined( $constant ) ) {
				$missing[] = "constant {$constant}";
			}
		}

		if ( ! function_exists( 'sodium_crypto_generichash' ) ) {
			$missing[] = 'function sodium_crypto_generichash';
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_application_password_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = 81000 + $ctx->int( 0, 9999 );
		$name     = self::name_case( $ctx->fork( 'name' ) );
		$new_name = self::name_case( $ctx->fork( 'updated-name' ) );
		$app_id   = self::app_id_case( $ctx->fork( 'app-id' ) );

		self::$application_passwords[ $user_id ] = array();
		\delete_option( \WP_Application_Passwords::OPTION_KEY_IN_USE );
		\wp_cache_delete( \WP_Application_Passwords::OPTION_KEY_IN_USE, 'options' );

		self::collect_failure(
			$failures,
			array() === \WP_Application_Passwords::get_user_application_passwords( $user_id ),
			'new synthetic user starts without application passwords',
			array( 'userId' => $user_id )
		);

		$empty_name = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => "<b>\n\t</b>",
				'app_id' => $app_id,
			)
		);
		self::collect_failure(
			$failures,
			self::is_error_code( $empty_name, 'application_password_empty_name' ),
			'create rejects names that sanitize to empty text',
			array( 'result' => self::describe_value( $empty_name ) )
		);

		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => $name['raw'],
				'app_id' => $app_id,
			)
		);

		$plain_password = null;
		$item           = null;
		if ( is_array( $created ) && 2 === count( $created ) ) {
			$plain_password = $created[0];
			$item           = $created[1];
		}

		self::collect_failure(
			$failures,
			is_string( $plain_password )
				&& \WP_Application_Passwords::PW_LENGTH === strlen( $plain_password )
				&& is_array( $item )
				&& isset( $item['uuid'], $item['name'], $item['app_id'], $item['password'], $item['created'] )
				&& array_key_exists( 'last_used', $item )
				&& array_key_exists( 'last_ip', $item )
				&& $name['sanitized'] === $item['name']
				&& $app_id === $item['app_id']
				&& null === $item['last_used']
				&& null === $item['last_ip']
				&& str_starts_with( (string) $item['password'], '$generic$' )
				&& \WP_Application_Passwords::check_password( (string) $plain_password, (string) $item['password'] )
				&& ! \WP_Application_Passwords::check_password( self::mutate_secret( (string) $plain_password ), (string) $item['password'] ),
			'create stores sanitized metadata and a generic hash matching only the generated password',
			array(
				'name'    => $name,
				'appId'   => self::describe_string( $app_id ),
				'created' => self::describe_value( $created ),
			)
		);

		$uuid = is_array( $item ) ? (string) $item['uuid'] : '';
		self::collect_failure(
			$failures,
			'' !== $uuid
				&& $item === \WP_Application_Passwords::get_user_application_password( $user_id, $uuid )
				&& \WP_Application_Passwords::application_name_exists_for_user( $user_id, strtolower( $name['sanitized'] ) )
				&& \WP_Application_Passwords::is_in_use(),
			'getters find the created item, duplicate-name probe is case-insensitive, and in-use flag is set',
			array(
				'uuid'     => $uuid,
				'name'     => $name,
				'inUse'    => \WP_Application_Passwords::is_in_use(),
				'password' => self::describe_value( \WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) ),
			)
		);

		$duplicate = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => strtoupper( $name['sanitized'] ),
				'app_id' => self::app_id_case( $ctx->fork( 'duplicate-app-id' ) ),
			)
		);
		$duplicate_behavior = \is_wp_error( $duplicate ) ? 'rejected' : 'created';

		if ( \is_wp_error( $duplicate ) ) {
			$second_name = self::name_case( $ctx->fork( 'second-name' ), 'Second Component Fuzz App' );
			$second      = \WP_Application_Passwords::create_new_application_password(
				$user_id,
				array(
					'name'   => $second_name['raw'],
					'app_id' => self::app_id_case( $ctx->fork( 'second-app-id' ) ),
				)
			);
		} else {
			$second_name = array(
				'raw'       => strtoupper( $name['sanitized'] ),
				'sanitized' => \sanitize_text_field( strtoupper( $name['sanitized'] ) ),
			);
			$second      = $duplicate;
		}

		$passwords_after_second = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		self::collect_failure(
			$failures,
			is_array( $second )
				&& 2 === count( $passwords_after_second )
				&& isset( $passwords_after_second[0]['uuid'], $passwords_after_second[1]['uuid'] )
				&& $passwords_after_second[0]['uuid'] !== $passwords_after_second[1]['uuid'],
			'second application password leaves two distinct lifecycle records',
			array(
				'duplicateBehavior' => $duplicate_behavior,
				'duplicateResult'   => self::describe_value( $duplicate ),
				'secondName'        => $second_name,
				'passwords'         => self::describe_value( $passwords_after_second ),
			)
		);

		$update_result = \WP_Application_Passwords::update_application_password(
			$user_id,
			$uuid,
			array( 'name' => $new_name['raw'] )
		);
		$updated_item  = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		$same_update   = \WP_Application_Passwords::update_application_password(
			$user_id,
			$uuid,
			array( 'name' => $new_name['sanitized'] )
		);
		$missing_uuid  = 'missing|' . self::random_string( $ctx->fork( 'missing-uuid' ), 20 );

		self::collect_failure(
			$failures,
			true === $update_result
				&& true === $same_update
				&& is_array( $updated_item )
				&& $new_name['sanitized'] === $updated_item['name']
				&& self::is_error_code( \WP_Application_Passwords::update_application_password( $user_id, $missing_uuid, array( 'name' => 'Missing' ) ), 'application_password_not_found' )
				&& null === \WP_Application_Passwords::get_user_application_password( $user_id, $missing_uuid ),
			'update sanitizes names, idempotent updates are true, and missing UUIDs never resolve',
			array(
				'uuid'        => $uuid,
				'newName'     => $new_name,
				'updatedItem' => self::describe_value( $updated_item ),
				'missingUuid' => self::describe_string( $missing_uuid ),
			)
		);

		$ip_before                  = self::ip_case( $ctx->fork( 'usage-ip' ) );
		$_SERVER['REMOTE_ADDR']     = $ip_before;
		$usage_started              = time();
		$usage_result               = \WP_Application_Passwords::record_application_password_usage( $user_id, $uuid );
		$usage_finished             = time();
		$used_item                  = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		$last_used_after_first_call = is_array( $used_item ) ? $used_item['last_used'] : null;
		$last_ip_after_first_call   = is_array( $used_item ) ? $used_item['last_ip'] : null;

		$_SERVER['REMOTE_ADDR'] = self::ip_case( $ctx->fork( 'second-usage-ip' ) );
		$second_usage_result    = \WP_Application_Passwords::record_application_password_usage( $user_id, $uuid );
		$used_item_again        = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );

		self::collect_failure(
			$failures,
			true === $usage_result
				&& true === $second_usage_result
				&& is_array( $used_item )
				&& is_int( $last_used_after_first_call )
				&& $last_used_after_first_call >= $usage_started
				&& $last_used_after_first_call <= $usage_finished
				&& $ip_before === $last_ip_after_first_call
				&& is_array( $used_item_again )
				&& $last_used_after_first_call === $used_item_again['last_used']
				&& $last_ip_after_first_call === $used_item_again['last_ip']
				&& self::is_error_code( \WP_Application_Passwords::record_application_password_usage( $user_id, $missing_uuid ), 'application_password_not_found' ),
			'record usage stores IP/time once per day and missing UUIDs fail closed',
			array(
				'ipBefore'       => $ip_before,
				'ipSecond'       => $_SERVER['REMOTE_ADDR'],
				'usedItem'       => self::describe_value( $used_item ),
				'usedItemAgain'  => self::describe_value( $used_item_again ),
				'usageStarted'   => $usage_started,
				'usageFinished'  => $usage_finished,
			)
		);

		$count_before_delete     = count( \WP_Application_Passwords::get_user_application_passwords( $user_id ) );
		$delete_result           = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		$passwords_after_delete  = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$delete_missing_result   = \WP_Application_Passwords::delete_application_password( $user_id, $missing_uuid );
		$count_before_delete_all = count( $passwords_after_delete );
		$delete_all_result       = \WP_Application_Passwords::delete_all_application_passwords( $user_id );
		$delete_all_again        = \WP_Application_Passwords::delete_all_application_passwords( $user_id );

		self::collect_failure(
			$failures,
			true === $delete_result
				&& $count_before_delete - 1 === count( $passwords_after_delete )
				&& null === \WP_Application_Passwords::get_user_application_password( $user_id, $uuid )
				&& self::is_error_code( $delete_missing_result, 'application_password_not_found' )
				&& $count_before_delete_all === $delete_all_result
				&& 0 === $delete_all_again
				&& array() === \WP_Application_Passwords::get_user_application_passwords( $user_id ),
			'delete one/all transitions remove exactly the targeted application passwords',
			array(
				'countBeforeDelete'    => $count_before_delete,
				'passwordsAfterDelete' => self::describe_value( $passwords_after_delete ),
				'deleteAllResult'      => $delete_all_result,
				'deleteAllAgain'       => $delete_all_again,
				'missingDelete'        => self::describe_value( $delete_missing_result ),
			)
		);

		return self::row(
			$ctx,
			'account-security.application-password.lifecycle-state-transitions',
			array() === $failures,
			array(
				'userId'            => $user_id,
				'duplicateBehavior' => $duplicate_behavior,
				'failures'          => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_application_password_chunking_and_hashes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$passwords = self::password_cases( $ctx->fork( 'passwords' ) );

		foreach ( $passwords as $index => $password ) {
			$chunked  = \WP_Application_Passwords::chunk_password( $password );
			$stripped = preg_replace( '/[^a-z\d]/i', '', $password );
			$expected = trim( chunk_split( (string) $stripped, 4, ' ' ) );
			$groups   = '' === $chunked ? array() : explode( ' ', $chunked );

			self::collect_failure(
				$failures,
				$expected === $chunked
					&& ! str_contains( $chunked, "\n" )
					&& 1 === preg_match( '/\A(?:[A-Za-z0-9]{1,4})(?: [A-Za-z0-9]{1,4})*\z|\A\z/', $chunked )
					&& count( array_filter( $groups, static fn ( string $group ): bool => strlen( $group ) > 4 ) ) === 0,
				"chunk_password strips non-alnum and chunks case {$index}",
				array(
					'password' => self::describe_string( $password ),
					'expected' => self::describe_string( $expected ),
					'actual'   => self::describe_string( $chunked ),
					'groups'   => $groups,
				)
			);

			$generic_hash   = \WP_Application_Passwords::hash_password( $password );
			$generic_hash_b = \WP_Application_Passwords::hash_password( $password );
			$wrong          = self::mutate_secret( $password );

			self::collect_failure(
				$failures,
				str_starts_with( $generic_hash, '$generic$' )
					&& $generic_hash === $generic_hash_b
					&& \WP_Application_Passwords::check_password( $password, $generic_hash )
					&& ! \WP_Application_Passwords::check_password( $wrong, $generic_hash )
					&& ! \WP_Application_Passwords::check_password( $password, '$generic$malformed' ),
				"generic application password hash verifies only the source password case {$index}",
				array(
					'password' => self::describe_string( $password ),
					'hash'     => self::describe_string( $generic_hash ),
					'wrong'    => self::describe_string( $wrong ),
				)
			);
		}

		$legacy_password = 'legacy-' . self::token( $ctx->fork( 'legacy-password' ), 18 );
		$legacy_wrong    = self::mutate_secret( $legacy_password );
		$wp_hash         = \wp_hash_password( $legacy_password );
		$phpass_hash     = ( new \PasswordHash( 8, true ) )->HashPassword( $legacy_password );
		$md5_hash        = md5( $legacy_password );

		self::collect_failure(
			$failures,
			\WP_Application_Passwords::check_password( $legacy_password, $wp_hash )
				&& ! \WP_Application_Passwords::check_password( $legacy_wrong, $wp_hash )
				&& \WP_Application_Passwords::check_password( $legacy_password, $phpass_hash )
				&& ! \WP_Application_Passwords::check_password( $legacy_wrong, $phpass_hash )
				&& \WP_Application_Passwords::check_password( $legacy_password, $md5_hash )
				&& ! \WP_Application_Passwords::check_password( $legacy_wrong, $md5_hash )
				&& false === \WP_Application_Passwords::check_password( $legacy_password, 'not-a-recognized-hash' ),
			'legacy application password branches accept only matching wp_hash_password/phpass/md5 hashes',
			array(
				'legacyPassword' => self::describe_string( $legacy_password ),
				'wpHash'         => self::describe_string( $wp_hash ),
				'phpassHash'     => self::describe_string( $phpass_hash ),
				'md5Hash'        => $md5_hash,
			)
		);

		return self::row(
			$ctx,
			'account-security.application-password.chunk-and-hash-oracles',
			array() === $failures,
			array(
				'cases'    => count( $passwords ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_application_password_authentication( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::token( $ctx->fork( 'user-token' ), 10 );
		$login    = 'cfz_app_auth_' . $ctx->iteration() . '_' . $token;
		$email    = $login . '@example.com';
		$user_id  = \wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => 'component-fuzz-pass',
				'user_email' => $email,
				'role'       => 'subscriber',
			)
		);

		if ( ! is_int( $user_id ) ) {
			return self::row(
				$ctx,
				'account-security.application-password.authentication-flow',
				false,
				array(
					'stage'  => 'insert-user',
					'result' => self::describe_value( $user_id ),
				)
			);
		}

		$self_ip            = self::ip_case( $ctx->fork( 'success-ip' ) );
		$api_request        = false;
		$site_available     = true;
		$user_available     = true;
		$reject_valid_match = false;
		$did_authenticate   = array();
		$failed_auth        = array();
		$password_checks    = array();
		$added_email_filter = false;

		$api_request_filter = static function () use ( &$api_request ): bool {
			return $api_request;
		};
		$site_available_filter = static function () use ( &$site_available ): bool {
			return $site_available;
		};
		$user_available_filter = static function ( $available, $user ) use ( &$user_available, $user_id ): bool {
			unset( $available );
			return $user instanceof \WP_User && $user->ID === $user_id && $user_available;
		};
		$did_authenticate_action = static function ( $user, $item ) use ( &$did_authenticate ): void {
			$did_authenticate[] = array(
				'userId' => $user instanceof \WP_User ? $user->ID : null,
				'uuid'   => is_array( $item ) ? ( $item['uuid'] ?? null ) : null,
			);
		};
		$failed_auth_action = static function ( $error ) use ( &$failed_auth ): void {
			$failed_auth[] = \is_wp_error( $error ) ? $error->get_error_code() : get_debug_type( $error );
		};
		$password_error_action = static function ( $error, $user, $item, $password ) use ( &$password_checks, &$reject_valid_match, $user_id ): void {
			$password_checks[] = array(
				'userId'   => $user instanceof \WP_User ? $user->ID : null,
				'uuid'     => is_array( $item ) ? ( $item['uuid'] ?? null ) : null,
				'password' => $password,
			);

			if ( $reject_valid_match && $error instanceof \WP_Error && $user instanceof \WP_User && $user->ID === $user_id ) {
				$error->add( 'component_fuzz_rejected', 'Synthetic application password constraint rejected the login.' );
			}
		};

		\add_filter( 'application_password_is_api_request', $api_request_filter );
		\add_filter( 'wp_is_application_passwords_available', $site_available_filter );
		\add_filter( 'wp_is_application_passwords_available_for_user', $user_available_filter, 10, 2 );
		\add_action( 'application_password_did_authenticate', $did_authenticate_action, 10, 2 );
		\add_action( 'application_password_failed_authentication', $failed_auth_action );
		\add_action( 'wp_authenticate_application_password_errors', $password_error_action, 10, 4 );

		try {
			if ( false === \has_filter( 'is_email', 'wp_is_ascii_email' ) && false === \has_filter( 'is_email', 'wp_is_unicode_email' ) ) {
				\add_filter( 'is_email', 'wp_is_ascii_email', 10, 3 );
				$added_email_filter = true;
			}

			self::$application_passwords[ $user_id ] = array();
			\delete_option( \WP_Application_Passwords::OPTION_KEY_IN_USE );
			\wp_cache_delete( \WP_Application_Passwords::OPTION_KEY_IN_USE, 'options' );

			$created = \WP_Application_Passwords::create_new_application_password(
				$user_id,
				array(
					'name'   => self::name_case( $ctx->fork( 'auth-app-name' ) )['raw'],
					'app_id' => self::app_id_case( $ctx->fork( 'auth-app-id' ) ),
				)
			);

			$plain_password = is_array( $created ) && isset( $created[0] ) ? (string) $created[0] : '';
			$item           = is_array( $created ) && isset( $created[1] ) && is_array( $created[1] ) ? $created[1] : array();
			$uuid           = (string) ( $item['uuid'] ?? '' );
			$chunked        = \WP_Application_Passwords::chunk_password( $plain_password );
			$spaced         = " \t" . $chunked . "\n ";
			$wrong_password = self::mutate_secret( $plain_password );

			self::collect_failure(
				$failures,
				'' !== $plain_password && '' !== $uuid && \WP_Application_Passwords::is_in_use(),
				'application password fixture is created and marks application passwords in use',
				array(
					'created' => self::describe_value( $created ),
					'inUse'   => \WP_Application_Passwords::is_in_use(),
				)
			);

			$non_api = \wp_authenticate_application_password( null, $login, $spaced );
			self::collect_failure(
				$failures,
				null === $non_api && array() === $did_authenticate && array() === $failed_auth && array() === $password_checks,
				'non-API requests return the incoming null user without checking stored passwords or firing auth hooks',
				array(
					'result'         => self::describe_value( $non_api ),
					'didAuthenticate' => self::describe_value( $did_authenticate ),
					'failedAuth'     => self::describe_value( $failed_auth ),
					'passwordChecks' => self::describe_value( $password_checks ),
				)
			);

			$api_request            = true;
			$_SERVER['REMOTE_ADDR'] = $self_ip;
			$auth_start             = time();
			$success                = \wp_authenticate_application_password( null, $login, $spaced );
			$auth_finished          = time();
			$used_item              = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
			$first_did_count        = count( $did_authenticate );
			$first_failed_count     = count( $failed_auth );

			self::collect_failure(
				$failures,
				$success instanceof \WP_User
					&& $success->ID === $user_id
					&& 1 === $first_did_count
					&& 0 === $first_failed_count
					&& isset( $did_authenticate[0]['uuid'] )
					&& $uuid === $did_authenticate[0]['uuid']
					&& isset( $password_checks[0]['password'] )
					&& $plain_password === $password_checks[0]['password']
					&& is_array( $used_item )
					&& is_int( $used_item['last_used'] ?? null )
					&& $used_item['last_used'] >= $auth_start
					&& $used_item['last_used'] <= $auth_finished
					&& $self_ip === ( $used_item['last_ip'] ?? null ),
				'API authentication strips readable chunking, fires success hooks, and records first-use IP/time',
				array(
					'success'         => self::describe_value( $success ),
					'uuid'            => $uuid,
					'didAuthenticate' => self::describe_value( $did_authenticate ),
					'failedAuth'      => self::describe_value( $failed_auth ),
					'passwordChecks'  => self::describe_value( $password_checks ),
					'usedItem'        => self::describe_value( $used_item ),
					'authStart'       => $auth_start,
					'authFinished'    => $auth_finished,
				)
			);

			$email_success        = \wp_authenticate_application_password( null, $email, $spaced );
			$accepted_did_count   = count( $did_authenticate );
			$last_success_event   = end( $did_authenticate );
			$last_success_event   = is_array( $last_success_event ) ? $last_success_event : array();
			$password_check_count = count( $password_checks );

			self::collect_failure(
				$failures,
				$email_success instanceof \WP_User
					&& $email_success->ID === $user_id
					&& $first_did_count + 1 === $accepted_did_count
					&& $uuid === ( $last_success_event['uuid'] ?? null )
					&& 2 === $password_check_count,
				'API authentication also resolves users through the email fallback branch',
				array(
					'email'           => self::describe_string( $email ),
					'emailSuccess'    => self::describe_value( $email_success ),
					'didAuthenticate' => self::describe_value( $did_authenticate ),
					'passwordChecks'  => self::describe_value( $password_checks ),
				)
			);

			$wrong = \wp_authenticate_application_password( null, $login, $wrong_password );
			self::collect_failure(
				$failures,
				self::is_error_code( $wrong, 'incorrect_password' )
					&& in_array( 'incorrect_password', $failed_auth, true )
					&& $accepted_did_count === count( $did_authenticate ),
				'incorrect passwords fail closed and fire only the failure hook',
				array(
					'wrong'           => self::describe_value( $wrong ),
					'didAuthenticate' => self::describe_value( $did_authenticate ),
					'failedAuth'      => self::describe_value( $failed_auth ),
				)
			);

			$reject_valid_match = true;
			$rejected           = \wp_authenticate_application_password( null, $login, $spaced );
			$reject_valid_match = false;
			self::collect_failure(
				$failures,
				self::is_error_code( $rejected, 'component_fuzz_rejected' )
					&& in_array( 'component_fuzz_rejected', $failed_auth, true )
					&& $accepted_did_count === count( $did_authenticate ),
				'constraint hook can reject a matched password before usage is accepted',
				array(
					'rejected'        => self::describe_value( $rejected ),
					'didAuthenticate' => self::describe_value( $did_authenticate ),
					'failedAuth'      => self::describe_value( $failed_auth ),
					'passwordChecks'  => self::describe_value( $password_checks ),
				)
			);

			$failure_hook_start            = count( $failed_auth );
			$success_count_before_failures = count( $did_authenticate );
			$check_count_before_failures   = count( $password_checks );
			$site_available                = false;
			$site_disabled                 = \wp_authenticate_application_password( null, $login, $spaced );
			$site_available                = true;
			$user_available                = false;
			$user_disabled                 = \wp_authenticate_application_password( null, $login, $spaced );
			$user_available                = true;
			$unknown_email                 = \wp_authenticate_application_password( null, 'missing@example.com', $spaced );
			$unknown_login                 = \wp_authenticate_application_password( null, 'missing account', $spaced );
			$failure_hook_sequence         = array_slice( $failed_auth, $failure_hook_start );

			self::collect_failure(
				$failures,
				self::is_error_code( $site_disabled, 'application_passwords_disabled' )
					&& self::is_error_code( $user_disabled, 'application_passwords_disabled_for_user' )
					&& self::is_error_code( $unknown_email, 'invalid_email' )
					&& self::is_error_code( $unknown_login, 'invalid_username' )
					&& array(
						'application_passwords_disabled',
						'application_passwords_disabled_for_user',
						'invalid_email',
						'invalid_username',
					) === $failure_hook_sequence
					&& $success_count_before_failures === count( $did_authenticate )
					&& $check_count_before_failures === count( $password_checks ),
				'availability and identity failures return documented error codes and failure-hook payloads',
				array(
					'siteDisabled'       => self::describe_value( $site_disabled ),
					'userDisabled'       => self::describe_value( $user_disabled ),
					'unknownEmail'       => self::describe_value( $unknown_email ),
					'unknownLogin'       => self::describe_value( $unknown_login ),
					'failureHookSequence' => self::describe_value( $failure_hook_sequence ),
					'didAuthenticate'    => self::describe_value( $did_authenticate ),
					'passwordChecks'     => self::describe_value( $password_checks ),
				)
			);

			$_SERVER['PHP_AUTH_USER'] = $email;
			$_SERVER['PHP_AUTH_PW']   = $spaced;
			$validated                = \wp_validate_application_password( false );
			$already_validated        = \wp_validate_application_password( $user_id );
			unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );

			self::collect_failure(
				$failures,
				$user_id === $validated && $user_id === $already_validated && $accepted_did_count + 1 === count( $did_authenticate ),
				'server Basic Auth credentials validate to the user ID and non-empty input users short-circuit',
				array(
					'validated'        => self::describe_value( $validated ),
					'alreadyValidated' => self::describe_value( $already_validated ),
					'didAuthenticate'  => self::describe_value( $did_authenticate ),
					'failedAuth'       => self::describe_value( $failed_auth ),
				)
			);
		} finally {
			\remove_filter( 'application_password_is_api_request', $api_request_filter );
			\remove_filter( 'wp_is_application_passwords_available', $site_available_filter );
			\remove_filter( 'wp_is_application_passwords_available_for_user', $user_available_filter, 10 );
			\remove_action( 'application_password_did_authenticate', $did_authenticate_action, 10 );
			\remove_action( 'application_password_failed_authentication', $failed_auth_action, 10 );
			\remove_action( 'wp_authenticate_application_password_errors', $password_error_action, 10 );
			if ( $added_email_filter ) {
				\remove_filter( 'is_email', 'wp_is_ascii_email', 10 );
			}
			unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		}

		return self::row(
			$ctx,
			'account-security.application-password.authentication-flow',
			array() === $failures,
			array(
				'userId'          => $user_id,
				'login'           => $login,
				'failures'        => array_slice( $failures, 0, 5 ),
				'successEvents'   => count( $did_authenticate ),
				'failureEvents'   => $failed_auth,
				'constraintCalls' => count( $password_checks ),
			)
		);
	}

	private static function check_password_reset_key_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::can_reset_stub_content() ) {
			return self::skip(
				$ctx,
				'account-security.password-reset-key.lifecycle-and-fail-closed-paths',
				'The in-memory wpdb content reset hook is unavailable.'
			);
		}

		$failures             = array();
		$user_id              = 0;
		$login                = self::login_case( $ctx->fork( 'login' ) );
		$email                = $login . '@example.test';
		$password             = 'component-fuzz-reset-' . self::token( $ctx->fork( 'password' ), 16 );
		$expiration_duration  = 300 + $ctx->int( 0, DAY_IN_SECONDS );
		$allow_decision       = true;
		$expired_override     = false;
		$retrieve_events      = array();
		$key_events           = array();
		$allow_events         = array();
		$expiration_events    = array();
		$expired_key_events   = array();
		$filters_restored     = false;
		$content_counts_after = null;

		$retrieve_action = static function ( string $user_login ) use ( &$retrieve_events ): void {
			$retrieve_events[] = $user_login;
		};
		$key_action      = static function ( string $user_login, string $key ) use ( &$key_events ): void {
			$key_events[] = array(
				'login' => $user_login,
				'key'   => $key,
			);
		};
		$allow_filter    = static function ( $allow, int $candidate_user_id ) use ( &$allow_events, &$allow_decision, &$user_id ) {
			$allow_events[] = array(
				'incoming' => $allow,
				'userId'   => $candidate_user_id,
			);

			if ( $candidate_user_id !== $user_id ) {
				return $allow;
			}

			return $allow_decision;
		};
		$expiration_filter = static function ( int $duration ) use ( &$expiration_events, &$expiration_duration ): int {
			$expiration_events[] = $duration;
			return $expiration_duration;
		};
		$expired_key_filter = static function ( $return, int $candidate_user_id ) use ( &$expired_key_events, &$expired_override, &$user_id ) {
			$expired_key_events[] = array(
				'code'   => $return instanceof \WP_Error ? $return->get_error_code() : get_debug_type( $return ),
				'userId' => $candidate_user_id,
			);

			if ( $expired_override && $candidate_user_id === $user_id ) {
				$user = \get_user_by( 'id', $user_id );
				if ( $user instanceof \WP_User ) {
					return $user;
				}
			}

			return $return;
		};

		self::reset_stub_content();

		try {
			$invalid_user_result = \get_password_reset_key( new \stdClass() );
			$missing_allowed     = \wp_is_password_reset_allowed_for_user( 0 );

			self::collect_failure(
				$failures,
				self::is_error_code( $invalid_user_result, 'invalidcombo' ) && false === $missing_allowed,
				'password reset helpers fail closed for non-user inputs before reset hooks run',
				array(
					'invalidUserResult' => self::describe_value( $invalid_user_result ),
					'missingAllowed'    => self::describe_value( $missing_allowed ),
				)
			);

			$inserted = \wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => $password,
					'user_email' => $email,
					'role'       => 'subscriber',
				)
			);

			if ( is_int( $inserted ) ) {
				$user_id = $inserted;
			}

			$user = is_int( $inserted ) ? \get_user_by( 'id', $inserted ) : false;
			self::collect_failure(
				$failures,
				is_int( $inserted )
					&& $user instanceof \WP_User
					&& $login === $user->user_login
					&& $email === $user->user_email,
				'synthetic reset user is created in the in-memory wpdb stub',
				array(
					'inserted' => self::describe_value( $inserted ),
					'user'     => self::describe_value( $user ),
					'login'    => self::describe_string( $login ),
					'email'    => self::describe_string( $email ),
				)
			);

			if ( $user instanceof \WP_User ) {
				\add_action( 'retrieve_password', $retrieve_action );
				\add_action( 'retrieve_password_key', $key_action, 10, 2 );
				\add_filter( 'allow_password_reset', $allow_filter, 10, 2 );
				\add_filter( 'password_reset_expiration', $expiration_filter );
				\add_filter( 'password_reset_key_expired', $expired_key_filter, 10, 2 );

				$allowed_by_object = \wp_is_password_reset_allowed_for_user( $user );
				$allowed_by_id     = \wp_is_password_reset_allowed_for_user( $user_id );

				self::collect_failure(
					$failures,
					true === $allowed_by_object
						&& true === $allowed_by_id
						&& 2 === count( $allow_events )
						&& array( $user_id, $user_id ) === array_column( $allow_events, 'userId' ),
					'password reset availability accepts both WP_User and user ID for the target account',
					array(
						'allowedByObject' => self::describe_value( $allowed_by_object ),
						'allowedById'     => self::describe_value( $allowed_by_id ),
						'allowEvents'     => self::describe_value( $allow_events ),
					)
				);

				$allow_decision = false;
				$denied         = \get_password_reset_key( $user );
				$allow_decision = new \WP_Error( 'component_fuzz_reset_blocked', 'Synthetic reset policy rejected the account.' );
				$custom_denied  = \get_password_reset_key( $user );
				$allow_decision = true;

				self::collect_failure(
					$failures,
					self::is_error_code( $denied, 'no_password_reset' )
						&& self::is_error_code( $custom_denied, 'component_fuzz_reset_blocked' )
						&& array() === $key_events
						&& array( $login, $login ) === array_slice( $retrieve_events, -2 ),
					'allow_password_reset can reject key generation with default and custom errors before keys are stored',
					array(
						'denied'         => self::describe_value( $denied ),
						'customDenied'   => self::describe_value( $custom_denied ),
						'keyEvents'      => self::describe_value( $key_events ),
						'retrieveEvents' => self::describe_value( $retrieve_events ),
					)
				);

				$key_started = time();
				$key         = \get_password_reset_key( $user );
				$key_finished = time();
				$stored_user  = \get_user_by( 'id', $user_id );
				$stored_key   = $stored_user instanceof \WP_User ? (string) $stored_user->user_activation_key : '';
				$key_parts    = explode( ':', $stored_key, 2 );
				$key_time     = isset( $key_parts[0] ) && ctype_digit( $key_parts[0] ) ? (int) $key_parts[0] : 0;
				$key_hash     = (string) ( $key_parts[1] ?? '' );

				self::collect_failure(
					$failures,
					is_string( $key )
						&& 1 === preg_match( '/\A[A-Za-z0-9]{20}\z/', $key )
						&& 2 === count( $key_parts )
						&& $stored_key !== $key
						&& $key_time >= $key_started
						&& $key_time <= $key_finished
						&& str_starts_with( $key_hash, '$generic$' )
						&& \wp_verify_fast_hash( $key, $key_hash )
						&& 1 === count( $key_events )
						&& $login === ( $key_events[0]['login'] ?? null )
						&& $key === ( $key_events[0]['key'] ?? null ),
					'generated password reset keys are bounded, action-visible, and stored only as timestamped fast hashes',
					array(
						'key'         => self::describe_value( $key ),
						'storedKey'   => self::describe_string( $stored_key ),
						'keyParts'    => self::describe_value( $key_parts ),
						'keyEvents'   => self::describe_value( $key_events ),
						'keyStarted'  => $key_started,
						'keyFinished' => $key_finished,
					)
				);

				$decorated_key = is_string( $key ) ? trim( chunk_split( $key, 4, ' - ' ), " -\t\n\r\0\x0B" ) : '';
				$exact_result  = is_string( $key ) ? \check_password_reset_key( $key, $login ) : null;
				$spaced_result = '' !== $decorated_key ? \check_password_reset_key( $decorated_key, $login ) : null;
				$wrong_key     = is_string( $key ) ? \check_password_reset_key( self::mutate_secret( $key ), $login ) : null;
				$wrong_login   = is_string( $key ) ? \check_password_reset_key( $key, strtoupper( $login ) ) : null;
				$empty_key     = \check_password_reset_key( " \t-|", $login );
				$empty_login   = is_string( $key ) ? \check_password_reset_key( $key, '' ) : null;

				self::collect_failure(
					$failures,
					$exact_result instanceof \WP_User
						&& $exact_result->ID === $user_id
						&& $spaced_result instanceof \WP_User
						&& $spaced_result->ID === $user_id
						&& self::is_error_code( $wrong_key, 'invalid_key' )
						&& self::is_error_code( $wrong_login, 'invalid_key' )
						&& self::is_error_code( $empty_key, 'invalid_key' )
						&& self::is_error_code( $empty_login, 'invalid_key' ),
					'reset key validation accepts normalized key punctuation and rejects wrong keys/logins/empties',
					array(
						'decoratedKey' => self::describe_string( $decorated_key ),
						'exactResult'  => self::describe_value( $exact_result ),
						'spacedResult' => self::describe_value( $spaced_result ),
						'wrongKey'     => self::describe_value( $wrong_key ),
						'wrongLogin'   => self::describe_value( $wrong_login ),
						'emptyKey'     => self::describe_value( $empty_key ),
						'emptyLogin'   => self::describe_value( $empty_login ),
					)
				);

				$expired_key     = self::token( $ctx->fork( 'expired-key' ), 20 );
				$expired_started = time() - $expiration_duration - $ctx->int( 1, 120 );
				\wp_update_user(
					array(
						'ID'                  => $user_id,
						'user_activation_key' => $expired_started . ':' . \wp_fast_hash( $expired_key ),
					)
				);
				$expired_result = \check_password_reset_key( $expired_key, $login );

				$legacy_key = self::token( $ctx->fork( 'legacy-key' ), 20 );
				\wp_update_user(
					array(
						'ID'                  => $user_id,
						'user_activation_key' => $legacy_key,
					)
				);
				$legacy_expired  = \check_password_reset_key( $legacy_key, $login );
				$expired_override = true;
				$legacy_override = \check_password_reset_key( $legacy_key, $login );

				$timeless_key = self::token( $ctx->fork( 'timeless-key' ), 20 );
				\wp_update_user(
					array(
						'ID'                  => $user_id,
						'user_activation_key' => \wp_fast_hash( $timeless_key ),
					)
				);
				$timeless_override = \check_password_reset_key( $timeless_key, $login );
				$expired_override  = false;

				self::collect_failure(
					$failures,
					self::is_error_code( $expired_result, 'expired_key' )
						&& self::is_error_code( $legacy_expired, 'expired_key' )
						&& $legacy_override instanceof \WP_User
						&& $legacy_override->ID === $user_id
						&& $timeless_override instanceof \WP_User
						&& $timeless_override->ID === $user_id
						&& count( $expired_key_events ) >= 3
						&& in_array( $user_id, array_column( $expired_key_events, 'userId' ), true ),
					'expired current hashes fail closed while legacy plaintext/timeless hashes flow through the expired-key filter',
					array(
						'expirationDuration' => $expiration_duration,
						'expiredStarted'     => $expired_started,
						'expiredResult'      => self::describe_value( $expired_result ),
						'legacyExpired'      => self::describe_value( $legacy_expired ),
						'legacyOverride'     => self::describe_value( $legacy_override ),
						'timelessOverride'   => self::describe_value( $timeless_override ),
						'expiredKeyEvents'   => self::describe_value( $expired_key_events ),
					)
				);

				$new_key      = \get_password_reset_key( $user );
				$old_after_new = is_string( $key ) ? \check_password_reset_key( $key, $login ) : null;
				$new_result   = is_string( $new_key ) ? \check_password_reset_key( $new_key, $login ) : null;

				self::collect_failure(
					$failures,
					is_string( $new_key )
						&& $new_key !== $key
						&& self::is_error_code( $old_after_new, 'invalid_key' )
						&& $new_result instanceof \WP_User
						&& $new_result->ID === $user_id,
					'new password reset keys replace older keys for the same account',
					array(
						'oldKey'      => self::describe_value( $key ),
						'newKey'      => self::describe_value( $new_key ),
						'oldAfterNew' => self::describe_value( $old_after_new ),
						'newResult'   => self::describe_value( $new_result ),
					)
				);
			}
		} finally {
			\remove_action( 'retrieve_password', $retrieve_action );
			\remove_action( 'retrieve_password_key', $key_action, 10 );
			\remove_filter( 'allow_password_reset', $allow_filter, 10 );
			\remove_filter( 'password_reset_expiration', $expiration_filter );
			\remove_filter( 'password_reset_key_expired', $expired_key_filter, 10 );

			$filters_restored = false === \has_filter( 'retrieve_password', $retrieve_action )
				&& false === \has_filter( 'retrieve_password_key', $key_action )
				&& false === \has_filter( 'allow_password_reset', $allow_filter )
				&& false === \has_filter( 'password_reset_expiration', $expiration_filter )
				&& false === \has_filter( 'password_reset_key_expired', $expired_key_filter );

			self::reset_stub_content();
			$content_counts_after = self::stub_content_counts();
		}

		self::collect_failure(
			$failures,
			$filters_restored
				&& is_array( $content_counts_after )
				&& 0 === array_sum( array_map( 'intval', $content_counts_after ) ),
			'password reset key lifecycle restores scoped hooks and in-memory content state',
			array(
				'filtersRestored'    => $filters_restored,
				'contentCountsAfter' => self::describe_value( $content_counts_after ),
			)
		);

		return self::row(
			$ctx,
			'account-security.password-reset-key.lifecycle-and-fail-closed-paths',
			array() === $failures,
			array(
				'userId'           => $user_id,
				'login'            => $login,
				'expiration'       => $expiration_duration,
				'retrieveEvents'   => count( $retrieve_events ),
				'keyEvents'        => count( $key_events ),
				'allowEvents'      => count( $allow_events ),
				'expirationEvents' => count( $expiration_events ),
				'expiredEvents'    => count( $expired_key_events ),
				'failures'         => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_reset_password_composition( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::can_reset_stub_content() ) {
			return self::skip(
				$ctx,
				'account-security.reset-password.hooks-storage-and-repeat-replacement',
				'The in-memory wpdb content reset hook is unavailable.'
			);
		}

		$failures             = array();
		$user_id              = 0;
		$login                = self::login_case( $ctx->fork( 'login' ) );
		$email                = $login . '@example.test';
		$old_password         = 'component-fuzz-old-' . self::token( $ctx->fork( 'old-password' ), 16 );
		$new_password         = 'component-fuzz-new-' . self::token( $ctx->fork( 'new-password' ), 18 );
		$second_password      = 'component-fuzz-next-' . self::token( $ctx->fork( 'second-password' ), 18 );
		$activation_key       = 'component-fuzz-activation-' . self::token( $ctx->fork( 'activation-key' ), 20 );
		$reset_events         = array();
		$set_password_events  = array();
		$event_sequence       = array();
		$hooks_restored       = false;
		$content_counts_after = null;

		if ( $second_password === $new_password ) {
			$second_password = self::mutate_secret( $second_password );
		}

		$reset_action = static function ( \WP_User $user, string $new_pass ) use ( &$reset_events, &$event_sequence ): void {
			$fresh            = \get_userdata( $user->ID );
			$event_sequence[] = \current_filter();
			$reset_events[]   = array(
				'hook'               => \current_filter(),
				'userId'             => $user->ID,
				'userLogin'          => $user->user_login,
				'newPass'            => $new_pass,
				'storedHash'         => $fresh instanceof \WP_User ? $fresh->user_pass : null,
				'activationKey'      => $fresh instanceof \WP_User ? $fresh->user_activation_key : null,
				'defaultPasswordNag' => \get_user_meta( $user->ID, 'default_password_nag', true ),
			);
		};
		$set_password_action = static function ( string $password, int $candidate_user_id, $old_user_data ) use ( &$set_password_events, &$event_sequence ): void {
			$fresh                 = \get_userdata( $candidate_user_id );
			$event_sequence[]      = \current_filter();
			$set_password_events[] = array(
				'hook'               => \current_filter(),
				'userId'             => $candidate_user_id,
				'password'           => $password,
				'oldUserId'          => $old_user_data instanceof \WP_User ? $old_user_data->ID : null,
				'oldHash'            => $old_user_data instanceof \WP_User ? $old_user_data->user_pass : null,
				'oldActivationKey'   => $old_user_data instanceof \WP_User ? $old_user_data->user_activation_key : null,
				'storedHash'         => $fresh instanceof \WP_User ? $fresh->user_pass : null,
				'activationKey'      => $fresh instanceof \WP_User ? $fresh->user_activation_key : null,
				'defaultPasswordNag' => \get_user_meta( $candidate_user_id, 'default_password_nag', true ),
			);
		};

		self::reset_stub_content();

		try {
			$inserted = \wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => $old_password,
					'user_email' => $email,
					'role'       => 'subscriber',
				)
			);

			if ( is_int( $inserted ) ) {
				$user_id = $inserted;
				\update_user_meta( $user_id, 'default_password_nag', true );
				\wp_update_user(
					array(
						'ID'                  => $user_id,
						'user_activation_key' => $activation_key,
					)
				);
			}

			$user = is_int( $inserted ) ? \get_userdata( $inserted ) : false;
			self::collect_failure(
				$failures,
				is_int( $inserted )
					&& $user instanceof \WP_User
					&& $login === $user->user_login
					&& $email === $user->user_email
					&& \wp_check_password( $old_password, $user->user_pass, $user_id )
					&& $activation_key === $user->user_activation_key
					&& true === (bool) \get_user_meta( $user_id, 'default_password_nag', true ),
				'reset_password synthetic user starts with an old password, activation key, and default-password nag',
				array(
					'inserted'      => self::describe_value( $inserted ),
					'user'          => self::describe_value( $user ),
					'login'         => self::describe_string( $login ),
					'activationKey' => self::describe_string( $activation_key ),
					'nag'           => is_int( $inserted ) ? \get_user_meta( $inserted, 'default_password_nag', true ) : null,
				)
			);

			if ( $user instanceof \WP_User ) {
				\add_action( 'password_reset', $reset_action, 10, 2 );
				\add_action( 'wp_set_password', $set_password_action, 10, 3 );
				\add_action( 'after_password_reset', $reset_action, 10, 2 );

				\reset_password( $user, $new_password );
				$after_first = \get_userdata( $user_id );

				if ( $after_first instanceof \WP_User ) {
					\reset_password( $after_first, $second_password );
				}
				$after_second = \get_userdata( $user_id );

				self::collect_failure(
					$failures,
					array(
						'password_reset',
						'wp_set_password',
						'after_password_reset',
						'password_reset',
						'wp_set_password',
						'after_password_reset',
					) === $event_sequence,
					'reset_password fires reset, storage, and after-reset hooks in order for each reset call',
					array(
						'eventSequence'      => self::describe_value( $event_sequence ),
						'resetEvents'        => self::describe_value( $reset_events ),
						'setPasswordEvents'  => self::describe_value( $set_password_events ),
					)
				);

				self::collect_failure(
					$failures,
					4 === count( $reset_events )
						&& array( 'password_reset', 'after_password_reset', 'password_reset', 'after_password_reset' ) === array_column( $reset_events, 'hook' )
						&& array( $user_id, $user_id, $user_id, $user_id ) === array_column( $reset_events, 'userId' )
						&& array( $new_password, $new_password, $second_password, $second_password ) === array_column( $reset_events, 'newPass' ),
					'password_reset and after_password_reset expose the same target user and plaintext password payloads',
					array(
						'resetEvents'    => self::describe_value( $reset_events ),
						'newPassword'    => self::describe_string( $new_password ),
						'secondPassword' => self::describe_string( $second_password ),
					)
				);

				self::collect_failure(
					$failures,
					isset( $reset_events[0], $reset_events[1] )
						&& \wp_check_password( $old_password, (string) $reset_events[0]['storedHash'], $user_id )
						&& $activation_key === $reset_events[0]['activationKey']
						&& true === (bool) $reset_events[0]['defaultPasswordNag']
						&& \wp_check_password( $new_password, (string) $reset_events[1]['storedHash'], $user_id )
						&& ! \wp_check_password( $old_password, (string) $reset_events[1]['storedHash'], $user_id )
						&& '' === $reset_events[1]['activationKey']
						&& false === (bool) $reset_events[1]['defaultPasswordNag'],
					'password_reset sees pre-storage account state while after_password_reset sees replaced password and cleared nag state',
					array(
						'firstPasswordReset' => self::describe_value( $reset_events[0] ?? null ),
						'firstAfterReset'    => self::describe_value( $reset_events[1] ?? null ),
					)
				);

				self::collect_failure(
					$failures,
					$after_first instanceof \WP_User
						&& \wp_check_password( $new_password, $after_first->user_pass, $user_id )
						&& ! \wp_check_password( $old_password, $after_first->user_pass, $user_id )
						&& '' === $after_first->user_activation_key
						&& false === (bool) \get_user_meta( $user_id, 'default_password_nag', true ),
					'first reset replaces the stored hash, invalidates the previous password, clears activation key, and clears default-password nag',
					array(
						'afterFirst'  => self::describe_value( $after_first ),
						'currentNag'  => \get_user_meta( $user_id, 'default_password_nag', true ),
					)
				);

				self::collect_failure(
					$failures,
					$after_second instanceof \WP_User
						&& \wp_check_password( $second_password, $after_second->user_pass, $user_id )
						&& ! \wp_check_password( $new_password, $after_second->user_pass, $user_id )
						&& ! \wp_check_password( $old_password, $after_second->user_pass, $user_id )
						&& '' === $after_second->user_activation_key
						&& false === (bool) \get_user_meta( $user_id, 'default_password_nag', true ),
					'repeated reset_password calls replace the previous reset password without reviving activation or nag state',
					array(
						'afterSecond'    => self::describe_value( $after_second ),
						'secondPassword' => self::describe_string( $second_password ),
						'currentNag'     => \get_user_meta( $user_id, 'default_password_nag', true ),
					)
				);

				self::collect_failure(
					$failures,
					2 === count( $set_password_events )
						&& array( $new_password, $second_password ) === array_column( $set_password_events, 'password' )
						&& array( $user_id, $user_id ) === array_column( $set_password_events, 'userId' )
						&& array( $user_id, $user_id ) === array_column( $set_password_events, 'oldUserId' )
						&& \wp_check_password( $old_password, (string) ( $set_password_events[0]['oldHash'] ?? '' ), $user_id )
						&& $activation_key === ( $set_password_events[0]['oldActivationKey'] ?? null )
						&& \wp_check_password( $new_password, (string) ( $set_password_events[1]['oldHash'] ?? '' ), $user_id )
						&& '' === ( $set_password_events[1]['oldActivationKey'] ?? null ),
					'reset_password delegates storage through wp_set_password with old-user snapshots from before each replacement',
					array(
						'setPasswordEvents' => self::describe_value( $set_password_events ),
						'oldPassword'       => self::describe_string( $old_password ),
						'activationKey'     => self::describe_string( $activation_key ),
					)
				);
			}
		} finally {
			\remove_action( 'password_reset', $reset_action, 10 );
			\remove_action( 'wp_set_password', $set_password_action, 10 );
			\remove_action( 'after_password_reset', $reset_action, 10 );

			$hooks_restored = false === \has_filter( 'password_reset', $reset_action )
				&& false === \has_filter( 'wp_set_password', $set_password_action )
				&& false === \has_filter( 'after_password_reset', $reset_action );

			self::reset_stub_content();
			$content_counts_after = self::stub_content_counts();
		}

		self::collect_failure(
			$failures,
			$hooks_restored
				&& is_array( $content_counts_after )
				&& 0 === array_sum( array_map( 'intval', $content_counts_after ) ),
			'reset_password composition restores scoped hooks and in-memory content state',
			array(
				'hooksRestored'      => $hooks_restored,
				'contentCountsAfter' => self::describe_value( $content_counts_after ),
			)
		);

		return self::row(
			$ctx,
			'account-security.reset-password.hooks-storage-and-repeat-replacement',
			array() === $failures,
			array(
				'userId'            => $user_id,
				'login'             => $login,
				'resetEvents'       => count( $reset_events ),
				'setPasswordEvents' => count( $set_password_events ),
				'eventSequence'     => $event_sequence,
				'failures'          => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_recovery_key_service( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$service  = new \WP_Recovery_Mode_Key_Service();
		$ttl      = 120 + $ctx->int( 0, 3600 );
		$token    = self::recovery_token_case( $ctx->fork( 'token' ) );

		\delete_option( 'recovery_keys' );
		\wp_cache_delete( 'recovery_keys', 'options' );

		$generated_token = $service->generate_recovery_mode_token();
		self::collect_failure(
			$failures,
			22 === strlen( $generated_token ) && ctype_alnum( $generated_token ),
			'generated recovery mode token is a 22-character alphanumeric token',
			array( 'token' => self::describe_string( $generated_token ) )
		);

		$key     = $service->generate_and_store_recovery_mode_key( $token );
		$records = self::recovery_key_records();
		self::collect_failure(
			$failures,
			is_string( $key )
				&& 22 === strlen( $key )
				&& isset( $records[ $token ]['hashed_key'], $records[ $token ]['created_at'] )
				&& str_starts_with( (string) $records[ $token ]['hashed_key'], '$generic$' )
				&& \wp_verify_fast_hash( $key, (string) $records[ $token ]['hashed_key'] )
				&& is_int( $records[ $token ]['created_at'] ),
			'generate_and_store records a generic hash and timestamp under the supplied token',
			array(
				'token'   => self::describe_string( $token ),
				'key'     => self::describe_string( $key ),
				'records' => self::describe_value( $records ),
			)
		);

		$wrong_key_result     = $service->validate_recovery_mode_key( $token, self::mutate_secret( $key ), $ttl );
		$correct_after_wrong  = $service->validate_recovery_mode_key( $token, $key, $ttl );
		$records_after_wrong  = self::recovery_key_records();
		$key_after_wrong_used = $service->generate_and_store_recovery_mode_key( $token );
		$correct_result       = $service->validate_recovery_mode_key( $token, $key_after_wrong_used, $ttl );
		$reused_result        = $service->validate_recovery_mode_key( $token, $key_after_wrong_used, $ttl );

		self::collect_failure(
			$failures,
			self::is_error_code( $wrong_key_result, 'hash_mismatch' )
				&& self::is_error_code( $correct_after_wrong, 'token_not_found' )
				&& ! isset( $records_after_wrong[ $token ] )
				&& true === $correct_result
				&& self::is_error_code( $reused_result, 'token_not_found' ),
			'wrong recovery keys fail and consume records; correct keys validate once',
			array(
				'token'             => self::describe_string( $token ),
				'wrongResult'       => self::describe_value( $wrong_key_result ),
				'correctAfterWrong' => self::describe_value( $correct_after_wrong ),
				'correctResult'     => self::describe_value( $correct_result ),
				'reusedResult'      => self::describe_value( $reused_result ),
			)
		);

		$expired_token = $token . '|expired';
		$expired_key   = $service->generate_and_store_recovery_mode_key( $expired_token );
		$records       = self::recovery_key_records();
		$records[ $expired_token ]['created_at'] = time() - $ttl - 10;
		\update_option( 'recovery_keys', $records, false );
		$expired_result = $service->validate_recovery_mode_key( $expired_token, $expired_key, $ttl );
		$after_expired  = self::recovery_key_records();

		$malformed_token             = $token . "|malformed\x80";
		$records                     = self::recovery_key_records();
		$records[ $malformed_token ] = array( 'created_at' => time() );
		\update_option( 'recovery_keys', $records, false );
		$malformed_result = $service->validate_recovery_mode_key( $malformed_token, 'anything', $ttl );
		$after_malformed  = self::recovery_key_records();

		self::collect_failure(
			$failures,
			self::is_error_code( $expired_result, 'key_expired' )
				&& ! isset( $after_expired[ $expired_token ] )
				&& self::is_error_code( $malformed_result, 'invalid_recovery_key_format' )
				&& ! isset( $after_malformed[ $malformed_token ] ),
			'expired and malformed recovery key records fail closed and are removed',
			array(
				'expiredToken'    => self::describe_string( $expired_token ),
				'expiredResult'   => self::describe_value( $expired_result ),
				'afterExpired'    => self::describe_value( $after_expired ),
				'malformedToken'  => self::describe_string( $malformed_token ),
				'malformedResult' => self::describe_value( $malformed_result ),
				'afterMalformed'  => self::describe_value( $after_malformed ),
			)
		);

		$fresh_token   = $token . '|fresh';
		$old_token     = $token . '|old';
		$missing_token = $token . '|missing-created';
		\update_option(
			'recovery_keys',
			array(
				$fresh_token   => array(
					'hashed_key' => \wp_fast_hash( 'fresh-key' ),
					'created_at' => time(),
				),
				$old_token     => array(
					'hashed_key' => \wp_fast_hash( 'old-key' ),
					'created_at' => time() - $ttl - 1,
				),
				$missing_token => array(
					'hashed_key' => \wp_fast_hash( 'missing-key' ),
				),
			),
			false
		);
		$service->clean_expired_keys( $ttl );
		$after_clean = self::recovery_key_records();

		self::collect_failure(
			$failures,
			isset( $after_clean[ $fresh_token ] )
				&& ! isset( $after_clean[ $old_token ] )
				&& ! isset( $after_clean[ $missing_token ] )
				&& 1 === count( $after_clean ),
			'clean_expired_keys preserves only unexpired well-formed records',
			array(
				'freshToken'   => self::describe_string( $fresh_token ),
				'oldToken'     => self::describe_string( $old_token ),
				'missingToken' => self::describe_string( $missing_token ),
				'afterClean'   => self::describe_value( $after_clean ),
			)
		);

		return self::row(
			$ctx,
			'account-security.recovery-key-service.single-use-expiry-cleanup',
			array() === $failures,
			array(
				'ttl'      => $ttl,
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_recovery_cookie_service( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$service  = new \WP_Recovery_Mode_Cookie_Service();

		$generate_cookie = new \ReflectionMethod( \WP_Recovery_Mode_Cookie_Service::class, 'generate_cookie' );
		$recovery_hash = new \ReflectionMethod( \WP_Recovery_Mode_Cookie_Service::class, 'recovery_mode_hash' );

		$cookie  = (string) $generate_cookie->invoke( $service );
		$decoded = base64_decode( $cookie, true );
		$parts   = false === $decoded ? array() : explode( '|', $decoded );

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
			'generated recovery cookie has four signed parts and validates to the random session id',
			array(
				'cookie'  => self::describe_string( $cookie ),
				'decoded' => self::describe_string( is_string( $decoded ) ? $decoded : '' ),
				'parts'   => self::describe_value( $parts ),
			)
		);

		$_COOKIE[ RECOVERY_MODE_COOKIE ] = $cookie;
		self::collect_failure(
			$failures,
			$service->is_cookie_set()
				&& true === $service->validate_cookie()
				&& sha1( $parts[2] ?? '' ) === $service->get_session_id_from_cookie(),
			'cookie service validates and reads from the scoped RECOVERY_MODE_COOKIE superglobal',
			array(
				'cookieName' => RECOVERY_MODE_COOKIE,
				'cookieSet'  => $service->is_cookie_set(),
				'sessionId'  => self::describe_value( $service->get_session_id_from_cookie() ),
			)
		);
		unset( $_COOKIE[ RECOVERY_MODE_COOKIE ] );

		$tampered_parts      = $parts;
		$tampered_parts[3]   = self::mutate_hex( (string) ( $tampered_parts[3] ?? '' ) );
		$tampered_cookie     = base64_encode( implode( '|', $tampered_parts ) );
		$expired_created     = (string) ( time() - WEEK_IN_SECONDS - 10 );
		$expired_random      = 'expired-' . self::token( $ctx->fork( 'expired-random' ), 12 );
		$expired_to_sign     = sprintf( 'recovery_mode|%s|%s', $expired_created, $expired_random );
		$expired_signature   = (string) $recovery_hash->invoke( $service, $expired_to_sign );
		$expired_cookie      = base64_encode( sprintf( '%s|%s', $expired_to_sign, $expired_signature ) );
		$invalid_format      = base64_encode( 'one|two|three' );
		$invalid_created_at  = base64_encode( 'recovery_mode|not-digits|random|signature' );
		$no_cookie_result    = $service->validate_cookie();
		$tampered_result     = $service->validate_cookie( $tampered_cookie );
		$expired_result      = $service->validate_cookie( $expired_cookie );
		$format_result       = $service->validate_cookie( $invalid_format );
		$created_at_result   = $service->validate_cookie( $invalid_created_at );
		$malformed_session   = $service->get_session_id_from_cookie( $invalid_format );

		self::collect_failure(
			$failures,
			self::is_error_code( $no_cookie_result, 'no_cookie' )
				&& self::is_error_code( $tampered_result, 'signature_mismatch' )
				&& self::is_error_code( $expired_result, 'expired' )
				&& self::is_error_code( $format_result, 'invalid_format' )
				&& self::is_error_code( $created_at_result, 'invalid_created_at' )
				&& self::is_error_code( $malformed_session, 'invalid_format' ),
			'recovery cookie validation rejects absent, tampered, expired, and malformed cookies',
			array(
				'noCookie'         => self::describe_value( $no_cookie_result ),
				'tampered'         => self::describe_value( $tampered_result ),
				'expired'          => self::describe_value( $expired_result ),
				'invalidFormat'    => self::describe_value( $format_result ),
				'invalidCreatedAt' => self::describe_value( $created_at_result ),
				'malformedSession' => self::describe_value( $malformed_session ),
			)
		);

		$wrong_prefix_accepted = null;
		if ( 4 === count( $parts ) ) {
			$wrong_prefix_parts    = $parts;
			$wrong_prefix_parts[0] = 'not_recovery_mode';
			$wrong_prefix_cookie   = base64_encode( implode( '|', $wrong_prefix_parts ) );
			$wrong_prefix_accepted = true === $service->validate_cookie( $wrong_prefix_cookie );
		}

		return self::row(
			$ctx,
			'account-security.recovery-cookie-service.shape-validation',
			array() === $failures,
			array(
				'wrongPrefixAccepted' => $wrong_prefix_accepted,
				'failures'            => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_paused_extension_storage( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$mode_snapshot = self::snapshot_recovery_mode_state();
		$session_id    = 'component_fuzz_' . self::token( $ctx->fork( 'session' ), 20 );
		$option_name   = $session_id . '_paused_extensions';

		try {
			self::set_recovery_mode_state( true, $session_id );
			\delete_option( $option_name );
			\wp_cache_delete( $option_name, 'options' );

			$plugin_storage = new \WP_Paused_Extensions_Storage( 'plugin' );
			$theme_storage  = new \WP_Paused_Extensions_Storage( 'theme' );
			$plugin         = 'plugin-' . self::token( $ctx->fork( 'plugin' ), 8 ) . "/main\x80.php";
			$theme          = 'theme-' . self::token( $ctx->fork( 'theme' ), 8 );
			$plugin_error   = self::extension_error_case( $ctx->fork( 'plugin-error' ), WP_PLUGIN_DIR . '/' . $plugin );
			$plugin_error_2 = self::extension_error_case( $ctx->fork( 'plugin-error-2' ), WP_PLUGIN_DIR . '/' . $plugin );
			$theme_error    = self::extension_error_case( $ctx->fork( 'theme-error' ), WP_CONTENT_DIR . '/themes/' . $theme . '/functions.php' );

			$set_plugin      = $plugin_storage->set( $plugin, $plugin_error );
			$set_plugin_same = $plugin_storage->set( $plugin, $plugin_error );
			$set_theme       = $theme_storage->set( $theme, $theme_error );
			$set_plugin_2    = $plugin_storage->set( $plugin, $plugin_error_2 );
			$raw_option      = \get_option( $option_name, array() );

			self::collect_failure(
				$failures,
				true === $set_plugin
					&& true === $set_plugin_same
					&& true === $set_theme
					&& true === $set_plugin_2
					&& $plugin_error_2 === $plugin_storage->get( $plugin )
					&& $theme_error === $theme_storage->get( $theme )
					&& null === $theme_storage->get( $plugin )
					&& null === $plugin_storage->get( $theme )
					&& array( $plugin => $plugin_error_2 ) === $plugin_storage->get_all()
					&& array( $theme => $theme_error ) === $theme_storage->get_all()
					&& isset( $raw_option['plugin'][ $plugin ], $raw_option['theme'][ $theme ] ),
				'paused extension storage round-trips structured errors and isolates plugin/theme types',
				array(
					'optionName'    => $option_name,
					'plugin'        => self::describe_string( $plugin ),
					'theme'         => self::describe_string( $theme ),
					'pluginAll'     => self::describe_value( $plugin_storage->get_all() ),
					'themeAll'      => self::describe_value( $theme_storage->get_all() ),
					'rawOption'     => self::describe_value( $raw_option ),
					'pluginError'   => self::describe_value( $plugin_error ),
					'pluginError2'  => self::describe_value( $plugin_error_2 ),
					'themeError'    => self::describe_value( $theme_error ),
				)
			);

			$delete_missing     = $plugin_storage->delete( 'missing|' . self::random_string( $ctx->fork( 'missing-extension' ), 12 ) );
			$delete_plugin      = $plugin_storage->delete( $plugin );
			$after_delete       = \get_option( $option_name, array() );
			$reset_plugin       = $plugin_storage->set( $plugin, $plugin_error );
			$delete_plugin_all  = $plugin_storage->delete_all();
			$after_plugin_all   = \get_option( $option_name, array() );
			$delete_theme_all   = $theme_storage->delete_all();
			$after_theme_all    = \get_option( $option_name, false );

			self::collect_failure(
				$failures,
				true === $delete_missing
					&& true === $delete_plugin
					&& null === $plugin_storage->get( $plugin )
					&& isset( $after_delete['theme'][ $theme ] )
					&& ! isset( $after_delete['plugin'][ $plugin ] )
					&& true === $reset_plugin
					&& true === $delete_plugin_all
					&& isset( $after_plugin_all['theme'][ $theme ] )
					&& ! isset( $after_plugin_all['plugin'][ $plugin ] )
					&& true === $delete_theme_all
					&& false === $after_theme_all,
				'paused extension delete/delete_all remove only the selected type and clean the option when empty',
				array(
					'deleteMissing'   => $delete_missing,
					'deletePlugin'    => $delete_plugin,
					'afterDelete'     => self::describe_value( $after_delete ),
					'resetPlugin'     => $reset_plugin,
					'deletePluginAll' => $delete_plugin_all,
					'afterPluginAll'  => self::describe_value( $after_plugin_all ),
					'deleteThemeAll'  => $delete_theme_all,
					'afterThemeAll'   => self::describe_value( $after_theme_all ),
				)
			);

			self::set_recovery_mode_state( false, '' );
			$inactive_storage = new \WP_Paused_Extensions_Storage( 'plugin' );
			self::collect_failure(
				$failures,
				false === $inactive_storage->set( $plugin, $plugin_error )
					&& null === $inactive_storage->get( $plugin )
					&& array() === $inactive_storage->get_all()
					&& false === $inactive_storage->delete_all(),
				'paused extension storage is inert outside an active recovery mode session',
				array(
					'set'       => $inactive_storage->set( $plugin, $plugin_error ),
					'get'       => self::describe_value( $inactive_storage->get( $plugin ) ),
					'getAll'    => self::describe_value( $inactive_storage->get_all() ),
					'deleteAll' => $inactive_storage->delete_all(),
				)
			);
		} finally {
			self::restore_recovery_mode_state( $mode_snapshot );
			\delete_option( $option_name );
			\wp_cache_delete( $option_name, 'options' );
		}

		return self::row(
			$ctx,
			'account-security.paused-extensions.type-isolated-storage',
			array() === $failures,
			array(
				'sessionId' => $session_id,
				'failures'  => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function install_scoped_filters(): void {
		\add_filter( 'get_user_metadata', array( __CLASS__, 'filter_get_user_metadata' ), 10, 5 );
		\add_filter( 'update_user_metadata', array( __CLASS__, 'filter_update_user_metadata' ), 10, 5 );
	}

	private static function reset_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-admin/profile.php?page=component-fuzz-account-security';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.24';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/account-security';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['SERVER_PORT']     = '80';
	}

	private static function reset_static_state(): void {
		self::$application_passwords = array();
	}

	private static function can_reset_stub_content(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' );
	}

	private static function reset_stub_content(): void {
		if ( self::can_reset_stub_content() ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
	}

	private static function stub_content_counts(): ?array {
		if (
			isset( $GLOBALS['wpdb'] )
			&& $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
		) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return null;
	}

	private static function recovery_key_records(): array {
		$records = \get_option( 'recovery_keys', array() );
		return is_array( $records ) ? $records : array();
	}

	private static function name_case( \ComponentFuzz\FuzzContext $ctx, string $prefix = 'Component Fuzz App' ): array {
		$raw = $prefix . ' ' . self::random_string( $ctx, $ctx->int( 4, 48 ) ) . " <b>\xC3\xA9</b>\n\x80";
		$sanitized = \sanitize_text_field( $raw );

		if ( '' === $sanitized ) {
			$raw       = $prefix . ' fallback';
			$sanitized = \sanitize_text_field( $raw );
		}

		return array(
			'raw'       => $raw,
			'sanitized' => $sanitized,
		);
	}

	private static function app_id_case( \ComponentFuzz\FuzzContext $ctx ): string {
		return sprintf(
			'%s-%s-%s-%s-%s|%s',
			self::token( $ctx->fork( 'app-id-1' ), 8 ),
			self::token( $ctx->fork( 'app-id-2' ), 4 ),
			self::token( $ctx->fork( 'app-id-3' ), 4 ),
			self::token( $ctx->fork( 'app-id-4' ), 4 ),
			self::token( $ctx->fork( 'app-id-5' ), 12 ),
			self::random_string( $ctx->fork( 'suffix' ), 8 )
		);
	}

	private static function password_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			'',
			'plain-password-1234',
			"spaces and\ttabs\n1234",
			"unicode-\xC3\xA9-\xE2\x98\x83-\xF0\x9F\x98\x80",
			"invalid-\x80\xFF-bytes",
			"symbols:/?#[]@!$&'()*+,;=%",
		);

		foreach ( self::generated_strings( $ctx, self::GENERATED_STRING_CASES, 96 ) as $generated ) {
			$cases[] = $generated;
		}

		return $cases;
	}

	private static function login_case( \ComponentFuzz\FuzzContext $ctx ): string {
		return sprintf(
			'cfz_reset_%d_%s_%s',
			$ctx->iteration(),
			self::token( $ctx->fork( 'login-a' ), 8 ),
			self::token( $ctx->fork( 'login-b' ), 10 )
		);
	}

	private static function recovery_token_case( \ComponentFuzz\FuzzContext $ctx ): string {
		$token = 'tok|' . self::random_string( $ctx, $ctx->int( 8, 48 ) );
		return '' === $token ? 'tok-empty' : $token;
	}

	private static function ip_case( \ComponentFuzz\FuzzContext $ctx ): string {
		if ( $ctx->bool() ) {
			return sprintf( '198.51.100.%d', $ctx->int( 1, 254 ) );
		}

		return sprintf( '2001:db8::%x', $ctx->int( 1, 65535 ) );
	}

	private static function extension_error_case( \ComponentFuzz\FuzzContext $ctx, string $file ): array {
		return array(
			'type'    => $ctx->choice( array( E_ERROR, E_PARSE, E_USER_ERROR, E_WARNING ) ),
			'file'    => $file,
			'line'    => $ctx->int( 1, 5000 ),
			'message' => 'Component fuzz failure ' . self::random_string( $ctx->fork( 'message' ), $ctx->int( 4, 80 ) ),
		);
	}

	private static function generated_strings( \ComponentFuzz\FuzzContext $ctx, int $count, int $max_length ): array {
		$strings = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$case      = $ctx->fork( 'generated-string-' . $i );
			$strings[] = self::random_string( $case, $case->int( 0, $max_length ) );
		}
		return $strings;
	}

	private static function random_string( \ComponentFuzz\FuzzContext $ctx, int $length ): string {
		$atoms = array(
			'a',
			'Z',
			'9',
			'_',
			'-',
			'.',
			'@',
			' ',
			"\t",
			"\n",
			'|',
			':/?#[]@!$&\'()*+,;=',
			'<tag attr="value">',
			'&amp;',
			'%0d%0a',
			"\x00",
			"\x1F",
			"\x7F",
			"\x80",
			"\xFF",
			"\xC3\xA9",
			"\xE2\x98\x83",
			"\xF0\x9F\x98\x80",
		);

		$out = '';
		while ( strlen( $out ) < $length ) {
			$out .= $ctx->choice( $atoms );
		}

		return substr( $out, 0, $length );
	}

	private static function mutate_secret( string $secret ): string {
		if ( '' === $secret ) {
			return 'component-fuzz-mutated';
		}

		return $secret . '|mutated';
	}

	private static function mutate_hex( string $hex ): string {
		if ( '' === $hex ) {
			return '0';
		}

		$first = $hex[0];
		return ( '0' === $first ? '1' : '0' ) . substr( $hex, 1 );
	}

	private static function token( \ComponentFuzz\FuzzContext $ctx, int $length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $ctx->int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	private static function is_error_code( $value, string $code ): bool {
		return \is_wp_error( $value ) && $code === $value->get_error_code();
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

	private static function snapshot_recovery_mode_state(): ?array {
		if ( ! function_exists( 'wp_recovery_mode' ) || ! class_exists( 'WP_Recovery_Mode' ) ) {
			return null;
		}

		$mode  = \wp_recovery_mode();
		$state = array();
		foreach ( array( 'is_initialized', 'is_active', 'session_id' ) as $property ) {
			$reflection = new \ReflectionProperty( \WP_Recovery_Mode::class, $property );
			$state[ $property ] = $reflection->getValue( $mode );
		}

		return $state;
	}

	private static function set_recovery_mode_state( bool $active, string $session_id ): void {
		$mode = \wp_recovery_mode();

		$initialized = new \ReflectionProperty( \WP_Recovery_Mode::class, 'is_initialized' );
		$initialized->setValue( $mode, true );

		$is_active = new \ReflectionProperty( \WP_Recovery_Mode::class, 'is_active' );
		$is_active->setValue( $mode, $active );

		$session = new \ReflectionProperty( \WP_Recovery_Mode::class, 'session_id' );
		$session->setValue( $mode, $active ? $session_id : '' );
	}

	private static function restore_recovery_mode_state( ?array $state ): void {
		if ( null === $state ) {
			return;
		}

		$mode = \wp_recovery_mode();
		foreach ( $state as $property => $value ) {
			$reflection = new \ReflectionProperty( \WP_Recovery_Mode::class, $property );
			$reflection->setValue( $mode, $value );
		}
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
					'type' => 'WP_Error',
					'code' => $value->get_error_code(),
					'data' => self::describe_value( $value->get_error_data(), $depth + 1 ),
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

	private static function snapshot_globals(): array {
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
				'pagenow',
				'current_user',
				'wp_roles',
				'wp_user_roles',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_rest_application_password_status',
				'wp_rest_application_password_uuid',
				'wpdb',
			) as $name
		) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
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

		self::restore_recovery_mode_state( $snapshot['recovery'] ?? null );
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
}
