<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB XML-RPC protocol and safe server helper behavior.
 */
final class XmlRpcSurface {
	public const NAME = 'xmlrpc';

	private const SAMPLE_BYTES = 160;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'xmlrpc.bootstrap-apis-available',
					'Required XML-RPC APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_ixr_value_serialization( $ctx->fork( 'ixr-values' ) );
			$rows[] = self::check_ixr_request_round_trip( $ctx->fork( 'ixr-requests' ) );
			$rows[] = self::check_ixr_message_fail_closed( $ctx->fork( 'ixr-message-errors' ) );
			$rows[] = self::check_ixr_fault_xml( $ctx->fork( 'ixr-faults' ) );
			$rows[] = self::check_ixr_server_dispatch( $ctx->fork( 'ixr-server' ) );
			$rows[] = self::check_ixr_server_multicall_matrix( $ctx->fork( 'ixr-multicall' ) );
			$rows[] = self::check_wp_xmlrpc_server_helpers( $ctx->fork( 'wp-server' ) );
			$rows[] = self::check_pingback_fail_closed_and_readonly_lookups( $ctx->fork( 'pingbacks' ) );
			$rows[] = self::check_authenticated_readonly_content_media_methods( $ctx->fork( 'readonly-content-media' ) );
			$rows[] = self::check_authenticated_write_post_methods( $ctx->fork( 'write-post-methods' ) );
			$rows[] = self::check_xmlrpc_post_data_helpers( $ctx->fork( 'post-data' ) );
			$rows[] = self::check_http_ixr_client_transport( $ctx->fork( 'http-client' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'xmlrpc.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		self::load_http_ixr_client();

		$missing = array();

		foreach (
			array(
				'IXR_Base64',
				'IXR_Date',
				'IXR_Error',
				'IXR_Message',
				'IXR_Request',
				'IXR_Server',
				'IXR_Value',
				'WP_Error',
				'WP_HTTP_IXR_Client',
				'wp_xmlrpc_server',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'current_theme_supports',
				'current_user_can',
				'get_default_post_to_edit',
				'get_post',
				'get_permalink',
				'get_post_format',
				'get_post_meta',
				'get_post_thumbnail_id',
				'get_post_type_object',
				'get_posts',
				'has_filter',
				'image_downsize',
				'is_sticky',
				'is_wp_error',
				'pings_open',
				'remove_action',
				'remove_filter',
				'register_post_type',
				'url_to_postid',
				'wp_authenticate',
				'wp_after_insert_post',
				'wp_cache_delete',
				'wp_cache_set_posts_last_changed',
				'wp_delete_post',
				'wp_get_attachment_metadata',
				'wp_get_attachment_url',
				'wp_get_recent_posts',
				'wp_insert_post',
				'wp_next_scheduled',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
				'wp_schedule_event',
				'wp_set_current_user',
				'wp_slash',
				'wp_update_post',
				'wp_safe_remote_post',
				'xml_parser_create',
				'xmlrpc_getpostcategory',
				'xmlrpc_getposttitle',
				'xmlrpc_removepostdata',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function load_http_ixr_client(): void {
		if ( class_exists( 'WP_HTTP_IXR_Client' ) ) {
			return;
		}

		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			$file = ABSPATH . WPINC . '/class-wp-http-ixr-client.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	private static function check_ixr_value_serialization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'        => 'dangerous-string',
				'value'        => 'alpha<tag attr="value">&"quote"',
				'expectedType' => 'string',
				'mustContain'  => array( '&lt;tag attr=&quot;value&quot;&gt;', '&amp;', '&quot;quote&quot;' ),
				'mustExclude'  => array( '<tag attr', '<script' ),
			),
			array(
				'label'        => 'indexed-array',
				'value'        => array( 1, true, 'plain', 3.5 ),
				'expectedType' => 'array',
				'mustContain'  => array( '<array><data>', '<boolean>1</boolean>', '<double>3.5</double>' ),
				'mustExclude'  => array( '<struct>' ),
			),
			array(
				'label'        => 'escaped-struct-key',
				'value'        => array( 'unsafe<key>&' => 'safe<value>&' ),
				'expectedType' => 'struct',
				'mustContain'  => array( '<struct>', '<name>unsafe&lt;key&gt;&amp;</name>', '<string>safe&lt;value&gt;&amp;</string>' ),
				'mustExclude'  => array( 'unsafe<key>', 'safe<value>' ),
			),
			array(
				'label'        => 'base64-bytes',
				'value'        => new \IXR_Base64( "binary\x00\xFF<xml>" ),
				'expectedType' => 'base64',
				'mustContain'  => array( '<base64>', base64_encode( "binary\x00\xFF<xml>" ) ),
				'mustExclude'  => array( "binary\x00", '<xml>' ),
			),
			array(
				'label'        => 'date',
				'value'        => new \IXR_Date( 1700000000 + $ctx->int( 0, 1000 ) ),
				'expectedType' => 'date',
				'mustContain'  => array( '<dateTime.iso8601>' ),
				'mustExclude'  => array(),
			),
		);

		foreach ( $cases as $case ) {
			$value = new \IXR_Value( $case['value'] );
			$xml   = $value->getXml();
			$ok    = $case['expectedType'] === $value->type && is_string( $xml ) && '' !== $xml;

			foreach ( $case['mustContain'] as $needle ) {
				$ok = $ok && str_contains( $xml, $needle );
			}
			foreach ( $case['mustExclude'] as $needle ) {
				$ok = $ok && false === strpos( $xml, $needle );
			}

			if ( ! $ok ) {
				$failures[] = array(
					'label'        => $case['label'],
					'expectedType' => $case['expectedType'],
					'actualType'   => $value->type,
					'xml'          => self::describe_string( $xml ),
				);
			}
		}

		return self::row(
			$ctx,
			'xmlrpc.ixr-value.xml-escaping-and-types',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_ixr_request_round_trip( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::request_cases( $ctx );

		foreach ( $cases as $case ) {
			$request = new \IXR_Request( $case['method'], $case['args'] );
			$xml     = $request->getXml();
			$message = new \IXR_Message( $xml );
			$parsed  = self::call(
				static function () use ( $message ) {
					return $message->parse();
				}
			);

			$expected_params = self::normalize_ixr_value( $case['args'] );
			$actual_params   = self::normalize_ixr_value( $message->params );
			$ok              = ! $parsed['threw']
				&& true === $parsed['value']
				&& 'methodCall' === $message->messageType
				&& $case['method'] === $message->methodName
				&& self::values_equivalent( $expected_params, $actual_params )
				&& $request->getLength() === strlen( $xml )
				&& false === strpos( $xml, '<script' );

			if ( ! $ok ) {
				$failures[] = array(
					'label'          => $case['label'],
					'method'         => $case['method'],
					'parse'          => self::describe_call( $parsed ),
					'messageType'    => $message->messageType,
					'methodName'     => $message->methodName,
					'expectedParams' => self::describe_value( $expected_params ),
					'actualParams'   => self::describe_value( $actual_params ),
					'difference'     => self::first_difference( $expected_params, $actual_params ),
					'xml'            => self::describe_string( $xml ),
				);
			}
		}

		return self::row(
			$ctx,
			'xmlrpc.ixr-request.serialized-message-round-trips',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function check_ixr_message_fail_closed( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'    => 'empty',
				'message'  => '',
				'expected' => false,
			),
			array(
				'label'    => 'unknown-root',
				'message'  => '<?xml version="1.0"?><notMethod><value>bad</value></notMethod>',
				'expected' => false,
			),
			array(
				'label'    => 'repeated-doctype-root',
				'message'  => '<!DOCTYPE methodCall><!DOCTYPE methodCall><methodCall><methodName>demo.sayHello</methodName></methodCall>',
				'expected' => false,
			),
			array(
				'label'    => 'response-not-call',
				'message'  => '<methodResponse><params><param><value><string>ok</string></value></param></params></methodResponse>',
				'expected' => true,
			),
		);

		foreach ( $cases as $case ) {
			$message = new \IXR_Message( $case['message'] );
			$result  = self::call(
				static function () use ( $message ) {
					return $message->parse();
				}
			);
			$ok      = ! $result['threw'] && $case['expected'] === $result['value'];

			if ( ! $ok ) {
				$failures[] = array(
					'label'  => $case['label'],
					'result' => self::describe_call( $result ),
				);
			}
		}

		$limit_filter = static function (): int {
			return 1;
		};
		try {
			\add_filter( 'xmlrpc_element_limit', $limit_filter );
			$limited_message = new \IXR_Message( '<methodCall><methodName>demo.sayHello</methodName><params><param><value><string>a</string></value></param></params></methodCall>' );
			$limited         = self::call(
				static function () use ( $limited_message ) {
					return $limited_message->parse();
				}
			);
		} finally {
			\remove_filter( 'xmlrpc_element_limit', $limit_filter );
		}

		if ( $limited['threw'] || false !== $limited['value'] ) {
			$failures[] = array(
				'label'  => 'element-limit',
				'result' => self::describe_call( $limited ),
			);
		}

		return self::row(
			$ctx,
			'xmlrpc.ixr-message.invalid-inputs-fail-closed',
			array() === $failures,
			array(
				'cases'    => count( $cases ) + 1,
				'failures' => $failures,
			)
		);
	}

	private static function check_ixr_fault_xml( \ComponentFuzz\FuzzContext $ctx ): array {
		$message = 'bad <fault> & "quoted" ' . self::safe_text( $ctx, 12 );
		$error   = new \IXR_Error( 451, $message );
		$xml     = $error->getXml();
		$parsed  = new \IXR_Message( $xml );
		$result  = self::call(
			static function () use ( $parsed ) {
				return $parsed->parse();
			}
		);

		$ok = ! $result['threw']
			&& true === $result['value']
			&& 'fault' === $parsed->messageType
			&& 451 === $parsed->faultCode
			&& $message === $parsed->faultString
			&& false === strpos( $xml, '<fault>' . $message )
			&& false === strpos( $xml, '<faultString>' )
			&& str_contains( $xml, '&lt;fault&gt;' )
			&& str_contains( $xml, '&amp;' );

		return self::row(
			$ctx,
			'xmlrpc.ixr-error.fault-xml-escapes-and-parses',
			$ok,
			array(
				'message'     => self::describe_string( $message ),
				'parse'       => self::describe_call( $result ),
				'faultCode'   => $parsed->faultCode,
				'faultString' => self::describe_value( $parsed->faultString ),
				'xml'         => self::describe_string( $xml ),
			)
		);
	}

	private static function check_ixr_server_dispatch( \ComponentFuzz\FuzzContext $ctx ): array {
		$server       = new \IXR_Server( false, false, true );
		$list_methods = $server->listMethods( array() );
		$capabilities = $server->getCapabilities( array() );
		$missing      = $server->call( 'component.missing', array() );
		$recursive    = $server->multiCall(
			array(
				array(
					'methodName' => 'system.multicall',
					'params'     => array(),
				),
			)
		);

		$ok = $server->hasMethod( 'system.getCapabilities' )
			&& $server->hasMethod( 'system.listMethods' )
			&& $server->hasMethod( 'system.multicall' )
			&& in_array( 'system.getCapabilities', $list_methods, true )
			&& isset( $capabilities['xmlrpc']['specVersion'], $capabilities['system.multicall']['specVersion'] )
			&& $missing instanceof \IXR_Error
			&& -32601 === $missing->code
			&& is_array( $recursive )
			&& isset( $recursive[0]['faultCode'] )
			&& -32600 === $recursive[0]['faultCode'];

		return self::row(
			$ctx,
			'xmlrpc.ixr-server.system-methods-and-errors',
			$ok,
			array(
				'methods'      => $list_methods,
				'capabilities' => self::describe_value( $capabilities ),
				'missing'      => self::describe_value( $missing ),
				'recursive'    => self::describe_value( $recursive ),
			)
		);
	}

	private static function check_ixr_server_multicall_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$marker = 'multi-' . $ctx->identifier( 5, 10 );
		$text   = 'payload <tag>& "' . self::safe_text( $ctx->fork( 'text' ), 18 );
		$left   = $ctx->int( -1000, 1000 );
		$right  = $ctx->int( -1000, 1000 );
		$helper = new class() {
			public function echoPayload( $args ) {
				return $args;
			}

			public function addPair( array $args ): int {
				return array_sum( $args );
			}

			public function reject( array $args ): \IXR_Error {
				return new \IXR_Error( 490, 'Rejected ' . (string) ( $args['marker'] ?? '' ) );
			}
		};
		$server = new \IXR_Server(
			array(
				'component.echo'   => array( $helper, 'echoPayload' ),
				'component.add'    => array( $helper, 'addPair' ),
				'component.reject' => array( $helper, 'reject' ),
			),
			false,
			true
		);

		$calls  = array(
			array(
				'methodName' => 'component.echo',
				'params'     => array(
					array(
						'marker' => $marker,
						'text'   => $text,
					),
				),
			),
			array(
				'methodName' => 'component.add',
				'params'     => array( $left, $right ),
			),
			array(
				'methodName' => 'component.reject',
				'params'     => array( array( 'marker' => $marker ) ),
			),
			array(
				'methodName' => 'component.missing',
				'params'     => array( $marker ),
			),
			array(
				'methodName' => 'system.multicall',
				'params'     => array(),
			),
		);
		$result = self::call(
			static function () use ( $server, $calls ) {
				return $server->multiCall( $calls );
			}
		);
		$value  = ! $result['threw'] ? new \IXR_Value( $result['value'] ) : null;
		$xml    = $value instanceof \IXR_Value ? $value->getXml() : '';

		$ok = ! $result['threw']
			&& is_array( $result['value'] )
			&& 5 === count( $result['value'] )
			&& array( 'marker' => $marker, 'text' => $text ) === ( $result['value'][0][0] ?? null )
			&& array( $left + $right ) === ( $result['value'][1] ?? null )
			&& 490 === ( $result['value'][2]['faultCode'] ?? null )
			&& 'Rejected ' . $marker === ( $result['value'][2]['faultString'] ?? null )
			&& -32601 === ( $result['value'][3]['faultCode'] ?? null )
			&& -32600 === ( $result['value'][4]['faultCode'] ?? null )
			&& str_contains( (string) ( $result['value'][3]['faultString'] ?? '' ), 'component.missing' )
			&& str_contains( (string) ( $result['value'][4]['faultString'] ?? '' ), 'Recursive calls' )
			&& str_contains( $xml, '<array><data>' )
			&& str_contains( $xml, '<name>faultCode</name>' )
			&& false === strpos( $xml, '<tag>' );

		return self::row(
			$ctx,
			'xmlrpc.ixr-server.multicall-mixed-success-fault-order',
			$ok,
			array(
				'marker' => $marker,
				'left'   => $left,
				'right'  => $right,
				'call'   => self::describe_call( $result ),
				'xml'    => self::describe_string( $xml ),
			)
		);
	}

	private static function check_wp_xmlrpc_server_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$seen_filters = 0;
		$methods_hook = static function ( array $methods ) use ( &$seen_filters ): array {
			++$seen_filters;
			unset( $methods['demo.sayHello'] );
			$methods['component.filteredEcho'] = 'this:sayHello';
			return $methods;
		};
		$text_filters = static function (): array {
			return array(
				'component-filter' => array(
					'label' => 'Component Filter',
				),
			);
		};
		$disabled_xmlrpc = static function (): bool {
			return false;
		};

		try {
			\add_filter( 'xmlrpc_methods', $methods_hook );
			\add_filter( 'xmlrpc_text_filters', $text_filters );
			\add_filter( 'xmlrpc_enabled', $disabled_xmlrpc );

			$server = new \wp_xmlrpc_server();
			$sum    = $server->addTwoNumbers( array( $ctx->int( -1000, 1000 ), $ctx->int( -1000, 1000 ) ) );
			$bad    = $server->addTwoNumbers( array( '1', 2 ) );
			$login  = $server->login( 'component-user', 'component-password' );

			$escaped_scalar = "quote' slash\\ <tag>";
			$escaped_return = $server->escape( $escaped_scalar );
			$escaped_array  = array(
				'plain'  => "quote' slash\\ <tag>",
				'nested' => array( 'value' => '"double"' ),
				'object' => (object) array( 'raw' => "don't slash object props" ),
			);
			$server->escape( $escaped_array );

			$supported = $server->mt_supportedMethods();
			$filters   = $server->mt_supportedTextFilters();
		} finally {
			\remove_filter( 'xmlrpc_methods', $methods_hook );
			\remove_filter( 'xmlrpc_text_filters', $text_filters );
			\remove_filter( 'xmlrpc_enabled', $disabled_xmlrpc );
		}

		self::collect_failure(
			$failures,
			1 === $seen_filters
				&& isset( $server->methods['component.filteredEcho'] )
				&& ! isset( $server->methods['demo.sayHello'] )
				&& in_array( 'component.filteredEcho', $supported, true )
				&& ! in_array( 'demo.sayHello', $supported, true ),
			'wp_xmlrpc_server method registry filter is scoped and reflected in mt.supportedMethods',
			array(
				'seenFilters' => $seen_filters,
				'methods'     => array_slice( $supported, 0, 12 ),
			)
		);

		self::collect_failure(
			$failures,
			'Hello!' === $server->sayHello()
				&& is_int( $sum )
				&& $bad instanceof \IXR_Error
				&& 400 === $bad->code,
			'demo helpers return stable values and invalid arguments produce IXR_Error',
			array(
				'sum' => self::describe_value( $sum ),
				'bad' => self::describe_value( $bad ),
			)
		);

		self::collect_failure(
			$failures,
			false === $login
				&& $server->error instanceof \IXR_Error
				&& 405 === $server->error->code,
			'disabled xmlrpc login fails closed before authentication',
			array(
				'login' => self::describe_value( $login ),
				'error' => self::describe_value( $server->error ),
			)
		);

		self::collect_failure(
			$failures,
			\wp_slash( "quote' slash\\ <tag>" ) === $escaped_return
				&& \wp_slash( "quote' slash\\ <tag>" ) === $escaped_array['plain']
				&& \wp_slash( '"double"' ) === $escaped_array['nested']['value']
				&& "don't slash object props" === $escaped_array['object']->raw,
			'escape slashes scalars and nested arrays but leaves object properties alone',
			array(
				'escapedReturn' => self::describe_string( $escaped_return ),
				'escapedArray'  => self::describe_value( $escaped_array ),
			)
		);

		self::collect_failure(
			$failures,
			array( 'component-filter' => array( 'label' => 'Component Filter' ) ) === $filters,
			'mt.supportedTextFilters returns the filtered list',
			array( 'filters' => self::describe_value( $filters ) )
		);

		return self::row(
			$ctx,
			'xmlrpc.wp-server.safe-helper-contracts',
			array() === $failures,
			array(
				'failures'             => $failures,
				'dbBackedPathsSkipped' => array(
					'wp.getOptions requires successful XML-RPC authentication before option reads.',
					'Publishing, media upload, taxonomy, and comment methods require DB/network side effects.',
					'Pingback coverage is limited to pre-network fail-closed and read-only lookup branches.',
				),
			)
		);
	}

	private static function check_pingback_fail_closed_and_readonly_lookups( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures         = array();
		$home             = 'http://example.test';
		$post_id          = 70000 + $ctx->int( 1, 5000 );
		$closed_post_id   = $post_id + 1;
		$missing_post_id  = $post_id + 10000;
		$comment_base_id  = $post_id + 20000;
		$title            = 'Pingback Target ' . self::safe_text( $ctx->fork( 'title' ), 18 );
		$slug             = 'pingback-' . $ctx->identifier( 5, 10 );
		$source           = $home . '/source/' . $ctx->identifier( 5, 12 ) . '?from=' . rawurlencode( self::safe_text( $ctx->fork( 'source' ), 10 ) );
		$other_source     = $home . '/source/other/' . $ctx->identifier( 5, 12 );
		$pingback_source  = $home . '/pingback/' . $ctx->identifier( 4, 8 );
		$same_source      = $home . '/?p=' . $post_id;
		$server           = new \wp_xmlrpc_server();
		$events           = array(
			'xmlrpcCall' => array(),
			'sourceUri'  => array(),
			'http'       => 0,
			'pingback'   => 0,
		);
		$targets          = array(
			'query'      => $home . '/?p=' . $post_id,
			'p-path'     => $home . '/archives/p/' . $post_id,
			'fragment'   => $home . '/linked#' . $post_id,
			'post-frag'  => $home . '/linked#post-' . $post_id,
			'missing'    => $home . '/?p=' . $missing_post_id,
			'unknown'    => $home . '/not-a-post',
			'offsite'    => 'http://offsite.invalid/?p=' . $post_id,
		);
		$wpdb             = $GLOBALS['wpdb'] ?? null;
		$options_snapshot = is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_get_options' )
			? $wpdb->component_fuzz_get_options()
			: null;
		$content_snapshot = is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_content_counts' )
			? $wpdb->component_fuzz_content_counts()
			: null;
		$runtime_snapshot = is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_get_runtime_state' )
			? $wpdb->component_fuzz_get_runtime_state()
			: null;
		$had_wp_rewrite   = array_key_exists( 'wp_rewrite', $GLOBALS );
		$old_wp_rewrite   = $GLOBALS['wp_rewrite'] ?? null;
		$home_filter      = static function () use ( $home ): string {
			return $home;
		};
		$siteurl_filter   = static function () use ( $home ): string {
			return $home;
		};
		$url_filter       = static function ( string $url ): string {
			return $url;
		};
		$source_filter    = static function ( string $from, string $to ) use ( &$events, $source ): string {
			$events['sourceUri'][] = array(
				'from' => $from,
				'to'   => $to,
			);
			if ( str_contains( $from, '/blocked-empty-source/' ) ) {
				return '';
			}
			if ( str_contains( $from, '&amp;' ) ) {
				return str_replace( '&amp;', '&', $from );
			}
			return $source === $from ? $source : $from;
		};
		$xmlrpc_action    = static function ( string $method ) use ( &$events ): void {
			$events['xmlrpcCall'][] = $method;
		};
		$http_filter      = static function () use ( &$events ) {
			++$events['http'];
			return new \WP_Error( 'component_fuzz_unexpected_pingback_http', 'Pingback fuzz cases must not reach HTTP transport.' );
		};
		$pingback_action  = static function () use ( &$events ): void {
			++$events['pingback'];
		};
		$xmlrpc_error_filter_priority = false;

		try {
			$GLOBALS['wp_rewrite'] = new class() {
				public $index = 'index.php';
				public $use_verbose_page_rules = false;

				public function wp_rewrite_rules(): array {
					return array();
				}

				public function using_index_permalinks(): bool {
					return false;
				}
			};

			\add_filter( 'pre_option_home', $home_filter, 0, 3 );
			\add_filter( 'pre_option_siteurl', $siteurl_filter, 0, 3 );
			$xmlrpc_error_filter_priority = \has_filter( 'xmlrpc_pingback_error', 'xmlrpc_pingback_error' );
			if ( false !== $xmlrpc_error_filter_priority ) {
				\remove_filter( 'xmlrpc_pingback_error', 'xmlrpc_pingback_error', $xmlrpc_error_filter_priority );
			}

			self::seed_pingback_post( $post_id, $title, $slug, 'open' );
			self::seed_pingback_post( $closed_post_id, $title . ' Closed', $slug . '-closed', 'closed' );
			self::seed_pingback_comment( $comment_base_id, $post_id, $source, 'pingback' );
			self::seed_pingback_comment( $comment_base_id + 1, $post_id, $other_source, 'comment' );
			self::seed_pingback_comment( $comment_base_id + 2, $post_id, $pingback_source, 'pingback' );

			\add_filter( 'url_to_postid', $url_filter, 10, 1 );
			\add_filter( 'pingback_ping_source_uri', $source_filter, 10, 2 );
			\add_filter( 'pre_http_request', $http_filter, 10, 0 );
			\add_action( 'xmlrpc_call', $xmlrpc_action, 10, 1 );
			\add_action( 'pingback_post', $pingback_action, 10, 1 );

			$empty_source  = $server->pingback_ping( array( $home . '/blocked-empty-source/' . $ctx->identifier( 4, 8 ), $targets['query'] ) );
			$offsite       = $server->pingback_ping( array( $source, $targets['offsite'] ) );
			$unknown       = $server->pingback_ping( array( $source, $targets['unknown'] ) );
			$missing_post  = $server->pingback_ping( array( $source, $targets['missing'] ) );
			$same_resource = $server->pingback_ping( array( $same_source, $targets['query'] ) );
			$closed        = $server->pingback_ping( array( $source, $home . '/?p=' . $closed_post_id ) );
			$duplicate     = array();
			foreach ( array( 'query', 'p-path', 'fragment', 'post-frag' ) as $label ) {
				$duplicate[ $label ] = $server->pingback_ping( array( $source, $targets[ $label ] ) );
			}
			$pingbacks     = $server->pingback_extensions_getPingbacks( $targets['query'] );
			$missing_list  = $server->pingback_extensions_getPingbacks( $targets['missing'] );
			$unknown_list  = $server->pingback_extensions_getPingbacks( $targets['unknown'] );
		} finally {
			\remove_action( 'pingback_post', $pingback_action, 10 );
			\remove_action( 'xmlrpc_call', $xmlrpc_action, 10 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			\remove_filter( 'pingback_ping_source_uri', $source_filter, 10 );
			\remove_filter( 'url_to_postid', $url_filter, 10 );
			\remove_filter( 'pre_option_home', $home_filter, 0 );
			\remove_filter( 'pre_option_siteurl', $siteurl_filter, 0 );
			if ( false !== $xmlrpc_error_filter_priority ) {
				\add_filter( 'xmlrpc_pingback_error', 'xmlrpc_pingback_error', $xmlrpc_error_filter_priority );
			}
			if ( is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
				foreach ( array( $comment_base_id, $comment_base_id + 1, $comment_base_id + 2 ) as $comment_id ) {
					$wpdb->delete( $wpdb->comments, array( 'comment_ID' => $comment_id ) );
				}
				foreach ( array( $post_id, $closed_post_id ) as $delete_post_id ) {
					$wpdb->delete( $wpdb->posts, array( 'ID' => $delete_post_id ) );
				}
			}
			foreach ( array( $post_id, $closed_post_id, $missing_post_id ) as $cache_id ) {
				\wp_cache_delete( $cache_id, 'posts' );
			}
			if ( null !== $options_snapshot && is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_reset_options' ) ) {
				$wpdb->component_fuzz_reset_options( $options_snapshot );
			}
			if ( null !== $runtime_snapshot && is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_restore_runtime_state' ) ) {
				$wpdb->component_fuzz_restore_runtime_state( $runtime_snapshot );
			}
			if ( $had_wp_rewrite ) {
				$GLOBALS['wp_rewrite'] = $old_wp_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}
		}

		self::collect_failure(
			$failures,
			self::ixr_error_code( $empty_source ) === 0
				&& self::ixr_error_code( $offsite ) === 0
				&& self::ixr_error_code( $unknown ) === 33
				&& self::ixr_error_code( $missing_post ) === 33
				&& self::ixr_error_code( $same_resource ) === 0
				&& self::ixr_error_code( $closed ) === 33,
			'pingback.ping rejects empty source, offsite target, unresolved target, missing posts, same resource, and closed pings before network',
			array(
				'emptySource'  => self::describe_value( $empty_source ),
				'offsite'      => self::describe_value( $offsite ),
				'unknown'      => self::describe_value( $unknown ),
				'missingPost'  => self::describe_value( $missing_post ),
				'sameResource' => self::describe_value( $same_resource ),
				'closed'       => self::describe_value( $closed ),
			)
		);

		foreach ( $duplicate as $label => $result ) {
			self::collect_failure(
				$failures,
				self::ixr_error_code( $result ) === 48,
				'pingback.ping legacy target extraction reaches duplicate detection before network',
				array(
					'label'  => $label,
					'target' => $targets[ $label ],
					'result' => self::describe_value( $result ),
				)
			);
		}

		$expected_pingbacks = array( $source, $pingback_source );
		$actual_pingbacks   = is_array( $pingbacks ) ? array_values( $pingbacks ) : array();
		sort( $expected_pingbacks, SORT_STRING );
		sort( $actual_pingbacks, SORT_STRING );

		self::collect_failure(
			$failures,
			$expected_pingbacks === $actual_pingbacks
				&& self::ixr_error_code( $missing_list ) === 32
				&& self::ixr_error_code( $unknown_list ) === 33,
			'pingback.extensions.getPingbacks returns only pingback author URLs and fail-closed errors for missing resources',
			array(
				'pingbacks'   => self::describe_value( $pingbacks ),
				'missingList' => self::describe_value( $missing_list ),
				'unknownList' => self::describe_value( $unknown_list ),
			)
		);

		self::collect_failure(
			$failures,
			0 === $events['http']
				&& 0 === $events['pingback']
				&& 13 === count( $events['xmlrpcCall'] )
				&& 10 === count( $events['sourceUri'] )
				&& false === \has_filter( 'url_to_postid', $url_filter )
				&& false === \has_filter( 'pre_option_home', $home_filter )
				&& false === \has_filter( 'pre_option_siteurl', $siteurl_filter )
				&& false === \has_filter( 'pingback_ping_source_uri', $source_filter )
				&& false === \has_filter( 'pre_http_request', $http_filter )
				&& false === \has_filter( 'xmlrpc_call', $xmlrpc_action )
				&& false === \has_filter( 'pingback_post', $pingback_action )
				&& (
					null === $content_snapshot
					|| $content_snapshot === ( is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_content_counts' ) ? $wpdb->component_fuzz_content_counts() : null )
				),
			'pingback fuzzing stays pre-network/pre-insert and removes hooks after execution',
			array(
				'events'             => $events,
				'urlToPostIdFilter'  => \has_filter( 'url_to_postid', $url_filter ),
				'homeFilter'         => \has_filter( 'pre_option_home', $home_filter ),
				'siteurlFilter'      => \has_filter( 'pre_option_siteurl', $siteurl_filter ),
				'sourceUriFilter'    => \has_filter( 'pingback_ping_source_uri', $source_filter ),
				'preHttpFilter'      => \has_filter( 'pre_http_request', $http_filter ),
				'xmlrpcAction'       => \has_filter( 'xmlrpc_call', $xmlrpc_action ),
				'pingbackAction'     => \has_filter( 'pingback_post', $pingback_action ),
				'contentBefore'      => $content_snapshot,
				'contentAfterCleanup' => is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_content_counts' ) ? $wpdb->component_fuzz_content_counts() : null,
			)
		);

		return self::row(
			$ctx,
			'xmlrpc.pingback.fail-closed-and-readonly-lookups',
			array() === $failures,
			array(
				'postId'   => $post_id,
				'failures' => $failures,
				'events'   => $events,
			)
		);
	}

	private static function check_authenticated_readonly_content_media_methods( \ComponentFuzz\FuzzContext $ctx ): array {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'insert' )
			|| ! method_exists( $wpdb, 'delete' )
			|| ! method_exists( $wpdb, 'component_fuzz_content_counts' )
			|| ! method_exists( $wpdb, 'component_fuzz_get_runtime_state' )
			|| ! method_exists( $wpdb, 'component_fuzz_restore_runtime_state' )
		) {
			return $ctx->skip(
				'xmlrpc.authenticated-readonly-content-media',
				'Authenticated XML-RPC read-only content/media coverage requires the component fuzzer in-memory DB stub.',
				array()
			);
		}

		$failures         = array();
		$global_snapshot  = self::snapshot_globals();
		$content_snapshot = $wpdb->component_fuzz_content_counts();
		$runtime_snapshot = $wpdb->component_fuzz_get_runtime_state();
		$cleanup_state    = array(
			'authenticate'       => null,
			'userHasCap'         => null,
			'defaultFields'      => null,
			'postPrepare'        => null,
			'mediaPrepare'       => null,
			'xmlrpcCall'         => null,
			'uploadDir'          => null,
			'imageDownsize'      => null,
			'home'               => null,
			'siteurl'            => null,
			'permalinkStructure' => null,
			'stickyPosts'        => null,
			'contentCounts'      => null,
		);
		$home             = 'https://example.test';
		$uploads_url      = $home . '/wp-content/uploads';
		$uploads_dir      = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-xmlrpc-uploads';
		$marker           = 'xmlrpc-readonly-' . $ctx->identifier( 5, 12 );
		$user_id          = 820000 + $ctx->int( 1, 5000 );
		$username         = 'xmlrpc_user_' . $ctx->identifier( 4, 9 );
		$password         = 'xmlrpc-pass-' . $ctx->identifier( 6, 12 );
		$base_id          = 830000 + ( $ctx->int( 1, 8000 ) * 10 );
		$newer_id         = $base_id + 1;
		$older_id         = $base_id + 2;
		$future_id        = $base_id + 3;
		$jpg_id           = $base_id + 4;
		$pdf_id           = $base_id + 5;
		$png_id           = $base_id + 6;
		$missing_id       = $base_id + 9000;
		$jpg_file         = '2026/06/' . $marker . '.jpg';
		$pdf_file         = '2026/06/' . $marker . '.pdf';
		$png_file         = '2026/06/' . $marker . '.png';
		$jpg_meta         = array(
			'width'  => 640,
			'height' => 480,
			'file'   => $jpg_file,
			'sizes'  => array(
				'thumbnail' => array(
					'file'      => 'thumb-' . basename( $jpg_file ),
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
				),
			),
		);
		$jpg_alt          = 'JPEG alt ' . self::safe_text( $ctx->fork( 'jpg-alt' ), 12 );
		$post_rows        = array(
			$newer_id  => array(
				'ID'                => $newer_id,
				'post_author'       => $user_id,
				'post_date'         => '2026-06-05 12:13:14',
				'post_date_gmt'     => '2026-06-05 10:13:14',
				'post_content'      => 'XML-RPC newer content ' . self::safe_text( $ctx->fork( 'newer-content' ), 18 ),
				'post_title'        => 'XML-RPC newer ' . self::safe_text( $ctx->fork( 'newer-title' ), 14 ),
				'post_excerpt'      => 'Newer excerpt',
				'post_status'       => 'publish',
				'comment_status'    => 'open',
				'ping_status'       => 'closed',
				'post_password'     => '',
				'post_name'         => 'xmlrpc-newer-' . $ctx->identifier( 4, 8 ),
				'post_modified'     => '2026-06-06 07:08:09',
				'post_modified_gmt' => '2026-06-06 05:08:09',
				'post_parent'       => 0,
				'guid'              => $home . '/?p=' . $newer_id,
				'menu_order'        => 2,
				'post_type'         => 'post',
				'post_mime_type'    => '',
				'comment_count'     => 0,
			),
			$older_id  => array(
				'ID'                => $older_id,
				'post_author'       => $user_id,
				'post_date'         => '2026-06-02 03:04:05',
				'post_date_gmt'     => '2026-06-02 01:04:05',
				'post_content'      => 'XML-RPC older content ' . self::safe_text( $ctx->fork( 'older-content' ), 18 ),
				'post_title'        => 'XML-RPC older ' . self::safe_text( $ctx->fork( 'older-title' ), 14 ),
				'post_excerpt'      => 'Older excerpt',
				'post_status'       => 'publish',
				'comment_status'    => 'closed',
				'ping_status'       => 'open',
				'post_password'     => '',
				'post_name'         => 'xmlrpc-older-' . $ctx->identifier( 4, 8 ),
				'post_modified'     => '2026-06-03 07:08:09',
				'post_modified_gmt' => '2026-06-03 05:08:09',
				'post_parent'       => 0,
				'guid'              => $home . '/?p=' . $older_id,
				'menu_order'        => 3,
				'post_type'         => 'post',
				'post_mime_type'    => '',
				'comment_count'     => 0,
			),
			$future_id => array(
				'ID'                => $future_id,
				'post_author'       => $user_id,
				'post_date'         => '2026-05-01 02:03:04',
				'post_date_gmt'     => '2026-05-01 00:03:04',
				'post_content'      => 'XML-RPC future content',
				'post_title'        => 'XML-RPC future ' . self::safe_text( $ctx->fork( 'future-title' ), 14 ),
				'post_excerpt'      => 'Future excerpt',
				'post_status'       => 'future',
				'comment_status'    => 'open',
				'ping_status'       => 'open',
				'post_password'     => '',
				'post_name'         => 'xmlrpc-future-' . $ctx->identifier( 4, 8 ),
				'post_modified'     => '2026-05-01 03:03:04',
				'post_modified_gmt' => '2026-05-01 01:03:04',
				'post_parent'       => 0,
				'guid'              => $home . '/?p=' . $future_id,
				'menu_order'        => 4,
				'post_type'         => 'post',
				'post_mime_type'    => '',
				'comment_count'     => 0,
			),
		);
		$attachment_rows  = array(
			$jpg_id => array(
				'ID'                => $jpg_id,
				'post_author'       => $user_id,
				'post_date'         => '2026-06-08 01:02:03',
				'post_date_gmt'     => '2026-06-07 23:02:03',
				'post_content'      => 'JPEG description ' . self::safe_text( $ctx->fork( 'jpg-description' ), 14 ),
				'post_title'        => 'JPEG ' . self::safe_text( $ctx->fork( 'jpg-title' ), 12 ),
				'post_excerpt'      => 'JPEG caption',
				'post_status'       => 'inherit',
				'comment_status'    => 'closed',
				'ping_status'       => 'closed',
				'post_password'     => '',
				'post_name'         => 'xmlrpc-jpeg-' . $ctx->identifier( 4, 8 ),
				'post_modified'     => '2026-06-08 01:12:03',
				'post_modified_gmt' => '2026-06-07 23:12:03',
				'post_parent'       => $newer_id,
				'guid'              => $uploads_url . '/' . $jpg_file,
				'menu_order'        => 0,
				'post_type'         => 'attachment',
				'post_mime_type'    => 'image/jpeg',
				'comment_count'     => 0,
			),
			$pdf_id => array(
				'ID'                => $pdf_id,
				'post_author'       => $user_id,
				'post_date'         => '2026-06-09 01:02:03',
				'post_date_gmt'     => '2026-06-08 23:02:03',
				'post_content'      => 'PDF description',
				'post_title'        => 'PDF ' . self::safe_text( $ctx->fork( 'pdf-title' ), 12 ),
				'post_excerpt'      => 'PDF caption',
				'post_status'       => 'inherit',
				'comment_status'    => 'closed',
				'ping_status'       => 'closed',
				'post_password'     => '',
				'post_name'         => 'xmlrpc-pdf-' . $ctx->identifier( 4, 8 ),
				'post_modified'     => '2026-06-09 01:12:03',
				'post_modified_gmt' => '2026-06-08 23:12:03',
				'post_parent'       => 0,
				'guid'              => $uploads_url . '/' . $pdf_file,
				'menu_order'        => 0,
				'post_type'         => 'attachment',
				'post_mime_type'    => 'application/pdf',
				'comment_count'     => 0,
			),
			$png_id => array(
				'ID'                => $png_id,
				'post_author'       => $user_id,
				'post_date'         => '2026-06-10 01:02:03',
				'post_date_gmt'     => '2026-06-09 23:02:03',
				'post_content'      => 'PNG description',
				'post_title'        => 'PNG ' . self::safe_text( $ctx->fork( 'png-title' ), 12 ),
				'post_excerpt'      => 'PNG caption',
				'post_status'       => 'inherit',
				'comment_status'    => 'closed',
				'ping_status'       => 'closed',
				'post_password'     => '',
				'post_name'         => 'xmlrpc-png-' . $ctx->identifier( 4, 8 ),
				'post_modified'     => '2026-06-10 01:12:03',
				'post_modified_gmt' => '2026-06-09 23:12:03',
				'post_parent'       => $newer_id,
				'guid'              => $uploads_url . '/' . $png_file,
				'menu_order'        => 0,
				'post_type'         => 'attachment',
				'post_mime_type'    => 'image/png',
				'comment_count'     => 0,
			),
		);
		$default_calls    = array();
		$post_prepare    = array();
		$media_prepare   = array();
		$xmlrpc_calls    = array();
		$grant_caps      = true;
		$thrown          = null;
		$default_post    = null;
		$default_posts   = null;
		$future_post     = null;
		$invalid_post    = null;
		$denied_post     = null;
		$posts_list      = null;
		$invalid_type    = null;
		$denied_posts    = null;
		$media_item      = null;
		$invalid_media   = null;
		$denied_media    = null;
		$media_library      = null;
		$wrong_mime_library = null;
		$unattached         = null;
		$denied_library     = null;
		$auth_failure       = null;

		$authenticate_filter = static function ( $user, string $login, string $pass ) use ( $username, $password, $user_id ) {
			if ( $username === $login && $password === $pass ) {
				return new \WP_User( $user_id );
			}

			return new \WP_Error( 'component_fuzz_xmlrpc_auth', 'Synthetic XML-RPC authentication failure.' );
		};
		$cap_filter          = static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( &$grant_caps, $user_id ): array {
			if ( (int) $user->ID !== $user_id ) {
				return $allcaps;
			}

			foreach ( array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'upload_files', 'edit_post_meta' ) as $primitive ) {
				$allcaps[ $primitive ] = $grant_caps;
			}
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = $grant_caps && 'do_not_allow' !== $cap;
			}

			return $allcaps;
		};
		$default_fields_filter = static function ( array $fields, string $method ) use ( &$default_calls ): array {
			$default_calls[] = array(
				'method' => $method,
				'fields' => array_values( $fields ),
			);
			return array( 'post_title', 'post_status', 'post_date' );
		};
		$post_prepare_filter = static function ( array $prepared, array $post, array $fields ) use ( &$post_prepare, $marker ): array {
			$post_prepare[] = array(
				'postId' => (int) $post['ID'],
				'fields' => array_values( $fields ),
				'keys'   => array_keys( $prepared ),
			);
			$prepared['component_fuzz_marker'] = $marker . ':post:' . (int) $post['ID'];
			return $prepared;
		};
		$media_prepare_filter = static function ( array $prepared, \WP_Post $media_item, string $thumbnail_size ) use ( &$media_prepare, $marker ): array {
			$media_prepare[] = array(
				'attachmentId'  => (int) $media_item->ID,
				'thumbnailSize' => $thumbnail_size,
				'keys'          => array_keys( $prepared ),
			);
			$prepared['component_fuzz_marker'] = $marker . ':media:' . (int) $media_item->ID;
			return $prepared;
		};
		$xmlrpc_call_action = static function ( string $method ) use ( &$xmlrpc_calls ): void {
			$xmlrpc_calls[] = $method;
		};
		$upload_dir_filter  = static function () use ( $uploads_dir, $uploads_url ): array {
			return array(
				'path'    => $uploads_dir,
				'url'     => $uploads_url,
				'subdir'  => '',
				'basedir' => $uploads_dir,
				'baseurl' => $uploads_url,
				'error'   => false,
			);
		};
		$image_downsize_filter = static function ( $downsize, int $id ) use ( $jpg_id, $jpg_file, $uploads_url ) {
			if ( $jpg_id === $id ) {
				return array( $uploads_url . '/' . dirname( $jpg_file ) . '/thumb-' . basename( $jpg_file ), 150, 150, true );
			}

			return $downsize;
		};
		$home_filter        = static function () use ( $home ): string {
			return $home;
		};
		$plain_permalink_filter = static function (): string {
			return '';
		};
		$sticky_filter      = static function () use ( $newer_id ): array {
			return array( $newer_id );
		};

		try {
			\add_filter( 'authenticate', $authenticate_filter, 1, 3 );
			\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
			\add_filter( 'xmlrpc_default_post_fields', $default_fields_filter, 10, 2 );
			\add_filter( 'xmlrpc_prepare_post', $post_prepare_filter, 10, 3 );
			\add_filter( 'xmlrpc_prepare_media_item', $media_prepare_filter, 10, 3 );
			\add_action( 'xmlrpc_call', $xmlrpc_call_action, 10, 1 );
			\add_filter( 'upload_dir', $upload_dir_filter, 10, 1 );
			\add_filter( 'image_downsize', $image_downsize_filter, 10, 2 );
			\add_filter( 'pre_option_home', $home_filter, 0, 3 );
			\add_filter( 'pre_option_siteurl', $home_filter, 0, 3 );
			\add_filter( 'pre_option_permalink_structure', $plain_permalink_filter, 0, 3 );
			\add_filter( 'pre_option_sticky_posts', $sticky_filter, 0, 3 );

			if ( ! \get_post_type_object( 'post' ) ) {
				\register_post_type(
					'post',
					array(
						'_builtin'        => true,
						'public'          => true,
						'show_ui'         => true,
						'capability_type' => 'post',
						'map_meta_cap'    => true,
						'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
					)
				);
			}
			self::seed_xmlrpc_user( $user_id, $username, $password );
			foreach ( $post_rows + $attachment_rows as $row ) {
				self::seed_xmlrpc_post_row( $row );
			}
			self::seed_xmlrpc_post_meta( $jpg_id, '_wp_attached_file', $jpg_file );
			self::seed_xmlrpc_post_meta( $jpg_id, '_wp_attachment_image_alt', $jpg_alt );
			self::seed_xmlrpc_post_meta( $jpg_id, '_wp_attachment_metadata', $jpg_meta );
			self::seed_xmlrpc_post_meta( $pdf_id, '_wp_attached_file', $pdf_file );
			self::seed_xmlrpc_post_meta( $png_id, '_wp_attached_file', $png_file );
			\wp_cache_set_posts_last_changed();

			$server          = new \wp_xmlrpc_server();
			$default_post    = $server->wp_getPost( array( 1, $username, $password, $newer_id ) );
			$default_posts   = $server->wp_getPosts( array( 1, $username, $password, array( 'post_type' => 'post', 'number' => 1 ) ) );
			$future_post     = $server->wp_getPost( array( 1, $username, $password, $future_id, array( 'post_title', 'post_status', 'post_date_gmt', 'link', 'sticky' ) ) );
			$invalid_post    = $server->wp_getPost( array( 1, $username, $password, $missing_id, array( 'post_title' ) ) );
			$posts_list      = $server->wp_getPosts(
				array(
					1,
					$username,
					$password,
					array(
						'post_type'   => 'post',
						'post_status' => 'publish',
						'number'      => 1,
						'offset'      => 1,
						'orderby'     => 'post_date',
						'order'       => 'DESC',
					),
					array( 'post_title', 'post_status', 'post_date' ),
				)
			);
			$invalid_type    = $server->wp_getPosts( array( 1, $username, $password, array( 'post_type' => 'component_fuzz_missing' ), array( 'post_title' ) ) );
			$media_item      = $server->wp_getMediaItem( array( 1, $username, $password, $jpg_id ) );
			$invalid_media   = $server->wp_getMediaItem( array( 1, $username, $password, $newer_id ) );
			$media_library   = $server->wp_getMediaLibrary(
				array(
					1,
					$username,
					$password,
					array(
						'parent_id' => $newer_id,
						'mime_type' => 'image/jpeg',
						'number'    => 1,
						'offset'    => 0,
					),
				)
			);
			$wrong_mime_library = $server->wp_getMediaLibrary(
				array(
					1,
					$username,
					$password,
					array(
						'parent_id' => $newer_id,
						'mime_type' => 'application/pdf',
						'number'    => 5,
						'offset'    => 0,
					),
				)
			);
			$unattached      = $server->wp_getMediaLibrary( array( 1, $username, $password, array( 'parent_id' => 0, 'number' => 5, 'offset' => 0 ) ) );
			$grant_caps      = false;
			$denied_post     = $server->wp_getPost( array( 1, $username, $password, $newer_id, array( 'post_title' ) ) );
			$denied_posts    = $server->wp_getPosts( array( 1, $username, $password, array( 'post_type' => 'post' ), array( 'post_title' ) ) );
			$denied_media    = $server->wp_getMediaItem( array( 1, $username, $password, $jpg_id ) );
			$denied_library  = $server->wp_getMediaLibrary( array( 1, $username, $password, array( 'number' => 1 ) ) );

			$auth_server  = new \wp_xmlrpc_server();
			$auth_failure = $auth_server->wp_getPost( array( 1, $username, $password . '-wrong', $newer_id, array( 'post_title' ) ) );
		} catch ( \Throwable $e ) {
			$thrown = self::describe_throwable( $e );
		} finally {
			\remove_filter( 'pre_option_sticky_posts', $sticky_filter, 0 );
			\remove_filter( 'pre_option_permalink_structure', $plain_permalink_filter, 0 );
			\remove_filter( 'pre_option_siteurl', $home_filter, 0 );
			\remove_filter( 'pre_option_home', $home_filter, 0 );
			\remove_filter( 'image_downsize', $image_downsize_filter, 10 );
			\remove_filter( 'upload_dir', $upload_dir_filter, 10 );
			\remove_action( 'xmlrpc_call', $xmlrpc_call_action, 10 );
			\remove_filter( 'xmlrpc_prepare_media_item', $media_prepare_filter, 10 );
			\remove_filter( 'xmlrpc_prepare_post', $post_prepare_filter, 10 );
			\remove_filter( 'xmlrpc_default_post_fields', $default_fields_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'authenticate', $authenticate_filter, 1 );

			foreach ( array( $jpg_id, $pdf_id, $png_id ) as $post_id ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );
				\wp_cache_delete( $post_id, 'post_meta' );
			}
			foreach ( array( $newer_id, $older_id, $future_id, $jpg_id, $pdf_id, $png_id ) as $post_id ) {
				$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
				\wp_cache_delete( $post_id, 'posts' );
				\wp_cache_delete( $post_id, 'post_meta' );
			}
			$wpdb->delete( $wpdb->users, array( 'ID' => $user_id ) );
			\wp_cache_delete( $user_id, 'users' );
			\wp_cache_delete( $username, 'userlogins' );
			\wp_cache_set_posts_last_changed();
			$cleanup_state = array(
				'authenticate'       => \has_filter( 'authenticate', $authenticate_filter ),
				'userHasCap'         => \has_filter( 'user_has_cap', $cap_filter ),
				'defaultFields'      => \has_filter( 'xmlrpc_default_post_fields', $default_fields_filter ),
				'postPrepare'        => \has_filter( 'xmlrpc_prepare_post', $post_prepare_filter ),
				'mediaPrepare'       => \has_filter( 'xmlrpc_prepare_media_item', $media_prepare_filter ),
				'xmlrpcCall'         => \has_filter( 'xmlrpc_call', $xmlrpc_call_action ),
				'uploadDir'          => \has_filter( 'upload_dir', $upload_dir_filter ),
				'imageDownsize'      => \has_filter( 'image_downsize', $image_downsize_filter ),
				'home'               => \has_filter( 'pre_option_home', $home_filter ),
				'siteurl'            => \has_filter( 'pre_option_siteurl', $home_filter ),
				'permalinkStructure' => \has_filter( 'pre_option_permalink_structure', $plain_permalink_filter ),
				'stickyPosts'        => \has_filter( 'pre_option_sticky_posts', $sticky_filter ),
				'contentCounts'      => $wpdb->component_fuzz_content_counts(),
			);
			$wpdb->component_fuzz_restore_runtime_state( $runtime_snapshot );
			self::restore_globals( $global_snapshot );
		}

