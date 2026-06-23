<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB block editor adjunct APIs.
 */
final class BlockEditorAdjunctsSurface {
	public const NAME = 'block-editor-adjuncts';

	private const CATEGORY_CASES = 6;
	private const PREVIEW_BYTES  = 240;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'block-editor-adjuncts.bootstrap-apis-available',
					'Required WordPress block editor adjunct APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot  = self::snapshot_state();
		$ob_level  = ob_get_level();
		$temp_root = null;
		$cleanup   = null;
		$rows      = array();

		try {
			$temp_root = self::make_temp_root( $ctx );
			$case      = self::case_for_context( $ctx, $temp_root );

			$rows[] = self::check_context_filters( $ctx, $case );
			$rows[] = self::check_editor_settings_merge( $ctx, $case );
			$rows[] = self::check_theme_styles_and_iframed_assets( $ctx, $case );
			$rows[] = self::check_rest_preload_paths( $ctx, $case );
			$rows[] = self::check_block_tree_helpers( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'block-editor-adjuncts.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $temp_root ) {
				$cleanup = self::remove_dir_recursive( $temp_root );
			}
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_state( $snapshot );
		}

		if ( null !== $temp_root ) {
			$rows[] = $ctx->result(
				'block-editor-adjuncts.temp-sandbox-cleaned',
				true === $cleanup && ! file_exists( $temp_root ),
				array(
					'root'    => self::preview( $temp_root ),
					'cleaned' => true === $cleanup,
					'exists'  => file_exists( $temp_root ),
				)
			);
		}

