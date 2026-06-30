<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WordPress-as-provider post embed helpers against the in-memory DB.
 */
final class PostEmbedsSurface {
	public const NAME = 'post-embeds';

	/** @var array<int,array<string,mixed>> */
	private static array $http_intercepts = array();

	/** @var array<int,array<string,mixed>> */
	private static array $http_request_log = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'post-embeds.bootstrap-apis-available',
					'Required WordPress post embed APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$case     = self::case_for_context( $ctx );
		$rows     = array();

		try {
			self::prepare_runtime( $case );
			$fixtures = self::seed_fixtures( $case );

			$rows[] = self::check_embeddable_predicates( $ctx->fork( 'predicates' ), $case, $fixtures );
			$rows[] = self::check_oembed_response_data( $ctx->fork( 'response-data' ), $case, $fixtures );
			$rows[] = self::check_embed_url_and_html( $ctx->fork( 'url-html' ), $case, $fixtures );
			$rows[] = self::check_discovery_links( $ctx->fork( 'discovery' ), $case, $fixtures );
			$rows[] = self::check_controller_and_pre_oembed( $ctx->fork( 'controller-pre' ), $case, $fixtures );
			$rows[] = self::check_proxy_provider_transient_cache( $ctx->fork( 'proxy-cache' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'post-embeds.surface-no-throw',
				array(
					'case'      => self::case_summary( $case ),
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	public static function filter_pre_http_request( $preempt, array $args, string $url ) {
		self::$http_request_log[] = array(
			'url'                 => $url,
			'method'              => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'limitResponseSize'   => $args['limit_response_size'] ?? null,
			'rejectUnsafeUrls'    => $args['reject_unsafe_urls'] ?? null,
			'timeout'             => $args['timeout'] ?? null,
			'userAgent'           => $args['user-agent'] ?? null,
		);

		foreach ( self::$http_intercepts as $intercept ) {
			$method = strtoupper( (string) ( $intercept['method'] ?? 'GET' ) );
			if ( $method !== strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
				continue;
			}

			$contains = (string) ( $intercept['contains'] ?? '' );
			if ( '' !== $contains && ! str_contains( $url, $contains ) ) {
				continue;
			}

			if ( isset( $intercept['callback'] ) && is_callable( $intercept['callback'] ) ) {
				return $intercept['callback']( $url, $args );
			}

			return $intercept['response'] ?? false;
		}

		return new \WP_Error(
			'component_fuzz_unexpected_oembed_http',
			'Unexpected oEmbed HTTP request escaped the component fuzz intercepts.',
			array( 'url' => $url )
		);
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'Component_Fuzz_WPDB_Stub', 'WP_Embed', 'WP_Error', 'WP_oEmbed', 'WP_Post', 'WP_Query', 'WP_REST_Request', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_post_meta',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'delete_transient',
				'do_action',
				'get_author_posts_url',
				'get_bloginfo',
				'get_home_url',
				'get_oembed_endpoint_url',
				'get_oembed_response_data',
				'get_oembed_response_data_for_url',
				'get_oembed_response_data_rich',
				'get_page_by_path',
				'get_permalink',
				'get_post',
				'get_transient',
				'get_post_embed_html',
				'get_post_embed_url',
				'get_post_thumbnail_id',
				'get_the_title',
				'has_action',
				'has_filter',
				'has_post_thumbnail',
				'is_post_embeddable',
				'is_wp_error',
				'register_post_type',
				'remove_action',
				'remove_all_actions',
				'remove_filter',
				'sanitize_key',
				'sanitize_title',
				'set_transient',
				'update_option',
				'update_post_meta',
				'wp_cache_flush',
				'wp_filter_pre_oembed_result',
				'wp_insert_post',
				'wp_insert_user',
				'wp_oembed_add_discovery_links',
				'wp_slash',
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

	private static function check_embeddable_predicates( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();

		$public_post        = \get_post( $fixtures['post'] );
		$private_post       = \get_post( $fixtures['privatePost'] );
		$blocked_type_post  = \get_post( $fixtures['blockedTypePost'] );
		$private_type_post  = \get_post( $fixtures['privateTypePost'] );
		$missing_type_post  = clone $public_post;
		$missing_type_post->post_type = 'cf_missing_' . substr( $case['token'], 0, 7 );
		$filtered_calls     = array();
		$filter             = static function ( bool $is_embeddable, \WP_Post $post ) use ( &$filtered_calls, $fixtures ): bool {
			$filtered_calls[] = array(
				'postId' => (int) $post->ID,
				'before' => $is_embeddable,
			);

			if ( (int) $post->ID === (int) $fixtures['blockedTypePost'] ) {
				return true;
			}

			if ( (int) $post->ID === (int) $fixtures['post'] ) {
				return false;
			}

			return $is_embeddable;
		};

		$public_embeddable       = \is_post_embeddable( $public_post );
		$blocked_embeddable      = \is_post_embeddable( $blocked_type_post );
		$private_type_embeddable = \is_post_embeddable( $private_type_post );
		$missing_type_embeddable = \is_post_embeddable( $missing_type_post );
		$private_response        = \get_oembed_response_data( $private_post, $case['requestedWidth'] );
		$blocked_response        = \get_oembed_response_data( $blocked_type_post, $case['requestedWidth'] );
		$private_type_response   = \get_oembed_response_data( $private_type_post, $case['requestedWidth'] );

		\add_filter( 'is_post_embeddable', $filter, 10, 2 );
		try {
			$filtered_public  = \is_post_embeddable( $public_post );
			$filtered_blocked = \is_post_embeddable( $blocked_type_post );
		} finally {
			\remove_filter( 'is_post_embeddable', $filter, 10 );
		}

		self::collect_failure(
			$failures,
			true === $public_embeddable
				&& false === $blocked_embeddable
				&& true === $private_type_embeddable
				&& false === $missing_type_embeddable
				&& false === $private_response
				&& false === $blocked_response
				&& false === $private_type_response,
			'embeddability is type-based while response generation still requires public visibility',
			array(
				'publicEmbeddable'      => $public_embeddable,
				'blockedEmbeddable'     => $blocked_embeddable,
				'privateTypeEmbeddable' => $private_type_embeddable,
				'missingTypeEmbeddable' => $missing_type_embeddable,
				'privateResponse'       => $private_response,
				'blockedResponse'       => $blocked_response,
				'privateTypeResponse'   => $private_type_response,
			)
		);

		self::collect_failure(
			$failures,
			false === $filtered_public
				&& true === $filtered_blocked
				&& count( $filtered_calls ) >= 2
				&& false === \has_filter( 'is_post_embeddable', $filter ),
			'is_post_embeddable filter can override per-post decisions and is scoped',
			array(
				'filteredPublic'  => $filtered_public,
				'filteredBlocked' => $filtered_blocked,
				'calls'           => $filtered_calls,
				'filterActive'    => \has_filter( 'is_post_embeddable', $filter ),
			)
		);

		return self::result(
			$ctx,
			'post-embeds.embeddable-predicates-and-visibility',
			$failures,
			array(
				'case'     => self::case_summary( $case ),
				'fixtures' => self::fixture_summary( $fixtures ),
			)
		);
	}

	private static function check_oembed_response_data( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$captures = array();
		$min      = $ctx->int( 180, 260 );
		$max      = $ctx->int( 420, 760 );
		$width    = min( max( $min, $case['requestedWidth'] ), $max );
		$height   = max( (int) ceil( $width / 16 * 9 ), 200 );

		$min_max_filter = static function () use ( $min, $max ): array {
			return array(
				'min' => $min,
				'max' => $max,
			);
		};
		$capture_filter = static function ( array $data, \WP_Post $post, int $filtered_width, int $filtered_height ) use ( &$captures ): array {
			$captures[] = array(
				'data'   => $data,
				'postId' => (int) $post->ID,
				'width'  => $filtered_width,
				'height' => $filtered_height,
			);

			return $data;
		};

		\remove_filter( 'oembed_response_data', 'get_oembed_response_data_rich', 10 );
		\add_filter( 'oembed_min_max_width', $min_max_filter );
		\add_filter( 'oembed_response_data', $capture_filter, 9, 4 );
		\add_filter( 'oembed_response_data', 'get_oembed_response_data_rich', 10, 4 );
		try {
			$data = self::with_missing_embed_script_warning_suppressed(
				static function () use ( $fixtures, $case ) {
					return \get_oembed_response_data( $fixtures['post'], $case['requestedWidth'] );
				}
			);
		} finally {
			\remove_filter( 'oembed_response_data', 'get_oembed_response_data_rich', 10 );
			\remove_filter( 'oembed_response_data', $capture_filter, 9 );
			\remove_filter( 'oembed_min_max_width', $min_max_filter );
		}

		$base = $captures[0]['data'] ?? array();
		$html = is_array( $data ) ? (string) ( $data['html'] ?? '' ) : '';

		self::collect_failure(
			$failures,
			is_array( $data )
				&& 1 === count( $captures )
				&& (int) $fixtures['post'] === (int) ( $captures[0]['postId'] ?? 0 )
				&& $width === (int) ( $captures[0]['width'] ?? 0 )
				&& $height === (int) ( $captures[0]['height'] ?? 0 )
				&& '1.0' === ( $base['version'] ?? null )
				&& $case['siteName'] === ( $base['provider_name'] ?? null )
				&& 'http://example.test' === ( $base['provider_url'] ?? null )
				&& $case['authorDisplay'] === ( $base['author_name'] ?? null )
				&& \get_author_posts_url( $fixtures['author'] ) === ( $base['author_url'] ?? null )
				&& \get_the_title( $fixtures['post'] ) === ( $base['title'] ?? null )
				&& 'link' === ( $base['type'] ?? null ),
			'base oEmbed response captures provider, author, title, and clamped dimensions',
			array(
				'data'      => $data,
				'captures'  => $captures,
				'expected'  => array(
					'width'  => $width,
					'height' => $height,
				),
				'minMax'    => array(
					'min' => $min,
					'max' => $max,
				),
			)
		);

		self::collect_failure(
			$failures,
			is_array( $data )
				&& 'rich' === ( $data['type'] ?? null )
				&& $width === (int) ( $data['width'] ?? 0 )
				&& $height === (int) ( $data['height'] ?? 0 )
				&& self::html_has_embed_shape( $html, $width, $height )
				&& isset( $data['thumbnail_url'], $data['thumbnail_width'], $data['thumbnail_height'] )
				&& (int) $data['thumbnail_width'] > 0
				&& (int) $data['thumbnail_height'] > 0,
			'rich response adds iframe HTML, final dimensions, and thumbnail metadata',
			array(
				'data'        => $data,
				'htmlPreview' => self::preview( $html ),
			)
		);

		return self::result(
			$ctx,
			'post-embeds.response-data-and-rich-conversion',
			$failures,
			array(
				'case' => self::case_summary( $case ),
			)
		);
	}

	private static function check_embed_url_and_html( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$width    = $ctx->int( 220, 640 );
		$height   = $ctx->int( 200, 420 );

		self::set_permalink_structure( '' );
		$plain_url = \get_post_embed_url( $fixtures['post'] );
		$html      = self::with_missing_embed_script_warning_suppressed(
			static function () use ( $width, $height, $fixtures ) {
				return \get_post_embed_html( $width, $height, $fixtures['post'] );
			}
		);

		self::set_permalink_structure( '/%postname%/' );
		$pretty_url = \get_post_embed_url( $fixtures['post'] );

		$parent_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_author' => $fixtures['author'],
					'post_name'   => $case['postSlug'],
					'post_status' => 'publish',
					'post_title'  => 'Conflict parent ' . $case['token'],
					'post_type'   => 'page',
				)
			)
		);
		$conflict_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_author' => $fixtures['author'],
					'post_name'   => 'embed',
					'post_parent' => $parent_id,
					'post_status' => 'publish',
					'post_title'  => 'Conflict child ' . $case['token'],
					'post_type'   => 'page',
				)
			)
		);
		if ( is_int( $conflict_id ) ) {
			$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_name' => 'embed' ), array( 'ID' => $conflict_id ) );
			\wp_cache_flush();
		}
		$conflict_url = \get_post_embed_url( $fixtures['post'] );

