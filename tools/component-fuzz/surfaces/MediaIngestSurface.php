<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes media upload and sideload ingest paths against temp fixtures only.
 */
final class MediaIngestSurface {
	public const NAME = 'media-ingest';

	private const SUCCESS_CASES         = 8;
	private const FILENAME_CASES        = 18;
	private const DIRECT_HANDLE_CASES   = 3;
	private const REJECTION_CASES       = 6;
	private const OVERRIDE_ERROR_CASES  = 6;
	private const PREVIEW_BYTES         = 180;
	private const WPDB_PRIVATE_PROPS    = array(
		'component_fuzz_options',
		'component_fuzz_posts',
		'component_fuzz_terms',
		'component_fuzz_term_taxonomy_rows',
		'component_fuzz_term_relationship_rows',
		'component_fuzz_users',
		'component_fuzz_comments',
		'component_fuzz_links',
		'component_fuzz_meta',
		'component_fuzz_next_ids',
	);
	private const WPDB_PUBLIC_PROPS     = array(
		'insert_id',
		'is_mysql',
		'last_error',
		'last_query',
		'num_rows',
		'rows_affected',
		'suppress_errors',
	);

	private static ?string $upload_root = null;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'media-ingest.bootstrap-apis-available',
					'Required WordPress media ingest APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot     = self::snapshot_state();
		$temp_root    = self::make_temp_root( $ctx );
		$cleanup_ok   = null === $temp_root;
		$cleanup_path = $temp_root;
		$restoration_ok   = false;
		$restoration_data = array();
		$rows             = array();

		try {
			if ( null === $temp_root ) {
				$rows[] = $ctx->skip(
					'media-ingest.temp-root.available',
					'Could not create an isolated temporary directory.',
					array( 'sysTempDir' => sys_get_temp_dir() )
				);
			} else {
				self::prepare_runtime( $temp_root );

				$rows[] = self::check_filename_and_filetype_boundaries( $ctx->fork( 'filename-filetype' ), $temp_root );
				$rows[] = self::check_prefilter_and_direct_handle_boundaries( $ctx->fork( 'direct-handles' ), $temp_root );
				$rows[] = self::check_handle_override_error_semantics( $ctx->fork( 'handle-overrides' ), $temp_root );
				foreach ( self::check_successful_ingest_flows( $ctx->fork( 'success' ), $temp_root ) as $row ) {
					$rows[] = $row;
				}
				$rows[] = self::check_rejection_paths( $ctx->fork( 'rejections' ), $temp_root );
				$rows[] = self::check_download_short_circuits( $ctx->fork( 'download-short-circuit' ), $temp_root );
				$rows[] = self::check_metadata_failure_paths( $ctx->fork( 'metadata-failures' ), $temp_root );
			}
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->result(
				'media-ingest.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::$upload_root = null;
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
				$cleanup_ok = ! is_dir( $temp_root );
			}
			self::restore_state( $snapshot );
			$restoration_data = self::restoration_probe( $snapshot );
			$restoration_ok   = $restoration_data['restored'];
		}