		$rows[] = $ctx->result(
			'block-editor-adjuncts.global-state-restored',
			self::state_restored( $snapshot ),
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
				'WP_Block_Editor_Context',
				'WP_Block_Type_Registry',
				'WP_REST_Response',
				'WP_Scripts',
				'WP_Styles',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_get_iframed_editor_assets',
				'add_filter',
				'add_theme_support',
				'block_editor_rest_api_preload',
				'get_allowed_block_types',
				'get_block_categories',
				'get_block_editor_settings',
				'get_block_editor_theme_styles',
				'get_classic_theme_supports_block_editor_settings',
				'get_default_block_categories',
				'get_default_block_editor_settings',
				'get_legacy_widget_block_editor_settings',
				'has_filter',
				'register_block_type',
				'remove_filter',
				'unregister_block_type',
				'wp_add_inline_style',
				'wp_get_first_block',
				'wp_register_script',
				'wp_register_style',
				'wp_scripts',
				'wp_styles',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_context_filters( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		foreach ( self::context_cases( $ctx->fork( 'contexts' ), $case ) as $index => $context_case ) {
			$seen_categories = array();
			$seen_allowed    = array();
			$category_filter = static function ( array $categories, $context ) use ( &$seen_categories, $context_case ): array {
				$seen_categories[] = BlockEditorAdjunctsSurface::context_summary( $context );
				$categories[]      = $context_case['category'];
				return $categories;
			};
			$allowed_filter  = static function ( $allowed, $context ) use ( &$seen_allowed, $context_case ) {
				unset( $allowed );
				$seen_allowed[] = BlockEditorAdjunctsSurface::context_summary( $context );
				return $context_case['allowed'];
			};

			\add_filter( 'block_categories_all', $category_filter, 10, 2 );
			\add_filter( 'allowed_block_types_all', $allowed_filter, 10, 2 );
			try {
				$categories = \get_block_categories( $context_case['categoryInput'] );
				$allowed    = \get_allowed_block_types( $context_case['context'] );
			} finally {
				\remove_filter( 'block_categories_all', $category_filter, 10 );
				\remove_filter( 'allowed_block_types_all', $allowed_filter, 10 );
			}

			$category_slugs = self::category_slugs( $categories );
			self::record_if_false(
				$failures,
				self::default_category_prefix_ok( $categories )
					&& 1 === count( array_keys( $category_slugs, $context_case['category']['slug'], true ) )
					&& $context_case['category'] === $categories[ count( $categories ) - 1 ]
					&& $seen_categories === array( $context_case['expectedCategoryContext'] ),
				"context categories are filtered with the expected editor context case {$index}",
				array(
					'inputKind' => $context_case['inputKind'],
					'expected'  => $context_case['expectedCategoryContext'],
					'seen'      => $seen_categories,
					'slugs'     => $category_slugs,
				)
			);

			self::record_if_false(
				$failures,
				$allowed === $context_case['allowed']
					&& $seen_allowed === array( self::context_summary( $context_case['context'] ) ),
				"allowed block types filter is contextual and exact case {$index}",
				array(
					'expected' => $context_case['allowed'],
					'actual'   => $allowed,
					'seen'     => $seen_allowed,
				)
			);
		}

		return self::row(
			$ctx,
			'block-editor-adjuncts.context.filters-categories-and-allowed-blocks',
			$failures,
			array( 'cases' => self::CATEGORY_CASES )
		);
	}

	private static function check_editor_settings_merge( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::prepare_asset_globals();
		self::install_theme_supports( $case );

		$style_filters = self::install_local_theme_style_filters( $case );
		$seen_settings = array();

		$image_sizes_filter = static function ( array $sizes ) use ( $case ): array {
			$sizes[ $case['imageSizeSlug'] ] = $case['imageSizeName'];
			return $sizes;
		};
		$image_default_filter = static function () use ( $case ): string {
			return $case['imageSizeSlug'];
		};
		$legacy_widget_filter = static function ( array $widgets ) use ( $case ): array {
			return array_values( array_unique( array_merge( $widgets, $case['legacyWidgetHidden'] ) ) );
		};
		$category_filter      = static function ( array $categories ) use ( $case ): array {
			$categories[] = $case['settingsCategory'];
			return $categories;
		};
		$allowed_filter       = static function () use ( $case ) {
			return $case['settingsAllowedBlocks'];
		};
		$settings_filter      = static function ( array $settings, $context ) use ( &$seen_settings, $case ): array {
			$seen_settings[] = BlockEditorAdjunctsSurface::context_summary( $context );
			$settings['componentFuzzFilter'] = array(
				'id'      => $case['slug'],
				'context' => $context instanceof \WP_Block_Editor_Context ? $context->name : null,
			);
			return $settings;
		};
		$cap_filter           = static function ( array $allcaps ): array {
			$allcaps['edit_theme_options'] = true;
			$allcaps['exist']              = true;
			$allcaps['read']               = true;
			$allcaps['unfiltered_html']    = true;
			$allcaps['upload_files']       = true;
			return $allcaps;
		};
		$bindings_filter      = static function () use ( $case ): array {
			return $case['bindingAttributes'];
		};

		\add_filter( 'image_size_names_choose', $image_sizes_filter );
		\add_filter( 'pre_option_image_default_size', $image_default_filter );
		\add_filter( 'widget_types_to_hide_from_legacy_widget_block', $legacy_widget_filter );
		\add_filter( 'block_categories_all', $category_filter );
		\add_filter( 'allowed_block_types_all', $allowed_filter );
		\add_filter( 'block_editor_settings_all', $settings_filter, 10, 2 );
		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		\add_filter( 'block_bindings_supported_attributes_' . $case['bindingBlockName'], $bindings_filter );

		$registered_block = \register_block_type(
			$case['bindingBlockName'],
			array(
				'title'      => 'Component Fuzz Binding Probe',
				'attributes' => array(
					'content' => array( 'type' => 'string' ),
				),
			)
		);

		try {
			$default_settings = \get_default_block_editor_settings();
			$legacy_settings  = \get_legacy_widget_block_editor_settings();
			$classic_settings = \get_classic_theme_supports_block_editor_settings();
			$editor_settings  = \get_block_editor_settings( $case['customSettings'], $case['settingsContext'] );
		} finally {
			\remove_filter( 'image_size_names_choose', $image_sizes_filter );
			\remove_filter( 'pre_option_image_default_size', $image_default_filter );
			\remove_filter( 'widget_types_to_hide_from_legacy_widget_block', $legacy_widget_filter );
			\remove_filter( 'block_categories_all', $category_filter );
			\remove_filter( 'allowed_block_types_all', $allowed_filter );
			\remove_filter( 'block_editor_settings_all', $settings_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'block_bindings_supported_attributes_' . $case['bindingBlockName'], $bindings_filter );
			\unregister_block_type( $case['bindingBlockName'] );
			self::remove_filters( $style_filters );
		}

		$image_size_slugs = array();
		foreach ( $default_settings['imageSizes'] ?? array() as $size ) {
			if ( is_array( $size ) && isset( $size['slug'] ) ) {
				$image_size_slugs[] = $size['slug'];
			}
		}

		self::record_if_false(
			$failures,
			$registered_block instanceof \WP_Block_Type
				&& in_array( $case['imageSizeSlug'], $image_size_slugs, true )
				&& $case['imageSizeSlug'] === ( $default_settings['imageDefaultSize'] ?? null )
				&& array() === array_diff( $case['legacyWidgetHidden'], $legacy_settings['widgetTypesToHideFromLegacyWidgetBlock'] ?? array() ),
			'default and legacy editor adjunct settings honor filters',
			array(
				'imageSizes'     => $image_size_slugs,
				'imageDefault'   => $default_settings['imageDefaultSize'] ?? null,
				'legacyHidden'   => $legacy_settings['widgetTypesToHideFromLegacyWidgetBlock'] ?? null,
				'registeredType' => $registered_block instanceof \WP_Block_Type ? $registered_block->name : $registered_block,
			)
		);

		self::record_if_false(
			$failures,
			$case['themePalette'] === ( $classic_settings['colors'] ?? null )
				&& $case['themeFontSizes'] === ( $classic_settings['fontSizes'] ?? null )
				&& $case['themeGradients'] === ( $classic_settings['gradients'] ?? null )
				&& $case['themeSpacingSizes'] === ( $classic_settings['spacingSizes'] ?? null ),
			'classic theme support settings preserve palette, font, gradient, and spacing data',
			array(
				'classicSettings' => $classic_settings,
			)
		);

		self::record_if_false(
			$failures,
			$case['customSettings']['imageEditing'] === ( $editor_settings['imageEditing'] ?? null )
				&& $case['customSettings']['componentFuzzSetting'] === ( $editor_settings['componentFuzzSetting'] ?? null )
				&& $case['settingsAllowedBlocks'] === ( $editor_settings['allowedBlockTypes'] ?? null )
				&& in_array( $case['settingsCategory']['slug'], self::category_slugs( $editor_settings['blockCategories'] ?? array() ), true )
				&& $case['bindingAttributes'] === (
					$editor_settings['__experimentalBlockBindingsSupportedAttributes'][ $case['bindingBlockName'] ] ?? null
				)
				&& true === ( $editor_settings['canEditCSS'] ?? null )
				&& true === ( $editor_settings['canUpdateBlockBindings'] ?? null )
				&& self::contains_style_marker( $editor_settings['styles'] ?? array(), $case['themeCssMarker'] )
				&& isset( $editor_settings['__unstableResolvedAssets']['styles'], $editor_settings['__unstableResolvedAssets']['scripts'] )
				&& $seen_settings === array( self::context_summary( $case['settingsContext'] ) ),
			'merged editor settings keep custom settings, context filters, bindings, assets, and caps',
			array(
				'allowedBlockTypes' => $editor_settings['allowedBlockTypes'] ?? null,
				'categorySlugs'     => self::category_slugs( $editor_settings['blockCategories'] ?? array() ),
				'bindings'          => $editor_settings['__experimentalBlockBindingsSupportedAttributes'][ $case['bindingBlockName'] ] ?? null,
				'componentSetting'  => $editor_settings['componentFuzzSetting'] ?? null,
				'filterSetting'     => $editor_settings['componentFuzzFilter'] ?? null,
				'seenSettings'      => $seen_settings,
				'canEditCSS'        => $editor_settings['canEditCSS'] ?? null,
				'canUpdateBindings' => $editor_settings['canUpdateBlockBindings'] ?? null,
			)
		);

		return self::row(
			$ctx,
			'block-editor-adjuncts.editor-settings.default-legacy-classic-and-merged',
			$failures,
			array(
				'context'   => self::context_summary( $case['settingsContext'] ),
				'styleFile' => self::preview( $case['themeStyleFile'] ),
			)
		);
	}

	private static function check_theme_styles_and_iframed_assets( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::prepare_asset_globals();
		self::install_theme_supports( $case );

		$style_filters   = self::install_local_theme_style_filters( $case );
		$remote_attempts = 0;
		$remote_filter   = static function () use ( &$remote_attempts ) {
			++$remote_attempts;
			return new \WP_Error( 'component_fuzz_no_remote', 'Remote editor styles are blocked by the fuzzer.' );
		};

		\add_filter( 'pre_http_request', $remote_filter );

		$style_handle    = $case['iframeStyleHandle'];
		$style_marker    = $case['iframeStyleMarker'];
		$block_name      = $case['iframeBlockName'];
		$original_styles  = $GLOBALS['wp_styles'] ?? null;
		$original_scripts = $GLOBALS['wp_scripts'] ?? null;

		\wp_register_style( $style_handle, false );
		\wp_add_inline_style( $style_handle, '.' . $style_marker . '{outline:1px solid #123456;}' );
		$registered_block = \register_block_type(
			$block_name,
			array(
				'title'                => 'Component Fuzz Iframe Probe',
				'editor_style_handles' => array( $style_handle ),
			)
		);

		try {
			$theme_styles = \get_block_editor_theme_styles();
			$assets       = \_wp_get_iframed_editor_assets();
		} finally {
			\unregister_block_type( $block_name );
			\remove_filter( 'pre_http_request', $remote_filter );
			self::remove_filters( $style_filters );
		}

		self::record_if_false(
			$failures,
			$registered_block instanceof \WP_Block_Type
				&& 1 === count( $theme_styles )
				&& isset( $theme_styles[0]['css'], $theme_styles[0]['baseURL'] )
				&& str_contains( $theme_styles[0]['css'], $case['themeCssMarker'] )
				&& $case['themeStyleUri'] === $theme_styles[0]['baseURL']
				&& 0 === $remote_attempts,
			'local editor theme styles are loaded from the temp fixture without remote fetches',
			array(
				'themeStyles'    => $theme_styles,
				'remoteAttempts' => $remote_attempts,
				'registeredType' => $registered_block instanceof \WP_Block_Type ? $registered_block->name : $registered_block,
			)
		);

		self::record_if_false(
			$failures,
			is_array( $assets )
				&& isset( $assets['styles'], $assets['scripts'] )
				&& is_string( $assets['styles'] )
				&& is_string( $assets['scripts'] )
				&& str_contains( $assets['styles'], $style_marker )
				&& ( $GLOBALS['wp_styles'] ?? null ) === $original_styles
				&& ( $GLOBALS['wp_scripts'] ?? null ) === $original_scripts
				&& false === \has_filter( 'should_load_block_editor_scripts_and_styles', '__return_false' ),
			'iframe asset collection includes editor style handles and restores asset globals',
			array(
				'stylesPreview'   => self::preview( $assets['styles'] ?? '' ),
				'scriptsPreview'  => self::preview( $assets['scripts'] ?? '' ),
				'globalsRestored' => array(
					'styles'  => ( $GLOBALS['wp_styles'] ?? null ) === $original_styles,
					'scripts' => ( $GLOBALS['wp_scripts'] ?? null ) === $original_scripts,
				),
				'filterPriority'  => \has_filter( 'should_load_block_editor_scripts_and_styles', '__return_false' ),
			)
		);

		return self::row(
			$ctx,
			'block-editor-adjuncts.theme-styles-and-iframed-assets.local-safe-restoring',
			$failures,
			array(
				'styleHandle' => $style_handle,
				'blockName'   => $block_name,
			)
		);
	}

	private static function check_rest_preload_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		self::prepare_asset_globals();

		$post_before    = self::synthetic_post( $ctx->fork( 'preload-post' ), 'preload-global-post' );
		$post_expected  = self::post_summary( $post_before );
		$GLOBALS['post'] = $post_before;

		\wp_register_script( 'wp-api-fetch', false );

		$seen_context = array();
		$seen_requests = array();
		$paths_filter = static function ( array $paths, $context ) use ( &$seen_context, $case ): array {
			$seen_context[] = BlockEditorAdjunctsSurface::context_summary( $context );
			$paths[]        = array( $case['preloadOptionsPath'], 'OPTIONS' );
			$paths[]        = array( $case['preloadInvalidMethodPath'], $case['preloadInvalidMethod'] );
			return $paths;
		};
		$pre_dispatch_filter = static function ( $result, $server, $request ) use ( &$seen_requests, $case ) {
			unset( $result, $server );
			$seen_requests[] = array(
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
				'query'  => $request->get_query_params(),
			);

			$GLOBALS['post']->post_title = 'mutated during rest preload';
			\wp_register_script( $case['leakyScriptHandle'], false );
			\wp_register_style( $case['leakyStyleHandle'], false );

			return new \WP_REST_Response(
				array(
					'method' => $request->get_method(),
					'route'  => $request->get_route(),
					'query'  => $request->get_query_params(),
					'token'  => $case['slug'],
				),
				200,
				array( 'X-Component-Fuzz' => $case['slug'] )
			);
		};

		\add_filter( 'block_editor_rest_api_preload_paths', $paths_filter, 10, 2 );
		\add_filter( 'rest_pre_dispatch', $pre_dispatch_filter, 10, 3 );

		try {
			\block_editor_rest_api_preload( array( $case['preloadGetPath'] ), $case['settingsContext'] );
		} finally {
			\remove_filter( 'block_editor_rest_api_preload_paths', $paths_filter, 10 );
			\remove_filter( 'rest_pre_dispatch', $pre_dispatch_filter, 10 );
		}

		$inline_after = \wp_scripts()->registered['wp-api-fetch']->extra['after'] ?? array();
		$preload_data = self::extract_preload_data( $inline_after );

		self::record_if_false(
			$failures,
			$seen_context === array( self::context_summary( $case['settingsContext'] ) )
				&& self::request_seen( $seen_requests, 'GET', $case['preloadGetRoute'] )
				&& self::request_seen( $seen_requests, 'OPTIONS', $case['preloadOptionsRoute'] )
				&& self::request_seen( $seen_requests, 'GET', $case['preloadInvalidMethodRoute'] ),
			'preload filters and REST short-circuit see normalized methods and context',
			array(
				'seenContext'  => $seen_context,
				'seenRequests' => $seen_requests,
			)
		);

		self::record_if_false(
			$failures,
			isset(
				$preload_data[ $case['preloadGetKey'] ],
				$preload_data['OPTIONS'][ $case['preloadOptionsKey'] ],
				$preload_data[ $case['preloadInvalidMethodKey'] ]
			)
				&& 'GET' === ( $preload_data[ $case['preloadGetKey'] ]['body']['method'] ?? null )
				&& 'OPTIONS' === ( $preload_data['OPTIONS'][ $case['preloadOptionsKey'] ]['body']['method'] ?? null )
				&& 'GET' === ( $preload_data[ $case['preloadInvalidMethodKey'] ]['body']['method'] ?? null ),
			'preload inline script stores normalized GET and OPTIONS response data',
			array(
				'inlineAfter'  => $inline_after,
				'preloadData'  => $preload_data,
				'expectedKeys' => array(
					'get'     => $case['preloadGetKey'],
					'options' => $case['preloadOptionsKey'],
					'invalid' => $case['preloadInvalidMethodKey'],
				),
			)
		);

		self::record_if_false(
			$failures,
			( $GLOBALS['post'] ?? null ) instanceof \WP_Post
				&& $post_expected['id'] === (int) $GLOBALS['post']->ID
				&& $post_expected['title'] === $GLOBALS['post']->post_title
				&& ! \wp_script_is( $case['leakyScriptHandle'], 'registered' )
				&& ! \wp_style_is( $case['leakyStyleHandle'], 'registered' ),
			'preload restores global post and discards assets registered during REST dispatch',
			array(
				'postBefore'       => $post_expected,
				'postAfter'        => self::post_summary( $GLOBALS['post'] ?? null ),
				'leakyScriptKnown' => \wp_script_is( $case['leakyScriptHandle'], 'registered' ),
				'leakyStyleKnown'  => \wp_style_is( $case['leakyStyleHandle'], 'registered' ),
			)
		);

		return self::row(
			$ctx,
			'block-editor-adjuncts.rest-preload.paths-normalized-short-circuited-and-restored',
			$failures,
			array(
				'paths' => array(
					$case['preloadGetPath'],
					$case['preloadOptionsPath'],
					$case['preloadInvalidMethodPath'],
				),
			)
		);
	}

