<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes media upload and sideload ingest paths against temp fixtures only.
 */
final class MediaIngestSurface {
	public const NAME = 'media-ingest';

	private const SUCCESS_CASES = 4;
	private const PREVIEW_BYTES  = 180;

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
		$rows         = array();

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
				foreach ( self::check_successful_ingest_flows( $ctx->fork( 'success' ), $temp_root ) as $row ) {
					$rows[] = $row;
				}
				$rows[] = self::check_rejection_paths( $ctx->fork( 'rejections' ), $temp_root );
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
		}

		$rows[] = $ctx->result(
			'media-ingest.cleanup.temp-files-and-globals',
			$cleanup_ok,
			array(
				'tempRoot' => $cleanup_path,
				'cleaned'  => $cleanup_ok,
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
				'get_attached_file',
				'get_post',
				'get_post_meta',
				'media_handle_sideload',
				'media_handle_upload',
				'sanitize_file_name',
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

		foreach ( array( 'WP_Error', 'WP_Post' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_filename_and_filetype_boundaries( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$collision_dir = $temp_root . DIRECTORY_SEPARATOR . 'collisions';
		\ComponentFuzz\ensure_dir( $collision_dir );

		for ( $i = 0; $i < 12; $i++ ) {
			$ext       = $ctx->choice( array( 'png', 'jpg', 'txt', 'php', 'tar.gz', '' ) );
			$filename  = self::client_filename( $ctx->fork( 'name-' . $i ), $ext );
			$sanitized = \sanitize_file_name( $filename );
			$again     = \sanitize_file_name( $sanitized );

			self::collect_failure(
				$failures,
				'' !== $sanitized
					&& $sanitized === $again
					&& false === strpos( $sanitized, chr( 0 ) )
					&& false === strpbrk( $sanitized, "/\\" ),
				'sanitize_file_name is idempotent and removes path separators',
				array(
					'filename'  => self::describe_string( $filename ),
					'sanitized' => self::describe_string( $sanitized ),
					'again'     => self::describe_string( $again ),
				)
			);
		}

		$collision_name = 'component fuzz upload.txt';
		$existing      = $collision_dir . DIRECTORY_SEPARATOR . \sanitize_file_name( $collision_name );
		file_put_contents( $existing, 'existing' );
		$unique = \wp_unique_filename( $collision_dir, '../' . $collision_name );
		self::collect_failure(
			$failures,
			'' !== $unique
				&& $unique !== \sanitize_file_name( $collision_name )
				&& false === strpbrk( $unique, "/\\" )
				&& ! file_exists( $collision_dir . DIRECTORY_SEPARATOR . $unique ),
			'wp_unique_filename avoids collisions after sanitizing unsafe input',
			array(
				'existing' => basename( $existing ),
				'unique'   => $unique,
			)
		);

		$fixture_dir = $temp_root . DIRECTORY_SEPARATOR . 'filetype';
		$png_path    = self::write_fixture( $fixture_dir, 'actual-png.bin', self::png_bytes() );
		$text_path   = self::write_fixture( $fixture_dir, 'actual-text.bin', "hello component fuzz\n" );
		$spoof_path  = self::write_fixture( $fixture_dir, 'spoof-image.bin', "<?php echo 'not an image';\n" );

		if ( null === $png_path || null === $text_path || null === $spoof_path ) {
			self::collect_failure( $failures, false, 'fixture files are writable for filetype checks' );
		} else {
			$png_as_jpg = \wp_check_filetype_and_ext( $png_path, 'unsafe path/../photo.jpg', self::allowed_mimes() );
			$text       = \wp_check_filetype_and_ext( $text_path, 'notes.txt', self::allowed_mimes() );
			$php        = \wp_check_filetype_and_ext( $spoof_path, 'payload.php', self::allowed_mimes() );

			self::collect_failure(
				$failures,
				'image/png' === ( $png_as_jpg['type'] ?? null )
					&& 'png' === ( $png_as_jpg['ext'] ?? null )
					&& 'unsafe path/../photo.png' === ( $png_as_jpg['proper_filename'] ?? null )
					&& 'text/plain' === ( $text['type'] ?? null )
					&& 'txt' === ( $text['ext'] ?? null )
					&& false === ( $php['type'] ?? null )
					&& false === ( $php['ext'] ?? null ),
				'wp_check_filetype_and_ext corrects image extensions and rejects disallowed executable extensions',
				array(
					'pngAsJpg' => $png_as_jpg,
					'text'     => $text,
					'php'      => $php,
				)
			);
		}

		return $ctx->result(
			'media-ingest.filename-filetype-boundaries',
			array() === $failures,
			array(
				'cases'    => 15,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_successful_ingest_flows( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures    = array();
		$rows        = array();
		$source_dir  = $temp_root . DIRECTORY_SEPARATOR . 'successful-source';
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

				self::assert_successful_attachment(
					$failures,
					$case,
					$result,
					$source_path,
					$parent_id,
					$before,
					$after,
					$upload_root
				);
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
			$cases = array(
				array(
					'label'       => 'disallowed-php-upload',
					'mode'        => 'upload',
					'filename'    => self::client_filename( $ctx->fork( 'php-name' ), 'php' ),
					'fixtureName' => 'php.txt',
					'mime'        => 'application/x-php',
					'bytes'       => "<?php echo 'blocked';\n",
				),
				array(
					'label'       => 'empty-sideload',
					'mode'        => 'sideload',
					'filename'    => self::client_filename( $ctx->fork( 'empty-name' ), 'txt' ),
					'fixtureName' => 'empty.txt',
					'mime'        => 'text/plain',
					'bytes'       => '',
				),
			);

			foreach ( $cases as $index => $case ) {
				$source_path = self::write_fixture( $source_dir, 'reject-' . $index . '-' . $case['fixtureName'], $case['bytes'] );
				if ( null === $source_path ) {
					self::collect_failure( $failures, false, 'rejection fixture is writable', array( 'case' => self::case_summary( $case ) ) );
					continue;
				}

				$before = self::content_counts();
				$result = self::run_ingest_case( $case, $source_path, 0 );
				$after  = self::content_counts();

				self::collect_failure(
					$failures,
					\is_wp_error( $result )
						&& 'upload_error' === $result->get_error_code()
						&& ( $after['posts'] ?? null ) === ( $before['posts'] ?? null )
						&& ( $after['post_meta'] ?? null ) === ( $before['post_meta'] ?? null ),
					'failed ingest returns upload_error without creating attachment rows',
					array(
						'case'   => self::case_summary( $case ),
						'result' => self::describe_error( $result ),
						'before' => $before,
						'after'  => $after,
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
				'cases'        => 2,
				'uploadEvents' => $upload_events,
				'failures'     => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_metadata_failure_paths( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures   = array();
		$source_dir = $temp_root . DIRECTORY_SEPARATOR . 'metadata-source';

		$upload_dir_filter = static function ( array $uploads ): array {
			return MediaIngestSurface::filter_upload_dir( $uploads );
		};
		\add_filter( 'upload_dir', $upload_dir_filter );

		try {
			$case        = self::text_success_case( $ctx->fork( 'metadata-text' ), 'sideload' );
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
		} finally {
			\remove_filter( 'upload_dir', $upload_dir_filter );
			self::cleanup_leftover_sources( $source_dir );
		}

		return $ctx->result(
			'media-ingest.metadata-update-failure-paths',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
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

	private static function assert_successful_attachment( array &$failures, array $case, $attachment_id, string $source_path, int $parent_id, array $before, array $after, ?string $upload_root ): void {
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
			return;
		}

		$post          = \get_post( $attachment_id );
		$attached_file = \get_attached_file( $attachment_id );
		$metadata      = \wp_get_attachment_metadata( $attachment_id );
		$basename      = is_string( $attached_file ) ? basename( $attached_file ) : '';

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
				&& ! file_exists( $source_path ),
			'attached file is moved into the filtered upload root with a sanitized basename',
			array(
				'case'         => self::case_summary( $case ),
				'attachedFile' => $attached_file,
				'uploadRoot'   => $upload_root,
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
			( $after['posts'] ?? 0 ) === ( $before['posts'] ?? 0 ) + 1
				&& ( $after['post_meta'] ?? 0 ) >= ( $before['post_meta'] ?? 0 ) + 2,
			'successful ingest creates one attachment row and attachment metadata rows',
			array(
				'case'   => self::case_summary( $case ),
				'before' => $before,
				'after'  => $after,
			)
		);
	}

	private static function successful_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			self::png_success_case( $ctx->fork( 'upload-png' ), 'upload' ),
			self::text_success_case( $ctx->fork( 'sideload-text' ), 'sideload' ),
			self::text_success_case( $ctx->fork( 'upload-text' ), 'upload' ),
			self::png_success_case( $ctx->fork( 'sideload-png' ), 'sideload' ),
		);

		$cases[2]['postData'] = array(
			'ID'         => 987000 + $ctx->iteration(),
			'post_title' => 'Component fuzz text ' . $ctx->identifier( 3, 8 ),
		);
		$cases[3]['postData'] = array(
			'post_date' => sprintf( '2026-%02d-%02d 10:00:00', $ctx->int( 1, 12 ), $ctx->int( 1, 28 ) ),
		);
		$cases[3]['desc']     = 'Sideloaded image ' . $ctx->identifier( 3, 8 );

		return array_slice( $cases, 0, self::SUCCESS_CASES );
	}

	private static function png_success_case( \ComponentFuzz\FuzzContext $ctx, string $mode ): array {
		$filename = self::client_filename( $ctx, $ctx->choice( array( 'png', 'jpg' ) ) );

		return array(
			'label'       => $mode . '-png',
			'mode'        => $mode,
			'filename'    => $filename,
			'fixtureName' => 'one-pixel.png',
			'mime'        => 'image/png',
			'bytes'       => self::png_bytes(),
		);
	}

	private static function text_success_case( \ComponentFuzz\FuzzContext $ctx, string $mode ): array {
		return array(
			'label'       => $mode . '-text',
			'mode'        => $mode,
			'filename'    => self::client_filename( $ctx, 'txt' ),
			'fixtureName' => 'notes.txt',
			'mime'        => 'text/plain',
			'bytes'       => "component fuzz text\n" . $ctx->ascii( 4, 48 ),
		);
	}

	private static function file_array( array $case, string $source_path ): array {
		return array(
			'name'     => $case['filename'],
			'type'     => $case['mime'],
			'tmp_name' => $source_path,
			'error'    => UPLOAD_ERR_OK,
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
