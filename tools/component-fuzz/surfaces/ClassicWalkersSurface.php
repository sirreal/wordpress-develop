<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes classic Walker base behavior and public classic walker subclasses.
 */
final class ClassicWalkersSurface {
	public const NAME = 'classic-walkers';

	private const PREVIEW_BYTES = 180;

	/** @var array<int,WP_Term> */
	private static array $terms_by_id = array();

	/** @var array<int,string> */
	private static array $page_links = array();

	/** @var array<int,string> */
	private static array $term_links = array();

	/** @var array<int,string> */
	private static array $comment_links = array();

	/** @var int[] */
	private static array $cached_post_ids = array();

	/** @var int[] */
	private static array $cached_term_ids = array();

	/** @var int[] */
	private static array $cached_comment_ids = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'classic-walkers.bootstrap.required-apis',
					'Required WordPress Walker APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$comment_available = self::load_comment_walker();
		$admin_available   = self::load_admin_walkers();
		$snapshot          = self::snapshot_state();
		$rows              = array();
		$cleanup           = array(
			'ok'       => false,
			'failures' => array( 'cleanup did not run' ),
		);

		try {
			self::reset_runtime();
			self::install_filters();

			$rows[] = self::check_base_walk_and_display( $ctx );
			$rows[] = self::check_paged_walk( $ctx );
			$rows[] = self::check_generated_tree_walk_and_reverse_paging( $ctx );
			$rows[] = self::check_nav_menu_walker( $ctx );
			$rows[] = self::check_nav_menu_item_matrix( $ctx );
			$rows[] = self::check_page_walker( $ctx );
			$rows[] = self::check_category_walker( $ctx );
			$rows[] = self::check_dropdown_walkers_and_filters( $ctx );

			if ( $comment_available ) {
				$rows[] = self::check_comment_walker( $ctx );
				$rows[] = self::check_comment_walker_callbacks( $ctx );
			} else {
				$rows[] = $ctx->skip(
					'classic-walkers.comment.available',
					'Walker_Comment could not be loaded from the stripped bootstrap.'
				);
			}

			if ( $admin_available ) {
				$rows[] = self::check_admin_nav_menu_walkers( $ctx );
				$rows[] = self::check_admin_nav_menu_direct_helpers( $ctx );
				$rows[] = self::check_admin_nav_menu_quick_search_and_metabox_queries(
					$ctx->fork( 'admin-nav-quick-search' )
				);
			} else {
				$rows[] = $ctx->skip(
					'classic-walkers.admin-nav.walkers-available',
					'Admin nav menu walker dependencies could not be loaded safely.'
				);
				$rows[] = $ctx->skip(
					'classic-walkers.admin-nav.direct-helper-contracts',
					'Admin nav menu helper dependencies could not be loaded safely.'
				);
				$rows[] = $ctx->skip(
					'classic-walkers.admin-nav.quick-search-metabox-queries',
					'Admin nav menu query dependencies could not be loaded safely.'
				);
			}
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'classic-walkers.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			$cleanup = self::cleanup_and_restore( $snapshot );
		}

		$rows[] = self::row(
			$ctx,
			'classic-walkers.runtime.restoration',
			$cleanup['ok'],
			array(
				'failures'      => $cleanup['failures'],
				'closedBuffers' => $cleanup['closedBuffers'] ?? 0,
				'filterLeaks'   => $cleanup['filterLeaks'] ?? array(),
			)
		);

