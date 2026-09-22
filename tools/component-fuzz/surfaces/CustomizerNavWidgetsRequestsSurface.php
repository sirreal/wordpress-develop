<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes Customizer nav-menu and widget request/persistence edges.
 */
final class CustomizerNavWidgetsRequestsSurface {
	public const NAME = 'customizer-nav-widgets-requests';

	private const ACTIVE_STYLESHEET = 'component-fuzz-theme';
	private const SIDEBAR_ID        = 'cfz-sidebar';
	private const WIDGET_BASE       = 'cfz_widget';
	private const WIDGET_ID         = 'cfz_widget-2';

	private const HOOK_SNAPSHOT_NAMES = array(
		'customize_loaded_components',
		'customize_nav_menu_available_items',
		'customize_nav_menu_searched_items',
		'customize_refresh_nonces',
		'customize_save_nav_menus_created_posts',
		'dynamic_sidebar_after',
		'dynamic_sidebar_before',
		'dynamic_sidebar_params',
		'map_meta_cap',
		'pre_option_blog_charset',
		'sidebars_widgets',
		'status_header',
		'use_widgets_block_editor',
		'user_has_cap',
		'widget_customizer_setting_args',
		'wp_ajax_customize-nav-menus-insert-auto-draft',
		'wp_ajax_load-available-menu-items-customizer',
		'wp_ajax_search-available-menu-items-customizer',
		'wp_die_ajax_handler',
		'wp_die_handler',
		'wp_doing_ajax',
	);

	private static array $widget_updates = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_customizer_runtime();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'customizer-nav-widgets-requests.bootstrap-apis-available',
					'Required Customizer nav/widget APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_component_bootstrap_and_hooks( $ctx->fork( 'bootstrap' ) );
			$rows[] = self::check_nav_menu_available_and_search_requests( $ctx->fork( 'nav-available-search' ) );
			$rows[] = self::check_nav_menu_auto_draft_lifecycle( $ctx->fork( 'nav-auto-draft' ) );
			$rows[] = self::check_nav_menu_setting_placeholder_remaps( $ctx->fork( 'nav-menu-setting-remaps' ) );
			$rows[] = self::check_nav_menu_preview_hmacs( $ctx->fork( 'nav-preview-hmac' ) );
			$rows[] = self::check_widget_instance_and_ajax_requests( $ctx->fork( 'widget-ajax' ) );
			$rows[] = self::check_widget_selective_refresh_partials( $ctx->fork( 'widget-partials' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'customizer-nav-widgets-requests.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'customizer-nav-widgets-requests.state-restored',
			self::state_matches( $snapshot ),
			array(
				'obLevel'       => ob_get_level(),
				'contentCounts' => self::db_content_counts(),
				'optionsEqual'  => self::db_options() === $snapshot['options'],
			)
		);

		return $rows;
	}