	private static function check_block_tree_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$tree     = self::block_tree_case( $ctx->fork( 'block-tree' ), $case );
		$original = $tree['blocks'];

		$found   = \wp_get_first_block( $tree['blocks'], $tree['targetName'] );
		$missing = \wp_get_first_block( $tree['blocks'], $tree['missingName'] );

		self::record_if_false(
			$failures,
			$found === $tree['expectedBlock']
				&& array() === $missing
				&& $tree['blocks'] === $original,
			'wp_get_first_block returns the first depth-first match and does not mutate input',
			array(
				'targetName' => $tree['targetName'],
				'found'      => $found,
				'expected'   => $tree['expectedBlock'],
				'missing'    => $missing,
			)
		);

		$post_id_before     = $GLOBALS['post_ID'] ?? null;
		$GLOBALS['post_ID'] = 0;
		try {
			$post_content_attrs = \wp_get_post_content_block_attributes();
		} finally {
			if ( null === $post_id_before ) {
				unset( $GLOBALS['post_ID'] );
			} else {
				$GLOBALS['post_ID'] = $post_id_before;
			}
		}

		self::record_if_false(
			$failures,
			null === $post_content_attrs,
			'post content block attribute helper returns null without a selected post',
			array( 'attributes' => $post_content_attrs )
		);

