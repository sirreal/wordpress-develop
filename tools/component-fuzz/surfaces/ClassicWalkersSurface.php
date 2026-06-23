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
			$rows[] = self::check_nav_menu_walker( $ctx );
			$rows[] = self::check_page_walker( $ctx );
			$rows[] = self::check_category_walker( $ctx );

			if ( $comment_available ) {
				$rows[] = self::check_comment_walker( $ctx );
			} else {
				$rows[] = $ctx->skip(
					'classic-walkers.comment.available',
					'Walker_Comment could not be loaded from the stripped bootstrap.'
				);
			}

			if ( $admin_available ) {
				$rows[] = self::check_admin_nav_menu_walkers( $ctx );
			} else {
				$rows[] = $ctx->skip(
					'classic-walkers.admin-nav.walkers-available',
					'Admin nav menu walker dependencies could not be loaded safely.'
				);
			}

			$rows[] = $ctx->skip(
				'classic-walkers.admin-nav.dispatch-paths',
				'Nav menu AJAX quick-search, meta-box pagination, and browser admin page dispatch '
					. 'are intentionally not invoked in-process.'
			);
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
			'Walker_Category',
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

	private static function check_nav_menu_walker( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$base_id  = self::base_id( $ctx, 'nav' );
		$items    = array(
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

		$output = ( new \Walker_Nav_Menu() )->walk( $items, 0, $args );

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

	private static function check_page_walker( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$base_id  = self::base_id( $ctx, 'page' );
		$root     = self::page_post( $base_id + 1, 0, 'Root page ' . $ctx->identifier( 3, 8 ) );
		$child    = self::page_post( $base_id + 2, $root->ID, 'Child page ' . $ctx->identifier( 3, 8 ) );
		$sibling  = self::page_post( $base_id + 3, 0, 'Sibling page ' . $ctx->identifier( 3, 8 ) );
		$pages    = array( $root, $child, $sibling );

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

		$output = ( new \Walker_Page() )->walk( $pages, 0, $args, (int) $child->ID );

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
		$failures = array();
		$base_id  = self::base_id( $ctx, 'category' );
		$root     = self::category_term(
			$base_id + 1,
			0,
			'Root <script>alert(1)</script> ' . $ctx->identifier( 3, 8 )
		);
		$child    = self::category_term(
			$base_id + 2,
			(int) $root->term_id,
			'Child <script>alert(2)</script> ' . $ctx->identifier( 3, 8 )
		);
		$terms    = array( $root, $child );

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

		$output = ( new \Walker_Category() )->walk( $terms, 0, $args );

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

	private static function check_admin_nav_menu_walkers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$base_id  = self::base_id( $ctx, 'admin-nav' );
		$parent   = self::menu_item(
			$base_id + 1,
			1,
			0,
			'Admin <script>alert(1)</script> ' . $ctx->identifier( 3, 8 ),
			'javascript:alert(5)',
			false
		);
		$child    = self::menu_item(
			$base_id + 2,
			2,
			$base_id + 1,
			'Admin child <script>alert(2)</script> ' . $ctx->identifier( 3, 8 ),
			'https://example.test/admin-child',
			false
		);
		$items    = array( $parent, $child );

		$GLOBALS['_nav_menu_placeholder']   = 0;
		$GLOBALS['nav_menu_selected_id']    = $base_id + 50;
		$GLOBALS['one_theme_location_no_menus'] = false;
		$GLOBALS['_wp_nav_menu_max_depth']  = 0;
		$_GET                              = array();

		$checklist_output = ( new \Walker_Nav_Menu_Checklist() )->walk( $items, 0, (object) array() );
		$edit_output      = ( new \Walker_Nav_Menu_Edit() )->walk( $items, 0, (object) array() );

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

		return self::row(
			$ctx,
			'classic-walkers.admin-nav.checklist-edit-rendering',
			array() === $failures,
			array( 'failures' => $failures )
		);
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

	private static function node( int $id, int $parent ): object {
		return (object) array(
			'id'     => $id,
			'parent' => $parent,
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

	private static function event_ids( array $events ): array {
		return array_map(
			static function ( array $event ): int {
				return (int) $event['id'];
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
