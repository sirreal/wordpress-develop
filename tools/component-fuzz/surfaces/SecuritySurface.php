<?php
namespace ComponentFuzz\Surfaces;

final class SecuritySurface {
	public const NAME = 'security';

	private const GENERATED_STRING_CASES = 8;
	private const SAMPLE_BYTES           = 160;

	/** @var array<string,int> */
	private static array $nonce_logged_out_users = array();

	private static int $nonce_life = 1000000000;

	/** @var array<int,array<string,array<string,int>>> */
	private static array $session_tokens = array();

	private static string $session_seed = 'security';

	/** @var array<int,array<string,mixed>> */
	private static array $redirect_events = array();

	/** @var array<int,array<string,mixed>> */
	private static array $redirect_status_events = array();

	/** @var string[] */
	private static array $allowed_redirect_hosts = array();

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
			$rows[] = self::check_nonce_metamorphic_behavior( $ctx );
			$rows[] = self::check_nonce_url_and_fields( $ctx );
			$rows[] = self::check_ajax_referer_lookup_order( $ctx );
			$rows[] = self::check_admin_referer_valid_paths( $ctx );
			$rows[] = self::check_auth_cookie_structure_and_validation( $ctx );
			$rows[] = self::check_redirect_filters_without_headers( $ctx );
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
		unset( $lifespan, $action );
		return self::$nonce_life;
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
		unset( $host );
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
				'check_admin_referer',
				'check_ajax_referer',
				'esc_attr',
				'esc_html',
				'esc_url',
				'get_userdata',
				'hash_equals',
				'hash_hmac_algos',
				'remove_filter',
				'remove_query_arg',
				'update_user_caches',
				'wp_create_nonce',
				'wp_check_password',
				'wp_fast_hash',
				'wp_generate_auth_cookie',
				'wp_hash',
				'wp_hash_password',
				'wp_nonce_field',
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
		self::$session_tokens         = array();
		self::$session_seed           = 'security';
		self::$redirect_events        = array();
		self::$redirect_status_events = array();
		self::$allowed_redirect_hosts = array();
		self::$safe_redirect_fallback = null;
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

	private static function cookie_name_for_scheme( string $scheme ): string {
		if ( 'secure_auth' === $scheme ) {
			return SECURE_AUTH_COOKIE;
		}

		if ( 'logged_in' === $scheme ) {
			return LOGGED_IN_COOKIE;
		}

		return AUTH_COOKIE;
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
