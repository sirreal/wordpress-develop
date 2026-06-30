<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes remote-directory REST controllers with no live WordPress.org or site HTTP calls.
 */
final class RestDirectoryServicesSurface {
	public const NAME = 'rest-directory-services';

	private const SAMPLE_BYTES = 220;

	/** @var array<string,bool> */
	private static array $granted_caps = array();

	/** @var array<string,bool> */
	private static array $denied_caps = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'rest-directory-services.bootstrap-apis-available',
					'Required REST directory service APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot           = self::snapshot_state();
		$before_fingerprint = self::state_fingerprint();
		$rows               = array();
		$ob_level           = ob_get_level();

		try {
			self::reset_runtime_state();
			self::install_scoped_filters();

			$rows[] = self::check_route_and_schema_contracts( $ctx->fork( 'routes' ) );
			$rows[] = self::check_block_directory_controller( $ctx->fork( 'block-directory' ) );
			$rows[] = self::check_pattern_directory_controller( $ctx->fork( 'pattern-directory' ) );
			$rows[] = self::check_url_details_controller( $ctx->fork( 'url-details' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'rest-directory-services.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}

			self::reset_static_state();
			self::restore_state( $snapshot );

			$after_fingerprint = self::state_fingerprint();
			$rows[]            = self::row(
				$ctx,
				'rest-directory-services.state-restored',
				$before_fingerprint === $after_fingerprint,
				array(
					'before'     => $before_fingerprint,
					'after'      => $after_fingerprint,
					'difference' => $before_fingerprint === $after_fingerprint
						? null
						: self::first_difference( $before_fingerprint, $after_fingerprint ),
				)
			);
		}

		return $rows;
	}

	public static function filter_user_has_cap( array $allcaps, array $caps = array() ): array {
		foreach ( self::$granted_caps as $cap => $grant ) {
			if ( $grant ) {
				$allcaps[ $cap ] = true;
			}
		}

		foreach ( $caps as $cap ) {
			if ( isset( self::$granted_caps[ $cap ] ) && self::$granted_caps[ $cap ] ) {
				$allcaps[ $cap ] = true;
			}
		}

		foreach ( self::$denied_caps as $cap => $deny ) {
			if ( $deny ) {
				$allcaps[ $cap ] = false;
			}
		}

		foreach ( $caps as $cap ) {
			if ( isset( self::$denied_caps[ $cap ] ) && self::$denied_caps[ $cap ] ) {
				$allcaps[ $cap ] = false;
			}
		}

		if ( isset( self::$denied_caps['do_not_allow'] ) ) {
			$allcaps['do_not_allow'] = false;
		}

		return $allcaps;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Error',
				'WP_Http',
				'WP_Post_Type',
				'WP_REST_Block_Directory_Controller',
				'WP_REST_Pattern_Directory_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Server',
				'WP_REST_URL_Details_Controller',
				'WP_User',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'current_user_can',
				'delete_site_transient',
				'get_plugins',
				'get_post_types',
				'get_site_transient',
				'has_filter',
				'is_wp_error',
				'plugins_api',
				'register_post_type',
				'register_rest_route',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_ensure_response',
				'rest_is_field_included',
				'rest_url',
				'rest_validate_value_from_schema',
				'sanitize_text_field',
				'sanitize_url',
				'set_site_transient',
				'wp_cache_flush',
				'wp_http_validate_url',
				'wp_insert_user',
				'wp_json_encode',
				'wp_kses_post',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
				'wp_safe_remote_get',
				'wp_set_current_user',
				'wp_strip_all_tags',
				'wp_trim_words',
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

	private static function check_route_and_schema_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();

		$failures           = array();
		$server             = self::fresh_server();
		$block_controller   = new \WP_REST_Block_Directory_Controller();
		$pattern_controller = new \WP_REST_Pattern_Directory_Controller();
		$url_controller     = new \WP_REST_URL_Details_Controller();

		$block_controller->register_routes();
		$pattern_controller->register_routes();
		$url_controller->register_routes();

		$routes        = $server->get_routes();
		$block_route   = '/wp/v2/block-directory/search';
		$pattern_route = '/wp/v2/pattern-directory/patterns';
		$url_route     = '/wp-block-editor/v1/url-details';

		$block_data   = $server->get_data_for_route( $block_route, $routes[ $block_route ] ?? array(), 'help' );
		$pattern_data = $server->get_data_for_route( $pattern_route, $routes[ $pattern_route ] ?? array(), 'help' );
		$url_data     = $server->get_data_for_route( $url_route, $routes[ $url_route ] ?? array(), 'help' );

		$block_params   = $block_controller->get_collection_params();
		$pattern_params = $pattern_controller->get_collection_params();
		$url_endpoint   = self::route_data_endpoint_for_methods( $url_data, array( 'GET' ) );
		$url_raw_args   = isset( $routes[ $url_route ][0]['args'] ) && is_array( $routes[ $url_route ][0]['args'] )
			? $routes[ $url_route ][0]['args']
			: array();

		self::collect_failure(
			$failures,
			isset( $routes[ $block_route ], $routes[ $pattern_route ], $routes[ $url_route ] )
				&& array( 'GET' ) === self::route_methods( $routes[ $block_route ] )
				&& array( 'GET' ) === self::route_methods( $routes[ $pattern_route ] )
				&& array( 'GET' ) === self::route_methods( $routes[ $url_route ] ),
			'directory service routes register as readable REST routes',
			array(
				'blockMethods'   => isset( $routes[ $block_route ] ) ? self::route_methods( $routes[ $block_route ] ) : null,
				'patternMethods' => isset( $routes[ $pattern_route ] ) ? self::route_methods( $routes[ $pattern_route ] ) : null,
				'urlMethods'     => isset( $routes[ $url_route ] ) ? self::route_methods( $routes[ $url_route ] ) : null,
			)
		);

		self::collect_failure(
			$failures,
			self::schema_has_properties(
				$block_controller->get_item_schema(),
				array(
					'name',
					'title',
					'description',
					'id',
					'rating',
					'rating_count',
					'active_installs',
					'author_block_rating',
					'author_block_count',
					'author',
					'icon',
					'last_updated',
					'humanized_updated',
				)
			)
				&& isset( $block_params['term'] )
				&& true === ( $block_params['term']['required'] ?? false )
				&& 1 === (int) ( $block_params['term']['minLength'] ?? 0 )
				&& 'view' === ( $block_params['context']['default'] ?? null )
				&& ! isset( $block_params['search'] )
				&& self::route_data_schema_has_properties( $block_data, array( 'name', 'rating', 'last_updated' ) )
				&& self::route_data_endpoint_has_args( $block_data, array( 'GET' ), array( 'term', 'page', 'per_page', 'context' ) ),
			'block directory route exposes required term arg and 13-property schema',
			array(
				'params'      => self::interesting_params( $block_params, array( 'term', 'search', 'context', 'page', 'per_page' ) ),
				'routeSchema' => self::schema_property_keys( $block_data ),
			)
		);

		self::collect_failure(
			$failures,
			self::schema_has_properties(
				$pattern_controller->get_item_schema(),
				array( 'id', 'title', 'content', 'categories', 'keywords', 'description', 'viewport_width', 'block_types' )
			)
				&& 100 === (int) ( $pattern_params['per_page']['default'] ?? 0 )
				&& 1 === (int) ( $pattern_params['search']['minLength'] ?? 0 )
				&& 'view' === ( $pattern_params['context']['default'] ?? null )
				&& 1 === (int) ( $pattern_params['category']['minimum'] ?? 0 )
				&& 1 === (int) ( $pattern_params['keyword']['minimum'] ?? 0 )
				&& 'array' === ( $pattern_params['slug']['type'] ?? null )
				&& array( 'asc', 'desc' ) === array_values( $pattern_params['order']['enum'] ?? array() )
				&& in_array( 'favorite_count', $pattern_params['orderby']['enum'] ?? array(), true )
				&& self::route_data_schema_has_properties( $pattern_data, array( 'id', 'content', 'block_types' ) )
				&& self::route_data_endpoint_has_args( $pattern_data, array( 'GET' ), array( 'search', 'category', 'keyword', 'slug', 'order', 'orderby' ) ),
			'pattern directory route exposes collection args, defaults, enums, and schema',
			array(
				'params'      => self::interesting_params( $pattern_params, array( 'per_page', 'search', 'context', 'category', 'keyword', 'slug', 'order', 'orderby' ) ),
				'routeSchema' => self::schema_property_keys( $pattern_data ),
			)
		);

		self::collect_failure(
			$failures,
			self::schema_has_properties( $url_controller->get_item_schema(), array( 'title', 'icon', 'description', 'image' ) )
				&& null !== $url_endpoint
				&& isset( $url_endpoint['args']['url'] )
				&& true === ( $url_endpoint['args']['url']['required'] ?? false )
				&& 'wp_http_validate_url' === self::callable_name( $url_raw_args['url']['validate_callback'] ?? null )
				&& 'sanitize_url' === self::callable_name( $url_raw_args['url']['sanitize_callback'] ?? null )
				&& 'uri' === ( $url_endpoint['args']['url']['format'] ?? null )
				&& true === ( $url_controller->get_item_schema()['properties']['title']['readonly'] ?? false )
				&& 'uri' === ( $url_controller->get_item_schema()['properties']['image']['format'] ?? null ),
			'URL details route exposes safe URL validator/sanitizer and readonly metadata schema',
			array(
				'urlArg'     => isset( $url_endpoint['args']['url'] ) ? self::describe_value( $url_endpoint['args']['url'] ) : null,
				'rawUrlArg'  => isset( $url_raw_args['url'] ) ? self::describe_value( $url_raw_args['url'] ) : null,
				'schemaKeys' => array_keys( $url_controller->get_item_schema()['properties'] ?? array() ),
			)
		);

		$delete_response = self::dispatch( $server, self::request( 'DELETE', $url_route ) );
		self::collect_failure(
			$failures,
			self::response_error_ok( $delete_response, 'rest_no_route', 404 ),
			'unsupported methods fail before callbacks',
			array( 'delete' => $delete_response )
		);

		return self::row(
			$ctx,
			'rest-directory-services.route-args-schema-contracts',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_block_directory_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();
		self::seed_current_user( $ctx, 'block' );
		self::set_granted_caps( array( 'activate_plugins', 'install_plugins' ) );

		$failures     = array();
		$case         = self::block_case( $ctx );
		$mode         = 'success';
		$plugin_calls = array();
		$http_calls   = array();

		$plugins_filter = static function ( $result, string $action, $args ) use ( &$plugin_calls, &$mode, $case ) {
			$plugin_calls[] = array(
				'action'   => $action,
				'block'    => $args->block ?? null,
				'per_page' => $args->per_page ?? null,
				'page'     => $args->page ?? null,
				'locale'   => $args->locale ?? null,
			);

			if ( 'query_plugins' !== $action ) {
				return $result;
			}

			if ( 'error' === $mode ) {
				return new \WP_Error( 'plugins_api_failed', 'Synthetic plugin API failure.' );
			}

			return (object) array(
				'plugins' => array(
					$case['plugin'],
					$case['emptyBlocksPlugin'],
				),
			);
		};

		$http_guard = static function ( $preempt, array $parsed_args, string $url ) use ( &$http_calls ) {
			unset( $parsed_args );
			$http_calls[] = $url;
			return new \WP_Error( 'component_fuzz_unregistered_http', 'Unexpected live HTTP from block directory.' );
		};

		\add_filter( 'plugins_api', $plugins_filter, 10, 3 );
		\add_filter( 'pre_http_request', $http_guard, 10, 3 );

		try {
			$server     = self::fresh_server();
			$controller = new \WP_REST_Block_Directory_Controller();
			$controller->register_routes();

			$response = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/block-directory/search',
					array(
						'term'     => $case['term'],
						'per_page' => $case['perPage'],
						'page'     => $case['page'],
					)
				)
			);
			$data     = $response->get_data();
			$item     = is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ? $data[0] : array();

			self::collect_failure(
				$failures,
				200 === $response->get_status()
					&& 1 === count( is_array( $data ) ? $data : array() )
					&& $case['firstBlockName'] === ( $item['name'] ?? null )
					&& $case['plugin']['name'] === ( $item['title'] ?? null )
					&& $case['plugin']['slug'] === ( $item['id'] ?? null )
					&& $case['expectedDescription'] === ( $item['description'] ?? null )
					&& 4.25 === ( $item['rating'] ?? null )
					&& 3.5 === ( $item['author_block_rating'] ?? null )
					&& $case['expectedAuthor'] === ( $item['author'] ?? null )
					&& 'block-default' === ( $item['icon'] ?? null )
					&& 17 === ( $item['rating_count'] ?? null )
					&& 2500 === ( $item['active_installs'] ?? null )
					&& 6 === ( $item['author_block_count'] ?? null )
					&& $case['expectedLastUpdated'] === ( $item['last_updated'] ?? null )
					&& self::schema_valid( $item, $controller->get_item_schema() ),
				'block directory maps first block, skips empty block plugins, normalizes fields, and validates schema',
				array(
					'status' => $response->get_status(),
					'data'   => $data,
				)
			);

			self::collect_failure(
				$failures,
				1 === count( $plugin_calls )
					&& $case['term'] === ( $plugin_calls[0]['block'] ?? null )
					&& $case['perPage'] === (int) ( $plugin_calls[0]['per_page'] ?? 0 )
					&& $case['page'] === (int) ( $plugin_calls[0]['page'] ?? 0 )
					&& array() === $http_calls,
				'block directory short-circuits plugins_api and never reaches WordPress.org HTTP',
				array(
					'pluginCalls' => $plugin_calls,
					'httpCalls'   => $http_calls,
				)
			);

			$link_request = self::request(
				'GET',
				'/wp/v2/block-directory/search',
				array(
					'term'     => $case['term'],
					'per_page' => $case['perPage'],
					'page'     => $case['page'],
					'_fields'  => 'id,name,_links',
				)
			);
			$links_item   = $controller->prepare_item_for_response( $case['plugin'], $link_request );
			$install_href = $links_item instanceof \WP_REST_Response
				? self::link_href( $links_item->get_links(), 'https://api.w.org/install-plugin' )
				: null;

			self::collect_failure(
				$failures,
				$links_item instanceof \WP_REST_Response
					&& is_string( $install_href )
					&& ( str_contains( $install_href, '/wp/v2/plugins' ) || str_contains( $install_href, 'rest_route=%2Fwp%2Fv2%2Fplugins' ) )
					&& str_contains( $install_href, 'slug=' . rawurlencode( $case['plugin']['slug'] ) ),
				'block directory prepared item exposes install-plugin link when _links are requested',
				array(
					'links'       => $links_item instanceof \WP_REST_Response ? $links_item->get_links() : null,
					'installHref' => $install_href,
				)
			);

			$mode           = 'error';
			$error_response = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/block-directory/search',
					array(
						'term'     => $case['term'] . '-error',
						'per_page' => 1,
						'page'     => 1,
					)
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $error_response, 'plugins_api_failed', 500 ),
				'block directory converts plugins_api WP_Error to a 500 REST error',
				array( 'response' => $error_response )
			);

