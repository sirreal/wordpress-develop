<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes bounded admin-ajax response helpers without dispatching admin-ajax.php.
 */
final class AdminAjaxSurface {
	public const NAME = 'admin-ajax';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::maybe_load_optional_ajax_support();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::row(
					$ctx,
					'admin-ajax.bootstrap-apis-available',
					true,
					array( 'missing' => $missing ),
					'skipped'
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_die_handlers_capture_and_restore( $ctx->fork( 'die-handlers' ) );
			$rows[] = self::check_json_response_helpers( $ctx->fork( 'json-helpers' ) );
			$rows[] = self::check_ajax_response_xml_boundaries( $ctx->fork( 'ajax-response' ) );
			$rows[] = self::check_nonce_and_capability_failures( $ctx->fork( 'nonce-cap' ) );
			$rows[] = self::check_selected_safe_ajax_handlers( $ctx->fork( 'safe-handlers' ) );
			$rows[] = self::check_compression_test_handler( $ctx->fork( 'compression-test' ) );
			$rows[] = self::check_malformed_request_globals_restore( $ctx->fork( 'request-restore' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'admin-ajax.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = self::row(
			$ctx,
			'admin-ajax.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'obLevel'  => ob_get_level(),
				'globals'  => array_keys( $snapshot['globals'] ),
				'dbCounts' => self::db_content_counts(),
			)
		);

		return $rows;
	}

