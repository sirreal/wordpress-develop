<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB style generation APIs.
 */
final class StyleSurface {
	public const NAME = 'style';

	private const PREVIEW_BYTES = 240;
	private const SIDES         = array( 'top', 'right', 'bottom', 'left' );

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'style.bootstrap-apis-available',
					'Required WordPress style APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$ob_level = ob_get_level();
		$rows     = array();

		try {
			self::prepare_block_registry();
			self::set_style_stores( array() );

			$case = self::case_for_context( $ctx );

			$rows[] = self::check_style_engine_block_styles( $ctx, $case );
			$rows[] = self::check_style_engine_preset_boundaries( $ctx, $case );
			$rows[] = self::check_css_declarations_safety( $ctx, $case );
			$rows[] = self::check_stylesheet_rules_and_context_store( $ctx, $case );
			$rows[] = self::check_block_selector_helpers( $ctx );
			$rows[] = self::check_theme_json_schema_migration( $ctx, $case );
			$rows[] = self::check_theme_json_data_merge( $ctx, $case );
			$rows[] = self::check_theme_json_variable_resolution( $ctx, $case );
			$rows[] = self::check_theme_json_stylesheet( $ctx, $case );
			$rows[] = self::check_block_supports_wrapper_attributes_serialization( $ctx, $case );
			$rows[] = self::check_block_style_variation_serialization( $ctx, $case );
			$rows[] = self::check_registered_block_style_variation_source_order( $ctx, $case );
			$rows[] = self::check_theme_style_helper_filter_restoration( $ctx, $case );
			$rows[] = self::check_style_store_cleanup( $ctx );
			$rows[] = self::check_global_stylesheet_guard( $ctx );
			$rows[] = self::check_global_styles_user_data_and_getters( $ctx->fork( 'global-styles-user-data' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'style.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Style_Engine',
				'WP_Style_Engine_CSS_Declarations',
				'WP_Style_Engine_CSS_Rule',
				'WP_Style_Engine_CSS_Rules_Store',
				'WP_Style_Engine_Processor',
				'WP_Theme_JSON',
				'WP_Theme_JSON_Data',
				'WP_Theme_JSON_Resolver',
				'WP_Theme_JSON_Schema',
				'WP_Block_Type',
				'WP_Block_Type_Registry',
				'WP_Block_Styles_Registry',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'wp_style_engine_get_styles',
				'wp_style_engine_get_stylesheet_from_css_rules',
				'wp_style_engine_get_stylesheet_from_context',
				'safecss_filter_attr',
				'register_block_type',
				'unregister_block_type',
				'register_block_style',
				'get_block_editor_theme_styles',
				'wp_get_block_name_from_theme_json_path',
				'wp_get_block_css_selector',
				'wp_get_layout_definitions',
				'wp_get_typography_font_size_value',
				'wp_strip_all_tags',
				'wp_get_global_stylesheet',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function block_support_wrapper_missing_requirements(): array {
		self::load_block_support_wrapper_apis();

		$missing = array();

		foreach ( array( 'WP_Block_Supports', 'WP_HTML_Tag_Processor' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'wp_register_colors_support',
				'wp_apply_colors_support',
				'wp_register_spacing_support',
				'wp_apply_spacing_support',
				'wp_register_border_support',
				'wp_apply_border_support',
				'wp_register_typography_support',
				'wp_apply_typography_support',
				'wp_register_dimensions_support',
				'wp_apply_dimensions_support',
				'wp_register_shadow_support',
				'wp_apply_shadow_support',
				'wp_register_alignment_support',
				'wp_apply_alignment_support',
				'wp_should_skip_block_supports_serialization',
				'block_has_support',
				'get_block_wrapper_attributes',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function load_block_support_wrapper_apis(): void {
		if ( ! class_exists( 'WP_Block_Supports' ) || ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		foreach (
			array(
				'block-supports/utils.php',
				'block-supports/colors.php',
				'block-supports/spacing.php',
				'block-supports/border.php',
				'block-supports/typography.php',
				'block-supports/dimensions.php',
				'block-supports/shadow.php',
				'block-supports/align.php',
			) as $relative_path
		) {
			$path = ABSPATH . WPINC . '/' . $relative_path;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		self::ensure_block_support_callback( 'typography', 'wp_register_typography_support', 'wp_apply_typography_support' );
		self::ensure_block_support_callback( 'colors', 'wp_register_colors_support', 'wp_apply_colors_support' );
		self::ensure_block_support_callback( 'spacing', 'wp_register_spacing_support', 'wp_apply_spacing_support' );
		self::ensure_block_support_callback( 'border', 'wp_register_border_support', 'wp_apply_border_support' );
		self::ensure_block_support_callback( 'dimensions', 'wp_register_dimensions_support', 'wp_apply_dimensions_support' );
		self::ensure_block_support_callback( 'shadow', 'wp_register_shadow_support', 'wp_apply_shadow_support' );
		self::ensure_block_support_callback( 'align', 'wp_register_alignment_support', 'wp_apply_alignment_support' );
	}

	private static function ensure_block_support_callback( string $name, ?string $register_attribute, string $apply ): void {
		if ( ! class_exists( 'WP_Block_Supports' ) || ! function_exists( $apply ) ) {
			return;
		}

		$config = array(
			'apply' => $apply,
		);

		if ( null !== $register_attribute && function_exists( $register_attribute ) ) {
			$config['register_attribute'] = $register_attribute;
		}

		\WP_Block_Supports::get_instance()->register( $name, $config );
	}

	private static function check_style_engine_block_styles( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$options = array(
			'selector'                   => $case['selector'],
			'context'                    => self::context_name( $ctx, 'block-styles' ),
			'convert_vars_to_classnames' => $case['convertVarsToClassnames'],
		);

		$first  = self::call( static fn() => \wp_style_engine_get_styles( $case['blockStyles'], $options ) );
		$second = self::call( static fn() => \wp_style_engine_get_styles( $case['blockStyles'], $options ) );

		$css          = is_array( $first['value'] ) ? (string) ( $first['value']['css'] ?? '' ) : '';
		$declarations = is_array( $first['value'] ) && isset( $first['value']['declarations'] ) && is_array( $first['value']['declarations'] )
			? $first['value']['declarations']
			: array();
		$classnames   = is_array( $first['value'] ) ? (string) ( $first['value']['classnames'] ?? '' ) : '';
		$tokens       = '' === trim( $classnames ) ? array() : preg_split( '/\s+/', trim( $classnames ) );

		$ok = ! $first['threw']
			&& ! $second['threw']
			&& $first['value'] === $second['value']
			&& self::declaration_properties_allowed( array_keys( $declarations ) )
			&& self::css_structure_ok( $css )
			&& ! self::contains_raw_unsafe_bytes( $css )
			&& count( $tokens ) === count( array_unique( $tokens ) );

		return $ctx->result(
			'style.wp-style-engine.block-styles-deterministic-safe',
			$ok,
			array(
				'selector'       => $case['selector'],
				'convertVars'    => $case['convertVarsToClassnames'],
				'blockStyles'    => self::preview( $case['blockStyles'] ),
				'first'          => self::describe_call( $first ),
				'second'         => self::describe_call( $second ),
				'declarations'   => $declarations,
				'classnames'     => $classnames,
				'structure'      => self::css_structure_report( $css ),
				'unsafeBytes'    => self::unsafe_byte_report( $css ),
				'allowedMissing' => array_values( array_diff( array_keys( $declarations ), self::allowed_style_engine_properties() ) ),
			)
		);
	}

	private static function check_style_engine_preset_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$slug     = $case['safeSlug'];
		$css_slug = \_wp_to_kebab_case( $slug );
		$styles   = array(
			'color'      => array(
				'text'       => 'var:preset|color|' . $slug,
				'background' => 'var:preset|color|' . $slug,
				'gradient'   => 'var:preset|gradient|' . $slug,
			),
			'spacing'    => array(
				'padding' => array(
					'top'    => 'var:preset|spacing|' . $slug,
					'right'  => '0',
					'bottom' => $case['safeSpacing'],
				),
			),
			'typography' => array(
				'fontSize'   => 'var:preset|font-size|' . $slug,
				'fontFamily' => 'var:preset|font-family|' . $slug,
			),
			'border'     => array(
				'radius' => array(
					'bottomRight' => 'var:preset|border-radius|' . $slug,
				),
				'top'    => array(
					'color' => 'var:preset|color|' . $slug,
					'width' => '0',
					'style' => 'solid',
				),
			),
			'dimensions' => array(
				'aspectRatio' => '16 / 9',
				'width'       => 'var:preset|dimension|' . $slug,
			),
		);

		$selector = '.component-fuzz-preset-' . $slug;
		$resolved = self::call(
			static fn() => \wp_style_engine_get_styles(
				$styles,
				array(
					'selector'                   => $selector,
					'convert_vars_to_classnames' => false,
				)
			)
		);
		$classnames_only = self::call(
			static fn() => \wp_style_engine_get_styles(
				$styles,
				array(
					'selector'                   => $selector,
					'convert_vars_to_classnames' => true,
				)
			)
		);

		$resolved_value        = is_array( $resolved['value'] ) ? $resolved['value'] : array();
		$classnames_only_value = is_array( $classnames_only['value'] ) ? $classnames_only['value'] : array();
		$resolved_declarations = is_array( $resolved_value['declarations'] ?? null ) ? $resolved_value['declarations'] : array();
		$skipped_declarations  = is_array( $classnames_only_value['declarations'] ?? null ) ? $classnames_only_value['declarations'] : array();
		$resolved_css          = (string) ( $resolved_value['css'] ?? '' );
		$skipped_css           = (string) ( $classnames_only_value['css'] ?? '' );
		$resolved_classes      = self::class_tokens( (string) ( $resolved_value['classnames'] ?? '' ) );
		$skipped_classes       = self::class_tokens( (string) ( $classnames_only_value['classnames'] ?? '' ) );

		$expected_classes = array(
			'has-text-color',
			'has-' . $css_slug . '-color',
			'has-background',
			'has-' . $css_slug . '-background-color',
			'has-' . $css_slug . '-gradient-background',
			'has-aspect-ratio',
			'has-' . $css_slug . '-font-size',
			'has-' . $css_slug . '-font-family',
		);

		$ok = ! $resolved['threw']
			&& ! $classnames_only['threw']
			&& array() === array_diff( $expected_classes, $resolved_classes )
			&& array() === array_diff( $expected_classes, $skipped_classes )
			&& $resolved_classes === $skipped_classes
			&& count( $resolved_classes ) === count( array_unique( $resolved_classes ) )
			&& count( $skipped_classes ) === count( array_unique( $skipped_classes ) )
			&& self::expected_declarations_present(
				$resolved_declarations,
				array(
					'color'                      => 'var(--wp--preset--color--' . $css_slug . ')',
					'background-color'           => 'var(--wp--preset--color--' . $css_slug . ')',
					'background'                 => 'var(--wp--preset--gradient--' . $css_slug . ')',
					'padding-top'                => 'var(--wp--preset--spacing--' . $css_slug . ')',
					'padding-right'              => '0',
					'border-bottom-right-radius' => 'var(--wp--preset--border-radius--' . $css_slug . ')',
					'border-top-color'           => 'var(--wp--preset--color--' . $css_slug . ')',
					'border-top-style'           => 'solid',
					'font-size'                  => 'var(--wp--preset--font-size--' . $css_slug . ')',
					'font-family'                => 'var(--wp--preset--font-family--' . $css_slug . ')',
					'width'                      => 'var(--wp--preset--dimension--' . $css_slug . ')',
					'aspect-ratio'               => '16 / 9',
				)
			)
			&& ! array_key_exists( 'color', $skipped_declarations )
			&& ! array_key_exists( 'background-color', $skipped_declarations )
			&& ! array_key_exists( 'background', $skipped_declarations )
			&& ! array_key_exists( 'font-size', $skipped_declarations )
			&& ! array_key_exists( 'font-family', $skipped_declarations )
			&& ! array_key_exists( 'width', $skipped_declarations )
			&& 'var:preset|spacing|' . $slug === ( $skipped_declarations['padding-top'] ?? null )
			&& 'var:preset|border-radius|' . $slug === ( $skipped_declarations['border-bottom-right-radius'] ?? null )
			&& 'var:preset|color|' . $slug === ( $skipped_declarations['border-top-color'] ?? null )
			&& 'solid' === ( $skipped_declarations['border-top-style'] ?? null )
			&& '16 / 9' === ( $skipped_declarations['aspect-ratio'] ?? null )
			&& self::css_structure_ok( $resolved_css )
			&& self::css_structure_ok( $skipped_css )
			&& ! self::contains_raw_unsafe_bytes( $resolved_css )
			&& ! self::contains_raw_unsafe_bytes( $skipped_css );

		return $ctx->result(
			'style.wp-style-engine.preset-classnames-css-var-boundaries',
			$ok,
			array(
				'slug'                 => $slug,
				'cssSlug'              => $css_slug,
				'resolved'             => self::describe_call( $resolved ),
				'classnamesOnly'       => self::describe_call( $classnames_only ),
				'resolvedDeclarations' => $resolved_declarations,
				'skippedDeclarations'  => $skipped_declarations,
				'resolvedClasses'      => $resolved_classes,
				'skippedClasses'       => $skipped_classes,
				'resolvedStructure'    => self::css_structure_report( $resolved_css ),
				'skippedStructure'     => self::css_structure_report( $skipped_css ),
				'resolvedUnsafeBytes'  => self::unsafe_byte_report( $resolved_css ),
				'skippedUnsafeBytes'   => self::unsafe_byte_report( $skipped_css ),
			)
		);
	}

	private static function check_css_declarations_safety( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$declarations = array(
			'color'                         => $case['safeColor'],
			'font-size'                     => $case['safeFontSize'],
			'--wp--custom--fuzz-token'      => $case['safeSpacing'],
			'--wp--custom--unicode-fallback' => 'var(--wp--preset--spacing--' . $case['safeSlug'] . ')',
			'background-image'              => "url('javascript:alert(1)')",
			'background;bad'                => '#fff',
			'width'                         => "<script>alert(1)</script>\x01",
			'height'                        => array( 'not-a-string' ),
		);

		$call = self::call(
			static function () use ( $declarations ) {
				$object = new \WP_Style_Engine_CSS_Declarations( $declarations );
				return array(
					'stored' => $object->get_declarations(),
					'css'    => $object->get_declarations_string(),
					'pretty' => $object->get_declarations_string( true, 1 ),
				);
			}
		);

		$value = is_array( $call['value'] ) ? $call['value'] : array();
		$css   = (string) ( $value['css'] ?? '' );

		$ok = ! $call['threw']
			&& str_contains( $css, 'color:' . $case['safeColor'] )
			&& str_contains( $css, '--wp--custom--fuzz-token:' . $case['safeSpacing'] )
			&& ! str_contains( $css, 'backgroundbad:' )
			&& ! str_contains( strtolower( $css ), 'javascript:' )
			&& ! self::contains_raw_unsafe_bytes( $css )
			&& self::declaration_block_ok( $css );

		return $ctx->result(
			'style.css-declarations.safe-filtering-and-custom-properties',
			$ok,
			array(
				'input'       => self::preview( $declarations ),
				'call'        => self::describe_call( $call ),
				'css'         => $css,
				'structure'   => self::css_structure_report( $css ),
				'unsafeBytes' => self::unsafe_byte_report( $css ),
			)
		);
	}

	private static function check_stylesheet_rules_and_context_store( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::set_style_stores( array() );

		$context = self::context_name( $ctx, 'rules' );
		$rules   = $case['cssRules'];
		$first   = self::call(
			static fn() => \wp_style_engine_get_stylesheet_from_css_rules(
				$rules,
				array(
					'context'  => $context,
					'prettify' => false,
					'optimize' => false,
				)
			)
		);
		$second  = self::call(
			static fn() => \wp_style_engine_get_stylesheet_from_css_rules(
				$rules,
				array(
					'prettify' => false,
					'optimize' => false,
				)
			)
		);
		$stored  = self::call(
			static fn() => \wp_style_engine_get_stylesheet_from_context(
				$context,
				array(
					'prettify' => false,
					'optimize' => false,
				)
			)
		);

		$css            = (string) $first['value'];
		$merge_selector = $case['mergeSelector'];
		$expected_color = $case['mergeExpectedColor'];
		$replaced_color = $case['mergeReplacedColor'];

		$ok = ! $first['threw']
			&& ! $second['threw']
			&& ! $stored['threw']
			&& $first['value'] === $second['value']
			&& $first['value'] === $stored['value']
			&& str_contains( $css, $merge_selector . '{' )
			&& str_contains( $css, 'color:' . $expected_color )
			&& ! str_contains( $css, 'color:' . $replaced_color )
			&& str_contains( $css, '--wp--custom--merge-token:' . $case['safeSpacing'] )
			&& ! str_contains( strtolower( $css ), 'javascript:' )
			&& self::css_structure_ok( $css )
			&& ! self::contains_raw_unsafe_bytes( $css );

		return $ctx->result(
			'style.stylesheet-rules.merge-context-deterministic-structured',
			$ok,
			array(
				'rules'          => self::preview( $rules ),
				'first'          => self::describe_call( $first ),
				'second'         => self::describe_call( $second ),
				'stored'         => self::describe_call( $stored ),
				'mergeSelector'  => $merge_selector,
				'expectedColor'  => $expected_color,
				'replacedColor'  => $replaced_color,
				'structure'      => self::css_structure_report( $css ),
				'storesAfterRun' => array_keys( \WP_Style_Engine_CSS_Rules_Store::get_stores() ),
			)
		);
	}

	private static function check_block_selector_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$box      = $registry->get_registered( 'fuzz/box' );
		$button   = $registry->get_registered( 'core/button' );

		$call = self::call(
			static function () use ( $box, $button ): array {
				return array(
					'boxRoot'                  => $box ? \wp_get_block_css_selector( $box, 'root' ) : null,
					'boxSpacingRoot'           => $box ? \wp_get_block_css_selector( $box, 'spacing' ) : null,
					'boxSpacingRootPath'       => $box ? \wp_get_block_css_selector( $box, array( 'spacing', 'root' ) ) : null,
					'boxSpacingPadding'        => $box ? \wp_get_block_css_selector( $box, array( 'spacing', 'padding' ) ) : null,
					'boxSpacingPaddingFallback' => $box ? \wp_get_block_css_selector( $box, array( 'spacing', 'padding' ), true ) : null,
					'boxColor'                 => $box ? \wp_get_block_css_selector( $box, 'color' ) : null,
					'boxColorFallback'         => $box ? \wp_get_block_css_selector( $box, 'color', true ) : null,
					'buttonRoot'               => $button ? \wp_get_block_css_selector( $button, 'root' ) : null,
					'emptyTarget'              => $box ? \wp_get_block_css_selector( $box, '' ) : 'not-called',
					'pathDirect'               => \wp_get_block_name_from_theme_json_path( array( 'styles', 'blocks', 'fuzz/box', 'elements', 'link' ) ),
					'pathFallback'             => \wp_get_block_name_from_theme_json_path( array( 'styles', 'elements', 'core/paragraph', 'link' ) ),
					'pathInvalid'              => \wp_get_block_name_from_theme_json_path( array( 'styles', 'blocks', 'fuzz-box' ) ),
				);
			}
		);

		$value = is_array( $call['value'] ) ? $call['value'] : array();
		$ok    = $box instanceof \WP_Block_Type
			&& $button instanceof \WP_Block_Type
			&& ! $call['threw']
			&& '.wp-block-fuzz-box' === ( $value['boxRoot'] ?? null )
			&& '.wp-block-fuzz-box__inner' === ( $value['boxSpacingRoot'] ?? null )
			&& '.wp-block-fuzz-box__inner' === ( $value['boxSpacingRootPath'] ?? null )
			&& array_key_exists( 'boxSpacingPadding', $value )
			&& null === $value['boxSpacingPadding']
			&& '.wp-block-fuzz-box__inner' === ( $value['boxSpacingPaddingFallback'] ?? null )
			&& array_key_exists( 'boxColor', $value )
			&& null === $value['boxColor']
			&& '.wp-block-fuzz-box' === ( $value['boxColorFallback'] ?? null )
			&& '.wp-block-button' === ( $value['buttonRoot'] ?? null )
			&& array_key_exists( 'emptyTarget', $value )
			&& null === $value['emptyTarget']
			&& 'fuzz/box' === ( $value['pathDirect'] ?? null )
			&& 'core/paragraph' === ( $value['pathFallback'] ?? null )
			&& '' === ( $value['pathInvalid'] ?? null );

		return $ctx->result(
			'style.block-selector-helpers.paths-fallbacks-deterministic',
			$ok,
			array(
				'registered' => array(
					'fuzz/box'    => $box instanceof \WP_Block_Type,
					'core/button' => $button instanceof \WP_Block_Type,
				),
				'call'       => self::describe_call( $call ),
				'selectors'  => $value,
			)
		);
	}

	private static function check_theme_json_schema_migration( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$legacy = array(
			'version'  => 1,
			'settings' => array(
				'border'     => array(
					'customRadius' => true,
				),
				'spacing'    => array(
					'customMargin'  => true,
					'customPadding' => false,
					'spacingScale'  => array(
						'steps'      => 3,
						'mediumStep' => 1.5,
						'unit'       => 'rem',
					),
				),
				'typography' => array(
					'customLineHeight' => true,
					'fontSizes'        => array(
						array(
							'name' => 'Fuzz Small',
							'slug' => $case['safeSlug'],
							'size' => $case['safeFontSize'],
						),
					),
				),
				'blocks'     => array(
					'core/paragraph' => array(
						'spacing'    => array(
							'customPadding' => true,
						),
						'typography' => array(
							'customLineHeight' => true,
						),
					),
				),
			),
		);

		$first = self::call( static fn() => \WP_Theme_JSON_Schema::migrate( $legacy, 'theme' ) );
		$again = self::call( static fn() => \WP_Theme_JSON_Schema::migrate( $first['value'], 'theme' ) );

		$migrated = is_array( $first['value'] ) ? $first['value'] : array();
		$ok       = ! $first['threw']
			&& ! $again['threw']
			&& $first['value'] === $again['value']
			&& ( \WP_Theme_JSON::LATEST_SCHEMA === ( $migrated['version'] ?? null ) )
			&& true === self::array_get( $migrated, array( 'settings', 'border', 'radius' ) )
			&& true === self::array_get( $migrated, array( 'settings', 'spacing', 'margin' ) )
			&& false === self::array_get( $migrated, array( 'settings', 'spacing', 'padding' ) )
			&& true === self::array_get( $migrated, array( 'settings', 'typography', 'lineHeight' ) )
			&& ! array_key_exists( 'customRadius', $migrated['settings']['border'] ?? array() )
			&& ! array_key_exists( 'customPadding', $migrated['settings']['spacing'] ?? array() )
			&& false === self::array_get( $migrated, array( 'settings', 'typography', 'defaultFontSizes' ) )
			&& false === self::array_get( $migrated, array( 'settings', 'spacing', 'defaultSpacingSizes' ) );

		return $ctx->result(
			'style.theme-json-schema.migration-idempotent-versioned',
			$ok,
			array(
				'legacy'   => self::preview( $legacy ),
				'first'    => self::describe_call( $first ),
				'again'    => self::describe_call( $again ),
				'migrated' => self::preview( $migrated ),
			)
		);
	}

	private static function check_theme_json_data_merge( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$base     = $case['themeJsonBase'];
		$incoming = $case['themeJsonIncoming'];

		$forward = self::call(
			static function () use ( $base, $incoming ) {
				$data = new \WP_Theme_JSON_Data( $base, 'theme' );
				return $data->update_with( $incoming )->get_data();
			}
		);
		$repeat  = self::call(
			static function () use ( $base, $incoming ) {
				$data = new \WP_Theme_JSON_Data( $base, 'theme' );
				return $data->update_with( $incoming )->get_data();
			}
		);
		$direct  = self::call(
			static function () use ( $base, $incoming ) {
				$data = new \WP_Theme_JSON( $base, 'theme' );
				$data->merge( new \WP_Theme_JSON( $incoming, 'theme' ) );
				return $data->get_raw_data();
			}
		);
		$reverse = self::call(
			static function () use ( $base, $incoming ) {
				$data = new \WP_Theme_JSON_Data( $incoming, 'theme' );
				return $data->update_with( $base )->get_data();
			}
		);

		$forward_data = is_array( $forward['value'] ) ? $forward['value'] : array();
		$reverse_data = is_array( $reverse['value'] ) ? $reverse['value'] : array();

		$ok = ! $forward['threw']
			&& ! $repeat['threw']
			&& ! $direct['threw']
			&& ! $reverse['threw']
			&& $forward['value'] === $repeat['value']
			&& $forward['value'] === $direct['value']
			&& \WP_Theme_JSON::LATEST_SCHEMA === ( $forward_data['version'] ?? null )
			&& $case['incomingTextColor'] === self::array_get( $forward_data, array( 'styles', 'color', 'text' ) )
			&& $case['baseTextColor'] === self::array_get( $reverse_data, array( 'styles', 'color', 'text' ) )
			&& $case['incomingUnits'] === self::array_get( $forward_data, array( 'settings', 'spacing', 'units' ) );

		return $ctx->result(
			'style.theme-json-data.merge-order-stable',
			$ok,
			array(
				'baseTextColor'     => $case['baseTextColor'],
				'incomingTextColor' => $case['incomingTextColor'],
				'incomingUnits'     => $case['incomingUnits'],
				'forward'           => self::describe_call( $forward ),
				'repeat'            => self::describe_call( $repeat ),
				'direct'            => self::describe_call( $direct ),
				'reverse'           => self::describe_call( $reverse ),
			)
		);
	}

	private static function check_theme_json_variable_resolution( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$raw_slug      = $case['safeSlug'];
		$slug          = \_wp_to_kebab_case( $raw_slug );
		$missing       = 'missing-' . $slug;
		$variable_color = '#123456';
		$data          = array(
			'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
			'settings' => array(
				'color'      => array(
					'palette' => array(
						array(
							'name'  => 'Fuzz Accent',
							'slug'  => $slug,
							'color' => $variable_color,
						),
					),
				),
				'spacing'    => array(
					'spacingSizes' => array(
						array(
							'name' => 'Fuzz Space',
							'slug' => $slug,
							'size' => $case['safeSpacing'],
						),
					),
				),
				'typography' => array(
					'fontSizes' => array(
						array(
							'name' => 'Fuzz Font',
							'slug' => $slug,
							'size' => $case['safeFontSize'],
						),
					),
				),
			),
			'styles'   => array(
				'color'      => array(
					'text'       => 'var:preset|color|' . $slug,
					'background' => 'var:preset|color|' . $missing,
				),
				'spacing'    => array(
					'blockGap' => 'var:preset|spacing|' . $slug,
				),
				'typography' => array(
					'fontSize' => 'var:preset|font-size|' . $slug,
				),
				'blocks'     => array(
					'core/paragraph' => array(
						'color' => array(
							'text' => 'var:preset|color|' . $slug,
						),
					),
				),
			),
		);

		$first = self::call(
			static function () use ( $data ): array {
				$tree     = new \WP_Theme_JSON( $data, 'theme' );
				$raw      = $tree->get_raw_data();
				$resolved = \WP_Theme_JSON::resolve_variables( $tree )->get_raw_data();
				return array(
					'raw'      => $raw,
					'resolved' => $resolved,
				);
			}
		);
		$second = self::call(
			static function () use ( $data ): array {
				$tree = new \WP_Theme_JSON( $data, 'theme' );
				return \WP_Theme_JSON::resolve_variables( $tree )->get_raw_data();
			}
		);

		$value    = is_array( $first['value'] ) ? $first['value'] : array();
		$raw      = is_array( $value['raw'] ?? null ) ? $value['raw'] : array();
		$resolved = is_array( $value['resolved'] ?? null ) ? $value['resolved'] : array();

		$ok = ! $first['threw']
			&& ! $second['threw']
			&& $resolved === $second['value']
			&& 'var(--wp--preset--color--' . $slug . ')' === self::array_get( $raw, array( 'styles', 'color', 'text' ) )
			&& 'var(--wp--preset--color--' . $missing . ')' === self::array_get( $raw, array( 'styles', 'color', 'background' ) )
			&& $variable_color === self::array_get( $resolved, array( 'styles', 'color', 'text' ) )
			&& 'var(--wp--preset--color--' . $missing . ')' === self::array_get( $resolved, array( 'styles', 'color', 'background' ) )
			&& $case['safeSpacing'] === self::array_get( $resolved, array( 'styles', 'spacing', 'blockGap' ) )
			&& $case['safeFontSize'] === self::array_get( $resolved, array( 'styles', 'typography', 'fontSize' ) )
			&& $variable_color === self::array_get( $resolved, array( 'styles', 'blocks', 'core/paragraph', 'color', 'text' ) );

		return $ctx->result(
			'style.theme-json.variables-resolve-known-presets-only',
			$ok,
			array(
				'slug'      => $slug,
				'rawSlug'   => $raw_slug,
				'missing'   => $missing,
				'color'     => $variable_color,
				'first'     => self::describe_call( $first ),
				'second'    => self::describe_call( $second ),
				'raw'       => self::preview( $raw ),
				'resolved'  => self::preview( $resolved ),
			)
		);
	}

	private static function check_theme_json_stylesheet( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$raw = $case['themeJsonUnsafe'];

		$first = self::call(
			static function () use ( $raw ) {
				$clean = \WP_Theme_JSON::remove_insecure_properties( $raw, 'theme' );
				$tree  = new \WP_Theme_JSON( $clean, 'theme' );

				return array(
					'clean'      => $clean,
					'stylesheet' => $tree->get_stylesheet(
						array( 'variables', 'styles', 'presets', 'custom-css' ),
						array( 'theme' ),
						array(
							'include_block_style_variations' => true,
							'skip_root_layout_styles'        => true,
							'scope'                          => '.component-fuzz-scope',
						)
					),
				);
			}
		);
		$second = self::call(
			static function () use ( $raw ) {
				$clean = \WP_Theme_JSON::remove_insecure_properties( $raw, 'theme' );
				$tree  = new \WP_Theme_JSON( $clean, 'theme' );
				return $tree->get_stylesheet(
					array( 'variables', 'styles', 'presets', 'custom-css' ),
					array( 'theme' ),
					array(
						'include_block_style_variations' => true,
						'skip_root_layout_styles'        => true,
						'scope'                          => '.component-fuzz-scope',
					)
				);
			}
		);

		$value      = is_array( $first['value'] ) ? $first['value'] : array();
		$stylesheet = (string) ( $value['stylesheet'] ?? '' );
		$clean      = is_array( $value['clean'] ?? null ) ? $value['clean'] : array();

		$ok = ! $first['threw']
			&& ! $second['threw']
			&& $stylesheet === $second['value']
			&& \WP_Theme_JSON::LATEST_SCHEMA === ( $clean['version'] ?? null )
			&& str_contains( $stylesheet, '.component-fuzz-scope' )
			&& str_contains( $stylesheet, '.wp-block-button' )
			&& str_contains( $stylesheet, ':hover' )
			&& ! str_contains( strtolower( $stylesheet ), '<script' )
			&& ! str_contains( strtolower( $stylesheet ), 'javascript:' )
			&& ! self::contains_raw_unsafe_bytes( $stylesheet )
			&& self::css_structure_ok( $stylesheet );

		return $ctx->result(
			'style.theme-json.stylesheet-sanitized-deterministic-structured',
			$ok,
			array(
				'raw'         => self::preview( $raw ),
				'clean'       => self::preview( $clean ),
				'first'       => self::describe_call( $first ),
				'second'      => self::describe_call( $second ),
				'structure'   => self::css_structure_report( $stylesheet ),
				'unsafeBytes' => self::unsafe_byte_report( $stylesheet ),
			)
		);
	}

	private static function check_block_supports_wrapper_attributes_serialization( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$missing = self::block_support_wrapper_missing_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'style.block-supports.wrapper-attributes-serialization',
				'Required WordPress block-support wrapper APIs are unavailable.',
				array( 'missing' => implode( ', ', $missing ) )
			);
		}

		$slug              = \_wp_to_kebab_case( $case['safeSlug'] );
		$supports          = self::block_support_wrapper_supports();
		$skip_supports     = self::block_support_wrapper_skip_supports();
		$attributes        = self::block_support_wrapper_attrs( $slug );
		$custom_attributes = self::block_support_wrapper_custom_attrs( $slug );
		$extra_attributes  = self::block_support_wrapper_extra_attrs( $slug );
		$attribute_schema  = self::block_support_wrapper_attribute_schema();

		$direct_type = new \WP_Block_Type(
			'component-fuzz/style-wrapper-direct',
			array(
				'supports'   => $supports,
				'attributes' => $attribute_schema,
			)
		);
		$skip_type   = new \WP_Block_Type(
			'component-fuzz/style-wrapper-skip',
			array(
				'supports'   => $skip_supports,
				'attributes' => $attribute_schema,
			)
		);

		$registry                = \WP_Block_Type_Registry::get_instance();
		$supports_api            = \WP_Block_Supports::get_instance();
		$registered_types_before = self::get_object_property( $registry, 'registered_block_types' );
		$block_supports_before   = self::get_object_property( $supports_api, 'block_supports' );
		$block_to_render_before  = self::get_static_property( 'WP_Block_Supports', 'block_to_render' );
		$registry_restored       = false;
		$supports_restored       = false;
		$registered              = false;
		$register_call           = null;
		$direct_first            = null;
		$direct_second           = null;
		$skip_call               = null;
		$skip_query              = null;
		$aggregate_first         = null;
		$aggregate_second        = null;
		$custom_direct           = null;
		$custom_aggregate        = null;
		$serialized_wrapper      = null;
		$empty_aggregate         = null;

		try {
			$register_call = self::call( static fn() => self::block_support_wrapper_register_outputs( $supports ) );
			$direct_first  = self::call( static fn() => self::block_support_wrapper_apply_outputs( $direct_type, $attributes ) );
			$direct_second = self::call( static fn() => self::block_support_wrapper_apply_outputs( $direct_type, $attributes ) );
			$skip_call     = self::call( static fn() => self::block_support_wrapper_apply_outputs( $skip_type, $attributes ) );
			$skip_query    = self::call( static fn() => self::block_support_wrapper_skip_queries( $skip_type ) );
			$custom_direct = self::call( static fn() => self::block_support_wrapper_apply_outputs( $direct_type, $custom_attributes ) );

			$block_name      = 'component-fuzz/style-wrapper-' . $ctx->seed() . '-' . $ctx->iteration();
			$registered_type = \register_block_type(
				$block_name,
				array(
					'title'           => 'Component Fuzz Style Wrapper',
					'supports'        => $supports,
					'attributes'      => $attribute_schema,
					'render_callback' => static fn() => '',
				)
			);
			$registered      = $registered_type instanceof \WP_Block_Type;

			$aggregate_first = self::call(
				static function () use ( $block_name, $attributes ): array {
					\WP_Block_Supports::$block_to_render = array(
						'blockName' => $block_name,
						'attrs'     => $attributes,
					);
					return \WP_Block_Supports::get_instance()->apply_block_supports();
				}
			);
			$aggregate_second = self::call(
				static function () use ( $block_name, $attributes ): array {
					\WP_Block_Supports::$block_to_render = array(
						'blockName' => $block_name,
						'attrs'     => $attributes,
					);
					return \WP_Block_Supports::get_instance()->apply_block_supports();
				}
			);
			$custom_aggregate = self::call(
				static function () use ( $block_name, $custom_attributes ): array {
					\WP_Block_Supports::$block_to_render = array(
						'blockName' => $block_name,
						'attrs'     => $custom_attributes,
					);
					return \WP_Block_Supports::get_instance()->apply_block_supports();
				}
			);
			$serialized_wrapper = self::call(
				static fn() => self::block_support_wrapper_serialized_output( $block_name, $attributes, $extra_attributes )
			);
			$empty_aggregate  = self::call(
				static function (): array {
					\WP_Block_Supports::$block_to_render = null;
					return \WP_Block_Supports::get_instance()->apply_block_supports();
				}
			);
		} finally {
			self::set_object_property( $registry, 'registered_block_types', $registered_types_before );
			self::set_object_property( $supports_api, 'block_supports', $block_supports_before );
			self::set_static_property( 'WP_Block_Supports', 'instance', $supports_api );
			self::set_static_property( 'WP_Block_Supports', 'block_to_render', $block_to_render_before );

			$registry_restored = $registered_types_before === self::get_object_property( $registry, 'registered_block_types' );
			$supports_restored = $block_supports_before === self::get_object_property( $supports_api, 'block_supports' )
				&& $block_to_render_before === self::get_static_property( 'WP_Block_Supports', 'block_to_render' );
		}

		$register_value  = is_array( $register_call['value'] ?? null ) ? $register_call['value'] : array();
		$direct_value    = is_array( $direct_first['value'] ?? null ) ? $direct_first['value'] : array();
		$skip_value      = is_array( $skip_call['value'] ?? null ) ? $skip_call['value'] : array();
		$skip_query_value = is_array( $skip_query['value'] ?? null ) ? $skip_query['value'] : array();
		$aggregate_value = is_array( $aggregate_first['value'] ?? null ) ? $aggregate_first['value'] : array();
		$custom_direct_value = is_array( $custom_direct['value'] ?? null ) ? $custom_direct['value'] : array();
		$custom_aggregate_value = is_array( $custom_aggregate['value'] ?? null ) ? $custom_aggregate['value'] : array();
		$serialized_wrapper_value = is_array( $serialized_wrapper['value'] ?? null ) ? $serialized_wrapper['value'] : array();

		$ok = is_array( $register_call )
			&& is_array( $direct_first )
			&& is_array( $direct_second )
			&& is_array( $skip_call )
			&& is_array( $skip_query )
			&& is_array( $aggregate_first )
			&& is_array( $aggregate_second )
			&& is_array( $custom_direct )
			&& is_array( $custom_aggregate )
			&& is_array( $serialized_wrapper )
			&& is_array( $empty_aggregate )
			&& ! $register_call['threw']
			&& ! $direct_first['threw']
			&& ! $direct_second['threw']
			&& ! $skip_call['threw']
			&& ! $skip_query['threw']
			&& ! $aggregate_first['threw']
			&& ! $aggregate_second['threw']
			&& ! $custom_direct['threw']
			&& ! $custom_aggregate['threw']
			&& ! $serialized_wrapper['threw']
			&& ! $empty_aggregate['threw']
			&& $direct_first['value'] === $direct_second['value']
			&& $aggregate_first['value'] === $aggregate_second['value']
			&& array() === $empty_aggregate['value']
			&& self::block_support_wrapper_registers_ok( $register_value )
			&& self::block_support_wrapper_direct_outputs_ok( $direct_value, $slug )
			&& self::block_support_wrapper_skip_outputs_ok( $skip_value, $slug )
			&& self::block_support_wrapper_skip_queries_ok( $skip_query_value )
			&& $registered
			&& self::block_support_wrapper_aggregate_output_ok( $aggregate_value, $slug )
			&& self::block_support_wrapper_custom_outputs_ok( $custom_direct_value )
			&& self::block_support_wrapper_custom_aggregate_output_ok( $custom_aggregate_value )
			&& self::block_support_wrapper_serialized_output_ok( $serialized_wrapper_value, $slug, $extra_attributes )
			&& $registry_restored
			&& $supports_restored;

		return $ctx->result(
			'style.block-supports.wrapper-attributes-serialization',
			$ok,
			array(
				'slug'             => $slug,
				'attributes'       => self::preview( $attributes ),
				'register'         => is_array( $register_call ) ? self::describe_call( $register_call ) : null,
				'direct'           => is_array( $direct_first ) ? self::describe_call( $direct_first ) : null,
				'directRepeat'     => is_array( $direct_second ) ? self::describe_call( $direct_second ) : null,
				'skip'             => is_array( $skip_call ) ? self::describe_call( $skip_call ) : null,
				'skipQueries'      => is_array( $skip_query ) ? self::describe_call( $skip_query ) : null,
				'aggregate'        => is_array( $aggregate_first ) ? self::describe_call( $aggregate_first ) : null,
				'aggregateRepeat'  => is_array( $aggregate_second ) ? self::describe_call( $aggregate_second ) : null,
				'customDirect'     => is_array( $custom_direct ) ? self::describe_call( $custom_direct ) : null,
				'customAggregate'  => is_array( $custom_aggregate ) ? self::describe_call( $custom_aggregate ) : null,
				'serializedWrapper' => is_array( $serialized_wrapper ) ? self::describe_call( $serialized_wrapper ) : null,
				'emptyAggregate'   => is_array( $empty_aggregate ) ? self::describe_call( $empty_aggregate ) : null,
				'registered'       => $registered,
				'registryRestored' => $registry_restored,
				'supportsRestored' => $supports_restored,
				'unsafeBytes'      => self::block_support_wrapper_unsafe_report( $direct_value, $skip_value, $aggregate_value ),
			)
		);
	}

	private static function check_block_style_variation_serialization( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$raw = $case['themeJsonUnsafe'];

		$call = self::call(
			static function () use ( $raw ) {
				$clean = \WP_Theme_JSON::remove_insecure_properties( $raw, 'theme' );
				$tree  = new \WP_Theme_JSON( $clean, 'theme' );
				return $tree->get_stylesheet(
					array( 'styles' ),
					array( 'theme' ),
					array(
						'include_block_style_variations' => true,
						'skip_root_layout_styles'        => true,
					)
				);
			}
		);

		$css = (string) $call['value'];
		$ok  = ! $call['threw']
			&& str_contains( $css, '.wp-block-paragraph.is-style-fuzz-tone' )
			&& str_contains( $css, $case['variationTextColor'] )
			&& ! str_contains( strtolower( $css ), 'javascript:' )
			&& ! str_contains( strtolower( $css ), '<script' )
			&& self::css_structure_ok( $css );

		return $ctx->result(
			'style.block-style-variations.declarations-serialize-safely',
			$ok,
			array(
				'call'               => self::describe_call( $call ),
				'variationTextColor' => $case['variationTextColor'],
				'structure'          => self::css_structure_report( $css ),
			)
		);
	}

	private static function check_registered_block_style_variation_source_order( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$variation                = $case['registeredBlockStyleVariation'];
		$registry                 = \WP_Block_Styles_Registry::get_instance();
		$registered_styles_before = self::get_object_property( $registry, 'registered_block_styles' );
		$theme_json_meta_before   = self::get_static_property( 'WP_Theme_JSON', 'blocks_metadata' );
		$registered               = false;
		$registry_restored        = false;
		$metadata_restored        = false;
		$call                     = null;

		try {
			$registered = \register_block_style(
				'core/paragraph',
				array(
					'name'       => $variation['name'],
					'label'      => 'Fuzz Registry ' . $variation['name'],
					'style_data' => $variation['registryData'],
				)
			);

			$call = self::call(
				static function () use ( $variation ): array {
					$data = array(
						'version' => \WP_Theme_JSON::LATEST_SCHEMA,
						'styles'  => array(
							'variations' => array(
								$variation['name'] => $variation['topLevelData'],
							),
							'blocks'     => array(
								'core/paragraph' => array(
									'variations' => array(
										$variation['name'] => $variation['blockLevelData'],
									),
								),
							),
						),
					);

					$injected = self::inject_registered_block_style_variations( $data );
					$clean    = \WP_Theme_JSON::remove_insecure_properties( $injected, 'theme' );
					$tree     = new \WP_Theme_JSON( $clean, 'theme' );

					return array(
						'injected'   => $injected,
						'clean'      => $clean,
						'stylesheet' => $tree->get_stylesheet(
							array( 'styles' ),
							array( 'theme' ),
							array(
								'include_block_style_variations' => true,
								'skip_root_layout_styles'        => true,
							)
						),
					);
				}
			);
		} finally {
			self::set_object_property( $registry, 'registered_block_styles', $registered_styles_before );
			self::set_static_property( 'WP_Theme_JSON', 'blocks_metadata', $theme_json_meta_before );

			$registry_restored = $registered_styles_before === self::get_object_property( $registry, 'registered_block_styles' );
			$metadata_restored = $theme_json_meta_before === self::get_static_property( 'WP_Theme_JSON', 'blocks_metadata' );
		}

		$value          = is_array( $call['value'] ?? null ) ? $call['value'] : array();
		$injected       = is_array( $value['injected'] ?? null ) ? $value['injected'] : array();
		$clean          = is_array( $value['clean'] ?? null ) ? $value['clean'] : array();
		$stylesheet     = (string) ( $value['stylesheet'] ?? '' );
		$variation_data = self::array_get( $injected, array( 'styles', 'blocks', 'core/paragraph', 'variations', $variation['name'] ), array() );
		$clean_data     = self::array_get( $clean, array( 'styles', 'blocks', 'core/paragraph', 'variations', $variation['name'] ), array() );
		$selector       = '.wp-block-paragraph.is-style-' . $variation['name'];

		$ok = $registered
			&& is_array( $call )
			&& ! $call['threw']
			&& is_array( $variation_data )
			&& is_array( $clean_data )
			&& $variation['blockTextColor'] === self::array_get( $variation_data, array( 'color', 'text' ) )
			&& $variation['topBackgroundColor'] === self::array_get( $variation_data, array( 'color', 'background' ) )
			&& $variation['topPaddingTop'] === self::array_get( $variation_data, array( 'spacing', 'padding', 'top' ) )
			&& $variation['blockPaddingRight'] === self::array_get( $variation_data, array( 'spacing', 'padding', 'right' ) )
			&& $variation['registryBorderColor'] === self::array_get( $variation_data, array( 'border', 'color' ) )
			&& $variation['registryBorderColor'] === self::array_get( $clean_data, array( 'border', 'color' ) )
			&& str_contains( $stylesheet, ':root :where(' . $selector . ')' )
			&& self::css_contains_declaration( $stylesheet, 'color', $variation['blockTextColor'] )
			&& self::css_contains_declaration( $stylesheet, 'background-color', $variation['topBackgroundColor'] )
			&& self::css_contains_declaration( $stylesheet, 'padding-top', $variation['topPaddingTop'] )
			&& self::css_contains_declaration( $stylesheet, 'padding-right', $variation['blockPaddingRight'] )
			&& self::css_contains_declaration( $stylesheet, 'border-color', $variation['registryBorderColor'] )
			&& ! str_contains( $stylesheet, $variation['registryTextColor'] )
			&& ! str_contains( $stylesheet, $variation['topTextColor'] )
			&& ! str_contains( strtolower( $stylesheet ), 'javascript:' )
			&& ! str_contains( strtolower( $stylesheet ), '<script' )
			&& self::css_structure_ok( $stylesheet )
			&& ! self::contains_raw_unsafe_bytes( $stylesheet )
			&& $registry_restored
			&& $metadata_restored;

		return $ctx->result(
			'style.block-style-registry.style-data-injection-source-order-safe',
			$ok,
			array(
				'name'             => $variation['name'],
				'selector'         => $selector,
				'registered'       => $registered,
				'call'             => is_array( $call ) ? self::describe_call( $call ) : null,
				'variationData'    => self::preview( $variation_data ),
				'cleanData'        => self::preview( $clean_data ),
				'structure'        => self::css_structure_report( $stylesheet ),
				'unsafeBytes'      => self::unsafe_byte_report( $stylesheet ),
				'registryRestored' => $registry_restored,
				'metadataRestored' => $metadata_restored,
			)
		);
	}

	private static function check_theme_style_helper_filter_restoration( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$global_snapshot = self::snapshot_globals( array( 'editor_styles', '_wp_theme_features' ) );
		$style_handle    = 'component-fuzz-editor-' . $ctx->seed() . '-' . $ctx->iteration() . '.css';
		$style_file      = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $style_handle;
		$style_url       = 'http://example.test/component-fuzz-theme/' . rawurlencode( $style_handle );
		$remote_url      = 'https://example.invalid/component-fuzz-editor.css';
		$css             = '.component-fuzz-editor-' . $ctx->iteration() . '{color:' . $case['safeColor'] . ';margin-top:' . $case['safeSpacing'] . ';}';
		$remote_requests = array();

		if ( false === file_put_contents( $style_file, $css ) ) {
			self::restore_globals( $global_snapshot );
			return $ctx->fail(
				'style.theme-style-helper.local-filtered-editor-styles',
				array(
					'message' => 'Could not write temporary editor style fixture.',
					'path'    => $style_file,
				)
			);
		}

		$path_filter = static function ( string $path, string $file ) use ( $style_file, $style_handle ): string {
			return $style_handle === $file ? $style_file : $path;
		};
		$uri_filter  = static function ( string $url, string $file ) use ( $style_url, $style_handle ): string {
			return $style_handle === $file ? $style_url : $url;
		};
		$http_filter = static function ( $preempt, array $parsed_args, string $url ) use ( &$remote_requests ) {
			$remote_requests[] = $url;
			return new \WP_Error( 'component_fuzz_no_network', 'Network access is disabled for style fuzzing.' );
		};

		$call             = null;
		$filters_removed  = false;
		$globals_restored = false;
		try {
			\add_filter( 'theme_file_path', $path_filter, 10, 2 );
			\add_filter( 'theme_file_uri', $uri_filter, 10, 2 );
			\add_filter( 'pre_http_request', $http_filter, 10, 3 );
			\add_theme_support( 'editor-styles' );
			$GLOBALS['editor_styles'] = array( $style_handle, $remote_url );

			$call = self::call( static fn() => \get_block_editor_theme_styles() );
		} finally {
			\remove_filter( 'theme_file_path', $path_filter, 10 );
			\remove_filter( 'theme_file_uri', $uri_filter, 10 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			self::restore_globals( $global_snapshot );

			if ( is_file( $style_file ) ) {
				unlink( $style_file );
			}

			$filters_removed  = false === \has_filter( 'theme_file_path', $path_filter )
				&& false === \has_filter( 'theme_file_uri', $uri_filter )
				&& false === \has_filter( 'pre_http_request', $http_filter );
			$globals_restored = self::globals_match_snapshot( $global_snapshot );
		}

		$value = is_array( $call['value'] ?? null ) ? $call['value'] : array();
		$first = is_array( $value[0] ?? null ) ? $value[0] : array();
		$ok    = is_array( $call )
			&& ! $call['threw']
			&& 1 === count( $value )
			&& $css === ( $first['css'] ?? null )
			&& $style_url === ( $first['baseURL'] ?? null )
			&& 'theme' === ( $first['__unstableType'] ?? null )
			&& false === ( $first['isGlobalStyles'] ?? true )
			&& array( $remote_url ) === $remote_requests
			&& $filters_removed
			&& $globals_restored;

		return $ctx->result(
			'style.theme-style-helper.local-filtered-editor-styles',
			$ok,
			array(
				'call'             => is_array( $call ) ? self::describe_call( $call ) : null,
				'styleHandle'      => $style_handle,
				'styleUrl'         => $style_url,
				'remoteRequests'   => $remote_requests,
				'filtersRemoved'   => $filters_removed,
				'globalsRestored'  => $globals_restored,
				'tempFileRemaining' => is_file( $style_file ),
			)
		);
	}

	private static function check_style_store_cleanup( \ComponentFuzz\FuzzContext $ctx ): array {
		$before = array_keys( \WP_Style_Engine_CSS_Rules_Store::get_stores() );
		\WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		$after = \WP_Style_Engine_CSS_Rules_Store::get_stores();

		return $ctx->result(
			'style.state.css-rule-store-resettable',
			array() === $after,
			array(
				'before' => $before,
				'after'  => array_keys( $after ),
			)
		);
	}

	private static function check_global_stylesheet_guard( \ComponentFuzz\FuzzContext $ctx ): array {
		$slug              = self::normalize_preset_slug( self::safe_slug( $ctx, 'global' ) );
		$theme_color       = self::hex_color( $ctx );
		$custom_text_color = self::hex_color( $ctx, array( $theme_color ) );
		$spacing           = self::spacing_value( $ctx, false );
		$font_size         = self::font_size_value( $ctx, false );
		$resolver_before   = self::snapshot_theme_json_resolver();
		$cache_before_found = false;
		$cache_before       = \wp_cache_get( 'wp_get_global_stylesheet', 'theme_json', false, $cache_before_found );
		$queries_before    = self::wpdb_stub_queries();
		$cache_cleared     = false;
		$cache_restored    = false;
		$full_first        = null;
		$full_second       = null;
		$all_types         = null;
		$variables         = null;
		$styles            = null;
		$presets           = null;
		$custom_css        = null;
		$cache_after_first = false;
		$cache_after_typed = false;
		$query_delta       = null;

		try {
			self::seed_theme_json_resolver_for_global_stylesheet( $slug, $theme_color, $custom_text_color, $spacing, $font_size );
			\wp_cache_delete( 'wp_get_global_stylesheet', 'theme_json' );
			$cache_cleared = false === \wp_cache_get( 'wp_get_global_stylesheet', 'theme_json' );

			$full_first        = self::call( static fn() => \wp_get_global_stylesheet() );
			$cache_after_first = \wp_cache_get( 'wp_get_global_stylesheet', 'theme_json' );
			$full_second       = self::call( static fn() => \wp_get_global_stylesheet() );
			$all_types         = self::call( static fn() => \wp_get_global_stylesheet( array( 'variables', 'styles', 'presets', 'custom-css' ) ) );
			$variables         = self::call( static fn() => \wp_get_global_stylesheet( array( 'variables' ) ) );
			$styles            = self::call( static fn() => \wp_get_global_stylesheet( array( 'styles' ) ) );
			$presets           = self::call( static fn() => \wp_get_global_stylesheet( array( 'presets' ) ) );
			$custom_css        = self::call( static fn() => \wp_get_global_stylesheet( array( 'custom-css' ) ) );
			$cache_after_typed = \wp_cache_get( 'wp_get_global_stylesheet', 'theme_json' );
		} finally {
			self::restore_theme_json_resolver( $resolver_before );
			if ( $cache_before_found ) {
				\wp_cache_set( 'wp_get_global_stylesheet', $cache_before, 'theme_json' );
			} else {
				\wp_cache_delete( 'wp_get_global_stylesheet', 'theme_json' );
			}
			$cache_after_restore_found = false;
			$cache_after_restore       = \wp_cache_get( 'wp_get_global_stylesheet', 'theme_json', false, $cache_after_restore_found );
			$cache_restored            = $cache_before_found === $cache_after_restore_found
				&& ( ! $cache_before_found || $cache_before === $cache_after_restore );

			$queries_after = self::wpdb_stub_queries();
			if ( null !== $queries_before && null !== $queries_after ) {
				$query_delta = count( $queries_after ) - count( $queries_before );
			}
		}

		$outputs = array(
			'fullFirst'  => $full_first,
			'fullSecond' => $full_second,
			'allTypes'   => $all_types,
			'variables'  => $variables,
			'styles'     => $styles,
			'presets'    => $presets,
			'customCss'  => $custom_css,
		);
		$strings = array();
		foreach ( $outputs as $name => $call ) {
			$strings[ $name ] = is_array( $call ) && ! $call['threw'] && is_string( $call['value'] )
				? $call['value']
				: '';
		}

		$expected = array(
			'variable'      => '--wp--preset--color--' . $slug,
			'presetClass'   => '.has-' . $slug . '-color',
			'customText'    => $custom_text_color,
			'customCssRule' => '.component-fuzz-global-' . $slug,
			'fontVariable'  => '--wp--preset--font-size--' . $slug,
			'fontClass'     => '.has-' . $slug . '-font-family',
			'paragraph'     => '.wp-block-paragraph',
			'spacingValue'  => $spacing,
		);

		$all_calls_ok = true;
		foreach ( $outputs as $call ) {
			if ( ! is_array( $call ) || ! empty( $call['threw'] ) || ! is_string( $call['value'] ) || '' === trim( $call['value'] ) ) {
				$all_calls_ok = false;
				break;
			}
		}

		$all_css_safe = true;
		foreach ( $strings as $css ) {
			if ( self::contains_raw_unsafe_bytes( $css ) || ! self::css_structure_ok( $css ) ) {
				$all_css_safe = false;
				break;
			}
		}

		$resolver_restored = self::theme_json_resolver_matches( $resolver_before );
		$full_css          = $strings['fullFirst'];
		$ok                = $cache_cleared
			&& $all_calls_ok
			&& $strings['fullFirst'] === $strings['fullSecond']
			&& $cache_after_first === $strings['fullFirst']
			&& $cache_after_typed === $strings['fullFirst']
			&& str_contains( $strings['variables'], $expected['variable'] )
			&& str_contains( $strings['variables'], $expected['fontVariable'] )
			&& str_contains( $strings['variables'], $expected['spacingValue'] )
			&& str_contains( $strings['presets'], $expected['presetClass'] )
			&& str_contains( $strings['presets'], $expected['fontClass'] )
			&& str_contains( $strings['styles'], $expected['customText'] )
			&& str_contains( $strings['styles'], $expected['paragraph'] )
			&& str_contains( $strings['customCss'], $expected['customCssRule'] )
			&& str_contains( $strings['allTypes'], $expected['customCssRule'] )
			&& ! str_contains( $strings['fullFirst'], $expected['customCssRule'] )
			&& str_ends_with( trim( $strings['allTypes'] ), trim( $strings['customCss'] ) )
			&& ! str_contains( strtolower( $full_css ), 'javascript:' )
			&& ! str_contains( strtolower( $full_css ), '<script' )
			&& $all_css_safe
			&& 0 === $query_delta
			&& $resolver_restored
			&& $cache_restored;

		return $ctx->result(
			'style.wp-get-global-stylesheet.seeded-resolver-cache',
			$ok,
			array(
				'slug'             => $slug,
				'expected'         => $expected,
				'cacheCleared'     => $cache_cleared,
				'cacheReused'      => $cache_after_first === $strings['fullFirst'],
				'cachePreservedAfterTypedCalls' => $cache_after_typed === $strings['fullFirst'],
				'cacheBeforeFound' => $cache_before_found,
				'cacheRestored'    => $cache_restored,
				'queryDelta'       => $query_delta,
				'queryTrackingAvailable' => null !== $queries_before,
				'resolverRestored' => $resolver_restored,
				'calls'            => array_map( array( self::class, 'describe_call' ), $outputs ),
				'lengths'          => array_map( 'strlen', $strings ),
				'structures'       => array_map( array( self::class, 'css_structure_report' ), $strings ),
				'unsafeBytes'      => array_map( array( self::class, 'unsafe_byte_report' ), $strings ),
				'notCovered'       => 'Active theme file discovery and wp_global_styles CPT querying are bypassed by seeded WP_Theme_JSON_Resolver state.',
			)
		);
	}

	private static function check_global_styles_user_data_and_getters( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::global_styles_user_data_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'style.global-styles.user-data-and-getters',
				'Required global styles user-data APIs are unavailable.',
				array( 'missing' => implode( ', ', $missing ) )
			);
		}

		$slug              = self::normalize_preset_slug( self::safe_slug( $ctx, 'global-user' ) );
		$theme_slug        = 'cfz-theme-' . $slug;
		$wrong_theme_slug  = 'cfz-other-' . $slug;
		$unsafe_theme_slug = 'cfz-unsafe-' . $slug;
		$created_theme_slug = 'cfz-create-' . $slug;
		$theme_preset_slug = 'theme-' . $slug;
		$user_token        = 'user-token-' . $slug;
		$theme_token       = 'theme-token-' . $slug;
		$block_token       = 'block-token-' . $slug;
		$theme_color       = self::hex_color( $ctx );
		$user_color        = self::hex_color( $ctx, array( $theme_color ) );
		$block_color       = self::hex_color( $ctx, array( $theme_color, $user_color ) );
		$decoy_color       = self::hex_color( $ctx, array( $theme_color, $user_color, $block_color ) );
		$resolver_before   = self::snapshot_theme_json_resolver();
		$globals_before    = self::snapshot_globals(
			array(
				'_wp_post_type_features',
				'post_type_meta_caps',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'wp_post_statuses',
				'wp_post_types',
				'wp_rewrite',
				'wp_taxonomies',
			)
		);
		$wpdb_before       = self::snapshot_wpdb_stub_state();
		$before_counts     = self::wpdb_content_counts();
		$failures          = array();

		$valid_id            = 0;
		$wrong_theme_id      = 0;
		$draft_id            = 0;
		$unsafe_id           = 0;
		$created_id_first    = null;
		$created_id_second   = null;
		$user_cpt            = array();
		$unsafe_user_raw     = array();
		$created_post        = null;
		$settings_custom     = null;
		$settings_base       = null;
		$settings_block      = null;
		$styles_custom       = null;
		$styles_base         = null;
		$styles_base_resolved = null;
		$styles_block        = null;
		$custom_cache_found  = false;
		$theme_cache_found   = false;
		$custom_cache        = false;
		$theme_cache         = false;
		$cache_cleaned       = false;
		$query_posts         = array();
		$query_log           = array();
		$queries_before      = self::wpdb_stub_queries();
		$queries_after       = null;
		$posts_pre_query_filter = static function ( $posts, \WP_Query $query ) use ( &$query_posts, &$query_log ) {
			if ( 'wp_global_styles' !== (string) ( $query->query_vars['post_type'] ?? '' ) ) {
				return $posts;
			}

			$theme = null;
			foreach ( (array) ( $query->query_vars['tax_query'] ?? array() ) as $tax_query ) {
				if ( is_array( $tax_query ) && 'wp_theme' === ( $tax_query['taxonomy'] ?? '' ) ) {
					$terms = (array) ( $tax_query['terms'] ?? array() );
					$theme = null === reset( $terms ) ? null : (string) reset( $terms );
					break;
				}
			}
			if ( null === $theme ) {
				return $posts;
			}

			$statuses   = array_map( 'strval', (array) ( $query->query_vars['post_status'] ?? array( 'publish' ) ) );
			$status_map = array_fill_keys( $statuses, true );
			$candidates = array_values(
				array_filter(
					$query_posts,
					static function ( array $entry ) use ( $theme, $status_map ): bool {
						return $theme === $entry['theme'] && isset( $status_map[ $entry['status'] ] );
					}
				)
			);

			usort(
				$candidates,
				static function ( array $a, array $b ): int {
					$date_compare = strcmp( $b['date'], $a['date'] );
					return 0 !== $date_compare ? $date_compare : ( $b['id'] <=> $a['id'] );
				}
			);

			$selected = array();
			if ( isset( $candidates[0]['post'] ) && $candidates[0]['post'] instanceof \WP_Post ) {
				$selected[] = $candidates[0]['post'];
			}

			$query->found_posts   = count( $selected );
			$query->max_num_pages = count( $selected );
			$query_log[]          = array(
				'theme'       => $theme,
				'statuses'    => $statuses,
				'candidateIds' => array_column( $candidates, 'id' ),
				'selectedIds'  => array_map(
					static function ( \WP_Post $post ): int {
						return (int) $post->ID;
					},
					$selected
				),
			);

			return $selected;
		};

		try {
			\add_filter( 'posts_pre_query', $posts_pre_query_filter, 10, 2 );
			self::prepare_global_styles_user_data_runtime(
				$theme_slug,
				$theme_preset_slug,
				$theme_color,
				$theme_token,
				$wrong_theme_slug
			);

			$wrong_theme_id = self::insert_global_styles_fixture(
				$wrong_theme_slug,
				self::global_styles_user_data_content(
					true,
					'wrong-theme-token-' . $slug,
					'wrong-block-token-' . $slug,
					$decoy_color,
					$decoy_color
				),
				'publish',
				'2026-06-24 12:00:00'
			);
			self::add_global_styles_query_fixture( $query_posts, $wrong_theme_id, $wrong_theme_slug );
			$draft_id       = self::insert_global_styles_fixture(
				$theme_slug,
				self::global_styles_user_data_content(
					true,
					'draft-token-' . $slug,
					'draft-block-token-' . $slug,
					$decoy_color,
					$decoy_color
				),
				'draft',
				'2026-06-24 13:00:00'
			);
			self::add_global_styles_query_fixture( $query_posts, $draft_id, $theme_slug );
			$valid_id       = self::insert_global_styles_fixture(
				$theme_slug,
				self::global_styles_user_data_content(
					true,
					$user_token,
					$block_token,
					$user_color,
					$block_color
				),
				'publish',
				'2026-06-24 11:00:00'
			);
			self::add_global_styles_query_fixture( $query_posts, $valid_id, $theme_slug );

			\WP_Theme_JSON_Resolver::clean_cached_data();
			self::seed_theme_json_resolver_for_user_data_getters( $theme_preset_slug, $theme_color, $theme_token );
			$user_cpt = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( \wp_get_theme() );

			$settings_custom      = \wp_get_global_settings( array( 'custom', 'componentFuzz' ) );
			$settings_base        = \wp_get_global_settings( array( 'custom', 'componentFuzz' ), array( 'origin' => 'base' ) );
			$settings_block       = \wp_get_global_settings(
				array( 'custom', 'componentFuzz', 'blockToken' ),
				array( 'block_name' => 'core/paragraph' )
			);
			$styles_custom        = \wp_get_global_styles( array( 'color', 'text' ) );
			$styles_base          = \wp_get_global_styles( array( 'color', 'text' ), array( 'origin' => 'base' ) );
			$styles_base_resolved = \wp_get_global_styles(
				array( 'color', 'text' ),
				array(
					'origin'     => 'base',
					'transforms' => array( 'resolve-variables' ),
				)
			);
			$styles_block         = \wp_get_global_styles(
				array( 'color', 'text' ),
				array( 'block_name' => 'core/paragraph' )
			);
			$custom_cache         = \wp_cache_get( 'wp_get_global_settings_custom', 'theme_json', false, $custom_cache_found );
			$theme_cache          = \wp_cache_get( 'wp_get_global_settings_theme', 'theme_json', false, $theme_cache_found );

			\wp_clean_theme_json_cache();
			$cache_cleaned = false === \wp_cache_get( 'wp_get_global_settings_custom', 'theme_json' )
				&& false === \wp_cache_get( 'wp_get_global_settings_theme', 'theme_json' );

			\update_option( 'stylesheet', $unsafe_theme_slug, false );
			\update_option( 'template', $unsafe_theme_slug, false );
			$unsafe_id = self::insert_global_styles_fixture(
				$unsafe_theme_slug,
				self::global_styles_user_data_content(
					false,
					'unsafe-token-' . $slug,
					'unsafe-block-token-' . $slug,
					$decoy_color,
					$decoy_color
				),
				'publish',
				'2026-06-24 14:00:00'
			);
			self::add_global_styles_query_fixture( $query_posts, $unsafe_id, $unsafe_theme_slug );
			\WP_Theme_JSON_Resolver::clean_cached_data();
			$unsafe_user_raw = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();

			\update_option( 'stylesheet', $created_theme_slug, false );
			\update_option( 'template', $created_theme_slug, false );
			\WP_Theme_JSON_Resolver::clean_cached_data();
			$created_id_first  = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			$created_id_second = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			$created_post      = is_int( $created_id_first ) ? \get_post( $created_id_first ) : null;
			$queries_after     = self::wpdb_stub_queries();
		} finally {
			\remove_filter( 'posts_pre_query', $posts_pre_query_filter, 10 );
			if ( function_exists( 'wp_cache_flush' ) ) {
				\wp_cache_flush();
			}
			if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
				\wp_clean_theme_json_cache();
			}
			if ( function_exists( 'wp_clean_themes_cache' ) ) {
				\wp_clean_themes_cache( false );
			}
			self::restore_theme_json_resolver( $resolver_before );
			self::restore_globals( $globals_before );
			self::restore_wpdb_stub_state( $wpdb_before );
		}

		$after_counts       = self::wpdb_content_counts();
		$created_post_data  = $created_post instanceof \WP_Post ? get_object_vars( $created_post ) : array();
		$created_json       = isset( $created_post_data['post_content'] ) ? json_decode( $created_post_data['post_content'], true ) : null;
		$query_delta        = null;
		if ( null !== $queries_before && null !== $queries_after ) {
			$query_delta = count( $queries_after ) - count( $queries_before );
		}

		self::collect_style_failure(
			$failures,
			isset( $user_cpt['ID'] )
				&& (int) $valid_id === (int) $user_cpt['ID']
				&& (int) $wrong_theme_id !== (int) $user_cpt['ID']
				&& (int) $draft_id !== (int) $user_cpt['ID'],
			'published active-theme wp_global_styles post wins over draft and wrong-theme decoys',
			array(
				'validId'       => $valid_id,
				'wrongThemeId'  => $wrong_theme_id,
				'draftId'       => $draft_id,
				'selectedId'    => $user_cpt['ID'] ?? null,
				'selectedTitle' => $user_cpt['post_title'] ?? null,
			)
		);
		self::collect_style_failure(
			$failures,
			is_array( $settings_custom )
				&& ( $settings_custom['themeToken'] ?? null ) === $theme_token
				&& ( $settings_custom['userToken'] ?? null ) === $user_token
				&& is_array( $settings_base )
				&& ( $settings_base['themeToken'] ?? null ) === $theme_token
				&& ! array_key_exists( 'userToken', $settings_base )
				&& $block_token === $settings_block,
			'wp_get_global_settings respects custom/base origins and block-name path rewriting',
			array(
				'settingsCustom' => $settings_custom,
				'settingsBase'   => $settings_base,
				'settingsBlock'  => $settings_block,
			)
		);
		self::collect_style_failure(
			$failures,
			$user_color === $styles_custom
				&& is_string( $styles_base )
				&& str_contains( $styles_base, '--wp--preset--color--' . $theme_preset_slug )
				&& $theme_color === $styles_base_resolved
				&& $block_color === $styles_block,
			'wp_get_global_styles respects user override, base origin, variable resolution, and block paths',
			array(
				'stylesCustom'       => $styles_custom,
				'stylesBase'         => $styles_base,
				'stylesBaseResolved' => $styles_base_resolved,
				'stylesBlock'        => $styles_block,
			)
		);
		self::collect_style_failure(
			$failures,
			$custom_cache_found
				&& $theme_cache_found
				&& is_array( $custom_cache )
				&& is_array( $theme_cache )
				&& $cache_cleaned,
			'global settings cache keys are populated and wp_clean_theme_json_cache clears them',
			array(
				'customCacheFound' => $custom_cache_found,
				'themeCacheFound'  => $theme_cache_found,
				'cacheCleaned'     => $cache_cleaned,
			)
		);
		self::collect_style_failure(
			$failures,
			(int) $unsafe_id > 0
				&& ( ! isset( $unsafe_user_raw['styles']['color']['text'] ) || $decoy_color !== $unsafe_user_raw['styles']['color']['text'] )
				&& empty( $unsafe_user_raw['isGlobalStylesUserThemeJSON'] ),
			'unsafe user global styles content without the safety flag does not enter user config',
			array(
				'unsafeId'      => $unsafe_id,
				'unsafeUserRaw' => $unsafe_user_raw,
			)
		);
		self::collect_style_failure(
			$failures,
			is_int( $created_id_first )
				&& $created_id_first > 0
				&& $created_id_first === $created_id_second
				&& $created_post instanceof \WP_Post
				&& 'wp_global_styles' === $created_post->post_type
				&& 'publish' === $created_post->post_status
				&& is_array( $created_json )
				&& true === ( $created_json['isGlobalStylesUserThemeJSON'] ?? null ),
			'get_user_global_styles_post_id creates one cached safe publish post when absent',
			array(
				'firstId'      => $created_id_first,
				'secondId'     => $created_id_second,
				'createdPost'  => $created_post_data,
				'createdJson'  => $created_json,
			)
		);
		self::collect_style_failure(
			$failures,
			$before_counts === $after_counts
				&& self::theme_json_resolver_matches( $resolver_before )
				&& self::globals_match_snapshot( $globals_before ),
			'global styles user-data row restores resolver, globals, and content state',
			array(
				'beforeCounts'     => $before_counts,
				'afterCounts'      => $after_counts,
				'resolverRestored' => self::theme_json_resolver_matches( $resolver_before ),
				'globalsRestored'  => self::globals_match_snapshot( $globals_before ),
			)
		);

		return $ctx->result(
			'style.global-styles.user-data-and-getters',
			array() === $failures,
			array(
				'themeSlug'       => $theme_slug,
				'validId'         => $valid_id,
				'createdId'       => $created_id_first,
				'queryDelta'      => $query_delta,
				'queryLog'        => $query_log,
				'queryTrackingAvailable' => null !== $queries_before,
				'failures'        => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function global_styles_user_data_requirements(): array {
		$missing = array();

		foreach ( array( 'Component_Fuzz_WPDB_Stub', 'WP_Post', 'WP_Query', 'WP_Rewrite', 'WP_Theme_JSON', 'WP_Theme_JSON_Resolver' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'get_post',
				'is_wp_error',
				'remove_filter',
				'update_option',
				'wp_cache_flush',
				'wp_clean_theme_json_cache',
				'wp_clean_themes_cache',
				'wp_get_global_settings',
				'wp_get_global_styles',
				'wp_get_theme',
				'wp_insert_post',
				'wp_insert_term',
				'wp_json_encode',
				'wp_set_object_terms',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function prepare_global_styles_user_data_runtime(
		string $theme_slug,
		string $theme_preset_slug,
		string $theme_color,
		string $theme_token,
		string $wrong_theme_slug
	): void {
		unset( $theme_preset_slug, $theme_color, $theme_token );

		$GLOBALS['_wp_post_type_features'] = array();
		$GLOBALS['post_type_meta_caps']    = array();
		$GLOBALS['wp_post_statuses']       = array();
		$GLOBALS['wp_post_types']          = array();
		$GLOBALS['wp_rewrite']             = new \WP_Rewrite();
		$GLOBALS['wp_taxonomies']          = array();

		\create_initial_post_types();
		\create_initial_taxonomies();

		foreach ( array( $theme_slug, $wrong_theme_slug ) as $slug ) {
			$term = \wp_insert_term( $slug, 'wp_theme' );
			if ( \is_wp_error( $term ) && 'term_exists' !== $term->get_error_code() ) {
				throw new \RuntimeException( 'Could not create wp_theme term: ' . $term->get_error_code() );
			}
		}

		\update_option( 'current_theme', 'Component Fuzz Global Styles', false );
		\update_option( 'stylesheet', $theme_slug, false );
		\update_option( 'template', $theme_slug, false );
		\WP_Theme_JSON_Resolver::clean_cached_data();
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			\wp_clean_theme_json_cache();
		}
	}

	private static function seed_theme_json_resolver_for_user_data_getters( string $slug, string $theme_color, string $theme_token ): void {
		$registered_blocks = array_fill_keys(
			array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ),
			true
		);
		$block_cache       = array(
			'core'   => $registered_blocks,
			'blocks' => $registered_blocks,
			'theme'  => $registered_blocks,
			'user'   => $registered_blocks,
		);

		self::set_static_property( 'WP_Theme_JSON_Resolver', 'blocks_cache', $block_cache );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'theme_json_file_cache', array() );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'user_custom_post_type_id', null );
		self::set_static_property(
			'WP_Theme_JSON_Resolver',
			'core',
			new \WP_Theme_JSON(
				array(
					'version' => \WP_Theme_JSON::LATEST_SCHEMA,
				),
				'default'
			)
		);
		self::set_static_property(
			'WP_Theme_JSON_Resolver',
			'blocks',
			new \WP_Theme_JSON(
				array(
					'version' => \WP_Theme_JSON::LATEST_SCHEMA,
					'styles'  => array(
						'blocks' => array(
							'core/paragraph' => array(
								'color' => array(
									'text' => '#101010',
								),
							),
						),
					),
				),
				'blocks'
			)
		);
		self::set_static_property(
			'WP_Theme_JSON_Resolver',
			'theme',
			new \WP_Theme_JSON(
				array(
					'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => array(
						'custom' => array(
							'componentFuzz' => array(
								'themeToken' => $theme_token,
							),
						),
						'color'  => array(
							'palette' => array(
								array(
									'name'  => 'Component Fuzz Theme',
									'slug'  => $slug,
									'color' => $theme_color,
								),
							),
						),
					),
					'styles'   => array(
						'color'  => array(
							'text' => 'var:preset|color|' . $slug,
						),
						'blocks' => array(
							'core/paragraph' => array(
								'color' => array(
									'text' => $theme_color,
								),
							),
						),
					),
				),
				'theme'
			)
		);
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'user', null );
	}

	private static function global_styles_user_data_content(
		bool $safe_flag,
		string $user_token,
		string $block_token,
		string $user_color,
		string $block_color
	): string {
		return \wp_json_encode(
			array(
				'isGlobalStylesUserThemeJSON' => $safe_flag,
				'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
				'settings'                    => array(
					'custom' => array(
						'componentFuzz' => array(
							'userToken' => $user_token,
						),
					),
					'blocks' => array(
						'core/paragraph' => array(
							'custom' => array(
								'componentFuzz' => array(
									'blockToken' => $block_token,
								),
							),
						),
					),
				),
				'styles'                      => array(
					'color'  => array(
						'text' => $user_color,
					),
					'blocks' => array(
						'core/paragraph' => array(
							'color' => array(
								'text' => $block_color,
							),
						),
					),
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
		);
	}

	private static function insert_global_styles_fixture( string $theme_slug, string $content, string $status, string $date ): int {
		$post_id = \wp_insert_post(
			array(
				'post_author'       => 0,
				'post_content'      => $content,
				'post_date'         => $date,
				'post_date_gmt'     => $date,
				'post_modified'     => $date,
				'post_modified_gmt' => $date,
				'post_name'         => 'wp-global-styles-' . $theme_slug . '-' . substr( sha1( $content . $date ), 0, 8 ),
				'post_status'       => $status,
				'post_title'        => 'Component Fuzz Global Styles ' . $theme_slug,
				'post_type'         => 'wp_global_styles',
			),
			true,
			false
		);
		if ( \is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Could not create wp_global_styles fixture: ' . $post_id->get_error_code() );
		}

		$term = \wp_insert_term( $theme_slug, 'wp_theme' );
		if ( \is_wp_error( $term ) && 'term_exists' !== $term->get_error_code() ) {
			throw new \RuntimeException( 'Could not create wp_theme term: ' . $term->get_error_code() );
		}

		$assigned = \wp_set_object_terms( (int) $post_id, $theme_slug, 'wp_theme' );
		if ( \is_wp_error( $assigned ) ) {
			throw new \RuntimeException( 'Could not assign wp_theme term: ' . $assigned->get_error_code() );
		}

		return (int) $post_id;
	}

	private static function add_global_styles_query_fixture( array &$query_posts, int $post_id, string $theme_slug ): void {
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( 'Could not reload wp_global_styles fixture.' );
		}

		$query_posts[] = array(
			'id'     => (int) $post->ID,
			'post'   => $post,
			'theme'  => $theme_slug,
			'status' => (string) $post->post_status,
			'date'   => (string) $post->post_date,
		);
	}

	private static function collect_style_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::preview( $details ),
		);
	}

	private static function snapshot_wpdb_stub_state(): ?array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return null;
		}