	public static function grant_runtime_capabilities( array $allcaps ): array {
		foreach (
			array(
				'assign_terms',
				'customize',
				'edit_pages',
				'edit_post',
				'edit_posts',
				'edit_theme_options',
				'manage_categories',
				'publish_pages',
				'publish_posts',
				'unfiltered_html',
			) as $cap
		) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	public static function widget_callback( array $args, array $instance = array() ): void {
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$body  = isset( $instance['body'] ) ? (string) $instance['body'] : '';

		echo $args['before_widget'];
		echo '<strong class="cfz-widget-title">' . \esc_html( $title ) . '</strong>';
		echo '<span class="cfz-widget-body">' . \wp_kses_post( $body ) . '</span>';
		echo $args['after_widget'];
	}

	public static function widget_control_callback( array $args = array() ): void {
		$number = isset( $args['number'] ) ? (int) $args['number'] : 2;
		$value  = self::$widget_updates[ $number ] ?? array(
			'title' => 'Control',
			'body'  => '',
		);

		printf(
			'<input id="widget-%1$s-%2$d-title" name="widget-%1$s[%2$d][title]" value="%3$s" />',
			\esc_attr( self::WIDGET_BASE ),
			$number,
			\esc_attr( (string) ( $value['title'] ?? '' ) )
		);
	}

	public static function widget_update_callback( array $args = array() ): void {
		$number = isset( $args['number'] ) && -1 !== (int) $args['number'] ? (int) $args['number'] : 2;
		$key    = 'widget-' . self::WIDGET_BASE;
		$posted = isset( $_POST[ $key ][ $number ] ) && is_array( $_POST[ $key ][ $number ] )
			? \wp_unslash( $_POST[ $key ][ $number ] )
			: array();

		$option            = \get_option( 'widget_' . self::WIDGET_BASE, array() );
		$option            = is_array( $option ) ? $option : array();
		$option[ $number ] = array(
			'title' => isset( $posted['title'] ) ? \sanitize_text_field( (string) $posted['title'] ) : '',
			'body'  => isset( $posted['body'] ) ? \wp_kses_post( (string) $posted['body'] ) : '',
		);

		self::$widget_updates[ $number ] = $option[ $number ];
		\update_option( 'widget_' . self::WIDGET_BASE, $option );
	}

	private static function check_component_bootstrap_and_hooks( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();
		$manager = self::manager( $ctx );

		$nonces_before = array( 'existing' => 'kept' );
		$nonces        = \apply_filters( 'customize_refresh_nonces', $nonces_before );
		$failures      = array();

		self::collect_failure(
			$failures,
			isset( $manager->nav_menus, $manager->widgets, $manager->selective_refresh )
				&& $manager->nav_menus instanceof \WP_Customize_Nav_Menus
				&& $manager->widgets instanceof \WP_Customize_Widgets
				&& $manager->selective_refresh instanceof \WP_Customize_Selective_Refresh,
			'manager loads nav menus, widgets, and selective refresh components together',
			array(
				'hasNavMenus'        => isset( $manager->nav_menus ),
				'hasWidgets'         => isset( $manager->widgets ),
				'hasSelectiveRefresh' => isset( $manager->selective_refresh ),
			)
		);

		self::collect_failure(
			$failures,
			isset( $nonces['existing'], $nonces['customize-menus'] )
				&& is_string( $nonces['customize-menus'] )
				&& \has_action( 'wp_ajax_load-available-menu-items-customizer', array( $manager->nav_menus, 'ajax_load_available_items' ) )
				&& \has_action( 'wp_ajax_search-available-menu-items-customizer', array( $manager->nav_menus, 'ajax_search_available_items' ) )
				&& \has_action( 'wp_ajax_customize-nav-menus-insert-auto-draft', array( $manager->nav_menus, 'ajax_insert_auto_draft_post' ) ),
			'cap-gated nav menu AJAX hooks and customize-menus nonce are registered',
			array(
				'nonces'   => array_keys( $nonces ),
				'loadHook' => \has_action( 'wp_ajax_load-available-menu-items-customizer', array( $manager->nav_menus, 'ajax_load_available_items' ) ),
			)
		);

		$denied = self::with_caps_denied(
			static function () use ( $ctx ): \WP_Customize_Manager {
				return self::manager( $ctx->fork( 'denied' ) );
			}
		);

		self::collect_failure(
			$failures,
			isset( $denied->nav_menus, $denied->widgets )
				&& false === \has_action( 'wp_ajax_load-available-menu-items-customizer', array( $denied->nav_menus, 'ajax_load_available_items' ) )
				&& false === \has_action( 'wp_ajax_search-available-menu-items-customizer', array( $denied->nav_menus, 'ajax_search_available_items' ) )
				&& false === \has_action( 'wp_ajax_customize-nav-menus-insert-auto-draft', array( $denied->nav_menus, 'ajax_insert_auto_draft_post' ) ),
			'denied edit_theme_options suppresses nav menu AJAX hooks while still constructing components',
			array(
				'loadHookDenied'   => \has_action( 'wp_ajax_load-available-menu-items-customizer', array( $denied->nav_menus, 'ajax_load_available_items' ) ),
				'searchHookDenied' => \has_action( 'wp_ajax_search-available-menu-items-customizer', array( $denied->nav_menus, 'ajax_search_available_items' ) ),
			)
		);

		return self::row(
			$ctx,
			'customizer-nav-widgets-requests.bootstrap-hooks',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_nav_menu_available_and_search_requests( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();
		$manager   = self::manager( $ctx );
		$nav_menus = $manager->nav_menus;
		$fixtures  = self::seed_nav_content( $ctx );
		$failures  = array();

		$created_setting = $manager->add_setting(
			'nav_menus_created_posts',
			array(
				'default'           => array( $fixtures['draftPage'], $fixtures['post'] ),
				'sanitize_callback' => array( $nav_menus, 'sanitize_nav_menus_created_posts' ),
			)
		);

		$filter_item = array(
			'id'             => 'cfz-filter-' . $ctx->identifier( 4, 7 ),
			'title'          => 'Filtered ' . self::safe_label( $ctx ),
			'original_title' => 'Filtered original',
			'type'           => 'custom',
			'type_label'     => 'Custom Link',
			'object'         => '',
			'url'            => 'https://example.test/filtered',
		);
		$available_filter = static function ( array $items, string $object_type, string $object_name, int $page ) use ( $filter_item ): array {
			if ( 'post_type' === $object_type && 'page' === $object_name && 0 === $page ) {
				$items[] = $filter_item;
			}
			return $items;
		};
		$searched_filter  = static function ( array $items, array $args ) use ( $filter_item ): array {
			if ( ! empty( $args['s'] ) ) {
				$items[] = $filter_item;
			}
			return $items;
		};

		\add_filter( 'customize_nav_menu_available_items', $available_filter, 10, 4 );
		\add_filter( 'customize_nav_menu_searched_items', $searched_filter, 10, 2 );
		try {
			$available_items = $nav_menus->load_available_items_query( 'post_type', 'page', 0 );
			$taxonomy_items  = $nav_menus->load_available_items_query( 'taxonomy', 'category', 0 );
			$search_items    = $nav_menus->search_available_items_query(
				array(
					'pagenum' => 1,
					's'       => 'Fuzz',
				)
			);
		} finally {
			\remove_filter( 'customize_nav_menu_available_items', $available_filter, 10 );
			\remove_filter( 'customize_nav_menu_searched_items', $searched_filter, 10 );
		}

		$invalid_post_type = $nav_menus->load_available_items_query( 'post_type', 'cfz_missing_type', 0 );
		$invalid_taxonomy  = $nav_menus->load_available_items_query( 'taxonomy', 'cfz_missing_taxonomy', 0 );
		$bad_nonce         = self::capture_ajax(
			static function () use ( $nav_menus ): void {
				self::set_ajax_post(
					array(
					'action'                => 'load-available-menu-items-customizer',
					'customize-menus-nonce' => 'bad',
					'type'                  => 'post_type',
					'object'                => 'page',
					)
				);
				$nav_menus->ajax_load_available_items();
			}
		);
		$missing_type      = self::capture_ajax(
			static function () use ( $nav_menus ): void {
				self::set_ajax_post(
					array(
					'action'                => 'load-available-menu-items-customizer',
					'customize-menus-nonce' => \wp_create_nonce( 'customize-menus' ),
					)
				);
				$nav_menus->ajax_load_available_items();
			}
		);
		$empty_search      = self::capture_ajax(
			static function () use ( $nav_menus ): void {
				self::set_ajax_post(
					array(
					'action'                => 'search-available-menu-items-customizer',
					'customize-menus-nonce' => \wp_create_nonce( 'customize-menus' ),
					'search'                => '',
					)
				);
				$nav_menus->ajax_search_available_items();
			}
		);
		$search_success    = self::capture_ajax(
			static function () use ( $nav_menus ): void {
				self::set_ajax_post(
					array(
					'action'                => 'search-available-menu-items-customizer',
					'customize-menus-nonce' => \wp_create_nonce( 'customize-menus' ),
					'search'                => 'Fuzz',
					'page'                  => '1',
					)
				);
				$nav_menus->ajax_search_available_items();
			}
		);

		self::collect_failure(
			$failures,
			$created_setting instanceof \WP_Customize_Setting
				&& self::contains_item_id( $available_items, 'post-' . $fixtures['draftPage'] )
				&& self::contains_item_id( $available_items, $filter_item['id'] )
				&& self::contains_item_id( $taxonomy_items, 'term-' . $fixtures['term'] )
				&& self::contains_item_id( $search_items, 'post-' . $fixtures['draftPage'] )
				&& self::contains_item_id( $search_items, $filter_item['id'] ),
			'available-item and search queries include created drafts, taxonomy terms, and filter-injected items',
			array(
				'availableIds' => self::item_ids( $available_items ),
				'taxonomyIds'  => self::item_ids( $taxonomy_items ),
				'searchIds'    => self::item_ids( $search_items ),
			)
		);

		self::collect_failure(
			$failures,
			$invalid_post_type instanceof \WP_Error
				&& 'nav_menus_invalid_post_type' === $invalid_post_type->get_error_code()
				&& $invalid_taxonomy instanceof \WP_Error
				&& 'invalid_taxonomy' === $invalid_taxonomy->get_error_code(),
			'invalid post type and taxonomy requests surface stable WP_Error codes',
			array(
				'postTypeError' => self::describe_error( $invalid_post_type ),
				'taxonomyError' => self::describe_error( $invalid_taxonomy ),
			)
		);

		self::collect_failure(
			$failures,
			self::ajax_die_message( $bad_nonce ) === '-1'
				&& self::json_error_code( $missing_type ) === 'nav_menus_missing_type_or_object_parameter'
				&& self::json_error_code( $empty_search ) === 'nav_menus_missing_search_parameter'
				&& self::json_success( $search_success )
				&& false === \has_filter( 'customize_nav_menu_available_items', $available_filter )
				&& false === \has_filter( 'customize_nav_menu_searched_items', $searched_filter ),
			'nav menu AJAX handlers reject bad requests and encode successful search responses',
			array(
				'badNonce'      => self::summarize_capture( $bad_nonce ),
				'missingType'   => self::summarize_capture( $missing_type ),
				'emptySearch'   => self::summarize_capture( $empty_search ),
				'searchSuccess' => self::summarize_capture( $search_success ),
			)
		);

		return self::row(
			$ctx,
			'customizer-nav-widgets-requests.nav-menu-available-search',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_nav_menu_auto_draft_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();
		$manager   = self::manager( $ctx );
		$nav_menus = $manager->nav_menus;
		$failures  = array();
		\wp_set_current_user( self::insert_user( $ctx->fork( 'publisher' ) ) );

		$missing_type = $nav_menus->insert_auto_draft_post( array( 'post_title' => 'Missing Type' ) );
		$empty_title  = $nav_menus->insert_auto_draft_post(
			array(
				'post_type'  => 'page',
				'post_title' => '',
			)
		);
		$bad_status   = $nav_menus->insert_auto_draft_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Bad Status',
				'post_status' => 'draft',
			)
		);
		$title        = 'Draft ' . self::safe_label( $ctx );
		$post_name    = 'draft-' . strtolower( $ctx->identifier( 4, 8 ) );
		$draft        = $nav_menus->insert_auto_draft_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_name'    => $post_name,
				'post_content' => $ctx->htmlFragment( 2 ),
			)
		);
		$published    = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Already Published',
					'post_status' => 'publish',
				)
			),
			true
		);

		$sanitized = $nav_menus->sanitize_nav_menus_created_posts(
			array(
				$draft instanceof \WP_Post ? $draft->ID : 0,
				is_int( $published ) ? $published : 0,
				0,
			)
		);
		$setting   = $manager->add_setting(
			'nav_menus_created_posts',
			array(
				'default'           => $sanitized,
				'sanitize_callback' => array( $nav_menus, 'sanitize_nav_menus_created_posts' ),
			)
		);
		$manager->set_post_value( 'nav_menus_created_posts', $sanitized );
		$before_meta = $draft instanceof \WP_Post ? \get_post_meta( $draft->ID, '_customize_draft_post_name', true ) : null;
		$nav_menus->save_nav_menus_created_posts( $setting );
		$after       = $draft instanceof \WP_Post ? \get_post( $draft->ID ) : null;
		$after_meta  = $draft instanceof \WP_Post ? \get_post_meta( $draft->ID, '_customize_draft_post_name', true ) : null;
		$uuid_meta   = $draft instanceof \WP_Post ? \get_post_meta( $draft->ID, '_customize_changeset_uuid', true ) : null;

		$bad_nonce    = self::capture_ajax(
			static function () use ( $nav_menus ): void {
				self::set_ajax_post(
					array(
					'action'                => 'customize-nav-menus-insert-auto-draft',
					'customize-menus-nonce' => 'bad',
					'params'                => array(
						'post_type'  => 'page',
						'post_title' => 'Bad nonce',
					),
					)
				);
				$nav_menus->ajax_insert_auto_draft_post();
			}
		);
		$missing_type_ajax = self::capture_ajax(
			static function () use ( $nav_menus ): void {
				self::set_ajax_post(
					array(
					'action'                => 'customize-nav-menus-insert-auto-draft',
					'customize-menus-nonce' => \wp_create_nonce( 'customize-menus' ),
					'params'                => array(
						'post_title' => 'Missing type',
					),
					)
				);
				$nav_menus->ajax_insert_auto_draft_post();
			}
		);
		$success_ajax = self::capture_ajax(
			static function () use ( $nav_menus, $ctx ): void {
				self::set_ajax_post(
					array(
					'action'                => 'customize-nav-menus-insert-auto-draft',
					'customize-menus-nonce' => \wp_create_nonce( 'customize-menus' ),
					'params'                => array(
						'post_type'  => 'page',
						'post_title' => 'Ajax ' . self::safe_label( $ctx->fork( 'ajax-title' ) ),
					),
					)
				);
				$nav_menus->ajax_insert_auto_draft_post();
			}
		);
		$success_json = self::json_body( $success_ajax );
		$ajax_post_id = isset( $success_json['data']['post_id'] ) ? (int) $success_json['data']['post_id'] : 0;
		$ajax_post    = $ajax_post_id > 0 ? \get_post( $ajax_post_id ) : null;

		self::collect_failure(
			$failures,
			self::error_code( $missing_type ) === 'unknown_post_type'
				&& self::error_code( $empty_title ) === 'empty_title'
				&& self::error_code( $bad_status ) === 'status_forbidden',
			'direct auto-draft insertion rejects missing type, empty title, and caller-supplied status',
			array(
				'missingType' => self::describe_error( $missing_type ),
				'emptyTitle'  => self::describe_error( $empty_title ),
				'badStatus'   => self::describe_error( $bad_status ),
			)
		);

		self::collect_failure(
			$failures,
			$draft instanceof \WP_Post
				&& array( $draft->ID ) === $sanitized
				&& $before_meta === $post_name
				&& $after instanceof \WP_Post
				&& 'publish' === $after->post_status
				&& $post_name === $after->post_name
				&& '' === $after_meta
				&& $uuid_meta === $manager->changeset_uuid(),
			'created auto-drafts carry changeset metadata, sanitize to publishable IDs, and save as published posts with draft slugs',
			array(
				'draft'      => self::describe_post( $draft ),
				'after'      => self::describe_post( $after ),
				'sanitized'  => $sanitized,
				'beforeMeta' => $before_meta,
				'afterMeta'  => $after_meta,
				'uuidMeta'   => $uuid_meta,
			)
		);

		self::collect_failure(
			$failures,
			self::json_error_code( $bad_nonce ) === 'bad_nonce'
				&& self::json_error_code( $missing_type_ajax ) === 'missing_post_type_param'
				&& self::json_success( $success_ajax )
				&& $ajax_post instanceof \WP_Post
				&& 'auto-draft' === $ajax_post->post_status,
			'auto-draft AJAX handler rejects bad nonce/missing type and returns a URL-bearing success envelope',
			array(
				'badNonce'    => self::summarize_capture( $bad_nonce ),
				'missingType' => self::summarize_capture( $missing_type_ajax ),
				'success'     => self::summarize_capture( $success_ajax ),
				'ajaxPost'    => self::describe_post( $ajax_post ),
			)
		);

		return self::row(
			$ctx,
			'customizer-nav-widgets-requests.nav-menu-auto-drafts',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_nav_menu_setting_placeholder_remaps( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();
		$manager     = self::manager( $ctx );
		$nav_menus   = $manager->nav_menus;
		$placeholder = -1 * $ctx->int( 101, 999 );
		$failures    = array();
		\wp_set_current_user( self::insert_user( $ctx->fork( 'menu-user' ) ) );

		$menu_setting_id = 'nav_menu[' . $placeholder . ']';
		$menu_args       = $nav_menus->filter_dynamic_setting_args( false, $menu_setting_id );
		$menu_class      = $nav_menus->filter_dynamic_setting_class( \WP_Customize_Setting::class, $menu_setting_id, (array) $menu_args );
		$item_setting_id = 'nav_menu_item[' . ( $placeholder - 1 ) . ']';
		$item_args       = $nav_menus->filter_dynamic_setting_args( false, $item_setting_id );
		$item_class      = $nav_menus->filter_dynamic_setting_class( \WP_Customize_Setting::class, $item_setting_id, (array) $item_args );
		$invalid_args    = $nav_menus->filter_dynamic_setting_args( false, 'nav_menu[bad]' );

		$menu_setting = new \WP_Customize_Nav_Menu_Setting( $manager, $menu_setting_id );
		$manager->add_setting( $menu_setting );
		$manager->add_setting(
			'nav_menu_locations[primary]',
			array(
				'default' => 0,
				'type'    => 'theme_mod',
			)
		);

		$widget_setting_id = 'widget_nav_menu[2]';
		$manager->add_setting( $widget_setting_id, $manager->widgets->get_setting_args( $widget_setting_id ) );
		$widget_value = $manager->widgets->sanitize_widget_js_instance(
			array(
				'nav_menu' => $placeholder,
				'title'    => 'Menu Widget ' . self::safe_label( $ctx ),
			),
			'nav_menu'
		);

		$raw_menu_value = array(
			'auto_add'    => true,
			'description' => 'Desc <script>alert(1)</script>',
			'extra'       => 'dropped',
			'name'        => 'Menu <b>' . self::safe_label( $ctx->fork( 'name' ) ) . '</b>',
			'parent'      => -42,
		);
		$sanitized      = $menu_setting->sanitize( $raw_menu_value );
		$invalid        = $menu_setting->sanitize( 'not-array' );

		$manager->set_post_value( $menu_setting_id, $raw_menu_value );
		$manager->set_post_value( 'nav_menu_locations[primary]', $placeholder );
		$manager->set_post_value( $widget_setting_id, $widget_value );
		$menu_setting->save();

		$response       = \apply_filters( 'customize_save_response', array() );
		$theme_location = \get_theme_mod( 'nav_menu_locations', array() );
		$widget_option  = \get_option( 'widget_nav_menu', array() );
		$nav_options    = \get_option( 'nav_menu_options', array() );
		$widget_update  = $response['widget_nav_menu_updates'][ $widget_setting_id ] ?? null;
		$widget_remap   = is_array( $widget_update ) ? $manager->widgets->sanitize_widget_instance( $widget_update, 'nav_menu' ) : null;

		self::collect_failure(
			$failures,
			is_array( $menu_args )
				&& \WP_Customize_Nav_Menu_Setting::class === $menu_class
				&& is_array( $item_args )
				&& \WP_Customize_Nav_Menu_Item_Setting::class === $item_class
				&& false === $invalid_args,
			'dynamic Customizer setting filters recognize nav menu and menu-item placeholders only',
			array(
				'menuArgs'    => $menu_args,
				'menuClass'   => $menu_class,
				'itemArgs'    => $item_args,
				'itemClass'   => $item_class,
				'invalidArgs' => $invalid_args,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $sanitized )
				&& null === $invalid
				&& '&lt;b&gt;' === substr( $sanitized['name'], 5, 9 )
				&& 'Desc' === $sanitized['description']
				&& 0 === $sanitized['parent']
				&& true === $sanitized['auto_add'],
			'nav menu setting sanitizes names/descriptions, clamps parents, preserves auto-add, and rejects invalid shapes',
			array(
				'sanitized' => $sanitized,
				'invalid'   => $invalid,
			)
		);

		self::collect_failure(
			$failures,
			$menu_setting->term_id > 0
				&& $placeholder === $menu_setting->previous_term_id
				&& 'inserted' === $menu_setting->update_status
				&& isset( $theme_location['primary'] )
				&& (int) $theme_location['primary'] === $menu_setting->term_id
				&& isset( $widget_option[2]['nav_menu'] )
				&& (int) $widget_option[2]['nav_menu'] === $menu_setting->term_id
				&& is_array( $widget_remap )
				&& (int) $widget_remap['nav_menu'] === $menu_setting->term_id
				&& in_array( $menu_setting->term_id, (array) ( $nav_options['auto_add'] ?? array() ), true )
				&& isset( $response['nav_menu_updates'][0]['status'] )
				&& 'inserted' === $response['nav_menu_updates'][0]['status'],
			'placeholder nav menu save remaps theme locations, nav-menu widgets, auto-add options, and save response IDs',
			array(
				'termId'        => $menu_setting->term_id,
				'previousTerm'  => $menu_setting->previous_term_id,
				'themeLocation' => $theme_location,
				'widgetOption'  => $widget_option,
				'widgetRemap'   => $widget_remap,
				'navOptions'    => $nav_options,
				'response'      => $response,
			)
		);

		return self::row(
			$ctx,
			'customizer-nav-widgets-requests.nav-menu-setting-placeholder-remaps',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_nav_menu_preview_hmacs( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();
		$manager   = self::manager( $ctx );
		$nav_menus = $manager->nav_menus;
		$fixtures  = self::seed_nav_content( $ctx );
		$menu_id   = (int) \wp_create_nav_menu( 'Preview ' . self::safe_label( $ctx ) );
		\wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Preview Link',
				'menu-item-url'    => 'https://example.test/preview',
				'menu-item-status' => 'publish',
			)
		);

		$args = array(
			'container'  => false,
			'echo'       => false,
			'fallback_cb' => false,
			'menu'       => $menu_id,
		);
		ksort( $args );
		$args['args_hmac'] = $nav_menus->hash_nav_menu_args( $args );
		$valid             = $nav_menus->render_nav_menu_partial( null, $args );
		$missing_hmac      = $nav_menus->render_nav_menu_partial(
			null,
			array(
				'menu' => $menu_id,
			)
		);
		$tampered          = $args;
		$tampered['menu']  = $menu_id + 9999 + $fixtures['post'];
		$mismatch          = $nav_menus->render_nav_menu_partial( null, $tampered );
		$tracked_args      = $nav_menus->filter_wp_nav_menu_args(
			array(
				'container'   => 'nav',
				'echo'        => true,
				'fallback_cb' => false,
				'menu'        => $menu_id,
			)
		);
		$filtered_menu     = $nav_menus->filter_wp_nav_menu( '<nav class="menu"></nav>', (object) $tracked_args );
		$response          = $nav_menus->export_partial_rendered_nav_menu_instances( array( 'ok' => true ) );
		$failures          = array();

		self::collect_failure(
			$failures,
			false !== $valid
				&& false === $missing_hmac
				&& false === $mismatch
				&& isset( $tracked_args['customize_preview_nav_menus_args']['args_hmac'] )
				&& str_contains( $filtered_menu, 'data-customize-partial-id' )
				&& isset( $response['ok'], $response['nav_menu_instance_args'] )
				&& is_array( $response['nav_menu_instance_args'] )
				&& array_key_exists( $tracked_args['customize_preview_nav_menus_args']['args_hmac'], $response['nav_menu_instance_args'] ),
			'nav menu preview partials require matching HMACs and exported preview args inject selective-refresh placement data',
			array(
				'valid'        => self::describe_string( (string) $valid ),
				'missingHmac'  => $missing_hmac,
				'mismatch'     => $mismatch,
				'filteredMenu' => self::describe_string( $filtered_menu ),
				'responseKeys' => array_keys( $response ),
			)
		);

		return self::row(
			$ctx,
			'customizer-nav-widgets-requests.nav-menu-preview-hmacs',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_widget_instance_and_ajax_requests( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();
		$manager = self::manager( $ctx );
		$widgets = $manager->widgets;
		self::register_widget_fixture( $ctx );
		$user_id = self::insert_user( $ctx );
		\wp_set_current_user( $user_id );

		$instance  = array(
			'title' => 'Widget ' . self::safe_label( $ctx ),
			'body'  => $ctx->htmlFragment( 2 ),
		);
		$js_value  = $widgets->sanitize_widget_js_instance( $instance, self::WIDGET_BASE );
		$roundtrip = $widgets->sanitize_widget_instance( $js_value, self::WIDGET_BASE );
		$tampered  = $js_value;
		$tampered['encoded_serialized_instance'] = base64_encode( serialize( array( 'title' => 'Changed' ) ) );
		$malformed = $js_value;
		$malformed['encoded_serialized_instance'] = '@@@not-base64@@@';

		$setting_args = $widgets->get_setting_args( 'widget_' . self::WIDGET_BASE . '[2]' );
		$sidebar_args = $widgets->get_setting_args( 'sidebars_widgets[' . self::SIDEBAR_ID . ']' );
		$sidebar_ids  = $widgets->sanitize_sidebar_widgets_js_instance(
			array(
				self::WIDGET_ID,
				'missing-widget-9',
			)
		);
		$direct_update = $widgets->call_widget_update( self::WIDGET_ID );

		$missing_widget = self::capture_ajax(
			static function () use ( $widgets ): void {
				self::set_ajax_post(
					array(
					'action' => 'update-widget',
					'nonce'  => \wp_create_nonce( 'update-widget' ),
					)
				);
				$widgets->wp_ajax_update_widget();
			}
		);
		$template_widget = self::capture_ajax(
			static function () use ( $widgets ): void {
				self::set_ajax_post(
					array(
					'action'                         => 'update-widget',
					'nonce'                          => \wp_create_nonce( 'update-widget' ),
					'widget-id'                      => self::WIDGET_BASE . '-__i__',
					'widget-' . self::WIDGET_BASE . '-__i__' => array(
						'__i__' => array(
							'title' => 'Template',
						),
					),
					)
				);
				$widgets->wp_ajax_update_widget();
			}
		);
		$success_ajax    = self::capture_ajax(
			static function () use ( $widgets, $js_value ): void {
				self::set_ajax_post(
					array(
					'action'                   => 'update-widget',
					'nonce'                    => \wp_create_nonce( 'update-widget' ),
					'widget-id'                => self::WIDGET_ID,
					'sanitized_widget_setting' => \wp_json_encode( $js_value ),
					)
				);
				$widgets->wp_ajax_update_widget();
			}
		);
		\wp_set_current_user( 0 );
		$logged_out = self::capture_ajax(
			static function () use ( $widgets ): void {
				self::set_ajax_post(
					array(
					'action'    => 'update-widget',
					'nonce'     => \wp_create_nonce( 'update-widget' ),
					'widget-id' => self::WIDGET_ID,
					)
				);
				$widgets->wp_ajax_update_widget();
			}
		);

		$success_json = self::json_body( $success_ajax );
		$failures     = array();

		self::collect_failure(
			$failures,
			$roundtrip === $instance
				&& null === $widgets->sanitize_widget_instance( $tampered, self::WIDGET_BASE )
				&& null === $widgets->sanitize_widget_instance( $malformed, self::WIDGET_BASE )
				&& isset( $setting_args['sanitize_callback'], $setting_args['sanitize_js_callback'] )
				&& isset( $sidebar_args['sanitize_callback'], $sidebar_args['sanitize_js_callback'] )
				&& array( self::WIDGET_ID ) === $sidebar_ids,
			'widget setting args, signed JS instances, tamper rejection, and sidebar widget filtering are stable',
			array(
				'jsValueKeys'  => array_keys( $js_value ),
				'roundtrip'    => $roundtrip,
				'settingArgs'  => array_intersect_key( $setting_args, array_flip( array( 'type', 'capability', 'transport' ) ) ),
				'sidebarIds'   => $sidebar_ids,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $direct_update )
				&& isset( $direct_update['instance'], $direct_update['form'] )
				&& is_string( $direct_update['form'] )
				&& str_contains( $direct_update['form'], 'widget-' . self::WIDGET_BASE . '-2-title' )
				&& self::json_error_code( $missing_widget ) === 'missing_widget-id'
				&& self::json_error_code( $template_widget ) === 'template_widget_not_updatable'
				&& self::json_success( $success_ajax )
				&& isset( $success_json['data']['form'], $success_json['data']['instance']['instance_hash_key'] )
				&& self::ajax_die_message( $logged_out ) === '0',
			'widget update requests reject missing/template/logged-out cases and return signed updated instances on success',
			array(
				'directUpdate' => is_array( $direct_update ) ? array_keys( $direct_update ) : self::describe_error( $direct_update ),
				'missing'      => self::summarize_capture( $missing_widget ),
				'template'     => self::summarize_capture( $template_widget ),
				'success'      => self::summarize_capture( $success_ajax ),
				'loggedOut'    => self::summarize_capture( $logged_out ),
			)
		);

		return self::row(
			$ctx,
			'customizer-nav-widgets-requests.widget-instance-ajax',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_widget_selective_refresh_partials( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();
		$manager = self::manager( $ctx );
		$widgets = $manager->widgets;
		self::register_widget_fixture( $ctx );

		$partial_args = $widgets->customize_dynamic_partial_args( false, 'widget[' . self::WIDGET_ID . ']' );
		$invalid_args = $widgets->customize_dynamic_partial_args( false, 'not-a-widget' );
		$widgets->selective_refresh_init();

		$params = array(
			array(
				'id'            => self::SIDEBAR_ID,
				'before_widget' => '<aside id="' . self::WIDGET_ID . '" class="widget">',
				'after_widget'  => '</aside>',
				'widget_id'     => self::WIDGET_ID,
			),
		);

		ob_start();
		$widgets->start_dynamic_sidebar( self::SIDEBAR_ID );
		$marked = $widgets->filter_dynamic_sidebar_params( $params );
		$widgets->end_dynamic_sidebar( self::SIDEBAR_ID );
		$markers = ob_get_clean();

		$partial = new \WP_Customize_Partial(
			$manager->selective_refresh,
			'widget[' . self::WIDGET_ID . ']',
			array(
				'render_callback' => array( $widgets, 'render_widget_partial' ),
			)
		);
		$valid_render = $widgets->render_widget_partial(
			$partial,
			array(
				'sidebar_id'              => self::SIDEBAR_ID,
				'sidebar_instance_number' => 1,
			)
		);
		$invalid_render = $widgets->render_widget_partial(
			$partial,
			array(
				'sidebar_id' => 'missing-sidebar',
			)
		);
		$failures = array();

		self::collect_failure(
			$failures,
			is_array( $partial_args )
				&& 'widget' === ( $partial_args['type'] ?? null )
				&& array( 'widget_' . self::WIDGET_BASE . '[2]' ) === ( $partial_args['settings'] ?? array() )
				&& false === $invalid_args
				&& isset( $marked[0]['before_widget'] )
				&& str_contains( $marked[0]['before_widget'], 'data-customize-partial-id' )
				&& str_contains( $marked[0]['before_widget'], 'data-customize-widget-id="' . self::WIDGET_ID . '"' )
				&& str_contains( $markers, 'dynamic_sidebar_before:' . self::SIDEBAR_ID . ':1' )
				&& str_contains( $markers, 'dynamic_sidebar_after:' . self::SIDEBAR_ID . ':1' ),
			'widget dynamic partial args and sidebar wrappers carry selective-refresh placement metadata',
			array(
				'partialArgs'  => $partial_args,
				'markedBefore' => $marked[0]['before_widget'] ?? null,
				'markers'      => self::describe_string( $markers ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $valid_render )
				&& str_contains( $valid_render, 'cfz-widget-title' )
				&& false === $invalid_render
				&& false === \has_filter( 'sidebars_widgets', array( $widgets, 'filter_sidebars_widgets_for_rendering_widget' ) ),
			'widget partial rendering isolates the target widget and rejects invalid sidebar context',
			array(
				'validRender'   => self::describe_string( (string) $valid_render ),
				'invalidRender' => $invalid_render,
			)
		);

		return self::row(
			$ctx,
			'customizer-nav-widgets-requests.widget-selective-refresh',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function load_customizer_runtime(): void {
		if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		foreach (
			array(
				ABSPATH . WPINC . '/customize/class-wp-customize-partial.php',
				ABSPATH . WPINC . '/customize/class-wp-customize-nav-menu-setting.php',
				ABSPATH . WPINC . '/customize/class-wp-customize-nav-menu-item-setting.php',
				ABSPATH . WPINC . '/class-wp-customize-widgets.php',
				ABSPATH . WPINC . '/class-wp-customize-nav-menus.php',
				ABSPATH . 'wp-admin/includes/ajax-actions.php',
			) as $file
		) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Customize_Manager',
				'WP_Customize_Nav_Menu_Item_Setting',
				'WP_Customize_Nav_Menu_Setting',
				'WP_Customize_Nav_Menus',
				'WP_Customize_Partial',
				'WP_Customize_Selective_Refresh',
				'WP_Customize_Widgets',
				'WP_Error',
				'WP_Post',
				'WP_Query',
				'WP_Rewrite',
				'WP_User',
				'WP_Widget_Factory',
			) as $class
		) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'check_ajax_referer',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'get_post',
				'get_post_meta',
				'get_theme_mod',
				'get_terms',
				'register_nav_menus',
				'register_sidebar',
				'remove_filter',
				'update_option',
				'wp_create_nav_menu',
				'wp_create_nonce',
				'wp_die',
				'wp_doing_ajax',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_json_encode',
				'wp_register_sidebar_widget',
				'wp_register_widget_control',
				'wp_send_json_error',
				'wp_set_current_user',
				'wp_set_sidebars_widgets',
				'wp_update_nav_menu_item',
				'wp_update_post',
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

	private static function manager( \ComponentFuzz\FuzzContext $ctx ): \WP_Customize_Manager {
		self::ensure_cap_filter();

		$components_filter = static function (): array {
			return array( 'nav_menus', 'widgets' );
		};
		$block_editor_filter = '__return_false';

		\add_filter( 'customize_loaded_components', $components_filter, 1000 );
		\add_filter( 'use_widgets_block_editor', $block_editor_filter, 1000 );
		try {
			$manager = new \WP_Customize_Manager(
				array(
					'changeset_uuid'     => self::uuid( $ctx ),
					'settings_previewed' => false,
					'branching'          => true,
					'autosaved'          => false,
				)
			);
		} finally {
			\remove_filter( 'customize_loaded_components', $components_filter, 1000 );
			\remove_filter( 'use_widgets_block_editor', $block_editor_filter, 1000 );
		}

		$GLOBALS['wp_customize'] = $manager;
		return $manager;
	}

	private static function reset_runtime(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		if ( function_exists( 'create_initial_post_types' ) ) {
			\create_initial_post_types();
		}
		if ( function_exists( 'create_initial_taxonomies' ) ) {
			\create_initial_taxonomies();
		}

		$GLOBALS['wp_registered_nav_menus']       = array();
		$GLOBALS['wp_registered_sidebars']        = array();
		$GLOBALS['wp_registered_widgets']         = array();
		$GLOBALS['wp_registered_widget_controls'] = array();
		$GLOBALS['wp_registered_widget_updates']  = array();
		$GLOBALS['_wp_sidebars_widgets']          = array();
		$GLOBALS['sidebars_widgets']              = array();
		$GLOBALS['wp_widget_factory']             = new \WP_Widget_Factory();
		$GLOBALS['wp_rewrite']                    = new \WP_Rewrite();
		$GLOBALS['wp_query']                      = new \WP_Query();
		$GLOBALS['wp_the_query']                  = $GLOBALS['wp_query'];
		$GLOBALS['_wp_theme_features']            = array();
		$GLOBALS['wp_customize']                  = null;
		self::$widget_updates                     = array();

		\add_theme_support( 'menus' );
		\add_theme_support( 'widgets' );
		\add_theme_support( 'customize-selective-refresh-widgets' );
		\register_nav_menus(
			array(
				'primary' => 'Primary',
				'footer'  => 'Footer',
			)
		);

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'blog_charset'                         => 'UTF-8',
					'blogdescription'                      => 'Component Fuzz Site',
					'blogname'                             => 'Component Fuzz',
					'category_base'                        => '',
					'comments_per_page'                    => 50,
					'date_format'                          => 'F j, Y',
					'home'                                 => 'https://example.test',
					'nav_menu_options'                     => array( 'auto_add' => array() ),
					'page_for_posts'                       => '0',
					'page_on_front'                        => '0',
					'permalink_structure'                  => '/%postname%/',
					'show_on_front'                        => 'posts',
					'sidebars_widgets'                     => array(),
					'siteurl'                              => 'https://example.test',
					'stylesheet'                           => self::ACTIVE_STYLESHEET,
					'tag_base'                             => '',
					'template'                             => self::ACTIVE_STYLESHEET,
					'theme_mods_' . self::ACTIVE_STYLESHEET => array(),
					'widget_' . self::WIDGET_BASE          => array(),
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		if ( function_exists( 'wp_set_current_user' ) ) {
			\wp_set_current_user( 0 );
		}
		if ( method_exists( 'WP_Customize_Setting', 'reset_aggregated_multidimensionals' ) ) {
			\WP_Customize_Setting::reset_aggregated_multidimensionals();
		}
	}

	private static function register_widget_fixture( \ComponentFuzz\FuzzContext $ctx ): void {
		\register_sidebar(
			array(
				'id'            => self::SIDEBAR_ID,
				'name'          => 'Component Fuzz Sidebar',
				'description'   => 'Sidebar ' . self::safe_label( $ctx ),
				'before_widget' => '<aside id="%1$s" class="widget %2$s">',
				'after_widget'  => '</aside>',
				'before_title'  => '<h2>',
				'after_title'   => '</h2>',
			)
		);
		\wp_register_sidebar_widget(
			self::WIDGET_ID,
			'Component Fuzz Widget',
			array( self::class, 'widget_callback' ),
			array(
				'classname'                   => 'cfz-widget',
				'customize_selective_refresh' => true,
				'description'                 => 'Component fuzz widget',
			),
			array( 'number' => 2 )
		);
		\wp_register_widget_control(
			self::WIDGET_ID,
			'Component Fuzz Widget',
			array( self::class, 'widget_control_callback' ),
			array(
				'height'  => 200,
				'id_base' => self::WIDGET_BASE,
				'width'   => 250,
			),
			array( 'number' => 2 )
		);
		$GLOBALS['wp_registered_widget_updates'][ self::WIDGET_BASE ] = array(
			'callback' => array( self::class, 'widget_update_callback' ),
			'params'   => array( array( 'number' => -1 ) ),
		);

		$instance = array(
			'title' => 'Initial ' . self::safe_label( $ctx->fork( 'initial' ) ),
			'body'  => 'Body',
		);
		self::$widget_updates[2] = $instance;
		\update_option( 'widget_' . self::WIDGET_BASE, array( 2 => $instance ) );
		\wp_set_sidebars_widgets(
			array(
				self::SIDEBAR_ID       => array( self::WIDGET_ID ),
				'wp_inactive_widgets' => array(),
			)
		);
		$GLOBALS['_wp_sidebars_widgets'] = array(
			self::SIDEBAR_ID       => array( self::WIDGET_ID ),
			'wp_inactive_widgets' => array(),
		);
		$GLOBALS['sidebars_widgets']     = $GLOBALS['_wp_sidebars_widgets'];
	}

	private static function seed_nav_content( \ComponentFuzz\FuzzContext $ctx ): array {
		$page_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Fuzz Page ' . self::safe_label( $ctx ),
					'post_content' => 'Page body',
				)
			),
			true
		);
		$post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => 'Fuzz Post ' . self::safe_label( $ctx->fork( 'post' ) ),
					'post_content' => 'Post body',
				)
			),
			true
		);
		$draft_page = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'auto-draft',
					'post_title'   => 'Fuzz Draft ' . self::safe_label( $ctx->fork( 'draft' ) ),
					'post_content' => 'Draft body',
				)
			),
			true
		);
		$term = \wp_insert_term( 'Fuzz Term ' . self::safe_label( $ctx->fork( 'term' ) ), 'category' );

		return array(
			'draftPage' => is_int( $draft_page ) ? $draft_page : 0,
			'page'      => is_int( $page_id ) ? $page_id : 0,
			'post'      => is_int( $post_id ) ? $post_id : 0,
			'term'      => is_array( $term ) && isset( $term['term_id'] ) ? (int) $term['term_id'] : 0,
		);
	}

	private static function capture_ajax( callable $callback ): array {
		$start_level       = ob_get_level();
		$die_calls         = array();
		$status_headers    = array();
		$captured          = false;
		$returned          = false;
		$throwable         = null;
		$output            = '';
		$cleaned_buffers   = 0;
		$doing_ajax_filter = static function (): bool {
			return true;
		};
		$ajax_die_filter   = static function ( $handler ) use ( &$die_calls ) {
			unset( $handler );
			return static function ( $message = '', string $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'kind'    => 'ajax',
					'message' => $message,
					'title'   => $title,
					'args'    => \wp_parse_args( $args ),
				);
				throw new CustomizerNavWidgetsRequests_DieCaptured( 'Captured ajax wp_die.' );
			};
		};
		$default_die_filter = static function ( $handler ) use ( &$die_calls ) {
			unset( $handler );
			return static function ( $message = '', string $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'kind'    => 'default',
					'message' => $message,
					'title'   => $title,
					'args'    => \wp_parse_args( $args ),
				);
				throw new CustomizerNavWidgetsRequests_DieCaptured( 'Captured default wp_die.' );
			};
		};
		$status_filter      = static function ( string $status_header, int $code, string $description, string $protocol ) use ( &$status_headers ): string {
			$status_headers[] = compact( 'code', 'description', 'protocol', 'status_header' );
			return $status_header;
		};
		$charset_filter     = static fn() => 'UTF-8';

		if ( ! headers_sent() ) {
			header_remove();
		}

		\add_filter( 'wp_doing_ajax', $doing_ajax_filter, 9999 );
		\add_filter( 'wp_die_ajax_handler', $ajax_die_filter, 1 );
		\add_filter( 'wp_die_handler', $default_die_filter, 1 );
		\add_filter( 'status_header', $status_filter, 10, 4 );
		\add_filter( 'pre_option_blog_charset', $charset_filter, 10, 3 );

		ob_start();
		try {
			$callback();
			$returned = true;
		} catch ( CustomizerNavWidgetsRequests_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$throwable = $e;
		} finally {
			while ( ob_get_level() > $start_level ) {
				$chunk   = ob_get_clean();
				$output  = ( false === $chunk ? '' : $chunk ) . $output;
				++$cleaned_buffers;
			}

			\remove_filter( 'wp_doing_ajax', $doing_ajax_filter, 9999 );
			\remove_filter( 'wp_die_ajax_handler', $ajax_die_filter, 1 );
			\remove_filter( 'wp_die_handler', $default_die_filter, 1 );
			\remove_filter( 'status_header', $status_filter, 10 );
			\remove_filter( 'pre_option_blog_charset', $charset_filter, 10 );

			if ( ! headers_sent() ) {
				header_remove();
			}
		}

		return array(
			'bufferBalanced' => $start_level === ob_get_level() && 1 === $cleaned_buffers,
			'captured'       => $captured,
			'dieCalls'       => $die_calls,
			'output'         => $output,
			'returned'       => $returned,
			'statusHeaders'  => $status_headers,
			'throwable'      => null === $throwable ? null : self::describe_throwable( $throwable ),
		);
	}

	private static function set_ajax_post( array $post ): void {
		$_POST    = $post;
		$_REQUEST = $post;
	}

	private static function ensure_cap_filter(): void {
		if ( false === \has_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ) ) ) {
			\add_filter( 'user_has_cap', array( self::class, 'grant_runtime_capabilities' ), 10, 4 );
		}
	}

	private static function with_caps_denied( callable $callback ) {
		$deny = static function ( array $allcaps ): array {
			foreach ( array_keys( $allcaps ) as $cap ) {
				$allcaps[ $cap ] = false;
			}
			return $allcaps;
		};
		\add_filter( 'user_has_cap', $deny, PHP_INT_MAX, 4 );
		try {
			return $callback();
		} finally {
			\remove_filter( 'user_has_cap', $deny, PHP_INT_MAX );
		}
	}

	private static function insert_user( \ComponentFuzz\FuzzContext $ctx ): int {
		$token = substr( hash( 'sha1', 'customizer-nav-widget:' . $ctx->seed() . ':' . $ctx->iteration() ), 0, 12 );
		$user  = \wp_insert_user(
			array(
				'display_name' => 'Customizer Widget ' . $token,
				'role'         => 'editor',
				'user_email'   => 'customizer-widget-' . $token . '@example.test',
				'user_login'   => 'customizer_widget_' . $token,
				'user_pass'    => 'component-fuzz-pass',
			)
		);

		return is_int( $user ) ? $user : 0;
	}

	private static function uuid( \ComponentFuzz\FuzzContext $ctx ): string {
		$hex = hash( 'sha256', 'customizer-nav-widgets:' . $ctx->seed() . ':' . $ctx->iteration() );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-a' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}

	private static function item_ids( $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}
		return array_values(
			array_map(
				static fn( array $item ): string => (string) ( $item['id'] ?? '' ),
				array_filter( $items, 'is_array' )
			)
		);
	}

	private static function contains_item_id( $items, string $id ): bool {
		return in_array( $id, self::item_ids( $items ), true );
	}

	private static function json_body( array $capture ): ?array {
		$body = trim( (string) ( $capture['output'] ?? '' ) );
		foreach ( array_reverse( $capture['dieCalls'] ?? array() ) as $call ) {
			$message = $call['message'] ?? '';
			if ( is_string( $message ) && '' !== trim( $message ) ) {
				$body .= trim( $message );
				break;
			}
		}

		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function json_success( array $capture ): bool {
		$body = self::json_body( $capture );
		return is_array( $body ) && true === ( $body['success'] ?? null );
	}

	private static function json_error_code( array $capture ): ?string {
		$body = self::json_body( $capture );
		if ( ! is_array( $body ) || true === ( $body['success'] ?? null ) ) {
			return null;
		}
		if ( is_string( $body['data'] ?? null ) ) {
			return $body['data'];
		}
		return is_array( $body['data'] ?? null ) && isset( $body['data']['code'] ) ? (string) $body['data']['code'] : null;
	}

	private static function ajax_die_message( array $capture ): ?string {
		$call = $capture['dieCalls'][0] ?? null;
		if ( ! is_array( $call ) ) {
			return null;
		}
		return (string) ( $call['message'] ?? '' );
	}

	private static function summarize_capture( array $capture ): array {
		return array(
			'captured'       => (bool) ( $capture['captured'] ?? false ),
			'returned'       => (bool) ( $capture['returned'] ?? false ),
			'bufferBalanced' => (bool) ( $capture['bufferBalanced'] ?? false ),
			'json'           => self::json_body( $capture ),
			'dieMessage'     => self::ajax_die_message( $capture ),
			'statusHeaders'  => $capture['statusHeaders'] ?? array(),
			'throwable'      => $capture['throwable'] ?? null,
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ctx->result( $invariant, $ok, $data );
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'data'    => $data,
			);
		}
	}

	private static function error_code( $value ): ?string {
		return $value instanceof \WP_Error ? $value->get_error_code() : null;
	}

	private static function describe_error( $value ) {
		if ( ! $value instanceof \WP_Error ) {
			return $value;
		}
		return array(
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
		);
	}

	private static function describe_post( $post ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return array(
			'ID'          => (int) $post->ID,
			'postName'    => $post->post_name,
			'postStatus'  => $post->post_status,
			'postTitle'   => $post->post_title,
			'postType'    => $post->post_type,
		);
	}

	private static function describe_string( string $value ): array {
		return array(
			'length'  => strlen( $value ),
			'preview' => substr( $value, 0, 220 ),
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

	private static function safe_label( \ComponentFuzz\FuzzContext $ctx ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $ctx->identifier( 4, 10 ) ), 0, 12 );
	}

	private static function db_options(): ?array {
		return isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
			? $GLOBALS['wpdb']->component_fuzz_get_options()
			: null;
	}

	private static function db_content_counts(): ?array {
		return isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
			? $GLOBALS['wpdb']->component_fuzz_content_counts()
			: null;
	}

	private static function snapshot_state(): array {
		global $wp_filter;

		$hooks = array();
		foreach ( self::HOOK_SNAPSHOT_NAMES as $hook_name ) {
			$hooks[ $hook_name ] = isset( $wp_filter[ $hook_name ] ) ? clone $wp_filter[ $hook_name ] : null;
		}

		return array(
			'contentCounts'      => self::db_content_counts(),
			'globals'            => self::snapshot_globals(
				array(
					'_GET',
					'_POST',
					'_REQUEST',
					'current_screen',
					'current_user',
					'pagenow',
					'sidebars_widgets',
					'userdata',
					'user_ID',
					'user_email',
					'user_identity',
					'user_login',
					'user_url',
					'wp_actions',
					'wp_current_filter',
					'wp_customize',
					'wp_filter',
					'wp_filters',
					'wp_post_types',
					'wp_query',
					'wp_registered_nav_menus',
					'wp_registered_sidebars',
					'wp_registered_widget_controls',
					'wp_registered_widget_updates',
					'wp_registered_widgets',
					'wp_rewrite',
					'wp_taxonomies',
					'wp_the_query',
					'wp_widget_factory',
					'_wp_sidebars_widgets',
					'_wp_theme_features',
				)
			),
			'hooks'              => $hooks,
			'obLevel'            => ob_get_level(),
			'options'            => self::db_options(),
			'aggregatedSettings' => self::get_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals' ),
			'controlCount'       => self::get_static_property( 'WP_Customize_Control', 'instance_count' ),
			'sectionCount'       => self::get_static_property( 'WP_Customize_Section', 'instance_count' ),
			'panelCount'         => self::get_static_property( 'WP_Customize_Panel', 'instance_count' ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		global $wp_filter;

		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}

		self::restore_globals( $snapshot['globals'] );

		foreach ( self::HOOK_SNAPSHOT_NAMES as $hook_name ) {
			if ( null === $snapshot['hooks'][ $hook_name ] ) {
				unset( $wp_filter[ $hook_name ] );
			} else {
				$wp_filter[ $hook_name ] = clone $snapshot['hooks'][ $hook_name ];
			}
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			if ( null !== $snapshot['options'] ) {
				$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
			}
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		if ( ! headers_sent() ) {
			header_remove();
		}

		self::set_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals', $snapshot['aggregatedSettings'] );
		self::set_static_property( 'WP_Customize_Control', 'instance_count', $snapshot['controlCount'] );
		self::set_static_property( 'WP_Customize_Section', 'instance_count', $snapshot['sectionCount'] );
		self::set_static_property( 'WP_Customize_Panel', 'instance_count', $snapshot['panelCount'] );
	}

	private static function state_matches( array $snapshot ): bool {
		return ob_get_level() === $snapshot['obLevel']
			&& self::globals_match( $snapshot['globals'] )
			&& self::db_content_counts() === $snapshot['contentCounts']
			&& self::db_options() === $snapshot['options']
			&& self::get_static_property( 'WP_Customize_Setting', 'aggregated_multidimensionals' ) === $snapshot['aggregatedSettings']
			&& self::get_static_property( 'WP_Customize_Control', 'instance_count' ) === $snapshot['controlCount']
			&& self::get_static_property( 'WP_Customize_Section', 'instance_count' ) === $snapshot['sectionCount']
			&& self::get_static_property( 'WP_Customize_Panel', 'instance_count' ) === $snapshot['panelCount'];
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

	private static function globals_match( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
			if ( $entry['exists'] && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}
		return true;
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

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class, false ) || ! property_exists( $class, $property ) ) {
			return null;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		if ( PHP_VERSION_ID < 80100 && method_exists( $reflection, 'setAccessible' ) ) {
			$reflection->setAccessible( true );
		}

		return $reflection->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class, false ) || ! property_exists( $class, $property ) ) {
			return;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		if ( PHP_VERSION_ID < 80100 && method_exists( $reflection, 'setAccessible' ) ) {
			$reflection->setAccessible( true );
		}
		$reflection->setValue( null, $value );
	}
}

final class CustomizerNavWidgetsRequests_DieCaptured extends \RuntimeException {}