		return self::row(
			$ctx,
			'block-editor-adjuncts.block-tree-and-post-content-helpers.guarded',
			$failures,
			array( 'targetName' => $tree['targetName'] )
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$slug          = self::slug( $ctx, 'editor' );
		$theme_marker  = 'cfz-theme-' . $slug;
		$theme_file    = $temp_root . DIRECTORY_SEPARATOR . 'editor-' . $slug . '.css';
		$theme_uri     = 'https://example.test/component-fuzz/editor-' . rawurlencode( $slug ) . '.css';
		$theme_css     = '.editor-styles-wrapper .' . $theme_marker . '{color:' . self::safe_color( $ctx ) . ';}';

		self::write_file( $theme_file, $theme_css );

		$path_slug = rawurlencode( $slug );

		return array(
			'slug'                    => $slug,
			'settingsContext'         => new \WP_Block_Editor_Context(
				array(
					'name' => 'core/edit-site',
				)
			),
			'settingsCategory'        => array(
				'slug'  => 'component-fuzz-' . $slug,
				'title' => 'Component Fuzz ' . $slug,
				'icon'  => null,
			),
			'settingsAllowedBlocks'   => array(
				'core/paragraph',
				'core/image',
				'component-fuzz/' . $slug,
			),
			'customSettings'          => array(
				'imageEditing'         => $ctx->bool(),
				'componentFuzzSetting' => array(
					'id'      => $slug,
					'enabled' => $ctx->bool(),
					'limit'   => $ctx->int( 1, 50 ),
				),
			),
			'imageSizeSlug'           => 'component-fuzz-' . $slug,
			'imageSizeName'           => 'Component Fuzz ' . $slug,
			'legacyWidgetHidden'      => array(
				'component_fuzz_' . str_replace( '-', '_', $slug ),
				'component_fuzz_extra_' . $ctx->int( 1, 999 ),
			),
			'themePalette'            => array(
				array(
					'name'  => 'Component Primary',
					'slug'  => 'component-primary-' . $slug,
					'color' => self::safe_color( $ctx ),
				),
			),
			'themeFontSizes'          => array(
				array(
					'name' => 'Component Small',
					'slug' => 'component-small-' . $slug,
					'size' => $ctx->int( 11, 18 ) . 'px',
				),
			),
			'themeGradients'          => array(
				array(
					'name'     => 'Component Gradient',
					'slug'     => 'component-gradient-' . $slug,
					'gradient' => 'linear-gradient(90deg,' . self::safe_color( $ctx ) . ',' . self::safe_color( $ctx ) . ')',
				),
			),
			'themeSpacingSizes'       => array(
				array(
					'name' => 'Component Spacing',
					'slug' => 'component-spacing-' . $slug,
					'size' => $ctx->int( 1, 8 ) . 'rem',
				),
			),
			'themeStyleRelativeFile'  => basename( $theme_file ),
			'themeStyleFile'          => $theme_file,
			'themeStyleUri'           => $theme_uri,
			'themeCssMarker'          => $theme_marker,
			'bindingBlockName'        => 'component-fuzz/' . $slug . '-binding',
			'bindingAttributes'       => array( 'content', 'url-' . $slug ),
			'iframeBlockName'         => 'component-fuzz/' . $slug . '-iframe',
			'iframeStyleHandle'       => 'component-fuzz-iframe-' . $slug,
			'iframeStyleMarker'       => 'cfz-iframe-' . $slug,
			'preloadGetPath'          => 'component-fuzz/v1/' . $path_slug . '/?a=1',
			'preloadGetRoute'         => '/component-fuzz/v1/' . $slug,
			'preloadGetKey'           => '/component-fuzz/v1/' . $slug . '?a=1',
			'preloadOptionsPath'      => 'component-fuzz/v1/' . $path_slug . '-options/?b=2/',
			'preloadOptionsRoute'     => '/component-fuzz/v1/' . $slug . '-options',
			'preloadOptionsKey'       => '/component-fuzz/v1/' . $slug . '-options?b=2',
			'preloadInvalidMethodPath' => 'component-fuzz/v1/' . $path_slug . '-invalid',
			'preloadInvalidMethodRoute' => '/component-fuzz/v1/' . $slug . '-invalid',
			'preloadInvalidMethodKey' => '/component-fuzz/v1/' . $slug . '-invalid',
			'preloadInvalidMethod'    => $ctx->choice( array( 'POST', 'PATCH', 'DELETE' ) ),
			'leakyScriptHandle'       => 'component-fuzz-leaky-script-' . $slug,
			'leakyStyleHandle'        => 'component-fuzz-leaky-style-' . $slug,
		);
	}

