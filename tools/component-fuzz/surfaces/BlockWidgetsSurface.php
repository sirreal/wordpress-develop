<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes block-backed widgets and block widget option mapping.
 */
final class BlockWidgetsSurface {
	public const NAME = 'block-widgets';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'block-widgets.bootstrap-apis-available',
					'Required WordPress block widget APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_block_widget_rendering( $ctx->fork( 'render' ) );
			$rows[] = self::check_update_and_form_escaping( $ctx->fork( 'update-form' ) );
			$rows[] = self::check_widgets_block_editor_support( $ctx->fork( 'support' ) );
			$rows[] = self::check_sidebars_widget_mapping( $ctx->fork( 'mapping' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'block-widgets.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'block-widgets.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'optionsTracked' => count( $snapshot['options'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Widget', 'WP_Widget_Block', 'WP_Widget_Factory' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_remove_unregistered_widgets',
				'add_filter',
				'add_theme_support',
				'current_user_can',
				'do_blocks',
				'get_option',
				'get_theme_support',
				'is_active_widget',
				'parse_blocks',
				'register_sidebar',
				'register_widget',
				'remove_filter',
				'remove_theme_support',
				'retrieve_widgets',
				'the_widget',
				'unregister_sidebar',
				'unregister_widget',
				'update_option',
				'wp_get_sidebars_widgets',
				'wp_kses_post',
				'wp_map_sidebars_widgets',
				'wp_parse_widget_id',
				'wp_render_widget',
				'wp_set_current_user',
				'wp_set_sidebars_widgets',
				'wp_setup_widgets_block_editor',
				'wp_use_widgets_block_editor',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_block_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::block_case( $ctx );
		$widget   = new \WP_Widget_Block();
		$seen     = array(
			'classes' => array(),
			'content' => array(),
		);

		$class_filter = static function ( string $classname, ?string $block_name ) use ( &$seen ): string {
			$seen['classes'][] = array(
				'classname' => $classname,
				'blockName' => $block_name,
			);
			return $classname . ' component-fuzz-dynamic';
		};
		$content_filter = static function ( string $content, array $instance, \WP_Widget_Block $current_widget ) use ( &$seen ): string {
			$seen['content'][] = array(
				'content'  => $content,
				'instance' => $instance,
				'idBase'   => $current_widget->id_base,
			);
			return \do_blocks( $content ) . '<span data-component-fuzz="filtered"></span>';
		};

		\add_filter( 'widget_block_dynamic_classname', $class_filter, 10, 2 );
		\add_filter( 'widget_block_content', $content_filter, 10, 3 );
		try {
			ob_start();
			$widget->widget(
				array(
					'before_widget' => '<section id="block-2" class="widget widget_block marker">',
					'after_widget'  => '</section>',
					'before_title'  => '<h2>',
					'after_title'   => '</h2>',
				),
				array( 'content' => $case['content'] )
			);
			$output = ob_get_clean();
		} finally {
			\remove_filter( 'widget_block_content', $content_filter, 10 );
			\remove_filter( 'widget_block_dynamic_classname', $class_filter, 10 );
		}

		self::collect_failure(
			$failures,
			str_contains( $output, $case['expectedClass'] )
				&& str_contains( $output, 'component-fuzz-dynamic' )
				&& str_contains( $output, 'data-component-fuzz="filtered"' )
				&& ! str_contains( $output, '<!-- wp:' )
				&& isset( $seen['classes'][0], $seen['content'][0] )
				&& $case['blockName'] === $seen['classes'][0]['blockName']
				&& 'block' === $seen['content'][0]['idBase'],
			'WP_Widget_Block renders dynamic legacy class and filtered block content',
			array(
				'case'   => $case,
				'seen'   => $seen,
				'output' => self::preview( $output ),
			)
		);

		$unknown = new \WP_Widget_Block();
		ob_start();
		$unknown->widget(
			array(
				'before_widget' => '<aside class="widget widget_block">',
				'after_widget'  => '</aside>',
			),
			array( 'content' => '<!-- wp:component-fuzz/unknown --><p>Unknown</p><!-- /wp:component-fuzz/unknown -->' )
		);
		$unknown_output = ob_get_clean();
		self::collect_failure(
			$failures,
			str_contains( $unknown_output, 'class="widget widget_block"' )
				&& ! str_contains( $unknown_output, 'widget_text' )
				&& ! str_contains( $unknown_output, 'widget_search' ),
			'unknown block widget content keeps the base widget_block class',
			array( 'output' => self::preview( $unknown_output ) )
		);

		return self::result( $ctx, 'block-widgets.render.dynamic-class-and-content-filters', $failures );
	}

	private static function check_update_and_form_escaping( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$widget   = new \WP_Widget_Block();
		$unsafe   = '<!-- wp:html --><script>alert(1)</script><p>' . $ctx->identifier( 3, 10 ) . ' & value</p><!-- /wp:html -->';

		\wp_set_current_user( 0 );
		$sanitized = $widget->update( array( 'content' => $unsafe ), array() );

		$grant_unfiltered = static function ( array $allcaps ): array {
			$allcaps['unfiltered_html'] = true;
			return $allcaps;
		};
		\add_filter( 'user_has_cap', $grant_unfiltered );
		try {
			$trusted = $widget->update( array( 'content' => $unsafe ), array() );
		} finally {
			\remove_filter( 'user_has_cap', $grant_unfiltered );
		}

		$widget->_set( $ctx->int( 2, 9 ) );
		ob_start();
		$widget->form( array( 'content' => '<textarea>x</textarea>&' . $ctx->identifier( 3, 8 ) ) );
		$form = ob_get_clean();

		self::collect_failure(
			$failures,
			is_array( $sanitized )
				&& ! str_contains( $sanitized['content'], '<script' )
				&& str_contains( $sanitized['content'], '<p>' )
				&& is_array( $trusted )
				&& str_contains( $trusted['content'], '<script>alert(1)</script>' ),
			'WP_Widget_Block::update sanitizes without unfiltered_html and preserves trusted HTML with capability',
			array(
				'unsafe'    => self::preview( $unsafe ),
				'sanitized' => $sanitized,
				'trusted'   => $trusted,
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $form, 'name="widget-block[' . $widget->number . '][content]"' )
				&& str_contains( $form, '&lt;textarea&gt;x&lt;/textarea&gt;&amp;' )
				&& ! str_contains( $form, '<textarea>x</textarea>' ),
			'WP_Widget_Block::form escapes stored block HTML in the textarea field',
			array( 'form' => self::preview( $form ) )
		);

		return self::result( $ctx, 'block-widgets.update-and-form.capability-sensitive-escaping', $failures );
	}

	private static function check_widgets_block_editor_support( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		\remove_theme_support( 'widgets-block-editor' );
		$disabled = \wp_use_widgets_block_editor();
		\wp_setup_widgets_block_editor();
		$enabled = \wp_use_widgets_block_editor();

		$force_false = static fn (): bool => false;
		$force_true  = static fn (): bool => true;
		\add_filter( 'use_widgets_block_editor', $force_false );
		$filtered_false = \wp_use_widgets_block_editor();
		\remove_filter( 'use_widgets_block_editor', $force_false );
		\remove_theme_support( 'widgets-block-editor' );
		\add_filter( 'use_widgets_block_editor', $force_true );
		$filtered_true = \wp_use_widgets_block_editor();
		\remove_filter( 'use_widgets_block_editor', $force_true );

		$wide = ( new \WP_Widget_Block() )->set_is_wide_widget_in_customizer( true, 'block-' . $ctx->int( 1, 99 ) );
		$other_wide = ( new \WP_Widget_Block() )->set_is_wide_widget_in_customizer( true, 'text-' . $ctx->int( 1, 99 ) );

		self::collect_failure(
			$failures,
			false === (bool) $disabled
				&& true === (bool) $enabled
				&& false === $filtered_false
				&& true === $filtered_true
				&& false === $wide
				&& true === $other_wide,
			'widgets block editor support toggles through theme support, filters, and customizer width guard',
			array(
				'disabled'      => $disabled,
				'enabled'       => $enabled,
				'filteredFalse' => $filtered_false,
				'filteredTrue'  => $filtered_true,
				'wide'          => $wide,
				'otherWide'     => $other_wide,
			)
		);

		return self::result( $ctx, 'block-widgets.editor-support.theme-filter-and-width-guard', $failures );
	}

	private static function check_sidebars_widget_mapping( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_widget_factory, $wp_registered_widgets;

		$failures = array();
		$primary  = 'sidebar-primary-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 6 );
		$footer   = 'footer-' . substr( hash( 'crc32b', 'footer:' . $ctx->seed() ), 0, 6 );

		\register_sidebar(
			array(
				'id'            => $primary,
				'name'          => 'Component Fuzz Primary',
				'before_widget' => '<section id="%1$s" class="widget %2$s">',
				'after_widget'  => '</section>',
			)
		);
		\register_sidebar(
			array(
				'id'            => $footer,
				'name'          => 'Component Fuzz Footer',
				'before_widget' => '<section id="%1$s" class="widget %2$s">',
				'after_widget'  => '</section>',
			)
		);
		\update_option(
			'widget_block',
			array(
				2              => array( 'content' => '<!-- wp:paragraph --><p>Mapped ' . $ctx->identifier( 3, 8 ) . '</p><!-- /wp:paragraph -->' ),
				3              => array( 'content' => '<!-- wp:search /-->' ),
				'_multiwidget' => 1,
			)
		);
		\register_widget( 'WP_Widget_Block' );
		$wp_widget_factory->_register_widgets();

		$parsed = \wp_parse_widget_id( 'block-3' );
		$mapped = \wp_map_sidebars_widgets(
			array(
				'sidebar-old'        => array( 'block-2', 'missing-1' ),
				'orphaned_widgets_1' => array( 'block-3' ),
				'wp_inactive_widgets' => array( 'block-4' ),
			)
		);
		$clean = \_wp_remove_unregistered_widgets( $mapped, array( 'block-2', 'block-3' ) );
		\wp_set_sidebars_widgets( $clean );
		$GLOBALS['_wp_sidebars_widgets'] = $clean;
		$stored = \wp_get_sidebars_widgets();

		$rendered = \wp_render_widget( 'block-2', $primary );
		$active   = \is_active_widget( false, 'block-2', 'block', false );

		\unregister_widget( 'WP_Widget_Block' );
		\unregister_sidebar( $primary );
		\unregister_sidebar( $footer );

		self::collect_failure(
			$failures,
			array( 'id_base' => 'block', 'number' => 3 ) === $parsed
				&& in_array( 'block-2', $clean[ $primary ] ?? array(), true )
				&& in_array( 'block-3', $clean['wp_inactive_widgets'] ?? array(), true )
				&& ! in_array( 'missing-1', $clean[ $primary ] ?? array(), true )
				&& ! in_array( 'block-4', $clean['wp_inactive_widgets'] ?? array(), true )
				&& $stored[ $primary ] === $clean[ $primary ]
				&& is_string( $rendered )
				&& str_contains( $rendered, 'widget_block' )
				&& false !== $active
				&& isset( $wp_registered_widgets['block-2'] ),
			'block widget sidebars map, persist, render, and report active widget instances',
			array(
				'parsed'     => $parsed,
				'mapped'     => $mapped,
				'clean'      => $clean,
				'stored'     => $stored,
				'rendered'   => self::preview( $rendered ),
				'active'     => $active,
				'registered' => array_keys( $wp_registered_widgets ),
			)
		);

		return self::result( $ctx, 'block-widgets.sidebars.mapping-persistence-and-rendering', $failures );
	}

	private static function block_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'blockName'      => 'core/paragraph',
				'expectedClass'  => 'widget_text',
				'content'        => '<!-- wp:paragraph --><p>Paragraph ' . $ctx->identifier( 3, 8 ) . '</p><!-- /wp:paragraph -->',
			),
			array(
				'blockName'      => 'core/search',
				'expectedClass'  => 'widget_search',
				'content'        => '<!-- wp:search {"label":"Find ' . $ctx->identifier( 3, 8 ) . '"} /-->',
			),
			array(
				'blockName'      => 'core/html',
				'expectedClass'  => 'widget_custom_html',
				'content'        => '<!-- wp:html --><div>Custom ' . $ctx->identifier( 3, 8 ) . '</div><!-- /wp:html -->',
			),
			array(
				'blockName'      => 'core/rss',
				'expectedClass'  => 'widget_rss',
				'content'        => '<!-- wp:rss {"feedURL":"https://example.test/feed/"} /-->',
			),
		);

		return $ctx->choice( $cases );
	}

