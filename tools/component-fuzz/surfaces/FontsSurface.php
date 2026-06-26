<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes the no-DB WordPress Fonts APIs.
 */
final class FontsSurface {
	public const NAME = 'fonts';

	private const FONT_FACE_CASES = 10;
	private const COLLECTION_CASES = 5;
	private const REST_FONT_FACE_CASES = 6;

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
			$rows[] = self::check_theme_json_font_face_resolver_and_default_print( $ctx->fork( 'theme-json-font-face' ) );
			$rows[] = self::check_font_dir_filters( $ctx->fork( 'font-dir' ) );
			$rows[] = self::check_font_library_lifecycle( $ctx->fork( 'font-library' ) );
			$rows[] = self::check_font_collection_json_sources( $ctx->fork( 'font-collection-json' ) );
			$rows[] = self::check_rest_font_collection_controller_boundaries( $ctx->fork( 'rest-font-collections' ) );
			$rows[] = self::check_font_utils_normalization( $ctx->fork( 'font-utils' ) );
			$rows[] = self::check_font_utils_schema_sanitization( $ctx->fork( 'font-utils-schema' ) );
			$rows[] = self::check_rest_font_face_prepare_boundaries( $ctx->fork( 'rest-font-face' ) );
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
		self::ensure_rest_font_faces_controller_loaded();
		self::ensure_rest_font_collections_controller_loaded();

		$missing = array();

