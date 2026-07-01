<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes selected dynamic core block render callbacks with synthetic fixtures.
 */
final class CoreBlockRenderSurface {
	public const NAME = 'core-block-render';

	private const CASES        = 6;
	private const MAX_FAILURES = 8;

	private const CORE_BLOCKS = array(
		'archives'                   => 'register_block_core_archives',
		'calendar'                   => 'register_block_core_calendar',
		'categories'                 => 'register_block_core_categories',
		'post-title'                 => 'register_block_core_post_title',
		'post-date'                  => 'register_block_core_post_date',
		'post-excerpt'               => 'register_block_core_post_excerpt',
		'read-more'                  => 'register_block_core_read_more',
		'latest-comments'            => 'register_block_core_latest_comments',
		'latest-posts'               => 'register_block_core_latest_posts',
		'site-title'                 => 'register_block_core_site_title',
		'site-tagline'               => 'register_block_core_site_tagline',
		'tag-cloud'                  => 'register_block_core_tag_cloud',
		'query-pagination'           => 'register_block_core_query_pagination',
		'query-pagination-next'      => 'register_block_core_query_pagination_next',
		'query-pagination-previous'  => 'register_block_core_query_pagination_previous',
		'query-pagination-numbers'   => 'register_block_core_query_pagination_numbers',
		'search'                     => 'register_block_core_search',
		'loginout'                   => 'register_block_core_loginout',
		'button'                     => 'register_block_core_button',
		'file'                       => 'register_block_core_file',
		'image'                      => 'register_block_core_image',
		'navigation-link'            => 'register_block_core_navigation_link',
		'navigation-submenu'         => 'register_block_core_navigation_submenu',
		'page-list-item'             => 'register_block_core_page_list_item',
		'page-list'                  => 'register_block_core_page_list',
		'home-link'                  => 'register_block_core_home_link',
		'navigation'                 => 'register_block_core_navigation',
	);

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'core-block-render.bootstrap-apis-available',
					'Required WordPress block render APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot       = self::snapshot_state();
		$nav_snapshot   = null;
		$rows           = array();
		$state_restored = false;

