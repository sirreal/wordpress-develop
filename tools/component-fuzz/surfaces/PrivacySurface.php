<?php
namespace ComponentFuzz\Surfaces;

final class PrivacySurface {
	public const NAME = 'privacy';

	private const GENERATED_REQUEST_CASES = 10;
	private const SAMPLE_BYTES            = 160;
	private const MAX_FAILURES            = 12;
	private const POLICY_CONTENT_META_KEY = '_wp_suggested_privacy_policy_content';

	/** @var array<int,array<string,mixed>> */
	private static array $post_meta = array();

	/** @var array<string,mixed> */
	private static array $options = array();

	/** @var array<string,array<string,mixed>> */
	private static array $exporters = array();

	/** @var array<string,array<string,mixed>> */
	private static array $erasers = array();

	/** @var array<int,array<string,mixed>> */
	private static array $export_file_actions = array();

	/** @var int[] */
	private static array $erased_actions = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'privacy.bootstrap-apis-available',
					'Required WordPress privacy APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_runtime();
			self::reset_static_state();
			self::install_scoped_filters();

			$request_cases = self::request_cases( $ctx );

			$rows[] = self::check_user_request_objects( $ctx->fork( 'user-request-objects' ), $request_cases );
			$rows[] = self::check_action_descriptions( $ctx->fork( 'action-descriptions' ) );
			$rows[] = self::check_request_lifecycle_helpers( $ctx->fork( 'request-lifecycle' ) );
			$rows[] = self::check_user_request_keys( $ctx->fork( 'request-keys' ) );
			$rows[] = self::check_missing_user_request_key( $ctx->fork( 'missing-request-key' ) );
			$rows[] = self::check_user_request_key_expiration_filters( $ctx->fork( 'request-key-expiration-filters' ) );
			$rows[] = self::check_confirmation_messages( $ctx->fork( 'confirmation-messages' ) );
			$rows[] = self::check_export_group_html( $ctx->fork( 'export-group-html' ) );
			$rows[] = self::check_registry_filters( $ctx->fork( 'registry-filters' ) );
			$rows[] = self::check_comment_privacy_callbacks( $ctx->fork( 'comment-privacy-callbacks' ) );
			$rows[] = self::check_builtin_user_media_exporters( $ctx->fork( 'builtin-user-media-exporters' ) );
			$rows[] = self::check_export_processor( $ctx->fork( 'export-processor' ) );
			$rows[] = self::check_export_email_notification( $ctx->fork( 'export-email' ) );
			$rows[] = self::check_export_email_negative_paths( $ctx->fork( 'export-email-negative' ) );
			$rows[] = self::check_erasure_processor( $ctx->fork( 'erasure-processor' ) );
			$rows[] = self::check_anonymization_helpers( $ctx->fork( 'anonymization' ) );
			$rows[] = self::check_export_paths_and_cleanup( $ctx->fork( 'export-paths-cleanup' ) );
			$rows[] = self::check_policy_content_helpers( $ctx->fork( 'policy-content' ) );
			$rows[] = self::check_policy_content_lifecycle( $ctx->fork( 'policy-content-lifecycle' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'privacy.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_static_state();
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	public static function filter_get_post_metadata( $value, int $object_id, string $meta_key, bool $single, string $meta_type ) {
		if ( 'post' !== $meta_type ) {
			return $value;
		}

		$meta = self::$post_meta[ $object_id ] ?? array();
		if ( '' === $meta_key ) {
			return $meta;
		}

		if ( ! array_key_exists( $meta_key, $meta ) ) {
			return $single ? array( '' ) : array();
		}

		if ( self::POLICY_CONTENT_META_KEY === $meta_key ) {
			$values = is_array( $meta[ $meta_key ] ) ? array_values( $meta[ $meta_key ] ) : array( $meta[ $meta_key ] );
			return $single ? array( $values[0] ?? '' ) : $values;
		}

		return $single ? array( $meta[ $meta_key ] ) : array( $meta[ $meta_key ] );
	}

	public static function filter_add_post_metadata( $check, int $object_id, string $meta_key, $meta_value, bool $unique ) {
		unset( $check );

		if ( ! isset( self::$post_meta[ $object_id ] ) ) {
			self::$post_meta[ $object_id ] = array();
		}

		if ( self::POLICY_CONTENT_META_KEY === $meta_key ) {
			$values = self::$post_meta[ $object_id ][ $meta_key ] ?? array();
			$values = is_array( $values ) ? array_values( $values ) : array( $values );
			if ( $unique && array() !== $values ) {
				return false;
			}

			$values[] = $meta_value;
			self::$post_meta[ $object_id ][ $meta_key ] = $values;
			return count( $values );
		}

		if ( $unique && array_key_exists( $meta_key, self::$post_meta[ $object_id ] ) ) {
			return false;
		}

		self::$post_meta[ $object_id ][ $meta_key ] = $meta_value;

		return count( self::$post_meta[ $object_id ] );
	}

	public static function filter_update_post_metadata( $check, int $object_id, string $meta_key, $meta_value, $prev_value ) {
		unset( $check, $prev_value );

		if ( ! isset( self::$post_meta[ $object_id ] ) ) {
			self::$post_meta[ $object_id ] = array();
		}

		self::$post_meta[ $object_id ][ $meta_key ] = self::POLICY_CONTENT_META_KEY === $meta_key ? array( $meta_value ) : $meta_value;

		return true;
	}

	public static function filter_delete_post_metadata( $check, int $object_id, string $meta_key, $meta_value, bool $delete_all ) {
		unset( $check );

		if ( $delete_all ) {
			foreach ( self::$post_meta as $id => $meta ) {
				self::delete_post_meta_value( $id, $meta_key, $meta_value );
			}
			return true;
		}

		self::delete_post_meta_value( $object_id, $meta_key, $meta_value );

		return true;
	}

	public static function filter_pre_option( $pre_option, string $option, $default_value ) {
		unset( $default_value );

		return array_key_exists( $option, self::$options ) ? self::$options[ $option ] : $pre_option;
	}

	public static function filter_pre_update_option( $value, $old_value, string $option ) {
		self::$options[ $option ] = $value;

		return $old_value;
	}

	public static function filter_exporters( array $exporters ): array {
		unset( $exporters );

		return self::$exporters;
	}

	public static function filter_erasers( array $erasers ): array {
		unset( $erasers );

		return self::$erasers;
	}

	public static function filter_upload_dir( array $uploads ): array {
		$tmp = sys_get_temp_dir();

		$uploads['path']    = $tmp;
		$uploads['url']     = 'http://example.test/uploads';
		$uploads['subdir']  = '';
		$uploads['basedir'] = $tmp;
		$uploads['baseurl'] = 'http://example.test/uploads';
		$uploads['error']   = false;

		return $uploads;
	}

	public static function record_export_file_action( int $request_id ): void {
		self::$export_file_actions[] = array(
			'requestId' => $request_id,
			'grouped'   => \get_post_meta( $request_id, '_export_data_grouped', true ),
		);
	}

	public static function record_erased_action( int $request_id ): void {
		self::$erased_actions[] = $request_id;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'WP_Error',
				'WP_User_Request',
				'WP_Post',
				'WP_Query',
				'WP_Rewrite',
				'WP_Screen',
				'WP_User',
				'PHPMailer\PHPMailer\PHPMailer',
				'WP_PHPMailer',
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
				'add_post_meta',
				'clean_post_cache',
				'clean_user_cache',
				'current_user_can',
				'date_i18n',
				'delete_post_meta',
				'did_action',
				'esc_attr',
				'esc_html',
				'get_current_screen',
				'has_filter',
				'has_action',
				'get_comment',
				'get_comment_link',
				'get_comment_text',
				'get_comments',
				'get_option',
				'get_post_meta',
				'get_user_by',
				'get_user_meta',
				'is_wp_error',
				'maybe_serialize',
				'maybe_unserialize',
				'remove_action',
				'remove_filter',
				'sanitize_email',
				'sanitize_title_with_dashes',
				'update_post_meta',
				'update_option',
				'wp_cache_delete',
				'wp_generate_user_request_key',
				'wp_cache_set',
				'wp_fast_hash',
				'wp_get_attachment_url',
				'wp_get_user_request',
				'wp_json_encode',
				'wp_mail',
				'wp_is_unicode_email',
				'wp_sanitize_unicode_email',
				'wp_privacy_generate_personal_data_export_group_html',
				'wp_privacy_process_personal_data_erasure_page',
				'wp_privacy_process_personal_data_export_page',
				'wp_privacy_send_personal_data_export_email',
				'wp_privacy_anonymize_data',
				'wp_privacy_anonymize_ip',
				'wp_privacy_delete_old_export_files',
				'wp_privacy_exports_dir',
				'wp_privacy_exports_url',
				'wp_add_privacy_policy_content',
				'wp_admin_notice',
				'wp_user_request_action_description',
				'wp_validate_user_request_key',
				'wp_verify_fast_hash',
				'wp_comments_personal_data_eraser',
				'wp_comments_personal_data_exporter',
				'wp_insert_comment',
				'wp_media_personal_data_exporter',
				'wp_register_comment_personal_data_eraser',
				'wp_register_comment_personal_data_exporter',
				'wp_register_media_personal_data_exporter',
				'wp_register_user_personal_data_exporter',
				'wp_user_personal_data_exporter',
				'_wp_privacy_account_request_confirmed',
				'_wp_privacy_account_request_confirmed_message',
				'_wp_privacy_completed_request',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
			$missing[] = 'class WP_Privacy_Policy_Content';
		}

		return $missing;
	}

	private static function check_user_request_objects( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures       = array();
		$observed       = array();
		$non_array_data = 0;
		$invalid_dates  = 0;

		foreach ( $cases as $case ) {
			self::prime_request_post( $case['post'] );
			self::set_post_meta(
				(int) $case['post']->ID,
				array(
					'_wp_user_request_confirmed_timestamp' => $case['confirmed'],
					'_wp_user_request_completed_timestamp' => $case['completed'],
				)
			);

			$direct = self::call(
				static function () use ( $case ) {
					return new \WP_User_Request( $case['post'] );
				}
			);
			$looked_up = self::call(
				static function () use ( $case ) {
					return \wp_get_user_request( (int) $case['post']->ID );
				}
			);

			if ( $direct['threw'] || $looked_up['threw'] ) {
				self::record_failure(
					$failures,
					'wp-user-request.constructor-and-cache-lookup.no-throw',
					$case,
					array(
						'direct'   => self::describe_call( $direct ),
						'lookedUp' => self::describe_call( $looked_up ),
					)
				);
				continue;
			}

			$direct_request = $direct['value'];
			$lookup_request = $looked_up['value'];
			if ( ! ( $direct_request instanceof \WP_User_Request ) || ! ( $lookup_request instanceof \WP_User_Request ) ) {
				self::record_failure(
					$failures,
					'wp-user-request.constructor-and-cache-lookup.return-type',
					$case,
					array(
						'direct'   => self::describe_value( $direct_request ),
						'lookedUp' => self::describe_value( $lookup_request ),
					)
				);
				continue;
			}

			$expected_data = json_decode( $case['post']->post_content, true );
			if ( ! is_array( $expected_data ) ) {
				++$non_array_data;
			}

			$expected_created  = strtotime( $case['post']->post_date_gmt );
			$expected_modified = strtotime( $case['post']->post_modified_gmt );
			if ( false === $expected_created || false === $expected_modified ) {
				++$invalid_dates;
			}

			$mapped_ok = self::request_matches_post( $direct_request, $case, $expected_data, $expected_created, $expected_modified )
				&& self::request_matches_post( $lookup_request, $case, $expected_data, $expected_created, $expected_modified )
				&& self::request_public_shape_ok( $direct_request )
				&& self::request_public_shape_ok( $lookup_request );

			if ( ! $mapped_ok ) {
				self::record_failure(
					$failures,
					'wp-user-request.field-mapping-and-shape',
					$case,
					array(
						'expectedData'     => self::describe_value( $expected_data ),
						'expectedCreated'  => $expected_created,
						'expectedModified' => $expected_modified,
						'direct'           => self::describe_user_request( $direct_request ),
						'lookedUp'         => self::describe_user_request( $lookup_request ),
					)
				);
			}

			$observed[] = array(
				'label'          => $case['label'],
				'emailSanitized' => \sanitize_email( $case['post']->post_title ),
				'action'         => self::describe_string( $case['post']->post_name ),
				'requestData'    => is_array( $expected_data ) ? 'array' : gettype( $expected_data ),
				'createdType'    => gettype( $direct_request->created_timestamp ),
				'modifiedType'   => gettype( $direct_request->modified_timestamp ),
			);
		}

		return self::row(
			$ctx,
			'privacy.wp-user-request.synthetic-object-shape',
			array() === $failures,
			array(
				'cases'             => count( $cases ),
				'nonArrayJsonData'  => $non_array_data,
				'invalidDateInputs' => $invalid_dates,
				'observed'          => array_slice( $observed, 0, 8 ),
				'failures'          => $failures,
			)
		);
	}

	private static function check_action_descriptions( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$unknown  = 'custom<privacy-action-' . $ctx->int( 1000, 9999 ) . "-\xC3\xA9>\"&";

		$known = array(
			'export_personal_data' => 'Export Personal Data',
			'remove_personal_data' => 'Erase Personal Data',
		);

		foreach ( $known as $action => $expected ) {
			$actual = self::call(
				static function () use ( $action ) {
					return \wp_user_request_action_description( $action );
				}
			);

			if ( $actual['threw'] || $expected !== $actual['value'] ) {
				self::record_failure(
					$failures,
					'action-description.known-stable',
					array( 'label' => $action ),
					array(
						'expected' => $expected,
						'actual'   => self::describe_call( $actual ),
					)
				);
			}
		}

		$default = self::call(
			static function () use ( $unknown ) {
				return \wp_user_request_action_description( $unknown );
			}
		);
		$expected_default = sprintf( 'Confirm the "%s" action', $unknown );
		if ( $default['threw'] || $expected_default !== $default['value'] ) {
			self::record_failure(
				$failures,
				'action-description.unknown-default-shape',
				array( 'label' => 'unknown-default' ),
				array(
					'unknown'  => self::describe_string( $unknown ),
					'expected' => self::describe_string( $expected_default ),
					'actual'   => self::describe_call( $default ),
				)
			);
		}

		$seen_filter = array();
		\add_filter(
			'user_request_action_description',
			static function ( string $description, string $action_name ) use ( &$seen_filter, $unknown ): string {
				$seen_filter[] = array(
					'description' => $description,
					'action'      => $action_name,
				);

				if ( $unknown !== $action_name ) {
					return $description;
				}

				return 'filtered:' . \esc_html( $description ) . ':action=' . \esc_attr( $action_name );
			},
			10,
			2
		);

		$filtered = self::call(
			static function () use ( $unknown ) {
				return \wp_user_request_action_description( $unknown );
			}
		);
		$filter_saw_raw = array() !== $seen_filter
			&& $expected_default === $seen_filter[0]['description']
			&& $unknown === $seen_filter[0]['action'];
		$filter_escaped = ! $filtered['threw']
			&& is_string( $filtered['value'] )
			&& str_starts_with( $filtered['value'], 'filtered:' )
			&& false === strpos( $filtered['value'], '<' )
			&& str_contains( $filtered['value'], '&lt;' )
			&& str_contains( $filtered['value'], '&quot;' );

		if ( ! $filter_saw_raw || ! $filter_escaped ) {
			self::record_failure(
				$failures,
				'action-description.filter-can-escape-unknown',
				array( 'label' => 'unknown-filtered' ),
				array(
					'unknown'      => self::describe_string( $unknown ),
					'seenFilter'   => self::describe_value( $seen_filter ),
					'filteredCall' => self::describe_call( $filtered ),
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.action-description.known-unknown-filtered',
			array() === $failures,
			array(
				'unknown'  => self::describe_string( $unknown ),
				'failures' => $failures,
			)
		);
	}

	private static function check_request_lifecycle_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::wpdb_stub_available() ) {
			return self::skip(
				$ctx,
				'privacy.request-lifecycle.key-confirm-complete',
				'The wpdb content stub is unavailable for DB-backed request lifecycle helpers.'
			);
		}

		$failures = array();

		self::reset_db_content();
		try {
			$request_id = 94001;
			self::seed_db_request_post(
				self::post_record(
					array(
						'ID'                => $request_id,
						'post_title'        => 'lifecycle@example.test',
						'post_name'         => 'export_personal_data',
						'post_status'       => 'request-failed',
						'post_content'      => \wp_json_encode( array( 'source' => 'request-lifecycle' ) ),
						'post_password'     => \wp_fast_hash( 'old-confirm-key' ),
						'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					)
				)
			);
			self::set_post_meta( $request_id, array() );

			$key_call  = self::call(
				static function () use ( $request_id ) {
					return \wp_generate_user_request_key( $request_id );
				}
			);
			$key_after = \wp_get_user_request( $request_id );
			$key_ok    = ! $key_call['threw']
				&& is_string( $key_call['value'] )
				&& 20 === strlen( $key_call['value'] )
				&& $key_after instanceof \WP_User_Request
				&& 'request-pending' === $key_after->status
				&& \wp_verify_fast_hash( $key_call['value'], $key_after->confirm_key );

			if ( ! $key_ok ) {
				self::record_failure(
					$failures,
					'request-lifecycle.generate-key-updates-pending-hash',
					array( 'label' => 'generate-key' ),
					array(
						'keyCall' => self::describe_call( $key_call ),
						'request' => self::describe_value( $key_after ),
					)
				);
			}

			$confirm_call  = self::call(
				static function () use ( $request_id ): void {
					\_wp_privacy_account_request_confirmed( $request_id );
				}
			);
			$confirm_after = \wp_get_user_request( $request_id );
			$confirmed_at  = \get_post_meta( $request_id, '_wp_user_request_confirmed_timestamp', true );
			$confirm_ok    = ! $confirm_call['threw']
				&& $confirm_after instanceof \WP_User_Request
				&& 'request-confirmed' === $confirm_after->status
				&& is_int( $confirmed_at )
				&& $confirmed_at <= time()
				&& $confirmed_at >= time() - 5;

			if ( ! $confirm_ok ) {
				self::record_failure(
					$failures,
					'request-lifecycle.confirmed-action-marks-status-and-meta',
					array( 'label' => 'confirm-action' ),
					array(
						'confirmCall' => self::describe_call( $confirm_call ),
						'request'     => self::describe_value( $confirm_after ),
						'confirmedAt' => self::describe_value( $confirmed_at ),
					)
				);
			}

			$complete_call  = self::call(
				static function () use ( $request_id ) {
					return \_wp_privacy_completed_request( $request_id );
				}
			);
			$complete_after = \wp_get_user_request( $request_id );
			$completed_at   = \get_post_meta( $request_id, '_wp_user_request_completed_timestamp', true );
			$complete_ok    = ! $complete_call['threw']
				&& (int) $request_id === (int) $complete_call['value']
				&& $complete_after instanceof \WP_User_Request
				&& 'request-completed' === $complete_after->status
				&& is_int( $completed_at )
				&& $completed_at <= time()
				&& $completed_at >= time() - 5;

			if ( ! $complete_ok ) {
				self::record_failure(
					$failures,
					'request-lifecycle.completed-request-marks-status-and-meta',
					array( 'label' => 'complete-request' ),
					array(
						'completeCall' => self::describe_call( $complete_call ),
						'request'      => self::describe_value( $complete_after ),
						'completedAt'  => self::describe_value( $completed_at ),
					)
				);
			}

			$closed_id = 94002;
			self::seed_db_request_post(
				self::post_record(
					array(
						'ID'                => $closed_id,
						'post_title'        => 'closed@example.test',
						'post_name'         => 'remove_personal_data',
						'post_status'       => 'request-completed',
						'post_content'      => '{"closed":true}',
						'post_password'     => \wp_fast_hash( 'closed-key' ),
						'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					)
				)
			);
			self::set_post_meta( $closed_id, array() );

			$closed_call  = self::call(
				static function () use ( $closed_id ): void {
					\_wp_privacy_account_request_confirmed( $closed_id );
				}
			);
			$closed_after = \wp_get_user_request( $closed_id );
			$closed_meta  = \get_post_meta( $closed_id, '_wp_user_request_confirmed_timestamp', true );
			$closed_ok    = ! $closed_call['threw']
				&& $closed_after instanceof \WP_User_Request
				&& 'request-completed' === $closed_after->status
				&& '' === $closed_meta;

			if ( ! $closed_ok ) {
				self::record_failure(
					$failures,
					'request-lifecycle.confirm-action-ignores-closed-requests',
					array( 'label' => 'closed-request' ),
					array(
						'closedCall' => self::describe_call( $closed_call ),
						'request'    => self::describe_value( $closed_after ),
						'meta'       => self::describe_value( $closed_meta ),
					)
				);
			}
		} finally {
			self::reset_db_content();
		}

		return self::row(
			$ctx,
			'privacy.request-lifecycle.key-confirm-complete',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_user_request_keys( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$now      = time();
		$key      = 'key|' . self::random_string( $ctx->fork( 'matching-key' ), 32 );
		$wrong    = self::mutate_secret( $key );
		$hash     = \wp_fast_hash( $key );

		self::collect_failure(
			$failures,
			\wp_verify_fast_hash( $key, $hash ) && ! \wp_verify_fast_hash( $wrong, $hash ),
			'wp_fast_hash verifies only the matching request key bytes',
			array(
				'key'   => self::describe_string( $key ),
				'wrong' => self::describe_string( $wrong ),
				'hash'  => self::describe_string( $hash ),
			)
		);

		$cases = array(
			array(
				'label'      => 'pending-valid',
				'id'         => 91001,
				'status'     => 'request-pending',
				'storedHash' => $hash,
				'key'        => $key,
				'modified'   => $now - 60,
				'expected'   => true,
			),
			array(
				'label'      => 'failed-valid',
				'id'         => 91002,
				'status'     => 'request-failed',
				'storedHash' => $hash,
				'key'        => $key,
				'modified'   => $now - 60,
				'expected'   => true,
			),
			array(
				'label'      => 'wrong-key',
				'id'         => 91003,
				'status'     => 'request-pending',
				'storedHash' => $hash,
				'key'        => $wrong,
				'modified'   => $now - 60,
				'expected'   => 'invalid_key',
			),
			array(
				'label'      => 'wrong-hash',
				'id'         => 91004,
				'status'     => 'request-pending',
				'storedHash' => \wp_fast_hash( $wrong ),
				'key'        => $key,
				'modified'   => $now - 60,
				'expected'   => 'invalid_key',
			),
			array(
				'label'      => 'missing-key',
				'id'         => 91005,
				'status'     => 'request-pending',
				'storedHash' => $hash,
				'key'        => '',
				'modified'   => $now - 60,
				'expected'   => 'missing_key',
			),
			array(
				'label'      => 'completed-status',
				'id'         => 91006,
				'status'     => 'request-completed',
				'storedHash' => $hash,
				'key'        => $key,
				'modified'   => $now - 60,
				'expected'   => 'expired_request',
			),
			array(
				'label'      => 'missing-hash',
				'id'         => 91007,
				'status'     => 'request-pending',
				'storedHash' => '',
				'key'        => $key,
				'modified'   => $now - 60,
				'expected'   => 'invalid_request',
			),
			array(
				'label'      => 'missing-modified-time',
				'id'         => 91008,
				'status'     => 'request-pending',
				'storedHash' => $hash,
				'key'        => $key,
				'modified'   => '',
				'expected'   => 'invalid_request',
			),
			array(
				'label'      => 'expired-key',
				'id'         => 91009,
				'status'     => 'request-pending',
				'storedHash' => $hash,
				'key'        => $key,
				'modified'   => $now - 3 * DAY_IN_SECONDS,
				'expected'   => 'expired_key',
			),
		);

		foreach ( $cases as $case ) {
			$modified_gmt = is_int( $case['modified'] ) ? gmdate( 'Y-m-d H:i:s', $case['modified'] ) : (string) $case['modified'];
			$post         = self::post_record(
				array(
					'ID'                => $case['id'],
					'post_author'       => '0',
					'post_title'        => 'privacy-key-' . $case['id'] . '@example.test',
					'post_name'         => 'export_personal_data',
					'post_status'       => $case['status'],
					'post_content'      => '{"key":"validation"}',
					'post_password'     => $case['storedHash'],
					'post_modified_gmt' => $modified_gmt,
					'post_modified'     => $modified_gmt,
				)
			);
			self::prime_request_post( $post );
			self::set_post_meta( (int) $post->ID, array() );

			$actual = self::call(
				static function () use ( $case ) {
					return \wp_validate_user_request_key( $case['id'], $case['key'] );
				}
			);

			if ( true === $case['expected'] ) {
				$ok = ! $actual['threw'] && true === $actual['value'];
			} else {
				$ok = ! $actual['threw'] && self::is_error_code( $actual['value'], $case['expected'] );
			}

			if ( ! $ok ) {
				self::record_failure(
					$failures,
					'user-request-key.validation-case',
					$case,
					array(
						'expected' => $case['expected'],
						'actual'   => self::describe_call( $actual ),
					)
				);
			}
		}

		return self::row(
			$ctx,
			'privacy.user-request-key.fail-closed-hash-semantics',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_missing_user_request_key( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures           = array();
		$missing_request_id = 91990 + $ctx->int( 0, 999 );
		$valid_request_id   = 91250 + $ctx->int( 0, 99 );
		$key                = 'missing|' . self::random_string( $ctx->fork( 'key' ), 24 );
		$hash               = \wp_fast_hash( $key );
		$valid_post         = self::post_record(
			array(
				'ID'                => $valid_request_id,
				'post_title'        => 'global-fallback@example.test',
				'post_name'         => 'export_personal_data',
				'post_status'       => 'request-pending',
				'post_content'      => '{"key":"global-fallback"}',
				'post_password'     => $hash,
				'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);

		self::prime_request_post( $valid_post );
		self::set_post_meta( (int) $valid_post->ID, array() );

		$cases = array(
			array(
				'label' => 'uncached-missing-id',
				'id'    => $missing_request_id,
			),
			array(
				'label'  => 'zero-id-with-global-post',
				'id'     => 0,
				'global' => $valid_post,
			),
		);

		foreach ( $cases as $case ) {
			\wp_cache_delete( (int) $case['id'], 'posts' );
			unset( self::$post_meta[ (int) $case['id'] ] );

			$had_global_post = array_key_exists( 'post', $GLOBALS );
			$global_post     = $GLOBALS['post'] ?? null;
			if ( isset( $case['global'] ) ) {
				$GLOBALS['post'] = $case['global'];
			} else {
				unset( $GLOBALS['post'] );
			}

			$actual = self::call(
				static function () use ( $case, $key ) {
					return \wp_validate_user_request_key( $case['id'], $key );
				}
			);

			if ( $had_global_post ) {
				$GLOBALS['post'] = $global_post;
			} else {
				unset( $GLOBALS['post'] );
			}

			if ( $actual['threw'] || ! self::is_error_code( $actual['value'], 'invalid_request' ) ) {
				self::record_failure(
					$failures,
					'user-request-key.missing-request-case',
					$case,
					array( 'actual' => self::describe_call( $actual ) )
				);
			}
		}

		return self::row(
			$ctx,
			'privacy.user-request-key.missing-request-fails-closed',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'key'      => self::describe_string( $key ),
				'failures' => $failures,
			)
		);
	}

	private static function check_user_request_key_expiration_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$now      = time();
		$key      = 'expiration|' . self::random_string( $ctx->fork( 'expiration-key' ), 24 );
		$hash     = \wp_fast_hash( $key );
		$post     = self::post_record(
			array(
				'ID'                => 91101,
				'post_title'        => 'expiration@example.test',
				'post_name'         => 'export_personal_data',
				'post_status'       => 'request-pending',
				'post_content'      => '{"expiration":"filter"}',
				'post_password'     => $hash,
				'post_modified'     => gmdate( 'Y-m-d H:i:s', $now - 60 ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			)
		);

		self::prime_request_post( $post );
		self::set_post_meta( (int) $post->ID, array() );

		$seen_short = array();
		$short      = static function ( int $expiration ) use ( &$seen_short ): int {
			$seen_short[] = $expiration;
			return 1;
		};
		\add_filter( 'user_request_key_expiration', $short, 10, 1 );
		try {
			$expired = self::call(
				static function () use ( $post, $key ) {
					return \wp_validate_user_request_key( (int) $post->ID, $key );
				}
			);
		} finally {
			\remove_filter( 'user_request_key_expiration', $short, 10 );
		}

		$seen_long = array();
		$long      = static function ( int $expiration ) use ( &$seen_long ): int {
			$seen_long[] = $expiration;
			return 7 * DAY_IN_SECONDS;
		};
		\add_filter( 'user_request_key_expiration', $long, 10, 1 );
		try {
			$valid = self::call(
				static function () use ( $post, $key ) {
					return \wp_validate_user_request_key( (int) $post->ID, $key );
				}
			);
		} finally {
			\remove_filter( 'user_request_key_expiration', $long, 10 );
		}

		$ok = ! $expired['threw']
			&& self::is_error_code( $expired['value'], 'expired_key' )
			&& ! $valid['threw']
			&& true === $valid['value']
			&& array( DAY_IN_SECONDS ) === $seen_short
			&& array( DAY_IN_SECONDS ) === $seen_long
			&& false === \has_filter( 'user_request_key_expiration', $short )
			&& false === \has_filter( 'user_request_key_expiration', $long );

		if ( ! $ok ) {
			self::record_failure(
				$failures,
				'user-request-key.expiration-filter-controls-window',
				array( 'label' => 'expiration-filters' ),
				array(
					'expired'   => self::describe_call( $expired ),
					'valid'     => self::describe_call( $valid ),
					'seenShort' => self::describe_value( $seen_short ),
					'seenLong'  => self::describe_value( $seen_long ),
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.user-request-key.expiration-filter-contract',
			array() === $failures,
			array(
				'requestId' => (int) $post->ID,
				'failures'  => $failures,
			)
		);
	}

	private static function check_confirmation_messages( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'    => 'export',
				'id'       => 91201,
				'action'   => 'export_personal_data',
				'expected' => 'export request',
			),
			array(
				'label'    => 'erase',
				'id'       => 91202,
				'action'   => 'remove_personal_data',
				'expected' => 'erasure request',
			),
			array(
				'label'    => 'unknown',
				'id'       => 91203,
				'action'   => 'custom_' . self::random_string( $ctx->fork( 'unknown-action' ), 10 ),
				'expected' => 'Action has been confirmed.',
			),
		);

		foreach ( $cases as $case ) {
			self::prime_request_post(
				self::post_record(
					array(
						'ID'           => $case['id'],
						'post_title'   => $case['label'] . '@example.test',
						'post_name'    => $case['action'],
						'post_status'  => 'request-confirmed',
						'post_content' => '{"confirmed":"message"}',
					)
				)
			);
		}

		$seen_filter = array();
		$filter      = static function ( string $message, int $request_id ) use ( &$seen_filter ): string {
			$seen_filter[] = array(
				'id'      => $request_id,
				'message' => $message,
			);

			if ( 91202 !== $request_id ) {
				return $message;
			}

			return '<p class="component-fuzz-filtered">' . \esc_html( $message ) . '</p>';
		};
		\add_filter( 'user_request_action_confirmed_message', $filter, 10, 2 );
		try {
			foreach ( $cases as $case ) {
				$actual = self::call(
					static function () use ( $case ) {
						return \_wp_privacy_account_request_confirmed_message( $case['id'] );
					}
				);

				$contains_expected = ! $actual['threw']
					&& is_string( $actual['value'] )
					&& false !== stripos( $actual['value'], $case['expected'] );
				$filtered_erase    = 'erase' !== $case['label']
					|| (
						str_contains( $actual['value'], 'component-fuzz-filtered' )
						&& false === strpos( $actual['value'], '<script' )
					);

				if ( ! $contains_expected || ! $filtered_erase ) {
					self::record_failure(
						$failures,
						'confirmation-message.case-and-filter',
						$case,
						array( 'actual' => self::describe_call( $actual ) )
					);
				}
			}

			$missing = self::call(
				static function () {
					return \_wp_privacy_account_request_confirmed_message( 91919 );
				}
			);
			if ( $missing['threw'] || ! is_string( $missing['value'] ) || ! str_contains( $missing['value'], 'Action has been confirmed.' ) ) {
				self::record_failure(
					$failures,
					'confirmation-message.missing-request-default',
					array( 'label' => 'missing-request' ),
					array( 'actual' => self::describe_call( $missing ) )
				);
			}
		} finally {
			\remove_filter( 'user_request_action_confirmed_message', $filter, 10 );
		}

		if ( 4 !== count( $seen_filter ) || false !== \has_filter( 'user_request_action_confirmed_message', $filter ) ) {
			self::record_failure(
				$failures,
				'confirmation-message.filter-locality',
				array( 'label' => 'filter-locality' ),
				array(
					'seenFilter' => self::describe_value( $seen_filter ),
					'hasFilter'  => \has_filter( 'user_request_action_confirmed_message', $filter ),
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.confirmation-message.known-unknown-filtered',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_export_group_html( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$group_id    = 'group<' . self::random_string( $ctx->fork( 'group-id' ), 10 ) . '>';
		$group_label = 'Profile <Export> & "Unicode" ' . self::random_string( $ctx->fork( 'group-label' ), 8 );
		$description = "Contains <script>alert(1)</script> & personal data \xC3\xA9.";
		$url_value   = 'https://example.test/privacy?name=' . rawurlencode( self::random_string( $ctx->fork( 'url' ), 12 ) );
		$group_data  = array(
			'group_label'       => $group_label,
			'group_description' => $description,
			'items'             => array(
				'item-1' => array(
					array(
						'name'  => 'Display <Name>',
						'value' => '<img src=x onerror=alert(1)>Alice & Bob',
					),
					array(
						'name'  => 'Profile URL',
						'value' => $url_value,
					),
				),
				'item-2' => array(
					array(
						'name'  => 'Notes & "HTML"',
						'value' => '<strong>allowed</strong><script>alert(1)</script><a href="javascript:alert(1)">bad</a>',
					),
				),
			),
		);

		$html = self::call(
			static function () use ( $group_data, $group_id ) {
				return \wp_privacy_generate_personal_data_export_group_html( $group_data, $group_id, 2 );
			}
		);

		if ( $html['threw'] || ! is_string( $html['value'] ) ) {
			return self::row(
				$ctx,
				'privacy.export-group-html.escapes-and-preserves-structure',
				false,
				array(
					'call' => self::describe_call( $html ),
				)
			);
		}

		$value           = $html['value'];
		$expected_id     = \sanitize_title_with_dashes( $group_label . '-' . $group_id );
		$total_rows      = 0;
		$escaped_ths     = array();
		$dangerous_terms = array( '<script', '<img', 'onerror', 'javascript:' );
		foreach ( $group_data['items'] as $item ) {
			foreach ( $item as $datum ) {
				++$total_rows;
				$escaped_ths[] = \esc_html( $datum['name'] );
			}
		}

		foreach ( $dangerous_terms as $needle ) {
			if ( false !== stripos( $value, $needle ) ) {
				self::record_failure(
					$failures,
					'export-group-html.dangerous-html-removed',
					array( 'label' => $needle ),
					array( 'html' => self::describe_string( $value ) )
				);
			}
		}

		$structure_ok = str_contains( $value, '<h2 id="' . \esc_attr( $expected_id ) . '">' )
			&& str_contains( $value, \esc_html( $group_label ) )
			&& str_contains( $value, \esc_html( $description ) )
			&& count( $group_data['items'] ) === substr_count( $value, '<table>' )
			&& $total_rows === substr_count( $value, '<tr>' )
			&& str_contains( $value, '<span class="count">(2)</span>' )
			&& str_contains( $value, '<div class="return-to-top">' )
			&& str_contains( $value, '<a href="' . \esc_url( $url_value ) . '">' . \esc_html( $url_value ) . '</a>' );

		foreach ( $escaped_ths as $escaped_th ) {
			$structure_ok = $structure_ok && str_contains( $value, '<th>' . $escaped_th . '</th>' );
		}

		if ( ! $structure_ok ) {
			self::record_failure(
				$failures,
				'export-group-html.structure-and-escaping',
				array( 'label' => 'group-html' ),
				array(
					'expectedId' => $expected_id,
					'rows'       => $total_rows,
					'tables'     => count( $group_data['items'] ),
					'html'       => self::describe_string( $value ),
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.export-group-html.escapes-and-preserves-structure',
			array() === $failures,
			array(
				'expectedId' => $expected_id,
				'htmlBytes'  => strlen( $value ),
				'failures'   => $failures,
			)
		);
	}

	private static function check_registry_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::$exporters = array(
			'alpha-exporter' => array(
				'exporter_friendly_name' => 'Alpha Exporter ' . $ctx->identifier( 3, 7 ),
				'callback'               => '__return_empty_array',
			),
			'unicode-exporter' => array(
				'exporter_friendly_name' => "Unicode Exporter \xC3\xA9",
				'callback'               => '__return_empty_array',
			),
		);
		self::$erasers   = array(
			'alpha-eraser' => array(
				'eraser_friendly_name' => 'Alpha Eraser ' . $ctx->identifier( 3, 7 ),
				'callback'             => '__return_empty_array',
			),
			'unicode-eraser' => array(
				'eraser_friendly_name' => "Unicode Eraser \xE2\x98\x83",
				'callback'             => '__return_empty_array',
			),
		);

		$seen_exporters = array();
		$late_exporter  = static function ( array $exporters ) use ( &$seen_exporters ): array {
			$seen_exporters[]          = array_keys( $exporters );
			$exporters['late-export'] = array(
				'exporter_friendly_name' => 'Late Exporter',
				'callback'               => '__return_empty_array',
			);
			return $exporters;
		};

		$seen_erasers = array();
		$late_eraser  = static function ( array $erasers ) use ( &$seen_erasers ): array {
			$seen_erasers[]         = array_keys( $erasers );
			$erasers['late-erase'] = array(
				'eraser_friendly_name' => 'Late Eraser',
				'callback'             => '__return_empty_array',
			);
			return $erasers;
		};

		\add_filter( 'wp_privacy_personal_data_exporters', $late_exporter, 20, 1 );
		\add_filter( 'wp_privacy_personal_data_erasers', $late_eraser, 20, 1 );
		try {
			$exporters = \apply_filters( 'wp_privacy_personal_data_exporters', array( 'incoming' => array() ) );
			$erasers   = \apply_filters( 'wp_privacy_personal_data_erasers', array( 'incoming' => array() ) );
		} finally {
			\remove_filter( 'wp_privacy_personal_data_exporters', $late_exporter, 20 );
			\remove_filter( 'wp_privacy_personal_data_erasers', $late_eraser, 20 );
		}

		$exporters_ok = isset( $exporters['alpha-exporter'], $exporters['unicode-exporter'], $exporters['late-export'] )
			&& ! isset( $exporters['incoming'] )
			&& array( array( 'alpha-exporter', 'unicode-exporter' ) ) === $seen_exporters
			&& is_callable( $exporters['alpha-exporter']['callback'] );
		$erasers_ok   = isset( $erasers['alpha-eraser'], $erasers['unicode-eraser'], $erasers['late-erase'] )
			&& ! isset( $erasers['incoming'] )
			&& array( array( 'alpha-eraser', 'unicode-eraser' ) ) === $seen_erasers
			&& is_callable( $erasers['unicode-eraser']['callback'] );
		$filters_gone = false === \has_filter( 'wp_privacy_personal_data_exporters', $late_exporter )
			&& false === \has_filter( 'wp_privacy_personal_data_erasers', $late_eraser );

		if ( ! $exporters_ok || ! $erasers_ok || ! $filters_gone ) {
			self::record_failure(
				$failures,
				'privacy-registry.filters-shape-order-and-locality',
				array( 'label' => 'registry-filters' ),
				array(
					'exporters'     => self::describe_value( $exporters ),
					'erasers'       => self::describe_value( $erasers ),
					'seenExporters' => self::describe_value( $seen_exporters ),
					'seenErasers'   => self::describe_value( $seen_erasers ),
					'filtersGone'   => $filters_gone,
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.registry-filters.exporter-eraser-shape-and-locality',
			array() === $failures,
			array(
				'exporterKeys' => array_keys( $exporters ),
				'eraserKeys'   => array_keys( $erasers ),
				'failures'     => $failures,
			)
		);
	}

	private static function check_comment_privacy_callbacks( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::wpdb_stub_available() ) {
			return self::skip(
				$ctx,
				'privacy.comments.exporter-eraser-callback-contracts',
				'The wpdb content stub is unavailable for built-in comment privacy callback coverage.'
			);
		}

		$failures = array();
		$email    = 'privacy-comment-' . strtolower( $ctx->identifier( 4, 8 ) ) . '@example.test';
		$had_wp_rewrite = array_key_exists( 'wp_rewrite', $GLOBALS );
		$wp_rewrite     = $GLOBALS['wp_rewrite'] ?? null;

		self::reset_db_content();
		try {
			if ( ! isset( $GLOBALS['wp_rewrite'] ) || ! $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
				$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			}

			$post = self::post_record(
				array(
					'ID'          => 93500 + $ctx->int( 0, 199 ),
					'post_name'   => 'privacy-comment-fixture-' . strtolower( $ctx->identifier( 4, 8 ) ),
					'post_title'  => 'Privacy Comment Fixture',
					'post_status' => 'publish',
					'post_type'   => 'post',
					'guid'        => 'https://example.test/privacy-comment-fixture',
				)
			);
			self::seed_db_request_post( $post );

			$target_comment_id = self::insert_privacy_comment(
				array(
					'comment_post_ID'      => (int) $post->ID,
					'comment_author'       => 'Alice <Exporter> ' . $ctx->identifier( 3, 6 ),
					'comment_author_email' => $email,
					'comment_author_url'   => 'https://example.test/profile?x=' . rawurlencode( self::random_string( $ctx->fork( 'url' ), 8 ) ),
					'comment_author_IP'    => '203.0.113.' . $ctx->int( 1, 254 ),
					'comment_agent'        => 'ComponentFuzz/' . $ctx->int( 10, 99 ),
					'comment_content'      => 'Export me <script>alert(1)</script> & keep text ' . self::random_string( $ctx->fork( 'content' ), 10 ),
					'comment_approved'     => '0',
					'comment_date'         => '2026-06-25 10:00:00',
					'comment_date_gmt'     => '2026-06-25 10:00:00',
					'user_id'              => 1,
				)
			);
			$retained_comment_id = self::insert_privacy_comment(
				array(
					'comment_post_ID'      => (int) $post->ID,
					'comment_author'       => 'Retained Author',
					'comment_author_email' => $email,
					'comment_author_url'   => 'https://example.test/retained',
					'comment_author_IP'    => '198.51.100.' . $ctx->int( 1, 254 ),
					'comment_agent'        => 'RetainedAgent',
					'comment_content'      => 'Retained comment content',
					'comment_approved'     => '0',
					'user_id'              => 1,
				)
			);
			$other_comment_id = self::insert_privacy_comment(
				array(
					'comment_post_ID'      => (int) $post->ID,
					'comment_author'       => 'Other Author',
					'comment_author_email' => 'other-' . strtolower( $ctx->identifier( 3, 6 ) ) . '@example.test',
					'comment_content'      => 'Other comment content',
					'comment_author_IP'    => '192.0.2.' . $ctx->int( 1, 254 ),
				)
			);

			$exporters = \wp_register_comment_personal_data_exporter(
				array(
					'existing-exporter' => array(
						'exporter_friendly_name' => 'Existing Exporter',
						'callback'               => '__return_empty_array',
					),
				)
			);
			$erasers   = \wp_register_comment_personal_data_eraser(
				array(
					'existing-eraser' => array(
						'eraser_friendly_name' => 'Existing Eraser',
						'callback'             => '__return_empty_array',
					),
				)
			);

			$export = \wp_comments_personal_data_exporter( $email, 1 );
			$empty  = \wp_comments_personal_data_exporter( 'missing-' . $email, 1 );

			$export_ok = is_array( $export )
				&& true === ( $export['done'] ?? null )
				&& isset( $export['data'] )
				&& is_array( $export['data'] )
				&& 2 === count( $export['data'] )
				&& self::comment_export_item_ok( $export['data'][0] ?? array(), $target_comment_id, $email )
				&& self::comment_export_item_ok( $export['data'][1] ?? array(), $retained_comment_id, $email )
				&& is_array( $empty )
				&& true === ( $empty['done'] ?? null )
				&& array() === ( $empty['data'] ?? null );
			$registry_ok = isset( $exporters['existing-exporter'], $exporters['wordpress-comments'], $erasers['existing-eraser'], $erasers['wordpress-comments'] )
				&& 'wp_comments_personal_data_exporter' === ( $exporters['wordpress-comments']['callback'] ?? null )
				&& 'wp_comments_personal_data_eraser' === ( $erasers['wordpress-comments']['callback'] ?? null );

			if ( ! $export_ok || ! $registry_ok ) {
				self::record_failure(
					$failures,
					'comments-privacy.exporter-registration-and-payload-shape',
					array( 'label' => 'exporter' ),
					array(
						'exporters' => self::describe_value( $exporters ),
						'erasers'   => self::describe_value( $erasers ),
						'export'    => self::describe_value( $export ),
						'empty'     => self::describe_value( $empty ),
					)
				);
			}

			$filter_calls = array();
			$retain       = static function ( $anon_message, \WP_Comment $comment, array $anonymized_comment ) use ( &$filter_calls, $retained_comment_id ) {
				$filter_calls[] = array(
					'id'         => (int) $comment->comment_ID,
					'message'    => $anon_message,
					'anonymized' => $anonymized_comment,
				);

				if ( (int) $comment->comment_ID === $retained_comment_id ) {
					return 'Retained <component-fuzz>';
				}

				return $anon_message;
			};
			\add_filter( 'wp_anonymize_comment', $retain, 10, 3 );
			try {
				$erased = \wp_comments_personal_data_eraser( $email, 1 );
			} finally {
				\remove_filter( 'wp_anonymize_comment', $retain, 10 );
			}

			$target_after   = \get_comment( $target_comment_id );
			$retained_after = \get_comment( $retained_comment_id );
			$other_after    = \get_comment( $other_comment_id );
			$eraser_ok      = is_array( $erased )
				&& true === ( $erased['items_removed'] ?? null )
				&& true === ( $erased['items_retained'] ?? null )
				&& true === ( $erased['done'] ?? null )
				&& array( 'Retained &lt;component-fuzz&gt;' ) === ( $erased['messages'] ?? null )
				&& $target_after instanceof \WP_Comment
				&& 'Anonymous' === $target_after->comment_author
				&& '' === $target_after->comment_author_email
				&& '' === $target_after->comment_author_url
				&& 0 === (int) $target_after->user_id
				&& str_ends_with( $target_after->comment_author_IP, '.0' )
				&& $retained_after instanceof \WP_Comment
				&& 'Retained Author' === $retained_after->comment_author
				&& $email === $retained_after->comment_author_email
				&& $other_after instanceof \WP_Comment
				&& 'Other Author' === $other_after->comment_author
				&& 2 === count( $filter_calls )
				&& false === \has_filter( 'wp_anonymize_comment', $retain );

			if ( ! $eraser_ok ) {
				self::record_failure(
					$failures,
					'comments-privacy.eraser-anonymizes-matching-comments-and-reports-retained',
					array( 'label' => 'eraser' ),
					array(
						'erased'        => self::describe_value( $erased ),
						'targetAfter'   => self::describe_value( $target_after ),
						'retainedAfter' => self::describe_value( $retained_after ),
						'otherAfter'    => self::describe_value( $other_after ),
						'filterCalls'   => self::describe_value( $filter_calls ),
						'hasFilter'     => \has_filter( 'wp_anonymize_comment', $retain ),
					)
				);
			}
		} finally {
			self::reset_db_content();
			if ( $had_wp_rewrite ) {
				$GLOBALS['wp_rewrite'] = $wp_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}
		}

		return self::row(
			$ctx,
			'privacy.comments.exporter-eraser-callback-contracts',
			array() === $failures,
			array(
				'email'    => self::describe_string( $email ),
				'failures' => $failures,
			)
		);
	}

	private static function check_builtin_user_media_exporters( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::wpdb_stub_available() ) {
			return self::skip(
				$ctx,
				'privacy.builtin-exporters.user-media-payloads',
				'The wpdb content stub is unavailable for built-in user and media exporter coverage.'
			);
		}

		$failures = array();
		$token    = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 5, 10 ) ) );
		$token    = '' === $token ? 'privacy' . abs( $ctx->seed() % 10000 ) : $token;

		$user_id       = 93600 + $ctx->int( 0, 199 );
		$other_user_id = $user_id + 1000;
		$email         = 'privacy-user-' . $token . '@example.test';
		$login         = 'privacy_' . $token;
		$profile_note  = 'profile-note-' . $token . ' <script>alert(1)</script>';
		$session_key   = 'component_' . $token;
		$attachment_base = 94000 + ( $ctx->int( 0, 100 ) * 100 );
		$attachment_ids  = array();

		$profile_filter_calls = array();
		$reserved_names_seen  = array();
		$profile_filter       = static function ( array $items, \WP_User $user, array $reserved_names ) use ( &$profile_filter_calls, &$reserved_names_seen, $profile_note, $user_id ): array {
			$profile_filter_calls[] = (int) $user->ID;
			$reserved_names_seen[]  = $reserved_names;

			$items[] = array(
				'name'  => 'Component Fuzz Profile Note',
				'value' => $profile_note,
			);
			$items[] = array(
				'name'  => 'User Email',
				'value' => 'reserved-profile-value-' . $user_id,
			);

			return $items;
		};
		$suppress_doing_it_wrong = static fn() => false;

		$attachment_url_calls = array();
		$attachment_url_filter = static function ( $url, int $post_id ) use ( &$attachment_url_calls, &$attachment_ids, $token ) {
			$attachment_url_calls[] = $post_id;

			if ( in_array( $post_id, $attachment_ids, true ) ) {
				return 'https://media.example.test/' . $token . '/attachment-' . $post_id . '.jpg?unsafe=<script>';
			}

			return $url;
		};

		self::reset_db_content();
		try {
			$wpdb = $GLOBALS['wpdb'];

			$wpdb->insert(
				$wpdb->users,
				array(
					'ID'              => $user_id,
					'user_login'      => $login,
					'user_pass'       => '$P$ComponentFuzzPrivacyUser',
					'user_nicename'   => 'privacy-nice-' . $token,
					'user_email'      => $email,
					'user_url'        => 'https://example.test/users/' . rawurlencode( $token ) . '?q=<script>',
					'user_registered' => '2026-06-29 12:34:56',
					'display_name'    => 'Privacy User ' . $token,
					'user_status'     => 0,
				)
			);
			$wpdb->insert(
				$wpdb->users,
				array(
					'ID'              => $other_user_id,
					'user_login'      => 'other_' . $token,
					'user_pass'       => '$P$ComponentFuzzOtherUser',
					'user_nicename'   => 'other-privacy-' . $token,
					'user_email'      => 'other-privacy-' . $token . '@example.test',
					'user_registered' => '2026-06-29 13:00:00',
					'display_name'    => 'Other Privacy User',
					'user_status'     => 0,
				)
			);

			foreach (
				array(
					'nickname'                  => 'Nick ' . $token,
					'first_name'                => 'MetaFirst ' . $token,
					'last_name'                 => 'MetaLast ' . $token,
					'description'               => 'Meta description ' . $token,
					'community-events-location' => array(
						'description' => 'Barcelona ' . $token,
						'country'     => 'ES',
						'latitude'    => '41.3874',
						'longitude'   => '2.1686',
						'ip'          => '198.51.100.' . $ctx->int( 1, 254 ),
					),
					'session_tokens'            => array(
						$session_key => array(
							'expiration' => 1780000000 + $ctx->int( 0, 1000 ),
							'ip'         => '203.0.113.' . $ctx->int( 1, 254 ),
							'ua'         => 'ComponentFuzz/' . $ctx->int( 10, 99 ),
							'login'      => 1770000000 + $ctx->int( 0, 1000 ),
						),
					),
				) as $meta_key => $meta_value
			) {
				$wpdb->insert(
					$wpdb->usermeta,
					array(
						'user_id'    => $user_id,
						'meta_key'   => $meta_key,
						'meta_value' => \maybe_serialize( $meta_value ),
					)
				);
			}
			\clean_user_cache( $user_id );
			\wp_cache_delete( $user_id, 'user_meta' );

			$exporters = \wp_register_user_personal_data_exporter(
				array(
					'existing-exporter' => array(
						'exporter_friendly_name' => 'Existing Exporter',
						'callback'               => '__return_empty_array',
					),
				)
			);
			$exporters = \wp_register_media_personal_data_exporter( $exporters );
			$registry_ok = isset( $exporters['existing-exporter'], $exporters['wordpress-user'], $exporters['wordpress-media'] )
				&& 'WordPress User' === ( $exporters['wordpress-user']['exporter_friendly_name'] ?? null )
				&& 'wp_user_personal_data_exporter' === ( $exporters['wordpress-user']['callback'] ?? null )
				&& 'WordPress Media' === ( $exporters['wordpress-media']['exporter_friendly_name'] ?? null )
				&& 'wp_media_personal_data_exporter' === ( $exporters['wordpress-media']['callback'] ?? null );

			\add_filter( 'wp_privacy_additional_user_profile_data', $profile_filter, 10, 3 );
			\add_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10, 4 );
			try {
				$user_export  = \wp_user_personal_data_exporter( " \t" . strtoupper( $email ) . "\n" );
				$missing_user = \wp_user_personal_data_exporter( 'missing-' . $email );
			} finally {
				\remove_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10 );
				\remove_filter( 'wp_privacy_additional_user_profile_data', $profile_filter, 10 );
			}

			$user_item     = self::find_export_item( (array) ( $user_export['data'] ?? array() ), 'user', 'user-' . $user_id );
			$location_item = self::find_export_item( (array) ( $user_export['data'] ?? array() ), 'community-events-location', 'community-events-location-' . $user_id );
			$session_item  = self::find_export_item( (array) ( $user_export['data'] ?? array() ), 'session-tokens', 'session-tokens-' . $user_id . '-' . $session_key );

			$user_fields     = self::export_item_fields( $user_item );
			$location_fields = self::export_item_fields( $location_item );
			$session_fields  = self::export_item_fields( $session_item );
			$field_counts    = array_count_values(
				array_values(
					array_filter(
						array_map(
							static fn( $datum ) => is_array( $datum ) ? ( $datum['name'] ?? null ) : null,
							(array) ( $user_item['data'] ?? array() )
						),
						'is_string'
					)
				)
			);
			$user_html = is_array( $user_item )
				? \wp_privacy_generate_personal_data_export_group_html(
					array(
						'group_label'       => $user_item['group_label'] ?? 'User',
						'group_description' => $user_item['group_description'] ?? '',
						'items'             => array(
							$user_item['item_id'] ?? 'user' => $user_item['data'] ?? array(),
						),
					),
					'user',
					1
				)
				: '';

			$user_ok = is_array( $user_export )
				&& true === ( $user_export['done'] ?? null )
				&& array() === ( $missing_user['data'] ?? null )
				&& true === ( $missing_user['done'] ?? null )
				&& $email === ( $user_fields['User Email'] ?? null )
				&& $login === ( $user_fields['User Login Name'] ?? null )
				&& 'MetaFirst ' . $token === ( $user_fields['User First Name'] ?? null )
				&& 'MetaLast ' . $token === ( $user_fields['User Last Name'] ?? null )
				&& 'Meta description ' . $token === ( $user_fields['User Description'] ?? null )
				&& $profile_note === ( $user_fields['Component Fuzz Profile Note'] ?? null )
				&& 1 === ( $field_counts['User Email'] ?? 0 )
				&& 'Barcelona ' . $token === ( $location_fields['City'] ?? null )
				&& 'ES' === ( $location_fields['Country'] ?? null )
				&& isset( $location_fields['Latitude'], $location_fields['Longitude'], $location_fields['IP'] )
				&& isset( $session_fields['Expiration'], $session_fields['IP'], $session_fields['User Agent'], $session_fields['Last Login'] )
				&& 1 === count( $profile_filter_calls )
				&& $user_id === ( $profile_filter_calls[0] ?? null )
				&& isset( $reserved_names_seen[0] )
				&& in_array( 'User Email', $reserved_names_seen[0], true )
				&& false === \has_filter( 'wp_privacy_additional_user_profile_data', $profile_filter )
				&& false === \has_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong )
				&& is_string( $user_html )
				&& false === stripos( $user_html, '<script' )
				&& str_contains( $user_html, 'Component Fuzz Profile Note' )
				&& str_contains( $user_html, 'alert(1)' );

			if ( ! $registry_ok || ! $user_ok ) {
				self::record_failure(
					$failures,
					'builtin-exporters.user-registry-payload-filter-and-html-contracts',
					array( 'label' => 'user-exporter' ),
					array(
						'exporters'           => self::describe_value( $exporters ),
						'userExport'          => self::describe_value( $user_export ),
						'missingUser'         => self::describe_value( $missing_user ),
						'userFields'          => self::describe_value( $user_fields ),
						'locationFields'      => self::describe_value( $location_fields ),
						'sessionFields'       => self::describe_value( $session_fields ),
						'profileFilterCalls'  => self::describe_value( $profile_filter_calls ),
						'reservedNamesSeen'   => self::describe_value( $reserved_names_seen ),
						'userHtml'            => self::describe_string( $user_html ),
						'registryOk'          => $registry_ok,
						'userOk'              => $user_ok,
					)
				);
			}

			for ( $i = 0; $i < 51; $i++ ) {
				$attachment_id     = $attachment_base + $i;
				$attachment_ids[]  = $attachment_id;
				self::seed_db_request_post(
					self::post_record(
						array(
							'ID'             => $attachment_id,
							'post_author'    => $user_id,
							'post_name'      => 'privacy-media-' . $token . '-' . $i,
							'post_title'     => 'Privacy Media ' . $i,
							'post_status'    => 0 === $i % 2 ? 'inherit' : 'private',
							'post_type'      => 'attachment',
							'post_mime_type' => 'image/jpeg',
							'guid'           => 'https://media.example.test/guid/' . $attachment_id . '.jpg',
						)
					)
				);
			}
			self::seed_db_request_post(
				self::post_record(
					array(
						'ID'             => $attachment_base + 1000,
						'post_author'    => $other_user_id,
						'post_name'      => 'privacy-other-author-media-' . $token,
						'post_status'    => 'inherit',
						'post_type'      => 'attachment',
						'post_mime_type' => 'image/jpeg',
					)
				)
			);
			self::seed_db_request_post(
				self::post_record(
					array(
						'ID'          => $attachment_base + 1001,
						'post_author' => $user_id,
						'post_name'   => 'privacy-non-attachment-' . $token,
						'post_status' => 'publish',
						'post_type'   => 'post',
					)
				)
			);

			\add_filter( 'wp_get_attachment_url', $attachment_url_filter, 10, 2 );
			try {
				$media_page_1  = \wp_media_personal_data_exporter( $email, 1 );
				$media_page_2  = \wp_media_personal_data_exporter( $email, 2 );
				$media_page_3  = \wp_media_personal_data_exporter( $email, 3 );
				$missing_media = \wp_media_personal_data_exporter( 'missing-' . $email, 1 );
			} finally {
				\remove_filter( 'wp_get_attachment_url', $attachment_url_filter, 10 );
			}

			$page_1_items = (array) ( $media_page_1['data'] ?? array() );
			$page_2_items = (array) ( $media_page_2['data'] ?? array() );
			$page_3_items = (array) ( $media_page_3['data'] ?? array() );
			$page_1_first = $page_1_items[0] ?? null;
			$page_1_last  = $page_1_items[49] ?? null;
			$page_2_only  = $page_2_items[0] ?? null;
			$page_1_ids   = self::export_item_ids( $page_1_items );
			$page_2_ids   = self::export_item_ids( $page_2_items );

			$media_ok = is_array( $media_page_1 )
				&& false === ( $media_page_1['done'] ?? null )
				&& 50 === count( $page_1_items )
				&& true === ( $media_page_2['done'] ?? null )
				&& 1 === count( $page_2_items )
				&& true === ( $media_page_3['done'] ?? null )
				&& array() === $page_3_items
				&& true === ( $missing_media['done'] ?? null )
				&& array() === ( $missing_media['data'] ?? null )
				&& 'post-' . $attachment_ids[0] === ( $page_1_first['item_id'] ?? null )
				&& 'post-' . $attachment_ids[49] === ( $page_1_last['item_id'] ?? null )
				&& 'post-' . $attachment_ids[50] === ( $page_2_only['item_id'] ?? null )
				&& 'media' === ( $page_1_first['group_id'] ?? null )
				&& 'https://media.example.test/' . $token . '/attachment-' . $attachment_ids[0] . '.jpg?unsafe=<script>' === ( self::export_item_fields( $page_1_first )['URL'] ?? null )
				&& 'https://media.example.test/' . $token . '/attachment-' . $attachment_ids[50] . '.jpg?unsafe=<script>' === ( self::export_item_fields( $page_2_only )['URL'] ?? null )
				&& range( $attachment_ids[0], $attachment_ids[49] ) === $page_1_ids
				&& array( $attachment_ids[50] ) === $page_2_ids
				&& false === \has_filter( 'wp_get_attachment_url', $attachment_url_filter );

			if ( ! $media_ok ) {
				self::record_failure(
					$failures,
					'builtin-exporters.media-pagination-filtering-and-url-contracts',
					array( 'label' => 'media-exporter' ),
					array(
						'page1'              => self::describe_value( $media_page_1 ),
						'page2'              => self::describe_value( $media_page_2 ),
						'page3'              => self::describe_value( $media_page_3 ),
						'missingMedia'       => self::describe_value( $missing_media ),
						'page1Ids'           => self::describe_value( $page_1_ids ),
						'page2Ids'           => self::describe_value( $page_2_ids ),
						'attachmentUrlCalls' => self::describe_value( $attachment_url_calls ),
					)
				);
			}
		} finally {
			\remove_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10 );
			\remove_filter( 'wp_privacy_additional_user_profile_data', $profile_filter, 10 );
			\remove_filter( 'wp_get_attachment_url', $attachment_url_filter, 10 );
			\clean_user_cache( $user_id );
			\clean_user_cache( $other_user_id );
			\wp_cache_delete( $user_id, 'user_meta' );
			\wp_cache_delete( $other_user_id, 'user_meta' );
			foreach ( $attachment_ids as $attachment_id ) {
				\clean_post_cache( $attachment_id );
			}
			self::reset_db_content();
		}

		return self::row(
			$ctx,
			'privacy.builtin-exporters.user-media-payloads',
			array() === $failures,
			array(
				'userId'          => $user_id,
				'attachmentCount' => count( $attachment_ids ),
				'failures'        => $failures,
			)
		);
	}

	private static function check_export_processor( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$malformed = array(
			array( 'label' => 'non-array', 'response' => 'not an array' ),
			array( 'label' => 'missing-done', 'response' => array( 'data' => array() ) ),
			array( 'label' => 'missing-data', 'response' => array( 'done' => false ) ),
			array( 'label' => 'data-not-array', 'response' => array( 'done' => false, 'data' => 'bad' ) ),
		);

		foreach ( $malformed as $case ) {
			$before_actions = self::$export_file_actions;
			$actual         = self::call(
				static function () use ( $case ) {
					return \wp_privacy_process_personal_data_export_page( $case['response'], 1, 'person@example.test', 1, 0, false, 'bad-shape' );
				}
			);

			if ( $actual['threw'] || $actual['value'] !== $case['response'] || $before_actions !== self::$export_file_actions ) {
				self::record_failure(
					$failures,
					'export-processor.malformed-response-passthrough',
					$case,
					array(
						'actual'  => self::describe_call( $actual ),
						'actions' => self::describe_value( self::$export_file_actions ),
					)
				);
			}
		}

		self::$exporters = self::processor_exporters();
		$request_id      = 92001;
		self::prime_privacy_request( $request_id, 'export_personal_data', 'request-confirmed' );
		self::set_post_meta(
			$request_id,
			array(
				'_export_file_name' => 'component-fuzz-export.zip',
			)
		);

		$page_one = array(
			'done' => false,
			'data' => array(
				self::export_datum( 'profile', 'Profile', 'profile-1', 'Name', 'Alice <script>x</script>' ),
			),
		);
		$page_one_result = self::call(
			static function () use ( $page_one, $request_id ) {
				return \wp_privacy_process_personal_data_export_page( $page_one, 1, 'person@example.test', 1, $request_id, false, 'component-one' );
			}
		);
		$raw_after_page_one = \get_post_meta( $request_id, '_export_data_raw', true );

		if (
			$page_one_result['threw']
			|| $page_one_result['value'] !== $page_one
			|| count( $raw_after_page_one ) !== 1
			|| array() !== self::$export_file_actions
		) {
			self::record_failure(
				$failures,
				'export-processor.partial-page-accumulates-without-final-side-effects',
				array( 'label' => 'page-one' ),
				array(
					'result'     => self::describe_call( $page_one_result ),
					'rawMeta'    => self::describe_value( $raw_after_page_one ),
					'fileAction' => self::describe_value( self::$export_file_actions ),
				)
			);
		}

		$page_two = array(
			'done' => true,
			'data' => array(
				self::export_datum( 'profile', 'Profile', 'profile-1', 'Email', 'person@example.test' ),
				self::export_datum( 'activity', 'Activity', 'login-1', 'IP', '203.0.113.44' ),
			),
		);
		$page_two_result = self::call(
			static function () use ( $page_two, $request_id ) {
				return \wp_privacy_process_personal_data_export_page( $page_two, 2, 'person@example.test', 1, $request_id, false, 'component-two' );
			}
		);

		$expected_url = 'http://example.test/uploads/wp-personal-data-exports/component-fuzz-export.zip';
		$final_ok     = ! $page_two_result['threw']
			&& is_array( $page_two_result['value'] )
			&& true === $page_two_result['value']['done']
			&& $expected_url === ( $page_two_result['value']['url'] ?? null )
			&& '' === \get_post_meta( $request_id, '_export_data_raw', true )
			&& '' === \get_post_meta( $request_id, '_export_data_grouped', true )
			&& 1 === count( self::$export_file_actions )
			&& self::grouped_export_action_ok( self::$export_file_actions[0] ?? array() );

		if ( ! $final_ok ) {
			self::record_failure(
				$failures,
				'export-processor.final-page-groups-data-and-exposes-url',
				array( 'label' => 'page-two' ),
				array(
					'expectedUrl' => $expected_url,
					'result'      => self::describe_call( $page_two_result ),
					'rawMeta'     => self::describe_value( \get_post_meta( $request_id, '_export_data_raw', true ) ),
					'groupedMeta' => self::describe_value( \get_post_meta( $request_id, '_export_data_grouped', true ) ),
					'actions'     => self::describe_value( self::$export_file_actions ),
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.export-processor.shape-done-and-grouping',
			array() === $failures,
			array(
				'exporters' => array_keys( self::$exporters ),
				'actions'   => self::describe_value( self::$export_file_actions ),
				'failures'  => $failures,
			)
		);
	}

	private static function check_export_email_notification( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 5, 10 ) ) );
		$token    = '' === $token ? 'privacy' . abs( $ctx->seed() % 10000 ) : $token;

		$request_id = 92500 + $ctx->int( 1, 99 );
		$request_email = 'privacy-export+' . $token . '@example.com';
		$recipient     = 'privacy-recipient-' . $token . '@example.com';
		$file_name     = 'component-privacy-export-' . $token . '.zip';
		$site_name     = 'Privacy <b>Fuzz</b> & Site';
		$site_url      = 'https://privacy.example.com/base';
		$expected_url  = 'http://example.test/uploads/wp-personal-data-exports/' . $file_name;
		$expiration_seconds = ( 2 + $ctx->int( 0, 4 ) ) * DAY_IN_SECONDS;

		self::prime_request_post(
			self::post_record(
				array(
					'ID'            => $request_id,
					'post_author'   => '0',
					'post_title'    => $request_email,
					'post_name'     => 'export_personal_data',
					'post_status'   => 'request-confirmed',
					'post_content'  => '{"export":"email"}',
					'post_password' => \wp_fast_hash( 'export-email-key-' . $token ),
				)
			)
		);
		self::set_post_meta(
			$request_id,
			array(
				'_export_file_name' => $file_name,
			)
		);

		$mailer_snapshot   = self::snapshot_mailer_state();
		$mailer_restored   = false;
		$to_calls           = array();
		$subject_calls      = array();
		$content_calls      = array();
		$header_calls       = array();
		$succeeded          = array();

		$blogname_filter = static fn() => $site_name;
		$home_filter     = static fn() => $site_url;
		$date_filter     = static fn() => 'Y-m-d';
		$expiration_filter = static function ( int $seconds ) use ( $expiration_seconds ): int {
			unset( $seconds );

			return $expiration_seconds;
		};
		$to_filter       = static function ( string $request_email_arg, \WP_User_Request $request ) use ( &$to_calls, $recipient ): string {
			$to_calls[] = array(
				'requestEmail' => $request_email_arg,
				'request'      => $request,
			);

			return $recipient;
		};
		$subject_filter  = static function ( string $subject, string $sitename, array $email_data ) use ( &$subject_calls, $token ): string {
			$subject_calls[] = array(
				'subject'   => $subject,
				'sitename'  => $sitename,
				'emailData' => $email_data,
			);

			return $subject . ' [' . $token . ']';
		};
		$content_filter  = static function ( string $content, int $request_id_arg, array $email_data ) use ( &$content_calls, $token ): string {
			$content_calls[] = array(
				'content'   => $content,
				'requestId' => $request_id_arg,
				'emailData' => $email_data,
			);

			return 'Export ###LINK### for ###EMAIL### from ###SITENAME### at ###SITEURL### until ###EXPIRATION### token ' . $token;
		};
		$headers_filter  = static function ( $headers, string $subject, string $content, int $request_id_arg, array $email_data ) use ( &$header_calls, $token ): array {
			$header_calls[] = array(
				'headers'   => $headers,
				'subject'   => $subject,
				'content'   => $content,
				'requestId' => $request_id_arg,
				'emailData' => $email_data,
			);

			return array(
				'Content-Type: text/plain; charset=UTF-8',
				'X-Privacy-Token: ' . $token,
			);
		};
		$success_action  = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};

		try {
			PrivacySurfaceMailer::$sent = array();
			$GLOBALS['phpmailer']       = self::new_mailer();
			\PHPMailer\PHPMailer\PHPMailer::$validator = static function ( $email ): bool {
				return (bool) \is_email( $email );
			};

			\add_filter( 'pre_option_blogname', $blogname_filter );
			\add_filter( 'pre_option_home', $home_filter );
			\add_filter( 'pre_option_date_format', $date_filter );
			\add_filter( 'wp_privacy_export_expiration', $expiration_filter, 10, 1 );
			\add_filter( 'wp_privacy_personal_data_email_to', $to_filter, 10, 2 );
			\add_filter( 'wp_privacy_personal_data_email_subject', $subject_filter, 10, 3 );
			\add_filter( 'wp_privacy_personal_data_email_content', $content_filter, 10, 3 );
			\add_filter( 'wp_privacy_personal_data_email_headers', $headers_filter, 10, 5 );
			\add_action( 'wp_mail_succeeded', $success_action );

			$result = self::call(
				static function () use ( $request_id ) {
					return \wp_privacy_send_personal_data_export_email( $request_id );
				}
			);
		} finally {
			\remove_action( 'wp_mail_succeeded', $success_action );
			\remove_filter( 'wp_privacy_personal_data_email_headers', $headers_filter, 10 );
			\remove_filter( 'wp_privacy_personal_data_email_content', $content_filter, 10 );
			\remove_filter( 'wp_privacy_personal_data_email_subject', $subject_filter, 10 );
			\remove_filter( 'wp_privacy_personal_data_email_to', $to_filter, 10 );
			\remove_filter( 'wp_privacy_export_expiration', $expiration_filter, 10 );
			\remove_filter( 'pre_option_date_format', $date_filter );
			\remove_filter( 'pre_option_home', $home_filter );
			\remove_filter( 'pre_option_blogname', $blogname_filter );
			self::restore_mailer_state( $mailer_snapshot );
			$mailer_restored = self::mailer_state_restored( $mailer_snapshot );
		}

		$sent         = PrivacySurfaceMailer::$sent[0] ?? array();
		$succeeded_to = isset( $succeeded[0]['to'] ) ? (array) $succeeded[0]['to'] : array();
		$sent_body    = is_array( $sent ) ? (string) ( $sent['body'] ?? '' ) : '';
		$sent_subject = is_array( $sent ) ? (string) ( $sent['subject'] ?? '' ) : '';
		$to_request   = $to_calls[0]['request'] ?? null;
		$subject_data = $subject_calls[0]['emailData'] ?? array();
		$content_data = $content_calls[0]['emailData'] ?? array();
		$headers_data = $header_calls[0]['emailData'] ?? array();
		$expiration_date = is_string( $content_data['expiration_date'] ?? null )
			? $content_data['expiration_date']
			: '';

		self::collect_failure(
			$failures,
			! $result['threw']
				&& true === $result['value']
				&& 1 === count( PrivacySurfaceMailer::$sent )
				&& 1 === count( $succeeded )
				&& self::addresses_include( $sent['to'] ?? array(), $recipient, '' )
				&& array( $recipient ) === $succeeded_to
				&& $mailer_restored,
			'export-email.wp-mail-success-and-recipient',
			array(
				'result'    => self::describe_call( $result ),
				'sent'      => self::describe_value( $sent ),
				'succeeded' => self::describe_value( $succeeded ),
				'mailerRestored' => $mailer_restored,
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $to_calls )
				&& 1 === count( $subject_calls )
				&& 1 === count( $content_calls )
				&& 1 === count( $header_calls )
				&& $to_request instanceof \WP_User_Request
				&& $request_id === $to_request->ID
				&& $request_email === ( $to_calls[0]['requestEmail'] ?? null )
				&& $recipient === ( $subject_data['message_recipient'] ?? null )
				&& $recipient === ( $content_data['message_recipient'] ?? null )
				&& $recipient === ( $headers_data['message_recipient'] ?? null )
				&& $expected_url === ( $subject_data['export_file_url'] ?? null )
				&& $expected_url === ( $content_data['export_file_url'] ?? null )
				&& $expected_url === ( $headers_data['export_file_url'] ?? null )
				&& $expiration_seconds === ( $subject_data['expiration'] ?? null )
				&& $expiration_seconds === ( $content_data['expiration'] ?? null )
				&& $expiration_seconds === ( $headers_data['expiration'] ?? null ),
			'export-email.filter-data-uses-request-and-recipient',
			array(
				'toCalls'      => self::describe_value( $to_calls ),
				'subjectCalls' => self::describe_value( $subject_calls ),
				'contentCalls' => self::describe_value( $content_calls ),
				'headerCalls'  => self::describe_value( $header_calls ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $sent_subject, '[Privacy <b>Fuzz</b> & Site] Personal Data Export' )
				&& str_contains( $sent_subject, '[' . $token . ']' )
				&& str_contains( $sent_body, $expected_url )
				&& str_contains( $sent_body, $recipient )
				&& str_contains( $sent_body, $site_name )
				&& str_contains( $sent_body, $site_url )
				&& '' !== $expiration_date
				&& str_contains( $sent_body, $expiration_date )
				&& str_contains( $sent_body, 'token ' . $token )
				&& ! str_contains( $sent_body, '###LINK###' )
				&& ! str_contains( $sent_body, '###EMAIL###' )
				&& ! str_contains( $sent_body, '###SITENAME###' )
				&& ! str_contains( $sent_body, '###SITEURL###' )
				&& ! str_contains( $sent_body, '###EXPIRATION###' ),
			'export-email.placeholders-and-subject-rendered',
			array(
				'subject' => self::describe_string( $sent_subject ),
				'body'    => self::describe_string( $sent_body ),
			)
		);

		self::collect_failure(
			$failures,
			'text/plain' === ( $sent['contentType'] ?? null )
				&& 'UTF-8' === ( $sent['charset'] ?? null )
				&& self::custom_headers_include( $sent['customHeaders'] ?? array(), 'X-Privacy-Token', $token )
				&& false === \has_filter( 'wp_privacy_personal_data_email_to', $to_filter )
				&& false === \has_filter( 'wp_privacy_personal_data_email_subject', $subject_filter )
				&& false === \has_filter( 'wp_privacy_personal_data_email_content', $content_filter )
				&& false === \has_filter( 'wp_privacy_personal_data_email_headers', $headers_filter )
				&& false === \has_filter( 'wp_privacy_export_expiration', $expiration_filter )
				&& false === \has_filter( 'wp_mail_succeeded', $success_action ),
			'export-email.headers-and-filter-cleanup',
			array(
				'sent' => self::describe_value( $sent ),
				'filters' => array(
					'to'      => \has_filter( 'wp_privacy_personal_data_email_to', $to_filter ),
					'subject' => \has_filter( 'wp_privacy_personal_data_email_subject', $subject_filter ),
					'content' => \has_filter( 'wp_privacy_personal_data_email_content', $content_filter ),
					'headers' => \has_filter( 'wp_privacy_personal_data_email_headers', $headers_filter ),
					'expiration' => \has_filter( 'wp_privacy_export_expiration', $expiration_filter ),
					'success' => \has_filter( 'wp_mail_succeeded', $success_action ),
				),
			)
		);

		PrivacySurfaceMailer::$sent = array();

		return self::row(
			$ctx,
			'privacy.export-email.notification-filters-and-mailer',
			array() === $failures,
			array(
				'requestId'   => $request_id,
				'recipient'   => $recipient,
				'exportUrl'   => $expected_url,
				'mailCount'   => count( $succeeded ),
				'filterCalls' => array(
					'to'      => count( $to_calls ),
					'subject' => count( $subject_calls ),
					'content' => count( $content_calls ),
					'headers' => count( $header_calls ),
				),
				'failures'    => $failures,
			)
		);
	}

	private static function check_export_email_negative_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 5, 10 ) ) );
		$token    = '' === $token ? 'privacy' . abs( $ctx->seed() % 10000 ) : $token;

		$missing_id    = 92700 + $ctx->int( 1, 49 );
		$wrong_action_id = $missing_id + 100;
		$mail_error_id   = $missing_id + 200;
		$file_name       = 'component-privacy-negative-' . $token . '.zip';

		self::prime_request_post(
			self::post_record(
				array(
					'ID'            => $wrong_action_id,
					'post_author'   => '0',
					'post_title'    => 'privacy-wrong-action-' . $token . '@example.com',
					'post_name'     => 'remove_personal_data',
					'post_status'   => 'request-confirmed',
					'post_content'  => '{"export":"wrong-action"}',
					'post_password' => \wp_fast_hash( 'wrong-action-key-' . $token ),
				)
			)
		);
		self::prime_request_post(
			self::post_record(
				array(
					'ID'            => $mail_error_id,
					'post_author'   => '0',
					'post_title'    => 'privacy-mail-error-' . $token . '@example.com',
					'post_name'     => 'export_personal_data',
					'post_status'   => 'request-confirmed',
					'post_content'  => '{"export":"mail-error"}',
					'post_password' => \wp_fast_hash( 'mail-error-key-' . $token ),
				)
			)
		);
		self::set_post_meta(
			$mail_error_id,
			array(
				'_export_file_name' => $file_name,
			)
		);

		$mailer_snapshot = self::snapshot_mailer_state();
		$mailer_restored = false;
		$succeeded       = array();
		$mail_failed     = array();
		$bad_to_calls    = array();

		$home_filter    = static fn() => 'https://privacy.example.com/base';
		$bad_to_filter = static function ( string $request_email, \WP_User_Request $request ) use ( &$bad_to_calls ): string {
			$bad_to_calls[] = array(
				'requestEmail' => $request_email,
				'request'      => $request,
			);

			return 'not-an-email';
		};
		$success_action = static function ( array $mail_data ) use ( &$succeeded ): void {
			$succeeded[] = $mail_data;
		};
		$failed_action  = static function ( \WP_Error $error ) use ( &$mail_failed ): void {
			$mail_failed[] = $error;
		};

		try {
			PrivacySurfaceMailer::$sent = array();
			$GLOBALS['phpmailer']       = self::new_mailer();
			\PHPMailer\PHPMailer\PHPMailer::$validator = static function ( $email ): bool {
				return (bool) \is_email( $email );
			};

			\add_filter( 'pre_option_home', $home_filter );
			\add_filter( 'wp_privacy_personal_data_email_to', $bad_to_filter, 10, 2 );
			\add_action( 'wp_mail_succeeded', $success_action );
			\add_action( 'wp_mail_failed', $failed_action );

			$missing = self::call(
				static function () use ( $missing_id ) {
					return \wp_privacy_send_personal_data_export_email( $missing_id );
				}
			);
			$wrong_action = self::call(
				static function () use ( $wrong_action_id ) {
					return \wp_privacy_send_personal_data_export_email( $wrong_action_id );
				}
			);
			$mail_error = self::call(
				static function () use ( $mail_error_id ) {
					return \wp_privacy_send_personal_data_export_email( $mail_error_id );
				}
			);
		} finally {
			\remove_action( 'wp_mail_failed', $failed_action );
			\remove_action( 'wp_mail_succeeded', $success_action );
			\remove_filter( 'wp_privacy_personal_data_email_to', $bad_to_filter, 10 );
			\remove_filter( 'pre_option_home', $home_filter );
			self::restore_mailer_state( $mailer_snapshot );
			$mailer_restored = self::mailer_state_restored( $mailer_snapshot );
		}

		self::collect_failure(
			$failures,
			! $missing['threw']
				&& self::is_error_code( $missing['value'], 'invalid_request' )
				&& ! $wrong_action['threw']
				&& self::is_error_code( $wrong_action['value'], 'invalid_request' ),
			'export-email.negative-invalid-request-codes',
			array(
				'missing'     => self::describe_call( $missing ),
				'wrongAction' => self::describe_call( $wrong_action ),
			)
		);

		self::collect_failure(
			$failures,
			! $mail_error['threw']
				&& self::is_error_code( $mail_error['value'], 'privacy_email_error' )
				&& 1 === count( $bad_to_calls )
				&& 1 === count( $mail_failed ),
			'export-email.negative-mail-error-code-and-failure-action',
			array(
				'mailError'  => self::describe_call( $mail_error ),
				'toCalls'    => self::describe_value( $bad_to_calls ),
				'mailFailed' => self::describe_value( $mail_failed ),
			)
		);

		self::collect_failure(
			$failures,
			0 === count( PrivacySurfaceMailer::$sent )
				&& 0 === count( $succeeded )
				&& false === \has_filter( 'wp_privacy_personal_data_email_to', $bad_to_filter )
				&& false === \has_filter( 'wp_mail_succeeded', $success_action )
				&& false === \has_filter( 'wp_mail_failed', $failed_action )
				&& $mailer_restored,
			'export-email.negative-no-send-success-or-leak',
			array(
				'sent'           => self::describe_value( PrivacySurfaceMailer::$sent ),
				'succeeded'      => self::describe_value( $succeeded ),
				'mailerRestored' => $mailer_restored,
				'filters'        => array(
					'to'      => \has_filter( 'wp_privacy_personal_data_email_to', $bad_to_filter ),
					'success' => \has_filter( 'wp_mail_succeeded', $success_action ),
					'failed'  => \has_filter( 'wp_mail_failed', $failed_action ),
				),
			)
		);

		PrivacySurfaceMailer::$sent = array();

		return self::row(
			$ctx,
			'privacy.export-email.negative-paths',
			array() === $failures,
			array(
				'missingRequestId' => $missing_id,
				'wrongActionId'   => $wrong_action_id,
				'mailErrorId'     => $mail_error_id,
				'failedActions'   => count( $mail_failed ),
				'succeeded'       => count( $succeeded ),
				'failures'        => $failures,
			)
		);
	}

