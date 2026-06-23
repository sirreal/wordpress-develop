<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WordPress roles, primitive capabilities, and cheap meta-cap mappings.
 */
final class CapabilitiesSurface {
	public const NAME = 'capabilities';

	private const GENERATED_CASES = 8;
	private const PREVIEW_BYTES   = 160;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'capabilities.bootstrap-apis-available',
					'Required WordPress capability APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::install_roles( $ctx );

			$rows[] = self::check_role_registry_mutations( $ctx );
			$rows[] = self::check_role_has_cap_filter( $ctx );
			$rows[] = self::check_user_capability_aggregation( $ctx );
			$rows[] = self::check_user_has_cap_filter_contracts( $ctx );
			$rows[] = self::check_meta_cap_mappings( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'capabilities.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Role', 'WP_Roles', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'current_user_can',
				'map_meta_cap',
				'remove_filter',
				'sanitize_key',
				'user_can',
				'wp_roles',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_role_registry_mutations( \ComponentFuzz\FuzzContext $ctx ): array {
		$roles    = \wp_roles();
		$failures = array();

		foreach ( self::role_cases( $ctx->fork( 'registry' ) ) as $index => $case ) {
			$role_name = $case['role'];
			$cap_a     = $case['caps'][0];
			$cap_b     = $case['caps'][1];
			$cap_c     = $case['caps'][2];

			$role      = $roles->add_role( $role_name, 'Component Fuzz ' . $index, array( $cap_a, $cap_b ) );
			$duplicate = $roles->add_role( $role_name, 'Duplicate', array( $cap_c => true ) );

			self::collect_failure(
				$failures,
				$role instanceof \WP_Role
					&& null === $duplicate
					&& $roles->is_role( $role_name )
					&& $role === $roles->get_role( $role_name )
					&& isset( $roles->get_names()[ $role_name ] )
					&& true === $role->has_cap( $cap_a )
					&& true === $role->has_cap( $cap_b )
					&& false === $role->has_cap( $cap_c ),
				"numeric capability role add case {$index}",
				array(
					'role'      => $role_name,
					'caps'      => $case['caps'],
					'roleShape' => $role instanceof \WP_Role ? $role->capabilities : null,
					'duplicate' => self::describe_value( $duplicate ),
				)
			);

			if ( $role instanceof \WP_Role ) {
				$role->add_cap( $cap_c, false );
				self::collect_failure(
					$failures,
					array_key_exists( $cap_c, $role->capabilities )
						&& false === $role->capabilities[ $cap_c ]
						&& false === $roles->roles[ $role_name ]['capabilities'][ $cap_c ]
						&& false === $role->has_cap( $cap_c ),
					"explicit false role cap is preserved case {$index}",
					array(
						'role'         => $role_name,
						'cap'          => $cap_c,
						'capabilities' => $role->capabilities,
						'registry'     => $roles->roles[ $role_name ]['capabilities'] ?? null,
					)
				);

				$role->add_cap( $cap_c, true );
				$role->remove_cap( $cap_a );
				self::collect_failure(
					$failures,
					true === $role->has_cap( $cap_c )
						&& false === $role->has_cap( $cap_a )
						&& ! array_key_exists( $cap_a, $roles->roles[ $role_name ]['capabilities'] ),
					"role object and registry stay synchronized case {$index}",
					array(
						'role'         => $role_name,
						'addedCap'     => $cap_c,
						'removedCap'   => $cap_a,
						'capabilities' => $role->capabilities,
						'registry'     => $roles->roles[ $role_name ]['capabilities'] ?? null,
					)
				);
			}

			$roles->remove_role( $role_name );
			self::collect_failure(
				$failures,
				! $roles->is_role( $role_name )
					&& null === $roles->get_role( $role_name )
					&& ! isset( $roles->get_names()[ $role_name ] )
					&& ! isset( $roles->roles[ $role_name ] ),
				"remove_role clears role maps case {$index}",
				array(
					'role'  => $role_name,
					'names' => $roles->get_names(),
				)
			);
		}

		return self::row(
			$ctx,
			'capabilities.roles.registry-mutation-synchronization',
			array() === $failures,
			array(
				'cases'    => self::GENERATED_CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_role_has_cap_filter( \ComponentFuzz\FuzzContext $ctx ): array {
		$roles    = \wp_roles();
		$failures = array();
		$role     = $roles->get_role( 'cfz_author' );
		$grant    = self::cap_name( $ctx->fork( 'filter-grant' ), 'grant' );
		$deny     = 'cfz_publish';

		if ( ! $role instanceof \WP_Role ) {
			return self::row(
				$ctx,
				'capabilities.roles.role_has_cap-filter-locality',
				false,
				array( 'message' => 'Seed role cfz_author was not installed.' )
			);
		}

		$grant_filter = static function ( array $capabilities, string $cap, string $name ) use ( $grant ): array {
			if ( 'cfz_author' === $name && $grant === $cap ) {
				$capabilities[ $cap ] = true;
			}

			return $capabilities;
		};
		$deny_filter  = static function ( array $capabilities, string $cap, string $name ) use ( $deny ): array {
			if ( 'cfz_author' === $name && $deny === $cap ) {
				$capabilities[ $cap ] = false;
			}

			return $capabilities;
		};

		try {
			$before_grant = $role->has_cap( $grant );
			$before_deny  = $role->has_cap( $deny );

			\add_filter( 'role_has_cap', $grant_filter, 10, 3 );
			$during_grant = $role->has_cap( $grant );
			\remove_filter( 'role_has_cap', $grant_filter, 10 );
			$after_grant = $role->has_cap( $grant );

			\add_filter( 'role_has_cap', $deny_filter, 10, 3 );
			$during_deny = $role->has_cap( $deny );
			\remove_filter( 'role_has_cap', $deny_filter, 10 );
			$after_deny = $role->has_cap( $deny );

			self::collect_failure(
				$failures,
				false === $before_grant
					&& true === $during_grant
					&& false === $after_grant
					&& true === $before_deny
					&& false === $during_deny
					&& true === $after_deny,
				'role_has_cap filters are scoped and reversible',
				array(
					'grantCap' => $grant,
					'denyCap'  => $deny,
					'states'   => array(
						'beforeGrant' => $before_grant,
						'duringGrant' => $during_grant,
						'afterGrant'  => $after_grant,
						'beforeDeny'  => $before_deny,
						'duringDeny'  => $during_deny,
						'afterDeny'   => $after_deny,
					),
				)
			);
		} finally {
			\remove_filter( 'role_has_cap', $grant_filter, 10 );
			\remove_filter( 'role_has_cap', $deny_filter, 10 );
		}

		return self::row(
			$ctx,
			'capabilities.roles.role_has_cap-filter-locality',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_user_capability_aggregation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$direct_grant = self::cap_name( $ctx->fork( 'direct-grant' ), 'direct' );
		$direct_deny  = self::cap_name( $ctx->fork( 'direct-deny' ), 'deny' );
		$unknown      = self::cap_name( $ctx->fork( 'unknown' ), 'unknown' );
		$user         = self::synthetic_user( $ctx->fork( 'user' ) );

		$user->caps = array(
			'cfz_editor' => true,
			'cfz_author' => true,
			$direct_grant => true,
			$direct_deny  => false,
			'cfz_shared'  => false,
			'level_7'     => true,
		);
		$user->get_role_caps();
		$GLOBALS['current_user'] = $user;

		$checks = array(
			'role-read'       => array( 'cap' => 'read', 'expected' => true ),
			'role-edit'       => array( 'cap' => 'cfz_edit', 'expected' => true ),
			'role-publish'    => array( 'cap' => 'cfz_publish', 'expected' => true ),
			'direct-grant'    => array( 'cap' => $direct_grant, 'expected' => true ),
			'direct-deny'     => array( 'cap' => $direct_deny, 'expected' => false ),
			'direct-overlays' => array( 'cap' => 'cfz_shared', 'expected' => false ),
			'unknown'         => array( 'cap' => $unknown, 'expected' => false ),
			'exist'           => array( 'cap' => 'exist', 'expected' => true ),
			'do-not-allow'    => array( 'cap' => 'do_not_allow', 'expected' => false ),
			'level'           => array( 'cap' => 'level_7', 'expected' => true ),
		);

		foreach ( $checks as $label => $check ) {
			$has_cap     = $user->has_cap( $check['cap'] );
			$user_can    = \user_can( $user, $check['cap'] );
			$current_can = \current_user_can( $check['cap'] );

			self::collect_failure(
				$failures,
				$check['expected'] === $has_cap
					&& $has_cap === $user_can
					&& $user_can === $current_can,
				"user capability aggregation {$label}",
				array(
					'cap'        => $check['cap'],
					'expected'   => $check['expected'],
					'hasCap'     => $has_cap,
					'userCan'    => $user_can,
					'currentCan' => $current_can,
					'allcaps'    => $user->allcaps,
				)
			);
		}

		self::collect_failure(
			$failures,
			'level_3' === $user->translate_level_to_cap( 3 )
				&& 'level_10' === $user->translate_level_to_cap( '10' ),
			'translate_level_to_cap keeps legacy level shape',
			array(
				'level3'  => $user->translate_level_to_cap( 3 ),
				'level10' => $user->translate_level_to_cap( '10' ),
			)
		);

		return self::row(
			$ctx,
			'capabilities.user.role-direct-cap-aggregation',
			array() === $failures,
			array(
				'user'      => self::describe_user( $user ),
				'failures'  => array_slice( $failures, 0, 6 ),
				'directCap' => $direct_grant,
				'denyCap'   => $direct_deny,
			)
		);
	}

	private static function check_user_has_cap_filter_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$user         = self::synthetic_user( $ctx->fork( 'filter-user' ) );
		$meta_cap     = self::cap_name( $ctx->fork( 'meta-cap' ), 'meta' );
		$required_a   = self::cap_name( $ctx->fork( 'required-a' ), 'required_a' );
		$required_b   = self::cap_name( $ctx->fork( 'required-b' ), 'required_b' );
		$object_id    = 90000 + $ctx->int( 0, 9999 );
		$context      = 'ctx-' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 );
		$map_calls    = array();
		$filter_calls = array();

		$user->caps = array(
			'cfz_reader' => true,
			$required_a  => true,
			$required_b  => false,
		);
		$user->get_role_caps();

		$map_filter = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $meta_cap, $required_a, $required_b, $object_id, $context, &$map_calls ): array {
			$map_calls[] = array(
				'caps'   => $caps,
				'cap'    => $cap,
				'userId' => $user_id,
				'args'   => $args,
			);

			if ( $meta_cap === $cap && array( $object_id, $context ) === $args ) {
				return array( $required_a, $required_b );
			}

			return $caps;
		};

		$grant_filter = static function ( array $allcaps, array $caps, array $args, \WP_User $filtered_user ) use ( $meta_cap, $required_a, $required_b, $object_id, $context, $user, &$filter_calls ): array {
			$filter_calls[] = array(
				'mode'   => 'grant',
				'caps'   => $caps,
				'args'   => $args,
				'userId' => $filtered_user->ID,
			);

			if (
				$user->ID === $filtered_user->ID
				&& array( $required_a, $required_b ) === $caps
				&& array( $meta_cap, $user->ID, $object_id, $context ) === $args
			) {
				$allcaps[ $required_b ] = true;
			}

			return $allcaps;
		};

		$deny_filter = static function ( array $allcaps, array $caps, array $args, \WP_User $filtered_user ) use ( $meta_cap, $required_a, $required_b, $object_id, $context, $user, &$filter_calls ): array {
			$filter_calls[] = array(
				'mode'   => 'deny',
				'caps'   => $caps,
				'args'   => $args,
				'userId' => $filtered_user->ID,
			);

			if (
				$user->ID === $filtered_user->ID
				&& array( $required_a, $required_b ) === $caps
				&& array( $meta_cap, $user->ID, $object_id, $context ) === $args
			) {
				$allcaps[ $required_a ] = false;
				$allcaps[ $required_b ] = true;
			}

			return $allcaps;
		};

		try {
			\add_filter( 'map_meta_cap', $map_filter, 10, 4 );

			$before = $user->has_cap( $meta_cap, $object_id, $context );

			\add_filter( 'user_has_cap', $grant_filter, 10, 4 );
			$granted = $user->has_cap( $meta_cap, $object_id, $context );
			\remove_filter( 'user_has_cap', $grant_filter, 10 );

			$after_grant_removed = $user->has_cap( $meta_cap, $object_id, $context );

			\add_filter( 'user_has_cap', $deny_filter, 10, 4 );
			$denied = $user->has_cap( $meta_cap, $object_id, $context );
			\remove_filter( 'user_has_cap', $deny_filter, 10 );

			$after_deny_removed = $user->has_cap( $meta_cap, $object_id, $context );

			\remove_filter( 'map_meta_cap', $map_filter, 10 );
			$after_map_removed = $user->has_cap( $meta_cap, $object_id, $context );

			self::collect_failure(
				$failures,
				false === $before
					&& true === $granted
					&& false === $after_grant_removed
					&& false === $denied
					&& false === $after_deny_removed
					&& false === $after_map_removed,
				'user_has_cap grants are scoped after generated meta-cap mapping',
				array(
					'states' => array(
						'before'            => $before,
						'granted'           => $granted,
						'afterGrantRemoved' => $after_grant_removed,
						'denied'            => $denied,
						'afterDenyRemoved'  => $after_deny_removed,
						'afterMapRemoved'   => $after_map_removed,
					),
				)
			);

			self::collect_failure(
				$failures,
				5 === count( $map_calls )
					&& 2 === count( $filter_calls )
					&& array( $required_a, $required_b ) === ( $filter_calls[0]['caps'] ?? null )
					&& array( $meta_cap, $user->ID, $object_id, $context ) === ( $filter_calls[0]['args'] ?? null )
					&& array( $required_a, $required_b ) === ( $filter_calls[1]['caps'] ?? null )
					&& array( $meta_cap, $user->ID, $object_id, $context ) === ( $filter_calls[1]['args'] ?? null ),
				'map_meta_cap and user_has_cap filters receive expected generated context',
				array(
					'mapCallCount'    => count( $map_calls ),
					'filterCallCount' => count( $filter_calls ),
					'mapCalls'        => $map_calls,
					'filterCalls'     => $filter_calls,
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $grant_filter, 10 );
			\remove_filter( 'user_has_cap', $deny_filter, 10 );
			\remove_filter( 'map_meta_cap', $map_filter, 10 );
		}

		return self::row(
			$ctx,
			'capabilities.user-has-cap.filter-contracts',
			array() === $failures,
			array(
				'user'        => self::describe_user( $user ),
				'metaCap'     => $meta_cap,
				'requiredCap' => array( $required_a, $required_b ),
				'objectId'    => $object_id,
				'context'     => $context,
				'failures'    => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_meta_cap_mappings( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = 70000 + $ctx->int( 0, 9999 );
		$custom   = self::cap_name( $ctx->fork( 'primitive' ), 'primitive' );

		$cases = array(
			array( 'cap' => 'add_users', 'user' => $user_id, 'args' => array(), 'expected' => array( 'promote_users' ) ),
			array( 'cap' => 'promote_user', 'user' => $user_id, 'args' => array( $user_id + 1 ), 'expected' => array( 'promote_users' ) ),
			array( 'cap' => 'edit_users', 'user' => $user_id, 'args' => array(), 'expected' => array( 'edit_users' ) ),
			array( 'cap' => 'edit_user', 'user' => 0, 'args' => array( $user_id ), 'expected' => array( 'do_not_allow' ) ),
			array( 'cap' => 'edit_user', 'user' => $user_id, 'args' => array( $user_id ), 'expected' => array() ),
			array( 'cap' => 'remove_user', 'user' => $user_id, 'args' => array( $user_id ), 'expected' => array( 'do_not_allow' ) ),
			array( 'cap' => 'remove_user', 'user' => $user_id, 'args' => array( $user_id + 1 ), 'expected' => array( 'remove_users' ) ),
			array( 'cap' => $custom, 'user' => $user_id, 'args' => array(), 'expected' => array( $custom ) ),
		);

		foreach ( $cases as $index => $case ) {
			$actual = \map_meta_cap( $case['cap'], $case['user'], ...$case['args'] );
			self::collect_failure(
				$failures,
				$case['expected'] === $actual,
				"map_meta_cap simple mapping case {$index}",
				array(
					'case'   => $case,
					'actual' => $actual,
				)
			);
		}

		$user = self::synthetic_user( $ctx->fork( 'meta-user' ) );
		$user->caps = array(
			'cfz_editor' => true,
		);
		$user->get_role_caps();

		self::collect_failure(
			$failures,
			true === $user->has_cap( 'edit_user', $user->ID )
				&& false === $user->has_cap( 'remove_user', $user->ID )
				&& true === $user->has_cap( 'add_users' ),
			'WP_User::has_cap applies cheap meta-cap mappings',
			array(
				'user'       => self::describe_user( $user ),
				'editSelf'   => $user->has_cap( 'edit_user', $user->ID ),
				'removeSelf' => $user->has_cap( 'remove_user', $user->ID ),
				'addUsers'   => $user->has_cap( 'add_users' ),
				'allcaps'    => $user->allcaps,
			)
		);

		return self::row(
			$ctx,
			'capabilities.map-meta-cap.cheap-mappings',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function install_roles( \ComponentFuzz\FuzzContext $ctx ): void {
		unset( $ctx );

		$GLOBALS['wp_user_roles'] = array(
			'cfz_reader' => array(
				'name'         => 'Component Fuzz Reader',
				'capabilities' => array(
					'read'       => true,
					'cfz_shared' => true,
				),
			),
			'cfz_author' => array(
				'name'         => 'Component Fuzz Author',
				'capabilities' => array(
					'read'        => true,
					'cfz_publish' => true,
					'cfz_shared'  => true,
				),
			),
			'cfz_editor' => array(
				'name'         => 'Component Fuzz Editor',
				'capabilities' => array(
					'read'          => true,
					'cfz_edit'      => true,
					'promote_users' => true,
					'edit_users'    => true,
					'cfz_shared'    => true,
				),
			),
		);

		unset( $GLOBALS['wp_roles'] );
		$GLOBALS['wp_roles'] = new \WP_Roles();
	}

	private static function synthetic_user( \ComponentFuzz\FuzzContext $ctx ): \WP_User {
		$id    = 70000 + $ctx->int( 0, 9999 );
		$login = 'cap_user_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 12 );
		$data  = (object) array(
			'ID'              => $id,
			'user_login'      => $login,
			'user_pass'       => 'cap-pass-' . hash( 'sha256', $login . '|' . $id ),
			'user_nicename'   => $login,
			'user_email'      => $login . '@example.test',
			'user_url'        => 'https://example.test/users/' . $login,
			'user_registered' => '2024-01-01 00:00:00',
			'user_activation_key' => '',
			'user_status'     => 0,
			'display_name'    => 'Capability User ' . $id,
		);

		return new \WP_User( $data );
	}

	private static function role_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::GENERATED_CASES; ++$i ) {
			$case = $ctx->fork( 'case-' . $i );
			$role = 'cfz_role_' . substr( hash( 'sha1', $case->seed() . '|role|' . $i ), 0, 10 );
			$caps = array(
				self::cap_name( $case->fork( 'cap-a' ), 'a' ),
				self::cap_name( $case->fork( 'cap-b' ), 'b' ),
				self::cap_name( $case->fork( 'cap-c' ), 'c' ),
			);

			$cases[] = array(
				'role' => $role,
				'caps' => array_values( array_unique( $caps ) ),
			);
		}

		return $cases;
	}

	private static function cap_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$raw = $prefix . '_' . $ctx->text( 1, 32 ) . '_' . hash( 'crc32b', (string) $ctx->seed() );
		$cap = \sanitize_key( $raw );

		if ( '' === $cap ) {
			return 'cfz_' . $prefix . '_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 8 );
		}

		return 'cfz_' . $cap;
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

	private static function describe_user( \WP_User $user ): array {
		return array(
			'ID'        => $user->ID,
			'userLogin' => $user->user_login,
			'roles'     => $user->roles,
			'capCount'  => count( $user->allcaps ),
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

	private static function escape_bytes( string $value, int $limit = self::PREVIEW_BYTES ): string {
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
		$snapshot = array();
		foreach ( array( 'current_user', 'wp_roles', 'wp_user_roles' ) as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}
}