	private static function context_cases( \ComponentFuzz\FuzzContext $ctx, array $base_case ): array {
		$cases = array();
		for ( $i = 0; $i < self::CATEGORY_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'case-' . $i );
			$slug     = self::slug( $case_ctx, 'category' );
			$post     = $case_ctx->bool( 50 ) ? self::synthetic_post( $case_ctx, 'category-post' ) : null;
			$name     = $case_ctx->choice(
				array(
					'core/edit-post',
					'core/edit-site',
					'core/edit-widgets',
					'core/customize-widgets',
					'component-fuzz/' . $slug,
				)
			);
			$settings = array( 'name' => $name );
			if ( $post instanceof \WP_Post ) {
				$settings['post'] = $post;
			}
			$context = new \WP_Block_Editor_Context( $settings );

			$use_post_input = $post instanceof \WP_Post && $case_ctx->bool( 50 );
			$allowed        = $case_ctx->choice(
				array(
					true,
					false,
					array(
						'core/paragraph',
						'component-fuzz/' . $slug,
						$base_case['bindingBlockName'],
					),
				)
			);
			$category       = array(
				'slug'  => 'component-fuzz-' . $slug,
				'title' => 'Component Fuzz ' . $slug,
				'icon'  => null,
			);

			$cases[] = array(
				'context'                 => $context,
				'categoryInput'           => $use_post_input ? $post : $context,
				'inputKind'               => $use_post_input ? 'post' : 'context',
				'category'                => $category,
				'allowed'                 => $allowed,
				'expectedCategoryContext' => $use_post_input
					? self::context_summary( new \WP_Block_Editor_Context( array( 'post' => $post ) ) )
					: self::context_summary( $context ),
			);
		}