	private static function check_erasure_processor( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures                   = array();
		$completed_path_exercised   = false;
		$completed_path_skip_reason = null;

		$malformed = array(
			array( 'label' => 'non-array', 'response' => 'not an array' ),
			array( 'label' => 'missing-done', 'response' => array( 'items_removed' => false, 'items_retained' => false, 'messages' => array() ) ),
			array( 'label' => 'missing-items-removed', 'response' => array( 'done' => false, 'items_retained' => false, 'messages' => array() ) ),
			array( 'label' => 'missing-items-retained', 'response' => array( 'done' => false, 'items_removed' => false, 'messages' => array() ) ),
			array( 'label' => 'missing-messages', 'response' => array( 'done' => false, 'items_removed' => false, 'items_retained' => false ) ),
		);

		foreach ( $malformed as $case ) {
			$actual = self::call(
				static function () use ( $case ) {
					return \wp_privacy_process_personal_data_erasure_page( $case['response'], 1, 'person@example.test', 1, 0 );
				}
			);

			if ( $actual['threw'] || $actual['value'] !== $case['response'] || array() !== self::$erased_actions ) {
				self::record_failure(
					$failures,
					'erasure-processor.malformed-response-passthrough',
					$case,
					array(
						'actual'        => self::describe_call( $actual ),
						'erasedActions' => self::describe_value( self::$erased_actions ),
					)
				);
			}
		}

		self::$erasers = self::processor_erasers();
		$request_id    = 93001;
		self::prime_privacy_request( $request_id, 'remove_personal_data', 'request-confirmed' );

		$done_but_not_last = array(
			'done'           => true,
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array( 'first eraser finished' ),
		);
		$done_but_not_last_result = self::call(
			static function () use ( $done_but_not_last, $request_id ) {
				return \wp_privacy_process_personal_data_erasure_page( $done_but_not_last, 1, 'person@example.test', 1, $request_id );
			}
		);

		$last_but_not_done = array(
			'done'           => false,
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => array( 'second eraser has another page' ),
		);
		$last_but_not_done_result = self::call(
			static function () use ( $last_but_not_done, $request_id ) {
				return \wp_privacy_process_personal_data_erasure_page( $last_but_not_done, 2, 'person@example.test', 2, $request_id );
			}
		);

		$non_final_ok = ! $done_but_not_last_result['threw']
			&& $done_but_not_last_result['value'] === $done_but_not_last
			&& ! $last_but_not_done_result['threw']
			&& $last_but_not_done_result['value'] === $last_but_not_done
			&& array() === self::$erased_actions;

		if ( ! $non_final_ok ) {
			self::record_failure(
				$failures,
				'erasure-processor.done-flag-requires-last-eraser',
				array( 'label' => 'non-final-done-flags' ),
				array(
					'doneButNotLast'   => self::describe_call( $done_but_not_last_result ),
					'lastButNotDone'   => self::describe_call( $last_but_not_done_result ),
					'erasedActions'    => self::describe_value( self::$erased_actions ),
				)
			);
		}

		if ( self::wpdb_stub_available() ) {
			$final_request_id = 93002;
			$final_response   = array(
				'done'           => true,
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array( 'final eraser finished' ),
			);

			self::reset_db_content();
			self::$erased_actions = array();

			try {
				self::seed_db_request_post(
					self::post_record(
						array(
							'ID'                => $final_request_id,
							'post_title'        => 'privacy-final-erasure@example.test',
							'post_name'         => 'remove_personal_data',
							'post_status'       => 'request-confirmed',
							'post_content'      => '{"processor":"privacy-erasure-final"}',
							'post_password'     => \wp_fast_hash( 'final-erasure-key' ),
							'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
							'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
						)
					)
				);
				self::set_post_meta( $final_request_id, array() );

				$final_result    = self::call(
					static function () use ( $final_response, $final_request_id ) {
						return \wp_privacy_process_personal_data_erasure_page( $final_response, 2, 'privacy-final-erasure@example.test', 1, $final_request_id );
					}
				);
				$completed_after = \wp_get_user_request( $final_request_id );
				$completed_at    = \get_post_meta( $final_request_id, '_wp_user_request_completed_timestamp', true );
				$completed_ok    = ! $final_result['threw']
					&& $final_response === $final_result['value']
					&& $completed_after instanceof \WP_User_Request
					&& 'request-completed' === $completed_after->status
					&& 'remove_personal_data' === $completed_after->action_name
					&& 'privacy-final-erasure@example.test' === $completed_after->email
					&& is_int( $completed_at )
					&& $completed_at === $completed_after->completed_timestamp
					&& $completed_at <= time()
					&& $completed_at >= time() - 5
					&& array( $final_request_id ) === self::$erased_actions;
				$completed_path_exercised = true;

				if ( ! $completed_ok ) {
					self::record_failure(
						$failures,
						'erasure-processor.final-done-completes-request-and-fires-action',
						array( 'label' => 'final-done' ),
						array(
							'finalResult'    => self::describe_call( $final_result ),
							'completedAfter' => self::describe_value( $completed_after ),
							'completedAt'    => self::describe_value( $completed_at ),
							'erasedActions'  => self::describe_value( self::$erased_actions ),
						)
					);
				}
			} finally {
				self::reset_db_content();
				\clean_post_cache( $final_request_id );
			}
		} else {
			$completed_path_skip_reason = 'The in-memory wpdb content stub is unavailable for the final erasure completion path.';
		}

		return self::row(
			$ctx,
			'privacy.erasure-processor.shape-nonfinal-and-final-completion',
			array() === $failures,
			array(
				'erasers'                  => array_keys( self::$erasers ),
				'completedPathExercised'   => $completed_path_exercised,
				'completedPathSkipReason'  => $completed_path_skip_reason,
				'erasedActions'            => self::describe_value( self::$erased_actions ),
				'failures'                 => $failures,
			)
		);
	}

