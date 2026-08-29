<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes safe classic editor configuration helpers.
 */
final class EditorHelpersSurface {
	public const NAME = 'editor-helpers';

	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'editor-helpers.bootstrap-apis-available',
					'Required WordPress editor helper APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$ob_level = ob_get_level();
		$rows     = array();

		try {
			self::prepare_editor_globals();
			$case = self::case_for_context( $ctx );

			$rows[] = self::check_parse_settings( $ctx->fork( 'parse-settings' ), $case );
			$rows[] = self::check_default_editor_selection( $ctx->fork( 'default-editor' ), $case );
			$rows[] = self::check_full_editor_settings( $ctx->fork( 'full-settings' ), $case );
			$rows[] = self::check_teeny_editor_settings( $ctx->fork( 'teeny-settings' ), $case );
			$rows[] = self::check_enqueue_scripts( $ctx->fork( 'enqueue-scripts' ), $case );
			$rows[] = self::check_editor_markup( $ctx->fork( 'editor-markup' ), $case );
			$rows[] = self::check_mce_translation( $ctx->fork( 'mce-translation' ), $case );
			$rows[] = self::check_tinymce_inline_scripts( $ctx->fork( 'tinymce-inline-scripts' ), $case );
			$rows[] = self::check_link_query_and_dialog( $ctx->fork( 'link-query-dialog' ), $case );
			$rows[] = self::check_media_view_styles( $ctx->fork( 'media-view-styles' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'editor-helpers.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'editor-helpers.global-state-restored',
			self::state_restored( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedStatics' => array_keys( $snapshot['editorStatics'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		self::load_editor_class();

		$missing = array();
		foreach ( array( '_WP_Editors', 'WP_Post', 'WP_Query', 'WP_Rewrite', 'WP_Scripts', 'WP_Styles' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_thickbox',
				'esc_attr',
				'get_permalink',
				'get_post_type_object',
				'get_post_types',
				'has_filter',
				'mysql2date',
				'remove_action',
				'remove_filter',
				'register_post_type',
				'user_can_richedit',
				'unregister_post_type',
				'wp_cache_delete',
				'wp_cache_set',
				'wp_default_editor',
				'wp_editor',
				'wp_enqueue_script',
				'wp_nonce_field',
				'wp_enqueue_style',
				'wp_parse_url',
				'wp_print_scripts',
				'wp_print_styles',
				'wp_script_is',
				'wp_scripts',
				'wp_style_is',
				'wp_styles',
				'wp_tinymce_inline_scripts',
				'wpview_media_sandbox_styles',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function load_editor_class(): void {
		if ( ! class_exists( '_WP_Editors', false ) ) {
			require_once ABSPATH . WPINC . '/class-wp-editor.php';
		}

		global $tinymce_version;
		if ( ! isset( $tinymce_version ) || '' === $tinymce_version ) {
			require ABSPATH . WPINC . '/version.php';
		}
	}

	private static function check_parse_settings( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures   = array();
		$seen       = array();
		$rich_seen  = 0;
		$rich_filter = static function () use ( &$rich_seen ): bool {
			++$rich_seen;
			return true;
		};
		$settings_filter = static function ( array $settings, string $editor_id ) use ( &$seen, $case ): array {
			$seen[]                    = $editor_id;
			$settings['textarea_name'] = $case['textareaName'];
			$settings['editor_height'] = $case['parseHeight'];
			$settings['tabindex']      = $case['tabindex'];
			return $settings;
		};

		self::reset_editor_statics();
		unset( $GLOBALS['wp_rich_edit'] );

		\add_filter( 'user_can_richedit', $rich_filter );
		\add_filter( 'wp_editor_settings', $settings_filter, 10, 2 );
		try {
			$parsed = \_WP_Editors::parse_settings(
				$case['editorId'],
				array(
					'drag_drop_upload' => $case['dragDropUpload'],
					'quicktags'        => $case['quicktagsInput'],
					'teeny'            => false,
					'tinymce'          => $case['tinymceInput'],
				)
			);
			$statics = self::editor_statics();

			self::reset_editor_statics();
			$bracket_id = $case['editorId'] . '[field]';
			\_WP_Editors::parse_settings(
				$bracket_id,
				array(
					'quicktags' => false,
					'tinymce'   => true,
				)
			);
			$bracket_statics = self::editor_statics();
		} finally {
			\remove_filter( 'wp_editor_settings', $settings_filter, 10 );
			\remove_filter( 'user_can_richedit', $rich_filter );
		}

		$expected_height = empty( $case['parseHeight'] )
			? $case['parseHeight']
			: max( 50, min( 5000, (int) $case['parseHeight'] ) );

		self::record_if_false(
			$failures,
			$seen === array( $case['editorId'], $case['editorId'] . '[field]' )
				&& $rich_seen >= 2,
			'wp_editor_settings and user_can_richedit filters are scoped to parse_settings calls',
			array(
				'seenEditorIds' => $seen,
				'richSeen'      => $rich_seen,
			)
		);
		self::record_if_false(
			$failures,
			$case['textareaName'] === $parsed['textarea_name']
				&& $expected_height === $parsed['editor_height']
				&& $case['tabindex'] === $parsed['tabindex'],
			'parse_settings applies filtered settings and clamps non-empty editor heights',
			array(
				'expectedHeight' => $expected_height,
				'parsed'         => self::preview( $parsed ),
			)
		);
		self::record_if_false(
			$failures,
			true === $statics['this_tinymce']
				&& true === $statics['has_tinymce']
				&& true === $statics['this_quicktags']
				&& true === $statics['has_quicktags'],
			'parse_settings records current and aggregate TinyMCE/Quicktags state',
			array( 'statics' => self::preview( $statics ) )
		);
		self::record_if_false(
			$failures,
			false === $bracket_statics['this_tinymce']
				&& false === $bracket_statics['this_quicktags'],
			'parse_settings disables TinyMCE for editor IDs containing brackets',
			array(
				'bracketId'      => $bracket_id,
				'bracketStatics' => self::preview( $bracket_statics ),
			)
		);

		return self::row(
			$ctx,
			'editor-helpers.parse-settings.normalization-and-state',
			$failures,
			array(
				'editorId'    => $case['editorId'],
				'inputHeight' => $case['parseHeight'],
			)
		);
	}

	private static function check_default_editor_selection( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures      = array();
		$base_seen     = array();
		$rich_filter   = static function () use ( $case ): bool {
			return $case['richEditing'];
		};
		$default_filter = static function ( string $default ) use ( &$base_seen, $case ): string {
			$base_seen[] = $default;
			return $case['defaultOverride'];
		};

		unset( $GLOBALS['wp_rich_edit'] );
		\add_filter( 'user_can_richedit', $rich_filter );
		try {
			$base = \wp_default_editor();
			\add_filter( 'wp_default_editor', $default_filter );
			$filtered = \wp_default_editor();
		} finally {
			\remove_filter( 'wp_default_editor', $default_filter );
			\remove_filter( 'user_can_richedit', $rich_filter );
			unset( $GLOBALS['wp_rich_edit'] );
		}

		$expected_base = $case['richEditing'] ? 'tinymce' : 'html';
		if ( \wp_get_current_user() ) {
			$user_setting  = \get_user_setting( 'editor', 'tinymce' );
			$expected_base = in_array( $user_setting, array( 'tinymce', 'html', 'test' ), true ) ? $user_setting : $expected_base;
		}

		self::record_if_false(
			$failures,
			$expected_base === $base,
			'wp_default_editor follows rich-edit capability and user-setting fallback order',
			array(
				'expected'    => $expected_base,
				'actual'      => $base,
				'richEditing' => $case['richEditing'],
			)
		);
		self::record_if_false(
			$failures,
			$case['defaultOverride'] === $filtered
				&& $base_seen === array( $expected_base ),
			'wp_default_editor filter receives the computed default and can override it',
			array(
				'override' => $case['defaultOverride'],
				'filtered' => $filtered,
				'seen'     => $base_seen,
			)
		);

		return self::row(
			$ctx,
			'editor-helpers.default-editor.filters-and-rich-edit',
			$failures,
			array( 'override' => $case['defaultOverride'] )
		);
	}

	private static function check_full_editor_settings( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$seen     = self::install_full_editor_filters( $case );

		try {
			$first = self::generate_full_editor_js_output( $case );
			$second = self::generate_full_editor_js_output( $case );
		} finally {
			self::remove_full_editor_filters( $case, $seen );
		}

		$statics = $first['statics'];
		$mce     = $statics['mce_settings'][ $case['editorId'] ] ?? array();
		$qt      = $statics['qt_settings'][ $case['editorId'] ] ?? array();

		self::record_if_false(
			$failures,
			! $first['call']['threw']
				&& ! $second['call']['threw']
				&& $first['output'] === $second['output']
				&& str_contains( $first['output'], 'tinyMCEPreInit' )
				&& str_contains( $first['output'], $case['button1'] )
				&& str_contains( $first['output'], $case['quicktagsButton'] ),
			'editor_js output is deterministic for identical generated settings',
			array(
				'firstCall'  => self::describe_call( $first['call'] ),
				'secondCall' => self::describe_call( $second['call'] ),
				'firstHash'  => sha1( $first['output'] ),
				'secondHash' => sha1( $second['output'] ),
				'preview'    => self::preview( $first['output'] ),
			)
		);
		self::record_if_false(
			$failures,
			isset( $qt['id'], $qt['buttons'] )
				&& $case['editorId'] === $qt['id']
				&& str_contains( $qt['buttons'], $case['quicktagsButton'] )
				&& in_array( $case['quicktagsButton'], $statics['qt_buttons'], true ),
			'quicktags_settings filter updates per-editor settings and aggregate button state',
			array(
				'qt'        => self::preview( $qt ),
				'qtButtons' => self::preview( $statics['qt_buttons'] ),
			)
		);
		self::record_if_false(
			$failures,
			isset( $mce['plugins'], $mce['external_plugins'] )
				&& str_contains( $mce['plugins'], $case['plugin'] )
				&& ! str_contains( $mce['plugins'], 'spellchecker' )
				&& self::external_plugin_registered( $mce['external_plugins'], $case ),
			'tiny_mce_plugins removes spellchecker and preserves filtered external plugin config',
			array(
				'plugins'         => $mce['plugins'] ?? null,
				'externalPlugins' => $mce['external_plugins'] ?? null,
			)
		);
		self::record_if_false(
			$failures,
			isset( $mce['toolbar1'], $mce['toolbar3'], $mce['toolbar4'] )
				&& str_contains( $mce['toolbar1'], $case['button1'] )
				&& $case['button4'] === $mce['toolbar3']
				&& '' === $mce['toolbar4'],
			'mce button filters update toolbars and toolbar4 rolls up to toolbar3 when toolbar3 is empty',
			array(
				'toolbar1' => $mce['toolbar1'] ?? null,
				'toolbar3' => $mce['toolbar3'] ?? null,
				'toolbar4' => $mce['toolbar4'] ?? null,
			)
		);
		self::record_if_false(
			$failures,
			isset( $mce['content_css'], $mce['body_class'], $mce['component_fuzz_marker'] )
				&& str_contains( $mce['content_css'], $case['cssUrl'] )
				&& ! str_starts_with( $mce['content_css'], ',' )
				&& ! str_ends_with( $mce['content_css'], ',' )
				&& str_contains( $mce['body_class'], $case['bodyClass'] )
				&& $case['marker'] === $mce['component_fuzz_marker'],
			'tiny_mce_before_init and mce_css filters normalize generated TinyMCE settings',
			array(
				'contentCss' => $mce['content_css'] ?? null,
				'bodyClass'  => $mce['body_class'] ?? null,
				'marker'     => $mce['component_fuzz_marker'] ?? null,
			)
		);
		self::record_if_false(
			$failures,
			self::seen_counts_match(
				$seen['events'],
				array(
					'quicktags'            => 2,
					'mce_external_plugins' => 2,
					'tiny_mce_plugins'     => 2,
					'mce_buttons'          => 2,
					'mce_buttons_2'        => 2,
					'mce_buttons_3'        => 2,
					'mce_buttons_4'        => 2,
					'tiny_mce_before_init' => 2,
					'mce_css'              => 2,
				)
			),
			'full editor filters fire exactly once per generated settings pass',
			array( 'seen' => self::preview( self::seen_events_to_array( $seen['events'] ) ) )
		);

		return self::row(
			$ctx,
			'editor-helpers.full-settings.filters-scripts-and-state',
			$failures,
			array( 'editorId' => $case['editorId'] )
		);
	}

	private static function check_teeny_editor_settings( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$seen     = array(
			'teeny_mce_plugins'     => array(),
			'teeny_mce_buttons'     => array(),
			'teeny_mce_before_init' => array(),
			'tiny_mce_plugins'      => array(),
		);

		$plugins_filter = static function ( array $plugins, string $editor_id ) use ( &$seen, $case ): array {
			$seen['teeny_mce_plugins'][] = $editor_id;
			$plugins[]                   = $case['teenyPlugin'];
			return $plugins;
		};
		$buttons_filter = static function ( array $buttons, string $editor_id ) use ( &$seen, $case ): array {
			$seen['teeny_mce_buttons'][] = $editor_id;
			$buttons[]                   = $case['teenyButton'];
			return $buttons;
		};
		$before_filter = static function ( array $init, string $editor_id ) use ( &$seen, $case ): array {
			$seen['teeny_mce_before_init'][] = $editor_id;
			$init['component_fuzz_teeny']    = $case['marker'];
			return $init;
		};
		$full_filter   = static function ( array $plugins, string $editor_id ) use ( &$seen ): array {
			$seen['tiny_mce_plugins'][] = $editor_id;
			return $plugins;
		};
		$rich_filter   = static fn (): bool => true;

		self::reset_scripts_and_styles();
		self::reset_editor_statics();
		unset( $GLOBALS['wp_rich_edit'] );

		\add_filter( 'user_can_richedit', $rich_filter );
		\add_filter( 'teeny_mce_plugins', $plugins_filter, 10, 2 );
		\add_filter( 'teeny_mce_buttons', $buttons_filter, 10, 2 );
		\add_filter( 'teeny_mce_before_init', $before_filter, 10, 2 );
		\add_filter( 'tiny_mce_plugins', $full_filter, 10, 2 );
		try {
			$set = \_WP_Editors::parse_settings(
				$case['teenyEditorId'],
				array(
					'media_buttons' => false,
					'quicktags'     => false,
					'teeny'         => true,
					'tinymce'       => true,
				)
			);
			\_WP_Editors::editor_settings( $case['teenyEditorId'], $set );
			$statics = self::editor_statics();
		} finally {
			\remove_filter( 'tiny_mce_plugins', $full_filter, 10 );
			\remove_filter( 'teeny_mce_before_init', $before_filter, 10 );
			\remove_filter( 'teeny_mce_buttons', $buttons_filter, 10 );
			\remove_filter( 'teeny_mce_plugins', $plugins_filter, 10 );
			\remove_filter( 'user_can_richedit', $rich_filter );
			unset( $GLOBALS['wp_rich_edit'] );
		}

		$mce = $statics['mce_settings'][ $case['teenyEditorId'] ] ?? array();

		self::record_if_false(
			$failures,
			isset( $mce['plugins'], $mce['toolbar1'], $mce['toolbar2'], $mce['component_fuzz_teeny'] )
				&& str_contains( $mce['plugins'], $case['teenyPlugin'] )
				&& str_contains( $mce['toolbar1'], $case['teenyButton'] )
				&& '' === $mce['toolbar2']
				&& $case['marker'] === $mce['component_fuzz_teeny'],
			'teeny editor settings use teeny plugin/button/init filters and keep secondary toolbars empty',
			array( 'mce' => self::preview( $mce ) )
		);
		self::record_if_false(
			$failures,
			$seen['teeny_mce_plugins'] === array( $case['teenyEditorId'] )
				&& $seen['teeny_mce_buttons'] === array( $case['teenyEditorId'] )
				&& $seen['teeny_mce_before_init'] === array( $case['teenyEditorId'] )
				&& array() === $seen['tiny_mce_plugins'],
			'teeny settings do not invoke full TinyMCE plugin filter',
			array( 'seen' => $seen )
		);

		return self::row(
			$ctx,
			'editor-helpers.teeny-settings.filter-branch',
			$failures,
			array( 'editorId' => $case['teenyEditorId'] )
		);
	}

	private static function check_enqueue_scripts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures       = array();
		$targeted_seen  = array();
		$default_seen   = array();
		$expects_wplink = 'wplink' === $case['enqueuePlugin'] || 'link' === $case['enqueueQuicktag'];
		$targeted_hook  = static function ( array $to_load ) use ( &$targeted_seen ): void {
			$targeted_seen[] = $to_load;
		};
		$default_hook   = static function ( array $to_load ) use ( &$default_seen ): void {
			$default_seen[] = $to_load;
		};

		self::reset_scripts_and_styles();
		self::register_editor_enqueue_handles();
		self::reset_editor_statics();
		self::set_editor_static_property( 'has_tinymce', true );
		self::set_editor_static_property( 'has_quicktags', true );
		self::set_editor_static_property( 'has_medialib', $case['enqueueMedia'] );
		self::set_editor_static_property( 'plugins', array( $case['enqueuePlugin'] ) );
		self::set_editor_static_property( 'qt_buttons', array( $case['enqueueQuicktag'] ) );

		\add_action( 'wp_enqueue_editor', $targeted_hook );
		try {
			\_WP_Editors::enqueue_scripts( false );
			$targeted_queue = self::editor_enqueue_state();
		} finally {
			\remove_action( 'wp_enqueue_editor', $targeted_hook );
		}

		self::reset_scripts_and_styles();
		self::register_editor_enqueue_handles();
		self::reset_editor_statics();

		\add_action( 'wp_enqueue_editor', $default_hook );
		try {
			\_WP_Editors::enqueue_scripts( true );
			$default_queue = self::editor_enqueue_state();
		} finally {
			\remove_action( 'wp_enqueue_editor', $default_hook );
		}

		self::record_if_false(
			$failures,
			array(
				array(
					'tinymce'   => true,
					'quicktags' => true,
				),
			) === $targeted_seen
				&& $targeted_queue['scripts']['editor']
				&& $targeted_queue['scripts']['quicktags']
				&& $targeted_queue['styles']['buttons']
				&& $expects_wplink === $targeted_queue['scripts']['wplink']
				&& $expects_wplink === $targeted_queue['scripts']['jquery-ui-autocomplete']
				&& $case['enqueueMedia'] === $targeted_queue['scripts']['media-upload']
				&& $case['enqueueMedia'] === $targeted_queue['scripts']['wp-embed']
				&& $case['enqueueMedia'] === $targeted_queue['scripts']['thickbox']
				&& $case['enqueueMedia'] === $targeted_queue['styles']['thickbox'],
			'enqueue_scripts loads targeted editor, link, and optional media handles from editor static state',
			array(
				'seen'   => $targeted_seen,
				'queue'  => $targeted_queue,
				'case'   => array(
					'media'     => $case['enqueueMedia'],
					'plugin'    => $case['enqueuePlugin'],
					'quicktag'  => $case['enqueueQuicktag'],
					'wplink'    => $expects_wplink,
				),
			)
		);
		self::record_if_false(
			$failures,
			array(
				array(
					'tinymce'   => true,
					'quicktags' => true,
				),
			) === $default_seen
				&& $default_queue['scripts']['editor']
				&& $default_queue['scripts']['quicktags']
				&& $default_queue['styles']['buttons']
				&& $default_queue['scripts']['wplink']
				&& $default_queue['scripts']['jquery-ui-autocomplete']
				&& $default_queue['scripts']['media-upload']
				&& ! $default_queue['scripts']['wp-embed']
				&& $default_queue['scripts']['thickbox']
				&& ! $default_queue['styles']['thickbox'],
			'enqueue_scripts default mode loads editor/link/media-upload handles, including script dependencies, without forcing embeds or Thickbox styles',
			array(
				'seen'  => $default_seen,
				'queue' => $default_queue,
			)
		);

		return self::row(
			$ctx,
			'editor-helpers.enqueue-scripts.handle-selection-and-action-payload',
			$failures,
			array(
				'media'    => $case['enqueueMedia'],
				'targeted' => $targeted_queue,
				'default'  => $default_queue,
			)
		);
	}

	private static function check_editor_markup( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures           = array();
		$the_editor_seen    = array();
		$content_seen       = array();
		$default_seen       = array();
		$rich_filter        = static fn (): bool => true;
		$default_filter     = static function ( string $default ) use ( &$default_seen, $case ): string {
			$default_seen[] = $default;
			return $case['markupDefaultEditor'];
		};
		$the_editor_filter  = static function ( string $html ) use ( &$the_editor_seen, $case ): string {
			$the_editor_seen[] = str_contains( $html, '%s' );
			return str_replace(
				'class="wp-editor-container"',
				'class="wp-editor-container" data-component-fuzz="' . \esc_attr( $case['marker'] ) . '"',
				$html
			);
		};
		$content_filter     = static function ( string $content, string $default_editor ) use ( &$content_seen ): string {
			$content_seen[] = $default_editor;
			return $content;
		};
		$format_filter_was = \has_filter( 'the_editor_content', 'format_for_editor' );

		self::reset_scripts_and_styles();
		self::reset_editor_statics();
		self::set_editor_static_property( 'editor_buttons_css', false );
		unset( $GLOBALS['wp_rich_edit'] );

		\add_filter( 'user_can_richedit', $rich_filter );
		\add_filter( 'wp_default_editor', $default_filter );
		\add_filter( 'the_editor', $the_editor_filter );
		\add_filter( 'the_editor_content', $content_filter, 20, 2 );
		try {
			$call = self::capture_output(
				static function () use ( $case ): void {
					\wp_editor(
						$case['dangerousContent'],
						$case['markupEditorId'],
						array(
							'editor_class'    => $case['editorClass'],
							'editor_height'   => $case['markupHeight'],
							'media_buttons'   => false,
							'quicktags'       => true,
							'textarea_name'   => $case['textareaName'],
							'textarea_rows'   => 4,
							'tinymce'         => array(
								'wp_skip_init' => true,
							),
						)
					);
				}
			);
			$statics = self::editor_statics();
		} finally {
			\remove_filter( 'the_editor_content', $content_filter, 20 );
			\remove_filter( 'the_editor', $the_editor_filter );
			\remove_filter( 'wp_default_editor', $default_filter );
			\remove_filter( 'user_can_richedit', $rich_filter );
			unset( $GLOBALS['wp_rich_edit'] );
		}

		$output              = $call['output'];
		$expected_wrap_class = 'html' === $case['markupDefaultEditor'] ? 'html-active' : 'tmce-active';
		$escaped_name        = \esc_attr( $case['textareaName'] );
		$escaped_id          = \esc_attr( $case['markupEditorId'] );

		self::record_if_false(
			$failures,
			! $call['threw']
				&& str_contains( $output, 'id="' . $escaped_id . '"' )
				&& str_contains( $output, 'name="' . $escaped_name . '"' )
				&& str_contains( $output, $expected_wrap_class )
				&& str_contains( $output, 'data-component-fuzz="' . \esc_attr( $case['marker'] ) . '"' ),
			'wp_editor emits captured markup with escaped editor attributes and expected active tab',
			array(
				'call'              => self::describe_call( $call ),
				'expectedWrapClass' => $expected_wrap_class,
				'preview'           => self::preview( $output ),
			)
		);
		self::record_if_false(
			$failures,
			str_contains( $output, '&lt;/textarea' )
				&& ! str_contains( $output, '</textarea><script' ),
			'wp_editor neutralizes textarea-closing content before output',
			array( 'preview' => self::preview( $output ) )
		);
		self::record_if_false(
			$failures,
			$the_editor_seen === array( true )
				&& $content_seen === array( $case['markupDefaultEditor'] )
				&& $default_seen === array( 'tinymce' ),
			'the_editor, the_editor_content, and wp_default_editor filters are local and contextual',
			array(
				'theEditorSeen' => $the_editor_seen,
				'contentSeen'   => $content_seen,
				'defaultSeen'   => $default_seen,
			)
		);
		self::record_if_false(
			$failures,
			isset( $statics['mce_settings'][ $case['markupEditorId'] ], $statics['qt_settings'][ $case['markupEditorId'] ] )
				&& \has_filter( 'the_editor_content', 'format_for_editor' ) === $format_filter_was,
			'wp_editor records per-editor settings and removes its temporary content filter',
			array(
				'hasMce'             => isset( $statics['mce_settings'][ $case['markupEditorId'] ] ),
				'hasQuicktags'       => isset( $statics['qt_settings'][ $case['markupEditorId'] ] ),
				'formatFilterBefore' => $format_filter_was,
				'formatFilterAfter'  => \has_filter( 'the_editor_content', 'format_for_editor' ),
			)
		);

		return self::row(
			$ctx,
			'editor-helpers.editor-markup.output-buffer-escaping-and-filters',
			$failures,
			array( 'editorId' => $case['markupEditorId'] )
		);
	}

	private static function check_mce_translation( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$seen     = array();
		$filter   = static function ( array $translations, string $locale ) use ( &$seen, $case ): array {
			$seen[] = $locale;
			$translations['Component Fuzz Same']   = 'Component Fuzz Same';
			$translations['Component Fuzz Entity'] = 'Ampersand &amp; Entity';
			$translations['Component Fuzz Marker'] = $case['translationMarker'];
			return $translations;
		};

		self::reset_editor_statics();
		\add_filter( 'wp_mce_translation', $filter, 10, 2 );
		try {
			$json_one = \_WP_Editors::wp_mce_translation( 'en', true );
			$json_two = \_WP_Editors::wp_mce_translation( 'en', true );
			$script   = \_WP_Editors::wp_mce_translation( 'en', false );
		} finally {
			\remove_filter( 'wp_mce_translation', $filter, 10 );
		}

		$decoded = json_decode( $json_one, true );

		self::record_if_false(
			$failures,
			is_array( $decoded )
				&& $json_one === $json_two
				&& ! isset( $decoded['Component Fuzz Same'] )
				&& 'Ampersand & Entity' === ( $decoded['Component Fuzz Entity'] ?? null )
				&& $case['translationMarker'] === ( $decoded['Component Fuzz Marker'] ?? null ),
			'wp_mce_translation JSON output is deterministic, filtered, and entity-normalized',
			array(
				'seen'        => $seen,
				'jsonPreview' => self::preview( $json_one ),
				'jsonError'   => json_last_error_msg(),
			)
		);
		self::record_if_false(
			$failures,
			str_contains( $script, "tinymce.addI18n( 'en', " )
				&& str_contains( $script, '/langs/en.js' )
				&& ! str_contains( $script, '<script' ),
			'wp_mce_translation script output is a bounded snippet without script tags',
			array( 'scriptPreview' => self::preview( $script ) )
		);

		return self::row(
			$ctx,
			'editor-helpers.mce-translation.json-and-script-snippet',
			$failures,
			array( 'marker' => $case['translationMarker'] )
		);
	}

	private static function check_tinymce_inline_scripts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$events   = array(
			'editor_settings'       => array(),
			'tiny_mce_plugins'      => array(),
			'disable_captions'      => 0,
			'mce_buttons'           => array(),
			'mce_buttons_2'         => array(),
			'mce_buttons_3'         => array(),
			'mce_buttons_4'         => array(),
			'mce_external_plugins'  => array(),
			'tiny_mce_before_init'  => array(),
		);
		$inline_plugin       = 'cf_inline_plugin_' . self::safe_key( $case['marker'], 'plugin' );
		$inline_button       = 'cf_inline_button_' . self::safe_key( $case['button1'], 'button' );
		$inline_button_two   = 'cf_inline_button2_' . self::safe_key( $case['button2'], 'button2' );
		$inline_button_three = 'cf_inline_button3_' . self::safe_key( $case['button4'], 'button3' );
		$inline_button_four  = 'cf_inline_button4_' . self::safe_key( $case['teenyButton'], 'button4' );
		$external_plugin     = 'cf_inline_external_' . self::safe_key( $case['externalPlugin'], 'external' );
		$external_url        = 'https://example.test/classic-block/' . rawurlencode( $case['marker'] ) . '/plugin.js';
		$plain_setting       = 'plain-' . self::safe_key( $case['marker'], 'plain' );
		$json_setting        = '{"marker":"' . self::safe_key( $case['marker'], 'json' ) . '"}';
		$array_setting       = '["' . self::safe_key( $case['marker'], 'array' ) . '"]';
		$function_setting    = 'function () { return "' . self::safe_key( $case['marker'], 'fn' ) . '"; }';

		$settings_filter = static function ( array $settings, string $editor_id ) use ( &$events, $plain_setting, $json_setting, $array_setting, $function_setting ): array {
			$events['editor_settings'][] = array(
				'editorId' => $editor_id,
				'input'    => $settings,
			);
			$settings['tinymce'] = array(
				'component_plain'    => $plain_setting,
				'component_json'     => $json_setting,
				'component_array'    => $array_setting,
				'component_function' => $function_setting,
				'wp_autoresize_on'   => true,
			);
			return $settings;
		};
		$plugins_filter = static function ( array $plugins, string $editor_id ) use ( &$events, $inline_plugin ): array {
			$events['tiny_mce_plugins'][] = $editor_id;
			$plugins[]                    = $inline_plugin;
			$plugins[]                    = $inline_plugin;
			return $plugins;
		};
		$disable_filter = static function () use ( &$events ): bool {
			++$events['disable_captions'];
			return true;
		};
		$buttons_filter = static function ( array $buttons, string $editor_id ) use ( &$events, $inline_button ): array {
			$events['mce_buttons'][] = $editor_id;
			$buttons[]               = $inline_button;
			return $buttons;
		};
		$buttons_two_filter = static function ( array $buttons, string $editor_id ) use ( &$events, $inline_button_two ): array {
			$events['mce_buttons_2'][] = $editor_id;
			$buttons[]                 = $inline_button_two;
			return $buttons;
		};
		$buttons_three_filter = static function ( array $buttons, string $editor_id ) use ( &$events, $inline_button_three ): array {
			$events['mce_buttons_3'][] = $editor_id;
			$buttons[]                 = $inline_button_three;
			return $buttons;
		};
		$buttons_four_filter = static function ( array $buttons, string $editor_id ) use ( &$events, $inline_button_four ): array {
			$events['mce_buttons_4'][] = $editor_id;
			$buttons[]                 = $inline_button_four;
			return $buttons;
		};
		$external_filter = static function ( array $plugins, string $editor_id ) use ( &$events, $external_plugin, $external_url ): array {
			$events['mce_external_plugins'][] = $editor_id;
			$plugins[ $external_plugin ]      = $external_url;
			return $plugins;
		};
		$before_filter = static function ( array $settings, string $editor_id ) use ( &$events, $case ): array {
			$events['tiny_mce_before_init'][] = array(
				'editorId' => $editor_id,
				'settings' => $settings,
			);
			$settings['component_before_marker'] = $case['marker'];
			return $settings;
		};

		self::reset_scripts_and_styles();
		\wp_scripts()->add( 'wp-block-library', false );

		\add_filter( 'wp_editor_settings', $settings_filter, 10, 2 );
		\add_filter( 'tiny_mce_plugins', $plugins_filter, 10, 2 );
		\add_filter( 'disable_captions', $disable_filter );
		\add_filter( 'mce_buttons', $buttons_filter, 10, 2 );
		\add_filter( 'mce_buttons_2', $buttons_two_filter, 10, 2 );
		\add_filter( 'mce_buttons_3', $buttons_three_filter, 10, 2 );
		\add_filter( 'mce_buttons_4', $buttons_four_filter, 10, 2 );
		\add_filter( 'mce_external_plugins', $external_filter, 10, 2 );
		\add_filter( 'tiny_mce_before_init', $before_filter, 10, 2 );
		try {
			\wp_tinymce_inline_scripts();
			$before_data = \wp_scripts()->get_data( 'wp-block-library', 'before' );
		} finally {
			\remove_filter( 'tiny_mce_before_init', $before_filter, 10 );
			\remove_filter( 'mce_external_plugins', $external_filter, 10 );
			\remove_filter( 'mce_buttons_4', $buttons_four_filter, 10 );
			\remove_filter( 'mce_buttons_3', $buttons_three_filter, 10 );
			\remove_filter( 'mce_buttons_2', $buttons_two_filter, 10 );
			\remove_filter( 'mce_buttons', $buttons_filter, 10 );
			\remove_filter( 'disable_captions', $disable_filter );
			\remove_filter( 'tiny_mce_plugins', $plugins_filter, 10 );
			\remove_filter( 'wp_editor_settings', $settings_filter, 10 );
		}

		$inline_scripts = is_array( $before_data ) ? array_values( array_filter( $before_data, 'is_string' ) ) : array();
		$script         = implode( "\n", $inline_scripts );
		$captured       = $events['tiny_mce_before_init'][0]['settings'] ?? array();

		self::record_if_false(
			$failures,
			( $events['editor_settings'][0]['editorId'] ?? null ) === 'classic-block'
				&& array( 'tinymce' => true ) === ( $events['editor_settings'][0]['input'] ?? null )
				&& $events['tiny_mce_plugins'] === array( 'classic-block' )
				&& 1 === $events['disable_captions']
				&& $events['mce_buttons'] === array( 'classic-block' )
				&& $events['mce_buttons_2'] === array( 'classic-block' )
				&& $events['mce_buttons_3'] === array( 'classic-block' )
				&& $events['mce_buttons_4'] === array( 'classic-block' )
				&& $events['mce_external_plugins'] === array( 'classic-block' )
				&& array( 'classic-block' ) === array_column( $events['tiny_mce_before_init'], 'editorId' ),
			'wp_tinymce_inline_scripts applies classic-block editor filters exactly once and in the expected branch',
			array( 'events' => self::preview( $events ) )
		);
		self::record_if_false(
			$failures,
			isset( $captured['plugins'], $captured['toolbar1'], $captured['toolbar2'], $captured['toolbar3'], $captured['toolbar4'], $captured['external_plugins'] )
				&& 1 === substr_count( ',' . $captured['plugins'] . ',', ',' . $inline_plugin . ',' )
				&& str_contains( $captured['toolbar1'], $inline_button )
				&& str_contains( $captured['toolbar2'], $inline_button_two )
				&& str_contains( $captured['toolbar3'], $inline_button_three )
				&& str_contains( $captured['toolbar4'], $inline_button_four )
				&& true === ( $captured['classic_block_editor'] ?? null )
				&& true === ( $captured['wpeditimage_disable_captions'] ?? null )
				&& true === ( $captured['wp_autoresize_on'] ?? null )
				&& $plain_setting === ( $captured['component_plain'] ?? null )
				&& $json_setting === ( $captured['component_json'] ?? null )
				&& $array_setting === ( $captured['component_array'] ?? null )
				&& $function_setting === ( $captured['component_function'] ?? null )
				&& $external_url === ( json_decode( $captured['external_plugins'] ?? '[]', true )[ $external_plugin ] ?? null ),
			'TinyMCE inline settings merge generated editor/plugin/button/caption/external-plugin values before serialization',
			array( 'captured' => self::preview( $captured ) )
		);
		self::record_if_false(
			$failures,
			1 === count( $inline_scripts )
				&& str_contains( $script, 'window.wpEditorL10n' )
				&& str_contains( $script, 'baseURL: "http://example.test/wp-includes/js/tinymce"' )
				&& str_contains( $script, 'component_plain:"' . $plain_setting . '"' )
				&& str_contains( $script, 'component_json:' . $json_setting )
				&& str_contains( $script, 'component_array:' . $array_setting )
				&& str_contains( $script, 'component_function:' . $function_setting )
				&& str_contains( $script, 'wp_autoresize_on:true' )
				&& str_contains( $script, 'wpeditimage_disable_captions:true' )
				&& str_contains( $script, 'component_before_marker:"' . $case['marker'] . '"' )
				&& ! str_contains( $script, '<script' ),
			'wp_tinymce_inline_scripts attaches a single before-script with expected raw, boolean, and string serialization',
			array(
				'inlineCount' => count( $inline_scripts ),
				'preview'     => self::preview( $script ),
			)
		);
		self::record_if_false(
			$failures,
			false === \has_filter( 'wp_editor_settings', $settings_filter )
				&& false === \has_filter( 'tiny_mce_plugins', $plugins_filter )
				&& false === \has_filter( 'disable_captions', $disable_filter )
				&& false === \has_filter( 'mce_buttons', $buttons_filter )
				&& false === \has_filter( 'mce_buttons_2', $buttons_two_filter )
				&& false === \has_filter( 'mce_buttons_3', $buttons_three_filter )
				&& false === \has_filter( 'mce_buttons_4', $buttons_four_filter )
				&& false === \has_filter( 'mce_external_plugins', $external_filter )
				&& false === \has_filter( 'tiny_mce_before_init', $before_filter ),
			'wp_tinymce_inline_scripts coverage removes all temporary classic-block filters',
			array( 'marker' => $case['marker'] )
		);

		return self::row(
			$ctx,
			'editor-helpers.tinymce-inline-scripts.classic-block-filter-merge',
			$failures,
			array(
				'marker'     => $case['marker'],
				'scriptHash' => sha1( $script ),
			)
		);
	}

	private static function check_link_query_and_dialog( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures   = array();
		$marker_key = 'cf_link_' . self::safe_key( $case['marker'], 'link' );
		$post_id    = 700000 + ( $ctx->seed() % 10000 );
		$cpt_id     = $post_id + 1;
		$cpt        = 'cf_link_' . self::safe_key( strtolower( substr( $case['linkTitleToken'], -5 ) ), 'cpt' );
		$cpt_label  = 'Component Link ' . strtoupper( substr( $cpt, -3 ) );
		$post       = self::link_query_post(
			$post_id,
			'post',
			'editor-link-post-' . self::safe_key( $case['marker'], 'post' ),
			'  Link Query ' . $case['linkTitleToken'] . ' <em>& More</em><script>bad</script>  ',
			'2026-06-' . str_pad( (string) $ctx->int( 1, 28 ), 2, '0', STR_PAD_LEFT ) . ' 13:24:00'
		);
		$cpt_post   = self::link_query_post(
			$cpt_id,
			$cpt,
			'editor-link-cpt-' . self::safe_key( $case['marker'], 'cpt' ),
			'Custom Link ' . $case['linkTitleToken'] . ' <strong>& Other</strong>',
			'2026-05-15 08:10:00'
		);
		$fixtures   = array( $post, $cpt_post );
		$events     = array(
			'args'    => array(),
			'queries' => array(),
			'results' => array(),
		);
		$post_type_names         = array();
		$expected_post_permalink = '';
		$expected_cpt_permalink  = '';
		$local_globals           = self::snapshot_globals( array( 'wp_rewrite' ) );

		$args_filter = static function ( array $query ) use ( &$events, $marker_key ): array {
			$events['args'][]                         = $query;
			$query['component_fuzz_link_query_marker'] = $marker_key;
			$query['cache_results']                    = false;
			return $query;
		};
		$posts_filter = static function ( $posts, \WP_Query $query ) use ( &$events, $fixtures, $case, $marker_key ) {
			if ( ( $query->query_vars['component_fuzz_link_query_marker'] ?? null ) !== $marker_key ) {
				return $posts;
			}

			$events['queries'][] = array(
				'post_type'        => $query->query_vars['post_type'] ?? null,
				's'                => $query->query_vars['s'] ?? null,
				'offset'           => $query->query_vars['offset'] ?? null,
				'posts_per_page'   => $query->query_vars['posts_per_page'] ?? null,
				'post_status'      => $query->query_vars['post_status'] ?? null,
				'suppress_filters' => $query->query_vars['suppress_filters'] ?? null,
				'cache_results'    => $query->query_vars['cache_results'] ?? null,
				'term'             => $query->query_vars['s'] ?? null,
			);

			if ( ( $query->query_vars['s'] ?? '' ) === $case['linkNoMatchSearch'] ) {
				$query->found_posts   = 0;
				$query->max_num_pages = 0;
				return array();
			}

			$query->found_posts   = count( $fixtures );
			$query->max_num_pages = 1;
			return $fixtures;
		};
		$results_filter = static function ( array $results, array $query ) use ( &$events ): array {
			$events['results'][] = array(
				'query'   => $query,
				'results' => $results,
			);
			return $results;
		};

		try {
			if ( ! isset( $GLOBALS['wp_rewrite'] ) || ! is_object( $GLOBALS['wp_rewrite'] ) ) {
				$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			}

			\register_post_type(
				$cpt,
				array(
					'public' => true,
					'labels' => array(
						'name'          => $cpt_label . 's',
						'singular_name' => $cpt_label,
					),
				)
			);

			foreach ( $fixtures as $fixture ) {
				\wp_cache_set( $fixture->ID, $fixture, 'posts' );
			}
			$post_type_names         = array_keys( \get_post_types( array( 'public' => true ), 'objects' ) );
			$expected_post_permalink = \get_permalink( $post->ID );
			$expected_cpt_permalink  = \get_permalink( $cpt_post->ID );

			\add_filter( 'wp_link_query_args', $args_filter, 10, 1 );
			\add_filter( 'posts_pre_query', $posts_filter, 10, 2 );
			\add_filter( 'wp_link_query', $results_filter, 10, 2 );
			$results = \_WP_Editors::wp_link_query(
				array(
					'pagenum' => $case['linkPageNumber'],
					's'       => $case['linkSearch'],
				)
			);
			$empty_results = \_WP_Editors::wp_link_query(
				array(
					'pagenum' => 0,
					's'       => $case['linkNoMatchSearch'],
				)
			);
		} finally {
			\remove_filter( 'wp_link_query', $results_filter, 10 );
			\remove_filter( 'posts_pre_query', $posts_filter, 10 );
			\remove_filter( 'wp_link_query_args', $args_filter, 10 );

			foreach ( $fixtures as $fixture ) {
				\wp_cache_delete( $fixture->ID, 'posts' );
			}
			if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ) {
				\unregister_post_type( $cpt );
			} else {
				unset( $GLOBALS['wp_post_types'][ $cpt ] );
				\remove_action( 'future_' . $cpt, '_future_post_hook', 5 );
			}
			self::restore_globals( $local_globals );
		}

		$expected_post_title = 'Link Query ' . $case['linkTitleToken'] . ' &amp; Morebad';
		$expected_cpt_title  = 'Custom Link ' . $case['linkTitleToken'] . ' &amp; Other';
		$expected_offset     = 20 * ( $case['linkPageNumber'] - 1 );
		$first_result        = is_array( $results ) ? ( $results[0] ?? array() ) : array();
		$second_result       = is_array( $results ) ? ( $results[1] ?? array() ) : array();

		self::record_if_false(
			$failures,
			is_array( $results )
				&& 2 === count( $results )
				&& $post->ID === ( $first_result['ID'] ?? null )
				&& $expected_post_title === ( $first_result['title'] ?? null )
				&& $expected_post_permalink === ( $first_result['permalink'] ?? null )
				&& \mysql2date( __( 'Y/m/d' ), $post->post_date ) === ( $first_result['info'] ?? null )
				&& $cpt_post->ID === ( $second_result['ID'] ?? null )
				&& $expected_cpt_title === ( $second_result['title'] ?? null )
				&& $expected_cpt_permalink === ( $second_result['permalink'] ?? null )
				&& $cpt_label === ( $second_result['info'] ?? null )
				&& false === $empty_results
				&& ! str_contains( strtolower( wp_json_encode( $results ) ?: '' ), '<script' )
				&& ! str_contains( strtolower( wp_json_encode( $results ) ?: '' ), 'javascript:' ),
			'wp_link_query returns sanitized generated post/custom-post results, permalinks, info labels, and false on empty result sets',
			array(
				'results'      => $results,
				'emptyResults' => $empty_results,
				'expected'     => array(
					'postTitle' => $expected_post_title,
					'cptTitle'  => $expected_cpt_title,
					'cptLabel'  => $cpt_label,
				),
			)
		);
		self::record_if_false(
			$failures,
			2 === count( $events['args'] )
				&& 2 === count( $events['queries'] )
				&& 2 === count( $events['results'] )
				&& $case['linkSearch'] === ( $events['args'][0]['s'] ?? null )
				&& $case['linkNoMatchSearch'] === ( $events['args'][1]['s'] ?? null )
				&& $expected_offset === ( $events['args'][0]['offset'] ?? null )
				&& 0 === ( $events['args'][1]['offset'] ?? null )
				&& 20 === ( $events['args'][0]['posts_per_page'] ?? null )
				&& true === ( $events['args'][0]['suppress_filters'] ?? null )
				&& self::same_string_set( $events['args'][0]['post_type'] ?? array(), $post_type_names )
				&& $case['linkSearch'] === ( $events['queries'][0]['s'] ?? null )
				&& $expected_offset === ( $events['queries'][0]['offset'] ?? null )
				&& 20 === ( $events['queries'][0]['posts_per_page'] ?? null )
				&& 'publish' === ( $events['queries'][0]['post_status'] ?? null )
				&& true === ( $events['queries'][0]['suppress_filters'] ?? null )
				&& false === ( $events['queries'][0]['cache_results'] ?? null )
				&& $results === ( $events['results'][0]['results'] ?? null )
				&& array() === ( $events['results'][1]['results'] ?? null ),
			'wp_link_query exposes deterministic query-argument, WP_Query, and result-filter payloads for generated searches',
			array(
				'events'          => $events,
				'postTypeNames'   => $post_type_names,
				'expectedOffset'  => $expected_offset,
			)
		);
		self::record_if_false(
			$failures,
			false === \has_filter( 'wp_link_query_args', $args_filter )
				&& false === \has_filter( 'posts_pre_query', $posts_filter )
				&& false === \has_filter( 'wp_link_query', $results_filter )
				&& false === \has_filter( 'future_' . $cpt, '_future_post_hook' ),
			'wp_link_query coverage removes temporary query/result filters and generated post-type hooks',
			array(
				'argsFilter'    => \has_filter( 'wp_link_query_args', $args_filter ),
				'postsFilter'   => \has_filter( 'posts_pre_query', $posts_filter ),
				'resultsFilter' => \has_filter( 'wp_link_query', $results_filter ),
				'futureHook'    => \has_filter( 'future_' . $cpt, '_future_post_hook' ),
			)
		);

		self::reset_editor_statics();
		$first_dialog = self::capture_output(
			static function (): void {
				\_WP_Editors::wp_link_dialog();
			}
		);
		$second_dialog = self::capture_output(
			static function (): void {
				\_WP_Editors::wp_link_dialog();
			}
		);
		$dialog_output = $first_dialog['output'] ?? '';
		self::record_if_false(
			$failures,
			empty( $first_dialog['threw'] )
				&& empty( $second_dialog['threw'] )
				&& '' === ( $second_dialog['output'] ?? '' )
				&& str_contains( $dialog_output, 'id="wp-link-backdrop"' )
				&& str_contains( $dialog_output, 'id="wp-link-wrap"' )
				&& str_contains( $dialog_output, 'role="dialog"' )
				&& str_contains( $dialog_output, 'aria-modal="true"' )
				&& str_contains( $dialog_output, 'id="wp-link-url"' )
				&& str_contains( $dialog_output, 'id="wp-link-search"' )
				&& str_contains( $dialog_output, 'name="_ajax_linking_nonce"' )
				&& str_contains( $dialog_output, 'id="search-results"' )
				&& str_contains( $dialog_output, 'id="most-recent-results"' )
				&& str_contains( $dialog_output, 'id="wp-link-submit"' )
				&& true === self::get_editor_static_property( 'link_dialog_printed' )
				&& ! str_contains( strtolower( $dialog_output ), '<script' )
				&& ! str_contains( strtolower( $dialog_output ), 'javascript:' ),
			'wp_link_dialog prints the internal-linking dialog once with nonce, search/result containers, accessibility attributes, and no script output',
			array(
				'first'  => self::describe_call( $first_dialog ),
				'second' => self::describe_call( $second_dialog ),
				'output' => self::preview( $dialog_output ),
			)
		);

		return self::row(
			$ctx,
			'editor-helpers.link-query-dialog.query-results-and-single-print-markup',
			$failures,
			array(
				'search'       => $case['linkSearch'],
				'pageNumber'   => $case['linkPageNumber'],
				'resultCount'  => is_array( $results ) ? count( $results ) : 0,
				'dialogHash'   => sha1( $dialog_output ),
			)
		);
	}

	private static function check_media_view_styles( \ComponentFuzz\FuzzContext $ctx ): array {
		$styles   = \wpview_media_sandbox_styles();
		$failures = array();
		$hosts    = array();

		foreach ( $styles as $style ) {
			$parts = \wp_parse_url( $style );
			if ( isset( $parts['host'] ) ) {
				$hosts[] = $parts['host'];
			}
		}

		self::record_if_false(
			$failures,
			2 === count( $styles )
				&& str_contains( $styles[0], 'mediaelementplayer-legacy.min.css' )
				&& str_contains( $styles[1], 'wp-mediaelement.css' )
				&& str_contains( $styles[0], 'ver=' )
				&& str_contains( $styles[1], 'ver=' ),
			'wpview_media_sandbox_styles returns deterministic versioned media-view stylesheet URLs',
			array(
				'styles' => $styles,
				'hosts'  => $hosts,
			)
		);

		return self::row(
			$ctx,
			'editor-helpers.media-view.stylesheet-urls',
			$failures,
			array( 'styles' => $styles )
		);
	}

	private static function generate_full_editor_js_output( array $case ): array {
		self::reset_scripts_and_styles();
		self::reset_editor_statics();
		unset( $GLOBALS['wp_rich_edit'] );

		$set = \_WP_Editors::parse_settings(
			$case['editorId'],
			array(
				'_content_editor_dfw' => $case['dfw'],
				'media_buttons'       => false,
				'quicktags'           => array(
					'buttons' => 'strong,em,link',
				),
				'tabfocus_elements'   => ':prev,:next',
				'teeny'               => false,
				'tinymce'             => array(
					'body_class'   => $case['bodyClass'],
					'wp_skip_init' => true,
				),
				'wpautop'             => false,
			)
		);
		\_WP_Editors::editor_settings( $case['editorId'], $set );
		$statics = self::editor_statics();
		$call    = self::capture_output(
			static function (): void {
				\_WP_Editors::editor_js();
			}
		);

		return array(
			'call'    => $call,
			'output'  => $call['output'],
			'statics' => $statics,
		);
	}

	private static function install_full_editor_filters( array $case ): array {
		$events = (object) array(
			'quicktags'            => array(),
			'mce_external_plugins' => array(),
			'tiny_mce_plugins'     => array(),
			'mce_buttons'          => array(),
			'mce_buttons_2'        => array(),
			'mce_buttons_3'        => array(),
			'mce_buttons_4'        => array(),
			'tiny_mce_before_init' => array(),
			'mce_css'              => array(),
		);
		$seen   = array( 'events' => $events );

		$seen['user_can_richedit'] = static fn (): bool => true;
		$seen['quicktags_filter']  = static function ( array $qt_init, string $editor_id ) use ( $events, $case ): array {
			$events->quicktags[]    = $editor_id;
			$qt_init['buttons']    .= ',' . $case['quicktagsButton'];
			$qt_init['componentId'] = $case['marker'];
			return $qt_init;
		};
		$seen['external_filter']   = static function ( array $plugins, string $editor_id ) use ( $events, $case ): array {
			$events->mce_external_plugins[] = $editor_id;
			$plugins[ $case['externalPlugin'] ] = $case['externalUrl'];
			return $plugins;
		};
		$seen['plugins_filter']    = static function ( array $plugins, string $editor_id ) use ( $events, $case ): array {
			$events->tiny_mce_plugins[] = $editor_id;
			$plugins[]                  = 'spellchecker';
			$plugins[]                  = $case['plugin'];
			return $plugins;
		};
		$seen['buttons_filter']    = static function ( array $buttons, string $editor_id ) use ( $events, $case ): array {
			$events->mce_buttons[] = $editor_id;
			$buttons[]             = $case['button1'];
			return $buttons;
		};
		$seen['buttons_2_filter']  = static function ( array $buttons, string $editor_id ) use ( $events, $case ): array {
			$events->mce_buttons_2[] = $editor_id;
			$buttons[]               = $case['button2'];
			return $buttons;
		};
		$seen['buttons_3_filter']  = static function ( array $buttons, string $editor_id ) use ( $events ): array {
			unset( $buttons );
			$events->mce_buttons_3[] = $editor_id;
			return array();
		};
		$seen['buttons_4_filter']  = static function ( array $buttons, string $editor_id ) use ( $events, $case ): array {
			unset( $buttons );
			$events->mce_buttons_4[] = $editor_id;
			return array( $case['button4'] );
		};
		$seen['before_filter']     = static function ( array $init, string $editor_id ) use ( $events, $case ): array {
			$events->tiny_mce_before_init[] = $editor_id;
			$init['component_fuzz_marker']  = $case['marker'];
			return $init;
		};
		$seen['css_filter']        = static function ( string $css ) use ( $events, $case ): string {
			$events->mce_css[] = $css;
			return $css . ',' . $case['cssUrl'];
		};

		\add_filter( 'user_can_richedit', $seen['user_can_richedit'] );
		\add_filter( 'quicktags_settings', $seen['quicktags_filter'], 10, 2 );
		\add_filter( 'mce_external_plugins', $seen['external_filter'], 10, 2 );
		\add_filter( 'tiny_mce_plugins', $seen['plugins_filter'], 10, 2 );
		\add_filter( 'mce_buttons', $seen['buttons_filter'], 10, 2 );
		\add_filter( 'mce_buttons_2', $seen['buttons_2_filter'], 10, 2 );
		\add_filter( 'mce_buttons_3', $seen['buttons_3_filter'], 10, 2 );
		\add_filter( 'mce_buttons_4', $seen['buttons_4_filter'], 10, 2 );
		\add_filter( 'tiny_mce_before_init', $seen['before_filter'], 10, 2 );
		\add_filter( 'mce_css', $seen['css_filter'] );

		return $seen;
	}

	private static function remove_full_editor_filters( array $case, array $seen ): void {
		unset( $case );
		\remove_filter( 'mce_css', $seen['css_filter'] );
		\remove_filter( 'tiny_mce_before_init', $seen['before_filter'], 10 );
		\remove_filter( 'mce_buttons_4', $seen['buttons_4_filter'], 10 );
		\remove_filter( 'mce_buttons_3', $seen['buttons_3_filter'], 10 );
		\remove_filter( 'mce_buttons_2', $seen['buttons_2_filter'], 10 );
		\remove_filter( 'mce_buttons', $seen['buttons_filter'], 10 );
		\remove_filter( 'tiny_mce_plugins', $seen['plugins_filter'], 10 );
		\remove_filter( 'mce_external_plugins', $seen['external_filter'], 10 );
		\remove_filter( 'quicktags_settings', $seen['quicktags_filter'], 10 );
		\remove_filter( 'user_can_richedit', $seen['user_can_richedit'] );
		unset( $GLOBALS['wp_rich_edit'] );
	}

	private static function external_plugin_registered( string $json, array $case ): bool {
		$decoded = json_decode( $json, true );
		$expected_url = function_exists( 'set_url_scheme' ) ? \set_url_scheme( $case['externalUrl'] ) : $case['externalUrl'];

		return is_array( $decoded )
			&& isset( $decoded[ $case['externalPlugin'] ] )
			&& $expected_url === $decoded[ $case['externalPlugin'] ];
	}

	private static function seen_counts_match( $seen, array $expected ): bool {
		$seen = is_object( $seen ) ? get_object_vars( $seen ) : $seen;
		foreach ( $expected as $key => $count ) {
			if ( ! isset( $seen[ $key ] ) || count( $seen[ $key ] ) !== $count ) {
				return false;
			}
		}
		return true;
	}

	private static function seen_events_to_array( object $events ): array {
		return get_object_vars( $events );
	}

	private static function link_query_post( int $id, string $post_type, string $post_name, string $post_title, string $post_date ): \WP_Post {
		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => 1,
				'post_date'             => $post_date,
				'post_date_gmt'         => $post_date,
				'post_content'          => '',
				'post_title'            => $post_title,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $post_name,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $post_date,
				'post_modified_gmt'     => $post_date,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://example.test/?p=' . $id,
				'menu_order'            => 0,
				'post_type'             => $post_type,
				'post_mime_type'        => '',
				'comment_count'         => 0,
				'filter'                => 'raw',
			)
		);
	}

	private static function same_string_set( $actual, array $expected ): bool {
		if ( ! is_array( $actual ) ) {
			return false;
		}

		$actual   = array_values( array_map( 'strval', $actual ) );
		$expected = array_values( array_map( 'strval', $expected ) );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$slug = self::safe_key( strtolower( $ctx->identifier( 4, 10 ) ), 'case' );

		return array(
			'bodyClass'           => 'cf-body-' . $slug,
			'button1'             => 'cf_btn_' . $slug,
			'button2'             => 'cf_btn2_' . $slug,
			'button4'             => 'cf_btn4_' . $slug,
			'cssUrl'              => 'https://example.test/editor-' . $slug . '.css?ver=1%2C2',
			'dangerousContent'    => "Lead {$slug}</textarea><script>alert(1)</script>\nTail",
			'defaultOverride'     => $ctx->choice( array( 'tinymce', 'html', 'test' ) ),
			'dfw'                 => $ctx->bool(),
			'dragDropUpload'      => $ctx->bool(),
			'enqueueMedia'        => $ctx->bool(),
			'enqueuePlugin'       => $ctx->choice( array( 'wplink', 'lists' ) ),
			'enqueueQuicktag'     => $ctx->choice( array( 'link', 'strong' ) ),
			'editorClass'         => 'cf-class-' . $slug . ' quoted',
			'editorId'            => 'cf_editor_' . $slug,
			'externalPlugin'      => 'cf_external_' . $slug,
			'externalUrl'         => 'https://example.test/plugins/' . $slug . '/plugin.js',
			'linkNoMatchSearch'   => 'missing-' . $slug . ' <none>',
			'linkPageNumber'      => $ctx->int( 2, 5 ),
			'linkSearch'          => 'needle ' . $slug . ' & <query>',
			'linkTitleToken'      => 'Token ' . strtoupper( substr( $slug, 0, 5 ) ),
			'markupDefaultEditor' => $ctx->choice( array( 'tinymce', 'html' ) ),
			'markupEditorId'      => 'cf_markup_' . $slug,
			'markupHeight'        => $ctx->int( 50, 420 ),
			'marker'              => 'cf-marker-' . $slug,
			'parseHeight'         => $ctx->choice( array( -20, 0, 49, 50, 51, 4999, 5000, 5001, 6400 ) ),
			'plugin'              => 'cf_plugin_' . $slug,
			'quicktagsButton'     => 'cf_qt_' . $slug,
			'quicktagsInput'      => array( 'buttons' => 'strong,em,link' ),
			'richEditing'         => $ctx->bool(),
			'tabindex'            => (string) $ctx->int( 1, 20 ),
			'teenyButton'         => 'cf_teeny_btn_' . $slug,
			'teenyEditorId'       => 'cf_teeny_' . $slug,
			'teenyPlugin'         => 'cf_teeny_plugin_' . $slug,
			'textareaName'        => 'component_fuzz[' . $slug . ']',
			'tinymceInput'        => array( 'body_class' => 'parse-body-' . $slug ),
			'translationMarker'   => 'Translated marker ' . $slug,
		);
	}

	private static function prepare_editor_globals(): void {
		self::load_editor_class();
		if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
			$_SERVER['SERVER_NAME'] = 'example.test';
		}
	}

