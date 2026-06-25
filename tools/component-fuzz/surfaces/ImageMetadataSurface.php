<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes image EXIF/IPTC metadata and admin image metadata helpers.
 */
final class ImageMetadataSurface {
	public const NAME = 'image-metadata';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'image-metadata.bootstrap-apis-available',
					'Required WordPress image metadata APIs are unavailable.',
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
					'image-metadata.temp-root.available',
					'Could not create an isolated temporary directory.',
					array( 'sysTempDir' => sys_get_temp_dir() )
				);
			} else {
				self::prepare_runtime();

				$rows[] = self::check_invalid_file_failure_paths( $ctx->fork( 'invalid' ), $temp_root );
				$rows[] = self::check_minimal_image_metadata_filters( $ctx->fork( 'minimal' ), $temp_root );
				$rows[] = self::check_xmp_alt_text_selection( $ctx->fork( 'xmp-alt' ), $temp_root );
				$rows[] = self::check_iptc_metadata( $ctx->fork( 'iptc' ), $temp_root );
				$rows[] = self::check_exif_metadata( $ctx->fork( 'exif' ), $temp_root );
				$rows[] = self::check_exif_helpers( $ctx->fork( 'helpers' ) );
			}
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'image-metadata.surface-no-throw',
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
			'image-metadata.cleanup.temp-files-and-state',
			$cleanup_ok && self::restored_state_matches( $snapshot ),
			array(
				'tempRoot'      => $cleanup_path,
				'cleaned'       => $cleanup_ok,
				'stateRestored' => self::restored_state_matches( $snapshot ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'add_filter',
				'get_locale',
				'has_filter',
				'maybe_serialize',
				'maybe_unserialize',
				'remove_filter',
				'sanitize_file_name',
				'wp_cache_flush',
				'wp_exif_date2ts',
				'wp_exif_frac2dec',
				'wp_get_image_alttext',
				'wp_getimagesize',
				'wp_is_valid_utf8',
				'wp_kses_post_deep',
				'wp_read_image_metadata',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_invalid_file_failure_paths( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures = array();
		$events   = array();
		$dir      = $temp_root . DIRECTORY_SEPARATOR . 'invalid-fixtures';
		$missing  = $dir . DIRECTORY_SEPARATOR . 'missing.jpg';
		$filter   = static function ( array $meta, string $file, int $image_type, array $iptc, array $exif ) use ( &$events ): array {
			$events[] = array(
				'basename'  => basename( $file ),
				'imageType' => $image_type,
				'iptcKeys'  => array_keys( $iptc ),
				'exifKeys'  => array_keys( $exif ),
			);
			$meta['component_fuzz_seen'] = basename( $file );
			return $meta;
		};

		$cases = array(
			array(
				'label'    => 'empty',
				'filename' => 'empty.jpg',
				'bytes'    => '',
			),
			array(
				'label'    => 'random-binary',
				'filename' => 'random.bin',
				'bytes'    => $ctx->bytes( 1, 48 ),
			),
			array(
				'label'    => 'truncated-jpeg',
				'filename' => 'truncated.jpg',
				'bytes'    => "\xFF\xD8\xFF\xE1\x00\x40" . $ctx->bytes( 0, 16 ),
			),
			array(
				'label'    => 'png-ish',
				'filename' => 'pngish.png',
				'bytes'    => "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR",
			),
			array(
				'label'    => 'tiff-ish',
				'filename' => 'tiffish.tif',
				'bytes'    => "II*\x00" . $ctx->bytes( 0, 20 ),
			),
		);

		\add_filter( 'wp_read_image_metadata', $filter, 10, 5 );
		try {
			self::collect_failure(
				$failures,
				false === \wp_read_image_metadata( $missing ),
				'missing file returns false before metadata filters',
				array( 'missing' => $missing )
			);

			foreach ( $cases as $case ) {
				$path = self::write_fixture( $dir, $case['filename'], $case['bytes'] );
				if ( null === $path ) {
					self::collect_failure( $failures, false, 'invalid fixture is writable', array( 'case' => $case['label'] ) );
					continue;
				}

				self::collect_failure(
					$failures,
					false === \wp_read_image_metadata( $path ),
					"malformed {$case['label']} image fails closed",
					array(
						'case' => $case['label'],
						'path' => $path,
					)
				);
			}
		} finally {
			\remove_filter( 'wp_read_image_metadata', $filter, 10 );
		}

		self::collect_failure(
			$failures,
			array() === $events && false === \has_filter( 'wp_read_image_metadata', $filter ),
			'invalid files return before final metadata filter and filter is restored',
			array(
				'events'    => $events,
				'hasFilter' => \has_filter( 'wp_read_image_metadata', $filter ),
			)
		);

		return self::row(
			$ctx,
			'image-metadata.invalid-files.fail-closed',
			array() === $failures,
			array(
				'cases'    => count( $cases ) + 1,
				'events'   => $events,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_minimal_image_metadata_filters( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures    = array();
		$events      = array(
			'types' => array(),
			'meta'  => array(),
		);
		$dir         = $temp_root . DIRECTORY_SEPARATOR . 'minimal-fixtures';
		$dimensions  = array(
			'width'  => $ctx->int( 1, 24 ),
			'height' => $ctx->int( 1, 24 ),
		);
		$fixture_map = array(
			'jpeg' => array(
				'filename' => 'minimal.jpg',
				'bytes'    => self::jpeg_bytes( array(), $dimensions['width'], $dimensions['height'] ),
			),
			'png'  => array(
				'filename' => 'minimal.png',
				'bytes'    => self::png_bytes( $dimensions['width'], $dimensions['height'] ),
			),
			'tiff' => array(
				'filename' => 'minimal.tif',
				'bytes'    => self::minimal_tiff_bytes( $dimensions['width'], $dimensions['height'] ),
			),
		);
		$paths       = array();

		foreach ( $fixture_map as $label => $fixture ) {
			$paths[ $label ] = self::write_fixture( $dir, $fixture['filename'], $fixture['bytes'] );
			if ( null === $paths[ $label ] ) {
				self::collect_failure( $failures, false, 'minimal image fixture is writable', array( 'case' => $label ) );
			}
		}

		$types_filter = static function ( array $types ) use ( &$events ): array {
			$events['types'][] = $types;
			$types[]           = IMAGETYPE_PNG;
			return array_values( array_unique( $types ) );
		};
		$meta_filter  = static function ( array $meta, string $file, int $image_type, array $iptc, array $exif ) use ( &$events ): array {
			$events['meta'][] = array(
				'basename'  => basename( $file ),
				'imageType' => $image_type,
				'iptcCount' => count( $iptc ),
				'exifCount' => count( $exif ),
				'keys'      => array_keys( $meta ),
			);
			$meta['component_fuzz_filter'] = basename( $file );
			return $meta;
		};

		$metadata = array();
		\add_filter( 'wp_read_image_metadata_types', $types_filter );
		\add_filter( 'wp_read_image_metadata', $meta_filter, 10, 5 );
		try {
			foreach ( $paths as $label => $path ) {
				if ( null === $path ) {
					continue;
				}
				$metadata[ $label ] = \wp_read_image_metadata( $path );
			}
		} finally {
			\remove_filter( 'wp_read_image_metadata', $meta_filter, 10 );
			\remove_filter( 'wp_read_image_metadata_types', $types_filter );
		}

		foreach ( $metadata as $label => $meta ) {
			self::collect_failure(
				$failures,
				is_array( $meta )
					&& isset( $meta['component_fuzz_filter'] )
					&& self::default_metadata_shape_ok( $meta )
					&& self::metadata_text_is_clean( $meta )
					&& self::serializable_array_ok( $meta ),
				"minimal {$label} image returns bounded default metadata through final filter",
				array(
					'case'     => $label,
					'metadata' => $meta,
				)
			);
		}

		self::collect_failure(
			$failures,
			count( $events['types'] ) === count( $metadata )
				&& count( $events['meta'] ) === count( $metadata )
				&& false === \has_filter( 'wp_read_image_metadata_types', $types_filter )
				&& false === \has_filter( 'wp_read_image_metadata', $meta_filter ),
			'metadata type and final metadata filters are local to valid minimal images',
			array(
				'events'        => $events,
				'typesHasFilter' => \has_filter( 'wp_read_image_metadata_types', $types_filter ),
				'metaHasFilter' => \has_filter( 'wp_read_image_metadata', $meta_filter ),
			)
		);

		return self::row(
			$ctx,
			'image-metadata.minimal-images.filters-and-defaults',
			array() === $failures,
			array(
				'dimensions' => $dimensions,
				'events'     => $events,
				'metadata'   => self::preview_metadata_map( $metadata ),
				'failures'   => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_xmp_alt_text_selection( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return $ctx->skip(
				'image-metadata.xmp-alt.locale-fallbacks',
				'DOMDocument/DOMXPath are unavailable in this PHP build.'
			);
		}

		$failures = array();
		$dir      = $temp_root . DIRECTORY_SEPARATOR . 'xmp-alt-fixtures';
		$token    = $ctx->identifier( 3, 8 );
		$alts     = array(
			'default' => 'Default alt ' . $token,
			'en'      => 'English alt ' . $token,
			'fr'      => 'Texte alternatif ' . $token . ' <script>drop()</script>',
		);

		$localized_path = self::write_fixture(
			$dir,
			'localized-alt.jpg',
			self::jpeg_bytes(
				array(
					self::jpeg_xmp_alt_segment(
						array(
							'x-default' => $alts['default'],
							'en'        => $alts['en'],
							'fr_FR'     => $alts['fr'],
						)
					),
				),
				$ctx->int( 4, 32 ),
				$ctx->int( 4, 32 )
			)
		);
		$partial_path   = self::write_fixture(
			$dir,
			'partial-alt.jpg',
			self::jpeg_bytes(
				array(
					self::jpeg_xmp_alt_segment(
						array(
							'x-default' => $alts['default'],
							'en'        => $alts['en'],
						)
					),
				),
				$ctx->int( 4, 32 ),
				$ctx->int( 4, 32 )
			)
		);
		$default_path   = self::write_fixture(
			$dir,
			'default-alt.jpg',
			self::jpeg_bytes(
				array(
					self::jpeg_xmp_alt_segment(
						array(
							'x-default' => $alts['default'],
						)
					),
				),
				$ctx->int( 4, 32 ),
				$ctx->int( 4, 32 )
			)
		);
		$plain_path     = self::write_fixture(
			$dir,
			'plain.jpg',
			self::jpeg_bytes( array(), $ctx->int( 4, 32 ), $ctx->int( 4, 32 ) )
		);

		foreach (
			array(
				'localized' => $localized_path,
				'partial'   => $partial_path,
				'default'   => $default_path,
				'plain'     => $plain_path,
			) as $label => $path
		) {
			self::collect_failure(
				$failures,
				is_string( $path ),
				"XMP alt fixture {$label} is writable",
				array( 'path' => $path )
			);
		}

		if ( array() !== $failures ) {
			return self::row(
				$ctx,
				'image-metadata.xmp-alt.locale-fallbacks',
				false,
				array( 'failures' => array_slice( $failures, 0, 8 ) )
			);
		}

		$fr_locale = static fn(): string => 'fr_FR';
		$en_locale = static fn(): string => 'en_US';
		$es_locale = static fn(): string => 'es_ES';

		\add_filter( 'locale', $fr_locale );
		try {
			$direct_fr = \wp_get_image_alttext( $localized_path );
			$meta_fr   = \wp_read_image_metadata( $localized_path );
		} finally {
			\remove_filter( 'locale', $fr_locale );
		}

		\add_filter( 'locale', $en_locale );
		try {
			$direct_en = \wp_get_image_alttext( $partial_path );
		} finally {
			\remove_filter( 'locale', $en_locale );
		}

		\add_filter( 'locale', $es_locale );
		try {
			$direct_default = \wp_get_image_alttext( $default_path );
		} finally {
			\remove_filter( 'locale', $es_locale );
		}

		$plain_alt = \wp_get_image_alttext( $plain_path );

		self::collect_failure(
			$failures,
			$alts['fr'] === $direct_fr
				&& is_array( $meta_fr )
				&& isset( $meta_fr['alt'] )
				&& str_contains( (string) $meta_fr['alt'], 'Texte alternatif ' . $token )
				&& ! str_contains( strtolower( (string) $meta_fr['alt'] ), '<script' )
				&& self::metadata_text_is_clean( $meta_fr )
				&& $alts['en'] === $direct_en
				&& $alts['default'] === $direct_default
				&& '' === $plain_alt
				&& false === \has_filter( 'locale', $fr_locale )
				&& false === \has_filter( 'locale', $en_locale )
				&& false === \has_filter( 'locale', $es_locale ),
			'XMP alt text honors exact locale, partial locale, x-default fallback, and metadata sanitization',
			array(
				'directFr'          => $direct_fr,
				'metadataFr'        => is_array( $meta_fr ) ? self::preview_metadata( $meta_fr ) : $meta_fr,
				'directEn'          => $direct_en,
				'directDefault'     => $direct_default,
				'plainAlt'          => $plain_alt,
				'frLocaleHasFilter' => \has_filter( 'locale', $fr_locale ),
				'enLocaleHasFilter' => \has_filter( 'locale', $en_locale ),
				'esLocaleHasFilter' => \has_filter( 'locale', $es_locale ),
			)
		);

		return self::row(
			$ctx,
			'image-metadata.xmp-alt.locale-fallbacks',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_iptc_metadata( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		if ( ! is_callable( 'iptcparse' ) ) {
			return $ctx->skip(
				'image-metadata.iptc.fields-sanitization',
				'iptcparse() is unavailable in this PHP build.',
				array( 'iptcparse' => false )
			);
		}

		$failures = array();
		$events   = array();
		$dir      = $temp_root . DIRECTORY_SEPARATOR . 'iptc-fixtures';
		$title    = 'IPTC Headline <b>' . $ctx->identifier( 3, 8 ) . '</b><script>drop()</script>';
		$caption  = 'IPTC caption ' . $ctx->identifier( 3, 8 ) . ' <script>drop()</script>';
		$credit   = 'Credit ' . $ctx->identifier( 2, 5 );
		$alt      = 'Accessible alt ' . $ctx->identifier( 3, 8 ) . ' <script>drop()</script>';
		$iptc     = self::iptc_app13_payload(
			array(
				array( 105, $title ),
				array( 120, $caption ),
				array( 110, $credit ),
				array( 55, '2026-06-23' ),
				array( 60, '10:11:12' ),
				array( 116, 'Copyright <script>drop()</script> Holder' ),
				array( 25, 'keyword-' . $ctx->identifier( 2, 4 ) ),
				array( 25, 'keyword-extra-' . $ctx->identifier( 2, 4 ) ),
			)
		);
		$segments = array(
			self::jpeg_segment( 0xED, $iptc ),
			self::jpeg_xmp_segment( $alt ),
		);
		$path     = self::write_fixture(
			$dir,
			'iptc-rich.jpg',
			self::jpeg_bytes( $segments, $ctx->int( 3, 32 ), $ctx->int( 3, 32 ) )
		);

		if ( null === $path ) {
			return self::row(
				$ctx,
				'image-metadata.iptc.fields-sanitization',
				false,
				array( 'failures' => array( array( 'message' => 'IPTC fixture is writable' ) ) )
			);
		}

		$filter = static function ( array $meta, string $file, int $image_type, array $iptc_data, array $exif ) use ( &$events ): array {
			$events[] = array(
				'basename'  => basename( $file ),
				'imageType' => $image_type,
				'iptcKeys'  => array_keys( $iptc_data ),
				'exifKeys'  => array_keys( $exif ),
			);
			$meta['component_fuzz_iptc_filter'] = count( $iptc_data );
			return $meta;
		};

		\add_filter( 'wp_read_image_metadata', $filter, 10, 5 );
		try {
			$meta = \wp_read_image_metadata( $path );
		} finally {
			\remove_filter( 'wp_read_image_metadata', $filter, 10 );
		}

		self::collect_failure(
			$failures,
			is_array( $meta )
				&& isset( $meta['component_fuzz_iptc_filter'] )
				&& IMAGETYPE_JPEG === ( $events[0]['imageType'] ?? null )
				&& in_array( '2#105', $events[0]['iptcKeys'] ?? array(), true )
				&& '' !== $meta['title']
				&& '' !== $meta['caption']
				&& '' !== $meta['credit']
				&& '' !== $meta['copyright']
				&& ! empty( $meta['created_timestamp'] )
				&& ! empty( $meta['keywords'] )
				&& '' !== $meta['alt']
				&& self::metadata_text_is_clean( $meta )
				&& self::serializable_array_ok( $meta )
				&& false === \has_filter( 'wp_read_image_metadata', $filter ),
			'IPTC APP13 fields populate title/caption/credit/copyright/time/keywords/alt and are sanitized',
			array(
				'metadata'  => $meta,
				'events'    => $events,
				'hasFilter' => \has_filter( 'wp_read_image_metadata', $filter ),
			)
		);

		return self::row(
			$ctx,
			'image-metadata.iptc.fields-sanitization',
			array() === $failures,
			array(
				'events'   => $events,
				'metadata' => is_array( $meta ) ? self::preview_metadata( $meta ) : $meta,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_exif_metadata( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		if ( ! is_callable( 'exif_read_data' ) ) {
			return $ctx->skip(
				'image-metadata.exif.fields-and-type-filter',
				'exif_read_data() is unavailable in this PHP build.',
				array( 'exif_read_data' => false )
			);
		}

		$failures     = array();
		$events       = array();
		$dir          = $temp_root . DIRECTORY_SEPARATOR . 'exif-fixtures';
		$description  = 'EXIF title ' . $ctx->identifier( 3, 8 ) . ' <script>drop()</script>';
		$user_comment = 'EXIF caption ' . $ctx->identifier( 3, 8 ) . ' <script>drop()</script>';
		$model        = 'Camera ' . $ctx->identifier( 3, 8 );
		$artist       = 'Artist ' . $ctx->identifier( 3, 8 );
		$path         = self::write_fixture(
			$dir,
			'exif-rich.jpg',
			self::jpeg_bytes(
				array(
					self::jpeg_segment(
						0xE1,
						self::exif_app1_payload(
							array(
								'description' => $description,
								'userComment' => $user_comment,
								'artist'      => $artist,
								'copyright'   => 'Copyright <script>drop()</script> Owner',
								'model'       => $model,
								'date'        => '2026:06:23 10:11:12',
								'orientation' => $ctx->choice( array( 1, 3, 6, 8 ) ),
							)
						)
					),
				),
				$ctx->int( 4, 40 ),
				$ctx->int( 4, 40 )
			)
		);

		if ( null === $path ) {
			return self::row(
				$ctx,
				'image-metadata.exif.fields-and-type-filter',
				false,
				array( 'failures' => array( array( 'message' => 'EXIF fixture is writable' ) ) )
			);
		}

		$block_types_filter = static function ( array $types ) use ( &$events ): array {
			$events[] = array(
				'filter' => 'types-block',
				'before' => $types,
			);
			return array();
		};
		$allow_types_filter = static function ( array $types ) use ( &$events ): array {
			$events[] = array(
				'filter' => 'types-allow',
				'before' => $types,
			);
			return array_values( array_unique( array_merge( $types, array( IMAGETYPE_JPEG ) ) ) );
		};
		$meta_filter        = static function ( array $meta, string $file, int $image_type, array $iptc, array $exif ) use ( &$events ): array {
			$events[] = array(
				'filter'    => 'metadata',
				'basename'  => basename( $file ),
				'imageType' => $image_type,
				'iptcKeys'  => array_keys( $iptc ),
				'exifKeys'  => array_keys( $exif ),
			);
			$meta['component_fuzz_exif_filter'] = count( $exif );
			return $meta;
		};

		\add_filter( 'wp_read_image_metadata_types', $block_types_filter );
		try {
			$blocked = \wp_read_image_metadata( $path );
		} finally {
			\remove_filter( 'wp_read_image_metadata_types', $block_types_filter );
		}

		\add_filter( 'wp_read_image_metadata_types', $allow_types_filter );
		\add_filter( 'wp_read_image_metadata', $meta_filter, 10, 5 );
		try {
			$meta = \wp_read_image_metadata( $path );
		} finally {
			\remove_filter( 'wp_read_image_metadata', $meta_filter, 10 );
			\remove_filter( 'wp_read_image_metadata_types', $allow_types_filter );
		}

		$expected_timestamp = \wp_exif_date2ts( '2026:06:23 10:11:12' );
		self::collect_failure(
			$failures,
			is_array( $blocked )
				&& '' === $blocked['camera']
				&& 0 === (int) $blocked['created_timestamp']
				&& is_array( $meta )
				&& isset( $meta['component_fuzz_exif_filter'] )
				&& '' !== $meta['title']
				&& '' !== $meta['caption']
				&& $artist === $meta['credit']
				&& $model === $meta['camera']
				&& $expected_timestamp === (int) $meta['created_timestamp']
				&& 5.6 === (float) $meta['aperture']
				&& '35' === $meta['focal_length']
				&& '0.008' === $meta['shutter_speed']
				&& ! empty( $meta['orientation'] )
				&& self::metadata_text_is_clean( $meta )
				&& self::serializable_array_ok( $meta )
				&& false === \has_filter( 'wp_read_image_metadata_types', $block_types_filter )
				&& false === \has_filter( 'wp_read_image_metadata_types', $allow_types_filter )
				&& false === \has_filter( 'wp_read_image_metadata', $meta_filter ),
			'EXIF fields populate camera/credit/timestamps/fractions and wp_read_image_metadata_types gates parsing locally',
			array(
				'blocked'             => $blocked,
				'metadata'            => $meta,
				'events'              => $events,
				'expectedTimestamp'   => $expected_timestamp,
				'blockTypesHasFilter' => \has_filter( 'wp_read_image_metadata_types', $block_types_filter ),
				'allowTypesHasFilter' => \has_filter( 'wp_read_image_metadata_types', $allow_types_filter ),
				'metaHasFilter'       => \has_filter( 'wp_read_image_metadata', $meta_filter ),
			)
		);

		return self::row(
			$ctx,
			'image-metadata.exif.fields-and-type-filter',
			array() === $failures,
			array(
				'events'   => $events,
				'metadata' => is_array( $meta ) ? self::preview_metadata( $meta ) : $meta,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_exif_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			'fraction'         => \wp_exif_frac2dec( '3/2' ),
			'decimal-string'   => \wp_exif_frac2dec( '12.5' ),
			'int'              => \wp_exif_frac2dec( 7 ),
			'zero-denominator' => \wp_exif_frac2dec( '5/0' ),
			'not-scalar'       => \wp_exif_frac2dec( array( 1, 2 ) ),
			'bool'             => \wp_exif_frac2dec( true ),
			'multiple-slashes' => \wp_exif_frac2dec( '1/2/3' ),
			'generated'        => \wp_exif_frac2dec( $ctx->int( 1, 50 ) . '/' . $ctx->int( 1, 50 ) ),
		);
		$date     = '2026:06:23 10:11:12';

		self::collect_failure(
			$failures,
			1.5 === $cases['fraction']
				&& 12.5 === $cases['decimal-string']
				&& 7 === $cases['int']
				&& 0 === $cases['zero-denominator']
				&& 0 === $cases['not-scalar']
				&& 0 === $cases['bool']
				&& 0 === $cases['multiple-slashes']
				&& is_numeric( $cases['generated'] )
				&& 0 <= $cases['generated']
				&& \wp_exif_date2ts( $date ) === strtotime( '2026-06-23 10:11:12' ),
			'EXIF fraction and date helpers normalize valid and invalid scalar inputs',
			array(
				'cases' => $cases,
				'date'  => \wp_exif_date2ts( $date ),
			)
		);

		return self::row(
			$ctx,
			'image-metadata.helpers.exif-frac-date',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function prepare_runtime(): void {
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz ImageMetadata';
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/image-metadata/';
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
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
			'server'  => $server,
		);
	}

	private static function restore_state( array $snapshot ): void {
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

	private static function restored_state_matches( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
			if ( $entry['exists'] && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $_SERVER ) ) {
				return false;
			}
			if ( $entry['exists'] && $_SERVER[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function default_metadata_shape_ok( array $meta ): bool {
		foreach (
			array(
				'aperture',
				'credit',
				'camera',
				'caption',
				'created_timestamp',
				'copyright',
				'focal_length',
				'iso',
				'shutter_speed',
				'title',
				'orientation',
				'keywords',
				'alt',
			) as $key
		) {
			if ( ! array_key_exists( $key, $meta ) ) {
				return false;
			}
		}

		return is_array( $meta['keywords'] )
			&& self::metadata_text_is_clean( $meta )
			&& self::bounded_numeric_metadata_ok( $meta );
	}

	private static function metadata_text_is_clean( array $meta ): bool {
		foreach ( array( 'title', 'caption', 'credit', 'copyright', 'camera', 'iso', 'alt' ) as $key ) {
			if ( ! isset( $meta[ $key ] ) || '' === $meta[ $key ] ) {
				continue;
			}

			if ( ! is_scalar( $meta[ $key ] ) ) {
				return false;
			}

			$value = (string) $meta[ $key ];
			if ( false !== strpos( $value, chr( 0 ) )
				|| str_contains( strtolower( $value ), '<script' )
				|| ! \wp_is_valid_utf8( $value )
			) {
				return false;
			}
		}

		foreach ( $meta['keywords'] ?? array() as $keyword ) {
			if ( ! is_scalar( $keyword ) ) {
				return false;
			}

			$value = (string) $keyword;
			if ( false !== strpos( $value, chr( 0 ) )
				|| str_contains( strtolower( $value ), '<script' )
				|| ! \wp_is_valid_utf8( $value )
			) {
				return false;
			}
		}

		return true;
	}

	private static function bounded_numeric_metadata_ok( array $meta ): bool {
		foreach ( array( 'aperture', 'created_timestamp', 'focal_length', 'orientation', 'shutter_speed' ) as $key ) {
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			if ( ! is_numeric( $meta[ $key ] ) || (float) $meta[ $key ] < 0 ) {
				return false;
			}
		}

		return true;
	}

	private static function serializable_array_ok( array $value ): bool {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $encoded ) {
			return false;
		}

		return \maybe_unserialize( \maybe_serialize( $value ) ) == $value; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
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
		$dir  = $base . DIRECTORY_SEPARATOR . 'component-fuzz-image-metadata-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration();

		if ( is_dir( $dir ) ) {
			self::remove_dir_recursive( $dir );
		}

		if ( mkdir( $dir, 0700, true ) || is_dir( $dir ) ) {
			return $dir;
		}

		return null;
	}

	private static function remove_dir_recursive( string $dir ): void {
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-image-metadata-';
		if ( ! str_starts_with( $dir, $temp_prefix ) || ! file_exists( $dir ) ) {
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

	private static function png_bytes( int $width, int $height ): string {
		return "\x89PNG\r\n\x1A\n"
			. self::png_chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) )
			. self::png_chunk( 'IEND', '' );
	}

	private static function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) )
			. $type
			. $data
			. pack( 'N', crc32( $type . $data ) );
	}

	private static function jpeg_bytes( array $segments, int $width, int $height ): string {
		return "\xFF\xD8"
			. implode( '', $segments )
			. self::jpeg_segment( 0xC0, "\x08" . pack( 'nn', $height, $width ) . "\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00" )
			. self::jpeg_segment( 0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00" )
			. "\x00\xFF\xD9";
	}

	private static function jpeg_segment( int $marker, string $payload ): string {
		return "\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	private static function jpeg_xmp_segment( string $alt_text ): string {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		return self::jpeg_xmp_alt_segment( array( 'x-default' => $alt_text ) );
	}

	private static function jpeg_xmp_alt_segment( array $alternatives ): string {
		$items = '';
		foreach ( $alternatives as $locale => $alt_text ) {
			$items .= '<rdf:li xml:lang="' . htmlspecialchars( (string) $locale, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '">'
				. htmlspecialchars( (string) $alt_text, ENT_XML1 | ENT_COMPAT, 'UTF-8' )
				. '</rdf:li>';
		}

		$xmp     = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/">'
			. '<Iptc4xmpCore:AltTextAccessibility><rdf:Alt>'
			. $items
			. '</rdf:Alt></Iptc4xmpCore:AltTextAccessibility>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';

		return self::jpeg_segment( 0xE1, "http://ns.adobe.com/xap/1.0/\x00" . $xmp );
	}

	private static function minimal_tiff_bytes( int $width, int $height ): string {
		$entries = array(
			self::tiff_short_entry( 0x0100, 4, $width ),
			self::tiff_short_entry( 0x0101, 4, $height ),
			self::tiff_short_entry( 0x0102, 3, 8 ),
			self::tiff_short_entry( 0x0103, 3, 1 ),
			self::tiff_short_entry( 0x0106, 3, 1 ),
			self::tiff_short_entry( 0x0111, 4, 0 ),
			self::tiff_short_entry( 0x0115, 3, 1 ),
			self::tiff_short_entry( 0x0116, 4, $height ),
			self::tiff_short_entry( 0x0117, 4, 0 ),
		);

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $a['tag'] <=> $b['tag'];
			}
		);

		$out = "II*\x00" . pack( 'V', 8 ) . pack( 'v', count( $entries ) );
		foreach ( $entries as $entry ) {
			$out .= pack( 'vvV', $entry['tag'], $entry['type'], 1 ) . $entry['value'];
		}
		return $out . pack( 'V', 0 );
	}

	private static function tiff_short_entry( int $tag, int $type, int $value ): array {
		return array(
			'tag'   => $tag,
			'type'  => $type,
			'value' => 3 === $type ? pack( 'v', $value ) . "\x00\x00" : pack( 'V', $value ),
		);
	}

	private static function iptc_app13_payload( array $fields ): string {
		$iptc = '';
		foreach ( $fields as $field ) {
			$value = (string) $field[1];
			$iptc .= "\x1C\x02" . chr( (int) $field[0] ) . pack( 'n', strlen( $value ) ) . $value;
		}

		$name     = "\x00\x00";
		$resource = '8BIM' . pack( 'n', 0x0404 ) . $name . pack( 'N', strlen( $iptc ) ) . $iptc;
		if ( 1 === strlen( $iptc ) % 2 ) {
			$resource .= "\x00";
		}

		return "Photoshop 3.0\x00" . $resource;
	}

	private static function exif_app1_payload( array $values ): string {
		$ifd0_entries = array(
			self::ifd_ascii_entry( 0x010E, (string) $values['description'] ),
			self::ifd_ascii_entry( 0x0110, (string) $values['model'] ),
			self::ifd_short_direct_entry( 0x0112, (int) $values['orientation'] ),
			self::ifd_ascii_entry( 0x013B, (string) $values['artist'] ),
			self::ifd_ascii_entry( 0x8298, (string) $values['copyright'] ),
		);

		$sub_ifd_entries = array(
			self::ifd_rational_entry( 0x829A, 1, 125 ),
			self::ifd_rational_entry( 0x829D, 56, 10 ),
			self::ifd_ascii_entry( 0x9004, (string) $values['date'] ),
			self::ifd_rational_entry( 0x920A, 350, 10 ),
			self::ifd_undefined_entry( 0x9286, "ASCII\x00\x00\x00" . (string) $values['userComment'] ),
		);

		$ifd0_base        = 8;
		$ifd0_count       = count( $ifd0_entries ) + 1;
		$ifd0_header_size = 2 + ( 12 * $ifd0_count ) + 4;
		$ifd0_data_size   = self::ifd_data_size( $ifd0_entries );
		$sub_ifd_offset   = $ifd0_base + $ifd0_header_size + $ifd0_data_size;
		$ifd0_entries[]   = self::ifd_long_direct_entry( 0x8769, $sub_ifd_offset );

		return "Exif\x00\x00II*\x00" . pack( 'V', $ifd0_base )
			. self::build_ifd( $ifd0_entries, $ifd0_base )
			. self::build_ifd( $sub_ifd_entries, $sub_ifd_offset );
	}

	private static function ifd_ascii_entry( int $tag, string $value ): array {
		return self::ifd_offset_entry( $tag, 2, $value . "\x00" );
	}

	private static function ifd_rational_entry( int $tag, int $numerator, int $denominator ): array {
		return array(
			'tag'   => $tag,
			'type'  => 5,
			'count' => 1,
			'value' => pack( 'VV', $numerator, max( 1, $denominator ) ),
		);
	}

	private static function ifd_undefined_entry( int $tag, string $value ): array {
		return self::ifd_offset_entry( $tag, 7, $value );
	}

	private static function ifd_offset_entry( int $tag, int $type, string $value ): array {
		return array(
			'tag'   => $tag,
			'type'  => $type,
			'count' => strlen( $value ),
			'value' => $value,
		);
	}

	private static function ifd_short_direct_entry( int $tag, int $value ): array {
		return array(
			'tag'    => $tag,
			'type'   => 3,
			'count'  => 1,
			'direct' => pack( 'v', $value ) . "\x00\x00",
		);
	}

	private static function ifd_long_direct_entry( int $tag, int $value ): array {
		return array(
			'tag'    => $tag,
			'type'   => 4,
			'count'  => 1,
			'direct' => pack( 'V', $value ),
		);
	}

	private static function build_ifd( array $entries, int $base_offset ): string {
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $a['tag'] <=> $b['tag'];
			}
		);

		$data_offset = $base_offset + 2 + ( 12 * count( $entries ) ) + 4;
		$data        = '';
		$out         = pack( 'v', count( $entries ) );

		foreach ( $entries as $entry ) {
			if ( isset( $entry['direct'] ) ) {
				$value = $entry['direct'];
			} elseif ( strlen( $entry['value'] ) <= 4 ) {
				$value = str_pad( $entry['value'], 4, "\x00" );
			} else {
				$value = pack( 'V', $data_offset + strlen( $data ) );
				$data .= $entry['value'];
				if ( 1 === strlen( $data ) % 2 ) {
					$data .= "\x00";
				}
			}

			$out .= pack( 'vvV', $entry['tag'], $entry['type'], $entry['count'] ) . $value;
		}

		return $out . pack( 'V', 0 ) . $data;
	}

	private static function ifd_data_size( array $entries ): int {
		$size = 0;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['direct'] ) || strlen( $entry['value'] ) <= 4 ) {
				continue;
			}
			$size += strlen( $entry['value'] );
			if ( 1 === $size % 2 ) {
				$size++;
			}
		}

		return $size;
	}

	private static function preview_metadata_map( array $metadata ): array {
		$out = array();
		foreach ( $metadata as $key => $value ) {
			$out[ $key ] = is_array( $value ) ? self::preview_metadata( $value ) : $value;
		}
		return $out;
	}

	private static function preview_metadata( array $metadata ): array {
		$out = array();
		foreach ( $metadata as $key => $value ) {
			$out[ $key ] = \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES );
		}
		return $out;
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
