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
			$rows[] = self::check_wp_nav_menu_current_tree_classes( $ctx );
			$rows[] = self::check_tree_walker_output( $ctx );
			$rows[] = self::check_wp_nav_menu_rendering( $ctx );
			$rows[] = self::check_wp_nav_menu_short_circuit_and_fallback( $ctx );
			$rows[] = self::check_wp_nav_menu_filter_pipeline( $ctx );
			$rows[] = self::check_wp_nav_menu_args_and_items_wrap( $ctx );
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

	private static function check_wp_nav_menu_current_tree_classes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$menu         = self::menu_object( $ctx->fork( 'current-tree-menu' ), 81 );
		$item_base    = 800000 + ( $ctx->iteration() * 10 ) + $ctx->int( 1, 9 );
		$current_path = '/current-tree-' . $ctx->int( 100, 999 );
		$current_url  = 'https://example.test' . $current_path;
		$items        = array(
			self::menu_item(
				$item_base,
				1,
				0,
				'Tree Root',
				'https://example.test/root-' . $ctx->int( 1, 99 ),
				array( 'seed-root' )
			),
			self::menu_item(
				$item_base + 1,
				2,
				$item_base,
				'Tree Parent',
				'https://example.test/parent-' . $ctx->int( 1, 99 ),
				array( 'seed-parent' )
			),
			self::menu_item(
				$item_base + 2,
				3,
				$item_base + 1,
				'Tree Current',
				$current_url,
				array( 'seed-current' )
			),
			self::menu_item(
				$item_base + 3,
				4,
				$item_base,
				'Tree Sibling',
				'https://example.test/sibling-' . $ctx->int( 1, 99 ),
				array( 'seed-sibling' )
			),
		);
		$parent_meta  = array(
			$item_base + 2 => $item_base + 1,
			$item_base + 1 => $item_base,
			$item_base     => 0,
		);
		$captured     = array();

		self::$menus[ $menu->term_id ]      = $menu;
		self::$menu_items[ $menu->term_id ] = $items;

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = $current_path;

		if ( ! \taxonomy_exists( 'nav_menu' ) ) {
			\register_taxonomy( 'nav_menu', 'nav_menu_item', array( 'public' => false ) );
		}

		$meta_filter = static function ( $value, $object_id, string $meta_key, bool $single, string $meta_type ) use ( $parent_meta ) {
			if ( 'post' !== $meta_type || '_menu_item_menu_item_parent' !== $meta_key || ! $single ) {
				return $value;
			}

			return (string) ( $parent_meta[ (int) $object_id ] ?? 0 );
		};
		$objects_filter = static function ( array $sorted_menu_items, object $args ) use ( &$captured ): array {
			unset( $args );
			foreach ( $sorted_menu_items as $item ) {
				$captured[ (int) $item->ID ] = clone $item;
			}

			return $sorted_menu_items;
		};

		\add_filter( 'get_post_metadata', $meta_filter, 10, 5 );
		\add_filter( 'wp_nav_menu_objects', $objects_filter, 10, 2 );
		try {
			$output = \wp_nav_menu(
				array(
					'menu'         => $menu->term_id,
					'echo'         => false,
					'fallback_cb'  => false,
					'container'    => false,
					'menu_class'   => 'cfz-current-tree',
					'menu_id'      => 'cfz-current-tree-' . substr( hash( 'crc32b', $current_url . (string) $ctx->seed() ), 0, 8 ),
					'item_spacing' => 'discard',
					'depth'        => 0,
				)
			);
		} finally {
			\remove_filter( 'get_post_metadata', $meta_filter, 10 );
			\remove_filter( 'wp_nav_menu_objects', $objects_filter, 10 );
		}

		$root    = $captured[ $item_base ] ?? null;
		$parent  = $captured[ $item_base + 1 ] ?? null;
		$current = $captured[ $item_base + 2 ] ?? null;
		$sibling = $captured[ $item_base + 3 ] ?? null;

		self::collect_failure(
			$failures,
			$current instanceof \stdClass
				&& true === $current->current
				&& in_array( 'current-menu-item', $current->classes, true )
				&& in_array( 'menu-item-type-custom', $current->classes, true )
				&& in_array( 'menu-item-object-custom', $current->classes, true )
				&& $parent instanceof \stdClass
				&& true === $parent->current_item_parent
				&& true === $parent->current_item_ancestor
				&& in_array( 'current-menu-parent', $parent->classes, true )
				&& in_array( 'current-menu-ancestor', $parent->classes, true )
				&& $root instanceof \stdClass
				&& false === $root->current_item_parent
				&& true === $root->current_item_ancestor
				&& in_array( 'current-menu-ancestor', $root->classes, true )
				&& $sibling instanceof \stdClass
				&& false === $sibling->current
				&& false === $sibling->current_item_parent
				&& false === $sibling->current_item_ancestor
				&& ! in_array( 'current-menu-item', $sibling->classes, true )
				&& ! in_array( 'current-menu-parent', $sibling->classes, true )
				&& ! in_array( 'current-menu-ancestor', $sibling->classes, true ),
			'wp_nav_menu propagates current custom-link state through generated parent and ancestor chains',
			array(
				'root'    => $root,
				'parent'  => $parent,
				'current' => $current,
				'sibling' => $sibling,
				'meta'    => $parent_meta,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $output )
				&& 1 === substr_count( $output, 'current-menu-item' )
				&& 1 === substr_count( $output, 'current-menu-parent' )
				&& 2 === substr_count( $output, 'current-menu-ancestor' )
				&& str_contains( $output, 'aria-current="page"' )
				&& false === \has_filter( 'get_post_metadata', $meta_filter )
				&& false === \has_filter( 'wp_nav_menu_objects', $objects_filter ),
			'rendered wp_nav_menu output mirrors captured current tree classes and local filters are removed',
			array(
				'output'       => self::describe_string( is_string( $output ) ? $output : '' ),
				'filterActive' => array(
					'getPostMetadata'  => \has_filter( 'get_post_metadata', $meta_filter ),
					'wpNavMenuObjects' => \has_filter( 'wp_nav_menu_objects', $objects_filter ),
				),
			)
		);

		return self::row(
			$ctx,
			'navigation.wp-nav-menu.current-tree-classes',
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

	private static function check_wp_nav_menu_short_circuit_and_fallback( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$short_output  = '<nav class="cfz-pre">' . esc_html( $ctx->text( 0, 24 ) ) . '</nav>';
		$pre_filter    = static function ( $output, object $args ) use ( $short_output ) {
			return 'cfz-pre' === $args->menu_class ? $short_output : $output;
		};
		$fallback_seen = array();
		$fallback      = static function ( array $args ) use ( &$fallback_seen ): string {
			$fallback_seen[] = $args;
			return '<fallback data-menu="' . esc_attr( (string) ( $args['menu'] ?? '' ) ) . '"></fallback>';
		};

		\add_filter( 'pre_wp_nav_menu', $pre_filter, 10, 2 );
		try {
			$returned = \wp_nav_menu(
				array(
					'menu'       => 'missing-' . $ctx->identifier( 4, 10 ),
					'echo'       => false,
					'menu_class' => 'cfz-pre',
				)
			);
			$captured = self::capture_echo(
				static function () use ( $ctx ): void {
					\wp_nav_menu(
						array(
							'menu'       => 'missing-' . $ctx->identifier( 4, 10 ),
							'echo'       => true,
							'menu_class' => 'cfz-pre',
						)
					);
				}
			);
		} finally {
			\remove_filter( 'pre_wp_nav_menu', $pre_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$short_output === $returned
				&& $short_output === $captured,
			'pre_wp_nav_menu short-circuits both return and echo modes',
			array(
				'returned' => self::describe_string( (string) $returned ),
				'captured' => self::describe_string( $captured ),
			)
		);

		$fallback_result = \wp_nav_menu(
			array(
				'menu'         => 'missing-' . $ctx->identifier( 4, 10 ),
				'echo'         => false,
				'fallback_cb'  => $fallback,
				'item_spacing' => 'invalid',
			)
		);

		self::collect_failure(
			$failures,
			is_string( $fallback_result )
				&& str_starts_with( $fallback_result, '<fallback ' )
				&& 1 === count( $fallback_seen )
				&& 'preserve' === ( $fallback_seen[0]['item_spacing'] ?? null )
				&& false === \has_filter( 'pre_wp_nav_menu', $pre_filter ),
			'missing menu invokes fallback callback with normalized args only after pre-filter cleanup',
			array(
				'fallbackResult' => self::describe_string( (string) $fallback_result ),
				'fallbackSeen'   => $fallback_seen,
			)
		);

		return self::row(
			$ctx,
			'navigation.wp-nav-menu.short-circuit-and-fallback',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_wp_nav_menu_filter_pipeline( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$menu     = self::menu_object( $ctx->fork( 'pipeline-menu' ), 61 );
		$items    = self::menu_items( $ctx->fork( 'pipeline-items' ), 'https://example.test/current-pipeline' );
		$menu_id  = 'cfz-pipeline-' . substr( hash( 'crc32b', $menu->slug . (string) $ctx->seed() ), 0, 8 );
		$seen     = array(
			'orders'       => array(),
			'argDepths'    => array(),
			'classDepths'  => array(),
			'linkDepths'   => array(),
			'subDepths'    => array(),
			'finalFilters' => array(),
		);

		self::$menus[ $menu->term_id ]      = $menu;
		self::$menu_items[ $menu->term_id ] = $items;

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/current-pipeline';

		if ( ! \taxonomy_exists( 'nav_menu' ) ) {
			\register_taxonomy( 'nav_menu', 'nav_menu_item', array( 'public' => false ) );
		}

		$allowed_tags = static function ( array $tags ): array {
			$tags[] = 'section';
			return array_values( array_unique( $tags ) );
		};
		$objects_filter = static function ( array $sorted_menu_items, object $args ) use ( &$seen ): array {
			unset( $args );
			$seen['orders'][] = array_values(
				array_map(
					static fn( object $item ): int => (int) $item->menu_order,
					$sorted_menu_items
				)
			);
			return $sorted_menu_items;
		};
		$item_args_filter = static function ( object $args, object $menu_item, int $depth ) use ( &$seen ): object {
			unset( $menu_item );
			$seen['argDepths'][] = $depth;
			$args->link_before   = '<strong>';
			$args->link_after    = '</strong>';
			return $args;
		};
		$css_filter = static function ( array $classes, object $menu_item, object $args, int $depth ) use ( &$seen ): array {
			unset( $menu_item, $args );
			$seen['classDepths'][] = $depth;
			$classes[]             = 'cfz-depth-' . $depth;
			$classes[]             = 'cfz-class-"escaped"';
			return $classes;
		};
		$id_filter = static function ( string $id, object $menu_item ): string {
			unset( $id );
			return 'cfz-item-' . $menu_item->ID . '-"<>';
		};
		$item_atts_filter = static function ( array $atts, object $menu_item, object $args, int $depth ): array {
			unset( $menu_item, $args );
			$atts['data-cfz-li']    = 'li-"<>-' . $depth;
			$atts['data-cfz-empty'] = '';
			return $atts;
		};
		$title_filter = static function ( string $title, object $menu_item, object $args, int $depth ): string {
			unset( $title, $args );
			return 'Filtered ' . $depth . ' #' . $menu_item->ID;
		};
		$link_atts_filter = static function ( array $atts, object $menu_item, object $args, int $depth ) use ( &$seen ): array {
			unset( $menu_item, $args );
			$seen['linkDepths'][]    = $depth;
			$atts['data-cfz-link']   = 'link-"<>-' . $depth;
			$atts['data-cfz-false']  = false;
			$atts['aria-current']    = $atts['aria-current'] ?? '';
			return $atts;
		};
		$submenu_class_filter = static function ( array $classes, object $args, int $depth ) use ( &$seen ): array {
			unset( $args );
			$seen['subDepths'][] = $depth;
			$classes[]           = 'cfz-submenu-depth-' . $depth;
			return $classes;
		};
		$submenu_atts_filter = static function ( array $atts, object $args, int $depth ): array {
			unset( $args );
			$atts['data-cfz-submenu'] = 'submenu-depth-' . $depth;
			return $atts;
		};
		$start_el_filter = static function ( string $item_output, object $menu_item, int $depth, object $args ): string {
			unset( $menu_item, $args );
			return $item_output . '<!--start-depth-' . $depth . '-->';
		};
		$items_filter = static function ( string $html, object $args ) use ( &$seen ): string {
			unset( $args );
			$seen['finalFilters'][] = 'items';
			return $html . '<!--global-items-->';
		};
		$specific_items_filter = static function ( string $html, object $args ) use ( &$seen ): string {
			unset( $args );
			$seen['finalFilters'][] = 'specific-items';
			return $html . '<!--specific-items-->';
		};
		$menu_filter = static function ( string $html, object $args ) use ( &$seen ): string {
			unset( $args );
			$seen['finalFilters'][] = 'menu';
			return $html . '<!--final-menu-->';
		};

		\add_filter( 'wp_nav_menu_container_allowedtags', $allowed_tags, 10, 1 );
		\add_filter( 'wp_nav_menu_objects', $objects_filter, 10, 2 );
		\add_filter( 'nav_menu_item_args', $item_args_filter, 10, 3 );
		\add_filter( 'nav_menu_css_class', $css_filter, 10, 4 );
		\add_filter( 'nav_menu_item_id', $id_filter, 10, 2 );
		\add_filter( 'nav_menu_item_attributes', $item_atts_filter, 10, 4 );
		\add_filter( 'nav_menu_item_title', $title_filter, 10, 4 );
		\add_filter( 'nav_menu_link_attributes', $link_atts_filter, 10, 4 );
		\add_filter( 'nav_menu_submenu_css_class', $submenu_class_filter, 10, 3 );
		\add_filter( 'nav_menu_submenu_attributes', $submenu_atts_filter, 10, 3 );
		\add_filter( 'walker_nav_menu_start_el', $start_el_filter, 10, 4 );
		\add_filter( 'wp_nav_menu_items', $items_filter, 10, 2 );
		\add_filter( 'wp_nav_menu_' . $menu->slug . '_items', $specific_items_filter, 10, 2 );
		\add_filter( 'wp_nav_menu', $menu_filter, 10, 2 );

		try {
			$output = \wp_nav_menu(
				array(
					'menu'            => $menu->term_id,
					'echo'            => false,
					'fallback_cb'     => false,
					'container'       => 'section',
					'container_class' => 'cfz-container "quoted"',
					'container_id'    => 'cfz-container-"quoted"',
					'menu_class'      => 'cfz-menu "quoted"',
					'menu_id'         => $menu_id,
					'item_spacing'    => 'discard',
					'depth'           => 0,
				)
			);
			$invalid_container_output = \wp_nav_menu(
				array(
					'menu'         => $menu->term_id,
					'echo'         => false,
					'fallback_cb'  => false,
					'container'    => 'article',
					'menu_class'   => 'cfz-invalid-container',
					'item_spacing' => 'discard',
				)
			);
		} finally {
			\remove_filter( 'wp_nav_menu_container_allowedtags', $allowed_tags, 10 );
			\remove_filter( 'wp_nav_menu_objects', $objects_filter, 10 );
			\remove_filter( 'nav_menu_item_args', $item_args_filter, 10 );
			\remove_filter( 'nav_menu_css_class', $css_filter, 10 );
			\remove_filter( 'nav_menu_item_id', $id_filter, 10 );
			\remove_filter( 'nav_menu_item_attributes', $item_atts_filter, 10 );
			\remove_filter( 'nav_menu_item_title', $title_filter, 10 );
			\remove_filter( 'nav_menu_link_attributes', $link_atts_filter, 10 );
			\remove_filter( 'nav_menu_submenu_css_class', $submenu_class_filter, 10 );
			\remove_filter( 'nav_menu_submenu_attributes', $submenu_atts_filter, 10 );
			\remove_filter( 'walker_nav_menu_start_el', $start_el_filter, 10 );
			\remove_filter( 'wp_nav_menu_items', $items_filter, 10 );
			\remove_filter( 'wp_nav_menu_' . $menu->slug . '_items', $specific_items_filter, 10 );
			\remove_filter( 'wp_nav_menu', $menu_filter, 10 );
		}

		self::collect_failure(
			$failures,
			is_string( $output )
				&& str_starts_with( $output, '<section ' )
				&& str_contains( $output, 'id="cfz-container-&quot;quoted&quot;"' )
				&& str_contains( $output, 'class="cfz-container &quot;quoted&quot;"' )
				&& str_contains( $output, 'id="' . $menu_id . '"' )
				&& str_contains( $output, 'class="cfz-menu &quot;quoted&quot;"' )
				&& str_contains( $output, 'cfz-depth-0' )
				&& str_contains( $output, 'cfz-class-&quot;escaped&quot;' )
				&& str_contains( $output, 'id="cfz-item-' )
				&& str_contains( $output, '-&quot;&lt;&gt;"' )
				&& str_contains( $output, 'data-cfz-li="li-&quot;&lt;&gt;-0"' )
				&& str_contains( $output, 'data-cfz-link="link-&quot;&lt;&gt;-0"' )
				&& str_contains( $output, 'data-cfz-submenu="submenu-depth-0"' )
				&& str_contains( $output, 'cfz-submenu-depth-0' )
				&& str_contains( $output, '<strong>Filtered 0 #' )
				&& str_contains( $output, '<!--start-depth-0-->' )
				&& str_contains( $output, '<!--global-items--><!--specific-items-->' )
				&& str_ends_with( $output, '</section><!--final-menu-->' )
				&& ! str_contains( $output, 'data-cfz-empty' )
				&& ! str_contains( $output, 'data-cfz-false' )
				&& array( 'items', 'specific-items', 'menu' ) === array_slice( $seen['finalFilters'], 0, 3 ),
			'wp_nav_menu filter pipeline preserves ordering while escaping injected attributes',
			array(
				'output' => self::describe_string( (string) $output ),
				'seen'   => $seen,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $invalid_container_output )
				&& ! str_starts_with( $invalid_container_output, '<article' )
				&& str_starts_with( $invalid_container_output, '<ul ' ),
			'container allowlist rejects disallowed tags without suppressing menu output',
			array(
				'invalidContainerOutput' => self::describe_string( (string) $invalid_container_output ),
			)
		);

		self::collect_failure(
			$failures,
			array( array( 1, 2, 3 ), array( 1, 2, 3 ) ) === $seen['orders']
				&& in_array( 0, $seen['argDepths'], true )
				&& in_array( 1, $seen['argDepths'], true )
				&& in_array( 0, $seen['classDepths'], true )
				&& in_array( 1, $seen['classDepths'], true )
				&& in_array( 0, $seen['linkDepths'], true )
				&& in_array( 1, $seen['linkDepths'], true )
				&& in_array( 0, $seen['subDepths'], true )
				&& array( 'items', 'specific-items', 'menu', 'items', 'specific-items', 'menu' ) === $seen['finalFilters']
				&& false === \has_filter( 'wp_nav_menu_container_allowedtags', $allowed_tags )
				&& false === \has_filter( 'wp_nav_menu_' . $menu->slug . '_items', $specific_items_filter ),
			'navigation menu filters observe sorted items, depth transitions, and cleanup',
			array( 'seen' => $seen )
		);

		return self::row(
			$ctx,
			'navigation.wp-nav-menu.filter-pipeline-and-escaping',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_wp_nav_menu_args_and_items_wrap( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$menu     = self::menu_object( $ctx->fork( 'args-menu' ), 71 );
		$items    = self::menu_items( $ctx->fork( 'args-items' ), 'https://example.test/args-current' );
		$location = self::location_id( $ctx->fork( 'args-location' ) );
		$menu_id  = 'cfz-args-"<>-' . substr( hash( 'crc32b', $location . (string) $ctx->seed() ), 0, 8 );

		self::$menus[ $menu->term_id ]      = $menu;
		self::$menu_items[ $menu->term_id ] = $items;
		self::$locations                    = array( $location => $menu->term_id );

		\register_nav_menu( $location, 'Args location ' . $ctx->text( 0, 20 ) );
		if ( ! \taxonomy_exists( 'nav_menu' ) ) {
			\register_taxonomy( 'nav_menu', 'nav_menu_item', array( 'public' => false ) );
		}

		$seen        = array();
		$args_filter = static function ( array $args ) use ( &$seen, $location, $menu_id ): array {
			$seen[] = array(
				'themeLocation' => $args['theme_location'] ?? null,
				'itemSpacing'   => $args['item_spacing'] ?? null,
				'menu'          => $args['menu'] ?? null,
				'fallbackCb'    => $args['fallback_cb'] ?? null,
			);

			if ( $location === ( $args['theme_location'] ?? null ) ) {
				$args['container']    = false;
				$args['depth']        = 1;
				$args['items_wrap']   = '<ol id="%1$s" class="%2$s" data-cfz-wrap="args">%3$s</ol>';
				$args['menu_class']   = 'cfz-args "quoted" <bad>';
				$args['menu_id']      = $menu_id;
				$args['fallback_cb']  = false;
				$args['link_before']  = '<span class="arg-link">';
				$args['link_after']   = '</span>';
			}

			return $args;
		};

		\add_filter( 'wp_nav_menu_args', $args_filter, 10, 1 );
		try {
			$output = \wp_nav_menu(
				array(
					'theme_location' => $location,
					'echo'           => false,
					'item_spacing'   => 'invalid',
				)
			);
		} finally {
			\remove_filter( 'wp_nav_menu_args', $args_filter, 10 );
		}

		self::collect_failure(
			$failures,
			is_string( $output )
				&& str_starts_with( $output, '<ol ' )
				&& str_ends_with( $output, '</ol>' )
				&& str_contains( $output, 'id="' . \esc_attr( $menu_id ) . '"' )
				&& str_contains( $output, 'class="' . \esc_attr( 'cfz-args "quoted" <bad>' ) . '"' )
				&& str_contains( $output, 'data-cfz-wrap="args"' )
				&& str_contains( $output, '<span class="arg-link">' )
				&& 2 === substr_count( $output, '<li ' )
				&& ! str_contains( $output, '<ul class="sub-menu">' )
				&& ! str_contains( strtolower( $output ), '<script' )
				&& ! str_contains( strtolower( $output ), 'javascript:' ),
			'wp_nav_menu_args can rewrite wrappers/classes while items_wrap escapes IDs/classes and depth=1 omits child lists',
			array(
				'output' => self::describe_string( is_string( $output ) ? $output : '' ),
				'menuId' => $menu_id,
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $seen )
				&& $location === $seen[0]['themeLocation']
				&& 'preserve' === $seen[0]['itemSpacing']
				&& '' === $seen[0]['menu']
				&& 'wp_page_menu' === $seen[0]['fallbackCb']
				&& false === \has_filter( 'wp_nav_menu_args', $args_filter, 10 ),
			'wp_nav_menu_args receives normalized defaults before theme-location lookup and is removed locally',
			array(
				'seen'         => $seen,
				'filterActive' => \has_filter( 'wp_nav_menu_args', $args_filter, 10 ),
			)
		);

		return self::row(
			$ctx,
			'navigation.wp-nav-menu.args-filter-and-items-wrap',
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

	private static function capture_echo( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
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
