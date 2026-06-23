<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes front-controller request parsing and status/header transitions.
 */
final class RequestLifecycleSurface {
	public const NAME = 'request-lifecycle';

	private const HOME_URL = 'http://example.test/site-base';
	private const SITE_URL = 'http://example.test/site-base/wp';
	private const FIXED_LAST_MODIFIED = '2024-02-03 04:05:06';

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
			$rows[] = self::check_parse_request_pathinfo_and_index( $ctx->fork( 'pathinfo-index' ), $case );
			$rows[] = self::check_parse_request_public_private_gates( $ctx->fork( 'public-private-gates' ), $case );
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
				'trackedGlobals'      => array_keys( $snapshot['globals'] ),
				'trackedServer'       => array_keys( $snapshot['server'] ),
				'trackedSuperglobals' => array( '_GET', '_POST', '_REQUEST', '_COOKIE' ),
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

	public static function filter_permalink_structure(): string {
		return '/%postname%/';
	}

	public static function filter_rewrite_rules() {
		return self::$rewrite_rules;
	}

	/** @var array<string,string> */
	private static array $rewrite_rules = array();

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Post', 'WP_Query', 'WP_Rewrite', 'WP_MatchesMapRegex' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_action',
				'do_action_ref_array',
				'get_option',
				'get_post_types',
				'get_taxonomies',
				'has_filter',
				'home_url',
				'is_404',
				'mysql2date',
				'register_taxonomy',
				'remove_action',
				'remove_filter',
				'sanitize_title_with_dashes',
				'status_header',
				'unregister_taxonomy',
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
		$token = strtolower( str_replace( array( '-', ':' ), '_', $ctx->identifier( 5, 10 ) ) );
		$slug  = self::slug_for_context( $ctx, 'Item ' . $token . ' ' . $ctx->int( 1, 999 ) );

		$rewrite_var      = self::query_var_name( 'cfuzz_route_', $ctx->identifier( 4, 10 ) );
		$extra_var        = self::query_var_name( 'cfuzz_extra_', $ctx->identifier( 4, 10 ) );
		$get_var          = self::query_var_name( 'cfuzz_get_', $ctx->identifier( 4, 10 ) );
		$post_var         = self::query_var_name( 'cfuzz_post_', $ctx->identifier( 4, 10 ) );
		$tag_var          = self::query_var_name( 'cfuzz_tag_', $ctx->identifier( 4, 10 ) );
		$pathinfo_var     = self::query_var_name( 'cfuzz_path_', $ctx->identifier( 4, 10 ) );
		$index_var        = self::query_var_name( 'cfuzz_index_', $ctx->identifier( 4, 10 ) );
		$taxonomy_var     = self::query_var_name( 'cfuzz_taxq_', $ctx->identifier( 4, 10 ) );
		$filtered_out_var = self::query_var_name( 'cfuzz_deny_', $ctx->identifier( 4, 10 ) );
		$filter_added_var = self::query_var_name( 'cfuzz_allow_', $ctx->identifier( 4, 10 ) );
		$private_leak_var = self::query_var_name( 'cfuzz_private_', $ctx->identifier( 4, 10 ) );
		$blocked_type     = 'cfuzz_hidden_' . substr( $token, 0, 8 );
		$public_taxonomy  = 'cfuzzrtax_' . substr( $token, 0, 12 );
		$private_taxonomy = 'cfuzzrpriv_' . substr( $token, 0, 12 );
		$library_rule     = 'library/([^/]+)/([^/]+)/?$';
		$pathinfo_rule    = 'index.php/pathinfo/([^/]+)/?$';
		$gate_rule        = 'gated/([^/]+)/([^/]+)/?$';
		$library_query    = 'index.php?'
			. $rewrite_var . '=$matches[1]'
			. '&' . $extra_var . '=from-rewrite'
			. '&' . $tag_var . '=permalink+value'
			. '&' . $taxonomy_var . '=taxonomy+value'
			. '&page=2&offset=99&fields=rewrite-fields&post_type=' . $blocked_type;
		$gate_query       = 'index.php?taxonomy=$matches[1]&term=$matches[2]'
			. '&post_type=' . $blocked_type
			. '&fields=rewrite-fields&' . $private_leak_var . '=rewrite-leak';

