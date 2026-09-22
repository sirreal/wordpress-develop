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
			self::exercise_user_dropdowns( $result, $rng );
			self::exercise_wp_user_identity_fields( $result, $rng );
			self::exercise_current_user_lifecycle( $result, $rng );
			self::exercise_user_existence_helpers( $result, $rng );
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

	private static function exercise_user_dropdowns( array &$result, array &$rng ): void {
		if ( ! class_exists( 'WP_User' ) || ! class_exists( 'WP_User_Query' ) ) {
			$result['skips'][] = 'user_dropdowns:missing_class:WP_User_or_WP_User_Query';
			return;
		}

		if ( ! self::have_functions( array( '_x', 'add_filter', 'clean_user_cache', 'esc_html', 'get_userdata', 'has_filter', 'remove_filter', 'update_user_caches', 'wp_cache_get', 'wp_dropdown_users' ), $result, 'user_dropdowns' ) ) {
			return;
		}

		$cases = array_slice( self::wp_user_identity_cases( $rng ), 0, 3 );

		self::run_case(
			$result,
			'user_dropdowns',
			static function () use ( &$result, $cases ): void {
				$token        = str_replace( '-', '_', $cases[0]['token'] );
				$rows_by_id   = array();
				$users        = array();
				$arg_events   = array();
				$query_events = array();
				$html_events  = array();
				$mode         = '';
				$marker       = '<!-- identity-dropdown-' . $token . ' -->';

				foreach ( $cases as $case ) {
					$row                            = $case['row'];
					$rows_by_id[ (int) $row['ID'] ] = $row;
				}

				$ids              = array_keys( $rows_by_id );
				$select_name      = 'identity_user_' . $token;
				$select_id        = 'identity-user-' . $token;
				$select_class     = 'identity-user-class-' . $token;
				$show_option_all  = 'All identity ' . $token;
				$show_option_none = 'No identity ' . $token;
				$none_value       = -7000 - (int) ( $ids[0] % 997 );

				$dropdown_args_filter = static function ( array $query_args, array $parsed_args ) use ( &$arg_events, &$mode, $ids ): array {
					$arg_events[] = array(
						'mode'       => $mode,
						'queryArgs'  => self::user_dropdown_args_snapshot( $query_args ),
						'parsedArgs' => self::user_dropdown_args_snapshot( $parsed_args ),
					);

					if ( 'main' === $mode ) {
						$query_args['include'] = array( $ids[0], $ids[1] );
						$query_args['orderby'] = 'ID';
						$query_args['order']   = 'DESC';
						$query_args['fields']  = array( 'ID', 'user_login', 'display_name', 'user_email' );
					} elseif ( 'include_selected' === $mode ) {
						$query_args['include'] = array( $ids[0] );
						$query_args['orderby'] = 'ID';
						$query_args['order']   = 'ASC';
						$query_args['fields']  = array( 'ID', 'user_login' );
					} elseif ( 'single' === $mode ) {
						$query_args['exclude'] = array( $ids[1], $ids[2] );
						$query_args['orderby'] = 'ID';
						$query_args['order']   = 'ASC';
						$query_args['fields']  = array( 'ID', 'user_login' );
					}

					return $query_args;
				};

				$users_pre_query_filter = static function ( $users, $query ) use ( &$query_events, &$mode, $rows_by_id ) {
					if ( ! in_array( $mode, array( 'main', 'include_selected', 'single' ), true ) ) {
						return $users;
					}

					$query_vars    = is_object( $query ) && isset( $query->query_vars ) && is_array( $query->query_vars ) ? $query->query_vars : array();
					$query_events[] = array(
						'mode'      => $mode,
						'queryVars' => self::user_dropdown_args_snapshot( $query_vars ),
					);

					return self::user_dropdown_query_objects( $rows_by_id, $query_vars );
				};

				$html_filter = static function ( string $html ) use ( &$html_events, $marker ): string {
					$html_events[] = $html;

					return $html . $marker;
				};

				$before_filters = array(
					'wp_dropdown_users_args' => \has_filter( 'wp_dropdown_users_args', $dropdown_args_filter ),
					'users_pre_query'        => \has_filter( 'users_pre_query', $users_pre_query_filter ),
					'wp_dropdown_users'      => \has_filter( 'wp_dropdown_users', $html_filter ),
				);

				$main_html             = null;
				$main_echo             = null;
				$include_selected_html = null;
				$hidden_html           = null;
				$thrown                = null;

				try {
					foreach ( $cases as $case ) {
						$user    = self::fake_identity_user( $case['row'] );
						$users[] = $user;
						\clean_user_cache( $user );
						\update_user_caches( $user );
					}

					\add_filter( 'wp_dropdown_users_args', $dropdown_args_filter, 999, 2 );
					\add_filter( 'users_pre_query', $users_pre_query_filter, 999, 2 );
					\add_filter( 'wp_dropdown_users', $html_filter, 999, 1 );

					$mode = 'main';
					ob_start();
					try {
						$main_html = \wp_dropdown_users(
							array(
								'echo'              => false,
								'name'              => $select_name,
								'id'                => $select_id,
								'class'             => $select_class,
								'show'              => 'display_name_with_login',
								'selected'          => $ids[1],
								'show_option_all'   => $show_option_all,
								'show_option_none'  => $show_option_none,
								'option_none_value' => $none_value,
							)
						);
					} finally {
						$main_echo = ob_get_clean();
					}

					\remove_filter( 'wp_dropdown_users', $html_filter, 999 );

					$mode = 'include_selected';
					$include_selected_html = \wp_dropdown_users(
						array(
							'echo'             => false,
							'show'             => 'user_login',
							'selected'         => $ids[1],
							'include_selected' => true,
						)
					);

					$mode        = 'single';
					$hidden_html = \wp_dropdown_users(
						array(
							'echo'                    => false,
							'hide_if_only_one_author' => true,
							'show'                    => 'user_login',
						)
					);

					$mode = '';
				} catch ( \Throwable $e ) {
					$thrown = $e;
				} finally {
					$mode = '';
					\remove_filter( 'wp_dropdown_users', $html_filter, 999 );
					\remove_filter( 'users_pre_query', $users_pre_query_filter, 999 );
					\remove_filter( 'wp_dropdown_users_args', $dropdown_args_filter, 999 );

					foreach ( $users as $user ) {
						\clean_user_cache( $user );
					}
				}

				$after_filters = array(
					'wp_dropdown_users_args' => \has_filter( 'wp_dropdown_users_args', $dropdown_args_filter ),
					'users_pre_query'        => \has_filter( 'users_pre_query', $users_pre_query_filter ),
					'wp_dropdown_users'      => \has_filter( 'wp_dropdown_users', $html_filter ),
				);

				self::check( $result, 'wp_dropdown_users.filters_restored', $before_filters === $after_filters, array_keys( $before_filters ), $before_filters, $after_filters );
				self::check( $result, 'wp_dropdown_users.generated_user_caches_cleaned', self::user_existence_caches_are_clean( $cases ), $cases, 'no generated user cache keys remain', self::user_existence_cache_observation( $cases ) );

				if ( null !== $thrown ) {
					throw $thrown;
				}

				$main_args_event          = $arg_events[0] ?? array();
				$main_query_event         = $query_events[0] ?? array();
				$include_query_event      = $query_events[1] ?? array();
				$single_query_event       = $query_events[2] ?? array();
				$main_original_query      = $main_args_event['queryArgs'] ?? array();
				$main_parsed_args         = $main_args_event['parsedArgs'] ?? array();
				$main_query_vars          = $main_query_event['queryVars'] ?? array();
				$include_query_vars       = $include_query_event['queryVars'] ?? array();
				$single_query_vars        = $single_query_event['queryVars'] ?? array();
				$first_display_with_login = \esc_html( sprintf( \_x( '%1$s (%2$s)', 'user dropdown' ), $rows_by_id[ $ids[0] ]['display_name'], $rows_by_id[ $ids[0] ]['user_login'] ) );
				$second_display_with_login = \esc_html( sprintf( \_x( '%1$s (%2$s)', 'user dropdown' ), $rows_by_id[ $ids[1] ]['display_name'], $rows_by_id[ $ids[1] ]['user_login'] ) );

				self::check( $result, 'wp_dropdown_users.echo_false_returns_html_without_echoing', is_string( $main_html ) && '' !== $main_html && '' === $main_echo, $main_parsed_args, 'returned HTML and empty output buffer', array( 'html' => $main_html, 'echo' => $main_echo ) );
				self::check( $result, 'wp_dropdown_users.renders_select_name_id_class_contracts', str_contains( (string) $main_html, "<select name='{$select_name}' id='{$select_id}' class='{$select_class}'>" ), $main_parsed_args, array( 'name' => $select_name, 'id' => $select_id, 'class' => $select_class ), $main_html );
				self::check( $result, 'wp_dropdown_users.renders_all_none_and_selected_options', str_contains( (string) $main_html, "<option value='0'>{$show_option_all}</option>" ) && str_contains( (string) $main_html, "<option value='" . $none_value . "'>{$show_option_none}</option>" ) && str_contains( (string) $main_html, "<option value='" . $ids[1] . "' selected='selected'>" ), $main_parsed_args, array( 'all' => $show_option_all, 'none' => $show_option_none, 'selected' => $ids[1] ), $main_html );
				self::check( $result, 'wp_dropdown_users.args_filter_receives_query_args_and_parsed_args', 'main' === ( $main_args_event['mode'] ?? null ) && 'display_name_with_login' === ( $main_parsed_args['show'] ?? null ) && array( 'ID', 'user_login', 'display_name' ) === ( $main_original_query['fields'] ?? null ) && $select_name === ( $main_parsed_args['name'] ?? null ) && $ids[1] === (int) ( $main_parsed_args['selected'] ?? 0 ), $cases, 'query args derived from show plus parsed dropdown args', $main_args_event );
				self::check( $result, 'wp_dropdown_users.args_filter_constrains_include_order_and_fields', array( $ids[0], $ids[1] ) === self::user_dropdown_ids_from_query_arg( $main_query_vars['include'] ?? array() ) && 'ID' === ( $main_query_vars['orderby'] ?? null ) && 'DESC' === ( $main_query_vars['order'] ?? null ) && array( 'id', 'user_login', 'display_name', 'user_email' ) === ( $main_query_vars['fields'] ?? null ) && false !== strpos( (string) $main_html, "value='" . $ids[1] . "'" ) && false !== strpos( (string) $main_html, "value='" . $ids[0] . "'" ) && strpos( (string) $main_html, "value='" . $ids[1] . "'" ) < strpos( (string) $main_html, "value='" . $ids[0] . "'" ) && ! str_contains( (string) $main_html, "value='" . $ids[2] . "'" ), $cases, 'filtered include, DESC ID order, constrained fields, omitted third user', array( 'queryVars' => $main_query_vars, 'html' => $main_html ) );
				self::check( $result, 'wp_dropdown_users.html_filter_receives_generated_html_and_appends_marker', 1 === count( $html_events ) && is_string( $html_events[0] ?? null ) && str_contains( $html_events[0], '<select ' ) && ! str_contains( $html_events[0], $marker ) && str_ends_with( (string) $main_html, $marker ), $main_parsed_args, 'one pre-marker HTML event and marker appended to return', array( 'events' => $html_events, 'html' => $main_html ) );
				self::check( $result, 'wp_dropdown_users.display_name_with_login_labels_are_escaped', str_contains( (string) $main_html, '>' . $first_display_with_login . '</option>' ) && str_contains( (string) $main_html, '>' . $second_display_with_login . '</option>' ) && ! str_contains( (string) $main_html, $rows_by_id[ $ids[0] ]['display_name'] . ' (' . $rows_by_id[ $ids[0] ]['user_login'] . ')' ) && ! str_contains( (string) $main_html, $rows_by_id[ $ids[1] ]['display_name'] . ' (' . $rows_by_id[ $ids[1] ]['user_login'] . ')' ), $cases, array( $first_display_with_login, $second_display_with_login ), $main_html );
				self::check( $result, 'wp_dropdown_users.include_selected_appends_cache_seeded_omitted_user', array( $ids[0] ) === self::user_dropdown_ids_from_query_arg( $include_query_vars['include'] ?? array() ) && str_contains( (string) $include_selected_html, "<option value='" . $ids[0] . "'>" . \esc_html( $rows_by_id[ $ids[0] ]['user_login'] ) . '</option>' ) && str_contains( (string) $include_selected_html, "<option value='" . $ids[1] . "' selected='selected'>" . \esc_html( $rows_by_id[ $ids[1] ]['user_login'] ) . '</option>' ), $cases, 'query includes first user; selected second user appended from cache', array( 'queryVars' => $include_query_vars, 'html' => $include_selected_html, 'selectedUserId' => $ids[1] ) );
				self::check( $result, 'wp_dropdown_users.args_filter_constrains_exclude_for_single_author_branch', array( $ids[1], $ids[2] ) === self::user_dropdown_ids_from_query_arg( $single_query_vars['exclude'] ?? array() ), $cases, array( $ids[1], $ids[2] ), $single_query_vars );
				self::check( $result, 'wp_dropdown_users.hide_if_only_one_author_suppresses_single_filtered_user', '' === $hidden_html, $cases, 'filtered query returns one user and dropdown output is empty', array( 'queryVars' => $single_query_vars, 'html' => $hidden_html ) );
			}
		);
	}

	private static function exercise_wp_user_identity_fields( array &$result, array &$rng ): void {
		if ( ! class_exists( 'WP_User' ) ) {
			$result['skips'][] = 'wp_user_identity_fields:missing_class:WP_User';
			return;
		}

		if ( ! self::have_functions( array( 'add_filter', 'clean_user_cache', 'esc_attr', 'esc_url', 'get_user_by', 'get_userdata', 'has_filter', 'remove_filter', 'sanitize_email', 'sanitize_title_with_dashes', 'sanitize_user', 'sanitize_user_field', 'update_user_caches' ), $result, 'wp_user_identity_fields' ) ) {
			return;
		}

		$cases = self::wp_user_identity_cases( $rng );

		self::run_case(
			$result,
			'wp_user_identity_fields',
			static function () use ( &$result, $cases ): void {
				$fields = array( 'ID', 'user_login', 'user_nicename', 'user_email', 'user_url', 'display_name', 'first_name', 'last_name', 'description' );

				foreach ( $cases as $case ) {
					$row  = $case['row'];
					$user = self::fake_identity_user( $row );

					try {
						\clean_user_cache( $user );

						$array = $user->to_array();
						self::check( $result, 'wp_user.to_array_preserves_generated_identity_row', $row === $array, $case, $row, $array );
						self::check( $result, 'wp_user.raw_field_getters_match_data_properties', self::wp_user_raw_fields_match( $user, $row, $fields ), $case, self::wp_user_field_expectation( $row, $fields ), self::wp_user_field_observation( $user, $fields ) );
						self::check( $result, 'wp_user.exists_tracks_nonzero_generated_id', $user->exists() && (int) $row['ID'] === $user->ID, $case, array( 'exists' => true, 'ID' => $row['ID'] ), array( 'exists' => $user->exists(), 'ID' => $user->ID ) );

						$user->filter = 'display';
						$display_url  = $user->user_url;
						$user->filter = 'attribute';
						$attribute_url = $user->user_url;
						$user->filter  = null;

						self::check( $result, 'wp_user.user_url_contexts_use_public_escapers', \esc_url( $row['user_url'] ) === $display_url && \esc_attr( \esc_url( $row['user_url'] ) ) === $attribute_url, $case, array( 'display' => \esc_url( $row['user_url'] ), 'attribute' => \esc_attr( \esc_url( $row['user_url'] ) ) ), array( 'display' => $display_url, 'attribute' => $attribute_url ) );

						\update_user_caches( $user );
						$lookups = array(
							'id'    => \get_userdata( $row['ID'] ),
							'login' => \get_user_by( 'login', $row['user_login'] ),
							'slug'  => \get_user_by( 'slug', $row['user_nicename'] ),
							'email' => \get_user_by( 'email', $row['user_email'] ),
						);

						self::check( $result, 'wp_user.cache_seeded_public_lookups_round_trip_identity', self::wp_user_lookups_match_row( $lookups, $row ), $case, self::wp_user_lookup_expectation( $row ), self::wp_user_lookup_observation( $lookups ) );
					} finally {
						$user->filter = null;
						\clean_user_cache( $user );
					}
				}

				self::exercise_wp_user_field_filters( $result, $cases[0] );
			}
		);
	}

	private static function exercise_wp_user_field_filters( array &$result, array $case ): void {
		$row     = $case['row'];
		$user    = self::fake_identity_user( $row );
		$token   = $case['token'];
		$events  = array(
			'displayName' => array(),
			'userUrl'     => array(),
			'nicename'    => array(),
			'editName'    => array(),
		);
		$name    = 'Filtered <b>Display</b> ' . $token;
		$url     = 'https://filtered.example.test/profile/' . rawurlencode( $token ) . '?a=1&b=two';
		$slug    = 'filtered-nicename-' . $token;
		$edit    = 'Edit "Display" <script>' . $token . '</script>';

		$display_name_filter = static function ( $value, int $user_id, string $context ) use ( &$events, $name ): string {
			$events['displayName'][] = array(
				'value'   => $value,
				'userId'  => $user_id,
				'context' => $context,
			);

			return $name;
		};
		$user_url_filter     = static function ( $value, int $user_id, string $context ) use ( &$events, $url ): string {
			$events['userUrl'][] = array(
				'value'   => $value,
				'userId'  => $user_id,
				'context' => $context,
			);

			return $url;
		};
		$nicename_filter     = static function ( $value ) use ( &$events, $slug ): string {
			$events['nicename'][] = $value;

			return $slug;
		};
		$edit_name_filter    = static function ( $value, int $user_id ) use ( &$events, $edit ): string {
			$events['editName'][] = array(
				'value'  => $value,
				'userId' => $user_id,
			);

			return $edit;
		};

		$before_filters = array(
			'user_display_name'      => \has_filter( 'user_display_name', $display_name_filter ),
			'user_url'               => \has_filter( 'user_url', $user_url_filter ),
			'pre_user_nicename'      => \has_filter( 'pre_user_nicename', $nicename_filter ),
			'edit_user_display_name' => \has_filter( 'edit_user_display_name', $edit_name_filter ),
		);

		\add_filter( 'user_display_name', $display_name_filter, 999, 3 );
		\add_filter( 'user_url', $user_url_filter, 999, 3 );
		\add_filter( 'pre_user_nicename', $nicename_filter, 999, 1 );
		\add_filter( 'edit_user_display_name', $edit_name_filter, 999, 2 );

		try {
			$user->filter    = 'display';
			$display_name    = $user->display_name;
			$display_url     = $user->user_url;
			$raw_name        = \sanitize_user_field( 'display_name', $row['display_name'], $row['ID'], 'raw' );
			$db_nicename     = \sanitize_user_field( 'user_nicename', $row['user_nicename'], $row['ID'], 'db' );
			$edit_name       = \sanitize_user_field( 'display_name', $row['display_name'], $row['ID'], 'edit' );
			$user->filter    = null;
		} finally {
			$user->filter = null;
			\remove_filter( 'user_display_name', $display_name_filter, 999 );
			\remove_filter( 'user_url', $user_url_filter, 999 );
			\remove_filter( 'pre_user_nicename', $nicename_filter, 999 );
			\remove_filter( 'edit_user_display_name', $edit_name_filter, 999 );
		}

		$after_filters = array(
			'user_display_name'      => \has_filter( 'user_display_name', $display_name_filter ),
			'user_url'               => \has_filter( 'user_url', $user_url_filter ),
			'pre_user_nicename'      => \has_filter( 'pre_user_nicename', $nicename_filter ),
			'edit_user_display_name' => \has_filter( 'edit_user_display_name', $edit_name_filter ),
		);

		self::check( $result, 'wp_user.field_filters.display_name_filter_is_context_local', $name === $display_name && $row['display_name'] === $raw_name && 1 === count( $events['displayName'] ) && $row['display_name'] === $events['displayName'][0]['value'] && $row['ID'] === $events['displayName'][0]['userId'] && 'display' === $events['displayName'][0]['context'], $case, 'display filter once; raw context bypassed', array( 'displayName' => $display_name, 'rawName' => $raw_name, 'events' => $events['displayName'] ) );
		self::check( $result, 'wp_user.field_filters.user_url_filter_is_escaped_after_override', \esc_url( $url ) === $display_url && 1 === count( $events['userUrl'] ) && $row['user_url'] === $events['userUrl'][0]['value'] && 'display' === $events['userUrl'][0]['context'], $case, \esc_url( $url ), array( 'displayUrl' => $display_url, 'events' => $events['userUrl'] ) );
		self::check( $result, 'wp_user.field_filters.nicename_db_filter_is_prefix_scoped', $slug === $db_nicename && array( $row['user_nicename'] ) === $events['nicename'], $case, $slug, array( 'dbNicename' => $db_nicename, 'events' => $events['nicename'] ) );
		self::check( $result, 'wp_user.field_filters.edit_display_name_escapes_filter_result', \esc_attr( $edit ) === $edit_name && 1 === count( $events['editName'] ) && $row['display_name'] === $events['editName'][0]['value'] && $row['ID'] === $events['editName'][0]['userId'], $case, \esc_attr( $edit ), array( 'editName' => $edit_name, 'events' => $events['editName'] ) );
		self::check( $result, 'wp_user.field_filters.restored', $before_filters === $after_filters, array_keys( $before_filters ), $before_filters, $after_filters );
	}

	private static function exercise_current_user_lifecycle( array &$result, array &$rng ): void {
		if ( ! class_exists( 'WP_User' ) ) {
			$result['skips'][] = 'current_user_lifecycle:missing_class:WP_User';
			return;
		}

		if ( ! self::have_functions( array( 'add_action', 'add_filter', 'clean_user_cache', 'get_current_user_id', 'get_userdata', 'has_filter', 'is_user_logged_in', 'remove_action', 'remove_filter', 'sanitize_email', 'sanitize_title_with_dashes', 'sanitize_user', 'update_user_caches', 'wp_cache_get', 'wp_get_current_user', 'wp_set_current_user' ), $result, 'current_user_lifecycle' ) ) {
			return;
		}

		$cases = array_slice( self::wp_user_identity_cases( $rng ), 0, 3 );

		self::run_case(
			$result,
			'current_user_lifecycle',
			static function () use ( &$result, $cases ): void {
				$users                 = array();
				$user_global_snapshot  = self::snapshot_current_user_globals();
				$hook_global_snapshot  = self::snapshot_hook_globals();
				$set_current_user_hits = array();
				$set_current_user_hook = static function () use ( &$set_current_user_hits ): void {
					$set_current_user_hits[] = \get_current_user_id();
				};
				$before_action_filter = \has_filter( 'set_current_user', $set_current_user_hook );
				$determine_row        = $cases[2]['row'];
				$determine_events     = array();
				$determine_filter     = static function ( $user_id ) use ( &$determine_events, $determine_row ): int {
					$determine_events[] = $user_id;

					return (int) $determine_row['ID'];
				};
				$before_determine     = \has_filter( 'determine_current_user', $determine_filter );
				$thrown               = null;
				$cleanup_thrown       = null;

				try {
					foreach ( $cases as $case ) {
						$user    = self::fake_identity_user( $case['row'] );
						$users[] = $user;
						\update_user_caches( $user );
					}

					\add_action( 'set_current_user', $set_current_user_hook, 999, 0 );

					foreach ( $cases as $case ) {
						$row             = $case['row'];
						$before_hit_count = count( $set_current_user_hits );
						$current         = \wp_set_current_user( $row['ID'] );
						$fetched         = \wp_get_current_user();

						self::check( $result, 'wp_set_current_user.cache_seeded_user_populates_current_identity', $current instanceof \WP_User && $fetched instanceof \WP_User && (int) $row['ID'] === $current->ID && $current === $fetched && $row['user_login'] === $current->user_login && $row['user_email'] === $current->user_email && (int) $row['ID'] === \get_current_user_id() && \is_user_logged_in(), $case, self::current_user_expectation( $row ), self::current_user_observation( $current ) );
						self::check( $result, 'wp_set_current_user.setup_userdata_globals_match_current_identity', self::current_user_legacy_globals_match_row( $row ), $case, self::current_user_legacy_globals_expectation( $row ), self::current_user_legacy_globals_observation() );
						self::check( $result, 'wp_set_current_user.set_current_user_action_fires_after_identity_is_current', count( $set_current_user_hits ) === $before_hit_count + 1 && (int) $row['ID'] === $set_current_user_hits[ $before_hit_count ], $case, array( 'newHit' => (int) $row['ID'] ), $set_current_user_hits );

						$object_id_before_repeat = spl_object_id( $current );
						$hit_count_before_repeat = count( $set_current_user_hits );
						$repeat                  = \wp_set_current_user( $row['ID'] );
						self::check( $result, 'wp_set_current_user.same_id_is_idempotent_and_does_not_refire_action', $repeat instanceof \WP_User && spl_object_id( $repeat ) === $object_id_before_repeat && count( $set_current_user_hits ) === $hit_count_before_repeat, $case, array( 'objectId' => $object_id_before_repeat, 'actionCount' => $hit_count_before_repeat ), array( 'objectId' => $repeat instanceof \WP_User ? spl_object_id( $repeat ) : null, 'actionCount' => count( $set_current_user_hits ) ) );
					}

					$before_clear_hits = count( $set_current_user_hits );
					$cleared           = \wp_set_current_user( 0 );
					self::check( $result, 'wp_set_current_user.zero_id_clears_logged_in_state', $cleared instanceof \WP_User && 0 === $cleared->ID && 0 === \get_current_user_id() && ! \is_user_logged_in() && count( $set_current_user_hits ) === $before_clear_hits + 1, $cases, 'logged out WP_User with action hit', array( 'ID' => $cleared instanceof \WP_User ? $cleared->ID : null, 'currentId' => \get_current_user_id(), 'loggedIn' => \is_user_logged_in(), 'actionCount' => count( $set_current_user_hits ) ) );
					self::check( $result, 'wp_set_current_user.zero_id_resets_setup_userdata_globals', self::current_user_legacy_globals_are_logged_out(), $cases, self::current_user_logged_out_globals_expectation(), self::current_user_legacy_globals_observation() );

					$upgrade_row                = $cases[1]['row'];
					$GLOBALS['current_user']    = (object) array( 'ID' => $upgrade_row['ID'] );
					$before_upgrade_hit_count   = count( $set_current_user_hits );
					$upgraded                   = \wp_get_current_user();
					self::check( $result, 'wp_get_current_user.upgrades_legacy_object_global_from_cache', $upgraded instanceof \WP_User && (int) $upgrade_row['ID'] === $upgraded->ID && $upgrade_row['user_login'] === $upgraded->user_login && count( $set_current_user_hits ) === $before_upgrade_hit_count + 1, $cases[1], self::current_user_expectation( $upgrade_row ), self::current_user_observation( $upgraded ) );

					$GLOBALS['current_user'] = null;
					$_COOKIE            = array();
					\add_filter( 'determine_current_user', $determine_filter, 999, 1 );
					try {
						$determined = \wp_get_current_user();
					} finally {
						\remove_filter( 'determine_current_user', $determine_filter, 999 );
					}

					$after_determine = \has_filter( 'determine_current_user', $determine_filter );

					self::check( $result, 'wp_get_current_user.determine_current_user_filter_sets_generated_user', $determined instanceof \WP_User && (int) $determine_row['ID'] === $determined->ID && $determine_row['user_login'] === $determined->user_login && array( false ) === $determine_events, $cases[2], self::current_user_expectation( $determine_row ), array( 'user' => self::current_user_observation( $determined ), 'events' => $determine_events ) );
					self::check( $result, 'wp_get_current_user.determine_current_user_filter_restored', $before_determine === $after_determine, 'determine_current_user', $before_determine, $after_determine );

					\remove_action( 'set_current_user', $set_current_user_hook, 999 );
					self::check( $result, 'wp_set_current_user.action_filter_restored', $before_action_filter === \has_filter( 'set_current_user', $set_current_user_hook ), 'set_current_user', $before_action_filter, \has_filter( 'set_current_user', $set_current_user_hook ) );
				} catch ( \Throwable $e ) {
					$thrown = $e;
				} finally {
					try {
						foreach ( $users as $user ) {
							\clean_user_cache( $user );
						}
					} catch ( \Throwable $e ) {
						$cleanup_thrown = $e;
					} finally {
						self::restore_current_user_globals( $user_global_snapshot );
						self::restore_hook_globals( $hook_global_snapshot );
					}
				}

				self::check( $result, 'current_user_lifecycle.generated_user_caches_cleaned', self::user_existence_caches_are_clean( $cases ), $cases, 'no generated user cache keys remain', self::user_existence_cache_observation( $cases ) );
				self::check( $result, 'current_user_lifecycle.current_user_globals_restored', self::current_user_globals_match_snapshot( $user_global_snapshot ), self::current_user_global_names(), self::current_user_globals_expectation( $user_global_snapshot ), self::current_user_globals_observation() );
				self::check( $result, 'current_user_lifecycle.hook_globals_restored', self::hook_counter_globals_match_snapshot( $hook_global_snapshot ) && $before_action_filter === \has_filter( 'set_current_user', $set_current_user_hook ) && $before_determine === \has_filter( 'determine_current_user', $determine_filter ), array( 'set_current_user', 'determine_current_user' ), self::hook_globals_expectation( $hook_global_snapshot, $before_action_filter, $before_determine ), self::hook_globals_observation( $set_current_user_hook, $determine_filter ) );

				if ( null !== $cleanup_thrown && null === $thrown ) {
					$thrown = $cleanup_thrown;
				}

				if ( null !== $thrown ) {
					throw $thrown;
				}
			}
		);
	}

	private static function exercise_user_existence_helpers( array &$result, array &$rng ): void {
		if ( ! class_exists( 'WP_User' ) ) {
			$result['skips'][] = 'user_existence_helpers:missing_class:WP_User';
			return;
		}

		if ( ! self::have_functions( array( 'add_filter', 'clean_user_cache', 'email_exists', 'has_filter', 'remove_filter', 'sanitize_email', 'sanitize_title_with_dashes', 'sanitize_user', 'update_user_caches', 'username_exists', 'wp_cache_get' ), $result, 'user_existence_helpers' ) ) {
			return;
		}

		$cases       = array_slice( self::wp_user_identity_cases( $rng ), 0, 3 );
		$override_id = 900000 + self::rand_int( $rng, 1, 99999 );

		self::run_case(
			$result,
			'user_existence_helpers',
			static function () use ( &$result, $cases, $override_id ): void {
				$users = array();
				try {
					foreach ( $cases as $case ) {
						$user    = self::fake_identity_user( $case['row'] );
						$users[] = $user;
						\update_user_caches( $user );
					}

					$observed = array();
					foreach ( $cases as $case ) {
						$row       = $case['row'];
						$observed[] = array(
							'ID'       => $row['ID'],
							'username' => \username_exists( "\t" . $row['user_login'] . " \n" ),
							'email'    => \email_exists( ' ' . $row['user_email'] . "\t" ),
						);
					}

					self::check( $result, 'username_exists.cache_backed_padded_login_returns_generated_user_id', self::user_existence_observations_match( $observed, 'username' ), $cases, self::user_existence_expected_ids( $cases ), $observed );
					self::check( $result, 'email_exists.cache_backed_padded_email_returns_generated_user_id', self::user_existence_observations_match( $observed, 'email' ), $cases, self::user_existence_expected_ids( $cases ), $observed );

					$row            = $cases[0]['row'];
					$username_query = ' ' . $row['user_login'] . "\n";
					$email_query    = "\t" . $row['user_email'] . ' ';
					$empty_query    = " \t\n";
					$username_events = array();
					$email_events    = array();

					$username_filter = static function ( $user_id, string $username ) use ( &$username_events, $override_id ): int {
						$username_events[] = array(
							'userId'   => $user_id,
							'username' => $username,
						);

						return $override_id;
					};
					$email_filter    = static function ( $user_id, string $email ) use ( &$email_events, $override_id ): int {
						$email_events[] = array(
							'userId' => $user_id,
							'email'  => $email,
						);

						return $override_id + 1;
					};

					$before_filters = array(
						'username_exists' => \has_filter( 'username_exists', $username_filter ),
						'email_exists'    => \has_filter( 'email_exists', $email_filter ),
					);

					\add_filter( 'username_exists', $username_filter, 999, 2 );
					\add_filter( 'email_exists', $email_filter, 999, 2 );
					try {
						$filtered_username       = \username_exists( $username_query );
						$filtered_empty_username = \username_exists( $empty_query );
						$filtered_email          = \email_exists( $email_query );
						$filtered_empty_email    = \email_exists( $empty_query );
					} finally {
						\remove_filter( 'username_exists', $username_filter, 999 );
						\remove_filter( 'email_exists', $email_filter, 999 );
					}

					$after_filters = array(
						'username_exists' => \has_filter( 'username_exists', $username_filter ),
						'email_exists'    => \has_filter( 'email_exists', $email_filter ),
					);

					self::check( $result, 'username_exists.filter_receives_raw_query_and_cache_result', 2 === count( $username_events ) && (int) $row['ID'] === $username_events[0]['userId'] && $username_query === $username_events[0]['username'] && false === $username_events[1]['userId'] && $empty_query === $username_events[1]['username'], $row, 'cached ID event then empty false event', $username_events );
					self::check( $result, 'email_exists.filter_receives_raw_query_and_cache_result', 2 === count( $email_events ) && (int) $row['ID'] === $email_events[0]['userId'] && $email_query === $email_events[0]['email'] && false === $email_events[1]['userId'] && $empty_query === $email_events[1]['email'], $row, 'cached ID event then empty false event', $email_events );
					self::check( $result, 'user_existence_helpers.filters_can_override_hits_and_empty_misses', $override_id === $filtered_username && $override_id === $filtered_empty_username && ( $override_id + 1 ) === $filtered_email && ( $override_id + 1 ) === $filtered_empty_email, $row, array( $override_id, $override_id, $override_id + 1, $override_id + 1 ), array( $filtered_username, $filtered_empty_username, $filtered_email, $filtered_empty_email ) );
					self::check( $result, 'user_existence_helpers.filters_restored', $before_filters === $after_filters, array_keys( $before_filters ), $before_filters, $after_filters );
				} finally {
					foreach ( $users as $user ) {
						\clean_user_cache( $user );
					}
				}

				self::check( $result, 'user_existence_helpers.generated_user_caches_cleaned', self::user_existence_caches_are_clean( $cases ), $cases, 'no generated user cache keys remain', self::user_existence_cache_observation( $cases ) );
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

	private static function wp_user_identity_cases( array &$rng ): array {
		$token          = substr( hash( 'sha256', self::rand_bytes( $rng, 16 ) ), 0, 10 );
		$base_id        = 500000 + self::rand_int( $rng, 1, 500000 );
		$display_values = array(
			'Identity <b>User</b> ' . $token,
			'Display "Quoted" & ' . substr( self::random_string( $rng, 32 ), 0, 32 ),
			"Line\nName " . $token,
			"Unicode snowman \xE2\x98\x83 " . $token,
		);
		$url_values     = array(
			'https://example.test/users/' . $token . '?name=<b>x</b>&n=' . self::rand_int( $rng, 10, 99 ),
			'example.test/profile/' . $token . ' with spaces',
			'javascript:alert(1)',
			'mailto:identity-' . $token . '@example.test',
		);

		$cases = array();
		for ( $i = 0; $i < 4; ++$i ) {
			$login = \sanitize_user( 'identity_' . $token . '_' . $i . '_' . self::rand_int( $rng, 100, 999 ), true );
			if ( '' === $login ) {
				$login = 'identity_' . $token . '_' . $i;
			}

			$nicename = \sanitize_title_with_dashes( $display_values[ $i ] . '-' . $login . '-' . substr( self::random_string( $rng, 20 ), 0, 20 ), '', 'save' );
			if ( '' === $nicename ) {
				$nicename = 'identity-' . $token . '-' . $i;
			}

			$email = \sanitize_email( 'identity-' . $token . '-' . $i . '@example.test' );
			if ( '' === $email ) {
				$email = 'identity-' . $token . '-' . $i . '@example.com';
			}

			$cases[] = array(
				'label' => 'generated_identity_user_' . $i,
				'token' => $token . '-' . $i,
				'row'   => array(
					'ID'                  => $base_id + $i,
					'user_login'          => $login,
					'user_pass'           => '',
					'user_nicename'       => $nicename,
					'user_email'          => $email,
					'user_url'            => $url_values[ $i ],
					'user_registered'     => sprintf( '2026-06-%02d 12:00:00', 20 + $i ),
					'user_activation_key' => 'activation-' . $token . '-' . $i,
					'user_status'         => '0',
					'display_name'        => $display_values[ $i ],
					'nickname'            => 'nick-' . $token . '-' . $i,
					'first_name'          => 'First ' . $i,
					'last_name'           => 'Last ' . $token,
					'description'         => 'Profile <em>description</em> ' . substr( self::random_string( $rng, 32 ), 0, 32 ),
				),
			);
		}

		return $cases;
	}

	private static function fake_identity_user( array $row ): \WP_User {
		$reflection = new \ReflectionClass( 'WP_User' );
		$user       = $reflection->newInstanceWithoutConstructor();
		$user->ID   = (int) $row['ID'];
		$user->data = (object) $row;
		$user->filter = null;
		$user->caps   = array();
		$user->roles  = array();
		$user->allcaps = array();

		return $user;
	}

	private static function user_dropdown_args_snapshot( array $args ): array {
		$keys = array(
			'include',
			'exclude',
			'orderby',
			'order',
			'fields',
			'show',
			'selected',
			'include_selected',
			'name',
			'id',
			'class',
			'show_option_all',
			'show_option_none',
			'hide_if_only_one_author',
		);

		$out = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$out[ $key ] = $args[ $key ];
			}
		}

		return $out;
	}

	private static function user_dropdown_query_objects( array $rows_by_id, array $query_vars ): array {
		$rows    = array_values( $rows_by_id );
		$include = self::user_dropdown_ids_from_query_arg( $query_vars['include'] ?? array() );
		$exclude = self::user_dropdown_ids_from_query_arg( $query_vars['exclude'] ?? array() );

		if ( array() !== $include ) {
			$include_map = array_flip( $include );
			$rows        = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $include_map ): bool {
						return isset( $include_map[ (int) $row['ID'] ] );
					}
				)
			);
		} elseif ( array() !== $exclude ) {
			$exclude_map = array_flip( $exclude );
			$rows        = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $exclude_map ): bool {
						return ! isset( $exclude_map[ (int) $row['ID'] ] );
					}
				)
			);
		}

		$orderby = strtolower( (string) ( $query_vars['orderby'] ?? 'ID' ) );
		$order   = strtoupper( (string) ( $query_vars['order'] ?? 'ASC' ) );
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $orderby, $order ): int {
				if ( 'display_name' === $orderby ) {
					$comparison = strcmp( (string) $left['display_name'], (string) $right['display_name'] );
				} elseif ( 'user_login' === $orderby ) {
					$comparison = strcmp( (string) $left['user_login'], (string) $right['user_login'] );
				} else {
					$comparison = (int) $left['ID'] <=> (int) $right['ID'];
				}

				return 'DESC' === $order ? -$comparison : $comparison;
			}
		);

		$objects = array();
		foreach ( $rows as $row ) {
			$objects[] = (object) array(
				'ID'           => (int) $row['ID'],
				'user_login'   => $row['user_login'],
				'display_name' => $row['display_name'],
				'user_email'   => $row['user_email'],
			);
		}

		return $objects;
	}

	private static function user_dropdown_ids_from_query_arg( $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}

		$ids = array();
		foreach ( (array) $value as $item ) {
			$id = (int) $item;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function current_user_expectation( array $row ): array {
		return array(
			'ID'         => (int) $row['ID'],
			'user_login' => $row['user_login'],
			'user_email' => $row['user_email'],
			'loggedIn'   => 0 !== (int) $row['ID'],
		);
	}

	private static function current_user_observation( $user ): array {
		return array(
			'class'      => is_object( $user ) ? get_class( $user ) : gettype( $user ),
			'ID'         => $user instanceof \WP_User ? $user->ID : null,
			'user_login' => $user instanceof \WP_User ? $user->user_login : null,
			'user_email' => $user instanceof \WP_User ? $user->user_email : null,
			'currentId'  => function_exists( 'get_current_user_id' ) ? \get_current_user_id() : null,
			'loggedIn'   => function_exists( 'is_user_logged_in' ) ? \is_user_logged_in() : null,
		);
	}

	private static function current_user_legacy_globals_match_row( array $row ): bool {
		return (int) $row['ID'] === ( $GLOBALS['user_ID'] ?? null )
			&& 0 === ( $GLOBALS['user_level'] ?? null )
			&& $row['user_login'] === ( $GLOBALS['user_login'] ?? null )
			&& $row['user_email'] === ( $GLOBALS['user_email'] ?? null )
			&& $row['user_url'] === ( $GLOBALS['user_url'] ?? null )
			&& $row['display_name'] === ( $GLOBALS['user_identity'] ?? null )
			&& isset( $GLOBALS['userdata'] )
			&& $GLOBALS['userdata'] instanceof \WP_User
			&& (int) $row['ID'] === $GLOBALS['userdata']->ID;
	}

	private static function current_user_legacy_globals_are_logged_out(): bool {
		return 0 === ( $GLOBALS['user_ID'] ?? null )
			&& 0 === ( $GLOBALS['user_level'] ?? null )
			&& null === ( $GLOBALS['userdata'] ?? null )
			&& '' === ( $GLOBALS['user_login'] ?? null )
			&& '' === ( $GLOBALS['user_email'] ?? null )
			&& '' === ( $GLOBALS['user_url'] ?? null )
			&& '' === ( $GLOBALS['user_identity'] ?? null );
	}

	private static function current_user_legacy_globals_expectation( array $row ): array {
		return array(
			'user_ID'       => (int) $row['ID'],
			'user_level'    => 0,
			'user_login'    => $row['user_login'],
			'user_email'    => $row['user_email'],
			'user_url'      => $row['user_url'],
			'user_identity' => $row['display_name'],
			'userdataId'    => (int) $row['ID'],
		);
	}

	private static function current_user_logged_out_globals_expectation(): array {
		return array(
			'user_ID'       => 0,
			'user_level'    => 0,
			'user_login'    => '',
			'user_email'    => '',
			'user_url'      => '',
			'user_identity' => '',
			'userdata'      => null,
		);
	}

	private static function current_user_legacy_globals_observation(): array {
		return array(
			'user_ID'       => $GLOBALS['user_ID'] ?? null,
			'user_level'    => $GLOBALS['user_level'] ?? null,
			'user_login'    => $GLOBALS['user_login'] ?? null,
			'user_email'    => $GLOBALS['user_email'] ?? null,
			'user_url'      => $GLOBALS['user_url'] ?? null,
			'user_identity' => $GLOBALS['user_identity'] ?? null,
			'userdata'      => isset( $GLOBALS['userdata'] ) && $GLOBALS['userdata'] instanceof \WP_User ? self::current_user_observation( $GLOBALS['userdata'] ) : ( $GLOBALS['userdata'] ?? null ),
		);
	}

	private static function current_user_globals_match_snapshot( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( (bool) $entry['exists'] !== $exists ) {
				return false;
			}

			if ( ! $exists ) {
				continue;
			}

			$expected = $entry['value'];
			$actual   = $GLOBALS[ $name ];
			if ( is_object( $expected ) || is_object( $actual ) ) {
				if ( $expected !== $actual ) {
					return false;
				}
				continue;
			}

			if ( ! self::same_value( $expected, $actual ) ) {
				return false;
			}
		}

		return true;
	}

	private static function current_user_globals_expectation( array $snapshot ): array {
		$out = array();
		foreach ( self::current_user_global_names() as $name ) {
			$entry        = $snapshot[ $name ] ?? array( 'exists' => false, 'value' => null );
			$out[ $name ] = array(
				'exists' => (bool) $entry['exists'],
				'value'  => self::summarize_current_user_global_value( $entry['value'] ),
			);
		}

		return $out;
	}

	private static function current_user_globals_observation(): array {
		$out = array();
		foreach ( self::current_user_global_names() as $name ) {
			$out[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => self::summarize_current_user_global_value( $GLOBALS[ $name ] ?? null ),
			);
		}

		return $out;
	}

	private static function summarize_current_user_global_value( $value ) {
		if ( $value instanceof \WP_User ) {
			return array(
				'class'      => \WP_User::class,
				'objectId'   => spl_object_id( $value ),
				'ID'         => $value->ID,
				'user_login' => $value->user_login,
			);
		}

		if ( is_object( $value ) ) {
			return array(
				'class'    => get_class( $value ),
				'objectId' => spl_object_id( $value ),
			);
		}

		return $value;
	}

	private static function wp_user_raw_fields_match( \WP_User $user, array $row, array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( ! $user->has_prop( $field ) ) {
				return false;
			}

			if ( $row[ $field ] !== $user->get( $field ) ) {
				return false;
			}

			if ( $row[ $field ] !== $user->$field ) {
				return false;
			}
		}

		return true;
	}

	private static function wp_user_field_expectation( array $row, array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$out[ $field ] = array(
				'hasProp'  => true,
				'get'      => $row[ $field ],
				'property' => $row[ $field ],
			);
		}

		return $out;
	}

	private static function wp_user_field_observation( \WP_User $user, array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$out[ $field ] = array(
				'hasProp'  => $user->has_prop( $field ),
				'get'      => $user->get( $field ),
				'property' => $user->$field,
			);
		}

		return $out;
	}

	private static function wp_user_lookups_match_row( array $lookups, array $row ): bool {
		$fields = array( 'ID', 'user_login', 'user_nicename', 'user_email', 'user_url', 'display_name', 'first_name', 'last_name', 'description' );

		foreach ( $lookups as $lookup ) {
			if ( ! $lookup instanceof \WP_User || ! self::wp_user_raw_fields_match( $lookup, $row, $fields ) ) {
				return false;
			}
		}

		return true;
	}

	private static function wp_user_lookup_expectation( array $row ): array {
		return array(
			'ID'            => $row['ID'],
			'user_login'    => $row['user_login'],
			'user_nicename' => $row['user_nicename'],
			'user_email'    => $row['user_email'],
			'user_url'      => $row['user_url'],
			'display_name'  => $row['display_name'],
		);
	}

	private static function wp_user_lookup_observation( array $lookups ): array {
		$out = array();
		foreach ( $lookups as $key => $lookup ) {
			$out[ $key ] = $lookup instanceof \WP_User ? self::wp_user_lookup_expectation( $lookup->to_array() ) : $lookup;
		}

		return $out;
	}

	private static function user_existence_observations_match( array $observed, string $field ): bool {
		foreach ( $observed as $item ) {
			if ( ! array_key_exists( $field, $item ) || (int) $item['ID'] !== $item[ $field ] ) {
				return false;
			}
		}

		return true;
	}

	private static function user_existence_expected_ids( array $cases ): array {
		$out = array();
		foreach ( $cases as $case ) {
			$out[] = (int) $case['row']['ID'];
		}

		return $out;
	}

	private static function user_existence_caches_are_clean( array $cases ): bool {
		foreach ( self::user_existence_cache_observation( $cases ) as $item ) {
			foreach ( $item as $value ) {
				if ( false !== $value ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function user_existence_cache_observation( array $cases ): array {
		$out = array();
		foreach ( $cases as $case ) {
			$row   = $case['row'];
			$out[] = array(
				'users'      => \wp_cache_get( $row['ID'], 'users' ),
				'userlogins' => \wp_cache_get( $row['user_login'], 'userlogins' ),
				'userslugs'  => \wp_cache_get( $row['user_nicename'], 'userslugs' ),
				'useremail'  => \wp_cache_get( $row['user_email'], 'useremail' ),
			);
		}

		return $out;
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
			$snapshot['wp_filter']        = self::clone_wp_filter_registry( $GLOBALS['wp_filter'] ?? null );
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

	private static function current_user_global_names(): array {
		return array(
			'current_user',
			'userdata',
			'user_login',
			'user_level',
			'user_ID',
			'user_email',
			'user_url',
			'user_identity',
		);
	}

	private static function snapshot_current_user_globals(): array {
		$snapshot = array();
		foreach ( self::current_user_global_names() as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		return $snapshot;
	}

	private static function restore_current_user_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( ! empty( $entry['exists'] ) ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function snapshot_hook_globals(): array {
		return array(
			'wp_filter'         => array(
				'exists' => array_key_exists( 'wp_filter', $GLOBALS ),
				'value'  => self::clone_wp_filter_registry( $GLOBALS['wp_filter'] ?? null ),
			),
			'wp_actions'        => array(
				'exists' => array_key_exists( 'wp_actions', $GLOBALS ),
				'value'  => $GLOBALS['wp_actions'] ?? null,
			),
			'wp_filters'        => array(
				'exists' => array_key_exists( 'wp_filters', $GLOBALS ),
				'value'  => $GLOBALS['wp_filters'] ?? null,
			),
			'wp_current_filter' => array(
				'exists' => array_key_exists( 'wp_current_filter', $GLOBALS ),
				'value'  => $GLOBALS['wp_current_filter'] ?? null,
			),
		);
	}

	private static function restore_hook_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( ! empty( $entry['exists'] ) ) {
				$GLOBALS[ $name ] = 'wp_filter' === $name ? self::clone_wp_filter_registry( $entry['value'] ) : $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function hook_counter_globals_match_snapshot( array $snapshot ): bool {
		foreach ( array( 'wp_actions', 'wp_filters', 'wp_current_filter' ) as $name ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( (bool) $snapshot[ $name ]['exists'] !== $exists ) {
				return false;
			}

			if ( $exists && ! self::same_value( $snapshot[ $name ]['value'], $GLOBALS[ $name ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function hook_globals_expectation( array $snapshot, $set_current_user_hook, $determine_current_user_hook ): array {
		return array(
			'wp_actions'             => self::hook_counter_snapshot_item( $snapshot, 'wp_actions' ),
			'wp_filters'             => self::hook_counter_snapshot_item( $snapshot, 'wp_filters' ),
			'wp_current_filter'      => self::hook_counter_snapshot_item( $snapshot, 'wp_current_filter' ),
			'setCurrentUserHook'     => $set_current_user_hook,
			'determineCurrentUserHook' => $determine_current_user_hook,
		);
	}

	private static function hook_globals_observation( callable $set_current_user_hook, callable $determine_current_user_hook ): array {
		return array(
			'wp_actions'             => self::hook_counter_global_observation( 'wp_actions' ),
			'wp_filters'             => self::hook_counter_global_observation( 'wp_filters' ),
			'wp_current_filter'      => self::hook_counter_global_observation( 'wp_current_filter' ),
			'setCurrentUserHook'     => \has_filter( 'set_current_user', $set_current_user_hook ),
			'determineCurrentUserHook' => \has_filter( 'determine_current_user', $determine_current_user_hook ),
		);
	}

	private static function hook_counter_snapshot_item( array $snapshot, string $name ): array {
		return array(
			'exists' => (bool) ( $snapshot[ $name ]['exists'] ?? false ),
			'value'  => $snapshot[ $name ]['value'] ?? null,
		);
	}

	private static function hook_counter_global_observation( string $name ): array {
		return array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => $GLOBALS[ $name ] ?? null,
		);
	}

	private static function clone_wp_filter_registry( $registry ) {
		if ( ! is_array( $registry ) ) {
			return $registry;
		}

		$clone = array();
		foreach ( $registry as $hook_name => $hook ) {
			$clone[ $hook_name ] = is_object( $hook ) ? clone $hook : $hook;
		}

		return $clone;
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