			$missing_term = self::dispatch( $server, self::request( 'GET', '/wp/v2/block-directory/search' ) );
			self::collect_failure(
				$failures,
				self::response_error_ok( $missing_term, 'rest_missing_callback_param', 400 ),
				'block directory rejects missing required term before callback',
				array( 'response' => $missing_term )
			);

			self::set_granted_caps( array( 'activate_plugins', 'install_plugins' ) );
			self::set_denied_caps( array( 'install_plugins' ) );
			$deny_install = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/block-directory/search',
					array( 'term' => $case['term'] )
				)
			);

			self::set_granted_caps( array( 'activate_plugins', 'install_plugins' ) );
			self::set_denied_caps( array( 'activate_plugins' ) );
			$deny_activate = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/block-directory/search',
					array( 'term' => $case['term'] )
				)
			);

			self::clear_caps();
			\wp_set_current_user( 0 );
			$logged_out = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/block-directory/search',
					array( 'term' => $case['term'] )
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $deny_install, 'rest_block_directory_cannot_view', 403 )
					&& self::response_error_ok( $deny_activate, 'rest_block_directory_cannot_view', 403 )
					&& self::response_error_ok( $logged_out, 'rest_block_directory_cannot_view', 401 ),
				'block directory permission requires both install_plugins and activate_plugins',
				array(
					'denyInstall'  => $deny_install,
					'denyActivate' => $deny_activate,
					'loggedOut'    => $logged_out,
				)
			);
		} finally {
			\remove_filter( 'plugins_api', $plugins_filter, 10 );
			\remove_filter( 'pre_http_request', $http_guard, 10 );
		}

		return self::row(
			$ctx,
			'rest-directory-services.block-directory-transform-permissions-network',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_pattern_directory_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();
		self::seed_current_user( $ctx, 'pattern' );
		self::set_granted_caps( array( 'edit_posts' ) );

		$failures       = array();
		$case           = self::pattern_case( $ctx );
		$mode           = 'success';
		$http_calls     = array();
		$transient_sets = array();
		$prepare_calls  = array();
		$params_calls   = 0;

		$http_filter = static function ( $preempt, array $parsed_args, string $url ) use ( &$http_calls, &$mode, $case ) {
			unset( $preempt );
			$parts = parse_url( $url );
			if ( ! isset( $parts['host'], $parts['path'] ) || 'api.wordpress.org' !== $parts['host'] || '/patterns/1.0/' !== $parts['path'] ) {
				return new \WP_Error( 'component_fuzz_unregistered_http', 'Unexpected live HTTP from pattern directory: ' . $url );
			}

			$query = array();
			if ( isset( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
			}

			$http_calls[] = array(
				'url'   => $url,
				'query' => $query,
				'args'  => $parsed_args,
				'mode'  => $mode,
			);

			if ( 'transport-error' === $mode ) {
				return new \WP_Error( 'component_fuzz_pattern_http_failed', 'Synthetic pattern directory transport failure.' );
			}

			if ( 'invalid-json' === $mode ) {
				return self::http_response( '{not-json', 200 );
			}

			return self::http_response( \wp_json_encode( array( $case['rawPattern'] ) ), 200 );
		};

		$transient_action = static function ( string $transient, $value, int $expiration ) use ( &$transient_sets ): void {
			$transient_sets[] = array(
				'transient'  => $transient,
				'expiration' => $expiration,
				'valueType'   => is_object( $value ) ? get_class( $value ) : gettype( $value ),
			);
		};

		$prepare_filter = static function ( \WP_REST_Response $response, $raw_pattern, \WP_REST_Request $request ) use ( &$prepare_calls ): \WP_REST_Response {
			$prepare_calls[] = array(
				'id'     => $raw_pattern->id ?? null,
				'method' => $request->get_method(),
			);
			return $response;
		};

		$params_filter = static function ( array $params ) use ( &$params_calls ): array {
			++$params_calls;
			$params['component_fuzz_marker'] = array(
				'type'        => 'string',
				'description' => 'Component fuzz marker.',
			);
			return $params;
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		\add_action( 'set_site_transient', $transient_action, 10, 3 );
		\add_filter( 'rest_prepare_block_pattern', $prepare_filter, 10, 3 );
		\add_filter( 'rest_pattern_directory_collection_params', $params_filter );

		try {
			$server     = self::fresh_server();
			$controller = new \WP_REST_Pattern_Directory_Controller();
			$controller->register_routes();

			$params = $controller->get_collection_params();
			self::collect_failure(
				$failures,
				isset( $params['component_fuzz_marker'] ) && $params_calls > 0,
				'pattern directory collection params filter is applied',
				array(
					'paramsCalls' => $params_calls,
					'hasMarker'   => isset( $params['component_fuzz_marker'] ),
				)
			);

			$response = self::dispatch( $server, self::request( 'GET', '/wp/v2/pattern-directory/patterns', $case['query'] ) );
			$data     = $response->get_data();
			$item     = is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ? $data[0] : array();

			self::collect_failure(
				$failures,
				200 === $response->get_status()
					&& 1 === count( is_array( $data ) ? $data : array() )
					&& $case['expected']['id'] === ( $item['id'] ?? null )
					&& $case['expected']['title'] === ( $item['title'] ?? null )
					&& $case['expected']['content'] === ( $item['content'] ?? null )
					&& $case['expected']['categories'] === ( $item['categories'] ?? null )
					&& $case['expected']['keywords'] === ( $item['keywords'] ?? null )
					&& $case['expected']['description'] === ( $item['description'] ?? null )
					&& $case['expected']['viewport_width'] === ( $item['viewport_width'] ?? null )
					&& $case['expected']['block_types'] === ( $item['block_types'] ?? null )
					&& ! array_key_exists( 'extra_remote_field', $item )
					&& self::schema_valid( $item, $controller->get_item_schema() ),
				'pattern directory sanitizes remote pattern fields, drops extras, and validates schema',
				array(
					'status' => $response->get_status(),
					'item'   => $item,
				)
			);

			$query = $http_calls[0]['query'] ?? array();
			self::collect_failure(
				$failures,
				1 === count( $http_calls )
					&& isset( $query['locale'], $query['wp-version'] )
					&& $case['query']['category'] === (int) ( $query['pattern-categories'] ?? 0 )
					&& $case['query']['keyword'] === (int) ( $query['pattern-keywords'] ?? 0 )
					&& $case['query']['search'] === ( $query['search'] ?? null )
					&& $case['query']['order'] === ( $query['order'] ?? null )
					&& $case['query']['orderby'] === ( $query['orderby'] ?? null )
					&& self::latest_transient_has( $transient_sets, 'wp_remote_block_patterns_', \HOUR_IN_SECONDS ),
				'pattern directory remaps query args and caches valid WordPress.org responses for one hour',
				array(
					'httpCalls'     => $http_calls,
					'transientSets' => $transient_sets,
				)
			);

			$reordered_query         = $case['query'];
			$reordered_query['slug'] = array_reverse( $case['query']['slug'] );
			$cached_response         = self::dispatch( $server, self::request( 'GET', '/wp/v2/pattern-directory/patterns', $reordered_query ) );
			$head_response           = self::dispatch( $server, self::request( 'HEAD', '/wp/v2/pattern-directory/patterns', $reordered_query ) );

			self::collect_failure(
				$failures,
				200 === $cached_response->get_status()
					&& $data === $cached_response->get_data()
					&& 1 === count( $http_calls )
					&& 2 === count( $prepare_calls )
					&& 200 === $head_response->get_status()
					&& array() === $head_response->get_data()
					&& 2 === count( $prepare_calls ),
				'pattern directory cache key sorts slug arrays and HEAD returns cached empty data without prepare callbacks',
				array(
					'httpCallCount'    => count( $http_calls ),
					'prepareCallCount' => count( $prepare_calls ),
					'headData'         => $head_response->get_data(),
				)
			);

			$hostile_query = array_merge(
				$case['query'],
				array(
					'search'             => $case['query']['search'] . '-hostile',
					'category'           => $case['query']['category'] + 100,
					'keyword'            => $case['query']['keyword'] + 100,
					'locale'             => 'zz_ZZ',
					'wp-version'         => '0.0-hostile',
					'pattern-categories' => '999999',
					'pattern-keywords'   => '999998',
					'unknown-proxy-key'  => 'must-not-leak',
				)
			);
			$hostile_response = self::dispatch( $server, self::request( 'GET', '/wp/v2/pattern-directory/patterns', $hostile_query ) );
			$hostile_call     = $http_calls[ count( $http_calls ) - 1 ] ?? array();
			$hostile_url_args = $hostile_call['query'] ?? array();

			self::collect_failure(
				$failures,
				200 === $hostile_response->get_status()
					&& 2 === count( $http_calls )
					&& $hostile_query['search'] === ( $hostile_url_args['search'] ?? null )
					&& $hostile_query['category'] === (int) ( $hostile_url_args['pattern-categories'] ?? 0 )
					&& $hostile_query['keyword'] === (int) ( $hostile_url_args['pattern-keywords'] ?? 0 )
					&& 'zz_ZZ' !== ( $hostile_url_args['locale'] ?? null )
					&& '0.0-hostile' !== ( $hostile_url_args['wp-version'] ?? null )
					&& ! array_key_exists( 'category', $hostile_url_args )
					&& ! array_key_exists( 'keyword', $hostile_url_args )
					&& ! array_key_exists( 'unknown-proxy-key', $hostile_url_args ),
				'pattern directory proxies only allowlisted query args and overwrites client-supplied derived WordPress.org args',
				array(
					'hostileQuery' => $hostile_query,
					'httpCall'     => $hostile_call,
				)
			);

			$mode         = 'invalid-json';
			$invalid_json = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/pattern-directory/patterns',
					array_merge( $case['query'], array( 'search' => $case['query']['search'] . '-invalid' ) )
				)
			);

			$mode            = 'transport-error';
			$transport_error = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/pattern-directory/patterns',
					array_merge( $case['query'], array( 'search' => $case['query']['search'] . '-transport' ) )
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $invalid_json, 'pattern_api_failed', 500 )
					&& self::response_error_ok( $transport_error, 'component_fuzz_pattern_http_failed', 500 )
					&& self::latest_transient_has( $transient_sets, 'wp_remote_block_patterns_', 5 ),
				'pattern directory returns 500 and short-lived cache entries for invalid JSON and transport errors',
				array(
					'invalidJson'   => $invalid_json,
					'transport'     => $transport_error,
					'transientSets' => $transient_sets,
				)
			);

			self::set_granted_caps( array() );
			self::set_denied_caps( array( 'edit_posts' ) );
			self::seed_current_user( $ctx, 'pattern-denied' );
			$denied = self::dispatch( $server, self::request( 'GET', '/wp/v2/pattern-directory/patterns', $case['query'] ) );

			self::clear_caps();
			\wp_set_current_user( 0 );
			$logged_out = self::dispatch( $server, self::request( 'GET', '/wp/v2/pattern-directory/patterns', $case['query'] ) );

			self::seed_current_user( $ctx, 'pattern-fallback' );
			$fallback_cap = self::register_rest_visible_post_type_with_cap( $ctx, 'pattern' );
			self::set_granted_caps( array( $fallback_cap ) );
			self::set_denied_caps( array( 'edit_posts' ) );
			$fallback_allowed = $controller->get_items_permissions_check( self::request( 'GET', '/wp/v2/pattern-directory/patterns', $case['query'] ) );

			self::collect_failure(
				$failures,
				self::response_error_ok( $denied, 'rest_pattern_directory_cannot_view', 403 )
					&& self::response_error_ok( $logged_out, 'rest_pattern_directory_cannot_view', 401 )
					&& true === $fallback_allowed,
				'pattern directory permission supports edit_posts or REST-visible post-type edit cap, and denies others',
				array(
					'denied'          => $denied,
					'loggedOut'       => $logged_out,
					'fallbackCap'     => $fallback_cap,
					'fallbackAllowed' => $fallback_allowed,
				)
			);
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			\remove_action( 'set_site_transient', $transient_action, 10 );
			\remove_filter( 'rest_prepare_block_pattern', $prepare_filter, 10 );
			\remove_filter( 'rest_pattern_directory_collection_params', $params_filter );
		}

		return self::row(
			$ctx,
			'rest-directory-services.pattern-directory-query-cache-permissions',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 7 ) )
		);
	}

	private static function check_url_details_controller( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime_state();
		self::seed_current_user( $ctx, 'url' );
		self::set_granted_caps( array( 'edit_posts' ) );

		$failures       = array();
		$case           = self::url_case( $ctx );
		$http_calls     = array();
		$request_args   = array();
		$prepare_calls  = array();
		$cache_ttls     = array();
		$transient_sets = array();

		$http_filter = static function ( $preempt, array $parsed_args, string $url ) use ( &$http_calls, $case ) {
			unset( $preempt );
			$http_calls[] = array(
				'url'  => $url,
				'args' => $parsed_args,
			);

			if ( $case['url'] === $url || $case['urlNoTrailingSlash'] === $url ) {
				return self::http_response( $case['html'], 200 );
			}

			if ( $case['dataIconUrl'] === $url ) {
				return self::http_response( $case['dataIconHtml'], 200 );
			}

			if ( $case['fallbackHeadUrl'] === $url ) {
				return self::http_response( $case['fallbackHeadHtml'], 200 );
			}

			if ( $case['non200Url'] === $url ) {
				return self::http_response( '<html><head><title>Unavailable</title></head></html>', 503 );
			}

			if ( $case['emptyUrl'] === $url ) {
				return self::http_response( '', 200 );
			}

			return new \WP_Error( 'component_fuzz_unregistered_http', 'Unexpected live HTTP from URL details: ' . $url );
		};

		$request_args_filter = static function ( array $args, string $url ) use ( &$request_args ): array {
			$request_args[] = array(
				'url'  => $url,
				'args' => $args,
			);
			return $args;
		};

		$ttl_filter = static function ( int $ttl ) use ( &$cache_ttls, $case ): int {
			$cache_ttls[] = $ttl;
			return $case['cacheTtl'];
		};

		$prepare_filter = static function ( \WP_REST_Response $response, string $url, \WP_REST_Request $request, string $remote_body ) use ( &$prepare_calls ): \WP_REST_Response {
			$prepare_calls[] = array(
				'url'        => $url,
				'method'     => $request->get_method(),
				'bodyLength' => strlen( $remote_body ),
			);
			return $response;
		};

		$transient_action = static function ( string $transient, $value, int $expiration ) use ( &$transient_sets ): void {
			$transient_sets[] = array(
				'transient'  => $transient,
				'expiration' => $expiration,
				'valueType'   => is_object( $value ) ? get_class( $value ) : gettype( $value ),
			);
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		\add_filter( 'rest_url_details_http_request_args', $request_args_filter, 10, 2 );
		\add_filter( 'rest_url_details_cache_expiration', $ttl_filter );
		\add_filter( 'rest_prepare_url_details', $prepare_filter, 10, 4 );
		\add_action( 'set_site_transient', $transient_action, 10, 3 );

		try {
			$server     = self::fresh_server();
			$controller = new \WP_REST_URL_Details_Controller();
			$controller->register_routes();

			$response = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['url'] )
				)
			);
			$data     = $response->get_data();

			self::collect_failure(
				$failures,
				200 === $response->get_status()
					&& $case['expectedTitle'] === ( $data['title'] ?? null )
					&& $case['expectedIcon'] === ( $data['icon'] ?? null )
					&& $case['expectedDescription'] === ( $data['description'] ?? null )
					&& $case['expectedImage'] === ( $data['image'] ?? null )
					&& self::schema_valid( $data, $controller->get_item_schema() ),
				'URL details parses head metadata, strips tags/entities, absolutizes relative media, and validates schema',
				array(
					'status' => $response->get_status(),
					'data'   => $data,
				)
			);

			$first_args = $request_args[0]['args'] ?? array();
			self::collect_failure(
				$failures,
				1 === count( $http_calls )
					&& 150 * \KB_IN_BYTES === (int) ( $first_args['limit_response_size'] ?? 0 )
					&& isset( $first_args['user-agent'] )
					&& str_starts_with( (string) $first_args['user-agent'], 'WP-URLDetails/' )
					&& self::latest_transient_has( $transient_sets, 'g_url_details_response_', $case['cacheTtl'] )
					&& \HOUR_IN_SECONDS === ( $cache_ttls[0] ?? null ),
				'URL details uses bounded WP HTTP args, custom user agent, and filtered success-cache TTL',
				array(
					'httpCalls'     => $http_calls,
					'requestArgs'   => $request_args,
					'cacheTtls'     => $cache_ttls,
					'transientSets' => $transient_sets,
				)
			);

			$cached_response = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['urlNoTrailingSlash'] )
				)
			);

			self::collect_failure(
				$failures,
				200 === $cached_response->get_status()
					&& $data === $cached_response->get_data()
					&& 1 === count( $http_calls )
					&& 2 === count( $prepare_calls ),
				'URL details cache key untrailingslashes URL and cache hits still run prepare filter without HTTP',
				array(
					'httpCallCount'    => count( $http_calls ),
					'prepareCallCount' => count( $prepare_calls ),
				)
			);

			$data_icon_response = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['dataIconUrl'] )
				)
			);
			$data_icon_data     = $data_icon_response->get_data();

			self::collect_failure(
				$failures,
				200 === $data_icon_response->get_status()
					&& $case['dataIcon'] === ( $data_icon_data['icon'] ?? null )
					&& '' === ( $data_icon_data['image'] ?? null ),
				'URL details preserves data URL icons without absolutizing them',
				array( 'data' => $data_icon_data )
			);

			$fallback_head_response = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['fallbackHeadUrl'] )
				)
			);
			$fallback_head_data     = $fallback_head_response->get_data();

			self::collect_failure(
				$failures,
				200 === $fallback_head_response->get_status()
					&& $case['expectedFallbackTitle'] === ( $fallback_head_data['title'] ?? null )
					&& $case['expectedFallbackIcon'] === ( $fallback_head_data['icon'] ?? null )
					&& $case['expectedFallbackDescription'] === ( $fallback_head_data['description'] ?? null )
					&& $case['expectedFallbackImage'] === ( $fallback_head_data['image'] ?? null )
					&& self::schema_valid( $fallback_head_data, $controller->get_item_schema() ),
				'URL details extracts unclosed head metadata before body and preserves first matching description/image variants',
				array(
					'status' => $fallback_head_response->get_status(),
					'data'   => $fallback_head_data,
				)
			);

			$non200 = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['non200Url'] )
				)
			);
			$empty  = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['emptyUrl'] )
				)
			);
			$invalid = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => 'ftp://example.test/' . $case['token'] )
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $non200, 'no_response', 404 )
					&& self::response_error_ok( $empty, 'no_content', 404 )
					&& self::response_error_ok( $invalid, 'rest_invalid_param', 400 ),
				'URL details rejects unsafe URLs and distinguishes non-200 from empty successful bodies',
				array(
					'non200'  => $non200,
					'empty'   => $empty,
					'invalid' => $invalid,
				)
			);

			self::set_granted_caps( array() );
			self::set_denied_caps( array( 'edit_posts' ) );
			self::seed_current_user( $ctx, 'url-denied' );
			$denied = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['url'] )
				)
			);

			self::clear_caps();
			\wp_set_current_user( 0 );
			$logged_out = self::dispatch(
				$server,
				self::request(
					'GET',
					'/wp-block-editor/v1/url-details',
					array( 'url' => $case['url'] )
				)
			);

			self::seed_current_user( $ctx, 'url-fallback' );
			$fallback_cap = self::register_rest_visible_post_type_with_cap( $ctx, 'url' );
			self::set_granted_caps( array( $fallback_cap ) );
			self::set_denied_caps( array( 'edit_posts' ) );
			$fallback_allowed = $controller->permissions_check();

			self::collect_failure(
				$failures,
				self::response_error_ok( $denied, 'rest_cannot_view_url_details', 403 )
					&& self::response_error_ok( $logged_out, 'rest_cannot_view_url_details', 401 )
					&& true === $fallback_allowed,
				'URL details permission supports edit_posts or REST-visible post-type edit cap, and denies others',
				array(
					'denied'          => $denied,
					'loggedOut'       => $logged_out,
					'fallbackCap'     => $fallback_cap,
					'fallbackAllowed' => $fallback_allowed,
				)
			);
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			\remove_filter( 'rest_url_details_http_request_args', $request_args_filter, 10 );
			\remove_filter( 'rest_url_details_cache_expiration', $ttl_filter );
			\remove_filter( 'rest_prepare_url_details', $prepare_filter, 10 );
			\remove_action( 'set_site_transient', $transient_action, 10 );
		}

		return self::row(
			$ctx,
			'rest-directory-services.url-details-parse-cache-permissions',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 7 ) )
		);
	}

	private static function install_scoped_filters(): void {
		\add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 10, 4 );
	}

	private static function reset_runtime_state(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/rest-directory-services';
		$_SERVER['HTTPS']           = 'on';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.77';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-json/';
		$_SERVER['SERVER_PORT']     = '443';

		self::clear_caps();

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function reset_static_state(): void {
		self::clear_caps();
	}

	private static function fresh_server(): \WP_REST_Server {
		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		if ( function_exists( 'rest_api_default_filters' ) ) {
			\rest_api_default_filters();
		}
		return $server;
	}

	private static function dispatch( \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		$response = \rest_ensure_response( $server->dispatch( $request ) );
		if ( \is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			return new \WP_REST_Response(
				array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
					'data'    => array( 'status' => $status ),
				),
				$status
			);
		}

		return \apply_filters( 'rest_post_dispatch', $response, $server, $request );
	}

	private static function seed_current_user( \ComponentFuzz\FuzzContext $ctx, string $label ): int {
		$token = self::token( $ctx, $label );
		$user  = \wp_insert_user(
			array(
				'display_name' => 'Directory Services ' . $label,
				'user_email'   => 'rest-directory-' . $token . '@example.test',
				'user_login'   => 'cfz_dir_' . $token,
				'user_pass'    => 'pass-' . $token,
			)
		);
		if ( \is_wp_error( $user ) ) {
			throw new \RuntimeException( 'Could not create REST directory services user: ' . $user->get_error_code() );
		}

		\wp_set_current_user( (int) $user );
		return (int) $user;
	}

	private static function set_granted_caps( array $caps ): void {
		self::$granted_caps = array_fill_keys( $caps, true );
	}

	private static function set_denied_caps( array $caps ): void {
		self::$denied_caps = array_fill_keys( $caps, true );
	}

	private static function clear_caps(): void {
		self::$granted_caps = array();
		self::$denied_caps  = array();
	}

	private static function request( string $method, string $route, array $query_params = array(), array $url_params = array(), array $body_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( array() !== $query_params ) {
			$request->set_query_params( $query_params );
		}
		if ( array() !== $url_params ) {
			$request->set_url_params( $url_params );
		}
		if ( array() !== $body_params ) {
			$request->set_body_params( $body_params );
		}
		return $request;
	}

	private static function block_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token       = self::token( $ctx, 'block' );
		$slug        = 'cfz-block-' . $token;
		$description = implode( ' ', array_map( static fn ( int $i ): string => 'word' . $i, range( 1, 38 ) ) );
		$updated     = '2025-04-03 02:01:09';
		$plugin      = array(
			'name'                => 'Component Fuzz Block Plugin ' . $token,
			'short_description'   => $description,
			'slug'                => $slug,
			'rating'              => 85,
			'num_ratings'         => '17',
			'active_installs'     => '2500',
			'author_block_rating' => 70,
			'author_block_count'  => '6',
			'author'              => '<a href="https://profiles.wordpress.org/fuzz">Fuzz Author ' . $token . '</a>',
			'icons'               => array(),
			'last_updated'        => $updated,
			'blocks'              => array(
				array(
					'name'  => 'component-fuzz/' . $slug,
					'title' => '',
				),
				array(
					'name'  => 'component-fuzz/ignored-' . $slug,
					'title' => 'Ignored block title',
				),
			),
		);

		return array(
			'token'               => $token,
			'term'                => 'fuzz ' . $token,
			'perPage'             => $ctx->int( 2, 12 ),
			'page'                => $ctx->int( 1, 5 ),
			'plugin'              => $plugin,
			'emptyBlocksPlugin'   => array_merge(
				$plugin,
				array(
					'name'   => 'Skipped Plugin ' . $token,
					'slug'   => $slug . '-empty',
					'blocks' => array(),
				)
			),
			'firstBlockName'      => 'component-fuzz/' . $slug,
			'expectedAuthor'      => \wp_strip_all_tags( $plugin['author'] ),
			'expectedDescription' => \wp_trim_words( $description, 30, '...' ),
			'expectedLastUpdated' => gmdate( 'Y-m-d\TH:i:s', strtotime( $updated ) ),
		);
	}

	private static function pattern_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token       = self::token( $ctx, 'pattern' );
		$raw_pattern = array(
			'id'                 => (string) $ctx->int( 101, 999 ),
			'title'              => array( 'rendered' => '<b>Pattern &amp; Title ' . $token . '</b>' ),
			'pattern_content'    => '<!-- wp:paragraph --><p>Allowed ' . $token . '</p><script>bad()</script><!-- /wp:paragraph -->',
			'category_slugs'     => array( 'Hero Section', 'News & Updates' ),
			'meta'               => array(
				'wpop_keywords'       => 'alpha,<b>beta</b>, spaces  ',
				'wpop_description'    => '<em>Description &amp; details ' . $token . '</em>',
				'wpop_viewport_width' => '1200px',
				'wpop_block_types'    => array( 'core/post-content', '<b>core/query</b>' ),
			),
			'extra_remote_field' => 'must not be returned',
		);

		$query = array(
			'search'   => 'pattern ' . $token,
			'category' => $ctx->int( 1, 20 ),
			'keyword'  => $ctx->int( 21, 40 ),
			'slug'     => array( 'beta-' . $token, 'alpha-' . $token ),
			'per_page' => $ctx->int( 1, 15 ),
			'page'     => $ctx->int( 1, 4 ),
			'offset'   => $ctx->int( 0, 3 ),
			'order'    => $ctx->choice( array( 'asc', 'desc' ) ),
			'orderby'  => $ctx->choice( array( 'date', 'title', 'favorite_count' ) ),
		);

		return array(
			'token'      => $token,
			'query'      => $query,
			'rawPattern' => json_decode( \wp_json_encode( $raw_pattern ) ),
			'expected'   => array(
				'id'             => absint( $raw_pattern['id'] ),
				'title'          => \sanitize_text_field( $raw_pattern['title']['rendered'] ),
				'content'        => \wp_kses_post( $raw_pattern['pattern_content'] ),
				'categories'     => array_map( 'sanitize_title', $raw_pattern['category_slugs'] ),
				'keywords'       => array_map( 'sanitize_text_field', explode( ',', $raw_pattern['meta']['wpop_keywords'] ) ),
				'description'    => \sanitize_text_field( $raw_pattern['meta']['wpop_description'] ),
				'viewport_width' => absint( $raw_pattern['meta']['wpop_viewport_width'] ),
				'block_types'    => array_map( 'sanitize_text_field', $raw_pattern['meta']['wpop_block_types'] ),
			),
		);
	}

	private static function url_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token          = self::token( $ctx, 'url' );
		$url            = 'https://example.test/url-details-' . $token . '/';
		$data_icon      = 'data:image/svg+xml;base64,PHN2Zy8+';
		$html           = '<!doctype html><html><head><title> Fuzz &amp; <b>Title ' . $token . '</b> </title>'
			. '<link rel="icon" href="/assets/favicon-' . $token . '.ico">'
			. '<meta name="description" content="Remote &amp; <strong>description ' . $token . '</strong>">'
			. '<meta property="og:image" content="/images/card-' . $token . '.png">'
			. '</head><body>ignored</body></html>';
		$data_icon_html = '<html><head><title>Data Icon</title><link rel="icon" href="' . $data_icon . '"></head></html>';
		$fallback_html  = '<!doctype html><html><head data-fuzz="' . $token . '"><title>Fallback &amp; <em>Title ' . $token . '</em></title>'
			. '<link rel="shortcut icon" href="favicons/fallback-' . $token . '.ico">'
			. '<meta name="og:description" content="OG &amp; <strong>description ' . $token . '</strong>">'
			. '<meta name="description" content="Ignored later description ' . $token . '">'
			. '<meta property="og:image:url" content="images/fallback-' . $token . '.png">'
			. '<meta property="og:image" content="images/ignored-' . $token . '.png">'
			. '<body><title>Ignored Body Title</title><meta name="description" content="ignored body ' . $token . '"></body></html>';

		return array(
			'token'                  => $token,
			'url'                    => $url,
			'urlNoTrailingSlash'     => untrailingslashit( $url ),
			'dataIconUrl'            => 'https://example.test/url-details-data-icon-' . $token,
			'fallbackHeadUrl'        => 'https://example.test/url-details-head-fallback-' . $token,
			'non200Url'              => 'https://example.test/url-details-not-found-' . $token,
			'emptyUrl'               => 'https://example.test/url-details-empty-' . $token,
			'html'                   => $html,
			'dataIconHtml'           => $data_icon_html,
			'fallbackHeadHtml'       => $fallback_html,
			'dataIcon'               => $data_icon,
			'cacheTtl'               => 137 + $ctx->int( 1, 50 ),
			'expectedTitle'          => 'Fuzz & Title ' . $token,
			'expectedIcon'           => 'https://example.test/assets/favicon-' . $token . '.ico',
			'expectedDescription'    => 'Remote & description ' . $token,
			'expectedImage'          => 'https://example.test/images/card-' . $token . '.png',
			'expectedFallbackTitle'       => 'Fallback & Title ' . $token,
			'expectedFallbackIcon'        => 'https://example.test/favicons/fallback-' . $token . '.ico',
			'expectedFallbackDescription' => 'OG & description ' . $token,
			'expectedFallbackImage'       => 'https://example.test/images/fallback-' . $token . '.png',
		);
	}

	private static function register_rest_visible_post_type_with_cap( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$token       = substr( self::token( $ctx, $label . '-post-type' ), 0, 10 );
		$post_type   = 'cfz_' . substr( preg_replace( '/[^a-z0-9_]/', '', $label ), 0, 2 ) . '_' . $token;
		$plural_base = 'cfz_' . str_replace( '-', '_', $label ) . '_items_' . $token;
		$registered  = \register_post_type(
			$post_type,
			array(
				'label'           => 'Directory Services ' . $label,
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => true,
				'rewrite'         => false,
				'query_var'       => false,
				'capability_type' => array( 'cfz_' . $label . '_item', $plural_base ),
				'map_meta_cap'    => false,
			)
		);

		if ( \is_wp_error( $registered ) || ! $registered instanceof \WP_Post_Type ) {
			throw new \RuntimeException( 'Could not register REST-visible post type for ' . $label );
		}

		return (string) $registered->cap->edit_posts;
	}

	private static function http_response( string $body, int $status ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Synthetic Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function route_methods( array $handlers ): array {
		$methods = array();
		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
				continue;
			}
			foreach ( $handler['methods'] as $method => $enabled ) {
				if ( $enabled ) {
					$methods[] = (string) $method;
				}
			}
		}

		$methods = array_values( array_unique( $methods ) );
		sort( $methods );
		return $methods;
	}

	private static function route_data_endpoint_for_methods( ?array $data, array $methods ): ?array {
		if ( null === $data || ! isset( $data['endpoints'] ) || ! is_array( $data['endpoints'] ) ) {
			return null;
		}

		sort( $methods );
		foreach ( $data['endpoints'] as $endpoint ) {
			if ( ! isset( $endpoint['methods'] ) || ! is_array( $endpoint['methods'] ) ) {
				continue;
			}
			$endpoint_methods = array_values( array_unique( array_map( 'strval', $endpoint['methods'] ) ) );
			sort( $endpoint_methods );
			if ( $methods === $endpoint_methods ) {
				return $endpoint;
			}
		}
		return null;
	}

	private static function route_data_endpoint_has_args( ?array $data, array $methods, array $args ): bool {
		$endpoint = self::route_data_endpoint_for_methods( $data, $methods );
		if ( null === $endpoint || ! isset( $endpoint['args'] ) || ! is_array( $endpoint['args'] ) ) {
			return false;
		}
		foreach ( $args as $arg ) {
			if ( ! array_key_exists( $arg, $endpoint['args'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function route_data_schema_has_properties( ?array $data, array $properties ): bool {
		if ( null === $data || ! isset( $data['schema']['properties'] ) || ! is_array( $data['schema']['properties'] ) ) {
			return false;
		}
		foreach ( $properties as $property ) {
			if ( ! array_key_exists( $property, $data['schema']['properties'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function schema_has_properties( array $schema, array $properties ): bool {
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return false;
		}
		foreach ( $properties as $property ) {
			if ( ! array_key_exists( $property, $schema['properties'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function schema_valid( $value, array $schema ): bool {
		$valid = \rest_validate_value_from_schema( $value, $schema );
		return true === $valid;
	}

	private static function response_error_ok( $response, string $code, int $status ): bool {
		if ( ! $response instanceof \WP_REST_Response || $status !== $response->get_status() ) {
			return false;
		}

		$data = $response->get_data();
		return is_array( $data )
			&& $code === ( $data['code'] ?? null )
			&& isset( $data['data'] )
			&& is_array( $data['data'] )
			&& $status === (int) ( $data['data']['status'] ?? 0 );
	}

	private static function latest_transient_has( array $sets, string $prefix, int $expiration ): bool {
		for ( $i = count( $sets ) - 1; $i >= 0; --$i ) {
			if ( str_starts_with( (string) ( $sets[ $i ]['transient'] ?? '' ), $prefix ) ) {
				return $expiration === (int) ( $sets[ $i ]['expiration'] ?? -1 );
			}
		}
		return false;
	}

	private static function link_href( array $links, string $rel ): ?string {
		return isset( $links[ $rel ][0]['href'] ) ? (string) $links[ $rel ][0]['href'] : null;
	}

	private static function interesting_params( array $params, array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$out[ $key ] = $params[ $key ];
			}
		}
		return $out;
	}

	private static function schema_property_keys( ?array $route_data ): array {
		if ( ! isset( $route_data['schema']['properties'] ) || ! is_array( $route_data['schema']['properties'] ) ) {
			return array();
		}
		$keys = array_keys( $route_data['schema']['properties'] );
		sort( $keys );
		return $keys;
	}

	private static function callable_name( $callable ): ?string {
		if ( is_string( $callable ) ) {
			return $callable;
		}
		if ( is_array( $callable ) && isset( $callable[1] ) ) {
			$class = is_object( $callable[0] ?? null ) ? get_class( $callable[0] ) : (string) ( $callable[0] ?? '' );
			return $class . '::' . (string) $callable[1];
		}
		return null;
	}

	private static function token( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return substr( sha1( $ctx->seed() . ':' . $ctx->iteration() . ':' . $label ), 0, 10 );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
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

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'authordata',
					'current_user',
					'user_ID',
					'userdata',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_post_types',
					'wp_rest_server',
				)
			),
			'request' => array(
				'_GET'     => $_GET,
				'_POST'    => $_POST,
				'_REQUEST' => $_REQUEST,
				'_SERVER'  => $_SERVER,
			),
			'wpdb'    => self::snapshot_wpdb(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_wpdb( $snapshot['wpdb'] );
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		self::restore_globals( $snapshot['globals'] );
		$_GET     = $snapshot['request']['_GET'];
		$_POST    = $snapshot['request']['_POST'];
		$_REQUEST = $snapshot['request']['_REQUEST'];
		$_SERVER  = $snapshot['request']['_SERVER'];
	}

	private static function state_fingerprint(): array {
		return array(
			'globals' => self::stable_hash(
				self::summarize_for_hash(
					self::snapshot_globals(
						array(
							'current_user',
							'user_ID',
							'wp_filter',
							'wp_post_types',
							'wp_rest_server',
						)
					)
				)
			),
			'request' => self::stable_hash(
				self::summarize_for_hash(
					array(
						'_GET'     => $_GET,
						'_POST'    => $_POST,
						'_REQUEST' => $_REQUEST,
						'_SERVER'  => $_SERVER,
					)
				)
			),
			'wpdb'    => self::stable_hash( self::summarize_for_hash( self::snapshot_wpdb() ) ),
		);
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
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

	private static function snapshot_wpdb(): ?array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return null;
		}

		$wpdb       = $GLOBALS['wpdb'];
		$reflection = new \ReflectionClass( $wpdb );
		$state      = array(
			'public'  => array(
				'insert_id'     => $wpdb->insert_id,
				'last_error'    => $wpdb->last_error,
				'last_query'    => $wpdb->last_query,
				'num_rows'      => $wpdb->num_rows,
				'rows_affected' => $wpdb->rows_affected,
			),
			'private' => array(),
		);

		foreach ( $reflection->getProperties() as $property ) {
			$name = $property->getName();
			if ( str_starts_with( $name, 'component_fuzz_' ) ) {
				$state['private'][ $name ] = $property->getValue( $wpdb );
			}
		}

		return $state;
	}

	private static function restore_wpdb( ?array $snapshot ): void {
		if ( null === $snapshot || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return;
		}

		$wpdb = $GLOBALS['wpdb'];
		foreach ( $snapshot['public'] as $name => $value ) {
			$wpdb->{$name} = $value;
		}

		$reflection = new \ReflectionClass( $wpdb );
		foreach ( $snapshot['private'] as $name => $value ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}
			$property = $reflection->getProperty( $name );
			$property->setValue( $wpdb, $value );
		}
	}

	private static function stable_hash( $value ): ?string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? null : sha1( $json );
	}

	private static function summarize_for_hash( $value, int $depth = 0 ) {
		if ( $depth > 4 ) {
			return is_array( $value ) ? array( 'array' => count( $value ) ) : gettype( $value );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ (string) $key ] = self::summarize_for_hash( $item, $depth + 1 );
			}
			ksort( $out );
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \WP_Error ) {
				return array( 'WP_Error' => $value->get_error_code() );
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array( 'WP_REST_Response' => self::summarize_for_hash( $value->get_data(), $depth + 1 ) );
			}
			if ( $value instanceof \Closure ) {
				return array( 'Closure' => true );
			}
			return array( 'object' => get_class( $value ) );
		}

		return $value;
	}

	private static function first_difference( $before, $after, string $path = '' ) {
		if ( $before === $after ) {
			return null;
		}

		if ( is_array( $before ) && is_array( $after ) ) {
			$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
			foreach ( $keys as $key ) {
				$next_path = '' === $path ? (string) $key : $path . '.' . $key;
				if ( ! array_key_exists( $key, $before ) || ! array_key_exists( $key, $after ) ) {
					return array(
						'path'   => $next_path,
						'before' => array_key_exists( $key, $before ) ? $before[ $key ] : '[missing]',
						'after'  => array_key_exists( $key, $after ) ? $after[ $key ] : '[missing]',
					);
				}
				$diff = self::first_difference( $before[ $key ], $after[ $key ], $next_path );
				if ( null !== $diff ) {
					return $diff;
				}
			}
		}

		return array(
			'path'   => $path,
			'before' => self::describe_value( $before ),
			'after'  => self::describe_value( $after ),
		);
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > self::SAMPLE_BYTES ? substr( self::escape_string( $value ), 0, self::SAMPLE_BYTES ) . '...' : self::escape_string( $value );
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
				$out[ is_int( $key ) ? $key : self::escape_string( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}
			if ( $value instanceof \WP_Error ) {
				return array(
					'type' => 'WP_Error',
					'code' => $value->get_error_code(),
					'data' => self::describe_value( $value->get_error_data(), $depth + 1 ),
				);
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array(
					'type'   => 'WP_REST_Response',
					'status' => $value->get_status(),
					'data'   => self::describe_value( $value->get_data(), $depth + 1 ),
				);
			}
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_string( $e->getMessage() ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function escape_string( string $value ): string {
		return (string) preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/',
			static function ( array $matches ): string {
				return sprintf( '\\x%02X', ord( $matches[0] ) );
			},
			$value
		);
	}
}
