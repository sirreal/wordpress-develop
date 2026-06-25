<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB WordPress admin screen, settings, and meta-box APIs.
 */
final class AdminScreenSurface {
	public const NAME = 'admin-screen';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-screen.bootstrap-apis-available',
					'Required admin screen APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_screen_normalization( $ctx->fork( 'screen-normalization' ) );
			$rows[] = self::check_help_tabs_and_screen_options( $ctx->fork( 'help-screen-options' ) );
			$rows[] = self::check_screen_options_rendering( $ctx->fork( 'screen-options-rendering' ) );
			$rows[] = self::check_screen_meta_rendering_lifecycle( $ctx->fork( 'screen-meta-rendering-lifecycle' ) );
			$rows[] = self::check_column_headers( $ctx->fork( 'column-headers' ) );
			$rows[] = self::check_settings_registry( $ctx->fork( 'settings-registry' ) );
			$rows[] = self::check_settings_rendering( $ctx->fork( 'settings-rendering' ) );
			$rows[] = self::check_meta_boxes( $ctx->fork( 'meta-boxes' ) );
			$rows[] = self::check_accordion_sections( $ctx->fork( 'accordion-sections' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'admin-screen.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Screen' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_meta_box',
				'add_screen_option',
				'add_settings_field',
				'add_settings_section',
				'convert_to_screen',
				'do_accordion_sections',
				'do_meta_boxes',
				'do_settings_fields',
				'do_settings_sections',
				'esc_attr',
				'esc_html',
				'get_column_headers',
				'get_current_screen',
				'get_option',
				'get_registered_settings',
				'has_filter',
				'post_type_exists',
				'register_post_type',
				'register_setting',
				'register_taxonomy',
				'remove_action',
				'remove_filter',
				'remove_meta_box',
				'sanitize_key',
				'sanitize_html_class',
				'sanitize_option',
				'sanitize_text_field',
				'set_current_screen',
				'settings_fields',
				'taxonomy_exists',
				'unregister_setting',
				'wp_kses_post',
				'wp_parse_args',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_screen_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures  = array();
		$post_type = self::post_type_key( $ctx->fork( 'post-type' ) );
		$taxonomy  = self::taxonomy_key( $ctx->fork( 'taxonomy' ) );
		$tool_page = self::id( $ctx->fork( 'tool-page' ), 'cfz_tool_page', 36 );

		\register_post_type(
			$post_type,
			array(
				'label'        => 'Component Fuzz ' . self::fuzz_label( $ctx->fork( 'post-label' ) ),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
			)
		);
		\register_taxonomy(
			$taxonomy,
			$post_type,
			array(
				'label'        => 'Component Fuzz Tax ' . self::fuzz_label( $ctx->fork( 'tax-label' ) ),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => false,
			)
		);

		$cases = array(
			array(
				'hook'      => $post_type,
				'id'        => $post_type,
				'base'      => 'post',
				'post_type' => $post_type,
				'taxonomy'  => '',
				'action'    => '',
				'in_admin'  => 'site',
			),
			array(
				'hook'      => 'edit-' . $post_type,
				'id'        => 'edit-' . $post_type,
				'base'      => 'edit',
				'post_type' => $post_type,
				'taxonomy'  => '',
				'action'    => '',
				'in_admin'  => 'site',
			),
			array(
				'hook'      => 'edit-' . $taxonomy,
				'id'        => 'edit-' . $taxonomy,
				'base'      => 'edit-tags',
				'post_type' => 'post',
				'taxonomy'  => $taxonomy,
				'action'    => '',
				'in_admin'  => 'site',
			),
			array(
				'hook'      => 'post-new.php',
				'id'        => 'post',
				'base'      => 'post',
				'post_type' => 'post',
				'taxonomy'  => '',
				'action'    => 'add',
				'in_admin'  => 'site',
			),
			array(
				'hook'      => 'index.php',
				'id'        => 'dashboard',
				'base'      => 'dashboard',
				'post_type' => '',
				'taxonomy'  => '',
				'action'    => '',
				'in_admin'  => 'site',
			),
			array(
				'hook'      => 'front',
				'id'        => 'front',
				'base'      => 'front',
				'post_type' => '',
				'taxonomy'  => '',
				'action'    => '',
				'in_admin'  => false,
			),
			array(
				'hook'      => $tool_page . '.php',
				'id'        => \sanitize_key( $tool_page ),
				'base'      => \sanitize_key( $tool_page ),
				'post_type' => '',
				'taxonomy'  => '',
				'action'    => '',
				'in_admin'  => 'site',
			),
			array(
				'hook'      => 'edit-' . $post_type . '-network',
				'id'        => 'edit-' . $post_type . '-network',
				'base'      => 'edit-network',
				'post_type' => $post_type,
				'taxonomy'  => '',
				'action'    => '',
				'in_admin'  => 'network',
			),
		);

		foreach ( $cases as $case ) {
			$screen    = \WP_Screen::get( $case['hook'] );
			$converted = \convert_to_screen( $case['hook'] );

			self::collect_failure(
				$failures,
				$screen instanceof \WP_Screen
					&& $converted === $screen
					&& $case['id'] === $screen->id
					&& $case['base'] === $screen->base
					&& $case['post_type'] === $screen->post_type
					&& $case['taxonomy'] === $screen->taxonomy
					&& $case['action'] === $screen->action
					&& ( false === $case['in_admin'] ? false === $screen->in_admin() : $screen->in_admin( $case['in_admin'] ) ),
				'WP_Screen::get() and convert_to_screen() normalize hook names consistently',
				array(
					'case'   => $case,
					'screen' => self::describe_screen( $screen ),
				)
			);
		}

		$screen_snapshot = self::snapshot_globals( array( 'current_screen', 'typenow', 'taxnow' ) );
		$current_calls   = array();
		$listener        = static function ( \WP_Screen $screen ) use ( &$current_calls ): void {
			$current_calls[] = array(
				'id'        => $screen->id,
				'post_type' => $screen->post_type,
				'taxonomy'  => $screen->taxonomy,
			);
		};

		\add_action( 'current_screen', $listener, 10, 1 );
		$edit_screen = \WP_Screen::get( 'edit-' . $post_type );
		\set_current_screen( $edit_screen );
		$current           = \get_current_screen();
		$typenow_after_set = $GLOBALS['typenow'] ?? null;
		$taxnow_after_set  = $GLOBALS['taxnow'] ?? null;
		\remove_action( 'current_screen', $listener, 10 );
		self::restore_globals( $screen_snapshot );

		self::collect_failure(
			$failures,
			$current === $edit_screen
				&& $edit_screen->post_type === $typenow_after_set
				&& $edit_screen->taxonomy === $taxnow_after_set
				&& array( $edit_screen->id ) === array_column( $current_calls, 'id' )
				&& self::globals_match( $screen_snapshot, array( 'current_screen', 'typenow', 'taxnow' ) ),
			'set_current_screen() updates globals, fires current_screen once, and can be restored',
			array(
				'current' => self::describe_screen( $current ),
				'calls'   => $current_calls,
				'globals' => array(
					'typenow' => $typenow_after_set,
					'taxnow'  => $taxnow_after_set,
				),
			)
		);

		return self::row(
			$ctx,
			'admin-screen.screen.normalization-and-current',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 5 ) )
		);
	}

	private static function check_help_tabs_and_screen_options( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$screen   = \convert_to_screen( self::id( $ctx->fork( 'screen' ), 'cfz_help_screen', 40 ) );
		$tab_high = self::id( $ctx->fork( 'high-tab' ), 'cfz_help_high', 32 );
		$tab_mid  = self::id( $ctx->fork( 'mid-tab' ), 'cfz_help_mid', 32 );
		$tab_low  = self::id( $ctx->fork( 'low-tab' ), 'cfz_help_low', 32 );

		$screen->add_help_tab(
			array(
				'id'       => $tab_low,
				'title'    => 'Low ' . self::fuzz_label( $ctx->fork( 'low-title' ) ),
				'content'  => '<p>Low ' . \esc_html( self::fuzz_label( $ctx->fork( 'low-content' ) ) ) . '</p>',
				'priority' => 30,
			)
		);
		$screen->add_help_tab(
			array(
				'id'       => $tab_high,
				'title'    => 'High ' . self::fuzz_label( $ctx->fork( 'high-title' ) ),
				'content'  => '<p>High ' . \esc_html( self::fuzz_label( $ctx->fork( 'high-content' ) ) ) . '</p>',
				'priority' => 5,
			)
		);
		$screen->add_help_tab(
			array(
				'id'       => $tab_mid,
				'title'    => 'Original ' . self::fuzz_label( $ctx->fork( 'mid-title-old' ) ),
				'content'  => '<p>Original</p>',
				'priority' => 20,
			)
		);
		$screen->add_help_tab(
			array(
				'id'       => $tab_mid,
				'title'    => 'Override ' . self::fuzz_label( $ctx->fork( 'mid-title-new' ) ),
				'content'  => '<p>Override ' . \esc_html( self::fuzz_label( $ctx->fork( 'mid-content' ) ) ) . '</p>',
				'priority' => 15,
			)
		);
		$screen->add_help_tab(
			array(
				'id'       => self::id( $ctx->fork( 'invalid-tab' ), 'cfz_help_invalid', 32 ),
				'content'  => '<p>Missing title</p>',
				'priority' => 1,
			)
		);

		$tabs_before_remove = $screen->get_help_tabs();
		$mid_tab            = $screen->get_help_tab( $tab_mid );
		$missing_tab        = $screen->get_help_tab( 'cfz_missing_tab' );
		$screen->remove_help_tab( $tab_high );
		$tabs_after_remove = $screen->get_help_tabs();
		$screen->remove_help_tabs();
		$tabs_after_clear = $screen->get_help_tabs();

		self::collect_failure(
			$failures,
			array( $tab_high, $tab_mid, $tab_low ) === array_keys( $tabs_before_remove )
				&& is_array( $mid_tab )
				&& 15 === ( $mid_tab['priority'] ?? null )
				&& str_starts_with( (string) ( $mid_tab['title'] ?? '' ), 'Override ' )
				&& null === $missing_tab
				&& array( $tab_mid, $tab_low ) === array_keys( $tabs_after_remove )
				&& array() === $tabs_after_clear,
			'WP_Screen help tabs sort by priority, override duplicate IDs, and remove cleanly',
			array(
				'screen'           => self::describe_screen( $screen ),
				'tabsBeforeRemove' => $tabs_before_remove,
				'midTab'           => $mid_tab,
				'tabsAfterRemove'  => $tabs_after_remove,
				'tabsAfterClear'   => $tabs_after_clear,
			)
		);

		$per_page = array(
			'label'   => 'Per page ' . self::fuzz_label( $ctx->fork( 'per-page-label' ) ),
			'default' => $ctx->int( 5, 99 ),
			'option'  => self::id( $ctx->fork( 'per-page-option' ), 'cfz_per_page', 32 ),
		);
		$layout   = array(
			'max'     => $ctx->int( 2, 6 ),
			'default' => $ctx->int( 1, 2 ),
		);

		$screen->add_option( 'per_page', $per_page );
		$screen->add_option( 'layout_columns', $layout );
		$options_before_remove = $screen->get_options();
		$per_page_default      = $screen->get_option( 'per_page', 'default' );
		$missing_option        = $screen->get_option( 'cfz_missing_option' );
		$screen->remove_option( 'layout_columns' );
		$options_after_remove = $screen->get_options();

		$options_settings_calls = array();
		$options_show_calls     = array();
		$options_settings_filter = static function ( string $settings, \WP_Screen $seen_screen ) use ( &$options_settings_calls, $screen ): string {
			$options_settings_calls[] = array(
				'sameScreen' => $seen_screen === $screen,
				'incoming'   => $settings,
			);

			return $settings;
		};
		$options_show_filter     = static function ( bool $show_screen, \WP_Screen $seen_screen ) use ( &$options_show_calls, $screen ): bool {
			$options_show_calls[] = array(
				'sameScreen' => $seen_screen === $screen,
				'incoming'   => $show_screen,
			);

			return $show_screen;
		};

		\add_filter( 'screen_settings', $options_settings_filter, 10, 2 );
		\add_filter( 'screen_options_show_screen', $options_show_filter, 10, 2 );
		try {
			$show_first  = $screen->show_screen_options();
			$show_second = $screen->show_screen_options();
		} finally {
			\remove_filter( 'screen_options_show_screen', $options_show_filter, 10 );
			\remove_filter( 'screen_settings', $options_settings_filter, 10 );
			self::reset_screen_options_cache( $screen );
		}

		$screen->remove_options();
		$options_after_clear = $screen->get_options();

		$settings_screen = \convert_to_screen( self::id( $ctx->fork( 'settings-screen' ), 'cfz_settings_screen', 40 ) );
		$settings_calls  = array();
		$settings_show_calls = array();
		$settings_filter = static function ( string $settings, \WP_Screen $seen_screen ) use ( &$settings_calls, $settings_screen ): string {
			$settings_calls[] = array(
				'sameScreen' => $seen_screen === $settings_screen,
				'incoming'   => $settings,
			);

			return $settings . '<p class="cfz-screen-settings">settings</p>';
		};
		$settings_show_filter = static function ( bool $show_screen, \WP_Screen $seen_screen ) use ( &$settings_show_calls, $settings_screen ): bool {
			$settings_show_calls[] = array(
				'sameScreen' => $seen_screen === $settings_screen,
				'incoming'   => $show_screen,
			);

			return $show_screen;
		};

		\add_filter( 'screen_settings', $settings_filter, 10, 2 );
		\add_filter( 'screen_options_show_screen', $settings_show_filter, 10, 2 );
		try {
			$settings_show_first  = $settings_screen->show_screen_options();
			$settings_show_second = $settings_screen->show_screen_options();
		} finally {
			\remove_filter( 'screen_options_show_screen', $settings_show_filter, 10 );
			\remove_filter( 'screen_settings', $settings_filter, 10 );
			self::reset_screen_options_cache( $settings_screen );
		}

		self::collect_failure(
			$failures,
			isset( $options_before_remove['per_page'], $options_before_remove['layout_columns'] )
				&& $per_page_default === $per_page['default']
				&& null === $missing_option
				&& isset( $options_after_remove['per_page'] )
				&& ! isset( $options_after_remove['layout_columns'] )
				&& array() === $options_after_clear
				&& true === $show_first
				&& true === $show_second
				&& 1 === count( $options_settings_calls )
				&& 1 === count( $options_show_calls )
				&& true === ( $options_settings_calls[0]['sameScreen'] ?? null )
				&& '' === ( $options_settings_calls[0]['incoming'] ?? null )
				&& true === ( $options_show_calls[0]['sameScreen'] ?? null )
				&& true === ( $options_show_calls[0]['incoming'] ?? null )
				&& array() === $settings_screen->get_options()
				&& true === $settings_show_first
				&& true === $settings_show_second
				&& 1 === count( $settings_calls )
				&& 1 === count( $settings_show_calls )
				&& true === ( $settings_calls[0]['sameScreen'] ?? null )
				&& '' === ( $settings_calls[0]['incoming'] ?? null )
				&& true === ( $settings_show_calls[0]['sameScreen'] ?? null )
				&& true === ( $settings_show_calls[0]['incoming'] ?? null )
				&& false === \has_filter( 'screen_settings', $options_settings_filter )
				&& false === \has_filter( 'screen_options_show_screen', $options_show_filter )
				&& false === \has_filter( 'screen_settings', $settings_filter )
				&& false === \has_filter( 'screen_options_show_screen', $settings_show_filter ),
			'WP_Screen options store values, remove cleanly, show options once, and restore filters',
			array(
				'perPage'             => $per_page,
				'layout'              => $layout,
				'optionsBeforeRemove' => $options_before_remove,
				'optionsAfterRemove'  => $options_after_remove,
				'optionsAfterClear'   => $options_after_clear,
				'showFirst'           => $show_first,
				'showSecond'          => $show_second,
				'optionsSettingsCalls'=> $options_settings_calls,
				'optionsShowCalls'    => $options_show_calls,
				'settingsScreen'      => self::describe_screen( $settings_screen ),
				'settingsShowFirst'   => $settings_show_first,
				'settingsShowSecond'  => $settings_show_second,
				'settingsCalls'       => $settings_calls,
				'settingsShowCalls'   => $settings_show_calls,
				'optionsSettingsHasFilter' => \has_filter( 'screen_settings', $options_settings_filter ),
				'optionsShowHasFilter' => \has_filter( 'screen_options_show_screen', $options_show_filter ),
				'settingsHasFilter'   => \has_filter( 'screen_settings', $settings_filter ),
				'settingsShowHasFilter' => \has_filter( 'screen_options_show_screen', $settings_show_filter ),
			)
		);

		return self::row(
			$ctx,
			'admin-screen.help-tabs-and-screen-options',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_screen_options_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$current_snapshot = self::snapshot_globals( array( 'current_screen' ) );
		unset( $GLOBALS['current_screen'] );
		\add_screen_option(
			'per_page',
			array(
				'label'   => 'No screen',
				'default' => 17,
				'option'  => 'cfz_no_screen_per_page',
			)
		);
		$no_screen_result = \get_current_screen();
		self::restore_globals( $current_snapshot );

		$screen            = \convert_to_screen( self::id( $ctx->fork( 'screen' ), 'cfz_render_screen', 40 ) );
		$option            = self::id( $ctx->fork( 'option' ), 'cfz_render_per_page', 32 );
		$default_per_page  = $ctx->int( 7, 55 );
		$filtered_per_page = $default_per_page + $ctx->int( 3, 25 );
		$columns           = $ctx->int( 2, 5 );
		$label             = 'Items per page: ' . \esc_html( self::fuzz_label( $ctx->fork( 'label' ) ) );
		$per_page_calls    = array();
		$submit_calls      = array();

		\set_current_screen( $screen );
		\add_screen_option(
			'per_page',
			array(
				'label'   => $label,
				'default' => $default_per_page,
				'option'  => $option,
			)
		);
		\add_screen_option(
			'layout_columns',
			array(
				'max'     => $columns,
				'default' => 1,
			)
		);

		$per_page_filter = static function ( int $per_page ) use ( &$per_page_calls, $filtered_per_page, $option ): int {
			$per_page_calls[] = array(
				'option'   => $option,
				'incoming' => $per_page,
			);

			return $filtered_per_page;
		};
		$submit_filter   = static function ( bool $show, \WP_Screen $seen_screen ) use ( &$submit_calls, $screen ): bool {
			$submit_calls[] = array(
				'incoming'   => $show,
				'sameScreen' => $seen_screen === $screen,
			);

			return $show;
		};

		\add_filter( $option, $per_page_filter, 10, 1 );
		\add_filter( 'screen_options_show_submit', $submit_filter, 20, 2 );
		$ob_level = ob_get_level();
		try {
			ob_start();
			$screen->render_screen_options( array( 'wrap' => true ) );
			$wrapped_html = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			\remove_filter( 'screen_options_show_submit', '__return_true' );
			\remove_filter( 'screen_options_show_submit', $submit_filter, 20 );
			\remove_filter( $option, $per_page_filter, 10 );
		}

		\add_filter( $option, $per_page_filter, 10, 1 );
		\add_filter( 'screen_options_show_submit', $submit_filter, 20, 2 );
		$ob_level = ob_get_level();
		try {
			ob_start();
			$screen->render_screen_options( array( 'wrap' => false ) );
			$unwrapped_html = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			\remove_filter( 'screen_options_show_submit', '__return_true' );
			\remove_filter( 'screen_options_show_submit', $submit_filter, 20 );
			\remove_filter( $option, $per_page_filter, 10 );
		}

		self::collect_failure(
			$failures,
			null === $no_screen_result
				&& $screen === \get_current_screen()
				&& $screen->get_option( 'per_page', 'option' ) === $option
				&& $screen->get_option( 'per_page', 'default' ) === $default_per_page
				&& $screen->get_option( 'layout_columns', 'max' ) === $columns,
			'add_screen_option() no-ops without a current screen and attaches options to the current WP_Screen',
			array(
				'noScreenResult' => $no_screen_result,
				'screen'         => self::describe_screen( $screen ),
				'options'        => $screen->get_options(),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $wrapped_html, 'id="screen-options-wrap"' )
				&& str_contains( $wrapped_html, "form id='adv-settings' method='post'" )
				&& str_contains( $wrapped_html, 'name="screenoptionnonce"' )
				&& str_contains( $wrapped_html, 'class="screen-options"' )
				&& str_contains( $wrapped_html, 'name="wp_screen_options[value]"' )
				&& str_contains( $wrapped_html, 'id="' . \esc_attr( $option ) . '"' )
				&& str_contains( $wrapped_html, 'value="' . \esc_attr( (string) $filtered_per_page ) . '"' )
				&& str_contains( $wrapped_html, 'name="wp_screen_options[option]" value="' . \esc_attr( $option ) . '"' )
				&& substr_count( $wrapped_html, "name='screen_columns'" ) === $columns
				&& str_contains( $wrapped_html, 'id="screen-options-apply"' )
				&& self::html_has_no_unsafe_raw_markup( $wrapped_html ),
			'WP_Screen::render_screen_options() emits wrapped form, nonce, generated per-page option, column radios, and submit button safely',
			array( 'html' => self::describe_string( $wrapped_html ) )
		);

		self::collect_failure(
			$failures,
			! str_contains( $unwrapped_html, 'id="screen-options-wrap"' )
				&& str_contains( $unwrapped_html, "form id='adv-settings' method='post'" )
				&& str_contains( $unwrapped_html, 'name="screenoptionnonce"' )
				&& str_contains( $unwrapped_html, 'name="wp_screen_options[option]" value="' . \esc_attr( $option ) . '"' )
				&& self::html_has_no_unsafe_raw_markup( $unwrapped_html ),
			'WP_Screen::render_screen_options() honors wrap=false while preserving form controls',
			array( 'html' => self::describe_string( $unwrapped_html ) )
		);

		self::collect_failure(
			$failures,
			array(
				array( 'option' => $option, 'incoming' => $default_per_page ),
				array( 'option' => $option, 'incoming' => $default_per_page ),
			) === $per_page_calls
				&& 2 === count( $submit_calls )
				&& self::all_call_values( $submit_calls, 'incoming', true )
				&& self::all_call_values( $submit_calls, 'sameScreen', true )
				&& false === \has_filter( $option, $per_page_filter )
				&& false === \has_filter( 'screen_options_show_submit', $submit_filter ),
			'per-page and submit filters receive generated screen context and are removed after rendering',
			array(
				'perPageCalls' => $per_page_calls,
				'submitCalls'  => $submit_calls,
				'perPageHook'  => \has_filter( $option, $per_page_filter ),
				'submitHook'   => \has_filter( 'screen_options_show_submit', $submit_filter ),
			)
		);

		self::restore_globals( $current_snapshot );

		return self::row(
			$ctx,
			'admin-screen.screen-options.rendering-controls',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_screen_meta_rendering_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$screen   = \convert_to_screen( self::id( $ctx->fork( 'screen' ), 'cfz_screen_meta', 40 ) );

		$reader_heading = \esc_html( 'Views <script>alert(1)</script> ' . self::fuzz_label( $ctx->fork( 'reader-heading' ) ) );
		$reader_list    = \esc_html( 'List " onclick="bad ' . self::fuzz_label( $ctx->fork( 'reader-list' ) ) );
		$reader_custom  = \esc_html( 'Custom reader ' . self::fuzz_label( $ctx->fork( 'reader-custom' ) ) );
		$reader_content = array(
			'heading_views' => $reader_heading,
			'heading_list'  => $reader_list,
			'cfz_custom'    => $reader_custom,
		);

		$screen->set_screen_reader_content( $reader_content );
		$reader_after_set     = $screen->get_screen_reader_content();
		$reader_heading_text  = $screen->get_screen_reader_text( 'heading_views' );
		$reader_missing_text  = $screen->get_screen_reader_text( 'cfz_missing_reader' );
		$reader_ob_level      = ob_get_level();
		$reader_html          = '';
		$missing_reader_html  = '';
		$removed_reader_html  = '';
		try {
			ob_start();
			$screen->render_screen_reader_content( 'heading_views', 'h3' );
			$reader_html = (string) ob_get_clean();

			ob_start();
			$screen->render_screen_reader_content( 'cfz_missing_reader', 'h3' );
			$missing_reader_html = (string) ob_get_clean();

			$screen->remove_screen_reader_content();
			$reader_after_remove      = $screen->get_screen_reader_content();
			$reader_after_remove_text = $screen->get_screen_reader_text( 'heading_views' );

			ob_start();
			$screen->render_screen_reader_content( 'heading_views', 'h3' );
			$removed_reader_html = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $reader_ob_level ) {
				ob_end_clean();
			}
		}

		self::collect_failure(
			$failures,
			$reader_heading === ( $reader_after_set['heading_views'] ?? null )
				&& $reader_list === ( $reader_after_set['heading_list'] ?? null )
				&& $reader_custom === ( $reader_after_set['cfz_custom'] ?? null )
				&& isset( $reader_after_set['heading_pagination'] )
				&& $reader_heading === $reader_heading_text
				&& null === $reader_missing_text
				&& str_contains( $reader_html, "<h3 class='screen-reader-text'>" )
				&& str_contains( $reader_html, $reader_heading )
				&& '' === $missing_reader_html
				&& array() === $reader_after_remove
				&& null === $reader_after_remove_text
				&& '' === $removed_reader_html
				&& self::html_has_no_unsafe_raw_markup( $reader_html ),
			'Screen reader content stores generated labels, renders requested keys, no-ops missing keys, and removes cleanly',
			array(
				'screen'                => self::describe_screen( $screen ),
				'readerAfterSet'        => $reader_after_set,
				'readerHtml'            => self::describe_string( $reader_html ),
				'missingReaderHtml'     => self::describe_string( $missing_reader_html ),
				'readerAfterRemove'     => $reader_after_remove,
				'readerAfterRemoveText' => $reader_after_remove_text,
				'removedReaderHtml'     => self::describe_string( $removed_reader_html ),
			)
		);

		$primary_tab       = self::id( $ctx->fork( 'primary-tab' ), 'cfz_help_meta_primary', 32 );
		$secondary_tab     = self::id( $ctx->fork( 'secondary-tab' ), 'cfz_help_meta_secondary', 32 );
		$primary_title     = 'Primary <script>alert(2)</script> ' . self::fuzz_label( $ctx->fork( 'primary-title' ) );
		$secondary_title   = 'Secondary " onclick="bad ' . self::fuzz_label( $ctx->fork( 'secondary-title' ) );
		$primary_payload   = 'Primary body <script>alert(3)</script> ' . self::fuzz_label( $ctx->fork( 'primary-content' ) );
		$secondary_payload = 'Secondary body " onclick="bad ' . self::fuzz_label( $ctx->fork( 'secondary-content' ) );
		$callback_payload  = 'Callback body <script>alert(4)</script> ' . self::fuzz_label( $ctx->fork( 'callback-content' ) );
		$sidebar_payload   = 'Sidebar body <script>alert(5)</script> ' . self::fuzz_label( $ctx->fork( 'sidebar-content' ) );
		$primary_content   = '<p class="cfz-help-content" data-tab="' . \esc_attr( $primary_tab ) . '">' . \esc_html( $primary_payload ) . '</p>';
		$secondary_content = '<p class="cfz-help-content" data-tab="' . \esc_attr( $secondary_tab ) . '">' . \esc_html( $secondary_payload ) . '</p>';
		$sidebar_html      = '<aside class="cfz-help-sidebar" data-screen="' . \esc_attr( $screen->id ) . '">' . \esc_html( $sidebar_payload ) . '</aside>';
		$callback_calls    = array();
		$callback          = static function ( \WP_Screen $seen_screen, array $tab ) use ( &$callback_calls, $screen, $callback_payload ): void {
			$callback_calls[] = array(
				'sameScreen' => $seen_screen === $screen,
				'id'         => $tab['id'] ?? null,
				'title'      => $tab['title'] ?? null,
			);
			echo '<span class="cfz-help-callback" data-tab="' . \esc_attr( $tab['id'] ?? '' ) . '">';
			echo \esc_html( $callback_payload );
			echo '</span>';
		};

		$screen->add_help_tab(
			array(
				'id'       => $secondary_tab,
				'title'    => $secondary_title,
				'content'  => $secondary_content,
				'priority' => 30,
			)
		);
		$screen->add_help_tab(
			array(
				'id'       => $primary_tab,
				'title'    => $primary_title,
				'content'  => $primary_content,
				'callback' => $callback,
				'priority' => 5,
			)
		);
		$screen->set_help_sidebar( $sidebar_html );

		$per_page_option    = self::id( $ctx->fork( 'per-page-option' ), 'cfz_meta_per_page', 32 );
		$default_per_page   = $ctx->int( 5, 40 );
		$filtered_per_page  = $default_per_page + $ctx->int( 3, 25 );
		$layout_columns_max = $ctx->int( 2, 5 );
		$layout_default     = $ctx->int( 1, $layout_columns_max );
		$layout_calls       = array();
		$per_page_calls     = array();
		$globals_snapshot   = self::snapshot_globals( array( 'current_screen', 'screen_layout_columns', 'taxnow', 'typenow' ) );
		$meta_html          = '';
		$columns_during     = null;

		$layout_filter = static function ( array $columns, string $screen_id, \WP_Screen $seen_screen ) use ( &$layout_calls, $screen ): array {
			$layout_calls[] = array(
				'sameScreen' => $seen_screen === $screen,
				'screenId'   => $screen_id,
				'incoming'   => $columns,
			);

			return $columns;
		};
		$per_page_filter = static function ( int $per_page ) use ( &$per_page_calls, $filtered_per_page, $per_page_option ): int {
			$per_page_calls[] = array(
				'option'   => $per_page_option,
				'incoming' => $per_page,
			);

			return $filtered_per_page;
		};

		\set_current_screen( $screen );
		\add_screen_option(
			'per_page',
			array(
				'label'   => 'Meta per page ' . \esc_html( self::fuzz_label( $ctx->fork( 'per-page-label' ) ) ),
				'default' => $default_per_page,
				'option'  => $per_page_option,
			)
		);
		\add_screen_option(
			'layout_columns',
			array(
				'max'     => $layout_columns_max,
				'default' => $layout_default,
			)
		);

		\add_filter( 'screen_layout_columns', $layout_filter, 10, 3 );
		\add_filter( $per_page_option, $per_page_filter, 10, 1 );
		$meta_ob_level = ob_get_level();
		try {
			ob_start();
			$screen->render_screen_meta();
			$meta_html      = (string) ob_get_clean();
			$columns_during = $GLOBALS['screen_layout_columns'] ?? null;
		} finally {
			while ( ob_get_level() > $meta_ob_level ) {
				ob_end_clean();
			}
			\remove_filter( 'screen_options_show_submit', '__return_true' );
			\remove_filter( $per_page_option, $per_page_filter, 10 );
			\remove_filter( 'screen_layout_columns', $layout_filter, 10 );
			self::restore_globals( $globals_snapshot );
		}

		self::collect_failure(
			$failures,
			$sidebar_html === $screen->get_help_sidebar()
				&& str_contains( $meta_html, 'id="screen-meta" class="metabox-prefs"' )
				&& str_contains( $meta_html, 'id="contextual-help-wrap" class="hidden"' )
				&& ! str_contains( $meta_html, 'no-sidebar' )
				&& str_contains( $meta_html, 'class="contextual-help-tabs"' )
				&& str_contains( $meta_html, 'id="tab-link-' . \esc_attr( $primary_tab ) . '" class="active"' )
				&& str_contains( $meta_html, 'id="tab-panel-' . \esc_attr( $primary_tab ) . '" class="help-tab-content active"' )
				&& str_contains( $meta_html, \esc_html( $primary_title ) )
				&& str_contains( $meta_html, \esc_html( $secondary_title ) )
				&& str_contains( $meta_html, 'class="contextual-help-sidebar"' )
				&& str_contains( $meta_html, 'cfz-help-sidebar' )
				&& str_contains( $meta_html, 'cfz-help-content' )
				&& str_contains( $meta_html, 'cfz-help-callback' )
				&& 1 === count( $callback_calls )
				&& true === ( $callback_calls[0]['sameScreen'] ?? null )
				&& $primary_tab === ( $callback_calls[0]['id'] ?? null )
				&& str_contains( $meta_html, 'id="screen-options-wrap" class="hidden"' )
				&& str_contains( $meta_html, "form id='adv-settings' method='post'" )
				&& str_contains( $meta_html, 'name="screenoptionnonce"' )
				&& str_contains( $meta_html, 'class="screen-options"' )
				&& str_contains( $meta_html, 'id="' . \esc_attr( $per_page_option ) . '"' )
				&& str_contains( $meta_html, 'value="' . \esc_attr( (string) $filtered_per_page ) . '"' )
				&& str_contains( $meta_html, 'name="wp_screen_options[option]" value="' . \esc_attr( $per_page_option ) . '"' )
				&& substr_count( $meta_html, "name='screen_columns'" ) === $layout_columns_max
				&& str_contains( $meta_html, "value='" . \esc_attr( (string) $layout_default ) . "'" )
				&& str_contains( $meta_html, 'id="screen-options-link-wrap"' )
				&& str_contains( $meta_html, 'id="show-settings-link"' )
				&& str_contains( $meta_html, 'aria-controls="screen-options-wrap"' )
				&& str_contains( $meta_html, 'id="contextual-help-link-wrap"' )
				&& str_contains( $meta_html, 'id="contextual-help-link"' )
				&& str_contains( $meta_html, 'aria-controls="contextual-help-wrap"' )
				&& self::html_has_no_unsafe_raw_markup( $meta_html ),
			'WP_Screen::render_screen_meta() combines help tabs, sidebar, callbacks, screen options, and toggle links safely',
			array(
				'screen'          => self::describe_screen( $screen ),
				'helpTabs'        => array_keys( $screen->get_help_tabs() ),
				'callbackCalls'   => $callback_calls,
				'options'         => $screen->get_options(),
				'columnsDuring'   => $columns_during,
				'metaHtml'        => self::describe_string( $meta_html ),
			)
		);

		self::collect_failure(
			$failures,
			$layout_default === $columns_during
				&& array(
					array(
						'sameScreen' => true,
						'screenId'   => $screen->id,
						'incoming'   => array(),
					),
				) === $layout_calls
				&& array(
					array(
						'option'   => $per_page_option,
						'incoming' => $default_per_page,
					),
				) === $per_page_calls
				&& false === \has_filter( 'screen_layout_columns', $layout_filter )
				&& false === \has_filter( $per_page_option, $per_page_filter )
				&& self::globals_match( $globals_snapshot, array( 'current_screen', 'screen_layout_columns', 'taxnow', 'typenow' ) ),
			'render_screen_meta() applies scoped option/layout filters, sets the legacy layout global, and restores filters/globals',
			array(
				'layoutDefault'       => $layout_default,
				'columnsDuring'       => $columns_during,
				'layoutCalls'         => $layout_calls,
				'perPageCalls'        => $per_page_calls,
				'layoutHasFilter'     => \has_filter( 'screen_layout_columns', $layout_filter ),
				'perPageHasFilter'    => \has_filter( $per_page_option, $per_page_filter ),
				'globalsRestored'     => self::globals_match( $globals_snapshot, array( 'current_screen', 'screen_layout_columns', 'taxnow', 'typenow' ) ),
			)
		);

		return self::row(
			$ctx,
			'admin-screen.screen-meta.rendering-lifecycle',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_column_headers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$screen_a = \convert_to_screen( self::id( $ctx->fork( 'screen-a' ), 'cfz_columns_a', 40 ) );
		$screen_b = \convert_to_screen( self::id( $ctx->fork( 'screen-b' ), 'cfz_columns_b', 40 ) );
		$column   = self::id( $ctx->fork( 'column' ), 'cfz_column', 30 );
		$label    = 'Column ' . self::fuzz_label( $ctx->fork( 'label' ) );
		$calls    = 0;
		$filter   = static function ( array $columns ) use ( &$calls, $column, $label ): array {
			++$calls;
			$columns[ $column ] = $label;
			return $columns;
		};

		\add_filter( "manage_{$screen_a->id}_columns", $filter );
		$first  = \get_column_headers( $screen_a );
		$second = \get_column_headers( $screen_a );
		\remove_filter( "manage_{$screen_a->id}_columns", $filter );
		$other = \get_column_headers( $screen_b );

		self::collect_failure(
			$failures,
			1 === $calls
				&& $first === $second
				&& isset( $first[ $column ] )
				&& $label === $first[ $column ]
				&& array() === $other
				&& false === \has_filter( "manage_{$screen_a->id}_columns", $filter ),
			'get_column_headers() applies the dynamic filter once per screen id and caches locally',
			array(
				'screenA' => $screen_a->id,
				'screenB' => $screen_b->id,
				'column'  => $column,
				'calls'   => $calls,
				'first'   => $first,
				'second'  => $second,
				'other'   => $other,
			)
		);

		return self::row(
			$ctx,
			'admin-screen.columns.filter-locality',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_settings_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		global $new_allowed_options;

		$failures = array();
		$group    = self::id( $ctx->fork( 'group' ), 'cfz_group', 36 );
		$name     = self::id( $ctx->fork( 'name' ), 'cfz_option', 40 );
		$raw      = " Raw <b>\xE2\x98\x83</b> " . $ctx->text( 0, 28 );
		$calls    = array();
		$sanitize = static function ( $value ) use ( &$calls ): string {
			$calls[] = $value;
			return 'sanitized:' . \sanitize_key( \sanitize_text_field( (string) $value ) );
		};
		$args     = array(
			'type'              => 'string',
			'label'             => 'Label ' . self::fuzz_label( $ctx->fork( 'label' ) ),
			'description'       => 'Description ' . self::fuzz_label( $ctx->fork( 'description' ) ),
			'sanitize_callback' => $sanitize,
			'default'           => 'default-' . $ctx->int( 100, 999 ),
			'show_in_rest'      => false,
		);

		\register_setting( $group, $name, $args );

		$registered              = \get_registered_settings();
		$allowed_before          = $new_allowed_options[ $group ] ?? array();
		$sanitize_filter_before  = \has_filter( "sanitize_option_{$name}", $sanitize );
		$default_filter_before   = \has_filter( "default_option_{$name}", 'filter_default_option' );
		$sanitized               = \sanitize_option( $name, $raw );
		$default                 = \get_option( $name );
		$registered_before_drop  = $registered[ $name ] ?? null;

		\unregister_setting( $group, $name );

		$registered_after        = \get_registered_settings();
		$allowed_after           = $new_allowed_options[ $group ] ?? array();
		$sanitize_filter_after   = \has_filter( "sanitize_option_{$name}", $sanitize );
		$default_filter_after    = \has_filter( "default_option_{$name}", 'filter_default_option' );

		self::collect_failure(
			$failures,
			is_array( $registered_before_drop )
				&& in_array( $name, $allowed_before, true )
				&& 10 === $sanitize_filter_before
				&& 10 === $default_filter_before
				&& $args['label'] === $registered_before_drop['label']
				&& $args['description'] === $registered_before_drop['description']
				&& $args['default'] === $default
				&& array( $raw ) === $calls
				&& 'sanitized:' . \sanitize_key( \sanitize_text_field( (string) $raw ) ) === $sanitized
				&& ! isset( $registered_after[ $name ] )
				&& ! in_array( $name, $allowed_after, true )
				&& false === $sanitize_filter_after
				&& false === $default_filter_after,
			'register_setting() wires registry, allowed options, defaults, sanitize callbacks, and unregister cleanup',
			array(
				'group'                  => $group,
				'name'                   => $name,
				'registeredBeforeDrop'   => $registered_before_drop,
				'allowedBefore'          => $allowed_before,
				'sanitizeFilterBefore'   => $sanitize_filter_before,
				'defaultFilterBefore'    => $default_filter_before,
				'sanitized'              => $sanitized,
				'default'                => $default,
				'calls'                  => $calls,
				'allowedAfter'           => $allowed_after,
				'sanitizeFilterAfter'    => $sanitize_filter_after,
				'defaultFilterAfter'     => $default_filter_after,
			)
		);

		return self::row(
			$ctx,
			'admin-screen.settings.registry-sanitize-unregister',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_settings_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$page     = self::id( $ctx->fork( 'page' ), 'cfz_settings_page', 40 );
		$section  = self::id( $ctx->fork( 'section' ), 'cfz_section', 32 );
		$field    = self::id( $ctx->fork( 'field' ), 'cfz_field', 32 );
		$label_for = $field . '" onclick="bad' . $ctx->int( 10, 99 );
		$class    = 'cfz-field-class " onclick="bad ' . $ctx->identifier( 3, 8 );
		$group    = self::id( $ctx->fork( 'fields-group' ), 'cfz_fields', 28 ) . '"<script>alert(1)</script>';
		$section_calls = array();
		$field_calls   = array();

		$section_callback = static function ( array $section_args ) use ( &$section_calls ): void {
			$section_calls[] = $section_args;
			echo '<p class="cfz-section-marker" data-section="' . \esc_attr( $section_args['id'] ) . '">';
			echo \esc_html( (string) ( $section_args['section_class'] ?? '' ) );
			echo '</p>';
		};
		$field_callback   = static function ( array $field_args ) use ( &$field_calls ): void {
			$field_calls[] = $field_args;
			echo '<input class="cfz-field-input" id="' . \esc_attr( $field_args['label_for'] ?? '' ) . '" ';
			echo 'value="' . \esc_attr( $field_args['payload'] ?? '' ) . '" />';
		};

		\add_settings_section(
			$section,
			'Section ' . \esc_html( self::fuzz_label( $ctx->fork( 'section-title' ) ) ),
			$section_callback,
			$page,
			array(
				'before_section' => '<section class="%s"><script>alert(1)</script><p>lead</p>',
				'after_section'  => '<script>alert(2)</script><p>tail</p></section>',
				'section_class'  => 'cfz-section " onclick="bad ' . $ctx->identifier( 3, 8 ),
			)
		);
		\add_settings_field(
			$field,
			'Field ' . \esc_html( self::fuzz_label( $ctx->fork( 'field-title' ) ) ),
			$field_callback,
			$page,
			$section,
			array(
				'label_for' => $label_for,
				'class'     => $class,
				'payload'   => self::fuzz_label( $ctx->fork( 'payload' ) ),
			)
		);

		ob_start();
		\do_settings_sections( $page );
		$sections_html = (string) ob_get_clean();

		ob_start();
		\do_settings_fields( $page, $section );
		$fields_html = (string) ob_get_clean();

		ob_start();
		\settings_fields( $group );
		$settings_fields_html = (string) ob_get_clean();

		self::collect_failure(
			$failures,
			1 === count( $section_calls )
				&& 2 === count( $field_calls )
				&& $section === ( $section_calls[0]['id'] ?? null )
				&& $label_for === ( $field_calls[0]['label_for'] ?? null )
				&& str_contains( $sections_html, 'cfz-section-marker' )
				&& str_contains( $sections_html, 'class="form-table"' )
				&& str_contains( $sections_html, 'cfz-field-input' )
				&& str_contains( $fields_html, 'cfz-field-input' )
				&& str_contains( $settings_fields_html, "name='option_page'" )
				&& str_contains( $settings_fields_html, 'name="action" value="update"' )
				&& str_contains( $settings_fields_html, 'name="_wpnonce"' )
				&& str_contains( $settings_fields_html, "value='" . \esc_attr( $group ) . "'" )
				&& self::html_has_no_unsafe_raw_markup( $sections_html )
				&& self::html_has_no_unsafe_raw_markup( $fields_html )
				&& self::html_has_no_unsafe_raw_markup( $settings_fields_html ),
			'Settings section, field, and nonce renderers call callbacks and escape hostile args',
			array(
				'page'               => $page,
				'section'            => $section,
				'field'              => $field,
				'sectionCalls'       => $section_calls,
				'fieldCalls'         => $field_calls,
				'sectionsHtml'       => self::describe_string( $sections_html ),
				'fieldsHtml'         => self::describe_string( $fields_html ),
				'settingsFieldsHtml' => self::describe_string( $settings_fields_html ),
			)
		);

		return self::row(
			$ctx,
			'admin-screen.settings.rendering-and-escaping',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_meta_boxes( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_meta_boxes;

		$failures    = array();
		$screen      = \convert_to_screen( self::id( $ctx->fork( 'screen' ), 'cfz_meta_screen', 40 ) );
		$context     = $ctx->choice( array( 'normal', 'side', 'advanced' ) );
		$alt_context = 'side' === $context ? 'normal' : 'side';
		$high_id     = self::id( $ctx->fork( 'high' ), 'cfz_box_high', 32 );
		$moved_id    = self::id( $ctx->fork( 'moved' ), 'cfz_box_moved', 32 );
		$default_id  = self::id( $ctx->fork( 'default' ), 'cfz_box_default', 32 );
		$removed_id  = self::id( $ctx->fork( 'removed' ), 'cfz_box_removed', 32 );
		$data_object = (object) array(
			'ID'        => $ctx->int( 1000, 9999 ),
			'post_type' => 'component_fuzz',
		);
		$calls       = array();
		$callback    = static function ( $object, array $box ) use ( &$calls, $data_object ): void {
			$calls[] = array(
				'id'          => $box['id'],
				'sameObject'  => $object === $data_object,
				'payload'     => $box['args']['payload'] ?? null,
				'argKeys'     => array_keys( (array) ( $box['args'] ?? array() ) ),
			);
			echo '<span class="cfz-meta-callback" data-box="' . \esc_attr( $box['id'] ) . '">';
			echo \esc_html( (string) ( $box['args']['payload'] ?? '' ) );
			echo '</span>';
		};

		\add_meta_box(
			$high_id,
			'High ' . \esc_html( self::fuzz_label( $ctx->fork( 'high-title' ) ) ),
			$callback,
			$screen,
			$context,
			'high',
			array( 'payload' => self::fuzz_label( $ctx->fork( 'high-payload' ) ) )
		);
		\add_meta_box(
			$moved_id,
			'Moved ' . \esc_html( self::fuzz_label( $ctx->fork( 'moved-title' ) ) ),
			$callback,
			$screen,
			$alt_context,
			'high',
			array( 'payload' => self::fuzz_label( $ctx->fork( 'moved-payload-initial' ) ) )
		);
		\add_meta_box(
			$moved_id,
			'Moved Updated ' . \esc_html( self::fuzz_label( $ctx->fork( 'moved-title-updated' ) ) ),
			$callback,
			$screen,
			$context,
			'',
			array( 'payload' => self::fuzz_label( $ctx->fork( 'moved-payload' ) ) )
		);
		\add_meta_box(
			$default_id,
			'Default ' . \esc_html( self::fuzz_label( $ctx->fork( 'default-title' ) ) ),
			$callback,
			$screen,
			$context,
			'default',
			array( 'payload' => self::fuzz_label( $ctx->fork( 'default-payload' ) ) )
		);
		\add_meta_box(
			$removed_id,
			'Removed ' . \esc_html( self::fuzz_label( $ctx->fork( 'removed-title' ) ) ),
			$callback,
			$screen,
			$context,
			'low',
			array( 'payload' => self::fuzz_label( $ctx->fork( 'removed-payload' ) ) )
		);
		\remove_meta_box( $removed_id, array( $screen ), $context );

		ob_start();
		$count = \do_meta_boxes( $screen, $context, $data_object );
		$html  = (string) ob_get_clean();

		$call_ids       = array_column( $calls, 'id' );
		$expected_order = array( $high_id, $moved_id, $default_id );
		$page           = $screen->id;

		self::collect_failure(
			$failures,
			3 === $count
				&& $expected_order === $call_ids
				&& ! isset( $wp_meta_boxes[ $page ][ $alt_context ]['high'][ $moved_id ] )
				&& isset( $wp_meta_boxes[ $page ][ $context ]['high'][ $moved_id ] )
				&& false === ( $wp_meta_boxes[ $page ][ $context ]['low'][ $removed_id ] ?? null )
				&& self::all_call_values( $calls, 'sameObject', true )
				&& str_contains( $html, 'id="' . $context . '-sortables"' )
				&& str_contains( $html, 'cfz-meta-callback' )
				&& ! str_contains( $html, $removed_id )
				&& self::strings_in_order( $html, $expected_order )
				&& self::html_has_no_unsafe_raw_markup( $html ),
			'Meta boxes render in priority order, move duplicate IDs, pass args, and honor removals',
			array(
				'screen'        => self::describe_screen( $screen ),
				'context'       => $context,
				'altContext'    => $alt_context,
				'expectedOrder' => $expected_order,
				'callIds'       => $call_ids,
				'count'         => $count,
				'calls'         => $calls,
				'html'          => self::describe_string( $html ),
			)
		);

		return self::row(
			$ctx,
			'admin-screen.meta-boxes.order-removal-callbacks',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_accordion_sections( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$screen      = \convert_to_screen( self::id( $ctx->fork( 'screen' ), 'cfz_accordion_screen', 40 ) );
		$context     = 'side';
		$box_id      = self::id( $ctx->fork( 'box' ), 'cfz_accordion_box', 32 );
		$removed_id  = self::id( $ctx->fork( 'removed' ), 'cfz_accordion_removed', 32 );
		$data_object = (object) array( 'kind' => 'accordion-data' );
		$calls       = array();
		$title       = 'Accordion <script>alert(1)</script> ' . self::fuzz_label( $ctx->fork( 'title' ) );
		$callback    = static function ( $object, array $box ) use ( &$calls, $data_object ): void {
			$calls[] = array(
				'id'         => $box['id'],
				'sameObject' => $object === $data_object,
				'payload'    => $box['args']['payload'] ?? null,
			);
			echo '<span class="cfz-accordion-callback" data-box="' . \esc_attr( $box['id'] ) . '">';
			echo \esc_html( (string) ( $box['args']['payload'] ?? '' ) );
			echo '</span>';
		};

		\add_meta_box(
			$box_id,
			$title,
			$callback,
			$screen,
			$context,
			'high',
			array( 'payload' => self::fuzz_label( $ctx->fork( 'payload' ) ) )
		);
		\add_meta_box(
			$removed_id,
			'Removed Accordion ' . self::fuzz_label( $ctx->fork( 'removed-title' ) ),
			$callback,
			$screen,
			$context,
			'low',
			array( 'payload' => self::fuzz_label( $ctx->fork( 'removed-payload' ) ) )
		);
		\remove_meta_box( $removed_id, $screen, $context );

		ob_start();
		$count = \do_accordion_sections( $screen, $context, $data_object );
		$html  = (string) ob_get_clean();

		self::collect_failure(
			$failures,
			1 === $count
				&& array( $box_id ) === array_column( $calls, 'id' )
				&& self::all_call_values( $calls, 'sameObject', true )
				&& str_contains( $html, 'accordion-container' )
				&& str_contains( $html, 'cfz-accordion-callback' )
				&& str_contains( $html, \esc_html( $title ) )
				&& ! str_contains( $html, $removed_id )
				&& self::html_has_no_unsafe_raw_markup( $html ),
			'do_accordion_sections() renders escaped accordion titles and skips removed boxes',
			array(
				'screen'  => self::describe_screen( $screen ),
				'boxId'   => $box_id,
				'count'   => $count,
				'calls'   => $calls,
				'html'    => self::describe_string( $html ),
			)
		);

		return self::row(
			$ctx,
			'admin-screen.meta-boxes.accordion-rendering',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function post_type_key( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'cfzpt' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
	}

	private static function taxonomy_key( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'cfztax' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
	}

	private static function id( \ComponentFuzz\FuzzContext $ctx, string $prefix, int $max = 32 ): string {
		return strtolower( substr( $prefix . '_' . hash( 'crc32b', (string) $ctx->seed() ), 0, $max ) );
	}

	private static function fuzz_label( \ComponentFuzz\FuzzContext $ctx ): string {
		return '☃ ' . $ctx->text( 0, 36 ) . ' <em data-x="' . \esc_attr( $ctx->identifier( 3, 8 ) ) . '">HTML</em>';
	}

	private static function html_has_no_unsafe_raw_markup( string $html ): bool {
		$lower = strtolower( $html );

		return ! str_contains( $lower, '<script' )
			&& ! str_contains( $lower, ' onclick="' )
			&& ! str_contains( $lower, " onclick='" );
	}

	private static function reset_screen_options_cache( \WP_Screen $screen ): void {
		$reset = \Closure::bind(
			static function () use ( $screen ): void {
				$screen->_show_screen_options = null;
				$screen->_screen_settings     = null;
			},
			null,
			\WP_Screen::class
		);

		$reset();
	}

	private static function strings_in_order( string $haystack, array $needles ): bool {
		$offset = 0;

		foreach ( $needles as $needle ) {
			$position = strpos( $haystack, (string) $needle, $offset );
			if ( false === $position ) {
				return false;
			}
			$offset = $position + strlen( (string) $needle );
		}

		return true;
	}

	private static function all_call_values( array $calls, string $key, $expected ): bool {
		foreach ( $calls as $call ) {
			if ( ! array_key_exists( $key, $call ) || $expected !== $call[ $key ] ) {
				return false;
			}
		}

		return true;
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

	private static function row(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		bool $ok,
		array $data = array(),
		?string $status = null
	): array {
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

	private static function describe_screen( $screen ): array {
		if ( ! $screen instanceof \WP_Screen ) {
			return array(
				'type' => is_object( $screen ) ? get_class( $screen ) : gettype( $screen ),
			);
		}

		return array(
			'id'              => $screen->id,
			'base'            => $screen->base,
			'post_type'       => $screen->post_type,
			'taxonomy'        => $screen->taxonomy,
			'action'          => $screen->action,
			'in_admin'        => $screen->in_admin(),
			'is_block_editor' => $screen->is_block_editor(),
		);
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
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
				if ( $i >= 16 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}

			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'type'    => 'string',
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => self::escape_bytes( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function escape_bytes( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; ++$i ) {
			$byte = ord( $value[ $i ] );
			if ( 0x5C === $byte ) {
				$out .= '\\\\';
			} elseif ( $byte >= 0x20 && $byte <= 0x7E ) {
				$out .= chr( $byte );
			} elseif ( 0x0A === $byte ) {
				$out .= '\\n';
			} elseif ( 0x0D === $byte ) {
				$out .= '\\r';
			} elseif ( 0x09 === $byte ) {
				$out .= '\\t';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $shown ) {
			$out .= '...';
		}

		return $out;
	}

	private static function snapshot_state(): array {
		$snapshot = array(
			'globals' => self::snapshot_globals(
				array(
					'_GET',
					'_POST',
					'_REQUEST',
					'current_screen',
					'hook_suffix',
					'new_allowed_options',
					'new_whitelist_options',
					'pagenow',
					'taxnow',
					'typenow',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_meta_boxes',
					'wp_post_types',
					'wp_registered_settings',
					'wp_scripts',
					'wp_settings_errors',
					'wp_settings_fields',
					'wp_settings_sections',
					'wp_taxonomies',
				)
			),
			'options' => null,
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$snapshot['options'] = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return $snapshot;
	}

	private static function restore_state( array $snapshot ): void {
		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		self::restore_globals( $snapshot['globals'] );

		if ( array_key_exists( 'new_allowed_options', $GLOBALS ) ) {
			$GLOBALS['new_whitelist_options'] = &$GLOBALS['new_allowed_options'];
		}
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
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function globals_match( array $snapshot, array $names ): bool {
		foreach ( $names as $name ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $snapshot[ $name ]['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] != $snapshot[ $name ]['value'] ) {
				return false;
			}
		}

		return true;
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
}