		return array(
			'options' => method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
			'runtime' => method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_runtime_state' )
				? $GLOBALS['wpdb']->component_fuzz_get_runtime_state()
				: null,
		);
	}

	private static function restore_wpdb_stub_state( ?array $snapshot ): void {
		if ( null === $snapshot || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return;
		}

		if ( method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
		if ( null !== $snapshot['options'] && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}
		if ( null !== $snapshot['runtime'] && method_exists( $GLOBALS['wpdb'], 'component_fuzz_restore_runtime_state' ) ) {
			$GLOBALS['wpdb']->component_fuzz_restore_runtime_state( $snapshot['runtime'] );
		}
	}

	private static function wpdb_content_counts(): ?array {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return null;
	}

	private static function snapshot_theme_json_resolver(): array {
		$snapshot = array();
		foreach (
			array(
				'blocks_cache',
				'core',
				'blocks',
				'theme',
				'user',
				'user_custom_post_type_id',
				'i18n_schema',
				'theme_json_file_cache',
			) as $property
		) {
			$snapshot[ $property ] = self::get_static_property( 'WP_Theme_JSON_Resolver', $property );
		}

		return $snapshot;
	}

	private static function restore_theme_json_resolver( array $snapshot ): void {
		foreach ( $snapshot as $property => $value ) {
			self::set_static_property( 'WP_Theme_JSON_Resolver', $property, $value );
		}
	}

	private static function theme_json_resolver_matches( array $snapshot ): bool {
		foreach ( $snapshot as $property => $value ) {
			if ( self::get_static_property( 'WP_Theme_JSON_Resolver', $property ) !== $value ) {
				return false;
			}
		}

		return true;
	}

	private static function seed_theme_json_resolver_for_global_stylesheet(
		string $slug,
		string $theme_color,
		string $custom_text_color,
		string $spacing,
		string $font_size
	): void {
		$registered_blocks = array_fill_keys(
			array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ),
			true
		);
		$block_cache       = array(
			'core'   => $registered_blocks,
			'blocks' => $registered_blocks,
			'theme'  => $registered_blocks,
			'user'   => $registered_blocks,
		);

		self::set_static_property( 'WP_Theme_JSON_Resolver', 'blocks_cache', $block_cache );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'theme_json_file_cache', array() );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'user_custom_post_type_id', null );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'core', new \WP_Theme_JSON( self::global_stylesheet_default_theme_json( $slug ), 'default' ) );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'blocks', new \WP_Theme_JSON( self::global_stylesheet_block_theme_json( $slug ), 'blocks' ) );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'theme', new \WP_Theme_JSON( self::global_stylesheet_theme_theme_json( $slug, $theme_color, $spacing, $font_size ), 'theme' ) );
		self::set_static_property( 'WP_Theme_JSON_Resolver', 'user', new \WP_Theme_JSON( self::global_stylesheet_user_theme_json( $slug, $custom_text_color ), 'custom' ) );
	}

	private static function global_stylesheet_default_theme_json( string $slug ): array {
		return array(
			'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
			'settings' => array(
				'color' => array(
					'palette' => array(
						array(
							'name'  => 'Component Fuzz Default',
							'slug'  => 'default-' . $slug,
							'color' => '#102030',
						),
					),
				),
			),
		);
	}

	private static function global_stylesheet_block_theme_json( string $slug ): array {
		return array(
			'version' => \WP_Theme_JSON::LATEST_SCHEMA,
			'styles'  => array(
				'blocks' => array(
					'core/paragraph' => array(
						'color' => array(
							'text' => '#203040',
						),
					),
				),
			),
		);
	}

	private static function global_stylesheet_theme_theme_json( string $slug, string $theme_color, string $spacing, string $font_size ): array {
		return array(
			'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
			'settings' => array(
				'custom'     => array(
					'componentFuzz' => array(
						'token' => $spacing,
					),
				),
				'color'      => array(
					'palette' => array(
						array(
							'name'  => 'Component Fuzz Theme',
							'slug'  => $slug,
							'color' => $theme_color,
						),
					),
				),
				'spacing'    => array(
					'units'        => array( 'px', 'rem', '%' ),
					'spacingSizes' => array(
						array(
							'name' => 'Component Fuzz Space',
							'slug' => $slug,
							'size' => $spacing,
						),
					),
				),
				'typography' => array(
					'fontSizes'    => array(
						array(
							'name' => 'Component Fuzz Font',
							'slug' => $slug,
							'size' => $font_size,
						),
					),
					'fontFamilies' => array(
						array(
							'name'       => 'Component Fuzz Family',
							'slug'       => $slug,
							'fontFamily' => 'Inter, Arial, sans-serif',
						),
					),
				),
			),
			'styles'   => array(
				'color'      => array(
					'text' => 'var:preset|color|' . $slug,
				),
				'spacing'    => array(
					'padding' => array(
						'top'    => $spacing,
						'right'  => $spacing,
						'bottom' => '0',
						'left'   => '0',
					),
				),
				'typography' => array(
					'fontSize' => 'var:preset|font-size|' . $slug,
					'fontFamily' => 'var:preset|font-family|' . $slug,
				),
				'blocks'     => array(
					'core/paragraph' => array(
						'color'      => array(
							'text' => $theme_color,
						),
						'typography' => array(
							'fontSize' => 'var:preset|font-size|' . $slug,
						),
					),
				),
			),
		);
	}

	private static function global_stylesheet_user_theme_json( string $slug, string $custom_text_color ): array {
		return array(
			'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
			'styles'   => array(
				'color' => array(
					'text' => $custom_text_color,
				),
				'css'   => '.component-fuzz-global-' . $slug . '{color:' . $custom_text_color . ';}',
			),
		);
	}

	private static function wpdb_stub_queries(): ?array {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'component_fuzz_get_queries' ) ) {
			$queries = $wpdb->component_fuzz_get_queries();
			return is_array( $queries ) ? $queries : null;
		}

		return null;
	}

	private static function normalize_preset_slug( string $slug ): string {
		if ( function_exists( '_wp_to_kebab_case' ) ) {
			return \_wp_to_kebab_case( $slug );
		}

		$slug = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $slug ) );
		$slug = preg_replace( '/([a-z])([0-9])/', '$1-$2', $slug );
		$slug = preg_replace( '/([0-9])([a-z])/', '$1-$2', $slug );
		$slug = preg_replace( '/-+/', '-', $slug );
		return trim( $slug, '-' );
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$safe_slug       = self::safe_slug( $ctx, 'preset' );
		$unicode_slug    = 'fuzz-' . $ctx->choice( array( 'cafe', 'nino', 'uber' ) ) . '-' . $ctx->int( 1, 999 );
		$base_text       = self::safe_color( $ctx );
		$incoming_text   = self::safe_color( $ctx );
		$variation_color = self::simple_safe_color( $ctx );
		if ( $incoming_text === $base_text ) {
			$incoming_text = '#112233';
		}

		$safe_spacing = self::spacing_value( $ctx, false );
		$safe_font    = self::font_size_value( $ctx, false );
		$selector     = self::selector( $ctx );
		$block_styles = self::block_styles( $ctx, $safe_slug );

		$merge_selector = '.component-fuzz-merge-' . $ctx->seed() . '-' . $ctx->iteration();
		$first_color    = '#102030';
		$second_color   = '#405060';
		$css_rules      = array(
			array(
				'selector'     => $merge_selector,
				'declarations' => array(
					'color'                    => $first_color,
					'--wp--custom--merge-token' => $safe_spacing,
				),
			),
			array(
				'selector'     => $merge_selector,
				'declarations' => array(
					'color'            => $second_color,
					'margin-top'       => $safe_spacing,
					'background-image' => "url('javascript:alert(1)')",
				),
			),
			array(
				'rules_group'  => '@media (min-width: 40rem)',
				'selector'     => self::selector( $ctx ),
				'declarations' => array(
					'font-size'                => $safe_font,
					'--wp--custom--media-token' => $safe_spacing,
				),
			),
			array(
				'rules_group'  => '@supports (display: grid)',
				'selector'     => self::selector( $ctx ),
				'declarations' => array(
					'display'              => 'grid',
					'grid-template-columns' => 'repeat(2, minmax(0, 1fr))',
				),
			),
			array(
				'selector'     => '',
				'declarations' => array(
					'color' => '#ffffff',
				),
			),
		);

		$theme_base     = self::theme_json_base( $ctx, $safe_slug, $unicode_slug, $base_text, $safe_spacing, $safe_font );
		$theme_incoming = self::theme_json_incoming( $ctx, $safe_slug, $incoming_text, $safe_spacing );
		$theme_unsafe   = self::theme_json_unsafe( $ctx, $safe_slug, $unicode_slug, $variation_color, $safe_spacing );
		$registry_style = self::registered_block_style_variation_case( $ctx, $safe_spacing );

		return array(
			'safeSlug'                      => $safe_slug,
			'unicodeSlug'                   => $unicode_slug,
			'safeColor'                     => self::simple_safe_color( $ctx ),
			'safeSpacing'                   => $safe_spacing,
			'safeFontSize'                  => $safe_font,
			'selector'                      => $selector,
			'convertVarsToClassnames'       => $ctx->bool(),
			'blockStyles'                   => $block_styles,
			'cssRules'                      => $css_rules,
			'mergeSelector'                 => $merge_selector,
			'mergeExpectedColor'            => $second_color,
			'mergeReplacedColor'            => $first_color,
			'themeJsonBase'                 => $theme_base,
			'themeJsonIncoming'             => $theme_incoming,
			'themeJsonUnsafe'               => $theme_unsafe,
			'registeredBlockStyleVariation' => $registry_style,
			'baseTextColor'                 => $base_text,
			'incomingTextColor'             => $incoming_text,
			'incomingUnits'                 => array( 'rem', 'px', '%' ),
			'variationTextColor'            => $variation_color,
		);
	}

	private static function block_styles( \ComponentFuzz\FuzzContext $ctx, string $slug ): array {
		$radius = self::spacing_value( $ctx, false );

		return array(
			'background' => array(
				'backgroundImage'      => $ctx->bool()
					? array( 'url' => 'https://example.test/fuzz-' . $ctx->int( 1, 99 ) . '.png' )
					: "url('javascript:alert(1)')",
				'backgroundPosition'   => $ctx->choice( array( 'center center', '20% 80%', 'left top' ) ),
				'backgroundRepeat'     => $ctx->choice( array( 'no-repeat', 'repeat-x', 'round' ) ),
				'backgroundSize'       => $ctx->choice( array( 'cover', 'contain', '50% auto' ) ),
				'backgroundAttachment' => $ctx->choice( array( 'scroll', 'fixed' ) ),
			),
			'color'      => array(
				'text'       => $ctx->bool() ? 'var:preset|color|' . $slug : self::safe_color( $ctx ),
				'background' => self::maybe_unsafe_color( $ctx ),
				'gradient'   => $ctx->choice(
					array(
						'linear-gradient(90deg, #123456, #abcdef)',
						'var:preset|gradient|' . $slug,
					)
				),
			),
			'spacing'    => array(
				'padding' => self::box_values( $ctx ),
				'margin'  => $ctx->bool() ? self::box_values( $ctx ) : self::spacing_value( $ctx, false ),
			),
			'typography' => array(
				'fontSize'       => $ctx->bool() ? 'var:preset|font-size|' . $slug : self::font_size_value( $ctx, false ),
				'fontFamily'     => $ctx->bool() ? 'var:preset|font-family|' . $slug : 'Inter, Arial, sans-serif',
				'fontStyle'      => $ctx->choice( array( 'normal', 'italic', 'oblique' ) ),
				'fontWeight'     => (string) $ctx->choice( array( 300, 400, 600, 700 ) ),
				'lineHeight'     => $ctx->choice( array( '1', '1.25', '1.6' ) ),
				'textColumns'    => (string) $ctx->int( 1, 4 ),
				'textDecoration' => $ctx->choice( array( 'none', 'underline', 'line-through' ) ),
				'textIndent'     => self::spacing_value( $ctx, false ),
				'textTransform'  => $ctx->choice( array( 'none', 'uppercase', 'capitalize' ) ),
				'letterSpacing'  => $ctx->choice( array( '0.01em', '0.08em', '1px' ) ),
				'writingMode'    => $ctx->choice( array( 'horizontal-tb', 'vertical-rl' ) ),
			),
			'border'     => array(
				'color'  => $ctx->bool() ? 'var:preset|color|' . $slug : self::safe_color( $ctx ),
				'radius' => array(
					'topLeft'     => $radius,
					'topRight'    => $radius,
					'bottomRight' => 'var:preset|border-radius|' . $slug,
				),
				'style'  => $ctx->choice( array( 'solid', 'dashed', 'double' ) ),
				'width'  => $ctx->choice( array( '1px', '2px', '0' ) ),
				'top'    => array(
					'color' => self::safe_color( $ctx ),
					'width' => '1px',
					'style' => 'solid',
				),
			),
			'dimensions' => array(
				'aspectRatio' => $ctx->choice( array( '1 / 1', '16 / 9', '4 / 3' ) ),
				'height'      => self::spacing_value( $ctx, false ),
				'minHeight'   => self::spacing_value( $ctx, false ),
				'objectFit'   => $ctx->choice( array( 'cover', 'contain', 'fill' ) ),
				'width'       => $ctx->bool() ? 'var:preset|dimension|' . $slug : self::spacing_value( $ctx, false ),
			),
			'shadow'     => '0 0 ' . $ctx->int( 1, 8 ) . 'px rgba(0,0,0,.2)',
			'unknown'    => array(
				'unsafe' => '<script>alert(1)</script>',
			),
		);
	}

	private static function theme_json_base(
		\ComponentFuzz\FuzzContext $ctx,
		string $slug,
		string $unicode_slug,
		string $text_color,
		string $spacing,
		string $font_size
	): array {
		return array(
			'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
			'settings' => array(
				'custom'     => array(
					'fuzz' => array(
						'token' => $spacing,
					),
				),
				'color'      => array(
					'palette' => array(
						array(
							'name'  => 'Fuzz Base',
							'slug'  => $slug,
							'color' => $text_color,
						),
						array(
							'name'  => 'Unicode Slug',
							'slug'  => $unicode_slug,
							'color' => self::safe_color( $ctx ),
						),
					),
				),
				'spacing'    => array(
					'units'        => array( 'px', 'em', 'rem' ),
					'spacingSizes' => array(
						array(
							'name' => 'Fuzz Space',
							'slug' => $slug,
							'size' => $spacing,
						),
					),
				),
				'typography' => array(
					'fontSizes'    => array(
						array(
							'name' => 'Fuzz Font',
							'slug' => $slug,
							'size' => $font_size,
						),
					),
					'fontFamilies' => array(
						array(
							'name'       => 'Fuzz Sans',
							'slug'       => $slug,
							'fontFamily' => 'Inter, Arial, sans-serif',
						),
					),
				),
			),
			'styles'   => array(
				'color'      => array(
					'text' => $text_color,
				),
				'spacing'    => array(
					'padding' => array(
						'top'    => $spacing,
						'bottom' => $spacing,
					),
				),
				'typography' => array(
					'fontSize' => $font_size,
				),
			),
		);
	}

	private static function theme_json_incoming( \ComponentFuzz\FuzzContext $ctx, string $slug, string $text_color, string $spacing ): array {
		return array(
			'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
			'settings' => array(
				'spacing' => array(
					'units'        => array( 'rem', 'px', '%' ),
					'spacingSizes' => array(
						array(
							'name' => 'Incoming Space',
							'slug' => $slug,
							'size' => $spacing,
						),
					),
				),
			),
			'styles'   => array(
				'color'      => array(
					'text'       => $text_color,
					'background' => self::safe_color( $ctx ),
				),
				'dimensions' => array(
					'minHeight' => $spacing,
				),
			),
		);
	}

	private static function theme_json_unsafe(
		\ComponentFuzz\FuzzContext $ctx,
		string $slug,
		string $unicode_slug,
		string $variation_color,
		string $spacing
	): array {
		return array(
			'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
			'settings' => array(
				'appearanceTools' => true,
				'custom'          => array(
					'fuzz' => array(
						'token' => $spacing,
					),
				),
				'color'           => array(
					'palette'   => array(
						array(
							'name'  => 'Fuzz Accent',
							'slug'  => $slug,
							'color' => $variation_color,
						),
						array(
							'name'  => 'Bad Script',
							'slug'  => 'bad-' . $slug,
							'color' => "url('javascript:alert(1)')",
						),
						array(
							'name'  => 'Unicode Slug',
							'slug'  => $unicode_slug,
							'color' => self::safe_color( $ctx ),
						),
					),
					'gradients' => array(
						array(
							'name'     => 'Fuzz Gradient',
							'slug'     => $slug,
							'gradient' => 'linear-gradient(90deg, #112233, #ddeeff)',
						),
					),
				),
				'spacing'         => array(
					'blockGap'     => true,
					'units'        => array( 'px', 'rem', '%', 'vh' ),
					'spacingSizes' => array(
						array(
							'name' => 'Fuzz Space',
							'slug' => $slug,
							'size' => $spacing,
						),
					),
				),
				'typography'      => array(
					'fontFamilies' => array(
						array(
							'name'       => 'Fuzz Sans',
							'slug'       => $slug,
							'fontFamily' => 'Inter, Arial, sans-serif',
						),
					),
				),
				'border'          => array(
					'radiusSizes' => array(
						array(
							'name' => 'Fuzz Radius',
							'slug' => $slug,
							'size' => $spacing,
						),
					),
				),
				'dimensions'      => array(
					'aspectRatios'   => array(
						array(
							'name'  => 'Fuzz Square',
							'slug'  => $slug,
							'ratio' => '1',
						),
					),
					'dimensionSizes' => array(
						array(
							'name' => 'Fuzz Width',
							'slug' => $slug,
							'size' => $spacing,
						),
					),
				),
			),
			'styles'   => array(
				'color'      => array(
					'text'       => $variation_color,
					'background' => "<script>alert(1)</script>\x02",
				),
				'spacing'    => array(
					'padding'  => self::box_values( $ctx ),
					'blockGap' => $spacing,
				),
				'typography' => array(
					'fontFamily' => 'var:preset|font-family|' . $slug,
					'lineHeight' => '1.45',
				),
				'border'     => array(
					'color'  => $variation_color,
					'radius' => $spacing,
					'style'  => 'solid',
					'width'  => '1px',
				),
				'dimensions' => array(
					'minHeight'   => $spacing,
					'aspectRatio' => '1 / 1',
				),
				'elements'   => array(
					'link'   => array(
						'color'  => array(
							'text' => $variation_color,
						),
							':hover' => array(
								'color' => array(
									'text' => self::simple_safe_color( $ctx ),
								),
							),
						),
					'button' => array(
						'color'  => array(
							'text' => $variation_color,
						),
							':focus' => array(
								'border' => array(
									'color' => self::simple_safe_color( $ctx ),
								),
							),
						),
				),
				'blocks'     => array(
					'core/paragraph' => array(
						'color'      => array(
							'text' => $variation_color,
							),
							'typography' => array(
								'textIndent' => $spacing,
								'lineHeight' => '1.4',
							),
						'elements'   => array(
							'link' => array(
									':hover' => array(
										'color' => array(
											'text' => self::simple_safe_color( $ctx ),
										),
									),
								),
						),
						'variations' => array(
							'fuzz-tone' => array(
									'color'      => array(
										'text'       => $variation_color,
										'background' => self::simple_safe_color( $ctx ),
								),
								'typography' => array(
									'lineHeight' => '1.3',
								),
							),
						),
					),
					'core/button'    => array(
							'color'  => array(
								'text'       => $variation_color,
								'background' => self::simple_safe_color( $ctx ),
							),
							':hover' => array(
								'color' => array(
									'background' => self::simple_safe_color( $ctx ),
								),
							),
						'tablet' => array(
								':hover' => array(
									'border' => array(
										'color' => self::simple_safe_color( $ctx ),
									),
								),
						),
					),
					'fuzz/box'       => array(
						'spacing'    => array(
							'padding' => self::box_values( $ctx ),
						),
						'dimensions' => array(
							'width' => $spacing,
						),
					),
				),
				'css'        => 'color: <script>alert(1)</script>;',
			),
		);
	}

	private static function registered_block_style_variation_case( \ComponentFuzz\FuzzContext $ctx, string $spacing ): array {
		$registry_text_color   = self::hex_color( $ctx );
		$top_text_color        = self::hex_color( $ctx, array( $registry_text_color ) );
		$block_text_color      = self::hex_color( $ctx, array( $registry_text_color, $top_text_color ) );
		$top_background_color  = self::hex_color( $ctx, array( $registry_text_color, $top_text_color, $block_text_color ) );
		$registry_border_color = self::hex_color( $ctx, array( $registry_text_color, $top_text_color, $block_text_color, $top_background_color ) );
		$top_padding_top       = self::spacing_value( $ctx, false );
		$block_padding_right   = self::spacing_value( $ctx, false );

		return array(
			'name'                => self::safe_slug( $ctx, 'registry-style' ),
			'registryTextColor'   => $registry_text_color,
			'topTextColor'        => $top_text_color,
			'blockTextColor'      => $block_text_color,
			'topBackgroundColor'  => $top_background_color,
			'registryBorderColor' => $registry_border_color,
			'topPaddingTop'       => $top_padding_top,
			'blockPaddingRight'   => $block_padding_right,
			'registryData'        => array(
				'color'      => array(
					'text'       => $registry_text_color,
					'background' => self::hex_color( $ctx ),
				),
				'spacing'    => array(
					'padding' => array(
						'top'    => $spacing,
						'right'  => $spacing,
						'bottom' => $spacing,
					),
				),
				'border'     => array(
					'color' => $registry_border_color,
					'style' => 'solid',
					'width' => '1px',
				),
				'background' => array(
					'backgroundImage' => "url('javascript:alert(1)')",
				),
			),
			'topLevelData'       => array(
				'color'      => array(
					'text'       => $top_text_color,
					'background' => $top_background_color,
				),
				'spacing'    => array(
					'padding' => array(
						'top' => $top_padding_top,
					),
				),
				'typography' => array(
					'lineHeight' => '1.35',
				),
			),
			'blockLevelData'     => array(
				'color'      => array(
					'text' => $block_text_color,
				),
				'spacing'    => array(
					'padding' => array(
						'right' => $block_padding_right,
					),
				),
				'typography' => array(
					'fontWeight' => '700',
				),
			),
		);
	}

	private static function prepare_block_registry(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = array(
			'core/paragraph' => array(
				'title'    => 'Paragraph',
				'supports' => self::block_supports( true ),
				'styles'   => array(
					array(
						'name'  => 'fuzz-tone',
						'label' => 'Fuzz Tone',
					),
				),
			),
			'core/button'    => array(
				'title'    => 'Button',
				'supports' => self::block_supports( true ),
			),
			'fuzz/box'       => array(
				'title'     => 'Fuzz Box',
				'supports'  => self::block_supports( false ),
				'selectors' => array(
					'root'       => '.wp-block-fuzz-box',
					'spacing'    => array(
						'root' => '.wp-block-fuzz-box__inner',
					),
					'dimensions' => array(
						'root' => '.wp-block-fuzz-box',
					),
				),
			),
		);

		foreach ( $blocks as $name => $args ) {
			if ( ! $registry->is_registered( $name ) ) {
				\register_block_type( $name, $args );
			}
		}

		\register_block_style(
			'core/paragraph',
			array(
				'name'       => 'fuzz-tone',
				'label'      => 'Fuzz Tone',
				'style_data' => array(
					'color' => array(
						'text' => '#334455',
					),
				),
			)
		);
	}

	private static function block_supports( bool $with_layout ): array {
		$supports = array(
			'color'                => array(
				'text'       => true,
				'background' => true,
				'gradients'  => true,
				'link'       => true,
			),
			'spacing'              => array(
				'margin'   => true,
				'padding'  => true,
				'blockGap' => true,
			),
			'typography'           => array(
				'fontSize'                     => true,
				'lineHeight'                   => true,
				'textColumns'                  => true,
				'textIndent'                   => true,
				'__experimentalFontFamily'     => true,
				'__experimentalFontStyle'      => true,
				'__experimentalFontWeight'     => true,
				'__experimentalLetterSpacing'  => true,
				'__experimentalTextDecoration' => true,
				'__experimentalTextTransform'  => true,
				'__experimentalWritingMode'    => true,
			),
			'__experimentalBorder' => array(
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			),
			'dimensions'           => array(
				'aspectRatio' => true,
				'height'      => true,
				'minHeight'   => true,
				'width'       => true,
			),
		);

		if ( $with_layout ) {
			$supports['layout'] = true;
		}

		return $supports;
	}

	private static function block_support_wrapper_supports(): array {
		return array(
			'color'                => array(
				'text'       => true,
				'background' => true,
				'gradients'  => true,
			),
			'spacing'              => array(
				'padding' => true,
				'margin'  => true,
			),
			'__experimentalBorder' => array(
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			),
			'typography'           => array(
				'fontSize'                     => true,
				'lineHeight'                   => true,
				'textAlign'                    => true,
				'textColumns'                  => true,
				'textIndent'                   => true,
				'__experimentalFontFamily'     => true,
				'__experimentalFontStyle'      => true,
				'__experimentalFontWeight'     => true,
				'__experimentalLetterSpacing'  => true,
				'__experimentalTextDecoration' => true,
				'__experimentalTextTransform'  => true,
				'__experimentalWritingMode'    => true,
			),
			'dimensions'           => array(
				'height'    => true,
				'minHeight' => true,
				'width'     => true,
			),
			'shadow'               => true,
			'align'                => array( 'left', 'center', 'right', 'wide', 'full' ),
		);
	}

	private static function block_support_wrapper_skip_supports(): array {
		$supports = self::block_support_wrapper_supports();

		$supports['color']['__experimentalSkipSerialization']                = array( 'text' );
		$supports['spacing']['__experimentalSkipSerialization']              = array( 'padding' );
		$supports['__experimentalBorder']['__experimentalSkipSerialization'] = array( 'color' );
		$supports['typography']['__experimentalSkipSerialization']           = array( 'fontSize' );
		$supports['dimensions']['__experimentalSkipSerialization']           = array( 'width' );
		$supports['shadow']                                                  = array(
			'__experimentalSkipSerialization' => true,
		);

		return $supports;
	}

	private static function block_support_wrapper_attribute_schema(): array {
		return array(
			'style'           => array(
				'type' => 'object',
			),
			'textColor'       => array(
				'type' => 'string',
			),
			'backgroundColor' => array(
				'type' => 'string',
			),
			'gradient'        => array(
				'type' => 'string',
			),
			'borderColor'     => array(
				'type' => 'string',
			),
			'fontSize'        => array(
				'type' => 'string',
			),
			'fontFamily'      => array(
				'type' => 'string',
			),
			'align'           => array(
				'type' => 'string',
				'enum' => array( 'left', 'center', 'right', 'wide', 'full', '' ),
			),
		);
	}

	private static function block_support_wrapper_attrs( string $slug ): array {
		$hostile_declaration = "1px;background:url('javascript:alert(1)')";
		$hostile_script      = "<script>alert(1)</script>\x02";

		return array(
			'textColor'       => $slug,
			'backgroundColor' => $slug,
			'gradient'        => $slug,
			'borderColor'     => $slug,
			'fontSize'        => $slug,
			'fontFamily'      => $slug,
			'align'           => 'wide',
			'style'           => array(
				'color'      => array(
					'text'       => $hostile_script,
					'background' => "url('javascript:alert(1)')",
					'gradient'   => 'linear-gradient(90deg, #111111, #eeeeee)',
				),
				'spacing'    => array(
					'padding'  => array(
						'top'        => '1px',
						'right'      => 'var:preset|spacing|' . $slug,
						'unsupported' => $hostile_declaration,
					),
					'margin'   => array(
						'bottom'     => '2rem',
						'unsupported' => $hostile_script,
					),
					'blockGap' => $hostile_declaration,
				),
				'border'     => array(
					'color'  => "url('javascript:alert(1)')",
					'radius' => '3',
					'style'  => 'solid',
					'width'  => '2',
					'top'    => array(
						'color' => 'var:preset|color|' . $slug,
						'style' => 'dashed',
						'width' => '4px',
					),
					'unsafe' => $hostile_script,
				),
				'typography' => array(
					'fontSize'       => $hostile_script,
					'fontFamily'     => 'var:preset|font-family|' . $slug,
					'fontStyle'      => 'italic',
					'fontWeight'     => '700',
					'letterSpacing'  => '0.1em',
					'lineHeight'     => '1.5',
					'textAlign'      => 'center',
					'textColumns'    => '2',
					'textDecoration' => 'underline',
					'textIndent'     => '1em',
					'textTransform'  => 'uppercase',
					'writingMode'    => 'vertical-rl',
					'unsafe'         => $hostile_declaration,
				),
				'dimensions' => array(
					'aspectRatio' => $hostile_declaration,
					'height'      => '20px',
					'minHeight'   => '10px',
					'width'       => '30px',
				),
				'shadow'     => '1px 1px 1px #000',
				'unknown'    => array(
					'unsafe' => $hostile_script,
				),
			),
		);
	}

	private static function block_support_wrapper_custom_attrs( string $slug ): array {
		return array(
			'align' => 'wide',
			'style' => array(
				'color'      => array(
					'text'       => '#123456',
					'background' => "url('javascript:alert(1)')",
					'gradient'   => 'linear-gradient(135deg, #111111, #eeeeee)',
				),
				'spacing'    => array(
					'padding' => array(
						'left' => '4px',
					),
					'margin'  => array(
						'top' => '1rem',
					),
				),
				'border'     => array(
					'color'  => '#654321',
					'radius' => '7px',
					'style'  => 'solid',
					'width'  => '1px',
					'bottom' => array(
						'color' => "url('javascript:alert(1)')",
						'style' => 'dotted',
						'width' => '3px',
					),
				),
				'typography' => array(
					'fontSize'      => '18px',
					'fontFamily'    => 'serif',
					'fontStyle'     => 'normal',
					'fontWeight'    => '600',
					'letterSpacing' => "1px;background:url('javascript:alert(1)')",
					'lineHeight'    => '1.25',
					'textAlign'     => 'right',
				),
				'dimensions' => array(
					'height'    => '40px',
					'minHeight' => '20px',
					'width'     => '60px',
				),
				'shadow'     => '2px 2px 4px rgba(0,0,0,.25)',
				'unknown'    => array(
					'unsafe' => '<script>alert(1)</script>',
				),
			),
			'componentFuzzSlug' => $slug,
		);
	}

	private static function block_support_wrapper_extra_attrs( string $slug ): array {
		return array(
			'class'      => 'component-fuzz-extra has-' . $slug . '-font-size component-fuzz-extra',
			'style'      => "margin-left:5px;background-image:url('javascript:alert(1)')",
			'id'         => 'component-fuzz-wrapper-' . $slug . '"quoted',
			'aria-label' => 'Component fuzz wrapper ' . $slug . ' "label"',
			'data-fuzz'  => 'wrapper-' . $slug,
			'inert'      => true,
		);
	}

	private static function block_support_wrapper_register_outputs( array $supports ): array {
		$callbacks = array(
			'wp_register_colors_support',
			'wp_register_spacing_support',
			'wp_register_border_support',
			'wp_register_typography_support',
			'wp_register_dimensions_support',
			'wp_register_shadow_support',
			'wp_register_alignment_support',
		);
		$preserve_callbacks = array(
			'wp_register_colors_support',
			'wp_register_spacing_support',
			'wp_register_border_support',
			'wp_register_typography_support',
			'wp_register_dimensions_support',
			'wp_register_alignment_support',
		);
		$preexisting_style  = array(
			'type'    => 'object',
			'default' => array(
				'componentFuzz' => 'preserve',
			),
		);

		$supported   = new \WP_Block_Type(
			'component-fuzz/style-wrapper-register-supported',
			array(
				'supports'   => $supports,
				'attributes' => array(),
			)
		);
		$unsupported = new \WP_Block_Type(
			'component-fuzz/style-wrapper-register-unsupported',
			array(
				'supports'   => array(),
				'attributes' => array(),
			)
		);
		$preexisting = new \WP_Block_Type(
			'component-fuzz/style-wrapper-register-preserve',
			array(
				'supports'   => $supports,
				'attributes' => array(
					'style' => $preexisting_style,
				),
			)
		);

		foreach ( $callbacks as $callback ) {
			$callback( $supported );
			$callback( $unsupported );
		}

		foreach ( $preserve_callbacks as $callback ) {
			$callback( $preexisting );
		}

		return array(
			'supportedAttributes'   => $supported->attributes,
			'unsupportedAttributes' => $unsupported->attributes,
			'preexistingAttributes' => $preexisting->attributes,
			'preexistingStyle'      => $preexisting_style,
		);
	}

	private static function block_support_wrapper_apply_outputs( \WP_Block_Type $block_type, array $attributes ): array {
		return array(
			'colors'     => \wp_apply_colors_support( $block_type, $attributes ),
			'spacing'    => \wp_apply_spacing_support( $block_type, $attributes ),
			'border'     => \wp_apply_border_support( $block_type, $attributes ),
			'typography' => \wp_apply_typography_support( $block_type, $attributes ),
			'dimensions' => \wp_apply_dimensions_support( $block_type, $attributes ),
			'shadow'     => \wp_apply_shadow_support( $block_type, $attributes ),
			'alignment'  => \wp_apply_alignment_support( $block_type, $attributes ),
		);
	}

	private static function block_support_wrapper_skip_queries( \WP_Block_Type $block_type ): array {
		return array(
			'colorText'        => \wp_should_skip_block_supports_serialization( $block_type, 'color', 'text' ),
			'colorBackground'  => \wp_should_skip_block_supports_serialization( $block_type, 'color', 'background' ),
			'spacingPadding'   => \wp_should_skip_block_supports_serialization( $block_type, 'spacing', 'padding' ),
			'spacingMargin'    => \wp_should_skip_block_supports_serialization( $block_type, 'spacing', 'margin' ),
			'borderColor'      => \wp_should_skip_block_supports_serialization( $block_type, '__experimentalBorder', 'color' ),
			'borderWidth'      => \wp_should_skip_block_supports_serialization( $block_type, '__experimentalBorder', 'width' ),
			'typographySize'   => \wp_should_skip_block_supports_serialization( $block_type, 'typography', 'fontSize' ),
			'typographyFamily' => \wp_should_skip_block_supports_serialization( $block_type, 'typography', 'fontFamily' ),
			'dimensionsWidth'  => \wp_should_skip_block_supports_serialization( $block_type, 'dimensions', 'width' ),
			'dimensionsHeight' => \wp_should_skip_block_supports_serialization( $block_type, 'dimensions', 'height' ),
			'shadowAll'        => \wp_should_skip_block_supports_serialization( $block_type, 'shadow' ),
			'alignAll'         => \wp_should_skip_block_supports_serialization( $block_type, 'align' ),
		);
	}

	private static function block_support_wrapper_serialized_output( string $block_name, array $attributes, array $extra_attributes ): array {
		\WP_Block_Supports::$block_to_render = array(
			'blockName' => $block_name,
			'attrs'     => $attributes,
		);

		$serialized = \get_block_wrapper_attributes( $extra_attributes );
		$html       = '<div ' . $serialized . '></div>';
		$processor  = new \WP_HTML_Tag_Processor( $html );
		$parsed     = null;

		if ( $processor->next_tag( 'div' ) ) {
			$parsed = array();
			foreach ( array( 'class', 'style', 'id', 'aria-label', 'data-fuzz', 'inert' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( null !== $value ) {
					$parsed[ $attribute ] = $value;
				}
			}
		}

		return array(
			'serialized' => $serialized,
			'parsed'     => $parsed,
		);
	}

	private static function block_support_wrapper_registers_ok( array $result ): bool {
		$supported   = is_array( $result['supportedAttributes'] ?? null ) ? $result['supportedAttributes'] : array();
		$unsupported = is_array( $result['unsupportedAttributes'] ?? null ) ? $result['unsupportedAttributes'] : array();
		$preexisting = is_array( $result['preexistingAttributes'] ?? null ) ? $result['preexistingAttributes'] : array();

		$added_attributes = array( 'style', 'textColor', 'backgroundColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'align' );
		$preset_attributes = array( 'textColor', 'backgroundColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'align' );

		return array() === array_diff( $added_attributes, array_keys( $supported ) )
			&& array() === array_intersect( $added_attributes, array_keys( $unsupported ) )
			&& array() === array_diff( $preset_attributes, array_keys( $preexisting ) )
			&& ( $result['preexistingStyle'] ?? null ) === ( $preexisting['style'] ?? null );
	}

	private static function block_support_wrapper_direct_outputs_ok( array $outputs, string $slug ): bool {
		$colors     = is_array( $outputs['colors'] ?? null ) ? $outputs['colors'] : array();
		$spacing    = is_array( $outputs['spacing'] ?? null ) ? $outputs['spacing'] : array();
		$border     = is_array( $outputs['border'] ?? null ) ? $outputs['border'] : array();
		$typography = is_array( $outputs['typography'] ?? null ) ? $outputs['typography'] : array();
		$dimensions = is_array( $outputs['dimensions'] ?? null ) ? $outputs['dimensions'] : array();
		$shadow     = is_array( $outputs['shadow'] ?? null ) ? $outputs['shadow'] : array();
		$alignment  = is_array( $outputs['alignment'] ?? null ) ? $outputs['alignment'] : array();

		$spacing_style    = (string) ( $spacing['style'] ?? '' );
		$border_style     = (string) ( $border['style'] ?? '' );
		$typography_style = (string) ( $typography['style'] ?? '' );
		$dimensions_style = (string) ( $dimensions['style'] ?? '' );
		$shadow_style     = (string) ( $shadow['style'] ?? '' );

		return self::block_support_wrapper_outputs_safe( $outputs )
			&& self::class_tokens_include(
				(string) ( $colors['class'] ?? '' ),
				array(
					'has-text-color',
					'has-' . $slug . '-color',
					'has-background',
					'has-' . $slug . '-background-color',
					'has-' . $slug . '-gradient-background',
				)
			)
			&& ! array_key_exists( 'style', $colors )
			&& self::css_contains_declaration( $spacing_style, 'padding-top', '1px' )
			&& self::css_contains_declaration( $spacing_style, 'padding-right', 'var(--wp--preset--spacing--' . $slug . ')' )
			&& self::css_contains_declaration( $spacing_style, 'margin-bottom', '2rem' )
			&& self::class_tokens_include(
				(string) ( $border['class'] ?? '' ),
				array(
					'has-border-color',
					'has-' . $slug . '-border-color',
				)
			)
			&& self::css_contains_declaration( $border_style, 'border-radius', '3px' )
			&& self::css_contains_declaration( $border_style, 'border-style', 'solid' )
			&& self::css_contains_declaration( $border_style, 'border-width', '2px' )
			&& self::css_contains_declaration( $border_style, 'border-top-width', '4px' )
			&& self::css_contains_declaration( $border_style, 'border-top-color', 'var(--wp--preset--color--' . $slug . ')' )
			&& self::css_contains_declaration( $border_style, 'border-top-style', 'dashed' )
			&& self::class_tokens_include(
				(string) ( $typography['class'] ?? '' ),
				array(
					'has-' . $slug . '-font-size',
					'has-' . $slug . '-font-family',
					'has-text-align-center',
				)
			)
			&& self::css_contains_declaration( $typography_style, 'font-style', 'italic' )
			&& self::css_contains_declaration( $typography_style, 'font-weight', '700' )
			&& self::css_contains_declaration( $typography_style, 'line-height', '1.5' )
			&& self::css_contains_declaration( $typography_style, 'column-count', '2' )
			&& self::css_contains_declaration( $typography_style, 'text-decoration', 'underline' )
			&& self::css_contains_declaration( $typography_style, 'text-indent', '1em' )
			&& self::css_contains_declaration( $typography_style, 'text-transform', 'uppercase' )
			&& self::css_contains_declaration( $typography_style, 'letter-spacing', '0.1em' )
			&& self::css_contains_declaration( $typography_style, 'writing-mode', 'vertical-rl' )
			&& self::css_contains_declaration( $dimensions_style, 'height', '20px' )
			&& self::css_contains_declaration( $dimensions_style, 'min-height', '10px' )
			&& self::css_contains_declaration( $dimensions_style, 'width', '30px' )
			&& self::css_contains_declaration( $shadow_style, 'box-shadow', '1px 1px 1px #000' )
			&& self::class_tokens_include( (string) ( $alignment['class'] ?? '' ), array( 'alignwide' ) );
	}

	private static function block_support_wrapper_skip_outputs_ok( array $outputs, string $slug ): bool {
		$colors     = is_array( $outputs['colors'] ?? null ) ? $outputs['colors'] : array();
		$spacing    = is_array( $outputs['spacing'] ?? null ) ? $outputs['spacing'] : array();
		$border     = is_array( $outputs['border'] ?? null ) ? $outputs['border'] : array();
		$typography = is_array( $outputs['typography'] ?? null ) ? $outputs['typography'] : array();
		$dimensions = is_array( $outputs['dimensions'] ?? null ) ? $outputs['dimensions'] : array();
		$shadow     = is_array( $outputs['shadow'] ?? null ) ? $outputs['shadow'] : array();
		$alignment  = is_array( $outputs['alignment'] ?? null ) ? $outputs['alignment'] : array();

		$color_tokens      = self::class_tokens( (string) ( $colors['class'] ?? '' ) );
		$typography_tokens = self::class_tokens( (string) ( $typography['class'] ?? '' ) );
		$spacing_style     = (string) ( $spacing['style'] ?? '' );
		$border_style      = (string) ( $border['style'] ?? '' );
		$dimensions_style  = (string) ( $dimensions['style'] ?? '' );

		return self::block_support_wrapper_outputs_safe( $outputs )
			&& ! in_array( 'has-text-color', $color_tokens, true )
			&& ! in_array( 'has-' . $slug . '-color', $color_tokens, true )
			&& self::class_tokens_include(
				(string) ( $colors['class'] ?? '' ),
				array(
					'has-background',
					'has-' . $slug . '-background-color',
					'has-' . $slug . '-gradient-background',
				)
			)
			&& ! self::css_contains_declaration( $spacing_style, 'padding-top', '1px' )
			&& ! self::css_contains_declaration( $spacing_style, 'padding-right', 'var(--wp--preset--spacing--' . $slug . ')' )
			&& self::css_contains_declaration( $spacing_style, 'margin-bottom', '2rem' )
			&& ! array_key_exists( 'class', $border )
			&& ! self::css_contains_declaration( $border_style, 'border-top-color', 'var(--wp--preset--color--' . $slug . ')' )
			&& self::css_contains_declaration( $border_style, 'border-radius', '3px' )
			&& self::css_contains_declaration( $border_style, 'border-style', 'solid' )
			&& self::css_contains_declaration( $border_style, 'border-width', '2px' )
			&& self::css_contains_declaration( $border_style, 'border-top-width', '4px' )
			&& self::css_contains_declaration( $border_style, 'border-top-style', 'dashed' )
			&& ! in_array( 'has-' . $slug . '-font-size', $typography_tokens, true )
			&& self::class_tokens_include(
				(string) ( $typography['class'] ?? '' ),
				array(
					'has-' . $slug . '-font-family',
					'has-text-align-center',
				)
			)
			&& ! self::css_contains_declaration( $dimensions_style, 'width', '30px' )
			&& self::css_contains_declaration( $dimensions_style, 'height', '20px' )
			&& self::css_contains_declaration( $dimensions_style, 'min-height', '10px' )
			&& array() === $shadow
			&& self::class_tokens_include( (string) ( $alignment['class'] ?? '' ), array( 'alignwide' ) );
	}

	private static function block_support_wrapper_skip_queries_ok( array $queries ): bool {
		$expected = array(
			'colorText'        => true,
			'colorBackground'  => false,
			'spacingPadding'   => true,
			'spacingMargin'    => false,
			'borderColor'      => true,
			'borderWidth'      => false,
			'typographySize'   => true,
			'typographyFamily' => false,
			'dimensionsWidth'  => true,
			'dimensionsHeight' => false,
			'shadowAll'        => true,
			'alignAll'         => false,
		);

		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $queries ) || $queries[ $key ] !== $value ) {
				return false;
			}
		}

		return true;
	}

	private static function block_support_wrapper_aggregate_output_ok( array $attributes, string $slug ): bool {
		$class = (string) ( $attributes['class'] ?? '' );
		$style = (string) ( $attributes['style'] ?? '' );

		return self::block_support_wrapper_attributes_safe( $attributes )
			&& self::class_tokens_include(
				$class,
				array(
					'has-' . $slug . '-font-size',
					'has-' . $slug . '-font-family',
					'has-text-align-center',
					'has-text-color',
					'has-' . $slug . '-color',
					'has-background',
					'has-' . $slug . '-background-color',
					'has-' . $slug . '-gradient-background',
					'has-border-color',
					'has-' . $slug . '-border-color',
					'alignwide',
				)
			)
			&& self::css_contains_declaration( $style, 'font-style', 'italic' )
			&& self::css_contains_declaration( $style, 'line-height', '1.5' )
			&& self::css_contains_declaration( $style, 'padding-top', '1px' )
			&& self::css_contains_declaration( $style, 'padding-right', 'var(--wp--preset--spacing--' . $slug . ')' )
			&& self::css_contains_declaration( $style, 'margin-bottom', '2rem' )
			&& self::css_contains_declaration( $style, 'border-radius', '3px' )
			&& self::css_contains_declaration( $style, 'border-top-color', 'var(--wp--preset--color--' . $slug . ')' )
			&& self::css_contains_declaration( $style, 'height', '20px' )
			&& self::css_contains_declaration( $style, 'min-height', '10px' )
			&& self::css_contains_declaration( $style, 'width', '30px' )
			&& self::css_contains_declaration( $style, 'box-shadow', '1px 1px 1px #000' );
	}

	private static function block_support_wrapper_custom_outputs_ok( array $outputs ): bool {
		$colors     = is_array( $outputs['colors'] ?? null ) ? $outputs['colors'] : array();
		$spacing    = is_array( $outputs['spacing'] ?? null ) ? $outputs['spacing'] : array();
		$border     = is_array( $outputs['border'] ?? null ) ? $outputs['border'] : array();
		$typography = is_array( $outputs['typography'] ?? null ) ? $outputs['typography'] : array();
		$dimensions = is_array( $outputs['dimensions'] ?? null ) ? $outputs['dimensions'] : array();
		$alignment  = is_array( $outputs['alignment'] ?? null ) ? $outputs['alignment'] : array();

		$colors_style     = self::css_for_matching( (string) ( $colors['style'] ?? '' ) );
		$spacing_style    = self::css_for_matching( (string) ( $spacing['style'] ?? '' ) );
		$border_style     = self::css_for_matching( (string) ( $border['style'] ?? '' ) );
		$typography_style = self::css_for_matching( (string) ( $typography['style'] ?? '' ) );
		$dimensions_style = self::css_for_matching( (string) ( $dimensions['style'] ?? '' ) );

		return self::block_support_wrapper_outputs_safe( $outputs )
			&& self::css_contains_declaration( $colors_style, 'color', '#123456' )
			&& self::css_contains_declaration( $spacing_style, 'padding-left', '4px' )
			&& self::css_contains_declaration( $spacing_style, 'margin-top', '1rem' )
			&& self::css_contains_declaration( $border_style, 'border-color', '#654321' )
			&& self::css_contains_declaration( $border_style, 'border-radius', '7px' )
			&& self::css_contains_declaration( $border_style, 'border-style', 'solid' )
			&& self::css_contains_declaration( $border_style, 'border-width', '1px' )
			&& self::css_contains_declaration( $border_style, 'border-bottom-style', 'dotted' )
			&& self::css_contains_declaration( $border_style, 'border-bottom-width', '3px' )
			&& self::css_contains_declaration( $typography_style, 'font-size', '18px' )
			&& self::css_contains_declaration( $typography_style, 'font-family', 'serif' )
			&& self::css_contains_declaration( $typography_style, 'font-style', 'normal' )
			&& self::css_contains_declaration( $typography_style, 'font-weight', '600' )
			&& self::css_contains_declaration( $typography_style, 'line-height', '1.25' )
			&& self::class_tokens_include( (string) ( $typography['class'] ?? '' ), array( 'has-text-align-right' ) )
			&& self::css_contains_declaration( $dimensions_style, 'height', '40px' )
			&& self::css_contains_declaration( $dimensions_style, 'min-height', '20px' )
			&& self::css_contains_declaration( $dimensions_style, 'width', '60px' )
			&& self::class_tokens_include( (string) ( $alignment['class'] ?? '' ), array( 'alignwide' ) )
			&& ! self::css_contains_declaration( $colors_style, 'background', "url('javascript:alert(1)')" )
			&& ! self::css_contains_declaration( $border_style, 'border-bottom-color', "url('javascript:alert(1)')" )
			&& ! self::css_contains_declaration( $typography_style, 'letter-spacing', "1px;background:url('javascript:alert(1)')" );
	}

	private static function block_support_wrapper_custom_aggregate_output_ok( array $attributes ): bool {
		$class = (string) ( $attributes['class'] ?? '' );
		$style = self::css_for_matching( (string) ( $attributes['style'] ?? '' ) );

		return self::block_support_wrapper_attributes_safe( $attributes )
			&& self::class_tokens_include(
				$class,
				array(
					'has-text-align-right',
					'alignwide',
				)
			)
			&& self::css_contains_declaration( $style, 'color', '#123456' )
			&& self::css_contains_declaration( $style, 'padding-left', '4px' )
			&& self::css_contains_declaration( $style, 'margin-top', '1rem' )
			&& self::css_contains_declaration( $style, 'border-color', '#654321' )
			&& self::css_contains_declaration( $style, 'font-size', '18px' )
			&& self::css_contains_declaration( $style, 'height', '40px' )
			&& ! self::css_contains_declaration( $style, 'background', "url('javascript:alert(1)')" )
			&& ! self::css_contains_declaration( $style, 'border-bottom-color', "url('javascript:alert(1)')" )
			&& ! self::css_contains_declaration( $style, 'letter-spacing', "1px;background:url('javascript:alert(1)')" );
	}

	private static function block_support_wrapper_serialized_output_ok( array $result, string $slug, array $extra_attributes ): bool {
		$serialized = (string) ( $result['serialized'] ?? '' );
		$parsed     = is_array( $result['parsed'] ?? null ) ? $result['parsed'] : array();
		$class      = (string) ( $parsed['class'] ?? '' );
		$style      = self::css_for_matching( (string) ( $parsed['style'] ?? '' ) );

		return '' !== $serialized
			&& ! str_contains( strtolower( $serialized ), 'javascript:' )
			&& ! self::contains_raw_unsafe_bytes( $serialized )
			&& ! str_contains( $serialized, '<script' )
			&& self::class_tokens_include(
				$class,
				array(
					'component-fuzz-extra',
					'has-' . $slug . '-font-size',
					'has-' . $slug . '-font-family',
					'has-text-align-center',
					'has-text-color',
					'has-' . $slug . '-color',
					'has-background',
					'has-' . $slug . '-background-color',
					'has-' . $slug . '-gradient-background',
					'has-border-color',
					'has-' . $slug . '-border-color',
					'alignwide',
				)
			)
			&& 1 === self::class_token_count( $class, 'component-fuzz-extra' )
			&& 1 === self::class_token_count( $class, 'has-' . $slug . '-font-size' )
			&& self::css_contains_declaration( $style, 'margin-left', '5px' )
			&& self::css_contains_declaration( $style, 'padding-top', '1px' )
			&& self::css_contains_declaration( $style, 'border-top-color', 'var(--wp--preset--color--' . $slug . ')' )
			&& self::css_contains_declaration( $style, 'box-shadow', '1px 1px 1px #000' )
			&& ! str_contains( strtolower( $style ), 'javascript:' )
			&& self::declaration_block_ok( $style )
			&& ( $extra_attributes['id'] ?? null ) === ( $parsed['id'] ?? null )
			&& ( $extra_attributes['aria-label'] ?? null ) === ( $parsed['aria-label'] ?? null )
			&& ( $extra_attributes['data-fuzz'] ?? null ) === ( $parsed['data-fuzz'] ?? null )
			&& ! array_key_exists( 'inert', $parsed );
	}

	private static function block_support_wrapper_outputs_safe( array $outputs ): bool {
		foreach ( $outputs as $attributes ) {
			if ( ! is_array( $attributes ) || ! self::block_support_wrapper_attributes_safe( $attributes ) ) {
				return false;
			}
		}

		return true;
	}

	private static function block_support_wrapper_attributes_safe( array $attributes ): bool {
		foreach ( array( 'class', 'style' ) as $attribute_name ) {
			if ( ! array_key_exists( $attribute_name, $attributes ) ) {
				continue;
			}

			$value = $attributes[ $attribute_name ];
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				return false;
			}

			$value = (string) $value;
			if ( str_contains( strtolower( $value ), 'javascript:' ) || self::contains_raw_unsafe_bytes( $value ) ) {
				return false;
			}

			if ( 'style' === $attribute_name && ! self::declaration_block_ok( $value ) ) {
				return false;
			}

			if ( 'class' === $attribute_name ) {
				$tokens = self::class_tokens( $value );
				if ( count( $tokens ) !== count( array_unique( $tokens ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function block_support_wrapper_unsafe_report( array $direct, array $skip, array $aggregate ): array {
		return array(
			'directSafe'    => self::block_support_wrapper_outputs_safe( $direct ),
			'skipSafe'      => self::block_support_wrapper_outputs_safe( $skip ),
			'aggregateSafe' => self::block_support_wrapper_attributes_safe( $aggregate ),
		);
	}

	private static function safe_color( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				'#' . sprintf( '%06x', $ctx->int( 0, 0xffffff ) ),
				'rgb(' . $ctx->int( 0, 255 ) . ', ' . $ctx->int( 0, 255 ) . ', ' . $ctx->int( 0, 255 ) . ')',
				'hsl(' . $ctx->int( 0, 360 ) . 'deg 60% 45%)',
				'currentColor',
			)
		);
	}

	private static function simple_safe_color( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				'#' . sprintf( '%06x', $ctx->int( 0, 0xffffff ) ),
				'currentColor',
			)
		);
	}

	private static function hex_color( \ComponentFuzz\FuzzContext $ctx, array $except = array() ): string {
		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$color = '#' . sprintf( '%06x', $ctx->int( 0, 0xffffff ) );
			if ( ! in_array( $color, $except, true ) ) {
				return $color;
			}
		}

		foreach ( array( '#123456', '#654321', '#0f766e', '#7c2d12', '#4338ca', '#be123c' ) as $fallback ) {
			if ( ! in_array( $fallback, $except, true ) ) {
				return $fallback;
			}
		}

		return '#000000';
	}

	private static function maybe_unsafe_color( \ComponentFuzz\FuzzContext $ctx ): string {
		if ( $ctx->bool( 25 ) ) {
			return $ctx->choice(
				array(
					"'><script>alert(1)</script>",
					"url('javascript:alert(1)')",
					"red\x01",
				)
			);
		}

		return self::safe_color( $ctx );
	}

	private static function spacing_value( \ComponentFuzz\FuzzContext $ctx, bool $allow_unsafe = true ): string {
		$values = array(
			'0',
			$ctx->int( 1, 12 ) . 'px',
			$ctx->int( 1, 8 ) / 2 . 'rem',
			$ctx->int( 5, 95 ) . '%',
			'calc(100% - ' . $ctx->int( 1, 8 ) . 'px)',
			'clamp(1rem, 2vw, 3rem)',
		);

		if ( $allow_unsafe ) {
			$values[] = "1px;background:url('javascript:alert(1)')";
			$values[] = "2rem\x02";
		}

		return (string) $ctx->choice( $values );
	}

	private static function font_size_value( \ComponentFuzz\FuzzContext $ctx, bool $allow_unsafe = true ): string {
		$values = array(
			$ctx->int( 12, 48 ) . 'px',
			$ctx->int( 1, 4 ) . 'rem',
			'clamp(1rem, 1.5vw, 2.4rem)',
		);

		if ( $allow_unsafe ) {
			$values[] = '<script>alert(1)</script>';
		}

		return (string) $ctx->choice( $values );
	}

	private static function box_values( \ComponentFuzz\FuzzContext $ctx ): array {
		$out = array();
		foreach ( self::SIDES as $side ) {
			if ( $ctx->bool( 75 ) ) {
				$out[ $side ] = self::spacing_value( $ctx, false );
			}
		}
		return array() === $out ? array( 'top' => '0' ) : $out;
	}

	private static function safe_slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return $prefix . '-' . strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $ctx->identifier( 3, 10 ) ) ) . '-' . $ctx->int( 1, 999 );
	}

	private static function selector( \ComponentFuzz\FuzzContext $ctx ): string {
		$slug = self::safe_slug( $ctx, 'selector' );
		return $ctx->choice(
			array(
				'.wp-block-' . $slug,
				'.wp-block-' . $slug . ':hover',
				':where(.wp-block-' . $slug . ') > a:focus',
				'.wp-block-' . $slug . '[data-fuzz="' . $ctx->int( 1, 99 ) . '"]',
			)
		);
	}

	private static function context_name( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'component_fuzz_style_' . $label . '_' . $ctx->seed() . '_' . $ctx->iteration();
	}

	private static function declaration_properties_allowed( array $properties ): bool {
		return array() === array_diff( $properties, self::allowed_style_engine_properties() );
	}

	private static function class_tokens( string $classnames ): array {
		if ( '' === trim( $classnames ) ) {
			return array();
		}

		$tokens = preg_split( '/\s+/', trim( $classnames ) );
		return is_array( $tokens ) ? $tokens : array();
	}

	private static function class_tokens_include( string $classnames, array $expected ): bool {
		return array() === array_diff( $expected, self::class_tokens( $classnames ) );
	}

	private static function class_token_count( string $classnames, string $expected ): int {
		return count(
			array_filter(
				self::class_tokens( $classnames ),
				static fn( string $token ): bool => $token === $expected
			)
		);
	}

	private static function expected_declarations_present( array $declarations, array $expected ): bool {
		foreach ( $expected as $property => $value ) {
			if ( ! array_key_exists( $property, $declarations ) || $declarations[ $property ] !== $value ) {
				return false;
			}
		}
		return true;
	}

	private static function allowed_style_engine_properties(): array {
		static $allowed = null;

		if ( null !== $allowed ) {
			return $allowed;
		}

		$properties = array();
		foreach ( \WP_Style_Engine::BLOCK_STYLE_DEFINITIONS_METADATA as $definition_group ) {
			foreach ( $definition_group as $definition ) {
				foreach ( $definition['property_keys'] ?? array() as $kind => $property ) {
					if ( 'individual' === $kind ) {
						foreach ( array_merge( self::SIDES, array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ) ) as $side ) {
							$properties[] = sprintf( $property, $side );
						}
					} else {
						$properties[] = $property;
					}
				}
			}
		}

		$allowed = array_values( array_unique( $properties ) );
		sort( $allowed );
		return $allowed;
	}

	private static function css_structure_ok( string $css ): bool {
		$report = self::css_structure_report( $css );
		return $report['balanced'] && $report['leafBlocksHaveDeclarations'];
	}

	private static function declaration_block_ok( string $css ): bool {
		$css = trim( $css );
		if ( '' === $css ) {
			return true;
		}

		if ( ! str_ends_with( $css, ';' ) ) {
			return false;
		}

		foreach ( array_filter( explode( ';', $css ) ) as $declaration ) {
			if ( ! str_contains( $declaration, ':' ) ) {
				return false;
			}
		}

		return true;
	}

	private static function css_contains_declaration( string $css, string $property, string $value ): bool {
		return preg_match(
			'/(?:^|[;{])\s*' . preg_quote( $property, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '\s*;/',
			$css
		) === 1;
	}

	private static function css_for_matching( string $css ): string {
		$css = trim( $css );
		if ( '' !== $css && ! str_ends_with( $css, ';' ) ) {
			$css .= ';';
		}
		return $css;
	}

	private static function css_structure_report( string $css ): array {
		$stack       = array();
		$balanced    = true;
		$leaf_blocks = array();
		$length      = strlen( $css );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '{' === $char ) {
				if ( ! empty( $stack ) ) {
					$stack[ count( $stack ) - 1 ]['hasChild'] = true;
				}
				$stack[] = array(
					'start'    => $i,
					'hasChild' => false,
				);
				continue;
			}

			if ( '}' === $char ) {
				if ( empty( $stack ) ) {
					$balanced = false;
					break;
				}
				$open = array_pop( $stack );
				if ( ! $open['hasChild'] ) {
					$leaf_blocks[] = substr( $css, $open['start'] + 1, $i - $open['start'] - 1 );
				}
			}
		}

		if ( ! empty( $stack ) ) {
			$balanced = false;
		}

		$leaf_blocks_have_declarations = true;
		foreach ( $leaf_blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			if ( ! str_ends_with( $block, ';' ) ) {
				$leaf_blocks_have_declarations = false;
				break;
			}
			foreach ( array_filter( array_map( 'trim', explode( ';', $block ) ) ) as $declaration ) {
				if ( ! str_contains( $declaration, ':' ) ) {
					$leaf_blocks_have_declarations = false;
					break 2;
				}
			}
		}

		return array(
			'balanced'                   => $balanced,
			'leafBlockCount'             => count( $leaf_blocks ),
			'leafBlocksHaveDeclarations' => $leaf_blocks_have_declarations,
			'length'                     => strlen( $css ),
		);
	}

	private static function contains_raw_unsafe_bytes( string $css ): bool {
		return preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css ) === 1
			|| str_contains( strtolower( $css ), '<script' );
	}

	private static function unsafe_byte_report( string $css ): array {
		preg_match_all( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css, $matches );
		return array(
			'hasUnsafe'    => self::contains_raw_unsafe_bytes( $css ),
			'controlCount' => count( $matches[0] ),
			'hasScript'    => str_contains( strtolower( $css ), '<script' ),
		);
	}

	private static function snapshot_state(): array {
		$block_registry       = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		$block_style_registry = self::get_static_property( 'WP_Block_Styles_Registry', 'instance' );
		$block_supports       = self::get_static_property( 'WP_Block_Supports', 'instance' );

		return array(
			'globals'              => self::snapshot_globals(
				array(
					'wp_filter',
					'wp_actions',
					'wp_filters',
					'wp_current_filter',
					'_wp_theme_features',
				)
			),
			'styleStores'          => self::get_style_stores(),
			'blockRegistry'        => $block_registry,
			'blockTypes'           => $block_registry instanceof \WP_Block_Type_Registry
				? self::get_object_property( $block_registry, 'registered_block_types' )
				: null,
			'blockStyleRegistry'   => $block_style_registry,
			'blockStyles'          => $block_style_registry instanceof \WP_Block_Styles_Registry
				? self::get_object_property( $block_style_registry, 'registered_block_styles' )
				: null,
			'themeJsonBlockMeta'   => self::get_static_property( 'WP_Theme_JSON', 'blocks_metadata' ),
			'blockSupportsApi'     => $block_supports,
			'blockSupports'        => $block_supports instanceof \WP_Block_Supports
				? self::get_object_property( $block_supports, 'block_supports' )
				: null,
			'blockSupportRenderItem' => self::get_static_property( 'WP_Block_Supports', 'block_to_render' ),
			'renderBlockHook'      => self::snapshot_filter_hook( 'render_block' ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		self::set_style_stores( $snapshot['styleStores'] );
		self::set_static_property( 'WP_Theme_JSON', 'blocks_metadata', $snapshot['themeJsonBlockMeta'] );

		if ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) {
			self::set_object_property( $snapshot['blockRegistry'], 'registered_block_types', $snapshot['blockTypes'] );
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', $snapshot['blockRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', null );
		}

		if ( $snapshot['blockStyleRegistry'] instanceof \WP_Block_Styles_Registry ) {
			self::set_object_property( $snapshot['blockStyleRegistry'], 'registered_block_styles', $snapshot['blockStyles'] );
			self::set_static_property( 'WP_Block_Styles_Registry', 'instance', $snapshot['blockStyleRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Styles_Registry', 'instance', null );
		}

		if ( $snapshot['blockSupportsApi'] instanceof \WP_Block_Supports ) {
			self::set_object_property( $snapshot['blockSupportsApi'], 'block_supports', $snapshot['blockSupports'] );
			self::set_static_property( 'WP_Block_Supports', 'instance', $snapshot['blockSupportsApi'] );
		} else {
			self::set_static_property( 'WP_Block_Supports', 'instance', null );
		}
		self::set_static_property( 'WP_Block_Supports', 'block_to_render', $snapshot['blockSupportRenderItem'] );
		self::restore_filter_hook( 'render_block', $snapshot['renderBlockHook'] );
	}

	private static function snapshot_filter_hook( string $hook_name ): array {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook_name ] ) || ! ( $GLOBALS['wp_filter'][ $hook_name ] instanceof \WP_Hook ) ) {
			return array(
				'exists'    => false,
				'callbacks' => array(),
			);
		}

		return array(
			'exists'    => true,
			'callbacks' => $GLOBALS['wp_filter'][ $hook_name ]->callbacks,
		);
	}

	private static function restore_filter_hook( string $hook_name, array $snapshot ): void {
		if ( empty( $snapshot['exists'] ) ) {
			unset( $GLOBALS['wp_filter'][ $hook_name ] );
			return;
		}

		if ( ! class_exists( 'WP_Hook' ) ) {
			return;
		}

		if ( ! isset( $GLOBALS['wp_filter'][ $hook_name ] ) || ! ( $GLOBALS['wp_filter'][ $hook_name ] instanceof \WP_Hook ) ) {
			$GLOBALS['wp_filter'][ $hook_name ] = new \WP_Hook();
		}

		$callbacks = is_array( $snapshot['callbacks'] ?? null ) ? $snapshot['callbacks'] : array();
		self::set_object_property( $GLOBALS['wp_filter'][ $hook_name ], 'callbacks', $callbacks );
		self::set_object_property( $GLOBALS['wp_filter'][ $hook_name ], 'priorities', array_keys( $callbacks ) );
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}
		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( empty( $entry['exists'] ) ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $entry['value'];
			}
		}
	}

	private static function globals_match_snapshot( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			if ( empty( $entry['exists'] ) ) {
				if ( array_key_exists( $name, $GLOBALS ) ) {
					return false;
				}
				continue;
			}

			if ( ! array_key_exists( $name, $GLOBALS ) || $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}
		return true;
	}

	private static function get_style_stores(): array {
		return self::get_static_property( 'WP_Style_Engine_CSS_Rules_Store', 'stores' ) ?? array();
	}

	private static function set_style_stores( array $stores ): void {
		self::set_static_property( 'WP_Style_Engine_CSS_Rules_Store', 'stores', $stores );
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

	private static function inject_registered_block_style_variations( array $data ): array {
		$reflection = new \ReflectionMethod( 'WP_Theme_JSON_Resolver', 'inject_variations_from_block_styles_registry' );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		$injected = $reflection->invoke( null, $data );
		return is_array( $injected ) ? $injected : array();
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
			);
		}
	}

	private static function describe_call( array $call ): array {
		if ( ! empty( $call['threw'] ) ) {
			return array(
				'threw'     => true,
				'throwable' => $call['throwable'] ?? array(),
			);
		}

		return array(
			'threw' => false,
			'value' => self::preview( $call['value'] ?? null ),
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

	private static function preview( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_replace_callback(
				'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
				static function ( array $matches ): string {
					return sprintf( '\\x%02X', ord( $matches[0] ) );
				},
				$value
			);

			return strlen( $value ) > self::PREVIEW_BYTES ? substr( $value, 0, self::PREVIEW_BYTES ) . '...' : $value;
		}

		if ( is_array( $value ) ) {
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
			return false === $json ? '[array]' : self::preview( $json );
		}

		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		return $value;
	}

	private static function array_get( array $array, array $path, $default = null ) {
		foreach ( $path as $segment ) {
			if ( ! is_array( $array ) || ! array_key_exists( $segment, $array ) ) {
				return $default;
			}
			$array = $array[ $segment ];
		}

		return $array;
	}
}
