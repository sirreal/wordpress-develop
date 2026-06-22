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

			$rows[] = self::check_embed_handler_lifecycle( $ctx );
			$rows[] = self::check_oembed_provider_registry( $ctx );
			$rows[] = self::check_oembed_output_filters( $ctx );
			$rows[] = self::check_feed_helpers( $ctx );
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

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'SimpleXMLElement', 'WP_Embed', 'WP_oEmbed' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_oembed_create_xml',
				'add_filter',
				'feed_content_type',
				'get_bloginfo_rss',
				'get_default_feed',
				'get_self_link',
				'prep_atom_text_construct',
				'remove_filter',
				'self_link',
				'wp_embed_defaults',
				'wp_embed_register_handler',
				'wp_embed_unregister_handler',
				'wp_filter_oembed_iframe_title_attribute',
				'wp_filter_oembed_result',
				'wp_oembed_ensure_format',
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

		$id       = 'cfz_' . strtolower( $ctx->identifier( 4, 10 ) );
		$priority = $ctx->int( 1, 20 );
		$url      = 'https://media.example.test/item/' . rawurlencode( $ctx->identifier( 3, 12 ) );

		\wp_embed_register_handler( $id, '~^https://media\.example\.test/item/([^/?#]+)~i', array( self::class, 'embed_handler' ), $priority );
		$html = $wp_embed->get_embed_handler_html(
			array(
				'width' => $ctx->int( 240, 960 ),
			),
			$url
		);

		self::collect_failure(
			$failures,
			is_string( $html )
				&& 1 === count( self::$handler_calls )
				&& str_contains( $html, '<iframe ' )
				&& str_contains( $html, 'src="' . \esc_url( $url ) . '"' )
				&& isset( $wp_embed->handlers[ $priority ][ $id ] )
				&& isset( self::$handler_calls[0]['attr']['width'], self::$handler_calls[0]['attr']['height'] )
				&& self::$handler_calls[0]['rawattr']['width'] === self::$handler_calls[0]['attr']['width'],
			'registered embed handler receives raw and defaulted attributes and returns HTML',
			array(
				'id'      => $id,
				'html'    => self::describe_string( is_string( $html ) ? $html : '' ),
				'calls'   => self::$handler_calls,
				'handler' => $wp_embed->handlers[ $priority ][ $id ] ?? null,
			)
		);

		$denied = $wp_embed->get_embed_handler_html( array(), 'https://media.example.test/item/deny/' . $ctx->identifier( 3, 8 ) );
		self::collect_failure(
			$failures,
			false === $denied,
			'handler false return allows get_embed_handler_html to fail closed',
			array( 'denied' => $denied )
		);

		\wp_embed_unregister_handler( $id, $priority );
		$after_unregister = $wp_embed->get_embed_handler_html( array(), $url );
		self::collect_failure(
			$failures,
			false === $after_unregister && ! isset( $wp_embed->handlers[ $priority ][ $id ] ),
			'unregister removes only the selected embed handler',
			array(
				'afterUnregister' => $after_unregister,
				'handlers'        => $wp_embed->handlers,
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
		$failures = array();
		$format   = 'https://provider-' . strtolower( $ctx->identifier( 3, 8 ) ) . '.example.test/watch/*';
		$url      = str_replace( '*', rawurlencode( $ctx->identifier( 3, 12 ) ), $format );
		$endpoint = 'https://oembed.example.test/provider.{format}?token=' . rawurlencode( $ctx->identifier( 3, 10 ) );

		\WP_oEmbed::$early_providers = array();
		\WP_oEmbed::_add_provider_early( $format, $endpoint, false );
		$oembed = new \WP_oEmbed();
		$found  = $oembed->get_provider( $url, array( 'discover' => false ) );

		self::collect_failure(
			$failures,
			str_replace( '{format}', 'json', $endpoint ) === $found
				&& array() === \WP_oEmbed::$early_providers,
			'early oEmbed providers are consumed by the next WP_oEmbed instance',
			array(
				'format'   => $format,
				'url'      => $url,
				'endpoint' => $endpoint,
				'found'    => $found,
				'early'    => \WP_oEmbed::$early_providers,
			)
		);

		\WP_oEmbed::$early_providers = array();
		\WP_oEmbed::_add_provider_early( $format, $endpoint, false );
		\WP_oEmbed::_remove_provider_early( $format );
		$removed = new \WP_oEmbed();

		self::collect_failure(
			$failures,
			false === $removed->get_provider( $url, array( 'discover' => false ) ),
			'early provider removal wins over early addition for the same format',
			array( 'providers' => $removed->providers[ $format ] ?? null )
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

		self::collect_failure(
			$failures,
			is_string( $photo )
				&& str_contains( $photo, '<img ' )
				&& ! str_contains( strtolower( $photo ), 'javascript:' )
				&& str_contains( $photo, 'alt="Photo &quot;Title&quot; &lt;tag&gt;"' )
				&& is_string( $link )
				&& str_contains( $link, 'Link &lt;Title&gt; &amp; more' ),
			'WP_oEmbed data2html escapes photo/link fields and strips unsafe protocols',
			array(
				'photo' => self::describe_string( is_string( $photo ) ? $photo : '' ),
				'link'  => self::describe_string( is_string( $link ) ? $link : '' ),
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
		$html     = '<blockquote><a href="javascript:alert(1)">bad</a></blockquote><script>alert(1)</script><iframe src="https://player.example.test/embed" width="640" height="360"></iframe>';

		$titled   = \wp_filter_oembed_iframe_title_attribute( '<iframe src="https://player.example.test/embed"></iframe>', $data, $url );
		$filtered = \wp_filter_oembed_result( $html, $data, $url );

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
				&& str_contains( $filtered, 'wp-embedded-content' ),
			'oEmbed filters add iframe titles and sandbox untrusted rich/video HTML',
			array(
				'title'    => self::describe_string( is_string( $titled ) ? $titled : '' ),
				'filtered' => self::describe_string( is_string( $filtered ) ? $filtered : '' ),
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
				&& str_contains( $xml, 'A&amp;B &lt; C' ),
			'_oembed_create_xml serializes nested arrays and escapes text nodes',
			array( 'xml' => self::describe_string( is_string( $xml ) ? $xml : '' ) )
		);

		return self::row(
			$ctx,
			'syndication.oembed.output-filters-and-xml',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_feed_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		\add_filter( 'pre_option_blogname', array( self::class, 'filter_blogname' ), 10, 3 );
		\add_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10, 3 );
		try {
			$bloginfo = \get_bloginfo_rss( 'name' );
			$_SERVER['REQUEST_URI'] = '/feed/?q=' . rawurlencode( $ctx->text( 0, 16 ) );
			$self_link = \get_self_link();

			ob_start();
			\self_link();
			$self_link_output = (string) ob_get_clean();
		} finally {
			\remove_filter( 'pre_option_blogname', array( self::class, 'filter_blogname' ), 10 );
			\remove_filter( 'pre_option_home', array( self::class, 'filter_home' ), 10 );
		}

		$rss_filter = static fn (): string => 'rss';
		$atom_filter = static fn (): string => 'atom';
		\add_filter( 'default_feed', $rss_filter );
		$rss_default = \get_default_feed();
		\remove_filter( 'default_feed', $rss_filter );
		\add_filter( 'default_feed', $atom_filter );
		$atom_default = \get_default_feed();
		\remove_filter( 'default_feed', $atom_filter );

		self::collect_failure(
			$failures,
			'Component Fuzz &#038; "Feeds"' === $bloginfo
				&& 'rss2' === $rss_default
				&& 'atom' === $atom_default
				&& 'application/rss+xml' === \feed_content_type( 'rss2' )
				&& 'application/atom+xml' === \feed_content_type( 'atom' )
				&& str_starts_with( $self_link, 'https://example.test/feed/' )
				&& $self_link_output === \esc_url( $self_link ),
			'feed helpers escape bloginfo, normalize default feed, map content types, and render self links',
			array(
				'bloginfo'       => $bloginfo,
				'rssDefault'     => $rss_default,
				'atomDefault'    => $atom_default,
				'selfLink'       => $self_link,
				'selfLinkOutput' => $self_link_output,
			)
		);

		$plain = \prep_atom_text_construct( 'plain text' );
		$xhtml = \prep_atom_text_construct( '<strong>ok</strong>' );
		$html  = \prep_atom_text_construct( '<strong>broken' );

		self::collect_failure(
			$failures,
			array( 'text', 'plain text' ) === $plain
				&& 'xhtml' === $xhtml[0]
				&& str_contains( $xhtml[1], "xmlns='http://www.w3.org/1999/xhtml'" )
				&& 'html' === $html[0]
				&& str_contains( $html[1], '<![CDATA[' ),
			'prep_atom_text_construct partitions plain, xhtml, and html fallback payloads',
			array(
				'plain' => $plain,
				'xhtml' => $xhtml,
				'html'  => $html,
			)
		);

		return self::row(
			$ctx,
			'syndication.feed.helpers-and-atom-text',
			array() === $failures,
			array( 'failures' => $failures )
		);
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