		if ( null !== $thrown ) {
			$failures[] = array(
				'message' => 'authenticated XML-RPC read-only methods do not throw',
				'details' => self::describe_value( $thrown ),
			);
		}

		$expected_default_keys = array( 'component_fuzz_marker', 'post_date', 'post_id', 'post_status', 'post_title' );
		$actual_default_keys   = is_array( $default_post ) ? array_keys( $default_post ) : array();
		sort( $actual_default_keys );
		self::collect_failure(
			$failures,
			is_array( $default_post )
				&& $expected_default_keys === $actual_default_keys
				&& (string) $newer_id === ( $default_post['post_id'] ?? null )
				&& is_array( $default_posts )
				&& array( (string) $newer_id ) === self::xmlrpc_post_ids( $default_posts )
				&& $post_rows[ $newer_id ]['post_title'] === ( $default_post['post_title'] ?? null )
				&& 'publish' === ( $default_post['post_status'] ?? null )
				&& '20260605T12:13:14' === self::ixr_date_iso( $default_post['post_date'] ?? null )
				&& $marker . ':post:' . $newer_id === ( $default_post['component_fuzz_marker'] ?? null ),
			'wp.getPost uses default post-field filtering and preserves IXR dates',
			array( 'defaultPost' => self::describe_value( $default_post ) )
		);