		self::collect_failure(
			$failures,
			is_string( $plain_url )
				&& str_contains( $plain_url, 'embed=true' )
				&& is_string( $pretty_url )
				&& 1 === preg_match( '~/' . preg_quote( $case['postSlug'], '~' ) . '/embed/?(?:$|[?#])~', $pretty_url )
				&& ! str_contains( $pretty_url, 'embed=true' )
				&& is_int( $parent_id )
				&& is_int( $conflict_id )
				&& is_string( $conflict_url )
				&& str_contains( $conflict_url, 'embed=true' ),
			'embed URL chooses query args for plain permalinks or path conflicts and pretty /embed/ otherwise',
			array(
				'plainUrl'    => $plain_url,
				'prettyUrl'   => $pretty_url,
				'conflictUrl' => $conflict_url,
				'parentId'    => $parent_id,
				'conflictId'  => $conflict_id,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $html )
				&& self::html_has_embed_shape( $html, $width, $height )
				&& 1 === preg_match_all( '/<blockquote\b/i', $html )
				&& 1 === preg_match_all( '/<iframe\b/i', $html )
				&& 1 === preg_match_all( '/<script\b/i', $html )
				&& preg_match( '/data-secret="([^"]+)"/', $html, $secret_match )
				&& 2 === substr_count( $html, 'data-secret="' . $secret_match[1] . '"' )
				&& str_contains( $html, 'sandbox="allow-scripts"' )
				&& str_contains( $html, 'security="restricted"' )
				&& str_contains( $html, \esc_html( \get_the_title( $fixtures['post'] ) ) )
				&& str_contains( $html, \esc_attr( $case['siteName'] ) ),
			'embed HTML emits one blockquote/iframe/script with shared secret and escaped title metadata',
			array(
				'htmlPreview' => self::preview( is_string( $html ) ? $html : '' ),
				'width'       => $width,
				'height'      => $height,
			)
		);