		foreach (
			array(
				'WP_Error',
				'WP_Font_Collection',
				'WP_Font_Face',
				'WP_Font_Face_Resolver',
				'WP_Font_Library',
				'WP_Font_Utils',
				'WP_HTML_Tag_Processor',
				'WP_Post',
				'WP_REST_Font_Collections_Controller',
				'WP_REST_Font_Faces_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_Theme_JSON',
				'WP_Theme_JSON_Data',
				'WP_Theme_JSON_Resolver',
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
				'add_query_arg',
				'apply_filters',
				'doing_filter',
				'get_theme_file_uri',
				'has_filter',
				'is_wp_error',
				'remove_filter',
				'rest_ensure_response',
				'rest_is_field_included',
				'rest_url',
				'rest_validate_value_from_schema',
				'sanitize_text_field',
				'sanitize_title',
				'sanitize_url',
				'urlencode_deep',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
				'wp_font_dir',
				'wp_get_global_settings',
				'wp_get_font_dir',
				'wp_http_validate_url',
				'wp_json_encode',
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

	private static function ensure_rest_font_faces_controller_loaded(): void {
		if ( class_exists( 'WP_REST_Font_Faces_Controller' ) ) {
			return;
		}

		$base = defined( 'ABSPATH' ) ? ABSPATH : \ComponentFuzz\repo_root() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
		$path = $base . 'wp-includes/rest-api/endpoints/class-wp-rest-font-faces-controller.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	private static function ensure_rest_font_collections_controller_loaded(): void {
		if ( class_exists( 'WP_REST_Font_Collections_Controller' ) ) {
			return;
		}

		$base = defined( 'ABSPATH' ) ? ABSPATH : \ComponentFuzz\repo_root() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
		$path = $base . 'wp-includes/rest-api/endpoints/class-wp-rest-font-collections-controller.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
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

	private static function check_theme_json_font_face_resolver_and_default_print( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$case           = self::theme_json_font_face_resolver_case( $ctx );
		$resolved_files = array();

		$theme_json_filter = static function () use ( $case ): \WP_Theme_JSON_Data {
			return new \WP_Theme_JSON_Data( $case['themeJson'], 'theme' );
		};
		$theme_file_uri_filter = static function ( string $url, string $file ) use ( $case, &$resolved_files ): string {
			$resolved_files[] = $file;
			return $case['themeFileBaseUrl'] . ltrim( $file, '/' );
		};
		$user_theme_json_filter = static function (): \WP_Theme_JSON_Data {
			return new \WP_Theme_JSON_Data( array( 'version' => \WP_Theme_JSON::LATEST_SCHEMA ), 'custom' );
		};
		$posts_pre_query_filter = static function ( $posts, $query ) {
			return 'wp_global_styles' === $query->get( 'post_type' ) ? array() : $posts;
		};

		\add_filter( 'wp_theme_json_data_theme', $theme_json_filter, 10 );
		\add_filter( 'theme_file_uri', $theme_file_uri_filter, 10, 2 );
		\add_filter( 'wp_theme_json_data_user', $user_theme_json_filter, 10 );
		\add_filter( 'posts_pre_query', $posts_pre_query_filter, 10, 2 );
		self::clean_theme_json_caches();

		try {
			$resolver_call = self::capture_doing_it_wrong(
				static function (): array {
					return \WP_Font_Face_Resolver::get_fonts_from_theme_json();
				}
			);
			$fonts         = is_array( $resolver_call['value'] ?? null ) ? $resolver_call['value'] : array();
			$explicit_call = self::capture_output_doing_it_wrong(
				static function () use ( $fonts ): void {
					\wp_print_font_faces( $fonts );
				}
			);
			self::clean_theme_json_caches();
			$default_call = self::capture_output_doing_it_wrong(
				static function (): void {
					\wp_print_font_faces();
				}
			);
		} finally {
			\remove_filter( 'posts_pre_query', $posts_pre_query_filter, 10 );
			\remove_filter( 'wp_theme_json_data_user', $user_theme_json_filter, 10 );
			\remove_filter( 'theme_file_uri', $theme_file_uri_filter, 10 );
			\remove_filter( 'wp_theme_json_data_theme', $theme_json_filter, 10 );
			self::clean_theme_json_caches();
		}

		$flat_fonts      = is_array( $fonts ) && array() !== $fonts ? array_merge( array(), ...array_map( 'array_values', $fonts ) ) : array();
		$explicit_output = (string) ( $explicit_call['output'] ?? '' );
		$default_output  = (string) ( $default_call['output'] ?? '' );
		$blocks          = self::font_face_blocks( $default_output );

		self::collect_failure(
			$failures,
			! $resolver_call['threw']
				&& self::normalize_key_order( $case['expectedFonts'] ) === self::normalize_key_order( $fonts )
				&& array() === $resolver_call['warnings'],
			'WP_Font_Face_Resolver::get_fonts_from_theme_json converts valid theme.json fontFace entries and skips invalid families',
			array(
				'expected' => $case['expectedFonts'],
				'actual'   => $fonts,
				'call'     => self::describe_call( $resolver_call ),
			)
		);

		self::collect_failure(
			$failures,
			count( $flat_fonts ) === $case['expectedFaceCount']
				&& ! self::contains_any_key( $flat_fonts, $case['camelCaseKeys'] )
				&& $case['expectedThemeFiles'] === array_values( array_unique( $resolved_files ) )
				&& ! str_contains( (string) \wp_json_encode( $fonts ), 'file:./' ),
			'theme.json fontFace properties are kebab-cased and file placeholders resolve to theme URLs',
			array(
				'flatFonts'          => $flat_fonts,
				'camelCaseKeys'      => $case['camelCaseKeys'],
				'resolvedFiles'      => $resolved_files,
				'uniqueResolvedFiles' => array_values( array_unique( $resolved_files ) ),
				'expectedThemeFiles' => $case['expectedThemeFiles'],
			)
		);

		self::collect_failure(
			$failures,
			! $explicit_call['threw']
				&& ! $default_call['threw']
				&& array() === ( $explicit_call['warnings'] ?? array() )
				&& array() === ( $default_call['warnings'] ?? array() )
				&& $explicit_output === $default_output
				&& count( $blocks ) === $case['expectedFaceCount']
				&& str_contains( $default_output, $case['themeFileBaseUrl'] . $case['expectedThemeFiles'][0] )
				&& ! str_contains( $default_output, 'file:./' )
				&& ! str_contains( $default_output, $case['invalidFamilyNeedle'] ),
			'default wp_print_font_faces() output matches explicit resolver output and excludes skipped theme.json entries',
			array(
				'explicit' => self::describe_call( $explicit_call ),
				'default'  => self::describe_call( $default_call ),
				'blocks'   => $blocks,
			)
		);

		self::collect_failure(
			$failures,
			self::has_declaration( $blocks[0] ?? '', 'font-family', self::expected_font_family( $case['firstFamilyName'] ) )
				&& ! str_contains( $blocks[0] ?? '', $case['commaFallbackNeedle'] )
				&& self::has_declaration( $blocks[0] ?? '', 'font-feature-settings', '"kern" 1' )
				&& self::has_declaration( $blocks[0] ?? '', 'font-variation-settings', '"wght" 420' ),
			'comma-separated fontFamily uses the first family name and preserves converted fontFace declarations',
			array(
				'firstBlock' => $blocks[0] ?? '',
				'expected'   => array(
					'fontFamily'            => self::expected_font_family( $case['firstFamilyName'] ),
					'fontFeatureSettings'   => '"kern" 1',
					'fontVariationSettings' => '"wght" 420',
				),
			)
		);

		return self::row(
			$ctx,
			'fonts.theme-json.resolver-and-default-print-no-db',
			array() === $failures,
			array(
				'families' => count( $fonts ),
				'faces'    => count( $flat_fonts ),
				'failures' => array_slice( $failures, 0, 8 ),
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

	private static function check_rest_font_collection_controller_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::rest_font_collection_controller_case( $ctx );

		self::reset_font_library();

		foreach ( $case['collections'] as $collection ) {
			\wp_register_font_collection( $collection['slug'], $collection['args'] );
		}
		\wp_register_font_collection(
			$case['invalidSlug'],
			array(
				'name'          => 'Component Fuzz Invalid REST Collection',
				'font_families' => $case['missingJsonPath'],
			)
		);

		$controller             = new \WP_REST_Font_Collections_Controller();
		$prepared_filter_calls  = array();
		$collection_param_calls = 0;
		$home_filter            = static function (): string {
			return 'http://example.test';
		};
		$empty_filter           = static function (): string {
			return '';
		};
		$prepare_filter         = static function ( $response, $item, $request ) use ( &$prepared_filter_calls ) {
			$prepared_filter_calls[] = array(
				'slug'   => $item instanceof \WP_Font_Collection ? $item->slug : null,
				'method' => $request instanceof \WP_REST_Request ? $request->get_method() : null,
				'fields' => $request instanceof \WP_REST_Request ? $request->get_param( '_fields' ) : null,
			);

			if ( $response instanceof \WP_REST_Response && $item instanceof \WP_Font_Collection ) {
				$response->header( 'X-Component-Fuzz-Collection', $item->slug );
			}

			return $response;
		};
		$params_filter          = static function ( array $params ) use ( &$collection_param_calls ): array {
			++$collection_param_calls;
			$params['component_fuzz_marker'] = array( 'type' => 'string' );
			return $params;
		};

		\add_filter( 'pre_option_home', $home_filter, 0 );
		\add_filter( 'pre_option_siteurl', $home_filter, 0 );
		\add_filter( 'pre_option_permalink_structure', $empty_filter, 0 );
		\add_filter( 'rest_prepare_font_collection', $prepare_filter, 10, 3 );
		\add_filter( 'rest_font_collections_collection_params', $params_filter, 10 );

		try {
			$params = $controller->get_collection_params();

			$page_one_call = self::capture_doing_it_wrong(
				static function () use ( $controller ): \WP_REST_Response {
					return $controller->get_items(
						self::rest_font_collection_request(
							'GET',
							'/wp/v2/font-collections',
							array(
								'context'  => 'view',
								'page'     => 1,
								'per_page' => 2,
								'_fields'  => 'slug,name,_links',
							)
						)
					);
				}
			);
			$page_two_call = self::capture_doing_it_wrong(
				static function () use ( $controller ): \WP_REST_Response {
					return $controller->get_items(
						self::rest_font_collection_request(
							'GET',
							'/wp/v2/font-collections',
							array(
								'context'  => 'view',
								'page'     => 2,
								'per_page' => 2,
								'_fields'  => 'slug,name',
							)
						)
					);
				}
			);
			$head_call     = self::capture_doing_it_wrong(
				static function () use ( $controller ): \WP_REST_Response {
					return $controller->get_items(
						self::rest_font_collection_request(
							'HEAD',
							'/wp/v2/font-collections',
							array(
								'context'  => 'view',
								'page'     => 1,
								'per_page' => 2,
								'_fields'  => 'slug,name',
							)
						)
					);
				}
			);
			$item_call     = self::capture_doing_it_wrong(
				static function () use ( $controller, $case ): \WP_REST_Response {
					return $controller->get_item(
						self::rest_font_collection_request(
							'GET',
							'/wp/v2/font-collections/' . $case['collections'][0]['slug'],
							array(
								'context' => 'view',
								'_fields' => 'slug,_links',
								'slug'    => $case['collections'][0]['slug'],
							)
						)
					);
				}
			);
			$missing_call  = self::capture_doing_it_wrong(
				static function () use ( $controller ): \WP_Error {
					return $controller->get_item(
						self::rest_font_collection_request(
							'GET',
							'/wp/v2/font-collections/component-fuzz-missing',
							array(
								'context' => 'view',
								'slug'    => 'component-fuzz-missing',
							)
						)
					);
				}
			);
		} finally {
			\remove_filter( 'rest_font_collections_collection_params', $params_filter, 10 );
			\remove_filter( 'rest_prepare_font_collection', $prepare_filter, 10 );
			\remove_filter( 'pre_option_permalink_structure', $empty_filter, 0 );
			\remove_filter( 'pre_option_siteurl', $home_filter, 0 );
			\remove_filter( 'pre_option_home', $home_filter, 0 );
		}

		$page_one = $page_one_call['value'] ?? null;
		$page_two = $page_two_call['value'] ?? null;
		$head     = $head_call['value'] ?? null;
		$item     = $item_call['value'] ?? null;
		$missing  = $missing_call['value'] ?? null;

		self::collect_failure(
			$failures,
			is_array( $params )
				&& ! isset( $params['search'] )
				&& isset( $params['component_fuzz_marker'] )
				&& 1 === $collection_param_calls,
			'REST font collections params remove search and run the collection params filter once',
			array(
				'params' => $params,
				'calls'  => $collection_param_calls,
			)
		);

		self::collect_failure(
			$failures,
			$page_one instanceof \WP_REST_Response
				&& array() === ( $page_one_call['warnings'] ?? array() )
				&& 200 === $page_one->get_status()
				&& self::rest_collection_headers_match( $page_one, 4, 2, 'next' )
				&& self::rest_collection_page_one_body_matches_case( $page_one->get_data(), $case ),
			'REST font collections GET page body, totals, next link, _fields, and compact _links are stable',
			array(
				'call'    => self::describe_call( $page_one_call ),
				'headers' => $page_one instanceof \WP_REST_Response ? $page_one->get_headers() : null,
				'data'    => $page_one instanceof \WP_REST_Response ? $page_one->get_data() : null,
			)
		);

		self::collect_failure(
			$failures,
			$page_two instanceof \WP_REST_Response
				&& 200 === $page_two->get_status()
				&& self::rest_collection_headers_match( $page_two, 4, 2, 'prev' )
				&& self::rest_collection_page_two_body_matches_case( $page_two->get_data(), $case )
				&& self::warnings_match_functions(
					$page_two_call['warnings'] ?? array(),
					array( 'WP_Font_Collection::load_from_json' )
				),
			'REST font collections GET skips invalid collection data without failing the bounded page',
			array(
				'call'    => self::describe_call( $page_two_call ),
				'headers' => $page_two instanceof \WP_REST_Response ? $page_two->get_headers() : null,
				'data'    => $page_two instanceof \WP_REST_Response ? $page_two->get_data() : null,
			)
		);

		self::collect_failure(
			$failures,
			$head instanceof \WP_REST_Response
				&& array() === ( $head_call['warnings'] ?? array() )
				&& 200 === $head->get_status()
				&& array() === $head->get_data()
				&& self::rest_collection_headers_match( $head, 4, 2, 'next' ),
			'REST font collections HEAD returns headers and no response body',
			array(
				'call'    => self::describe_call( $head_call ),
				'headers' => $head instanceof \WP_REST_Response ? $head->get_headers() : null,
				'data'    => $head instanceof \WP_REST_Response ? $head->get_data() : null,
			)
		);

		self::collect_failure(
			$failures,
			$item instanceof \WP_REST_Response
				&& array() === ( $item_call['warnings'] ?? array() )
				&& 200 === $item->get_status()
				&& array( 'slug' => $case['collections'][0]['slug'] ) === $item->get_data()
				&& isset( $item->get_links()['self'][0]['href'], $item->get_links()['collection'][0]['href'] )
				&& $case['collections'][0]['slug'] === ( $item->get_headers()['X-Component-Fuzz-Collection'] ?? null ),
			'REST font collection get_item/prepare honors _fields, _links, and rest_prepare_font_collection',
			array(
				'call'    => self::describe_call( $item_call ),
				'headers' => $item instanceof \WP_REST_Response ? $item->get_headers() : null,
				'links'   => $item instanceof \WP_REST_Response ? $item->get_links() : null,
				'data'    => $item instanceof \WP_REST_Response ? $item->get_data() : null,
			)
		);

		self::collect_failure(
			$failures,
			$missing instanceof \WP_Error
				&& array() === ( $missing_call['warnings'] ?? array() )
				&& 'rest_font_collection_not_found' === $missing->get_error_code()
				&& 404 === ( $missing->get_error_data()['status'] ?? null ),
			'REST font collection get_item returns a 404 WP_Error for missing slugs',
			array( 'call' => self::describe_call( $missing_call ) )
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'rest_prepare_font_collection', $prepare_filter )
				&& false === \has_filter( 'rest_font_collections_collection_params', $params_filter )
				&& false === \has_filter( 'pre_option_home', $home_filter )
				&& false === \has_filter( 'pre_option_siteurl', $home_filter )
				&& false === \has_filter( 'pre_option_permalink_structure', $empty_filter )
				&& count( $prepared_filter_calls ) >= 5,
			'REST font collection temporary filters are cleaned up after bounded calls',
			array(
				'preparedFilterCalls' => $prepared_filter_calls,
				'hasFilters'          => array(
					'restPrepare'          => \has_filter( 'rest_prepare_font_collection', $prepare_filter ),
					'collectionParams'     => \has_filter( 'rest_font_collections_collection_params', $params_filter ),
					'preOptionHome'        => \has_filter( 'pre_option_home', $home_filter ),
					'preOptionSiteurl'     => \has_filter( 'pre_option_siteurl', $home_filter ),
					'preOptionPermalink'   => \has_filter( 'pre_option_permalink_structure', $empty_filter ),
				),
			)
		);

		return self::row(
			$ctx,
			'fonts.rest-font-collections.controller-boundaries-no-db',
			array() === $failures,
			array(
				'collections' => count( $case['collections'] ) + 1,
				'failures'    => array_slice( $failures, 0, 8 ),
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

	private static function check_rest_font_face_prepare_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures  = array();
		$controller = self::rest_font_face_controller();

		foreach ( self::rest_font_face_cases( $ctx ) as $index => $case ) {
			$json       = \wp_json_encode( $case['settings'] );
			$validation = $controller->validate_create_font_face_settings(
				$json,
				self::rest_font_face_request( $case['settings'], $case['files'], $case['parentId'] )
			);
			$sanitized  = $controller->sanitize_font_face_settings( $json );
			$prepared   = $controller->component_fuzz_prepare_item_for_database(
				self::rest_font_face_request( $sanitized, $case['files'], $case['parentId'] )
			);

			self::collect_failure(
				$failures,
				true === $validation
					&& is_array( $sanitized )
					&& self::rest_sanitized_font_face_matches_case( $sanitized, $case )
					&& self::rest_prepared_font_face_matches_case( $prepared, $sanitized, $case ),
				"REST font-face create settings validate, sanitize, and prepare post data case {$index}",
				array(
					'case'       => $case['label'],
					'validation' => self::describe_value( $validation ),
					'sanitized'  => $sanitized,
					'prepared'   => self::describe_value( $prepared ),
					'expected'   => $case['expected'],
				)
			);

			$post     = self::rest_font_face_post(
				$case['postId'],
				$case['parentId'],
				\wp_json_encode( $sanitized + array( 'componentFuzzUnknown' => 'drop-me' ) )
			);
			$response = $controller->prepare_item_for_response( $post, self::rest_font_face_response_request( $case['parentId'] ) );
			$data     = $response instanceof \WP_REST_Response ? $response->get_data() : null;

			self::collect_failure(
				$failures,
				is_array( $data )
					&& $case['postId'] === ( $data['id'] ?? null )
					&& $case['parentId'] === ( $data['parent'] ?? null )
					&& \WP_REST_Font_Faces_Controller::LATEST_THEME_JSON_VERSION_SUPPORTED === ( $data['theme_json_version'] ?? null )
					&& $sanitized === ( $data['font_face_settings'] ?? null )
					&& ! isset( $data['font_face_settings']['componentFuzzUnknown'] ),
				"REST font-face response preparation exposes schema fields and strips unknown settings case {$index}",
				array(
					'case'     => $case['label'],
					'response' => self::describe_value( $data ),
					'expected' => array(
						'id'                 => $case['postId'],
						'parent'             => $case['parentId'],
						'themeJsonVersion'   => \WP_REST_Font_Faces_Controller::LATEST_THEME_JSON_VERSION_SUPPORTED,
						'fontFaceSettings'   => $sanitized,
						'unknownKeyExcluded' => true,
					),
				)
			);
		}

		$duplicate_case = self::rest_font_face_duplicate_slug_case( $ctx->fork( 'duplicate-slug' ) );
		$prepared_a     = $controller->component_fuzz_prepare_item_for_database(
			self::rest_font_face_request( $duplicate_case['a'], array(), $duplicate_case['parentId'] )
		);
		$prepared_b     = $controller->component_fuzz_prepare_item_for_database(
			self::rest_font_face_request( $duplicate_case['b'], array(), $duplicate_case['parentId'] )
		);

		self::collect_failure(
			$failures,
			is_object( $prepared_a )
				&& is_object( $prepared_b )
				&& $prepared_a->post_title === $prepared_b->post_title
				&& $prepared_a->post_name === $prepared_b->post_name
				&& $prepared_a->post_content !== $prepared_b->post_content,
			'REST font-face prepared post slug is stable across equivalent matching settings',
			array(
				'case'      => $duplicate_case,
				'preparedA' => self::describe_value( $prepared_a ),
				'preparedB' => self::describe_value( $prepared_b ),
			)
		);

		$invalid_post      = self::rest_font_face_post( 970000 + $ctx->iteration(), 970100 + $ctx->iteration(), "{not-json\n" );
		$invalid_response  = $controller->prepare_item_for_response( $invalid_post, self::rest_font_face_response_request( 970100 + $ctx->iteration() ) );
		$invalid_data      = $invalid_response instanceof \WP_REST_Response ? $invalid_response->get_data() : null;
		$invalid_settings  = is_array( $invalid_data ) ? ( $invalid_data['font_face_settings'] ?? null ) : null;
		$invalid_creations = self::rest_font_face_invalid_create_cases( $ctx->fork( 'invalid-create' ) );

		self::collect_failure(
			$failures,
			array( 'fontFamily' => '', 'src' => array() ) === $invalid_settings,
			'REST font-face response preparation falls back for invalid stored JSON',
			array(
				'response' => self::describe_value( $invalid_data ),
				'expected' => array( 'fontFamily' => '', 'src' => array() ),
			)
		);

		foreach ( $invalid_creations as $index => $case ) {
			$validation = $controller->validate_create_font_face_settings(
				$case['json'],
				self::rest_font_face_request( array(), $case['files'], $case['parentId'] )
			);

			self::collect_failure(
				$failures,
				$validation instanceof \WP_Error
					&& $case['expectedCode'] === $validation->get_error_code()
					&& 400 === ( $validation->get_error_data()['status'] ?? null ),
				"REST font-face create validation rejects boundary case {$index}",
				array(
					'case'           => $case,
					'validationCode' => $validation instanceof \WP_Error ? $validation->get_error_code() : null,
					'validation'     => self::describe_value( $validation ),
				)
			);
		}

		return self::row(
			$ctx,
			'fonts.rest-font-face.prepare-boundaries',
			array() === $failures,
			array(
				'cases'    => self::REST_FONT_FACE_CASES,
				'invalids' => count( $invalid_creations ),
				'failures' => array_slice( $failures, 0, 8 ),
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

	private static function theme_json_font_face_resolver_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token           = self::slug( $ctx, 'theme-face' );
		$family_a        = 'Component Fuzz Theme ' . $token;
		$family_b        = 'ComponentFuzzTheme' . str_replace( '-', '', $token );
		$regular_file    = 'assets/fonts/' . $token . '-regular.woff2';
		$italic_file     = 'assets/fonts/' . $token . '-italic.woff2';
		$theme_file_base = 'https://theme.example.test/';
		$remote_woff     = 'https://cdn.example.test/fonts/' . $token . '-regular.woff';
		$remote_ttf      = 'https://cdn.example.test/fonts/' . $token . '-mono.ttf';
		$invalid_family  = 'Component Fuzz Invalid Theme ' . $token;

		$expected_fonts = array(
			array(
				array(
					'font-style'               => 'normal',
					'font-weight'              => '400',
					'font-stretch'             => 'normal',
					'font-display'             => 'swap',
					'font-feature-settings'    => '"kern" 1',
					'font-variation-settings'  => '"wght" 420',
					'unicode-range'            => 'U+00-5FF',
					'src'                      => array( $theme_file_base . $regular_file, $remote_woff ),
					'font-family'              => $family_a,
				),
				array(
					'font-style'          => 'italic',
					'font-weight'         => '700',
					'font-display'        => 'optional',
					'ascent-override'     => '90%',
					'descent-override'    => '-20%',
					'line-gap-override'   => '0%',
					'size-adjust'         => '105%',
					'src'                 => array( $theme_file_base . $italic_file ),
					'font-family'         => $family_a,
				),
			),
			array(
				array(
					'font-style'   => 'normal',
					'font-weight'  => '300',
					'font-display' => 'fallback',
					'src'          => array( $remote_ttf ),
					'font-family'  => $family_b,
				),
			),
		);

		return array(
			'themeFileBaseUrl'    => $theme_file_base,
			'expectedThemeFiles'  => array( $regular_file, $italic_file ),
			'firstFamilyName'     => $family_a,
			'commaFallbackNeedle' => 'ui-sans-serif',
			'invalidFamilyNeedle' => $invalid_family,
			'expectedFaceCount'   => 3,
			'camelCaseKeys'       => array(
				'ascentOverride',
				'descentOverride',
				'fontDisplay',
				'fontFamily',
				'fontFeatureSettings',
				'fontStretch',
				'fontStyle',
				'fontVariationSettings',
				'fontWeight',
				'lineGapOverride',
				'sizeAdjust',
				'unicodeRange',
			),
			'expectedFonts'       => $expected_fonts,
			'themeJson'           => array(
				'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => array(
					'typography' => array(
						'fontFamilies' => array(
							'theme'  => array(
								array(
									'name'       => $family_a,
									'slug'       => $token,
									'fontFamily' => '"' . $family_a . '", ui-sans-serif, sans-serif',
									'fontFace'   => array(
										array(
											'fontFamily'            => 'Ignored Face Family ' . $token,
											'fontStyle'             => 'normal',
											'fontWeight'            => '400',
											'fontStretch'           => 'normal',
											'fontDisplay'           => 'swap',
											'fontFeatureSettings'   => '"kern" 1',
											'fontVariationSettings' => '"wght" 420',
											'unicodeRange'          => 'U+00-5FF',
											'src'                   => array( 'file:./' . $regular_file, $remote_woff ),
										),
										array(
											'fontStyle'        => 'italic',
											'fontWeight'       => '700',
											'fontDisplay'      => 'optional',
											'ascentOverride'   => '90%',
											'descentOverride'  => '-20%',
											'lineGapOverride'  => '0%',
											'sizeAdjust'       => '105%',
											'src'              => 'file:./' . $italic_file,
										),
									),
								),
								array(
									'name'       => $invalid_family,
									'slug'       => 'missing-face-' . $token,
									'fontFamily' => $invalid_family,
								),
								array(
									'name'       => 'Empty First Family ' . $token,
									'slug'       => 'empty-first-' . $token,
									'fontFamily' => ', serif',
									'fontFace'   => array(
										array(
											'fontWeight' => '400',
											'src'        => 'https://cdn.example.test/fonts/skipped-' . $token . '.woff2',
										),
									),
								),
							),
							'custom' => array(
								array(
									'name'       => $family_b,
									'slug'       => strtolower( $family_b ),
									'fontFamily' => $family_b,
									'fontFace'   => array(
										array(
											'fontStyle'   => 'normal',
											'fontWeight'  => '300',
											'fontDisplay' => 'fallback',
											'src'         => $remote_ttf,
										),
									),
								),
								array(
									'name'       => 'Empty Font Family ' . $token,
									'slug'       => 'empty-family-' . $token,
									'fontFamily' => '',
									'fontFace'   => array(
										array(
											'fontWeight' => '400',
											'src'        => 'https://cdn.example.test/fonts/empty-' . $token . '.woff2',
										),
									),
								),
							),
						),
					),
				),
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

	private static function rest_font_collection_controller_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$collections = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$case_ctx = $ctx->fork( 'rest-collection-' . $i );
			$token    = self::slug( $case_ctx, 'rest-collection' );
			$family   = 'REST Collection Family ' . $case_ctx->int( 100, 999 );
			$slug     = 'component-fuzz-rest-' . $token;

			$collections[] = array(
				'slug' => $slug,
				'args' => array(
					'name'          => 'REST Collection ' . $case_ctx->int( 1000, 9999 ),
					'description'   => 'REST collection description ' . $token,
					'font_families' => array(
						array(
							'font_family_settings' => array(
								'name'       => $family,
								'slug'       => $family,
								'fontFamily' => '"' . $family . '", serif',
								'fontFace'   => array(
									array(
										'fontFamily'  => $family,
										'fontStyle'   => 'normal',
										'fontWeight'  => '400',
										'src'         => array( 'https://example.test/fonts/' . $token . '.woff2' ),
										'fontDisplay' => 'swap',
									),
								),
							),
							'categories'           => array( 'rest fuzz' ),
						),
					),
					'categories'    => array(
						array(
							'name' => 'REST Fuzz',
							'slug' => 'rest-fuzz',
						),
					),
				),
			);
		}

		return array(
			'collections'     => $collections,
			'invalidSlug'     => 'component-fuzz-rest-invalid-' . self::slug( $ctx->fork( 'invalid' ), 'collection' ),
			'missingJsonPath' => rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-missing-font-collection-' . self::slug( $ctx->fork( 'missing' ), 'json' ) . '.json',
		);
	}

	private static function rest_font_face_controller(): \WP_REST_Font_Faces_Controller {
		return new class() extends \WP_REST_Font_Faces_Controller {
			public function __construct() {
				$this->post_type = 'wp_font_face';
				$this->rest_base = 'font-families/(?P<font_family_id>[\d]+)/font-faces';
				$this->namespace = 'wp/v2';
			}

			public function component_fuzz_prepare_item_for_database( \WP_REST_Request $request ) {
				return $this->prepare_item_for_database( $request );
			}
		};
	}

	private static function rest_font_face_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		for ( $i = 0; $i < self::REST_FONT_FACE_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'rest-case-' . $i );
			$token    = self::slug( $case_ctx, 'rest-face' );
			$file_key = 'file-' . str_replace( '-', '_', self::slug( $case_ctx->fork( 'file' ), 'font' ) );
			$remote   = '  https://example.test/fonts/' . $token . '.woff2';
			$src      = 0 === $i % 3 ? $remote : array( $remote, $file_key );

			if ( 2 === $i % 3 ) {
				$src = array( $file_key, $remote );
			}

			$settings = array(
				'fontFamily'          => '<b>Component Fuzz REST ' . $token . '</b>, serif',
				'fontStyle'           => $case_ctx->choice( array( 'normal', 'italic', 'oblique 10deg' ) ),
				'fontWeight'          => $case_ctx->choice( array( '400', '700', 700, '100 900' ) ),
				'fontDisplay'         => $case_ctx->choice( array( 'auto', 'block', 'fallback', 'swap', 'optional' ) ),
				'src'                 => $src,
				'fontStretch'         => $case_ctx->choice( array( 'normal', 'condensed', '125%' ) ),
				'fontFeatureSettings' => '"kern" 1 <i>ignored</i>',
				'unicodeRange'        => 'U+00-' . strtoupper( dechex( 255 + $i + $case_ctx->int( 0, 31 ) ) ),
				'preview'             => 'https://example.test/previews/' . $token . '.png',
			);
			$files    = is_array( $src ) && in_array( $file_key, $src, true )
				? array(
					$file_key => array(
						'name'     => $token . '.woff2',
						'type'     => 'font/woff2',
						'tmp_name' => '/tmp/component-fuzz-' . $token . '.woff2',
						'error'    => 0,
						'size'     => $case_ctx->int( 128, 4096 ),
					),
				)
				: array();

			$cases[] = array(
				'label'    => $token,
				'parentId' => 960000 + $ctx->iteration() * 100 + $i,
				'postId'   => 961000 + $ctx->iteration() * 100 + $i,
				'settings' => $settings,
				'files'    => $files,
				'expected' => array(
					'fontFamily' => \WP_Font_Utils::sanitize_font_family( $settings['fontFamily'] ),
					'src'        => self::expected_rest_src( $settings['src'] ),
				),
			);
		}

		return $cases;
	}

	private static function rest_sanitized_font_face_matches_case( array $sanitized, array $case ): bool {
		$settings = $case['settings'];

		return $case['expected']['fontFamily'] === ( $sanitized['fontFamily'] ?? null )
			&& $case['expected']['src'] === ( $sanitized['src'] ?? null )
			&& $settings['fontStyle'] === ( $sanitized['fontStyle'] ?? null )
			&& (string) $settings['fontWeight'] === (string) ( $sanitized['fontWeight'] ?? null )
			&& $settings['fontDisplay'] === ( $sanitized['fontDisplay'] ?? null )
			&& $settings['fontStretch'] === ( $sanitized['fontStretch'] ?? null )
			&& '"kern" 1 ignored' === ( $sanitized['fontFeatureSettings'] ?? null )
			&& $settings['unicodeRange'] === ( $sanitized['unicodeRange'] ?? null )
			&& $settings['preview'] === ( $sanitized['preview'] ?? null )
			&& ! str_contains( $sanitized['fontFamily'] ?? '', '<' )
			&& ! str_contains( $sanitized['fontFeatureSettings'] ?? '', '<' );
	}

	private static function rest_prepared_font_face_matches_case( $prepared, array $sanitized, array $case ): bool {
		if ( ! is_object( $prepared ) ) {
			return false;
		}

		$title = \WP_Font_Utils::get_font_face_slug( $sanitized );

		return 'wp_font_face' === ( $prepared->post_type ?? null )
			&& $case['parentId'] === ( $prepared->post_parent ?? null )
			&& 'publish' === ( $prepared->post_status ?? null )
			&& $title === ( $prepared->post_title ?? null )
			&& \sanitize_title( $title ) === ( $prepared->post_name ?? null )
			&& \wp_json_encode( $sanitized ) === ( $prepared->post_content ?? null );
	}

	private static function rest_font_face_duplicate_slug_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::slug( $ctx, 'dup-face' );

		return array(
			'parentId' => 968000 + $ctx->iteration(),
			'a'        => array(
				'fontFamily'   => '"Component Fuzz ' . $token . '", serif',
				'fontStyle'    => 'NORMAL',
				'fontWeight'   => 'bold',
				'fontStretch'  => 'normal',
				'unicodeRange' => 'u+00-ff',
				'src'          => 'https://example.test/fonts/' . $token . '-a.woff2',
			),
			'b'        => array(
				'fontFamily'   => "'component fuzz " . $token . "',serif",
				'fontStyle'    => 'normal',
				'fontWeight'   => '700',
				'fontStretch'  => '100%',
				'unicodeRange' => 'U+00-FF',
				'src'          => 'https://example.test/fonts/' . $token . '-b.woff2',
			),
		);
	}

	private static function rest_font_face_invalid_create_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::slug( $ctx, 'invalid-rest-face' );

		return array(
			array(
				'label'        => 'invalid-json',
				'parentId'     => 969000 + $ctx->iteration(),
				'json'         => "{not-json\n",
				'files'        => array(),
				'expectedCode' => 'rest_invalid_param',
			),
			array(
				'label'        => 'empty-required-src',
				'parentId'     => 969100 + $ctx->iteration(),
				'json'         => \wp_json_encode(
					array(
						'fontFamily' => 'Component Fuzz Empty Src ' . $token,
						'src'        => '',
					)
				),
				'files'        => array(),
				'expectedCode' => 'rest_invalid_param',
			),
			array(
				'label'        => 'bad-src-reference',
				'parentId'     => 969200 + $ctx->iteration(),
				'json'         => \wp_json_encode(
					array(
						'fontFamily' => 'Component Fuzz Bad Src ' . $token,
						'src'        => 'not-a-url-or-file',
					)
				),
				'files'        => array(),
				'expectedCode' => 'rest_invalid_param',
			),
			array(
				'label'        => 'unused-upload-file',
				'parentId'     => 969300 + $ctx->iteration(),
				'json'         => \wp_json_encode(
					array(
						'fontFamily' => 'Component Fuzz Unused File ' . $token,
						'src'        => 'https://example.test/fonts/' . $token . '.woff2',
					)
				),
				'files'        => array(
					'file-' . $token => array(
						'name'     => $token . '.woff2',
						'type'     => 'font/woff2',
						'tmp_name' => '/tmp/component-fuzz-' . $token . '.woff2',
						'error'    => 0,
						'size'     => 512,
					),
				),
				'expectedCode' => 'rest_invalid_param',
			),
		);
	}

	private static function expected_rest_src( $src ) {
		if ( is_array( $src ) ) {
			return array_map( array( self::class, 'expected_rest_src_item' ), $src );
		}

		return self::expected_rest_src_item( $src );
	}

	private static function expected_rest_src_item( $src ): string {
		$src = ltrim( (string) $src );
		return false === \wp_http_validate_url( $src ) ? $src : \sanitize_url( $src );
	}

	private static function rest_font_face_request( array $settings, array $files, int $parent_id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/font-families/' . $parent_id . '/font-faces' );
		$request->set_param( 'font_family_id', $parent_id );
		$request->set_param( 'font_face_settings', $settings );
		$request->set_file_params( $files );
		return $request;
	}

	private static function rest_font_face_response_request( int $parent_id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/font-families/' . $parent_id . '/font-faces/1' );
		$request->set_param( 'font_family_id', $parent_id );
		$request->set_param( 'context', 'view' );
		$request->set_param( '_fields', 'id,parent,theme_json_version,font_face_settings' );
		return $request;
	}

	private static function rest_font_face_post( int $id, int $parent_id, string $content ): \WP_Post {
		return new \WP_Post(
			(object) array(
				'ID'           => $id,
				'post_parent'  => $parent_id,
				'post_content' => $content,
				'post_title'   => 'component-fuzz-font-face-' . $id,
				'post_name'    => 'component-fuzz-font-face-' . $id,
				'post_status'  => 'publish',
				'post_type'    => 'wp_font_face',
			)
		);
	}

	private static function rest_font_collection_request( string $method, string $path, array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $path );

		$query_params = $params;
		unset( $query_params['slug'] );
		$request->set_query_params( $query_params );

		if ( isset( $params['slug'] ) ) {
			$request->set_url_params( array( 'slug' => $params['slug'] ) );
		}

		return $request;
	}

	private static function rest_collection_headers_match( \WP_REST_Response $response, int $total, int $pages, string $expected_link_rel ): bool {
		$headers = $response->get_headers();

		return $total === ( $headers['X-WP-Total'] ?? null )
			&& $pages === ( $headers['X-WP-TotalPages'] ?? null )
			&& isset( $headers['Link'] )
			&& str_contains( $headers['Link'], 'rel="' . $expected_link_rel . '"' )
			&& str_contains( $headers['Link'], 'http://example.test' );
	}

	private static function rest_collection_page_one_body_matches_case( $data, array $case ): bool {
		if ( ! is_array( $data ) || 2 !== count( $data ) ) {
			return false;
		}

		foreach ( array( 0, 1 ) as $index ) {
			$item = $data[ $index ] ?? null;
			if (
				! is_array( $item )
				|| array( 'slug', 'name', '_links' ) !== array_keys( $item )
				|| $case['collections'][ $index ]['slug'] !== ( $item['slug'] ?? null )
				|| \sanitize_text_field( $case['collections'][ $index ]['args']['name'] ) !== ( $item['name'] ?? null )
				|| ! isset( $item['_links']['self'][0]['href'], $item['_links']['collection'][0]['href'] )
			) {
				return false;
			}
		}

		return true;
	}

	private static function rest_collection_page_two_body_matches_case( $data, array $case ): bool {
		return is_array( $data )
			&& 1 === count( $data )
			&& array( 'slug', 'name' ) === array_keys( $data[0] ?? array() )
			&& $case['collections'][2]['slug'] === ( $data[0]['slug'] ?? null )
			&& \sanitize_text_field( $case['collections'][2]['args']['name'] ) === ( $data[0]['name'] ?? null );
	}

	private static function has_declaration( string $block, string $property, string $value ): bool {
		return str_contains( $block, $property . ':' . $value . ';' );
	}

	private static function contains_any_key( array $data, array $keys ): bool {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, $keys, true ) ) {
				return true;
			}

			if ( is_array( $value ) && self::contains_any_key( $value, $keys ) ) {
				return true;
			}
		}

		return false;
	}