		self::collect_failure(
			$failures,
			is_array( $future_post )
				&& 'publish' === ( $future_post['post_status'] ?? null )
				&& '20260501T00:03:04' === self::ixr_date_iso( $future_post['post_date_gmt'] ?? null )
				&& $home . '/?p=' . $future_id === ( $future_post['link'] ?? null )
				&& false === ( $future_post['sticky'] ?? null )
				&& ! array_key_exists( 'post_content', $future_post ),
			'wp.getPost explicit field filtering preserves permalink/sticky and future-as-publish behavior',
			array( 'futurePost' => self::describe_value( $future_post ) )
		);

		self::collect_failure(
			$failures,
			404 === self::ixr_error_code( $invalid_post )
				&& 401 === self::ixr_error_code( $denied_post )
				&& 403 === self::ixr_error_code( $auth_failure ),
			'wp.getPost returns distinct missing, capability, and authentication errors',
			array(
				'invalidPost' => self::describe_value( $invalid_post ),
				'deniedPost'  => self::describe_value( $denied_post ),
				'authFailure' => self::describe_value( $auth_failure ),
			)
		);

		self::collect_failure(
			$failures,
			is_array( $posts_list )
				&& array( (string) $older_id ) === self::xmlrpc_post_ids( $posts_list )
				&& $post_rows[ $older_id ]['post_title'] === ( $posts_list[0]['post_title'] ?? null )
				&& '20260602T03:04:05' === self::ixr_date_iso( $posts_list[0]['post_date'] ?? null )
				&& 403 === self::ixr_error_code( $invalid_type )
				&& 401 === self::ixr_error_code( $denied_posts ),
			'wp.getPosts applies type/status/number/offset/order filters and fail-closed collection branches',
			array(
				'postsList'   => self::describe_value( $posts_list ),
				'invalidType' => self::describe_value( $invalid_type ),
				'deniedPosts' => self::describe_value( $denied_posts ),
			)
		);

