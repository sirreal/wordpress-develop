<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes DB-backed navigation menu persistence and REST menu controllers.
 */
final class NavigationLifecycleSurface {
	public const NAME = 'navigation-lifecycle';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_fallback_classes();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'navigation-lifecycle.bootstrap-apis-available',
					'Required WordPress navigation lifecycle APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$case     = self::case_for_context( $ctx );
		$rows     = array();

		try {
			self::prepare_runtime();
			$rows[] = self::check_menu_object_lifecycle( $ctx->fork( 'menus' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_menu_item_lifecycle( $ctx->fork( 'items' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_associated_object_cleanup_and_auto_add( $ctx->fork( 'cleanup-auto-add' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_location_mapping( $ctx->fork( 'locations' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_navigation_fallback_classic_menu_conversion( $ctx->fork( 'navigation-fallback' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_rest_menus_controller( $ctx->fork( 'rest-menus' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_rest_menu_items_controller( $ctx->fork( 'rest-menu-items' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_rest_menu_locations_controller( $ctx->fork( 'rest-menu-locations' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'navigation-lifecycle.surface-no-throw',
				array(
					'case'      => self::case_summary( $case ),
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_state( $snapshot );
			$rows[] = self::check_state_restored( $ctx, $snapshot );
		}

		return $rows;
	}

	private static function load_fallback_classes(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach (
			array(
				'wp-includes/class-wp-classic-to-block-menu-converter.php',
				'wp-includes/class-wp-navigation-fallback.php',
			) as $file
		) {
			$path = ABSPATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Classic_To_Block_Menu_Converter',
				'WP_Error',
				'WP_Navigation_Fallback',
				'WP_Post',
				'WP_Query',
				'WP_REST_Menu_Items_Controller',
				'WP_REST_Menu_Locations_Controller',
				'WP_REST_Menus_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Server',
				'WP_Rewrite',
				'WP_Term',
			) as $class
		) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_auto_add_pages_to_menu',
				'_wp_delete_post_menu_item',
				'_wp_delete_tax_menu_item',
				'_wp_menu_item_classes_by_context',
				'_wp_reset_invalid_menu_item_parent',
				'add_action',
				'add_filter',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'delete_post_meta',
				'get_nav_menu_locations',
				'get_option',
				'get_post',
				'get_post_meta',
				'get_posts',
				'get_registered_nav_menus',
				'get_term',
				'has_filter',
				'is_nav_menu',
				'is_nav_menu_item',
				'is_wp_error',
				'parse_blocks',
				'register_nav_menus',
				'remove_action',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_ensure_response',
				'rest_get_route_for_term',
				'rest_url',
				'sanitize_html_class',
				'sanitize_key',
				'serialize_block',
				'serialize_blocks',
				'set_theme_mod',
				'taxonomy_exists',
				'update_option',
				'update_post_meta',
				'wp_cache_flush',
				'wp_create_nav_menu',
				'wp_delete_nav_menu',
				'wp_delete_post',
				'wp_get_associated_nav_menu_items',
				'wp_get_nav_menu_items',
				'wp_get_nav_menu_object',
				'wp_get_nav_menus',
				'wp_insert_post',
				'wp_insert_term',
				'wp_map_nav_menu_locations',
				'wp_set_current_user',
				'wp_setup_nav_menu_item',
				'wp_slash',
				'wp_unslash',
				'wp_update_nav_menu_item',
				'wp_update_nav_menu_object',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_menu_object_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$events  = array();
		$hooks   = self::install_menu_object_hooks( $events );
		$failures = array();

		try {
			$menu_id    = \wp_create_nav_menu( \wp_slash( $case['menuName'] ) );
			$duplicate = \wp_create_nav_menu( \wp_slash( $case['menuName'] ) );
			$menus     = \wp_get_nav_menus();

			self::collect_failure(
				$failures,
				is_int( $menu_id )
					&& $menu_id > 0
					&& self::error_has_code( $duplicate, 'menu_exists' )
					&& 1 === count( $menus ),
				'creating a menu persists one nav_menu term and duplicate names fail closed',
				array(
					'menuId'    => $menu_id,
					'duplicate' => $duplicate,
					'menus'     => self::term_list_summary( $menus ),
				)
			);

			$updated = \wp_update_nav_menu_object(
				$menu_id,
				\wp_slash(
					array(
						'description' => $case['menuDescription'],
						'menu-name'   => $case['updatedMenuName'],
					)
				)
			);
			$menu    = \wp_get_nav_menu_object( $menu_id );

			self::collect_failure(
				$failures,
				(int) $menu_id === (int) $updated
					&& $menu instanceof \WP_Term
					&& $case['updatedMenuName'] === $menu->name
					&& $case['menuDescription'] === $menu->description,
				'updating a menu mutates the existing term without changing identity',
				array(
					'updated' => $updated,
					'menu'    => $menu,
				)
			);

			\register_nav_menus(
				array(
					$case['primaryLocation'] => 'Primary ' . $case['token'],
					$case['footerLocation']  => 'Footer ' . $case['token'],
				)
			);
			\set_theme_mod(
				'nav_menu_locations',
				array(
					$case['primaryLocation'] => $menu_id,
					$case['footerLocation']  => $menu_id,
				)
			);

			$deleted         = \wp_delete_nav_menu( $menu_id );
			$after_delete    = \wp_get_nav_menu_object( $menu_id );
			$after_locations = \get_nav_menu_locations();

			self::collect_failure(
				$failures,
				true === $deleted
					&& false === $after_delete
					&& array_key_exists( $case['primaryLocation'], $after_locations )
					&& array_key_exists( $case['footerLocation'], $after_locations )
					&& 0 === (int) $after_locations[ $case['primaryLocation'] ]
					&& 0 === (int) $after_locations[ $case['footerLocation'] ],
				'deleting a menu removes the term and clears every assigned location',
				array(
					'deleted'        => $deleted,
					'afterDelete'    => $after_delete,
					'afterLocations' => $after_locations,
				)
			);

			self::collect_failure(
				$failures,
				array(
					'create' => array( $menu_id ),
					'update' => array( $menu_id ),
					'delete' => array( $menu_id ),
				) == self::event_ids_by_type( $events ),
				'create, update, and delete menu hooks fire once with the target IDs',
				array( 'events' => $events )
			);
		} finally {
			self::remove_hooks( $hooks );
		}

		return $ctx->result(
			'navigation-lifecycle.menu-object-create-update-delete-hooks-and-locations',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_menu_item_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$fixture  = self::create_menu_fixture( $case, 'item-lifecycle' );

		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'navigation-lifecycle.menu-item-crud-metadata-orphans-and-ordering',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$item_id = \wp_update_nav_menu_item(
			$fixture['menuId'],
			0,
			\wp_slash(
				array(
					'menu-item-attr-title'  => $case['itemAttrTitle'],
					'menu-item-classes'     => 'alpha bad<script> spaced',
					'menu-item-description' => $case['itemDescription'],
					'menu-item-status'      => 'publish',
					'menu-item-target'      => '_blank',
					'menu-item-title'       => $case['itemTitle'],
					'menu-item-type'        => 'custom',
					'menu-item-url'         => '  ' . $case['itemUrl'] . '  ',
					'menu-item-xfn'         => 'friend bad<script>',
				)
			)
		);

		$item = is_int( $item_id ) ? \wp_setup_nav_menu_item( \get_post( $item_id ) ) : null;
		self::collect_failure(
			$failures,
			is_int( $item_id )
				&& $item_id > 0
				&& $item
				&& \is_nav_menu_item( $item_id )
				&& 'custom' === \get_post_meta( $item_id, '_menu_item_type', true )
				&& (string) $item_id === \get_post_meta( $item_id, '_menu_item_object_id', true )
				&& 'custom' === \get_post_meta( $item_id, '_menu_item_object', true )
				&& '_blank' === \get_post_meta( $item_id, '_menu_item_target', true )
				&& $case['itemUrl'] === \get_post_meta( $item_id, '_menu_item_url', true )
				&& array( 'alpha', 'badscript', 'spaced' ) === \get_post_meta( $item_id, '_menu_item_classes', true ),
			'creating a custom item stores sanitized menu-item meta and setup fields',
			array(
				'itemId'  => $item_id,
				'item'    => $item,
				'classes' => is_int( $item_id ) ? \get_post_meta( $item_id, '_menu_item_classes', true ) : null,
				'urlMeta' => is_int( $item_id ) ? \get_post_meta( $item_id, '_menu_item_url', true ) : null,
			)
		);

		$updated = is_int( $item_id )
			? \wp_update_nav_menu_item(
				$fixture['menuId'],
				$item_id,
				\wp_slash(
					array(
						'menu-item-db-id'       => $item_id,
						'menu-item-parent-id'   => $item_id,
						'menu-item-position'    => 0,
						'menu-item-status'      => 'publish',
						'menu-item-title'       => $case['updatedItemTitle'],
						'menu-item-type'        => 'custom',
						'menu-item-url'         => $case['updatedItemUrl'],
					)
				)
			)
			: 0;

		self::collect_failure(
			$failures,
			(int) $updated === (int) $item_id
				&& '0' === \get_post_meta( $item_id, '_menu_item_menu_item_parent', true )
				&& $case['updatedItemUrl'] === \get_post_meta( $item_id, '_menu_item_url', true ),
			'updating an item resets self-parent references and refreshes meta',
			array(
				'updated'    => $updated,
				'parentMeta' => is_int( $item_id ) ? \get_post_meta( $item_id, '_menu_item_menu_item_parent', true ) : null,
				'urlMeta'    => is_int( $item_id ) ? \get_post_meta( $item_id, '_menu_item_url', true ) : null,
			)
		);

		$orphan_id = \wp_update_nav_menu_item(
			0,
			0,
			\wp_slash(
				array(
					'menu-item-status' => 'draft',
					'menu-item-title'  => $case['orphanTitle'],
					'menu-item-type'   => 'custom',
					'menu-item-url'    => $case['orphanUrl'],
				)
			)
		);
		$orphaned_before = is_int( $orphan_id ) ? \get_post_meta( $orphan_id, '_menu_item_orphaned', true ) : '';
		$moved_orphan    = is_int( $orphan_id )
			? \wp_update_nav_menu_item(
				$fixture['menuId'],
				$orphan_id,
				\wp_slash(
					array(
						'menu-item-db-id'    => $orphan_id,
						'menu-item-status'   => 'publish',
						'menu-item-title'    => $case['orphanTitle'],
						'menu-item-type'     => 'custom',
						'menu-item-url'      => $case['orphanUrl'],
					)
				)
			)
			: 0;
		$orphaned_after = is_int( $orphan_id ) ? \get_post_meta( $orphan_id, '_menu_item_orphaned', true ) : '';

		self::collect_failure(
			$failures,
			is_int( $orphan_id )
				&& $orphan_id > 0
				&& '' !== $orphaned_before
				&& (int) $moved_orphan === $orphan_id
				&& '' === $orphaned_after,
			'orphan menu items are marked while unattached and cleared when moved to a real menu',
			array(
				'orphanId'       => $orphan_id,
				'orphanedBefore' => $orphaned_before,
				'movedOrphan'    => $moved_orphan,
				'orphanedAfter'  => $orphaned_after,
			)
		);

		$post_item_id = \wp_update_nav_menu_item(
			$fixture['menuId'],
			0,
			\wp_slash(
				array(
					'menu-item-object-id' => $fixture['postId'],
					'menu-item-object'    => 'post',
					'menu-item-status'    => 'publish',
					'menu-item-type'      => 'post_type',
				)
			)
		);
		$term_item_id = \wp_update_nav_menu_item(
			$fixture['menuId'],
			0,
			\wp_slash(
				array(
					'menu-item-object-id' => $fixture['termId'],
					'menu-item-object'    => 'category',
					'menu-item-status'    => 'publish',
					'menu-item-type'      => 'taxonomy',
				)
			)
		);
		$items        = \wp_get_nav_menu_items( $fixture['menuId'], array( 'post_status' => 'publish,draft' ) );

		self::collect_failure(
			$failures,
			is_int( $post_item_id )
				&& is_int( $term_item_id )
				&& is_array( $items )
				&& self::menu_orders_are_sequential( $items )
				&& self::setup_items_include_types( $items, array( 'custom', 'post_type', 'taxonomy' ) ),
			'item retrieval decorates items and normalizes output ordering across custom, post, and term entries',
			array(
				'postItemId' => $post_item_id,
				'termItemId' => $term_item_id,
				'items'      => self::menu_item_list_summary( is_array( $items ) ? $items : array() ),
			)
		);

		\update_menu_item_cache( is_array( $items ) ? $items : array() );

		return $ctx->result(
			'navigation-lifecycle.menu-item-crud-metadata-orphans-and-ordering',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_associated_object_cleanup_and_auto_add( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$fixture  = self::create_menu_fixture( $case, 'cleanup' );

		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'navigation-lifecycle.associated-object-cleanup-and-auto-add',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$post_item = \wp_update_nav_menu_item(
			$fixture['menuId'],
			0,
			\wp_slash(
				array(
					'menu-item-object-id' => $fixture['postId'],
					'menu-item-object'    => 'post',
					'menu-item-status'    => 'publish',
					'menu-item-type'      => 'post_type',
				)
			)
		);
		$term_item = \wp_update_nav_menu_item(
			$fixture['menuId'],
			0,
			\wp_slash(
				array(
					'menu-item-object-id' => $fixture['termId'],
					'menu-item-object'    => 'category',
					'menu-item-status'    => 'publish',
					'menu-item-type'      => 'taxonomy',
				)
			)
		);

		$post_associated = \wp_get_associated_nav_menu_items( $fixture['postId'], 'post_type', 'post' );
		$term_associated = \wp_get_associated_nav_menu_items( $fixture['termId'], 'taxonomy', 'category' );

		\_wp_delete_post_menu_item( $fixture['postId'] );
		\_wp_delete_tax_menu_item( $fixture['termId'], $fixture['termTaxonomyId'], 'category' );

		self::collect_failure(
			$failures,
			is_int( $post_item )
				&& is_int( $term_item )
				&& in_array( $post_item, array_map( 'intval', $post_associated ), true )
				&& in_array( $term_item, array_map( 'intval', $term_associated ), true )
				&& ! \get_post( $post_item )
				&& ! \get_post( $term_item ),
			'associated post and term delete callbacks remove matching nav menu items only',
			array(
				'postItem'       => $post_item,
				'termItem'       => $term_item,
				'postAssociated' => $post_associated,
				'termAssociated' => $term_associated,
				'postAfter'      => is_int( $post_item ) ? \get_post( $post_item ) : null,
				'termAfter'      => is_int( $term_item ) ? \get_post( $term_item ) : null,
			)
		);

		$page_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_status' => 'publish',
					'post_title'  => $case['pageTitle'],
					'post_type'   => 'page',
				)
			),
			true,
			false
		);
		\update_option( 'nav_menu_options', array( 'auto_add' => array( $fixture['menuId'] ) ) );

		$page = is_int( $page_id ) ? \get_post( $page_id ) : null;
		if ( $page instanceof \WP_Post ) {
			\_wp_auto_add_pages_to_menu( 'publish', 'draft', $page );
			\_wp_auto_add_pages_to_menu( 'publish', 'draft', $page );
		}

		$auto_items = \wp_get_nav_menu_items( $fixture['menuId'], array( 'post_status' => 'publish,draft' ) );
		$page_items = self::items_for_object( is_array( $auto_items ) ? $auto_items : array(), $page_id, 'post_type', 'page' );

		self::collect_failure(
			$failures,
			is_int( $page_id )
				&& $page instanceof \WP_Post
				&& 1 === count( $page_items ),
			'auto_add inserts a top-level published page once for opted-in menus',
			array(
				'pageId'    => $page_id,
				'pageItems' => self::menu_item_list_summary( $page_items ),
				'allItems'  => self::menu_item_list_summary( is_array( $auto_items ) ? $auto_items : array() ),
			)
		);

		return $ctx->result(
			'navigation-lifecycle.associated-object-cleanup-and-auto-add',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_location_mapping( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		\register_nav_menus(
			array(
				'primary' => 'Primary',
				'footer'  => 'Footer',
				'social'  => 'Social',
			)
		);

		$mapped = \wp_map_nav_menu_locations(
			array(
				'footer'       => 0,
				'primary'      => 0,
				'social'       => 0,
				'unregistered' => 999,
			),
			array(
				'main'   => 11,
				'bottom' => 22,
				'social' => 33,
			)
		);

		self::collect_failure(
			$failures,
			! array_key_exists( 'unregistered', $mapped )
				&& 11 === (int) ( $mapped['primary'] ?? 0 )
				&& 22 === (int) ( $mapped['footer'] ?? 0 )
				&& 33 === (int) ( $mapped['social'] ?? 0 ),
			'location mapping keeps registered locations, exact matches, and slug-group guesses',
			array( 'mapped' => $mapped )
		);

		$reset = \_wp_reset_invalid_menu_item_parent(
			array(
				'ID'               => 44,
				'menu_item_parent' => 44,
				'title'            => $case['itemTitle'],
			)
		);
		$other = \_wp_reset_invalid_menu_item_parent(
			array(
				'ID'               => 44,
				'menu_item_parent' => 43,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $reset )
				&& 0 === (int) $reset['menu_item_parent']
				&& is_array( $other )
				&& 43 === (int) $other['menu_item_parent']
				&& 'scalar' === \_wp_reset_invalid_menu_item_parent( 'scalar' ),
			'invalid menu item parent normalization only resets self-parent arrays',
			array(
				'reset' => $reset,
				'other' => $other,
			)
		);

		return $ctx->result(
			'navigation-lifecycle.location-mapping-and-parent-normalization',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_navigation_fallback_classic_menu_conversion( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$fixture  = self::create_fallback_classic_menu_fixture( $case );

		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'navigation-lifecycle.navigation-fallback-classic-menu-conversion',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$disable_creation = static function (): bool {
			return false;
		};
		\add_filter( 'wp_navigation_should_create_fallback', $disable_creation );
		try {
			$disabled_fallback = \WP_Navigation_Fallback::get_fallback();
		} finally {
			\remove_filter( 'wp_navigation_should_create_fallback', $disable_creation );
		}
		$before_posts = self::published_navigation_posts();

		self::collect_failure(
			$failures,
			null === $disabled_fallback
				&& array() === $before_posts
				&& false === \has_filter( 'wp_navigation_should_create_fallback', $disable_creation ),
			'fallback creation can be disabled without inserting a wp_navigation post or leaking the filter',
			array(
				'disabledFallback' => $disabled_fallback,
				'beforePosts'      => self::post_list_summary( $before_posts ),
				'filterRemaining'  => \has_filter( 'wp_navigation_should_create_fallback', $disable_creation ),
			)
		);

		$fallback     = \WP_Navigation_Fallback::get_fallback();
		$after_posts  = self::published_navigation_posts();
		$fallback_id  = $fallback instanceof \WP_Post ? (int) $fallback->ID : 0;
		$fallback_post = $fallback instanceof \WP_Post ? $fallback : null;
		$blocks       = $fallback_post ? \parse_blocks( $fallback_post->post_content ) : array();
		$parent_block = self::block_by_label( $blocks, $case['fallbackParentTitle'] );
		$child_block  = self::block_by_label( $blocks, $case['fallbackChildTitle'] );
		$post_block   = self::block_by_label( $blocks, $case['fallbackPostTitle'] );

		self::collect_failure(
			$failures,
			$fallback_post instanceof \WP_Post
				&& 1 === count( $after_posts )
				&& $fallback_id === (int) $after_posts[0]->ID
				&& 'wp_navigation' === $fallback_post->post_type
				&& 'publish' === $fallback_post->post_status
				&& $fixture['assignedMenuName'] === $fallback_post->post_title
				&& $fixture['assignedMenuSlug'] === $fallback_post->post_name,
			'fallback creation chooses the classic menu assigned to the primary location and inserts one published wp_navigation post',
			array(
				'fallback'          => $fallback_post,
				'fallbackId'        => $fallback_id,
				'posts'             => self::post_list_summary( $after_posts ),
				'assignedMenuId'    => $fixture['assignedMenuId'],
				'primarySlugMenuId' => $fixture['primarySlugMenuId'],
				'newestMenuId'      => $fixture['newestMenuId'],
			)
		);

		self::collect_failure(
			$failures,
			array( $case['fallbackParentTitle'], $case['fallbackPostTitle'] ) === self::top_level_block_labels( $blocks )
				&& self::block_has_name( $parent_block, 'core/navigation-submenu' )
				&& self::block_has_name( $child_block, 'core/navigation-link' )
				&& self::block_has_name( $post_block, 'core/navigation-link' ),
			'converted fallback content preserves top-level order and represents nested classic items as a navigation-submenu tree',
			array(
				'topLevelLabels' => self::top_level_block_labels( $blocks ),
				'parentBlock'    => $parent_block,
				'childBlock'     => $child_block,
				'postBlock'      => $post_block,
			)
		);

		$parent_attrs = is_array( $parent_block ) ? (array) ( $parent_block['attrs'] ?? array() ) : array();
		$child_attrs  = is_array( $child_block ) ? (array) ( $child_block['attrs'] ?? array() ) : array();
		$post_attrs   = is_array( $post_block ) ? (array) ( $post_block['attrs'] ?? array() ) : array();

		self::collect_failure(
			$failures,
			$case['fallbackParentTitle'] === ( $parent_attrs['label'] ?? null )
				&& $case['fallbackParentUrl'] === ( $parent_attrs['url'] ?? null )
				&& $case['fallbackParentDescription'] === ( $parent_attrs['description'] ?? null )
				&& $case['fallbackParentAttrTitle'] === ( $parent_attrs['title'] ?? null )
				&& true === ( $parent_attrs['opensInNewTab'] ?? null )
				&& 'friend badscript' === ( $parent_attrs['rel'] ?? null )
				&& 'custom' === ( $parent_attrs['kind'] ?? null )
				&& 'custom' === ( $parent_attrs['type'] ?? null )
				&& self::class_attr_contains( $parent_attrs['className'] ?? null, 'fallback-alpha' )
				&& self::class_attr_contains( $parent_attrs['className'] ?? null, 'badscript' ),
			'custom parent menu item attributes survive conversion with sanitized class and rel tokens',
			array( 'parentAttrs' => $parent_attrs )
		);

		self::collect_failure(
			$failures,
			$case['fallbackChildTitle'] === ( $child_attrs['label'] ?? null )
				&& $case['fallbackChildUrl'] === ( $child_attrs['url'] ?? null )
				&& false === ( $child_attrs['opensInNewTab'] ?? null )
				&& 'custom' === ( $child_attrs['kind'] ?? null )
				&& 'custom' === ( $child_attrs['type'] ?? null )
				&& self::class_attr_contains( $child_attrs['className'] ?? null, 'fallback-child' ),
			'custom child menu item remains nested and keeps normalized custom-link attributes',
			array( 'childAttrs' => $child_attrs )
		);

		self::collect_failure(
			$failures,
			$case['fallbackPostTitle'] === ( $post_attrs['label'] ?? null )
				&& 'post-type' === ( $post_attrs['kind'] ?? null )
				&& 'post' === ( $post_attrs['type'] ?? null )
				&& $fixture['postId'] === (int) ( $post_attrs['id'] ?? 0 ),
			'post-type menu items convert to navigation-link blocks with the target post identity',
			array(
				'postAttrs' => $post_attrs,
				'postId'    => $fixture['postId'],
			)
		);

		$second_fallback = \WP_Navigation_Fallback::get_fallback();
		$second_posts    = self::published_navigation_posts();

		self::collect_failure(
			$failures,
			$second_fallback instanceof \WP_Post
				&& $fallback_id === (int) $second_fallback->ID
				&& 1 === count( $second_posts ),
			'repeated fallback lookups reuse the existing published wp_navigation post instead of creating duplicates',
			array(
				'firstId'     => $fallback_id,
				'second'      => $second_fallback,
				'secondPosts' => self::post_list_summary( $second_posts ),
			)
		);

		return $ctx->result(
			'navigation-lifecycle.navigation-fallback-classic-menu-conversion',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 10 ),
			)
		);
	}

	private static function check_rest_menus_controller( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$controller = new \WP_REST_Menus_Controller( 'nav_menu' );
		$failures   = array();

		\register_nav_menus(
			array(
				$case['primaryLocation'] => 'Primary ' . $case['token'],
				$case['footerLocation']  => 'Footer ' . $case['token'],
			)
		);

		$list_request = self::request( 'GET', '/wp/v2/menus' );
		$denied       = $controller->get_items_permissions_check( $list_request );
		$read_filter  = self::install_read_access_filter();
		try {
			$filter_allowed = $controller->get_items_permissions_check( $list_request );
		} finally {
			$read_filter_restored = self::remove_filter_handle( 'rest_menu_read_access', $read_filter );
		}
		$cap_filter = self::install_cap_filter( array( 'edit_posts' ) );
		try {
			$cap_allowed = $controller->get_items_permissions_check( $list_request );
		} finally {
			$cap_filter_restored = self::remove_filter_handle( 'user_has_cap', $cap_filter );
		}

		self::collect_failure(
			$failures,
			self::error_has_code( $denied, 'rest_cannot_view' )
				&& true === $filter_allowed
				&& true === $read_filter_restored
				&& true === $cap_allowed
				&& true === $cap_filter_restored,
			'menus controller read permission gates honor rest_menu_read_access and edit-post caps',
			array(
				'denied'             => $denied,
				'filterAllowed'      => $filter_allowed,
				'readFilterRestored' => $read_filter_restored,
				'capAllowed'         => $cap_allowed,
				'capFilterRestored'  => $cap_filter_restored,
			)
		);

		$create = $controller->create_item(
			self::request(
				'POST',
				'/wp/v2/menus',
				array(),
				array(),
				array(
					'auto_add'  => true,
					'locations' => array( $case['primaryLocation'] ),
					'name'      => $case['restMenuName'],
				)
			)
		);
		$created_data = $create instanceof \WP_REST_Response ? $create->get_data() : array();
		$menu_id      = (int) ( $created_data['id'] ?? 0 );
		$locations    = \get_nav_menu_locations();
		$auto_add     = (array) \get_option( 'nav_menu_options', array() );
		$duplicate    = $controller->create_item(
			self::request(
				'POST',
				'/wp/v2/menus',
				array(),
				array(),
				array( 'name' => $case['restMenuName'] )
			)
		);

		self::collect_failure(
			$failures,
			$create instanceof \WP_REST_Response
				&& 201 === $create->get_status()
				&& $menu_id > 0
				&& $case['restMenuName'] === ( $created_data['name'] ?? null )
				&& array( $case['primaryLocation'] ) === ( $created_data['locations'] ?? null )
				&& true === ( $created_data['auto_add'] ?? null )
				&& $menu_id === (int) ( $locations[ $case['primaryLocation'] ] ?? 0 )
				&& in_array( $menu_id, $auto_add['auto_add'] ?? array(), true )
				&& self::error_has_code( $duplicate, 'menu_exists' )
				&& self::error_status_is( $duplicate, 400 ),
			'menus controller create persists nav menu state, locations, auto_add, and duplicate errors',
			array(
				'create'      => $create,
				'createdData' => $created_data,
				'locations'   => $locations,
				'autoAdd'     => $auto_add,
				'duplicate'   => $duplicate,
			)
		);

		$update = $controller->update_item(
			self::request(
				'PUT',
				'/wp/v2/menus/' . $menu_id,
				array(),
				array( 'id' => $menu_id ),
				array(
					'auto_add'  => false,
					'locations' => array( $case['footerLocation'] ),
					'name'      => $case['restUpdatedMenuName'],
				)
			)
		);
		$updated_data     = $update instanceof \WP_REST_Response ? $update->get_data() : array();
		$updated_locations = \get_nav_menu_locations();
		$updated_auto_add  = (array) \get_option( 'nav_menu_options', array() );
		$invalid_location  = $controller->update_item(
			self::request(
				'PUT',
				'/wp/v2/menus/' . $menu_id,
				array(),
				array( 'id' => $menu_id ),
				array( 'locations' => array( 'missing-location-' . $case['token'] ) )
			)
		);

		self::collect_failure(
			$failures,
			$update instanceof \WP_REST_Response
				&& $case['restUpdatedMenuName'] === ( $updated_data['name'] ?? null )
				&& array( $case['footerLocation'] ) === ( $updated_data['locations'] ?? null )
				&& false === ( $updated_data['auto_add'] ?? null )
				&& 0 === (int) ( $updated_locations[ $case['primaryLocation'] ] ?? 0 )
				&& $menu_id === (int) ( $updated_locations[ $case['footerLocation'] ] ?? 0 )
				&& ! in_array( $menu_id, $updated_auto_add['auto_add'] ?? array(), true )
				&& self::error_has_code( $invalid_location, 'rest_invalid_menu_location' )
				&& self::error_status_is( $invalid_location, 400 ),
			'menus controller update moves locations, toggles auto_add, and rejects unknown locations',
			array(
				'update'           => $update,
				'updatedData'      => $updated_data,
				'updatedLocations' => $updated_locations,
				'updatedAutoAdd'   => $updated_auto_add,
				'invalidLocation'  => $invalid_location,
			)
		);

		$trash = $controller->delete_item(
			self::request(
				'DELETE',
				'/wp/v2/menus/' . $menu_id,
				array(),
				array( 'id' => $menu_id ),
				array( 'force' => false )
			)
		);
		$delete = $controller->delete_item(
			self::request(
				'DELETE',
				'/wp/v2/menus/' . $menu_id,
				array(),
				array( 'id' => $menu_id ),
				array( 'force' => true )
			)
		);
		$delete_data       = $delete instanceof \WP_REST_Response ? $delete->get_data() : array();
		$delete_locations  = \get_nav_menu_locations();

		self::collect_failure(
			$failures,
			self::error_has_code( $trash, 'rest_trash_not_supported' )
				&& self::error_status_is( $trash, 501 )
				&& $delete instanceof \WP_REST_Response
				&& true === ( $delete_data['deleted'] ?? null )
				&& false === \wp_get_nav_menu_object( $menu_id )
				&& 0 === (int) ( $delete_locations[ $case['footerLocation'] ] ?? 0 ),
			'menus controller force-delete semantics mirror core menu deletion',
			array(
				'trash'           => $trash,
				'delete'          => $delete,
				'deleteData'      => $delete_data,
				'deleteLocations' => $delete_locations,
			)
		);

		return $ctx->result(
			'navigation-lifecycle.rest-menus-controller-permissions-lifecycle-and-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 10 ),
			)
		);
	}

	private static function check_rest_menu_items_controller( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$controller = new \WP_REST_Menu_Items_Controller( 'nav_menu_item' );
		$failures   = array();
		$fixture    = self::create_menu_fixture( $case, 'rest-items' );

		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'navigation-lifecycle.rest-menu-items-controller-validation-crud-and-permissions',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$list_request = self::request( 'GET', '/wp/v2/menu-items' );
		$denied       = $controller->get_items_permissions_check( $list_request );
		$read_filter  = self::install_read_access_filter();
		try {
			$filter_allowed = $controller->get_items_permissions_check( $list_request );
		} finally {
			$read_filter_restored = self::remove_filter_handle( 'rest_menu_read_access', $read_filter );
		}

		self::collect_failure(
			$failures,
			self::error_has_code( $denied, 'rest_cannot_view' )
				&& true === $filter_allowed
				&& true === $read_filter_restored,
			'menu items controller read permission gate honors rest_menu_read_access',
			array(
				'denied'             => $denied,
				'filterAllowed'      => $filter_allowed,
				'readFilterRestored' => $read_filter_restored,
			)
		);

		$invalid_custom = $controller->create_item(
			self::request(
				'POST',
				'/wp/v2/menu-items',
				array(),
				array(),
				array(
					'menus' => $fixture['menuId'],
					'type'  => 'custom',
				)
			)
		);
		$invalid_post = $controller->create_item(
			self::request(
				'POST',
				'/wp/v2/menu-items',
				array(),
				array(),
				array(
					'menus'     => $fixture['menuId'],
					'object_id' => 999999,
					'type'      => 'post_type',
				)
			)
		);

		self::collect_failure(
			$failures,
			self::error_has_code( $invalid_custom, 'rest_title_required' )
				&& self::error_has_code( $invalid_custom, 'rest_url_required' )
				&& self::error_has_code( $invalid_post, 'rest_post_invalid_id' ),
			'menu items controller rejects incomplete custom items and missing object targets',
			array(
				'invalidCustom' => $invalid_custom,
				'invalidPost'   => $invalid_post,
			)
		);

		$create = $controller->create_item(
			self::request(
				'POST',
				'/wp/v2/menu-items',
				array(),
				array(),
				array(
					'classes'    => array( 'rest-class', 'bad<script>' ),
					'description' => $case['itemDescription'],
					'menus'      => $fixture['menuId'],
					'status'     => 'publish',
					'target'     => '_blank',
					'title'      => $case['restItemTitle'],
					'type'       => 'custom',
					'url'        => $case['restItemUrl'],
					'xfn'        => array( 'friend', 'bad<script>' ),
				)
			)
		);
		$created_data = $create instanceof \WP_REST_Response ? $create->get_data() : array();
		$item_id      = (int) ( $created_data['id'] ?? 0 );

		self::collect_failure(
			$failures,
			$create instanceof \WP_REST_Response
				&& 201 === $create->get_status()
				&& $item_id > 0
				&& $case['restItemTitle'] === ( $created_data['title']['raw'] ?? null )
				&& $case['restItemUrl'] === ( $created_data['url'] ?? null )
				&& 'custom' === ( $created_data['type'] ?? null )
				&& $fixture['menuId'] === (int) ( $created_data['menus'] ?? 0 )
				&& array( 'rest-class', 'badscript' ) === ( $created_data['classes'] ?? null )
				&& array( 'friend', 'badscript' ) === ( $created_data['xfn'] ?? null ),
			'menu items controller create persists sanitized custom item data and menu assignment',
			array(
				'create'      => $create,
				'createdData' => $created_data,
			)
		);

		$update = $controller->update_item(
			self::request(
				'PUT',
				'/wp/v2/menu-items/' . $item_id,
				array(),
				array( 'id' => $item_id ),
				array(
					'parent' => $item_id,
					'title'  => $case['restUpdatedItemTitle'],
					'url'    => $case['restUpdatedItemUrl'],
				)
			)
		);
		$updated_data = $update instanceof \WP_REST_Response ? $update->get_data() : array();

		self::collect_failure(
			$failures,
			$update instanceof \WP_REST_Response
				&& $case['restUpdatedItemTitle'] === ( $updated_data['title']['raw'] ?? null )
				&& $case['restUpdatedItemUrl'] === ( $updated_data['url'] ?? null )
				&& 0 === (int) ( $updated_data['parent'] ?? -1 )
				&& '0' === \get_post_meta( $item_id, '_menu_item_menu_item_parent', true ),
			'menu items controller update preserves existing data while normalizing self-parent input',
			array(
				'update'      => $update,
				'updatedData' => $updated_data,
				'parentMeta'  => $item_id ? \get_post_meta( $item_id, '_menu_item_menu_item_parent', true ) : null,
			)
		);

		$trash = $controller->delete_item(
			self::request(
				'DELETE',
				'/wp/v2/menu-items/' . $item_id,
				array(),
				array( 'id' => $item_id ),
				array( 'force' => false )
			)
		);
		$delete = $controller->delete_item(
			self::request(
				'DELETE',
				'/wp/v2/menu-items/' . $item_id,
				array(),
				array( 'id' => $item_id ),
				array( 'force' => true )
			)
		);
		$delete_data = $delete instanceof \WP_REST_Response ? $delete->get_data() : array();

		self::collect_failure(
			$failures,
			self::error_has_code( $trash, 'rest_trash_not_supported' )
				&& self::error_status_is( $trash, 501 )
				&& $delete instanceof \WP_REST_Response
				&& true === ( $delete_data['deleted'] ?? null )
				&& ! \get_post( $item_id ),
			'menu items controller requires force delete and removes the nav_menu_item post',
			array(
				'trash'      => $trash,
				'delete'     => $delete,
				'deleteData' => $delete_data,
				'postAfter'  => $item_id ? \get_post( $item_id ) : null,
			)
		);

		return $ctx->result(
			'navigation-lifecycle.rest-menu-items-controller-validation-crud-and-permissions',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 10 ),
			)
		);
	}

	private static function check_rest_menu_locations_controller( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$controller = new \WP_REST_Menu_Locations_Controller();
		$failures   = array();
		$menu_id    = \wp_create_nav_menu( \wp_slash( $case['locationMenuName'] ) );

		\register_nav_menus(
			array(
				$case['primaryLocation'] => 'Primary ' . $case['token'],
				$case['footerLocation']  => 'Footer ' . $case['token'],
			)
		);
		\set_theme_mod(
			'nav_menu_locations',
			array(
				$case['primaryLocation'] => $menu_id,
				$case['footerLocation']  => 0,
			)
		);

		$list_request = self::request( 'GET', '/wp/v2/menu-locations' );
		$denied       = $controller->get_items_permissions_check( $list_request );
		$read_filter  = self::install_read_access_filter();
		try {
			$filter_allowed = $controller->get_items_permissions_check( $list_request );
		} finally {
			$read_filter_restored = self::remove_filter_handle( 'rest_menu_read_access', $read_filter );
		}
		$cap_filter = self::install_cap_filter( array( 'edit_theme_options' ) );
		try {
			$cap_allowed = $controller->get_items_permissions_check( $list_request );
		} finally {
			$cap_filter_restored = self::remove_filter_handle( 'user_has_cap', $cap_filter );
		}

		self::collect_failure(
			$failures,
			self::error_has_code( $denied, 'rest_cannot_view' )
				&& true === $filter_allowed
				&& true === $read_filter_restored
				&& true === $cap_allowed
				&& true === $cap_filter_restored,
			'menu locations controller permission gates honor read filter and edit_theme_options',
			array(
				'denied'             => $denied,
				'filterAllowed'      => $filter_allowed,
				'readFilterRestored' => $read_filter_restored,
				'capAllowed'         => $cap_allowed,
				'capFilterRestored'  => $cap_filter_restored,
			)
		);

		$items = $controller->get_items(
			self::request(
				'GET',
				'/wp/v2/menu-locations',
				array( '_fields' => 'name,description,menu' )
			)
		);
		$items_data = $items instanceof \WP_REST_Response ? $items->get_data() : array();
		$item       = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/menu-locations/' . $case['primaryLocation'],
				array( '_fields' => 'name,description,menu,_links' ),
				array( 'location' => $case['primaryLocation'] )
			)
		);
		$item_data  = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		$missing    = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/menu-locations/missing',
				array(),
				array( 'location' => 'missing-' . $case['token'] )
			)
		);

		self::collect_failure(
			$failures,
			$items instanceof \WP_REST_Response
				&& $item instanceof \WP_REST_Response
				&& isset( $items_data[ $case['primaryLocation'] ], $items_data[ $case['footerLocation'] ] )
				&& $menu_id === (int) ( $items_data[ $case['primaryLocation'] ]['menu'] ?? 0 )
				&& 0 === (int) ( $items_data[ $case['footerLocation'] ]['menu'] ?? -1 )
				&& $case['primaryLocation'] === ( $item_data['name'] ?? null )
				&& $menu_id === (int) ( $item_data['menu'] ?? 0 )
				&& isset( $item_links['self'][0]['href'], $item_links['https://api.w.org/menu'][0]['href'] )
				&& self::error_has_code( $missing, 'rest_menu_location_invalid' )
				&& self::error_status_is( $missing, 404 ),
			'menu locations controller serializes assignments, links assigned menus, and rejects unknown locations',
			array(
				'items'     => $items,
				'itemsData' => $items_data,
				'item'      => $item,
				'itemData'  => $item_data,
				'links'     => $item_links,
				'missing'   => $missing,
			)
		);

		return $ctx->result(
			'navigation-lifecycle.rest-menu-locations-controller-permissions-serialization-and-links',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 10 ),
			)
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'sha1', (string) $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );

		return array(
			'token'                     => $token,
			'fallbackAssignedMenuName'  => 'Assigned Classic Menu ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'fallbackChildTitle'        => 'Fallback Child ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'fallbackChildUrl'          => 'https://example.test/' . $token . '/fallback-child',
			'fallbackNewestMenuName'    => 'Newest Classic Menu ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'fallbackParentAttrTitle'   => 'Fallback Parent Attribute ' . $ctx->int( 10, 999 ),
			'fallbackParentDescription' => 'Fallback parent description ' . $token,
			'fallbackParentTitle'       => 'Fallback Parent ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'fallbackParentUrl'         => 'https://example.test/' . $token . '/fallback-parent',
			'fallbackPostTitle'         => 'Fallback Target Post ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'footerLocation'            => 'footer-' . $token,
			'itemAttrTitle'             => 'Attribute ' . $ctx->int( 10, 999 ),
			'itemDescription'           => 'Generated item description ' . $token,
			'itemTitle'                 => 'Menu Item ' . $ctx->int( 10, 999 ),
			'itemUrl'                   => 'https://example.test/' . $token . '/item',
			'locationMenuName'          => 'Location Menu ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'menuDescription'           => 'Generated menu description ' . $token,
			'menuName'                  => 'Lifecycle Menu ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'orphanTitle'               => 'Orphan Item ' . $ctx->int( 10, 999 ),
			'orphanUrl'                 => 'https://example.test/' . $token . '/orphan',
			'pageTitle'                 => 'Auto Page ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'postTitle'                 => 'Navigation Target Post ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'primaryLocation'           => 'primary-' . $token,
			'restItemTitle'             => 'REST Item ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'restItemUrl'               => 'https://example.test/' . $token . '/rest-item',
			'restMenuName'              => 'REST Menu ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'restUpdatedItemTitle'      => 'REST Item Updated ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'restUpdatedItemUrl'        => 'https://example.test/' . $token . '/rest-item-updated',
			'restUpdatedMenuName'       => 'REST Menu Updated ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'termName'                  => 'Navigation Target Term ' . $ctx->int( 10, 999 ) . ' ' . $token,
			'updatedItemTitle'          => 'Updated Menu Item ' . $ctx->int( 10, 999 ),
			'updatedItemUrl'            => 'https://example.test/' . $token . '/updated-item',
			'updatedMenuName'           => 'Updated Lifecycle Menu ' . $ctx->int( 10, 999 ) . ' ' . $token,
		);
	}

	private static function create_fallback_classic_menu_fixture( array $case ): array {
		$assigned_menu_id = \wp_create_nav_menu( \wp_slash( $case['fallbackAssignedMenuName'] ) );
		$primary_slug_id  = \wp_create_nav_menu( 'Primary' );
		$newest_menu_id   = \wp_create_nav_menu( \wp_slash( $case['fallbackNewestMenuName'] ) );

		if ( \is_wp_error( $assigned_menu_id ) || ! is_int( $assigned_menu_id ) ) {
			return array( 'error' => array( 'assignedMenu' => $assigned_menu_id ) );
		}
		if ( \is_wp_error( $primary_slug_id ) || ! is_int( $primary_slug_id ) ) {
			return array( 'error' => array( 'primarySlugMenu' => $primary_slug_id ) );
		}
		if ( \is_wp_error( $newest_menu_id ) || ! is_int( $newest_menu_id ) ) {
			return array( 'error' => array( 'newestMenu' => $newest_menu_id ) );
		}

		\register_nav_menus( array( 'primary' => 'Primary fallback location ' . $case['token'] ) );
		\set_theme_mod( 'nav_menu_locations', array( 'primary' => $assigned_menu_id ) );

		$post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_content' => 'Fallback target content ' . $case['token'],
					'post_status'  => 'publish',
					'post_title'   => $case['fallbackPostTitle'],
					'post_type'    => 'post',
				)
			),
			true,
			false
		);
		if ( \is_wp_error( $post_id ) || ! is_int( $post_id ) ) {
			return array( 'error' => array( 'post' => $post_id ) );
		}

		$parent_id = \wp_update_nav_menu_item(
			$assigned_menu_id,
			0,
			\wp_slash(
				array(
					'menu-item-attr-title'  => $case['fallbackParentAttrTitle'],
					'menu-item-classes'     => 'fallback-alpha bad<script>',
					'menu-item-description' => $case['fallbackParentDescription'],
					'menu-item-position'    => 1,
					'menu-item-status'      => 'publish',
					'menu-item-target'      => '_blank',
					'menu-item-title'       => $case['fallbackParentTitle'],
					'menu-item-type'        => 'custom',
					'menu-item-url'         => $case['fallbackParentUrl'],
					'menu-item-xfn'         => 'friend bad<script>',
				)
			)
		);
		if ( \is_wp_error( $parent_id ) || ! is_int( $parent_id ) ) {
			return array( 'error' => array( 'parentItem' => $parent_id ) );
		}

		$child_id = \wp_update_nav_menu_item(
			$assigned_menu_id,
			0,
			\wp_slash(
				array(
					'menu-item-classes'   => 'fallback-child',
					'menu-item-parent-id' => $parent_id,
					'menu-item-position'  => 2,
					'menu-item-status'    => 'publish',
					'menu-item-title'     => $case['fallbackChildTitle'],
					'menu-item-type'      => 'custom',
					'menu-item-url'       => $case['fallbackChildUrl'],
				)
			)
		);
		if ( \is_wp_error( $child_id ) || ! is_int( $child_id ) ) {
			return array( 'error' => array( 'childItem' => $child_id ) );
		}

		$post_item_id = \wp_update_nav_menu_item(
			$assigned_menu_id,
			0,
			\wp_slash(
				array(
					'menu-item-object'    => 'post',
					'menu-item-object-id' => $post_id,
					'menu-item-position'  => 3,
					'menu-item-status'    => 'publish',
					'menu-item-type'      => 'post_type',
				)
			)
		);
		if ( \is_wp_error( $post_item_id ) || ! is_int( $post_item_id ) ) {
			return array( 'error' => array( 'postItem' => $post_item_id ) );
		}

		$assigned_menu = \wp_get_nav_menu_object( $assigned_menu_id );
		if ( ! $assigned_menu instanceof \WP_Term ) {
			return array( 'error' => array( 'assignedMenuObject' => $assigned_menu ) );
		}

		return array(
			'assignedMenuId'    => (int) $assigned_menu_id,
			'assignedMenuName'  => $assigned_menu->name,
			'assignedMenuSlug'  => $assigned_menu->slug,
			'childItemId'       => (int) $child_id,
			'newestMenuId'      => (int) $newest_menu_id,
			'parentItemId'      => (int) $parent_id,
			'postId'            => (int) $post_id,
			'postItemId'        => (int) $post_item_id,
			'primarySlugMenuId' => (int) $primary_slug_id,
		);
	}

	private static function create_menu_fixture( array $case, string $suffix ): array {
		$menu_id = \wp_create_nav_menu( \wp_slash( $case['menuName'] . ' ' . $suffix ) );
		if ( \is_wp_error( $menu_id ) || ! is_int( $menu_id ) ) {
			return array( 'error' => array( 'menu' => $menu_id ) );
		}

		$post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_content' => 'Navigation target content ' . $case['token'] . ' ' . $suffix,
					'post_status'  => 'publish',
					'post_title'   => $case['postTitle'] . ' ' . $suffix,
					'post_type'    => 'post',
				)
			),
			true,
			false
		);
		if ( \is_wp_error( $post_id ) || ! is_int( $post_id ) ) {
			return array( 'error' => array( 'post' => $post_id ) );
		}

		$term = \wp_insert_term(
			$case['termName'] . ' ' . $suffix,
			'category',
			array(
				'description' => 'Navigation target term ' . $case['token'] . ' ' . $suffix,
				'slug'        => 'nav-target-' . $case['token'] . '-' . $suffix,
			)
		);
		if ( \is_wp_error( $term ) || ! is_array( $term ) || empty( $term['term_id'] ) || empty( $term['term_taxonomy_id'] ) ) {
			return array( 'error' => array( 'term' => $term ) );
		}

		return array(
			'menuId'         => (int) $menu_id,
			'postId'         => (int) $post_id,
			'termId'         => (int) $term['term_id'],
			'termTaxonomyId' => (int) $term['term_taxonomy_id'],
		);
	}

	private static function prepare_runtime(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'admin_email'       => 'admin@example.test',
					'blog_charset'      => 'UTF-8',
					'blogname'          => 'Component Fuzz',
					'category_base'     => '',
					'comment_moderation' => 0,
					'home'              => 'http://example.test',
					'nav_menu_options'  => array( 'auto_add' => array() ),
					'permalink_structure' => '',
					'siteurl'           => 'http://example.test',
					'stylesheet'        => 'component-fuzz-theme',
					'template'          => 'component-fuzz-theme',
					'theme_mods_component-fuzz-theme' => array( 'nav_menu_locations' => array() ),
				)
			);
		}

