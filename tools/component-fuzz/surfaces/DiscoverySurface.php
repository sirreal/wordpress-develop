<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes public discovery helpers: robots directives and XML sitemap plumbing.
 */
final class DiscoverySurface {
	public const NAME = 'discovery';

	private const PREVIEW_BYTES = 160;
	private const URL_CASES     = 8;

	/** @var array<string,array<string,mixed>> */
	private static array $provider_data = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'discovery.bootstrap-apis-available',
					'Required WordPress discovery APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_runtime();
			self::install_url_filters();

			$rows[] = self::check_robots_directives( $ctx );
			$rows[] = self::check_sitemap_registry_and_urls( $ctx );
			$rows[] = self::check_sitemap_renderer_xml( $ctx );
			$rows[] = self::check_sitemap_max_url_filter( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'discovery.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	public static function filter_home_option( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );
		return 'https://example.test';
	}

	public static function filter_blog_public( $pre_option, string $option = '', $default_value = false ): int {
		unset( $pre_option, $option, $default_value );
		return 1;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'SimpleXMLElement', 'WP_Sitemaps', 'WP_Sitemaps_Provider', 'WP_Sitemaps_Registry', 'WP_Sitemaps_Renderer' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'esc_attr',
				'get_sitemap_url',
				'remove_filter',
				'wp_register_sitemap_provider',
				'wp_robots',
				'wp_robots_max_image_preview_large',
				'wp_robots_no_robots',
				'wp_robots_noindex',
				'wp_robots_sensitive_page',
				'wp_sitemaps_get_max_urls',
				'wp_sitemaps_get_server',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_robots_directives( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::robots_cases( $ctx->fork( 'robots' ) );

		foreach ( $cases as $index => $case ) {
			$filter = static function () use ( $case ): array {
				return $case['directives'];
			};

			try {
				\add_filter( 'wp_robots', $filter, 10, 0 );
				ob_start();
				\wp_robots();
				$output = ob_get_clean();
			} finally {
				\remove_filter( 'wp_robots', $filter, 10 );
			}

			$expected_parts = array();
			foreach ( $case['directives'] as $directive => $value ) {
				if ( is_string( $value ) ) {
					$expected_parts[] = "{$directive}:{$value}";
				} elseif ( $value ) {
					$expected_parts[] = $directive;
				}
			}
			$expected = '' === implode( ', ', $expected_parts )
				? ''
				: "<meta name='robots' content='" . \esc_attr( implode( ', ', $expected_parts ) ) . "' />\n";

			self::collect_failure(
				$failures,
				$expected === $output
					&& false === strpos( $output, '<script' )
					&& false === strpos( $output, '"' )
					&& ( '' === $output || 1 === substr_count( $output, "<meta name='robots'" ) ),
				"wp_robots output shape case {$index}",
				array(
					'directives' => $case['directives'],
					'expected'   => self::describe_string( $expected ),
					'actual'     => self::describe_string( $output ),
				)
			);
		}

		$public_robots = \wp_robots_max_image_preview_large( array() );
		$no_robots     = \wp_robots_no_robots( array() );
		$sensitive     = \wp_robots_sensitive_page( array() );
		$noindex       = \wp_robots_noindex( array() );

		self::collect_failure(
			$failures,
			array( 'max-image-preview' => 'large' ) === $public_robots
				&& array( 'noindex' => true, 'follow' => true ) === $no_robots
				&& array( 'noindex' => true, 'noarchive' => true ) === $sensitive
				&& array() === $noindex,
			'robots helper directives match blog_public semantics',
			array(
				'maxImage'  => $public_robots,
				'noRobots'  => $no_robots,
				'sensitive' => $sensitive,
				'noindex'   => $noindex,
			)
		);

		return self::row(
			$ctx,
			'discovery.robots.directive-rendering-and-helpers',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_sitemap_registry_and_urls( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$server   = \wp_sitemaps_get_server();
		$name     = 'cfz-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 6 );
		$subtypes = array(
			'alpha' => array( 'label' => 'Alpha' ),
			'beta'  => array( 'label' => 'Beta' ),
		);
		$urls     = self::sitemap_url_cases( $ctx->fork( 'urls' ) );
		$provider = self::sitemap_provider( $name, 'component', $subtypes, $urls );

		$added     = \wp_register_sitemap_provider( $name, $provider );
		$duplicate = \wp_register_sitemap_provider( $name, self::sitemap_provider( $name, 'other', array(), array() ) );
		$found     = $server->registry->get_provider( $name );
		$missing   = $server->registry->get_provider( 'missing-' . $name );

		$url_alpha_0 = \get_sitemap_url( $name, 'alpha', 0 );
		$url_alpha_3 = \get_sitemap_url( $name, 'alpha', 3 );
		$url_bad     = \get_sitemap_url( $name, 'unknown', 1 );
		$url_missing = \get_sitemap_url( 'missing-' . $name, '', 1 );
		$index_url   = \get_sitemap_url( 'index' );

		self::collect_failure(
			$failures,
			true === $added
				&& false === $duplicate
				&& $provider === $found
				&& null === $missing
				&& is_string( $url_alpha_0 )
				&& is_string( $url_alpha_3 )
				&& str_contains( $url_alpha_0, 'sitemap=' . rawurlencode( $name ) )
				&& str_contains( $url_alpha_0, 'sitemap-subtype=alpha' )
				&& str_contains( $url_alpha_0, 'paged=1' )
				&& str_contains( $url_alpha_3, 'paged=3' )
				&& false === $url_bad
				&& false === $url_missing
				&& is_string( $index_url ),
			'sitemap provider registry and URL normalization',
			array(
				'name'       => $name,
				'added'      => $added,
				'duplicate'  => $duplicate,
				'found'      => self::describe_value( $found ),
				'urlAlpha0'  => $url_alpha_0,
				'urlAlpha3'  => $url_alpha_3,
				'urlBad'     => $url_bad,
				'urlMissing' => $url_missing,
				'indexUrl'   => $index_url,
			)
		);

		$entries = $provider->get_sitemap_entries();
		$list    = $server->index->get_sitemap_list();
		self::collect_failure(
			$failures,
			4 === count( $entries )
				&& 4 === count( $list )
				&& self::all_entries_are_sitemap_locs( $entries, $name )
				&& self::all_entries_are_sitemap_locs( $list, $name ),
			'sitemap entries expand subtype page counts into index list',
			array(
				'entries' => $entries,
				'list'    => $list,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.registry-urls-and-index-entries',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_sitemap_renderer_xml( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$renderer = new \WP_Sitemaps_Renderer();
		$urls     = self::sitemap_url_cases( $ctx->fork( 'renderer' ) );
		$xml      = $renderer->get_sitemap_xml( $urls );
		$index    = $renderer->get_sitemap_index_xml(
			array(
				array(
					'loc'     => 'https://example.test/wp-sitemap-posts-post-1.xml?x=<unsafe>&ok=1',
					'lastmod' => '2026-06-21T12:00:00+00:00',
				),
			)
		);

		$parsed_xml   = is_string( $xml ) ? @simplexml_load_string( $xml ) : false;
		$parsed_index = is_string( $index ) ? @simplexml_load_string( $index ) : false;

		self::collect_failure(
			$failures,
			is_string( $xml )
				&& false !== $parsed_xml
				&& count( $parsed_xml->url ) === count( $urls )
				&& ! str_contains( $xml, '<unsafe>' )
				&& ! str_contains( $xml, '<script' )
				&& substr_count( $xml, '<loc>' ) === count( $urls ),
			'sitemap renderer emits well-formed escaped urlset XML',
			array(
				'xml'       => self::describe_string( is_string( $xml ) ? $xml : '' ),
				'urlCount'  => count( $urls ),
				'parsedXml' => false !== $parsed_xml,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $index )
				&& false !== $parsed_index
				&& 1 === count( $parsed_index->sitemap )
				&& ! str_contains( $index, '<unsafe>' )
				&& str_contains( $index, 'wp-sitemap-posts-post-1.xml' ),
			'sitemap renderer emits well-formed escaped index XML',
			array(
				'index'       => self::describe_string( is_string( $index ) ? $index : '' ),
				'parsedIndex' => false !== $parsed_index,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.renderer-escaped-xml',
			array() === $failures,
			array(
				'urlCount' => count( $urls ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_sitemap_max_url_filter( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$limit    = 10 + $ctx->int( 0, 50 );
		$seen     = array();
		$filter   = static function ( int $max_urls, string $object_type ) use ( $limit, &$seen ): int {
			$seen[] = array(
				'max'  => $max_urls,
				'type' => $object_type,
			);

			return 'component' === $object_type ? $limit : $max_urls;
		};

		try {
			\add_filter( 'wp_sitemaps_max_urls', $filter, 10, 2 );
			$component = \wp_sitemaps_get_max_urls( 'component' );
			$post      = \wp_sitemaps_get_max_urls( 'post' );
		} finally {
			\remove_filter( 'wp_sitemaps_max_urls', $filter, 10 );
		}

		$after = \wp_sitemaps_get_max_urls( 'component' );
		self::collect_failure(
			$failures,
			$limit === $component
				&& 2000 === $post
				&& 2000 === $after
				&& 2 === count( $seen )
				&& 'component' === $seen[0]['type']
				&& 'post' === $seen[1]['type'],
			'wp_sitemaps_get_max_urls filter is scoped by object type and removable',
			array(
				'limit'     => $limit,
				'component' => $component,
				'post'      => $post,
				'after'     => $after,
				'seen'      => $seen,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.max-url-filter-locality',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function robots_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'directives' => array() ),
			array( 'directives' => array( 'noindex' => true, 'nofollow' => false, 'max-image-preview' => 'large' ) ),
			array( 'directives' => array( 'index' => true, 'snippet' => 'max-snippet:' . $ctx->int( 1, 99 ) ) ),
			array( 'directives' => array( 'unsafe<tag' => 'a"b<c&d', 'archive' => true ) ),
		);

		for ( $i = count( $cases ); $i < 8; ++$i ) {
			$case       = $ctx->fork( 'case-' . $i );
			$directive  = 'cfz-' . substr( hash( 'crc32b', (string) $case->seed() ), 0, 8 );
			$value      = $case->choice( array( true, false, 'value-' . $case->int( 0, 999 ), "unsafe\"<>&" ) );
			$cases[]    = array(
				'directives' => array(
					$directive           => $value,
					'max-snippet'        => (string) $case->int( 1, 500 ),
					'max-image-preview'  => 'large',
				),
			);
		}

		return $cases;
	}

	private static function sitemap_url_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$urls = array();
		for ( $i = 0; $i < self::URL_CASES; ++$i ) {
			$case   = $ctx->fork( 'url-' . $i );
			$urls[] = array(
				'loc'        => 'https://example.test/content/' . $i . '?q=' . rawurlencode( $case->text( 0, 24 ) ),
				'lastmod'    => sprintf( '2026-06-%02dT%02d:00:00+00:00', 1 + $case->int( 0, 20 ), $case->int( 0, 23 ) ),
				'changefreq' => $case->choice( array( 'daily', 'weekly', 'monthly' ) ),
				'priority'   => sprintf( '0.%d', $case->int( 1, 9 ) ),
			);
		}

		$urls[] = array(
			'loc'     => 'https://example.test/unsafe?x=<tag>&quote="',
			'lastmod' => '2026-06-21T00:00:00+00:00',
		);

		return $urls;
	}

	private static function sitemap_provider( string $name, string $object_type, array $subtypes, array $urls ): \WP_Sitemaps_Provider {
		return new class( $name, $object_type, $subtypes, $urls ) extends \WP_Sitemaps_Provider {
			/** @var array<string,array<string,mixed>> */
			private array $subtypes;

			/** @var array<int,array<string,string>> */
			private array $urls;

			public function __construct( string $name, string $object_type, array $subtypes, array $urls ) {
				$this->name        = $name;
				$this->object_type = $object_type;
				$this->subtypes    = $subtypes;
				$this->urls        = $urls;
			}

			public function get_url_list( $page_num, $object_subtype = '' ) {
				unset( $object_subtype );
				$page_num = max( 1, (int) $page_num );
				return array_slice( $this->urls, ( $page_num - 1 ) * 4, 4 );
			}

			public function get_max_num_pages( $object_subtype = '' ) {
				return 'beta' === $object_subtype ? 3 : 1;
			}

			public function get_object_subtypes() {
				return $this->subtypes;
			}
		};
	}

	private static function all_entries_are_sitemap_locs( array $entries, string $name ): bool {
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['loc'] ) || ! is_string( $entry['loc'] ) || ! str_contains( $entry['loc'], 'sitemap=' . rawurlencode( $name ) ) ) {
				return false;
			}
		}

		return true;
	}

	private static function reset_runtime(): void {
		global $wp_rewrite;

		self::$provider_data     = array();
		if ( class_exists( 'WP_Rewrite' ) ) {
			$wp_rewrite = new \WP_Rewrite();
		} else {
			$wp_rewrite = new class() {
				public function using_permalinks(): bool {
					return false;
				}
			};
		}

		$GLOBALS['wp_sitemaps']           = new \WP_Sitemaps();
		$GLOBALS['wp_sitemaps']->registry = new \WP_Sitemaps_Registry();
		$GLOBALS['wp_sitemaps']->renderer = new \WP_Sitemaps_Renderer();
		$GLOBALS['wp_sitemaps']->index    = new \WP_Sitemaps_Index( $GLOBALS['wp_sitemaps']->registry );
	}

	private static function install_url_filters(): void {
		\add_filter( 'pre_option_home', array( __CLASS__, 'filter_home_option' ), 10, 3 );
		\add_filter( 'pre_option_siteurl', array( __CLASS__, 'filter_home_option' ), 10, 3 );
		\add_filter( 'pre_option_blog_public', array( __CLASS__, 'filter_blog_public' ), 10, 3 );
		\add_filter( 'wp_sitemaps_stylesheet_url', '__return_false', 0 );
		\add_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false', 0 );
	}

	private static function remove_url_filters(): void {
		\remove_filter( 'pre_option_home', array( __CLASS__, 'filter_home_option' ), 10 );
		\remove_filter( 'pre_option_siteurl', array( __CLASS__, 'filter_home_option' ), 10 );
		\remove_filter( 'pre_option_blog_public', array( __CLASS__, 'filter_blog_public' ), 10 );
		\remove_filter( 'wp_sitemaps_stylesheet_url', '__return_false', 0 );
		\remove_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false', 0 );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
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

	private static function skip( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		$data['reason'] = $reason;
		return self::row( $ctx, $invariant, true, $data, 'skipped' );
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

	private static function snapshot_globals(): array {
		$snapshot = array(
			'providerData' => self::$provider_data,
			'globals'      => array(),
		);

		foreach ( array( 'wp_rewrite', 'wp_sitemaps', 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter' ) as $name ) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		self::remove_url_filters();
		self::$provider_data = $snapshot['providerData'];

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
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
}
