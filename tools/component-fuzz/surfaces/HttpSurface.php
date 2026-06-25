<?php
namespace ComponentFuzz\Surfaces;

final class HttpSurface {
	public const NAME = 'http';

	private const GENERATED_RESPONSE_CASES     = 8;
	private const GENERATED_ABSOLUTE_URL_CASES = 10;
	private const GENERATED_CHUNK_CASES        = 6;

	/** @var bool|null */
	private static $proxy_override = null;

	/** @var string[] */
	private static array $allowed_redirect_hosts = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'http.bootstrap-apis-available',
					'Required WordPress HTTP APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();

		try {
			self::install_scoped_filters();

			$rows[] = self::check_remote_retrieve_helpers( $ctx );
			$rows[] = self::check_remote_request_wrappers( $ctx );
			$rows[] = self::check_chunk_transfer_decode( $ctx );
			$rows[] = self::check_request_normalization_no_network( $ctx );
			$rows[] = self::check_response_objects( $ctx );
			$rows[] = self::check_header_processing( $ctx );
			$rows[] = self::check_cookie_parsing_and_headers( $ctx );
			$rows[] = self::check_absolute_url_resolution( $ctx );
			$rows[] = self::check_url_validation_and_redirects( $ctx );
			$rows[] = self::check_proxy_contracts( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'http.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::$proxy_override        = null;
			self::$allowed_redirect_hosts = array();
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	public static function filter_home_option( $pre_option, string $option = '', $default_value = false ) {
		unset( $pre_option, $option, $default_value );
		return 'http://example.test:8080';
	}

	public static function filter_siteurl_option( $pre_option, string $option = '', $default_value = false ) {
		unset( $pre_option, $option, $default_value );
		return 'http://example.test/wp';
	}

	public static function filter_proxy_override( $override, string $uri, array $check, array $home ) {
		unset( $uri, $check, $home );
		return null === self::$proxy_override ? $override : self::$proxy_override;
	}

	public static function filter_allowed_redirect_hosts( array $hosts, string $host ): array {
		unset( $host );
		return array_values( array_unique( array_merge( $hosts, self::$allowed_redirect_hosts ) ) );
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Error',
				'WP_HTTP_Response',
				'WP_REST_Response',
				'WP_Http',
				'WP_Http_Cookie',
				'WP_HTTP_Proxy',
				'WpOrg\Requests\Response\Headers',
				'WpOrg\Requests\Cookie\Jar',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'absint',
				'add_action',
				'add_filter',
				'apply_filters',
				'home_url',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'wp_http_validate_url',
				'wp_parse_url',
				'wp_remote_get',
				'wp_remote_head',
				'wp_remote_post',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_cookie',
				'wp_remote_retrieve_cookie_value',
				'wp_remote_retrieve_cookies',
				'wp_remote_retrieve_header',
				'wp_remote_retrieve_headers',
				'wp_remote_retrieve_response_code',
				'wp_remote_retrieve_response_message',
				'wp_remote_request',
				'wp_safe_remote_get',
				'wp_safe_remote_head',
				'wp_safe_remote_post',
				'wp_safe_remote_request',
				'wp_sanitize_redirect',
				'wp_validate_redirect',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_remote_retrieve_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::response_cases( $ctx );

		foreach ( $cases as $index => $case ) {
			$response       = self::synthetic_response( $case );
			$combined_token = $case['tokenA'] . ',' . $case['tokenB'];
			$cookie         = $response['cookies'][0];

			self::collect_failure(
				$failures,
				wp_remote_retrieve_headers( $response ) === $response['headers']
					&& $case['contentType'] === wp_remote_retrieve_header( $response, 'content-type' )
					&& $case['contentType'] === wp_remote_retrieve_header( $response, 'CONTENT-TYPE' )
					&& $combined_token === wp_remote_retrieve_header( $response, 'x-fuzz-token' )
					&& $case['status'] === wp_remote_retrieve_response_code( $response )
					&& $case['message'] === wp_remote_retrieve_response_message( $response )
					&& $case['body'] === wp_remote_retrieve_body( $response )
					&& array( $cookie ) === wp_remote_retrieve_cookies( $response )
					&& $cookie === wp_remote_retrieve_cookie( $response, $case['cookieName'] )
					&& $case['cookieValue'] === wp_remote_retrieve_cookie_value( $response, $case['cookieName'] )
					&& '' === wp_remote_retrieve_cookie( $response, 'missing-' . $index )
					&& '' === wp_remote_retrieve_cookie_value( $response, 'missing-' . $index ),
				"response case {$index}",
				array(
					'expectedStatus' => $case['status'],
					'actualStatus'   => wp_remote_retrieve_response_code( $response ),
					'headers'        => self::headers_to_array( $response['headers'] ),
					'body'           => self::describe_string( $case['body'] ),
				)
			);
		}

		$error_response = new \WP_Error( 'component_fuzz_http', 'synthetic error' );
		self::collect_failure(
			$failures,
			array() === wp_remote_retrieve_headers( $error_response )
				&& '' === wp_remote_retrieve_header( $error_response, 'content-type' )
				&& '' === wp_remote_retrieve_response_code( $error_response )
				&& '' === wp_remote_retrieve_response_message( $error_response )
				&& '' === wp_remote_retrieve_body( $error_response )
				&& array() === wp_remote_retrieve_cookies( $error_response )
				&& '' === wp_remote_retrieve_cookie( $error_response, 'any' )
				&& '' === wp_remote_retrieve_cookie_value( $error_response, 'any' ),
			'WP_Error response defaults',
			array(
				'headers' => wp_remote_retrieve_headers( $error_response ),
				'body'    => wp_remote_retrieve_body( $error_response ),
			)
		);

		self::collect_failure(
			$failures,
			array() === wp_remote_retrieve_headers( array() )
				&& '' === wp_remote_retrieve_response_code( array() )
				&& '' === wp_remote_retrieve_body( array() ),
			'missing response members default safely',
			array()
		);

		return $ctx->result(
			'http.remote-retrieve.synthetic-response-shape',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_remote_request_wrappers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$captured = array();
		$responses = array();

		$cases = array(
			array(
				'label'             => 'remote-get',
				'function'          => 'wp_remote_get',
				'method'            => 'GET',
				'rejectUnsafeUrls'  => false,
				'expectedRedirects' => 5,
				'args'              => array(
					'timeout' => 2.5,
					'headers' => array( 'X-Fuzz-Wrapper' => 'get' ),
				),
			),
			array(
				'label'             => 'remote-post',
				'function'          => 'wp_remote_post',
				'method'            => 'POST',
				'rejectUnsafeUrls'  => false,
				'expectedRedirects' => 5,
				'args'              => array(
					'body'    => 'payload=' . rawurlencode( self::token( $ctx, 8 ) ),
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				),
			),
			array(
				'label'             => 'remote-head',
				'function'          => 'wp_remote_head',
				'method'            => 'HEAD',
				'rejectUnsafeUrls'  => false,
				'expectedRedirects' => 0,
				'args'              => array(),
			),
			array(
				'label'             => 'remote-request-patch',
				'function'          => 'wp_remote_request',
				'method'            => 'PATCH',
				'rejectUnsafeUrls'  => false,
				'expectedRedirects' => 5,
				'args'              => array(
					'method' => 'PATCH',
					'body'   => self::body_value( $ctx ),
				),
			),
			array(
				'label'             => 'safe-get',
				'function'          => 'wp_safe_remote_get',
				'method'            => 'GET',
				'rejectUnsafeUrls'  => true,
				'expectedRedirects' => 5,
				'args'              => array(
					'reject_unsafe_urls' => false,
					'limit_response_size' => 1024,
				),
			),
			array(
				'label'             => 'safe-post',
				'function'          => 'wp_safe_remote_post',
				'method'            => 'POST',
				'rejectUnsafeUrls'  => true,
				'expectedRedirects' => 5,
				'args'              => array(
					'reject_unsafe_urls' => false,
					'body'               => 'safe=' . rawurlencode( self::token( $ctx, 8 ) ),
				),
			),
			array(
				'label'             => 'safe-head',
				'function'          => 'wp_safe_remote_head',
				'method'            => 'HEAD',
				'rejectUnsafeUrls'  => true,
				'expectedRedirects' => 0,
				'args'              => array( 'reject_unsafe_urls' => false ),
			),
			array(
				'label'             => 'safe-request-delete',
				'function'          => 'wp_safe_remote_request',
				'method'            => 'DELETE',
				'rejectUnsafeUrls'  => true,
				'expectedRedirects' => 5,
				'args'              => array(
					'method'             => 'DELETE',
					'reject_unsafe_urls' => false,
					'headers'            => array( 'X-Fuzz-Wrapper' => 'safe-request' ),
				),
			),
		);

		$pre_http_request = static function ( $preempt, array $parsed_args, string $url ) use ( &$captured ): array {
			$index      = count( $captured );
			$method     = (string) ( $parsed_args['method'] ?? 'GET' );
			$case       = array(
				'status'      => 230 + $index,
				'message'     => 'Synthetic ' . $method,
				'body'        => 'short-circuited-' . $index . '-' . strtolower( $method ),
				'contentType' => 'text/plain; charset=UTF-8',
				'tokenA'      => 'wrapper',
				'tokenB'      => (string) $index,
				'cookieName'  => 'http_wrapper_' . $index,
				'cookieValue' => strtolower( $method ),
			);
			$captured[] = array(
				'preempt' => $preempt,
				'url'     => $url,
				'args'    => self::request_arg_summary( $parsed_args ),
				'case'    => $case,
			);

			return self::synthetic_response( $case );
		};

		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );
		try {
			foreach ( $cases as $index => $case ) {
				$url       = 'https://api.example.test/component-fuzz/http/' . $case['label'] . '?q=' . rawurlencode( self::token( $ctx, 6 ) . ' & ' . $index );
				$function  = $case['function'];
				$response  = $function( $url, $case['args'] );
				$responses[] = array(
					'url'      => $url,
					'case'     => $case,
					'response' => $response,
				);
			}
		} finally {
			remove_filter( 'pre_http_request', $pre_http_request, 10 );
		}

		foreach ( $responses as $index => $response_case ) {
			$case     = $response_case['case'];
			$response = $response_case['response'];
			$capture  = $captured[ $index ] ?? null;
			$args     = is_array( $capture ) ? $capture['args'] : array();
			$expected = is_array( $capture ) ? $capture['case'] : array();

			self::collect_failure(
				$failures,
				is_array( $capture )
					&& false === $capture['preempt']
					&& $response_case['url'] === $capture['url']
					&& $case['method'] === ( $args['method'] ?? null )
					&& $case['rejectUnsafeUrls'] === ( $args['reject_unsafe_urls'] ?? null )
					&& $case['expectedRedirects'] === ( $args['redirection'] ?? null )
					&& $case['expectedRedirects'] === ( $args['_redirection'] ?? null )
					&& $expected['status'] === wp_remote_retrieve_response_code( $response )
					&& $expected['message'] === wp_remote_retrieve_response_message( $response )
					&& $expected['body'] === wp_remote_retrieve_body( $response )
					&& $expected['contentType'] === wp_remote_retrieve_header( $response, 'content-type' )
					&& $expected['cookieValue'] === wp_remote_retrieve_cookie_value( $response, $expected['cookieName'] ),
				'wrapper dispatch ' . $case['label'],
				array(
					'expected' => $case,
					'captured' => $capture,
					'response' => self::describe_value( $response ),
				)
			);
		}

		self::collect_failure(
			$failures,
			count( $cases ) === count( $captured ),
			'each wrapper call is short-circuited exactly once',
			array(
				'expected' => count( $cases ),
				'actual'   => count( $captured ),
			)
		);

		return $ctx->result(
			'http.remote-request-wrappers.short-circuit-dispatch',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_chunk_transfer_decode( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'    => 'single-chunk-with-extension',
				'body'     => "5;fuzz=alpha\r\nhello0\r\n",
				'expected' => 'hello',
			),
			array(
				'label'    => 'multiple-legacy-contiguous-chunks',
				'body'     => "4\r\nWiki5;part=2\r\npedia0\r\n",
				'expected' => 'Wikipedia',
			),
			array(
				'label'    => 'binary-boundary',
				'body'     => "7;bin=1\r\nA\x00B\r\nC\xff0\r\n",
				'expected' => "A\x00B\r\nC\xff",
			),
			array(
				'label'    => 'rfc-multichunk-separators-return-raw',
				'body'     => "4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n",
				'expected' => "4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n",
			),
			array(
				'label'    => 'trailers-return-raw',
				'body'     => "5\r\nhello0\r\nX-Fuzz-Trailer: yes\r\n\r\n",
				'expected' => "5\r\nhello0\r\nX-Fuzz-Trailer: yes\r\n\r\n",
			),
			array(
				'label'    => 'non-hex-return-raw',
				'body'     => "g\r\nbody0\r\n",
				'expected' => "g\r\nbody0\r\n",
			),
			array(
				'label'    => 'leading-crlf-return-raw',
				'body'     => "\r\n5\r\nhello0\r\n",
				'expected' => "\r\n5\r\nhello0\r\n",
			),
		);

		for ( $i = 0; $i < self::GENERATED_CHUNK_CASES; ++$i ) {
			$chunks = array();
			$count  = $ctx->int( 1, 3 );
			for ( $j = 0; $j < $count; ++$j ) {
				$chunks[] = $ctx->bytes( 1, 12 );
			}

			$cases[] = array(
				'label'    => 'generated-legacy-contiguous-' . $i,
				'body'     => self::legacy_chunk_transfer_body( $chunks, $ctx ),
				'expected' => implode( '', $chunks ),
			);
		}

		foreach ( $cases as $index => $case ) {
			$actual = \WP_Http::chunkTransferDecode( $case['body'] );
			self::collect_failure(
				$failures,
				$case['expected'] === $actual,
				'chunkTransferDecode ' . $case['label'],
				array(
					'index'    => $index,
					'input'    => self::describe_string( $case['body'] ),
					'expected' => self::describe_string( $case['expected'] ),
					'actual'   => self::describe_string( $actual ),
				)
			);
		}

		return $ctx->result(
			'http.chunk-transfer-decode.legacy-success-and-malformed-raw',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_request_normalization_no_network( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures             = array();
		$arg_events           = array();
		$pre_events           = array();
		$debug_events         = array();
		$http                 = new \WP_Http();
		$preempt_url          = 'https://api.example.test/component-fuzz/http/normalized/' . self::token( $ctx, 8 );
		$invalid_url          = 'component-fuzz-no-scheme-' . self::token( $ctx, 8 );
		$stream_url           = 'http://example.test:8080/component-fuzz/http/stream/' . self::token( $ctx, 8 );
		$filtered_redirection = $ctx->int( 1, 4 );
		$filtered_timeout     = $ctx->choice( array( 0.5, 1.25, 3.75 ) );
		$input_limit          = 64 + $ctx->int( 0, 128 );
		$filtered_limit       = 512 + $ctx->int( 0, 1024 );
		$invalid_limit        = 128 + $ctx->int( 0, 256 );
		$stream_filename      = self::missing_stream_filename( $ctx );
		$stream_parent        = dirname( $stream_filename );
		$stream_parent_file   = is_file( $stream_parent );
		$preempt_case         = array(
			'status'      => 287,
			'message'     => 'Preempted',
			'body'        => 'request-preempt-' . self::token( $ctx, 8 ),
			'contentType' => 'text/plain',
			'tokenA'      => 'request',
			'tokenB'      => 'normalization',
			'cookieName'  => 'request_preempt',
			'cookieValue' => self::token( $ctx, 6 ),
		);
		$preempt_response     = self::synthetic_response( $preempt_case );

		$request_args_filter = static function ( array $parsed_args, string $url ) use ( &$arg_events, $preempt_url, $filtered_redirection, $filtered_timeout, $filtered_limit ): array {
			$arg_events[] = array(
				'url'  => $url,
				'args' => $parsed_args,
			);

			if ( $preempt_url === $url ) {
				$parsed_args['redirection'] = $filtered_redirection;
				$parsed_args['timeout'] = $filtered_timeout;
				$parsed_args['limit_response_size'] = $filtered_limit;
				$parsed_args['headers']['X-Fuzz-Filtered'] = 'yes';
			}

			return $parsed_args;
		};

		$pre_http_request = static function ( $preempt, array $parsed_args, string $url ) use ( &$pre_events, $preempt_url, $preempt_response ) {
			$pre_events[] = array(
				'preempt' => $preempt,
				'url'     => $url,
				'args'    => $parsed_args,
			);

			if ( $preempt_url === $url ) {
				return $preempt_response;
			}

			return $preempt;
		};

		$debug_action = static function ( $response, string $context, string $class, array $parsed_args, string $url ) use ( &$debug_events ): void {
			$debug_events[] = array(
				'url'      => $url,
				'context'  => $context,
				'class'    => $class,
				'response' => $response,
				'args'     => $parsed_args,
			);
		};

		add_filter( 'http_request_args', $request_args_filter, 10, 2 );
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );
		add_action( 'http_api_debug', $debug_action, 10, 5 );
		try {
			$preempted_response = $http->request(
				$preempt_url,
				array(
					'method'              => 'POST',
					'headers'             => array( 'X-Fuzz-Input' => self::token( $ctx, 6 ) ),
					'body'                => 'payload=' . rawurlencode( self::token( $ctx, 8 ) ),
					'redirection'         => 9,
					'limit_response_size' => $input_limit,
				)
			);

			$invalid_response = $http->request(
				$invalid_url,
				array(
					'headers'             => "X-Fuzz-Raw: one\r\nX-Fuzz-Raw: two",
					'limit_response_size' => $invalid_limit,
				)
			);

			$stream_response = $http->request(
				$stream_url,
				array(
					'blocking' => false,
					'filename' => $stream_filename,
					'headers'  => 'X-Fuzz-Stream: raw',
					'stream'   => true,
				)
			);
		} finally {
			remove_action( 'http_api_debug', $debug_action, 10 );
			remove_filter( 'pre_http_request', $pre_http_request, 10 );
			remove_filter( 'http_request_args', $request_args_filter, 10 );
			self::cleanup_stream_parent_file( $stream_filename );
		}

		$preempt_args_event = self::first_event_for_url( $arg_events, $preempt_url );
		$preempt_event      = self::first_event_for_url( $pre_events, $preempt_url );
		$invalid_event      = self::first_event_for_url( $pre_events, $invalid_url );
		$invalid_debug      = self::first_event_for_url( $debug_events, $invalid_url );
		$stream_event       = self::first_event_for_url( $pre_events, $stream_url );
		$stream_debug       = self::first_event_for_url( $debug_events, $stream_url );
		$preempt_counts     = self::event_counts_for_url( $pre_events, $preempt_url );
		$invalid_counts     = self::event_counts_for_url( $pre_events, $invalid_url );
		$stream_counts      = self::event_counts_for_url( $pre_events, $stream_url );
		$invalid_debug_counts = self::event_counts_for_url( $debug_events, $invalid_url );
		$stream_debug_counts = self::event_counts_for_url( $debug_events, $stream_url );
		$stream_parent_cleaned = ! is_file( $stream_parent );

		self::collect_failure(
			$failures,
			is_array( $preempt_args_event )
				&& ! isset( $preempt_args_event['args']['_redirection'] )
				&& 'POST' === ( $preempt_args_event['args']['method'] ?? null )
				&& $input_limit === ( $preempt_args_event['args']['limit_response_size'] ?? null )
				&& true === ( $preempt_args_event['args']['blocking'] ?? null )
				&& true === ( $preempt_args_event['args']['decompress'] ?? null )
				&& ! empty( $preempt_args_event['args']['sslcertificates'] )
				&& is_array( $preempt_event )
				&& 1 === $preempt_counts
				&& false === $preempt_event['preempt']
				&& $preempt_response === $preempted_response
				&& $filtered_redirection === ( $preempt_event['args']['redirection'] ?? null )
				&& $filtered_redirection === ( $preempt_event['args']['_redirection'] ?? null )
				&& $filtered_timeout === ( $preempt_event['args']['timeout'] ?? null )
				&& $filtered_limit === ( $preempt_event['args']['limit_response_size'] ?? null )
				&& 'yes' === ( $preempt_event['args']['headers']['X-Fuzz-Filtered'] ?? null ),
			'WP_Http::request merges defaults, applies http_request_args, copies _redirection, and preempts before transport',
			array(
				'httpRequestArgs' => self::event_summary( $preempt_args_event ),
				'preHttpRequest'  => self::event_summary( $preempt_event ),
				'response'        => self::describe_value( $preempted_response ),
			)
		);

		self::collect_failure(
			$failures,
			$invalid_response instanceof \WP_Error
				&& 'http_request_failed' === $invalid_response->get_error_code()
				&& is_array( $invalid_event )
				&& 1 === $invalid_counts
				&& false === $invalid_event['preempt']
				&& is_array( $invalid_debug )
				&& 1 === $invalid_debug_counts
				&& $invalid_debug['response'] instanceof \WP_Error
				&& 'http_request_failed' === $invalid_debug['response']->get_error_code()
				&& 'response' === ( $invalid_debug['context'] ?? null )
				&& 'WpOrg\Requests\Requests' === ( $invalid_debug['class'] ?? null )
				&& $invalid_limit === ( $invalid_debug['args']['limit_response_size'] ?? null ),
			'WP_Http::request reports invalid URLs through http_api_debug after pre_http_request declines',
			array(
				'preHttpRequest' => self::event_summary( $invalid_event ),
				'debug'          => self::event_summary( $invalid_debug ),
				'response'       => self::describe_value( $invalid_response ),
			)
		);

		self::collect_failure(
			$failures,
			$stream_response instanceof \WP_Error
				&& 'http_request_failed' === $stream_response->get_error_code()
				&& is_array( $stream_event )
				&& 1 === $stream_counts
				&& false === $stream_event['preempt']
				&& false === ( $stream_event['args']['blocking'] ?? null )
				&& is_array( $stream_debug )
				&& 1 === $stream_debug_counts
				&& $stream_debug['response'] instanceof \WP_Error
				&& 'http_request_failed' === $stream_debug['response']->get_error_code()
				&& true === ( $stream_debug['args']['stream'] ?? null )
				&& true === ( $stream_debug['args']['blocking'] ?? null )
				&& $stream_filename === ( $stream_debug['args']['filename'] ?? null )
				&& 'raw' === ( $stream_debug['args']['headers']['x-fuzz-stream'] ?? null )
				&& $stream_parent_file
				&& $stream_parent_cleaned,
			'WP_Http::request stream destination errors force blocking and expose debug payload without transport',
			array(
				'preHttpRequest' => self::event_summary( $stream_event ),
				'debug'          => self::event_summary( $stream_debug ),
				'response'       => self::describe_value( $stream_response ),
				'filename'       => $stream_filename,
				'streamParent'   => $stream_parent,
				'streamParentFileBeforeRequest' => $stream_parent_file,
				'streamParentCleaned' => $stream_parent_cleaned,
			)
		);

		return $ctx->result(
			'http.request.no-network-normalization-and-early-errors',
			array() === $failures,
			array(
				'argEvents'   => count( $arg_events ),
				'preEvents'   => count( $pre_events ),
				'debugEvents' => count( $debug_events ),
				'failures'    => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_response_objects( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array();

		for ( $i = 0; $i < 8; ++$i ) {
			$cases[] = array(
				'data'    => array(
					'index' => $i,
					'value' => $ctx->jsonValue(),
				),
				'status'  => $ctx->choice( array( -50, 0, 100, 200, 201, 204, 301, 302, 399, 400, 404, 418, 500, 599, 700 ) ),
				'header'  => 'X-Fuzz-' . self::token( $ctx, 6 ),
				'value'   => $ctx->ascii( 0, 24 ),
				'append'  => $ctx->ascii( 0, 24 ),
				'body'    => self::body_value( $ctx ),
			);
		}

		foreach ( $cases as $index => $case ) {
			$headers  = array(
				$case['header'] => $case['value'],
				'Content-Type'  => 'application/json; charset=UTF-8',
			);
			$response = new \WP_HTTP_Response( $case['data'], $case['status'], $headers );
			$response->header( $case['header'], $case['append'], false );
			$response->set_data( $case['body'] );
			$response->set_status( $case['status'] );

			self::collect_failure(
				$failures,
				$response->get_data() === $case['body']
					&& $response->jsonSerialize() === $case['body']
					&& self::absint_oracle( $case['status'] ) === $response->get_status()
					&& isset( $response->get_headers()[ $case['header'] ] )
					&& $case['value'] . ', ' . $case['append'] === $response->get_headers()[ $case['header'] ],
				"WP_HTTP_Response case {$index}",
				array(
					'status'   => $case['status'],
					'expected' => self::absint_oracle( $case['status'] ),
					'actual'   => $response->get_status(),
					'headers'  => $response->get_headers(),
					'body'     => self::describe_value( $case['body'] ),
				)
			);
		}

		$rest_status = 400 + $ctx->int( 0, 99 );
		$rest_data   = array(
			'code'              => 'component_fuzz_http_error',
			'message'           => 'Synthetic REST error.',
			'data'              => array( 'status' => $rest_status ),
			'additional_errors' => array(
				array(
					'code'    => 'component_fuzz_secondary',
					'message' => 'Secondary error.',
					'data'    => array( 'status' => $rest_status ),
				),
			),
		);
		$rest        = new \WP_REST_Response( $rest_data, $rest_status );
		$handler     = array( 'methods' => 'GET', 'callback' => '__return_true' );
		$rest->set_matched_route( '/component-fuzz/v1/http/(?P<id>[\d]+)' );
		$rest->set_matched_handler( $handler );
		$rest->add_link(
			'self',
			'http://example.test/wp-json/component-fuzz/v1/http/1',
			array(
				'href'  => 'ignored-by-add-link',
				'title' => 'HTTP surface',
			)
		);
		$rest->link_header(
			'alternate',
			'http://example.test/component-fuzz/http/1',
			array(
				'type'  => 'text/html',
				'title' => 'Alternate',
			)
		);

		$error = $rest->as_error();
		$links = $rest->get_links();
		self::collect_failure(
			$failures,
			$rest->is_error()
				&& $error instanceof \WP_Error
				&& 'component_fuzz_http_error' === $error->get_error_code()
				&& array( 'status' => $rest_status ) === $error->get_error_data()
				&& '/component-fuzz/v1/http/(?P<id>[\d]+)' === $rest->get_matched_route()
				&& $handler === $rest->get_matched_handler()
				&& isset( $links['self'][0]['href'] )
				&& 'http://example.test/wp-json/component-fuzz/v1/http/1' === $links['self'][0]['href']
				&& isset( $rest->get_headers()['Link'] )
				&& false !== strpos( $rest->get_headers()['Link'], 'rel="alternate"' ),
			'WP_REST_Response error/link round trip',
			array(
				'status'  => $rest_status,
				'links'   => $links,
				'headers' => $rest->get_headers(),
				'error'   => $error instanceof \WP_Error ? $error->get_error_code() : self::describe_value( $error ),
			)
		);

		$rest->remove_link( 'self', 'http://example.test/wp-json/component-fuzz/v1/http/1' );
		$ok_rest = new \WP_REST_Response( array( 'ok' => true ), 204 );
		self::collect_failure(
			$failures,
			! isset( $rest->get_links()['self'] )
				&& ! $ok_rest->is_error()
				&& null === $ok_rest->as_error(),
			'WP_REST_Response removes links and keeps non-errors non-errors',
			array(
				'remainingLinks' => $rest->get_links(),
				'okStatus'       => $ok_rest->get_status(),
			)
		);

		return $ctx->result(
			'http.response-objects.round-trip',
			array() === $failures,
			array(
				'cases'    => count( $cases ) + 2,
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_header_processing( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$body     = "Body bytes: \x00\xff\nsecond line";
		$raw      = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Fuzz: alpha\r\n\r\n" . $body;
		$split    = \WP_Http::processResponse( $raw );

		self::collect_failure(
			$failures,
			"HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Fuzz: alpha" === $split['headers']
				&& $body === $split['body'],
			'processResponse splits headers and binary body',
			array(
				'headers' => $split['headers'],
				'body'    => self::describe_string( $split['body'] ),
			)
		);

		$multi_headers = implode(
			"\r\n",
			array(
				'HTTP/1.1 301 Moved Permanently',
				'Location: /old',
				'',
				'HTTP/1.1 207 Multi Status',
				'Content-Type: text/plain; charset=UTF-8',
				'X-Fuzz: one',
				'x-fuzz: two',
				"Folded: first\r\n second",
				'Set-Cookie: sid=abc%20123; Path=/app/; Domain=example.test; Expires=Tue, 19 Jan 2038 03:14:07 GMT; HttpOnly; Secure; SameSite=Lax',
				'',
			)
		);
		$processed     = \WP_Http::processHeaders( $multi_headers, 'https://example.test/app/page' );
		$cookie        = $processed['cookies'][0] ?? null;

		self::collect_failure(
			$failures,
			207 === $processed['response']['code']
				&& 'Multi Status' === $processed['response']['message']
				&& 'text/plain; charset=UTF-8' === $processed['headers']['content-type']
				&& array( 'one', 'two' ) === $processed['headers']['x-fuzz']
				&& 'first second' === $processed['headers']['folded']
				&& $cookie instanceof \WP_Http_Cookie
				&& 'sid' === $cookie->name
				&& 'abc 123' === $cookie->value
				&& '/app/' === $cookie->path
				&& 'example.test' === $cookie->domain
				&& isset( $cookie->httponly, $cookie->secure, $cookie->samesite )
				&& 'Lax' === $cookie->samesite,
			'processHeaders normalizes duplicate, folded, and cookie headers',
			array(
				'processed' => self::describe_value( $processed ),
				'cookie'    => self::describe_cookie( $cookie ),
			)
		);

		$malformed = array(
			'HTTP/1.1 204 No Content',
			'X-Good: yes',
			'Malformed Header Without-Colon',
			'',
		);
		$malformed_result = \WP_Http::processHeaders( $malformed, 'http://example.test/' );
		self::collect_failure(
			$failures,
			is_array( $malformed_result )
				&& isset( $malformed_result['response'], $malformed_result['headers'], $malformed_result['cookies'] )
				&& is_array( $malformed_result['response'] )
				&& is_array( $malformed_result['headers'] )
				&& is_array( $malformed_result['cookies'] ),
			'processHeaders represents malformed header lines without throwing',
			array( 'result' => self::describe_value( $malformed_result ) )
		);

		return $ctx->result(
			'http.header-processing.normalization-and-malformed-lines',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_cookie_parsing_and_headers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$header   = 'session=abc%20123; Path=/app/; Domain=example.test; Expires=Tue, 19 Jan 2038 03:14:07 GMT; HttpOnly; Secure; SameSite=Lax';
		$cookie   = new \WP_Http_Cookie( $header, 'https://example.test/app/page' );

		self::collect_failure(
			$failures,
			'session' === $cookie->name
				&& 'abc 123' === $cookie->value
				&& '/app/' === $cookie->path
				&& 'example.test' === $cookie->domain
				&& isset( $cookie->httponly, $cookie->secure, $cookie->samesite )
				&& 'Lax' === $cookie->samesite
				&& $cookie->test( 'https://example.test/app/next' )
				&& $cookie->test( 'https://sub.example.test/app/next' )
				&& ! $cookie->test( 'https://example.test/other' )
				&& 'session=abc 123' === $cookie->getHeaderValue()
				&& 'Cookie: session=abc 123' === $cookie->getFullHeader()
				&& array(
					'expires' => $cookie->expires,
					'path'    => '/app/',
					'domain'  => 'example.test',
				) === $cookie->get_attributes(),
			'WP_Http_Cookie parses attributes and scope',
			array( 'cookie' => self::describe_cookie( $cookie ) )
		);

		$port_cookie = new \WP_Http_Cookie(
			array(
				'name'      => 'porty',
				'value'     => 'v',
				'expires'   => PHP_INT_MAX,
				'path'      => '/',
				'domain'    => 'example.test',
				'port'      => '8080,8443',
				'host_only' => false,
			)
		);
		$expired     = new \WP_Http_Cookie(
			array(
				'name'    => 'old',
				'value'   => 'gone',
				'expires' => 1,
				'path'    => '/',
				'domain'  => 'example.test',
			)
		);
		$invalid     = new \WP_Http_Cookie( array( 'value' => 'missing-name' ) );

		self::collect_failure(
			$failures,
			$port_cookie->test( 'http://example.test:8080/path' )
				&& $port_cookie->test( 'https://example.test:8443/path' )
				&& ! $port_cookie->test( 'http://example.test:80/path' )
				&& ! $expired->test( 'http://example.test/path' )
				&& ! $invalid->test( 'http://example.test/path' )
				&& '' === $invalid->getHeaderValue(),
			'WP_Http_Cookie rejects invalid, expired, and wrong-port cookies',
			array(
				'portCookie' => self::describe_cookie( $port_cookie ),
				'expired'    => self::describe_cookie( $expired ),
				'invalid'    => self::describe_cookie( $invalid ),
			)
		);

		$args = array(
			'headers' => array(),
			'cookies' => array(
				'plain'       => 'value',
				'with_object' => $cookie,
			),
		);
		\WP_Http::buildCookieHeader( $args );
		$jar = \WP_Http::normalize_cookies(
			array(
				'scalar'             => 'one',
				$port_cookie->name   => $port_cookie,
				'ignored_non_scalar' => array( 'not', 'a', 'cookie' ),
			)
		);

		self::collect_failure(
			$failures,
			isset( $args['headers']['cookie'] )
				&& false !== strpos( $args['headers']['cookie'], 'plain=value' )
				&& false !== strpos( $args['headers']['cookie'], 'session=abc 123' )
				&& isset( $jar['scalar'], $jar['porty'] )
				&& ! isset( $jar['ignored_non_scalar'] ),
			'cookie request helpers build Cookie headers and Requests jars',
			array(
				'cookieHeader' => $args['headers']['cookie'] ?? null,
				'jar'          => self::describe_value( iterator_to_array( $jar ) ),
			)
		);

		return $ctx->result(
			'http.cookies.parsing-scope-and-request-normalization',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_absolute_url_resolution( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array( 'base' => 'https://example.test/a/b/c.html', 'relative' => '../d.txt' ),
			array( 'base' => 'http://example.test:8080/a/b/', 'relative' => '/root?x=1#frag' ),
			array( 'base' => 'https://example.test/a/b/c', 'relative' => '?q=1&v=two' ),
			array( 'base' => 'https://example.test/a/b/c', 'relative' => '#fragment' ),
			array( 'base' => 'https://example.test/a/b/c', 'relative' => '//cdn.example.org:8443/lib.js' ),
			array( 'base' => 'https://[2001:db8::1]:8443/a/b/c', 'relative' => 'd/e' ),
			array( 'base' => 'https://xn--bcher-kva.example/a/b/', 'relative' => '../c' ),
			array( 'base' => '', 'relative' => 'path' ),
			array( 'base' => 'https://example.test/a/b/c', 'relative' => 'ftp://files.example.test/archive.zip' ),
		);

		$bases = array(
			'http://example.test/a/b/c',
			'https://example.test:8080/dir/index.php',
			'https://xn--bcher-kva.example/base/path/',
			'https://[2001:db8::1]/ipv6/base',
		);
		$relatives = array( 'child', 'child/grand', '../up', '../../root', '/absolute/path', '?query=1', '#frag', '//assets.example.test/c.js' );
		for ( $i = 0; $i < self::GENERATED_ABSOLUTE_URL_CASES; ++$i ) {
			$cases[] = array(
				'base'     => $ctx->choice( $bases ),
				'relative' => $ctx->choice( $relatives ),
			);
		}

		foreach ( $cases as $index => $case ) {
			$expected = self::absolute_url_oracle( $case['relative'], $case['base'] );
			$actual   = \WP_Http::make_absolute_url( $case['relative'], $case['base'] );
			self::collect_failure(
				$failures,
				$expected === $actual,
				"make_absolute_url case {$index}",
				array(
					'base'     => $case['base'],
					'relative' => $case['relative'],
					'expected' => $expected,
					'actual'   => $actual,
				)
			);
		}

		return $ctx->result(
			'http.make-absolute-url.simple-oracle',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_url_validation_and_redirects( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array( 'label' => 'empty', 'url' => '', 'expected' => false ),
			array( 'label' => 'numeric', 'url' => '12345', 'expected' => false ),
			array( 'label' => 'unsupported-ftp', 'url' => 'ftp://example.test/file', 'expected' => false ),
			array( 'label' => 'unsupported-javascript', 'url' => 'javascript:alert(1)', 'expected' => false ),
			array( 'label' => 'userinfo', 'url' => 'http://user:pass@example.test/', 'expected' => false ),
			array( 'label' => 'loopback', 'url' => 'http://127.0.0.1/', 'expected' => false ),
			array( 'label' => 'private-10', 'url' => 'http://10.0.0.5/', 'expected' => false ),
			array( 'label' => 'private-172', 'url' => 'http://172.16.0.1/', 'expected' => false ),
			array( 'label' => 'private-192', 'url' => 'http://192.168.1.7/', 'expected' => false ),
			array( 'label' => 'zero-network', 'url' => 'http://0.0.0.0/', 'expected' => false ),
			array( 'label' => 'ipv6-host-rejected-before-dns', 'url' => 'http://[::1]/', 'expected' => false ),
			array( 'label' => 'same-host-safe-port', 'url' => 'http://example.test:8080/path', 'expected' => 'http://example.test:8080/path' ),
			array( 'label' => 'same-host-unsafe-port', 'url' => 'http://example.test:81/path', 'expected' => false ),
			array( 'label' => 'public-ip-safe-port', 'url' => 'http://8.8.8.8:80/path', 'expected' => 'http://8.8.8.8:80/path' ),
			array( 'label' => 'public-ip-unsafe-port', 'url' => 'http://8.8.8.8:81/path', 'expected' => false ),
		);

		foreach ( $cases as $case ) {
			$actual = wp_http_validate_url( $case['url'] );
			self::collect_failure(
				$failures,
				$case['expected'] === $actual,
				'wp_http_validate_url ' . $case['label'],
				array(
					'url'      => $case['url'],
					'expected' => $case['expected'],
					'actual'   => $actual,
				)
			);
		}

		$allow_local = static function ( bool $is_external, string $host, string $url ): bool {
			return '127.0.0.1' === $host && 'http://127.0.0.1:80/allowed' === $url ? true : $is_external;
		};
		add_filter( 'http_request_host_is_external', $allow_local, 10, 3 );
		$allowed_local = wp_http_validate_url( 'http://127.0.0.1:80/allowed' );
		remove_filter( 'http_request_host_is_external', $allow_local, 10 );

		$allow_port = static function ( array $ports ): array {
			$ports[] = 81;
			return $ports;
		};
		add_filter( 'http_allowed_safe_ports', $allow_port );
		$allowed_port = wp_http_validate_url( 'http://8.8.8.8:81/path' );
		remove_filter( 'http_allowed_safe_ports', $allow_port );

		self::collect_failure(
			$failures,
			'http://127.0.0.1:80/allowed' === $allowed_local
				&& 'http://8.8.8.8:81/path' === $allowed_port,
			'wp_http_validate_url honors scoped allow filters',
			array(
				'allowedLocal' => $allowed_local,
				'allowedPort'  => $allowed_port,
			)
		);

		$fallback = 'http://example.test/fallback';
		$same     = wp_validate_redirect( 'http://example.test/path', $fallback );
		$relative = wp_validate_redirect( '/wp-admin/edit.php', $fallback );
		$evil     = wp_validate_redirect( 'https://evil.test/path', $fallback );
		$scheme   = wp_validate_redirect( 'data:text/plain,hi', $fallback );

		self::$allowed_redirect_hosts = array( 'evil.test' );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ), 10, 2 );
		$allowed_evil = wp_validate_redirect( 'https://evil.test/path', $fallback );
		remove_filter( 'allowed_redirect_hosts', array( __CLASS__, 'filter_allowed_redirect_hosts' ), 10 );
		self::$allowed_redirect_hosts = array();

		self::collect_failure(
			$failures,
			'http://example.test/path' === $same
				&& '/wp-admin/edit.php' === $relative
				&& $fallback === $evil
				&& $fallback === $scheme
				&& 'https://evil.test/path' === $allowed_evil,
			'wp_validate_redirect allows same-host/relative and rejects unsafe hosts/schemes',
			array(
				'same'        => $same,
				'relative'    => $relative,
				'evil'        => $evil,
				'scheme'      => $scheme,
				'allowedEvil' => $allowed_evil,
			)
		);

		$valid_redirect_threw   = false;
		$invalid_redirect_threw = false;
		try {
			\WP_Http::validate_redirects( 'http://example.test:8080/ok' );
		} catch ( \Throwable $e ) {
			$valid_redirect_threw = true;
		}

		try {
			\WP_Http::validate_redirects( 'ftp://example.test/file' );
		} catch ( \Throwable $e ) {
			$invalid_redirect_threw = true;
		}

		self::collect_failure(
			$failures,
			! $valid_redirect_threw && $invalid_redirect_threw,
			'WP_Http::validate_redirects accepts safe URLs and throws for invalid URLs',
			array(
				'validThrew'   => $valid_redirect_threw,
				'invalidThrew' => $invalid_redirect_threw,
			)
		);

		return $ctx->result(
			'http.url-validation-and-redirect-safety.no-network-cases',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_proxy_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$proxy    = new \WP_HTTP_Proxy();

		$expected_enabled = defined( 'WP_PROXY_HOST' ) && defined( 'WP_PROXY_PORT' );
		$expected_auth    = defined( 'WP_PROXY_USERNAME' ) && defined( 'WP_PROXY_PASSWORD' );
		self::collect_failure(
			$failures,
			$expected_enabled === $proxy->is_enabled()
				&& $expected_auth === $proxy->use_authentication()
				&& ( defined( 'WP_PROXY_HOST' ) ? WP_PROXY_HOST : '' ) === $proxy->host()
				&& ( defined( 'WP_PROXY_PORT' ) ? WP_PROXY_PORT : '' ) === $proxy->port()
				&& ( defined( 'WP_PROXY_USERNAME' ) ? WP_PROXY_USERNAME : '' ) === $proxy->username()
				&& ( defined( 'WP_PROXY_PASSWORD' ) ? WP_PROXY_PASSWORD : '' ) === $proxy->password()
				&& $proxy->username() . ':' . $proxy->password() === $proxy->authentication()
				&& 'Proxy-Authorization: Basic ' . base64_encode( $proxy->authentication() ) === $proxy->authentication_header(),
			'WP_HTTP_Proxy reflects configured constants without defining new ones',
			array(
				'enabled' => $proxy->is_enabled(),
				'auth'    => $proxy->use_authentication(),
				'host'    => $proxy->host(),
				'port'    => $proxy->port(),
			)
		);

		add_filter( 'pre_http_send_through_proxy', array( __CLASS__, 'filter_proxy_override' ), 10, 4 );
		self::$proxy_override = false;
		$forced_bypass        = $proxy->send_through_proxy( 'http://198.51.100.10/path' );
		self::$proxy_override = true;
		$forced_proxy         = $proxy->send_through_proxy( 'http://localhost/path' );
		self::$proxy_override = null;

		$localhost = $proxy->send_through_proxy( 'http://localhost/path' );
		$site_host = $proxy->send_through_proxy( 'http://example.test/wp-json' );
		$external  = $proxy->send_through_proxy( 'http://198.51.100.10/path' );
		remove_filter( 'pre_http_send_through_proxy', array( __CLASS__, 'filter_proxy_override' ), 10 );

		self::collect_failure(
			$failures,
			false === $forced_bypass
				&& true === $forced_proxy
				&& false === $localhost
				&& false === $site_host
				&& ( defined( 'WP_PROXY_BYPASS_HOSTS' ) || true === $external ),
			'WP_HTTP_Proxy send_through_proxy honors scoped filters and local bypasses',
			array(
				'forcedBypass' => $forced_bypass,
				'forcedProxy'  => $forced_proxy,
				'localhost'    => $localhost,
				'siteHost'     => $site_host,
				'external'     => $external,
			)
		);

		return $ctx->result(
			'http.proxy.default-state-and-scoped-overrides',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function response_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'status'      => 200,
				'message'     => 'OK',
				'body'        => '',
				'contentType' => 'text/plain',
				'tokenA'      => 'alpha',
				'tokenB'      => 'beta',
				'cookieName'  => 'sid',
				'cookieValue' => 'abc 123',
			),
			array(
				'status'      => 204,
				'message'     => 'No Content',
				'body'        => '',
				'contentType' => 'application/octet-stream',
				'tokenA'      => 'empty',
				'tokenB'      => 'body',
				'cookieName'  => 'empty_body',
				'cookieValue' => '',
			),
			array(
				'status'      => 418,
				'message'     => "I'm a teapot",
				'body'        => "binary\x00\xffbody",
				'contentType' => 'application/json; charset=UTF-8',
				'tokenA'      => 'mixed',
				'tokenB'      => 'case',
				'cookieName'  => 'utf',
				'cookieValue' => "snowman-\xe2\x98\x83",
			),
		);

		$statuses = array( 100, 101, 200, 201, 202, 204, 206, 301, 302, 304, 307, 308, 400, 401, 403, 404, 409, 418, 429, 500, 502, 503, 599 );
		$messages = array( 'OK', 'Created', 'Accepted', 'No Content', 'Moved Permanently', 'Found', 'Not Modified', 'Bad Request', 'Forbidden', 'Not Found', 'Conflict', 'Too Many Requests', 'Internal Server Error', 'Service Unavailable', 'Custom' );
		$types    = array( 'text/plain', 'text/html; charset=UTF-8', 'application/json', 'application/octet-stream', 'image/svg+xml' );

		for ( $i = 0; $i < self::GENERATED_RESPONSE_CASES; ++$i ) {
			$cases[] = array(
				'status'      => $ctx->choice( $statuses ),
				'message'     => $ctx->choice( $messages ),
				'body'        => self::body_value( $ctx ),
				'contentType' => $ctx->choice( $types ),
				'tokenA'      => self::token( $ctx, 5 ),
				'tokenB'      => self::token( $ctx, 5 ),
				'cookieName'  => 'c_' . self::token( $ctx, 6 ),
				'cookieValue' => $ctx->text( 0, 24 ),
			);
		}

		return $cases;
	}

	private static function synthetic_response( array $case ): array {
		$headers                  = new \WpOrg\Requests\Response\Headers();
		$headers['Content-Type']  = $case['contentType'];
		$headers['X-Fuzz-Token']  = $case['tokenA'];
		$headers['x-fuzz-token']  = $case['tokenB'];
		$headers['X-Status-Code'] = (string) $case['status'];

		$cookie = new \WP_Http_Cookie(
			array(
				'name'      => $case['cookieName'],
				'value'     => $case['cookieValue'],
				'expires'   => PHP_INT_MAX,
				'path'      => '/',
				'domain'    => 'example.test',
				'host_only' => true,
			)
		);

		return array(
			'headers'  => $headers,
			'body'     => $case['body'],
			'response' => array(
				'code'    => $case['status'],
				'message' => $case['message'],
			),
			'cookies'  => array( $cookie ),
			'filename' => null,
		);
	}

	private static function legacy_chunk_transfer_body( array $chunks, \ComponentFuzz\FuzzContext $ctx ): string {
		$body = '';
		foreach ( $chunks as $chunk ) {
			$extension = $ctx->bool( 50 ) ? ';fuzz=' . self::token( $ctx, 4 ) : '';
			if ( $ctx->bool( 25 ) ) {
				$extension .= ';quoted="' . self::token( $ctx, 3 ) . '"';
			}

			$body .= dechex( strlen( $chunk ) ) . $extension . "\r\n" . $chunk;
		}

		return $body . "0\r\n";
	}

	private static function missing_stream_filename( \ComponentFuzz\FuzzContext $ctx ): string {
		$path = tempnam( sys_get_temp_dir(), 'component-fuzz-http-parent-' );
		if ( false === $path ) {
			throw new \RuntimeException( 'Could not reserve a component fuzz HTTP temp file.' );
		}

		return $path . DIRECTORY_SEPARATOR . 'response-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . self::token( $ctx, 6 ) . '.bin';
	}

	private static function cleanup_stream_parent_file( string $filename ): void {
		$parent = dirname( $filename );
		if ( is_file( $parent ) ) {
			@unlink( $parent );
		}
	}

	private static function first_event_for_url( array $events, string $url ): ?array {
		foreach ( $events as $event ) {
			if ( is_array( $event ) && $url === ( $event['url'] ?? null ) ) {
				return $event;
			}
		}

		return null;
	}

	private static function event_counts_for_url( array $events, string $url ): int {
		$count = 0;
		foreach ( $events as $event ) {
			if ( is_array( $event ) && $url === ( $event['url'] ?? null ) ) {
				++$count;
			}
		}

		return $count;
	}

	private static function event_summary( $event ): array {
		if ( ! is_array( $event ) ) {
			return array( 'event' => self::describe_value( $event ) );
		}

		$summary = array(
			'url' => $event['url'] ?? null,
		);

		if ( array_key_exists( 'preempt', $event ) ) {
			$summary['preempt'] = self::describe_value( $event['preempt'] );
		}

		if ( array_key_exists( 'context', $event ) ) {
			$summary['context'] = $event['context'];
		}

		if ( array_key_exists( 'class', $event ) ) {
			$summary['class'] = $event['class'];
		}

		if ( array_key_exists( 'response', $event ) ) {
			$summary['response'] = self::describe_value( $event['response'] );
		}

		if ( isset( $event['args'] ) && is_array( $event['args'] ) ) {
			$summary['args'] = self::request_arg_summary( $event['args'] );
		}

		return $summary;
	}

	private static function install_scoped_filters(): void {
		add_filter( 'pre_option_home', array( __CLASS__, 'filter_home_option' ), 10, 3 );
		add_filter( 'pre_option_siteurl', array( __CLASS__, 'filter_siteurl_option' ), 10, 3 );
	}

	private static function absolute_url_oracle( string $maybe_relative_path, string $url ): string {
		if ( '' === $url ) {
			return $maybe_relative_path;
		}

		$url_parts = parse_url( $url );
		if ( false === $url_parts || empty( $url_parts['scheme'] ) || empty( $url_parts['host'] ) ) {
			return $maybe_relative_path;
		}

		$relative_parts = parse_url( $maybe_relative_path );
		if ( false === $relative_parts ) {
			return $maybe_relative_path;
		}

		if ( ! empty( $relative_parts['scheme'] ) ) {
			return $maybe_relative_path;
		}

		$has_relative_host = isset( $relative_parts['host'] );
		$host              = $has_relative_host ? $relative_parts['host'] : $url_parts['host'];
		$port              = $has_relative_host ? ( $relative_parts['port'] ?? null ) : ( $url_parts['port'] ?? null );
		$path              = ! empty( $url_parts['path'] ) ? $url_parts['path'] : '/';

		if ( ! empty( $relative_parts['path'] ) && '/' === $relative_parts['path'][0] ) {
			$path = $relative_parts['path'];
		} elseif ( ! empty( $relative_parts['path'] ) ) {
			$last_slash = strrpos( $path, '/' );
			$directory  = false === $last_slash ? '/' : substr( $path, 0, $last_slash + 1 );
			$path       = self::remove_dot_segments( $directory . $relative_parts['path'] );
		}

		$absolute = $url_parts['scheme'] . '://' . $host;
		if ( null !== $port ) {
			$absolute .= ':' . $port;
		}

		$absolute .= '/' . ltrim( $path, '/' );

		if ( ! empty( $relative_parts['query'] ) ) {
			$absolute .= '?' . $relative_parts['query'];
		}

		if ( ! empty( $relative_parts['fragment'] ) ) {
			$absolute .= '#' . $relative_parts['fragment'];
		}

		return $absolute;
	}

	private static function remove_dot_segments( string $path ): string {
		$absolute = str_starts_with( $path, '/' );
		$segments = explode( '/', $path );
		$out      = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}
			$out[] = $segment;
		}

		$normalized = implode( '/', $out );
		return $absolute ? '/' . $normalized : $normalized;
	}

	private static function token( \ComponentFuzz\FuzzContext $ctx, int $length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $ctx->int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	private static function body_value( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				'',
				$ctx->text( 0, 96 ),
				$ctx->bytes( 0, 64 ),
				"json-ish {\"ok\":true}\n",
				"invalid utf8: \xff\xfe\xfa",
			)
		);
	}

	private static function absint_oracle( int $value ): int {
		return abs( (int) $value );
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

	private static function headers_to_array( $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return $headers->getAll();
		}
		return (array) $headers;
	}

	private static function request_arg_summary( array $args ): array {
		return array(
			'method'              => (string) ( $args['method'] ?? '' ),
			'reject_unsafe_urls'  => (bool) ( $args['reject_unsafe_urls'] ?? false ),
			'redirection'         => $args['redirection'] ?? null,
			'_redirection'        => $args['_redirection'] ?? null,
			'timeout'             => $args['timeout'] ?? null,
			'blocking'            => $args['blocking'] ?? null,
			'stream'              => $args['stream'] ?? null,
			'filename'            => $args['filename'] ?? null,
			'decompress'          => $args['decompress'] ?? null,
			'sslverify'           => $args['sslverify'] ?? null,
			'sslcertificates'     => $args['sslcertificates'] ?? null,
			'headers'             => self::describe_value( $args['headers'] ?? array() ),
			'body'                => self::describe_value( $args['body'] ?? null ),
			'limit_response_size' => $args['limit_response_size'] ?? null,
		);
	}

	private static function describe_cookie( $cookie ) {
		if ( ! ( $cookie instanceof \WP_Http_Cookie ) ) {
			return self::describe_value( $cookie );
		}

		$fields = array( 'name', 'value', 'expires', 'path', 'domain', 'port', 'host_only', 'secure', 'httponly', 'samesite' );
		$out    = array();
		foreach ( $fields as $field ) {
			if ( isset( $cookie->$field ) ) {
				$out[ $field ] = self::describe_value( $cookie->$field );
			}
		}
		return $out;
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \WP_Error ) {
				return array(
					'type' => 'WP_Error',
					'code' => $value->get_error_code(),
				);
			}
			if ( $value instanceof \WP_Http_Cookie ) {
				return self::describe_cookie( $value );
			}
			return array( 'type' => get_class( $value ) );
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		$preview = preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/',
			static function ( array $match ): string {
				return sprintf( '\\x%02X', ord( $match[0] ) );
			},
			$value
		);

		if ( strlen( $preview ) > 96 ) {
			$preview = substr( $preview, 0, 96 ) . '...';
		}

		return array(
			'length' => strlen( $value ),
			'sha256' => hash( 'sha256', $value ),
			'preview' => $preview,
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'type'    => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function snapshot_globals(): array {
		$snapshot = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', '_SERVER', '_ENV' ) as $name ) {
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
