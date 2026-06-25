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

			$rows[] = self::check_role_registry_lifecycle( $ctx );
			$rows[] = self::check_role_registry_mutations( $ctx );
			$rows[] = self::check_role_has_cap_filter( $ctx );
			$rows[] = self::check_user_capability_aggregation( $ctx );
			$rows[] = self::check_user_for_site_contracts( $ctx );
			$rows[] = self::check_user_capability_mutations( $ctx );
			$rows[] = self::check_user_has_cap_filter_contracts( $ctx );
			$rows[] = self::check_meta_cap_mappings( $ctx );
			$rows[] = self::check_primitive_meta_cap_monotonicity( $ctx );
			$rows[] = self::check_capability_key_boundaries( $ctx );
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
				'add_role',
				'add_filter',
				'current_user_can',
				'get_role',
				'has_filter',
				'map_meta_cap',
				'remove_role',
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

	private static function check_role_registry_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$roles          = \wp_roles();
		$failures       = array();
		$original_site  = $roles->get_site_id();
		$original_names = $roles->get_names();
		$original_shape = self::role_registry_shape( $roles );
		$wrapper_role   = 'cfz_wrapper_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 );
		$wrapper_cap    = self::cap_name( $ctx->fork( 'wrapper-cap' ), 'wrapper' );

		self::collect_failure(
			$failures,
			$roles === \wp_roles()
				&& false === $roles->use_db
				&& isset( $original_names['cfz_reader'], $original_names['cfz_author'], $original_names['cfz_editor'] )
				&& \get_role( 'cfz_reader' ) === $roles->get_role( 'cfz_reader' ),
			'wp_roles returns stable in-memory seeded registry',
			array(
				'useDb' => $roles->use_db,
				'names' => $original_names,
				'shape' => $original_shape,
			)
		);

		$empty = $roles->add_role( '', 'Empty Component Fuzz Role', array( $wrapper_cap => true ) );
		self::collect_failure(
			$failures,
			null === $empty && $original_shape === self::role_registry_shape( $roles ),
			'empty role add is a registry no-op',
			array(
				'empty'       => self::describe_value( $empty ),
				'beforeShape' => $original_shape,
				'afterShape'  => self::role_registry_shape( $roles ),
			)
		);

		$wrapper = \add_role( $wrapper_role, 'Component Fuzz Wrapper', array( $wrapper_cap ) );
		$again   = \add_role( $wrapper_role, 'Component Fuzz Wrapper Duplicate', array( 'cfz_shared' => true ) );
		\remove_role( $wrapper_role );
		\remove_role( $wrapper_role );

		self::collect_failure(
			$failures,
			$wrapper instanceof \WP_Role
				&& null === $again
				&& true === $wrapper->has_cap( $wrapper_cap )
				&& ! $roles->is_role( $wrapper_role )
				&& null === \get_role( $wrapper_role ),
			'add_role/remove_role wrappers are idempotent against global registry',
			array(
				'role'        => $wrapper_role,
				'cap'         => $wrapper_cap,
				'wrapper'     => $wrapper instanceof \WP_Role ? $wrapper->capabilities : self::describe_value( $wrapper ),
				'duplicate'   => self::describe_value( $again ),
				'afterRemove' => self::role_registry_shape( $roles ),
			)
		);

		$roles->init_roles();
		$roles->for_site( $original_site + 1000 );
		$alternate_site = $roles->get_site_id();
		$roles->for_site( $original_site );

		self::collect_failure(
			$failures,
			$original_names === $roles->get_names()
				&& $original_site === $roles->get_site_id()
				&& $original_shape['roleCount'] === self::role_registry_shape( $roles )['roleCount']
				&& $original_shape['nameCount'] === self::role_registry_shape( $roles )['nameCount']
				&& $original_site + 1000 === $alternate_site,
			'init_roles and for_site preserve no-DB role maps',
			array(
				'originalSite'  => $original_site,
				'alternateSite' => $alternate_site,
				'names'         => $roles->get_names(),
				'shape'         => self::role_registry_shape( $roles ),
			)
		);

		return self::row(
			$ctx,
			'capabilities.roles.registry-lifecycle-no-db',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 6 ),
				'shape'    => self::role_registry_shape( $roles ),
			)
		);
	}

	private static function check_role_registry_mutations( \ComponentFuzz\FuzzContext $ctx ): array {
		$roles    = \wp_roles();
		$failures = array();

		foreach ( self::role_cases( $ctx->fork( 'registry' ) ) as $index => $case ) {
			$role_name       = $case['role'];
			$assoc_role_name = $case['assocRole'];
			$cap_a           = $case['caps'][0];
			$cap_b           = $case['caps'][1];
			$cap_c           = $case['caps'][2];
			$missing_cap     = $case['missingCap'];

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
				$before_missing_remove = $role->capabilities;
				$role->remove_cap( $missing_cap );
				self::collect_failure(
					$failures,
					$before_missing_remove === $role->capabilities
						&& $before_missing_remove === ( $roles->roles[ $role_name ]['capabilities'] ?? null ),
					"removing absent role cap is idempotent case {$index}",
					array(
						'role'         => $role_name,
						'missingCap'   => $missing_cap,
						'before'       => $before_missing_remove,
						'capabilities' => $role->capabilities,
						'registry'     => $roles->roles[ $role_name ]['capabilities'] ?? null,
					)
				);

				$role->add_cap( $cap_c, false );
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

			$assoc_role      = $roles->add_role( $assoc_role_name, 'Component Fuzz Associative ' . $index, $case['assocCaps'] );
			$assoc_duplicate = $roles->add_role( $assoc_role_name, 'Duplicate Associative', array( $missing_cap ) );
			self::collect_failure(
				$failures,
				$assoc_role instanceof \WP_Role
					&& null === $assoc_duplicate
					&& $case['assocCaps'] === $assoc_role->capabilities
					&& $case['assocCaps'] === ( $roles->roles[ $assoc_role_name ]['capabilities'] ?? null )
					&& true === $assoc_role->has_cap( $cap_a )
					&& false === $assoc_role->has_cap( $cap_b )
					&& $case['assocCaps'][ $cap_c ] === $assoc_role->has_cap( $cap_c ),
				"associative capability role add preserves grants case {$index}",
				array(
					'role'        => $assoc_role_name,
					'caps'        => $case['assocCaps'],
					'roleShape'   => $assoc_role instanceof \WP_Role ? $assoc_role->capabilities : null,
					'registry'    => $roles->roles[ $assoc_role_name ]['capabilities'] ?? null,
					'duplicate'   => self::describe_value( $assoc_duplicate ),
					'capCGranted' => $assoc_role instanceof \WP_Role ? $assoc_role->has_cap( $cap_c ) : null,
				)
			);
			$roles->remove_role( $assoc_role_name );
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
		$other    = $roles->get_role( 'cfz_reader' );
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
			$during_grant       = $role->has_cap( $grant );
			$other_during_grant = $other instanceof \WP_Role ? $other->has_cap( $grant ) : null;
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
					&& false === $other_during_grant
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
						'otherGrant'  => $other_during_grant,
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

		self::collect_failure(
			$failures,
			false === \has_filter( 'role_has_cap', $grant_filter )
				&& false === \has_filter( 'role_has_cap', $deny_filter ),
			'role_has_cap filters are removed from hook stack',
			array(
				'grantFilter' => \has_filter( 'role_has_cap', $grant_filter ),
				'denyFilter'  => \has_filter( 'role_has_cap', $deny_filter ),
			)
		);

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
		$direct_level = 'level_' . $ctx->int( 0, 10 );
		$unknown      = self::cap_name( $ctx->fork( 'unknown' ), 'unknown' );
		$user         = self::synthetic_user( $ctx->fork( 'user' ) );

		$user->caps = array(
			'cfz_editor' => true,
			'cfz_author' => true,
			$direct_grant => true,
			$direct_deny  => false,
			'cfz_shared'  => false,
			$direct_level => true,
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
			'level'           => array( 'cap' => $direct_level, 'expected' => true ),
			'numeric-level'   => array( 'cap' => (int) substr( $direct_level, 6 ), 'expected' => true ),
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

		self::collect_failure(
			$failures,
			array( 'cfz_editor', 'cfz_author' ) === $user->roles
				&& false === $user->allcaps['cfz_shared']
				&& true === $user->allcaps[ $direct_grant ]
				&& false === $user->allcaps[ $direct_deny ],
			'WP_User role list and direct caps preserve overlay order',
			array(
				'user'          => self::describe_user( $user ),
				'directGrant'   => $direct_grant,
				'directDeny'    => $direct_deny,
				'directLevel'   => $direct_level,
				'overlayShared' => $user->allcaps['cfz_shared'] ?? null,
				'allcaps'       => $user->allcaps,
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
				'levelCap'  => $direct_level,
			)
		);
	}

	private static function check_user_for_site_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = array();
		foreach (
			array(
				'current_user_can_for_site',
				'user_can_for_site',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'Component_Fuzz_WPDB_Stub', false ) ) {
			$missing[] = 'class Component_Fuzz_WPDB_Stub';
		}

		if ( array() !== $missing ) {
			return self::skip(
				$ctx,
				'capabilities.user.site-scoped-cap-keys',
				'Site-scoped capability APIs or cache helpers are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$failures           = array();
		$site_a             = 2 + $ctx->int( 0, 97 );
		$site_b             = $site_a + 100 + $ctx->int( 0, 97 );
		$site_a_cap         = self::cap_name( $ctx->fork( 'site-a-cap' ), 'site_a' );
		$site_b_cap         = self::cap_name( $ctx->fork( 'site-b-cap' ), 'site_b' );
		$shared_cap         = self::cap_name( $ctx->fork( 'site-shared-cap' ), 'site_shared' );
		$user_data          = self::synthetic_user_data( $ctx->fork( 'site-user' ) );
		$user_id            = (int) $user_data->ID;
		$original_wpdb      = $GLOBALS['wpdb'] ?? null;
		$had_wpdb           = array_key_exists( 'wpdb', $GLOBALS );
		$original_blog_id   = $GLOBALS['blog_id'] ?? null;
		$had_blog_id        = array_key_exists( 'blog_id', $GLOBALS );
		$original_roles     = \wp_roles();
		$original_role_site = $original_roles->get_site_id();
		$cached_user        = \wp_cache_get( $user_id, 'users' );
		$had_cached_user    = false !== $cached_user;
		$meta_calls         = array();

		$prefix_for = static function ( int $site_id ): string {
			return $site_id <= 1 ? 'wp_' : 'wp_' . $site_id . '_';
		};

		$site_a_key = $prefix_for( $site_a ) . 'capabilities';
		$site_b_key = $prefix_for( $site_b ) . 'capabilities';
		$site_caps  = array(
			$site_a_key => array(
				'cfz_reader' => true,
				$site_a_cap  => true,
				$site_b_cap  => false,
				$shared_cap  => false,
			),
			$site_b_key => array(
				'cfz_author' => true,
				$site_a_cap  => false,
				$site_b_cap  => true,
				$shared_cap  => true,
			),
		);

		$get_metadata_filter = static function ( $value, int $object_id, string $meta_key, bool $single, string $meta_type ) use ( $user_id, $site_caps, &$meta_calls ) {
			$meta_calls[] = array(
				'objectId' => $object_id,
				'metaKey'  => $meta_key,
				'single'   => $single,
				'metaType' => $meta_type,
			);

			if ( 'user' !== $meta_type || $user_id !== $object_id || ! array_key_exists( $meta_key, $site_caps ) ) {
				return $value;
			}

			return array( $site_caps[ $meta_key ] );
		};

		try {
			$GLOBALS['wpdb'] = new class() extends \Component_Fuzz_WPDB_Stub {
				public function get_blog_prefix( $blog_id = null ) {
					if ( null === $blog_id ) {
						$blog_id = $GLOBALS['blog_id'] ?? 1;
					}

					$blog_id = \absint( $blog_id );
					if ( $blog_id <= 1 ) {
						return $this->base_prefix;
					}

					return $this->base_prefix . $blog_id . '_';
				}
			};

			$GLOBALS['blog_id'] = $site_a;
			\wp_cache_set( $user_id, $user_data, 'users' );
			\add_filter( 'get_user_metadata', $get_metadata_filter, 10, 5 );

			$user = new \WP_User( $user_data, '', $site_a );
			$site_a_state = self::site_user_state( $user, $site_a_cap, $site_b_cap, $shared_cap );

			$user->for_site( $site_b );
			$site_b_state = self::site_user_state( $user, $site_a_cap, $site_b_cap, $shared_cap );

			$GLOBALS['blog_id'] = $site_b;
			$default_site_user  = new \WP_User( $user_data );
			$default_state      = self::site_user_state( $default_site_user, $site_a_cap, $site_b_cap, $shared_cap );

			$GLOBALS['blog_id'] = $site_a;
			$current_user       = new \WP_User( $user_data, '', $site_a );
			$GLOBALS['current_user'] = $current_user;

			$user_can_default       = \user_can( $user_id, $site_a_cap );
			$user_can_for_site_id   = \user_can_for_site( $user_id, $site_b, $site_a_cap );
			$user_can_for_site_obj  = \user_can_for_site( $current_user, $site_b, $site_a_cap );
			$user_can_invalid_sites = array(
				'zero'     => \user_can_for_site( $user_id, 0, $site_a_cap ),
				'negative' => \user_can_for_site( $user_id, -1 * $site_b, $site_a_cap ),
				'string'   => \user_can_for_site( $user_id, 'not-a-site', $site_a_cap ),
			);
			$current_can_default    = \current_user_can( $site_a_cap );
			$current_can_for_site   = \current_user_can_for_site( $site_b, $site_a_cap );
			$blog_id_after_wrappers = $GLOBALS['blog_id'];
			$current_after_wrappers = $GLOBALS['current_user'];

			$original_roles->for_site( $site_a );
			$site_a_role_shape = self::role_registry_shape( $original_roles );
			$original_roles->for_site( $site_b );
			$site_b_role_shape = self::role_registry_shape( $original_roles );

			self::collect_failure(
				$failures,
				$site_a === $site_a_state['siteId']
					&& $site_a_key === $site_a_state['capKey']
					&& array( 'cfz_reader' ) === $site_a_state['roles']
					&& true === $site_a_state['hasSiteA']
					&& false === $site_a_state['hasSiteB']
					&& false === $site_a_state['hasShared']
					&& false === $site_a_state['canPublish'],
				'WP_User::for_site loads generated site A cap key and direct overrides',
				array(
					'site'  => $site_a,
					'key'   => $site_a_key,
					'state' => $site_a_state,
				)
			);

			self::collect_failure(
				$failures,
				$site_b === $site_b_state['siteId']
					&& $site_b_key === $site_b_state['capKey']
					&& array( 'cfz_author' ) === $site_b_state['roles']
					&& false === $site_b_state['hasSiteA']
					&& true === $site_b_state['hasSiteB']
					&& true === $site_b_state['hasShared']
					&& true === $site_b_state['canPublish'],
				'WP_User::for_site loads generated site B cap key and role grants',
				array(
					'site'  => $site_b,
					'key'   => $site_b_key,
					'state' => $site_b_state,
				)
			);

			self::collect_failure(
				$failures,
				$site_b === $default_state['siteId']
					&& $site_b_key === $default_state['capKey']
					&& true === $default_state['hasSiteB']
					&& false === $default_state['hasSiteA'],
				'WP_User defaults to current blog id when for_site receives no site',
				array(
					'blogId' => $GLOBALS['blog_id'],
					'state'  => $default_state,
				)
			);

			self::collect_failure(
				$failures,
				true === $user_can_default
					&& $user_can_default === $user_can_for_site_id
					&& $user_can_default === $user_can_for_site_obj
					&& array( 'zero' => false, 'negative' => false, 'string' => false ) === $user_can_invalid_sites
					&& true === $current_can_default
					&& $current_can_default === $current_can_for_site
					&& $site_a === $blog_id_after_wrappers
					&& $current_after_wrappers === $current_user,
				'user_can_for_site validates site IDs and single-site wrappers preserve current state',
				array(
					'userCanDefault'       => $user_can_default,
					'userCanForSiteId'     => $user_can_for_site_id,
					'userCanForSiteObject' => $user_can_for_site_obj,
					'userCanInvalidSites'  => $user_can_invalid_sites,
					'currentCanDefault'    => $current_can_default,
					'currentCanForSite'    => $current_can_for_site,
					'blogIdAfterWrappers'  => $blog_id_after_wrappers,
					'currentSameObject'    => $current_after_wrappers === $current_user,
				)
			);

			self::collect_failure(
				$failures,
				$site_a === $site_a_role_shape['siteId']
					&& $site_a_key === str_replace( 'user_roles', 'capabilities', $site_a_role_shape['roleKey'] )
					&& $site_b === $site_b_role_shape['siteId']
					&& $site_b_key === str_replace( 'user_roles', 'capabilities', $site_b_role_shape['roleKey'] )
					&& $site_a_role_shape['roleCount'] === $site_b_role_shape['roleCount'],
				'WP_Roles::for_site uses generated blog prefixes without changing no-DB role maps',
				array(
					'siteA' => $site_a_role_shape,
					'siteB' => $site_b_role_shape,
				)
			);
		} finally {
			\remove_filter( 'get_user_metadata', $get_metadata_filter, 10 );

			if ( $had_cached_user ) {
				\wp_cache_set( $user_id, $cached_user, 'users' );
			} else {
				\wp_cache_delete( $user_id, 'users' );
			}

			if ( $had_wpdb ) {
				$GLOBALS['wpdb'] = $original_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}

			if ( $had_blog_id ) {
				$GLOBALS['blog_id'] = $original_blog_id;
			} else {
				unset( $GLOBALS['blog_id'] );
			}

			if ( isset( $GLOBALS['wp_roles'] ) && $GLOBALS['wp_roles'] instanceof \WP_Roles ) {
				$GLOBALS['wp_roles']->for_site( $original_role_site );
			}
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'get_user_metadata', $get_metadata_filter ),
			'get_user_metadata filter is removed from hook stack',
			array( 'filter' => \has_filter( 'get_user_metadata', $get_metadata_filter ) )
		);

		return self::row(
			$ctx,
			'capabilities.user.site-scoped-cap-keys',
			array() === $failures,
			array(
				'userId'    => $user_id,
				'sites'     => array( $site_a, $site_b ),
				'keys'      => array( $site_a_key, $site_b_key ),
				'caps'      => array( $site_a_cap, $site_b_cap, $shared_cap ),
				'metaCalls' => array_slice( $meta_calls, 0, 12 ),
				'failures'  => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_user_capability_mutations( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user     = self::synthetic_user( $ctx->fork( 'mutation-user' ) );
		$grant    = self::cap_name( $ctx->fork( 'mutation-grant' ), 'mutation_grant' );
		$deny     = self::cap_name( $ctx->fork( 'mutation-deny' ), 'mutation_deny' );

		$user->caps = array(
			'cfz_reader' => true,
			$deny        => false,
		);
		$user->get_role_caps();

		try {
			$user->add_cap( $grant, true );
			$after_add_cap = array(
				'hasGrant'  => $user->has_cap( $grant ),
				'inCaps'    => $user->caps[ $grant ] ?? null,
				'inAllcaps' => $user->allcaps[ $grant ] ?? null,
			);

			$user->add_cap( $deny, true );
			$after_overwrite = array(
				'hasDeny' => $user->has_cap( $deny ),
				'inCaps'  => $user->caps[ $deny ] ?? null,
			);

			$user->remove_cap( $grant );
			$user->remove_cap( $grant );
			$after_remove_cap = array(
				'hasGrant'  => $user->has_cap( $grant ),
				'inCaps'    => array_key_exists( $grant, $user->caps ),
				'inAllcaps' => array_key_exists( $grant, $user->allcaps ),
			);

			$user->add_role( 'cfz_author' );
			$user->add_role( 'cfz_author' );
			$after_add_role = array(
				'roles'        => $user->roles,
				'authorCount'  => count( array_keys( $user->roles, 'cfz_author', true ) ),
				'canPublish'   => $user->has_cap( 'cfz_publish' ),
				'readerInCaps' => $user->caps['cfz_reader'] ?? null,
				'authorInCaps' => $user->caps['cfz_author'] ?? null,
			);

			$user->remove_role( 'cfz_reader' );
			$user->remove_role( 'cfz_reader' );
			$after_remove_role = array(
				'roles'        => $user->roles,
				'canPublish'   => $user->has_cap( 'cfz_publish' ),
				'readerInCaps' => array_key_exists( 'cfz_reader', $user->caps ),
			);

			$user->set_role( 'cfz_editor' );
			$after_set_role = array(
				'roles'       => $user->roles,
				'canEdit'     => $user->has_cap( 'cfz_edit' ),
				'canPublish'  => $user->has_cap( 'cfz_publish' ),
				'directGrant' => $user->has_cap( $deny ),
			);

			$user->set_role( '' );
			$after_clear_role = array(
				'roles'       => $user->roles,
				'canEdit'     => $user->has_cap( 'cfz_edit' ),
				'directGrant' => $user->has_cap( $deny ),
			);

			$user->remove_all_caps();
			$after_remove_all = array(
				'caps'       => $user->caps,
				'roles'      => $user->roles,
				'allcaps'    => $user->allcaps,
				'exists'     => $user->has_cap( 'exist' ),
				'directDeny' => $user->has_cap( $deny ),
			);

			self::collect_failure(
				$failures,
				true === $after_add_cap['hasGrant']
					&& true === $after_add_cap['inCaps']
					&& true === $after_add_cap['inAllcaps']
					&& true === $after_overwrite['hasDeny']
					&& true === $after_overwrite['inCaps']
					&& false === $after_remove_cap['hasGrant']
					&& false === $after_remove_cap['inCaps']
					&& false === $after_remove_cap['inAllcaps'],
				'WP_User direct cap add/remove mutations are synchronized',
				array(
					'grant'          => $grant,
					'deny'           => $deny,
					'afterAddCap'    => $after_add_cap,
					'afterOverwrite' => $after_overwrite,
					'afterRemoveCap' => $after_remove_cap,
				)
			);

			self::collect_failure(
				$failures,
				array( 'cfz_reader', 'cfz_author' ) === $after_add_role['roles']
					&& 1 === $after_add_role['authorCount']
					&& true === $after_add_role['canPublish']
					&& true === $after_add_role['readerInCaps']
					&& true === $after_add_role['authorInCaps']
					&& array( 'cfz_author' ) === $after_remove_role['roles']
					&& true === $after_remove_role['canPublish']
					&& false === $after_remove_role['readerInCaps'],
				'WP_User role add/remove mutations are idempotent',
				array(
					'afterAddRole'    => $after_add_role,
					'afterRemoveRole' => $after_remove_role,
				)
			);

			self::collect_failure(
				$failures,
				array( 'cfz_editor' ) === $after_set_role['roles']
					&& true === $after_set_role['canEdit']
					&& false === $after_set_role['canPublish']
					&& true === $after_set_role['directGrant']
					&& array() === $after_clear_role['roles']
					&& false === $after_clear_role['canEdit']
					&& true === $after_clear_role['directGrant']
					&& array() === $after_remove_all['caps']
					&& array() === $after_remove_all['roles']
					&& array() === $after_remove_all['allcaps']
					&& true === $after_remove_all['exists']
					&& false === $after_remove_all['directDeny'],
				'WP_User set_role and remove_all_caps keep role/direct caps distinct',
				array(
					'afterSetRole'    => $after_set_role,
					'afterClearRole'  => $after_clear_role,
					'afterRemoveAll'  => $after_remove_all,
				)
			);
		} finally {
			$user->remove_all_caps();
		}

		return self::row(
			$ctx,
			'capabilities.user.mutation-idempotence',
			array() === $failures,
			array(
				'user'     => self::describe_user( $user ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_user_has_cap_filter_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$user          = self::synthetic_user( $ctx->fork( 'filter-user' ) );
		$other_user    = self::synthetic_user( $ctx->fork( 'filter-other-user' ) );
		$meta_cap      = self::cap_name( $ctx->fork( 'meta-cap' ), 'meta' );
		$required_a    = self::cap_name( $ctx->fork( 'required-a' ), 'required_a' );
		$required_b    = self::cap_name( $ctx->fork( 'required-b' ), 'required_b' );
		$object_id     = 90000 + $ctx->int( 0, 9999 );
		$context       = 'ctx-' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 );
		$other_context = $context . '-other';
		$map_calls     = array();
		$filter_calls  = array();

		$user->caps = array(
			'cfz_reader' => true,
			$required_a  => true,
			$required_b  => false,
		);
		$user->get_role_caps();
		$other_user->caps = $user->caps;
		$other_user->get_role_caps();

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
			$granted             = $user->has_cap( $meta_cap, $object_id, $context );
			$other_user_granted  = $other_user->has_cap( $meta_cap, $object_id, $context );
			$other_context_grant = $user->has_cap( $meta_cap, $object_id, $other_context );
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
					&& false === $other_user_granted
					&& false === $other_context_grant
					&& false === $after_grant_removed
					&& false === $denied
					&& false === $after_deny_removed
					&& false === $after_map_removed,
				'user_has_cap grants are scoped after generated meta-cap mapping',
				array(
					'states' => array(
						'before'            => $before,
						'granted'           => $granted,
						'otherUserGranted'  => $other_user_granted,
						'otherContextGrant' => $other_context_grant,
						'afterGrantRemoved' => $after_grant_removed,
						'denied'            => $denied,
						'afterDenyRemoved'  => $after_deny_removed,
						'afterMapRemoved'   => $after_map_removed,
					),
				)
			);

			self::collect_failure(
				$failures,
				7 === count( $map_calls )
					&& 4 === count( $filter_calls )
					&& array( $required_a, $required_b ) === ( $filter_calls[0]['caps'] ?? null )
					&& array( $meta_cap, $user->ID, $object_id, $context ) === ( $filter_calls[0]['args'] ?? null )
					&& array( $required_a, $required_b ) === ( $filter_calls[1]['caps'] ?? null )
					&& array( $meta_cap, $other_user->ID, $object_id, $context ) === ( $filter_calls[1]['args'] ?? null )
					&& array( $meta_cap ) === ( $filter_calls[2]['caps'] ?? null )
					&& array( $meta_cap, $user->ID, $object_id, $other_context ) === ( $filter_calls[2]['args'] ?? null )
					&& array( $required_a, $required_b ) === ( $filter_calls[3]['caps'] ?? null )
					&& array( $meta_cap, $user->ID, $object_id, $context ) === ( $filter_calls[3]['args'] ?? null ),
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

		self::collect_failure(
			$failures,
			false === \has_filter( 'user_has_cap', $grant_filter )
				&& false === \has_filter( 'user_has_cap', $deny_filter )
				&& false === \has_filter( 'map_meta_cap', $map_filter ),
			'user_has_cap and map_meta_cap filters are removed from hook stack',
			array(
				'grantFilter' => \has_filter( 'user_has_cap', $grant_filter ),
				'denyFilter'  => \has_filter( 'user_has_cap', $deny_filter ),
				'mapFilter'   => \has_filter( 'map_meta_cap', $map_filter ),
			)
		);

		return self::row(
			$ctx,
			'capabilities.user-has-cap.filter-contracts',
			array() === $failures,
			array(
				'user'         => self::describe_user( $user ),
				'metaCap'      => $meta_cap,
				'requiredCap'  => array( $required_a, $required_b ),
				'objectId'     => $object_id,
				'context'      => $context,
				'otherContext' => $other_context,
				'failures'     => array_slice( $failures, 0, 6 ),
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
			array( 'cap' => 'delete_user', 'user' => $user_id, 'args' => array( $user_id + 1 ), 'expected' => array( 'delete_users' ) ),
			array( 'cap' => 'customize', 'user' => $user_id, 'args' => array(), 'expected' => array( 'edit_theme_options' ) ),
			array( 'cap' => 'setup_network', 'user' => $user_id, 'args' => array(), 'expected' => array( 'manage_options' ) ),
			array( 'cap' => 'update_php', 'user' => $user_id, 'args' => array(), 'expected' => array( 'update_core' ) ),
			array( 'cap' => 'update_https', 'user' => $user_id, 'args' => array(), 'expected' => array( 'manage_options', 'update_core' ) ),
			array( 'cap' => 'manage_privacy_options', 'user' => $user_id, 'args' => array(), 'expected' => array( 'manage_options' ) ),
			array( 'cap' => 'create_app_password', 'user' => $user_id, 'args' => array( $user_id ), 'expected' => array() ),
			array( 'cap' => 'manage_post_tags', 'user' => $user_id, 'args' => array(), 'expected' => array( 'manage_categories' ) ),
			array( 'cap' => 'assign_post_tags', 'user' => $user_id, 'args' => array(), 'expected' => array( 'edit_posts' ) ),
			array( 'cap' => 'edit_blocks', 'user' => $user_id, 'args' => array(), 'expected' => array( 'edit_posts' ) ),
			array( 'cap' => 'publish_blocks', 'user' => $user_id, 'args' => array(), 'expected' => array( 'publish_posts' ) ),
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

	private static function check_primitive_meta_cap_monotonicity( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures  = array();
		$user_base = self::synthetic_user( $ctx->fork( 'monotonic-user' ) );
		$primitive = self::cap_name( $ctx->fork( 'monotonic-primitive' ), 'primitive' );

		$cases = array(
			array( 'cap' => 'add_users', 'args' => array(), 'required' => array( 'promote_users' ) ),
			array( 'cap' => 'customize', 'args' => array(), 'required' => array( 'edit_theme_options' ) ),
			array( 'cap' => 'setup_network', 'args' => array(), 'required' => array( 'manage_options' ) ),
			array( 'cap' => 'update_php', 'args' => array(), 'required' => array( 'update_core' ) ),
			array( 'cap' => 'update_https', 'args' => array(), 'required' => array( 'manage_options', 'update_core' ) ),
			array( 'cap' => 'manage_privacy_options', 'args' => array(), 'required' => array( 'manage_options' ) ),
			array( 'cap' => 'manage_post_tags', 'args' => array(), 'required' => array( 'manage_categories' ) ),
			array( 'cap' => 'assign_post_tags', 'args' => array(), 'required' => array( 'edit_posts' ) ),
			array( 'cap' => 'edit_blocks', 'args' => array(), 'required' => array( 'edit_posts' ) ),
			array( 'cap' => 'publish_blocks', 'args' => array(), 'required' => array( 'publish_posts' ) ),
		);

		foreach ( $cases as $index => $case ) {
			$user       = new \WP_User( $user_base );
			$user->ID  = $user_base->ID + $index + 1;
			$all_grant = array_fill_keys( $case['required'], true );

			$user->caps = $all_grant;
			$user->get_role_caps();
			$allowed = $user->has_cap( $case['cap'], ...$case['args'] );

			$denied_cap                = $case['required'][ $index % count( $case['required'] ) ];
			$user->caps[ $denied_cap ] = false;
			$user->get_role_caps();
			$denied = $user->has_cap( $case['cap'], ...$case['args'] );

			$user->caps[ $denied_cap ] = true;
			$user->caps[ $primitive ]  = false;
			$user->get_role_caps();
			$restored_with_unrelated_deny = $user->has_cap( $case['cap'], ...$case['args'] );

			self::collect_failure(
				$failures,
				true === $allowed
					&& false === $denied
					&& true === $restored_with_unrelated_deny,
				"primitive/meta cap monotonicity case {$index}",
				array(
					'case'           => $case,
					'deniedCap'      => $denied_cap,
					'unrelatedDeny'  => $primitive,
					'allowed'        => $allowed,
					'denied'         => $denied,
					'restored'       => $restored_with_unrelated_deny,
					'finalAllcaps'   => $user->allcaps,
				)
			);
		}

		$primitive_user = new \WP_User( $user_base );
		$primitive_user->ID++;
		$primitive_user->caps = array( $primitive => true );
		$primitive_user->get_role_caps();
		$primitive_allowed = $primitive_user->has_cap( $primitive );
		$primitive_user->caps[ $primitive ] = false;
		$primitive_user->get_role_caps();
		$primitive_denied = $primitive_user->has_cap( $primitive );

		self::collect_failure(
			$failures,
			true === $primitive_allowed && false === $primitive_denied,
			'primitive direct capability grants are monotonic',
			array(
				'primitive' => $primitive,
				'allowed'   => $primitive_allowed,
				'denied'    => $primitive_denied,
				'allcaps'   => $primitive_user->allcaps,
			)
		);

		return self::row(
			$ctx,
			'capabilities.primitive-meta-cap.monotonicity',
			array() === $failures,
			array(
				'cases'     => count( $cases ) + 1,
				'primitive' => $primitive,
				'failures'  => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_capability_key_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$roles     = \wp_roles();
		$failures  = array();
		$role_name = 'cfz_boundary_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 );
		$raw_cases = self::capability_boundary_cases( $ctx->fork( 'boundary-cases' ) );
		$cap_cases = array();
		$role_caps = array();

		foreach ( $raw_cases as $index => $raw ) {
			$sanitized = \sanitize_key( $raw );
			$cap       = self::cap_from_raw( $raw, 'boundary_' . $index, $ctx->fork( 'boundary-cap-' . $index ) );
			$grant     = 1 !== $index % 3;

			$cap_cases[] = array(
				'raw'       => $raw,
				'sanitized' => $sanitized,
				'cap'       => $cap,
				'grant'     => $grant,
			);
			$role_caps[ $cap ] = $grant;

			self::collect_failure(
				$failures,
				is_string( $sanitized )
					&& 1 === preg_match( '/^[a-z0-9_-]*$/', $sanitized )
					&& '' !== $cap
					&& strlen( $cap ) <= 96
					&& 1 === preg_match( '/^cfz_[a-z0-9_-]+$/', $cap ),
				"capability key sanitization boundary case {$index}",
				array(
					'raw'       => $raw,
					'sanitized' => $sanitized,
					'cap'       => $cap,
					'grant'     => $grant,
				)
			);
		}

		$role = $roles->add_role( $role_name, 'Component Fuzz Boundary', $role_caps );
		if ( $role instanceof \WP_Role ) {
			foreach ( $cap_cases as $index => $case ) {
				self::collect_failure(
					$failures,
					$case['grant'] === $role->has_cap( $case['cap'] )
						&& $case['grant'] === ( $roles->roles[ $role_name ]['capabilities'][ $case['cap'] ] ?? null ),
					"boundary role capability grant case {$index}",
					array(
						'case'     => $case,
						'roleCaps' => $role->capabilities,
						'registry' => $roles->roles[ $role_name ]['capabilities'] ?? null,
					)
				);
			}
		} else {
			self::collect_failure(
				$failures,
				false,
				'boundary role could be added',
				array(
					'role' => $role_name,
					'caps' => $role_caps,
				)
			);
		}

		$user       = self::synthetic_user( $ctx->fork( 'boundary-user' ) );
		$user->caps = array_merge( array( 'cfz_reader' => true ), $role_caps );
		$user->get_role_caps();

		foreach ( $cap_cases as $index => $case ) {
			self::collect_failure(
				$failures,
				$case['grant'] === $user->has_cap( $case['cap'] ),
				"boundary user direct capability grant case {$index}",
				array(
					'case'    => $case,
					'user'    => self::describe_user( $user ),
					'allcaps' => $user->allcaps,
				)
			);
		}

		$roles->remove_role( $role_name );
		self::collect_failure(
			$failures,
			! $roles->is_role( $role_name )
				&& null === $roles->get_role( $role_name ),
			'boundary role cleanup removes generated role',
			array(
				'role'  => $role_name,
				'names' => $roles->get_names(),
			)
		);

		return self::row(
			$ctx,
			'capabilities.capability-key.boundaries',
			array() === $failures,
			array(
				'cases'    => count( $cap_cases ),
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
		return new \WP_User( self::synthetic_user_data( $ctx ) );
	}

	private static function synthetic_user_data( \ComponentFuzz\FuzzContext $ctx ): \stdClass {
		$id    = 70000 + $ctx->int( 0, 9999 );
		$login = 'cap_user_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 12 );
		return (object) array(
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
	}

	private static function role_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::GENERATED_CASES; ++$i ) {
			$case        = $ctx->fork( 'case-' . $i );
			$role        = 'cfz_role_' . substr( hash( 'sha1', $case->seed() . '|role|' . $i ), 0, 10 );
			$caps        = array();
			$missing_cap = self::cap_name( $case->fork( 'missing-cap' ), 'missing' );

			foreach ( array( 'a', 'b', 'c' ) as $cap_prefix ) {
				$cap    = self::cap_name( $case->fork( 'cap-' . $cap_prefix ), $cap_prefix );
				$base   = $cap;
				$suffix = 1;
				while ( in_array( $cap, $caps, true ) ) {
					$cap = $base . '_' . $suffix++;
				}
				$caps[] = $cap;
			}

			$missing_base   = $missing_cap;
			$missing_suffix = 1;
			while ( in_array( $missing_cap, $caps, true ) ) {
				$missing_cap = $missing_base . '_missing_' . $missing_suffix++;
			}

			$cases[] = array(
				'role'       => $role,
				'assocRole'  => $role . '_assoc',
				'caps'       => $caps,
				'missingCap' => $missing_cap,
				'assocCaps'  => array(
					$caps[0] => true,
					$caps[1] => false,
					$caps[2] => $case->bool() ? true : false,
				),
			);
		}

		return $cases;
	}

	private static function cap_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$raw = $prefix . '_' . $ctx->text( 1, 32 ) . '_' . hash( 'crc32b', (string) $ctx->seed() );
		return self::cap_from_raw( $raw, $prefix, $ctx );
	}

	private static function cap_from_raw( string $raw, string $prefix, \ComponentFuzz\FuzzContext $ctx ): string {
		$prefix    = \sanitize_key( $prefix );
		$sanitized = \sanitize_key( $raw );

		if ( '' === $prefix ) {
			$prefix = 'cap';
		}

		if ( '' === $sanitized ) {
			$sanitized = 'empty_' . substr( hash( 'sha1', $raw . '|' . $ctx->seed() ), 0, 8 );
		}

		if ( strlen( $sanitized ) > 48 ) {
			$sanitized = substr( $sanitized, 0, 48 );
		}

		return 'cfz_' . $prefix . '_' . $sanitized . '_' . hash( 'crc32b', $raw . '|' . $ctx->seed() );
	}

	private static function capability_boundary_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'',
			'   ',
			"caps\nline\tbreak",
			'UPPER-Mixed_123',
			'cap.with.dot/slash\\backslash',
			str_repeat( 'long-capability-segment_', 8 ),
			$ctx->ascii( 0, 96 ),
			$ctx->text( 0, 96 ),
			$ctx->bytes( 0, 32 ),
			$ctx->identifier( 1, 32 ),
		);
	}

	private static function role_registry_shape( \WP_Roles $roles ): array {
		$role_keys        = array_keys( (array) $roles->roles );
		$role_object_keys = array_keys( (array) $roles->role_objects );
		$role_name_keys   = array_keys( (array) $roles->role_names );
		sort( $role_keys );
		sort( $role_object_keys );
		sort( $role_name_keys );

		return array(
			'useDb'          => $roles->use_db,
			'siteId'         => $roles->get_site_id(),
			'roleKey'        => $roles->role_key,
			'roleCount'      => count( $role_keys ),
			'objectCount'    => count( $role_object_keys ),
			'nameCount'      => count( $role_name_keys ),
			'roles'          => $role_keys,
			'roleObjects'    => $role_object_keys,
			'roleNames'      => $role_name_keys,
		);
	}

	private static function site_user_state( \WP_User $user, string $site_a_cap, string $site_b_cap, string $shared_cap ): array {
		return array(
			'siteId'     => $user->get_site_id(),
			'capKey'     => $user->cap_key,
			'roles'      => $user->roles,
			'caps'       => $user->caps,
			'allcaps'    => $user->allcaps,
			'hasSiteA'   => $user->has_cap( $site_a_cap ),
			'hasSiteB'   => $user->has_cap( $site_b_cap ),
			'hasShared'  => $user->has_cap( $shared_cap ),
			'canRead'    => $user->has_cap( 'read' ),
			'canPublish' => $user->has_cap( 'cfz_publish' ),
		);
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