		self::collect_failure(
			$failures,
			is_array( $media_item )
				&& (string) $jpg_id === ( $media_item['attachment_id'] ?? null )
				&& '20260607T23:02:03' === self::ixr_date_iso( $media_item['date_created_gmt'] ?? null )
				&& $newer_id === ( $media_item['parent'] ?? null )
				&& $uploads_url . '/' . $jpg_file === ( $media_item['link'] ?? null )
				&& $uploads_url . '/' . dirname( $jpg_file ) . '/thumb-' . basename( $jpg_file ) === ( $media_item['thumbnail'] ?? null )
				&& $jpg_meta === ( $media_item['metadata'] ?? null )
				&& $jpg_alt === ( $media_item['alt'] ?? null )
				&& 'image/jpeg' === ( $media_item['type'] ?? null )
				&& $marker . ':media:' . $jpg_id === ( $media_item['component_fuzz_marker'] ?? null )
				&& 404 === self::ixr_error_code( $invalid_media )
				&& 403 === self::ixr_error_code( $denied_media ),
			'wp.getMediaItem returns exact media projection and distinguishes invalid attachment from upload-cap denial',
			array(
				'mediaItem'    => self::describe_value( $media_item ),
				'invalidMedia' => self::describe_value( $invalid_media ),
				'deniedMedia'  => self::describe_value( $denied_media ),
			)
		);

