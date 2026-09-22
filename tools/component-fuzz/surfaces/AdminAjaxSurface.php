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
			$rows[] = self::check_heartbeat_nonce_branches( $ctx->fork( 'heartbeat-nonce-branches' ) );
			$rows[] = self::check_selected_safe_ajax_handlers( $ctx->fork( 'safe-handlers' ) );
			$rows[] = self::check_find_posts_modal_and_ajax( $ctx->fork( 'find-posts' ) );
			$rows[] = self::check_attachment_ajax_workflows( $ctx->fork( 'attachment-workflows' ) );
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

		if ( defined( 'ABSPATH' ) && ! function_exists( 'find_posts_div' ) ) {
			$template = ABSPATH . 'wp-admin/includes/template.php';
			if ( file_exists( $template ) ) {
				require_once $template;
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
				'find_posts_div',
				'get_post_type_object',
				'get_post_types',
				'get_posts',
				'mysql2date',
				'register_taxonomy',
				'register_post_type',
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
				'wp_slash',
				'wp_unslash',
				'wp_verify_nonce',
				'wp_ajax_find_posts',
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

	private static function check_heartbeat_nonce_branches( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'wp_ajax_heartbeat' ) ) {
			return self::row(
				$ctx,
				'admin-ajax.heartbeat.nonce-branch-hooks',
				true,
				array( 'missing' => array( 'wp_ajax_heartbeat' ) ),
				'skipped'
			);
		}

		$failures           = array();
		$local              = self::snapshot_superglobals();
		$user_snapshot      = self::snapshot_globals( array( 'current_user', 'userdata', 'user_ID' ) );
		$db_before          = self::db_content_counts();
		$valid_screen_raw   = self::hostile_string( $ctx->fork( 'valid-screen' ) );
		$valid_screen       = \sanitize_key( $valid_screen_raw );
		$valid_data         = array(
			'token'  => self::safe_label( $ctx->fork( 'valid-token' ) ),
			'nested' => array(
				'label'  => self::safe_label( $ctx->fork( 'valid-nested-label' ) ),
				'unsafe' => self::hostile_string( $ctx->fork( 'valid-unsafe' ) ),
			),
			'slashy' => 'quote " apostrophe \' slash \\',
		);
		$valid_events       = array();
		$valid_received     = array();
		$valid_send         = array();
		$valid_tick         = array();
		$valid_capture      = null;
		$invalid_screen_raw = self::hostile_string( $ctx->fork( 'invalid-screen' ) );
		$invalid_screen     = \sanitize_key( $invalid_screen_raw );
		$invalid_nonce      = 'invalid-' . self::safe_label( $ctx->fork( 'invalid-nonce' ) );
		$invalid_data       = array(
			'token'  => self::safe_label( $ctx->fork( 'invalid-token' ) ),
			'unsafe' => self::hostile_string( $ctx->fork( 'invalid-unsafe' ) ),
			'slashy' => 'invalid " nonce \\ payload',
		);
		$invalid_events     = array();
		$refresh_calls      = array();
		$verify_failures    = array();
		$invalid_capture    = null;

		try {
			$valid_nonce = \wp_create_nonce( 'heartbeat-nonce' );
			self::set_request_globals(
				array(),
				array(
					'action'    => 'heartbeat',
					'_nonce'    => $valid_nonce,
					'data'      => \wp_slash( $valid_data ),
					'screen_id' => $valid_screen_raw,
				)
			);

			$heartbeat_received = static function ( array $response, array $data, string $screen_id ) use ( &$valid_events, &$valid_received ): array {
				$valid_events[]   = 'received';
				$valid_received[] = array(
					'data'     => $data,
					'response' => $response,
					'screenId' => $screen_id,
				);

				$response['cfz_valid_received'] = $data['token'] ?? '';
				$response['cfz_valid_nested']   = $data['nested']['label'] ?? '';
				return $response;
			};
			$heartbeat_send     = static function ( array $response, string $screen_id ) use ( &$valid_events, &$valid_send ): array {
				$valid_events[] = 'send';
				$valid_send[]   = array(
					'response' => $response,
					'screenId' => $screen_id,
				);

				$response['cfz_valid_send_screen'] = $screen_id;
				return $response;
			};
			$heartbeat_tick     = static function ( array $response, string $screen_id ) use ( &$valid_events, &$valid_tick ): void {
				$valid_events[] = 'tick';
				$valid_tick[]   = array(
					'response' => $response,
					'screenId' => $screen_id,
				);
			};
			$unexpected_refresh = static function ( array $response ) use ( &$valid_events ): array {
				$valid_events[] = 'unexpected-refresh';
				return $response;
			};

			\add_filter( 'heartbeat_received', $heartbeat_received, 10, 3 );
			\add_filter( 'heartbeat_send', $heartbeat_send, 10, 2 );
			\add_action( 'heartbeat_tick', $heartbeat_tick, 10, 2 );
			\add_filter( 'wp_refresh_nonces', $unexpected_refresh, 10, 3 );
			try {
				$valid_capture = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_heartbeat();
					},
					true
				);
			} finally {
				\remove_filter( 'heartbeat_received', $heartbeat_received, 10 );
				\remove_filter( 'heartbeat_send', $heartbeat_send, 10 );
				\remove_action( 'heartbeat_tick', $heartbeat_tick, 10 );
				\remove_filter( 'wp_refresh_nonces', $unexpected_refresh, 10 );
			}

			self::set_request_globals(
				array(),
				array(
					'action'    => 'heartbeat',
					'_nonce'    => $invalid_nonce,
					'data'      => \wp_slash( $invalid_data ),
					'screen_id' => $invalid_screen_raw,
				)
			);

			$refresh_nonces       = static function ( array $response, array $data, string $screen_id ) use ( &$invalid_events, &$refresh_calls ): array {
				$invalid_events[] = 'refresh-nonces';
				$refresh_calls[]  = array(
					'data'     => $data,
					'response' => $response,
					'screenId' => $screen_id,
				);

				$response['cfz_refreshed']      = $data['token'] ?? '';
				$response['cfz_refresh_screen'] = $screen_id;
				return $response;
			};
			$verify_nonce_failed  = static function ( $nonce, $action, $user, $token ) use ( &$invalid_events, &$verify_failures ): void {
				$invalid_events[]  = 'verify-failed';
				$verify_failures[] = array(
					'action' => $action,
					'nonce'  => $nonce,
					'token'  => $token,
					'userId' => is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : null,
				);
			};
			$unexpected_heartbeat = static function ( $response ) use ( &$invalid_events ): array {
				$invalid_events[] = 'unexpected-heartbeat-filter';
				return is_array( $response ) ? $response : array();
			};
			$unexpected_tick      = static function () use ( &$invalid_events ): void {
				$invalid_events[] = 'unexpected-heartbeat-tick';
			};

			\add_filter( 'wp_refresh_nonces', $refresh_nonces, 10, 3 );
			\add_action( 'wp_verify_nonce_failed', $verify_nonce_failed, 10, 4 );
			\add_filter( 'heartbeat_received', $unexpected_heartbeat, 10, 3 );
			\add_filter( 'heartbeat_send', $unexpected_heartbeat, 10, 2 );
			\add_action( 'heartbeat_tick', $unexpected_tick, 10, 2 );
			try {
				$invalid_capture = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_heartbeat();
					},
					true
				);
			} finally {
				\remove_filter( 'wp_refresh_nonces', $refresh_nonces, 10 );
				\remove_action( 'wp_verify_nonce_failed', $verify_nonce_failed, 10 );
				\remove_filter( 'heartbeat_received', $unexpected_heartbeat, 10 );
				\remove_filter( 'heartbeat_send', $unexpected_heartbeat, 10 );
				\remove_action( 'heartbeat_tick', $unexpected_tick, 10 );
			}
		} finally {
			self::restore_superglobals( $local );
			self::restore_globals( $user_snapshot );
		}

		$valid_decoded = null === $valid_capture ? null : json_decode( self::terminal_body( $valid_capture ), true );
		self::collect_failure(
			$failures,
			is_array( $valid_decoded )
				&& array( 'received', 'send', 'tick' ) === $valid_events
				&& array( $valid_data ) === array_column( $valid_received, 'data' )
				&& array( array() ) === array_column( $valid_received, 'response' )
				&& array( $valid_screen ) === array_column( $valid_received, 'screenId' )
				&& array( $valid_screen ) === array_column( $valid_send, 'screenId' )
				&& array( $valid_screen ) === array_column( $valid_tick, 'screenId' )
				&& false === array_key_exists( 'server_time', $valid_tick[0]['response'] ?? array() )
				&& ( $valid_data['token'] ?? null ) === ( $valid_decoded['cfz_valid_received'] ?? null )
				&& ( $valid_data['nested']['label'] ?? null ) === ( $valid_decoded['cfz_valid_nested'] ?? null )
				&& $valid_screen === ( $valid_decoded['cfz_valid_send_screen'] ?? null )
				&& isset( $valid_decoded['server_time'] )
				&& is_int( $valid_decoded['server_time'] )
				&& ! array_key_exists( 'nonces_expired', $valid_decoded )
				&& null !== $valid_capture
				&& $valid_capture['captured']
				&& 1 === count( $valid_capture['dieCalls'] )
				&& self::raw_die_response_is_null( $valid_capture['dieCalls'][0] ?? array() )
				&& $valid_capture['bufferBalanced']
				&& $valid_capture['filtersRestored']
				&& false === \has_filter( 'heartbeat_received', $heartbeat_received )
				&& false === \has_filter( 'heartbeat_send', $heartbeat_send )
				&& false === \has_action( 'heartbeat_tick', $heartbeat_tick )
				&& false === \has_filter( 'wp_refresh_nonces', $unexpected_refresh ),
			'wp_ajax_heartbeat() valid nonce runs received/send/tick hooks in order with unslashed data and sanitized screen id',
			array(
				'capture'       => $valid_capture,
				'decoded'       => $valid_decoded,
				'events'        => $valid_events,
				'receivedCalls' => $valid_received,
				'sendCalls'     => $valid_send,
				'tickCalls'     => $valid_tick,
			)
		);

		$invalid_decoded = null === $invalid_capture ? null : json_decode( self::terminal_body( $invalid_capture ), true );
		self::collect_failure(
			$failures,
			is_array( $invalid_decoded )
				&& array( 'verify-failed', 'refresh-nonces' ) === $invalid_events
				&& array( $invalid_data ) === array_column( $refresh_calls, 'data' )
				&& array( array() ) === array_column( $refresh_calls, 'response' )
				&& array( $invalid_screen ) === array_column( $refresh_calls, 'screenId' )
				&& array( 'heartbeat-nonce' ) === array_column( $verify_failures, 'action' )
				&& array( $invalid_nonce ) === array_column( $verify_failures, 'nonce' )
				&& ( $invalid_data['token'] ?? null ) === ( $invalid_decoded['cfz_refreshed'] ?? null )
				&& $invalid_screen === ( $invalid_decoded['cfz_refresh_screen'] ?? null )
				&& true === ( $invalid_decoded['nonces_expired'] ?? null )
				&& ! array_key_exists( 'server_time', $invalid_decoded )
				&& null !== $invalid_capture
				&& $invalid_capture['captured']
				&& 1 === count( $invalid_capture['dieCalls'] )
				&& self::raw_die_response_is_null( $invalid_capture['dieCalls'][0] ?? array() )
				&& $invalid_capture['bufferBalanced']
				&& $invalid_capture['filtersRestored']
				&& false === \has_filter( 'wp_refresh_nonces', $refresh_nonces )
				&& false === \has_action( 'wp_verify_nonce_failed', $verify_nonce_failed )
				&& false === \has_filter( 'heartbeat_received', $unexpected_heartbeat )
				&& false === \has_filter( 'heartbeat_send', $unexpected_heartbeat )
				&& false === \has_action( 'heartbeat_tick', $unexpected_tick ),
			'wp_ajax_heartbeat() invalid nonce refreshes nonces and exits before heartbeat filters or server_time',
			array(
				'capture'        => $invalid_capture,
				'decoded'        => $invalid_decoded,
				'events'         => $invalid_events,
				'refreshCalls'   => $refresh_calls,
				'verifyFailures' => $verify_failures,
			)
		);

		self::collect_failure(
			$failures,
			self::superglobals_match( $local )
				&& self::globals_match( $user_snapshot )
				&& $db_before === self::db_content_counts(),
			'wp_ajax_heartbeat() nonce branch checks restore request/user globals and avoid DB content mutation',
			array(
				'dbBefore' => $db_before,
				'dbAfter'  => self::db_content_counts(),
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.heartbeat.nonce-branch-hooks',
			array() === $failures,
			array(
				'cases'    => array(
					'valid'   => array(
						'bytes'  => null === $valid_capture ? 0 : strlen( self::terminal_body( $valid_capture ) ),
						'events' => $valid_events,
					),
					'invalid' => array(
						'bytes'  => null === $invalid_capture ? 0 : strlen( self::terminal_body( $invalid_capture ) ),
						'events' => $invalid_events,
					),
				),
				'failures' => array_slice( $failures, 0, 6 ),
			)
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

	private static function check_find_posts_modal_and_ajax( \ComponentFuzz\FuzzContext $ctx ): array {
		$required = array(
			'WP_Post',
			'WP_Post_Type',
			'WP_Query',
			'__',
			'_e',
			'check_ajax_referer',
			'create_initial_post_types',
			'esc_attr',
			'esc_attr_e',
			'esc_html',
			'find_posts_div',
			'get_post_type_object',
			'get_post_types',
			'get_posts',
			'mysql2date',
			'register_post_type',
			'submit_button',
			'wp_ajax_find_posts',
			'wp_create_nonce',
			'wp_nonce_field',
			'wp_send_json_error',
			'wp_send_json_success',
			'wp_slash',
		);
		$missing  = array();
		foreach ( $required as $symbol ) {
			if ( str_starts_with( $symbol, 'WP_' ) ) {
				if ( ! class_exists( $symbol ) ) {
					$missing[] = "class {$symbol}";
				}
				continue;
			}

			if ( ! function_exists( $symbol ) ) {
				$missing[] = "function {$symbol}";
			}
		}

		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-ajax.find-posts.modal-query-json',
				true,
				array( 'missing' => $missing ),
				'skipped'
			);
		}

		$failures          = array();
		$case              = self::find_posts_case( $ctx );
		$local             = self::snapshot_superglobals();
		$user_snapshot     = self::snapshot_globals( array( 'current_user', 'userdata', 'user_ID' ) );
		$registry_snapshot = self::snapshot_globals( array( '_wp_post_type_features', 'post_type_meta_caps', 'wp_post_types' ) );
		$db_before         = self::db_content_counts();
		$start_level       = ob_get_level();
		$query_calls       = array();
		$mode              = 'success';

		$query_filter = static function ( $posts, \WP_Query $query ) use ( &$query_calls, &$mode, $case ): array {
			unset( $posts );
			$filtered_posts = self::find_posts_query_posts( $case, $query, $mode );
			$query_calls[] = array(
				'mode'        => $mode,
				'queryVars'   => $query->query_vars,
				'returnedIds' => self::find_posts_post_ids( $filtered_posts ),
			);

			return $filtered_posts;
		};

		try {
			\wp_set_current_user( 0 );
			foreach ( $case['postTypes'] as $post_type => $args ) {
				\register_post_type( $post_type, $args );
			}

			$expected_post_types = array_keys( \get_post_types( array( 'public' => true ), 'objects' ) );
			$expected_post_types = array_values( array_diff( $expected_post_types, array( 'attachment' ) ) );
			sort( $expected_post_types );

			$modal = self::capture_output(
				static function () use ( $case ): void {
					\find_posts_div( $case['foundAction'] );
				}
			);

			self::collect_failure(
				$failures,
				str_contains( $modal, 'id="find-posts"' )
					&& str_contains( $modal, 'name="found_action" value="' . \esc_attr( $case['foundAction'] ) . '"' )
					&& str_contains( $modal, 'name="affected" id="affected" value=""' )
					&& str_contains( $modal, 'name="_ajax_nonce"' )
					&& str_contains( $modal, 'id="find-posts-input" name="ps" value=""' )
					&& str_contains( $modal, 'id="find-posts-search"' )
					&& str_contains( $modal, 'id="find-posts-response"' )
					&& str_contains( $modal, 'name="find-posts-submit"' )
					&& ! str_contains( $modal, '<script>' )
					&& ! str_contains( $modal, 'onfocus=' ),
				'find_posts_div() prints the modal shell with escaped found_action, nonce, search, response, and submit controls',
				array(
					'foundAction' => $case['foundAction'],
					'modal'       => self::describe_string( $modal ),
				)
			);

			\add_filter( 'posts_pre_query', $query_filter, 10, 2 );
			try {
				self::set_request_globals(
					array(),
					array(
						'action'      => 'find-posts',
						'_ajax_nonce' => \wp_create_nonce( 'find-posts' ),
						'ps'          => \wp_slash( $case['search'] ),
					)
				);
				$success_capture = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_find_posts();
					},
					true
				);

				$mode = 'empty';
				self::set_request_globals(
					array(),
					array(
						'action'      => 'find-posts',
						'_ajax_nonce' => \wp_create_nonce( 'find-posts' ),
						'ps'          => '',
					)
				);
				$empty_capture = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_find_posts();
					},
					true
				);
			} finally {
				\remove_filter( 'posts_pre_query', $query_filter, 10 );
			}

			$referer_calls  = array();
			$referer_action = static function ( $seen_action, $result ) use ( &$referer_calls ): void {
				$referer_calls[] = array(
					'action' => $seen_action,
					'result' => $result,
				);
			};
			\add_action( 'check_ajax_referer', $referer_action, 10, 2 );
			try {
				self::set_request_globals(
					array(),
					array(
						'action'      => 'find-posts',
						'_ajax_nonce' => 'bad-' . $case['token'],
						'ps'          => \wp_slash( 'blocked ' . $case['search'] ),
					)
				);
				$query_count_before_invalid = count( $query_calls );
				$invalid_capture            = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_find_posts();
					},
					true
				);
			} finally {
				\remove_action( 'check_ajax_referer', $referer_action, 10 );
			}
		} finally {
			\remove_filter( 'posts_pre_query', $query_filter, 10 );
			if ( isset( $referer_action ) ) {
				\remove_action( 'check_ajax_referer', $referer_action, 10 );
			}
			self::restore_superglobals( $local );
			self::restore_globals( $user_snapshot );
			self::restore_globals( $registry_snapshot );
		}

		$success_decoded = json_decode( self::terminal_body( $success_capture ?? array() ), true );
		$success_html    = is_array( $success_decoded ) && is_string( $success_decoded['data'] ?? null ) ? $success_decoded['data'] : '';
		$success_query   = $query_calls[0]['queryVars'] ?? array();
		$empty_decoded   = json_decode( self::terminal_body( $empty_capture ?? array() ), true );
		$empty_query     = $query_calls[1]['queryVars'] ?? array();
		$invalid_die     = $invalid_capture['dieCalls'][0] ?? array();
		$success_rows_ok = self::find_posts_success_rows_ok( $success_html, $case );
		$expected_ids    = self::find_posts_post_ids( $case['expectedPosts'] );

		self::collect_failure(
			$failures,
			is_array( $success_decoded )
				&& true === ( $success_decoded['success'] ?? null )
				&& $success_rows_ok
				&& $expected_ids === ( $query_calls[0]['returnedIds'] ?? array() )
				&& $expected_post_types === array_values( (array) ( $success_query['post_type'] ?? array() ) )
				&& 'any' === ( $success_query['post_status'] ?? null )
				&& 50 === (int) ( $success_query['posts_per_page'] ?? 0 )
				&& true === ( $success_query['ignore_sticky_posts'] ?? null )
				&& true === ( $success_query['no_found_rows'] ?? null )
				&& $case['search'] === ( $success_query['s'] ?? null )
				&& ! in_array( 'attachment', (array) ( $success_query['post_type'] ?? array() ), true )
				&& $success_capture['captured']
				&& $success_capture['bufferBalanced']
				&& $success_capture['filtersRestored'],
			'wp_ajax_find_posts() valid search returns escaped table rows and exact public post-type query args without attachments',
			array(
				'decoded'           => $success_decoded,
				'queryVars'         => $success_query,
				'expectedPostTypes' => $expected_post_types,
				'expectedIds'       => $expected_ids,
				'returnedIds'       => $query_calls[0]['returnedIds'] ?? array(),
				'successRowsOk'     => $success_rows_ok,
				'capture'           => $success_capture,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $empty_decoded )
				&& false === ( $empty_decoded['success'] ?? null )
				&& 'No items found.' === ( $empty_decoded['data'] ?? null )
				&& $expected_post_types === array_values( (array) ( $empty_query['post_type'] ?? array() ) )
				&& ( ! isset( $empty_query['s'] ) || '' === $empty_query['s'] )
				&& $empty_capture['captured']
				&& $empty_capture['bufferBalanced']
				&& $empty_capture['filtersRestored'],
			'wp_ajax_find_posts() empty search omits a meaningful search term and returns the stable no-items JSON error',
			array(
				'decoded'   => $empty_decoded,
				'queryVars' => $empty_query,
				'capture'   => $empty_capture,
			)
		);

		self::collect_failure(
			$failures,
			isset( $query_count_before_invalid )
				&& count( $query_calls ) === $query_count_before_invalid
				&& '-1' === self::terminal_body( $invalid_capture ?? array() )
				&& 403 === ( $invalid_die['processed']['args']['response'] ?? null )
				&& array( 'find-posts' ) === array_column( $referer_calls ?? array(), 'action' )
				&& array( false ) === array_column( $referer_calls ?? array(), 'result' )
				&& false === \has_action( 'check_ajax_referer', $referer_action ?? '__missing_find_posts_referer_action__' )
				&& ( $invalid_capture['captured'] ?? false )
				&& ( $invalid_capture['bufferBalanced'] ?? false )
				&& ( $invalid_capture['filtersRestored'] ?? false ),
			'wp_ajax_find_posts() invalid nonce dies with -1/403 before querying posts',
			array(
				'queryCountBeforeInvalid' => $query_count_before_invalid ?? null,
				'queryCountAfterInvalid'  => count( $query_calls ),
				'capture'                 => $invalid_capture ?? null,
				'refererCalls'            => $referer_calls ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			self::superglobals_match( $local )
				&& self::globals_match( $user_snapshot )
				&& self::find_posts_registry_restored( $registry_snapshot, $case )
				&& false === \has_filter( 'posts_pre_query', $query_filter )
				&& false === \has_action( 'check_ajax_referer', $referer_action ?? '__missing_find_posts_referer_action__' )
				&& $start_level === ob_get_level()
				&& $db_before === self::db_content_counts(),
			'Find Posts modal and Ajax checks restore request/user/post-type/hook state without DB mutations',
			array(
				'dbBefore'         => $db_before,
				'dbAfter'          => self::db_content_counts(),
				'queryCalls'       => $query_calls,
				'hookRestored'     => false === \has_filter( 'posts_pre_query', $query_filter ),
				'refererHookRestored' => false === \has_action( 'check_ajax_referer', $referer_action ?? '__missing_find_posts_referer_action__' ),
				'startLevel'       => $start_level,
				'endLevel'         => ob_get_level(),
				'registryRestored' => self::find_posts_registry_restored( $registry_snapshot, $case ),
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.find-posts.modal-query-json',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_attachment_ajax_workflows( \ComponentFuzz\FuzzContext $ctx ): array {
		$required = array(
			'WP_Query',
			'WP_Rewrite',
			'check_ajax_referer',
			'create_initial_post_types',
			'current_user_can',
			'get_post',
			'get_post_meta',
			'get_post_type_object',
			'wp_ajax_query_attachments',
			'wp_ajax_save_attachment',
			'wp_create_nonce',
			'wp_insert_post',
			'wp_prepare_attachment_for_js',
			'wp_send_json_error',
			'wp_send_json_success',
			'wp_set_current_user',
			'wp_slash',
			'wp_strip_all_tags',
			'wp_unslash',
			'update_post_meta',
		);
		$missing  = array();

		foreach ( $required as $symbol ) {
			if ( str_starts_with( $symbol, 'WP_' ) ) {
				if ( ! class_exists( $symbol ) ) {
					$missing[] = "class {$symbol}";
				}
				continue;
			}

			if ( ! function_exists( $symbol ) ) {
				$missing[] = "function {$symbol}";
			}
		}

		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-ajax.attachments.query-save-workflows',
				true,
				array( 'missing' => $missing ),
				'skipped'
			);
		}

		$failures        = array();
		$local           = self::snapshot_superglobals();
		$user_snapshot   = self::snapshot_globals( array( 'current_user', 'userdata', 'user_ID' ) );
		$registry_snapshot = self::snapshot_globals( array( '_wp_post_type_features', 'post_type_meta_caps', 'wp_post_statuses', 'wp_post_types', 'wp_rewrite' ) );
		$start_level     = ob_get_level();
		$fixtures        = array();
		$case            = self::attachment_ajax_case( $ctx );
		$query_cases     = array();
		$db_before_seed  = self::db_content_counts();
		$db_after_seed   = null;
		$db_after_delete = null;
		$slug_filter     = static function ( $override_slug, string $slug ): string {
			unset( $override_slug );
			return $slug;
		};

		\add_filter( 'pre_wp_unique_post_slug', $slug_filter, 10, 6 );
		try {
			if ( ! \get_post_type_object( 'attachment' ) ) {
				\create_initial_post_types();
			}
			if ( ! isset( $GLOBALS['wp_rewrite'] ) || ! $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
				$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			}

			\wp_set_current_user( 0 );
			$fixtures      = self::seed_attachment_ajax_fixtures( $case );
			$db_after_seed = self::db_content_counts();

			$query_grant = self::cap_grant_filter( array( 'upload_files' ) );
			\add_filter( 'user_has_cap', $query_grant, 10, 4 );
			try {
				$mimetype_query = array(
					'order'          => 'DESC',
					'orderby'        => 'date',
					'paged'          => 2,
					'post_mime_type' => 'image',
					'posts_per_page' => 2,
				);
				self::set_request_globals(
					array(),
					array(
						'action' => 'query-attachments',
						'query'  => $mimetype_query,
					)
				);
				$mimetype_capture = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_query_attachments();
					},
					true
				);
				$mimetype_decoded = json_decode( self::terminal_body( $mimetype_capture ), true );
				$mimetype_ids     = self::decoded_attachment_ids( $mimetype_decoded );
				$mimetype_shape   = self::decoded_attachment_shape_ok( $mimetype_decoded );
				$expected_mimetype_ids = array(
					$fixtures['titleSearch'],
					$fixtures['imageNew'],
				);
				$mimetype_ok      = is_array( $mimetype_decoded )
					&& true === ( $mimetype_decoded['success'] ?? null )
					&& $expected_mimetype_ids === $mimetype_ids
					&& $mimetype_shape
					&& self::decoded_attachment_field( $mimetype_decoded, 0, 'mime' ) === 'image/jpeg'
					&& self::decoded_attachment_field( $mimetype_decoded, 1, 'mime' ) === 'image/png'
					&& self::decoded_attachment_field( $mimetype_decoded, 0, 'filename' ) === basename( $case['files']['titleSearch'] )
					&& ! in_array( $fixtures['pdf'], $mimetype_ids, true )
					&& ! in_array( $fixtures['filenameSearch'], $mimetype_ids, true )
					&& ! in_array( $fixtures['nonAttachment'], $mimetype_ids, true )
					&& ! in_array( $fixtures['privateImage'], $mimetype_ids, true )
					&& $mimetype_capture['captured']
					&& $mimetype_capture['bufferBalanced']
					&& $mimetype_capture['filtersRestored'];

				self::collect_failure(
					$failures,
					$mimetype_ok,
					'wp_ajax_query_attachments() applies MIME filtering plus date-desc paging and excludes mismatched/private/non-attachment rows',
					array(
						'capture'     => $mimetype_capture,
						'decoded'     => $mimetype_decoded,
						'expectedIds' => $expected_mimetype_ids,
						'actualIds'   => $mimetype_ids,
					)
				);
				$query_cases[] = array(
					'name' => 'mime-date-paged',
					'ids'  => $mimetype_ids,
				);

				$filename_hook_snapshot = self::snapshot_hook( 'wp_allow_query_attachment_by_filename' );
				$sentinel_filter        = static function (): bool {
					return false;
				};
				\add_filter( 'wp_allow_query_attachment_by_filename', $sentinel_filter, 9, 1 );
				$search_query           = array(
					'order'          => 'DESC',
					'orderby'        => 'ID',
					'posts_per_page' => 5,
					's'              => $case['searchToken'],
				);
				try {
					self::set_request_globals(
						array(),
						array(
							'action' => 'query-attachments',
							'query'  => $search_query,
						)
					);
					$search_capture = self::capture_terminating_call(
						static function (): void {
							\wp_ajax_query_attachments();
						},
						true
					);
				} finally {
					self::restore_hook( 'wp_allow_query_attachment_by_filename', $filename_hook_snapshot );
				}
				$search_decoded = json_decode( self::terminal_body( $search_capture ), true );
				$search_ids     = self::decoded_attachment_ids( $search_decoded );
				$expected_search_ids = array(
					$fixtures['filenameSearch'],
					$fixtures['titleSearch'],
				);
				$search_ok      = is_array( $search_decoded )
					&& true === ( $search_decoded['success'] ?? null )
					&& $expected_search_ids === $search_ids
					&& self::decoded_attachment_shape_ok( $search_decoded )
					&& self::decoded_attachment_field( $search_decoded, 0, 'filename' ) === basename( $case['files']['filenameSearch'] )
					&& false === \has_filter( 'wp_allow_query_attachment_by_filename', '__return_true' )
					&& self::hook_matches( 'wp_allow_query_attachment_by_filename', $filename_hook_snapshot )
					&& $search_capture['captured']
					&& $search_capture['bufferBalanced']
					&& $search_capture['filtersRestored'];

				self::collect_failure(
					$failures,
					$search_ok,
					'wp_ajax_query_attachments() searches title/content and attached filenames while removing the filename-search filter',
					array(
						'capture'     => $search_capture,
						'decoded'     => $search_decoded,
						'expectedIds' => $expected_search_ids,
						'actualIds'   => $search_ids,
						'hasFilenameFilter' => \has_filter( 'wp_allow_query_attachment_by_filename', '__return_true' ),
						'hookRestored' => self::hook_matches( 'wp_allow_query_attachment_by_filename', $filename_hook_snapshot ),
					)
				);
				$query_cases[] = array(
					'name' => 'search-title-filename',
					'ids'  => $search_ids,
				);
			} finally {
				\remove_filter( 'user_has_cap', $query_grant, 10 );
			}

			self::set_request_globals(
				array(),
				array(
					'action' => 'query-attachments',
					'query'  => array(
						'post_mime_type' => 'image',
						'posts_per_page' => 3,
						's'              => $case['searchToken'],
					),
				)
			);
			$query_denied_capture = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_query_attachments();
				},
				true
			);
			$query_denied_decoded = json_decode( self::terminal_body( $query_denied_capture ), true );
			self::collect_failure(
				$failures,
				is_array( $query_denied_decoded )
					&& false === ( $query_denied_decoded['success'] ?? null )
					&& ! array_key_exists( 'data', $query_denied_decoded )
					&& $query_denied_capture['captured']
					&& $query_denied_capture['bufferBalanced']
					&& $query_denied_capture['filtersRestored']
					&& $db_after_seed === self::db_content_counts(),
				'wp_ajax_query_attachments() capability denial returns stable JSON error before mutating attachment fixtures',
				array(
					'capture'  => $query_denied_capture,
					'decoded'  => $query_denied_decoded,
					'dbBefore' => $db_after_seed,
					'dbAfter'  => self::db_content_counts(),
				)
			);

			$target_before = \get_post( $fixtures['saveTarget'], ARRAY_A );
			$other_before  = \get_post( $fixtures['otherAttachment'], ARRAY_A );
			$other_alt_before = \get_post_meta( $fixtures['otherAttachment'], '_wp_attachment_image_alt', true );
			$target_unrelated_before = \get_post_meta( $fixtures['saveTarget'], '_cfz_unrelated_attachment_meta', true );
			$save_nonce    = \wp_create_nonce( 'update-post_' . $fixtures['saveTarget'] );
			$save_changes  = array(
				'alt'         => $case['saveAlt'],
				'caption'     => $case['saveCaption'],
				'description' => $case['saveDescription'],
				'title'       => $case['saveTitle'],
			);
			$save_grant    = self::cap_grant_filter( array( 'edit_post' ) );
			\add_filter( 'user_has_cap', $save_grant, 10, 4 );
			try {
				self::set_request_globals(
					array(),
					array(
						'action'  => 'save-attachment',
						'changes' => \wp_slash( $save_changes ),
						'id'      => (string) $fixtures['saveTarget'],
						'nonce'   => $save_nonce,
					)
				);
				$save_capture = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_save_attachment();
					},
					true
				);
			} finally {
				\remove_filter( 'user_has_cap', $save_grant, 10 );
			}

			$save_decoded       = json_decode( self::terminal_body( $save_capture ), true );
			$target_after       = \get_post( $fixtures['saveTarget'], ARRAY_A );
			$other_after        = \get_post( $fixtures['otherAttachment'], ARRAY_A );
			$target_alt_after   = \get_post_meta( $fixtures['saveTarget'], '_wp_attachment_image_alt', true );
			$other_alt_after    = \get_post_meta( $fixtures['otherAttachment'], '_wp_attachment_image_alt', true );
			$target_unrelated_after = \get_post_meta( $fixtures['saveTarget'], '_cfz_unrelated_attachment_meta', true );
			$expected_alt       = \wp_strip_all_tags( \wp_unslash( $save_changes['alt'] ), true );
			$save_ok            = is_array( $save_decoded )
				&& true === ( $save_decoded['success'] ?? null )
				&& ! array_key_exists( 'data', $save_decoded )
				&& is_array( $target_before )
				&& is_array( $target_after )
				&& \wp_unslash( $save_changes['title'] ) === (string) ( $target_after['post_title'] ?? '' )
				&& \wp_unslash( $save_changes['caption'] ) === (string) ( $target_after['post_excerpt'] ?? '' )
				&& \wp_unslash( $save_changes['description'] ) === (string) ( $target_after['post_content'] ?? '' )
				&& $expected_alt === $target_alt_after
				&& (string) ( $target_before['post_mime_type'] ?? '' ) === (string) ( $target_after['post_mime_type'] ?? '' )
				&& (int) ( $target_before['post_parent'] ?? -1 ) === (int) ( $target_after['post_parent'] ?? -2 )
				&& $target_unrelated_before === $target_unrelated_after
				&& $other_before === $other_after
				&& $other_alt_before === $other_alt_after
				&& $save_capture['captured']
				&& $save_capture['bufferBalanced']
				&& $save_capture['filtersRestored']
				&& false === \has_filter( 'user_has_cap', $save_grant );

			self::collect_failure(
				$failures,
				$save_ok,
				'wp_ajax_save_attachment() updates only targeted title/caption/description/alt fields with expected slashing and alt stripping',
				array(
					'capture'       => $save_capture,
					'decoded'       => $save_decoded,
					'targetBefore'  => self::summarize_post_row( $target_before ),
					'targetAfter'   => self::summarize_post_row( $target_after ),
					'expectedAlt'   => $expected_alt,
					'actualAlt'     => $target_alt_after,
					'otherChanged'  => $other_before != $other_after,
				)
			);

			$after_success_post = \get_post( $fixtures['saveTarget'], ARRAY_A );
			$after_success_alt  = \get_post_meta( $fixtures['saveTarget'], '_wp_attachment_image_alt', true );
			$nonce_events       = array();
			$nonce_action       = static function ( $action, $result ) use ( &$nonce_events ): void {
				$nonce_events[] = array(
					'action' => $action,
					'result' => $result,
				);
			};
			$nonce_grant        = self::cap_grant_filter( array( 'edit_post' ) );
			\add_action( 'check_ajax_referer', $nonce_action, 10, 2 );
			\add_filter( 'user_has_cap', $nonce_grant, 10, 4 );
			try {
				self::set_request_globals(
					array(),
					array(
						'action'  => 'save-attachment',
						'changes' => \wp_slash(
							array(
								'alt'   => 'blocked alt ' . $case['token'],
								'title' => 'Blocked title ' . $case['token'],
							)
						),
						'id'      => (string) $fixtures['saveTarget'],
						'nonce'   => 'bad-' . $case['token'],
					)
				);
				$save_bad_nonce_capture = self::capture_terminating_call(
					static function (): void {
						\wp_ajax_save_attachment();
					},
					true
				);
			} finally {
				\remove_filter( 'user_has_cap', $nonce_grant, 10 );
				\remove_action( 'check_ajax_referer', $nonce_action, 10 );
			}

			$bad_nonce_die = $save_bad_nonce_capture['dieCalls'][0] ?? array();
			self::collect_failure(
				$failures,
				$save_bad_nonce_capture['captured']
					&& '-1' === self::terminal_body( $save_bad_nonce_capture )
					&& 403 === ( $bad_nonce_die['processed']['args']['response'] ?? null )
					&& array( 'update-post_' . $fixtures['saveTarget'] ) === array_column( $nonce_events, 'action' )
					&& array( false ) === array_column( $nonce_events, 'result' )
					&& $after_success_post === \get_post( $fixtures['saveTarget'], ARRAY_A )
					&& $after_success_alt === \get_post_meta( $fixtures['saveTarget'], '_wp_attachment_image_alt', true )
					&& false === \has_action( 'check_ajax_referer', $nonce_action )
					&& false === \has_filter( 'user_has_cap', $nonce_grant )
					&& $save_bad_nonce_capture['bufferBalanced']
					&& $save_bad_nonce_capture['filtersRestored'],
				'wp_ajax_save_attachment() invalid nonce dies with -1/403 before post or meta mutation',
				array(
					'capture'     => $save_bad_nonce_capture,
					'nonceEvents' => $nonce_events,
				)
			);

			$valid_nonce_for_cap_denial = \wp_create_nonce( 'update-post_' . $fixtures['saveTarget'] );
			self::set_request_globals(
				array(),
				array(
					'action'  => 'save-attachment',
					'changes' => \wp_slash(
						array(
							'alt'         => 'denied alt ' . $case['token'],
							'description' => 'Denied description ' . $case['token'],
							'title'       => 'Denied title ' . $case['token'],
						)
					),
					'id'      => (string) $fixtures['saveTarget'],
					'nonce'   => $valid_nonce_for_cap_denial,
				)
			);
			$save_cap_denied_capture = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_save_attachment();
				},
				true
			);
			$save_cap_denied_decoded = json_decode( self::terminal_body( $save_cap_denied_capture ), true );
			self::collect_failure(
				$failures,
				is_array( $save_cap_denied_decoded )
					&& false === ( $save_cap_denied_decoded['success'] ?? null )
					&& ! array_key_exists( 'data', $save_cap_denied_decoded )
					&& $after_success_post === \get_post( $fixtures['saveTarget'], ARRAY_A )
					&& $after_success_alt === \get_post_meta( $fixtures['saveTarget'], '_wp_attachment_image_alt', true )
					&& $save_cap_denied_capture['captured']
					&& $save_cap_denied_capture['bufferBalanced']
					&& $save_cap_denied_capture['filtersRestored'],
				'wp_ajax_save_attachment() capability denial returns JSON error before post or meta mutation',
				array(
					'capture' => $save_cap_denied_capture,
					'decoded' => $save_cap_denied_decoded,
				)
			);

			self::collect_failure(
				$failures,
				$db_after_seed === self::db_content_counts(),
				'attachment Ajax workflow cases keep fixture row counts bounded',
				array(
					'dbAfterSeed' => $db_after_seed,
					'dbAfterCases' => self::db_content_counts(),
				)
			);
		} finally {
			self::restore_superglobals( $local );
			self::restore_globals( $user_snapshot );
			\remove_filter( 'pre_wp_unique_post_slug', $slug_filter, 10 );
			self::delete_attachment_ajax_fixtures( $fixtures );
			self::restore_globals( $registry_snapshot );
			$db_after_delete = self::db_content_counts();
		}

		self::collect_failure(
			$failures,
			self::superglobals_match( $local )
				&& self::globals_match( $user_snapshot )
				&& self::globals_match( $registry_snapshot )
				&& $start_level === ob_get_level()
				&& false === \has_filter( 'pre_wp_unique_post_slug', $slug_filter )
				&& $db_before_seed === $db_after_delete
				&& self::attachment_ajax_fixtures_deleted( $fixtures ),
			'attachment Ajax workflows restore request/user globals, output buffers, and seeded fixture rows',
			array(
				'dbBeforeSeed'  => $db_before_seed,
				'dbAfterDelete' => $db_after_delete,
				'registryRestored' => self::globals_match( $registry_snapshot ),
				'slugFilter'    => \has_filter( 'pre_wp_unique_post_slug', $slug_filter ),
				'fixturesDeleted' => self::attachment_ajax_fixtures_deleted( $fixtures ),
				'startLevel'    => $start_level,
				'endLevel'      => ob_get_level(),
			)
		);

		return self::row(
			$ctx,
			'admin-ajax.attachments.query-save-workflows',
			array() === $failures,
			array(
				'queryCases' => $query_cases,
				'fixtures'   => array_intersect_key(
					$fixtures,
					array_fill_keys(
						array(
							'imageOld',
							'imageNew',
							'pdf',
							'titleSearch',
							'filenameSearch',
							'saveTarget',
							'otherAttachment',
							'nonAttachment',
						),
						true
					)
				),
				'failures'  => array_slice( $failures, 0, 10 ),
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

	private static function capture_output( callable $callback ): string {
		$start_level = ob_get_level();
		$output      = '';

		ob_start();
		try {
			$callback();
		} finally {
			while ( ob_get_level() > $start_level ) {
				$chunk  = ob_get_clean();
				$output = ( false === $chunk ? '' : $chunk ) . $output;
			}
		}

		return $output;
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

	private static function find_posts_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token      = self::slug( $ctx->fork( 'token' ), 'find' );
		$primary    = 'cfz_find_' . substr( $token, 0, 10 );
		$secondary  = 'cfz_pick_' . substr( self::slug( $ctx->fork( 'secondary' ), 'pick' ), 0, 10 );
		$private_pt = 'cfz_hidden_' . substr( self::slug( $ctx->fork( 'hidden' ), 'hidden' ), 0, 8 );
		$needle     = 'find_' . self::safe_label( $ctx->fork( 'search' ) );
		$search     = $needle . ' <b>& quoted</b>';

		$expected_posts = array(
			self::find_posts_post(
				11001 + $ctx->int( 1, 200 ),
				$primary,
				'publish',
				'Published ' . $needle . ' <script>alert(1)</script> & "quoted" ' . $token,
				'2026-07-01 10:11:12'
			),
			self::find_posts_post(
				12001 + $ctx->int( 1, 200 ),
				$secondary,
				'private',
				'Private ' . $needle . ' & <strong>escaped</strong> ' . $token,
				'2026-07-02 11:12:13'
			),
			self::find_posts_post(
				13001 + $ctx->int( 1, 200 ),
				$primary,
				'future',
				'Scheduled ' . $needle . ' ' . $token,
				'2026-08-03 12:13:14'
			),
			self::find_posts_post(
				14001 + $ctx->int( 1, 200 ),
				$secondary,
				'pending',
				'Pending ' . $needle . ' ' . $token,
				'0000-00-00 00:00:00'
			),
			self::find_posts_post(
				15001 + $ctx->int( 1, 200 ),
				$primary,
				'draft',
				" \t\n",
				'2026-09-04 13:14:15',
				'Blank title ' . $needle . ' ' . $token
			),
		);
		$excluded_posts  = array(
			self::find_posts_post(
				16001 + $ctx->int( 1, 200 ),
				'attachment',
				'publish',
				'Attachment ' . $needle . ' excluded ' . $token,
				'2026-10-05 14:15:16'
			),
			self::find_posts_post(
				17001 + $ctx->int( 1, 200 ),
				$private_pt,
				'publish',
				'Hidden type ' . $needle . ' excluded ' . $token,
				'2026-11-06 15:16:17'
			),
			self::find_posts_post(
				18001 + $ctx->int( 1, 200 ),
				$primary,
				'publish',
				'Public nonmatching title ' . $token,
				'2026-12-07 16:17:18'
			),
		);

		return array(
			'excludedPosts' => $excluded_posts,
			'expectedPosts' => $expected_posts,
			'foundAction'   => 'attach" onclick="bad()" <script>' . $token,
			'needle'        => $needle,
			'postTypes'     => array(
				$primary    => array(
					'label'        => 'Find Primary ' . $token,
					'labels'       => array(
						'singular_name' => 'Primary <em>Type</em> & "' . $token . '"',
					),
					'public'       => true,
					'show_ui'      => false,
					'show_in_rest' => false,
					'rewrite'      => false,
					'query_var'    => false,
				),
				$secondary  => array(
					'label'        => 'Find Secondary ' . $token,
					'labels'       => array(
						'singular_name' => 'Secondary & <script>Type</script> ' . $token,
					),
					'public'       => true,
					'show_ui'      => false,
					'show_in_rest' => false,
					'rewrite'      => false,
					'query_var'    => false,
				),
				$private_pt => array(
					'label'        => 'Hidden Find ' . $token,
					'public'       => false,
					'show_ui'      => false,
					'show_in_rest' => false,
					'rewrite'      => false,
					'query_var'    => false,
				),
			),
			'posts'         => array_merge( $expected_posts, $excluded_posts ),
			'search'        => $search,
			'token'         => $token,
		);
	}

	private static function find_posts_post( int $id, string $post_type, string $status, string $title, string $date, string $content = '' ): \WP_Post {
		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => 0,
				'post_date'             => $date,
				'post_date_gmt'         => $date,
				'post_content'          => '' === $content ? 'Generated Find Posts content for ' . $id : $content,
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => $status,
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'cfz-find-post-' . $id,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $date,
				'post_modified_gmt'     => $date,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'https://example.test/?p=' . $id,
				'menu_order'            => 0,
				'post_type'             => $post_type,
				'post_mime_type'        => '',
				'comment_count'         => 0,
				'filter'                => 'raw',
			)
		);
	}

	private static function find_posts_query_posts( array $case, \WP_Query $query, string $mode ): array {
		if ( 'empty' === $mode ) {
			return array();
		}

		if ( $case['search'] !== (string) $query->get( 's' ) ) {
			return array();
		}

		$post_types = (array) $query->get( 'post_type' );
		$needle     = strtolower( $case['needle'] );

		return array_values(
			array_filter(
				$case['posts'],
				static function ( $post ) use ( $post_types, $needle ): bool {
					return $post instanceof \WP_Post
						&& in_array( $post->post_type, $post_types, true )
						&& (
							str_contains( strtolower( $post->post_title ), $needle )
							|| str_contains( strtolower( $post->post_content ), $needle )
						);
				}
			)
		);
	}

	private static function find_posts_post_ids( array $posts ): array {
		return array_map(
			static fn ( \WP_Post $post ): int => (int) $post->ID,
			$posts
		);
	}

	private static function find_posts_success_rows_ok( string $html, array $case ): bool {
		if (
			! str_contains( $html, '<table class="widefat">' )
			|| ! str_contains( $html, '<th>Title</th>' )
			|| ! str_contains( $html, 'found-radio' )
			|| str_contains( $html, '<script>' )
			|| str_contains( $html, '<strong>' )
			|| str_contains( $html, '<em>' )
		) {
			return false;
		}

		$status_labels = array(
			'publish' => 'Published',
			'private' => 'Published',
			'future'  => 'Scheduled',
			'pending' => 'Pending Review',
			'draft'   => 'Draft',
		);

		foreach ( $case['expectedPosts'] as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				return false;
			}

			$title      = trim( $post->post_title ) ? $post->post_title : '(no title)';
			$type       = \get_post_type_object( $post->post_type );
			$type_label = $type ? $type->labels->singular_name : '';
			$time       = '0000-00-00 00:00:00' === $post->post_date ? '' : \mysql2date( __( 'Y/m/d' ), $post->post_date );

			if (
				! str_contains( $html, 'id="found-' . $post->ID . '"' )
				|| ! str_contains( $html, 'value="' . \esc_attr( (string) $post->ID ) . '"' )
				|| ! str_contains( $html, '<label for="found-' . $post->ID . '">' . \esc_html( $title ) . '</label>' )
				|| ! str_contains( $html, '<td class="no-break">' . \esc_html( $type_label ) . '</td>' )
				|| ! str_contains( $html, '<td class="no-break">' . \esc_html( $time ) . '</td>' )
				|| ! str_contains( $html, '<td class="no-break">' . \esc_html( $status_labels[ $post->post_status ] ) . ' </td>' )
			) {
				return false;
			}
		}

		foreach ( $case['excludedPosts'] as $post ) {
			if (
				$post instanceof \WP_Post
				&& (
					str_contains( $html, 'id="found-' . $post->ID . '"' )
					|| str_contains( $html, 'value="' . \esc_attr( (string) $post->ID ) . '"' )
				)
			) {
				return false;
			}
		}

		return true;
	}

	private static function find_posts_registry_restored( array $snapshot, array $case ): bool {
		foreach ( array_keys( $case['postTypes'] ) as $post_type ) {
			if ( isset( $GLOBALS['wp_post_types'][ $post_type ] ) ) {
				return false;
			}
			if ( isset( $GLOBALS['_wp_post_type_features'][ $post_type ] ) ) {
				return false;
			}
		}

		foreach ( array( 'wp_post_types', '_wp_post_type_features' ) as $name ) {
			$expected = array_keys( (array) ( $snapshot[ $name ]['value'] ?? array() ) );
			$current  = array_keys( (array) ( $GLOBALS[ $name ] ?? array() ) );
			sort( $expected );
			sort( $current );
			if ( $expected !== $current ) {
				return false;
			}
		}

		return true;
	}

	private static function xml_name( \ComponentFuzz\FuzzContext $ctx, string $fallback ): string {
		$name = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '_', $ctx->identifier( 3, 16 ) ) );
		if ( '' === $name || ! preg_match( '/^[a-z_]/', $name ) ) {
			$name = $fallback . '_' . $name;
		}

		return $name;
	}

	private static function attachment_ajax_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token        = self::slug( $ctx->fork( 'token' ), 'attachment' );
		$search_token = 'cfzsearch' . self::safe_label( $ctx->fork( 'search-token' ) );
		$text_suffix  = self::safe_label( $ctx->fork( 'text-suffix' ) );

		return array(
			'files'           => array(
				'filenameSearch' => "2026/06/{$search_token}-filename-match-{$token}.txt",
				'imageNew'       => "2026/06/{$token}-new-image.jpg",
				'imageOld'       => "2026/06/{$token}-old-image.jpg",
				'otherAttachment' => "2026/06/{$token}-other-image.jpg",
				'pdf'            => "2026/06/{$token}-document.pdf",
				'privateImage'   => "2026/06/{$token}-private-image.jpg",
				'saveTarget'     => "2026/06/{$token}-save-target.jpg",
				'titleSearch'    => "2026/06/{$token}-title-match.jpg",
			),
			'saveAlt'         => "Alt {$text_suffix} <em>strip me</em> & \"quoted\"\nsecond line",
			'saveCaption'     => "Caption {$text_suffix} <strong>kept</strong> & \"quoted\"\nsecond line",
			'saveDescription' => "Description {$text_suffix} <p>body</p> & \"quoted\"\nsecond line",
			'saveTitle'       => "Title {$text_suffix} <b>bold</b> & \"quoted\"",
			'searchToken'     => $search_token,
			'token'           => $token,
		);
	}

	private static function seed_attachment_ajax_fixtures( array $case ): array {
		$post_ids = array();
		$base_url = 'http://example.test/wp-content/uploads/';
		$token    = $case['token'];

		$parent = self::insert_attachment_ajax_post(
			array(
				'post_content' => 'Attachment parent content ' . $token,
				'post_name'    => 'cfz-attachment-parent-' . $token,
				'post_status'  => 'publish',
				'post_title'   => 'Attachment Parent ' . $token,
				'post_type'    => 'post',
			)
		);
		$post_ids[] = $parent;

		$non_attachment = self::insert_attachment_ajax_post(
			array(
				'post_content' => 'Not an attachment but contains ' . $case['searchToken'],
				'post_name'    => 'cfz-not-attachment-' . $token,
				'post_status'  => 'publish',
				'post_title'   => 'Not Attachment ' . $case['searchToken'],
				'post_type'    => 'post',
			)
		);
		$post_ids[] = $non_attachment;

		$image_old = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['imageOld'],
				'post_date'      => '2026-06-01 10:00:00',
				'post_date_gmt'  => '2026-06-01 08:00:00',
				'post_mime_type' => 'image/jpeg',
				'post_name'      => 'cfz-old-image-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'inherit',
				'post_title'     => 'Old Image ' . $token,
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $image_old;
		\update_post_meta( $image_old, '_wp_attached_file', $case['files']['imageOld'] );
		\update_post_meta( $image_old, '_wp_attachment_image_alt', 'Old image alt ' . $token );

		$image_new = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['imageNew'],
				'post_date'      => '2026-06-02 10:00:00',
				'post_date_gmt'  => '2026-06-02 08:00:00',
				'post_mime_type' => 'image/png',
				'post_name'      => 'cfz-new-image-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'inherit',
				'post_title'     => 'New Image ' . $token,
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $image_new;
		\update_post_meta( $image_new, '_wp_attached_file', $case['files']['imageNew'] );
		\update_post_meta( $image_new, '_wp_attachment_image_alt', 'New image alt ' . $token );

		$private_image = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['privateImage'],
				'post_date'      => '2026-06-03 10:00:00',
				'post_date_gmt'  => '2026-06-03 08:00:00',
				'post_mime_type' => 'image/jpeg',
				'post_name'      => 'cfz-private-image-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'private',
				'post_title'     => 'Private Image ' . $token,
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $private_image;
		\update_post_meta( $private_image, '_wp_attached_file', $case['files']['privateImage'] );

		$pdf = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['pdf'],
				'post_date'      => '2026-06-04 10:00:00',
				'post_date_gmt'  => '2026-06-04 08:00:00',
				'post_mime_type' => 'application/pdf',
				'post_name'      => 'cfz-pdf-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'inherit',
				'post_title'     => 'PDF Document ' . $token,
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $pdf;
		\update_post_meta( $pdf, '_wp_attached_file', $case['files']['pdf'] );

		$title_search = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['titleSearch'],
				'post_content'   => 'Title-search attachment body ' . $token,
				'post_date'      => '2026-06-05 10:00:00',
				'post_date_gmt'  => '2026-06-05 08:00:00',
				'post_mime_type' => 'image/jpeg',
				'post_name'      => 'cfz-title-search-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'inherit',
				'post_title'     => 'Title Match ' . $case['searchToken'],
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $title_search;
		\update_post_meta( $title_search, '_wp_attached_file', $case['files']['titleSearch'] );

		$filename_search = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['filenameSearch'],
				'post_content'   => 'Filename-search body without the generated token',
				'post_date'      => '2026-06-06 10:00:00',
				'post_date_gmt'  => '2026-06-06 08:00:00',
				'post_mime_type' => 'text/plain',
				'post_name'      => 'cfz-filename-search-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'inherit',
				'post_title'     => 'Filename Match ' . $token,
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $filename_search;
		\update_post_meta( $filename_search, '_wp_attached_file', $case['files']['filenameSearch'] );

		$save_target = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['saveTarget'],
				'post_content'   => 'Original save target description ' . $token,
				'post_date'      => '2026-06-07 10:00:00',
				'post_date_gmt'  => '2026-06-07 08:00:00',
				'post_excerpt'   => 'Original save target caption ' . $token,
				'post_mime_type' => 'image/jpeg',
				'post_name'      => 'cfz-save-target-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'inherit',
				'post_title'     => 'Original Save Target ' . $token,
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $save_target;
		\update_post_meta( $save_target, '_wp_attached_file', $case['files']['saveTarget'] );
		\update_post_meta( $save_target, '_wp_attachment_image_alt', 'Original target alt ' . $token );
		\update_post_meta( $save_target, '_cfz_unrelated_attachment_meta', 'target-unrelated-' . $token );

		$other_attachment = self::insert_attachment_ajax_post(
			array(
				'guid'           => $base_url . $case['files']['otherAttachment'],
				'post_content'   => 'Other attachment description ' . $token,
				'post_date'      => '2026-06-08 10:00:00',
				'post_date_gmt'  => '2026-06-08 08:00:00',
				'post_excerpt'   => 'Other attachment caption ' . $token,
				'post_mime_type' => 'image/jpeg',
				'post_name'      => 'cfz-other-attachment-' . $token,
				'post_parent'    => $parent,
				'post_status'    => 'inherit',
				'post_title'     => 'Other Attachment ' . $token,
				'post_type'      => 'attachment',
			)
		);
		$post_ids[] = $other_attachment;
		\update_post_meta( $other_attachment, '_wp_attached_file', $case['files']['otherAttachment'] );
		\update_post_meta( $other_attachment, '_wp_attachment_image_alt', 'Other attachment alt ' . $token );

		self::flush_runtime_cache();

		return array(
			'filenameSearch' => $filename_search,
			'imageNew'       => $image_new,
			'imageOld'       => $image_old,
			'nonAttachment'  => $non_attachment,
			'otherAttachment' => $other_attachment,
			'parent'         => $parent,
			'pdf'            => $pdf,
			'postIds'        => $post_ids,
			'privateImage'   => $private_image,
			'saveTarget'     => $save_target,
			'titleSearch'    => $title_search,
		);
	}

	private static function insert_attachment_ajax_post( array $postarr ): int {
		$post_id = \wp_insert_post( \wp_slash( $postarr ), true, false );
		if ( \is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Could not seed admin-ajax attachment fixture: ' . $post_id->get_error_code() );
		}

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			throw new \RuntimeException( 'Could not seed admin-ajax attachment fixture.' );
		}

		return $post_id;
	}

	private static function cap_grant_filter( array $allowed_requested_caps ): callable {
		$allowed_requested_caps = array_values( array_map( 'strval', $allowed_requested_caps ) );

		return static function ( array $allcaps, array $caps, array $args = array(), $user = null ) use ( $allowed_requested_caps ): array {
			unset( $user );

			$requested = (string) ( $args[0] ?? '' );
			$allowed   = in_array( $requested, $allowed_requested_caps, true );
			foreach ( $caps as $cap ) {
				if ( in_array( (string) $cap, $allowed_requested_caps, true ) ) {
					$allowed = true;
					break;
				}
			}

			if ( $allowed ) {
				foreach ( $caps as $cap ) {
					if ( 'do_not_allow' !== (string) $cap ) {
						$allcaps[ (string) $cap ] = true;
					}
				}
			}

			foreach ( $allowed_requested_caps as $cap ) {
				if ( ! in_array( $cap, array( 'delete_post', 'edit_post', 'read_post' ), true ) ) {
					$allcaps[ $cap ] = true;
				}
			}

			return $allcaps;
		};
	}

	private static function decoded_attachment_ids( $decoded ): array {
		if ( ! is_array( $decoded ) || ! is_array( $decoded['data'] ?? null ) ) {
			return array();
		}

		$ids = array();
		foreach ( $decoded['data'] as $row ) {
			if ( is_array( $row ) && array_key_exists( 'id', $row ) ) {
				$ids[] = (int) $row['id'];
			}
		}

		return $ids;
	}

	private static function decoded_attachment_shape_ok( $decoded ): bool {
		if ( ! is_array( $decoded ) || ! is_array( $decoded['data'] ?? null ) || array() === $decoded['data'] ) {
			return false;
		}

		foreach ( $decoded['data'] as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}

			foreach ( array( 'id', 'title', 'filename', 'url', 'alt', 'mime', 'type', 'subtype', 'status', 'uploadedTo', 'nonces' ) as $key ) {
				if ( ! array_key_exists( $key, $row ) ) {
					return false;
				}
			}

			if (
				! is_int( $row['id'] )
				|| ! is_string( $row['title'] )
				|| ! is_string( $row['filename'] )
				|| ! is_string( $row['mime'] )
				|| ! is_string( $row['type'] )
				|| ! is_string( $row['subtype'] )
				|| ! is_array( $row['nonces'] )
			) {
				return false;
			}
		}

		return true;
	}

	private static function decoded_attachment_field( $decoded, int $index, string $field ) {
		return is_array( $decoded )
			&& is_array( $decoded['data'] ?? null )
			&& is_array( $decoded['data'][ $index ] ?? null )
			&& array_key_exists( $field, $decoded['data'][ $index ] )
				? $decoded['data'][ $index ][ $field ]
				: null;
	}

	private static function summarize_post_row( $post ): ?array {
		if ( ! is_array( $post ) ) {
			return null;
		}

		return array_intersect_key(
			$post,
			array_fill_keys(
				array(
					'ID',
					'post_content',
					'post_excerpt',
					'post_mime_type',
					'post_parent',
					'post_status',
					'post_title',
					'post_type',
				),
				true
			)
		);
	}

	private static function delete_attachment_ajax_fixtures( array $fixtures ): void {
		if ( empty( $fixtures['postIds'] ) || ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			self::flush_runtime_cache();
			return;
		}

		$wpdb = $GLOBALS['wpdb'];
		foreach ( array_reverse( array_map( 'intval', $fixtures['postIds'] ) ) as $post_id ) {
			if ( method_exists( $wpdb, 'delete' ) ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );
				$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
			}

			if ( function_exists( 'clean_post_cache' ) ) {
				\clean_post_cache( $post_id );
			}
		}

		self::flush_runtime_cache();
	}

	private static function attachment_ajax_fixtures_deleted( array $fixtures ): bool {
		if ( empty( $fixtures['postIds'] ) ) {
			return true;
		}

		foreach ( array_map( 'intval', $fixtures['postIds'] ) as $post_id ) {
			if ( null !== \get_post( $post_id ) ) {
				return false;
			}
		}

		return true;
	}

	private static function flush_runtime_cache(): void {
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function snapshot_hook( string $hook_name ) {
		global $wp_filter;

		return isset( $wp_filter[ $hook_name ] ) ? self::clone_value( $wp_filter[ $hook_name ] ) : null;
	}

	private static function restore_hook( string $hook_name, $snapshot ): void {
		global $wp_filter;

		if ( null === $snapshot ) {
			unset( $wp_filter[ $hook_name ] );
			return;
		}

		$wp_filter[ $hook_name ] = self::clone_value( $snapshot );
	}

	private static function hook_matches( string $hook_name, $snapshot ): bool {
		global $wp_filter;

		$current = $wp_filter[ $hook_name ] ?? null;

		return $current == $snapshot;
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
