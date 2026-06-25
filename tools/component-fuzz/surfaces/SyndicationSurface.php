<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-network feed and oEmbed syndication helpers.
 */
final class SyndicationSurface {
	public const NAME = 'syndication';

	private const PREVIEW_BYTES = 180;

	/** @var array<int,array<string,mixed>> */
	private static array $handler_calls = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'syndication.bootstrap-apis-available',
					'Required WordPress syndication APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_embed_handler_lifecycle( $ctx->fork( 'embed-handlers' ) );
			$rows[] = self::check_oembed_provider_registry( $ctx->fork( 'providers' ) );
			$rows[] = self::check_oembed_output_filters( $ctx->fork( 'output-filters' ) );
			$rows[] = self::check_oembed_no_network_shortcuts( $ctx->fork( 'no-network' ) );
			$rows[] = self::check_oembed_rest_controller_cache( $ctx->fork( 'rest-controller' ) );
			$rows[] = self::check_feed_helpers( $ctx->fork( 'feed-helpers' ) );
			$rows[] = self::check_feed_head_link_output( $ctx->fork( 'feed-head-links' ) );
			$rows[] = self::check_feed_link_generation( $ctx->fork( 'feed-links' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'syndication.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	public static function embed_handler( array $matches, array $attr, string $url, array $rawattr ) {
		self::$handler_calls[] = array(
			'matches' => $matches,
			'attr'    => $attr,
			'url'     => $url,
			'rawattr' => $rawattr,
		);

		if ( str_contains( $url, '/deny/' ) ) {
			return false;
		}

		return sprintf(
			'<iframe src="%s" width="%d" height="%d"></iframe>',
			\esc_url( $url ),
			(int) $attr['width'],
			(int) $attr['height']
		);
	}

	public static function filter_blogname( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );
		return 'Component <b>Fuzz</b> & "Feeds"';
	}