	private static function check_anonymization_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$ip_cases = array(
			array(
				'label'    => 'empty',
				'input'    => '',
				'expected' => '0.0.0.0',
			),
			array(
				'label'    => 'ipv4',
				'input'    => '203.0.113.' . $ctx->int( 1, 254 ),
				'expected' => '203.0.113.0',
			),
			array(
				'label'    => 'ipv4-port',
				'input'    => '198.51.100.' . $ctx->int( 1, 254 ) . ':' . $ctx->int( 1024, 9999 ),
				'expected' => '198.51.100.0',
			),
			array(
				'label'    => 'ipv6',
				'input'    => '[2001:db8:abcd:12::' . dechex( $ctx->int( 1, 4095 ) ) . ']:' . $ctx->int( 1024, 9999 ),
				'expected' => '2001:db8:abcd:12::',
			),
			array(
				'label'    => 'ipv4-mapped-ipv6',
				'input'    => '::ffff:192.0.2.' . $ctx->int( 1, 254 ),
				'expected' => '::ffff:192.0.2.0',
			),
			array(
				'label'    => 'malformed-ipv6-bracket',
				'input'    => '[2001:db8::1',
				'expected' => '::',
			),
			array(
				'label'    => 'not-an-ip',
				'input'    => 'not-an-ip-' . substr( hash( 'sha1', (string) $ctx->fork( 'not-ip' )->seed() ), 0, 8 ),
				'expected' => '0.0.0.0',
			),
		);

