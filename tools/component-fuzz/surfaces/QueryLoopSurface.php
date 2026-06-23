<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WP_Query loop execution state through pre-query fixtures.
 */
final class QueryLoopSurface {
	public const NAME = 'query-loop';

	private const MAX_FAILURES = 8;

	private static array $posts = array();

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

			$rows[] = self::check_pre_query_loop_state( $ctx->fork( 'pre-query' ) );
			$rows[] = self::check_global_wrappers_and_reset( $ctx->fork( 'global-wrappers' ) );
			$rows[] = self::check_query_flags_and_selected_posts( $ctx->fork( 'flags' ) );
			$rows[] = self::check_empty_query_events( $ctx->fork( 'empty' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'query-loop.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::$posts = array();
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

		$fixture = self::selected_posts_for_query( $query );
		$query->found_posts   = count( $fixture );
		$query->max_num_pages = self::max_pages( count( $fixture ), (int) ( $query->query_vars['posts_per_page'] ?? 0 ) );

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
				'create_initial_post_types',
				'get_post',
				'get_post_status_object',
				'get_post_type_object',
				'have_posts',
				'remove_filter',
				'rewind_posts',
				'setup_postdata',
				'the_post',
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
	}

	private static function check_pre_query_loop_state( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$events   = array();
		$actions  = array();
		$query    = self::run_pre_query(
			array(
				'post_type'              => 'post',
				'posts_per_page'         => $ctx->int( 1, 4 ),
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
			self::remove_loop_actions( $actions );
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
				self::ids_for_type( 'post' ) === $wrapper_seen
					&& -1 === $query->current_post
					&& true === $setup_ok
					&& $GLOBALS['post'] instanceof \WP_Post
					&& (int) $GLOBALS['post']->ID === (int) $first->ID,
				'global loop wrappers delegate to wp_query and setup/reset postdata coherently',
				array(
					'seen'        => $wrapper_seen,
					'events'      => $events,
					'setupOk'     => $setup_ok,
					'globalPost'  => self::describe_post( $GLOBALS['post'] ?? null ),
					'firstPost'   => self::describe_post( $first ),
					'currentPost' => $query->current_post,
				)
			);
		} finally {
			self::remove_loop_actions( $actions );
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
			self::remove_loop_actions( $actions_page );
			self::remove_loop_actions( $actions_post );
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
			self::remove_loop_actions( $actions );
		}

		return self::row(
			$ctx,
			'query-loop.empty-query-events',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function run_pre_query( array $args, array &$events, array &$actions ): \WP_Query {
		$events = array();
		$loop_start = static function () use ( &$events ): void {
			$events[] = 'loop_start';
		};
		$loop_end = static function () use ( &$events ): void {
			$events[] = 'loop_end';
		};
		$loop_no_results = static function () use ( &$events ): void {
			$events[] = 'loop_no_results';
		};
		$actions = array( $loop_start, $loop_end, $loop_no_results );

		\add_filter( 'posts_pre_query', array( self::class, 'filter_posts_pre_query' ), 10, 2 );
		\add_action( 'loop_start', $loop_start, 10, 1 );
		\add_action( 'loop_end', $loop_end, 10, 1 );
		\add_action( 'loop_no_results', $loop_no_results, 10, 1 );

		try {
			$query = new \WP_Query( $args );
		} finally {
			\remove_filter( 'posts_pre_query', array( self::class, 'filter_posts_pre_query' ), 10 );
		}

		return $query;
	}

	private static function remove_loop_actions( array $actions ): void {
		if ( 3 !== count( $actions ) ) {
			return;
		}

		\remove_action( 'loop_start', $actions[0], 10 );
		\remove_action( 'loop_end', $actions[1], 10 );
		\remove_action( 'loop_no_results', $actions[2], 10 );
	}

	private static function selected_posts_for_query( \WP_Query $query ): array {
		$vars      = $query->query_vars;
		$post_type = $vars['post_type'] ?? 'post';
		$page_id   = (int) ( $vars['page_id'] ?? 0 );
		$post_id   = (int) ( $vars['p'] ?? 0 );

		return array_values(
			array_filter(
				self::$posts,
				static function ( \WP_Post $post ) use ( $post_type, $page_id, $post_id ): bool {
					if ( $page_id > 0 ) {
						return 'page' === $post->post_type && (int) $post->ID === $page_id;
					}
					if ( $post_id > 0 ) {
						return 'post' === $post->post_type && (int) $post->ID === $post_id;
					}
					return $post->post_type === $post_type;
				}
			)
		);
	}

	private static function post_fixtures( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::slug( $ctx->fork( 'token' ), 'loop' );

		return array(
			self::post_fixture( 91001, "post-a-{$token}", 'Alpha <script>post</script> ' . $ctx->text( 0, 18 ), 0, 'post' ),
			self::post_fixture( 91002, "post-b-{$token}", 'Beta & post ' . $ctx->text( 0, 18 ), 0, 'post' ),
			self::post_fixture( 91003, "post-c-{$token}", 'Gamma post ' . $ctx->text( 0, 18 ), 91001, 'post' ),
			self::post_fixture( 92001, "page-a-{$token}", 'Page <strong>A</strong> ' . $ctx->text( 0, 18 ), 0, 'page' ),
		);
	}

	private static function post_fixture( int $id, string $slug, string $title, int $parent, string $type ): \WP_Post {
		return new \WP_Post(
			(object) array(
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
				return (int) ( $post instanceof \WP_Post ? $post->ID : $post );
			},
			$posts
		);
	}

	private static function max_pages( int $count, int $per_page ): int {
		return $per_page > 0 ? (int) ceil( $count / $per_page ) : 0;
	}

	private static function query_flags( \WP_Query $query ): array {
		return array(
			'isHome'   => $query->is_home(),
			'isPage'   => $query->is_page(),
			'isSingle' => $query->is_single(),
			'isSearch' => $query->is_search(),
			'is404'    => $query->is_404(),
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
					'wp_post_statuses',
					'wp_post_types',
					'wp_query',
					'wp_the_query',
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
