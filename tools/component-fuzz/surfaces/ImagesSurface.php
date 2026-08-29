<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes image dimension math and responsive image markup helpers.
 */
final class ImagesSurface {
	public const NAME = 'images';

	private const CASES         = 24;
	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'images.bootstrap-apis-available',
					'Required WordPress image APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			$rows[] = self::check_constrain_dimensions( $ctx );
			$rows[] = self::check_resize_dimensions( $ctx );
			$rows[] = self::check_intermediate_size_helpers( $ctx );
			$rows[] = self::check_srcset_and_sizes( $ctx );
			$rows[] = self::check_responsive_filter_boundaries( $ctx );
			$rows[] = self::check_image_tag_attributes( $ctx );
			$rows[] = self::check_content_tag_filter_pipeline( $ctx );
			$rows[] = self::check_loading_optimization_attributes( $ctx );
			$rows[] = self::check_attachment_helpers( $ctx );
			$rows[] = self::check_filetype_helpers( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'images.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'image_resize_dimensions',
				'image_get_intermediate_size',
				'image_hwstring',
				'get_intermediate_image_sizes',
				'wp_check_filetype',
				'wp_calculate_image_sizes',
				'wp_calculate_image_srcset',
				'wp_constrain_dimensions',
				'wp_ext2type',
				'wp_get_default_extension_for_mime_type',
				'wp_get_attachment_image',
				'wp_get_attachment_image_sizes',
				'wp_get_attachment_image_src',
				'wp_get_attachment_image_srcset',
				'wp_get_attachment_image_url',
				'wp_get_loading_optimization_attributes',
				'wp_get_mime_types',
				'wp_image_add_srcset_and_sizes',
				'wp_image_file_matches_image_meta',
				'wp_image_src_get_dimensions',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function missing_content_tag_requirements(): array {
		$missing = array();
		foreach (
			array(
				'wp_filter_content_tags',
				'wp_iframe_tag_add_loading_attr',
				'wp_img_tag_add_auto_sizes',
				'wp_img_tag_add_loading_optimization_attrs',
				'wp_img_tag_add_srcset_and_sizes_attr',
				'wp_img_tag_add_width_and_height_attr',
				'wp_sizes_attribute_includes_valid_auto',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			$missing[] = 'class WP_HTML_Tag_Processor';
		}

		return $missing;
	}

	private static function check_constrain_dimensions( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		foreach ( self::dimension_cases( $ctx->fork( 'constrain' ) ) as $index => $case ) {
			$result = \wp_constrain_dimensions( $case['width'], $case['height'], $case['maxWidth'], $case['maxHeight'] );
			$repeat = \wp_constrain_dimensions( $result[0], $result[1], $case['maxWidth'], $case['maxHeight'] );

			$bounded_width  = 0 >= $case['maxWidth'] || $result[0] <= max( 1, $case['maxWidth'] );
			$bounded_height = 0 >= $case['maxHeight'] || $result[1] <= max( 1, $case['maxHeight'] );
			$not_upscaled   = $result[0] <= max( 1, $case['width'] ) && $result[1] <= max( 1, $case['height'] );
			$aspect_ok      = self::aspect_close( $case['width'], $case['height'], $result[0], $result[1] );

			self::collect_failure(
				$failures,
				is_array( $result )
					&& 2 === count( $result )
					&& $result === $repeat
					&& $result[0] >= 1
					&& $result[1] >= 1
					&& $bounded_width
					&& $bounded_height
					&& $not_upscaled
					&& $aspect_ok,
				"wp_constrain_dimensions bounded idempotence case {$index}",
				array(
					'case'   => $case,
					'result' => $result,
					'repeat' => $repeat,
				)
			);
		}

		return self::row(
			$ctx,
			'images.dimensions.constrain-bounded-idempotent',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_resize_dimensions( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$crops    = array( false, true, array( 'left', 'top' ), array( 'right', 'bottom' ), array( 'center', 'center' ) );

		foreach ( self::resize_cases( $ctx->fork( 'resize' ) ) as $index => $case ) {
			foreach ( $crops as $crop ) {
				$result = \image_resize_dimensions( $case['origW'], $case['origH'], $case['destW'], $case['destH'], $crop );
				if ( false === $result ) {
					self::collect_failure(
						$failures,
						self::resize_expected_false( $case, $crop ),
						"image_resize_dimensions false only for upscale/impossible case {$index}",
						array(
							'case' => $case,
							'crop' => $crop,
						)
					);
					continue;
				}

				list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $result;
				$expected = self::expected_resize_result( $case, $crop );
				self::collect_failure(
					$failures,
					0 === $dst_x
						&& 0 === $dst_y
						&& $src_x >= 0
						&& $src_y >= 0
						&& $dst_w >= 1
						&& $dst_h >= 1
						&& $src_w >= 1
						&& $src_h >= 1
						&& $src_x + $src_w <= $case['origW']
						&& $src_y + $src_h <= $case['origH']
						&& $dst_w <= max( $case['destW'], $case['origW'] )
						&& $dst_h <= max( $case['destH'], $case['origH'] )
						&& $expected === $result,
					"image_resize_dimensions crop bounds case {$index}",
					array(
						'case'     => $case,
						'crop'     => $crop,
						'result'   => $result,
						'expected' => $expected,
					)
				);
			}
		}

		$sentinel = array( 0, 0, 1, 2, 3, 4, 5, 6 );
		$filter   = static function ( $output, int $orig_w, int $orig_h, int $dest_w, int $dest_h, $crop ) use ( $sentinel ) {
			if ( 777 === $orig_w && 555 === $orig_h && 333 === $dest_w && 222 === $dest_h && false === $crop ) {
				return $sentinel;
			}

			return $output;
		};

		\add_filter( 'image_resize_dimensions', $filter, 10, 6 );
		try {
			$filtered = \image_resize_dimensions( 777, 555, 333, 222, false );
		} finally {
			\remove_filter( 'image_resize_dimensions', $filter, 10 );
		}
		$after_filter = \image_resize_dimensions( 777, 555, 333, 222, false );

		self::collect_failure(
			$failures,
			$sentinel === $filtered && $sentinel !== $after_filter,
			'image_resize_dimensions short-circuit filter is local and removable',
			array(
				'filtered'    => $filtered,
				'afterFilter' => $after_filter,
			)
		);

		return self::row(
			$ctx,
			'images.resize.crop-coordinates-bounded',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_intermediate_size_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$attachment_id = 200000 + $ctx->iteration();
		$meta          = self::generated_image_meta( $ctx->fork( 'intermediate-meta' ) );
		$medium        = $meta['sizes']['medium'];
		$large         = $meta['sizes']['large'];
		$filtered_name = 'component-fuzz-' . $ctx->int( 100, 999 );

		$meta_filter = static function ( $value, int $object_id, string $meta_key, bool $single ) use ( $attachment_id, $meta ) {
			if ( $attachment_id !== $object_id || '_wp_attachment_metadata' !== $meta_key || ! $single ) {
				return $value;
			}

			return array( $meta );
		};
		$size_filter = static function ( array $sizes ) use ( $filtered_name ): array {
			$sizes[] = $filtered_name;
			return $sizes;
		};
		$output_filter = static function ( $data, int $post_id, $size ) use ( $attachment_id ) {
			if ( $attachment_id === $post_id && 'medium' === $size && is_array( $data ) ) {
				$data['component_fuzz_filtered'] = 'yes';
			}

			return $data;
		};

		\add_filter( 'get_post_metadata', $meta_filter, 10, 4 );
		\add_filter( 'intermediate_image_sizes', $size_filter );
		\add_filter( 'image_get_intermediate_size', $output_filter, 10, 3 );
		try {
			$exact     = \image_get_intermediate_size( $attachment_id, 'medium' );
			$array     = \image_get_intermediate_size( $attachment_id, array( 0, $large['height'] ) );
			$missing   = \image_get_intermediate_size( $attachment_id, 'not-a-size' );
			$size_list = \get_intermediate_image_sizes();
		} finally {
			\remove_filter( 'image_get_intermediate_size', $output_filter, 10 );
			\remove_filter( 'intermediate_image_sizes', $size_filter );
			\remove_filter( 'get_post_metadata', $meta_filter, 10 );
		}

		$after_exact     = \image_get_intermediate_size( $attachment_id, 'medium' );
		$after_size_list = \get_intermediate_image_sizes();

		self::collect_failure(
			$failures,
			is_array( $exact )
				&& $medium['file'] === $exact['file']
				&& $medium['width'] === $exact['width']
				&& $medium['height'] === $exact['height']
				&& $medium['path'] === $exact['path']
				&& $medium['url'] === $exact['url']
				&& 'yes' === ( $exact['component_fuzz_filtered'] ?? null )
				&& is_array( $array )
				&& $large['file'] === $array['file']
				&& $large['height'] === $array['height']
				&& false === $missing
				&& in_array( $filtered_name, $size_list, true )
				&& false === $after_exact
				&& ! in_array( $filtered_name, $after_size_list, true ),
			'image_get_intermediate_size uses synthetic metadata and local filters',
			array(
				'meta'          => $meta,
				'exact'         => $exact,
				'array'         => $array,
				'missing'       => $missing,
				'sizeList'      => $size_list,
				'afterExact'    => $after_exact,
				'afterSizeList' => $after_size_list,
			)
		);

		return self::row(
			$ctx,
			'images.intermediate.synthetic-metadata-filters',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_srcset_and_sizes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$meta     = self::image_meta( $ctx );
		$src      = 'https://example.test/wp-content/uploads/2026/06/photo-600x400.jpg';
		$srcset   = \wp_calculate_image_srcset( array( 600, 400 ), $src, $meta, 0 );
		$sizes    = \wp_calculate_image_sizes( array( 600, 400 ), $src, $meta, 0 );
		$full_dim = \wp_image_src_get_dimensions( 'https://example.test/wp-content/uploads/2026/06/photo.jpg', $meta, 0 );
		$med_dim  = \wp_image_src_get_dimensions( $src, $meta, 0 );
		$missing  = \wp_image_src_get_dimensions( 'https://example.test/wp-content/uploads/2026/06/missing.jpg', $meta, 0 );
		$generated_meta   = self::generated_image_meta( $ctx->fork( 'srcset-meta' ) );
		$generated_medium = $generated_meta['sizes']['medium'];
		$generated_src    = self::image_url( $generated_meta, 'medium' );
		$generated_srcset = \wp_calculate_image_srcset(
			array( $generated_medium['width'], $generated_medium['height'] ),
			$generated_src,
			$generated_meta,
			0
		);
		$generated_sizes  = \wp_calculate_image_sizes( 'medium', $generated_src, $generated_meta, 0 );
		$generated_dim    = \wp_image_src_get_dimensions( $generated_src, $generated_meta, 0 );
		$generated_query_dim = \wp_image_src_get_dimensions( $generated_src . '?cache=1', $generated_meta, 0 );

		$candidates = is_string( $srcset ) ? self::parse_srcset_widths( $srcset ) : array();
		$generated_candidates = is_string( $generated_srcset ) ? self::parse_srcset_widths( $generated_srcset ) : array();
		self::collect_failure(
			$failures,
			is_string( $srcset )
				&& count( $candidates ) >= 2
				&& isset( $candidates[300], $candidates[600], $candidates[1200] )
				&& count( $candidates ) === count( array_unique( array_keys( $candidates ) ) )
				&& is_string( $sizes )
				&& '(max-width: 600px) 100vw, 600px' === $sizes
				&& array( 1200, 800 ) === $full_dim
				&& array( 600, 400 ) === $med_dim
				&& false === $missing
				&& is_string( $generated_srcset )
				&& self::expected_srcset_widths_present( $generated_meta, $generated_candidates, $generated_medium['width'], $generated_medium['height'] )
				&& sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $generated_medium['width'] ) === $generated_sizes
				&& array( $generated_medium['width'], $generated_medium['height'] ) === $generated_dim
				&& false === $generated_query_dim,
			'wp_calculate_image_srcset/sizes and metadata dimensions agree',
			array(
				'srcset'              => $srcset,
				'candidates'          => $candidates,
				'sizes'               => $sizes,
				'fullDim'             => $full_dim,
				'medDim'              => $med_dim,
				'missing'             => $missing,
				'generatedSrcset'     => $generated_srcset,
				'generatedCandidates' => $generated_candidates,
				'generatedSizes'      => $generated_sizes,
				'generatedDim'        => $generated_dim,
				'generatedQueryDim'   => $generated_query_dim,
			)
		);

		return self::row(
			$ctx,
			'images.responsive.srcset-sizes-dimensions',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_responsive_filter_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$attachment_id = 300000 + $ctx->iteration();
		$meta          = self::generated_image_meta( $ctx->fork( 'responsive-boundary-meta' ) );
		$medium        = $meta['sizes']['medium'];
		$large         = $meta['sizes']['large'];
		$src           = self::image_url( $meta, 'medium' );

		$max_width_filter = static fn() => $medium['width'];
		\add_filter( 'max_srcset_image_width', $max_width_filter );
		try {
			$limited = \wp_calculate_image_srcset( array( $medium['width'], $medium['height'] ), $src, $meta, $attachment_id );
		} finally {
			\remove_filter( 'max_srcset_image_width', $max_width_filter );
		}

		$meta_filter = static function ( array $image_meta, array $size_array, string $image_src, int $id ) use ( $attachment_id ): array {
			if ( $attachment_id === $id ) {
				$image_meta['sizes'] = array();
			}

			return $image_meta;
		};
		\add_filter( 'wp_calculate_image_srcset_meta', $meta_filter, 10, 4 );
		try {
			$blocked = \wp_calculate_image_srcset( array( $medium['width'], $medium['height'] ), $src, $meta, $attachment_id );
		} finally {
			\remove_filter( 'wp_calculate_image_srcset_meta', $meta_filter, 10 );
		}

		$size_filter = static fn() => '100vw';
		\add_filter( 'wp_calculate_image_sizes', $size_filter );
		try {
			$filtered_sizes = \wp_calculate_image_sizes( array( $medium['width'], $medium['height'] ), $src, $meta, $attachment_id );
		} finally {
			\remove_filter( 'wp_calculate_image_sizes', $size_filter );
		}
		$after_filtered_sizes = \wp_calculate_image_sizes( array( $medium['width'], $medium['height'] ), $src, $meta, $attachment_id );

		$gif_meta = $meta;
		foreach ( $gif_meta['sizes'] as &$size_data ) {
			$size_data['mime-type'] = 'image/gif';
			$size_data['file']      = preg_replace( '/\.jpg$/', '.gif', $size_data['file'] );
			$size_data['path']      = preg_replace( '/\.jpg$/', '.gif', $size_data['path'] );
			$size_data['url']       = preg_replace( '/\.jpg$/', '.gif', $size_data['url'] );
		}
		unset( $size_data );
		$gif_meta['file']           = preg_replace( '/\.jpg$/', '.gif', $gif_meta['file'] );
		$gif_meta['original_image'] = preg_replace( '/\.jpg$/', '.gif', $gif_meta['original_image'] );
		$gif_full_src               = self::image_url( $gif_meta, null );
		$gif_medium_src             = self::image_url( $gif_meta, 'medium' );
		$gif_full_srcset            = \wp_calculate_image_srcset( array( $gif_meta['width'], $gif_meta['height'] ), $gif_full_src, $gif_meta, 0 );
		$gif_medium_srcset          = \wp_calculate_image_srcset( array( $medium['width'], $medium['height'] ), $gif_medium_src, $gif_meta, 0 );

		$limited_widths = is_string( $limited ) ? self::parse_srcset_widths( $limited ) : array();
		$gif_widths     = is_string( $gif_medium_srcset ) ? self::parse_srcset_widths( $gif_medium_srcset ) : array();
		self::collect_failure(
			$failures,
			is_string( $limited )
				&& isset( $limited_widths[ $medium['width'] ] )
				&& ! isset( $limited_widths[ $large['width'] ] )
				&& false === $blocked
				&& '100vw' === $filtered_sizes
				&& sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $medium['width'] ) === $after_filtered_sizes
				&& false === $gif_full_srcset
				&& is_string( $gif_medium_srcset )
				&& isset( $gif_widths[ $medium['width'] ] )
				&& ! isset( $gif_widths[ $gif_meta['width'] ] ),
			'responsive image filters, width caps, and GIF animation boundaries hold',
			array(
				'limited'            => $limited,
				'limitedWidths'      => $limited_widths,
				'blocked'            => $blocked,
				'filteredSizes'      => $filtered_sizes,
				'afterFilteredSizes' => $after_filtered_sizes,
				'gifFullSrcset'      => $gif_full_srcset,
				'gifMediumSrcset'    => $gif_medium_srcset,
				'gifWidths'          => $gif_widths,
			)
		);

		return self::row(
			$ctx,
			'images.responsive.filters-gif-boundaries',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_image_tag_attributes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$meta     = self::image_meta( $ctx );
		$image    = '<img class="wp-image-10" src="https://example.test/wp-content/uploads/2026/06/photo-600x400.jpg" width="600" height="400" alt="Example">';
		$first    = \wp_image_add_srcset_and_sizes( $image, $meta, 10 );
		$existing = \wp_image_add_srcset_and_sizes( str_replace( ' alt=', ' sizes="100vw" alt=', $image ), $meta, 10 );
		$derived  = \wp_image_add_srcset_and_sizes( str_replace( ' width="600" height="400"', '', $image ), $meta, 10 );
		$single_quoted = \wp_image_add_srcset_and_sizes(
			"<img class='wp-image-10' src='https://example.test/wp-content/uploads/2026/06/photo-600x400.jpg' width='600' height='400' alt='Single'>",
			$meta,
			10
		);

		self::collect_failure(
			$failures,
			is_string( $first )
				&& str_contains( $first, ' srcset=' )
				&& str_contains( $first, ' sizes=' )
				&& 1 === substr_count( $first, ' srcset=' )
				&& 1 === substr_count( $first, ' sizes=' )
				&& str_contains( $first, 'photo-300x200.jpg 300w' )
				&& str_contains( $first, 'photo-600x400.jpg 600w' )
				&& str_contains( $first, 'photo.jpg 1200w' )
				&& 1 === substr_count( $existing, ' sizes=' )
				&& 1 === substr_count( $existing, ' srcset=' )
				&& str_contains( $existing, 'sizes="100vw"' )
				&& str_contains( $derived, ' srcset=' )
				&& str_contains( $derived, ' sizes=' )
				&& ! str_contains( $derived, ' width=' )
				&& $single_quoted === "<img class='wp-image-10' src='https://example.test/wp-content/uploads/2026/06/photo-600x400.jpg' width='600' height='400' alt='Single'>",
			'wp_image_add_srcset_and_sizes inserts responsive attrs without clobbering sizes',
			array(
				'first'        => self::describe_string( $first ),
				'existing'     => self::describe_string( $existing ),
				'derived'      => self::describe_string( $derived ),
				'singleQuoted' => self::describe_string( $single_quoted ),
			)
		);

		return self::row(
			$ctx,
			'images.markup.srcset-sizes-insertion',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_content_tag_filter_pipeline( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_content_tag_requirements();
		if ( array() !== $missing ) {
			return self::skip(
				$ctx,
				'images.content-tags.direct-helper-pipeline',
				'Required WordPress content tag image APIs are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$failures      = array();
		$attachment_id = 400000 + $ctx->iteration();
		$meta          = self::generated_image_meta( $ctx->fork( 'content-tag-meta' ) );
		$medium        = $meta['sizes']['medium'];
		$medium_src    = self::image_url( $meta, 'medium' );
		$context       = 'component-fuzz-content-tags';

		if ( class_exists( '\WP_Query' ) && ! isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query'] = new \WP_Query();
		}

		$valid_auto_cases = array(
			'auto, (max-width: 600px) 100vw, 600px',
			" \tAuTo\r\n, 100vw",
		);
		$invalid_auto_cases = array(
			'(max-width: 600px) 100vw, auto',
			'automatic, 100vw',
			'auto 100vw, 50vw',
		);

		$auto_results = array();
		foreach ( $valid_auto_cases as $case ) {
			$auto_results[ $case ] = \wp_sizes_attribute_includes_valid_auto( $case );
		}
		foreach ( $invalid_auto_cases as $case ) {
			$auto_results[ $case ] = \wp_sizes_attribute_includes_valid_auto( $case );
		}

		self::collect_failure(
			$failures,
			array_fill_keys( $valid_auto_cases, true ) + array_fill_keys( $invalid_auto_cases, false ) === $auto_results,
			'wp_sizes_attribute_includes_valid_auto only accepts leading auto token',
			array( 'results' => $auto_results )
		);

		$auto_base = '<img src="http://example.test/image.jpg" loading="lazy" width="640" sizes="(max-width: 640px) 100vw, 640px" alt="Auto">';
		$auto_added = \wp_img_tag_add_auto_sizes( $auto_base );
		$auto_attrs = self::tag_attributes( $auto_added, 'img' );

		$auto_eager    = str_replace( 'loading="lazy"', 'loading="eager"', $auto_base );
		$auto_no_width = str_replace( ' width="640"', '', $auto_base );
		$auto_no_sizes = str_replace( ' sizes="(max-width: 640px) 100vw, 640px"', '', $auto_base );
		$auto_existing = '<img src="http://example.test/image.jpg" loading="lazy" width="640" sizes=" AUTO, 100vw" alt="Auto">';

		$auto_disabled_filter = static fn() => false;
		\add_filter( 'wp_img_tag_add_auto_sizes', $auto_disabled_filter );
		try {
			$auto_disabled = \wp_img_tag_add_auto_sizes( $auto_base );
		} finally {
			\remove_filter( 'wp_img_tag_add_auto_sizes', $auto_disabled_filter );
		}

		self::collect_failure(
			$failures,
			'auto, (max-width: 640px) 100vw, 640px' === ( $auto_attrs['sizes'] ?? null )
				&& 1 === substr_count( strtolower( (string) ( $auto_attrs['sizes'] ?? '' ) ), 'auto' )
				&& 1 === self::tag_attribute_count( $auto_added, 'sizes' )
				&& $auto_eager === \wp_img_tag_add_auto_sizes( $auto_eager )
				&& $auto_no_width === \wp_img_tag_add_auto_sizes( $auto_no_width )
				&& $auto_no_sizes === \wp_img_tag_add_auto_sizes( $auto_no_sizes )
				&& $auto_existing === \wp_img_tag_add_auto_sizes( $auto_existing )
				&& $auto_base === $auto_disabled
				&& false === \has_filter( 'wp_img_tag_add_auto_sizes', $auto_disabled_filter ),
			'wp_img_tag_add_auto_sizes is gated by lazy loading, width, sizes, existing auto, and filter',
			array(
				'autoAdded'       => self::describe_string( $auto_added ),
				'autoAttrs'       => $auto_attrs,
				'autoDisabled'    => self::describe_string( $auto_disabled ),
				'disabledHasHook' => \has_filter( 'wp_img_tag_add_auto_sizes', $auto_disabled_filter ),
			)
		);

		$content_img_calls    = array();
		$iframe_loading_calls = array();
		$img_loading_contexts = array(
			'component-fuzz-img-loading',
			'component-fuzz-img-loading-missing-dimensions',
			'component-fuzz-img-loading-omit-decoding',
			'component-fuzz-img-loading-auto-decoding',
			'component-fuzz-img-loading-omit-loading',
			'component-fuzz-img-loading-eager-loading',
			$context,
		);
		$iframe_contexts      = array(
			'component-fuzz-iframe-loading',
			'component-fuzz-iframe-loading-invalid',
			$context,
		);

		$meta_filter = static function ( $value, int $object_id, string $meta_key, bool $single ) use ( $attachment_id, $meta ) {
			if ( $attachment_id !== $object_id || '_wp_attachment_metadata' !== $meta_key || ! $single ) {
				return $value;
			}

			return array( $meta );
		};

		$lazy_enabled_filter = static function ( bool $default, string $tag_name, string $filter_context ) use ( $img_loading_contexts, $iframe_contexts ): bool {
			if ( 'img' === $tag_name && in_array( $filter_context, $img_loading_contexts, true ) ) {
				return true;
			}

			if ( 'iframe' === $tag_name && in_array( $filter_context, $iframe_contexts, true ) ) {
				return true;
			}

			return $default;
		};

		$decoding_attr_filter = static function ( $value, string $image, string $filter_context ) {
			unset( $image );

			if ( 'component-fuzz-img-loading-omit-decoding' === $filter_context ) {
				return false;
			}

			if ( 'component-fuzz-img-loading-auto-decoding' === $filter_context ) {
				return 'auto';
			}

			return $value;
		};

		$img_loading_attr_filter = static function ( $value, string $image, string $filter_context ) {
			unset( $image );

			if ( 'component-fuzz-img-loading-omit-loading' === $filter_context ) {
				return false;
			}

			if ( 'component-fuzz-img-loading-eager-loading' === $filter_context ) {
				return 'eager';
			}

			return $value;
		};

		$iframe_loading_attr_filter = static function ( $value, string $iframe, string $filter_context ) use ( &$iframe_loading_calls, $context ) {
			if ( $context === $filter_context ) {
				$iframe_loading_calls[] = $iframe;
			}

			if ( 'component-fuzz-iframe-loading-invalid' === $filter_context ) {
				return 'invalid';
			}

			return $value;
		};

		$content_img_filter = static function ( string $filtered_image, string $filter_context, int $image_attachment_id ) use ( &$content_img_calls, $context ): string {
			if ( $context !== $filter_context ) {
				return $filtered_image;
			}

			$content_img_calls[] = array(
				'attachmentId' => $image_attachment_id,
				'image'        => $filtered_image,
			);

			return str_replace( '<img', '<img data-content-filtered="' . $image_attachment_id . '"', $filtered_image );
		};

		\add_filter( 'get_post_metadata', $meta_filter, 10, 4 );
		\add_filter( 'wp_lazy_loading_enabled', $lazy_enabled_filter, 10, 3 );
		\add_filter( 'wp_img_tag_add_decoding_attr', $decoding_attr_filter, 10, 3 );
		\add_filter( 'wp_img_tag_add_loading_attr', $img_loading_attr_filter, 10, 3 );
		\add_filter( 'wp_iframe_tag_add_loading_attr', $iframe_loading_attr_filter, 10, 3 );
		\add_filter( 'wp_content_img_tag', $content_img_filter, 10, 3 );
		try {
			$width_tag       = '<img class="wp-image-' . $attachment_id . '" src="' . $medium_src . '?cache=1" alt="Attachment">';
			$width_added     = \wp_img_tag_add_width_and_height_attr( $width_tag, 'component-fuzz-width-height', $attachment_id );
			$width_attrs     = self::tag_attributes( $width_added, 'img' );
			$style_width     = max( 1, (int) round( $medium['width'] / 3 ) );
			$style_height    = (int) round( $medium['height'] * $style_width / $medium['width'] );
			$style_tag       = '<img class="wp-image-' . $attachment_id . '" src="' . $medium_src . '" style="width: ' . $style_width . 'px;" alt="Styled">';
			$style_added     = \wp_img_tag_add_width_and_height_attr( $style_tag, 'component-fuzz-width-height-style', $attachment_id );
			$style_attrs     = self::tag_attributes( $style_added, 'img' );
			$single_src_tag  = "<img class='wp-image-{$attachment_id}' src='{$medium_src}' alt='Single'>";
			$unknown_src_tag = '<img class="wp-image-' . $attachment_id . '" src="http://example.test/wp-content/uploads/2026/06/not-found.jpg" alt="Unknown">';

			$disable_width_filter = static fn() => false;
			\add_filter( 'wp_img_tag_add_width_and_height_attr', $disable_width_filter );
			try {
				$width_disabled = \wp_img_tag_add_width_and_height_attr( $width_tag, 'component-fuzz-width-height-disabled', $attachment_id );
			} finally {
				\remove_filter( 'wp_img_tag_add_width_and_height_attr', $disable_width_filter );
			}

			self::collect_failure(
				$failures,
				(string) $medium['width'] === ( $width_attrs['width'] ?? null )
					&& (string) $medium['height'] === ( $width_attrs['height'] ?? null )
					&& (string) $style_width === ( $style_attrs['width'] ?? null )
					&& (string) $style_height === ( $style_attrs['height'] ?? null )
					&& $single_src_tag === \wp_img_tag_add_width_and_height_attr( $single_src_tag, 'component-fuzz-width-height-single-src', $attachment_id )
					&& $unknown_src_tag === \wp_img_tag_add_width_and_height_attr( $unknown_src_tag, 'component-fuzz-width-height-unknown-src', $attachment_id )
					&& $width_tag === $width_disabled
					&& false === \has_filter( 'wp_img_tag_add_width_and_height_attr', $disable_width_filter ),
				'wp_img_tag_add_width_and_height_attr uses metadata, style scaling, double-quoted src, and filter',
				array(
					'widthAdded'      => self::describe_string( $width_added ),
					'widthAttrs'      => $width_attrs,
					'styleAdded'      => self::describe_string( $style_added ),
					'styleAttrs'      => $style_attrs,
					'widthDisabled'   => self::describe_string( $width_disabled ),
					'disabledHasHook' => \has_filter( 'wp_img_tag_add_width_and_height_attr', $disable_width_filter ),
				)
			);

			$srcset_tag   = '<img class="wp-image-' . $attachment_id . '" src="' . $medium_src . '" width="' . $medium['width'] . '" height="' . $medium['height'] . '" alt="Responsive">';
			$srcset_added = \wp_img_tag_add_srcset_and_sizes_attr( $srcset_tag, 'component-fuzz-srcset-helper', $attachment_id );
			$srcset_attrs = self::tag_attributes( $srcset_added, 'img' );
			$srcset_widths = isset( $srcset_attrs['srcset'] ) && is_string( $srcset_attrs['srcset'] )
				? self::parse_srcset_widths( $srcset_attrs['srcset'] )
				: array();
			$srcset_urls = isset( $srcset_attrs['srcset'] ) && is_string( $srcset_attrs['srcset'] )
				? self::parse_srcset_width_urls( $srcset_attrs['srcset'] )
				: array();

			$disable_srcset_filter = static fn() => false;
			\add_filter( 'wp_img_tag_add_srcset_and_sizes_attr', $disable_srcset_filter );
			try {
				$srcset_disabled = \wp_img_tag_add_srcset_and_sizes_attr( $srcset_tag, 'component-fuzz-srcset-helper-disabled', $attachment_id );
			} finally {
				\remove_filter( 'wp_img_tag_add_srcset_and_sizes_attr', $disable_srcset_filter );
			}

			self::collect_failure(
				$failures,
					isset( $srcset_attrs['srcset'], $srcset_attrs['sizes'] )
						&& is_string( $srcset_attrs['srcset'] )
						&& self::expected_srcset_widths_present( $meta, $srcset_widths, (int) $medium['width'], (int) $medium['height'] )
						&& self::expected_srcset_urls_present( $meta, $srcset_urls, (int) $medium['width'], (int) $medium['height'] )
						&& sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $medium['width'] ) === $srcset_attrs['sizes']
						&& self::tag_has_unique_attributes( $srcset_added, array( 'srcset', 'sizes' ) )
						&& $srcset_tag === $srcset_disabled
					&& false === \has_filter( 'wp_img_tag_add_srcset_and_sizes_attr', $disable_srcset_filter ),
				'wp_img_tag_add_srcset_and_sizes_attr adds responsive attrs from metadata and obeys filter',
				array(
					'srcsetAdded'     => self::describe_string( $srcset_added ),
					'srcsetAttrs'     => $srcset_attrs,
					'srcsetWidths'    => $srcset_widths,
					'srcsetUrls'      => $srcset_urls,
					'srcsetDisabled'  => self::describe_string( $srcset_disabled ),
					'disabledHasHook' => \has_filter( 'wp_img_tag_add_srcset_and_sizes_attr', $disable_srcset_filter ),
				)
			);

			$loading_tag        = '<img src="http://example.test/loading.jpg" width="640" height="480" alt="Loading">';
			$loading_added      = \wp_img_tag_add_loading_optimization_attrs( $loading_tag, 'component-fuzz-img-loading' );
			$loading_attrs      = self::tag_attributes( $loading_added, 'img' );
			$missing_dim_added  = \wp_img_tag_add_loading_optimization_attrs( '<img src="http://example.test/loading.jpg" alt="Loading">', 'component-fuzz-img-loading-missing-dimensions' );
			$missing_dim_attrs  = self::tag_attributes( $missing_dim_added, 'img' );
			$existing_loading   = '<img src="http://example.test/loading.jpg" width="640" height="480" loading="eager" decoding="sync" fetchpriority="low" alt="Loading">';
			$existing_added     = \wp_img_tag_add_loading_optimization_attrs( $existing_loading, 'component-fuzz-img-loading' );
			$existing_attrs     = self::tag_attributes( $existing_added, 'img' );
			$omit_decoding      = \wp_img_tag_add_loading_optimization_attrs( $loading_tag, 'component-fuzz-img-loading-omit-decoding' );
			$omit_decoding_attrs = self::tag_attributes( $omit_decoding, 'img' );
			$auto_decoding      = \wp_img_tag_add_loading_optimization_attrs( $loading_tag, 'component-fuzz-img-loading-auto-decoding' );
			$auto_decoding_attrs = self::tag_attributes( $auto_decoding, 'img' );
			$omit_loading       = \wp_img_tag_add_loading_optimization_attrs( $loading_tag, 'component-fuzz-img-loading-omit-loading' );
			$omit_loading_attrs = self::tag_attributes( $omit_loading, 'img' );
			$eager_loading      = \wp_img_tag_add_loading_optimization_attrs( $loading_tag, 'component-fuzz-img-loading-eager-loading' );
			$eager_loading_attrs = self::tag_attributes( $eager_loading, 'img' );
			$single_quote_loading = '<img src=\'http://example.test/loading.jpg\' width="640" height="480" alt="Loading">';

			self::collect_failure(
				$failures,
				'async' === ( $loading_attrs['decoding'] ?? null )
					&& 'lazy' === ( $loading_attrs['loading'] ?? null )
					&& 'async' === ( $missing_dim_attrs['decoding'] ?? null )
					&& ! isset( $missing_dim_attrs['loading'] )
					&& 'eager' === ( $existing_attrs['loading'] ?? null )
					&& 'sync' === ( $existing_attrs['decoding'] ?? null )
					&& 'low' === ( $existing_attrs['fetchpriority'] ?? null )
					&& ! isset( $omit_decoding_attrs['decoding'] )
					&& 'lazy' === ( $omit_decoding_attrs['loading'] ?? null )
					&& 'auto' === ( $auto_decoding_attrs['decoding'] ?? null )
					&& 'lazy' === ( $auto_decoding_attrs['loading'] ?? null )
					&& 'async' === ( $omit_loading_attrs['decoding'] ?? null )
					&& ! isset( $omit_loading_attrs['loading'] )
					&& 'async' === ( $eager_loading_attrs['decoding'] ?? null )
					&& 'eager' === ( $eager_loading_attrs['loading'] ?? null )
					&& $single_quote_loading === \wp_img_tag_add_loading_optimization_attrs( $single_quote_loading, 'component-fuzz-img-loading' )
					&& self::tag_has_unique_attributes( $loading_added, array( 'loading', 'decoding', 'fetchpriority' ) )
					&& self::tag_has_unique_attributes( $existing_added, array( 'loading', 'decoding', 'fetchpriority' ) ),
				'wp_img_tag_add_loading_optimization_attrs gates loading, preserves attrs, and applies decoding/loading filters',
				array(
					'loadingAdded'        => self::describe_string( $loading_added ),
					'loadingAttrs'        => $loading_attrs,
					'missingDimAdded'     => self::describe_string( $missing_dim_added ),
					'missingDimAttrs'     => $missing_dim_attrs,
					'existingAdded'       => self::describe_string( $existing_added ),
					'existingAttrs'       => $existing_attrs,
					'omitDecodingAttrs'   => $omit_decoding_attrs,
					'autoDecodingAttrs'   => $auto_decoding_attrs,
					'omitLoadingAttrs'    => $omit_loading_attrs,
					'eagerLoadingAttrs'   => $eager_loading_attrs,
				)
			);

			$iframe_tag        = '<iframe src="https://example.test/embed" width="640" height="360">';
			$iframe_added      = \wp_iframe_tag_add_loading_attr( $iframe_tag, 'component-fuzz-iframe-loading' );
			$iframe_attrs      = self::tag_attributes( $iframe_added, 'iframe' );
			$iframe_no_width   = '<iframe src="https://example.test/embed" height="360">';
			$iframe_no_src     = '<iframe width="640" height="360">';
			$iframe_invalid    = \wp_iframe_tag_add_loading_attr( $iframe_tag, 'component-fuzz-iframe-loading-invalid' );
			$iframe_invalid_attrs = self::tag_attributes( $iframe_invalid, 'iframe' );

			self::collect_failure(
				$failures,
				'lazy' === ( $iframe_attrs['loading'] ?? null )
					&& $iframe_no_width === \wp_iframe_tag_add_loading_attr( $iframe_no_width, 'component-fuzz-iframe-loading' )
					&& $iframe_no_src === \wp_iframe_tag_add_loading_attr( $iframe_no_src, 'component-fuzz-iframe-loading' )
					&& 'lazy' === ( $iframe_invalid_attrs['loading'] ?? null )
					&& self::tag_has_unique_attributes( $iframe_added, array( 'loading' ) )
					&& self::tag_has_unique_attributes( $iframe_invalid, array( 'loading' ) ),
				'wp_iframe_tag_add_loading_attr requires double-quoted src and dimensions and falls back for invalid filter values',
				array(
					'iframeAdded'        => self::describe_string( $iframe_added ),
					'iframeAttrs'        => $iframe_attrs,
					'iframeInvalid'      => self::describe_string( $iframe_invalid ),
					'iframeInvalidAttrs' => $iframe_invalid_attrs,
				)
			);

			$attachment_tag     = '<img class="alignnone wp-image-' . $attachment_id . '" src="' . $medium_src . '" alt="Attachment">';
			$non_attachment_src = 'http://example.test/wp-content/uploads/2026/06/not-an-attachment.jpg';
			$non_attachment_tag = '<img class="not-an-attachment" src="' . $non_attachment_src . '" width="320" height="180" alt="External">';
			$iframe_open        = '<iframe src="https://example.test/embed" width="640" height="360">';
			$content            = '<p>' . $attachment_tag . '</p><p>' . $attachment_tag . '</p><p>' . $non_attachment_tag . '</p><figure>' . $iframe_open . '</iframe>' . $iframe_open . '</iframe></figure>';
			$filtered_content   = \wp_filter_content_tags( $content, $context );
			$filtered_imgs      = self::extract_tag_opens( $filtered_content, 'img' );
			$filtered_iframes   = self::extract_tag_opens( $filtered_content, 'iframe' );
			$attachment_imgs    = array_values(
				array_filter(
					$filtered_imgs,
					static function ( string $tag ) use ( $medium_src ): bool {
						$attrs = self::tag_attributes( $tag, 'img' );
						return $medium_src === ( $attrs['src'] ?? null );
					}
				)
			);
			$non_attachment_imgs = array_values(
				array_filter(
					$filtered_imgs,
					static function ( string $tag ) use ( $non_attachment_src ): bool {
						$attrs = self::tag_attributes( $tag, 'img' );
						return $non_attachment_src === ( $attrs['src'] ?? null );
					}
				)
			);
			$attachment_attrs = isset( $attachment_imgs[0] ) ? self::tag_attributes( $attachment_imgs[0], 'img' ) : array();
			$non_attrs        = isset( $non_attachment_imgs[0] ) ? self::tag_attributes( $non_attachment_imgs[0], 'img' ) : array();
			$iframe_attrs     = isset( $filtered_iframes[0] ) ? self::tag_attributes( $filtered_iframes[0], 'iframe' ) : array();
			$attachment_srcset_widths = isset( $attachment_attrs['srcset'] ) && is_string( $attachment_attrs['srcset'] )
				? self::parse_srcset_widths( $attachment_attrs['srcset'] )
				: array();
			$attachment_srcset_urls = isset( $attachment_attrs['srcset'] ) && is_string( $attachment_attrs['srcset'] )
				? self::parse_srcset_width_urls( $attachment_attrs['srcset'] )
				: array();
			$attachment_sizes = 'auto, ' . sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $medium['width'] );
			$attachment_call_count = count(
				array_filter(
					$content_img_calls,
					static fn( array $call ): bool => $attachment_id === ( $call['attachmentId'] ?? null )
				)
			);
			$non_attachment_call_count = count(
				array_filter(
					$content_img_calls,
					static fn( array $call ): bool => 0 === ( $call['attachmentId'] ?? null )
				)
			);
			$all_filtered_tags_unique = true;
			foreach ( array_merge( $filtered_imgs, $filtered_iframes ) as $tag ) {
				$all_filtered_tags_unique = $all_filtered_tags_unique
					&& self::tag_has_unique_attributes( $tag, array( 'srcset', 'sizes', 'loading', 'decoding' ) );
			}

			self::collect_failure(
				$failures,
				3 === count( $filtered_imgs )
					&& 2 === count( $filtered_iframes )
					&& 2 === count( $attachment_imgs )
					&& isset( $attachment_imgs[0] )
					&& 2 === substr_count( $filtered_content, $attachment_imgs[0] )
					&& 1 === $attachment_call_count
					&& 1 === $non_attachment_call_count
					&& (string) $medium['width'] === ( $attachment_attrs['width'] ?? null )
					&& (string) $medium['height'] === ( $attachment_attrs['height'] ?? null )
					&& isset( $attachment_attrs['srcset'], $attachment_attrs['sizes'] )
					&& self::expected_srcset_widths_present( $meta, $attachment_srcset_widths, (int) $medium['width'], (int) $medium['height'] )
					&& self::expected_srcset_urls_present( $meta, $attachment_srcset_urls, (int) $medium['width'], (int) $medium['height'] )
					&& $attachment_sizes === $attachment_attrs['sizes']
					&& 'lazy' === ( $attachment_attrs['loading'] ?? null )
					&& 'async' === ( $attachment_attrs['decoding'] ?? null )
					&& (string) $attachment_id === ( $attachment_attrs['data-content-filtered'] ?? null )
					&& 1 === count( $non_attachment_imgs )
					&& '0' === ( $non_attrs['data-content-filtered'] ?? null )
					&& 'lazy' === ( $non_attrs['loading'] ?? null )
					&& 'async' === ( $non_attrs['decoding'] ?? null )
					&& ! isset( $non_attrs['srcset'], $non_attrs['sizes'] )
					&& '320' === ( $non_attrs['width'] ?? null )
					&& '180' === ( $non_attrs['height'] ?? null )
					&& 2 === substr_count( $filtered_content, $filtered_iframes[0] ?? '' )
					&& 1 === count( $iframe_loading_calls )
					&& $iframe_open === ( $iframe_loading_calls[0] ?? null )
					&& 'lazy' === ( $iframe_attrs['loading'] ?? null )
					&& $all_filtered_tags_unique,
				'wp_filter_content_tags transforms unique duplicate images/iframes once and replaces all copies',
				array(
					'contentImgCalls'    => $content_img_calls,
					'iframeCalls'        => $iframe_loading_calls,
					'attachmentAttrs'    => $attachment_attrs,
					'attachmentWidths'   => $attachment_srcset_widths,
					'attachmentUrls'     => $attachment_srcset_urls,
					'attachmentSizes'    => $attachment_sizes,
					'nonAttachmentAttrs' => $non_attrs,
					'iframeAttrs'        => $iframe_attrs,
					'filteredContent'    => self::describe_string( $filtered_content ),
				)
			);
		} finally {
			\remove_filter( 'wp_content_img_tag', $content_img_filter, 10 );
			\remove_filter( 'wp_iframe_tag_add_loading_attr', $iframe_loading_attr_filter, 10 );
			\remove_filter( 'wp_img_tag_add_loading_attr', $img_loading_attr_filter, 10 );
			\remove_filter( 'wp_img_tag_add_decoding_attr', $decoding_attr_filter, 10 );
			\remove_filter( 'wp_lazy_loading_enabled', $lazy_enabled_filter, 10 );
			\remove_filter( 'get_post_metadata', $meta_filter, 10 );
		}

		return self::row(
			$ctx,
			'images.content-tags.direct-helper-pipeline',
			array() === $failures,
			array(
				'attachmentId' => $attachment_id,
				'failures'     => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_loading_optimization_attributes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$attrs    = array(
			'src'    => 'https://example.test/image.jpg',
			'width'  => 600 + $ctx->int( 0, 300 ),
			'height' => 400 + $ctx->int( 0, 200 ),
		);

		if ( class_exists( '\WP_Query' ) && ! isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query'] = new \WP_Query();
		}

		$first    = \wp_get_loading_optimization_attributes( 'img', $attrs, 'component-fuzz' );
		$second   = \wp_get_loading_optimization_attributes( 'img', $attrs, 'component-fuzz' );
		$template = \wp_get_loading_optimization_attributes( 'img', $attrs, 'template' );
		$span     = \wp_get_loading_optimization_attributes( 'span', $attrs, 'component-fuzz' );
		$explicit = \wp_get_loading_optimization_attributes( 'img', $attrs + array( 'loading' => 'eager', 'decoding' => 'sync' ), 'component-fuzz' );
		$low      = \wp_get_loading_optimization_attributes( 'img', $attrs + array( 'fetchpriority' => 'low' ), 'component-fuzz-low' );
		$auto     = \wp_get_loading_optimization_attributes( 'img', $attrs + array( 'fetchpriority' => 'auto' ), 'component-fuzz-auto' );
		$iframe   = \wp_get_loading_optimization_attributes( 'iframe', $attrs, 'component-fuzz-frame' );
		$missing_dimensions = \wp_get_loading_optimization_attributes(
			'img',
			array( 'src' => 'https://example.test/missing-dimensions.jpg' ),
			'component-fuzz'
		);
		$pre_filter = static function ( $loading_attrs, string $tag_name, array $attr, string $context ) {
			if ( 'component-fuzz-short-circuit' === $context ) {
				return array(
					'loading'       => 'eager',
					'fetchpriority' => 'low',
				);
			}

			return $loading_attrs;
		};
		$lazy_filter = static function ( bool $default, string $tag_name, string $context ): bool {
			if ( 'component-fuzz-no-lazy' === $context ) {
				return false;
			}

			return $default;
		};
		\add_filter( 'pre_wp_get_loading_optimization_attributes', $pre_filter, 10, 4 );
		\add_filter( 'wp_lazy_loading_enabled', $lazy_filter, 10, 3 );
		try {
			$short_circuit = \wp_get_loading_optimization_attributes( 'img', $attrs, 'component-fuzz-short-circuit' );
			$no_lazy       = \wp_get_loading_optimization_attributes( 'img', $attrs, 'component-fuzz-no-lazy' );
		} finally {
			\remove_filter( 'wp_lazy_loading_enabled', $lazy_filter, 10 );
			\remove_filter( 'pre_wp_get_loading_optimization_attributes', $pre_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$first === $second
				&& isset( $first['decoding'] )
				&& 'async' === $first['decoding']
				&& array() === $template
				&& array() === $span
				&& isset( $explicit['decoding'] )
				&& 'sync' === $explicit['decoding']
				&& ! isset( $explicit['loading'] )
				&& isset( $low['fetchpriority'] )
				&& 'low' === $low['fetchpriority']
				&& ! isset( $low['loading'] )
				&& isset( $auto['fetchpriority'] )
				&& 'auto' === $auto['fetchpriority']
				&& isset( $auto['loading'] )
				&& 'lazy' === $auto['loading']
				&& ! isset( $iframe['decoding'] )
				&& isset( $iframe['loading'] )
				&& 'lazy' === $iframe['loading']
				&& array( 'decoding' => 'async' ) === $missing_dimensions
				&& array( 'loading' => 'eager', 'fetchpriority' => 'low' ) === $short_circuit
				&& isset( $no_lazy['decoding'] )
				&& 'async' === $no_lazy['decoding']
				&& ! isset( $no_lazy['loading'] ),
			'wp_get_loading_optimization_attributes deterministic context behavior',
			array(
				'attrs'             => $attrs,
				'first'             => $first,
				'second'            => $second,
				'template'          => $template,
				'span'              => $span,
				'explicit'          => $explicit,
				'low'               => $low,
				'auto'              => $auto,
				'iframe'            => $iframe,
				'missingDimensions' => $missing_dimensions,
				'shortCircuit'      => $short_circuit,
				'noLazy'            => $no_lazy,
			)
		);

		return self::row(
			$ctx,
			'images.loading-optimization.context-determinism',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_attachment_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$attachment_id = 100000 + $ctx->iteration();
		$meta          = self::image_meta( $ctx );
		$src           = 'http://example.test/wp-content/uploads/2026/06/photo-600x400.jpg';
		$full_src      = 'http://example.test/wp-content/uploads/2026/06/photo.jpg';
		$attr_calls    = array();

		if ( class_exists( '\WP_Query' ) && ! isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query'] = new \WP_Query();
		}

		$source_filter = static function ( $image, int $id, $size ) use ( $attachment_id, $src, $full_src ) {
			if ( $attachment_id !== $id ) {
				return $image;
			}

			if ( 'medium' === $size ) {
				return array( $src, 600, 400, true );
			}

			if ( 'full' === $size ) {
				return array( $full_src, 1200, 800, false );
			}

			if ( is_array( $size ) ) {
				return array( $src, (int) $size[0], (int) $size[1], true );
			}

			return $image;
		};
		$auto_sizes_filter = static fn() => false;
		$meta_filter       = static function ( $value, int $object_id, string $meta_key, bool $single ) use ( $attachment_id, $meta ) {
			if ( $attachment_id !== $object_id || ! $single ) {
				return $value;
			}

			if ( '_wp_attachment_metadata' === $meta_key ) {
				return array( $meta );
			}

			if ( '_wp_attachment_image_alt' === $meta_key ) {
				return 'Default <Alt>';
			}

			return $value;
		};
		$attribute_filter  = static function ( array $attr, $attachment, $size ) use ( &$attr_calls ): array {
			$attr_calls[] = array(
				'attachmentClass' => is_object( $attachment ) ? get_class( $attachment ) : gettype( $attachment ),
				'size'            => $size,
				'hasWidth'        => isset( $attr['width'] ),
				'hasHeight'       => isset( $attr['height'] ),
			);

			if ( 'medium' === $size ) {
				$attr['class']        .= ' component-fuzzed';
				$attr['data-filtered'] = 'filter <value>';
			}

			return $attr;
		};

		\add_filter( 'wp_get_attachment_image_src', $source_filter, 10, 3 );
		\add_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter );
		\add_filter( 'get_post_metadata', $meta_filter, 10, 4 );
		\add_filter( 'wp_get_attachment_image_attributes', $attribute_filter, 10, 3 );
		try {
			$image_src = \wp_get_attachment_image_src( $attachment_id, 'medium' );
			$image_url = \wp_get_attachment_image_url( $attachment_id, 'medium' );
			$srcset    = \wp_get_attachment_image_srcset( $attachment_id, 'medium', $meta );
			$sizes     = \wp_get_attachment_image_sizes( $attachment_id, 'medium', $meta );
			$html      = \wp_get_attachment_image(
				$attachment_id,
				'medium',
				false,
				array(
					'alt'        => 'Fuzz <Alt>',
					'loading'    => false,
					'decoding'   => 'sync',
					'data-extra' => 'raw " value',
				)
			);
		} finally {
			\remove_filter( 'wp_get_attachment_image_attributes', $attribute_filter, 10 );
			\remove_filter( 'get_post_metadata', $meta_filter, 10 );
			\remove_filter( 'wp_get_attachment_image_src', $source_filter, 10 );
			\remove_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter );
		}

		$full_match      = \wp_image_file_matches_image_meta( $full_src . '?ver=1', $meta, $attachment_id );
		$thumbnail_match = \wp_image_file_matches_image_meta( '/var/www/wp-content/uploads/2026/06/photo-300x200.jpg', $meta, $attachment_id );
		$original_match  = \wp_image_file_matches_image_meta( 'http://example.test/wp-content/uploads/2026/06/photo-original.jpg', $meta, $attachment_id );
		$missing_match   = \wp_image_file_matches_image_meta( 'http://example.test/wp-content/uploads/2026/06/other.jpg', $meta, $attachment_id );
		$match_filter    = static function ( bool $match, string $image_location, array $image_meta, int $id ) use ( $attachment_id ): bool {
			if ( $attachment_id === $id && str_contains( $image_location, 'forced-match.jpg' ) ) {
				return true;
			}

			return $match;
		};
		\add_filter( 'wp_image_file_matches_image_meta', $match_filter, 10, 4 );
		try {
			$forced_match = \wp_image_file_matches_image_meta( 'http://example.test/wp-content/uploads/2026/06/forced-match.jpg', $meta, $attachment_id );
		} finally {
			\remove_filter( 'wp_image_file_matches_image_meta', $match_filter, 10 );
		}
		$after_forced_match = \wp_image_file_matches_image_meta( 'http://example.test/wp-content/uploads/2026/06/forced-match.jpg', $meta, $attachment_id );

		self::collect_failure(
			$failures,
			array( $src, 600, 400, true ) === $image_src
				&& $src === $image_url
				&& is_string( $srcset )
				&& str_contains( $srcset, 'photo-300x200.jpg 300w' )
				&& str_contains( $srcset, 'photo-600x400.jpg 600w' )
				&& str_contains( $srcset, 'photo.jpg 1200w' )
				&& '(max-width: 600px) 100vw, 600px' === $sizes
				&& is_string( $html )
				&& str_starts_with( $html, '<img ' )
				&& str_contains( $html, 'src="' . esc_attr( $src ) . '"' )
				&& str_contains( $html, 'width="600"' )
				&& str_contains( $html, 'height="400"' )
				&& str_contains( $html, 'alt="Fuzz &lt;Alt&gt;"' )
				&& str_contains( $html, 'decoding="sync"' )
				&& str_contains( $html, 'class="attachment-medium size-medium component-fuzzed"' )
				&& str_contains( $html, 'data-filtered="filter &lt;value&gt;"' )
				&& str_contains( $html, 'data-extra="raw &quot; value"' )
				&& str_contains( $html, 'srcset=' )
				&& str_contains( $html, 'sizes=' )
				&& ! str_contains( $html, ' loading=' )
				&& true === $full_match
				&& true === $thumbnail_match
				&& true === $original_match
				&& false === $missing_match
				&& true === $forced_match
				&& false === $after_forced_match
				&& 1 === count( $attr_calls )
				&& true === $attr_calls[0]['hasWidth']
				&& true === $attr_calls[0]['hasHeight'],
			'wp_get_attachment_image helpers and image-meta matching agree',
			array(
				'imageSrc'       => $image_src,
				'imageUrl'       => $image_url,
				'srcset'         => $srcset,
				'sizes'          => $sizes,
				'html'           => self::describe_string( $html ),
				'fullMatch'      => $full_match,
				'thumbnailMatch' => $thumbnail_match,
				'originalMatch'  => $original_match,
				'missingMatch'   => $missing_match,
				'forcedMatch'    => $forced_match,
				'afterForced'    => $after_forced_match,
				'attrCalls'      => $attr_calls,
			)
		);

		return self::row(
			$ctx,
			'images.attachment.helpers-meta-agreement',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_filetype_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$mimes    = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'avif'         => 'image/avif',
			'svg'          => 'image/svg+xml',
		);
		$cases    = array(
			array( 'name' => 'photo-' . $ctx->int( 100, 999 ) . '.JPG', 'ext' => 'jpg', 'type' => 'image/jpeg' ),
			array( 'name' => 'nested/path/diagram.' . $ctx->choice( array( 'png', 'PNG' ) ), 'ext' => 'png', 'type' => 'image/png' ),
			array( 'name' => 'vector.SVG', 'ext' => 'svg', 'type' => 'image/svg+xml' ),
			array( 'name' => 'archive.jpg.php', 'ext' => false, 'type' => false ),
			array( 'name' => 'query.webp?ver=1', 'ext' => false, 'type' => false ),
			array( 'name' => 'no-extension', 'ext' => false, 'type' => false ),
		);

		foreach ( $cases as $index => $case ) {
			$result = \wp_check_filetype( $case['name'], $mimes );
			self::collect_failure(
				$failures,
				$case['ext'] === ( is_string( $result['ext'] ) ? strtolower( $result['ext'] ) : $result['ext'] )
					&& $case['type'] === $result['type'],
				"wp_check_filetype maps extension case {$index}",
				array(
					'case'   => $case,
					'result' => $result,
				)
			);
		}

		$hw_width_only = \image_hwstring( '640px', 0 );
		$hw_both       = \image_hwstring( 320, '180px' );
		$mime_types    = \wp_get_mime_types();
		$default_jpeg  = \wp_get_default_extension_for_mime_type( 'image/jpeg' );

		self::collect_failure(
			$failures,
			'image' === \wp_ext2type( 'JPG' )
				&& null === \wp_ext2type( 'component-fuzz-unknown' )
				&& 'jpg' === $default_jpeg
				&& isset( $mime_types['jpg|jpeg|jpe'] )
				&& 'image/jpeg' === $mime_types['jpg|jpeg|jpe']
				&& 'width="640" ' === $hw_width_only
				&& 'width="320" height="180" ' === $hw_both,
			'image extension, MIME, and dimension-attribute helpers agree',
			array(
				'defaultJpeg' => $default_jpeg,
				'hwWidthOnly' => $hw_width_only,
				'hwBoth'      => $hw_both,
			)
		);

		return self::row(
			$ctx,
			'images.filetypes.extensions-hwstring',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function image_meta( \ComponentFuzz\FuzzContext $ctx ): array {
		unset( $ctx );
		return array(
			'width'  => 1200,
			'height' => 800,
			'file'   => '2026/06/photo.jpg',
			'original_image' => 'photo-original.jpg',
			'sizes'  => array(
				'thumbnail' => array(
					'file'      => 'photo-300x200.jpg',
					'width'     => 300,
					'height'    => 200,
					'mime-type' => 'image/jpeg',
				),
				'medium'    => array(
					'file'      => 'photo-600x400.jpg',
					'width'     => 600,
					'height'    => 400,
					'mime-type' => 'image/jpeg',
				),
				'large'     => array(
					'file'      => 'photo-1200x800.jpg',
					'width'     => 1200,
					'height'    => 800,
					'mime-type' => 'image/jpeg',
				),
			),
		);
	}

	private static function generated_image_meta( \ComponentFuzz\FuzzContext $ctx ): array {
		$width   = $ctx->int( 900, 2400 );
		$height  = $ctx->int( 500, 1800 );
		$base    = 'fuzz-' . $ctx->int( 1000, 9999 );
		$dirname = '2026/06';
		$sizes   = array();

		foreach (
			array(
				'thumbnail'    => 0.25,
				'medium'       => 0.5,
				'medium_large' => 0.625,
				'large'        => 0.75,
			) as $name => $scale
		) {
			$size_width = max( 1, (int) round( $width * $scale ) );
			$size_height = max( 1, (int) round( $height * $size_width / $width ) );
			$file      = "{$base}-{$size_width}x{$size_height}.jpg";
			$sizes[ $name ] = array(
				'file'      => $file,
				'width'     => $size_width,
				'height'    => $size_height,
				'mime-type' => 'image/jpeg',
				'path'      => "{$dirname}/{$file}",
				'url'       => "http://example.test/wp-content/uploads/{$dirname}/{$file}",
			);
		}

		return array(
			'width'          => $width,
			'height'         => $height,
			'file'           => "{$dirname}/{$base}.jpg",
			'original_image' => "{$base}-original.jpg",
			'sizes'          => $sizes,
		);
	}

	private static function image_url( array $meta, ?string $size ): string {
		$dirname = dirname( $meta['file'] );
		$prefix  = '.' === $dirname ? '' : trim( $dirname, '/' ) . '/';
		$file    = null === $size ? basename( $meta['file'] ) : $meta['sizes'][ $size ]['file'];

		return "http://example.test/wp-content/uploads/{$prefix}{$file}";
	}

	private static function dimension_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'width' => 1200, 'height' => 800, 'maxWidth' => 600, 'maxHeight' => 600 ),
			array( 'width' => 465, 'height' => 700, 'maxWidth' => 177, 'maxHeight' => 177 ),
			array( 'width' => 1, 'height' => 400, 'maxWidth' => 100, 'maxHeight' => 100 ),
			array( 'width' => 640, 'height' => 480, 'maxWidth' => 0, 'maxHeight' => 240 ),
			array( 'width' => 4096, 'height' => 1, 'maxWidth' => 2048, 'maxHeight' => 99 ),
			array( 'width' => 37, 'height' => 113, 'maxWidth' => 36, 'maxHeight' => 112 ),
			array( 'width' => 1000, 'height' => 1000, 'maxWidth' => 333, 'maxHeight' => 0 ),
		);

		for ( $i = count( $cases ); $i < self::CASES; ++$i ) {
			$case    = $ctx->fork( 'dimension-' . $i );
			$width   = $case->int( 1, 4000 );
			$height  = $case->int( 1, 4000 );
			$cases[] = array(
				'width'     => $width,
				'height'    => $height,
				'maxWidth'  => $case->choice( array( 0, $case->int( 1, $width ) ) ),
				'maxHeight' => $case->choice( array( 0, $case->int( 1, $height ) ) ),
			);
		}

		return $cases;
	}

	private static function resize_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'origW' => 600, 'origH' => 300, 'destW' => 400, 'destH' => 400 ),
			array( 'origW' => 300, 'origH' => 600, 'destW' => 200, 'destH' => 0 ),
			array( 'origW' => 300, 'origH' => 200, 'destW' => 0, 'destH' => 100 ),
			array( 'origW' => 64, 'origH' => 64, 'destW' => 128, 'destH' => 0 ),
		);

		for ( $i = count( $cases ); $i < self::CASES; ++$i ) {
			$case   = $ctx->fork( 'resize-' . $i );
			$orig_w = $case->int( 32, 4096 );
			$orig_h = $case->int( 32, 4096 );
			$cases[] = array(
				'origW' => $orig_w,
				'origH' => $orig_h,
				'destW' => $case->choice( array( 0, $case->int( 16, $orig_w ), $orig_w + $case->int( 1, 200 ) ) ),
				'destH' => $case->choice( array( 0, $case->int( 16, $orig_h ), $orig_h + $case->int( 1, 200 ) ) ),
			);
		}

		return $cases;
	}

	private static function expected_resize_result( array $case, $crop ) {
		if ( self::resize_expected_false( $case, $crop ) ) {
			return false;
		}

		list( $new_w, $new_h ) = self::resize_target_dimensions( $case, $crop );

		if ( $crop ) {
			$size_ratio = max( $new_w / $case['origW'], $new_h / $case['origH'] );
			$crop_w     = round( $new_w / $size_ratio );
			$crop_h     = round( $new_h / $size_ratio );
			$crop       = is_array( $crop ) && 2 === count( $crop ) ? $crop : array( 'center', 'center' );

			if ( 'left' === $crop[0] ) {
				$src_x = 0;
			} elseif ( 'right' === $crop[0] ) {
				$src_x = $case['origW'] - $crop_w;
			} else {
				$src_x = floor( ( $case['origW'] - $crop_w ) / 2 );
			}

			if ( 'top' === $crop[1] ) {
				$src_y = 0;
			} elseif ( 'bottom' === $crop[1] ) {
				$src_y = $case['origH'] - $crop_h;
			} else {
				$src_y = floor( ( $case['origH'] - $crop_h ) / 2 );
			}
		} else {
			$crop_w = $case['origW'];
			$crop_h = $case['origH'];
			$src_x  = 0;
			$src_y  = 0;
		}

		return array( 0, 0, (int) $src_x, (int) $src_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h );
	}

	private static function resize_expected_false( array $case, $crop ): bool {
		if ( $case['origW'] <= 0 || $case['origH'] <= 0 ) {
			return true;
		}

		if ( $case['destW'] <= 0 && $case['destH'] <= 0 ) {
			return true;
		}

		if ( $case['destW'] <= 0 && $case['destH'] > $case['origH'] ) {
			return true;
		}

		if ( $case['destH'] <= 0 && $case['destW'] > $case['origW'] ) {
			return true;
		}

		if ( $case['destW'] > $case['origW'] && $case['destH'] > $case['origH'] ) {
			return true;
		}

		list( $new_w, $new_h ) = self::resize_target_dimensions( $case, $crop );
		return self::fuzzy_number_match( $new_w, $case['origW'] )
			&& self::fuzzy_number_match( $new_h, $case['origH'] );
	}

	private static function resize_target_dimensions( array $case, $crop ): array {
		if ( $crop ) {
			$aspect_ratio = $case['origW'] / $case['origH'];
			$new_w        = min( $case['destW'], $case['origW'] );
			$new_h        = min( $case['destH'], $case['origH'] );

			if ( ! $new_w ) {
				$new_w = (int) round( $new_h * $aspect_ratio );
			}

			if ( ! $new_h ) {
				$new_h = (int) round( $new_w / $aspect_ratio );
			}

			return array( (int) $new_w, (int) $new_h );
		}

		$dimensions = \wp_constrain_dimensions( $case['origW'], $case['origH'], $case['destW'], $case['destH'] );
		return array( (int) $dimensions[0], (int) $dimensions[1] );
	}

	private static function fuzzy_number_match( $expected, $actual ): bool {
		if ( function_exists( 'wp_fuzzy_number_match' ) ) {
			return \wp_fuzzy_number_match( $expected, $actual );
		}

		return abs( (float) $expected - (float) $actual ) <= 1;
	}

	private static function parse_srcset_widths( string $srcset ): array {
		$widths = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( preg_match( '/\s(\d+)w$/', $candidate, $matches ) ) {
				$widths[ (int) $matches[1] ] = $candidate;
			}
		}

		return $widths;
	}

	private static function parse_srcset_width_urls( string $srcset ): array {
		$urls = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( preg_match( '/^(.+)\s+(\d+)w$/', $candidate, $matches ) ) {
				$urls[ (int) $matches[2] ] = $matches[1];
			}
		}

		return $urls;
	}

	private static function extract_tag_opens( string $html, string $tag_name ): array {
		if ( ! preg_match_all( '/<' . preg_quote( $tag_name, '/' ) . '\s[^>]*>/i', $html, $matches ) ) {
			return array();
		}

		return $matches[0];
	}

	private static function tag_attributes( string $tag, string $tag_name ): array {
		$candidates = array( $tag );
		if ( 'img' !== strtolower( $tag_name ) && ! str_contains( strtolower( $tag ), '</' . strtolower( $tag_name ) . '>' ) ) {
			$candidates[] = $tag . '</' . strtolower( $tag_name ) . '>';
		}

		foreach ( $candidates as $html ) {
			$processor = new \WP_HTML_Tag_Processor( $html );
			if ( ! $processor->next_tag( array( 'tag_name' => strtoupper( $tag_name ) ) ) ) {
				continue;
			}

			$names = $processor->get_attribute_names_with_prefix( '' );
			if ( ! is_array( $names ) ) {
				return array();
			}

			$attrs = array();
			foreach ( $names as $name ) {
				$attrs[ $name ] = $processor->get_attribute( $name );
			}

			return $attrs;
		}

		return array();
	}

	private static function tag_attribute_count( string $tag, string $name ): int {
		return preg_match_all( '/(?<=\s)' . preg_quote( $name, '/' ) . '\s*=/i', $tag );
	}

	private static function tag_has_unique_attributes( string $tag, array $names ): bool {
		foreach ( $names as $name ) {
			if ( self::tag_attribute_count( $tag, (string) $name ) > 1 ) {
				return false;
			}
		}

		return true;
	}

	private static function expected_srcset_widths_present( array $meta, array $candidates, int $src_width, int $src_height ): bool {
		$images = array_merge(
			array(
				array(
					'width'  => (int) $meta['width'],
					'height' => (int) $meta['height'],
				),
			),
			array_values( $meta['sizes'] )
		);

		foreach ( $images as $image ) {
			$width            = (int) $image['width'];
			$ratio_matches    = \wp_image_matches_ratio( $src_width, $src_height, $width, (int) $image['height'] );
			$allowed_by_width = $width <= 2048 || $width === $src_width;

			if ( $ratio_matches && $allowed_by_width && ! isset( $candidates[ $width ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function expected_srcset_urls_present( array $meta, array $candidate_urls, int $src_width, int $src_height ): bool {
		$images = array(
			array(
				'width'  => (int) $meta['width'],
				'height' => (int) $meta['height'],
				'url'    => self::image_url( $meta, null ),
			),
		);

		foreach ( $meta['sizes'] as $size => $image ) {
			$images[] = array(
				'width'  => (int) $image['width'],
				'height' => (int) $image['height'],
				'url'    => self::image_url( $meta, (string) $size ),
			);
		}

		foreach ( $images as $image ) {
			$width            = (int) $image['width'];
			$ratio_matches    = \wp_image_matches_ratio( $src_width, $src_height, $width, (int) $image['height'] );
			$allowed_by_width = $width <= 2048 || $width === $src_width;

			if ( $ratio_matches && $allowed_by_width && ( ! isset( $candidate_urls[ $width ] ) || $image['url'] !== $candidate_urls[ $width ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function aspect_close( int $orig_w, int $orig_h, int $new_w, int $new_h ): bool {
		if ( $orig_w <= 1 || $orig_h <= 1 || $new_w <= 2 || $new_h <= 2 ) {
			return true;
		}

		$diff      = abs( ( $orig_w * $new_h ) - ( $new_w * $orig_h ) );
		$tolerance = 2 * max( $orig_w, $orig_h, $new_w, $new_h );
		return $diff <= $tolerance;
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
		foreach ( array( 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter', 'wp_query', '_wp_additional_image_sizes' ) as $name ) {
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
