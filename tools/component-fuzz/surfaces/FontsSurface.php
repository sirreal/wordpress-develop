<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes the no-DB WordPress Fonts APIs.
 */
final class FontsSurface {
	public const NAME = 'fonts';

	private const FONT_FACE_CASES = 10;
	private const COLLECTION_CASES = 5;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'fonts.bootstrap-apis-available',
					'Required WordPress Fonts APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_font_face_serialization_and_escaping( $ctx->fork( 'font-face' ) );
			$rows[] = self::check_invalid_font_face_rejection( $ctx->fork( 'invalid-font-face' ) );
			$rows[] = self::check_font_dir_filters( $ctx->fork( 'font-dir' ) );
			$rows[] = self::check_font_library_lifecycle( $ctx->fork( 'font-library' ) );
			$rows[] = self::check_font_collection_json_sources( $ctx->fork( 'font-collection-json' ) );
			$rows[] = self::check_font_utils_normalization( $ctx->fork( 'font-utils' ) );
			$rows[] = self::check_font_utils_schema_sanitization( $ctx->fork( 'font-utils-schema' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'fonts.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Error',
				'WP_Font_Collection',
				'WP_Font_Face',
				'WP_Font_Library',
				'WP_Font_Utils',
				'WP_HTML_Tag_Processor',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_filter_font_directory',
				'_wp_to_kebab_case',
				'add_filter',
				'apply_filters',
				'doing_filter',
				'has_filter',
				'remove_filter',
				'sanitize_text_field',
				'sanitize_title',
				'wp_font_dir',
				'wp_get_font_dir',
				'wp_print_font_faces',
				'wp_register_font_collection',
				'wp_unregister_font_collection',
				'wp_upload_dir',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_font_face_serialization_and_escaping( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::font_face_cases( $ctx );
		$faces    = array();

		foreach ( $cases as $case ) {
			$faces[] = $case['face'];
		}

		$call   = self::capture_output(
			static function () use ( $faces ): void {
				\wp_print_font_faces( array( 'component-fuzz' => $faces ) );
			}
		);
		$output = (string) $call['output'];
		$blocks = self::font_face_blocks( $output );

		self::collect_failure(
			$failures,
			! $call['threw']
				&& str_contains( $output, '<style class="wp-fonts-local">' )
				&& count( $blocks ) === count( $cases ),
			'wp_print_font_faces emits one style wrapper and one block per valid face',
			array(
				'blockCount' => count( $blocks ),
				'caseCount'  => count( $cases ),
				'call'       => self::describe_call( $call ),
				'output'     => $output,
			)
		);

		foreach ( $cases as $index => $case ) {
			$block = $blocks[ $index ] ?? '';
			$declaration_properties = self::font_face_declaration_properties( $block );

			self::collect_failure(
				$failures,
				'' !== $block
					&& self::has_declaration( $block, 'src', $case['expectedSrc'] )
					&& self::has_declaration( $block, 'font-display', $case['expectedDisplay'] )
					&& self::has_declaration( $block, 'font-style', $case['expectedStyle'] )
					&& self::has_declaration( $block, 'font-weight', (string) $case['expectedWeight'] )
					&& ! str_contains( $block, $case['droppedProperty'] )
					&& ! str_contains( $block, $case['droppedSrc'] ),
				"font-face declaration defaults, src order, and invalid property removal case {$index}",
				array(
					'case'  => $case['label'],
					'block' => $block,
					'face'  => $case['face'],
					'expected' => array(
						'src'         => $case['expectedSrc'],
						'display'     => $case['expectedDisplay'],
						'style'       => $case['expectedStyle'],
						'weight'      => (string) $case['expectedWeight'],
						'droppedProp' => $case['droppedProperty'],
						'droppedSrc'  => $case['droppedSrc'],
					),
				)
			);

			if ( isset( $case['expectedFamily'] ) ) {
				self::collect_failure(
					$failures,
					self::has_declaration( $block, 'font-family', $case['expectedFamily'] ),
					"font-family quoting follows WP_Font_Face rules case {$index}",
					array(
						'case'     => $case['label'],
						'block'    => $block,
						'expected' => $case['expectedFamily'],
					)
				);
			}

			if ( isset( $case['expectedVariationSettings'] ) ) {
				self::collect_failure(
					$failures,
					self::has_declaration( $block, 'font-variation-settings', $case['expectedVariationSettings'] ),
					"font-variation-settings arrays are serialized case {$index}",
					array(
						'case'     => $case['label'],
						'block'    => $block,
						'expected' => $case['expectedVariationSettings'],
					)
				);
			}

			self::collect_failure(
				$failures,
				! in_array( 'color', $declaration_properties, true ),
				"CSS delimiter payloads stay inside font-face declaration values case {$index}",
				array(
					'case'        => $case['label'],
					'block'       => $block,
					'declarations' => $declaration_properties,
				)
			);
		}

		self::collect_failure(
			$failures,
			! str_contains( strtolower( $output ), '<script' )
				&& ! str_contains( $output, '<img' )
				&& ! str_contains( $output, 'component-fuzz-invalid-prop' )
				&& 1 === substr_count( strtolower( $output ), '<style class="wp-fonts-local">' )
				&& 1 === substr_count( strtolower( $output ), '</style>' ),
			'font-face output strips tag-breaking markup and invalid declarations',
			array( 'output' => $output )
		);

		return self::row(
			$ctx,
			'fonts.font-face.serialization-src-order-escaping',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_invalid_font_face_rejection( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$valid    = self::valid_sentinel_face( $ctx->fork( 'sentinel' ) );
		$invalids = array(
			array(
				'label' => 'missing-font-family',
				'face'  => array( 'src' => 'https://example.test/fonts/missing-family.woff2' ),
			),
			array(
				'label' => 'empty-src',
				'face'  => array(
					'font-family' => 'Component Fuzz Invalid Empty Src',
					'src'         => '',
				),
			),
			array(
				'label' => 'non-string-src-item',
				'face'  => array(
					'font-family' => 'Component Fuzz Invalid Src Item',
					'src'         => array( 'https://example.test/fonts/ok.woff2', array( 'bad' ) ),
				),
			),
			array(
				'label' => 'invalid-weight-type',
				'face'  => array(
					'font-family' => 'Component Fuzz Invalid Weight',
					'src'         => 'https://example.test/fonts/weight.woff2',
					'font-weight' => array( '700' ),
				),
			),
		);

		$faces = array_merge( array_column( $invalids, 'face' ), array( $valid['face'] ) );
		$call  = self::capture_doing_it_wrong(
			static function () use ( $faces ): array {
				return self::capture_output(
					static function () use ( $faces ): void {
						\wp_print_font_faces( array( 'component-fuzz-invalid' => $faces ) );
					}
				);
			}
		);

		$output_call = is_array( $call['value'] ) ? $call['value'] : array();
		$output      = (string) ( $output_call['output'] ?? '' );
		$blocks      = self::font_face_blocks( $output );

		self::collect_failure(
			$failures,
			! $call['threw']
				&& empty( $output_call['threw'] )
				&& count( $call['warnings'] ) >= count( $invalids )
				&& 1 === count( $blocks )
				&& str_contains( $blocks[0] ?? '', $valid['family'] )
				&& ! str_contains( $output, 'Component Fuzz Invalid Empty Src' )
				&& ! str_contains( $output, 'Component Fuzz Invalid Src Item' )
				&& ! str_contains( $output, 'Component Fuzz Invalid Weight' ),
			'invalid font-face declarations are rejected while valid siblings render',
			array(
				'invalids' => array_column( $invalids, 'label' ),
				'warnings' => $call['warnings'],
				'blocks'   => $blocks,
				'call'     => self::describe_call( $call ),
			)
		);

		return self::row(
			$ctx,
			'fonts.font-face.invalid-declaration-rejection',
			array() === $failures,
			array(
				'cases'    => count( $invalids ) + 1,
				'failures' => array_slice( $failures, 0, 4 ),
			)
		);
	}

	private static function check_font_dir_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$suffix   = '/scoped-' . self::slug( $ctx, 'dir' );
		$seen     = 0;
		$guard_ok = null;

		$siteurl_filter = static function () {
			return 'http://example.test';
		};
		$empty_filter = static function () {
			return '';
		};
		$false_filter = static function () {
			return false;
		};
		$font_dir_filter = static function ( array $font_dir ) use ( &$seen, &$guard_ok, $suffix ): array {
			++$seen;

			$sentinel = array(
				'path'    => '/tmp/component-fuzz-sentinel',
				'url'     => 'http://example.test/sentinel',
				'subdir'  => '/sentinel',
				'basedir' => '/tmp/component-fuzz-sentinel',
				'baseurl' => 'http://example.test/sentinel',
				'error'   => 'sentinel',
			);
			$guard_ok = $sentinel === \_wp_filter_font_directory( $sentinel );

			$font_dir['path']    .= $suffix;
			$font_dir['url']     .= $suffix;
			$font_dir['basedir'] .= $suffix;
			$font_dir['baseurl'] .= $suffix;
			return $font_dir;
		};

		$base_upload = \wp_upload_dir( null, false, false );

		\add_filter( 'pre_option_siteurl', $siteurl_filter, 0 );
		\add_filter( 'pre_option_upload_path', $empty_filter, 0 );
		\add_filter( 'pre_option_upload_url_path', $empty_filter, 0 );
		\add_filter( 'pre_option_uploads_use_yearmonth_folders', $false_filter, 0 );
		\add_filter( 'font_dir', $font_dir_filter, 10 );

		try {
			$font_dir = \wp_get_font_dir();
		} finally {
			\remove_filter( 'font_dir', $font_dir_filter, 10 );
			\remove_filter( 'pre_option_uploads_use_yearmonth_folders', $false_filter, 0 );
			\remove_filter( 'pre_option_upload_url_path', $empty_filter, 0 );
			\remove_filter( 'pre_option_upload_path', $empty_filter, 0 );
			\remove_filter( 'pre_option_siteurl', $siteurl_filter, 0 );
		}

		$expected_base_dir = untrailingslashit( $base_upload['basedir'] ) . '/fonts' . $suffix;
		$expected_base_url = untrailingslashit( $base_upload['baseurl'] ) . '/fonts' . $suffix;

		self::collect_failure(
			$failures,
			$expected_base_dir === $font_dir['basedir']
				&& $expected_base_dir === $font_dir['path']
				&& $expected_base_url === $font_dir['baseurl']
				&& $expected_base_url === $font_dir['url']
				&& '' === $font_dir['subdir']
				&& false === $font_dir['error']
				&& 1 === $seen
				&& true === $guard_ok
				&& false === \has_filter( 'upload_dir', '_wp_filter_font_directory' ),
			'wp_get_font_dir maps uploads to fonts, applies font_dir once, and removes upload_dir filter',
			array(
				'actual'   => $font_dir,
				'expected' => array(
					'basedir' => $expected_base_dir,
					'baseurl' => $expected_base_url,
				),
				'seen'     => $seen,
				'guardOk'  => $guard_ok,
				'uploadDirFilterAfter' => \has_filter( 'upload_dir', '_wp_filter_font_directory' ),
			)
		);

		return self::row(
			$ctx,
			'fonts.font-dir.filters-no-db',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 4 ),
			)
		);
	}

	private static function check_font_library_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		self::reset_font_library();

		$library = \WP_Font_Library::get_instance();
		foreach ( self::collection_cases( $ctx ) as $index => $case ) {
			$registered = \wp_register_font_collection( $case['slug'], $case['args'] );
			$lookup     = $library->get_font_collection( $case['slug'] );
			$all        = $library->get_font_collections();
			$data       = $registered instanceof \WP_Font_Collection ? $registered->get_data() : null;

			self::collect_failure(
				$failures,
				$registered instanceof \WP_Font_Collection
					&& $lookup === $registered
					&& isset( $all[ $case['slug'] ] )
					&& self::collection_data_matches_case( $data, $case ),
				"font collection registers, sanitizes, and is retrievable case {$index}",
				array(
					'case'       => $case,
					'registered' => self::describe_font_collection( $registered ),
					'data'       => $data,
					'allKeys'    => array_keys( $all ),
				)
			);

			$duplicate = self::capture_doing_it_wrong(
				static function () use ( $case ) {
					return \wp_register_font_collection( $case['slug'], $case['args'] );
				}
			);
			self::collect_failure(
				$failures,
				! $duplicate['threw']
					&& $duplicate['value'] instanceof \WP_Error
					&& 'font_collection_registration_error' === $duplicate['value']->get_error_code()
					&& count( $duplicate['warnings'] ) >= 1,
				"duplicate font collection registration is rejected case {$index}",
				array(
					'case'      => $case['slug'],
					'duplicate' => self::describe_call( $duplicate ),
				)
			);

			$removed = \wp_unregister_font_collection( $case['slug'] );
			$missing = self::capture_doing_it_wrong(
				static function () use ( $case ) {
					return \wp_unregister_font_collection( $case['slug'] );
				}
			);
			self::collect_failure(
				$failures,
				true === $removed
					&& null === $library->get_font_collection( $case['slug'] )
					&& ! $missing['threw']
					&& false === $missing['value']
					&& count( $missing['warnings'] ) >= 1,
				"font collection unregister removes state and missing unregister warns case {$index}",
				array(
					'case'    => $case['slug'],
					'removed' => $removed,
					'missing' => self::describe_call( $missing ),
				)
			);
		}

		$invalid_slug_case = self::collection_cases( $ctx->fork( 'invalid-slug' ) )[0];
		$invalid_slug      = 'Component Fuzz Invalid Slug ' . $ctx->int( 100, 999 );
		$sanitized_slug    = \sanitize_title( $invalid_slug );
		$invalid_slug_call = self::capture_doing_it_wrong(
			static function () use ( $invalid_slug, $invalid_slug_case ) {
				return \wp_register_font_collection( $invalid_slug, $invalid_slug_case['args'] );
			}
		);
		$invalid_slug_registered = $invalid_slug_call['value'] ?? null;
		$invalid_slug_removed    = \wp_unregister_font_collection( $sanitized_slug );

		self::collect_failure(
			$failures,
			! $invalid_slug_call['threw']
				&& $invalid_slug_registered instanceof \WP_Font_Collection
				&& $sanitized_slug === $invalid_slug_registered->slug
				&& (
					$sanitized_slug === $invalid_slug
					|| count( $invalid_slug_call['warnings'] ) >= 1
				)
				&& true === $invalid_slug_removed,
			'font collection slug follows sanitize_title and reports when changed',
			array(
				'inputSlug'      => $invalid_slug,
				'sanitizedSlug'  => $sanitized_slug,
				'registeredSlug' => $invalid_slug_registered instanceof \WP_Font_Collection ? $invalid_slug_registered->slug : null,
				'call'           => self::describe_call( $invalid_slug_call ),
				'removed'        => $invalid_slug_removed,
			)
		);

		$missing_slug = 'component-fuzz-missing-' . self::slug( $ctx->fork( 'missing' ), 'collection' );
		$missing_call = self::capture_doing_it_wrong(
			static function () use ( $missing_slug ) {
				return \wp_register_font_collection(
					$missing_slug,
					array( 'name' => 'Missing Families' )
				);
			}
		);
		$missing_data = $missing_call['value'] instanceof \WP_Font_Collection ? $missing_call['value']->get_data() : null;
		$missing_removed = \wp_unregister_font_collection( $missing_slug );

		self::collect_failure(
			$failures,
			! $missing_call['threw']
				&& $missing_call['value'] instanceof \WP_Font_Collection
				&& $missing_data instanceof \WP_Error
				&& 'font_collection_missing_property' === $missing_data->get_error_code()
				&& count( $missing_call['warnings'] ) >= 1
				&& true === $missing_removed,
			'missing font_families yields a collection carrying a validation error',
			array(
				'slug'    => $missing_slug,
				'call'    => self::describe_call( $missing_call ),
				'data'    => self::describe_error( $missing_data ),
				'removed' => $missing_removed,
			)
		);

		return self::row(
			$ctx,
			'fonts.library.collection-register-unregister',
			array() === $failures,
			array(
				'cases'    => self::COLLECTION_CASES,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_font_collection_json_sources( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		self::reset_font_library();

		$temp_dir = self::temp_dir( $ctx );
		\mkdir( $temp_dir, 0777, true );

		try {
			$valid_path   = $temp_dir . DIRECTORY_SEPARATOR . 'valid-font-collection.json';
			$invalid_path = $temp_dir . DIRECTORY_SEPARATOR . 'invalid-font-collection.json';
			$missing_path = $temp_dir . DIRECTORY_SEPARATOR . 'missing-font-collection.json';
			$json_case    = self::json_collection_case( $ctx->fork( 'valid-json' ) );

			\file_put_contents(
				$valid_path,
				\json_encode( $json_case['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			);
			\file_put_contents( $invalid_path, "{not-json\n" );

			$slug       = 'component-fuzz-json-' . self::slug( $ctx, 'valid' );
			$registered = \wp_register_font_collection(
				$slug,
				array(
					'name'          => $json_case['argsName'],
					'description'   => $json_case['argsDescription'],
					'font_families' => $valid_path,
					'categories'    => $json_case['argsCategories'],
				)
			);
			$data       = $registered instanceof \WP_Font_Collection ? $registered->get_data() : null;
			$data_again = $registered instanceof \WP_Font_Collection ? $registered->get_data() : null;

			self::collect_failure(
				$failures,
				$registered instanceof \WP_Font_Collection
					&& self::json_collection_data_matches_case( $data, $json_case )
					&& $data === $data_again,
				'font collection lazy-loads local JSON, sanitizes it, and caches data',
				array(
					'slug'       => $slug,
					'registered' => self::describe_font_collection( $registered ),
					'data'       => $data,
					'dataAgain'  => $data_again,
					'case'       => $json_case,
				)
			);

			$bad_json_slug = 'component-fuzz-json-bad-' . self::slug( $ctx->fork( 'bad-json' ), 'collection' );
			$bad_json      = \wp_register_font_collection(
				$bad_json_slug,
				array(
					'name'          => 'Bad JSON Collection',
					'font_families' => $invalid_path,
				)
			);
			$bad_json_data = $bad_json instanceof \WP_Font_Collection ? $bad_json->get_data() : null;

			self::collect_failure(
				$failures,
				$bad_json instanceof \WP_Font_Collection
					&& $bad_json_data instanceof \WP_Error
					&& 'font_collection_decode_error' === $bad_json_data->get_error_code(),
				'invalid local JSON returns a decode WP_Error',
				array(
					'slug' => $bad_json_slug,
					'data' => self::describe_error( $bad_json_data ),
				)
			);

			$missing_slug = 'component-fuzz-json-missing-' . self::slug( $ctx->fork( 'missing-json' ), 'collection' );
			$missing_call = self::capture_doing_it_wrong(
				static function () use ( $missing_slug, $missing_path ) {
					$collection = \wp_register_font_collection(
						$missing_slug,
						array(
							'name'          => 'Missing JSON Collection',
							'font_families' => $missing_path,
						)
					);
					return $collection instanceof \WP_Font_Collection ? $collection->get_data() : $collection;
				}
			);

			self::collect_failure(
				$failures,
				! $missing_call['threw']
					&& $missing_call['value'] instanceof \WP_Error
					&& 'font_collection_json_missing' === $missing_call['value']->get_error_code()
					&& count( $missing_call['warnings'] ) >= 1,
				'missing local JSON path returns a missing-file WP_Error and warning',
				array(
					'slug' => $missing_slug,
					'call' => self::describe_call( $missing_call ),
				)
			);
		} finally {
			self::remove_directory( $temp_dir );
		}

		return self::row(
			$ctx,
			'fonts.collection.local-json-no-db',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_font_utils_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$families = array(
			'FuzzSans'                          => 'FuzzSans',
			'Fuzz Sans'                         => '"Fuzz Sans"',
			'Fuzz Sans, serif, generic(system)' => '"Fuzz Sans", serif, generic(system)',
			'Alpha-Serif'                       => 'Alpha-Serif',
			'<b>Bad</b> Family'                 => '"Bad Family"',
		);

		foreach ( $families as $input => $expected ) {
			$actual = \WP_Font_Utils::sanitize_font_family( $input );
			self::collect_failure(
				$failures,
				$expected === $actual,
				'WP_Font_Utils::sanitize_font_family formats CSS family lists',
				array(
					'input'    => $input,
					'expected' => $expected,
					'actual'   => $actual,
				)
			);
		}

		$slug_a = \WP_Font_Utils::get_font_face_slug(
			array(
				'fontFamily'   => '"Fuzz Sans", serif',
				'fontStyle'    => 'NORMAL',
				'fontWeight'   => 'bold',
				'fontStretch'  => 'normal',
				'unicodeRange' => 'u+00-ff',
			)
		);
		$slug_b = \WP_Font_Utils::get_font_face_slug(
			array(
				'fontFamily'   => "'fuzz sans',serif",
				'fontStyle'    => 'normal',
				'fontWeight'   => '700',
				'fontStretch'  => '100%',
				'unicodeRange' => 'U+00-FF',
			)
		);
		$slug_c = \WP_Font_Utils::get_font_face_slug(
			array(
				'fontFamily'  => 'Fuzz Condensed',
				'fontStretch' => 'condensed',
			)
		);
		$mimes  = \WP_Font_Utils::get_allowed_font_mime_types();

		self::collect_failure(
			$failures,
			$slug_a === $slug_b
				&& str_contains( $slug_a, 'fuzz sans,serif;normal;700;100%;U+00-FF' )
				&& str_contains( $slug_c, '75%' ),
			'WP_Font_Utils::get_font_face_slug normalizes quotes, case, weight, stretch, and unicode range',
			array(
				'slugA' => $slug_a,
				'slugB' => $slug_b,
				'slugC' => $slug_c,
			)
		);

		self::collect_failure(
			$failures,
			isset( $mimes['otf'], $mimes['ttf'], $mimes['woff'], $mimes['woff2'] )
				&& '' !== $mimes['otf']
				&& '' !== $mimes['ttf']
				&& '' !== $mimes['woff']
				&& '' !== $mimes['woff2'],
			'WP_Font_Utils::get_allowed_font_mime_types exposes all font extensions',
			array( 'mimes' => $mimes )
		);

		return self::row(
			$ctx,
			'fonts.utils.normalization-and-mimes',
			array() === $failures,
			array(
				'cases'    => count( $families ) + 3,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_font_utils_schema_sanitization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = self::slug( $ctx, 'schema' );
		$tree     = array(
			'name'       => '<b>Component Schema ' . $token . '</b>',
			'raw'        => 'Raw <em>Value</em> ' . $token,
			'empty'      => '',
			'unknownTop' => 'remove-me',
			'metadata'   => array(
				'label'   => "Label\n" . $token,
				'rawList' => array( 'Alpha ' . $token, 'Beta ' . $token ),
				'unknown' => 'remove-me',
			),
			'families'   => array(
				array(
					'name'    => '<i>Family Alpha ' . $token . '</i>',
					'slug'    => 'Family Alpha ' . $token,
					'faces'   => array(
						array(
							'fontWeight' => ' 700 ',
							'src'        => 'https://example.test/fonts/' . $token . '.woff2',
							'unknown'    => 'remove-me',
						),
						array(
							'fontWeight' => '',
							'src'        => 'https://example.test/fonts/' . $token . '-italic.woff',
						),
					),
					'unknown' => 'remove-me',
				),
			),
			'badNested'  => 'not-an-array',
		);
		$schema   = array(
			'name'      => 'sanitize_text_field',
			'raw'       => null,
			'metadata'  => array(
				'label'   => 'sanitize_textarea_field',
				'rawList' => array( null ),
			),
			'families'  => array(
				array(
					'name'  => 'sanitize_text_field',
					'slug'  => 'sanitize_title',
					'faces' => array(
						array(
							'fontWeight' => 'sanitize_text_field',
							'src'        => 'esc_url_raw',
						),
					),
				),
			),
			'badNested' => array( 'name' => 'sanitize_text_field' ),
		);

		$sanitized = \WP_Font_Utils::sanitize_from_schema( $tree, $schema );

		self::collect_failure(
			$failures,
			! isset( $sanitized['unknownTop'], $sanitized['empty'], $sanitized['badNested'] )
				&& ! isset( $sanitized['metadata']['unknown'] )
				&& ! isset( $sanitized['families'][0]['unknown'], $sanitized['families'][0]['faces'][0]['unknown'] ),
			'WP_Font_Utils::sanitize_from_schema removes unknown, empty, and structurally invalid values',
			array(
				'sanitized' => $sanitized,
				'tree'      => $tree,
			)
		);
		self::collect_failure(
			$failures,
			'Component Schema ' . $token === ( $sanitized['name'] ?? null )
				&& $tree['raw'] === ( $sanitized['raw'] ?? null )
				&& $tree['metadata']['label'] === ( $sanitized['metadata']['label'] ?? null )
				&& $tree['metadata']['rawList'] === ( $sanitized['metadata']['rawList'] ?? null ),
			'WP_Font_Utils::sanitize_from_schema applies callables and preserves null-sanitizer values',
			array(
				'sanitized' => $sanitized,
				'expected'  => array(
					'name'    => 'Component Schema ' . $token,
					'raw'     => $tree['raw'],
					'label'   => $tree['metadata']['label'],
					'rawList' => $tree['metadata']['rawList'],
				),
			)
		);
		self::collect_failure(
			$failures,
			isset( $sanitized['families'][0]['faces'][0]['src'], $sanitized['families'][0]['faces'][1]['src'] )
				&& 'Family Alpha ' . $token === ( $sanitized['families'][0]['name'] ?? null )
				&& \sanitize_title( 'Family Alpha ' . $token ) === ( $sanitized['families'][0]['slug'] ?? null )
				&& '700' === ( $sanitized['families'][0]['faces'][0]['fontWeight'] ?? null )
				&& ! isset( $sanitized['families'][0]['faces'][1]['fontWeight'] ),
			'WP_Font_Utils::sanitize_from_schema recursively sanitizes nested numeric arrays',
			array(
				'sanitized' => $sanitized,
			)
		);
		self::collect_failure(
			$failures,
			array() === \WP_Font_Utils::sanitize_from_schema( 'not-array', $schema )
				&& array() === \WP_Font_Utils::sanitize_from_schema( $tree, 'not-array' ),
			'WP_Font_Utils::sanitize_from_schema fails closed for non-array roots or schemas',
			array(
				'nonArrayTree'   => \WP_Font_Utils::sanitize_from_schema( 'not-array', $schema ),
				'nonArraySchema' => \WP_Font_Utils::sanitize_from_schema( $tree, 'not-array' ),
			)
		);

		return self::row(
			$ctx,
			'fonts.utils.schema-sanitization',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function font_face_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		for ( $i = 0; $i < self::FONT_FACE_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'case-' . $i );
			$token    = self::slug( $case_ctx, 'face' );
			$family   = 0 === $i % 3 ? 'Component Fuzz ' . $token : 'ComponentFuzz-' . $token;
			$sources  = self::font_sources( $case_ctx, $token, 5 === $i );
			$display  = $case_ctx->choice( array( 'auto', 'block', 'fallback', 'swap', 'optional', 'invalid-display-' . $i ) );
			$style    = $case_ctx->choice( array( 'normal', 'italic', 'oblique 10deg' ) );
			$weight   = $case_ctx->choice( array( '100 900', '400', 700 ) );
			$dropped_property = 'component-fuzz-invalid-prop-' . $token;
			$dropped_src      = 'https://example.test/fonts/' . $token . '.svg';

			$face = array(
				'font-family'              => $family,
				'src'                      => array_merge( $sources, array( $dropped_src ) ),
				'font-style'               => $style,
				'font-weight'              => $weight,
				'font-display'             => $display,
				'font-feature-settings'    => '"kern" 1',
				'font-variation-settings'  => array( '"wght"' => (string) ( 300 + $i * 50 ) ),
				'unicode-range'            => 'U+00-' . strtoupper( dechex( 255 + $i ) ),
				$dropped_property          => 'component-fuzz-invalid-prop-value',
			);

			if ( 6 === $i ) {
				$face['font-family'] = 'Component Fuzz </style><script>alert(1)</script><img src=x>';
				$family             = null;
			}

			if ( 7 === $i ) {
				$face['src'] = $sources[0];
			}

			if ( 8 === $i ) {
				$family              = 'Component Fuzz "' . $token . ';color:red;/*';
				$face['font-family'] = $family;
			}

			if ( 9 === $i ) {
				$face['src'] = array( 'https://example.test/fonts/' . $token . "');color:red;/*.woff2" );
			}

			$expected_display = in_array( $display, array( 'auto', 'block', 'fallback', 'swap', 'optional' ), true ) ? $display : 'fallback';
			$expected_family  = null === $family ? null : self::expected_font_family( $family );

			$cases[] = array(
				'label'                     => $token,
				'face'                      => $face,
				'expectedSrc'               => self::expected_src( (array) $face['src'] ),
				'expectedDisplay'           => $expected_display,
				'expectedStyle'             => $style,
				'expectedWeight'            => $weight,
				'expectedFamily'            => $expected_family,
				'expectedVariationSettings' => '"wght" ' . ( 300 + $i * 50 ),
				'droppedProperty'           => $dropped_property,
				'droppedSrc'                => $dropped_src,
			);
		}

		return $cases;
	}

	private static function font_sources( \ComponentFuzz\FuzzContext $ctx, string $token, bool $duplicate_woff2 ): array {
		$base = 'https://example.test/fonts/' . $token;
		$src  = array(
			$base . '.ttf',
			'data:font/woff2;base64,' . base64_encode( $token ),
			$base . '.eot',
			$base . '.woff',
			$base . '.otf',
			$base . '.woff2',
		);

		if ( $duplicate_woff2 ) {
			$src[] = $base . '-last.woff2';
		}

		$count = count( $src );
		for ( $i = $count - 1; $i > 0; $i-- ) {
			$j        = $ctx->int( 0, $i );
			$tmp      = $src[ $i ];
			$src[ $i ] = $src[ $j ];
			$src[ $j ] = $tmp;
		}

		return $src;
	}

	private static function expected_src( array $sources ): string {
		$data_sources = array();
		$by_extension = array();

		foreach ( $sources as $source ) {
			if ( ! is_string( $source ) || '' === $source ) {
				continue;
			}

			if ( str_starts_with( trim( $source ), 'data:' ) ) {
				$data_sources[] = $source;
				continue;
			}

			$by_extension[ pathinfo( $source, PATHINFO_EXTENSION ) ] = $source;
		}

		$parts = array();
		foreach ( $data_sources as $source ) {
			$parts[] = "url('" . self::escape_css_string( $source, "'" ) . "')";
		}

		foreach (
			array(
				'woff2' => 'woff2',
				'woff'  => 'woff',
				'ttf'   => 'truetype',
				'eot'   => 'embedded-opentype',
				'otf'   => 'opentype',
			) as $extension => $format
		) {
			if ( isset( $by_extension[ $extension ] ) ) {
				$parts[] = "url('" . self::escape_css_string( $by_extension[ $extension ], "'" ) . "') format('{$format}')";
			}
		}

		return implode( ', ', $parts );
	}

	private static function expected_font_family( string $font_family ): string {
		if ( self::font_family_needs_quotes( $font_family ) ) {
			return '"' . self::escape_css_string( $font_family, '"' ) . '"';
		}

		return $font_family;
	}

	private static function font_family_needs_quotes( string $font_family ): bool {
		if (
			str_contains( $font_family, ';' )
			|| str_contains( $font_family, '{' )
			|| str_contains( $font_family, '}' )
			|| str_contains( $font_family, '/*' )
			|| str_contains( $font_family, '*/' )
			|| preg_match( '/[\x00-\x1F\x7F]/', $font_family )
		) {
			return true;
		}

		return str_contains( $font_family, ' ' )
			&& ! str_contains( $font_family, '"' )
			&& ! str_contains( $font_family, "'" );
	}

	private static function escape_css_string( string $value, string $quote ): string {
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( $quote, '\\' . $quote, $value );

		return preg_replace_callback(
			'/[\x00-\x1F\x7F]/',
			static function ( array $matches ): string {
				return '\\' . strtoupper( dechex( ord( $matches[0] ) ) ) . ' ';
			},
			$value
		);
	}

	private static function valid_sentinel_face( \ComponentFuzz\FuzzContext $ctx ): array {
		$token  = self::slug( $ctx, 'sentinel' );
		$family = 'Component Fuzz Sentinel ' . $token;

		return array(
			'family' => $family,
			'face'   => array(
				'font-family' => $family,
				'src'         => 'https://example.test/fonts/' . $token . '.woff2',
			),
		);
	}

	private static function collection_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		for ( $i = 0; $i < self::COLLECTION_CASES; $i++ ) {
			$case_ctx    = $ctx->fork( 'collection-' . $i );
			$token       = self::slug( $case_ctx, 'collection' );
			$family_name = 'Component Fuzz Family ' . $case_ctx->int( 100, 999 );
			$family_css  = 0 === $i % 2 ? 'Component Fuzz Family, serif' : 'ComponentFuzzFamily';

			$cases[] = array(
				'slug' => 'component-fuzz-' . $token,
				'args' => array(
					'name'          => 'Component Fuzz Collection ' . $case_ctx->int( 1000, 9999 ),
					'description'   => "Description for {$token}\nwith whitespace",
					'font_families' => array(
						array(
							'font_family_settings' => array(
								'name'          => $family_name,
								'slug'          => $family_name,
								'fontFamily'    => $family_css,
								'preview'       => 'http://example.test/fonts/' . $token . '.png',
								'fontFace'      => array(
									array(
										'fontFamily'          => $family_name,
										'fontStyle'           => $case_ctx->choice( array( 'normal', 'italic' ) ),
										'fontWeight'          => (string) $case_ctx->choice( array( 300, 400, 700 ) ),
										'src'                 => array( 'http://example.test/fonts/' . $token . '.woff2' ),
										'fontDisplay'         => 'swap',
										'fontStretch'         => 'normal',
										'componentFuzzUnused' => 'remove-me',
									),
								),
								'componentFuzzSetting' => 'remove-me',
							),
							'categories'          => array( 'sans serif', 'component fuzz' ),
							'componentFuzzFamily' => 'remove-me',
						),
					),
					'categories'    => array(
						array(
							'name' => 'Component Fuzz',
							'slug' => 'component fuzz',
						),
					),
					'componentFuzzTop' => 'remove-me',
				),
				'expected' => array(
					'familyName'       => $family_name,
					'familySlug'       => \_wp_to_kebab_case( \sanitize_title( $family_name ) ),
					'fontFamily'       => 0 === $i % 2 ? '"Component Fuzz Family", serif' : 'ComponentFuzzFamily',
					'familyCategories' => array( \sanitize_title( 'sans serif' ), \sanitize_title( 'component fuzz' ) ),
					'topCategorySlug'  => \sanitize_title( 'component fuzz' ),
				),
			);
		}

		return $cases;
	}

	private static function collection_data_matches_case( $data, array $case ): bool {
		if ( ! is_array( $data ) || empty( $data['font_families'][0]['font_family_settings'] ) ) {
			return false;
		}

		$family  = $data['font_families'][0];
		$setting = $family['font_family_settings'];
		$face    = $setting['fontFace'][0] ?? array();

		return \sanitize_text_field( $case['args']['name'] ) === $data['name']
			&& \sanitize_text_field( $case['args']['description'] ) === $data['description']
			&& array() !== $data['categories']
			&& $case['expected']['topCategorySlug'] === ( $data['categories'][0]['slug'] ?? null )
			&& $case['expected']['familyName'] === ( $setting['name'] ?? null )
			&& $case['expected']['familySlug'] === ( $setting['slug'] ?? null )
			&& $case['expected']['fontFamily'] === ( $setting['fontFamily'] ?? null )
			&& $case['expected']['familyCategories'] === ( $family['categories'] ?? null )
			&& isset( $face['src'][0] )
			&& ! isset( $data['componentFuzzTop'] )
			&& ! isset( $family['componentFuzzFamily'] )
			&& ! isset( $setting['componentFuzzSetting'] )
			&& ! isset( $face['componentFuzzUnused'] );
	}

	private static function json_collection_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token       = self::slug( $ctx, 'json' );
		$family_name = 'JSON Fuzz Family ' . $ctx->int( 100, 999 );

		return array(
			'argsName'        => 'JSON Args Collection',
			'argsDescription' => 'JSON args description',
			'argsCategories'  => array(
				array(
					'name' => 'JSON Args Category',
					'slug' => 'json args',
				),
			),
			'json'            => array(
				'name'          => 'JSON File Name Should Not Replace Args Name',
				'font_families' => array(
					array(
						'font_family_settings' => array(
							'name'       => $family_name,
							'slug'       => $family_name,
							'fontFamily' => 'JSON Fuzz Family, sans-serif',
							'fontFace'   => array(
								array(
									'fontFamily'  => $family_name,
									'fontStyle'   => 'normal',
									'fontWeight'  => '400',
									'src'         => 'https://example.test/fonts/' . $token . '.woff2',
									'fontDisplay' => 'swap',
								),
							),
						),
						'categories'           => array( 'json category' ),
					),
				),
				'categories'    => array(
					array(
						'name' => 'JSON File Category',
						'slug' => 'json-file-category',
					),
				),
			),
			'expected'        => array(
				'familyName'   => $family_name,
				'familySlug'   => \_wp_to_kebab_case( \sanitize_title( $family_name ) ),
				'categorySlug' => \sanitize_title( 'json args' ),
			),
		);
	}

	private static function json_collection_data_matches_case( $data, array $case ): bool {
		if ( ! is_array( $data ) || empty( $data['font_families'][0]['font_family_settings'] ) ) {
			return false;
		}

		$setting = $data['font_families'][0]['font_family_settings'];

		return 'JSON Args Collection' === $data['name']
			&& 'JSON args description' === $data['description']
			&& $case['expected']['categorySlug'] === ( $data['categories'][0]['slug'] ?? null )
			&& $case['expected']['familyName'] === ( $setting['name'] ?? null )
			&& $case['expected']['familySlug'] === ( $setting['slug'] ?? null )
			&& '"JSON Fuzz Family", sans-serif' === ( $setting['fontFamily'] ?? null )
			&& 'JSON File Category' !== ( $data['categories'][0]['name'] ?? null );
	}

	private static function has_declaration( string $block, string $property, string $value ): bool {
		return str_contains( $block, $property . ':' . $value . ';' );
	}

	private static function font_face_declaration_properties( string $block ): array {
		$properties = array();
		$start      = 0;
		$quote      = null;
		$escaped    = false;
		$depth      = 0;
		$length     = strlen( $block );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $block[ $i ];

			if ( null !== $quote ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $char ) {
					$escaped = true;
				} elseif ( $quote === $char ) {
					$quote = null;
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
				continue;
			}

			if ( ')' === $char && $depth > 0 ) {
				--$depth;
				continue;
			}

			if ( ';' === $char && 0 === $depth ) {
				$declaration = substr( $block, $start, $i - $start );
				$colon       = strpos( $declaration, ':' );
				if ( false !== $colon ) {
					$property = trim( substr( $declaration, 0, $colon ) );
					if ( '' !== $property ) {
						$properties[] = strtolower( $property );
					}
				}
				$start = $i + 1;
			}
		}

		return $properties;
	}

	private static function font_face_blocks( string $output ): array {
		if ( ! preg_match_all( '/@font-face\{([^}]*)\}/', $output, $matches ) ) {
			return array();
		}

		return $matches[1];
	}

	private static function capture_output( callable $callback ): array {
		\ob_start();
		try {
			$value  = $callback();
			$output = \ob_get_clean();
			return array(
				'threw'  => false,
				'value'  => $value,
				'output' => $output,
			);
		} catch ( \Throwable $e ) {
			$output = \ob_get_clean();
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'output'    => $output,
			);
		}
	}

	private static function capture_doing_it_wrong( callable $callback ): array {
		$warnings = array();
		$listener = static function ( $function_name, $message, $version ) use ( &$warnings ): void {
			$warnings[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};

		\add_action( 'doing_it_wrong_run', $listener, 10, 3 );
		try {
			$value = $callback();
			return array(
				'threw'    => false,
				'value'    => $value,
				'warnings' => $warnings,
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'warnings'  => $warnings,
			);
		} finally {
			\remove_action( 'doing_it_wrong_run', $listener, 10 );
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

	private static function snapshot_state(): array {
		$library = self::get_static_property( 'WP_Font_Library', 'instance' );

		return array(
			'globals'            => self::snapshot_globals( array( 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter' ) ),
			'fontLibrary'        => $library,
			'fontLibraryEntries' => $library instanceof \WP_Font_Library ? self::get_object_property( $library, 'collections' ) : null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( $snapshot['fontLibrary'] instanceof \WP_Font_Library ) {
			self::set_object_property( $snapshot['fontLibrary'], 'collections', $snapshot['fontLibraryEntries'] );
			self::set_static_property( 'WP_Font_Library', 'instance', $snapshot['fontLibrary'] );
		} else {
			self::set_static_property( 'WP_Font_Library', 'instance', null );
		}
	}

	private static function reset_font_library(): void {
		self::set_static_property( 'WP_Font_Library', 'instance', null );
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
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

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$reflection = new \ReflectionProperty( $class, $property );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}
		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
	}

	private static function get_object_property( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		return $reflection->getValue( $object );
	}

	private static function set_object_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setValue( $object, $value );
	}

	private static function temp_dir( \ComponentFuzz\FuzzContext $ctx ): string {
		return rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-fonts-' . getmypid() . '-' . self::slug( $ctx, 'tmp' );
	}

	private static function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				self::remove_directory( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$raw = strtolower( $label . '-' . $ctx->identifier( 3, 12 ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$raw = preg_replace( '/[^a-z0-9-]+/', '-', $raw );
		$raw = trim( (string) $raw, '-' );
		return '' === $raw ? 'fuzz-' . dechex( $ctx->seed() & 0xffff ) : substr( $raw, 0, 48 );
	}

	private static function describe_call( array $call ): array {
		$out = array(
			'threw'    => (bool) ( $call['threw'] ?? false ),
			'warnings' => $call['warnings'] ?? array(),
		);

		if ( isset( $call['throwable'] ) ) {
			$out['throwable'] = $call['throwable'];
		}
		if ( array_key_exists( 'value', $call ) ) {
			$out['value'] = self::describe_value( $call['value'] );
		}
		if ( array_key_exists( 'output', $call ) ) {
			$out['output'] = $call['output'];
		}

		return $out;
	}

	private static function describe_font_collection( $collection ) {
		if ( ! $collection instanceof \WP_Font_Collection ) {
			return self::describe_value( $collection );
		}

		return array(
			'type' => 'WP_Font_Collection',
			'slug' => $collection->slug,
		);
	}

	private static function describe_error( $value ) {
		if ( ! $value instanceof \WP_Error ) {
			return self::describe_value( $value );
		}

		return array(
			'type'    => 'WP_Error',
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
		);
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			$printable = preg_replace_callback(
				'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
				static function ( array $m ): string {
					return sprintf( '\\x%02X', ord( $m[0] ) );
				},
				$value
			);

			if ( strlen( $printable ) > 220 ) {
				return substr( $printable, 0, 220 ) . '...';
			}

			return $printable;
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
				if ( $i >= 18 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::describe_value( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}

			if ( $value instanceof \WP_Error ) {
				return self::describe_error( $value );
			}

			if ( $value instanceof \WP_Font_Collection ) {
				return self::describe_font_collection( $value );
			}

			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'type'    => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
