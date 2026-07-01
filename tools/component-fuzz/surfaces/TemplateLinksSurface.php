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
			$rows[] = self::check_post_class_container_contracts( $ctx->fork( 'post-class' ) );
			$rows[] = self::check_body_language_filter_ordering( $ctx );
			$rows[] = self::check_document_title_helpers( $ctx );
			$rows[] = self::check_resource_hints_and_preloads( $ctx );
			$rows[] = self::check_page_and_paginate_links( $ctx );
			$rows[] = self::check_archive_navigation_wrappers( $ctx->fork( 'archive-navigation' ) );
			$rows[] = self::check_search_feed_site_and_admin_links( $ctx );
			$rows[] = self::check_search_form_rendering_contracts( $ctx->fork( 'search-form' ) );
			$rows[] = self::check_post_link_helpers( $ctx );
			$rows[] = self::check_archive_link_helpers( $ctx );
			$rows[] = self::check_adjacent_post_link_helpers( $ctx );
			$rows[] = self::check_adjacent_image_link_helpers( $ctx->fork( 'adjacent-image-links' ) );
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
				'add_action',
				'add_theme_support',
				'add_filter',
				'body_class',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_theme_supports',
				'esc_attr',
				'esc_attr_x',
				'esc_url',
				'get_admin_url',
				'get_attachment_link',
				'get_adjacent_post_rel_link',
				'get_adjacent_image_link',
				'get_author_posts_url',
				'get_body_class',
				'get_bookmark',
				'get_bookmark_field',
				'get_children',
				'get_day_link',
				'get_edit_post_link',
				'get_delete_post_link',
				'get_feed_link',
				'get_home_url',
				'get_language_attributes',
				'get_month_link',
				'get_next_image_link',
				'get_next_posts_link',
				'get_next_posts_page_link',
				'get_next_post_link',
				'get_pagenum_link',
				'get_permalink',
				'get_preview_post_link',
				'get_post_class',
				'get_posts_nav_link',
				'get_previous_image_link',
				'get_previous_posts_link',
				'get_previous_posts_page_link',
				'get_previous_post_link',
				'get_query_var',
				'get_search_feed_link',
				'get_search_form',
				'get_search_link',
				'get_search_query',
				'get_site_url',
				'get_the_posts_navigation',
				'get_the_posts_pagination',
				'get_the_title',
				'get_year_link',
				'has_filter',
				'home_url',
				'is_wp_error',
				'language_attributes',
				'locate_template',
				'adjacent_posts_rel_link',
				'adjacent_posts_rel_link_wp_head',
				'adjacent_image_link',
				'_navigation_markup',
				'next_posts',
				'next_posts_link',
				'next_post_rel_link',
				'next_image_link',
				'paginate_links',
				'post_class',
				'posts_nav_link',
				'prev_post_rel_link',
				'previous_posts',
				'previous_posts_link',
				'previous_image_link',
				'register_taxonomy',
				'rel_canonical',
				'remove_action',
				'remove_filter',
				'remove_post_type_support',
				'sanitize_html_class',
				'sanitize_title_with_dashes',
				'taxonomy_exists',
				'unregister_taxonomy',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
				'wp_check_invalid_utf8',
				'wp_get_canonical_url',
				'wp_get_document_title',
				'wp_get_attachment_link',
				'wp_get_attachment_image',
				'wp_get_attachment_image_src',
				'wp_get_attachment_url',
				'wp_get_shortlink',
				'wp_list_bookmarks',
				'wp_preload_resources',
				'wp_resource_hints',
				'wp_shortlink_wp_head',
				'wp_title',
				'the_posts_navigation',
				'the_posts_pagination',
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

	private static function check_post_class_container_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures          = array();
		$marker            = strtolower( $ctx->identifier( 4, 10 ) );
		$current_post      = self::current_post();
		$post_id           = $current_post->ID + 41000 + $ctx->int( 10, 999 );
		$post_case         = self::post_case( $ctx->fork( 'post' ) );
		$post              = new \WP_Post(
			(object) array_merge(
				$post_case->to_array(),
				array(
					'ID'        => $post_id,
					'post_name' => 'post-class-' . $marker,
					'guid'      => 'http://example.test/?p=' . $post_id,
				)
			)
		);
		$password_post     = new \WP_Post(
			(object) array_merge(
				$post->to_array(),
				array(
					'ID'            => $post->ID + 37,
					'post_name'     => 'post-class-password-' . $marker,
					'guid'          => 'http://example.test/?p=' . ( $post->ID + 37 ),
					'post_password' => 'component-fuzz-pass-' . $ctx->identifier( 3, 8 ),
				)
			)
		);
		$custom_taxonomy   = 'cfz_pc_' . substr( preg_replace( '/[^a-z0-9_]/', '', $marker ) ?? '', 0, 20 );
		$custom_taxonomy   = substr( $custom_taxonomy, 0, 32 );
		$custom_classes    = "cfz-user-{$marker} raw<script>{$marker} duplicate-class duplicate-class spaced\t{$marker}";
		$filter_raw_class  = 'cfz-filter<unsafe>"' . $marker;
		$taxonomy_events   = array();
		$post_class_events = array();
		$global_snapshot   = self::snapshot_globals( array( 'page', 'paged', 'post', 'wp_query', 'wp_the_query' ) );
		$options_snapshot  = self::$options;
		$posts_snapshot    = self::$posts;
		$buffer_level      = ob_get_level();
		$cookie_snapshot   = $_COOKIE;
		$theme_exists      = array_key_exists( '_wp_theme_features', $GLOBALS );
		$theme_features    = $theme_exists ? self::clone_value( $GLOBALS['_wp_theme_features'] ) : null;
		$registered        = false;

		$taxonomy_filter = static function ( array $taxonomies, int $post_id, array $classes, array $css_class ) use (
			&$taxonomy_events,
			$post,
			$custom_classes,
			$custom_taxonomy
		): array {
			$taxonomy_events[] = array(
				'postId'                 => $post_id,
				'sawBasePostClass'       => in_array( 'post-' . $post->ID, $classes, true ),
				'sawRawCustomArgument'   => preg_split( '#\s+#', $custom_classes ) === $css_class,
				'originalTaxonomySample' => array_slice( $taxonomies, 0, 5 ),
			);

			if ( $post_id !== $post->ID ) {
				return array();
			}

			return array( 'category', 'post_tag', $custom_taxonomy );
		};

		$post_class_filter = static function ( array $classes, array $css_class, int $post_id ) use (
			&$post_class_events,
			$post,
			$custom_classes,
			$marker,
			$filter_raw_class
		): array {
			$post_class_events[] = array(
				'postId'                  => $post_id,
				'sawHentry'               => in_array( 'hentry', $classes, true ),
				'sawEscapedCustomClass'   => in_array( \esc_attr( 'raw<script>' . $marker ), $classes, true ),
				'sawRawCustomArgument'    => preg_split( '#\s+#', $custom_classes ) === $css_class,
				'isPrimaryPost'           => $post_id === $post->ID,
			);
			$classes[]            = $filter_raw_class;
			$classes[]            = 'hentry';
			return $classes;
		};

		\add_filter( 'post_class_taxonomies', $taxonomy_filter, 20, 4 );
		\add_filter( 'post_class', $post_class_filter, 10, 3 );

		try {
			self::seed_post_storage( $post );
			self::seed_post_storage( $password_post );
			self::set_current_post_query( $post, 0, true );

			$taxonomy_result = \register_taxonomy(
				$custom_taxonomy,
				'post',
				array(
					'public'    => true,
					'query_var' => false,
					'rewrite'   => false,
				)
			);
			$registered      = ! \is_wp_error( $taxonomy_result );

			self::prime_post_class_terms(
				$post->ID,
				array(
					'category'        => array(
						self::post_class_term( 7101, 'category', 'category-' . $marker ),
					),
					'post_tag'        => array(
						self::post_class_term( 7102, 'post_tag', 'tag-' . $marker ),
					),
					$custom_taxonomy  => array(
						self::post_class_term( 7103, $custom_taxonomy, '12345' ),
						self::post_class_term( 7104, $custom_taxonomy, '---' ),
					),
				)
			);

			\wp_cache_set( $post->ID, array( '_thumbnail_id' => array( 8801 ) ), 'post_meta' );
			\add_theme_support( 'post-thumbnails' );
			self::$options['sticky_posts'] = array( $post->ID );

			$classes = \get_post_class( $custom_classes, $post );

			ob_start();
			\post_class( $custom_classes, $post );
			$post_class_output = (string) ob_get_clean();

			$category_token      = self::post_class_term_token( 'category', 'category-' . $marker, 7101 );
			$tag_token           = self::post_class_term_token( 'post_tag', 'tag-' . $marker, 7102 );
			$custom_number_token = self::post_class_term_token( $custom_taxonomy, '12345', 7103 );
			$custom_hyphen_token = self::post_class_term_token( $custom_taxonomy, '---', 7104 );
			$expected_output     = 'class="' . \esc_attr( implode( ' ', $classes ) ) . '"';
			$expected_tokens     = array(
				'post-' . $post->ID,
				'post',
				'type-post',
				'status-' . $post->post_status,
				'hentry',
				'sticky',
				'has-post-thumbnail',
				$category_token,
				$tag_token,
				$custom_number_token,
				$custom_hyphen_token,
				$filter_raw_class,
			);
			$whitespace_tokens  = array_values(
				array_filter(
					$classes,
					static fn( $class ): bool => is_string( $class ) && 1 === preg_match( '/\s/', $class )
				)
			);
			$missing_tokens     = array_values( array_diff( $expected_tokens, $classes ) );
			$output_matches     = $expected_output === $post_class_output;

			self::collect_failure(
				$failures,
				$registered
					&& $output_matches
					&& array() === $missing_tokens
					&& count( $classes ) === count( array_unique( $classes ) )
					&& array() === $whitespace_tokens
					&& in_array( \esc_attr( 'raw<script>' . $marker ), $classes, true )
					&& ! str_contains( $post_class_output, $filter_raw_class )
					&& str_contains( $post_class_output, \esc_attr( $filter_raw_class ) ),
				'post_class echoes the escaped get_post_class token stream while preserving filter-returned raw tokens for final escaping',
				array(
					'classes'          => $classes,
					'expectedOutput'   => $expected_output,
					'actualOutput'     => $post_class_output,
					'expectedTokens'    => $expected_tokens,
					'whitespaceTokens'  => $whitespace_tokens,
					'taxonomyRegistered' => $registered,
				)
			);

			self::set_current_post_query( $post, 2, true );
			$paged_classes = \get_post_class( array(), $post );
			self::collect_failure(
				$failures,
				! in_array( 'sticky', $paged_classes, true ),
				'sticky class is withheld for paged home queries',
				array( 'classes' => $paged_classes )
			);

			self::set_current_post_query( $post, 0, false );
			$non_home_classes = \get_post_class( array(), $post );
			self::collect_failure(
				$failures,
				! in_array( 'sticky', $non_home_classes, true ),
				'sticky class is withheld outside the home query',
				array( 'classes' => $non_home_classes )
			);

			$_COOKIE = $cookie_snapshot;
			if ( defined( 'COOKIEHASH' ) ) {
				unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
			}
			self::set_current_post_query( $password_post, 0, true );
			\wp_cache_set( $password_post->ID, array( '_thumbnail_id' => array( 8802 ) ), 'post_meta' );
			$password_classes = \get_post_class( array(), $password_post );
			self::collect_failure(
				$failures,
				in_array( 'post-password-required', $password_classes, true )
					&& ! in_array( 'post-password-protected', $password_classes, true )
					&& ! in_array( 'has-post-thumbnail', $password_classes, true ),
				'password-required posts use the required token and suppress protected/thumbnail tokens',
				array( 'classes' => $password_classes )
			);

			$primary_taxonomy_events = array_values(
				array_filter(
					$taxonomy_events,
					static fn( array $event ): bool => $event['postId'] === $post->ID && $event['sawRawCustomArgument']
				)
			);
			$primary_post_events     = array_values(
				array_filter(
					$post_class_events,
					static fn( array $event ): bool => $event['isPrimaryPost'] && $event['sawRawCustomArgument']
				)
			);
			self::collect_failure(
				$failures,
				count( $primary_taxonomy_events ) >= 2
					&& count( $primary_post_events ) >= 2
					&& ! in_array( false, array_column( $primary_taxonomy_events, 'sawBasePostClass' ), true )
					&& ! in_array( false, array_column( $primary_post_events, 'sawHentry' ), true )
					&& ! in_array( false, array_column( $primary_post_events, 'sawEscapedCustomClass' ), true ),
				'post_class_taxonomies and post_class filters receive expected post id, classes, and raw css arguments',
				array(
					'taxonomyEvents' => $taxonomy_events,
					'postEvents'     => $post_class_events,
				)
			);
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			\remove_filter( 'post_class_taxonomies', $taxonomy_filter, 20 );
			\remove_filter( 'post_class', $post_class_filter, 10 );
			if ( $registered && \taxonomy_exists( $custom_taxonomy ) ) {
				\unregister_taxonomy( $custom_taxonomy );
			}
			if ( $theme_exists ) {
				$GLOBALS['_wp_theme_features'] = $theme_features;
			} else {
				unset( $GLOBALS['_wp_theme_features'] );
			}
			$_COOKIE = $cookie_snapshot;
			self::$options['sticky_posts'] = array();
			\wp_cache_delete( $post->ID, 'posts' );
			\wp_cache_delete( $post->ID, 'post_meta' );
			\wp_cache_delete( $password_post->ID, 'posts' );
			\wp_cache_delete( $password_post->ID, 'post_meta' );
			foreach ( array( 'category', 'post_tag', $custom_taxonomy ) as $taxonomy ) {
				\wp_cache_delete( $post->ID, "{$taxonomy}_relationships" );
			}
			foreach ( array( 7101, 7102, 7103, 7104 ) as $term_id ) {
				\wp_cache_delete( $term_id, 'terms' );
			}
			self::delete_post_storage( $post->ID );
			self::delete_post_storage( $password_post->ID );
			self::$options = $options_snapshot;
			self::$posts   = $posts_snapshot;
			self::restore_globals( $global_snapshot );
		}

		return self::row(
			$ctx,
			'template-links.template.post-class-container-contracts',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_body_language_filter_ordering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$post            = self::current_post();
		$marker          = strtolower( $ctx->identifier( 4, 10 ) );
		$custom_classes  = array(
			'cfz-custom-' . $marker,
			'raw<script>' . $marker,
		);
		$early_body      = 'cfz-early-' . $marker;
		$late_body       = 'cfz-late-' . $marker;
		$unsafe_body     = 'cfz-unsafe<late>' . $marker;
		$body_events     = array();
		$language_events = array();

		$body_filter_early = static function ( array $classes, array $css_class ) use (
			&$body_events,
			$custom_classes,
			$early_body
		): array {
			$body_events[] = array(
				'phase'                 => 'early',
				'sawRawCustomArgument'  => $custom_classes === $css_class,
				'sawEscapedCustomClass' => in_array( \esc_attr( $custom_classes[1] ), $classes, true ),
			);
			$classes[]      = $early_body;
			$classes[]      = $early_body;
			return $classes;
		};

		$body_filter_late = static function ( array $classes, array $css_class ) use (
			&$body_events,
			$custom_classes,
			$early_body,
			$late_body,
			$unsafe_body
		): array {
			$body_events[] = array(
				'phase'                => 'late',
				'sawEarlyToken'        => in_array( $early_body, $classes, true ),
				'sawRawCustomArgument' => $custom_classes === $css_class,
			);
			$classes[]      = $late_body;
			$classes[]      = $unsafe_body;
			return $classes;
		};

		$language_filter_early = static function ( string $output, string $doctype ) use (
			&$language_events,
			$marker
		): string {
			$language_events[] = 'early:' . $doctype;
			return $output . ' data-cfz-early="' . \esc_attr( $marker . '<early>' ) . '"';
		};

		$language_filter_late = static function ( string $output, string $doctype ) use (
			&$language_events,
			$marker
		): string {
			$language_events[] = 'late:' . $doctype . ':' . ( str_contains( $output, 'data-cfz-early=' ) ? 'saw-early' : 'missing-early' );
			return $output . ' data-cfz-late="' . \esc_attr( $doctype . '-' . $marker . '<late>' ) . '"';
		};

		$buffer_level = ob_get_level();
		$body_classes = array();
		$body_output  = '';
		$html         = '';
		$html_echo    = '';
		$xhtml        = '';

		\add_filter( 'body_class', $body_filter_early, 5, 2 );
		\add_filter( 'body_class', $body_filter_late, 50, 2 );
		\add_filter( 'language_attributes', $language_filter_early, 5, 2 );
		\add_filter( 'language_attributes', $language_filter_late, 50, 2 );

		try {
			$body_classes = \get_body_class( $custom_classes );

			ob_start();
			\body_class( $custom_classes );
			$body_output = (string) ob_get_clean();

			$html = \get_language_attributes( 'html' );

			ob_start();
			\language_attributes( 'html' );
			$html_echo = (string) ob_get_clean();

			$xhtml = \get_language_attributes( 'xhtml' );
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			\remove_filter( 'body_class', $body_filter_early, 5 );
			\remove_filter( 'body_class', $body_filter_late, 50 );
			\remove_filter( 'language_attributes', $language_filter_early, 5 );
			\remove_filter( 'language_attributes', $language_filter_late, 50 );
		}

		$early_index = array_search( $early_body, $body_classes, true );
		$late_index  = array_search( $late_body, $body_classes, true );

		self::collect_failure(
			$failures,
			is_int( $early_index )
				&& is_int( $late_index )
				&& $early_index < $late_index
				&& 1 === count( array_keys( $body_classes, $early_body, true ) )
				&& in_array( \esc_attr( $custom_classes[1] ), $body_classes, true )
				&& in_array( $unsafe_body, $body_classes, true )
				&& $body_output === 'class="' . \esc_attr( implode( ' ', $body_classes ) ) . '"'
				&& ! self::contains_raw_dangerous_html( $body_output )
				&& array(
					'early',
					'late',
					'early',
					'late',
				) === array_column( $body_events, 'phase' )
				&& false === \has_filter( 'body_class', $body_filter_early, 5 )
				&& false === \has_filter( 'body_class', $body_filter_late, 50 ),
			'body class filters receive raw custom args, run by priority, deduplicate filtered tokens, and echo escaped output',
			array(
				'postId'      => $post->ID,
				'classes'     => $body_classes,
				'bodyOutput'  => $body_output,
				'bodyEvents'  => $body_events,
				'earlyIndex'  => $early_index,
				'lateIndex'   => $late_index,
				'unsafeToken' => $unsafe_body,
			)
		);

		self::collect_failure(
			$failures,
			$html === $html_echo
				&& str_contains( $html, 'lang="en-US"' )
				&& str_contains( $xhtml, 'xml:lang="en-US"' )
				&& str_contains( $html, 'data-cfz-early="' . \esc_attr( $marker . '<early>' ) . '"' )
				&& str_contains( $html, 'data-cfz-late="' . \esc_attr( 'html-' . $marker . '<late>' ) . '"' )
				&& str_contains( $xhtml, 'data-cfz-late="' . \esc_attr( 'xhtml-' . $marker . '<late>' ) . '"' )
				&& array(
					'early:html',
					'late:html:saw-early',
					'early:html',
					'late:html:saw-early',
					'early:xhtml',
					'late:xhtml:saw-early',
				) === $language_events
				&& ! self::contains_raw_dangerous_html( $html . $xhtml )
				&& false === \has_filter( 'language_attributes', $language_filter_early, 5 )
				&& false === \has_filter( 'language_attributes', $language_filter_late, 50 ),
			'language attribute filters receive doctype, run by priority, preserve echo/getter parity, and stay local',
			array(
				'html'           => $html,
				'htmlEcho'       => $html_echo,
				'xhtml'          => $xhtml,
				'languageEvents' => $language_events,
			)
		);

		return self::row(
			$ctx,
			'template-links.template.body-language-filter-ordering',
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

	private static function check_archive_navigation_wrappers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$marker         = 'cfz-nav-' . strtolower( $ctx->identifier( 4, 10 ) );
		$global_snapshot = self::snapshot_globals( array( 'page', 'paged', 'post', 'wp_query', 'wp_the_query' ) );
		$request_exists = array_key_exists( 'REQUEST_URI', $_SERVER );
		$request_uri    = $_SERVER['REQUEST_URI'] ?? null;
		$buffer_level   = ob_get_level();

		$next_attr_events  = array();
		$prev_attr_events  = array();
		$pagination_events = array();
		$template_events   = array();

		$next_attr_filter = static function ( string $attributes ) use ( &$next_attr_events, $marker ): string {
			$next_attr_events[] = $attributes;
			return trim( $attributes . ' data-cfz-next="' . \esc_attr( $marker ) . '"' );
		};
		$prev_attr_filter = static function ( string $attributes ) use ( &$prev_attr_events, $marker ): string {
			$prev_attr_events[] = $attributes;
			return trim( $attributes . ' data-cfz-prev="' . \esc_attr( $marker ) . '"' );
		};
		$pagination_args_filter = static function ( array $args ) use ( &$pagination_events, $marker ): array {
			$pagination_events[] = array(
				'class'            => $args['class'] ?? null,
				'midSize'          => $args['mid_size'] ?? null,
				'screenReaderText' => $args['screen_reader_text'] ?? null,
				'ariaLabel'        => $args['aria_label'] ?? null,
				'type'             => $args['type'] ?? null,
			);

			$args['mid_size']           = 0;
			$args['type']               = 'array';
			$args['class']              = 'pagination ' . $marker . ' <script>';
			$args['screen_reader_text'] = 'Pages <reader> ' . $marker;
			$args['aria_label']         = 'Archive "pages" <' . $marker . '>';

			return $args;
		};
		$navigation_template_filter = static function ( string $template, string $css_class ) use ( &$template_events, $marker ): string {
			$template_events[] = array(
				'template' => self::describe_string( $template ),
				'cssClass' => $css_class,
			);

			return "\n<nav class=\"navigation %1\$s\" aria-label=\"%4\$s\" data-cfz-template=\"" . \esc_attr( $marker ) . "\">\n\t<h2 class=\"screen-reader-text\">%2\$s</h2>\n\t<div class=\"nav-links\">%3\$s</div>\n</nav>";
		};

		$middle_next_page      = '';
		$middle_next_page_echo = '';
		$middle_prev_page      = '';
		$middle_prev_page_echo = '';
		$next_link            = '';
		$next_link_echo       = '';
		$previous_link        = '';
		$previous_link_echo   = '';
		$posts_nav            = '';
		$posts_nav_echo       = '';
		$posts_navigation     = '';
		$posts_navigation_echo = '';
		$pagination           = '';
		$pagination_echo      = '';
		$first_navigation     = '';
		$first_posts_nav      = '';
		$last_navigation      = '';
		$last_posts_nav       = '';
		$single_pagination    = '';
		$single_navigation    = '';
		$singular_next_link   = null;
		$singular_prev_link   = null;
		$singular_posts_nav   = '';
		$direct_markup        = '';
		$direct_css_class     = 'archive nav <script> ' . $marker;

		\add_filter( 'next_posts_link_attributes', $next_attr_filter, 10, 1 );
		\add_filter( 'previous_posts_link_attributes', $prev_attr_filter, 10, 1 );
		\add_filter( 'the_posts_pagination_args', $pagination_args_filter, 10, 1 );
		\add_filter( 'navigation_markup_template', $navigation_template_filter, 10, 2 );

		try {
			self::with_permalink_structure(
				'',
				static function () use (
					$marker,
					&$middle_next_page,
					&$middle_next_page_echo,
					&$middle_prev_page,
					&$middle_prev_page_echo,
					&$next_link,
					&$next_link_echo,
					&$previous_link,
					&$previous_link_echo,
					&$posts_nav,
					&$posts_nav_echo,
					&$posts_navigation,
					&$posts_navigation_echo,
					&$pagination,
					&$pagination_echo,
					&$first_navigation,
					&$first_posts_nav,
					&$last_navigation,
					&$last_posts_nav,
					&$single_pagination,
					&$single_navigation,
					&$singular_next_link,
					&$singular_prev_link,
					&$singular_posts_nav,
					&$direct_markup,
					$direct_css_class
				): void {
					$_SERVER['REQUEST_URI'] = '/archive/?paged=3&keep=' . rawurlencode( $marker ) . '&unsafe=<script>#ignored';
					self::set_archive_query( 3, 5 );

					$middle_next_page      = (string) \next_posts( 5, false );
					$middle_next_page_echo = self::capture_output(
						static function (): void {
							\next_posts( 5, true );
						}
					);
					$middle_prev_page      = (string) \previous_posts( false );
					$middle_prev_page_echo = self::capture_output(
						static function (): void {
							\previous_posts( true );
						}
					);

					$next_label      = 'Older & Archive ' . $marker;
					$previous_label  = 'Newer & Archive ' . $marker;
					$next_link       = (string) \get_next_posts_link( $next_label, 5 );
					$next_link_echo  = self::capture_output(
						static function () use ( $next_label ): void {
							\next_posts_link( $next_label, 5 );
						}
					);
					$previous_link   = (string) \get_previous_posts_link( $previous_label );
					$previous_link_echo = self::capture_output(
						static function () use ( $previous_label ): void {
							\previous_posts_link( $previous_label );
						}
					);

					$separator      = ' | ' . $marker . ' | ';
					$posts_nav      = (string) \get_posts_nav_link(
						array(
							'sep'      => $separator,
							'prelabel' => $previous_label,
							'nxtlabel' => $next_label,
						)
					);
					$posts_nav_echo = self::capture_output(
						static function () use ( $separator, $previous_label, $next_label ): void {
							\posts_nav_link( $separator, $previous_label, $next_label );
						}
					);

					$navigation_args       = array(
						'prev_text'          => $next_label,
						'next_text'          => $previous_label,
						'screen_reader_text' => 'Posts <navigation> ' . $marker,
						'aria_label'         => 'Archive navigation "' . $marker . '"',
						'class'              => 'posts nav <script> ' . $marker,
					);
					$posts_navigation      = (string) \get_the_posts_navigation( $navigation_args );
					$posts_navigation_echo = self::capture_output(
						static function () use ( $navigation_args ): void {
							\the_posts_navigation( $navigation_args );
						}
					);

					$pagination      = (string) \get_the_posts_pagination(
						array(
							'prev_text' => 'Back ' . $marker,
							'next_text' => 'Forward ' . $marker,
						)
					);
					$pagination_echo = self::capture_output(
						static function () use ( $marker ): void {
							\the_posts_pagination(
								array(
									'prev_text' => 'Back ' . $marker,
									'next_text' => 'Forward ' . $marker,
								)
							);
						}
					);

					self::set_archive_query( 1, 5 );
					$first_navigation = (string) \get_the_posts_navigation( $navigation_args );
					$first_posts_nav  = (string) \get_posts_nav_link(
						array(
							'sep'      => $separator,
							'prelabel' => $previous_label,
							'nxtlabel' => $next_label,
						)
					);

					self::set_archive_query( 5, 5 );
					$last_navigation = (string) \get_the_posts_navigation( $navigation_args );
					$last_posts_nav  = (string) \get_posts_nav_link(
						array(
							'sep'      => $separator,
							'prelabel' => $previous_label,
							'nxtlabel' => $next_label,
						)
					);

					self::set_archive_query( 1, 1 );
					$single_pagination = \get_the_posts_pagination();
					$single_navigation = (string) \get_the_posts_navigation( $navigation_args );

					self::set_current_post_query( self::current_post(), 2, false );
					$singular_next_link = \get_next_posts_link( $next_label, 5 );
					$singular_prev_link = \get_previous_posts_link( $previous_label );
					$singular_posts_nav = (string) \get_posts_nav_link(
						array(
							'sep'      => $separator,
							'prelabel' => $previous_label,
							'nxtlabel' => $next_label,
						)
					);

					$direct_markup = \_navigation_markup(
						'<a href="http://example.test/archive/?safe=1">Anchor ' . $marker . '</a>',
						$direct_css_class,
						'Screen <reader> ' . $marker,
						'Archive "label" <' . $marker . '>'
					);
				}
			);
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			\remove_filter( 'next_posts_link_attributes', $next_attr_filter, 10 );
			\remove_filter( 'previous_posts_link_attributes', $prev_attr_filter, 10 );
			\remove_filter( 'the_posts_pagination_args', $pagination_args_filter, 10 );
			\remove_filter( 'navigation_markup_template', $navigation_template_filter, 10 );
			self::restore_globals( $global_snapshot );
			if ( $request_exists ) {
				$_SERVER['REQUEST_URI'] = $request_uri;
			} else {
				unset( $_SERVER['REQUEST_URI'] );
			}
		}

		self::collect_failure(
			$failures,
			'' !== $middle_next_page
				&& $middle_next_page === $middle_next_page_echo
				&& '' !== $middle_prev_page
				&& $middle_prev_page === $middle_prev_page_echo
				&& str_contains( $middle_next_page, 'paged=4' )
				&& str_contains( $middle_prev_page, 'paged=2' )
				&& ! str_contains( strtolower( $middle_next_page . $middle_prev_page ), '<script' ),
			'next_posts and previous_posts wrappers echo/return matching page URLs for archive query state',
			array(
				'nextPage' => $middle_next_page,
				'prevPage' => $middle_prev_page,
			)
		);

		self::collect_failure(
			$failures,
			$next_link === $next_link_echo
				&& $previous_link === $previous_link_echo
				&& str_contains( $next_link, 'data-cfz-next="' . \esc_attr( $marker ) . '"' )
				&& str_contains( $previous_link, 'data-cfz-prev="' . \esc_attr( $marker ) . '"' )
				&& str_contains( $next_link, 'Older &#038; Archive ' . $marker )
				&& str_contains( $previous_link, 'Newer &#038; Archive ' . $marker )
				&& str_contains( $next_link, 'paged=4' )
				&& str_contains( $previous_link, 'paged=2' )
				&& 0 < count( $next_attr_events )
				&& 0 < count( $prev_attr_events )
				&& false === \has_filter( 'next_posts_link_attributes', $next_attr_filter, 10 )
				&& false === \has_filter( 'previous_posts_link_attributes', $prev_attr_filter, 10 ),
			'next/previous posts link wrappers apply scoped attribute filters, preserve echo parity, and clean up hooks',
			array(
				'nextLink'       => self::describe_string( $next_link ),
				'previousLink'   => self::describe_string( $previous_link ),
				'nextAttrEvents' => $next_attr_events,
				'prevAttrEvents' => $prev_attr_events,
			)
		);

		self::collect_failure(
			$failures,
			$posts_nav === $posts_nav_echo
				&& str_contains( $posts_nav, ' | ' . $marker . ' | ' )
				&& str_contains( $posts_nav, 'paged=2' )
				&& str_contains( $posts_nav, 'paged=4' )
				&& ! str_contains( $first_posts_nav, ' | ' . $marker . ' | ' )
				&& ! str_contains( $last_posts_nav, ' | ' . $marker . ' | ' )
				&& str_contains( $first_posts_nav, 'paged=2' )
				&& ! str_contains( $first_posts_nav, 'paged=0' )
				&& str_contains( $last_posts_nav, 'paged=4' )
				&& ! str_contains( $last_posts_nav, 'paged=6' ),
			'get_posts_nav_link adds separators only when both directions exist and posts_nav_link echoes the getter',
			array(
				'middle' => self::describe_string( $posts_nav ),
				'first'  => self::describe_string( $first_posts_nav ),
				'last'   => self::describe_string( $last_posts_nav ),
			)
		);

		self::collect_failure(
			$failures,
			$posts_navigation === $posts_navigation_echo
				&& str_contains( $posts_navigation, 'class="nav-previous"' )
				&& str_contains( $posts_navigation, 'class="nav-next"' )
				&& str_contains( $posts_navigation, 'Older &#038; Archive ' . $marker )
				&& str_contains( $posts_navigation, 'Newer &#038; Archive ' . $marker )
				&& str_contains( $posts_navigation, 'paged=4' )
				&& str_contains( $posts_navigation, 'paged=2' )
				&& str_contains( $first_navigation, 'class="nav-previous"' )
				&& ! str_contains( $first_navigation, 'class="nav-next"' )
				&& str_contains( $last_navigation, 'class="nav-next"' )
				&& ! str_contains( $last_navigation, 'class="nav-previous"' )
				&& '' === $single_navigation
				&& ! str_contains( strtolower( $posts_navigation . $first_navigation . $last_navigation ), '<script' ),
			'get_the_posts_navigation wraps available archive directions, suppresses missing sides, and echoes exactly',
			array(
				'middle' => self::describe_string( $posts_navigation ),
				'first'  => self::describe_string( $first_navigation ),
				'last'   => self::describe_string( $last_navigation ),
				'single' => self::describe_string( $single_navigation ),
			)
		);

		$expected_direct_class = \sanitize_html_class( $direct_css_class );
		self::collect_failure(
			$failures,
			$pagination === $pagination_echo
				&& '' === $single_pagination
				&& 0 < count( $pagination_events )
				&& str_contains( $pagination, 'class="navigation ' . \sanitize_html_class( 'pagination ' . $marker . ' <script>' ) . '"' )
				&& str_contains( $pagination, 'Pages &lt;reader&gt; ' . $marker )
				&& str_contains( $pagination, 'Archive &quot;pages&quot; &lt;' . $marker . '&gt;' )
				&& str_contains( $pagination, 'class="page-numbers current"' )
				&& ! str_contains( strtolower( $pagination ), '<script' )
				&& false === \has_filter( 'the_posts_pagination_args', $pagination_args_filter, 10 ),
			'get_the_posts_pagination filters args, coerces array type back to string markup, escapes nav labels, and echoes exactly',
			array(
				'pagination'       => self::describe_string( $pagination ),
				'singlePagination' => self::describe_value( $single_pagination ),
				'events'           => $pagination_events,
			)
		);

		self::collect_failure(
			$failures,
			null === $singular_next_link
				&& null === $singular_prev_link
				&& '' === $singular_posts_nav
				&& str_contains( $direct_markup, 'class="navigation ' . $expected_direct_class . '"' )
				&& str_contains( $direct_markup, 'Screen &lt;reader&gt; ' . $marker )
				&& str_contains( $direct_markup, 'Archive &quot;label&quot; &lt;' . $marker . '&gt;' )
				&& str_contains( $direct_markup, '<a href="http://example.test/archive/?safe=1">Anchor ' . $marker . '</a>' )
				&& 0 < count( $template_events )
				&& false === \has_filter( 'navigation_markup_template', $navigation_template_filter, 10 ),
			'archive navigation wrappers fail closed for singular queries and _navigation_markup sanitizes classes while preserving link markup',
			array(
				'singularNextLink' => self::describe_value( $singular_next_link ),
				'singularPrevLink' => self::describe_value( $singular_prev_link ),
				'singularPostsNav' => self::describe_string( $singular_posts_nav ),
				'directMarkup'     => self::describe_string( $direct_markup ),
				'templateEvents'   => $template_events,
			)
		);

		return self::row(
			$ctx,
			'template-links.links.archive-navigation-wrappers',
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

	private static function check_search_form_rendering_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$query          = 'search ' . $ctx->text( 0, 24 ) . '"<&';
		$aria_label     = 'Search area ' . $ctx->identifier( 4, 12 ) . '"<&';
		$home           = self::$options['home'] ?? 'http://example.test';
		$query_snapshot = self::snapshot_query_search_state();
		$events         = array(
			'pre'    => array(),
			'args'   => array(),
			'format' => array(),
			'form'   => array(),
		);
		$mode           = 'html5';
		$sequence       = 0;
		$buffer_level   = ob_get_level();

		$pre_action = static function ( $args ) use ( &$events, &$sequence ): void {
			$events['pre'][] = array(
				'order'   => ++$sequence,
				'rawType' => gettype( $args ),
				'args'    => is_array( $args ) ? self::search_form_args_summary( $args ) : $args,
			);
		};
		$args_filter = static function ( array $args ) use ( &$events, &$sequence ): array {
			$events['args'][] = array(
				'order' => ++$sequence,
				'args'  => self::search_form_args_summary( $args ),
			);
			return $args;
		};
		$format_filter = static function ( string $format, array $args ) use ( &$events, &$mode, &$sequence ): string {
			$events['format'][] = array(
				'order'          => ++$sequence,
				'defaultFormat'  => $format,
				'returnedFormat' => $mode,
				'args'           => self::search_form_args_summary( $args ),
			);
			return $mode;
		};
		$form_filter = static function ( string $form, array $args ) use ( &$events, &$mode, &$sequence ): ?string {
			$events['form'][] = array(
				'order'     => ++$sequence,
				'mode'      => $mode,
				'formSha1'  => sha1( $form ),
				'formBytes' => strlen( $form ),
				'args'      => self::search_form_args_summary( $args ),
			);

			if ( 'null-fallback' === $mode ) {
				return null;
			}

			return $form . '<!--cfz-search-form-' . \esc_attr( $mode ) . '-->';
		};

		\add_action( 'pre_get_search_form', $pre_action, 10, 1 );
		\add_filter( 'search_form_args', $args_filter, 10, 1 );
		\add_filter( 'search_form_format', $format_filter, 10, 2 );
		\add_filter( 'get_search_form', $form_filter, 10, 2 );

		try {
			self::set_search_query_context( $query );

			$mode  = 'html5';
			$html5 = \get_search_form(
				array(
					'echo'       => false,
					'aria_label' => $aria_label,
				)
			);

			$mode  = 'xhtml';
			$xhtml = \get_search_form(
				array(
					'echo'       => false,
					'aria_label' => $aria_label,
				)
			);

			$echo_return = 'not-called';
			$echoed      = self::capture_output(
				static function () use ( &$echo_return, $aria_label ): void {
					$echo_return = \get_search_form(
						array(
							'echo'       => true,
							'aria_label' => $aria_label,
						)
					);
				}
			);

			$mode   = 'html5';
			$legacy = \get_search_form( false );

			$mode          = 'null-fallback';
			$null_fallback = \get_search_form(
				array(
					'echo'       => false,
					'aria_label' => $aria_label,
				)
			);
		} finally {
			\remove_action( 'pre_get_search_form', $pre_action, 10 );
			\remove_filter( 'search_form_args', $args_filter, 10 );
			\remove_filter( 'search_form_format', $format_filter, 10 );
			\remove_filter( 'get_search_form', $form_filter, 10 );
			self::restore_query_search_state( $query_snapshot );
		}

		$escaped_query = \esc_attr( $query );
		$escaped_aria  = \esc_attr( $aria_label );
		$home_action   = \esc_url( \home_url( '/' ) );

		self::collect_failure(
			$failures,
			is_string( $html5 )
				&& 1 === substr_count( $html5, '<form ' )
				&& 1 === substr_count( $html5, '</form>' )
				&& str_contains( $html5, '<form role="search" aria-label="' . $escaped_aria . '" method="get" class="search-form" action="' . $home_action . '">' )
				&& str_contains( $html5, '<input type="search" class="search-field"' )
				&& str_contains( $html5, 'placeholder="' . \esc_attr_x( 'Search &hellip;', 'placeholder' ) . '"' )
				&& str_contains( $html5, 'value="' . $escaped_query . '" name="s" />' )
				&& str_contains( $html5, '<input type="submit" class="search-submit" value="' . \esc_attr_x( 'Search', 'submit button' ) . '" />' )
				&& str_contains( $html5, '<!--cfz-search-form-html5-->' )
				&& ! str_contains( $html5, 'id="searchform"' )
				&& ! str_contains( strtolower( $html5 ), '<script' )
				&& ! str_contains( strtolower( $html5 ), ' onerror=' )
				&& ! str_contains( $html5, '"<&' ),
			'get_search_form renders default HTML5 markup with escaped aria label, query value, action, and filter output',
			array(
				'html5'        => self::describe_string( is_string( $html5 ) ? $html5 : '' ),
				'escapedQuery' => $escaped_query,
				'escapedAria'  => $escaped_aria,
				'homeAction'   => $home_action,
				'homeOption'   => $home,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $xhtml )
				&& is_string( $echoed )
				&& null === $echo_return
				&& $xhtml === $echoed
				&& str_contains( $xhtml, '<form role="search" aria-label="' . $escaped_aria . '" method="get" id="searchform" class="searchform" action="' . $home_action . '">' )
				&& str_contains( $xhtml, '<input type="text" value="' . $escaped_query . '" name="s" id="s" />' )
				&& str_contains( $xhtml, '<input type="submit" id="searchsubmit" value="' . \esc_attr_x( 'Search', 'submit button' ) . '" />' )
				&& str_contains( $xhtml, '<!--cfz-search-form-xhtml-->' )
				&& ! str_contains( $xhtml, 'type="search"' )
				&& ! str_contains( strtolower( $xhtml ), '<script' )
				&& ! str_contains( strtolower( $xhtml ), ' onerror=' )
				&& ! str_contains( $xhtml, '"<&' ),
			'get_search_form renders forced XHTML markup and echo mode exactly matches returned markup',
			array(
				'xhtml'      => self::describe_string( is_string( $xhtml ) ? $xhtml : '' ),
				'echoed'     => self::describe_string( is_string( $echoed ) ? $echoed : '' ),
				'echoReturn' => $echo_return,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $legacy )
				&& str_contains( $legacy, '<form role="search" method="get" class="search-form" action="' . $home_action . '">' )
				&& ! str_contains( $legacy, 'aria-label=' )
				&& str_contains( $legacy, '<!--cfz-search-form-html5-->' )
				&& is_string( $null_fallback )
				&& str_contains( $null_fallback, '<form role="search" aria-label="' . $escaped_aria . '" method="get" id="searchform" class="searchform" action="' . $home_action . '">' )
				&& str_contains( $null_fallback, '<input type="text" value="' . $escaped_query . '" name="s" id="s" />' )
				&& ! str_contains( $null_fallback, '<!--cfz-search-form-' ),
			'get_search_form preserves legacy boolean return mode and null get_search_form filter fallback',
			array(
				'legacy'       => self::describe_string( is_string( $legacy ) ? $legacy : '' ),
				'nullFallback' => self::describe_string( is_string( $null_fallback ) ? $null_fallback : '' ),
			)
		);

		$orders = array_merge(
			array_column( $events['pre'], 'order' ),
			array_column( $events['args'], 'order' ),
			array_column( $events['format'], 'order' ),
			array_column( $events['form'], 'order' )
		);
		$sorted_orders = $orders;
		sort( $sorted_orders );
		$first_args    = $events['args'][0]['args'] ?? array();
		$legacy_pre    = $events['pre'][3] ?? array();
		$legacy_args   = $events['args'][3]['args'] ?? array();
		$first_orders  = array(
			$events['pre'][0]['order'] ?? null,
			$events['args'][0]['order'] ?? null,
			$events['format'][0]['order'] ?? null,
			$events['form'][0]['order'] ?? null,
		);
		$legacy_orders = array(
			$events['pre'][3]['order'] ?? null,
			$events['args'][3]['order'] ?? null,
			$events['format'][3]['order'] ?? null,
			$events['form'][3]['order'] ?? null,
		);

		self::collect_failure(
			$failures,
			20 === count( $orders )
				&& range( 1, 20 ) === $sorted_orders
				&& array( 1, 2, 3, 4 ) === $first_orders
				&& array( 13, 14, 15, 16 ) === $legacy_orders
				&& 5 === count( $events['pre'] )
				&& 5 === count( $events['args'] )
				&& 5 === count( $events['format'] )
				&& 5 === count( $events['form'] )
				&& true === ( $first_args['hasAriaLabel'] ?? null )
				&& false === ( $first_args['echo'] ?? null )
				&& 'boolean' === ( $legacy_pre['rawType'] ?? null )
				&& false === ( $legacy_pre['args'] ?? null )
				&& false === ( $legacy_args['echo'] ?? null )
				&& false === ( $legacy_args['hasAriaLabel'] ?? null )
				&& false === \has_filter( 'pre_get_search_form', $pre_action )
				&& false === \has_filter( 'search_form_args', $args_filter )
				&& false === \has_filter( 'search_form_format', $format_filter )
				&& false === \has_filter( 'get_search_form', $form_filter )
				&& ob_get_level() === $buffer_level
				&& self::query_search_state_matches( $query_snapshot ),
			'get_search_form action/filter payloads are ordered and cleanup restores hooks and query globals',
			array(
				'events'        => $events,
				'orders'        => $orders,
				'sortedOrders'  => $sorted_orders,
				'firstOrders'   => $first_orders,
				'legacyOrders'  => $legacy_orders,
				'bufferBefore'  => $buffer_level,
				'bufferAfter'   => ob_get_level(),
				'querySnapshot' => self::describe_value( $query_snapshot ),
				'queryAfter'    => self::describe_value( self::snapshot_query_search_state() ),
			)
		);

		return self::row(
			$ctx,
			'template-links.search-form-rendering-filters',
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

	private static function check_archive_link_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$year            = $ctx->int( 1970, 2035 );
		$month           = $ctx->int( 1, 12 );
		$day             = $ctx->int( 1, 28 );
		$author_id       = 100 + $ctx->int( 1, 900 );
		$author_slug     = trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $ctx->identifier( 4, 10 ) ) ), '-' );
		$author_nicename = 'author-' . $author_slug . '-' . $ctx->int( 10, 99 );
		$dirty_author_id = $author_id + 1000;
		$dirty_nicename  = 'space name/slash%2F<tag>-utf8-'
			. "\xE2\x98\x83"
			. '-' . "\xC3\xA9"
			. '-case-' . $ctx->int( 10, 99 );
		$month_padded    = sprintf( '%02d', $month );
		$day_padded      = sprintf( '%02d', $day );
		$plain_links     = array();
		$pretty_links    = array();
		$dirty_author    = null;
		$date_events     = array();
		$author_events   = array();

		$year_filter = static function ( string $link, int $filtered_year ) use ( &$date_events ): string {
			$date_events[] = array(
				'helper' => 'year',
				'link'   => $link,
				'year'   => $filtered_year,
			);

			return $link;
		};
		$month_filter = static function ( string $link, int $filtered_year, int $filtered_month ) use ( &$date_events ): string {
			$date_events[] = array(
				'helper' => 'month',
				'link'   => $link,
				'year'   => $filtered_year,
				'month'  => $filtered_month,
			);

			return $link;
		};
		$day_filter = static function ( string $link, int $filtered_year, int $filtered_month, int $filtered_day ) use ( &$date_events ): string {
			$date_events[] = array(
				'helper' => 'day',
				'link'   => $link,
				'year'   => $filtered_year,
				'month'  => $filtered_month,
				'day'    => $filtered_day,
			);

			return $link;
		};
		$author_filter = static function ( string $link, int $filtered_author_id, string $filtered_nicename ) use ( &$author_events ): string {
			$author_events[] = array(
				'link'     => $link,
				'authorId' => $filtered_author_id,
				'nicename' => $filtered_nicename,
			);

			return $link;
		};

		\add_filter( 'year_link', $year_filter, 10, 2 );
		\add_filter( 'month_link', $month_filter, 10, 3 );
		\add_filter( 'day_link', $day_filter, 10, 4 );
		\add_filter( 'author_link', $author_filter, 10, 3 );

		try {
			self::with_permalink_structure(
				'',
				static function () use ( $year, $month, $day, $author_id, $author_nicename, &$plain_links ): void {
					$plain_links = array(
						'year'   => \get_year_link( $year ),
						'month'  => \get_month_link( $year, $month ),
						'day'    => \get_day_link( $year, $month, $day ),
						'author' => \get_author_posts_url( $author_id, $author_nicename ),
					);
				}
			);

			self::with_permalink_structure(
				'/%year%/%monthnum%/%postname%/',
				static function () use ( $year, $month, $day, $author_id, $author_nicename, $dirty_author_id, $dirty_nicename, &$pretty_links, &$dirty_author ): void {
					$pretty_links = array(
						'year'   => \get_year_link( $year ),
						'month'  => \get_month_link( $year, $month ),
						'day'    => \get_day_link( $year, $month, $day ),
						'author' => \get_author_posts_url( $author_id, $author_nicename ),
					);
					$dirty_author = \get_author_posts_url( $dirty_author_id, $dirty_nicename );
				}
			);
		} finally {
			\remove_filter( 'year_link', $year_filter, 10 );
			\remove_filter( 'month_link', $month_filter, 10 );
			\remove_filter( 'day_link', $day_filter, 10 );
			\remove_filter( 'author_link', $author_filter, 10 );
		}

		$expected_plain  = array(
			'year'   => "http://example.test/?m={$year}",
			'month'  => "http://example.test/?m={$year}{$month_padded}",
			'day'    => "http://example.test/?m={$year}{$month_padded}{$day_padded}",
			'author' => "http://example.test/?author={$author_id}",
		);
		$expected_pretty = array(
			'year'   => "http://example.test/{$year}/",
			'month'  => "http://example.test/{$year}/{$month_padded}/",
			'day'    => "http://example.test/{$year}/{$month_padded}/{$day_padded}/",
			'author' => "http://example.test/author/{$author_nicename}/",
		);
		$date_urls       = array(
			$plain_links['year'] ?? '',
			$plain_links['month'] ?? '',
			$plain_links['day'] ?? '',
			$pretty_links['year'] ?? '',
			$pretty_links['month'] ?? '',
			$pretty_links['day'] ?? '',
		);
		$author_urls     = array(
			$plain_links['author'] ?? '',
			$pretty_links['author'] ?? '',
		);
		$escaped_dirty   = \esc_url( (string) $dirty_author );

		self::collect_failure(
			$failures,
			( $plain_links['year'] ?? null ) === $expected_plain['year']
				&& ( $plain_links['month'] ?? null ) === $expected_plain['month']
				&& ( $plain_links['day'] ?? null ) === $expected_plain['day']
				&& ( $pretty_links['year'] ?? null ) === $expected_pretty['year']
				&& ( $pretty_links['month'] ?? null ) === $expected_pretty['month']
				&& ( $pretty_links['day'] ?? null ) === $expected_pretty['day']
				&& $plain_links['year'] !== $pretty_links['year']
				&& self::all_urls_are_http_or_relative( $date_urls )
				&& ! self::contains_raw_dangerous_html( implode( "\n", $date_urls ) )
				&& array( 'year', 'month', 'day', 'year', 'month', 'day' ) === array_column( $date_events, 'helper' )
				&& array( $year, $year, $year, $year, $year, $year ) === array_column( $date_events, 'year' )
				&& array( null, $month, $month, null, $month, $month ) === array_map(
					static function ( array $event ) {
						return $event['month'] ?? null;
					},
					$date_events
				)
				&& array( null, null, $day, null, null, $day ) === array_map(
					static function ( array $event ) {
						return $event['day'] ?? null;
					},
					$date_events
				)
				&& false === \has_filter( 'year_link', $year_filter, 10 )
				&& false === \has_filter( 'month_link', $month_filter, 10 )
				&& false === \has_filter( 'day_link', $day_filter, 10 ),
			'date archive helpers produce exact plain query and pretty permalink URLs with expected filter payloads',
			array(
				'expectedPlain'  => $expected_plain,
				'expectedPretty' => $expected_pretty,
				'plainLinks'     => $plain_links,
				'prettyLinks'    => $pretty_links,
				'dateEvents'     => $date_events,
			)
		);

		self::collect_failure(
			$failures,
			( $plain_links['author'] ?? null ) === $expected_plain['author']
				&& ( $pretty_links['author'] ?? null ) === $expected_pretty['author']
				&& self::all_urls_are_http_or_relative( $author_urls )
				&& ! self::contains_raw_dangerous_html( implode( "\n", $author_urls ) )
				&& 3 === count( $author_events )
				&& array( $expected_plain['author'], $expected_pretty['author'], $dirty_author ) === array_column( $author_events, 'link' )
				&& array( $author_id, $author_id, $dirty_author_id ) === array_column( $author_events, 'authorId' )
				&& array( $author_nicename, $author_nicename, $dirty_nicename ) === array_column( $author_events, 'nicename' )
				&& false === \has_filter( 'author_link', $author_filter, 10 ),
			'author archive helper honors plain query fallback, pretty author base, and expected filter payloads',
			array(
				'authorId'       => $author_id,
				'authorNicename' => $author_nicename,
				'plainAuthor'    => $plain_links['author'] ?? null,
				'prettyAuthor'   => $pretty_links['author'] ?? null,
				'dirtyAuthor'    => $dirty_author,
				'authorEvents'   => $author_events,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $dirty_author )
				&& str_starts_with( $escaped_dirty, 'http://example.test/author/' )
				&& str_ends_with( $escaped_dirty, '/' )
				&& ! str_contains( $escaped_dirty, '<' )
				&& ! str_contains( $escaped_dirty, ' ' )
				&& ! self::contains_raw_dangerous_html( $escaped_dirty )
				&& self::is_http_or_relative_url( $escaped_dirty ),
			'author archive helper dirty nicename bytes stay escapable and local',
			array(
				'dirtyAuthorId'   => $dirty_author_id,
				'dirtyNicename'   => $dirty_nicename,
				'dirtyAuthor'     => $dirty_author,
				'escapedDirtyUrl' => $escaped_dirty,
			)
		);

		return self::row(
			$ctx,
			'template-links.links.archive-url-helpers',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_adjacent_post_link_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$current  = self::current_post();
		$marker   = 'cfz-adjacent-' . strtolower( $ctx->identifier( 4, 10 ) );

		$previous = self::adjacent_post_case( $ctx->fork( 'previous-adjacent' ), $current, 'previous' );
		$next     = self::adjacent_post_case( $ctx->fork( 'next-adjacent' ), $current, 'next' );

		self::seed_post_storage( $previous );
		self::seed_post_storage( $next );

		$where_events = array();
		$term_events  = array();
		$rel_events   = array();
		$link_events  = array();

		$previous_where_filter = self::adjacent_where_filter( 'previous', $previous->ID, $where_events );
		$next_where_filter     = self::adjacent_where_filter( 'next', $next->ID, $where_events );

		$previous_terms_filter = static function ( $excluded_terms ) use ( &$term_events ) {
			$term_events[] = array(
				'adjacent' => 'previous',
				'terms'    => $excluded_terms,
			);
			return array_values( array_unique( array_merge( array_map( 'intval', (array) $excluded_terms ), array( 91 ) ) ) );
		};
		$next_terms_filter     = static function ( $excluded_terms ) use ( &$term_events ) {
			$term_events[] = array(
				'adjacent' => 'next',
				'terms'    => $excluded_terms,
			);
			return array_values( array_unique( array_merge( array_map( 'intval', (array) $excluded_terms ), array( 92 ) ) ) );
		};

		$previous_rel_filter = self::adjacent_rel_filter( 'previous', $marker, $rel_events );
		$next_rel_filter     = self::adjacent_rel_filter( 'next', $marker, $rel_events );
		$previous_link_filter = self::adjacent_link_filter( 'previous', $marker, $link_events );
		$next_link_filter     = self::adjacent_link_filter( 'next', $marker, $link_events );

		$buffer_level = ob_get_level();
		$previous_rel = null;
		$next_rel     = null;
		$combined_rel = '';
		$head_rel     = '';
		$head_bail    = '';
		$previous_nav = '';
		$next_nav     = '';

		\add_filter( 'get_previous_post_where', $previous_where_filter, 10, 5 );
		\add_filter( 'get_next_post_where', $next_where_filter, 10, 5 );
		\add_filter( 'get_previous_post_excluded_terms', $previous_terms_filter, 10, 1 );
		\add_filter( 'get_next_post_excluded_terms', $next_terms_filter, 10, 1 );
		\add_filter( 'previous_post_rel_link', $previous_rel_filter, 10, 1 );
		\add_filter( 'next_post_rel_link', $next_rel_filter, 10, 1 );
		\add_filter( 'previous_post_link', $previous_link_filter, 10, 5 );
		\add_filter( 'next_post_link', $next_link_filter, 10, 5 );

		try {
			self::with_permalink_structure(
				'/%year%/%monthnum%/%postname%/',
				static function () use (
					$previous,
					$next,
					&$previous_rel,
					&$next_rel,
					&$combined_rel,
					&$head_rel,
					&$head_bail,
					&$previous_nav,
					&$next_nav
				): void {
					$previous_rel = \get_adjacent_post_rel_link( 'Older %title on %date', false, array( 11, 12 ), true );
					$next_rel     = \get_adjacent_post_rel_link( 'Newer %title on %date', false, '21,22', false );

					ob_start();
					\adjacent_posts_rel_link( 'Around %title on %date', false, array( 31 ), 'category' );
					$combined_rel = (string) ob_get_clean();

					ob_start();
					\prev_post_rel_link( 'Prev %title', false, array( 41 ) );
					\next_post_rel_link( 'Next %title', false, '51,52' );
					$head_rel = (string) ob_get_clean();

					ob_start();
					\adjacent_posts_rel_link_wp_head();
					$head_rel .= (string) ob_get_clean();

					$query           = $GLOBALS['wp_query'] ?? null;
					$previous_single = $query instanceof \WP_Query ? $query->is_single : null;
					if ( $query instanceof \WP_Query ) {
						$query->is_single = false;
					}
					ob_start();
					\adjacent_posts_rel_link_wp_head();
					$head_bail = (string) ob_get_clean();
					if ( $query instanceof \WP_Query ) {
						$query->is_single = $previous_single;
					}

					$previous_nav = (string) \get_previous_post_link( '<nav class="previous">%link</nav>', 'Older %title', false, array( 61 ) );
					$next_nav     = (string) \get_next_post_link( '<nav class="next">%link</nav>', 'Newer %title', false, '71,72' );

					unset( $previous, $next );
				}
			);
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			\remove_filter( 'get_previous_post_where', $previous_where_filter, 10 );
			\remove_filter( 'get_next_post_where', $next_where_filter, 10 );
			\remove_filter( 'get_previous_post_excluded_terms', $previous_terms_filter, 10 );
			\remove_filter( 'get_next_post_excluded_terms', $next_terms_filter, 10 );
			\remove_filter( 'previous_post_rel_link', $previous_rel_filter, 10 );
			\remove_filter( 'next_post_rel_link', $next_rel_filter, 10 );
			\remove_filter( 'previous_post_link', $previous_link_filter, 10 );
			\remove_filter( 'next_post_link', $next_link_filter, 10 );
			self::delete_post_storage( $previous->ID );
			self::delete_post_storage( $next->ID );
		}

		$previous_href = \get_permalink( $previous );
		$next_href     = \get_permalink( $next );

		self::collect_failure(
			$failures,
			is_string( $previous_rel )
				&& is_string( $next_rel )
				&& str_contains( $previous_rel, "rel='prev'" )
				&& str_contains( $next_rel, "rel='next'" )
				&& str_contains( $previous_rel, 'Older Previous Adjacent' )
				&& str_contains( $next_rel, 'Newer Next Adjacent' )
				&& str_contains( $previous_rel, '&amp; &quot;Quote&quot; on 2026-06-20' )
				&& str_contains( $next_rel, '&amp; &quot;Quote&quot; on 2026-06-24' )
				&& str_contains( $previous_rel, \esc_url( $previous_href ) )
				&& str_contains( $next_rel, \esc_url( $next_href ) )
				&& str_contains( $previous_rel, "data-cfz-previous='" . \esc_attr( $marker ) . "'" )
				&& str_contains( $next_rel, "data-cfz-next='" . \esc_attr( $marker ) . "'" )
				&& ! str_contains( $previous_rel . $next_rel, '<Prev>' )
				&& ! str_contains( $previous_rel . $next_rel, '<Next>' )
				&& ! str_contains( strtolower( $previous_rel . $next_rel . $combined_rel . $head_rel ), '<script' )
				&& '' === $head_bail,
			'adjacent relational link helpers find previous/next posts, escape title attributes, honor wp_head gates, and keep filter output display-safe',
			array(
				'previousRel' => self::describe_string( is_string( $previous_rel ) ? $previous_rel : '' ),
				'nextRel'     => self::describe_string( is_string( $next_rel ) ? $next_rel : '' ),
				'combinedRel' => self::describe_string( $combined_rel ),
				'headRel'     => self::describe_string( $head_rel ),
				'headBail'    => self::describe_string( $head_bail ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $previous_nav, '<nav class="previous">' )
				&& str_contains( $next_nav, '<nav class="next">' )
				&& str_contains( $previous_nav, 'rel="prev"' )
				&& str_contains( $next_nav, 'rel="next"' )
				&& str_contains( $previous_nav, $previous->post_name )
				&& str_contains( $next_nav, $next->post_name )
				&& str_contains( $previous_nav, 'data-cfz-link="' . \esc_attr( $marker . '-previous' ) . '"' )
				&& str_contains( $next_nav, 'data-cfz-link="' . \esc_attr( $marker . '-next' ) . '"' )
				&& 0 < count( array_filter( $link_events, static fn( array $event ): bool => 'previous' === $event['adjacent'] ) )
				&& 0 < count( array_filter( $link_events, static fn( array $event ): bool => 'next' === $event['adjacent'] ) ),
			'previous/next post link helpers render expected adjacent anchors and expose filter payloads',
			array(
				'previousNav' => self::describe_string( $previous_nav ),
				'nextNav'     => self::describe_string( $next_nav ),
				'linkEvents'  => $link_events,
			)
		);

		self::collect_failure(
			$failures,
			0 < count( array_filter( $where_events, static fn( array $event ): bool => 'previous' === $event['adjacent'] && $current->ID === $event['currentPost'] ) )
				&& 0 < count( array_filter( $where_events, static fn( array $event ): bool => 'next' === $event['adjacent'] && $current->ID === $event['currentPost'] ) )
				&& 0 < count( array_filter( $term_events, static fn( array $event ): bool => 'previous' === $event['adjacent'] && in_array( 11, (array) $event['terms'], true ) ) )
				&& 0 < count( array_filter( $term_events, static fn( array $event ): bool => 'next' === $event['adjacent'] && in_array( 21, (array) $event['terms'], true ) ) )
				&& 0 < count( array_filter( $rel_events, static fn( array $event ): bool => 'previous' === $event['adjacent'] ) )
				&& 0 < count( array_filter( $rel_events, static fn( array $event ): bool => 'next' === $event['adjacent'] ) )
				&& false === \has_filter( 'get_previous_post_where', $previous_where_filter, 10 )
				&& false === \has_filter( 'get_next_post_where', $next_where_filter, 10 )
				&& false === \has_filter( 'previous_post_rel_link', $previous_rel_filter, 10 )
				&& false === \has_filter( 'next_post_link', $next_link_filter, 10 ),
			'adjacent post query and output filters receive expected payloads and are removed after use',
			array(
				'whereEvents' => $where_events,
				'termEvents'  => $term_events,
				'relEvents'   => $rel_events,
			)
		);

		return self::row(
			$ctx,
			'template-links.links.adjacent-post-relations',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_adjacent_image_link_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$parent      = self::current_post();
		$marker      = 'cfz-image-' . strtolower( $ctx->identifier( 4, 10 ) );
		$text        = 'Some text';
		$attachments = array();

		$previous_post         = $GLOBALS['post'] ?? null;
		$previous_wp_query     = $GLOBALS['wp_query'] ?? null;
		$previous_wp_the_query = $GLOBALS['wp_the_query'] ?? null;
		$previous_paged        = $GLOBALS['paged'] ?? null;
		$previous_page         = $GLOBALS['page'] ?? null;
		$buffer_level          = ob_get_level();

		$previous_filter_events = array();
		$next_filter_events     = array();
		$previous_filter        = self::adjacent_image_filter( 'previous', $marker, $previous_filter_events );
		$next_filter            = self::adjacent_image_filter( 'next', $marker, $next_filter_events );

		$previous_text       = '';
		$previous_getter     = '';
		$previous_echo       = '';
		$previous_adjacent   = '';
		$previous_image      = '';
		$first_previous      = null;
		$next_text           = '';
		$next_getter         = '';
		$next_echo           = '';
		$next_adjacent       = '';
		$next_image          = '';
		$last_next           = null;
		$filtered_previous   = '';
		$filtered_next       = '';
		$expected_previous   = '';
		$expected_next       = '';
		$previous_image_url  = '';
		$next_image_url      = '';
		$previous_anchor_url = '';
		$next_anchor_url     = '';

		try {
			for ( $index = 1; $index <= 5; $index++ ) {
				$attachment    = self::image_attachment_case( $ctx->fork( 'image-' . $index ), $parent, $index );
				$attachments[] = $attachment;

				self::seed_post_storage( $attachment );
				self::seed_attachment_image_meta( $attachment, $index );
			}

			self::with_permalink_structure(
				'',
				static function () use (
					$attachments,
					$text,
					$previous_filter,
					$next_filter,
					&$previous_text,
					&$previous_getter,
					&$previous_echo,
					&$previous_adjacent,
					&$previous_image,
					&$first_previous,
					&$next_text,
					&$next_getter,
					&$next_echo,
					&$next_adjacent,
					&$next_image,
					&$last_next,
					&$filtered_previous,
					&$filtered_next
				): void {
					self::set_current_attachment_query( $attachments[2] );
					$previous_text     = (string) \get_adjacent_image_link( true, 'thumbnail', $text );
					$previous_getter   = (string) \get_previous_image_link( 'thumbnail', $text );
					$previous_echo     = self::capture_output(
						static function () use ( $text ): void {
							\previous_image_link( 'thumbnail', $text );
						}
					);
					$previous_adjacent = self::capture_output(
						static function () use ( $text ): void {
							\adjacent_image_link( true, 'thumbnail', $text );
						}
					);
					$previous_image    = (string) \get_previous_image_link( 'thumbnail', false );

					self::set_current_attachment_query( $attachments[0] );
					$first_previous = \get_previous_image_link( 'thumbnail', $text );

					self::set_current_attachment_query( $attachments[3] );
					$next_text     = (string) \get_adjacent_image_link( false, 'thumbnail', $text );
					$next_getter   = (string) \get_next_image_link( 'thumbnail', $text );
					$next_echo     = self::capture_output(
						static function () use ( $text ): void {
							\next_image_link( 'thumbnail', $text );
						}
					);
					$next_adjacent = self::capture_output(
						static function () use ( $text ): void {
							\adjacent_image_link( false, 'thumbnail', $text );
						}
					);
					$next_image    = (string) \get_next_image_link( 'thumbnail', false );

					self::set_current_attachment_query( $attachments[4] );
					$last_next = \get_next_image_link( 'thumbnail', $text );

					\add_filter( 'previous_image_link', $previous_filter, 10, 4 );
					\add_filter( 'next_image_link', $next_filter, 10, 4 );
					try {
						self::set_current_attachment_query( $attachments[2] );
						$filtered_previous = (string) \get_previous_image_link( 'thumbnail', 'Filtered previous' );

						self::set_current_attachment_query( $attachments[3] );
						$filtered_next = (string) \get_next_image_link( 'thumbnail', 'Filtered next' );
					} finally {
						\remove_filter( 'previous_image_link', $previous_filter, 10 );
						\remove_filter( 'next_image_link', $next_filter, 10 );
					}
				}
			);
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			\remove_filter( 'previous_image_link', $previous_filter, 10 );
			\remove_filter( 'next_image_link', $next_filter, 10 );

			foreach ( $attachments as $attachment ) {
				self::delete_post_storage( $attachment->ID );
				\wp_cache_delete( $attachment->ID, 'post_meta' );
			}

			if ( $previous_post instanceof \WP_Post ) {
				$GLOBALS['post'] = $previous_post;
			} else {
				unset( $GLOBALS['post'] );
			}

			if ( $previous_wp_query instanceof \WP_Query ) {
				$GLOBALS['wp_query'] = $previous_wp_query;
			} else {
				unset( $GLOBALS['wp_query'] );
			}

			if ( $previous_wp_the_query instanceof \WP_Query ) {
				$GLOBALS['wp_the_query'] = $previous_wp_the_query;
			} else {
				unset( $GLOBALS['wp_the_query'] );
			}

			if ( null !== $previous_paged ) {
				$GLOBALS['paged'] = $previous_paged;
			} else {
				unset( $GLOBALS['paged'] );
			}

			if ( null !== $previous_page ) {
				$GLOBALS['page'] = $previous_page;
			} else {
				unset( $GLOBALS['page'] );
			}
		}

		if ( 5 === count( $attachments ) ) {
			$expected_previous   = "<a href='http://example.test/?attachment_id={$attachments[1]->ID}'>{$text}</a>";
			$expected_next       = "<a href='http://example.test/?attachment_id={$attachments[4]->ID}'>{$text}</a>";
			$previous_image_url  = 'http://example.test/wp-content/uploads/2026/07/cfz-image-2-150x150.jpg';
			$next_image_url      = 'http://example.test/wp-content/uploads/2026/07/cfz-image-5-150x150.jpg';
			$previous_anchor_url = "href='http://example.test/?attachment_id={$attachments[1]->ID}'";
			$next_anchor_url     = "href='http://example.test/?attachment_id={$attachments[4]->ID}'";
		}

		self::collect_failure(
			$failures,
			'' !== $expected_previous
				&& $previous_text === $expected_previous
				&& $previous_getter === $expected_previous
				&& $previous_echo === $expected_previous
				&& $previous_adjacent === $expected_previous
				&& '' === $first_previous
				&& $next_text === $expected_next
				&& $next_getter === $expected_next
				&& $next_echo === $expected_next
				&& $next_adjacent === $expected_next
				&& '' === $last_next,
			'adjacent image text helpers render exact sibling attachment anchors and empty edge links',
			array(
				'previousText'     => self::describe_string( $previous_text ),
				'previousGetter'   => self::describe_string( $previous_getter ),
				'previousEcho'     => self::describe_string( $previous_echo ),
				'previousAdjacent' => self::describe_string( $previous_adjacent ),
				'firstPrevious'    => self::describe_value( $first_previous ),
				'nextText'         => self::describe_string( $next_text ),
				'nextGetter'       => self::describe_string( $next_getter ),
				'nextEcho'         => self::describe_string( $next_echo ),
				'nextAdjacent'     => self::describe_string( $next_adjacent ),
				'lastNext'         => self::describe_value( $last_next ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $previous_image, $previous_anchor_url )
				&& str_contains( $next_image, $next_anchor_url )
				&& str_contains( $previous_image, '<img ' )
				&& str_contains( $next_image, '<img ' )
				&& str_contains( $previous_image, 'attachment-thumbnail size-thumbnail' )
				&& str_contains( $next_image, 'attachment-thumbnail size-thumbnail' )
				&& str_contains( $previous_image, $previous_image_url )
				&& str_contains( $next_image, $next_image_url )
				&& str_contains( $previous_image, 'alt="Image 2' )
				&& str_contains( $next_image, 'alt="Image 5' )
				&& ! str_contains( strtolower( $previous_image . $next_image ), '<script' ),
			'adjacent image helpers render attachment image markup with selected sibling URLs and escaped alt text',
			array(
				'previousImage' => self::describe_string( $previous_image ),
				'nextImage'     => self::describe_string( $next_image ),
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $previous_filter_events )
				&& 1 === count( $next_filter_events )
				&& ( $previous_filter_events[0]['attachmentId'] ?? null ) === ( $attachments[1]->ID ?? null )
				&& ( $next_filter_events[0]['attachmentId'] ?? null ) === ( $attachments[4]->ID ?? null )
				&& 'thumbnail' === ( $previous_filter_events[0]['size'] ?? null )
				&& 'thumbnail' === ( $next_filter_events[0]['size'] ?? null )
				&& 'Filtered previous' === ( $previous_filter_events[0]['text'] ?? null )
				&& 'Filtered next' === ( $next_filter_events[0]['text'] ?? null )
				&& str_contains( $filtered_previous, 'data-cfz-image="' . \esc_attr( $marker . '-previous' ) . '"' )
				&& str_contains( $filtered_next, 'data-cfz-image="' . \esc_attr( $marker . '-next' ) . '"' )
				&& false === \has_filter( 'previous_image_link', $previous_filter, 10 )
				&& false === \has_filter( 'next_image_link', $next_filter, 10 ),
			'adjacent image filters receive attachment ID, size, and text payloads and are removed after use',
			array(
				'previousEvents'   => $previous_filter_events,
				'nextEvents'       => $next_filter_events,
				'filteredPrevious' => self::describe_string( $filtered_previous ),
				'filteredNext'     => self::describe_string( $filtered_next ),
			)
		);

		return self::row(
			$ctx,
			'template-links.media.adjacent-image-links',
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
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
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
		if ( ! \taxonomy_exists( 'category' ) ) {
			\create_initial_taxonomies();
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

	private static function adjacent_post_case( \ComponentFuzz\FuzzContext $ctx, \WP_Post $current, string $adjacent ): \WP_Post {
		$is_previous = 'previous' === $adjacent;
		$id_offset   = $is_previous ? 10000 : 20000;
		$id          = $current->ID + $id_offset + $ctx->int( 10, 999 );
		$title       = ( $is_previous ? 'Previous' : 'Next' ) . ' Adjacent ' . self::safe_title_text( $ctx->text( 0, 18 ) );
		$title      .= $is_previous ? ' <Prev> & "Quote"' : ' <Next> & "Quote"';
		$slug        = \sanitize_title_with_dashes( $adjacent . '-' . $ctx->text( 4, 30 ), '', 'save' );
		if ( '' === $slug ) {
			$slug = $adjacent . '-adjacent-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		}

		return new \WP_Post(
			(object) array_merge(
				$current->to_array(),
				array(
					'ID'                => $id,
					'post_date'         => $is_previous ? '2026-06-20 09:00:00' : '2026-06-24 09:00:00',
					'post_date_gmt'     => $is_previous ? '2026-06-20 07:00:00' : '2026-06-24 07:00:00',
					'post_modified'     => $is_previous ? '2026-06-20 09:00:00' : '2026-06-24 09:00:00',
					'post_modified_gmt' => $is_previous ? '2026-06-20 07:00:00' : '2026-06-24 07:00:00',
					'post_name'         => $slug,
					'post_title'        => $title,
					'guid'              => 'http://example.test/?p=' . $id,
				)
			)
		);
	}

	private static function image_attachment_case( \ComponentFuzz\FuzzContext $ctx, \WP_Post $parent, int $index ): \WP_Post {
		$id    = $parent->ID + 30000 + $index;
		$title = 'Image ' . $index . ' ' . self::safe_title_text( $ctx->text( 0, 16 ) );
		$slug  = 'cfz-image-' . $index . '-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );

		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => 0,
				'post_date'             => '2026-06-22 10:2' . $index . ':30',
				'post_date_gmt'         => '2026-06-22 08:2' . $index . ':30',
				'post_content'          => '',
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => 'inherit',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $slug,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-22 10:2' . $index . ':30',
				'post_modified_gmt'     => '2026-06-22 08:2' . $index . ':30',
				'post_content_filtered' => '',
				'post_parent'           => $parent->ID,
				'guid'                  => 'http://example.test/wp-content/uploads/2026/07/cfz-image-' . $index . '.jpg',
				'menu_order'            => $index,
				'post_type'             => 'attachment',
				'post_mime_type'        => 'image/jpeg',
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	private static function seed_post_storage( \WP_Post $post ): void {
		global $wpdb;

		self::$posts[ $post->ID ] = $post;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
			$wpdb->insert( $wpdb->posts, $post->to_array() );
		}
		\wp_cache_set( $post->ID, (object) $post->to_array(), 'posts' );
	}

	private static function delete_post_storage( int $post_id ): void {
		global $wpdb;

		unset( self::$posts[ $post_id ] );
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
			$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
		}
		\wp_cache_delete( $post_id, 'posts' );
	}

	private static function seed_attachment_image_meta( \WP_Post $attachment, int $index ): void {
		$file      = '2026/07/cfz-image-' . $index . '.jpg';
		$thumbnail = 'cfz-image-' . $index . '-150x150.jpg';

		\wp_cache_set(
			$attachment->ID,
			array(
				'_wp_attached_file'      => array( $file ),
				'_wp_attachment_image_alt' => array( 'Image ' . $index . ' alt <script>' ),
				'_wp_attachment_metadata' => array(
					array(
						'width'  => 640,
						'height' => 480,
						'file'   => $file,
						'sizes'  => array(
							'thumbnail' => array(
								'file'      => $thumbnail,
								'width'     => 150,
								'height'    => 150,
								'mime-type' => 'image/jpeg',
							),
						),
					),
				),
			),
			'post_meta'
		);
	}

	private static function post_class_term( int $term_id, string $taxonomy, string $slug ): object {
		return (object) array(
			'term_id'          => $term_id,
			'name'             => 'Post Class Term ' . $term_id,
			'slug'             => $slug,
			'term_group'       => 0,
			'term_taxonomy_id' => $term_id + 1000,
			'taxonomy'         => $taxonomy,
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
			'filter'           => 'raw',
		);
	}

	private static function post_class_term_token( string $taxonomy, string $slug, int $term_id ): string {
		$term_class = \sanitize_html_class( $slug, (string) $term_id );
		if ( is_numeric( $term_class ) || ! trim( $term_class, '-' ) ) {
			$term_class = (string) $term_id;
		}

		if ( 'post_tag' === $taxonomy ) {
			return 'tag-' . $term_class;
		}

		return \sanitize_html_class( $taxonomy . '-' . $term_class, $taxonomy . '-' . $term_id );
	}

	/**
	 * @param array<string,object[]> $terms_by_taxonomy
	 */
	private static function prime_post_class_terms( int $post_id, array $terms_by_taxonomy ): void {
		foreach ( $terms_by_taxonomy as $taxonomy => $terms ) {
			$term_ids = array();
			foreach ( $terms as $term ) {
				$term_ids[] = (int) $term->term_id;
				\wp_cache_set( (int) $term->term_id, $term, 'terms' );
			}

			\wp_cache_set( $post_id, $term_ids, "{$taxonomy}_relationships" );
		}
	}

	private static function set_current_post_query( \WP_Post $post, int $paged, bool $is_home ): void {
		$query = self::query_for_post( $post, $paged );

		$query->is_home     = $is_home;
		$query->is_paged    = $paged > 1;
		$query->is_single   = ! $is_home;
		$query->is_singular = ! $is_home;

		$GLOBALS['post']         = $post;
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['paged']        = $paged;
		$GLOBALS['page']         = 1;
	}

	private static function set_archive_query( int $paged, int $max_num_pages ): void {
		$post  = self::current_post();
		$query = self::query_for_post( $post, $paged );

		$query->query_vars['p']     = 0;
		$query->query_vars['name']  = '';
		$query->query_vars['paged'] = $paged;
		$query->queried_object      = null;
		$query->queried_object_id   = 0;
		$query->found_posts         = max( 1, $max_num_pages ) * 10;
		$query->max_num_pages       = $max_num_pages;
		$query->is_single           = false;
		$query->is_singular         = false;
		$query->is_home             = true;
		$query->is_archive          = false;
		$query->is_paged            = $paged > 1;

		$GLOBALS['post']         = $post;
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['paged']        = $paged;
		$GLOBALS['page']         = 1;
	}

	private static function set_current_attachment_query( \WP_Post $attachment ): void {
		self::set_current_post_query( $attachment, 1, false );

		$query = $GLOBALS['wp_query'] ?? null;
		if ( $query instanceof \WP_Query ) {
			$query->is_attachment                = true;
			$query->is_single                    = false;
			$query->query_vars['attachment']     = $attachment->post_name;
			$query->query_vars['attachment_id']  = $attachment->ID;
			$query->query_vars['post_type']      = 'attachment';
			$query->query_vars['post_mime_type'] = 'image/jpeg';
		}
	}

	private static function adjacent_where_filter( string $adjacent, int $target_id, array &$events ): callable {
		return static function ( string $where, bool $in_same_term, $excluded_terms, string $taxonomy, \WP_Post $post ) use (
			$adjacent,
			$target_id,
			&$events
		): string {
			global $wpdb;

			$events[] = array(
				'adjacent'      => $adjacent,
				'currentPost'   => $post->ID,
				'inSameTerm'    => $in_same_term,
				'excludedTerms' => $excluded_terms,
				'taxonomy'      => $taxonomy,
				'originalWhere' => self::describe_string( $where ),
				'targetId'      => $target_id,
			);

			return $wpdb->prepare( 'WHERE p.ID = %d AND p.post_type = %s', $target_id, $post->post_type );
		};
	}

	private static function adjacent_rel_filter( string $adjacent, string $marker, array &$events ): callable {
		return static function ( string $link ) use ( $adjacent, $marker, &$events ): string {
			$events[] = array(
				'adjacent' => $adjacent,
				'link'     => self::describe_string( $link ),
			);

			$attribute = " data-cfz-{$adjacent}='" . \esc_attr( $marker ) . "'";
			return preg_replace( '/\s*\/>\n?$/', $attribute . " />\n", $link ) ?? $link;
		};
	}

	private static function adjacent_link_filter( string $adjacent, string $marker, array &$events ): callable {
		return static function ( string $output, string $format, string $link, $post, string $filtered_adjacent ) use (
			$adjacent,
			$marker,
			&$events
		): string {
			$events[] = array(
				'adjacent'         => $adjacent,
				'filteredAdjacent' => $filtered_adjacent,
				'format'           => self::describe_string( $format ),
				'link'             => self::describe_string( $link ),
				'postId'           => $post instanceof \WP_Post ? $post->ID : null,
				'output'           => self::describe_string( $output ),
			);

			return str_replace(
				'<a ',
				'<a data-cfz-link="' . \esc_attr( $marker . '-' . $adjacent ) . '" ',
				$output
			);
		};
	}

	private static function adjacent_image_filter( string $adjacent, string $marker, array &$events ): callable {
		return static function ( $output, $attachment_id, $size, $text ) use ( $adjacent, $marker, &$events ): string {
			$events[] = array(
				'adjacent'     => $adjacent,
				'attachmentId' => (int) $attachment_id,
				'size'         => is_array( $size ) ? array_values( $size ) : $size,
				'text'         => $text,
				'output'       => self::describe_string( (string) $output ),
			);

			return str_replace(
				'<a ',
				'<a data-cfz-image="' . \esc_attr( $marker . '-' . $adjacent ) . '" ',
				(string) $output
			);
		};
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

	private static function snapshot_query_search_state(): array {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query ) {
			return array(
				'exists' => false,
			);
		}

		return array(
			'exists'       => true,
			'hasSearchVar' => array_key_exists( 's', $query->query_vars ),
			'searchVar'    => $query->query_vars['s'] ?? null,
			'isSearch'     => $query->is_search,
		);
	}

	private static function set_search_query_context( string $query_string ): void {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query ) {
			return;
		}

		$query->query_vars['s'] = $query_string;
		$query->is_search       = true;
	}

	private static function restore_query_search_state( array $snapshot ): void {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query || empty( $snapshot['exists'] ) ) {
			return;
		}

		if ( ! empty( $snapshot['hasSearchVar'] ) ) {
			$query->query_vars['s'] = $snapshot['searchVar'];
		} else {
			unset( $query->query_vars['s'] );
		}

		$query->is_search = (bool) $snapshot['isSearch'];
	}

	private static function query_search_state_matches( array $snapshot ): bool {
		return self::snapshot_query_search_state() === $snapshot;
	}

	private static function search_form_args_summary( array $args ): array {
		return array(
			'keys'          => array_keys( $args ),
			'echo'          => $args['echo'] ?? null,
			'hasAriaLabel'  => array_key_exists( 'aria_label', $args ) && '' !== (string) $args['aria_label'],
			'ariaLabelSha1' => array_key_exists( 'aria_label', $args ) ? sha1( (string) $args['aria_label'] ) : null,
		);
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

	private static function capture_output( callable $callback ): string {
		$buffer_level = ob_get_level();

		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
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
