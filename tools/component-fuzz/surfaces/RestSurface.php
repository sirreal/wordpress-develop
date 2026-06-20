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
			'rest_convert_error_to_response',
			'rest_ensure_response',
			'rest_sanitize_value_from_schema',
			'rest_validate_value_from_schema',
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

		$bad_regex_server = new \WP_REST_Server();
		$bad_regex_route  = '/' . $namespace . '/broken/(?P<broken>[';
		$bad_regex_server->register_route(
			$namespace,
			$bad_regex_route,
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
		$bad_regex_response = $bad_regex_server->dispatch( new \WP_REST_Request( 'GET', '/' . $namespace . '/broken/x' ) );
		$bad_regex_data     = $bad_regex_response->get_data();
		$bad_regex_ok       = 404 === $bad_regex_response->get_status()
			&& is_array( $bad_regex_data )
			&& isset( $bad_regex_data['code'] )
			&& 'rest_no_route' === $bad_regex_data['code'];

		$ok = $match_ok && $path_ok && $bad_regex_ok;

		return array(
			'ok'       => $ok,
			'message'  => $ok ? 'Route matching and malformed route/path handling stayed stable.' : 'Route matching or malformed route/path handling drifted.',
			'features' => array( 'routes', 'named-captures', 'malformed-path', 'malformed-route-regex' ),
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
				'badRegex'  => array(
					'ok'       => $bad_regex_ok,
					'route'    => $bad_regex_route,
					'response' => self::response_summary( $bad_regex_response ),
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