		return $rows;
	}

	public static function filter_home_option( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );

		return 'https://example.test';
	}

	public static function filter_permalink_structure( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );

		return '';
	}

	public static function filter_zero_option( $pre_option, string $option = '', $default_value = false ): int {
		unset( $pre_option, $option, $default_value );

		return 0;
	}

	public static function filter_false_option( $pre_option, string $option = '', $default_value = false ): bool {
		unset( $pre_option, $option, $default_value );

		return false;
	}

	public static function filter_default_comments_page(
		$pre_option,
		string $option = '',
		$default_value = false
	): string {
		unset( $pre_option, $option, $default_value );

		return 'oldest';
	}

	public static function filter_comments_per_page( $pre_option, string $option = '', $default_value = false ): int {
		unset( $pre_option, $option, $default_value );

		return 50;
	}

	public static function filter_page_link( $link, $post_id, $sample = false ): string {
		unset( $sample );

		return self::$page_links[ (int) $post_id ] ?? (string) $link;
	}

	public static function filter_term_link( $link, $term, $taxonomy ): string {
		unset( $taxonomy );

		$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : 0;

		return self::$term_links[ $term_id ] ?? (string) $link;
	}

	public static function filter_comment_link( $link, $comment, $args, $cpage ): string {
		unset( $args, $cpage );

		$comment_id = is_object( $comment ) && isset( $comment->comment_ID ) ? (int) $comment->comment_ID : 0;

		return self::$comment_links[ $comment_id ] ?? (string) $link;
	}

	public static function filter_terms_pre_query( $terms, \WP_Term_Query $query ) {
		$taxonomies = (array) ( $query->query_vars['taxonomy'] ?? array() );
		if ( ! in_array( 'category', $taxonomies, true ) ) {
			return $terms;
		}

		$include = array_map( 'intval', (array) ( $query->query_vars['include'] ?? array() ) );
		if ( array() === $include ) {
			return $terms;
		}

		$matched = array();
		foreach ( $include as $term_id ) {
			if ( isset( self::$terms_by_id[ $term_id ] ) ) {
				$matched[] = self::$terms_by_id[ $term_id ];
			}
		}

		return $matched;
	}

	private static function missing_requirements(): array {
		$missing = array();

		$classes = array(
			'Walker',
			'Walker_Nav_Menu',
			'Walker_Page',
			'Walker_PageDropdown',
			'Walker_Category',
			'Walker_CategoryDropdown',
			'WP_Query',
			'WP_Rewrite',
		);

		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'apply_filters',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'esc_attr',
				'esc_html',
				'esc_textarea',
				'esc_url',
				'get_permalink',
				'get_term_link',
				'remove_filter',
				'wp_cache_delete',
				'wp_cache_set',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function load_comment_walker(): bool {
		if ( class_exists( 'Walker_Comment' ) ) {
			return true;
		}

		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			$path = ABSPATH . WPINC . '/class-walker-comment.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		return class_exists( 'Walker_Comment' );
	}

	private static function load_admin_walkers(): bool {
		if (
			class_exists( 'Walker_Nav_Menu_Checklist' )
			&& class_exists( 'Walker_Nav_Menu_Edit' )
			&& function_exists( 'wp_nav_menu_disabled_check' )
		) {
			return true;
		}

		if ( defined( 'ABSPATH' ) ) {
			$path = ABSPATH . 'wp-admin/includes/nav-menu.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		return class_exists( 'Walker_Nav_Menu_Checklist' )
			&& class_exists( 'Walker_Nav_Menu_Edit' )
			&& function_exists( 'wp_nav_menu_disabled_check' );
	}

	private static function check_base_walk_and_display( \ComponentFuzz\FuzzContext $ctx ): array {
		$nodes    = self::base_nodes( $ctx->fork( 'base-nodes' ) );
		$failures = array();

		$walk_all = self::test_walker();
		$walk_all->walk( $nodes, 0, array( 'marker' => 'walk-all' ) );
		$all_events = $walk_all->start_events();

		$walk_depth_one = self::test_walker();
		$walk_depth_one->walk( $nodes, 1, array( 'marker' => 'depth-one' ) );

		$walk_depth_two = self::test_walker();
		$walk_depth_two->walk( $nodes, 2, array( 'marker' => 'depth-two' ) );

		$walk_flat = self::test_walker();
		$walk_flat->walk( $nodes, -1, array( 'marker' => 'flat' ) );

		$rootless = self::rootless_nodes( $ctx->fork( 'rootless-nodes' ) );
		$rootless_walker = self::test_walker();
		$rootless_walker->walk( $rootless, 0, array( 'marker' => 'rootless' ) );

		$direct_children = array(
			$nodes[0]->id => array( $nodes[1] ),
		);
		$direct_output   = '';
		$direct_walker   = self::test_walker();
		$direct_walker->display_element(
			$nodes[0],
			$direct_children,
			0,
			0,
			array( array( 'marker' => 'direct' ) ),
			$direct_output
		);
		$direct_events = $direct_walker->start_events();

		self::collect_failure(
			$failures,
			self::event_ids( $all_events ) === array_column( $nodes, 'id' ),
			'walk(0) preserves preorder over rooted items followed by orphan items',
			array(
				'expected' => array_column( $nodes, 'id' ),
				'actual'   => self::event_ids( $all_events ),
			)
		);

		self::collect_failure(
			$failures,
			self::event_ids( $walk_depth_one->start_events() ) === array( $nodes[0]->id, $nodes[3]->id ),
			'walk(1) emits only top-level rooted items and drops deeper/orphan branches',
			array( 'actual' => self::event_ids( $walk_depth_one->start_events() ) )
		);

		$depth_two_expected = array(
			$nodes[0]->id,
			$nodes[1]->id,
			$nodes[3]->id,
			$nodes[4]->id,
		);

		self::collect_failure(
			$failures,
			self::event_ids( $walk_depth_two->start_events() ) === $depth_two_expected,
			'walk(2) includes children but not grandchildren or orphan nodes',
			array( 'actual' => self::event_ids( $walk_depth_two->start_events() ) )
		);

		self::collect_failure(
			$failures,
			self::event_ids( $walk_flat->start_events() ) === array_column( $nodes, 'id' )
				&& self::all_events_have_depth( $walk_flat->start_events(), 0 )
				&& ! self::any_event_has_children( $walk_flat->start_events() ),
			'walk(-1) is flat input order with no child context',
			array( 'actual' => $walk_flat->start_events() )
		);

		self::collect_failure(
			$failures,
			self::event_ids( $rootless_walker->start_events() ) === array_column( $rootless, 'id' ),
			'rootless input uses the first parent bucket as the synthetic top level',
			array( 'actual' => self::event_ids( $rootless_walker->start_events() ) )
		);

		self::collect_failure(
			$failures,
			self::has_children_by_id( $all_events ) === self::expected_has_children( $nodes ),
			'has_children and back-compat args match generated child relationships',
			array(
				'expected' => self::expected_has_children( $nodes ),
				'actual'   => self::has_children_by_id( $all_events ),
				'events'   => $all_events,
			)
		);

		self::collect_failure(
			$failures,
			array( $nodes[0]->id, $nodes[1]->id ) === self::event_ids( $direct_events )
				&& array() === $direct_children
				&& true === ( $direct_events[0]['argsHasChildren'] ?? null ),
			'direct display_element() descends, unsets consumed children, and propagates has_children',
			array(
				'directEvents'   => $direct_events,
				'childrenAfter'  => $direct_children,
				'directOutput'   => self::describe_string( $direct_output ),
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.base.walk-display-invariants',
			array() === $failures,
			array(
				'failures' => $failures,
				'rootIds'  => array_column( $nodes, 'id' ),
			)
		);
	}

	private static function check_paged_walk( \ComponentFuzz\FuzzContext $ctx ): array {
		$nodes      = self::base_nodes( $ctx->fork( 'paged-nodes' ) );
		$per_page   = $ctx->int( 1, 3 );
		$failures   = array();
		$page_ids   = array();
		$page_counts = array();
		$max_pages  = null;

		for ( $page = 1; $page <= 4; $page++ ) {
			$walker = self::test_walker();
			$walker->paged_walk( $nodes, 0, $page, $per_page, array( 'marker' => 'paged' ) );
			if ( null === $max_pages ) {
				$max_pages = $walker->max_pages;
			}

			if ( $page > $max_pages ) {
				break;
			}

			$ids                  = self::event_ids( $walker->start_events() );
			$page_ids[ $page ]    = $ids;
			$page_counts[ $page ] = count( $ids );
		}

		$combined_ids = array_merge( ...array_values( $page_ids ) );
		$expected_ids = array_column( $nodes, 'id' );

		$flat_pages = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$walker = self::test_walker();
			$walker->paged_walk( $nodes, -1, $page, 3, array( 'marker' => 'flat-paged' ) );
			$flat_pages[ $page ] = self::event_ids( $walker->start_events() );
		}

		$unpaged_walker = self::test_walker();
		$unpaged_walker->paged_walk( $nodes, 0, 0, -1, array( 'marker' => 'unpaged' ) );

		$expected_max_pages = (int) ceil( 2 / $per_page );

		self::collect_failure(
			$failures,
			$expected_max_pages === $max_pages,
			'paged_walk() reports max_pages from root element count',
			array(
				'perPage'          => $per_page,
				'expectedMaxPages' => $expected_max_pages,
				'maxPages'         => $max_pages,
			)
		);

		self::collect_failure(
			$failures,
			$expected_ids === $combined_ids
				&& count( $combined_ids ) === count( array_unique( $combined_ids ) ),
			'valid hierarchical pages preserve every generated ID exactly once',
			array(
				'expected' => $expected_ids,
				'pages'    => $page_ids,
				'combined' => $combined_ids,
			)
		);

		self::collect_failure(
			$failures,
			array_chunk( $expected_ids, 3 ) === array_values( $flat_pages ),
			'flat paged_walk(-1) partitions input order by per-page size',
			array( 'flatPages' => $flat_pages )
		);

		self::collect_failure(
			$failures,
			$expected_ids === self::event_ids( $unpaged_walker->start_events() )
				&& 1 === $unpaged_walker->max_pages,
			'page_num < 1 or per_page < 0 disables paging without dropping IDs',
			array(
				'ids'      => self::event_ids( $unpaged_walker->start_events() ),
				'maxPages' => $unpaged_walker->max_pages,
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.base.paged-walk-coherence',
			array() === $failures,
			array(
				'failures'   => $failures,
				'perPage'    => $per_page,
				'pageCounts' => $page_counts,
			)
		);
	}

	private static function check_generated_tree_walk_and_reverse_paging( \ComponentFuzz\FuzzContext $ctx ): array {
		$nodes       = self::generated_connected_nodes( $ctx->fork( 'generated-tree' ) );
		$root_ids    = self::root_ids( $nodes );
		$failures    = array();
		$walk_all    = self::test_walker();
		$walk_depth2 = self::test_walker();
		$reverse     = self::test_walker();
		$flat        = self::test_walker();
		$mutator     = self::test_walker();

		$walk_all->walk( $nodes, 0, array( 'marker' => 'generated-all' ) );
		$walk_depth2->walk( $nodes, 2, array( 'marker' => 'generated-depth2' ) );
		$reverse->paged_walk(
			$nodes,
			0,
			1,
			count( $root_ids ),
			array(
				'marker'            => 'generated-reverse',
				'reverse_top_level' => true,
				'reverse_children'  => true,
			)
		);
		$flat->paged_walk(
			$nodes,
			-1,
			1,
			count( $nodes ),
			array(
				'marker'            => 'generated-flat-reverse',
				'reverse_top_level' => true,
			)
		);

		$children_elements = self::children_buckets( $nodes );
		$first_root        = self::root_nodes( $nodes )[0];
		$removed_ids       = array_merge(
			array( (int) $first_root->id ),
			self::descendant_ids( $nodes, (int) $first_root->id )
		);
		$mutator->unset_children( $first_root, $children_elements );
		$remaining_parent_ids = array_map( 'intval', array_keys( $children_elements ) );

		self::collect_failure(
			$failures,
			count( $root_ids ) === $mutator->get_number_of_root_elements( $nodes ),
			'get_number_of_root_elements() matches generated root count',
			array(
				'expectedRoots' => $root_ids,
				'actualCount'   => $mutator->get_number_of_root_elements( $nodes ),
			)
		);

		self::collect_failure(
			$failures,
			self::event_id_depths( $walk_all->start_events() )
				=== self::expected_connected_walk_events( $nodes, 0 ),
			'walk(0) preserves generated hierarchical preorder and computed depths',
			array(
				'expected' => self::expected_connected_walk_events( $nodes, 0 ),
				'actual'   => self::event_id_depths( $walk_all->start_events() ),
			)
		);

		self::collect_failure(
			$failures,
			self::event_id_depths( $walk_depth2->start_events() )
				=== self::expected_connected_walk_events( $nodes, 2 ),
			'walk(2) cuts generated trees at the second rendered level without reordering siblings',
			array(
				'expected' => self::expected_connected_walk_events( $nodes, 2 ),
				'actual'   => self::event_id_depths( $walk_depth2->start_events() ),
			)
		);

		self::collect_failure(
			$failures,
			self::same_bool_map( self::expected_has_children( $nodes ), self::has_children_by_id( $walk_all->start_events() ) ),
			'generated has_children flags match parent buckets at every rendered depth',
			array(
				'mismatches' => self::has_children_mismatches(
					self::expected_has_children( $nodes ),
					self::has_children_by_id( $walk_all->start_events() )
				),
				'events'     => self::compact_event_summary( $walk_all->start_events() ),
				'nodes'      => self::compact_node_summary( $nodes ),
			)
		);

		self::collect_failure(
			$failures,
			self::event_id_depths( $reverse->start_events() )
				=== self::expected_connected_walk_events( $nodes, 0, true, true ),
			'paged_walk() honors reverse_top_level and reverse_children together',
			array(
				'expected' => self::expected_connected_walk_events( $nodes, 0, true, true ),
				'actual'   => self::event_id_depths( $reverse->start_events() ),
			)
		);

		self::collect_failure(
			$failures,
			self::event_ids( $flat->start_events() ) === array_reverse( array_column( $nodes, 'id' ) )
				&& self::all_events_have_depth( $flat->start_events(), 0 ),
			'paged_walk(-1) reverses generated flat input without changing depth',
			array( 'actual' => $flat->start_events() )
		);

		self::collect_failure(
			$failures,
			array() === array_values( array_intersect( $removed_ids, $remaining_parent_ids ) )
				&& array() !== $remaining_parent_ids,
			'unset_children() removes every descendant bucket for one generated root and leaves siblings intact',
			array(
				'removedIds'          => $removed_ids,
				'remainingParentIds'  => $remaining_parent_ids,
				'childrenBucketCount' => count( $children_elements ),
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.base.generated-tree-reverse-oracles',
			array() === $failures,
			array(
				'failures' => $failures,
				'nodeIds'  => array_column( $nodes, 'id' ),
				'rootIds'  => $root_ids,
			)
		);
	}

	private static function check_nav_menu_walker( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$base_id         = self::base_id( $ctx, 'nav' );
		$item_arg_calls  = array();
		$link_attr_calls = array();
		$start_el_calls  = array();
		$items           = array(
			self::menu_item( $base_id + 1, 1, 0, 'Current nav ' . $ctx->identifier( 3, 8 ), 'javascript:alert(1)', true ),
			self::menu_item(
				$base_id + 2,
				2,
				$base_id + 1,
				'Child nav ' . $ctx->identifier( 3, 8 ),
				'https://example.test/child',
				false
			),
		);
		$args     = (object) array(
			'before'       => '',
			'after'        => '',
			'link_before'  => '',
			'link_after'   => '',
			'item_spacing' => $ctx->choice( array( 'preserve', 'discard' ) ),
		);

		$item_args_filter = static function ( $args, $menu_item, int $depth ) use ( &$item_arg_calls ) {
			$item_arg_calls[] = array(
				'id'    => (int) $menu_item->ID,
				'depth' => $depth,
			);

			$args->link_before = '<span class="cfz-nav-label">';
			$args->link_after  = '</span>';

			return $args;
		};

		$link_attrs_filter = static function ( array $atts, $menu_item, $args, int $depth ) use ( &$link_attr_calls ): array {
			unset( $args );

			$link_attr_calls[] = array(
				'id'          => (int) $menu_item->ID,
				'depth'       => $depth,
				'ariaCurrent' => (string) ( $atts['aria-current'] ?? '' ),
			);

			$atts['data-cfz-nav']  = '"<nav-' . (int) $menu_item->ID . '>';
			$atts['data-cfz-drop'] = array( 'not scalar' );

			return $atts;
		};

		$start_el_filter = static function ( string $item_output, $menu_item, int $depth, $args ) use ( &$start_el_calls ): string {
			unset( $args );

			$start_el_calls[] = array(
				'id'      => (int) $menu_item->ID,
				'depth'   => $depth,
				'wrapped' => str_contains( $item_output, 'cfz-nav-label' ),
			);

			return $item_output;
		};

		$output = self::with_temporary_filters(
			array(
				array(
					'hook'         => 'nav_menu_item_args',
					'callback'     => $item_args_filter,
					'priority'     => 10,
					'acceptedArgs' => 3,
				),
				array(
					'hook'         => 'nav_menu_link_attributes',
					'callback'     => $link_attrs_filter,
					'priority'     => 10,
					'acceptedArgs' => 4,
				),
				array(
					'hook'         => 'walker_nav_menu_start_el',
					'callback'     => $start_el_filter,
					'priority'     => 10,
					'acceptedArgs' => 4,
				),
			),
			static function () use ( $items, $args ): string {
				return ( new \Walker_Nav_Menu() )->walk( $items, 0, $args );
			}
		);

		self::collect_failure(
			$failures,
			self::balanced_enough( $output, array( 'li', 'ul', 'a' ) )
				&& 2 === substr_count( $output, '<li' )
				&& str_contains( $output, '<ul class="sub-menu">' ),
			'Walker_Nav_Menu emits a balanced nested list for generated menu items',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $output, 'current-menu-item' )
				&& str_contains( $output, 'aria-current="page"' )
				&& ! str_contains( strtolower( $output ), 'javascript:' )
				&& ! str_contains( strtolower( $output ), '<script' ),
			'Walker_Nav_Menu preserves current classes and escapes unsafe URL/attribute data',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			array_column( $item_arg_calls, 'id' ) === array_column( $items, 'ID' )
				&& array_column( $link_attr_calls, 'id' ) === array_column( $items, 'ID' )
				&& array_column( $start_el_calls, 'id' ) === array_column( $items, 'ID' )
				&& array( 0, 1 ) === array_column( $item_arg_calls, 'depth' )
				&& array( 0, 1 ) === array_column( $link_attr_calls, 'depth' )
				&& array( 0, 1 ) === array_column( $start_el_calls, 'depth' )
				&& array( true, true ) === array_column( $start_el_calls, 'wrapped' )
				&& str_contains( $output, '<span class="cfz-nav-label">' )
				&& str_contains( $output, 'data-cfz-nav="&quot;&lt;nav-' )
				&& ! str_contains( $output, 'data-cfz-drop' ),
			'Walker_Nav_Menu filter callbacks receive item/depth state and escaped scalar attributes',
			array(
				'itemArgCalls'  => $item_arg_calls,
				'linkAttrCalls' => $link_attr_calls,
				'startElCalls'  => $start_el_calls,
				'output'        => self::describe_string( $output ),
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.public.nav-menu-rendering',
			array() === $failures,
			array(
				'failures' => $failures,
				'itemIds'  => array_column( $items, 'ID' ),
			)
		);
	}

	private static function check_nav_menu_item_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$base_id      = self::base_id( $ctx, 'nav-matrix' );
		$class_calls  = array();
		$id_calls     = array();
		$li_calls     = array();
		$link_calls   = array();
		$subnav_calls = array();
		$root         = self::menu_item(
			$base_id + 1,
			1,
			0,
			'Matrix root <script>alert(21)</script> ' . $ctx->identifier( 3, 8 ),
			'https://example.test/root',
			false
		);
		$parent       = self::menu_item(
			$base_id + 2,
			2,
			(int) $root->ID,
			'Matrix parent ' . $ctx->identifier( 3, 8 ),
			'https://example.test/parent',
			false
		);
		$current      = self::menu_item(
			$base_id + 3,
			3,
			(int) $parent->ID,
			'Matrix current <script>alert(22)</script> ' . $ctx->identifier( 3, 8 ),
			'https://example.test/current?x=<script>',
			true
		);
		$sibling      = self::menu_item(
			$base_id + 4,
			4,
			(int) $parent->ID,
			'Matrix sibling ' . $ctx->identifier( 3, 8 ),
			'https://example.test/sibling',
			false
		);
		$other_root   = self::menu_item(
			$base_id + 5,
			5,
			0,
			'Matrix other ' . $ctx->identifier( 3, 8 ),
			'https://example.test/other',
			false
		);

		$root->classes       = array( 'matrix-root', 'current-menu-ancestor', 'menu-item-has-children' );
		$parent->classes     = array( 'matrix-parent', 'current-menu-parent', 'menu-item-has-children' );
		$current->classes    = array( 'matrix-current', 'current-menu-item', 'cfz-unsafe<script>' );
		$sibling->classes    = array( 'matrix-sibling' );
		$other_root->classes = array( 'matrix-other-root' );

		$items          = array( $root, $parent, $current, $sibling, $other_root );
		$expected_ids   = array_map( 'intval', array_column( $items, 'ID' ) );
		$expected_depth = array( 0, 1, 2, 2, 0 );
		$current_id     = (int) $current->ID;
		$args           = (object) array(
			'before'       => '',
			'after'        => '',
			'link_before'  => '',
			'link_after'   => '',
			'item_spacing' => $ctx->choice( array( 'preserve', 'discard' ) ),
		);

		$class_filter = static function ( array $classes, $menu_item, $args, int $depth ) use ( &$class_calls ): array {
			unset( $args );

			$class_calls[] = array(
				'id'       => (int) $menu_item->ID,
				'depth'    => $depth,
				'current'  => (bool) $menu_item->current,
				'ancestor' => in_array( 'current-menu-ancestor', $classes, true ),
				'parent'   => in_array( 'current-menu-parent', $classes, true ),
			);

			$classes[] = 'cfz-matrix-depth-' . $depth;
			if ( $menu_item->current ) {
				$classes[] = 'cfz-matrix-filter-current';
			}

			return $classes;
		};

		$id_filter = static function ( string $item_id, $menu_item, $args, int $depth ) use ( &$id_calls ): string {
			unset( $args );

			$id_calls[] = array(
				'id'      => (int) $menu_item->ID,
				'depth'   => $depth,
				'default' => $item_id,
			);

			return 'cfz-li-"<' . (int) $menu_item->ID . '>-d' . $depth;
		};

		$li_attr_filter = static function ( array $atts, $menu_item, $args, int $depth ) use ( &$li_calls ): array {
			unset( $args );

			$li_calls[] = array(
				'id'         => (int) $menu_item->ID,
				'depth'      => $depth,
				'current'    => (bool) $menu_item->current,
				'defaultId'  => (string) ( $atts['id'] ?? '' ),
				'classNames' => (string) ( $atts['class'] ?? '' ),
			);

			$atts['data-cfz-depth'] = (string) $depth;
			if ( $menu_item->current ) {
				$atts['data-cfz-current'] = '"<current-' . (int) $menu_item->ID . '>';
			} else {
				$atts['data-cfz-current'] = '';
			}
			$atts['data-cfz-drop'] = array( 'not scalar' );

			return $atts;
		};

		$link_attr_filter = static function ( array $atts, $menu_item, $args, int $depth ) use ( &$link_calls ): array {
			unset( $args );

			$link_calls[] = array(
				'id'          => (int) $menu_item->ID,
				'depth'       => $depth,
				'ariaCurrent' => (string) ( $atts['aria-current'] ?? '' ),
			);

			$atts['data-cfz-link'] = '"<link-' . (int) $menu_item->ID . '>';

			return $atts;
		};

		$submenu_attr_filter = static function ( array $atts, $args, int $depth ) use ( &$subnav_calls ): array {
			unset( $args );

			$subnav_calls[] = array(
				'depth' => $depth,
				'class' => (string) ( $atts['class'] ?? '' ),
			);

			$atts['data-cfz-submenu-depth'] = (string) $depth;

			return $atts;
		};

		$output = self::with_temporary_filters(
			array(
				array(
					'hook'         => 'nav_menu_css_class',
					'callback'     => $class_filter,
					'priority'     => 10,
					'acceptedArgs' => 4,
				),
				array(
					'hook'         => 'nav_menu_item_id',
					'callback'     => $id_filter,
					'priority'     => 10,
					'acceptedArgs' => 4,
				),
				array(
					'hook'         => 'nav_menu_item_attributes',
					'callback'     => $li_attr_filter,
					'priority'     => 10,
					'acceptedArgs' => 4,
				),
				array(
					'hook'         => 'nav_menu_link_attributes',
					'callback'     => $link_attr_filter,
					'priority'     => 10,
					'acceptedArgs' => 4,
				),
				array(
					'hook'         => 'nav_menu_submenu_attributes',
					'callback'     => $submenu_attr_filter,
					'priority'     => 10,
					'acceptedArgs' => 3,
				),
			),
			static function () use ( $items, $args ): string {
				return ( new \Walker_Nav_Menu() )->walk( $items, 0, $args );
			}
		);

		$li_tags = self::opening_tags( $output, 'li' );
		$a_tags  = self::opening_tags( $output, 'a' );
		$ul_tags = self::opening_tags( $output, 'ul' );
		$opening_tag_text = implode( '', array_merge( $li_tags, $a_tags, $ul_tags ) );

		self::collect_failure(
			$failures,
			self::balanced_enough( $output, array( 'li', 'ul', 'a' ) )
				&& 5 === count( $li_tags )
				&& 5 === count( $a_tags )
				&& 2 === count( $ul_tags ),
			'Walker_Nav_Menu matrix emits balanced item/link/submenu counts for a generated hierarchy',
			array(
				'liCount' => count( $li_tags ),
				'aCount'  => count( $a_tags ),
				'ulCount' => count( $ul_tags ),
				'output'  => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			array_column( $class_calls, 'id' ) === $expected_ids
				&& array_column( $id_calls, 'id' ) === $expected_ids
				&& array_column( $li_calls, 'id' ) === $expected_ids
				&& array_column( $link_calls, 'id' ) === $expected_ids
				&& array_column( $class_calls, 'depth' ) === $expected_depth
				&& array_column( $id_calls, 'depth' ) === $expected_depth
				&& array_column( $li_calls, 'depth' ) === $expected_depth
				&& array_column( $link_calls, 'depth' ) === $expected_depth,
			'Walker_Nav_Menu matrix filters observe generated item preorder and depth',
			array(
				'classCalls' => $class_calls,
				'idCalls'    => $id_calls,
				'liCalls'    => $li_calls,
				'linkCalls'  => $link_calls,
			)
		);

		self::collect_failure(
			$failures,
			array( true, false, false, false, false ) === array_column( $class_calls, 'ancestor' )
				&& array( false, true, false, false, false ) === array_column( $class_calls, 'parent' )
				&& array( false, false, true, false, false ) === array_column( $class_calls, 'current' )
				&& array( '', '', 'page', '', '' ) === array_column( $link_calls, 'ariaCurrent' ),
			'Walker_Nav_Menu current, parent, ancestor, and aria state stay local to generated items',
			array(
				'classCalls' => $class_calls,
				'linkCalls'  => $link_calls,
			)
		);

		self::collect_failure(
			$failures,
			self::nav_menu_matrix_tags_match_state( $li_tags, $a_tags, $expected_ids, $expected_depth, $current_id )
				&& ! str_contains( strtolower( $opening_tag_text ), '<script' )
				&& ! str_contains( $output, 'data-cfz-drop' ),
			'Walker_Nav_Menu item attributes are escaped, scoped, and omit non-scalar matrix data',
			array(
				'liTags' => $li_tags,
				'aTags'  => $a_tags,
				'output' => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			array( 0, 1 ) === array_column( $subnav_calls, 'depth' )
				&& array( 'sub-menu', 'sub-menu' ) === array_column( $subnav_calls, 'class' )
				&& self::tags_contain_in_order(
					$ul_tags,
					array(
						'data-cfz-submenu-depth="0"',
						'data-cfz-submenu-depth="1"',
					)
				),
			'Walker_Nav_Menu submenu attribute filters remain depth-local for nested generated branches',
			array(
				'subnavCalls' => $subnav_calls,
				'ulTags'      => $ul_tags,
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.public.nav-menu-item-matrix-locality',
			array() === $failures,
			array(
				'failures'  => $failures,
				'itemIds'   => $expected_ids,
				'currentId' => $current_id,
			)
		);
	}

	private static function check_page_walker( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$base_id         = self::base_id( $ctx, 'page' );
		$link_attr_calls = array();
		$root            = self::page_post( $base_id + 1, 0, 'Root page ' . $ctx->identifier( 3, 8 ) );
		$child           = self::page_post( $base_id + 2, $root->ID, 'Child page ' . $ctx->identifier( 3, 8 ) );
		$sibling         = self::page_post( $base_id + 3, 0, 'Sibling page ' . $ctx->identifier( 3, 8 ) );
		$pages           = array( $root, $child, $sibling );

		foreach ( $pages as $page ) {
			self::cache_post( $page );
			self::$page_links[ (int) $page->ID ] = 'https://example.test/page-' . (int) $page->ID;
		}
		self::$page_links[ (int) $child->ID ] = 'javascript:alert(2)';

		$args = array(
			'pages_with_children' => array( (int) $root->ID => true ),
			'item_spacing'        => $ctx->choice( array( 'preserve', 'discard' ) ),
			'link_before'         => '',
			'link_after'          => '',
			'show_date'           => '',
			'date_format'         => 'Y-m-d',
		);

		$link_attrs_filter = static function (
			array $atts,
			\WP_Post $page,
			int $depth,
			array $args,
			int $current_page_id
		) use ( &$link_attr_calls ): array {
			$link_attr_calls[] = array(
				'id'                  => (int) $page->ID,
				'depth'               => $depth,
				'currentPageId'       => $current_page_id,
				'hasNormalizedBefore' => array_key_exists( 'link_before', $args ),
				'hasNormalizedAfter'  => array_key_exists( 'link_after', $args ),
			);

			$atts['data-cfz-page'] = '"<page-' . (int) $page->ID . '>';
			$atts['data-cfz-drop'] = array( 'not scalar' );

			return $atts;
		};

		$output = self::with_temporary_filters(
			array(
				array(
					'hook'         => 'page_menu_link_attributes',
					'callback'     => $link_attrs_filter,
					'priority'     => 10,
					'acceptedArgs' => 5,
				),
			),
			static function () use ( $pages, $args, $child ): string {
				return ( new \Walker_Page() )->walk( $pages, 0, $args, (int) $child->ID );
			}
		);

		self::collect_failure(
			$failures,
			self::balanced_enough( $output, array( 'li', 'ul', 'a' ) )
				&& 3 === substr_count( $output, '<li' )
				&& str_contains( $output, "class='children'" ),
			'Walker_Page emits a balanced nested children list',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $output, 'page_item_has_children' )
				&& str_contains( $output, 'current_page_item' )
				&& str_contains( $output, 'current_page_parent' )
				&& str_contains( $output, 'aria-current="page"' )
				&& ! str_contains( strtolower( $output ), 'javascript:' ),
			'Walker_Page selected/current classes and unsafe permalink escaping match generated state',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			array_column( $link_attr_calls, 'id' ) === array_column( $pages, 'ID' )
				&& array( 0, 1, 0 ) === array_column( $link_attr_calls, 'depth' )
				&& array( (int) $child->ID, (int) $child->ID, (int) $child->ID )
					=== array_column( $link_attr_calls, 'currentPageId' )
				&& array( true, true, true ) === array_column( $link_attr_calls, 'hasNormalizedBefore' )
				&& array( true, true, true ) === array_column( $link_attr_calls, 'hasNormalizedAfter' )
				&& str_contains( $output, 'data-cfz-page="&quot;&lt;page-' )
				&& ! str_contains( $output, 'data-cfz-drop' ),
			'Walker_Page link-attribute filters receive normalized args/current state and escape attributes',
			array(
				'linkAttrCalls' => $link_attr_calls,
				'output'        => self::describe_string( $output ),
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.public.page-rendering',
			array() === $failures,
			array(
				'failures'  => $failures,
				'currentId' => (int) $child->ID,
			)
		);
	}

	private static function check_category_walker( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$base_id         = self::base_id( $ctx, 'category' );
		$link_attr_calls = array();
		$root            = self::category_term(
			$base_id + 1,
			0,
			'Root <script>alert(1)</script> ' . $ctx->identifier( 3, 8 )
		);
		$child           = self::category_term(
			$base_id + 2,
			(int) $root->term_id,
			'Child <script>alert(2)</script> ' . $ctx->identifier( 3, 8 )
		);
		$terms           = array( $root, $child );

		foreach ( $terms as $term ) {
			self::cache_term( $term );
			self::$terms_by_id[ (int) $term->term_id ] = $term;
			self::$term_links[ (int) $term->term_id ]  = 'https://example.test/category-' . (int) $term->term_id;
		}
		self::$term_links[ (int) $child->term_id ] = 'javascript:alert(3)';

		$args = array(
			'style'              => 'list',
			'use_desc_for_title' => true,
			'feed_image'         => '',
			'feed'               => '',
			'feed_type'          => 'rss2',
			'show_count'         => true,
			'current_category'   => array( (int) $child->term_id ),
		);

		$link_attrs_filter = static function (
			array $atts,
			\WP_Term $category,
			int $depth,
			array $args,
			int $current_object_id
		) use ( &$link_attr_calls ): array {
			$link_attr_calls[] = array(
				'id'              => (int) $category->term_id,
				'depth'           => $depth,
				'currentObjectId' => $current_object_id,
				'currentCategory' => array_map( 'intval', (array) ( $args['current_category'] ?? array() ) ),
			);

			$atts['data-cfz-category'] = '"<category-' . (int) $category->term_id . '>';
			$atts['data-cfz-false']    = false;

			return $atts;
		};

		$output = self::with_temporary_filters(
			array(
				array(
					'hook'         => 'category_list_link_attributes',
					'callback'     => $link_attrs_filter,
					'priority'     => 10,
					'acceptedArgs' => 5,
				),
			),
			static function () use ( $terms, $args ): string {
				return ( new \Walker_Category() )->walk( $terms, 0, $args );
			}
		);

		self::collect_failure(
			$failures,
			self::balanced_enough( $output, array( 'li', 'ul', 'a' ) )
				&& 2 === substr_count( $output, '<li' )
				&& str_contains( $output, "class='children'" ),
			'Walker_Category emits a balanced nested category list',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $output, 'current-cat' )
				&& str_contains( $output, 'current-cat-parent' )
				&& str_contains( $output, 'aria-current="page"' )
				&& ! str_contains( strtolower( $output ), 'javascript:' )
				&& ! str_contains( strtolower( $output ), '<script' ),
			'Walker_Category current classes and escaped hostile term data match generated state',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			array_column( $link_attr_calls, 'id' ) === array_map( 'intval', array_column( $terms, 'term_id' ) )
				&& array( 0, 1 ) === array_column( $link_attr_calls, 'depth' )
				&& array( 0, 0 ) === array_column( $link_attr_calls, 'currentObjectId' )
				&& str_contains( $output, 'data-cfz-category="&quot;&lt;category-' )
				&& ! str_contains( $output, 'data-cfz-false' ),
			'Walker_Category link-attribute filters receive generated args/depth and escape scalar attributes',
			array(
				'linkAttrCalls' => $link_attr_calls,
				'output'        => self::describe_string( $output ),
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.public.category-rendering',
			array() === $failures,
			array(
				'failures'  => $failures,
				'currentId' => (int) $child->term_id,
			)
		);
	}

	private static function check_dropdown_walkers_and_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$base_id        = self::base_id( $ctx, 'dropdown' );
		$page_calls     = array();
		$category_calls = array();
		$page_root      = self::page_post( $base_id + 1, 0, 'Dropdown root & ' . $ctx->identifier( 3, 8 ) );
		$page_child     = self::page_post( $base_id + 2, (int) $page_root->ID, '' );
		$page_deep      = self::page_post( $base_id + 3, (int) $page_child->ID, 'Dropdown deep ' . $ctx->identifier( 3, 8 ) );
		$pages          = array( $page_root, $page_child, $page_deep );
		$term_root      = self::category_term( $base_id + 4, 0, 'Dropdown root & ' . $ctx->identifier( 3, 8 ) );
		$term_child     = self::category_term(
			$base_id + 5,
			(int) $term_root->term_id,
			'Dropdown child & ' . $ctx->identifier( 3, 8 )
		);
		$terms          = array( $term_root, $term_child );

		$page_title_filter = static function ( string $title, \WP_Post $page ) use ( &$page_calls ): string {
			$page_calls[] = array(
				'id'    => (int) $page->ID,
				'title' => $title,
			);

			return $title . ' <script>alert(11)</script>';
		};

		$category_name_filter = static function ( string $name, \WP_Term $category ) use ( &$category_calls ): string {
			$category_calls[] = array(
				'id'   => (int) $category->term_id,
				'name' => $name,
			);

			return esc_html( $name . ' & filtered' );
		};

		$outputs = self::with_temporary_filters(
			array(
				array(
					'hook'         => 'list_pages',
					'callback'     => $page_title_filter,
					'priority'     => 10,
					'acceptedArgs' => 2,
				),
				array(
					'hook'         => 'list_cats',
					'callback'     => $category_name_filter,
					'priority'     => 10,
					'acceptedArgs' => 2,
				),
			),
			static function () use ( $pages, $terms, $page_child, $term_child ): array {
				$page_output = ( new \Walker_PageDropdown() )->walk(
					$pages,
					0,
					array(
						'selected'    => (int) $page_child->ID,
						'value_field' => 'missing_field',
					)
				);

				$category_output = ( new \Walker_CategoryDropdown() )->walk(
					$terms,
					0,
					array(
						'selected'    => (string) $term_child->slug,
						'show_count'  => true,
						'value_field' => 'slug',
					)
				);

				$fallback_category_output = ( new \Walker_CategoryDropdown() )->walk(
					array( $term_child ),
					-1,
					array(
						'selected'    => (int) $term_child->term_id,
						'show_count'  => false,
						'value_field' => 'missing_field',
					)
				);

				return array(
					'page'             => $page_output,
					'category'         => $category_output,
					'categoryFallback' => $fallback_category_output,
				);
			}
		);

		$page_output              = $outputs['page'];
		$category_output          = $outputs['category'];
		$fallback_category_output = $outputs['categoryFallback'];

		self::collect_failure(
			$failures,
			self::balanced_enough( $page_output, array( 'option' ) )
				&& 3 === substr_count( $page_output, '<option' )
				&& str_contains( $page_output, 'class="level-2"' )
				&& str_contains( $page_output, 'value="' . (int) $page_child->ID . '" selected="selected"' )
				&& str_contains( $page_output, '&lt;script&gt;alert(11)&lt;/script&gt;' )
				&& ! str_contains( strtolower( $page_output ), '<script' ),
			'Walker_PageDropdown falls back to ID values, marks selected pages, and escapes filtered titles',
			array(
				'pageCalls' => $page_calls,
				'output'    => self::describe_string( $page_output ),
			)
		);

		self::collect_failure(
			$failures,
			array_column( $page_calls, 'id' ) === array_column( $pages, 'ID' )
				&& str_contains( $page_calls[1]['title'] ?? '', '(no title)' ),
			'Walker_PageDropdown list_pages filter sees generated page IDs including untitled fallbacks',
			array( 'pageCalls' => $page_calls )
		);

		self::collect_failure(
			$failures,
			self::balanced_enough( $category_output, array( 'option' ) )
				&& 2 === substr_count( $category_output, '<option' )
				&& str_contains( $category_output, 'value="' . esc_attr( $term_child->slug ) . '" selected="selected"' )
				&& str_contains( $category_output, 'class="level-1"' )
				&& str_contains( $category_output, '&amp; filtered' )
				&& str_contains( $category_output, '&nbsp;&nbsp;(3)' )
				&& str_contains( $fallback_category_output, 'value="' . (int) $term_child->term_id . '" selected="selected"' ),
			'Walker_CategoryDropdown uses requested value fields, string selected matching, counts, and fallback IDs',
			array(
				'categoryCalls'   => $category_calls,
				'output'          => self::describe_string( $category_output ),
				'fallbackOutput'  => self::describe_string( $fallback_category_output ),
				'selectedTermId'  => (int) $term_child->term_id,
				'selectedTermSlug' => (string) $term_child->slug,
			)
		);

		self::collect_failure(
			$failures,
			array_column( $category_calls, 'id' ) === array(
				(int) $term_root->term_id,
				(int) $term_child->term_id,
				(int) $term_child->term_id,
			),
			'Walker_CategoryDropdown list_cats filter fires for hierarchical and flat fallback passes',
			array( 'categoryCalls' => $category_calls )
		);

		return self::row(
			$ctx,
			'classic-walkers.public.dropdown-filter-normalization',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_comment_walker( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$base_id  = self::base_id( $ctx, 'comment' );
		$post     = self::page_post( $base_id + 1, 0, 'Comment host ' . $ctx->identifier( 3, 8 ) );
		$root     = self::comment_object( $base_id + 2, (int) $post->ID, 0, 'Root comment <script>alert(1)</script>' );
		$child    = self::comment_object(
			$base_id + 3,
			(int) $post->ID,
			(int) $root->comment_ID,
			'Child comment <script>alert(2)</script>'
		);
		$deep     = self::comment_object(
			$base_id + 4,
			(int) $post->ID,
			(int) $child->comment_ID,
			'Deep comment <script>alert(3)</script>'
		);
		$comments = array( $root, $child, $deep );

		self::cache_post( $post );
		self::$page_links[ (int) $post->ID ] = 'https://example.test/comment-host';

		foreach ( $comments as $comment ) {
			self::cache_comment( $comment );
			self::$comment_links[ (int) $comment->comment_ID ] = 'javascript:alert(4)';
		}

		$_COOKIE = array();
		$args    = array(
			'style'       => 'ol',
			'format'      => 'html5',
			'avatar_size' => 0,
			'short_ping'  => false,
			'max_depth'   => 2,
			'page'        => 1,
			'per_page'    => 0,
			'cpage'       => 1,
			'reply_text'  => 'Reply',
		);

		$output = ( new \Walker_Comment() )->walk( $comments, 2, $args );

		self::collect_failure(
			$failures,
			self::balanced_enough( $output, array( 'li', 'ol', 'article', 'a' ) )
				&& 3 === substr_count( $output, '<li' )
				&& str_contains( $output, 'class="children"' ),
			'Walker_Comment emits bounded nested comment markup and keeps too-deep descendants inline',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $output, 'parent' )
				&& ! str_contains( strtolower( $output ), 'javascript:' )
				&& ! str_contains( strtolower( $output ), '<script' ),
			'Walker_Comment parent class and pending-comment sanitization match generated state',
			array( 'output' => self::describe_string( $output ) )
		);

		return self::row(
			$ctx,
			'classic-walkers.public.comment-rendering',
			array() === $failures,
			array(
				'failures'   => $failures,
				'commentIds' => array_map( 'intval', array_column( $comments, 'comment_ID' ) ),
			)
		);
	}

	private static function check_comment_walker_callbacks( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$base_id     = self::base_id( $ctx, 'comment-callback' );
		$post        = self::page_post( $base_id + 1, 0, 'Callback host ' . $ctx->identifier( 3, 8 ) );
		$root        = self::comment_object( $base_id + 2, (int) $post->ID, 0, 'Root callback <script>alert(12)</script>' );
		$child       = self::comment_object(
			$base_id + 3,
			(int) $post->ID,
			(int) $root->comment_ID,
			'Child callback <script>alert(13)</script>'
		);
		$comments    = array( $root, $child );
		$start_calls = array();
		$end_calls   = array();

		self::cache_post( $post );
		foreach ( $comments as $comment ) {
			self::cache_comment( $comment );
		}

		$callback = static function ( \WP_Comment $comment, array $args, int $depth ) use ( &$start_calls ): void {
			$global_comment = $GLOBALS['comment'] ?? null;

			$start_calls[] = array(
				'id'          => (int) $comment->comment_ID,
				'depth'       => $depth,
				'globalId'    => is_object( $global_comment ) ? (int) $global_comment->comment_ID : 0,
				'globalDepth' => (int) ( $GLOBALS['comment_depth'] ?? -1 ),
				'style'       => (string) ( $args['style'] ?? '' ),
			);

			echo '<li class="cfz-comment-callback" data-comment-id="' . (int) $comment->comment_ID . '" data-depth="' . (int) $depth . '">';
			echo esc_html( $comment->comment_content );
		};

		$end_callback = static function ( \WP_Comment $comment, array $args, int $depth ) use ( &$end_calls ): void {
			$end_calls[] = array(
				'id'    => (int) $comment->comment_ID,
				'depth' => $depth,
				'style' => (string) ( $args['style'] ?? '' ),
			);

			echo '</li>';
		};

		$output = ( new \Walker_Comment() )->walk(
			$comments,
			0,
			array(
				'style'        => 'ul',
				'format'       => 'html5',
				'avatar_size'  => 0,
				'short_ping'   => false,
				'max_depth'    => 3,
				'callback'     => $callback,
				'end-callback' => $end_callback,
			)
		);

		self::collect_failure(
			$failures,
			self::balanced_enough( $output, array( 'li', 'ul' ) )
				&& 2 === substr_count( $output, 'class="cfz-comment-callback"' )
				&& str_contains( $output, '<ul class="children">' )
				&& str_contains( $output, '&lt;script&gt;alert(12)&lt;/script&gt;' )
				&& str_contains( $output, '&lt;script&gt;alert(13)&lt;/script&gt;' )
				&& ! str_contains( strtolower( $output ), '<script' ),
			'Walker_Comment callback output is captured, nested, balanced, and callback-escaped',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			array_column( $start_calls, 'id' ) === array_map( 'intval', array_column( $comments, 'comment_ID' ) )
				&& array( 1, 2 ) === array_column( $start_calls, 'depth' )
				&& array_column( $start_calls, 'id' ) === array_column( $start_calls, 'globalId' )
				&& array_column( $start_calls, 'depth' ) === array_column( $start_calls, 'globalDepth' )
				&& array( 'ul', 'ul' ) === array_column( $start_calls, 'style' ),
			'Walker_Comment start callbacks receive one-based depth and synchronized globals',
			array( 'startCalls' => $start_calls )
		);

		self::collect_failure(
			$failures,
			array_column( $end_calls, 'id' ) === array( (int) $child->comment_ID, (int) $root->comment_ID )
				&& array( 1, 0 ) === array_column( $end_calls, 'depth' )
				&& array( 'ul', 'ul' ) === array_column( $end_calls, 'style' ),
			'Walker_Comment end-callback receives zero-based display depths in close order',
			array( 'endCalls' => $end_calls )
		);

		return self::row(
			$ctx,
			'classic-walkers.public.comment-callbacks',
			array() === $failures,
			array(
				'failures'   => $failures,
				'commentIds' => array_map( 'intval', array_column( $comments, 'comment_ID' ) ),
			)
		);
	}

	private static function check_admin_nav_menu_walkers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures           = array();
		$base_id            = self::base_id( $ctx, 'admin-nav' );
		$custom_field_calls = array();
		$parent             = self::menu_item(
			$base_id + 1,
			1,
			0,
			'Admin <script>alert(1)</script> ' . $ctx->identifier( 3, 8 ),
			'javascript:alert(5)',
			false
		);
		$child              = self::menu_item(
			$base_id + 2,
			2,
			$base_id + 1,
			'Admin child <script>alert(2)</script> ' . $ctx->identifier( 3, 8 ),
			'https://example.test/admin-child',
			false
		);
		$items              = array( $parent, $child );

		$GLOBALS['_nav_menu_placeholder']   = 0;
		$GLOBALS['nav_menu_selected_id']    = $base_id + 50;
		$GLOBALS['one_theme_location_no_menus'] = false;
		$GLOBALS['_wp_nav_menu_max_depth']  = 0;
		$_GET                              = array();

		$custom_fields_action = static function (
			string $item_id,
			$menu_item,
			int $depth,
			$args,
			int $current_object_id
		) use ( &$custom_field_calls ): void {
			unset( $args );

			$custom_field_calls[] = array(
				'itemId'          => $item_id,
				'menuItemId'      => (int) $menu_item->ID,
				'depth'           => $depth,
				'currentObjectId' => $current_object_id,
			);

			echo '<input type="hidden" class="cfz-custom-field" name="cfz-custom['
				. esc_attr( $item_id )
				. ']" value="'
				. esc_attr( (string) $depth )
				. '" />';
		};

		$checklist_output = ( new \Walker_Nav_Menu_Checklist() )->walk( $items, 0, (object) array() );
		$edit_output      = self::with_temporary_filters(
			array(
				array(
					'hook'         => 'wp_nav_menu_item_custom_fields',
					'callback'     => $custom_fields_action,
					'priority'     => 10,
					'acceptedArgs' => 5,
				),
			),
			static function () use ( $items ): string {
				return ( new \Walker_Nav_Menu_Edit() )->walk( $items, 0, (object) array() );
			}
		);

		$object_id = (int) $parent->object_id;
		$item_id   = (int) $parent->ID;

		self::collect_failure(
			$failures,
			self::balanced_enough( $checklist_output, array( 'li', 'ul', 'label' ) )
				&& str_contains( $checklist_output, 'name="menu-item[' . $object_id . '][menu-item-object-id]"' )
				&& str_contains(
					$checklist_output,
					'name="menu-item[' . $object_id . '][menu-item-db-id]" value="' . $item_id . '"'
				)
				&& str_contains( $checklist_output, 'class="menu-item-url"' )
				&& ! str_contains( strtolower( $checklist_output ), 'javascript:' )
				&& ! str_contains( strtolower( $checklist_output ), '<script' ),
			'Walker_Nav_Menu_Checklist emits stable field names/hidden inputs and escapes hostile data',
			array(
				'objectId' => $object_id,
				'itemId'   => $item_id,
				'length'   => strlen( $checklist_output ),
			)
		);

		self::collect_failure(
			$failures,
			self::balanced_enough( $edit_output, array( 'li', 'div', 'a', 'label', 'textarea' ) )
				&& str_contains( $edit_output, 'id="menu-item-' . $item_id . '"' )
				&& str_contains( $edit_output, 'id="menu-item-settings-' . $item_id . '"' )
				&& str_contains( $edit_output, 'name="menu-item-db-id[' . $item_id . ']" value="' . $item_id . '"' )
				&& str_contains( $edit_output, 'name="menu-item-title[' . $item_id . ']"' )
				&& str_contains( $edit_output, 'name="menu-item-url[' . $item_id . ']"' )
				&& ! str_contains( strtolower( $edit_output ), 'javascript:' )
				&& ! str_contains( strtolower( $edit_output ), '<script' )
				&& 1 === $GLOBALS['_wp_nav_menu_max_depth'],
			'Walker_Nav_Menu_Edit emits stable IDs/names/hidden inputs and records generated depth',
			array(
				'itemId'   => $item_id,
				'length'   => strlen( $edit_output ),
				'maxDepth' => $GLOBALS['_wp_nav_menu_max_depth'],
			)
		);

		self::collect_failure(
			$failures,
			array_column( $custom_field_calls, 'menuItemId' ) === array_column( $items, 'ID' )
				&& array( 0, 1 ) === array_column( $custom_field_calls, 'depth' )
				&& array( 0, 0 ) === array_column( $custom_field_calls, 'currentObjectId' )
				&& 2 === substr_count( $edit_output, 'class="cfz-custom-field"' )
				&& str_contains( $edit_output, 'name="cfz-custom[' . (int) $parent->ID . ']" value="0"' )
				&& str_contains( $edit_output, 'name="cfz-custom[' . (int) $child->ID . ']" value="1"' ),
			'Walker_Nav_Menu_Edit fires custom field callbacks with generated item IDs and depths',
			array(
				'customFieldCalls' => $custom_field_calls,
				'length'           => strlen( $edit_output ),
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.admin-nav.checklist-edit-rendering',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_admin_nav_menu_direct_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_admin_nav_helper_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'classic-walkers.admin-nav.direct-helper-contracts',
				'Admin nav menu direct helper dependencies are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$failures = array();
		$base_id  = self::base_id( $ctx, 'admin-nav-helper' );

		self::reset_runtime();

		$page     = (object) array( 'name' => 'page' );
		$post     = (object) array( 'name' => 'post' );
		$category = (object) array( 'name' => 'category' );
		$product  = (object) array( 'name' => 'product_' . $ctx->identifier( 3, 8 ) );
		$nameless = (object) array( 'label' => 'nameless' );

		$page_result     = \_wp_nav_menu_meta_box_object( $page );
		$post_result     = \_wp_nav_menu_meta_box_object( $post );
		$category_result = \_wp_nav_menu_meta_box_object( $category );
		$product_result  = \_wp_nav_menu_meta_box_object( $product );
		$nameless_result = \_wp_nav_menu_meta_box_object( $nameless );

		$GLOBALS['one_theme_location_no_menus'] = false;
		$disabled_selected_zero                 = \wp_nav_menu_disabled_check( 0, false );
		$disabled_selected_menu                 = \wp_nav_menu_disabled_check( $base_id + 1, false );
		$GLOBALS['one_theme_location_no_menus'] = true;
		$disabled_one_location                  = \wp_nav_menu_disabled_check( 0, false );
		$GLOBALS['one_theme_location_no_menus'] = false;

		$GLOBALS['_nav_menu_placeholder'] = 4;
		$GLOBALS['nav_menu_selected_id']  = 0;
		ob_start();
		\wp_nav_menu_item_link_meta_box();
		$disabled_link_box = (string) ob_get_clean();
		$disabled_placeholder = $GLOBALS['_nav_menu_placeholder'];

		$GLOBALS['_nav_menu_placeholder'] = -3;
		$GLOBALS['nav_menu_selected_id']  = $base_id + 10;
		ob_start();
		\wp_nav_menu_item_link_meta_box();
		$enabled_link_box = (string) ob_get_clean();
		$enabled_placeholder = $GLOBALS['_nav_menu_placeholder'];

		$columns      = \wp_nav_menu_manage_columns();
		$column_keys  = array_keys( $columns );
		$column_values = array_values( $columns );

		self::collect_failure(
			$failures,
			$page === $page_result
				&& array(
					'orderby'     => 'menu_order title',
					'post_status' => 'publish',
				) === ( $page_result->_default_query ?? null )
				&& $post === $post_result
				&& array( 'post_status' => 'publish' ) === ( $post_result->_default_query ?? null )
				&& $category === $category_result
				&& array(
					'orderby' => 'id',
					'order'   => 'DESC',
				) === ( $category_result->_default_query ?? null )
				&& $product === $product_result
				&& array( 'post_status' => 'publish' ) === ( $product_result->_default_query ?? null )
				&& $nameless === $nameless_result
				&& ! isset( $nameless_result->_default_query ),
			'_wp_nav_menu_meta_box_object() mutates known post type and taxonomy objects with their exact default query shapes',
			array(
				'page'     => $page_result->_default_query ?? null,
				'post'     => $post_result->_default_query ?? null,
				'category' => $category_result->_default_query ?? null,
				'product'  => $product_result->_default_query ?? null,
				'nameless' => isset( $nameless_result->_default_query ),
			)
		);

		self::collect_failure(
			$failures,
			" disabled='disabled'" === $disabled_selected_zero
				&& '' === $disabled_selected_menu
				&& false === $disabled_one_location,
			'wp_nav_menu_disabled_check() matches disabled(), selected-menu, and one-location-no-menus bypass contracts',
			array(
				'selectedZero' => $disabled_selected_zero,
				'selectedMenu' => $disabled_selected_menu,
				'oneLocation'  => $disabled_one_location,
			)
		);

		self::collect_failure(
			$failures,
			-1 === $disabled_placeholder
				&& str_contains( $disabled_link_box, '<div class="customlinkdiv" id="customlinkdiv">' )
				&& str_contains( $disabled_link_box, 'name="menu-item[-1][menu-item-type]"' )
				&& str_contains( $disabled_link_box, 'name="menu-item[-1][menu-item-url]"' )
				&& str_contains( $disabled_link_box, 'name="menu-item[-1][menu-item-title]"' )
				&& str_contains( $disabled_link_box, 'name="add-custom-menu-item"' )
				&& 3 === substr_count( $disabled_link_box, " disabled='disabled'" )
				&& ! str_contains( strtolower( $disabled_link_box ), '<script' ),
			'wp_nav_menu_item_link_meta_box() initializes a new negative placeholder and disables URL/title/submit controls when no menu is selected',
			array(
				'placeholder' => $disabled_placeholder,
				'output'      => self::describe_string( $disabled_link_box ),
			)
		);

		self::collect_failure(
			$failures,
			-4 === $enabled_placeholder
				&& str_contains( $enabled_link_box, 'name="menu-item[-4][menu-item-type]"' )
				&& str_contains( $enabled_link_box, 'id="custom-menu-item-url"' )
				&& str_contains( $enabled_link_box, 'placeholder="https://"' )
				&& str_contains( $enabled_link_box, 'class="button submit-add-to-menu right"' )
				&& ! str_contains( $enabled_link_box, " disabled='disabled'" )
				&& ! str_contains( strtolower( $enabled_link_box ), '<script' ),
			'wp_nav_menu_item_link_meta_box() decrements existing negative placeholders and leaves controls enabled for a selected menu',
			array(
				'placeholder' => $enabled_placeholder,
				'output'      => self::describe_string( $enabled_link_box ),
			)
		);

		self::collect_failure(
			$failures,
			array( '_title', 'cb', 'link-target', 'title-attribute', 'css-classes', 'xfn', 'description' ) === $column_keys
				&& '<input type="checkbox" />' === ( $columns['cb'] ?? null )
				&& count( $columns ) === count( array_filter( $column_values, 'is_string' ) )
				&& ! str_contains( strtolower( implode( ' ', $column_values ) ), '<script' ),
			'wp_nav_menu_manage_columns() returns the stable advanced-property column keys and safe checkbox column markup',
			array( 'columns' => $columns )
		);

		return self::row(
			$ctx,
			'classic-walkers.admin-nav.direct-helper-contracts',
			array() === $failures,
			array(
				'failures' => $failures,
				'covered'  => array(
					'_wp_nav_menu_meta_box_object',
					'wp_nav_menu_disabled_check',
					'wp_nav_menu_item_link_meta_box',
					'wp_nav_menu_manage_columns',
				),
				'notCovered' => array(
					'browser admin page dispatch',
				),
			)
		);
	}

	private static function check_admin_nav_menu_quick_search_and_metabox_queries(
		\ComponentFuzz\FuzzContext $ctx
	): array {
		$missing = self::missing_admin_nav_query_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'classic-walkers.admin-nav.quick-search-metabox-queries',
				'Admin nav menu quick-search and meta-box query dependencies are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$failures          = array();
		$query_events      = array();
		$term_query_events = array();
		$base_id           = self::base_id( $ctx, 'admin-nav-query' );
		$suffix            = substr( sha1( (string) $ctx->seed() . '|' . (string) $ctx->iteration() ), 0, 10 );
		$post_type         = 'cfznav' . $suffix;
		$taxonomy          = 'cfznavtax' . $suffix;
		$post_search       = 'needle-' . substr( $suffix, 0, 6 );
		$term_search       = 'facet-' . substr( $suffix, 0, 6 );
		$posts             = self::nav_query_posts( $base_id, $post_type, $post_search );
		$terms             = self::nav_query_terms( $base_id + 5000, $taxonomy, $term_search );
		$post_filter       = static function ( $pre, \WP_Query $query ) use ( $post_type, $posts, &$query_events ) {
			unset( $pre );

			$query_vars = $query->query_vars;
			if ( ! self::query_targets_post_type( $query_vars, $post_type ) ) {
				return null;
			}

			$query->query_vars['update_post_meta_cache'] = false;
			$query->query_vars['update_post_term_cache'] = false;
			$matched = self::select_nav_posts( $posts, $query_vars );
			$query->found_posts   = count( $matched['all'] );
			$query->max_num_pages = (int) ceil( count( $matched['all'] ) / max( 1, (int) ( $query_vars['posts_per_page'] ?? count( $matched['all'] ) ) ) );
			$query->post_count    = count( $matched['page'] );

			$query_events[] = array(
				'postType'     => $post_type,
				'postsPerPage' => (int) ( $query_vars['posts_per_page'] ?? 0 ),
				'offset'       => (int) ( $query_vars['offset'] ?? 0 ),
				'orderby'      => $query_vars['orderby'] ?? '',
				'order'        => $query_vars['order'] ?? '',
				'search'       => (string) ( $query_vars['s'] ?? '' ),
				'noFoundRows'  => (bool) ( $query_vars['no_found_rows'] ?? false ),
				'searchCols'   => array_values( (array) ( $query_vars['search_columns'] ?? array() ) ),
				'found'        => count( $matched['all'] ),
				'ids'          => self::post_ids( $matched['page'] ),
			);

			return $matched['page'];
		};
		$term_filter       = static function ( $pre, \WP_Term_Query $query ) use ( $taxonomy, $terms, &$term_query_events ) {
			unset( $pre );

			$query_vars = $query->query_vars;
			if ( ! self::query_targets_taxonomy( $query_vars, $taxonomy ) ) {
				return null;
			}

			$matched = self::select_nav_terms( $terms, $query_vars );
			$fields  = (string) ( $query_vars['fields'] ?? 'all' );

			$term_query_events[] = array(
				'taxonomy' => $taxonomy,
				'fields'   => $fields,
				'number'   => (int) ( $query_vars['number'] ?? 0 ),
				'offset'   => (int) ( $query_vars['offset'] ?? 0 ),
				'orderby'  => $query_vars['orderby'] ?? '',
				'order'    => $query_vars['order'] ?? '',
				'nameLike' => (string) ( $query_vars['name__like'] ?? '' ),
				'found'    => count( $matched['all'] ),
				'ids'      => self::term_ids( $matched['page'] ),
			);

			if ( 'count' === $fields ) {
				return (string) count( $matched['all'] );
			}

			return $matched['page'];
		};

		self::reset_runtime();
		$post_types_before = $GLOBALS['wp_post_types'] ?? array();
		$taxonomies_before = $GLOBALS['wp_taxonomies'] ?? array();
		\register_post_type(
			$post_type,
			array(
				'public'            => true,
				'show_in_nav_menus' => true,
				'hierarchical'      => true,
				'has_archive'       => true,
				'labels'            => array(
					'name'          => 'Fuzz nav posts <script>',
					'all_items'     => 'All fuzz nav posts <script>',
					'archives'      => 'Fuzz archive <script>',
					'search_items'  => 'Search fuzz posts <script>',
					'singular_name' => 'Fuzz nav post',
				),
			)
		);
		\register_taxonomy(
			$taxonomy,
			array( $post_type ),
			array(
				'public'            => true,
				'show_in_nav_menus' => true,
				'hierarchical'      => true,
				'labels'            => array(
					'name'         => 'Fuzz nav terms <script>',
					'all_items'    => 'All fuzz nav terms <script>',
					'most_used'    => 'Most used fuzz terms <script>',
					'search_items' => 'Search fuzz terms <script>',
				),
			)
		);

		foreach ( $posts as $post ) {
			self::cache_post( $post );
			self::$page_links[ (int) $post->ID ] = 'https://example.test/' . $post_type . '/' . $post->post_name . '/';
		}
		foreach ( $terms as $term ) {
			self::cache_term( $term );
			self::$terms_by_id[ (int) $term->term_id ] = $term;
			self::$term_links[ (int) $term->term_id ] = 'https://example.test/' . $taxonomy . '/' . $term->slug . '/';
		}

		\add_filter( 'posts_pre_query', $post_filter, 10, 2 );
		\add_filter( 'terms_pre_query', $term_filter, 9, 2 );

		try {
			$GLOBALS['nav_menu_selected_id'] = $base_id + 9000;
			$GLOBALS['_nav_menu_placeholder'] = 0;
			$_SERVER['REQUEST_URI'] = '/wp-admin/nav-menus.php?action=edit&menu=' . ( $base_id + 9000 );

			$post_quick_output = self::capture_output(
				static function () use ( $post_type, $post_search ): void {
					\_wp_ajax_menu_quick_search(
						array(
							'type'            => 'quick-search-posttype-' . $post_type,
							'object_type'     => $post_type,
							'q'               => $post_search,
							'response-format' => 'bad-format',
						)
					);
				}
			);
			$post_quick_json   = self::decode_json_lines( $post_quick_output );
			$post_quick_ids    = array_map( 'intval', array_column( $post_quick_json, 'ID' ) );

			$term_quick_output = self::capture_output(
				static function () use ( $taxonomy, $term_search ): void {
					\_wp_ajax_menu_quick_search(
						array(
							'type'            => 'quick-search-taxonomy-' . $taxonomy,
							'object_type'     => $taxonomy,
							'q'               => $term_search,
							'response-format' => 'json',
						)
					);
				}
			);
			$term_quick_json   = self::decode_json_lines( $term_quick_output );
			$term_quick_ids    = array_map( 'intval', array_column( $term_quick_json, 'ID' ) );

			$get_post_item_output = self::capture_output(
				static function () use ( $post_type, $posts ): void {
					\_wp_ajax_menu_quick_search(
						array(
							'type'            => 'get-post-item',
							'object_type'     => $post_type,
							'ID'              => (string) $posts[3]->ID,
							'response-format' => 'json',
						)
					);
				}
			);
			$get_post_item_json = self::decode_json_lines( $get_post_item_output );

			$get_term_item_markup = self::capture_output(
				static function () use ( $taxonomy, $terms ): void {
					\_wp_ajax_menu_quick_search(
						array(
							'type'            => 'get-post-item',
							'object_type'     => $taxonomy,
							'ID'              => (string) $terms[4]->term_id,
							'response-format' => 'markup',
						)
					);
				}
			);

			$_GET = array(
				$post_type . '-tab' => 'all',
				'paged'             => '2',
				'item-type'         => 'post_type',
				'item-object'       => $post_type,
			);
			$_POST    = array();
			$_REQUEST = $_GET;
			$post_all_output = self::capture_output(
				static function () use ( $post_type ): void {
					\wp_nav_menu_item_post_type_meta_box(
						null,
						array(
							'id'    => 'add-post-type-' . $post_type,
							'title' => 'Fuzz posts',
							'args'  => \_wp_nav_menu_meta_box_object( \get_post_type_object( $post_type ) ),
						)
					);
				}
			);
			$post_all_placeholder = (int) $GLOBALS['_nav_menu_placeholder'];
			$post_page_two        = self::select_nav_posts(
				$posts,
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'offset'         => 50,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			$_GET = array(
				'quick-search-posttype-' . $post_type => $post_search,
				$post_type . '-tab'                   => 'search',
				'item-type'                           => 'post_type',
				'item-object'                         => $post_type,
			);
			$_REQUEST = $_GET;
			$post_search_output = self::capture_output(
				static function () use ( $post_type ): void {
					\wp_nav_menu_item_post_type_meta_box(
						null,
						array(
							'id'    => 'add-post-type-' . $post_type,
							'title' => 'Fuzz posts',
							'args'  => \_wp_nav_menu_meta_box_object( \get_post_type_object( $post_type ) ),
						)
					);
				}
			);

			$_GET = array(
				$taxonomy . '-tab' => 'all',
				'paged'           => '2',
				'item-type'       => 'taxonomy',
				'item-object'     => $taxonomy,
			);
			$_REQUEST = $_GET;
			$term_all_output = self::capture_output(
				static function () use ( $taxonomy ): void {
					\wp_nav_menu_item_taxonomy_meta_box(
						null,
						array(
							'id'    => 'add-taxonomy-' . $taxonomy,
							'title' => 'Fuzz terms',
							'args'  => \get_taxonomy( $taxonomy ),
						)
					);
				}
			);
			$term_page_two   = self::select_nav_terms(
				$terms,
				array(
					'taxonomy' => $taxonomy,
					'number'   => 50,
					'offset'   => 50,
					'orderby'  => 'name',
					'order'    => 'ASC',
				)
			);

			$_GET = array(
				'quick-search-taxonomy-' . $taxonomy => $term_search,
				$taxonomy . '-tab'                   => 'search',
				'item-type'                          => 'taxonomy',
				'item-object'                        => $taxonomy,
			);
			$_REQUEST = $_GET;
			$term_search_output = self::capture_output(
				static function () use ( $taxonomy ): void {
					\wp_nav_menu_item_taxonomy_meta_box(
						null,
						array(
							'id'    => 'add-taxonomy-' . $taxonomy,
							'title' => 'Fuzz terms',
							'args'  => \get_taxonomy( $taxonomy ),
						)
					);
				}
			);
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				'nav menu quick-search and meta-box query coverage does not throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			\remove_filter( 'terms_pre_query', $term_filter, 9 );
			\remove_filter( 'posts_pre_query', $post_filter, 10 );
			$GLOBALS['wp_post_types'] = $post_types_before;
			$GLOBALS['wp_taxonomies'] = $taxonomies_before;
		}

		$post_quick_event = self::first_query_event(
			$query_events,
			static function ( array $event ) use ( $post_search ): bool {
				return 10 === (int) $event['postsPerPage']
					&& $post_search === $event['search']
					&& true === $event['noFoundRows'];
			}
		);
		$post_page_event = self::first_query_event(
			$query_events,
			static function ( array $event ): bool {
				return 50 === (int) $event['postsPerPage']
					&& 50 === (int) $event['offset']
					&& 'title' === $event['orderby'];
			}
		);
		$term_quick_event = self::first_query_event(
			$term_query_events,
			static function ( array $event ) use ( $term_search ): bool {
				return 10 === (int) $event['number']
					&& $term_search === $event['nameLike'];
			}
		);
		$term_count_event = self::first_query_event(
			$term_query_events,
			static function ( array $event ): bool {
				return 'count' === $event['fields']
					&& 0 === (int) $event['number'];
			}
		);
		$post_search_event = self::first_query_event(
			$query_events,
			static function ( array $event ) use ( $post_search ): bool {
				return $post_search === $event['search']
					&& 10 !== (int) $event['postsPerPage'];
			}
		);
		$term_search_event = self::first_query_event(
			$term_query_events,
			static function ( array $event ) use ( $term_search ): bool {
				return $term_search === $event['nameLike']
					&& 'count' === $event['orderby'];
			}
		);

		self::collect_failure(
			$failures,
			( $post_quick_event['ids'] ?? array() ) === $post_quick_ids
				&& 10 === count( $post_quick_json ?? array() )
				&& array_fill( 0, 10, $post_type ) === array_values( array_column( $post_quick_json ?? array(), 'post_type' ) )
				&& array( 'post_title' ) === ( $post_quick_event['searchCols'] ?? null ),
			'_wp_ajax_menu_quick_search() returns bounded post-type JSON results and preserves the search-column query contract',
			array(
				'ids'        => $post_quick_ids ?? array(),
				'event'      => $post_quick_event,
				'jsonSample' => $post_quick_json[0] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			( $term_quick_event['ids'] ?? array() ) === $term_quick_ids
				&& 10 === count( $term_quick_json ?? array() )
				&& array_fill( 0, 10, $taxonomy ) === array_values( array_column( $term_quick_json ?? array(), 'post_type' ) )
				&& 10 === (int) ( $term_quick_event['number'] ?? 0 ),
			'_wp_ajax_menu_quick_search() returns bounded taxonomy JSON results through get_terms()',
			array(
				'ids'        => $term_quick_ids ?? array(),
				'event'      => $term_quick_event,
				'jsonSample' => $term_quick_json[0] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			(int) ( $get_post_item_json[0]['ID'] ?? 0 ) === (int) $posts[3]->ID
				&& $post_type === ( $get_post_item_json[0]['post_type'] ?? null )
				&& self::contains_nav_menu_object_id( $get_term_item_markup ?? '', (int) $terms[4]->term_id )
				&& str_contains( $get_term_item_markup ?? '', 'value="' . esc_attr( $taxonomy ) . '"' )
				&& ! str_contains( strtolower( $get_term_item_markup ?? '' ), '<script' ),
			'get-post-item quick-search branches return direct post JSON and escaped taxonomy checklist markup',
			array(
				'postJson'   => $get_post_item_json[0] ?? null,
				'termMarkup' => self::describe_string( $get_term_item_markup ?? '' ),
			)
		);

		self::collect_failure(
			$failures,
			null !== $post_page_event
				&& $post_all_placeholder < -1
				&& str_contains( $post_all_output ?? '', 'id="' . esc_attr( $post_type . '-all' ) . '"' )
				&& str_contains( $post_all_output ?? '', 'tabs-panel-view-all tabs-panel-active' )
				&& str_contains( $post_all_output ?? '', 'class="add-menu-item-pagelinks"' )
				&& str_contains( $post_all_output ?? '', 'value="post_type_archive"' )
				&& str_contains( $post_all_output ?? '', 'value="' . esc_attr( $post_type ) . '"' )
				&& self::contains_nav_menu_object_id( $post_all_output ?? '', (int) $post_page_two['page'][0]->ID )
				&& ! str_contains( strtolower( $post_all_output ?? '' ), '<script' ),
			'wp_nav_menu_item_post_type_meta_box() renders archive placeholder, page-2 post checklist, and pagination without raw script',
			array(
				'event'       => $post_page_event,
				'pageTwoIds'  => self::post_ids( $post_page_two['page'] ?? array() ),
				'placeholder' => $post_all_placeholder ?? null,
				'output'      => self::describe_string( $post_all_output ?? '' ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $post_search_output ?? '', 'tabs-panel-active' )
				&& str_contains( $post_search_output ?? '', 'value="' . esc_attr( $post_search ) . '"' )
				&& str_contains( $post_search_output ?? '', 'id="submit-quick-search-posttype-' . esc_attr( $post_type ) . '"' )
				&& self::contains_nav_menu_object_id( $post_search_output ?? '', (int) ( $post_search_event['ids'][0] ?? 0 ) )
				&& ! str_contains( strtolower( $post_search_output ?? '' ), '<script' ),
			'wp_nav_menu_item_post_type_meta_box() activates search output from quick-search request state and escapes labels/results',
			array(
				'event'  => $post_search_event,
				'output' => self::describe_string( $post_search_output ?? '' ),
			)
		);

		self::collect_failure(
			$failures,
			null !== $term_count_event
				&& str_contains( $term_all_output ?? '', 'id="' . esc_attr( $taxonomy . 'checklist' ) . '"' )
				&& str_contains( $term_all_output ?? '', 'tabs-panel-view-all tabs-panel-active' )
				&& str_contains( $term_all_output ?? '', 'class="add-menu-item-pagelinks"' )
				&& self::contains_nav_menu_object_id( $term_all_output ?? '', (int) $term_page_two['page'][0]->term_id )
				&& ! str_contains( strtolower( $term_all_output ?? '' ), '<script' ),
			'wp_nav_menu_item_taxonomy_meta_box() renders counted page-2 term checklist and pagination without raw script',
			array(
				'countEvent' => $term_count_event,
				'pageTwoIds' => self::term_ids( $term_page_two['page'] ?? array() ),
				'output'     => self::describe_string( $term_all_output ?? '' ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $term_search_output ?? '', 'tabs-panel-active' )
				&& str_contains( $term_search_output ?? '', 'value="' . esc_attr( $term_search ) . '"' )
				&& str_contains( $term_search_output ?? '', 'id="submit-quick-search-taxonomy-' . esc_attr( $taxonomy ) . '"' )
				&& self::contains_nav_menu_object_id( $term_search_output ?? '', (int) ( $term_search_event['ids'][0] ?? 0 ) )
				&& ! str_contains( strtolower( $term_search_output ?? '' ), '<script' ),
			'wp_nav_menu_item_taxonomy_meta_box() activates search output from quick-search request state and escapes labels/results',
			array(
				'event'  => $term_search_event,
				'output' => self::describe_string( $term_search_output ?? '' ),
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'posts_pre_query', $post_filter )
				&& false === \has_filter( 'terms_pre_query', $term_filter )
				&& ! \post_type_exists( $post_type )
				&& ! \taxonomy_exists( $taxonomy ),
			'nav menu quick-search query fixtures remove scoped filters and temporary object types',
			array(
				'postFilter' => \has_filter( 'posts_pre_query', $post_filter ),
				'termFilter' => \has_filter( 'terms_pre_query', $term_filter ),
				'postType'   => \post_type_exists( $post_type ),
				'taxonomy'   => \taxonomy_exists( $taxonomy ),
			)
		);

		return self::row(
			$ctx,
			'classic-walkers.admin-nav.quick-search-metabox-queries',
			array() === $failures,
			array(
				'failures'         => $failures,
				'postQueryEvents'  => $query_events,
				'termQueryEvents'  => $term_query_events,
				'covered'          => array(
					'_wp_ajax_menu_quick_search',
					'wp_nav_menu_item_post_type_meta_box',
					'wp_nav_menu_item_taxonomy_meta_box',
					'Walker_Nav_Menu_Checklist query-backed rendering',
				),
				'notCovered'       => array( 'browser admin page dispatch' ),
			)
		);
	}

	private static function missing_admin_nav_query_requirements(): array {
		$missing = array();
		foreach ( array( 'WP_Query', 'WP_Term', 'Walker_Nav_Menu_Checklist' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_ajax_menu_quick_search',
				'_wp_nav_menu_meta_box_object',
				'get_post_type_object',
				'get_taxonomy',
				'post_type_exists',
				'register_post_type',
				'register_taxonomy',
				'taxonomy_exists',
				'walk_nav_menu_tree',
				'wp_nav_menu_item_post_type_meta_box',
				'wp_nav_menu_item_taxonomy_meta_box',
				'wp_json_encode',
				'wp_setup_nav_menu_item',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function missing_admin_nav_helper_requirements(): array {
		$missing = array();
		foreach (
			array(
				'_wp_nav_menu_meta_box_object',
				'wp_nav_menu_disabled_check',
				'wp_nav_menu_item_link_meta_box',
				'wp_nav_menu_manage_columns',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	/**
	 * @return array<int,object>
	 */
	private static function base_nodes( \ComponentFuzz\FuzzContext $ctx ): array {
		$base = self::base_id( $ctx, 'base-tree' );

		return array(
			self::node( $base + 1, 0 ),
			self::node( $base + 2, $base + 1 ),
			self::node( $base + 3, $base + 2 ),
			self::node( $base + 4, 0 ),
			self::node( $base + 5, $base + 4 ),
			self::node( $base + 6, $base + 999 ),
			self::node( $base + 7, $base + 998 ),
		);
	}

	/**
	 * @return array<int,object>
	 */
	private static function rootless_nodes( \ComponentFuzz\FuzzContext $ctx ): array {
		$base = self::base_id( $ctx, 'rootless-tree' );

		return array(
			self::node( $base + 1, $base + 50 ),
			self::node( $base + 2, $base + 1 ),
			self::node( $base + 3, $base + 50 ),
		);
	}

	/**
	 * @return array<int,object>
	 */
	private static function generated_connected_nodes( \ComponentFuzz\FuzzContext $ctx ): array {
		$base           = self::base_id( $ctx, 'generated-connected-tree' );
		$nodes          = array();
		$next_offset    = 1;
		$root_count     = $ctx->int( 2, 4 );
		$first_child_id = null;
		$has_grandchild = false;

		for ( $root_index = 0; $root_index < $root_count; $root_index++ ) {
			$root_id = $base + $next_offset;
			++$next_offset;
			$nodes[] = self::node( $root_id, 0 );

			$child_count = $ctx->int( 1, 3 );
			for ( $child_index = 0; $child_index < $child_count; $child_index++ ) {
				$child_id = $base + $next_offset;
				++$next_offset;
				$nodes[] = self::node( $child_id, $root_id );

				if ( null === $first_child_id ) {
					$first_child_id = $child_id;
				}

				$grandchild_count = $ctx->int( 0, 2 );
				for ( $grandchild_index = 0; $grandchild_index < $grandchild_count; $grandchild_index++ ) {
					$grandchild_id = $base + $next_offset;
					++$next_offset;
					$nodes[]        = self::node( $grandchild_id, $child_id );
					$has_grandchild = true;
				}
			}
		}

		if ( ! $has_grandchild && null !== $first_child_id ) {
			$nodes[] = self::node( $base + $next_offset, $first_child_id );
		}

		return $nodes;
	}

	/**
	 * @param array<int,object> $nodes Nodes with id and parent properties.
	 * @return int[]
	 */
	private static function root_ids( array $nodes ): array {
		return array_map(
			static function ( object $node ): int {
				return (int) $node->id;
			},
			self::root_nodes( $nodes )
		);
	}

	/**
	 * @param array<int,object> $nodes Nodes with id and parent properties.
	 * @return array<int,object>
	 */
	private static function root_nodes( array $nodes ): array {
		$roots = array();
		foreach ( $nodes as $node ) {
			if ( empty( $node->parent ) ) {
				$roots[] = $node;
			}
		}

		return $roots;
	}

	/**
	 * @param array<int,object> $nodes Nodes with id and parent properties.
	 * @return array<int,array<int,object>>
	 */
	private static function children_buckets( array $nodes ): array {
		$children = array();
		foreach ( $nodes as $node ) {
			$parent = (int) $node->parent;
			if ( 0 !== $parent ) {
				$children[ $parent ][] = $node;
			}
		}

		return $children;
	}

	/**
	 * @param array<int,object> $nodes Nodes with id and parent properties.
	 * @return int[]
	 */
	private static function descendant_ids( array $nodes, int $parent_id ): array {
		$children    = self::children_buckets( $nodes );
		$descendants = array();

		self::collect_descendant_ids( $parent_id, $children, $descendants );

		return $descendants;
	}

	/**
	 * @param array<int,array<int,object>> $children Children grouped by parent ID.
	 * @param int[]                       $descendants Descendant IDs.
	 */
	private static function collect_descendant_ids( int $parent_id, array $children, array &$descendants ): void {
		foreach ( $children[ $parent_id ] ?? array() as $child ) {
			$child_id      = (int) $child->id;
			$descendants[] = $child_id;
			self::collect_descendant_ids( $child_id, $children, $descendants );
		}
	}

	/**
	 * @param array<int,object> $nodes Nodes with id and parent properties.
	 * @return array<int,array{id:int,depth:int}>
	 */
	private static function expected_connected_walk_events(
		array $nodes,
		int $max_depth,
		bool $reverse_top_level = false,
		bool $reverse_children = false
	): array {
		$children = self::children_buckets( $nodes );
		$roots    = self::root_nodes( $nodes );
		$events   = array();

		if ( $reverse_top_level ) {
			$roots = array_reverse( $roots );
		}

		foreach ( $roots as $root ) {
			self::append_expected_connected_walk_events( $root, $children, $max_depth, 0, $reverse_children, $events );
		}

		return $events;
	}

	/**
	 * @param array<int,array<int,object>> $children Children grouped by parent ID.
	 * @param array<int,array{id:int,depth:int}> $events Expected event records.
	 */
	private static function append_expected_connected_walk_events(
		object $node,
		array $children,
		int $max_depth,
		int $depth,
		bool $reverse_children,
		array &$events
	): void {
		$node_id  = (int) $node->id;
		$events[] = array(
			'id'    => $node_id,
			'depth' => $depth,
		);

		if ( 0 !== $max_depth && $max_depth <= $depth + 1 ) {
			return;
		}

		$child_nodes = $children[ $node_id ] ?? array();
		if ( $reverse_children ) {
			$child_nodes = array_reverse( $child_nodes );
		}

		foreach ( $child_nodes as $child ) {
			self::append_expected_connected_walk_events( $child, $children, $max_depth, $depth + 1, $reverse_children, $events );
		}
	}

	private static function node( int $id, int $parent ): object {
		return (object) array(
			'id'     => $id,
			'parent' => $parent,
		);
	}

	private static function compact_node_summary( array $nodes ): string {
		return implode(
			',',
			array_map(
				static function ( object $node ): string {
					return (int) $node->id . '>' . (int) $node->parent;
				},
				$nodes
			)
		);
	}

	private static function menu_item(
		int $id,
		int $order,
		int $parent,
		string $title,
		string $url,
		bool $current
	): object {
		$classes = $current
			? array( 'current-menu-item', 'menu-item-has-children' )
			: array( 'child-class<script>' );

		return (object) array(
			'ID'               => $id,
			'db_id'            => $id,
			'menu_order'       => $order,
			'menu_item_parent' => $parent,
			'object_id'        => $id + 1000,
			'object'           => 'custom',
			'type'             => 'custom',
			'type_label'       => 'Custom Link',
			'post_parent'      => 0,
			'post_type'        => 'nav_menu_item',
			'post_status'      => 'publish',
			'post_title'       => $title,
			'post_excerpt'     => 'Excerpt <script>alert(8)</script>',
			'title'            => $title,
			'label'            => $title,
			'url'              => $url,
			'target'           => '',
			'attr_title'       => 'Attr <script>alert(6)</script>',
			'description'      => 'Description <script>alert(7)</script>',
			'classes'          => $classes,
			'xfn'              => 'friend <script>',
			'current'          => $current,
		);
	}

	private static function page_post( int $id, int $parent, string $title ): \WP_Post {
		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => '0',
				'post_date'             => '2026-06-23 10:00:00',
				'post_date_gmt'         => '2026-06-23 10:00:00',
				'post_content'          => '',
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'cfz-page-' . $id,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 11:00:00',
				'post_modified_gmt'     => '2026-06-23 11:00:00',
				'post_content_filtered' => '',
				'post_parent'           => $parent,
				'guid'                  => 'https://example.test/?page_id=' . $id,
				'menu_order'            => 0,
				'post_type'             => 'page',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	private static function category_term( int $id, int $parent, string $name ): \WP_Term {
		return new \WP_Term(
			(object) array(
				'term_id'          => $id,
				'name'             => $name,
				'slug'             => 'cfz-category-' . $id,
				'term_group'       => 0,
				'term_taxonomy_id' => $id + 100,
				'taxonomy'         => 'category',
				'description'      => 'Description <script>alert(9)</script>',
				'parent'           => $parent,
				'count'            => 3,
				'filter'           => 'raw',
			)
		);
	}

	/**
	 * @return WP_Post[]
	 */
	private static function nav_query_posts( int $base_id, string $post_type, string $search ): array {
		$posts = array();
		for ( $index = 0; $index < 64; $index++ ) {
			$matches = $index < 18 || 0 === $index % 7;
			$status  = 22 === $index || 45 === $index ? 'draft' : 'publish';
			$parent  = $index > 0 && 0 === $index % 6 ? $base_id + $index : 0;
			$title   = sprintf(
				'Fuzz nav %02d %s %s',
				$index,
				$matches ? $search : 'plain',
				0 === $index % 9 ? '<script>alert(11)</script>' : 'safe'
			);

			$posts[] = self::nav_query_post( $base_id + $index + 1, $post_type, $parent, $title, $status, $index );
		}

		return $posts;
	}

	private static function nav_query_post(
		int $id,
		string $post_type,
		int $parent,
		string $title,
		string $status,
		int $index
	): \WP_Post {
		$day    = 1 + ( $index % 27 );
		$minute = $index % 60;

		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => '0',
				'post_date'             => sprintf( '2026-06-%02d 10:%02d:00', $day, $minute ),
				'post_date_gmt'         => sprintf( '2026-06-%02d 10:%02d:00', $day, $minute ),
				'post_content'          => '',
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => $status,
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'cfz-nav-' . $id,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => sprintf( '2026-06-%02d 11:%02d:00', $day, $minute ),
				'post_modified_gmt'     => sprintf( '2026-06-%02d 11:%02d:00', $day, $minute ),
				'post_content_filtered' => '',
				'post_parent'           => $parent,
				'guid'                  => 'https://example.test/?p=' . $id,
				'menu_order'            => $index,
				'post_type'             => $post_type,
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	/**
	 * @return WP_Term[]
	 */
	private static function nav_query_terms( int $base_id, string $taxonomy, string $search ): array {
		$terms = array();
		for ( $index = 0; $index < 64; $index++ ) {
			$matches = $index < 18 || 0 === $index % 8;
			$parent  = $index > 0 && 0 === $index % 5 ? $base_id + $index : 0;
			$name    = sprintf(
				'Fuzz term %02d %s %s',
				$index,
				$matches ? $search : 'plain',
				0 === $index % 10 ? '<script>alert(12)</script>' : 'safe'
			);

			$terms[] = self::nav_query_term( $base_id + $index + 1, $taxonomy, $parent, $name, 200 - $index );
		}

		return $terms;
	}

	private static function nav_query_term(
		int $id,
		string $taxonomy,
		int $parent,
		string $name,
		int $count
	): \WP_Term {
		return new \WP_Term(
			(object) array(
				'term_id'          => $id,
				'name'             => $name,
				'slug'             => 'cfz-nav-term-' . $id,
				'term_group'       => 0,
				'term_taxonomy_id' => $id + 1000,
				'taxonomy'         => $taxonomy,
				'description'      => 'Description <script>alert(13)</script>',
				'parent'           => $parent,
				'count'            => $count,
				'filter'           => 'raw',
			)
		);
	}

	private static function comment_object( int $id, int $post_id, int $parent, string $content ): \WP_Comment {
		return new \WP_Comment(
			(object) array(
				'comment_ID'           => (string) $id,
				'comment_post_ID'      => (string) $post_id,
				'comment_author'       => 'Author ' . $id,
				'comment_author_email' => '',
				'comment_author_url'   => 'javascript:alert(10)',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2026-06-23 12:00:00',
				'comment_date_gmt'     => '2026-06-23 12:00:00',
				'comment_content'      => $content,
				'comment_karma'        => '0',
				'comment_approved'     => '0',
				'comment_agent'        => 'ComponentFuzz',
				'comment_type'         => 'comment',
				'comment_parent'       => (string) $parent,
				'user_id'              => '0',
			)
		);
	}

	private static function cache_post( \WP_Post $post ): void {
		$post_id = (int) $post->ID;
		\wp_cache_set( $post_id, (object) $post->to_array(), 'posts' );
		self::$cached_post_ids[] = $post_id;
	}

	private static function cache_term( \WP_Term $term ): void {
		$term_id = (int) $term->term_id;
		\wp_cache_set( $term_id, $term, 'terms' );
		self::$cached_term_ids[] = $term_id;
	}

	private static function cache_comment( \WP_Comment $comment ): void {
		$comment_id = (int) $comment->comment_ID;
		\wp_cache_set( $comment_id, (object) $comment->to_array(), 'comment' );
		self::$cached_comment_ids[] = $comment_id;
	}

	private static function install_filters(): void {
		foreach ( self::filter_specs() as $spec ) {
			\add_filter( $spec['hook'], $spec['callback'], $spec['priority'], $spec['acceptedArgs'] );
		}
	}

	private static function remove_filters(): void {
		foreach ( self::filter_specs() as $spec ) {
			\remove_filter( $spec['hook'], $spec['callback'], $spec['priority'] );
		}
	}

	private static function filter_specs(): array {
		return array(
			array(
				'hook'         => 'pre_option_home',
				'callback'     => array( self::class, 'filter_home_option' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_siteurl',
				'callback'     => array( self::class, 'filter_home_option' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_permalink_structure',
				'callback'     => array( self::class, 'filter_permalink_structure' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_page_for_posts',
				'callback'     => array( self::class, 'filter_zero_option' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_wp_page_for_privacy_policy',
				'callback'     => array( self::class, 'filter_zero_option' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_page_comments',
				'callback'     => array( self::class, 'filter_false_option' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_comment_registration',
				'callback'     => array( self::class, 'filter_false_option' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_default_comments_page',
				'callback'     => array( self::class, 'filter_default_comments_page' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'pre_option_comments_per_page',
				'callback'     => array( self::class, 'filter_comments_per_page' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'page_link',
				'callback'     => array( self::class, 'filter_page_link' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'term_link',
				'callback'     => array( self::class, 'filter_term_link' ),
				'priority'     => 10,
				'acceptedArgs' => 3,
			),
			array(
				'hook'         => 'get_comment_link',
				'callback'     => array( self::class, 'filter_comment_link' ),
				'priority'     => 10,
				'acceptedArgs' => 4,
			),
			array(
				'hook'         => 'terms_pre_query',
				'callback'     => array( self::class, 'filter_terms_pre_query' ),
				'priority'     => 10,
				'acceptedArgs' => 2,
			),
		);
	}

	private static function with_temporary_filters( array $filters, callable $callback ) {
		foreach ( $filters as $filter ) {
			\add_filter( $filter['hook'], $filter['callback'], $filter['priority'], $filter['acceptedArgs'] );
		}

		try {
			return $callback();
		} finally {
			for ( $i = count( $filters ) - 1; $i >= 0; $i-- ) {
				\remove_filter( $filters[ $i ]['hook'], $filters[ $i ]['callback'], $filters[ $i ]['priority'] );
			}
		}
	}

	private static function reset_runtime(): void {
		self::$terms_by_id       = array();
		self::$page_links        = array();
		self::$term_links        = array();
		self::$comment_links     = array();
		self::$cached_post_ids   = array();
		self::$cached_term_ids   = array();
		self::$cached_comment_ids = array();

		\create_initial_post_types();
		\create_initial_taxonomies();

		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		$GLOBALS['wp_query']   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['post']       = null;
		$GLOBALS['comment']    = null;
		$GLOBALS['comment_depth'] = 0;
		$GLOBALS['_nav_menu_placeholder'] = 0;
		$GLOBALS['nav_menu_selected_id'] = 0;
		$GLOBALS['_wp_nav_menu_max_depth'] = 0;
		$GLOBALS['one_theme_location_no_menus'] = false;

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/component-fuzz/classic-walkers';
		$_GET                   = array();
		$_POST                  = array();
		$_REQUEST               = array();
		$_COOKIE                = array();
	}

	private static function cleanup_and_restore( array $snapshot ): array {
		$failures       = array();
		$target_ob      = (int) $snapshot['obLevel'];
		$closed_buffers = max( 0, ob_get_level() - $target_ob );

		while ( ob_get_level() > $target_ob ) {
			if ( ! @ob_end_clean() ) {
				$failures[] = 'Could not close leaked output buffer.';
				break;
			}
		}

		if ( $closed_buffers > 0 ) {
			$failures[] = 'A check leaked output buffers.';
		}

		self::remove_filters();
		$filter_leaks = self::filter_leaks();
		if ( array() !== $filter_leaks ) {
			$failures[] = 'Surface filters were not fully removed.';
		}

		foreach ( self::$cached_post_ids as $post_id ) {
			\wp_cache_delete( $post_id, 'posts' );
		}
		foreach ( self::$cached_term_ids as $term_id ) {
			\wp_cache_delete( $term_id, 'terms' );
		}
		foreach ( self::$cached_comment_ids as $comment_id ) {
			\wp_cache_delete( $comment_id, 'comment' );
		}

		self::$terms_by_id       = array();
		self::$page_links        = array();
		self::$term_links        = array();
		self::$comment_links     = array();
		self::$cached_post_ids   = array();
		self::$cached_term_ids   = array();
		self::$cached_comment_ids = array();

		self::restore_snapshot( $snapshot );

		if ( ! self::snapshot_restored( $snapshot ) ) {
			$failures[] = 'Selected globals or superglobals did not restore to their snapshot values.';
		}

		return array(
			'ok'            => array() === $failures,
			'failures'      => $failures,
			'closedBuffers' => $closed_buffers,
			'filterLeaks'   => $filter_leaks,
		);
	}

	private static function filter_leaks(): array {
		$leaks = array();
		foreach ( self::filter_specs() as $spec ) {
			if ( false !== \has_filter( $spec['hook'], $spec['callback'] ) ) {
				$leaks[] = $spec['hook'];
			}
		}

		return $leaks;
	}

	private static function query_targets_post_type( array $query_vars, string $post_type ): bool {
		$post_types = (array) ( $query_vars['post_type'] ?? array() );

		return in_array( $post_type, $post_types, true );
	}

	private static function query_targets_taxonomy( array $query_vars, string $taxonomy ): bool {
		$taxonomies = (array) ( $query_vars['taxonomy'] ?? array() );

		return in_array( $taxonomy, $taxonomies, true );
	}

	/**
	 * @param WP_Post[] $posts
	 * @return array{all:WP_Post[],page:WP_Post[]}
	 */
	private static function select_nav_posts( array $posts, array $query_vars ): array {
		$statuses = array_values( array_filter( array_map( 'strval', (array) ( $query_vars['post_status'] ?? array() ) ) ) );
		if ( array() === $statuses ) {
			$statuses = array( 'publish' );
		}

		$excluded = array_map( 'intval', (array) ( $query_vars['post__not_in'] ?? array() ) );
		$search   = strtolower( (string) ( $query_vars['s'] ?? '' ) );
		$matched  = array_values(
			array_filter(
				$posts,
				static function ( \WP_Post $post ) use ( $statuses, $excluded, $search ): bool {
					if ( ! in_array( 'any', $statuses, true ) && ! in_array( (string) $post->post_status, $statuses, true ) ) {
						return false;
					}
					if ( in_array( (int) $post->ID, $excluded, true ) ) {
						return false;
					}

					return '' === $search || str_contains( strtolower( (string) $post->post_title ), $search );
				}
			)
		);

		$orderby = strtolower( is_array( $query_vars['orderby'] ?? '' ) ? implode( ' ', array_keys( $query_vars['orderby'] ) ) : (string) ( $query_vars['orderby'] ?? '' ) );
		usort(
			$matched,
			static function ( \WP_Post $a, \WP_Post $b ) use ( $orderby ): int {
				if ( str_contains( $orderby, 'post_date' ) || str_contains( $orderby, 'date' ) ) {
					return strcmp( (string) $a->post_date, (string) $b->post_date );
				}
				if ( str_contains( $orderby, 'title' ) ) {
					$title_compare = strnatcasecmp( (string) $a->post_title, (string) $b->post_title );
					return 0 !== $title_compare ? $title_compare : (int) $a->ID <=> (int) $b->ID;
				}

				return (int) $a->ID <=> (int) $b->ID;
			}
		);

		if ( 'DESC' === strtoupper( (string) ( $query_vars['order'] ?? 'ASC' ) ) ) {
			$matched = array_reverse( $matched );
		}

		$offset         = max( 0, (int) ( $query_vars['offset'] ?? 0 ) );
		$posts_per_page = isset( $query_vars['posts_per_page'] ) ? (int) $query_vars['posts_per_page'] : count( $matched );
		$page           = $posts_per_page < 0
			? array_slice( $matched, $offset )
			: array_slice( $matched, $offset, $posts_per_page );

		return array(
			'all'  => $matched,
			'page' => array_values( $page ),
		);
	}

	/**
	 * @param WP_Term[] $terms
	 * @return array{all:WP_Term[],page:WP_Term[]}
	 */
	private static function select_nav_terms( array $terms, array $query_vars ): array {
		$search  = strtolower( (string) ( $query_vars['name__like'] ?? '' ) );
		$matched = array_values(
			array_filter(
				$terms,
				static function ( \WP_Term $term ) use ( $search ): bool {
					return '' === $search || str_contains( strtolower( (string) $term->name ), $search );
				}
			)
		);

		$orderby = strtolower( (string) ( $query_vars['orderby'] ?? 'name' ) );
		usort(
			$matched,
			static function ( \WP_Term $a, \WP_Term $b ) use ( $orderby ): int {
				if ( 'count' === $orderby ) {
					return (int) $a->count <=> (int) $b->count;
				}
				if ( in_array( $orderby, array( 'id', 'term_id' ), true ) ) {
					return (int) $a->term_id <=> (int) $b->term_id;
				}

				$name_compare = strnatcasecmp( (string) $a->name, (string) $b->name );
				return 0 !== $name_compare ? $name_compare : (int) $a->term_id <=> (int) $b->term_id;
			}
		);

		if ( 'DESC' === strtoupper( (string) ( $query_vars['order'] ?? 'ASC' ) ) ) {
			$matched = array_reverse( $matched );
		}

		$offset = max( 0, (int) ( $query_vars['offset'] ?? 0 ) );
		$number = isset( $query_vars['number'] ) && '' !== (string) $query_vars['number']
			? (int) $query_vars['number']
			: 0;
		$page   = $number > 0 ? array_slice( $matched, $offset, $number ) : array_slice( $matched, $offset );

		return array(
			'all'  => $matched,
			'page' => array_values( $page ),
		);
	}

	private static function capture_output( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	private static function decode_json_lines( string $output ): array {
		$decoded = array();
		foreach ( preg_split( '/\r?\n/', trim( $output ) ) ?: array() as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$value = json_decode( $line, true );
			if ( is_array( $value ) ) {
				$decoded[] = $value;
			}
		}

		return $decoded;
	}

	private static function first_query_event( array $events, callable $predicate ): ?array {
		foreach ( $events as $event ) {
			if ( $predicate( $event ) ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * @param WP_Post[] $posts
	 * @return int[]
	 */
	private static function post_ids( array $posts ): array {
		return array_map(
			static function ( \WP_Post $post ): int {
				return (int) $post->ID;
			},
			$posts
		);
	}

	/**
	 * @param WP_Term[] $terms
	 * @return int[]
	 */
	private static function term_ids( array $terms ): array {
		return array_map(
			static function ( \WP_Term $term ): int {
				return (int) $term->term_id;
			},
			$terms
		);
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach ( self::tracked_globals() as $global ) {
			$globals[ $global ] = array(
				'exists' => array_key_exists( $global, $GLOBALS ),
				'value'  => $GLOBALS[ $global ] ?? null,
			);
		}

		return array(
			'obLevel'      => ob_get_level(),
			'globals'      => $globals,
			'server'       => $_SERVER,
			'get'          => $_GET,
			'postRequest'  => $_POST,
			'request'      => $_REQUEST,
			'cookie'       => $_COOKIE,
		);
	}

	private static function restore_snapshot( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $global => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $global ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $global ] );
			}
		}

		$_SERVER  = $snapshot['server'];
		$_GET     = $snapshot['get'];
		$_POST    = $snapshot['postRequest'];
		$_REQUEST = $snapshot['request'];
		$_COOKIE  = $snapshot['cookie'];
	}

	private static function snapshot_restored( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $global => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $global, $GLOBALS ) ) {
				return false;
			}
			if ( $entry['exists'] && $GLOBALS[ $global ] !== $entry['value'] ) {
				return false;
			}
		}

		return $_SERVER === $snapshot['server']
			&& $_GET === $snapshot['get']
			&& $_POST === $snapshot['postRequest']
			&& $_REQUEST === $snapshot['request']
			&& $_COOKIE === $snapshot['cookie'];
	}

	private static function tracked_globals(): array {
		return array(
			'wp_taxonomies',
			'wp_post_types',
			'wp_query',
			'wp_the_query',
			'wp_rewrite',
			'post',
			'comment',
			'comment_depth',
			'comment_alt',
			'comment_thread_alt',
			'comment_order',
			'_nav_menu_placeholder',
			'nav_menu_selected_id',
			'_wp_nav_menu_max_depth',
			'one_theme_location_no_menus',
		);
	}

	private static function expected_has_children( array $nodes ): array {
		$parent_counts = array();
		foreach ( $nodes as $node ) {
			if ( ! empty( $node->parent ) ) {
				$parent_counts[ (int) $node->parent ] = true;
			}
		}

		$expected = array();
		foreach ( $nodes as $node ) {
			$expected[ (int) $node->id ] = ! empty( $parent_counts[ (int) $node->id ] );
		}

		return $expected;
	}

	private static function has_children_by_id( array $events ): array {
		$actual = array();
		foreach ( $events as $event ) {
			$actual[ (int) $event['id'] ] = (bool) $event['hasChildren']
				&& (bool) $event['argsHasChildren'];
		}

		return $actual;
	}

	private static function same_bool_map( array $expected, array $actual ): bool {
		$expected = array_map( 'boolval', $expected );
		$actual   = array_map( 'boolval', $actual );
		ksort( $expected );
		ksort( $actual );

		return $expected === $actual;
	}

	private static function has_children_mismatches( array $expected, array $actual ): string {
		$mismatches = array();
		$ids        = array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) );
		sort( $ids, SORT_NUMERIC );

		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $expected ) || ! array_key_exists( $id, $actual ) ) {
				$mismatches[] = (int) $id . ':e' . ( array_key_exists( $id, $expected ) ? '1' : '?' ) . '/a' . ( array_key_exists( $id, $actual ) ? '1' : '?' );
				continue;
			}

			$expected_value = $expected[ $id ];
			$actual_value = (bool) ( $actual[ $id ] ?? false );
			if ( (bool) $expected_value !== $actual_value ) {
				$mismatches[] = (int) $id . ':e' . ( (bool) $expected_value ? '1' : '0' ) . '/a' . ( $actual_value ? '1' : '0' );
			}
		}

		return implode( ',', $mismatches );
	}

	private static function compact_event_summary( array $events ): string {
		return implode(
			',',
			array_map(
				static function ( array $event ): string {
					return (int) $event['id'] . '@' . (int) $event['depth'] . ':h' . ( ! empty( $event['hasChildren'] ) ? '1' : '0' ) . '/a' . ( ! empty( $event['argsHasChildren'] ) ? '1' : '0' );
				},
				$events
			)
		);
	}

	private static function event_ids( array $events ): array {
		return array_map(
			static function ( array $event ): int {
				return (int) $event['id'];
			},
			$events
		);
	}

	private static function event_id_depths( array $events ): array {
		return array_map(
			static function ( array $event ): array {
				return array(
					'id'    => (int) $event['id'],
					'depth' => (int) $event['depth'],
				);
			},
			$events
		);
	}

	private static function all_events_have_depth( array $events, int $depth ): bool {
		foreach ( $events as $event ) {
			if ( $depth !== (int) $event['depth'] ) {
				return false;
			}
		}

		return true;
	}

	private static function any_event_has_children( array $events ): bool {
		foreach ( $events as $event ) {
			if ( ! empty( $event['hasChildren'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function balanced_enough( string $html, array $tags ): bool {
		foreach ( $tags as $tag ) {
			$open_count  = preg_match_all( '/<' . preg_quote( $tag, '/' ) . '(?:\s|>|\/)/i', $html );
			$close_count = preg_match_all( '/<\/' . preg_quote( $tag, '/' ) . '\s*>/i', $html );

			if ( $open_count !== $close_count ) {
				return false;
			}
		}

		return true;
	}

	private static function opening_tags( string $html, string $tag ): array {
		preg_match_all( '/<' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', $html, $matches );

		return $matches[0];
	}

	private static function contains_nav_menu_object_id( string $html, int $object_id ): bool {
		return 1 === preg_match(
			'/name="menu-item\[-?\d+\]\[menu-item-object-id\]" value="' . preg_quote( (string) $object_id, '/' ) . '"/',
			$html
		);
	}

	private static function nav_menu_matrix_tags_match_state(
		array $li_tags,
		array $a_tags,
		array $expected_ids,
		array $expected_depth,
		int $current_id
	): bool {
		if ( count( $li_tags ) !== count( $expected_ids ) || count( $a_tags ) !== count( $expected_ids ) ) {
			return false;
		}

		foreach ( $expected_ids as $index => $item_id ) {
			$li_tag     = $li_tags[ $index ];
			$a_tag      = $a_tags[ $index ];
			$depth      = $expected_depth[ $index ];
			$is_current = $current_id === $item_id;

			if (
				! str_contains( $li_tag, 'id="cfz-li-&quot;&lt;' . $item_id . '&gt;-d' . $depth . '"' )
				|| ! str_contains( $li_tag, 'data-cfz-depth="' . $depth . '"' )
				|| ! str_contains( $li_tag, 'cfz-matrix-depth-' . $depth )
				|| ! str_contains( $a_tag, 'data-cfz-link="&quot;&lt;link-' . $item_id . '&gt;"' )
			) {
				return false;
			}

			if ( $is_current ) {
				if (
					! str_contains( $li_tag, 'current-menu-item' )
					|| ! str_contains( $li_tag, 'cfz-matrix-filter-current' )
					|| ! str_contains( $li_tag, 'data-cfz-current="&quot;&lt;current-' . $item_id . '&gt;"' )
					|| ! str_contains( $a_tag, 'aria-current="page"' )
				) {
					return false;
				}
				continue;
			}

			if (
				str_contains( $li_tag, 'current-menu-item' )
				|| str_contains( $li_tag, 'cfz-matrix-filter-current' )
				|| str_contains( $li_tag, 'data-cfz-current' )
				|| str_contains( $a_tag, 'aria-current' )
			) {
				return false;
			}
		}

		return str_contains( $li_tags[0], 'current-menu-ancestor' )
			&& str_contains( $li_tags[1], 'current-menu-parent' )
			&& str_contains( $li_tags[2], 'cfz-unsafe&lt;script&gt;' )
			&& ! str_contains( $li_tags[3], 'current-menu-' )
			&& ! str_contains( $li_tags[4], 'current-menu-' );
	}

	private static function tags_contain_in_order( array $tags, array $needles ): bool {
		if ( count( $tags ) !== count( $needles ) ) {
			return false;
		}

		foreach ( $needles as $index => $needle ) {
			if ( ! str_contains( $tags[ $index ], $needle ) ) {
				return false;
			}
		}

		return true;
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

	private static function row(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		bool $ok,
		array $data = array()
	): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function describe_string( string $value ): array {
		return array(
			'length'  => strlen( $value ),
			'preview' => strlen( $value ) > self::PREVIEW_BYTES ? substr( $value, 0, self::PREVIEW_BYTES ) . '...' : $value,
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

	private static function test_walker() {
		return new class() extends \Walker {
			public $tree_type = 'classic-walkers-test';

			public $db_fields = array(
				'parent' => 'parent',
				'id'     => 'id',
			);

			/** @var array<int,array<string,mixed>> */
			private array $events = array();

			public function start_lvl( &$output, $depth = 0, $args = array() ) {
				unset( $args );
				$output .= '[start_lvl:' . (int) $depth . ']';
			}

			public function end_lvl( &$output, $depth = 0, $args = array() ) {
				unset( $args );
				$output .= '[end_lvl:' . (int) $depth . ']';
			}

			public function start_el( &$output, $data_object, $depth = 0, $args = array(), $current_object_id = 0 ) {
				unset( $current_object_id );

				$this->events[] = array(
					'id'              => (int) $data_object->id,
					'parent'          => (int) $data_object->parent,
					'depth'           => (int) $depth,
					'hasChildren'     => (bool) $this->has_children,
					'argsHasChildren' => (bool) ( $args['has_children'] ?? false ),
					'marker'          => (string) ( $args['marker'] ?? '' ),
				);

				$output .= '[el:' . (int) $data_object->id . ':' . (int) $depth . ']';
			}

			public function end_el( &$output, $data_object, $depth = 0, $args = array() ) {
				unset( $args );
				$output .= '[/el:' . (int) $data_object->id . ':' . (int) $depth . ']';
			}

			public function start_events(): array {
				return $this->events;
			}
		};
	}

	private static function base_id( \ComponentFuzz\FuzzContext $ctx, string $label ): int {
		return 1000000 + ( abs( crc32( $label . '|' . $ctx->seed() ) ) % 7000000 );
	}
}