		$rows[] = $ctx->result(
			'media-ingest.cleanup.temp-files-and-globals',
			$cleanup_ok && $restoration_ok,
			array(
				'tempRoot'    => $cleanup_path,
				'cleaned'     => $cleanup_ok,
				'restoration' => $restoration_data,
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
		$url    = 'http://example.test/component-fuzz-media-ingest';

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
				'add_filter',
				'download_url',
				'get_attached_file',
				'get_post',
				'get_post_meta',
				'has_filter',
				'is_wp_error',
				'media_handle_sideload',
				'media_handle_upload',
				'remove_filter',
				'sanitize_file_name',
				'validate_file',
				'wp_basename',
				'wp_check_filetype',
				'wp_check_filetype_and_ext',
				'wp_generate_attachment_metadata',
				'wp_get_attachment_metadata',
				'wp_get_upload_dir',
				'wp_handle_sideload',
				'wp_handle_upload',
				'wp_insert_attachment',
				'wp_insert_post',
				'wp_unique_filename',
				'wp_update_attachment_metadata',
				'wp_upload_dir',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'Component_Fuzz_WPDB_Stub', 'WP_Error', 'WP_Post' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global $wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_filename_and_filetype_boundaries( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$case_rows     = array();
		$collision_dir = ( self::$upload_root ?? $temp_root ) . DIRECTORY_SEPARATOR . 'collisions';
		\ComponentFuzz\ensure_dir( $collision_dir );

		foreach ( self::filename_boundary_cases( $ctx ) as $case ) {
			$filename  = $case['filename'];
			$sanitized = \sanitize_file_name( $filename );
			$again     = \sanitize_file_name( $sanitized );
			$filetype  = \wp_check_filetype( $sanitized, self::allowed_mimes() );
			$case_rows[] = array(
				'label'     => $case['label'],
				'caseSeed'  => $case['caseSeed'],
				'input'     => self::describe_string( $filename ),
				'sanitized' => self::describe_string( $sanitized ),
				'filetype'  => $filetype,
			);

			self::collect_failure(
				$failures,
				'' !== $sanitized
					&& $sanitized === $again
					&& false === strpos( $sanitized, chr( 0 ) )
					&& false === strpbrk( $sanitized, "/\\" )
					&& 0 === \validate_file( $sanitized )
					&& strlen( $sanitized ) <= 255,
				'sanitize_file_name is idempotent, path-local, and filesystem-bounded',
				array(
					'case'      => $case,
					'filename'  => self::describe_string( $filename ),
					'sanitized' => self::describe_string( $sanitized ),
					'again'     => self::describe_string( $again ),
				)
			);

			self::collect_failure(
				$failures,
				( $case['allowed'] && $case['expectedType'] === ( $filetype['type'] ?? null ) && $case['expectedExt'] === strtolower( (string) ( $filetype['ext'] ?? '' ) ) )
					|| ( ! $case['allowed'] && false === ( $filetype['type'] ?? null ) && false === ( $filetype['ext'] ?? null ) ),
				'wp_check_filetype maps only the configured local MIME allowlist',
				array(
					'case'     => $case,
					'filetype' => $filetype,
				)
			);
		}

		self::check_unique_filename_boundaries( $failures, $collision_dir );

		$fixture_dir = $temp_root . DIRECTORY_SEPARATOR . 'filetype';
		$png_path    = self::write_fixture( $fixture_dir, 'actual-png.bin', self::png_bytes() );
		$text_path   = self::write_fixture( $fixture_dir, 'actual-text.bin', "hello component fuzz\n" );
		$spoof_path  = self::write_fixture( $fixture_dir, 'spoof-image.bin', "<?php echo 'not an image';\n" );
		$pdf_path    = self::write_fixture( $fixture_dir, 'actual-pdf.bin', "%PDF-1.4\n% component fuzz\n" );

		if ( null === $png_path || null === $text_path || null === $spoof_path || null === $pdf_path ) {
			self::collect_failure( $failures, false, 'fixture files are writable for filetype checks' );
		} else {
			$content_cases = array(
				array(
					'label'          => 'png-corrects-jpg',
					'path'           => $png_path,
					'name'           => 'unsafe path/../photo.JPG',
					'expectedExt'    => 'png',
					'expectedType'   => 'image/png',
					'properSuffix'   => 'photo.png',
					'expectedProper' => true,
				),
				array(
					'label'        => 'png-uppercase-ok',
					'path'         => $png_path,
					'name'         => 'photo.PNG',
					'expectedExt'  => 'png',
					'expectedType' => 'image/png',
				),
				array(
					'label'        => 'text-uppercase-ok',
					'path'         => $text_path,
					'name'         => 'NOTES.TXT',
					'expectedExt'  => 'txt',
					'expectedType' => 'text/plain',
				),
				array(
					'label'        => 'php-bytes-rejected',
					'path'         => $spoof_path,
					'name'         => 'payload.txt',
					'expectedExt'  => false,
					'expectedType' => false,
				),
				array(
					'label'        => 'php-extension-rejected',
					'path'         => $spoof_path,
					'name'         => 'payload.PhP',
					'expectedExt'  => false,
					'expectedType' => false,
				),
				array(
					'label'        => 'pdf-allowed-application',
					'path'         => $pdf_path,
					'name'         => 'local-document.PDF',
					'expectedExt'  => 'pdf',
					'expectedType' => 'application/pdf',
				),
				array(
					'label'        => 'missing-path-extension-only',
					'path'         => $fixture_dir . DIRECTORY_SEPARATOR . 'missing.png',
					'name'         => 'missing.png',
					'expectedExt'  => 'png',
					'expectedType' => 'image/png',
				),
			);

			foreach ( $content_cases as $content_case ) {
				$checked = \wp_check_filetype_and_ext( $content_case['path'], $content_case['name'], self::allowed_mimes() );
				$proper  = $checked['proper_filename'] ?? false;

				self::collect_failure(
					$failures,
					$content_case['expectedType'] === ( $checked['type'] ?? null )
						&& $content_case['expectedExt'] === ( is_string( $checked['ext'] ?? null ) ? strtolower( (string) $checked['ext'] ) : ( $checked['ext'] ?? null ) )
						&& ( empty( $content_case['expectedProper'] ) || ( is_string( $proper ) && str_ends_with( $proper, $content_case['properSuffix'] ) ) ),
					'wp_check_filetype_and_ext respects content, extension casing, and image correction boundaries',
					array(
						'case'    => $content_case,
						'checked' => $checked,
					)
				);
			}
		}

		return $ctx->result(
			'media-ingest.filename-filetype-boundaries',
			array() === $failures,
			array(
				'cases'    => count( $case_rows ) + 9,
				'filenames' => array_slice( $case_rows, 0, 8 ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_prefilter_and_direct_handle_boundaries( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures    = array();
		$source_dir  = $temp_root . DIRECTORY_SEPARATOR . 'direct-handle-source';
		$upload_root = self::$upload_root;
		$events      = array(
			'handled'    => array(),
			'moves'      => array(),
			'overrides'  => array(),
			'prefilters' => array(),
			'uploadDirs' => array(),
		);
		$before      = self::content_counts();
		$token       = $ctx->identifier( 4, 9 );
		$action      = 'component_fuzz_direct_upload';

		$upload_dir_filter = static function ( array $uploads ) use ( &$events ): array {
			$filtered = MediaIngestSurface::filter_upload_dir( $uploads );
			$events['uploadDirs'][] = array(
				'path'   => $filtered['path'] ?? null,
				'subdir' => $filtered['subdir'] ?? null,
			);
			return $filtered;
		};
		$handle_filter     = static function ( array $upload, string $context ) use ( &$events ): array {
			$events['handled'][] = array(
				'context'  => $context,
				'basename' => isset( $upload['file'] ) ? basename( (string) $upload['file'] ) : null,
				'type'     => $upload['type'] ?? null,
			);
			return $upload;
		};
		$standard_block    = static function ( array $file ) use ( &$events, $token ): array {
			$events['prefilters'][] = array(
				'hook' => 'wp_handle_upload_prefilter',
				'name' => $file['name'] ?? null,
			);
			$file['error'] = 'component fuzz upload prefilter blocked ' . $token;
			return $file;
		};
		$upload_prefilter  = static function ( array $file ) use ( &$events, $token ): array {
			$events['prefilters'][] = array(
				'hook' => 'component_fuzz_direct_upload_prefilter',
				'name' => $file['name'] ?? null,
			);
			$file['name'] = 'Direct Filtered ' . $token . '.TXT';
			$file['type'] = 'text/plain';
			return $file;
		};
		$upload_overrides  = static function ( $overrides, array $file ) use ( &$events ) {
			$events['overrides'][] = array(
				'hook' => 'component_fuzz_direct_upload_overrides',
				'name' => $file['name'] ?? null,
			);
			$overrides           = is_array( $overrides ) ? $overrides : array();
			$overrides['mimes']  = MediaIngestSurface::allowed_mimes();
			$overrides['action'] = 'component_fuzz_direct_upload';
			return $overrides;
		};
		$sideload_prefilter = static function ( array $file ) use ( &$events, $token ): array {
			$events['prefilters'][] = array(
				'hook' => 'wp_handle_sideload_prefilter',
				'name' => $file['name'] ?? null,
			);
			$file['name'] = 'Sideload Filtered ' . $token . '.TXT';
			$file['type'] = 'text/plain';
			return $file;
		};
		$sideload_overrides = static function ( $overrides, array $file ) use ( &$events ) {
			$events['overrides'][] = array(
				'hook' => 'wp_handle_sideload_overrides',
				'name' => $file['name'] ?? null,
			);
			$overrides          = is_array( $overrides ) ? $overrides : array();
			$overrides['mimes'] = MediaIngestSurface::allowed_mimes();
			return $overrides;
		};
		$move_filter       = static function ( $move_new_file, array $file, string $new_file, string $type ) use ( &$events, $action ): ?bool {
			$events['moves'][] = array(
				'name'        => $file['name'] ?? null,
				'newBasename' => basename( $new_file ),
				'type'        => $type,
				'short'       => isset( $file['component_fuzz_action'] ) && $action === $file['component_fuzz_action'],
			);

			if ( ! isset( $file['component_fuzz_action'] ) || $action !== $file['component_fuzz_action'] ) {
				return $move_new_file;
			}

			\ComponentFuzz\ensure_dir( dirname( $new_file ) );
			if ( @copy( $file['tmp_name'], $new_file ) ) {
				@unlink( $file['tmp_name'] );
				return true;
			}

			return false;
		};
		$error_handler     = static function ( array &$file, string $message ): array {
			return array(
				'error'                 => $message,
				'component_fuzz_name'   => $file['name'] ?? null,
				'component_fuzz_tmp'    => isset( $file['tmp_name'] ) ? basename( (string) $file['tmp_name'] ) : null,
				'component_fuzz_source' => isset( $file['tmp_name'] ) && file_exists( (string) $file['tmp_name'] ),
			);
		};

		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'wp_handle_upload', $handle_filter, 10, 2 );
		\add_filter( 'wp_handle_upload_prefilter', $standard_block );
		\add_filter( "{$action}_prefilter", $upload_prefilter );
		\add_filter( "{$action}_overrides", $upload_overrides, 10, 2 );
		\add_filter( 'wp_handle_sideload_prefilter', $sideload_prefilter );
		\add_filter( 'wp_handle_sideload_overrides', $sideload_overrides, 10, 2 );
		\add_filter( 'pre_move_uploaded_file', $move_filter, 10, 4 );

		try {
			$blocked_case = array(
				'label'       => 'standard-upload-prefilter-block',
				'filename'    => 'blocked-' . $token . '.txt',
				'fixtureName' => 'blocked.txt',
				'mime'        => 'text/plain',
				'bytes'       => 'blocked direct upload ' . $ctx->ascii( 3, 16 ),
			);
			$blocked_path = self::write_fixture( $source_dir, 'blocked.txt', $blocked_case['bytes'] );
			$blocked_file = null === $blocked_path ? array() : self::file_array( $blocked_case, $blocked_path );
			$blocked      = null === $blocked_path ? null : \wp_handle_upload(
				$blocked_file,
				array(
					'mimes'               => self::allowed_mimes(),
					'test_form'           => false,
					'upload_error_handler' => $error_handler,
				)
			);

			self::collect_failure(
				$failures,
				is_array( $blocked )
					&& isset( $blocked['error'] )
					&& str_contains( (string) $blocked['error'], 'component fuzz upload prefilter blocked' )
					&& null !== $blocked_path
					&& file_exists( $blocked_path ),
				'wp_handle_upload_prefilter can fail a local temp upload before move or DB effects',
				array(
					'case'   => self::case_summary( $blocked_case ),
					'result' => self::describe_result( $blocked ),
				)
			);
			if ( null !== $blocked_path ) {
				@unlink( $blocked_path );
			}

			$upload_case = array(
				'label'       => 'custom-upload-prefilter-pre-move',
				'filename'    => '../direct ' . $token . '.TXT',
				'fixtureName' => 'direct.txt',
				'mime'        => 'text/plain',
				'bytes'       => "direct upload\n" . $ctx->ascii( 8, 32 ),
			);
			$upload_path = self::write_fixture( $source_dir, 'direct-upload.txt', $upload_case['bytes'] );
			$upload_file = null === $upload_path ? array() : self::file_array( $upload_case, $upload_path );
			if ( null !== $upload_path ) {
				$upload_file['component_fuzz_action'] = $action;
			}
			$upload_result = null === $upload_path ? null : \wp_handle_upload(
				$upload_file,
				array(
					'action'               => $action,
					'mimes'                => self::allowed_mimes(),
					'test_form'            => false,
					'upload_error_handler' => $error_handler,
				)
			);

			self::assert_direct_handle_success(
				$failures,
				$upload_case,
				$upload_result,
				$upload_path,
				$upload_root,
				'upload',
				true
			);

			$sideload_case = array(
				'label'       => 'sideload-prefilter-core-move',
				'filename'    => '..\\sideload ' . $token . '.TXT',
				'fixtureName' => 'sideload.txt',
				'mime'        => 'text/plain',
				'bytes'       => "direct sideload\n" . $ctx->ascii( 8, 32 ),
			);
			$sideload_path = self::write_fixture( $source_dir, 'direct-sideload.txt', $sideload_case['bytes'] );
			$sideload_file = null === $sideload_path ? array() : self::file_array( $sideload_case, $sideload_path );
			$sideload      = null === $sideload_path ? null : \wp_handle_sideload(
				$sideload_file,
				array(
					'mimes'     => self::allowed_mimes(),
					'test_form' => false,
				)
			);

			self::assert_direct_handle_success(
				$failures,
				$sideload_case,
				$sideload,
				$sideload_path,
				$upload_root,
				'sideload',
				false
			);
		} finally {
			\remove_filter( 'pre_move_uploaded_file', $move_filter, 10 );
			\remove_filter( 'wp_handle_sideload_overrides', $sideload_overrides, 10 );
			\remove_filter( 'wp_handle_sideload_prefilter', $sideload_prefilter );
			\remove_filter( "{$action}_overrides", $upload_overrides, 10 );
			\remove_filter( "{$action}_prefilter", $upload_prefilter );
			\remove_filter( 'wp_handle_upload_prefilter', $standard_block );
			\remove_filter( 'wp_handle_upload', $handle_filter, 10 );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		$after = self::content_counts();

		self::collect_failure(
			$failures,
			$before === $after
				&& self::events_include_context( $events['handled'], 'upload' )
				&& self::events_include_context( $events['handled'], 'sideload' )
				&& self::upload_events_within_root( $events['uploadDirs'], (string) $upload_root ),
			'direct wp_handle_* checks move files locally without attachment DB writes',
			array(
				'before'     => $before,
				'after'      => $after,
				'events'     => $events,
				'uploadRoot' => $upload_root,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'upload_dir', $upload_dir_filter )
				&& false === \has_filter( 'wp_handle_upload', $handle_filter )
				&& false === \has_filter( 'wp_handle_upload_prefilter', $standard_block )
				&& false === \has_filter( "{$action}_prefilter", $upload_prefilter )
				&& false === \has_filter( "{$action}_overrides", $upload_overrides )
				&& false === \has_filter( 'wp_handle_sideload_prefilter', $sideload_prefilter )
				&& false === \has_filter( 'wp_handle_sideload_overrides', $sideload_overrides )
				&& false === \has_filter( 'pre_move_uploaded_file', $move_filter ),
			'direct handle filters are restored after prefilter and move checks',
			array(
				'uploadDir'        => \has_filter( 'upload_dir', $upload_dir_filter ),
				'handle'           => \has_filter( 'wp_handle_upload', $handle_filter ),
				'standardUpload'   => \has_filter( 'wp_handle_upload_prefilter', $standard_block ),
				'customUpload'     => \has_filter( "{$action}_prefilter", $upload_prefilter ),
				'customOverrides'  => \has_filter( "{$action}_overrides", $upload_overrides ),
				'sideload'         => \has_filter( 'wp_handle_sideload_prefilter', $sideload_prefilter ),
				'sideloadOverride' => \has_filter( 'wp_handle_sideload_overrides', $sideload_overrides ),
				'preMove'          => \has_filter( 'pre_move_uploaded_file', $move_filter ),
			)
		);

		return $ctx->result(
			'media-ingest.direct-handle-prefilter-move-boundaries',
			array() === $failures,
			array(
				'cases'    => self::DIRECT_HANDLE_CASES,
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_handle_override_error_semantics( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$source_dir    = $temp_root . DIRECTORY_SEPARATOR . 'override-error-source';
		$upload_root   = self::$upload_root;
		$case_rows     = array();
		$token         = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $ctx->identifier( 4, 9 ) ) ), '-' );
		if ( '' === $token ) {
			$token = 'token-' . $ctx->int( 1000, 9999 );
		}
		$form_action   = 'component_fuzz_override_form_' . preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $ctx->fork( 'form-action' )->identifier( 4, 8 ) ) );
		$post_snapshot = $_POST;
		$before_counts = self::content_counts();
		$events        = array(
			'errors'          => array(),
			'handled'         => array(),
			'moves'           => array(),
			'overrides'       => array(),
			'sequence'        => array(),
			'uniqueCallbacks' => array(),
			'uniqueFilters'   => array(),
			'uploadDirs'      => array(),
		);

		$upload_dir_filter = static function ( array $uploads ) use ( &$events ): array {
			$filtered = MediaIngestSurface::filter_upload_dir( $uploads );
			$events['uploadDirs'][] = array(
				'path'   => $filtered['path'] ?? null,
				'subdir' => $filtered['subdir'] ?? null,
			);
			return $filtered;
		};
		$handle_filter     = static function ( array $upload, string $context ) use ( &$events ): array {
			$events['handled'][] = array(
				'context'  => $context,
				'basename' => isset( $upload['file'] ) ? basename( (string) $upload['file'] ) : null,
				'type'     => $upload['type'] ?? null,
			);
			return $upload;
		};
		$error_handler     = static function ( array &$file, string $message ) use ( &$events ): array {
			$event = array(
				'name'        => $file['name'] ?? null,
				'message'     => $message,
				'error'       => $file['error'] ?? null,
				'size'        => $file['size'] ?? null,
				'tmpBasename' => isset( $file['tmp_name'] ) ? basename( (string) $file['tmp_name'] ) : null,
				'tmpExists'   => isset( $file['tmp_name'] ) && file_exists( (string) $file['tmp_name'] ),
			);
			$events['errors'][]   = $event;
			$events['sequence'][] = array(
				'hook' => 'error',
				'name' => $file['name'] ?? null,
			);

			return array(
				'error'                => $message,
				'component_fuzz_error' => $event,
			);
		};
		$action_overrides  = static function ( $overrides, array $file ) use ( &$events, $form_action, $error_handler ): array {
			$overrides = is_array( $overrides ) ? $overrides : array();
			$events['overrides'][] = array(
				'hook'     => "{$form_action}_overrides",
				'name'     => $file['name'] ?? null,
				'testForm' => $overrides['test_form'] ?? null,
			);
			$events['sequence'][]  = array(
				'hook' => 'overrides',
				'name' => $file['name'] ?? null,
			);

			$overrides['mimes']                = MediaIngestSurface::allowed_mimes();
			$overrides['test_form']            = true;
			$overrides['upload_error_handler'] = $error_handler;

			return $overrides;
		};
		$move_filter       = static function ( $move_new_file, array $file, string $new_file, string $type ) use ( &$events ) {
			$events['moves'][] = array(
				'name'        => $file['name'] ?? null,
				'newBasename' => basename( $new_file ),
				'type'        => $type,
			);
			return $move_new_file;
		};

		$unique_callback_base = 'callback-' . $token;
		$unique_callback      = static function ( string $dir, string $name, string $ext ) use ( &$events, $unique_callback_base ): string {
			$events['uniqueCallbacks'][] = array(
				'dir'  => $dir,
				'name' => $name,
				'ext'  => $ext,
			);

			return $unique_callback_base . strtolower( $ext );
		};
		$unique_filter        = static function (
			string $filename,
			string $ext,
			string $dir,
			$callback,
			array $alt_filenames,
			$number
		) use ( &$events, $unique_callback, $unique_callback_base ): string {
			if ( ! is_callable( $callback ) ) {
				return $filename;
			}

			$events['uniqueFilters'][] = array(
				'filename'     => $filename,
				'ext'          => $ext,
				'dir'          => $dir,
				'sameCallback' => $callback === $unique_callback,
				'altCount'     => count( $alt_filenames ),
				'number'       => $number,
			);

			if ( 0 === strpos( $filename, $unique_callback_base ) ) {
				return 'filtered-' . $filename;
			}

			return $filename;
		};

		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'wp_handle_upload', $handle_filter, 10, 2 );
		\add_filter( "{$form_action}_overrides", $action_overrides, 10, 2 );
		\add_filter( 'pre_move_uploaded_file', $move_filter, 10, 4 );
		\add_filter( 'wp_unique_filename', $unique_filter, 10, 6 );

		try {
			$too_large_message = 'component fuzz form too large ' . $token;
			$error_strings     = array_fill( 0, 9, 'component fuzz unexpected upload error' );
			$error_strings[ UPLOAD_ERR_FORM_SIZE ] = $too_large_message;
			$too_large_case = array(
				'label'       => 'upload-error-custom-handler-payload',
				'filename'    => 'too-large-' . $token . '.txt',
				'fixtureName' => 'too-large.txt',
				'mime'        => 'text/plain',
				'bytes'       => "too large\n" . $ctx->ascii( 4, 16 ),
				'error'       => UPLOAD_ERR_FORM_SIZE,
			);
			$too_large_path = self::write_fixture( $source_dir, 'too-large.txt', $too_large_case['bytes'] );
			$before_files   = self::list_files_recursive( (string) $upload_root );
			$before_moves   = count( $events['moves'] );
			$before_handles = count( $events['handled'] );
			$too_large_file = null === $too_large_path ? array() : self::file_array( $too_large_case, $too_large_path );
			$too_large      = null === $too_large_path ? null : \wp_handle_upload(
				$too_large_file,
				array(
					'mimes'               => self::allowed_mimes(),
					'test_form'           => false,
					'upload_error_handler' => $error_handler,
					'upload_error_strings' => $error_strings,
				)
			);
			$too_large_event = $events['errors'][ count( $events['errors'] ) - 1 ] ?? null;
			$case_rows[]     = array(
				'case'   => self::case_summary( $too_large_case ),
				'result' => self::describe_result( $too_large ),
			);

			self::collect_failure(
				$failures,
				is_array( $too_large )
					&& $too_large_message === ( $too_large['error'] ?? null )
					&& self::result_contains_error_payload( $too_large, $too_large_message, UPLOAD_ERR_FORM_SIZE )
					&& is_array( $too_large_event )
					&& $too_large_message === ( $too_large_event['message'] ?? null )
					&& UPLOAD_ERR_FORM_SIZE === ( $too_large_event['error'] ?? null )
					&& true === ( $too_large_event['tmpExists'] ?? null )
					&& is_string( $too_large_path )
					&& file_exists( $too_large_path )
					&& $before_files === self::list_files_recursive( (string) $upload_root )
					&& $before_moves === count( $events['moves'] )
					&& $before_handles === count( $events['handled'] ),
				'wp_handle_upload uses custom upload_error_handler payloads for UPLOAD_ERR_* without moving files',
				array(
					'case'        => self::case_summary( $too_large_case ),
					'result'      => self::describe_result( $too_large ),
					'event'       => $too_large_event,
					'beforeFiles' => $before_files,
					'afterFiles'  => self::list_files_recursive( (string) $upload_root ),
				)
			);

			$form_case = array(
				'label'       => 'action-overrides-test-form-error',
				'filename'    => 'form-' . $token . '.txt',
				'fixtureName' => 'form.txt',
				'mime'        => 'text/plain',
				'bytes'       => "form mismatch\n" . $ctx->ascii( 4, 16 ),
			);
			$form_path = self::write_fixture( $source_dir, 'form.txt', $form_case['bytes'] );
			$form_file = null === $form_path ? array() : self::file_array( $form_case, $form_path );
			$_POST['action'] = 'component_fuzz_wrong_action';
			$before_files    = self::list_files_recursive( (string) $upload_root );
			$before_moves    = count( $events['moves'] );
			$before_handles  = count( $events['handled'] );
			$form_result     = null === $form_path ? null : \wp_handle_sideload(
				$form_file,
				array(
					'action'    => $form_action,
					'test_form' => true,
				)
			);
			$form_event      = $events['errors'][ count( $events['errors'] ) - 1 ] ?? null;
			$form_sequence   = array();
			foreach ( $events['sequence'] as $event ) {
				if ( is_array( $event ) && $form_case['filename'] === ( $event['name'] ?? null ) ) {
					$form_sequence[] = $event['hook'] ?? null;
				}
			}
			$case_rows[] = array(
				'case'   => self::case_summary( $form_case ),
				'result' => self::describe_result( $form_result ),
			);

			self::collect_failure(
				$failures,
				is_array( $form_result )
					&& \__( 'Invalid form submission.' ) === ( $form_result['error'] ?? null )
					&& self::result_contains_error_payload( $form_result, \__( 'Invalid form submission.' ), UPLOAD_ERR_OK )
					&& array( 'overrides', 'error' ) === $form_sequence
					&& is_array( $form_event )
					&& \__( 'Invalid form submission.' ) === ( $form_event['message'] ?? null )
					&& is_string( $form_path )
					&& file_exists( $form_path )
					&& $before_files === self::list_files_recursive( (string) $upload_root )
					&& $before_moves === count( $events['moves'] )
					&& $before_handles === count( $events['handled'] ),
				'action-specific override filters can inject error handlers before test_form rejects a sideload',
				array(
					'case'        => self::case_summary( $form_case ),
					'result'      => self::describe_result( $form_result ),
					'sequence'    => $form_sequence,
					'overrides'   => $events['overrides'],
					'beforeFiles' => $before_files,
					'afterFiles'  => self::list_files_recursive( (string) $upload_root ),
				)
			);
			$_POST = $post_snapshot;

			$empty_error = \is_multisite()
				? \__( 'File is empty. Please upload something more substantial.' )
				: sprintf(
					\__( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your %1$s file or by %2$s being defined as smaller than %3$s in %1$s.' ),
					'php.ini',
					'post_max_size',
					'upload_max_filesize'
				);
			$empty_case  = array(
				'label'       => 'test-size-empty-sideload-error',
				'filename'    => 'empty-' . $token . '.txt',
				'fixtureName' => 'empty.txt',
				'mime'        => 'text/plain',
				'bytes'       => '',
			);
			$empty_path  = self::write_fixture( $source_dir, 'empty.txt', $empty_case['bytes'] );
			$empty_file  = null === $empty_path ? array() : self::file_array( $empty_case, $empty_path );
			$before_files   = self::list_files_recursive( (string) $upload_root );
			$before_moves   = count( $events['moves'] );
			$before_handles = count( $events['handled'] );
			$empty_result   = null === $empty_path ? null : \wp_handle_sideload(
				$empty_file,
				array(
					'mimes'               => self::allowed_mimes(),
					'test_form'           => false,
					'test_size'           => true,
					'upload_error_handler' => $error_handler,
				)
			);
			$empty_event    = $events['errors'][ count( $events['errors'] ) - 1 ] ?? null;
			$case_rows[]    = array(
				'case'   => self::case_summary( $empty_case ),
				'result' => self::describe_result( $empty_result ),
			);

			self::collect_failure(
				$failures,
				is_array( $empty_result )
					&& $empty_error === ( $empty_result['error'] ?? null )
					&& self::result_contains_error_payload( $empty_result, $empty_error, UPLOAD_ERR_OK )
					&& is_array( $empty_event )
					&& 0 === ( $empty_event['size'] ?? null )
					&& $empty_error === ( $empty_event['message'] ?? null )
					&& is_string( $empty_path )
					&& file_exists( $empty_path )
					&& $before_files === self::list_files_recursive( (string) $upload_root )
					&& $before_moves === count( $events['moves'] )
					&& $before_handles === count( $events['handled'] ),
				'test_size rejects empty readable sideloads through the custom error handler without moving files',
				array(
					'case'   => self::case_summary( $empty_case ),
					'result' => self::describe_result( $empty_result ),
					'event'  => $empty_event,
				)
			);

			$type_error = \__( 'Sorry, you are not allowed to upload this file type.' );
			$type_case  = array(
				'label'       => 'test-type-mimes-reject-php',
				'filename'    => 'payload-' . $token . '.php',
				'fixtureName' => 'payload.php',
				'mime'        => 'application/x-php',
				'bytes'       => "<?php echo 'component fuzz';\n",
			);
			$type_path  = self::write_fixture( $source_dir, 'payload.php', $type_case['bytes'] );
			$type_file  = null === $type_path ? array() : self::file_array( $type_case, $type_path );
			$before_files   = self::list_files_recursive( (string) $upload_root );
			$before_moves   = count( $events['moves'] );
			$before_handles = count( $events['handled'] );
			$before_dirs    = count( $events['uploadDirs'] );
			$type_result    = null === $type_path ? null : \wp_handle_sideload(
				$type_file,
				array(
					'mimes'               => array( 'txt' => 'text/plain' ),
					'test_form'           => false,
					'test_type'           => true,
					'upload_error_handler' => $error_handler,
				)
			);
			$type_event     = $events['errors'][ count( $events['errors'] ) - 1 ] ?? null;
			$case_rows[]    = array(
				'case'   => self::case_summary( $type_case ),
				'result' => self::describe_result( $type_result ),
			);

			self::collect_failure(
				$failures,
				is_array( $type_result )
					&& $type_error === ( $type_result['error'] ?? null )
					&& self::result_contains_error_payload( $type_result, $type_error, UPLOAD_ERR_OK )
					&& is_array( $type_event )
					&& $type_error === ( $type_event['message'] ?? null )
					&& is_string( $type_path )
					&& file_exists( $type_path )
					&& $before_files === self::list_files_recursive( (string) $upload_root )
					&& $before_moves === count( $events['moves'] )
					&& $before_handles === count( $events['handled'] )
					&& $before_dirs === count( $events['uploadDirs'] ),
				'test_type and mimes reject disallowed sideload extensions before upload_dir or move filters run',
				array(
					'case'        => self::case_summary( $type_case ),
					'result'      => self::describe_result( $type_result ),
					'event'       => $type_event,
					'beforeDirs'  => $before_dirs,
					'afterDirs'   => count( $events['uploadDirs'] ),
					'beforeFiles' => $before_files,
					'afterFiles'  => self::list_files_recursive( (string) $upload_root ),
				)
			);

			$loose_case = array(
				'label'       => 'test-type-disabled-allows-custom-extension',
				'filename'    => 'loose ' . $token . '.component',
				'fixtureName' => 'loose.component',
				'mime'        => 'application/x-component-fuzz',
				'bytes'       => "loose type\n" . $ctx->ascii( 4, 16 ),
			);
			$loose_path = self::write_fixture( $source_dir, 'loose.component', $loose_case['bytes'] );
			$loose_file = null === $loose_path ? array() : self::file_array( $loose_case, $loose_path );
			$loose      = null === $loose_path ? null : \wp_handle_sideload(
				$loose_file,
				array(
					'mimes'     => array( 'txt' => 'text/plain' ),
					'test_form' => false,
					'test_type' => false,
				)
			);
			$loose_file_path = is_array( $loose ) ? ( $loose['file'] ?? null ) : null;
			$case_rows[]     = array(
				'case'   => self::case_summary( $loose_case ),
				'result' => self::describe_result( $loose ),
			);

			self::collect_failure(
				$failures,
				is_array( $loose )
					&& ! isset( $loose['error'] )
					&& '' === ( $loose['type'] ?? null )
					&& is_string( $loose_file_path )
					&& file_exists( $loose_file_path )
					&& null !== $upload_root
					&& self::path_starts_with( $loose_file_path, $upload_root )
					&& basename( $loose_file_path ) === \sanitize_file_name( basename( $loose_file_path ) )
					&& str_ends_with( basename( $loose_file_path ), '.component' )
					&& is_string( $loose_path )
					&& ! file_exists( $loose_path ),
				'test_type false lets sideload bypass the mimes allowlist while still moving only inside the temp upload root',
				array(
					'case'       => self::case_summary( $loose_case ),
					'result'     => self::describe_result( $loose ),
					'uploadRoot' => $upload_root,
					'sourceGone' => is_string( $loose_path ) ? ! file_exists( $loose_path ) : null,
				)
			);

			$unique_case = array(
				'label'       => 'unique-filename-callback-filter-sideload',
				'filename'    => '../Unique ' . $token . '.TXT',
				'fixtureName' => 'unique.txt',
				'mime'        => 'text/plain',
				'bytes'       => "unique callback\n" . $ctx->ascii( 4, 16 ),
			);
			$unique_path = self::write_fixture( $source_dir, 'unique.txt', $unique_case['bytes'] );
			$unique_file = null === $unique_path ? array() : self::file_array( $unique_case, $unique_path );
			$unique      = null === $unique_path ? null : \wp_handle_sideload(
				$unique_file,
				array(
					'mimes'                   => self::allowed_mimes(),
					'test_form'               => false,
					'unique_filename_callback' => $unique_callback,
				)
			);
			$unique_file_path = is_array( $unique ) ? ( $unique['file'] ?? null ) : null;
			$unique_basename  = is_string( $unique_file_path ) ? basename( $unique_file_path ) : null;
			$expected_unique  = 'filtered-' . $unique_callback_base . '.txt';
			$callback_name    = $events['uniqueCallbacks'][0]['name'] ?? null;
			$case_rows[]      = array(
				'case'   => self::case_summary( $unique_case ),
				'result' => self::describe_result( $unique ),
			);

			self::collect_failure(
				$failures,
				is_array( $unique )
					&& ! isset( $unique['error'] )
					&& 'text/plain' === ( $unique['type'] ?? null )
					&& $expected_unique === $unique_basename
					&& is_string( $unique_file_path )
					&& file_exists( $unique_file_path )
					&& null !== $upload_root
					&& self::path_starts_with( $unique_file_path, $upload_root )
					&& basename( $unique_file_path ) === \sanitize_file_name( basename( $unique_file_path ) )
					&& is_string( $unique_path )
					&& ! file_exists( $unique_path )
					&& 1 === count( $events['uniqueCallbacks'] )
					&& 1 === count( $events['uniqueFilters'] )
					&& true === ( $events['uniqueFilters'][0]['sameCallback'] ?? null )
					&& is_string( $events['uniqueCallbacks'][0]['dir'] ?? null )
					&& self::path_starts_with( (string) $events['uniqueCallbacks'][0]['dir'], (string) $upload_root )
					&& is_string( $callback_name )
					&& 'unique-' . $token . '.txt' === strtolower( $callback_name )
					&& $callback_name === \sanitize_file_name( $callback_name )
					&& false === strpbrk( $callback_name, "/\\" )
					&& '.txt' === strtolower( (string) ( $events['uniqueCallbacks'][0]['ext'] ?? '' ) ),
				'unique_filename_callback and wp_unique_filename filter shape the real sideload destination safely',
				array(
					'case'            => self::case_summary( $unique_case ),
					'result'          => self::describe_result( $unique ),
					'expectedBasename' => $expected_unique,
					'callbackName'    => $callback_name,
					'callbacks'       => $events['uniqueCallbacks'],
					'filters'         => $events['uniqueFilters'],
				)
			);
		} finally {
			$_POST = $post_snapshot;
			\remove_filter( 'wp_unique_filename', $unique_filter, 10 );
			\remove_filter( 'pre_move_uploaded_file', $move_filter, 10 );
			\remove_filter( "{$form_action}_overrides", $action_overrides, 10 );
			\remove_filter( 'wp_handle_upload', $handle_filter, 10 );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		$after_counts = self::content_counts();
		self::collect_failure(
			$failures,
			$before_counts === $after_counts
				&& null !== $upload_root
				&& self::upload_events_within_root( $events['uploadDirs'], $upload_root )
				&& false === \has_filter( 'upload_dir', $upload_dir_filter )
				&& false === \has_filter( 'wp_handle_upload', $handle_filter )
				&& false === \has_filter( "{$form_action}_overrides", $action_overrides )
				&& false === \has_filter( 'pre_move_uploaded_file', $move_filter )
				&& false === \has_filter( 'wp_unique_filename', $unique_filter ),
			'wp_handle_* override/error checks restore filters and avoid attachment DB side effects',
			array(
				'before'    => $before_counts,
				'after'     => $after_counts,
				'events'    => $events,
				'hasFilter' => array(
					'uploadDir'       => \has_filter( 'upload_dir', $upload_dir_filter ),
					'handle'          => \has_filter( 'wp_handle_upload', $handle_filter ),
					'actionOverrides' => \has_filter( "{$form_action}_overrides", $action_overrides ),
					'preMove'         => \has_filter( 'pre_move_uploaded_file', $move_filter ),
					'uniqueFilename'  => \has_filter( 'wp_unique_filename', $unique_filter ),
				),
			)
		);

		return $ctx->result(
			'media-ingest.wp-handle-override-error-semantics',
			array() === $failures,
			array(
				'cases'    => self::OVERRIDE_ERROR_CASES,
				'rows'     => $case_rows,
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_successful_ingest_flows( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures    = array();
		$rows        = array();
		$source_dir  = $temp_root . DIRECTORY_SEPARATOR . 'successful-source';
		$attachments = array();
		$events      = array(
			'contexts'      => array(),
			'generatedMeta' => array(),
			'uploadDirs'    => array(),
		);
		$upload_root = self::$upload_root;
		$parent_id   = self::insert_parent_post( $ctx );

		$upload_dir_filter = static function ( array $uploads ) use ( &$events ): array {
			$filtered = MediaIngestSurface::filter_upload_dir( $uploads );
			$events['uploadDirs'][] = array(
				'path'   => $filtered['path'] ?? null,
				'subdir' => $filtered['subdir'] ?? null,
			);
			return $filtered;
		};
		$handle_filter     = static function ( array $upload, string $context ) use ( &$events ): array {
			$events['contexts'][] = $context;
			return $upload;
		};
		$metadata_filter   = static function ( array $metadata, int $attachment_id, string $context ) use ( &$events ): array {
			$metadata['component_fuzz_ingest'] = array(
				'attachment_id' => $attachment_id,
				'context'       => $context,
			);
			$events['generatedMeta'][] = array(
				'id'      => $attachment_id,
				'context' => $context,
				'keys'    => array_keys( $metadata ),
			);
			return $metadata;
		};

		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'wp_handle_upload', $handle_filter, 10, 2 );
		\add_filter( 'wp_generate_attachment_metadata', $metadata_filter, 10, 3 );

		try {
			foreach ( self::successful_cases( $ctx ) as $index => $case ) {
				$source_path = self::write_fixture( $source_dir, 'case-' . $index . '-' . $case['fixtureName'], $case['bytes'] );
				if ( null === $source_path ) {
					self::collect_failure(
						$failures,
						false,
						'success fixture is writable',
						array( 'case' => self::case_summary( $case ) )
					);
					continue;
				}

				$before = self::content_counts();
				$result = self::run_ingest_case( $case, $source_path, $parent_id );
				$after  = self::content_counts();

				$attachment = self::assert_successful_attachment(
					$failures,
					$case,
					$result,
					$source_path,
					$parent_id,
					$before,
					$after,
					$upload_root
				);
				if ( null !== $attachment ) {
					$attachments[] = $attachment;
				}
			}
		} finally {
			\remove_filter( 'wp_generate_attachment_metadata', $metadata_filter, 10 );
			\remove_filter( 'wp_handle_upload', $handle_filter, 10 );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		$rows[] = $ctx->result(
			'media-ingest.media-handle-success-attachments',
			array() === $failures,
			array(
				'cases'    => self::SUCCESS_CASES,
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);

		self::collect_failure(
			$failures,
			count( $attachments ) === self::SUCCESS_CASES
				&& self::attachment_basenames_are_unique( $attachments )
				&& self::success_attachment_expectations_hold( $attachments ),
			'generated success cases produce unique, expected, locally moved attachments',
			array(
				'attachments' => $attachments,
				'cases'       => array_map( array( __CLASS__, 'case_summary' ), self::successful_cases( $ctx ) ),
			)
		);

		$rows[] = $ctx->result(
			'media-ingest.generated-success-case-cross-invariants',
			array() === $failures,
			array(
				'cases'       => self::SUCCESS_CASES,
				'attachments' => $attachments,
				'failures'    => array_slice( $failures, 0, 8 ),
			)
		);

		$contexts_ok = in_array( 'upload', $events['contexts'], true ) && in_array( 'sideload', $events['contexts'], true );
		$dirs_ok     = array() !== $events['uploadDirs'];
		foreach ( $events['uploadDirs'] as $upload_dir ) {
			$dirs_ok = $dirs_ok
				&& is_string( $upload_dir['path'] ?? null )
				&& self::path_starts_with( (string) $upload_dir['path'], (string) $upload_root );
		}

		$rows[] = $ctx->result(
			'media-ingest.upload-dir-and-context-filters',
			$contexts_ok && $dirs_ok,
			array(
				'contexts'   => $events['contexts'],
				'uploadDirs' => $events['uploadDirs'],
				'uploadRoot' => $upload_root,
			)
		);

		return $rows;
	}

	private static function check_rejection_paths( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$source_dir    = $temp_root . DIRECTORY_SEPARATOR . 'rejection-source';
		$upload_events = array();
		$error_rows    = array();

		$upload_dir_filter = static function ( array $uploads ): array {
			return MediaIngestSurface::filter_upload_dir( $uploads );
		};
		$handle_filter     = static function ( array $upload, string $context ) use ( &$upload_events ): array {
			$upload_events[] = array(
				'context' => $context,
				'file'    => $upload['file'] ?? null,
			);
			return $upload;
		};

		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'wp_handle_upload', $handle_filter, 10, 2 );

		try {
			$cases = self::rejection_cases( $ctx );

			foreach ( $cases as $index => $case ) {
				$source_path = self::write_fixture( $source_dir, 'reject-' . $index . '-' . $case['fixtureName'], $case['bytes'] );
				if ( null === $source_path ) {
					self::collect_failure( $failures, false, 'rejection fixture is writable', array( 'case' => self::case_summary( $case ) ) );
					continue;
				}

				if ( ! empty( $case['deleteBeforeHandle'] ) ) {
					@unlink( $source_path );
				}

				$before       = self::content_counts();
				$before_files = self::list_files_recursive( (string) self::$upload_root );
				$result       = self::run_ingest_case( $case, $source_path, 0 );
				$after_files  = self::list_files_recursive( (string) self::$upload_root );
				$after        = self::content_counts();
				$error_rows[] = array(
					'case'   => self::case_summary( $case ),
					'result' => self::describe_error( $result ),
				);

				self::collect_failure(
					$failures,
					self::is_upload_error( $result )
						&& ( $after['posts'] ?? null ) === ( $before['posts'] ?? null )
						&& ( $after['post_meta'] ?? null ) === ( $before['post_meta'] ?? null )
						&& $before_files === $after_files,
					'failed ingest returns stable upload_error shape without creating attachment rows or files',
					array(
						'case'        => self::case_summary( $case ),
						'result'      => self::describe_error( $result ),
						'before'      => $before,
						'after'       => $after,
						'beforeFiles' => $before_files,
						'afterFiles'  => $after_files,
					)
				);

				@unlink( $source_path );
			}
		} finally {
			\remove_filter( 'wp_handle_upload', $handle_filter, 10 );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		return $ctx->result(
			'media-ingest.rejection-paths-no-attachment-side-effects',
			array() === $failures && array() === $upload_events,
			array(
				'cases'        => self::REJECTION_CASES,
				'errors'       => $error_rows,
				'uploadEvents' => $upload_events,
				'failures'     => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_download_short_circuits( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures     = array();
		$events       = array();
		$tracked      = array();
		$before_files = self::list_files_recursive( $temp_root );
		$url          = 'https://example.test/component-fuzz-media-ingest/' . rawurlencode( $ctx->identifier( 4, 10 ) ) . '.png';

		$http_filter = static function ( $preempt, array $parsed_args, string $request_url ) use ( &$events, &$tracked ) {
			unset( $preempt );
			$filename = isset( $parsed_args['filename'] ) ? (string) $parsed_args['filename'] : null;
			if ( is_string( $filename ) && '' !== $filename ) {
				$tracked[] = $filename;
			}

			$events[] = array(
				'filename' => is_string( $filename ) ? $filename : null,
				'stream'   => $parsed_args['stream'] ?? null,
				'timeout'  => $parsed_args['timeout'] ?? null,
				'url'      => $request_url,
			);

			return new \WP_Error(
				'component_fuzz_media_ingest_no_network',
				'Component fuzz media ingest blocks live HTTP.',
				array(
					'filename' => is_string( $filename ) ? basename( $filename ) : null,
					'url'      => $request_url,
				)
			);
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		try {
			$result = \download_url( $url, 7 );
			self::collect_failure(
				$failures,
				\is_wp_error( $result )
					&& 'component_fuzz_media_ingest_no_network' === $result->get_error_code()
					&& 1 === count( $events )
					&& true === ( $events[0]['stream'] ?? null )
					&& is_string( $events[0]['filename'] ?? null )
					&& ! file_exists( (string) $events[0]['filename'] ),
				'download_url is fully short-circuited through pre_http_request and cleans its temp file',
				array(
					'events' => $events,
					'result' => self::describe_error( $result ),
				)
			);

			$no_url = \download_url( '', 7 );
			self::collect_failure(
				$failures,
				\is_wp_error( $no_url ) && 'http_no_url' === $no_url->get_error_code() && 1 === count( $events ),
				'download_url empty-url validation returns before temp creation or HTTP filters',
				array( 'result' => self::describe_error( $no_url ) )
			);
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_http_request', $http_filter )
				&& self::tracked_paths_absent( $tracked )
				&& $before_files === self::list_files_recursive( $temp_root ),
			'download short-circuit filters and tracked temp files are cleaned up',
			array(
				'hasFilter' => \has_filter( 'pre_http_request', $http_filter ),
				'tracked'   => $tracked,
			)
		);

		return $ctx->result(
			'media-ingest.download-url-no-network-short-circuit',
			array() === $failures,
			array(
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 6 ),
				'tracked'  => array_map( 'basename', $tracked ),
			)
		);
	}

	private static function check_metadata_failure_paths( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures   = array();
		$source_dir = $temp_root . DIRECTORY_SEPARATOR . 'metadata-source';
		$events     = array(
			'generated' => array(),
			'get'       => array(),
			'updated'   => array(),
		);

		$upload_dir_filter = static function ( array $uploads ): array {
			return MediaIngestSurface::filter_upload_dir( $uploads );
		};
		\add_filter( 'upload_dir', $upload_dir_filter );

		try {
			$case        = self::text_success_case( $ctx->fork( 'metadata-text' ), 'sideload', 'metadata-text' );
			$source_path = self::write_fixture( $source_dir, 'metadata-' . $case['fixtureName'], $case['bytes'] );
			if ( null === $source_path ) {
				self::collect_failure( $failures, false, 'metadata fixture is writable' );
				$attachment_id = 0;
			} else {
				$attachment_id = self::run_ingest_case( $case, $source_path, 0 );
			}

			self::collect_failure(
				$failures,
				is_int( $attachment_id ) && $attachment_id > 0,
				'metadata failure checks have a valid attachment fixture',
				array( 'attachmentId' => self::describe_result( $attachment_id ) )
			);

			$missing_update = \wp_update_attachment_metadata(
				900000 + $ctx->iteration(),
				array( 'component_fuzz_missing' => true )
			);

			$update_filter = static function ( array $data, int $attachment_id_for_filter ) use ( &$events ): array {
				$events['updated'][] = array(
					'id'   => $attachment_id_for_filter,
					'keys' => array_keys( $data ),
				);
				$data['component_fuzz_update_filter'] = $attachment_id_for_filter;
				return $data;
			};
			\add_filter( 'wp_update_attachment_metadata', $update_filter, 10, 2 );
			try {
				$filtered_update = is_int( $attachment_id )
					? \wp_update_attachment_metadata( $attachment_id, array( 'component_fuzz_filter_base' => true ) )
					: null;
			} finally {
				\remove_filter( 'wp_update_attachment_metadata', $update_filter, 10 );
			}
			$filtered_stored = is_int( $attachment_id ) ? \wp_get_attachment_metadata( $attachment_id, true ) : null;

			$blocked_seen = array();
			$block_filter = static function ( $check, int $object_id, string $meta_key, $meta_value, $prev_value ) use ( &$blocked_seen, &$attachment_id ) {
				unset( $meta_value, $prev_value );
				if ( (int) $object_id === (int) $attachment_id && '_wp_attachment_metadata' === $meta_key ) {
					$blocked_seen[] = $object_id;
					return false;
				}
				return $check;
			};

			\add_filter( 'update_post_metadata', $block_filter, 10, 5 );
			try {
				$forced_update = is_int( $attachment_id )
					? \wp_update_attachment_metadata( $attachment_id, array( 'component_fuzz_forced' => true ) )
					: null;
			} finally {
				\remove_filter( 'update_post_metadata', $block_filter, 10 );
			}

			$stored = is_int( $attachment_id ) ? \wp_get_attachment_metadata( $attachment_id ) : null;

			self::collect_failure(
				$failures,
				false !== $filtered_update
					&& array( $attachment_id ) === array_column( $events['updated'], 'id' )
					&& is_array( $filtered_stored )
					&& (int) ( $filtered_stored['component_fuzz_update_filter'] ?? 0 ) === $attachment_id,
				'wp_update_attachment_metadata filter can shape valid metadata updates and is then restored',
				array(
					'filteredUpdate' => $filtered_update,
					'filteredStored' => $filtered_stored,
					'events'         => $events['updated'],
					'hasFilter'      => \has_filter( 'wp_update_attachment_metadata', $update_filter ),
				)
			);

			self::collect_failure(
				$failures,
				false === $missing_update
					&& false === $forced_update
					&& array( $attachment_id ) === $blocked_seen
					&& is_array( $stored )
					&& ! isset( $stored['component_fuzz_forced'] ),
				'wp_update_attachment_metadata missing and short-circuited updates fail closed',
				array(
					'missingUpdate' => $missing_update,
					'forcedUpdate'  => $forced_update,
					'blockedSeen'   => $blocked_seen,
					'stored'        => $stored,
				)
			);

			$generate_filter = static function ( array $metadata, int $attachment_id_for_filter, string $context ) use ( &$events ): array {
				$events['generated'][] = array(
					'context' => $context,
					'id'      => $attachment_id_for_filter,
					'keys'    => array_keys( $metadata ),
				);
				$metadata['component_fuzz_generated_filter'] = $context;
				return $metadata;
			};
			$get_filter      = static function ( array $metadata, int $attachment_id_for_filter ) use ( &$events ): array {
				$events['get'][] = array(
					'id'   => $attachment_id_for_filter,
					'keys' => array_keys( $metadata ),
				);
				$metadata['component_fuzz_get_filter'] = $attachment_id_for_filter;
				return $metadata;
			};

			\add_filter( 'wp_generate_attachment_metadata', $generate_filter, 10, 3 );
			\add_filter( 'wp_get_attachment_metadata', $get_filter, 10, 2 );
			try {
				$missing_file       = $source_dir . DIRECTORY_SEPARATOR . 'missing-generated-' . $ctx->identifier( 3, 8 ) . '.png';
				$generated_missing = is_int( $attachment_id ) ? \wp_generate_attachment_metadata( $attachment_id, $missing_file ) : null;
				$filtered_get      = is_int( $attachment_id ) ? \wp_get_attachment_metadata( $attachment_id ) : null;
				$unfiltered_get    = is_int( $attachment_id ) ? \wp_get_attachment_metadata( $attachment_id, true ) : null;
				$missing_get       = \wp_get_attachment_metadata( 900000 + $ctx->iteration(), true );
			} finally {
				\remove_filter( 'wp_get_attachment_metadata', $get_filter, 10 );
				\remove_filter( 'wp_generate_attachment_metadata', $generate_filter, 10 );
			}

			self::collect_failure(
				$failures,
				is_array( $generated_missing )
					&& 'create' === ( $generated_missing['component_fuzz_generated_filter'] ?? null )
					&& ! isset( $generated_missing['filesize'] )
					&& is_array( $filtered_get )
					&& (int) ( $filtered_get['component_fuzz_get_filter'] ?? 0 ) === $attachment_id
					&& is_array( $unfiltered_get )
					&& ! isset( $unfiltered_get['component_fuzz_get_filter'] )
					&& false === $missing_get
					&& false === \has_filter( 'wp_generate_attachment_metadata', $generate_filter )
					&& false === \has_filter( 'wp_get_attachment_metadata', $get_filter ),
				'attachment metadata helpers keep missing-file, filtered, and unfiltered boundaries distinct',
				array(
					'generatedMissing' => $generated_missing,
					'filteredGet'      => $filtered_get,
					'unfilteredGet'    => $unfiltered_get,
					'missingGet'       => $missing_get,
					'events'           => $events,
					'generateFilter'   => \has_filter( 'wp_generate_attachment_metadata', $generate_filter ),
					'getFilter'        => \has_filter( 'wp_get_attachment_metadata', $get_filter ),
				)
			);
		} finally {
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		return $ctx->result(
			'media-ingest.metadata-update-failure-paths',
			array() === $failures,
			array(
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function run_ingest_case( array $case, string $source_path, int $parent_id ) {
		if ( 'upload' === $case['mode'] ) {
			$field = 'component_fuzz_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $case['label'] ) );

			$_FILES[ $field ] = self::file_array( $case, $source_path );
			try {
				return \media_handle_upload(
					$field,
					$parent_id,
					$case['postData'] ?? array(),
					array(
						'action'    => 'component_fuzz_upload',
						'mimes'     => self::allowed_mimes(),
						'test_form' => false,
					)
				);
			} finally {
				unset( $_FILES[ $field ] );
			}
		}

		return \media_handle_sideload(
			self::file_array( $case, $source_path ),
			$parent_id,
			$case['desc'] ?? null,
			$case['postData'] ?? array()
		);
	}

	private static function assert_successful_attachment( array &$failures, array $case, $attachment_id, string $source_path, int $parent_id, array $before, array $after, ?string $upload_root ): ?array {
		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			self::collect_failure(
				$failures,
				false,
				'media_handle_* returns a positive attachment ID for valid local fixtures',
				array(
					'case'   => self::case_summary( $case ),
					'result' => self::describe_result( $attachment_id ),
				)
			);
			return null;
		}

		$post          = \get_post( $attachment_id );
		$attached_file = \get_attached_file( $attachment_id );
		$metadata      = \wp_get_attachment_metadata( $attachment_id );
		$basename      = is_string( $attached_file ) ? basename( $attached_file ) : '';
		$extension     = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
		$is_image      = str_starts_with( (string) $case['mime'], 'image/' );
		$is_text       = 'text/plain' === $case['mime'];

		self::collect_failure(
			$failures,
			$post instanceof \WP_Post
				&& 'attachment' === $post->post_type
				&& 'inherit' === $post->post_status
				&& (int) $post->post_parent === $parent_id
				&& $case['mime'] === $post->post_mime_type
				&& false === strpos( $post->guid, chr( 0 ) )
				&& str_starts_with( $post->guid, 'http://example.test/component-fuzz-media-ingest/' ),
			'attachment post row is created with expected type, parent, MIME, and URL',
			array(
				'case' => self::case_summary( $case ),
				'post' => $post instanceof \WP_Post
					? array(
						'ID'             => $post->ID,
						'post_type'      => $post->post_type,
						'post_status'    => $post->post_status,
						'post_parent'    => $post->post_parent,
						'post_mime_type' => $post->post_mime_type,
						'guid'           => $post->guid,
						'post_title'     => $post->post_title,
						'post_content'   => $post->post_content,
						'post_excerpt'   => $post->post_excerpt,
						'post_date'      => $post->post_date,
					)
					: self::describe_result( $post ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $attached_file )
				&& file_exists( $attached_file )
				&& null !== $upload_root
				&& self::path_starts_with( $attached_file, $upload_root )
				&& false === strpbrk( $basename, "/\\" )
				&& false === strpos( $basename, chr( 0 ) )
				&& $basename === \sanitize_file_name( $basename )
				&& ( $case['expectedExtension'] ?? $extension ) === $extension
				&& ! file_exists( $source_path ),
			'attached file is moved into the filtered upload root with a sanitized expected basename',
			array(
				'case'         => self::case_summary( $case ),
				'attachedFile' => $attached_file,
				'uploadRoot'   => $upload_root,
				'extension'    => $extension,
				'sourceExists' => file_exists( $source_path ),
			)
		);

		self::collect_failure(
			$failures,
			is_array( $metadata )
				&& isset( $metadata['component_fuzz_ingest'] )
				&& (int) ( $metadata['component_fuzz_ingest']['attachment_id'] ?? 0 ) === $attachment_id
				&& isset( $metadata['filesize'] )
				&& (int) $metadata['filesize'] === strlen( $case['bytes'] ),
			'attachment metadata is generated and stored through the postmeta stub',
			array(
				'case'     => self::case_summary( $case ),
				'metadata' => $metadata,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $metadata )
				&& ( ! $is_text || ( ! isset( $metadata['width'], $metadata['height'], $metadata['sizes'] ) ) )
				&& ( ! $is_image || ( isset( $metadata['width'], $metadata['height'] ) && (int) $metadata['width'] > 0 && (int) $metadata['height'] > 0 ) ),
			'image and non-image metadata helpers stay on their expected boundaries',
			array(
				'case'     => self::case_summary( $case ),
				'isImage'  => $is_image,
				'metadata' => $metadata,
			)
		);

		if ( isset( $case['forbiddenPostId'] ) ) {
			self::collect_failure(
				$failures,
				$post instanceof \WP_Post && (int) $post->ID !== (int) $case['forbiddenPostId'],
				'media_handle_* unsets postData ID instead of overwriting an existing attachment ID',
				array(
					'case'            => self::case_summary( $case ),
					'attachmentId'    => $post instanceof \WP_Post ? $post->ID : null,
					'forbiddenPostId' => $case['forbiddenPostId'],
				)
			);
		}

		foreach ( self::expected_post_fields( $case ) as $field => $expected ) {
			self::collect_failure(
				$failures,
				$post instanceof \WP_Post && (string) $expected === (string) $post->{$field},
				'media_handle_* preserves expected attachment post fields from desc and postData',
				array(
					'case'     => self::case_summary( $case ),
					'field'    => $field,
					'expected' => self::describe_result( $expected ),
					'actual'   => $post instanceof \WP_Post ? self::describe_result( $post->{$field} ) : null,
				)
			);
		}

		self::collect_failure(
			$failures,
			( $after['posts'] ?? 0 ) === ( $before['posts'] ?? 0 ) + 1
				&& ( $after['post_meta'] ?? 0 ) >= ( $before['post_meta'] ?? 0 ) + 2,
			'successful ingest creates one attachment row and attachment metadata rows',
			array(
				'case'   => self::case_summary( $case ),
				'before' => $before,
				'after'  => $after,
			)
		);

		return array(
			'attachmentId'             => $attachment_id,
			'basename'                 => $basename,
			'case'                     => self::case_summary( $case ),
			'expectedBasenameContains' => $case['expectedBasenameContains'] ?? null,
			'expectedExtension'        => $case['expectedExtension'] ?? null,
			'extension'                => $extension,
			'fileExists'               => is_string( $attached_file ) && file_exists( $attached_file ),
			'metadataKeys'             => is_array( $metadata ) ? array_keys( $metadata ) : array(),
			'mime'                     => $post instanceof \WP_Post ? $post->post_mime_type : null,
			'expectedPostFields'       => self::expected_post_fields( $case ),
			'postContent'              => $post instanceof \WP_Post ? $post->post_content : null,
			'postDate'                 => $post instanceof \WP_Post ? $post->post_date : null,
			'postExcerpt'              => $post instanceof \WP_Post ? $post->post_excerpt : null,
			'postParent'               => $post instanceof \WP_Post ? (int) $post->post_parent : null,
			'postTitle'                => $post instanceof \WP_Post ? $post->post_title : null,
			'sourceExists'             => file_exists( $source_path ),
			'withinUploadRoot'         => is_string( $attached_file ) && null !== $upload_root && self::path_starts_with( $attached_file, $upload_root ),
		);
	}

	private static function attachment_basenames_are_unique( array $attachments ): bool {
		$basenames = array();
		foreach ( $attachments as $attachment ) {
			$basename = $attachment['basename'] ?? null;
			if ( ! is_string( $basename ) || '' === $basename ) {
				return false;
			}

			$basenames[] = $basename;
		}

		return count( $basenames ) === count( array_unique( $basenames ) );
	}

	private static function success_attachment_expectations_hold( array $attachments ): bool {
		foreach ( $attachments as $attachment ) {
			if (
				true !== ( $attachment['fileExists'] ?? null )
				|| false !== ( $attachment['sourceExists'] ?? null )
				|| true !== ( $attachment['withinUploadRoot'] ?? null )
				|| ! is_string( $attachment['mime'] ?? null )
			) {
				return false;
			}

			if (
				isset( $attachment['expectedExtension'] )
				&& $attachment['expectedExtension'] !== ( $attachment['extension'] ?? null )
			) {
				return false;
			}

			if (
				isset( $attachment['expectedBasenameContains'] )
				&& (
					! is_string( $attachment['basename'] ?? null )
					|| ! str_contains( $attachment['basename'], (string) $attachment['expectedBasenameContains'] )
				)
			) {
				return false;
			}

			if (
				! is_array( $attachment['metadataKeys'] ?? null )
				|| ! in_array( 'component_fuzz_ingest', $attachment['metadataKeys'], true )
				|| ! in_array( 'filesize', $attachment['metadataKeys'], true )
			) {
				return false;
			}

			foreach ( $attachment['expectedPostFields'] ?? array() as $field => $expected ) {
				$summary_key = self::attachment_summary_key_for_post_field( (string) $field );
				if ( null === $summary_key || (string) $expected !== (string) ( $attachment[ $summary_key ] ?? null ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function expected_post_fields( array $case ): array {
		$fields = array(
			'expectedPostTitle'   => 'post_title',
			'expectedPostContent' => 'post_content',
			'expectedPostExcerpt' => 'post_excerpt',
			'expectedPostDate'    => 'post_date',
		);
		$expected = array();

		foreach ( $fields as $case_key => $post_field ) {
			if ( array_key_exists( $case_key, $case ) ) {
				$expected[ $post_field ] = $case[ $case_key ];
			}
		}

		return $expected;
	}

	private static function attachment_summary_key_for_post_field( string $field ): ?string {
		$map = array(
			'post_title'   => 'postTitle',
			'post_content' => 'postContent',
			'post_excerpt' => 'postExcerpt',
			'post_date'    => 'postDate',
		);

		return $map[ $field ] ?? null;
	}

	private static function events_include_context( array $events, string $context ): bool {
		foreach ( $events as $event ) {
			if ( is_array( $event ) && $context === ( $event['context'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function upload_events_within_root( array $events, string $upload_root ): bool {
		if ( '' === $upload_root || array() === $events ) {
			return false;
		}

		foreach ( $events as $event ) {
			if (
				! is_array( $event )
				|| ! is_string( $event['path'] ?? null )
				|| ! self::path_starts_with( $event['path'], $upload_root )
			) {
				return false;
			}
		}

		return true;
	}

	private static function list_files_recursive( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path       = wp_normalize_path( $item->getPathname() );
			$prefix     = trailingslashit( wp_normalize_path( $root ) );
			$files[]    = str_starts_with( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : $path;
		}

		sort( $files, SORT_STRING );
		return $files;
	}

	private static function is_upload_error( $result ): bool {
		if ( \is_wp_error( $result ) ) {
			return true;
		}

		return is_int( $result ) && $result <= 0;
	}

	private static function tracked_paths_absent( array $paths ): bool {
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
				return false;
			}
		}

		return true;
	}

	private static function successful_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			self::png_success_case( $ctx->fork( 'upload-png-corrected' ), 'upload', 'png-corrected-extension', 'JPG' ),
			self::text_success_case( $ctx->fork( 'sideload-text' ), 'sideload', 'sideload-text' ),
			self::text_success_case( $ctx->fork( 'upload-text-postdata' ), 'upload', 'upload-text-postdata' ),
			self::png_success_case( $ctx->fork( 'sideload-png-date' ), 'sideload', 'sideload-png-post-date', 'png' ),
			self::text_success_case( $ctx->fork( 'upload-uppercase-text' ), 'upload', 'upload-uppercase-text', 'TXT' ),
			self::text_success_case( $ctx->fork( 'sideload-collision-one' ), 'sideload', 'sideload-collision-one', 'txt', 'component fuzz collision.txt' ),
			self::text_success_case( $ctx->fork( 'sideload-collision-two' ), 'sideload', 'sideload-collision-two', 'txt', 'component fuzz collision.txt' ),
			self::png_success_case( $ctx->fork( 'upload-dimension-like' ), 'upload', 'upload-dimension-like-image', 'PNG', 'component-fuzz-150x150.PNG' ),
		);

		$upload_post_ctx  = $ctx->fork( 'upload-postdata-fields' );
		$sideload_ctx     = $ctx->fork( 'sideload-postdata-fields' );
		$upload_title     = 'Component fuzz text ' . $upload_post_ctx->identifier( 3, 8 );
		$upload_content   = 'Upload body ' . $upload_post_ctx->identifier( 3, 8 );
		$upload_excerpt   = 'Upload caption ' . $upload_post_ctx->identifier( 3, 8 );
		$sideload_title   = 'Sideloaded image ' . $sideload_ctx->identifier( 3, 8 );
		$sideload_content = 'Sideload body ' . $sideload_ctx->identifier( 3, 8 );
		$sideload_excerpt = 'Sideload caption ' . $sideload_ctx->identifier( 3, 8 );
		$sideload_date    = sprintf( '2026-%02d-%02d 10:00:00', $sideload_ctx->int( 1, 12 ), $sideload_ctx->int( 1, 28 ) );

		$cases[2]['postData'] = array(
			'ID'           => 987000 + $ctx->iteration(),
			'post_title'   => $upload_title,
			'post_content' => $upload_content,
			'post_excerpt' => $upload_excerpt,
		);
		$cases[2]['forbiddenPostId'] = $cases[2]['postData']['ID'];
		$cases[2]['expectedPostTitle']   = $upload_title;
		$cases[2]['expectedPostContent'] = $upload_content;
		$cases[2]['expectedPostExcerpt'] = $upload_excerpt;
		$cases[3]['postData'] = array(
			'post_content' => $sideload_content,
			'post_excerpt' => $sideload_excerpt,
			'post_date'    => $sideload_date,
		);
		$cases[3]['desc']                = $sideload_title;
		$cases[3]['expectedPostTitle']   = $sideload_title;
		$cases[3]['expectedPostContent'] = $sideload_content;
		$cases[3]['expectedPostExcerpt'] = $sideload_excerpt;
		$cases[3]['expectedPostDate']    = $sideload_date;
		$cases[5]['collisionGroup'] = 'text-collision';
		$cases[6]['collisionGroup'] = 'text-collision';
		$cases[7]['expectedBasenameContains'] = '-1.';

		return array_slice( $cases, 0, self::SUCCESS_CASES );
	}

	private static function png_success_case( \ComponentFuzz\FuzzContext $ctx, string $mode, string $label, string $extension, ?string $filename = null ): array {
		$filename = $filename ?? self::client_filename( $ctx, $extension );

		return array(
			'caseSeed'          => $ctx->seed(),
			'expectedExtension' => 'png',
			'fixtureName'       => 'one-pixel.png',
			'filename'          => $filename,
			'label'             => $label,
			'mime'              => 'image/png',
			'mode'              => $mode,
			'scenario'          => 'png',
			'bytes'             => self::png_bytes(),
		);
	}

	private static function text_success_case( \ComponentFuzz\FuzzContext $ctx, string $mode, string $label, string $extension = 'txt', ?string $filename = null ): array {
		return array(
			'caseSeed'          => $ctx->seed(),
			'expectedExtension' => 'txt',
			'fixtureName'       => 'notes.txt',
			'filename'          => $filename ?? self::client_filename( $ctx, $extension ),
			'label'             => $label,
			'mime'              => 'text/plain',
			'mode'              => $mode,
			'scenario'          => 'text',
			'bytes'             => "component fuzz text\n" . $ctx->ascii( 4, 48 ),
		);
	}

	private static function filename_boundary_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$raw_cases = array(
			array( 'label' => 'png-basic', 'filename' => 'component fuzz image.PNG', 'ext' => 'png', 'type' => 'image/png' ),
			array( 'label' => 'jpg-path', 'filename' => '../escape photo.jpg', 'ext' => 'jpg', 'type' => 'image/jpeg' ),
			array( 'label' => 'jpeg-backslash', 'filename' => '..\\escape photo.JPEG', 'ext' => 'jpeg', 'type' => 'image/jpeg' ),
			array( 'label' => 'text-space', 'filename' => 'notes final.txt', 'ext' => 'txt', 'type' => 'text/plain' ),
			array( 'label' => 'text-alias', 'filename' => 'notes.text', 'ext' => 'text', 'type' => 'text/plain' ),
			array( 'label' => 'pdf-uppercase', 'filename' => 'report.PDF', 'ext' => 'pdf', 'type' => 'application/pdf' ),
			array( 'label' => 'php-disallowed', 'filename' => 'payload.php', 'ext' => false, 'type' => false ),
			array( 'label' => 'svg-disallowed', 'filename' => 'vector.svg', 'ext' => false, 'type' => false ),
			array( 'label' => 'double-extension-disallowed', 'filename' => 'archive.tar.gz', 'ext' => false, 'type' => false ),
			array( 'label' => 'no-extension', 'filename' => 'no extension', 'ext' => false, 'type' => false ),
		);

		$extensions = array( 'png', 'jpg', 'jpeg', 'txt', 'text', 'pdf', 'php', 'svg', 'gz', '' );
		for ( $i = count( $raw_cases ); $i < self::FILENAME_CASES; ++$i ) {
			$case_ctx  = $ctx->fork( 'filename-' . $i );
			$extension = $case_ctx->choice( $extensions );
			$filename  = self::client_filename( $case_ctx, $extension );
			$map       = array(
				'png'  => array( 'png', 'image/png' ),
				'jpg'  => array( 'jpg', 'image/jpeg' ),
				'jpeg' => array( 'jpeg', 'image/jpeg' ),
				'txt'  => array( 'txt', 'text/plain' ),
				'text' => array( 'text', 'text/plain' ),
				'pdf'  => array( 'pdf', 'application/pdf' ),
			);

			$raw_cases[] = array(
				'label'    => 'generated-' . $i,
				'filename' => $filename,
				'ext'      => $map[ strtolower( $extension ) ][0] ?? false,
				'type'     => $map[ strtolower( $extension ) ][1] ?? false,
			);
		}

		return array_map(
			static function ( array $case ) use ( $ctx ): array {
				return array(
					'label'        => $case['label'],
					'caseSeed'     => $ctx->seed(),
					'filename'     => $case['filename'],
					'allowed'      => false !== $case['ext'],
					'expectedExt'  => $case['ext'],
					'expectedType' => $case['type'],
				);
			},
			array_slice( $raw_cases, 0, self::FILENAME_CASES )
		);
	}

	private static function check_unique_filename_boundaries( array &$failures, string $collision_dir ): void {
		$names = array(
			'component fuzz upload.txt',
			'component fuzz upload-1.txt',
			'image-150x150.png',
			'image-150x150-1.png',
		);

		foreach ( $names as $name ) {
			$path = $collision_dir . DIRECTORY_SEPARATOR . \sanitize_file_name( $name );
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, 'existing' );
			}
		}

		$text_unique = \wp_unique_filename( $collision_dir, '../component fuzz upload.txt' );
		$image_unique = \wp_unique_filename( $collision_dir, 'image-150x150.png' );

		self::collect_failure(
			$failures,
			'' !== $text_unique
				&& 'component-fuzz-upload.txt' !== $text_unique
				&& false === strpbrk( $text_unique, "/\\" )
				&& ! file_exists( $collision_dir . DIRECTORY_SEPARATOR . $text_unique )
				&& str_ends_with( $text_unique, '.txt' )
				&& '' !== $image_unique
				&& 'image-150x150.png' !== $image_unique
				&& false === strpbrk( $image_unique, "/\\" )
				&& ! file_exists( $collision_dir . DIRECTORY_SEPARATOR . $image_unique )
				&& str_ends_with( $image_unique, '.png' ),
			'wp_unique_filename avoids sanitized and dimension-like collisions',
			array(
				'textUnique'  => $text_unique,
				'imageUnique' => $image_unique,
				'existing'    => $names,
			)
		);
	}

	private static function assert_direct_handle_success( array &$failures, array $case, $result, ?string $source_path, ?string $upload_root, string $context, bool $used_pre_move ): void {
		$file = is_array( $result ) ? ( $result['file'] ?? null ) : null;
		$url  = is_array( $result ) ? ( $result['url'] ?? null ) : null;
		$type = is_array( $result ) ? ( $result['type'] ?? null ) : null;

		self::collect_failure(
			$failures,
			is_array( $result )
				&& is_string( $file )
				&& file_exists( $file )
				&& null !== $upload_root
				&& self::path_starts_with( $file, $upload_root )
				&& is_string( $url )
				&& str_starts_with( $url, 'http://example.test/component-fuzz-media-ingest/' )
				&& 'text/plain' === $type
				&& false === strpbrk( basename( $file ), "/\\" )
				&& basename( $file ) === \sanitize_file_name( basename( $file ) )
				&& ( null === $source_path || ! file_exists( $source_path ) ),
			"wp_handle_{$context} returns a local sanitized text upload" . ( $used_pre_move ? ' through pre_move_uploaded_file' : '' ),
			array(
				'case'       => self::case_summary( $case ),
				'result'     => self::describe_result( $result ),
				'sourcePath' => $source_path,
				'sourceGone' => null === $source_path || ! file_exists( $source_path ),
				'uploadRoot' => $upload_root,
			)
		);
	}

	private static function result_contains_error_payload( $result, string $message, int $error_code ): bool {
		return is_array( $result )
			&& isset( $result['component_fuzz_error'] )
			&& is_array( $result['component_fuzz_error'] )
			&& $message === ( $result['component_fuzz_error']['message'] ?? null )
			&& $error_code === ( $result['component_fuzz_error']['error'] ?? null )
			&& true === ( $result['component_fuzz_error']['tmpExists'] ?? null );
	}

	private static function rejection_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'       => 'disallowed-php-extension',
				'mode'        => 'upload',
				'filename'    => 'payload.php',
				'fixtureName' => 'payload.php',
				'mime'        => 'application/x-php',
				'bytes'       => "<?php echo 'no';\n",
			),
			array(
				'label'       => 'upload-error',
				'mode'        => 'upload',
				'filename'    => 'too-large.txt',
				'fixtureName' => 'too-large.txt',
				'mime'        => 'text/plain',
				'bytes'       => 'too large',
				'error'       => UPLOAD_ERR_INI_SIZE,
			),
			array(
				'label'              => 'missing-temp-upload',
				'mode'               => 'upload',
				'filename'           => 'missing.txt',
				'fixtureName'        => 'missing.txt',
				'mime'               => 'text/plain',
				'bytes'              => 'missing',
				'deleteBeforeHandle' => true,
			),
			array(
				'label'       => 'disallowed-sideload-svg',
				'mode'        => 'sideload',
				'filename'    => 'vector.svg',
				'fixtureName' => 'vector.svg',
				'mime'        => 'image/svg+xml',
				'bytes'       => '<svg></svg>',
			),
			array(
				'label'       => 'empty-extension',
				'mode'        => 'sideload',
				'filename'    => 'no-extension',
				'fixtureName' => 'no-extension',
				'mime'        => 'application/octet-stream',
				'bytes'       => 'bytes',
			),
			array(
				'label'       => 'double-extension-gz',
				'mode'        => 'sideload',
				'filename'    => 'archive.tar.gz',
				'fixtureName' => 'archive.tar.gz',
				'mime'        => 'application/gzip',
				'bytes'       => 'gzip',
			),
		);

		return array_slice( $cases, 0, self::REJECTION_CASES );
	}

	private static function file_array( array $case, string $source_path ): array {
		return array(
			'name'     => $case['filename'],
			'type'     => $case['mime'],
			'tmp_name' => $source_path,
			'error'    => $case['error'] ?? UPLOAD_ERR_OK,
			'size'     => file_exists( $source_path ) ? filesize( $source_path ) : strlen( $case['bytes'] ),
		);
	}

	private static function allowed_mimes(): array {
		return array(
			'gif'       => 'image/gif',
			'jpg|jpeg'  => 'image/jpeg',
			'png'       => 'image/png',
			'txt|text'  => 'text/plain',
			'pdf'       => 'application/pdf',
		);
	}

	private static function client_filename( \ComponentFuzz\FuzzContext $ctx, string $extension ): string {
		$bases = array(
			'component fuzz ' . $ctx->identifier( 3, 10 ),
			'../escape-' . $ctx->identifier( 2, 6 ),
			'..\\escape ' . $ctx->identifier( 2, 6 ),
			'multi.part.' . $ctx->identifier( 2, 6 ),
			'percent%20name ' . $ctx->identifier( 2, 6 ),
		);
		$base = $ctx->choice( $bases );

		if ( '' === $extension ) {
			return $base;
		}

		return $base . '.' . $extension;
	}

	private static function insert_parent_post( \ComponentFuzz\FuzzContext $ctx ): int {
		$post_id = \wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Component fuzz parent ' . $ctx->identifier( 3, 8 ),
				'post_content' => '',
				'post_date'    => sprintf( '2026-%02d-%02d 12:00:00', $ctx->int( 1, 12 ), $ctx->int( 1, 28 ) ),
			),
			true
		);

		return is_int( $post_id ) ? $post_id : 0;
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
		$_SERVER['HTTP_USER_AGENT']  = 'ComponentFuzz MediaIngest';
		$_SERVER['REQUEST_URI']      = '/component-fuzz/media-ingest/';
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
		$_POST  = $snapshot['post'];
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

		if ( $_POST !== $snapshot['post'] ) {
			$failures[] = array( 'name' => 'post-superglobal' );
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
				'keys'  => array_slice( array_map( 'strval', array_keys( $value ) ), 0, 20 ),
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

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-ingest-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration();
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
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-ingest-';
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
			'mode'     => $case['mode'] ?? null,
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
