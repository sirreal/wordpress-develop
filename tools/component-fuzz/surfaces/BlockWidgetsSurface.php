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

			$rows[] = self::check_dynamic_classname_matrix( $ctx->fork( 'class-matrix' ) );
			$rows[] = self::check_block_widget_rendering( $ctx->fork( 'render' ) );
			$rows[] = self::check_update_and_form_escaping( $ctx->fork( 'update-form' ) );
			$rows[] = self::check_widgets_block_editor_support( $ctx->fork( 'support' ) );
			$rows[] = self::check_the_widget_and_control_rendering( $ctx->fork( 'the-widget-control' ) );
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
				'add_action',
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
				'remove_action',
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
				'wp_render_widget_control',
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

	private static function check_dynamic_classname_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$widget   = new \WP_Widget_Block();
		$seen     = array();
		$cases    = array(
			array( 'core/paragraph', 'widget_text', '<!-- wp:paragraph --><p>Paragraph ' . $ctx->identifier( 3, 8 ) . '</p><!-- /wp:paragraph -->' ),
			array( 'core/calendar', 'widget_calendar', '<!-- wp:calendar /-->' ),
			array( 'core/search', 'widget_search', '<!-- wp:search {"label":"Find"} /-->' ),
			array( 'core/html', 'widget_custom_html', '<!-- wp:html --><div>Custom</div><!-- /wp:html -->' ),
			array( 'core/archives', 'widget_archive', '<!-- wp:archives /-->' ),
			array( 'core/latest-posts', 'widget_recent_entries', '<!-- wp:latest-posts /-->' ),
			array( 'core/latest-comments', 'widget_recent_comments', '<!-- wp:latest-comments /-->' ),
			array( 'core/tag-cloud', 'widget_tag_cloud', '<!-- wp:tag-cloud /-->' ),
			array( 'core/categories', 'widget_categories', '<!-- wp:categories /-->' ),
			array( 'core/audio', 'widget_media_audio', '<!-- wp:audio {"id":1} /-->' ),
			array( 'core/video', 'widget_media_video', '<!-- wp:video {"id":2} /-->' ),
			array( 'core/image', 'widget_media_image', '<!-- wp:image {"id":3} --><figure><img src="https://example.test/image.jpg" alt=""></figure><!-- /wp:image -->' ),
			array( 'core/gallery', 'widget_media_gallery', '<!-- wp:gallery {"ids":[1,2]} --><figure></figure><!-- /wp:gallery -->' ),
			array( 'core/rss', 'widget_rss', '<!-- wp:rss {"feedURL":"https://example.test/feed/"} /-->' ),
			array( 'component-fuzz/unknown', null, '<!-- wp:component-fuzz/unknown --><p>Unknown</p><!-- /wp:component-fuzz/unknown -->' ),
			array( null, null, '<p>Loose unparsed ' . $ctx->identifier( 3, 8 ) . '</p>' ),
		);
		$class_filter = static function ( string $classname, ?string $block_name ) use ( &$seen ): string {
			$seen[] = array(
				'classname' => $classname,
				'blockName' => $block_name,
			);
			return $classname;
		};

		\add_filter( 'widget_block_dynamic_classname', $class_filter, 10, 2 );
		try {
			foreach ( $cases as $index => $case ) {
				list( $block_name, $legacy_class, $content ) = $case;
				ob_start();
				$widget->widget(
					array(
						'before_widget' => '<aside id="matrix-' . $index . '" class="widget widget_block marker">',
						'after_widget'  => '</aside>',
					),
					array( 'content' => $content )
				);
				$output = ob_get_clean();

				self::collect_failure(
					$failures,
					str_contains( $output, 'class="widget widget_block' )
						&& str_contains( $output, ' marker"' )
						&& ( null !== $legacy_class || ! preg_match( '/widget_(text|calendar|search|custom_html|archive|recent_entries|recent_comments|tag_cloud|categories|media_audio|media_video|media_image|media_gallery|rss)/', $output ) )
						&& ( null === $legacy_class || str_contains( $output, $legacy_class ) )
						&& isset( $seen[ $index ] )
						&& array_key_exists( 'blockName', $seen[ $index ] )
						&& $seen[ $index ]['blockName'] === $block_name,
					'WP_Widget_Block maps parsed first block names to legacy widget classes only for known mappings',
					array(
						'index'       => $index,
						'blockName'   => $block_name,
						'legacyClass' => $legacy_class,
						'seen'        => $seen[ $index ] ?? null,
						'output'      => self::preview( $output ),
					)
				);
			}
		} finally {
			\remove_filter( 'widget_block_dynamic_classname', $class_filter, 10 );
		}

		self::collect_failure(
			$failures,
			count( $cases ) === count( $seen )
				&& false === \has_filter( 'widget_block_dynamic_classname', $class_filter ),
			'dynamic classname filter fires once per matrix case and is removed',
			array(
				'cases' => count( $cases ),
				'seen'  => $seen,
			)
		);

		return self::result( $ctx, 'block-widgets.render.legacy-class-matrix', $failures );
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

	private static function check_the_widget_and_control_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_widget_controls, $wp_registered_widgets, $wp_widget_factory;

		$failures        = array();
		$number          = $ctx->int( 10, 99 );
		$cancel_token    = 'cancel-' . $ctx->identifier( 4, 9 );
		$marker          = 'marker-' . $ctx->identifier( 4, 9 );
		$paragraph_text  = 'Widget ' . $ctx->identifier( 4, 10 ) . ' & <unsafe>';
		$control_token   = $ctx->identifier( 4, 10 );
		$render_content  = '<!-- wp:paragraph --><p>' . \esc_html( $paragraph_text ) . '</p><!-- /wp:paragraph -->';
		$control_content = '<!-- wp:html --><textarea>' . $control_token . '</textarea><script>alert(1)</script><!-- /wp:html -->';
		$widget_id       = 'block-' . $number;
		$seen            = array(
			'display' => array(),
			'actions' => array(),
			'content' => array(),
			'order'   => array(),
		);

		$display_filter = static function ( $instance, \WP_Widget $widget, array $args ) use ( &$seen, $cancel_token, $marker ) {
			$seen['order'][] = 'display';
			$seen['display'][] = array(
				'idBase'       => $widget->id_base,
				'content'      => $instance['content'] ?? null,
				'beforeWidget' => $args['before_widget'] ?? null,
			);

			if ( str_contains( (string) ( $instance['content'] ?? '' ), $cancel_token ) ) {
				return false;
			}

			$instance['content'] .= '<!-- wp:paragraph --><p>' . \esc_html( $marker ) . '</p><!-- /wp:paragraph -->';
			return $instance;
		};
		$the_widget_action = static function ( string $widget, array $instance, array $args ) use ( &$seen ): void {
			$seen['order'][]   = 'action';
			$seen['actions'][] = array(
				'widget'       => $widget,
				'content'      => $instance['content'] ?? null,
				'beforeWidget' => $args['before_widget'] ?? null,
			);
		};
		$content_filter = static function ( string $content, array $instance, \WP_Widget_Block $widget ) use ( &$seen ): string {
			$seen['order'][]   = 'content';
			$seen['content'][] = array(
				'content' => $content,
				'idBase'  => $widget->id_base,
			);
			return $content;
		};

		\register_widget( 'WP_Widget_Block' );
		\update_option(
			'widget_block',
			array(
				$number        => array( 'content' => $control_content ),
				'_multiwidget' => 1,
			)
		);
		$wp_widget_factory->_register_widgets();

		\add_filter( 'widget_display_callback', $display_filter, 10, 3 );
		\add_action( 'the_widget', $the_widget_action, 10, 3 );
		\add_filter( 'widget_block_content', $content_filter, 10, 3 );
		try {
			ob_start();
			\the_widget(
				'WP_Widget_Block',
				array( 'content' => $render_content ),
				array(
					'before_widget' => '<aside class="%s component-fuzz-the-widget">',
					'after_widget'  => '</aside>',
				)
			);
			$rendered = ob_get_clean();

			ob_start();
			\the_widget(
				'WP_Widget_Block',
				array( 'content' => '<!-- wp:paragraph --><p>' . \esc_html( $cancel_token ) . '</p><!-- /wp:paragraph -->' ),
				array(
					'before_widget' => '<aside class="%s component-fuzz-cancelled">',
					'after_widget'  => '</aside>',
				)
			);
			$cancelled = ob_get_clean();
		} finally {
			\remove_filter( 'widget_block_content', $content_filter, 10 );
			\remove_action( 'the_widget', $the_widget_action, 10 );
			\remove_filter( 'widget_display_callback', $display_filter, 10 );
		}

		$control_output = \wp_render_widget_control( $widget_id );
		$missing_output = \wp_render_widget_control( 'block-' . ( $number + 1000 ) );
		\unregister_widget( 'WP_Widget_Block' );

		self::collect_failure(
			$failures,
			str_contains( $rendered, '<aside class="widget_block widget_text component-fuzz-the-widget">' )
				&& str_contains( $rendered, \esc_html( $paragraph_text ) )
				&& str_contains( $rendered, \esc_html( $marker ) )
				&& '' === $cancelled
				&& 2 === count( $seen['display'] )
				&& 1 === count( $seen['actions'] )
				&& 1 === count( $seen['content'] )
				&& array( 'display', 'action', 'content', 'display' ) === $seen['order']
				&& 'block' === ( $seen['display'][0]['idBase'] ?? null )
				&& str_contains( (string) ( $seen['display'][0]['beforeWidget'] ?? '' ), 'widget_block' )
				&& str_contains( (string) ( $seen['display'][1]['content'] ?? '' ), $cancel_token )
				&& 'WP_Widget_Block' === ( $seen['actions'][0]['widget'] ?? null )
				&& str_contains( (string) ( $seen['actions'][0]['content'] ?? '' ), $marker )
				&& 'block' === ( $seen['content'][0]['idBase'] ?? null )
				&& str_contains( (string) ( $seen['content'][0]['content'] ?? '' ), $marker ),
			'the_widget applies display callbacks before action/rendering and honors cancellation',
			array(
				'rendered'  => self::preview( $rendered ),
				'cancelled' => self::preview( $cancelled ),
				'seen'      => $seen,
			)
		);
		self::collect_failure(
			$failures,
			isset( $wp_registered_widgets[ $widget_id ], $wp_registered_widget_controls[ $widget_id ] )
				&& $number === ( $wp_registered_widget_controls[ $widget_id ]['params'][0]['number'] ?? null )
				&& 400 === ( $wp_registered_widget_controls[ $widget_id ]['width'] ?? null )
				&& 350 === ( $wp_registered_widget_controls[ $widget_id ]['height'] ?? null )
				&& is_string( $control_output )
				&& str_contains( $control_output, 'name="widget-block[' . $number . '][content]"' )
				&& str_contains( $control_output, '&lt;textarea&gt;' )
				&& str_contains( $control_output, '&lt;script&gt;alert(1)&lt;/script&gt;' )
				&& ! str_contains( $control_output, '<textarea>' . $control_token . '</textarea>' )
				&& ! str_contains( $control_output, '<script>alert' )
				&& null === $missing_output,
			'registered block widget controls retain number/dimensions and escape stored block HTML',
			array(
				'widgetId'       => $widget_id,
				'registered'     => $wp_registered_widgets[ $widget_id ] ?? null,
				'control'        => $wp_registered_widget_controls[ $widget_id ] ?? null,
				'controlOutput'  => self::preview( is_string( $control_output ) ? $control_output : '' ),
				'missingOutput'  => $missing_output,
			)
		);

		return self::result( $ctx, 'block-widgets.the-widget-and-control-rendering', $failures );
	}

	private static function check_sidebars_widget_mapping( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_widget_factory, $wp_registered_widgets;

		self::reset_runtime();

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