	private static function reset_scripts_and_styles(): void {
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		$GLOBALS['wp_styles']  = new \WP_Styles();
	}

	private static function reset_editor_statics(): void {
		foreach ( self::editor_static_defaults() as $property => $value ) {
			self::set_editor_static_property( $property, $value );
		}
	}

	private static function editor_static_defaults(): array {
		return array(
			'mce_locale'               => null,
			'mce_settings'             => array(),
			'qt_settings'              => array(),
			'plugins'                  => array(),
			'qt_buttons'               => array(),
			'ext_plugins'              => null,
			'baseurl'                  => null,
			'first_init'               => null,
			'this_tinymce'             => false,
			'this_quicktags'           => false,
			'has_tinymce'              => false,
			'has_quicktags'            => false,
			'has_medialib'             => false,
			'editor_buttons_css'       => true,
			'drag_drop_upload'         => false,
			'translation'              => null,
			'tinymce_scripts_printed'  => false,
			'link_dialog_printed'      => false,
		);
	}

	private static function snapshot_state(): array {
		return array(
			'globals'       => self::snapshot_globals(
				array(
					'_updated_user_settings',
					'concatenate_scripts',
					'compress_css',
					'compress_scripts',
					'current_screen',
					'current_user',
					'is_IE',
					'is_chrome',
					'is_edge',
					'is_gecko',
					'is_opera',
					'is_safari',
					'post',
					'tinymce_version',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_post_types',
					'wp_rich_edit',
					'wp_rewrite',
					'wp_scripts',
					'wp_styles',
				)
			),
			'editorStatics' => self::snapshot_editor_statics(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		foreach ( $snapshot['editorStatics'] as $property => $entry ) {
			self::set_editor_static_property( $property, $entry['value'] );
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

		foreach ( $snapshot['editorStatics'] as $property => $entry ) {
			if ( self::get_editor_static_property_raw( $property ) !== $entry['value'] ) {
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

	private static function snapshot_editor_statics(): array {
		$statics = array();
		foreach ( array_keys( self::editor_static_defaults() ) as $property ) {
			$statics[ $property ] = array(
				'value' => self::get_editor_static_property( $property ),
			);
		}

		return $statics;
	}

	private static function editor_statics(): array {
		$statics = array();
		foreach ( array_keys( self::editor_static_defaults() ) as $property ) {
			$statics[ $property ] = self::get_editor_static_property( $property );
		}
		return $statics;
	}

	private static function register_editor_enqueue_handles(): void {
		$scripts = \wp_scripts();
		$styles  = \wp_styles();

		foreach ( array( 'editor', 'quicktags', 'wplink', 'jquery-ui-autocomplete', 'wp-embed', 'thickbox', 'shortcode' ) as $handle ) {
			$scripts->add( $handle, false );
		}
		$scripts->add( 'media-upload', false, array( 'thickbox', 'shortcode' ) );

		foreach ( array( 'buttons', 'thickbox' ) as $handle ) {
			$styles->add( $handle, false );
		}
	}

	private static function editor_enqueue_state(): array {
		$scripts = array(
			'editor',
			'quicktags',
			'wplink',
			'jquery-ui-autocomplete',
			'media-upload',
			'wp-embed',
			'thickbox',
		);
		$styles  = array(
			'buttons',
			'thickbox',
		);
		$state   = array(
			'scripts' => array(),
			'styles'  => array(),
		);

		foreach ( $scripts as $handle ) {
			$state['scripts'][ $handle ] = \wp_script_is( $handle, 'enqueued' );
		}

		foreach ( $styles as $handle ) {
			$state['styles'][ $handle ] = \wp_style_is( $handle, 'enqueued' );
		}

		return $state;
	}

	private static function get_editor_static_property_raw( string $property ) {
		$reflection = new \ReflectionProperty( '_WP_Editors', $property );
		return $reflection->getValue();
	}

	private static function get_editor_static_property( string $property ) {
		return self::clone_value( self::get_editor_static_property_raw( $property ) );
	}

	private static function set_editor_static_property( string $property, $value ): void {
		$reflection = new \ReflectionProperty( '_WP_Editors', $property );
		$reflection->setValue( null, $value );
	}

	private static function capture_output( callable $callback ): array {
		$level = ob_get_level();
		ob_start();
		try {
			$value  = $callback();
			$output = ob_get_clean();
			return array(
				'threw'  => false,
				'output' => $output,
				'value'  => $value,
			);
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return array(
				'threw'     => true,
				'output'    => '',
				'throwable' => self::describe_throwable( $e ),
			);
		}
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

	private static function safe_key( string $value, string $fallback ): string {
		$value = preg_replace( '/[^A-Za-z0-9_-]+/', '-', $value );
		$value = trim( (string) $value, '-' );

		return '' === $value ? $fallback : $value;
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

	private static function describe_call( array $call ): array {
		if ( empty( $call['threw'] ) ) {
			return array(
				'threw'       => false,
				'outputBytes' => strlen( $call['output'] ?? '' ),
			);
		}

		return array(
			'threw'     => true,
			'throwable' => $call['throwable'] ?? null,
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
}
