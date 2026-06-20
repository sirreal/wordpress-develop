<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes post type and post status registries without requiring stored posts.
 */
final class PostTypesSurface {
	public const NAME = 'post-types';

	private const CASES         = 10;
	private const PREVIEW_BYTES = 160;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'post-types.bootstrap-apis-available',
					'Required WordPress post type/status APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_registries();

			$rows[] = self::check_post_type_defaults_supports_and_caps( $ctx );
			$rows[] = self::check_post_type_unregister_cleanup( $ctx );
			$rows[] = self::check_post_status_defaults_and_filters( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'post-types.surface-no-throw',
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

		foreach ( array( 'WP_Error', 'WP_Post_Type' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'get_all_post_type_supports',
				'get_post_stati',
				'get_post_status_object',
				'get_post_type_object',
				'get_post_types',
				'get_post_types_by_support',
				'post_type_exists',
				'post_type_supports',
				'register_post_status',
				'register_post_type',
				'remove_post_type_support',
				'sanitize_key',
				'sanitize_title_with_dashes',
				'unregister_post_type',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_post_type_defaults_supports_and_caps( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::post_type_cases( $ctx->fork( 'types' ) );

		foreach ( $cases as $index => $case ) {
			$post_type = $case['name'];
			$args      = $case['args'];
			$object    = \register_post_type( $post_type, $args );

			if ( ! $object instanceof \WP_Post_Type ) {
				$failures[] = array(
					'label'   => "register_post_type returned failure case {$index}",
					'details' => self::describe_value(
						array(
							'postType' => $post_type,
							'args'     => $args,
							'actual'   => $object,
						)
					),
				);
				continue;
			}

			$expected_public       = (bool) $args['public'];
			$expected_show_ui      = array_key_exists( 'show_ui', $args ) ? (bool) $args['show_ui'] : $expected_public;
			$expected_show_menu    = array_key_exists( 'show_in_menu', $args ) ? $args['show_in_menu'] : $expected_show_ui;
			$expected_show_menu    = $expected_show_ui ? $expected_show_menu : false;
			$expected_archive      = $args['has_archive'] ?? false;
			$expected_query_var    = self::expected_query_var( $post_type, $args['query_var'] ?? true );
			$expected_supports     = self::expected_supports( $args['supports'] ?? array() );
			$actual_supports       = \get_all_post_type_supports( $post_type );
			$expected_singular_cap = is_array( $args['capability_type'] ?? null ) ? $args['capability_type'][0] : ( $args['capability_type'] ?? 'post' );
			$expected_plural_cap   = is_array( $args['capability_type'] ?? null ) ? $args['capability_type'][1] : $expected_singular_cap . 's';

			self::collect_failure(
				$failures,
				\post_type_exists( $post_type )
					&& $object === \get_post_type_object( $post_type )
					&& in_array( $post_type, \get_post_types( array(), 'names' ), true )
					&& $expected_public === $object->public
					&& $expected_public === $object->publicly_queryable
					&& $expected_public === $object->embeddable
					&& $expected_public === $object->show_in_nav_menus
					&& ( ! $expected_public ) === $object->exclude_from_search
					&& $expected_show_ui === $object->show_ui
					&& $expected_show_menu === $object->show_in_menu
					&& (bool) $expected_show_menu === $object->show_in_admin_bar
					&& $expected_archive === $object->has_archive
					&& $expected_query_var === $object->query_var,
				"post type default cascade case {$index}",
				array(
					'postType' => $post_type,
					'args'     => $args,
					'actual'   => self::post_type_summary( $object ),
					'expected' => array(
						'public'              => $expected_public,
						'showUi'              => $expected_show_ui,
						'showInMenu'          => $expected_show_menu,
						'showInAdminBar'      => (bool) $expected_show_menu,
						'excludeFromSearch'   => ! $expected_public,
						'publiclyQueryable'   => $expected_public,
						'embeddable'          => $expected_public,
						'showInNavMenus'      => $expected_public,
						'hasArchive'          => $expected_archive,
						'queryVar'            => $expected_query_var,
					),
				)
			);

			self::collect_failure(
				$failures,
				self::support_maps_match( $expected_supports, $actual_supports )
					&& self::support_queries_match( $post_type, array_keys( $expected_supports ) ),
				"post type support registration case {$index}",
				array(
					'postType' => $post_type,
					'args'     => $args,
					'expected' => $expected_supports,
					'actual'   => $actual_supports,
					'byEditor' => \get_post_types_by_support( 'editor' ),
					'byBoth'   => \get_post_types_by_support( array( 'editor', 'autosave' ), 'and' ),
				)
			);

			self::collect_failure(
				$failures,
				$expected_singular_cap === $object->capability_type
					&& 'edit_' . $expected_singular_cap === $object->cap->edit_post
					&& 'read_' . $expected_singular_cap === $object->cap->read_post
					&& 'delete_' . $expected_singular_cap === $object->cap->delete_post
					&& 'edit_' . $expected_plural_cap === $object->cap->edit_posts
					&& 'delete_' . $expected_plural_cap === $object->cap->delete_posts
					&& true === $object->map_meta_cap,
				"post type capability generation case {$index}",
				array(
					'postType' => $post_type,
					'args'     => $args,
					'actual'   => self::cap_summary( $object->cap ),
					'expected' => array(
						'singular'   => $expected_singular_cap,
						'plural'     => $expected_plural_cap,
						'editPost'   => 'edit_' . $expected_singular_cap,
						'editPosts'  => 'edit_' . $expected_plural_cap,
						'deletePost' => 'delete_' . $expected_singular_cap,
					),
				)
			);
		}

		return self::row(
			$ctx,
			'post-types.register.defaults-supports-capabilities',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_type_unregister_cleanup( \ComponentFuzz\FuzzContext $ctx ): array {
		global $_wp_post_type_features, $post_type_meta_caps, $wp;

		$failures  = array();
		$post_type = self::post_type_name( $ctx->fork( 'cleanup-name' ), 'cleanup' );
		$tax       = self::post_type_name( $ctx->fork( 'cleanup-tax' ), 'tax' );
		$args      = array(
			'public'          => true,
			'rewrite'         => false,
			'query_var'       => 'query-' . $post_type,
			'taxonomies'      => array( $tax ),
			'supports'        => array( 'title', 'editor', 'custom-fields' ),
			'capability_type' => array( 'entry', 'entries' ),
			'map_meta_cap'    => true,
		);

		$object = \register_post_type( $post_type, $args );
		$before = array(
			'exists'       => \post_type_exists( $post_type ),
			'supports'     => $_wp_post_type_features[ $post_type ] ?? null,
			'queryVars'    => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
			'metaCapValue' => $post_type_meta_caps[ $object->cap->edit_post ] ?? null,
		);
		$result = \unregister_post_type( $post_type );

		self::collect_failure(
			$failures,
			$object instanceof \WP_Post_Type
				&& true === $before['exists']
				&& is_array( $before['supports'] )
				&& true === $result
				&& ! \post_type_exists( $post_type )
				&& null === \get_post_type_object( $post_type )
				&& ! isset( $_wp_post_type_features[ $post_type ] )
				&& ! in_array( $post_type, \get_post_types_by_support( 'editor' ), true )
				&& ! isset( $post_type_meta_caps[ $object->cap->edit_post ] ),
			'unregister_post_type removes registry, supports, and meta-cap entries',
			array(
				'postType' => $post_type,
				'before'   => $before,
				'result'   => $result,
				'after'    => array(
					'exists'   => \post_type_exists( $post_type ),
					'supports' => $_wp_post_type_features[ $post_type ] ?? null,
					'metaCap'  => $post_type_meta_caps[ $object instanceof \WP_Post_Type ? $object->cap->edit_post : '' ] ?? null,
				),
			)
		);

		$missing = \unregister_post_type( $post_type );
		self::collect_failure(
			$failures,
			$missing instanceof \WP_Error && 'invalid_post_type' === $missing->get_error_code(),
			'unregister_post_type reports invalid post type after cleanup',
			array(
				'postType' => $post_type,
				'missing'  => self::describe_value( $missing ),
			)
		);

		return self::row(
			$ctx,
			'post-types.unregister.cleans-registries',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_post_status_defaults_and_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::status_cases( $ctx->fork( 'statuses' ) );

		foreach ( $cases as $index => $case ) {
			$status = \register_post_status( $case['name'], $case['args'] );
			$args   = $case['args'];

			$expected = self::expected_status_props( $case['name'], $args );

			self::collect_failure(
				$failures,
				is_object( $status )
					&& $status === \get_post_status_object( $case['name'] )
					&& $expected['public'] === $status->public
					&& $expected['private'] === $status->private
					&& $expected['protected'] === $status->protected
					&& $expected['internal'] === $status->internal
					&& $expected['publicly_queryable'] === $status->publicly_queryable
					&& $expected['exclude_from_search'] === $status->exclude_from_search
					&& $expected['show_in_admin_all_list'] === $status->show_in_admin_all_list
					&& $expected['show_in_admin_status_list'] === $status->show_in_admin_status_list
					&& $expected['date_floating'] === $status->date_floating
					&& $expected['label'] === $status->label,
				"post status default cascade case {$index}",
				array(
					'status'   => $case['name'],
					'args'     => $args,
					'expected' => $expected,
					'actual'   => self::status_summary( $status ),
				)
			);
		}

		$public_names   = \get_post_stati( array( 'public' => true ), 'names' );
		$internal_names = \get_post_stati( array( 'internal' => true ), 'names' );
		$admin_objects  = \get_post_stati( array( 'show_in_admin_all_list' => true ), 'objects' );
		$or_names       = \get_post_stati( array( 'public' => true, 'private' => true ), 'names', 'or' );
		$and_names      = \get_post_stati( array( 'public' => true, 'private' => true ), 'names', 'and' );

		self::collect_failure(
			$failures,
			self::filter_expectation_matches( $cases, 'public', true, $public_names )
				&& self::filter_expectation_matches( $cases, 'internal', true, $internal_names )
				&& self::filter_expectation_matches( $cases, 'show_in_admin_all_list', true, array_keys( $admin_objects ) )
				&& self::status_or_expectation_matches( $cases, array( 'public' => true, 'private' => true ), $or_names )
				&& self::status_and_expectation_matches( $cases, array( 'public' => true, 'private' => true ), $and_names ),
			'get_post_stati filters names and objects deterministically',
			array(
				'publicNames'   => $public_names,
				'internalNames' => $internal_names,
				'adminObjects'  => array_keys( $admin_objects ),
				'orNames'       => $or_names,
				'andNames'      => $and_names,
				'cases'         => $cases,
			)
		);

		return self::row(
			$ctx,
			'post-types.status.defaults-and-filters',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function post_type_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'name' => self::post_type_name( $ctx->fork( 'public' ), 'pub' ),
				'args' => array(
					'public'          => true,
					'rewrite'         => false,
					'supports'        => array( 'title', 'editor' ),
					'capability_type' => array( 'story', 'stories' ),
					'map_meta_cap'    => true,
				),
			),
			array(
				'name' => self::post_type_name( $ctx->fork( 'private' ), 'priv' ),
				'args' => array(
					'public'          => false,
					'rewrite'         => false,
					'supports'        => false,
					'query_var'       => false,
					'capability_type' => 'note',
					'map_meta_cap'    => true,
				),
			),
			array(
				'name' => self::post_type_name( $ctx->fork( 'menu' ), 'menu' ),
				'args' => array(
					'public'          => true,
					'show_ui'         => true,
					'show_in_menu'    => 'tools.php',
					'rewrite'         => false,
					'has_archive'     => 'archive-' . $ctx->int( 10, 99 ),
					'query_var'       => 'custom query/' . $ctx->int( 1, 9 ),
					'supports'        => array(
						'title',
						array( 'custom-fields', array( 'source' => 'fuzz' ) ),
					),
					'capability_type' => array( 'artifact', 'artifacts' ),
					'map_meta_cap'    => true,
				),
			),
		);

		for ( $i = count( $cases ); $i < self::CASES; ++$i ) {
			$case        = $ctx->fork( 'generated-' . $i );
			$public      = $case->bool();
			$support_set = $case->choice(
				array(
					array( 'title', 'editor', 'thumbnail' ),
					array( 'excerpt', 'comments' ),
					false,
					array( 'author', array( 'revisions', array( 'bounded' => true ) ) ),
				)
			);
			$singular    = self::cap_base( $case->fork( 'singular' ), 'item' );
			$plural      = self::cap_base( $case->fork( 'plural' ), 'items' );

			$cases[] = array(
				'name' => self::post_type_name( $case->fork( 'name' ), 'gen' ),
				'args' => array(
					'public'          => $public,
					'show_ui'         => $case->bool(),
					'rewrite'         => false,
					'query_var'       => $case->bool() ? true : self::cap_base( $case->fork( 'query' ), 'query-var' ),
					'has_archive'     => $case->bool() ? true : false,
					'supports'        => $support_set,
					'capability_type' => array( $singular, $plural ),
					'map_meta_cap'    => true,
				),
			);
		}

		return $cases;
	}

	private static function status_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'name' => self::status_name( $ctx->fork( 'implicit' ), 'implicit' ), 'args' => array() ),
			array( 'name' => self::status_name( $ctx->fork( 'public' ), 'public' ), 'args' => array( 'public' => true ) ),
			array( 'name' => self::status_name( $ctx->fork( 'private' ), 'private' ), 'args' => array( 'private' => true, 'label' => 'Private Fuzz' ) ),
			array( 'name' => self::status_name( $ctx->fork( 'protected' ), 'protected' ), 'args' => array( 'protected' => true, 'show_in_admin_status_list' => false ) ),
			array( 'name' => self::status_name( $ctx->fork( 'query' ), 'query' ), 'args' => array( 'public' => true, 'publicly_queryable' => false, 'exclude_from_search' => true ) ),
		);

		for ( $i = count( $cases ); $i < self::CASES; ++$i ) {
			$case = $ctx->fork( 'status-' . $i );
			$args = array(
				'public'        => $case->choice( array( null, true, false ) ),
				'internal'      => $case->choice( array( null, true, false ) ),
				'protected'     => $case->choice( array( null, true, false ) ),
				'private'       => $case->choice( array( null, true, false ) ),
				'date_floating' => $case->bool(),
			);

			$args = array_filter(
				$args,
				static function ( $value ): bool {
					return null !== $value;
				}
			);

			$cases[] = array(
				'name' => self::status_name( $case->fork( 'name' ), 'gen' ),
				'args' => $args,
			);
		}

		return $cases;
	}

	private static function expected_query_var( string $post_type, $query_var ) {
		if ( false === $query_var ) {
			return false;
		}

		if ( true === $query_var ) {
			return $post_type;
		}

		return \sanitize_title_with_dashes( $query_var );
	}

	private static function expected_supports( $supports ): array {
		if ( false === $supports ) {
			return array();
		}

		if ( empty( $supports ) ) {
			return array(
				'title'   => true,
				'editor'  => true,
				'autosave' => true,
			);
		}

		$expected = array();
		foreach ( $supports as $feature => $args ) {
			if ( is_array( $args ) ) {
				$expected[ $feature ] = array( $args );
			} else {
				$expected[ $args ] = true;
			}
		}

		if ( isset( $expected['editor'] ) && ! isset( $expected['autosave'] ) ) {
			$expected['autosave'] = true;
		}

		return $expected;
	}

	private static function support_maps_match( array $expected, array $actual ): bool {
		foreach ( $expected as $feature => $value ) {
			if ( ! array_key_exists( $feature, $actual ) || $actual[ $feature ] !== $value ) {
				return false;
			}
		}

		foreach ( $actual as $feature => $value ) {
			if ( ! array_key_exists( $feature, $expected ) || $expected[ $feature ] !== $value ) {
				return false;
			}
		}

		return true;
	}

	private static function support_queries_match( string $post_type, array $features ): bool {
		foreach ( $features as $feature ) {
			if ( ! \post_type_supports( $post_type, $feature ) || ! in_array( $post_type, \get_post_types_by_support( $feature ), true ) ) {
				return false;
			}
		}

		if ( in_array( 'editor', $features, true ) && ! in_array( $post_type, \get_post_types_by_support( array( 'editor', 'autosave' ), 'and' ), true ) ) {
			return false;
		}

		return true;
	}

	private static function expected_status_props( string $name, array $args ): array {
		$public    = $args['public'] ?? null;
		$internal  = $args['internal'] ?? null;
		$protected = $args['protected'] ?? null;
		$private   = $args['private'] ?? null;

		if ( null === $public && null === $internal && null === $protected && null === $private ) {
			$internal = true;
		}

		$public    = (bool) ( $public ?? false );
		$private   = (bool) ( $private ?? false );
		$protected = (bool) ( $protected ?? false );
		$internal  = (bool) ( $internal ?? false );

		return array(
			'name'                      => $name,
			'public'                    => $public,
			'private'                   => $private,
			'protected'                 => $protected,
			'internal'                  => $internal,
			'publicly_queryable'        => (bool) ( $args['publicly_queryable'] ?? $public ),
			'exclude_from_search'       => (bool) ( $args['exclude_from_search'] ?? $internal ),
			'show_in_admin_all_list'    => (bool) ( $args['show_in_admin_all_list'] ?? ! $internal ),
			'show_in_admin_status_list' => (bool) ( $args['show_in_admin_status_list'] ?? ! $internal ),
			'date_floating'             => (bool) ( $args['date_floating'] ?? false ),
			'label'                     => false === ( $args['label'] ?? false ) ? $name : $args['label'],
		);
	}

	private static function filter_expectation_matches( array $cases, string $property, bool $value, array $actual ): bool {
		$actual_map = array_fill_keys( $actual, true );
		foreach ( $cases as $case ) {
			$expected = self::expected_status_props( $case['name'], $case['args'] );
			$present  = isset( $actual_map[ $case['name'] ] );
			if ( ( $expected[ $property ] === $value ) !== $present ) {
				return false;
			}
		}

		return true;
	}

	private static function status_or_expectation_matches( array $cases, array $criteria, array $actual ): bool {
		$actual_map = array_fill_keys( $actual, true );
		foreach ( $cases as $case ) {
			$expected_props = self::expected_status_props( $case['name'], $case['args'] );
			$expected_match = false;
			foreach ( $criteria as $property => $value ) {
				if ( $expected_props[ $property ] === $value ) {
					$expected_match = true;
					break;
				}
			}

			if ( $expected_match !== isset( $actual_map[ $case['name'] ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function status_and_expectation_matches( array $cases, array $criteria, array $actual ): bool {
		$actual_map = array_fill_keys( $actual, true );
		foreach ( $cases as $case ) {
			$expected_props = self::expected_status_props( $case['name'], $case['args'] );
			$expected_match = true;
			foreach ( $criteria as $property => $value ) {
				if ( $expected_props[ $property ] !== $value ) {
					$expected_match = false;
					break;
				}
			}

			if ( $expected_match !== isset( $actual_map[ $case['name'] ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function reset_registries(): void {
		global $wp, $wp_rewrite;

		$GLOBALS['wp_post_types']          = array();
		$GLOBALS['wp_post_statuses']       = array();
		$GLOBALS['_wp_post_type_features'] = array();
		$GLOBALS['post_type_meta_caps']    = array();

		if ( class_exists( 'WP' ) ) {
			$wp                    = new \WP();
			$wp->public_query_vars = array();
		} else {
			$wp = null;
		}

		if ( class_exists( 'WP_Rewrite' ) ) {
			$wp_rewrite = new \WP_Rewrite();
		} else {
			$wp_rewrite = (object) array(
				'extra_rules_top' => array(),
				'extra_permastructs' => array(),
				'front'           => '/',
				'root'            => '',
				'feeds'           => array( 'feed', 'rss2' ),
				'pagination_base' => 'page',
			);
		}
	}

	private static function post_type_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$name = \sanitize_key( $prefix . '_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 ) );
		return substr( $name, 0, 20 );
	}

	private static function status_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return self::post_type_name( $ctx, 'st_' . substr( $prefix, 0, 6 ) );
	}

	private static function cap_base( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$base = \sanitize_key( $prefix . '_' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
		return '' === $base ? 'cfz_cap' : $base;
	}

	private static function post_type_summary( \WP_Post_Type $object ): array {
		return array(
			'name'              => $object->name,
			'public'            => $object->public,
			'publiclyQueryable' => $object->publicly_queryable,
			'embeddable'        => $object->embeddable,
			'showUi'            => $object->show_ui,
			'showInMenu'        => $object->show_in_menu,
			'showInAdminBar'    => $object->show_in_admin_bar,
			'showInNavMenus'    => $object->show_in_nav_menus,
			'excludeFromSearch' => $object->exclude_from_search,
			'queryVar'          => $object->query_var,
			'hasArchive'        => $object->has_archive,
			'capabilityType'    => $object->capability_type,
			'mapMetaCap'        => $object->map_meta_cap,
		);
	}

	private static function status_summary( object $status ): array {
		return array(
			'name'                  => $status->name ?? null,
			'label'                 => $status->label ?? null,
			'public'                => $status->public ?? null,
			'private'               => $status->private ?? null,
			'protected'             => $status->protected ?? null,
			'internal'              => $status->internal ?? null,
			'publiclyQueryable'     => $status->publicly_queryable ?? null,
			'excludeFromSearch'     => $status->exclude_from_search ?? null,
			'showInAdminAllList'    => $status->show_in_admin_all_list ?? null,
			'showInAdminStatusList' => $status->show_in_admin_status_list ?? null,
			'dateFloating'          => $status->date_floating ?? null,
		);
	}

	private static function cap_summary( object $cap ): array {
		return array(
			'editPost'    => $cap->edit_post ?? null,
			'readPost'    => $cap->read_post ?? null,
			'deletePost'  => $cap->delete_post ?? null,
			'editPosts'   => $cap->edit_posts ?? null,
			'deletePosts' => $cap->delete_posts ?? null,
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
		foreach (
			array(
				'_wp_post_type_features',
				'post_type_meta_caps',
				'wp',
				'wp_post_statuses',
				'wp_post_types',
				'wp_rewrite',
			) as $name
		) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
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