		self::collect_failure(
			$failures,
			is_array( $media_library )
				&& array( (string) $jpg_id ) === self::xmlrpc_media_ids( $media_library )
				&& is_array( $wrong_mime_library )
				&& array() === self::xmlrpc_media_ids( $wrong_mime_library )
				&& is_array( $unattached )
				&& array( (string) $pdf_id ) === self::xmlrpc_media_ids( $unattached )
				&& 401 === self::ixr_error_code( $denied_library ),
			'wp.getMediaLibrary applies parent, MIME, number, and offset filters with its documented cap error',
			array(
				'mediaLibrary'     => self::describe_value( $media_library ),
				'wrongMimeLibrary' => self::describe_value( $wrong_mime_library ),
				'unattached'       => self::describe_value( $unattached ),
				'denied'           => self::describe_value( $denied_library ),
			)
		);

		self::collect_failure(
			$failures,
			in_array( 'wp.getPost', array_column( $default_calls, 'method' ), true )
				&& in_array( 'wp.getPosts', array_column( $default_calls, 'method' ), true )
				&& in_array( $newer_id, array_column( $post_prepare, 'postId' ), true )
				&& in_array( $older_id, array_column( $post_prepare, 'postId' ), true )
				&& in_array( $jpg_id, array_column( $media_prepare, 'attachmentId' ), true )
				&& in_array( 'wp.getMediaLibrary', $xmlrpc_calls, true )
				&& false === $cleanup_state['authenticate']
				&& false === $cleanup_state['userHasCap']
				&& false === $cleanup_state['defaultFields']
				&& false === $cleanup_state['postPrepare']
				&& false === $cleanup_state['mediaPrepare']
				&& false === $cleanup_state['xmlrpcCall']
				&& false === $cleanup_state['uploadDir']
				&& false === $cleanup_state['imageDownsize']
				&& false === $cleanup_state['home']
				&& false === $cleanup_state['siteurl']
				&& false === $cleanup_state['permalinkStructure']
				&& false === $cleanup_state['stickyPosts']
				&& $content_snapshot === $cleanup_state['contentCounts'],
			'authenticated XML-RPC read-only checks fire scoped hooks and restore seeded content',
			array(
				'defaultCalls' => self::describe_value( $default_calls ),
				'postPrepare'  => self::describe_value( $post_prepare ),
				'mediaPrepare' => self::describe_value( $media_prepare ),
				'xmlrpcCalls'  => self::describe_value( $xmlrpc_calls ),
				'cleanup'      => $cleanup_state,
				'beforeCounts' => $content_snapshot,
				'afterCounts'  => $cleanup_state['contentCounts'],
			)
		);

