<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes importer registry helpers, import upload UI, text diffs, and error export.
 */
final class ImportDiffSurface {
	public const NAME = 'import-diff';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'import-diff.bootstrap-apis-available',
					'Required import and diff APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_importer_registry( $ctx->fork( 'registry' ) );
			$rows[] = self::check_import_upload_form( $ctx->fork( 'upload-form' ) );
			$rows[] = self::check_import_upload_handler_and_cleanup( $ctx->fork( 'upload-handler' ) );
			$rows[] = self::check_text_diff_rendering( $ctx->fork( 'text-diff' ) );
			$rows[] = self::check_error_export_and_merge( $ctx->fork( 'error-export' ) );
			$rows[] = self::check_error_lifecycle_ordering( $ctx->fork( 'error-lifecycle' ) );
			$rows[] = self::check_imported_post_lookup( $ctx->fork( 'post-lookup' ) );
			$rows[] = self::check_imported_comment_lookup( $ctx->fork( 'comment-lookup' ) );
			$rows[] = self::check_importer_get_page_http_wrapper( $ctx->fork( 'importer-get-page' ) );
			$rows[] = self::check_importer_base_helpers( $ctx->fork( 'importer-base-helpers' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'import-diff.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'import-diff.global-state-restored',
			self::state_matches( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot['globals'] ) )
		);