		try {
			self::include_and_register_core_blocks();
			$nav_snapshot = self::snapshot_navigation_renderer_state();
			self::prepare_runtime();

			$rows[] = self::check_post_context_blocks( $ctx->fork( 'post-context' ) );
			$rows[] = self::check_missing_context_fail_closed( $ctx->fork( 'missing-context' ) );
			$rows[] = self::check_site_identity_blocks( $ctx->fork( 'site-identity' ) );
			$rows[] = self::check_query_pagination_context( $ctx->fork( 'query-pagination' ) );
			$rows[] = self::check_search_and_loginout_blocks( $ctx->fork( 'forms-loginout' ) );
			$rows[] = self::check_html_mutation_blocks( $ctx->fork( 'html-mutation' ) );
			$rows[] = self::check_navigation_block_rendering( $ctx->fork( 'navigation' ) );
			$rows[] = self::check_frontend_list_blocks( $ctx->fork( 'frontend-lists' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'core-block-render.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
			self::restore_navigation_renderer_state( $nav_snapshot );
			$state_restored = self::state_matches( $snapshot ) && self::navigation_renderer_state_matches( $nav_snapshot );
		}

		$rows[] = $ctx->result(
			'core-block-render.state-restored',
			$state_restored,
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedStatics' => array_keys( $snapshot['statics'] ),
				'navigationRendererState' => null !== $nav_snapshot ? 'tracked' : 'unavailable',
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Block', 'WP_Block_Supports', 'WP_Block_Type_Registry', 'WP_Comment', 'WP_Comment_Query', 'WP_HTML_Tag_Processor', 'WP_Post', 'WP_Query', 'WP_Term', 'WP_Term_Query' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'get_calendar',
				'get_block_wrapper_attributes',
				'get_comments',
				'get_permalink',
				'get_pages',
				'get_taxonomy',
				'has_filter',
				'register_block_type_from_metadata',
				'remove_filter',
				'render_block',
				'update_option',
				'wp_cache_set',
				'wp_cache_get_last_changed',
				'wp_cache_set_salted',
				'wp_dropdown_categories',
				'wp_enqueue_script_module',
				'wp_interactivity_data_wp_context',
				'wp_get_archives',
				'wp_list_categories',
				'wp_parse_url',
				'wp_recursive_ksort',
				'wp_script_modules',
				'wp_set_current_user',
				'wp_style_engine_get_styles',
				'wp_tag_cloud',
				'wp_unique_id',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array_keys( self::CORE_BLOCKS ) as $slug ) {
			if ( ! file_exists( ABSPATH . WPINC . '/blocks/' . $slug . '.php' ) ) {
				$missing[] = "block {$slug}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$missing[] = 'component_fuzz wpdb stub';
		}

		return $missing;
	}

	private static function include_and_register_core_blocks(): void {
		foreach ( array_keys( self::CORE_BLOCKS ) as $slug ) {
			require_once ABSPATH . WPINC . '/blocks/' . $slug . '.php';
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::CORE_BLOCKS as $slug => $register_callback ) {
			if ( $registry->is_registered( 'core/' . $slug ) || ! function_exists( $register_callback ) ) {
				continue;
			}

			$register_callback();
		}
	}

	private static function prepare_runtime(): void {
		\create_initial_post_types();
		\create_initial_taxonomies();

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'home'                => 'http://example.test',
					'siteurl'             => 'http://example.test',
					'blogname'            => 'Component Fuzz',
					'blogdescription'     => 'Component fuzz tagline',
					'blog_charset'        => 'UTF-8',
					'comments_per_page'   => 50,
					'date_format'         => 'Y-m-d',
					'time_format'         => 'H:i',
					'permalink_structure' => '',
					'page_for_posts'      => 0,
					'show_on_front'       => 'posts',
					'start_of_week'       => 1,
					'users_can_register'  => 0,
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		if ( function_exists( 'block_core_latest_posts_migrate_categories' ) && false === \has_filter( 'render_block_data', 'block_core_latest_posts_migrate_categories' ) ) {
			\add_filter( 'render_block_data', 'block_core_latest_posts_migrate_categories' );
		}

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/component-fuzz/';
		$_SERVER['PHP_SELF']    = '/index.php';

		if ( class_exists( 'WP_Rewrite' ) ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}

		$GLOBALS['paged']        = 1;
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		\wp_set_current_user( 0 );
	}

	private static function check_post_context_blocks( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'post-' . $i );
			$case     = self::post_block_case( $case_ctx, $i );
			$post     = self::install_post_fixture( $case );

			$GLOBALS['post'] = $post;
			$GLOBALS['id']   = $post->ID;

			$title_output = self::render_simple_block(
				'core/post-title',
				array(
					'level'      => $case['titleLevel'],
					'isLink'     => true,
					'linkTarget' => $case['linkTarget'],
					'rel'        => $case['hostileRel'],
					'textAlign'  => $case['textAlign'],
					'style'      => array(
						'elements' => array(
							'link' => array(
								'color' => array(
									'text' => '#135e96',
								),
							),
						),
					),
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $title_output, '<' . $case['titleTag'] . ' ' )
					&& str_contains( $title_output, 'has-text-align-' . $case['textAlign'] )
					&& str_contains( $title_output, 'has-link-color' )
					&& str_contains( $title_output, 'href="' . self::expected_permalink( $post->ID ) . '"' )
					&& str_contains( $title_output, esc_attr( $case['linkTarget'] ) )
					&& str_contains( $title_output, esc_attr( $case['hostileRel'] ) )
					&& str_contains( $title_output, $case['title'] )
					&& self::sane_html_fragment( $title_output, array( 'a', $case['titleTag'] ) ),
				"core/post-title renders linked title, wrapper classes, and escaped link attributes case {$i}",
				array(
					'output' => self::preview( $title_output ),
					'case'   => $case,
					'report' => self::hostile_report( $title_output ),
				)
			);

			$date_output = self::render_simple_block(
				'core/post-date',
				array(
					'datetime'  => $case['date'],
					'format'    => $case['dateFormat'],
					'isLink'    => $case['dateIsLink'],
					'textAlign' => $case['textAlign'],
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $date_output, '<time datetime="' . $case['date'] . '">' . $case['formattedDate'] . '</time>' )
					&& str_contains( $date_output, 'has-text-align-' . $case['textAlign'] )
					&& ( ! $case['dateIsLink'] || str_contains( $date_output, 'href="' . self::expected_permalink( $post->ID ) . '"' ) )
					&& self::sane_html_fragment( $date_output, array( 'a', 'div', 'time' ) ),
				"core/post-date renders deterministic datetime and optional post link case {$i}",
				array(
					'output' => self::preview( $date_output ),
					'case'   => $case,
					'report' => self::hostile_report( $date_output ),
				)
			);

			$excerpt_more_before   = self::hook_callbacks( 'excerpt_more' );
			$excerpt_length_before = self::hook_callbacks( 'excerpt_length' );
			$excerpt_output        = self::render_simple_block(
				'core/post-excerpt',
				array(
					'excerptLength'     => $case['excerptLength'],
					'moreText'          => $case['moreText'],
					'showMoreOnNewLine' => $case['moreOnNewLine'],
					'textAlign'         => $case['textAlign'],
				)
			);
			$excerpt_more_after    = self::hook_callbacks( 'excerpt_more' );
			$excerpt_length_after  = self::hook_callbacks( 'excerpt_length' );

			self::collect_failure(
				$failures,
				str_contains( $excerpt_output, 'wp-block-post-excerpt' )
					&& str_contains( $excerpt_output, 'wp-block-post-excerpt__excerpt' )
					&& str_contains( $excerpt_output, $case['excerptNeedle'] )
					&& str_contains( $excerpt_output, 'wp-block-post-excerpt__more-link' )
					&& str_contains( $excerpt_output, '<strong>More ' . $case['token'] . '</strong>' )
					&& ! str_contains( $excerpt_output, '<script' )
					&& $excerpt_more_before === $excerpt_more_after
					&& $excerpt_length_before === $excerpt_length_after
					&& self::sane_html_fragment( $excerpt_output, array( 'a', 'div', 'p', 'strong' ) ),
				"core/post-excerpt trims safe excerpts, sanitizes moreText, and restores excerpt filters case {$i}",
				array(
					'output' => self::preview( $excerpt_output ),
					'case'   => $case,
					'report' => self::hostile_report( $excerpt_output ),
				)
			);

			$read_more_output = self::render_simple_block(
				'core/read-more',
				array(
					'content'        => $case['readMoreText'],
					'linkTarget'     => $case['linkTarget'],
					'justifyContent' => $case['justifyContent'],
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $read_more_output, 'is-justified-' . $case['justifyContent'] )
					&& str_contains( $read_more_output, 'href="' . self::expected_permalink( $post->ID ) . '"' )
					&& str_contains( $read_more_output, esc_attr( $case['linkTarget'] ) )
					&& str_contains( $read_more_output, '<span>Read ' . $case['token'] . '</span>' )
					&& str_contains( $read_more_output, 'screen-reader-text' )
					&& str_contains( $read_more_output, $case['title'] )
					&& self::sane_html_fragment( $read_more_output, array( 'a', 'span' ) ),
				"core/read-more renders sanitized label, post link, alignment class, and screen-reader title case {$i}",
				array(
					'output' => self::preview( $read_more_output ),
					'case'   => $case,
					'report' => self::hostile_report( $read_more_output ),
				)
			);
		}

		return self::row(
			$ctx,
			'core-block-render.post-context-callbacks',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'blocks'   => array( 'core/post-title', 'core/post-date', 'core/post-excerpt', 'core/read-more' ),
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_missing_context_fail_closed( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		unset( $GLOBALS['post'], $GLOBALS['id'] );

		$post_title   = self::render_simple_block( 'core/post-title', array( 'isLink' => true ) );
		$post_excerpt = self::render_simple_block(
			'core/post-excerpt',
			array(
				'excerptLength' => 8,
				'moreText'      => 'More',
			)
		);
		$read_more    = self::render_simple_block( 'core/read-more', array( 'content' => 'Read more' ) );
		$post_date    = self::render_simple_block( 'core/post-date', array( 'datetime' => '' ) );
		$image_empty  = self::render_simple_block( 'core/image', array(), '<figure class="wp-block-image"></figure>' );

		self::collect_failure(
			$failures,
			'' === $post_title
				&& '' === $post_excerpt
				&& '' === $read_more
				&& '' === $post_date
				&& '' === $image_empty,
			'post-context and image callbacks fail closed for missing context or missing required markup',
			array(
				'postTitle'   => self::preview( $post_title ),
				'postExcerpt' => self::preview( $post_excerpt ),
				'readMore'    => self::preview( $read_more ),
				'postDate'    => self::preview( $post_date ),
				'imageEmpty'  => self::preview( $image_empty ),
			)
		);

		return self::row(
			$ctx,
			'core-block-render.missing-context-fail-closed',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_site_identity_blocks( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case_ctx    = $ctx->fork( 'site-' . $i );
			$token       = self::token( $case_ctx, 'site' );
			$blog_name   = 'Site ' . $token . ' <script>alert(1)</script>';
			$description = 'Tagline ' . $token . ' words';
			$is_link     = $case_ctx->bool();
			$level       = $case_ctx->choice( array( 0, 1, 2, 3, 4, 5, 6 ) );
			$tag         = 0 === $level ? 'p' : 'h' . $level;
			$text_align  = $case_ctx->choice( array( 'left', 'center', 'right' ) );

			\update_option( 'blogname', $blog_name );
			\update_option( 'blogdescription', $description );

			$title_output = self::render_simple_block(
				'core/site-title',
				array(
					'isLink'     => $is_link,
					'level'      => $level,
					'linkTarget' => '_blank<script>',
					'textAlign'  => $text_align,
				)
			);
			$tagline      = self::render_simple_block(
				'core/site-tagline',
				array(
					'level'     => $level,
					'textAlign' => $text_align,
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $title_output, '<' . $tag . ' ' )
					&& str_contains( $title_output, 'has-text-align-' . $text_align )
					&& str_contains( $title_output, esc_html( $blog_name ) )
					&& ( ! $is_link || ( str_contains( $title_output, '<a href="http://example.test" target="' ) && str_contains( $title_output, 'rel="home"' ) ) )
					&& self::sane_html_fragment( $title_output, array( 'a', $tag ) ),
				"core/site-title escapes blog name and renders expected wrapper/link case {$i}",
				array(
					'output' => self::preview( $title_output ),
					'report' => self::hostile_report( $title_output ),
					'case'   => array(
						'isLink' => $is_link,
						'level'  => $level,
					),
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $tagline, '<' . $tag . ' ' )
					&& str_contains( $tagline, 'has-text-align-' . $text_align )
					&& str_contains( $tagline, $description )
					&& self::sane_html_fragment( $tagline, array( $tag ) ),
				"core/site-tagline renders deterministic benign description and wrapper class case {$i}",
				array(
					'output' => self::preview( $tagline ),
					'case'   => array(
						'level' => $level,
					),
				)
			);
		}

		\update_option( 'blogname', '   ' );
		\update_option( 'blogdescription', '' );
		$empty_title   = self::render_simple_block( 'core/site-title', array( 'isLink' => false ) );
		$empty_tagline = self::render_simple_block( 'core/site-tagline', array() );
		self::collect_failure(
			$failures,
			'' === $empty_title && '' === $empty_tagline,
			'site title and tagline return empty output for empty option values',
			array(
				'emptyTitle'   => self::preview( $empty_title ),
				'emptyTagline' => self::preview( $empty_tagline ),
			)
		);

		return self::row(
			$ctx,
			'core-block-render.site-identity-callbacks',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'blocks'   => array( 'core/site-title', 'core/site-tagline' ),
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_query_pagination_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case_ctx   = $ctx->fork( 'pagination-' . $i );
			$token      = self::token( $case_ctx, 'page' );
			$page       = $case_ctx->int( 2, 4 );
			$total      = max( $page + 1, $case_ctx->int( 4, 7 ) );
			$query_id   = $case_ctx->int( 10, 999 );
			$enhanced   = $case_ctx->bool();
			$show_label = $case_ctx->bool();
			$arrow      = $case_ctx->choice( array( 'none', 'arrow', 'chevron' ) );
			$previous   = 'Previous ' . $token . ' <script>';
			$next       = 'Next ' . $token . ' <script>';

			self::install_global_query( $page, $total );
			$_SERVER['REQUEST_URI'] = '/component-fuzz/?paged=' . $page . '&keep=' . rawurlencode( $token );
			$_GET                   = array( 'paged' => (string) $page );

			$next_filter_before     = self::hook_callbacks( 'next_posts_link_attributes' );
			$previous_filter_before = self::hook_callbacks( 'previous_posts_link_attributes' );

			$pagination = self::render_block_with_context(
				self::parsed_block(
					'core/query-pagination',
					array(
						'paginationArrow' => $arrow,
						'showLabel'       => $show_label,
					),
					'',
					array(
						self::parsed_block(
							'core/query-pagination-previous',
							array( 'label' => $previous )
						),
						self::parsed_block(
							'core/query-pagination-numbers',
							array( 'midSize' => $case_ctx->int( 0, 3 ) )
						),
						self::parsed_block(
							'core/query-pagination-next',
							array( 'label' => $next )
						),
					),
					array( null, "\n", null, "\n", null )
				),
				array(
					'queryId'            => $query_id,
					'query'              => array(
						'inherit' => true,
						'pages'   => $total,
					),
					'enhancedPagination' => $enhanced,
				)
			);

			$next_filter_after     = self::hook_callbacks( 'next_posts_link_attributes' );
			$previous_filter_after = self::hook_callbacks( 'previous_posts_link_attributes' );

			self::collect_failure(
				$failures,
				str_contains( $pagination, '<nav ' )
					&& str_contains( $pagination, 'aria-label="Pagination"' )
					&& str_contains( $pagination, 'wp-block-query-pagination' )
					&& str_contains( $pagination, 'page-numbers' )
					&& self::has_paged_link( $pagination, $page - 1, $token )
					&& self::has_paged_link( $pagination, $page + 1, $token )
					&& str_contains( $pagination, esc_html( $previous ) )
					&& str_contains( $pagination, esc_html( $next ) )
					&& ( 'none' === $arrow || str_contains( $pagination, 'is-arrow-' . $arrow ) )
					&& ( ! $enhanced || ( str_contains( $pagination, 'data-wp-key=' ) && str_contains( $pagination, 'core/query::actions.navigate' ) ) )
					&& $next_filter_before === $next_filter_after
					&& $previous_filter_before === $previous_filter_after
					&& self::sane_html_fragment( $pagination, array( 'a', 'div', 'nav', 'span' ) ),
				"query pagination parent propagates query, labels, arrows, enhanced directives, and restores link filters case {$i}",
				array(
					'output' => self::preview( $pagination ),
					'report' => self::hostile_report( $pagination ),
					'case'   => array(
						'page'      => $page,
						'total'     => $total,
						'queryId'   => $query_id,
						'enhanced'  => $enhanced,
						'showLabel' => $show_label,
						'arrow'     => $arrow,
					),
				)
			);
		}

		return self::row(
			$ctx,
			'core-block-render.query-pagination-context',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'blocks'   => array( 'core/query-pagination', 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ),
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_search_and_loginout_blocks( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case_ctx        = $ctx->fork( 'search-' . $i );
			$token           = self::token( $case_ctx, 'search' );
			$button_position = $case_ctx->choice( array( 'button-outside', 'button-inside', 'no-button', 'button-only' ) );
			$label           = 'Find <strong>' . $token . '</strong>' . self::hostile_text( $token );
			$button_text     = 'Search <em>' . $token . '</em>' . self::hostile_text( $token . '-button' );

			$_GET     = array( 's' => 'needle ' . self::hostile_text( $token . '-query' ) );
			$_REQUEST = $_GET;

			$search = self::render_simple_block(
				'core/search',
				array(
					'label'           => $label,
					'showLabel'       => $case_ctx->bool(),
					'placeholder'     => 'Placeholder "' . $token . '" <script>',
					'buttonText'      => $button_text,
					'buttonPosition'  => $button_position,
					'buttonUseIcon'   => $case_ctx->bool(),
					'query'           => array(
						'component_fuzz' => $token,
						'unsafe'         => self::hostile_text( $token . '-hidden' ),
					),
					'style'           => array(
						'border' => array(
							'width' => '1px',
							'style' => 'solid',
						),
					),
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $search, '<form ' )
					&& str_contains( $search, 'role="search"' )
					&& str_contains( $search, 'method="get"' )
					&& str_contains( $search, 'action="http://example.test/"' )
					&& str_contains( $search, 'wp-block-search' )
					&& str_contains( $search, 'wp-block-search__input' )
					&& str_contains( $search, 'name="s"' )
					&& str_contains( $search, esc_attr( 'Placeholder "' . $token . '" <script>' ) )
					&& str_contains( $search, 'name="component_fuzz" value="' . esc_attr( $token ) . '"' )
					&& ( 'no-button' === $button_position || str_contains( $search, 'wp-block-search__button' ) )
					&& ( 'button-only' !== $button_position || str_contains( $search, 'data-wp-interactive="core/search"' ) )
					&& self::sane_html_fragment( $search, array( 'button', 'div', 'form', 'label', 'span', 'strong' ) ),
				"core/search renders escaped form controls, query hidden inputs, and optional interactivity case {$i}",
				array(
					'output' => self::preview( $search ),
					'report' => self::hostile_report( $search ),
					'case'   => array(
						'buttonPosition' => $button_position,
					),
				)
			);
		}

		$_SERVER['REQUEST_URI'] = '/component-fuzz/login/?next=' . rawurlencode( '<script>alert(1)</script>' );
		$_GET                   = array();
		$_REQUEST               = array();
		\wp_set_current_user( 0 );

		$logged_out_link = self::render_simple_block(
			'core/loginout',
			array(
				'redirectToCurrent'  => true,
				'displayLoginAsForm' => false,
			)
		);
		$logged_out_form = self::render_simple_block(
			'core/loginout',
			array(
				'redirectToCurrent'  => true,
				'displayLoginAsForm' => true,
			)
		);

		$user_id = self::install_user_fixture( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );
		$logged_in = self::render_simple_block(
			'core/loginout',
			array(
				'redirectToCurrent' => true,
			)
		);
		\wp_set_current_user( 0 );

		self::collect_failure(
			$failures,
			str_contains( $logged_out_link, 'logged-out' )
				&& str_contains( $logged_out_link, 'Log in' )
				&& str_contains( $logged_out_form, 'has-login-form' )
				&& str_contains( $logged_out_form, 'name="log"' )
				&& str_contains( $logged_in, 'logged-in' )
				&& str_contains( $logged_in, 'Log out' )
				&& self::sane_html_fragment( $logged_out_link, array( 'a', 'div' ) )
				&& self::sane_html_fragment( $logged_out_form, array( 'div', 'form', 'input', 'label', 'p' ) )
				&& self::sane_html_fragment( $logged_in, array( 'a', 'div' ) ),
			'core/loginout renders logged-out link, login form, and logged-in logout branch without raw redirect bytes',
			array(
				'loggedOutLink' => self::preview( $logged_out_link ),
				'loggedOutForm' => self::preview( $logged_out_form ),
				'loggedIn'      => self::preview( $logged_in ),
				'reports'       => array(
					'loggedOutLink' => self::hostile_report( $logged_out_link ),
					'loggedOutForm' => self::hostile_report( $logged_out_form ),
					'loggedIn'      => self::hostile_report( $logged_in ),
				),
			)
		);

		return self::row(
			$ctx,
			'core-block-render.search-loginout-callbacks',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'blocks'   => array( 'core/search', 'core/loginout' ),
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_html_mutation_blocks( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'html-' . $i );
			$token    = self::token( $case_ctx, 'html' );

			$button_content = '<div class="wp-block-button"><a class="wp-block-button__link" href="https://example.test/button/' . rawurlencode( $token ) . '">Button ' . esc_html( $token ) . '</a></div>';
			$button_output  = self::render_simple_block( 'core/button', array(), $button_content );
			$empty_button   = self::render_simple_block( 'core/button', array(), '<div class="wp-block-button"><a class="wp-block-button__link"><!-- empty --></a></div>' );

			self::collect_failure(
				$failures,
				$button_content === $button_output
					&& '' === $empty_button
					&& self::sane_html_fragment( $button_output, array( 'a', 'div' ) ),
				"core/button preserves non-empty button markup and drops empty button content case {$i}",
				array(
					'button'      => self::preview( $button_output ),
					'emptyButton' => self::preview( $empty_button ),
				)
			);

			$file_content = '<div class="wp-block-file"><object class="wp-block-file__embed" data="https://example.test/file-' . rawurlencode( $token ) . '.pdf" type="application/pdf" aria-label="file ' . esc_attr( self::hostile_text( $token ) ) . '"></object><a href="https://example.test/file-' . rawurlencode( $token ) . '.pdf">Download</a></div>';
			$file_output  = self::render_simple_block(
				'core/file',
				array( 'displayPreview' => true ),
				$file_content
			);

			self::collect_failure(
				$failures,
				str_contains( $file_output, 'data-wp-interactive="core/file"' )
					&& str_contains( $file_output, 'data-wp-bind--hidden="!state.hasPdfPreview"' )
					&& str_contains( $file_output, 'hidden' )
					&& str_contains( $file_output, 'Embed of file' )
					&& ! str_contains( $file_output, '<script' )
					&& self::sane_html_fragment( $file_output, array( 'a', 'div', 'object' ) ),
				"core/file adds preview directives and escapes hostile object aria-label case {$i}",
				array(
					'output' => self::preview( $file_output ),
					'report' => self::hostile_report( $file_output ),
				)
			);

			$image_id      = 8100 + $i;
			$image_content = '<figure class="wp-block-image size-large"><img src="https://example.test/image-' . rawurlencode( $token ) . '.jpg" class="wp-image-7" alt="Alt ' . esc_attr( $token ) . '"><figcaption></figcaption></figure>';
			$image_output  = self::render_simple_block(
				'core/image',
				array(
					'id'              => $image_id,
					'data-id'         => 7,
					'lightbox'        => array( 'enabled' => false ),
					'metadata'        => array(
						'bindings' => array(
							'id' => array(
								'source' => 'component-fuzz/missing',
							),
						),
					),
				),
				$image_content
			);

			self::collect_failure(
				$failures,
				str_contains( $image_output, 'data-id="' . $image_id . '"' )
					&& str_contains( $image_output, 'wp-image-' . $image_id )
					&& ! str_contains( $image_output, '<figcaption' )
					&& self::sane_html_fragment( $image_output, array( 'figure' ) ),
				"core/image updates bound image IDs/data IDs and removes empty captions case {$i}",
				array(
					'output'  => self::preview( $image_output ),
					'imageId' => $image_id,
					'report'  => self::hostile_report( $image_output ),
				)
			);
		}

		return self::row(
			$ctx,
			'core-block-render.html-mutation-callbacks',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'blocks'   => array( 'core/button', 'core/file', 'core/image' ),
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_navigation_block_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures            = array();
		$failed_check_names  = array();
		$baseline            = self::snapshot_navigation_renderer_state();

		for ( $i = 0; $i < self::CASES; ++$i ) {
			self::restore_navigation_renderer_state( $baseline );

			$case_ctx      = $ctx->fork( 'nav-' . $i );
			self::reset_content();
			$page_fixture  = self::install_navigation_page_fixtures( $case_ctx->fork( 'pages' ), $i );
			self::set_current_page_query( $page_fixture['child'] );
			$case          = self::navigation_case( $case_ctx, $i, $page_fixture );
			$counts_before = self::content_counts();
			$navigation    = self::render_simple_block(
				'core/navigation',
				$case['attrs'],
				'',
				$case['innerBlocks'],
				$case['innerContent']
			);
			$duplicate     = self::render_simple_block(
				'core/navigation',
				$case['attrs'],
				'',
				$case['innerBlocks'],
				$case['innerContent']
			);
			$counts_after  = self::content_counts();

			$is_responsive = 'never' !== $case['attrs']['overlayMenu'];
			$is_interactive = $is_responsive || 'click' === $case['visibility'] || $case['attrs']['showSubmenuIcon'];

			$visibility_helper = function_exists( 'block_core_navigation_get_submenu_visibility' )
				? \block_core_navigation_get_submenu_visibility( $case['attrs'] )
				: null;
			$submenu_helper    = function_exists( 'block_core_navigation_submenu_get_submenu_visibility' )
				? \block_core_navigation_submenu_get_submenu_visibility( $case['attrs'] )
				: null;

			$visibility_class_ok = true;
			if ( 'click' === $case['visibility'] ) {
				$visibility_class_ok = str_contains( $navigation, 'open-on-click' );
			} elseif ( 'always' === $case['visibility'] ) {
				$visibility_class_ok = str_contains( $navigation, 'open-always' );
			} elseif ( $case['attrs']['showSubmenuIcon'] ) {
				$visibility_class_ok = str_contains( $navigation, 'open-on-hover-click' );
			} else {
				$visibility_class_ok = ! str_contains( $navigation, 'open-on-click' )
					&& ! str_contains( $navigation, 'open-always' )
					&& ! str_contains( $navigation, 'open-on-hover-click' );
			}

			$checks = array(
				'nav'                => str_contains( $navigation, '<nav ' ),
				'navClass'           => str_contains( $navigation, 'wp-block-navigation' ),
				'ariaLabel'          => str_contains( $navigation, 'aria-label="' . esc_attr( $case['attrs']['ariaLabel'] ) . '"' ),
				'duplicateLabel'     => str_contains( $duplicate, 'aria-label="' . esc_attr( $case['attrs']['ariaLabel'] . ' 2' ) . '"' ),
				'container'          => str_contains( $navigation, 'wp-block-navigation__container' ),
				'itemContent'        => str_contains( $navigation, 'wp-block-navigation-item__content' ),
				'linkLabel'          => str_contains( $navigation, '<strong>' . $case['token'] . ' Link</strong>' ),
				'linkDescription'    => str_contains( $navigation, '<em>' . $case['token'] . ' Description</em>' ),
				'rel'                => str_contains( $navigation, esc_attr( $case['rel'] ) ),
				'title'              => str_contains( $navigation, esc_attr( $case['title'] ) ),
				'linkUrl'            => str_contains( $navigation, esc_url( $case['linkUrl'] ) ),
				'submenuUrl'         => 'click' === $case['visibility'] || str_contains( $navigation, esc_url( $case['submenuUrl'] ) ),
				'childUrl'           => str_contains( $navigation, esc_url( $case['childUrl'] ) ),
				'homeClass'          => str_contains( $navigation, 'wp-block-home-link__content' ),
				'homeUrl'            => str_contains( $navigation, 'href="http://example.test" rel="home"' ),
				'homeLabel'          => str_contains( $navigation, '<span>' . $case['token'] . ' Home</span>' ),
				'pageList'           => str_contains( $navigation, 'wp-block-pages-list__item' ),
				'pageListLink'       => str_contains( $navigation, 'wp-block-pages-list__item__link wp-block-navigation-item__content' ),
				'currentItem'        => str_contains( $navigation, 'current-menu-item' ),
				'currentAncestor'    => str_contains( $navigation, 'current-menu-ancestor' ),
				'parentTitle'        => str_contains( $navigation, 'Parent ' . $page_fixture['token'] ),
				'childTitle'         => str_contains( $navigation, 'Child ' . $page_fixture['token'] ),
				'childPermalink'     => str_contains( $navigation, esc_url( get_permalink( $page_fixture['child'] ) ) ),
				'visibilityClass'    => $visibility_class_ok,
				'submenuIcon'        => ! $case['attrs']['showSubmenuIcon'] || str_contains( $navigation, 'wp-block-navigation__submenu-icon' ),
				'responsivePresent'  => ! $is_responsive || ( str_contains( $navigation, 'wp-block-navigation__responsive-container' ) && str_contains( $navigation, 'wp-block-navigation__responsive-container-open' ) && str_contains( $navigation, 'wp-block-navigation__responsive-container-close' ) ),
				'responsiveAbsent'   => $is_responsive || ! str_contains( $navigation, 'wp-block-navigation__responsive-container' ),
				'interactiveData'    => ! $is_interactive || str_contains( $navigation, 'data-wp-interactive="core/navigation"' ),
				'moduleEnqueued'     => ! $is_interactive || self::script_module_enqueued( '@wordpress/block-library/navigation/view' ),
				'navVisibility'      => $case['visibility'] === $visibility_helper,
				'submenuVisibility'  => $case['visibility'] === $submenu_helper,
				'contentCounts'      => $counts_before === $counts_after,
				'saneHtml'           => self::sane_html_fragment( $navigation, array( 'a', 'button', 'div', 'em', 'li', 'nav', 'span', 'strong', 'ul' ) ),
			);
			foreach ( $checks as $check_name => $ok ) {
				if ( ! $ok ) {
					$failed_check_names[ $check_name ] = true;
				}
			}

			self::collect_failure(
				$failures,
				! in_array( false, $checks, true ),
				"core/navigation renders explicit custom links, submenu mode, responsive controls, and unique names case {$i}",
				array(
					'failedChecks' => array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) ),
					'case'        => array(
						'visibility'      => $case['visibility'],
						'overlayMenu'     => $case['attrs']['overlayMenu'],
						'showSubmenuIcon' => $case['attrs']['showSubmenuIcon'],
						'layout'          => $case['attrs']['layout'],
					),
					'contentDiff' => array(
						'before' => $counts_before,
						'after'  => $counts_after,
					),
				)
			);
		}

		self::restore_navigation_renderer_state( $baseline );
		$self_reference = self::render_simple_block(
			'core/navigation',
			array(
				'ariaLabel'   => 'Self Reference Menu',
				'overlayMenu' => 'never',
			),
			'',
			array(
				self::parsed_block(
					'core/navigation',
					array(
						'ariaLabel'   => 'Nested Self Reference',
						'overlayMenu' => 'never',
					),
					'',
					array(
						self::navigation_link_block( 'Nested link', 'https://example.test/nested' ),
					),
					array( null )
				),
			),
			array( null )
		);

		self::collect_failure(
			$failures,
			'' === $self_reference,
			'navigation blocks containing nested navigation blocks fail closed before fallback/render recursion',
			array( 'output' => self::preview( $self_reference ) )
		);

		self::restore_navigation_renderer_state( $baseline );

		return self::row(
			$ctx,
			'core-block-render.navigation-rendering-callbacks',
			array() === $failures,
			array(
				'cases'        => self::CASES,
				'blocks'       => array( 'core/navigation', 'core/navigation-link', 'core/navigation-submenu', 'core/home-link', 'core/page-list', 'core/page-list-item' ),
				'failedChecks' => array_keys( $failed_check_names ),
				'failures'     => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_frontend_list_blocks( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures           = array();
		$failed_check_names = array();
		$seen               = array(
			'archiveArgs'         => array(),
			'archiveDropdownArgs' => array(),
			'calendarArgs'        => array(),
			'commentQueries'      => array(),
			'postQueries'         => array(),
			'termQueries'         => array(),
		);

		self::reset_content();
		\create_initial_taxonomies();

		$fixture       = self::frontend_list_fixture( $ctx->fork( 'fixture' ) );
		$counts_before = self::content_counts();

		$posts_filter = static function ( $posts, \WP_Query $query ) use ( &$seen, $fixture ) {
			unset( $posts );
			$seen['postQueries'][] = array(
				'postsPerPage' => $query->get( 'posts_per_page' ),
				'postStatus'   => $query->get( 'post_status' ),
				'order'        => $query->get( 'order' ),
				'orderby'      => $query->get( 'orderby' ),
				'categoryIn'   => $query->get( 'category__in' ),
				'author'       => $query->get( 'author' ),
			);
			$query->found_posts   = count( $fixture['posts'] );
			$query->max_num_pages = 1;

			return $fixture['posts'];
		};
		$comments_filter = static function ( $comments, \WP_Comment_Query $query ) use ( &$seen, $fixture ) {
			unset( $comments );
			$seen['commentQueries'][] = $query->query_vars;

			return $fixture['comments'];
		};
		$terms_filter = static function ( $terms, \WP_Term_Query $query ) use ( &$seen, $fixture ) {
			unset( $terms );
			$query_vars = $query->query_vars;
			$taxonomies = (array) ( $query_vars['taxonomy'] ?? array() );
			$seen['termQueries'][] = array(
				'taxonomies' => $taxonomies,
				'number'     => $query_vars['number'] ?? null,
				'parent'     => $query_vars['parent'] ?? null,
				'hideEmpty'  => $query_vars['hide_empty'] ?? null,
			);

			if ( 'id=>parent' === ( $query_vars['fields'] ?? null ) && in_array( 'category', $taxonomies, true ) ) {
				$parents = array();
				foreach ( $fixture['categories'] as $term ) {
					$parents[ (int) $term->term_id ] = (int) $term->parent;
				}

				return $parents;
			}

			if ( 'all_with_object_id' === ( $query_vars['fields'] ?? null ) && ! empty( $query_vars['object_ids'] ) ) {
				$terms_for_objects = array();
				if ( in_array( 'category', $taxonomies, true ) ) {
					$terms_for_objects[] = $fixture['categories'][0];
				}
				if ( in_array( 'post_tag', $taxonomies, true ) ) {
					$terms_for_objects[] = $fixture['tags'][0];
				}

				$object_terms = array();
				foreach ( (array) $query_vars['object_ids'] as $object_id ) {
					foreach ( $terms_for_objects as $term ) {
						$term_for_object            = clone $term;
						$term_for_object->object_id = (int) $object_id;
						$object_terms[]             = $term_for_object;
					}
				}

				return $object_terms;
			}

			if ( in_array( 'category', $taxonomies, true ) ) {
				if ( isset( $query_vars['parent'] ) && '' !== $query_vars['parent'] && 0 === (int) $query_vars['parent'] ) {
					return array_values(
						array_filter(
							$fixture['categories'],
							static fn( \WP_Term $term ): bool => 0 === (int) $term->parent
						)
					);
				}

				return $fixture['categories'];
			}

			if ( in_array( 'post_tag', $taxonomies, true ) ) {
				return $fixture['tags'];
			}

			return array();
		};
		$archive_args_filter = static function ( array $args ) use ( &$seen ): array {
			$seen['archiveArgs'][] = $args;
			return $args;
		};
		$archive_dropdown_filter = static function ( array $args ) use ( &$seen ): array {
			$seen['archiveDropdownArgs'][] = $args;
			return $args;
		};
		$calendar_filter = static function ( string $output, array $args ) use ( &$seen ): string {
			$seen['calendarArgs'][] = $args;
			return $output . '<!-- component-fuzz-calendar-block -->';
		};

		\add_filter( 'posts_pre_query', $posts_filter, 10, 2 );
		\add_filter( 'comments_pre_query', $comments_filter, 10, 2 );
		\add_filter( 'terms_pre_query', $terms_filter, 10, 2 );
		\add_filter( 'widget_archives_args', $archive_args_filter );
		\add_filter( 'widget_archives_dropdown_args', $archive_dropdown_filter );
		\add_filter( 'get_calendar', $calendar_filter, 10, 2 );

		try {
			for ( $i = 0; $i < self::CASES; ++$i ) {
				$case_ctx        = $ctx->fork( 'list-' . $i );
				$dropdown        = $case_ctx->bool();
				$show_label      = $case_ctx->bool();
				$show_counts     = $case_ctx->bool();
				$enhanced        = $case_ctx->bool();
				$top_level_only  = $case_ctx->bool();
				$calendar_month  = $case_ctx->int( 1, 12 );
				$calendar_year   = 2026 + $case_ctx->int( 0, 2 );
				$content_mode    = $case_ctx->choice( array( 'excerpt', 'full_post' ) );
				$comment_content = $case_ctx->choice( array( 'excerpt', 'full', 'none' ) );
				$font_unit       = $case_ctx->choice( array( 'px', 'em', 'rem', '%' ) );
				$smallest_font   = $case_ctx->int( 8, 14 ) . $font_unit;
				$largest_font    = $case_ctx->int( 18, 32 ) . $font_unit;

				self::seed_core_archives_cache(
					array(
						(object) array(
							'year'  => '2026',
							'month' => '6',
							'posts' => (string) $fixture['archiveCount'],
						),
					)
				);

				$archives = self::render_simple_block(
					'core/archives',
					array(
						'displayAsDropdown' => $dropdown,
						'showLabel'         => $show_label,
						'showPostCounts'    => $show_counts,
						'type'              => 'monthly',
					)
				);

				$GLOBALS['wp_query']->query_vars['category_name'] = $fixture['categories'][0]->slug;
				$categories = self::render_block_with_context(
					self::parsed_block(
						'core/categories',
						array(
							'displayAsDropdown' => $dropdown,
							'label'             => '<strong>' . $fixture['token'] . ' Categories</strong>' . self::hostile_text( $fixture['token'] . '-category-label' ),
							'showEmpty'         => true,
							'showHierarchy'     => true,
							'showLabel'         => $show_label,
							'showOnlyTopLevel'  => $top_level_only,
							'showPostCounts'    => $show_counts,
							'taxonomy'          => 'category',
						)
					),
					array( 'enhancedPagination' => $enhanced )
				);

				$excerpt_before = self::hook_callbacks( 'excerpt_length' );
				$latest_posts   = self::render_simple_block(
					'core/latest-posts',
					array(
						'addLinkToFeaturedImage'   => false,
						'categories'               => (string) $fixture['categories'][0]->term_id,
						'columns'                  => $case_ctx->int( 2, 4 ),
						'displayAuthor'            => true,
						'displayFeaturedImage'     => false,
						'displayPostContent'       => true,
						'displayPostContentRadio'  => $content_mode,
						'displayPostDate'          => true,
						'excerptLength'            => $case_ctx->int( 5, 12 ),
						'order'                    => $case_ctx->choice( array( 'asc', 'desc' ) ),
						'orderBy'                  => $case_ctx->choice( array( 'date', 'title' ) ),
						'postLayout'               => $case_ctx->choice( array( 'list', 'grid' ) ),
						'postsToShow'              => 2,
						'selectedAuthor'           => $fixture['authorId'],
						'style'                    => array(
							'elements' => array(
								'link' => array(
									'color' => array(
										'text' => '#135e96',
									),
								),
							),
						),
					)
				);
				$excerpt_after  = self::hook_callbacks( 'excerpt_length' );

				$latest_comments = self::render_simple_block(
					'core/latest-comments',
					array(
						'commentsToShow' => 2,
						'displayAvatar'  => false,
						'displayContent' => $comment_content,
						'displayDate'    => true,
					)
				);

				$tag_cloud = self::render_simple_block(
					'core/tag-cloud',
					array(
						'largestFontSize'  => $largest_font,
						'numberOfTags'     => 2,
						'showTagCounts'    => $show_counts,
						'smallestFontSize' => $smallest_font,
						'taxonomy'         => 'post_tag',
					)
				);

				$previous_monthnum = $GLOBALS['monthnum'] ?? null;
				$previous_year     = $GLOBALS['year'] ?? null;
				$GLOBALS['monthnum'] = 11;
				$GLOBALS['year']     = 2024;
				\update_option( 'wp_calendar_block_has_published_posts', true );
				\update_option( 'permalink_structure', '/%year%/%monthnum%/%postname%/' );
				self::seed_core_calendar_cache(
					'<table id="wp-calendar" class="wp-calendar-table"><caption>Calendar ' . esc_html( $fixture['token'] ) . '</caption><tbody><tr><td>23</td></tr></tbody></table>',
					$calendar_month,
					$calendar_year
				);
				$calendar = self::render_simple_block(
					'core/calendar',
					array(
						'backgroundColor' => 'white',
						'month'           => $calendar_month,
						'style'           => array(
							'color'    => array(
								'text'       => '#123456',
								'background' => '#f6f7f7',
							),
							'elements' => array(
								'link' => array(
									'color' => array(
										'text' => '#135e96',
									),
								),
							),
						),
						'textColor'       => 'black',
						'year'            => $calendar_year,
					)
				);
				$monthnum_restored = ( $GLOBALS['monthnum'] ?? null ) === 11;
				$year_restored     = ( $GLOBALS['year'] ?? null ) === 2024;
				$GLOBALS['monthnum'] = $previous_monthnum;
				$GLOBALS['year']     = $previous_year;

				$checks = array(
					'archives-wrapper'         => str_contains( $archives, $dropdown ? 'wp-block-archives-dropdown' : 'wp-block-archives-list' ),
					'archives-month'           => str_contains( $archives, 'June 2026' ),
					'archives-count'           => ! $show_counts || str_contains( $archives, '&nbsp;(' . $fixture['archiveCount'] . ')' ),
					'archives-dropdown-script' => ! $dropdown || ( str_contains( $archives, '<select ' ) && str_contains( $archives, 'sourceURL=block_core_archives_build_dropdown_script' ) ),
					'archives-label-mode'      => ! $dropdown || ( $show_label ? ! str_contains( $archives, 'wp-block-archives__label screen-reader-text' ) : str_contains( $archives, 'wp-block-archives__label screen-reader-text' ) ),
					'categories-wrapper'       => str_contains( $categories, $dropdown ? 'wp-block-categories-dropdown' : 'wp-block-categories-list' ),
					'categories-taxonomy'      => str_contains( $categories, 'wp-block-categories-taxonomy-category' ),
					'categories-label-sanitized' => ! $dropdown || ! $show_label || ( str_contains( $categories, '<strong>' . $fixture['token'] . ' Categories</strong>' ) && ! str_contains( $categories, $fixture['token'] . '-category-label' ) ),
					'categories-parent-filter'  => ! $top_level_only || ! str_contains( $categories, $fixture['categories'][1]->name ),
					'categories-child-visible'  => $top_level_only || $dropdown || str_contains( $categories, $fixture['categories'][1]->name ),
					'categories-enhanced'       => $dropdown || ! $enhanced || str_contains( $categories, 'data-wp-on--click="core/query::actions.navigate"' ),
					'latest-posts-wrapper'      => str_contains( $latest_posts, 'wp-block-latest-posts__list' ),
					'latest-posts-title'        => str_contains( $latest_posts, $fixture['posts'][0]->post_title ) && str_contains( $latest_posts, $fixture['posts'][1]->post_title ),
					'latest-posts-author'       => str_contains( $latest_posts, $fixture['authorName'] ),
					'latest-posts-date'         => str_contains( $latest_posts, 'wp-block-latest-posts__post-date' ),
					'latest-posts-content-mode' => 'full_post' === $content_mode ? str_contains( $latest_posts, 'wp-block-latest-posts__post-full-content' ) : str_contains( $latest_posts, 'wp-block-latest-posts__post-excerpt' ),
					'latest-posts-migration'    => isset( $seen['postQueries'][ $i ]['categoryIn'][0] ) && (int) $fixture['categories'][0]->term_id === (int) $seen['postQueries'][ $i ]['categoryIn'][0],
					'latest-posts-filter-restored' => $excerpt_before === $excerpt_after,
					'latest-comments-wrapper'    => str_contains( $latest_comments, 'wp-block-latest-comments' ),
					'latest-comments-author'     => str_contains( $latest_comments, $fixture['comments'][0]->comment_author ),
					'latest-comments-post-title' => str_contains( $latest_comments, $fixture['posts'][0]->post_title ),
					'latest-comments-content-mode' => 'none' === $comment_content ? ! str_contains( $latest_comments, 'wp-block-latest-comments__comment-excerpt' ) : str_contains( $latest_comments, 'wp-block-latest-comments__comment-excerpt' ),
					'tag-cloud-wrapper'          => str_contains( $tag_cloud, '<p ' ) && str_contains( $tag_cloud, 'wp-block-tag-cloud' ),
					'tag-cloud-terms'            => str_contains( $tag_cloud, $fixture['tags'][0]->name ) && str_contains( $tag_cloud, $fixture['tags'][1]->name ),
					'tag-cloud-unit'             => str_contains( $tag_cloud, $font_unit ),
					'calendar-wrapper'           => str_contains( $calendar, 'wp-block-calendar' ),
					'calendar-cache'             => str_contains( $calendar, 'Calendar ' . $fixture['token'] ) && str_contains( $calendar, 'component-fuzz-calendar-block' ),
					'calendar-style'             => str_contains( $calendar, 'wp-calendar-table' ) && str_contains( $calendar, 'has-link-color' ) && str_contains( $calendar, 'has-text-color' ) && str_contains( $calendar, 'has-background' ),
					'calendar-globals-restored' => $monthnum_restored && $year_restored,
					'sane-lists'                 => self::sane_html_fragment( $latest_posts . $latest_comments . $tag_cloud . $calendar, array( 'a', 'article', 'div', 'footer', 'li', 'ol', 'p', 'table', 'time', 'ul' ) ),
				);

				foreach ( $checks as $check_name => $ok ) {
					if ( ! $ok ) {
						$failed_check_names[ $check_name ] = true;
					}
				}

				self::collect_failure(
					$failures,
					! in_array( false, $checks, true ),
					"frontend list-style core block callbacks render deterministic fixtures and restore state case {$i}",
					array(
						'failedChecks' => array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) ),
						'case'         => array(
							'dropdown'       => $dropdown,
							'showLabel'      => $show_label,
							'showCounts'     => $show_counts,
							'enhanced'       => $enhanced,
							'topLevelOnly'   => $top_level_only,
							'contentMode'    => $content_mode,
							'commentContent' => $comment_content,
							'fontUnit'       => $font_unit,
							'calendarMonth'  => $calendar_month,
							'calendarYear'   => $calendar_year,
						),
						'previews'     => array(
							'archives'       => self::preview( $archives ),
							'categories'     => self::preview( $categories ),
							'latestPosts'    => self::preview( $latest_posts ),
							'latestComments' => self::preview( $latest_comments ),
							'tagCloud'       => self::preview( $tag_cloud ),
							'calendar'       => self::preview( $calendar ),
						),
					)
				);
			}
		} finally {
			\remove_filter( 'get_calendar', $calendar_filter, 10 );
			\remove_filter( 'widget_archives_dropdown_args', $archive_dropdown_filter );
			\remove_filter( 'widget_archives_args', $archive_args_filter );
			\remove_filter( 'terms_pre_query', $terms_filter, 10 );
			\remove_filter( 'comments_pre_query', $comments_filter, 10 );
			\remove_filter( 'posts_pre_query', $posts_filter, 10 );
			\wp_set_current_user( 0 );
		}

		\update_option( 'wp_calendar_block_has_published_posts', false );
		\wp_set_current_user( 0 );
		$hidden_calendar = self::render_simple_block( 'core/calendar', array() );
		\wp_set_current_user( $fixture['authorId'] );
		$editor_calendar = self::render_simple_block( 'core/calendar', array() );
		\wp_set_current_user( 0 );

		self::collect_failure(
			$failures,
			'' === $hidden_calendar
				&& str_contains( $editor_calendar, 'The calendar block is hidden because there are no published posts.' ),
			'calendar block hides for logged-out empty sites and explains hidden state to logged-in users',
			array(
				'hidden' => self::preview( $hidden_calendar ),
				'editor' => self::preview( $editor_calendar ),
			)
		);

		$counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			$counts_before === $counts_after
				&& false === \has_filter( 'posts_pre_query', $posts_filter )
				&& false === \has_filter( 'comments_pre_query', $comments_filter )
				&& false === \has_filter( 'terms_pre_query', $terms_filter )
				&& false === \has_filter( 'widget_archives_args', $archive_args_filter )
				&& false === \has_filter( 'widget_archives_dropdown_args', $archive_dropdown_filter )
				&& false === \has_filter( 'get_calendar', $calendar_filter ),
			'frontend list block filters and content fixture counts are restored',
			array(
				'countsBefore' => $counts_before,
				'countsAfter'  => $counts_after,
			)
		);

		return self::row(
			$ctx,
			'core-block-render.frontend-list-callbacks',
			array() === $failures,
			array(
				'cases'        => self::CASES,
				'blocks'       => array( 'core/archives', 'core/categories', 'core/latest-posts', 'core/latest-comments', 'core/tag-cloud', 'core/calendar' ),
				'failedChecks' => array_keys( $failed_check_names ),
				'seen'         => array(
					'archiveArgs'         => count( $seen['archiveArgs'] ),
					'archiveDropdownArgs' => count( $seen['archiveDropdownArgs'] ),
					'calendarArgs'        => count( $seen['calendarArgs'] ),
					'commentQueries'      => count( $seen['commentQueries'] ),
					'postQueries'         => count( $seen['postQueries'] ),
					'termQueries'         => count( $seen['termQueries'] ),
				),
				'failures'     => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function frontend_list_fixture( \ComponentFuzz\FuzzContext $ctx ): array {
		$token     = self::token( $ctx, 'list' );
		$base_id   = 930000 + ( $ctx->seed() & 0xffff );
		$author_id = self::install_user_fixture( $ctx->fork( 'author' ) );

		$posts = array(
			self::install_frontend_post_fixture(
				$base_id + 1,
				$author_id,
				'List Alpha ' . $token,
				'Excerpt alpha ' . $token . ' with deterministic words for latest posts.',
				'Full post alpha ' . $token . ' with deterministic body copy for latest posts.',
				'2026-06-23 12:00:00',
				'list-alpha-' . $token
			),
			self::install_frontend_post_fixture(
				$base_id + 2,
				$author_id,
				'List Beta ' . $token,
				'Excerpt beta ' . $token . ' with deterministic words for latest posts.',
				'Full post beta ' . $token . ' with deterministic body copy for latest posts.',
				'2026-06-22 11:00:00',
				'list-beta-' . $token
			),
		);

		$category_parent = self::make_frontend_term_fixture( $base_id + 101, 'News ' . $token, 'news-' . $token, 'category', 2 );
		$category_child  = self::make_frontend_term_fixture( $base_id + 102, 'Dispatches ' . $token, 'dispatches-' . $token, 'category', 1, (int) $category_parent->term_id );
		\update_option( 'category_children', array( (int) $category_parent->term_id => array( (int) $category_child->term_id ) ) );

		$tags            = array(
			self::make_frontend_term_fixture( $base_id + 201, 'Alpha ' . $token, 'alpha-' . $token, 'post_tag', 5 ),
			self::make_frontend_term_fixture( $base_id + 202, 'Beta ' . $token, 'beta-' . $token, 'post_tag', 2 ),
		);

		return array(
			'token'        => $token,
			'authorId'     => $author_id,
			'authorName'   => 'Component Fuzz User',
			'archiveCount' => count( $posts ),
			'posts'        => $posts,
			'categories'   => array( $category_parent, $category_child ),
			'tags'         => $tags,
			'comments'     => array(
				self::make_frontend_comment_fixture( $base_id + 301, (int) $posts[0]->ID, 'Ada ' . $token, 'First latest comment ' . $token . ' with deterministic text.', $author_id ),
				self::make_frontend_comment_fixture( $base_id + 302, (int) $posts[1]->ID, 'Grace ' . $token, 'Second latest comment ' . $token . ' with deterministic text.', $author_id ),
			),
		);
	}

	private static function install_frontend_post_fixture( int $id, int $author_id, string $title, string $excerpt, string $content, string $date, string $slug ): \WP_Post {
		$row = array(
			'ID'                    => $id,
			'post_author'           => $author_id,
			'post_date'             => $date,
			'post_date_gmt'         => $date,
			'post_content'          => $content,
			'post_title'            => $title,
			'post_excerpt'          => $excerpt,
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $slug,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $date,
			'post_modified_gmt'     => $date,
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => self::expected_permalink( $id ),
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 1,
			'filter'                => 'raw',
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->posts, $row );
		}

		$post = new \WP_Post( (object) $row );
		\wp_cache_set( $post->ID, $post, 'posts' );

		return $post;
	}

	private static function make_frontend_term_fixture( int $id, string $name, string $slug, string $taxonomy, int $count, int $parent = 0 ): \WP_Term {
		$row = array(
			'term_id'          => $id,
			'name'             => $name,
			'slug'             => $slug,
			'term_group'       => 0,
			'term_taxonomy_id' => $id,
			'taxonomy'         => $taxonomy,
			'description'      => '',
			'parent'           => $parent,
			'count'            => $count,
			'filter'           => 'raw',
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			$GLOBALS['wpdb']->insert(
				$GLOBALS['wpdb']->terms,
				array(
					'term_id'    => $id,
					'name'       => $name,
					'slug'       => $slug,
					'term_group' => 0,
				)
			);
			$GLOBALS['wpdb']->insert(
				$GLOBALS['wpdb']->term_taxonomy,
				array(
					'term_taxonomy_id' => $id,
					'term_id'          => $id,
					'taxonomy'         => $taxonomy,
					'description'      => '',
					'parent'           => $parent,
					'count'            => $count,
				)
			);
		}

		$term = new \WP_Term( (object) $row );
		\wp_cache_set( $id, $term, 'terms' );

		return $term;
	}

	private static function make_frontend_comment_fixture( int $id, int $post_id, string $author, string $content, int $user_id ): \WP_Comment {
		$row = array(
			'comment_ID'           => $id,
			'comment_post_ID'      => $post_id,
			'comment_author'       => $author,
			'comment_author_email' => strtolower( str_replace( ' ', '.', $author ) ) . '@example.test',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2026-06-23 12:00:00',
			'comment_date_gmt'     => '2026-06-23 10:00:00',
			'comment_content'      => $content,
			'comment_karma'        => '0',
			'comment_approved'     => '1',
			'comment_agent'        => 'ComponentFuzz',
			'comment_type'         => 'comment',
			'comment_parent'       => '0',
			'user_id'              => (string) $user_id,
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->comments, $row );
		}

		$comment = new \WP_Comment( (object) $row );
		\wp_cache_set( $id, $comment, 'comment' );

		return $comment;
	}

	private static function seed_core_archives_cache( array $results ): void {
		global $wpdb;

		$parsed_args = array(
			'type'            => 'monthly',
			'limit'           => '',
			'format'          => 'html',
			'before'          => '',
			'after'           => '',
			'show_post_count' => '1',
			'echo'            => 1,
			'order'           => 'DESC',
			'post_type'       => 'post',
			'year'            => '',
			'monthnum'        => '',
			'day'             => '',
			'w'               => '',
		);
		$where       = $wpdb->prepare( "WHERE post_type = %s AND post_status = 'publish'", $parsed_args['post_type'] );
		$query       = "SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts FROM $wpdb->posts  $where GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY post_date DESC ";
		$key         = 'wp_get_archives:' . md5( $query );

		\wp_cache_set_salted( $key, $results, 'post-queries', \wp_cache_get_last_changed( 'posts' ) );
	}

	private static function seed_core_calendar_cache( string $html, int $monthnum, int $year ): void {
		$args = array(
			'initial'   => true,
			'display'   => true,
			'post_type' => 'post',
		);
		$cache_args = $args;
		unset( $cache_args['display'] );

		$cache_args['globals'] = array(
			'm'        => $GLOBALS['m'] ?? null,
			'monthnum' => $monthnum,
			'year'     => $year,
			'week'     => isset( $_GET['w'] ) ? (int) $_GET['w'] : 0,
		);

		\wp_recursive_ksort( $cache_args );
		$key = md5( serialize( $cache_args ) );
		\wp_cache_set( 'get_calendar', array( $key => $html ), 'calendar' );
	}

	private static function navigation_case( \ComponentFuzz\FuzzContext $ctx, int $index, array $page_fixture ): array {
		$token           = self::token( $ctx, 'nav' );
		$overlay_menu    = $ctx->choice( array( 'never', 'mobile', 'always' ) );
		$submenu_mode    = $ctx->choice( array( 'hover', 'click', 'always' ) );
		$legacy_present  = 0 === $index % 3;
		$legacy_on_click = 0 === $index % 2;
		$show_icon       = $ctx->bool();
		$link_url        = 'https://example.test/navigation/' . rawurlencode( $token ) . '?from=top';
		$submenu_url     = 'https://example.test/navigation/' . rawurlencode( $token ) . '/section';
		$child_url       = 'https://example.test/navigation/' . rawurlencode( $token ) . '/child';
		$rel             = 'nofollow ' . self::hostile_text( $token . '-rel' );
		$title           = 'Title ' . self::hostile_text( $token . '-title' );
		$attrs           = array(
			'ariaLabel'        => 'Menu ' . $token,
			'overlayMenu'      => $overlay_menu,
			'showSubmenuIcon'  => $show_icon,
			'submenuVisibility' => $submenu_mode,
			'layout'           => array(
				'justifyContent' => $ctx->choice( array( 'left', 'center', 'right', 'space-between' ) ),
				'orientation'    => $ctx->choice( array( 'horizontal', 'vertical' ) ),
				'flexWrap'       => $ctx->choice( array( 'wrap', 'nowrap' ) ),
			),
			'style'            => array(
				'typography' => array(
					'textDecoration' => $ctx->choice( array( 'none', 'underline', 'line-through' ) ),
				),
			),
		);

		if ( $legacy_present ) {
			$attrs['openSubmenusOnClick'] = $legacy_on_click;
		}

		$visibility = $legacy_present ? ( $legacy_on_click ? 'click' : 'hover' ) : $submenu_mode;
		$link       = self::navigation_link_block(
			'<strong>' . $token . ' Link</strong>' . self::hostile_text( $token . '-label' ),
			$link_url,
			array(
				'description'   => '<em>' . $token . ' Description</em>' . self::hostile_text( $token . '-description' ),
				'rel'           => $rel,
				'title'         => $title,
				'opensInNewTab' => $ctx->bool(),
			)
		);
		$submenu    = self::navigation_submenu_block(
			$token . ' Section' . self::hostile_text( $token . '-section' ),
			$submenu_url,
			array(
				self::navigation_link_block(
					$token . ' Child',
					$child_url,
					array(
						'description' => 'Child description ' . $token,
					)
				),
			),
			array(
				'description'   => 'Section description ' . $token,
				'rel'           => $rel,
				'title'         => $title,
				'opensInNewTab' => $ctx->bool(),
			)
		);
		$home       = self::parsed_block(
			'core/home-link',
			array(
				'label' => '<span>' . $token . ' Home</span>' . self::hostile_text( $token . '-home' ),
			)
		);
		$page_list  = self::parsed_block(
			'core/page-list',
			array(
				'parentPageID' => 0,
				'isNested'     => false,
			)
		);

		return array(
			'token'        => $token,
			'attrs'        => $attrs,
			'innerBlocks'  => array( $home, $link, $submenu, $page_list ),
			'innerContent' => array( null, "\n", null, "\n", null, "\n", null ),
			'visibility'   => $visibility,
			'linkUrl'      => $link_url,
			'submenuUrl'   => $submenu_url,
			'childUrl'     => $child_url,
			'rel'          => $rel,
			'title'        => $title,
			'pageIds'      => array(
				'parent' => $page_fixture['parent']->ID,
				'child'  => $page_fixture['child']->ID,
			),
		);
	}

	private static function install_navigation_page_fixtures( \ComponentFuzz\FuzzContext $ctx, int $index ): array {
		$token     = self::token( $ctx, 'page' );
		$parent_id = 920000 + ( $ctx->seed() & 0xffff ) + ( $index * 10 );
		$child_id  = $parent_id + 1;
		$parent    = self::install_page_fixture(
			$parent_id,
			'Parent ' . $token . ' <script>alert(1)</script>',
			0,
			'parent-' . $token,
			0
		);
		$child     = self::install_page_fixture(
			$child_id,
			'Child ' . $token . ' <img src="javascript:alert(1)" onerror="alert(1)">',
			$parent_id,
			'child-' . $token,
			1
		);

		return array(
			'token'  => $token,
			'parent' => $parent,
			'child'  => $child,
		);
	}

	private static function install_page_fixture( int $id, string $title, int $parent_id, string $slug, int $menu_order ): \WP_Post {
		$row = array(
			'ID'                    => $id,
			'post_author'           => 1,
			'post_date'             => '2024-06-01 00:00:00',
			'post_date_gmt'         => '2024-06-01 00:00:00',
			'post_content'          => 'Page content for ' . $slug,
			'post_title'            => $title,
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $slug,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-06-01 00:00:00',
			'post_modified_gmt'     => '2024-06-01 00:00:00',
			'post_content_filtered' => '',
			'post_parent'           => $parent_id,
			'guid'                  => 'http://example.test/?page_id=' . $id,
			'menu_order'            => $menu_order,
			'post_type'             => 'page',
			'post_mime_type'        => '',
			'comment_count'         => 0,
			'filter'                => 'raw',
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->posts, $row );
		}

		$post = new \WP_Post( (object) $row );
		\wp_cache_set( $post->ID, $post, 'posts' );

		return $post;
	}

	private static function set_current_page_query( \WP_Post $page ): void {
		$query                    = new \WP_Query();
		$query->query_vars        = array(
			'page_id' => $page->ID,
			'p'       => $page->ID,
		);
		$query->post              = $page;
		$query->posts             = array( $page );
		$query->queried_object    = $page;
		$query->queried_object_id = $page->ID;
		$query->is_page           = true;
		$query->is_singular       = true;
		$query->is_home           = false;
		$query->is_archive        = false;

		$GLOBALS['post']         = $page;
		$GLOBALS['id']           = $page->ID;
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
	}

	private static function navigation_link_block( string $label, string $url, array $attrs = array() ): array {
		return self::parsed_block(
			'core/navigation-link',
			array_merge(
				array(
					'label' => $label,
					'url'   => $url,
					'kind'  => 'custom',
					'type'  => 'custom',
				),
				$attrs
			)
		);
	}

	private static function navigation_submenu_block( string $label, string $url, array $inner_blocks, array $attrs = array() ): array {
		$inner_content = array();
		foreach ( $inner_blocks as $inner_block ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}
		array_pop( $inner_content );

		return self::parsed_block(
			'core/navigation-submenu',
			array_merge(
				array(
					'label' => $label,
					'url'   => $url,
					'kind'  => 'custom',
					'type'  => 'custom',
				),
				$attrs
			),
			'',
			$inner_blocks,
			$inner_content
		);
	}

	private static function post_block_case( \ComponentFuzz\FuzzContext $ctx, int $index ): array {
		$token          = self::token( $ctx, 'post' );
		$day            = $ctx->int( 1, 26 );
		$date           = sprintf( '2024-06-%02d %02d:%02d:00', $day, $ctx->int( 0, 23 ), $ctx->int( 0, 59 ) );
		$date_format    = $ctx->choice( array( 'Y-m-d', 'M j, Y', 'F j, Y' ) );
		$title_level    = $ctx->choice( array( 0, 1, 2, 3, 4, 5, 6 ) );
		$excerpt_length = $ctx->int( 4, 12 );
		$excerpt_words  = array( 'Excerpt', $token, 'alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet' );

		return array(
			'id'              => 700000 + ( $ctx->seed() & 0xffff ) + $index,
			'token'           => $token,
			'title'           => 'Fuzz Post ' . $token,
			'excerpt'         => implode( ' ', $excerpt_words ),
			'excerptNeedle'   => 'Excerpt ' . $token,
			'content'         => 'Content body for ' . $token . ' with deterministic words only.',
			'date'            => $date,
			'dateFormat'      => $date_format,
			'formattedDate'   => \wp_date( $date_format, strtotime( $date ) ),
			'dateIsLink'      => $ctx->bool(),
			'titleLevel'      => $title_level,
			'titleTag'        => 0 === $title_level ? 'p' : 'h' . $title_level,
			'textAlign'       => $ctx->choice( array( 'left', 'center', 'right' ) ),
			'linkTarget'      => '_blank<script>',
			'hostileRel'      => 'nofollow ' . self::hostile_text( $token . '-rel' ),
			'excerptLength'   => $excerpt_length,
			'moreText'        => '<strong>More ' . $token . '</strong>' . self::hostile_text( $token . '-more' ),
			'moreOnNewLine'   => $ctx->bool(),
			'readMoreText'    => '<span>Read ' . $token . '</span>' . self::hostile_text( $token . '-read' ),
			'justifyContent'  => $ctx->choice( array( 'left', 'center', 'right', 'space-between' ) ),
		);
	}

	private static function install_post_fixture( array $case ): \WP_Post {
		$row = array(
			'ID'                    => $case['id'],
			'post_author'           => 1,
			'post_date'             => $case['date'],
			'post_date_gmt'         => $case['date'],
			'post_content'          => $case['content'],
			'post_title'            => $case['title'],
			'post_excerpt'          => $case['excerpt'],
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'fuzz-post-' . $case['token'],
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $case['date'],
			'post_modified_gmt'     => $case['date'],
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => self::expected_permalink( $case['id'] ),
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
			'filter'                => 'raw',
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->posts, $row );
		}

		$post = new \WP_Post( (object) $row );
		\wp_cache_set( $post->ID, $post, 'posts' );

		return $post;
	}

	private static function install_user_fixture( \ComponentFuzz\FuzzContext $ctx ): int {
		$user_id = 910000 + ( $ctx->seed() & 0xffff );
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			$GLOBALS['wpdb']->insert(
				$GLOBALS['wpdb']->users,
				array(
					'ID'                  => $user_id,
					'user_login'          => 'cfz_' . self::token( $ctx, 'user' ),
					'user_pass'           => 'hash',
					'user_nicename'       => 'cfz-user',
					'user_email'          => 'cfz-user@example.test',
					'user_url'            => '',
					'user_registered'     => '2024-06-01 00:00:00',
					'user_activation_key' => '',
					'user_status'         => 0,
					'display_name'        => 'Component Fuzz User',
				)
			);
		}

		return $user_id;
	}

	private static function install_global_query( int $page, int $total ): void {
		$query                = new \WP_Query();
		$query->query_vars    = array(
			'paged'          => $page,
			'posts_per_page' => 3,
		);
		$query->max_num_pages = $total;
		$query->found_posts   = $total * 3;
		$query->is_home       = true;
		$query->is_archive    = true;
		$query->is_single     = false;
		$query->is_singular   = false;

		$GLOBALS['paged']        = $page;
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
	}

	private static function render_simple_block( string $name, array $attrs = array(), string $inner_html = '', array $inner_blocks = array(), ?array $inner_content = null ): string {
		return \render_block( self::parsed_block( $name, $attrs, $inner_html, $inner_blocks, $inner_content ) );
	}

	private static function render_block_with_context( array $parsed_block, array $context ): string {
		$block = new \WP_Block( $parsed_block, $context );
		return $block->render();
	}

	private static function parsed_block( string $name, array $attrs = array(), string $inner_html = '', array $inner_blocks = array(), ?array $inner_content = null ): array {
		if ( null === $inner_content ) {
			$inner_content = array( $inner_html );
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		);
	}

	private static function expected_permalink( int $post_id ): string {
		return 'http://example.test/?p=' . $post_id;
	}

	private static function token( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$raw = strtolower( $label . '-' . $ctx->identifier( 3, 10 ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$raw = preg_replace( '/[^a-z0-9-]+/', '-', $raw );
		$raw = trim( preg_replace( '/-+/', '-', (string) $raw ), '-' );

		return '' === $raw ? $label . '-0' : $raw;
	}

	private static function hostile_text( string $token ): string {
		return '<script data-token="' . $token . '">alert(1)</script><img src="javascript:alert(1)" onerror="alert(1)">';
	}

	private static function sane_html_fragment( string $html, array $paired_tags ): bool {
		$report = self::hostile_report( $html );
		if ( $report['rawScriptTag'] || $report['inlineEventAttribute'] || $report['javascriptUrl'] || $report['controlBytes'] > 0 ) {
			return false;
		}

		return self::paired_tags_balanced( $html, $paired_tags );
	}

	private static function hostile_report( string $html ): array {
		preg_match_all( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html, $control_matches );

		return array(
			'rawScriptTag'         => false !== stripos( $html, '<script' ),
			'inlineEventAttribute' => self::has_inline_event_attribute( $html ),
			'javascriptUrl'        => self::has_javascript_url_attribute( $html ),
			'controlBytes'         => count( $control_matches[0] ),
			'bytes'                => strlen( $html ),
		);
	}

	private static function has_inline_event_attribute( string $html ): bool {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$event_attributes = $processor->get_attribute_names_with_prefix( 'on' );
			if ( ! is_array( $event_attributes ) ) {
				continue;
			}
			foreach ( $event_attributes as $attribute ) {
				if ( 1 === preg_match( '/^on[a-z]+$/i', $attribute ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function has_javascript_url_attribute( string $html ): bool {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			foreach ( array( 'href', 'src', 'action', 'data' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( is_string( $value ) && 1 === preg_match( '/^\s*javascript\s*:/i', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function has_paged_link( string $html, int $target_page, string $keep_token ): bool {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			if ( 'A' !== $processor->get_tag() ) {
				continue;
			}

			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) ) {
				continue;
			}

			$parts = \wp_parse_url( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( ! is_array( $parts ) ) {
				continue;
			}

			$query = array();
			parse_str( (string) ( $parts['query'] ?? '' ), $query );
			if ( $keep_token !== ( $query['keep'] ?? null ) ) {
				continue;
			}

			$paged = isset( $query['paged'] ) ? (int) $query['paged'] : 1;
			if ( $target_page === $paged ) {
				return true;
			}
		}

		return false;
	}

	private static function paired_tags_balanced( string $html, array $tags ): bool {
		foreach ( array_unique( $tags ) as $tag ) {
			if ( in_array( $tag, array( 'area', 'br', 'hr', 'img', 'input', 'meta', 'link', 'object' ), true ) ) {
				continue;
			}

			$open   = preg_match_all( '/<' . preg_quote( $tag, '/' ) . '(?:\s|>|\/)/i', $html );
			$closed = preg_match_all( '/<\/' . preg_quote( $tag, '/' ) . '\s*>/i', $html );
			if ( $open !== $closed ) {
				return false;
			}
		}

		return true;
	}

	private static function hook_callbacks( string $hook_name ): array {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook_name ] ) || ! ( $GLOBALS['wp_filter'][ $hook_name ] instanceof \WP_Hook ) ) {
			return array();
		}

		return $GLOBALS['wp_filter'][ $hook_name ]->callbacks;
	}

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function reset_content(): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function script_module_enqueued( string $id ): bool {
		if ( ! function_exists( 'wp_script_modules' ) ) {
			return false;
		}

		$modules = \wp_script_modules();
		if ( ! is_object( $modules ) || ! property_exists( $modules, 'queue' ) ) {
			return false;
		}

		$queue = self::get_object_property( $modules, 'queue' );
		return is_array( $queue ) && in_array( $id, $queue, true );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
		if ( $condition || count( $failures ) >= self::MAX_FAILURES ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => \ComponentFuzz\preview_value( $value, 220 ),
		);
	}

	private static function snapshot_navigation_renderer_state(): ?array {
		if ( ! class_exists( 'WP_Navigation_Block_Renderer' ) ) {
			return null;
		}

		return self::snapshot_static_properties(
			array(
				'WP_Navigation_Block_Renderer' => array( 'has_submenus', 'needs_list_item_wrapper', 'seen_menu_names' ),
			)
		);
	}

	private static function restore_navigation_renderer_state( ?array $snapshot ): void {
		if ( null === $snapshot ) {
			return;
		}

		foreach ( $snapshot as $class => $properties ) {
			foreach ( $properties as $property => $value ) {
				self::set_static_property( $class, $property, $value );
			}
		}
	}

	private static function navigation_renderer_state_matches( ?array $snapshot ): bool {
		if ( null === $snapshot ) {
			return true;
		}

		foreach ( $snapshot as $class => $properties ) {
			foreach ( $properties as $property => $value ) {
				if ( self::get_static_property( $class, $property ) != $value ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function snapshot_state(): array {
		$block_registry = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		$supports       = self::get_static_property( 'WP_Block_Supports', 'instance' );

		return array(
			'obLevel'       => ob_get_level(),
			'globals'       => self::snapshot_globals(
				array(
					'_GET',
					'_POST',
					'_REQUEST',
					'_SERVER',
					'authordata',
					'current_user',
					'id',
					'm',
					'monthnum',
					'page',
					'paged',
					'pages',
					'more',
					'multipage',
					'numpages',
					'post',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_interactivity',
					'wp_object_cache',
					'wp_post_types',
					'wp_query',
					'wp_rewrite',
					'wp_script_modules',
					'wp_scripts',
					'wp_styles',
					'wp_taxonomies',
					'wp_the_query',
					'wpdb',
					'year',
				)
			),
			'blockRegistry' => $block_registry,
			'blockTypes'    => $block_registry instanceof \WP_Block_Type_Registry ? self::get_object_property( $block_registry, 'registered_block_types' ) : null,
			'supports'      => $supports,
			'blockSupports' => $supports instanceof \WP_Block_Supports ? self::get_object_property( $supports, 'block_supports' ) : null,
			'blockToRender' => self::get_static_property( 'WP_Block_Supports', 'block_to_render' ),
			'statics'       => self::snapshot_static_properties(
				array(
					'WP_Block_Metadata_Registry' => array( 'collections', 'last_matched_collection', 'default_collection_roots' ),
					'WP_Theme_JSON'              => array( 'blocks_metadata' ),
				)
			),
		);
	}

	private static function restore_state( array $snapshot ): void {
		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}

		self::restore_globals( $snapshot['globals'] );

		if ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) {
			self::set_object_property( $snapshot['blockRegistry'], 'registered_block_types', $snapshot['blockTypes'] );
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', $snapshot['blockRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', null );
		}

		if ( $snapshot['supports'] instanceof \WP_Block_Supports ) {
			self::set_object_property( $snapshot['supports'], 'block_supports', $snapshot['blockSupports'] );
			self::set_static_property( 'WP_Block_Supports', 'instance', $snapshot['supports'] );
		} else {
			self::set_static_property( 'WP_Block_Supports', 'instance', null );
		}
		self::set_static_property( 'WP_Block_Supports', 'block_to_render', $snapshot['blockToRender'] );

		foreach ( $snapshot['statics'] as $class => $properties ) {
			foreach ( $properties as $property => $value ) {
				self::set_static_property( $class, $property, $value );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		if ( ob_get_level() !== $snapshot['obLevel'] ) {
			return false;
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( array_key_exists( $name, $GLOBALS ) !== $entry['exists'] ) {
				return false;
			}
		}

		$block_registry = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		if ( ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) !== ( $block_registry instanceof \WP_Block_Type_Registry ) ) {
			return false;
		}
		if ( $block_registry instanceof \WP_Block_Type_Registry && self::get_object_property( $block_registry, 'registered_block_types' ) != $snapshot['blockTypes'] ) {
			return false;
		}

		if ( self::get_static_property( 'WP_Block_Supports', 'block_to_render' ) !== $snapshot['blockToRender'] ) {
			return false;
		}

		foreach ( $snapshot['statics'] as $class => $properties ) {
			foreach ( $properties as $property => $value ) {
				if ( self::get_static_property( $class, $property ) != $value ) {
					return false;
				}
			}
		}

		return true;
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

	private static function snapshot_static_properties( array $classes ): array {
		$snapshot = array();
		foreach ( $classes as $class => $properties ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			foreach ( $properties as $property ) {
				if ( ! property_exists( $class, $property ) ) {
					continue;
				}

				$snapshot[ $class ][ $property ] = self::clone_value( self::get_static_property( $class, $property ) );
			}
		}

		return $snapshot;
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
			return null;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
			return;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
	}

	private static function get_object_property( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		return $reflection->getValue( $object );
	}

	private static function set_object_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setValue( $object, $value );
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

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
