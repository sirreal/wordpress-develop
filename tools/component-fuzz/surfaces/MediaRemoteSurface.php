<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes remote media download and sideload helpers without live network IO.
 */
final class MediaRemoteSurface {
	public const NAME = 'media-remote';

	private const PREVIEW_BYTES = 180;

	private static ?string $upload_root = null;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'media-remote.bootstrap-apis-available',
					'Required WordPress remote media APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot         = self::snapshot_state();
		$temp_root        = self::make_temp_root( $ctx );
		$cleanup_ok       = null === $temp_root;
		$cleanup_path     = $temp_root;
		$download_paths   = array();
		$restoration_ok   = false;
		$restoration_data = array();
		$rows             = array();

		try {
			if ( null === $temp_root ) {
				$rows[] = $ctx->skip(
					'media-remote.temp-root.available',
					'Could not create an isolated temporary directory.',
					array( 'sysTempDir' => sys_get_temp_dir() )
				);
			} else {
				self::prepare_runtime( $temp_root );

				$rows[] = self::check_download_url_contracts( $ctx->fork( 'download-url' ), $download_paths );
				$rows[] = self::check_media_sideload_image_flows( $ctx->fork( 'media-sideload-image' ), $temp_root, $download_paths );
				$rows[] = self::check_media_handle_sideload_branches( $ctx->fork( 'media-handle-sideload' ), $temp_root );
				$rows[] = self::check_media_handle_sideload_filter_contracts( $ctx->fork( 'media-handle-filters' ), $temp_root );
			}
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->result(
				'media-remote.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::$upload_root = null;
			foreach ( array_unique( array_filter( $download_paths, 'is_string' ) ) as $path ) {
				if ( self::is_safe_tracked_download_path( $path ) && file_exists( $path ) ) {
					@unlink( $path );
				}
			}
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
				$cleanup_ok = ! is_dir( $temp_root );
			}
			self::restore_state( $snapshot );
			$restoration_data = self::restoration_probe( $snapshot );
			$restoration_ok   = $restoration_data['restored'];
		}