		return self::result(
			$ctx,
			'post-embeds.url-selection-and-iframe-html',
			$failures,
			array(
				'case' => self::case_summary( $case ),
			)
		);
	}

	private static function check_discovery_links( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();

		self::set_singular_query( \get_post( $fixtures['post'] ) );
		\remove_all_actions( 'wp_head' );
		\add_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
		\add_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );

		ob_start();
		\do_action( 'wp_head' );
		$output = (string) ob_get_clean();

		$non_singular_output = '';
		$GLOBALS['wp_query'] = new \WP_Query();
		ob_start();
		\wp_oembed_add_discovery_links();
		$non_singular_output = (string) ob_get_clean();

		self::set_singular_query( \get_post( $fixtures['blockedTypePost'] ) );
		ob_start();
		\wp_oembed_add_discovery_links();
		$blocked_output = (string) ob_get_clean();

		self::collect_failure(
			$failures,
			1 === substr_count( $output, 'application/json+oembed' )
				&& ( class_exists( 'SimpleXMLElement', false ) ? 1 === substr_count( $output, 'text/xml+oembed' ) : true )
				&& str_contains( $output, 'oembed/1.0/embed' )
				&& str_contains( $output, rawurlencode( \get_permalink( $fixtures['post'] ) ) )
				&& 4 === \has_action( 'wp_head', 'wp_oembed_add_discovery_links' )
				&& '' === trim( $non_singular_output )
				&& '' === trim( $blocked_output ),
			'discovery links print once for singular embeddable posts and stay quiet otherwise',
			array(
				'output'             => $output,
				'nonSingularOutput'  => $non_singular_output,
				'blockedOutput'      => $blocked_output,
				'remainingPriority'  => \has_action( 'wp_head', 'wp_oembed_add_discovery_links' ),
				'permalink'          => \get_permalink( $fixtures['post'] ),
			)
		);

		return self::result(
			$ctx,
			'post-embeds.discovery-link-output',
			$failures,
			array(
				'case' => self::case_summary( $case ),
			)
		);
	}

	private static function check_controller_and_pre_oembed( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		if ( ! self::load_oembed_controller() ) {
			return $ctx->skip(
				'post-embeds.controller-class-available',
				'WP_oEmbed_Controller could not be loaded.'
			);
		}

		$failures = array();
		$width    = $ctx->int( 240, 720 );
		$expected_width = min( max( 200, $width ), 600 );
		$url      = 'http://example.test/embed-target/' . rawurlencode( $case['token'] );
		$remote   = 'https://remote.example.test/embed-target/' . rawurlencode( $case['token'] );
		$calls    = array();
		$request_filter = static function ( int $post_id, string $request_url ) use ( &$calls, $fixtures, $url ): int {
			$calls[] = array(
				'before' => $post_id,
				'url'    => $request_url,
			);

			return $url === $request_url ? (int) $fixtures['post'] : $post_id;
		};
		$blocked_filter = static function ( int $post_id, string $request_url ) use ( $fixtures, $remote ): int {
			return $remote === $request_url ? (int) $fixtures['blockedTypePost'] : $post_id;
		};

		\remove_filter( 'oembed_response_data', 'get_oembed_response_data_rich', 10 );
		\add_filter( 'oembed_response_data', 'get_oembed_response_data_rich', 10, 4 );
		\add_filter( 'oembed_request_post_id', $request_filter, 10, 2 );
		\add_filter( 'oembed_request_post_id', $blocked_filter, 11, 2 );
		try {
			$controller = new \WP_oEmbed_Controller();
			$request    = new \WP_REST_Request( 'GET', '/oembed/1.0/embed' );
			$request->set_param( 'url', $url );
			$request->set_param( 'maxwidth', $width );
			$item = self::with_missing_embed_script_warning_suppressed(
				static function () use ( $controller, $request ) {
					return $controller->get_item( $request );
				}
			);

			$missing_request = new \WP_REST_Request( 'GET', '/oembed/1.0/embed' );
			$missing_request->set_param( 'url', 'http://example.test/missing/' . rawurlencode( $case['token'] ) );
			$missing_request->set_param( 'maxwidth', $width );
			$missing = $controller->get_item( $missing_request );

			$blocked_request = new \WP_REST_Request( 'GET', '/oembed/1.0/embed' );
			$blocked_request->set_param( 'url', $remote );
			$blocked_request->set_param( 'maxwidth', $width );
			$blocked = $controller->get_item( $blocked_request );

			$local_data = self::with_missing_embed_script_warning_suppressed(
				static function () use ( $url, $width ) {
					return \get_oembed_response_data_for_url( $url, array( 'width' => $width ) );
				}
			);
			$pre_local  = self::with_missing_embed_script_warning_suppressed(
				static function () use ( $url, $width ) {
					return \wp_filter_pre_oembed_result( null, $url, array( 'width' => $width ) );
				}
			);
			$pre_remote = \wp_filter_pre_oembed_result( 'original-result', 'https://elsewhere.example.test/item/' . rawurlencode( $case['token'] ), array( 'width' => $width ) );
		} finally {
			\remove_filter( 'oembed_request_post_id', $blocked_filter, 11 );
			\remove_filter( 'oembed_request_post_id', $request_filter, 10 );
			\remove_filter( 'oembed_response_data', 'get_oembed_response_data_rich', 10 );
		}

		self::collect_failure(
			$failures,
			is_array( $item )
				&& 'rich' === ( $item['type'] ?? null )
				&& $expected_width === (int) ( $item['width'] ?? 0 )
				&& isset( $item['html'] )
				&& \is_wp_error( $missing )
				&& 'oembed_invalid_url' === $missing->get_error_code()
				&& 404 === (int) ( $missing->get_error_data()['status'] ?? 0 )
				&& \is_wp_error( $blocked )
				&& 'oembed_invalid_url' === $blocked->get_error_code(),
			'oEmbed REST controller resolves filtered local post IDs and fails closed for missing or non-embeddable posts',
			array(
				'item'    => $item,
				'missing' => self::describe_wp_error( $missing ),
				'blocked' => self::describe_wp_error( $blocked ),
				'calls'   => $calls,
				'expectedWidth' => $expected_width,
			)
		);

		self::collect_failure(
			$failures,
			is_object( $local_data )
				&& 'rich' === ( $local_data->type ?? null )
				&& $expected_width === (int) ( $local_data->width ?? 0 )
				&& is_string( $pre_local )
				&& str_contains( $pre_local, '<iframe' )
				&& str_contains( $pre_local, 'wp-embedded-content' )
				&& 'original-result' === $pre_remote
				&& false === \has_filter( 'oembed_request_post_id', $request_filter )
				&& false === \has_filter( 'oembed_request_post_id', $blocked_filter ),
			'pre_oembed same-site shortcut returns local HTML and preserves non-local results',
			array(
				'localData'          => is_object( $local_data ) ? get_object_vars( $local_data ) : $local_data,
				'preLocalPreview'    => self::preview( is_string( $pre_local ) ? $pre_local : '' ),
				'preRemote'          => $pre_remote,
				'requestFilterAlive' => \has_filter( 'oembed_request_post_id', $request_filter ),
				'blockedFilterAlive' => \has_filter( 'oembed_request_post_id', $blocked_filter ),
			)
		);

		return self::result(
			$ctx,
			'post-embeds.controller-and-pre-oembed-shortcut',
			$failures,
			array(
				'case' => self::case_summary( $case ),
			)
		);
	}

	private static function check_proxy_provider_transient_cache( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! self::load_oembed_controller() ) {
			return $ctx->skip(
				'post-embeds.proxy-controller-class-available',
				'WP_oEmbed_Controller could not be loaded.'
			);
		}

		$failures       = array();
		$url            = 'https://consumer.example.test/item/' . rawurlencode( $case['token'] );
		$width          = $ctx->int( 260, 620 );
		$height         = $ctx->int( 180, 420 );
		$changed_width  = $width + $ctx->int( 7, 73 );
		$ttl            = $ctx->int( 300, 7200 );
		$marker         = '<!--cfz-oembed-proxy-' . $case['token'] . '-->';
		$pattern        = 'https://consumer.example.test/item/*';
		$endpoint       = 'https://provider.example.test/oembed.{format}';
		$provider_name  = 'Component Fuzz Provider ' . $case['token'];
		$fetch_urls     = array();
		$remote_args    = array();
		$result_calls   = array();
		$ttl_calls      = array();
		$content_before = self::content_counts();

		$oembed             = \_wp_oembed_get_object();
		$providers_snapshot = $oembed->providers;

		$first_request  = self::proxy_request( $url, $width, $height, 'first-' . $case['token'] );
		$second_request = self::proxy_request( $url, $width, $height, 'second-' . $case['token'] );
		$third_request  = self::proxy_request( $url, $changed_width, $height, 'third-' . $case['token'] );
		$first_key      = self::proxy_cache_key( $first_request );
		$second_key     = self::proxy_cache_key( $second_request );
		$third_key      = self::proxy_cache_key( $third_request );

		\delete_transient( $first_key );
		\delete_transient( $third_key );

		$fetch_filter = static function ( string $provider, string $request_url, array $args ) use ( &$fetch_urls ): string {
			$fetch_urls[] = array(
				'provider' => $provider,
				'url'      => $request_url,
				'args'     => $args,
				'query'    => self::url_query_args( $provider ),
			);

			return $provider;
		};
		$remote_args_filter = static function ( array $args, string $request_url ) use ( &$remote_args ): array {
			$remote_args[] = array(
				'args'  => $args,
				'url'   => $request_url,
				'query' => self::url_query_args( $request_url ),
			);

			return $args;
		};
		$result_filter = static function ( $html, string $request_url, array $args ) use ( &$result_calls, $marker ) {
			$result_calls[] = array(
				'html' => is_string( $html ) ? self::preview( $html ) : $html,
				'url'  => $request_url,
				'args' => $args,
			);

			return is_string( $html ) ? $html . $marker : $html;
		};
		$ttl_filter = static function ( int $time, string $request_url, array $args ) use ( &$ttl_calls, $ttl ): int {
			$ttl_calls[] = array(
				'incoming' => $time,
				'url'      => $request_url,
				'args'     => $args,
			);

			return $ttl;
		};

		self::$http_request_log = array();
		self::$http_intercepts  = array(
			array(
				'method'   => 'GET',
				'contains' => 'provider.example.test/oembed.json',
				'callback' => static function ( string $request_url, array $args ) use ( $case, $provider_name ) {
					unset( $args );

					$query = self::url_query_args( $request_url );
					$width = max( 1, (int) ( $query['maxwidth'] ?? 0 ) );
					$height = max( 1, (int) ( $query['maxheight'] ?? 0 ) );

					return self::http_response(
						json_encode(
							array(
								'version'       => '1.0',
								'type'          => 'rich',
								'provider_name' => $provider_name,
								'provider_url'  => 'https://provider.example.test/',
								'title'         => 'Remote embed ' . $case['token'],
								'html'          => '<iframe class="cfz-remote-embed" src="https://provider.example.test/embed/' . esc_attr( $case['token'] ) . '" width="' . $width . '" height="' . $height . '"></iframe>',
								'width'         => $width,
								'height'        => $height,
							)
						)
					);
				},
			),
		);

		\add_filter( 'pre_http_request', array( self::class, 'filter_pre_http_request' ), 10, 3 );
		\add_filter( 'oembed_fetch_url', $fetch_filter, 10, 3 );
		\add_filter( 'oembed_remote_get_args', $remote_args_filter, 10, 2 );
		\add_filter( 'oembed_result', $result_filter, 10, 3 );
		\add_filter( 'rest_oembed_ttl', $ttl_filter, 10, 3 );

		try {
			$oembed->providers[ $pattern ] = array( $endpoint, false );
			$controller                    = new \WP_oEmbed_Controller();

			$first                  = $controller->get_proxy_item( $first_request );
			$http_count_after_first = count( self::$http_request_log );
			$first_cache            = \get_transient( $first_key );

			$second                  = $controller->get_proxy_item( $second_request );
			$http_count_after_second = count( self::$http_request_log );

			$third                  = $controller->get_proxy_item( $third_request );
			$http_count_after_third = count( self::$http_request_log );
			$third_cache            = \get_transient( $third_key );
		} finally {
			\delete_transient( $first_key );
			\delete_transient( $third_key );
			$oembed->providers = $providers_snapshot;
			\remove_filter( 'rest_oembed_ttl', $ttl_filter, 10 );
			\remove_filter( 'oembed_result', $result_filter, 10 );
			\remove_filter( 'oembed_remote_get_args', $remote_args_filter, 10 );
			\remove_filter( 'oembed_fetch_url', $fetch_filter, 10 );
			\remove_filter( 'pre_http_request', array( self::class, 'filter_pre_http_request' ), 10 );
		}

		$http_log          = self::$http_request_log;
		$content_after     = self::content_counts();
		$first_query       = isset( $fetch_urls[0]['provider'] ) ? self::url_query_args( (string) $fetch_urls[0]['provider'] ) : array();
		$third_query       = isset( $fetch_urls[1]['provider'] ) ? self::url_query_args( (string) $fetch_urls[1]['provider'] ) : array();
		$first_http_query  = isset( $http_log[0]['url'] ) ? self::url_query_args( (string) $http_log[0]['url'] ) : array();
		$third_http_query  = isset( $http_log[1]['url'] ) ? self::url_query_args( (string) $http_log[1]['url'] ) : array();
		$first_cached_data = is_object( $first_cache ) ? get_object_vars( $first_cache ) : $first_cache;
		$third_cached_data = is_object( $third_cache ) ? get_object_vars( $third_cache ) : $third_cache;
		self::$http_intercepts = array();
		self::$http_request_log = array();

		self::collect_failure(
			$failures,
			$first_key === $second_key
				&& $first_key !== $third_key
				&& is_object( $first_cache )
				&& is_object( $third_cache )
				&& false === \get_transient( $first_key )
				&& false === \get_transient( $third_key ),
			'proxy cache key ignores nonce, includes dimensions, stores transient data, and cleans transient options',
			array(
				'firstKey'   => $first_key,
				'secondKey'  => $second_key,
				'thirdKey'   => $third_key,
				'firstCache' => $first_cached_data,
				'thirdCache' => $third_cached_data,
			)
		);

		self::collect_failure(
			$failures,
			is_object( $first )
				&& is_object( $second )
				&& is_object( $third )
				&& 'rich' === ( $first->type ?? null )
				&& $provider_name === ( $first->provider_name ?? null )
				&& $provider_name === ( $second->provider_name ?? null )
				&& $provider_name === ( $third->provider_name ?? null )
				&& $width === (int) ( $first->width ?? 0 )
				&& $width === (int) ( $second->width ?? 0 )
				&& $changed_width === (int) ( $third->width ?? 0 )
				&& is_string( $first->html ?? null )
				&& str_contains( $first->html, '<iframe' )
				&& 1 === substr_count( $first->html, $marker )
				&& ( $first->html ?? null ) === ( $second->html ?? null )
				&& is_string( $third->html ?? null )
				&& str_contains( $third->html, $marker ),
			'proxy returns filtered rich oEmbed objects and serves same-dimension nonce changes from cache',
			array(
				'first'        => is_object( $first ) ? get_object_vars( $first ) : self::describe_wp_error( $first ),
				'second'       => is_object( $second ) ? get_object_vars( $second ) : self::describe_wp_error( $second ),
				'third'        => is_object( $third ) ? get_object_vars( $third ) : self::describe_wp_error( $third ),
				'expected'     => array( 'width' => $width, 'changedWidth' => $changed_width, 'height' => $height ),
				'marker'       => $marker,
			)
		);

		self::collect_failure(
			$failures,
			1 === $http_count_after_first
				&& 1 === $http_count_after_second
				&& 2 === $http_count_after_third
				&& 2 === count( $http_log )
				&& 2 === count( $fetch_urls )
				&& 2 === count( $remote_args )
				&& 2 === count( $result_calls )
				&& 2 === count( $ttl_calls ),
			'cold proxy requests fetch once, cached nonce variants fetch zero times, and changed dimensions fetch again',
			array(
				'httpCounts'  => array( $http_count_after_first, $http_count_after_second, $http_count_after_third ),
				'httpLog'     => $http_log,
				'fetchUrls'   => $fetch_urls,
				'remoteArgs'  => $remote_args,
				'resultCalls' => $result_calls,
				'ttlCalls'    => $ttl_calls,
			)
		);

		self::collect_failure(
			$failures,
			'1' === (string) ( $first_query['dnt'] ?? '' )
				&& $width === (int) ( $first_query['maxwidth'] ?? 0 )
				&& $height === (int) ( $first_query['maxheight'] ?? 0 )
				&& $url === self::decode_url_arg( $first_query['url'] ?? '' )
				&& 'json' === ( $first_http_query['format'] ?? null )
				&& '1' === (string) ( $third_query['dnt'] ?? '' )
				&& $changed_width === (int) ( $third_query['maxwidth'] ?? 0 )
				&& $height === (int) ( $third_query['maxheight'] ?? 0 )
				&& $url === self::decode_url_arg( $third_query['url'] ?? '' )
				&& 'json' === ( $third_http_query['format'] ?? null ),
			'provider fetch URL carries max dimensions, original URL, dnt, and JSON format',
			array(
				'firstFetchQuery'  => $first_query,
				'firstHttpQuery'   => $first_http_query,
				'thirdFetchQuery'  => $third_query,
				'thirdHttpQuery'   => $third_http_query,
				'decodedFirstUrl'  => self::decode_url_arg( $first_http_query['url'] ?? '' ),
				'decodedThirdUrl'  => self::decode_url_arg( $third_http_query['url'] ?? '' ),
				'expectedOriginal' => $url,
			)
		);

		self::collect_failure(
			$failures,
			self::ttl_call_matches( $ttl_calls[0] ?? array(), $url, $width, $height, $ttl )
				&& self::ttl_call_matches( $ttl_calls[1] ?? array(), $url, $changed_width, $height, $ttl )
				&& ! array_key_exists( '_wpnonce', $ttl_calls[0]['args'] ?? array() )
				&& ! array_key_exists( '_wpnonce', $ttl_calls[1]['args'] ?? array() ),
			'rest_oembed_ttl receives nonce-free args with copied width and height',
			array(
				'ttlCalls' => $ttl_calls,
				'ttl'      => $ttl,
			)
		);

		self::collect_failure(
			$failures,
			$content_before === $content_after
				&& $providers_snapshot === $oembed->providers
				&& false === \has_filter( 'pre_http_request', array( self::class, 'filter_pre_http_request' ) )
				&& false === \has_filter( 'oembed_fetch_url', $fetch_filter )
				&& false === \has_filter( 'oembed_remote_get_args', $remote_args_filter )
				&& false === \has_filter( 'oembed_result', $result_filter )
				&& false === \has_filter( 'rest_oembed_ttl', $ttl_filter ),
			'proxy check restores provider registry, filters, and content-row counts',
			array(
				'contentBefore' => $content_before,
				'contentAfter'  => $content_after,
			)
		);

		return self::result(
			$ctx,
			'post-embeds.proxy-provider-transient-cache',
			$failures,
			array(
				'case' => self::case_summary( $case ),
			)
		);
	}

	private static function seed_fixtures( array $case ): array {
		\register_post_type(
			$case['blockedType'],
			array(
				'public'      => true,
				'embeddable'  => false,
				'query_var'   => false,
				'rewrite'     => false,
				'show_in_rest' => false,
				'supports'    => array( 'title', 'editor', 'author', 'thumbnail' ),
			)
		);
		\register_post_type(
			$case['privateType'],
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'embeddable'         => true,
				'query_var'          => false,
				'rewrite'            => false,
				'show_in_rest'       => false,
				'supports'           => array( 'title', 'editor', 'author' ),
			)
		);

		$author_id = \wp_insert_user(
			array(
				'display_name' => $case['authorDisplay'],
				'role'         => 'author',
				'user_email'   => 'embed-author-' . $case['token'] . '@example.test',
				'user_login'   => 'embed_author_' . $case['token'],
				'user_pass'    => 'component-fuzz-pass',
				'user_url'     => 'http://example.test/authors/' . rawurlencode( $case['token'] ),
			)
		);

		$post_id = \wp_insert_post(
			\wp_slash(
				array(
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_author'    => $author_id,
					'post_content'   => $case['content'],
					'post_excerpt'   => $case['excerpt'],
					'post_name'      => $case['postSlug'],
					'post_status'    => 'publish',
					'post_title'     => $case['title'],
					'post_type'      => 'post',
				)
			)
		);
		$private_post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_author'  => $author_id,
					'post_content' => $case['content'],
					'post_name'    => $case['postSlug'] . '-private',
					'post_status'  => 'private',
					'post_title'   => $case['title'] . ' private',
					'post_type'    => 'post',
				)
			)
		);
		$blocked_post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_author'  => $author_id,
					'post_content' => $case['content'],
					'post_name'    => $case['postSlug'] . '-blocked',
					'post_status'  => 'publish',
					'post_title'   => $case['title'] . ' blocked',
					'post_type'    => $case['blockedType'],
				)
			)
		);
		$private_type_post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_author'  => $author_id,
					'post_content' => $case['content'],
					'post_name'    => $case['postSlug'] . '-private-type',
					'post_status'  => 'publish',
					'post_title'   => $case['title'] . ' private type',
					'post_type'    => $case['privateType'],
				)
			)
		);

		$attachment_id = \wp_insert_post(
			\wp_slash(
				array(
					'guid'           => 'http://example.test/wp-content/uploads/' . $case['imageFile'],
					'post_author'    => $author_id,
					'post_mime_type' => 'image/jpeg',
					'post_name'      => $case['imageSlug'],
					'post_parent'    => $post_id,
					'post_status'    => 'inherit',
					'post_title'     => 'Thumbnail ' . $case['token'],
					'post_type'      => 'attachment',
				)
			)
		);
		\update_post_meta( $attachment_id, '_wp_attached_file', $case['imageFile'] );
		\update_post_meta(
			$attachment_id,
			'_wp_attachment_metadata',
			array(
				'width'  => $case['imageWidth'],
				'height' => $case['imageHeight'],
				'file'   => $case['imageFile'],
				'sizes'  => array(
					'thumbnail' => array(
						'file'      => $case['imageThumbFile'],
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/jpeg',
					),
					'medium'    => array(
						'file'      => $case['imageMediumFile'],
						'width'     => min( 640, $case['imageWidth'] ),
						'height'    => min( 360, $case['imageHeight'] ),
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		\update_post_meta( $post_id, '_thumbnail_id', $attachment_id );

		return array(
			'attachment'      => (int) $attachment_id,
			'author'          => (int) $author_id,
			'blockedTypePost' => (int) $blocked_post_id,
			'post'            => (int) $post_id,
			'privatePost'     => (int) $private_post_id,
			'privateTypePost' => (int) $private_type_post_id,
		);
	}

	private static function prepare_runtime( array $case ): void {
		self::$http_intercepts  = array();
		self::$http_request_log = array();

		$wpdb = $GLOBALS['wpdb'];
		$wpdb->component_fuzz_reset_content();
		$wpdb->component_fuzz_reset_options(
			array(
				'admin_email'                   => 'admin@example.test',
				'blog_charset'                  => 'UTF-8',
				'blogdescription'               => 'Post embed provider fuzzing',
				'blogname'                      => $case['siteName'],
				'default_category'              => 0,
				'default_comment_status'        => 'closed',
				'default_ping_status'           => 'closed',
				'default_role'                  => 'subscriber',
				'home'                          => 'http://example.test',
				'permalink_structure'           => '',
				'require_name_email'            => 0,
				'show_avatars'                  => 0,
				'siteurl'                       => 'http://example.test',
				'thumbnail_size_h'              => 150,
				'thumbnail_size_w'              => 150,
				'timezone_string'               => '',
				'upload_path'                   => '',
				'upload_url_path'               => '',
				'uploads_use_yearmonth_folders' => 0,
			)
		);

		\wp_cache_flush();
		$GLOBALS['wp_post_types']    = array();
		$GLOBALS['wp_post_statuses'] = array();
		$GLOBALS['wp_taxonomies']    = array();
		$GLOBALS['wp']               = self::plain_wp_stub();
		$GLOBALS['wp_rewrite']       = new \WP_Rewrite();
		$GLOBALS['wp_query']         = new \WP_Query();
		$GLOBALS['wp_the_query']     = $GLOBALS['wp_query'];
		$GLOBALS['wp_embed']         = new \WP_Embed();

		\create_initial_post_types();
		\create_initial_taxonomies();

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz PostEmbeds';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/post-embeds/';
		$_SERVER['SERVER_SOFTWARE'] = 'ComponentFuzz';
	}

	private static function set_permalink_structure( string $structure ): void {
		\update_option( 'permalink_structure', $structure );
		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
			$GLOBALS['wp_rewrite']->permalink_structure = $structure;
			$GLOBALS['wp_rewrite']->root                = '';
			$GLOBALS['wp_rewrite']->front               = '';
		}
	}

	private static function set_singular_query( \WP_Post $post ): void {
		$query                    = new \WP_Query();
		$query->queried_object    = $post;
		$query->queried_object_id = (int) $post->ID;
		$query->post              = $post;
		$query->posts             = array( $post );
		$query->post_count        = 1;
		$query->found_posts       = 1;
		$query->is_single         = 'page' !== $post->post_type;
		$query->is_page           = 'page' === $post->post_type;
		$query->is_singular       = true;
		$query->is_home           = false;
		$query->is_archive        = false;
		$query->is_404            = false;

		$GLOBALS['post']         = $post;
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
	}

	private static function plain_wp_stub(): object {
		return new class() {
			/** @var string[] */
			public array $public_query_vars = array( 'p', 'page_id', 'attachment_id', 'name', 'pagename', 'post_type' );

			public function add_query_var( string $qv ): void {
				if ( ! in_array( $qv, $this->public_query_vars, true ) ) {
					$this->public_query_vars[] = $qv;
				}
			}

			public function remove_query_var( string $name ): void {
				$this->public_query_vars = array_values( array_diff( $this->public_query_vars, array( $name ) ) );
			}
		};
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token        = substr( hash( 'sha256', $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );
		$image_width  = $ctx->int( 720, 1600 );
		$image_height = $ctx->int( 405, 1000 );

		return array(
			'token'          => $token,
			'authorDisplay'  => self::clean_text( $ctx->fork( 'author' )->choice( array( 'Embed Author ' . $token, 'Author <b>' . $token . '</b>', 'Author & "Quoted" ' . $token ) ), 'Embed Author ' . $token, 80 ),
			'blockedType'    => 'cfemb_no_' . substr( $token, 0, 10 ),
			'content'        => self::clean_content( $ctx->fork( 'content' )->choice( array( '<p>Provider content ' . $token . '</p>', "Line one {$token}\nLine two", 'Content & <script>alert(1)</script> ' . $token ) ), 'Provider content ' . $token ),
			'excerpt'        => self::clean_text( $ctx->fork( 'excerpt' )->choice( array( 'Excerpt ' . $token, '', '<em>Excerpt</em> ' . $token ) ), 'Excerpt ' . $token, 160 ),
			'imageFile'      => 'component-fuzz-' . $token . '.jpg',
			'imageMediumFile' => 'component-fuzz-' . $token . '-640x360.jpg',
			'imageSlug'      => 'component-fuzz-image-' . $token,
			'imageThumbFile' => 'component-fuzz-' . $token . '-150x150.jpg',
			'imageWidth'     => $image_width,
			'imageHeight'    => $image_height,
			'postSlug'       => 'post-embed-' . $token,
			'privateType'    => 'cfemb_pr_' . substr( $token, 0, 10 ),
			'requestedWidth' => $ctx->int( 80, 1200 ),
			'siteName'       => self::clean_text( $ctx->fork( 'site' )->choice( array( 'Component Fuzz Embeds', 'Embeds & "Provider"', 'Provider <b>Site</b>' ) ), 'Component Fuzz Embeds', 80 ),
			'title'          => self::clean_text( $ctx->fork( 'title' )->choice( array( 'Embed title ' . $token, 'Title & "Quoted" ' . $token, '<b>Embed</b> ' . $token ) ), 'Embed title ' . $token, 120 ),
		);
	}

	private static function snapshot_state(): array {
		return array(
			'get'            => $_GET,
			'post'           => $_POST,
			'request'        => $_REQUEST,
			'globals'        => self::snapshot_globals(
				array(
					'post',
					'wp',
					'wp_actions',
					'wp_current_filter',
					'wp_embed',
					'wp_filter',
					'wp_filters',
					'wp_post_types',
					'wp_query',
					'wp_rewrite',
					'wp_scripts',
					'wp_taxonomies',
					'wp_the_query',
					'_wp_theme_features',
				)
			),
			'options'        => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
			'server'         => array(
				'HTTP_HOST'       => array_key_exists( 'HTTP_HOST', $_SERVER ) ? $_SERVER['HTTP_HOST'] : null,
				'HTTP_USER_AGENT' => array_key_exists( 'HTTP_USER_AGENT', $_SERVER ) ? $_SERVER['HTTP_USER_AGENT'] : null,
				'HTTPS'           => array_key_exists( 'HTTPS', $_SERVER ) ? $_SERVER['HTTPS'] : null,
				'REQUEST_URI'     => array_key_exists( 'REQUEST_URI', $_SERVER ) ? $_SERVER['REQUEST_URI'] : null,
				'SERVER_SOFTWARE' => array_key_exists( 'SERVER_SOFTWARE', $_SERVER ) ? $_SERVER['SERVER_SOFTWARE'] : null,
			),
			'earlyProviders' => self::clone_value( \WP_oEmbed::$early_providers ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		\wp_cache_flush();
		self::restore_globals( $snapshot['globals'] );

		$_GET     = $snapshot['get'];
		$_POST    = $snapshot['post'];
		$_REQUEST = $snapshot['request'];

		foreach ( $snapshot['server'] as $name => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $name ] );
			} else {
				$_SERVER[ $name ] = $value;
			}
		}

		\WP_oEmbed::$early_providers = $snapshot['earlyProviders'];
	}

	private static function proxy_request( string $url, int $width, int $height, string $nonce ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/oembed/1.0/proxy' );
		$request->set_param( 'url', $url );
		$request->set_param( 'format', 'json' );
		$request->set_param( 'maxwidth', $width );
		$request->set_param( 'maxheight', $height );
		$request->set_param( 'discover', false );
		$request->set_param( '_wpnonce', $nonce );

		return $request;
	}

	private static function proxy_cache_key( \WP_REST_Request $request ): string {
		$args = $request->get_params();
		unset( $args['_wpnonce'] );

		return 'oembed_' . md5( serialize( $args ) );
	}

	private static function http_response( string $body, int $status = 200 ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function url_query_args( string $url ): array {
		$query = parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return array();
		}

		$args = array();
		parse_str( $query, $args );

		return $args;
	}

	private static function decode_url_arg( $value ): string {
		$decoded = (string) $value;
		for ( $i = 0; $i < 3; ++$i ) {
			$next = rawurldecode( $decoded );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}

		return $decoded;
	}

	private static function ttl_call_matches( array $call, string $url, int $width, int $height, int $ttl ): bool {
		$args = $call['args'] ?? null;

		return is_array( $args )
			&& DAY_IN_SECONDS === (int) ( $call['incoming'] ?? 0 )
			&& $url === ( $call['url'] ?? null )
			&& $ttl > 0
			&& $width === (int) ( $args['maxwidth'] ?? 0 )
			&& $width === (int) ( $args['width'] ?? 0 )
			&& $height === (int) ( $args['maxheight'] ?? 0 )
			&& $height === (int) ( $args['height'] ?? 0 )
			&& false === ( $args['discover'] ?? null )
			&& 'json' === ( $args['format'] ?? null )
			&& ! array_key_exists( 'url', $args );
	}

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function load_oembed_controller(): bool {
		if ( class_exists( 'WP_oEmbed_Controller', false ) ) {
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

		return class_exists( 'WP_oEmbed_Controller', false );
	}

	private static function html_has_embed_shape( string $html, int $width, int $height ): bool {
		return str_contains( $html, '<blockquote class="wp-embedded-content"' )
			&& str_contains( $html, '<iframe ' )
			&& str_contains( $html, 'class="wp-embedded-content"' )
			&& str_contains( $html, 'width="' . (int) $width . '"' )
			&& str_contains( $html, 'height="' . (int) $height . '"' )
			&& str_contains( $html, 'sandbox="allow-scripts"' )
			&& str_contains( $html, 'security="restricted"' )
			&& str_contains( $html, 'wp-embed' );
	}

	private static function with_missing_embed_script_warning_suppressed( callable $callback ) {
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				if ( str_contains( $message, 'file_get_contents' ) && str_contains( $message, 'wp-embed' ) ) {
					return true;
				}

				if ( error_reporting() & $severity ) {
					throw new \ErrorException( $message, 0, $severity, $file, $line );
				}

				return false;
			}
		);

		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $label, array $details = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function clean_text( string $value, string $fallback, int $max_len ): string {
		$value = \wp_check_invalid_utf8( str_replace( "\0", '', $value ), true );
		$value = trim( strip_tags( $value ) );
		if ( '' === $value ) {
			$value = $fallback;
		}

		return substr( $value, 0, $max_len );
	}

	private static function clean_content( string $value, string $fallback ): string {
		$value = \wp_check_invalid_utf8( str_replace( "\0", '', $value ), true );
		if ( '' === trim( strip_tags( $value ) ) ) {
			$value = $fallback;
		}

		return substr( $value, 0, 320 );
	}

	private static function preview( string $value ): string {
		$value = preg_replace( '/\s+/', ' ', $value );
		return substr( (string) $value, 0, 220 );
	}

	private static function fixture_summary( array $fixtures ): array {
		return array(
			'attachment'      => $fixtures['attachment'],
			'author'          => $fixtures['author'],
			'blockedTypePost' => $fixtures['blockedTypePost'],
			'post'            => $fixtures['post'],
			'privatePost'     => $fixtures['privatePost'],
			'privateTypePost' => $fixtures['privateTypePost'],
		);
	}

	private static function case_summary( array $case ): array {
		return array(
			'blockedType'    => $case['blockedType'],
			'postSlug'       => $case['postSlug'],
			'privateType'    => $case['privateType'],
			'requestedWidth' => $case['requestedWidth'],
			'token'          => $case['token'],
		);
	}

	private static function describe_wp_error( $value ): array {
		if ( ! $value instanceof \WP_Error ) {
			return array( 'type' => get_debug_type( $value ) );
		}

		return array(
			'code' => $value->get_error_code(),
			'data' => $value->get_error_data(),
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
		return is_object( $value ) ? clone $value : $value;
	}
}