		return $cases;
	}

	private static function block_tree_case( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$target_name = 'component-fuzz/' . self::slug( $ctx, 'target' );
		$missing     = $target_name . '-missing';
		$target      = array(
			'blockName'    => $target_name,
			'attrs'        => array(
				'id'    => $case['slug'],
				'depth' => 2,
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>target</p>',
			'innerContent' => array( '<p>target</p>' ),
		);
		$later       = array(
			'blockName'    => $target_name,
			'attrs'        => array( 'id' => 'later' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>later</p>',
			'innerContent' => array( '<p>later</p>' ),
		);
		$blocks      = array(
			array(
				'blockName'    => 'core/group',
				'attrs'        => array( 'className' => 'component-fuzz-group' ),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/paragraph',
						'attrs'        => array( 'placeholder' => $ctx->identifier( 3, 10 ) ),
						'innerBlocks'  => array(),
						'innerHTML'    => '<p>intro</p>',
						'innerContent' => array( '<p>intro</p>' ),
					),
					array(
						'blockName'    => 'core/columns',
						'attrs'        => array(),
						'innerBlocks'  => array( $target ),
						'innerHTML'    => '',
						'innerContent' => array(),
					),
				),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$later,
		);

		return array(
			'blocks'        => $blocks,
			'targetName'    => $target_name,
			'missingName'   => $missing,
			'expectedBlock' => $target,
		);
	}

	private static function install_theme_supports( array $case ): void {
		\add_theme_support( 'editor-styles' );
		\add_theme_support( 'editor-color-palette', $case['themePalette'] );
		\add_theme_support( 'editor-font-sizes', $case['themeFontSizes'] );
		\add_theme_support( 'editor-gradient-presets', $case['themeGradients'] );
		\add_theme_support( 'editor-spacing-sizes', $case['themeSpacingSizes'] );
		\add_theme_support( 'custom-line-height' );
		\add_theme_support( 'custom-spacing' );
		\add_theme_support( 'custom-units', array( 'px', 'em', 'rem', 'vh', 'vw' ) );
	}

	private static function install_local_theme_style_filters( array $case ): array {
		$GLOBALS['editor_styles'] = array( $case['themeStyleRelativeFile'] );

		$path_filter = static function ( string $path, string $file ) use ( $case ): string {
			return $case['themeStyleRelativeFile'] === $file ? $case['themeStyleFile'] : $path;
		};
		$uri_filter  = static function ( string $url, string $file ) use ( $case ): string {
			return $case['themeStyleRelativeFile'] === $file ? $case['themeStyleUri'] : $url;
		};

		\add_filter( 'theme_file_path', $path_filter, 10, 2 );
		\add_filter( 'theme_file_uri', $uri_filter, 10, 2 );

		return array(
			array( 'theme_file_path', $path_filter, 10 ),
			array( 'theme_file_uri', $uri_filter, 10 ),
		);
	}

	private static function remove_filters( array $filters ): void {
		foreach ( $filters as $filter ) {
			\remove_filter( $filter[0], $filter[1], $filter[2] );
		}
	}

	private static function prepare_asset_globals(): void {
		if ( ! isset( $GLOBALS['wp_scripts'] ) || ! $GLOBALS['wp_scripts'] instanceof \WP_Scripts ) {
			$GLOBALS['wp_scripts'] = null;
			\wp_scripts();
		}
		if ( ! isset( $GLOBALS['wp_styles'] ) || ! $GLOBALS['wp_styles'] instanceof \WP_Styles ) {
			$GLOBALS['wp_styles'] = null;
			\wp_styles();
		}
	}

	private static function extract_preload_data( array $inline_after ): array {
		$script = implode( "\n", array_map( 'strval', $inline_after ) );
		if ( ! preg_match( '/createPreloadingMiddleware\\( (.*) \\) \\);/s', $script, $matches ) ) {
			return array();
		}

		$decoded = json_decode( $matches[1], true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function request_seen( array $requests, string $method, string $route ): bool {
		foreach ( $requests as $request ) {
			if ( $method === ( $request['method'] ?? null ) && $route === ( $request['route'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function contains_style_marker( array $styles, string $marker ): bool {
		foreach ( $styles as $style ) {
			if ( is_array( $style ) && isset( $style['css'] ) && str_contains( (string) $style['css'], $marker ) ) {
				return true;
			}
		}

		return false;
	}

	private static function default_category_prefix_ok( array $categories ): bool {
		$slugs = self::category_slugs( $categories );
		return isset( $slugs[0], $slugs[1], $slugs[2] )
			&& 'text' === $slugs[0]
			&& 'media' === $slugs[1]
			&& 'design' === $slugs[2];
	}

	private static function category_slugs( array $categories ): array {
		$slugs = array();
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && isset( $category['slug'] ) ) {
				$slugs[] = $category['slug'];
			}
		}

		return $slugs;
	}

	private static function synthetic_post( \ComponentFuzz\FuzzContext $ctx, string $label ): \WP_Post {
		$slug = self::slug( $ctx, $label );
		return new \WP_Post(
			(object) array(
				'ID'                    => $ctx->int( 1, 999999 ),
				'post_author'           => '1',
				'post_date'             => '2026-06-23 00:00:00',
				'post_date_gmt'         => '2026-06-23 00:00:00',
				'post_content'          => '<!-- wp:paragraph --><p>Component fuzz</p><!-- /wp:paragraph -->',
				'post_title'            => 'Component Fuzz ' . $slug,
				'post_excerpt'          => '',
				'post_status'           => 'draft',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $slug,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 00:00:00',
				'post_modified_gmt'     => '2026-06-23 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'https://example.test/?p=' . $slug,
				'menu_order'            => 0,
				'post_type'             => $ctx->choice( array( 'post', 'page' ) ),
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			)
		);
	}

	private static function context_summary( $context ): array {
		if ( ! $context instanceof \WP_Block_Editor_Context ) {
			return array(
				'class' => is_object( $context ) ? get_class( $context ) : gettype( $context ),
			);
		}

		return array(
			'class'    => 'WP_Block_Editor_Context',
			'name'     => $context->name,
			'postId'   => $context->post instanceof \WP_Post ? (int) $context->post->ID : null,
			'postType' => $context->post instanceof \WP_Post ? $context->post->post_type : null,
		);
	}

	private static function post_summary( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return is_object( $post ) ? get_class( $post ) : gettype( $post );
		}

		return array(
			'id'     => (int) $post->ID,
			'type'   => $post->post_type,
			'title'  => $post->post_title,
			'status' => $post->post_status,
		);
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$value = strtolower( $prefix . '-' . $ctx->identifier( 4, 12 ) . '-' . $ctx->int( 0, 9999 ) );
		$value = preg_replace( '/[^a-z0-9-]+/', '-', $value );
		$value = trim( (string) $value, '-' );
		return '' === $value ? $prefix . '-empty' : $value;
	}

	private static function safe_color( \ComponentFuzz\FuzzContext $ctx ): string {
		return sprintf( '#%02x%02x%02x', $ctx->int( 0, 255 ), $ctx->int( 0, 255 ), $ctx->int( 0, 255 ) );
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): string {
		$root = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
			. DIRECTORY_SEPARATOR
			. 'component-fuzz-block-editor-adjuncts-'
			. getmypid()
			. '-'
			. $ctx->iteration()
			. '-'
			. substr( sha1( (string) $ctx->seed() ), 0, 8 );

		if ( file_exists( $root ) ) {
			self::remove_dir_recursive( $root );
		}
		if ( ! mkdir( $root, 0777, true ) && ! is_dir( $root ) ) {
			throw new \RuntimeException( 'Could not create block editor adjunct temp root: ' . $root );
		}

		return $root;
	}

	private static function write_file( string $path, string $contents ): void {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create temp directory: ' . $dir );
		}
		if ( false === file_put_contents( $path, $contents ) ) {
			throw new \RuntimeException( 'Could not write temp file: ' . $path );
		}
	}

	private static function remove_dir_recursive( string $dir ): bool {
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-block-editor-adjuncts-';
		if ( ! str_starts_with( $dir, $temp_prefix ) || ! file_exists( $dir ) ) {
			return ! file_exists( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return @unlink( $dir );
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

		return @rmdir( $dir );
	}

	private static function snapshot_state(): array {
		$block_registry = self::get_static_property_raw( 'WP_Block_Type_Registry', 'instance' );

		return array(
			'globals'       => self::snapshot_globals(
				array(
					'wp_filter',
					'wp_filters',
					'wp_actions',
					'wp_current_filter',
					'wp_scripts',
					'wp_styles',
					'wp_rest_server',
					'post',
					'post_ID',
					'editor_styles',
					'_wp_theme_features',
					'_wp_additional_image_sizes',
				)
			),
			'blockRegistry' => $block_registry,
			'blockTypes'    => $block_registry instanceof \WP_Block_Type_Registry
				? self::get_object_property( $block_registry, 'registered_block_types' )
				: null,
			'statics'       => self::snapshot_statics(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) {
			self::set_object_property( $snapshot['blockRegistry'], 'registered_block_types', $snapshot['blockTypes'] );
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', $snapshot['blockRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', null );
		}

		foreach ( $snapshot['statics'] as $entry ) {
			self::set_static_property( $entry['class'], $entry['property'], $entry['value'] );
		}
	}

	private static function state_restored( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		foreach ( $snapshot['statics'] as $entry ) {
			if ( self::get_static_property_raw( $entry['class'], $entry['property'] ) !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
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

	private static function snapshot_statics(): array {
		$statics = array();
		foreach (
			array(
				array( 'WP_Theme_JSON', 'blocks_metadata' ),
			) as $entry
		) {
			if ( ! class_exists( $entry[0] ) ) {
				continue;
			}
			$statics[ $entry[0] . '::' . $entry[1] ] = array(
				'class'    => $entry[0],
				'property' => $entry[1],
				'value'    => self::get_static_property( $entry[0], $entry[1] ),
			);
		}

		return $statics;
	}

	private static function get_static_property_raw( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		return $reflection->getValue();
	}

	private static function get_static_property( string $class, string $property ) {
		return self::clone_value( self::get_static_property_raw( $class, $property ) );
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

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}
		if ( $value instanceof \Closure ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			try {
				return clone $value;
			} catch ( \Throwable $e ) {
				return $value;
			}
		}

		return $value;
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function record_if_false( array &$failures, bool $ok, string $check, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'check' => $check,
			'data'  => $data,
		);
	}

	private static function preview( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > self::PREVIEW_BYTES ? substr( $value, 0, self::PREVIEW_BYTES ) . '...' : $value;
		}
		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			return false === $json ? '[array]' : self::preview( $json );
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		return $value;
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
