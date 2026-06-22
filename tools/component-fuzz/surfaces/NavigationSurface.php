<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB navigation menu registration and rendering paths.
 */
final class NavigationSurface {
	public const NAME = 'navigation';

	private const PREVIEW_BYTES = 160;

	/** @var array<int,object> */
	private static array $menus = array();

	/** @var array<int,array<int,object>> */
	private static array $menu_items = array();

	/** @var array<string,int> */
	private static array $locations = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'navigation.bootstrap-apis-available',
					'Required WordPress navigation APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();
			self::install_filters();

			$rows[] = self::check_location_registry( $ctx );
			$rows[] = self::check_menu_lookup_and_item_setup( $ctx );
			$rows[] = self::check_context_classes( $ctx );
			$rows[] = self::check_tree_walker_output( $ctx );
			$rows[] = self::check_wp_nav_menu_rendering( $ctx );
			$rows[] = self::check_depth_class_contracts( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'navigation.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::remove_filters();
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	public static function filter_nav_menu_locations( $locations ) {
		unset( $locations );
		return self::$locations;
	}

	public static function filter_nav_menu_object( $menu_obj, $menu ) {
		if ( is_object( $menu ) && isset( $menu->taxonomy ) && 'nav_menu' === $menu->taxonomy ) {
			return $menu;
		}

		foreach ( self::$menus as $candidate ) {
			if (
				(int) $candidate->term_id === (int) $menu
				|| (string) $candidate->slug === (string) $menu
				|| (string) $candidate->name === (string) $menu
			) {
				return clone $candidate;
			}
		}

		return $menu_obj;
	}

	public static function filter_nav_menu_items( $items, $menu, $args ): array {
		unset( $items, $args );
		$term_id = is_object( $menu ) && isset( $menu->term_id ) ? (int) $menu->term_id : 0;

		return self::clone_items( self::$menu_items[ $term_id ] ?? array() );
	}

	public static function filter_setup_nav_menu_item( $pre_menu_item, $menu_item ) {
		if ( isset( $menu_item->component_fuzz_prepared ) && $menu_item->component_fuzz_prepared ) {
			$prepared                    = clone $menu_item;
			$prepared->db_id            = (int) $menu_item->ID;
			$prepared->menu_item_parent = 0;
			$prepared->object_id        = (int) $menu_item->ID;
			$prepared->object           = 'custom';
			$prepared->type             = 'custom';
			$prepared->type_label       = 'Custom Link';
			$prepared->url              = $menu_item->url ?? 'https://example.test/prepared';
			$prepared->title            = $menu_item->post_title ?? '';
			$prepared->target           = '';
			$prepared->attr_title       = '';
			$prepared->description      = '';
			$prepared->classes          = array();
			$prepared->xfn              = '';
			return $prepared;
		}

		return $pre_menu_item;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'Walker', 'Walker_Nav_Menu', 'WP_Query', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_menu_item_classes_by_context',
				'add_filter',
				'get_nav_menu_locations',
				'get_registered_nav_menus',
				'has_nav_menu',
				'is_nav_menu',
				'register_nav_menu',
				'register_nav_menus',
				'register_taxonomy',
				'remove_filter',
				'taxonomy_exists',
				'unregister_nav_menu',
				'walk_nav_menu_tree',
				'wp_get_nav_menu_name',
				'wp_get_nav_menu_object',
				'wp_nav_menu',
				'wp_nav_menu_remove_menu_item_has_children_class',
				'wp_setup_nav_menu_item',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_location_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$menu     = self::menu_object( $ctx->fork( 'registry-menu' ), 31 );
		$primary  = self::location_id( $ctx->fork( 'primary' ) );
		$footer   = self::location_id( $ctx->fork( 'footer' ) );
		$missing  = self::location_id( $ctx->fork( 'missing' ) );

		self::$menus[ $menu->term_id ] = $menu;
		self::$locations               = array(
			$primary => $menu->term_id,
			$footer  => 0,
		);

		\register_nav_menus(
			array(
				$primary => 'Primary ' . $ctx->text( 0, 20 ),
				$footer  => 'Footer ' . $ctx->text( 0, 20 ),
			)
		);
		\register_nav_menu( $primary, 'Primary replacement ' . $ctx->int( 1, 99 ) );

		$registered = \get_registered_nav_menus();
		$locations  = \get_nav_menu_locations();

		self::collect_failure(
			$failures,
			isset( $registered[ $primary ], $registered[ $footer ] )
				&& str_starts_with( (string) $registered[ $primary ], 'Primary replacement ' )
				&& self::$locations === $locations
				&& \has_nav_menu( $primary )
				&& ! \has_nav_menu( $footer )
				&& ! \has_nav_menu( $missing )
				&& $menu->name === \wp_get_nav_menu_name( $primary )
				&& '' === \wp_get_nav_menu_name( $footer ),
			'registered locations, theme assignments, and names are consistent',
			array(
				'registered' => $registered,
				'locations'  => $locations,
				'primary'    => $primary,
				'footer'     => $footer,
				'missing'    => $missing,
				'menu'       => $menu,
			)
		);

		$removed_primary = \unregister_nav_menu( $primary );
		$removed_missing = \unregister_nav_menu( $missing );
		$after_remove    = \get_registered_nav_menus();

		self::collect_failure(
			$failures,
			true === $removed_primary
				&& false === $removed_missing
				&& ! isset( $after_remove[ $primary ] )
				&& isset( $after_remove[ $footer ] ),
			'unregister_nav_menu mutates only the requested registered location',
			array(
				'removedPrimary' => $removed_primary,
				'removedMissing' => $removed_missing,
				'afterRemove'    => $after_remove,
			)
		);

		return self::row(
			$ctx,
			'navigation.locations.registry-and-assignments',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_menu_lookup_and_item_setup( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$menu     = self::menu_object( $ctx->fork( 'lookup-menu' ), 41 );

		self::$menus[ $menu->term_id ] = $menu;

		$by_id      = \wp_get_nav_menu_object( $menu->term_id );
		$by_slug    = \wp_get_nav_menu_object( $menu->slug );
		$by_name    = \wp_get_nav_menu_object( $menu->name );
		$by_object  = \wp_get_nav_menu_object( $menu );
		$unknown    = \wp_get_nav_menu_object( 'missing-' . $menu->slug );
		$is_nav     = \is_nav_menu( $menu->term_id );
		$is_unknown = \is_nav_menu( 'missing-' . $menu->slug );

		self::collect_failure(
			$failures,
			self::same_menu( $menu, $by_id )
				&& self::same_menu( $menu, $by_slug )
				&& self::same_menu( $menu, $by_name )
				&& self::same_menu( $menu, $by_object )
				&& false === $unknown
				&& true === $is_nav
				&& false === $is_unknown,
			'wp_get_nav_menu_object resolves supported identifiers only',
			array(
				'menu'      => $menu,
				'byId'      => $by_id,
				'bySlug'    => $by_slug,
				'byName'    => $by_name,
				'byObject'  => $by_object,
				'unknown'   => $unknown,
				'isNav'     => $is_nav,
				'isUnknown' => $is_unknown,
			)
		);

		$raw = (object) array(
			'ID'                       => $ctx->int( 1000, 9999 ),
			'post_title'               => 'Prepared ' . $ctx->text( 0, 24 ),
			'post_type'                => 'nav_menu_item',
			'url'                      => $ctx->url(),
			'component_fuzz_prepared'  => true,
			'component_fuzz_untouched' => $ctx->text( 0, 12 ),
		);

		$prepared = \wp_setup_nav_menu_item( $raw );
		self::collect_failure(
			$failures,
			is_object( $prepared )
				&& (int) $raw->ID === (int) $prepared->db_id
				&& 'custom' === $prepared->type
				&& 'custom' === $prepared->object
				&& $raw->post_title === $prepared->title
				&& $raw->url === $prepared->url
				&& $raw->component_fuzz_untouched === $prepared->component_fuzz_untouched,
			'pre_wp_setup_nav_menu_item can deterministically decorate synthetic items',
			array(
				'raw'      => $raw,
				'prepared' => $prepared,
			)
		);

		return self::row(
			$ctx,
			'navigation.lookup.menu-object-and-item-setup',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_context_classes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$items    = self::menu_items( $ctx->fork( 'context-items' ), 'https://example.test/current-path' );

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/current-path';

		\_wp_menu_item_classes_by_context( $items );

		$current = $items[0];
		$child   = $items[1];

		self::collect_failure(
			$failures,
			true === $current->current
				&& in_array( 'current-menu-item', $current->classes, true )
				&& in_array( 'menu-item-type-custom', $current->classes, true )
				&& in_array( 'menu-item-object-custom', $current->classes, true )
				&& false === $child->current
				&& ! in_array( 'current-menu-item', $child->classes, true ),
			'custom URL context marks only the current matching item',
			array(
				'current' => $current,
				'child'   => $child,
				'server'  => array(
					'HTTP_HOST'   => $_SERVER['HTTP_HOST'],
					'REQUEST_URI' => $_SERVER['REQUEST_URI'],
				),
			)
		);

		return self::row(
			$ctx,
			'navigation.context.current-menu-classes',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_tree_walker_output( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$items    = self::menu_items( $ctx->fork( 'walker-items' ), 'javascript:alert(1)' );
		$args     = (object) array(
			'before'       => '<span class="before">',
			'after'        => '</span>',
			'link_before'  => '<em>',
			'link_after'   => '</em>',
			'item_spacing' => $ctx->choice( array( 'preserve', 'discard' ) ),
			'depth'        => 0,
			'walker'       => '',
		);

		$output_all   = \walk_nav_menu_tree( self::clone_items( $items ), 0, $args );
		$output_depth = \walk_nav_menu_tree( self::clone_items( $items ), 1, $args );

		self::collect_failure(
			$failures,
			is_string( $output_all )
				&& str_contains( $output_all, '<li' )
				&& str_contains( $output_all, '<a' )
				&& str_contains( $output_all, '<ul class="sub-menu">' )
				&& str_contains( $output_all, '<em>' )
				&& ! str_contains( strtolower( $output_all ), 'javascript:' )
				&& substr_count( $output_all, '<li' ) >= 2,
			'Walker_Nav_Menu renders nested items and sanitizes unsafe href protocols',
			array(
				'args'   => $args,
				'output' => self::describe_string( $output_all ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $output_depth )
				&& 1 === substr_count( $output_depth, '<li' )
				&& ! str_contains( $output_depth, '<ul class="sub-menu">' ),
			'walk_nav_menu_tree depth=1 omits child branches',
			array(
				'outputDepthOne' => self::describe_string( $output_depth ),
			)
		);

		return self::row(
			$ctx,
			'navigation.walker.output-shape-and-depth',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_wp_nav_menu_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$menu     = self::menu_object( $ctx->fork( 'render-menu' ), 51 );
		$items    = self::menu_items( $ctx->fork( 'render-items' ), 'https://example.test/current-render' );
		$menu_id  = 'cfz-menu-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );

		self::$menus[ $menu->term_id ]      = $menu;
		self::$menu_items[ $menu->term_id ] = $items;

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/current-render';

		if ( ! \taxonomy_exists( 'nav_menu' ) ) {
			\register_taxonomy( 'nav_menu', 'nav_menu_item', array( 'public' => false ) );
		}

		$output = \wp_nav_menu(
			array(
				'menu'                 => $menu->term_id,
				'echo'                 => false,
				'fallback_cb'          => false,
				'container'            => 'nav',
				'container_class'      => 'cfz-container ' . $ctx->identifier( 3, 8 ),
				'container_id'         => 'cfz-container-' . $ctx->int( 1, 999 ),
				'container_aria_label' => 'Menu ' . $ctx->text( 0, 24 ),
				'menu_class'           => 'cfz-menu',
				'menu_id'              => $menu_id,
				'item_spacing'         => $ctx->choice( array( 'preserve', 'discard', 'invalid' ) ),
				'depth'                => 0,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $output )
				&& str_starts_with( $output, '<nav ' )
				&& str_ends_with( $output, '</nav>' )
				&& str_contains( $output, 'id="' . $menu_id . '"' )
				&& str_contains( $output, 'class="cfz-menu"' )
				&& str_contains( $output, 'aria-label=' )
				&& str_contains( $output, 'menu-item-has-children' )
				&& str_contains( $output, 'current-menu-item' )
				&& str_contains( $output, 'aria-current="page"' )
				&& substr_count( $output, '<li' ) >= 3
				&& ! str_contains( strtolower( $output ), '<script' ),
			'wp_nav_menu renders filtered no-DB menu items with expected wrapper and classes',
			array(
				'menu'   => $menu,
				'items'  => $items,
				'output' => self::describe_string( $output ),
			)
		);

		return self::row(
			$ctx,
			'navigation.wp-nav-menu.filtered-rendering',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_depth_class_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$classes       = array( 'menu-item', 'menu-item-has-children', 'custom-' . $ctx->identifier( 3, 8 ) );
		$keep_args     = (object) array( 'depth' => 2 );
		$trim_args     = (object) array( 'depth' => 1 );
		$flat_args     = (object) array( 'depth' => -1 );
		$legacy_result = \wp_nav_menu_remove_menu_item_has_children_class( $classes, (object) array( 'ID' => 1 ) );
		$kept          = \wp_nav_menu_remove_menu_item_has_children_class( $classes, (object) array( 'ID' => 1 ), $keep_args, 0 );
		$trimmed       = \wp_nav_menu_remove_menu_item_has_children_class( $classes, (object) array( 'ID' => 1 ), $trim_args, 0 );
		$flat          = \wp_nav_menu_remove_menu_item_has_children_class( $classes, (object) array( 'ID' => 1 ), $flat_args, 0 );

		$ok = $legacy_result === $classes
			&& in_array( 'menu-item-has-children', $kept, true )
			&& ! in_array( 'menu-item-has-children', $trimmed, true )
			&& ! in_array( 'menu-item-has-children', $flat, true )
			&& in_array( $classes[2], $trimmed, true )
			&& in_array( $classes[2], $flat, true );

		return self::row(
			$ctx,
			'navigation.depth.has-children-class-contracts',
			$ok,
			array(
				'classes' => $classes,
				'legacy' => $legacy_result,
				'kept'   => $kept,
				'trimmed' => $trimmed,
				'flat'   => $flat,
			)
		);
	}

	private static function menu_object( \ComponentFuzz\FuzzContext $ctx, int $base_id ): object {
		$name = 'Menu ' . preg_replace( '/\s+/', ' ', trim( $ctx->text( 1, 24 ) ) );
		if ( 'Menu' === trim( $name ) ) {
			$name .= ' ' . $base_id;
		}

		$slug = 'cfz-menu-' . substr( hash( 'crc32b', $name . '|' . $ctx->seed() ), 0, 8 );

		return (object) array(
			'term_id'          => $base_id + $ctx->int( 1, 500 ),
			'term_taxonomy_id' => $base_id + $ctx->int( 501, 999 ),
			'name'             => $name,
			'slug'             => $slug,
			'taxonomy'         => 'nav_menu',
			'count'            => 0,
		);
	}

	/**
	 * @return array<int,object>
	 */
	private static function menu_items( \ComponentFuzz\FuzzContext $ctx, string $current_url ): array {
		$parent_id = 700 + $ctx->int( 1, 200 );
		$child_id  = $parent_id + 1;
		$self_id   = $parent_id + 2;

		return array(
			self::menu_item(
				$parent_id,
				1,
				0,
				'Current ' . $ctx->text( 0, 24 ),
				$current_url,
				array( 'seed-parent' )
			),
			self::menu_item(
				$child_id,
				2,
				$parent_id,
				'Child ' . $ctx->text( 0, 24 ),
				'https://example.test/child-' . $ctx->int( 1, 99 ),
				array( 'seed-child' )
			),
			self::menu_item(
				$self_id,
				3,
				$self_id,
				'Self Parent ' . $ctx->text( 0, 24 ),
				'https://example.test/self-' . $ctx->int( 1, 99 ),
				array( 'seed-self-parent' )
			),
		);
	}

	private static function menu_item( int $id, int $order, int $parent, string $title, string $url, array $classes ): object {
		return (object) array(
			'ID'               => $id,
			'db_id'            => $id,
			'menu_order'       => $order,
			'menu_item_parent' => $parent,
			'object_id'        => $id,
			'object'           => 'custom',
			'type'             => 'custom',
			'type_label'       => 'Custom Link',
			'post_parent'      => 0,
			'post_type'        => 'nav_menu_item',
			'post_title'       => $title,
			'title'            => $title,
			'url'              => $url,
			'target'           => '',
			'attr_title'       => $title . ' title',
			'description'      => '',
			'classes'          => $classes,
			'xfn'              => '',
			'current'          => false,
		);
	}

	private static function location_id( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'cfz-' . strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', $ctx->identifier( 4, 14 ) ) );
	}

	private static function same_menu( object $expected, $actual ): bool {
		return is_object( $actual )
			&& isset( $actual->term_id, $actual->slug, $actual->name, $actual->taxonomy )
			&& (int) $expected->term_id === (int) $actual->term_id
			&& (string) $expected->slug === (string) $actual->slug
			&& (string) $expected->name === (string) $actual->name
			&& 'nav_menu' === $actual->taxonomy;
	}

	/**
	 * @param array<int,object> $items Menu items.
	 * @return array<int,object>
	 */
	private static function clone_items( array $items ): array {
		return array_map(
			static function ( object $item ): object {
				return clone $item;
			},
			$items
		);
	}

	private static function install_filters(): void {
		\add_filter( 'theme_mod_nav_menu_locations', array( self::class, 'filter_nav_menu_locations' ), 10, 1 );
		\add_filter( 'wp_get_nav_menu_object', array( self::class, 'filter_nav_menu_object' ), 10, 2 );
		\add_filter( 'wp_get_nav_menu_items', array( self::class, 'filter_nav_menu_items' ), 10, 3 );
		\add_filter( 'pre_wp_setup_nav_menu_item', array( self::class, 'filter_setup_nav_menu_item' ), 10, 2 );
	}

	private static function remove_filters(): void {
		\remove_filter( 'theme_mod_nav_menu_locations', array( self::class, 'filter_nav_menu_locations' ), 10 );
		\remove_filter( 'wp_get_nav_menu_object', array( self::class, 'filter_nav_menu_object' ), 10 );
		\remove_filter( 'wp_get_nav_menu_items', array( self::class, 'filter_nav_menu_items' ), 10 );
		\remove_filter( 'pre_wp_setup_nav_menu_item', array( self::class, 'filter_setup_nav_menu_item' ), 10 );
	}

	private static function reset_runtime(): void {
		global $_wp_registered_nav_menus, $wp_query, $wp_rewrite;

		self::$menus      = array();
		self::$menu_items = array();
		self::$locations  = array();

		$_wp_registered_nav_menus = array();
		$wp_query                 = new \WP_Query();
		$wp_query->is_singular    = false;
		$wp_query->is_home        = false;
		$wp_query->is_page        = false;
		$wp_query->is_category    = false;
		$wp_query->is_tag         = false;
		$wp_query->is_tax         = false;
		$wp_query->queried_object = null;
		$wp_query->queried_object_id = 0;

		$wp_rewrite        = new \WP_Rewrite();
		$wp_rewrite->index = 'index.php';

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/component-fuzz/';
	}

	private static function snapshot_state(): array {
		return array(
			'_wp_registered_nav_menus' => $GLOBALS['_wp_registered_nav_menus'] ?? null,
			'_wp_theme_features'       => $GLOBALS['_wp_theme_features'] ?? null,
			'wp_taxonomies'            => $GLOBALS['wp_taxonomies'] ?? null,
			'wp_post_types'            => $GLOBALS['wp_post_types'] ?? null,
			'wp_query'                 => $GLOBALS['wp_query'] ?? null,
			'wp_rewrite'               => $GLOBALS['wp_rewrite'] ?? null,
			'server'                   => array(
				'HTTP_HOST'   => $_SERVER['HTTP_HOST'] ?? null,
				'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			),
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( array( '_wp_registered_nav_menus', '_wp_theme_features', 'wp_taxonomies', 'wp_post_types', 'wp_query', 'wp_rewrite' ) as $global ) {
			if ( array_key_exists( $global, $snapshot ) && null !== $snapshot[ $global ] ) {
				$GLOBALS[ $global ] = $snapshot[ $global ];
			} else {
				unset( $GLOBALS[ $global ] );
			}
		}

		foreach ( array( 'HTTP_HOST', 'REQUEST_URI' ) as $server_key ) {
			if ( null === $snapshot['server'][ $server_key ] ) {
				unset( $_SERVER[ $server_key ] );
			} else {
				$_SERVER[ $server_key ] = $snapshot['server'][ $server_key ];
			}
		}

		self::$menus      = array();
		self::$menu_items = array();
		self::$locations  = array();
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function skip( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		return $ctx->skip( $invariant, $reason, $data );
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private static function describe_string( string $value ): array {
		return array(
			'length'  => strlen( $value ),
			'preview' => self::preview( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function preview( string $value ): string {
		if ( strlen( $value ) <= self::PREVIEW_BYTES ) {
			return $value;
		}

		return substr( $value, 0, self::PREVIEW_BYTES ) . '...';
	}
}