		$rows[] = $ctx->result(
			'media-remote.cleanup.temp-files-filters-and-globals',
			$cleanup_ok && $restoration_ok && self::tracked_paths_absent( $download_paths ),
			array(
				'tempRoot'       => $cleanup_path,
				'cleaned'        => $cleanup_ok,
				'trackedTempMax' => count( array_unique( array_filter( $download_paths, 'is_string' ) ) ),
				'trackedCleaned' => self::tracked_paths_absent( $download_paths ),
				'restoration'    => $restoration_data,
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
		$url    = 'http://example.test/component-fuzz-media-remote';

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
				'add_post_meta',
				'current_time',
				'download_url',
				'get_attached_file',
				'get_allowed_mime_types',
				'get_post',
				'get_post_meta',
				'is_wp_error',
				'media_handle_sideload',
				'media_sideload_image',
				'sanitize_file_name',
				'validate_file',
				'wp_check_filetype_and_ext',
				'wp_generate_attachment_metadata',
				'wp_get_attachment_metadata',
				'wp_get_attachment_url',
				'wp_handle_sideload',
				'wp_insert_post',
				'wp_remote_retrieve_header',
				'wp_safe_remote_get',
				'wp_tempnam',
				'wp_update_attachment_metadata',
				'wp_upload_dir',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'WP_Error', 'WP_Post' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_download_url_contracts( \ComponentFuzz\FuzzContext $ctx, array &$download_paths ): array {
		$failures  = array();
		$events    = array();
		$error_cap = $ctx->int( 7, 19 );

		$disposition_name     = '../remote ' . $ctx->identifier( 3, 8 ) . ' photo.PNG';
		$disposition_header   = 'attachment; filename=' . $disposition_name;
		$expected_disposition = \sanitize_file_name( strtolower( substr( $disposition_header, 21 ) ) );

		$ok_body     = "component fuzz remote\n" . $ctx->ascii( 8, 32 );
		$image_body  = self::png_bytes();
		$error_body  = 'error-prefix-' . $ctx->ascii( 20, 36 );
		$md5_body    = 'md5 mismatch ' . $ctx->ascii( 8, 16 );
		$request_url = 'https://example.test/component-fuzz/direct-' . rawurlencode( $ctx->identifier( 4, 10 ) ) . '.bin';

		$routes = array(
			$request_url => array(
				'body'    => $ok_body,
				'code'    => 200,
				'headers' => array(
					'Content-Disposition' => $disposition_header,
					'content-type'         => 'application/octet-stream',
				),
			),
			'https://example.test/component-fuzz/no-extension-' . $ctx->identifier( 4, 8 ) => array(
				'body'    => $image_body,
				'code'    => 200,
				'headers' => array(
					'content-type' => 'image/png',
				),
			),
			'https://example.test/component-fuzz/status-' . $ctx->identifier( 4, 8 ) . '.jpg' => array(
				'body'    => $error_body,
				'code'    => 418,
				'message' => 'Synthetic remote failure',
				'headers' => array(
					'content-type' => 'text/plain',
				),
			),
			'https://example.test/component-fuzz/md5-' . $ctx->identifier( 4, 8 ) . '.txt' => array(
				'body'    => $md5_body,
				'code'    => 200,
				'headers' => array(
					'Content-MD5' => str_repeat( '0', 32 ),
					'content-type' => 'text/plain',
				),
			),
			'https://example.test/component-fuzz/http-error-' . $ctx->identifier( 4, 8 ) . '.png' => array(
				'errorCode'    => 'component_fuzz_http_error',
				'errorMessage' => 'Synthetic transport failure.',
			),
		);

		$urls = array_keys( $routes );

		$http_filter = self::http_interceptor( $routes, $events, $download_paths );
		$body_filter = static function () use ( $error_cap ): int {
			return $error_cap;
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		\add_filter( 'download_url_error_max_body_size', $body_filter );

		try {
			$downloaded = \download_url( $request_url, 13 );
			self::collect_failure(
				$failures,
				is_string( $downloaded )
					&& is_file( $downloaded )
					&& $expected_disposition === basename( $downloaded )
					&& false === strpbrk( basename( $downloaded ), "/\\" )
					&& 0 === \validate_file( basename( $downloaded ) )
					&& $ok_body === file_get_contents( $downloaded ),
				'download_url stores 200 responses under sanitized Content-Disposition filenames',
				array(
					'result'              => self::describe_result( $downloaded ),
					'expectedDisposition' => $expected_disposition,
				)
			);
			if ( is_string( $downloaded ) ) {
				$download_paths[] = $downloaded;
				@unlink( $downloaded );
			}

			$typed = \download_url( $urls[1], 13 );
			self::collect_failure(
				$failures,
				is_string( $typed )
					&& is_file( $typed )
					&& 'png' === pathinfo( $typed, PATHINFO_EXTENSION )
					&& $image_body === file_get_contents( $typed ),
				'download_url infers safe extensions from Content-Type when the temp basename is .tmp',
				array( 'result' => self::describe_result( $typed ) )
			);
			if ( is_string( $typed ) ) {
				$download_paths[] = $typed;
				@unlink( $typed );
			}

			$status_error = \download_url( $urls[2], 13 );
			$status_data  = \is_wp_error( $status_error ) ? $status_error->get_error_data() : null;
			self::collect_failure(
				$failures,
				\is_wp_error( $status_error )
					&& 'http_404' === $status_error->get_error_code()
					&& is_array( $status_data )
					&& 418 === (int) ( $status_data['code'] ?? 0 )
					&& substr( $error_body, 0, $error_cap ) === (string) ( $status_data['body'] ?? null ),
				'download_url non-200 errors include bounded response body data and delete temp files',
				array(
					'result'   => self::describe_error( $status_error ),
					'bodyCap'  => $error_cap,
					'bodySize' => is_array( $status_data ) ? strlen( (string) ( $status_data['body'] ?? '' ) ) : null,
				)
			);

			$md5_error = \download_url( $urls[3], 13 );
			self::collect_failure(
				$failures,
				\is_wp_error( $md5_error ) && 'md5_mismatch' === $md5_error->get_error_code(),
				'download_url returns md5_mismatch and removes the temp file for invalid Content-MD5',
				array( 'result' => self::describe_error( $md5_error ) )
			);

			$transport_error = \download_url( $urls[4], 13 );
			self::collect_failure(
				$failures,
				\is_wp_error( $transport_error ) && 'component_fuzz_http_error' === $transport_error->get_error_code(),
				'download_url preserves WP_Error transport failures and removes the temp file',
				array( 'result' => self::describe_error( $transport_error ) )
			);
		} finally {
			\remove_filter( 'download_url_error_max_body_size', $body_filter );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		$registered_events = array_filter(
			$events,
			static function ( array $event ): bool {
				return ! empty( $event['registered'] );
			}
		);
		$streamed_events   = array_filter(
			$registered_events,
			static function ( array $event ): bool {
				return ! empty( $event['stream'] ) && is_string( $event['filename'] ?? null ) && '' !== $event['filename'];
			}
		);

		self::collect_failure(
			$failures,
			count( $routes ) === count( $registered_events )
				&& count( $registered_events ) === count( $streamed_events )
				&& array() === array_filter(
					$events,
					static function ( array $event ): bool {
						return empty( $event['registered'] );
					}
				)
				&& self::tracked_paths_absent( $download_paths ),
			'pre_http_request short-circuits every download_url request and no live HTTP fallback is possible',
			array(
				'events'              => $events,
				'trackedPathsCleaned' => self::tracked_paths_absent( $download_paths ),
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_http_request', $http_filter )
				&& false === \has_filter( 'download_url_error_max_body_size', $body_filter ),
			'download_url filters are removed after the check',
			array(
				'preHttp' => \has_filter( 'pre_http_request', $http_filter ),
				'bodyCap' => \has_filter( 'download_url_error_max_body_size', $body_filter ),
			)
		);

		return $ctx->result(
			'media-remote.download-url-http-temp-error-contracts',
			array() === $failures,
			array(
				'cases'    => count( $routes ),
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_media_sideload_image_flows( \ComponentFuzz\FuzzContext $ctx, string $temp_root, array &$download_paths ): array {
		$failures      = array();
		$http_events   = array();
		$upload_events = array();
		$before        = self::content_counts();
		$upload_root   = self::$upload_root;

		$valid_url      = 'https://example.test/component-fuzz/media-photo-' . rawurlencode( $ctx->identifier( 4, 9 ) ) . '.png?token=' . rawurlencode( $ctx->identifier( 3, 8 ) );
		$html_url       = 'https://example.test/component-fuzz/media-html-' . rawurlencode( $ctx->identifier( 4, 9 ) ) . '.png';
		$empty_url      = 'https://example.test/component-fuzz/media-empty-' . rawurlencode( $ctx->identifier( 4, 9 ) ) . '.png';
		$spoofed_url    = 'https://example.test/component-fuzz/media-spoofed-' . rawurlencode( $ctx->identifier( 4, 9 ) ) . '.png';
		$invalid_url    = 'https://example.test/component-fuzz/media-invalid-' . rawurlencode( $ctx->identifier( 4, 9 ) ) . '.php';
		$valid_body     = self::png_bytes();
		$desc           = 'Remote desc ' . $ctx->identifier( 3, 8 ) . ' <quoted>';
		$parent_id      = self::insert_parent_post( $ctx );
		$extension_hits = array();

		$routes = array(
			$valid_url   => array(
				'body'    => $valid_body,
				'code'    => 200,
				'headers' => array(
					'content-type' => 'image/png',
				),
			),
			$html_url    => array(
				'body'    => $valid_body,
				'code'    => 200,
				'headers' => array(
					'content-type' => 'image/png',
				),
			),
			$empty_url   => array(
				'body'    => '',
				'code'    => 200,
				'headers' => array(
					'content-type' => 'image/png',
				),
			),
			$spoofed_url => array(
				'body'    => "<?php echo 'not an image';\n",
				'code'    => 200,
				'headers' => array(
					'content-type' => 'image/png',
				),
			),
		);

		$http_filter = self::http_interceptor( $routes, $http_events, $download_paths );
		$upload_dir_filter = static function ( array $uploads ) use ( &$upload_events ): array {
			$filtered        = MediaRemoteSurface::filter_upload_dir( $uploads );
			$upload_events[] = array(
				'path'   => $filtered['path'] ?? null,
				'subdir' => $filtered['subdir'] ?? null,
			);
			return $filtered;
		};
		$extension_filter  = static function ( array $extensions, string $file ) use ( &$extension_hits ): array {
			$extension_hits[] = array(
				'file'       => $file,
				'extensions' => $extensions,
			);

			return $extensions;
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'image_sideload_extensions', $extension_filter, 10, 2 );

		try {
			$id = \media_sideload_image( $valid_url, $parent_id, $desc, 'id' );
			self::assert_remote_attachment(
				$failures,
				$id,
				array(
					'url'        => $valid_url,
					'parentId'   => $parent_id,
					'title'      => $desc,
					'mime'       => 'image/png',
					'uploadRoot' => $upload_root,
				)
			);

			if ( is_int( $id ) ) {
				$source_url = \get_post_meta( $id, '_source_url', true );
				self::collect_failure(
					$failures,
					$valid_url === $source_url,
					'media_sideload_image stores the original remote URL in _source_url metadata',
					array(
						'id'        => $id,
						'sourceUrl' => $source_url,
					)
				);
			}

			$html = \media_sideload_image( $html_url, 0, $desc, 'html' );
			self::collect_failure(
				$failures,
				is_string( $html )
					&& str_starts_with( $html, '<img ' )
					&& str_contains( $html, "src='http://example.test/component-fuzz-media-remote/" )
					&& str_contains( $html, "alt='Remote desc" )
					&& str_contains( $html, '&lt;quoted&gt;' ),
				'media_sideload_image html return type includes escaped alt text and filtered upload URL',
				array( 'html' => self::describe_result( $html ) )
			);

			$empty_error = \media_sideload_image( $empty_url, 0, null, 'id' );
			self::collect_failure(
				$failures,
				\is_wp_error( $empty_error ) && 'upload_error' === $empty_error->get_error_code(),
				'media_sideload_image rejects empty remote bodies through media_handle_sideload',
				array( 'result' => self::describe_error( $empty_error ) )
			);

			$spoofed_error = \media_sideload_image( $spoofed_url, 0, null, 'id' );
			self::collect_failure(
				$failures,
				\is_wp_error( $spoofed_error ) && 'upload_error' === $spoofed_error->get_error_code(),
				'media_sideload_image rejects URL-extension/body-MIME mismatches after download',
				array( 'result' => self::describe_error( $spoofed_error ) )
			);

			$invalid_error = \media_sideload_image( $invalid_url, 0, null, 'id' );
			self::collect_failure(
				$failures,
				\is_wp_error( $invalid_error ) && 'image_sideload_failed' === $invalid_error->get_error_code(),
				'media_sideload_image rejects URLs outside the filtered image extension set before HTTP',
				array( 'result' => self::describe_error( $invalid_error ) )
			);
		} finally {
			\remove_filter( 'image_sideload_extensions', $extension_filter, 10 );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		$after        = self::content_counts();
		$invalid_hits = array_filter(
			$http_events,
			static function ( array $event ) use ( $invalid_url ): bool {
				return $invalid_url === ( $event['url'] ?? null );
			}
		);

		self::collect_failure(
			$failures,
			array() === $invalid_hits
				&& count( $routes ) === count(
					array_filter(
						$http_events,
						static function ( array $event ): bool {
							return ! empty( $event['registered'] );
						}
					)
				)
				&& self::tracked_paths_absent( $download_paths ),
			'media_sideload_image remote fetches are fully short-circuited and rejected extensions do not hit HTTP',
			array(
				'httpEvents'          => $http_events,
				'invalidUrl'          => $invalid_url,
				'trackedPathsCleaned' => self::tracked_paths_absent( $download_paths ),
			)
		);

		self::collect_failure(
			$failures,
			( $after['posts'] ?? 0 ) >= ( $before['posts'] ?? 0 ) + 2
				&& ( $after['post_meta'] ?? 0 ) >= ( $before['post_meta'] ?? 0 ) + 4
				&& null !== $upload_root
				&& array() !== $upload_events
				&& self::upload_events_within_root( $upload_events, $upload_root ),
			'media_sideload_image successes create attachments only inside the filtered upload root',
			array(
				'before'       => $before,
				'after'        => $after,
				'uploadEvents' => $upload_events,
				'uploadRoot'   => $upload_root,
			)
		);

		self::collect_failure(
			$failures,
			array() !== $extension_hits
				&& false === \has_filter( 'image_sideload_extensions', $extension_filter )
				&& false === \has_filter( 'upload_dir', $upload_dir_filter )
				&& false === \has_filter( 'pre_http_request', $http_filter ),
			'media_sideload_image extension, upload, and HTTP filters are local to the check',
			array(
				'extensionHits' => $extension_hits,
				'imageFilter'   => \has_filter( 'image_sideload_extensions', $extension_filter ),
				'uploadFilter'  => \has_filter( 'upload_dir', $upload_dir_filter ),
				'httpFilter'    => \has_filter( 'pre_http_request', $http_filter ),
			)
		);

		self::cleanup_leftover_sources( $temp_root . DIRECTORY_SEPARATOR . 'downloaded' );

		return $ctx->result(
			'media-remote.media-sideload-image-remote-boundaries',
			array() === $failures,
			array(
				'cases'    => 5,
				'failures' => array_slice( $failures, 0, 10 ),
				'http'     => $http_events,
				'uploads'  => $upload_events,
			)
		);
	}

	private static function check_media_handle_sideload_branches( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$source_dir    = $temp_root . DIRECTORY_SEPARATOR . 'handle-source';
		$upload_events = array();
		$upload_root   = self::$upload_root;
		$parent_id     = self::insert_parent_post_with_date( $ctx, '2023-11-12 10:00:00' );

		$upload_dir_filter = static function ( array $uploads ) use ( &$upload_events ): array {
			$filtered        = MediaRemoteSurface::filter_upload_dir( $uploads );
			$upload_events[] = array(
				'path'   => $filtered['path'] ?? null,
				'subdir' => $filtered['subdir'] ?? null,
			);
			return $filtered;
		};

		\add_filter( 'upload_dir', $upload_dir_filter );

		try {
			$post_date_case = array(
				'label'    => 'post-data-date-desc',
				'filename' => self::client_filename( $ctx->fork( 'date-filename' ), 'txt' ),
				'mime'     => 'text/plain',
				'bytes'    => "dated sideload\n" . $ctx->ascii( 4, 24 ),
				'desc'     => 'Remote sideload desc ' . $ctx->identifier( 3, 8 ),
				'postData' => array(
					'post_date' => '2024-07-09 12:00:00',
				),
			);
			$post_date_path = self::write_fixture( $source_dir, 'post-date.txt', $post_date_case['bytes'] );
			$post_date_id   = null === $post_date_path ? null : \media_handle_sideload( self::file_array( $post_date_case, $post_date_path ), 0, $post_date_case['desc'], $post_date_case['postData'] );
			self::assert_handle_sideload_attachment(
				$failures,
				$post_date_id,
				array(
					'case'              => $post_date_case,
					'expectedTitle'     => $post_date_case['desc'],
					'expectedParent'    => 0,
					'expectedDate'      => '2024-07-09 12:00:00',
					'expectedPathPart'  => '/2024/07/',
					'uploadRoot'        => $upload_root,
					'originalSource'    => $post_date_path,
					'forbiddenPostId'   => null,
				)
			);

			$parent_date_case = array(
				'label'    => 'parent-date-id-unset',
				'filename' => self::client_filename( $ctx->fork( 'parent-filename' ), 'txt' ),
				'mime'     => 'text/plain',
				'bytes'    => "parent date sideload\n" . $ctx->ascii( 4, 24 ),
				'desc'     => null,
				'postData' => array(
					'ID'         => 810000 + $ctx->iteration(),
					'post_title' => 'Post data title ' . $ctx->identifier( 3, 8 ),
				),
			);
			$parent_date_path = self::write_fixture( $source_dir, 'parent-date.txt', $parent_date_case['bytes'] );
			$parent_date_id   = null === $parent_date_path ? null : \media_handle_sideload( self::file_array( $parent_date_case, $parent_date_path ), $parent_id, $parent_date_case['desc'], $parent_date_case['postData'] );
			self::assert_handle_sideload_attachment(
				$failures,
				$parent_date_id,
				array(
					'case'              => $parent_date_case,
					'expectedTitle'     => $parent_date_case['postData']['post_title'],
					'expectedParent'    => $parent_id,
					'expectedDate'      => null,
					'expectedPathPart'  => '/2023/11/',
					'uploadRoot'        => $upload_root,
					'originalSource'    => $parent_date_path,
					'forbiddenPostId'   => $parent_date_case['postData']['ID'],
				)
			);

			$before_error = self::content_counts();
			$error_case   = array(
				'label'    => 'no-file-error',
				'filename' => self::client_filename( $ctx->fork( 'error-filename' ), 'png' ),
				'mime'     => 'image/png',
				'bytes'    => '',
				'error'    => UPLOAD_ERR_NO_FILE,
			);
			$error_result = \media_handle_sideload( self::file_array( $error_case, $source_dir . DIRECTORY_SEPARATOR . 'missing.tmp' ), 0 );
			$after_error  = self::content_counts();

			self::collect_failure(
				$failures,
				\is_wp_error( $error_result )
					&& 'upload_error' === $error_result->get_error_code()
					&& ( $before_error['posts'] ?? null ) === ( $after_error['posts'] ?? null )
					&& ( $before_error['post_meta'] ?? null ) === ( $after_error['post_meta'] ?? null ),
				'media_handle_sideload upload-error branches return upload_error without attachment side effects',
				array(
					'result' => self::describe_error( $error_result ),
					'before' => $before_error,
					'after'  => $after_error,
				)
			);
		} finally {
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		self::collect_failure(
			$failures,
			null !== $upload_root
				&& array() !== $upload_events
				&& self::upload_events_within_root( $upload_events, $upload_root )
				&& false === \has_filter( 'upload_dir', $upload_dir_filter ),
			'media_handle_sideload upload_dir filter is scoped and every upload event stays inside the temp root',
			array(
				'uploadRoot'   => $upload_root,
				'uploadEvents' => $upload_events,
				'hasFilter'    => \has_filter( 'upload_dir', $upload_dir_filter ),
			)
		);

		return $ctx->result(
			'media-remote.media-handle-sideload-date-title-error-branches',
			array() === $failures,
			array(
				'cases'    => 3,
				'failures' => array_slice( $failures, 0, 10 ),
				'uploads'  => $upload_events,
			)
		);
	}

	private static function check_media_handle_sideload_filter_contracts( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures         = array();
		$source_dir       = $temp_root . DIRECTORY_SEPARATOR . 'handle-filter-source';
		$upload_events    = array();
		$prefilter_events = array();
		$override_events  = array();
		$upload_root      = self::$upload_root;
		$parent_id        = self::insert_parent_post_with_date( $ctx, '2022-05-06 09:15:00' );
		$forced_base      = 'forced-' . preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $ctx->identifier( 4, 10 ) ) );
		$forced_base      = trim( $forced_base, '-' );
		$forced_name      = null;
		$reject_name      = null;

		$upload_dir_filter = static function ( array $uploads ) use ( &$upload_events ): array {
			$filtered        = MediaRemoteSurface::filter_upload_dir( $uploads );
			$upload_events[] = array(
				'path'   => $filtered['path'] ?? null,
				'subdir' => $filtered['subdir'] ?? null,
			);
			return $filtered;
		};
		$prefilter = static function ( array $file ) use ( &$prefilter_events, &$reject_name ): array {
			$prefilter_events[] = array(
				'name'  => $file['name'] ?? null,
				'error' => $file['error'] ?? null,
				'size'  => $file['size'] ?? null,
			);

			if ( is_string( $reject_name ) && $reject_name === ( $file['name'] ?? null ) ) {
				$file['error'] = 'component fuzz prefilter rejected ' . $reject_name;
			}

			return $file;
		};
		$overrides_filter = static function ( $overrides, array $file ) use ( &$override_events, &$forced_name, $forced_base ) {
			$overrides = is_array( $overrides ) ? $overrides : array();
			$override_events[] = array(
				'name'     => $file['name'] ?? null,
				'testForm' => $overrides['test_form'] ?? null,
			);

			if ( is_string( $forced_name ) && $forced_name === ( $file['name'] ?? null ) ) {
				$overrides['unique_filename_callback'] = static function ( string $dir, string $name, string $ext ) use ( $forced_base ): string {
					unset( $dir, $name );
					return $forced_base . $ext;
				};
			}

			return $overrides;
		};

		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'wp_handle_sideload_prefilter', $prefilter );
		\add_filter( 'wp_handle_sideload_overrides', $overrides_filter, 10, 2 );

		try {
			$forced_case = array(
				'label'    => 'overrides-unique-filename',
				'filename' => self::client_filename( $ctx->fork( 'forced-name' ), 'txt' ),
				'mime'     => 'text/plain',
				'bytes'    => "override filename sideload\n" . $ctx->ascii( 6, 24 ),
				'desc'     => null,
				'postData' => array(),
			);
			$forced_name = $forced_case['filename'];
			$forced_path = self::write_fixture( $source_dir, 'forced-name.txt', $forced_case['bytes'] );
			$forced_id   = null === $forced_path ? null : \media_handle_sideload( self::file_array( $forced_case, $forced_path ), $parent_id, null, array() );

			self::assert_handle_sideload_attachment(
				$failures,
				$forced_id,
				array(
					'case'              => $forced_case,
					'expectedTitle'     => $forced_base,
					'expectedParent'    => $parent_id,
					'expectedDate'      => null,
					'expectedPathPart'  => '/2022/05/',
					'expectedBasename'  => $forced_base . '.txt',
					'uploadRoot'        => $upload_root,
					'originalSource'    => $forced_path,
					'forbiddenPostId'   => null,
				)
			);

			$before_reject = self::content_counts();
			$reject_case   = array(
				'label'    => 'prefilter-error',
				'filename' => self::client_filename( $ctx->fork( 'reject-name' ), 'txt' ),
				'mime'     => 'text/plain',
				'bytes'    => "prefilter rejected sideload\n" . $ctx->ascii( 6, 24 ),
			);
			$reject_name   = $reject_case['filename'];
			$reject_path   = self::write_fixture( $source_dir, 'prefilter-reject.txt', $reject_case['bytes'] );
			$reject_result = null === $reject_path ? null : \media_handle_sideload( self::file_array( $reject_case, $reject_path ), 0 );
			$after_reject  = self::content_counts();

			self::collect_failure(
				$failures,
				\is_wp_error( $reject_result )
					&& 'upload_error' === $reject_result->get_error_code()
					&& str_contains( $reject_result->get_error_message(), 'component fuzz prefilter rejected' )
					&& ( $before_reject['posts'] ?? null ) === ( $after_reject['posts'] ?? null )
					&& ( $before_reject['post_meta'] ?? null ) === ( $after_reject['post_meta'] ?? null )
					&& is_string( $reject_path )
					&& file_exists( $reject_path ),
				'wp_handle_sideload_prefilter errors stop attachment creation before moving the temp source',
				array(
					'result'       => self::describe_error( $reject_result ),
					'before'       => $before_reject,
					'after'        => $after_reject,
					'sourceExists' => is_string( $reject_path ) ? file_exists( $reject_path ) : null,
				)
			);
		} finally {
			\remove_filter( 'wp_handle_sideload_overrides', $overrides_filter, 10 );
			\remove_filter( 'wp_handle_sideload_prefilter', $prefilter );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		self::collect_failure(
			$failures,
			null !== $upload_root
				&& array() !== $upload_events
				&& self::upload_events_within_root( $upload_events, $upload_root )
				&& false === \has_filter( 'upload_dir', $upload_dir_filter )
				&& false === \has_filter( 'wp_handle_sideload_prefilter', $prefilter )
				&& false === \has_filter( 'wp_handle_sideload_overrides', $overrides_filter ),
			'media_handle_sideload upload, prefilter, and override filters are scoped to the filter contract check',
			array(
				'uploadRoot' => $upload_root,
				'uploads'    => $upload_events,
				'prefilter'  => $prefilter_events,
				'overrides'  => $override_events,
				'hasFilters' => array(
					'uploadDir'  => \has_filter( 'upload_dir', $upload_dir_filter ),
					'prefilter'  => \has_filter( 'wp_handle_sideload_prefilter', $prefilter ),
					'overrides'  => \has_filter( 'wp_handle_sideload_overrides', $overrides_filter ),
				),
			)
		);

		self::collect_failure(
			$failures,
			self::event_contains_name( $prefilter_events, (string) $forced_name )
				&& self::event_contains_name( $prefilter_events, (string) $reject_name )
				&& self::event_contains_name( $override_events, (string) $forced_name )
				&& self::event_contains_name( $override_events, (string) $reject_name ),
			'media_handle_sideload prefilter and overrides filters observe both success and rejection fixtures',
			array(
				'forcedName' => $forced_name,
				'rejectName' => $reject_name,
				'prefilter'  => $prefilter_events,
				'overrides'  => $override_events,
			)
		);

		return $ctx->result(
			'media-remote.media-handle-sideload-filter-contracts',
			array() === $failures,
			array(
				'cases'     => 2,
				'forced'    => $forced_base,
				'failures'  => array_slice( $failures, 0, 10 ),
				'prefilter' => $prefilter_events,
				'overrides' => $override_events,
				'uploads'   => $upload_events,
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
			foreach ( self::predicted_download_rename_paths( $filename, $route ) as $predicted_path ) {
				$download_paths[] = $predicted_path;
			}

			if ( isset( $route['errorCode'] ) ) {
				return new \WP_Error( (string) $route['errorCode'], (string) ( $route['errorMessage'] ?? 'Synthetic HTTP error.' ) );
			}

			$body = (string) ( $route['body'] ?? '' );
			if ( '' !== $filename ) {
				\ComponentFuzz\ensure_dir( dirname( $filename ) );
				file_put_contents( $filename, $body );
			}

			return array(
				'headers'  => $route['headers'] ?? array(),
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

	private static function predicted_download_rename_paths( string $filename, array $route ): array {
		if ( '' === $filename ) {
			return array();
		}

		$paths   = array();
		$headers = $route['headers'] ?? array();

		if ( isset( $headers['Content-Disposition'] ) ) {
			$content_disposition = strtolower( (string) $headers['Content-Disposition'] );
			if ( str_starts_with( $content_disposition, 'attachment; filename=' ) ) {
				$disposition_name = \sanitize_file_name( substr( $content_disposition, 21 ) );
				if ( $disposition_name && 0 === \validate_file( $disposition_name ) ) {
					$paths[] = dirname( $filename ) . DIRECTORY_SEPARATOR . $disposition_name;
				}
			}
		}

		if ( 'tmp' === pathinfo( $filename, PATHINFO_EXTENSION ) && isset( $headers['content-type'] ) ) {
			$extension = self::extension_for_mime_type( (string) $headers['content-type'] );
			if ( null !== $extension ) {
				$paths[] = substr( $filename, 0, -4 ) . '.' . $extension;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	private static function extension_for_mime_type( string $mime_type ): ?string {
		$valid_mime_types = array_flip( \get_allowed_mime_types() );
		if ( empty( $valid_mime_types[ $mime_type ] ) ) {
			return null;
		}

		$extensions = explode( '|', $valid_mime_types[ $mime_type ] );
		return $extensions[0] ?? null;
	}

	private static function assert_remote_attachment( array &$failures, $attachment_id, array $expected ): void {
		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			self::collect_failure(
				$failures,
				false,
				'remote image sideload returns a positive attachment ID',
				array(
					'expected' => $expected,
					'result'   => self::describe_result( $attachment_id ),
				)
			);
			return;
		}

		$post          = \get_post( $attachment_id );
		$attached_file = \get_attached_file( $attachment_id );
		$metadata      = \wp_get_attachment_metadata( $attachment_id );

		self::collect_failure(
			$failures,
			$post instanceof \WP_Post
				&& 'attachment' === $post->post_type
				&& 'inherit' === $post->post_status
				&& (int) $post->post_parent === (int) $expected['parentId']
				&& (string) $post->post_title === (string) $expected['title']
				&& (string) $post->post_mime_type === (string) $expected['mime'],
			'remote sideload attachment post row preserves parent, title, status, and MIME',
			array(
				'id'   => $attachment_id,
				'post' => $post instanceof \WP_Post
					? array(
						'ID'             => $post->ID,
						'post_parent'    => $post->post_parent,
						'post_status'    => $post->post_status,
						'post_title'     => $post->post_title,
						'post_mime_type' => $post->post_mime_type,
					)
					: self::describe_result( $post ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $attached_file )
				&& null !== $expected['uploadRoot']
				&& self::path_starts_with( $attached_file, (string) $expected['uploadRoot'] )
				&& is_file( $attached_file )
				&& basename( $attached_file ) === \sanitize_file_name( basename( $attached_file ) ),
			'remote sideload attachment file is moved into the filtered upload root with a sanitized basename',
			array(
				'id'           => $attachment_id,
				'attachedFile' => $attached_file,
				'uploadRoot'   => $expected['uploadRoot'],
			)
		);

		self::collect_failure(
			$failures,
			is_array( $metadata ) && isset( $metadata['filesize'] ) && (int) $metadata['filesize'] > 0,
			'remote sideload stores generated attachment metadata for successful images',
			array(
				'id'       => $attachment_id,
				'metadata' => $metadata,
			)
		);
	}

	private static function assert_handle_sideload_attachment( array &$failures, $attachment_id, array $expected ): void {
		$case = $expected['case'];

		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			self::collect_failure(
				$failures,
				false,
				'media_handle_sideload returns a positive attachment ID for branch fixture',
				array(
					'case'   => self::case_summary( $case ),
					'result' => self::describe_result( $attachment_id ),
				)
			);
			return;
		}

		$post          = \get_post( $attachment_id );
		$attached_file = \get_attached_file( $attachment_id );
		$metadata      = \wp_get_attachment_metadata( $attachment_id );
		$forbidden_id  = $expected['forbiddenPostId'];

		self::collect_failure(
			$failures,
			$post instanceof \WP_Post
				&& 'attachment' === $post->post_type
				&& 'inherit' === $post->post_status
				&& (int) $post->post_parent === (int) $expected['expectedParent']
				&& (string) $post->post_title === (string) $expected['expectedTitle']
				&& ( null === $expected['expectedDate'] || (string) $post->post_date === (string) $expected['expectedDate'] )
				&& ( null === $forbidden_id || (int) $post->ID !== (int) $forbidden_id ),
			'media_handle_sideload applies desc/post_data title, post_date, parent, and ID-unset contracts',
			array(
				'case'        => self::case_summary( $case ),
				'post'        => $post instanceof \WP_Post
					? array(
						'ID'          => $post->ID,
						'post_parent' => $post->post_parent,
						'post_title'  => $post->post_title,
						'post_date'   => $post->post_date,
					)
					: self::describe_result( $post ),
				'forbiddenId' => $forbidden_id,
			)
		);

		$normalized_file = is_string( $attached_file ) ? wp_normalize_path( $attached_file ) : '';
		self::collect_failure(
			$failures,
			is_string( $attached_file )
				&& null !== $expected['uploadRoot']
				&& self::path_starts_with( $attached_file, (string) $expected['uploadRoot'] )
				&& str_contains( $normalized_file, (string) $expected['expectedPathPart'] )
				&& ( ! isset( $expected['expectedBasename'] ) || basename( $attached_file ) === (string) $expected['expectedBasename'] )
				&& is_file( $attached_file )
				&& null !== $expected['originalSource']
				&& ! file_exists( (string) $expected['originalSource'] ),
			'media_handle_sideload chooses upload subdirectories from post_data or parent post dates and moves temp source',
			array(
				'case'             => self::case_summary( $case ),
				'attachedFile'     => $attached_file,
				'expectedPathPart' => $expected['expectedPathPart'],
				'expectedBasename' => $expected['expectedBasename'] ?? null,
				'uploadRoot'       => $expected['uploadRoot'],
				'sourceExists'     => null === $expected['originalSource'] ? null : file_exists( (string) $expected['originalSource'] ),
			)
		);

		self::collect_failure(
			$failures,
			is_array( $metadata )
				&& isset( $metadata['filesize'] )
				&& (int) $metadata['filesize'] === strlen( (string) $case['bytes'] ),
			'media_handle_sideload stores filesize metadata for non-image sideload fixtures',
			array(
				'case'     => self::case_summary( $case ),
				'metadata' => $metadata,
			)
		);
	}

	private static function prepare_runtime( string $temp_root ): void {
		$upload_root = $temp_root . DIRECTORY_SEPARATOR . 'uploads';
		\ComponentFuzz\ensure_dir( $upload_root );
		self::$upload_root = $upload_root;

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'admin_email'                    => 'admin@example.test',
					'blog_charset'                   => 'UTF-8',
					'blogname'                       => 'Component Fuzz',
					'default_category'               => 0,
					'default_comment_status'         => 'closed',
					'default_ping_status'            => 'closed',
					'gmt_offset'                     => 0,
					'home'                           => 'http://example.test',
					'permalink_structure'            => '',
					'siteurl'                        => 'http://example.test',
					'start_of_week'                  => 1,
					'timezone_string'                => '',
					'upload_path'                    => '',
					'upload_url_path'                => '',
					'uploads_use_yearmonth_folders' => 1,
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		$GLOBALS['wp_post_types']   = array();
		$GLOBALS['wp_post_statuses'] = array();
		$GLOBALS['wp_taxonomies']   = array();
		$GLOBALS['wp_rewrite']      = new \WP_Rewrite();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_SERVER['REMOTE_ADDR']      = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT']  = 'ComponentFuzz MediaRemote';
		$_SERVER['REQUEST_URI']      = '/component-fuzz/media-remote/';
		$_SERVER['HTTP_HOST']        = 'example.test';
		$_SERVER['SERVER_SOFTWARE']  = 'ComponentFuzz';
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'wp_rewrite', 'current_user', 'user_ID' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_SOFTWARE' ) as $name ) {
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
			'server'  => $server,
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

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

		$_FILES = $snapshot['files'];
		$_GET   = $snapshot['get'];
		$_POST  = $snapshot['post'];
	}

	private static function restoration_probe( array $snapshot ): array {
		$globals_ok = true;
		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				$globals_ok = false;
				break;
			}
			if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
				$globals_ok = false;
				break;
			}
		}

		$server_ok = true;
		foreach ( $snapshot['server'] as $name => $entry ) {
			$exists = array_key_exists( $name, $_SERVER );
			if ( $exists !== $entry['exists'] ) {
				$server_ok = false;
				break;
			}
			if ( $exists && $_SERVER[ $name ] !== $entry['value'] ) {
				$server_ok = false;
				break;
			}
		}

		return array(
			'restored' => $globals_ok && $server_ok && $_FILES === $snapshot['files'] && $_GET === $snapshot['get'] && $_POST === $snapshot['post'],
			'globals'  => $globals_ok,
			'server'   => $server_ok,
			'files'    => $_FILES === $snapshot['files'],
			'get'      => $_GET === $snapshot['get'],
			'post'     => $_POST === $snapshot['post'],
		);
	}

	private static function insert_parent_post( \ComponentFuzz\FuzzContext $ctx ): int {
		return self::insert_parent_post_with_date(
			$ctx,
			sprintf( '2026-%02d-%02d 12:00:00', $ctx->int( 1, 12 ), $ctx->int( 1, 28 ) )
		);
	}

	private static function insert_parent_post_with_date( \ComponentFuzz\FuzzContext $ctx, string $post_date ): int {
		$post_id = \wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Component fuzz remote parent ' . $ctx->identifier( 3, 8 ),
				'post_content' => '',
				'post_date'    => $post_date,
			),
			true
		);

		return is_int( $post_id ) ? $post_id : 0;
	}

	private static function file_array( array $case, string $source_path ): array {
		return array(
			'name'     => $case['filename'],
			'type'     => $case['mime'],
			'tmp_name' => $source_path,
			'error'    => $case['error'] ?? UPLOAD_ERR_OK,
			'size'     => file_exists( $source_path ) ? filesize( $source_path ) : strlen( (string) $case['bytes'] ),
		);
	}

	private static function client_filename( \ComponentFuzz\FuzzContext $ctx, string $extension ): string {
		$bases = array(
			'remote fuzz ' . $ctx->identifier( 3, 10 ),
			'../remote-' . $ctx->identifier( 2, 6 ),
			'..\\remote ' . $ctx->identifier( 2, 6 ),
			'multi.remote.part.' . $ctx->identifier( 2, 6 ),
			'percent%20remote ' . $ctx->identifier( 2, 6 ),
		);
		$base  = $ctx->choice( $bases );

		return '' === $extension ? $base : $base . '.' . $extension;
	}

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-remote-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration();
		if ( file_exists( $base ) ) {
			self::remove_dir_recursive( $base );
		}

		if ( ! is_dir( $base ) && ! mkdir( $base, 0777, true ) && ! is_dir( $base ) ) {
			return null;
		}

		return $base;
	}

	private static function write_fixture( string $dir, string $filename, string $bytes ): ?string {
		\ComponentFuzz\ensure_dir( $dir );
		$path = $dir . DIRECTORY_SEPARATOR . \sanitize_file_name( $filename );

		if ( false === file_put_contents( $path, $bytes ) ) {
			return null;
		}

		return $path;
	}

	private static function cleanup_leftover_sources( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
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
	}

	private static function remove_dir_recursive( string $dir ): void {
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-remote-';
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

	private static function tracked_paths_absent( array $paths ): bool {
		foreach ( array_unique( array_filter( $paths, 'is_string' ) ) as $path ) {
			if ( file_exists( $path ) ) {
				return false;
			}
		}

		return true;
	}

	private static function is_safe_tracked_download_path( string $path ): bool {
		$normalized_path = wp_normalize_path( $path );
		$normalized_temp = trailingslashit( wp_normalize_path( sys_get_temp_dir() ) );

		return str_starts_with( $normalized_path, $normalized_temp ) && ! str_contains( substr( $normalized_path, strlen( $normalized_temp ) ), '/' );
	}

	private static function upload_events_within_root( array $events, string $upload_root ): bool {
		foreach ( $events as $event ) {
			if ( ! is_string( $event['path'] ?? null ) || ! self::path_starts_with( (string) $event['path'], $upload_root ) ) {
				return false;
			}
		}

		return true;
	}

	private static function event_contains_name( array $events, string $name ): bool {
		foreach ( $events as $event ) {
			if ( $name === (string) ( $event['name'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function path_starts_with( string $path, string $root ): bool {
		$real_path = realpath( $path );
		$real_root = realpath( $root );

		if ( false === $real_path || false === $real_root ) {
			$normalized_path = wp_normalize_path( $path );
			$normalized_root = wp_normalize_path( $root );
			return $normalized_path === $normalized_root || str_starts_with( $normalized_path, trailingslashit( $normalized_root ) );
		}

		$normalized_path = wp_normalize_path( $real_path );
		$normalized_root = wp_normalize_path( $real_root );
		return $normalized_path === $normalized_root || str_starts_with( $normalized_path, trailingslashit( $normalized_root ) );
	}

	private static function png_bytes(): string {
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=' );
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $details = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'details' => $details,
		);
	}

	private static function case_summary( array $case ): array {
		return array(
			'label'    => $case['label'] ?? null,
			'filename' => isset( $case['filename'] ) ? self::describe_string( (string) $case['filename'] ) : null,
			'mime'     => $case['mime'] ?? null,
			'size'     => isset( $case['bytes'] ) ? strlen( (string) $case['bytes'] ) : null,
		);
	}

	private static function describe_result( $value ) {
		if ( \is_wp_error( $value ) ) {
			return self::describe_error( $value );
		}

		return \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES );
	}

	private static function describe_error( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES );
		}

		return array(
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
		);
	}

	private static function describe_string( string $value ): string {
		return (string) \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES );
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