	private static function normalize_key_order( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize_key_order( $item );
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	private static function warnings_match_functions( array $warnings, array $expected_functions ): bool {
		if ( count( $warnings ) !== count( $expected_functions ) ) {
			return false;
		}

		foreach ( $expected_functions as $index => $function_name ) {
			if ( $function_name !== ( $warnings[ $index ]['function'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function clean_theme_json_caches(): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			\wp_cache_delete( 'wp_get_global_settings_custom', 'theme_json' );
			\wp_cache_delete( 'wp_get_global_settings_theme', 'theme_json' );
		}
	}

	private static function snapshot_theme_json_state(): array {
		$state = array(
			'statics' => array(),
			'cache'   => array(),
		);

		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			foreach ( self::theme_json_resolver_static_properties() as $property ) {
				$state['statics'][ $property ] = self::get_static_property( 'WP_Theme_JSON_Resolver', $property );
			}
		}

		if ( function_exists( 'wp_cache_get' ) ) {
			foreach ( self::theme_json_cache_keys() as $cache_key ) {
				$found = null;
				$value = \wp_cache_get( $cache_key, 'theme_json', false, $found );

				$state['cache'][ $cache_key ] = array(
					'exists' => (bool) $found,
					'value'  => $value,
				);
			}
		}

		return $state;
	}

	private static function restore_theme_json_state( array $state ): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			foreach ( $state['statics'] ?? array() as $property => $value ) {
				self::set_static_property( 'WP_Theme_JSON_Resolver', $property, $value );
			}
		}

		if ( function_exists( 'wp_cache_set' ) && function_exists( 'wp_cache_delete' ) ) {
			foreach ( $state['cache'] ?? array() as $cache_key => $entry ) {
				if ( $entry['exists'] ?? false ) {
					\wp_cache_set( $cache_key, $entry['value'], 'theme_json' );
				} else {
					\wp_cache_delete( $cache_key, 'theme_json' );
				}
			}
		}
	}

	private static function theme_json_resolver_static_properties(): array {
		return array(
			'core',
			'blocks',
			'blocks_cache',
			'theme',
			'user',
			'user_custom_post_type_id',
			'i18n_schema',
			'theme_json_file_cache',
		);
	}

	private static function theme_json_cache_keys(): array {
		return array(
			'wp_get_global_settings_custom',
			'wp_get_global_settings_theme',
		);
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

	private static function capture_output_doing_it_wrong( callable $callback ): array {
		$warnings = array();
		$listener = static function ( $function_name, $message, $version ) use ( &$warnings ): void {
			$warnings[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};

		\add_action( 'doing_it_wrong_run', $listener, 10, 3 );
		\ob_start();
		try {
			$value  = $callback();
			$output = \ob_get_clean();
			return array(
				'threw'    => false,
				'value'    => $value,
				'output'   => $output,
				'warnings' => $warnings,
			);
		} catch ( \Throwable $e ) {
			$output = \ob_get_clean();
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'output'    => $output,
				'warnings'  => $warnings,
			);
		} finally {
			\remove_action( 'doing_it_wrong_run', $listener, 10 );
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
			'themeJson'          => self::snapshot_theme_json_state(),
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

		self::restore_theme_json_state( $snapshot['themeJson'] ?? array() );
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