		foreach ( $ip_cases as $case ) {
			$actual = \wp_privacy_anonymize_ip( $case['input'] );
			if ( $case['expected'] !== $actual ) {
				self::record_failure(
					$failures,
					'anonymize-ip.case',
					$case,
					array(
						'expected' => $case['expected'],
						'actual'   => $actual,
					)
				);
			}
		}

		$data_cases = array(
			'email'    => array( 'input' => 'person+' . $ctx->identifier( 3, 8 ) . '@example.test', 'expected' => 'deleted@site.invalid' ),
			'url'      => array( 'input' => 'https://example.test/path?x=<tag>', 'expected' => 'https://site.invalid' ),
			'ip'       => array( 'input' => '203.0.113.44', 'expected' => '203.0.113.0' ),
			'date'     => array( 'input' => '2026-06-22 11:12:13', 'expected' => '0000-00-00 00:00:00' ),
			'text'     => array( 'input' => '<script>alert(1)</script>', 'expected' => '[deleted]' ),
			'longtext' => array( 'input' => str_repeat( 'content ', 4 ), 'expected' => 'This content was deleted by the author.' ),
			'unknown'  => array( 'input' => 'keep me?', 'expected' => '' ),
		);

		foreach ( $data_cases as $type => $case ) {
			$actual = \wp_privacy_anonymize_data( $type, $case['input'] );
			if ( $case['expected'] !== $actual ) {
				self::record_failure(
					$failures,
					'anonymize-data.default-type',
					array( 'label' => $type ),
					array(
						'input'    => self::describe_string( $case['input'] ),
						'expected' => self::describe_string( $case['expected'] ),
						'actual'   => self::describe_string( (string) $actual ),
					)
				);
			}
		}

