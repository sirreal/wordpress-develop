<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB feed template rendering around synthetic query loops.
 */
final class FeedRenderingSurface {
	public const NAME = 'feed-rendering';

	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'feed-rendering.bootstrap-apis-available',
					'Required WordPress feed rendering APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::install_default_feed_filters();
			$case = self::prepare_case( $ctx );

			$rows[] = self::check_rss2_posts_template( $ctx->fork( 'rss2' ), $case );
			$rows[] = self::check_atom_posts_template( $ctx->fork( 'atom' ), $case );
			$rows[] = self::check_comments_rss2_template( $ctx->fork( 'comments-rss2' ), $case );
			$rows[] = self::check_comments_atom_template( $ctx->fork( 'comments-atom' ), $case );
			$rows[] = self::check_feed_template_hook_payloads( $ctx->fork( 'hook-payloads' ), $case );
			$rows[] = self::check_content_mode_switches( $ctx->fork( 'content-modes' ), $case );
			$rows[] = self::check_feed_link_helpers( $ctx->fork( 'feed-links' ), $case );
			$rows[] = self::check_self_link_request_uri_oracles( $ctx->fork( 'self-link' ), $case );
			$rows[] = self::check_feed_loop_helpers( $ctx->fork( 'helpers' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'feed-rendering.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'feed-rendering.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedServer'  => array_keys( $snapshot['server'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'SimpleXMLElement', 'WP_Post', 'WP_Comment', 'WP_Query', 'WP_User', 'Component_Fuzz_WPDB_Stub' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_metadata',
				'add_theme_support',
				'atom_enclosure',
				'bloginfo_rss',
				'comment_author_rss',
				'comment_guid',
				'comment_text',
				'comment_text_rss',
				'comments_link_feed',
				'convert_chars',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'ent2ncr',
				'esc_html',
				'esc_url',
				'feed_links',
				'feed_links_extra',
				'get_comment',
				'get_comment_author_url',
				'get_comment_guid',
				'get_comment_link',
				'get_feed_build_date',
				'get_feed_link',
				'get_option',
				'get_post',
				'get_post_comments_feed_link',
				'get_self_link',
				'get_the_content_feed',
				'has_filter',
				'have_comments',
				'have_posts',
				'html_type_rss',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'rss_enclosure',
				'sanitize_title_with_dashes',
				'self_link',
				'set_url_scheme',
				'simplexml_load_string',
				'the_category_rss',
				'the_comment',
				'the_content_feed',
				'the_excerpt_rss',
				'the_guid',
				'the_permalink_rss',
				'the_post',
				'the_title_rss',
				'update_option',
				'wp_cache_delete',
				'wp_cache_flush',
				'wp_check_invalid_utf8',
				'wp_insert_comment',
				'wp_insert_post',
				'wp_insert_user',
				'wp_slash',
				'wp_unslash',
				'wp_title_rss',
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

	private static function check_rss2_posts_template( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::set_posts_query( $case, 'rss2', false );
		$output = self::render_template( 'feed-rss2.php' );

		$xml = self::parse_xml( $output );
		self::collect_failure(
			$failures,
			$xml['ok'] && str_starts_with( $output, '<?xml version="1.0"' ),
			'RSS2 posts feed is parseable XML with an XML declaration',
			array( 'xml' => $xml, 'preview' => self::preview( $output ) )
		);
		self::collect_failure(
			$failures,
			count( $case['posts'] ) === substr_count( $output, '<item>' )
				&& str_contains( $output, '<rss version="2.0"' )
				&& str_contains( $output, 'type="application/rss+xml"' )
				&& str_contains( $output, self::url_attr( $case['selfLink'] ) ),
			'RSS2 posts feed renders one item per synthetic post and a self link',
			array(
				'itemCount' => substr_count( $output, '<item>' ),
				'selfLink'  => $case['selfLink'],
			)
		);
		self::collect_failure(
			$failures,
			$case['rssUseExcerpt']
				? ! str_contains( $output, '<content:encoded>' )
				: (
					count( $case['posts'] ) === substr_count( $output, '<content:encoded>' )
					&& str_contains( $output, 'CDATA close ]]&gt; marker' )
				),
			'RSS2 excerpt mode controls content:encoded and content escapes CDATA terminators',
			array(
				'rssUseExcerpt' => $case['rssUseExcerpt'],
				'encodedCount'   => substr_count( $output, '<content:encoded>' ),
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $output, '<enclosure url="' . self::url_attr( $case['enclosure']['url'] ) . '"' )
				&& str_contains( $output, 'length="' . (string) absint( $case['enclosure']['length'] ) . '"' )
				&& str_contains( $output, 'type="' . self::xml_attr( $case['enclosure']['type'] ) . '"' ),
			'RSS2 enclosures are rendered from post meta with escaped URL, absint length, and MIME type',
			array( 'enclosure' => $case['enclosure'] )
		);
		self::collect_failure(
			$failures,
			! str_contains( $output, '<script' )
				&& ! str_contains( $output, '</title><' . $case['token'] )
				&& str_contains( $output, esc_html( ent2ncr( strip_tags( $case['posts'][0]->post_title ) ) ) ),
			'RSS2 feed escapes title-sensitive text and does not emit raw script tags',
			array( 'title' => $case['posts'][0]->post_title )
		);

		return self::result( $ctx, 'feed-rendering.rss2-posts-template.structure-and-escaping', $failures, $output );
	}

	private static function check_atom_posts_template( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::set_posts_query( $case, 'atom', false );
		$output = self::render_template( 'feed-atom.php' );

		$xml = self::parse_xml( $output );
		self::collect_failure(
			$failures,
			$xml['ok']
				&& str_contains( $output, '<feed' )
				&& count( $case['posts'] ) === substr_count( $output, '<entry>' )
				&& str_contains( $output, 'type="application/atom+xml" href="' . self::url_attr( $case['selfLink'] ) . '"' ),
			'Atom posts feed is parseable and renders one entry per synthetic post with a self link',
			array(
				'xml'        => $xml,
				'entryCount' => substr_count( $output, '<entry>' ),
				'preview'    => self::preview( $output ),
			)
		);
		self::collect_failure(
			$failures,
			$case['rssUseExcerpt']
				? ! str_contains( $output, '<content ' )
				: (
					count( $case['posts'] ) === substr_count( $output, '<content ' )
					&& str_contains( $output, 'CDATA close ]]&gt; marker' )
				),
			'Atom excerpt mode controls content elements and content escapes CDATA terminators',
			array(
				'rssUseExcerpt' => $case['rssUseExcerpt'],
				'contentCount'  => substr_count( $output, '<content ' ),
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $output, 'rel="enclosure"' )
				&& str_contains( $output, 'href="' . self::url_attr( $case['enclosure']['url'] ) . '"' )
				&& str_contains( $output, 'length="' . (string) absint( $case['enclosure']['length'] ) . '"' )
				&& str_contains( $output, 'type="' . self::xml_attr( $case['enclosure']['type'] ) . '"' ),
			'Atom enclosures are rendered from post meta with escaped attributes',
			array( 'enclosure' => $case['enclosure'] )
		);
		self::collect_failure(
			$failures,
			str_contains( $output, '<title type="html"><![CDATA[' )
				&& ! str_contains( $output, '<script' )
				&& str_contains( $output, esc_html( ent2ncr( $case['authorDisplay'] ) ) ),
			'Atom feed keeps title CDATA boundaries and escapes author text through feed filters',
			array( 'author' => $case['authorDisplay'] )
		);

		return self::result( $ctx, 'feed-rendering.atom-posts-template.structure-and-escaping', $failures, $output );
	}

	private static function check_comments_rss2_template( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::set_posts_query( $case, 'rss2', true );
		$output = self::render_template( 'feed-rss2-comments.php' );

		$xml = self::parse_xml( $output );
		self::collect_failure(
			$failures,
			$xml['ok']
				&& count( $case['comments'] ) === substr_count( $output, '<item>' )
				&& str_contains( $output, 'type="application/rss+xml"' ),
			'RSS2 comments feed is parseable and renders one item per synthetic comment',
			array(
				'xml'       => $xml,
				'itemCount' => substr_count( $output, '<item>' ),
				'preview'   => self::preview( $output ),
			)
		);
		foreach ( $case['comments'] as $comment ) {
			self::collect_failure(
				$failures,
				str_contains( $output, '#comment-' . $comment->comment_ID )
					&& str_contains( $output, self::xml_text( $comment->comment_author ) )
					&& str_contains( $output, esc_html( ent2ncr( $comment->comment_content ) ) ),
				'RSS2 comments feed includes comment GUID, escaped author, and escaped description text',
				array( 'comment' => self::comment_summary( $comment ) )
			);
		}
		self::collect_failure(
			$failures,
			! str_contains( $output, '<script' )
				&& ! str_contains( $output, ']]> inside comment' ),
			'RSS2 comments feed does not emit raw script tags or raw CDATA terminators from comments',
			array( 'preview' => self::preview( $output ) )
		);

		return self::result( $ctx, 'feed-rendering.rss2-comments-template.structure-and-escaping', $failures, $output );
	}

	private static function check_comments_atom_template( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::set_posts_query( $case, 'atom', true );
		$output = self::render_template( 'feed-atom-comments.php' );

		$xml = self::parse_xml( $output );
		self::collect_failure(
			$failures,
			$xml['ok']
				&& count( $case['comments'] ) === substr_count( $output, '<entry>' )
				&& str_contains( $output, 'type="application/atom+xml" href="' . self::url_attr( \get_feed_link( 'comments_atom' ) ) . '"' ),
			'Atom comments feed is parseable and renders one entry per synthetic comment',
			array(
				'xml'        => $xml,
				'entryCount' => substr_count( $output, '<entry>' ),
				'preview'    => self::preview( $output ),
			)
		);

		$parent_comment = $case['comments'][0];
		$child_comment  = $case['comments'][1];
		self::collect_failure(
			$failures,
			str_contains( $output, '<thr:in-reply-to ref="' . self::url_attr( $case['posts'][0]->guid ) . '"' )
				&& str_contains( $output, '<thr:in-reply-to ref="' . self::url_attr( \get_comment_guid( $parent_comment ) ) . '"' )
				&& str_contains( $output, '#comment-' . $child_comment->comment_ID ),
			'Atom comments feed renders both post-level and parent-comment threading references',
			array(
				'parent' => self::comment_summary( $parent_comment ),
				'child'  => self::comment_summary( $child_comment ),
			)
		);
		foreach ( $case['comments'] as $comment ) {
			self::collect_failure(
				$failures,
				str_contains( $output, '<id>' . self::url_attr( \get_comment_guid( $comment ) ) . '</id>' )
					&& str_contains( $output, '<name>' . esc_html( ent2ncr( $comment->comment_author ) ) . '</name>' )
					&& str_contains( $output, '<uri>' . \get_comment_author_url( $comment ) . '</uri>' ),
				'Atom comments feed includes escaped comment GUID, author, and author URI',
				array( 'comment' => self::comment_summary( $comment ) )
			);
		}
		self::collect_failure(
			$failures,
			count( $case['comments'] ) === substr_count( $output, '<content type="html" xml:base="' )
				&& ! str_contains( $output, '<script' )
				&& ! str_contains( $output, ']]> inside comment' ),
			'Atom comments feed emits one HTML content block per comment without raw script or CDATA terminators',
			array( 'contentCount' => substr_count( $output, '<content type="html" xml:base="' ) )
		);

		return self::result( $ctx, 'feed-rendering.atom-comments-template.threading-and-escaping', $failures, $output );
	}

	private static function check_feed_template_hook_payloads( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$outputs  = array();
		$events   = array(
			'rss_tag_pre'        => array(),
			'rss2_ns'            => array(),
			'rss2_comments_ns'   => array(),
			'atom_ns'            => array(),
			'atom_comments_ns'   => array(),
			'rss2_head'          => array(),
			'atom_head'          => array(),
			'commentsrss2_head'  => array(),
			'comments_atom_head' => array(),
			'rss2_item'          => array(),
			'atom_entry'         => array(),
			'commentrss2_item'   => array(),
			'comment_atom_entry' => array(),
		);
		$payload  = array(
			'namespace'         => 'https://component-fuzz.example.test/feed/' . rawurlencode( $case['token'] ),
			'commentsNamespace' => 'https://component-fuzz.example.test/comments/' . rawurlencode( $case['token'] ),
			'attr'              => 'Hook attr "' . self::safe_text( $ctx, 4, 20 ) . '" & <node> CDATA ]]>',
			'text'              => 'Hook payload <node attr="x"> & CDATA ]]> ' . self::safe_text( $ctx, 4, 24 ),
		);
		$hooked   = array();

		$current_context = static function (): array {
			$query = $GLOBALS['wp_query'] ?? null;

			if ( $query instanceof \WP_Query ) {
				return array(
					'feed'          => (string) ( $query->query_vars['feed'] ?? '' ),
					'isCommentFeed' => $query->is_comment_feed(),
				);
			}

			return array(
				'feed'          => '',
				'isCommentFeed' => false,
			);
		};

		$add_action = static function ( string $hook, callable $callback, int $accepted_args = 0 ) use ( &$hooked ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooked[] = array(
				'hook'     => $hook,
				'callback' => $callback,
				'priority' => 10,
			);
		};

		$add_action(
			'rss_tag_pre',
			static function ( string $context ) use ( &$events ): void {
				$events['rss_tag_pre'][] = $context;
			},
			1
		);
		$add_action(
			'rss2_ns',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['rss2_ns'][] = $current_context();
				echo "\n\txmlns:cfz=\"" . self::xml_attr( $payload['namespace'] ) . '"';
			}
		);
		$add_action(
			'rss2_comments_ns',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['rss2_comments_ns'][] = $current_context();
				echo "\n\txmlns:cfzc=\"" . self::xml_attr( $payload['commentsNamespace'] ) . '"';
			}
		);
		$add_action(
			'atom_ns',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['atom_ns'][] = $current_context();
				echo "\n\txmlns:cfz=\"" . self::xml_attr( $payload['namespace'] ) . '"';
			}
		);
		$add_action(
			'atom_comments_ns',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['atom_comments_ns'][] = $current_context();
				echo "\n\txmlns:cfzc=\"" . self::xml_attr( $payload['commentsNamespace'] ) . '"';
			}
		);
		$add_action(
			'rss2_head',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['rss2_head'][] = $current_context();
				echo "\t<cfz:feed-marker data-cfz=\"" . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfz:feed-marker>\n";
			}
		);
		$add_action(
			'atom_head',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['atom_head'][] = $current_context();
				echo "\t<cfz:feed-marker data-cfz=\"" . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfz:feed-marker>\n";
			}
		);
		$add_action(
			'commentsrss2_head',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['commentsrss2_head'][] = $current_context();
				echo "\t<cfzc:comments-feed-marker data-cfz=\"" . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfzc:comments-feed-marker>\n";
			}
		);
		$add_action(
			'comments_atom_head',
			static function () use ( &$events, $payload, $current_context ): void {
				$events['comments_atom_head'][] = $current_context();
				echo "\t<cfzc:comments-feed-marker data-cfz=\"" . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfzc:comments-feed-marker>\n";
			}
		);
		$add_action(
			'rss2_item',
			static function () use ( &$events, $payload ): void {
				$post    = $GLOBALS['post'] ?? null;
				$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;

				$events['rss2_item'][] = $post_id;
				echo "\t\t<cfz:item-marker post-id=\"" . self::xml_attr( (string) $post_id ) . '" data-cfz="' . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfz:item-marker>\n";
			}
		);
		$add_action(
			'atom_entry',
			static function () use ( &$events, $payload ): void {
				$post    = $GLOBALS['post'] ?? null;
				$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;

				$events['atom_entry'][] = $post_id;
				echo "\t\t<cfz:item-marker post-id=\"" . self::xml_attr( (string) $post_id ) . '" data-cfz="' . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfz:item-marker>\n";
			}
		);
		$add_action(
			'commentrss2_item',
			static function ( $comment_id, $comment_post_id ) use ( &$events, $payload ): void {
				$events['commentrss2_item'][] = array(
					'commentId' => (int) $comment_id,
					'postId'    => (int) $comment_post_id,
				);
				echo "\t\t<cfzc:comment-marker comment-id=\"" . self::xml_attr( (string) $comment_id ) . '" post-id="' . self::xml_attr( (string) $comment_post_id ) . '" data-cfz="' . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfzc:comment-marker>\n";
			},
			2
		);
		$add_action(
			'comment_atom_entry',
			static function ( $comment_id, $comment_post_id ) use ( &$events, $payload ): void {
				$events['comment_atom_entry'][] = array(
					'commentId' => (int) $comment_id,
					'postId'    => (int) $comment_post_id,
				);
				echo "\t\t<cfzc:comment-marker comment-id=\"" . self::xml_attr( (string) $comment_id ) . '" post-id="' . self::xml_attr( (string) $comment_post_id ) . '" data-cfz="' . self::xml_attr( $payload['attr'] ) . '">'
					. self::xml_text( $payload['text'] )
					. "</cfzc:comment-marker>\n";
			},
			2
		);

		try {
			self::set_posts_query( $case, 'rss2', false );
			$outputs['rss2'] = self::render_template( 'feed-rss2.php' );

			self::set_posts_query( $case, 'atom', false );
			$outputs['atom'] = self::render_template( 'feed-atom.php' );

			self::set_posts_query( $case, 'rss2', true );
			$outputs['rss2-comments'] = self::render_template( 'feed-rss2-comments.php' );

			self::set_posts_query( $case, 'atom', true );
			$outputs['atom-comments'] = self::render_template( 'feed-atom-comments.php' );
		} finally {
			foreach ( $hooked as $entry ) {
				\remove_action( $entry['hook'], $entry['callback'], $entry['priority'] );
			}
		}

		$leaked_hooks = array();
		foreach ( $hooked as $entry ) {
			if ( false !== \has_filter( $entry['hook'], $entry['callback'] ) ) {
				$leaked_hooks[] = $entry['hook'];
			}
		}

		$parsed = array_map(
			static fn ( string $output ): array => self::parse_xml( $output ),
			$outputs
		);
		$xml_failures = array_filter(
			$parsed,
			static fn ( array $xml ): bool => ! $xml['ok']
		);

		$rss2_context          = array(
			'feed'          => 'rss2',
			'isCommentFeed' => false,
		);
		$atom_context          = array(
			'feed'          => 'atom',
			'isCommentFeed' => false,
		);
		$rss2_comments_context = array(
			'feed'          => 'comments-rss2',
			'isCommentFeed' => true,
		);
		$atom_comments_context = array(
			'feed'          => 'comments-atom',
			'isCommentFeed' => true,
		);
		$expected_post_ids     = array_map(
			static fn ( \WP_Post $post ): int => (int) $post->ID,
			$case['posts']
		);
		$expected_comments     = array_map(
			static fn ( \WP_Comment $comment ): array => array(
				'commentId' => (int) $comment->comment_ID,
				'postId'    => (int) $comment->comment_post_ID,
			),
			$case['comments']
		);
		$combined              = implode( "\n", $outputs );
		$expected_marker_count = 4 + ( 2 * count( $case['posts'] ) ) + ( 2 * count( $case['comments'] ) );

		self::collect_failure(
			$failures,
			array() === $xml_failures,
			'Custom feed-template hook payloads keep all four feed templates parseable XML',
			array( 'parsed' => $parsed )
		);
		self::collect_failure(
			$failures,
			array( 'rss2', 'atom', 'rss2-comments', 'atom-comments' ) === $events['rss_tag_pre']
				&& array( $rss2_context, $rss2_comments_context ) === $events['rss2_ns']
				&& array( $rss2_comments_context ) === $events['rss2_comments_ns']
				&& array( $atom_context, $atom_comments_context ) === $events['atom_ns']
				&& array( $atom_comments_context ) === $events['atom_comments_ns']
				&& array( $rss2_context ) === $events['rss2_head']
				&& array( $atom_context ) === $events['atom_head']
				&& array( $rss2_comments_context ) === $events['commentsrss2_head']
				&& array( $atom_comments_context ) === $events['comments_atom_head'],
			'Feed template namespace and header hooks fire with the expected feed contexts',
			array(
				'rssTagPre'       => $events['rss_tag_pre'],
				'namespaceEvents' => array(
					'rss2'         => $events['rss2_ns'],
					'rss2Comments' => $events['rss2_comments_ns'],
					'atom'         => $events['atom_ns'],
					'atomComments' => $events['atom_comments_ns'],
				),
				'headEvents'      => array(
					'rss2'         => $events['rss2_head'],
					'rss2Comments' => $events['commentsrss2_head'],
					'atom'         => $events['atom_head'],
					'atomComments' => $events['comments_atom_head'],
				),
			)
		);
		self::collect_failure(
			$failures,
			$expected_post_ids === $events['rss2_item']
				&& $expected_post_ids === $events['atom_entry']
				&& $expected_comments === $events['commentrss2_item']
				&& $expected_comments === $events['comment_atom_entry'],
			'Feed item hooks observe current synthetic post/comment IDs in loop order',
			array(
				'expectedPostIds'  => $expected_post_ids,
				'rss2PostIds'      => $events['rss2_item'],
				'atomPostIds'      => $events['atom_entry'],
				'expectedComments' => $expected_comments,
				'rss2Comments'     => $events['commentrss2_item'],
				'atomComments'     => $events['comment_atom_entry'],
			)
		);
		self::collect_failure(
			$failures,
			$expected_marker_count === substr_count( $combined, 'data-cfz="' . self::xml_attr( $payload['attr'] ) . '"' )
				&& $expected_marker_count === substr_count( $combined, self::xml_text( $payload['text'] ) )
				&& str_contains( $outputs['rss2'], 'xmlns:cfz="' . self::xml_attr( $payload['namespace'] ) . '"' )
				&& str_contains( $outputs['atom'], 'xmlns:cfz="' . self::xml_attr( $payload['namespace'] ) . '"' )
				&& str_contains( $outputs['rss2-comments'], 'xmlns:cfzc="' . self::xml_attr( $payload['commentsNamespace'] ) . '"' )
				&& str_contains( $outputs['atom-comments'], 'xmlns:cfzc="' . self::xml_attr( $payload['commentsNamespace'] ) . '"' )
				&& ! str_contains( $combined, '<node attr=' )
				&& ! str_contains( $combined, 'CDATA ]]>' ),
			'Generated hook payloads are escaped in namespace, header, and item output',
			array(
				'expectedMarkerCount' => $expected_marker_count,
				'actualMarkerCount'   => substr_count( $combined, 'data-cfz="' . self::xml_attr( $payload['attr'] ) . '"' ),
				'payload'             => $payload,
			)
		);
		self::collect_failure(
			$failures,
			array() === $leaked_hooks,
			'Temporary feed hook payload callbacks are removed after rendering',
			array( 'leakedHooks' => $leaked_hooks )
		);

		return self::result( $ctx, 'feed-rendering.template-hook-payloads.context-escaping-and-loop-ids', $failures, $combined );
	}

	private static function check_content_mode_switches( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$output   = '';

		foreach ( array( false, true ) as $use_excerpt ) {
			\update_option( 'rss_use_excerpt', $use_excerpt ? 1 : 0 );

			self::set_posts_query( $case, 'rss2', false );
			$rss2_output = self::render_template( 'feed-rss2.php' );
			$rss2_xml    = self::parse_xml( $rss2_output );

			self::set_posts_query( $case, 'atom', false );
			$atom_output = self::render_template( 'feed-atom.php' );
			$atom_xml    = self::parse_xml( $atom_output );

			$output .= $rss2_output . $atom_output;

			$expected_content_count = $use_excerpt ? 0 : count( $case['posts'] );
			self::collect_failure(
				$failures,
				$rss2_xml['ok']
					&& $atom_xml['ok']
					&& count( $case['posts'] ) === substr_count( $rss2_output, '<description><![CDATA[' )
					&& count( $case['posts'] ) === substr_count( $atom_output, '<summary type="html"><![CDATA[' ),
				'RSS2 and Atom posts feeds stay parseable and keep per-post summaries in both content modes',
				array(
					'useExcerpt'          => $use_excerpt,
					'rss2Xml'             => $rss2_xml,
					'atomXml'             => $atom_xml,
					'rss2DescriptionCount' => substr_count( $rss2_output, '<description><![CDATA[' ),
					'atomSummaryCount'    => substr_count( $atom_output, '<summary type="html"><![CDATA[' ),
				)
			);
			self::collect_failure(
				$failures,
				$expected_content_count === substr_count( $rss2_output, '<content:encoded>' )
					&& $expected_content_count === substr_count( $atom_output, '<content type="html" ' ),
				'RSS2 and Atom posts feeds switch full content elements exactly with rss_use_excerpt',
				array(
					'useExcerpt'          => $use_excerpt,
					'expectedContentCount' => $expected_content_count,
					'rss2ContentCount'    => substr_count( $rss2_output, '<content:encoded>' ),
					'atomContentCount'    => substr_count( $atom_output, '<content type="html" ' ),
				)
			);
			self::collect_failure(
				$failures,
				$use_excerpt
					? (
						! str_contains( $rss2_output, 'CDATA close' )
						&& ! str_contains( $atom_output, 'CDATA close' )
					)
					: (
						str_contains( $rss2_output, 'CDATA close ]]&gt; marker' )
						&& str_contains( $atom_output, 'CDATA close ]]&gt; marker' )
						&& ! str_contains( $rss2_output, 'CDATA close ]]> marker' )
						&& ! str_contains( $atom_output, 'CDATA close ]]> marker' )
					),
				'RSS2 and Atom full-content mode escapes CDATA terminators and excerpt mode omits post content',
				array( 'useExcerpt' => $use_excerpt )
			);
		}

		\update_option( 'rss_use_excerpt', $case['rssUseExcerpt'] ? 1 : 0 );

		return self::result( $ctx, 'feed-rendering.posts-template.content-mode-switches', $failures, $output );
	}

	private static function check_feed_link_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		\add_theme_support( 'automatic-feed-links' );

		self::set_posts_query( $case, 'rss2', false );
		$feed_links = self::capture_output(
			static function (): void {
				\feed_links(
					array(
						'separator' => '&',
						'feedtitle' => '%1$s %2$s Posts "Feed"',
						'comstitle' => '%1$s %2$s Comments <Feed>',
					)
				);
			}
		);

		self::collect_failure(
			$failures,
			2 === substr_count( $feed_links, '<link rel="alternate"' )
				&& str_contains( $feed_links, 'type="application/rss+xml"' )
				&& str_contains( $feed_links, 'href="' . self::url_attr( \get_feed_link() ) . '"' )
				&& str_contains( $feed_links, 'href="' . self::url_attr( \get_feed_link( 'comments_rss2' ) ) . '"' ),
			'feed_links renders posts and comments links when automatic feed links are supported',
			array( 'feedLinks' => self::preview( $feed_links ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $feed_links, 'title="Component Feed Fuzz &amp; Posts &quot;Feed&quot;"' )
				&& str_contains( $feed_links, 'title="Component Feed Fuzz &amp; Comments &lt;Feed&gt;"' )
				&& ! str_contains( $feed_links, 'Comments <Feed>' ),
			'feed_links escapes generated title attributes',
			array( 'feedLinks' => self::preview( $feed_links ) )
		);

		self::set_singular_posts_query( $case, 'rss2' );
		$extra_links = self::capture_output(
			static function (): void {
				\feed_links_extra(
					array(
						'separator'   => '&',
						'singletitle' => '%1$s %2$s %3$s Comments "Feed"',
					)
				);
			}
		);

		self::collect_failure(
			$failures,
			1 === substr_count( $extra_links, '<link rel="alternate"' )
				&& str_contains( $extra_links, 'href="' . self::url_attr( \get_post_comments_feed_link( $case['posts'][0]->ID ) ) . '"' )
				&& str_contains( $extra_links, 'Comments &quot;Feed&quot;' )
				&& ! str_contains( $extra_links, '<Title>' ),
			'feed_links_extra renders a singular post comments feed link with escaped title text',
			array( 'feedLinksExtra' => self::preview( $extra_links ) )
		);

		return self::result( $ctx, 'feed-rendering.feed-link-helpers.alternate-links', $failures, $feed_links . $extra_links );
	}

	private static function check_self_link_request_uri_oracles( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures      = array();
		$previous_home = (string) \get_option( 'home' );
		$home_host     = 'feeds-' . $case['token'] . '.example.test';
		$home_port     = $ctx->int( 8000, 8999 );
		$home          = 'http://' . $home_host . ':' . $home_port . '/ignored/base';
		$attacker_host = 'attacker-' . $case['token'] . '.invalid:9443';
		$active_marker = '';
		$filter_events = array();
		$output        = '';
		$self_filter   = static function ( string $feed_link ) use ( &$active_marker, &$filter_events ): string {
			$filter_events[] = $feed_link;

			return $feed_link . '&cfz-marker=' . rawurlencode( $active_marker );
		};
		$request_cases = array(
			array(
				'label' => 'encoded-query',
				'value' => '/feed/' . rawurlencode( $case['token'] ) . '/?title=' . rawurlencode( 'A&B " <feed>' ) . '&view=' . rawurlencode( 'summary/value' ),
				'slash' => false,
			),
			array(
				'label' => 'slashed-superglobal',
				'value' => '/comments/feed/?quote=\'single\'&path=folder\\name&token=' . rawurlencode( $case['token'] ),
				'slash' => true,
			),
			array(
				'label' => 'encoded-unicode-and-empty',
				'value' => '/atom/?empty=&unicode=' . rawurlencode( 'cafe ' . $case['token'] ) . '&encoded=%E2%9C%93',
				'slash' => false,
			),
		);

		\update_option( 'home', $home );
		$_SERVER['HTTP_HOST'] = $attacker_host;
		unset( $_SERVER['HTTPS'] );
		\add_filter( 'self_link', $self_filter, 10, 1 );
		try {
			foreach ( $request_cases as $index => $request_case ) {
				$active_marker          = $request_case['label'] . '-' . self::safe_text( $ctx, 3, 10 );
				$_SERVER['REQUEST_URI'] = $request_case['slash'] ? \wp_slash( $request_case['value'] ) : $request_case['value'];

				$expected_raw      = \set_url_scheme( 'http://' . $home_host . ':' . $home_port . \wp_unslash( $_SERVER['REQUEST_URI'] ) );
				$expected_filtered = \esc_url( $expected_raw . '&cfz-marker=' . rawurlencode( $active_marker ) );
				$raw_self_link     = \get_self_link();
				$rendered_self     = self::capture_output(
					static function (): void {
						\self_link();
					}
				);
				$output           .= $raw_self_link . "\n" . $rendered_self . "\n";

				self::collect_failure(
					$failures,
					$expected_raw === $raw_self_link
						&& str_starts_with( $raw_self_link, 'http://' . $home_host . ':' . $home_port . '/' )
						&& ! str_contains( $raw_self_link, $attacker_host )
						&& ! str_contains( $raw_self_link, '/ignored/base' )
						&& ( ! $request_case['slash'] || ! str_contains( $raw_self_link, "\\'" ) ),
					'get_self_link() derives the feed URL from home host/port plus unslashed REQUEST_URI, not HTTP_HOST or home path',
					array(
						'index'       => $index,
						'requestCase' => $request_case,
						'requestUri'  => $_SERVER['REQUEST_URI'],
						'expected'    => $expected_raw,
						'actual'      => $raw_self_link,
						'home'        => $home,
						'httpHost'    => $attacker_host,
					)
				);
				self::collect_failure(
					$failures,
					$expected_filtered === $rendered_self
						&& ! str_contains( $rendered_self, '<feed>' )
						&& ! str_contains( $rendered_self, '"' ),
					'self_link() applies the self_link filter and escapes the rendered URL for feed attributes',
					array(
						'index'    => $index,
						'expected' => $expected_filtered,
						'actual'   => $rendered_self,
						'marker'   => $active_marker,
					)
				);
			}
		} finally {
			\remove_filter( 'self_link', $self_filter, 10 );
			\update_option( 'home', $previous_home );
		}

		self::collect_failure(
			$failures,
			count( $request_cases ) === count( $filter_events )
				&& false === \has_filter( 'self_link', $self_filter ),
			'self_link request URI oracle removes temporary filters after generated cases',
			array(
				'filterEvents' => $filter_events,
				'hasFilter'    => \has_filter( 'self_link', $self_filter ),
			)
		);

		return self::result( $ctx, 'feed-rendering.self-link.request-uri-host-filter-escaping', $failures, $output );
	}

	private static function check_feed_loop_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::set_posts_query( $case, 'rss2', false );

		$post = $case['posts'][0];
		$GLOBALS['wp_query']->the_post();
		$content_feed = \get_the_content_feed( 'rss2' );

		ob_start();
		\the_excerpt_rss();
		$excerpt_rss = ob_get_clean();

		ob_start();
		\rss_enclosure();
		$rss_enclosure = ob_get_clean();

		ob_start();
		\atom_enclosure();
		$atom_enclosure = ob_get_clean();

		$build_date = \get_feed_build_date( 'Y-m-d\TH:i:s\Z' );

		self::collect_failure(
			$failures,
			str_contains( $content_feed, 'CDATA close ]]&gt; marker' )
				&& ! str_contains( $content_feed, ']]> marker' ),
			'get_the_content_feed escapes CDATA terminators without losing content',
			array( 'contentFeed' => self::preview( $content_feed ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $excerpt_rss, ent2ncr( convert_chars( $post->post_excerpt ) ) )
				&& ! str_contains( $excerpt_rss, '<script' ),
			'the_excerpt_rss applies feed excerpt filters and avoids raw script output',
			array( 'excerpt' => self::preview( $excerpt_rss ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $rss_enclosure, '<enclosure ' )
				&& str_contains( $atom_enclosure, 'rel="enclosure"' )
				&& str_contains( $rss_enclosure, self::url_attr( $case['enclosure']['url'] ) )
				&& str_contains( $atom_enclosure, self::url_attr( $case['enclosure']['url'] ) ),
			'rss_enclosure and atom_enclosure agree on the current post enclosure URL',
			array(
				'rss'  => self::preview( $rss_enclosure ),
				'atom' => self::preview( $atom_enclosure ),
			)
		);
		self::collect_failure(
			$failures,
			$case['expectedBuildDate'] === $build_date,
			'get_feed_build_date selects the latest post modified date for post feeds',
			array(
				'expected' => $case['expectedBuildDate'],
				'actual'   => $build_date,
			)
		);

		self::set_posts_query( $case, 'rss2', true );
		$comment_build_date = \get_feed_build_date( 'Y-m-d\TH:i:s\Z' );
		self::collect_failure(
			$failures,
			$case['expectedCommentBuildDate'] === $comment_build_date,
			'get_feed_build_date includes comment dates for comment feeds',
			array(
				'expected' => $case['expectedCommentBuildDate'],
				'actual'   => $comment_build_date,
			)
		);

		return self::result( $ctx, 'feed-rendering.loop-helpers.content-enclosures-build-date', $failures, $content_feed . $rss_enclosure . $atom_enclosure );
	}

	private static function prepare_case( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb;

		self::reset_runtime();

		$token          = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $ctx->identifier( 4, 12 ) ) );
		$token          = '' === $token ? 'feed-fuzz' : $token;
		$rss_use_excerpt = $ctx->bool();
		\update_option( 'rss_use_excerpt', $rss_use_excerpt ? 1 : 0 );
		$author_display = 'Feed Author ' . self::safe_text( $ctx, 4, 18 ) . ' & <Name>';
		$user_id        = \wp_insert_user(
			array(
				'user_login'   => 'feed_user_' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ),
				'user_pass'    => 'feed-pass',
				'user_email'   => 'feed-author@example.test',
				'display_name' => $author_display,
				'user_url'     => 'https://author.example.test/profile/' . rawurlencode( $token ),
			)
		);

		if ( \is_wp_error( $user_id ) ) {
			throw new \RuntimeException( 'Could not insert feed author: ' . $user_id->get_error_message() );
		}

		$dates = array(
			'2026-06-22 08:15:00',
			'2026-06-22 09:45:30',
		);
		if ( $ctx->bool() ) {
			$dates = array_reverse( $dates );
		}

		$posts = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$title = 'Feed ' . ( $i + 1 ) . ' <Title> & ' . self::safe_text( $ctx, 3, 18 );
			if ( 0 === $i ) {
				$title .= ' CDATA title ]]> marker <script>alert(1)</script>';
			}
			$slug  = \sanitize_title_with_dashes( 'feed-' . $token . '-' . $i, '', 'save' );
			if ( '' === $slug ) {
				$slug = 'feed-' . substr( hash( 'crc32b', $token . ':' . $i ), 0, 10 );
			}

			$post_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_author'       => (int) $user_id,
						'post_date'         => str_replace( '09:', '10:', $dates[ $i ] ),
						'post_date_gmt'     => $dates[ $i ],
						'post_modified'     => str_replace( '09:', '10:', $dates[ $i ] ),
						'post_modified_gmt' => $dates[ $i ],
						'post_title'        => $title,
						'post_name'         => $slug,
						'post_content'      => 'Content <strong>' . self::safe_text( $ctx, 6, 24 ) . '</strong> CDATA close ]]> marker',
						'post_excerpt'      => 'Excerpt & <summary> ' . self::safe_text( $ctx, 4, 18 ),
						'post_status'       => 'publish',
						'post_type'         => 'post',
						'comment_status'    => 'open',
						'ping_status'       => 'closed',
						'guid'              => 'https://example.test/?p=feed-' . rawurlencode( $token ) . '-' . $i,
					)
				),
				true,
				false
			);

			if ( \is_wp_error( $post_id ) ) {
				throw new \RuntimeException( 'Could not insert feed post: ' . $post_id->get_error_message() );
			}

			$posts[] = \get_post( $post_id );
		}

		$enclosure = array(
			'url'    => 'https://media.example.test/' . rawurlencode( $token ) . '/audio-' . $ctx->int( 1, 999 ) . '.mp3?x=' . rawurlencode( self::safe_text( $ctx, 0, 8 ) ) . '&name=' . rawurlencode( 'clip & ' . $token ),
			'length' => (string) $ctx->int( 1, 999999 ),
			'type'   => 'audio/mpeg',
		);
		\add_metadata( 'post', $posts[0]->ID, 'enclosure', $enclosure['url'] . "\n" . $enclosure['length'] . "\n" . $enclosure['type'] );

		$comments          = array();
		$parent_comment_id = 0;
		$comment_posts     = array( $posts[0], $posts[0], $posts[1] );
		foreach ( $comment_posts as $index => $post ) {
			$local_hour = sprintf( '%02d', 11 + $index );
			$gmt_hour   = sprintf( '%02d', 9 + $index );
			$comment_id = \wp_insert_comment(
				array(
					'comment_post_ID'      => $post->ID,
					'comment_author'       => 'Commenter ' . ( $index + 1 ) . ' & <Name>',
					'comment_author_email' => 'commenter-' . $index . '@example.test',
					'comment_author_url'   => 'https://commenter.example.test/' . $index,
					'comment_content'      => 'Comment <b>' . self::safe_text( $ctx, 4, 18 ) . '</b> & escaped',
					'comment_parent'       => 1 === $index ? (string) $parent_comment_id : '0',
					'comment_type'         => 'comment',
					'comment_approved'     => '1',
					'comment_date'         => '2026-06-22 ' . $local_hour . ':05:00',
					'comment_date_gmt'     => '2026-06-22 ' . $gmt_hour . ':05:00',
				)
			);
			if ( 0 === $index ) {
				$parent_comment_id = (int) $comment_id;
			}
			$comments[] = \get_comment( $comment_id );
		}

		$comment_counts = array_fill_keys( wp_list_pluck( $posts, 'ID' ), 0 );
		foreach ( $comments as $comment ) {
			$comment_counts[ $comment->comment_post_ID ]++;
		}
		foreach ( $posts as $post ) {
			$wpdb->update( $wpdb->posts, array( 'comment_count' => (string) $comment_counts[ $post->ID ] ), array( 'ID' => $post->ID ) );
			\wp_cache_delete( $post->ID, 'posts' );
		}
		$posts = array_map( 'get_post', wp_list_pluck( $posts, 'ID' ) );

		$_SERVER['REQUEST_URI'] = '/feed/' . rawurlencode( $token ) . '/?q=' . rawurlencode( self::safe_text( $ctx, 0, 12 ) ) . '&view=' . rawurlencode( 'feed & ' . $token );

		return array(
			'token'                    => $token,
			'posts'                    => $posts,
			'comments'                 => $comments,
			'enclosure'                => $enclosure,
			'authorDisplay'            => $author_display,
			'rssUseExcerpt'            => $rss_use_excerpt,
			'selfLink'                 => 'http://example.test' . $_SERVER['REQUEST_URI'],
			'expectedBuildDate'        => max( array_map( static fn ( \WP_Post $post ): string => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $post->post_modified_gmt . ' UTC' ) ), $posts ) ),
			'expectedCommentBuildDate' => max(
				array_merge(
					array_map( static fn ( \WP_Post $post ): string => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $post->post_modified_gmt . ' UTC' ) ), $posts ),
					array_map( static fn ( \WP_Comment $comment ): string => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $comment->comment_date_gmt . ' UTC' ) ), $comments )
				)
			),
		);
	}

	private static function set_posts_query( array $case, string $feed, bool $comments_feed ): void {
		$query = new \WP_Query();
		$query->init();
		$query->query_vars = array(
			'fields'      => 'all',
			'feed'        => $comments_feed ? 'comments-' . $feed : $feed,
			'withcomments' => $comments_feed ? 1 : 0,
			'withoutcomments' => 0,
			's'           => '',
			'page'        => 1,
			'paged'       => 0,
			'comments_per_page' => count( $case['comments'] ),
		);
		$query->posts             = $case['posts'];
		$query->post_count        = count( $case['posts'] );
		$query->found_posts       = count( $case['posts'] );
		$query->max_num_pages     = 1;
		$query->post              = $case['posts'][0] ?? null;
		$query->queried_object    = $case['posts'][0] ?? null;
		$query->queried_object_id = isset( $case['posts'][0] ) ? (int) $case['posts'][0]->ID : 0;
		$query->is_feed           = true;
		$query->is_home           = ! $comments_feed;
		$query->is_single         = false;
		$query->is_singular       = false;
		$query->is_search         = false;
		$query->is_archive        = false;
		$query->is_404            = false;
		$query->is_comment_feed   = $comments_feed;
		$query->comments          = $comments_feed ? $case['comments'] : array();
		$query->comment_count     = $comments_feed ? count( $case['comments'] ) : 0;
		$query->current_comment   = -1;

		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['post']         = $case['posts'][0] ?? null;
		$GLOBALS['comment']      = $comments_feed ? ( $case['comments'][0] ?? null ) : null;
		$GLOBALS['id']           = isset( $case['posts'][0] ) ? (int) $case['posts'][0]->ID : 0;
	}

	private static function set_singular_posts_query( array $case, string $feed ): void {
		self::set_posts_query( $case, $feed, false );

		$post = $case['posts'][0] ?? null;
		if ( ! $post instanceof \WP_Post || ! $GLOBALS['wp_query'] instanceof \WP_Query ) {
			return;
		}

		$GLOBALS['wp_query']->is_home     = false;
		$GLOBALS['wp_query']->is_single   = true;
		$GLOBALS['wp_query']->is_singular = true;
		$GLOBALS['wp_query']->query_vars['p'] = (int) $post->ID;
		$GLOBALS['wp_the_query']              = $GLOBALS['wp_query'];
	}

	private static function render_template( string $template ): string {
		global $authordata, $comment, $id, $more, $post, $wp_query;

		$path = ABSPATH . WPINC . '/' . $template;
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( 'Feed template missing: ' . $template );
		}

		ob_start();
		try {
			require $path;
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
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

	private static function reset_runtime(): void {
		global $wpdb;

		if ( $wpdb instanceof \Component_Fuzz_WPDB_Stub ) {
			$wpdb->component_fuzz_reset_content();
			$wpdb->component_fuzz_reset_options(
				array(
					'admin_email'              => 'admin@example.test',
					'blog_charset'             => 'UTF-8',
					'blogdescription'          => 'Feed rendering fuzz <description>',
					'blogname'                 => 'Component Feed Fuzz',
					'comments_per_page'        => 25,
					'date_format'              => 'Y-m-d',
					'default_category'         => 1,
					'default_comments_page'    => 'oldest',
					'default_comment_status'   => 'open',
					'default_feed'             => 'rss2',
					'default_ping_status'      => 'closed',
					'gmt_offset'               => 0,
					'home'                     => 'http://example.test',
					'html_type'                => 'text/html',
					'language'                 => 'en',
					'page_comments'            => 0,
					'permalink_structure'      => '/%postname%/',
					'posts_per_rss'            => 10,
					'rewrite_rules'            => array(),
					'rss_use_excerpt'          => 0,
					'show_on_front'            => 'posts',
					'site_icon'                => 0,
					'siteurl'                  => 'http://example.test',
					'thread_comments'          => 0,
					'thread_comments_depth'    => 5,
					'use_smilies'              => 0,
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		$GLOBALS['wp_query']   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['wp_post_types'] = array();
		$GLOBALS['wp_taxonomies'] = array();
		\create_initial_post_types();
		\create_initial_taxonomies();
	}

	private static function install_default_feed_filters(): void {
		\add_filter( 'the_title_rss', 'strip_tags' );
		\add_filter( 'the_title_rss', 'ent2ncr', 8 );
		\add_filter( 'the_title_rss', 'esc_html' );
		\add_filter( 'the_excerpt_rss', 'convert_chars' );
		\add_filter( 'the_excerpt_rss', 'ent2ncr', 8 );
		\add_filter( 'comment_author_rss', 'ent2ncr', 8 );
		\add_filter( 'comment_author_rss', 'esc_html' );
		\add_filter( 'comment_text_rss', 'ent2ncr', 8 );
		\add_filter( 'comment_text_rss', 'esc_html' );
		\add_filter( 'bloginfo_rss', 'ent2ncr', 8 );
		\add_filter( 'the_author', 'ent2ncr', 8 );
		\add_filter( 'the_author', 'esc_html' );
		\add_filter( 'wp_title_rss', 'strip_tags' );
		\add_filter( 'wp_title_rss', 'ent2ncr', 8 );
		\add_filter( 'wp_title_rss', 'esc_html' );
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'_wp_theme_features',
				'authordata',
				'comment',
				'id',
				'more',
				'post',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_post_types',
				'wp_query',
				'wp_rewrite',
				'wp_taxonomies',
				'wp_the_query',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		$server = array();
		foreach ( array( 'HTTP_HOST', 'HTTPS', 'REQUEST_URI' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => array_key_exists( $name, $_SERVER ) ? $_SERVER[ $name ] : null,
			);
		}

		return array(
			'globals' => $globals,
			'server'  => $server,
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
			'counts'  => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			if ( $snapshot['options'] !== $GLOBALS['wpdb']->component_fuzz_get_options() ) {
				return false;
			}
			if ( $snapshot['counts'] !== $GLOBALS['wpdb']->component_fuzz_content_counts() ) {
				return false;
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $_SERVER ) ) {
				return false;
			}
			if ( $entry['exists'] && $entry['value'] !== $_SERVER[ $name ] ) {
				return false;
			}
		}

		return true;
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, string $output ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 10 ),
				'preview'  => self::preview( $output ),
			)
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $details = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'details' => $details,
			);
		}
	}

	private static function parse_xml( string $xml ): array {
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$parsed = simplexml_load_string( $xml );
		$errors = array_map(
			static fn ( \LibXMLError $error ): string => trim( $error->message ) . ' @' . $error->line . ':' . $error->column,
			libxml_get_errors()
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'ok'     => $parsed instanceof \SimpleXMLElement,
			'errors' => array_slice( $errors, 0, 5 ),
		);
	}

	private static function safe_text( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): string {
		$atoms = array(
			'alpha',
			'bravo',
			'cafe',
			'delta & echo',
			'emoji',
			'quote " mark',
			'apostrophe \' mark',
			'angle <tag>',
			'ampersand & value',
			'colon:value',
		);
		$out = '';
		while ( strlen( $out ) < $min ) {
			$out .= ( '' === $out ? '' : ' ' ) . $ctx->choice( $atoms );
		}
		while ( strlen( $out ) < $max && $ctx->bool( 60 ) ) {
			$out .= ' ' . $ctx->choice( $atoms );
		}

		$out = \wp_check_invalid_utf8( $out, true );
		return substr( $out, 0, $max );
	}

	private static function xml_text( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
	}

	private static function xml_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	private static function url_attr( string $url ): string {
		return (string) \esc_url( $url );
	}

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => self::escape_bytes( $value ),
		);
	}

	private static function comment_summary( \WP_Comment $comment ): array {
		return array(
			'id'      => (int) $comment->comment_ID,
			'postId'  => (int) $comment->comment_post_ID,
			'author'  => self::preview( $comment->comment_author ),
			'content' => self::preview( $comment->comment_content ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
		);
	}

	private static function escape_bytes( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$out   = '';
		$shown = min( strlen( $value ), $limit );
		for ( $i = 0; $i < $shown; $i++ ) {
			$byte = ord( $value[ $i ] );
			if ( $byte >= 0x20 && $byte <= 0x7e && 0x5c !== $byte ) {
				$out .= chr( $byte );
			} elseif ( 0x5c === $byte ) {
				$out .= '\\\\';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}
		if ( strlen( $value ) > $limit ) {
			$out .= '...';
		}
		return $out;
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
}
