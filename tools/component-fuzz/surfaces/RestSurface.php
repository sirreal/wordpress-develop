<?php
namespace ComponentFuzz\Surfaces;

final class RestSurface {
	public const NAME = 'rest';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$seed       = self::seed_from_context( $ctx );
		$started_at = microtime( true );
		self::maybe_load_optional_core_support();
		$missing    = self::missing_core_requirements();

		if ( array() !== $missing ) {
			return array(
				'schemaVersion'       => 1,
				'kind'                => 'component-fuzz-surface-result',
				'surface'             => self::NAME,
				'ok'                  => true,
				'status'              => 'skipped',
				'seed'                => $seed,
				'missingRequirements' => $missing,
				'checks'              => array(),
				'skipped'             => array(
					array(
						'name'   => 'bootstrap-requirements',
						'reason' => 'Required WordPress REST API classes/functions are not loaded.',
						'data'   => array( 'missingRequirements' => $missing ),
					),
				),
				'features'            => array( 'guarded-bootstrap' ),
				'durationMs'          => self::duration_ms( $started_at ),
			);
		}

		$rng     = self::rng( $seed );
		$checks  = array();
		$filters = self::install_stateless_filters();

		try {
			$checks[] = self::run_check(
				'method-normalization',
				'WP_REST_Request uppercases methods and is_method() compares case-insensitively to that normalized method.',
				function () use ( &$rng ) {
					return self::check_method_normalization( $rng );
				}
			);
			$checks[] = self::run_check(
				'header-query-body-normalization',
				'Headers canonicalize dash/underscore names, URL-encoded bodies merge without overriding explicit body params, files and rest_route handling remain stable.',
				function () use ( &$rng ) {
					return self::check_header_query_body_normalization( $rng );
				}
			);
			$checks[] = self::run_check(
				'request-param-precedence',
				'Parameter precedence is deterministic across JSON, body, query, URL, and defaults; repeated get_params() calls are stable.',
				function () use ( &$rng ) {
					return self::check_request_param_precedence( $rng );
				}
			);
			$checks[] = self::run_check(
				'json-body-parsing',
				'JSON bodies parse lazily; malformed JSON and invalid UTF-8 are represented as WP_Error values and never throw.',
				function () use ( &$rng ) {
					return self::check_json_body_parsing( $rng );
				}
			);
			$checks[] = self::run_check(
				'schema-sanitize-validate',
				'Schema sanitization is idempotent on valid generated values, sanitized values validate, and enum/pattern/format constraints reject invalid values.',
				function () use ( &$rng ) {
					return self::check_schema_sanitize_validate( $rng );
				}
			);
			$checks[] = self::run_check(
				'route-regex-matching',
				'Local WP_REST_Server route regexes extract exactly named captures; malformed paths and regexes produce represented no-route results.',
				function () use ( &$rng ) {
					return self::check_route_regex_matching( $rng );
				}
			);
			$checks[] = self::run_check(
				'register-rest-route-wrapper',
				'register_rest_route() trims, merges common args into handlers, merges non-overridden handlers, honors override replacement, and restores global REST registration state.',
				function () use ( &$rng ) {
					return self::check_register_rest_route_wrapper( $rng );
				}
			);
			$checks[] = self::run_check(
				'head-get-routing',
				'HEAD falls back to a GET handler only when no HEAD handler is registered; explicit HEAD handlers take precedence when ordered first.',
				function () use ( &$rng ) {
					return self::check_head_get_routing( $rng );
				}
			);
			$checks[] = self::run_check(
				'permission-callback-semantics',
				'Permission callbacks deny only strict false/null or WP_Error; other falsey return values still allow the endpoint callback.',
				function () use ( &$rng ) {
					return self::check_permission_callback_semantics( $rng );
				}
			);
			$checks[] = self::run_check(
				'argument-validation-error-envelope-parity',
				'Route argument sanitization, validation, required-param errors, direct envelopes, and batch child envelopes retain stable REST error structure before permission callbacks run.',
				function () use ( &$rng ) {
					return self::check_argument_validation_error_envelope_parity( $rng );
				}
			);
			$checks[] = self::run_check(
				'batch-v1-execution',
				'REST batch/v1 dispatch validates child requests, honors allow_batch gates, preserves child request data, envelopes responses, and restores temporary filters.',
				function () use ( &$rng ) {
					return self::check_batch_v1_execution( $rng );
				}
			);
			$checks[] = self::run_check(
				'batch-v1-malformed-child-path-parsing',
				'REST batch/v1 child path parsing represents wp_parse_url() failures as aligned error envelopes without dispatching malformed children.',
				function () use ( &$rng ) {
					return self::check_batch_v1_malformed_child_path_parsing( $rng );
				}
			);
			$checks[] = self::run_check(
				'batch-v1-no-route-child-alignment',
				'REST batch/v1 parsed child paths that match no route remain aligned as rest_no_route envelopes, dispatch child filters in normal mode, and abort without child execution in require-all validation.',
				function () use ( &$rng ) {
					return self::check_batch_v1_no_route_child_alignment( $rng );
				}
			);
			$checks[] = self::run_check(
				'batch-v1-pre-dispatch-short-circuit',
				'REST batch/v1 child pre-dispatch filters can short-circuit valid children before permission callbacks, normal validation errors remain enveloped, and require-all validation prevents child execution.',
				function () use ( &$rng ) {
					return self::check_batch_v1_pre_dispatch_short_circuit( $rng );
				}
			);
			$checks[] = self::run_check(
				'response-links-envelope',
				'WP_REST_Response links, headers, CURIE compaction, embedding, envelopes, and response conversion preserve response metadata without leaking filters.',
				function () use ( &$rng ) {
					return self::check_response_links_envelope( $rng );
				}
			);
		} finally {
			self::remove_stateless_filters( $filters );
		}

		$failures = array();
		foreach ( $checks as $check ) {
			if ( empty( $check['ok'] ) ) {
				$failures[] = array(
					'check'   => $check['name'],
					'name'    => $check['name'],
					'message' => $check['message'] ?? 'Invariant failed.',
					'details' => $check['details'] ?? null,
				);
			}
		}

