<?php
namespace ComponentFuzz\Surfaces;

final class SecuritySurface {
	public const NAME = 'security';

	private const GENERATED_STRING_CASES = 8;
	private const SAMPLE_BYTES           = 160;

	/** @var array<string,int> */
	private static array $nonce_logged_out_users = array();

	private static int $nonce_life = 1000000000;

	/** @var array<string,int> */
	private static array $nonce_life_by_action = array();

	/** @var array<int,array<string,array<string,int>>> */
	private static array $session_tokens = array();

	private static string $session_seed = 'security';

	/** @var array<int,array<string,mixed>> */
	private static array $redirect_events = array();

	/** @var array<int,array<string,mixed>> */
	private static array $redirect_status_events = array();

	/** @var string[] */
	private static array $allowed_redirect_hosts = array();

	/** @var array<int,array<string,mixed>> */
	private static array $allowed_redirect_host_events = array();

	private static ?string $safe_redirect_fallback = null;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'security.bootstrap-apis-available',
					'Required WordPress security APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_runtime();
			self::install_scoped_filters();

			$rows[] = self::check_salts_and_hashes( $ctx );
			$rows[] = self::check_password_hashing_contracts( $ctx );
			$rows[] = self::check_password_and_fast_hash_filter_boundaries( $ctx );
			$rows[] = self::check_nonce_metamorphic_behavior( $ctx );
			$rows[] = self::check_nonce_tick_lifetime_boundaries( $ctx );
			$rows[] = self::check_nonce_url_and_fields( $ctx );
			$rows[] = self::check_ajax_referer_lookup_order( $ctx );
			$rows[] = self::check_admin_referer_valid_paths( $ctx );
			$rows[] = self::check_auth_cookie_structure_and_validation( $ctx );
			$rows[] = self::check_auth_cookie_grace_period_and_session_edges( $ctx );
			$rows[] = self::check_redirect_filters_without_headers( $ctx );
			$rows[] = self::check_validate_redirect_matrix( $ctx );
			$rows[] = self::check_redirect_sanitize_validate_metamorphic_behavior( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'security.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_static_state();
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	public static function filter_home_option( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );
		return 'http://example.test';
	}