		\wp_cache_flush();

		$GLOBALS['_wp_post_type_features']  = array();
		$GLOBALS['post_type_meta_caps']     = array();
		$GLOBALS['wp_post_statuses']        = array();
		$GLOBALS['wp_post_types']           = array();
		$GLOBALS['wp_query']                = new \WP_Query();
		$GLOBALS['wp_the_query']            = $GLOBALS['wp_query'];
		$GLOBALS['_wp_registered_nav_menus'] = array();
		$GLOBALS['wp_rest_server']          = new \WP_REST_Server();
		$GLOBALS['wp_rewrite']              = new \WP_Rewrite();
		$GLOBALS['wp_taxonomies']           = array();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz NavigationLifecycle';
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/navigation-lifecycle/';
	}

	private static function request( string $method, string $route, array $query_params = array(), array $url_params = array(), array $body_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( array() !== $query_params ) {
			$request->set_query_params( $query_params );
		}
		if ( array() !== $url_params ) {
			$request->set_url_params( $url_params );
		}
		if ( array() !== $body_params ) {
			$request->set_body_params( $body_params );
		}
		return $request;
	}

	private static function install_menu_object_hooks( array &$events ): array {
		$create = static function ( int $term_id ) use ( &$events ): void {
			$events[] = array( 'type' => 'create', 'id' => $term_id );
		};
		$update = static function ( int $term_id ) use ( &$events ): void {
			$events[] = array( 'type' => 'update', 'id' => $term_id );
		};
		$delete = static function ( int $term_id ) use ( &$events ): void {
			$events[] = array( 'type' => 'delete', 'id' => $term_id );
		};

		\add_action( 'wp_create_nav_menu', $create, 10, 2 );
		\add_action( 'wp_update_nav_menu', $update, 10, 2 );
		\add_action( 'wp_delete_nav_menu', $delete, 10, 1 );

		return array(
			array( 'tag' => 'wp_create_nav_menu', 'callback' => $create ),
			array( 'tag' => 'wp_update_nav_menu', 'callback' => $update ),
			array( 'tag' => 'wp_delete_nav_menu', 'callback' => $delete ),
		);
	}

	private static function remove_hooks( array $hooks ): void {
		foreach ( $hooks as $hook ) {
			\remove_action( $hook['tag'], $hook['callback'], 10 );
		}
	}

	private static function install_read_access_filter(): callable {
		$filter = static function (): bool {
			return true;
		};
		\add_filter( 'rest_menu_read_access', $filter, 10, 3 );
		return $filter;
	}

	private static function install_cap_filter( array $granted_caps ): callable {
		$granted_caps = array_fill_keys( $granted_caps, true );
		$filter       = static function ( array $allcaps, array $caps = array() ) use ( $granted_caps ): array {
			foreach ( $granted_caps as $cap => $grant ) {
				$allcaps[ $cap ] = $grant;
			}
			foreach ( $caps as $cap ) {
				if ( 'do_not_allow' !== $cap ) {
					$allcaps[ $cap ] = true;
				}
			}
			return $allcaps;
		};
		\add_filter( 'user_has_cap', $filter, 10, 4 );
		return $filter;
	}

	private static function remove_filter_handle( string $tag, callable $filter ): bool {
		\remove_filter( $tag, $filter, 10 );
		return false === \has_filter( $tag, $filter );
	}

	private static function event_ids_by_type( array $events ): array {
		$ids = array();
		foreach ( $events as $event ) {
			$type = (string) ( $event['type'] ?? '' );
			if ( '' === $type ) {
				continue;
			}
			$ids[ $type ][] = (int) ( $event['id'] ?? 0 );
		}
		ksort( $ids );
		return $ids;
	}

	private static function menu_orders_are_sequential( array $items ): bool {
		if ( array() === $items ) {
			return false;
		}

		$expected = 1;
		foreach ( $items as $item ) {
			if ( ! isset( $item->menu_order ) || (int) $item->menu_order !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	private static function setup_items_include_types( array $items, array $types ): bool {
		$seen = array();
		foreach ( $items as $item ) {
			if ( isset( $item->type ) ) {
				$seen[ (string) $item->type ] = true;
			}
		}
		foreach ( $types as $type ) {
			if ( ! isset( $seen[ $type ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function items_for_object( array $items, $object_id, string $type, string $object ): array {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $object_id, $type, $object ): bool {
					return isset( $item->object_id, $item->type, $item->object )
						&& (int) $item->object_id === (int) $object_id
						&& $type === (string) $item->type
						&& $object === (string) $item->object;
				}
			)
		);
	}

	private static function error_has_code( $value, string $code ): bool {
		return $value instanceof \WP_Error && in_array( $code, $value->get_error_codes(), true );
	}

	private static function error_status_is( $value, int $status ): bool {
		if ( ! $value instanceof \WP_Error ) {
			return false;
		}

		$data = $value->get_error_data();
		return is_array( $data ) && $status === (int) ( $data['status'] ?? 0 );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function term_list_summary( array $terms ): array {
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'   => isset( $term->term_id ) ? (int) $term->term_id : null,
				'name' => $term->name ?? null,
				'slug' => $term->slug ?? null,
			);
		}
		return $out;
	}

	private static function menu_item_list_summary( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'id'          => isset( $item->ID ) ? (int) $item->ID : null,
				'menuOrder'   => isset( $item->menu_order ) ? (int) $item->menu_order : null,
				'object'      => $item->object ?? null,
				'objectId'    => isset( $item->object_id ) ? (int) $item->object_id : null,
				'title'       => $item->title ?? ( $item->post_title ?? null ),
				'type'        => $item->type ?? null,
			);
		}
		return $out;
	}

	private static function post_list_summary( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'     => isset( $post->ID ) ? (int) $post->ID : null,
				'name'   => $post->post_name ?? null,
				'status' => $post->post_status ?? null,
				'title'  => $post->post_title ?? null,
				'type'   => $post->post_type ?? null,
			);
		}
		return $out;
	}

	private static function published_navigation_posts(): array {
		$posts = \get_posts(
			array(
				'no_found_rows'          => true,
				'numberposts'            => -1,
				'order'                  => 'ASC',
				'orderby'                => 'ID',
				'post_status'            => 'publish',
				'post_type'              => 'wp_navigation',
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	private static function top_level_block_labels( array $blocks ): array {
		$labels = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}
			$labels[] = (string) ( $block['attrs']['label'] ?? '' );
		}
		return $labels;
	}

	private static function block_by_label( array $blocks, string $label ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			if ( $label === (string) ( $block['attrs']['label'] ?? '' ) ) {
				return $block;
			}

			$inner = self::block_by_label( (array) ( $block['innerBlocks'] ?? array() ), $label );
			if ( null !== $inner ) {
				return $inner;
			}
		}

		return null;
	}

	private static function block_has_name( ?array $block, string $name ): bool {
		return is_array( $block ) && $name === ( $block['blockName'] ?? null );
	}

	private static function class_attr_contains( $class_attr, string $class_name ): bool {
		if ( ! is_string( $class_attr ) ) {
			return false;
		}

		return in_array( $class_name, preg_split( '/\s+/', trim( $class_attr ) ), true );
	}

	private static function case_summary( array $case ): array {
		return array(
			'token'           => $case['token'],
			'primaryLocation' => $case['primaryLocation'],
			'footerLocation'  => $case['footerLocation'],
		);
	}

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$after = self::snapshot_state();
		return $ctx->result(
			'navigation-lifecycle.state-restored',
			self::snapshots_match( $snapshot, $after ),
			array(
				'difference' => self::first_difference( $snapshot, $after ),
			)
		);
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'_wp_post_type_features',
					'current_user',
					'post_type_meta_caps',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_query',
					'wp_the_query',
					'wp_post_statuses',
					'wp_post_types',
					'_wp_registered_nav_menus',
					'wp_registered_settings',
					'wp_rest_additional_fields',
					'wp_rest_server',
					'wp_rewrite',
					'wp_taxonomies',
				)
			),
			'server'  => self::snapshot_server(
				array(
					'HTTP_HOST',
					'HTTP_USER_AGENT',
					'REMOTE_ADDR',
					'REQUEST_URI',
				)
			),
			'wpdb'    => self::snapshot_wpdb(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_wpdb( $snapshot['wpdb'] );
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		self::restore_globals( $snapshot['globals'] );
		self::restore_server( $snapshot['server'] );
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
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

	private static function snapshot_server( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => array_key_exists( $name, $_SERVER ) ? $_SERVER[ $name ] : null,
			);
		}
		return $snapshot;
	}

	private static function restore_server( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}
	}

	private static function snapshot_wpdb(): ?array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return null;
		}

		$wpdb       = $GLOBALS['wpdb'];
		$reflection = new \ReflectionClass( $wpdb );
		$state      = array(
			'public'  => array(
				'insert_id'     => $wpdb->insert_id,
				'last_error'    => $wpdb->last_error,
				'last_query'    => $wpdb->last_query,
				'num_rows'      => $wpdb->num_rows,
				'rows_affected' => $wpdb->rows_affected,
			),
			'private' => array(),
		);

		foreach ( $reflection->getProperties() as $property ) {
			$name = $property->getName();
			if ( str_starts_with( $name, 'component_fuzz_' ) ) {
				$state['private'][ $name ] = $property->getValue( $wpdb );
			}
		}

		return $state;
	}

	private static function restore_wpdb( ?array $snapshot ): void {
		if ( null === $snapshot || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return;
		}

		$wpdb = $GLOBALS['wpdb'];
		foreach ( $snapshot['public'] as $name => $value ) {
			$wpdb->{$name} = $value;
		}

		$reflection = new \ReflectionClass( $wpdb );
		foreach ( $snapshot['private'] as $name => $value ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}
			$property = $reflection->getProperty( $name );
			$property->setValue( $wpdb, $value );
		}
	}

	private static function snapshots_match( array $before, array $after ): bool {
		return self::stable_hash( self::summarize_for_hash( $before ) ) === self::stable_hash( self::summarize_for_hash( $after ) );
	}

	private static function first_difference( $before, $after, string $path = '' ) {
		if ( $before === $after ) {
			return null;
		}

		if ( is_array( $before ) && is_array( $after ) ) {
			$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
			foreach ( $keys as $key ) {
				$next_path = '' === $path ? (string) $key : $path . '.' . $key;
				if ( ! array_key_exists( $key, $before ) || ! array_key_exists( $key, $after ) ) {
					return array(
						'path'   => $next_path,
						'before' => array_key_exists( $key, $before ) ? self::describe_value( $before[ $key ] ) : '[missing]',
						'after'  => array_key_exists( $key, $after ) ? self::describe_value( $after[ $key ] ) : '[missing]',
					);
				}
				$diff = self::first_difference( $before[ $key ], $after[ $key ], $next_path );
				if ( null !== $diff ) {
					return $diff;
				}
			}
		}

		return array(
			'path'   => $path,
			'before' => self::describe_value( $before ),
			'after'  => self::describe_value( $after ),
		);
	}

	private static function stable_hash( $value ): ?string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? null : sha1( $json );
	}

	private static function summarize_for_hash( $value, int $depth = 0 ) {
		if ( $depth > 4 ) {
			return is_array( $value ) ? array( 'array' => count( $value ) ) : gettype( $value );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ (string) $key ] = self::summarize_for_hash( $item, $depth + 1 );
			}
			ksort( $out );
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \WP_Error ) {
				return array( 'WP_Error' => $value->get_error_codes() );
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array( 'WP_REST_Response' => self::summarize_for_hash( $value->get_data(), $depth + 1 ) );
			}
			if ( $value instanceof \Closure ) {
				return array( 'Closure' => true );
			}
			return array( 'object' => get_class( $value ) );
		}

		return $value;
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			$value = preg_replace_callback(
				'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
				static function ( array $matches ): string {
					return sprintf( '\\x%02X', ord( $matches[0] ) );
				},
				$value
			);
			return strlen( $value ) > 220 ? substr( $value, 0, 220 ) . '...' : $value;
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
				if ( $i >= 20 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : (string) $key ] = self::describe_value( $item, $depth + 1 );
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
					'codes'   => $value->get_error_codes(),
					'message' => $value->get_error_message(),
					'data'    => self::describe_value( $value->get_all_error_data(), $depth + 1 ),
				);
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array(
					'type'   => 'WP_REST_Response',
					'status' => $value->get_status(),
					'data'   => self::describe_value( $value->get_data(), $depth + 1 ),
				);
			}
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