		return array(
			'schemaVersion' => 1,
			'kind'          => 'component-fuzz-surface-result',
			'surface'       => self::NAME,
			'ok'            => array() === $failures,
			'status'        => array() === $failures ? 'passed' : 'failed',
			'seed'          => $seed,
			'caseCount'     => count( $checks ),
			'checks'        => $checks,
			'failures'      => $failures,
			'features'      => self::features_from_checks( $checks ),
			'durationMs'    => self::duration_ms( $started_at ),
		);
	}

	private static function missing_core_requirements(): array {
		$classes = array(
			'WP_Error',
			'WP_HTTP_Response',
			'WP_Http',
			'WP_List_Util',
			'WP_REST_Request',
			'WP_REST_Response',
			'WP_REST_Server',
			'WP_User',
		);
		$functions = array(
			'__',
			'_n',
			'_wp_get_current_user',
			'absint',
			'apply_filters',
			'get_option',
			'has_filter',
			'is_email',
			'is_user_logged_in',
			'is_wp_error',
			'number_format_i18n',
			'register_rest_route',
			'rest_convert_error_to_response',
			'rest_ensure_response',
			'rest_get_server',
			'rest_sanitize_value_from_schema',
			'rest_send_allow_header',
			'rest_validate_value_from_schema',
			'rest_url',
			'sanitize_hex_color',
			'sanitize_text_field',
			'sanitize_url',
			'trailingslashit',
			'wp_is_json_media_type',
			'wp_is_numeric_array',
			'wp_list_filter',
			'wp_parse_args',
			'wp_parse_list',
			'wp_set_current_user',
			'wp_sprintf_l',
		);

		$missing = array();
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = 'class:' . $class;
			}
		}
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = 'function:' . $function;
			}
		}

		return $missing;
	}

	private static function check_method_normalization( array &$rng ): array {
		$methods = array(
			'get',
			'Post',
			'PATCH',
			'HeAd',
			'options',
			'propfind',
			"get\0with-control",
			self::method_token( $rng ),
		);

		$cases = array();
		$ok    = true;
		foreach ( $methods as $method ) {
			$request  = new \WP_REST_Request( $method, '/cfuzz/v1/method' );
			$expected = strtoupper( $method );
			$observed = $request->get_method();
			$case_ok  = $expected === $observed
				&& $request->is_method( $method )
				&& $request->is_method( strtolower( $method ) );
			$ok       = $ok && $case_ok;
			$cases[]  = array(
				'ok'              => $case_ok,
				'input'           => $method,
				'expectedMethod'  => $expected,
				'observedMethod'  => $observed,
				'isOriginal'      => $request->is_method( $method ),
				'isLowercase'     => $request->is_method( strtolower( $method ) ),
			);
		}

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'All generated methods normalized as expected.' : 'At least one generated method did not normalize as expected.',
			'features' => array( 'methods', 'control-method-bytes' ),
			'details'  => array( 'cases' => $cases ),
		);
	}

	private static function check_header_query_body_normalization( array &$rng ): array {
		$token = self::slug_token( $rng, 'tok' );

		$header_request = new \WP_REST_Request( 'POST', '/cfuzz/v1/headers' );
		$header_request->set_headers(
			array(
				'Content-Type'   => 'Application/JSON; Charset=UTF-8',
				'X-Fuzz-Token'   => array( 'alpha', 'beta' ),
				'X-Mixed-Header' => 'one',
			)
		);
		$header_request->add_header( 'x_fuzz_token', 'gamma-' . $token );
		$header_request->add_header( 'x_mixed_header', array( 'two', 'three' ) );

		$content_type = $header_request->get_content_type();
		$headers_ok   = array(
			'contentTypeValue' => isset( $content_type['value'] ) && 'application/json' === $content_type['value'],
			'contentTypeType'  => isset( $content_type['type'] ) && 'application' === $content_type['type'],
			'contentTypeSub'   => isset( $content_type['subtype'] ) && 'json' === $content_type['subtype'],
			'jsonMediaType'    => $header_request->is_json_content_type(),
			'fuzzHeader'       => 'alpha,beta,gamma-' . $token === $header_request->get_header( 'X-Fuzz-Token' ),
			'fuzzHeaderArray'  => array( 'alpha', 'beta', 'gamma-' . $token ) === $header_request->get_header_as_array( 'x-fuzz-token' ),
			'mixedHeader'      => 'one,two,three' === $header_request->get_header( 'X_Mixed_Header' ),
			'canonicalName'    => 'x_fuzz_token' === \WP_REST_Request::canonicalize_header_name( 'X-Fuzz-Token' ),
		);

		$form_request = new \WP_REST_Request( 'PUT', '/cfuzz/v1/normalize' );
		$form_request->set_query_params(
			array(
				'same'       => 'query',
				'queryOnly'  => 'q-' . $token,
				'rest_route' => '/cfuzz/v1/normalize',
			)
		);
		$form_request->set_url_params(
			array(
				'same'      => 'url',
				'routeOnly' => 'route-' . $token,
			)
		);
		$form_request->set_default_params(
			array(
				'same'        => 'default',
				'defaultOnly' => 'default-' . $token,
			)
		);
		$form_request->set_body_params(
			array(
				'same'     => 'manual-body',
				'bodyOnly' => 'manual-' . $token,
			)
		);
		$form_request->set_file_params(
			array(
				'upload' => array(
					'name'  => 'fuzz-' . $token . '.txt',
					'type'  => 'text/plain',
					'size'  => self::rng_int( $rng, 1, 2048 ),
					'error' => 0,
				),
			)
		);
		$form_request->set_header( 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8' );
		$form_request->set_body( 'same=parsed-body&bodyOnly=parsed&from_body=1&list%5B0%5D=a&list%5B1%5D=b' );

		$body_before = $form_request->get_body_params();
		$params_one  = $form_request->get_params();
		$params_two  = $form_request->get_params();
		$body_after  = $form_request->get_body_params();
		$files       = $form_request->get_file_params();

		$params_ok = array(
			'repeatedStable'      => $params_one === $params_two,
			'manualBodyPrecedes'  => isset( $params_one['same'] ) && 'manual-body' === $params_one['same'],
			'manualBodyPreserved' => isset( $params_one['bodyOnly'] ) && 'manual-' . $token === $params_one['bodyOnly'],
			'parsedBodyAdded'     => isset( $params_one['from_body'] ) && '1' === $params_one['from_body'],
			'parsedListAdded'     => isset( $params_one['list'] ) && array( 'a', 'b' ) === $params_one['list'],
			'queryPreserved'      => isset( $params_one['queryOnly'] ) && 'q-' . $token === $params_one['queryOnly'],
			'urlPreserved'        => isset( $params_one['routeOnly'] ) && 'route-' . $token === $params_one['routeOnly'],
			'defaultPreserved'    => isset( $params_one['defaultOnly'] ) && 'default-' . $token === $params_one['defaultOnly'],
			'restRouteRemoved'    => ! array_key_exists( 'rest_route', $params_one ),
			'bodyLazyParsed'      => ! array_key_exists( 'from_body', $body_before ) && isset( $body_after['from_body'] ),
			'fileParamsStable'    => isset( $files['upload']['name'] ) && 'fuzz-' . $token . '.txt' === $files['upload']['name'],
		);

		$ok = self::all_true( $headers_ok ) && self::all_true( $params_ok );

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'Header and body/query normalization stayed stable.' : 'Header or body/query normalization drifted.',
			'features' => array( 'headers', 'urlencoded-body', 'files', 'rest-route-query-normalization' ),
			'details'  => array(
				'headers' => array(
					'ok'          => $headers_ok,
					'contentType' => $content_type,
					'allHeaders'  => $header_request->get_headers(),
				),
				'params'  => array(
					'ok'         => $params_ok,
					'bodyBefore' => $body_before,
					'bodyAfter'  => $body_after,
					'params'     => $params_one,
					'files'      => $files,
				),
			),
		);
	}

	private static function check_request_param_precedence( array &$rng ): array {
		$cases = array(
			array(
				'label'        => 'post-json',
				'method'       => 'POST',
				'contentType'  => 'application/json',
				'body'         => self::json_encode_for_body(
					array(
						'same'     => 'json',
						'jsonOnly' => self::slug_token( $rng, 'json' ),
						'nullish'  => null,
					)
				),
				'expectedSame' => 'json',
				'expectNull'   => true,
			),
			array(
				'label'        => 'delete-json',
				'method'       => 'DELETE',
				'contentType'  => 'application/vnd.api+json',
				'body'         => self::json_encode_for_body( array( 'same' => 'json-delete' ) ),
				'expectedSame' => 'json-delete',
			),
			array(
				'label'        => 'post-non-json',
				'method'       => 'POST',
				'contentType'  => 'text/plain',
				'body'         => '{"same":"ignored-json"}',
				'expectedSame' => 'body',
			),
			array(
				'label'        => 'put-urlencoded',
				'method'       => 'PUT',
				'contentType'  => 'application/x-www-form-urlencoded',
				'body'         => 'same=parsed-body&parsedOnly=yes',
				'expectedSame' => 'body',
				'parsedBody'   => true,
			),
			array(
				'label'        => 'get-urlencoded-body',
				'method'       => 'GET',
				'contentType'  => 'application/x-www-form-urlencoded',
				'body'         => 'same=parsed-body&parsedOnly=yes',
				'expectedSame' => 'query',
				'parsedBody'   => true,
			),
			array(
				'label'        => 'get-json-body',
				'method'       => 'GET',
				'contentType'  => 'application/json',
				'body'         => self::json_encode_for_body( array( 'same' => 'json-get' ) ),
				'expectedSame' => 'json-get',
			),
		);

		$results = array();
		$ok      = true;
		foreach ( $cases as $case ) {
			$request = new \WP_REST_Request( $case['method'], '/cfuzz/v1/precedence' );
			$request->set_default_params(
				array(
					'same'        => 'default',
					'defaultOnly' => 'default',
				)
			);
			$request->set_url_params(
				array(
					'same'    => 'url',
					'urlOnly' => 'url',
				)
			);
			$request->set_query_params(
				array(
					'same'       => 'query',
					'queryOnly'  => 'query',
					'rest_route' => '/cfuzz/v1/precedence',
				)
			);
			$request->set_body_params(
				array(
					'same'     => 'body',
					'bodyOnly' => 'body',
				)
			);
			$request->set_header( 'Content-Type', $case['contentType'] );
			$request->set_body( $case['body'] );

			$params_one  = $request->get_params();
			$params_two  = $request->get_params();
			$body_params = $request->get_body_params();
			$case_ok     = $case['expectedSame'] === $request->get_param( 'same' )
				&& $params_one === $params_two
				&& isset( $params_one['same'] )
				&& $case['expectedSame'] === $params_one['same']
				&& ! array_key_exists( 'rest_route', $params_one );

			if ( ! empty( $case['expectNull'] ) ) {
				$case_ok = $case_ok
					&& $request->has_param( 'nullish' )
					&& null === $request->get_param( 'nullish' )
					&& ! isset( $request['nullish'] );
			}
			if ( ! empty( $case['parsedBody'] ) ) {
				$case_ok = $case_ok && isset( $body_params['parsedOnly'] ) && 'yes' === $body_params['parsedOnly'];
			}

			$ok        = $ok && $case_ok;
			$results[] = array(
				'ok'              => $case_ok,
				'label'           => $case['label'],
				'method'          => $case['method'],
				'contentType'     => $case['contentType'],
				'expectedSame'    => $case['expectedSame'],
				'observedSame'    => $request->get_param( 'same' ),
				'params'          => $params_one,
				'bodyParams'      => $body_params,
				'hasNullish'      => ! empty( $case['expectNull'] ) ? $request->has_param( 'nullish' ) : null,
				'issetNullish'    => ! empty( $case['expectNull'] ) ? isset( $request['nullish'] ) : null,
				'repeatedStable'  => $params_one === $params_two,
			);
		}

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'Request parameter precedence matched expected order for all generated cases.' : 'Request parameter precedence mismatch.',
			'features' => array( 'param-precedence', 'json-precedence', 'body-precedence', 'null-parameter-presence' ),
			'details'  => array( 'cases' => $results ),
		);
	}

	private static function check_json_body_parsing( array &$rng ): array {
		$valid_token = self::slug_token( $rng, 'json' );
		$cases       = array(
			array(
				'label'       => 'valid-object',
				'contentType' => 'application/json',
				'body'        => self::json_encode_for_body(
					array(
						'value'   => $valid_token,
						'control' => "line\0end",
					)
				),
				'expect'      => 'valid',
			),
			array(
				'label'       => 'invalid-syntax',
				'contentType' => 'application/json',
				'body'        => '{"value":',
				'expect'      => 'error',
			),
			array(
				'label'       => 'invalid-utf8',
				'contentType' => 'application/json',
				'body'        => "{\"value\":\"\xC3\x28\"}",
				'expect'      => 'error',
			),
			array(
				'label'       => 'non-json-content-type',
				'contentType' => 'text/plain',
				'body'        => '{"value":',
				'expect'      => 'not-json',
			),
		);

		$results = array();
		$ok      = true;
		foreach ( $cases as $case ) {
			$request = new \WP_REST_Request( 'POST', '/cfuzz/v1/json' );
			$request->set_header( 'Content-Type', $case['contentType'] );
			$request->set_body( $case['body'] );
			$request->set_attributes( array( 'args' => array() ) );

			$valid_one = $request->has_valid_params();
			$valid_two = $request->has_valid_params();
			$json      = $request->get_json_params();
			$case_ok   = false;

			if ( 'valid' === $case['expect'] ) {
				$case_ok = true === $valid_one
					&& true === $valid_two
					&& is_array( $json )
					&& isset( $json['value'] )
					&& $valid_token === $json['value']
					&& isset( $json['control'] )
					&& "line\0end" === $json['control'];
			} elseif ( 'error' === $case['expect'] ) {
				$error_one = self::wp_error_summary( $valid_one );
				$error_two = self::wp_error_summary( $valid_two );
				$case_ok   = null !== $error_one
					&& null !== $error_two
					&& 'rest_invalid_json' === $error_one['code']
					&& 'rest_invalid_json' === $error_two['code']
					&& $error_one['data'] === $error_two['data'];
			} else {
				$case_ok = true === $valid_one && true === $valid_two && null === $json;
			}

			$ok        = $ok && $case_ok;
			$results[] = array(
				'ok'          => $case_ok,
				'label'       => $case['label'],
				'contentType' => $case['contentType'],
				'expect'      => $case['expect'],
				'validOne'    => self::result_summary( $valid_one ),
				'validTwo'    => self::result_summary( $valid_two ),
				'jsonParams'  => $json,
				'bodyPreview' => self::byte_preview( $case['body'] ),
			);
		}

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'JSON parsing represented all valid and invalid cases correctly.' : 'JSON parsing result drifted.',
			'features' => array( 'json', 'invalid-json', 'invalid-utf8', 'control-json-string' ),
			'details'  => array( 'cases' => $results ),
		);
	}

	private static function check_schema_sanitize_validate( array &$rng ): array {
		$enum_a = self::slug_token( $rng, 'red' );
		$enum_b = self::slug_token( $rng, 'green' );
		$cases  = array(
			array(
				'label'        => 'string-enum',
				'schema'       => array(
					'type' => 'string',
					'enum' => array( $enum_a, $enum_b ),
				),
				'valid'        => $enum_b,
				'invalid'      => 'outside-' . $enum_b,
				'constraint'   => 'enum',
			),
			array(
				'label'        => 'string-pattern',
				'schema'       => array(
					'type'    => 'string',
					'pattern' => '^[a-z]{2}[0-9]{2}$',
				),
				'valid'        => 'ab12',
				'invalid'      => 'ab!',
				'constraint'   => 'pattern',
			),
			array(
				'label'        => 'invalid-utf8-pattern',
				'schema'       => array(
					'type'    => 'string',
					'pattern' => '^valid$',
				),
				'valid'        => 'valid',
				'invalid'      => "\xC3\x28",
				'constraint'   => 'pattern',
			),
			array(
				'label'        => 'integer-bounds-multiple',
				'schema'       => array(
					'type'       => 'integer',
					'minimum'    => -10,
					'maximum'    => 10,
					'multipleOf' => 2,
				),
				'valid'        => '4',
				'invalid'      => 5,
				'constraint'   => 'multipleOf',
			),
			array(
				'label'        => 'boolean',
				'schema'       => array( 'type' => 'boolean' ),
				'valid'        => 'false',
				'invalid'      => 'maybe',
				'constraint'   => 'type',
			),
			array(
				'label'        => 'array-items',
				'schema'       => array(
					'type'      => 'array',
					'items'     => array( 'type' => 'integer' ),
					'minItems'  => 1,
					'maxItems'  => 4,
					'uniqueItems' => true,
				),
				'valid'        => array( '1', 2, '3' ),
				'invalid'      => array( 1, 2, 3, 4, 5 ),
				'constraint'   => 'maxItems',
			),
			array(
				'label'        => 'object-properties-pattern',
				'schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'name' ),
					'properties'           => array(
						'name' => array(
							'type'    => 'string',
							'pattern' => '^[a-z]+$',
						),
					),
					'patternProperties'    => array(
						'^x_[a-z]+$' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'valid'        => array(
					'name'      => 'alice',
					'x_enabled' => 'true',
				),
				'invalid'      => array(
					'name'  => 'alice',
					'extra' => 'forbidden',
				),
				'constraint'   => 'additionalProperties',
			),
			array(
				'label'        => 'date-time-format',
				'schema'       => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'valid'        => '2026-06-19T12:34:56Z',
				'invalid'      => 'not-a-date',
				'constraint'   => 'format',
			),
			array(
				'label'        => 'hex-color-format',
				'schema'       => array(
					'type'   => 'string',
					'format' => 'hex-color',
				),
				'valid'        => '#aabbcc',
				'invalid'      => 'blue',
				'constraint'   => 'format',
			),
		);

		$results = array();
		$ok      = true;
		foreach ( $cases as $i => $case ) {
			$param             = 'cfuzz_schema_' . $i;
			$raw_valid         = \rest_validate_value_from_schema( $case['valid'], $case['schema'], $param );
			$sanitized         = \rest_sanitize_value_from_schema( $case['valid'], $case['schema'], $param );
			$sanitize_error    = self::wp_error_summary( $sanitized );
			$sanitized_valid   = null;
			$sanitized_again   = null;
			$sanitized_stable  = false;

			if ( null === $sanitize_error ) {
				$sanitized_valid  = \rest_validate_value_from_schema( $sanitized, $case['schema'], $param );
				$sanitized_again  = \rest_sanitize_value_from_schema( $sanitized, $case['schema'], $param );
				$sanitized_stable = self::values_equal( $sanitized, $sanitized_again );
			}

			$invalid_validation = \rest_validate_value_from_schema( $case['invalid'], $case['schema'], $param );
			$invalid_sanitized  = \rest_sanitize_value_from_schema( $case['invalid'], $case['schema'], $param );
			$invalid_after_sanitize_validation = self::wp_error_summary( $invalid_sanitized )
				? null
				: \rest_validate_value_from_schema( $invalid_sanitized, $case['schema'], $param );

			$raw_valid_ok         = true === $raw_valid;
			$sanitized_valid_ok   = true === $sanitized_valid;
			$invalid_rejected     = null !== self::wp_error_summary( $invalid_validation );
			$constraint_rechecked = true;
			if ( in_array( $case['constraint'], array( 'enum', 'pattern', 'format' ), true ) ) {
				$constraint_rechecked = null !== self::wp_error_summary( $invalid_after_sanitize_validation );
			}

			$case_ok = $raw_valid_ok
				&& null === $sanitize_error
				&& $sanitized_valid_ok
				&& $sanitized_stable
				&& $invalid_rejected
				&& $constraint_rechecked;

			$ok        = $ok && $case_ok;
			$results[] = array(
				'ok'                                 => $case_ok,
				'label'                              => $case['label'],
				'constraint'                         => $case['constraint'],
				'schema'                             => $case['schema'],
				'valid'                              => $case['valid'],
				'sanitizedValid'                     => self::result_summary( $sanitized_valid ),
				'sanitized'                          => $sanitized,
				'sanitizedAgain'                     => $sanitized_again,
				'sanitizedStable'                    => $sanitized_stable,
				'invalid'                            => $case['invalid'],
				'invalidValidation'                  => self::result_summary( $invalid_validation ),
				'invalidAfterSanitizeValidation'     => self::result_summary( $invalid_after_sanitize_validation ),
			);
		}

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'Schema sanitize/validate invariants held for generated schemas.' : 'Schema sanitize/validate invariant failed.',
			'features' => array( 'schema', 'enum', 'pattern', 'format', 'array-schema', 'object-schema', 'invalid-utf8-schema' ),
			'details'  => array( 'cases' => $results ),
		);
	}

	private static function check_route_regex_matching( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$id        = (string) self::rng_int( $rng, 10, 9999 );
		$slug      = self::slug_token( $rng, 'item' );
		$route     = '/' . $namespace . '/thing/(?P<id>[0-9]+)/(?P<slug>[a-z][a-z0-9-]*)';
		$path      = '/' . $namespace . '/thing/' . $id . '/' . $slug;
		$hits      = array();
		$server    = new \WP_REST_Server();

		$server->register_route(
			$namespace,
			$route,
			array(
				array(
					'methods'             => 'GET, POST',
					'permission_callback' => function () {
						return true;
					},
					'callback'            => function ( $request ) use ( &$hits ) {
						$url_params = $request->get_url_params();
						$hits[]     = $url_params;
						return array(
							'method'    => $request->get_method(),
							'urlParams' => $url_params,
						);
					},
					'args'                => array(
						'defaulted' => array( 'default' => 'route-default' ),
					),
				),
			)
		);

		$request  = new \WP_REST_Request( 'GET', $path );
		$response = $server->dispatch( $request );
		$data     = $response->get_data();

		$expected_params = array(
			'id'   => $id,
			'slug' => $slug,
		);
		$match_ok        = 200 === $response->get_status()
			&& $route === $response->get_matched_route()
			&& isset( $data['urlParams'] )
			&& $expected_params === $data['urlParams']
			&& array( 'id', 'slug' ) === array_keys( $data['urlParams'] )
			&& $expected_params === $request->get_url_params()
			&& array( $expected_params ) === $hits;

		$malformed_path = '/' . $namespace . "/thing/\xC3\x28/" . str_repeat( 'a', self::rng_int( $rng, 8, 32 ) );
		$bad_request    = new \WP_REST_Request( 'GET', $malformed_path );
		$bad_response   = $server->dispatch( $bad_request );
		$bad_data       = $bad_response->get_data();
		$path_ok        = 404 === $bad_response->get_status()
			&& is_array( $bad_data )
			&& isset( $bad_data['code'] )
			&& 'rest_no_route' === $bad_data['code'];

		$nonmatching_regex_server = new \WP_REST_Server();
		$nonmatching_regex_route  = '/' . $namespace . '/broken/(?P<broken>[0-9]+)';
		$nonmatching_regex_server->register_route(
			$namespace,
			$nonmatching_regex_route,
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return true;
					},
					'callback'            => function () {
						return array( 'unexpected' => true );
					},
				),
			)
		);
		$nonmatching_regex_response = $nonmatching_regex_server->dispatch( new \WP_REST_Request( 'GET', '/' . $namespace . '/broken/x' ) );
		$nonmatching_regex_data     = $nonmatching_regex_response->get_data();
		$nonmatching_regex_ok       = 404 === $nonmatching_regex_response->get_status()
			&& is_array( $nonmatching_regex_data )
			&& isset( $nonmatching_regex_data['code'] )
			&& 'rest_no_route' === $nonmatching_regex_data['code'];

		$ok = $match_ok && $path_ok && $nonmatching_regex_ok;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'Route matching and non-matching route/path handling stayed stable.' : 'Route matching or non-matching route/path handling drifted.',
			'features' => array( 'routes', 'named-captures', 'malformed-path', 'nonmatching-route-regex' ),
			'details'  => array(
				'namespace' => $namespace,
				'route'     => $route,
				'path'      => $path,
				'match'     => array(
					'ok'              => $match_ok,
					'response'        => self::response_summary( $response ),
					'requestUrlParams' => $request->get_url_params(),
					'expectedParams'  => $expected_params,
					'hits'            => $hits,
				),
				'badPath'   => array(
					'ok'       => $path_ok,
					'path'     => $malformed_path,
					'response' => self::response_summary( $bad_response ),
				),
				'nonmatchingRegex' => array(
					'ok'       => $nonmatching_regex_ok,
					'route'    => $nonmatching_regex_route,
					'response' => self::response_summary( $nonmatching_regex_response ),
				),
			),
		);
	}

	private static function check_register_rest_route_wrapper( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$token     = self::slug_token( $rng, 'route' );
		$id        = (string) self::rng_int( $rng, 100, 999 );
		$route     = '/wrapper/(?P<id>[0-9]+)';
		$full_route = '/' . $namespace . '/wrapper/(?P<id>[0-9]+)';
		$path      = '/' . $namespace . '/wrapper/' . $id;
		$server             = new \WP_REST_Server();
		$get_hits           = 0;
		$post_hits          = 0;
		$override_get_hits  = 0;
		$override_post_hits = 0;
		$get_token          = 'get-' . substr( $token, -6 );
		$post_token         = 'post-' . substr( $token, -6 );

		$state = array(
			'serverExists' => array_key_exists( 'wp_rest_server', $GLOBALS ),
			'server'       => $GLOBALS['wp_rest_server'] ?? null,
			'actionsExists' => array_key_exists( 'wp_actions', $GLOBALS ),
			'actions'      => $GLOBALS['wp_actions'] ?? null,
		);

		try {
			$GLOBALS['wp_rest_server'] = $server;
			if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
				$GLOBALS['wp_actions'] = array();
			}
			$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

			$common_args = array(
				'id'    => array(
					'type' => 'integer',
				),
				'token' => array(
					'type'    => 'string',
					'default' => $token,
					'pattern' => '^route-[a-z0-9]{6}$',
				),
			);

			$registered_get = \register_rest_route(
				$namespace,
				$route,
				array(
					'args'   => $common_args,
					'schema' => static function () use ( $namespace ): array {
						return array( 'namespace' => $namespace );
					},
					array(
						'methods'             => 'GET',
						'permission_callback' => static function (): bool {
							return true;
						},
						'callback'            => static function ( \WP_REST_Request $request ) use ( &$get_hits ): array {
							++$get_hits;

							return array(
								'handler' => 'get',
								'id'      => $request['id'],
								'token'   => $request['token'],
								'mode'    => $request['mode'],
								'method'  => $request->get_method(),
							);
						},
						'args'                => array(
							'mode' => array(
								'type'    => 'string',
								'default' => 'view',
								'enum'    => array( 'view', 'edit' ),
							),
							'token' => array(
								'type'    => 'string',
								'default' => $get_token,
								'pattern' => '^get-[a-z0-9]{6}$',
							),
						),
					),
				)
			);

			$registered_post = \register_rest_route(
				$namespace,
				$route,
				array(
					'args' => $common_args,
					array(
						'methods'             => array( 'POST' ),
						'permission_callback' => static function (): bool {
							return true;
						},
						'callback'            => static function ( \WP_REST_Request $request ) use ( &$post_hits ): array {
							++$post_hits;

							return array(
								'handler' => 'post',
								'id'      => $request['id'],
								'token'   => $request['token'],
								'payload' => $request['payload'],
								'method'  => $request->get_method(),
							);
						},
						'args'                => array(
							'payload' => array(
								'type'     => 'string',
								'required' => true,
							),
							'token'   => array(
								'type'    => 'string',
								'default' => $post_token,
								'pattern' => '^post-[a-z0-9]{6}$',
							),
						),
					),
				)
			);

			$routes_after_merge = $server->get_routes( $namespace );
			$route_options      = $server->get_route_options( $full_route );
			$merged_handlers    = $routes_after_merge[ $full_route ] ?? array();
			$merged_summary     = self::route_handler_summary( $merged_handlers );
			$schema_data        = is_callable( $route_options['schema'] ?? null ) ? call_user_func( $route_options['schema'] ) : null;

			$get_request = new \WP_REST_Request( 'GET', $path );
			$get_request->set_query_params( array( 'mode' => 'edit' ) );
			$get_response = $server->dispatch( $get_request );
			$get_data     = $get_response->get_data();

			$post_request = new \WP_REST_Request( 'POST', $path );
			$post_request->set_body_params( array( 'payload' => 'body-' . $token ) );
			$post_response = $server->dispatch( $post_request );
			$post_data     = $post_response->get_data();

			$override_route = '/override-' . substr( $token, -3 );
			$override_path  = '/' . $namespace . '/' . trim( $override_route, '/' );
			$override_full  = '/' . $namespace . '/' . trim( $override_route, '/' );
			$override_get   = \register_rest_route(
				$namespace,
				$override_route,
				array(
					array(
						'methods'             => 'GET',
						'permission_callback' => static function (): bool {
							return true;
						},
						'callback'            => static function () use ( &$override_get_hits ): array {
							++$override_get_hits;

							return array( 'handler' => 'override-get' );
						},
					),
				)
			);
			$override_post  = \register_rest_route(
				$namespace,
				$override_route,
				array(
					array(
						'methods'             => 'POST',
						'permission_callback' => static function (): bool {
							return true;
						},
						'callback'            => static function () use ( &$override_post_hits ): array {
							++$override_post_hits;

							return array( 'handler' => 'override-post' );
						},
					),
				),
				true
			);
			$routes_after_override = $server->get_routes( $namespace );
			$override_handlers     = $routes_after_override[ $override_full ] ?? array();
			$override_summary      = self::route_handler_summary( $override_handlers );
			$override_get_response  = $server->dispatch( new \WP_REST_Request( 'GET', $override_path ) );
			$override_post_response = $server->dispatch( new \WP_REST_Request( 'POST', $override_path ) );
			$override_get_data      = $override_get_response->get_data();
			$override_post_data     = $override_post_response->get_data();
		} finally {
			if ( $state['serverExists'] ) {
				$GLOBALS['wp_rest_server'] = $state['server'];
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
			if ( $state['actionsExists'] ) {
				$GLOBALS['wp_actions'] = $state['actions'];
			} else {
				unset( $GLOBALS['wp_actions'] );
			}
		}

		$state_restored = ( $state['serverExists']
			? array_key_exists( 'wp_rest_server', $GLOBALS ) && $state['server'] === $GLOBALS['wp_rest_server']
			: ! array_key_exists( 'wp_rest_server', $GLOBALS ) )
			&& ( $state['actionsExists']
				? array_key_exists( 'wp_actions', $GLOBALS ) && $state['actions'] === $GLOBALS['wp_actions']
				: ! array_key_exists( 'wp_actions', $GLOBALS ) );

		$merge_ok = true === $registered_get
			&& true === $registered_post
			&& array( 'GET', 'POST' ) === array_keys( $merged_summary )
			&& isset( $merged_handlers[0]['args']['id'], $merged_handlers[0]['args']['token'], $merged_handlers[0]['args']['mode'] )
			&& isset( $merged_handlers[1]['args']['id'], $merged_handlers[1]['args']['token'], $merged_handlers[1]['args']['payload'] )
			&& ( $merged_handlers[0]['args']['token']['default'] ?? null ) === $get_token
			&& ( $merged_handlers[1]['args']['token']['default'] ?? null ) === $post_token
			&& is_array( $route_options )
			&& is_callable( $route_options['schema'] ?? null )
			&& array( 'namespace' => $namespace ) === $schema_data;
		$dispatch_ok = 200 === $get_response->get_status()
			&& 200 === $post_response->get_status()
			&& is_array( $get_data )
			&& is_array( $post_data )
			&& array(
				'handler' => 'get',
				'id'      => (int) $id,
				'token'   => $get_token,
				'mode'    => 'edit',
				'method'  => 'GET',
			) === $get_data
			&& array(
				'handler' => 'post',
				'id'      => (int) $id,
				'token'   => $post_token,
				'payload' => 'body-' . $token,
				'method'  => 'POST',
			) === $post_data
			&& 1 === $get_hits
			&& 1 === $post_hits;
		$override_ok = true === $override_get
			&& true === $override_post
			&& array( 'POST' ) === array_keys( $override_summary )
			&& 1 === count( $override_handlers )
			&& 0 === $override_get_hits
			&& 1 === $override_post_hits
			&& 200 !== $override_get_response->get_status()
			&& 200 === $override_post_response->get_status()
			&& is_array( $override_get_data )
			&& is_array( $override_post_data )
			&& array( 'handler' => 'override-post' ) === $override_post_data;
		$ok = $merge_ok && $dispatch_ok && $override_ok && $state_restored;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'register_rest_route wrapper merge, override, dispatch, and global restoration semantics held.' : 'register_rest_route wrapper invariant failed.',
			'features' => array( 'register-rest-route', 'route-merge', 'route-override', 'common-route-args', 'route-options', 'global-rest-server-restore' ),
			'details'  => array(
				'namespace'            => $namespace,
				'route'                => $full_route,
				'mergeOk'              => $merge_ok,
				'dispatchOk'           => $dispatch_ok,
				'overrideOk'           => $override_ok,
				'stateRestored'        => $state_restored,
				'mergedHandlers'       => $merged_summary,
				'overrideHandlers'     => $override_summary,
				'routeOptions'         => array_keys( (array) $route_options ),
				'getResponse'          => self::response_summary( $get_response ),
				'postResponse'         => self::response_summary( $post_response ),
				'overrideGetResponse'  => self::response_summary( $override_get_response ),
				'overridePostResponse' => self::response_summary( $override_post_response ),
				'hits'                 => array(
					'get'           => $get_hits,
					'post'          => $post_hits,
					'overrideGet'   => $override_get_hits,
					'overridePost'  => $override_post_hits,
				),
			),
		);
	}

	private static function check_head_get_routing( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$server    = new \WP_REST_Server();
		$get_hits  = 0;
		$head_hits = 0;

		$fallback_route = '/' . $namespace . '/head-fallback/(?P<id>[0-9]+)';
		$server->register_route(
			$namespace,
			$fallback_route,
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return true;
					},
					'callback'            => function ( $request ) use ( &$get_hits ) {
						++$get_hits;
						return array(
							'handler'       => 'get-fallback',
							'requestMethod' => $request->get_method(),
							'urlParams'     => $request->get_url_params(),
						);
					},
				),
			)
		);

		$explicit_route = '/' . $namespace . '/head-explicit';
		$server->register_route(
			$namespace,
			$explicit_route,
			array(
				array(
					'methods'             => 'HEAD',
					'permission_callback' => function () {
						return true;
					},
					'callback'            => function ( $request ) use ( &$head_hits ) {
						++$head_hits;
						return array(
							'handler'       => 'head-explicit',
							'requestMethod' => $request->get_method(),
						);
					},
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return true;
					},
					'callback'            => function ( $request ) use ( &$get_hits ) {
						++$get_hits;
						return array(
							'handler'       => 'get-explicit-route',
							'requestMethod' => $request->get_method(),
						);
					},
				),
			)
		);

		$fallback_response = $server->dispatch( new \WP_REST_Request( 'HEAD', '/' . $namespace . '/head-fallback/42' ) );
		$explicit_head     = $server->dispatch( new \WP_REST_Request( 'HEAD', '/' . $namespace . '/head-explicit' ) );
		$explicit_get      = $server->dispatch( new \WP_REST_Request( 'GET', '/' . $namespace . '/head-explicit' ) );

		$fallback_data = $fallback_response->get_data();
		$head_data     = $explicit_head->get_data();
		$get_data      = $explicit_get->get_data();

		$fallback_ok = 200 === $fallback_response->get_status()
			&& is_array( $fallback_data )
			&& 'get-fallback' === ( $fallback_data['handler'] ?? null )
			&& 'HEAD' === ( $fallback_data['requestMethod'] ?? null )
			&& array( 'id' => '42' ) === ( $fallback_data['urlParams'] ?? null );
		$explicit_ok = 200 === $explicit_head->get_status()
			&& 200 === $explicit_get->get_status()
			&& is_array( $head_data )
			&& is_array( $get_data )
			&& 'head-explicit' === ( $head_data['handler'] ?? null )
			&& 'HEAD' === ( $head_data['requestMethod'] ?? null )
			&& 'get-explicit-route' === ( $get_data['handler'] ?? null )
			&& 'GET' === ( $get_data['requestMethod'] ?? null );
		$hits_ok     = 2 === $get_hits && 1 === $head_hits;
		$ok          = $fallback_ok && $explicit_ok && $hits_ok;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'HEAD/GET route behavior matched documented fallback rules.' : 'HEAD/GET route behavior drifted.',
			'features' => array( 'head-routing', 'get-fallback' ),
			'details'  => array(
				'namespace' => $namespace,
				'fallback'  => array(
					'ok'       => $fallback_ok,
					'response' => self::response_summary( $fallback_response ),
				),
				'explicit'  => array(
					'ok'           => $explicit_ok,
					'headResponse' => self::response_summary( $explicit_head ),
					'getResponse'  => self::response_summary( $explicit_get ),
				),
				'hits'      => array(
					'ok'       => $hits_ok,
					'getHits'  => $get_hits,
					'headHits' => $head_hits,
				),
			),
		);
	}

	private static function check_permission_callback_semantics( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$server    = new \WP_REST_Server();
		$cases     = array(
			array(
				'label'   => 'true',
				'factory' => function () {
					return true;
				},
				'allowed' => true,
			),
			array(
				'label'   => 'zero',
				'factory' => function () {
					return 0;
				},
				'allowed' => true,
			),
			array(
				'label'   => 'empty-string',
				'factory' => function () {
					return '';
				},
				'allowed' => true,
			),
			array(
				'label'   => 'false',
				'factory' => function () {
					return false;
				},
				'allowed' => false,
				'code'    => 'rest_forbidden',
			),
			array(
				'label'   => 'null',
				'factory' => function () {
					return null;
				},
				'allowed' => false,
				'code'    => 'rest_forbidden',
			),
			array(
				'label'   => 'wp-error',
				'factory' => function () {
					return new \WP_Error( 'cfuzz_permission_denied', 'Denied by component fuzz permission callback.', array( 'status' => 418 ) );
				},
				'allowed' => false,
				'code'    => 'cfuzz_permission_denied',
				'status'  => 418,
			),
		);

		$results = array();
		$ok      = true;
		foreach ( $cases as $case ) {
			$route           = '/' . $namespace . '/permission/' . $case['label'];
			$permission_hits = 0;
			$callback_hits   = 0;
			$factory         = $case['factory'];
			$label           = $case['label'];

			$server->register_route(
				$namespace,
				$route,
				array(
					array(
						'methods'             => 'GET',
						'permission_callback' => function () use ( &$permission_hits, $factory ) {
							++$permission_hits;
							return $factory();
						},
						'callback'            => function () use ( &$callback_hits, $label ) {
							++$callback_hits;
							return array(
								'case' => $label,
								'ok'   => true,
							);
						},
					),
				)
			);

			$response = $server->dispatch( new \WP_REST_Request( 'GET', $route ) );
			$data     = $response->get_data();
			if ( ! empty( $case['allowed'] ) ) {
				$case_ok = 200 === $response->get_status()
					&& 1 === $permission_hits
					&& 1 === $callback_hits
					&& is_array( $data )
					&& $label === ( $data['case'] ?? null );
			} else {
				$expected_status = isset( $case['status'] ) ? $case['status'] : null;
				$status_ok       = null === $expected_status
					? in_array( $response->get_status(), array( 401, 403 ), true )
					: $expected_status === $response->get_status();
				$case_ok         = $status_ok
					&& 1 === $permission_hits
					&& 0 === $callback_hits
					&& is_array( $data )
					&& isset( $data['code'] )
					&& $case['code'] === $data['code'];
			}

			$ok        = $ok && $case_ok;
			$results[] = array(
				'ok'             => $case_ok,
				'label'          => $label,
				'allowed'        => $case['allowed'],
				'permissionHits' => $permission_hits,
				'callbackHits'   => $callback_hits,
				'response'       => self::response_summary( $response ),
			);
		}

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'Permission callback strictness matched REST server semantics.' : 'Permission callback semantics drifted.',
			'features' => array( 'permissions', 'wp-error-permission', 'falsey-permission-values' ),
			'details'  => array(
				'namespace' => $namespace,
				'cases'     => $results,
			),
		);
	}

	private static function check_argument_validation_error_envelope_parity( array &$rng ): array {
		$namespace         = self::namespace_token( $rng );
		$token             = self::slug_token( $rng, 'arg' );
		$route             = '/' . $namespace . '/arg-errors/(?P<id>[0-9]+)';
		$path              = '/' . $namespace . '/arg-errors/42';
		$server            = new \WP_REST_Server();
		$permission_hits   = 0;
		$callback_hits     = 0;
		$sanitize_hits     = array();
		$validate_hits     = array();
		$callback_params   = array();
		$valid_label       = '  Clean <b>' . $token . '</b>  ';
		$valid_sanitized   = \sanitize_text_field( $valid_label );
		$sanitize_error    = new \WP_Error(
			'cfuzz_arg_sanitize_error',
			'Custom sanitizer rejected ' . $token,
			array(
				'status' => 400,
				'param'  => 'customSanitize',
				'token'  => $token,
			)
		);
		$validate_error    = new \WP_Error(
			'cfuzz_arg_validate_error',
			'Custom validator rejected ' . $token,
			array(
				'status' => 400,
				'param'  => 'customValidate',
				'token'  => $token,
			)
		);
		$valid_common_body = array(
			'requiredToken'  => $token,
			'schemaCount'   => '4',
			'schemaFlag'    => 'true',
			'schemaPattern' => 'ok-' . substr( $token, -6 ),
		);

		$server->register_route(
			$namespace,
			$route,
			array(
				'allow_batch' => array( 'v1' => true ),
				array(
					'methods'             => 'POST',
					'permission_callback' => static function () use ( &$permission_hits ): bool {
						++$permission_hits;
						return true;
					},
					'callback'            => static function ( \WP_REST_Request $request ) use ( &$callback_hits, &$callback_params ): \WP_REST_Response {
						++$callback_hits;
						$callback_params[] = $request->get_params();

						return new \WP_REST_Response(
							array(
								'params' => $request->get_params(),
								'route'  => $request->get_route(),
								'method' => $request->get_method(),
							),
							201,
							array( 'X-Arg-Route' => 'seen' )
						);
					},
					'args'                => array(
						'id'             => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'requiredToken'  => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^arg-[a-z0-9]{6}$',
						),
						'schemaCount'   => array(
							'type'    => 'integer',
							'minimum' => 2,
							'maximum' => 5,
						),
						'schemaFlag'    => array(
							'type' => 'boolean',
						),
						'schemaPattern' => array(
							'type'    => 'string',
							'pattern' => '^ok-[a-z0-9]+$',
						),
						'customSanitize' => array(
							'sanitize_callback' => static function ( $value, \WP_REST_Request $request, string $param ) use ( &$sanitize_hits, $sanitize_error ) {
								$sanitize_hits[] = array(
									'param' => $param,
									'value' => $value,
									'route' => $request->get_route(),
								);

								if ( 'sanitize-error' === $value ) {
									return $sanitize_error;
								}

								return \sanitize_text_field( $value );
							},
						),
						'customValidate' => array(
							'validate_callback' => static function ( $value, \WP_REST_Request $request, string $param ) use ( &$validate_hits, $validate_error, $token ) {
								$validate_hits[] = array(
									'param' => $param,
									'value' => $value,
									'route' => $request->get_route(),
								);

								if ( 'valid-' . $token === $value ) {
									return true;
								}

								return $validate_error;
							},
						),
					),
				),
			)
		);

		$schema_invalid_body = array_merge(
			$valid_common_body,
			array(
				'schemaCount'   => 1,
				'schemaFlag'    => 'not-bool',
				'schemaPattern' => 'bad pattern',
			)
		);
		$schema_request      = new \WP_REST_Request( 'POST', $path );
		$schema_request->set_body_params( $schema_invalid_body );
		$schema_response = $server->dispatch( $schema_request );
		$schema_data     = $schema_response->get_data();

		$sanitize_request = new \WP_REST_Request( 'POST', $path );
		$sanitize_request->set_body_params(
			array_merge(
				$valid_common_body,
				array(
					'customSanitize' => 'sanitize-error',
				)
			)
		);
		$sanitize_response = $server->dispatch( $sanitize_request );
		$sanitize_data     = $sanitize_response->get_data();

		$missing_request = new \WP_REST_Request( 'POST', $path );
		$missing_request->set_body_params(
			array(
				'schemaCount'   => 3,
				'schemaFlag'    => false,
				'schemaPattern' => 'ok-' . substr( $token, -6 ),
			)
		);
		$missing_response = $server->dispatch( $missing_request );
		$missing_data     = $missing_response->get_data();

		$validate_request = new \WP_REST_Request( 'POST', $path );
		$validate_request->set_body_params(
			array_merge(
				$valid_common_body,
				array(
					'customSanitize' => $valid_label,
					'customValidate' => 'invalid-' . $token,
				)
			)
		);
		$validate_response = $server->dispatch( $validate_request );
		$validate_data     = $validate_response->get_data();

		$invalid_permission_hits = $permission_hits;
		$invalid_callback_hits   = $callback_hits;

		$valid_request = new \WP_REST_Request( 'POST', $path );
		$valid_request->set_body_params(
			array_merge(
				$valid_common_body,
				array(
					'customSanitize' => $valid_label,
					'customValidate' => 'valid-' . $token,
				)
			)
		);
		$valid_response = $server->dispatch( $valid_request );
		$valid_data     = $valid_response->get_data();

		$schema_envelope   = $server->envelope_response( $schema_response, false );
		$sanitize_envelope = $server->envelope_response( $sanitize_response, false );
		$validate_envelope = $server->envelope_response( $validate_response, false );
		$batch_response    = $server->dispatch(
			self::batch_request(
				array(
					array(
						'method' => 'POST',
						'path'   => $path,
						'body'   => $schema_invalid_body,
					),
				),
				'normal'
			)
		);
		$batch_data        = $batch_response->get_data();
		$batch_child       = is_array( $batch_data['responses'][0] ?? null ) ? $batch_data['responses'][0] : array();

		$schema_params = is_array( $schema_data['data']['params'] ?? null ) ? array_keys( $schema_data['data']['params'] ) : array();
		sort( $schema_params, SORT_STRING );
		$schema_details = is_array( $schema_data['data']['details'] ?? null ) ? $schema_data['data']['details'] : array();
		$schema_ok      = 400 === $schema_response->get_status()
			&& 'rest_invalid_param' === ( $schema_data['code'] ?? null )
			&& 400 === ( $schema_data['data']['status'] ?? null )
			&& array( 'schemaCount', 'schemaFlag', 'schemaPattern' ) === $schema_params
			&& 'rest_out_of_bounds' === ( $schema_details['schemaCount']['code'] ?? null )
			&& 'rest_invalid_type' === ( $schema_details['schemaFlag']['code'] ?? null )
			&& 'rest_invalid_pattern' === ( $schema_details['schemaPattern']['code'] ?? null );

		$sanitize_params = is_array( $sanitize_data['data']['params'] ?? null ) ? array_keys( $sanitize_data['data']['params'] ) : array();
		$sanitize_ok     = 400 === $sanitize_response->get_status()
			&& 'rest_invalid_param' === ( $sanitize_data['code'] ?? null )
			&& array( 'customSanitize' ) === $sanitize_params
			&& \rest_convert_error_to_response( $sanitize_error )->get_data() === ( $sanitize_data['data']['details']['customSanitize'] ?? null );

		$missing_ok = 400 === $missing_response->get_status()
			&& 'rest_missing_callback_param' === ( $missing_data['code'] ?? null )
			&& 400 === ( $missing_data['data']['status'] ?? null )
			&& array( 'requiredToken' ) === ( $missing_data['data']['params'] ?? null );

		$validate_params = is_array( $validate_data['data']['params'] ?? null ) ? array_keys( $validate_data['data']['params'] ) : array();
		$validate_ok     = 400 === $validate_response->get_status()
			&& 'rest_invalid_param' === ( $validate_data['code'] ?? null )
			&& array( 'customValidate' ) === $validate_params
			&& \rest_convert_error_to_response( $validate_error )->get_data() === ( $validate_data['data']['details']['customValidate'] ?? null );

		$envelope_ok = 200 === $schema_envelope->get_status()
			&& array(
				'body'    => $schema_data,
				'status'  => 400,
				'headers' => $schema_response->get_headers(),
			) === $schema_envelope->get_data()
			&& 200 === $sanitize_envelope->get_status()
			&& array(
				'body'    => $sanitize_data,
				'status'  => 400,
				'headers' => $sanitize_response->get_headers(),
			) === $sanitize_envelope->get_data()
			&& 200 === $validate_envelope->get_status()
			&& array(
				'body'    => $validate_data,
				'status'  => 400,
				'headers' => $validate_response->get_headers(),
			) === $validate_envelope->get_data();

		$batch_ok = 207 === $batch_response->get_status()
			&& 400 === ( $batch_child['status'] ?? null )
			&& $schema_data === ( $batch_child['body'] ?? null )
			&& $schema_response->get_headers() === ( $batch_child['headers'] ?? null );

		$valid_params = is_array( $valid_data['params'] ?? null ) ? $valid_data['params'] : array();
		$valid_ok     = 201 === $valid_response->get_status()
			&& 1 === $permission_hits
			&& 1 === $callback_hits
			&& array(
				'id'             => 42,
				'requiredToken'  => $token,
				'schemaCount'   => 4,
				'schemaFlag'    => true,
				'schemaPattern' => 'ok-' . substr( $token, -6 ),
				'customSanitize' => $valid_sanitized,
				'customValidate' => 'valid-' . $token,
			) === $valid_params
			&& array( $valid_params ) === $callback_params
			&& 'seen' === ( $valid_response->get_headers()['X-Arg-Route'] ?? null );

		$callback_order_ok = 0 === $invalid_permission_hits
			&& 0 === $invalid_callback_hits
			&& 2 === count( $sanitize_hits )
			&& 2 === count( $validate_hits );

		$ok = $schema_ok
			&& $sanitize_ok
			&& $missing_ok
			&& $validate_ok
			&& $envelope_ok
			&& $batch_ok
			&& $valid_ok
			&& $callback_order_ok;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'REST argument validation and envelope parity invariants held.' : 'REST argument validation or envelope parity invariant failed.',
			'features' => array( 'route-args', 'rest-invalid-param', 'rest-missing-callback-param', 'error-details', 'envelope-parity', 'batch-child-error-envelope' ),
			'details'  => array(
				'namespace'             => $namespace,
				'route'                 => $route,
				'schemaOk'              => $schema_ok,
				'sanitizeOk'            => $sanitize_ok,
				'missingOk'             => $missing_ok,
				'validateOk'            => $validate_ok,
				'envelopeOk'            => $envelope_ok,
				'batchOk'               => $batch_ok,
				'validOk'               => $valid_ok,
				'callbackOrderOk'       => $callback_order_ok,
				'invalidPermissionHits' => $invalid_permission_hits,
				'invalidCallbackHits'   => $invalid_callback_hits,
				'permissionHits'        => $permission_hits,
				'callbackHits'          => $callback_hits,
				'sanitizeHits'          => $sanitize_hits,
				'validateHits'          => $validate_hits,
				'schemaResponse'        => self::response_summary( $schema_response ),
				'sanitizeResponse'      => self::response_summary( $sanitize_response ),
				'missingResponse'       => self::response_summary( $missing_response ),
				'validateResponse'      => self::response_summary( $validate_response ),
				'validResponse'         => self::response_summary( $valid_response ),
				'batchResponse'         => self::response_summary( $batch_response ),
			),
		);
	}

	private static function check_batch_v1_execution( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$token     = self::slug_token( $rng, 'batch' );
		$server    = new \WP_REST_Server();

		$had_rest_server      = array_key_exists( 'wp_rest_server', $GLOBALS );
		$previous_rest_server = $had_rest_server ? $GLOBALS['wp_rest_server'] : null;
		$callback_hits        = array();
		$permission_hits      = array();
		$gate_hits            = array();
		$seen_requests        = array();
		$child_pre_dispatch   = array();
		$child_post_dispatch  = array();
		$parent_pre_hits      = 0;

		$allowed_route = '/' . $namespace . '/batch/(?P<id>[0-9]+)';
		$allowed_path  = '/' . $namespace . '/batch/';
		$allowed_args  = array(
			'id'        => array(
				'type' => 'integer',
			),
			'token'     => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^batch-[a-z0-9]{6}$',
			),
			'mode'      => array(
				'type'    => 'string',
				'default' => 'alpha',
				'enum'    => array( 'alpha', 'beta', 'gamma', 'delta' ),
			),
			'bodyValue' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$status_by_method = array(
			'POST'   => 200,
			'PUT'    => 201,
			'PATCH'  => 202,
			'DELETE' => 203,
		);

		$server->register_route(
			$namespace,
			$allowed_route,
			array(
				'allow_batch' => array( 'v1' => true ),
				array(
					'methods'             => 'POST, PUT, PATCH, DELETE',
					'permission_callback' => static function ( \WP_REST_Request $request ) use ( &$permission_hits ) {
						$route                    = $request->get_route();
						$permission_hits[ $route ] = ( $permission_hits[ $route ] ?? 0 ) + 1;
						return true;
					},
					'callback'            => static function ( \WP_REST_Request $request ) use ( &$callback_hits, &$seen_requests, $status_by_method ) {
						$route                 = $request->get_route();
						$method                = $request->get_method();
						$callback_hits[ $route ] = ( $callback_hits[ $route ] ?? 0 ) + 1;
						$seen_requests[]       = array(
							'route'   => $route,
							'method'  => $method,
							'query'   => $request->get_query_params(),
							'body'    => $request->get_body_params(),
							'headers' => array(
								'x_fuzz_batch' => $request->get_header_as_array( 'X-Fuzz-Batch' ),
								'x_method'     => $request->get_header( 'X-Method' ),
							),
						);

						return new \WP_REST_Response(
							array(
								'id'        => $request['id'],
								'method'    => $method,
								'route'     => $route,
								'urlParams' => $request->get_url_params(),
								'query'     => $request->get_query_params(),
								'body'      => $request->get_body_params(),
								'headers'   => array(
									'x_fuzz_batch' => $request->get_header_as_array( 'X-Fuzz-Batch' ),
									'x_method'     => $request->get_header( 'X-Method' ),
								),
								'params'    => array(
									'token'     => $request['token'],
									'mode'      => $request['mode'],
									'bodyValue' => $request['bodyValue'],
								),
							),
							$status_by_method[ $method ] ?? 200,
							array( 'X-Batch-Child' => (string) $request['id'] )
						);
					},
					'args'                => $allowed_args,
				),
			)
		);

		$gate_cases = array(
			array( 'label' => 'missing' ),
			array( 'label' => 'malformed', 'allow_batch' => true ),
			array( 'label' => 'wrong-version', 'allow_batch' => array( 'v2' => true ) ),
			array( 'label' => 'v1-false', 'allow_batch' => array( 'v1' => false ) ),
		);
		foreach ( $gate_cases as $case ) {
			$gate_route = '/' . $namespace . '/batch-gate-' . $case['label'];
			$route_args = array(
				array(
					'methods'             => 'POST',
					'permission_callback' => static function () {
						return true;
					},
					'callback'            => static function () use ( &$gate_hits, $case ) {
						$gate_hits[ $case['label'] ] = ( $gate_hits[ $case['label'] ] ?? 0 ) + 1;
						return array( 'gate' => $case['label'] );
					},
				),
			);
			if ( array_key_exists( 'allow_batch', $case ) ) {
				$route_args['allow_batch'] = $case['allow_batch'];
			}

			$server->register_route( $namespace, $gate_route, $route_args );
		}

		$valid_specs = array(
			array( 'method' => 'POST', 'id' => 101, 'mode' => 'alpha', 'queryOnly' => 'q-post', 'bodyValue' => 'body-post' ),
			array( 'method' => 'PUT', 'id' => 102, 'mode' => 'beta', 'queryOnly' => 'q-put', 'bodyValue' => 'body-put' ),
			array( 'method' => 'PATCH', 'id' => 103, 'mode' => 'gamma', 'queryOnly' => 'q-patch', 'bodyValue' => 'body-patch' ),
			array( 'method' => 'DELETE', 'id' => 104, 'mode' => 'delta', 'queryOnly' => 'q-delete', 'bodyValue' => 'body-delete' ),
		);
		$normal_requests = array();
		foreach ( $valid_specs as $spec ) {
			$normal_requests[] = array(
				'method'  => $spec['method'],
				'path'    => $allowed_path . $spec['id'] . '?token=' . rawurlencode( $token ) . '&mode=' . $spec['mode'] . '&queryOnly=' . rawurlencode( $spec['queryOnly'] ),
				'body'    => array(
					'bodyValue' => $spec['bodyValue'],
					'shared'    => 'shared-' . strtolower( $spec['method'] ),
				),
				'headers' => array(
					'X-Fuzz-Batch' => array( 'header-' . strtolower( $spec['method'] ), $token ),
					'X-Method'     => $spec['method'],
				),
			);
		}
		foreach ( $gate_cases as $case ) {
			$normal_requests[] = array(
				'method' => 'POST',
				'path'   => '/' . $namespace . '/batch-gate-' . $case['label'],
			);
		}
		$normal_requests[] = array(
			'method' => 'POST',
			'path'   => $allowed_path . '199?token=bad-token&mode=alpha',
			'body'   => array( 'bodyValue' => 'invalid-token-body' ),
		);
		$normal_requests[] = array(
			'method' => 'POST',
			'path'   => 'http://',
		);

		$pre_filter = static function ( $result, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, &$child_pre_dispatch, &$parent_pre_hits ) {
			if ( $filter_server !== $server ) {
				return $result;
			}
			if ( '/batch/v1' === $request->get_route() ) {
				++$parent_pre_hits;
				return $result;
			}

			$child_pre_dispatch[] = array(
				'route'     => $request->get_route(),
				'method'    => $request->get_method(),
				'urlParams' => $request->get_url_params(),
				'attrs'     => $request->get_attributes(),
				'defaults'  => $request->get_default_params(),
			);
			return $result;
		};
		$post_filter = static function ( \WP_REST_Response $response, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, &$child_post_dispatch ) {
			if ( $filter_server === $server ) {
				$child_post_dispatch[] = array(
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
					'status' => $response->get_status(),
				);
				$response->header( 'X-Post-Dispatch', 'seen' );
			}

			return $response;
		};

		add_filter( 'rest_pre_dispatch', $pre_filter, 10, 3 );
		add_filter( 'rest_post_dispatch', $post_filter, 10, 3 );
		try {
			$normal_response = $server->dispatch( self::batch_request( $normal_requests, 'normal' ) );
			$normal_data     = $normal_response->get_data();
			$callbacks_after_normal = array_sum( $callback_hits );
			$child_pre_after_normal = count( $child_pre_dispatch );
			$child_post_after_normal = count( $child_post_dispatch );

			$require_all_requests = array(
				array(
					'method' => 'POST',
					'path'   => $allowed_path . '301?token=' . rawurlencode( $token ) . '&mode=alpha',
					'body'   => array( 'bodyValue' => 'require-all-valid' ),
				),
				array(
					'method' => 'PATCH',
					'path'   => $allowed_path . '302?token=invalid token&mode=gamma',
					'body'   => array( 'bodyValue' => 'require-all-invalid' ),
				),
			);
			$require_all_response = $server->dispatch( self::batch_request( $require_all_requests, 'require-all-validate' ) );
			$require_all_data     = $require_all_response->get_data();
			$callbacks_after_require_all = array_sum( $callback_hits );
			$child_pre_after_require_all = count( $child_pre_dispatch );
			$child_post_after_require_all = count( $child_post_dispatch );
		} finally {
			remove_filter( 'rest_pre_dispatch', $pre_filter, 10 );
			remove_filter( 'rest_post_dispatch', $post_filter, 10 );
		}

		$max_filter = static function (): int {
			return 2;
		};
		add_filter( 'rest_get_max_batch_size', $max_filter );
		try {
			$small_batch_server = new \WP_REST_Server();
		} finally {
			remove_filter( 'rest_get_max_batch_size', $max_filter );
		}
		$overflow_response = $small_batch_server->dispatch(
			self::batch_request(
				array(
					array( 'path' => '/' ),
					array( 'path' => '/' ),
					array( 'path' => '/' ),
				),
				'normal'
			)
		);
		$overflow_data = $overflow_response->get_data();

		$filter_removed = false === has_filter( 'rest_pre_dispatch', $pre_filter )
			&& false === has_filter( 'rest_post_dispatch', $post_filter )
			&& false === has_filter( 'rest_get_max_batch_size', $max_filter );
		$rest_server_restored = $had_rest_server
			? array_key_exists( 'wp_rest_server', $GLOBALS ) && $previous_rest_server === $GLOBALS['wp_rest_server']
			: ! array_key_exists( 'wp_rest_server', $GLOBALS );

		$normal_responses = is_array( $normal_data['responses'] ?? null ) ? $normal_data['responses'] : array();
		$valid_ok         = 207 === $normal_response->get_status() && count( $normal_requests ) === count( $normal_responses );
		foreach ( $valid_specs as $index => $spec ) {
			$envelope = $normal_responses[ $index ] ?? array();
			$body     = $envelope['body'] ?? array();
			$headers  = $envelope['headers'] ?? array();
			$valid_ok = $valid_ok
				&& ( $status_by_method[ $spec['method'] ] ?? 200 ) === ( $envelope['status'] ?? null )
				&& (string) $spec['id'] === (string) ( $headers['X-Batch-Child'] ?? null )
				&& 'seen' === ( $headers['X-Post-Dispatch'] ?? null )
				&& is_array( $body )
				&& $spec['id'] === (int) ( $body['id'] ?? 0 )
				&& $spec['method'] === ( $body['method'] ?? null )
				&& $allowed_path . $spec['id'] === ( $body['route'] ?? null )
				&& array( 'id' => $spec['id'] ) === ( $body['urlParams'] ?? null )
				&& $token === ( $body['query']['token'] ?? null )
				&& $spec['mode'] === ( $body['query']['mode'] ?? null )
				&& $spec['queryOnly'] === ( $body['query']['queryOnly'] ?? null )
				&& $spec['bodyValue'] === ( $body['body']['bodyValue'] ?? null )
				&& 'shared-' . strtolower( $spec['method'] ) === ( $body['body']['shared'] ?? null )
				&& array( 'header-' . strtolower( $spec['method'] ), $token ) === ( $body['headers']['x_fuzz_batch'] ?? null )
				&& $spec['method'] === ( $body['headers']['x_method'] ?? null )
				&& $token === ( $body['params']['token'] ?? null )
				&& $spec['mode'] === ( $body['params']['mode'] ?? null )
				&& $spec['bodyValue'] === ( $body['params']['bodyValue'] ?? null );
		}

		$gate_start_index = count( $valid_specs );
		$gate_ok          = array() === $gate_hits;
		foreach ( $gate_cases as $offset => $case ) {
			$envelope = $normal_responses[ $gate_start_index + $offset ] ?? array();
			$gate_ok  = $gate_ok
				&& 400 === ( $envelope['status'] ?? null )
				&& 'rest_batch_not_allowed' === ( $envelope['body']['code'] ?? null );
		}

		$invalid_index    = $gate_start_index + count( $gate_cases );
		$parse_index      = $invalid_index + 1;
		$normal_error_ok  = 400 === ( $normal_responses[ $parse_index ]['status'] ?? null )
			&& 'parse_path_failed' === ( $normal_responses[ $parse_index ]['body']['code'] ?? null )
			&& 400 === ( $normal_responses[ $invalid_index ]['status'] ?? null )
			&& 'rest_invalid_param' === ( $normal_responses[ $invalid_index ]['body']['code'] ?? null );

		$pre_routes       = array_column( $child_pre_dispatch, 'route' );
		$post_routes      = array_column( $child_post_dispatch, 'route' );
		$filter_locality_ok = 2 === $parent_pre_hits
			&& count( $normal_requests ) - 1 === $child_pre_after_normal
			&& count( $normal_requests ) - 1 === $child_post_after_normal
			&& $child_pre_after_normal === $child_pre_after_require_all
			&& $child_post_after_normal === $child_post_after_require_all
			&& ! in_array( 'http://', $pre_routes, true )
			&& ! in_array( 'http://', $post_routes, true );

		$require_all_responses = is_array( $require_all_data['responses'] ?? null ) ? $require_all_data['responses'] : array();
		$require_all_ok        = 207 === $require_all_response->get_status()
			&& 'validation' === ( $require_all_data['failed'] ?? null )
			&& array_key_exists( 0, $require_all_responses )
			&& null === $require_all_responses[0]
			&& 400 === ( $require_all_responses[1]['status'] ?? null )
			&& 'rest_invalid_param' === ( $require_all_responses[1]['body']['code'] ?? null )
			&& $callbacks_after_normal === $callbacks_after_require_all;

		$overflow_ok = 400 === $overflow_response->get_status()
			&& 'rest_invalid_param' === ( $overflow_data['code'] ?? null )
			&& 'rest_too_many_items' === ( $overflow_data['data']['details']['requests']['code'] ?? null );

		$callback_ok = count( $valid_specs ) === $callbacks_after_normal
			&& count( $valid_specs ) === array_sum( $permission_hits )
			&& count( $valid_specs ) === count( $seen_requests );

		$ok = $valid_ok
			&& $gate_ok
			&& $normal_error_ok
			&& $filter_locality_ok
			&& $require_all_ok
			&& $overflow_ok
			&& $callback_ok
			&& $filter_removed
			&& $rest_server_restored;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'REST batch/v1 dispatch invariants held.' : 'REST batch/v1 dispatch invariant failed.',
			'features' => array( 'batch-v1', 'allow-batch', 'batch-validation', 'batch-envelopes', 'batch-filters', 'batch-max-size' ),
			'details'  => array(
				'namespace'          => $namespace,
				'token'              => $token,
				'validOk'            => $valid_ok,
				'gateOk'             => $gate_ok,
				'normalErrorOk'      => $normal_error_ok,
				'filterLocalityOk'   => $filter_locality_ok,
				'requireAllOk'       => $require_all_ok,
				'overflowOk'         => $overflow_ok,
				'callbackOk'         => $callback_ok,
				'filterRemoved'      => $filter_removed,
				'restServerRestored' => $rest_server_restored,
				'normalResponse'     => self::response_summary( $normal_response ),
				'requireAllResponse' => self::response_summary( $require_all_response ),
				'overflowResponse'   => self::response_summary( $overflow_response ),
				'callbackHits'       => $callback_hits,
				'permissionHits'     => $permission_hits,
				'gateHits'           => $gate_hits,
				'parentPreHits'      => $parent_pre_hits,
				'childPreDispatch'   => $child_pre_dispatch,
				'childPostDispatch'  => $child_post_dispatch,
				'seenRequests'       => $seen_requests,
			),
		);
	}

	private static function check_batch_v1_malformed_child_path_parsing( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$token     = self::slug_token( $rng, 'path' );
		$server    = new \WP_REST_Server();

		$had_rest_server      = array_key_exists( 'wp_rest_server', $GLOBALS );
		$previous_rest_server = $had_rest_server ? $GLOBALS['wp_rest_server'] : null;
		$permission_hits      = array();
		$callback_hits        = array();
		$seen_requests        = array();
		$child_pre_dispatch   = array();
		$child_post_dispatch  = array();
		$parent_pre_hits      = 0;

		$route = '/' . $namespace . '/batch-path/(?P<id>[0-9]+)';
		$path  = '/' . $namespace . '/batch-path/';

		$server->register_route(
			$namespace,
			$route,
			array(
				'allow_batch' => array( 'v1' => true ),
				array(
					'methods'             => 'POST',
					'permission_callback' => static function ( \WP_REST_Request $request ) use ( &$permission_hits ): bool {
						$route                    = $request->get_route();
						$permission_hits[ $route ] = ( $permission_hits[ $route ] ?? 0 ) + 1;
						return true;
					},
					'callback'            => static function ( \WP_REST_Request $request ) use ( &$callback_hits, &$seen_requests ) {
						$route                 = $request->get_route();
						$method                = $request->get_method();
						$callback_hits[ $route ] = ( $callback_hits[ $route ] ?? 0 ) + 1;
						$seen_requests[]       = array(
							'route'     => $route,
							'method'    => $method,
							'urlParams' => $request->get_url_params(),
							'query'     => $request->get_query_params(),
							'body'      => $request->get_body_params(),
							'headers'   => array(
								'x_batch_path' => $request->get_header( 'X-Batch-Path' ),
							),
						);

						return new \WP_REST_Response(
							array(
								'id'        => $request['id'],
								'route'     => $route,
								'method'    => $method,
								'urlParams' => $request->get_url_params(),
								'query'     => $request->get_query_params(),
								'body'      => $request->get_body_params(),
								'headers'   => array(
									'x_batch_path' => $request->get_header( 'X-Batch-Path' ),
								),
							),
							201,
							array( 'X-Batch-Path-Id' => (string) $request['id'] )
						);
					},
					'args'                => array(
						'id'     => array(
							'type' => 'integer',
						),
						'token'  => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^path-[a-z0-9]{6}$',
						),
						'marker' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		$valid_request = array(
			'method'  => 'POST',
			'path'    => $path . '101?token=' . rawurlencode( $token ) . '&marker=valid',
			'body'    => array( 'bodyValue' => 'body-valid' ),
			'headers' => array( 'X-Batch-Path' => 'valid' ),
		);
		$malformed_paths = array(
			array(
				'label' => 'empty-http-authority',
				'path'  => 'http://',
			),
			array(
				'label' => 'bad-userinfo',
				'path'  => 'http://user@:80',
			),
			array(
				'label' => 'empty-protocol-relative',
				'path'  => '//',
			),
			array(
				'label' => 'alpha-port',
				'path'  => 'http://example.test:' . self::slug_token( $rng, 'port' ) . '/batch',
			),
			array(
				'label' => 'negative-port',
				'path'  => 'http://example.test:-' . self::rng_int( $rng, 1, 99 ) . '/batch',
			),
		);

		$normal_requests = array( $valid_request );
		foreach ( $malformed_paths as $spec ) {
			$normal_requests[] = array(
				'method'  => 'POST',
				'path'    => $spec['path'],
				'body'    => array( 'bodyValue' => 'body-' . $spec['label'] ),
				'headers' => array( 'X-Batch-Path' => $spec['label'] ),
			);
		}

		$pre_filter = static function ( $result, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, &$parent_pre_hits, &$child_pre_dispatch ) {
			if ( $filter_server !== $server ) {
				return $result;
			}
			if ( '/batch/v1' === $request->get_route() ) {
				++$parent_pre_hits;
				return $result;
			}

			$child_pre_dispatch[] = array(
				'route'      => $request->get_route(),
				'method'     => $request->get_method(),
				'urlParams'  => $request->get_url_params(),
				'attrsEmpty' => array() === $request->get_attributes(),
				'defaults'   => $request->get_default_params(),
				'query'      => $request->get_query_params(),
			);
			return $result;
		};
		$post_filter = static function ( \WP_REST_Response $response, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, &$child_post_dispatch ): \WP_REST_Response {
			if ( $filter_server !== $server || '/batch/v1' === $request->get_route() ) {
				return $response;
			}

			$child_post_dispatch[] = array(
				'route'  => $request->get_route(),
				'method' => $request->get_method(),
				'status' => $response->get_status(),
			);
			$response->header( 'X-Path-Post-Dispatch', 'seen' );
			return $response;
		};

		add_filter( 'rest_pre_dispatch', $pre_filter, 10, 3 );
		add_filter( 'rest_post_dispatch', $post_filter, 10, 3 );
		try {
			$normal_response = $server->dispatch( self::batch_request( $normal_requests, 'normal' ) );
			$normal_data     = $normal_response->get_data();
			$callbacks_after_normal = array_sum( $callback_hits );
			$permissions_after_normal = array_sum( $permission_hits );
			$pre_after_normal      = count( $child_pre_dispatch );
			$post_after_normal     = count( $child_post_dispatch );

			$require_all_requests = array(
				array(
					'method'  => 'POST',
					'path'    => $path . '201?token=' . rawurlencode( $token ) . '&marker=require-valid',
					'body'    => array( 'bodyValue' => 'require-valid' ),
					'headers' => array( 'X-Batch-Path' => 'require-valid' ),
				),
			);
			foreach ( $malformed_paths as $spec ) {
				$require_all_requests[] = array(
					'method'  => 'POST',
					'path'    => $spec['path'],
					'body'    => array( 'bodyValue' => 'require-' . $spec['label'] ),
					'headers' => array( 'X-Batch-Path' => 'require-' . $spec['label'] ),
				);
			}
			$require_all_response = $server->dispatch( self::batch_request( $require_all_requests, 'require-all-validate' ) );
			$require_all_data     = $require_all_response->get_data();
		} finally {
			remove_filter( 'rest_pre_dispatch', $pre_filter, 10 );
			remove_filter( 'rest_post_dispatch', $post_filter, 10 );
		}

		$filter_removed = false === has_filter( 'rest_pre_dispatch', $pre_filter )
			&& false === has_filter( 'rest_post_dispatch', $post_filter );
		$rest_server_restored = $had_rest_server
			? array_key_exists( 'wp_rest_server', $GLOBALS ) && $previous_rest_server === $GLOBALS['wp_rest_server']
			: ! array_key_exists( 'wp_rest_server', $GLOBALS );

		$normal_responses = is_array( $normal_data['responses'] ?? null ) ? $normal_data['responses'] : array();
		$valid_ok         = 207 === $normal_response->get_status()
			&& count( $normal_requests ) === count( $normal_responses );
		$valid_envelope = is_array( $normal_responses[0] ?? null ) ? $normal_responses[0] : array();
		$valid_body     = is_array( $valid_envelope['body'] ?? null ) ? $valid_envelope['body'] : array();
		$valid_headers  = is_array( $valid_envelope['headers'] ?? null ) ? $valid_envelope['headers'] : array();
		$valid_ok       = $valid_ok
			&& 201 === ( $valid_envelope['status'] ?? null )
			&& '101' === (string) ( $valid_headers['X-Batch-Path-Id'] ?? null )
			&& 'seen' === ( $valid_headers['X-Path-Post-Dispatch'] ?? null )
			&& 101 === (int) ( $valid_body['id'] ?? 0 )
			&& $path . '101' === ( $valid_body['route'] ?? null )
			&& 'POST' === ( $valid_body['method'] ?? null )
			&& array( 'id' => 101 ) === ( $valid_body['urlParams'] ?? null )
			&& $token === ( $valid_body['query']['token'] ?? null )
			&& 'valid' === ( $valid_body['query']['marker'] ?? null )
			&& 'body-valid' === ( $valid_body['body']['bodyValue'] ?? null )
			&& 'valid' === ( $valid_body['headers']['x_batch_path'] ?? null );

		$malformed_ok = true;
		foreach ( $malformed_paths as $offset => $spec ) {
			$index    = 1 + $offset;
			$envelope = is_array( $normal_responses[ $index ] ?? null ) ? $normal_responses[ $index ] : array();
			$headers  = is_array( $envelope['headers'] ?? null ) ? $envelope['headers'] : array();
			$malformed_ok = $malformed_ok
				&& 400 === ( $envelope['status'] ?? null )
				&& 'parse_path_failed' === ( $envelope['body']['code'] ?? null )
				&& ! isset( $headers['X-Path-Post-Dispatch'] );
		}

		$pre_routes        = array_column( $child_pre_dispatch, 'route' );
		$post_routes       = array_column( $child_post_dispatch, 'route' );
		$expected_child_routes = array( $path . '101' );
		$filter_locality_ok   = 2 === $parent_pre_hits
			&& count( $expected_child_routes ) === $pre_after_normal
			&& count( $expected_child_routes ) === $post_after_normal
			&& $pre_after_normal === count( $pre_routes )
			&& $post_after_normal === count( $post_routes )
			&& $expected_child_routes === array_slice( $pre_routes, 0, count( $expected_child_routes ) )
			&& $expected_child_routes === array_slice( $post_routes, 0, count( $expected_child_routes ) );

		$require_all_responses = is_array( $require_all_data['responses'] ?? null ) ? $require_all_data['responses'] : array();
		$require_all_ok        = 207 === $require_all_response->get_status()
			&& 'validation' === ( $require_all_data['failed'] ?? null )
			&& count( $require_all_requests ) === count( $require_all_responses )
			&& array_key_exists( 0, $require_all_responses )
			&& null === $require_all_responses[0]
			&& $callbacks_after_normal === array_sum( $callback_hits )
			&& $permissions_after_normal === array_sum( $permission_hits )
			&& $pre_after_normal === count( $child_pre_dispatch )
			&& $post_after_normal === count( $child_post_dispatch );
		for ( $i = 1; $i < count( $require_all_requests ); ++$i ) {
			$require_all_ok = $require_all_ok
				&& 400 === ( $require_all_responses[ $i ]['status'] ?? null )
				&& 'parse_path_failed' === ( $require_all_responses[ $i ]['body']['code'] ?? null )
				&& array() === ( $require_all_responses[ $i ]['headers'] ?? null );
		}

		$callback_ok = 1 === $callbacks_after_normal
			&& 1 === $permissions_after_normal
			&& 1 === count( $seen_requests );

		$ok = $valid_ok
			&& $malformed_ok
			&& $filter_locality_ok
			&& $require_all_ok
			&& $callback_ok
			&& $filter_removed
			&& $rest_server_restored;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'REST batch/v1 malformed path invariants held.' : 'REST batch/v1 malformed path invariant failed.',
			'features' => array( 'batch-v1', 'path-parsing', 'parse-path-failed', 'require-all-validation', 'filter-cleanup' ),
			'details'  => array(
				'namespace'          => $namespace,
				'token'              => $token,
				'validOk'            => $valid_ok,
				'malformedOk'        => $malformed_ok,
				'filterLocalityOk'   => $filter_locality_ok,
				'requireAllOk'       => $require_all_ok,
				'callbackOk'         => $callback_ok,
				'filterRemoved'      => $filter_removed,
				'restServerRestored' => $rest_server_restored,
				'malformedPaths'     => $malformed_paths,
				'normalResponse'     => self::response_summary( $normal_response ),
				'requireAllResponse' => self::response_summary( $require_all_response ),
				'childPreDispatch'   => $child_pre_dispatch,
				'childPostDispatch'  => $child_post_dispatch,
				'seenRequests'       => $seen_requests,
			),
		);
	}

	private static function check_batch_v1_no_route_child_alignment( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$token     = self::slug_token( $rng, 'noroute' );
		$server    = new \WP_REST_Server();

		$had_rest_server      = array_key_exists( 'wp_rest_server', $GLOBALS );
		$previous_rest_server = $had_rest_server ? $GLOBALS['wp_rest_server'] : null;
		$permission_hits      = array();
		$callback_hits        = array();
		$seen_requests        = array();
		$child_pre_dispatch   = array();
		$child_post_dispatch  = array();
		$parent_pre_hits      = 0;

		$route = '/' . $namespace . '/batch-no-route/(?P<id>[0-9]+)';
		$path  = '/' . $namespace . '/batch-no-route/';

		$server->register_route(
			$namespace,
			$route,
			array(
				'allow_batch' => array( 'v1' => true ),
				array(
					'methods'             => 'POST',
					'permission_callback' => static function ( \WP_REST_Request $request ) use ( &$permission_hits ): bool {
						$route                    = $request->get_route();
						$permission_hits[ $route ] = ( $permission_hits[ $route ] ?? 0 ) + 1;
						return true;
					},
					'callback'            => static function ( \WP_REST_Request $request ) use ( &$callback_hits, &$seen_requests ) {
						$route                 = $request->get_route();
						$method                = $request->get_method();
						$callback_hits[ $route ] = ( $callback_hits[ $route ] ?? 0 ) + 1;
						$seen_requests[]       = array(
							'route'     => $route,
							'method'    => $method,
							'urlParams' => $request->get_url_params(),
							'query'     => $request->get_query_params(),
							'body'      => $request->get_body_params(),
							'headers'   => array(
								'x_no_route' => $request->get_header( 'X-No-Route' ),
							),
						);

						return new \WP_REST_Response(
							array(
								'id'        => $request['id'],
								'route'     => $route,
								'method'    => $method,
								'urlParams' => $request->get_url_params(),
								'query'     => $request->get_query_params(),
								'body'      => $request->get_body_params(),
								'headers'   => array(
									'x_no_route' => $request->get_header( 'X-No-Route' ),
								),
							),
							209,
							array( 'X-No-Route-Valid' => (string) $request['id'] )
						);
					},
					'args'                => array(
						'id'     => array(
							'type' => 'integer',
						),
						'token'  => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^noroute-[a-z0-9]{6}$',
						),
						'marker' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		$server->register_route(
			$namespace,
			'/' . $namespace . '/method-only/(?P<id>[0-9]+)',
			array(
				'allow_batch' => array( 'v1' => true ),
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						return array( 'methodOnly' => true );
					},
				),
			)
		);

		$valid_request = array(
			'method'  => 'POST',
			'path'    => $path . '101?token=' . rawurlencode( $token ) . '&marker=valid&queryOnly=' . rawurlencode( self::slug_token( $rng, 'query' ) ),
			'body'    => array( 'bodyValue' => 'body-valid' ),
			'headers' => array( 'X-No-Route' => 'valid-' . $token ),
		);
		$tail_valid_request = array(
			'method'  => 'POST',
			'path'    => $path . '102?token=' . rawurlencode( $token ) . '&marker=valid-tail',
			'body'    => array( 'bodyValue' => 'body-tail' ),
			'headers' => array( 'X-No-Route' => 'valid-tail-' . $token ),
		);
		$no_route_specs = array(
			array(
				'label'  => 'unknown-namespace',
				'method' => 'POST',
				'path'   => '/' . $namespace . '-missing/nowhere?token=' . rawurlencode( $token ) . '&case=unknown',
			),
			array(
				'label'  => 'nonmatching-regex',
				'method' => 'POST',
				'path'   => $path . 'not-a-number?token=' . rawurlencode( $token ) . '&case=regex',
			),
			array(
				'label'  => 'unsupported-method',
				'method' => 'DELETE',
				'path'   => '/' . $namespace . '/method-only/202?case=method',
			),
			array(
				'label'  => 'absolute-same-host-unknown',
				'method' => 'PATCH',
				'path'   => 'https://example.test/' . $namespace . '/absolute-missing?token=' . rawurlencode( $token ) . '&case=absolute',
			),
			array(
				'label'  => 'encoded-space-path',
				'method' => 'PUT',
				'path'   => '/' . $namespace . '/space%20path/' . self::slug_token( $rng, 'missing' ) . '?case=encoded-space',
			),
			array(
				'label'  => 'protocol-relative-unknown',
				'method' => 'POST',
				'path'   => '//example.test/' . $namespace . '/protocol-relative-missing?case=protocol',
			),
		);

		$normal_requests = array( $valid_request );
		foreach ( $no_route_specs as $spec ) {
			$normal_requests[] = array(
				'method'  => $spec['method'],
				'path'    => $spec['path'],
				'body'    => array( 'bodyValue' => 'body-' . $spec['label'] ),
				'headers' => array( 'X-No-Route' => $spec['label'] ),
			);
		}
		$normal_requests[] = $tail_valid_request;

		$pre_filter = static function ( $result, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, &$parent_pre_hits, &$child_pre_dispatch ) {
			if ( $filter_server !== $server ) {
				return $result;
			}
			if ( '/batch/v1' === $request->get_route() ) {
				++$parent_pre_hits;
				return $result;
			}

			$child_pre_dispatch[] = array(
				'route'      => $request->get_route(),
				'method'     => $request->get_method(),
				'urlParams'  => $request->get_url_params(),
				'attrsEmpty' => array() === $request->get_attributes(),
				'defaults'   => $request->get_default_params(),
				'query'      => $request->get_query_params(),
				'body'       => $request->get_body_params(),
				'headers'    => array(
					'x_no_route' => $request->get_header( 'X-No-Route' ),
				),
			);
			return $result;
		};
		$post_filter = static function ( \WP_REST_Response $response, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, &$child_post_dispatch ): \WP_REST_Response {
			if ( $filter_server !== $server || '/batch/v1' === $request->get_route() ) {
				return $response;
			}

			$child_post_dispatch[] = array(
				'route'  => $request->get_route(),
				'method' => $request->get_method(),
				'status' => $response->get_status(),
			);
			$response->header( 'X-No-Route-Post', 'seen-' . count( $child_post_dispatch ) );
			return $response;
		};

		add_filter( 'rest_pre_dispatch', $pre_filter, 10, 3 );
		add_filter( 'rest_post_dispatch', $post_filter, 10, 3 );
		try {
			$normal_response = $server->dispatch( self::batch_request( $normal_requests, 'normal' ) );
			$normal_data     = $normal_response->get_data();
			$callbacks_after_normal = array_sum( $callback_hits );
			$permissions_after_normal = array_sum( $permission_hits );
			$pre_after_normal      = count( $child_pre_dispatch );
			$post_after_normal     = count( $child_post_dispatch );

			$require_all_requests = array(
				array(
					'method'  => 'POST',
					'path'    => $path . '301?token=' . rawurlencode( $token ) . '&marker=require-valid',
					'body'    => array( 'bodyValue' => 'require-valid' ),
					'headers' => array( 'X-No-Route' => 'require-valid' ),
				),
			);
			foreach ( $no_route_specs as $spec ) {
				$require_all_requests[] = array(
					'method'  => $spec['method'],
					'path'    => $spec['path'] . ( false === strpos( $spec['path'], '?' ) ? '?' : '&' ) . 'require=1',
					'body'    => array( 'bodyValue' => 'require-' . $spec['label'] ),
					'headers' => array( 'X-No-Route' => 'require-' . $spec['label'] ),
				);
			}
			$require_all_requests[] = array(
				'method'  => 'POST',
				'path'    => $path . '302?token=' . rawurlencode( $token ) . '&marker=require-tail',
				'body'    => array( 'bodyValue' => 'require-tail' ),
				'headers' => array( 'X-No-Route' => 'require-tail' ),
			);
			$require_all_response = $server->dispatch( self::batch_request( $require_all_requests, 'require-all-validate' ) );
			$require_all_data     = $require_all_response->get_data();
		} finally {
			remove_filter( 'rest_pre_dispatch', $pre_filter, 10 );
			remove_filter( 'rest_post_dispatch', $post_filter, 10 );
		}

		$filter_removed = false === has_filter( 'rest_pre_dispatch', $pre_filter )
			&& false === has_filter( 'rest_post_dispatch', $post_filter );
		$rest_server_restored = $had_rest_server
			? array_key_exists( 'wp_rest_server', $GLOBALS ) && $previous_rest_server === $GLOBALS['wp_rest_server']
			: ! array_key_exists( 'wp_rest_server', $GLOBALS );

		$normal_responses = is_array( $normal_data['responses'] ?? null ) ? $normal_data['responses'] : array();
		$valid_envelope   = is_array( $normal_responses[0] ?? null ) ? $normal_responses[0] : array();
		$valid_body       = is_array( $valid_envelope['body'] ?? null ) ? $valid_envelope['body'] : array();
		$valid_headers    = is_array( $valid_envelope['headers'] ?? null ) ? $valid_envelope['headers'] : array();
		$valid_ok         = 207 === $normal_response->get_status()
			&& count( $normal_requests ) === count( $normal_responses )
			&& 209 === ( $valid_envelope['status'] ?? null )
			&& '101' === (string) ( $valid_headers['X-No-Route-Valid'] ?? null )
			&& 'seen-1' === ( $valid_headers['X-No-Route-Post'] ?? null )
			&& 101 === (int) ( $valid_body['id'] ?? 0 )
			&& $path . '101' === ( $valid_body['route'] ?? null )
			&& 'POST' === ( $valid_body['method'] ?? null )
			&& array( 'id' => 101 ) === ( $valid_body['urlParams'] ?? null )
			&& $token === ( $valid_body['query']['token'] ?? null )
			&& 'valid' === ( $valid_body['query']['marker'] ?? null )
			&& 'body-valid' === ( $valid_body['body']['bodyValue'] ?? null )
			&& 'valid-' . $token === ( $valid_body['headers']['x_no_route'] ?? null );
		$tail_index    = count( $normal_requests ) - 1;
		$tail_envelope = is_array( $normal_responses[ $tail_index ] ?? null ) ? $normal_responses[ $tail_index ] : array();
		$tail_body     = is_array( $tail_envelope['body'] ?? null ) ? $tail_envelope['body'] : array();
		$tail_headers  = is_array( $tail_envelope['headers'] ?? null ) ? $tail_envelope['headers'] : array();
		$valid_ok      = $valid_ok
			&& 209 === ( $tail_envelope['status'] ?? null )
			&& '102' === (string) ( $tail_headers['X-No-Route-Valid'] ?? null )
			&& 'seen-' . count( $normal_requests ) === ( $tail_headers['X-No-Route-Post'] ?? null )
			&& 102 === (int) ( $tail_body['id'] ?? 0 )
			&& $path . '102' === ( $tail_body['route'] ?? null )
			&& 'POST' === ( $tail_body['method'] ?? null )
			&& array( 'id' => 102 ) === ( $tail_body['urlParams'] ?? null )
			&& $token === ( $tail_body['query']['token'] ?? null )
			&& 'valid-tail' === ( $tail_body['query']['marker'] ?? null )
			&& 'body-tail' === ( $tail_body['body']['bodyValue'] ?? null )
			&& 'valid-tail-' . $token === ( $tail_body['headers']['x_no_route'] ?? null );

		$no_route_ok = true;
		foreach ( $no_route_specs as $offset => $spec ) {
			$index    = 1 + $offset;
			$envelope = is_array( $normal_responses[ $index ] ?? null ) ? $normal_responses[ $index ] : array();
			$headers  = is_array( $envelope['headers'] ?? null ) ? $envelope['headers'] : array();
			$body     = is_array( $envelope['body'] ?? null ) ? $envelope['body'] : array();
			$no_route_ok = $no_route_ok
				&& 404 === ( $envelope['status'] ?? null )
				&& 'rest_no_route' === ( $body['code'] ?? null )
				&& 404 === ( $body['data']['status'] ?? null )
				&& 'seen-' . ( $index + 1 ) === ( $headers['X-No-Route-Post'] ?? null );
		}

		$pre_routes     = array_column( $child_pre_dispatch, 'route' );
		$post_routes    = array_column( $child_post_dispatch, 'route' );
		$expected_routes = array_map(
			static function ( array $request ): string {
				$parsed = \wp_parse_url( $request['path'] );
				return is_array( $parsed ) ? (string) ( $parsed['path'] ?? '' ) : '';
			},
			$normal_requests
		);
		$filter_locality_ok = 2 === $parent_pre_hits
			&& count( $normal_requests ) === $pre_after_normal
			&& count( $normal_requests ) === $post_after_normal
			&& $expected_routes === $pre_routes
			&& $expected_routes === $post_routes;
		foreach ( $child_pre_dispatch as $index => $seen ) {
			$filter_locality_ok = $filter_locality_ok
				&& true === ( $seen['attrsEmpty'] ?? null )
				&& array() === ( $seen['defaults'] ?? null )
				&& array() === ( $seen['urlParams'] ?? null )
				&& ( $normal_requests[ $index ]['method'] ?? null ) === ( $seen['method'] ?? null )
				&& ( $normal_requests[ $index ]['body']['bodyValue'] ?? null ) === ( $seen['body']['bodyValue'] ?? null )
				&& ( $normal_requests[ $index ]['headers']['X-No-Route'] ?? null ) === ( $seen['headers']['x_no_route'] ?? null );
		}

		$require_all_responses = is_array( $require_all_data['responses'] ?? null ) ? $require_all_data['responses'] : array();
		$require_all_ok        = 207 === $require_all_response->get_status()
			&& 'validation' === ( $require_all_data['failed'] ?? null )
			&& count( $require_all_requests ) === count( $require_all_responses )
			&& array_key_exists( 0, $require_all_responses )
			&& null === $require_all_responses[0]
			&& $callbacks_after_normal === array_sum( $callback_hits )
			&& $permissions_after_normal === array_sum( $permission_hits )
			&& $pre_after_normal === count( $child_pre_dispatch )
			&& $post_after_normal === count( $child_post_dispatch );
		for ( $i = 1; $i < count( $require_all_requests ) - 1; ++$i ) {
			$require_all_ok = $require_all_ok
				&& 404 === ( $require_all_responses[ $i ]['status'] ?? null )
				&& 'rest_no_route' === ( $require_all_responses[ $i ]['body']['code'] ?? null )
				&& 404 === ( $require_all_responses[ $i ]['body']['data']['status'] ?? null )
				&& array() === ( $require_all_responses[ $i ]['headers'] ?? null );
		}
		$require_all_ok = $require_all_ok
			&& array_key_exists( count( $require_all_requests ) - 1, $require_all_responses )
			&& null === $require_all_responses[ count( $require_all_requests ) - 1 ];

		$callback_ok = 2 === $callbacks_after_normal
			&& 2 === $permissions_after_normal
			&& 2 === count( $seen_requests );

		$ok = $valid_ok
			&& $no_route_ok
			&& $filter_locality_ok
			&& $require_all_ok
			&& $callback_ok
			&& $filter_removed
			&& $rest_server_restored;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'REST batch/v1 no-route child alignment invariants held.' : 'REST batch/v1 no-route child alignment invariant failed.',
			'features' => array( 'batch-v1', 'rest-no-route', 'normal-child-filter-dispatch', 'require-all-validation', 'filter-cleanup' ),
			'details'  => array(
				'namespace'          => $namespace,
				'token'              => $token,
				'validOk'            => $valid_ok,
				'noRouteOk'          => $no_route_ok,
				'filterLocalityOk'   => $filter_locality_ok,
				'requireAllOk'       => $require_all_ok,
				'callbackOk'         => $callback_ok,
				'filterRemoved'      => $filter_removed,
				'restServerRestored' => $rest_server_restored,
				'noRouteSpecs'       => $no_route_specs,
				'expectedRoutes'     => $expected_routes,
				'normalResponse'     => self::response_summary( $normal_response ),
				'requireAllResponse' => self::response_summary( $require_all_response ),
				'childPreDispatch'   => $child_pre_dispatch,
				'childPostDispatch'  => $child_post_dispatch,
				'seenRequests'       => $seen_requests,
			),
		);
	}

	private static function check_batch_v1_pre_dispatch_short_circuit( array &$rng ): array {
		$namespace = self::namespace_token( $rng );
		$token     = self::slug_token( $rng, 'pre' );
		$server    = new \WP_REST_Server();

		$had_rest_server      = array_key_exists( 'wp_rest_server', $GLOBALS );
		$previous_rest_server = $had_rest_server ? $GLOBALS['wp_rest_server'] : null;
		$permission_hits      = 0;
		$callback_hits        = 0;
		$parent_pre_hits      = 0;
		$child_pre_dispatch   = array();
		$child_post_dispatch  = array();

		$route = '/' . $namespace . '/short/(?P<id>[0-9]+)';
		$path  = '/' . $namespace . '/short/';
		$server->register_route(
			$namespace,
			$route,
			array(
				'allow_batch' => array( 'v1' => true ),
				array(
					'methods'             => 'POST',
					'permission_callback' => static function () use ( &$permission_hits ) {
						++$permission_hits;
						return true;
					},
					'callback'            => static function ( \WP_REST_Request $request ) use ( &$callback_hits ) {
						++$callback_hits;
						return new \WP_REST_Response(
							array(
								'callback' => true,
								'id'       => $request['id'],
							),
							208,
							array( 'X-Callback' => 'hit' )
						);
					},
					'args'                => array(
						'id'        => array(
							'type' => 'integer',
						),
						'token'     => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^pre-[a-z0-9]{6}$',
						),
						'bodyValue' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		$pre_filter = static function ( $result, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, $path, $token, &$parent_pre_hits, &$child_pre_dispatch ) {
			if ( $filter_server !== $server ) {
				return $result;
			}
			if ( '/batch/v1' === $request->get_route() ) {
				++$parent_pre_hits;
				return $result;
			}

			$child_pre_dispatch[] = array(
				'route'      => $request->get_route(),
				'method'     => $request->get_method(),
				'urlParams'  => $request->get_url_params(),
				'attrsEmpty' => array() === $request->get_attributes(),
				'defaults'   => $request->get_default_params(),
				'query'      => $request->get_query_params(),
				'body'       => $request->get_body_params(),
			);

			if ( $path . '101' !== $request->get_route() || $token !== $request['token'] ) {
				return $result;
			}

			return new \WP_REST_Response(
				array(
					'shortCircuited' => true,
					'route'          => $request->get_route(),
					'method'         => $request->get_method(),
					'idParam'        => $request['id'],
					'token'          => $request['token'],
					'bodyValue'      => $request['bodyValue'],
					'query'          => $request->get_query_params(),
					'body'           => $request->get_body_params(),
					'headers'        => array(
						'x_short' => $request->get_header_as_array( 'X-Short' ),
					),
				),
				206,
				array( 'X-Pre-Dispatch' => 'short' )
			);
		};
		$post_filter = static function ( \WP_REST_Response $response, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, &$child_post_dispatch ) {
			if ( $filter_server !== $server || '/batch/v1' === $request->get_route() ) {
				return $response;
			}

			$child_post_dispatch[] = array(
				'route'  => $request->get_route(),
				'method' => $request->get_method(),
				'status' => $response->get_status(),
			);
			$response->header( 'X-Post-Dispatch', 'pre-short' );
			return $response;
		};

		add_filter( 'rest_pre_dispatch', $pre_filter, 10, 3 );
		add_filter( 'rest_post_dispatch', $post_filter, 10, 3 );
		try {
			$normal_response = $server->dispatch(
				self::batch_request(
					array(
						array(
							'method'  => 'POST',
							'path'    => $path . '101?token=' . rawurlencode( $token ) . '&queryOnly=short',
							'body'    => array( 'bodyValue' => 'short-body' ),
							'headers' => array( 'X-Short' => array( 'first', $token ) ),
						),
						array(
							'method' => 'POST',
							'path'   => $path . '102?token=bad%20token',
							'body'   => array( 'bodyValue' => 'invalid-body' ),
						),
					),
					'normal'
				)
			);
			$normal_data = $normal_response->get_data();

			$pre_after_normal      = count( $child_pre_dispatch );
			$post_after_normal     = count( $child_post_dispatch );
			$permission_after_norm = $permission_hits;
			$callback_after_norm   = $callback_hits;

			$require_all_response = $server->dispatch(
				self::batch_request(
					array(
						array(
							'method' => 'POST',
							'path'   => $path . '103?token=' . rawurlencode( $token ),
							'body'   => array( 'bodyValue' => 'require-all-valid' ),
						),
						array(
							'method' => 'POST',
							'path'   => $path . '104?token=bad%20token',
							'body'   => array( 'bodyValue' => 'require-all-invalid' ),
						),
					),
					'require-all-validate'
				)
			);
			$require_all_data = $require_all_response->get_data();
		} finally {
			remove_filter( 'rest_pre_dispatch', $pre_filter, 10 );
			remove_filter( 'rest_post_dispatch', $post_filter, 10 );
		}

		$filter_removed = false === has_filter( 'rest_pre_dispatch', $pre_filter )
			&& false === has_filter( 'rest_post_dispatch', $post_filter );
		$rest_server_restored = $had_rest_server
			? array_key_exists( 'wp_rest_server', $GLOBALS ) && $previous_rest_server === $GLOBALS['wp_rest_server']
			: ! array_key_exists( 'wp_rest_server', $GLOBALS );

		$normal_responses = is_array( $normal_data['responses'] ?? null ) ? $normal_data['responses'] : array();
		$short_envelope   = $normal_responses[0] ?? array();
		$short_body       = is_array( $short_envelope['body'] ?? null ) ? $short_envelope['body'] : array();
		$short_headers    = is_array( $short_envelope['headers'] ?? null ) ? $short_envelope['headers'] : array();
		$error_envelope   = $normal_responses[1] ?? array();
		$error_headers    = is_array( $error_envelope['headers'] ?? null ) ? $error_envelope['headers'] : array();

		$short_circuit_ok = 207 === $normal_response->get_status()
			&& 2 === count( $normal_responses )
			&& 206 === ( $short_envelope['status'] ?? null )
			&& true === ( $short_body['shortCircuited'] ?? null )
			&& $path . '101' === ( $short_body['route'] ?? null )
			&& 'POST' === ( $short_body['method'] ?? null )
			&& null === ( $short_body['idParam'] ?? null )
			&& $token === ( $short_body['token'] ?? null )
			&& 'short-body' === ( $short_body['bodyValue'] ?? null )
			&& $token === ( $short_body['query']['token'] ?? null )
			&& 'short' === ( $short_body['query']['queryOnly'] ?? null )
			&& 'short-body' === ( $short_body['body']['bodyValue'] ?? null )
			&& array( 'first', $token ) === ( $short_body['headers']['x_short'] ?? null )
			&& 'short' === ( $short_headers['X-Pre-Dispatch'] ?? null )
			&& 'pre-short' === ( $short_headers['X-Post-Dispatch'] ?? null );

		$normal_error_ok = 400 === ( $error_envelope['status'] ?? null )
			&& 'rest_invalid_param' === ( $error_envelope['body']['code'] ?? null )
			&& 'pre-short' === ( $error_headers['X-Post-Dispatch'] ?? null );

		$pre_dispatch_ok = 2 === $pre_after_normal
			&& $path . '101' === ( $child_pre_dispatch[0]['route'] ?? null )
			&& $path . '102' === ( $child_pre_dispatch[1]['route'] ?? null )
			&& 'POST' === ( $child_pre_dispatch[0]['method'] ?? null )
			&& 'POST' === ( $child_pre_dispatch[1]['method'] ?? null )
			&& array() === ( $child_pre_dispatch[0]['urlParams'] ?? null )
			&& array() === ( $child_pre_dispatch[1]['urlParams'] ?? null )
			&& true === ( $child_pre_dispatch[0]['attrsEmpty'] ?? null )
			&& true === ( $child_pre_dispatch[1]['attrsEmpty'] ?? null )
			&& array() === ( $child_pre_dispatch[0]['defaults'] ?? null )
			&& array() === ( $child_pre_dispatch[1]['defaults'] ?? null )
			&& $token === ( $child_pre_dispatch[0]['query']['token'] ?? null )
			&& 'bad token' === ( $child_pre_dispatch[1]['query']['token'] ?? null );

		$post_dispatch_ok = 2 === $post_after_normal
			&& $path . '101' === ( $child_post_dispatch[0]['route'] ?? null )
			&& 206 === ( $child_post_dispatch[0]['status'] ?? null )
			&& $path . '102' === ( $child_post_dispatch[1]['route'] ?? null )
			&& 400 === ( $child_post_dispatch[1]['status'] ?? null );

		$require_all_responses = is_array( $require_all_data['responses'] ?? null ) ? $require_all_data['responses'] : array();
		$require_all_ok        = 207 === $require_all_response->get_status()
			&& 'validation' === ( $require_all_data['failed'] ?? null )
			&& array_key_exists( 0, $require_all_responses )
			&& null === $require_all_responses[0]
			&& 400 === ( $require_all_responses[1]['status'] ?? null )
			&& 'rest_invalid_param' === ( $require_all_responses[1]['body']['code'] ?? null )
			&& $pre_after_normal === count( $child_pre_dispatch )
			&& $post_after_normal === count( $child_post_dispatch );

		$callback_ok = 0 === $permission_after_norm
			&& 0 === $callback_after_norm
			&& 0 === $permission_hits
			&& 0 === $callback_hits;

		$ok = $short_circuit_ok
			&& $normal_error_ok
			&& $pre_dispatch_ok
			&& $post_dispatch_ok
			&& $require_all_ok
			&& $callback_ok
			&& 2 === $parent_pre_hits
			&& $filter_removed
			&& $rest_server_restored;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'REST batch/v1 pre-dispatch short-circuit invariants held.' : 'REST batch/v1 pre-dispatch short-circuit invariant failed.',
			'features' => array( 'batch-v1', 'rest-pre-dispatch', 'rest-post-dispatch', 'batch-validation', 'batch-envelopes', 'filter-cleanup' ),
			'details'  => array(
				'namespace'          => $namespace,
				'token'              => $token,
				'shortCircuitOk'     => $short_circuit_ok,
				'normalErrorOk'      => $normal_error_ok,
				'preDispatchOk'      => $pre_dispatch_ok,
				'postDispatchOk'     => $post_dispatch_ok,
				'requireAllOk'       => $require_all_ok,
				'callbackOk'         => $callback_ok,
				'parentPreHits'      => $parent_pre_hits,
				'filterRemoved'      => $filter_removed,
				'restServerRestored' => $rest_server_restored,
				'normalResponse'     => self::response_summary( $normal_response ),
				'requireAllResponse' => self::response_summary( $require_all_response ),
				'permissionHits'     => $permission_hits,
				'callbackHits'       => $callback_hits,
				'childPreDispatch'   => $child_pre_dispatch,
				'childPostDispatch'  => $child_post_dispatch,
			),
		);
	}

	private static function check_response_links_envelope( array &$rng ): array {
		$namespace    = self::namespace_token( $rng );
		$token        = self::slug_token( $rng, 'resp' );
		$id           = (string) self::rng_int( $rng, 100, 999 );
		$server       = new \WP_REST_Server();
		$embed_hits   = 0;
		$envelope_hits = 0;
		$target_hint_permission_hits = 0;

		$item_route = '/' . $namespace . '/items/(?P<id>[0-9]+)';
		$server->register_route(
			$namespace,
			$item_route,
			array(
				array(
					'methods'             => 'GET, POST',
					'permission_callback' => function () use ( &$target_hint_permission_hits ) {
						++$target_hint_permission_hits;
						return true;
					},
					'callback'            => function ( $request ) {
						return array(
							'id'      => $request['id'],
							'context' => $request['context'],
						);
					},
				),
			)
		);

		$embedded_route = '/' . $namespace . '/embedded/(?P<id>[0-9]+)';
		$server->register_route(
			$namespace,
			$embedded_route,
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return true;
					},
					'callback'            => function ( $request ) use ( &$embed_hits, $token ) {
						++$embed_hits;
						return new \WP_REST_Response(
							array(
								'embeddedId' => $request['id'],
								'context'    => $request['context'],
								'perPage'    => $request['per_page'],
								'token'      => $token,
							),
							207,
							array( 'X-Embedded' => 'yes' )
						);
					},
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'maximum' => 7,
						),
					),
				),
			)
		);

		$self_href       = \rest_url( $namespace . '/items/' . $id );
		$self_auto_href  = $self_href;
		$collection_href = \rest_url( $namespace . '/items' );
		$temp_href       = \rest_url( $namespace . '/items/temp-' . $id );
		$embedded_href   = \rest_url( $namespace . '/embedded/' . $id );
		$wp_term_href    = \rest_url( $namespace . '/terms/' . $id );
		$wp_term_two     = \rest_url( $namespace . '/terms/' . ( (int) $id + 1 ) );

		$response = new \WP_REST_Response(
			array(
				'id'    => $id,
				'token' => $token,
			),
			202,
			array( 'X-Fuzz-Header' => 'initial' )
		);
		$response->header( 'X-Fuzz-Header', 'replacement' );
		$response->header( 'X-Fuzz-Trace', 'one' );
		$response->header( 'X-Fuzz-Trace', 'two', false );
		$response->link_header( 'alternate', 'https://example.test/rest/' . rawurlencode( $token ), array( 'title' => 'Alt ' . $token ) );
		$response->link_header( 'describedby', 'https://example.test/rest/schema/' . rawurlencode( $token ), array( 'type' => 'application/schema+json' ) );
		$response->add_link(
			'self',
			$self_href,
			array(
				'href'        => 'ignored-by-add-link',
				'title'       => 'Self ' . $token,
				'targetHints' => array( 'allow' => array( 'GET', 'HEAD' ) ),
			)
		);
		$response->add_link(
			'self',
			$self_auto_href,
			array(
				'title' => 'Self Auto ' . $token,
			)
		);
		$response->add_link( 'collection', $temp_href, array( 'title' => 'Removed ' . $token ) );
		$response->add_links(
			array(
				'collection'            => array(
					'href'  => $collection_href,
					'title' => 'Collection ' . $token,
				),
				'https://api.w.org/term' => array(
					array(
						'href'     => $wp_term_href,
						'taxonomy' => 'category',
					),
					array(
						'href'     => $wp_term_two,
						'taxonomy' => 'post_tag',
					),
				),
			)
		);
		$response->remove_link( 'collection', $temp_href );
		$response->add_link( 'related', $embedded_href, array( 'embeddable' => true ) );
		$response->add_link( 'author', 'https://example.invalid/not-rest/' . rawurlencode( $id ), array( 'embeddable' => true ) );

		$envelope_filter = function ( array $envelope, \WP_REST_Response $original ) use ( &$envelope_hits, $response, $token ): array {
			++$envelope_hits;
			if ( $original === $response ) {
				$envelope['headers']['X-Envelope-Token'] = $token;
				$envelope['body']['envelopeToken']       = $token;
			}
			return $envelope;
		};

		$had_rest_server      = array_key_exists( 'wp_rest_server', $GLOBALS );
		$previous_rest_server = $had_rest_server ? $GLOBALS['wp_rest_server'] : null;
		$GLOBALS['wp_rest_server'] = $server;

		\add_filter( 'rest_envelope_response', $envelope_filter, 10, 2 );
		try {
			$links          = \WP_REST_Server::get_response_links( $response );
			$target_hint_hits_after_links = $target_hint_permission_hits;
			$compact_links  = \WP_REST_Server::get_compact_response_links( $response );
			$data_no_embed  = $server->response_to_data( $response, false );
			$data_embed_all = $server->response_to_data( $response, true );
			$enveloped      = $server->envelope_response( $response, array( 'related' ) );
			$envelope_data  = $enveloped->get_data();
		} finally {
			\remove_filter( 'rest_envelope_response', $envelope_filter, 10 );
			if ( $had_rest_server ) {
				$GLOBALS['wp_rest_server'] = $previous_rest_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		$filter_removed = false === \has_filter( 'rest_envelope_response', $envelope_filter );
		$rest_server_restored = $had_rest_server
			? array_key_exists( 'wp_rest_server', $GLOBALS ) && $previous_rest_server === $GLOBALS['wp_rest_server']
			: ! array_key_exists( 'wp_rest_server', $GLOBALS );
		$headers         = $response->get_headers();
		$ensured_same    = \rest_ensure_response( $response );
		$http_response   = new \WP_HTTP_Response( array( 'converted' => $token ), 206, array( 'X-Converted' => $token ) );
		$converted       = \rest_ensure_response( $http_response );
		$embedded        = $envelope_data['body']['_embedded']['related'][0] ?? null;
		$embedded_all    = $data_embed_all['_embedded']['related'][0] ?? null;
		$links_in_body   = $data_no_embed['_links'] ?? array();
		$envelope_body   = $envelope_data['body'] ?? array();
		$envelope_headers = $envelope_data['headers'] ?? array();

		$headers_ok = array(
			'replacedHeader' => 'replacement' === ( $headers['X-Fuzz-Header'] ?? null ),
			'appendedHeader' => 'one, two' === ( $headers['X-Fuzz-Trace'] ?? null ),
			'linkAlternate'  => isset( $headers['Link'] ) && str_contains( $headers['Link'], 'rel="alternate"' ) && str_contains( $headers['Link'], 'title="Alt ' . $token . '"' ),
			'linkDescribed'  => isset( $headers['Link'] ) && str_contains( $headers['Link'], 'rel="describedby"' ) && str_contains( $headers['Link'], 'type=application/schema+json' ),
		);
		$links_ok   = array(
			'selfHref'          => $self_href === ( $links['self'][0]['href'] ?? null ),
			'selfAutoHref'      => $self_auto_href === ( $links['self'][1]['href'] ?? null ),
			'selfRecordFlattened' => ! isset( $links['self'][0]['attributes'] ),
			'selfTargetHints'   => array( 'GET', 'HEAD' ) === ( $links['self'][0]['targetHints']['allow'] ?? null ),
			'selfAutomaticTargetHints' => array( 'GET', 'POST' ) === ( $links['self'][1]['targetHints']['allow'] ?? null )
				&& 2 === $target_hint_hits_after_links,
			'collectionRemoved' => array( $collection_href ) === array_column( $links['collection'] ?? array(), 'href' ),
			'curieCompacted'    => isset( $compact_links['wp:term'], $compact_links['curies'][0] )
				&& ! isset( $compact_links['https://api.w.org/term'] )
				&& array( $wp_term_href, $wp_term_two ) === array_column( $compact_links['wp:term'], 'href' )
				&& 'wp' === ( $compact_links['curies'][0]['name'] ?? null )
				&& true === ( $compact_links['curies'][0]['templated'] ?? null ),
			'bodyLinksCompact'  => isset( $links_in_body['wp:term'], $links_in_body['related'], $links_in_body['curies'] )
				&& $embedded_href === ( $links_in_body['related'][0]['href'] ?? null )
				&& true === ( $links_in_body['related'][0]['embeddable'] ?? null ),
			'noEmbedWhenFalse'  => ! isset( $data_no_embed['_embedded'] ),
		);
		$embed_ok = array(
			'relatedEmbeddedAll' => is_array( $embedded_all )
				&& $id === ( $embedded_all['embeddedId'] ?? null )
				&& 'embed' === ( $embedded_all['context'] ?? null )
				&& 7 === ( $embedded_all['perPage'] ?? null )
				&& $token === ( $embedded_all['token'] ?? null ),
			'externalAuthorRejected' => isset( $data_embed_all['_links']['author'][0]['href'] )
				&& true === ( $data_embed_all['_links']['author'][0]['embeddable'] ?? null )
				&& ! isset( $data_embed_all['_embedded']['author'] ),
		);
		$envelope_ok = array(
			'envelopeResponseClass' => $enveloped instanceof \WP_REST_Response,
			'envelopeHttpStatus'    => 200 === $enveloped->get_status(),
			'originalStatus'        => 202 === ( $envelope_data['status'] ?? null ),
			'bodyPreserved'         => $id === ( $envelope_body['id'] ?? null ) && $token === ( $envelope_body['token'] ?? null ),
			'filterMutated'         => 1 === $envelope_hits
				&& $token === ( $envelope_headers['X-Envelope-Token'] ?? null )
				&& $token === ( $envelope_body['envelopeToken'] ?? null ),
			'filterRemoved'         => $filter_removed,
			'restServerRestored'    => $rest_server_restored,
			'headersPreserved'      => 'replacement' === ( $envelope_headers['X-Fuzz-Header'] ?? null )
				&& 'one, two' === ( $envelope_headers['X-Fuzz-Trace'] ?? null )
				&& isset( $envelope_headers['Link'] ),
			'relatedEmbedded'       => 2 === $embed_hits
				&& is_array( $embedded )
				&& $id === ( $embedded['embeddedId'] ?? null )
				&& 'embed' === ( $embedded['context'] ?? null )
				&& 7 === ( $embedded['perPage'] ?? null )
				&& $token === ( $embedded['token'] ?? null ),
			'authorNotEmbedded'     => ! isset( $envelope_body['_embedded']['author'] ),
		);
		$conversion_ok = array(
			'restResponseIdentity' => $ensured_same === $response,
			'httpResponseWrapped'  => $converted instanceof \WP_REST_Response
				&& 206 === $converted->get_status()
				&& array( 'converted' => $token ) === $converted->get_data()
				&& array( 'X-Converted' => $token ) === $converted->get_headers(),
		);

		$ok = self::all_true( $headers_ok )
			&& self::all_true( $links_ok )
			&& self::all_true( $embed_ok )
			&& self::all_true( $envelope_ok )
			&& self::all_true( $conversion_ok );

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'REST response links, envelopes, embedding, headers, and conversions stayed stable.' : 'REST response link/envelope invariant failed.',
			'features' => array( 'response-links', 'curies', 'response-headers', 'embedding', 'envelopes', 'response-conversion' ),
			'details'  => array(
				'namespace'    => $namespace,
				'headersOk'    => $headers_ok,
				'linksOk'      => $links_ok,
				'embedOk'      => $embed_ok,
				'envelopeOk'   => $envelope_ok,
				'conversionOk' => $conversion_ok,
				'links'        => $links,
				'compactLinks' => $compact_links,
				'embedAll'     => $data_embed_all,
				'envelope'     => $envelope_data,
				'targetHintPermissionHits' => $target_hint_permission_hits,
				'targetHintHitsAfterLinks' => $target_hint_hits_after_links,
			),
		);
	}

	private static function run_check( string $name, string $invariant, callable $callback ): array {
		$php_errors = array();
		set_error_handler(
			function ( $severity, $message, $file, $line ) use ( &$php_errors ) {
				$php_errors[] = array(
					'severity' => $severity,
					'message'  => $message,
					'file'     => basename( (string) $file ),
					'line'     => $line,
				);
				return true;
			}
		);

		try {
			$result = call_user_func( $callback );
			if ( ! is_array( $result ) ) {
				$result = array(
					'ok'      => false,
					'message' => 'Check did not return an array result.',
					'details' => array( 'type' => gettype( $result ) ),
				);
			}
		} catch ( \Throwable $e ) {
			$result = array(
				'ok'      => false,
				'message' => 'Check threw ' . get_class( $e ) . ': ' . $e->getMessage(),
				'details' => array(
					'throwable' => get_class( $e ),
					'file'      => basename( $e->getFile() ),
					'line'      => $e->getLine(),
				),
			);
		} finally {
			restore_error_handler();
		}

		$result['name']      = $name;
		$result['invariant'] = $invariant;
		if ( array() !== $php_errors ) {
			$result['phpErrors'] = $php_errors;
			if ( ! empty( $result['ok'] ) ) {
				$result['ok']      = false;
				$result['message'] = 'Check emitted PHP errors.';
			}
		}

		return self::safe_value( $result );
	}

	private static function install_stateless_filters(): array {
		if ( ! function_exists( 'add_filter' ) ) {
			return array();
		}

		$filters = array();
		self::add_temp_filter(
			$filters,
			'pre_option_permalink_structure',
			function () {
				return '';
			}
		);
		self::add_temp_filter(
			$filters,
			'pre_option_home',
			function () {
				return 'http://component-fuzz.test';
			}
		);
		self::add_temp_filter(
			$filters,
			'pre_option_siteurl',
			function () {
				return 'http://component-fuzz.test';
			}
		);
		self::add_temp_filter(
			$filters,
			'pre_option_blog_charset',
			function () {
				return 'UTF-8';
			}
		);
		self::add_temp_filter(
			$filters,
			'determine_current_user',
			function () {
				return 0;
			}
		);
		if ( function_exists( 'wp_sprintf_l' ) && ( ! function_exists( 'has_filter' ) || false === has_filter( 'wp_sprintf', 'wp_sprintf_l' ) ) ) {
			self::add_temp_filter( $filters, 'wp_sprintf', 'wp_sprintf_l', 10, 2 );
		}

		return $filters;
	}

	private static function add_temp_filter( array &$filters, string $hook, callable $callback, int $priority = 999, int $accepted_args = 1 ): void {
		add_filter( $hook, $callback, $priority, $accepted_args );
		$filters[] = array(
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
		);
	}

	private static function remove_stateless_filters( array $filters ): void {
		if ( ! function_exists( 'remove_filter' ) ) {
			return;
		}

		for ( $i = count( $filters ) - 1; $i >= 0; --$i ) {
			remove_filter( $filters[ $i ]['hook'], $filters[ $i ]['callback'], $filters[ $i ]['priority'] );
		}
	}

	private static function maybe_load_optional_core_support(): void {
		if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		$base = rtrim( (string) ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . trim( (string) WPINC, '/\\' ) . DIRECTORY_SEPARATOR;
		$map  = array(
			'WP_Http'      => 'class-wp-http.php',
			'WP_List_Util' => 'class-wp-list-util.php',
			'WP_User'      => 'class-wp-user.php',
		);

		foreach ( $map as $class => $relative ) {
			$path = $base . $relative;
			if ( ! class_exists( $class ) && is_readable( $path ) ) {
				require_once $path;
			}
		}
		if ( ! function_exists( '_wp_get_current_user' ) ) {
			$user_path = $base . 'user.php';
			if ( is_readable( $user_path ) ) {
				require_once $user_path;
			}
		}
	}

	private static function seed_from_context( $ctx ): int {
		foreach ( array( 'seed', 'getSeed', 'get_seed' ) as $method ) {
			if ( ! method_exists( $ctx, $method ) ) {
				continue;
			}
			try {
				$reflection = new \ReflectionMethod( $ctx, $method );
				if ( $reflection->isPublic() && 0 === $reflection->getNumberOfRequiredParameters() ) {
					$value = $ctx->{$method}();
					if ( is_int( $value ) || is_numeric( $value ) ) {
						return (int) $value;
					}
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		foreach ( get_object_vars( $ctx ) as $key => $value ) {
			if ( in_array( $key, array( 'seed', 'caseSeed', 'iteration' ), true ) && ( is_int( $value ) || is_numeric( $value ) ) ) {
				return (int) $value;
			}
		}

		return 1;
	}

	private static function rng( $seed ): array {
		return array(
			'seed'    => (string) $seed,
			'counter' => 0,
			'buffer'  => '',
		);
	}

	private static function rng_bytes( array &$rng, int $length ): string {
		while ( strlen( $rng['buffer'] ) < $length ) {
			$rng['buffer'] .= hash( 'sha256', $rng['seed'] . ':' . $rng['counter'], true );
			++$rng['counter'];
		}

		$out           = substr( $rng['buffer'], 0, $length );
		$rng['buffer'] = substr( $rng['buffer'], $length );
		return $out;
	}

	private static function rng_uint32( array &$rng ): int {
		$parts = unpack( 'Nvalue', self::rng_bytes( $rng, 4 ) );
		return (int) $parts['value'];
	}

	private static function rng_int( array &$rng, int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}

		return $min + ( self::rng_uint32( $rng ) % ( $max - $min + 1 ) );
	}

	private static function slug_token( array &$rng, string $prefix ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $prefix ) );
		$out      = trim( $out, '-' );
		if ( '' === $out || ! ctype_alpha( $out[0] ) ) {
			$out = 'x' . $out;
		}
		$out .= '-';
		for ( $i = 0; $i < 6; ++$i ) {
			$out .= $alphabet[ self::rng_int( $rng, 0, strlen( $alphabet ) - 1 ) ];
		}

		return $out;
	}

	private static function namespace_token( array &$rng ): string {
		return 'cfuzz-rest/v' . self::rng_int( $rng, 1, 9 ) . '-' . self::slug_token( $rng, 'ns' );
	}

	private static function method_token( array &$rng ): string {
		$methods = array( 'mkcol', 'copy', 'move', 'lock', 'unlock', 'purge' );
		return $methods[ self::rng_int( $rng, 0, count( $methods ) - 1 ) ];
	}

	private static function json_encode_for_body( $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? 'null' : $json;
	}

	private static function batch_request( array $requests, string $validation ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'validation' => $validation,
				'requests'   => $requests,
			)
		);

		return $request;
	}

	private static function response_summary( $response ): array {
		if ( ! is_object( $response ) ) {
			return array( 'type' => gettype( $response ) );
		}

		$summary = array(
			'class' => get_class( $response ),
		);
		if ( method_exists( $response, 'get_status' ) ) {
			$summary['status'] = $response->get_status();
		}
		if ( method_exists( $response, 'get_data' ) ) {
			$summary['data'] = $response->get_data();
		}
		if ( method_exists( $response, 'get_matched_route' ) ) {
			$summary['matchedRoute'] = $response->get_matched_route();
		}

		return $summary;
	}

	private static function route_handler_summary( array $handlers ): array {
		$summary = array();
		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
				continue;
			}

			foreach ( array_keys( $handler['methods'] ) as $method ) {
				$summary[ $method ] = array(
					'args'        => isset( $handler['args'] ) && is_array( $handler['args'] ) ? array_keys( $handler['args'] ) : array(),
					'showInIndex' => $handler['show_in_index'] ?? null,
				);
			}
		}

		ksort( $summary );
		return $summary;
	}

	private static function result_summary( $value ) {
		$error = self::wp_error_summary( $value );
		if ( null !== $error ) {
			return $error;
		}
		if ( true === $value ) {
			return true;
		}
		if ( null === $value ) {
			return null;
		}

		return $value;
	}

	private static function wp_error_summary( $value ): ?array {
		if ( ! function_exists( 'is_wp_error' ) || ! \is_wp_error( $value ) ) {
			return null;
		}

		$codes = $value->get_error_codes();
		$code  = $codes ? $codes[0] : '';
		return array(
			'code'     => $code,
			'message'  => $code ? $value->get_error_message( $code ) : $value->get_error_message(),
			'data'     => $code ? $value->get_error_data( $code ) : null,
			'allCodes' => $codes,
		);
	}

	private static function values_equal( $a, $b ): bool {
		return serialize( self::canonical_value( $a ) ) === serialize( self::canonical_value( $b ) );
	}

	private static function canonical_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::canonical_value( $item );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return array(
				'__class' => get_class( $value ),
				'vars'    => self::canonical_value( get_object_vars( $value ) ),
			);
		}

		return $value;
	}

	private static function all_true( array $values ): bool {
		foreach ( $values as $value ) {
			if ( true !== $value ) {
				return false;
			}
		}

		return true;
	}

	private static function features_from_checks( array $checks ): array {
		$features = array();
		foreach ( $checks as $check ) {
			foreach ( (array) ( $check['features'] ?? array() ) as $feature ) {
				$features[ $feature ] = true;
			}
			if ( ! empty( $check['phpErrors'] ) ) {
				$features['captured-php-errors'] = true;
			}
		}

		$features = array_keys( $features );
		sort( $features );
		return $features;
	}

	private static function duration_ms( float $started_at ): int {
		return (int) round( ( microtime( true ) - $started_at ) * 1000 );
	}

	private static function byte_preview( string $bytes ): array {
		$slice = substr( $bytes, 0, 120 );
		return array(
			'length' => strlen( $bytes ),
			'sha1'   => sha1( $bytes ),
			'utf8'   => self::is_valid_utf8( $bytes ),
			'text'   => self::is_valid_utf8( $slice ) ? $slice : null,
			'base64' => self::is_valid_utf8( $slice ) ? null : base64_encode( $slice ),
		);
	}

	private static function safe_value( $value, int $depth = 0 ) {
		if ( $depth > 12 ) {
			return array( '__truncated' => 'max-depth' );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$safe_key         = is_string( $key ) && ! self::is_valid_utf8( $key ) ? 'base64-key:' . base64_encode( $key ) : $key;
				$out[ $safe_key ] = self::safe_value( $item, $depth + 1 );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			if ( self::is_valid_utf8( $value ) ) {
				if ( strlen( $value ) <= 240 ) {
					return $value;
				}
				return array(
					'__string' => true,
					'length'   => strlen( $value ),
					'sha1'     => sha1( $value ),
					'preview'  => substr( $value, 0, 240 ),
				);
			}

			return array(
				'__bytes' => true,
				'length'  => strlen( $value ),
				'sha1'    => sha1( $value ),
				'base64'  => base64_encode( $value ),
			);
		}

		$error = self::wp_error_summary( $value );
		if ( null !== $error ) {
			return $error;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Closure ) {
				return array( '__object' => 'Closure' );
			}
			return array(
				'__object' => get_class( $value ),
				'vars'     => self::safe_value( get_object_vars( $value ), $depth + 1 ),
			);
		}

		if ( is_resource( $value ) ) {
			return array( '__resource' => get_resource_type( $value ) );
		}

		return $value;
	}

	private static function is_valid_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}
}
