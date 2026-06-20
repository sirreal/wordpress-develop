<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes image dimension math and responsive image markup helpers.
 */
final class ImagesSurface {
	public const NAME = 'images';

	private const CASES         = 16;
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
			$rows[] = self::check_srcset_and_sizes( $ctx );
			$rows[] = self::check_image_tag_attributes( $ctx );
			$rows[] = self::check_loading_optimization_attributes( $ctx );
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
				'wp_calculate_image_sizes',
				'wp_calculate_image_srcset',
				'wp_constrain_dimensions',
				'wp_get_loading_optimization_attributes',
				'wp_image_add_srcset_and_sizes',
				'wp_image_src_get_dimensions',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
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
						self::resize_expected_false( $case ),
						"image_resize_dimensions false only for upscale/impossible case {$index}",
						array(
							'case' => $case,
							'crop' => $crop,
						)
					);
					continue;
				}

				list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $result;
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
						&& $dst_h <= max( $case['destH'], $case['origH'] ),
					"image_resize_dimensions crop bounds case {$index}",
					array(
						'case'   => $case,
						'crop'   => $crop,
						'result' => $result,
					)
				);
			}
		}

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

	private static function check_srcset_and_sizes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$meta     = self::image_meta( $ctx );
		$src      = 'https://example.test/wp-content/uploads/2026/06/photo-600x400.jpg';
		$srcset   = \wp_calculate_image_srcset( array( 600, 400 ), $src, $meta, 0 );
		$sizes    = \wp_calculate_image_sizes( array( 600, 400 ), $src, $meta, 0 );
		$full_dim = \wp_image_src_get_dimensions( 'https://example.test/wp-content/uploads/2026/06/photo.jpg', $meta, 0 );
		$med_dim  = \wp_image_src_get_dimensions( $src, $meta, 0 );
		$missing  = \wp_image_src_get_dimensions( 'https://example.test/wp-content/uploads/2026/06/missing.jpg', $meta, 0 );

		$candidates = is_string( $srcset ) ? self::parse_srcset_widths( $srcset ) : array();
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
				&& false === $missing,
			'wp_calculate_image_srcset/sizes and metadata dimensions agree',
			array(
				'srcset'     => $srcset,
				'candidates' => $candidates,
				'sizes'      => $sizes,
				'fullDim'    => $full_dim,
				'medDim'     => $med_dim,
				'missing'    => $missing,
			)
		);

		return self::row(
			$ctx,
			'images.responsive.srcset-sizes-dimensions',
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
				&& str_contains( $existing, 'sizes="100vw"' ),
			'wp_image_add_srcset_and_sizes inserts responsive attrs without clobbering sizes',
			array(
				'first'    => self::describe_string( $first ),
				'existing' => self::describe_string( $existing ),
			)
		);

		return self::row(
			$ctx,
			'images.markup.srcset-sizes-insertion',
			array() === $failures,
			array( 'failures' => $failures )
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

		self::collect_failure(
			$failures,
			$first === $second
				&& isset( $first['decoding'] )
				&& 'async' === $first['decoding']
				&& array() === $template
				&& array() === $span
				&& isset( $explicit['decoding'] )
				&& 'sync' === $explicit['decoding']
				&& ! isset( $explicit['loading'] ),
			'wp_get_loading_optimization_attributes deterministic context behavior',
			array(
				'attrs'    => $attrs,
				'first'    => $first,
				'second'   => $second,
				'template' => $template,
				'span'     => $span,
				'explicit' => $explicit,
			)
		);

		return self::row(
			$ctx,
			'images.loading-optimization.context-determinism',
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

	private static function dimension_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'width' => 1200, 'height' => 800, 'maxWidth' => 600, 'maxHeight' => 600 ),
			array( 'width' => 465, 'height' => 700, 'maxWidth' => 177, 'maxHeight' => 177 ),
			array( 'width' => 1, 'height' => 400, 'maxWidth' => 100, 'maxHeight' => 100 ),
			array( 'width' => 640, 'height' => 480, 'maxWidth' => 0, 'maxHeight' => 240 ),
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
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
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

	private static function resize_expected_false( array $case ): bool {
		if ( $case['origW'] <= 0 || $case['origH'] <= 0 ) {
			return true;
		}

		if ( $case['destW'] <= 0 && $case['destH'] <= 0 ) {
			return true;
		}

		if ( $case['destW'] <= 0 ) {
			return $case['destH'] > $case['origH'];
		}

		if ( $case['destH'] <= 0 ) {
			return $case['destW'] > $case['origW'];
		}

		return $case['destW'] > $case['origW'] && $case['destH'] > $case['origH'];
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

	private static function aspect_close( int $orig_w, int $orig_h, int $new_w, int $new_h ): bool {
		if ( $orig_w <= 1 || $orig_h <= 1 || $new_w <= 1 || $new_h <= 1 ) {
			return true;
		}

		$diff      = abs( ( $orig_w * $new_h ) - ( $new_w * $orig_h ) );
		$tolerance = max( $orig_w, $orig_h, $new_w, $new_h );
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
		foreach ( array( 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter', 'wp_query' ) as $name ) {
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
