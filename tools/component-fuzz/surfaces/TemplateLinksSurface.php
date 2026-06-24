<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB public template and link helper APIs.
 */
final class TemplateLinksSurface {
	public const NAME = 'template-links';

	private const PREVIEW_BYTES = 180;

	/** @var array<string,mixed> */
	private static array $options = array();

	/** @var array<int,\WP_Post> */
	private static array $posts = array();

	/** @var array<int,object> */
	private static array $bookmarks = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'template-links.bootstrap-apis-available',
					'Required WordPress template/link APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::install_filters();
			self::reset_runtime( $ctx );

			$rows[] = self::check_body_class_and_language_attributes( $ctx );
			$rows[] = self::check_document_title_helpers( $ctx );
			$rows[] = self::check_resource_hints_and_preloads( $ctx );
			$rows[] = self::check_page_and_paginate_links( $ctx );
			$rows[] = self::check_search_feed_site_and_admin_links( $ctx );
			$rows[] = self::check_post_link_helpers( $ctx );
			$rows[] = self::check_canonical_and_shortlink_outputs( $ctx );
			$rows[] = self::check_bookmark_fields_and_lists( $ctx );
			$rows[] = self::check_restoration_probe( $ctx, $snapshot );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'template-links.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	public static function filter_option( $pre_option, string $option = '', $default_value = false ) {
		unset( $default_value );

		if ( array_key_exists( $option, self::$options ) ) {
			return self::$options[ $option ];
		}

