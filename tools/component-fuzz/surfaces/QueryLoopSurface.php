<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WP_Query loop execution state through pre-query fixtures.
 */
final class QueryLoopSurface {
	public const NAME = 'query-loop';

	private const GENERATED_CASES = 7;
	private const MAX_FAILURES    = 8;
	private const TAXONOMY        = 'component_fuzz_loop_topic';

	private static array $posts = array();
	private static array $post_meta = array();
	private static array $post_terms = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'query-loop.bootstrap-apis-available',
					'Required WordPress query loop APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::prepare_runtime();
			self::$posts = self::post_fixtures( $ctx );
			self::prime_post_caches();

			$rows[] = self::check_pre_query_loop_state( $ctx->fork( 'pre-query' ) );
			$rows[] = self::check_global_wrappers_and_reset( $ctx->fork( 'global-wrappers' ) );
			$rows[] = self::check_query_flags_and_selected_posts( $ctx->fork( 'flags' ) );
			$rows[] = self::check_empty_query_events( $ctx->fork( 'empty' ) );
			$rows   = array_merge( $rows, self::check_generated_query_cases( $ctx->fork( 'generated-cases' ) ) );
			$rows[] = self::check_filter_and_cache_locality( $ctx->fork( 'filter-cache-locality' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'query-loop.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::delete_post_caches();
			self::$posts = array();
			self::$post_meta = array();
			self::$post_terms = array();
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'query-loop.global-state-restored',
			self::state_matches( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot['globals'] ) )
		);