	public static function filter_siteurl_option( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );
		return 'http://example.test/wp';
	}

	public static function filter_nonce_user_logged_out( int $uid, $action ): int {
		$key = self::action_key( $action );
		return self::$nonce_logged_out_users[ $key ] ?? $uid;
	}

	public static function filter_nonce_life( int $lifespan, $action = -1 ): int {
		unset( $lifespan );
		return self::$nonce_life_by_action[ self::action_key( $action ) ] ?? self::$nonce_life;
	}

	public static function filter_session_token_manager( string $manager ): string {
		unset( $manager );
		return __NAMESPACE__ . '\\SecuritySurface_SessionTokenManager';
	}

	public static function filter_user_metadata( $value, int $user_id, string $meta_key, bool $single, string $meta_type ) {
		unset( $user_id, $meta_type );

		if ( str_ends_with( $meta_key, 'capabilities' ) ) {
			return $single ? array( array() ) : array();
		}

		if ( 'session_tokens' === $meta_key ) {
			return $single ? array( array() ) : array();
		}

		return $value;
	}

	public static function filter_wp_redirect_capture( $location, int $status ) {
		self::$redirect_events[] = array(
			'location' => $location,
			'status'   => $status,
		);

		return false;
	}

	public static function filter_wp_redirect_status_capture( int $status, $location ): int {
		self::$redirect_status_events[] = array(
			'location' => $location,
			'status'   => $status,
		);

		return $status;
	}

	public static function filter_safe_redirect_fallback( string $fallback_url, int $status ): string {
		unset( $fallback_url, $status );
		return self::$safe_redirect_fallback ?? 'http://example.test/wp/wp-admin/';
	}

	public static function filter_allowed_redirect_hosts( array $hosts, string $host ): array {
		self::$allowed_redirect_host_events[] = array(
			'hosts'   => $hosts,
			'host'    => $host,
			'allowed' => self::$allowed_redirect_hosts,
		);

		return array_values( array_unique( array_merge( $hosts, self::$allowed_redirect_hosts ) ) );
	}

	public static function register_session_token( int $user_id, string $token, int $expiration ): void {
		if ( ! isset( self::$session_tokens[ $user_id ] ) ) {
			self::$session_tokens[ $user_id ] = array();
		}

		self::$session_tokens[ $user_id ][ $token ] = array(
			'expiration' => $expiration,
		);
	}

	public static function create_session_token( int $user_id, int $expiration ): string {
		$token = substr(
			hash( 'sha256', self::$session_seed . '|' . $user_id . '|' . $expiration . '|' . count( self::$session_tokens[ $user_id ] ?? array() ) ),
			0,
			43
		);

		self::register_session_token( $user_id, $token, $expiration );
		return $token;
	}

	public static function session_token_valid( int $user_id, string $token ): bool {
		$session = self::$session_tokens[ $user_id ][ $token ] ?? null;
		return is_array( $session ) && (int) $session['expiration'] >= time();
	}

	public static function session_tokens_for_user( int $user_id ): array {
		return self::$session_tokens[ $user_id ] ?? array();
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_User',
				'WP_Session_Tokens',
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
				'apply_filters',
				'check_admin_referer',
				'check_ajax_referer',
				'esc_attr',
				'esc_html',
				'esc_url',
				'get_userdata',
				'hash_equals',
				'hash_hmac_algos',
				'has_filter',
				'remove_filter',
				'remove_query_arg',
				'update_user_caches',
				'wp_create_nonce',
				'wp_check_password',
				'wp_doing_ajax',
				'wp_fast_hash',
				'wp_generate_auth_cookie',
				'wp_get_current_user',
				'wp_get_session_token',
				'wp_hash',
				'wp_hash_password',
				'wp_nonce_field',
				'wp_nonce_tick',
				'wp_nonce_url',
				'wp_original_referer_field',
				'wp_parse_auth_cookie',
				'wp_password_needs_rehash',
				'wp_redirect',
				'wp_referer_field',
				'wp_safe_redirect',
				'wp_salt',
				'wp_sanitize_redirect',
				'wp_unslash',
				'wp_validate_auth_cookie',
				'wp_validate_redirect',
				'wp_verify_nonce',
				'wp_verify_fast_hash',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach (
			array(
				'AUTH_COOKIE',
				'HOUR_IN_SECONDS',
				'SECURE_AUTH_COOKIE',
				'LOGGED_IN_COOKIE',
			) as $constant
		) {
			if ( ! defined( $constant ) ) {
				$missing[] = "constant {$constant}";
			}
		}

		return $missing;
	}

	private static function check_salts_and_hashes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$data_cases = self::data_cases( $ctx->fork( 'hash-data' ) );
		$schemes    = array_merge(
			array( 'auth', 'secure_auth', 'logged_in', 'nonce', 'component_fuzz_custom', 'scheme|pipe', "scheme\x80bytes" ),
			array_slice( self::generated_strings( $ctx->fork( 'hash-schemes' ), 3, 48 ), 0, 3 )
		);
		$algorithms = array_values( array_intersect( array( 'md5', 'sha1', 'sha256', 'sha512' ), hash_hmac_algos() ) );

		foreach ( $schemes as $scheme_index => $scheme ) {
			$salt_a = \wp_salt( $scheme );
			$salt_b = \wp_salt( $scheme );
			self::collect_failure(
				$failures,
				is_string( $salt_a ) && '' !== $salt_a && $salt_a === $salt_b,
				"salt deterministic for scheme {$scheme_index}",
				array(
					'scheme' => self::describe_string( $scheme ),
					'saltA'  => self::describe_string( $salt_a ),
					'saltB'  => self::describe_string( $salt_b ),
				)
			);

			foreach ( $algorithms as $algorithm ) {
				foreach ( array_slice( $data_cases, 0, 5 ) as $data_index => $data ) {
					$expected = hash_hmac( $algorithm, $data, $salt_a );
					$actual_a = \wp_hash( $data, $scheme, $algorithm );
					$actual_b = \wp_hash( $data, $scheme, $algorithm );

					self::collect_failure(
						$failures,
						$expected === $actual_a && $actual_a === $actual_b,
						"wp_hash matches hash_hmac scheme {$scheme_index} algo {$algorithm} data {$data_index}",
						array(
							'scheme'   => self::describe_string( $scheme ),
							'algorithm' => $algorithm,
							'data'      => self::describe_string( $data ),
							'expected'  => $expected,
							'actualA'   => $actual_a,
							'actualB'   => $actual_b,
						)
					);
				}
			}
		}

		$unsupported_algorithms = array(
			'component-fuzz-unsupported',
			'md5|sha1',
			"sha256\x00suffix",
		);

		foreach ( $unsupported_algorithms as $algorithm ) {
			$thrown = null;
			$return = null;
			try {
				$return = \wp_hash( $data_cases[0], 'auth', $algorithm );
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}

			self::collect_failure(
				$failures,
				$thrown instanceof \InvalidArgumentException && null === $return,
				'wp_hash rejects unsupported algorithm',
				array(
					'algorithm' => self::describe_string( $algorithm ),
					'throwable' => null === $thrown ? null : self::describe_throwable( $thrown ),
					'return'    => self::describe_value( $return ),
				)
			);
		}

		return self::row(
			$ctx,
			'security.hash.salt-and-hmac-determinism',
			array() === $failures,
			array(
				'schemes'    => count( $schemes ),
				'algorithms' => $algorithms,
				'dataCases'  => count( $data_cases ),
				'failures'   => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_password_hashing_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = 70000 + $ctx->int( 0, 9999 );

		$algorithm_filter = static function ( string $algorithm ): string {
			unset( $algorithm );
			return PASSWORD_BCRYPT;
		};
		$cheap_options_filter = static function ( array $options, string $algorithm ): array {
			if ( PASSWORD_BCRYPT === $algorithm ) {
				$options['cost'] = 4;
			}
			return $options;
		};
		$rehash_options_filter = static function ( array $options, string $algorithm ): array {
			if ( PASSWORD_BCRYPT === $algorithm ) {
				$options['cost'] = 5;
			}
			return $options;
		};

		\add_filter( 'wp_hash_password_algorithm', $algorithm_filter );
		\add_filter( 'wp_hash_password_options', $cheap_options_filter, 10, 2 );
		try {
			$passwords = array(
				'',
				'plain-' . self::token( $ctx->fork( 'password-plain' ), 10 ),
				"unicode-\xC3\xA9-\xE2\x98\x83-" . self::token( $ctx->fork( 'password-unicode' ), 6 ),
				"binary-\x00-\x80-\xFF-" . self::token( $ctx->fork( 'password-binary' ), 6 ),
				str_repeat( 'long-pass-', 40 ) . self::token( $ctx->fork( 'password-long' ), 10 ),
			);

			foreach ( $passwords as $index => $password ) {
				$hash       = \wp_hash_password( $password );
				$mutated    = $password . '|mutated';
				$valid      = \wp_check_password( $password, $hash, $user_id );
				$invalid    = \wp_check_password( $mutated, $hash, $user_id );
				$needs_same = \wp_password_needs_rehash( $hash, $user_id );

				\remove_filter( 'wp_hash_password_options', $cheap_options_filter, 10 );
				\add_filter( 'wp_hash_password_options', $rehash_options_filter, 10, 2 );
				$needs_higher_cost = \wp_password_needs_rehash( $hash, $user_id );
				\remove_filter( 'wp_hash_password_options', $rehash_options_filter, 10 );
				\add_filter( 'wp_hash_password_options', $cheap_options_filter, 10, 2 );

				self::collect_failure(
					$failures,
					str_starts_with( $hash, '$wp$' )
						&& true === $valid
						&& false === $invalid
						&& false === $needs_same
						&& true === $needs_higher_cost,
					"wp_hash_password case {$index}",
					array(
						'password'        => self::describe_string( $password ),
						'hash'            => self::describe_string( $hash ),
						'valid'           => $valid,
						'invalid'         => $invalid,
						'needsSame'       => $needs_same,
						'needsHigherCost' => $needs_higher_cost,
					)
				);
			}

			$too_long = str_repeat( 'x', 4097 );
			self::collect_failure(
				$failures,
				'*' === \wp_hash_password( $too_long )
					&& false === \wp_check_password( $too_long, '*', $user_id ),
				'password hashing rejects unsupported long passwords',
				array(
					'bytes' => strlen( $too_long ),
					'hash'  => \wp_hash_password( $too_long ),
					'check' => \wp_check_password( $too_long, '*', $user_id ),
				)
			);

			$md5_password = 'legacy-' . self::token( $ctx->fork( 'password-md5' ), 8 );
			$md5_hash     = md5( $md5_password );
			self::collect_failure(
				$failures,
				true === \wp_check_password( $md5_password, $md5_hash, $user_id )
					&& false === \wp_check_password( $md5_password . '-wrong', $md5_hash, $user_id )
					&& true === \wp_password_needs_rehash( $md5_hash, $user_id ),
				'wp_check_password keeps legacy md5 compatibility and rehash signal',
				array(
					'password' => self::describe_string( $md5_password ),
					'hash'     => $md5_hash,
				)
			);
		} finally {
			\remove_filter( 'wp_hash_password_options', $cheap_options_filter, 10 );
			\remove_filter( 'wp_hash_password_options', $rehash_options_filter, 10 );
			\remove_filter( 'wp_hash_password_algorithm', $algorithm_filter );
		}

		foreach ( array_slice( self::data_cases( $ctx->fork( 'fast-hash-data' ) ), 0, 8 ) as $index => $message ) {
			$hash         = \wp_fast_hash( $message );
			$mutated_hash = substr( $hash, 0, -1 ) . ( 'A' === substr( $hash, -1 ) ? 'B' : 'A' );

			self::collect_failure(
				$failures,
				str_starts_with( $hash, '$generic$' )
					&& true === \wp_verify_fast_hash( $message, $hash )
					&& false === \wp_verify_fast_hash( $message . '|mutated', $hash )
					&& false === \wp_verify_fast_hash( $message, $mutated_hash ),
				"wp_fast_hash case {$index}",
				array(
					'message'     => self::describe_string( $message ),
					'hash'        => self::describe_string( $hash ),
					'mutatedHash' => self::describe_string( $mutated_hash ),
				)
			);
		}

		return self::row(
			$ctx,
			'security.password-and-fast-hash.verification-and-rehash',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_password_and_fast_hash_filter_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$target_user_id = 71000 + $ctx->int( 0, 9999 );
		$other_user_id  = $target_user_id + 10000;
		$password       = 'filter-pass-' . self::token( $ctx->fork( 'password-filter-pass' ), 12 );

		$algorithm_filter = static function ( string $algorithm ): string {
			unset( $algorithm );
			return PASSWORD_BCRYPT;
		};
		$cheap_options_filter = static function ( array $options, string $algorithm ): array {
			if ( PASSWORD_BCRYPT === $algorithm ) {
				$options['cost'] = 4;
			}
			return $options;
		};

		\add_filter( 'wp_hash_password_algorithm', $algorithm_filter );
		\add_filter( 'wp_hash_password_options', $cheap_options_filter, 10, 2 );
		try {
			$hash              = \wp_hash_password( $password );
			$override_password = $password . '|filter-override';
			$baseline_valid    = \wp_check_password( $password, $hash, $target_user_id );
			$baseline_override = \wp_check_password( $override_password, $hash, $target_user_id );

			$check_events = array();
			$check_filter = static function ( bool $check, string $candidate, string $candidate_hash, $user_id ) use ( &$check_events, $target_user_id, $override_password ): bool {
				if ( count( $check_events ) < 6 ) {
					$check_events[] = array(
						'userId'       => $user_id,
						'candidateSha' => sha1( $candidate ),
						'hashSha'      => sha1( $candidate_hash ),
						'incoming'     => $check,
					);
				}

				if ( $target_user_id === (int) $user_id && $override_password === $candidate ) {
					return true;
				}

				return $check;
			};

			\add_filter( 'check_password', $check_filter, 10, 4 );
			try {
				$target_override = \wp_check_password( $override_password, $hash, $target_user_id );
				$other_override  = \wp_check_password( $override_password, $hash, $other_user_id );
			} finally {
				\remove_filter( 'check_password', $check_filter, 10 );
			}

			$after_check_filter = \wp_check_password( $override_password, $hash, $target_user_id );
			self::collect_failure(
				$failures,
				true === $baseline_valid
					&& false === $baseline_override
					&& true === $target_override
					&& false === $other_override
					&& false === $after_check_filter
					&& false === \has_filter( 'check_password', $check_filter ),
				'check_password filter only overrides the targeted user and is removed',
				array(
					'targetUserId'      => $target_user_id,
					'otherUserId'       => $other_user_id,
					'hash'              => self::describe_string( $hash ),
					'baselineValid'     => $baseline_valid,
					'baselineOverride'  => $baseline_override,
					'targetOverride'    => $target_override,
					'otherOverride'     => $other_override,
					'afterCheckFilter'  => $after_check_filter,
					'filterStillActive' => \has_filter( 'check_password', $check_filter ),
					'events'            => $check_events,
				)
			);

			$baseline_needs_rehash = \wp_password_needs_rehash( $hash, $target_user_id );
			$rehash_events         = array();
			$rehash_filter         = static function ( bool $needs_rehash, string $candidate_hash, $user_id ) use ( &$rehash_events, $target_user_id ): bool {
				if ( count( $rehash_events ) < 6 ) {
					$rehash_events[] = array(
						'userId'   => $user_id,
						'hashSha'  => sha1( $candidate_hash ),
						'incoming' => $needs_rehash,
					);
				}

				if ( $target_user_id === (int) $user_id ) {
					return ! $needs_rehash;
				}

				return $needs_rehash;
			};

			\add_filter( 'password_needs_rehash', $rehash_filter, 10, 3 );
			try {
				$target_needs_rehash = \wp_password_needs_rehash( $hash, $target_user_id );
				$other_needs_rehash  = \wp_password_needs_rehash( $hash, $other_user_id );
			} finally {
				\remove_filter( 'password_needs_rehash', $rehash_filter, 10 );
			}

			$after_rehash_filter = \wp_password_needs_rehash( $hash, $target_user_id );
			self::collect_failure(
				$failures,
				false === $baseline_needs_rehash
					&& true === $target_needs_rehash
					&& false === $other_needs_rehash
					&& false === $after_rehash_filter
					&& false === \has_filter( 'password_needs_rehash', $rehash_filter ),
				'password_needs_rehash filter only overrides the targeted user and is removed',
				array(
					'targetUserId'      => $target_user_id,
					'otherUserId'       => $other_user_id,
					'baselineNeeds'     => $baseline_needs_rehash,
					'targetNeeds'       => $target_needs_rehash,
					'otherNeeds'        => $other_needs_rehash,
					'afterRehashFilter' => $after_rehash_filter,
					'filterStillActive' => \has_filter( 'password_needs_rehash', $rehash_filter ),
					'events'            => $rehash_events,
				)
			);
		} finally {
			\remove_filter( 'wp_hash_password_options', $cheap_options_filter, 10 );
			\remove_filter( 'wp_hash_password_algorithm', $algorithm_filter );
		}

		$message      = 'fast-filter-' . self::token( $ctx->fork( 'fast-filter-message' ), 16 );
		$generic_hash = \wp_fast_hash( $message );
		$malformed    = array(
			'$generic$',
			'$generic$' . substr( $generic_hash, 9, 12 ),
			'$generic$' . str_repeat( '!', 40 ),
		);

		foreach ( $malformed as $index => $hash ) {
			self::collect_failure(
				$failures,
				false === \wp_verify_fast_hash( $message, $hash ),
				"wp_verify_fast_hash rejects malformed generic hash {$index}",
				array(
					'message' => self::describe_string( $message ),
					'hash'    => self::describe_string( $hash ),
					'actual'  => \wp_verify_fast_hash( $message, $hash ),
				)
			);
		}

		require_once ABSPATH . WPINC . '/class-phpass.php';
		$legacy_hash = ( new \PasswordHash( 8, true ) )->HashPassword( $message );
		self::collect_failure(
			$failures,
			true === \wp_verify_fast_hash( $message, $legacy_hash )
				&& false === \wp_verify_fast_hash( $message . '|mutated', $legacy_hash ),
			'wp_verify_fast_hash keeps legacy phpass compatibility without accepting mutations',
			array(
				'message'    => self::describe_string( $message ),
				'legacyHash' => self::describe_string( $legacy_hash ),
				'valid'      => \wp_verify_fast_hash( $message, $legacy_hash ),
				'mutated'    => \wp_verify_fast_hash( $message . '|mutated', $legacy_hash ),
			)
		);

		return self::row(
			$ctx,
			'security.password-and-fast-hash.filter-locality-and-failures',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_nonce_metamorphic_behavior( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$actions  = self::action_cases( $ctx->fork( 'nonce-actions' ) );

		self::$nonce_life = 1000000000 + $ctx->int( 0, 9999 );

		foreach ( $actions as $index => $action ) {
			$key                                      = self::action_key( $action );
			self::$nonce_logged_out_users[ $key ]     = 1000 + $index + $ctx->int( 0, 500 );
			self::$nonce_logged_out_users['mutated'] = self::$nonce_logged_out_users[ $key ] + 10000;

			$nonce_a = \wp_create_nonce( $action );
			$nonce_b = \wp_create_nonce( $action );
			$verify  = \wp_verify_nonce( $nonce_a, $action );

			$mutated_action = self::mutate_action( $action );
			$wrong_action   = \wp_verify_nonce( $nonce_a, $mutated_action );
			$truncated      = \wp_verify_nonce( substr( $nonce_a, 0, 9 ), $action );

			self::$nonce_logged_out_users[ $key ]++;
			$wrong_user = \wp_verify_nonce( $nonce_a, $action );
			self::$nonce_logged_out_users[ $key ]--;

			self::collect_failure(
				$failures,
				$nonce_a === $nonce_b
					&& 1 === preg_match( '/\A[a-f0-9]{10}\z/', $nonce_a )
					&& in_array( $verify, array( 1, 2 ), true )
					&& false === $wrong_action
					&& false === $truncated
					&& false === $wrong_user,
				"nonce metamorphic case {$index}",
				array(
					'action'        => self::describe_value( $action ),
					'uid'           => self::$nonce_logged_out_users[ $key ],
					'nonceA'        => $nonce_a,
					'nonceB'        => $nonce_b,
					'verify'        => $verify,
					'wrongAction'   => $wrong_action,
					'truncated'     => $truncated,
					'wrongUser'     => $wrong_user,
					'nonceLife'     => self::$nonce_life,
					'mutatedAction' => self::describe_value( $mutated_action ),
				)
			);
		}

		return self::row(
			$ctx,
			'security.nonce.logged-out-user-and-life-metamorphic',
			array() === $failures,
			array(
				'cases'     => count( $actions ),
				'nonceLife' => self::$nonce_life,
				'failures'  => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_nonce_tick_lifetime_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$previous_life  = self::$nonce_life;
		$previous_lives = self::$nonce_life_by_action;
		$previous_users = self::$nonce_logged_out_users;

		try {
			$actions   = array_slice( self::action_cases( $ctx->fork( 'nonce-boundary-actions' ) ), 0, 7 );
			$lifespans = array(
				2,
				3,
				4,
				5,
				60 + $ctx->int( 0, 5 ),
				86399 + $ctx->int( 0, 3 ),
				1000000000 + $ctx->int( 0, 9999 ),
			);

			foreach ( $lifespans as $index => $lifespan ) {
				$action                                   = $actions[ $index % count( $actions ) ];
				$key                                      = self::action_key( $action );
				self::$nonce_life_by_action[ $key ]       = $lifespan;
				self::$nonce_logged_out_users[ $key ]     = 3000 + $index + $ctx->int( 0, 100 );
				$before                                   = time();
				$tick                                     = \wp_nonce_tick( $action );
				$after                                    = time();
				$expected_min                             = (int) ceil( $before / ( $lifespan / 2 ) );
				$expected_max                             = (int) ceil( $after / ( $lifespan / 2 ) );

				self::collect_failure(
					$failures,
					(int) $tick >= $expected_min && (int) $tick <= $expected_max,
					"wp_nonce_tick honors nonce_life for action {$index}",
					array(
						'action'      => self::describe_value( $action ),
						'nonceLife'   => $lifespan,
						'before'      => $before,
						'after'       => $after,
						'actual'      => $tick,
						'expectedMin' => $expected_min,
						'expectedMax' => $expected_max,
					)
				);
			}

			$stable_life = 1000000000 + $ctx->int( 10000, 99999 );
			foreach ( $actions as $index => $action ) {
				$key                                  = self::action_key( $action );
				self::$nonce_life_by_action[ $key ]   = $stable_life + $index;
				self::$nonce_logged_out_users[ $key ] = 5000 + $index + $ctx->int( 0, 100 );

				$tick          = \wp_nonce_tick( $action );
				$created       = \wp_create_nonce( $action );
				$current_nonce = self::nonce_for_tick( $tick, $action );
				$old_nonce     = self::nonce_for_tick( $tick - 1, $action );
				$expired_nonce = self::nonce_for_tick( $tick - 2, $action );

				$current_result = \wp_verify_nonce( $current_nonce, $action );
				$old_result     = \wp_verify_nonce( $old_nonce, $action );
				$expired_result = \wp_verify_nonce( $expired_nonce, $action );

				self::collect_failure(
					$failures,
					$created === $current_nonce
						&& 1 === $current_result
						&& 2 === $old_result
						&& false === $expired_result,
					"wp_verify_nonce partitions current, previous, and expired ticks {$index}",
					array(
						'action'        => self::describe_value( $action ),
						'nonceLife'     => self::$nonce_life_by_action[ $key ],
						'tick'          => $tick,
						'created'       => $created,
						'currentNonce'  => $current_nonce,
						'oldNonce'      => $old_nonce,
						'expiredNonce'  => $expired_nonce,
						'currentResult' => $current_result,
						'oldResult'     => $old_result,
						'expiredResult' => $expired_result,
					)
				);
			}

			$action_a = 'life-a-' . self::token( $ctx->fork( 'nonce-life-a' ), 6 );
			$action_b = 'life-b-' . self::token( $ctx->fork( 'nonce-life-b' ), 6 );
			self::$nonce_life_by_action[ self::action_key( $action_a ) ] = 1000000000;
			self::$nonce_life_by_action[ self::action_key( $action_b ) ] = 200000000;
			self::$nonce_logged_out_users[ self::action_key( $action_a ) ] = 9001;
			self::$nonce_logged_out_users[ self::action_key( $action_b ) ] = 9001;

			$tick_a  = \wp_nonce_tick( $action_a );
			$tick_b  = \wp_nonce_tick( $action_b );
			$nonce_a = \wp_create_nonce( $action_a );
			$nonce_b = \wp_create_nonce( $action_b );
			self::collect_failure(
				$failures,
				(int) $tick_a !== (int) $tick_b
					&& $nonce_a !== $nonce_b
					&& 1 === \wp_verify_nonce( $nonce_a, $action_a )
					&& 1 === \wp_verify_nonce( $nonce_b, $action_b ),
				'action-scoped nonce_life values produce independent ticks',
				array(
					'actionA' => $action_a,
					'actionB' => $action_b,
					'tickA'   => $tick_a,
					'tickB'   => $tick_b,
					'nonceA'  => $nonce_a,
					'nonceB'  => $nonce_b,
				)
			);
		} finally {
			self::$nonce_life             = $previous_life;
			self::$nonce_life_by_action   = $previous_lives;
			self::$nonce_logged_out_users = $previous_users;
		}

		return self::row(
			$ctx,
			'security.nonce.tick-boundary-and-lifetime-oracles',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_nonce_url_and_fields( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$action   = 'field-action|' . self::token( $ctx->fork( 'field-action' ), 8 );
		$name     = 'nonce_' . self::token( $ctx->fork( 'field-name' ), 6 );
		$nonce    = \wp_create_nonce( $action );

		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=component%20fuzz&_wp_http_referer='
			. rawurlencode( '/previous?x=1&unsafe=<tag>' )
			. '&reserved='
			. rawurlencode( "pipes|slashes/?#[]@!$&'()*+,;=" );
		$_REQUEST                = array();
		$_GET                    = array();
		$_POST                   = array();

		$url         = 'https://example.test/wp-admin/admin.php?page=fuzz&already=1&amp;reserved=' . rawurlencode( "a|b/?#[]@!$&'()*+,;=" );
		$nonce_url   = \wp_nonce_url( $url, $action, $name );
		$decoded_url = html_entity_decode( $nonce_url, ENT_QUOTES, 'UTF-8' );
		$query       = (string) wp_parse_url( $decoded_url, PHP_URL_QUERY );
		$params      = array();
		parse_str( $query, $params );

		self::collect_failure(
			$failures,
			isset( $params[ $name ] )
				&& $nonce === $params[ $name ]
				&& false === strpos( $nonce_url, '<' )
				&& false === strpos( $nonce_url, '"' ),
			'wp_nonce_url adds escaped nonce query arg',
			array(
				'inputUrl'    => $url,
				'nonceUrl'    => $nonce_url,
				'decodedUrl'  => $decoded_url,
				'queryParams' => $params,
				'expected'    => $nonce,
			)
		);

		$field_name = 'field"with<unsafe>&' . self::token( $ctx->fork( 'unsafe-field-name' ), 4 );
		$field      = \wp_nonce_field( $action, $field_name, true, false );
		$inputs     = self::hidden_inputs( $field );
		$nonce_input = $inputs[0] ?? array();
		$referer_input = $inputs[1] ?? array();

		self::collect_failure(
			$failures,
			isset( $nonce_input['type'], $nonce_input['id'], $nonce_input['name'], $nonce_input['value'] )
				&& 'hidden' === $nonce_input['type']
				&& $field_name === $nonce_input['id']
				&& $field_name === $nonce_input['name']
				&& $nonce === $nonce_input['value']
				&& isset( $referer_input['name'], $referer_input['value'] )
				&& '_wp_http_referer' === $referer_input['name'],
			'wp_nonce_field emits escaped nonce and referer hidden inputs',
			array(
				'fieldName' => self::describe_string( $field_name ),
				'html'      => $field,
				'inputs'    => $inputs,
				'expected'  => array(
					'name'  => $field_name,
					'value' => $nonce,
				),
			)
		);

		ob_start();
		$returned = \wp_nonce_field( $action, $name, false, true );
		$echoed   = ob_get_clean();
		self::collect_failure(
			$failures,
			$returned === $echoed,
			'wp_nonce_field display=true echoes the returned field',
			array(
				'returned' => $returned,
				'echoed'   => $echoed,
			)
		);

		$referer_field = \wp_referer_field( false );
		$referer_inputs = self::hidden_inputs( $referer_field );
		$expected_referer = html_entity_decode( \esc_url( \remove_query_arg( '_wp_http_referer' ) ), ENT_QUOTES, 'UTF-8' );
		self::collect_failure(
			$failures,
			isset( $referer_inputs[0]['name'], $referer_inputs[0]['value'] )
				&& '_wp_http_referer' === $referer_inputs[0]['name']
				&& $expected_referer === $referer_inputs[0]['value'],
			'wp_referer_field removes nested referer query arg and escapes value',
			array(
				'requestUri' => $_SERVER['REQUEST_URI'],
				'html'       => $referer_field,
				'inputs'     => $referer_inputs,
				'expected'   => $expected_referer,
			)
		);

		$original = 'https://example.test/original?x=<tag>&pipe=|';
		$_REQUEST['_wp_original_http_referer'] = $original;
		$original_field    = \wp_original_referer_field( false, 'previous' );
		$original_inputs = self::hidden_inputs( $original_field );
		$expected_original = \wp_validate_redirect( \wp_unslash( $original ), false );
		self::collect_failure(
			$failures,
			isset( $original_inputs[0]['name'], $original_inputs[0]['value'] )
				&& '_wp_original_http_referer' === $original_inputs[0]['name']
				&& $expected_original === $original_inputs[0]['value'],
			'wp_original_referer_field uses request original referer when present',
			array(
				'original' => self::describe_string( $original ),
				'expected' => self::describe_value( $expected_original ),
				'html'     => $original_field,
				'inputs'   => $original_inputs,
			)
		);

		unset( $_REQUEST['_wp_original_http_referer'] );
		$current_original_field = \wp_original_referer_field( false, 'current' );
		$current_original_inputs = self::hidden_inputs( $current_original_field );
		self::collect_failure(
			$failures,
			isset( $current_original_inputs[0]['value'] )
				&& \wp_unslash( $_SERVER['REQUEST_URI'] ) === $current_original_inputs[0]['value'],
			'wp_original_referer_field falls back to current request URI',
			array(
				'requestUri' => $_SERVER['REQUEST_URI'],
				'html'       => $current_original_field,
				'inputs'     => $current_original_inputs,
			)
		);

		return self::row(
			$ctx,
			'security.nonce-url-and-hidden-fields.escape-and-shape',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_ajax_referer_lookup_order( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$action   = 'ajax-action-' . self::token( $ctx->fork( 'ajax-action' ), 8 );
		$valid    = \wp_create_nonce( $action );
		$invalid  = self::invalid_nonce( $valid );
		$custom   = 'nonce_arg_' . self::token( $ctx->fork( 'ajax-custom' ), 5 );

		$cases = array(
			array(
				'label'    => '_ajax_nonce preferred over invalid _wpnonce',
				'request'  => array(
					'_ajax_nonce' => $valid,
					'_wpnonce'    => $invalid,
				),
				'queryArg' => false,
				'valid'    => true,
			),
			array(
				'label'    => 'invalid _ajax_nonce prevents fallback to valid _wpnonce',
				'request'  => array(
					'_ajax_nonce' => $invalid,
					'_wpnonce'    => $valid,
				),
				'queryArg' => false,
				'valid'    => false,
			),
			array(
				'label'    => '_wpnonce used when _ajax_nonce absent',
				'request'  => array(
					'_wpnonce' => $valid,
				),
				'queryArg' => false,
				'valid'    => true,
			),
			array(
				'label'    => 'custom query arg wins over default args',
				'request'  => array(
					$custom        => $valid,
					'_ajax_nonce'  => $invalid,
					'_wpnonce'     => $invalid,
					'reserved|arg' => "value/?#[]@!$&'()*+,;=",
				),
				'queryArg' => $custom,
				'valid'    => true,
			),
			array(
				'label'    => 'invalid custom query arg wins over valid defaults',
				'request'  => array(
					$custom       => $invalid,
					'_ajax_nonce' => $valid,
					'_wpnonce'    => $valid,
				),
				'queryArg' => $custom,
				'valid'    => false,
			),
		);

		foreach ( $cases as $index => $case ) {
			$_REQUEST = $case['request'];
			$_GET     = $case['request'];
			$_POST    = array();

			$result = \check_ajax_referer( $action, $case['queryArg'], false );
			$ok     = $case['valid'] ? in_array( $result, array( 1, 2 ), true ) : false === $result;

			self::collect_failure(
				$failures,
				$ok,
				"check_ajax_referer case {$index}: {$case['label']}",
				array(
					'action'   => $action,
					'request'  => self::describe_value( $case['request'] ),
					'queryArg' => $case['queryArg'],
					'expected' => $case['valid'] ? 'valid nonce result' : false,
					'actual'   => $result,
				)
			);
		}

		return self::row(
			$ctx,
			'security.check-ajax-referer.stop-false-lookup-order',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_admin_referer_valid_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$action   = 'admin-action-' . self::token( $ctx->fork( 'admin-action' ), 8 );
		$custom   = 'admin_nonce_' . self::token( $ctx->fork( 'admin-custom' ), 5 );
		$nonce    = \wp_create_nonce( $action );

		$_SERVER['REQUEST_URI']  = '/wp-admin/options.php?page=component-fuzz';
		$_SERVER['HTTP_REFERER'] = 'http://example.test/wp/wp-admin/options.php?page=previous';

		$cases = array(
			array(
				'label'    => 'default _wpnonce',
				'request'  => array( '_wpnonce' => $nonce ),
				'queryArg' => '_wpnonce',
			),
			array(
				'label'    => 'custom query arg',
				'request'  => array(
					$custom    => $nonce,
					'_wpnonce' => self::invalid_nonce( $nonce ),
				),
				'queryArg' => $custom,
			),
		);

		foreach ( $cases as $index => $case ) {
			$_REQUEST = $case['request'];
			$_GET     = $case['request'];
			$_POST    = array();

			$result = \check_admin_referer( $action, $case['queryArg'] );
			self::collect_failure(
				$failures,
				in_array( $result, array( 1, 2 ), true ),
				"check_admin_referer valid case {$index}: {$case['label']}",
				array(
					'action'   => $action,
					'request'  => $case['request'],
					'queryArg' => $case['queryArg'],
					'actual'   => $result,
				)
			);
		}

		return self::row(
			$ctx,
			'security.check-admin-referer.valid-nonce-no-wp-die-paths',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_auth_cookie_structure_and_validation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user     = self::synthetic_user( $ctx->fork( 'auth-user' ) );
		$expires  = 2000000000 + $ctx->int( 0, 9999 );

		self::$session_seed = (string) $ctx->seed();
		self::prime_synthetic_user( $user );

		$schemes = array( 'auth', 'secure_auth', 'logged_in' );
		foreach ( $schemes as $scheme ) {
			$token = self::token( $ctx->fork( 'auth-token-' . $scheme ), 32 );
			self::register_session_token( (int) $user->ID, $token, $expires );

			$cookie = \wp_generate_auth_cookie( (int) $user->ID, $expires, $scheme, $token );
			$parsed = \wp_parse_auth_cookie( $cookie, $scheme );
			$valid  = \wp_validate_auth_cookie( $cookie, $scheme );
			$hmac   = self::expected_auth_cookie_hmac( $user, $expires, $scheme, $token );

			$cookie_name = self::cookie_name_for_scheme( $scheme );
			$_COOKIE[ $cookie_name ] = $cookie;
			$parsed_from_cookie = \wp_parse_auth_cookie( '', $scheme );
			unset( $_COOKIE[ $cookie_name ] );

			$tampered_hmac = substr( $cookie, 0, -1 ) . ( '0' === substr( $cookie, -1 ) ? '1' : '0' );
			$tampered_user = 'missing_' . $cookie;
			$expired       = \wp_generate_auth_cookie( (int) $user->ID, 1000, $scheme, $token );

			self::collect_failure(
				$failures,
				is_array( $parsed )
					&& $user->user_login === $parsed['username']
					&& (string) $expires === $parsed['expiration']
					&& $token === $parsed['token']
					&& $hmac === $parsed['hmac']
					&& $scheme === $parsed['scheme']
					&& $parsed === $parsed_from_cookie
					&& (int) $user->ID === $valid
					&& false === \wp_validate_auth_cookie( $tampered_hmac, $scheme )
					&& false === \wp_validate_auth_cookie( $tampered_user, $scheme )
					&& false === \wp_validate_auth_cookie( $expired, $scheme ),
				"auth cookie generate/parse/validate scheme {$scheme}",
				array(
					'user'             => self::describe_user( $user ),
					'scheme'           => $scheme,
					'cookie'           => self::describe_string( $cookie ),
					'parsed'           => $parsed,
					'parsedFromCookie' => $parsed_from_cookie,
					'expectedHmac'     => $hmac,
					'validated'        => $valid,
					'tamperedHmac'     => \wp_validate_auth_cookie( $tampered_hmac, $scheme ),
					'tamperedUser'     => \wp_validate_auth_cookie( $tampered_user, $scheme ),
					'expired'          => \wp_validate_auth_cookie( $expired, $scheme ),
				)
			);
		}

		$implicit_cookie = \wp_generate_auth_cookie( (int) $user->ID, $expires, 'logged_in' );
		$implicit_parsed = \wp_parse_auth_cookie( $implicit_cookie, 'logged_in' );
		self::collect_failure(
			$failures,
			is_array( $implicit_parsed )
				&& '' !== $implicit_parsed['token']
				&& self::session_token_valid( (int) $user->ID, $implicit_parsed['token'] )
				&& (int) $user->ID === \wp_validate_auth_cookie( $implicit_cookie, 'logged_in' ),
			'wp_generate_auth_cookie creates synthetic session token when omitted',
			array(
				'cookie' => self::describe_string( $implicit_cookie ),
				'parsed' => $implicit_parsed,
				'valid'  => \wp_validate_auth_cookie( $implicit_cookie, 'logged_in' ),
			)
		);

		foreach ( array( '', 'one|two|three', 'a|b|c|d|e', "user|exp|tok|hmac|extra\x80" ) as $malformed ) {
			self::collect_failure(
				$failures,
				false === \wp_parse_auth_cookie( $malformed, 'auth' ),
				'wp_parse_auth_cookie rejects malformed pipe count',
				array(
					'cookie' => self::describe_string( $malformed ),
					'actual' => \wp_parse_auth_cookie( $malformed, 'auth' ),
				)
			);
		}

		self::collect_failure(
			$failures,
			'' === \wp_generate_auth_cookie( (int) $user->ID + 9999, $expires, 'auth', 'missing-user-token' ),
			'wp_generate_auth_cookie returns empty string for missing user',
			array( 'missingUserId' => (int) $user->ID + 9999 )
		);

		return self::row(
			$ctx,
			'security.auth-cookie.synthetic-user-session-structure',
			array() === $failures,
			array(
				'user'      => self::describe_user( $user ),
				'schemes'   => $schemes,
				'failures'  => array_slice( $failures, 0, 5 ),
				'validated' => 'synthetic user cache and session_token_manager filter',
			)
		);
	}

	private static function check_auth_cookie_grace_period_and_session_edges( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$user            = self::synthetic_user( $ctx->fork( 'auth-grace-user' ) );
		$other_user      = self::synthetic_user( $ctx->fork( 'auth-grace-other-user' ) );
		$scheme          = 'auth';
		$now             = time();
		$session_expires = $now + HOUR_IN_SECONDS + $ctx->int( 60, 600 );
		$grace_expires   = $now - $ctx->int( 1, HOUR_IN_SECONDS - 5 );
		$outside_expires = $now - HOUR_IN_SECONDS - $ctx->int( 10, 600 );

		$had_method      = array_key_exists( 'REQUEST_METHOD', $_SERVER );
		$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$had_grace       = array_key_exists( 'login_grace_period', $GLOBALS );
		$previous_grace  = $GLOBALS['login_grace_period'] ?? null;

		self::$session_seed = 'auth-grace-' . $ctx->seed();
		self::prime_synthetic_user( $user );
		self::prime_synthetic_user( $other_user );

		$ajax_filter = static function ( bool $doing_ajax ): bool {
			unset( $doing_ajax );
			return true;
		};

		try {
			$token = self::token( $ctx->fork( 'auth-grace-token' ), 32 );
			self::register_session_token( (int) $user->ID, $token, $session_expires );
			$cookie = \wp_generate_auth_cookie( (int) $user->ID, $grace_expires, $scheme, $token );

			$_SERVER['REQUEST_METHOD'] = 'GET';
			unset( $GLOBALS['login_grace_period'] );
			$get_result = \wp_validate_auth_cookie( $cookie, $scheme );
			$get_grace  = $GLOBALS['login_grace_period'] ?? null;

			$_SERVER['REQUEST_METHOD'] = 'POST';
			unset( $GLOBALS['login_grace_period'] );
			$post_result = \wp_validate_auth_cookie( $cookie, $scheme );
			$post_grace  = $GLOBALS['login_grace_period'] ?? null;

			\add_filter( 'wp_doing_ajax', $ajax_filter );
			try {
				$_SERVER['REQUEST_METHOD'] = 'GET';
				unset( $GLOBALS['login_grace_period'] );
				$ajax_result = \wp_validate_auth_cookie( $cookie, $scheme );
				$ajax_grace  = $GLOBALS['login_grace_period'] ?? null;
			} finally {
				\remove_filter( 'wp_doing_ajax', $ajax_filter );
			}

			self::collect_failure(
				$failures,
				false === $get_result
					&& null === $get_grace
					&& (int) $user->ID === $post_result
					&& 1 === $post_grace
					&& (int) $user->ID === $ajax_result
					&& 1 === $ajax_grace
					&& false === \has_filter( 'wp_doing_ajax', $ajax_filter ),
				'auth cookie grace period is limited to POST and Ajax requests',
				array(
					'user'              => self::describe_user( $user ),
					'cookie'            => self::describe_string( $cookie ),
					'cookieExpiration'  => $grace_expires,
					'sessionExpiration' => $session_expires,
					'getResult'         => $get_result,
					'getGrace'          => $get_grace,
					'postResult'        => $post_result,
					'postGrace'         => $post_grace,
					'ajaxResult'        => $ajax_result,
					'ajaxGrace'         => $ajax_grace,
					'ajaxFilter'        => \has_filter( 'wp_doing_ajax', $ajax_filter ),
				)
			);

			$outside_token = self::token( $ctx->fork( 'auth-outside-token' ), 32 );
			self::register_session_token( (int) $user->ID, $outside_token, $session_expires );
			$outside_cookie = \wp_generate_auth_cookie( (int) $user->ID, $outside_expires, $scheme, $outside_token );

			$_SERVER['REQUEST_METHOD'] = 'POST';
			unset( $GLOBALS['login_grace_period'] );
			$outside_result = \wp_validate_auth_cookie( $outside_cookie, $scheme );
			$outside_grace  = $GLOBALS['login_grace_period'] ?? null;

			$expired_session_token = self::token( $ctx->fork( 'auth-expired-session-token' ), 32 );
			self::register_session_token( (int) $user->ID, $expired_session_token, $now - 10 );
			$expired_session_cookie = \wp_generate_auth_cookie( (int) $user->ID, $grace_expires, $scheme, $expired_session_token );

			unset( $GLOBALS['login_grace_period'] );
			$expired_session_result = \wp_validate_auth_cookie( $expired_session_cookie, $scheme );
			$expired_session_grace  = $GLOBALS['login_grace_period'] ?? null;

			$wrong_user_token = self::token( $ctx->fork( 'auth-wrong-user-token' ), 32 );
			self::register_session_token( (int) $other_user->ID, $wrong_user_token, $session_expires );
			$wrong_user_cookie = \wp_generate_auth_cookie( (int) $user->ID, $grace_expires, $scheme, $wrong_user_token );

			unset( $GLOBALS['login_grace_period'] );
			$wrong_user_result = \wp_validate_auth_cookie( $wrong_user_cookie, $scheme );
			$wrong_user_grace  = $GLOBALS['login_grace_period'] ?? null;

			self::collect_failure(
				$failures,
				false === $outside_result
					&& null === $outside_grace
					&& false === $expired_session_result
					&& null === $expired_session_grace
					&& false === $wrong_user_result
					&& null === $wrong_user_grace,
				'auth cookie validation rejects outside-grace and bad session-token edges',
				array(
					'user'                   => self::describe_user( $user ),
					'otherUser'              => self::describe_user( $other_user ),
					'outsideExpiration'      => $outside_expires,
					'outsideResult'          => $outside_result,
					'outsideGrace'           => $outside_grace,
					'expiredSessionResult'   => $expired_session_result,
					'expiredSessionGrace'    => $expired_session_grace,
					'wrongUserSessionResult' => $wrong_user_result,
					'wrongUserSessionGrace'  => $wrong_user_grace,
				)
			);
		} finally {
			\remove_filter( 'wp_doing_ajax', $ajax_filter );
			if ( $had_method ) {
				$_SERVER['REQUEST_METHOD'] = $previous_method;
			} else {
				unset( $_SERVER['REQUEST_METHOD'] );
			}

			if ( $had_grace ) {
				$GLOBALS['login_grace_period'] = $previous_grace;
			} else {
				unset( $GLOBALS['login_grace_period'] );
			}
		}

		return self::row(
			$ctx,
			'security.auth-cookie.grace-period-and-session-token-edges',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_redirect_filters_without_headers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		\add_filter( 'wp_redirect', array( __CLASS__, 'filter_wp_redirect_capture' ), 10, 2 );
		\add_filter( 'wp_redirect_status', array( __CLASS__, 'filter_wp_redirect_status_capture' ), 10, 2 );
		\add_filter( 'wp_safe_redirect_fallback', array( __CLASS__, 'filter_safe_redirect_fallback' ), 10, 2 );
		\add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ), 10, 2 );

		$headers_before = function_exists( 'headers_list' ) ? headers_list() : array();

		$raw_location = "https://example.test/wp-admin/path with spaces?x=1%0d%0aInjected:bad&data=" . rawurlencode( $ctx->text( 0, 48 ) );
		$sanitized    = \wp_sanitize_redirect( $raw_location );
		self::collect_failure(
			$failures,
			false === strpos( strtolower( $sanitized ), '%0d' )
				&& false === strpos( strtolower( $sanitized ), '%0a' )
				&& false === strpos( $sanitized, ' ' ),
			'wp_sanitize_redirect strips CRLF and encodes spaces',
			array(
				'raw'       => self::describe_string( $raw_location ),
				'sanitized' => self::describe_string( $sanitized ),
			)
		);

		self::$redirect_events        = array();
		self::$redirect_status_events = array();
		$redirect_result             = \wp_redirect( $raw_location, 302, 'ComponentFuzz' );

		self::collect_failure(
			$failures,
			false === $redirect_result
				&& isset( self::$redirect_events[0]['location'], self::$redirect_events[0]['status'] )
				&& $raw_location === self::$redirect_events[0]['location']
				&& 302 === self::$redirect_events[0]['status']
				&& isset( self::$redirect_status_events[0]['status'] )
				&& 302 === self::$redirect_status_events[0]['status'],
			'wp_redirect filters can cancel before headers are emitted',
			array(
				'result'       => $redirect_result,
				'events'       => self::$redirect_events,
				'statusEvents' => self::$redirect_status_events,
			)
		);

		self::$redirect_events        = array();
		self::$redirect_status_events = array();
		self::$safe_redirect_fallback = 'http://example.test/wp/wp-admin/fallback.php?from=fuzz';
		$safe_result                  = \wp_safe_redirect( 'https://evil.test/path?x=1', 303, 'ComponentFuzz' );

		self::collect_failure(
			$failures,
			false === $safe_result
				&& isset( self::$redirect_events[0]['location'] )
				&& self::$safe_redirect_fallback === self::$redirect_events[0]['location']
				&& 303 === self::$redirect_events[0]['status'],
			'wp_safe_redirect falls back before canceled wp_redirect',
			array(
				'result'   => $safe_result,
				'fallback' => self::$safe_redirect_fallback,
				'events'   => self::$redirect_events,
			)
		);

		self::$redirect_events         = array();
		self::$allowed_redirect_hosts = array( 'allowed.example' );
		$allowed_location             = 'https://allowed.example/path?x=1&pipe=|';
		$expected_allowed_location    = \wp_sanitize_redirect( $allowed_location );
		$allowed_result               = \wp_safe_redirect( $allowed_location, 307, 'ComponentFuzz' );

		self::collect_failure(
			$failures,
			false === $allowed_result
				&& isset( self::$redirect_events[0]['location'] )
				&& $expected_allowed_location === self::$redirect_events[0]['location']
				&& 307 === self::$redirect_events[0]['status'],
			'wp_safe_redirect honors scoped allowed_redirect_hosts filter',
			array(
				'result'   => $allowed_result,
				'allowed'  => self::$allowed_redirect_hosts,
				'events'   => self::$redirect_events,
				'location' => $allowed_location,
				'expected' => $expected_allowed_location,
			)
		);

		$headers_after = function_exists( 'headers_list' ) ? headers_list() : array();
		self::collect_failure(
			$failures,
			$headers_before === $headers_after,
			'redirect checks do not emit headers',
			array(
				'before' => $headers_before,
				'after'  => $headers_after,
			)
		);

		return self::row(
			$ctx,
			'security.redirect.filters-and-safe-fallback-no-headers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_validate_redirect_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$fallback = 'http://example.test/wp/wp-admin/fallback.php?from=validate';
		$token    = self::token( $ctx->fork( 'redirect-token' ), 8 );

		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=component-fuzz&token=' . rawurlencode( $token );

		$self_host_path = 'http://example.test/wp-admin/dashboard.php?x=' . rawurlencode( $ctx->text( 0, 24 ) );
		$raw_crlf       = "http://example.test/path with spaces?x=1%0d%0aInjected:bad&token={$token}";
		$cases          = array(
			array(
				'label'    => 'relative-path-expanded-beside-current-admin-request',
				'input'    => 'settings.php?updated=1&token=' . rawurlencode( $token ),
				'expected' => '/wp-admin/settings.php?updated=1&token=' . rawurlencode( $token ),
			),
			array(
				'label'    => 'root-relative-path-preserved',
				'input'    => '/wp-admin/options.php?x=1&pipe=|',
				'expected' => \wp_sanitize_redirect( '/wp-admin/options.php?x=1&pipe=|' ),
			),
			array(
				'label'    => 'same-home-host-allowed',
				'input'    => $self_host_path,
				'expected' => \wp_sanitize_redirect( $self_host_path ),
			),
			array(
				'label'    => 'protocol-relative-same-host-normalized-to-http',
				'input'    => '//example.test/wp-admin/profile.php?x=1',
				'expected' => 'http://example.test/wp-admin/profile.php?x=1',
			),
			array(
				'label'    => 'disallowed-host-falls-back',
				'input'    => 'https://evil.test/wp-admin/?x=1',
				'expected' => $fallback,
			),
			array(
				'label'    => 'disallowed-scheme-falls-back',
				'input'    => 'data:text/html,<script>alert(1)</script>',
				'expected' => $fallback,
			),
			array(
				'label'    => 'hostless-scheme-falls-back',
				'input'    => 'https:example.test/wp-admin/',
				'expected' => $fallback,
			),
			array(
				'label'    => 'sanitize-crlf-and-space-on-allowed-host',
				'input'    => $raw_crlf,
				'expected' => \wp_sanitize_redirect( $raw_crlf ),
			),
		);

		foreach ( $cases as $index => $case ) {
			$actual = \wp_validate_redirect( $case['input'], $fallback );
			self::collect_failure(
				$failures,
				$case['expected'] === $actual
					&& false === str_contains( strtolower( $actual ), '%0d' )
					&& false === str_contains( strtolower( $actual ), '%0a' )
					&& false === str_contains( $actual, ' ' ),
				"wp_validate_redirect matrix case {$index}: {$case['label']}",
				array(
					'input'    => self::describe_string( $case['input'] ),
					'expected' => self::describe_string( $case['expected'] ),
					'actual'   => self::describe_string( $actual ),
				)
			);
		}

		self::$allowed_redirect_hosts = array( 'allowed.example' );
		\add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ), 10, 2 );
		try {
			$allowed_location = 'https://allowed.example/wp-admin/?token=' . rawurlencode( $token );
			$allowed_actual   = \wp_validate_redirect( $allowed_location, $fallback );
		} finally {
			\remove_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ), 10 );
			self::$allowed_redirect_hosts = array();
		}

		self::collect_failure(
			$failures,
			$allowed_location === $allowed_actual
				&& false === \has_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ) ),
			'wp_validate_redirect honors scoped allowed_redirect_hosts filters and removes them',
			array(
				'allowedLocation' => self::describe_string( $allowed_location ),
				'actual'          => self::describe_string( $allowed_actual ),
				'filter'          => \has_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ) ),
			)
		);

		return self::row(
			$ctx,
			'security.redirect.validate-location-matrix',
			array() === $failures,
			array(
				'cases'    => count( $cases ) + 1,
				'fallback' => self::describe_string( $fallback ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_redirect_sanitize_validate_metamorphic_behavior( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$fallback        = 'http://example.test/wp/wp-admin/fallback.php?from=metamorphic';
		$token           = self::token( $ctx->fork( 'redirect-metamorphic-token' ), 8 );
		$previous_hosts  = self::$allowed_redirect_hosts;
		$previous_events = self::$allowed_redirect_host_events;

		$_SERVER['REQUEST_URI'] = '/wp-admin/tools.php?page=component-fuzz-security&token=' . rawurlencode( $token );

		$cases = array(
			array(
				'label' => 'trimmed relative path expands from current admin directory',
				'input' => " \tsettings.php?updated=1&token=" . rawurlencode( $token ) . "\n",
			),
			array(
				'label' => 'same-host CRLF and spaces are sanitized',
				'input' => "http://example.test/wp-admin/path with spaces?x=1%0d%0aInjected:bad&token={$token}",
			),
			array(
				'label' => 'query-contained absolute URL stays data, not redirect host',
				'input' => '/wp-admin/admin.php?redirect=http://evil.test/%0d%0a&token=' . rawurlencode( $token ),
			),
			array(
				'label' => 'protocol-relative same host normalizes before host validation',
				'input' => '//example.test/wp-admin/profile.php?token=' . rawurlencode( $token ),
			),
			array(
				'label' => 'uppercase home host follows current case-sensitive host boundary',
				'input' => 'https://EXAMPLE.TEST/wp-admin/?token=' . rawurlencode( $token ),
			),
			array(
				'label' => 'disallowed scheme remains a fallback after sanitization',
				'input' => 'javascript:alert(1)',
			),
			array(
				'label' => 'hostless scheme remains a fallback after sanitization',
				'input' => 'https:example.test/wp-admin/?token=' . rawurlencode( $token ),
			),
			array(
				'label' => 'generated same-host bytes are sanitize-idempotent',
				'input' => 'http://example.test/wp-admin/' . self::random_string( $ctx->fork( 'redirect-generated-path' ), $ctx->int( 0, 96 ) ),
			),
		);

		foreach ( $cases as $index => $case ) {
			$trimmed             = trim( $case['input'], " \t\n\r\0\x08\x0B" );
			$sanitized           = \wp_sanitize_redirect( $case['input'] );
			$trimmed_sanitized   = \wp_sanitize_redirect( $trimmed );
			$sanitized_twice     = \wp_sanitize_redirect( $sanitized );
			$validated           = \wp_validate_redirect( $case['input'], $fallback );
			$validated_sanitized = \wp_validate_redirect( $trimmed_sanitized, $fallback );
			$validated_trimmed   = \wp_validate_redirect( $trimmed, $fallback );

			self::collect_failure(
				$failures,
				$sanitized === $sanitized_twice
					&& $trimmed_sanitized === \wp_sanitize_redirect( $trimmed_sanitized )
					&& $validated === $validated_sanitized
					&& $validated === $validated_trimmed
					&& self::redirect_value_is_clean( $sanitized )
					&& self::redirect_value_is_clean( $validated ),
				"redirect sanitize/validate metamorphic case {$index}: {$case['label']}",
				array(
					'input'              => self::describe_string( $case['input'] ),
					'trimmed'            => self::describe_string( $trimmed ),
					'sanitized'          => self::describe_string( $sanitized ),
					'trimmedSanitized'   => self::describe_string( $trimmed_sanitized ),
					'sanitizedTwice'     => self::describe_string( $sanitized_twice ),
					'validated'          => self::describe_string( $validated ),
					'validatedSanitized' => self::describe_string( $validated_sanitized ),
					'validatedTrimmed'   => self::describe_string( $validated_trimmed ),
				)
			);
		}

		self::$allowed_redirect_hosts       = array( 'allowed.example' );
		self::$allowed_redirect_host_events = array();
		\add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ), 10, 2 );
		try {
			$allowed_location    = 'https://allowed.example:8443/wp-admin/?token=' . rawurlencode( $token );
			$disallowed_location = 'https://allowed.example.evil.test/wp-admin/?token=' . rawurlencode( $token );
			$hostless_location   = '/wp-admin/hostless.php?token=' . rawurlencode( $token );
			$events              = array();
			$allowed_actual      = \wp_validate_redirect( $allowed_location, $fallback );
			$disallowed_actual   = \wp_validate_redirect( $disallowed_location, $fallback );
			$hostless_actual     = \wp_validate_redirect( $hostless_location, $fallback );
			$events              = self::$allowed_redirect_host_events;
		} finally {
			\remove_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ), 10 );
			self::$allowed_redirect_hosts       = $previous_hosts;
			self::$allowed_redirect_host_events = $previous_events;
		}

		$event_hosts = array();
		foreach ( $events as $event ) {
			$event_hosts[] = $event['host'];
		}

		self::collect_failure(
			$failures,
			\wp_sanitize_redirect( $allowed_location ) === $allowed_actual
				&& $fallback === $disallowed_actual
				&& \wp_sanitize_redirect( $hostless_location ) === $hostless_actual
				&& in_array( 'allowed.example', $event_hosts, true )
				&& in_array( 'allowed.example.evil.test', $event_hosts, true )
				&& in_array( '', $event_hosts, true )
				&& false === \has_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ) ),
			'allowed_redirect_hosts filter observes exact destination host boundaries',
			array(
				'allowedLocation'    => self::describe_string( $allowed_location ),
				'allowedActual'      => self::describe_string( $allowed_actual ),
				'disallowedLocation' => self::describe_string( $disallowed_location ),
				'disallowedActual'   => self::describe_string( $disallowed_actual ),
				'hostlessLocation'   => self::describe_string( $hostless_location ),
				'hostlessActual'     => self::describe_string( $hostless_actual ),
				'events'             => $events,
				'filterStillActive'  => \has_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ) ),
			)
		);

		return self::row(
			$ctx,
			'security.redirect.sanitize-validate-metamorphic-boundaries',
			array() === $failures,
			array(
				'cases'    => count( $cases ) + 1,
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function install_scoped_filters(): void {
		\add_filter( 'pre_option_home', array( __CLASS__, 'filter_home_option' ), 10, 3 );
		\add_filter( 'pre_option_siteurl', array( __CLASS__, 'filter_siteurl_option' ), 10, 3 );
		\add_filter( 'pre_site_option_siteurl', array( __CLASS__, 'filter_siteurl_option' ), 10, 3 );
		\add_filter( 'nonce_user_logged_out', array( __CLASS__, 'filter_nonce_user_logged_out' ), 10, 2 );
		\add_filter( 'nonce_life', array( __CLASS__, 'filter_nonce_life' ), 10, 2 );
		\add_filter( 'session_token_manager', array( __CLASS__, 'filter_session_token_manager' ) );
		\add_filter( 'get_user_metadata', array( __CLASS__, 'filter_user_metadata' ), 10, 5 );
	}

	private static function reset_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-admin/admin.php?page=component-fuzz';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.42';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/security';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['SERVER_PORT']     = '80';

		$GLOBALS['is_IIS']       = false;
		$GLOBALS['current_user'] = new \WP_User( 0 );
	}

	private static function reset_static_state(): void {
		self::$nonce_logged_out_users = array();
		self::$nonce_life             = 1000000000;
		self::$nonce_life_by_action   = array();
		self::$session_tokens         = array();
		self::$session_seed           = 'security';
		self::$redirect_events               = array();
		self::$redirect_status_events        = array();
		self::$allowed_redirect_hosts        = array();
		self::$allowed_redirect_host_events  = array();
		self::$safe_redirect_fallback        = null;
	}

	private static function synthetic_user( \ComponentFuzz\FuzzContext $ctx ): \stdClass {
		$id    = 50000 + $ctx->int( 0, 9999 );
		$login = 'fuzz_user_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 12 );

		return (object) array(
			'ID'              => $id,
			'user_login'      => $login,
			'user_pass'       => 'component-pass-' . hash( 'sha256', $login . '|' . $id ),
			'user_nicename'   => $login,
			'user_email'      => $login . '@example.test',
			'user_url'        => 'https://example.test/users/' . $login,
			'user_registered' => '2024-01-01 00:00:00',
			'user_activation_key' => '',
			'user_status'     => 0,
			'display_name'    => 'Fuzz User ' . $id,
		);
	}

	private static function prime_synthetic_user( \stdClass $user ): void {
		\wp_cache_set( (int) $user->ID, $user, 'users' );
		\wp_cache_set( $user->user_login, (int) $user->ID, 'userlogins' );
		\wp_cache_set( $user->user_nicename, (int) $user->ID, 'userslugs' );
		\wp_cache_set( $user->user_email, (int) $user->ID, 'useremail' );
		\wp_cache_set(
			(int) $user->ID,
			array(
				'wp_capabilities' => array( array() ),
			),
			'user_meta'
		);
	}

	private static function expected_auth_cookie_hmac( \stdClass $user, int $expiration, string $scheme, string $token ): string {
		$pass_frag = ( str_starts_with( $user->user_pass, '$P$' ) || str_starts_with( $user->user_pass, '$2y$' ) )
			? substr( $user->user_pass, 8, 4 )
			: substr( $user->user_pass, -4 );
		$key       = \wp_hash( $user->user_login . '|' . $pass_frag . '|' . $expiration . '|' . $token, $scheme );

		return hash_hmac( 'sha256', $user->user_login . '|' . $expiration . '|' . $token, $key );
	}

	private static function nonce_for_tick( $tick, $action ): string {
		$user = \wp_get_current_user();
		$uid  = (int) $user->ID;
		if ( ! $uid ) {
			$uid = \apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		return substr( \wp_hash( $tick . '|' . $action . '|' . $uid . '|' . \wp_get_session_token(), 'nonce' ), -12, 10 );
	}

	private static function cookie_name_for_scheme( string $scheme ): string {
		if ( 'secure_auth' === $scheme ) {
			return SECURE_AUTH_COOKIE;
		}

		if ( 'logged_in' === $scheme ) {
			return LOGGED_IN_COOKIE;
		}

		return AUTH_COOKIE;
	}

	private static function redirect_value_is_clean( string $value ): bool {
		$lower = strtolower( $value );

		return false === str_contains( $lower, '%0d' )
			&& false === str_contains( $lower, '%0a' )
			&& false === str_contains( $value, ' ' )
			&& false === strpbrk( $value, "\r\n\0" );
	}

	private static function hidden_inputs( string $html ): array {
		$inputs = array();
		if ( ! preg_match_all( '/<input\s+([^>]+?)\s*\/?>/i', $html, $matches ) ) {
			return $inputs;
		}

		foreach ( $matches[1] as $attribute_string ) {
			$attributes = array();
			if ( preg_match_all( '/([a-zA-Z0-9_:-]+)="([^"]*)"/', $attribute_string, $attribute_matches, PREG_SET_ORDER ) ) {
				foreach ( $attribute_matches as $attribute_match ) {
					$attributes[ $attribute_match[1] ] = html_entity_decode( $attribute_match[2], ENT_QUOTES, 'UTF-8' );
				}
			}
			$inputs[] = $attributes;
		}

		return $inputs;
	}

	private static function action_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$actions = array(
			-1,
			0,
			'',
			'plain-action',
			'action|with|pipes',
			"unicode-\xC3\xA9-\xE2\x98\x83",
			"invalid-\x80\xFF-bytes",
			"reserved:/?#[]@!$&'()*+,;=",
			str_repeat( 'long-action-', 32 ),
		);

		foreach ( self::generated_strings( $ctx, self::GENERATED_STRING_CASES, 192 ) as $generated ) {
			$actions[] = $generated;
		}

		return $actions;
	}

	private static function data_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			'',
			'plain text',
			"unicode-\xC3\xA9-\xE2\x98\x83-\xF0\x9F\x98\x80",
			"invalid-\x80\xFF-bytes",
			"line1\r\nline2\twith|pipes",
			"reserved:/?#[]@!$&'()*+,;=%",
			str_repeat( 'A', 512 ),
		);

		foreach ( self::generated_strings( $ctx, self::GENERATED_STRING_CASES, 256 ) as $generated ) {
			$cases[] = $generated;
		}

		return $cases;
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

	private static function mutate_action( $action ) {
		if ( is_int( $action ) ) {
			return $action + 1;
		}

		return (string) $action . '|mutated';
	}

	private static function invalid_nonce( string $nonce ): string {
		$invalid = strtr( $nonce, '0123456789abcdef', 'fedcba9876543210' );
		if ( $invalid === $nonce ) {
			$invalid = strrev( $nonce );
		}
		if ( $invalid === $nonce ) {
			$invalid = '0000000000' === $nonce ? '1111111111' : '0000000000';
		}
		return $invalid;
	}

	private static function action_key( $action ): string {
		return is_int( $action ) ? 'int:' . $action : 'string:' . (string) $action;
	}

	private static function token( \ComponentFuzz\FuzzContext $ctx, int $length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $ctx->int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
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

	private static function describe_user( \stdClass $user ): array {
		return array(
			'ID'         => (int) $user->ID,
			'userLogin'  => $user->user_login,
			'userEmail'  => $user->user_email,
			'passSha256' => hash( 'sha256', $user->user_pass ),
		);
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
		);

		foreach (
			array(
				'current_user',
				'is_IIS',
				'login_grace_period',
				'userdata',
				'user_email',
				'user_identity',
				'user_level',
				'user_login',
				'user_url',
				'user_ID',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_roles',
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

final class SecuritySurface_SessionTokenManager {
	private int $user_id;

	public function __construct( int $user_id ) {
		$this->user_id = $user_id;
	}

	public function verify( string $token ): bool {
		return SecuritySurface::session_token_valid( $this->user_id, $token );
	}

	public function create( int $expiration ): string {
		return SecuritySurface::create_session_token( $this->user_id, $expiration );
	}

	public function get_all(): array {
		return SecuritySurface::session_tokens_for_user( $this->user_id );
	}
}
