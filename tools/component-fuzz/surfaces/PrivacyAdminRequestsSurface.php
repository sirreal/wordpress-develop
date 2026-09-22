<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes privacy request admin list tables and AJAX handlers without page dispatch.
 */
final class PrivacyAdminRequestsSurface {
	public const NAME = 'privacy-admin-requests';

	private const MAX_FAILURES = 10;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_optional_admin_privacy_support();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::row(
					$ctx,
					'privacy-admin-requests.bootstrap-apis-available',
					true,
					array( 'missing' => $missing ),
					'skipped'
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();
			self::initialize_core_content_types();
			self::install_scoped_email_filters();
			self::reset_db_content();

			$rows[] = self::check_list_table_markup_and_queries( $ctx->fork( 'list-tables' ) );
			$rows[] = self::check_bulk_actions_and_direct_helpers( $ctx->fork( 'bulk-actions' ) );
			$rows[] = self::check_admin_form_actions_and_cleanup( $ctx->fork( 'form-actions-cleanup' ) );
			$rows[] = self::check_ajax_default_processors( $ctx->fork( 'ajax-default-processors' ) );
			$rows[] = self::check_ajax_export_handler( $ctx->fork( 'ajax-export' ) );
			$rows[] = self::check_ajax_erasure_handler( $ctx->fork( 'ajax-erasure' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'privacy-admin-requests.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_db_content();
			self::restore_state( $snapshot );
		}

		$rows[] = self::row(
			$ctx,
			'privacy-admin-requests.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'filters'  => array_keys( $snapshot['wp_filter'] ),
				'dbCounts' => self::db_counts(),
			)
		);

		return $rows;
	}

	private static function load_optional_admin_privacy_support(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach (
			array(
				'wp-admin/includes/class-wp-privacy-requests-table.php',
				'wp-admin/includes/class-wp-privacy-data-export-requests-list-table.php',
				'wp-admin/includes/class-wp-privacy-data-removal-requests-list-table.php',
				'wp-admin/includes/privacy-tools.php',
				'wp-admin/includes/ajax-actions.php',
			) as $file
		) {
			$path = ABSPATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Error',
				'WP_Privacy_Data_Export_Requests_List_Table',
				'WP_Privacy_Data_Removal_Requests_List_Table',
				'WP_User',
				'WP_User_Request',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_privacy_completed_request',
				'_wp_privacy_resend_request',
				'_wp_personal_data_cleanup_requests',
				'_wp_personal_data_handle_actions',
				'add_filter',
				'add_settings_error',
				'check_admin_referer',
				'check_ajax_referer',
				'clean_post_cache',
				'clean_user_cache',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'get_post',
				'get_post_meta',
				'get_settings_errors',
				'get_user_by',
				'has_filter',
				'home_url',
				'is_email',
				'is_wp_error',
				'remove_all_filters',
				'remove_filter',
				'restore_previous_locale',
				'sanitize_url',
				'switch_to_locale',
				'update_post_meta',
				'wp_ajax_wp_privacy_erase_personal_data',
				'wp_ajax_wp_privacy_export_personal_data',
				'wp_cache_delete',
				'wp_cache_set',
				'wp_create_nonce',
				'wp_create_user_request',
				'wp_delete_post',
				'wp_fast_hash',
				'wp_generate_password',
				'wp_generate_user_request_key',
				'wp_get_user_request',
				'wp_login_url',
				'wp_mail',
				'wp_privacy_process_personal_data_erasure_page',
				'wp_privacy_process_personal_data_export_page',
				'wp_is_unicode_email',
				'wp_json_encode',
				'wp_send_user_request',
				'wp_send_json_error',
				'wp_send_json_success',
				'wp_set_current_user',
				'wp_sanitize_unicode_email',
				'wp_specialchars_decode',
				'wp_validate_user_request_key',
				'wp_update_post',
				'wp_verify_nonce',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! self::wpdb_stub_available() ) {
			$missing[] = 'Component_Fuzz_WPDB_Stub content store';
		}

		return $missing;
	}

	private static function check_list_table_markup_and_queries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::token( $ctx );

		self::reset_db_content();
		self::seed_admin_user( 97101, 'privacy-admin-list-' . $token );
		\wp_set_current_user( 97101 );

		$export_ids = array(
			'pending'   => 97200 + $ctx->int( 0, 50 ),
			'confirmed' => 97300 + $ctx->int( 0, 50 ),
			'failed'    => 97400 + $ctx->int( 0, 50 ),
			'completed' => 97500 + $ctx->int( 0, 50 ),
		);
		$erase_ids = array(
			'pending'   => 97600 + $ctx->int( 0, 50 ),
			'confirmed' => 97700 + $ctx->int( 0, 50 ),
			'completed' => 97800 + $ctx->int( 0, 50 ),
		);

		foreach ( $export_ids as $status => $id ) {
			self::seed_privacy_request(
				$id,
				'export_personal_data',
				'request-' . $status,
				'export-' . $status . '-' . $token . '@example.test',
				$token,
				'2026-06-30 10:' . sprintf( '%02d', (int) ( $id % 60 ) ) . ':00'
			);
		}
		foreach ( $erase_ids as $status => $id ) {
			self::seed_privacy_request(
				$id,
				'remove_personal_data',
				'request-' . $status,
				'erase-' . $status . '-' . $token . '@example.test',
				$token,
				'2026-06-30 11:' . sprintf( '%02d', (int) ( $id % 60 ) ) . ':00'
			);
		}

		self::prime_request_count_cache(
			'export_personal_data',
			array(
				'request-pending'   => 1,
				'request-confirmed' => 1,
				'request-failed'    => 1,
				'request-completed' => 1,
			)
		);
		self::prime_request_count_cache(
			'remove_personal_data',
			array(
				'request-pending'   => 1,
				'request-confirmed' => 1,
				'request-completed' => 1,
			)
		);

		$exporters = static function (): array {
			return array(
				'alpha-exporter' => array(
					'exporter_friendly_name' => 'Alpha Exporter',
					'callback'               => '__return_empty_array',
				),
				'beta-exporter'  => array(
					'exporter_friendly_name' => 'Beta Exporter',
					'callback'               => '__return_empty_array',
				),
			);
		};
		$erasers   = static function (): array {
			return array(
				'alpha-eraser' => array(
					'eraser_friendly_name' => 'Alpha Eraser',
					'callback'             => '__return_empty_array',
				),
				'beta-eraser'  => array(
					'eraser_friendly_name' => 'Beta Eraser',
					'callback'             => '__return_empty_array',
				),
			);
		};

		\add_filter( 'wp_privacy_personal_data_exporters', $exporters, 10, 0 );
		\add_filter( 'wp_privacy_personal_data_erasers', $erasers, 10, 0 );
		try {
			$export_table = self::export_table();
			$erase_table  = self::removal_table();

			$_GET     = array( 'filter-status' => 'request-confirmed' );
			$_POST    = array();
			$_REQUEST = $_GET;
			$export_views = $export_table->exposed_views();
			$erase_views  = $erase_table->exposed_views();

			$_GET     = array(
				'filter-status' => 'request-confirmed',
				'orderby'       => 'requester',
				'order'         => 'ASC',
			);
			$_REQUEST = $_GET;
			$export_table->prepare_items();
			$prepared_items = $export_table->exposed_items();

			$export_confirmed = \wp_get_user_request( $export_ids['confirmed'] );
			$export_failed    = \wp_get_user_request( $export_ids['failed'] );
			$export_completed = \wp_get_user_request( $export_ids['completed'] );
			$erase_pending    = \wp_get_user_request( $erase_ids['pending'] );
			$erase_confirmed  = \wp_get_user_request( $erase_ids['confirmed'] );
			$erase_completed  = \wp_get_user_request( $erase_ids['completed'] );

			$export_email_html      = $export_table->column_email( $export_confirmed );
			$export_next_confirmed  = self::capture_output( static fn() => $export_table->column_next_steps( $export_confirmed ) );
			$export_next_failed     = self::capture_output( static fn() => $export_table->column_next_steps( $export_failed ) );
			$export_email_completed = $export_table->column_email( $export_completed );
			$export_status_html     = self::capture_output( static fn() => $export_table->column_status( $export_completed ) );
			$export_cb_html         = $export_table->column_cb( $export_confirmed );

			$erase_email_pending     = $erase_table->column_email( $erase_pending );
			$erase_next_confirmed    = self::capture_output( static fn() => $erase_table->column_next_steps( $erase_confirmed ) );
			$erase_next_completed    = self::capture_output( static fn() => $erase_table->column_next_steps( $erase_completed ) );
			$erase_email_completed   = $erase_table->column_email( $erase_completed );
			$export_nonce_from_email = self::html_attr( $export_email_html, 'data-nonce' );
			$erase_nonce_from_email  = self::html_attr( $erase_email_pending, 'data-nonce' );
		} finally {
			\remove_filter( 'wp_privacy_personal_data_erasers', $erasers, 10 );
			\remove_filter( 'wp_privacy_personal_data_exporters', $exporters, 10 );
		}

