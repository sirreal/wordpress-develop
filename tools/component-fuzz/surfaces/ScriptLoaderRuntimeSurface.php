<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes server-side script-loader runtime helpers.
 */
final class ScriptLoaderRuntimeSurface {
	public const NAME = 'script-loader-runtime';

	private const BASE_URL        = 'https://example.test/wp/';
	private const CONTENT_URL     = 'https://example.test/wp-content';
	private const DEFAULT_VERSION = 'component-fuzz-script-loader-runtime';
	private const PREVIEW_BYTES   = 240;
	private const FAILURE_LIMIT   = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'script-loader-runtime.required-apis-available',
					'Required WordPress script-loader runtime APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();
		$ob_level = ob_get_level();

		try {
			self::prepare_runtime_globals();

			$case = self::case_for_context( $ctx );

			$rows[] = self::check_default_registrations( $ctx, $case );
			$rows[] = self::check_handle_normalization_duplicate_updates( $ctx, $case );
			$rows[] = self::check_dependency_order_print_boundaries( $ctx, $case );
			$rows[] = self::check_inline_localization_data( $ctx, $case );
			$rows[] = self::check_tag_builders_dataset_helpers( $ctx, $case );
			$rows[] = self::check_script_translations( $ctx, $case );
			$rows[] = self::check_emoji_settings_and_styles( $ctx, $case );
			$rows[] = self::check_style_inlining_boundaries( $ctx, $case );
			$rows[] = self::check_block_editor_loader_guards( $ctx, $case );
			$rows[] = self::check_strategy_module_interactions( $ctx, $case );
			$rows[] = self::check_classic_module_import_map_preloads( $ctx, $case );
			$rows[] = self::check_jit_script_localization( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'script-loader-runtime.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_globals( $snapshot );
		}

		$rows[] = $ctx->result(
			'script-loader-runtime.global-state-restored',
			ob_get_level() === $ob_level,
			array(
				'outputBufferLevel' => ob_get_level(),
				'expectedLevel'     => $ob_level,
				'globalNames'       => array_keys( $snapshot ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Dependencies',
				'WP_HTML_Tag_Processor',
				'WP_Scripts',
				'WP_Styles',
				'_WP_Dependency',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_print_emoji_detection_script',
				'_wp_normalize_relative_css_links',
				'load_script_textdomain',
				'load_script_translations',
				'print_footer_scripts',
				'print_head_scripts',
				'script_concat_settings',
				'wp_add_inline_script',
				'wp_add_inline_style',
				'wp_common_block_scripts_and_styles',
				'wp_default_packages',
				'wp_default_scripts',
				'wp_default_styles',
				'wp_dequeue_script',
				'wp_dequeue_style',
				'wp_deregister_script',
				'wp_deregister_style',
				'wp_enqueue_emoji_styles',
				'wp_enqueue_registered_block_scripts_and_styles',
				'wp_enqueue_script',
				'wp_enqueue_style',
				'wp_get_inline_script_tag',
				'wp_get_script_tag',
				'wp_html_custom_data_attribute_name',
				'wp_js_dataset_name',
				'wp_just_in_time_script_localization',
				'wp_localize_jquery_ui_datepicker',
				'wp_localize_script',
				'wp_maybe_inline_styles',
				'wp_print_footer_scripts',
				'wp_print_head_scripts',
				'wp_print_scripts',
				'wp_print_styles',
				'wp_prototype_before_jquery',
				'wp_register_script',
				'wp_register_style',
				'wp_remove_surrounding_empty_script_tags',
				'wp_script_add_data',
				'wp_script_is',
				'wp_scripts',
				'wp_set_script_translations',
				'wp_should_load_block_assets_on_demand',
				'wp_should_load_block_editor_scripts_and_styles',
				'wp_should_load_separate_core_block_assets',
				'wp_style_add_data',
				'wp_style_is',
				'wp_styles',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_default_registrations( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();
		self::reset_script_modules_global();

		$scripts = \wp_scripts();
		$styles  = \wp_styles();

		$script_call = self::call(
			static function () use ( $scripts ): void {
				\wp_default_scripts( $scripts );
				\wp_default_packages( $scripts );
			}
		);
		$module_call = self::call(
			static function (): void {
				if ( function_exists( 'wp_default_script_modules' ) ) {
					\wp_default_script_modules();
				}
			}
		);
		$style_call  = self::call( static fn() => \wp_default_styles( $styles ) );

		$jquery        = $scripts->registered['jquery'] ?? null;
		$jquery_core   = $scripts->registered['jquery-core'] ?? null;
		$common        = $scripts->registered['common'] ?? null;
		$wp_i18n       = $scripts->registered['wp-i18n'] ?? null;
		$block_library = $styles->registered['wp-block-library'] ?? null;
		$colors        = $styles->registered['colors'] ?? null;
		$module        = function_exists( 'wp_script_modules' ) && method_exists( \wp_script_modules(), 'get_registered' )
			? \wp_script_modules()->get_registered( '@wordpress/interactivity' )
			: null;

		$ok = ! $script_call['threw']
			&& ! $style_call['threw']
			&& ! $module_call['threw']
			&& $jquery instanceof \_WP_Dependency
			&& false === $jquery->src
			&& array( 'jquery-core', 'jquery-migrate' ) === $jquery->deps
			&& $jquery_core instanceof \_WP_Dependency
			&& is_string( $jquery_core->src )
			&& str_contains( $jquery_core->src, 'jquery' )
			&& $common instanceof \_WP_Dependency
			&& 1 === $common->args
			&& $wp_i18n instanceof \_WP_Dependency
			&& $block_library instanceof \_WP_Dependency
			&& $colors instanceof \_WP_Dependency
			&& true === $colors->src;

		if ( method_exists( \wp_script_modules(), 'get_registered' ) ) {
			$ok = $ok
				&& is_array( $module )
				&& str_contains( (string) ( $module['src'] ?? '' ), 'interactivity' )
				&& 'low' === ( $module['fetchpriority'] ?? null )
				&& true === ( $module['in_footer'] ?? null );
		}

		return $ctx->result(
			'script-loader-runtime.default-registrations',
			$ok,
			self::case_data( $case ) + array(
				'registeredScripts' => count( $scripts->registered ),
				'registeredStyles'  => count( $styles->registered ),
				'jquery'            => self::dependency_summary( $jquery ),
				'common'            => self::dependency_summary( $common ),
				'wpI18n'            => self::dependency_summary( $wp_i18n ),
				'blockLibrary'      => self::dependency_summary( $block_library ),
				'colors'            => self::dependency_summary( $colors ),
				'defaultModule'     => self::preview_array( $module ),
				'calls'             => array(
					'scripts' => self::describe_call( $script_call ),
					'styles'  => self::describe_call( $style_call ),
					'modules' => self::describe_call( $module_call ),
				),
			)
		);
	}

	private static function check_handle_normalization_duplicate_updates( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		$script       = $case['handles']['script'];
		$style        = $case['handles']['style'];
		$script_query = 'alpha=1&beta=' . rawurlencode( $case['token'] );
		$style_query  = 'gamma=2&delta=' . rawurlencode( $case['token'] );

		$script_registered = \wp_register_script( $script, $case['scriptSrcA'], array( $case['handles']['dep'] ), '1.0.0' );
		$script_duplicate  = \wp_register_script( $script, $case['scriptSrcB'], array(), '2.0.0' );
		$script_enqueue    = self::call( static fn() => \wp_enqueue_script( "{$script}?{$script_query}", $case['scriptSrcB'], array(), '2.0.0' ) );
		$script_add_args   = array(
			'invalidStrategy' => \wp_script_add_data( $script, 'strategy', 'blocking' ),
			'validStrategy'   => \wp_script_add_data( $script, 'strategy', 'defer' ),
			'conditional'     => \wp_script_add_data( $script, 'conditional', 'IE 9' ),
			'modules'         => \wp_script_add_data(
				$script,
				'module_dependencies',
				array(
					$case['moduleIds'][0],
					array(
						'id'     => $case['moduleIds'][1],
						'import' => 'dynamic',
					),
					array( 'not-id' => 'ignored' ),
					17,
				)
			),
		);

		$style_registered = \wp_register_style( $style, $case['styleSrcA'], array( $case['handles']['styleDep'] ), '1.0.0', 'screen' );
		$style_duplicate  = \wp_register_style( $style, $case['styleSrcB'], array(), '2.0.0', 'print' );
		$style_enqueue    = self::call( static fn() => \wp_enqueue_style( "{$style}?{$style_query}", $case['styleSrcB'], array(), '2.0.0', 'print' ) );
		$style_conditional = \wp_style_add_data( $style, 'conditional', 'lte IE 8' );
		$style_alt         = \wp_style_add_data( $style, 'alt', true );
		$style_title       = \wp_style_add_data( $style, 'title', $case['tagValue'] );

		$script_obj  = \wp_scripts()->registered[ $script ] ?? null;
		$style_obj   = \wp_styles()->registered[ $style ] ?? null;
		$module_deps = \wp_scripts()->get_data( $script, 'module_dependencies' );

		$ok = true === $script_registered
			&& false === $script_duplicate
			&& ! $script_enqueue['threw']
			&& $script_obj instanceof \_WP_Dependency
			&& $case['scriptSrcA'] === $script_obj->src
			&& array() === $script_obj->deps
			&& $script_query === ( \wp_scripts()->args[ $script ] ?? null )
			&& true === \wp_script_is( $script, 'enqueued' )
			&& false === $script_add_args['invalidStrategy']
			&& true === $script_add_args['validStrategy']
			&& true === $script_add_args['conditional']
			&& true === $script_add_args['modules']
			&& is_array( $module_deps )
			&& 2 === count( $module_deps )
			&& true === $style_registered
			&& false === $style_duplicate
			&& ! $style_enqueue['threw']
			&& $style_obj instanceof \_WP_Dependency
			&& $case['styleSrcA'] === $style_obj->src
			&& array() === $style_obj->deps
			&& $style_query === ( \wp_styles()->args[ $style ] ?? null )
			&& true === \wp_style_is( $style, 'enqueued' )
			&& true === $style_conditional
			&& true === $style_alt
			&& true === $style_title
			&& true === ( \wp_styles()->get_data( $style, 'alt' ) ?? null )
			&& $case['tagValue'] === \wp_styles()->get_data( $style, 'title' );

		return $ctx->result(
			'script-loader-runtime.handle-normalization-duplicate-update',
			$ok,
			self::case_data( $case ) + array(
				'scriptHandle'   => $script,
				'styleHandle'    => $style,
				'scriptArgs'     => \wp_scripts()->args[ $script ] ?? null,
				'styleArgs'      => \wp_styles()->args[ $style ] ?? null,
				'moduleDeps'     => $module_deps,
				'scriptDeps'     => $script_obj instanceof \_WP_Dependency ? $script_obj->deps : null,
				'styleDeps'      => $style_obj instanceof \_WP_Dependency ? $style_obj->deps : null,
				'scriptDataAdds' => $script_add_args,
				'styleDataAdds'  => array(
					'conditional' => $style_conditional,
					'alt'         => $style_alt,
					'title'       => $style_title,
				),
			)
		);
	}

	private static function check_dependency_order_print_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		$base   = $case['handles']['orderBase'];
		$middle = $case['handles']['orderMiddle'];
		$entry  = $case['handles']['orderEntry'];
		$footer = $case['handles']['footer'];

		\wp_register_script( $base, 'runtime/order-base.js', array(), '1.0.0' );
		\wp_register_script( $middle, 'runtime/order-middle.js', array( $base ), '1.0.0' );
		\wp_register_script( $entry, 'runtime/order-entry.js', array( $middle ), '1.0.0' );
		\wp_register_script( $footer, 'runtime/order-footer.js', array( $base ), '1.0.0', array( 'in_footer' => true ) );
		\wp_add_inline_script( $base, 'window.cfOrderBase = 1;', 'before' );
		\wp_add_inline_script( $entry, 'window.cfOrderEntry = 1;' );
		\wp_enqueue_script( array( $entry, $footer ) );

		$head_print   = self::capture_output( static fn() => \print_head_scripts() );
		$footer_print = self::capture_output( static fn() => \print_footer_scripts() );
		$script_done  = \wp_scripts()->done;

		$style_base  = $case['handles']['styleOrderBase'];
		$style_entry = $case['handles']['styleOrderEntry'];
		\wp_register_style( $style_base, 'runtime/order-base.css', array(), '1.0.0' );
		\wp_register_style( $style_entry, 'runtime/order-entry.css', array( $style_base ), '1.0.0' );
		\wp_add_inline_style( $style_entry, '.cf-order-entry { color: #123456; }' );
		\wp_enqueue_style( $style_entry );
		$style_print = self::capture_output( static fn() => \wp_print_styles() );
		$style_done  = \wp_styles()->done;

		$head_positions = array(
			'base'   => self::attribute_position( $head_print['output'], 'id', "{$base}-js" ),
			'middle' => self::attribute_position( $head_print['output'], 'id', "{$middle}-js" ),
			'entry'  => self::attribute_position( $head_print['output'], 'id', "{$entry}-js" ),
			'footer' => self::attribute_position( $head_print['output'], 'id', "{$footer}-js" ),
		);
		$footer_position = self::attribute_position( $footer_print['output'], 'id', "{$footer}-js" );
		$style_positions = array(
			'base'   => self::attribute_position( $style_print['output'], 'id', "{$style_base}-css" ),
			'entry'  => self::attribute_position( $style_print['output'], 'id', "{$style_entry}-css" ),
			'inline' => self::attribute_position( $style_print['output'], 'id', "{$style_entry}-inline-css" ),
		);

		$ok = ! $head_print['threw']
			&& ! $footer_print['threw']
			&& ! $style_print['threw']
			&& array( $base, $middle, $entry, $footer ) === $script_done
			&& false !== $head_positions['base']
			&& false !== $head_positions['middle']
			&& false !== $head_positions['entry']
			&& false === $head_positions['footer']
			&& false !== $footer_position
			&& $head_positions['base'] < $head_positions['middle']
			&& $head_positions['middle'] < $head_positions['entry']
			&& self::contains_in_order( $head_print['output'], array( "{$base}-js-before", "{$base}-js", "{$entry}-js", "{$entry}-js-after" ) )
			&& array( $style_base, $style_entry ) === $style_done
			&& false !== $style_positions['base']
			&& false !== $style_positions['entry']
			&& false !== $style_positions['inline']
			&& $style_positions['base'] < $style_positions['entry']
			&& $style_positions['entry'] < $style_positions['inline'];

		return $ctx->result(
			'script-loader-runtime.dependency-order-print-boundaries',
			$ok,
			self::case_data( $case ) + array(
				'scriptDone'       => $script_done,
				'styleDone'        => $style_done,
				'headPositions'    => $head_positions,
				'footerPosition'   => $footer_position,
				'stylePositions'   => $style_positions,
				'headPreview'      => self::preview( $head_print['output'] ),
				'footerPreview'    => self::preview( $footer_print['output'] ),
				'stylePreview'     => self::preview( $style_print['output'] ),
				'headCall'         => self::describe_call( $head_print ),
				'footerCall'       => self::describe_call( $footer_print ),
				'styleCall'        => self::describe_call( $style_print ),
			)
		);
	}

	private static function check_inline_localization_data( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();
		self::register_default_datepicker_scripts();

		$handle      = $case['handles']['inline'];
		$style       = $case['handles']['inlineStyle'];
		$object_name = $case['objectName'];

		\wp_register_script( $handle, 'runtime/inline.js', array(), '1.0.0' );
		$inline_added = array(
			'beforeScriptTagStripped' => \wp_add_inline_script( $handle, '<script>window.cfBefore = "one";</script>', 'before' ),
			'badPositionBecomesBefore' => \wp_add_inline_script( $handle, 'window.cfSideways = "two";', 'sideways' ),
			'after'                   => \wp_add_inline_script( $handle, 'window.cfAfter = "three";', 'after' ),
			'emptyRejected'           => \wp_add_inline_script( $handle, '', 'after' ),
		);
		$localized    = \wp_localize_script(
			$handle,
			$object_name,
			array(
				'plain'            => 'alpha',
				'hostile'          => $case['tagValue'],
				'entity'           => '&lt;decoded&gt;',
				'nested'           => array( 'left' => $case['token'] ),
				'l10n_print_after' => 'window.cfAfterLocalization = true',
			)
		);
		\wp_enqueue_script( $handle );

		$before_data = \wp_scripts()->get_inline_script_data( $handle, 'before' );
		$after_data  = \wp_scripts()->get_inline_script_data( $handle, 'after' );
		$extra_data  = \wp_scripts()->get_data( $handle, 'data' );
		$printed     = self::capture_output( static fn() => \wp_print_scripts() );
		$output      = $printed['output'];

		\wp_register_style( $style, 'runtime/inline.css', array(), '1.0.0' );
		$style_added = array(
			'styleTagStripped' => \wp_add_inline_style( $style, '<style>.cf-inline { content: "<&>"; }</style>' ),
			'second'           => \wp_add_inline_style( $style, '.cf-inline-two { color: #abcdef; }' ),
			'emptyRejected'    => \wp_add_inline_style( $style, '' ),
		);
		\wp_enqueue_style( $style );
		$style_data  = \wp_styles()->print_inline_style( $style, false );
		$style_print = self::capture_output( static fn() => \wp_print_styles() );

		\wp_enqueue_script( 'jquery-ui-datepicker' );
		$datepicker_call = self::call( static fn() => \wp_localize_jquery_ui_datepicker() );
		$datepicker_data = \wp_scripts()->get_inline_script_data( 'jquery-ui-datepicker', 'after' );

		$positions = array(
			'extra'  => self::attribute_position( $output, 'id', "{$handle}-js-extra" ),
			'before' => self::attribute_position( $output, 'id', "{$handle}-js-before" ),
			'main'   => self::attribute_position( $output, 'id', "{$handle}-js" ),
			'after'  => self::attribute_position( $output, 'id', "{$handle}-js-after" ),
		);
		$style_positions = array(
			'link'  => self::attribute_position( $style_print['output'], 'id', "{$style}-css" ),
			'style' => self::attribute_position( $style_print['output'], 'id', "{$style}-inline-css" ),
		);

		$ok = ! $printed['threw']
			&& ! $style_print['threw']
			&& ! $datepicker_call['threw']
			&& array(
				'beforeScriptTagStripped' => true,
				'badPositionBecomesBefore' => true,
				'after'                   => true,
				'emptyRejected'           => false,
			) === $inline_added
			&& true === $localized
			&& str_contains( $before_data, 'window.cfBefore = "one";' )
			&& str_contains( $before_data, 'window.cfSideways = "two";' )
			&& ! str_contains( $before_data, '<script>' )
			&& str_contains( $after_data, 'window.cfAfter = "three";' )
			&& str_contains( $before_data, rawurlencode( "{$handle}-js-before" ) )
			&& str_contains( $after_data, rawurlencode( "{$handle}-js-after" ) )
			&& is_string( $extra_data )
			&& str_contains( $extra_data, "var {$object_name} = " )
			&& str_contains( $extra_data, '\\u003Ctag' )
			&& str_contains( $extra_data, '\\u003C/tag\\u003E' )
			&& ! str_contains( $extra_data, '<tag>' )
			&& str_contains( $extra_data, 'window.cfAfterLocalization = true;' )
			&& false !== $positions['extra']
			&& false !== $positions['before']
			&& false !== $positions['main']
			&& false !== $positions['after']
			&& $positions['extra'] < $positions['before']
			&& $positions['before'] < $positions['main']
			&& $positions['main'] < $positions['after']
			&& array(
				'styleTagStripped' => true,
				'second'           => true,
				'emptyRejected'    => false,
			) === $style_added
			&& is_string( $style_data )
			&& str_contains( $style_data, '.cf-inline { content: "<&>"; }' )
			&& ! str_contains( $style_data, '<style>' )
			&& false !== $style_positions['link']
			&& false !== $style_positions['style']
			&& $style_positions['link'] < $style_positions['style']
			&& str_contains( $datepicker_data, 'jQuery.datepicker.setDefaults' )
			&& str_contains( $datepicker_data, rawurlencode( 'jquery-ui-datepicker-js-after' ) );

		return $ctx->result(
			'script-loader-runtime.inline-localization-data',
			$ok,
			self::case_data( $case ) + array(
				'handle'          => $handle,
				'objectName'      => $object_name,
				'inlineAdded'     => $inline_added,
				'localized'       => $localized,
				'positions'       => $positions,
				'stylePositions'  => $style_positions,
				'beforeData'      => self::preview( $before_data ),
				'afterData'       => self::preview( $after_data ),
				'extraData'       => self::preview( (string) $extra_data ),
				'styleData'       => self::preview( is_string( $style_data ) ? $style_data : '' ),
				'datepickerData'  => self::preview( $datepicker_data ),
				'outputPreview'   => self::preview( $output ),
				'stylePreview'    => self::preview( $style_print['output'] ),
			)
		);
	}

	private static function check_tag_builders_dataset_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$script_filter_calls = 0;
		$script_filter       = static function ( array $attributes ) use ( &$script_filter_calls, $case ): array {
			++$script_filter_calls;
			$attributes['data-filtered'] = $case['tagValue'];
			$attributes['ID']            = 'duplicate-ignored';
			return $attributes;
		};
		$inline_filter_calls = 0;
		$inline_filter       = static function ( array $attributes, string $data ) use ( &$inline_filter_calls, $case ): array {
			++$inline_filter_calls;
			$attributes['data-inline'] = $case['token'];
			$attributes['data-bytes']  = (string) strlen( $data );
			return $attributes;
		};

		\add_filter( 'wp_script_attributes', $script_filter );
		\add_filter( 'wp_inline_script_attributes', $inline_filter, 10, 2 );

		$tag = \wp_get_script_tag(
			array(
				'id'    => $case['handles']['tag'] . '"<&',
				'src'   => 'runtime/tag.js?x=<tag>&y="quote"',
				'defer' => true,
			)
		);
		$inline_js       = \wp_get_inline_script_tag( 'const payload = "</script><tag>&";', array( 'id' => $case['handles']['tag'] . '-inline' ) );
		$inline_json     = \wp_get_inline_script_tag( '{"value":"</script><tag>&"}', array( 'type' => 'application/json' ) );
		$inline_rejected = \wp_get_inline_script_tag( '</script><tag>', array( 'type' => 'text/plain' ) );
		$printed_script  = self::capture_output( static fn() => \wp_print_script_tag( array( 'src' => 'runtime/printed.js', 'id' => 'printed-runtime' ) ) );
		$printed_inline  = self::capture_output(
			static fn() => \wp_print_inline_script_tag( 'window.printedInline = true;', array( 'id' => 'printed-inline-runtime' ) )
		);

		\remove_filter( 'wp_script_attributes', $script_filter );
		\remove_filter( 'wp_inline_script_attributes', $inline_filter, 10 );

		$dataset_cases = array(
			'postId'      => 'data-post-id',
			'Before'      => 'data--before',
			'-One--Two---' => 'data---one---two---',
			''            => 'data-',
		);
		$dataset_failures = array();
		foreach ( $dataset_cases as $js_name => $html_name ) {
			$actual_html = \wp_html_custom_data_attribute_name( $js_name );
			$actual_js   = \wp_js_dataset_name( $html_name );
			if ( $actual_html !== $html_name || $actual_js !== $js_name ) {
				$dataset_failures[] = array(
					'js'          => $js_name,
					'html'        => $html_name,
					'actualHtml'  => $actual_html,
					'actualJs'    => $actual_js,
				);
			}
		}

		$script_stripped = \wp_remove_surrounding_empty_script_tags( " \n<SCRIPT>sayHello();</SCRIPT>\n" );
		$script_invalid  = \wp_remove_surrounding_empty_script_tags( '<script type="module">sayHello();</script>' );

		$tag_id_values = self::attribute_values( $tag, 'id' );
		$ok = 2 === $script_filter_calls
			&& 4 === $inline_filter_calls
			&& false === \has_filter( 'wp_script_attributes', $script_filter )
			&& false === \has_filter( 'wp_inline_script_attributes', $inline_filter )
			&& 1 === count( $tag_id_values )
			&& str_contains( $tag, 'defer' )
			&& str_contains( $tag, 'data-filtered=' )
			&& ! str_contains( $tag, '<tag>' )
			&& ! str_contains( $tag, '"quote"' )
			&& ( str_contains( $inline_js, '<\\/script>' ) || str_contains( $inline_js, '</\\u0073cript>' ) )
			&& str_contains( $inline_js, 'data-inline=' )
			&& str_contains( $inline_json, 'application/json' )
			&& '' === $inline_rejected
			&& ! $printed_script['threw']
			&& ! $printed_inline['threw']
			&& str_contains( $printed_script['output'], 'printed-runtime' )
			&& str_contains( $printed_inline['output'], 'printed-inline-runtime' )
			&& array() === $dataset_failures
			&& null === \wp_js_dataset_name( 'data bad' )
			&& null === \wp_js_dataset_name( 'post-id' )
			&& null === \wp_html_custom_data_attribute_name( 'no spaces' )
			&& 'sayHello();' === $script_stripped
			&& str_starts_with( $script_invalid, 'console.error(' );

		return $ctx->result(
			'script-loader-runtime.tag-builders-dataset-escaping',
			$ok,
			self::case_data( $case ) + array(
				'scriptFilterCalls' => $script_filter_calls,
				'inlineFilterCalls' => $inline_filter_calls,
				'tagPreview'        => self::preview( $tag ),
				'inlinePreview'     => self::preview( $inline_js ),
				'jsonPreview'       => self::preview( $inline_json ),
				'printedScript'     => self::preview( $printed_script['output'] ),
				'printedInline'     => self::preview( $printed_inline['output'] ),
				'datasetFailures'   => array_slice( $dataset_failures, 0, self::FAILURE_LIMIT ),
				'strippedScript'    => $script_stripped,
				'invalidScript'     => self::preview( $script_invalid ),
			)
		);
	}

	private static function check_script_translations( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		$handle = $case['handles']['translation'];
		$domain = 'cf-runtime-' . $case['token'];
		$json   = \wp_json_encode(
			array(
				'locale_data' => array(
					$domain => array(
						''      => array(
							'domain' => $domain,
							'lang'   => 'en_US',
						),
						'Hello' => array( 'Translated <hello> &' ),
					),
				),
			),
			JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
		);

		$pre_calls      = array();
		$relative_calls = array();
		$pre_filter     = static function ( $translations, $file, $filter_handle, $filter_domain ) use ( &$pre_calls, $handle, $domain, $json ) {
			$pre_calls[] = array(
				'file'   => false === $file ? false : basename( (string) $file ),
				'handle' => $filter_handle,
				'domain' => $filter_domain,
			);
			if ( $handle === $filter_handle && $domain === $filter_domain ) {
				return $json;
			}
			return $translations;
		};
		$relative_filter = static function ( $relative, $src, $is_module ) use ( &$relative_calls ) {
			$relative_calls[] = array(
				'relative' => $relative,
				'src'      => $src,
				'isModule' => $is_module,
			);
			return $relative;
		};

		\add_filter( 'pre_load_script_translations', $pre_filter, 10, 4 );
		\add_filter( 'load_script_textdomain_relative_path', $relative_filter, 10, 3 );

		\wp_register_script( 'wp-i18n', 'runtime/wp-i18n.js', array(), '1.0.0' );
		\wp_register_script( $handle, 'wp-includes/js/dist/runtime-translation.min.js', array(), '1.0.0' );
		$set         = \wp_set_script_translations( $handle, $domain, '' );
		$missing_set = \wp_set_script_translations( $case['handles']['missingTranslation'], $domain, '' );
		$loaded      = \load_script_textdomain( $handle, $domain, '' );
		$translation = \wp_scripts()->print_translations( $handle, false );
		\wp_enqueue_script( $handle );
		$printed = self::capture_output( static fn() => \wp_print_scripts() );

		\remove_filter( 'pre_load_script_translations', $pre_filter, 10 );
		\remove_filter( 'load_script_textdomain_relative_path', $relative_filter, 10 );

		$obj = \wp_scripts()->registered[ $handle ] ?? null;

		$ok = true === $set
			&& false === $missing_set
			&& $json === $loaded
			&& is_string( $translation )
			&& str_contains( $translation, 'wp.i18n.setLocaleData' )
			&& str_contains( $translation, $domain )
			&& ! str_contains( $translation, '<hello>' )
			&& $obj instanceof \_WP_Dependency
			&& 1 === count( array_keys( $obj->deps, 'wp-i18n', true ) )
			&& ! $printed['threw']
			&& self::attribute_position( $printed['output'], 'id', "{$handle}-js-translations" ) !== false
			&& self::attribute_position( $printed['output'], 'id', "{$handle}-js" ) !== false
			&& self::attribute_position( $printed['output'], 'id', 'wp-i18n-js' ) !== false
			&& self::attribute_position( $printed['output'], 'id', 'wp-i18n-js' ) < self::attribute_position( $printed['output'], 'id', "{$handle}-js-translations" )
			&& self::attribute_position( $printed['output'], 'id', "{$handle}-js-translations" ) < self::attribute_position( $printed['output'], 'id', "{$handle}-js" )
			&& false === \has_filter( 'pre_load_script_translations', $pre_filter )
			&& false === \has_filter( 'load_script_textdomain_relative_path', $relative_filter )
			&& count( $pre_calls ) >= 2
			&& count( $relative_calls ) >= 1;

		return $ctx->result(
			'script-loader-runtime.script-translations-filter-locality',
			$ok,
			self::case_data( $case ) + array(
				'handle'          => $handle,
				'domain'          => $domain,
				'set'             => $set,
				'missingSet'      => $missing_set,
				'deps'            => $obj instanceof \_WP_Dependency ? $obj->deps : null,
				'preCalls'        => array_slice( $pre_calls, 0, self::FAILURE_LIMIT ),
				'relativeCalls'   => array_slice( $relative_calls, 0, self::FAILURE_LIMIT ),
				'translation'     => self::preview( is_string( $translation ) ? $translation : '' ),
				'outputPreview'   => self::preview( $printed['output'] ),
			)
		);
	}

	private static function check_emoji_settings_and_styles( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		\add_action( 'wp_print_styles', 'print_emoji_styles' );
		$styles_call        = self::call( static fn() => \wp_enqueue_emoji_styles() );
		$emoji_style_data   = \wp_styles()->print_inline_style( 'wp-emoji-styles', false );
		$style_hook_cleared = false === \has_action( 'wp_print_styles', 'print_emoji_styles' );
		$style_ok           = ! $styles_call['threw']
			&& true === \wp_style_is( 'wp-emoji-styles', 'enqueued' )
			&& is_string( $emoji_style_data )
			&& str_contains( $emoji_style_data, 'img.wp-smiley' )
			&& $style_hook_cleared;

		$loader_path = ABSPATH . WPINC . '/js/wp-emoji-loader' . \wp_scripts_get_suffix() . '.js';
		if ( ! is_readable( $loader_path ) ) {
			$data = self::case_data( $case ) + array(
				'loaderPath'       => $loader_path,
				'styleData'        => self::preview( is_string( $emoji_style_data ) ? $emoji_style_data : '' ),
				'stylesCall'       => self::describe_call( $styles_call ),
				'styleHookCleared' => $style_hook_cleared,
			);

			if ( ! $style_ok ) {
				return $ctx->fail( 'script-loader-runtime.emoji-settings-styles-escaping', $data );
			}

			return $ctx->skip(
				'script-loader-runtime.emoji-settings-styles-escaping',
				'Emoji detection loader asset is unavailable in this source checkout for the active SCRIPT_DEBUG suffix.',
				$data
			);
		}

		$emoji_url_calls = 0;
		$emoji_ext_calls = 0;
		$src_calls       = array();
		$emoji_url       = static function () use ( &$emoji_url_calls, $case ): string {
			++$emoji_url_calls;
			return 'https://emoji.example.test/' . rawurlencode( $case['token'] ) . '/<tag>/';
		};
		$emoji_ext       = static function () use ( &$emoji_ext_calls ): string {
			++$emoji_ext_calls;
			return '.png?<tag>';
		};
		$script_src      = static function ( string $src, string $handle ) use ( &$src_calls ): string {
			$src_calls[] = array(
				'handle' => $handle,
				'src'    => $src,
			);
			return $src;
		};

		\add_filter( 'emoji_url', $emoji_url );
		\add_filter( 'emoji_ext', $emoji_ext );
		\add_filter( 'script_loader_src', $script_src, 10, 2 );

		$printed = self::capture_output( static fn() => \_print_emoji_detection_script() );

		\remove_filter( 'emoji_url', $emoji_url );
		\remove_filter( 'emoji_ext', $emoji_ext );
		\remove_filter( 'script_loader_src', $script_src, 10 );

		$settings_json = self::first_script_text_by_id( $printed['output'], 'wp-emoji-settings' );
		$settings      = is_string( $settings_json ) ? json_decode( $settings_json, true ) : null;

		$ok = ! $printed['threw']
			&& $style_ok
			&& 1 === $emoji_url_calls
			&& 1 === $emoji_ext_calls
			&& count( $src_calls ) >= 1
			&& false === \has_filter( 'emoji_url', $emoji_url )
			&& false === \has_filter( 'emoji_ext', $emoji_ext )
			&& false === \has_filter( 'script_loader_src', $script_src )
			&& is_array( $settings )
			&& isset( $settings['baseUrl'], $settings['ext'], $settings['svgUrl'], $settings['svgExt'], $settings['source'] )
			&& str_contains( $settings['baseUrl'], $case['token'] )
			&& '.png?<tag>' === $settings['ext']
			&& ! str_contains( $printed['output'], '<tag>' )
			&& str_contains( $printed['output'], 'id="wp-emoji-settings"' )
			&& str_contains( $printed['output'], 'type="application/json"' )
			&& str_contains( $printed['output'], 'type="module"' )
			&& true === \wp_style_is( 'wp-emoji-styles', 'enqueued' )
			&& is_string( $emoji_style_data )
			&& str_contains( $emoji_style_data, 'img.wp-smiley' )
			&& $style_hook_cleared;

		return $ctx->result(
			'script-loader-runtime.emoji-settings-styles-escaping',
			$ok,
			self::case_data( $case ) + array(
				'emojiUrlCalls'   => $emoji_url_calls,
				'emojiExtCalls'   => $emoji_ext_calls,
				'scriptSrcCalls'  => array_slice( $src_calls, 0, self::FAILURE_LIMIT ),
				'settings'        => self::preview_array( $settings ),
				'styleData'       => self::preview( is_string( $emoji_style_data ) ? $emoji_style_data : '' ),
				'outputPreview'   => self::preview( $printed['output'] ),
				'printedCall'     => self::describe_call( $printed ),
				'styleHookCleared' => $style_hook_cleared,
			)
		);
	}

	private static function check_style_inlining_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_styles_global();

		$handle = $case['handles']['inlineFileStyle'];
		$path   = self::temp_path( 'script-loader-runtime-style-' . $case['token'] . '.css' );
		$css    = ".cf-inline-file { background: url(images/bg.png); }\n"
			. ".cf-root { background: url(/already-root.png); }\n"
			. ".cf-data { background: url(data:image/png;base64,AAAA); }\n";

		$limit_calls = 0;
		$limit_filter = static function ( int $limit ) use ( &$limit_calls ): int {
			++$limit_calls;
			return max( $limit, 1024 );
		};

		$write = self::call( static fn() => file_put_contents( $path, $css ) );
		\add_filter( 'styles_inline_size_limit', $limit_filter );

		\wp_register_style( $handle, self::CONTENT_URL . '/themes/runtime/style.css', array(), '1.0.0' );
		\wp_style_add_data( $handle, 'path', $path );
		\wp_enqueue_style( $handle );
		$inline_call = self::call( static fn() => \wp_maybe_inline_styles() );
		\remove_filter( 'styles_inline_size_limit', $limit_filter );

		$obj       = \wp_styles()->registered[ $handle ] ?? null;
		$after     = $obj instanceof \_WP_Dependency ? ( $obj->extra['after'] ?? null ) : null;
		$style_out = self::capture_output( static fn() => \wp_print_styles() );

		if ( file_exists( $path ) ) {
			unlink( $path );
		}

		$inlined_css = is_array( $after ) ? implode( "\n", $after ) : '';
		$ok = ! $write['threw']
			&& is_int( $write['value'] )
			&& $write['value'] > 0
			&& ! $inline_call['threw']
			&& 1 === $limit_calls
			&& false === \has_filter( 'styles_inline_size_limit', $limit_filter )
			&& $obj instanceof \_WP_Dependency
			&& false === $obj->src
			&& is_array( $after )
			&& str_contains( $inlined_css, 'url(/wp-content/themes/runtime/images/bg.png)' )
			&& str_contains( $inlined_css, 'url(/already-root.png)' )
			&& str_contains( $inlined_css, 'url(data:image/png;base64,AAAA)' )
			&& self::attribute_position( $style_out['output'], 'id', "{$handle}-inline-css" ) !== false
			&& ! str_contains( $style_out['output'], "{$handle}-css" )
			&& str_contains( $style_out['output'], 'sourceURL=' . self::CONTENT_URL . '/themes/runtime/style.css' );

		return $ctx->result(
			'script-loader-runtime.style-inlining-relative-links',
			$ok,
			self::case_data( $case ) + array(
				'handle'         => $handle,
				'path'           => $path,
				'write'          => self::describe_call( $write ),
				'inlineCall'     => self::describe_call( $inline_call ),
				'limitCalls'     => $limit_calls,
				'registeredSrc'  => $obj instanceof \_WP_Dependency ? $obj->src : null,
				'inlinedCss'     => self::preview( $inlined_css ),
				'outputPreview'  => self::preview( $style_out['output'] ),
			)
		);
	}

	private static function check_block_editor_loader_guards( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();
		\wp_default_styles( \wp_styles() );

		$editor_filter_calls = 0;
		$editor_filter       = static function ( bool $load ) use ( &$editor_filter_calls ): bool {
			++$editor_filter_calls;
			return ! $load;
		};
		$separate_calls      = 0;
		$separate_filter     = static function ( bool $load ) use ( &$separate_calls ): bool {
			++$separate_calls;
			return true;
		};
		$demand_calls        = 0;
		$demand_filter       = static function ( bool $load ) use ( &$demand_calls ): bool {
			++$demand_calls;
			return $load;
		};

		$default_editor = \wp_should_load_block_editor_scripts_and_styles();
		\add_filter( 'should_load_block_editor_scripts_and_styles', $editor_filter );
		$filtered_editor = \wp_should_load_block_editor_scripts_and_styles();
		\remove_filter( 'should_load_block_editor_scripts_and_styles', $editor_filter );

		$default_separate = \wp_should_load_separate_core_block_assets();
		\add_filter( 'should_load_separate_core_block_assets', $separate_filter );
		$filtered_separate = \wp_should_load_separate_core_block_assets();
		\add_filter( 'should_load_block_assets_on_demand', $demand_filter );
		$filtered_demand = \wp_should_load_block_assets_on_demand();

		$common_call = self::call( static fn() => \wp_common_block_scripts_and_styles() );
		$registered_call = self::call( static fn() => \wp_enqueue_registered_block_scripts_and_styles() );

		\remove_filter( 'should_load_separate_core_block_assets', $separate_filter );
		\remove_filter( 'should_load_block_assets_on_demand', $demand_filter );

		$ok = false === $default_editor
			&& true === $filtered_editor
			&& 1 === $editor_filter_calls
			&& false === \has_filter( 'should_load_block_editor_scripts_and_styles', $editor_filter )
			&& false === $default_separate
			&& true === $filtered_separate
			&& true === $filtered_demand
			&& $separate_calls >= 2
			&& $demand_calls >= 1
			&& false === \has_filter( 'should_load_separate_core_block_assets', $separate_filter )
			&& false === \has_filter( 'should_load_block_assets_on_demand', $demand_filter )
			&& ! $common_call['threw']
			&& ! $registered_call['threw']
			&& true === \wp_style_is( 'wp-block-library', 'enqueued' );

		return $ctx->result(
			'script-loader-runtime.block-editor-loader-guards',
			$ok,
			self::case_data( $case ) + array(
				'defaultEditor'    => $default_editor,
				'filteredEditor'   => $filtered_editor,
				'defaultSeparate'  => $default_separate,
				'filteredSeparate' => $filtered_separate,
				'filteredDemand'   => $filtered_demand,
				'editorCalls'      => $editor_filter_calls,
				'separateCalls'    => $separate_calls,
				'demandCalls'      => $demand_calls,
				'styleQueue'       => \wp_styles()->queue,
				'commonCall'       => self::describe_call( $common_call ),
				'registeredCall'   => self::describe_call( $registered_call ),
			)
		);
	}

	private static function check_strategy_module_interactions( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_script_modules_global();

		$dep     = $case['handles']['strategyDep'];
		$entry   = $case['handles']['strategyEntry'];
		$inline  = $case['handles']['strategyInline'];
		$modules = $case['handles']['strategyModules'];

		\wp_register_script( $dep, 'runtime/strategy-dep.js', array(), '1.0.0', array( 'strategy' => 'async', 'fetchpriority' => 'low' ) );
		\wp_register_script( $entry, 'runtime/strategy-entry.js', array( $dep ), '1.0.0', array( 'fetchpriority' => 'high' ) );
		\wp_register_script( $inline, 'runtime/strategy-inline.js', array(), '1.0.0', array( 'strategy' => 'defer' ) );
		\wp_add_inline_script( $inline, 'window.cfInlineAfter = true;', 'after' );
		\wp_register_script(
			$modules,
			'runtime/strategy-modules.js',
			array(),
			'1.0.0',
			array(
				'in_footer'           => true,
				'strategy'            => 'defer',
				'module_dependencies' => array(
					$case['moduleIds'][0],
					array(
						'id'     => $case['moduleIds'][1],
						'import' => 'dynamic',
					),
					array( 'missing-id' => 'ignored' ),
				),
			)
		);

		if ( function_exists( 'wp_register_script_module' ) ) {
			\wp_register_script_module( $case['moduleIds'][0], 'runtime/module-a.js', array(), '1.0.0', array( 'fetchpriority' => 'low' ) );
			\wp_register_script_module( $case['moduleIds'][1], 'runtime/module-b.js', array(), '1.0.0' );
		}

		\wp_enqueue_script( array( $entry, $inline, $modules ) );
		$printed     = self::capture_output( static fn() => \wp_print_scripts() );
		$output      = $printed['output'];
		$module_deps = \wp_scripts()->get_data( $modules, 'module_dependencies' );

		$dep_tag     = self::tag_by_id( $output, "{$dep}-js" );
		$entry_tag   = self::tag_by_id( $output, "{$entry}-js" );
		$inline_tag  = self::tag_by_id( $output, "{$inline}-js" );
		$modules_tag = self::tag_by_id( $output, "{$modules}-js" );

		$ok = ! $printed['threw']
			&& is_array( $module_deps )
			&& 2 === count( $module_deps )
			&& is_string( $dep_tag )
			&& is_string( $entry_tag )
			&& is_string( $inline_tag )
			&& is_string( $modules_tag )
			&& str_contains( $dep_tag, 'data-wp-strategy="async"' )
			&& ! str_contains( $dep_tag, ' async' )
			&& str_contains( $dep_tag, 'fetchpriority="high"' )
			&& str_contains( $dep_tag, 'data-wp-fetchpriority="low"' )
			&& str_contains( $entry_tag, 'fetchpriority="high"' )
			&& str_contains( $inline_tag, 'data-wp-strategy="defer"' )
			&& ! str_contains( $inline_tag, ' defer' )
			&& str_contains( $modules_tag, 'defer' )
			&& str_contains( $modules_tag, 'data-wp-strategy="defer"' )
			&& 1 === ( \wp_scripts()->get_data( $modules, 'group' ) ?? null );

		return $ctx->result(
			'script-loader-runtime.strategy-module-fetchpriority-interactions',
			$ok,
			self::case_data( $case ) + array(
				'dependencyTag' => self::preview( (string) $dep_tag ),
				'entryTag'      => self::preview( (string) $entry_tag ),
				'inlineTag'     => self::preview( (string) $inline_tag ),
				'modulesTag'    => self::preview( (string) $modules_tag ),
				'moduleDeps'    => $module_deps,
				'outputPreview' => self::preview( $output ),
			)
		);
	}

	private static function check_classic_module_import_map_preloads( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		foreach (
			array(
				'wp_register_script_module',
				'wp_enqueue_script_module',
				'wp_script_modules',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				return $ctx->skip(
					'script-loader-runtime.classic-module-import-map-preloads',
					'Script Modules wrapper APIs are unavailable in this WordPress checkout.',
					self::case_data( $case ) + array( 'missingFunction' => $function )
				);
			}
		}

		foreach (
			array(
				'print_enqueued_script_modules',
				'print_import_map',
				'print_script_module_preloads',
			) as $method
		) {
			if ( ! method_exists( \wp_script_modules(), $method ) ) {
				return $ctx->skip(
					'script-loader-runtime.classic-module-import-map-preloads',
					'Script Modules printer APIs are unavailable in this WordPress checkout.',
					self::case_data( $case ) + array( 'missingMethod' => $method )
				);
			}
		}

		self::reset_scripts_global();
		self::reset_script_modules_global();

		$graph     = self::script_module_graph_for_context( $ctx->fork( 'classic-module-import-map-preloads' ), $case );
		$classic   = $case['handles']['classicModuleHost'];
		$src_calls = array();
		$src_filter = static function ( $src, string $id ) use ( &$src_calls ) {
			$src_calls[] = array(
				'id'  => $id,
				'src' => is_string( $src ) ? $src : gettype( $src ),
			);
			return $src;
		};

		\add_filter( 'script_module_loader_src', $src_filter, 10, 2 );

		foreach ( $graph['modules'] as $id => $module ) {
			\wp_register_script_module(
				$id,
				$module['src'],
				$module['dependencies'],
				$module['version'],
				array( 'fetchpriority' => $module['fetchpriority'] )
			);
		}

		\wp_register_script( $classic, 'runtime/classic-module-host.js', array(), '1.0.0' );
		$classic_added = \wp_script_add_data( $classic, 'module_dependencies', $graph['classicDependencies'] );
		\wp_enqueue_script( $classic );

		foreach ( $graph['queue'] as $id ) {
			\wp_enqueue_script_module( $id );
		}

		$import_map_print = self::capture_output( static fn() => \wp_script_modules()->print_import_map() );
		$preload_print    = self::capture_output( static fn() => \wp_script_modules()->print_script_module_preloads() );
		$module_print     = self::capture_output( static fn() => \wp_script_modules()->print_enqueued_script_modules() );

		\remove_filter( 'script_module_loader_src', $src_filter, 10 );

		$import_map_json = self::first_script_text_by_id( $import_map_print['output'], 'wp-importmap' );
		$import_map      = is_string( $import_map_json ) ? json_decode( $import_map_json, true ) : null;
		$actual_imports  = is_array( $import_map ) && isset( $import_map['imports'] ) && is_array( $import_map['imports'] )
			? $import_map['imports']
			: array();

		$expected_imports = array();
		foreach ( $graph['expectedImportIds'] as $id ) {
			$expected_imports[ $id ] = self::expected_script_module_src( $graph['modules'][ $id ] );
		}

		$sorted_expected_imports = $expected_imports;
		$sorted_actual_imports   = $actual_imports;
		ksort( $sorted_expected_imports );
		ksort( $sorted_actual_imports );

		$preload_ids = self::element_ids( $preload_print['output'], 'link' );
		$module_ids  = self::element_ids( $module_print['output'], 'script' );

		$expected_preload_element_ids = array_map(
			static fn( string $id ): string => "{$id}-js-modulepreload",
			$graph['expectedPreloadIds']
		);
		$expected_module_element_ids  = array_map(
			static fn( string $id ): string => "{$id}-js-module",
			$graph['queue']
		);

		$sorted_preload_ids          = $preload_ids;
		$sorted_expected_preload_ids = $expected_preload_element_ids;
		$sorted_module_ids           = $module_ids;
		$sorted_expected_module_ids  = $expected_module_element_ids;
		sort( $sorted_preload_ids );
		sort( $sorted_expected_preload_ids );
		sort( $sorted_module_ids );
		sort( $sorted_expected_module_ids );

		$preload_failures = array();
		foreach ( $graph['expectedPreloadIds'] as $id ) {
			$module       = $graph['modules'][ $id ];
			$tag          = self::link_tag_by_id( $preload_print['output'], "{$id}-js-modulepreload" );
			$actual_high  = $graph['expectedPreloadFetchpriority'];
			$expected_src = self::expected_printed_script_module_src( $module );
			$expected_data = self::expected_original_fetchpriority_attribute(
				$module['fetchpriority'],
				$actual_high
			);

			if (
				! is_string( $tag )
				|| array( 'modulepreload' ) !== self::attribute_values( $tag, 'rel' )
				|| array( $expected_src ) !== self::attribute_values( $tag, 'href' )
				|| self::expected_attribute_values( $actual_high, 'auto' ) !== self::attribute_values( $tag, 'fetchpriority' )
				|| self::expected_attribute_values( $expected_data, null ) !== self::attribute_values( $tag, 'data-wp-fetchpriority' )
			) {
				$preload_failures[] = array(
					'id'           => $id,
					'tag'          => self::preview( (string) $tag ),
					'expectedSrc'  => $expected_src,
					'expectedHigh' => $actual_high,
					'expectedData' => $expected_data,
				);
			}
		}

		$module_failures = array();
		foreach ( $graph['queue'] as $id ) {
			$module       = $graph['modules'][ $id ];
			$tag          = self::tag_by_id( $module_print['output'], "{$id}-js-module" );
			$expected_src = self::expected_printed_script_module_src( $module );

			if (
				! is_string( $tag )
				|| array( 'module' ) !== self::attribute_values( $tag, 'type' )
				|| array( $expected_src ) !== self::attribute_values( $tag, 'src' )
				|| self::expected_attribute_values( $module['fetchpriority'], 'auto' ) !== self::attribute_values( $tag, 'fetchpriority' )
				|| array() !== self::attribute_values( $tag, 'data-wp-fetchpriority' )
			) {
				$module_failures[] = array(
					'id'          => $id,
					'tag'         => self::preview( (string) $tag ),
					'expectedSrc' => $expected_src,
					'priority'    => $module['fetchpriority'],
				);
			}
		}

		$negative_ids = array_merge(
			$graph['expectedImportOnlyIds'],
			array( $graph['ids']['queuedDynamic'] )
		);
		$negative_preloads_ok = true;
		foreach ( $negative_ids as $id ) {
			if ( in_array( "{$id}-js-modulepreload", $preload_ids, true ) ) {
				$negative_preloads_ok = false;
				break;
			}
		}

		$queued_absent_from_import_map = array();
		foreach ( $graph['queue'] as $id ) {
			if ( isset( $actual_imports[ $id ] ) ) {
				$queued_absent_from_import_map[] = $id;
			}
		}

		$source_filter_ids = array_values( array_unique( array_column( $src_calls, 'id' ) ) );
		$expected_src_ids  = array_values(
			array_unique(
				array_merge(
					$graph['expectedImportIds'],
					$graph['expectedPreloadIds'],
					$graph['queue']
				)
			)
		);
		$missing_src_filter_ids = array_values( array_diff( $expected_src_ids, $source_filter_ids ) );

		$preload_order_ok = self::attribute_position( $preload_print['output'], 'id', $graph['ids']['queuedLeaf'] . '-js-modulepreload' )
			< self::attribute_position( $preload_print['output'], 'id', $graph['ids']['queuedShared'] . '-js-modulepreload' );
		$module_order_ok  = self::contains_in_order(
			$module_print['output'],
			array(
				$graph['ids']['queuedEntry'] . '-js-module',
				$graph['ids']['queuedPeer'] . '-js-module',
				$graph['ids']['queuedDirect'] . '-js-module',
			)
		);

		$ok = ! $import_map_print['threw']
			&& ! $preload_print['threw']
			&& ! $module_print['threw']
			&& true === $classic_added
			&& $sorted_expected_imports === $sorted_actual_imports
			&& $sorted_expected_preload_ids === $sorted_preload_ids
			&& $sorted_expected_module_ids === $sorted_module_ids
			&& array() === $preload_failures
			&& array() === $module_failures
			&& $negative_preloads_ok
			&& array() === $queued_absent_from_import_map
			&& array() === $missing_src_filter_ids
			&& false === \has_filter( 'script_module_loader_src', $src_filter )
			&& $preload_order_ok
			&& $module_order_ok;

		return $ctx->result(
			'script-loader-runtime.classic-module-import-map-preloads',
			$ok,
			self::case_data( $case ) + array(
				'classicHandle'             => $classic,
				'classicAdded'              => $classic_added,
				'expectedImportIds'         => $graph['expectedImportIds'],
				'actualImportIds'           => array_keys( $actual_imports ),
				'expectedPreloadIds'        => $expected_preload_element_ids,
				'actualPreloadIds'          => $preload_ids,
				'expectedModuleIds'         => $expected_module_element_ids,
				'actualModuleIds'           => $module_ids,
				'preloadFailures'           => array_slice( $preload_failures, 0, self::FAILURE_LIMIT ),
				'moduleFailures'            => array_slice( $module_failures, 0, self::FAILURE_LIMIT ),
				'queuedAbsentFromImportMap' => $queued_absent_from_import_map,
				'missingSrcFilterIds'       => $missing_src_filter_ids,
				'sourceFilterCalls'         => array_slice( $src_calls, 0, self::FAILURE_LIMIT ),
				'preloadOrderOk'            => $preload_order_ok,
				'moduleOrderOk'             => $module_order_ok,
				'importMapPreview'          => self::preview( $import_map_print['output'] ),
				'preloadPreview'            => self::preview( $preload_print['output'] ),
				'modulePreview'             => self::preview( $module_print['output'] ),
			)
		);
	}

	private static function check_jit_script_localization( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! defined( 'AUTOSAVE_INTERVAL' ) ) {
			return $ctx->skip(
				'script-loader-runtime.jit-script-localization',
				'AUTOSAVE_INTERVAL is not defined in the stripped no-DB bootstrap; calling the helper would fatal before exercising script-loader state.',
				self::case_data( $case )
			);
		}

		self::reset_scripts_global();

		$GLOBALS['shortcode_tags'] = array(
			'gallery'          => static fn() => '',
			$case['shortcode'] => static fn() => '',
		);

		foreach ( array( 'autosave', 'mce-view', 'word-count' ) as $handle ) {
			\wp_register_script( $handle, "runtime/{$handle}.js", array(), '1.0.0' );
		}

		$call = self::call( static fn() => \wp_just_in_time_script_localization() );

		$autosave = \wp_scripts()->get_data( 'autosave', 'data' );
		$mce_view = \wp_scripts()->get_data( 'mce-view', 'data' );
		$word_count = \wp_scripts()->get_data( 'word-count', 'data' );

		$ok = ! $call['threw']
			&& is_string( $autosave )
			&& is_string( $mce_view )
			&& is_string( $word_count )
			&& str_contains( $autosave, 'autosaveInterval' )
			&& str_contains( $mce_view, $case['shortcode'] )
			&& str_contains( $word_count, $case['shortcode'] );

		return $ctx->result(
			'script-loader-runtime.jit-script-localization',
			$ok,
			self::case_data( $case ) + array(
				'shortcode' => $case['shortcode'],
				'autosave'  => self::preview( is_string( $autosave ) ? $autosave : '' ),
				'mceView'   => self::preview( is_string( $mce_view ) ? $mce_view : '' ),
				'wordCount' => self::preview( is_string( $word_count ) ? $word_count : '' ),
				'call'      => self::describe_call( $call ),
			)
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token  = $ctx->iteration() . '-' . substr( sha1( (string) $ctx->seed() ), 0, 8 );
		$prefix = 'cf-slr-' . $token;
		$tag_value = '<tag attr="value&' . $ctx->int( 0, 999 ) . '">"quoted"&</tag>';

		return array(
			'token'      => $token,
			'prefix'     => $prefix,
			'tagValue'   => $tag_value,
			'scriptSrcA' => 'runtime/' . rawurlencode( $prefix ) . '-one.js?x=1#frag',
			'scriptSrcB' => 'runtime/' . rawurlencode( $prefix ) . '-two.js?x=2#frag',
			'styleSrcA'  => 'runtime/' . rawurlencode( $prefix ) . '-one.css?x=1#frag',
			'styleSrcB'  => 'runtime/' . rawurlencode( $prefix ) . '-two.css?x=2#frag',
			'objectName' => 'cfRuntime' . str_replace( '-', '_', $token ),
			'shortcode'  => 'cf_shortcode_' . str_replace( '-', '_', $token ),
			'moduleIds'  => array(
				'@component-fuzz/runtime/' . $token . '/alpha',
				'@component-fuzz/runtime/' . $token . '/beta',
			),
			'handles'    => array(
				'dep'                => "{$prefix}-dep",
				'styleDep'           => "{$prefix}-style-dep",
				'script'             => "{$prefix}-script",
				'style'              => "{$prefix}-style",
				'orderBase'          => "{$prefix}-order-base",
				'orderMiddle'        => "{$prefix}-order-middle",
				'orderEntry'         => "{$prefix}-order-entry",
				'footer'             => "{$prefix}-footer",
				'styleOrderBase'     => "{$prefix}-style-order-base",
				'styleOrderEntry'    => "{$prefix}-style-order-entry",
				'inline'             => "{$prefix}-inline",
				'inlineStyle'        => "{$prefix}-inline-style",
				'tag'                => "{$prefix}-tag",
				'translation'        => "{$prefix}-translation",
				'missingTranslation' => "{$prefix}-missing-translation",
				'inlineFileStyle'    => "{$prefix}-inline-file-style",
				'strategyDep'        => "{$prefix}-strategy-dep",
				'strategyEntry'      => "{$prefix}-strategy-entry",
				'strategyInline'     => "{$prefix}-strategy-inline",
				'strategyModules'    => "{$prefix}-strategy-modules",
				'classicModuleHost'  => "{$prefix}-classic-module-host",
			),
		);
	}

	private static function case_data( array $case ): array {
		return array(
			'token'   => $case['token'],
			'prefix'  => $case['prefix'],
			'handles' => array_values( $case['handles'] ),
		);
	}

	private static function prepare_runtime_globals(): void {
		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['init'] = max( 1, (int) ( $GLOBALS['wp_actions']['init'] ?? 0 ) );
		$GLOBALS['concatenate_scripts'] = false;
		$GLOBALS['compress_scripts']    = false;
		$GLOBALS['compress_css']        = false;
		$GLOBALS['pagenow']             = 'index.php';
		self::reset_scripts_global();
		self::reset_styles_global();
		self::reset_script_modules_global();
	}

	private static function reset_scripts_global(): void {
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		\wp_scripts()->base_url        = self::BASE_URL;
		\wp_scripts()->content_url     = self::CONTENT_URL;
		\wp_scripts()->default_version = self::DEFAULT_VERSION;
		\wp_scripts()->default_dirs    = array();
		$GLOBALS['concatenate_scripts'] = false;
		$GLOBALS['compress_scripts']    = false;
	}

	private static function reset_styles_global(): void {
		$GLOBALS['wp_styles'] = new \WP_Styles();
		\wp_styles()->base_url        = self::BASE_URL;
		\wp_styles()->content_url     = self::CONTENT_URL;
		\wp_styles()->default_version = self::DEFAULT_VERSION;
		\wp_styles()->default_dirs    = array();
		$GLOBALS['concatenate_scripts'] = false;
		$GLOBALS['compress_css']        = false;
	}

	private static function reset_script_modules_global(): void {
		if ( class_exists( 'WP_Script_Modules' ) ) {
			$GLOBALS['wp_script_modules'] = new \WP_Script_Modules();
		}
	}

	private static function register_default_datepicker_scripts(): void {
		\wp_register_script( 'jquery', false, array( 'jquery-core', 'jquery-migrate' ), '1.0.0' );
		\wp_register_script( 'jquery-core', 'runtime/jquery.js', array(), '1.0.0' );
		\wp_register_script( 'jquery-migrate', 'runtime/jquery-migrate.js', array( 'jquery-core' ), '1.0.0' );
		\wp_register_script( 'jquery-ui-core', 'runtime/jquery-ui-core.js', array( 'jquery' ), '1.0.0' );
		\wp_register_script( 'jquery-ui-datepicker', 'runtime/jquery-ui-datepicker.js', array( 'jquery-ui-core' ), '1.0.0' );
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

	private static function capture_output( callable $callback ): array {
		$level = ob_get_level();
		ob_start();
		try {
			$value  = $callback();
			$output = ob_get_clean();
			return array(
				'threw'  => false,
				'value'  => $value,
				'output' => $output,
			);
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
				'output'    => '',
			);
		}
	}

	private static function describe_call( array $call ): array {
		return array(
			'threw'     => (bool) ( $call['threw'] ?? false ),
			'value'     => self::describe_value( $call['value'] ?? null ),
			'throwable' => $call['throwable'] ?? null,
		);
	}

	private static function describe_value( $value ) {
		if ( is_array( $value ) ) {
			return array(
				'type'  => 'array',
				'count' => count( $value ),
				'value' => self::preview_array( $value ),
			);
		}
		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}
		return $value;
	}