	private static function reset_runtime(): void {
		$GLOBALS['wp_registered_sidebars']        = array();
		$GLOBALS['wp_registered_widgets']         = array();
		$GLOBALS['wp_registered_widget_controls'] = array();
		$GLOBALS['wp_registered_widget_updates']  = array();
		$GLOBALS['_wp_sidebars_widgets']          = array();
		$GLOBALS['sidebars_widgets']              = array();
		$GLOBALS['wp_widget_factory']             = new \WP_Widget_Factory();
		$GLOBALS['_wp_theme_features']            = array();

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'home'             => 'http://example.test',
					'siteurl'          => 'http://example.test',
					'sidebars_widgets' => array(),
					'widget_block'     => array(),
				)
			);
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		\wp_set_current_user( 0 );
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'_wp_sidebars_widgets',
				'_wp_theme_features',
				'current_user',
				'sidebars_widgets',
				'user_ID',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_registered_sidebars',
				'wp_registered_widget_controls',
				'wp_registered_widget_updates',
				'wp_registered_widgets',
				'wp_widget_factory',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'globals' => $globals,
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			if ( $snapshot['options'] !== $GLOBALS['wpdb']->component_fuzz_get_options() ) {
				return false;
			}
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
		}

		return true;
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $details = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'details' => $details,
			);
		}
	}

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
		);
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
