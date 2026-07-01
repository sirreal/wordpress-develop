<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes core block-support registration, wrapper, helper, and render-filter APIs.
 */
final class BlockSupportsSurface {
	public const NAME = 'block-supports';

	private const PREVIEW_BYTES = 260;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'block-supports.bootstrap-apis-available',
					'Required WordPress block-support APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot       = self::snapshot_state();
		$ob_level       = ob_get_level();
		$rows           = array();
		$state_restored = false;

		try {
			self::prepare_runtime();

			$case = self::case_for_context( $ctx );

			$rows[] = self::check_registration_and_direct_callbacks( $ctx, $case );
			$rows[] = self::check_wrapper_merge_and_skip_serialization( $ctx, $case );
			$rows[] = self::check_render_filters( $ctx, $case );
			$rows[] = self::check_elements_and_custom_css_filters( $ctx, $case );
			$rows[] = self::check_state_and_layout_helpers( $ctx, $case );
			$rows[] = self::check_duotone_support( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'block-supports.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_state( $snapshot );
			$state_restored = self::state_matches( $snapshot );
		}

		$rows[] = $ctx->result(
			'block-supports.state-restored',
			$state_restored,
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedStatics' => array_keys( $snapshot['statics'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Block_Supports',
				'WP_Block_Type',
				'WP_Block_Type_Registry',
				'WP_HTML_Tag_Processor',
				'WP_Scripts',
				'WP_Style_Engine_CSS_Rules_Store',
				'WP_Styles',
				'WP_Theme_JSON',
				'WP_Theme_JSON_Data',
				'WP_Theme_JSON_Resolver',
				'WP_Duotone',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_add_block_level_preset_styles',
				'_wp_get_presets_class_name',
				'block_has_support',
				'get_block_wrapper_attributes',
				'register_block_type',
				'unregister_block_type',
				'wp_get_block_css_selector',
				'wp_get_global_settings',
				'wp_apply_alignment_support',
				'wp_apply_anchor_support',
				'wp_apply_aria_label_support',
				'wp_apply_border_support',
				'wp_apply_colors_support',
				'wp_apply_custom_classname_support',
				'wp_apply_dimensions_support',
				'wp_apply_generated_classname_support',
				'wp_apply_shadow_support',
				'wp_apply_spacing_support',
				'wp_apply_typography_support',
				'wp_build_state_selector',
				'wp_get_child_layout_style_rules',
				'wp_get_state_declarations_with_background_resets',
				'wp_get_state_declarations_with_fallback_border_styles',
				'wp_mark_auto_generate_control_attributes',
				'wp_render_background_support',
				'wp_render_block_states_support',
				'wp_render_block_visibility_support',
				'wp_render_custom_css_class_name',
				'wp_render_custom_css_support_styles',
				'wp_render_dimensions_support',
				'wp_render_elements_class_name',
				'wp_render_elements_support_styles',
				'wp_render_layout_support_flag',
				'wp_render_position_support',
				'wp_sanitize_block_gap_value',
				'wp_should_skip_block_supports_serialization',
				'wp_split_selector_list',
				'wp_style_engine_get_stylesheet_from_css_rules',
				'wp_style_engine_get_stylesheet_from_context',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function prepare_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/component-fuzz/block-supports/';
		$_SERVER['PHP_SELF']    = '/index.php';

		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		$GLOBALS['wp_styles']  = new \WP_Styles();

		self::set_style_stores( array() );
		self::reset_duotone_state();
		self::clean_theme_json_caches();

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function check_registration_and_direct_callbacks( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$name     = $case['blockName'] . '-registration';

		\unregister_block_type( $name );
		$block_type = \register_block_type(
			$name,
			array(
				'title'      => 'Component Fuzz Block Supports',
				'supports'   => self::base_supports(),
				'attributes' => self::base_attributes(),
				'selectors'  => self::base_selectors(),
			)
		);
		\WP_Block_Supports::init();

		self::collect_failure(
			$failures,
			$block_type instanceof \WP_Block_Type,
			'register_block_type returns a WP_Block_Type for the generated block',
			array( 'name' => $name )
		);

		if ( ! $block_type instanceof \WP_Block_Type ) {
			return self::result(
				$ctx,
				'block-supports.registration.direct-callback-contracts',
				false,
				array( 'failures' => $failures )
			);
		}

		$attributes = $block_type->attributes;
		$align      = \wp_apply_alignment_support( $block_type, array( 'align' => 'wide' ) );
		$anchor     = \wp_apply_anchor_support( $block_type, array( 'anchor' => $case['anchor'] ) );
		$aria       = \wp_apply_aria_label_support( $block_type, array( 'ariaLabel' => $case['ariaLabel'] ) );
		$generated  = \wp_apply_generated_classname_support( $block_type );
		$custom     = \wp_apply_custom_classname_support( $block_type, array( 'className' => $case['userClass'] ) );

		self::collect_failure(
			$failures,
			isset(
				$attributes['align'],
				$attributes['anchor'],
				$attributes['ariaLabel'],
				$attributes['className'],
				$attributes['style'],
				$attributes['textColor'],
				$attributes['backgroundColor'],
				$attributes['gradient'],
				$attributes['borderColor'],
				$attributes['fontSize'],
				$attributes['fontFamily']
			),
			'WP_Block_Supports::init() registers expected support attributes',
			array( 'attributes' => array_keys( (array) $attributes ) )
		);

		self::collect_failure(
			$failures,
			! empty( $attributes['customToken']['autoGenerateControl'] )
				&& ! empty( $attributes['numericToken']['autoGenerateControl'] )
				&& empty( $attributes['htmlToken']['autoGenerateControl'] )
				&& empty( $attributes['localToken']['autoGenerateControl'] )
				&& empty( $attributes['objectToken']['autoGenerateControl'] ),
			'auto-register marks only user-controlled scalar attributes',
			array(
				'customToken'  => $attributes['customToken'] ?? null,
				'numericToken' => $attributes['numericToken'] ?? null,
				'htmlToken'    => $attributes['htmlToken'] ?? null,
				'localToken'   => $attributes['localToken'] ?? null,
				'objectToken'  => $attributes['objectToken'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			'alignwide' === ( $align['class'] ?? null )
				&& $case['anchor'] === ( $anchor['id'] ?? null )
				&& $case['ariaLabel'] === ( $aria['aria-label'] ?? null )
				&& str_contains( (string) ( $generated['class'] ?? '' ), 'wp-block-component-fuzz-' )
				&& $case['userClass'] === ( $custom['class'] ?? null ),
			'direct support callbacks emit expected attributes',
			array(
				'align'     => $align,
				'anchor'    => $anchor,
				'aria'      => $aria,
				'generated' => $generated,
				'custom'    => $custom,
			)
		);

		self::collect_failure(
			$failures,
			\block_has_support( $block_type, array( 'color', 'link' ), false )
				&& \block_has_support( $block_type, array( 'spacing', 'blockGap' ), false )
				&& \block_has_support( $block_type, array( 'dimensions', 'aspectRatio' ), false )
				&& ! \wp_should_skip_block_supports_serialization( $block_type, 'color', 'text' ),
			'block_has_support and serialization query helpers agree with support matrix',
			array( 'supports' => $block_type->supports )
		);

		\unregister_block_type( $name );

		return self::result(
			$ctx,
			'block-supports.registration.direct-callback-contracts',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_wrapper_merge_and_skip_serialization( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$name     = $case['blockName'] . '-wrapper';
		$skip     = $case['blockName'] . '-skip';

		\unregister_block_type( $name );
		\unregister_block_type( $skip );

		$block_type = \register_block_type(
			$name,
			array(
				'title'      => 'Component Fuzz Wrapper',
				'supports'   => self::base_supports(),
				'attributes' => self::base_attributes(),
				'selectors'  => self::base_selectors(),
			)
		);
		$skip_type  = \register_block_type(
			$skip,
			array(
				'title'      => 'Component Fuzz Wrapper Skip',
				'supports'   => self::skip_supports(),
				'attributes' => self::base_attributes(),
				'selectors'  => self::base_selectors(),
			)
		);
		\WP_Block_Supports::init();

		if ( ! $block_type instanceof \WP_Block_Type || ! $skip_type instanceof \WP_Block_Type ) {
			return self::result(
				$ctx,
				'block-supports.wrapper.merge-and-skip-serialization',
				false,
				array( 'registered' => array( $block_type instanceof \WP_Block_Type, $skip_type instanceof \WP_Block_Type ) )
			);
		}

		$attrs = self::support_attributes( $case );
		\WP_Block_Supports::$block_to_render = array(
			'blockName' => $name,
			'attrs'     => $attrs,
		);
		$applied = \WP_Block_Supports::get_instance()->apply_block_supports();
		$wrapper = \get_block_wrapper_attributes(
			array(
				'class'      => 'extra-class ' . $case['userClass'],
				'style'      => 'color:#010203;',
				'id'         => 'explicit-' . $case['slug'],
				'aria-label' => 'Explicit ' . $case['slug'],
				'data-token' => $case['slug'],
				'hidden'     => true,
				'bad'        => array( 'not-rendered' ),
			)
		);

		$skip_outputs = array(
			'color'      => \wp_apply_colors_support( $skip_type, $attrs ),
			'spacing'    => \wp_apply_spacing_support( $skip_type, $attrs ),
			'border'     => \wp_apply_border_support( $skip_type, $attrs ),
			'typography' => \wp_apply_typography_support( $skip_type, $attrs ),
			'dimensions' => \wp_apply_dimensions_support( $skip_type, $attrs ),
			'shadow'     => \wp_apply_shadow_support( $skip_type, $attrs ),
		);

		\WP_Block_Supports::$block_to_render = null;
		$empty_applied                       = \WP_Block_Supports::get_instance()->apply_block_supports();

		self::collect_failure(
			$failures,
			isset( $applied['class'], $applied['style'], $applied['id'], $applied['aria-label'] )
				&& str_contains( $applied['class'], 'aligncenter' )
				&& str_contains( $applied['class'], $case['userClass'] )
				&& str_contains( $applied['class'], 'wp-block-component-fuzz-' )
				&& $case['anchor'] === $applied['id']
				&& $case['ariaLabel'] === $applied['aria-label']
				&& self::css_contains_declaration( $applied['style'], 'line-height', $case['lineHeight'] ),
			'aggregate apply_block_supports combines core support attributes',
			array( 'applied' => $applied )
		);

		self::collect_failure(
			$failures,
			str_contains( $wrapper, 'class="' )
				&& str_contains( $wrapper, 'extra-class' )
				&& str_contains( $wrapper, 'aligncenter' )
				&& str_contains( $wrapper, 'style="' )
				&& str_contains( $wrapper, 'color:#010203' )
				&& str_contains( $wrapper, 'id="explicit-' . $case['slug'] . '"' )
				&& str_contains( $wrapper, 'aria-label="Explicit ' . $case['slug'] . '"' )
				&& str_contains( $wrapper, 'data-token="' . $case['slug'] . '"' )
				&& ! str_contains( $wrapper, 'hidden=' )
				&& ! str_contains( $wrapper, 'bad=' )
				&& ! str_contains( strtolower( $wrapper ), '<script' ),
			'get_block_wrapper_attributes merges, escapes, and filters wrapper attributes',
			array( 'wrapper' => self::preview( $wrapper ) )
		);

		self::collect_failure(
			$failures,
			array() === $skip_outputs['color']
				&& array() === $skip_outputs['spacing']
				&& array() === $skip_outputs['border']
				&& array() === $skip_outputs['typography']
				&& array() === $skip_outputs['dimensions']
				&& array() === $skip_outputs['shadow']
				&& \wp_should_skip_block_supports_serialization( $skip_type, 'color' )
				&& \wp_should_skip_block_supports_serialization( $skip_type, 'spacing', 'blockGap' )
				&& \wp_should_skip_block_supports_serialization( $skip_type, 'shadow' )
				&& array() === $empty_applied,
			'serialization skip flags suppress targeted support output and empty render context fails closed',
			array(
				'skipOutputs'  => $skip_outputs,
				'emptyApplied' => $empty_applied,
			)
		);

		\unregister_block_type( $name );
		\unregister_block_type( $skip );

		return self::result(
			$ctx,
			'block-supports.wrapper.merge-and-skip-serialization',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_render_filters( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$name     = $case['blockName'] . '-render';

		\unregister_block_type( $name );
		self::set_style_stores( array() );

		$theme_filter = static function ( \WP_Theme_JSON_Data $theme_json ): \WP_Theme_JSON_Data {
			return $theme_json->update_with(
				array(
					'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => array(
						'position' => array(
							'sticky' => true,
							'fixed'  => true,
						),
						'spacing'  => array(
							'blockGap' => true,
						),
					),
					'styles'   => array(
						'spacing' => array(
							'blockGap' => '0.75rem',
						),
					),
				)
			);
		};

		\add_filter( 'wp_theme_json_data_default', $theme_filter, 10 );
		self::clean_theme_json_caches();

		try {
			$block_type = \register_block_type(
				$name,
				array(
					'title'      => 'Component Fuzz Render Filters',
					'supports'   => array_replace_recursive(
						self::base_supports(),
						array(
							'background' => array( 'backgroundImage' => true ),
							'position'   => array( 'sticky' => true ),
							'layout'     => array(
								'default' => array(
									'type' => 'flex',
								),
							),
						)
					),
					'attributes' => self::base_attributes(),
					'selectors'  => self::base_selectors(),
				)
			);

			if ( ! $block_type instanceof \WP_Block_Type ) {
				self::collect_failure( $failures, false, 'render-filter block type registers', array( 'name' => $name ) );
			}

			$html = '<div class="outer" style="opacity:.9"><span>child</span><img src="https://example.test/a.jpg"></div><p class="sibling">side</p>';

			$background = \wp_render_background_support(
				$html,
				array(
					'blockName' => $name,
					'attrs'     => array(
						'style' => array(
							'background' => array(
								'backgroundImage' => array( 'url' => 'https://example.test/bg-' . $case['slug'] . '.png' ),
								'backgroundSize'  => 'contain',
							),
						),
					),
				)
			);

			$dimensions = \wp_render_dimensions_support(
				'<div class="box" style="height:20px">ratio</div>',
				array(
					'blockName' => $name,
					'attrs'     => array(
						'style' => array(
							'dimensions' => array(
								'aspectRatio' => '16 / 9',
							),
						),
					),
				)
			);

			$hidden = \wp_render_block_visibility_support(
				$html,
				array(
					'blockName' => $name,
					'attrs'     => array(
						'metadata' => array(
							'blockVisibility' => false,
						),
					),
				)
			);

			$viewport = \wp_render_block_visibility_support(
				$html,
				array(
					'blockName' => $name,
					'attrs'     => array(
						'metadata' => array(
							'blockVisibility' => array(
								'viewport' => array(
									'desktop' => false,
									'mobile'  => false,
									'tablet'  => true,
									'unknown' => false,
								),
							),
						),
					),
				)
			);

			$position = \wp_render_position_support(
				'<section class="positioned">sticky</section>',
				array(
					'blockName' => $name,
					'attrs'     => array(
						'style' => array(
							'position' => array(
								'type' => 'sticky',
								'top'  => '0',
							),
						),
					),
				)
			);

			$layout = \wp_render_layout_support_flag(
				'<div class="layout"><span>item</span></div>',
				array(
					'blockName' => $name,
					'attrs'     => array(
						'layout' => array(
							'type'           => 'flex',
							'orientation'    => 'horizontal',
							'justifyContent' => 'center',
							'flexWrap'       => 'nowrap',
						),
						'style'  => array(
							'spacing' => array(
								'blockGap' => $case['gap'],
							),
						),
					),
				)
			);

			$stylesheet = self::stylesheet_for_context( 'block-supports' );
		} finally {
			\remove_filter( 'wp_theme_json_data_default', $theme_filter, 10 );
			self::clean_theme_json_caches();
		}

		self::collect_failure(
			$failures,
			str_contains( $background, 'has-background' )
				&& str_contains( $background, 'background-image:url(' )
				&& str_contains( $background, 'background-size:contain' )
				&& str_contains( $background, 'background-position:50% 50%' )
				&& 1 === substr_count( $background, 'has-background' )
				&& str_contains( $background, '<p class="sibling">side</p>' ),
			'background support mutates only the first wrapper with sanitized declarations',
			array( 'background' => self::preview( $background ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $dimensions, 'aspect-ratio:16 / 9' )
				&& str_contains( $dimensions, 'height:unset' )
				&& str_contains( $dimensions, 'min-height:unset' )
				&& '' === $hidden,
			'dimensions aspect-ratio and hidden visibility branches render expected output',
			array(
				'dimensions' => self::preview( $dimensions ),
				'hidden'     => $hidden,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $viewport, 'wp-block-hidden-desktop wp-block-hidden-mobile' )
				&& str_contains( $viewport, 'fetchpriority="auto"' )
				&& str_contains( $stylesheet, '@media (width > 782px)' )
				&& str_contains( $stylesheet, '@media (width <= 480px)' )
				&& str_contains( $stylesheet, 'display:none !important' ),
			'viewport visibility classes are deterministic and enqueue matching media rules',
			array(
				'viewport'   => self::preview( $viewport ),
				'stylesheet' => self::preview( $stylesheet ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $position, 'is-position-sticky' )
				&& str_contains( $layout, 'is-layout-flex' )
				&& str_contains( $layout, 'is-horizontal' )
				&& str_contains( $layout, 'is-content-justification-center' )
				&& str_contains( $layout, 'is-nowrap' )
				&& str_contains( $stylesheet, 'position:sticky' )
				&& str_contains( $stylesheet, 'gap:' )
				&& self::css_is_safe( $stylesheet ),
			'position and layout render filters add gated classes and safe stored CSS',
			array(
				'position'   => self::preview( $position ),
				'layout'     => self::preview( $layout ),
				'stylesheet' => self::preview( $stylesheet ),
			)
		);

		\unregister_block_type( $name );

		return self::result(
			$ctx,
			'block-supports.render-filters.wrapper-mutations-and-stored-css',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_elements_and_custom_css_filters( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$name     = $case['blockName'] . '-elements';

		\unregister_block_type( $name );
		self::set_style_stores( array() );

		$block_type = \register_block_type(
			$name,
			array(
				'title'      => 'Component Fuzz Elements',
				'supports'   => array_replace_recursive(
					self::base_supports(),
					array(
						'customCSS' => true,
						'color'     => array(
							'link'    => true,
							'button'  => true,
							'heading' => true,
						),
					)
				),
				'attributes' => self::base_attributes(),
				'selectors'  => self::base_selectors(),
			)
		);

		if ( ! $block_type instanceof \WP_Block_Type ) {
			return self::result(
				$ctx,
				'block-supports.elements-custom-css.render-data-and-class-filters',
				false,
				array( 'registered' => false )
			);
		}

		$parsed = array(
			'blockName' => $name,
			'attrs'     => array(
				'className' => 'existing-' . $case['slug'],
				'style'     => array(
					'elements' => array(
						'link'   => array(
							'color'  => array(
								'text' => $case['safeColor'],
							),
							':hover' => array(
								'color' => array(
									'text' => '#123456',
								),
							),
						),
						'button' => array(
							'color' => array(
								'background' => '#abcdef',
							),
						),
					),
				),
			),
		);

		$updated_elements = \wp_render_elements_support_styles( $parsed );
		$element_html     = \wp_render_elements_class_name( '<div class="first"><a href="#">link</a></div><div class="second"></div>', $updated_elements );

		$custom = array(
			'blockName' => $name,
			'attrs'     => array(
				'style' => array(
					'css' => '& .child { color: ' . $case['safeColor'] . '; } &:hover { background-color: #abcdef; }',
				),
			),
		);

		$updated_custom = \wp_render_custom_css_support_styles( $custom );
		$custom_html    = \wp_render_custom_css_class_name( '<div class="custom"><span class="child">x</span></div>', $updated_custom );
		$invalid_custom = \wp_render_custom_css_support_styles(
			array(
				'blockName' => $name,
				'attrs'     => array(
					'style' => array(
						'css' => '<script>alert(1)</script>',
					),
				),
			)
		);

		$stylesheet    = self::stylesheet_for_context( 'block-supports' );
		$custom_styles = self::registered_style_after( 'wp-block-custom-css' );

		$element_class = (string) ( $updated_elements['attrs']['className'] ?? '' );
		$custom_class  = (string) ( $updated_custom['attrs']['className'] ?? '' );

		self::collect_failure(
			$failures,
			str_contains( $element_class, 'existing-' . $case['slug'] )
				&& str_contains( $element_class, 'wp-elements-' )
				&& str_contains( $element_html, 'wp-elements-' )
				&& str_contains( $stylesheet, 'a:where(:not(.wp-element-button))' )
				&& str_contains( $stylesheet, $case['safeColor'] )
				&& self::css_is_safe( $stylesheet ),
			'element support adds a generated class and safe element CSS rules',
			array(
				'elementClass' => $element_class,
				'elementHtml'  => self::preview( $element_html ),
				'stylesheet'   => self::preview( $stylesheet ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $custom_class, 'wp-custom-css-' )
				&& str_contains( $custom_html, 'has-custom-css' )
				&& str_contains( $custom_html, 'wp-custom-css-' )
				&& str_contains( $custom_styles, $case['safeColor'] )
				&& str_contains( $custom_styles, '.child' )
				&& ! str_contains( (string) ( $invalid_custom['attrs']['className'] ?? '' ), 'wp-custom-css-' )
				&& self::css_is_safe( $custom_styles ),
			'custom CSS support hashes class names, rejects markup CSS, and stores safe inline CSS',
			array(
				'customClass'  => $custom_class,
				'customHtml'   => self::preview( $custom_html ),
				'customStyles' => self::preview( $custom_styles ),
				'invalid'      => $invalid_custom,
			)
		);

		\unregister_block_type( $name );

		return self::result(
			$ctx,
			'block-supports.elements-custom-css.render-data-and-class-filters',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_state_and_layout_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$name     = 'core/button';

		\unregister_block_type( $name );
		self::set_style_stores( array() );

		$split      = \wp_split_selector_list( '.alpha:is(.one,.two), [data-fuzz="x"] .beta' );
		$selector   = \wp_build_state_selector( '.wp-states-abcd', '.wp-block-button > .wp-element-button, [data-fuzz] .inner', ':hover' );
		$gap_string = \wp_sanitize_block_gap_value( 'calc(1px + 1em)' );
		$gap_array  = \wp_sanitize_block_gap_value(
			array(
				'top'    => $case['gap'],
				'bottom' => '1px/*bad*/',
			)
		);
		$child_rules = \wp_get_child_layout_style_rules(
			'.child-layout',
			array(
				'selfStretch' => 'fixedNoShrink',
				'flexSize'    => '25%',
				'columnStart' => '2',
				'columnSpan'  => '3',
			),
			array(
				'type'               => 'grid',
				'minimumColumnWidth' => '8rem',
				'columnCount'        => '4',
			)
		);
		$border_fallback = \wp_get_state_declarations_with_fallback_border_styles(
			array(
				'border-color' => '#112233',
			)
		);
		$background_reset = \wp_get_state_declarations_with_background_resets(
			array(
				'background-color' => '#ddeeff',
			)
		);
		$auto_marked      = \wp_mark_auto_generate_control_attributes(
			array(
				'supports'   => array( 'autoRegister' => true ),
				'attributes' => self::base_attributes(),
			)
		);

		$block_type = \register_block_type(
			$name,
			array(
				'title'      => 'Component Fuzz Button State',
				'supports'   => self::base_supports(),
				'attributes' => self::base_attributes(),
				'selectors'  => array(
					'root'  => '.wp-block-button',
					'color' => array(
						'root' => '.wp-block-button > .wp-element-button',
						'text' => '.wp-block-button > .wp-element-button',
					),
				),
			)
		);

		$state_html = \wp_render_block_states_support(
			'<div class="wp-block-button"><a class="wp-element-button">Button</a></div>',
			array(
				'blockName' => $name,
				'attrs'     => array(
					'style' => array(
						':hover' => array(
							'color'  => array(
								'text'       => 'var:preset|color|' . $case['slug'],
								'background' => '#ffffff',
							),
							'border' => array(
								'color' => '#112233',
							),
						),
						'mobile' => array(
							'color'    => array(
								'background' => '#ddeeff',
							),
							'elements' => array(
								'link' => array(
									':hover' => array(
										'color' => array(
											'text' => '#334455',
										),
									),
								),
							),
						),
					),
				),
			)
		);
		$stylesheet = self::stylesheet_for_context( 'block-supports' );

		self::collect_failure(
			$failures,
			2 === count( $split )
				&& '.wp-states-abcd > .wp-element-button:hover, .wp-states-abcd .inner:hover' === $selector
				&& null === $gap_string
				&& $case['gap'] === ( $gap_array['top'] ?? null )
				&& array_key_exists( 'bottom', $gap_array )
				&& null === $gap_array['bottom']
				&& self::rules_contain_declaration( $child_rules, 'flex-basis', '25%' )
				&& self::rules_contain_declaration( $child_rules, 'flex-shrink', '0' )
				&& self::rules_contain_declaration( $child_rules, 'grid-column', '2 / span 3' )
				&& 'solid' === ( $border_fallback['border-style'] ?? null )
				&& 'unset !important' === ( $background_reset['background-image'] ?? null ),
			'pure helper matrices preserve selector splitting, layout child rules, and fallback declarations',
			array(
				'split'           => $split,
				'selector'        => $selector,
				'gapString'       => $gap_string,
				'gapArray'        => $gap_array,
				'childRules'      => $child_rules,
				'borderFallback'  => $border_fallback,
				'backgroundReset' => $background_reset,
			)
		);

		self::collect_failure(
			$failures,
			! empty( $auto_marked['attributes']['customToken']['autoGenerateControl'] )
				&& ! empty( $auto_marked['attributes']['numericToken']['autoGenerateControl'] )
				&& empty( $auto_marked['attributes']['htmlToken']['autoGenerateControl'] )
				&& $block_type instanceof \WP_Block_Type
				&& str_contains( $state_html, 'wp-states-' )
				&& str_contains( $stylesheet, '.wp-states-' )
				&& str_contains( $stylesheet, ':hover' )
				&& str_contains( $stylesheet, '!important' )
				&& str_contains( $stylesheet, 'border-style:solid' )
				&& str_contains( $stylesheet, 'background-image:unset !important' )
				&& self::css_is_safe( $stylesheet ),
			'state renderer scopes pseudo and responsive state CSS with safe fallback declarations',
			array(
				'stateHtml'  => self::preview( $state_html ),
				'stylesheet' => self::preview( $stylesheet ),
				'autoMarked' => $auto_marked['attributes'] ?? array(),
			)
		);

		\unregister_block_type( $name );

		return self::result(
			$ctx,
			'block-supports.state-layout-and-auto-register-helpers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_duotone_support( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures      = array();
		$name          = $case['blockName'] . '-duotone';
		$unsupported   = $case['blockName'] . '-duotone-unsupported';
		$no_global     = $case['blockName'] . '-duotone-no-global';
		$preset_slug   = self::duotone_preset_slug( $case['slug'] );
		$preset_filter = 'wp-duotone-' . $preset_slug;
		$preset_attr   = 'var:preset|duotone|' . $preset_slug;
		$custom_colors = array( '#ffffff', $case['safeColor'] );

		\unregister_block_type( $name );
		\unregister_block_type( $unsupported );
		\unregister_block_type( $no_global );
		self::set_style_stores( array() );
		self::reset_duotone_state();

		$theme_filter = static function ( \WP_Theme_JSON_Data $theme_json ) use ( $name, $preset_slug, $case ): \WP_Theme_JSON_Data {
			return $theme_json->update_with(
				array(
					'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => array(
						'color' => array(
							'duotone' => array(
								array(
									'name'   => 'Component Fuzz ' . $preset_slug,
									'slug'   => $preset_slug,
									'colors' => array( '#000000', $case['safeColor'] ),
								),
							),
						),
					),
					'styles'   => array(
						'blocks' => array(
							$name => array(
								'filter' => array(
									'duotone' => 'var:preset|duotone|' . $preset_slug,
								),
							),
						),
					),
				)
			);
		};

		\add_filter( 'wp_theme_json_data_default', $theme_filter, 10 );
		self::clean_theme_json_caches();

		try {
			$block_type       = \register_block_type(
				$name,
				array(
					'title'      => 'Component Fuzz Duotone',
					'supports'   => array(
						'filter' => array(
							'duotone' => true,
						),
					),
					'attributes' => self::base_attributes(),
					'selectors'  => array(
						'root'   => '.wp-block-component-fuzz',
						'filter' => array(
							'duotone' => '.wp-block-component-fuzz img,.wp-block-component-fuzz picture',
						),
					),
				)
			);
			$unsupported_type = \register_block_type(
				$unsupported,
				array(
					'title'      => 'Component Fuzz Duotone Unsupported',
					'supports'   => array(
						'filter' => array(
							'duotone' => false,
						),
					),
					'attributes' => self::base_attributes(),
					'selectors'  => array(
						'root' => '.wp-block-component-fuzz',
					),
				)
			);
			$no_global_type   = \register_block_type(
				$no_global,
				array(
					'title'      => 'Component Fuzz Duotone No Global Style',
					'supports'   => array(
						'filter' => array(
							'duotone' => true,
						),
					),
					'attributes' => self::base_attributes(),
					'selectors'  => array(
						'root'   => '.wp-block-component-fuzz',
						'filter' => array(
							'duotone' => '.wp-block-component-fuzz img,.wp-block-component-fuzz picture',
						),
					),
				)
			);
			\WP_Block_Supports::init();

			$migrated        = \WP_Duotone::migrate_experimental_duotone_support_flag(
				array( 'supports' => array() ),
				array(
					'supports' => array(
						'color' => array(
							'__experimentalDuotone' => '.legacy-target',
						),
					),
				)
			);
			$not_overwritten = \WP_Duotone::migrate_experimental_duotone_support_flag(
				array(
					'supports' => array(
						'filter' => array(
							'duotone' => '.existing-target',
						),
					),
				),
				array(
					'supports' => array(
						'color' => array(
							'__experimentalDuotone' => false,
						),
					),
				)
			);

			$html = '<figure class="wp-block-component-fuzz"><img src="/' . $case['slug'] . '.jpg" alt=""></figure><p class="sibling">side</p>';

			$preset = self::render_duotone_case(
				$name,
				array(
					'style' => array(
						'color' => array(
							'duotone' => $preset_attr,
						),
					),
				),
				$html
			);
			$custom = self::render_duotone_case(
				$name,
				array(
					'style' => array(
						'color' => array(
							'duotone' => $custom_colors,
						),
					),
				),
				$html
			);
			$unset  = self::render_duotone_case(
				$name,
				array(
					'style' => array(
						'color' => array(
							'duotone' => 'unset',
						),
					),
				),
				$html
			);
			$global = self::render_duotone_case( $name, array(), $html );
			$empty  = self::render_duotone_case(
				$name,
				array(
					'style' => array(
						'color' => array(
							'duotone' => $preset_attr,
						),
					),
				),
				''
			);

			$unsupported_render = self::render_duotone_case(
				$unsupported,
				array(
					'style' => array(
						'color' => array(
							'duotone' => $preset_attr,
						),
					),
				),
				$html
			);
			$without_attr       = self::render_duotone_case( $no_global, array( 'className' => 'no-duotone' ), $html );

			$used_presets = (array) self::get_static_property( 'WP_Duotone', 'used_global_styles_presets' );
			$used_svgs    = (array) self::get_static_property( 'WP_Duotone', 'used_svg_filter_data' );
			$css_rules    = (array) self::get_static_property( 'WP_Duotone', 'block_css_declarations' );
			$css          = (string) \wp_style_engine_get_stylesheet_from_css_rules(
				$css_rules,
				array(
					'prettify' => false,
					'optimize' => false,
				)
			);

			$editor_settings = \WP_Duotone::add_editor_settings( array( 'styles' => array() ) );
			$editor_css      = '';
			$editor_assets   = '';
			foreach ( $editor_settings['styles'] ?? array() as $style ) {
				$editor_css    .= (string) ( $style['css'] ?? '' );
				$editor_assets .= (string) ( $style['assets'] ?? '' );
			}
		} finally {
			\remove_filter( 'wp_theme_json_data_default', $theme_filter, 10 );
			self::clean_theme_json_caches();
		}

		self::collect_failure(
			$failures,
			$block_type instanceof \WP_Block_Type
				&& $unsupported_type instanceof \WP_Block_Type
				&& $no_global_type instanceof \WP_Block_Type
				&& isset( $block_type->attributes['style'] )
				&& \block_has_support( $block_type, array( 'filter', 'duotone' ), null )
				&& \block_has_support( $no_global_type, array( 'filter', 'duotone' ), null )
				&& ! \block_has_support( $unsupported_type, array( 'filter', 'duotone' ), null )
				&& true === ( $migrated['supports']['filter']['duotone'] ?? null )
				&& '.existing-target' === ( $not_overwritten['supports']['filter']['duotone'] ?? null ),
			'duotone support registers style attributes, gates unsupported blocks, and migrates legacy metadata once',
			array(
				'supportedAttributes'   => $block_type instanceof \WP_Block_Type ? array_keys( (array) $block_type->attributes ) : array(),
				'unsupportedAttributes' => $unsupported_type instanceof \WP_Block_Type ? array_keys( (array) $unsupported_type->attributes ) : array(),
				'noGlobalAttributes'    => $no_global_type instanceof \WP_Block_Type ? array_keys( (array) $no_global_type->attributes ) : array(),
				'migrated'              => $migrated,
				'notOverwritten'        => $not_overwritten,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $preset, 'class="wp-block-component-fuzz ' . $preset_filter . '"' )
				&& 1 === substr_count( $preset, $preset_filter )
				&& preg_match( '/wp-duotone-ffffff-[0-9a-f]{6}-\d+/', $custom )
				&& preg_match( '/wp-duotone-unset-\d+/', $unset )
				&& str_contains( $global, 'class="wp-block-component-fuzz ' . $preset_filter . '"' )
				&& '' === $empty
				&& $html === $unsupported_render
				&& $html === $without_attr
				&& str_contains( $preset, '<p class="sibling">side</p>' )
				&& self::html_is_safe( $preset . $custom . $unset . $global ),
			'duotone render support handles preset, custom, unset, global-style, empty, and unsupported paths',
			array(
				'checks'      => array(
					'presetClass'  => str_contains( $preset, 'class="wp-block-component-fuzz ' . $preset_filter . '"' ),
					'presetCount'  => 1 === substr_count( $preset, $preset_filter ),
					'customClass'  => (bool) preg_match( '/wp-duotone-ffffff-[0-9a-f]{6}-\d+/', $custom ),
					'unsetClass'   => (bool) preg_match( '/wp-duotone-unset-\d+/', $unset ),
					'globalClass'  => str_contains( $global, 'class="wp-block-component-fuzz ' . $preset_filter . '"' ),
					'empty'        => '' === $empty,
					'unsupported'  => $html === $unsupported_render,
					'withoutAttr'  => $html === $without_attr,
					'sibling'      => str_contains( $preset, '<p class="sibling">side</p>' ),
					'safeHtml'     => self::html_is_safe( $preset . $custom . $unset . $global ),
				),
				'preset'      => self::preview( $preset ),
				'custom'      => self::preview( $custom ),
				'unset'       => self::preview( $unset ),
				'global'      => self::preview( $global ),
				'empty'       => $empty,
				'unsupported' => self::preview( $unsupported_render ),
				'withoutAttr' => self::preview( $without_attr ),
			)
		);

		self::collect_failure(
			$failures,
			isset( $used_presets[ $preset_filter ], $used_svgs[ $preset_filter ] )
				&& count( $css_rules ) >= 4
				&& str_contains( $css, '.' . $preset_filter . '.wp-block-component-fuzz img' )
				&& str_contains( $css, 'filter:var(--wp--preset--duotone--' . $preset_slug . ')' )
				&& str_contains( $css, 'filter:unset' )
				&& str_contains( $css, 'url(#wp-duotone-' )
				&& str_contains( $editor_css, '--wp--preset--duotone--' . $preset_slug )
				&& str_contains( $editor_assets, 'id="' . $preset_filter . '"' )
				&& self::css_is_safe( $css . $editor_css )
				&& self::html_is_safe( $editor_assets ),
			'duotone stores safe preset SVG data, block CSS declarations, and editor settings assets',
			array(
				'usedPresets'  => array_keys( $used_presets ),
				'usedSvgs'     => array_keys( $used_svgs ),
				'cssRules'     => array_slice( $css_rules, 0, 5 ),
				'css'          => self::preview( $css ),
				'editorCss'    => self::preview( $editor_css ),
				'editorAssets' => self::preview( $editor_assets ),
			)
		);

		\unregister_block_type( $name );
		\unregister_block_type( $unsupported );
		\unregister_block_type( $no_global );

		return self::result(
			$ctx,
			'block-supports.duotone.render-and-asset-state',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$slug = self::safe_slug( $ctx );

		return array(
			'slug'       => $slug,
			'blockName'  => 'component-fuzz/' . $slug,
			'anchor'     => 'anchor-' . $slug,
			'ariaLabel'  => 'Label ' . $slug,
			'userClass'  => 'user-' . $slug,
			'safeColor'  => self::safe_color( $ctx ),
			'gap'        => $ctx->choice( array( '0.5rem', '12px', '1.25em' ) ),
			'lineHeight' => $ctx->choice( array( '1', '1.25', '1.6' ) ),
			'shadow'     => '1px 1px ' . $ctx->int( 1, 8 ) . 'px rgba(0,0,0,.25)',
		);
	}

	private static function base_supports(): array {
		return array(
			'align'                => array( 'left', 'center', 'right', 'wide', 'full' ),
			'anchor'               => true,
			'ariaLabel'            => true,
			'autoRegister'         => true,
			'background'           => array(
				'backgroundImage' => true,
			),
			'className'            => true,
			'color'                => array(
				'text'       => true,
				'background' => true,
				'gradients'  => true,
				'link'       => true,
				'button'     => true,
				'heading'    => true,
			),
			'customClassName'      => true,
			'customCSS'            => true,
			'dimensions'           => array(
				'minHeight'   => true,
				'height'      => true,
				'width'       => true,
				'aspectRatio' => true,
			),
			'layout'               => array(
				'default' => array(
					'type' => 'constrained',
				),
			),
			'position'             => array(
				'sticky' => true,
				'fixed'  => true,
			),
			'shadow'               => true,
			'spacing'              => array(
				'padding'  => true,
				'margin'   => true,
				'blockGap' => true,
			),
			'typography'           => array(
				'__experimentalFontFamily'     => true,
				'fontSize'                     => true,
				'__experimentalFontStyle'      => true,
				'__experimentalFontWeight'     => true,
				'__experimentalLetterSpacing'  => true,
				'lineHeight'                   => true,
				'textAlign'                    => true,
				'textColumns'                  => true,
				'__experimentalTextDecoration' => true,
				'__experimentalTextTransform'  => true,
				'textIndent'                   => true,
				'__experimentalWritingMode'    => true,
			),
			'visibility'           => true,
			'__experimentalBorder' => array(
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			),
			'__experimentalSettings' => true,
		);
	}

	private static function skip_supports(): array {
		$supports = self::base_supports();

		$supports['color']['__experimentalSkipSerialization'] = true;
		$supports['spacing']['__experimentalSkipSerialization'] = true;
		$supports['typography']['__experimentalSkipSerialization'] = true;
		$supports['dimensions']['__experimentalSkipSerialization'] = true;
		$supports['shadow'] = array( '__experimentalSkipSerialization' => true );
		$supports['border'] = array( '__experimentalSkipSerialization' => true );
		$supports['__experimentalBorder']['__experimentalSkipSerialization'] = true;

		return $supports;
	}

	private static function base_attributes(): array {
		return array(
			'customToken' => array(
				'type'    => 'string',
				'default' => 'component-fuzz',
			),
			'numericToken' => array(
				'type' => 'integer',
			),
			'htmlToken'   => array(
				'type'     => 'string',
				'source'   => 'html',
				'selector' => 'span',
			),
			'localToken'  => array(
				'type' => 'string',
				'role' => 'local',
			),
			'objectToken' => array(
				'type' => 'object',
			),
		);
	}

	private static function base_selectors(): array {
		return array(
			'root'  => '.wp-block-component-fuzz',
			'color' => array(
				'root'    => '.wp-block-component-fuzz',
				'text'    => '.wp-block-component-fuzz',
				'button'  => '.wp-block-component-fuzz .wp-element-button',
				'heading' => '.wp-block-component-fuzz h1,.wp-block-component-fuzz h2',
			),
		);
	}

	private static function support_attributes( array $case ): array {
		return array(
			'align'           => 'center',
			'anchor'          => $case['anchor'],
			'ariaLabel'       => $case['ariaLabel'],
			'className'       => $case['userClass'],
			'textColor'       => $case['slug'],
			'backgroundColor' => $case['slug'] . '-background',
			'fontSize'        => $case['slug'],
			'style'           => array(
				'color'      => array(
					'text'       => $case['safeColor'],
					'background' => '#f0f6fc',
				),
				'spacing'    => array(
					'padding' => array(
						'top'    => $case['gap'],
						'bottom' => $case['gap'],
					),
					'margin'  => array(
						'top' => '2px',
					),
				),
				'border'     => array(
					'color'  => '#112233',
					'radius' => '4px',
					'style'  => 'solid',
					'width'  => '1px',
				),
				'typography' => array(
					'fontSize'       => '18px',
					'fontFamily'     => 'Inter, Arial, sans-serif',
					'fontStyle'      => 'italic',
					'fontWeight'     => '600',
					'lineHeight'     => $case['lineHeight'],
					'textAlign'      => 'right',
					'letterSpacing'  => '0.02em',
					'textDecoration' => 'underline',
					'textTransform'  => 'uppercase',
					'textIndent'     => '1em',
					'writingMode'    => 'horizontal-tb',
				),
				'dimensions' => array(
					'minHeight' => '20px',
					'height'    => '40px',
					'width'     => '80%',
				),
				'shadow'     => $case['shadow'],
			),
		);
	}

	private static function collect_failure( array &$failures, bool $condition, string $message, array $data = array() ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function stylesheet_for_context( string $context ): string {
		if ( ! class_exists( 'WP_Style_Engine' ) ) {
			return '';
		}

		return (string) \wp_style_engine_get_stylesheet_from_context(
			$context,
			array(
				'prettify' => false,
				'optimize' => false,
			)
		);
	}

	private static function registered_style_after( string $handle ): string {
		$styles = \wp_styles();
		$style  = $styles->registered[ $handle ] ?? null;
		if ( ! $style || empty( $style->extra['after'] ) || ! is_array( $style->extra['after'] ) ) {
			return '';
		}

		return implode( "\n", array_map( 'strval', $style->extra['after'] ) );
	}

	private static function css_contains_declaration( string $css, string $property, string $value ): bool {
		$css = preg_replace( '/\s+/', '', strtolower( $css ) );
		$needle = strtolower( $property . ':' . $value );
		$needle = preg_replace( '/\s+/', '', $needle );

		return is_string( $css ) && is_string( $needle ) && str_contains( $css, $needle );
	}

	private static function rules_contain_declaration( array $rules, string $property, string $value ): bool {
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$declarations = $rule['declarations'] ?? array();
			if ( is_array( $declarations ) && $value === ( $declarations[ $property ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function css_is_safe( string $css ): bool {
		$lower = strtolower( $css );

		return ! str_contains( $lower, '<script' )
			&& ! str_contains( $lower, 'javascript:' )
			&& substr_count( $css, '{' ) === substr_count( $css, '}' );
	}

	private static function html_is_safe( string $html ): bool {
		$lower = strtolower( $html );

		return ! str_contains( $lower, '<script' )
			&& ! str_contains( $lower, 'javascript:' );
	}

	private static function render_duotone_case( string $name, array $attrs, string $html ): string {
		$parsed = array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);

		return \WP_Duotone::render_duotone_support( $html, $parsed, new \WP_Block( $parsed ) );
	}

	private static function duotone_preset_slug( string $slug ): string {
		$letters = preg_replace( '/[^a-z]+/', '-', strtolower( $slug ) );
		$letters = trim( (string) $letters, '-' );

		return 'duo-' . ( '' === $letters ? 'component' : $letters );
	}

	private static function safe_slug( \ComponentFuzz\FuzzContext $ctx ): string {
		$raw = strtolower( $ctx->identifier( 4, 12 ) );
		$raw = preg_replace( '/[^a-z0-9-]+/', '-', $raw );
		$raw = trim( (string) $raw, '-' );

		return '' === $raw ? 'block-supports' : 'bs-' . $raw . '-' . $ctx->int( 1, 999 );
	}

	private static function safe_color( \ComponentFuzz\FuzzContext $ctx ): string {
		return sprintf( '#%02x%02x%02x', $ctx->int( 16, 230 ), $ctx->int( 16, 230 ), $ctx->int( 16, 230 ) );
	}

	private static function snapshot_state(): array {
		$block_registry = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		$supports       = self::get_static_property( 'WP_Block_Supports', 'instance' );

		return array(
			'globals'              => self::snapshot_globals( array( 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter', 'wp_styles', 'wp_scripts', '_wp_theme_features' ) ),
			'blockRegistry'        => $block_registry,
			'blockTypes'           => $block_registry instanceof \WP_Block_Type_Registry ? self::clone_value( self::get_object_property( $block_registry, 'registered_block_types' ) ) : null,
			'supports'             => $supports,
			'blockSupports'        => $supports instanceof \WP_Block_Supports ? self::clone_value( self::get_object_property( $supports, 'block_supports' ) ) : null,
			'blockSupportRender'   => self::clone_value( self::get_static_property( 'WP_Block_Supports', 'block_to_render' ) ),
			'styleStores'          => self::clone_value( self::get_static_property( 'WP_Style_Engine_CSS_Rules_Store', 'stores' ) ),
			'duotone'              => self::snapshot_duotone_state(),
			'themeJson'            => self::snapshot_theme_json_state(),
			'statics'              => array(
				'WP_Block_Type_Registry::instance'       => $block_registry,
				'WP_Block_Supports::instance'            => $supports,
				'WP_Block_Supports::block_to_render'     => self::get_static_property( 'WP_Block_Supports', 'block_to_render' ),
				'WP_Style_Engine_CSS_Rules_Store::stores' => self::get_static_property( 'WP_Style_Engine_CSS_Rules_Store', 'stores' ),
				'WP_Duotone::global_styles_block_names'  => self::get_static_property( 'WP_Duotone', 'global_styles_block_names' ),
				'WP_Duotone::global_styles_presets'      => self::get_static_property( 'WP_Duotone', 'global_styles_presets' ),
				'WP_Duotone::used_global_styles_presets' => self::get_static_property( 'WP_Duotone', 'used_global_styles_presets' ),
				'WP_Duotone::used_svg_filter_data'       => self::get_static_property( 'WP_Duotone', 'used_svg_filter_data' ),
				'WP_Duotone::block_css_declarations'     => self::get_static_property( 'WP_Duotone', 'block_css_declarations' ),
			),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) {
			self::set_object_property( $snapshot['blockRegistry'], 'registered_block_types', self::clone_value( $snapshot['blockTypes'] ) );
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', $snapshot['blockRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', null );
		}

		if ( $snapshot['supports'] instanceof \WP_Block_Supports ) {
			self::set_object_property( $snapshot['supports'], 'block_supports', self::clone_value( $snapshot['blockSupports'] ) );
			self::set_static_property( 'WP_Block_Supports', 'instance', $snapshot['supports'] );
		} else {
			self::set_static_property( 'WP_Block_Supports', 'instance', null );
		}

		self::set_static_property( 'WP_Block_Supports', 'block_to_render', self::clone_value( $snapshot['blockSupportRender'] ) );
		self::set_style_stores( self::clone_value( $snapshot['styleStores'] ) );
		self::restore_duotone_state( $snapshot['duotone'] );
		self::restore_theme_json_state( $snapshot['themeJson'] );
	}

	private static function state_matches( array $snapshot ): bool {
		$block_registry = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		$supports       = self::get_static_property( 'WP_Block_Supports', 'instance' );

		return $block_registry === $snapshot['blockRegistry']
			&& $supports === $snapshot['supports']
			&& self::get_object_property( $block_registry, 'registered_block_types' ) == $snapshot['blockTypes']
			&& self::get_object_property( $supports, 'block_supports' ) == $snapshot['blockSupports']
			&& self::get_static_property( 'WP_Block_Supports', 'block_to_render' ) == $snapshot['blockSupportRender']
			&& self::get_static_property( 'WP_Style_Engine_CSS_Rules_Store', 'stores' ) == $snapshot['styleStores']
			&& self::snapshot_duotone_state() == $snapshot['duotone'];
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array_key_exists( $name, $GLOBALS )
				? array(
					'exists' => true,
					'value'  => self::clone_value( $GLOBALS[ $name ] ),
				)
				: array( 'exists' => false );
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ?? false ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function snapshot_theme_json_state(): array {
		$state = array(
			'statics' => array(),
			'cache'   => array(),
		);

		foreach ( self::theme_json_resolver_static_properties() as $property ) {
			$state['statics'][ $property ] = self::clone_value( self::get_static_property( 'WP_Theme_JSON_Resolver', $property ) );
		}

		if ( function_exists( 'wp_cache_get' ) ) {
			foreach ( array( 'wp_get_global_settings_custom', 'wp_get_global_settings_theme' ) as $cache_key ) {
				$found = null;
				$value = \wp_cache_get( $cache_key, 'theme_json', false, $found );

				$state['cache'][ $cache_key ] = array(
					'exists' => (bool) $found,
					'value'  => self::clone_value( $value ),
				);
			}
		}

		return $state;
	}

	private static function restore_theme_json_state( array $state ): void {
		foreach ( $state['statics'] ?? array() as $property => $value ) {
			self::set_static_property( 'WP_Theme_JSON_Resolver', $property, self::clone_value( $value ) );
		}

		if ( function_exists( 'wp_cache_set' ) && function_exists( 'wp_cache_delete' ) ) {
			foreach ( $state['cache'] ?? array() as $cache_key => $entry ) {
				if ( $entry['exists'] ?? false ) {
					\wp_cache_set( $cache_key, self::clone_value( $entry['value'] ), 'theme_json' );
				} else {
					\wp_cache_delete( $cache_key, 'theme_json' );
				}
			}
		}
	}

	private static function clean_theme_json_caches(): void {
		\WP_Theme_JSON_Resolver::clean_cached_data();

		if ( function_exists( 'wp_cache_delete' ) ) {
			\wp_cache_delete( 'wp_get_global_settings_custom', 'theme_json' );
			\wp_cache_delete( 'wp_get_global_settings_theme', 'theme_json' );
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

	private static function set_style_stores( array $stores ): void {
		self::set_static_property( 'WP_Style_Engine_CSS_Rules_Store', 'stores', $stores );
	}

	private static function snapshot_duotone_state(): array {
		$state = array();

		foreach ( self::duotone_static_properties() as $property ) {
			$state[ $property ] = self::clone_value( self::get_static_property( 'WP_Duotone', $property ) );
		}

		return $state;
	}

	private static function restore_duotone_state( array $state ): void {
		foreach ( self::duotone_static_properties() as $property ) {
			self::set_static_property( 'WP_Duotone', $property, self::clone_value( $state[ $property ] ?? null ) );
		}
	}

	private static function reset_duotone_state(): void {
		self::set_static_property( 'WP_Duotone', 'global_styles_block_names', null );
		self::set_static_property( 'WP_Duotone', 'global_styles_presets', null );
		self::set_static_property( 'WP_Duotone', 'used_global_styles_presets', array() );
		self::set_static_property( 'WP_Duotone', 'used_svg_filter_data', array() );
		self::set_static_property( 'WP_Duotone', 'block_css_declarations', array() );
	}

	private static function duotone_static_properties(): array {
		return array(
			'global_styles_block_names',
			'global_styles_presets',
			'used_global_styles_presets',
			'used_svg_filter_data',
			'block_css_declarations',
		);
	}

	private static function get_object_property( $object, string $property ) {
		if ( ! is_object( $object ) ) {
			return null;
		}

		$reflection = new \ReflectionObject( $object );
		while ( ! $reflection->hasProperty( $property ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		if ( ! $reflection->hasProperty( $property ) ) {
			return null;
		}

		$prop = $reflection->getProperty( $property );
		return $prop->getValue( $object );
	}

	private static function set_object_property( $object, string $property, $value ): void {
		if ( ! is_object( $object ) ) {
			return;
		}

		$reflection = new \ReflectionObject( $object );
		while ( ! $reflection->hasProperty( $property ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		if ( ! $reflection->hasProperty( $property ) ) {
			return;
		}

		$prop = $reflection->getProperty( $property );
		$prop->setValue( $object, $value );
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$reflection = new \ReflectionClass( $class );
		while ( ! $reflection->hasProperty( $property ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		if ( ! $reflection->hasProperty( $property ) ) {
			return null;
		}

		$prop = $reflection->getProperty( $property );
		return $prop->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}

		$reflection = new \ReflectionClass( $class );
		while ( ! $reflection->hasProperty( $property ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		if ( ! $reflection->hasProperty( $property ) ) {
			return;
		}

		$prop = $reflection->getProperty( $property );
		$prop->setValue( null, $value );
	}

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		return $value;
	}

	private static function preview( $value ) {
		return \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES );
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
