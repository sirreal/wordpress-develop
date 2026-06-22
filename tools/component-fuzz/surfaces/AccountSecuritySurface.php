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
				'add_filter',
				'delete_option',
				'get_network_option',
				'get_option',
				'get_user_meta',
				'is_wp_error',
				'remove_filter',
				'sanitize_text_field',
				'update_network_option',
				'update_option',
				'update_user_meta',
				'wp_check_password',
				'wp_fast_hash',
				'wp_generate_password',
				'wp_generate_uuid4',
				'wp_hash_password',
				'wp_recovery_mode',
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
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
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