		return $rows;
	}

	public static function importer_callback(): void {}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'Text_Diff', 'WP_Error', 'WP_Importer', 'WP_Post', 'WP_Text_Diff_Renderer_Table' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'get_importers',
				'get_post',
				'has_filter',
				'remove_filter',
				'register_importer',
				'wp_safe_remote_request',
				'wp_delete_attachment',
				'wp_import_cleanup',
				'wp_import_handle_upload',
				'wp_import_upload_form',
				'wp_text_diff',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_importer_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_importers;

		$failures     = array();
		$wp_importers = array();
		$callback     = array( __CLASS__, 'importer_callback' );
		$registered   = array(
			'component-fuzz-beta-10' => array(
				'Beta 10 ' . $ctx->identifier( 3, 6 ),
				'Imports beta-ten data & checks sorting.',
				$callback,
			),
			'component-fuzz-alpha-2' => array(
				'alpha 2 ' . $ctx->identifier( 3, 6 ),
				'Imports alpha-two data.',
				$callback,
			),
			'component-fuzz-alpha-10' => array(
				'Alpha 10 ' . $ctx->identifier( 3, 6 ),
				'Imports alpha-ten data.',
				$callback,
			),
			'component-fuzz-zeta' => array(
				'Zeta ' . $ctx->identifier( 3, 6 ),
				'Imports zeta data.',
				$callback,
			),
		);

		foreach ( $registered as $id => $entry ) {
			$result = \register_importer( $id, $entry[0], $entry[1], $entry[2] );
			self::collect_failure(
				$failures,
				null === $result,
				'register_importer returns void for valid callbacks',
				array( 'id' => $id, 'result' => self::describe_value( $result ) )
			);
		}

		$error      = new \WP_Error( 'component_fuzz_importer_unavailable', 'Importer unavailable.' );
		$error_out  = \register_importer( 'component-fuzz-error', 'Error', 'Should not register.', $error );
		$importers  = \get_importers();
		$expected   = $registered;
		$sort_names = $expected;
		uasort( $sort_names, '_usort_by_first_member' );

		self::collect_failure(
			$failures,
			$error === $error_out
				&& ! isset( $importers['component-fuzz-error'] )
				&& array_keys( $sort_names ) === array_keys( $importers )
				&& self::importer_entries_match( $expected, $importers ),
			'importer registry stores valid importers, preserves callbacks, sorts by name, and rejects WP_Error callbacks',
			array(
				'expectedOrder' => array_keys( $sort_names ),
				'actualOrder'   => array_keys( is_array( $importers ) ? $importers : array() ),
				'errorOut'      => self::describe_value( $error_out ),
				'importers'     => self::describe_value( $importers ),
			)
		);

		return self::result( $ctx, 'import-diff.importer-registry.sorting-and-error-callbacks', $failures );
	}

	private static function check_import_upload_form( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$limit          = 1024 * $ctx->int( 2, 256 );
		$limit_observed = array();
		$limit_filter   = static function ( int $bytes ) use ( $limit, &$limit_observed ): int {
			$limit_observed[] = $bytes;
			return $limit;
		};

		$action = 'admin.php?import=component-fuzz&step=' . rawurlencode( $ctx->identifier( 3, 10 ) ) . '&bad=<script>alert(1)</script>';

		\add_filter( 'import_upload_size_limit', $limit_filter );
		try {
			ob_start();
			\wp_import_upload_form( $action );
			$output = ob_get_clean();
		} finally {
			\remove_filter( 'import_upload_size_limit', $limit_filter );
			if ( ob_get_level() > 0 && false === isset( $output ) ) {
				ob_end_clean();
			}
		}

		self::collect_failure(
			$failures,
			isset( $output )
				&& '' !== $output
				&& array() !== $limit_observed
				&& str_contains( $output, 'id="import-upload-form"' )
				&& str_contains( $output, 'enctype="multipart/form-data"' )
				&& str_contains( $output, 'name="import"' )
				&& str_contains( $output, 'name="action" value="save"' )
				&& str_contains( $output, '_wpnonce=' )
				&& ! str_contains( $output, '<script>' )
				&& ! str_contains( $output, '</script>' ),
			'import upload form applies size filters, emits expected controls, and escapes action data',
			array(
				'action'        => self::preview( $action ),
				'limit'         => $limit,
				'limitObserved' => $limit_observed,
				'output'        => self::preview( $output ?? '' ),
			)
		);

		return self::result( $ctx, 'import-diff.import-upload-form.controls-size-and-escaping', $failures );
	}

	private static function check_import_upload_handler_and_cleanup( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'component_fuzz_content_counts' ) ) {
			return $ctx->skip(
				'import-diff.import-upload-handler.fail-closed-and-cleanup',
				'The in-memory wpdb content stub is unavailable.'
			);
		}

		$before_counts = $wpdb->component_fuzz_content_counts();
		if ( array_sum( $before_counts ) !== 0 ) {
			return $ctx->skip(
				'import-diff.import-upload-handler.fail-closed-and-cleanup',
				'The content stub was not empty before the import upload handler case.',
				array( 'counts' => $before_counts )
			);
		}

		$failures          = array();
		$token             = $ctx->identifier( 5, 10 );
		$temp_dir          = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-import-upload-' . getmypid() . '-' . $token;
		$tmp_file          = $temp_dir . DIRECTORY_SEPARATOR . 'incoming-' . $token . '.xml';
		$attachment_file   = $temp_dir . DIRECTORY_SEPARATOR . 'cleanup-' . $token . '.txt';
		$superglobal_state = array(
			'_FILES'   => $_FILES,
			'_POST'    => $_POST,
			'_REQUEST' => $_REQUEST,
		);
		$events            = array(
			'prefilter' => array(),
			'overrides' => array(),
			'handler'   => array(),
			'handle'    => array(),
			'move'      => array(),
		);
		$prefilter         = static function ( array $file ) use ( &$events, $token ): array {
			$events['prefilter'][] = array(
				'name'  => $file['name'] ?? null,
				'size'  => $file['size'] ?? null,
				'error' => $file['error'] ?? null,
			);
			$file['error'] = 'component fuzz blocked import upload ' . $token;
			return $file;
		};
		$overrides_filter  = static function ( $overrides, array $file ) use ( &$events ): array {
			$events['overrides'][] = array(
				'overrides' => $overrides,
				'name'      => $file['name'] ?? null,
				'error'     => $file['error'] ?? null,
			);

			$overrides                         = is_array( $overrides ) ? $overrides : array();
			$overrides['upload_error_handler'] = static function ( array &$file, string $message ) use ( &$events ): array {
				$events['handler'][] = array(
					'name'    => $file['name'] ?? null,
					'message' => $message,
				);

				return array(
					'error' => $message,
					'name'  => $file['name'] ?? null,
					'size'  => $file['size'] ?? null,
				);
			};
			return $overrides;
		};
		$handle_filter     = static function ( array $upload, string $context ) use ( &$events ): array {
			$events['handle'][] = array(
				'upload'  => $upload,
				'context' => $context,
			);
			return $upload;
		};
		$move_filter       = static function ( $move, array $file, string $new_file, string $type ) use ( &$events ) {
			$events['move'][] = array(
				'name'    => $file['name'] ?? null,
				'newFile' => $new_file,
				'type'    => $type,
			);
			return $move;
		};
		$filters_after     = array();

		\add_filter( 'wp_handle_upload_prefilter', $prefilter );
		\add_filter( 'wp_handle_upload_overrides', $overrides_filter, 10, 2 );
		\add_filter( 'wp_handle_upload', $handle_filter, 10, 2 );
		\add_filter( 'pre_move_uploaded_file', $move_filter, 10, 4 );

		try {
			if ( ! is_dir( $temp_dir ) ) {
				mkdir( $temp_dir, 0777, true );
			}

			unset( $_FILES['import'] );
			$missing_upload = \wp_import_handle_upload();

			self::collect_failure(
				$failures,
				is_array( $missing_upload )
					&& isset( $missing_upload['error'] )
					&& is_string( $missing_upload['error'] )
					&& str_contains( $missing_upload['error'], 'post_max_size' )
					&& array() === $events['prefilter']
					&& $before_counts === $wpdb->component_fuzz_content_counts(),
				'wp_import_handle_upload fails closed before upload hooks or DB writes when the import file is missing',
				array(
					'missingUpload' => self::describe_value( $missing_upload ),
					'events'        => $events,
					'counts'        => $wpdb->component_fuzz_content_counts(),
				)
			);

			file_put_contents( $tmp_file, "component import fuzz {$token}\n<item>data</item>\n" );
			$_POST    = array( 'action' => 'unexpected-action' );
			$_REQUEST = $_POST;
			$_FILES   = array(
				'import' => array(
					'name'     => 'component-import-' . $token . '.xml',
					'type'     => 'text/xml',
					'tmp_name' => $tmp_file,
					'error'    => 0,
					'size'     => filesize( $tmp_file ),
				),
			);

			$blocked_upload     = \wp_import_handle_upload();
			$counts_after_block = $wpdb->component_fuzz_content_counts();

			self::collect_failure(
				$failures,
				is_array( $blocked_upload )
					&& 'component fuzz blocked import upload ' . $token === ( $blocked_upload['error'] ?? null )
					&& 'component-import-' . $token . '.xml.txt' === ( $blocked_upload['name'] ?? null )
					&& array( 'test_form' => false, 'test_type' => false ) === ( $events['overrides'][0]['overrides'] ?? null )
					&& 1 === count( $events['prefilter'] )
					&& 1 === count( $events['overrides'] )
					&& 1 === count( $events['handler'] )
					&& array() === $events['handle']
					&& array() === $events['move']
					&& 'component-import-' . $token . '.xml.txt' === ( $_FILES['import']['name'] ?? null )
					&& $before_counts === $counts_after_block,
				'wp_import_handle_upload appends .txt, passes import overrides to wp_handle_upload, and returns prefiltered upload errors without move or attachment effects',
				array(
					'blockedUpload'    => self::describe_value( $blocked_upload ),
					'events'           => $events,
					'filesNameAfter'   => $_FILES['import']['name'] ?? null,
					'countsAfterBlock' => $counts_after_block,
				)
			);

			file_put_contents( $attachment_file, 'component import cleanup ' . $token );
			$attachment_id     = self::insert_import_attachment( $attachment_file, $token );
			$before_cleanup    = is_int( $attachment_id ) ? \get_post( $attachment_id ) : null;
			$cleanup_return    = is_int( $attachment_id ) ? \wp_import_cleanup( $attachment_id ) : null;
			$after_cleanup     = is_int( $attachment_id ) ? \get_post( $attachment_id ) : null;
			$cleanup_completed = ! $after_cleanup || ( $after_cleanup instanceof \WP_Post && 'private' !== $after_cleanup->post_status );

			self::collect_failure(
				$failures,
				is_int( $attachment_id )
					&& $attachment_id > 0
					&& $before_cleanup instanceof \WP_Post
					&& 'attachment' === $before_cleanup->post_type
					&& null === $cleanup_return
					&& $cleanup_completed,
				'wp_import_cleanup delegates to attachment deletion and does not leave the generated import attachment live',
				array(
					'attachmentId'     => $attachment_id,
					'beforeCleanup'    => $before_cleanup instanceof \WP_Post ? array( 'type' => $before_cleanup->post_type, 'status' => $before_cleanup->post_status ) : self::describe_value( $before_cleanup ),
					'cleanupReturn'    => self::describe_value( $cleanup_return ),
					'afterCleanup'     => $after_cleanup instanceof \WP_Post ? array( 'type' => $after_cleanup->post_type, 'status' => $after_cleanup->post_status ) : self::describe_value( $after_cleanup ),
					'countsAfterClean' => $wpdb->component_fuzz_content_counts(),
				)
			);
		} finally {
			\remove_filter( 'pre_move_uploaded_file', $move_filter, 10 );
			\remove_filter( 'wp_handle_upload', $handle_filter, 10 );
			\remove_filter( 'wp_handle_upload_overrides', $overrides_filter, 10 );
			\remove_filter( 'wp_handle_upload_prefilter', $prefilter, 10 );
			$filters_after = array(
				'prefilter' => \has_filter( 'wp_handle_upload_prefilter', $prefilter ),
				'overrides' => \has_filter( 'wp_handle_upload_overrides', $overrides_filter ),
				'handle'    => \has_filter( 'wp_handle_upload', $handle_filter ),
				'move'      => \has_filter( 'pre_move_uploaded_file', $move_filter ),
			);

			$_FILES   = $superglobal_state['_FILES'];
			$_POST    = $superglobal_state['_POST'];
			$_REQUEST = $superglobal_state['_REQUEST'];
			$wpdb->component_fuzz_reset_content();
			self::delete_tree( $temp_dir );
		}

		self::collect_failure(
			$failures,
			array( 'prefilter' => false, 'overrides' => false, 'handle' => false, 'move' => false ) === $filters_after,
			'import upload handler filters are removed after the upload and cleanup checks',
			array( 'filtersAfter' => $filters_after )
		);

		return self::result( $ctx, 'import-diff.import-upload-handler.fail-closed-and-cleanup', $failures );
	}

	private static function check_text_diff_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = $ctx->identifier( 3, 10 );
		$left     = "common <tag>& {$token}\nremoved {$token}\nkept";
		$right    = "common <tag>& {$token}\nadded & changed {$token}\nkept";
		$split    = \wp_text_diff(
			$left,
			$right,
			array(
				'title'           => 'Component Fuzz Diff',
				'title_left'      => 'Before',
				'title_right'     => 'After',
				'show_split_view' => true,
			)
		);
		$unified  = \wp_text_diff(
			$left,
			$right,
			array(
				'title_left'      => 'Before',
				'title_right'     => 'After',
				'show_split_view' => false,
			)
		);
		$same     = \wp_text_diff( "same\r\nwhitespace\tcase", "same\nwhitespace case" );

		self::collect_failure(
			$failures,
			is_string( $split )
				&& str_contains( $split, "<table class='diff is-split-view'>" )
				&& str_contains( $split, "class='diff-deletedline'" )
				&& str_contains( $split, "class='diff-addedline'" )
				&& str_contains( $split, 'common &lt;tag&gt;&amp; ' . $token )
				&& str_contains( $split, 'added &amp; changed' )
				&& str_contains( $split, $token )
				&& ! str_contains( $split, 'common <tag>& ' . $token ),
			'wp_text_diff split view escapes changed content and marks added/deleted lines',
			array( 'split' => self::preview( $split ) )
		);
		self::collect_failure(
			$failures,
			is_string( $unified )
				&& str_contains( $unified, "<table class='diff'>" )
				&& ! str_contains( $unified, 'is-split-view' )
				&& str_contains( $unified, 'Before' )
				&& ! str_contains( $unified, '<th>After</th>' ),
			'wp_text_diff unified view omits split class and right-side header',
			array( 'unified' => self::preview( $unified ) )
		);
		self::collect_failure(
			$failures,
			'' === $same,
			'wp_text_diff returns an empty string after normalizing equivalent whitespace',
			array( 'same' => self::preview( $same ) )
		);

		return self::result( $ctx, 'import-diff.text-diff.rendering-and-normalization', $failures );
	}

	private static function check_error_export_and_merge( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$source   = new \WP_Error();
		$target   = new \WP_Error( 'existing', 'Existing target message.', array( 'kept' => true ) );
		$merged   = new \WP_Error( 'merged', 'Existing merge message.' );
		$code     = 'component_fuzz_' . $ctx->identifier( 4, 10 );
		$message  = 'Message <' . $ctx->identifier( 3, 7 ) . '>';
		$data_one = array(
			'seed'  => $ctx->seed(),
			'token' => $ctx->identifier( 4, 10 ),
		);
		$data_two = array(
			'next' => $ctx->identifier( 4, 10 ),
		);

		$source->add( $code, $message );
		$source->add_data( $data_one, $code );
		$source->add_data( $data_two, $code );
		$source->export_to( $target );
		$merged->merge_from( $source );

		self::collect_failure(
			$failures,
			in_array( 'existing', $target->get_error_codes(), true )
				&& in_array( $code, $target->get_error_codes(), true )
				&& array( $message ) === $target->get_error_messages( $code )
				&& array( $data_one, $data_two ) === $target->get_all_error_data( $code )
				&& array( $message ) === $merged->get_error_messages( $code )
				&& array( $data_one, $data_two ) === $merged->get_all_error_data( $code ),
			'WP_Error export_to and merge_from copy messages and all error data without dropping existing target errors',
			array(
				'code'   => $code,
				'target' => self::describe_error( $target ),
				'merged' => self::describe_error( $merged ),
			)
		);

		$target->remove( $code );
		self::collect_failure(
			$failures,
			! in_array( $code, $target->get_error_codes(), true )
				&& array() === $target->get_all_error_data( $code )
				&& in_array( 'existing', $target->get_error_codes(), true ),
			'WP_Error::remove deletes copied messages and data for one code only',
			array( 'target' => self::describe_error( $target ) )
		);

		return self::result( $ctx, 'import-diff.wp-error.export-merge-and-remove', $failures );
	}

	private static function check_error_lifecycle_ordering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$code_a       = 'component_fuzz_a_' . $ctx->identifier( 4, 10 );
		$code_b       = 'component_fuzz_b_' . $ctx->identifier( 4, 10 );
		$message_a    = 'Primary message ' . $ctx->identifier( 3, 7 );
		$message_a_2  = 'Secondary message ' . $ctx->identifier( 3, 7 );
		$message_b    = 'Other code message ' . $ctx->identifier( 3, 7 );
		$data_a       = array( 'phase' => 'construct', 'seed' => $ctx->seed() );
		$data_a_2     = array( 'phase' => 'add', 'token' => $ctx->identifier( 4, 9 ) );
		$data_default = array( 'phase' => 'default-code', 'iteration' => $ctx->iteration() );
		$data_b       = array( 'phase' => 'second-code', 'flag' => true );
		$error        = new \WP_Error( $code_a, $message_a, $data_a );

		$error->add( $code_a, $message_a_2, $data_a_2 );
		$error->add_data( $data_default );
		$error->add( $code_b, $message_b, $data_b );

		self::collect_failure(
			$failures,
			array( $code_a, $code_b ) === $error->get_error_codes()
				&& $code_a === $error->get_error_code()
				&& array( $message_a, $message_a_2 ) === $error->get_error_messages( $code_a )
				&& array( $message_b ) === $error->get_error_messages( $code_b )
				&& array( $message_a, $message_a_2, $message_b ) === $error->get_error_messages()
				&& $message_a === $error->get_error_message()
				&& $message_b === $error->get_error_message( $code_b )
				&& $data_default === $error->get_error_data( $code_a )
				&& $data_b === $error->get_error_data( $code_b )
				&& array( $data_a, $data_a_2, $data_default ) === $error->get_all_error_data( $code_a )
				&& array( $data_b ) === $error->get_all_error_data( $code_b )
				&& $error->has_errors(),
			'WP_Error preserves code order, message order, newest data, and all-data history across add/add_data',
			array(
				'codeA' => $code_a,
				'codeB' => $code_b,
				'error' => self::describe_error( $error ),
			)
		);

		$copy = new \WP_Error();
		$error->export_to( $copy );
		$error->remove( $code_a );

		self::collect_failure(
			$failures,
			array( $code_b ) === $error->get_error_codes()
				&& $code_b === $error->get_error_code()
				&& array() === $error->get_error_messages( $code_a )
				&& array() === $error->get_all_error_data( $code_a )
				&& $message_b === $error->get_error_message()
				&& array( $code_a, $code_b ) === $copy->get_error_codes()
				&& array( $data_a, $data_a_2, $data_default ) === $copy->get_all_error_data( $code_a )
				&& array( $data_b ) === $copy->get_all_error_data( $code_b ),
			'WP_Error::remove promotes the remaining first code and export_to creates an independent copy',
			array(
				'removed' => self::describe_error( $error ),
				'copy'    => self::describe_error( $copy ),
			)
		);

		return self::result( $ctx, 'import-diff.wp-error.lifecycle-ordering-and-default-code', $failures );
	}

	private static function check_imported_post_lookup( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'component_fuzz_content_counts' ) ) {
			return $ctx->skip(
				'import-diff.importer.imported-post-lookup',
				'The in-memory wpdb content stub is unavailable.'
			);
		}

		$before = $wpdb->component_fuzz_content_counts();
		if ( array_sum( $before ) !== 0 ) {
			return $ctx->skip(
				'import-diff.importer.imported-post-lookup',
				'The content stub was not empty before the importer post lookup case.',
				array( 'counts' => $before )
			);
		}

		$failures      = array();
		$importer_name = 'component_fuzz_' . $ctx->identifier( 4, 9 );
		$blog_id       = (string) $ctx->int( 2, 20 );
		$other_blog_id = (string) ( (int) $blog_id + 200 );
		$expected      = array();
		$matching_ids  = array();

		try {
			for ( $i = 0; $i < 105; ++$i ) {
				$permalink      = 'https://example.test/imported/' . $i . '-' . rawurlencode( $ctx->identifier( 4, 10 ) );
				$matching_ids[] = self::insert_imported_post_meta( $importer_name, $blog_id, $permalink, 'match-' . $i );
				$expected[ $permalink ] = end( $matching_ids );
			}

			$duplicate_permalink              = array_key_first( $expected );
			$duplicate_id                     = self::insert_imported_post_meta( $importer_name, $blog_id, (string) $duplicate_permalink, 'duplicate' );
			$expected[ $duplicate_permalink ] = $duplicate_id;
			$other_permalink                  = 'https://other.test/imported/' . rawurlencode( $ctx->identifier( 4, 10 ) );
			$other_id                         = self::insert_imported_post_meta( $importer_name, $other_blog_id, $other_permalink, 'other-blog' );
			self::insert_imported_post_meta( $importer_name . '_other', $blog_id, (string) $duplicate_permalink, 'other-importer' );

			$importer = new \WP_Importer();
			$lookup   = $importer->get_imported_posts( $importer_name, $blog_id );
			$lookup_query = (string) $wpdb->last_query;
			$count    = $importer->count_imported_posts( $importer_name, $blog_id );
			$other    = $importer->get_imported_posts( $importer_name, $other_blog_id );

			self::collect_failure(
				$failures,
				$expected === $lookup
					&& 106 === $count
					&& array( $other_permalink => $other_id ) === $other
					&& str_contains( $lookup_query, 'LIMIT 100,100' ),
				'WP_Importer maps imported post permalinks to local post IDs by importer/blog meta key across chunks',
				array(
					'blogId'       => $blog_id,
					'importerName' => $importer_name,
					'lookupCount'  => count( $lookup ),
					'lookupQuery'  => $lookup_query,
					'count'        => $count,
					'expectedCount' => count( $expected ),
					'otherLookup'  => $other,
				)
			);
		} finally {
			$wpdb->component_fuzz_reset_content();
		}

		return self::result( $ctx, 'import-diff.importer.imported-post-lookup', $failures );
	}

	private static function check_imported_comment_lookup( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'component_fuzz_content_counts' ) ) {
			return $ctx->skip(
				'import-diff.importer.imported-comment-lookup',
				'The in-memory wpdb content stub is unavailable.'
			);
		}

		$before = $wpdb->component_fuzz_content_counts();
		if ( array_sum( $before ) !== 0 ) {
			return $ctx->skip(
				'import-diff.importer.imported-comment-lookup',
				'The content stub was not empty before the importer lookup case.',
				array( 'counts' => $before )
			);
		}

		$failures = array();
		$blog_id  = $ctx->int( 2, 20 );
		$source_a = $ctx->int( 100, 999 );
		$source_b = $source_a + $ctx->int( 1, 20 );
		$other    = $blog_id + 100;
		$ids      = array();

		try {
			$ids[] = self::insert_comment_agent( $blog_id . '-' . $source_a, 'first' );
			$ids[] = self::insert_comment_agent( $blog_id . '-' . $source_b, 'second' );
			$ids[] = self::insert_comment_agent( $other . '-' . $source_a, 'other-blog' );

			$importer = new \WP_Importer();
			$lookup   = $importer->get_imported_comments( (string) $blog_id );

			self::collect_failure(
				$failures,
				array(
					$source_a => $ids[0],
					$source_b => $ids[1],
				) === $lookup,
				'WP_Importer maps source comment IDs to local comment IDs for the selected blog only',
				array(
					'blogId' => $blog_id,
					'ids'    => $ids,
					'lookup' => $lookup,
				)
			);
		} finally {
			$wpdb->component_fuzz_reset_content();
		}

		return self::result( $ctx, 'import-diff.importer.imported-comment-lookup', $failures );
	}

	private static function check_importer_get_page_http_wrapper( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$importer = new \WP_Importer();
		$username = 'user-' . $ctx->identifier( 3, 8 );
		$password = 'pass:' . $ctx->identifier( 4, 10 );
		$token    = $ctx->identifier( 4, 10 );
		$calls    = array();

		$fake_response = static function ( string $label ) use ( $token ): array {
			return array(
				'headers'  => array( 'x-component-fuzz' => $label ),
				'body'     => 'component-fuzz-importer-' . $label . '-' . $token,
				'response' => array(
					'code'    => 200,
					'message' => 'OK ' . $label,
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		$http_filter   = static function ( $preempt, array $parsed_args, string $url ) use ( &$calls, $fake_response ) {
			$label   = 'call-' . count( $calls );
			$calls[] = array(
				'url'  => $url,
				'args' => $parsed_args,
			);

			return $fake_response( $label );
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		try {
			$default_url       = 'https://imports.example.test/' . rawurlencode( $token ) . '/data.xml?view=' . rawurlencode( 'full & safe' );
			$head_url          = 'https://imports.example.test/' . rawurlencode( $token ) . '/head.xml';
			$auth_url          = 'https://imports.example.test/' . rawurlencode( $token ) . '/auth.xml';
			$username_only_url = 'https://imports.example.test/' . rawurlencode( $token ) . '/username-only.xml';
			$password_only_url = 'https://imports.example.test/' . rawurlencode( $token ) . '/password-only.xml';

			$default_response       = $importer->get_page( $default_url );
			$head_response          = $importer->get_page( $head_url, '', '', true );
			$auth_response          = $importer->get_page( $auth_url, $username, $password );
			$username_only_response = $importer->get_page( $username_only_url, $username, '' );
			$password_only_response = $importer->get_page( $password_only_url, '', $password );
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			\remove_filter( 'http_request_timeout', array( $importer, 'bump_request_timeout' ) );
		}

		$expected_responses = array(
			$fake_response( 'call-0' ),
			$fake_response( 'call-1' ),
			$fake_response( 'call-2' ),
			$fake_response( 'call-3' ),
			$fake_response( 'call-4' ),
		);
		$responses          = array(
			$default_response,
			$head_response,
			$auth_response,
			$username_only_response,
			$password_only_response,
		);
		$auth_header        = 'Basic ' . base64_encode( "{$username}:{$password}" );

		self::collect_failure(
			$failures,
			$expected_responses === $responses
				&& 5 === count( $calls ),
			'WP_Importer::get_page returns exact pre_http_request responses without falling through to live HTTP',
			array(
				'responses' => $responses,
				'calls'     => $calls,
			)
		);

		self::collect_failure(
			$failures,
			isset( $calls[0]['args'], $calls[1]['args'], $calls[2]['args'], $calls[3]['args'], $calls[4]['args'] )
				&& 'GET' === ( $calls[0]['args']['method'] ?? null )
				&& 'HEAD' === ( $calls[1]['args']['method'] ?? null )
				&& 60 === ( $calls[0]['args']['timeout'] ?? null )
				&& 60 === ( $calls[1]['args']['timeout'] ?? null )
				&& true === ( $calls[0]['args']['reject_unsafe_urls'] ?? null )
				&& true === ( $calls[1]['args']['reject_unsafe_urls'] ?? null )
				&& 0 === ( $calls[1]['args']['redirection'] ?? null )
				&& array() === ( $calls[0]['args']['headers'] ?? null )
				&& $auth_header === ( $calls[2]['args']['headers']['Authorization'] ?? null )
				&& ! isset( $calls[3]['args']['headers']['Authorization'] )
				&& ! isset( $calls[4]['args']['headers']['Authorization'] ),
			'WP_Importer::get_page composes method, timeout, safe URL, HEAD redirection, and Basic Auth wrapper args',
			array(
				'calls'      => $calls,
				'authHeader' => $auth_header,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_http_request', $http_filter, 10 )
				&& false === \has_filter( 'http_request_timeout', array( $importer, 'bump_request_timeout' ) ),
			'WP_Importer::get_page HTTP wrapper test removes pre_http_request and importer timeout filters',
			array(
				'preHttp' => \has_filter( 'pre_http_request', $http_filter, 10 ),
				'timeout' => \has_filter( 'http_request_timeout', array( $importer, 'bump_request_timeout' ) ),
			)
		);

		return self::result( $ctx, 'import-diff.importer.get-page-http-wrapper', $failures );
	}

	private static function check_importer_base_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb, $wp_actions;

		$failures       = array();
		$importer       = new \WP_Importer();
		$short          = 'a' . $ctx->identifier( 2, 3 );
		$medium         = 'mid-' . $ctx->identifier( 4, 7 );
		$long           = 'long-' . $ctx->identifier( 8, 14 );
		$unicode        = "gr\xC3\xA5-" . $ctx->identifier( 2, 5 );
		$space_token    = $ctx->identifier( 3, 6 );
		$spaces         = "alpha\t" . $space_token . "\n\n beta  \r gamma";
		$sorted         = array( $medium, $long, $short, $unicode );
		$expected_order = $sorted;
		usort(
			$expected_order,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		usort( $sorted, array( $importer, 'cmpr_strlen' ) );

		self::collect_failure(
			$failures,
			$expected_order === $sorted
				&& 0 === $importer->cmpr_strlen( 'aa', 'bb' )
				&& $importer->cmpr_strlen( $short, $long ) > 0
				&& $importer->cmpr_strlen( $long, $short ) < 0,
			'WP_Importer::cmpr_strlen orders generated strings by descending byte length and treats equal byte lengths as equal',
			array(
				'expected' => $expected_order,
				'actual'   => $sorted,
				'lengths'  => array_map( 'strlen', $sorted ),
			)
		);

		self::collect_failure(
			$failures,
			'alpha ' . $space_token . ' beta gamma' === $importer->min_whitespace( $spaces ),
			'WP_Importer::min_whitespace collapses generated tabs, newlines, carriage returns, and repeated spaces',
			array(
				'input'  => self::preview( $spaces ),
				'output' => self::preview( (string) $importer->min_whitespace( $spaces ) ),
			)
		);

		$timeout_inputs  = array( $ctx->int( 1, 30 ), 60, $ctx->int( 61, 120 ) );
		$timeout_results = array();
		foreach ( $timeout_inputs as $timeout_input ) {
			$timeout_results[] = $importer->bump_request_timeout( $timeout_input );
		}

		self::collect_failure(
			$failures,
			array( 60, 60, 60 ) === $timeout_results,
			'WP_Importer::bump_request_timeout always returns the importer timeout for below, equal, and above-threshold inputs',
			array(
				'inputs'  => $timeout_inputs,
				'results' => $timeout_results,
			)
		);

		$quota_default = $importer->is_user_over_quota();
		$quota_output  = '';
		$quota_true    = null;
		$option_filter = static function ( $pre, string $option ) {
			if ( 'blog_upload_space' === $option ) {
				return 1;
			}
			if ( 'upload_space_check_disabled' === $option ) {
				return 0;
			}

			return $pre;
		};
		$allowed_filter = static function () {
			return 1;
		};
		$used_filter    = static function () {
			return 2;
		};

		\add_filter( 'pre_option_blog_upload_space', $allowed_filter );
		\add_filter( 'pre_site_option', $option_filter, 10, 2 );
		\add_filter( 'pre_get_space_used', $used_filter );
		$buffer_level = ob_get_level();
		try {
			ob_start();
			$quota_true   = $importer->is_user_over_quota();
			$quota_output = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			\remove_filter( 'pre_option_blog_upload_space', $allowed_filter );
			\remove_filter( 'pre_site_option', $option_filter, 10 );
			\remove_filter( 'pre_get_space_used', $used_filter );
		}

		self::collect_failure(
			$failures,
			false === $quota_default
				&& true === $quota_true
				&& '' !== $quota_output
				&& false === \has_filter( 'pre_option_blog_upload_space', $allowed_filter )
				&& false === \has_filter( 'pre_site_option', $option_filter )
				&& false === \has_filter( 'pre_get_space_used', $used_filter ),
			'WP_Importer::is_user_over_quota delegates to upload quota helpers for both default false and filtered true branches',
			array(
				'default' => $quota_default,
				'true'    => $quota_true,
				'output'  => self::preview( $quota_output ),
			)
		);

		$previous_wpdb = $wpdb ?? null;
		$had_wpdb      = isset( $wpdb );
		$wpdb          = new class() {
			public $queries = array();
		};
		$wpdb->queries = array(
			array( 'SELECT component fuzz', 0.1, 'component-fuzz' ),
		);
		$wp_actions = array(
			'component_fuzz_import_action' => $ctx->int( 1, 5 ),
		);

		try {
			$importer->stop_the_insanity();

			self::collect_failure(
				$failures,
				array() === $wpdb->queries
					&& array() === $wp_actions,
				'WP_Importer::stop_the_insanity clears accumulated query logs and action counters',
				array(
					'queries'   => $wpdb->queries,
					'wpActions' => $wp_actions,
				)
			);
		} finally {
			if ( $had_wpdb ) {
				$wpdb = $previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}

		self::collect_failure(
			$failures,
			$had_wpdb ? $wpdb === $previous_wpdb : ! isset( $wpdb ),
			'temporary WPDB stand-in is restored before importer helper case returns',
			array(
				'hadWpdb'  => $had_wpdb,
				'restored' => $had_wpdb ? $wpdb === $previous_wpdb : ! isset( $wpdb ),
			)
		);

		return self::result( $ctx, 'import-diff.importer.base-helper-contracts', $failures );
	}

	private static function insert_imported_post_meta( string $importer_name, string $blog_id, string $permalink, string $label ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => 0,
				'post_date'             => '2026-06-24 00:00:00',
				'post_date_gmt'         => '2026-06-24 00:00:00',
				'post_content'          => 'Imported post ' . $label,
				'post_title'            => 'Imported ' . $label,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'imported-' . $label,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-24 00:00:00',
				'post_modified_gmt'     => '2026-06-24 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => $permalink,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => 0,
			)
		);
		$post_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $importer_name . '_' . $blog_id . '_permalink',
				'meta_value' => $permalink,
			)
		);

		return $post_id;
	}

	private static function insert_import_attachment( string $file, string $token ): int {
		global $wpdb;

		$url = 'https://example.test/imports/' . rawurlencode( basename( $file ) );
		$wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => 0,
				'post_date'             => '2026-06-30 12:00:00',
				'post_date_gmt'         => '2026-06-30 12:00:00',
				'post_content'          => $url,
				'post_title'            => basename( $file ),
				'post_excerpt'          => '',
				'post_status'           => 'private',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'component-import-cleanup-' . $token,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-30 12:00:00',
				'post_modified_gmt'     => '2026-06-30 12:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => $url,
				'menu_order'            => 0,
				'post_type'             => 'attachment',
				'post_mime_type'        => 'text/plain',
				'comment_count'         => 0,
			)
		);
		$post_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => '_wp_attached_file',
				'meta_value' => $file,
			)
		);

		return $post_id;
	}

	private static function insert_comment_agent( string $comment_agent, string $label ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->comments,
			array(
				'comment_post_ID'      => 0,
				'comment_author'       => 'Component Fuzz ' . $label,
				'comment_author_email' => 'comment-' . $label . '@example.test',
				'comment_author_url'   => '',
				'comment_author_IP'    => '192.0.2.55',
				'comment_date'         => '2026-06-23 00:00:00',
				'comment_date_gmt'     => '2026-06-23 00:00:00',
				'comment_content'      => 'Imported comment ' . $label,
				'comment_karma'        => 0,
				'comment_approved'     => '1',
				'comment_agent'        => $comment_agent,
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			)
		);

		return (int) $wpdb->insert_id;
	}

	private static function importer_entries_match( array $expected, $actual ): bool {
		if ( ! is_array( $actual ) ) {
			return false;
		}

		foreach ( $expected as $id => $entry ) {
			if ( ! isset( $actual[ $id ] ) || $entry !== $actual[ $id ] ) {
				return false;
			}
		}

		return true;
	}

	private static function reset_runtime(): void {
		$GLOBALS['wp_importers'] = array();
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_importers',
				'wp_object_cache',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'globals' => $globals,
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
		}

		return true;
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $details = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'details' => $details,
			);
		}
	}

	private static function describe_error( \WP_Error $error ): array {
		$out = array();
		foreach ( $error->get_error_codes() as $code ) {
			$out[ $code ] = array(
				'messages' => $error->get_error_messages( $code ),
				'data'     => $error->get_all_error_data( $code ),
			);
		}
		return $out;
	}

	private static function describe_value( $value ) {
		if ( $value instanceof \WP_Error ) {
			return self::describe_error( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}
		return $value;
	}

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES ),
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

	private static function delete_tree( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = scandir( $path );
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				self::delete_tree( $path . DIRECTORY_SEPARATOR . $item );
			}
		}

		@rmdir( $path );
	}
}
