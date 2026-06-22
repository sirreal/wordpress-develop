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
			$rows[] = self::check_wp_xmlrpc_server_helpers( $ctx->fork( 'wp-server' ) );
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
				'wp_xmlrpc_server',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'remove_filter',
				'wp_slash',
				'xml_parser_create',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
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
					'Publishing, media upload, taxonomy, comment, and pingback methods require DB/network side effects.',
				),
			)
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
				'current_user',
				'user_ID',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
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