		$filter_calls = array();
		$filter       = static function ( string $anonymous, string $type, string $data ) use ( &$filter_calls ): string {
			$filter_calls[] = array(
				'anonymous' => $anonymous,
				'type'      => $type,
				'data'      => $data,
			);

			if ( 'component-custom' !== $type ) {
				return $anonymous;
			}

			return 'filtered:' . sha1( $data );
		};

		\add_filter( 'wp_privacy_anonymize_data', $filter, 10, 3 );
		try {
			$filtered = \wp_privacy_anonymize_data( 'component-custom', self::random_string( $ctx->fork( 'custom' ), 18 ) );
			$email    = \wp_privacy_anonymize_data( 'email', 'person@example.test' );
		} finally {
			\remove_filter( 'wp_privacy_anonymize_data', $filter, 10 );
		}

		if (
			! str_starts_with( $filtered, 'filtered:' )
			|| 'deleted@site.invalid' !== $email
			|| 2 !== count( $filter_calls )
			|| 'component-custom' !== $filter_calls[0]['type']
			|| 'email' !== $filter_calls[1]['type']
			|| false !== \has_filter( 'wp_privacy_anonymize_data', $filter )
		) {
			self::record_failure(
				$failures,
				'anonymize-data.filter-locality',
				array( 'label' => 'filter' ),
				array(
					'filtered'    => self::describe_string( (string) $filtered ),
					'email'       => self::describe_string( (string) $email ),
					'filterCalls' => self::describe_value( $filter_calls ),
					'hasFilter'   => \has_filter( 'wp_privacy_anonymize_data', $filter ),
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.anonymization.ip-data-and-filter-contracts',
			array() === $failures,
			array(
				'ipCases'   => count( $ip_cases ),
				'dataTypes' => array_keys( $data_cases ),
				'failures'  => $failures,
			)
		);
	}

	private static function check_export_paths_and_cleanup( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = substr( sha1( (string) $ctx->seed() . ':' . (string) $ctx->iteration() ), 0, 12 );
		$base_dir = sys_get_temp_dir() . '/component-fuzz-privacy-' . $token;
		$dir      = $base_dir . '/wp-personal-data-exports/';
		$url      = 'https://privacy.example.test/exports/' . $token . '/';

		self::remove_tree( $base_dir );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		$old_file   = $dir . 'export-old.zip';
		$new_file   = $dir . 'export-new.zip';
		$index_file = $dir . 'index.php';
		file_put_contents( $old_file, 'old export' );
		file_put_contents( $new_file, 'new export' );
		file_put_contents( $index_file, '<?php // silence' );
		touch( $old_file, time() - 5 * DAY_IN_SECONDS );
		touch( $new_file, time() - HOUR_IN_SECONDS );
		touch( $index_file, time() - 5 * DAY_IN_SECONDS );
		clearstatcache();

		$seen_dir_filter = array();
		$dir_filter      = static function ( string $exports_dir ) use ( &$seen_dir_filter, $dir ): string {
			$seen_dir_filter[] = $exports_dir;
			return $dir;
		};
		$seen_url_filter = array();
		$url_filter      = static function ( string $exports_url ) use ( &$seen_url_filter, $url ): string {
			$seen_url_filter[] = $exports_url;
			return $url;
		};
		$seen_expiration = array();
		$expiration      = static function ( int $seconds ) use ( &$seen_expiration ): int {
			$seen_expiration[] = $seconds;
			return 2 * DAY_IN_SECONDS;
		};

		\add_filter( 'wp_privacy_exports_dir', $dir_filter, 10, 1 );
		\add_filter( 'wp_privacy_exports_url', $url_filter, 10, 1 );
		\add_filter( 'wp_privacy_export_expiration', $expiration, 10, 1 );
		try {
			$exports_dir = \wp_privacy_exports_dir();
			$exports_url = \wp_privacy_exports_url();
			$cleanup     = self::call(
				static function (): void {
					\wp_privacy_delete_old_export_files();
				}
			);
			clearstatcache();

			$ok = ! $cleanup['threw']
				&& $dir === $exports_dir
				&& $url === $exports_url
				&& ! file_exists( $old_file )
				&& file_exists( $new_file )
				&& file_exists( $index_file )
				&& 2 === count( $seen_dir_filter )
				&& array( 3 * DAY_IN_SECONDS ) === $seen_expiration
				&& array() !== $seen_url_filter;

			if ( ! $ok ) {
				self::record_failure(
					$failures,
					'export-paths.filtered-dir-url-and-expiration-cleanup',
					array( 'label' => 'export-paths-cleanup' ),
					array(
						'exportsDir'      => self::describe_string( (string) $exports_dir ),
						'exportsUrl'      => self::describe_string( (string) $exports_url ),
						'cleanup'         => self::describe_call( $cleanup ),
						'oldExists'       => file_exists( $old_file ),
						'newExists'       => file_exists( $new_file ),
						'indexExists'     => file_exists( $index_file ),
						'seenDirFilter'   => self::describe_value( $seen_dir_filter ),
						'seenUrlFilter'   => self::describe_value( $seen_url_filter ),
						'seenExpiration'  => self::describe_value( $seen_expiration ),
					)
				);
			}
		} finally {
			\remove_filter( 'wp_privacy_exports_dir', $dir_filter, 10 );
			\remove_filter( 'wp_privacy_exports_url', $url_filter, 10 );
			\remove_filter( 'wp_privacy_export_expiration', $expiration, 10 );
			self::remove_tree( $base_dir );
		}

		if (
			false !== \has_filter( 'wp_privacy_exports_dir', $dir_filter )
			|| false !== \has_filter( 'wp_privacy_exports_url', $url_filter )
			|| false !== \has_filter( 'wp_privacy_export_expiration', $expiration )
		) {
			self::record_failure(
				$failures,
				'export-paths.filters-removed',
				array( 'label' => 'filter-locality' ),
				array(
					'dirFilter'        => \has_filter( 'wp_privacy_exports_dir', $dir_filter ),
					'urlFilter'        => \has_filter( 'wp_privacy_exports_url', $url_filter ),
					'expirationFilter' => \has_filter( 'wp_privacy_export_expiration', $expiration ),
				)
			);
		}

		return self::row(
			$ctx,
			'privacy.export-paths.dir-url-and-expiration-cleanup',
			array() === $failures,
			array(
				'token'    => $token,
				'failures' => $failures,
			)
		);
	}

	private static function check_policy_content_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::set_policy_content_state( array() );
		$plugin_name = 'Component <Privacy> & "' . $ctx->identifier( 3, 8 );
		$policy_text = '<p>Collects <strong>settings</strong> & emails.</p><script>alert(1)</script>';
		$other_name  = 'Other Plugin ' . $ctx->identifier( 3, 8 );
		$other_text  = '<p>Stores anonymous telemetry only.</p>';

		self::with_admin_init_context(
			static function () use ( $plugin_name, $policy_text, $other_name, $other_text ): void {
				\wp_add_privacy_policy_content( '', $policy_text );
				\wp_add_privacy_policy_content( $plugin_name, '' );
				\wp_add_privacy_policy_content( $plugin_name, $policy_text );
				\wp_add_privacy_policy_content( $plugin_name, $policy_text );
				\wp_add_privacy_policy_content( $other_name, $other_text );
			}
		);

		$suggested = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		self::collect_failure(
			$failures,
			2 === count( $suggested )
				&& $plugin_name === ( $suggested[0]['plugin_name'] ?? null )
				&& $policy_text === ( $suggested[0]['policy_text'] ?? null )
				&& isset( $suggested[0]['added'], $suggested[1]['added'] )
				&& $suggested[0]['added'] <= time()
				&& $other_name === ( $suggested[1]['plugin_name'] ?? null )
				&& $other_text === ( $suggested[1]['policy_text'] ?? null ),
			'wp_add_privacy_policy_content ignores empty entries, deduplicates exact suggestions, and preserves raw suggested text',
			array( 'suggested' => self::describe_value( $suggested ) )
		);

		$default_blocks      = \WP_Privacy_Policy_Content::get_default_content( false, true );
		$default_classic     = \WP_Privacy_Policy_Content::get_default_content( false, false );
		$description_classic = \WP_Privacy_Policy_Content::get_default_content( true, false );
		self::collect_failure(
			$failures,
			is_string( $default_blocks )
				&& is_string( $default_classic )
				&& is_string( $description_classic )
				&& str_contains( $default_blocks, '<!-- wp:heading -->' )
				&& str_contains( $default_blocks, '<!-- wp:paragraph -->' )
				&& false === strpos( $default_classic, '<!-- wp:' )
				&& str_contains( $default_classic, 'http://example.test' )
				&& str_contains( $description_classic, 'privacy-policy-tutorial' ),
			'default privacy policy content switches block/classic/tutorial formats deterministically',
			array(
				'blocks'      => self::describe_string( $default_blocks ),
				'classic'     => self::describe_string( $default_classic ),
				'description' => self::describe_string( $description_classic ),
			)
		);

		self::set_policy_content_state( array() );
		self::with_admin_init_context(
			static function (): void {
				\WP_Privacy_Policy_Content::add_suggested_content();
			}
		);
		$core_suggested = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		self::collect_failure(
			$failures,
			1 === count( $core_suggested )
				&& 'WordPress' === ( $core_suggested[0]['plugin_name'] ?? null )
				&& ! empty( $core_suggested[0]['policy_text'] )
				&& false === strpos( $core_suggested[0]['policy_text'], '<!-- wp:' ),
			'add_suggested_content registers classic WordPress default text through the public wrapper',
			array( 'coreSuggested' => self::describe_value( $core_suggested ) )
		);

		return self::row(
			$ctx,
			'privacy.policy-content.registration-and-defaults',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_policy_content_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$suffix         = strtolower( $ctx->identifier( 4, 8 ) );
		$policy_page_id = 94800 + $ctx->int( 0, 99 );
		$old_time       = time() - ( 2 * DAY_IN_SECONDS );

		$stable_name        = 'Stable Privacy ' . $suffix;
		$renamed_old_name   = 'Old Privacy Name ' . $suffix;
		$renamed_name       = 'Renamed Privacy <Plugin> & "' . $suffix;
		$updated_name       = 'Updated Privacy ' . $suffix;
		$reactivated_name   = 'Reactivated Privacy ' . $suffix;
		$removed_name       = 'Removed Privacy ' . $suffix;
		$added_name         = 'Added Privacy ' . $suffix;
		$stable_text        = '<p>Stable policy text ' . $suffix . '.</p>';
		$renamed_text       = '<p>Policy text moved with the plugin name.</p>';
		$updated_old_text   = '<p>Old policy text.</p>';
		$updated_new_text   = '<p>New policy text with <strong>changed</strong> processing.</p>';
		$reactivated_text   = '<p>Reactivated policy text.</p>';
		$removed_text       = '<p>Removed policy text.</p>';
		$added_text         = '<p>New plugin policy text.</p>';
		$policy_changed_key = '_wp_suggested_policy_text_has_changed';

		$policy_post = self::prime_policy_page( $policy_page_id );
		self::prime_policy_page( $policy_page_id + 1 );
		self::$options['wp_page_for_privacy_policy'] = $policy_page_id;
		self::$options[ $policy_changed_key ]        = false;

		$old = array(
			array(
				'plugin_name' => $stable_name,
				'policy_text' => $stable_text,
				'added'       => $old_time,
			),
			array(
				'plugin_name' => $renamed_old_name,
				'policy_text' => $renamed_text,
				'added'       => $old_time,
			),
			array(
				'plugin_name' => $updated_name,
				'policy_text' => $updated_old_text,
				'added'       => $old_time,
			),
			array(
				'plugin_name' => $reactivated_name,
				'policy_text' => $reactivated_text,
				'removed'     => $old_time,
			),
			array(
				'plugin_name' => $removed_name,
				'policy_text' => $removed_text,
				'added'       => $old_time,
			),
		);
		$new = array(
			array(
				'plugin_name' => $stable_name,
				'policy_text' => $stable_text,
			),
			array(
				'plugin_name' => $renamed_name,
				'policy_text' => $renamed_text,
			),
			array(
				'plugin_name' => $updated_name,
				'policy_text' => $updated_new_text,
			),
			array(
				'plugin_name' => $reactivated_name,
				'policy_text' => $reactivated_text,
			),
			array(
				'plugin_name' => $added_name,
				'policy_text' => $added_text,
			),
		);

		self::set_post_meta( $policy_page_id, array( self::POLICY_CONTENT_META_KEY => $old ) );
		self::set_policy_content_state( $new );
		$start          = time();
		$suggested_call = self::call(
			static function () {
				return \WP_Privacy_Policy_Content::get_suggested_policy_text();
			}
		);
		$suggested      = ( ! $suggested_call['threw'] && is_array( $suggested_call['value'] ) ) ? $suggested_call['value'] : array();
		$by_plugin      = self::index_policy_sections( $suggested );

		$transition_ok = 6 === count( $suggested )
			&& isset( $by_plugin[ $stable_name ], $by_plugin[ $renamed_name ], $by_plugin[ $updated_name ], $by_plugin[ $reactivated_name ], $by_plugin[ $removed_name ], $by_plugin[ $added_name ] )
			&& ! isset( $by_plugin[ $renamed_old_name ] )
			&& $old_time === ( $by_plugin[ $stable_name ]['added'] ?? null )
			&& $old_time === ( $by_plugin[ $renamed_name ]['added'] ?? null )
			&& $updated_new_text === ( $by_plugin[ $updated_name ]['policy_text'] ?? null )
			&& isset( $by_plugin[ $updated_name ]['updated'] )
			&& (int) $by_plugin[ $updated_name ]['updated'] >= $start
			&& isset( $by_plugin[ $reactivated_name ]['added'] )
			&& (int) $by_plugin[ $reactivated_name ]['added'] >= $start
			&& ! isset( $by_plugin[ $reactivated_name ]['removed'] )
			&& isset( $by_plugin[ $removed_name ]['removed'] )
			&& (int) $by_plugin[ $removed_name ]['removed'] >= $start
			&& isset( $by_plugin[ $added_name ]['added'] )
			&& (int) $by_plugin[ $added_name ]['added'] >= $start;
		self::collect_failure(
			$failures,
			! $suggested_call['threw'] && $transition_ok,
			'policy-content.get-suggested-policy-text-classifies-added-updated-removed-reactivated-and-renamed',
			array(
				'suggestedCall' => self::describe_call( $suggested_call ),
				'suggested'     => self::describe_value( $suggested ),
			)
		);

		$meta_after = \get_post_meta( $policy_page_id, self::POLICY_CONTENT_META_KEY );
		self::collect_failure(
			$failures,
			$suggested === $meta_after,
			'policy-content.get-suggested-policy-text-rewrites-policy-page-cache-as-multi-row-meta',
			array(
				'suggested' => self::describe_value( $suggested ),
				'metaAfter' => self::describe_value( $meta_after ),
			)
		);

		$ignored_update = self::call(
			static function () use ( $policy_page_id ): void {
				\WP_Privacy_Policy_Content::_policy_page_updated( $policy_page_id + 1 );
			}
		);
		$after_ignored  = \get_post_meta( $policy_page_id, self::POLICY_CONTENT_META_KEY );
		self::collect_failure(
			$failures,
			! $ignored_update['threw'] && $after_ignored === $meta_after,
			'policy-content.policy-page-updated-ignores-non-policy-posts',
			array(
				'ignoredUpdate' => self::describe_call( $ignored_update ),
				'before'        => self::describe_value( $meta_after ),
				'after'         => self::describe_value( $after_ignored ),
			)
		);

		$matching_update = self::call(
			static function () use ( $policy_page_id ): void {
				\WP_Privacy_Policy_Content::_policy_page_updated( $policy_page_id );
			}
		);
		$final_meta      = \get_post_meta( $policy_page_id, self::POLICY_CONTENT_META_KEY );
		$final_by_plugin = self::index_policy_sections( $final_meta );
		$updated_time    = $by_plugin[ $updated_name ]['updated'] ?? null;
		self::collect_failure(
			$failures,
			! $matching_update['threw']
				&& 5 === count( $final_meta )
				&& ! isset( $final_by_plugin[ $removed_name ] )
				&& self::policy_sections_are_active( $final_meta )
				&& $updated_time === ( $final_by_plugin[ $updated_name ]['added'] ?? null ),
			'policy-content.policy-page-updated-drops-removed-and-converts-updated-to-added',
			array(
				'matchingUpdate' => self::describe_call( $matching_update ),
				'finalMeta'      => self::describe_value( $final_meta ),
			)
		);

		$grant_caps = static function ( array $allcaps, array $caps ): array {
			foreach ( array_merge( $caps, array( 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'manage_options', 'manage_privacy_options' ) ) as $cap ) {
				$allcaps[ $cap ] = true;
			}

			return $allcaps;
		};
		$deny_edit_post = static function ( array $caps, string $cap ): array {
			return 'edit_post' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		$text_old       = array(
			array(
				'plugin_name' => 'Text Change Stable',
				'policy_text' => '<p>Reviewed privacy text.</p>',
				'added'       => $old_time,
			),
			array(
				'plugin_name' => 'Text Change Removed',
				'policy_text' => '<p>Ignored removed text.</p>',
				'removed'     => $old_time,
			),
		);
		$text_same      = array(
			array(
				'plugin_name' => 'Text Change Stable',
				'policy_text' => '<p>Reviewed privacy text.</p>',
			),
		);
		$text_changed   = array(
			array(
				'plugin_name' => 'Text Change Stable',
				'policy_text' => '<p>Changed privacy text.</p>',
			),
		);

		self::$options['wp_page_for_privacy_policy'] = 0;
		$no_page = self::call(
			static function () {
				return self::with_admin_action_count(
					1,
					static function () {
						return \WP_Privacy_Policy_Content::text_change_check();
					}
				);
			}
		);
		self::collect_failure(
			$failures,
			! $no_page['threw'] && false === $no_page['value'],
			'policy-content.text-change-check-returns-false-without-policy-page',
			array( 'noPage' => self::describe_call( $no_page ) )
		);

		self::$options['wp_page_for_privacy_policy'] = $policy_page_id;
		self::set_post_meta( $policy_page_id, array( self::POLICY_CONTENT_META_KEY => $text_old ) );
		self::set_policy_content_state( $text_changed );
		\add_filter( 'map_meta_cap', $deny_edit_post, 10, 2 );
		try {
			$no_cap = self::call(
				static function () {
					return self::with_admin_action_count(
						1,
						static function () {
							return \WP_Privacy_Policy_Content::text_change_check();
						}
					);
				}
			);
		} finally {
			\remove_filter( 'map_meta_cap', $deny_edit_post, 10 );
		}
		self::collect_failure(
			$failures,
			! $no_cap['threw'] && false === $no_cap['value'],
			'policy-content.text-change-check-requires-edit-policy-page-capability',
			array( 'noCap' => self::describe_call( $no_cap ) )
		);

		\add_filter( 'user_has_cap', $grant_caps, 10, 2 );
		try {
			self::set_post_meta( $policy_page_id, array( self::POLICY_CONTENT_META_KEY => array() ) );
			$no_prior_review = self::call(
				static function () {
					return self::with_admin_action_count(
						1,
						static function () {
							return \WP_Privacy_Policy_Content::text_change_check();
						}
					);
				}
			);
			self::collect_failure(
				$failures,
				! $no_prior_review['threw'] && false === $no_prior_review['value'],
				'policy-content.text-change-check-returns-false-before-any-review',
				array( 'noPriorReview' => self::describe_call( $no_prior_review ) )
			);

			self::set_post_meta( $policy_page_id, array( self::POLICY_CONTENT_META_KEY => $text_old ) );
			self::$options[ $policy_changed_key ] = 'changed';
			$cached_changed = self::call(
				static function () {
					return self::with_admin_action_count(
						0,
						static function () {
							return \WP_Privacy_Policy_Content::text_change_check();
						}
					);
				}
			);
			self::$options[ $policy_changed_key ] = 'not-changed';
			$cached_unchanged = self::call(
				static function () {
					return self::with_admin_action_count(
						0,
						static function () {
							return \WP_Privacy_Policy_Content::text_change_check();
						}
					);
				}
			);
			self::collect_failure(
				$failures,
				! $cached_changed['threw']
					&& true === $cached_changed['value']
					&& ! $cached_unchanged['threw']
					&& false === $cached_unchanged['value'],
				'policy-content.text-change-check-before-admin-init-uses-cached-option',
				array(
					'cachedChanged'   => self::describe_call( $cached_changed ),
					'cachedUnchanged' => self::describe_call( $cached_unchanged ),
				)
			);

			\remove_action( 'admin_notices', array( 'WP_Privacy_Policy_Content', 'policy_text_changed_notice' ) );
			self::$options[ $policy_changed_key ] = 'changed';
			self::set_policy_content_state( $text_same );
			$unchanged = self::call(
				static function () {
					return self::with_admin_action_count(
						1,
						static function () {
							return \WP_Privacy_Policy_Content::text_change_check();
						}
					);
				}
			);
			self::collect_failure(
				$failures,
				! $unchanged['threw']
					&& false === $unchanged['value']
					&& 'not-changed' === ( self::$options[ $policy_changed_key ] ?? null )
					&& false === \has_action( 'admin_notices', array( 'WP_Privacy_Policy_Content', 'policy_text_changed_notice' ) ),
				'policy-content.text-change-check-normalizes-reviewed-and-removed-policy-text',
				array(
					'unchanged' => self::describe_call( $unchanged ),
					'option'    => self::describe_value( self::$options[ $policy_changed_key ] ?? null ),
					'hasNotice' => \has_action( 'admin_notices', array( 'WP_Privacy_Policy_Content', 'policy_text_changed_notice' ) ),
				)
			);

			self::$options[ $policy_changed_key ] = 'not-changed';
			self::set_policy_content_state( $text_changed );
			$changed = self::call(
				static function () {
					return self::with_admin_action_count(
						1,
						static function () {
							return \WP_Privacy_Policy_Content::text_change_check();
						}
					);
				}
			);
			$notice_hooked = \has_action( 'admin_notices', array( 'WP_Privacy_Policy_Content', 'policy_text_changed_notice' ) );
			self::collect_failure(
				$failures,
				! $changed['threw']
					&& true === $changed['value']
					&& 'changed' === ( self::$options[ $policy_changed_key ] ?? null )
					&& false !== $notice_hooked,
				'policy-content.text-change-check-detects-real-change-caches-state-and-schedules-notice',
				array(
					'changed'      => self::describe_call( $changed ),
					'option'       => self::describe_value( self::$options[ $policy_changed_key ] ?? null ),
					'noticeHooked' => $notice_hooked,
				)
			);

			$privacy_notice = self::call(
				static function () {
					return self::with_current_screen(
						'privacy',
						'privacy',
						false,
						static function () {
							return self::capture_output(
								static function (): void {
									\WP_Privacy_Policy_Content::policy_text_changed_notice();
								}
							);
						}
					);
				}
			);
			$other_notice   = self::call(
				static function () {
					return self::with_current_screen(
						'dashboard',
						'dashboard',
						false,
						static function () {
							return self::capture_output(
								static function (): void {
									\WP_Privacy_Policy_Content::policy_text_changed_notice();
								}
							);
						}
					);
				}
			);
			self::collect_failure(
				$failures,
				! $privacy_notice['threw']
					&& is_string( $privacy_notice['value'] )
					&& str_contains( $privacy_notice['value'], 'policy-text-updated' )
					&& str_contains( $privacy_notice['value'], 'privacy-policy-guide.php?tab=policyguide' )
					&& ! $other_notice['threw']
					&& '' === $other_notice['value'],
				'policy-content.policy-text-changed-notice-only-renders-on-privacy-screen',
				array(
					'privacyNotice' => self::describe_call( $privacy_notice ),
					'otherNotice'   => self::describe_call( $other_notice ),
				)
			);

			self::$options['wp_page_for_privacy_policy'] = $policy_page_id;
			$classic_notice = self::call(
				static function () use ( $policy_post ) {
					return self::with_current_screen(
						'page',
						'post',
						false,
						static function () use ( $policy_post ) {
							return self::capture_output(
								static function () use ( $policy_post ): void {
									\WP_Privacy_Policy_Content::notice( $policy_post );
								}
							);
						}
					);
				}
			);
			$non_policy_notice = self::call(
				static function () use ( $policy_page_id ) {
					$other_post = self::prime_policy_page( $policy_page_id + 50 );
					return self::with_current_screen(
						'page',
						'post',
						false,
						static function () use ( $other_post ) {
							return self::capture_output(
								static function () use ( $other_post ): void {
									\WP_Privacy_Policy_Content::notice( $other_post );
								}
							);
						}
					);
				}
			);
			self::collect_failure(
				$failures,
				! $classic_notice['threw']
					&& is_string( $classic_notice['value'] )
					&& str_contains( $classic_notice['value'], 'wp-pp-notice' )
					&& str_contains( $classic_notice['value'], 'options-privacy.php?tab=policyguide' )
					&& ! $non_policy_notice['threw']
					&& '' === $non_policy_notice['value'],
				'policy-content.notice-renders-only-for-classic-policy-page-editor',
				array(
					'classicNotice'   => self::describe_call( $classic_notice ),
					'nonPolicyNotice' => self::describe_call( $non_policy_notice ),
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $grant_caps, 10 );
			\remove_action( 'admin_notices', array( 'WP_Privacy_Policy_Content', 'policy_text_changed_notice' ) );
		}

		return self::row(
			$ctx,
			'privacy.policy-content.lifecycle-cache-notice-contracts',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function request_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$base_time = 1700000000 + $ctx->int( 0, 100000 );
		$cases     = array(
			array(
				'label'     => 'valid-export',
				'id'        => 90001,
				'userId'    => '101',
				'email'     => 'person@example.test',
				'action'    => 'export_personal_data',
				'status'    => 'request-pending',
				'content'   => \wp_json_encode( array( 'source' => 'fixed', 'html' => '<b>Alice</b>' ) ),
				'created'   => gmdate( 'Y-m-d H:i:s', $base_time ),
				'modified'  => gmdate( 'Y-m-d H:i:s', $base_time + 60 ),
				'confirmed' => $base_time + 120,
				'completed' => 0,
				'key'       => \wp_fast_hash( 'fixed-export-key' ),
			),
			array(
				'label'     => 'malformed-email-unknown-action',
				'id'        => 90002,
				'userId'    => '0',
				'email'     => "bad@@example.test\x00<script>",
				'action'    => 'unknown<' . self::random_string( $ctx->fork( 'fixed-action' ), 10 ) . '>',
				'status'    => 'request-failed',
				'content'   => '{"broken":',
				'created'   => 'not-a-date',
				'modified'  => gmdate( 'Y-m-d H:i:s', $base_time + 120 ),
				'confirmed' => '',
				'completed' => '42',
				'key'       => 'plain-not-a-fast-hash',
			),
			array(
				'label'     => 'unicode-erasure-array-json',
				'id'        => 90003,
				'userId'    => '777',
				'email'     => "δοκιμή@παράδειγμα.δοκιμή",
				'action'    => 'remove_personal_data',
				'status'    => 'request-confirmed',
				'content'   => \wp_json_encode( array( "é", '<script>x</script>', array( 'nested' => "snowman \xE2\x98\x83" ) ) ),
				'created'   => gmdate( 'Y-m-d H:i:s', $base_time + 240 ),
				'modified'  => '',
				'confirmed' => $base_time + 300,
				'completed' => $base_time + 360,
				'key'       => \wp_fast_hash( "unicode-key-\xE2\x98\x83" ),
			),
		);

		for ( $i = 0; $i < self::GENERATED_REQUEST_CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'generated-request-' . $i );
			$action   = $case_ctx->choice(
				array(
					'export_personal_data',
					'remove_personal_data',
					'custom_' . self::random_string( $case_ctx->fork( 'action' ), $case_ctx->int( 4, 18 ) ),
				)
			);
			$email    = $case_ctx->choice(
				array(
					'user+' . $i . '@example.test',
					'bad@@example.test',
					"unicode-\xC3\xA9@example.test",
					self::random_string( $case_ctx->fork( 'email-local' ), 12 ) . '@example.test',
				)
			);
			$content  = $case_ctx->choice(
				array(
					\wp_json_encode( array( 'i' => $i, 'value' => self::random_string( $case_ctx->fork( 'json-value' ), 24 ) ) ),
					\wp_json_encode( array( self::random_string( $case_ctx->fork( 'array-value' ), 16 ) ) ),
					\wp_json_encode( self::random_string( $case_ctx->fork( 'scalar-value' ), 16 ) ),
					'{"malformed":',
				)
			);
			$created  = $base_time + 600 + $i * 100;
			$modified = $created + $case_ctx->int( 0, 3600 );

			$cases[] = array(
				'label'     => 'generated-' . $i,
				'id'        => 90100 + $i,
				'userId'    => (string) $case_ctx->int( 0, 10000 ),
				'email'     => $email,
				'action'    => $action,
				'status'    => $case_ctx->choice( array( 'request-pending', 'request-confirmed', 'request-failed', 'request-completed' ) ),
				'content'   => false === $content ? 'null' : $content,
				'created'   => $case_ctx->bool( 10 ) ? 'invalid generated date' : gmdate( 'Y-m-d H:i:s', $created ),
				'modified'  => $case_ctx->bool( 10 ) ? '' : gmdate( 'Y-m-d H:i:s', $modified ),
				'confirmed' => $case_ctx->choice( array( 0, '', $modified + 10, (string) ( $modified + 20 ) ) ),
				'completed' => $case_ctx->choice( array( 0, '', $modified + 30, (string) ( $modified + 40 ) ) ),
				'key'       => $case_ctx->bool() ? \wp_fast_hash( 'generated-key-' . $i ) : self::random_string( $case_ctx->fork( 'plain-key' ), 20 ),
			);
		}

		foreach ( $cases as $index => $case ) {
			$cases[ $index ]['post'] = self::post_record(
				array(
					'ID'                => $case['id'],
					'post_author'       => $case['userId'],
					'post_title'        => $case['email'],
					'post_name'         => $case['action'],
					'post_status'       => $case['status'],
					'post_content'      => $case['content'],
					'post_date'         => $case['created'],
					'post_date_gmt'     => $case['created'],
					'post_modified'     => $case['modified'],
					'post_modified_gmt' => $case['modified'],
					'post_password'     => $case['key'],
				)
			);
		}

		return $cases;
	}

	private static function request_matches_post( \WP_User_Request $request, array $case, $expected_data, $expected_created, $expected_modified ): bool {
		$post = $case['post'];

		return $request->ID === $post->ID
			&& $request->user_id === $post->post_author
			&& $request->email === $post->post_title
			&& $request->action_name === $post->post_name
			&& $request->status === $post->post_status
			&& $request->created_timestamp === $expected_created
			&& $request->modified_timestamp === $expected_modified
			&& $request->confirmed_timestamp === (int) $case['confirmed']
			&& $request->completed_timestamp === (int) $case['completed']
			&& $request->request_data === $expected_data
			&& $request->confirm_key === $post->post_password;
	}

	private static function request_public_shape_ok( \WP_User_Request $request ): bool {
		$properties = array(
			'ID',
			'user_id',
			'email',
			'action_name',
			'status',
			'created_timestamp',
			'modified_timestamp',
			'confirmed_timestamp',
			'completed_timestamp',
			'request_data',
			'confirm_key',
		);

		foreach ( $properties as $property ) {
			if ( ! property_exists( $request, $property ) ) {
				return false;
			}
		}

		return is_int( $request->ID )
			&& is_string( $request->user_id )
			&& is_string( $request->email )
			&& is_string( $request->action_name )
			&& is_string( $request->status )
			&& ( is_int( $request->created_timestamp ) || false === $request->created_timestamp )
			&& ( is_int( $request->modified_timestamp ) || false === $request->modified_timestamp )
			&& is_int( $request->confirmed_timestamp )
			&& is_int( $request->completed_timestamp )
			&& is_string( $request->confirm_key );
	}

	private static function post_record( array $overrides ): object {
		return (object) array_merge(
			array(
				'ID'                    => 0,
				'post_author'           => '0',
				'post_date'             => '2024-01-01 00:00:00',
				'post_date_gmt'         => '2024-01-01 00:00:00',
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
				'post_modified'         => '2024-01-01 00:00:00',
				'post_modified_gmt'     => '2024-01-01 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => '',
				'menu_order'            => 0,
				'post_type'             => 'user_request',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			),
			$overrides
		);
	}

	private static function prime_privacy_request( int $request_id, string $action_name, string $status ): void {
		self::prime_request_post(
			self::post_record(
				array(
					'ID'                => $request_id,
					'post_author'       => '0',
					'post_title'        => 'person@example.test',
					'post_name'         => $action_name,
					'post_status'       => $status,
					'post_content'      => '{"processor":"privacy"}',
					'post_password'     => \wp_fast_hash( 'processor-key-' . $request_id ),
					'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - 60 ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				)
			)
		);
	}

	private static function prime_request_post( object $post ): void {
		\wp_cache_set( (int) $post->ID, $post, 'posts' );
	}

	private static function prime_policy_page( int $post_id ): \WP_Post {
		$post = self::post_record(
			array(
				'ID'          => $post_id,
				'post_author' => '1',
				'post_title'  => 'Privacy Policy ' . $post_id,
				'post_name'   => 'privacy-policy-' . $post_id,
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		self::prime_request_post( $post );

		return new \WP_Post( $post );
	}

	private static function set_post_meta( int $post_id, array $meta ): void {
		self::$post_meta[ $post_id ] = $meta;
	}

	private static function delete_post_meta_value( int $post_id, string $meta_key, $meta_value ): void {
		if ( ! isset( self::$post_meta[ $post_id ][ $meta_key ] ) ) {
			return;
		}

		if ( self::POLICY_CONTENT_META_KEY !== $meta_key || '' === $meta_value ) {
			unset( self::$post_meta[ $post_id ][ $meta_key ] );
			return;
		}

		$values   = self::$post_meta[ $post_id ][ $meta_key ];
		$values   = is_array( $values ) ? array_values( $values ) : array( $values );
		$filtered = array();
		foreach ( $values as $value ) {
			if ( $value !== $meta_value ) {
				$filtered[] = $value;
			}
		}

		if ( array() === $filtered ) {
			unset( self::$post_meta[ $post_id ][ $meta_key ] );
		} else {
			self::$post_meta[ $post_id ][ $meta_key ] = $filtered;
		}
	}

	private static function index_policy_sections( array $sections ): array {
		$indexed = array();
		foreach ( $sections as $section ) {
			if ( is_array( $section ) && isset( $section['plugin_name'] ) && is_string( $section['plugin_name'] ) ) {
				$indexed[ $section['plugin_name'] ] = $section;
			}
		}

		return $indexed;
	}

	private static function policy_sections_are_active( array $sections ): bool {
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || isset( $section['removed'] ) || isset( $section['updated'] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function processor_exporters(): array {
		return array(
			'component-one' => array(
				'exporter_friendly_name' => 'Component One',
				'callback'               => '__return_empty_array',
			),
			'component-two' => array(
				'exporter_friendly_name' => 'Component Two',
				'callback'               => '__return_empty_array',
			),
		);
	}

	private static function processor_erasers(): array {
		return array(
			'component-one' => array(
				'eraser_friendly_name' => 'Component One',
				'callback'             => '__return_empty_array',
			),
			'component-two' => array(
				'eraser_friendly_name' => 'Component Two',
				'callback'             => '__return_empty_array',
			),
		);
	}

	private static function export_datum( string $group_id, string $group_label, string $item_id, string $name, string $value ): array {
		return array(
			'group_id'          => $group_id,
			'group_label'       => $group_label,
			'group_description' => $group_label . ' data',
			'item_id'           => $item_id,
			'data'              => array(
				array(
					'name'  => $name,
					'value' => $value,
				),
			),
		);
	}

	private static function grouped_export_action_ok( array $action ): bool {
		if ( 92001 !== ( $action['requestId'] ?? null ) || ! isset( $action['grouped'] ) || ! is_array( $action['grouped'] ) ) {
			return false;
		}

		$groups = $action['grouped'];

		return isset( $groups['profile']['items']['profile-1'], $groups['activity']['items']['login-1'] )
			&& 'Profile' === $groups['profile']['group_label']
			&& 'Activity' === $groups['activity']['group_label']
			&& 2 === count( $groups['profile']['items']['profile-1'] )
			&& 'Email' === $groups['profile']['items']['profile-1'][0]['name']
			&& 'Name' === $groups['profile']['items']['profile-1'][1]['name']
			&& 'IP' === $groups['activity']['items']['login-1'][0]['name'];
	}

	private static function addresses_include( array $addresses, string $email, string $name ): bool {
		foreach ( $addresses as $address ) {
			if ( isset( $address[0], $address[1] ) && $email === $address[0] && $name === trim( (string) $address[1] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function custom_headers_include( array $headers, string $name, ?string $value = null ): bool {
		foreach ( $headers as $header ) {
			if ( is_array( $header ) && isset( $header[0] ) && $name === $header[0] ) {
				return null === $value || ( isset( $header[1] ) && $value === trim( (string) $header[1] ) );
			}
			if ( is_string( $header ) && str_contains( $header, ':' ) ) {
				list( $header_name, $header_value ) = explode( ':', $header, 2 );
				if ( $name === trim( $header_name ) ) {
					return null === $value || $value === trim( $header_value );
				}
			}
		}

		return false;
	}

	private static function snapshot_mailer_state(): array {
		return array(
			'phpmailer' => array_key_exists( 'phpmailer', $GLOBALS )
				? array( 'exists' => true, 'value' => $GLOBALS['phpmailer'] )
				: array( 'exists' => false, 'value' => null ),
			'validator' => \PHPMailer\PHPMailer\PHPMailer::$validator,
		);
	}

	private static function restore_mailer_state( array $snapshot ): void {
		if ( $snapshot['phpmailer']['exists'] ) {
			$GLOBALS['phpmailer'] = $snapshot['phpmailer']['value'];
		} else {
			unset( $GLOBALS['phpmailer'] );
		}

		\PHPMailer\PHPMailer\PHPMailer::$validator = $snapshot['validator'];
	}

	private static function mailer_state_restored( array $snapshot ): bool {
		$phpmailer_restored = $snapshot['phpmailer']['exists']
			? array_key_exists( 'phpmailer', $GLOBALS ) && $GLOBALS['phpmailer'] === $snapshot['phpmailer']['value']
			: ! array_key_exists( 'phpmailer', $GLOBALS );

		return $phpmailer_restored && \PHPMailer\PHPMailer\PHPMailer::$validator === $snapshot['validator'];
	}

	private static function new_mailer(): object {
		return new class( true ) extends \WP_PHPMailer {
			public function send(): bool {
				$this->preSend();
				PrivacySurfaceMailer::$sent[] = array(
					'to'            => $this->getToAddresses(),
					'cc'            => $this->getCcAddresses(),
					'bcc'           => $this->getBccAddresses(),
					'replyTo'       => $this->getReplyToAddresses(),
					'attachments'   => $this->getAttachments(),
					'customHeaders' => $this->getCustomHeaders(),
					'from'          => $this->From,
					'fromName'      => $this->FromName,
					'subject'       => $this->Subject,
					'body'          => $this->Body,
					'contentType'   => $this->ContentType,
					'charset'       => $this->CharSet,
					'encoding'      => $this->Encoding,
					'mimeMessage'   => $this->getSentMIMEMessage(),
				);

				return true;
			}
		};
	}

	private static function wpdb_stub_available(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' );
	}

	private static function reset_db_content(): void {
		if ( self::wpdb_stub_available() ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
	}

	private static function seed_db_request_post( object $post ): void {
		if ( ! self::wpdb_stub_available() ) {
			return;
		}

		$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->posts, get_object_vars( $post ) );
		\clean_post_cache( (int) $post->ID );
	}

	private static function insert_privacy_comment( array $overrides ): int {
		$comment_id = \wp_insert_comment(
			array_merge(
				array(
					'comment_post_ID'      => 0,
					'comment_author'       => 'Component Fuzz',
					'comment_author_email' => 'commenter@example.test',
					'comment_author_url'   => '',
					'comment_author_IP'    => '203.0.113.7',
					'comment_date'         => '2026-06-25 10:00:00',
					'comment_date_gmt'     => '2026-06-25 10:00:00',
					'comment_content'      => 'Component fuzz comment',
					'comment_approved'     => '1',
					'comment_agent'        => 'ComponentFuzz',
					'comment_type'         => 'comment',
					'user_id'              => 0,
				),
				$overrides
			)
		);

		return (int) $comment_id;
	}

	private static function comment_export_item_ok( $item, int $comment_id, string $email ): bool {
		if ( ! is_array( $item ) || "comment-{$comment_id}" !== ( $item['item_id'] ?? null ) || 'comments' !== ( $item['group_id'] ?? null ) ) {
			return false;
		}

		$fields = array();
		foreach ( (array) ( $item['data'] ?? array() ) as $datum ) {
			if ( is_array( $datum ) && isset( $datum['name'] ) ) {
				$fields[ $datum['name'] ] = $datum['value'] ?? null;
			}
		}

		$link = $fields['Comment URL'] ?? '';

		return $email === ( $fields['Comment Author Email'] ?? null )
			&& isset( $fields['Comment Author'], $fields['Comment Author IP'], $fields['Comment Author User Agent'], $fields['Comment Date'], $fields['Comment Content'] )
			&& is_string( $link )
			&& str_contains( $link, '<a href="' )
			&& str_contains( $link, 'target="_blank"' )
			&& false === stripos( $link, '<script' );
	}

	private static function export_item_fields( $item ): array {
		$fields = array();
		if ( ! is_array( $item ) ) {
			return $fields;
		}

		foreach ( (array) ( $item['data'] ?? array() ) as $datum ) {
			if ( is_array( $datum ) && isset( $datum['name'] ) ) {
				$fields[ $datum['name'] ] = $datum['value'] ?? null;
			}
		}

		return $fields;
	}

	private static function find_export_item( array $items, string $group_id, string $item_id ): ?array {
		foreach ( $items as $item ) {
			if ( is_array( $item ) && $group_id === ( $item['group_id'] ?? null ) && $item_id === ( $item['item_id'] ?? null ) ) {
				return $item;
			}
		}

		return null;
	}

	private static function export_item_ids( array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['item_id'] ) || ! is_string( $item['item_id'] ) ) {
				continue;
			}

			if ( preg_match( '/^post-(\d+)$/', $item['item_id'], $matches ) ) {
				$ids[] = (int) $matches[1];
			}
		}

		return $ids;
	}

	private static function install_scoped_filters(): void {
		\add_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 10, 5 );
		\add_filter( 'add_post_metadata', array( __CLASS__, 'filter_add_post_metadata' ), 10, 5 );
		\add_filter( 'update_post_metadata', array( __CLASS__, 'filter_update_post_metadata' ), 10, 5 );
		\add_filter( 'delete_post_metadata', array( __CLASS__, 'filter_delete_post_metadata' ), 10, 5 );
		\add_filter( 'pre_option_wp_page_for_privacy_policy', array( __CLASS__, 'filter_pre_option' ), 10, 3 );
		\add_filter( 'pre_option__wp_suggested_policy_text_has_changed', array( __CLASS__, 'filter_pre_option' ), 10, 3 );
		\add_filter( 'pre_update_option__wp_suggested_policy_text_has_changed', array( __CLASS__, 'filter_pre_update_option' ), 10, 3 );
		\add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'filter_exporters' ), 10, 1 );
		\add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'filter_erasers' ), 10, 1 );
		\add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10, 1 );
		\add_filter( 'is_email', 'wp_is_unicode_email', 10, 3 );
		\add_filter( 'sanitize_email', 'wp_sanitize_unicode_email', 10, 3 );
		\remove_all_filters( 'wp_privacy_personal_data_export_file' );
		\remove_all_filters( 'wp_privacy_personal_data_erased' );
		\add_action( 'wp_privacy_personal_data_export_file', array( __CLASS__, 'record_export_file_action' ), 10, 1 );
		\add_action( 'wp_privacy_personal_data_erased', array( __CLASS__, 'record_erased_action' ), 10, 1 );
	}

	private static function reset_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-admin/tools.php?page=component-fuzz-privacy';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.77';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/privacy';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['SERVER_PORT']     = '80';
	}

	private static function reset_static_state(): void {
		self::$post_meta           = array();
		self::$options             = array();
		self::$exporters           = array();
		self::$erasers             = array();
		self::$export_file_actions = array();
		self::$erased_actions      = array();
		PrivacySurfaceMailer::$sent = array();
	}

	private static function with_admin_init_context( callable $callback ): void {
		$had_current_screen = array_key_exists( 'current_screen', $GLOBALS );
		$current_screen     = $GLOBALS['current_screen'] ?? null;
		$had_wp_actions     = array_key_exists( 'wp_actions', $GLOBALS );
		$wp_actions         = $GLOBALS['wp_actions'] ?? null;

		$GLOBALS['current_screen'] = new class() {
			public function in_admin(): bool {
				return true;
			}
		};
		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['admin_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['admin_init'] ?? 0 ) );

		try {
			$callback();
		} finally {
			if ( $had_current_screen ) {
				$GLOBALS['current_screen'] = $current_screen;
			} else {
				unset( $GLOBALS['current_screen'] );
			}

			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $wp_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}
		}
	}

	private static function with_admin_action_count( int $count, callable $callback ) {
		$had_wp_actions = array_key_exists( 'wp_actions', $GLOBALS );
		$wp_actions     = $GLOBALS['wp_actions'] ?? null;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['admin_init'] = $count;

		try {
			return $callback();
		} finally {
			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $wp_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}
		}
	}

	private static function with_current_screen( string $id, string $base, bool $is_block_editor, callable $callback ) {
		$had_current_screen = array_key_exists( 'current_screen', $GLOBALS );
		$current_screen     = $GLOBALS['current_screen'] ?? null;
		$had_typenow        = array_key_exists( 'typenow', $GLOBALS );
		$typenow            = $GLOBALS['typenow'] ?? null;
		$had_taxnow         = array_key_exists( 'taxnow', $GLOBALS );
		$taxnow             = $GLOBALS['taxnow'] ?? null;

		$screen = \WP_Screen::get( $base );
		$screen->is_block_editor( $is_block_editor );
		$GLOBALS['current_screen'] = $screen;

		try {
			return $callback();
		} finally {
			if ( $had_current_screen ) {
				$GLOBALS['current_screen'] = $current_screen;
			} else {
				unset( $GLOBALS['current_screen'] );
			}

			if ( $had_typenow ) {
				$GLOBALS['typenow'] = $typenow;
			} else {
				unset( $GLOBALS['typenow'] );
			}

			if ( $had_taxnow ) {
				$GLOBALS['taxnow'] = $taxnow;
			} else {
				unset( $GLOBALS['taxnow'] );
			}
		}
	}

	private static function capture_output( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
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

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		self::record_failure(
			$failures,
			$label,
			array( 'label' => $label ),
			$details
		);
	}

	private static function record_failure( array &$failures, string $invariant, array $case, array $details ): void {
		if ( count( $failures ) >= self::MAX_FAILURES ) {
			return;
		}

		$failures[] = array(
			'invariant' => $invariant,
			'case'      => self::describe_case( $case ),
			'details'   => self::describe_value( $details ),
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

	private static function is_error_code( $value, string $code ): bool {
		return \is_wp_error( $value ) && $code === $value->get_error_code();
	}

	private static function random_string( \ComponentFuzz\FuzzContext $ctx, int $length ): string {
		$atoms = array(
			'a',
			'Z',
			'9',
			'_',
			'-',
			'.',
			'@',
			' ',
			"\t",
			"\n",
			'|',
			':/?#[]@!$&\'()*+,;=',
			'<tag attr="value">',
			'&amp;',
			'%0d%0a',
			"\x00",
			"\x1F",
			"\x7F",
			"\x80",
			"\xFF",
			"\xC3\xA9",
			"\xE2\x98\x83",
		);

		$out = '';
		while ( strlen( $out ) < $length ) {
			$out .= $ctx->choice( $atoms );
		}

		return substr( $out, 0, $length );
	}

	private static function mutate_secret( string $secret ): string {
		if ( '' === $secret ) {
			return 'component-fuzz-mutated';
		}

		$first = $secret[0];
		return ( 'x' === $first ? 'y' : 'x' ) . substr( $secret, 1 ) . '|mutated';
	}

	private static function remove_tree( string $path ): void {
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $path );
	}

	private static function describe_call( array $call ) {
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

	private static function describe_user_request( \WP_User_Request $request ): array {
		return array(
			'ID'                  => $request->ID,
			'user_id'             => self::describe_string( $request->user_id ),
			'email'               => self::describe_string( $request->email ),
			'action_name'         => self::describe_string( $request->action_name ),
			'status'              => self::describe_string( $request->status ),
			'created_timestamp'   => $request->created_timestamp,
			'modified_timestamp'  => $request->modified_timestamp,
			'confirmed_timestamp' => $request->confirmed_timestamp,
			'completed_timestamp' => $request->completed_timestamp,
			'request_data'        => self::describe_value( $request->request_data ),
			'confirm_key'         => self::describe_string( $request->confirm_key ),
		);
	}

	private static function describe_case( array $case ): array {
		$out = array();
		foreach ( array( 'label', 'id', 'status', 'action', 'expected' ) as $key ) {
			if ( array_key_exists( $key, $case ) ) {
				$out[ $key ] = self::describe_value( $case[ $key ] );
			}
		}

		if ( isset( $case['post'] ) && is_object( $case['post'] ) ) {
			$out['post'] = array(
				'ID'                => $case['post']->ID,
				'post_title'        => self::describe_string( $case['post']->post_title ),
				'post_name'         => self::describe_string( $case['post']->post_name ),
				'post_status'       => self::describe_string( $case['post']->post_status ),
				'post_date_gmt'     => self::describe_string( $case['post']->post_date_gmt ),
				'post_modified_gmt' => self::describe_string( $case['post']->post_modified_gmt ),
				'post_content'      => self::describe_string( $case['post']->post_content ),
			);
		}

		return $out;
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

		if ( is_object( $value ) ) {
			if ( $value instanceof \WP_User_Request ) {
				return self::describe_user_request( $value );
			}
			if ( $value instanceof \WP_Error ) {
				return array(
					'type'    => 'WP_Error',
					'code'    => $value->get_error_code(),
					'message' => self::describe_string( $value->get_error_message() ),
				);
			}
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
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

	private static function snapshot_globals(): array {
		$snapshot = array(
			'_GET'     => $_GET,
			'_POST'    => $_POST,
			'_REQUEST' => $_REQUEST,
			'_COOKIE'  => $_COOKIE,
			'_SERVER'  => $_SERVER,
			'globals'  => array(),
			'mailer'   => self::snapshot_mailer_state(),
			'policyContent' => self::get_policy_content_state(),
		);

		foreach (
			array(
				'post',
				'current_screen',
				'pagenow',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
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

		self::set_policy_content_state( $snapshot['policyContent'] );
		self::restore_mailer_state( $snapshot['mailer'] );
	}

	private static function get_policy_content_state(): array {
		$reflection = new \ReflectionProperty( \WP_Privacy_Policy_Content::class, 'policy_content' );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		$value = $reflection->getValue();
		return is_array( $value ) ? $value : array();
	}

	private static function set_policy_content_state( array $value ): void {
		$reflection = new \ReflectionProperty( \WP_Privacy_Policy_Content::class, 'policy_content' );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$reflection->setValue( null, $value );
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

final class PrivacySurfaceMailer {
	/** @var array<int,array<string,mixed>> */
	public static array $sent = array();
}
