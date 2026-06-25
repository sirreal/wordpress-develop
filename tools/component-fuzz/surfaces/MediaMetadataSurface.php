<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes local media metadata, ID3, and audio/video attachment helper paths.
 */
final class MediaMetadataSurface {
	public const NAME = 'media-metadata';

	private const PREVIEW_BYTES = 180;

	private static ?string $getid3_temp_root = null;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'media-metadata.bootstrap-apis-available',
					'Required WordPress media metadata APIs are unavailable.',
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
					'media-metadata.temp-root.available',
					'Could not create an isolated temporary directory.',
					array( 'sysTempDir' => sys_get_temp_dir() )
				);
			} else {
				self::prepare_runtime( $temp_root );

				$rows[] = self::check_audio_video_parser_failure_paths( $ctx->fork( 'parser' ), $temp_root );
				$rows[] = self::check_id3_tag_and_timestamp_helpers( $ctx->fork( 'id3-helper' ) );
				$rows[] = self::check_extension_key_and_attachment_helpers( $ctx->fork( 'helpers' ), $temp_root );
				$rows[] = self::check_attachment_metadata_get_update_helpers( $ctx->fork( 'metadata' ), $temp_root );
				$rows[] = self::check_original_image_metadata_helpers( $ctx->fork( 'original-image' ), $temp_root );
				$rows[] = self::check_generate_attachment_metadata_branches( $ctx->fork( 'generate' ), $temp_root );
			}
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'media-metadata.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
				$cleanup_ok = ! is_dir( $temp_root );
			}
			self::restore_state( $snapshot );
		}

		$rows[] = self::row(
			$ctx,
			'media-metadata.cleanup.temp-files-and-globals',
			$cleanup_ok,
			array(
				'tempRoot' => $cleanup_path,
				'cleaned'  => $cleanup_ok,
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_theme_supports',
				'get_attached_file',
				'get_post_meta',
				'get_post',
				'get_post_mime_type',
				'metadata_exists',
				'maybe_serialize',
				'maybe_unserialize',
				'post_type_supports',
				'remove_post_type_support',
				'remove_theme_support',
				'sanitize_file_name',
				'wp_add_id3_tag_data',
				'wp_attachment_is',
				'wp_attachment_is_image',
				'wp_cache_flush',
				'wp_cache_set',
				'wp_check_filetype',
				'wp_filesize',
				'wp_generate_attachment_metadata',
				'wp_get_attachment_id3_keys',
				'wp_get_audio_extensions',
				'wp_get_attachment_metadata',
				'wp_get_attachment_url',
				'wp_get_original_image_path',
				'wp_get_original_image_url',
				'wp_get_media_creation_timestamp',
				'wp_get_upload_dir',
				'wp_get_video_extensions',
				'wp_image_file_matches_image_meta',
				'wp_update_attachment_metadata',
				'wp_read_audio_metadata',
				'wp_read_video_metadata',
				'wp_set_current_user',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'WP_Post', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_audio_video_parser_failure_paths( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		if ( ! file_exists( ABSPATH . WPINC . '/ID3/getid3.php' ) ) {
			return $ctx->skip(
				'media-metadata.parsers.local-malformed-fail-closed',
				'Bundled getID3 library is unavailable, so parser integration is skipped narrowly.'
			);
		}

		$failures = array();
		$events   = array();
		$dir      = $temp_root . DIRECTORY_SEPARATOR . 'parser-fixtures';
		$missing  = $dir . DIRECTORY_SEPARATOR . 'missing.mp3';

		self::collect_failure(
			$failures,
			false === \wp_read_audio_metadata( $missing ) && false === \wp_read_video_metadata( $missing ),
			'missing audio/video files return false before filter dispatch',
			array( 'missing' => $missing )
		);

		$audio_filter = static function ( array $metadata, string $file, ?string $file_format, array $data ) use ( &$events ): array {
			$metadata['component_fuzz_audio_filter'] = array(
				'format'   => $file_format,
				'basename' => basename( $file ),
				'hasError' => ! empty( $data['error'] ),
			);
			$events[]                                = array(
				'type'     => 'audio',
				'file'     => basename( $file ),
				'format'   => $file_format,
				'hasError' => ! empty( $data['error'] ),
			);
			return $metadata;
		};
		$video_filter = static function ( array $metadata, string $file, ?string $file_format, array $data ) use ( &$events ): array {
			$metadata['component_fuzz_video_filter'] = array(
				'format'   => $file_format,
				'basename' => basename( $file ),
				'hasError' => ! empty( $data['error'] ),
			);
			$events[]                                = array(
				'type'     => 'video',
				'file'     => basename( $file ),
				'format'   => $file_format,
				'hasError' => ! empty( $data['error'] ),
			);
			return $metadata;
		};

		\add_filter( 'wp_read_audio_metadata', $audio_filter, 10, 4 );
		\add_filter( 'wp_read_video_metadata', $video_filter, 10, 4 );
		try {
			foreach ( self::parser_fixtures( $ctx ) as $index => $case ) {
				$path = self::write_fixture( $dir, $index . '-' . $case['filename'], $case['bytes'] );
				if ( null === $path ) {
					self::collect_failure( $failures, false, 'parser fixture is writable', array( 'case' => $case['label'] ) );
					continue;
				}

				$audio = \wp_read_audio_metadata( $path );
				$video = \wp_read_video_metadata( $path );

				self::collect_parser_shape_failures( $failures, $audio, $path, $case['label'], 'audio' );
				self::collect_parser_shape_failures( $failures, $video, $path, $case['label'], 'video' );
			}
		} finally {
			\remove_filter( 'wp_read_audio_metadata', $audio_filter, 10 );
			\remove_filter( 'wp_read_video_metadata', $video_filter, 10 );
		}

		self::collect_failure(
			$failures,
			class_exists( 'getID3', false )
				&& count( $events ) === 2 * count( self::parser_fixtures( $ctx ) )
				&& false === \has_filter( 'wp_read_audio_metadata', $audio_filter )
				&& false === \has_filter( 'wp_read_video_metadata', $video_filter ),
			'getID3 loads through parser helpers and parser filters are restored',
			array(
				'events'         => $events,
				'getid3Loaded'   => class_exists( 'getID3', false ),
				'getid3TempDir'  => defined( 'GETID3_TEMP_DIR' ) ? GETID3_TEMP_DIR : null,
				'audioHasFilter' => \has_filter( 'wp_read_audio_metadata', $audio_filter ),
				'videoHasFilter' => \has_filter( 'wp_read_video_metadata', $video_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-metadata.parsers.local-malformed-fail-closed',
			array() === $failures,
			array(
				'cases'    => count( self::parser_fixtures( $ctx ) ),
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_id3_tag_and_timestamp_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$metadata = array(
			'bitrate' => $ctx->int( 32000, 320000 ),
		);
		$data     = array(
			'id3v2'     => array(
				'comments' => array(
					'artist'       => array( 'Component <em>Artist</em><script>alert(1)</script>' ),
					'length'       => array( '999' ),
					'terms_of_use' => array( 'yright notice. <strong>Allowed</strong><script>drop()</script>' ),
					'title'        => array( 'ID3v2 <b>' . $ctx->identifier( 3, 8 ) . '</b><script>drop()</script>' ),
				),
				'APIC'     => array(
					array(
						'data'         => 'cover' . $ctx->identifier( 2, 4 ),
						'image_mime'   => 'image/png',
						'image_width'  => 2,
						'image_height' => 3,
					),
				),
			),
			'id3v1'     => array(
				'comments' => array(
					'title' => array( 'ID3v1 should not win' ),
				),
			),
			'fileformat' => 'mp3',
		);

		\wp_add_id3_tag_data( $metadata, $data );

		self::collect_failure(
			$failures,
			isset( $metadata['title'], $metadata['artist'], $metadata['terms_of_use'], $metadata['image'] )
				&& false === str_contains( strtolower( $metadata['title'] ), '<script' )
				&& false === str_contains( strtolower( $metadata['artist'] ), '<script' )
				&& false === str_contains( strtolower( $metadata['terms_of_use'] ), '<script' )
				&& str_starts_with( $metadata['terms_of_use'], 'Copyright notice.' )
				&& false === str_contains( $metadata['title'], 'ID3v1 should not win' )
				&& ! isset( $metadata['length'] )
				&& 'image/png' === ( $metadata['image']['mime'] ?? null )
				&& 2 === ( $metadata['image']['width'] ?? null )
				&& 3 === ( $metadata['image']['height'] ?? null )
				&& isset( $metadata['image']['data'] )
				&& self::serializable_array_ok( $metadata ),
			'wp_add_id3_tag_data prioritizes ID3v2, sanitizes comments, fixes terms text, and preserves bounded image shape',
			array( 'metadata' => $metadata )
		);

		$timestamps = array(
			'asf'       => \wp_get_media_creation_timestamp(
				array(
					'fileformat' => 'asf',
					'asf'        => array(
						'file_properties_object' => array( 'creation_date_unix' => 1760000000 ),
					),
				)
			),
			'matroska'  => \wp_get_media_creation_timestamp(
				array(
					'fileformat' => 'matroska',
					'matroska'   => array(
						'comments' => array( 'creation_time' => array( '2026-06-23 10:11:12' ) ),
					),
				)
			),
			'mp4'       => \wp_get_media_creation_timestamp(
				array(
					'fileformat' => 'mp4',
					'quicktime'  => array(
						'moov' => array(
							'subatoms' => array(
								array( 'creation_time_unix' => 1770000000 ),
							),
						),
					),
				)
			),
			'none'      => \wp_get_media_creation_timestamp( array( 'fileformat' => 'unknown' ) ),
			'no-format' => \wp_get_media_creation_timestamp( array() ),
		);

		self::collect_failure(
			$failures,
			1760000000 === $timestamps['asf']
				&& is_int( $timestamps['matroska'] )
				&& $timestamps['matroska'] > 0
				&& 1770000000 === $timestamps['mp4']
				&& false === $timestamps['none']
				&& false === $timestamps['no-format'],
			'wp_get_media_creation_timestamp extracts only supported local metadata shapes',
			array( 'timestamps' => $timestamps )
		);

		return self::row(
			$ctx,
			'media-metadata.id3-tags-and-creation-timestamps',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_extension_key_and_attachment_helpers( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures = array();

		$default_audio = \wp_get_audio_extensions();
		$default_video = \wp_get_video_extensions();
		$audio_ext     = 'cfza' . $ctx->int( 10, 99 );
		$video_ext     = 'cfzv' . $ctx->int( 10, 99 );
		$audio_filter  = static function ( array $extensions ) use ( $audio_ext ): array {
			$extensions[] = $audio_ext;
			return array_values( array_unique( $extensions ) );
		};
		$video_filter  = static function ( array $extensions ) use ( $video_ext ): array {
			$extensions[] = $video_ext;
			return array_values( array_unique( $extensions ) );
		};

		\add_filter( 'wp_audio_extensions', $audio_filter );
		\add_filter( 'wp_video_extensions', $video_filter );
		try {
			$filtered_audio = \wp_get_audio_extensions();
			$filtered_video = \wp_get_video_extensions();
		} finally {
			\remove_filter( 'wp_audio_extensions', $audio_filter );
			\remove_filter( 'wp_video_extensions', $video_filter );
		}

		$restored_audio = \wp_get_audio_extensions();
		$restored_video = \wp_get_video_extensions();

		self::collect_failure(
			$failures,
			array( 'mp3', 'ogg', 'flac', 'm4a', 'wav' ) === array_values( array_intersect( array( 'mp3', 'ogg', 'flac', 'm4a', 'wav' ), $default_audio ) )
				&& array( 'mp4', 'm4v', 'webm', 'ogv', 'flv' ) === array_values( array_intersect( array( 'mp4', 'm4v', 'webm', 'ogv', 'flv' ), $default_video ) )
				&& in_array( $audio_ext, $filtered_audio, true )
				&& in_array( $video_ext, $filtered_video, true )
				&& ! in_array( $audio_ext, $restored_audio, true )
				&& ! in_array( $video_ext, $restored_video, true ),
			'audio/video extension filters are local and default extension sets remain available',
			array(
				'defaultAudio'  => $default_audio,
				'defaultVideo'  => $default_video,
				'filteredAudio' => $filtered_audio,
				'filteredVideo' => $filtered_video,
				'restoredAudio' => $restored_audio,
				'restoredVideo' => $restored_video,
			)
		);

		$key_events = array();
		$key_filter = static function ( array $fields, \WP_Post $attachment, string $context ) use ( &$key_events ): array {
			$key_events[]                         = array(
				'id'      => $attachment->ID,
				'context' => $context,
				'keys'    => array_keys( $fields ),
			);
			$fields[ 'component_fuzz_' . $context ] = 'Component Fuzz';
			return $fields;
		};
		$attachment = self::attachment_post_object( 860000 + $ctx->iteration(), 'audio/mpeg', 'component-fuzz-audio' );

		\add_filter( 'wp_get_attachment_id3_keys', $key_filter, 10, 3 );
		try {
			$display_keys = \wp_get_attachment_id3_keys( $attachment, 'display' );
			$edit_keys    = \wp_get_attachment_id3_keys( $attachment, 'edit' );
			$js_keys      = \wp_get_attachment_id3_keys( $attachment, 'js' );
		} finally {
			\remove_filter( 'wp_get_attachment_id3_keys', $key_filter, 10 );
		}

		self::collect_failure(
			$failures,
			isset( $display_keys['artist'], $display_keys['album'], $display_keys['genre'], $display_keys['year'], $display_keys['length_formatted'], $display_keys['component_fuzz_display'] )
				&& isset( $edit_keys['artist'], $edit_keys['album'], $edit_keys['component_fuzz_edit'] )
				&& ! isset( $edit_keys['genre'], $edit_keys['bitrate'] )
				&& isset( $js_keys['artist'], $js_keys['album'], $js_keys['bitrate'], $js_keys['bitrate_mode'], $js_keys['component_fuzz_js'] )
				&& 3 === count( $key_events )
				&& false === \has_filter( 'wp_get_attachment_id3_keys', $key_filter ),
			'wp_get_attachment_id3_keys context keys and filter locality are stable',
			array(
				'displayKeys' => $display_keys,
				'editKeys'    => $edit_keys,
				'jsKeys'      => $js_keys,
				'events'      => $key_events,
				'hasFilter'   => \has_filter( 'wp_get_attachment_id3_keys', $key_filter ),
			)
		);

		$fixture_dir = $temp_root . DIRECTORY_SEPARATOR . 'attachment-is';
		$audio_path  = self::write_fixture( $fixture_dir, 'song.mp3', 'ID3' . $ctx->bytes( 8, 20 ) );
		$video_path  = self::write_fixture( $fixture_dir, 'clip.webm', "\x1A\x45\xDF\xA3" . $ctx->bytes( 8, 20 ) );
		$bin_path    = self::write_fixture( $fixture_dir, 'audio-binary.bin', $ctx->bytes( 8, 20 ) );
		$image_path  = self::write_fixture( $fixture_dir, 'picture.jpg', "\xFF\xD8\xFF\xE0" . $ctx->bytes( 8, 20 ) );
		$pdf_path    = self::write_fixture( $fixture_dir, 'document.pdf', "%PDF-1.4\n" . $ctx->bytes( 8, 20 ) . "\n%%EOF\n" );
		$mismatch    = self::write_fixture( $fixture_dir, 'mime-image-extension.png', "\x89PNG\r\n\x1A\n" . $ctx->bytes( 8, 20 ) );

		if ( null === $audio_path || null === $video_path || null === $bin_path || null === $image_path || null === $pdf_path || null === $mismatch ) {
			self::collect_failure( $failures, false, 'attachment type fixtures are writable' );
		} else {
			$audio_id    = 861000 + $ctx->iteration();
			$video_id    = 862000 + $ctx->iteration();
			$mime_id     = 863000 + $ctx->iteration();
			$image_id    = 864000 + $ctx->iteration();
			$pdf_id      = 865000 + $ctx->iteration();
			$mismatch_id = 866000 + $ctx->iteration();

			self::seed_attachment_post( $audio_id, 'import', $audio_path );
			self::seed_attachment_post( $video_id, 'import', $video_path );
			self::seed_attachment_post( $mime_id, 'audio/mpeg', $bin_path );
			self::seed_attachment_post( $image_id, 'import', $image_path );
			self::seed_attachment_post( $pdf_id, 'application/pdf', $pdf_path );
			self::seed_attachment_post( $mismatch_id, 'image/jpeg', $mismatch );

			$attachment_checks = array(
				'audio-import-audio'       => \wp_attachment_is( 'audio', $audio_id ),
				'audio-import-video'       => \wp_attachment_is( 'video', $audio_id ),
				'video-import-video'       => \wp_attachment_is( 'video', $video_id ),
				'video-import-audio'       => \wp_attachment_is( 'audio', $video_id ),
				'image-import-image'       => \wp_attachment_is( 'image', $image_id ),
				'image-import-wrapper'     => \wp_attachment_is_image( $image_id ),
				'image-import-png'         => \wp_attachment_is( 'png', $image_id ),
				'pdf-document'             => \wp_attachment_is( 'pdf', $pdf_id ),
				'pdf-image'                => \wp_attachment_is( 'image', $pdf_id ),
				'pdf-wrapper'              => \wp_attachment_is_image( $pdf_id ),
				'mime-audio'               => \wp_attachment_is( 'audio', $mime_id ),
				'mime-mismatch-image'      => \wp_attachment_is( 'image', $mismatch_id ),
				'mime-mismatch-png'        => \wp_attachment_is( 'png', $mismatch_id ),
				'mime-mismatch-jpg'        => \wp_attachment_is( 'jpg', $mismatch_id ),
				'mime-mp3'                 => \wp_attachment_is( 'mp3', $audio_id ),
				'missing'                  => \wp_attachment_is( 'audio', 999999 + $ctx->iteration() ),
			);

			self::collect_failure(
				$failures,
				true === $attachment_checks['audio-import-audio']
					&& false === $attachment_checks['audio-import-video']
					&& true === $attachment_checks['video-import-video']
					&& false === $attachment_checks['video-import-audio']
					&& true === $attachment_checks['image-import-image']
					&& true === $attachment_checks['image-import-wrapper']
					&& false === $attachment_checks['image-import-png']
					&& true === $attachment_checks['pdf-document']
					&& false === $attachment_checks['pdf-image']
					&& false === $attachment_checks['pdf-wrapper']
					&& true === $attachment_checks['mime-audio']
					&& true === $attachment_checks['mime-mismatch-image']
					&& true === $attachment_checks['mime-mismatch-png']
					&& false === $attachment_checks['mime-mismatch-jpg']
					&& true === $attachment_checks['mime-mp3']
					&& false === $attachment_checks['missing'],
				'wp_attachment_is honors import image/audio/video branches, direct MIME branches, extension fallbacks, wrappers, and missing attachments',
				array( 'attachmentChecks' => $attachment_checks )
			);
		}

		return self::row(
			$ctx,
			'media-metadata.extension-id3-key-and-attachment-helpers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_attachment_metadata_get_update_helpers( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures    = array();
		$fixture_dir = $temp_root . DIRECTORY_SEPARATOR . 'metadata-roundtrip';
		$file        = self::write_fixture( $fixture_dir, 'roundtrip-' . $ctx->identifier( 3, 8 ) . '.mp3', 'ID3' . $ctx->bytes( 8, 24 ) );

		if ( null === $file ) {
			return self::row(
				$ctx,
				'media-metadata.attachment-metadata-get-update-delete',
				false,
				array( 'failures' => array( array( 'message' => 'metadata round-trip fixture is writable' ) ) )
			);
		}

		$attachment_id = 873000 + $ctx->iteration();
		self::seed_attachment_post( $attachment_id, 'audio/mpeg', $file );

		$metadata = array(
			'file'       => basename( $file ),
			'filesize'   => filesize( $file ),
			'length'     => $ctx->int( 1, 999 ),
			'mime_type'  => 'audio/mpeg',
			'sizes'      => array(
				'component-fuzz' => array(
					'file'      => 'component-' . $ctx->identifier( 3, 8 ) . '.jpg',
					'width'     => $ctx->int( 1, 20 ),
					'height'    => $ctx->int( 1, 20 ),
					'mime-type' => 'image/jpeg',
				),
			),
			'image_meta' => array(
				'created_timestamp' => $ctx->int( 1000000000, 1999999999 ),
				'credit'            => 'Component Fuzz',
			),
		);
		$events   = array(
			'get'    => array(),
			'update' => array(),
		);

		$update_filter = static function ( array $data, int $post_id ) use ( &$events, $attachment_id ): array {
			$events['update'][]              = array(
				'id'   => $post_id,
				'keys' => array_keys( $data ),
			);
			if ( array() === $data ) {
				return $data;
			}

			$data['component_fuzz_updated'] = $attachment_id === $post_id;
			return $data;
		};
		$get_filter    = static function ( $data, int $post_id ) use ( &$events ): array {
			$events['get'][] = array(
				'id'   => $post_id,
				'type' => gettype( $data ),
			);
			if ( is_array( $data ) ) {
				$data['component_fuzz_get_filtered'] = $post_id;
			}
			return $data;
		};

		\add_filter( 'wp_update_attachment_metadata', $update_filter, 10, 2 );
		\add_filter( 'wp_get_attachment_metadata', $get_filter, 10, 2 );
		try {
			$updated      = \wp_update_attachment_metadata( $attachment_id, $metadata );
			$unfiltered   = \wp_get_attachment_metadata( $attachment_id, true );
			$filtered     = \wp_get_attachment_metadata( $attachment_id );
			$deleted      = \wp_update_attachment_metadata( $attachment_id, array() );
			$after_delete = \wp_get_attachment_metadata( $attachment_id, true );
		} finally {
			\remove_filter( 'wp_get_attachment_metadata', $get_filter, 10 );
			\remove_filter( 'wp_update_attachment_metadata', $update_filter, 10 );
		}

		self::collect_failure(
			$failures,
			false !== $updated
				&& is_array( $unfiltered )
				&& is_array( $filtered )
				&& true === ( $unfiltered['component_fuzz_updated'] ?? null )
				&& ! isset( $unfiltered['component_fuzz_get_filtered'] )
				&& true === ( $filtered['component_fuzz_updated'] ?? null )
				&& $attachment_id === ( $filtered['component_fuzz_get_filtered'] ?? null )
				&& $metadata['sizes'] === ( $unfiltered['sizes'] ?? null )
				&& $metadata['image_meta'] === ( $unfiltered['image_meta'] ?? null )
				&& false !== $deleted
				&& false === $after_delete,
			'wp_get/update_attachment_metadata round trips filtered metadata, honors unfiltered reads, and deletes on empty data',
			array(
				'updated'      => $updated,
				'unfiltered'   => $unfiltered,
				'filtered'     => $filtered,
				'deleted'      => $deleted,
				'afterDelete'  => $after_delete,
				'events'       => $events,
				'baseMetadata' => $metadata,
			)
		);

		self::collect_failure(
			$failures,
			2 === count( $events['update'] )
				&& 1 === count( $events['get'] )
				&& $attachment_id === ( $events['update'][0]['id'] ?? null )
				&& $attachment_id === ( $events['update'][1]['id'] ?? null )
				&& array() === ( $events['update'][1]['keys'] ?? null )
				&& $attachment_id === ( $events['get'][0]['id'] ?? null )
				&& false === \has_filter( 'wp_update_attachment_metadata', $update_filter )
				&& false === \has_filter( 'wp_get_attachment_metadata', $get_filter ),
			'attachment metadata filters fire with expected payloads and are restored',
			array(
				'events'    => $events,
				'hasUpdate' => \has_filter( 'wp_update_attachment_metadata', $update_filter ),
				'hasGet'    => \has_filter( 'wp_get_attachment_metadata', $get_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-metadata.attachment-metadata-get-update-delete',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_original_image_metadata_helpers( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures        = array();
		$events          = array(
			'attachmentUrl' => array(),
			'originalPath'  => array(),
			'originalUrl'   => array(),
			'matches'       => array(),
		);
		$upload_root     = $temp_root . DIRECTORY_SEPARATOR . 'metadata-uploads';
		$upload_url      = 'http://example.test/component-fuzz-media-' . $ctx->int( 1000, 9999 );
		$attachment_ids  = array();
		$generated_cases = array();

		$upload_filter = static function ( array $uploads ) use ( $upload_root, $upload_url ): array {
			$uploads['basedir'] = $upload_root;
			$uploads['baseurl'] = $upload_url;
			$uploads['path']    = $upload_root;
			$uploads['url']     = $upload_url;
			$uploads['subdir']  = '';
			$uploads['error']   = false;
			return $uploads;
		};
		$url_filter    = static function ( string $url, int $attachment_id ) use ( &$events, &$attachment_ids ): string {
			if ( in_array( $attachment_id, $attachment_ids, true ) ) {
				$events['attachmentUrl'][] = array(
					'id'  => $attachment_id,
					'url' => $url,
				);
			}

			return $url;
		};
		$path_filter   = static function ( string $original_image, int $attachment_id ) use ( &$events, &$attachment_ids ): string {
			if ( in_array( $attachment_id, $attachment_ids, true ) ) {
				$events['originalPath'][] = array(
					'id'       => $attachment_id,
					'basename' => basename( $original_image ),
				);
			}

			return $original_image;
		};
		$original_url_filter = static function ( string $original_image_url, int $attachment_id ) use ( &$events, &$attachment_ids ): string {
			if ( in_array( $attachment_id, $attachment_ids, true ) ) {
				$events['originalUrl'][] = array(
					'id'       => $attachment_id,
					'basename' => basename( $original_image_url ),
				);
			}

			return $original_image_url;
		};
		$match_filter        = static function ( bool $match, string $image_location, array $image_meta, int $attachment_id ) use ( &$events, &$attachment_ids ): bool {
			if ( in_array( $attachment_id, $attachment_ids, true ) ) {
				$events['matches'][] = array(
					'id'       => $attachment_id,
					'match'    => $match,
					'basename' => basename( explode( '?', $image_location, 2 )[0] ),
					'hasFile'  => isset( $image_meta['file'] ),
				);
			}

			return $match;
		};

		\ComponentFuzz\ensure_dir( $upload_root );

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_get_attachment_url', $url_filter, 10, 2 );
		\add_filter( 'wp_get_original_image_path', $path_filter, 10, 2 );
		\add_filter( 'wp_get_original_image_url', $original_url_filter, 10, 2 );
		\add_filter( 'wp_image_file_matches_image_meta', $match_filter, 10, 4 );
		try {
			foreach ( array( 'absolute', 'relative', 'legacy' ) as $index => $storage ) {
				$case_ctx         = $ctx->fork( $storage );
				$attachment_id    = 874000 + ( $ctx->iteration() * 10 ) + $index;
				$attachment_ids[] = $attachment_id;
				$year             = (string) $case_ctx->int( 2021, 2026 );
				$month            = str_pad( (string) $case_ctx->int( 1, 12 ), 2, '0', STR_PAD_LEFT );
				$subdir           = $year . '/' . $month;
				$slug             = strtolower( str_replace( array( ':', '_' ), '-', $case_ctx->identifier( 5, 10 ) ) );
				$width            = $case_ctx->int( 900, 2200 );
				$height           = $case_ctx->int( 600, 1600 );
				$medium_width     = max( 1, (int) floor( $width / 2 ) );
				$medium_height    = max( 1, (int) floor( $height / 2 ) );
				$current_file     = $slug . ( 'relative' === $storage ? '' : '-scaled' ) . '.jpg';
				$original_file    = 'relative' === $storage ? '' : $slug . '.jpg';
				$medium_file      = $slug . '-' . $medium_width . 'x' . $medium_height . '.jpg';
				$relative_file    = $subdir . '/' . $current_file;

				if ( 'absolute' === $storage ) {
					$attached_file = $upload_root . '/' . $relative_file;
				} elseif ( 'legacy' === $storage ) {
					$attached_file = $temp_root . '/legacy/wp-content/uploads/' . $relative_file;
				} else {
					$attached_file = $relative_file;
				}

				$expected_attached_file = 'relative' === $storage ? $upload_root . '/' . $relative_file : $attached_file;
				$expected_url           = $upload_url . '/' . $relative_file;
				$expected_original_path = '' === $original_file ? $expected_attached_file : dirname( $expected_attached_file ) . '/' . $original_file;
				$expected_original_url  = '' === $original_file ? $expected_url : dirname( $expected_url ) . '/' . $original_file;
				$metadata               = array(
					'width'      => $width,
					'height'     => $height,
					'file'       => $relative_file,
					'filesize'   => $case_ctx->int( 1000, 9000 ),
					'sizes'      => array(
						'medium' => array(
							'file'      => $medium_file,
							'width'     => $medium_width,
							'height'    => $medium_height,
							'mime-type' => 'image/jpeg',
						),
					),
					'image_meta' => array(
						'created_timestamp' => $case_ctx->int( 1000000000, 1999999999 ),
						'credit'            => 'Component Fuzz ' . $storage,
						'caption'           => 'Original image normalization',
					),
				);
				if ( '' !== $original_file ) {
					$metadata['original_image'] = $original_file;
				}

				self::seed_attachment_post( $attachment_id, 'image/jpeg', $attached_file );
				\wp_cache_set(
					$attachment_id,
					array(
						'_wp_attached_file'       => array( $attached_file ),
						'_wp_attachment_metadata' => array( $metadata ),
					),
					'post_meta'
				);

				$attached_unfiltered = \get_attached_file( $attachment_id, true );
				$attachment_url      = \wp_get_attachment_url( $attachment_id );
				$retrieved_meta      = \wp_get_attachment_metadata( $attachment_id, true );
				$original_path       = \wp_get_original_image_path( $attachment_id, true );
				$original_url        = \wp_get_original_image_url( $attachment_id );
				$current_match       = is_array( $retrieved_meta ) && is_string( $attachment_url )
					? \wp_image_file_matches_image_meta( $attachment_url . '?ver=' . $case_ctx->int( 1, 99 ), $retrieved_meta, $attachment_id )
					: false;
				$original_match      = is_array( $retrieved_meta )
					? \wp_image_file_matches_image_meta( $expected_original_url . '?ver=' . $case_ctx->int( 1, 99 ), $retrieved_meta, $attachment_id )
					: false;
				$medium_match        = is_array( $retrieved_meta )
					? \wp_image_file_matches_image_meta( dirname( $expected_url ) . '/' . $medium_file, $retrieved_meta, $attachment_id )
					: false;
				$foreign_match       = is_array( $retrieved_meta )
					? \wp_image_file_matches_image_meta( dirname( $expected_url ) . '/foreign-' . $medium_file, $retrieved_meta, $attachment_id )
					: true;

				$generated_cases[] = array(
					'id'                   => $attachment_id,
					'storage'              => $storage,
					'attached'             => $attached_unfiltered,
					'url'                  => $attachment_url,
					'originalPath'         => $original_path,
					'originalUrl'          => $original_url,
					'currentMatch'         => $current_match,
					'originalMatch'        => $original_match,
					'mediumMatch'          => $medium_match,
					'foreignMatch'         => $foreign_match,
					'metadata'             => $retrieved_meta,
					'expectedAttached'     => $expected_attached_file,
					'expectedUrl'          => $expected_url,
					'expectedOriginalPath' => $expected_original_path,
					'expectedOriginalUrl'  => $expected_original_url,
				);

				self::collect_failure(
					$failures,
					$metadata === $retrieved_meta
						&& self::serializable_array_ok( $retrieved_meta )
						&& $expected_attached_file === $attached_unfiltered
						&& $expected_url === $attachment_url
						&& $expected_original_path === $original_path
						&& $expected_original_url === $original_url
						&& true === $current_match
						&& true === $original_match
						&& true === $medium_match
						&& false === $foreign_match,
					'original image helpers normalize seeded metadata, storage style, generated sizes, and query strings',
					end( $generated_cases )
				);
			}

			$non_image_id = 874099 + ( $ctx->iteration() * 10 );
			self::seed_attachment_post( $non_image_id, 'application/pdf', 'documents/component-fuzz.pdf' );
			$non_image_path = \wp_get_original_image_path( $non_image_id, true );
			$non_image_url  = \wp_get_original_image_url( $non_image_id );

			self::collect_failure(
				$failures,
				false === $non_image_path && false === $non_image_url,
				'original image helpers fail closed for non-image attachments',
				array(
					'id'   => $non_image_id,
					'path' => $non_image_path,
					'url'  => $non_image_url,
				)
			);
		} finally {
			\remove_filter( 'wp_image_file_matches_image_meta', $match_filter, 10 );
			\remove_filter( 'wp_get_original_image_url', $original_url_filter, 10 );
			\remove_filter( 'wp_get_original_image_path', $path_filter, 10 );
			\remove_filter( 'wp_get_attachment_url', $url_filter, 10 );
			\remove_filter( 'upload_dir', $upload_filter );
		}

		self::collect_failure(
			$failures,
			3 === count( $events['originalPath'] )
				&& 3 === count( $events['originalUrl'] )
				&& 6 === count( $events['attachmentUrl'] )
				&& 12 === count( $events['matches'] )
				&& false === \has_filter( 'upload_dir', $upload_filter )
				&& false === \has_filter( 'wp_get_attachment_url', $url_filter )
				&& false === \has_filter( 'wp_get_original_image_path', $path_filter )
				&& false === \has_filter( 'wp_get_original_image_url', $original_url_filter )
				&& false === \has_filter( 'wp_image_file_matches_image_meta', $match_filter ),
			'original image metadata filters fire only for image cases and are restored',
			array(
				'events'                   => $events,
				'uploadHasFilter'          => \has_filter( 'upload_dir', $upload_filter ),
				'attachmentUrlHasFilter'   => \has_filter( 'wp_get_attachment_url', $url_filter ),
				'originalPathHasFilter'    => \has_filter( 'wp_get_original_image_path', $path_filter ),
				'originalUrlHasFilter'     => \has_filter( 'wp_get_original_image_url', $original_url_filter ),
				'imageFileMatchHasFilter'  => \has_filter( 'wp_image_file_matches_image_meta', $match_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-metadata.original-image-metadata-normalization',
			array() === $failures,
			array(
				'cases'    => $generated_cases,
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_generate_attachment_metadata_branches( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures    = array();
		$events      = array(
			'generated' => array(),
			'read'      => array(),
			'uploads'   => array(),
		);
		$fixture_dir = $temp_root . DIRECTORY_SEPARATOR . 'generate-fixtures';
		$audio_path  = self::write_fixture( $fixture_dir, 'branch-audio.mp3', "ID3\x04\x00\x00\x00\x00\x00\x00" . $ctx->bytes( 8, 32 ) );
		$video_path  = self::write_fixture( $fixture_dir, 'branch-video.mp4', self::mp4_like_bytes( $ctx ) );
		$missing     = $fixture_dir . DIRECTORY_SEPARATOR . 'missing-audio.mp3';

		if ( null === $audio_path || null === $video_path ) {
			return $ctx->result(
				'media-metadata.generate-attachment-audio-video-branches',
				false,
				array( 'failures' => array( array( 'message' => 'metadata branch fixtures are writable' ) ) )
			);
		}

		$audio_id   = 870000 + $ctx->iteration();
		$video_id   = 871000 + $ctx->iteration();
		$missing_id = 872000 + $ctx->iteration();

		self::seed_attachment_post( $audio_id, 'audio/mpeg', $audio_path );
		self::seed_attachment_post( $video_id, 'video/mp4', $video_path );
		self::seed_attachment_post( $missing_id, 'audio/mpeg', $missing );
		\remove_theme_support( 'post-thumbnails' );
		\remove_post_type_support( 'attachment:audio', 'thumbnail' );
		\remove_post_type_support( 'attachment:video', 'thumbnail' );

		$upload_filter = static function ( array $uploads ) use ( $temp_root, &$events ): array {
			$upload_root       = $temp_root . DIRECTORY_SEPARATOR . 'unexpected-cover-uploads';
			$uploads['basedir'] = $upload_root;
			$uploads['baseurl'] = 'http://example.test/component-fuzz-media-metadata';
			$uploads['path']    = $upload_root;
			$uploads['url']     = $uploads['baseurl'];
			$uploads['subdir']  = '';
			$uploads['error']   = false;
			$events['uploads'][] = $uploads['path'];
			return $uploads;
		};
		$audio_filter = static function ( array $metadata, string $file, ?string $file_format, array $data ) use ( &$events ): array {
			unset( $data );
			$metadata['component_fuzz_branch'] = 'audio';
			$metadata['image']                 = array(
				'data'   => 'tiny-cover-audio',
				'mime'   => 'image/png',
				'width'  => 1,
				'height' => 1,
			);
			$events['read'][]                  = array(
				'type'   => 'audio',
				'file'   => basename( $file ),
				'format' => $file_format,
			);
			return $metadata;
		};
		$video_filter = static function ( array $metadata, string $file, ?string $file_format, array $data ) use ( &$events ): array {
			unset( $data );
			$metadata['component_fuzz_branch'] = 'video';
			$metadata['image']                 = array(
				'data'   => 'tiny-cover-video',
				'mime'   => 'image/jpeg',
				'width'  => 2,
				'height' => 2,
			);
			$events['read'][]                  = array(
				'type'   => 'video',
				'file'   => basename( $file ),
				'format' => $file_format,
			);
			return $metadata;
		};
		$generate_filter = static function ( array $metadata, int $attachment_id, string $context ) use ( &$events ): array {
			$events['generated'][] = array(
				'id'      => $attachment_id,
				'context' => $context,
				'keys'    => array_keys( $metadata ),
			);
			return $metadata;
		};

		$before = self::content_counts();

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_read_audio_metadata', $audio_filter, 10, 4 );
		\add_filter( 'wp_read_video_metadata', $video_filter, 10, 4 );
		\add_filter( 'wp_generate_attachment_metadata', $generate_filter, 10, 3 );
		try {
			$audio_meta   = \wp_generate_attachment_metadata( $audio_id, $audio_path );
			$video_meta   = \wp_generate_attachment_metadata( $video_id, $video_path );
			$missing_meta = \wp_generate_attachment_metadata( $missing_id, $missing );
		} finally {
			\remove_filter( 'wp_generate_attachment_metadata', $generate_filter, 10 );
			\remove_filter( 'wp_read_video_metadata', $video_filter, 10 );
			\remove_filter( 'wp_read_audio_metadata', $audio_filter, 10 );
			\remove_filter( 'upload_dir', $upload_filter );
		}

		$after = self::content_counts();

		self::collect_failure(
			$failures,
			is_array( $audio_meta )
				&& is_array( $video_meta )
				&& array() === $missing_meta
				&& 'audio' === ( $audio_meta['component_fuzz_branch'] ?? null )
				&& 'video' === ( $video_meta['component_fuzz_branch'] ?? null )
				&& isset( $audio_meta['filesize'], $video_meta['filesize'] )
				&& filesize( $audio_path ) === (int) $audio_meta['filesize']
				&& filesize( $video_path ) === (int) $video_meta['filesize']
				&& ! isset( $audio_meta['image']['data'], $video_meta['image']['data'] )
				&& 'image/png' === ( $audio_meta['image']['mime'] ?? null )
				&& 'image/jpeg' === ( $video_meta['image']['mime'] ?? null )
				&& ! \metadata_exists( 'post', $audio_id, '_thumbnail_id' )
				&& ! \metadata_exists( 'post', $video_id, '_thumbnail_id' )
				&& $before === $after
				&& array() === $events['uploads']
				&& 2 === count( $events['read'] )
				&& 3 === count( $events['generated'] ),
			'wp_generate_attachment_metadata audio/video branches sanitize cover data and avoid cover attachment side effects',
			array(
				'audioMeta' => $audio_meta,
				'videoMeta' => $video_meta,
				'missing'   => $missing_meta,
				'events'    => $events,
				'before'    => $before,
				'after'     => $after,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'wp_read_audio_metadata', $audio_filter )
				&& false === \has_filter( 'wp_read_video_metadata', $video_filter )
				&& false === \has_filter( 'wp_generate_attachment_metadata', $generate_filter )
				&& false === \has_filter( 'upload_dir', $upload_filter ),
			'metadata generation filters are restored after branch checks',
			array(
				'audioHasFilter'    => \has_filter( 'wp_read_audio_metadata', $audio_filter ),
				'videoHasFilter'    => \has_filter( 'wp_read_video_metadata', $video_filter ),
				'generateHasFilter' => \has_filter( 'wp_generate_attachment_metadata', $generate_filter ),
				'uploadHasFilter'   => \has_filter( 'upload_dir', $upload_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-metadata.generate-attachment-audio-video-branches',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function collect_parser_shape_failures( array &$failures, $metadata, string $path, string $label, string $type ): void {
		self::collect_failure(
			$failures,
			is_array( $metadata )
				&& isset( $metadata[ 'component_fuzz_' . $type . '_filter' ] )
				&& self::serializable_array_ok( $metadata )
				&& self::bounded_metadata_scalars( $metadata )
				&& ( ! isset( $metadata['filesize'] ) || filesize( $path ) === (int) $metadata['filesize'] )
				&& ! isset( $metadata['image']['data'] )
				&& ! isset( $metadata['streams'] )
				&& ! isset( $metadata['audio']['streams'] ),
			"{$type} parser returns serializable bounded metadata for malformed local {$label}",
			array(
				'label'    => $label,
				'path'     => $path,
				'metadata' => $metadata,
			)
		);
	}

	private static function parser_fixtures( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			array(
				'label'    => 'empty-mp3',
				'filename' => 'empty.mp3',
				'bytes'    => '',
			),
			array(
				'label'    => 'truncated-id3',
				'filename' => 'truncated-id3.mp3',
				'bytes'    => "ID3\x04\x00\x00\x00\x00\x00" . chr( $ctx->int( 1, 24 ) ) . $ctx->bytes( 4, 32 ),
			),
			array(
				'label'    => 'minimal-wav',
				'filename' => 'minimal.wav',
				'bytes'    => self::wav_like_bytes( $ctx ),
			),
			array(
				'label'    => 'quicktime-header',
				'filename' => 'quicktime.mp4',
				'bytes'    => self::mp4_like_bytes( $ctx ),
			),
			array(
				'label'    => 'riff-video-header',
				'filename' => 'riff-video.avi',
				'bytes'    => 'RIFF' . pack( 'V', 12 + $ctx->int( 0, 8 ) ) . 'AVI ' . $ctx->bytes( 8, 24 ),
			),
		);
	}

	private static function wav_like_bytes( \ComponentFuzz\FuzzContext $ctx ): string {
		$sample_rate     = $ctx->choice( array( 8000, 11025, 22050, 44100 ) );
		$bits_per_sample = $ctx->choice( array( 8, 16 ) );
		$channels        = $ctx->choice( array( 1, 2 ) );
		$block_align     = max( 1, ( $channels * $bits_per_sample ) / 8 );
		$byte_rate       = $sample_rate * $block_align;

		return 'RIFF'
			. pack( 'V', 36 )
			. 'WAVEfmt '
			. pack( 'VvvVVvv', 16, 1, $channels, $sample_rate, $byte_rate, $block_align, $bits_per_sample )
			. 'data'
			. pack( 'V', 0 );
	}

	private static function mp4_like_bytes( \ComponentFuzz\FuzzContext $ctx ): string {
		$brands = $ctx->choice(
			array(
				array( 'isom', 'iso2' ),
				array( 'mp42', 'isom' ),
				array( 'M4V ', 'mp42' ),
			)
		);

		return pack( 'N', 24 ) . 'ftyp' . $brands[0] . "\x00\x00\x00\x00" . $brands[0] . $brands[1];
	}

	private static function serializable_array_ok( array $value ): bool {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $encoded ) {
			return false;
		}

		return \maybe_unserialize( \maybe_serialize( $value ) ) == $value; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
	}

	private static function bounded_metadata_scalars( array $metadata ): bool {
		foreach ( array( 'bitrate', 'filesize', 'height', 'length', 'width' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && ( ! is_numeric( $metadata[ $key ] ) || (int) $metadata[ $key ] < 0 ) ) {
				return false;
			}
		}

		foreach ( array( 'fileformat', 'mime_type', 'dataformat', 'encoder', 'codec', 'length_formatted' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && ( ! is_scalar( $metadata[ $key ] ) || false !== strpos( (string) $metadata[ $key ], chr( 0 ) ) ) ) {
				return false;
			}
		}

		return true;
	}

	private static function prepare_runtime( string $temp_root ): void {
		self::prepare_getid3_temp_dir();

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
					'uploads_use_yearmonth_folders' => 0,
				)
			);
		}

		\wp_cache_flush();

		if ( ! isset( $GLOBALS['wp_post_types']['attachment'] ) ) {
			\create_initial_post_types();
		}
		if ( empty( $GLOBALS['wp_taxonomies'] ) ) {
			\create_initial_taxonomies();
		}
		if ( ! isset( $GLOBALS['wp_rewrite'] ) || ! $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz MediaMetadata';
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/media-metadata/';

		\ComponentFuzz\ensure_dir( $temp_root . DIRECTORY_SEPARATOR . 'uploads' );
		\wp_set_current_user( 0 );
	}

	private static function prepare_getid3_temp_dir(): void {
		if ( defined( 'GETID3_TEMP_DIR' ) ) {
			if ( is_string( GETID3_TEMP_DIR ) && '' !== GETID3_TEMP_DIR && ! is_dir( GETID3_TEMP_DIR ) ) {
				@ComponentFuzz\ensure_dir( GETID3_TEMP_DIR );
			}
			return;
		}

		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-metadata-getid3-' . getmypid();
		if ( is_dir( $dir ) ) {
			self::remove_any_dir_recursive( $dir );
		}
		\ComponentFuzz\ensure_dir( $dir );

		self::$getid3_temp_root = $dir;
		define( 'GETID3_TEMP_DIR', $dir );
		register_shutdown_function(
			static function (): void {
				if ( null !== MediaMetadataSurface::$getid3_temp_root ) {
					MediaMetadataSurface::remove_any_dir_recursive( MediaMetadataSurface::$getid3_temp_root );
				}
			}
		);
	}

	private static function seed_attachment_post( int $attachment_id, string $mime, string $file ): void {
		\wp_cache_set( $attachment_id, self::attachment_post_object( $attachment_id, $mime, basename( $file ) ), 'posts' );
		\wp_cache_set(
			$attachment_id,
			array(
				'_wp_attached_file' => array( $file ),
			),
			'post_meta'
		);
	}

	private static function attachment_post_object( int $attachment_id, string $mime, string $title ): \WP_Post {
		return new \WP_Post(
			(object) array(
				'ID'                    => $attachment_id,
				'post_author'           => '0',
				'post_date'             => '2026-06-23 00:00:00',
				'post_date_gmt'         => '2026-06-23 00:00:00',
				'post_content'          => '',
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => 'inherit',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => \sanitize_file_name( $title ),
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 00:00:00',
				'post_modified_gmt'     => '2026-06-23 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://example.test/uploads/' . rawurlencode( $title ),
				'menu_order'            => 0,
				'post_type'             => 'attachment',
				'post_mime_type'        => $mime,
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'_wp_theme_features',
				'current_user',
				'user_ID',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_post_statuses',
				'wp_post_types',
				'wp_query',
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
		foreach ( array( 'HTTP_HOST', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'REQUEST_URI' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
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
	}

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function write_fixture( string $dir, string $filename, string $bytes ): ?string {
		\ComponentFuzz\ensure_dir( $dir );
		$path = $dir . DIRECTORY_SEPARATOR . \sanitize_file_name( $filename );

		if ( false === file_put_contents( $path, $bytes ) ) {
			return null;
		}

		return $path;
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR );
		$dir  = $base . DIRECTORY_SEPARATOR . 'component-fuzz-media-metadata-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration();

		if ( is_dir( $dir ) ) {
			self::remove_dir_recursive( $dir );
		}

		if ( mkdir( $dir, 0700, true ) || is_dir( $dir ) ) {
			return $dir;
		}

		return null;
	}

	private static function remove_dir_recursive( string $dir ): void {
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-metadata-';
		if ( ! str_starts_with( $dir, $temp_prefix ) || ! file_exists( $dir ) ) {
			return;
		}

		self::remove_any_dir_recursive( $dir );
	}

	public static function remove_any_dir_recursive( string $dir ): void {
		if ( ! file_exists( $dir ) ) {
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

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ctx->result( $invariant, $ok, $data );
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

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