	public static function filter_home( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );
		return 'https://example.test';
	}

	public static function filter_blogdescription( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );
		return 'Feed summary <em>RSS</em> & <script>alert(1)</script>';
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'SimpleXMLElement', 'WP_Embed', 'WP_oEmbed', 'WP_Post' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_oembed_create_xml',
				'_oembed_filter_feed_content',
				'add_filter',
				'esc_attr',
				'esc_html',
				'feed_content_type',
				'feed_links',
				'get_bloginfo_rss',
				'get_default_feed',
				'get_feed_link',
				'get_post_comments_feed_link',
				'get_self_link',
				'has_filter',
				'home_url',
				'prep_atom_text_construct',
				'post_comments_feed_link',
				'remove_filter',
				'self_link',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
				'wp_embed_defaults',
				'wp_embed_register_handler',
				'wp_embed_unregister_handler',
				'wp_filter_oembed_iframe_title_attribute',
				'wp_filter_oembed_result',
				'wp_maybe_load_embeds',
				'wp_oembed_get',
				'wp_oembed_ensure_format',
				'wp_parse_args',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_embed_handler_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_embed;

		$failures = array();
		$wp_embed = new \WP_Embed();
		self::$handler_calls = array();

		$token             = self::slug( $ctx, 4, 10 );
		$id                = 'cfz_' . $token;
		$neighbor_id       = $id . '_neighbor';
		$fallback_id       = $id . '_fallback';
		$priority          = $ctx->int( 1, 20 );
		$fallback_priority = $priority + $ctx->int( 1, 10 );
		$url               = 'https://media.example.test/item/' . rawurlencode( self::slug( $ctx, 3, 12 ) );
		$default_width     = $ctx->int( 320, 880 );
		$default_height    = $ctx->int( 180, 720 );
		$raw_width         = $ctx->int( 240, 960 );

		$handler_filter_calls = array();
		$handler_filter       = static function (
			string $return,
			string $filtered_url,
			array $attr
		) use ( &$handler_filter_calls, $token ): string {
			$handler_filter_calls[] = array(
				'url'    => $filtered_url,
				'attr'   => $attr,
				'return' => $return,
			);

			return str_replace( '</iframe>', '<!-- ' . \esc_html( $token ) . ' --></iframe>', $return );
		};

		$defaults_calls  = array();
		$defaults_filter = static function (
			array $size,
			string $defaults_url
		) use ( &$defaults_calls, $default_width, $default_height ): array {
			$defaults_calls[] = array(
				'size' => $size,
				'url'  => $defaults_url,
			);

			return array(
				'width'  => $default_width,
				'height' => $default_height,
			);
		};

		\add_filter( 'embed_handler_html', $handler_filter, 10, 3 );
		\add_filter( 'embed_defaults', $defaults_filter, 10, 2 );
		\wp_embed_register_handler(
			$neighbor_id,
			'~^https://media\.example\.test/other/([^/?#]+)~i',
			array( self::class, 'embed_handler' ),
			$priority
		);
		\wp_embed_register_handler(
			$fallback_id,
			'~^https://media\.example\.test/item/([^/?#]+)~i',
			array( self::class, 'embed_handler' ),
			$fallback_priority
		);
		\wp_embed_register_handler( $id, '~^https://media\.example\.test/item/([^/?#]+)~i', array( self::class, 'embed_handler' ), $priority );

		try {
			$html = $wp_embed->get_embed_handler_html(
				array(
					'width' => $raw_width,
				),
				$url
			);
		} finally {
			\remove_filter( 'embed_handler_html', $handler_filter, 10 );
			\remove_filter( 'embed_defaults', $defaults_filter, 10 );
		}

		self::collect_failure(
			$failures,
			is_string( $html )
				&& 1 === count( self::$handler_calls )
				&& 1 === count( $handler_filter_calls )
				&& 1 === count( $defaults_calls )
				&& str_contains( $html, '<iframe ' )
				&& str_contains( $html, 'src="' . \esc_url( $url ) . '"' )
				&& str_contains( $html, '<!-- ' . \esc_html( $token ) . ' -->' )
				&& isset( $wp_embed->handlers[ $priority ][ $id ] )
				&& isset( $wp_embed->handlers[ $priority ][ $neighbor_id ] )
				&& isset( $wp_embed->handlers[ $fallback_priority ][ $fallback_id ] )
				&& isset( self::$handler_calls[0]['attr']['width'], self::$handler_calls[0]['attr']['height'] )
				&& $raw_width === self::$handler_calls[0]['attr']['width']
				&& $default_height === self::$handler_calls[0]['attr']['height']
				&& self::$handler_calls[0]['rawattr']['width'] === self::$handler_calls[0]['attr']['width']
				&& false === \has_filter( 'embed_handler_html', $handler_filter, 10 )
				&& false === \has_filter( 'embed_defaults', $defaults_filter, 10 ),
			'registered embed handler receives raw/defaulted attributes, honors priority, and scopes filters',
			array(
				'id'                   => $id,
				'html'                 => self::describe_string( is_string( $html ) ? $html : '' ),
				'handlerCalls'         => self::$handler_calls,
				'handlerFilterCalls'   => $handler_filter_calls,
				'defaultsCalls'        => $defaults_calls,
				'handler'              => $wp_embed->handlers[ $priority ][ $id ] ?? null,
				'fallbackHandler'      => $wp_embed->handlers[ $fallback_priority ][ $fallback_id ] ?? null,
				'handlerFilterActive'  => \has_filter( 'embed_handler_html', $handler_filter, 10 ),
				'defaultsFilterActive' => \has_filter( 'embed_defaults', $defaults_filter, 10 ),
			)
		);

		$denied = $wp_embed->get_embed_handler_html( array(), 'https://media.example.test/item/deny/' . $ctx->identifier( 3, 8 ) );
		self::collect_failure(
			$failures,
			false === $denied,
			'handler false return allows get_embed_handler_html to fail closed',
			array( 'denied' => $denied )
		);

		self::$handler_calls = array();
		\wp_embed_unregister_handler( $id, $priority );
		$after_unregister = $wp_embed->get_embed_handler_html( array(), $url );
		self::collect_failure(
			$failures,
			is_string( $after_unregister )
				&& 1 === count( self::$handler_calls )
				&& ! isset( $wp_embed->handlers[ $priority ][ $id ] )
				&& isset( $wp_embed->handlers[ $priority ][ $neighbor_id ] )
				&& isset( $wp_embed->handlers[ $fallback_priority ][ $fallback_id ] ),
			'unregister removes only the selected embed handler and leaves fallbacks available',
			array(
				'afterUnregister' => $after_unregister,
				'handlers'        => $wp_embed->handlers,
				'calls'           => self::$handler_calls,
			)
		);

		\wp_embed_unregister_handler( $neighbor_id, $priority );
		\wp_embed_unregister_handler( $fallback_id, $fallback_priority );

		$load_filter = static fn (): bool => false;
		\add_filter( 'load_default_embeds', $load_filter );
		try {
			$wp_embed->handlers = array();
			\wp_maybe_load_embeds();
			$handlers_after_short_circuit = $wp_embed->handlers;
		} finally {
			\remove_filter( 'load_default_embeds', $load_filter );
		}

		self::collect_failure(
			$failures,
			array() === $handlers_after_short_circuit
				&& false === \has_filter( 'load_default_embeds', $load_filter, 10 ),
			'load_default_embeds filter short-circuits default handler registration locally',
			array(
				'handlers'     => $handlers_after_short_circuit,
				'filterActive' => \has_filter( 'load_default_embeds', $load_filter, 10 ),
			)
		);

		$cache_url    = 'https://cache.example.test/embed/' . rawurlencode( self::slug( $ctx, 4, 12 ) );
		$cache_attr   = array(
			'width'  => $ctx->int( 240, 1024 ),
			'height' => $ctx->int( 180, 900 ),
		);
		$cache_suffix = md5( $cache_url . serialize( \wp_parse_args( $cache_attr, \wp_embed_defaults( $cache_url ) ) ) );
		$cache_post   = self::cached_oembed_post( $ctx->int( 100000, 999999 ) );

		\wp_cache_set( $cache_post->ID, $cache_post, 'posts' );
		\wp_cache_set( $cache_suffix, $cache_post->ID, 'oembed_cache_post' );
		try {
			$cache_hit = $wp_embed->find_oembed_post_id( $cache_suffix );
		} finally {
			\wp_cache_delete( $cache_post->ID, 'posts' );
			\wp_cache_delete( $cache_suffix, 'oembed_cache_post' );
		}

		self::collect_failure(
			$failures,
			$cache_post->ID === $cache_hit,
			'oEmbed cache post lookup honors the generated key suffix through object-cache hits',
			array(
				'cacheUrl'    => $cache_url,
				'cacheAttr'   => $cache_attr,
				'cacheSuffix' => $cache_suffix,
				'cacheHit'    => $cache_hit,
				'postId'      => $cache_post->ID,
			)
		);

		return self::row(
			$ctx,
			'syndication.embed-handler.lifecycle-and-attributes',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_oembed_provider_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$provider_token  = self::slug( $ctx, 3, 8 );
		$watch_token     = self::slug( $ctx, 3, 12 );
		$clip_token      = self::slug( $ctx, 3, 12 );
		$format          = 'http://provider-' . $provider_token . '.example.test/watch/*/clip/*';
		$url             = 'https://provider-' . $provider_token . '.example.test/watch/'
			. rawurlencode( $watch_token ) . '/clip/'
			. rawurlencode( $clip_token ) . '?q=1';
		$near_miss_url   = 'https://provider-' . $provider_token . '.example.test/watch/'
			. rawurlencode( $watch_token ) . '/miss/'
			. rawurlencode( $clip_token );
		$endpoint        = 'https://oembed.example.test/provider.{format}?token=' . rawurlencode( self::slug( $ctx, 3, 10 ) );
		$regex_token     = self::slug( $ctx, 3, 8 );
		$regex_format    = '#https://regex-' . preg_quote( $regex_token, '#' ) . '\.example\.test/items/[a-z0-9-]+$#i';
		$regex_url       = 'https://regex-' . $regex_token . '.example.test/items/' . self::slug( $ctx, 4, 12 );
		$regex_endpoint  = 'https://oembed.example.test/regex.json?token=' . rawurlencode( $regex_token );
		$neighbor_format = 'https://neighbor-' . self::slug( $ctx, 3, 8 ) . '.example.test/watch/*';
		$neighbor_url    = str_replace( '*', rawurlencode( self::slug( $ctx, 3, 12 ) ), $neighbor_format );
		$neighbor_ep     = 'https://oembed.example.test/neighbor.{format}';

		\WP_oEmbed::$early_providers = array();
		\WP_oEmbed::_add_provider_early( $format, $endpoint, false );
		\WP_oEmbed::_add_provider_early( $regex_format, $regex_endpoint, true );
		$oembed        = new \WP_oEmbed();
		$found         = $oembed->get_provider( $url, array( 'discover' => false ) );
		$regex_found   = $oembed->get_provider( $regex_url, array( 'discover' => false ) );
		$near_miss     = $oembed->get_provider( $near_miss_url, array( 'discover' => false ) );
		$provider_copy = $oembed->providers;

		self::collect_failure(
			$failures,
			str_replace( '{format}', 'json', $endpoint ) === $found
				&& $regex_endpoint === $regex_found
				&& false === $near_miss
				&& isset( $provider_copy[ $format ], $provider_copy[ $regex_format ] )
				&& false === $provider_copy[ $format ][1]
				&& true === $provider_copy[ $regex_format ][1]
				&& array() === \WP_oEmbed::$early_providers,
			'early oEmbed providers are consumed, normalized, and matched by wildcard or regex shape',
			array(
				'format'      => $format,
				'url'         => $url,
				'endpoint'    => $endpoint,
				'found'       => $found,
				'regexFormat' => $regex_format,
				'regexUrl'    => $regex_url,
				'regexFound'  => $regex_found,
				'nearMiss'    => $near_miss,
				'early'       => \WP_oEmbed::$early_providers,
			)
		);

		\WP_oEmbed::$early_providers = array();
		\WP_oEmbed::_add_provider_early( $format, $endpoint, false );
		\WP_oEmbed::_add_provider_early( $neighbor_format, $neighbor_ep, false );
		\WP_oEmbed::_remove_provider_early( $format );
		$removed        = new \WP_oEmbed();
		$removed_match  = $removed->get_provider( $url, array( 'discover' => false ) );
		$neighbor_match = $removed->get_provider( $neighbor_url, array( 'discover' => false ) );

		self::collect_failure(
			$failures,
			false === $removed_match
				&& str_replace( '{format}', 'json', $neighbor_ep ) === $neighbor_match
				&& ! isset( $removed->providers[ $format ] )
				&& isset( $removed->providers[ $neighbor_format ] ),
			'early provider removal wins for the selected format without removing neighbors',
			array(
				'removedMatch'  => $removed_match,
				'neighborMatch' => $neighbor_match,
				'removed'       => $removed->providers[ $format ] ?? null,
				'neighbor'      => $removed->providers[ $neighbor_format ] ?? null,
			)
		);

		$photo = $oembed->data2html(
			(object) array(
				'type'   => 'photo',
				'url'    => 'javascript:alert(1)',
				'width'  => 640,
				'height' => 360,
				'title'  => 'Photo "Title" <tag>',
			),
			$url
		);
		$link = $oembed->data2html(
			(object) array(
				'type'  => 'link',
				'title' => 'Link <Title> & more',
			),
			$url
		);

		$dataparse_calls  = array();
		$dataparse_filter = static function ( $return, object $data, string $source_url ) use ( &$dataparse_calls ) {
			$dataparse_calls[] = array(
				'return' => $return,
				'type'   => $data->type ?? null,
				'url'    => $source_url,
			);

			return $return;
		};
		\add_filter( 'oembed_dataparse', $dataparse_filter, 11, 3 );
		try {
			$rich = $oembed->data2html(
				(object) array(
					'type' => 'rich',
					'html' => "<div>\n<iframe src=\"https://player.example.test/embed\"></iframe>\n<pre>keep\nline</pre></div>",
				),
				$url
			);
		} finally {
			\remove_filter( 'oembed_dataparse', $dataparse_filter, 11 );
		}

		self::collect_failure(
			$failures,
			is_string( $photo )
				&& str_contains( $photo, '<img ' )
				&& ! str_contains( strtolower( $photo ), 'javascript:' )
				&& str_contains( $photo, 'alt="Photo &quot;Title&quot; &lt;tag&gt;"' )
				&& is_string( $link )
				&& str_contains( $link, 'Link &lt;Title&gt; &amp; more' )
				&& is_string( $rich )
				&& ! str_contains( $rich, "\n<iframe" )
				&& str_contains( $rich, "keep\nline" )
				&& 1 === count( $dataparse_calls )
				&& false === \has_filter( 'oembed_dataparse', $dataparse_filter, 11 ),
			'WP_oEmbed data2html escapes photo/link fields, strips unsafe protocols, and scopes dataparse filters',
			array(
				'photo'           => self::describe_string( is_string( $photo ) ? $photo : '' ),
				'link'            => self::describe_string( is_string( $link ) ? $link : '' ),
				'rich'            => self::describe_string( is_string( $rich ) ? $rich : '' ),
				'dataparseCalls'  => $dataparse_calls,
				'dataparseFilter' => \has_filter( 'oembed_dataparse', $dataparse_filter, 11 ),
			)
		);

		return self::row(
			$ctx,
			'syndication.oembed.provider-registry-and-data2html',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_oembed_output_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$title    = 'Embed ' . $ctx->text( 0, 32 );
		$url      = 'https://untrusted-' . strtolower( $ctx->identifier( 3, 8 ) ) . '.example.test/watch';
		$data     = (object) array(
			'type'  => 'video',
			'title' => $title,
		);
		$html     = '<blockquote><a href="javascript:alert(1)">bad</a></blockquote>'
			. '<script>alert(1)</script>'
			. '<iframe src="https://player.example.test/embed" width="640" height="360"></iframe>';

		$titled   = \wp_filter_oembed_iframe_title_attribute( '<iframe src="https://player.example.test/embed"></iframe>', $data, $url );
		$filtered = \wp_filter_oembed_result( $html, $data, $url );
		$rejected = \wp_filter_oembed_result( '<blockquote>no iframe</blockquote><script>alert(1)</script>', $data, $url );

		self::collect_failure(
			$failures,
			is_string( $titled )
				&& str_contains( $titled, 'title="' . \esc_attr( $title ) . '"' )
				&& is_string( $filtered )
				&& ! str_contains( strtolower( $filtered ), '<script' )
				&& ! str_contains( strtolower( $filtered ), 'javascript:' )
				&& str_contains( $filtered, 'sandbox="allow-scripts"' )
				&& str_contains( $filtered, 'security="restricted"' )
				&& str_contains( $filtered, 'data-secret=' )
				&& str_contains( $filtered, 'wp-embedded-content' )
				&& false === $rejected,
			'oEmbed filters add iframe titles, sandbox untrusted rich/video HTML, and reject iframe-less payloads',
			array(
				'title'    => self::describe_string( is_string( $titled ) ? $titled : '' ),
				'filtered' => self::describe_string( is_string( $filtered ) ? $filtered : '' ),
				'rejected' => $rejected,
			)
		);

		$existing_title = 'Existing "Title" <safe> ' . self::slug( $ctx, 3, 8 );
		$title_marker   = 'filtered-' . self::slug( $ctx, 3, 8 );
		$title_calls    = array();
		$title_filter   = static function (
			string $candidate,
			string $result,
			object $filter_data,
			string $source_url
		) use ( &$title_calls, $title_marker ): string {
			$title_calls[] = array(
				'title'  => $candidate,
				'result' => $result,
				'type'   => $filter_data->type ?? null,
				'url'    => $source_url,
			);

			return $candidate . ' ' . $title_marker;
		};

		\add_filter( 'oembed_iframe_title_attribute', $title_filter, 10, 4 );
		try {
			$titled_existing = \wp_filter_oembed_iframe_title_attribute(
				'<iframe TITLE="' . \esc_attr( $existing_title ) . '" src="https://player.example.test/embed"></iframe>',
				$data,
				$url
			);
		} finally {
			\remove_filter( 'oembed_iframe_title_attribute', $title_filter, 10 );
		}

		self::collect_failure(
			$failures,
			is_string( $titled_existing )
				&& 1 === substr_count( strtolower( $titled_existing ), 'title=' )
				&& str_contains(
					$titled_existing,
					'title="' . \esc_attr( $existing_title . ' ' . $title_marker ) . '"'
				)
				&& 1 === count( $title_calls )
				&& \esc_attr( $existing_title ) === $title_calls[0]['title']
				&& false === \has_filter( 'oembed_iframe_title_attribute', $title_filter, 10 ),
			'oEmbed iframe title filter prefers existing titles and remains local to the check',
			array(
				'titledExisting' => self::describe_string( is_string( $titled_existing ) ? $titled_existing : '' ),
				'titleCalls'     => $title_calls,
				'filterActive'   => \has_filter( 'oembed_iframe_title_attribute', $title_filter, 10 ),
			)
		);

		$trusted_html     = '<blockquote><script>alert(1)</script><iframe src="https://www.youtube.com/embed/'
			. \esc_attr( self::slug( $ctx, 4, 12 ) )
			. '"></iframe></blockquote>';
		$trusted_filtered = \wp_filter_oembed_result(
			$trusted_html,
			(object) array(
				'type'  => 'video',
				'title' => 'Trusted',
			),
			'https://www.youtube.com/watch?v=' . rawurlencode( self::slug( $ctx, 4, 12 ) )
		);
		$feed_iframe          = '<iframe class="wp-embedded-content" style="position:absolute;visibility:hidden"'
			. ' src="https://player.example.test/embed"></iframe>'
			. '<iframe style="kept" src="https://player.example.test/other"></iframe>';
		$feed_filtered_iframe = \_oembed_filter_feed_content( $feed_iframe );

		self::collect_failure(
			$failures,
			$trusted_html === $trusted_filtered
				&& is_string( $feed_filtered_iframe )
				&& str_contains( $feed_filtered_iframe, 'class="wp-embedded-content"' )
				&& ! str_contains( $feed_filtered_iframe, 'visibility:hidden' )
				&& str_contains( $feed_filtered_iframe, 'style="kept"' ),
			'trusted provider HTML is left untouched while feed iframe filtering only removes embedded-content styles',
			array(
				'trustedFiltered' => self::describe_string( is_string( $trusted_filtered ) ? $trusted_filtered : '' ),
				'feedFiltered'    => self::describe_string( is_string( $feed_filtered_iframe ) ? $feed_filtered_iframe : '' ),
			)
		);

		$xml = \_oembed_create_xml(
			array(
				'type'     => 'rich',
				'html'     => '<iframe title="x"></iframe>',
				'nested'   => array(
					'title' => 'A&B < C',
				),
				'numeric'  => array( 'first', 'second' ),
				'emptyish' => 0,
			)
		);

		$parsed = is_string( $xml ) ? @simplexml_load_string( $xml ) : false;
		self::collect_failure(
			$failures,
			is_string( $xml )
				&& false !== $parsed
				&& str_contains( $xml, '<oembed>' )
				&& str_contains( $xml, '<nested>' )
				&& str_contains( $xml, '<oembed>first</oembed>' )
				&& str_contains( $xml, 'A&amp;B &lt; C' )
				&& false === \_oembed_create_xml( array() )
				&& 'json' === \wp_oembed_ensure_format( 'jsonp' )
				&& 'xml' === \wp_oembed_ensure_format( 'xml' ),
			'_oembed_create_xml serializes nested arrays, escapes text nodes, and rejects empty payloads',
			array( 'xml' => self::describe_string( is_string( $xml ) ? $xml : '' ) )
		);

		return self::row(
			$ctx,
			'syndication.oembed.output-filters-and-xml',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_oembed_no_network_shortcuts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::slug( $ctx, 4, 12 );
		$url      = 'https://shortcircuit-' . $token . '.example.test/watch/' . rawurlencode( self::slug( $ctx, 3, 8 ) );
		$fixture  = '<div class="component-fuzz-oembed">' . \esc_html( $ctx->text( 0, 24 ) ) . '</div>';
		$width    = $ctx->int( 280, 960 );
		$height   = $ctx->int( 180, 720 );

		$pre_calls  = array();
		$http_calls = array();
		$pre_filter = static function ( $pre, string $source_url, $args ) use ( &$pre_calls, $url, $fixture ) {
			$pre_calls[] = array(
				'pre'  => $pre,
				'url'  => $source_url,
				'args' => $args,
			);

			if ( $source_url === $url ) {
				return $fixture;
			}

			return $pre;
		};
		$http_filter = static function ( $preempt, array $parsed_args, string $request_url ) use ( &$http_calls ) {
			$http_calls[] = array(
				'preempt' => $preempt,
				'args'    => $parsed_args,
				'url'     => $request_url,
			);

			return new \WP_Error( 'component_fuzz_no_network', 'Syndication fuzzing blocks live oEmbed HTTP requests.' );
		};

		\add_filter( 'pre_oembed_result', $pre_filter, 10, 3 );
		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		try {
			$result = \wp_oembed_get(
				$url,
				array(
					'width'    => $width,
					'height'   => $height,
					'discover' => true,
				)
			);
		} finally {
			\remove_filter( 'pre_oembed_result', $pre_filter, 10 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$fixture === $result
				&& 1 === count( $pre_calls )
				&& array() === $http_calls
				&& false === \has_filter( 'pre_oembed_result', $pre_filter, 10 )
				&& false === \has_filter( 'pre_http_request', $http_filter, 10 ),
			'pre_oembed_result short-circuits wp_oembed_get before provider discovery or HTTP',
			array(
				'result'           => self::describe_string( is_string( $result ) ? $result : '' ),
				'preCalls'         => $pre_calls,
				'httpCalls'        => $http_calls,
				'preFilterActive'  => \has_filter( 'pre_oembed_result', $pre_filter, 10 ),
				'httpFilterActive' => \has_filter( 'pre_http_request', $http_filter, 10 ),
			)
		);

		$provider_url  = 'https://oembed-no-network.example.test/endpoint?token=' . rawurlencode( $token );
		$target_url    = 'https://target-' . $token . '.example.test/watch/'
			. rawurlencode( self::slug( $ctx, 4, 12 ) )
			. '?a=1&b=' . rawurlencode( $ctx->text( 0, 10 ) );
		$fetch_width   = $ctx->int( 320, 1280 );
		$fetch_height  = $ctx->int( 180, 900 );
		$fetch_calls   = array();
		$fetch_filter  = static function ( string $fetch_url, string $source_url, array $args ) use ( &$fetch_calls ): string {
			$fetch_calls[] = array(
				'fetchUrl' => $fetch_url,
				'url'      => $source_url,
				'args'     => $args,
			);

			return $fetch_url;
		};
		$fetch_http    = array();
		$fetch_blocker = static function ( $preempt, array $parsed_args, string $request_url ) use ( &$fetch_http ) {
			$fetch_http[] = array(
				'preempt' => $preempt,
				'args'    => $parsed_args,
				'url'     => $request_url,
			);

			return new \WP_Error( 'component_fuzz_no_network', 'Syndication fuzzing blocks live oEmbed fetch HTTP requests.' );
		};
		$oembed        = new \WP_oEmbed();

		\add_filter( 'oembed_fetch_url', $fetch_filter, 10, 3 );
		\add_filter( 'pre_http_request', $fetch_blocker, 10, 3 );
		try {
			$fetched = $oembed->fetch(
				$provider_url,
				$target_url,
				array(
					'width'  => $fetch_width,
					'height' => $fetch_height,
				)
			);
		} finally {
			\remove_filter( 'oembed_fetch_url', $fetch_filter, 10 );
			\remove_filter( 'pre_http_request', $fetch_blocker, 10 );
		}

		$query = array();
		if ( isset( $fetch_http[0]['url'] ) ) {
			parse_str( (string) parse_url( $fetch_http[0]['url'], PHP_URL_QUERY ), $query );
		}

		self::collect_failure(
			$failures,
			false === $fetched
				&& 1 === count( $fetch_calls )
				&& 1 === count( $fetch_http )
				&& (string) $fetch_width === (string) ( $query['maxwidth'] ?? null )
				&& (string) $fetch_height === (string) ( $query['maxheight'] ?? null )
				&& $target_url === ( $query['url'] ?? null )
				&& '1' === (string) ( $query['dnt'] ?? null )
				&& 'json' === ( $query['format'] ?? null )
				&& false === \has_filter( 'oembed_fetch_url', $fetch_filter, 10 )
				&& false === \has_filter( 'pre_http_request', $fetch_blocker, 10 ),
			'WP_oEmbed::fetch generates provider query arguments before the local HTTP blocker fails closed',
			array(
				'fetched'           => $fetched,
				'fetchCalls'        => $fetch_calls,
				'httpCalls'         => $fetch_http,
				'query'             => $query,
				'fetchFilterActive' => \has_filter( 'oembed_fetch_url', $fetch_filter, 10 ),
				'httpFilterActive'  => \has_filter( 'pre_http_request', $fetch_blocker, 10 ),
			)
		);

		return self::row(
			$ctx,
			'syndication.oembed.short-circuited-no-network',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_oembed_rest_controller_cache( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::load_oembed_controller() ) {
			return self::skip(
				$ctx,
				'syndication.oembed.rest-controller-cache',
				'WP_oEmbed_Controller is unavailable in this checkout.'
			);
		}

		foreach ( array( 'WP_REST_Request', 'WP_Error' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return self::skip(
					$ctx,
					'syndication.oembed.rest-controller-cache',
					"Class {$class} is unavailable."
				);
			}
		}

		foreach ( array( 'delete_transient', 'get_transient', 'rest_authorization_required_code', 'set_transient' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip(
					$ctx,
					'syndication.oembed.rest-controller-cache',
					"Function {$function} is unavailable."
				);
			}
		}

		$failures   = array();
		$controller = new \WP_oEmbed_Controller();
		$token      = self::slug( $ctx, 5, 12 );
		$url        = 'https://rest-oembed.example.test/watch/' . rawurlencode( $token );
		$args       = array(
			'url'       => $url,
			'format'    => 'json',
			'maxwidth'  => $ctx->int( 240, 960 ),
			'maxheight' => $ctx->int( 120, 720 ),
			'discover'  => (bool) $ctx->int( 0, 1 ),
		);
		$cache_key  = 'oembed_' . md5( serialize( $args ) );
		$cached     = (object) array(
			'provider_name' => 'Component Fuzz Provider',
			'type'          => 'rich',
			'html'          => '<blockquote data-token="' . \esc_attr( $token ) . '"></blockquote>',
			'width'         => $args['maxwidth'],
			'height'        => $args['maxheight'],
		);
		$request    = new \WP_REST_Request( 'GET', '/oembed/1.0/proxy' );
		foreach ( $args as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_param( '_wpnonce', 'nonce-' . $token );

		$permission = $controller->get_proxy_item_permissions_check();

		\set_transient( $cache_key, $cached, 60 );
		try {
			$result = $controller->get_proxy_item( $request );
		} finally {
			\delete_transient( $cache_key );
		}
		$after_cleanup = \get_transient( $cache_key );

		self::collect_failure(
			$failures,
			$permission instanceof \WP_Error
				&& 'rest_forbidden' === $permission->get_error_code()
				&& \rest_authorization_required_code() === ( $permission->get_error_data()['status'] ?? null ),
			'oEmbed REST proxy permission check fails closed without edit_posts capability',
			array(
				'permission' => $permission instanceof \WP_Error ? array(
					'code' => $permission->get_error_code(),
					'data' => $permission->get_error_data(),
				) : $permission,
			)
		);

		self::collect_failure(
			$failures,
			is_object( $result )
				&& $cached->provider_name === ( $result->provider_name ?? null )
				&& $cached->html === ( $result->html ?? null )
				&& $cached->width === ( $result->width ?? null )
				&& $cached->height === ( $result->height ?? null )
				&& false === $after_cleanup,
			'oEmbed REST proxy returns cached data using nonce-free cache keys and cleans up transients',
			array(
				'cacheKey'      => $cache_key,
				'requestParams' => $request->get_params(),
				'result'        => is_object( $result ) ? get_object_vars( $result ) : $result,
				'cached'        => get_object_vars( $cached ),
				'afterCleanup'  => $after_cleanup,
			)
		);

		return self::row(
			$ctx,
			'syndication.oembed.rest-controller-cache',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_feed_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		\add_filter( 'pre_option_blogname', array( self::class, 'filter_blogname' ), 10, 3 );
		\add_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10, 3 );
		\add_filter( 'pre_option_blogdescription', array( self::class, 'filter_blogdescription' ), 10, 3 );
		try {
			$bloginfo               = \get_bloginfo_rss( 'name' );
			$blogdescription        = \get_bloginfo_rss( 'description' );
			$_SERVER['HTTP_HOST']   = 'attacker.example.test';
			$_SERVER['REQUEST_URI'] = '/feed/'
				. rawurlencode( self::slug( $ctx, 3, 12 ) )
				. '/?q=' . rawurlencode( $ctx->text( 0, 16 ) )
				. '&danger=<script>';
			$self_link              = \get_self_link();
			$self_link_marker       = self::slug( $ctx, 3, 8 );
			$expected_filtered      = $self_link . '&cfz=' . rawurlencode( $self_link_marker );
			$self_link_filter_calls = array();
			$self_link_filter       = static function (
				string $feed_link
			) use ( &$self_link_filter_calls, $self_link_marker ): string {
				$self_link_filter_calls[] = $feed_link;

				return $feed_link . '&cfz=' . rawurlencode( $self_link_marker );
			};

			\add_filter( 'self_link', $self_link_filter );
			ob_start();
			\self_link();
			$self_link_output = (string) ob_get_clean();
		} finally {
			if ( isset( $self_link_filter ) ) {
				\remove_filter( 'self_link', $self_link_filter );
			}
			\remove_filter( 'pre_option_blogname', array( self::class, 'filter_blogname' ), 10 );
			\remove_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10 );
			\remove_filter( 'pre_option_blogdescription', array( self::class, 'filter_blogdescription' ), 10 );
		}

		$rss_filter = static fn (): string => 'rss';
		$atom_filter = static fn (): string => 'atom';
		\add_filter( 'default_feed', $rss_filter );
		$rss_default = \get_default_feed();
		\remove_filter( 'default_feed', $rss_filter );
		\add_filter( 'default_feed', $atom_filter );
		$atom_default = \get_default_feed();
		\remove_filter( 'default_feed', $atom_filter );

		$content_type_calls  = array();
		$custom_feed_type    = 'cfz-' . self::slug( $ctx, 3, 8 );
		$custom_content_type = 'application/x-' . $custom_feed_type . '+xml';
		$content_filter      = static function (
			string $content_type,
			string $type
		) use ( &$content_type_calls, $custom_feed_type, $custom_content_type ): string {
			$content_type_calls[] = array(
				'contentType' => $content_type,
				'type'        => $type,
			);

			return $custom_feed_type === $type ? $custom_content_type : $content_type;
		};
		\add_filter( 'feed_content_type', $content_filter, 10, 2 );
		$filtered_content_type = \feed_content_type( $custom_feed_type );
		\remove_filter( 'feed_content_type', $content_filter, 10 );
		$unfiltered_content_type = \feed_content_type( $custom_feed_type );

		self::collect_failure(
			$failures,
			'Component Fuzz &#038; "Feeds"' === $bloginfo
				&& ! str_contains( $blogdescription, '<' )
				&& ! str_contains( strtolower( $blogdescription ), 'script' )
				&& str_contains( $blogdescription, '&#038;' )
				&& 'rss2' === $rss_default
				&& 'atom' === $atom_default
				&& 'application/rss+xml' === \feed_content_type( 'rss2' )
				&& 'application/atom+xml' === \feed_content_type( 'atom' )
				&& str_starts_with( $self_link, 'https://example.test/feed/' )
				&& ! str_contains( $self_link, 'attacker.example.test' )
				&& $self_link_output === \esc_url( $expected_filtered )
				&& 1 === count( $self_link_filter_calls )
				&& $custom_content_type === $filtered_content_type
				&& 'application/octet-stream' === $unfiltered_content_type
				&& 1 === count( $content_type_calls )
				&& false === \has_filter( 'feed_content_type', $content_filter, 10 )
				&& false === \has_filter( 'self_link', $self_link_filter, 10 ),
			'feed helpers escape bloginfo, normalize default feed, map content types, and render filtered self links safely',
			array(
				'bloginfo'              => $bloginfo,
				'blogdescription'       => $blogdescription,
				'rssDefault'            => $rss_default,
				'atomDefault'           => $atom_default,
				'selfLink'              => $self_link,
				'selfLinkOutput'        => $self_link_output,
				'expectedFiltered'      => $expected_filtered,
				'selfLinkFilterCalls'   => $self_link_filter_calls,
				'filteredContentType'   => $filtered_content_type,
				'unfilteredContentType' => $unfiltered_content_type,
				'contentTypeCalls'      => $content_type_calls,
			)
		);

		$plain = \prep_atom_text_construct( 'plain text' );
		$xhtml = \prep_atom_text_construct( '<strong>ok</strong>' );
		$html  = \prep_atom_text_construct( '<strong>broken' );
		$cdata = \prep_atom_text_construct( 'bad ]]> marker <em>x</em> &' );

		self::collect_failure(
			$failures,
			array( 'text', 'plain text' ) === $plain
				&& 'xhtml' === $xhtml[0]
				&& str_contains( $xhtml[1], "xmlns='http://www.w3.org/1999/xhtml'" )
				&& 'html' === $html[0]
				&& str_contains( $html[1], '<![CDATA[' )
				&& 'html' === $cdata[0]
				&& ! str_contains( $cdata[1], ']]>' )
				&& str_contains( $cdata[1], '&gt;' )
				&& str_contains( $cdata[1], '&lt;em&gt;' ),
			'prep_atom_text_construct partitions plain, xhtml, CDATA, and escaped CDATA-terminator payloads',
			array(
				'plain' => $plain,
				'xhtml' => $xhtml,
				'html'  => $html,
				'cdata' => $cdata,
			)
		);

		return self::row(
			$ctx,
			'syndication.feed.helpers-and-atom-text',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_feed_head_link_output( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$marker   = self::slug( $ctx, 4, 12 );

		$GLOBALS['wp_rewrite'] = self::plain_rewrite_stub();
		\add_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10, 3 );
		\add_filter( 'pre_option_blogname', array( self::class, 'filter_blogname' ), 10, 3 );

		$unsupported_output  = '';
		$posts_only_output   = '';
		$comments_only_output = '';
		$default_feed_filter = null;
		$args_filter         = null;
		$link_filter         = null;
		$posts_gate_filter   = null;
		$posts_off_filter    = null;
		$comments_off_filter = null;
		$comments_on_filter  = null;
		$buffer_level        = ob_get_level();
		try {
			unset( $GLOBALS['_wp_theme_features']['automatic-feed-links'] );
			ob_start();
			\feed_links();
			$unsupported_output = (string) ob_get_clean();

			$GLOBALS['_wp_theme_features']['automatic-feed-links'] = true;
			$default_feed_filter = static fn (): string => 'atom';
			$args_calls          = array();
			$args_filter         = static function ( array $args ) use ( &$args_calls, $marker ): array {
				$args_calls[] = $args;

				return array_merge(
					$args,
					array(
						'separator' => '::' . $marker . '::',
						'feedtitle' => '%1$s <b>%2$s</b> Feed',
						'comstitle' => '%1$s <i>%2$s</i> Comments',
					)
				);
			};
			$link_calls          = array();
			$link_filter         = static function ( string $url, string $feed ) use ( &$link_calls, $marker ): string {
				$link_calls[] = array(
					'url'  => $url,
					'feed' => $feed,
				);

				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'cfz=' . rawurlencode( $marker );
			};

			$posts_gate_calls    = array();
			$posts_gate_filter   = static function ( bool $show ) use ( &$posts_gate_calls ): bool {
				$posts_gate_calls[] = $show;
				return true;
			};
			$comments_gate_calls = array();
			$comments_off_filter = static function ( bool $show ) use ( &$comments_gate_calls ): bool {
				$comments_gate_calls[] = $show;
				return false;
			};

			\add_filter( 'default_feed', $default_feed_filter );
			\add_filter( 'feed_links_args', $args_filter );
			\add_filter( 'feed_link', $link_filter, 10, 2 );
			\add_filter( 'feed_links_show_posts_feed', $posts_gate_filter );
			\add_filter( 'feed_links_show_comments_feed', $comments_off_filter );
			ob_start();
			\feed_links();
			$posts_only_output = (string) ob_get_clean();
			\remove_filter( 'feed_links_show_posts_feed', $posts_gate_filter );
			\remove_filter( 'feed_links_show_comments_feed', $comments_off_filter );

			$posts_off_filter = static function ( bool $show ) use ( &$posts_gate_calls ): bool {
				$posts_gate_calls[] = $show;
				return false;
			};
			$comments_on_filter = static function ( bool $show ) use ( &$comments_gate_calls ): bool {
				$comments_gate_calls[] = $show;
				return true;
			};

			\add_filter( 'feed_links_show_posts_feed', $posts_off_filter );
			\add_filter( 'feed_links_show_comments_feed', $comments_on_filter );
			ob_start();
			\feed_links();
			$comments_only_output = (string) ob_get_clean();
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			if ( null !== $posts_gate_filter ) {
				\remove_filter( 'feed_links_show_posts_feed', $posts_gate_filter );
			}
			if ( null !== $posts_off_filter ) {
				\remove_filter( 'feed_links_show_posts_feed', $posts_off_filter );
			}
			if ( null !== $comments_off_filter ) {
				\remove_filter( 'feed_links_show_comments_feed', $comments_off_filter );
			}
			if ( null !== $comments_on_filter ) {
				\remove_filter( 'feed_links_show_comments_feed', $comments_on_filter );
			}
			if ( null !== $link_filter ) {
				\remove_filter( 'feed_link', $link_filter, 10 );
			}
			if ( null !== $args_filter ) {
				\remove_filter( 'feed_links_args', $args_filter );
			}
			if ( null !== $default_feed_filter ) {
				\remove_filter( 'default_feed', $default_feed_filter );
			}
			\remove_filter( 'pre_option_blogname', array( self::class, 'filter_blogname' ), 10 );
			\remove_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10 );
		}

		$posts_link    = 'https://example.test/?feed=atom&cfz=' . rawurlencode( $marker );
		$comments_link = 'https://example.test/?feed=comments-atom&cfz=' . rawurlencode( $marker );

		self::collect_failure(
			$failures,
			'' === $unsupported_output
				&& 1 === substr_count( $posts_only_output, '<link ' )
				&& 1 === substr_count( $comments_only_output, '<link ' )
				&& str_contains( $posts_only_output, 'type="application/atom+xml"' )
				&& str_contains( $comments_only_output, 'type="application/atom+xml"' )
				&& str_contains( $posts_only_output, 'title="Component &lt;b&gt;Fuzz&lt;/b&gt; &amp; &quot;Feeds&quot; &lt;b&gt;::' . \esc_attr( $marker ) . '::&lt;/b&gt; Feed"' )
				&& str_contains( $comments_only_output, 'title="Component &lt;b&gt;Fuzz&lt;/b&gt; &amp; &quot;Feeds&quot; &lt;i&gt;::' . \esc_attr( $marker ) . '::&lt;/i&gt; Comments"' )
				&& str_contains( $posts_only_output, 'href="' . \esc_url( $posts_link ) . '"' )
				&& str_contains( $comments_only_output, 'href="' . \esc_url( $comments_link ) . '"' )
				&& ! str_contains( $posts_only_output, 'Comments' )
				&& ! str_contains( $comments_only_output, ' Feed"' )
				&& array( true, true ) === $posts_gate_calls
				&& array( true, true ) === $comments_gate_calls
				&& array( 'atom', 'comments-atom' ) === array_column( $link_calls, 'feed' )
				&& 2 === count( $args_calls )
				&& false === \has_filter( 'default_feed', $default_feed_filter, 10 )
				&& false === \has_filter( 'feed_links_args', $args_filter, 10 )
				&& false === \has_filter( 'feed_link', $link_filter, 10 )
				&& false === \has_filter( 'feed_links_show_posts_feed', $posts_gate_filter, 10 )
				&& false === \has_filter( 'feed_links_show_posts_feed', $posts_off_filter, 10 )
				&& false === \has_filter( 'feed_links_show_comments_feed', $comments_off_filter, 10 )
				&& false === \has_filter( 'feed_links_show_comments_feed', $comments_on_filter, 10 ),
			'feed_links honors theme support, posts/comments gates, default feed normalization, and escaped head output',
			array(
				'unsupportedOutput' => self::describe_string( $unsupported_output ?? '' ),
				'postsOnlyOutput'   => self::describe_string( $posts_only_output ?? '' ),
				'commentsOnlyOutput' => self::describe_string( $comments_only_output ?? '' ),
				'postsGateCalls'    => $posts_gate_calls,
				'commentsGateCalls' => $comments_gate_calls,
				'linkCalls'         => $link_calls,
				'argsCalls'         => $args_calls,
			)
		);

		return self::row(
			$ctx,
			'syndication.feed.head-link-output-and-gates',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_feed_link_generation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$post_id       = $ctx->int( 100000, 199999 );
		$page_id       = $ctx->int( 200000, 299999 );
		$attachment_id = $ctx->int( 300000, 399999 );
		$missing_id    = $ctx->int( 400000, 499999 );
		$marker        = self::slug( $ctx, 4, 12 );

		$post       = self::cached_feed_post( $post_id, 'post' );
		$page       = self::cached_feed_post( $page_id, 'page' );
		$attachment = self::cached_feed_post( $attachment_id, 'attachment' );

		$plain_permalink_filter = static fn (): string => '';
		$link_filter_calls      = array();
		$link_filter            = static function ( string $url ) use ( &$link_filter_calls, $marker ): string {
			$link_filter_calls[] = $url;

			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'cfz=' . rawurlencode( $marker );
		};

		$html_filter_calls = array();
		$html_filter       = static function (
			string $link,
			int $filtered_post_id,
			string $feed
		) use ( &$html_filter_calls, $marker ): string {
			$html_filter_calls[] = array(
				'link'   => $link,
				'postId' => $filtered_post_id,
				'feed'   => $feed,
			);

			return str_replace( '</a>', '<span data-cfz="' . \esc_attr( $marker ) . '"></span></a>', $link );
		};

		\wp_cache_set( $post_id, $post, 'posts' );
		\wp_cache_set( $page_id, $page, 'posts' );
		\wp_cache_set( $attachment_id, $attachment, 'posts' );
		$GLOBALS['wp_rewrite'] = self::plain_rewrite_stub();
		\add_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10, 3 );
		\add_filter( 'pre_option_permalink_structure', $plain_permalink_filter );
		$buffer_level = ob_get_level();
		try {
			$comments_atom_feed = \get_feed_link( 'comments_atom' );
			$post_rss_feed      = \get_post_comments_feed_link( $post_id, 'rss2' );
			$page_atom_feed     = \get_post_comments_feed_link( $page_id, 'atom' );
			$attachment_feed    = \get_post_comments_feed_link( $attachment_id, 'atom' );
			$missing_feed       = \get_post_comments_feed_link( $missing_id, 'rss2' );

			\add_filter( 'post_comments_feed_link', $link_filter );
			$filtered_post_feed = \get_post_comments_feed_link( $post_id, 'atom' );
			\remove_filter( 'post_comments_feed_link', $link_filter );

			$link_text = 'Comment feed <strong>' . \esc_html( $marker ) . '</strong> & raw';
			\add_filter( 'post_comments_feed_link_html', $html_filter, 10, 3 );
			ob_start();
			\post_comments_feed_link( $link_text, $page_id, 'atom' );
			$link_output = (string) ob_get_clean();
			\remove_filter( 'post_comments_feed_link_html', $html_filter, 10 );
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			\remove_filter( 'post_comments_feed_link', $link_filter );
			\remove_filter( 'post_comments_feed_link_html', $html_filter, 10 );
			\remove_filter( 'pre_option_permalink_structure', $plain_permalink_filter );
			\remove_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10 );
			\wp_cache_delete( $post_id, 'posts' );
			\wp_cache_delete( $page_id, 'posts' );
			\wp_cache_delete( $attachment_id, 'posts' );
		}

		$post_query       = self::query_args( $post_rss_feed ?? '' );
		$page_query       = self::query_args( $page_atom_feed ?? '' );
		$attachment_query = self::query_args( $attachment_feed ?? '' );
		$filtered_query   = self::query_args( $filtered_post_feed ?? '' );

		self::collect_failure(
			$failures,
			is_string( $comments_atom_feed ?? null )
				&& str_starts_with( $comments_atom_feed, 'https://example.test' )
				&& array( 'feed' => 'comments-atom' ) === self::query_args( $comments_atom_feed ),
			'get_feed_link normalizes comments_* feed names in plain permalink mode',
			array( 'commentsAtomFeed' => $comments_atom_feed ?? null )
		);

		self::collect_failure(
			$failures,
			'https' === parse_url( $post_rss_feed ?? '', PHP_URL_SCHEME )
				&& 'example.test' === parse_url( $post_rss_feed ?? '', PHP_URL_HOST )
				&& array(
					'feed' => 'rss2',
					'p'    => (string) $post_id,
				) === $post_query
				&& array(
					'feed'    => 'atom',
					'page_id' => (string) $page_id,
				) === $page_query
				&& array(
					'attachment_id' => (string) $attachment_id,
					'feed'          => 'atom',
				) === $attachment_query
				&& '' === $missing_feed,
			'post comment feed links use the correct plain-query key for posts, pages, unattached attachments, and missing posts',
			array(
				'postFeed'       => $post_rss_feed ?? null,
				'pageFeed'       => $page_atom_feed ?? null,
				'attachmentFeed' => $attachment_feed ?? null,
				'missingFeed'    => $missing_feed ?? null,
			)
		);

		self::collect_failure(
			$failures,
			array(
				'cfz'  => $marker,
				'feed' => 'atom',
				'p'    => (string) $post_id,
			) === $filtered_query
				&& 1 === count( $link_filter_calls )
				&& false === \has_filter( 'post_comments_feed_link', $link_filter, 10 ),
			'post_comments_feed_link filter receives the generated URL and remains local',
			array(
				'filteredPostFeed' => $filtered_post_feed ?? null,
				'filterCalls'      => $link_filter_calls,
				'filterActive'     => \has_filter( 'post_comments_feed_link', $link_filter, 10 ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $link_output ?? null )
				&& str_contains( $link_output, 'href="' . \esc_url( $page_atom_feed ?? '' ) . '"' )
				&& str_contains( $link_output, $link_text )
				&& str_contains( $link_output, '<span data-cfz="' . \esc_attr( $marker ) . '"></span>' )
				&& 1 === count( $html_filter_calls )
				&& $page_id === $html_filter_calls[0]['postId']
				&& 'atom' === $html_filter_calls[0]['feed']
				&& false === \has_filter( 'post_comments_feed_link_html', $html_filter, 10 ),
			'post_comments_feed_link emits escaped hrefs, preserves caller link text, and scopes the HTML filter',
			array(
				'linkOutput'      => self::describe_string( is_string( $link_output ?? null ) ? $link_output : '' ),
				'htmlFilterCalls' => $html_filter_calls,
				'filterActive'    => \has_filter( 'post_comments_feed_link_html', $html_filter, 10 ),
			)
		);

		return self::row(
			$ctx,
			'syndication.feed.link-generation-and-filters',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, int $min = 3, int $max = 12 ): string {
		$raw  = strtolower( $ctx->identifier( $min, $max ) );
		$slug = (string) preg_replace( '/[^a-z0-9-]+/', '-', $raw );
		$slug = trim( $slug, '-' );

		if ( '' === $slug ) {
			return substr( hash( 'crc32b', $raw ), 0, 8 );
		}

		return $slug;
	}

	private static function cached_oembed_post( int $post_id ): object {
		return (object) array(
			'ID'                    => $post_id,
			'post_author'           => 0,
			'post_date'             => '2024-01-01 00:00:00',
			'post_date_gmt'         => '2024-01-01 00:00:00',
			'post_content'          => '<div class="cached-oembed"></div>',
			'post_title'            => 'Cached oEmbed',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'cached-oembed-' . $post_id,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 00:00:00',
			'post_modified_gmt'     => '2024-01-01 00:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => '',
			'menu_order'            => 0,
			'post_type'             => 'oembed_cache',
			'post_mime_type'        => '',
			'comment_count'         => 0,
			'filter'                => 'raw',
		);
	}

	private static function cached_feed_post( int $post_id, string $post_type, int $post_parent = 0 ): object {
		return (object) array(
			'ID'                    => $post_id,
			'post_author'           => 0,
			'post_date'             => '2024-01-01 00:00:00',
			'post_date_gmt'         => '2024-01-01 00:00:00',
			'post_content'          => '',
			'post_title'            => 'Syndication Feed Post ' . $post_id,
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'syndication-feed-post-' . $post_id,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 00:00:00',
			'post_modified_gmt'     => '2024-01-01 00:00:00',
			'post_content_filtered' => '',
			'post_parent'           => $post_parent,
			'guid'                  => '',
			'menu_order'            => 0,
			'post_type'             => $post_type,
			'post_mime_type'        => 'attachment' === $post_type ? 'image/jpeg' : '',
			'comment_count'         => 0,
			'filter'                => 'raw',
		);
	}

	private static function query_args( string $url ): array {
		$query = parse_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return array();
		}

		$args = array();
		parse_str( $query, $args );
		ksort( $args );
		return array_map( 'strval', $args );
	}

	private static function plain_rewrite_stub(): object {
		return new class() {
			public function get_feed_permastruct(): string {
				return '';
			}

			public function get_comment_feed_permastruct(): string {
				return '';
			}
		};
	}

	private static function reset_runtime(): void {
		global $wp_embed;

		self::$handler_calls = array();
		$wp_embed = new \WP_Embed();
		\WP_oEmbed::$early_providers = array();

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/feed/';
		$_SERVER['HTTPS']       = 'on';
	}

	private static function snapshot_state(): array {
		return array(
			'globals'        => self::snapshot_globals(
				array(
					'content_width',
					'shortcode_tags',
					'wp_actions',
					'wp_current_filter',
					'wp_embed',
					'wp_filter',
					'wp_filters',
					'wp_rewrite',
					'_wp_theme_features',
				)
			),
			'server'         => array(
				'HTTP_HOST'   => $_SERVER['HTTP_HOST'] ?? null,
				'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
				'HTTPS'       => $_SERVER['HTTPS'] ?? null,
			),
			'earlyProviders' => self::clone_value( \WP_oEmbed::$early_providers ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		foreach ( array( 'HTTP_HOST', 'REQUEST_URI', 'HTTPS' ) as $key ) {
			if ( null === $snapshot['server'][ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $snapshot['server'][ $key ];
			}
		}
		\WP_oEmbed::$early_providers = $snapshot['earlyProviders'];
		self::$handler_calls         = array();
	}

	private static function load_oembed_controller(): bool {
		if ( class_exists( 'WP_oEmbed_Controller' ) ) {
			return true;
		}

		if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return false;
		}

		$file = rtrim( (string) ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . trim( (string) WPINC, '/\\' ) . DIRECTORY_SEPARATOR . 'class-wp-oembed-controller.php';
		if ( ! is_readable( $file ) ) {
			return false;
		}

		require_once $file;

		return class_exists( 'WP_oEmbed_Controller' );
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
}
