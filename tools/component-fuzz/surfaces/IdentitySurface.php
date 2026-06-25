<?php
namespace ComponentFuzz\Surfaces;

final class IdentitySurface {
	public const NAME = 'identity';

	private const MAX_STRING_BYTES = 2048;
	private const SAMPLE_BYTES     = 160;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$result = array(
			'schemaVersion' => 1,
			'kind'          => 'component-fuzz-surface-result',
			'surface'       => self::NAME,
			'ok'            => true,
			'seed'          => self::context_seed( $ctx ),
			'cases'         => 0,
			'checks'        => 0,
			'coverage'      => array(),
			'skips'         => array(),
			'failures'      => array(),
			'samples'       => array(),
		);

		$rng = self::make_rng( (string) $result['seed'] );

		$outer_globals = self::snapshot_globals( true );
		try {
			self::install_db_free_short_circuits();

			self::exercise_usernames( $result, $rng );
			self::exercise_email_addresses( $result, $rng );
			self::exercise_identity_filter_contracts( $result, $rng );
			self::exercise_capability_keys( $result, $rng );
			self::exercise_user_contact_methods( $result, $rng );
			self::exercise_urls( $result, $rng );
			self::exercise_avatar_helpers( $result, $rng );
			self::exercise_text_and_comment_helpers( $result, $rng );
			self::exercise_comment_cookies( $result, $rng );
			self::exercise_current_commenter( $result, $rng );
			self::exercise_comment_filtering( $result, $rng );
			self::exercise_options( $result, $rng );
			self::exercise_passwords( $result, $rng );
			self::exercise_parse_helpers( $result, $rng );
		} catch ( \Throwable $e ) {
			self::failure(
				$result,
				'identity.run.no_throw',
				self::NAME,
				'run completed without throwing',
				get_class( $e ) . ': ' . $e->getMessage()
			);
		} finally {
			self::restore_globals( $outer_globals );
		}

		$result['ok'] = array() === $result['failures'];
		sort( $result['coverage'] );
		sort( $result['skips'] );