	private static function dependency_summary( $dependency ): ?array {
		if ( ! $dependency instanceof \_WP_Dependency ) {
			return null;
		}

		return array(
			'src'  => self::preview( is_string( $dependency->src ) ? $dependency->src : ( true === $dependency->src ? '[true]' : '[false]' ) ),
			'deps' => $dependency->deps,
			'ver'  => self::describe_value( $dependency->ver ),
			'args' => self::describe_value( $dependency->args ),
		);
	}

	private static function preview_array( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $encoded ) {
			return '[array]';
		}

		return self::preview( $encoded, self::PREVIEW_BYTES );
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function first_script_text_by_id( string $html, string $id ): ?string {
		if ( ! preg_match( '/<script\b(?=[^>]*\bid=(["\'])' . preg_quote( $id, '/' ) . '\1)[^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return null;
		}

		return html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function tag_by_id( string $html, string $id ): ?string {
		if ( ! preg_match_all( '/<script\b[^>]*>.*?<\/script>/is', $html, $matches ) ) {
			return null;
		}

		foreach ( $matches[0] as $tag ) {
			if ( self::attribute_position( $tag, 'id', $id ) !== false ) {
				return $tag;
			}
		}

		return null;
	}

	private static function link_tag_by_id( string $html, string $id ): ?string {
		foreach ( self::opening_tags( $html, 'link' ) as $tag ) {
			if ( self::attribute_position( $tag, 'id', $id ) !== false ) {
				return $tag;
			}
		}

		return null;
	}

	private static function element_ids( string $html, string $tag_name ): array {
		$ids = array();
		foreach ( self::opening_tags( $html, $tag_name ) as $tag ) {
			$values = self::attribute_values( $tag, 'id' );
			if ( array() !== $values ) {
				$ids[] = $values[0];
			}
		}
		return $ids;
	}

	private static function opening_tags( string $html, string $tag_name ): array {
		if ( ! preg_match_all( '/<' . preg_quote( $tag_name, '/' ) . '\b[^>]*>/i', $html, $matches ) ) {
			return array();
		}

		return $matches[0];
	}

	private static function attribute_values( string $html, string $attribute ): array {
		if ( ! preg_match_all( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $html, $matches ) ) {
			return array();
		}

		return array_map(
			static fn( string $value ): string => self::decode_html_attribute( $value ),
			$matches[2]
		);
	}

	private static function attribute_position( string $html, string $attribute, string $decoded_value ) {
		if ( ! preg_match_all( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		foreach ( $matches[2] as $index => $match ) {
			if ( $decoded_value === self::decode_html_attribute( $match[0] ) ) {
				return $matches[0][ $index ][1];
			}
		}

		return false;
	}

	private static function decode_html_attribute( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function contains_in_order( string $haystack, array $needles ): bool {
		$offset = 0;
		foreach ( $needles as $needle ) {
			$pos = strpos( $haystack, $needle, $offset );
			if ( false === $pos ) {
				return false;
			}
			$offset = $pos + strlen( $needle );
		}
		return true;
	}

	private static function script_module_graph_for_context( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$base = '@component-fuzz/runtime/' . $case['token'];
		$ids  = array(
			'classicEntry'         => "{$base}/classic-entry",
			'classicStatic'        => "{$base}/classic-static",
			'classicLeaf'          => "{$base}/classic-leaf",
			'classicNestedDynamic' => "{$base}/classic-nested-dynamic",
			'classicDirectDynamic' => "{$base}/classic-direct-dynamic",
			'queuedEntry'          => "{$base}/queued-entry",
			'queuedPeer'           => "{$base}/queued-peer",
			'queuedDirect'         => "{$base}/queued-direct",
			'queuedShared'         => "{$base}/queued-shared",
			'queuedLeaf'           => "{$base}/queued-leaf",
			'queuedDynamic'        => "{$base}/queued-dynamic",
		);

		$entry_is_high  = $ctx->bool();
		$entry_priority = $entry_is_high ? 'high' : $ctx->choice( array( 'auto', 'low' ) );
		$peer_priority  = $entry_is_high ? $ctx->choice( array( 'auto', 'low' ) ) : 'high';

		$modules = array(
			$ids['classicEntry']         => array(
				'src'           => self::script_module_src( $case['prefix'], 'classic-entry', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(
					self::script_module_dependency( $ids['classicStatic'], 'static', $ctx->bool() ),
					self::script_module_dependency( $ids['classicNestedDynamic'], 'dynamic', true ),
				),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low', 'high' ) ),
			),
			$ids['classicStatic']        => array(
				'src'           => self::script_module_src( $case['prefix'], 'classic-static', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(
					self::script_module_dependency( $ids['classicLeaf'], 'static', $ctx->bool() ),
				),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low', 'high' ) ),
			),
			$ids['classicLeaf']          => array(
				'src'           => self::script_module_src( $case['prefix'], 'classic-leaf', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low', 'high' ) ),
			),
			$ids['classicNestedDynamic'] => array(
				'src'           => self::script_module_src( $case['prefix'], 'classic-nested-dynamic', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low', 'high' ) ),
			),
			$ids['classicDirectDynamic'] => array(
				'src'           => self::script_module_src( $case['prefix'], 'classic-direct-dynamic', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low', 'high' ) ),
			),
			$ids['queuedEntry']          => array(
				'src'           => self::script_module_src( $case['prefix'], 'queued-entry', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(
					self::script_module_dependency( $ids['queuedShared'], 'static', $ctx->bool() ),
					self::script_module_dependency( $ids['queuedDynamic'], 'dynamic', true ),
				),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $entry_priority,
			),
			$ids['queuedPeer']           => array(
				'src'           => self::script_module_src( $case['prefix'], 'queued-peer', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(
					self::script_module_dependency( $ids['queuedShared'], 'static', $ctx->bool() ),
				),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $peer_priority,
			),
			$ids['queuedDirect']         => array(
				'src'           => self::script_module_src( $case['prefix'], 'queued-direct', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low', 'high' ) ),
			),
			$ids['queuedShared']         => array(
				'src'           => self::script_module_src( $case['prefix'], 'queued-shared', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(
					self::script_module_dependency( $ids['queuedLeaf'], 'static', $ctx->bool() ),
				),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low' ) ),
			),
			$ids['queuedLeaf']           => array(
				'src'           => self::script_module_src( $case['prefix'], 'queued-leaf', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low' ) ),
			),
			$ids['queuedDynamic']        => array(
				'src'           => self::script_module_src( $case['prefix'], 'queued-dynamic', $ctx->int( 0, 3 ) ),
				'dependencies'  => array(),
				'version'       => self::script_module_version( $ctx ),
				'fetchpriority' => $ctx->choice( array( 'auto', 'low', 'high' ) ),
			),
		);

		return array(
			'ids'                          => $ids,
			'modules'                      => $modules,
			'classicDependencies'          => array(
				self::script_module_dependency( $ids['classicEntry'], 'static', $ctx->bool() ),
				self::script_module_dependency( $ids['classicDirectDynamic'], 'dynamic', true ),
			),
			'queue'                        => array(
				$ids['queuedEntry'],
				$ids['queuedPeer'],
				$ids['queuedDirect'],
			),
			'expectedImportIds'            => array(
				$ids['classicEntry'],
				$ids['classicDirectDynamic'],
				$ids['classicStatic'],
				$ids['classicLeaf'],
				$ids['classicNestedDynamic'],
				$ids['queuedShared'],
				$ids['queuedLeaf'],
				$ids['queuedDynamic'],
			),
			'expectedImportOnlyIds'        => array(
				$ids['classicEntry'],
				$ids['classicDirectDynamic'],
				$ids['classicStatic'],
				$ids['classicLeaf'],
				$ids['classicNestedDynamic'],
			),
			'expectedPreloadIds'           => array(
				$ids['queuedLeaf'],
				$ids['queuedShared'],
			),
			'expectedPreloadFetchpriority' => self::highest_fetchpriority(
				array(
					$entry_priority,
					$peer_priority,
				)
			),
		);
	}

	private static function script_module_src( string $prefix, string $label, int $variant ): string {
		$file = rawurlencode( "{$prefix}-{$label}" ) . '.js';
		if ( 1 === $variant ) {
			return "runtime/modules/{$file}?asset=" . rawurlencode( $label );
		}
		if ( 2 === $variant ) {
			return "runtime/modules/{$file}#" . rawurlencode( $label );
		}
		if ( 3 === $variant ) {
			return self::CONTENT_URL . "/component-fuzz/modules/{$file}?asset=absolute";
		}
		return "runtime/modules/{$file}";
	}

	private static function script_module_dependency( string $id, string $import, bool $as_array ) {
		if ( 'static' === $import && ! $as_array ) {
			return $id;
		}

		return array(
			'id'     => $id,
			'import' => $import,
		);
	}

	private static function script_module_version( \ComponentFuzz\FuzzContext $ctx ) {
		$choice = $ctx->int( 0, 2 );
		if ( 0 === $choice ) {
			return null;
		}
		if ( 1 === $choice ) {
			return false;
		}
		return 'runtime-' . $ctx->int( 1, 999 );
	}

	private static function expected_script_module_src( array $module ): string {
		$src     = $module['src'];
		$version = $module['version'];

		if ( '' === $src || null === $version ) {
			return $src;
		}

		$ver = false === $version ? \get_bloginfo( 'version' ) : $version;
		return \add_query_arg( 'ver', $ver, $src );
	}

	private static function expected_printed_script_module_src( array $module ): string {
		return self::decode_html_attribute( \esc_url( self::expected_script_module_src( $module ) ) );
	}

	private static function highest_fetchpriority( array $priorities ): string {
		$order         = array( 'low', 'auto', 'high' );
		$highest_index = 0;
		foreach ( $priorities as $priority ) {
			$index = array_search( $priority, $order, true );
			if ( false !== $index ) {
				$highest_index = max( $highest_index, (int) $index );
			}
		}
		return $order[ $highest_index ];
	}

	private static function expected_original_fetchpriority_attribute( string $original, string $actual ): ?string {
		if ( $actual !== $original && 'auto' !== $original ) {
			return $original;
		}
		return null;
	}

	private static function expected_attribute_values( ?string $value, ?string $empty_value ): array {
		if ( $value === $empty_value || null === $value ) {
			return array();
		}
		return array( $value );
	}

	private static function temp_path( string $filename ): string {
		return rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $filename;
	}

	private static function preview( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$printable = preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
			static function ( array $m ): string {
				return sprintf( '\\x%02X', ord( $m[0] ) );
			},
			$value
		);

		if ( strlen( $printable ) > $limit ) {
			return substr( $printable, 0, $limit ) . '...';
		}

		return $printable;
	}

	private static function snapshot_globals(): array {
		$snapshot = array();
		foreach (
			array(
				'concatenate_scripts',
				'compress_css',
				'compress_scripts',
				'current_screen',
				'pagenow',
				'shortcode_tags',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_script_modules',
				'wp_scripts',
				'wp_styles',
			) as $name
		) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
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
}
