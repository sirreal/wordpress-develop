<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB authentication and session flows around synthetic users.
 */
final class AuthFlowSurface {
	public const NAME = 'auth-flow';

	private const GENERATED_COOKIE_CASES = 12;
	private const SAMPLE_BYTES           = 160;

	private static int $auth_cookie_lifetime = 7200;

	/** @var array<string,array<int,array<string,mixed>>> */
	private static array $events = array();

	/** @var array<string,mixed> */
	private static array $session_attachment = array();

	private static string $password_seed    = 'auth-flow';
	private static int $password_counter    = 0;
	private static ?string $short_circuit_username = null;
	private static ?\WP_User $short_circuit_user   = null;
	private static ?string $authenticate_user_deny = null;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'auth-flow.bootstrap-apis-available',
					'Required WordPress authentication APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_static_state();
			self::$password_seed = (string) $ctx->seed();
			self::reset_runtime();
			self::install_default_auth_filters();

			$rows[] = self::check_synthetic_user_rows( $ctx->fork( 'user-rows' ) );
			$rows[] = self::check_authenticate_filter_and_password_paths( $ctx->fork( 'authenticate' ) );
			$rows[] = self::check_signon_cookie_actions_without_headers( $ctx->fork( 'signon' ) );
			$rows[] = self::check_clear_auth_cookie_without_headers( $ctx->fork( 'clear-cookie' ) );
			$rows[] = self::check_cookie_authentication_paths( $ctx->fork( 'cookie-auth' ) );
			$rows[] = self::check_auth_cookie_validation_events( $ctx->fork( 'cookie-events' ) );
			$rows[] = self::check_generated_cookie_scheme_boundaries( $ctx->fork( 'cookie-schemes' ) );
			$rows[] = self::check_session_token_lifecycle( $ctx->fork( 'session-lifecycle' ) );
			$rows[] = self::check_current_user_cookie_restoration( $ctx->fork( 'restoration' ) );
			$rows[] = self::check_parse_auth_cookie_generated_failures( $ctx->fork( 'parse-failures' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'auth-flow.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_static_state();
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	public static function filter_send_auth_cookies( bool $send, int $expire, int $expiration, int $user_id, string $scheme, string $token ): bool {
		self::record_event(
			'send_auth_cookies',
			array(
				'send'       => $send,
				'expire'     => $expire,
				'expiration' => $expiration,
				'userId'     => $user_id,
				'scheme'     => $scheme,
				'tokenSha1'  => sha1( $token ),
				'returned'   => false,
			)
		);

		return false;
	}

	public static function filter_auth_cookie_expiration( int $length, int $user_id, bool $remember ): int {
		self::record_event(
			'auth_cookie_expiration',
			array(
				'defaultLength' => $length,
				'userId'        => $user_id,
				'remember'      => $remember,
				'returned'      => self::$auth_cookie_lifetime,
			)
		);

		return self::$auth_cookie_lifetime;
	}

	public static function filter_auth_cookie( string $cookie, int $user_id, int $expiration, string $scheme, string $token ): string {
		$parsed = \wp_parse_auth_cookie( $cookie, $scheme );

		self::record_event(
			'auth_cookie',
			array(
				'cookieSha1' => sha1( $cookie ),
				'bytes'      => strlen( $cookie ),
				'userId'     => $user_id,
				'expiration' => $expiration,
				'scheme'     => $scheme,
				'tokenSha1'  => sha1( $token ),
				'parsed'     => is_array( $parsed )
					? array(
						'username'   => $parsed['username'],
						'expiration' => $parsed['expiration'],
						'tokenSha1'  => sha1( $parsed['token'] ),
						'hmacSha1'   => sha1( $parsed['hmac'] ),
						'scheme'     => $parsed['scheme'],
					)
					: false,
			)
		);

		return $cookie;
	}

	public static function filter_attach_session_information( array $session, int $user_id ): array {
		self::record_event(
			'attach_session_information',
			array(
				'userId' => $user_id,
				'before' => $session,
			)
		);

		return array_merge( $session, self::$session_attachment );
	}

	public static function filter_random_password( string $password, int $length, bool $special_chars, bool $extra_special_chars ): string {
		unset( $password, $special_chars, $extra_special_chars );

		$out = '';
		while ( strlen( $out ) < $length ) {
			$out .= hash( 'sha256', self::$password_seed . '|' . self::$password_counter );
			++self::$password_counter;
		}

		return substr( $out, 0, $length );
	}

	public static function filter_authenticate_short_circuit( $user, string $username, string $password ) {
		self::record_event(
			'authenticate',
			array(
				'username'    => $username,
				'passwordSha' => sha1( $password ),
				'userType'    => is_object( $user ) ? get_class( $user ) : gettype( $user ),
			)
		);

		if ( null !== self::$short_circuit_user && self::$short_circuit_username === $username ) {
			return self::$short_circuit_user;
		}

		return $user;
	}

	public static function filter_wp_authenticate_user( $user, string $password ) {
		self::record_event(
			'wp_authenticate_user',
			array(
				'userId'      => $user instanceof \WP_User ? (int) $user->ID : null,
				'passwordSha' => sha1( $password ),
			)
		);

		if ( null !== self::$authenticate_user_deny ) {
			return new \WP_Error( self::$authenticate_user_deny, 'Denied by auth-flow fuzzer.' );
		}

		return $user;
	}

	public static function action_wp_login_failed( string $username, \WP_Error $error ): void {
		self::record_event(
			'wp_login_failed',
			array(
				'username' => $username,
				'codes'    => $error->get_error_codes(),
			)
		);
	}

	public static function action_set_auth_cookie( string $cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token ): void {
		self::record_event(
			'set_auth_cookie',
			array(
				'cookie'     => self::cookie_summary( $cookie ),
				'expire'     => $expire,
				'expiration' => $expiration,
				'userId'     => $user_id,
				'scheme'     => $scheme,
				'tokenSha1'  => sha1( $token ),
			)
		);
	}

	public static function action_set_logged_in_cookie( string $cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token ): void {
		self::record_event(
			'set_logged_in_cookie',
			array(
				'cookie'     => self::cookie_summary( $cookie ),
				'expire'     => $expire,
				'expiration' => $expiration,
				'userId'     => $user_id,
				'scheme'     => $scheme,
				'tokenSha1'  => sha1( $token ),
			)
		);
	}

	public static function action_wp_login( string $user_login, \WP_User $user ): void {
		self::record_event(
			'wp_login',
			array(
				'userLogin' => $user_login,
				'userId'    => (int) $user->ID,
			)
		);
	}

	public static function action_clear_auth_cookie(): void {
		self::record_event( 'clear_auth_cookie', array() );
	}

	public static function action_auth_cookie_malformed( string $cookie, string $scheme ): void {
		self::record_cookie_event( 'auth_cookie_malformed', $cookie, $scheme );
	}

	public static function action_auth_cookie_expired( array $cookie_elements ): void {
		self::record_cookie_elements_event( 'auth_cookie_expired', $cookie_elements );
	}

	public static function action_auth_cookie_bad_username( array $cookie_elements ): void {
		self::record_cookie_elements_event( 'auth_cookie_bad_username', $cookie_elements );
	}

	public static function action_auth_cookie_bad_hash( array $cookie_elements ): void {
		self::record_cookie_elements_event( 'auth_cookie_bad_hash', $cookie_elements );
	}

	public static function action_auth_cookie_bad_session_token( array $cookie_elements ): void {
		self::record_cookie_elements_event( 'auth_cookie_bad_session_token', $cookie_elements );
	}

	public static function action_auth_cookie_valid( array $cookie_elements, \WP_User $user ): void {
		self::record_cookie_elements_event(
			'auth_cookie_valid',
			array_merge(
				$cookie_elements,
				array( 'validatedUserId' => (int) $user->ID )
			)
		);
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Error', 'WP_Session_Tokens', 'WP_User', 'WP_User_Meta_Session_Tokens' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_get_current_user',
				'add_action',
				'add_filter',
				'get_current_user_id',
				'get_user_by',
				'get_user_meta',
				'get_userdata',
				'is_user_logged_in',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'sanitize_user',
				'update_user_meta',
				'wp_authenticate',
				'wp_authenticate_cookie',
				'wp_authenticate_email_password',
				'wp_authenticate_username_password',
				'wp_cache_flush',
				'wp_check_password',
				'wp_clear_auth_cookie',
				'wp_destroy_all_sessions',
				'wp_destroy_current_session',
				'wp_destroy_other_sessions',
				'wp_generate_auth_cookie',
				'wp_get_all_sessions',
				'wp_get_current_user',
				'wp_get_session_token',
				'wp_hash_password',
				'wp_is_unicode_email',
				'wp_parse_auth_cookie',
				'wp_sanitize_unicode_email',
				'wp_set_auth_cookie',
				'wp_set_current_user',
				'wp_signon',
				'wp_unslash',
				'wp_validate_auth_cookie',
				'wp_validate_logged_in_cookie',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach (
			array(
				'ADMIN_COOKIE_PATH',
				'AUTH_COOKIE',
				'COOKIE_DOMAIN',
				'COOKIEPATH',
				'DAY_IN_SECONDS',
				'HOUR_IN_SECONDS',
				'LOGGED_IN_COOKIE',
				'PLUGINS_COOKIE_PATH',
				'SECURE_AUTH_COOKIE',
				'SITECOOKIEPATH',
				'YEAR_IN_SECONDS',
			) as $constant
		) {
			if ( ! defined( $constant ) ) {
				$missing[] = "constant {$constant}";
			}
		}

		return $missing;
	}

	private static function check_synthetic_user_rows( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();

		$failures       = array();
		$user_spec      = self::user_spec( $ctx, 'row', 'row-activation-' . $ctx->int( 100, 999 ) );
		$user           = self::insert_synthetic_user( $user_spec );
		$by_id          = \get_userdata( $user_spec['ID'] );
		$by_login       = \get_user_by( 'login', $user_spec['user_login'] );
		$by_email       = \get_user_by( 'email', strtoupper( $user_spec['user_email'] ) );
		$stored_caps    = \get_user_meta( $user_spec['ID'], 'wp_capabilities', true );
		$stored_session = \get_user_meta( $user_spec['ID'], 'session_tokens', true );

		self::collect_failure(
			$failures,
			$user instanceof \WP_User
				&& $by_id instanceof \WP_User
				&& $by_login instanceof \WP_User
				&& $by_email instanceof \WP_User
				&& (int) $by_id->ID === $user_spec['ID']
				&& (int) $by_login->ID === $user_spec['ID']
				&& (int) $by_email->ID === $user_spec['ID']
				&& \wp_check_password( $user_spec['plain_password'], $user->user_pass, $user_spec['ID'] )
				&& ! \wp_check_password( self::mutate_secret( $user_spec['plain_password'] ), $user->user_pass, $user_spec['ID'] )
				&& array() === $stored_caps
				&& '' === $stored_session,
			'synthetic user row is retrievable by id, login, and case-insensitive email',
			array(
				'user'          => self::describe_user( $user ),
				'byId'          => self::describe_user_result( $by_id ),
				'byLogin'       => self::describe_user_result( $by_login ),
				'byEmail'       => self::describe_user_result( $by_email ),
				'storedCaps'    => $stored_caps,
				'storedSession' => $stored_session,
			)
		);

		return self::row(
			$ctx,
			'auth-flow.synthetic-user-row-stubs',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_authenticate_filter_and_password_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();
		self::clear_events();

		$failures  = array();
		$user_spec = self::user_spec( $ctx, 'auth' );
		$user      = self::insert_synthetic_user( $user_spec );

		\add_action( 'wp_login_failed', array( __CLASS__, 'action_wp_login_failed' ), 10, 2 );
		\add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate_short_circuit' ), 1, 3 );
		\add_filter( 'wp_authenticate_user', array( __CLASS__, 'filter_wp_authenticate_user' ), 10, 2 );

		try {
			$valid_by_login = \wp_authenticate( $user_spec['user_login'], $user_spec['plain_password'] );
			$valid_by_email = \wp_authenticate( strtoupper( $user_spec['user_email'] ), $user_spec['plain_password'] );
			$wrong_password = \wp_authenticate( $user_spec['user_login'], self::mutate_secret( $user_spec['plain_password'] ) );
			$empty          = \wp_authenticate( '', '' );

			self::$authenticate_user_deny = 'component_fuzz_denied';
			$denied                      = \wp_authenticate( $user_spec['user_login'], $user_spec['plain_password'] );
			self::$authenticate_user_deny = null;

			$short_spec                   = self::user_spec( $ctx->fork( 'short-circuit' ), 'short' );
			$short_user                   = self::insert_synthetic_user( $short_spec );
			$raw_short_username           = $short_spec['user_login'] . "%41\n";
			$sanitized_short_username     = \sanitize_user( $raw_short_username );
			self::$short_circuit_username = $sanitized_short_username;
			self::$short_circuit_user     = $short_user;
			$short_circuit                = \wp_authenticate( $raw_short_username, " \t" . $short_spec['plain_password'] . "\n" );
			self::$short_circuit_username = null;
			self::$short_circuit_user     = null;

			$login_failed_events       = self::events( 'wp_login_failed' );
			$authenticate_events       = self::events( 'authenticate' );
			$wp_authenticate_user_hits = self::events( 'wp_authenticate_user' );

			self::collect_failure(
				$failures,
				$valid_by_login instanceof \WP_User
					&& (int) $valid_by_login->ID === $user_spec['ID']
					&& $valid_by_email instanceof \WP_User
					&& (int) $valid_by_email->ID === $user_spec['ID']
					&& self::is_error_code( $wrong_password, 'incorrect_password' )
					&& self::is_error_code( $empty, 'empty_username' )
					&& self::is_error_code( $empty, 'empty_password' )
					&& self::is_error_code( $denied, 'component_fuzz_denied' )
					&& $short_circuit instanceof \WP_User
					&& (int) $short_circuit->ID === $short_spec['ID'],
				'username, email, wrong-password, empty, deny-filter, and authenticate short-circuit paths',
				array(
					'user'           => self::describe_user( $user ),
					'validByLogin'   => self::describe_user_result( $valid_by_login ),
					'validByEmail'   => self::describe_user_result( $valid_by_email ),
					'wrongPassword'  => self::describe_error( $wrong_password ),
					'empty'          => self::describe_error( $empty ),
					'denied'         => self::describe_error( $denied ),
					'shortCircuit'   => self::describe_user_result( $short_circuit ),
					'sanitizedShort' => $sanitized_short_username,
				)
			);

			self::collect_failure(
				$failures,
				count( $login_failed_events ) >= 2
					&& self::events_include_code( $login_failed_events, 'incorrect_password' )
					&& self::events_include_code( $login_failed_events, 'component_fuzz_denied' )
					&& ! self::events_include_code( $login_failed_events, 'empty_username' )
					&& count( $wp_authenticate_user_hits ) >= 4
					&& count( $authenticate_events ) >= 6,
				'authenticate and wp_authenticate_user filters are applied and login-failed ignores empty-field errors',
				array(
					'loginFailed'        => $login_failed_events,
					'authenticateEvents' => array_slice( $authenticate_events, 0, 8 ),
					'userFilterEvents'   => array_slice( $wp_authenticate_user_hits, 0, 8 ),
				)
			);
		} finally {
			\remove_action( 'wp_login_failed', array( __CLASS__, 'action_wp_login_failed' ), 10 );
			\remove_filter( 'authenticate', array( __CLASS__, 'filter_authenticate_short_circuit' ), 1 );
			\remove_filter( 'wp_authenticate_user', array( __CLASS__, 'filter_wp_authenticate_user' ), 10 );
			self::$short_circuit_username = null;
			self::$short_circuit_user     = null;
			self::$authenticate_user_deny = null;
		}

		return self::row(
			$ctx,
			'auth-flow.authenticate-filters-password-paths',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_signon_cookie_actions_without_headers( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();
		self::clear_events();

		$failures                  = array();
		$user_spec                 = self::user_spec( $ctx, 'signon', 'activation-' . $ctx->int( 10000, 99999 ) );
		$user                      = self::insert_synthetic_user( $user_spec );
		$remember                  = $ctx->bool();
		$secure_cookie             = $ctx->bool();
		self::$auth_cookie_lifetime = 3600 + $ctx->int( 0, 7200 );
		$headers_before            = function_exists( 'headers_list' ) ? headers_list() : array();

		\add_filter( 'auth_cookie_expiration', array( __CLASS__, 'filter_auth_cookie_expiration' ), 10, 3 );
		\add_filter( 'send_auth_cookies', array( __CLASS__, 'filter_send_auth_cookies' ), 10, 6 );
		\add_action( 'set_auth_cookie', array( __CLASS__, 'action_set_auth_cookie' ), 10, 6 );
		\add_action( 'set_logged_in_cookie', array( __CLASS__, 'action_set_logged_in_cookie' ), 10, 6 );
		\add_action( 'wp_login', array( __CLASS__, 'action_wp_login' ), 10, 2 );

		try {
			$before = time();
			$signed = \wp_signon(
				array(
					'user_login'    => $user_spec['user_login'],
					'user_password' => $user_spec['plain_password'],
					'remember'      => $remember,
				),
				$secure_cookie
			);
			$after  = time();

			$auth_events       = self::events( 'set_auth_cookie' );
			$logged_in_events  = self::events( 'set_logged_in_cookie' );
			$send_events       = self::events( 'send_auth_cookies' );
			$wp_login_events   = self::events( 'wp_login' );
			$expiration_events = self::events( 'auth_cookie_expiration' );
			$headers_after     = function_exists( 'headers_list' ) ? headers_list() : array();
			$db_user           = self::raw_user_row( $user_spec['ID'] );

			$auth_event      = $auth_events[0] ?? array();
			$logged_in_event = $logged_in_events[0] ?? array();
			$auth_cookie     = isset( $auth_event['cookie']['raw'] ) ? $auth_event['cookie']['raw'] : '';
			$logged_cookie   = isset( $logged_in_event['cookie']['raw'] ) ? $logged_in_event['cookie']['raw'] : '';
			$auth_parsed     = \wp_parse_auth_cookie( $auth_cookie, $secure_cookie ? 'secure_auth' : 'auth' );
			$logged_parsed   = \wp_parse_auth_cookie( $logged_cookie, 'logged_in' );
			$expected_expire = $remember && isset( $auth_event['expiration'] )
				? (int) $auth_event['expiration'] + 12 * HOUR_IN_SECONDS
				: 0;

			self::collect_failure(
				$failures,
				$signed instanceof \WP_User
					&& (int) $signed->ID === $user_spec['ID']
					&& '' === $signed->user_activation_key
					&& is_array( $db_user )
					&& '' === (string) $db_user['user_activation_key']
					&& ! \is_user_logged_in(),
				'wp_signon authenticates, clears activation key, and leaves current user unchanged',
				array(
					'user'        => self::describe_user( $user ),
					'signed'      => self::describe_user_result( $signed ),
					'dbUser'      => $db_user,
					'currentUser' => self::describe_user_result( \wp_get_current_user() ),
				)
			);

			self::collect_failure(
				$failures,
				1 === count( $auth_events )
					&& 1 === count( $logged_in_events )
					&& 1 === count( $send_events )
					&& 1 === count( $wp_login_events )
					&& 1 === count( $expiration_events )
					&& isset( $auth_event['expiration'], $auth_event['expire'], $auth_event['scheme'] )
					&& $auth_event['expiration'] >= $before + self::$auth_cookie_lifetime
					&& $auth_event['expiration'] <= $after + self::$auth_cookie_lifetime
					&& $expected_expire === (int) $auth_event['expire']
					&& $auth_event['expire'] === $logged_in_event['expire']
					&& $auth_event['expiration'] === $logged_in_event['expiration']
					&& ( $secure_cookie ? 'secure_auth' : 'auth' ) === $auth_event['scheme']
					&& 'logged_in' === $logged_in_event['scheme']
					&& is_array( $auth_parsed )
					&& is_array( $logged_parsed )
					&& $auth_parsed['token'] === $logged_parsed['token']
					&& true === $send_events[0]['send']
					&& false === $send_events[0]['returned']
					&& $headers_before === $headers_after,
				'wp_signon emits scoped cookie actions, shares one session token, and send_auth_cookies prevents headers',
				array(
					'remember'         => $remember,
					'secureCookie'     => $secure_cookie,
					'authEvents'       => $auth_events,
					'loggedInEvents'   => $logged_in_events,
					'sendEvents'       => $send_events,
					'wpLoginEvents'    => $wp_login_events,
					'expirationEvents' => $expiration_events,
					'authParsed'       => $auth_parsed,
					'loggedParsed'     => $logged_parsed,
					'headersBefore'    => $headers_before,
					'headersAfter'     => $headers_after,
				)
			);
		} finally {
			\remove_filter( 'auth_cookie_expiration', array( __CLASS__, 'filter_auth_cookie_expiration' ), 10 );
			\remove_filter( 'send_auth_cookies', array( __CLASS__, 'filter_send_auth_cookies' ), 10 );
			\remove_action( 'set_auth_cookie', array( __CLASS__, 'action_set_auth_cookie' ), 10 );
			\remove_action( 'set_logged_in_cookie', array( __CLASS__, 'action_set_logged_in_cookie' ), 10 );
			\remove_action( 'wp_login', array( __CLASS__, 'action_wp_login' ), 10 );
			\remove_filter( 'authenticate', 'wp_authenticate_cookie', 30 );
		}

		return self::row(
			$ctx,
			'auth-flow.signon-cookie-actions-no-headers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_clear_auth_cookie_without_headers( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();
		self::clear_events();

		$failures       = array();
		$headers_before = function_exists( 'headers_list' ) ? headers_list() : array();
		$cookie_value   = implode(
			'|',
			array(
				str_replace( '|', '', $ctx->identifier( 4, 12 ) ),
				(string) ( time() + HOUR_IN_SECONDS ),
				str_replace( '|', '', $ctx->identifier( 4, 16 ) ),
				str_replace( '|', '', $ctx->identifier( 8, 32 ) ),
			)
		);

		$_COOKIE[ AUTH_COOKIE ]        = $cookie_value;
		$_COOKIE[ SECURE_AUTH_COOKIE ] = strrev( $cookie_value );
		$_COOKIE[ LOGGED_IN_COOKIE ]   = $cookie_value . '-logged-in';

		\add_filter( 'send_auth_cookies', array( __CLASS__, 'filter_send_auth_cookies' ), 10, 6 );
		\add_action( 'clear_auth_cookie', array( __CLASS__, 'action_clear_auth_cookie' ), 10, 0 );

		try {
			\wp_clear_auth_cookie();

			$clear_events  = self::events( 'clear_auth_cookie' );
			$send_events   = self::events( 'send_auth_cookies' );
			$send_event    = $send_events[0] ?? array();
			$headers_after = function_exists( 'headers_list' ) ? headers_list() : array();

			self::collect_failure(
				$failures,
				1 === count( $clear_events )
					&& 1 === count( $send_events )
					&& true === ( $send_event['send'] ?? null )
					&& 0 === ( $send_event['expire'] ?? null )
					&& 0 === ( $send_event['expiration'] ?? null )
					&& 0 === ( $send_event['userId'] ?? null )
					&& '' === ( $send_event['scheme'] ?? null )
					&& sha1( '' ) === ( $send_event['tokenSha1'] ?? null )
					&& false === ( $send_event['returned'] ?? null )
					&& $headers_before === $headers_after,
				'wp_clear_auth_cookie fires clear action, zeroes send_auth_cookies fields, and short-circuits headers',
				array(
					'clearEvents'   => $clear_events,
					'sendEvents'    => $send_events,
					'headersBefore' => $headers_before,
					'headersAfter'  => $headers_after,
				)
			);
		} finally {
			\remove_filter( 'send_auth_cookies', array( __CLASS__, 'filter_send_auth_cookies' ), 10 );
			\remove_action( 'clear_auth_cookie', array( __CLASS__, 'action_clear_auth_cookie' ), 10 );
			unset( $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ], $_COOKIE[ LOGGED_IN_COOKIE ] );
		}

		return self::row(
			$ctx,
			'auth-flow.clear-auth-cookie-action-and-send-filter',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_cookie_authentication_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();

		$failures  = array();
		$user_spec = self::user_spec( $ctx, 'cookie-auth' );
		$user      = self::insert_synthetic_user( $user_spec );
		$manager   = \WP_Session_Tokens::get_instance( $user_spec['ID'] );
		$expires   = time() + DAY_IN_SECONDS + $ctx->int( 0, 3600 );
		$token     = $manager->create( $expires );
		$auth_cookie = \wp_generate_auth_cookie( $user_spec['ID'], $expires, 'auth', $token );
		$logged_in_cookie = \wp_generate_auth_cookie( $user_spec['ID'], $expires, 'logged_in', $token );

		\add_filter( 'determine_current_user', 'wp_validate_auth_cookie' );
		\add_filter( 'determine_current_user', 'wp_validate_logged_in_cookie', 20 );

		try {
			$supplied_user = new \WP_User( $user_spec['ID'] );
			$short_user    = \wp_authenticate_cookie( $supplied_user, '', '' );
			$no_cookie     = \wp_authenticate_cookie( null, '', '' );

			$_COOKIE[ AUTH_COOKIE ] = 'broken|cookie|shape|hash';
			$expired_session             = \wp_authenticate_cookie( null, '', '' );
			unset( $_COOKIE[ AUTH_COOKIE ] );

			$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
			unset( $GLOBALS['current_user'] );
			$current_user  = \wp_get_current_user();
			$logged_in     = \is_user_logged_in();
			$session_token = \wp_get_session_token();
			$all_sessions  = \wp_get_all_sessions();

			$_COOKIE[ AUTH_COOKIE ] = $auth_cookie;
			$cookie_user   = \wp_authenticate_cookie( null, '', '' );
			unset( $_COOKIE[ AUTH_COOKIE ] );

			\wp_destroy_current_session();
			$destroyed_valid = \wp_validate_auth_cookie( $logged_in_cookie, 'logged_in' );
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

			self::collect_failure(
				$failures,
				$short_user === $supplied_user
					&& null === $no_cookie
					&& self::is_error_code( $expired_session, 'expired_session' )
					&& $current_user instanceof \WP_User
					&& (int) $current_user->ID === $user_spec['ID']
					&& true === $logged_in
					&& $token === $session_token
					&& count( $all_sessions ) >= 1
					&& $cookie_user instanceof \WP_User
					&& (int) $cookie_user->ID === $user_spec['ID']
					&& false === $destroyed_valid,
				'cookie authentication covers supplied-user, no-cookie, invalid-cookie, current-user, and destroy-current paths',
				array(
					'user'             => self::describe_user( $user ),
					'noCookie'         => self::describe_value( $no_cookie ),
					'expiredSession'   => self::describe_error( $expired_session ),
					'currentUser'      => self::describe_user_result( $current_user ),
					'isLoggedIn'       => $logged_in,
					'sessionTokenSha1' => sha1( $session_token ),
					'allSessions'      => $all_sessions,
					'cookieUser'       => self::describe_user_result( $cookie_user ),
					'destroyedValid'   => $destroyed_valid,
				)
			);
		} finally {
			\remove_filter( 'determine_current_user', 'wp_validate_auth_cookie' );
			\remove_filter( 'determine_current_user', 'wp_validate_logged_in_cookie', 20 );
			unset( $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ LOGGED_IN_COOKIE ] );
		}

		return self::row(
			$ctx,
			'auth-flow.cookie-authentication-current-session',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_auth_cookie_validation_events( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();
		self::clear_events();

		$failures  = array();
		$user_spec = self::user_spec( $ctx, 'events' );
		$user      = self::insert_synthetic_user( $user_spec );
		$manager   = \WP_Session_Tokens::get_instance( $user_spec['ID'] );
		$now       = time();
		$expires   = $now + DAY_IN_SECONDS + $ctx->int( 1, 3600 );
		$token     = $manager->create( $expires );
		$valid     = \wp_generate_auth_cookie( $user_spec['ID'], $expires, 'auth', $token );

		self::install_auth_cookie_event_actions();

		try {
			$valid_result = \wp_validate_auth_cookie( $valid, 'auth' );

			$bad_hash = substr( $valid, 0, -1 ) . ( '0' === substr( $valid, -1 ) ? '1' : '0' );
			$bad_hash_result = \wp_validate_auth_cookie( $bad_hash, 'auth' );

			$missing_user_cookie = preg_replace( '/^[^|]*/', 'missing_' . $user_spec['user_login'], $valid, 1 );
			$bad_user_result    = \wp_validate_auth_cookie( (string) $missing_user_cookie, 'auth' );

			$missing_token_cookie = \wp_generate_auth_cookie( $user_spec['ID'], $expires, 'auth', 'unregistered-' . $token );
			$bad_token_result     = \wp_validate_auth_cookie( $missing_token_cookie, 'auth' );

			$expired_cookie = \wp_generate_auth_cookie( $user_spec['ID'], $now - 10, 'auth', $token );
			$get_expired    = \wp_validate_auth_cookie( $expired_cookie, 'auth' );

			$_SERVER['REQUEST_METHOD'] = 'POST';
			unset( $GLOBALS['login_grace_period'] );
			$post_grace     = \wp_validate_auth_cookie( $expired_cookie, 'auth' );
			$grace_recorded = isset( $GLOBALS['login_grace_period'] ) && 1 === $GLOBALS['login_grace_period'];
			$_SERVER['REQUEST_METHOD'] = 'GET';

			$malformed_result = \wp_validate_auth_cookie( 'one|two|three', 'auth' );

			$_SERVER['HTTPS'] = 'off';
			$_COOKIE[ AUTH_COOKIE ] = $valid;
			$parsed_http            = \wp_parse_auth_cookie( '', '' );
			unset( $_COOKIE[ AUTH_COOKIE ] );

			$_SERVER['HTTPS']            = 'on';
			$secure_cookie               = \wp_generate_auth_cookie( $user_spec['ID'], $expires, 'secure_auth', $token );
			$_COOKIE[ SECURE_AUTH_COOKIE ] = $secure_cookie;
			$parsed_https                = \wp_parse_auth_cookie( '', '' );
			unset( $_COOKIE[ SECURE_AUTH_COOKIE ] );
			$_SERVER['HTTPS'] = 'off';

			self::collect_failure(
				$failures,
				$user_spec['ID'] === $valid_result
					&& false === $bad_hash_result
					&& false === $bad_user_result
					&& false === $bad_token_result
					&& false === $get_expired
					&& $user_spec['ID'] === $post_grace
					&& true === $grace_recorded
					&& false === $malformed_result
					&& is_array( $parsed_http )
					&& 'auth' === $parsed_http['scheme']
					&& is_array( $parsed_https )
					&& 'secure_auth' === $parsed_https['scheme'],
				'auth cookie validation returns safe false values, honors POST grace, and parses default schemes from globals',
				array(
					'user'              => self::describe_user( $user ),
					'validResult'       => $valid_result,
					'badHashResult'     => $bad_hash_result,
					'badUserResult'     => $bad_user_result,
					'badTokenResult'    => $bad_token_result,
					'getExpired'        => $get_expired,
					'postGrace'         => $post_grace,
					'graceRecorded'     => $grace_recorded,
					'malformedResult'   => $malformed_result,
					'parsedHttpScheme'  => is_array( $parsed_http ) ? $parsed_http['scheme'] : $parsed_http,
					'parsedHttpsScheme' => is_array( $parsed_https ) ? $parsed_https['scheme'] : $parsed_https,
				)
			);

			self::collect_failure(
				$failures,
				1 <= count( self::events( 'auth_cookie_valid' ) )
					&& 1 <= count( self::events( 'auth_cookie_bad_hash' ) )
					&& 1 <= count( self::events( 'auth_cookie_bad_username' ) )
					&& 1 <= count( self::events( 'auth_cookie_bad_session_token' ) )
					&& 1 <= count( self::events( 'auth_cookie_expired' ) )
					&& 1 <= count( self::events( 'auth_cookie_malformed' ) ),
				'auth cookie validation emits the expected hook for each failure class',
				array(
					'valid'           => self::events( 'auth_cookie_valid' ),
					'badHash'         => self::events( 'auth_cookie_bad_hash' ),
					'badUsername'     => self::events( 'auth_cookie_bad_username' ),
					'badSessionToken' => self::events( 'auth_cookie_bad_session_token' ),
					'expired'         => self::events( 'auth_cookie_expired' ),
					'malformed'       => self::events( 'auth_cookie_malformed' ),
				)
			);
		} finally {
			self::remove_auth_cookie_event_actions();
			unset( $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ], $GLOBALS['login_grace_period'] );
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['HTTPS']          = 'off';
		}

		return self::row(
			$ctx,
			'auth-flow.auth-cookie-validation-events-and-defaults',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_generated_cookie_scheme_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();
		self::clear_events();

		$failures  = array();
		$user_spec = self::user_spec( $ctx, 'schemes' );
		$user      = self::insert_synthetic_user( $user_spec );
		$manager   = \WP_Session_Tokens::get_instance( $user_spec['ID'] );
		$expires   = time() + DAY_IN_SECONDS + $ctx->int( 1, 7200 );
		$token     = $manager->create( $expires );
		$schemes   = array( 'auth', 'secure_auth', 'logged_in' );
		$cookies   = array();
		$parsed    = array();
		$validated = array();

		\add_filter( 'auth_cookie', array( __CLASS__, 'filter_auth_cookie' ), 10, 5 );

		try {
			foreach ( $schemes as $scheme ) {
				$cookies[ $scheme ]   = \wp_generate_auth_cookie( $user_spec['ID'], $expires, $scheme, $token );
				$parsed[ $scheme ]    = \wp_parse_auth_cookie( $cookies[ $scheme ], $scheme );
				$validated[ $scheme ] = array(
					'matching'    => \wp_validate_auth_cookie( $cookies[ $scheme ], $scheme ),
					'emptyScheme' => \wp_validate_auth_cookie( $cookies[ $scheme ], '' ),
				);

				foreach ( $schemes as $candidate_scheme ) {
					if ( $candidate_scheme === $scheme ) {
						continue;
					}

					$validated[ $scheme ][ $candidate_scheme ] = \wp_validate_auth_cookie( $cookies[ $scheme ], $candidate_scheme );
				}
			}

			$filter_events         = self::events( 'auth_cookie' );
			$parsed_checks         = array();
			$validation_checks     = array();
			$filter_payload_checks = array();
			$hmacs                 = array();

			foreach ( $schemes as $index => $scheme ) {
				$parsed_cookie = $parsed[ $scheme ];
				$event         = $filter_events[ $index ] ?? array();

				$parsed_checks[ $scheme ] = is_array( $parsed_cookie )
					&& $user_spec['user_login'] === $parsed_cookie['username']
					&& (string) $expires === (string) $parsed_cookie['expiration']
					&& $token === $parsed_cookie['token']
					&& $scheme === $parsed_cookie['scheme']
					&& '' !== $parsed_cookie['hmac'];

				if ( is_array( $parsed_cookie ) ) {
					$hmacs[ $scheme ] = $parsed_cookie['hmac'];
				}

				$cross_scheme_results = array();
				foreach ( $schemes as $candidate_scheme ) {
					if ( $candidate_scheme !== $scheme ) {
						$cross_scheme_results[] = false === ( $validated[ $scheme ][ $candidate_scheme ] ?? null );
					}
				}

				$validation_checks[ $scheme ] = $user_spec['ID'] === ( $validated[ $scheme ]['matching'] ?? null )
					&& false === ( $validated[ $scheme ]['emptyScheme'] ?? null )
					&& ! in_array( false, $cross_scheme_results, true );

				$filter_payload_checks[ $scheme ] = isset( $event['cookieSha1'], $event['userId'], $event['expiration'], $event['scheme'], $event['tokenSha1'], $event['parsed'] )
					&& sha1( $cookies[ $scheme ] ) === $event['cookieSha1']
					&& $user_spec['ID'] === $event['userId']
					&& $expires === $event['expiration']
					&& $scheme === $event['scheme']
					&& sha1( $token ) === $event['tokenSha1']
					&& is_array( $event['parsed'] )
					&& $scheme === $event['parsed']['scheme']
					&& $user_spec['user_login'] === $event['parsed']['username']
					&& (string) $expires === (string) $event['parsed']['expiration']
					&& sha1( $token ) === $event['parsed']['tokenSha1'];
			}

			self::collect_failure(
				$failures,
				3 === count( array_unique( $cookies ) )
					&& 3 === count( array_unique( $hmacs ) )
					&& ! in_array( false, $parsed_checks, true )
					&& ! in_array( false, $validation_checks, true ),
				'generated auth cookies are scheme-bound and only validate for their generating scheme',
				array(
					'user'             => self::describe_user( $user ),
					'cookieSha1s'      => array_map( 'sha1', $cookies ),
					'hmacSha1s'        => array_map( 'sha1', $hmacs ),
					'parsedChecks'     => $parsed_checks,
					'validationChecks' => $validation_checks,
					'validated'        => $validated,
					'parsed'           => $parsed,
				)
			);

			self::collect_failure(
				$failures,
				3 === count( $filter_events )
					&& ! in_array( false, $filter_payload_checks, true ),
				'auth_cookie filter receives the generated cookie and matching user, expiration, scheme, and token payload',
				array(
					'filterEvents'  => $filter_events,
					'payloadChecks' => $filter_payload_checks,
				)
			);
		} finally {
			\remove_filter( 'auth_cookie', array( __CLASS__, 'filter_auth_cookie' ), 10 );
		}

		return self::row(
			$ctx,
			'auth-flow.generated-cookie-scheme-boundaries',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_session_token_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();
		self::clear_events();

		$failures                  = array();
		$user_spec                 = self::user_spec( $ctx, 'session' );
		$user                      = self::insert_synthetic_user( $user_spec );
		self::$session_attachment  = array(
			'component_fuzz_seed' => $ctx->seed(),
			'component_fuzz_case' => $ctx->identifier( 4, 12 ),
		);
		$_SERVER['REMOTE_ADDR']     = '203.0.113.' . $ctx->int( 1, 200 );
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/auth-flow ' . $ctx->identifier( 4, 12 );

		\add_filter( 'attach_session_information', array( __CLASS__, 'filter_attach_session_information' ), 10, 2 );

		try {
			$manager        = \WP_Session_Tokens::get_instance( $user_spec['ID'] );
			$now            = time();
			$first_token    = $manager->create( $now + DAY_IN_SECONDS );
			$second_token   = $manager->create( $now + 2 * DAY_IN_SECONDS );
			$expired_token  = $manager->create( $now - 5 );
			$first_session  = $manager->get( $first_token );
			$second_session = $manager->get( $second_token );
			$expired        = $manager->get( $expired_token );
			$all_before     = $manager->get_all();
			$first_initial_valid   = $manager->verify( $first_token );
			$second_initial_valid  = $manager->verify( $second_token );
			$expired_initial_valid = $manager->verify( $expired_token );

			$manager->update(
				$first_token,
				array_merge(
					(array) $first_session,
					array(
						'expiration' => $now + 3 * DAY_IN_SECONDS,
						'updated'    => true,
					)
				)
			);
			$updated_first = $manager->get( $first_token );

			$manager->destroy_others( $first_token );
			$after_destroy_others = $manager->get_all();
			$first_after_others   = $manager->verify( $first_token );
			$second_after_others  = $manager->verify( $second_token );

			\wp_set_current_user( $user_spec['ID'] );
			$cookie = \wp_generate_auth_cookie( $user_spec['ID'], $now + DAY_IN_SECONDS, 'logged_in', $first_token );
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
			\wp_destroy_other_sessions();
			$after_current_destroy_others = $manager->get_all();
			\wp_destroy_all_sessions();
			$after_destroy_all = $manager->get_all();
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

			$checks = array(
				'firstSessionArray'       => is_array( $first_session ),
				'secondSessionArray'      => is_array( $second_session ),
				'expiredSessionFiltered'  => null === $expired,
				'firstVerifiesInitially'  => true === $first_initial_valid,
				'secondVerifiesInitially' => true === $second_initial_valid,
				'expiredDoesNotVerify'    => false === $expired_initial_valid,
				'allBeforeHasLiveTokens'  => count( $all_before ) >= 2,
				'attachmentSeed'          => self::$session_attachment['component_fuzz_seed'] === ( $first_session['component_fuzz_seed'] ?? null ),
				'ipAttached'              => $_SERVER['REMOTE_ADDR'] === ( $first_session['ip'] ?? null ),
				'userAgentAttached'       => $_SERVER['HTTP_USER_AGENT'] === ( $first_session['ua'] ?? null ),
				'updatedFirst'            => true === ( $updated_first['updated'] ?? false ),
				'destroyOthersKeepsOne'   => 1 === count( $after_destroy_others ),
				'firstKept'               => true === $first_after_others,
				'secondDestroyed'         => false === $second_after_others,
				'currentDestroyKeepsOne'  => 1 === count( $after_current_destroy_others ),
				'destroyAllEmpties'       => array() === $after_destroy_all,
			);

			self::collect_failure(
				$failures,
				! in_array( false, $checks, true ),
				'session token manager creates, filters, verifies, updates, destroys others, and destroys all sessions',
				array(
					'checks'                     => $checks,
					'user'                       => self::describe_user( $user ),
					'firstSession'               => $first_session,
					'secondSession'              => $second_session,
					'expiredSession'             => $expired,
					'allBefore'                  => $all_before,
					'updatedFirst'               => $updated_first,
					'afterDestroyOthers'         => $after_destroy_others,
					'firstInitialValid'          => $first_initial_valid,
					'secondInitialValid'         => $second_initial_valid,
					'expiredInitialValid'        => $expired_initial_valid,
					'firstAfterOthers'           => $first_after_others,
					'secondAfterOthers'          => $second_after_others,
					'afterCurrentDestroyOthers'  => $after_current_destroy_others,
					'afterDestroyAll'            => $after_destroy_all,
					'attachSessionInformation'   => self::events( 'attach_session_information' ),
				)
			);
		} finally {
			\remove_filter( 'attach_session_information', array( __CLASS__, 'filter_attach_session_information' ), 10 );
			self::$session_attachment = array();
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		}

		return self::row(
			$ctx,
			'auth-flow.session-token-lifecycle',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_current_user_cookie_restoration( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();

		$failures     = array();
		$local_before = self::snapshot_globals();
		$user_spec    = self::user_spec( $ctx, 'restore' );
		$user         = self::insert_synthetic_user( $user_spec );

		\add_filter( 'determine_current_user', 'wp_validate_auth_cookie' );
		\add_filter( 'determine_current_user', 'wp_validate_logged_in_cookie', 20 );

		try {
			$manager = \WP_Session_Tokens::get_instance( $user_spec['ID'] );
			$expires = time() + DAY_IN_SECONDS;
			$token   = $manager->create( $expires );
			$cookie  = \wp_generate_auth_cookie( $user_spec['ID'], $expires, 'logged_in', $token );

			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
			unset( $GLOBALS['current_user'] );

			$detected = \wp_get_current_user();
			$logged   = \is_user_logged_in();

			self::restore_globals( $local_before );

			$restored_cookie  = $_COOKIE;
			$restored_current = $GLOBALS['current_user'] ?? null;

			self::collect_failure(
				$failures,
				$detected instanceof \WP_User
					&& (int) $detected->ID === $user_spec['ID']
					&& true === $logged
					&& $restored_cookie === $local_before['_COOKIE']
					&& self::same_current_user_snapshot( $restored_current, $local_before['globals']['current_user'] ),
				'current user and auth cookies can be restored after cookie-derived login state',
				array(
					'user'             => self::describe_user( $user ),
					'detected'         => self::describe_user_result( $detected ),
					'logged'           => $logged,
					'restoredCookie'   => $restored_cookie,
					'originalCookie'   => $local_before['_COOKIE'],
					'restoredCurrent'  => self::describe_user_result( $restored_current ),
					'originalCurrent'  => self::describe_value( $local_before['globals']['current_user'] ),
				)
			);
		} finally {
			\remove_filter( 'determine_current_user', 'wp_validate_auth_cookie' );
			\remove_filter( 'determine_current_user', 'wp_validate_logged_in_cookie', 20 );
			self::restore_globals( $local_before );
		}

		return self::row(
			$ctx,
			'auth-flow.current-user-cookie-global-restoration',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_parse_auth_cookie_generated_failures( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_case_state();

		$failures = array();
		$cases    = array(
			'',
			'one',
			'one|two|three',
			'a|b|c|d|e',
			"user\x00|exp|token|hash|extra",
		);

		for ( $i = 0; $i < self::GENERATED_COOKIE_CASES; ++$i ) {
			$parts = array();
			$count = $ctx->choice( array( 0, 1, 2, 3, 5, 6 ) );
			for ( $j = 0; $j < $count; ++$j ) {
				$parts[] = self::cookie_part( $ctx->fork( 'part-' . $i . '-' . $j ) );
			}
			$cases[] = implode( '|', $parts );
		}

		foreach ( $cases as $index => $cookie ) {
			$parsed = \wp_parse_auth_cookie( $cookie, 'auth' );
			self::collect_failure(
				$failures,
				false === $parsed,
				"generated malformed auth cookie {$index} fails closed",
				array(
					'cookie' => self::describe_string( $cookie ),
					'parsed' => $parsed,
				)
			);
		}

		return self::row(
			$ctx,
			'auth-flow.parse-auth-cookie-generated-failures',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function install_default_auth_filters(): void {
		\add_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );
		\add_filter( 'authenticate', 'wp_authenticate_email_password', 20, 3 );
		\add_filter( 'is_email', 'wp_is_unicode_email', 10, 3 );
		\add_filter( 'sanitize_email', 'wp_sanitize_unicode_email', 10, 3 );
		\add_filter( 'random_password', array( __CLASS__, 'filter_random_password' ), 10, 4 );
	}

	private static function install_auth_cookie_event_actions(): void {
		\add_action( 'auth_cookie_malformed', array( __CLASS__, 'action_auth_cookie_malformed' ), 10, 2 );
		\add_action( 'auth_cookie_expired', array( __CLASS__, 'action_auth_cookie_expired' ), 10, 1 );
		\add_action( 'auth_cookie_bad_username', array( __CLASS__, 'action_auth_cookie_bad_username' ), 10, 1 );
		\add_action( 'auth_cookie_bad_hash', array( __CLASS__, 'action_auth_cookie_bad_hash' ), 10, 1 );
		\add_action( 'auth_cookie_bad_session_token', array( __CLASS__, 'action_auth_cookie_bad_session_token' ), 10, 1 );
		\add_action( 'auth_cookie_valid', array( __CLASS__, 'action_auth_cookie_valid' ), 10, 2 );
	}

	private static function remove_auth_cookie_event_actions(): void {
		\remove_action( 'auth_cookie_malformed', array( __CLASS__, 'action_auth_cookie_malformed' ), 10 );
		\remove_action( 'auth_cookie_expired', array( __CLASS__, 'action_auth_cookie_expired' ), 10 );
		\remove_action( 'auth_cookie_bad_username', array( __CLASS__, 'action_auth_cookie_bad_username' ), 10 );
		\remove_action( 'auth_cookie_bad_hash', array( __CLASS__, 'action_auth_cookie_bad_hash' ), 10 );
		\remove_action( 'auth_cookie_bad_session_token', array( __CLASS__, 'action_auth_cookie_bad_session_token' ), 10 );
		\remove_action( 'auth_cookie_valid', array( __CLASS__, 'action_auth_cookie_valid' ), 10 );
	}

	private static function reset_case_state(): void {
		self::reset_runtime();
		self::reset_content();
		self::clear_events();
	}

	private static function reset_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-login.php';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.77';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/auth-flow';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['SERVER_PORT']     = '80';

		$GLOBALS['auth_secure_cookie'] = false;
		$GLOBALS['current_user']       = new \WP_User( 0 );
		unset( $GLOBALS['login_grace_period'] );
	}

	private static function reset_content(): void {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function reset_static_state(): void {
		self::$auth_cookie_lifetime    = 7200;
		self::$events                  = array();
		self::$session_attachment      = array();
		self::$password_seed           = 'auth-flow';
		self::$password_counter        = 0;
		self::$short_circuit_username  = null;
		self::$short_circuit_user      = null;
		self::$authenticate_user_deny  = null;
	}

	private static function user_spec( \ComponentFuzz\FuzzContext $ctx, string $label, string $activation_key = '' ): array {
		$hash  = hash( 'sha256', $ctx->seed() . '|' . $label );
		$id    = 70000 + ( hexdec( substr( $hash, 0, 6 ) ) % 20000 );
		$login = 'cfz_auth_' . substr( $hash, 0, 12 );
		$email = 'cfzauth' . substr( $hash, 0, 12 ) . '@example.com';
		$pass  = 'cfz-pass-' . substr( $hash, 12, 24 );

		return array(
			'ID'                  => $id,
			'user_login'          => $login,
			'user_pass'           => \wp_hash_password( $pass ),
			'plain_password'      => $pass,
			'user_nicename'       => $login,
			'user_email'          => $email,
			'user_url'            => 'https://example.test/users/' . $login,
			'user_registered'     => '2024-01-01 00:00:00',
			'user_activation_key' => $activation_key,
			'user_status'         => 0,
			'display_name'        => 'Auth Flow ' . $id,
		);
	}

	private static function insert_synthetic_user( array $spec ): \WP_User {
		global $wpdb;

		$row = $spec;
		unset( $row['plain_password'] );

		$wpdb->insert( $wpdb->users, $row );
		\update_user_meta( (int) $spec['ID'], 'wp_capabilities', array() );
		\update_user_meta( (int) $spec['ID'], 'wp_user_level', 0 );

		$user = \get_userdata( (int) $spec['ID'] );
		if ( ! $user instanceof \WP_User ) {
			throw new \RuntimeException( 'Synthetic user could not be loaded.' );
		}

		return $user;
	}

	private static function raw_user_row( int $user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE ID = %d LIMIT 1", $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function cookie_part( \ComponentFuzz\FuzzContext $ctx ): string {
		return str_replace( '|', '/', $ctx->text( 0, 24 ) );
	}

	private static function mutate_secret( string $secret ): string {
		if ( '' === $secret ) {
			return 'x';
		}

		$last = substr( $secret, -1 );
		return substr( $secret, 0, -1 ) . ( 'x' === $last ? 'y' : 'x' );
	}

	private static function record_cookie_event( string $event, string $cookie, string $scheme ): void {
		self::record_event(
			$event,
			array(
				'cookie' => self::cookie_summary( $cookie ),
				'scheme' => $scheme,
			)
		);
	}

	private static function record_cookie_elements_event( string $event, array $cookie_elements ): void {
		$data = $cookie_elements;
		if ( isset( $data['token'] ) ) {
			$data['tokenSha1'] = sha1( (string) $data['token'] );
			unset( $data['token'] );
		}
		if ( isset( $data['hmac'] ) ) {
			$data['hmacSha1'] = sha1( (string) $data['hmac'] );
			unset( $data['hmac'] );
		}
		self::record_event( $event, $data );
	}

	private static function record_event( string $event, array $data ): void {
		if ( ! isset( self::$events[ $event ] ) ) {
			self::$events[ $event ] = array();
		}

		self::$events[ $event ][] = $data;
	}

	private static function events( string $event ): array {
		return self::$events[ $event ] ?? array();
	}

	private static function clear_events(): void {
		self::$events = array();
	}

	private static function events_include_code( array $events, string $code ): bool {
		foreach ( $events as $event ) {
			if ( in_array( $code, $event['codes'] ?? array(), true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function cookie_summary( string $cookie ): array {
		$parsed = \wp_parse_auth_cookie( $cookie, '' );

		return array(
			'raw'    => $cookie,
			'sha1'   => sha1( $cookie ),
			'bytes'  => strlen( $cookie ),
			'parsed' => is_array( $parsed )
				? array(
					'username'   => $parsed['username'],
					'expiration' => $parsed['expiration'],
					'tokenSha1'  => sha1( $parsed['token'] ),
					'hmacSha1'   => sha1( $parsed['hmac'] ),
					'scheme'     => $parsed['scheme'],
				)
				: false,
		);
	}

	private static function is_error_code( $value, string $code ): bool {
		return \is_wp_error( $value ) && in_array( $code, $value->get_error_codes(), true );
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

	private static function describe_user_result( $user ) {
		if ( $user instanceof \WP_User ) {
			return array(
				'type'       => 'WP_User',
				'ID'         => (int) $user->ID,
				'userLogin'  => $user->user_login,
				'userEmail'  => $user->user_email,
				'exists'     => $user->exists(),
			);
		}

		return self::describe_value( $user );
	}

	private static function describe_user( \WP_User $user ): array {
		return array(
			'ID'         => (int) $user->ID,
			'userLogin'  => $user->user_login,
			'userEmail'  => $user->user_email,
			'passSha256' => sha1( $user->user_pass ),
		);
	}

	private static function describe_error( $value ) {
		if ( \is_wp_error( $value ) ) {
			return array(
				'type' => 'WP_Error',
				'codes' => $value->get_error_codes(),
			);
		}

		return self::describe_user_result( $value );
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

			if ( $value instanceof \WP_User ) {
				return self::describe_user_result( $value );
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
				'auth_secure_cookie',
				'current_user',
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
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function same_current_user_snapshot( $current_user, array $snapshot ): bool {
		if ( ! $snapshot['exists'] ) {
			return ! array_key_exists( 'current_user', $GLOBALS );
		}

		$expected = $snapshot['value'];
		if ( $expected instanceof \WP_User && $current_user instanceof \WP_User ) {
			return (int) $expected->ID === (int) $current_user->ID;
		}

		return $current_user === $expected;
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
