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
			$rows[] = self::check_block_style_variation_serialization( $ctx, $case );
			$rows[] = self::check_theme_style_helper_filter_restoration( $ctx, $case );
			$rows[] = self::check_style_store_cleanup( $ctx );
			$rows[] = self::check_global_stylesheet_guard( $ctx );
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
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
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
		if ( ! function_exists( 'wp_get_global_stylesheet' ) ) {
			return $ctx->skip( 'style.wp-get-global-stylesheet.no-db-guard', 'wp_get_global_stylesheet() is unavailable.' );
		}

		return $ctx->skip(
			'style.wp-get-global-stylesheet.no-db-guard',
			'Skipped because the global stylesheet resolver can read active theme files and query wp_global_styles user data.',
			array(
				'functionAvailable' => true,
				'wpQueryLoaded'     => class_exists( 'WP_Query' ),
			)
		);
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

		return array(
			'safeSlug'                 => $safe_slug,
			'unicodeSlug'              => $unicode_slug,
			'safeColor'                => self::simple_safe_color( $ctx ),
			'safeSpacing'              => $safe_spacing,
			'safeFontSize'             => $safe_font,
			'selector'                 => $selector,
			'convertVarsToClassnames'  => $ctx->bool(),
			'blockStyles'              => $block_styles,
			'cssRules'                 => $css_rules,
			'mergeSelector'            => $merge_selector,
			'mergeExpectedColor'       => $second_color,
			'mergeReplacedColor'       => $first_color,
			'themeJsonBase'            => $theme_base,
			'themeJsonIncoming'        => $theme_incoming,
			'themeJsonUnsafe'          => $theme_unsafe,
			'baseTextColor'            => $base_text,
			'incomingTextColor'        => $incoming_text,
			'incomingUnits'            => array( 'rem', 'px', '%' ),
			'variationTextColor'       => $variation_color,
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