	private static function maybe_load_optional_ajax_support(): void {
		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) && ! class_exists( 'WP_Ajax_Response', false ) ) {
			$ajax_response = ABSPATH . WPINC . '/class-wp-ajax-response.php';
			if ( file_exists( $ajax_response ) ) {
				require_once $ajax_response;
			}
		}

		if ( defined( 'ABSPATH' ) && ! function_exists( 'wp_ajax_nopriv_heartbeat' ) ) {
			$ajax_actions = ABSPATH . 'wp-admin/includes/ajax-actions.php';
			if ( file_exists( $ajax_actions ) ) {
				require_once $ajax_actions;
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Ajax_Response', 'WP_Error', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'check_ajax_referer',
				'current_user_can',
				'get_option',
				'has_action',
				'has_filter',
				'is_wp_error',
				'register_taxonomy',
				'remove_action',
				'remove_filter',
				'sanitize_key',
				'status_header',
				'wp_create_nonce',
				'wp_die',
				'wp_doing_ajax',
				'wp_generate_password',
				'wp_json_encode',
				'wp_parse_args',
				'wp_send_json',
				'wp_send_json_error',
				'wp_send_json_success',
				'wp_set_current_user',
				'wp_unslash',
				'wp_verify_nonce',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_die_handlers_capture_and_restore( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$ajax_status    = self::status_code( $ctx->fork( 'ajax-status' ), array( 200, 201, 202, 204, 207, 400, 403, 409, 418, 422, 429, 500, 503 ) );
		$ajax_message   = self::hostile_string( $ctx->fork( 'ajax-message' ) );
		$ajax_title     = 'Ajax Boundary ' . self::safe_label( $ctx->fork( 'ajax-title' ) );
		$default_status = self::status_code( $ctx->fork( 'default-status' ), array( 400, 401, 403, 404, 409, 418, 422, 500 ) );
		$error          = new \WP_Error(
			'cfz_die_' . self::slug( $ctx->fork( 'error-code' ), 'die' ),
			'Default Boundary ' . self::hostile_string( $ctx->fork( 'default-message' ) ),
			array(
				'status' => $default_status,
				'title'  => 'Default Boundary ' . self::safe_label( $ctx->fork( 'default-title' ) ),
			)
		);
		$error->add(
			'cfz_extra_' . self::slug( $ctx->fork( 'extra-code' ), 'extra' ),
			'Extra ' . self::hostile_string( $ctx->fork( 'extra-message' ) ),
			array( 'status' => 499 )
		);

		$ajax_capture = self::capture_terminating_call(
			static function () use ( $ajax_message, $ajax_status, $ajax_title ): void {
				\wp_die(
					$ajax_message,
					$ajax_title,
					array(
						'code'     => 'cfz_ajax_die',
						'exit'     => true,
						'response' => $ajax_status,
					)
				);
			},
			true
		);

		$default_capture = self::capture_terminating_call(
			static function () use ( $error ): void {
				\wp_die(
					$error,
					'',
					array(
						'exit' => true,
					)
				);
			},
			false
		);

		$ajax_die = $ajax_capture['dieCalls'][0] ?? array();
		self::collect_failure(
			$failures,
			$ajax_capture['captured']
				&& 1 === count( $ajax_capture['dieCalls'] )
				&& 'ajax' === ( $ajax_die['kind'] ?? null )
				&& $ajax_status === ( $ajax_die['processed']['args']['response'] ?? null )
				&& $ajax_title === ( $ajax_die['processed']['title'] ?? null )
				&& self::same_string_description( $ajax_message, $ajax_die['message'] ?? null )
				&& $ajax_capture['filtersRestored']
				&& $ajax_capture['bufferBalanced'],
			'custom wp_die_ajax_handler captures message, title, response, and exits by exception only',
			array(
				'capture' => $ajax_capture,
				'status'  => $ajax_status,
			)
		);

		$default_die = $default_capture['dieCalls'][0] ?? array();
		self::collect_failure(
			$failures,
			$default_capture['captured']
				&& 1 === count( $default_capture['dieCalls'] )
				&& 'default' === ( $default_die['kind'] ?? null )
				&& $default_status === ( $default_die['processed']['args']['response'] ?? null )
				&& self::described_string_starts_with( $default_die['processed']['args']['code'] ?? null, 'cfz_die_' )
				&& 1 === count( $default_die['processed']['args']['additional_errors'] ?? array() )
				&& $default_capture['filtersRestored']
				&& $default_capture['bufferBalanced'],
			'custom wp_die_handler captures WP_Error status, title, code, and additional errors without exiting',
			array(
				'capture' => $default_capture,
				'status'  => $default_status,
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.wp-die.capture-restore',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_json_response_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$payload  = self::json_payload( $ctx->fork( 'success-payload' ) );
		$flags    = self::json_flags( $ctx->fork( 'flags' ) );
		$status   = self::status_code( $ctx->fork( 'success-status' ), array( 200, 201, 202, 204, 207, 400, 403, 409, 418, 422, 429, 500, 503 ) );
		$error    = self::json_error( $ctx->fork( 'error-object' ) );

		$cases = array(
			array(
				'name'     => 'success-generated-data',
				'helper'   => 'success',
				'value'    => $payload,
				'status'   => $status,
				'flags'    => $flags,
				'expected' => array(
					'success' => true,
					'data'    => $payload,
				),
			),
			array(
				'name'     => 'success-null-default-status',
				'helper'   => 'success',
				'value'    => null,
				'status'   => null,
				'flags'    => 0,
				'expected' => array(
					'success' => true,
				),
			),
			array(
				'name'     => 'error-generated-data',
				'helper'   => 'error',
				'value'    => self::json_payload( $ctx->fork( 'error-payload' ) ),
				'status'   => self::status_code( $ctx->fork( 'error-status' ), array( 400, 401, 403, 409, 422, 429, 500 ) ),
				'flags'    => $flags,
				'expected' => null,
			),
			array(
				'name'     => 'error-wp-error',
				'helper'   => 'error',
				'value'    => $error,
				'status'   => self::status_code( $ctx->fork( 'wp-error-status' ), array( 400, 403, 409, 422, 500 ) ),
				'flags'    => 0,
				'expected' => self::expected_wp_error_json( $error ),
			),
		);
		$case_results = array();

		foreach ( $cases as $case ) {
			if ( null === $case['expected'] ) {
				$case['expected'] = array(
					'success' => false,
					'data'    => $case['value'],
				);
			}

			$capture = self::capture_terminating_call(
				static function () use ( $case ): void {
					if ( 'success' === $case['helper'] ) {
						\wp_send_json_success( $case['value'], $case['status'], $case['flags'] );
						return;
					}

					\wp_send_json_error( $case['value'], $case['status'], $case['flags'] );
				},
				true
			);

			$body          = self::terminal_body( $capture );
			$decoded       = json_decode( $body, true );
			$json_error    = json_last_error_msg();
			$expected_json = \wp_json_encode( $case['expected'], $case['flags'] );
			$expected      = false === $expected_json ? null : json_decode( $expected_json, true );
			$status_codes  = array_column( $capture['statusHeaders'], 'code' );
			$headers_seen  = self::headers_contain( $capture['headersAfter'], 'Content-Type: application/json' );
			$headers_known = self::headers_observable( $capture['headersAfter'] );
			$ok            = is_array( $decoded )
				&& $decoded === $expected
				&& $capture['captured']
				&& 1 === count( $capture['dieCalls'] )
				&& self::raw_die_response_is_null( $capture['dieCalls'][0] ?? array() )
				&& $capture['filtersRestored']
				&& $capture['bufferBalanced']
				&& (
					null === $case['status']
						? array() === $status_codes
						: array( $case['status'] ) === $status_codes
				)
				&& ( ! $headers_known || $headers_seen );

			self::collect_failure(
				$failures,
				$ok,
				"wp_send_json_{$case['helper']}() emits stable JSON shape for {$case['name']}",
				array(
					'case'         => $case['name'],
					'jsonError'    => $json_error,
					'decoded'      => $decoded,
					'expected'     => $expected,
					'statusCodes'  => $status_codes,
					'headersAfter' => $capture['headersAfter'],
					'capture'      => $capture,
				)
			);

			$case_results[] = array(
				'name'              => $case['name'],
				'bytes'             => strlen( $body ),
				'statusCodes'       => $status_codes,
				'contentTypeChecked' => $headers_known,
				'contentTypeSeen'    => $headers_seen,
			);
		}

		return self::row(
			$ctx,
			'admin-ajax.wp-send-json.shape-status',
			array() === $failures,
			array(
				'cases'    => $case_results,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_ajax_response_xml_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$action   = 'cfz_' . self::xml_name( $ctx->fork( 'action' ), 'act' );
		$what     = 'cfz_' . self::xml_name( $ctx->fork( 'what' ), 'item' );
		$id       = (string) $ctx->int( 1, 99999 );
		$old_id   = (string) $ctx->int( 1, 99999 );
		$position = self::hostile_string( $ctx->fork( 'position' ) );
		$data_raw = self::xml_hostile_text( $ctx->fork( 'data' ) );
		$supp_raw = array(
			self::xml_name( $ctx->fork( 'supp-one' ), 'supp' ) => self::xml_hostile_text( $ctx->fork( 'supp-one-value' ) ),
			self::xml_name( $ctx->fork( 'supp-two' ), 'supp' ) => self::xml_hostile_text( $ctx->fork( 'supp-two-value' ) ),
		);
		$args     = array(
			'action'       => $action,
			'data'         => self::cdata_safe( $data_raw ),
			'id'           => $id,
			'old_id'       => $old_id,
			'position'     => $position,
			'supplemental' => array_map( array( self::class, 'cdata_safe' ), $supp_raw ),
			'what'         => $what,
		);

		$local = self::snapshot_superglobals();
		self::set_request_globals( array(), array( 'action' => $action ) );

		try {
			$response = new \WP_Ajax_Response();
			$fragment = $response->add( $args );
			$capture  = self::capture_terminating_call(
				static function () use ( $response ): void {
					$response->send();
				},
				true
			);
		} finally {
			self::restore_superglobals( $local );
		}

		$xml              = self::terminal_body( $capture );
		$parsed           = self::parse_xml( $xml );
		$position_clean   = preg_replace( '/[^a-z0-9:_-]/i', '', $position );
		$fragment_present = str_contains( $xml, $fragment );
		$parsed_xml       = isset( $parsed['xml'] ) && $parsed['xml'] instanceof \SimpleXMLElement ? $parsed['xml'] : null;
		$data_text        = null === $parsed_xml ? null : self::xml_first_text( $parsed_xml, 'response/' . $what . '/response_data' );
		$supp_texts       = array();
		foreach ( $supp_raw as $key => $value ) {
			$supp_texts[ $key ] = null === $parsed_xml ? null : self::xml_first_text( $parsed_xml, 'response/' . $what . '/supplemental/' . $key );
		}

		self::collect_failure(
			$failures,
			$capture['captured']
				&& $capture['bufferBalanced']
				&& $capture['filtersRestored']
				&& $fragment_present
				&& is_array( $parsed )
				&& $data_text === $data_raw
				&& $supp_texts === $supp_raw
				&& str_contains( $xml, "action='{$action}_{$id}'" )
				&& str_contains( $xml, "old_id='{$old_id}'" )
				&& str_contains( $xml, "position='{$position_clean}'" ),
			'WP_Ajax_Response emits parseable XML with hostile CDATA text and sanitized position attributes',
			array(
				'capture'       => $capture,
				'parse'         => $parsed,
				'fragmentBytes' => strlen( $fragment ),
				'xmlBytes'      => strlen( $xml ),
				'position'      => $position,
				'positionClean' => $position_clean,
				'dataRaw'       => $data_raw,
				'supplemental'  => $supp_raw,
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.wp-ajax-response.xml-boundaries',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_nonce_and_capability_failures( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$action         = 'cfz_ajax_' . self::slug( $ctx->fork( 'nonce-action' ), 'action' );
		$invalid_nonce  = 'bad-' . self::safe_label( $ctx->fork( 'nonce-value' ) );
		$referer_calls  = array();
		$referer_action = static function ( $seen_action, $result ) use ( &$referer_calls ): void {
			$referer_calls[] = array(
				'action' => $seen_action,
				'result' => $result,
			);
		};
		$local          = self::snapshot_superglobals();
		$db_before      = self::db_content_counts();

		\add_action( 'check_ajax_referer', $referer_action, 10, 2 );
		try {
			self::set_request_globals(
				array(),
				array(
					'action' => $action,
					'nonce'  => $invalid_nonce,
				)
			);
			$nonce_capture = self::capture_terminating_call(
				static function () use ( $action ): void {
					\check_ajax_referer( $action, 'nonce', true );
				},
				true
			);
		} finally {
			\remove_action( 'check_ajax_referer', $referer_action, 10 );
			self::restore_superglobals( $local );
		}

		$nonce_die = $nonce_capture['dieCalls'][0] ?? array();
		self::collect_failure(
			$failures,
			$nonce_capture['captured']
				&& '-1' === self::terminal_body( $nonce_capture )
				&& 403 === ( $nonce_die['processed']['args']['response'] ?? null )
				&& array( $action ) === array_column( $referer_calls, 'action' )
				&& array( false ) === array_column( $referer_calls, 'result' )
				&& false === \has_action( 'check_ajax_referer', $referer_action )
				&& $nonce_capture['filtersRestored']
				&& $nonce_capture['bufferBalanced'],
			'check_ajax_referer() invalid nonce fails through captured ajax wp_die without leaking action filters',
			array(
				'capture'      => $nonce_capture,
				'refererCalls' => $referer_calls,
			)
		);

		if ( ! function_exists( 'wp_ajax_ajax_tag_search' ) ) {
			return self::row(
				$ctx,
				'admin-ajax.nonce-cap.failure-boundaries',
				array() === $failures,
				array(
					'failures' => array_slice( $failures, 0, 5 ),
					'skipped'  => array( 'wp_ajax_ajax_tag_search unavailable' ),
				)
			);
		}

		$taxonomy = 'cfz_ajax_' . self::slug( $ctx->fork( 'taxonomy' ), 'tax' );
		$wp_taxonomies_snapshot = self::snapshot_globals( array( 'wp_taxonomies', 'current_user' ) );
		$capability             = 'cfz_assign_' . self::slug( $ctx->fork( 'capability' ), 'cap' );

		try {
			\register_taxonomy(
				$taxonomy,
				'post',
				array(
					'public'       => false,
					'show_ui'      => false,
					'capabilities' => array(
						'assign_terms' => $capability,
					),
				)
			);
			\wp_set_current_user( 0 );
			self::set_request_globals(
				array(
					'action' => 'ajax-tag-search',
					'number' => (string) $ctx->int( 0, 20 ),
					'q'      => self::hostile_string( $ctx->fork( 'tag-query' ) ),
					'tax'    => $taxonomy,
				),
				array()
			);

			$cap_capture = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_ajax_tag_search();
				},
				true
			);
		} finally {
			self::restore_superglobals( $local );
			self::restore_globals( $wp_taxonomies_snapshot );
		}

		self::collect_failure(
			$failures,
			$cap_capture['captured']
				&& '-1' === self::terminal_body( $cap_capture )
				&& 200 === ( $cap_capture['dieCalls'][0]['processed']['args']['response'] ?? null )
				&& $cap_capture['filtersRestored']
				&& $cap_capture['bufferBalanced']
				&& $db_before === self::db_content_counts(),
			'wp_ajax_ajax_tag_search() capability denial returns stable -1 without term queries or content mutation',
			array(
				'capture'    => $cap_capture,
				'taxonomy'   => $taxonomy,
				'capability' => $capability,
				'dbBefore'   => $db_before,
				'dbAfter'    => self::db_content_counts(),
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.nonce-cap.failure-boundaries',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_selected_safe_ajax_handlers( \ComponentFuzz\FuzzContext $ctx ): array {
		$required = array(
			'wp_ajax_generate_password',
			'wp_ajax_heartbeat',
			'wp_ajax_logged_in',
			'wp_ajax_nopriv_generate_password',
			'wp_ajax_nopriv_heartbeat',
		);
		$missing  = array_values( array_filter( $required, static fn ( string $function ): bool => ! function_exists( $function ) ) );

		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-ajax.safe-handlers.schema-valid',
				true,
				array( 'missing' => $missing ),
				'skipped'
			);
		}

		$failures      = array();
		$handler_cases = array();
		$db_before     = self::db_content_counts();
		$local         = self::snapshot_superglobals();

		$password_handlers = array(
			'generate-password'        => 'wp_ajax_generate_password',
			'nopriv-generate-password' => 'wp_ajax_nopriv_generate_password',
		);
		foreach ( $password_handlers as $name => $function ) {
			self::set_request_globals(
				array( 'action' => $name ),
				array( 'unsafe' => self::hostile_string( $ctx->fork( $name . '-request' ) ) )
			);
			$capture = self::capture_terminating_call(
				static function () use ( $function ): void {
					$function();
				},
				true
			);
			$decoded = json_decode( self::terminal_body( $capture ), true );
			$ok      = is_array( $decoded )
				&& true === ( $decoded['success'] ?? null )
				&& is_string( $decoded['data'] ?? null )
				&& 24 === strlen( $decoded['data'] )
				&& $capture['captured']
				&& $capture['bufferBalanced']
				&& $capture['filtersRestored'];

			self::collect_failure(
				$failures,
				$ok,
				"{$function}() returns a wp_send_json_success password schema",
				array(
					'capture' => $capture,
					'decoded' => $decoded,
				)
			);
			$handler_cases[] = array(
				'name'  => $name,
				'ok'    => $ok,
				'bytes' => strlen( self::terminal_body( $capture ) ),
			);
		}

		self::set_request_globals(
			array(),
			array(
				'action'    => 'heartbeat',
				'data'      => array(
					'token'  => self::safe_label( $ctx->fork( 'heartbeat-token' ) ),
					'unsafe' => self::hostile_string( $ctx->fork( 'heartbeat-unsafe' ) ),
				),
				'screen_id' => self::hostile_string( $ctx->fork( 'heartbeat-screen' ) ),
			)
		);

		$heartbeat_received = static function ( array $response, array $data, string $screen_id ): array {
			$response['cfz_echo']   = $data['token'] ?? '';
			$response['cfz_screen'] = $screen_id;
			return $response;
		};
		$heartbeat_send     = static function ( array $response, string $screen_id ): array {
			$response['cfz_send_screen'] = $screen_id;
			return $response;
		};

		\add_filter( 'heartbeat_nopriv_received', $heartbeat_received, 10, 3 );
		\add_filter( 'heartbeat_nopriv_send', $heartbeat_send, 10, 2 );
		try {
			$nopriv_heartbeat_capture = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_nopriv_heartbeat();
				},
				true
			);
		} finally {
			\remove_filter( 'heartbeat_nopriv_received', $heartbeat_received, 10 );
			\remove_filter( 'heartbeat_nopriv_send', $heartbeat_send, 10 );
		}

		$nopriv_heartbeat = json_decode( self::terminal_body( $nopriv_heartbeat_capture ), true );
		$nopriv_ok        = is_array( $nopriv_heartbeat )
			&& isset( $nopriv_heartbeat['server_time'] )
			&& is_int( $nopriv_heartbeat['server_time'] )
			&& isset( $nopriv_heartbeat['cfz_echo'] )
			&& isset( $nopriv_heartbeat['cfz_screen'] )
			&& isset( $nopriv_heartbeat['cfz_send_screen'] )
			&& false === \has_filter( 'heartbeat_nopriv_received', $heartbeat_received )
			&& false === \has_filter( 'heartbeat_nopriv_send', $heartbeat_send )
			&& $nopriv_heartbeat_capture['captured']
			&& $nopriv_heartbeat_capture['bufferBalanced']
			&& $nopriv_heartbeat_capture['filtersRestored'];

		self::collect_failure(
			$failures,
			$nopriv_ok,
			'wp_ajax_nopriv_heartbeat() returns schema-valid heartbeat JSON and removes scoped filters',
			array(
				'capture' => $nopriv_heartbeat_capture,
				'decoded' => $nopriv_heartbeat,
			)
		);
		$handler_cases[] = array(
			'name'  => 'nopriv-heartbeat',
			'ok'    => $nopriv_ok,
			'bytes' => strlen( self::terminal_body( $nopriv_heartbeat_capture ) ),
		);

		self::set_request_globals(
			array(),
			array(
				'action'    => 'heartbeat',
				'data'      => array( 'unsafe' => self::hostile_string( $ctx->fork( 'heartbeat-fail-data' ) ) ),
				'screen_id' => self::hostile_string( $ctx->fork( 'heartbeat-fail-screen' ) ),
			)
		);
		$heartbeat_capture = self::capture_terminating_call(
			static function (): void {
				\wp_ajax_heartbeat();
			},
			true
		);
		$heartbeat_decoded = json_decode( self::terminal_body( $heartbeat_capture ), true );
		$heartbeat_ok      = is_array( $heartbeat_decoded )
			&& false === ( $heartbeat_decoded['success'] ?? null )
			&& ! array_key_exists( 'data', $heartbeat_decoded )
			&& $heartbeat_capture['captured']
			&& $heartbeat_capture['bufferBalanced']
			&& $heartbeat_capture['filtersRestored'];

		self::collect_failure(
			$failures,
			$heartbeat_ok,
			'wp_ajax_heartbeat() missing nonce returns stable wp_send_json_error shape before mutations',
			array(
				'capture' => $heartbeat_capture,
				'decoded' => $heartbeat_decoded,
			)
		);
		$handler_cases[] = array(
			'name'  => 'heartbeat-missing-nonce',
			'ok'    => $heartbeat_ok,
			'bytes' => strlen( self::terminal_body( $heartbeat_capture ) ),
		);

		self::set_request_globals(
			array( 'action' => 'logged-in' ),
			array( 'malformed' => self::hostile_string( $ctx->fork( 'logged-in-request' ) ) )
		);
		$logged_in_capture = self::capture_terminating_call(
			static function (): void {
				\wp_ajax_logged_in();
			},
			true
		);
		$logged_in_ok      = '1' === self::terminal_body( $logged_in_capture )
			&& $logged_in_capture['captured']
			&& $logged_in_capture['bufferBalanced']
			&& $logged_in_capture['filtersRestored'];
		self::collect_failure(
			$failures,
			$logged_in_ok,
			'wp_ajax_logged_in() returns scalar alive marker through captured ajax wp_die',
			array( 'capture' => $logged_in_capture )
		);
		$handler_cases[] = array(
			'name'  => 'logged-in',
			'ok'    => $logged_in_ok,
			'bytes' => strlen( self::terminal_body( $logged_in_capture ) ),
		);

		self::restore_superglobals( $local );

		self::collect_failure(
			$failures,
			self::superglobals_match( $local )
				&& $db_before === self::db_content_counts(),
			'selected safe ajax handlers do not leak request globals or mutate component-fuzz DB content',
			array(
				'dbBefore' => $db_before,
				'dbAfter'  => self::db_content_counts(),
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.safe-handlers.schema-valid',
			array() === $failures,
			array(
				'cases'    => $handler_cases,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_compression_test_handler( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'wp_ajax_wp_compression_test' ) ) {
			return self::row(
				$ctx,
				'admin-ajax.wp-compression-test.capability-and-test-branch',
				true,
				array( 'missing' => array( 'wp_ajax_wp_compression_test' ) ),
				'skipped'
			);
		}

		$failures         = array();
		$local            = self::snapshot_superglobals();
		$user_snapshot    = self::snapshot_globals( array( 'current_user', 'userdata', 'user_ID' ) );
		$db_before        = self::db_content_counts();
		$compression_busy = ini_get( 'zlib.output_compression' ) || 'ob_gzhandler' === ini_get( 'output_handler' );

		try {
			\wp_set_current_user( 0 );
			self::set_request_globals(
				array(
					'action' => 'wp-compression-test',
					'test'   => self::safe_label( $ctx->fork( 'denied-test' ) ),
				),
				array()
			);
			$denied_capture = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_wp_compression_test();
				},
				true
			);

			if ( ! $compression_busy ) {
				$grant_manage_options = static function ( array $allcaps, array $caps, array $args = array(), $user = null ): array {
					unset( $caps, $args, $user );

					$allcaps['manage_options'] = true;
					return $allcaps;
				};

				\add_filter( 'user_has_cap', $grant_manage_options, 10, 4 );
				try {
					\wp_set_current_user( $ctx->int( 1, 99999 ) );
					self::set_request_globals(
						array(
							'action' => 'wp-compression-test',
							'test'   => '1',
							'noise'  => self::hostile_string( $ctx->fork( 'noise' ) ),
						),
						array()
					);
					$test_capture = self::capture_terminating_call(
						static function (): void {
							\wp_ajax_wp_compression_test();
						},
						true
					);
				} finally {
					\remove_filter( 'user_has_cap', $grant_manage_options, 10 );
				}
			} else {
				$grant_manage_options = null;
				$test_capture         = null;
			}
		} finally {
			self::restore_superglobals( $local );
			self::restore_globals( $user_snapshot );
		}

		self::collect_failure(
			$failures,
			$denied_capture['captured']
				&& '-1' === self::terminal_body( $denied_capture )
				&& $denied_capture['bufferBalanced']
				&& $denied_capture['filtersRestored'],
			'wp_ajax_wp_compression_test() capability denial returns stable -1 without side effects',
			array( 'capture' => $denied_capture )
		);

		if ( null !== $test_capture ) {
			$body = self::terminal_body( $test_capture );
			self::collect_failure(
				$failures,
				$test_capture['captured']
					&& str_contains( $body, 'wpCompressionTest Lorem ipsum' )
					&& strlen( $body ) > 1000
					&& $test_capture['bufferBalanced']
					&& $test_capture['filtersRestored']
					&& false === \has_filter( 'user_has_cap', $grant_manage_options ),
				'wp_ajax_wp_compression_test() test=1 emits deterministic JavaScript body under scoped capability grant',
				array(
					'capture' => $test_capture,
					'bytes'   => strlen( $body ),
				)
			);
		}

		self::collect_failure(
			$failures,
			self::superglobals_match( $local )
				&& self::globals_match( $user_snapshot )
				&& $db_before === self::db_content_counts(),
			'wp_ajax_wp_compression_test() restores request/user globals and avoids DB content mutation',
			array(
				'compressionBusy' => (bool) $compression_busy,
				'dbBefore'        => $db_before,
				'dbAfter'         => self::db_content_counts(),
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.wp-compression-test.capability-and-test-branch',
			array() === $failures,
			array(
				'compressionBusy' => (bool) $compression_busy,
				'testedBody'      => null !== $test_capture,
				'failures'        => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_malformed_request_globals_restore( \ComponentFuzz\FuzzContext $ctx ): array {
		$local       = self::snapshot_superglobals();
		$start_level = ob_get_level();
		$failures    = array();

		try {
			self::set_request_globals(
				array(
					'action'       => array( 'nested' => self::hostile_string( $ctx->fork( 'get-action' ) ) ),
					"bad\0key"     => self::invalid_utf8_string( $ctx->fork( 'bad-get' ) ),
					'_ajax_nonce'  => array( 'not-a-scalar' ),
					'terminator'   => ']]>',
				),
				array(
					'action'       => self::hostile_string( $ctx->fork( 'post-action' ) ),
					'deep'         => self::json_payload( $ctx->fork( 'post-deep' ) ),
					'screenoptionnonce' => self::invalid_utf8_string( $ctx->fork( 'bad-post' ) ),
				)
			);

			$capture = self::capture_terminating_call(
				static function (): void {
					\wp_send_json_success(
						array(
							'boundary' => 'request-globals',
							'ok'       => true,
						),
						200
					);
				},
				true
			);
		} finally {
			self::restore_superglobals( $local );
		}

		$decoded = json_decode( self::terminal_body( $capture ), true );
		self::collect_failure(
			$failures,
			is_array( $decoded )
				&& true === ( $decoded['success'] ?? null )
				&& 'request-globals' === ( $decoded['data']['boundary'] ?? null )
				&& array( 200 ) === array_column( $capture['statusHeaders'], 'code' )
				&& $capture['bufferBalanced']
				&& $capture['filtersRestored'],
			'malformed request globals do not perturb direct response helper output',
			array(
				'capture' => $capture,
				'decoded' => $decoded,
			)
		);

		self::collect_failure(
			$failures,
			self::superglobals_match( $local )
				&& $start_level === ob_get_level(),
			'malformed request globals and output buffers are restored after capture',
			array(
				'startLevel' => $start_level,
				'endLevel'   => ob_get_level(),
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.request-globals-output-buffers-restored',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function capture_terminating_call( callable $callback, bool $doing_ajax ): array {
		$start_level       = ob_get_level();
		$die_calls         = array();
		$status_headers    = array();
		$captured          = false;
		$returned          = false;
		$throwable         = null;
		$headers_before    = self::observed_headers();
		$headers_after     = array();
		$output            = '';
		$cleaned_buffers   = 0;
		$doing_ajax_filter = static function () use ( $doing_ajax ): bool {
			return $doing_ajax;
		};
		$ajax_die_filter   = static function ( $handler ) use ( &$die_calls ) {
			unset( $handler );
			return static function ( $message = '', string $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = AdminAjaxSurface::describe_die_call( 'ajax', $message, $title, $args );
				throw new AdminAjaxSurface_DieCaptured( 'Captured ajax wp_die.' );
			};
		};
		$default_die_filter = static function ( $handler ) use ( &$die_calls ) {
			unset( $handler );
			return static function ( $message = '', string $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = AdminAjaxSurface::describe_die_call( 'default', $message, $title, $args );
				throw new AdminAjaxSurface_DieCaptured( 'Captured default wp_die.' );
			};
		};
		$status_filter      = static function ( string $status_header, int $code, string $description, string $protocol ) use ( &$status_headers ): string {
			$status_headers[] = array(
				'code'        => $code,
				'description' => $description,
				'header'      => $status_header,
				'protocol'    => $protocol,
			);
			return $status_header;
		};
		$charset_filter     = static function () {
			return 'UTF-8';
		};

		if ( ! headers_sent() ) {
			header_remove();
		}

		\add_filter( 'wp_doing_ajax', $doing_ajax_filter, 9999 );
		\add_filter( 'wp_die_ajax_handler', $ajax_die_filter, 1 );
		\add_filter( 'wp_die_handler', $default_die_filter, 1 );
		\add_filter( 'status_header', $status_filter, 10, 4 );
		\add_filter( 'pre_option_blog_charset', $charset_filter, 10, 3 );

		ob_start();
		try {
			$callback();
			$returned = true;
		} catch ( AdminAjaxSurface_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$throwable = $e;
		} finally {
			while ( ob_get_level() > $start_level ) {
				$chunk   = ob_get_clean();
				$output  = ( false === $chunk ? '' : $chunk ) . $output;
				++$cleaned_buffers;
			}

			$headers_after = self::observed_headers();

			\remove_filter( 'wp_doing_ajax', $doing_ajax_filter, 9999 );
			\remove_filter( 'wp_die_ajax_handler', $ajax_die_filter, 1 );
			\remove_filter( 'wp_die_handler', $default_die_filter, 1 );
			\remove_filter( 'status_header', $status_filter, 10 );
			\remove_filter( 'pre_option_blog_charset', $charset_filter, 10 );

			if ( ! headers_sent() ) {
				header_remove();
			}
		}

		return array(
			'bufferBalanced'  => $start_level === ob_get_level() && 1 === $cleaned_buffers,
			'captured'        => $captured,
			'cleanedBuffers'  => $cleaned_buffers,
			'dieCalls'        => $die_calls,
			'filtersRestored' => false === \has_filter( 'wp_doing_ajax', $doing_ajax_filter )
				&& false === \has_filter( 'wp_die_ajax_handler', $ajax_die_filter )
				&& false === \has_filter( 'wp_die_handler', $default_die_filter )
				&& false === \has_filter( 'status_header', $status_filter )
				&& false === \has_filter( 'pre_option_blog_charset', $charset_filter ),
			'headersAfter'    => $headers_after,
			'headersBefore'   => $headers_before,
			'output'          => $output,
			'returned'        => $returned,
			'statusHeaders'   => $status_headers,
			'throwable'       => null === $throwable ? null : self::describe_throwable( $throwable ),
		);
	}

	private static function describe_die_call( string $kind, $message, string $title = '', $args = array() ): array {
		$raw_args       = is_array( $args ) ? $args : array( 'raw' => $args );
		$processed      = null;
		$process_args   = $args;
		$process_title  = $title;
		$process_failed = null;

		if ( 'ajax' === $kind ) {
			$process_args = \wp_parse_args(
				$process_args,
				array( 'response' => 200 )
			);
		}

		if ( function_exists( '_wp_die_process_input' ) ) {
			try {
				list( $processed_message, $processed_title, $processed_args ) = \_wp_die_process_input( $message, $process_title, $process_args );
				$processed = array(
					'args'    => self::describe_value( $processed_args ),
					'message' => self::describe_value( $processed_message ),
					'title'   => $processed_title,
				);
			} catch ( \Throwable $e ) {
				$process_failed = self::describe_throwable( $e );
			}
		}

		return array(
			'kind'          => $kind,
			'message'       => self::describe_value( $message ),
			'messageString' => is_scalar( $message ) ? (string) $message : null,
			'processed'     => $processed,
			'processFailed' => $process_failed,
			'rawArgs'       => self::describe_value( $raw_args ),
			'title'         => $title,
		);
	}

	private static function terminal_body( array $capture ): string {
		$output = (string) ( $capture['output'] ?? '' );
		if ( '' !== $output ) {
			return $output;
		}

		$die_calls = $capture['dieCalls'] ?? array();
		$last      = end( $die_calls );
		if ( is_array( $last ) && null !== ( $last['messageString'] ?? null ) ) {
			return (string) $last['messageString'];
		}

		return '';
	}

	private static function raw_die_response_is_null( array $die_call ): bool {
		return isset( $die_call['rawArgs'] )
			&& is_array( $die_call['rawArgs'] )
			&& array_key_exists( 'response', $die_call['rawArgs'] )
			&& null === $die_call['rawArgs']['response'];
	}

	private static function json_payload( \ComponentFuzz\FuzzContext $ctx ): array {
		$object          = new \stdClass();
		$object->label   = self::hostile_string( $ctx->fork( 'object-label' ) );
		$object->invalid = self::invalid_utf8_string( $ctx->fork( 'object-invalid' ) );
		$object->nested  = (object) array(
			'angle'      => '<tag attr="value">&text</tag>',
			'terminator' => ']]>',
		);

		return array(
			'empty'       => '',
			'false'       => false,
			'invalidUtf8' => self::invalid_utf8_string( $ctx->fork( 'invalid' ) ),
			'list'        => array(
				self::hostile_string( $ctx->fork( 'list-one' ) ),
				$ctx->int( -1000, 1000 ),
				null,
				array( 'deep' => $ctx->jsonValue() ),
			),
			'object'      => $object,
			'unsafe'      => self::hostile_string( $ctx->fork( 'unsafe' ) ),
		);
	}

	private static function json_error( \ComponentFuzz\FuzzContext $ctx ): \WP_Error {
		$error = new \WP_Error(
			'cfz_json_' . self::slug( $ctx->fork( 'code-one' ), 'one' ),
			'Primary ' . self::hostile_string( $ctx->fork( 'message-one' ) ),
			array(
				'status' => self::status_code( $ctx->fork( 'status-one' ), array( 400, 403, 422, 500 ) ),
				'meta'   => self::hostile_string( $ctx->fork( 'meta-one' ) ),
			)
		);
		$error->add(
			'cfz_json_' . self::slug( $ctx->fork( 'code-two' ), 'two' ),
			'Secondary ' . self::hostile_string( $ctx->fork( 'message-two' ) ),
			array( 'status' => self::status_code( $ctx->fork( 'status-two' ), array( 409, 418, 429, 500 ) ) )
		);

		return $error;
	}

	private static function expected_wp_error_json( \WP_Error $error ): array {
		$data = array();
		foreach ( $error->errors as $code => $messages ) {
			foreach ( $messages as $message ) {
				$data[] = array(
					'code'    => $code,
					'message' => $message,
				);
			}
		}

		return array(
			'success' => false,
			'data'    => $data,
		);
	}

	private static function json_flags( \ComponentFuzz\FuzzContext $ctx ): int {
		$sets = array(
			0,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);

		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$sets[] = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
		}

		return $ctx->choice( $sets );
	}

	private static function status_code( \ComponentFuzz\FuzzContext $ctx, array $codes ): int {
		return (int) $ctx->choice( $codes );
	}

	private static function hostile_string( \ComponentFuzz\FuzzContext $ctx ): string {
		$pieces = array(
			$ctx->text( 0, 32 ),
			'<script>alert("cfz")</script>',
			"line\nbreak\tvalue",
			'quote "\' amp & less < greater > slash /',
			'cdata ]]> terminator',
			$ctx->bool( 35 ) ? self::invalid_utf8_string( $ctx->fork( 'invalid' ) ) : '',
		);

		return implode( '|', array_slice( $pieces, 0, $ctx->int( 2, count( $pieces ) ) ) );
	}

	private static function xml_hostile_text( \ComponentFuzz\FuzzContext $ctx ): string {
		$random = preg_replace( '/[^\x20-\x7E\r\n\t]/', '', $ctx->ascii( 0, 48 ) );

		return 'xml<' . $random . '>|<script>alert("cfz")</script>|line'
			. "\n"
			. 'break|quote "\' amp & less < greater >|cdata]]>end';
	}

	private static function invalid_utf8_string( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'invalid-' . $ctx->identifier( 1, 8 ) . "-\xC3\x28-\xE2\x28\xA1-\xF0\x28\x8C\x28";
	}

	private static function safe_label( \ComponentFuzz\FuzzContext $ctx ): string {
		return preg_replace( '/[^A-Za-z0-9_-]/', '_', $ctx->identifier( 3, 18 ) );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $fallback ): string {
		$slug = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '_', $ctx->identifier( 3, 18 ) ) );
		$slug = trim( $slug, '_' );
		return '' === $slug ? $fallback : $slug;
	}

	private static function xml_name( \ComponentFuzz\FuzzContext $ctx, string $fallback ): string {
		$name = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '_', $ctx->identifier( 3, 16 ) ) );
		if ( '' === $name || ! preg_match( '/^[a-z_]/', $name ) ) {
			$name = $fallback . '_' . $name;
		}

		return $name;
	}

	private static function cdata_safe( string $value ): string {
		return str_replace( ']]>', ']]]]><![CDATA[>', $value );
	}

	private static function parse_xml( string $xml ): array {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'simplexml unavailable',
			);
		}

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$parsed = simplexml_load_string( $xml );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $parsed ) {
			return array(
				'ok'     => false,
				'errors' => array_map(
					static function ( \LibXMLError $error ): string {
						return trim( $error->message );
					},
					array_slice( $errors, 0, 5 )
				),
			);
		}

		return array(
			'ok'  => true,
			'xml' => $parsed,
		);
	}

	private static function xml_first_text( \SimpleXMLElement $xml, string $path ): ?string {
		$nodes = $xml->xpath( $path );
		if ( ! is_array( $nodes ) || array() === $nodes ) {
			return null;
		}

		return (string) $nodes[0];
	}

	private static function set_request_globals( array $get, array $post ): void {
		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );
	}

	private static function snapshot_superglobals(): array {
		return array(
			'_COOKIE'  => self::clone_value( $_COOKIE ),
			'_GET'     => self::clone_value( $_GET ),
			'_POST'    => self::clone_value( $_POST ),
			'_REQUEST' => self::clone_value( $_REQUEST ),
			'_SERVER'  => self::clone_value( $_SERVER ),
		);
	}

	private static function restore_superglobals( array $snapshot ): void {
		$_COOKIE  = self::clone_value( $snapshot['_COOKIE'] );
		$_GET     = self::clone_value( $snapshot['_GET'] );
		$_POST    = self::clone_value( $snapshot['_POST'] );
		$_REQUEST = self::clone_value( $snapshot['_REQUEST'] );
		$_SERVER  = self::clone_value( $snapshot['_SERVER'] );
	}

	private static function superglobals_match( array $snapshot ): bool {
		return $_COOKIE == $snapshot['_COOKIE']
			&& $_GET == $snapshot['_GET']
			&& $_POST == $snapshot['_POST']
			&& $_REQUEST == $snapshot['_REQUEST']
			&& $_SERVER == $snapshot['_SERVER'];
	}

	private static function snapshot_state(): array {
		return array(
			'dbCounts'     => self::db_content_counts(),
			'globals'      => self::snapshot_globals(
				array(
					'current_screen',
					'current_user',
					'pagenow',
					'userdata',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_taxonomies',
				)
			),
			'obLevel'      => ob_get_level(),
			'superglobals' => self::snapshot_superglobals(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}

		self::restore_superglobals( $snapshot['superglobals'] );
		self::restore_globals( $snapshot['globals'] );
	}

	private static function state_matches( array $snapshot ): bool {
		return ob_get_level() === $snapshot['obLevel']
			&& self::superglobals_match( $snapshot['superglobals'] )
			&& self::globals_match( $snapshot['globals'] )
			&& self::db_content_counts() === $snapshot['dbCounts'];
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

	private static function globals_match( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		return $value;
	}

	private static function db_content_counts(): ?array {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return null;
	}

	private static function observed_headers(): array {
		if ( function_exists( 'xdebug_get_headers' ) ) {
			return xdebug_get_headers();
		}

		return headers_list();
	}

	private static function headers_observable( array $headers ): bool {
		return array() !== $headers;
	}

	private static function headers_contain( array $headers, string $needle ): bool {
		foreach ( $headers as $header ) {
			if ( str_starts_with( strtolower( $header ), strtolower( $needle ) ) ) {
				return true;
			}
		}

		return false;
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

	private static function row(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		bool $ok,
		array $data = array(),
		?string $status = null
	): array {
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

	private static function same_string_description( string $expected, $description ): bool {
		return is_array( $description )
			&& ( $description['sha1'] ?? null ) === sha1( $expected )
			&& ( $description['bytes'] ?? null ) === strlen( $expected );
	}

	private static function described_string_starts_with( $description, string $prefix ): bool {
		return is_array( $description )
			&& isset( $description['preview'] )
			&& str_starts_with( $description['preview'], $prefix );
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_array( $value ) ) {
			if ( $depth >= 5 ) {
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

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}

			if ( $value instanceof \WP_Error ) {
				return array(
					'type'  => 'object',
					'class' => get_class( $value ),
					'codes' => $value->get_error_codes(),
				);
			}

			if ( $value instanceof \SimpleXMLElement ) {
				return array(
					'type' => 'SimpleXMLElement',
					'name' => $value->getName(),
				);
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
			'bytes'   => strlen( $value ),
			'preview' => self::escape_bytes( $value ),
			'sha1'    => sha1( $value ),
			'type'    => 'string',
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
			'message' => self::escape_bytes( $e->getMessage() ),
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
}

final class AdminAjaxSurface_DieCaptured extends \RuntimeException {}
