<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes image editor, generated sub-size, and attachment metadata helpers.
 */
final class MediaEditorSurface {
	public const NAME = 'media-editor';

	private const PREVIEW_BYTES = 180;
	private const REAL_EDITORS  = array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' );
	private const MIME_EXT      = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'media-editor.bootstrap-apis-available',
					'Required WordPress media editor APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot     = self::snapshot_globals();
		$temp_root    = self::make_temp_root( $ctx );
		$cleanup_ok   = null === $temp_root;
		$cleanup_path = $temp_root;
		$rows         = array();

		try {
			$rows[] = self::check_output_format_filters( $ctx->fork( 'output-format' ) );
			$rows[] = self::check_editor_selection_filters( $ctx->fork( 'editor-selection' ) );
			$rows[] = self::check_attachment_metadata_helpers( $ctx->fork( 'attachment-helpers' ), $temp_root );
			$rows[] = self::check_abstract_editor_contracts( $ctx->fork( 'abstract-editor' ), $temp_root );

			if ( null === $temp_root ) {
				$rows[] = $ctx->skip(
					'media-editor.temp-root.available',
					'Could not create an isolated temporary directory.',
					array( 'sysTempDir' => sys_get_temp_dir() )
				);
			} else {
				foreach ( self::check_file_editor_paths( $ctx->fork( 'file-editors' ), $temp_root ) as $row ) {
					$rows[] = $row;
				}
			}
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'media-editor.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
				$cleanup_ok = ! is_dir( $temp_root );
			}
			self::clear_editor_cache();
			self::restore_globals( $snapshot );
		}

		$rows[] = self::row(
			$ctx,
			'media-editor.cleanup.temp-files-and-globals',
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
				'get_attached_file',
				'image_make_intermediate_size',
				'image_resize_dimensions',
				'sanitize_file_name',
				'wp_create_image_subsizes',
				'wp_generate_attachment_metadata',
				'wp_get_attachment_metadata',
				'wp_get_image_editor',
				'wp_get_image_editor_output_format',
				'wp_get_missing_image_subsizes',
				'wp_get_registered_image_subsizes',
				'wp_getimagesize',
				'wp_image_editor_supports',
				'wp_update_attachment_metadata',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'WP_Image_Editor', 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_output_format_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$seen     = array();
		$filter   = static function ( array $formats, string $filename, string $mime_type ) use ( &$seen ): array {
			$seen[] = array(
				'filename' => $filename,
				'mime'     => $mime_type,
			);

			if ( 'image/jpeg' === $mime_type ) {
				$formats['image/jpeg'] = 'image/webp';
			}

			if ( 'image/png' === $mime_type ) {
				$formats['image/png'] = 'image/jpeg';
			}

			return $formats;
		};

		$default_heic = \wp_get_image_editor_output_format( '/tmp/component-fuzz.heic', 'image/heic' );
		$default_png  = \wp_get_image_editor_output_format( '/tmp/component-fuzz.png', 'image/png' );

		\add_filter( 'image_editor_output_format', $filter, 10, 3 );
		try {
			$jpeg = \wp_get_image_editor_output_format( '/tmp/component-fuzz.jpg', 'image/jpeg' );
			$png  = \wp_get_image_editor_output_format( '/tmp/component-fuzz.png', 'image/png' );
		} finally {
			\remove_filter( 'image_editor_output_format', $filter, 10 );
		}

		self::collect_failure(
			$failures,
			'image/jpeg' === ( $default_heic['image/heic'] ?? null )
				&& ! isset( $default_png['image/png'] )
				&& 'image/webp' === ( $jpeg['image/jpeg'] ?? null )
				&& 'image/jpeg' === ( $png['image/png'] ?? null )
				&& 2 === count( $seen )
				&& '/tmp/component-fuzz.jpg' === $seen[0]['filename']
				&& 'image/jpeg' === $seen[0]['mime'],
			'wp_get_image_editor_output_format defaults and filter mappings are stable',
			array(
				'defaultHeic' => $default_heic,
				'defaultPng'  => $default_png,
				'filteredJpeg' => $jpeg,
				'filteredPng' => $png,
				'seen'        => $seen,
			)
		);

		return self::row(
			$ctx,
			'media-editor.output-format.filters',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_editor_selection_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$input_only      = MediaEditorSurfaceInputOnlyEditor::class;
		$output_capable  = MediaEditorSurfaceOutputCapableEditor::class;
		$editors_filter  = static function ( array $editors ) use ( $input_only, $output_capable ): array {
			unset( $editors );
			return array( $input_only, $output_capable );
		};
		$unsupported     = array(
			'mime_type'        => 'image/jpeg',
			'output_mime_type' => 'image/tiff',
			'methods'          => array( 'resize' ),
			'component_fuzz'   => $ctx->seed(),
		);
		$output_prefer   = array(
			'mime_type'        => 'image/jpeg',
			'output_mime_type' => 'image/webp',
			'methods'          => array( 'resize' ),
			'component_fuzz'   => $ctx->seed() . ':webp',
		);
		$missing_method  = array(
			'mime_type'      => 'image/jpeg',
			'methods'        => array( 'rotate' ),
			'component_fuzz' => $ctx->seed() . ':rotate',
		);

		self::clear_editor_cache();
		\add_filter( 'wp_image_editors', $editors_filter );
		try {
			$chosen_output       = \_wp_image_editor_choose( $output_prefer );
			$supports_output     = \wp_image_editor_supports( $output_prefer );
			$chosen_unsupported  = \_wp_image_editor_choose( $unsupported );
			$supports_method_gap = \wp_image_editor_supports( $missing_method );
		} finally {
			\remove_filter( 'wp_image_editors', $editors_filter );
			self::clear_editor_cache();
		}

		self::collect_failure(
			$failures,
			$output_capable === $chosen_output
				&& true === $supports_output
				&& $output_capable === $chosen_unsupported
				&& false === $supports_method_gap,
			'editor chooser prefers output-capable filtered editor and honors method support',
			array(
				'chosenOutput'      => $chosen_output,
				'supportsOutput'    => $supports_output,
				'chosenUnsupported' => $chosen_unsupported,
				'supportsMethodGap' => $supports_method_gap,
				'inputOnly'         => $input_only,
				'outputCapable'     => $output_capable,
			)
		);

		return self::row(
			$ctx,
			'media-editor.editor-selection.filters-supports',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_abstract_editor_contracts( \ComponentFuzz\FuzzContext $ctx, ?string $temp_root ): array {
		$failures        = array();
		$base_dir        = $temp_root ?? sys_get_temp_dir();
		$source_mime     = $ctx->choice( array( 'image/jpeg', 'image/png' ) );
		$output_mime     = ( 'image/jpeg' === $source_mime ) ? 'image/webp' : 'image/jpeg';
		$source_ext      = self::extension_for_mime( $source_mime );
		$output_ext      = self::extension_for_mime( $output_mime );
		$width           = $ctx->int( 48, 512 );
		$height          = $ctx->int( 48, 512 );
		$source_name     = self::safe_image_basename( 'abstract source ' . $ctx->filename(), $source_ext );
		$target_name     = self::safe_image_basename( 'abstract output ' . $ctx->filename(), $source_ext );
		$source_file     = $base_dir . DIRECTORY_SEPARATOR . $source_name;
		$target_file     = $base_dir . DIRECTORY_SEPARATOR . $target_name;
		$editor          = self::make_fake_image_editor(
			$source_file,
			$source_mime,
			array(
				'width'  => $width,
				'height' => $height,
			)
		);
		$quality_values  = array(
			'image/jpeg' => 79,
			'image/png'  => 83,
			'image/webp' => 91,
		);
		$default_quality = array(
			'image/jpeg' => 82,
			'image/png'  => 82,
			'image/webp' => 86,
		);
		$quality_calls   = array();
		$jpeg_calls      = array();
		$output_calls    = array();

		$output_filter = static function ( array $formats, string $filename, string $mime_type ) use ( &$output_calls, $source_mime, $output_mime ): array {
			$output_calls[] = array(
				'filename' => $filename,
				'mime'     => $mime_type,
			);

			if ( $source_mime === $mime_type ) {
				$formats[ $source_mime ] = $output_mime;
			}

			return $formats;
		};
		$quality_filter = static function ( $quality, string $mime_type, array $size ) use ( &$quality_calls, $quality_values ) {
			$quality_calls[] = array(
				'quality' => $quality,
				'mime'    => $mime_type,
				'size'    => $size,
			);

			return $quality_values[ $mime_type ] ?? $quality;
		};
		$jpeg_filter    = static function ( $quality, string $context ) use ( &$jpeg_calls ): int {
			$jpeg_calls[] = array(
				'quality' => $quality,
				'context' => $context,
			);

			return max( 1, $quality - 5 );
		};

		$output_filter_active = false;
		\add_filter( 'image_editor_output_format', $output_filter, 10, 3 );
		\add_filter( 'wp_editor_set_quality', $quality_filter, 10, 3 );
		\add_filter( 'jpeg_quality', $jpeg_filter, 10, 2 );
		$output_filter_active = true;

		try {
			$converted_format         = $editor->component_fuzz_get_output_format( $target_file, $source_mime );
			$quality_after_conversion = $editor->get_quality();

			\remove_filter( 'image_editor_output_format', $output_filter, 10 );
			$output_filter_active = false;

			$reset_format         = $editor->component_fuzz_get_output_format( $target_file, $source_mime );
			$quality_after_reset  = $editor->get_quality();
		} finally {
			if ( $output_filter_active ) {
				\remove_filter( 'image_editor_output_format', $output_filter, 10 );
			}
			\remove_filter( 'wp_editor_set_quality', $quality_filter, 10 );
			\remove_filter( 'jpeg_quality', $jpeg_filter, 10 );
		}

		$quality_filters_gone = false === \has_filter( 'wp_editor_set_quality', $quality_filter )
			&& false === \has_filter( 'jpeg_quality', $jpeg_filter )
			&& false === \has_filter( 'image_editor_output_format', $output_filter );

		$quality_mimes        = array_column( $quality_calls, 'mime' );
		$quality_defaults     = array_column( $quality_calls, 'quality' );
		$expected_mimes       = array( $output_mime, $source_mime );
		$expected_defaults    = array( $default_quality[ $output_mime ], $default_quality[ $source_mime ] );
		$expected_conversion  = ( 'image/jpeg' === $output_mime ) ? 74 : $quality_values[ $output_mime ];
		$expected_reset       = ( 'image/jpeg' === $source_mime ) ? 74 : $quality_values[ $source_mime ];
		$output_calls_match   = 1 === count( $output_calls )
			&& $source_mime === ( $output_calls[0]['mime'] ?? null )
			&& self::same_path( $target_file, $output_calls[0]['filename'] ?? '' );
		$quality_sizes_match  = true;
		foreach ( $quality_calls as $call ) {
			$quality_sizes_match = $quality_sizes_match
				&& array(
					'width'  => $width,
					'height' => $height,
				) === $call['size'];
		}

		self::collect_failure(
			$failures,
			self::same_path( self::replace_file_extension( $target_file, $output_ext ), $converted_format[0] ?? '' )
				&& $output_ext === ( $converted_format[1] ?? null )
				&& $output_mime === ( $converted_format[2] ?? null )
				&& self::same_path( $target_file, $reset_format[0] ?? '' )
				&& $source_ext === ( $reset_format[1] ?? null )
				&& $source_mime === ( $reset_format[2] ?? null )
				&& $output_calls_match
				&& $expected_mimes === $quality_mimes
				&& $expected_defaults === $quality_defaults
				&& $expected_conversion === $quality_after_conversion
				&& $expected_reset === $quality_after_reset
				&& 1 === count( $jpeg_calls )
				&& 79 === ( $jpeg_calls[0]['quality'] ?? null )
				&& 'image_resize' === ( $jpeg_calls[0]['context'] ?? null )
				&& $quality_sizes_match
				&& $quality_filters_gone,
			'abstract editor output format conversion/reset updates filenames, mime type, and quality filters deterministically',
			array(
				'sourceMime'             => $source_mime,
				'outputMime'             => $output_mime,
				'targetFile'             => $target_file,
				'convertedFormat'        => $converted_format ?? null,
				'resetFormat'            => $reset_format ?? null,
				'outputCalls'            => $output_calls,
				'qualityCalls'           => $quality_calls,
				'jpegCalls'              => $jpeg_calls,
				'qualityAfterConversion' => $quality_after_conversion ?? null,
				'qualityAfterReset'      => $quality_after_reset ?? null,
				'qualityFiltersGone'     => $quality_filters_gone,
			)
		);

		$source_base       = \wp_basename( $source_file, '.' . $source_ext );
		$source_dir        = pathinfo( $source_file, PATHINFO_DIRNAME );
		$dest_dir          = realpath( $base_dir ) ?: $source_dir;
		$custom_suffix     = 'cf-' . $ctx->identifier( 2, 8 );
		$default_filename  = $editor->generate_filename();
		$empty_filename    = $editor->generate_filename( '', null, strtoupper( $output_ext ) );
		$custom_filename   = $editor->generate_filename( $custom_suffix, $base_dir, $output_ext );
		$expected_default  = trailingslashit( $source_dir ) . "{$source_base}-{$width}x{$height}.{$source_ext}";
		$expected_empty    = trailingslashit( $source_dir ) . "{$source_base}.{$output_ext}";
		$expected_custom   = trailingslashit( $dest_dir ) . "{$source_base}-{$custom_suffix}.{$output_ext}";
		$fallback_file     = $base_dir . DIRECTORY_SEPARATOR . self::safe_image_basename( 'abstract fallback ' . $ctx->filename(), 'gif' );
		$fallback_editor   = self::make_fake_image_editor(
			$source_file,
			$source_mime,
			array(
				'width'  => $width,
				'height' => $height,
			)
		);
		$default_calls     = array();
		$default_filter    = static function ( string $mime_type ) use ( &$default_calls ): string {
			$default_calls[] = $mime_type;
			return 'image/png';
		};

		\add_filter( 'image_editor_default_mime_type', $default_filter );
		try {
			$fallback_format = $fallback_editor->component_fuzz_get_output_format( $fallback_file, 'image/gif' );
		} finally {
			\remove_filter( 'image_editor_default_mime_type', $default_filter );
		}

		self::collect_failure(
			$failures,
			self::same_path( $expected_default, $default_filename )
				&& self::same_path( $expected_empty, $empty_filename )
				&& self::same_path( $expected_custom, $custom_filename )
				&& self::same_path( self::replace_file_extension( $fallback_file, 'png' ), $fallback_format[0] ?? '' )
				&& 'png' === ( $fallback_format[1] ?? null )
				&& 'image/png' === ( $fallback_format[2] ?? null )
				&& array( 'image/jpeg' ) === $default_calls
				&& false === \has_filter( 'image_editor_default_mime_type', $default_filter ),
			'abstract editor filename generation honors default, custom, empty suffix, destination, extension, and fallback mime semantics',
			array(
				'sourceFile'       => $source_file,
				'size'             => array(
					'width'  => $width,
					'height' => $height,
				),
				'defaultFilename'  => $default_filename,
				'expectedDefault'  => $expected_default,
				'emptyFilename'    => $empty_filename,
				'expectedEmpty'    => $expected_empty,
				'customFilename'   => $custom_filename,
				'expectedCustom'   => $expected_custom,
				'fallbackFile'     => $fallback_file,
				'fallbackFormat'   => $fallback_format ?? null,
				'defaultMimeCalls' => $default_calls,
			)
		);

		$rotate_file       = $base_dir . DIRECTORY_SEPARATOR . 'missing-exif-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration() . '.jpg';
		$rotate_editor     = self::make_fake_image_editor(
			$rotate_file,
			'image/jpeg',
			array(
				'width'  => $width,
				'height' => $height,
			)
		);
		$orientation_cases = array(
			array(
				'orientation' => null,
				'result'      => false,
				'operations'  => array(),
			),
			array(
				'orientation' => 1,
				'result'      => false,
				'operations'  => array(),
			),
			array(
				'orientation' => 2,
				'result'      => true,
				'operations'  => array(
					array(
						'type' => 'flip',
						'horz' => false,
						'vert' => true,
					),
				),
			),
			array(
				'orientation' => 3,
				'result'      => true,
				'operations'  => array(
					array(
						'type' => 'flip',
						'horz' => true,
						'vert' => true,
					),
				),
			),
			array(
				'orientation' => 4,
				'result'      => true,
				'operations'  => array(
					array(
						'type' => 'flip',
						'horz' => true,
						'vert' => false,
					),
				),
			),
			array(
				'orientation' => 5,
				'result'      => true,
				'operations'  => array(
					array(
						'type'  => 'rotate',
						'angle' => 90,
					),
					array(
						'type' => 'flip',
						'horz' => true,
						'vert' => false,
					),
				),
			),
			array(
				'orientation' => 6,
				'result'      => true,
				'operations'  => array(
					array(
						'type'  => 'rotate',
						'angle' => 270,
					),
				),
			),
			array(
				'orientation' => 7,
				'result'      => true,
				'operations'  => array(
					array(
						'type'  => 'rotate',
						'angle' => 90,
					),
					array(
						'type' => 'flip',
						'horz' => false,
						'vert' => true,
					),
				),
			),
			array(
				'orientation' => 8,
				'result'      => true,
				'operations'  => array(
					array(
						'type'  => 'rotate',
						'angle' => 90,
					),
				),
			),
		);
		$forced_orientation = null;
		$orientation_calls  = array();
		$orientation_filter = static function ( $orientation, string $file ) use ( &$forced_orientation, &$orientation_calls ) {
			$orientation_calls[] = array(
				'input'  => $orientation,
				'forced' => $forced_orientation,
				'file'   => $file,
			);

			return $forced_orientation;
		};
		$orientation_seen   = array();
		$orientation_ok     = true;

		\add_filter( 'wp_image_maybe_exif_rotate', $orientation_filter, 10, 2 );
		try {
			foreach ( $orientation_cases as $case ) {
				$forced_orientation = $case['orientation'];
				$rotate_editor->component_fuzz_reset_operations();
				$result             = $rotate_editor->maybe_exif_rotate();
				$operations         = $rotate_editor->component_fuzz_operations();
				$orientation_seen[] = array(
					'orientation' => $case['orientation'],
					'result'      => $result,
					'operations'  => $operations,
				);
				$orientation_ok     = $orientation_ok
					&& $case['result'] === $result
					&& $case['operations'] === $operations;
			}
		} finally {
			\remove_filter( 'wp_image_maybe_exif_rotate', $orientation_filter, 10 );
		}

		$orientation_files_ok = true;
		$orientation_forced   = array();
		foreach ( $orientation_calls as $call ) {
			$orientation_files_ok = $orientation_files_ok
				&& null === $call['input']
				&& self::same_path( $rotate_file, $call['file'] );
			$orientation_forced[] = $call['forced'];
		}

		self::collect_failure(
			$failures,
			$orientation_ok
				&& count( $orientation_cases ) === count( $orientation_calls )
				&& array_column( $orientation_cases, 'orientation' ) === $orientation_forced
				&& $orientation_files_ok
				&& false === \has_filter( 'wp_image_maybe_exif_rotate', $orientation_filter ),
			'abstract editor EXIF orientation filter maps rotations and flips in the documented order',
			array(
				'file'             => $rotate_file,
				'orientationSeen'  => $orientation_seen,
				'orientationCalls' => $orientation_calls,
			)
		);

		return self::row(
			$ctx,
			'media-editor.abstract-editor.contracts',
			array() === $failures,
			array(
				'sourceMime' => $source_mime,
				'outputMime' => $output_mime,
				'failures'   => $failures,
			)
		);
	}

	private static function check_attachment_metadata_helpers( \ComponentFuzz\FuzzContext $ctx, ?string $temp_root ): array {
		$failures = array();
		$root     = $temp_root ?? self::make_temp_root( $ctx->fork( 'attachment-temp' ) );
		if ( null === $root ) {
			return $ctx->skip(
				'media-editor.attachment-metadata.filters-cache-no-db',
				'Could not create a temporary upload root for attachment helper checks.'
			);
		}

		$upload_root   = $root . DIRECTORY_SEPARATOR . 'uploads';
		$attachment_id = 830000 + $ctx->iteration();
		$unsafe_name   = $ctx->filename();
		$safe_name     = self::safe_image_basename( $unsafe_name, 'jpg' );
		$original_name = self::safe_image_basename( '../original ' . $unsafe_name, 'jpg' );
		$relative_file = '2026/06/' . $safe_name;
		$existing_size = 'component-fuzz-existing-' . $ctx->int( 10, 999 );
		$missing_size  = 'component-fuzz-missing-' . $ctx->int( 10, 999 );
		$too_large     = 'component-fuzz-large-' . $ctx->int( 10, 999 );
		$metadata      = array(
			'width'          => 320,
			'height'         => 240,
			'file'           => $relative_file,
			'original_image' => $original_name,
			'sizes'          => array(
				$existing_size => array(
					'file'      => 'component-fuzz-existing-160x120.jpg',
					'width'     => 160,
					'height'    => 120,
					'mime-type' => 'image/jpeg',
				),
				'component fuzz:' . $ctx->identifier( 2, 6 ) => array(
					'file'      => 'component-fuzz-160x120.jpg',
					'width'     => 160,
					'height'    => 120,
					'mime-type' => 'image/jpeg',
				),
			),
		);
		$updates       = array();
		$size_name     = 'component-fuzz-helper-' . $ctx->int( 10, 999 );
		$missing_calls = array();
		$upload_filter = self::upload_dir_filter( $upload_root );
		$meta_filter   = static function ( $data, int $id ) use ( $attachment_id ) {
			if ( $attachment_id === $id && is_array( $data ) ) {
				$data['component_fuzz_filtered'] = true;
			}
			return $data;
		};
		$file_filter   = static function ( $file, int $id ) use ( $attachment_id ) {
			if ( $attachment_id !== $id || ! is_string( $file ) ) {
				return $file;
			}

			return path_join( dirname( $file ), 'filtered-' . \wp_basename( $file ) );
		};
		$update_filter = static function ( $check, int $object_id, string $meta_key, $meta_value ) use ( $attachment_id, &$updates ) {
			if ( $attachment_id === $object_id && '_wp_attachment_metadata' === $meta_key ) {
				$updates[] = $meta_value;
				return true;
			}

			return $check;
		};
		$missing_filter = static function ( array $missing_sizes, array $image_meta, int $id ) use ( $attachment_id, &$missing_calls ): array {
			if ( $attachment_id === $id ) {
				$missing_calls[] = array(
					'keys'   => array_keys( $missing_sizes ),
					'width'  => $image_meta['width'] ?? null,
					'height' => $image_meta['height'] ?? null,
				);
			}

			return $missing_sizes;
		};

		\ComponentFuzz\ensure_dir( $upload_root . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '06' );
		self::seed_attachment_post( $attachment_id, 'image/jpeg' );
		\wp_cache_set(
			$attachment_id,
			array(
				'_wp_attached_file'       => array( $relative_file ),
				'_wp_attachment_metadata' => array( $metadata ),
			),
			'post_meta'
		);

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_get_attachment_metadata', $meta_filter, 10, 2 );
		\add_filter( 'get_attached_file', $file_filter, 10, 2 );
		\add_filter( 'update_post_metadata', $update_filter, 10, 4 );
		\add_filter( 'wp_get_missing_image_subsizes', $missing_filter, 10, 3 );

		try {
			$attached_unfiltered = \get_attached_file( $attachment_id, true );
			$attached_filtered   = \get_attached_file( $attachment_id, false );
			$meta_unfiltered     = \wp_get_attachment_metadata( $attachment_id, true );
			$meta_filtered       = \wp_get_attachment_metadata( $attachment_id, false );
			$original_path       = \wp_get_original_image_path( $attachment_id, true );
			$update_result       = \wp_update_attachment_metadata( $attachment_id, $metadata + array( 'component_fuzz_update' => true ) );

			\add_image_size( $size_name, 111, 77, array( 'left', 'top' ) );
			\add_image_size( $existing_size, 160, 120, true );
			\add_image_size( $missing_size, 96, 96, false );
			\add_image_size( $too_large, 5000, 5000, false );
			$registered_sizes = \wp_get_registered_image_subsizes();
			$missing_subsizes = \wp_get_missing_image_subsizes( $attachment_id );
		} finally {
			\remove_filter( 'upload_dir', $upload_filter );
			\remove_filter( 'wp_get_attachment_metadata', $meta_filter, 10 );
			\remove_filter( 'get_attached_file', $file_filter, 10 );
			\remove_filter( 'update_post_metadata', $update_filter, 10 );
			\remove_filter( 'wp_get_missing_image_subsizes', $missing_filter, 10 );
			\wp_cache_delete( $attachment_id, 'posts' );
			\wp_cache_delete( $attachment_id, 'post_meta' );
		}

		$expected_attached = $upload_root . DIRECTORY_SEPARATOR . $relative_file;
		$expected_original = path_join( dirname( $expected_attached ), $original_name );

		self::collect_failure(
			$failures,
			$metadata === $meta_unfiltered
				&& true === ( $meta_filtered['component_fuzz_filtered'] ?? false )
				&& self::same_path( $expected_attached, $attached_unfiltered )
				&& self::path_is_inside( $attached_unfiltered, $upload_root )
				&& self::path_is_inside( $attached_filtered, $upload_root )
				&& self::same_path( $expected_original, $original_path )
				&& self::path_is_inside( $original_path, $upload_root )
				&& true === $update_result
				&& 1 === count( $updates )
				&& isset( $registered_sizes[ $size_name ] )
				&& 111 === $registered_sizes[ $size_name ]['width']
				&& 77 === $registered_sizes[ $size_name ]['height']
				&& array( 'left', 'top' ) === $registered_sizes[ $size_name ]['crop'],
			'attachment metadata/file helpers use cache and filters without DB writes or path escape',
			array(
				'unsafeName'          => $unsafe_name,
				'safeName'            => $safe_name,
				'attachedUnfiltered'  => $attached_unfiltered,
				'attachedFiltered'    => $attached_filtered,
				'metaUnfiltered'      => $meta_unfiltered,
				'metaFiltered'        => $meta_filtered,
				'originalPath'        => $original_path,
				'updateResult'        => $update_result,
				'updateCount'         => count( $updates ),
				'registeredSizeName'  => $size_name,
				'registeredSizeValue' => $registered_sizes[ $size_name ] ?? null,
			)
		);
		self::collect_failure(
			$failures,
			isset( $missing_subsizes[ $missing_size ] )
				&& isset( $missing_subsizes[ $size_name ] )
				&& ! isset( $missing_subsizes[ $existing_size ] )
				&& ! isset( $missing_subsizes[ $too_large ] )
				&& 96 === $missing_subsizes[ $missing_size ]['width']
				&& false === $missing_subsizes[ $missing_size ]['crop']
				&& 1 === count( $missing_calls )
				&& in_array( $missing_size, $missing_calls[0]['keys'], true )
				&& false === \has_filter( 'wp_get_missing_image_subsizes', $missing_filter ),
			'missing subsize helper returns only possible unrepresented registered sizes and removes scoped filter',
			array(
				'existingSize'    => $existing_size,
				'missingSize'     => $missing_size,
				'tooLarge'        => $too_large,
				'sizeName'        => $size_name,
				'missingSubsizes' => $missing_subsizes,
				'missingCalls'    => $missing_calls,
			)
		);

		if ( null === $temp_root ) {
			self::remove_dir_recursive( $root );
		}

		return self::row(
			$ctx,
			'media-editor.attachment-metadata.filters-cache-no-db',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_file_editor_paths( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$rows = array();
		$mime = self::choose_supported_source_mime( $ctx->fork( 'mime' ) );
		if ( null === $mime ) {
			return array(
				$ctx->skip(
					'media-editor.file-editors.available',
					'No installed GD/Imagick editor can edit a source image generated by this environment.',
					array(
						'gd'      => extension_loaded( 'gd' ),
						'imagick' => extension_loaded( 'imagick' ),
					)
				),
			);
		}

		foreach ( self::REAL_EDITORS as $class ) {
			$case_ctx = $ctx->fork( strtolower( $class ) );
			if ( ! class_exists( $class ) ) {
				$rows[] = $case_ctx->skip(
					'media-editor.editor-class.' . $class,
					'Editor class is unavailable.'
				);
				continue;
			}

			if ( ! self::editor_class_available_for_mime( $class, $mime ) ) {
				$rows[] = $case_ctx->skip(
					'media-editor.editor-class.' . $class,
					'Editor is unavailable or does not support this generated source mime type.',
					array(
						'mime'    => $mime,
						'gd'      => extension_loaded( 'gd' ),
						'imagick' => extension_loaded( 'imagick' ),
					)
				);
				continue;
			}

			$rows[] = self::check_single_editor_class( $case_ctx, $temp_root, $class, $mime );
		}

		$editor_class = self::first_available_editor_class( $mime );
		if ( null === $editor_class ) {
			$rows[] = $ctx->skip(
				'media-editor.intermediate-size.shape-bounds',
				'No editor supports the generated source mime type.',
				array( 'mime' => $mime )
			);
			$rows[] = $ctx->skip(
				'media-editor.subsizes.metadata-bounds',
				'No editor supports the generated source mime type.',
				array( 'mime' => $mime )
			);
			return $rows;
		}

		$rows[] = self::check_intermediate_size( $ctx->fork( 'intermediate-size' ), $temp_root, $editor_class, $mime );
		$rows[] = self::check_subsizes_metadata_generation( $ctx->fork( 'subsizes' ), $temp_root, $editor_class, $mime );

		return $rows;
	}

	private static function check_single_editor_class(
		\ComponentFuzz\FuzzContext $ctx,
		string $temp_root,
		string $class,
		string $mime
	): array {
		$failures    = array();
		$case        = self::image_case( $ctx, $mime, $class );
		$source_file = $temp_root . DIRECTORY_SEPARATOR . $case['filename'];
		$dest_file   = $temp_root . DIRECTORY_SEPARATOR . 'saved-' . strtolower( $class ) . '.' . self::extension_for_mime( $mime );
		$output_mime = ( 'image/webp' !== $mime && self::editor_class_supports_mime( $class, 'image/webp' ) ) ? 'image/webp' : null;

		if ( ! self::write_generated_image( $source_file, $case['width'], $case['height'], $mime, $ctx->fork( 'pixels' ) ) ) {
			return $ctx->skip(
				'media-editor.editor-class.' . $class,
				'Could not generate a source image for this editor check.',
				array( 'mime' => $mime )
			);
		}

		$editor_filter = static function ( array $editors ) use ( $class ): array {
			unset( $editors );
			return array( $class );
		};
		$output_filter = static function ( array $formats, string $filename, string $source_mime ) use ( $mime, $output_mime ): array {
			unset( $filename );
			if ( null !== $output_mime && $mime === $source_mime ) {
				$formats[ $source_mime ] = $output_mime;
			}
			return $formats;
		};

		self::clear_editor_cache();
		\add_filter( 'wp_image_editors', $editor_filter );
		\add_filter( 'image_editor_output_format', $output_filter, 10, 3 );

		try {
			$editor = \wp_get_image_editor(
				$source_file,
				array(
					'component_fuzz_case' => $ctx->seed(),
				)
			);

			if ( \is_wp_error( $editor ) ) {
				self::collect_failure(
					$failures,
					false,
					'wp_get_image_editor loads generated image with forced editor',
					array( 'error' => self::describe_wp_error( $editor ) )
				);
			} else {
				$expected_dimensions = \image_resize_dimensions(
					$case['width'],
					$case['height'],
					$case['targetWidth'],
					$case['targetHeight'],
					$case['crop']
				);
				$size                = $editor->get_size();
				$resize_result       = false !== $expected_dimensions
					? $editor->resize( $case['targetWidth'], $case['targetHeight'], $case['crop'] )
					: new \WP_Error( 'expected_dimensions_false' );
				$saved               = \is_wp_error( $resize_result ) ? $resize_result : $editor->save( $dest_file );

				self::collect_failure(
					$failures,
					is_a( $editor, $class )
						&& is_a( $editor, 'WP_Image_Editor' )
						&& array( 'width' => $case['width'], 'height' => $case['height'] ) === $size
						&& false !== $expected_dimensions
						&& true === $resize_result,
					'editor instance, base class, source dimensions, and resize result are stable',
					array(
						'class'              => get_class( $editor ),
						'initialSize'        => $size,
						'case'               => $case,
						'expectedDimensions' => $expected_dimensions,
						'resizeResult'       => \is_wp_error( $resize_result ) ? self::describe_wp_error( $resize_result ) : $resize_result,
					)
				);

				self::collect_saved_image_failure(
					$failures,
					$saved,
					$expected_dimensions,
					$output_mime ?? $mime,
					$temp_root,
					'editor resize/save output metadata is bounded and path-safe'
				);
			}
		} finally {
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'image_editor_output_format', $output_filter, 10 );
			self::clear_editor_cache();
		}

		return self::row(
			$ctx,
			'media-editor.editor-class.' . $class,
			array() === $failures,
			array(
				'class'      => $class,
				'mime'       => $mime,
				'outputMime' => $output_mime,
				'case'       => $case,
				'failures'   => $failures,
			)
		);
	}

	private static function check_intermediate_size(
		\ComponentFuzz\FuzzContext $ctx,
		string $temp_root,
		string $editor_class,
		string $mime
	): array {
		$failures    = array();
		$case        = self::image_case( $ctx, $mime, 'intermediate' );
		$source_file = $temp_root . DIRECTORY_SEPARATOR . 'intermediate-' . $case['filename'];

		if ( ! self::write_generated_image( $source_file, $case['width'], $case['height'], $mime, $ctx->fork( 'pixels' ) ) ) {
			return $ctx->skip(
				'media-editor.intermediate-size.shape-bounds',
				'Could not generate a source image for image_make_intermediate_size().',
				array( 'mime' => $mime )
			);
		}

		$editor_filter = static function ( array $editors ) use ( $editor_class ): array {
			unset( $editors );
			return array( $editor_class );
		};
		\add_filter( 'wp_image_editors', $editor_filter );
		self::clear_editor_cache();

		try {
			$result = \image_make_intermediate_size( $source_file, $case['targetWidth'], $case['targetHeight'], $case['crop'] );
		} finally {
			\remove_filter( 'wp_image_editors', $editor_filter );
			self::clear_editor_cache();
		}

		$expected = \image_resize_dimensions( $case['width'], $case['height'], $case['targetWidth'], $case['targetHeight'], $case['crop'] );
		if ( false === $result || false === $expected ) {
			self::collect_failure(
				$failures,
				false,
				'image_make_intermediate_size returns metadata for a downsizeable generated image',
				array(
					'case'     => $case,
					'expected' => $expected,
					'result'   => $result,
				)
			);
		} else {
			$created_file = dirname( $source_file ) . DIRECTORY_SEPARATOR . $result['file'];
			self::collect_failure(
				$failures,
				! isset( $result['path'] )
					&& isset( $result['file'], $result['width'], $result['height'], $result['mime-type'], $result['filesize'] )
					&& self::same_dimensions( $expected[4], $expected[5], (int) $result['width'], (int) $result['height'] )
					&& is_file( $created_file )
					&& self::path_is_inside( $created_file, $temp_root )
					&& filesize( $created_file ) === (int) $result['filesize'],
				'intermediate size metadata omits path, matches expected dimensions, and stays in temp root',
				array(
					'case'        => $case,
					'expected'    => $expected,
					'result'      => $result,
					'createdFile' => $created_file,
				)
			);
		}

		return self::row(
			$ctx,
			'media-editor.intermediate-size.shape-bounds',
			array() === $failures,
			array(
				'editor'   => $editor_class,
				'mime'     => $mime,
				'failures' => $failures,
			)
		);
	}

	private static function check_subsizes_metadata_generation(
		\ComponentFuzz\FuzzContext $ctx,
		string $temp_root,
		string $editor_class,
		string $mime
	): array {
		$failures      = array();
		$upload_root   = $temp_root . DIRECTORY_SEPARATOR . 'subsizes-uploads';
		$upload_subdir = $upload_root . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '06';
		$attachment_id = 840000 + $ctx->iteration();
		$case          = self::image_case( $ctx, $mime, 'subsizes' );
		$safe_name     = self::safe_image_basename( '../subsizes ' . $ctx->filename(), self::extension_for_mime( $mime ) );
		$source_file   = $upload_subdir . DIRECTORY_SEPARATOR . $safe_name;
		$sizes         = self::subsize_cases( $ctx->fork( 'sizes' ), $case['width'], $case['height'] );
		$updates       = array();
		$upload_filter = self::upload_dir_filter( $upload_root );

		\ComponentFuzz\ensure_dir( $upload_subdir );
		if ( ! self::write_generated_image( $source_file, $case['width'], $case['height'], $mime, $ctx->fork( 'pixels' ) ) ) {
			return $ctx->skip(
				'media-editor.subsizes.metadata-bounds',
				'Could not generate a source image for metadata generation.',
				array( 'mime' => $mime )
			);
		}

		self::seed_attachment_post( $attachment_id, $mime );

		$editor_filter = static function ( array $editors ) use ( $editor_class ): array {
			unset( $editors );
			return array( $editor_class );
		};
		$sizes_filter  = static function ( array $new_sizes, array $image_meta, int $id ) use ( $attachment_id, $sizes ): array {
			unset( $image_meta );
			if ( $attachment_id === $id ) {
				return $sizes;
			}

			return $new_sizes;
		};
		$update_filter = static function ( $check, int $object_id, string $meta_key, $meta_value ) use ( $attachment_id, &$updates ) {
			if ( $attachment_id === $object_id && '_wp_attachment_metadata' === $meta_key ) {
				$updates[] = array(
					'width' => is_array( $meta_value ) ? ( $meta_value['width'] ?? null ) : null,
					'sizes' => is_array( $meta_value ) && isset( $meta_value['sizes'] ) ? array_keys( $meta_value['sizes'] ) : array(),
				);
				return true;
			}

			return $check;
		};

		$rows_affected_before = isset( $GLOBALS['wpdb']->rows_affected ) ? $GLOBALS['wpdb']->rows_affected : null;

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_image_editors', $editor_filter );
		\add_filter( 'intermediate_image_sizes_advanced', $sizes_filter, 10, 3 );
		\add_filter( 'update_post_metadata', $update_filter, 10, 4 );
		self::clear_editor_cache();

		try {
			$created_meta   = \wp_create_image_subsizes( $source_file, $attachment_id );
			$generated_meta = \wp_generate_attachment_metadata( $attachment_id, $source_file );
		} finally {
			\remove_filter( 'upload_dir', $upload_filter );
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'intermediate_image_sizes_advanced', $sizes_filter, 10 );
			\remove_filter( 'update_post_metadata', $update_filter, 10 );
			\wp_cache_delete( $attachment_id, 'posts' );
			self::clear_editor_cache();
		}

		foreach (
			array(
				'created'   => $created_meta,
				'generated' => $generated_meta,
			) as $label => $meta
		) {
			foreach (
				self::subsizes_meta_failures(
					$label,
					$meta,
					$source_file,
					$upload_root,
					$sizes,
					$case['width'],
					$case['height']
				) as $failure
			) {
				$failures[] = $failure;
			}
		}

		self::collect_failure(
			$failures,
			count( $updates ) >= 2
				&& ( null === $rows_affected_before || $rows_affected_before === $GLOBALS['wpdb']->rows_affected ),
			'metadata updates were short-circuited before postmeta writes',
			array(
				'updates'            => $updates,
				'rowsAffectedBefore' => $rows_affected_before,
				'rowsAffectedAfter'  => $GLOBALS['wpdb']->rows_affected ?? null,
			)
		);

		return self::row(
			$ctx,
			'media-editor.subsizes.metadata-bounds',
			array() === $failures,
			array(
				'editor'   => $editor_class,
				'mime'     => $mime,
				'sizes'    => $sizes,
				'failures' => $failures,
			)
		);
	}

	private static function subsizes_meta_failures(
		string $label,
		array $meta,
		string $source_file,
		string $upload_root,
		array $sizes,
		int $source_width,
		int $source_height
	): array {
		$failures = array();
		self::collect_failure(
			$failures,
			$source_width === (int) ( $meta['width'] ?? 0 )
				&& $source_height === (int) ( $meta['height'] ?? 0 )
				&& isset( $meta['file'], $meta['filesize'], $meta['sizes'] )
				&& is_array( $meta['sizes'] )
				&& self::same_path( self::relative_to_root( $source_file, $upload_root ), $meta['file'] )
				&& self::path_is_inside( $source_file, $upload_root ),
			"{$label} base metadata matches generated source",
			array(
				'meta'       => $meta,
				'sourceFile' => $source_file,
			)
		);

		foreach ( $sizes as $name => $size ) {
			$expected = \image_resize_dimensions( $source_width, $source_height, $size['width'], $size['height'], $size['crop'] );
			$actual   = $meta['sizes'][ $name ] ?? null;
			$path     = is_array( $actual ) && isset( $actual['file'] ) ? dirname( $source_file ) . DIRECTORY_SEPARATOR . $actual['file'] : null;
			self::collect_failure(
				$failures,
				false !== $expected
					&& is_array( $actual )
					&& self::same_dimensions( $expected[4], $expected[5], (int) ( $actual['width'] ?? 0 ), (int) ( $actual['height'] ?? 0 ) )
					&& isset( $actual['mime-type'] )
					&& null !== $path
					&& is_file( $path )
					&& self::path_is_inside( $path, $upload_root ),
				"{$label} generated subsize {$name} has expected dimensions and file",
				array(
					'name'     => $name,
					'requested' => $size,
					'expected' => $expected,
					'actual'   => $actual,
					'path'     => $path,
				)
			);
		}

		return $failures;
	}

	private static function collect_saved_image_failure(
		array &$failures,
		$saved,
		$expected_dimensions,
		string $expected_mime,
		string $temp_root,
		string $label
	): void {
		if ( \is_wp_error( $saved ) || ! is_array( $saved ) ) {
			self::collect_failure(
				$failures,
				false,
				$label,
				array( 'saved' => \is_wp_error( $saved ) ? self::describe_wp_error( $saved ) : $saved )
			);
			return;
		}

		$path = $saved['path'] ?? null;
		$info = is_string( $path ) ? \wp_getimagesize( $path ) : false;
		self::collect_failure(
			$failures,
			is_array( $expected_dimensions )
				&& is_string( $path )
				&& is_file( $path )
				&& self::path_is_inside( $path, $temp_root )
				&& isset( $saved['file'], $saved['width'], $saved['height'], $saved['mime-type'], $saved['filesize'] )
				&& $expected_mime === $saved['mime-type']
				&& self::extension_for_mime( $expected_mime ) === strtolower( pathinfo( $saved['file'], PATHINFO_EXTENSION ) )
				&& self::same_dimensions( $expected_dimensions[4], $expected_dimensions[5], (int) $saved['width'], (int) $saved['height'] )
				&& is_array( $info )
				&& self::same_dimensions( (int) $info[0], (int) $info[1], (int) $saved['width'], (int) $saved['height'] )
				&& filesize( $path ) === (int) $saved['filesize'],
			$label,
			array(
				'saved'        => $saved,
				'expectedDims' => $expected_dimensions,
				'expectedMime' => $expected_mime,
				'imageInfo'    => $info,
			)
		);
	}

	private static function choose_supported_source_mime( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$candidates = array();
		foreach ( array_keys( self::MIME_EXT ) as $mime ) {
			if ( ! self::can_generate_image_mime( $mime ) ) {
				continue;
			}

			foreach ( self::REAL_EDITORS as $class ) {
				if ( self::editor_class_available_for_mime( $class, $mime ) ) {
					$candidates[] = $mime;
					break;
				}
			}
		}

		if ( array() === $candidates ) {
			return null;
		}

		return $ctx->choice( array_values( array_unique( $candidates ) ) );
	}

	private static function first_available_editor_class( string $mime ): ?string {
		foreach ( self::REAL_EDITORS as $class ) {
			if ( self::editor_class_available_for_mime( $class, $mime ) ) {
				return $class;
			}
		}

		return null;
	}

	private static function editor_class_available_for_mime( string $class, string $mime ): bool {
		if ( ! class_exists( $class ) ) {
			return false;
		}

		try {
			if ( ! call_user_func( array( $class, 'test' ), array( 'mime_type' => $mime ) ) ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}

		return self::editor_class_supports_mime( $class, $mime );
	}

	private static function editor_class_supports_mime( string $class, string $mime ): bool {
		if ( ! class_exists( $class ) ) {
			return false;
		}

		try {
			return (bool) call_user_func( array( $class, 'supports_mime_type' ), $mime );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	private static function image_case( \ComponentFuzz\FuzzContext $ctx, string $mime, string $label ): array {
		$width        = $ctx->int( 96, 220 );
		$height       = $ctx->int( 72, 180 );
		$target_width = $ctx->int( 24, max( 24, $width - 8 ) );
		$target_height = $ctx->int( 24, max( 24, $height - 8 ) );
		$crop         = $ctx->choice( array( false, true, array( 'left', 'top' ), array( 'right', 'bottom' ), array( 'center', 'center' ) ) );
		$extension    = self::extension_for_mime( $mime );
		$filename     = self::safe_image_basename( $label . ' ' . $ctx->filename(), $extension );

		return array(
			'width'        => $width,
			'height'       => $height,
			'targetWidth'  => $target_width,
			'targetHeight' => $target_height,
			'crop'         => $crop,
			'filename'     => $filename,
		);
	}

	private static function subsize_cases( \ComponentFuzz\FuzzContext $ctx, int $width, int $height ): array {
		$soft_width  = max( 24, min( $width - 2, (int) floor( $width * 0.62 ) ) );
		$soft_height = max( 24, min( $height - 2, (int) floor( $height * 0.62 ) ) );
		$crop_width  = max( 24, min( $width - 4, $ctx->int( 32, max( 32, $width - 6 ) ) ) );
		$crop_height = max( 24, min( $height - 4, $ctx->int( 32, max( 32, $height - 6 ) ) ) );

		return array(
			'component_fuzz_soft_' . $ctx->int( 10, 999 ) => array(
				'width'  => $soft_width,
				'height' => $soft_height,
				'crop'   => false,
			),
			'component fuzz crop:' . $ctx->identifier( 2, 6 ) => array(
				'width'  => $crop_width,
				'height' => $crop_height,
				'crop'   => $ctx->choice( array( true, array( 'left', 'top' ), array( 'right', 'bottom' ) ) ),
			),
		);
	}

	private static function write_generated_image(
		string $path,
		int $width,
		int $height,
		string $mime,
		\ComponentFuzz\FuzzContext $ctx
	): bool {
		\ComponentFuzz\ensure_dir( dirname( $path ) );

		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' ) ) {
			$image = imagecreatetruecolor( $width, $height );
			if ( ! $image ) {
				return false;
			}

			if ( function_exists( 'imagealphablending' ) && function_exists( 'imagesavealpha' ) ) {
				imagealphablending( $image, true );
				imagesavealpha( $image, true );
			}

			for ( $y = 0; $y < $height; ++$y ) {
				$red   = ( $ctx->int( 0, 255 ) + $y * 3 ) % 256;
				$green = ( $ctx->int( 0, 255 ) + $y * 5 ) % 256;
				$blue  = ( $ctx->int( 0, 255 ) + $y * 7 ) % 256;
				$color = imagecolorallocate( $image, $red, $green, $blue );
				imageline( $image, 0, $y, $width, $y, $color );
			}

			$result = false;
			if ( 'image/jpeg' === $mime && function_exists( 'imagejpeg' ) ) {
				$result = imagejpeg( $image, $path, 82 );
			} elseif ( 'image/png' === $mime && function_exists( 'imagepng' ) ) {
				$result = imagepng( $image, $path );
			} elseif ( 'image/webp' === $mime && function_exists( 'imagewebp' ) ) {
				$result = imagewebp( $image, $path, 82 );
			}

			if ( PHP_VERSION_ID < 80000 ) {
				imagedestroy( $image );
			}
			return (bool) $result && is_file( $path );
		}

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) && class_exists( 'ImagickPixel' ) ) {
			try {
				$pixel = new \ImagickPixel( sprintf( 'rgb(%d,%d,%d)', $ctx->int( 0, 255 ), $ctx->int( 0, 255 ), $ctx->int( 0, 255 ) ) );
				$image = new \Imagick();
				$image->newImage( $width, $height, $pixel );
				$image->setImageFormat( strtoupper( self::extension_for_mime( $mime ) ) );
				$image->writeImage( $path );
				$image->clear();
				$image->destroy();
				return is_file( $path );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		return false;
	}

	private static function can_generate_image_mime( string $mime ): bool {
		if ( extension_loaded( 'gd' ) ) {
			if ( 'image/jpeg' === $mime && function_exists( 'imagejpeg' ) ) {
				return true;
			}
			if ( 'image/png' === $mime && function_exists( 'imagepng' ) ) {
				return true;
			}
			if ( 'image/webp' === $mime && function_exists( 'imagewebp' ) ) {
				return true;
			}
		}

		return extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) && class_exists( 'ImagickPixel' );
	}

	private static function extension_for_mime( string $mime ): string {
		return self::MIME_EXT[ $mime ] ?? 'jpg';
	}

	private static function safe_image_basename( string $unsafe, string $extension ): string {
		$unsafe    = str_replace( array( '/', '\\', chr( 0 ) ), '-', $unsafe );
		$sanitized = \sanitize_file_name( $unsafe );
		$base      = pathinfo( $sanitized, PATHINFO_FILENAME );
		$base      = trim( str_replace( array( '/', '\\', chr( 0 ) ), '-', $base ), '.-_' );

		if ( '' === $base ) {
			$base = 'component-fuzz-image';
		}

		return \sanitize_file_name( $base . '.' . $extension );
	}

	private static function replace_file_extension( string $path, string $extension ): string {
		$dir = pathinfo( $path, PATHINFO_DIRNAME );
		$ext = pathinfo( $path, PATHINFO_EXTENSION );

		return trailingslashit( $dir ) . \wp_basename( $path, ".$ext" ) . '.' . strtolower( $extension );
	}

	private static function make_fake_image_editor( string $file, string $mime_type, array $size ) {
		return new class( $file, $mime_type, $size ) extends \WP_Image_Editor {
			private $operations = array();

			public function __construct( string $file, string $mime_type, array $size ) {
				parent::__construct( $file );

				$this->mime_type = $mime_type;
				$this->update_size( $size['width'] ?? 0, $size['height'] ?? 0 );
			}

			public static function test( $args = array() ) {
				unset( $args );
				return true;
			}

			public static function supports_mime_type( $mime_type ) {
				return in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/webp' ), true );
			}

			public function load() {
				return true;
			}

			public function save( $destfilename = null, $mime_type = null ) {
				return array(
					'path'      => $destfilename ?? $this->file,
					'file'      => \wp_basename( $destfilename ?? $this->file ),
					'width'     => $this->size['width'],
					'height'    => $this->size['height'],
					'mime-type' => $mime_type ?? $this->mime_type,
					'filesize'  => 0,
				);
			}

			public function resize( $max_w, $max_h, $crop = false ) {
				unset( $crop );
				$this->update_size( $max_w, $max_h );
				return true;
			}

			public function multi_resize( $sizes ) {
				unset( $sizes );
				return array();
			}

			public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
				$this->operations[] = array(
					'type'   => 'crop',
					'srcX'   => $src_x,
					'srcY'   => $src_y,
					'srcW'   => $src_w,
					'srcH'   => $src_h,
					'dstW'   => $dst_w,
					'dstH'   => $dst_h,
					'srcAbs' => $src_abs,
				);

				return true;
			}

			public function rotate( $angle ) {
				$this->operations[] = array(
					'type'  => 'rotate',
					'angle' => $angle,
				);

				return true;
			}

			public function flip( $horz, $vert ) {
				$this->operations[] = array(
					'type' => 'flip',
					'horz' => $horz,
					'vert' => $vert,
				);

				return true;
			}

			public function stream( $mime_type = null ) {
				unset( $mime_type );
				return true;
			}

			public function component_fuzz_get_output_format( ?string $filename = null, ?string $mime_type = null ): array {
				return $this->get_output_format( $filename, $mime_type );
			}

			public function component_fuzz_operations(): array {
				return $this->operations;
			}

			public function component_fuzz_reset_operations(): void {
				$this->operations = array();
			}
		};
	}

	private static function upload_dir_filter( string $upload_root ): \Closure {
		return static function ( array $uploads ) use ( $upload_root ): array {
			$uploads['basedir'] = $upload_root;
			$uploads['baseurl'] = 'http://example.test/component-fuzz-media-editor';
			$uploads['path']    = $upload_root;
			$uploads['url']     = $uploads['baseurl'];
			$uploads['subdir']  = '';
			$uploads['error']   = false;
			return $uploads;
		};
	}

	private static function seed_attachment_post( int $attachment_id, string $mime ): void {
		\wp_cache_set(
			$attachment_id,
			(object) array(
				'ID'                    => $attachment_id,
				'post_author'           => '0',
				'post_date'             => '2026-06-01 00:00:00',
				'post_date_gmt'         => '2026-06-01 00:00:00',
				'post_content'          => '',
				'post_title'            => 'component-fuzz-media-editor',
				'post_excerpt'          => '',
				'post_status'           => 'inherit',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'component-fuzz-media-editor',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-01 00:00:00',
				'post_modified_gmt'     => '2026-06-01 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://example.test/component-fuzz-media-editor.jpg',
				'menu_order'            => 0,
				'post_type'             => 'attachment',
				'post_mime_type'        => $mime,
				'comment_count'         => '0',
				'filter'                => 'raw',
			),
			'posts'
		);
	}

	private static function same_dimensions( int $expected_width, int $expected_height, int $actual_width, int $actual_height ): bool {
		return abs( $expected_width - $actual_width ) <= 1 && abs( $expected_height - $actual_height ) <= 1;
	}

	private static function same_path( string $expected, string $actual ): bool {
		return self::normalize_path( $expected ) === self::normalize_path( $actual );
	}

	private static function path_is_inside( string $path, string $root ): bool {
		$path = self::normalize_path( $path );
		$root = rtrim( self::normalize_path( $root ), '/' );

		return $path === $root || str_starts_with( $path, $root . '/' );
	}

	private static function relative_to_root( string $path, string $root ): string {
		$path = self::normalize_path( $path );
		$root = rtrim( self::normalize_path( $root ), '/' );

		if ( $path === $root ) {
			return '';
		}

		if ( str_starts_with( $path, $root . '/' ) ) {
			return substr( $path, strlen( $root ) + 1 );
		}

		return $path;
	}

	private static function normalize_path( string $path ): string {
		$path     = str_replace( '\\', '/', $path );
		$absolute = str_starts_with( $path, '/' );
		$parts    = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}

			$parts[] = $part;
		}

		return ( $absolute ? '/' : '' ) . implode( '/', $parts );
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR );
		$dir  = $base . DIRECTORY_SEPARATOR . 'component-fuzz-media-editor-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration();

		if ( is_dir( $dir ) ) {
			self::remove_dir_recursive( $dir );
		}

		if ( mkdir( $dir, 0700, true ) || is_dir( $dir ) ) {
			return $dir;
		}

		return null;
	}

	private static function remove_dir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_dir_recursive( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	private static function clear_editor_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			\wp_cache_delete( 'wp_image_editor_choose', 'image_editor' );
		}
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

	private static function describe_wp_error( \WP_Error $error ): array {
		return array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $error->get_error_data(),
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
				if ( $i >= 16 ) {
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
				return self::describe_wp_error( $value );
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

	private static function snapshot_globals(): array {
		$snapshot = array();
		foreach ( array( 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter', '_wp_additional_image_sizes' ) as $name ) {
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
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
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

final class MediaEditorSurfaceInputOnlyEditor {
	public static function test( $args = array() ): bool {
		unset( $args );
		return true;
	}

	public static function supports_mime_type( string $mime_type ): bool {
		return 'image/jpeg' === $mime_type;
	}

	public function resize(): void {
	}
}

final class MediaEditorSurfaceOutputCapableEditor {
	public static function test( $args = array() ): bool {
		unset( $args );
		return true;
	}

	public static function supports_mime_type( string $mime_type ): bool {
		return in_array( $mime_type, array( 'image/jpeg', 'image/webp' ), true );
	}

	public function resize(): void {
	}
}