		return array(
			'token'          => $token,
			'slug'           => $slug,
			'pathinfoSlug'   => self::slug_for_context( $ctx, 'Path ' . $token . ' ' . $ctx->int( 1000, 9999 ) ),
			'gateTerm'       => self::slug_for_context( $ctx, 'Gate ' . $token . ' ' . $ctx->int( 1000, 9999 ) ),
			'rewriteVar'     => $rewrite_var,
			'extraVar'       => $extra_var,
			'getVar'         => $get_var,
			'postVar'        => $post_var,
			'tagVar'         => $tag_var,
			'pathinfoVar'    => $pathinfo_var,
			'indexVar'       => $index_var,
			'taxonomyVar'    => $taxonomy_var,
			'filteredOutVar' => $filtered_out_var,
			'filterAddedVar' => $filter_added_var,
			'privateLeakVar' => $private_leak_var,
			'blockedType'    => $blocked_type,
			'publicTaxonomy' => $public_taxonomy,
			'privateTaxonomy' => $private_taxonomy,
			'libraryRule'    => $library_rule,
			'pathinfoRule'   => $pathinfo_rule,
			'gateRule'       => $gate_rule,
			'rules'          => array(
				$library_rule  => $library_query,
				$pathinfo_rule => 'index.php?' . $pathinfo_var . '=$matches[1]&name=pathinfo-' . $token,
				'$'            => 'index.php?' . $index_var . '=front&page_id=11',
				$gate_rule     => $gate_query,
			),
		);
	}

	private static function slug_for_context( \ComponentFuzz\FuzzContext $ctx, string $source ): string {
		$slug = sanitize_title_with_dashes( $source );
		if ( '' === $slug ) {
			$slug = 'item-' . $ctx->seed();
		}

		return $slug;
	}

	private static function query_var_name( string $prefix, string $identifier ): string {
		return $prefix . strtolower( str_replace( array( '-', ':' ), '_', $identifier ) );
	}

	private static function check_parse_request_rewrite_and_precedence(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		$wp = self::new_wp();
		$wp->add_query_var( $case['rewriteVar'] );
		$wp->add_query_var( $case['extraVar'] );
		$wp->add_query_var( $case['getVar'] );
		$wp->add_query_var( $case['postVar'] );
		$wp->add_query_var( $case['tagVar'] );
		$wp->add_query_var( $case['taxonomyVar'] );

		self::set_rewrite_rules( $case['rules'] );
		self::prepare_rewrite_globals();
		self::prepare_request_server( '/site-base/library/' . rawurlencode( $case['slug'] ) . '/term-source/?ignored=1', '' );
		self::set_request_superglobals(
			array(
				'error'                => '404',
				$case['extraVar']     => 'from-get',
				$case['getVar']       => 'from-get',
				$case['privateLeakVar'] => 'get-leak',
				'fields'              => 'get-fields',
				'offset'              => 'get-offset',
			),
			array(
				$case['extraVar'] => 'from-post',
				$case['postVar']  => 'from-post',
			)
		);

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
		$global_wp = array(
			'exists' => array_key_exists( 'wp', $GLOBALS ),
			'value'  => $GLOBALS['wp'] ?? null,
		);
		$GLOBALS['wp'] = $wp;
		register_taxonomy(
			$case['publicTaxonomy'],
			'post',
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'query_var'          => $case['taxonomyVar'],
				'rewrite'            => false,
			)
		);
		try {
			$parsed = $wp->parse_request(
				array(
					$case['extraVar'] => 'from-extra',
					'offset'          => 7,
					'post_status'     => 'publish',
					$case['privateLeakVar'] => 'extra-leak',
				)
			);
		} finally {
			remove_filter( 'request', $request_filter );
			remove_action( 'parse_request', $action );
			unregister_taxonomy( $case['publicTaxonomy'] );
			if ( $global_wp['exists'] ) {
				$GLOBALS['wp'] = $global_wp['value'];
			} else {
				unset( $GLOBALS['wp'] );
			}
		}

		$ok = true === $parsed
			&& true === $wp->did_permalink
			&& $case['libraryRule'] === $wp->matched_rule
			&& 'library/' . $case['slug'] . '/term-source' === $wp->request
			&& $case['slug'] === ( $wp->query_vars[ $case['rewriteVar'] ] ?? null )
			&& 'permalink value' === ( $wp->query_vars[ $case['tagVar'] ] ?? null )
			&& 'taxonomy+value' === ( $wp->query_vars[ $case['taxonomyVar'] ] ?? null )
			&& 'from-extra' === ( $wp->query_vars[ $case['extraVar'] ] ?? null )
			&& 'from-get' === ( $wp->query_vars[ $case['getVar'] ] ?? null )
			&& 'from-post' === ( $wp->query_vars[ $case['postVar'] ] ?? null )
			&& 7 === ( $wp->query_vars['offset'] ?? null )
			&& 'publish' === ( $wp->query_vars['post_status'] ?? null )
			&& '2' === ( $wp->query_vars['page'] ?? null )
			&& ! isset( $wp->query_vars['post_type'], $wp->query_vars['fields'], $wp->query_vars[ $case['privateLeakVar'] ] )
			&& ! isset( $_GET['error'] )
			&& 'yes' === ( $wp->query_vars['filtered_request'] ?? null )
			&& 1 === count( $seen_request )
			&& 1 === $action_count
			&& false === has_filter( 'request', $request_filter )
			&& false === has_filter( 'parse_request', $action );

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
				'seenRequest'  => $seen_request,
			)
		);
	}

	private static function check_parse_request_pathinfo_and_index( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$wp_pathinfo = self::new_wp();
		$wp_pathinfo->add_query_var( $case['pathinfoVar'] );

		self::set_rewrite_rules( $case['rules'] );
		self::prepare_rewrite_globals();
		$pathinfo = '/site-base/pathinfo/' . rawurlencode( $case['pathinfoSlug'] ) . '/';
		self::prepare_request_server( '/site-base/index.php?ignored=1', $pathinfo );
		self::set_request_superglobals( array(), array() );
		$pathinfo_parsed = $wp_pathinfo->parse_request();

		$wp_index = self::new_wp();
		$wp_index->add_query_var( $case['indexVar'] );

		self::prepare_rewrite_globals();
		self::prepare_request_server( '/site-base/index.php', '' );
		self::set_request_superglobals( array(), array() );
		$index_parsed = $wp_index->parse_request();

		$ok = true === $pathinfo_parsed
			&& false === $wp_pathinfo->did_permalink
			&& $case['pathinfoRule'] === $wp_pathinfo->matched_rule
			&& 'pathinfo/' . $case['pathinfoSlug'] === $wp_pathinfo->request
			&& $case['pathinfoSlug'] === ( $wp_pathinfo->query_vars[ $case['pathinfoVar'] ] ?? null )
			&& 'pathinfo-' . $case['token'] === ( $wp_pathinfo->query_vars['name'] ?? null )
			&& true === $index_parsed
			&& false === $wp_index->did_permalink
			&& '$' === $wp_index->matched_rule
			&& '' === $wp_index->request
			&& 'front' === ( $wp_index->query_vars[ $case['indexVar'] ] ?? null )
			&& '11' === ( $wp_index->query_vars['page_id'] ?? null )
			&& ! isset( $wp_index->query_vars['error'] );

		return $ctx->result(
			'request-lifecycle.parse-request.pathinfo-index-front-controller',
			$ok,
			array(
				'pathinfo' => array(
					'request'      => $wp_pathinfo->request,
					'matchedRule'  => $wp_pathinfo->matched_rule,
					'matchedQuery' => $wp_pathinfo->matched_query,
					'queryVars'    => $wp_pathinfo->query_vars,
				),
				'index'    => array(
					'request'      => $wp_index->request,
					'matchedRule'  => $wp_index->matched_rule,
					'matchedQuery' => $wp_index->matched_query,
					'queryVars'    => $wp_index->query_vars,
				),
			)
		);
	}

	private static function check_parse_request_public_private_gates(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		$non_public_taxonomy = $case['privateTaxonomy'];

		$wp = self::new_wp();
		$wp->add_query_var( $case['filteredOutVar'] );

		self::set_rewrite_rules( $case['rules'] );
		self::prepare_rewrite_globals();
		$gate_request = '/site-base/gated/'
			. rawurlencode( $non_public_taxonomy )
			. '/'
			. rawurlencode( $case['gateTerm'] )
			. '/';
		self::prepare_request_server( $gate_request, '' );
		self::set_request_superglobals(
			array(
				$case['filteredOutVar'] => 'from-get-denied',
				$case['filterAddedVar'] => 'from-get-added',
				$case['privateLeakVar'] => 'from-get-leak',
				'fields'                => 'ids',
			),
			array()
		);

		$query_vars_filter_count = 0;
		$query_vars_filter       = static function (
			array $public_query_vars
		) use ( &$query_vars_filter_count, $case ): array {
			++$query_vars_filter_count;
			$public_query_vars   = array_diff( $public_query_vars, array( $case['filteredOutVar'] ) );
			$public_query_vars[] = $case['filterAddedVar'];
			return array_values( array_unique( $public_query_vars ) );
		};

		add_filter( 'query_vars', $query_vars_filter );
		register_taxonomy(
			$non_public_taxonomy,
			'post',
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'query_var'          => false,
				'rewrite'            => false,
			)
		);
		try {
			$parsed = $wp->parse_request(
				http_build_query(
					array(
						'posts_per_page'       => 3,
						$case['filterAddedVar'] => 'from-extra-added',
						$case['privateLeakVar'] => 'from-extra-leak',
					),
					'',
					'&'
				)
			);
		} finally {
			remove_filter( 'query_vars', $query_vars_filter );
			unregister_taxonomy( $non_public_taxonomy );
		}

		$ok = true === $parsed
			&& $case['gateRule'] === $wp->matched_rule
			&& 'from-extra-added' === ( $wp->query_vars[ $case['filterAddedVar'] ] ?? null )
			&& '3' === ( $wp->query_vars['posts_per_page'] ?? null )
			&& ! isset( $wp->query_vars[ $case['filteredOutVar'] ] )
			&& ! isset( $wp->query_vars[ $case['privateLeakVar'] ] )
			&& ! isset( $wp->query_vars['fields'] )
			&& ! isset( $wp->query_vars['post_type'] )
			&& ! isset( $wp->query_vars['taxonomy'], $wp->query_vars['term'] )
			&& 1 === $query_vars_filter_count
			&& false === has_filter( 'query_vars', $query_vars_filter );

		return $ctx->result(
			'request-lifecycle.parse-request.public-private-gates',
			$ok,
			array(
				'nonPublicTaxonomy' => $non_public_taxonomy,
				'matchedRule'       => $wp->matched_rule,
				'matchedQuery'      => $wp->matched_query,
				'queryVars'         => $wp->query_vars,
				'filterCount'       => $query_vars_filter_count,
			)
		);
	}

	private static function check_parse_request_short_circuit( \ComponentFuzz\FuzzContext $ctx ): array {
		$wp = self::new_wp();
		self::set_rewrite_rules(
			array(
				'library/blocked/?$' => 'index.php?pagename=blocked',
			)
		);
		self::prepare_rewrite_globals();
		self::prepare_request_server( '/site-base/library/blocked/', '' );
		self::set_request_superglobals( array(), array() );

		$seen = array();
		$filter = static function ( bool $parse, \WP $seen_wp, $extra_query_vars ) use ( &$seen, $wp ): bool {
			$seen[] = array(
				'parse' => $parse,
				'same'  => $seen_wp === $wp,
				'extra' => $extra_query_vars,
			);
			return false;
		};
		$request_count = 0;
		$request_filter = static function ( array $query_vars ) use ( &$request_count ): array {
			++$request_count;
			return $query_vars;
		};
		$action_count = 0;
		$action       = static function () use ( &$action_count ): void {
			++$action_count;
		};

		add_filter( 'do_parse_request', $filter, 10, 3 );
		add_filter( 'request', $request_filter );
		add_action( 'parse_request', $action );
		try {
			$parsed = $wp->parse_request( array( 'p' => 123 ) );
		} finally {
			remove_filter( 'do_parse_request', $filter, 10 );
			remove_filter( 'request', $request_filter );
			remove_action( 'parse_request', $action );
		}

		return $ctx->result(
			'request-lifecycle.parse-request.short-circuit-no-mutation',
			false === $parsed
				&& array() === $wp->query_vars
				&& '' === $wp->matched_rule
				&& 1 === count( $seen )
				&& true === ( $seen[0]['parse'] ?? null )
				&& true === ( $seen[0]['same'] ?? null )
				&& 0 === $request_count
				&& 0 === $action_count
				&& false === has_filter( 'do_parse_request', $filter )
				&& false === has_filter( 'request', $request_filter )
				&& false === has_filter( 'parse_request', $action ),
			array(
				'seen'         => $seen,
				'queryVars'    => $wp->query_vars,
				'requestCount' => $request_count,
				'actionCount'  => $action_count,
			)
		);
	}

	private static function check_register_globals( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$wp = self::new_wp();
		$wp->query_vars = array(
			$case['rewriteVar'] => $case['slug'],
			'p'                 => (string) $ctx->int( 100, 999 ),
			'empty_kept_out'    => '',
			'array_discarded'   => array( 'not-scalar' ),
		);
		$wp->build_query_string();

		$post = (object) array(
			'ID'           => (int) $wp->query_vars['p'],
			'post_title'   => 'Lifecycle Global ' . $case['slug'],
			'post_content' => 'body<!--nextpage-->continued',
		);
		$second_post = (object) array(
			'ID'           => (int) $wp->query_vars['p'] + 1,
			'post_title'   => 'Lifecycle Global Follow-up',
			'post_content' => 'body',
		);

		$GLOBALS['wp_query'] = new \WP_Query();
		$GLOBALS['wp_query']->query_vars = $wp->query_vars;
		$GLOBALS['wp_query']->posts      = array( $post );
		$GLOBALS['wp_query']->post       = $post;
		$GLOBALS['wp_query']->request    = 'SELECT component_fuzz';
		$GLOBALS['wp_query']->is_single  = true;
		unset( $GLOBALS['authordata'] );

		$wp->register_globals();

		$GLOBALS['posts'][] = $second_post;
		$posts_are_referenced = 2 === count( $GLOBALS['wp_query']->posts )
			&& $second_post === $GLOBALS['wp_query']->posts[1];

		$ok = $case['slug'] === ( $GLOBALS[ $case['rewriteVar'] ] ?? null )
			&& (string) $post->ID === ( $GLOBALS['p'] ?? null )
			&& $wp->query_string === ( $GLOBALS['query_string'] ?? null )
			&& str_contains( $wp->query_string, $case['rewriteVar'] . '=' . rawurlencode( $case['slug'] ) )
			&& ! str_contains( $wp->query_string, 'empty_kept_out=' )
			&& ! str_contains( $wp->query_string, 'array_discarded=' )
			&& $posts_are_referenced
			&& $GLOBALS['post'] === $post
			&& 'SELECT component_fuzz' === ( $GLOBALS['request'] ?? null )
			&& 1 === ( $GLOBALS['more'] ?? null )
			&& 1 === ( $GLOBALS['single'] ?? null )
			&& ! isset( $GLOBALS['authordata'] );

		return $ctx->result(
			'request-lifecycle.register-globals.query-loop-exports-and-aliases',
			$ok,
			array(
				'queryString'         => $wp->query_string,
				'globalPost'          => is_object( $GLOBALS['post'] ?? null ) ? get_object_vars( $GLOBALS['post'] ) : null,
				'postsAreReferenced'  => $posts_are_referenced,
				'exportedQueryVarKeys' => array_keys( $GLOBALS['wp_query']->query_vars ),
			)
		);
	}

	private static function check_handle_404_transitions( \ComponentFuzz\FuzzContext $ctx ): array {
		$statuses        = array();
		$nocache_headers = array();
		$status_filter   = static function (
			string $status_header,
			int $code,
			string $description,
			string $protocol
		) use ( &$statuses ): string {
			$statuses[] = array(
				'code'        => $code,
				'description' => $description,
				'protocol'    => $protocol,
			);
			return $status_header;
		};
		$nocache_filter = static function ( array $headers ) use ( &$nocache_headers ): array {
			$nocache_headers[] = $headers;
			return $headers;
		};
		add_filter( 'status_header', $status_filter, 10, 4 );
		add_filter( 'nocache_headers', $nocache_filter );

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

			$GLOBALS['wp_query'] = new \WP_Query();
			$GLOBALS['wp_query']->posts     = array(
				self::make_post(
					51,
					array(
						'post_content' => 'first<!--nextpage-->second',
					)
				),
			);
			$GLOBALS['wp_query']->post      = $GLOBALS['wp_query']->posts[0];
			$GLOBALS['wp_query']->is_single = true;
			$GLOBALS['wp_query']->is_singular = true;
			$wp_paged_valid = self::new_wp();
			$wp_paged_valid->query_vars = array( 'page' => '2' );
			$wp_paged_valid->handle_404();
			$paged_valid_404 = $GLOBALS['wp_query']->is_404();

			$GLOBALS['wp_query'] = new \WP_Query();
			$GLOBALS['wp_query']->posts     = array(
				self::make_post(
					52,
					array(
						'post_content' => 'single page only',
					)
				),
			);
			$GLOBALS['wp_query']->post      = $GLOBALS['wp_query']->posts[0];
			$GLOBALS['wp_query']->is_single = true;
			$GLOBALS['wp_query']->is_singular = true;
			$wp_paged_missing = self::new_wp();
			$wp_paged_missing->query_vars = array( 'page' => '2' );
			$wp_paged_missing->handle_404();
			$paged_missing_404 = $GLOBALS['wp_query']->is_404();

			$GLOBALS['wp_query'] = new \WP_Query();
			$GLOBALS['wp_query']->posts   = array();
			$GLOBALS['wp_query']->is_home = true;
			$wp_home = self::new_wp();
			$wp_home->handle_404();
			$home_404 = $GLOBALS['wp_query']->is_404();

			$preempt_filter = static fn() => true;
			add_filter( 'pre_handle_404', $preempt_filter );
			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_preempt = self::new_wp();
			$wp_preempt->handle_404();
			$preempt_is_404 = $GLOBALS['wp_query']->is_404();
			remove_filter( 'pre_handle_404', $preempt_filter );
		} finally {
			remove_filter( 'status_header', $status_filter, 10 );
			remove_filter( 'nocache_headers', $nocache_filter );
			remove_action( 'set_404', $set_404_action );
		}

		$status_codes = array_column( $statuses, 'code' );
		$ok = true === $missing_posts_404
			&& false === $found_posts_404
			&& false === $paged_valid_404
			&& true === $paged_missing_404
			&& false === $home_404
			&& false === $preempt_is_404
			&& array( 404, 200, 200, 404, 200 ) === $status_codes
			&& 2 === $set_404_count
			&& 2 === count( $nocache_headers )
			&& false === has_filter( 'status_header', $status_filter )
			&& false === has_filter( 'nocache_headers', $nocache_filter )
			&& false === has_filter( 'set_404', $set_404_action );

		return $ctx->result(
			'request-lifecycle.handle-404.status-branches-and-preempt',
			$ok,
			array(
				'statuses'          => $statuses,
				'set404Count'       => $set_404_count,
				'nocacheCount'      => count( $nocache_headers ),
				'missingPosts404'   => $missing_posts_404,
				'foundPosts404'     => $found_posts_404,
				'pagedValid404'     => $paged_valid_404,
				'pagedMissing404'   => $paged_missing_404,
				'home404'           => $home_404,
				'preemptIs404'      => $preempt_is_404,
			)
		);
	}

	private static function check_send_headers_filters_and_actions( \ComponentFuzz\FuzzContext $ctx ): array {
		$captured_headers = array();
		$statuses         = array();
		$send_actions     = array();
		$headers_filter   = static function ( array $headers, \WP $wp ) use ( &$captured_headers ): array {
			$captured_headers[] = array(
				'objectId'  => spl_object_id( $wp ),
				'headers'   => $headers,
				'queryVars' => $wp->query_vars,
			);
			$headers['X-Component-Fuzz'] = 'request-lifecycle';
			return $headers;
		};
		$status_filter = static function (
			string $status_header,
			int $code,
			string $description,
			string $protocol
		) use ( &$statuses ): string {
			$statuses[] = array(
				'code'        => $code,
				'description' => $description,
				'protocol'    => $protocol,
			);
			return $status_header;
		};
		$send_action = static function ( \WP $wp ) use ( &$send_actions ): void {
			$send_actions[] = spl_object_id( $wp );
		};
		$last_modified_filter = static function () {
			return self::FIXED_LAST_MODIFIED;
		};

		add_filter( 'wp_headers', $headers_filter, 10, 2 );
		add_filter( 'status_header', $status_filter, 10, 4 );
		add_filter( 'pre_get_lastpostmodified', $last_modified_filter );
		add_action( 'send_headers', $send_action, 10, 1 );
		try {
			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_html = self::new_wp();
			$wp_html->query_vars = array();
			self::prepare_request_server( '/site-base/plain/', '' );
			self::set_request_superglobals( array(), array() );
			$wp_html->send_headers();

			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_error = self::new_wp();
			$wp_error->query_vars = array( 'error' => 404 );
			self::prepare_request_server( '/site-base/missing/', '' );
			self::set_request_superglobals( array(), array() );
			$wp_error->send_headers();

			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_moderation = self::new_wp();
			$wp_moderation->query_vars = array();
			self::prepare_request_server( '/site-base/comment-preview/', '' );
			self::set_request_superglobals(
				array(
					'unapproved'      => '1',
					'moderation-hash' => 'component-fuzz',
				),
				array()
			);
			$wp_moderation->send_headers();

			$GLOBALS['wp_query'] = new \WP_Query();
			$wp_feed = self::new_wp();
			$wp_feed->query_vars = array( 'feed' => 'rss2' );
			self::prepare_request_server( '/site-base/feed/', '' );
			self::set_request_superglobals( array(), array() );
			$wp_feed->send_headers();

			$GLOBALS['wp_query'] = new \WP_Query();
			$GLOBALS['wp_query']->post = self::make_post(
				77,
				array(
					'ping_status'   => 'open',
					'post_password' => 'secret',
				)
			);
			$GLOBALS['wp_query']->posts     = array( $GLOBALS['wp_query']->post );
			$GLOBALS['wp_query']->is_single = true;
			$GLOBALS['wp_query']->is_singular = true;
			$wp_singular = self::new_wp();
			$wp_singular->query_vars = array();
			self::prepare_request_server( '/site-base/singular/', '' );
			self::set_request_superglobals( array(), array() );
			$wp_singular->send_headers();
		} finally {
			remove_filter( 'wp_headers', $headers_filter, 10 );
			remove_filter( 'status_header', $status_filter, 10 );
			remove_filter( 'pre_get_lastpostmodified', $last_modified_filter );
			remove_action( 'send_headers', $send_action, 10 );
		}

		$html_headers       = $captured_headers[0]['headers'] ?? array();
		$error_headers      = $captured_headers[1]['headers'] ?? array();
		$moderation_headers = $captured_headers[2]['headers'] ?? array();
		$feed_headers       = $captured_headers[3]['headers'] ?? array();
		$singular_headers   = $captured_headers[4]['headers'] ?? array();
		$status_codes       = array_column( $statuses, 'code' );
		$header_object_ids  = array_column( $captured_headers, 'objectId' );
		$expected_feed_last_modified = mysql2date( 'D, d M Y H:i:s', self::FIXED_LAST_MODIFIED, false ) . ' GMT';
		$expected_feed_etag          = '"' . md5( $expected_feed_last_modified ) . '"';

		$ok = 5 === count( $captured_headers )
			&& $header_object_ids === $send_actions
			&& array( 404 ) === $status_codes
			&& 'text/html; charset=UTF-8' === ( $html_headers['Content-Type'] ?? null )
			&& 'text/html; charset=UTF-8' === ( $error_headers['Content-Type'] ?? null )
			&& isset( $error_headers['Cache-Control'], $error_headers['Expires'] )
			&& false === ( $error_headers['Last-Modified'] ?? null )
			&& 'max-age=600, must-revalidate' === ( $moderation_headers['Cache-Control'] ?? null )
			&& isset( $moderation_headers['Expires'] )
			&& 'application/rss+xml; charset=UTF-8' === ( $feed_headers['Content-Type'] ?? null )
			&& $expected_feed_last_modified === ( $feed_headers['Last-Modified'] ?? null )
			&& $expected_feed_etag === ( $feed_headers['ETag'] ?? null )
			&& 'text/html; charset=UTF-8' === ( $singular_headers['Content-Type'] ?? null )
			&& self::SITE_URL . '/xmlrpc.php' === ( $singular_headers['X-Pingback'] ?? null )
			&& isset( $singular_headers['Cache-Control'], $singular_headers['Expires'] )
			&& false === has_filter( 'wp_headers', $headers_filter )
			&& false === has_filter( 'status_header', $status_filter )
			&& false === has_filter( 'pre_get_lastpostmodified', $last_modified_filter )
			&& false === has_filter( 'send_headers', $send_action );

		return $ctx->result(
			'request-lifecycle.send-headers.filters-status-actions-and-safe-branches',
			$ok,
			array(
				'capturedHeaders' => $captured_headers,
				'statuses'        => $statuses,
				'sendActions'     => $send_actions,
				'expectedFeed'    => array(
					'Last-Modified' => $expected_feed_last_modified,
					'ETag'          => $expected_feed_etag,
				),
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

	private static function set_rewrite_rules( array $rewrite_rules ): void {
		self::$rewrite_rules = $rewrite_rules;
	}

	private static function prepare_request_server( string $request_uri, string $path_info ): void {
		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['PHP_SELF']        = '/site-base/index.php';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = $request_uri;
		$_SERVER['PATH_INFO']       = $path_info;
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
		unset( $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
	}

	private static function set_request_superglobals( array $get, array $post ): void {
		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );
	}

	private static function make_post( int $id, array $overrides = array() ): \WP_Post {
		return new \WP_Post(
			(object) array_merge(
				array(
					'ID'            => $id,
					'post_author'   => '0',
					'post_date'     => '2024-02-03 04:05:06',
					'post_date_gmt' => '2024-02-03 04:05:06',
					'post_content'  => 'component fuzz content',
					'post_title'    => 'Component Fuzz Post ' . $id,
					'post_excerpt'  => '',
					'post_status'   => 'publish',
					'post_name'     => 'component-fuzz-post-' . $id,
					'post_type'     => 'post',
					'ping_status'   => 'closed',
					'post_password' => '',
					'filter'        => 'raw',
				),
				$overrides
			)
		);
	}

	private static function install_option_filters( array $rewrite_rules ): void {
		self::$rewrite_rules = $rewrite_rules;
		add_filter( 'pre_option_home', array( self::class, 'filter_home' ) );
		add_filter( 'pre_option_siteurl', array( self::class, 'filter_siteurl' ) );
		add_filter( 'pre_option_blog_charset', array( self::class, 'filter_blog_charset' ) );
		add_filter( 'pre_option_html_type', array( self::class, 'filter_html_type' ) );
		add_filter( 'pre_option_permalink_structure', array( self::class, 'filter_permalink_structure' ) );
		add_filter( 'pre_option_rewrite_rules', array( self::class, 'filter_rewrite_rules' ) );
	}

	private static function remove_option_filters(): void {
		remove_filter( 'pre_option_home', array( self::class, 'filter_home' ) );
		remove_filter( 'pre_option_siteurl', array( self::class, 'filter_siteurl' ) );
		remove_filter( 'pre_option_blog_charset', array( self::class, 'filter_blog_charset' ) );
		remove_filter( 'pre_option_html_type', array( self::class, 'filter_html_type' ) );
		remove_filter( 'pre_option_permalink_structure', array( self::class, 'filter_permalink_structure' ) );
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
		foreach (
			array(
				'HTTP_HOST',
				'PHP_SELF',
				'REQUEST_METHOD',
				'REQUEST_URI',
				'PATH_INFO',
				'SERVER_PROTOCOL',
				'HTTP_IF_NONE_MATCH',
				'HTTP_IF_MODIFIED_SINCE',
			) as $name
		) {
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
			'request' => self::clone_value( $_REQUEST ),
			'cookie'  => self::clone_value( $_COOKIE ),
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
		$_REQUEST = self::clone_value( $snapshot['request'] );
		$_COOKIE  = self::clone_value( $snapshot['cookie'] );
	}

	private static function state_matches( array $snapshot ): bool {
		$current = self::snapshot_state();
		return $snapshot == $current
			&& false === has_filter( 'pre_option_home', array( self::class, 'filter_home' ) )
			&& false === has_filter( 'pre_option_permalink_structure', array( self::class, 'filter_permalink_structure' ) )
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