		return $pre_option;
	}

	public static function filter_user_has_cap( array $allcaps, array $caps ): array {
		foreach ( $caps as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	public static function filter_update_post_metadata_cache( $check, array $object_ids ) {
		foreach ( $object_ids as $object_id ) {
			if ( ! wp_cache_get( (int) $object_id, 'post_meta' ) ) {
				wp_cache_set( (int) $object_id, array(), 'post_meta' );
			}
		}

		return true;
	}

	public static function filter_post_metadata( $value, int $object_id, string $meta_key, bool $single ) {
		unset( $object_id );

		if ( '_wp_page_template' === $meta_key || '_wp_trash_meta_status' === $meta_key ) {
			return $single ? '' : array();
		}

		return $value;
	}

	public static function filter_post_class_taxonomies(): array {
		return array();
	}

	public static function filter_resource_hints( array $urls, string $relation_type ): array {
		unset( $urls );

		if ( 'preconnect' === $relation_type ) {
			return array(
				'https://cdn.example.test/assets/app.js?x=<script>',
				'https://cdn.example.test/duplicate.css',
				'javascript:alert(1)',
				array(
					'href'        => 'https://fonts.example.test/css?family=Component+Fuzz',
					'as'          => 'style',
					'crossorigin' => 'anonymous',
					'badattr'     => '<script>',
				),
			);
		}

		if ( 'dns-prefetch' === $relation_type ) {
			return array(
				'//static.example.test/assets',
				'https://static.example.test/ignored-path',
				'data:text/html,<script>alert(1)</script>',
			);
		}

		if ( 'prefetch' === $relation_type ) {
			return array(
				array(
					'href' => 'https://example.test/prefetch?q=<unsafe>&ok=1',
					'as'   => 'document',
				),
			);
		}

		return array(
			'https://example.test/prerender/' . rawurlencode( 'next page' ),
		);
	}

	public static function filter_preload_resources(): array {
		return array(
			array(
				'href'          => 'https://example.test/assets/app.js?bad=<script>',
				'as'            => 'script',
				'crossorigin'   => 'anonymous',
				'fetchpriority' => 'high',
				'badattr'       => 'must-not-render',
			),
			array(
				'href' => 'https://example.test/assets/app.js?bad=<script>',
				'as'   => 'script',
			),
			array(
				'href'  => 'javascript:alert(1)',
				'as'    => 'script',
				'media' => 'screen and (min-width: <script>)',
			),
			array(
				'as'          => 'image',
				'imagesrcset' => 'https://example.test/img-small.jpg 480w, https://example.test/img-large.jpg 960w',
				'imagesizes'  => '(max-width: 600px) 480px, 960px',
			),
		);
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Post', 'WP_Query', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_walk_bookmarks',
				'add_filter',
				'body_class',
				'create_initial_post_types',
				'esc_attr',
				'esc_url',
				'get_admin_url',
				'get_body_class',
				'get_bookmark',
				'get_bookmark_field',
				'get_edit_post_link',
				'get_delete_post_link',
				'get_feed_link',
				'get_home_url',
				'get_language_attributes',
				'get_pagenum_link',
				'get_permalink',
				'get_preview_post_link',
				'get_search_feed_link',
				'get_search_link',
				'get_site_url',
				'language_attributes',
				'paginate_links',
				'rel_canonical',
				'remove_filter',
				'remove_post_type_support',
				'sanitize_html_class',
				'sanitize_title_with_dashes',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
				'wp_check_invalid_utf8',
				'wp_get_canonical_url',
				'wp_get_document_title',
				'wp_get_shortlink',
				'wp_list_bookmarks',
				'wp_preload_resources',
				'wp_resource_hints',
				'wp_shortlink_wp_head',
				'wp_title',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'EMPTY_TRASH_DAYS' ) ) {
			$missing[] = 'constant EMPTY_TRASH_DAYS';
		}

		return $missing;
	}

	private static function check_body_class_and_language_attributes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$post     = self::current_post();
		$custom   = "custom-{$post->ID} raw<script> spaced\tclass " . $ctx->identifier( 3, 10 );
		$classes  = \get_body_class( $custom );

		ob_start();
		\body_class( $custom );
		$body_output = (string) ob_get_clean();

		$expected_body_output = 'class="' . \esc_attr( implode( ' ', $classes ) ) . '"';
		$builtin_tokens       = array(
			'wp-singular',
			'single',
			'single-post',
			'postid-' . $post->ID,
			'wp-theme-component-fuzz-theme',
		);

		self::collect_failure(
			$failures,
			$expected_body_output === $body_output
				&& array() === array_diff( $builtin_tokens, $classes )
				&& count( $classes ) === count( array_unique( $classes ) )
				&& ! self::contains_raw_dangerous_html( $body_output )
				&& self::all_builtin_class_tokens_are_sane( $classes ),
			'get_body_class/body_class agree, include expected singular tokens, and escape custom class bytes',
			array(
				'classes'  => $classes,
				'expected' => $expected_body_output,
				'actual'   => $body_output,
			)
		);

		$html = \get_language_attributes( 'html' );
		ob_start();
		\language_attributes( 'html' );
		$html_echo = (string) ob_get_clean();

		$xhtml = \get_language_attributes( 'xhtml' );
		ob_start();
		\language_attributes( 'xhtml' );
		$xhtml_echo = (string) ob_get_clean();

		self::collect_failure(
			$failures,
			$html === $html_echo
				&& $xhtml === $xhtml_echo
				&& str_contains( $html, 'lang="en-US"' )
				&& str_contains( $xhtml, 'lang="en-US"' )
				&& str_contains( $xhtml, 'xml:lang="en-US"' )
				&& ! self::contains_raw_dangerous_html( $html . $xhtml ),
			'language attribute getter and echo wrapper agree across html/xhtml doctypes',
			array(
				'html'      => $html,
				'htmlEcho'  => $html_echo,
				'xhtml'     => $xhtml,
				'xhtmlEcho' => $xhtml_echo,
			)
		);

		return self::row(
			$ctx,
			'template-links.template.body-class-and-language-attributes',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_document_title_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$post     = self::current_post();

		$title_one = \wp_get_document_title();
		$title_two = \wp_get_document_title();
		$wp_title  = \wp_title( '|', false, 'right' );
		$single    = \single_post_title( 'Prefix: ', false );

		ob_start();
		\wp_title( '|', true, 'right' );
		$wp_title_echo = (string) ob_get_clean();

		ob_start();
		\single_post_title( 'Prefix: ', true );
		$single_echo = (string) ob_get_clean();

		self::collect_failure(
			$failures,
			$title_one === $title_two
				&& is_string( $wp_title )
				&& $wp_title === $wp_title_echo
				&& 'Prefix: ' . $post->post_title === $single
				&& $single === $single_echo
				&& str_contains( $title_one, $post->post_title )
				&& str_contains( $title_one, (string) self::$options['blogname'] ),
			'document title helpers are deterministic and getter/display variants agree for a synthetic single post',
			array(
				'documentTitle' => self::describe_string( $title_one ),
				'wpTitle'       => self::describe_string( $wp_title ),
				'singleTitle'   => self::describe_string( (string) $single ),
				'postTitle'     => self::describe_string( $post->post_title ),
			)
		);

		return self::row(
			$ctx,
			'template-links.template.document-title-stability',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_resource_hints_and_preloads( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		ob_start();
		\wp_resource_hints();
		$hints = (string) ob_get_clean();

		ob_start();
		\wp_preload_resources();
		$preloads = (string) ob_get_clean();

		self::collect_failure(
			$failures,
			str_contains( $hints, "rel='preconnect'" )
				&& str_contains( $hints, "href='https://cdn.example.test'" )
				&& str_contains( $hints, "href='//static.example.test'" )
				&& str_contains( $hints, "crossorigin='anonymous'" )
				&& 1 === substr_count( $hints, "href='https://cdn.example.test'" )
				&& ! str_contains( strtolower( $hints ), 'javascript:' )
				&& ! str_contains( strtolower( $hints ), '<script' )
				&& ! str_contains( $hints, 'badattr' ),
			'wp_resource_hints normalizes hosts, deduplicates, filters unsafe schemes, and escapes attributes',
			array( 'hints' => self::describe_string( $hints ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $preloads, "rel='preload'" )
				&& str_contains( $preloads, "href='https://example.test/assets/app.js?bad=script'" )
				&& str_contains( $preloads, "fetchpriority='high'" )
				&& str_contains( $preloads, "imagesrcset='https://example.test/img-small.jpg 480w, https://example.test/img-large.jpg 960w'" )
				&& 1 === substr_count( $preloads, "href='https://example.test/assets/app.js?bad=script'" )
				&& ! str_contains( strtolower( $preloads ), 'javascript:' )
				&& ! str_contains( strtolower( $preloads ), '<script' )
				&& ! str_contains( $preloads, 'badattr' ),
			'wp_preload_resources deduplicates hrefs, filters unsafe href protocols, and escapes supported attributes',
			array( 'preloads' => self::describe_string( $preloads ) )
		);

		return self::row(
			$ctx,
			'template-links.template.resource-hints-and-preloads',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_page_and_paginate_links( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::with_permalink_structure(
			$ctx->choice( array( '', '/%year%/%monthnum%/%postname%/', '/index.php/%post_id%/%postname%' ) ),
			static function () use ( $ctx, &$failures ): void {
				$query_text = $ctx->text( 0, 24 );
				$_SERVER['REQUEST_URI'] = '/section/page/3/?paged=3&q=' . rawurlencode( $query_text ) . '&unsafe=<script>#frag';

				$page_one   = \get_pagenum_link( 1, true );
				$page_five  = \get_pagenum_link( 5, true );
				$page_raw   = \get_pagenum_link( 5, false );
				$page_again = \get_pagenum_link( 5, true );

				self::collect_failure(
					$failures,
					$page_five === $page_again
						&& ! str_contains( $page_one, 'paged=3' )
						&& ( str_contains( $page_five, 'paged=5' ) || str_contains( $page_five, '/page/5' ) )
						&& ! str_contains( strtolower( $page_five ), '<script' )
						&& ! str_contains( strtolower( $page_raw ), '<script' )
						&& self::is_http_or_relative_url( $page_five ),
					'get_pagenum_link removes stale paged args, adds requested page, escapes display output, and is stable',
					array(
						'pageOne'  => $page_one,
						'pageFive' => $page_five,
						'pageRaw'  => $page_raw,
					)
				);
			}
		);

		$base       = 'https://example.test/archive/%_%?existing=' . rawurlencode( $ctx->text( 0, 16 ) );
		$format     = 'page/%#%/';
		$fragment   = '#frag-' . rawurlencode( $ctx->text( 0, 10 ) ) . '-%3Cscript%3E';
		$base_args  = array(
			'base'               => $base,
			'format'             => $format,
			'total'              => 7,
			'current'            => 4,
			'show_all'           => false,
			'end_size'           => 1,
			'mid_size'           => 1,
			'prev_next'          => true,
			'prev_text'          => 'Previous',
			'next_text'          => 'Next',
			'add_args'           => array(
				'weird'  => $ctx->text( 0, 20 ),
				'url'    => 'javascript:alert(1)',
				'nested' => array( '<tag>' ),
			),
			'add_fragment'       => $fragment,
			'before_page_number' => 'Page ',
			'after_page_number'  => '',
		);
		$plain      = \paginate_links( array_merge( $base_args, array( 'type' => 'plain' ) ) );
		$array      = \paginate_links( array_merge( $base_args, array( 'type' => 'array' ) ) );
		$list       = \paginate_links( array_merge( $base_args, array( 'type' => 'list' ) ) );
		$null_links = \paginate_links( array_merge( $base_args, array( 'total' => 1 ) ) );

		self::collect_failure(
			$failures,
			is_string( $plain )
				&& is_array( $array )
				&& is_string( $list )
				&& null === $null_links
				&& implode( "\n", $array ) === $plain
				&& str_contains( $list, "<ul class='page-numbers'>" )
				&& 1 === substr_count( $plain, 'aria-current="page"' )
				&& str_contains( $plain, '#frag-' )
				&& str_contains( $plain, '-%3Cscript%3E' )
				&& ! str_contains( strtolower( $plain . $list ), '<script' )
				&& ! str_contains( strtolower( $plain . $list ), 'href="javascript:' ),
			'paginate_links keeps plain/array/list output shapes consistent while escaping generated hrefs',
			array(
				'plain' => self::describe_string( is_string( $plain ) ? $plain : '' ),
				'array' => $array,
				'list'  => self::describe_string( is_string( $list ) ? $list : '' ),
				'null'  => $null_links,
			)
		);

		return self::row(
			$ctx,
			'template-links.links.pagination-and-pagenum',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_search_feed_site_and_admin_links( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$query    = 'find/' . $ctx->text( 0, 24 ) . '<script>alert(1)</script>';
		$path     = 'edit.php?post_type=' . rawurlencode( self::current_post()->post_name ) . '&unsafe=%3Ctag%3E';

		self::with_permalink_structure(
			$ctx->choice( array( '', '/%year%/%monthnum%/%postname%/' ) ),
			static function () use ( $query, $path, &$failures ): void {
				$search       = \get_search_link( $query );
				$search_feed  = \get_search_feed_link( $query, 'atom' );
				$feed         = \get_feed_link( 'comments_atom' );
				$home_https   = \get_home_url( null, 'content/page?q=1', 'https' );
				$home_rel     = \get_home_url( null, '/relative/path', 'relative' );
				$site_http    = \get_site_url( null, 'wp-includes/js/app.js', 'http' );
				$admin        = \get_admin_url( null, $path, 'https' );
				$escaped_urls = array_map( 'esc_url', array( $search, $search_feed, $feed, $home_https, $home_rel, $site_http, $admin ) );

				self::collect_failure(
					$failures,
					str_starts_with( $home_https, 'https://example.test/content/page' )
						&& '/relative/path' === $home_rel
						&& str_starts_with( $site_http, 'http://example.test/wp-includes/js/app.js' )
						&& str_starts_with( $admin, 'https://example.test/wp-admin/edit.php' )
						&& str_contains( $search, '%3Cscript%3Ealert%281%29' )
						&& str_contains( $search_feed, 'atom' )
						&& ( str_contains( $feed, 'comments' ) || str_contains( $feed, 'comments_atom' ) )
						&& self::all_urls_are_http_or_relative( $escaped_urls )
						&& ! self::contains_raw_dangerous_html( implode( "\n", $escaped_urls ) )
						&& ! str_contains( strtolower( implode( "\n", $escaped_urls ) ), 'javascript:' ),
					'search/feed/home/site/admin URL helpers normalize schemes and produce display-safe escaped URLs',
					array(
						'search'      => $search,
						'searchFeed'  => $search_feed,
						'feed'        => $feed,
						'homeHttps'   => $home_https,
						'homeRel'     => $home_rel,
						'siteHttp'    => $site_http,
						'admin'       => $admin,
						'escapedUrls' => $escaped_urls,
					)
				);
			}
		);

		return self::row(
			$ctx,
			'template-links.links.search-feed-site-admin',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_post_link_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$post     = self::current_post();
		$links    = array();

		foreach ( array( '', '/%year%/%monthnum%/%postname%/', '/index.php/%post_id%/%postname%' ) as $structure ) {
			self::with_permalink_structure(
				$structure,
				static function () use ( $post, $structure, &$links, &$failures ): void {
					$permalink = \get_permalink( $post->ID );
					$links[ $structure ] = $permalink;

					$expected = '' === $structure
						? str_contains( $permalink, '?p=' . $post->ID )
						: str_contains( $permalink, $post->post_name );

					self::collect_failure(
						$failures,
						is_string( $permalink )
							&& $expected
							&& ! str_contains( $permalink, '<' )
							&& self::is_http_or_relative_url( $permalink ),
						"get_permalink honors structure {$structure}",
						array(
							'structure' => $structure,
							'permalink' => $permalink,
							'postName'  => $post->post_name,
						)
					);
				}
			);
		}

		self::with_permalink_structure(
			'/%year%/%monthnum%/%postname%/',
			static function () use ( $ctx, $post, &$failures ): void {
				$preview = \get_preview_post_link(
					$post->ID,
					array(
						'unsafe' => '<script>' . $ctx->text( 0, 12 ),
						'url'    => 'javascript:alert(1)',
					)
				);
				$edit_display = \get_edit_post_link( $post->ID, 'display' );
				$edit_raw     = \get_edit_post_link( $post->ID, 'raw' );
				$delete       = \get_delete_post_link( $post->ID, '', $ctx->bool() );
				$shortlink    = \wp_get_shortlink( $post->ID, 'post', true );

				$escaped_preview = \esc_url( (string) $preview );
				$escaped_delete  = \esc_url( (string) $delete );

				self::collect_failure(
					$failures,
					is_string( $preview )
						&& str_contains( $preview, 'preview=true' )
						&& str_contains( $escaped_preview, 'unsafe=script' )
						&& is_string( $edit_display )
						&& str_contains( $edit_display, 'post.php?post=' . $post->ID . '&amp;action=edit' )
						&& is_string( $edit_raw )
						&& str_contains( $edit_raw, 'post.php?post=' . $post->ID . '&action=edit' )
						&& is_string( $delete )
						&& str_contains( $delete, 'post.php?post=' . $post->ID )
						&& str_contains( $delete, '_wpnonce=' )
						&& 'http://example.test/?p=' . $post->ID === $shortlink
						&& self::is_http_or_relative_url( $escaped_preview )
						&& self::is_http_or_relative_url( $escaped_delete )
						&& ! self::contains_raw_dangerous_html( $escaped_preview . $escaped_delete )
						&& ! str_starts_with( strtolower( $escaped_preview ), 'javascript:' )
						&& ! str_starts_with( strtolower( $escaped_delete ), 'javascript:' ),
					'preview/edit/delete/shortlink helpers work with synthetic posts and produce escapable URLs',
					array(
						'preview'     => $preview,
						'editDisplay' => $edit_display,
						'editRaw'     => $edit_raw,
						'delete'      => $delete,
						'shortlink'   => $shortlink,
					)
				);
			}
		);

		self::collect_failure(
			$failures,
			3 === count( array_unique( $links ) ),
			'multiple permalink structures produce distinct deterministic post URLs',
			array( 'links' => $links )
		);

		return self::row(
			$ctx,
			'template-links.links.synthetic-post-helpers',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_canonical_and_shortlink_outputs( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$post     = self::current_post();
		$marker   = 'cfz-' . strtolower( $ctx->identifier( 4, 10 ) );

		$canonical_calls = array();
		$canonical_filter = static function ( string $url, \WP_Post $filtered_post ) use ( &$canonical_calls, $marker ): string {
			$canonical_calls[] = array(
				'url'  => $url,
				'post' => $filtered_post->ID,
			);

			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'cfz=' . rawurlencode( $marker );
		};

		$shortlink_calls = array();
		$shortlink_filter = static function (
			string $shortlink,
			int $id,
			string $context,
			bool $allow_slugs
		) use ( &$shortlink_calls, $marker ): string {
			$shortlink_calls[] = compact( 'shortlink', 'id', 'context', 'allow_slugs' );

			return $shortlink . ( str_contains( $shortlink, '?' ) ? '&' : '?' ) . 'short=<script>' . rawurlencode( $marker );
		};

		$draft_id = $post->ID + 30000 + $ctx->int( 10, 999 );
		$draft    = new \WP_Post(
			(object) array_merge(
				$post->to_array(),
				array(
					'ID'          => $draft_id,
					'post_name'   => 'draft-' . $marker,
					'post_status' => 'draft',
				)
			)
		);
		wp_cache_set( $draft_id, (object) $draft->to_array(), 'posts' );

		self::with_permalink_structure(
			'',
			static function () use ( $post, $draft_id, $marker, $canonical_filter, $shortlink_filter, &$canonical_calls, &$shortlink_calls, &$failures ): void {
				$query      = $GLOBALS['wp_query'] ?? null;
				$query_vars = $query instanceof \WP_Query ? $query->query_vars : null;
				$page_exists = array_key_exists( 'page', $GLOBALS );
				$page        = $GLOBALS['page'] ?? null;

				\add_filter( 'get_canonical_url', $canonical_filter, 10, 2 );
				\add_filter( 'get_shortlink', $shortlink_filter, 10, 4 );
				$buffer_level = ob_get_level();
				try {
					if ( $query instanceof \WP_Query ) {
						$query->query_vars['page']  = 3;
						$query->query_vars['cpage'] = 0;
					}
					$GLOBALS['page'] = 3;

					$canonical = \wp_get_canonical_url( $post->ID );

					ob_start();
					\rel_canonical();
					$canonical_output = (string) ob_get_clean();

					ob_start();
					\wp_shortlink_wp_head();
					$shortlink_output = (string) ob_get_clean();

					$draft_canonical = \wp_get_canonical_url( $draft_id );
				} finally {
					if ( ob_get_level() > $buffer_level ) {
						ob_end_clean();
					}
					\remove_filter( 'get_canonical_url', $canonical_filter, 10 );
					\remove_filter( 'get_shortlink', $shortlink_filter, 10 );
					if ( $query instanceof \WP_Query && null !== $query_vars ) {
						$query->query_vars = $query_vars;
					}
					if ( $page_exists ) {
						$GLOBALS['page'] = $page;
					} else {
						unset( $GLOBALS['page'] );
					}
				}

				self::collect_failure(
					$failures,
					is_string( $canonical ?? null )
						&& str_contains( $canonical, '?p=' . $post->ID )
						&& str_contains( $canonical, 'page=3' )
						&& str_contains( $canonical, 'cfz=' . rawurlencode( $marker ) )
						&& str_contains( $canonical_output ?? '', '<link rel="canonical" href="' . \esc_url( $canonical ) . '" />' )
						&& false === $draft_canonical,
					'canonical helpers include current page arguments, apply filters, escape rel output, and reject drafts',
					array(
						'canonical'       => $canonical ?? null,
						'canonicalOutput' => self::describe_string( is_string( $canonical_output ?? null ) ? $canonical_output : '' ),
						'draftCanonical'  => $draft_canonical ?? null,
					)
				);

				self::collect_failure(
					$failures,
					is_string( $shortlink_output ?? null )
						&& str_contains( $shortlink_output, "<link rel='shortlink' href='" )
						&& str_contains( $shortlink_output, '?p=' . $post->ID )
						&& str_contains( $shortlink_output, rawurlencode( $marker ) )
						&& ! str_contains( strtolower( $shortlink_output ), '<script' )
						&& 2 === count( $canonical_calls )
						&& 1 === count( $shortlink_calls )
						&& $post->ID === $canonical_calls[0]['post']
						&& 'query' === $shortlink_calls[0]['context']
						&& false === \has_filter( 'get_canonical_url', $canonical_filter, 10 )
						&& false === \has_filter( 'get_shortlink', $shortlink_filter, 10 ),
					'shortlink head output escapes filtered links and canonical/shortlink filters stay local',
					array(
						'shortlinkOutput' => self::describe_string( is_string( $shortlink_output ?? null ) ? $shortlink_output : '' ),
						'canonicalCalls'  => $canonical_calls,
						'shortlinkCalls'  => $shortlink_calls,
					)
				);
			}
		);

		wp_cache_delete( $draft_id, 'posts' );

		return self::row(
			$ctx,
			'template-links.links.canonical-and-shortlink-output',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_bookmark_fields_and_lists( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures  = array();
		$bookmarks = self::bookmark_cases( $ctx->fork( 'bookmarks' ) );

		foreach ( $bookmarks as $bookmark ) {
			self::$bookmarks[ (int) $bookmark->link_id ] = $bookmark;
			wp_cache_set( (int) $bookmark->link_id, clone $bookmark, 'bookmark' );
		}

		$first      = $bookmarks[0];
		$name_attr  = \get_bookmark_field( 'link_name', (int) $first->link_id, 'attribute' );
		$notes_js   = \get_bookmark_field( 'link_notes', (int) $first->link_id, 'js' );
		$missing    = \get_bookmark_field( 'missing_field', (int) $first->link_id, 'attribute' );
		$as_array   = \get_bookmark( (int) $first->link_id, ARRAY_A, 'raw' );
		$walk       = \_walk_bookmarks(
			$bookmarks,
			array(
				'show_images'      => 0,
				'show_description' => 1,
				'show_rating'      => 1,
				'show_name'        => 1,
			)
		);

		$list_args = array(
			'categorize'       => 0,
			'echo'             => 0,
			'show_images'      => 0,
			'show_description' => 1,
			'show_rating'      => 1,
			'show_name'        => 1,
			'title_li'         => 'Component Links',
			'class'            => 'cfz-links <bad> raw<script>',
			'category'         => '',
			'limit'            => -1,
			'orderby'          => 'name',
			'order'            => 'ASC',
			'hide_invisible'   => 0,
		);
		self::prime_bookmarks_cache( $list_args, $bookmarks );
		$list = \wp_list_bookmarks( $list_args );

		self::collect_failure(
			$failures,
			is_string( $name_attr )
				&& str_contains( $name_attr, '&lt;Name&gt;' )
				&& is_string( $notes_js )
				&& ! str_contains( strtolower( $notes_js ), '<script' )
				&& '' === $missing
				&& is_array( $as_array )
				&& $as_array['link_id'] === $first->link_id
				&& is_string( $walk )
				&& is_string( $list )
				&& str_contains( $list, 'Component Links' )
				&& str_contains( $list, $walk )
				&& ! str_contains( strtolower( $list ), 'javascript:' )
				&& ! str_contains( strtolower( $list ), '<script' )
				&& ! str_contains( $list, '<bad>' )
				&& str_contains( $list, '&lt;Name&gt;' )
				&& str_contains( $list, '&lt;Description&gt;' ),
			'bookmark field contexts and cached wp_list_bookmarks rendering escape names/descriptions and unsafe hrefs',
			array(
				'nameAttr' => $name_attr,
				'notesJs'  => self::describe_string( is_string( $notes_js ) ? $notes_js : '' ),
				'missing'  => $missing,
				'array'    => $as_array,
				'walk'     => self::describe_string( is_string( $walk ) ? $walk : '' ),
				'list'     => self::describe_string( is_string( $list ) ? $list : '' ),
			)
		);

		return self::row(
			$ctx,
			'template-links.bookmarks.fields-and-list-rendering',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_restoration_probe( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$failures = array();

		self::collect_failure(
			$failures,
			$snapshot['globals']['wp_filter']['value'] !== ( $GLOBALS['wp_filter'] ?? null )
				|| has_filter( 'pre_option_home', array( self::class, 'filter_option' ) )
				|| has_filter( 'wp_resource_hints', array( self::class, 'filter_resource_hints' ) ),
			'surface installed filters during execution and restoration probe has meaningful state to restore',
			array(
				'hasHomeFilter'     => has_filter( 'pre_option_home', array( self::class, 'filter_option' ) ),
				'hasResourceFilter' => has_filter( 'wp_resource_hints', array( self::class, 'filter_resource_hints' ) ),
			)
		);

		return self::row(
			$ctx,
			'template-links.state.restoration-probe',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function reset_runtime( \ComponentFuzz\FuzzContext $ctx ): void {
		self::$options = self::option_cases( $ctx );
		self::$posts   = array();

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['SERVER_NAME'] = 'example.test';
		$_SERVER['REQUEST_URI'] = '/component-fuzz/template-links/?q=initial';
		unset( $_SERVER['HTTPS'] );

		if ( ! get_post_type_object( 'post' ) || ! get_post_status_object( 'publish' ) ) {
			\create_initial_post_types();
		}
		\remove_post_type_support( 'post', 'post-formats' );

		$post = self::post_case( $ctx->fork( 'post' ) );
		self::$posts[ $post->ID ] = $post;
		wp_cache_set( $post->ID, (object) $post->to_array(), 'posts' );
		wp_cache_set( $post->ID, array(), 'post_meta' );

		$GLOBALS['post']         = $post;
		$GLOBALS['wp_query']     = self::query_for_post( $post, $ctx->int( 1, 3 ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['wp_rewrite']   = new \WP_Rewrite();
		$GLOBALS['paged']        = $GLOBALS['wp_query']->get( 'paged' );
		$GLOBALS['page']         = 1;
	}

	private static function option_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		unset( $ctx );

		return array(
			'admin_email'                => 'admin@example.test',
			'blog_charset'               => 'UTF-8',
			'blogdescription'            => 'No DB template/link fuzzing',
			'blogname'                   => 'Component Fuzz Links',
			'date_format'                => 'Y-m-d',
			'default_category'           => 1,
			'default_comments_page'      => 'oldest',
			'default_ping_status'        => 'closed',
			'default_post_format'        => '0',
			'gmt_offset'                 => 0,
			'home'                       => 'http://example.test',
			'html_type'                  => 'text/html',
			'links_updated_date_format'  => 'Y-m-d H:i',
			'page_for_posts'             => 0,
			'page_on_front'              => 0,
			'permalink_structure'        => '/%year%/%monthnum%/%postname%/',
			'show_on_front'              => 'posts',
			'site_icon'                  => 0,
			'siteurl'                    => 'http://example.test',
			'sticky_posts'               => array(),
			'stylesheet'                 => 'component-fuzz-theme',
			'template'                   => 'component-fuzz-theme',
			'thread_comments'            => 0,
			'wp_page_for_privacy_policy' => 0,
		);
	}

	private static function post_case( \ComponentFuzz\FuzzContext $ctx ): \WP_Post {
		$id         = 7000 + ( $ctx->seed() % 1000 );
		$title_text = self::safe_title_text( $ctx->text( 4, 40 ) );
		$slug       = \sanitize_title_with_dashes( $ctx->text( 4, 36 ), '', 'save' );
		if ( '' === $slug ) {
			$slug = 'component-fuzz-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		}

		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => 0,
				'post_date'             => '2026-06-22 10:20:30',
				'post_date_gmt'         => '2026-06-22 08:20:30',
				'post_content'          => 'Template/link content ' . $ctx->text( 0, 24 ),
				'post_title'            => 'Template Link ' . $title_text,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $slug,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-22 10:20:30',
				'post_modified_gmt'     => '2026-06-22 08:20:30',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://example.test/?p=' . $id,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	private static function query_for_post( \WP_Post $post, int $paged ): \WP_Query {
		$query = new \WP_Query();
		$query->init();

		$query->query_vars        = array(
			'p'      => $post->ID,
			'name'   => $post->post_name,
			'page'   => 1,
			'paged'  => $paged,
			's'      => '',
			'm'      => '',
			'year'   => '',
			'monthnum' => '',
			'day'    => '',
		);
		$query->queried_object    = $post;
		$query->queried_object_id = $post->ID;
		$query->post              = $post;
		$query->posts             = array( $post );
		$query->post_count        = 1;
		$query->found_posts       = 1;
		$query->max_num_pages     = 7;
		$query->is_single         = true;
		$query->is_singular       = true;
		$query->is_home           = false;
		$query->is_page           = false;
		$query->is_archive        = false;
		$query->is_search         = false;
		$query->is_404            = false;
		$query->is_paged          = $paged > 1;

		return $query;
	}

	private static function bookmark_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$first_name = 'Unsafe <Name> & "' . self::safe_title_text( $ctx->text( 0, 18 ) );

		return array(
			(object) array(
				'link_id'          => 9101,
				'link_url'         => 'javascript:alert(1)',
				'link_name'        => $first_name,
				'link_image'       => '',
				'link_target'      => '_blank',
				'link_category'    => array( 1, 'bad' ),
				'link_description' => 'Unsafe <Description> & "quote"',
				'link_visible'     => 'Y<script>',
				'link_owner'       => 0,
				'link_rating'      => 7,
				'link_updated'     => '2026-06-22 08:00:00',
				'link_updated_f'   => 0,
				'link_rel'         => 'noopener external',
				'link_notes'       => "note <script>alert(1)</script>\n" . $ctx->text( 0, 12 ),
				'link_rss'         => 'https://example.test/feed',
				'recently_updated' => false,
			),
			(object) array(
				'link_id'          => 9102,
				'link_url'         => 'https://example.test/bookmark?q=' . rawurlencode( $ctx->text( 0, 18 ) ),
				'link_name'        => 'Safe bookmark ' . self::safe_title_text( $ctx->text( 0, 12 ) ),
				'link_image'       => '',
				'link_target'      => '',
				'link_category'    => array( 1 ),
				'link_description' => 'Second description',
				'link_visible'     => 'Y',
				'link_owner'       => 0,
				'link_rating'      => 3,
				'link_updated'     => '2026-06-22 09:00:00',
				'link_updated_f'   => 0,
				'link_rel'         => 'friend',
				'link_notes'       => '',
				'link_rss'         => '',
				'recently_updated' => false,
			),
		);
	}

	private static function prime_bookmarks_cache( array $args, array $bookmarks ): void {
		$wp_list_defaults = array(
			'orderby'          => 'name',
			'order'            => 'ASC',
			'limit'            => -1,
			'category'         => '',
			'exclude_category' => '',
			'category_name'    => '',
			'hide_invisible'   => 1,
			'show_updated'     => 0,
			'echo'             => 1,
			'categorize'       => 1,
			'title_li'         => __( 'Bookmarks' ),
			'title_before'     => '<h2>',
			'title_after'      => '</h2>',
			'category_orderby' => 'name',
			'category_order'   => 'ASC',
			'class'            => 'linkcat',
			'category_before'  => '<li id="%id" class="%class">',
			'category_after'   => '</li>',
		);
		$bookmark_defaults = array(
			'orderby'        => 'name',
			'order'          => 'ASC',
			'limit'          => -1,
			'category'       => '',
			'category_name'  => '',
			'hide_invisible' => 1,
			'show_updated'   => 0,
			'include'        => '',
			'exclude'        => '',
			'search'         => '',
		);

		$parsed_args = wp_parse_args( $args, $wp_list_defaults );
		if ( ! is_array( $parsed_args['class'] ) ) {
			$parsed_args['class'] = explode( ' ', $parsed_args['class'] );
		}
		$parsed_args['class'] = array_map( 'sanitize_html_class', $parsed_args['class'] );
		$parsed_args['class'] = trim( implode( ' ', $parsed_args['class'] ) );

		$get_bookmarks_args = wp_parse_args( $parsed_args, $bookmark_defaults );
		$key                = md5( serialize( $get_bookmarks_args ) );
		wp_cache_set( 'get_bookmarks', array( $key => $bookmarks ), 'bookmark' );
	}

	private static function current_post(): \WP_Post {
		$post = $GLOBALS['post'] ?? null;
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( 'Synthetic post is unavailable.' );
		}

		return $post;
	}

	private static function with_permalink_structure( string $structure, callable $callback ): void {
		$previous = self::$options['permalink_structure'] ?? '';
		$rewrite  = $GLOBALS['wp_rewrite'] ?? null;

		self::$options['permalink_structure'] = $structure;
		$GLOBALS['wp_rewrite']                = new \WP_Rewrite();

		try {
			$callback();
		} finally {
			self::$options['permalink_structure'] = $previous;
			if ( null === $rewrite ) {
				unset( $GLOBALS['wp_rewrite'] );
			} else {
				$GLOBALS['wp_rewrite'] = $rewrite;
			}
		}
	}

	private static function safe_title_text( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[^\x20-\x7E]/', '', $value ) ?? '';
		$value = str_replace( array( '&', '"', "'", '<', '>' ), '', $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );

		return '' === $value ? 'case' : $value;
	}

	private static function all_builtin_class_tokens_are_sane( array $classes ): bool {
		foreach ( $classes as $class ) {
			if ( str_starts_with( $class, 'single-' )
				|| str_starts_with( $class, 'postid-' )
				|| str_starts_with( $class, 'wp-theme-' )
				|| in_array( $class, array( 'wp-singular', 'single', 'paged' ), true )
			) {
				if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $class ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function contains_raw_dangerous_html( string $value ): bool {
		return str_contains( strtolower( $value ), '<script' )
			|| str_contains( $value, '<' )
			|| str_contains( $value, ' onerror=' )
			|| str_contains( $value, ' onclick=' );
	}

	private static function is_http_or_relative_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		return str_starts_with( $url, 'http://example.test' )
			|| str_starts_with( $url, 'https://example.test' )
			|| str_starts_with( $url, 'http://cdn.example.test' )
			|| str_starts_with( $url, 'https://cdn.example.test' )
			|| str_starts_with( $url, '/' );
	}

	private static function all_urls_are_http_or_relative( array $urls ): bool {
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || ! self::is_http_or_relative_url( $url ) ) {
				return false;
			}
		}

		return true;
	}

	private static function install_filters(): void {
		foreach ( self::option_filter_names() as $option ) {
			add_filter( "pre_option_{$option}", array( self::class, 'filter_option' ), 10, 3 );
		}

		add_filter( 'user_has_cap', array( self::class, 'filter_user_has_cap' ), 10, 2 );
		add_filter( 'get_post_metadata', array( self::class, 'filter_post_metadata' ), 10, 4 );
		add_filter( 'update_post_metadata_cache', array( self::class, 'filter_update_post_metadata_cache' ), 10, 2 );
		add_filter( 'post_class_taxonomies', array( self::class, 'filter_post_class_taxonomies' ), 10, 0 );
		add_filter( 'wp_resource_hints', array( self::class, 'filter_resource_hints' ), 10, 2 );
		add_filter( 'wp_preload_resources', array( self::class, 'filter_preload_resources' ), 10, 0 );
	}

	private static function remove_filters(): void {
		foreach ( self::option_filter_names() as $option ) {
			remove_filter( "pre_option_{$option}", array( self::class, 'filter_option' ), 10 );
		}

		remove_filter( 'user_has_cap', array( self::class, 'filter_user_has_cap' ), 10 );
		remove_filter( 'get_post_metadata', array( self::class, 'filter_post_metadata' ), 10 );
		remove_filter( 'update_post_metadata_cache', array( self::class, 'filter_update_post_metadata_cache' ), 10 );
		remove_filter( 'post_class_taxonomies', array( self::class, 'filter_post_class_taxonomies' ), 10 );
		remove_filter( 'wp_resource_hints', array( self::class, 'filter_resource_hints' ), 10 );
		remove_filter( 'wp_preload_resources', array( self::class, 'filter_preload_resources' ), 10 );
	}

	private static function option_filter_names(): array {
		return array(
			'admin_email',
			'blog_charset',
			'blogdescription',
			'blogname',
			'date_format',
			'default_category',
			'default_comments_page',
			'default_ping_status',
			'default_post_format',
			'gmt_offset',
			'home',
			'html_type',
			'links_updated_date_format',
			'page_for_posts',
			'page_on_front',
			'permalink_structure',
			'show_on_front',
			'site_icon',
			'siteurl',
			'sticky_posts',
			'stylesheet',
			'template',
			'thread_comments',
			'wp_page_for_privacy_policy',
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => self::describe_value( $data ),
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

	private static function snapshot_state(): array {
		$globals = array(
			'_wp_post_type_features',
			'page',
			'paged',
			'post',
			'wp_actions',
			'wp_current_filter',
			'wp_filter',
			'wp_filters',
			'wp_object_cache',
			'wp_post_statuses',
			'wp_post_types',
			'wp_query',
			'wp_rewrite',
			'wp_scripts',
			'wp_styles',
			'wp_the_query',
		);
		$snapshot = array(
			'globals'   => array(),
			'server'    => array(),
			'options'   => self::$options,
			'posts'     => self::$posts,
			'bookmarks' => self::$bookmarks,
		);

		foreach ( $globals as $name ) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		foreach ( array( 'HTTP_HOST', 'HTTPS', 'REQUEST_URI', 'SERVER_NAME' ) as $key ) {
			$snapshot['server'][ $key ] = array(
				'exists' => array_key_exists( $key, $_SERVER ),
				'value'  => $_SERVER[ $key ] ?? null,
			);
		}

		return $snapshot;
	}

	private static function restore_state( array $snapshot ): void {
		self::remove_filters();

		self::$options   = $snapshot['options'];
		self::$posts     = $snapshot['posts'];
		self::$bookmarks = $snapshot['bookmarks'];

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['server'] as $key => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $key ] = $entry['value'];
			} else {
				unset( $_SERVER[ $key ] );
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

			if ( $value instanceof \WP_Post ) {
				return array(
					'type'       => 'object',
					'class'      => 'WP_Post',
					'ID'         => $value->ID,
					'post_type'  => $value->post_type,
					'post_name'  => $value->post_name,
					'post_title' => self::describe_string( $value->post_title ),
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
}
