<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes front-controller request parsing and status/header transitions.
 */
final class RequestLifecycleSurface {
	public const NAME = 'request-lifecycle';

	private const HOME_URL = 'http://example.test/site-base';
	private const SITE_URL = 'http://example.test/site-base/wp';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'request-lifecycle.bootstrap-apis-available',
					'Required WordPress request lifecycle APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$case = self::case_for_context( $ctx );
			self::install_option_filters( $case['rules'] );

			$rows[] = self::check_parse_request_rewrite_and_precedence( $ctx->fork( 'parse' ), $case );
			$rows[] = self::check_parse_request_short_circuit( $ctx->fork( 'short-circuit' ) );
			$rows[] = self::check_register_globals( $ctx->fork( 'globals' ), $case );
			$rows[] = self::check_handle_404_transitions( $ctx->fork( '404' ) );
			$rows[] = self::check_send_headers_filters_and_actions( $ctx->fork( 'headers' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'request-lifecycle.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::remove_option_filters();
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'request-lifecycle.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedServer'  => array_keys( $snapshot['server'] ),
			)
		);

		return $rows;
	}

	public static function filter_home(): string {
		return self::HOME_URL;
	}

	public static function filter_siteurl(): string {
		return self::SITE_URL;
	}

	public static function filter_blog_charset(): string {
		return 'UTF-8';
	}

	public static function filter_html_type(): string {
		return 'text/html';
	}

	public static function filter_rewrite_rules() {
		return self::$rewrite_rules;
	}

	/** @var array<string,string> */
	private static array $rewrite_rules = array();

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Query', 'WP_Rewrite', 'WP_MatchesMapRegex' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'do_action_ref_array',
				'get_option',
				'has_filter',
				'home_url',
				'is_404',
				'remove_filter',
				'status_header',
				'wp_get_nocache_headers',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$slug = sanitize_title_with_dashes( 'Item ' . $ctx->identifier( 4, 12 ) . ' ' . $ctx->int( 1, 999 ) );
		if ( '' === $slug ) {
			$slug = 'item-' . $ctx->seed();
		}

		$public_var = 'cfuzz_item_' . strtolower( str_replace( array( '-', ':' ), '_', $ctx->identifier( 4, 10 ) ) );
		$extra_var  = 'cfuzz_extra_' . strtolower( str_replace( array( '-', ':' ), '_', $ctx->identifier( 4, 10 ) ) );
		$tag_var    = 'cfuzz_tag_' . strtolower( str_replace( array( '-', ':' ), '_', $ctx->identifier( 4, 10 ) ) );
		$rule       = '^library/([^/]+)/?$';

		return array(
			'slug'      => $slug,
			'publicVar' => $public_var,
			'extraVar'  => $extra_var,
			'tagVar'    => $tag_var,
			'rule'      => $rule,
			'rules'     => array(
				$rule => 'index.php?' . $public_var . '=$matches[1]&' . $tag_var . '=permalink+value&page=2',
				'^feed/([^/]+)/?$' => 'index.php?feed=$matches[1]',
			),
		);
	}

	private static function check_parse_request_rewrite_and_precedence( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$wp = self::new_wp();
		$wp->add_query_var( $case['publicVar'] );
		$wp->add_query_var( $case['extraVar'] );
		$wp->add_query_var( $case['tagVar'] );

		self::prepare_rewrite_globals();
		self::prepare_request_server( '/site-base/library/' . rawurlencode( $case['slug'] ) . '/?ignored=1', '' );
		$_GET  = array( $case['extraVar'] => 'from-get' );
		$_POST = array();

		$seen_request = array();
		$request_filter = static function ( array $query_vars ) use ( &$seen_request ): array {
			$seen_request[] = $query_vars;
			$query_vars['filtered_request'] = 'yes';
			return $query_vars;
		};
		$action_count = 0;
		$action       = static function ( \WP $seen_wp ) use ( &$action_count, $wp ): void {
			if ( $seen_wp === $wp ) {
				++$action_count;
			}
		};

		add_filter( 'request', $request_filter );
		add_action( 'parse_request', $action );
		try {
			$parsed = $wp->parse_request(
				array(
					$case['extraVar'] => 'from-extra',
					'offset'          => 7,
				)
			);
		} finally {
			remove_filter( 'request', $request_filter );
			remove_action( 'parse_request', $action );
		}

		$ok = true === $parsed
			&& true === $wp->did_permalink
			&& $case['rule'] === $wp->matched_rule
			&& $case['slug'] === ( $wp->query_vars[ $case['publicVar'] ] ?? null )
			&& 'permalink value' === ( $wp->query_vars[ $case['tagVar'] ] ?? null )
			&& 'from-extra' === ( $wp->query_vars[ $case['extraVar'] ] ?? null )
			&& 7 === ( $wp->query_vars['offset'] ?? null )
			&& '2' === ( $wp->query_vars['page'] ?? null )
			&& 'yes' === ( $wp->query_vars['filtered_request'] ?? null )
			&& 1 === count( $seen_request )
			&& 1 === $action_count
			&& false === has_filter( 'request', $request_filter );

		return $ctx->result(
			'request-lifecycle.parse-request.rewrite-extra-precedence',
			$ok,
			array(
				'slug'         => $case['slug'],
				'request'      => $wp->request,
				'matchedRule'  => $wp->matched_rule,
				'matchedQuery' => $wp->matched_query,
				'queryVars'    => $wp->query_vars,
				'actionCount'  => $action_count,
			)
		);
	}

	private static function check_parse_request_short_circuit( \ComponentFuzz\FuzzContext $ctx ): array {
		$wp = self::new_wp();
		self::prepare_rewrite_globals();
		self::prepare_request_server( '/site-base/library/blocked/', '' );

		$seen = array();
		$filter = static function ( bool $parse, \WP $seen_wp, $extra_query_vars ) use ( &$seen, $wp ): bool {
			$seen[] = array(
				'parse' => $parse,
				'same'  => $seen_wp === $wp,
				'extra' => $extra_query_vars,
			);
			return false;
		};
		add_filter( 'do_parse_request', $filter, 10, 3 );
		try {
			$parsed = $wp->parse_request( array( 'p' => 123 ) );
		} finally {
			remove_filter( 'do_parse_request', $filter, 10 );
		}

		return $ctx->result(
			'request-lifecycle.parse-request.short-circuit-no-mutation',
			false === $parsed
				&& array() === $wp->query_vars
				&& '' === $wp->matched_rule
				&& 1 === count( $seen )
				&& true === ( $seen[0]['parse'] ?? null )
				&& true === ( $seen[0]['same'] ?? null )
				&& false === has_filter( 'do_parse_request', $filter ),
			array(
				'seen'      => $seen,
				'queryVars' => $wp->query_vars,
			)
		);
	}

	private static function check_register_globals( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$wp = self::new_wp();
		$wp->query_vars = array(
			$case['publicVar'] => $case['slug'],
			'p'                => (string) $ctx->int( 100, 999 ),
		);
		$wp->build_query_string();

		$post = (object) array(
			'ID'           => (int) $wp->query_vars['p'],
			'post_title'   => 'Lifecycle Global ' . $case['slug'],
			'post_content' => 'body',
		);

		$GLOBALS['wp_query'] = new \WP_Query();
		$GLOBALS['wp_query']->query_vars = $wp->query_vars;
		$GLOBALS['wp_query']->posts      = array( $post );
		$GLOBALS['wp_query']->post       = $post;
		$GLOBALS['wp_query']->request    = 'SELECT component_fuzz';
		$GLOBALS['wp_query']->is_single  = true;

		$wp->register_globals();

		$ok = $case['slug'] === ( $GLOBALS[ $case['publicVar'] ] ?? null )
			&& (string) $post->ID === ( $GLOBALS['p'] ?? null )
			&& $wp->query_string === ( $GLOBALS['query_string'] ?? null )
			&& $GLOBALS['posts'] === array( $post )
			&& $GLOBALS['post'] === $post
			&& 'SELECT component_fuzz' === ( $GLOBALS['request'] ?? null )
			&& 1 === ( $GLOBALS['more'] ?? null )
			&& 1 === ( $GLOBALS['single'] ?? null );

		return $ctx->result(
			'request-lifecycle.register-globals.query-loop-exports',
			$ok,
			array(
				'queryString' => $wp->query_string,
				'globalPost'  => is_object( $GLOBALS['post'] ?? null ) ? get_object_vars( $GLOBALS['post'] ) : null,
			)
		);
	}

	private static function check_handle_404_transitions( \ComponentFuzz\FuzzContext $ctx ): array {
		$statuses = array();
		$status_filter = static function ( string $status_header, int $code ) use ( &$statuses ): string {
			$statuses[] = $code;
			return $status_header;
		};
		add_filter( 'status_header', $status_filter, 10, 2 );

		$set_404_count = 0;
		$set_404_action = static function () use ( &$set_404_count ): void {
			++$set_404_count;
		};
		add_action( 'set_404', $set_404_action );

		try {
			$GLOBALS['wp_query'] = new \WP_Query();
			$GLOBALS['wp_query']->posts   = array();
			$GLOBALS['wp_query']->is_home = false;
			$GLOBALS['wp_query']->is_feed = false;
			$wp_404 = self::new_wp();
			$wp_404->handle_404();
			$missing_posts_404 = $GLOBALS['wp_query']->is_404();

			$GLOBALS['wp_query'] = new \WP_Query();
			$GLOBALS['wp_query']->posts = array( (object) array( 'ID' => 42 ) );
			$wp_200 = self::new_wp();
			$wp_200->handle_404();
			$found_posts_404 = $GLOBALS['wp_query']->is_404();

			$preempt_filter = static fn() => true;
			add_filter( 'pre_handle_404', $preempt_filter );
			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_preempt = self::new_wp();
			$wp_preempt->handle_404();
			$preempt_is_404 = $GLOBALS['wp_query']->is_404();
			remove_filter( 'pre_handle_404', $preempt_filter );
		} finally {
			remove_filter( 'status_header', $status_filter, 10 );
			remove_action( 'set_404', $set_404_action );
		}

		$ok = true === $missing_posts_404
			&& false === $found_posts_404
			&& false === $preempt_is_404
			&& array( 404, 200 ) === $statuses
			&& 1 === $set_404_count
			&& false === has_filter( 'status_header', $status_filter );

		return $ctx->result(
			'request-lifecycle.handle-404.status-transitions-and-preempt',
			$ok,
			array(
				'statuses'        => $statuses,
				'set404Count'     => $set_404_count,
				'missingPosts404' => $missing_posts_404,
				'foundPosts404'   => $found_posts_404,
				'preemptIs404'    => $preempt_is_404,
			)
		);
	}

	private static function check_send_headers_filters_and_actions( \ComponentFuzz\FuzzContext $ctx ): array {
		$captured_headers = array();
		$statuses         = array();
		$send_actions     = 0;
		$headers_filter   = static function ( array $headers, \WP $wp ) use ( &$captured_headers ): array {
			$captured_headers[] = array(
				'headers'   => $headers,
				'queryVars' => $wp->query_vars,
			);
			$headers['X-Component-Fuzz'] = 'request-lifecycle';
			return $headers;
		};
		$status_filter = static function ( string $status_header, int $code ) use ( &$statuses ): string {
			$statuses[] = $code;
			return $status_header;
		};
		$send_action = static function () use ( &$send_actions ): void {
			++$send_actions;
		};

		add_filter( 'wp_headers', $headers_filter, 10, 2 );
		add_filter( 'status_header', $status_filter, 10, 2 );
		add_action( 'send_headers', $send_action );
		try {
			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_html = self::new_wp();
			$wp_html->query_vars = array();
			$wp_html->send_headers();

			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_error = self::new_wp();
			$wp_error->query_vars = array( 'error' => 404 );
			$wp_error->send_headers();
		} finally {
			remove_filter( 'wp_headers', $headers_filter, 10 );
			remove_filter( 'status_header', $status_filter, 10 );
			remove_action( 'send_headers', $send_action );
		}

		$first_headers  = $captured_headers[0]['headers'] ?? array();
		$second_headers = $captured_headers[1]['headers'] ?? array();
		$ok = 2 === count( $captured_headers )
			&& 2 === $send_actions
			&& array( 404 ) === $statuses
			&& 'text/html; charset=UTF-8' === ( $first_headers['Content-Type'] ?? null )
			&& 'text/html; charset=UTF-8' === ( $second_headers['Content-Type'] ?? null )
			&& isset( $second_headers['Cache-Control'], $second_headers['Expires'] )
			&& false === has_filter( 'wp_headers', $headers_filter );

		return $ctx->result(
			'request-lifecycle.send-headers.filters-status-and-actions',
			$ok,
			array(
				'capturedHeaders' => $captured_headers,
				'statuses'        => $statuses,
				'sendActions'     => $send_actions,
			)
		);
	}

	private static function new_wp(): \WP {
		$wp = new \WP();
		$wp->public_query_vars  = array_values( array_unique( $wp->public_query_vars ) );
		$wp->private_query_vars = array_values( array_unique( $wp->private_query_vars ) );
		return $wp;
	}

	private static function prepare_rewrite_globals(): void {
		$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		$GLOBALS['wp_rewrite']->permalink_structure = '/%postname%/';
		$GLOBALS['wp_rewrite']->front               = '/';
		$GLOBALS['wp_rewrite']->root                = '';
		$GLOBALS['wp_rewrite']->index               = 'index.php';
	}

	private static function prepare_request_server( string $request_uri, string $path_info ): void {
		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['PHP_SELF']        = '/site-base/index.php';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = $request_uri;
		$_SERVER['PATH_INFO']       = $path_info;
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	}

	private static function install_option_filters( array $rewrite_rules ): void {
		self::$rewrite_rules = $rewrite_rules;
		add_filter( 'pre_option_home', array( self::class, 'filter_home' ) );
		add_filter( 'pre_option_siteurl', array( self::class, 'filter_siteurl' ) );
		add_filter( 'pre_option_blog_charset', array( self::class, 'filter_blog_charset' ) );
		add_filter( 'pre_option_html_type', array( self::class, 'filter_html_type' ) );
		add_filter( 'pre_option_rewrite_rules', array( self::class, 'filter_rewrite_rules' ) );
	}

	private static function remove_option_filters(): void {
		remove_filter( 'pre_option_home', array( self::class, 'filter_home' ) );
		remove_filter( 'pre_option_siteurl', array( self::class, 'filter_siteurl' ) );
		remove_filter( 'pre_option_blog_charset', array( self::class, 'filter_blog_charset' ) );
		remove_filter( 'pre_option_html_type', array( self::class, 'filter_html_type' ) );
		remove_filter( 'pre_option_rewrite_rules', array( self::class, 'filter_rewrite_rules' ) );
		self::$rewrite_rules = array();
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wp',
				'wp_rewrite',
				'wp_query',
				'wp_the_query',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'query_string',
				'posts',
				'post',
				'request',
				'more',
				'single',
				'authordata',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		$server = array();
		foreach ( array( 'HTTP_HOST', 'PHP_SELF', 'REQUEST_METHOD', 'REQUEST_URI', 'PATH_INFO', 'SERVER_PROTOCOL' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'server'  => $server,
			'get'     => self::clone_value( $_GET ),
			'post'    => self::clone_value( $_POST ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
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

		$_GET  = self::clone_value( $snapshot['get'] );
		$_POST = self::clone_value( $snapshot['post'] );
	}

	private static function state_matches( array $snapshot ): bool {
		$current = self::snapshot_state();
		return $snapshot == $current
			&& false === has_filter( 'pre_option_home', array( self::class, 'filter_home' ) )
			&& false === has_filter( 'pre_option_rewrite_rules', array( self::class, 'filter_rewrite_rules' ) );
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

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