		return self::row(
			$ctx,
			'xmlrpc.authenticated-readonly-content-media',
			array() === $failures,
			array(
				'failures'    => $failures,
				'xmlrpcCalls' => $xmlrpc_calls,
			)
		);
	}

	private static function check_authenticated_write_post_methods( \ComponentFuzz\FuzzContext $ctx ): array {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'insert' )
			|| ! method_exists( $wpdb, 'delete' )
			|| ! method_exists( $wpdb, 'component_fuzz_content_counts' )
			|| ! method_exists( $wpdb, 'component_fuzz_get_runtime_state' )
			|| ! method_exists( $wpdb, 'component_fuzz_restore_runtime_state' )
			|| ! method_exists( $wpdb, 'component_fuzz_get_options' )
			|| ! method_exists( $wpdb, 'component_fuzz_reset_options' )
		) {
			return $ctx->skip(
				'xmlrpc.authenticated-write-post-methods',
				'Authenticated XML-RPC write-method coverage requires the component fuzzer in-memory DB stub.',
				array()
			);
		}

		$failures         = array();
		$global_snapshot  = self::snapshot_globals();
		$content_snapshot = $wpdb->component_fuzz_content_counts();
		$runtime_snapshot = $wpdb->component_fuzz_get_runtime_state();
		$options_snapshot = $wpdb->component_fuzz_get_options();
		$cleanup_state    = array(
			'authenticate'       => null,
			'userHasCap'         => null,
			'insertData'         => null,
			'xmlrpcCall'         => null,
			'preHttp'            => null,
			'contentCounts'      => null,
			'optionsRestored'    => null,
			'runtimeRestored'    => null,
			'globalsRestored'    => null,
		);
		$marker           = 'xmlrpc-write-' . $ctx->identifier( 5, 12 );
		$user_id          = 890000 + $ctx->int( 1, 5000 );
		$username         = 'xmlrpc_writer_' . $ctx->identifier( 4, 9 );
		$password         = 'xmlrpc-write-pass-' . $ctx->identifier( 6, 12 );
		$base_id          = 900000 + ( $ctx->int( 1, 8000 ) * 10 );
		$edit_id          = $base_id + 1;
		$delete_id        = $base_id + 2;
		$caller_id        = $base_id + 5000;
		$missing_id       = $base_id + 9000;
		$new_title        = 'XML-RPC new ' . self::safe_text( $ctx->fork( 'new-title' ), 18 );
		$new_content      = 'XML-RPC new content ' . self::safe_text( $ctx->fork( 'new-content' ), 34 );
		$new_excerpt      = 'New excerpt ' . self::safe_text( $ctx->fork( 'new-excerpt' ), 14 );
		$edit_title       = 'XML-RPC edited ' . self::safe_text( $ctx->fork( 'edit-title' ), 18 );
		$edit_content     = 'XML-RPC edited content ' . self::safe_text( $ctx->fork( 'edit-content' ), 34 );
		$stale_title      = 'XML-RPC stale ' . self::safe_text( $ctx->fork( 'stale-title' ), 18 );
		$new_date         = new \IXR_Date( '20260612T10:11:12Z' );
		$stale_date       = new \IXR_Date( strtotime( '2026-06-01 00:00:00 UTC' ) );
		$fresh_date       = new \IXR_Date( strtotime( '2027-01-01 00:00:00 UTC' ) );
		$expected_date    = \iso8601_to_datetime( $new_date->getIso() );
		$expected_gmt     = \iso8601_to_datetime( $new_date->getIso(), 'gmt' );
		$xmlrpc_calls     = array();
		$insert_events    = array();
		$http_count       = 0;
		$grant_caps       = true;
		$created_id       = null;
		$thrown           = null;
		$auth_before      = null;
		$auth_after       = null;
		$denial_before    = null;
		$denial_after     = null;
		$auth_failure     = null;
		$denied_new       = null;
		$denied_edit      = null;
		$denied_delete    = null;
		$denied_edit_post = null;
		$denied_delete_post = null;
		$new_result       = null;
		$new_post         = null;
		$missing_edit     = null;
		$stale_edit       = null;
		$after_stale      = null;
		$valid_edit       = null;
		$edited_post      = null;
		$type_change      = null;
		$after_type_change = null;
		$missing_delete   = null;
		$valid_delete     = null;
		$after_delete     = null;

		$authenticate_filter = static function ( $user, string $login, string $pass ) use ( $username, $password, $user_id ) {
			if ( $username === $login && $password === $pass ) {
				return new \WP_User( $user_id );
			}

			return new \WP_Error( 'component_fuzz_xmlrpc_write_auth', 'Synthetic XML-RPC write authentication failure.' );
		};
		$cap_filter          = static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( &$grant_caps, $user_id ): array {
			if ( (int) $user->ID !== $user_id ) {
				return $allcaps;
			}

			foreach (
				array(
					'delete_others_posts',
					'delete_post',
					'delete_posts',
					'delete_published_posts',
					'edit_others_posts',
					'edit_post',
					'edit_posts',
					'edit_published_posts',
					'publish_posts',
					'read',
					'read_post',
				) as $primitive
			) {
				$allcaps[ $primitive ] = $grant_caps;
			}
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = $grant_caps && 'do_not_allow' !== $cap;
			}

			return $allcaps;
		};
		$insert_data_filter  = static function ( array $post_data, array $content_struct ) use ( &$insert_events ): array {
			$insert_events[] = array(
				'ID'            => (int) ( $post_data['ID'] ?? 0 ),
				'postContent'   => (string) ( $post_data['post_content'] ?? '' ),
				'postStatus'    => (string) ( $post_data['post_status'] ?? '' ),
				'postTitle'     => (string) ( $post_data['post_title'] ?? '' ),
				'contentKeys'   => array_keys( $content_struct ),
				'hasEditDate'   => array_key_exists( 'edit_date', $post_data ),
			);
			return $post_data;
		};
		$xmlrpc_call_action  = static function ( string $method ) use ( &$xmlrpc_calls ): void {
			$xmlrpc_calls[] = $method;
		};
		$http_filter         = static function () use ( &$http_count ) {
			++$http_count;
			return new \WP_Error( 'component_fuzz_xmlrpc_write_http', 'XML-RPC write fuzz cases must not reach HTTP transport.' );
		};

		try {
			\add_filter( 'authenticate', $authenticate_filter, 1, 3 );
			\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
			\add_filter( 'xmlrpc_wp_insert_post_data', $insert_data_filter, 10, 2 );
			\add_action( 'xmlrpc_call', $xmlrpc_call_action, 10, 1 );
			\add_filter( 'pre_http_request', $http_filter, 10, 3 );

			$GLOBALS['wp_rewrite'] = new class() {
				public $feeds = array( 'feed', 'rdf', 'rss', 'rss2', 'atom' );
				public $index = 'index.php';
				public $permalink_structure = '';
				public $use_verbose_page_rules = false;

				public function using_permalinks(): bool {
					return false;
				}

				public function using_index_permalinks(): bool {
					return false;
				}
			};

			if ( ! \get_post_type_object( 'post' ) ) {
				\register_post_type(
					'post',
					array(
						'_builtin'        => true,
						'public'          => true,
						'show_ui'         => true,
						'capability_type' => 'post',
						'map_meta_cap'    => true,
						'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
					)
				);
			}
			if ( ! \get_post_type_object( 'page' ) ) {
				\register_post_type(
					'page',
					array(
						'_builtin'        => true,
						'public'          => true,
						'show_ui'         => true,
						'capability_type' => 'page',
						'hierarchical'    => true,
						'map_meta_cap'    => true,
						'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
					)
				);
			}

			self::seed_xmlrpc_user( $user_id, $username, $password );
			self::seed_xmlrpc_post_row(
				array(
					'ID'                => $edit_id,
					'post_author'       => $user_id,
					'post_date'         => '2026-06-08 09:10:11',
					'post_date_gmt'     => '2026-06-08 07:10:11',
					'post_content'      => 'Original editable XML-RPC content ' . $marker,
					'post_title'        => 'Original editable XML-RPC title ' . $marker,
					'post_excerpt'      => 'Original editable excerpt',
					'post_status'       => 'publish',
					'comment_status'    => 'open',
					'ping_status'       => 'open',
					'post_password'     => '',
					'post_name'         => 'xmlrpc-edit-' . $ctx->identifier( 4, 8 ),
					'post_modified'     => '2026-06-10 12:00:00',
					'post_modified_gmt' => '2026-06-10 12:00:00',
					'post_parent'       => 0,
					'guid'              => 'https://example.test/?p=' . $edit_id,
					'menu_order'        => 0,
					'post_type'         => 'post',
					'post_mime_type'    => '',
					'comment_count'     => 0,
				)
			);
			self::seed_xmlrpc_post_row(
				array(
					'ID'                => $delete_id,
					'post_author'       => $user_id,
					'post_date'         => '2026-06-09 09:10:11',
					'post_date_gmt'     => '2026-06-09 07:10:11',
					'post_content'      => 'Delete target XML-RPC content ' . $marker,
					'post_title'        => 'Delete target XML-RPC title ' . $marker,
					'post_excerpt'      => 'Delete target excerpt',
					'post_status'       => 'publish',
					'comment_status'    => 'open',
					'ping_status'       => 'closed',
					'post_password'     => '',
					'post_name'         => 'xmlrpc-delete-' . $ctx->identifier( 4, 8 ),
					'post_modified'     => '2026-06-09 12:00:00',
					'post_modified_gmt' => '2026-06-09 12:00:00',
					'post_parent'       => 0,
					'guid'              => 'https://example.test/?p=' . $delete_id,
					'menu_order'        => 0,
					'post_type'         => 'post',
					'post_mime_type'    => '',
					'comment_count'     => 0,
				)
			);
			\wp_cache_set_posts_last_changed();

			$auth_server  = new \wp_xmlrpc_server();
			$auth_before  = $wpdb->component_fuzz_content_counts();
			$auth_failure = $auth_server->wp_newPost(
				array(
					1,
					$username,
					$password . '-wrong',
					array(
						'post_title'   => 'Should not insert ' . $marker,
						'post_content' => 'auth failure',
					),
				)
			);
			$auth_after   = $wpdb->component_fuzz_content_counts();

			$grant_caps       = false;
			$denial_server    = new \wp_xmlrpc_server();
			$denial_before    = $wpdb->component_fuzz_content_counts();
			$denied_new       = $denial_server->wp_newPost(
				array(
					1,
					$username,
					$password,
					array(
						'post_title'   => 'Denied new ' . $marker,
						'post_content' => 'denied new',
					),
				)
			);
			$denied_edit      = $denial_server->wp_editPost(
				array(
					1,
					$username,
					$password,
					$edit_id,
					array( 'post_title' => 'Denied edit ' . $marker ),
				)
			);
			$denied_delete    = $denial_server->wp_deletePost( array( 1, $username, $password, $delete_id ) );
			$denial_after     = $wpdb->component_fuzz_content_counts();
			$denied_edit_post = \get_post( $edit_id );
			$denied_delete_post = \get_post( $delete_id );
			$grant_caps       = true;

			$server     = new \wp_xmlrpc_server();
			$new_result = $server->wp_newPost(
				array(
					1,
					$username,
					$password,
					array(
						'ID'             => $caller_id,
						'post_title'     => $new_title,
						'post_content'   => $new_content,
						'post_excerpt'   => $new_excerpt,
						'post_status'    => 'component-fuzz-unknown-status',
						'post_date_gmt'  => $new_date,
						'comment_status' => 'open',
						'ping_status'    => 'closed',
					),
				)
			);
			$created_id = is_string( $new_result ) && ctype_digit( $new_result ) ? (int) $new_result : null;
			$new_post   = null !== $created_id ? \get_post( $created_id ) : null;

			$missing_edit = $server->wp_editPost(
				array(
					1,
					$username,
					$password,
					$missing_id,
					array( 'post_title' => 'Missing edit ' . $marker ),
				)
			);
			$stale_edit   = $server->wp_editPost(
				array(
					1,
					$username,
					$password,
					$edit_id,
					array(
						'post_title'            => $stale_title,
						'if_not_modified_since' => $stale_date,
					),
				)
			);
			$after_stale  = \get_post( $edit_id );

			$valid_edit = $server->wp_editPost(
				array(
					1,
					$username,
					$password,
					$edit_id,
					array(
						'post_title'            => $edit_title,
						'post_content'          => $edit_content,
						'post_status'           => 'publish',
						'if_not_modified_since' => $fresh_date,
					),
				)
			);
			$edited_post = \get_post( $edit_id );

			$type_change = $server->wp_editPost(
				array(
					1,
					$username,
					$password,
					$edit_id,
					array( 'post_type' => 'page' ),
				)
			);
			$after_type_change = \get_post( $edit_id );

			$missing_delete = $server->wp_deletePost( array( 1, $username, $password, $missing_id ) );
			$valid_delete   = $server->wp_deletePost( array( 1, $username, $password, $delete_id ) );
			$after_delete   = \get_post( $delete_id );
		} catch ( \Throwable $e ) {
			$thrown = self::describe_throwable( $e );
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			\remove_action( 'xmlrpc_call', $xmlrpc_call_action, 10 );
			\remove_filter( 'xmlrpc_wp_insert_post_data', $insert_data_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'authenticate', $authenticate_filter, 1 );

			foreach ( range( $base_id + 1, $base_id + 10 ) as $post_id ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );
				$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
				\wp_cache_delete( $post_id, 'posts' );
				\wp_cache_delete( $post_id, 'post_meta' );
			}
			foreach ( array( $caller_id, $missing_id ) as $post_id ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );
				$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
				\wp_cache_delete( $post_id, 'posts' );
				\wp_cache_delete( $post_id, 'post_meta' );
			}
			$wpdb->delete( $wpdb->users, array( 'ID' => $user_id ) );
			\wp_cache_delete( $user_id, 'users' );
			\wp_cache_delete( $username, 'userlogins' );
			\wp_cache_set_posts_last_changed();
			$wpdb->component_fuzz_reset_options( $options_snapshot );
			$cleanup_state = array(
				'authenticate'    => \has_filter( 'authenticate', $authenticate_filter ),
				'userHasCap'      => \has_filter( 'user_has_cap', $cap_filter ),
				'insertData'      => \has_filter( 'xmlrpc_wp_insert_post_data', $insert_data_filter ),
				'xmlrpcCall'      => \has_filter( 'xmlrpc_call', $xmlrpc_call_action ),
				'preHttp'         => \has_filter( 'pre_http_request', $http_filter ),
				'contentCounts'   => $wpdb->component_fuzz_content_counts(),
				'optionsRestored' => $options_snapshot === $wpdb->component_fuzz_get_options(),
				'runtimeRestored' => null,
				'globalsRestored' => null,
			);
			$wpdb->component_fuzz_restore_runtime_state( $runtime_snapshot );
			$cleanup_state['runtimeRestored'] = $runtime_snapshot === $wpdb->component_fuzz_get_runtime_state();
			self::restore_globals( $global_snapshot );
			$cleanup_state['globalsRestored'] = self::globals_match( $global_snapshot );
		}

		if ( null !== $thrown ) {
			$failures[] = array(
				'message'          => 'authenticated XML-RPC write methods do not throw',
				'throwableClass'   => $thrown['class'] ?? null,
				'throwableMessage' => $thrown['message'] ?? null,
				'throwableFile'    => $thrown['file'] ?? null,
				'throwableLine'    => $thrown['line'] ?? null,
			);
		}

		self::collect_failure(
			$failures,
			403 === self::ixr_error_code( $auth_failure )
				&& $auth_before === $auth_after,
			'wp.newPost authentication failures return 403 and do not mutate in-memory content',
			array(
				'authFailure' => self::describe_value( $auth_failure ),
				'before'      => $auth_before,
				'after'       => $auth_after,
			)
		);

		self::collect_failure(
			$failures,
			401 === self::ixr_error_code( $denied_new )
				&& 401 === self::ixr_error_code( $denied_edit )
				&& 401 === self::ixr_error_code( $denied_delete )
				&& $denial_before === $denial_after
				&& $denied_edit_post instanceof \WP_Post
				&& ! str_contains( $denied_edit_post->post_title, 'Denied edit' )
				&& $denied_delete_post instanceof \WP_Post
				&& 'publish' === $denied_delete_post->post_status,
			'XML-RPC post write methods fail closed on capability denial without creating, editing, or deleting rows',
			array(
				'deniedNew'    => self::describe_value( $denied_new ),
				'deniedEdit'   => self::describe_value( $denied_edit ),
				'deniedDelete' => self::describe_value( $denied_delete ),
				'before'       => $denial_before,
				'after'        => $denial_after,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $new_result )
				&& null !== $created_id
				&& $created_id !== $caller_id
				&& $new_post instanceof \WP_Post
				&& $user_id === (int) $new_post->post_author
				&& $new_title === $new_post->post_title
				&& $new_content === $new_post->post_content
				&& $new_excerpt === $new_post->post_excerpt
				&& 'draft' === $new_post->post_status
				&& 'open' === $new_post->comment_status
				&& 'closed' === $new_post->ping_status
				&& $expected_date === $new_post->post_date
				&& $expected_gmt === $new_post->post_date_gmt,
			'wp.newPost ignores caller IDs, normalizes unknown statuses to draft, converts IXR dates, and persists generated content',
			array(
				'result'       => self::describe_value( $new_result ),
				'createdId'    => $created_id,
				'callerId'     => $caller_id,
				'post'         => self::describe_value( $new_post ),
				'expectedDate' => $expected_date,
				'expectedGmt'  => $expected_gmt,
			)
		);

		$edit_ok = 404 === self::ixr_error_code( $missing_edit )
			&& 409 === self::ixr_error_code( $stale_edit )
			&& $after_stale instanceof \WP_Post
			&& ! str_contains( $after_stale->post_title, $stale_title )
			&& true === $valid_edit
			&& $edited_post instanceof \WP_Post
			&& $edit_title === $edited_post->post_title
			&& $edit_content === $edited_post->post_content
			&& 'Original editable excerpt' === $edited_post->post_excerpt
			&& 'publish' === $edited_post->post_status
			&& 401 === self::ixr_error_code( $type_change )
			&& $after_type_change instanceof \WP_Post
			&& 'post' === $after_type_change->post_type;
		if ( ! $edit_ok ) {
			$failures[] = array(
				'message'             => 'wp.editPost distinguishes missing, stale, valid, and post-type-change branches while preserving omitted fields',
				'missingCode'         => self::ixr_error_code( $missing_edit ),
				'staleCode'           => self::ixr_error_code( $stale_edit ),
				'afterStaleTitle'     => $after_stale instanceof \WP_Post ? $after_stale->post_title : null,
				'validEdit'           => $valid_edit,
				'editedTitle'         => $edited_post instanceof \WP_Post ? $edited_post->post_title : null,
				'editedContent'       => $edited_post instanceof \WP_Post ? $edited_post->post_content : null,
				'editedExcerpt'       => $edited_post instanceof \WP_Post ? $edited_post->post_excerpt : null,
				'editedStatus'        => $edited_post instanceof \WP_Post ? $edited_post->post_status : null,
				'typeChangeCode'      => self::ixr_error_code( $type_change ),
				'afterTypeChangeType' => $after_type_change instanceof \WP_Post ? $after_type_change->post_type : null,
			);
		}

		self::collect_failure(
			$failures,
			404 === self::ixr_error_code( $missing_delete )
				&& true === $valid_delete
				&& (
					null === $after_delete
					|| (
						$after_delete instanceof \WP_Post
						&& 'trash' === $after_delete->post_status
					)
				),
			'wp.deletePost reports missing rows, returns true for valid deletes, and either trashes or removes the row according to Core trash settings',
			array(
				'missing'     => self::describe_value( $missing_delete ),
				'validDelete' => self::describe_value( $valid_delete ),
				'afterDelete' => self::describe_value( $after_delete ),
			)
		);

		self::collect_failure(
			$failures,
			in_array( 'wp.newPost', $xmlrpc_calls, true )
				&& in_array( 'wp.editPost', $xmlrpc_calls, true )
				&& in_array( 'wp.deletePost', $xmlrpc_calls, true )
				&& count( $insert_events ) >= 2
				&& in_array( 'draft', array_column( $insert_events, 'postStatus' ), true )
				&& in_array( 'publish', array_column( $insert_events, 'postStatus' ), true )
				&& 0 === $http_count,
			'XML-RPC write methods fire call/insert filters for success paths and do not attempt HTTP',
			array(
				'xmlrpcCalls'  => self::describe_value( $xmlrpc_calls ),
				'insertEvents' => self::describe_value( $insert_events ),
				'httpCount'    => $http_count,
			)
		);

		self::collect_failure(
			$failures,
			false === $cleanup_state['authenticate']
				&& false === $cleanup_state['userHasCap']
				&& false === $cleanup_state['insertData']
				&& false === $cleanup_state['xmlrpcCall']
				&& false === $cleanup_state['preHttp']
				&& $content_snapshot === $cleanup_state['contentCounts']
				&& true === $cleanup_state['optionsRestored']
				&& true === $cleanup_state['runtimeRestored']
				&& true === $cleanup_state['globalsRestored'],
			'authenticated XML-RPC write-method checks remove hooks, rows, options, runtime, and globals',
			array(
				'cleanup'      => $cleanup_state,
				'beforeCounts' => $content_snapshot,
			)
		);

		return self::row(
			$ctx,
			'xmlrpc.authenticated-write-post-methods',
			array() === $failures,
			array(
				'failures'    => $failures,
				'xmlrpcCalls' => $xmlrpc_calls,
				'createdId'   => $created_id,
			)
		);
	}

	private static function seed_xmlrpc_user( int $user_id, string $username, string $password ): void {
		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $username,
				'user_pass'       => $password,
				'user_nicename'   => $username,
				'user_email'      => $username . '@example.test',
				'user_registered' => '2026-06-01 00:00:00',
				'display_name'    => 'XML-RPC User ' . $user_id,
			)
		);
		\wp_cache_delete( $user_id, 'users' );
		\wp_cache_delete( $username, 'userlogins' );
	}

	private static function seed_xmlrpc_post_row( array $row ): void {
		\wp_cache_delete( (int) $row['ID'], 'posts' );
		$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->posts, $row );
	}

	private static function seed_xmlrpc_post_meta( int $post_id, string $key, $value ): void {
		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $key,
				'meta_value' => $value,
			)
		);
		\wp_cache_delete( $post_id, 'post_meta' );
	}

	private static function ixr_date_iso( $value ): ?string {
		if ( ! $value instanceof \IXR_Date ) {
			return null;
		}

		return $value->getIso();
	}

	private static function xmlrpc_post_ids( $posts ): array {
		if ( ! is_array( $posts ) ) {
			return array();
		}

		$ids = array();
		foreach ( $posts as $post ) {
			if ( is_array( $post ) && isset( $post['post_id'] ) ) {
				$ids[] = (string) $post['post_id'];
			}
		}
		return $ids;
	}

	private static function xmlrpc_media_ids( $media_items ): array {
		if ( ! is_array( $media_items ) ) {
			return array();
		}

		$ids = array();
		foreach ( $media_items as $media_item ) {
			if ( is_array( $media_item ) && isset( $media_item['attachment_id'] ) ) {
				$ids[] = (string) $media_item['attachment_id'];
			}
		}
		return $ids;
	}

	private static function seed_pingback_post( int $post_id, string $title, string $slug, string $ping_status ): void {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) || ! method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			return;
		}

		\wp_cache_delete( $post_id, 'posts' );
		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->posts,
			array(
				'ID'                  => $post_id,
				'post_author'         => 1,
				'post_date'           => '2026-06-01 00:00:00',
				'post_date_gmt'       => '2026-06-01 00:00:00',
				'post_content'        => 'Synthetic XML-RPC pingback target.',
				'post_title'          => $title,
				'post_excerpt'        => '',
				'post_status'         => 'publish',
				'comment_status'      => 'open',
				'ping_status'         => $ping_status,
				'post_password'       => '',
				'post_name'           => $slug,
				'to_ping'             => '',
				'pinged'              => '',
				'post_modified'       => '2026-06-01 00:00:00',
				'post_modified_gmt'   => '2026-06-01 00:00:00',
				'post_content_filtered' => '',
				'post_parent'         => 0,
				'guid'                => 'https://example.test/?p=' . $post_id,
				'menu_order'          => 0,
				'post_type'           => 'post',
				'post_mime_type'      => '',
				'comment_count'       => 0,
			)
		);
	}

	private static function seed_pingback_comment( int $comment_id, int $post_id, string $author_url, string $type ): void {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) || ! method_exists( $GLOBALS['wpdb'], 'insert' ) ) {
			return;
		}

		$second = $comment_id % 60;
		$ip_octet = 1 + ( $comment_id % 250 );
		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->comments,
			array(
				'comment_ID'           => $comment_id,
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Pingback Source ' . $comment_id,
				'comment_author_email' => '',
				'comment_author_url'   => $author_url,
				'comment_author_IP'    => '192.0.2.' . $ip_octet,
				'comment_date'         => sprintf( '2026-06-01 00:00:%02d', $second ),
				'comment_date_gmt'     => sprintf( '2026-06-01 00:00:%02d', $second ),
				'comment_content'      => 'Synthetic pingback/comment ' . $comment_id,
				'comment_approved'     => '1',
				'comment_agent'        => 'component-fuzz/xmlrpc',
				'comment_type'         => $type,
				'comment_parent'       => 0,
				'user_id'              => 0,
			)
		);
	}

	private static function ixr_error_code( $value ): ?int {
		if ( $value instanceof \IXR_Error ) {
			return (int) $value->code;
		}

		return null;
	}

	private static function check_xmlrpc_post_data_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures                 = array();
		$previous_default_title   = $GLOBALS['post_default_title'] ?? null;
		$previous_default_cat     = $GLOBALS['post_default_category'] ?? null;
		$had_default_title        = array_key_exists( 'post_default_title', $GLOBALS );
		$had_default_cat          = array_key_exists( 'post_default_category', $GLOBALS );
		$title                    = 'Title ' . self::safe_text( $ctx->fork( 'title' ), 24 );
		$body                     = 'Body ' . self::safe_text( $ctx->fork( 'body' ), 48 );
		$categories               = array(
			'alpha-' . $ctx->identifier( 3, 8 ),
			'beta-' . $ctx->identifier( 3, 8 ),
			'gamma-' . $ctx->identifier( 3, 8 ),
		);
		$default_title            = 'Default title ' . self::safe_text( $ctx->fork( 'default-title' ), 18 );
		$default_category         = 'default-' . $ctx->identifier( 4, 10 );
		$content                  = '<title>' . $title . "</title>\n"
			. '<category>,' . implode( ',', $categories ) . ",</category>\n"
			. '<content>' . $body . '</content>';
		$fallback_content         = '<content>' . $body . '</content>';
		$GLOBALS['post_default_title']    = $default_title;
		$GLOBALS['post_default_category'] = $default_category;

		try {
			$extracted_title       = \xmlrpc_getposttitle( $content );
			$extracted_categories  = \xmlrpc_getpostcategory( $content );
			$removed              = \xmlrpc_removepostdata( $content );
			$fallback_title       = \xmlrpc_getposttitle( $fallback_content );
			$fallback_category    = \xmlrpc_getpostcategory( $fallback_content );
		} finally {
			if ( $had_default_title ) {
				$GLOBALS['post_default_title'] = $previous_default_title;
			} else {
				unset( $GLOBALS['post_default_title'] );
			}

			if ( $had_default_cat ) {
				$GLOBALS['post_default_category'] = $previous_default_cat;
			} else {
				unset( $GLOBALS['post_default_category'] );
			}
		}

		self::collect_failure(
			$failures,
			$title === $extracted_title
				&& $categories === $extracted_categories
				&& $default_title === $fallback_title
				&& $default_category === $fallback_category,
			'legacy XML-RPC post title/category helpers extract generated tags and fall back to globals',
			array(
				'title'              => self::describe_string( $title ),
				'extractedTitle'     => self::describe_value( $extracted_title ),
				'categories'         => $categories,
				'extractedCategories' => self::describe_value( $extracted_categories ),
				'fallbackTitle'      => self::describe_value( $fallback_title ),
				'fallbackCategory'   => self::describe_value( $fallback_category ),
			)
		);
		self::collect_failure(
			$failures,
			false === strpos( $removed, '<title>' )
				&& false === strpos( $removed, '<category>' )
				&& str_contains( $removed, '<content>' . $body . '</content>' ),
			'xmlrpc_removepostdata removes title/category elements while preserving remaining XML bytes',
			array(
				'removed' => self::describe_string( $removed ),
				'body'    => self::describe_string( $body ),
			)
		);

		return self::row(
			$ctx,
			'xmlrpc.legacy-post-data-helpers.extract-and-remove-bounded-tags',
			array() === $failures,
			array(
				'failures'   => $failures,
				'categories' => $categories,
			)
		);
	}

	private static function check_http_ixr_client_transport( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$captured       = array();
		$marker         = 'xmlrpc-http-' . $ctx->identifier( 6, 12 );
		$client_header  = 'client-' . $ctx->identifier( 5, 10 );
		$filtered_header = 'filtered-' . $ctx->identifier( 5, 10 );
		$url            = 'https://api.example.test:8443/xmlrpc.php?rsd=' . rawurlencode( $marker );
		$payload_text   = 'payload <tag>& "' . self::safe_text( $ctx->fork( 'payload' ), 18 );
		$payload_number = $ctx->int( -1000, 1000 );
		$payload_bytes  = "bytes:\x00" . self::safe_text( $ctx->fork( 'bytes' ), 12 );
		$expected_args  = self::normalize_ixr_value(
			array(
				$payload_text,
				$payload_number,
				new \IXR_Base64( $payload_bytes ),
			)
		);
		$header_filter  = static function ( array $headers ) use ( $filtered_header ): array {
			$headers['X-Filtered-Header'] = $filtered_header;
			return $headers;
		};
		$http_filter    = static function ( $preempt, array $parsed_args, string $request_url ) use ( &$captured, $marker ) {
			$message = new \IXR_Message( (string) ( $parsed_args['body'] ?? '' ) );
			$parsed  = $message->parse();
			$method  = is_string( $message->methodName ) ? $message->methodName : '';

			$captured[] = array(
				'preempt' => $preempt,
				'url'     => $request_url,
				'headers' => $parsed_args['headers'] ?? array(),
				'timeout' => $parsed_args['timeout'] ?? null,
				'body'    => self::describe_string( (string) ( $parsed_args['body'] ?? '' ) ),
				'request' => array(
					'parsed' => $parsed,
					'method' => $method,
					'params' => self::normalize_ixr_value( $message->params ),
				),
			);

			if ( 'component.status' === $method ) {
				return self::http_response( 503, 'Synthetic unavailable', '<not-used />' );
			}

			if ( 'component.fault' === $method ) {
				$error = new \IXR_Error( 499, 'Synthetic fault ' . $marker );
				return self::http_response( 200, 'OK', $error->getXml() );
			}

			if ( 'component.transport' === $method ) {
				return new \WP_Error( 'component_fuzz_xmlrpc_transport', 'Synthetic transport failure.' );
			}

			$response = new \IXR_Value(
				array(
					'method'     => $method,
					'paramCount' => count( $message->params ),
					'marker'     => $marker,
				)
			);

			return self::http_response(
				200,
				'OK',
				'<?xml version="1.0"?><methodResponse><params><param><value>' . $response->getXml() . '</value></param></params></methodResponse>'
			);
		};

		\add_filter( 'wp_http_ixr_client_headers', $header_filter, 10, 1 );
		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		try {
			$client                                = new \WP_HTTP_IXR_Client( $url, false, false, 7 );
			$client->headers['X-Client-Header']   = $client_header;
			$success                              = $client->query( 'component.ok', $payload_text, $payload_number, new \IXR_Base64( $payload_bytes ) );
			$success_response                     = $success ? $client->getResponse() : null;
			$status_client                        = new \WP_HTTP_IXR_Client( $url, false, false, 4 );
			$status_error                         = $status_client->query( 'component.status', $marker );
			$fault_client                         = new \WP_HTTP_IXR_Client( $url, false, false, 4 );
			$fault_error                          = $fault_client->query( 'component.fault', $marker );
			$transport_client                     = new \WP_HTTP_IXR_Client( $url, false, false, 4 );
			$transport_error                      = $transport_client->query( 'component.transport', $marker );
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			\remove_filter( 'wp_http_ixr_client_headers', $header_filter, 10 );
		}

		$first_capture = $captured[0] ?? array();
		$first_headers = is_array( $first_capture['headers'] ?? null ) ? $first_capture['headers'] : array();
		self::collect_failure(
			$failures,
			true === $success
				&& array(
					'method'     => 'component.ok',
					'paramCount' => 3,
					'marker'     => $marker,
				) === $success_response
				&& 4 === count( $captured )
				&& false === ( $first_capture['preempt'] ?? null )
				&& $url === ( $first_capture['url'] ?? null )
				&& 7 === ( $first_capture['timeout'] ?? null )
				&& 'text/xml' === ( $first_headers['Content-Type'] ?? null )
				&& $client_header === ( $first_headers['X-Client-Header'] ?? null )
				&& $filtered_header === ( $first_headers['X-Filtered-Header'] ?? null )
				&& 'component.ok' === ( $first_capture['request']['method'] ?? null )
				&& self::values_equivalent( $expected_args, $first_capture['request']['params'] ?? null ),
			'WP_HTTP_IXR_Client composes filtered HTTP requests and parses successful method responses',
			array(
				'success'          => $success,
				'successResponse'  => self::describe_value( $success_response ),
				'firstCapture'     => self::describe_value( $first_capture ),
				'expectedParams'   => self::describe_value( $expected_args ),
				'firstDifference'  => self::first_difference( $expected_args, $first_capture['request']['params'] ?? null ),
			)
		);
		self::collect_failure(
			$failures,
			false === $status_error
				&& $status_client->isError()
				&& -32301 === $status_client->getErrorCode()
				&& false === $fault_error
				&& $fault_client->isError()
				&& 499 === $fault_client->getErrorCode()
				&& str_contains( $fault_client->getErrorMessage(), 'Synthetic fault' )
				&& false === $transport_error
				&& $transport_client->isError()
				&& -32300 === $transport_client->getErrorCode(),
			'WP_HTTP_IXR_Client maps HTTP status, XML-RPC fault, and transport failures to IXR_Error codes',
			array(
				'statusError'    => self::describe_value( $status_client->error ),
				'faultError'     => self::describe_value( $fault_client->error ),
				'transportError' => self::describe_value( $transport_client->error ),
				'captured'       => self::describe_value( $captured ),
			)
		);
		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_http_request', $http_filter )
				&& false === \has_filter( 'wp_http_ixr_client_headers', $header_filter ),
			'XML-RPC HTTP client filters are removed after synthetic transport checks',
			array(
				'preHttp' => \has_filter( 'pre_http_request', $http_filter ),
				'headers' => \has_filter( 'wp_http_ixr_client_headers', $header_filter ),
			)
		);

		return self::row(
			$ctx,
			'xmlrpc.wp-http-ixr-client.short-circuited-transport-contracts',
			array() === $failures,
			array(
				'failures' => $failures,
				'captured' => self::describe_value( $captured ),
			)
		);
	}

	private static function http_response( int $code, string $message, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => $message,
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function request_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'  => 'hello-empty',
				'method' => 'demo.sayHello',
				'args'   => array(),
			),
			array(
				'label'  => 'add-two-numbers',
				'method' => 'demo.addTwoNumbers',
				'args'   => array( $ctx->int( -1000, 1000 ), $ctx->int( -1000, 1000 ) ),
			),
			array(
				'label'  => 'nested-struct',
				'method' => 'component.' . $ctx->identifier( 4, 12 ),
				'args'   => array(
					array(
						'title'    => 'safe<' . self::safe_text( $ctx->fork( 'title' ), 10 ) . '>&',
						'count'    => $ctx->int( 0, 1000 ),
						'enabled'  => $ctx->bool(),
						'nested'   => array(
							'alpha',
							'beta&gamma',
							array( 'key' => 'value' ),
						),
						'uploaded' => new \IXR_Base64( 'payload:' . self::safe_text( $ctx->fork( 'payload' ), 12 ) ),
					),
				),
			),
			array(
				'label'  => 'date-and-float',
				'method' => 'component.dateFloat',
				'args'   => array(
					new \IXR_Date( 1700000000 + $ctx->int( 0, 100000 ) ),
					$ctx->int( -10000, 10000 ) / 100,
					false,
				),
			),
		);

		for ( $i = 0; $i < 4; ++$i ) {
			$case_ctx = $ctx->fork( 'generated-' . $i );
			$cases[]  = array(
				'label'  => 'generated-' . $i,
				'method' => 'component.generated' . $i . '.' . $case_ctx->identifier( 3, 10 ),
				'args'   => self::safe_arg_list( $case_ctx ),
			);
		}

		return $cases;
	}

	private static function safe_arg_list( \ComponentFuzz\FuzzContext $ctx ): array {
		$args  = array();
		$count = $ctx->int( 1, 4 );
		for ( $i = 0; $i < $count; ++$i ) {
			$args[] = self::safe_value( $ctx->fork( 'arg-' . $i ), 0 );
		}
		return $args;
	}

	private static function safe_value( \ComponentFuzz\FuzzContext $ctx, int $depth ) {
		if ( $depth >= 3 ) {
			return $ctx->choice( array( true, false, $ctx->int( -1000, 1000 ), self::safe_text( $ctx, 20 ) ) );
		}

		$type = $ctx->choice( array( 'bool', 'int', 'float', 'string', 'array', 'struct', 'base64', 'date' ) );
		if ( 'bool' === $type ) {
			return $ctx->bool();
		}
		if ( 'int' === $type ) {
			return $ctx->int( -100000, 100000 );
		}
		if ( 'float' === $type ) {
			return $ctx->int( -100000, 100000 ) / max( 1, $ctx->int( 1, 1000 ) );
		}
		if ( 'string' === $type ) {
			return self::safe_text( $ctx, $ctx->int( 0, 48 ) );
		}
		if ( 'base64' === $type ) {
			return new \IXR_Base64( 'bytes:' . self::safe_text( $ctx, 24 ) );
		}
		if ( 'date' === $type ) {
			return new \IXR_Date( 1700000000 + $ctx->int( 0, 1000000 ) );
		}

		$count = $ctx->int( 0, 4 );
		$out   = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$key = 'struct' === $type ? 'k' . $i . '_' . $ctx->identifier( 2, 8 ) : $i;
			$out[ $key ] = self::safe_value( $ctx->fork( (string) $key ), $depth + 1 );
		}
		return $out;
	}

	private static function safe_text( \ComponentFuzz\FuzzContext $ctx, int $max_bytes ): string {
		$atoms = array(
			'a',
			'Z',
			'9',
			'_',
			'-',
			'.',
			':',
			'/',
			'?x=1&y=2',
			'<tag attr="value">',
			'&amp;',
			'quote"',
			'apostrophe\'',
			"\xC3\xA9",
			"\xE2\x98\x83",
		);

		$out = '';
		while ( strlen( $out ) < $max_bytes ) {
			$remaining = $max_bytes - strlen( $out );
			$atom      = $ctx->choice( $atoms );
			if ( strlen( $atom ) > $remaining ) {
				$filler = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
				$atom   = $filler[ $ctx->int( 0, strlen( $filler ) - 1 ) ];
			}
			$out .= $atom;
		}

		return trim( $out );
	}

	private static function normalize_ixr_value( $value ) {
		if ( $value instanceof \IXR_Base64 ) {
			return $value->data;
		}
		if ( $value instanceof \IXR_Date ) {
			return array(
				'type' => 'date',
				'iso'  => $value->getIso(),
			);
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::normalize_ixr_value( $item );
			}
			return $out;
		}

		return $value;
	}

	private static function values_equivalent( $expected, $actual ): bool {
		if ( is_float( $expected ) || is_float( $actual ) ) {
			return is_numeric( $expected )
				&& is_numeric( $actual )
				&& abs( (float) $expected - (float) $actual ) <= 1e-9 * max( 1, abs( (float) $expected ) );
		}

		if ( is_array( $expected ) || is_array( $actual ) ) {
			if ( ! is_array( $expected ) || ! is_array( $actual ) || array_keys( $expected ) !== array_keys( $actual ) ) {
				return false;
			}

			foreach ( $expected as $key => $value ) {
				if ( ! self::values_equivalent( $value, $actual[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}

		return $expected === $actual;
	}

	private static function first_difference( $expected, $actual, string $path = '$' ) {
		if ( self::values_equivalent( $expected, $actual ) ) {
			return null;
		}

		if ( is_array( $expected ) && is_array( $actual ) ) {
			if ( array_keys( $expected ) !== array_keys( $actual ) ) {
				return array(
					'path'         => $path,
					'expectedKeys' => array_keys( $expected ),
					'actualKeys'   => array_keys( $actual ),
				);
			}

			foreach ( $expected as $key => $value ) {
				$child = self::first_difference( $value, $actual[ $key ], $path . '[' . var_export( $key, true ) . ']' );
				if ( null !== $child ) {
					return $child;
				}
			}
		}

		if ( is_string( $expected ) || is_string( $actual ) ) {
			return array(
				'path'            => $path,
				'expectedBytes'   => is_string( $expected ) ? strlen( $expected ) : null,
				'expectedSha1'    => is_string( $expected ) ? sha1( $expected ) : null,
				'expectedPreview' => is_string( $expected ) ? self::escape_bytes( $expected ) : null,
				'actualBytes'     => is_string( $actual ) ? strlen( $actual ) : null,
				'actualSha1'      => is_string( $actual ) ? sha1( $actual ) : null,
				'actualPreview'   => is_string( $actual ) ? self::escape_bytes( $actual ) : null,
			);
		}

		return array(
			'path'     => $path,
			'expected' => self::describe_value( $expected ),
			'actual'   => self::describe_value( $actual ),
		);
	}

	private static function collect_failure( array &$failures, bool $condition, string $message, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'details' => self::describe_value( $details ),
		);
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
			);
		}
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return array(
			'ok'        => $ok,
			'status'    => $ok ? 'passed' : 'failed',
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'data'      => self::describe_value( $data ),
		);
	}

	private static function describe_call( array $call ): array {
		if ( $call['threw'] ) {
			return array(
				'threw'     => true,
				'throwable' => $call['throwable'],
			);
		}

		return array(
			'threw' => false,
			'value' => self::describe_value( $call['value'] ),
		);
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
				if ( $i >= 20 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( $value instanceof \IXR_Error ) {
			return array(
				'type'    => 'IXR_Error',
				'code'    => $value->code,
				'message' => self::describe_string( $value->message ),
			);
		}
		if ( $value instanceof \IXR_Date ) {
			return array(
				'type' => 'IXR_Date',
				'iso'  => self::describe_string( $value->getIso() ),
			);
		}
		if ( $value instanceof \IXR_Base64 ) {
			return array(
				'type' => 'IXR_Base64',
				'data' => self::describe_string( $value->data ),
			);
		}
		if ( is_object( $value ) ) {
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

	private static function escape_bytes( string $value, int $limit = self::SAMPLE_BYTES ): string {
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

	private static function reset_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/xmlrpc.php';
		$_SERVER['HTTP_HOST']      = 'example.test';
		$_SERVER['PHP_SELF']       = '/xmlrpc.php';
	}

	private static function snapshot_globals(): array {
		$snapshot = array(
			'_GET'     => $_GET,
			'_POST'    => $_POST,
			'_REQUEST' => $_REQUEST,
			'_COOKIE'  => $_COOKIE,
			'_SERVER'  => $_SERVER,
			'globals'  => array(),
		);

		foreach (
			array(
				'_wp_post_type_features',
				'current_user',
				'post_type_meta_caps',
				'user_email',
				'user_ID',
				'user_identity',
				'user_level',
				'user_login',
				'user_url',
				'userdata',
				'wp',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_post_types',
				'wp_rewrite',
			) as $name
		) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		$_GET     = $snapshot['_GET'];
		$_POST    = $snapshot['_POST'];
		$_REQUEST = $snapshot['_REQUEST'];
		$_COOKIE  = $snapshot['_COOKIE'];
		$_SERVER  = $snapshot['_SERVER'];

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function globals_match( array $snapshot ): bool {
		if (
			$_GET !== $snapshot['_GET']
			|| $_POST !== $snapshot['_POST']
			|| $_REQUEST !== $snapshot['_REQUEST']
			|| $_COOKIE !== $snapshot['_COOKIE']
			|| $_SERVER !== $snapshot['_SERVER']
		) {
			return false;
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( array_key_exists( $name, $GLOBALS ) !== $entry['exists'] ) {
				return false;
			}
			if ( $entry['exists'] && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}

		return true;
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