		return $rows;
	}

	public static function filter_posts_pre_query( $posts, \WP_Query $query ) {
		unset( $posts );

		$matches = self::matching_posts_for_query( $query );
		$fixture = self::apply_query_limits( $matches, $query->query_vars );

		if ( ! (bool) ( $query->query_vars['no_found_rows'] ?? false ) ) {
			$query->found_posts   = count( $matches );
			$query->max_num_pages = self::expected_max_pages( count( $matches ), $query->query_vars );
		} else {
			$query->found_posts   = 0;
			$query->max_num_pages = 0;
		}

		if ( 'ids' === ( $query->query_vars['fields'] ?? '' ) ) {
			return array_map(
				static function ( \WP_Post $post ): int {
					return (int) $post->ID;
				},
				$fixture
			);
		}

		if ( 'id=>parent' === ( $query->query_vars['fields'] ?? '' ) ) {
			return array_map(
				static function ( \WP_Post $post ): object {
					return (object) array(
						'ID'          => (int) $post->ID,
						'post_parent' => (int) $post->post_parent,
					);
				},
				$fixture
			);
		}

		return $fixture;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach ( array( 'WP_Post', 'WP_Query' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_action',
				'create_initial_post_types',
				'get_post',
				'get_post_status_object',
				'get_post_type_object',
				'has_filter',
				'have_posts',
				'register_taxonomy',
				'remove_filter',
				'remove_action',
				'rewind_posts',
				'setup_postdata',
				'taxonomy_exists',
				'the_post',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
				'wp_reset_postdata',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function prepare_runtime(): void {
		if (
			! \get_post_type_object( 'post' )
			|| ! \get_post_type_object( 'page' )
			|| ! \get_post_status_object( 'publish' )
		) {
			\create_initial_post_types();
		}

		if ( ! \taxonomy_exists( self::TAXONOMY ) ) {
			\register_taxonomy(
				self::TAXONOMY,
				array( 'post', 'page' ),
				array(
					'public'       => true,
					'query_var'    => self::TAXONOMY,
					'hierarchical' => false,
				)
			);
		}
	}

	private static function check_pre_query_loop_state( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$events   = array();
		$actions  = array();
		$query    = self::run_pre_query(
			array(
				'post_type'              => 'post',
				'posts_per_page'         => count( self::ids_for_type( 'post' ) ),
				'paged'                  => 1,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$events,
			$actions
		);
		$expected = self::ids_for_type( 'post' );

		try {
			$GLOBALS['wp_query']     = $query;
			$GLOBALS['wp_the_query'] = $query;

			self::collect_failure(
				$failures,
				$query->posts === array_values(
					array_filter(
						self::$posts,
						static fn ( \WP_Post $post ): bool => 'post' === $post->post_type
					)
				)
					&& count( $expected ) === $query->post_count
					&& count( $expected ) === $query->found_posts
					&& self::max_pages( count( $expected ), (int) $query->query_vars['posts_per_page'] ) === $query->max_num_pages
					&& is_string( $query->request )
					&& str_contains( $query->request, 'SELECT' ),
				'posts_pre_query supplies posts and preserves found/max page counts after request construction',
				array(
					'postCount'   => $query->post_count,
					'foundPosts'  => $query->found_posts,
					'maxNumPages' => $query->max_num_pages,
					'request'     => $query->request,
					'expectedIds' => $expected,
					'actualIds'   => self::post_ids( $query->posts ),
				)
			);

			$seen = array();
			while ( $query->have_posts() ) {
				$query->the_post();
				$seen[] = (int) $GLOBALS['post']->ID;
				self::collect_failure(
					$failures,
					$query->post instanceof \WP_Post
						&& $GLOBALS['post'] instanceof \WP_Post
						&& (int) $query->post->ID === (int) $GLOBALS['post']->ID,
					'the_post keeps query post and global post aligned',
					array(
						'queryPost'  => self::describe_post( $query->post ),
						'globalPost' => self::describe_post( $GLOBALS['post'] ?? null ),
					)
				);
			}

			self::collect_failure(
				$failures,
				$expected === $seen
					&& array( 'loop_start', 'loop_end' ) === $events
					&& -1 === $query->current_post
					&& false === $query->in_the_loop
					&& false === $query->before_loop,
				'loop iteration order, loop events, and completed-loop rewind state are coherent',
				array(
					'expected'     => $expected,
					'seen'         => $seen,
					'events'       => $events,
					'currentPost'  => $query->current_post,
					'inTheLoop'    => $query->in_the_loop,
					'beforeLoop'   => $query->before_loop,
				)
			);

			$query->rewind_posts();
			self::collect_failure(
				$failures,
				-1 === $query->current_post
					&& false === $query->before_loop
					&& false === $query->in_the_loop,
				'rewind_posts resets loop cursor without changing result set',
				array(
					'currentPost' => $query->current_post,
					'beforeLoop'  => $query->before_loop,
					'inTheLoop'   => $query->in_the_loop,
				)
			);
		} finally {
			self::remove_scoped_hooks( $actions );
		}

		return self::row(
			$ctx,
			'query-loop.pre-query-loop-state',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
				'events'   => $events,
			)
		);
	}

	private static function check_global_wrappers_and_reset( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$events        = array();
		$actions       = array();
		$previous_post = self::post_fixture( 999, 'previous', 'Previous global <post>', 0, 'page' );
		$GLOBALS['post'] = $previous_post;

		$query = self::run_pre_query(
			array(
				'post_type'              => 'post',
				'posts_per_page'         => 2,
				'ignore_sticky_posts'    => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$events,
			$actions
		);

		try {
			$GLOBALS['wp_query']     = $query;
			$GLOBALS['wp_the_query'] = $query;

			$expected_seen = self::post_ids( $query->posts );
			$wrapper_seen = array();
			while ( \have_posts() ) {
				\the_post();
				$wrapper_seen[] = (int) $GLOBALS['post']->ID;
			}

			\rewind_posts();
			$first    = $query->posts[0] ?? null;
			$setup_ok = false;
			if ( $first instanceof \WP_Post ) {
				$setup_ok = \setup_postdata( $first )
					&& isset( $GLOBALS['id'], $GLOBALS['authordata'] )
					&& (int) $GLOBALS['id'] === (int) $first->ID;
			}
			\wp_reset_postdata();

			self::collect_failure(
				$failures,
				$expected_seen === $wrapper_seen
					&& -1 === $query->current_post
					&& true === $setup_ok
					&& $GLOBALS['post'] instanceof \WP_Post
					&& (int) $GLOBALS['post']->ID === (int) $first->ID,
				'global loop wrappers delegate to wp_query and setup/reset postdata coherently',
				array(
					'expected'    => $expected_seen,
					'seen'        => $wrapper_seen,
					'events'      => $events,
					'setupOk'     => $setup_ok,
					'globalPost'  => self::describe_post( $GLOBALS['post'] ?? null ),
					'firstPost'   => self::describe_post( $first ),
					'currentPost' => $query->current_post,
				)
			);
		} finally {
			self::remove_scoped_hooks( $actions );
		}

		return self::row(
			$ctx,
			'query-loop.global-wrappers-reset-postdata',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_query_flags_and_selected_posts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$page         = self::first_post_of_type( 'page' );
		$post         = self::first_post_of_type( 'post' );
		$events_page  = array();
		$events_post  = array();
		$actions_page = array();
		$actions_post = array();
		$page_case    = self::run_pre_query(
			array(
				'page_id'                => $page->ID,
				'post_type'              => 'page',
				'p'                      => 0,
				'ignore_sticky_posts'    => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$events_page,
			$actions_page
		);
		$post_case    = self::run_pre_query(
			array(
				'p'                      => $post->ID,
				'post_type'              => 'post',
				'ignore_sticky_posts'    => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$events_post,
			$actions_post
		);
		unset( $events_page, $events_post );

		try {
			self::collect_failure(
				$failures,
				$page_case->is_page()
					&& ! $page_case->is_single()
					&& 1 === $page_case->post_count
					&& array( (int) $page->ID ) === self::post_ids( $page_case->posts ),
				'page query flags and selected posts agree with page_id',
				array(
					'flags'       => self::query_flags( $page_case ),
					'selectedIds' => self::post_ids( $page_case->posts ),
					'expectedId'  => $page->ID,
				)
			);

			self::collect_failure(
				$failures,
				$post_case->is_single()
					&& ! $post_case->is_page()
					&& 1 === $post_case->post_count
					&& array( (int) $post->ID ) === self::post_ids( $post_case->posts ),
				'single query flags and selected posts agree with p',
				array(
					'flags'       => self::query_flags( $post_case ),
					'selectedIds' => self::post_ids( $post_case->posts ),
					'expectedId'  => $post->ID,
				)
			);
		} finally {
			self::remove_scoped_hooks( $actions_page );
			self::remove_scoped_hooks( $actions_post );
		}

		return self::row(
			$ctx,
			'query-loop.flags-and-selected-posts',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_empty_query_events( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$events   = array();
		$actions  = array();
		$query    = self::run_pre_query(
			array(
				'post_type'              => 'missing_type',
				'posts_per_page'         => $ctx->int( 1, 5 ),
				'ignore_sticky_posts'    => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$events,
			$actions
		);

		try {
			$has_posts = $query->have_posts();
			$again     = $query->have_posts();

			self::collect_failure(
				$failures,
				false === $has_posts
					&& false === $again
					&& array( 'loop_no_results', 'loop_no_results' ) === $events
					&& 0 === $query->post_count
					&& 0 === $query->found_posts
					&& 0 === $query->max_num_pages
					&& false === $query->before_loop
					&& false === $query->in_the_loop,
				'empty pre-query result fires no-results and leaves loop state false',
				array(
					'events'      => $events,
					'postCount'   => $query->post_count,
					'foundPosts'  => $query->found_posts,
					'maxNumPages' => $query->max_num_pages,
					'beforeLoop'  => $query->before_loop,
					'inTheLoop'   => $query->in_the_loop,
				)
			);
		} finally {
			self::remove_scoped_hooks( $actions );
		}

		return self::row(
			$ctx,
			'query-loop.empty-query-events',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_generated_query_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::query_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$events = array();
			$hooks  = array();

			try {
				$query  = self::run_pre_query( $case['args'], $events, $hooks, $case['options'] ?? array() );
				$expect = self::expected_query_observation( $query, $case );

				$rows[] = self::case_row(
					$ctx,
					$case_index,
					$case,
					'query-loop.generated.case-no-throw',
					true,
					array(
						'queryVars' => self::selected_query_vars( $query ),
						'expected'  => $expect,
					)
				);

				$rows[] = self::case_row(
					$ctx,
					$case_index,
					$case,
					'query-loop.generated.normalization-and-counts',
					self::normalization_and_counts_match( $query, $case, $expect ),
					array(
						'queryVars' => self::selected_query_vars( $query ),
						'actual'    => self::query_result_observation( $query ),
						'expected'  => $expect,
					)
				);

				$rows[] = self::case_row(
					$ctx,
					$case_index,
					$case,
					'query-loop.generated.flags-and-request',
					self::query_flags_match( $query, $case )
						&& self::request_shape_matches( $query, $case ),
					array(
						'flags'   => self::query_flags( $query ),
						'expect'  => $case['expect']['flags'] ?? array(),
						'request' => self::describe_string( $query->request ),
					)
				);

				$loop = self::observe_query_loop( $query );
				$rows[] = self::case_row(
					$ctx,
					$case_index,
					$case,
					'query-loop.generated.loop-transition-oracles',
					self::loop_observation_matches( $query, $loop, $events, $expect ),
					array(
						'loop'     => $loop,
						'events'   => $events,
						'expected' => $expect,
					)
				);
			} catch ( \Throwable $e ) {
				$rows[] = self::case_row(
					$ctx,
					$case_index,
					$case,
					'query-loop.generated.case-no-throw',
					false,
					array( 'throwable' => self::describe_throwable( $e ) )
				);
			} finally {
				self::remove_scoped_hooks( $hooks );
			}
		}

		return $rows;
	}

	private static function check_filter_and_cache_locality( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$events         = array();
		$hooks          = array();
		$before_filter  = \has_filter( 'posts_pre_query', array( self::class, 'filter_posts_pre_query' ) );
		$before_wpdb    = self::wpdb_public_state();
		$sentinel_key   = 'query-loop-sentinel-' . $ctx->iteration() . '-' . substr( sha1( (string) $ctx->seed() ), 0, 12 );
		$sentinel_value = 'query-loop:' . $ctx->seed();

		\wp_cache_set( $sentinel_key, $sentinel_value, 'component-fuzz-query-loop' );

		try {
			$query  = self::run_pre_query(
				array(
					'post_type'              => 'post',
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'cache_results'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				),
				$events,
				$hooks
			);
			$expect = self::expected_query_observation( $query, array( 'args' => $query->query_vars ) );
			$loop   = self::observe_query_loop( $query );

			self::collect_failure(
				$failures,
				self::post_ids( self::$posts ) === self::post_ids( self::cached_posts_for_ids( self::post_ids( self::$posts ) ) ),
				'fixture posts remain available from the post cache during ID-field loop setup',
				array(
					'cached' => self::post_ids( self::cached_posts_for_ids( self::post_ids( self::$posts ) ) ),
					'posts'  => self::post_ids( self::$posts ),
				)
			);

			self::collect_failure(
				$failures,
				$expect['pageIds'] === $loop['seen']
					&& array( 'loop_start', 'loop_end' ) === $events,
				'ID-field loop uses scoped post cache and still emits exactly one loop_start/loop_end pair',
				array(
					'expected' => $expect['pageIds'],
					'seen'     => $loop['seen'],
					'events'   => $events,
				)
			);
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				'filter/cache locality probe does not throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::remove_scoped_hooks( $hooks );
		}

		self::collect_failure(
			$failures,
			$before_filter === \has_filter( 'posts_pre_query', array( self::class, 'filter_posts_pre_query' ) ),
			'posts_pre_query filter registration is restored after scoped query construction',
			array(
				'before' => $before_filter,
				'after'  => \has_filter( 'posts_pre_query', array( self::class, 'filter_posts_pre_query' ) ),
			)
		);

		self::collect_failure(
			$failures,
			$sentinel_value === \wp_cache_get( $sentinel_key, 'component-fuzz-query-loop' ),
			'unrelated cache key survives query-local cache priming',
			array(
				'expected' => $sentinel_value,
				'actual'   => \wp_cache_get( $sentinel_key, 'component-fuzz-query-loop' ),
			)
		);
		\wp_cache_delete( $sentinel_key, 'component-fuzz-query-loop' );

		self::collect_failure(
			$failures,
			$before_wpdb === self::wpdb_public_state(),
			'wpdb public stub state is unchanged by fully pre-query ID-field iteration',
			array(
				'before' => $before_wpdb,
				'after'  => self::wpdb_public_state(),
			)
		);

		return self::row(
			$ctx,
			'query-loop.filter-cache-locality',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function run_pre_query( array $args, array &$events, array &$hooks, array $options = array() ): \WP_Query {
		$events     = array();
		$hooks      = array();
		$loop_start = static function () use ( &$events ): void {
			$events[] = 'loop_start';
		};
		$loop_end = static function () use ( &$events ): void {
			$events[] = 'loop_end';
		};
		$loop_no_results = static function () use ( &$events ): void {
			$events[] = 'loop_no_results';
		};
		$options = array_merge(
			array(
				'comments_per_page' => 50,
				'page_on_front'     => 0,
				'posts_per_page'    => 10,
				'show_on_front'     => 'posts',
				'sticky_posts'      => array(),
			),
			$options
		);

		\add_filter( 'posts_pre_query', array( self::class, 'filter_posts_pre_query' ), 10, 2 );
		\add_action( 'loop_start', $loop_start, 10, 1 );
		\add_action( 'loop_end', $loop_end, 10, 1 );
		\add_action( 'loop_no_results', $loop_no_results, 10, 1 );
		$hooks[] = array( 'action', 'loop_start', $loop_start, 10 );
		$hooks[] = array( 'action', 'loop_end', $loop_end, 10 );
		$hooks[] = array( 'action', 'loop_no_results', $loop_no_results, 10 );

		foreach ( $options as $option => $value ) {
			$hook     = 'pre_option_' . $option;
			$callback = static function () use ( $value ) {
				return $value;
			};
			\add_filter( $hook, $callback, 10, 1 );
			$hooks[] = array( 'filter', $hook, $callback, 10 );
		}

		try {
			$query = new \WP_Query( $args );
		} finally {
			\remove_filter( 'posts_pre_query', array( self::class, 'filter_posts_pre_query' ), 10 );
		}

		return $query;
	}

	private static function remove_scoped_hooks( array $hooks ): void {
		foreach ( $hooks as $hook ) {
			if ( ! is_array( $hook ) || count( $hook ) < 4 ) {
				continue;
			}

			if ( 'filter' === $hook[0] ) {
				\remove_filter( $hook[1], $hook[2], $hook[3] );
			} else {
				\remove_action( $hook[1], $hook[2], $hook[3] );
			}
		}
	}

	private static function query_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$post_ids = self::ids_for_type( 'post' );
		$page_ids = self::ids_for_type( 'page' );

		$cases = array(
			self::query_case(
				'corpus-home-sticky-front',
				self::query_args(
					array(
						'posts_per_page'      => count( $post_ids ),
						'ignore_sticky_posts' => false,
						'orderby'             => 'ID',
						'order'               => 'ASC',
					)
				),
				array( 'isHome' => true ),
				array( 'sticky_posts' => array( $post_ids[1] ) ),
				array( 'sticky', 'home', 'orderby' )
			),
			self::query_case(
				'corpus-paged-date-desc',
				self::query_args(
					array(
						'post_type'      => 'post',
						'posts_per_page' => 2,
						'paged'          => 2,
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				),
				array(
					'isPaged' => true,
					'isHome'  => true,
				),
				array(),
				array( 'paged', 'date', 'foundRows' )
			),
			self::query_case(
				'corpus-post-in-preserves-order',
				self::query_args(
					array(
						'post_type'      => 'post',
						'post__in'       => array( $post_ids[2], $post_ids[0], $post_ids[3] ),
						'orderby'        => 'post__in',
						'posts_per_page' => 3,
					)
				),
				array( 'isHome' => true ),
				array(),
				array( 'post__in', 'orderby' )
			),
			self::query_case(
				'corpus-meta-between-score',
				self::query_args(
					array(
						'post_type'      => 'post',
						'meta_key'       => 'loop_score',
						'meta_query'     => array(
							array(
								'key'     => 'loop_score',
								'value'   => array( 20, 45 ),
								'compare' => 'BETWEEN',
								'type'    => 'NUMERIC',
							),
						),
						'orderby'        => 'meta_value_num',
						'order'          => 'DESC',
						'posts_per_page' => 4,
					)
				),
				array( 'isHome' => true ),
				array(),
				array( 'meta', 'orderby' )
			),
			self::query_case(
				'corpus-tax-term-taxonomy-ids',
				self::query_args(
					array(
						'post_type'      => 'post',
						'tax_query'      => array(
							array(
								'taxonomy' => self::TAXONOMY,
								'field'    => 'term_taxonomy_id',
								'terms'    => array( 7001, 7002 ),
								'operator' => 'IN',
							),
						),
						'orderby'        => 'title',
						'order'          => 'ASC',
						'posts_per_page' => 5,
					)
				),
				array(
					'isTax'     => true,
					'isArchive' => true,
				),
				array(),
				array( 'tax', 'taxonomy' )
			),
			self::query_case(
				'corpus-date-month',
				self::query_args(
					array(
						'post_type'      => 'post',
						'year'           => 2026,
						'monthnum'       => 6,
						'orderby'        => 'date',
						'order'          => 'DESC',
						'posts_per_page' => 5,
					)
				),
				array(
					'isDate'    => true,
					'isArchive' => true,
				),
				array(),
				array( 'date' )
			),
			self::query_case(
				'corpus-page-id',
				self::query_args(
					array(
						'page_id'        => $page_ids[0],
						'post_type'      => 'page',
						'posts_per_page' => 1,
					)
				),
				array( 'isPage' => true ),
				array(),
				array( 'page', 'conditional' )
			),
			self::query_case(
				'corpus-search-any',
				self::query_args(
					array(
						's'              => 'Alpha',
						'post_type'      => 'any',
						'posts_per_page' => 5,
					)
				),
				array( 'isSearch' => true ),
				array(),
				array( 'search', 'any' )
			),
			self::query_case(
				'corpus-fields-ids-nopaging',
				self::query_args(
					array(
						'post_type'      => 'post',
						'fields'         => 'ids',
						'posts_per_page' => -1,
						'no_found_rows'  => false,
					)
				),
				array( 'isHome' => true ),
				array(),
				array( 'fields', 'ids', 'foundRows' )
			),
			self::query_case(
				'corpus-fields-id-parent',
				self::query_args(
					array(
						'post_type'      => array( 'post', 'page' ),
						'fields'         => 'id=>parent',
						'post__in'       => array( $post_ids[2], $page_ids[1] ),
						'orderby'        => 'post__in',
						'posts_per_page' => -1,
						'no_found_rows'  => false,
					)
				),
				array( 'isHome' => true ),
				array(),
				array( 'fields', 'parents' )
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; ++$i ) {
			$cases[] = self::generated_query_case( $ctx->fork( 'case-' . $i ), $i );
		}

		return $cases;
	}

	private static function generated_query_case( \ComponentFuzz\FuzzContext $ctx, int $index ): array {
		$post_ids = self::ids_for_type( 'post' );
		$page_ids = self::ids_for_type( 'page' );
		$fields   = $ctx->weightedChoice(
			array(
				array( 55, 'all' ),
				array( 25, 'ids' ),
				array( 20, 'id=>parent' ),
			)
		);
		$args     = self::query_args(
			array(
				'post_type'      => $ctx->choice( array( 'post', 'post', 'any', array( 'post', 'page' ) ) ),
				'fields'         => $fields,
				'posts_per_page' => 'all' === $fields ? $ctx->int( 1, 4 ) : $ctx->choice( array( -1, 2, 3 ) ),
				'paged'          => $ctx->int( 1, 2 ),
				'orderby'        => $ctx->choice( array( 'ID', 'date', 'title', 'menu_order' ) ),
				'order'          => $ctx->choice( array( 'ASC', 'DESC' ) ),
				'no_found_rows'  => 'all' !== $fields,
			)
		);
		$tags     = array( 'generated', 'case-' . $index );

		switch ( $ctx->choice( array( 'post__in', 'meta', 'tax', 'date', 'parent', 'search', 'page' ) ) ) {
			case 'post__in':
				$args['post__in'] = array_values(
					array_unique(
						array(
							$post_ids[ $ctx->int( 0, count( $post_ids ) - 1 ) ],
							$post_ids[ $ctx->int( 0, count( $post_ids ) - 1 ) ],
							$page_ids[ $ctx->int( 0, count( $page_ids ) - 1 ) ],
						)
					)
				);
				$args['post_type'] = array( 'post', 'page' );
				$args['orderby']   = 'post__in';
				$tags[]            = 'post__in';
				break;

			case 'meta':
				$args['post_type']  = 'post';
				$args['meta_query'] = array(
					'relation' => $ctx->choice( array( 'AND', 'OR' ) ),
					array(
						'key'     => 'loop_score',
						'value'   => array( $ctx->int( 5, 25 ), $ctx->int( 35, 60 ) ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => 'loop_color',
						'value'   => $ctx->choice( array( 'red', 'blue', 'green' ) ),
						'compare' => '=',
					),
				);
				$args['orderby']    = 'meta_value_num';
				$args['meta_key']   = 'loop_score';
				$tags[]             = 'meta';
				break;

			case 'tax':
				$args['post_type'] = 'post';
				$args['tax_query'] = array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_taxonomy_id',
						'terms'    => $ctx->choice( array( array( 7001 ), array( 7002, 7004 ), array( 7003 ) ) ),
						'operator' => $ctx->choice( array( 'IN', 'NOT IN' ) ),
					),
				);
				$tags[]            = 'tax';
				break;

			case 'date':
				$args['post_type'] = 'post';
				if ( $ctx->bool() ) {
					$args['year']     = 2026;
					$args['monthnum'] = $ctx->choice( array( 5, 6 ) );
				} else {
					$args['m'] = $ctx->choice( array( '202606', '20260510', '202601' ) );
				}
				$tags[] = 'date';
				break;

			case 'parent':
				$args['post_type']       = 'post';
				$args['post_parent__in'] = array( $post_ids[0] );
				$args['orderby']         = 'ID';
				$tags[]                  = 'parent';
				break;

			case 'search':
				$args['post_type'] = 'any';
				$args['s']         = $ctx->choice( array( 'Alpha', 'Gamma', 'Page', 'missing-' . $ctx->identifier( 3, 6 ) ) );
				$tags[]            = 'search';
				break;

			case 'page':
				$args['page_id']        = $page_ids[ $ctx->int( 0, count( $page_ids ) - 1 ) ];
				$args['post_type']      = 'page';
				$args['posts_per_page'] = 1;
				$args['fields']         = 'all';
				$tags[]                 = 'page';
				break;
		}

		return self::query_case(
			'generated-' . $index . '-' . implode( '-', array_slice( $tags, 2 ) ),
			$args,
			self::expected_flags_from_args( $args ),
			array(),
			$tags
		);
	}

	private static function query_case( string $label, array $args, array $flags, array $options, array $tags ): array {
		return array(
			'label'   => $label,
			'args'    => $args,
			'expect'  => array( 'flags' => $flags ),
			'options' => $options,
			'tags'    => $tags,
		);
	}

	private static function query_args( array $args ): array {
		return array_merge(
			array(
				'post_type'              => 'post',
				'posts_per_page'         => 3,
				'paged'                  => 1,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lazy_load_term_meta'    => false,
			),
			$args
		);
	}

	private static function expected_flags_from_args( array $args ): array {
		if ( ! empty( $args['page_id'] ) || ! empty( $args['pagename'] ) ) {
			return array( 'isPage' => true );
		}

		if ( ! empty( $args['p'] ) || ! empty( $args['name'] ) ) {
			return array( 'isSingle' => true );
		}

		$flags = array();
		if ( array_key_exists( 's', $args ) ) {
			$flags['isSearch'] = true;
		}
		if ( ! empty( $args['tax_query'] ) && self::tax_query_sets_tax_flag( $args['tax_query'] ) ) {
			$flags['isTax']     = true;
			$flags['isArchive'] = true;
		}
		if ( ! empty( $args['year'] ) || ! empty( $args['monthnum'] ) || ! empty( $args['day'] ) || ! empty( $args['m'] ) ) {
			$flags['isDate']    = true;
			$flags['isArchive'] = true;
		}
		if ( ! empty( $args['paged'] ) && (int) $args['paged'] > 1 ) {
			$flags['isPaged'] = true;
		}
		if ( array() === $flags ) {
			$flags['isHome'] = true;
		}

		return $flags;
	}

	private static function tax_query_sets_tax_flag( array $tax_query ): bool {
		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}
			if ( self::is_nested_query_clause( $clause ) ) {
				if ( self::tax_query_sets_tax_flag( $clause ) ) {
					return true;
				}
				continue;
			}
			if ( 'NOT IN' !== strtoupper( (string) ( $clause['operator'] ?? 'IN' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function matching_posts_for_query( \WP_Query $query ): array {
		$vars  = $query->query_vars;
		$posts = array_values(
			array_filter(
				self::$posts,
				static function ( \WP_Post $post ) use ( $vars ): bool {
					return self::post_matches_query_vars( $post, $vars );
				}
			)
		);

		return self::sort_posts_for_query( $posts, $vars );
	}

	private static function post_matches_query_vars( \WP_Post $post, array $vars ): bool {
		$page_id = (int) ( $vars['page_id'] ?? 0 );
		$post_id = (int) ( $vars['p'] ?? 0 );

		if ( $page_id > 0 ) {
			return 'page' === $post->post_type && (int) $post->ID === $page_id;
		}

		if ( $post_id > 0 ) {
			return 'post' === $post->post_type && (int) $post->ID === $post_id;
		}

		if ( ! self::post_type_matches( $post, $vars['post_type'] ?? 'post' ) ) {
			return false;
		}

		foreach ( array( 'name' => 'post', 'pagename' => 'page' ) as $name_var => $type ) {
			if ( '' !== (string) ( $vars[ $name_var ] ?? '' ) ) {
				if ( $type !== $post->post_type || (string) $post->post_name !== (string) $vars[ $name_var ] ) {
					return false;
				}
			}
		}

		if ( '' !== (string) ( $vars['title'] ?? '' ) && (string) $post->post_title !== (string) $vars['title'] ) {
			return false;
		}

		if ( ! self::id_list_allows_post( $post, $vars ) ) {
			return false;
		}

		if ( ! self::parent_vars_allow_post( $post, $vars ) ) {
			return false;
		}

		if ( ! self::search_allows_post( $post, $vars ) ) {
			return false;
		}

		if ( ! self::date_vars_allow_post( $post, $vars ) ) {
			return false;
		}

		if ( ! self::meta_vars_allow_post( $post, $vars ) ) {
			return false;
		}

		if ( ! self::tax_vars_allow_post( $post, $vars ) ) {
			return false;
		}

		return true;
	}

	private static function post_type_matches( \WP_Post $post, $post_type ): bool {
		if ( '' === $post_type || null === $post_type ) {
			$post_type = 'post';
		}
		if ( 'any' === $post_type ) {
			return in_array( $post->post_type, array( 'post', 'page' ), true );
		}
		if ( is_array( $post_type ) ) {
			return in_array( $post->post_type, $post_type, true );
		}

		return $post->post_type === (string) $post_type;
	}

	private static function id_list_allows_post( \WP_Post $post, array $vars ): bool {
		$id = (int) $post->ID;

		if ( ! empty( $vars['post__in'] ) ) {
			$ids = array_map( 'intval', (array) $vars['post__in'] );
			if ( ! in_array( $id, $ids, true ) ) {
				return false;
			}
		}

		if ( ! empty( $vars['post__not_in'] ) ) {
			$ids = array_map( 'intval', (array) $vars['post__not_in'] );
			if ( in_array( $id, $ids, true ) ) {
				return false;
			}
		}

		return true;
	}

	private static function parent_vars_allow_post( \WP_Post $post, array $vars ): bool {
		if ( '' !== (string) ( $vars['post_parent'] ?? '' ) && (int) $post->post_parent !== (int) $vars['post_parent'] ) {
			return false;
		}

		if ( ! empty( $vars['post_parent__in'] ) ) {
			$parents = array_map( 'intval', (array) $vars['post_parent__in'] );
			if ( ! in_array( (int) $post->post_parent, $parents, true ) ) {
				return false;
			}
		}

		if ( ! empty( $vars['post_parent__not_in'] ) ) {
			$parents = array_map( 'intval', (array) $vars['post_parent__not_in'] );
			if ( in_array( (int) $post->post_parent, $parents, true ) ) {
				return false;
			}
		}

		return true;
	}

	private static function search_allows_post( \WP_Post $post, array $vars ): bool {
		if ( ! array_key_exists( 's', $vars ) || '' === (string) $vars['s'] ) {
			return true;
		}

		$needle = strtolower( (string) $vars['s'] );
		$haystack = strtolower( $post->post_title . ' ' . $post->post_content . ' ' . $post->post_excerpt );

		return str_contains( $haystack, $needle );
	}

	private static function date_vars_allow_post( \WP_Post $post, array $vars ): bool {
		$timestamp = strtotime( $post->post_date );
		$year      = (int) gmdate( 'Y', $timestamp );
		$month     = (int) gmdate( 'n', $timestamp );
		$day       = (int) gmdate( 'j', $timestamp );

		if ( ! empty( $vars['m'] ) ) {
			$m = (string) $vars['m'];
			if ( strlen( $m ) >= 4 && $year !== (int) substr( $m, 0, 4 ) ) {
				return false;
			}
			if ( strlen( $m ) >= 6 && $month !== (int) substr( $m, 4, 2 ) ) {
				return false;
			}
			if ( strlen( $m ) >= 8 && $day !== (int) substr( $m, 6, 2 ) ) {
				return false;
			}
		}

		if ( ! empty( $vars['year'] ) && $year !== (int) $vars['year'] ) {
			return false;
		}
		if ( ! empty( $vars['monthnum'] ) && $month !== (int) $vars['monthnum'] ) {
			return false;
		}
		if ( ! empty( $vars['day'] ) && $day !== (int) $vars['day'] ) {
			return false;
		}

		return true;
	}

	private static function meta_vars_allow_post( \WP_Post $post, array $vars ): bool {
		if ( ! empty( $vars['meta_key'] ) && ! array_key_exists( (string) $vars['meta_key'], self::$post_meta[ (int) $post->ID ] ?? array() ) ) {
			return false;
		}

		if ( ! empty( $vars['meta_key'] ) && array_key_exists( 'meta_value', $vars ) && '' !== (string) $vars['meta_value'] ) {
			$value = self::$post_meta[ (int) $post->ID ][ (string) $vars['meta_key'] ] ?? null;
			if ( (string) $value !== (string) $vars['meta_value'] ) {
				return false;
			}
		}

		$meta_query = $vars['meta_query'] ?? array();
		if ( ! is_array( $meta_query ) || array() === $meta_query ) {
			return true;
		}

		return self::query_clauses_allow_post( $post, $meta_query, 'meta' );
	}

	private static function tax_vars_allow_post( \WP_Post $post, array $vars ): bool {
		$tax_query = $vars['tax_query'] ?? array();
		if ( ! is_array( $tax_query ) || array() === $tax_query ) {
			return true;
		}

		return self::query_clauses_allow_post( $post, $tax_query, 'tax' );
	}

	private static function query_clauses_allow_post( \WP_Post $post, array $query, string $type ): bool {
		$relation = strtoupper( (string) ( $query['relation'] ?? 'AND' ) );
		$matches  = array();

		foreach ( $query as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}

			if ( self::is_nested_query_clause( $clause ) ) {
				$matches[] = self::query_clauses_allow_post( $post, $clause, $type );
				continue;
			}

			$matches[] = 'meta' === $type
				? self::meta_clause_allows_post( $post, $clause )
				: self::tax_clause_allows_post( $post, $clause );
		}

		if ( array() === $matches ) {
			return true;
		}

		return 'OR' === $relation ? in_array( true, $matches, true ) : ! in_array( false, $matches, true );
	}

	private static function is_nested_query_clause( array $clause ): bool {
		foreach ( $clause as $key => $value ) {
			if ( 'relation' !== $key && is_array( $value ) && ! array_key_exists( 'key', $clause ) && ! array_key_exists( 'taxonomy', $clause ) ) {
				return true;
			}
		}

		return false;
	}

	private static function meta_clause_allows_post( \WP_Post $post, array $clause ): bool {
		$key     = (string) ( $clause['key'] ?? '' );
		$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
		$value   = $clause['value'] ?? null;
		$actual  = self::$post_meta[ (int) $post->ID ][ $key ] ?? null;

		if ( 'EXISTS' === $compare ) {
			return null !== $actual;
		}
		if ( 'NOT EXISTS' === $compare ) {
			return null === $actual;
		}
		if ( null === $actual ) {
			return false;
		}

		if ( 'NUMERIC' === strtoupper( (string) ( $clause['type'] ?? '' ) ) ) {
			$actual = (float) $actual;
		}

		switch ( $compare ) {
			case '!=':
			case 'NOT LIKE':
				return (string) $actual !== (string) $value;
			case 'IN':
				return in_array( (string) $actual, array_map( 'strval', (array) $value ), true );
			case 'NOT IN':
				return ! in_array( (string) $actual, array_map( 'strval', (array) $value ), true );
			case 'BETWEEN':
				$range = array_values( (array) $value );
				return isset( $range[0], $range[1] ) && (float) $actual >= (float) $range[0] && (float) $actual <= (float) $range[1];
			case '>':
				return (float) $actual > (float) $value;
			case '>=':
				return (float) $actual >= (float) $value;
			case '<':
				return (float) $actual < (float) $value;
			case '<=':
				return (float) $actual <= (float) $value;
			default:
				return (string) $actual === (string) $value;
		}
	}

	private static function tax_clause_allows_post( \WP_Post $post, array $clause ): bool {
		$taxonomy = (string) ( $clause['taxonomy'] ?? '' );
		$field    = (string) ( $clause['field'] ?? 'slug' );
		$operator = strtoupper( (string) ( $clause['operator'] ?? 'IN' ) );
		$terms    = array_map( 'strval', (array) ( $clause['terms'] ?? array() ) );
		$actual   = array_map( 'strval', self::$post_terms[ (int) $post->ID ][ $taxonomy ][ $field ] ?? array() );
		$hit      = array() !== array_intersect( $terms, $actual );

		if ( 'NOT IN' === $operator ) {
			return ! $hit;
		}
		if ( 'AND' === $operator ) {
			return array() === array_diff( $terms, $actual );
		}

		return $hit;
	}

	private static function sort_posts_for_query( array $posts, array $vars ): array {
		$orderby = $vars['orderby'] ?? 'date';
		$order   = 'ASC' === strtoupper( (string) ( $vars['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		if ( is_array( $orderby ) ) {
			$orderby = (string) array_key_first( $orderby );
		}

		usort(
			$posts,
			static function ( \WP_Post $a, \WP_Post $b ) use ( $orderby, $order, $vars ): int {
				if ( 'post__in' === $orderby && ! empty( $vars['post__in'] ) ) {
					$order_map = array_flip( array_map( 'intval', (array) $vars['post__in'] ) );
					return ( $order_map[ (int) $a->ID ] ?? PHP_INT_MAX ) <=> ( $order_map[ (int) $b->ID ] ?? PHP_INT_MAX );
				}

				switch ( $orderby ) {
					case 'ID':
					case 'id':
						$result = (int) $a->ID <=> (int) $b->ID;
						break;
					case 'title':
						$result = strcasecmp( $a->post_title, $b->post_title );
						break;
					case 'menu_order':
						$result = (int) $a->menu_order <=> (int) $b->menu_order;
						break;
					case 'meta_value_num':
						$key    = (string) ( $vars['meta_key'] ?? 'loop_score' );
						$result = (float) ( self::$post_meta[ (int) $a->ID ][ $key ] ?? 0 ) <=> (float) ( self::$post_meta[ (int) $b->ID ][ $key ] ?? 0 );
						break;
					case 'name':
						$result = strcmp( $a->post_name, $b->post_name );
						break;
					case 'date':
					default:
						$result = strcmp( $a->post_date, $b->post_date );
						break;
				}

				if ( 0 === $result ) {
					$result = (int) $a->ID <=> (int) $b->ID;
				}

				return 'DESC' === $order ? -$result : $result;
			}
		);

		return $posts;
	}

	private static function apply_query_limits( array $posts, array $vars ): array {
		$per_page = (int) ( $vars['posts_per_page'] ?? 0 );
		if ( ! empty( $vars['nopaging'] ) || -1 === $per_page ) {
			return array_values( $posts );
		}

		$per_page = max( 1, $per_page );
		if ( '' !== (string) ( $vars['offset'] ?? '' ) ) {
			$offset = max( 0, (int) $vars['offset'] );
		} else {
			$paged  = max( 1, (int) ( $vars['paged'] ?? 1 ) );
			$offset = ( $paged - 1 ) * $per_page;
		}

		return array_slice( array_values( $posts ), $offset, $per_page );
	}

	private static function expected_query_observation( \WP_Query $query, array $case ): array {
		$matches = self::matching_posts_for_query( $query );
		$page    = self::apply_query_limits( $matches, $query->query_vars );
		$page    = self::apply_expected_sticky_reorder( $query, $page, $case['options']['sticky_posts'] ?? array() );
		$found   = ! (bool) ( $query->query_vars['no_found_rows'] ?? false ) ? count( $matches ) : 0;

		return array(
			'allIds'      => self::post_ids( $matches ),
			'pageIds'     => self::post_ids( $page ),
			'foundPosts'  => $found,
			'maxNumPages' => ! (bool) ( $query->query_vars['no_found_rows'] ?? false ) ? self::expected_max_pages( count( $matches ), $query->query_vars ) : 0,
			'fieldShape'  => $query->query_vars['fields'] ?? 'all',
		);
	}

	private static function apply_expected_sticky_reorder( \WP_Query $query, array $posts, array $sticky_posts ): array {
		if (
			! $query->is_home()
			|| (int) ( $query->query_vars['paged'] ?? 0 ) > 1
			|| (bool) ( $query->query_vars['ignore_sticky_posts'] ?? false )
			|| array() === $sticky_posts
			|| 'all' !== ( $query->query_vars['fields'] ?? 'all' )
		) {
			return $posts;
		}

		$ordered = array();
		foreach ( $sticky_posts as $sticky_id ) {
			foreach ( $posts as $post ) {
				if ( (int) $post->ID === (int) $sticky_id ) {
					$ordered[] = $post;
				}
			}
		}

		foreach ( $posts as $post ) {
			if ( ! in_array( (int) $post->ID, array_map( 'intval', $sticky_posts ), true ) ) {
				$ordered[] = $post;
			}
		}

		return $ordered;
	}

	private static function expected_max_pages( int $found_posts, array $vars ): int {
		$per_page = (int) ( $vars['posts_per_page'] ?? 0 );
		if ( ! empty( $vars['nopaging'] ) || $per_page <= 0 ) {
			return 0;
		}

		return (int) ceil( $found_posts / $per_page );
	}

	private static function normalization_and_counts_match( \WP_Query $query, array $case, array $expect ): bool {
		$vars = $query->query_vars;

		if ( ! in_array( $vars['fields'] ?? null, array( 'all', 'ids', 'id=>parent' ), true ) ) {
			return false;
		}
		if ( ! is_int( $vars['posts_per_page'] ?? null ) || ! is_int( $vars['paged'] ?? null ) ) {
			return false;
		}
		if ( ! is_bool( $vars['no_found_rows'] ?? null ) || ! is_bool( $vars['ignore_sticky_posts'] ?? null ) ) {
			return false;
		}
		if ( ! self::normalized_id_lists_are_integer_lists( $vars ) ) {
			return false;
		}
		if ( ! empty( $case['args']['meta_query'] ) && ! $query->meta_query instanceof \WP_Meta_Query ) {
			return false;
		}
		if ( ! empty( $case['args']['tax_query'] ) && ! $query->tax_query instanceof \WP_Tax_Query ) {
			return false;
		}

		$actual = self::query_result_observation( $query );

		return $expect['pageIds'] === $actual['ids']
			&& $expect['foundPosts'] === $actual['foundPosts']
			&& $expect['maxNumPages'] === $actual['maxNumPages']
			&& count( $expect['pageIds'] ) === $actual['postCount']
			&& self::post_field_shape_matches( $query );
	}

	private static function normalized_id_lists_are_integer_lists( array $vars ): bool {
		foreach ( array( 'post__in', 'post__not_in', 'post_parent__in', 'post_parent__not_in' ) as $key ) {
			if ( empty( $vars[ $key ] ) ) {
				continue;
			}
			foreach ( (array) $vars[ $key ] as $value ) {
				if ( ! is_int( $value ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function post_field_shape_matches( \WP_Query $query ): bool {
		$fields = $query->query_vars['fields'] ?? 'all';
		foreach ( $query->posts as $post ) {
			if ( 'ids' === $fields && ! is_int( $post ) ) {
				return false;
			}
			if ( 'id=>parent' === $fields && ( ! is_object( $post ) || ! isset( $post->ID, $post->post_parent ) || ! is_int( $post->ID ) || ! is_int( $post->post_parent ) ) ) {
				return false;
			}
			if ( 'all' === $fields && ! $post instanceof \WP_Post ) {
				return false;
			}
		}

		return true;
	}

	private static function query_result_observation( \WP_Query $query ): array {
		return array(
			'ids'         => self::post_ids( $query->posts ),
			'postCount'   => (int) $query->post_count,
			'foundPosts'  => (int) $query->found_posts,
			'maxNumPages' => (int) $query->max_num_pages,
			'fieldShape'  => $query->query_vars['fields'] ?? 'all',
		);
	}

	private static function query_flags_match( \WP_Query $query, array $case ): bool {
		$actual = self::query_flags( $query );

		foreach ( $case['expect']['flags'] ?? array() as $flag => $expected ) {
			if ( ! array_key_exists( $flag, $actual ) || (bool) $expected !== (bool) $actual[ $flag ] ) {
				return false;
			}
		}

		return true;
	}

	private static function request_shape_matches( \WP_Query $query, array $case ): bool {
		$request = (string) $query->request;
		$args    = $case['args'];

		if ( '' === $request || ! str_contains( $request, 'SELECT' ) || ! str_contains( $request, 'wp_posts' ) || str_contains( $request, "\0" ) ) {
			return false;
		}

		$expects_limit = ! $query->is_singular()
			&& empty( $query->query_vars['nopaging'] )
			&& (int) ( $query->query_vars['posts_per_page'] ?? 0 ) > 0;
		if ( $expects_limit !== str_contains( $request, 'LIMIT' ) ) {
			return false;
		}
		if ( ! empty( $args['meta_query'] ) && ! str_contains( $request, 'wp_postmeta' ) ) {
			return false;
		}
		if ( ! empty( $args['tax_query'] ) && ! str_contains( $request, 'wp_term_relationships' ) ) {
			return false;
		}
		if ( ( ! empty( $args['year'] ) || ! empty( $args['monthnum'] ) || ! empty( $args['day'] ) || ! empty( $args['m'] ) ) && ! str_contains( $request, 'post_date' ) ) {
			return false;
		}

		return true;
	}

	private static function observe_query_loop( \WP_Query $query ): array {
		$seen       = array();
		$aligned    = true;
		$iterations = 0;
		$fields     = $query->query_vars['fields'] ?? 'all';

		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;

		while ( $query->have_posts() ) {
			$query->the_post();
			++$iterations;

			$global_id = $GLOBALS['post'] instanceof \WP_Post ? (int) $GLOBALS['post']->ID : 0;
			$seen[]    = $global_id;

			if ( 'all' === $fields ) {
				$aligned = $aligned
					&& $query->post instanceof \WP_Post
					&& $GLOBALS['post'] instanceof \WP_Post
					&& (int) $query->post->ID === $global_id;
			} elseif ( 'ids' === $fields ) {
				$aligned = $aligned && (int) $query->post === $global_id && $GLOBALS['post'] instanceof \WP_Post;
			} else {
				$aligned = $aligned
					&& is_object( $query->post )
					&& isset( $query->post->ID )
					&& (int) $query->post->ID === $global_id
					&& $GLOBALS['post'] instanceof \WP_Post;
			}

			if ( $iterations > 50 ) {
				$aligned = false;
				break;
			}
		}

		return array(
			'seen'        => $seen,
			'aligned'     => $aligned,
			'currentPost' => $query->current_post,
			'inTheLoop'   => $query->in_the_loop,
			'beforeLoop'  => $query->before_loop,
			'post'        => self::describe_value( $query->post ),
		);
	}

	private static function loop_observation_matches( \WP_Query $query, array $loop, array $events, array $expect ): bool {
		$expected_events = array() === $expect['pageIds'] ? array( 'loop_no_results' ) : array( 'loop_start', 'loop_end' );

		return $expect['pageIds'] === $loop['seen']
			&& $expected_events === $events
			&& true === $loop['aligned']
			&& -1 === $loop['currentPost']
			&& false === $loop['inTheLoop']
			&& false === $loop['beforeLoop']
			&& ( array() === $expect['pageIds'] || null !== $query->post );
	}

	private static function selected_query_vars( \WP_Query $query ): array {
		$keys = array(
			'fields',
			'post_type',
			'posts_per_page',
			'paged',
			'offset',
			'nopaging',
			'no_found_rows',
			'ignore_sticky_posts',
			'post__in',
			'post__not_in',
			'post_parent',
			'post_parent__in',
			'orderby',
			'order',
			'meta_key',
			'meta_query',
			'tax_query',
			'year',
			'monthnum',
			'day',
			'm',
			's',
			'p',
			'page_id',
		);
		$out  = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $query->query_vars ) ) {
				$out[ $key ] = $query->query_vars[ $key ];
			}
		}

		return $out;
	}

	private static function case_row( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case, string $invariant, bool $ok, array $data = array() ): array {
		$data = array_merge(
			array(
				'case' => array(
					'index' => $case_index,
					'label' => $case['label'] ?? 'case-' . $case_index,
					'tags'  => $case['tags'] ?? array(),
					'args'  => $case['args'] ?? array(),
				),
			),
			$data
		);

		return self::row( $ctx, $invariant, $ok, $data );
	}

	private static function cached_posts_for_ids( array $ids ): array {
		$posts = array();
		foreach ( $ids as $id ) {
			$posts[] = \wp_cache_get( (int) $id, 'posts' );
		}

		return $posts;
	}

	private static function prime_post_caches(): void {
		foreach ( self::$posts as $post ) {
			\wp_cache_set( (int) $post->ID, $post, 'posts' );
			\wp_cache_set( 'post_parent:' . (string) $post->ID, (int) $post->post_parent, 'posts' );
		}
	}

	private static function delete_post_caches(): void {
		foreach ( self::$posts as $post ) {
			\wp_cache_delete( (int) $post->ID, 'posts' );
			\wp_cache_delete( 'post_parent:' . (string) $post->ID, 'posts' );
		}
	}

	private static function wpdb_public_state(): array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return array();
		}

		$wpdb = $GLOBALS['wpdb'];
		$keys = array( 'suppress_errors', 'last_error' );
		$out  = array();

		foreach ( $keys as $key ) {
			if ( property_exists( $wpdb, $key ) ) {
				$out[ $key ] = $wpdb->$key;
			}
		}
		if ( method_exists( $wpdb, 'component_fuzz_get_options' ) ) {
			$out['options'] = $wpdb->component_fuzz_get_options();
		}
		if ( method_exists( $wpdb, 'component_fuzz_content_counts' ) ) {
			$out['contentCounts'] = $wpdb->component_fuzz_content_counts();
		}

		return $out;
	}

	private static function post_fixtures( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::slug( $ctx->fork( 'token' ), 'loop' );
		$posts = array(
			self::post_fixture(
				91001,
				"post-a-{$token}",
				'Alpha <script>post</script> ' . $ctx->text( 0, 18 ),
				0,
				'post',
				array(
					'post_date'         => '2026-06-23 12:00:00',
					'post_date_gmt'     => '2026-06-23 12:00:00',
					'post_modified'     => '2026-06-23 12:10:00',
					'post_modified_gmt' => '2026-06-23 12:10:00',
					'menu_order'        => 2,
					'post_author'       => 1,
				)
			),
			self::post_fixture(
				91002,
				"post-b-{$token}",
				'Beta & sticky post ' . $ctx->text( 0, 18 ),
				0,
				'post',
				array(
					'post_date'         => '2026-06-22 09:30:00',
					'post_date_gmt'     => '2026-06-22 09:30:00',
					'post_modified'     => '2026-06-22 09:40:00',
					'post_modified_gmt' => '2026-06-22 09:40:00',
					'menu_order'        => 1,
					'post_author'       => 2,
				)
			),
			self::post_fixture(
				91003,
				"post-c-{$token}",
				'Gamma child post ' . $ctx->text( 0, 18 ),
				91001,
				'post',
				array(
					'post_date'         => '2026-05-10 08:15:00',
					'post_date_gmt'     => '2026-05-10 08:15:00',
					'post_modified'     => '2026-05-10 08:20:00',
					'post_modified_gmt' => '2026-05-10 08:20:00',
					'menu_order'        => 3,
					'post_author'       => 1,
				)
			),
			self::post_fixture(
				91004,
				"post-d-{$token}",
				'Delta green post ' . $ctx->text( 0, 18 ),
				0,
				'post',
				array(
					'post_date'         => '2026-01-05 16:45:00',
					'post_date_gmt'     => '2026-01-05 16:45:00',
					'post_modified'     => '2026-01-05 16:50:00',
					'post_modified_gmt' => '2026-01-05 16:50:00',
					'menu_order'        => 4,
					'post_author'       => 3,
				)
			),
			self::post_fixture(
				92001,
				"page-a-{$token}",
				'Page <strong>A</strong> ' . $ctx->text( 0, 18 ),
				0,
				'page',
				array(
					'post_date'         => '2026-06-20 07:00:00',
					'post_date_gmt'     => '2026-06-20 07:00:00',
					'post_modified'     => '2026-06-20 07:05:00',
					'post_modified_gmt' => '2026-06-20 07:05:00',
					'menu_order'        => 5,
				)
			),
			self::post_fixture(
				92002,
				"page-b-{$token}",
				'Page Beta ' . $ctx->text( 0, 18 ),
				92001,
				'page',
				array(
					'post_date'         => '2026-05-15 07:00:00',
					'post_date_gmt'     => '2026-05-15 07:00:00',
					'post_modified'     => '2026-05-15 07:05:00',
					'post_modified_gmt' => '2026-05-15 07:05:00',
					'menu_order'        => 6,
				)
			),
		);

		self::$post_meta = array(
			91001 => array(
				'loop_color' => 'red',
				'loop_score' => 10,
			),
			91002 => array(
				'loop_color' => 'blue',
				'loop_score' => 40,
			),
			91003 => array(
				'loop_color' => 'red',
				'loop_score' => 30,
			),
			91004 => array(
				'loop_color' => 'green',
				'loop_score' => 55,
			),
			92001 => array(
				'loop_color' => 'blue',
				'loop_score' => 20,
			),
			92002 => array(
				'loop_color' => 'green',
				'loop_score' => 25,
			),
		);
		self::$post_terms = array(
			91001 => array(
				self::TAXONOMY => array(
					'slug'             => array( 'red', 'featured' ),
					'term_taxonomy_id' => array( 7001, 7004 ),
				),
			),
			91002 => array(
				self::TAXONOMY => array(
					'slug'             => array( 'blue', 'featured' ),
					'term_taxonomy_id' => array( 7002, 7004 ),
				),
			),
			91003 => array(
				self::TAXONOMY => array(
					'slug'             => array( 'red' ),
					'term_taxonomy_id' => array( 7001 ),
				),
			),
			91004 => array(
				self::TAXONOMY => array(
					'slug'             => array( 'green' ),
					'term_taxonomy_id' => array( 7003 ),
				),
			),
			92001 => array(
				self::TAXONOMY => array(
					'slug'             => array( 'blue' ),
					'term_taxonomy_id' => array( 7002 ),
				),
			),
			92002 => array(
				self::TAXONOMY => array(
					'slug'             => array( 'green' ),
					'term_taxonomy_id' => array( 7003 ),
				),
			),
		);

		return $posts;
	}

	private static function post_fixture( int $id, string $slug, string $title, int $parent, string $type, array $overrides = array() ): \WP_Post {
		return new \WP_Post(
			(object) array_merge(
				array(
				'ID'                    => $id,
				'post_author'           => 1,
				'post_date'             => '2026-06-23 12:00:00',
				'post_date_gmt'         => '2026-06-23 12:00:00',
				'post_content'          => 'Content for ' . $slug,
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $slug,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 12:00:00',
				'post_modified_gmt'     => '2026-06-23 12:00:00',
				'post_content_filtered' => '',
				'post_parent'           => $parent,
				'guid'                  => 'http://example.test/?p=' . $id,
				'menu_order'            => 0,
				'post_type'             => $type,
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
				),
				$overrides
			)
		);
	}

	private static function first_post_of_type( string $type ): \WP_Post {
		foreach ( self::$posts as $post ) {
			if ( $type === $post->post_type ) {
				return $post;
			}
		}

		throw new \RuntimeException( 'Missing post fixture for type ' . $type );
	}

	private static function ids_for_type( string $type ): array {
		return self::post_ids(
			array_values(
				array_filter(
					self::$posts,
					static fn ( \WP_Post $post ): bool => $type === $post->post_type
				)
			)
		);
	}

	private static function post_ids( array $posts ): array {
		return array_map(
			static function ( $post ): int {
				if ( $post instanceof \WP_Post || ( is_object( $post ) && isset( $post->ID ) ) ) {
					return (int) $post->ID;
				}

				return (int) $post;
			},
			$posts
		);
	}

	private static function max_pages( int $count, int $per_page ): int {
		return $per_page > 0 ? (int) ceil( $count / $per_page ) : 0;
	}

	private static function query_flags( \WP_Query $query ): array {
		return array(
			'isHome'    => $query->is_home(),
			'isPage'    => $query->is_page(),
			'isSingle'  => $query->is_single(),
			'isSearch'  => $query->is_search(),
			'isArchive' => $query->is_archive(),
			'isDate'    => $query->is_date(),
			'isTax'     => $query->is_tax(),
			'isPaged'   => $query->is_paged(),
			'is404'     => $query->is_404(),
		);
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition || count( $failures ) >= self::MAX_FAILURES ) {
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

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'id',
					'authordata',
					'currentday',
					'currentmonth',
					'page',
					'pages',
					'multipage',
					'more',
					'numpages',
					'post',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_post_statuses',
					'wp_post_types',
					'wp_taxonomies',
					'wp_query',
					'wp_the_query',
					'wpdb',
				)
			),
			'obLevel' => ob_get_level(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}
		self::restore_globals( $snapshot['globals'] );
	}

	private static function state_matches( array $snapshot ): bool {
		return ob_get_level() === $snapshot['obLevel']
			&& self::globals_match( $snapshot['globals'] );
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
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
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function globals_match( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		return $value;
	}

	private static function describe_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return self::describe_value( $post );
		}

		return array(
			'ID'       => (int) $post->ID,
			'type'     => $post->post_type,
			'name'     => self::describe_string( $post->post_name ),
			'title'    => self::describe_string( $post->post_title ),
			'parent'   => (int) $post->post_parent,
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
				if ( $i >= 20 ) {
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
			if ( $value instanceof \WP_Post ) {
				return self::describe_post( $value );
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

	private static function escape_bytes( string $value, int $limit = 180 ): string {
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

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $fallback ): string {
		$slug = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '-', $ctx->identifier( 3, 14 ) ) );
		$slug = trim( $slug, '-' );
		return '' === $slug ? $fallback : $slug;
	}
}