		return $result;
	}

	private static function exercise_usernames( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'sanitize_user' ), $result, 'usernames' ) ) {
			return;
		}

		$inputs = self::string_corpus( $rng );
		foreach ( self::generated_strings( $rng, 12 ) as $input ) {
			$inputs[] = $input;
		}

		self::run_case(
			$result,
			'usernames',
			static function () use ( &$result, $inputs ): void {
				foreach ( $inputs as $input ) {
					$strict = \sanitize_user( $input, true );
					self::check( $result, 'sanitize_user.strict.string', is_string( $strict ), $input, null, $strict );
					self::check( $result, 'sanitize_user.strict.allowed_chars', 1 === preg_match( '/\A[a-z0-9 _.\-@]*\z/i', $strict ), $input, 'ASCII alnum, space, underscore, dot, dash, @', $strict );
					self::check( $result, 'sanitize_user.strict.idempotent', $strict === \sanitize_user( $strict, true ), $input, $strict, \sanitize_user( $strict, true ) );

					$loose = \sanitize_user( $input, false );
					if ( is_string( $loose ) ) {
						self::check( $result, 'sanitize_user.loose.idempotent_when_stable', $loose === \sanitize_user( $loose, false ), $input, $loose, \sanitize_user( $loose, false ) );
					}
				}
			}
		);

		if ( ! self::have_functions( array( 'validate_username' ), $result, 'validate_username' ) ) {
			return;
		}

		$simple = array(
			'admin',
			'Admin.User-01',
			'first last',
			'user@example.com',
			'',
			' has-edge-space ',
			"line\nbreak",
			'percent%41',
			'name&amp;',
		);

		self::run_case(
			$result,
			'validate_username',
			static function () use ( &$result, $simple ): void {
				foreach ( $simple as $input ) {
					$sanitized = \sanitize_user( $input, true );
					$expected  = ( $sanitized === $input && '' !== $sanitized );
					$actual    = \validate_username( $input );
					self::check( $result, 'validate_username.matches_strict_sanitize_for_simple_inputs', $expected === $actual, $input, $expected, $actual );
				}
			}
		);
	}

	private static function exercise_email_addresses( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'sanitize_email', 'is_email' ), $result, 'email' ) ) {
			return;
		}

		$emails = array(
			'user@example.com',
			'USER+tag@example.co.uk',
			'Display Name <user@example.com>',
			" info @ example . com. \t",
			"info@gr\xC3\xA5.org",
			'user@xn--exmple-cua.com',
			'bad@@example.com',
			'a@b',
			'.start@example.com',
			'name@domain..com',
			"invalid\x80@example.com",
			str_repeat( 'a', 80 ) . '@example.com',
			'user@example.com.',
		);
		foreach ( self::generated_strings( $rng, 8 ) as $value ) {
			$emails[] = $value . '@example.test';
		}

		self::run_case(
			$result,
			'email',
			static function () use ( &$result, $emails ): void {
				foreach ( $emails as $input ) {
					$sanitized = \sanitize_email( $input );
					self::check( $result, 'sanitize_email.string', is_string( $sanitized ), $input, 'string', $sanitized );
					self::check( $result, 'sanitize_email.idempotent', $sanitized === \sanitize_email( $sanitized ), $input, $sanitized, \sanitize_email( $sanitized ) );

					$valid_raw = \is_email( $input );
					if ( false !== $valid_raw ) {
						self::check( $result, 'is_email.raw_implies_sanitize_email_nonempty', '' !== $sanitized, $input, 'non-empty sanitized email', $sanitized );
					}

					if ( '' !== $sanitized ) {
						$valid_sanitized = \is_email( $sanitized );
						self::check( $result, 'sanitize_email.output_is_email', false !== $valid_sanitized, $input, 'valid sanitized email', $valid_sanitized );
						if ( false !== $valid_sanitized ) {
							self::check( $result, 'is_email.sanitized_canonical_stable', $sanitized === \sanitize_email( $valid_sanitized ), $input, $sanitized, \sanitize_email( $valid_sanitized ) );
						}
					}
				}
			}
		);
	}

	private static function exercise_identity_filter_contracts( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'add_filter', 'remove_filter', 'sanitize_user', 'sanitize_email', 'is_email' ), $result, 'identity_filter_contracts' ) ) {
			return;
		}

		$user_input  = ' Filter <b>User</b> ' . substr( self::random_string( $rng, 16 ), 0, 16 ) . ' ';
		$email_input = 'filter-' . self::rand_int( $rng, 100, 999 ) . '@example.test';

		self::run_case(
			$result,
			'identity_filter_contracts',
			static function () use ( &$result, $user_input, $email_input ): void {
				$user_events           = array();
				$sanitize_email_events = array();
				$is_email_events       = array();

				$user_filter = static function ( string $username, string $raw_username, bool $strict ) use ( &$user_events ): string {
					$user_events[] = array(
						'username' => $username,
						'raw'      => $raw_username,
						'strict'   => $strict,
					);

					return $strict ? 'identity_strict_user' : 'identity_loose_user';
				};

				$sanitize_email_filter = static function ( string $sanitized, string $email, ?string $context ) use ( &$sanitize_email_events ): string {
					$sanitize_email_events[] = array(
						'sanitized' => $sanitized,
						'email'     => $email,
						'context'   => $context,
					);

					return 'identity-sanitized@example.test';
				};

				$is_email_filter = static function ( $is_email, string $email, ?string $context ) use ( &$is_email_events ): string {
					$is_email_events[] = array(
						'isEmail' => $is_email,
						'email'   => $email,
						'context' => $context,
					);

					return 'identity-valid@example.test';
				};

				\add_filter( 'sanitize_user', $user_filter, 999, 3 );
				\add_filter( 'sanitize_email', $sanitize_email_filter, 999, 3 );
				\add_filter( 'is_email', $is_email_filter, 999, 3 );

				try {
					$strict_user = \sanitize_user( $user_input, true );
					$loose_user  = \sanitize_user( $user_input, false );
					$email       = \sanitize_email( $email_input );
					$valid_email = \is_email( $email_input );

					self::check( $result, 'sanitize_user.filter_overrides_strict_and_loose', 'identity_strict_user' === $strict_user && 'identity_loose_user' === $loose_user, $user_input, array( 'identity_strict_user', 'identity_loose_user' ), array( $strict_user, $loose_user ) );
					self::check( $result, 'sanitize_user.filter_receives_raw_and_strict_flag', 2 === count( $user_events ) && $user_input === $user_events[0]['raw'] && true === $user_events[0]['strict'] && $user_input === $user_events[1]['raw'] && false === $user_events[1]['strict'], $user_input, 'two events with raw username and strict flag', $user_events );
					self::check( $result, 'sanitize_email.filter_can_override_canonical_result', 'identity-sanitized@example.test' === $email, $email_input, 'identity-sanitized@example.test', $email );
					self::check( $result, 'sanitize_email.filter_receives_normalized_email_and_null_context', 1 === count( $sanitize_email_events ) && $email_input === $sanitize_email_events[0]['email'] && null === $sanitize_email_events[0]['context'], $email_input, 'email and null context', $sanitize_email_events );
					self::check( $result, 'is_email.filter_can_override_validation_result', 'identity-valid@example.test' === $valid_email, $email_input, 'identity-valid@example.test', $valid_email );
					self::check( $result, 'is_email.filter_receives_original_email_and_null_context', 1 === count( $is_email_events ) && $email_input === $is_email_events[0]['email'] && null === $is_email_events[0]['context'], $email_input, 'email and null context', $is_email_events );
				} finally {
					\remove_filter( 'sanitize_user', $user_filter, 999 );
					\remove_filter( 'sanitize_email', $sanitize_email_filter, 999 );
					\remove_filter( 'is_email', $is_email_filter, 999 );
				}
			}
		);
	}

	private static function exercise_user_contact_methods( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'add_filter', 'has_filter', 'remove_filter', 'wp_get_user_contact_methods', '_wp_get_user_contactmethods', '_get_additional_user_keys' ), $result, 'user_contact_methods' ) ) {
			return;
		}

		$token   = substr( hash( 'sha256', self::rand_bytes( $rng, 12 ) ), 0, 10 );
		$user    = (object) array(
			'ID'         => self::rand_int( $rng, 10, 999 ),
			'user_login' => 'identity_contact_' . $token,
		);
		$methods = array(
			'identity_profile_' . $token => 'Identity profile ' . $token,
			'identity_chat_' . $token    => 'Identity chat ' . $token,
		);

		self::run_case(
			$result,
			'user_contact_methods',
			static function () use ( &$result, $user, $methods ): void {
				$default_public = \wp_get_user_contact_methods( $user );
				$default_alias  = \_wp_get_user_contactmethods( $user );

				self::check( $result, 'wp_get_user_contactmethods.alias_matches_public_default', $default_public === $default_alias, $user, $default_public, $default_alias );

				$events = array();
				$filter = static function ( array $current_methods, $current_user ) use ( &$events, $methods ): array {
					$events[] = array(
						'methods' => $current_methods,
						'user'    => $current_user,
					);

					return array_merge( $current_methods, $methods );
				};
				$before_filter = \has_filter( 'user_contactmethods', $filter );

				\add_filter( 'user_contactmethods', $filter, 999, 2 );
				try {
					$filtered_null = \wp_get_user_contact_methods();
					$filtered_user = \wp_get_user_contact_methods( $user );
					$filtered_alias = \_wp_get_user_contactmethods( $user );
					$additional_keys = \_get_additional_user_keys( $user );
				} finally {
					\remove_filter( 'user_contactmethods', $filter, 999 );
				}

				self::check( $result, 'wp_get_user_contact_methods.filter_adds_generated_methods_for_null_user', $methods === array_intersect_assoc( $methods, $filtered_null ), $methods, $methods, $filtered_null );
				self::check( $result, 'wp_get_user_contact_methods.filter_adds_generated_methods_for_user', $methods === array_intersect_assoc( $methods, $filtered_user ), $methods, $methods, $filtered_user );
				self::check( $result, 'wp_get_user_contactmethods.alias_matches_filtered_public', $filtered_user === $filtered_alias, $methods, $filtered_user, $filtered_alias );
				self::check( $result, 'wp_get_user_contact_methods.filter_receives_null_and_user_payloads', 4 === count( $events ) && null === $events[0]['user'] && $user === $events[1]['user'] && $user === $events[2]['user'] && $user === $events[3]['user'], $user, 'null payload then user payloads', $events );
				self::check( $result, '_get_additional_user_keys.includes_filtered_contact_methods', array() === array_diff( array_keys( $methods ), $additional_keys ) && in_array( 'first_name', $additional_keys, true ) && in_array( 'locale', $additional_keys, true ), $methods, 'base keys plus generated contact keys', $additional_keys );
				self::check( $result, 'wp_get_user_contact_methods.filter_restored', $before_filter === \has_filter( 'user_contactmethods', $filter ), $methods, $before_filter, \has_filter( 'user_contactmethods', $filter ) );
			}
		);
	}

	private static function exercise_capability_keys( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'sanitize_key' ), $result, 'capability_keys' ) ) {
			return;
		}

		$keys = array(
			'edit_posts',
			'Edit_Posts',
			'delete-users',
			'manage_options',
			'promote Users',
			'<script>edit</script>',
			"cap\x00name",
			"cap\x80name",
		);
		foreach ( self::generated_strings( $rng, 8 ) as $value ) {
			$keys[] = $value;
		}

		self::run_case(
			$result,
			'capability_keys',
			static function () use ( &$result, $keys ): void {
				foreach ( $keys as $input ) {
					$key = \sanitize_key( $input );
					self::check( $result, 'sanitize_key.string', is_string( $key ), $input, 'string', $key );
					self::check( $result, 'sanitize_key.allowed_chars', 1 === preg_match( '/\A[a-z0-9_-]*\z/', $key ), $input, 'lowercase alnum, underscore, dash', $key );
					self::check( $result, 'sanitize_key.idempotent', $key === \sanitize_key( $key ), $input, $key, \sanitize_key( $key ) );
				}
			}
		);
	}

	private static function exercise_urls( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'sanitize_url' ), $result, 'urls' ) ) {
			return;
		}

		$urls = array(
			'https://example.com/path?x=1&y=2',
			'http://user:pass@example.com:8080/a[b]',
			'example.com/path with spaces',
			'/relative/path?x=1',
			'#fragment',
			'?query=1',
			'mailto:user@example.com',
			'ftp://example.com/file.txt',
			'javascript:alert(1)',
			'data:text/html,<b>x</b>',
			"https://example.com/\x80bad",
			str_repeat( 'a', 120 ) . '.example/path',
		);
		foreach ( self::generated_strings( $rng, 6 ) as $value ) {
			$urls[] = 'https://example.test/' . rawurlencode( substr( $value, 0, 48 ) );
		}

		self::run_case(
			$result,
			'urls',
			static function () use ( &$result, $urls ): void {
				foreach ( $urls as $input ) {
					$sanitized = \sanitize_url( $input );
					self::check( $result, 'sanitize_url.string', is_string( $sanitized ), $input, 'string', $sanitized );
					self::check( $result, 'sanitize_url.idempotent', $sanitized === \sanitize_url( $sanitized ), $input, $sanitized, \sanitize_url( $sanitized ) );

					if ( function_exists( 'esc_url_raw' ) ) {
						self::check( $result, 'esc_url_raw.aliases_sanitize_url', \esc_url_raw( $input ) === $sanitized, $input, $sanitized, \esc_url_raw( $input ) );
					}

					if ( function_exists( 'wp_parse_url' ) && '' !== $sanitized ) {
						$parsed = \wp_parse_url( $sanitized );
						self::check( $result, 'wp_parse_url.accepts_sanitize_url_output', false !== $parsed, $input, 'parseable sanitized URL', $parsed );
					}
				}
			}
		);
	}

	private static function exercise_avatar_helpers( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'add_filter', 'remove_filter', 'has_filter', 'get_avatar_data', 'get_avatar_url', 'get_avatar', 'wp_parse_str', 'wp_parse_url' ), $result, 'avatar_helpers' ) ) {
			return;
		}

		$cases = self::avatar_cases( $rng );

		self::run_case(
			$result,
			'avatar_helpers',
			static function () use ( &$result, $cases ): void {
				$option_default = 'retro';
				$option_rating  = 'PG';
				$option_filters = array(
					'pre_option_avatar_default' => static function () use ( $option_default ): string {
						return $option_default;
					},
					'pre_option_avatar_rating'  => static function () use ( $option_rating ): string {
						return $option_rating;
					},
					'pre_option_show_avatars'   => static function (): int {
						return 1;
					},
				);

				$before_filters = array();
				foreach ( $option_filters as $hook => $filter ) {
					$before_filters[ $hook ] = \has_filter( $hook, $filter );
					\add_filter( $hook, $filter, 999, 3 );
				}

				try {
					foreach ( $cases as $case ) {
						$expected = self::expected_avatar_data( $case, $option_default, $option_rating );
						$actual   = \get_avatar_data( $case['id'], $case['args'] );
						$url      = \get_avatar_url( $case['id'], $case['args'] );
						$observed = self::avatar_url_observation( $actual['url'] ?? false );
						$input    = self::describe_avatar_case( $case );

						self::check( $result, 'get_avatar_data.generated_dimensions_are_normalized', self::avatar_dimensions_match( $actual, $expected ), $input, self::avatar_dimension_expectation( $expected ), self::avatar_dimension_observation( $actual ) );
						self::check( $result, 'get_avatar_data.generated_flags_are_canonical', ( $actual['default'] ?? null ) === $expected['default'] && ( $actual['force_default'] ?? null ) === $expected['forceDefault'] && ( $actual['rating'] ?? null ) === $expected['rating'] && true === ( $actual['found_avatar'] ?? null ), $input, self::avatar_flag_expectation( $expected ), self::avatar_flag_observation( $actual ) );
						self::check( $result, 'get_avatar_data.generated_hash_and_query_contract', self::avatar_url_matches_expected( $observed, $expected ), $input, self::avatar_url_expectation( $expected ), $observed );
						self::check( $result, 'get_avatar_url.aliases_get_avatar_data_url', ( $actual['url'] ?? null ) === $url, $input, $actual['url'] ?? null, $url );
					}

					self::exercise_avatar_filter_pipeline( $result, $cases[0] );
					self::exercise_avatar_html_short_circuit( $result, $cases[1] );
				} finally {
					foreach ( $option_filters as $hook => $filter ) {
						\remove_filter( $hook, $filter, 999 );
					}
				}

				$after_filters = array();
				foreach ( $option_filters as $hook => $filter ) {
					$after_filters[ $hook ] = \has_filter( $hook, $filter );
				}

				self::check( $result, 'avatar_helpers.option_filters_restored', $before_filters === $after_filters, array_keys( $option_filters ), $before_filters, $after_filters );
			}
		);
	}

	private static function exercise_avatar_filter_pipeline( array &$result, array $case ): void {
		$token     = substr( hash( 'sha256', $case['label'] . '|filter' ), 0, 10 );
		$short_url = 'https://avatars.example.test/' . $token . '.png?source=pre';
		$events    = array(
			'pre'  => array(),
			'url'  => array(),
			'data' => array(),
		);

		$pre_filter = static function ( array $args, $id_or_email ) use ( &$events, $short_url ): array {
			$events['pre'][] = array(
				'args' => $args,
				'id'   => $id_or_email,
			);

			$args['url']          = $short_url;
			$args['found_avatar'] = true;
			return $args;
		};
		$url_filter = static function ( string $url, $id_or_email, array $args ) use ( &$events ): string {
			$events['url'][] = array(
				'url'  => $url,
				'id'   => $id_or_email,
				'args' => $args,
			);

			return $url . '&unexpected=1';
		};
		$data_filter = static function ( array $args, $id_or_email ) use ( &$events, $token ): array {
			$events['data'][] = array(
				'args' => $args,
				'id'   => $id_or_email,
			);

			$args['url'] .= '&filtered=' . $token;
			return $args;
		};

		$before_filters = array(
			'pre_get_avatar_data' => \has_filter( 'pre_get_avatar_data', $pre_filter ),
			'get_avatar_url'      => \has_filter( 'get_avatar_url', $url_filter ),
			'get_avatar_data'     => \has_filter( 'get_avatar_data', $data_filter ),
		);

		\add_filter( 'pre_get_avatar_data', $pre_filter, 999, 2 );
		\add_filter( 'get_avatar_url', $url_filter, 999, 3 );
		\add_filter( 'get_avatar_data', $data_filter, 999, 2 );

		$args = array(
			'size'          => '44',
			'height'        => '0',
			'width'         => '12',
			'default'       => 'mysteryman',
			'force_default' => true,
			'rating'        => 'PG',
		);

		try {
			$data = \get_avatar_data( $case['id'], $args );
			$url  = \get_avatar_url( $case['id'], $args );
		} finally {
			\remove_filter( 'pre_get_avatar_data', $pre_filter, 999 );
			\remove_filter( 'get_avatar_url', $url_filter, 999 );
			\remove_filter( 'get_avatar_data', $data_filter, 999 );
		}

		$after_filters = array(
			'pre_get_avatar_data' => \has_filter( 'pre_get_avatar_data', $pre_filter ),
			'get_avatar_url'      => \has_filter( 'get_avatar_url', $url_filter ),
			'get_avatar_data'     => \has_filter( 'get_avatar_data', $data_filter ),
		);
		$expected_url  = $short_url . '&filtered=' . $token;
		$first_args    = $events['pre'][0]['args'] ?? array();
		$input         = self::describe_avatar_case( $case );

		self::check( $result, 'get_avatar_data.pre_filter_short_circuits_url_generation', $expected_url === ( $data['url'] ?? null ) && $expected_url === $url && array() === $events['url'], $input, array( 'url' => $expected_url, 'urlFilterCalls' => 0 ), array( 'dataUrl' => $data['url'] ?? null, 'getAvatarUrl' => $url, 'urlEvents' => $events['url'] ) );
		self::check( $result, 'get_avatar_data.filters_receive_normalized_args_and_identity', 2 === count( $events['pre'] ) && 2 === count( $events['data'] ) && self::same_avatar_id( $case['id'], $events['pre'][0]['id'] ?? null ) && 44 === ( $first_args['size'] ?? null ) && 44 === ( $first_args['height'] ?? null ) && 12 === ( $first_args['width'] ?? null ) && 'mm' === ( $first_args['default'] ?? null ) && true === ( $first_args['force_default'] ?? null ) && 'pg' === ( $first_args['rating'] ?? null ) && false === ( $first_args['found_avatar'] ?? null ), $input, 'normalized pre/data filter events', $events );
		self::check( $result, 'get_avatar_data.filter_pipeline_restored', $before_filters === $after_filters, array_keys( $before_filters ), $before_filters, $after_filters );
	}

	private static function exercise_avatar_html_short_circuit( array &$result, array $case ): void {
		$token      = substr( hash( 'sha256', $case['label'] . '|html' ), 0, 10 );
		$pre_html   = '<img alt="short" data-token="' . $token . '" />';
		$final_html = $pre_html . '<!--avatar-filtered-' . $token . '-->';
		$events     = array(
			'pre'   => array(),
			'final' => array(),
		);

		$pre_filter = static function ( $avatar, $id_or_email, array $args ) use ( &$events, $pre_html ): string {
			$events['pre'][] = array(
				'avatar' => $avatar,
				'id'     => $id_or_email,
				'args'   => $args,
			);

			return $pre_html;
		};
		$final_filter = static function ( string $avatar, $id_or_email, int $size, string $default_value, string $alt, array $args ) use ( &$events, $token ): string {
			$events['final'][] = array(
				'avatar'  => $avatar,
				'id'      => $id_or_email,
				'size'    => $size,
				'default' => $default_value,
				'alt'     => $alt,
				'args'    => $args,
			);

			return $avatar . '<!--avatar-filtered-' . $token . '-->';
		};

		$before_filters = array(
			'pre_get_avatar' => \has_filter( 'pre_get_avatar', $pre_filter ),
			'get_avatar'     => \has_filter( 'get_avatar', $final_filter ),
		);

		\add_filter( 'pre_get_avatar', $pre_filter, 999, 3 );
		\add_filter( 'get_avatar', $final_filter, 999, 6 );

		$wp_query_exists = array_key_exists( 'wp_query', $GLOBALS );
		$wp_query_before = $GLOBALS['wp_query'] ?? null;
		$GLOBALS['wp_query'] = new class() {
			public bool $before_loop = false;
			public bool $in_the_loop = false;

			public function is_main_query(): bool {
				return false;
			}
		};

		try {
			$avatar = \get_avatar(
				$case['id'],
				'52',
				'mystery',
				'Alt ' . $token,
				array(
					'force_display' => true,
					'height'        => 0,
					'width'         => '31',
					'class'         => array( 'identity-avatar-' . $token ),
				)
			);
		} finally {
			\remove_filter( 'pre_get_avatar', $pre_filter, 999 );
			\remove_filter( 'get_avatar', $final_filter, 999 );
			if ( $wp_query_exists ) {
				$GLOBALS['wp_query'] = $wp_query_before;
			} else {
				unset( $GLOBALS['wp_query'] );
			}
		}

		$after_filters = array(
			'pre_get_avatar' => \has_filter( 'pre_get_avatar', $pre_filter ),
			'get_avatar'     => \has_filter( 'get_avatar', $final_filter ),
		);
		$pre_args      = $events['pre'][0]['args'] ?? array();
		$final_event   = $events['final'][0] ?? array();
		$input         = self::describe_avatar_case( $case );

		self::check( $result, 'get_avatar.pre_filter_short_circuits_html_then_final_filter_runs', $final_html === $avatar && 1 === count( $events['pre'] ) && 1 === count( $events['final'] ), $input, $final_html, array( 'avatar' => $avatar, 'events' => $events ) );
		self::check( $result, 'get_avatar.filters_receive_normalized_identity_args', self::same_avatar_id( $case['id'], $events['pre'][0]['id'] ?? null ) && null === ( $events['pre'][0]['avatar'] ?? null ) && 52 === ( $pre_args['size'] ?? null ) && 52 === ( $pre_args['height'] ?? null ) && '31' === ( $pre_args['width'] ?? null ) && 'mystery' === ( $pre_args['default'] ?? null ) && 'Alt ' . $token === ( $pre_args['alt'] ?? null ) && $pre_html === ( $final_event['avatar'] ?? null ) && 52 === ( $final_event['size'] ?? null ) && 'mystery' === ( $final_event['default'] ?? null ) && 'Alt ' . $token === ( $final_event['alt'] ?? null ), $input, 'normalized pre/final avatar filter args', $events );
		self::check( $result, 'get_avatar.filter_pipeline_restored', $before_filters === $after_filters, array_keys( $before_filters ), $before_filters, $after_filters );
		self::check( $result, 'get_avatar.wp_query_global_restored', $wp_query_exists === array_key_exists( 'wp_query', $GLOBALS ) && ( ! $wp_query_exists || $wp_query_before === $GLOBALS['wp_query'] ), $input, self::summarize( $wp_query_before ), self::summarize( $GLOBALS['wp_query'] ?? null ) );
	}

	private static function exercise_text_and_comment_helpers( array &$result, array &$rng ): void {
		$inputs = self::string_corpus( $rng );
		foreach ( self::generated_strings( $rng, 8 ) as $input ) {
			$inputs[] = $input;
		}

		if ( self::have_functions( array( 'sanitize_text_field' ), $result, 'sanitize_text_field' ) ) {
			self::run_case(
				$result,
				'sanitize_text_field',
				static function () use ( &$result, $inputs ): void {
					foreach ( $inputs as $input ) {
						$out = \sanitize_text_field( $input );
						self::check( $result, 'sanitize_text_field.string', is_string( $out ), $input, 'string', $out );
						self::check( $result, 'sanitize_text_field.idempotent', $out === \sanitize_text_field( $out ), $input, $out, \sanitize_text_field( $out ) );
					}
				}
			);
		}

		if ( self::have_functions( array( 'sanitize_textarea_field' ), $result, 'sanitize_textarea_field' ) ) {
			self::run_case(
				$result,
				'sanitize_textarea_field',
				static function () use ( &$result, $inputs ): void {
					foreach ( $inputs as $input ) {
						$out = \sanitize_textarea_field( $input );
						self::check( $result, 'sanitize_textarea_field.string', is_string( $out ), $input, 'string', $out );
						self::check( $result, 'sanitize_textarea_field.idempotent', $out === \sanitize_textarea_field( $out ), $input, $out, \sanitize_textarea_field( $out ) );
					}
				}
			);
		}

		if ( self::have_functions( array( 'wp_strip_all_tags' ), $result, 'wp_strip_all_tags' ) ) {
			self::run_case(
				$result,
				'wp_strip_all_tags',
				static function () use ( &$result, $inputs ): void {
					foreach ( $inputs as $input ) {
						$out = \wp_strip_all_tags( $input, true );
						self::check( $result, 'wp_strip_all_tags.string', is_string( $out ), $input, 'string', $out );
						self::check( $result, 'wp_strip_all_tags.idempotent', $out === \wp_strip_all_tags( $out, true ), $input, $out, \wp_strip_all_tags( $out, true ) );
					}
				}
			);
		}

		if ( self::have_functions( array( 'wp_filter_nohtml_kses' ), $result, 'wp_filter_nohtml_kses' ) ) {
			self::run_case(
				$result,
				'wp_filter_nohtml_kses',
				static function () use ( &$result, $inputs ): void {
					foreach ( $inputs as $input ) {
						$slashed = addslashes( $input );
						$out     = \wp_filter_nohtml_kses( $slashed );
						self::check( $result, 'wp_filter_nohtml_kses.string', is_string( $out ), $input, 'string', $out );
						self::check( $result, 'wp_filter_nohtml_kses.idempotent', $out === \wp_filter_nohtml_kses( $out ), $input, $out, \wp_filter_nohtml_kses( $out ) );
					}
				}
			);
		}
	}

	private static function exercise_comment_cookies( array &$result, array &$rng ): void {
		unset( $rng );

		if ( ! defined( 'COOKIEHASH' ) ) {
			$result['skips'][] = 'comment_cookies:missing_COOKIEHASH';
			return;
		}
		if ( ! self::have_functions( array( 'sanitize_comment_cookies', 'wp_unslash', 'esc_attr' ), $result, 'comment_cookies' ) ) {
			return;
		}

		$hash  = (string) constant( 'COOKIEHASH' );
		$cases = array(
			array(
				'comment_author_' . $hash       => "<b>Alice</b> O\\'Reilly",
				'comment_author_email_' . $hash => ' Display Name <user@example.com> ',
				'comment_author_url_' . $hash   => 'example.com/path with spaces',
			),
			array(
				'comment_author_' . $hash       => "bad\x80bytes",
				'comment_author_email_' . $hash => "bad@@example.com",
				'comment_author_url_' . $hash   => 'javascript:alert(1)',
			),
		);

		self::run_case(
			$result,
			'comment_cookies',
			static function () use ( &$result, $cases ): void {
				foreach ( $cases as $cookies ) {
					foreach ( $cookies as $name => $value ) {
						$_COOKIE[ $name ] = $value;
					}

					\sanitize_comment_cookies();
					$once = array();
					foreach ( array_keys( $cookies ) as $name ) {
						$once[ $name ] = $_COOKIE[ $name ] ?? null;
						self::check( $result, 'sanitize_comment_cookies.keeps_cookie_string', is_string( $once[ $name ] ), $cookies, 'string cookie value', $once[ $name ] );
					}

					\sanitize_comment_cookies();
					foreach ( array_keys( $cookies ) as $name ) {
						self::check( $result, 'sanitize_comment_cookies.idempotent', $once[ $name ] === ( $_COOKIE[ $name ] ?? null ), $cookies, $once[ $name ], $_COOKIE[ $name ] ?? null );
					}
				}
			}
		);
	}

	private static function exercise_current_commenter( array &$result, array &$rng ): void {
		if ( ! defined( 'COOKIEHASH' ) ) {
			$result['skips'][] = 'current_commenter:missing_COOKIEHASH';
			return;
		}
		if ( ! self::have_functions( array( 'add_filter', 'has_filter', 'remove_filter', 'sanitize_comment_cookies', 'wp_get_current_commenter' ), $result, 'current_commenter' ) ) {
			return;
		}

		$hash  = (string) constant( 'COOKIEHASH' );
		$cases = array(
			array(
				'comment_author_' . $hash       => "Alice <b>Identity</b> O\\'Reilly",
				'comment_author_email_' . $hash => ' alice.identity@example.test ',
				'comment_author_url_' . $hash   => 'https://example.test/commenter?x=1&y=2',
			),
			array(
				'comment_author_' . $hash       => self::random_string( $rng, 48 ),
				'comment_author_email_' . $hash => 'generated-' . self::rand_int( $rng, 100, 999 ) . '@example.test',
			),
			array(),
		);

		self::run_case(
			$result,
			'current_commenter',
			static function () use ( &$result, $cases, $hash ): void {
				foreach ( $cases as $cookies ) {
					unset(
						$_COOKIE[ 'comment_author_' . $hash ],
						$_COOKIE[ 'comment_author_email_' . $hash ],
						$_COOKIE[ 'comment_author_url_' . $hash ]
					);

					foreach ( $cookies as $name => $value ) {
						$_COOKIE[ $name ] = $value;
					}

					\sanitize_comment_cookies();
					$expected = array(
						'comment_author'       => $_COOKIE[ 'comment_author_' . $hash ] ?? '',
						'comment_author_email' => $_COOKIE[ 'comment_author_email_' . $hash ] ?? '',
						'comment_author_url'   => $_COOKIE[ 'comment_author_url_' . $hash ] ?? '',
					);
					$plain    = \wp_get_current_commenter();

					self::check( $result, 'wp_get_current_commenter.reads_sanitized_cookie_triplet', $expected === $plain, $cookies, $expected, $plain );

					$filter_events = array();
					$filter        = static function ( array $commenter ) use ( &$filter_events ): array {
						$filter_events[] = $commenter;

						$commenter['comment_author'] .= '|filtered';
						return $commenter;
					};
					$before_filter = \has_filter( 'wp_get_current_commenter', $filter );

					\add_filter( 'wp_get_current_commenter', $filter, 999, 1 );
					try {
						$filtered = \wp_get_current_commenter();
					} finally {
						\remove_filter( 'wp_get_current_commenter', $filter, 999 );
					}

					self::check( $result, 'wp_get_current_commenter.filter_receives_sanitized_payload', 1 === count( $filter_events ) && $expected === $filter_events[0], $cookies, $expected, $filter_events );
					self::check( $result, 'wp_get_current_commenter.filter_can_override_payload', is_array( $filtered ) && ( $expected['comment_author'] . '|filtered' ) === ( $filtered['comment_author'] ?? null ) && $expected['comment_author_email'] === ( $filtered['comment_author_email'] ?? null ) && $expected['comment_author_url'] === ( $filtered['comment_author_url'] ?? null ), $cookies, 'filtered author with preserved email/url', $filtered );
					self::check( $result, 'wp_get_current_commenter.filter_restored', $before_filter === \has_filter( 'wp_get_current_commenter', $filter ), $cookies, $before_filter, \has_filter( 'wp_get_current_commenter', $filter ) );
				}
			}
		);
	}

	private static function exercise_comment_filtering( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'wp_filter_comment' ), $result, 'wp_filter_comment' ) ) {
			return;
		}

		$comments = array(
			self::comment_like_array( 'Alice <b>A</b>', 'alice@example.com', 'example.com/a b', "Hello <script>x</script>\nWorld", '127.0.0.1', 'Agent/1.0' ),
			self::comment_like_array( "Bad\x80Name", 'bad@@example.com', 'javascript:alert(1)', "Line\r\n<em>content</em>", '::1', "Agent\x00Two" ),
			self::comment_like_array( self::random_string( $rng, 48 ), 'user@example.test', 'https://example.test/' . self::rand_int( $rng, 1, 999 ), self::random_string( $rng, 160 ), '192.0.2.1', 'Fuzzer/1.0' ),
		);

		self::run_case(
			$result,
			'wp_filter_comment',
			static function () use ( &$result, $comments ): void {
				foreach ( $comments as $comment ) {
					$out = \wp_filter_comment( $comment );
					self::check( $result, 'wp_filter_comment.array', is_array( $out ), $comment, 'array', $out );
					self::check( $result, 'wp_filter_comment.filtered_flag', true === ( $out['filtered'] ?? null ), $comment, true, $out['filtered'] ?? null );
					foreach ( array( 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content', 'comment_author_IP', 'comment_agent' ) as $key ) {
						self::check( $result, 'wp_filter_comment.preserves_required_string_fields', array_key_exists( $key, $out ) && is_string( $out[ $key ] ), $comment, $key . ' string', $out[ $key ] ?? null );
					}
				}
			}
		);
	}

	private static function exercise_options( array &$result, array &$rng ): void {
		if ( ! self::have_functions( array( 'sanitize_option' ), $result, 'sanitize_option' ) ) {
			return;
		}

		$cases = self::option_cases( $rng );

		self::run_case(
			$result,
			'sanitize_option',
			static function () use ( &$result, $cases ): void {
				foreach ( $cases as $case ) {
					$option = $case[0];
					$value  = $case[1];
					$family = $case[2];

					$sanitized = \sanitize_option( $option, $value );
					self::check( $result, 'sanitize_option.idempotent.' . $family, $sanitized === \sanitize_option( $option, $sanitized ), array( $option, $value ), $sanitized, \sanitize_option( $option, $sanitized ) );

					if ( 'absint' === $family ) {
						self::check( $result, 'sanitize_option.absint_type', is_int( $sanitized ) && $sanitized >= 0, array( $option, $value ), 'non-negative int', $sanitized );
					} elseif ( 'positive_or_minus_one_int' === $family ) {
						self::check( $result, 'sanitize_option.posts_count_type', is_int( $sanitized ) && 0 !== $sanitized && $sanitized >= -1, array( $option, $value ), 'int >= -1 except 0', $sanitized );
					} elseif ( 'charset' === $family ) {
						self::check( $result, 'sanitize_option.blog_charset_type', is_string( $sanitized ) && 1 === preg_match( '/\A[a-zA-Z0-9_-]*\z/', $sanitized ), array( $option, $value ), 'charset token string', $sanitized );
					} elseif ( 'blog_public' === $family ) {
						self::check( $result, 'sanitize_option.blog_public_type', is_int( $sanitized ), array( $option, $value ), 'int', $sanitized );
					} elseif ( 'gmt_offset' === $family ) {
						self::check( $result, 'sanitize_option.gmt_offset_type', is_string( $sanitized ) && 1 === preg_match( '/\A[0-9:.\-]*\z/', $sanitized ), array( $option, $value ), 'numeric offset string', $sanitized );
					} elseif ( 'status_string' === $family ) {
						self::check( $result, 'sanitize_option.status_string_type', is_string( $sanitized ), array( $option, $value ), 'string', $sanitized );
					}
				}
			}
		);
	}

	private static function exercise_passwords( array &$result, array &$rng ): void {
		if ( self::have_functions( array( 'wp_generate_password' ), $result, 'wp_generate_password' ) ) {
			$params = array(
				array( 0, false, false ),
				array( 1, false, false ),
				array( self::rand_int( $rng, 2, 16 ), true, false ),
				array( self::rand_int( $rng, 8, 32 ), true, true ),
			);

			self::run_case(
				$result,
				'wp_generate_password',
				static function () use ( &$result, $params ): void {
					foreach ( $params as $param ) {
						$length = $param[0];
						$special = $param[1];
						$extra = $param[2];
						$password = \wp_generate_password( $length, $special, $extra );
						$chars = self::password_chars( $special, $extra );

						self::check( $result, 'wp_generate_password.string', is_string( $password ), $param, 'string', $password );
						self::check( $result, 'wp_generate_password.length', strlen( $password ) === $length, $param, $length, strlen( $password ) );
						self::check( $result, 'wp_generate_password.allowed_chars', '' === $password || 1 === preg_match( '/\A[' . preg_quote( $chars, '/' ) . ']*\z/', $password ), $param, 'generated character class', $password );
					}
				}
			);
		}

		if ( ! self::have_functions( array( 'wp_hash_password', 'wp_check_password' ), $result, 'password_hashing' ) ) {
			return;
		}

		$passwords = array(
			'password',
			"inner\x00nul",
			"unicod\xC3\xA9-pass",
			substr( self::random_string( $rng, 32 ), 0, 32 ),
		);

		self::run_case(
			$result,
			'password_hashing',
			static function () use ( &$result, $passwords ): void {
				foreach ( $passwords as $password ) {
					$password = trim( substr( $password, 0, 64 ) );
					if ( '' === $password ) {
						$password = 'x';
					}

					$hash = \wp_hash_password( $password );
					self::check( $result, 'wp_hash_password.string', is_string( $hash ), $password, 'string hash', $hash );
					self::check( $result, 'wp_check_password.round_trip', \wp_check_password( $password, $hash ), $password, true, false );

					$wrong = $password . 'x';
					self::check( $result, 'wp_check_password.rejects_wrong_password', ! \wp_check_password( $wrong, $hash ), $password, false, true );
				}
			}
		);
	}

	private static function exercise_parse_helpers( array &$result, array &$rng ): void {
		unset( $rng );

		if ( self::have_functions( array( 'wp_parse_str' ), $result, 'wp_parse_str' ) ) {
			$queries = array(
				'author=Alice&email=alice%40example.com&url=https%3A%2F%2Fexample.com%2F',
				'comment_author=A%26B&comment_author_email=bad%40%40example.com&empty=',
				'name%5Bfirst%5D=Alice&name%5Blast%5D=Example&cap=edit_posts',
			);

			self::run_case(
				$result,
				'wp_parse_str',
				static function () use ( &$result, $queries ): void {
					foreach ( $queries as $query ) {
						$parsed = array();
						\wp_parse_str( $query, $parsed );
						self::check( $result, 'wp_parse_str.array_result', is_array( $parsed ), $query, 'array', $parsed );
						self::check( $result, 'wp_parse_str.nonempty_for_nonempty_query', '' === $query || array() !== $parsed, $query, 'non-empty array', $parsed );
					}
				}
			);
		}
	}

	private static function option_cases( array &$rng ): array {
		$absint_options = array(
			'thumbnail_size_w',
			'thumbnail_size_h',
			'comment_max_links',
			'thread_comments_depth',
			'users_can_register',
			'start_of_week',
		);
		$numeric_values = array( -10, '-7', 0, '0', 1, '42', '99 bottles', true, false, self::rand_int( $rng, -100, 100 ) );

		$cases = array();
		foreach ( $absint_options as $option ) {
			foreach ( $numeric_values as $value ) {
				$cases[] = array( $option, $value, 'absint' );
			}
		}

		foreach ( array( 'posts_per_page', 'posts_per_rss' ) as $option ) {
			foreach ( array( -10, -1, 0, '0', '12', 'abc', self::rand_int( $rng, -50, 50 ) ) as $value ) {
				$cases[] = array( $option, $value, 'positive_or_minus_one_int' );
			}
		}

		foreach ( array( 'default_ping_status', 'default_comment_status' ) as $option ) {
			foreach ( array( '', '0', 'open', 'closed', 'weird<script>' ) as $value ) {
				$cases[] = array( $option, $value, 'status_string' );
			}
		}

		foreach ( array( 'UTF-8', 'utf8mb4', 'utf 8', '../bad', "bad\x80", 123 ) as $value ) {
			$cases[] = array( 'blog_charset', $value, 'charset' );
		}

		foreach ( array( -1, 0, 1, '1', 'abc', true, false ) as $value ) {
			$cases[] = array( 'blog_public', $value, 'blog_public' );
		}

		foreach ( array( -12, '-5.5', '0', '5:30', 'UTC', '1e3', self::rand_int( $rng, -12, 14 ) ) as $value ) {
			$cases[] = array( 'gmt_offset', $value, 'gmt_offset' );
		}

		return $cases;
	}

	private static function comment_like_array( string $author, string $email, string $url, string $content, string $ip, string $agent ): array {
		return array(
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => $url,
			'comment_content'      => $content,
			'comment_author_IP'    => $ip,
			'comment_agent'        => $agent,
			'user_id'              => 0,
		);
	}

	private static function password_chars( bool $special_chars, bool $extra_special_chars ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$chars .= '!@#$%^&*()';
		}
		if ( $extra_special_chars ) {
			$chars .= '-_ []{}<>~`+=,.;:/?|';
		}

		return $chars;
	}

	private static function avatar_cases( array &$rng ): array {
		$token      = substr( hash( 'sha256', self::rand_bytes( $rng, 16 ) ), 0, 10 );
		$sha_email  = 'hash-source-' . $token . '@example.test';
		$user_email = 'avatar-user-' . $token . '@example.test';
		$cases      = array(
			array(
				'label' => 'mixed_email_alias_default',
				'id'    => '  Mixed.' . $token . '+Tag@Example.TEST  ',
				'args'  => array(
					'size'          => '0',
					'height'        => '27',
					'width'         => 0,
					'default'       => 'mysteryman',
					'force_default' => false,
					'rating'        => 'PG',
				),
			),
			array(
				'label' => 'sha256_hash_force_default',
				'id'    => hash( 'sha256', strtolower( $sha_email ) ) . '@sha256.gravatar.com',
				'args'  => array(
					'size'          => self::rand_int( $rng, 24, 96 ),
					'height'        => null,
					'width'         => '17',
					'default'       => 'gravatar_default',
					'force_default' => true,
					'rating'        => 'X',
				),
			),
			array(
				'label' => 'option_backed_defaults',
				'id'    => 'option-default-' . $token . '@example.test',
				'args'  => array(
					'size'   => self::rand_int( $rng, 32, 128 ),
					'height' => 'bad-height',
					'width'  => self::rand_int( $rng, 32, 128 ),
				),
			),
			array(
				'label' => 'email_initials',
				'id'    => 'first_last-' . $token . '@example.test',
				'args'  => array(
					'size'          => self::rand_int( $rng, 48, 96 ),
					'height'        => self::rand_int( $rng, 12, 36 ),
					'width'         => 'bad-width',
					'default'       => 'initials',
					'force_default' => false,
					'rating'        => 'g',
				),
			),
		);

		if ( class_exists( 'WP_User' ) ) {
			$cases[] = array(
				'label' => 'wp_user_initials',
				'id'    => self::fake_avatar_user(
					self::rand_int( $rng, 1000, 9999 ),
					'avatar_user_' . $token,
					$user_email,
					'Ada Lovelace',
					'Ada',
					'Lovelace'
				),
				'args'  => array(
					'size'          => self::rand_int( $rng, 36, 120 ),
					'height'        => null,
					'width'         => '-18',
					'default'       => 'initials',
					'force_default' => true,
					'rating'        => 'R',
				),
			);
		}

		return $cases;
	}

	private static function expected_avatar_data( array $case, string $option_default, string $option_rating ): array {
		$args           = $case['args'];
		$size           = self::normalize_avatar_dimension( $args['size'] ?? 96, 96 );
		$height         = self::normalize_avatar_dimension( $args['height'] ?? null, $size );
		$width          = self::normalize_avatar_dimension( $args['width'] ?? null, $size );
		$default        = self::normalize_avatar_default( $args['default'] ?? $option_default, $option_default );
		$force_default  = (bool) ( $args['force_default'] ?? false );
		$rating         = strtolower( (string) ( $args['rating'] ?? $option_rating ) );
		$email_hash     = self::expected_avatar_hash( $case['id'] );
		$expected_query = array(
			's' => (string) $size,
			'r' => $rating,
		);

		if ( false !== $default ) {
			$expected_query['d'] = (string) $default;
		}
		if ( $force_default ) {
			$expected_query['f'] = 'y';
		}

		$initials = self::expected_avatar_initials( $case['id'], $default );
		if ( null !== $initials ) {
			$expected_query['initials'] = $initials;
		}

		ksort( $expected_query );

		return array(
			'size'         => $size,
			'height'       => $height,
			'width'        => $width,
			'default'      => $default,
			'forceDefault' => $force_default,
			'rating'       => $rating,
			'hash'         => $email_hash,
			'query'        => $expected_query,
		);
	}

	private static function normalize_avatar_dimension( $value, int $fallback ): int {
		if ( is_numeric( $value ) ) {
			$value = abs( (int) $value );
			return $value ? $value : $fallback;
		}

		return $fallback;
	}

	private static function normalize_avatar_default( $default, string $option_default ) {
		if ( empty( $default ) ) {
			$default = $option_default;
		}

		if ( in_array( $default, array( 'mm', 'mystery', 'mysteryman' ), true ) ) {
			return 'mm';
		}
		if ( 'gravatar_default' === $default ) {
			return false;
		}

		return $default;
	}

	private static function expected_avatar_hash( $id_or_email ): string {
		if ( is_string( $id_or_email ) ) {
			if ( str_contains( $id_or_email, '@sha256.gravatar.com' ) || str_contains( $id_or_email, '@md5.gravatar.com' ) ) {
				return explode( '@', $id_or_email )[0];
			}

			return hash( 'sha256', strtolower( trim( $id_or_email ) ) );
		}

		if ( $id_or_email instanceof \WP_User ) {
			return hash( 'sha256', strtolower( trim( $id_or_email->user_email ) ) );
		}

		return '';
	}

	private static function expected_avatar_initials( $id_or_email, $default ): ?string {
		if ( 'initials' !== $default ) {
			return null;
		}

		$name = '';
		if ( $id_or_email instanceof \WP_User ) {
			if ( '' !== $id_or_email->display_name ) {
				$name = $id_or_email->display_name;
			} elseif ( '' !== $id_or_email->first_name && '' !== $id_or_email->last_name ) {
				$name = $id_or_email->first_name . ' ' . $id_or_email->last_name;
			} else {
				$name = $id_or_email->user_login;
			}
		} elseif ( is_string( $id_or_email ) && str_contains( $id_or_email, '@' ) ) {
			$name = str_replace( array( '.', '_', '-' ), ' ', substr( $id_or_email, 0, strpos( $id_or_email, '@' ) ) );
		}

		if ( '' === $name ) {
			return null;
		}

		if ( ! str_contains( $name, ' ' ) || preg_match( '/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u', $name ) ) {
			return mb_substr( $name, 0, min( 2, mb_strlen( $name, 'UTF-8' ) ), 'UTF-8' );
		}

		return mb_substr( $name, 0, 1, 'UTF-8' ) . mb_substr( $name, strrpos( $name, ' ' ) + 1, 1, 'UTF-8' );
	}

	private static function avatar_url_observation( $url ): array {
		if ( ! is_string( $url ) ) {
			return array(
				'url'   => $url,
				'parts' => false,
				'query' => array(),
			);
		}

		$parts = \wp_parse_url( $url );
		$query = array();
		if ( is_array( $parts ) && isset( $parts['query'] ) ) {
			\wp_parse_str( $parts['query'], $query );
		}
		ksort( $query );

		return array(
			'url'    => $url,
			'scheme' => is_array( $parts ) ? ( $parts['scheme'] ?? null ) : null,
			'host'   => is_array( $parts ) ? ( $parts['host'] ?? null ) : null,
			'path'   => is_array( $parts ) ? ( $parts['path'] ?? null ) : null,
			'query'  => $query,
		);
	}

	private static function avatar_url_matches_expected( array $observed, array $expected ): bool {
		return 'https' === ( $observed['scheme'] ?? null )
			&& 'secure.gravatar.com' === ( $observed['host'] ?? null )
			&& '/avatar/' . $expected['hash'] === ( $observed['path'] ?? null )
			&& $expected['query'] === ( $observed['query'] ?? array() );
	}

	private static function avatar_dimensions_match( array $actual, array $expected ): bool {
		return ( $actual['size'] ?? null ) === $expected['size']
			&& ( $actual['height'] ?? null ) === $expected['height']
			&& ( $actual['width'] ?? null ) === $expected['width'];
	}

	private static function avatar_dimension_expectation( array $expected ): array {
		return array(
			'size'   => $expected['size'],
			'height' => $expected['height'],
			'width'  => $expected['width'],
		);
	}

	private static function avatar_dimension_observation( array $actual ): array {
		return array(
			'size'   => $actual['size'] ?? null,
			'height' => $actual['height'] ?? null,
			'width'  => $actual['width'] ?? null,
		);
	}

	private static function avatar_flag_expectation( array $expected ): array {
		return array(
			'default'      => $expected['default'],
			'forceDefault' => $expected['forceDefault'],
			'rating'       => $expected['rating'],
			'foundAvatar'  => true,
		);
	}

	private static function avatar_flag_observation( array $actual ): array {
		return array(
			'default'      => $actual['default'] ?? null,
			'forceDefault' => $actual['force_default'] ?? null,
			'rating'       => $actual['rating'] ?? null,
			'foundAvatar'  => $actual['found_avatar'] ?? null,
		);
	}

	private static function avatar_url_expectation( array $expected ): array {
		return array(
			'scheme' => 'https',
			'host'   => 'secure.gravatar.com',
			'path'   => '/avatar/' . $expected['hash'],
			'query'  => $expected['query'],
		);
	}

	private static function fake_avatar_user( int $user_id, string $login, string $email, string $display_name, string $first_name, string $last_name ): \WP_User {
		$reflection = new \ReflectionClass( 'WP_User' );
		$user       = $reflection->newInstanceWithoutConstructor();
		$user->ID   = $user_id;
		$user->data = (object) array(
			'ID'                  => $user_id,
			'user_login'          => $login,
			'user_pass'           => '',
			'user_nicename'       => $login,
			'user_email'          => $email,
			'user_url'            => '',
			'user_registered'     => '2024-01-01 00:00:00',
			'user_activation_key' => '',
			'user_status'         => '0',
			'display_name'        => $display_name,
			'first_name'          => $first_name,
			'last_name'           => $last_name,
		);
		$user->filter = null;

		return $user;
	}

	private static function describe_avatar_case( array $case ): array {
		return array(
			'label' => $case['label'],
			'id'    => self::describe_avatar_id( $case['id'] ),
			'args'  => $case['args'],
		);
	}

	private static function describe_avatar_id( $id_or_email ) {
		if ( $id_or_email instanceof \WP_User ) {
			return array(
				'type'        => 'WP_User',
				'ID'          => $id_or_email->ID,
				'userLogin'   => $id_or_email->user_login,
				'userEmail'   => $id_or_email->user_email,
				'displayName' => $id_or_email->display_name,
			);
		}

		return $id_or_email;
	}

	private static function same_avatar_id( $expected, $actual ): bool {
		if ( is_object( $expected ) || is_object( $actual ) ) {
			return $expected === $actual;
		}

		return $expected === $actual;
	}

	private static function string_corpus( array &$rng ): array {
		unset( $rng );

		return array(
			'',
			'admin',
			'Admin.User-01',
			'first last',
			'user@example.com',
			"  whitespace\tname\n",
			'<b>Alice</b> &amp; Bob %41',
			"O'Reilly \\ \" <script>alert(1)</script>",
			"jo\x00n",
			"bad\x80\xFFbytes",
			"line1\r\nline2\tline3",
			"\x01control\x7Fchars",
			"snowman-\xE2\x98\x83",
			"emoji-\xF0\x9F\x98\x80",
			"accent-gr\xC3\xA5",
			str_repeat( 'A', self::MAX_STRING_BYTES ),
			str_repeat( "x%41&nbsp; \t", 180 ),
		);
	}

	private static function generated_strings( array &$rng, int $count ): array {
		$values = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$values[] = self::random_string( $rng, self::rand_int( $rng, 0, 256 ) );
		}

		return $values;
	}

	private static function random_string( array &$rng, int $length ): string {
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
			'<b>',
			'</b>',
			'&amp;',
			'%41',
			"'",
			'"',
			'\\',
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
			$out .= $atoms[ self::rand_int( $rng, 0, count( $atoms ) - 1 ) ];
		}

		return substr( $out, 0, min( $length, self::MAX_STRING_BYTES ) );
	}

	private static function run_case( array &$result, string $case, callable $callback ): void {
		++$result['cases'];
		$result['coverage'][] = $case;

		$before = self::snapshot_globals();
		try {
			$callback();
		} catch ( \Throwable $e ) {
			self::failure(
				$result,
				$case . '.no_throw',
				$case,
				'case completed without throwing',
				get_class( $e ) . ': ' . $e->getMessage()
			);
		}

		self::restore_globals( $before );
		$after_restore = self::snapshot_globals();
		self::check( $result, $case . '.globals_restored', self::same_value( $before, $after_restore ), $case, $before, $after_restore );
	}

	private static function install_db_free_short_circuits(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		\add_filter(
			'pre_option_blog_charset',
			static function () {
				return 'UTF-8';
			},
			0,
			3
		);
		\add_filter(
			'pre_option_WPLANG',
			static function () {
				return '';
			},
			0,
			3
		);
		\add_filter(
			'pre_site_option_WPLANG',
			static function () {
				return '';
			},
			0,
			3
		);
	}

	private static function snapshot_globals( bool $include_filters = false ): array {
		$snapshot = array(
			'_COOKIE'           => $_COOKIE,
			'wp_current_filter' => $GLOBALS['wp_current_filter'] ?? null,
			'wp_filters_exists' => array_key_exists( 'wp_filters', $GLOBALS ),
			'wp_filters'        => $GLOBALS['wp_filters'] ?? null,
		);

		if ( $include_filters ) {
			$snapshot['wp_filter_exists'] = array_key_exists( 'wp_filter', $GLOBALS );
			$snapshot['wp_filter']        = $GLOBALS['wp_filter'] ?? null;
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		$_COOKIE = $snapshot['_COOKIE'];
		if ( array_key_exists( 'wp_current_filter', $snapshot ) ) {
			if ( null === $snapshot['wp_current_filter'] ) {
				unset( $GLOBALS['wp_current_filter'] );
			} else {
				$GLOBALS['wp_current_filter'] = $snapshot['wp_current_filter'];
			}
		}
		if ( array_key_exists( 'wp_filters_exists', $snapshot ) ) {
			if ( $snapshot['wp_filters_exists'] ) {
				$GLOBALS['wp_filters'] = $snapshot['wp_filters'];
			} else {
				unset( $GLOBALS['wp_filters'] );
			}
		}
		if ( array_key_exists( 'wp_filter_exists', $snapshot ) ) {
			if ( $snapshot['wp_filter_exists'] ) {
				$GLOBALS['wp_filter'] = $snapshot['wp_filter'];
			} else {
				unset( $GLOBALS['wp_filter'] );
			}
		}
	}

	private static function check( array &$result, string $invariant, bool $ok, $input = null, $expected = null, $actual = null ): void {
		++$result['checks'];
		if ( $ok ) {
			return;
		}

		self::failure( $result, $invariant, $input, $expected, $actual );
	}

	private static function failure( array &$result, string $invariant, $input, $expected, $actual = null ): void {
		$result['failures'][] = array(
			'invariant' => $invariant,
			'input'     => self::summarize( $input ),
			'expected'  => self::summarize( $expected ),
			'actual'    => self::summarize( $actual ),
		);
	}

	private static function have_functions( array $functions, array &$result, string $label ): bool {
		$missing = array();
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = $function;
			}
		}

		if ( array() === $missing ) {
			return true;
		}

		$result['skips'][] = $label . ':missing_functions:' . implode( ',', $missing );
		return false;
	}

	private static function summarize( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return array(
				'type'    => 'string',
				'bytes'   => strlen( $value ),
				'sha1'    => sha1( $value ),
				'preview' => self::escape_bytes( substr( $value, 0, self::SAMPLE_BYTES ) ),
			);
		}

		if ( is_array( $value ) ) {
			if ( $depth >= 2 ) {
				return array(
					'type'  => 'array',
					'count' => count( $value ),
				);
			}

			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 12 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::summarize( $item, $depth + 1 );
				++$i;
			}

			return $out;
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function escape_bytes( string $value ): string {
		$out = '';
		$len = strlen( $value );
		for ( $i = 0; $i < $len; ++$i ) {
			$ord = ord( $value[ $i ] );
			if ( 0x5C === $ord ) {
				$out .= '\\\\';
			} elseif ( $ord >= 0x20 && $ord <= 0x7E ) {
				$out .= $value[ $i ];
			} elseif ( 0x0A === $ord ) {
				$out .= '\\n';
			} elseif ( 0x0D === $ord ) {
				$out .= '\\r';
			} elseif ( 0x09 === $ord ) {
				$out .= '\\t';
			} else {
				$out .= sprintf( '\\x%02X', $ord );
			}
		}

		return $out;
	}

	private static function same_value( $left, $right ): bool {
		return serialize( $left ) === serialize( $right );
	}

	private static function context_seed( \ComponentFuzz\FuzzContext $ctx ): string {
		foreach ( array( 'seed', 'getSeed', 'get_seed' ) as $method ) {
			if ( is_callable( array( $ctx, $method ) ) ) {
				try {
					$value = $ctx->$method();
					if ( is_scalar( $value ) ) {
						return (string) $value;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		foreach ( array( 'option', 'getOption', 'param', 'getParam' ) as $method ) {
			if ( is_callable( array( $ctx, $method ) ) ) {
				try {
					$value = $ctx->$method( 'seed', null );
					if ( is_scalar( $value ) ) {
						return (string) $value;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		try {
			$ref = new \ReflectionObject( $ctx );
			if ( $ref->hasProperty( 'seed' ) ) {
				$property = $ref->getProperty( 'seed' );
				if ( $property->isPublic() ) {
					$value = $property->getValue( $ctx );
					if ( is_scalar( $value ) ) {
						return (string) $value;
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return 'identity:0';
	}

	private static function make_rng( string $seed ): array {
		return array(
			'seed'    => $seed,
			'counter' => 0,
			'buffer'  => '',
		);
	}

	private static function rand_bytes( array &$rng, int $length ): string {
		while ( strlen( $rng['buffer'] ) < $length ) {
			$rng['buffer'] .= hash( 'sha256', $rng['seed'] . ':identity:' . $rng['counter'], true );
			++$rng['counter'];
		}

		$out           = substr( $rng['buffer'], 0, $length );
		$rng['buffer'] = substr( $rng['buffer'], $length );
		return $out;
	}

	private static function rand_uint32( array &$rng ): int {
		$parts = unpack( 'Nvalue', self::rand_bytes( $rng, 4 ) );
		return (int) $parts['value'];
	}

	private static function rand_int( array &$rng, int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}

		return $min + ( self::rand_uint32( $rng ) % ( $max - $min + 1 ) );
	}
}
