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
			$rows[] = self::check_concat_runtime_boundaries( $ctx, $case );
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
				'json_decode',
				'json_encode',
				'proc_close',
				'proc_open',
				'random_bytes',
				'stream_get_contents',
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

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
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

	private static function check_concat_runtime_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		$script_ctx          = $ctx->fork( 'concat-runtime-scripts' );
		$script_total        = $script_ctx->int( 5, 8 );
		$script_concat_count = $script_total - 2;
		$script_default_dir  = '/wp-admin/js/component-fuzz/';
		$script_prefix       = $case['prefix'] . '-concat-script';
		$script_base_url     = rtrim( self::BASE_URL, '/' );
		$script_objects      = array();
		$script_handles      = array();

		$scripts                  = \wp_scripts();
		$scripts->base_url        = $script_base_url;
		$scripts->default_dirs    = array( $script_default_dir );
		$scripts->do_concat       = true;
		$GLOBALS['concatenate_scripts'] = true;
		$GLOBALS['compress_scripts']    = false;

		for ( $i = 0; $i < $script_concat_count; $i++ ) {
			$handle           = "{$script_prefix}-{$i}";
			$script_handles[] = $handle;
			$script_objects[] = 'cfConcatScript' . str_replace( '-', '_', $case['token'] ) . "_{$i}";
			$deps             = 0 === $i ? array() : array( $script_handles[ $i - 1 ] );

			\wp_register_script( $handle, "{$script_default_dir}{$handle}.js", $deps, null );
			\wp_localize_script(
				$handle,
				$script_objects[ $i ],
				array(
					'handle' => $handle,
					'token'  => $case['token'],
				)
			);
		}

		$script_external = "{$script_prefix}-external";
		$script_delayed  = "{$script_prefix}-delayed";
		$strategy        = $script_ctx->choice( array( 'defer', 'async' ) );
		$script_external_src = 'https://cdn.example.test/component-fuzz/' . rawurlencode( $script_external ) . '.js';
		$script_delayed_src  = $script_base_url . "{$script_default_dir}{$script_delayed}.js";

		\wp_register_script( $script_external, $script_external_src, array(), null );
		\wp_register_script( $script_delayed, "{$script_default_dir}{$script_delayed}.js", array(), null, array( 'strategy' => $strategy ) );
		$scripts->enqueue( array( $script_handles[ $script_concat_count - 1 ], $script_external, $script_delayed ) );

		$script_print  = self::capture_output(
			static function (): void {
				\wp_print_scripts();
				\_print_scripts();
			}
		);
		$script_output = $script_print['output'];
		$script_loader = self::loader_query_details( $script_output, 'script', 'src', '/wp-admin/load-scripts.php' );
		$script_done   = $scripts->done;

		$script_external_tag = self::tag_by_id( $script_output, "{$script_external}-js" );
		$script_delayed_tag  = self::tag_by_id( $script_output, "{$script_delayed}-js" );
		$script_expected_list = implode( ',', $script_handles );
		$script_source_url    = rawurlencode( 'js-inline-concat-' . $script_expected_list );
		$script_loader_pos    = strpos( $script_output, 'load-scripts.php' );
		$script_source_pos    = strpos( $script_output, $script_source_url );

		$style_ctx          = $ctx->fork( 'concat-runtime-styles' );
		$style_total        = $style_ctx->int( 4, 8 );
		$style_concat_count = $style_total - 2;
		$style_default_dir  = '/wp-admin/css/component-fuzz/';
		$style_prefix       = $case['prefix'] . '-concat-style';
		$style_handles      = array();
		$style_dir          = $style_ctx->choice( array( 'ltr', 'rtl' ) );

		$styles                 = \wp_styles();
		$styles->base_url       = rtrim( self::BASE_URL, '/' );
		$styles->default_dirs   = array( $style_default_dir );
		$styles->do_concat      = true;
		$styles->text_direction = $style_dir;
		$GLOBALS['concatenate_scripts'] = true;
		$GLOBALS['compress_css']        = false;

		for ( $i = 0; $i < $style_concat_count; $i++ ) {
			$handle          = "{$style_prefix}-{$i}";
			$style_handles[] = $handle;
			$deps            = 0 === $i ? array() : array( $style_handles[ $i - 1 ] );

			\wp_register_style( $handle, "{$style_default_dir}{$handle}.css", $deps, null );
			\wp_add_inline_style( $handle, ".{$handle} { --cf-token: \"" . \esc_attr( $case['token'] ) . "\"; }" );
		}

		$style_external = "{$style_prefix}-external";
		$style_alt      = "{$style_prefix}-alt";
		$style_external_src = 'https://cdn.example.test/component-fuzz/' . rawurlencode( $style_external ) . '.css';
		$style_alt_href     = rtrim( self::BASE_URL, '/' ) . "{$style_default_dir}{$style_alt}.css";

		\wp_register_style( $style_external, $style_external_src, array(), null, 'screen' );
		\wp_add_inline_style( $style_external, ".{$style_external} { color: #123456; }" );
		\wp_register_style( $style_alt, "{$style_default_dir}{$style_alt}.css", array(), null, 'print' );
		\wp_style_add_data( $style_alt, 'alt', true );
		\wp_style_add_data( $style_alt, 'title', 'Component fuzz concat alt' );
		$styles->enqueue( array( $style_handles[ $style_concat_count - 1 ], $style_external, $style_alt ) );

		$style_print  = self::capture_output(
			static function (): void {
				\wp_print_styles();
				\_print_styles();
			}
		);
		$style_output = $style_print['output'];
		$style_loader = self::loader_query_details( $style_output, 'link', 'href', '/wp-admin/load-styles.php' );
		$style_done   = $styles->done;

		$style_external_tag        = self::link_tag_by_id( $style_output, "{$style_external}-css" );
		$style_external_inline_tag = self::opening_tag_by_id( $style_output, 'style', "{$style_external}-inline-css" );
		$style_alt_tag             = self::link_tag_by_id( $style_output, "{$style_alt}-css" );
		$style_expected_list       = implode( ',', $style_handles );
		$style_source_url          = rawurlencode( 'css-inline-concat-' . $style_expected_list );
		$style_loader_pos          = strpos( $style_output, 'load-styles.php' );
		$style_source_pos          = strpos( $style_output, $style_source_url );

		self::reset_scripts_global();
		self::reset_styles_global();
		$local_cleanup = false === ( $GLOBALS['concatenate_scripts'] ?? null )
			&& false === ( $GLOBALS['compress_scripts'] ?? null )
			&& false === ( $GLOBALS['compress_css'] ?? null )
			&& false === \wp_scripts()->do_concat
			&& false === \wp_styles()->do_concat;

		$script_checks = array(
			'print-call'              => ! $script_print['threw'],
			'loader-present'          => array() !== $script_loader,
			'loader-path'             => '/wp-admin/load-scripts.php' === substr( (string) ( $script_loader['path'] ?? '' ), -strlen( '/wp-admin/load-scripts.php' ) ),
			'loader-query'            => '0' === ( $script_loader['query']['c'] ?? null ) && self::DEFAULT_VERSION === ( $script_loader['query']['ver'] ?? null ),
			'loader-chunks'           => str_split( $script_expected_list, 128 ) === $script_loader['chunks'],
			'loader-handles'          => $script_handles === $script_loader['handles'],
			'excluded-handles'        => array() === array_values( array_intersect( $script_loader['handles'], array( $script_external, $script_delayed ) ) ),
			'separate-tags'           => is_string( $script_external_tag ) && is_string( $script_delayed_tag ),
			'external-tag'            => is_string( $script_external_tag ) && array( $script_external_src ) === self::attribute_values( $script_external_tag, 'src' ),
			'strategy-tag'            => is_string( $script_delayed_tag ) && array( $script_delayed_src ) === self::attribute_values( $script_delayed_tag, 'src' ) && str_contains( $script_delayed_tag, " {$strategy}" ) && str_contains( $script_delayed_tag, 'data-wp-strategy="' . $strategy . '"' ),
			'concat-inline-sourceurl' => false !== $script_source_pos && false !== $script_loader_pos && $script_source_pos < $script_loader_pos,
			'output-order'            => self::contains_in_order( $script_output, array_merge( $script_objects, array( 'load-scripts.php', "{$script_external}-js", "{$script_delayed}-js" ) ) ),
			'done-order'              => array_merge( $script_handles, array( $script_external, $script_delayed ) ) === $script_done,
		);
		$script_ok     = ! in_array( false, $script_checks, true );

		$style_checks = array(
			'print-call'              => ! $style_print['threw'],
			'loader-present'          => array() !== $style_loader,
			'loader-path'             => '/wp-admin/load-styles.php' === substr( (string) ( $style_loader['path'] ?? '' ), -strlen( '/wp-admin/load-styles.php' ) ),
			'loader-query'            => '0' === ( $style_loader['query']['c'] ?? null ) && $style_dir === ( $style_loader['query']['dir'] ?? null ) && self::DEFAULT_VERSION === ( $style_loader['query']['ver'] ?? null ),
			'loader-chunks'           => str_split( $style_expected_list, 128 ) === $style_loader['chunks'],
			'loader-handles'          => $style_handles === $style_loader['handles'],
			'excluded-handles'        => array() === array_values( array_intersect( $style_loader['handles'], array( $style_external, $style_alt ) ) ),
			'separate-tags'           => is_string( $style_external_tag ) && is_string( $style_external_inline_tag ) && is_string( $style_alt_tag ),
			'external-media'          => is_string( $style_external_tag ) && array( $style_external_src ) === self::attribute_values( $style_external_tag, 'href' ) && array( 'screen' ) === self::attribute_values( $style_external_tag, 'media' ),
			'alt-tag'                 => is_string( $style_alt_tag ) && array( $style_alt_href ) === self::attribute_values( $style_alt_tag, 'href' ) && array( 'alternate stylesheet' ) === self::attribute_values( $style_alt_tag, 'rel' ) && array( 'print' ) === self::attribute_values( $style_alt_tag, 'media' ),
			'concat-inline-sourceurl' => false !== $style_source_pos && false !== $style_loader_pos && $style_loader_pos < $style_source_pos,
			'output-order'            => self::contains_in_order( $style_output, array_merge( $style_handles, array( $style_source_url, "{$style_external}-css", "{$style_external}-inline-css", "{$style_alt}-css" ) ) ),
			'done-order'              => array_merge( $style_handles, array( $style_external, $style_alt ) ) === $style_done,
		);
		$style_ok     = ! in_array( false, $style_checks, true );

		return $ctx->result(
			'script-loader-runtime.concat-runtime-boundaries',
			$script_ok && $style_ok && $local_cleanup,
			self::case_data( $case ) + array(
				'scriptHandles'      => $script_handles,
				'scriptExternal'     => $script_external,
				'scriptDelayed'      => $script_delayed,
				'scriptStrategy'     => $strategy,
				'scriptDone'         => $script_done,
				'scriptLoader'       => self::preview_array( $script_loader ),
				'scriptExternalTag'  => self::preview( (string) $script_external_tag ),
				'scriptDelayedTag'   => self::preview( (string) $script_delayed_tag ),
				'scriptPreview'      => self::preview( $script_output ),
				'scriptCall'         => self::describe_call( $script_print ),
				'scriptChecks'       => $script_checks,
				'scriptFailedChecks' => array_keys( array_filter( $script_checks, static fn( bool $ok ): bool => ! $ok ) ),
				'styleHandles'       => $style_handles,
				'styleExternal'      => $style_external,
				'styleAlt'           => $style_alt,
				'styleDirection'     => $style_dir,
				'styleDone'          => $style_done,
				'styleLoader'        => self::preview_array( $style_loader ),
				'styleExternalTag'   => self::preview( (string) $style_external_tag ),
				'styleAltTag'        => self::preview( (string) $style_alt_tag ),
				'stylePreview'       => self::preview( $style_output ),
				'styleCall'          => self::describe_call( $style_print ),
				'styleChecks'        => $style_checks,
				'styleFailedChecks'  => array_keys( array_filter( $style_checks, static fn( bool $ok ): bool => ! $ok ) ),
				'localCleanup'       => $local_cleanup,
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
		$parent_state_before      = self::jit_parent_state_snapshot();
		$constant_defined_before = defined( 'AUTOSAVE_INTERVAL' );
		$autosave_interval       = $ctx->fork( 'jit-script-localization' )->int( 15, 300 );

		$run = self::run_child_jit_script_localization( $case, $autosave_interval );

		$parent_state_after      = self::jit_parent_state_snapshot();
		$constant_defined_after = defined( 'AUTOSAVE_INTERVAL' );

		$result        = is_array( $run['result'] ) ? $run['result'] : array();
		$localizations = is_array( $result['localizations'] ?? null ) ? $result['localizations'] : array();
		$autosave      = $localizations['autosave']['decoded'] ?? null;
		$mce_view      = $localizations['mce-view']['decoded'] ?? null;
		$word_count    = $localizations['word-count']['decoded'] ?? null;
		$autosave_raw  = (string) ( $localizations['autosave']['raw'] ?? '' );
		$mce_raw       = (string) ( $localizations['mce-view']['raw'] ?? '' );
		$word_raw      = (string) ( $localizations['word-count']['raw'] ?? '' );

		$expected_handles    = array( 'autosave', 'mce-view', 'word-count' );
		$expected_shortcodes = array_merge( array( 'gallery' ), $case['shortcodes'], array( $case['escapeShortcode'] ) );

		$failures = array();
		if ( ! $run['ok'] ) {
			$failures[] = array(
				'invariant' => 'isolated subprocess exits cleanly and returns structured JSON',
				'exitCode'  => $run['exitCode'],
				'stdout'    => self::preview( $run['stdout'] ),
				'stderr'    => self::preview( $run['stderr'] ),
			);
		}
		if ( true !== ( $result['childStateRestored'] ?? null ) ) {
			$failures[] = array(
				'invariant'          => 'child restores shortcode_tags, wp_scripts, and hook globals before exit',
				'childStateRestored' => $result['childStateRestored'] ?? null,
			);
		}
		if ( $parent_state_before !== $parent_state_after || $constant_defined_before !== $constant_defined_after ) {
			$failures[] = array(
				'invariant'             => 'parent shortcode_tags, wp_scripts, hook state, and AUTOSAVE_INTERVAL definition do not change',
				'parentStateHashBefore' => self::state_hash( $parent_state_before ),
				'parentStateHashAfter'  => self::state_hash( $parent_state_after ),
				'constantBefore'        => $constant_defined_before,
				'constantAfter'         => $constant_defined_after,
			);
		}
		if ( $expected_handles !== ( $result['registeredHandles'] ?? null ) ) {
			$failures[] = array(
				'invariant' => 'child registers only the expected script handles',
				'expected'  => $expected_handles,
				'actual'    => $result['registeredHandles'] ?? null,
			);
		}
		if (
			! is_array( $autosave )
			|| (string) $autosave_interval !== ( $autosave['autosaveInterval'] ?? null )
			|| '1' !== ( $autosave['blog_id'] ?? null )
		) {
			$failures[] = array(
				'invariant' => 'autosave localizes autosaveL10n.autosaveInterval and blog_id on the autosave handle',
				'expected'  => array(
					'autosaveInterval' => (string) $autosave_interval,
					'blog_id'          => '1',
				),
				'actual'    => $autosave,
			);
		}
		if ( ! is_array( $mce_view ) || $expected_shortcodes !== ( $mce_view['shortcodes'] ?? null ) ) {
			$failures[] = array(
				'invariant' => 'mce-view localizes mceViewL10n.shortcodes with generated shortcode tags',
				'expected'  => $expected_shortcodes,
				'actual'    => $mce_view['shortcodes'] ?? null,
			);
		}
		if (
			! is_array( $word_count )
			|| 'words' !== ( $word_count['type'] ?? null )
			|| $expected_shortcodes !== ( $word_count['shortcodes'] ?? null )
		) {
			$failures[] = array(
				'invariant' => 'word-count localizes wordCountL10n.type and generated shortcode tags',
				'expected'  => array(
					'type'       => 'words',
					'shortcodes' => $expected_shortcodes,
				),
				'actual'    => $word_count,
			);
		}
		foreach ( $expected_handles as $handle ) {
			$json_error = $localizations[ $handle ]['jsonError'] ?? null;
			if ( 'No error' !== $json_error ) {
				$failures[] = array(
					'invariant' => 'localized data is parseable JSON with the expected object shape',
					'handle'    => $handle,
					'jsonError' => $json_error,
					'raw'       => self::preview( (string) ( $localizations[ $handle ]['raw'] ?? '' ) ),
				);
			}
		}
		if (
			str_contains( $mce_raw, '<script' )
			|| str_contains( $mce_raw, '</script' )
			|| str_contains( $word_raw, '<script' )
			|| str_contains( $word_raw, '</script' )
			|| ! str_contains( $mce_raw, '\u003Cscript' )
			|| ! str_contains( $word_raw, '\u003Cscript' )
		) {
			$failures[] = array(
				'invariant'       => 'localized shortcode data JSON-escapes tag-shaped shortcode keys',
				'escapeShortcode' => $case['escapeShortcode'],
				'mceViewRaw'      => self::preview( $mce_raw ),
				'wordCountRaw'    => self::preview( $word_raw ),
			);
		}

		$ok = array() === $failures;

		return $ctx->result(
			'script-loader-runtime.jit-script-localization',
			$ok,
			self::case_data( $case ) + array(
				'autosaveInterval'      => $autosave_interval,
				'shortcodes'            => $expected_shortcodes,
				'parentStateHashBefore' => self::state_hash( $parent_state_before ),
				'parentStateHashAfter'  => self::state_hash( $parent_state_after ),
				'parentConstantBefore'  => $constant_defined_before,
				'parentConstantAfter'   => $constant_defined_after,
				'childStateRestored'    => $result['childStateRestored'] ?? null,
				'registeredHandles'     => $result['registeredHandles'] ?? null,
				'localizedHandles'      => $result['localizedHandles'] ?? null,
				'autosave'              => self::preview( $autosave_raw ),
				'mceView'               => self::preview( $mce_raw ),
				'wordCount'             => self::preview( $word_raw ),
				'call'                  => $result['call'] ?? null,
				'childUnexpectedOutput' => self::preview( (string) ( $result['unexpectedOutput'] ?? '' ) ),
				'childStderr'           => self::preview( $run['stderr'] ),
				'failures'              => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function run_child_jit_script_localization( array $case, int $autosave_interval ): array {
		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-script-loader-runtime';
		\ComponentFuzz\ensure_dir( $dir );

		$script = $dir . DIRECTORY_SEPARATOR . 'jit-child-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) ) . '.php';
		file_put_contents( $script, self::jit_script_localization_child_program() );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates AUTOSAVE_INTERVAL in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			@unlink( $script );
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		$payload = json_encode(
			array(
				'repoRoot'          => \ComponentFuzz\repo_root(),
				'case'              => $case,
				'autosaveInterval'  => $autosave_interval,
				'baseUrl'           => self::BASE_URL,
				'contentUrl'        => self::CONTENT_URL,
				'defaultVersion'    => self::DEFAULT_VERSION,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
		fwrite( $pipes[0], false === $payload ? '{}' : $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		@unlink( $script );

		$result = json_decode( (string) $stdout, true );
		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && ! empty( $result['ok'] ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function jit_script_localization_child_program(): string {
		return <<<'PHP'
<?php
ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

function component_fuzz_slr_snapshot_globals(): array {
	$snapshot = array(
		'obLevel' => ob_get_level(),
		'globals' => array(),
	);

	foreach ( array( 'shortcode_tags', 'wp_actions', 'wp_current_filter', 'wp_filter', 'wp_filters', 'wp_scripts' ) as $name ) {
		$snapshot['globals'][ $name ] = array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => $GLOBALS[ $name ] ?? null,
		);
	}

	return $snapshot;
}

function component_fuzz_slr_restore_globals( array $snapshot ): void {
	foreach ( $snapshot['globals'] as $name => $entry ) {
		if ( $entry['exists'] ) {
			$GLOBALS[ $name ] = $entry['value'];
		} else {
			unset( $GLOBALS[ $name ] );
		}
	}

	while ( ob_get_level() > $snapshot['obLevel'] ) {
		ob_end_clean();
	}
}

function component_fuzz_slr_state_matches( array $snapshot ): bool {
	if ( ob_get_level() !== $snapshot['obLevel'] ) {
		return false;
	}

	foreach ( $snapshot['globals'] as $name => $entry ) {
		if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
			return false;
		}
		if ( $entry['exists'] && $GLOBALS[ $name ] !== $entry['value'] ) {
			return false;
		}
	}

	return true;
}

function component_fuzz_slr_call( callable $callback ): array {
	try {
		$callback();
		return array(
			'threw' => false,
			'value' => null,
		);
	} catch ( Throwable $e ) {
		return array(
			'threw'     => true,
			'throwable' => array(
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			),
			'value'     => null,
		);
	}
}

function component_fuzz_slr_decode_localization( $raw, string $object_name ): array {
	if ( ! is_string( $raw ) ) {
		return array(
			'json'      => null,
			'decoded'   => null,
			'jsonError' => 'localized data is not a string',
		);
	}

	$pattern = '/^var\s+' . preg_quote( $object_name, '/' ) . '\s*=\s*(.*);$/s';
	if ( ! preg_match( $pattern, trim( $raw ), $matches ) ) {
		return array(
			'json'      => null,
			'decoded'   => null,
			'jsonError' => 'localized data does not match expected var assignment',
		);
	}

	$decoded = json_decode( $matches[1], true );
	return array(
		'json'      => $matches[1],
		'decoded'   => $decoded,
		'jsonError' => json_last_error_msg(),
	);
}

function component_fuzz_slr_localization( WP_Scripts $scripts, string $handle, string $object_name ): array {
	$raw     = $scripts->get_data( $handle, 'data' );
	$decoded = component_fuzz_slr_decode_localization( $raw, $object_name );

	return array(
		'object'    => $object_name,
		'raw'       => is_string( $raw ) ? $raw : null,
		'rawType'   => gettype( $raw ),
		'json'      => $decoded['json'],
		'decoded'   => $decoded['decoded'],
		'jsonError' => $decoded['jsonError'],
	);
}

function component_fuzz_slr_preview( string $value, int $limit = 240 ): string {
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

$component_fuzz_slr_outer_ob_level = ob_get_level();
ob_start();

$component_fuzz_slr_unexpected_output = '';
$component_fuzz_slr_snapshot          = null;
$component_fuzz_slr_result            = array(
	'ok'                 => false,
	'childStateRestored' => false,
	'registeredHandles'  => array(),
	'localizedHandles'   => array(),
	'localizations'      => array(),
	'call'               => null,
	'unexpectedOutput'   => '',
);

try {
	$component_fuzz_slr_raw     = stream_get_contents( STDIN );
	$component_fuzz_slr_fixture = json_decode( $component_fuzz_slr_raw, true );

	if ( ! is_array( $component_fuzz_slr_fixture ) || empty( $component_fuzz_slr_fixture['repoRoot'] ) || ! is_array( $component_fuzz_slr_fixture['case'] ?? null ) ) {
		throw new RuntimeException( 'Invalid script-loader JIT fixture.' );
	}

	define( 'AUTOSAVE_INTERVAL', (int) $component_fuzz_slr_fixture['autosaveInterval'] );

	require_once $component_fuzz_slr_fixture['repoRoot'] . '/tools/component-fuzz/lib/autoload.php';

	\ComponentFuzz\WpBootstrap::load();

	$component_fuzz_slr_snapshot = component_fuzz_slr_snapshot_globals();
	$component_fuzz_slr_case     = $component_fuzz_slr_fixture['case'];

	if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
		$GLOBALS['wp_actions'] = array();
	}
	$GLOBALS['wp_actions']['init'] = max( 1, (int) ( $GLOBALS['wp_actions']['init'] ?? 0 ) );
	$GLOBALS['concatenate_scripts'] = false;
	$GLOBALS['compress_scripts']    = false;
	$GLOBALS['wp_scripts']          = new WP_Scripts();
	wp_scripts()->base_url          = (string) $component_fuzz_slr_fixture['baseUrl'];
	wp_scripts()->content_url       = (string) $component_fuzz_slr_fixture['contentUrl'];
	wp_scripts()->default_version   = (string) $component_fuzz_slr_fixture['defaultVersion'];
	wp_scripts()->default_dirs      = array();

	$GLOBALS['shortcode_tags'] = array( 'gallery' => '__return_empty_string' );
	foreach ( $component_fuzz_slr_case['shortcodes'] as $component_fuzz_slr_shortcode ) {
		$GLOBALS['shortcode_tags'][ $component_fuzz_slr_shortcode ] = '__return_empty_string';
	}
	$GLOBALS['shortcode_tags'][ $component_fuzz_slr_case['escapeShortcode'] ] = '__return_empty_string';

	foreach ( array( 'autosave', 'mce-view', 'word-count' ) as $component_fuzz_slr_handle ) {
		wp_register_script( $component_fuzz_slr_handle, "runtime/{$component_fuzz_slr_handle}.js", array(), '1.0.0' );
	}

	$component_fuzz_slr_call = component_fuzz_slr_call( static fn() => wp_just_in_time_script_localization() );
	$component_fuzz_slr_scripts = wp_scripts();

	$component_fuzz_slr_result['call']              = $component_fuzz_slr_call;
	$component_fuzz_slr_result['registeredHandles'] = array_keys( $component_fuzz_slr_scripts->registered );
	$component_fuzz_slr_result['localizations']     = array(
		'autosave'   => component_fuzz_slr_localization( $component_fuzz_slr_scripts, 'autosave', 'autosaveL10n' ),
		'mce-view'   => component_fuzz_slr_localization( $component_fuzz_slr_scripts, 'mce-view', 'mceViewL10n' ),
		'word-count' => component_fuzz_slr_localization( $component_fuzz_slr_scripts, 'word-count', 'wordCountL10n' ),
	);
	$component_fuzz_slr_result['localizedHandles']  = array_values(
		array_filter(
			array_keys( $component_fuzz_slr_result['localizations'] ),
			static fn( string $handle ): bool => is_string( $component_fuzz_slr_result['localizations'][ $handle ]['raw'] )
		)
	);
	$component_fuzz_slr_result['wordCountType']     = wp_get_word_count_type();
	$component_fuzz_slr_result['ok']                = ! $component_fuzz_slr_call['threw'];
} catch ( Throwable $e ) {
	$component_fuzz_slr_result['throwable'] = array(
		'class'   => get_class( $e ),
		'message' => $e->getMessage(),
		'file'    => $e->getFile(),
		'line'    => $e->getLine(),
	);
} finally {
	if ( is_array( $component_fuzz_slr_snapshot ) ) {
		component_fuzz_slr_restore_globals( $component_fuzz_slr_snapshot );
		$component_fuzz_slr_result['childStateRestored'] = component_fuzz_slr_state_matches( $component_fuzz_slr_snapshot );
	}

	while ( ob_get_level() > $component_fuzz_slr_outer_ob_level ) {
		$component_fuzz_slr_unexpected_output = ob_get_clean() . $component_fuzz_slr_unexpected_output;
	}

	$component_fuzz_slr_result['unexpectedOutput'] = component_fuzz_slr_preview( $component_fuzz_slr_unexpected_output );
}

$component_fuzz_slr_json = json_encode( $component_fuzz_slr_result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
echo false === $component_fuzz_slr_json ? '{"ok":false,"error":"json_encode failed"}' : $component_fuzz_slr_json;
exit( ! empty( $component_fuzz_slr_result['ok'] ) ? 0 : 1 );
PHP;
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token  = $ctx->iteration() . '-' . substr( sha1( (string) $ctx->seed() ), 0, 8 );
		$prefix = 'cf-slr-' . $token;
		$tag_value = '<tag attr="value&' . $ctx->int( 0, 999 ) . '">"quoted"&</tag>';
		$shortcode = 'cf_shortcode_' . str_replace( '-', '_', $token );

		return array(
			'token'      => $token,
			'prefix'     => $prefix,
			'tagValue'   => $tag_value,
			'scriptSrcA' => 'runtime/' . rawurlencode( $prefix ) . '-one.js?x=1#frag',
			'scriptSrcB' => 'runtime/' . rawurlencode( $prefix ) . '-two.js?x=2#frag',
			'styleSrcA'  => 'runtime/' . rawurlencode( $prefix ) . '-one.css?x=1#frag',
			'styleSrcB'  => 'runtime/' . rawurlencode( $prefix ) . '-two.css?x=2#frag',
			'objectName' => 'cfRuntime' . str_replace( '-', '_', $token ),
			'shortcode'  => $shortcode,
			'shortcodes' => array(
				$shortcode,
				$shortcode . '_alpha',
				$shortcode . '_beta',
			),
			'escapeShortcode' => $shortcode . '_lt<script>alert(1)</script>',
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

	private static function opening_tag_by_id( string $html, string $tag_name, string $id ): ?string {
		foreach ( self::opening_tags( $html, $tag_name ) as $tag ) {
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

	private static function loader_query_details( string $html, string $tag_name, string $attribute, string $path_suffix ): array {
		foreach ( self::opening_tags( $html, $tag_name ) as $tag ) {
			foreach ( self::attribute_values( $tag, $attribute ) as $url ) {
				$parts = parse_url( $url );
				if ( ! is_array( $parts ) || ! str_ends_with( (string) ( $parts['path'] ?? '' ), $path_suffix ) ) {
					continue;
				}

				$query = array();
				parse_str( (string) ( $parts['query'] ?? '' ), $query );

				$chunks = array();
				$load   = $query['load'] ?? array();
				if ( is_array( $load ) ) {
					ksort( $load );
					$chunks = array_values( array_map( 'strval', $load ) );
				}

				$handles = array_values(
					array_filter(
						explode( ',', implode( '', $chunks ) ),
						static fn( string $handle ): bool => '' !== $handle
					)
				);

				return array(
					'url'     => $url,
					'path'    => (string) ( $parts['path'] ?? '' ),
					'query'   => $query,
					'chunks'  => $chunks,
					'handles' => $handles,
				);
			}
		}

		return array();
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

	private static function jit_parent_state_snapshot(): array {
		return array(
			'shortcodeTags' => array_key_exists( 'shortcode_tags', $GLOBALS )
				? self::shortcode_tags_state( $GLOBALS['shortcode_tags'] )
				: null,
			'wpScripts'     => self::wp_scripts_state( $GLOBALS['wp_scripts'] ?? null ),
			'hooks'         => array(
				'wp_actions'        => self::normalize_state_value( $GLOBALS['wp_actions'] ?? null ),
				'wp_current_filter' => self::normalize_state_value( $GLOBALS['wp_current_filter'] ?? null ),
				'wp_filter'         => self::filter_hooks_state( $GLOBALS['wp_filter'] ?? null ),
				'wp_filters'        => self::normalize_state_value( $GLOBALS['wp_filters'] ?? null ),
			),
		);
	}

	private static function shortcode_tags_state( $shortcode_tags ): array {
		if ( ! is_array( $shortcode_tags ) ) {
			return array(
				'type'  => gettype( $shortcode_tags ),
				'value' => self::normalize_state_value( $shortcode_tags ),
			);
		}

		$state = array();
		foreach ( $shortcode_tags as $tag => $callback ) {
			$state[ $tag ] = self::callback_state( $callback );
		}
		return $state;
	}

	private static function wp_scripts_state( $scripts ): array {
		if ( ! $scripts instanceof \WP_Scripts ) {
			return array(
				'type'  => is_object( $scripts ) ? get_class( $scripts ) : gettype( $scripts ),
				'value' => self::normalize_state_value( $scripts ),
			);
		}

		$registered = array();
		foreach ( $scripts->registered as $handle => $dependency ) {
			$registered[ $handle ] = self::dependency_state( $dependency );
		}

		return array(
			'class'           => get_class( $scripts ),
			'baseUrl'         => $scripts->base_url,
			'contentUrl'      => $scripts->content_url,
			'defaultVersion'  => $scripts->default_version,
			'defaultDirs'     => self::normalize_state_value( $scripts->default_dirs ),
			'registered'      => $registered,
			'queue'           => $scripts->queue,
			'toDo'            => $scripts->to_do,
			'done'            => $scripts->done,
			'args'            => self::normalize_state_value( $scripts->args ),
			'groups'          => self::normalize_state_value( $scripts->groups ),
			'inFooter'        => self::normalize_state_value( $scripts->in_footer ),
			'concat'          => $scripts->concat,
			'concatVersion'   => $scripts->concat_version,
			'doConcat'        => $scripts->do_concat,
			'printHtml'       => $scripts->print_html,
			'printCode'       => $scripts->print_code,
			'extHandles'      => $scripts->ext_handles,
			'extVersion'      => $scripts->ext_version,
		);
	}

	private static function dependency_state( $dependency ): array {
		if ( ! $dependency instanceof \_WP_Dependency ) {
			return array(
				'type'  => is_object( $dependency ) ? get_class( $dependency ) : gettype( $dependency ),
				'value' => self::normalize_state_value( $dependency ),
			);
		}

		return array(
			'handle'           => $dependency->handle,
			'src'              => $dependency->src,
			'deps'             => $dependency->deps,
			'ver'              => $dependency->ver,
			'args'             => self::normalize_state_value( $dependency->args ),
			'extra'            => self::normalize_state_value( $dependency->extra ),
			'textdomain'       => $dependency->textdomain ?? null,
			'translationsPath' => $dependency->translations_path ?? null,
		);
	}

	private static function filter_hooks_state( $hooks ): array {
		if ( ! is_array( $hooks ) ) {
			return array(
				'type'  => is_object( $hooks ) ? get_class( $hooks ) : gettype( $hooks ),
				'value' => self::normalize_state_value( $hooks ),
			);
		}

		$state = array();
		foreach ( $hooks as $hook_name => $hook ) {
			$state[ $hook_name ] = self::hook_state( $hook );
		}
		return $state;
	}

	private static function hook_state( $hook ): array {
		if ( $hook instanceof \WP_Hook ) {
			$callbacks = array();
			foreach ( $hook->callbacks as $priority => $priority_callbacks ) {
				$callbacks[ (string) $priority ] = array();
				foreach ( $priority_callbacks as $id => $callback ) {
					$callbacks[ (string) $priority ][ $id ] = array(
						'function'     => self::callback_state( $callback['function'] ?? null ),
						'acceptedArgs' => (int) ( $callback['accepted_args'] ?? 0 ),
					);
				}
			}

			return array(
				'class'     => get_class( $hook ),
				'callbacks' => $callbacks,
			);
		}

		return array(
			'type'  => is_object( $hook ) ? get_class( $hook ) : gettype( $hook ),
			'value' => self::normalize_state_value( $hook ),
		);
	}

	private static function callback_state( $callback ): array {
		if ( is_string( $callback ) ) {
			return array(
				'type' => 'function',
				'name' => $callback,
			);
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			return array(
				'type'   => 'method',
				'class'  => is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0],
				'object' => is_object( $callback[0] ) ? spl_object_id( $callback[0] ) : null,
				'method' => (string) $callback[1],
			);
		}
		if ( $callback instanceof \Closure ) {
			return array(
				'type'   => 'closure',
				'object' => spl_object_id( $callback ),
			);
		}
		if ( is_object( $callback ) ) {
			return array(
				'type'   => 'invokable',
				'class'  => get_class( $callback ),
				'object' => spl_object_id( $callback ),
			);
		}

		return array(
			'type'  => gettype( $callback ),
			'value' => self::normalize_state_value( $callback ),
		);
	}

	private static function normalize_state_value( $value, int $depth = 0 ) {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}
		if ( $depth > 5 ) {
			return is_object( $value ) ? '[object ' . get_class( $value ) . ']' : '[' . gettype( $value ) . ']';
		}
		if ( is_array( $value ) ) {
			$normalized = array();
			foreach ( $value as $key => $item ) {
				$normalized[ $key ] = self::normalize_state_value( $item, $depth + 1 );
			}
			return $normalized;
		}
		if ( $value instanceof \Closure ) {
			return array(
				'type'   => 'closure',
				'object' => spl_object_id( $value ),
			);
		}
		if ( is_object( $value ) ) {
			return array(
				'type'   => 'object',
				'class'  => get_class( $value ),
				'object' => spl_object_id( $value ),
			);
		}

		return '[' . gettype( $value ) . ']';
	}

	private static function state_hash( array $state ): string {
		$json = json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			return 'json-error:' . json_last_error_msg();
		}

		return hash( 'sha256', $json );
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