		self::collect_failure(
			$failures,
			isset( $export_views['all'], $export_views['request-confirmed'], $erase_views['all'], $erase_views['request-confirmed'] )
				&& str_contains( $export_views['request-confirmed'], 'class="current"' )
				&& str_contains( $export_views['request-confirmed'], 'filter-status=request-confirmed' )
				&& str_contains( $erase_views['request-confirmed'], 'filter-status=request-confirmed' ),
			'list-table views use primed request counts, current status, and normalized admin URLs',
			array(
				'exportViews' => $export_views,
				'eraseViews'  => $erase_views,
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $prepared_items )
				&& $prepared_items[0] instanceof \WP_User_Request
				&& $export_ids['confirmed'] === (int) $prepared_items[0]->ID,
			'prepare_items filters by request type, status, and requester order using seeded user_request rows',
			array(
				'prepared' => self::describe_value( $prepared_items ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $export_email_html, 'mailto:export-confirmed-' . $token . '@example.test' )
				&& str_contains( $export_email_html, 'data-exporters-count="2"' )
				&& str_contains( $export_email_html, 'data-request-id="' . $export_ids['confirmed'] . '"' )
				&& 1 === \wp_verify_nonce( $export_nonce_from_email, 'wp-privacy-export-personal-data-' . $export_ids['confirmed'] )
				&& str_contains( $export_next_confirmed, 'data-send-as-email="1"' )
				&& str_contains( $export_next_confirmed, 'Send export link' )
				&& str_contains( $export_next_failed, 'privacy_action_email_retry[' . $export_ids['failed'] . ']' )
				&& ! str_contains( $export_email_completed, 'complete-request' )
				&& str_contains( $export_status_html, 'status-request-completed' )
				&& str_contains( $export_status_html, 'status-date' )
				&& str_contains( $export_cb_html, 'name="request_id[]"' )
				&& str_contains( $export_cb_html, 'Select export-confirmed-' . $token . '@example.test' ),
			'export request table emits escaped mailto, action data attrs, retry/completion states, status dates, and checkbox labels',
			array(
				'email'       => self::describe_string( $export_email_html ),
				'next'        => self::describe_string( $export_next_confirmed ),
				'failedNext'  => self::describe_string( $export_next_failed ),
				'completed'   => self::describe_string( $export_email_completed ),
				'status'      => self::describe_string( $export_status_html ),
				'checkbox'    => self::describe_string( $export_cb_html ),
				'nonce'       => self::describe_string( (string) $export_nonce_from_email ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $erase_email_pending, 'force-remove-personal-data' )
				&& str_contains( $erase_email_pending, 'data-erasers-count="2"' )
				&& 1 === \wp_verify_nonce( $erase_nonce_from_email, 'wp-privacy-erase-personal-data-' . $erase_ids['pending'] )
				&& str_contains( $erase_next_confirmed, 'data-force-erase="1"' )
				&& str_contains( $erase_next_confirmed, 'Erase personal data' )
				&& str_contains( $erase_next_completed, 'action=delete' )
				&& str_contains( $erase_email_completed, 'force-remove-personal-data' )
				&& ! str_contains( $erase_email_completed, 'complete-request' )
				&& false === \has_filter( 'wp_privacy_personal_data_exporters', $exporters )
				&& false === \has_filter( 'wp_privacy_personal_data_erasers', $erasers ),
			'erasure request table emits force/confirmed/completed state controls and removes scoped registry filters',
			array(
				'pendingEmail'  => self::describe_string( $erase_email_pending ),
				'confirmedNext' => self::describe_string( $erase_next_confirmed ),
				'completedNext' => self::describe_string( $erase_next_completed ),
				'completedMail' => self::describe_string( $erase_email_completed ),
				'nonce'         => self::describe_string( (string) $erase_nonce_from_email ),
			)
		);

		self::reset_db_content();

		return self::row(
			$ctx,
			'privacy-admin-requests.list-tables.markup-views-and-queries',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_bulk_actions_and_direct_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::token( $ctx );

		self::reset_db_content();
		self::seed_admin_user( 97121, 'privacy-admin-bulk-' . $token );
		\wp_set_current_user( 97121 );

		$complete_id = 97900 + $ctx->int( 0, 50 );
		$delete_id   = 98000 + $ctx->int( 0, 50 );
		$resend_ids  = array(
			'first'  => 98100 + $ctx->int( 0, 50 ),
			'second' => 98200 + $ctx->int( 0, 50 ),
		);
		$invalid_id  = $complete_id + 1000;

		self::seed_privacy_request( $complete_id, 'export_personal_data', 'request-confirmed', 'complete-' . $token . '@example.test', $token );
		self::seed_privacy_request( $delete_id, 'export_personal_data', 'request-pending', 'delete-' . $token . '@example.test', $token );
		foreach ( $resend_ids as $label => $resend_id ) {
			self::seed_privacy_request( $resend_id, 'export_personal_data', 'request-confirmed', 'resend-' . $label . '-' . $token . '@example.com', $token );
		}

		$invalid_resend = \_wp_privacy_resend_request( $invalid_id );
		$invalid_complete = \_wp_privacy_completed_request( $invalid_id );

		$table = self::export_table();

		$GLOBALS['wp_settings_errors'] = array();
		$_POST = $_GET = $_REQUEST = array(
			'action'     => 'complete',
			'request_id' => array( $complete_id ),
			'_wpnonce'   => \wp_create_nonce( 'bulk-privacy_requests' ),
		);
		$table->process_bulk_action();
		$completed_after = \wp_get_user_request( $complete_id );
		$complete_errors = \get_settings_errors( 'bulk_action' );

		$GLOBALS['wp_settings_errors'] = array();
		$_POST = $_GET = $_REQUEST = array(
			'action'     => 'delete',
			'request_id' => array( $delete_id ),
			'_wpnonce'   => \wp_create_nonce( 'bulk-privacy_requests' ),
		);
		$table->process_bulk_action();
		$deleted_after = \get_post( $delete_id );
		$delete_errors = \get_settings_errors( 'bulk_action' );

		$mail_events    = array();
		$subject_events = array();
		$content_events = array();
		$header_events  = array();
		$mail_filter    = static function ( $pre, array $atts ) use ( &$mail_events ) {
			unset( $pre );
			$mail_events[] = $atts;
			return true;
		};
		$subject_filter = static function ( string $subject, string $sitename, array $email_data ) use ( &$subject_events ): string {
			$subject_events[] = array(
				'subject'   => $subject,
				'sitename'  => $sitename,
				'requestId' => (int) ( $email_data['request']->ID ?? 0 ),
				'email'     => (string) ( $email_data['email'] ?? '' ),
			);
			return '[cfz] ' . $subject;
		};
		$content_filter = static function ( string $content, array $email_data ) use ( &$content_events ): string {
			$content_events[] = array(
				'requestId'  => (int) ( $email_data['request']->ID ?? 0 ),
				'email'      => (string) ( $email_data['email'] ?? '' ),
				'confirmUrl' => (string) ( $email_data['confirm_url'] ?? '' ),
			);
			return $content . "\n\nEmail: ###EMAIL###\n";
		};
		$headers_filter = static function ( $headers, string $subject, string $content, int $request_id, array $email_data ) use ( &$header_events, $token ) {
			unset( $headers );
			$header_events[] = array(
				'requestId' => $request_id,
				'subject'   => $subject,
				'email'     => (string) ( $email_data['email'] ?? '' ),
				'content'   => $content,
			);
			return "X-Privacy-Resend: {$token}";
		};
		\add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
		\add_filter( 'user_request_action_email_subject', $subject_filter, 10, 3 );
		\add_filter( 'user_request_action_email_content', $content_filter, 10, 2 );
		\add_filter( 'user_request_action_email_headers', $headers_filter, 10, 5 );

		$GLOBALS['wp_settings_errors'] = array();
		$resend_before = array();
		foreach ( $resend_ids as $label => $resend_id ) {
			$resend_before[ $label ] = \wp_get_user_request( $resend_id );
		}
		$_POST = $_GET = $_REQUEST = array(
			'action'     => 'resend',
			'request_id' => array_merge( array_values( $resend_ids ), array( $invalid_id ) ),
			'_wpnonce'   => \wp_create_nonce( 'bulk-privacy_requests' ),
		);
		try {
			$table->process_bulk_action();
		} finally {
			\remove_filter( 'user_request_action_email_headers', $headers_filter, 10 );
			\remove_filter( 'user_request_action_email_content', $content_filter, 10 );
			\remove_filter( 'user_request_action_email_subject', $subject_filter, 10 );
			\remove_filter( 'pre_wp_mail', $mail_filter, 10 );
		}
		$resend_after = array();
		foreach ( $resend_ids as $label => $resend_id ) {
			$resend_after[ $label ] = \wp_get_user_request( $resend_id );
		}
		$resend_errors = \get_settings_errors( 'bulk_action' );
		$resend_checks = array();
		foreach ( array_values( $resend_ids ) as $mail_index => $resend_id ) {
			$label           = (string) array_search( $resend_id, $resend_ids, true );
			$mail            = $mail_events[ $mail_index ] ?? null;
			$message         = is_array( $mail ) ? (string) ( $mail['message'] ?? '' ) : '';
			$confirm_key     = self::query_value_from_text( $message, 'confirm_key' );
			$expected_email  = 'resend-' . $label . '-' . $token . '@example.com';
			$resend_checks[] = array(
				'id'             => $resend_id,
				'beforeConfirmed' => $resend_before[ $label ] instanceof \WP_User_Request && 'request-confirmed' === $resend_before[ $label ]->status,
				'afterPending'   => $resend_after[ $label ] instanceof \WP_User_Request && 'request-pending' === $resend_after[ $label ]->status,
				'keyChanged'     => $resend_before[ $label ] instanceof \WP_User_Request && $resend_after[ $label ] instanceof \WP_User_Request && $resend_before[ $label ]->confirm_key !== $resend_after[ $label ]->confirm_key,
				'keyLength'      => 20 === strlen( $confirm_key ),
				'keyValidates'   => true === \wp_validate_user_request_key( $resend_id, $confirm_key ),
				'mailTo'         => is_array( $mail ) && $expected_email === ( $mail['to'] ?? null ),
				'subject'        => is_array( $mail ) && str_contains( (string) ( $mail['subject'] ?? '' ), '[cfz] ' ) && str_contains( (string) ( $mail['subject'] ?? '' ), 'Confirm Action' ),
				'headers'        => is_array( $mail ) && "X-Privacy-Resend: {$token}" === ( $mail['headers'] ?? null ),
				'confirmUrl'     => str_contains( $message, 'action=confirmaction' ) && str_contains( $message, 'request_id=' . $resend_id ),
				'emailReplaced'  => str_contains( $message, 'Email: ' . $expected_email ),
				'placeholders'   => ! str_contains( $message, '###' ),
			);
		}
		$resend_ok = array_reduce(
			$resend_checks,
			static fn( bool $carry, array $check ): bool => $carry && ! in_array( false, $check, true ),
			true
		);

		self::collect_failure(
			$failures,
			self::is_error_code( $invalid_resend, 'privacy_request_error' )
				&& self::is_error_code( $invalid_complete, 'privacy_request_error' ),
			'direct resend/complete helpers reject missing requests with privacy_request_error',
			array(
				'resend'   => self::describe_value( $invalid_resend ),
				'complete' => self::describe_value( $invalid_complete ),
			)
		);

		self::collect_failure(
			$failures,
			$resend_ok
				&& 2 === count( $mail_events )
				&& 2 === count( $subject_events )
				&& 2 === count( $content_events )
				&& 2 === count( $header_events )
				&& self::has_settings_error( $resend_errors, 'error', '1 confirmation request failed to resend' )
				&& self::has_settings_error( $resend_errors, 'success', '2 confirmation requests re-sent successfully' )
				&& false === \has_filter( 'pre_wp_mail', $mail_filter )
				&& false === \has_filter( 'user_request_action_email_subject', $subject_filter )
				&& false === \has_filter( 'user_request_action_email_content', $content_filter )
				&& false === \has_filter( 'user_request_action_email_headers', $headers_filter ),
			'bulk resend reissues confirmation keys, sends captured confirmation emails, and reports mixed plural success/error counts',
			array(
				'checks'        => self::describe_value( $resend_checks ),
				'before'        => self::describe_value( $resend_before ),
				'after'         => self::describe_value( $resend_after ),
				'mailEvents'    => self::describe_value( $mail_events ),
				'subjectEvents' => self::describe_value( $subject_events ),
				'contentEvents' => self::describe_value( $content_events ),
				'headerEvents'  => self::describe_value( $header_events ),
				'errors'        => self::describe_value( $resend_errors ),
				'filters'       => array(
					'mail'    => \has_filter( 'pre_wp_mail', $mail_filter ),
					'subject' => \has_filter( 'user_request_action_email_subject', $subject_filter ),
					'content' => \has_filter( 'user_request_action_email_content', $content_filter ),
					'headers' => \has_filter( 'user_request_action_email_headers', $headers_filter ),
				),
			)
		);

		self::collect_failure(
			$failures,
			$completed_after instanceof \WP_User_Request
				&& 'request-completed' === $completed_after->status
				&& $completed_after->completed_timestamp > 0
				&& 1 === count( $complete_errors )
				&& 'success' === ( $complete_errors[0]['type'] ?? null )
				&& str_contains( $complete_errors[0]['message'] ?? '', 'marked as complete' ),
			'bulk complete updates request status, completed timestamp meta, and success settings error',
			array(
				'request' => self::describe_value( $completed_after ),
				'errors'  => self::describe_value( $complete_errors ),
			)
		);

		self::collect_failure(
			$failures,
			null === $deleted_after
				&& 1 === count( $delete_errors )
				&& 'success' === ( $delete_errors[0]['type'] ?? null )
				&& str_contains( $delete_errors[0]['message'] ?? '', 'deleted successfully' ),
			'bulk delete removes targeted request and reports success without touching other requests',
			array(
				'deletedAfter' => self::describe_value( $deleted_after ),
				'completePost' => self::describe_value( \get_post( $complete_id ) ),
				'errors'       => self::describe_value( $delete_errors ),
			)
		);

		self::reset_db_content();

		return self::row(
			$ctx,
			'privacy-admin-requests.bulk-actions.complete-delete-and-helper-errors',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_admin_form_actions_and_cleanup( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::token( $ctx );
		$admin_id = 97131;
		$login    = 'privacy-admin-form-' . $token;

		self::reset_db_content();
		self::seed_admin_user( $admin_id, $login );
		\wp_set_current_user( $admin_id );

		$pending_email   = 'form-export-' . $token . '@example.test';
		$retry_email     = 'form-retry-' . $token . '@example.test';
		$old_email       = 'cleanup-old-' . $token . '@example.test';
		$fresh_email     = 'cleanup-fresh-' . $token . '@example.test';
		$retry_id        = 98300 + $ctx->int( 0, 50 );
		$old_id          = 98400 + $ctx->int( 0, 50 );
		$fresh_id        = 98500 + $ctx->int( 0, 50 );
		$completed_id    = 98600 + $ctx->int( 0, 50 );
		$mail_events     = array();
		$subject_events  = array();
		$content_events  = array();
		$header_events   = array();
		$filters_removed = false;

		$mail_filter = static function ( $pre, array $atts ) use ( &$mail_events ) {
			unset( $pre );
			$mail_events[] = $atts;
			return true;
		};
		$subject_filter = static function ( string $subject, string $sitename, array $email_data ) use ( &$subject_events, $token ): string {
			$subject_events[] = array(
				'email'     => (string) ( $email_data['email'] ?? '' ),
				'requestId' => (int) ( $email_data['request']->ID ?? 0 ),
				'sitename'  => $sitename,
				'subject'   => $subject,
			);
			return '[cfz-form-' . $token . '] ' . $subject;
		};
		$content_filter = static function ( string $content, array $email_data ) use ( &$content_events ): string {
			$content_events[] = array(
				'confirmUrl' => (string) ( $email_data['confirm_url'] ?? '' ),
				'email'      => (string) ( $email_data['email'] ?? '' ),
				'requestId'  => (int) ( $email_data['request']->ID ?? 0 ),
			);
			return $content . "\n\nEmail: ###EMAIL###\nDescription: ###DESCRIPTION###\n";
		};
		$headers_filter = static function ( $headers, string $subject, string $content, int $request_id, array $email_data ) use ( &$header_events, $token ) {
			unset( $headers );
			$header_events[] = array(
				'content'   => $content,
				'email'     => (string) ( $email_data['email'] ?? '' ),
				'requestId' => $request_id,
				'subject'   => $subject,
			);
			return "X-Privacy-Form: {$token}";
		};

		\add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
		\add_filter( 'user_request_action_email_subject', $subject_filter, 10, 3 );
		\add_filter( 'user_request_action_email_content', $content_filter, 10, 2 );
		\add_filter( 'user_request_action_email_headers', $headers_filter, 10, 5 );

		try {
			$GLOBALS['wp_settings_errors'] = array();
			$_POST = $_GET = $_REQUEST = array(
				'_wpnonce'                             => \wp_create_nonce( 'personal-data-request' ),
				'action'                               => 'add_export_personal_data_request',
				'send_confirmation_email'              => '1',
				'type_of_action'                       => 'export_personal_data',
				'username_or_email_for_privacy_request' => $pending_email,
			);
			\_wp_personal_data_handle_actions();
			$pending_request = self::find_user_request( $pending_email, 'export_personal_data' );
			$pending_errors  = \get_settings_errors( 'username_or_email_for_privacy_request' );

			$GLOBALS['wp_settings_errors'] = array();
			$_POST = $_GET = $_REQUEST = array(
				'_wpnonce'                             => \wp_create_nonce( 'personal-data-request' ),
				'action'                               => 'add_remove_personal_data_request',
				'type_of_action'                       => 'remove_personal_data',
				'username_or_email_for_privacy_request' => $login,
			);
			\_wp_personal_data_handle_actions();
			$username_request = self::find_user_request( $login . '@example.test', 'remove_personal_data' );
			$username_errors  = \get_settings_errors( 'username_or_email_for_privacy_request' );

			$GLOBALS['wp_settings_errors'] = array();
			$_POST = $_GET = $_REQUEST = array(
				'_wpnonce'                             => \wp_create_nonce( 'personal-data-request' ),
				'action'                               => 'add_remove_personal_data_request',
				'type_of_action'                       => 'remove_personal_data',
				'username_or_email_for_privacy_request' => 'missing-user-' . $token,
			);
			\_wp_personal_data_handle_actions();
			$invalid_errors = \get_settings_errors( 'username_or_email_for_privacy_request' );

			self::seed_privacy_request( $retry_id, 'export_personal_data', 'request-confirmed', $retry_email, $token );
			$retry_before = \wp_get_user_request( $retry_id );

			$GLOBALS['wp_settings_errors'] = array();
			$_POST = $_GET = $_REQUEST = array(
				'_wpnonce'                   => \wp_create_nonce( 'bulk-privacy_requests' ),
				'privacy_action_email_retry' => array( $retry_id => '1' ),
			);
			\_wp_personal_data_handle_actions();
			$retry_after  = \wp_get_user_request( $retry_id );
			$retry_errors = \get_settings_errors( 'privacy_action_email_retry' );

			self::seed_privacy_request( $old_id, 'export_personal_data', 'request-pending', $old_email, $token, '2000-01-01 00:00:00', '2000-01-01 00:00:00' );
			self::seed_privacy_request( $fresh_id, 'export_personal_data', 'request-pending', $fresh_email, $token, '2099-01-01 00:00:00', '2099-01-01 00:00:00' );
			self::seed_privacy_request( $completed_id, 'export_personal_data', 'request-completed', 'cleanup-done-' . $token . '@example.test', $token, '2000-01-01 00:00:00', '2000-01-01 00:00:00' );
			\_wp_personal_data_cleanup_requests();
			$old_after       = \wp_get_user_request( $old_id );
			$fresh_after     = \wp_get_user_request( $fresh_id );
			$completed_after = \wp_get_user_request( $completed_id );
		} finally {
			\remove_filter( 'user_request_action_email_headers', $headers_filter, 10 );
			\remove_filter( 'user_request_action_email_content', $content_filter, 10 );
			\remove_filter( 'user_request_action_email_subject', $subject_filter, 10 );
			\remove_filter( 'pre_wp_mail', $mail_filter, 10 );
			$filters_removed = false === \has_filter( 'pre_wp_mail', $mail_filter )
				&& false === \has_filter( 'user_request_action_email_subject', $subject_filter )
				&& false === \has_filter( 'user_request_action_email_content', $content_filter )
				&& false === \has_filter( 'user_request_action_email_headers', $headers_filter );
		}

		$pending_mail_index = self::mail_event_index_for_email( $mail_events, $pending_email );
		$retry_mail_index   = self::mail_event_index_for_email( $mail_events, $retry_email );
		$pending_mail       = $pending_mail_index >= 0 ? $mail_events[ $pending_mail_index ] : null;
		$retry_mail         = $retry_mail_index >= 0 ? $mail_events[ $retry_mail_index ] : null;
		$pending_key        = is_array( $pending_mail ) ? self::query_value_from_text( (string) ( $pending_mail['message'] ?? '' ), 'confirm_key' ) : '';
		$retry_key          = is_array( $retry_mail ) ? self::query_value_from_text( (string) ( $retry_mail['message'] ?? '' ), 'confirm_key' ) : '';

		self::collect_failure(
			$failures,
			$pending_request instanceof \WP_User_Request
				&& 'request-pending' === $pending_request->status
				&& $pending_email === $pending_request->email
				&& is_array( $pending_mail )
				&& $pending_email === ( $pending_mail['to'] ?? null )
				&& str_contains( (string) ( $pending_mail['subject'] ?? '' ), '[cfz-form-' . $token . ']' )
				&& "X-Privacy-Form: {$token}" === ( $pending_mail['headers'] ?? null )
				&& true === \wp_validate_user_request_key( (int) $pending_request->ID, $pending_key )
				&& str_contains( (string) ( $pending_mail['message'] ?? '' ), 'Email: ' . $pending_email )
				&& ! str_contains( (string) ( $pending_mail['message'] ?? '' ), '###' )
				&& self::has_settings_error( $pending_errors, 'success', 'Confirmation request initiated successfully' ),
			'admin add-request form creates pending export request, sends confirmation email, and reports success',
			array(
				'mail'    => self::describe_value( $pending_mail ),
				'request' => self::describe_value( $pending_request ),
				'errors'  => self::describe_value( $pending_errors ),
			)
		);

		self::collect_failure(
			$failures,
			$username_request instanceof \WP_User_Request
				&& 'request-confirmed' === $username_request->status
				&& $login . '@example.test' === $username_request->email
				&& 'remove_personal_data' === $username_request->action_name
				&& -1 === self::mail_event_index_for_email( $mail_events, $login . '@example.test' )
				&& self::has_settings_error( $username_errors, 'success', 'Request added successfully' ),
			'admin add-request form resolves usernames, creates confirmed erasure requests, and does not send confirmation when omitted',
			array(
				'errors'  => self::describe_value( $username_errors ),
				'request' => self::describe_value( $username_request ),
			)
		);

		self::collect_failure(
			$failures,
			self::has_settings_error( $invalid_errors, 'error', 'valid email address or username' )
				&& null === self::find_user_request( 'missing-user-' . $token, 'remove_personal_data' ),
			'admin add-request form rejects unknown non-email requester identifiers without creating requests',
			array( 'errors' => self::describe_value( $invalid_errors ) )
		);

		self::collect_failure(
			$failures,
			$retry_before instanceof \WP_User_Request
				&& $retry_after instanceof \WP_User_Request
				&& 'request-confirmed' === $retry_before->status
				&& 'request-pending' === $retry_after->status
				&& $retry_before->confirm_key !== $retry_after->confirm_key
				&& is_array( $retry_mail )
				&& $retry_email === ( $retry_mail['to'] ?? null )
				&& true === \wp_validate_user_request_key( $retry_id, $retry_key )
				&& self::has_settings_error( $retry_errors, 'success', 'Confirmation request sent again successfully' ),
			'admin retry form reissues confirmation email and moves confirmed request back to pending',
			array(
				'after'  => self::describe_value( $retry_after ),
				'before' => self::describe_value( $retry_before ),
				'errors' => self::describe_value( $retry_errors ),
				'mail'   => self::describe_value( $retry_mail ),
			)
		);

		self::collect_failure(
			$failures,
			$old_after instanceof \WP_User_Request
				&& 'request-failed' === $old_after->status
				&& '' === $old_after->confirm_key
				&& $fresh_after instanceof \WP_User_Request
				&& 'request-pending' === $fresh_after->status
				&& str_contains( $fresh_after->confirm_key, 'request-key-' )
				&& $completed_after instanceof \WP_User_Request
				&& 'request-completed' === $completed_after->status,
			'admin cleanup marks only expired pending requests failed and clears their confirmation keys',
			array(
				'completed' => self::describe_value( $completed_after ),
				'fresh'     => self::describe_value( $fresh_after ),
				'old'       => self::describe_value( $old_after ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed
				&& 2 === count( $mail_events )
				&& 2 === count( $subject_events )
				&& 2 === count( $content_events )
				&& 2 === count( $header_events ),
			'admin form action mail filters are scoped and callback payload counts match the two confirmation sends',
			array(
				'contentEvents' => self::describe_value( $content_events ),
				'filtersRemoved' => $filters_removed,
				'headerEvents'  => self::describe_value( $header_events ),
				'mailEvents'    => self::describe_value( $mail_events ),
				'subjectEvents' => self::describe_value( $subject_events ),
			)
		);

		self::reset_db_content();

		return self::row(
			$ctx,
			'privacy-admin-requests.admin-form-actions-and-cleanup',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_ajax_default_processors( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$token          = self::token( $ctx );
		$export_email   = 'ajax-default-export-' . $token . '@example.test';
		$erase_email    = 'ajax-default-erase-' . $token . '@example.test';
		$export_id      = 98700 + $ctx->int( 0, 50 );
		$erase_id       = 98800 + $ctx->int( 0, 50 );
		$exporter_calls = array();
		$eraser_calls   = array();
		$erased_actions = array();
		$filters_removed = false;
		$had_export_processor = false !== \has_filter( 'wp_privacy_personal_data_export_page', 'wp_privacy_process_personal_data_export_page' );
		$had_erasure_processor = false !== \has_filter( 'wp_privacy_personal_data_erasure_page', 'wp_privacy_process_personal_data_erasure_page' );

		self::reset_db_content();
		self::seed_admin_user( 97151, 'privacy-admin-processors-' . $token );
		\wp_set_current_user( 97151 );
		self::seed_privacy_request( $export_id, 'export_personal_data', 'request-confirmed', $export_email, $token );
		self::seed_privacy_request( $erase_id, 'remove_personal_data', 'request-confirmed', $erase_email, $token );

		$cap_filter = self::cap_filter( array( 'delete_users', 'erase_others_personal_data', 'export_others_personal_data', 'manage_options' ) );
		$exporters  = static function () use ( &$exporter_calls, $token ): array {
			return array(
				'default-processor-exporter' => array(
					'exporter_friendly_name' => 'Default Processor Exporter',
					'callback'               => static function ( string $email_address, int $page ) use ( &$exporter_calls, $token ): array {
						$exporter_calls[] = array(
							'email' => $email_address,
							'page'  => $page,
						);

						return array(
							'data' => array(
								array(
									'group_id'    => 'privacy-admin-default',
									'group_label' => 'Privacy Admin Default',
									'item_id'     => 'item-' . $token,
									'data'        => array(
										array(
											'name'  => 'Generated Field',
											'value' => 'Generated Value ' . $token,
										),
									),
								),
							),
							'done' => false,
						);
					},
				),
			);
		};
		$erasers = static function () use ( &$eraser_calls, $token ): array {
			return array(
				'default-processor-eraser' => array(
					'eraser_friendly_name' => 'Default Processor Eraser',
					'callback'             => static function ( string $email_address, int $page ) use ( &$eraser_calls, $token ): array {
						$eraser_calls[] = array(
							'email' => $email_address,
							'page'  => $page,
						);

						return array(
							'done'           => true,
							'items_removed'  => true,
							'items_retained' => false,
							'messages'       => array( 'default processor erased ' . $token ),
						);
					},
				),
			);
		};
		$erased_action = static function ( int $request_id ) use ( &$erased_actions ): void {
			$erased_actions[] = $request_id;
		};

		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		\add_filter( 'wp_privacy_personal_data_exporters', $exporters, 10, 0 );
		\add_filter( 'wp_privacy_personal_data_erasers', $erasers, 10, 0 );
		if ( ! $had_export_processor ) {
			\add_filter( 'wp_privacy_personal_data_export_page', 'wp_privacy_process_personal_data_export_page', 10, 7 );
		}
		if ( ! $had_erasure_processor ) {
			\add_filter( 'wp_privacy_personal_data_erasure_page', 'wp_privacy_process_personal_data_erasure_page', 10, 5 );
		}
		\add_action( 'wp_privacy_personal_data_erased', $erased_action, 10, 1 );

		try {
			$_POST = $_GET = $_REQUEST = array(
				'id'          => (string) $export_id,
				'exporter'    => '1',
				'page'        => '1',
				'security'    => \wp_create_nonce( 'wp-privacy-export-personal-data-' . $export_id ),
				'sendAsEmail' => 'false',
			);
			$export_success = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_export_personal_data() );
			$export_after   = \wp_get_user_request( $export_id );
			$raw_export     = \get_post_meta( $export_id, '_export_data_raw', true );
			$grouped_export = \get_post_meta( $export_id, '_export_data_grouped', true );
			$export_file    = \get_post_meta( $export_id, '_export_file_name', true );

			$_POST = $_GET = $_REQUEST = array(
				'id'       => (string) $erase_id,
				'eraser'   => '1',
				'page'     => '1',
				'security' => \wp_create_nonce( 'wp-privacy-erase-personal-data-' . $erase_id ),
			);
			$erase_success = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_erase_personal_data() );
			$erase_after   = \wp_get_user_request( $erase_id );
			$erase_completed_at = \get_post_meta( $erase_id, '_wp_user_request_completed_timestamp', true );
		} finally {
			\remove_action( 'wp_privacy_personal_data_erased', $erased_action, 10 );
			if ( ! $had_erasure_processor ) {
				\remove_filter( 'wp_privacy_personal_data_erasure_page', 'wp_privacy_process_personal_data_erasure_page', 10 );
			}
			if ( ! $had_export_processor ) {
				\remove_filter( 'wp_privacy_personal_data_export_page', 'wp_privacy_process_personal_data_export_page', 10 );
			}
			\remove_filter( 'wp_privacy_personal_data_erasers', $erasers, 10 );
			\remove_filter( 'wp_privacy_personal_data_exporters', $exporters, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			$filters_removed = false === \has_filter( 'user_has_cap', $cap_filter )
				&& false === \has_filter( 'wp_privacy_personal_data_exporters', $exporters )
				&& false === \has_filter( 'wp_privacy_personal_data_erasers', $erasers )
				&& $had_export_processor === ( false !== \has_filter( 'wp_privacy_personal_data_export_page', 'wp_privacy_process_personal_data_export_page' ) )
				&& $had_erasure_processor === ( false !== \has_filter( 'wp_privacy_personal_data_erasure_page', 'wp_privacy_process_personal_data_erasure_page' ) )
				&& false === \has_filter( 'wp_privacy_personal_data_erased', $erased_action );
		}

		$raw_export_item = is_array( $raw_export ) ? ( $raw_export[0] ?? array() ) : array();

		self::collect_failure(
			$failures,
			true === ( $export_success['json']['success'] ?? null )
				&& $export_success['captured']
				&& $export_success['filtersRestored']
				&& array( array( 'email' => $export_email, 'page' => 1 ) ) === $exporter_calls
				&& $export_after instanceof \WP_User_Request
				&& 'request-confirmed' === $export_after->status
				&& is_array( $raw_export )
				&& 1 === count( $raw_export )
				&& 'privacy-admin-default' === ( $raw_export_item['group_id'] ?? null )
				&& 'item-' . $token === ( $raw_export_item['item_id'] ?? null )
				&& '' === $grouped_export
				&& '' === $export_file
				&& false === ( $export_success['json']['data']['done'] ?? null ),
			'default export AJAX processor accumulates raw data without final file/mail side effects when exporter is not done',
			array(
				'exporterCalls' => self::describe_value( $exporter_calls ),
				'grouped'       => self::describe_value( $grouped_export ),
				'raw'           => self::describe_value( $raw_export ),
				'request'       => self::describe_value( $export_after ),
				'response'      => self::describe_value( $export_success ),
			)
		);

		self::collect_failure(
			$failures,
			true === ( $erase_success['json']['success'] ?? null )
				&& $erase_success['captured']
				&& $erase_success['filtersRestored']
				&& array( array( 'email' => $erase_email, 'page' => 1 ) ) === $eraser_calls
				&& $erase_after instanceof \WP_User_Request
				&& 'request-completed' === $erase_after->status
				&& $erase_after->completed_timestamp > 0
				&& (int) $erase_completed_at === $erase_after->completed_timestamp
				&& array( $erase_id ) === $erased_actions
				&& true === ( $erase_success['json']['data']['items_removed'] ?? null )
				&& false === ( $erase_success['json']['data']['items_retained'] ?? null ),
			'default erasure AJAX processor completes the request, stores completion meta, and fires erased action',
			array(
				'actions'     => self::describe_value( $erased_actions ),
				'eraserCalls' => self::describe_value( $eraser_calls ),
				'request'     => self::describe_value( $erase_after ),
				'response'    => self::describe_value( $erase_success ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed,
			'default AJAX processor filters, capability filter, and erased action are removed',
			array( 'filtersRemoved' => $filters_removed )
		);

		self::reset_db_content();

		return self::row(
			$ctx,
			'privacy-admin-requests.ajax-default-processors.state-transitions',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_ajax_export_handler( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::token( $ctx );
		$email    = 'ajax-export-' . $token . '@example.com';
		$request_id = 98100 + $ctx->int( 0, 50 );

		self::reset_db_content();
		self::seed_admin_user( 97141, 'privacy-admin-export-' . $token );
		\wp_set_current_user( 97141 );
		self::seed_privacy_request( $request_id, 'export_personal_data', 'request-confirmed', $email, $token );

		$cap_filter = self::cap_filter( array( 'export_others_personal_data', 'manage_options' ) );
		$exporter_calls = array();
		$page_filter_calls = array();
		$exporters = static function () use ( &$exporter_calls ): array {
			return array(
				'component-exporter' => array(
					'exporter_friendly_name' => 'Component Exporter',
					'callback'               => static function ( string $email_address, int $page ) use ( &$exporter_calls ): array {
						$exporter_calls[] = array(
							'email' => $email_address,
							'page'  => $page,
						);

						return array(
							'data' => array(
								array(
									'group_id'    => 'component-export',
									'group_label' => 'Component Export',
									'item_id'     => 'component-item',
									'data'        => array(
										array(
											'name'  => 'Email',
											'value' => $email_address,
										),
									),
								),
							),
							'done' => true,
						);
					},
				),
			);
		};
		$page_filter = static function ( array $response, int $exporter_index, string $email_address, int $page, int $seen_request_id, bool $send_as_email, string $exporter_key ) use ( &$page_filter_calls ): array {
			$page_filter_calls[] = compact( 'exporter_index', 'email_address', 'page', 'seen_request_id', 'send_as_email', 'exporter_key' );
			$response['data'][] = array(
				'group_id'    => 'filtered-export',
				'group_label' => 'Filtered Export',
				'item_id'     => 'filtered-item',
				'data'        => array(),
			);
			return $response;
		};

		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		\add_filter( 'wp_privacy_personal_data_exporters', $exporters, 10, 0 );
		\add_filter( 'wp_privacy_personal_data_export_page', $page_filter, 10, 7 );
		try {
			$_POST = $_REQUEST = array(
				'id'          => (string) $request_id,
				'exporter'    => '1',
				'page'        => '2',
				'sendAsEmail' => 'true',
				'security'    => \wp_create_nonce( 'wp-privacy-export-personal-data-' . $request_id ),
			);
			$success = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_export_personal_data() );
		} finally {
			\remove_filter( 'wp_privacy_personal_data_export_page', $page_filter, 10 );
			\remove_filter( 'wp_privacy_personal_data_exporters', $exporters, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$_POST = $_REQUEST = array();
		$missing_id = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_export_personal_data() );

		$_POST = $_REQUEST = array(
			'id' => (string) $request_id,
		);
		$cap_denied = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_export_personal_data() );

		$bad_exporters = static fn() => array( 'bad-exporter' => 'not-an-array' );
		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		\add_filter( 'wp_privacy_personal_data_exporters', $bad_exporters, 10, 0 );
		try {
			$_POST = $_REQUEST = array(
				'id'       => (string) $request_id,
				'exporter' => '1',
				'page'     => '1',
				'security' => \wp_create_nonce( 'wp-privacy-export-personal-data-' . $request_id ),
			);
			$bad_shape = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_export_personal_data() );
		} finally {
			\remove_filter( 'wp_privacy_personal_data_exporters', $bad_exporters, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$success['captured']
				&& true === ( $success['json']['success'] ?? null )
				&& true === ( $success['json']['data']['done'] ?? null )
				&& 2 === count( $success['json']['data']['data'] ?? array() )
				&& array( array( 'email' => $email, 'page' => 2 ) ) === $exporter_calls
				&& 1 === count( $page_filter_calls )
				&& 1 === ( $page_filter_calls[0]['exporter_index'] ?? null )
				&& $email === ( $page_filter_calls[0]['email_address'] ?? null )
				&& 2 === ( $page_filter_calls[0]['page'] ?? null )
				&& $request_id === ( $page_filter_calls[0]['seen_request_id'] ?? null )
				&& true === ( $page_filter_calls[0]['send_as_email'] ?? null )
				&& 'component-exporter' === ( $page_filter_calls[0]['exporter_key'] ?? null )
				&& $success['filtersRestored'],
			'export AJAX success validates request, calls selected exporter/page filter, and returns JSON success payload',
			array(
				'success'       => $success,
				'exporterCalls' => $exporter_calls,
				'filterCalls'   => $page_filter_calls,
			)
		);

		self::collect_failure(
			$failures,
			false === ( $missing_id['json']['success'] ?? true )
				&& 'Missing request ID.' === ( $missing_id['json']['data'] ?? null )
				&& false === ( $cap_denied['json']['success'] ?? true )
				&& 'Sorry, you are not allowed to perform this action.' === ( $cap_denied['json']['data'] ?? null )
				&& false === ( $bad_shape['json']['success'] ?? true )
				&& str_contains( (string) ( $bad_shape['json']['data'] ?? '' ), 'Expected an array describing the exporter' ),
			'export AJAX fail-closed branches return stable JSON errors before callback execution',
			array(
				'missingId' => $missing_id,
				'capDenied' => $cap_denied,
				'badShape'  => $bad_shape,
			)
		);

		self::reset_db_content();

		return self::row(
			$ctx,
			'privacy-admin-requests.ajax-export.validation-callback-and-error-contracts',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_ajax_erasure_handler( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::token( $ctx );
		$email    = 'ajax-erase-' . $token . '@example.com';
		$request_id = 98200 + $ctx->int( 0, 50 );

		self::reset_db_content();
		self::seed_admin_user( 97161, 'privacy-admin-erase-' . $token );
		\wp_set_current_user( 97161 );
		self::seed_privacy_request( $request_id, 'remove_personal_data', 'request-confirmed', $email, $token );

		$cap_filter = self::cap_filter( array( 'erase_others_personal_data', 'delete_users', 'manage_options' ) );
		$eraser_calls = array();
		$page_filter_calls = array();
		$erasers = static function () use ( &$eraser_calls ): array {
			return array(
				'component-eraser' => array(
					'eraser_friendly_name' => 'Component Eraser',
					'callback'             => static function ( string $email_address, int $page ) use ( &$eraser_calls ): array {
						$eraser_calls[] = array(
							'email' => $email_address,
							'page'  => $page,
						);

						return array(
							'items_removed'  => true,
							'items_retained' => false,
							'messages'       => array( 'erased ' . $email_address ),
							'done'           => true,
						);
					},
				),
			);
		};
		$page_filter = static function ( array $response, int $eraser_index, string $email_address, int $page, int $seen_request_id, string $eraser_key ) use ( &$page_filter_calls ): array {
			$page_filter_calls[] = compact( 'eraser_index', 'email_address', 'page', 'seen_request_id', 'eraser_key' );
			$response['messages'][] = 'filtered erasure';
			return $response;
		};

		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		\add_filter( 'wp_privacy_personal_data_erasers', $erasers, 10, 0 );
		\add_filter( 'wp_privacy_personal_data_erasure_page', $page_filter, 10, 6 );
		try {
			$_POST = $_REQUEST = array(
				'id'       => (string) $request_id,
				'eraser'   => '1',
				'page'     => '3',
				'security' => \wp_create_nonce( 'wp-privacy-erase-personal-data-' . $request_id ),
			);
			$success = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_erase_personal_data() );
		} finally {
			\remove_filter( 'wp_privacy_personal_data_erasure_page', $page_filter, 10 );
			\remove_filter( 'wp_privacy_personal_data_erasers', $erasers, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$_POST = $_REQUEST = array(
			'id' => (string) $request_id,
		);
		$cap_denied = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_erase_personal_data() );

		$bad_erasers = static fn() => array(
			'bad-eraser' => array(
				'eraser_friendly_name' => 'Bad Eraser',
				'callback'             => static fn() => array(
					'items_removed'  => true,
					'items_retained' => false,
					'done'           => true,
				),
			),
		);
		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		\add_filter( 'wp_privacy_personal_data_erasers', $bad_erasers, 10, 0 );
		try {
			$_POST = $_REQUEST = array(
				'id'       => (string) $request_id,
				'eraser'   => '1',
				'page'     => '1',
				'security' => \wp_create_nonce( 'wp-privacy-erase-personal-data-' . $request_id ),
			);
			$bad_shape = self::capture_ajax_call( static fn() => \wp_ajax_wp_privacy_erase_personal_data() );
		} finally {
			\remove_filter( 'wp_privacy_personal_data_erasers', $bad_erasers, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$success['captured']
				&& true === ( $success['json']['success'] ?? null )
				&& true === ( $success['json']['data']['items_removed'] ?? null )
				&& false === ( $success['json']['data']['items_retained'] ?? null )
				&& array( 'erased ' . $email, 'filtered erasure' ) === ( $success['json']['data']['messages'] ?? null )
				&& true === ( $success['json']['data']['done'] ?? null )
				&& array( array( 'email' => $email, 'page' => 3 ) ) === $eraser_calls
				&& 1 === count( $page_filter_calls )
				&& 1 === ( $page_filter_calls[0]['eraser_index'] ?? null )
				&& $email === ( $page_filter_calls[0]['email_address'] ?? null )
				&& 3 === ( $page_filter_calls[0]['page'] ?? null )
				&& $request_id === ( $page_filter_calls[0]['seen_request_id'] ?? null )
				&& 'component-eraser' === ( $page_filter_calls[0]['eraser_key'] ?? null )
				&& $success['filtersRestored'],
			'erasure AJAX success validates request, calls selected eraser/page filter, and returns JSON success payload',
			array(
				'success'     => $success,
				'eraserCalls' => $eraser_calls,
				'filterCalls' => $page_filter_calls,
			)
		);

		self::collect_failure(
			$failures,
			false === ( $cap_denied['json']['success'] ?? true )
				&& 'Sorry, you are not allowed to perform this action.' === ( $cap_denied['json']['data'] ?? null )
				&& false === ( $bad_shape['json']['success'] ?? true )
				&& str_contains( (string) ( $bad_shape['json']['data'] ?? '' ), 'Expected messages key' ),
			'erasure AJAX fail-closed branches return stable JSON errors before invalid responses are accepted',
			array(
				'capDenied' => $cap_denied,
				'badShape'  => $bad_shape,
			)
		);

		self::reset_db_content();

		return self::row(
			$ctx,
			'privacy-admin-requests.ajax-erasure.validation-callback-and-error-contracts',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function seed_admin_user( int $user_id, string $login ): void {
		if ( ! self::wpdb_stub_available() ) {
			return;
		}

		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $login,
				'user_pass'       => '$P$ComponentFuzzPrivacyAdmin',
				'user_nicename'   => $login,
				'user_email'      => $login . '@example.test',
				'user_registered' => '2026-06-30 09:00:00',
				'display_name'    => 'Privacy Admin ' . $login,
				'user_status'     => 0,
			)
		);
		\clean_user_cache( $user_id );
		\wp_cache_delete( $user_id, 'user_meta' );
	}

	private static function export_table(): object {
		return new class(
			array(
				'plural' => 'privacy_requests',
				'screen' => 'export-personal-data',
			)
		) extends \WP_Privacy_Data_Export_Requests_List_Table {
			public function exposed_views(): array {
				return $this->get_views();
			}

			public function exposed_items(): array {
				return $this->items;
			}
		};
	}

	private static function removal_table(): object {
		return new class(
			array(
				'plural' => 'privacy_requests',
				'screen' => 'erase-personal-data',
			)
		) extends \WP_Privacy_Data_Removal_Requests_List_Table {
			public function exposed_views(): array {
				return $this->get_views();
			}

			public function exposed_items(): array {
				return $this->items;
			}
		};
	}

	private static function seed_privacy_request( int $id, string $action_name, string $status, string $email, string $token, string $date = '2026-06-30 10:00:00', ?string $modified_date = null ): void {
		if ( ! self::wpdb_stub_available() ) {
			return;
		}

		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->posts,
			get_object_vars(
				self::post_record(
					array(
						'ID'            => $id,
						'post_author'   => '0',
						'post_title'    => $email,
						'post_name'     => $action_name,
						'post_status'   => $status,
						'post_type'     => 'user_request',
						'post_content'  => \wp_json_encode(
							array(
								'token'  => $token,
								'action' => $action_name,
							)
						),
						'post_password' => 'request-key-' . $token . '-' . $id,
						'post_date'     => $date,
						'post_date_gmt' => $date,
						'post_modified'     => $modified_date ?? $date,
						'post_modified_gmt' => $modified_date ?? $date,
					)
				)
			)
		);
		\update_post_meta( $id, '_wp_user_request_confirmed_timestamp', 1770000000 + ( $id % 1000 ) );
		if ( 'request-completed' === $status ) {
			\update_post_meta( $id, '_wp_user_request_completed_timestamp', 1780000000 + ( $id % 1000 ) );
		}
		\clean_post_cache( $id );
	}

	private static function find_user_request( string $email, string $action_name ): ?\WP_User_Request {
		$query = new \WP_Query(
			array(
				'fields'        => 'ids',
				'post_name__in' => array( $action_name ),
				'post_status'   => array( 'request-pending', 'request-confirmed', 'request-failed', 'request-completed' ),
				'post_type'     => 'user_request',
				'title'         => $email,
			)
		);

		foreach ( $query->posts as $request_id ) {
			$request = \wp_get_user_request( (int) $request_id );
			if (
				$request instanceof \WP_User_Request
				&& $email === $request->email
				&& $action_name === $request->action_name
			) {
				return $request;
			}
		}

		return null;
	}

	private static function mail_event_index_for_email( array $mail_events, string $email ): int {
		foreach ( $mail_events as $index => $mail ) {
			if ( is_array( $mail ) && $email === ( $mail['to'] ?? null ) ) {
				return (int) $index;
			}
		}

		return -1;
	}

	private static function post_record( array $overrides ): object {
		return (object) array_merge(
			array(
				'ID'                    => 0,
				'post_author'           => '0',
				'post_date'             => '2026-06-30 10:00:00',
				'post_date_gmt'         => '2026-06-30 10:00:00',
				'post_content'          => '',
				'post_title'            => '',
				'post_excerpt'          => '',
				'post_status'           => 'request-pending',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'export_personal_data',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-30 10:00:00',
				'post_modified_gmt'     => '2026-06-30 10:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => '',
				'menu_order'            => 0,
				'post_type'             => 'user_request',
				'post_mime_type'        => '',
				'comment_count'         => '0',
			),
			$overrides
		);
	}

	private static function prime_request_count_cache( string $request_type, array $counts ): void {
		$all_counts = array_fill_keys( array( 'request-pending', 'request-confirmed', 'request-failed', 'request-completed' ), 0 );
		foreach ( $counts as $status => $count ) {
			$all_counts[ $status ] = $count;
		}

		\wp_cache_set( 'user_request-' . $request_type, (object) $all_counts, 'counts' );
	}

	private static function capture_ajax_call( callable $callback ): array {
		$die_calls      = array();
		$doing_ajax     = static fn() => true;
		$handler_filter = static function () use ( &$die_calls ) {
			return static function ( $message = '', $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'message' => $message,
					'title'   => $title,
					'args'    => $args,
				);
				throw new PrivacyAdminRequestsSurface_DieCaptured( 'Captured ajax wp_die.' );
			};
		};

		$level    = ob_get_level();
		$output   = '';
		$captured = false;
		$threw    = null;

		\add_filter( 'wp_doing_ajax', $doing_ajax, 1 );
		\add_filter( 'wp_die_ajax_handler', $handler_filter, 1 );
		ob_start();
		try {
			$callback();
		} catch ( PrivacyAdminRequestsSurface_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$threw = self::describe_throwable( $e );
		} finally {
			$output = (string) ob_get_clean();
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			\remove_filter( 'wp_die_ajax_handler', $handler_filter, 1 );
			\remove_filter( 'wp_doing_ajax', $doing_ajax, 1 );
		}

		return array(
			'captured'        => $captured,
			'threw'           => $threw,
			'output'          => self::describe_string( $output ),
			'json'            => self::decode_json_object( $output ),
			'dieCalls'        => $die_calls,
			'filtersRestored' => false === \has_filter( 'wp_die_ajax_handler', $handler_filter ) && false === \has_filter( 'wp_doing_ajax', $doing_ajax ),
			'bufferBalanced'  => ob_get_level() === $level,
		);
	}

	private static function capture_output( callable $callback ): string {
		ob_start();
		try {
			$result = $callback();
			$output = (string) ob_get_clean();
			if ( is_string( $result ) ) {
				$output .= $result;
			}
			return $output;
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	private static function decode_json_object( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function html_attr( string $html, string $attr ): string {
		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '="([^"]*)"/', $html, $matches ) ) {
			return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}

	private static function cap_filter( array $granted_caps ): callable {
		$map = array_fill_keys( $granted_caps, true );
		return static function ( array $allcaps ) use ( $map ): array {
			foreach ( $map as $cap => $grant ) {
				$allcaps[ $cap ] = $grant;
			}
			return $allcaps;
		};
	}

	private static function token( \ComponentFuzz\FuzzContext $ctx ): string {
		$token = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 5, 10 ) ) );
		return '' === $token ? 'privacy' . abs( $ctx->seed() % 10000 ) : $token;
	}

	private static function initialize_core_content_types(): void {
		\create_initial_post_types();
		\create_initial_taxonomies();
	}

	private static function install_scoped_email_filters(): void {
		\add_filter( 'is_email', 'wp_is_unicode_email', 10, 3 );
		\add_filter( 'sanitize_email', 'wp_sanitize_unicode_email', 10, 3 );
	}

	private static function reset_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['REQUEST_URI']     = '/wp-admin/admin-ajax.php';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.81';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/privacy-admin-requests';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['SERVER_PORT']     = '80';

		$GLOBALS['pagenow'] = 'admin-ajax.php';
	}

	private static function snapshot_state(): array {
		$global_keys = array(
			'current_screen',
			'pagenow',
			'post',
			'wp_actions',
			'wp_current_filter',
			'wp_filter',
			'wp_filters',
			'wp_object_cache',
			'wp_settings_errors',
			'current_user',
			'user_ID',
		);
		$globals = array();
		foreach ( $global_keys as $key ) {
			$globals[ $key ] = array_key_exists( $key, $GLOBALS )
				? array( 'exists' => true, 'value' => $GLOBALS[ $key ] )
				: array( 'exists' => false, 'value' => null );
		}

		return array(
			'get'     => $_GET,
			'post'    => $_POST,
			'request' => $_REQUEST,
			'cookie'  => $_COOKIE,
			'server'  => $_SERVER,
			'globals' => $globals,
			'wp_filter' => $GLOBALS['wp_filter'] ?? array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		$_GET     = $snapshot['get'];
		$_POST    = $snapshot['post'];
		$_REQUEST = $snapshot['request'];
		$_COOKIE  = $snapshot['cookie'];
		$_SERVER  = $snapshot['server'];

		foreach ( $snapshot['globals'] as $key => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $key ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		return $_GET === $snapshot['get']
			&& $_POST === $snapshot['post']
			&& $_REQUEST === $snapshot['request']
			&& $_COOKIE === $snapshot['cookie']
			&& $_SERVER === $snapshot['server']
			&& self::db_counts_empty();
	}

	private static function wpdb_stub_available(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' );
	}

	private static function reset_db_content(): void {
		if ( self::wpdb_stub_available() ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			self::flush_runtime_cache();
		}
	}

	private static function flush_runtime_cache(): void {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			\wp_cache_flush_runtime();
			return;
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function db_counts(): array {
		if ( self::wpdb_stub_available() && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function query_value_from_text( string $text, string $name ): string {
		if ( ! preg_match( '/[?&]' . preg_quote( $name, '/' ) . '=([^\\s&#]+)/', $text, $matches ) ) {
			return '';
		}

		return rawurldecode( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
	}

	private static function has_settings_error( array $errors, string $type, string $message_fragment ): bool {
		foreach ( $errors as $error ) {
			if (
				is_array( $error )
				&& $type === ( $error['type'] ?? null )
				&& str_contains( (string) ( $error['message'] ?? '' ), $message_fragment )
			) {
				return true;
			}
		}

		return false;
	}

	private static function db_counts_empty(): bool {
		foreach ( self::db_counts() as $count ) {
			if ( 0 !== (int) $count ) {
				return false;
			}
		}

		return true;
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array(), ?string $status = null ): array {
		return array(
			'ok'        => $ok,
			'status'    => $status ?? ( $ok ? 'passed' : 'failed' ),
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'seed'      => $ctx->seed(),
			'data'      => $data,
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function describe_string( string $value, int $limit = 220 ): array {
		return array(
			'type'    => 'string',
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => substr( $value, 0, $limit ),
		);
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'describe_value' ), $value );
		}
		if ( is_object( $value ) ) {
			return array(
				'type'  => get_class( $value ),
				'props' => array_map( array( __CLASS__, 'describe_value' ), get_object_vars( $value ) ),
			);
		}
		return $value;
	}

	private static function is_error_code( $value, string $code ): bool {
		return $value instanceof \WP_Error && $code === $value->get_error_code();
	}
}

final class PrivacyAdminRequestsSurface_DieCaptured extends \RuntimeException {}
