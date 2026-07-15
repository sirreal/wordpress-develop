<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes local media metadata, media shortcode rendering, ID3, and audio/video attachment helper paths.
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
				$rows[] = self::check_audio_video_shortcode_rendering( $ctx->fork( 'shortcodes' ) );
				$rows[] = self::check_gallery_playlist_shortcode_rendering( $ctx->fork( 'gallery-playlist' ) );
				$rows[] = self::check_attachment_metadata_get_update_helpers( $ctx->fork( 'metadata' ), $temp_root );
				$rows[] = self::check_generated_metadata_replacement_oracles( $ctx->fork( 'metadata-shapes' ), $temp_root );
				$rows[] = self::check_original_image_metadata_helpers( $ctx->fork( 'original-image' ), $temp_root );
				$rows[] = self::check_generate_attachment_metadata_branches( $ctx->fork( 'generate' ), $temp_root );
				$rows[] = self::check_generate_attachment_cover_creation_reuse( $ctx->fork( 'cover-art' ), $temp_root );
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
				'add_filter',
				'add_action',
				'add_post_type_support',
				'add_theme_support',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_theme_supports',
				'current_user_can',
				'esc_attr',
				'esc_html',
				'esc_url',
				'gallery_shortcode',
				'get_attached_file',
				'get_attachment_link',
				'get_children',
				'get_post_meta',
				'get_post',
				'get_post_mime_type',
				'get_post_thumbnail_id',
				'get_posts',
				'has_action',
				'has_filter',
				'is_feed',
				'is_post_publicly_viewable',
				'metadata_exists',
				'maybe_serialize',
				'maybe_unserialize',
				'post_type_supports',
				'post_password_required',
				'remove_action',
				'remove_filter',
				'remove_post_type_support',
				'remove_theme_support',
				'sanitize_file_name',
				'sanitize_html_class',
				'shortcode_atts',
				'tag_escape',
				'wp_add_id3_tag_data',
				'wp_attachment_is',
				'wp_attachment_is_image',
				'wp_cache_flush',
				'wp_cache_set',
				'wp_check_filetype',
				'wp_enqueue_script',
				'wp_enqueue_style',
				'wp_filesize',
				'wp_generate_attachment_metadata',
				'wp_get_attachment_id3_keys',
				'wp_get_audio_extensions',
				'wp_get_attachment_metadata',
				'wp_get_attachment_image',
				'wp_get_attachment_link',
				'wp_get_attachment_url',
				'wp_get_mime_types',
				'wp_get_original_image_path',
				'wp_get_original_image_url',
				'wp_get_media_creation_timestamp',
				'wp_get_upload_dir',
				'wp_get_video_extensions',
				'wp_image_file_matches_image_meta',
				'wp_insert_attachment',
				'wp_json_encode',
				'wp_mediaelement_fallback',
				'wp_mime_type_icon',
				'wp_parse_id_list',
				'wp_playlist_shortcode',
				'wp_audio_shortcode',
				'wp_video_shortcode',
				'wp_upload_bits',
				'wp_update_attachment_metadata',
				'wp_read_audio_metadata',
				'wp_read_video_metadata',
				'wp_validate_boolean',
				'wp_kses_allowed_html',
				'wptexturize',
				'wp_set_current_user',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'WP_Post', 'WP_Query', 'WP_Rewrite' ) as $class ) {
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

	private static function check_audio_video_shortcode_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures         = array();
		$runtime_snapshot = self::snapshot_media_shortcode_runtime();
		$token            = $ctx->identifier( 5, 12 );
		$audio_events     = array(
			'override' => array(),
			'library'  => array(),
			'class'    => array(),
			'output'   => array(),
		);
		$video_events     = array(
			'override' => array(),
			'library'  => array(),
			'class'    => array(),
			'output'   => array(),
		);
		$fallback_events  = array();
		$audio_library    = 'mediaelement';
		$video_library    = 'mediaelement';

		$audio_src          = self::media_shortcode_url( $ctx->fork( 'audio-src' ), 'audio-main', 'mp3' );
		$audio_typed_mp3    = self::media_shortcode_url( $ctx->fork( 'audio-typed-mp3' ), 'audio-typed', 'mp3' );
		$audio_typed_ogg    = self::media_shortcode_url( $ctx->fork( 'audio-typed-ogg' ), 'audio-typed', 'ogg' );
		$audio_invalid_src  = self::media_shortcode_url( $ctx->fork( 'audio-invalid' ), 'audio-invalid', 'txt' );
		$audio_override_src = self::media_shortcode_url( $ctx->fork( 'audio-override' ), 'audio-override', 'mp3' );
		$youtube_src        = 'http://www.youtube.com/watch?v=' . $ctx->identifier( 8, 12 ) . '&feature=oembed&danger=<script>';
		$vimeo_src          = 'http://player.vimeo.com/video/' . $ctx->int( 100000, 999999 ) . '?autoplay=1&danger=<script>';
		$video_typed_mp4    = self::media_shortcode_url( $ctx->fork( 'video-typed-mp4' ), 'video-typed', 'mp4' );
		$video_typed_webm   = self::media_shortcode_url( $ctx->fork( 'video-typed-webm' ), 'video-typed', 'webm' );
		$video_invalid_src  = self::media_shortcode_url( $ctx->fork( 'video-invalid' ), 'video-invalid', 'txt' );
		$video_override_src = self::media_shortcode_url( $ctx->fork( 'video-override' ), 'video-override', 'mp4' );
		$fallback_url       = self::media_shortcode_url( $ctx->fork( 'fallback' ), 'fallback', 'mp4' );

		$audio_override_filter = static function ( string $html, array $attr, string $content, int $instance ) use ( &$audio_events, $audio_override_src, $token ): string {
			$audio_events['override'][] = array(
				'html'     => $html,
				'src'      => $attr['src'] ?? null,
				'content'  => $content,
				'instance' => $instance,
			);

			return ( $attr['src'] ?? null ) === $audio_override_src
				? '<span data-cfz-audio-override="' . \esc_attr( $token ) . '"></span>'
				: '';
		};
		$audio_library_filter  = static function ( string $library ) use ( &$audio_events, &$audio_library ): string {
			$audio_events['library'][] = $library;
			return $audio_library;
		};
		$audio_class_filter    = static function ( string $class, array $atts ) use ( &$audio_events ): string {
			$audio_events['class'][] = array(
				'class'   => $class,
				'src'     => $atts['src'] ?? null,
				'preload' => $atts['preload'] ?? null,
				'loop'    => $atts['loop'] ?? null,
				'muted'   => $atts['muted'] ?? null,
			);

			return $class . ' cfz-audio-class';
		};
		$audio_output_filter   = static function ( string $html, array $atts, $audio, int $post_id, string $library ) use ( &$audio_events ): string {
			$audio_events['output'][] = array(
				'src'     => $atts['src'] ?? null,
				'class'   => $atts['class'] ?? null,
				'audio'   => $audio,
				'postId'  => $post_id,
				'library' => $library,
			);

			return $html . '<!--cfz-audio-shortcode-->';
		};
		$video_override_filter = static function ( string $html, array $attr, string $content, int $instance ) use ( &$video_events, $video_override_src, $token ): string {
			$video_events['override'][] = array(
				'html'     => $html,
				'src'      => $attr['src'] ?? null,
				'content'  => $content,
				'instance' => $instance,
			);

			return ( $attr['src'] ?? null ) === $video_override_src
				? '<span data-cfz-video-override="' . \esc_attr( $token ) . '"></span>'
				: '';
		};
		$video_library_filter  = static function ( string $library ) use ( &$video_events, &$video_library ): string {
			$video_events['library'][] = $library;
			return $video_library;
		};
		$video_class_filter    = static function ( string $class, array $atts ) use ( &$video_events ): string {
			$video_events['class'][] = array(
				'class'   => $class,
				'src'     => $atts['src'] ?? null,
				'width'   => $atts['width'] ?? null,
				'height'  => $atts['height'] ?? null,
				'preload' => $atts['preload'] ?? null,
			);

			return $class . ' cfz-video-class';
		};
		$video_output_filter   = static function ( string $html, array $atts, $video, int $post_id, string $library ) use ( &$video_events ): string {
			$video_events['output'][] = array(
				'src'     => $atts['src'] ?? null,
				'class'   => $atts['class'] ?? null,
				'video'   => $video,
				'postId'  => $post_id,
				'library' => $library,
			);

			return $html . '<!--cfz-video-shortcode-->';
		};
		$fallback_filter       = static function ( string $output, string $url ) use ( &$fallback_events ): string {
			$fallback_events[] = array(
				'url'    => $url,
				'output' => $output,
			);

			return $output . '<span data-cfz-mediaelement-fallback="1"></span>';
		};

		\add_filter( 'wp_audio_shortcode_override', $audio_override_filter, 10, 4 );
		\add_filter( 'wp_audio_shortcode_library', $audio_library_filter, 10, 1 );
		\add_filter( 'wp_audio_shortcode_class', $audio_class_filter, 10, 2 );
		\add_filter( 'wp_audio_shortcode', $audio_output_filter, 10, 5 );
		\add_filter( 'wp_video_shortcode_override', $video_override_filter, 10, 4 );
		\add_filter( 'wp_video_shortcode_library', $video_library_filter, 10, 1 );
		\add_filter( 'wp_video_shortcode_class', $video_class_filter, 10, 2 );
		\add_filter( 'wp_video_shortcode', $video_output_filter, 10, 5 );
		\add_filter( 'wp_mediaelement_fallback', $fallback_filter, 10, 2 );

		try {
			$GLOBALS['content_width'] = 480;

			$audio_library = 'mediaelement';
			$audio_html    = \wp_audio_shortcode(
				array(
					'src'      => $audio_src,
					'loop'     => '1',
					'autoplay' => 'true',
					'muted'    => '1',
					'preload'  => 'auto',
					'class'    => 'wp-audio-shortcode cfz-base',
					'style'    => 'width: 80%; max-width: 640px;',
				)
			);

			$audio_invalid = \wp_audio_shortcode( array( 'src' => $audio_invalid_src ) );

			$audio_library = 'html5';
			$audio_typed   = \wp_audio_shortcode(
				array(
					'mp3'     => $audio_typed_mp3,
					'ogg'     => $audio_typed_ogg,
					'preload' => 'invalid-preload',
				)
			);

			$audio_override = \wp_audio_shortcode( array( 'src' => $audio_override_src ), 'override content' );

			$video_library = 'mediaelement';
			$youtube_html  = \wp_video_shortcode(
				array(
					'src'      => $youtube_src,
					'width'    => 320,
					'height'   => 180,
					'poster'   => 'https://media.example.test/poster-' . rawurlencode( $token ) . '.jpg?bad=<script>',
					'loop'     => '1',
					'autoplay' => 'false',
					'muted'    => 'true',
					'preload'  => 'invalid-preload',
				),
				"\n<track kind=\"captions\" srclang=\"en\" src=\"https://media.example.test/captions-{$token}.vtt\" />\n"
			);

			$vimeo_html = \wp_video_shortcode(
				array(
					'src'     => $vimeo_src,
					'width'   => 360,
					'height'  => 240,
					'loop'    => '1',
					'preload' => 'metadata',
				)
			);

			$video_invalid = \wp_video_shortcode( array( 'src' => $video_invalid_src ) );

			$video_library = 'html5';
			$video_typed   = \wp_video_shortcode(
				array(
					'mp4'     => $video_typed_mp4,
					'webm'    => $video_typed_webm,
					'width'   => 300,
					'height'  => 160,
					'preload' => 'none',
				)
			);

			$video_override = \wp_video_shortcode( array( 'src' => $video_override_src ), 'override content' );
			$fallback_html  = \wp_mediaelement_fallback( $fallback_url );
		} finally {
			\remove_filter( 'wp_mediaelement_fallback', $fallback_filter, 10 );
			\remove_filter( 'wp_video_shortcode', $video_output_filter, 10 );
			\remove_filter( 'wp_video_shortcode_class', $video_class_filter, 10 );
			\remove_filter( 'wp_video_shortcode_library', $video_library_filter, 10 );
			\remove_filter( 'wp_video_shortcode_override', $video_override_filter, 10 );
			\remove_filter( 'wp_audio_shortcode', $audio_output_filter, 10 );
			\remove_filter( 'wp_audio_shortcode_class', $audio_class_filter, 10 );
			\remove_filter( 'wp_audio_shortcode_library', $audio_library_filter, 10 );
			\remove_filter( 'wp_audio_shortcode_override', $audio_override_filter, 10 );
			self::restore_media_shortcode_runtime( $runtime_snapshot );
		}

		self::collect_failure(
			$failures,
			is_string( $audio_html )
				&& str_contains( $audio_html, '<audio ' )
				&& str_contains( $audio_html, ' controls="controls"' )
				&& 1 === preg_match( '/id="audio-\d+-\d+"/', $audio_html )
				&& str_contains( $audio_html, 'class="wp-audio-shortcode cfz-base cfz-audio-class"' )
				&& str_contains( $audio_html, 'loop autoplay muted' )
				&& str_contains( $audio_html, 'preload="auto"' )
				&& str_contains( $audio_html, '<source type="audio/mpeg"' )
				&& str_contains( $audio_html, '.mp3' )
				&& str_contains( $audio_html, '<span data-cfz-mediaelement-fallback="1"></span>' )
				&& str_contains( $audio_html, '<!--cfz-audio-shortcode-->' )
				&& self::media_shortcode_markup_has_no_raw_payload( $audio_html ),
			'audio shortcode renders escaped mediaelement markup with normalized boolean/preload attributes and fallback',
			array( 'audio' => self::describe_string( is_string( $audio_html ) ? $audio_html : '' ) )
		);

		self::collect_failure(
			$failures,
			is_string( $audio_typed )
				&& 2 === substr_count( $audio_typed, '<source ' )
				&& str_contains( $audio_typed, '.mp3' )
				&& str_contains( $audio_typed, '.ogg' )
				&& ! str_contains( $audio_typed, 'invalid-preload' )
				&& ! str_contains( $audio_typed, 'data-cfz-mediaelement-fallback' )
				&& str_contains( $audio_typed, '<!--cfz-audio-shortcode-->' )
				&& self::media_shortcode_markup_has_no_raw_payload( $audio_typed ),
			'audio shortcode renders multiple typed sources and omits invalid preload values without mediaelement fallback when library changes',
			array( 'audioTyped' => self::describe_string( is_string( $audio_typed ) ? $audio_typed : '' ) )
		);

		self::collect_failure(
			$failures,
			is_string( $audio_invalid )
				&& str_starts_with( $audio_invalid, '<a class="wp-embedded-audio" href="' )
				&& str_contains( $audio_invalid, \esc_url( $audio_invalid_src ) )
				&& self::media_shortcode_markup_has_no_raw_payload( $audio_invalid )
				&& '<span data-cfz-audio-override="' . \esc_attr( $token ) . '"></span>' === $audio_override,
			'audio shortcode invalid source fallback and override filter short-circuit are deterministic',
			array(
				'invalid'  => self::describe_string( is_string( $audio_invalid ) ? $audio_invalid : '' ),
				'override' => $audio_override,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $youtube_html )
				&& str_contains( $youtube_html, '<div style="width: 320px;" class="wp-video">' )
				&& str_contains( $youtube_html, '<video ' )
				&& str_contains( $youtube_html, 'class="wp-video-shortcode cfz-video-class"' )
				&& str_contains( $youtube_html, 'width="320"' )
				&& str_contains( $youtube_html, 'height="180"' )
				&& str_contains( $youtube_html, 'loop muted' )
				&& ! str_contains( $youtube_html, ' autoplay' )
				&& ! str_contains( $youtube_html, 'invalid-preload' )
				&& str_contains( $youtube_html, 'type="video/youtube"' )
				&& str_contains( $youtube_html, 'https://www.youtube.com/watch?v=' )
				&& ! str_contains( $youtube_html, 'feature=oembed' )
				&& str_contains( $youtube_html, '<track kind="captions"' )
				&& str_contains( $youtube_html, '<span data-cfz-mediaelement-fallback="1"></span>' )
				&& str_contains( $youtube_html, '<!--cfz-video-shortcode-->' )
				&& self::media_shortcode_markup_has_no_raw_payload( $youtube_html ),
			'video shortcode normalizes YouTube mediaelement sources, dimensions, boolean attrs, captions, and output filters',
			array( 'youtube' => self::describe_string( is_string( $youtube_html ) ? $youtube_html : '' ) )
		);

		self::collect_failure(
			$failures,
			is_string( $vimeo_html )
				&& str_contains( $vimeo_html, 'type="video/vimeo"' )
				&& str_contains( $vimeo_html, 'https://player.vimeo.com/video/' )
				&& str_contains( $vimeo_html, 'loop=1' )
				&& ! str_contains( $vimeo_html, 'autoplay=1' )
				&& ! str_contains( $vimeo_html, 'danger=' )
				&& str_contains( $vimeo_html, '<span data-cfz-mediaelement-fallback="1"></span>' )
				&& self::media_shortcode_markup_has_no_raw_payload( $vimeo_html ),
			'video shortcode normalizes Vimeo mediaelement URLs to HTTPS path plus loop state',
			array( 'vimeo' => self::describe_string( is_string( $vimeo_html ) ? $vimeo_html : '' ) )
		);

		self::collect_failure(
			$failures,
			is_string( $video_typed )
				&& 2 === substr_count( $video_typed, '<source ' )
				&& str_contains( $video_typed, 'type="video/mp4"' )
				&& str_contains( $video_typed, 'type="video/webm"' )
				&& str_contains( $video_typed, 'preload="none"' )
				&& ! str_contains( $video_typed, 'data-cfz-mediaelement-fallback' )
				&& str_contains( $video_typed, '<!--cfz-video-shortcode-->' )
				&& self::media_shortcode_markup_has_no_raw_payload( $video_typed ),
			'video shortcode renders multiple typed sources and omits mediaelement fallback when library changes',
			array( 'videoTyped' => self::describe_string( is_string( $video_typed ) ? $video_typed : '' ) )
		);

		self::collect_failure(
			$failures,
			is_string( $video_invalid )
				&& str_starts_with( $video_invalid, '<a class="wp-embedded-video" href="' )
				&& str_contains( $video_invalid, \esc_url( $video_invalid_src ) )
				&& self::media_shortcode_markup_has_no_raw_payload( $video_invalid )
				&& '<span data-cfz-video-override="' . \esc_attr( $token ) . '"></span>' === $video_override,
			'video shortcode invalid source fallback and override filter short-circuit are deterministic',
			array(
				'invalid'  => self::describe_string( is_string( $video_invalid ) ? $video_invalid : '' ),
				'override' => $video_override,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $fallback_html )
				&& str_contains( $fallback_html, '<a href="' . \esc_url( $fallback_url ) . '">' )
				&& str_contains( $fallback_html, '<span data-cfz-mediaelement-fallback="1"></span>' )
				&& self::media_shortcode_markup_has_no_raw_payload( $fallback_html ),
			'wp_mediaelement_fallback escapes generated URLs and remains filterable',
			array(
				'fallback' => self::describe_string( is_string( $fallback_html ) ? $fallback_html : '' ),
				'events'   => $fallback_events,
			)
		);

		self::collect_failure(
			$failures,
			4 === count( $audio_events['override'] )
				&& 2 === count( $audio_events['library'] )
				&& 2 === count( $audio_events['class'] )
				&& 2 === count( $audio_events['output'] )
				&& 5 === count( $video_events['override'] )
				&& 3 === count( $video_events['library'] )
				&& 3 === count( $video_events['class'] )
				&& 3 === count( $video_events['output'] )
				&& count( $fallback_events ) >= 4
				&& false === \has_filter( 'wp_audio_shortcode_override', $audio_override_filter )
				&& false === \has_filter( 'wp_audio_shortcode_library', $audio_library_filter )
				&& false === \has_filter( 'wp_audio_shortcode_class', $audio_class_filter )
				&& false === \has_filter( 'wp_audio_shortcode', $audio_output_filter )
				&& false === \has_filter( 'wp_video_shortcode_override', $video_override_filter )
				&& false === \has_filter( 'wp_video_shortcode_library', $video_library_filter )
				&& false === \has_filter( 'wp_video_shortcode_class', $video_class_filter )
				&& false === \has_filter( 'wp_video_shortcode', $video_output_filter )
				&& false === \has_filter( 'wp_mediaelement_fallback', $fallback_filter )
				&& self::media_shortcode_runtime_matches( $runtime_snapshot ),
			'audio/video shortcode filters receive expected normalized payloads and cleanup restores filters and media globals',
			array(
				'audioEvents'    => $audio_events,
				'videoEvents'    => $video_events,
				'fallbackEvents' => $fallback_events,
				'runtimeAfter'   => self::snapshot_media_shortcode_runtime(),
			)
		);

		return self::row(
			$ctx,
			'media-metadata.audio-video-shortcode-rendering-filters',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_gallery_playlist_shortcode_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures                 = array();
		$runtime_snapshot         = self::snapshot_media_shortcode_runtime();
		$previous_post_set        = array_key_exists( 'post', $GLOBALS );
		$previous_post            = $GLOBALS['post'] ?? null;
		$previous_wp_query_set    = array_key_exists( 'wp_query', $GLOBALS );
		$previous_wp_query        = $GLOBALS['wp_query'] ?? null;
		$theme_features_set       = array_key_exists( '_wp_theme_features', $GLOBALS );
		$theme_features           = $GLOBALS['_wp_theme_features'] ?? null;
		$footer_template_before   = \has_action( 'wp_footer', 'wp_underscore_playlist_templates' );
		$admin_template_before    = \has_action( 'admin_footer', 'wp_underscore_playlist_templates' );
		$base_id                  = 918000 + ( $ctx->iteration() * 100 );
		$token                    = $ctx->identifier( 5, 12 );
		$parent_id                = $base_id + 1;
		$private_parent_id        = $base_id + 2;
		$landscape_id             = $base_id + 11;
		$portrait_id              = $base_id + 12;
		$pdf_id                   = $base_id + 13;
		$private_image_id         = $base_id + 14;
		$audio_id                 = $base_id + 21;
		$second_audio_id          = $base_id + 22;
		$private_audio_id         = $base_id + 23;
		$video_id                 = $base_id + 31;
		$thumb_id                 = $base_id + 41;
		$gallery_events           = array(
			'override'     => array(),
			'atts'         => array(),
			'defaultStyle' => array(),
			'style'        => array(),
			'imageAttrs'   => array(),
			'links'        => array(),
		);
		$playlist_events          = array(
			'override' => array(),
			'atts'     => array(),
			'scripts'  => array(),
			'id3'      => array(),
		);
		$query_events             = array();
		$image_source_events      = array();
		$attachment_url_events    = array();
		$gallery_style_enabled    = true;
		$footer_template_after    = null;
		$admin_template_after     = null;
		$filters_removed          = false;

		$parent = self::seed_post_cache(
			$parent_id,
			array(
				'post_title' => 'Component gallery host ' . $token,
				'post_name'  => 'component-gallery-host-' . $token,
			)
		);
		self::seed_post_cache(
			$private_parent_id,
			array(
				'post_title'    => 'Private component gallery host ' . $token,
				'post_name'     => 'private-component-gallery-host-' . $token,
				'post_status'   => 'private',
				'post_password' => 'secret-' . $token,
			)
		);

		self::seed_attachment_post(
			$landscape_id,
			'image/jpeg',
			'2026/06/gallery-landscape-' . $token . '.jpg',
			array(
				'post_parent'  => $parent_id,
				'post_title'   => 'Landscape ' . $token,
				'post_excerpt' => 'Landscape caption ' . $token,
				'menu_order'   => 1,
			),
			array(
				'_wp_attachment_metadata'  => array(
					'file'   => '2026/06/gallery-landscape-' . $token . '.jpg',
					'width'  => 1600,
					'height' => 900,
				),
				'_wp_attachment_image_alt' => 'Landscape alt ' . $token,
			)
		);
		self::seed_attachment_post(
			$portrait_id,
			'image/jpeg',
			'2026/06/gallery-portrait-' . $token . '.jpg',
			array(
				'post_parent'  => $parent_id,
				'post_title'   => 'Portrait ' . $token,
				'post_excerpt' => 'Portrait caption ' . $token,
				'menu_order'   => 2,
			),
			array(
				'_wp_attachment_metadata'  => array(
					'file'   => '2026/06/gallery-portrait-' . $token . '.jpg',
					'width'  => 700,
					'height' => 1100,
				),
				'_wp_attachment_image_alt' => 'Portrait alt ' . $token,
			)
		);
		self::seed_attachment_post(
			$pdf_id,
			'application/pdf',
			'2026/06/gallery-document-' . $token . '.pdf',
			array(
				'post_parent' => $parent_id,
				'post_title'  => 'Document ' . $token,
				'menu_order'  => 3,
			)
		);
		self::seed_attachment_post(
			$private_image_id,
			'image/jpeg',
			'2026/06/private-gallery-' . $token . '.jpg',
			array(
				'post_parent'  => $private_parent_id,
				'post_title'   => 'Private image ' . $token,
				'post_excerpt' => 'Private caption ' . $token,
			),
			array(
				'_wp_attachment_metadata' => array(
					'file'   => '2026/06/private-gallery-' . $token . '.jpg',
					'width'  => 300,
					'height' => 200,
				),
			)
		);
		self::seed_attachment_post(
			$thumb_id,
			'image/jpeg',
			'2026/06/playlist-thumb-' . $token . '.jpg',
			array(
				'post_parent' => $parent_id,
				'post_title'  => 'Playlist thumb ' . $token,
			),
			array(
				'_wp_attachment_metadata' => array(
					'file'   => '2026/06/playlist-thumb-' . $token . '.jpg',
					'width'  => 640,
					'height' => 360,
				),
			)
		);
		self::seed_attachment_post(
			$audio_id,
			'audio/mpeg',
			'2026/06/playlist-audio-' . $token . '.mp3',
			array(
				'post_parent'  => $parent_id,
				'post_title'   => 'Audio title ' . $token,
				'post_excerpt' => 'Audio caption ' . $token,
				'post_content' => 'Audio description <tag> ' . $token,
				'menu_order'   => 1,
			),
			array(
				'_thumbnail_id'            => (string) $thumb_id,
				'_wp_attachment_metadata'  => array(
					'artist'           => 'Artist ' . $token,
					'album'            => 'Album ' . $token,
					'genre'            => 'Genre ' . $token,
					'year'             => '2026',
					'length_formatted' => '1:23',
					'composer'         => 'Composer ' . $token,
				),
			)
		);
		self::seed_attachment_post(
			$second_audio_id,
			'audio/ogg',
			'2026/06/playlist-second-' . $token . '.ogg',
			array(
				'post_parent'  => $parent_id,
				'post_title'   => 'Second audio ' . $token,
				'post_excerpt' => 'Second caption ' . $token,
				'menu_order'   => 2,
			),
			array(
				'_wp_attachment_metadata' => array(
					'artist'           => 'Second artist ' . $token,
					'length_formatted' => '2:34',
				),
			)
		);
		self::seed_attachment_post(
			$private_audio_id,
			'audio/mpeg',
			'2026/06/private-audio-' . $token . '.mp3',
			array(
				'post_parent' => $private_parent_id,
				'post_title'  => 'Private audio ' . $token,
			),
			array(
				'_wp_attachment_metadata' => array(
					'length_formatted' => '0:42',
				),
			)
		);
		self::seed_attachment_post(
			$video_id,
			'video/mp4',
			'2026/06/playlist-video-' . $token . '.mp4',
			array(
				'post_parent'  => $parent_id,
				'post_title'   => 'Video title ' . $token,
				'post_excerpt' => 'Video caption ' . $token,
				'post_content' => 'Video description <tag> ' . $token,
			),
			array(
				'_wp_attachment_metadata' => array(
					'width'            => 1280,
					'height'           => 720,
					'length_formatted' => '3:21',
				),
			)
		);

		$fixtures = array();
		foreach (
			array(
				$landscape_id      => 'image',
				$portrait_id       => 'image',
				$pdf_id            => 'document',
				$private_image_id  => 'image',
				$thumb_id          => 'image',
				$audio_id          => 'audio',
				$second_audio_id   => 'audio',
				$private_audio_id  => 'audio',
				$video_id          => 'video',
			) as $attachment_id => $kind
		) {
			$fixtures[ $attachment_id ] = array(
				'kind' => $kind,
				'post' => \get_post( $attachment_id ),
			);
		}

		$query_filter = static function ( $posts, \WP_Query $query ) use ( &$query_events, $fixtures ) {
			$vars = $query->query_vars;
			if ( 'attachment' !== ( $vars['post_type'] ?? null ) ) {
				return $posts;
			}

			$mime_group = $vars['post_mime_type'] ?? '';
			if ( ! in_array( $mime_group, array( 'image', 'audio', 'video' ), true ) ) {
				return $posts;
			}

			$post__in     = array_map( 'intval', (array) ( $vars['post__in'] ?? array() ) );
			$post__not_in = array_map( 'intval', (array) ( $vars['post__not_in'] ?? array() ) );
			$post_parent  = isset( $vars['post_parent'] ) && '' !== $vars['post_parent'] ? (int) $vars['post_parent'] : null;

			$query_events[] = array(
				'mime'          => $mime_group,
				'postIn'        => $post__in,
				'postNotIn'     => $post__not_in,
				'postParent'    => $post_parent,
				'orderby'       => $vars['orderby'] ?? null,
				'order'         => $vars['order'] ?? null,
				'noFoundRows'   => $vars['no_found_rows'] ?? null,
				'suppress'      => $vars['suppress_filters'] ?? null,
			);

			$selected = array();
			foreach ( $fixtures as $attachment_id => $fixture ) {
				$post = $fixture['post'];
				if ( ! $post instanceof \WP_Post || $mime_group !== $fixture['kind'] ) {
					continue;
				}
				if ( null !== $post_parent && $post_parent !== (int) $post->post_parent ) {
					continue;
				}
				if ( array() !== $post__in && ! in_array( (int) $attachment_id, $post__in, true ) ) {
					continue;
				}
				if ( in_array( (int) $attachment_id, $post__not_in, true ) ) {
					continue;
				}
				$selected[] = $post;
			}

			if ( array() !== $post__in ) {
				usort(
					$selected,
					static function ( \WP_Post $a, \WP_Post $b ) use ( $post__in ): int {
						return array_search( (int) $a->ID, $post__in, true ) <=> array_search( (int) $b->ID, $post__in, true );
					}
				);
			}

			$query->found_posts   = count( $selected );
			$query->max_num_pages = 1;
			return array_values( $selected );
		};
		$gallery_override_filter = static function ( string $output, array $attr, int $instance ) use ( &$gallery_events, $token ): string {
			$gallery_events['override'][] = array(
				'include'  => $attr['include'] ?? null,
				'orderby'  => $attr['orderby'] ?? null,
				'instance' => $instance,
				'marker'   => $attr['data-cfz'] ?? null,
			);

			if ( 'override' === ( $attr['data-cfz'] ?? null ) ) {
				return '<div data-cfz-gallery-override="' . \esc_attr( $token ) . '" data-instance="' . (int) $instance . '"></div>';
			}

			return $output;
		};
		$gallery_atts_filter     = static function ( array $out, array $pairs, array $atts, string $shortcode ) use ( &$gallery_events ): array {
			$gallery_events['atts'][] = array(
				'shortcode' => $shortcode,
				'columns'   => $out['columns'] ?? null,
				'link'      => $out['link'] ?? null,
				'rawKeys'   => array_keys( $atts ),
			);

			if ( isset( $atts['data-cfz-columns'] ) ) {
				$out['columns'] = max( 1, (int) $atts['data-cfz-columns'] );
			}

			return $out;
		};
		$default_style_filter    = static function ( bool $print ) use ( &$gallery_events, &$gallery_style_enabled ): bool {
			$gallery_events['defaultStyle'][] = $print;
			return $gallery_style_enabled;
		};
		$gallery_style_filter    = static function ( string $style ) use ( &$gallery_events, $token ): string {
			$gallery_events['style'][] = array(
				'hasStyleTag' => str_contains( $style, '<style>' ),
				'bytes'       => strlen( $style ),
			);

			return $style . '<!--cfz-gallery-style-' . \esc_attr( $token ) . '-->';
		};
		$image_source_filter     = static function ( $image, int $attachment_id, $size, bool $icon ) use ( &$image_source_events, $token ) {
			$size_key              = is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size;
			$image_source_events[] = array(
				'id'   => $attachment_id,
				'size' => $size_key,
				'icon' => $icon,
			);

			if ( str_contains( $size_key, 'full' ) ) {
				return array( 'https://media.example.test/full-' . $attachment_id . '-' . rawurlencode( $token ) . '.jpg', 1200, 800, false );
			}

			if ( str_contains( $size_key, 'large' ) ) {
				return array( 'https://media.example.test/large-' . $attachment_id . '-' . rawurlencode( $token ) . '.jpg', 960, 540, true );
			}

			return array( 'https://media.example.test/thumb-' . $attachment_id . '-' . rawurlencode( $token ) . '.jpg', 300, 200, true );
		};
		$image_attributes_filter = static function ( array $attr, \WP_Post $attachment, $size ) use ( &$gallery_events, $token ): array {
			$gallery_events['imageAttrs'][] = array(
				'id'       => (int) $attachment->ID,
				'size'     => is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size,
				'hasAria'  => isset( $attr['aria-describedby'] ),
				'hasWidth' => isset( $attr['width'] ),
			);
			$attr['class']              = trim( (string) ( $attr['class'] ?? '' ) . ' component-gallery-image-' . $token );
			$attr['data-cfz-gallery']   = 'image <' . $token . '>';

			return $attr;
		};
		$link_attributes_filter  = static function ( array $attributes, int $id ) use ( &$gallery_events, $token ): array {
			$gallery_events['links'][] = array(
				'id'   => $id,
				'href' => $attributes['href'] ?? null,
			);
			$attributes['data-cfz-link'] = 'gallery ' . $token;

			return $attributes;
		};
		$attachment_url_filter   = static function ( $url, int $attachment_id ) use ( &$attachment_url_events, $token ) {
			$attachment_url_events[] = array(
				'id'  => $attachment_id,
				'url' => $url,
			);

			if ( is_string( $url ) ) {
				return preg_replace( '/\.([A-Za-z0-9]+)$/', '-cfz-' . rawurlencode( $token ) . '.$1', $url ) ?? $url;
			}

			return $url;
		};
		$playlist_override_filter = static function ( string $output, array $attr, int $instance ) use ( &$playlist_events, $token ): string {
			$playlist_events['override'][] = array(
				'include'  => $attr['include'] ?? null,
				'orderby'  => $attr['orderby'] ?? null,
				'instance' => $instance,
				'marker'   => $attr['data-cfz'] ?? null,
			);

			if ( 'override' === ( $attr['data-cfz'] ?? null ) ) {
				return '<div data-cfz-playlist-override="' . \esc_attr( $token ) . '" data-instance="' . (int) $instance . '"></div>';
			}

			return $output;
		};
		$playlist_atts_filter    = static function ( array $out, array $pairs, array $atts, string $shortcode ) use ( &$playlist_events ): array {
			$playlist_events['atts'][] = array(
				'shortcode' => $shortcode,
				'type'      => $out['type'] ?? null,
				'style'     => $out['style'] ?? null,
				'rawKeys'   => array_keys( $atts ),
			);

			if ( isset( $atts['data-cfz-style'] ) ) {
				$out['style'] = (string) $atts['data-cfz-style'];
			}

			return $out;
		};
		$playlist_scripts_action = static function ( string $type, string $style ) use ( &$playlist_events ): void {
			$playlist_events['scripts'][] = array(
				'type'  => $type,
				'style' => $style,
			);
		};
		$id3_keys_filter         = static function ( array $fields, \WP_Post $attachment, string $context ) use ( &$playlist_events ): array {
			$playlist_events['id3'][] = array(
				'id'      => (int) $attachment->ID,
				'context' => $context,
				'keys'    => array_keys( $fields ),
			);
			$fields['composer'] = 'Composer';

			return $fields;
		};
		$auto_sizes_filter       = static function (): bool {
			return false;
		};
		$loading_filter          = static function (): array {
			return array();
		};
		$has_query_event         = static function ( string $mime, ?array $post_in, ?int $parent ) use ( &$query_events ): bool {
			foreach ( $query_events as $event ) {
				if ( $mime !== ( $event['mime'] ?? null ) ) {
					continue;
				}
				if ( null !== $parent && $parent !== ( $event['postParent'] ?? null ) ) {
					continue;
				}
				if ( null !== $post_in && array_values( $post_in ) !== array_values( $event['postIn'] ?? array() ) ) {
					continue;
				}
				return true;
			}

			return false;
		};

		\add_filter( 'posts_pre_query', $query_filter, 10, 2 );
		\add_filter( 'post_gallery', $gallery_override_filter, 10, 3 );
		\add_filter( 'shortcode_atts_gallery', $gallery_atts_filter, 10, 4 );
		\add_filter( 'use_default_gallery_style', $default_style_filter, 10, 1 );
		\add_filter( 'gallery_style', $gallery_style_filter, 10, 1 );
		\add_filter( 'wp_get_attachment_image_src', $image_source_filter, 10, 4 );
		\add_filter( 'wp_get_attachment_image_attributes', $image_attributes_filter, 10, 3 );
		\add_filter( 'wp_get_attachment_link_attributes', $link_attributes_filter, 10, 2 );
		\add_filter( 'wp_get_attachment_url', $attachment_url_filter, 10, 2 );
		\add_filter( 'post_playlist', $playlist_override_filter, 10, 3 );
		\add_filter( 'shortcode_atts_playlist', $playlist_atts_filter, 10, 4 );
		\add_action( 'wp_playlist_scripts', $playlist_scripts_action, 1, 2 );
		\add_filter( 'wp_get_attachment_id3_keys', $id3_keys_filter, 10, 3 );
		\add_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter );
		\add_filter( 'pre_wp_get_loading_optimization_attributes', $loading_filter, 10, 4 );

		try {
			$GLOBALS['post']          = $parent;
			$GLOBALS['wp_query']      = new \WP_Query();
			$GLOBALS['content_width'] = 422;

			$gallery_override = \gallery_shortcode(
				array(
					'ids'      => "{$landscape_id},{$portrait_id}",
					'data-cfz' => 'override',
				)
			);

			$legacy_gallery = \gallery_shortcode(
				array(
					'ids'              => "{$landscape_id},{$portrait_id},{$pdf_id}",
					'link'             => 'none',
					'size'             => 'thumbnail',
					'itemtag'          => 'script',
					'icontag'          => 'style',
					'captiontag'       => 'iframe',
					'data-cfz-columns' => 2,
				)
			);

			$gallery_style_enabled = false;
			\add_theme_support( 'html5', array( 'gallery', 'style' ) );
			$html5_gallery         = \gallery_shortcode(
				array(
					'ids'     => "{$portrait_id},{$landscape_id}",
					'link'    => 'file',
					'size'    => 'large',
					'columns' => 3,
				)
			);

			$private_gallery = \gallery_shortcode(
				array(
					'id'   => $private_parent_id,
					'link' => 'none',
				)
			);

			$playlist_override = \wp_playlist_shortcode(
				array(
					'ids'      => "{$audio_id},{$second_audio_id}",
					'data-cfz' => 'override',
				)
			);

			$audio_playlist = \wp_playlist_shortcode(
				array(
					'ids'            => "{$audio_id},{$second_audio_id}",
					'tracklist'      => '0',
					'tracknumbers'   => '1',
					'images'         => '1',
					'artists'        => '0',
					'data-cfz-style' => 'dark',
				)
			);

			$video_playlist = \wp_playlist_shortcode(
				array(
					'ids'     => (string) $video_id,
					'type'    => 'clips',
					'images'  => '0',
					'artists' => '1',
				)
			);

			$private_playlist = \wp_playlist_shortcode(
				array(
					'id'   => $private_parent_id,
					'type' => 'audio',
				)
			);

			$audio_data = self::media_playlist_json_data( is_string( $audio_playlist ) ? $audio_playlist : '' );
			$video_data = self::media_playlist_json_data( is_string( $video_playlist ) ? $video_playlist : '' );
		} finally {
			\remove_filter( 'pre_wp_get_loading_optimization_attributes', $loading_filter, 10 );
			\remove_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter, 10 );
			\remove_filter( 'wp_get_attachment_id3_keys', $id3_keys_filter, 10 );
			\remove_action( 'wp_playlist_scripts', $playlist_scripts_action, 1 );
			\remove_filter( 'shortcode_atts_playlist', $playlist_atts_filter, 10 );
			\remove_filter( 'post_playlist', $playlist_override_filter, 10 );
			\remove_filter( 'wp_get_attachment_url', $attachment_url_filter, 10 );
			\remove_filter( 'wp_get_attachment_link_attributes', $link_attributes_filter, 10 );
			\remove_filter( 'wp_get_attachment_image_attributes', $image_attributes_filter, 10 );
			\remove_filter( 'wp_get_attachment_image_src', $image_source_filter, 10 );
			\remove_filter( 'gallery_style', $gallery_style_filter, 10 );
			\remove_filter( 'use_default_gallery_style', $default_style_filter, 10 );
			\remove_filter( 'shortcode_atts_gallery', $gallery_atts_filter, 10 );
			\remove_filter( 'post_gallery', $gallery_override_filter, 10 );
			\remove_filter( 'posts_pre_query', $query_filter, 10 );

			if ( false === $footer_template_before && false !== \has_action( 'wp_footer', 'wp_underscore_playlist_templates' ) ) {
				\remove_action( 'wp_footer', 'wp_underscore_playlist_templates', 0 );
			}
			if ( false === $admin_template_before && false !== \has_action( 'admin_footer', 'wp_underscore_playlist_templates' ) ) {
				\remove_action( 'admin_footer', 'wp_underscore_playlist_templates', 0 );
			}

			if ( $previous_post_set ) {
				$GLOBALS['post'] = $previous_post;
			} else {
				unset( $GLOBALS['post'] );
			}
			if ( $previous_wp_query_set ) {
				$GLOBALS['wp_query'] = $previous_wp_query;
			} else {
				unset( $GLOBALS['wp_query'] );
			}
			if ( $theme_features_set ) {
				$GLOBALS['_wp_theme_features'] = $theme_features;
			} else {
				unset( $GLOBALS['_wp_theme_features'] );
			}

			self::restore_media_shortcode_runtime( $runtime_snapshot );

			$footer_template_after = \has_action( 'wp_footer', 'wp_underscore_playlist_templates' );
			$admin_template_after  = \has_action( 'admin_footer', 'wp_underscore_playlist_templates' );
			$filters_removed       = false === \has_filter( 'posts_pre_query', $query_filter )
				&& false === \has_filter( 'post_gallery', $gallery_override_filter )
				&& false === \has_filter( 'shortcode_atts_gallery', $gallery_atts_filter )
				&& false === \has_filter( 'use_default_gallery_style', $default_style_filter )
				&& false === \has_filter( 'gallery_style', $gallery_style_filter )
				&& false === \has_filter( 'wp_get_attachment_image_src', $image_source_filter )
				&& false === \has_filter( 'wp_get_attachment_image_attributes', $image_attributes_filter )
				&& false === \has_filter( 'wp_get_attachment_link_attributes', $link_attributes_filter )
				&& false === \has_filter( 'wp_get_attachment_url', $attachment_url_filter )
				&& false === \has_filter( 'post_playlist', $playlist_override_filter )
				&& false === \has_filter( 'shortcode_atts_playlist', $playlist_atts_filter )
				&& false === \has_action( 'wp_playlist_scripts', $playlist_scripts_action )
				&& false === \has_filter( 'wp_get_attachment_id3_keys', $id3_keys_filter )
				&& false === \has_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter )
				&& false === \has_filter( 'pre_wp_get_loading_optimization_attributes', $loading_filter );
		}

		self::collect_failure(
			$failures,
			is_string( $gallery_override )
				&& str_contains( $gallery_override, 'data-cfz-gallery-override="' . \esc_attr( $token ) . '"' )
				&& str_contains( $gallery_override, 'data-instance=' )
				&& $landscape_id . ',' . $portrait_id === ( $gallery_events['override'][0]['include'] ?? null )
				&& 'post__in' === ( $gallery_events['override'][0]['orderby'] ?? null ),
			'gallery shortcode ids set include/orderby before post_gallery override short-circuit',
			array(
				'override' => $gallery_override,
				'events'   => $gallery_events['override'],
			)
		);

		self::collect_failure(
			$failures,
			is_string( $legacy_gallery )
				&& str_contains( $legacy_gallery, "class='gallery galleryid-{$parent_id} gallery-columns-2 gallery-size-thumbnail'" )
				&& str_contains( $legacy_gallery, '<!--cfz-gallery-style-' . \esc_attr( $token ) . '-->' )
				&& 2 === substr_count( $legacy_gallery, "class='gallery-item'" )
				&& str_contains( $legacy_gallery, "<dl class='gallery-item'>" )
				&& str_contains( $legacy_gallery, "<dt class='gallery-icon landscape'>" )
				&& str_contains( $legacy_gallery, "<dt class='gallery-icon portrait'>" )
				&& str_contains( $legacy_gallery, 'aria-describedby=' )
				&& str_contains( $legacy_gallery, 'component-gallery-image-' . $token )
				&& str_contains( $legacy_gallery, 'data-cfz-gallery="image &lt;' . \esc_attr( $token ) . '&gt;"' )
				&& ! str_contains( $legacy_gallery, '<a ' )
				&& ! str_contains( $legacy_gallery, (string) $pdf_id )
				&& self::media_shortcode_markup_has_no_raw_payload( $legacy_gallery ),
			'gallery shortcode renders ordered image-only legacy markup with invalid tag fallback, captions, orientation, and filtered image attributes',
			array( 'gallery' => self::describe_string( is_string( $legacy_gallery ) ? $legacy_gallery : '' ) )
		);

		self::collect_failure(
			$failures,
			is_string( $html5_gallery )
				&& str_contains( $html5_gallery, "class='gallery galleryid-{$parent_id} gallery-columns-3 gallery-size-large'" )
				&& str_contains( $html5_gallery, "<figure class='gallery-item'>" )
				&& str_contains( $html5_gallery, "<figcaption class='wp-caption-text gallery-caption'" )
				&& str_contains( $html5_gallery, '<a href=' )
				&& str_contains( $html5_gallery, "data-cfz-link='gallery " . \esc_attr( $token ) . "'" )
				&& str_contains( $html5_gallery, '-cfz-' . rawurlencode( $token ) . '.jpg' )
				&& ! str_contains( $html5_gallery, '<style>' )
				&& self::media_shortcode_markup_has_no_raw_payload( $html5_gallery ),
			'gallery shortcode renders HTML5 file-link markup and honors gallery style/link filters without default style output',
			array( 'gallery' => self::describe_string( is_string( $html5_gallery ) ? $html5_gallery : '' ) )
		);

		self::collect_failure(
			$failures,
			'' === $private_gallery,
			'gallery shortcode parent selection fails closed for unreadable password-protected parents',
			array( 'privateGallery' => $private_gallery )
		);

		self::collect_failure(
			$failures,
			is_string( $playlist_override )
				&& str_contains( $playlist_override, 'data-cfz-playlist-override="' . \esc_attr( $token ) . '"' )
				&& $audio_id . ',' . $second_audio_id === ( $playlist_events['override'][0]['include'] ?? null )
				&& 'post__in' === ( $playlist_events['override'][0]['orderby'] ?? null ),
			'playlist shortcode ids set include/orderby before post_playlist override short-circuit',
			array(
				'override' => $playlist_override,
				'events'   => $playlist_events['override'],
			)
		);

		$audio_playlist_checks = array(
			'isString'         => is_string( $audio_playlist ),
			'hasAudioClass'    => is_string( $audio_playlist ) && str_contains( $audio_playlist, 'wp-audio-playlist' ),
			'hasDarkStyle'     => is_string( $audio_playlist ) && str_contains( $audio_playlist, 'wp-playlist-dark' ),
			'hasAudioTag'      => is_string( $audio_playlist ) && str_contains( $audio_playlist, '<audio controls="controls" preload="none" width="400"' ),
			'hasNoscript'      => is_string( $audio_playlist ) && str_contains( $audio_playlist, '<noscript>' ),
			'type'             => 'audio' === ( $audio_data['type'] ?? null ),
			'tracklistFalse'   => false === ( $audio_data['tracklist'] ?? null ),
			'tracknumbersTrue' => true === ( $audio_data['tracknumbers'] ?? null ),
			'imagesTrue'       => true === ( $audio_data['images'] ?? null ),
			'artistsFalse'     => false === ( $audio_data['artists'] ?? null ),
			'twoTracks'        => 2 === count( $audio_data['tracks'] ?? array() ),
			'filteredSrc'      => str_contains( $audio_data['tracks'][0]['src'] ?? '', '-cfz-' . rawurlencode( $token ) . '.mp3' ),
			'fileType'         => 'audio/mpeg' === ( $audio_data['tracks'][0]['type'] ?? null ),
			'artistMeta'       => 'Artist ' . $token === ( $audio_data['tracks'][0]['meta']['artist'] ?? null ),
			'composerMeta'     => 'Composer ' . $token === ( $audio_data['tracks'][0]['meta']['composer'] ?? null ),
			'thumbImage'       => isset( $audio_data['tracks'][0]['image']['src'], $audio_data['tracks'][0]['thumb']['src'] ),
			'mimeIcon'         => isset( $audio_data['tracks'][1]['image']['src'], $audio_data['tracks'][1]['thumb']['src'] ),
			'escapedPayloads'  => is_string( $audio_playlist ) && self::media_playlist_markup_has_no_raw_payload( $audio_playlist ),
		);
		self::collect_failure(
			$failures,
			! in_array( false, $audio_playlist_checks, true ),
			'audio playlist shortcode JSON/rendering',
			array(
				'checks'   => $audio_playlist_checks,
				'playlist' => self::describe_string( is_string( $audio_playlist ) ? $audio_playlist : '' ),
				'data'     => $audio_data,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $video_playlist )
				&& str_contains( $video_playlist, 'wp-video-playlist' )
				&& str_contains( $video_playlist, '<video controls="controls" preload="none" width="400"' )
				&& str_contains( $video_playlist, 'height="225"' )
				&& 'video' === ( $video_data['type'] ?? null )
				&& false === ( $video_data['images'] ?? null )
				&& 1 === count( $video_data['tracks'] ?? array() )
				&& 'video/mp4' === ( $video_data['tracks'][0]['type'] ?? null )
				&& 1280 === ( $video_data['tracks'][0]['dimensions']['original']['width'] ?? null )
				&& 720 === ( $video_data['tracks'][0]['dimensions']['original']['height'] ?? null )
				&& 400 === ( $video_data['tracks'][0]['dimensions']['resized']['width'] ?? null )
				&& 225 === ( $video_data['tracks'][0]['dimensions']['resized']['height'] ?? null )
				&& ! isset( $video_data['tracks'][0]['image'] )
				&& self::media_playlist_markup_has_no_raw_payload( $video_playlist ),
			'playlist shortcode coerces non-audio types to video and scales video dimensions against content width',
			array(
				'playlist' => self::describe_string( is_string( $video_playlist ) ? $video_playlist : '' ),
				'data'     => $video_data,
			)
		);

		self::collect_failure(
			$failures,
			'' === $private_playlist,
			'playlist shortcode parent selection fails closed for unreadable password-protected parents',
			array( 'privatePlaylist' => $private_playlist )
		);

		self::collect_failure(
			$failures,
			$has_query_event( 'image', array( $landscape_id, $portrait_id, $pdf_id ), null )
				&& $has_query_event( 'image', array( $portrait_id, $landscape_id ), null )
				&& $has_query_event( 'image', null, $private_parent_id )
				&& $has_query_event( 'audio', array( $audio_id, $second_audio_id ), null )
				&& $has_query_event( 'audio', null, $private_parent_id )
				&& $has_query_event( 'video', array( $video_id ), null ),
			'gallery and playlist shortcode attachment queries are mapped through posts_pre_query for include and parent selections',
			array( 'queries' => $query_events )
		);

		self::collect_failure(
			$failures,
			3 <= count( $gallery_events['atts'] )
				&& 2 <= count( $gallery_events['style'] )
				&& 4 <= count( $gallery_events['imageAttrs'] )
				&& 2 <= count( $gallery_events['links'] )
				&& 3 <= count( $playlist_events['atts'] )
				&& 3 <= count( $playlist_events['id3'] )
				&& count( $playlist_events['scripts'] ) <= 1
				&& $filters_removed
				&& $footer_template_before === $footer_template_after
				&& $admin_template_before === $admin_template_after
				&& self::media_shortcode_runtime_matches( $runtime_snapshot ),
			'gallery/playlist shortcode filters, playlist script hooks, media globals, and template actions are restored',
			array(
				'galleryEvents'  => $gallery_events,
				'playlistEvents' => $playlist_events,
				'imageSources'   => array_slice( $image_source_events, 0, 12 ),
				'attachmentUrls' => array_slice( $attachment_url_events, 0, 12 ),
				'filtersRemoved' => $filters_removed,
				'footerBefore'   => $footer_template_before,
				'footerAfter'    => $footer_template_after,
				'adminBefore'    => $admin_template_before,
				'adminAfter'     => $admin_template_after,
			)
		);

		return self::row(
			$ctx,
			'media-metadata.gallery-playlist-shortcode-rendering-filters',
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

	private static function check_generated_metadata_replacement_oracles( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures       = array();
		$generated      = array();
		$events         = array(
			'get'    => array(),
			'update' => array(),
			'url'    => array(),
		);
		$upload_root    = $temp_root . DIRECTORY_SEPARATOR . 'metadata-shape-uploads';
		$upload_url     = 'http://example.test/component-fuzz-media-shapes-' . $ctx->int( 1000, 9999 );
		$attachment_ids = array();

		$upload_filter = static function ( array $uploads ) use ( $upload_root, $upload_url ): array {
			$uploads['basedir'] = $upload_root;
			$uploads['baseurl'] = $upload_url;
			$uploads['path']    = $upload_root;
			$uploads['url']     = $upload_url;
			$uploads['subdir']  = '';
			$uploads['error']   = false;
			return $uploads;
		};
		$update_filter = static function ( array $data, int $post_id ) use ( &$events, &$attachment_ids ): array {
			if ( ! in_array( $post_id, $attachment_ids, true ) ) {
				return $data;
			}

			$events['update'][] = array(
				'id'     => $post_id,
				'keys'   => array_keys( $data ),
				'label'  => $data['component_fuzz_generation'] ?? null,
				'sizes'  => isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? array_keys( $data['sizes'] ) : array(),
			);

			return self::metadata_with_update_marker( $data, $post_id );
		};
		$get_filter    = static function ( $data, int $post_id ) use ( &$events, &$attachment_ids ) {
			if ( ! in_array( $post_id, $attachment_ids, true ) ) {
				return $data;
			}

			$events['get'][] = array(
				'id'    => $post_id,
				'type'  => gettype( $data ),
				'label' => is_array( $data ) ? ( $data['component_fuzz_generation'] ?? null ) : null,
			);

			if ( is_array( $data ) ) {
				$data['component_fuzz_get_filter'] = $post_id;
			}

			return $data;
		};
		$url_filter    = static function ( string $url, int $attachment_id ) use ( &$events, &$attachment_ids ): string {
			if ( in_array( $attachment_id, $attachment_ids, true ) ) {
				$events['url'][] = array(
					'id'  => $attachment_id,
					'url' => $url,
				);
			}

			return $url;
		};

		\ComponentFuzz\ensure_dir( $upload_root );

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_update_attachment_metadata', $update_filter, 10, 2 );
		\add_filter( 'wp_get_attachment_metadata', $get_filter, 10, 2 );
		\add_filter( 'wp_get_attachment_url', $url_filter, 10, 2 );
		try {
			foreach ( self::generated_metadata_cases( $ctx, $temp_root, $upload_root, $upload_url ) as $index => $case ) {
				$case_ctx      = $ctx->fork( 'shape-case-' . $index );
				$attachment_id = 875000 + ( $ctx->iteration() * 10 ) + $index;
				$path          = self::write_fixture( $case['fixtureDir'], $case['filename'], self::media_fixture_bytes( $case_ctx, $case['kind'] ) );

				if ( null === $path ) {
					self::collect_failure( $failures, false, 'generated media metadata fixture is writable', $case );
					continue;
				}

				$attachment_ids[] = $attachment_id;
				self::seed_attachment_post( $attachment_id, $case['mime'], $case['attachedFile'] );
				\wp_cache_delete( $attachment_id, 'post_meta' );
				$attached_file_stored = \update_post_meta( $attachment_id, '_wp_attached_file', $case['attachedFile'] );

				$first_metadata  = self::generated_attachment_metadata( $case_ctx->fork( 'first' ), $case['kind'], $case['relativeFile'], $case['mime'], filesize( $path ), 'first' );
				$second_metadata = self::generated_attachment_metadata( $case_ctx->fork( 'second' ), $case['kind'], $case['relativeFile'], $case['mime'], filesize( $path ), 'second' );
				$expected_first  = self::metadata_with_update_marker( $first_metadata, $attachment_id );
				$expected_second = self::metadata_with_update_marker( $second_metadata, $attachment_id );

				$first_updated         = \wp_update_attachment_metadata( $attachment_id, $first_metadata );
				$first_unfiltered      = \wp_get_attachment_metadata( $attachment_id, true );
				$first_filtered        = \wp_get_attachment_metadata( $attachment_id );
				$second_updated        = \wp_update_attachment_metadata( $attachment_id, $second_metadata );
				$second_unfiltered     = \wp_get_attachment_metadata( $attachment_id, true );
				$second_filtered       = \wp_get_attachment_metadata( $attachment_id );
				$same_update           = \wp_update_attachment_metadata( $attachment_id, $second_metadata );
				$after_same_unfiltered = \wp_get_attachment_metadata( $attachment_id, true );
				$attached_unfiltered   = \get_attached_file( $attachment_id, true );
				$attachment_url        = \wp_get_attachment_url( $attachment_id );
				$type_checks           = array(
					'kind'      => \wp_attachment_is( $case['kind'], $attachment_id ),
					'extension' => \wp_attachment_is( $case['extension'], $attachment_id ),
					'image'     => \wp_attachment_is_image( $attachment_id ),
				);

				$generated[] = array(
					'id'                 => $attachment_id,
					'kind'               => $case['kind'],
					'storage'            => $case['storage'],
					'attachedStored'     => $attached_file_stored,
					'firstUpdated'       => $first_updated,
					'secondUpdated'      => $second_updated,
					'sameUpdate'         => $same_update,
					'attached'           => $attached_unfiltered,
					'url'                => $attachment_url,
					'typeChecks'         => $type_checks,
					'expectedAttached'   => $case['expectedAttached'],
					'expectedUrl'        => $case['expectedUrl'],
					'firstKeys'          => is_array( $first_unfiltered ) ? array_keys( $first_unfiltered ) : array(),
					'secondKeys'         => is_array( $second_unfiltered ) ? array_keys( $second_unfiltered ) : array(),
					'secondFilteredKeys' => is_array( $second_filtered ) ? array_keys( $second_filtered ) : array(),
				);

				self::collect_failure(
					$failures,
					false !== $attached_file_stored
						&& false !== $first_updated
						&& false !== $second_updated
						&& false === $same_update
						&& $expected_first === $first_unfiltered
						&& $expected_second === $second_unfiltered
						&& $expected_second === $after_same_unfiltered
						&& is_array( $first_filtered )
						&& is_array( $second_filtered )
						&& $attachment_id === ( $first_filtered['component_fuzz_get_filter'] ?? null )
						&& $attachment_id === ( $second_filtered['component_fuzz_get_filter'] ?? null )
						&& ! isset( $second_unfiltered['component_fuzz_get_filter'] )
						&& ! array_key_exists( 'component_fuzz_stale_top', $second_unfiltered )
						&& ! (
							isset( $second_unfiltered['image_meta'] )
							&& is_array( $second_unfiltered['image_meta'] )
							&& array_key_exists( 'component_fuzz_stale_nested', $second_unfiltered['image_meta'] )
						)
						&& ! array_intersect( array_keys( $first_metadata['sizes'] ?? array() ), array_keys( $second_unfiltered['sizes'] ?? array() ) )
						&& self::serializable_array_ok( $second_unfiltered )
						&& $case['expectedAttached'] === $attached_unfiltered
						&& $case['expectedUrl'] === $attachment_url
						&& true === $type_checks['kind']
						&& true === $type_checks['extension']
						&& ( ( 'image' === $case['kind'] ) === $type_checks['image'] ),
					'generated attachment metadata updates replace old shape, remain serializable, normalize file/URL paths, and preserve MIME/ext helpers',
					array(
						'case'             => end( $generated ),
						'firstUnfiltered'  => $first_unfiltered,
						'secondUnfiltered' => $second_unfiltered,
						'secondFiltered'   => $second_filtered,
					)
				);
			}
		} finally {
			\remove_filter( 'wp_get_attachment_url', $url_filter, 10 );
			\remove_filter( 'wp_get_attachment_metadata', $get_filter, 10 );
			\remove_filter( 'wp_update_attachment_metadata', $update_filter, 10 );
			\remove_filter( 'upload_dir', $upload_filter );
		}

		self::collect_failure(
			$failures,
			9 === count( $events['update'] )
				&& 6 === count( $events['get'] )
				&& 3 === count( $events['url'] )
				&& false === \has_filter( 'upload_dir', $upload_filter )
				&& false === \has_filter( 'wp_update_attachment_metadata', $update_filter )
				&& false === \has_filter( 'wp_get_attachment_metadata', $get_filter )
				&& false === \has_filter( 'wp_get_attachment_url', $url_filter ),
			'generated metadata shape filters fire for scoped reads/updates/URLs and are restored',
			array(
				'events'          => $events,
				'uploadHasFilter' => \has_filter( 'upload_dir', $upload_filter ),
				'updateHasFilter' => \has_filter( 'wp_update_attachment_metadata', $update_filter ),
				'getHasFilter'    => \has_filter( 'wp_get_attachment_metadata', $get_filter ),
				'urlHasFilter'    => \has_filter( 'wp_get_attachment_url', $url_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-metadata.generated-metadata-replacement-and-url-oracles',
			array() === $failures,
			array(
				'cases'    => $generated,
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
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

	private static function check_generate_attachment_cover_creation_reuse( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures    = array();
		$events      = array(
			'generated'     => array(),
			'handleUpload'  => array(),
			'read'          => array(),
			'thumbnailArgs' => array(),
			'uploadBits'    => array(),
			'uploadDirs'    => array(),
		);
		$fixture_dir = $temp_root . DIRECTORY_SEPARATOR . 'cover-fixtures';
		$audio_path  = self::write_fixture( $fixture_dir, 'cover-audio.mp3', "ID3\x04\x00\x00\x00\x00\x00\x00" . $ctx->bytes( 8, 32 ) );
		$video_path  = self::write_fixture( $fixture_dir, 'cover-video.mp4', self::mp4_like_bytes( $ctx ) . $ctx->bytes( 4, 16 ) );
		$empty_path  = self::write_fixture( $fixture_dir, 'empty-cover-audio.mp3', "ID3\x04\x00\x00\x00\x00\x00\x00" . $ctx->bytes( 4, 12 ) );

		if ( null === $audio_path || null === $video_path || null === $empty_path ) {
			return $ctx->result(
				'media-metadata.generate-attachment-cover-creation-reuse',
				false,
				array( 'failures' => array( array( 'message' => 'cover attachment fixtures are writable' ) ) )
			);
		}

		$audio_id = 873000 + ( $ctx->iteration() * 10 );
		$video_id = 873001 + ( $ctx->iteration() * 10 );
		$empty_id = 873002 + ( $ctx->iteration() * 10 );

		self::seed_attachment_post( $audio_id, 'audio/mpeg', $audio_path );
		self::seed_attachment_post( $video_id, 'video/mp4', $video_path );
		self::seed_attachment_post( $empty_id, 'audio/mpeg', $empty_path );

		$cover_bytes       = self::tiny_png_bytes();
		$cover_hash        = md5( $cover_bytes );
		$cover_sha1        = sha1( $cover_bytes );
		$cover_title       = 'Component Fuzz Cover ' . $ctx->identifier( 4, 10 );
		$upload_root       = $temp_root . DIRECTORY_SEPARATOR . 'cover-uploads';
		$audio_support     = \post_type_supports( 'attachment:audio', 'thumbnail' );
		$video_support     = \post_type_supports( 'attachment:video', 'thumbnail' );
		$before            = self::content_counts();
		$cover_id          = 0;
		$audio_meta        = null;
		$video_meta        = null;
		$empty_meta        = null;
		$after_audio       = array();
		$after_video       = array();
		$after_empty       = array();

		$upload_dir_filter = static function ( array $uploads ) use ( $upload_root, &$events ): array {
			$uploads['basedir'] = $upload_root;
			$uploads['baseurl'] = 'http://example.test/component-fuzz-media-cover';
			$uploads['path']    = $upload_root;
			$uploads['url']     = $uploads['baseurl'];
			$uploads['subdir']  = '';
			$uploads['error']   = false;
			$events['uploadDirs'][] = $uploads['path'];
			return $uploads;
		};
		$upload_bits_filter = static function ( $payload ) use ( &$events ) {
			if ( is_array( $payload ) ) {
				$events['uploadBits'][] = array(
					'name'  => (string) ( $payload['name'] ?? '' ),
					'bytes' => strlen( (string) ( $payload['bits'] ?? '' ) ),
					'sha1'  => sha1( (string) ( $payload['bits'] ?? '' ) ),
				);
			}
			return $payload;
		};
		$handle_upload_filter = static function ( array $upload, string $context ) use ( &$events ): array {
			$events['handleUpload'][] = array(
				'context' => $context,
				'file'    => basename( (string) ( $upload['file'] ?? '' ) ),
				'type'    => $upload['type'] ?? null,
				'error'   => $upload['error'] ?? null,
			);
			return $upload;
		};
		$thumbnail_args_filter = static function ( array $image_attachment, array $metadata, array $uploaded ) use ( &$events, $cover_title ): array {
			$events['thumbnailArgs'][] = array(
				'mime'     => $image_attachment['post_mime_type'] ?? null,
				'file'     => basename( (string) ( $uploaded['file'] ?? '' ) ),
				'hasImage' => isset( $metadata['image']['mime'] ),
			);
			$image_attachment['post_title']  = $cover_title;
			$image_attachment['post_status'] = 'inherit';
			return $image_attachment;
		};
		$audio_filter = static function ( array $metadata, string $file, ?string $file_format, array $data ) use ( &$events, $audio_path, $empty_path, $cover_bytes ): array {
			unset( $data );
			$events['read'][] = array(
				'type'   => 'audio',
				'file'   => basename( $file ),
				'format' => $file_format,
			);
			$metadata['component_fuzz_cover_branch'] = basename( $file ) === basename( $empty_path ) ? 'empty-audio' : 'audio';
			$metadata['length']                      = 43;
			$metadata['fileformat']                  = 'mp3';
			$metadata['image']                       = array(
				'mime'   => 'image/png',
				'width'  => 1,
				'height' => 1,
			);

			if ( basename( $file ) === basename( $audio_path ) ) {
				$metadata['image']['data'] = $cover_bytes;
			}

			return $metadata;
		};
		$video_filter = static function ( array $metadata, string $file, ?string $file_format, array $data ) use ( &$events, $cover_bytes ): array {
			unset( $data );
			$events['read'][] = array(
				'type'   => 'video',
				'file'   => basename( $file ),
				'format' => $file_format,
			);
			$metadata['component_fuzz_cover_branch'] = 'video';
			$metadata['width']                       = 640;
			$metadata['height']                      = 360;
			$metadata['fileformat']                  = 'mp4';
			$metadata['image']                       = array(
				'data'   => $cover_bytes,
				'mime'   => 'image/png',
				'width'  => 1,
				'height' => 1,
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

		\add_post_type_support( 'attachment:audio', 'thumbnail' );
		\add_post_type_support( 'attachment:video', 'thumbnail' );
		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'wp_upload_bits', $upload_bits_filter );
		\add_filter( 'wp_handle_upload', $handle_upload_filter, 10, 2 );
		\add_filter( 'attachment_thumbnail_args', $thumbnail_args_filter, 10, 3 );
		\add_filter( 'wp_read_audio_metadata', $audio_filter, 10, 4 );
		\add_filter( 'wp_read_video_metadata', $video_filter, 10, 4 );
		\add_filter( 'wp_generate_attachment_metadata', $generate_filter, 10, 3 );
		try {
			$audio_meta  = \wp_generate_attachment_metadata( $audio_id, $audio_path );
			$cover_id    = (int) \get_post_meta( $audio_id, '_thumbnail_id', true );
			$after_audio = self::content_counts();
			$video_meta  = \wp_generate_attachment_metadata( $video_id, $video_path );
			$after_video = self::content_counts();
			$empty_meta  = \wp_generate_attachment_metadata( $empty_id, $empty_path );
			$after_empty = self::content_counts();
		} finally {
			\remove_filter( 'wp_generate_attachment_metadata', $generate_filter, 10 );
			\remove_filter( 'wp_read_video_metadata', $video_filter, 10 );
			\remove_filter( 'wp_read_audio_metadata', $audio_filter, 10 );
			\remove_filter( 'attachment_thumbnail_args', $thumbnail_args_filter, 10 );
			\remove_filter( 'wp_handle_upload', $handle_upload_filter, 10 );
			\remove_filter( 'wp_upload_bits', $upload_bits_filter );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			if ( ! $audio_support ) {
				\remove_post_type_support( 'attachment:audio', 'thumbnail' );
			}
			if ( ! $video_support ) {
				\remove_post_type_support( 'attachment:video', 'thumbnail' );
			}
		}

		$cover_post           = $cover_id > 0 ? \get_post( $cover_id ) : null;
		$cover_file           = $cover_id > 0 ? \get_attached_file( $cover_id, true ) : '';
		$cover_hash_meta      = $cover_id > 0 ? \get_post_meta( $cover_id, '_cover_hash', true ) : '';
		$audio_thumbnail      = (int) \get_post_meta( $audio_id, '_thumbnail_id', true );
		$video_thumbnail      = (int) \get_post_meta( $video_id, '_thumbnail_id', true );
		$empty_thumbnail      = \get_post_meta( $empty_id, '_thumbnail_id', true );
		$generated_ids        = array_map( 'intval', array_column( $events['generated'], 'id' ) );

		self::collect_failure(
			$failures,
			is_array( $audio_meta )
				&& is_array( $video_meta )
				&& is_array( $empty_meta )
				&& 'audio' === ( $audio_meta['component_fuzz_cover_branch'] ?? null )
				&& 'video' === ( $video_meta['component_fuzz_cover_branch'] ?? null )
				&& 'empty-audio' === ( $empty_meta['component_fuzz_cover_branch'] ?? null )
				&& ! isset( $audio_meta['image']['data'], $video_meta['image']['data'], $empty_meta['image']['data'] )
				&& 'image/png' === ( $audio_meta['image']['mime'] ?? null )
				&& 'image/png' === ( $video_meta['image']['mime'] ?? null )
				&& 'image/png' === ( $empty_meta['image']['mime'] ?? null ),
			'audio/video metadata generation strips cover binary data while preserving bounded image metadata',
			array(
				'audioMeta' => $audio_meta,
				'videoMeta' => $video_meta,
				'emptyMeta' => $empty_meta,
			)
		);

		self::collect_failure(
			$failures,
			$cover_post instanceof \WP_Post
				&& 'attachment' === $cover_post->post_type
				&& 'inherit' === $cover_post->post_status
				&& 'image/png' === $cover_post->post_mime_type
				&& $cover_title === $cover_post->post_title
				&& $cover_hash === $cover_hash_meta
				&& 1 === count( $events['uploadBits'] )
				&& $cover_sha1 === ( $events['uploadBits'][0]['sha1'] ?? null )
				&& strlen( $cover_bytes ) === ( $events['uploadBits'][0]['bytes'] ?? null )
				&& $audio_thumbnail > 0
				&& $audio_thumbnail === $cover_id,
			'audio cover metadata creates one child image attachment with hash, uploaded bytes, and parent thumbnail metadata',
			array(
				'coverId'        => $cover_id,
				'coverPost'      => $cover_post instanceof \WP_Post ? array(
					'ID'             => $cover_post->ID,
					'post_type'      => $cover_post->post_type,
					'post_status'    => $cover_post->post_status,
					'post_mime_type' => $cover_post->post_mime_type,
					'post_title'     => $cover_post->post_title,
				) : $cover_post,
				'coverFile'      => $cover_file,
				'coverHash'      => $cover_hash_meta,
				'uploadBits'     => $events['uploadBits'],
				'audioThumbnail' => $audio_thumbnail,
			)
		);

		self::collect_failure(
			$failures,
			$video_thumbnail === $cover_id
				&& '' === (string) $empty_thumbnail
				&& ( $after_audio['posts'] ?? null ) === ( $before['posts'] ?? 0 ) + 1
				&& ( $after_video['posts'] ?? null ) === ( $after_audio['posts'] ?? null )
				&& ( $after_empty['posts'] ?? null ) === ( $after_video['posts'] ?? null )
				&& 1 === count( $events['handleUpload'] )
				&& in_array( $audio_id, $generated_ids, true )
				&& in_array( $video_id, $generated_ids, true )
				&& in_array( $empty_id, $generated_ids, true )
				&& in_array( $cover_id, $generated_ids, true ),
			'cover hash lookup reuses an existing cover for matching video art and skips mutation for metadata without cover data',
			array(
				'before'         => $before,
				'afterAudio'     => $after_audio,
				'afterVideo'     => $after_video,
				'afterEmpty'     => $after_empty,
				'events'         => $events,
				'audioThumbnail' => $audio_thumbnail,
				'videoThumbnail' => $video_thumbnail,
				'emptyThumbnail' => $empty_thumbnail,
				'coverId'        => $cover_id,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'upload_dir', $upload_dir_filter )
				&& false === \has_filter( 'wp_upload_bits', $upload_bits_filter )
				&& false === \has_filter( 'wp_handle_upload', $handle_upload_filter )
				&& false === \has_filter( 'attachment_thumbnail_args', $thumbnail_args_filter )
				&& false === \has_filter( 'wp_read_audio_metadata', $audio_filter )
				&& false === \has_filter( 'wp_read_video_metadata', $video_filter )
				&& false === \has_filter( 'wp_generate_attachment_metadata', $generate_filter )
				&& \post_type_supports( 'attachment:audio', 'thumbnail' ) === $audio_support
				&& \post_type_supports( 'attachment:video', 'thumbnail' ) === $video_support,
			'cover generation filters and post type support flags are restored',
			array(
				'uploadDir'      => \has_filter( 'upload_dir', $upload_dir_filter ),
				'uploadBits'     => \has_filter( 'wp_upload_bits', $upload_bits_filter ),
				'handleUpload'   => \has_filter( 'wp_handle_upload', $handle_upload_filter ),
				'thumbnailArgs'  => \has_filter( 'attachment_thumbnail_args', $thumbnail_args_filter ),
				'audioRead'      => \has_filter( 'wp_read_audio_metadata', $audio_filter ),
				'videoRead'      => \has_filter( 'wp_read_video_metadata', $video_filter ),
				'generated'      => \has_filter( 'wp_generate_attachment_metadata', $generate_filter ),
				'audioSupport'   => \post_type_supports( 'attachment:audio', 'thumbnail' ),
				'videoSupport'   => \post_type_supports( 'attachment:video', 'thumbnail' ),
			)
		);

		return self::row(
			$ctx,
			'media-metadata.generate-attachment-cover-creation-reuse',
			array() === $failures,
			array(
				'coverId'  => $cover_id,
				'failures' => array_slice( $failures, 0, 8 ),
			)
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

	private static function media_fixture_bytes( \ComponentFuzz\FuzzContext $ctx, string $kind ): string {
		if ( 'audio' === $kind ) {
			return "ID3\x04\x00\x00\x00\x00\x00\x00" . $ctx->bytes( 8, 24 );
		}

		if ( 'video' === $kind ) {
			return self::mp4_like_bytes( $ctx ) . $ctx->bytes( 4, 16 );
		}

		return "\xFF\xD8\xFF\xE0" . $ctx->bytes( 8, 24 );
	}

	private static function tiny_png_bytes(): string {
		$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true );
		return is_string( $bytes ) ? $bytes : 'component-fuzz-cover';
	}

	private static function generated_metadata_cases( \ComponentFuzz\FuzzContext $ctx, string $temp_root, string $upload_root, string $upload_url ): array {
		$cases = array();
		foreach (
			array(
				array(
					'kind'      => 'image',
					'extension' => 'jpg',
					'mime'      => 'image/jpeg',
					'storage'   => 'relative',
				),
				array(
					'kind'      => 'audio',
					'extension' => 'mp3',
					'mime'      => 'audio/mpeg',
					'storage'   => 'absolute',
				),
				array(
					'kind'      => 'video',
					'extension' => 'mp4',
					'mime'      => 'video/mp4',
					'storage'   => 'legacy',
				),
			) as $index => $base
		) {
			$case_ctx      = $ctx->fork( 'case-' . $index );
			$year          = (string) $case_ctx->int( 2021, 2026 );
			$month         = str_pad( (string) $case_ctx->int( 1, 12 ), 2, '0', STR_PAD_LEFT );
			$subdir        = $year . '/' . $month;
			$slug          = strtolower( str_replace( array( ':', '_' ), '-', $case_ctx->identifier( 5, 12 ) ) );
			$filename      = $slug . '-' . $case_ctx->int( 10, 99 ) . '.' . $base['extension'];
			$relative_file = $subdir . '/' . $filename;

			if ( 'legacy' === $base['storage'] ) {
				$fixture_dir       = $temp_root . '/legacy/wp-content/uploads/' . $subdir;
				$attached_file     = $fixture_dir . '/' . $filename;
				$expected_attached = $attached_file;
			} else {
				$fixture_dir       = $upload_root . '/' . $subdir;
				$attached_file     = 'relative' === $base['storage'] ? $relative_file : $fixture_dir . '/' . $filename;
				$expected_attached = 'relative' === $base['storage'] ? $upload_root . '/' . $relative_file : $attached_file;
			}

			$cases[] = array_merge(
				$base,
				array(
					'fixtureDir'       => $fixture_dir,
					'filename'         => $filename,
					'relativeFile'     => $relative_file,
					'attachedFile'     => $attached_file,
					'expectedAttached' => $expected_attached,
					'expectedUrl'      => $upload_url . '/' . $relative_file,
				)
			);
		}

		return $cases;
	}

	private static function generated_attachment_metadata( \ComponentFuzz\FuzzContext $ctx, string $kind, string $relative_file, string $mime, int $filesize, string $label ): array {
		$width     = $ctx->int( 320, 2400 );
		$height    = $ctx->int( 240, 1800 );
		$size_slug = 'component-fuzz-' . $label . '-' . strtolower( str_replace( array( ':', '_' ), '-', $ctx->identifier( 3, 8 ) ) );
		$size_file = preg_replace( '/(\.[^.]+)$/', '-' . max( 1, (int) floor( $width / 2 ) ) . 'x' . max( 1, (int) floor( $height / 2 ) ) . '$1', basename( $relative_file ) );
		$metadata  = array(
			'file'                      => $relative_file,
			'filesize'                  => $filesize,
			'mime_type'                 => $mime,
			'component_fuzz_generation' => $label,
			'component_fuzz_nested'     => array(
				'chapters' => array(
					array(
						'title' => 'chapter-' . $ctx->identifier( 3, 8 ),
						'start' => $ctx->int( 0, 120 ),
					),
				),
				'flags'    => array(
					'lossless' => $ctx->bool(),
					'label'    => $label,
				),
			),
		);

		if ( 'first' === $label ) {
			$metadata['component_fuzz_stale_top'] = 'removed-by-replacement';
		}

		if ( 'image' === $kind ) {
			$metadata['width']      = $width;
			$metadata['height']     = $height;
			$metadata['sizes']      = array(
				$size_slug => array(
					'file'      => $size_file,
					'width'     => max( 1, (int) floor( $width / 2 ) ),
					'height'    => max( 1, (int) floor( $height / 2 ) ),
					'mime-type' => $mime,
				),
			);
			$metadata['image_meta'] = array(
				'aperture'                     => '0',
				'credit'                       => 'Component Fuzz ' . $label,
				'created_timestamp'            => $ctx->int( 1000000000, 1999999999 ),
				'orientation'                  => $ctx->choice( array( 1, 3, 6, 8 ) ),
				'keywords'                     => array( 'media', $ctx->identifier( 3, 8 ) ),
			);

			if ( 'first' === $label ) {
				$metadata['image_meta']['component_fuzz_stale_nested'] = 'removed-by-replacement';
			}

			return $metadata;
		}

		$metadata['length']           = $ctx->int( 1, 7200 );
		$metadata['length_formatted'] = gmdate( 'i:s', $metadata['length'] );
		$metadata['bitrate']          = $ctx->int( 32000, 320000 );

		if ( 'audio' === $kind ) {
			$metadata['fileformat']   = 'mp3';
			$metadata['bitrate_mode'] = $ctx->choice( array( 'cbr', 'vbr' ) );
			$metadata['artist']       = 'Component Artist ' . $label;
			$metadata['album']        = 'Component Album ' . $ctx->identifier( 3, 8 );
			$metadata['image']        = array(
				'mime'   => 'image/jpeg',
				'width'  => $ctx->int( 1, 10 ),
				'height' => $ctx->int( 1, 10 ),
			);

			return $metadata;
		}

		$metadata['width']      = $width;
		$metadata['height']     = $height;
		$metadata['fileformat'] = 'mp4';
		$metadata['dataformat'] = 'quicktime';
		$metadata['codec']      = $ctx->choice( array( 'h264', 'hevc', 'mpeg4' ) );
		$metadata['audio']      = array(
			'dataformat' => 'mp4',
			'codec'      => $ctx->choice( array( 'aac', 'alac' ) ),
			'channels'   => $ctx->choice( array( 1, 2 ) ),
		);

		return $metadata;
	}

	private static function metadata_with_update_marker( array $metadata, int $attachment_id ): array {
		$metadata['component_fuzz_update_filter'] = array(
			'attachment' => $attachment_id,
			'key_count'  => count( $metadata ),
		);

		if ( isset( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ) {
			$metadata['image_meta']['component_fuzz_filtered'] = $attachment_id;
		}

		return $metadata;
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

	private static function seed_post_cache( int $post_id, array $overrides = array() ): \WP_Post {
		$data = array_merge(
			array(
				'ID'                    => $post_id,
				'post_author'           => '0',
				'post_date'             => '2026-06-23 00:00:00',
				'post_date_gmt'         => '2026-06-23 00:00:00',
				'post_content'          => '',
				'post_title'            => 'Component Fuzz Post ' . $post_id,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'component-fuzz-post-' . $post_id,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 00:00:00',
				'post_modified_gmt'     => '2026-06-23 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://example.test/?p=' . $post_id,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			),
			$overrides
		);
		$data['ID'] = $post_id;

		$post = new \WP_Post( (object) $data );
		\wp_cache_set( $post_id, $post, 'posts' );
		return $post;
	}

	private static function seed_attachment_post( int $attachment_id, string $mime, string $file, array $post_overrides = array(), array $meta_overrides = array() ): void {
		\wp_cache_set( $attachment_id, self::attachment_post_object( $attachment_id, $mime, basename( $file ), $post_overrides ), 'posts' );

		$meta = array(
			'_wp_attached_file' => array( $file ),
		);
		foreach ( $meta_overrides as $key => $value ) {
			$meta[ $key ] = array( $value );
		}

		\wp_cache_set( $attachment_id, $meta, 'post_meta' );
	}

	private static function attachment_post_object( int $attachment_id, string $mime, string $title, array $overrides = array() ): \WP_Post {
		$data = array_merge(
			array(
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
			),
			$overrides
		);
		$data['ID']             = $attachment_id;
		$data['post_type']      = 'attachment';
		$data['post_mime_type'] = $mime;
		if ( ! isset( $overrides['post_name'] ) && isset( $overrides['post_title'] ) ) {
			$data['post_name'] = \sanitize_file_name( (string) $data['post_title'] );
		}

		return new \WP_Post(
			(object) $data
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

	private static function media_shortcode_url( \ComponentFuzz\FuzzContext $ctx, string $label, string $extension ): string {
		$safe_label = \sanitize_file_name( $label . '-' . $ctx->identifier( 4, 10 ) );
		$payload    = rawurlencode( 'quote"<script>&' . $ctx->identifier( 3, 8 ) );

		return "https://media.example.test/{$payload}/{$safe_label}.{$extension}";
	}

	private static function media_shortcode_markup_has_no_raw_payload( string $html ): bool {
		$lower = strtolower( $html );

		return ! str_contains( $lower, '<script' )
			&& ! str_contains( $lower, 'bad=<script>' )
			&& ! str_contains( $lower, 'danger=<script>' )
			&& ! str_contains( $html, '"<&' )
			&& ! str_contains( $html, 'quote"<script>' );
	}

	private static function media_playlist_json_data( string $html ): array {
		if ( 1 !== preg_match( '/<script type="application\/json" class="wp-playlist-script">(.*?)<\/script>/s', $html, $matches ) ) {
			return array();
		}

		$data = json_decode( $matches[1], true );
		return is_array( $data ) ? $data : array();
	}

	private static function media_playlist_markup_has_no_raw_payload( string $html ): bool {
		$without_playlist_json = preg_replace( '/<script type="application\/json" class="wp-playlist-script">.*?<\/script>/s', '', $html );
		if ( ! is_string( $without_playlist_json ) ) {
			return false;
		}

		return self::media_shortcode_markup_has_no_raw_payload( $without_playlist_json )
			&& ! str_contains( strtolower( $html ), '<script>alert' )
			&& ! str_contains( $html, 'Audio description <tag>' )
			&& ! str_contains( $html, 'Video description <tag>' );
	}

	private static function snapshot_media_shortcode_runtime(): array {
		$globals = array();
		foreach ( array( 'wp_scripts', 'wp_styles', 'content_width' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_runtime_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array( 'globals' => $globals );
	}

	private static function restore_media_shortcode_runtime( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_runtime_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function media_shortcode_runtime_matches( array $snapshot ): bool {
		return self::snapshot_media_shortcode_runtime() == $snapshot;
	}

	private static function clone_runtime_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_runtime_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		$preview = str_replace(
			array( "\r", "\n", "\t" ),
			array( '\\r', '\\n', '\\t' ),
			substr( $value, 0, self::PREVIEW_BYTES )
		);

		return array(
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => $preview,
		);
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
