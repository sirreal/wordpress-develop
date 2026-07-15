<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes REST media attachment upload and post-processing contracts.
 */
final class RestMediaAttachmentsSurface {
	public const NAME = 'rest-media-attachments';

	private static ?string $upload_root = null;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'rest-media-attachments.bootstrap-apis-available',
					'Required WordPress REST media attachment APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot     = self::snapshot_state();
		$temp_root    = self::make_temp_root( $ctx );
		$cleanup_ok   = null === $temp_root;
		$cleanup_path = $temp_root;
		$rows         = array();

		try {
			if ( null === $temp_root ) {
				$rows[] = $ctx->skip(
					'rest-media-attachments.temp-root.available',
					'Could not create an isolated temporary upload directory.',
					array( 'sysTempDir' => sys_get_temp_dir() )
				);
			} else {
				self::prepare_runtime( $temp_root );

				$case   = self::case_for_context( $ctx );
				$rows[] = self::check_disposition_parser_and_raw_errors( $ctx->fork( 'raw-errors' ), $case );
				$rows[] = self::check_create_item_raw_upload_success( $ctx->fork( 'create-raw' ), $case, $temp_root );
				$rows[] = self::check_create_item_url_sideload_success( $ctx->fork( 'create-url' ), $case, $temp_root );
				$rows[] = self::check_sideload_raw_upload_metadata( $ctx->fork( 'sideload-raw' ), $case, $temp_root );
				$rows[] = self::check_finalize_and_response_projection( $ctx->fork( 'finalize' ), $case, $temp_root );
				$rows[] = self::check_permission_and_edit_fail_closed_paths( $ctx->fork( 'permission-edit' ), $case, $temp_root );
				$rows[] = self::check_client_side_route_contracts( $ctx->fork( 'client-side-routes' ) );
			}
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'rest-media-attachments.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::$upload_root = null;
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
				$cleanup_ok = ! is_dir( $temp_root );
			}
			self::restore_state( $snapshot );
		}

		$restoration = self::restoration_probe( $snapshot );
		$rows[]      = $ctx->result(
			'rest-media-attachments.cleanup.temp-files-and-state',
			$cleanup_ok && $restoration['restored'],
			array(
				'tempRoot'    => $cleanup_path,
				'cleaned'     => $cleanup_ok,
				'restoration' => $restoration,
			)
		);

		return $rows;
	}

	public static function filter_upload_dir( array $uploads ): array {
		if ( null === self::$upload_root ) {
			return $uploads;
		}

		$subdir = isset( $uploads['subdir'] ) ? (string) $uploads['subdir'] : '';
		$base   = rtrim( self::$upload_root, '/\\' );
		$url    = 'http://example.test/component-fuzz-rest-media';

		$uploads['basedir'] = $base;
		$uploads['baseurl'] = $url;
		$uploads['path']    = $base . $subdir;
		$uploads['url']     = $url . $subdir;
		$uploads['error']   = false;

		return $uploads;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Error',
				'WP_Post',
				'WP_REST_Attachments_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Server',
				'WP_Rewrite',
				'WP_User',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'__return_empty_array',
				'__return_false',
				'add_filter',
				'add_action',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'get_attached_file',
				'get_post',
				'get_post_parent',
				'get_post_meta',
				'has_filter',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_get_route_for_post',
				'rest_url',
				'sanitize_file_name',
				'sanitize_text_field',
				'update_post_meta',
				'update_attached_file',
				'wp_basename',
				'wp_attachment_is',
				'wp_attachment_is_image',
				'wp_cache_flush',
				'wp_create_nonce',
				'wp_get_attachment_metadata',
				'wp_get_attachment_url',
				'wp_get_original_image_path',
				'wp_getimagesize',
				'wp_filesize',
				'wp_insert_attachment',
				'wp_insert_post',
				'wp_insert_user',
				'wp_is_client_side_media_processing_enabled',
				'wp_set_current_user',
				'wp_slash',
				'wp_update_attachment_metadata',
				'wp_upload_dir',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global $wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_disposition_parser_and_raw_errors( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_content_runtime();

		$controller = self::attachments_probe();
		$failures   = array();
		$before     = self::content_counts();
		$headers    = array(
			'content_type'        => array( 'text/plain' ),
			'content_disposition' => array( 'attachment; filename="' . $case['uploadFile'] . '"' ),
		);

		$parser_cases = array(
			'no-semicolon'      => array( array( 'attachment' ), null ),
			'quoted'            => array( array( 'attachment; filename="quoted.txt"' ), 'quoted.txt' ),
			'unquoted'          => array( array( 'inline; filename=plain.txt' ), 'plain.txt' ),
			'filename-star-only' => array( array( "attachment; filename*=UTF-8''ignored.txt" ), null ),
			'last-wins'         => array( array( 'attachment; filename="first.txt"', 'attachment; filename="last.txt"' ), 'last.txt' ),
		);

		$parser_results = array();
		foreach ( $parser_cases as $label => $spec ) {
			$actual                   = \WP_REST_Attachments_Controller::get_filename_from_disposition( $spec[0] );
			$parser_results[ $label ] = $actual;
			self::collect_failure(
				$failures,
				$spec[1] === $actual,
				'Content-Disposition filename parser matches current REST attachment contract',
				array(
					'label'    => $label,
					'headers'  => $spec[0],
					'expected' => $spec[1],
					'actual'   => $actual,
				)
			);
		}

		$errors = array(
			'empty-body'          => $controller->probe_upload_from_data( '', $headers ),
			'missing-type'        => $controller->probe_upload_from_data(
				$case['body'],
				array( 'content_disposition' => $headers['content_disposition'] )
			),
			'missing-disposition' => $controller->probe_upload_from_data(
				$case['body'],
				array( 'content_type' => $headers['content_type'] )
			),
			'invalid-disposition' => $controller->probe_upload_from_data(
				$case['body'],
				array(
					'content_type'        => $headers['content_type'],
					'content_disposition' => array( 'attachment; name="file"' ),
				)
			),
			'md5-mismatch'        => $controller->probe_upload_from_data(
				$case['body'],
				array(
					'content_type'        => $headers['content_type'],
					'content_disposition' => $headers['content_disposition'],
					'content_md5'         => array( str_repeat( '0', 32 ) ),
				)
			),
		);

		self::collect_failure(
			$failures,
			self::error_matches( $errors['empty-body'], 'rest_upload_no_data', 400 )
				&& self::error_matches( $errors['missing-type'], 'rest_upload_no_content_type', 400 )
				&& self::error_matches( $errors['missing-disposition'], 'rest_upload_no_content_disposition', 400 )
				&& self::error_matches( $errors['invalid-disposition'], 'rest_upload_invalid_disposition', 400 )
				&& self::error_matches( $errors['md5-mismatch'], 'rest_upload_hash_mismatch', 412 )
				&& $before === self::content_counts(),
			'raw upload validation rejects missing body, headers, invalid disposition, and hash mismatch without content mutation',
			array(
				'errors' => self::describe_errors( $errors ),
				'before' => $before,
				'after'  => self::content_counts(),
			)
		);

		return self::result(
			$ctx,
			'rest-media-attachments.raw-upload-parser-and-errors',
			$failures,
			array(
				'case'          => self::case_summary( $case ),
				'parserResults' => $parser_results,
			)
		);
	}

	private static function check_create_item_raw_upload_success( \ComponentFuzz\FuzzContext $ctx, array $case, string $temp_root ): array {
		self::reset_content_runtime();

		$failures   = array();
		$controller = new \WP_REST_Attachments_Controller( 'attachment' );
		$author     = self::insert_user( $case, 'uploader' );
		$parent     = self::insert_post( $case, $author );

		\wp_set_current_user( 0 );
		$denied = $controller->create_item_permissions_check( self::raw_upload_request( $case, $parent ) );
		$grant  = self::install_cap_filter(
			array(
				'create_posts',
				'edit_post',
				'edit_posts',
				'edit_published_posts',
				'read',
				'upload_files',
			)
		);

		$before = self::content_counts();
		try {
			\wp_set_current_user( $author );
			$request = self::raw_upload_request( $case, $parent );
			$allowed = $controller->create_item_permissions_check( $request );
			$response = $controller->create_item( $request );
		} finally {
			$filter_removed = self::remove_cap_filter( $grant );
		}
		$after = self::content_counts();

		$data          = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$headers       = $response instanceof \WP_REST_Response ? $response->get_headers() : array();
		$attachment_id = (int) ( $data['id'] ?? 0 );
		$attachment    = $attachment_id > 0 ? \get_post( $attachment_id ) : null;
		$attached_file = $attachment_id > 0 ? \get_attached_file( $attachment_id ) : '';
		$relative_file = $attachment_id > 0 ? \get_post_meta( $attachment_id, '_wp_attached_file', true ) : '';
		$metadata      = $attachment_id > 0 ? \wp_get_attachment_metadata( $attachment_id ) : array();

		self::collect_failure(
			$failures,
			self::error_matches( $denied, 'rest_cannot_create' )
				&& true === $allowed
				&& $response instanceof \WP_REST_Response
				&& 201 === $response->get_status()
				&& isset( $headers['Location'] )
				&& str_contains( (string) $headers['Location'], 'wp/v2/media/' . $attachment_id )
				&& $attachment instanceof \WP_Post
				&& 'attachment' === $attachment->post_type
				&& 'inherit' === $attachment->post_status
				&& $parent === (int) $attachment->post_parent
				&& 'text/plain' === $attachment->post_mime_type
				&& $case['title'] === $attachment->post_title
				&& $case['caption'] === $attachment->post_excerpt
				&& $case['description'] === $attachment->post_content
				&& \sanitize_text_field( $case['altText'] ) === \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
				&& is_string( $attached_file )
				&& str_starts_with( $attached_file, rtrim( $temp_root, '/\\' ) )
				&& is_file( $attached_file )
				&& filesize( $attached_file ) === strlen( $case['body'] )
				&& $case['uploadFile'] === \wp_basename( $attached_file )
				&& $relative_file === \wp_basename( $attached_file )
				&& self::content_count_delta_matches( $before, $after, array( 'posts' => 1, 'post_meta' => 3 ) )
				&& false === \has_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' )
				&& false === \has_filter( 'fallback_intermediate_image_sizes', '__return_empty_array' )
				&& false === \has_filter( 'wp_image_maybe_exif_rotate', '__return_false' )
				&& false === \has_filter( 'image_editor_output_format', '__return_empty_array' )
				&& $filter_removed,
			'create_item raw upload creates one attachment, stores bounded metadata, sets response headers, and removes client-side filters',
			array(
				'denied'         => self::describe_error( $denied ),
				'allowed'        => self::describe_error( $allowed ),
				'responseStatus' => $response instanceof \WP_REST_Response ? $response->get_status() : null,
				'responseClass'  => is_object( $response ) ? get_class( $response ) : gettype( $response ),
				'dataKeys'       => array_keys( $data ),
				'headers'        => $headers,
				'postType'       => $attachment instanceof \WP_Post ? $attachment->post_type : null,
				'postStatus'     => $attachment instanceof \WP_Post ? $attachment->post_status : null,
				'postParent'     => $attachment instanceof \WP_Post ? (int) $attachment->post_parent : null,
				'postMimeType'   => $attachment instanceof \WP_Post ? $attachment->post_mime_type : null,
				'attachedFile'   => $attached_file,
				'relativeFile'   => $relative_file,
				'metadataKeys'   => is_array( $metadata ) ? array_keys( $metadata ) : null,
				'before'         => $before,
				'after'          => $after,
				'filterRemoved'  => $filter_removed,
			)
		);

		self::collect_failure(
			$failures,
			'id' === implode( ',', array_keys( array_intersect_key( $data, array( 'id' => true ) ) ) )
				&& array_key_exists( 'title', $data )
				&& array_key_exists( 'caption', $data )
				&& array_key_exists( 'description', $data )
				&& array_key_exists( 'alt_text', $data )
				&& array_key_exists( 'filename', $data )
				&& array_key_exists( 'filesize', $data )
				&& array_key_exists( 'source_url', $data )
				&& $case['title'] === ( $data['title']['raw'] ?? null )
				&& $case['caption'] === ( $data['caption']['raw'] ?? null )
				&& $case['description'] === ( $data['description']['raw'] ?? null )
				&& \sanitize_text_field( $case['altText'] ) === ( $data['alt_text'] ?? null )
				&& $case['uploadFile'] === ( $data['filename'] ?? null )
				&& strlen( $case['body'] ) === (int) ( $data['filesize'] ?? 0 )
				&& str_contains( (string) ( $data['source_url'] ?? '' ), rawurlencode( $case['uploadFile'] ) ),
			'_fields projection keeps REST attachment response focused while preserving requested media fields',
			array( 'data' => $data )
		);

		return self::result(
			$ctx,
			'rest-media-attachments.create-item-raw-upload-success',
			$failures,
			array( 'case' => self::case_summary( $case ) )
		);
	}

	private static function check_create_item_url_sideload_success( \ComponentFuzz\FuzzContext $ctx, array $case, string $temp_root ): array {
		self::reset_content_runtime();

		$failures      = array();
		$controller    = new \WP_REST_Attachments_Controller( 'attachment' );
		$author        = self::insert_user( $case, 'url-author' );
		$parent        = self::insert_post( $case, $author );
		$image_body    = self::tiny_png_bytes();
		$remote_file   = 'rest-remote-' . $case['token'] . '.png';
		$remote_url    = 'https://media.example.test/component-fuzz/' . rawurlencode( $remote_file ) . '?token=' . rawurlencode( $case['token'] );
		$missing_url   = 'https://media.example.test';
		$unsupported   = 'https://media.example.test/component-fuzz/' . rawurlencode( 'unsupported-' . $case['token'] . '.php' );
		$http_events    = array();
		$download_paths = array();
		$upload_events  = array();
		$action_events  = array();
		$routes         = array(
			$remote_url => array(
				'body'    => $image_body,
				'code'    => 200,
				'headers' => array(
					'Content-Type' => 'image/png',
				),
			),
		);

		$http_filter = self::http_interceptor( $routes, $http_events, $download_paths );
		$upload_dir_recorder = static function ( array $uploads ) use ( &$upload_events ): array {
			$upload_events[] = array(
				'path'   => $uploads['path'] ?? null,
				'subdir' => $uploads['subdir'] ?? null,
			);
			return $uploads;
		};
		$after_insert_action = static function ( \WP_Post $attachment, \WP_REST_Request $request, bool $creating ) use ( &$action_events ): void {
			$action_events[] = array(
				'id'       => (int) $attachment->ID,
				'creating' => $creating,
				'context'  => $request->get_param( 'context' ),
				'url'      => $request->get_param( 'url' ),
			);
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		\add_filter( 'upload_dir', $upload_dir_recorder, 101 );
		\add_action( 'rest_after_insert_attachment', $after_insert_action, 10, 3 );

		\wp_set_current_user( $author );
		$grant = self::install_cap_filter(
			array(
				'create_posts',
				'edit_post',
				'edit_posts',
				'edit_published_posts',
				'read',
				'upload_files',
			)
		);

		$before_errors  = self::content_counts();
		$missing_error  = null;
		$type_error     = null;
		$allowed        = null;
		$response       = null;
		$before_success = array();
		$after_success  = array();
		$after_errors     = $before_errors;
		$error_http_count = 0;
		$filter_removed   = false;
		try {
			$missing_error = $controller->create_item( self::url_sideload_request( $case, $parent, $missing_url ) );
			$type_error    = $controller->create_item( self::url_sideload_request( $case, $parent, $unsupported ) );
			$after_errors     = self::content_counts();
			$error_http_count = count( $http_events );

			$before_success = $after_errors;
			$request        = self::url_sideload_request( $case, $parent, $remote_url );
			$allowed        = $controller->create_item_permissions_check( $request );
			$response       = $controller->create_item( $request );
			$after_success  = self::content_counts();
		} finally {
			$filter_removed = self::remove_cap_filter( $grant );
			\remove_action( 'rest_after_insert_attachment', $after_insert_action, 10 );
			\remove_filter( 'upload_dir', $upload_dir_recorder, 101 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		$data          = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$headers       = $response instanceof \WP_REST_Response ? $response->get_headers() : array();
		$attachment_id = (int) ( $data['id'] ?? 0 );
		$attachment    = $attachment_id > 0 ? \get_post( $attachment_id ) : null;
		$attached_file = $attachment_id > 0 ? \get_attached_file( $attachment_id ) : '';
		$relative_file = $attachment_id > 0 ? \get_post_meta( $attachment_id, '_wp_attached_file', true ) : '';
		$metadata      = $attachment_id > 0 ? \wp_get_attachment_metadata( $attachment_id ) : array();

		$unregistered_events = array_filter(
			$http_events,
			static function ( array $event ): bool {
				return empty( $event['registered'] );
			}
		);
		$registered_events   = array_filter(
			$http_events,
			static function ( array $event ): bool {
				return ! empty( $event['registered'] );
			}
		);

		self::collect_failure(
			$failures,
			self::error_matches( $missing_error, 'rest_invalid_url', 400 )
				&& self::error_matches( $type_error, 'rest_invalid_url', 400 )
				&& $before_errors === $after_errors
				&& 0 === $error_http_count,
			'URL sideload rejects empty path filenames and unsupported extensions before HTTP or content mutation',
			array(
				'missing' => self::describe_error( $missing_error ),
				'type'    => self::describe_error( $type_error ),
				'before'  => $before_errors,
				'after'   => $after_errors,
				'http'    => array_slice( $http_events, 0, $error_http_count ),
			)
		);

		self::collect_failure(
			$failures,
			true === $allowed
				&& $response instanceof \WP_REST_Response
				&& 201 === $response->get_status()
				&& isset( $headers['Location'] )
				&& str_contains( (string) $headers['Location'], 'wp/v2/media/' . $attachment_id )
				&& $attachment instanceof \WP_Post
				&& 'attachment' === $attachment->post_type
				&& 'inherit' === $attachment->post_status
				&& $parent === (int) $attachment->post_parent
				&& 'image/png' === $attachment->post_mime_type
				&& 'rest-remote-' . $case['token'] === $attachment->post_title
				&& '' === $attachment->post_excerpt
				&& '' === $attachment->post_content
				&& is_string( $attached_file )
				&& self::path_starts_with( $attached_file, rtrim( $temp_root, '/\\' ) )
				&& is_file( $attached_file )
				&& $image_body === file_get_contents( $attached_file )
				&& $remote_file === \wp_basename( $attached_file )
				&& $relative_file === \wp_basename( $attached_file )
				&& is_array( $metadata )
				&& strlen( $image_body ) === (int) ( $metadata['filesize'] ?? 0 ),
			'create_item URL sideload downloads one registered image fixture into the filtered upload root and creates an attachment',
			array(
				'allowed'        => self::describe_error( $allowed ),
				'responseStatus' => $response instanceof \WP_REST_Response ? $response->get_status() : null,
				'headers'        => $headers,
				'post'           => $attachment instanceof \WP_Post ? array(
					'ID'             => $attachment->ID,
					'post_parent'    => $attachment->post_parent,
					'post_status'    => $attachment->post_status,
					'post_title'     => $attachment->post_title,
					'post_mime_type' => $attachment->post_mime_type,
				) : $attachment,
				'attachedFile'   => $attached_file,
				'relativeFile'   => $relative_file,
				'metadata'       => $metadata,
			)
		);

		self::collect_failure(
			$failures,
			$attachment_id > 0
				&& $attachment_id === (int) ( $data['id'] ?? 0 )
				&& $parent === (int) ( $data['post'] ?? 0 )
				&& 'image' === ( $data['media_type'] ?? null )
				&& 'image/png' === ( $data['mime_type'] ?? null )
				&& $remote_file === ( $data['filename'] ?? null )
				&& strlen( $image_body ) === (int) ( $data['filesize'] ?? 0 )
				&& str_contains( (string) ( $data['source_url'] ?? '' ), rawurlencode( $remote_file ) )
				&& self::projected_keys_match( $data, array( 'caption', 'description', 'filename', 'filesize', 'id', 'media_type', 'mime_type', 'post', 'source_url', 'title' ) ),
			'_fields projection for URL sideload exposes the created REST media fields without unrelated payload expansion',
			array( 'data' => $data )
		);

		self::collect_failure(
			$failures,
			1 === count( $registered_events )
				&& array() === $unregistered_events
				&& $remote_url === ( $registered_events[0]['url'] ?? null )
				&& ! empty( $registered_events[0]['stream'] )
				&& self::tracked_paths_absent( $download_paths )
				&& self::content_count_delta_matches( $before_success ?? array(), $after_success ?? array(), array( 'posts' => 1, 'post_meta' => 2 ) ),
			'URL sideload HTTP is fully short-circuited, temporary download paths are cleaned, and only attachment content rows are added',
			array(
				'http'                 => $http_events,
				'downloadPaths'        => $download_paths,
				'trackedPathsCleaned'  => self::tracked_paths_absent( $download_paths ),
				'beforeSuccess'        => $before_success ?? array(),
				'afterSuccess'         => $after_success ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			array(
				array(
					'id'       => $attachment_id,
					'creating' => true,
					'context'  => 'edit',
					'url'      => $remote_url,
				),
			) === $action_events
				&& array() !== $upload_events
				&& self::upload_events_within_root( $upload_events, rtrim( $temp_root, '/\\' ) )
				&& $filter_removed
				&& false === \has_filter( 'pre_http_request', $http_filter )
				&& false === \has_filter( 'upload_dir', $upload_dir_recorder )
				&& false === \has_filter( 'rest_after_insert_attachment', $after_insert_action )
				&& false === \has_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' )
				&& false === \has_filter( 'fallback_intermediate_image_sizes', '__return_empty_array' )
				&& false === \has_filter( 'wp_image_maybe_exif_rotate', '__return_false' )
				&& false === \has_filter( 'image_editor_output_format', '__return_empty_array' ),
			'URL sideload fires the REST insert action once and restores HTTP, upload, capability, and client-side media filters',
			array(
				'actions'       => $action_events,
				'uploads'       => $upload_events,
				'filterRemoved' => $filter_removed,
				'preHttp'       => \has_filter( 'pre_http_request', $http_filter ),
				'uploadDir'     => \has_filter( 'upload_dir', $upload_dir_recorder ),
				'afterInsert'   => \has_filter( 'rest_after_insert_attachment', $after_insert_action ),
			)
		);

		return self::result(
			$ctx,
			'rest-media-attachments.create-item-url-sideload-success',
			$failures,
			array(
				'case' => self::case_summary( $case ),
				'url'  => $remote_url,
			)
		);
	}

	private static function check_sideload_raw_upload_metadata( \ComponentFuzz\FuzzContext $ctx, array $case, string $temp_root ): array {
		self::reset_content_runtime();

		$failures       = array();
		$controller     = new \WP_REST_Attachments_Controller( 'attachment' );
		$author         = self::insert_user( $case, 'sideload-author' );
		$png_body       = self::tiny_png_bytes();
		$base_filename  = 'sideload-' . $case['token'] . '.png';
		$thumb_filename = 'sideload-' . $case['token'] . '-150x150.png';

		$text_attachment = self::insert_attachment_fixture( $case, $author, $temp_root, 'sideload-invalid-' . $case['uploadFile'], $case['body'], true );
		$image_attachment = self::insert_attachment_fixture(
			$case,
			$author,
			$temp_root,
			$base_filename,
			$png_body,
			true,
			'image/png',
			array(
				'file'     => $base_filename,
				'width'    => 1,
				'height'   => 1,
				'filesize' => strlen( $png_body ),
				'sizes'    => array(),
			)
		);
		$original_attachment = self::insert_attachment_fixture(
			$case,
			$author,
			$temp_root,
			'original-' . $base_filename,
			$png_body,
			true,
			'image/png',
			array(
				'file'     => 'original-' . $base_filename,
				'width'    => 1,
				'height'   => 1,
				'filesize' => strlen( $png_body ),
				'sizes'    => array(),
			)
		);

		$invalid_type = $controller->sideload_item(
			self::sideload_request(
				$text_attachment,
				$thumb_filename,
				$png_body,
				'thumbnail',
				false
			)
		);

		\wp_set_current_user( $author );
		$grant = self::install_cap_filter(
			array(
				'edit_post',
				'edit_posts',
				'edit_published_posts',
				'read',
				'upload_files',
			)
		);

		$before_counts = self::content_counts();
		try {
			$thumb_request = self::sideload_request(
				$image_attachment,
				$thumb_filename,
				$png_body,
				'thumbnail',
				false,
				array( 'id', 'media_details', 'filename', 'filesize' )
			);
			$thumb_allowed = $controller->sideload_item_permissions_check( $thumb_request );
			$thumb_response = $controller->sideload_item( $thumb_request );

			$original_filename = 'original-' . $case['token'] . '-replacement.png';
			$original_request  = self::sideload_request(
				$original_attachment,
				$original_filename,
				$png_body,
				'original',
				true,
				array( 'id', 'media_details' )
			);
			$original_allowed  = $controller->sideload_item_permissions_check( $original_request );
			$original_response = $controller->sideload_item( $original_request );
		} finally {
			$grant_removed = self::remove_cap_filter( $grant );
		}
		$after_counts = self::content_counts();

		$thumb_metadata  = \wp_get_attachment_metadata( $image_attachment, true );
		$thumb_path      = rtrim( $temp_root, '/\\' ) . DIRECTORY_SEPARATOR . $thumb_filename;
		$thumb_data      = $thumb_response instanceof \WP_REST_Response ? $thumb_response->get_data() : array();
		$thumb_headers   = $thumb_response instanceof \WP_REST_Response ? $thumb_response->get_headers() : array();
		$original_meta   = \wp_get_attachment_metadata( $original_attachment, true );
		$original_path   = \get_attached_file( $original_attachment, true );
		$original_upload = rtrim( $temp_root, '/\\' ) . DIRECTORY_SEPARATOR . $original_filename;
		$original_data   = $original_response instanceof \WP_REST_Response ? $original_response->get_data() : array();
		$original_header = $original_response instanceof \WP_REST_Response ? $original_response->get_headers() : array();

		self::collect_failure(
			$failures,
			self::error_matches( $invalid_type, 'rest_post_invalid_id', 400 ),
			'sideload rejects non-image and non-PDF attachments before writing upload metadata',
			array(
				'textAttachment' => $text_attachment,
				'result'         => self::describe_error( $invalid_type ),
			)
		);

		self::collect_failure(
			$failures,
			true === $thumb_allowed
				&& $thumb_response instanceof \WP_REST_Response
				&& 200 === $thumb_response->get_status()
				&& is_file( $thumb_path )
				&& $png_body === file_get_contents( $thumb_path )
				&& is_array( $thumb_metadata )
				&& $base_filename === ( $thumb_metadata['file'] ?? null )
				&& array() === ( $thumb_metadata['sizes'] ?? array() )
				&& 'thumbnail' === ( $thumb_data['image_size'] ?? null )
				&& 1 === (int) ( $thumb_data['width'] ?? 0 )
				&& 1 === (int) ( $thumb_data['height'] ?? 0 )
				&& $thumb_filename === ( $thumb_data['file'] ?? null )
				&& 'image/png' === ( $thumb_data['mime_type'] ?? null )
				&& strlen( $png_body ) === (int) ( $thumb_data['filesize'] ?? 0 )
				&& self::projected_keys_match( $thumb_data, array( 'file', 'filesize', 'height', 'image_size', 'mime_type', 'width' ) ),
			'sideload raw body returns exact generated subsize data without mutating attachment metadata before finalize',
			array(
				'allowed'   => self::describe_error( $thumb_allowed ),
				'status'    => $thumb_response instanceof \WP_REST_Response ? $thumb_response->get_status() : null,
				'headers'   => $thumb_headers,
				'metadata'  => $thumb_metadata,
				'data'      => $thumb_data,
				'thumbPath' => $thumb_path,
			)
		);

		self::collect_failure(
			$failures,
			true === $original_allowed
				&& $original_response instanceof \WP_REST_Response
				&& 200 === $original_response->get_status()
				&& is_array( $original_meta )
				&& ! isset( $original_meta['original_image'] )
				&& is_file( $original_upload )
				&& $png_body === file_get_contents( $original_upload )
				&& 'original-' . $base_filename === \wp_basename( (string) $original_path )
				&& 'original' === ( $original_data['image_size'] ?? null )
				&& $original_filename === ( $original_data['file'] ?? null )
				&& self::projected_keys_match( $original_data, array( 'file', 'image_size' ) ),
			'original-image sideload returns original_image data without replacing the attached file path before finalize',
			array(
				'allowed'      => self::describe_error( $original_allowed ),
				'headers'      => $original_header,
				'metadata'     => $original_meta,
				'attachedFile' => $original_path,
				'uploadFile'   => $original_upload,
				'data'         => $original_data,
			)
		);

		self::collect_failure(
			$failures,
			$grant_removed
				&& false === \has_filter( 'image_editor_output_format', '__return_empty_array' )
				&& self::content_count_delta_matches( $before_counts, $after_counts, array() ),
			'sideload restores capability and client-side conversion filters without creating attachment posts or new meta rows',
			array(
				'grantRemoved'             => $grant_removed,
				'imageEditorOutputFilter'  => \has_filter( 'image_editor_output_format', '__return_empty_array' ),
				'beforeCounts'             => $before_counts,
				'afterCounts'              => $after_counts,
			)
		);

		return self::result(
			$ctx,
			'rest-media-attachments.sideload-raw-upload-metadata',
			$failures,
			array( 'case' => self::case_summary( $case ) )
		);
	}

	private static function check_finalize_and_response_projection( \ComponentFuzz\FuzzContext $ctx, array $case, string $temp_root ): array {
		self::reset_content_runtime();

		$failures   = array();
		$controller = new \WP_REST_Attachments_Controller( 'attachment' );
		$author     = self::insert_user( $case, 'finalize-author' );
		$attachment = self::insert_attachment_fixture( $case, $author, $temp_root, 'finalize-' . $case['uploadFile'], $case['body'] );
		$filter_log = array();
		$filter     = static function ( array $metadata, int $attachment_id, string $context ) use ( &$filter_log, $attachment, $case ): array {
			$filter_log[] = array(
				'id'      => $attachment_id,
				'context' => $context,
			);

			if ( $attachment === $attachment_id && 'update' === $context ) {
				$metadata['component_fuzz_finalized'] = $case['token'];
			}

			return $metadata;
		};

		\add_filter( 'wp_generate_attachment_metadata', $filter, 10, 3 );
		try {
			$request = self::request(
				'POST',
				'/wp/v2/media/' . $attachment . '/finalize',
				array( '_fields' => 'id,media_details,filesize,filename' ),
				array( 'id' => $attachment )
			);
			$response = $controller->finalize_item( $request );
		} finally {
			$filter_removed = \remove_filter( 'wp_generate_attachment_metadata', $filter, 10 );
		}

		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : array();
		$metadata = \wp_get_attachment_metadata( $attachment );

		self::collect_failure(
			$failures,
			$response instanceof \WP_REST_Response
				&& 200 === $response->get_status()
				&& array( array( 'id' => $attachment, 'context' => 'update' ) ) === $filter_log
				&& is_array( $metadata )
				&& $case['token'] === ( $metadata['component_fuzz_finalized'] ?? null )
				&& $attachment === (int) ( $data['id'] ?? 0 )
				&& $case['token'] === ( $data['media_details']['component_fuzz_finalized'] ?? null )
				&& strlen( $case['body'] ) === (int) ( $data['filesize'] ?? 0 )
				&& 'finalize-' . $case['uploadFile'] === ( $data['filename'] ?? null )
				&& self::projected_keys_match( $data, array( 'filename', 'filesize', 'id', 'media_details' ) )
				&& $filter_removed,
			'finalize_item applies metadata update filters once, persists metadata, projects _fields, and restores filters',
			array(
				'filterLog'     => $filter_log,
				'filterRemoved' => $filter_removed,
				'metadata'      => $metadata,
				'data'          => $data,
			)
		);

		return self::result(
			$ctx,
			'rest-media-attachments.finalize-and-response-projection',
			$failures,
			array( 'case' => self::case_summary( $case ) )
		);
	}

	private static function check_permission_and_edit_fail_closed_paths( \ComponentFuzz\FuzzContext $ctx, array $case, string $temp_root ): array {
		self::reset_content_runtime();

		$failures   = array();
		$controller = new \WP_REST_Attachments_Controller( 'attachment' );
		$author     = self::insert_user( $case, 'editor' );
		$attachment = self::insert_attachment_fixture( $case, $author, $temp_root, 'edit-' . $case['uploadFile'], $case['body'], false );
		$request    = self::request(
			'POST',
			'/wp/v2/media/' . $attachment . '/edit',
			array(),
			array( 'id' => $attachment ),
			array( 'src' => \wp_get_attachment_url( $attachment ) )
		);

		\wp_set_current_user( 0 );
		$no_upload_cap = $controller->edit_media_item_permissions_check( $request );
		\wp_set_current_user( $author );
		$upload_only   = self::install_cap_filter( array( 'read', 'upload_files' ) );
		try {
			$no_edit_cap = $controller->edit_media_item_permissions_check( $request );
		} finally {
			$upload_only_removed = self::remove_cap_filter( $upload_only );
		}

		$edit_grant = self::install_cap_filter(
			array(
				'edit_post',
				'edit_posts',
				'edit_published_posts',
				'read',
				'upload_files',
			)
		);
		try {
			$edit_allowed      = $controller->edit_media_item_permissions_check( $request );
			$missing_metadata = $controller->edit_media_item( $request );
		} finally {
			$edit_grant_removed = self::remove_cap_filter( $edit_grant );
		}

		self::collect_failure(
			$failures,
			self::error_matches( $no_upload_cap, 'rest_cannot_edit_image' )
				&& self::error_matches( $no_edit_cap, 'rest_cannot_edit' )
				&& true === $edit_allowed
				&& self::error_matches( $missing_metadata, 'rest_unknown_attachment', 404 )
				&& $upload_only_removed
				&& $edit_grant_removed
				&& self::content_counts()['posts'] >= 1,
			'edit media permission gates require upload and edit caps, and missing image metadata fails closed without mutation',
			array(
				'noUploadCap'       => self::describe_error( $no_upload_cap ),
				'noEditCap'         => self::describe_error( $no_edit_cap ),
				'editAllowed'       => $edit_allowed,
				'missingMetadata'   => self::describe_error( $missing_metadata ),
				'uploadOnlyRemoved' => $upload_only_removed,
				'editGrantRemoved'  => $edit_grant_removed,
				'counts'            => self::content_counts(),
			)
		);

		return self::result(
			$ctx,
			'rest-media-attachments.permissions-and-edit-fail-closed',
			$failures,
			array( 'case' => self::case_summary( $case ) )
		);
	}

	private static function check_client_side_route_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_content_runtime();

		$failures   = array();
		$controller = new \WP_REST_Attachments_Controller( 'attachment' );
		$host_before = $_SERVER['HTTP_HOST'] ?? null;
		$_SERVER['HTTP_HOST'] = 'localhost';

		$creatable_args = $controller->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE );
		$enabled        = \wp_is_client_side_media_processing_enabled();
		$server         = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		$controller->register_routes();
		$routes = $server->get_routes();

		if ( null === $host_before ) {
			unset( $_SERVER['HTTP_HOST'] );
		} else {
			$_SERVER['HTTP_HOST'] = $host_before;
		}

		self::collect_failure(
			$failures,
			$enabled
				&& isset( $creatable_args['generate_sub_sizes'], $creatable_args['convert_format'] )
				&& 'boolean' === ( $creatable_args['generate_sub_sizes']['type'] ?? null )
				&& 'boolean' === ( $creatable_args['convert_format']['type'] ?? null )
				&& isset( $routes['/wp/v2/media/(?P<id>[\d]+)/sideload'] )
				&& isset( $routes['/wp/v2/media/(?P<id>[\d]+)/finalize'] )
				&& isset( $routes['/wp/v2/media/(?P<id>[\d]+)/edit'] ),
			'client-side media processing exposes create flags and attachment sideload/finalize/edit routes in secure contexts',
			array(
				'enabled' => $enabled,
				'args'    => array_intersect_key( $creatable_args, array( 'generate_sub_sizes' => true, 'convert_format' => true ) ),
				'routes'  => array_keys( $routes ),
			)
		);

		return self::result(
			$ctx,
			'rest-media-attachments.client-side-route-contracts',
			$failures
		);
	}

	private static function prepare_runtime( string $temp_root ): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'admin_email'                   => 'admin@example.test',
					'blog_charset'                  => 'UTF-8',
					'blogname'                      => 'Component Fuzz REST Media',
					'default_category'              => 0,
					'default_comment_status'        => 'closed',
					'default_ping_status'           => 'closed',
					'default_role'                  => 'subscriber',
					'home'                          => 'http://example.test',
					'large_size_h'                  => 1024,
					'large_size_w'                  => 1024,
					'medium_large_size_h'           => 0,
					'medium_large_size_w'           => 768,
					'medium_size_h'                 => 300,
					'medium_size_w'                 => 300,
					'permalink_structure'           => '',
					'require_name_email'            => 0,
					'show_avatars'                  => 0,
					'siteurl'                       => 'http://example.test',
					'thumbnail_crop'                => 1,
					'thumbnail_size_h'              => 150,
					'thumbnail_size_w'              => 150,
					'upload_path'                   => '',
					'upload_url_path'               => '',
					'uploads_use_yearmonth_folders' => 0,
					'wp_attachment_pages_enabled'   => '0',
				)
			);
		}

		self::$upload_root = $temp_root;
		\add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 100 );
		\wp_cache_flush();

		$GLOBALS['_wp_post_type_features'] = array();
		$GLOBALS['post_type_meta_caps']    = array();
		$GLOBALS['wp_meta_keys']           = array();
		$GLOBALS['wp_post_statuses']       = array();
		$GLOBALS['wp_post_types']          = array();
		$GLOBALS['wp_rest_server']         = new \WP_REST_Server();
		$GLOBALS['wp_rewrite']             = new \WP_Rewrite();
		$GLOBALS['wp_taxonomies']          = array();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();

		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz RestMediaAttachments';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/rest-media-attachments/';
		$_SERVER['SERVER_SOFTWARE'] = 'ComponentFuzz';
	}

	private static function reset_content_runtime(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		\wp_cache_flush();
		\wp_set_current_user( 0 );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'sha1', (string) $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );

		return array(
			'token'       => $token,
			'userLogin'   => 'cfz_rest_media_' . $token,
			'userEmail'   => 'rest-media-' . $token . '@example.test',
			'userName'    => 'REST Media ' . $ctx->int( 100, 999 ),
			'postTitle'   => 'REST Media Parent ' . $ctx->int( 100, 999 ),
			'title'       => 'Uploaded text ' . $ctx->int( 100, 999 ),
			'caption'     => 'Caption ' . $token,
			'description' => 'Description ' . $token,
			'altText'     => "Alt {$token}<script>bad</script>",
			'uploadFile'  => 'rest-media-' . $token . '.txt',
			'body'        => "Component fuzz REST media body {$token}\n" . $ctx->ascii( 8, 24 ),
		);
	}

	private static function raw_upload_request( array $case, int $parent ): \WP_REST_Request {
		$request = self::request(
			'POST',
			'/wp/v2/media',
			array( '_fields' => 'id,title,caption,description,alt_text,media_type,mime_type,source_url,filename,filesize,post' ),
			array(),
			array(
				'alt_text'           => $case['altText'],
				'caption'            => $case['caption'],
				'convert_format'     => false,
				'description'        => $case['description'],
				'generate_sub_sizes' => false,
				'post'               => $parent,
				'title'              => $case['title'],
			)
		);
		$request->set_body( $case['body'] );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'Content-Disposition', 'attachment; filename="' . $case['uploadFile'] . '"' );
		$request->set_header( 'Content-MD5', md5( $case['body'] ) );

		return $request;
	}

	private static function url_sideload_request( array $case, int $parent, string $url ): \WP_REST_Request {
		return self::request(
			'POST',
			'/wp/v2/media',
			array( '_fields' => 'id,title,caption,description,media_type,mime_type,source_url,filename,filesize,post' ),
			array(),
			array(
				'alt_text'           => $case['altText'],
				'caption'            => $case['caption'],
				'convert_format'     => false,
				'description'        => $case['description'],
				'generate_sub_sizes' => false,
				'post'               => $parent,
				'title'              => $case['title'],
				'url'                => $url,
			)
		);
	}

	private static function http_interceptor( array $routes, array &$events, array &$download_paths ): \Closure {
		return static function ( $pre, array $parsed_args, string $url ) use ( $routes, &$events, &$download_paths ) {
			unset( $pre );

			$filename   = isset( $parsed_args['filename'] ) ? (string) $parsed_args['filename'] : '';
			$registered = array_key_exists( $url, $routes );
			$events[]   = array(
				'url'        => $url,
				'registered' => $registered,
				'stream'     => ! empty( $parsed_args['stream'] ),
				'filename'   => $filename,
				'timeout'    => $parsed_args['timeout'] ?? null,
			);

			if ( '' !== $filename ) {
				$download_paths[] = $filename;
			}

			if ( ! $registered ) {
				return new \WP_Error( 'component_fuzz_unregistered_http', 'Unregistered component-fuzz HTTP fixture.' );
			}

			$route = $routes[ $url ];
			$body  = (string) ( $route['body'] ?? '' );
			if ( '' !== $filename ) {
				\ComponentFuzz\ensure_dir( dirname( $filename ) );
				file_put_contents( $filename, $body );
			}

			return array(
				'headers'  => self::response_headers( $route['headers'] ?? array() ),
				'body'     => empty( $parsed_args['stream'] ) ? $body : '',
				'response' => array(
					'code'    => (int) ( $route['code'] ?? 200 ),
					'message' => (string) ( $route['message'] ?? 'OK' ),
				),
				'cookies'  => array(),
				'filename' => $filename,
			);
		};
	}

	private static function response_headers( array $headers ): \WpOrg\Requests\Utility\CaseInsensitiveDictionary {
		return new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers );
	}

	private static function tracked_paths_absent( array $paths ): bool {
		foreach ( array_unique( array_filter( $paths, 'is_string' ) ) as $path ) {
			if ( '' !== $path && file_exists( $path ) ) {
				return false;
			}
		}

		return true;
	}

	private static function upload_events_within_root( array $events, string $upload_root ): bool {
		foreach ( $events as $event ) {
			if ( ! is_string( $event['path'] ?? null ) || ! self::path_starts_with( (string) $event['path'], $upload_root ) ) {
				return false;
			}
		}

		return true;
	}

	private static function path_starts_with( string $path, string $root ): bool {
		$path = str_replace( '\\', '/', $path );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		return $root !== '' && ( $path === $root || str_starts_with( $path, $root . '/' ) );
	}

	private static function attachments_probe(): \WP_REST_Attachments_Controller {
		return new class() extends \WP_REST_Attachments_Controller {
			public function __construct() {
				parent::__construct( 'attachment' );
			}

			public function probe_upload_from_data( string $data, array $headers ) {
				return $this->upload_from_data( $data, $headers );
			}
		};
	}

	private static function request( string $method, string $route, array $query_params = array(), array $url_params = array(), array $body_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( array() !== $query_params ) {
			$request->set_query_params( $query_params );
		}
		if ( array() !== $url_params ) {
			$request->set_url_params( $url_params );
		}
		if ( array() !== $body_params ) {
			$request->set_body_params( $body_params );
			foreach ( $body_params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		return $request;
	}

	private static function insert_user( array $case, string $suffix ): int {
		$user_id = \wp_insert_user(
			array(
				'display_name' => $case['userName'] . ' ' . $suffix,
				'role'         => 'author',
				'user_email'   => $suffix . '-' . $case['userEmail'],
				'user_login'   => $case['userLogin'] . '_' . str_replace( '-', '_', $suffix ),
				'user_pass'    => 'component-fuzz-pass',
			)
		);

		return is_int( $user_id ) ? $user_id : 0;
	}

	private static function insert_post( array $case, int $author ): int {
		$post_id = \wp_insert_post(
			array(
				'post_author'  => $author,
				'post_content' => 'Parent for REST media attachment ' . $case['token'],
				'post_status'  => 'publish',
				'post_title'   => $case['postTitle'],
				'post_type'    => 'post',
			),
			true,
			false
		);

		return is_int( $post_id ) ? $post_id : 0;
	}

	private static function insert_attachment_fixture(
		array $case,
		int $author,
		string $temp_root,
		string $filename,
		string $body,
		bool $with_metadata = true,
		string $mime_type = 'text/plain',
		?array $metadata = null
	): int {
		$uploads = \wp_upload_dir();
		\ComponentFuzz\ensure_dir( $uploads['path'] );
		$path = rtrim( $uploads['path'], '/\\' ) . DIRECTORY_SEPARATOR . \sanitize_file_name( $filename );
		file_put_contents( $path, $body );

		$id = \wp_insert_attachment(
			\wp_slash(
				array(
					'guid'           => $uploads['url'] . '/' . rawurlencode( \wp_basename( $path ) ),
					'post_author'    => $author,
					'post_mime_type' => $mime_type,
					'post_status'    => 'inherit',
					'post_title'     => 'Fixture ' . $case['token'],
					'post_type'      => 'attachment',
				)
			),
			$path,
			0,
			true,
			false
		);

		if ( is_int( $id ) ) {
			\update_post_meta( $id, '_wp_attached_file', \wp_basename( $path ) );
			if ( $with_metadata ) {
				\wp_update_attachment_metadata(
					$id,
					$metadata ?? array(
						'file'     => \wp_basename( $path ),
						'filesize' => strlen( $body ),
						'sizes'    => array(),
					)
				);
			}
		}

		return is_int( $id ) ? $id : 0;
	}

	private static function sideload_request( int $attachment_id, string $filename, string $body, string $image_size, bool $convert_format, array $fields = array() ): \WP_REST_Request {
		$query = array();
		if ( array() !== $fields ) {
			$query['_fields'] = implode( ',', $fields );
		}

		$request = self::request(
			'POST',
			'/wp/v2/media/' . $attachment_id . '/sideload',
			$query,
			array( 'id' => $attachment_id ),
			array(
				'convert_format' => $convert_format,
				'image_size'     => $image_size,
			)
		);
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'image/png' );
		$request->set_header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$request->set_header( 'Content-MD5', md5( $body ) );

		return $request;
	}

	private static function tiny_png_bytes(): string {
		return base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
		);
	}

	private static function install_cap_filter( array $granted_caps ): callable {
		$granted = array_fill_keys( $granted_caps, true );
		$filter  = static function ( array $allcaps, array $caps ) use ( $granted ): array {
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = isset( $granted[ $cap ] );
			}
			foreach ( $granted as $cap => $allowed ) {
				$allcaps[ $cap ] = $allowed;
			}

			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, PHP_INT_MAX, 2 );

		return $filter;
	}

	private static function remove_cap_filter( callable $filter ): bool {
		return \remove_filter( 'user_has_cap', $filter, PHP_INT_MAX );
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failures'] = array_slice( $failures, 0, 8 );

		return $ctx->result( $invariant, array() === $failures, $data );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function error_matches( $value, string $code, ?int $status = null ): bool {
		if ( ! \is_wp_error( $value ) || $code !== $value->get_error_code() ) {
			return false;
		}

		if ( null === $status ) {
			return true;
		}

		$data = $value->get_error_data();

		return is_array( $data ) && $status === (int) ( $data['status'] ?? 0 );
	}

	private static function describe_errors( array $values ): array {
		$out = array();
		foreach ( $values as $key => $value ) {
			$out[ $key ] = self::describe_error( $value );
		}

		return $out;
	}

	private static function describe_error( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return $value;
		}

		return array(
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
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

	private static function case_summary( array $case ): array {
		return array_intersect_key(
			$case,
			array(
				'token'      => true,
				'uploadFile' => true,
				'title'      => true,
				'body'       => true,
			)
		);
	}

	private static function projected_keys_match( array $data, array $expected ): bool {
		$actual = array_keys( $data );
		sort( $actual );
		sort( $expected );

		return $expected === $actual;
	}

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function content_count_delta_matches( array $before, array $after, array $expected_delta ): bool {
		foreach ( $expected_delta as $key => $delta ) {
			if ( ( (int) ( $before[ $key ] ?? 0 ) + $delta ) !== (int) ( $after[ $key ] ?? 0 ) ) {
				return false;
			}
		}

		foreach ( $before as $key => $value ) {
			if ( ! array_key_exists( $key, $expected_delta ) && (int) $value !== (int) ( $after[ $key ] ?? 0 ) ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'_wp_post_type_features',
				'current_user',
				'post_type_meta_caps',
				'user_ID',
				'wp_filter',
				'wp_actions',
				'wp_current_filter',
				'wp_filters',
				'wp_meta_keys',
				'wp_post_statuses',
				'wp_post_types',
				'wp_registered_settings',
				'wp_rest_additional_fields',
				'wp_rest_server',
				'wp_rewrite',
				'wp_taxonomies',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_HOST', 'HTTP_USER_AGENT', 'REQUEST_URI', 'SERVER_SOFTWARE' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'files'   => $_FILES,
			'get'     => $_GET,
			'globals' => $globals,
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
			'post'    => $_POST,
			'request' => $_REQUEST,
			'server'  => $server,
		);
	}

	private static function restore_state( array $snapshot ): void {
		\remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 100 );
		\remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
		\remove_filter( 'fallback_intermediate_image_sizes', '__return_empty_array', 100 );
		\remove_filter( 'wp_image_maybe_exif_rotate', '__return_false', 100 );
		\remove_filter( 'image_editor_output_format', '__return_empty_array', 100 );

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		\wp_cache_flush();
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}

		$_FILES   = $snapshot['files'];
		$_GET     = $snapshot['get'];
		$_POST    = $snapshot['post'];
		$_REQUEST = $snapshot['request'];
	}

	private static function restoration_probe( array $snapshot ): array {
		$failures = array();

		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( (bool) $entry['exists'] !== $exists ) {
				$failures[] = array(
					'name'           => 'global-existence',
					'global'         => $name,
					'expectedExists' => (bool) $entry['exists'],
					'actualExists'   => $exists,
				);
				continue;
			}

			if ( $entry['exists'] && self::state_signature( $entry['value'] ) !== self::state_signature( $GLOBALS[ $name ] ) ) {
				$failures[] = array(
					'name'     => 'global-signature',
					'global'   => $name,
					'expected' => self::state_signature( $entry['value'] ),
					'actual'   => self::state_signature( $GLOBALS[ $name ] ),
				);
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			$exists = array_key_exists( $name, $_SERVER );
			if ( (bool) $entry['exists'] !== $exists || ( $entry['exists'] && $_SERVER[ $name ] !== $entry['value'] ) ) {
				$failures[] = array(
					'name'           => 'server-value',
					'server'         => $name,
					'expectedExists' => (bool) $entry['exists'],
					'actualExists'   => $exists,
					'expected'       => $entry['value'],
					'actual'         => $_SERVER[ $name ] ?? null,
				);
			}
		}

		if ( $_FILES !== $snapshot['files'] ) {
			$failures[] = array( 'name' => 'files-superglobal' );
		}
		if ( $_GET !== $snapshot['get'] ) {
			$failures[] = array( 'name' => 'get-superglobal' );
		}
		if ( $_POST !== $snapshot['post'] ) {
			$failures[] = array( 'name' => 'post-superglobal' );
		}
		if ( $_REQUEST !== $snapshot['request'] ) {
			$failures[] = array( 'name' => 'request-superglobal' );
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
			if ( $options !== $snapshot['options'] ) {
				$failures[] = array(
					'name'     => 'wpdb-options',
					'expected' => array_keys( $snapshot['options'] ),
					'actual'   => array_keys( $options ),
				);
			}
		}

		return array(
			'restored'      => array() === $failures,
			'failures'      => array_slice( $failures, 0, 8 ),
			'contentCounts' => self::content_counts(),
		);
	}

	private static function state_signature( $value ): array {
		if ( is_array( $value ) ) {
			return array(
				'type'  => 'array',
				'count' => count( $value ),
				'keys'  => array_slice( array_map( 'strval', array_keys( $value ) ), 0, 24 ),
			);
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return array(
			'type'  => gettype( $value ),
			'value' => is_scalar( $value ) || null === $value ? $value : null,
		);
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-rest-media-attachments-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration();
		if ( file_exists( $base ) ) {
			self::remove_dir_recursive( $base );
		}

		if ( ! is_dir( $base ) && ! mkdir( $base, 0777, true ) && ! is_dir( $base ) ) {
			return null;
		}

		return $base;
	}

	private static function remove_dir_recursive( string $dir ): void {
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-rest-media-attachments-';
		if ( ! str_starts_with( $dir, $temp_prefix ) || ! file_exists( $dir ) ) {
			return;
		}

		if ( ! is_dir( $dir ) ) {
			@unlink( $dir );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}
}
