<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB WordPress admin bar APIs.
 */
final class AdminBarSurface {
	public const NAME = 'admin-bar';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-bar.bootstrap-apis-available',
					'Required admin bar APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot        = self::snapshot_state();
		$server_snapshot = self::snapshot_server( array( 'HTTP_ACCEPT', 'CONTENT_TYPE' ) );

		try {
			return array(
				self::check_node_lifecycle( $ctx->fork( 'node-lifecycle' ) ),
				self::check_parent_child_rendering( $ctx->fork( 'parent-child-rendering' ) ),
				self::check_render_escaping_contracts( $ctx->fork( 'render-escaping' ) ),
				self::check_back_compat_parents_and_tabindex( $ctx->fork( 'back-compat-tabindex' ) ),
				self::check_initialize_side_effects( $ctx->fork( 'initialize-side-effects' ) ),
				self::check_show_admin_bar_filters( $ctx->fork( 'show-admin-bar-filters' ) ),
				self::check_default_menu_hook_registration( $ctx->fork( 'default-menu-hooks' ) ),
				self::check_default_callback_node_graph( $ctx->fork( 'default-callback-node-graph' ) ),
				self::check_single_site_callback_node_graph( $ctx->fork( 'single-site-callback-node-graph' ) ),
			);
		} catch ( \Throwable $e ) {
			return array(
				$ctx->fail(
					'admin-bar.surface-no-throw',
					array( 'throwable' => self::describe_throwable( $e ) )
				),
			);
		} finally {
			self::restore_state( $snapshot );
			self::restore_server( $server_snapshot );
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Admin_Bar', 'WP_Screen', 'WP_Scripts', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_action',
				'current_theme_supports',
				'did_action',
				'do_action',
				'esc_attr',
				'esc_attr_e',
				'esc_js',
				'esc_url',
				'admin_url',
				'create_initial_post_types',
				'current_user_can',
				'get_avatar',
				'get_bloginfo',
				'get_current_user_id',
				'get_edit_profile_url',
				'get_post_type_object',
				'get_theme_support',
				'home_url',
				'has_filter',
				'number_format_i18n',
				'is_admin',
				'is_admin_bar_showing',
				'is_embed',
				'is_network_admin',
				'is_user_admin',
				'is_user_logged_in',
				'network_admin_url',
				'remove_action',
				'remove_filter',
				'sanitize_title',
				'self_admin_url',
				'set_current_screen',
				'show_admin_bar',
				'wp_admin_bar_add_secondary_groups',
				'wp_admin_bar_appearance_menu',
				'wp_admin_bar_command_palette_menu',
				'wp_admin_bar_comments_menu',
				'wp_admin_bar_new_content_menu',
				'wp_admin_bar_my_account_item',
				'wp_admin_bar_my_account_menu',
				'wp_admin_bar_render',
				'wp_admin_bar_search_menu',
				'wp_admin_bar_sidebar_toggle',
				'wp_admin_bar_site_menu',
				'wp_admin_bar_updates_menu',
				'wp_admin_bar_wp_menu',
				'wp_cache_set',
				'wp_count_comments',
				'wp_enqueue_script',
				'wp_enqueue_style',
				'wp_get_current_user',
				'wp_get_update_data',
				'wp_is_json_request',
				'wp_is_mobile',
				'wp_logout_url',
				'wp_parse_args',
				'wp_register_script',
				'wp_script_is',
				'wp_scripts',
				'wp_strip_all_tags',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_node_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$bar      = new \WP_Admin_Bar();

		$parent_id = self::node_id( $ctx->fork( 'parent-id' ), 'cfz-parent' );
		$child_id  = self::node_id( $ctx->fork( 'child-id' ), 'cfz-child' );
		$group_id  = self::node_id( $ctx->fork( 'group-id' ), 'cfz-group' );
		$sibling_id = self::node_id( $ctx->fork( 'sibling-id' ), 'cfz-sibling' );

		$title        = self::fuzz_title( $ctx->fork( 'title' ), 'Lifecycle' );
		$updated_title = self::fuzz_title( $ctx->fork( 'updated-title' ), 'Updated' );
		$href         = self::fuzz_href( $ctx->fork( 'href' ) );
		$class        = self::fuzz_class( $ctx->fork( 'class' ) );
		$rel          = 'nofollow ' . $ctx->identifier( 3, 8 );

		self::collect_failure(
			$failures,
			null === $bar->get_node( $parent_id ) && null === $bar->get_nodes(),
			'a new admin bar has no public nodes',
			array(
				'node'  => $bar->get_node( $parent_id ),
				'nodes' => $bar->get_nodes(),
			)
		);

		$bar->add_node(
			(object) array(
				'id'    => $parent_id,
				'title' => $title,
				'href'  => $href,
				'meta'  => array(
					'class' => $class,
					'title' => 'tooltip ' . $ctx->text( 0, 24 ),
				),
			)
		);

		$parent = $bar->get_node( $parent_id );
		self::collect_failure(
			$failures,
			$parent instanceof \stdClass
				&& $parent_id === $parent->id
				&& $title === $parent->title
				&& $href === $parent->href
				&& $class === ( $parent->meta['class'] ?? null ),
			'add_node accepts object input and stores node fields',
			array(
				'expected' => array(
					'id'    => $parent_id,
					'title' => $title,
					'href'  => $href,
					'class' => $class,
				),
				'actual'   => $parent,
			)
		);

		$clone = $bar->get_node( $parent_id );
		if ( $clone ) {
			$clone->title         = 'mutated clone';
			$clone->meta['class'] = 'mutated-clone-class';
		}
		$after_clone_mutation = $bar->get_node( $parent_id );
		self::collect_failure(
			$failures,
			$after_clone_mutation instanceof \stdClass
				&& $title === $after_clone_mutation->title
				&& $class === ( $after_clone_mutation->meta['class'] ?? null ),
			'get_node returns a clone instead of the mutable internal node',
			array( 'node' => $after_clone_mutation )
		);

		$bar->add_menu(
			array(
				'id'    => $parent_id,
				'title' => $updated_title,
				'meta'  => array(
					'rel' => $rel,
				),
			)
		);
		$updated_parent = $bar->get_node( $parent_id );
		self::collect_failure(
			$failures,
			$updated_parent instanceof \stdClass
				&& $updated_title === $updated_parent->title
				&& $href === $updated_parent->href
				&& $class === ( $updated_parent->meta['class'] ?? null )
				&& $rel === ( $updated_parent->meta['rel'] ?? null ),
			'add_menu aliases add_node and duplicate nodes preserve unspecified fields',
			array( 'node' => $updated_parent )
		);

		$bar->add_node(
			$parent_id,
			(object) array(),
			array(
				'id'    => $child_id,
				'title' => self::fuzz_title( $ctx->fork( 'child-title' ), 'Child' ),
			)
		);
		$child = $bar->get_node( $child_id );
		self::collect_failure(
			$failures,
			$child instanceof \stdClass
				&& $parent_id === $child->parent
				&& false === $child->href
				&& false === $child->group,
			'legacy add_node signature assigns the supplied parent and defaults',
			array( 'child' => $child )
		);

		$bar->add_group(
			array(
				'id'     => $group_id,
				'parent' => $parent_id,
				'meta'   => array(
					'class' => self::fuzz_class( $ctx->fork( 'group-class' ) ),
				),
			)
		);
		$group = $bar->get_node( $group_id );
		self::collect_failure(
			$failures,
			$group instanceof \stdClass
				&& $parent_id === $group->parent
				&& true === $group->group,
			'add_group stores a group node under the requested parent',
			array( 'group' => $group )
		);

		$before_invalid = self::node_registry_shape( $bar->get_nodes() );
		$bar->add_node( array( 'id' => '', 'title' => '' ) );
		$bar->remove_menu( 'missing-' . $ctx->identifier( 4, 12 ) );
		$after_invalid = self::node_registry_shape( $bar->get_nodes() );
		self::collect_failure(
			$failures,
			$before_invalid === $after_invalid,
			'empty no-title nodes and missing removals do not mutate existing nodes',
			array(
				'before' => $before_invalid,
				'after'  => $after_invalid,
			)
		);

		$generated_title = 'Generated Empty Id ' . $ctx->identifier( 4, 10 );
		$generated_id    = \esc_attr( \sanitize_title( trim( $generated_title ) ) );
		$bar->add_node(
			array(
				'id'    => '',
				'title' => $generated_title,
			)
		);
		self::collect_failure(
			$failures,
			'' !== $generated_id && $bar->get_node( $generated_id ) instanceof \stdClass,
			'empty IDs with titles generate a sanitized back-compat node ID',
			array(
				'title'       => $generated_title,
				'expected_id' => $generated_id,
				'node'        => $bar->get_node( $generated_id ),
			)
		);

		$bar->add_node(
			array(
				'id'    => $sibling_id,
				'title' => self::fuzz_title( $ctx->fork( 'sibling-title' ), 'Sibling' ),
			)
		);
		$bar->remove_node( $parent_id );
		self::collect_failure(
			$failures,
			null === $bar->get_node( $parent_id )
				&& $bar->get_node( $child_id ) instanceof \stdClass
				&& $bar->get_node( $group_id ) instanceof \stdClass
				&& $bar->get_node( $sibling_id ) instanceof \stdClass,
			'remove_node removes only the requested ID and leaves siblings intact',
			array(
				'parent'  => $bar->get_node( $parent_id ),
				'child'   => $bar->get_node( $child_id ),
				'group'   => $bar->get_node( $group_id ),
				'sibling' => $bar->get_node( $sibling_id ),
			)
		);

		$bar->remove_menu( $child_id );
		self::collect_failure(
			$failures,
			null === $bar->get_node( $child_id ),
			'remove_menu aliases remove_node',
			array( 'child' => $bar->get_node( $child_id ) )
		);

		return self::row(
			$ctx,
			'admin-bar.nodes.lifecycle-and-cloning',
			array() === $failures,
			array(
				'ids'      => compact( 'parent_id', 'child_id', 'group_id', 'sibling_id' ),
				'failures' => $failures,
			)
		);
	}

	private static function check_parent_child_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$bar      = new \WP_Admin_Bar();

		$top_id          = self::node_id( $ctx->fork( 'top' ), 'cfz-top' );
		$child_id        = self::node_id( $ctx->fork( 'child' ), 'cfz-child' );
		$sub_group_id    = self::node_id( $ctx->fork( 'sub-group' ), 'cfz-sub-group' );
		$sub_group_child = self::node_id( $ctx->fork( 'sub-group-child' ), 'cfz-sub-child' );
		$root_group_id   = self::node_id( $ctx->fork( 'root-group' ), 'cfz-root-group' );
		$root_group_item = self::node_id( $ctx->fork( 'root-group-item' ), 'cfz-root-item' );
		$inner_group_id  = self::node_id( $ctx->fork( 'inner-group' ), 'cfz-inner-group' );
		$inner_item_id   = self::node_id( $ctx->fork( 'inner-item' ), 'cfz-inner-item' );
		$orphan_id       = self::node_id( $ctx->fork( 'orphan' ), 'cfz-orphan' );
		$removed_parent  = self::node_id( $ctx->fork( 'removed-parent' ), 'cfz-removed-parent' );
		$removed_child   = self::node_id( $ctx->fork( 'removed-child' ), 'cfz-removed-child' );

		$root_group_class = 'root-group ' . self::fuzz_class( $ctx->fork( 'root-group-class' ) );
		$sub_group_class  = 'sub-group ' . self::fuzz_class( $ctx->fork( 'sub-group-class' ) );
		$inner_class      = 'inner-group ' . self::fuzz_class( $ctx->fork( 'inner-class' ) );

		$bar->add_node(
			array(
				'id'    => $top_id,
				'title' => self::fuzz_title( $ctx->fork( 'top-title' ), 'Top' ),
				'href'  => self::fuzz_href( $ctx->fork( 'top-href' ) ),
				'meta'  => array(
					'menu_title' => 'Top menu ' . $ctx->text( 0, 24 ),
				),
			)
		);
		$bar->add_node(
			array(
				'id'     => $child_id,
				'parent' => $top_id,
				'title'  => self::fuzz_title( $ctx->fork( 'child-title' ), 'Child' ),
			)
		);
		$bar->add_group(
			array(
				'id'     => $sub_group_id,
				'parent' => $top_id,
				'meta'   => array( 'class' => $sub_group_class ),
			)
		);
		$bar->add_node(
			array(
				'id'     => $sub_group_child,
				'parent' => $sub_group_id,
				'title'  => self::fuzz_title( $ctx->fork( 'sub-title' ), 'Sub child' ),
			)
		);
		$bar->add_group(
			array(
				'id'   => $root_group_id,
				'meta' => array( 'class' => $root_group_class ),
			)
		);
		$bar->add_node(
			array(
				'id'     => $root_group_item,
				'parent' => $root_group_id,
				'title'  => self::fuzz_title( $ctx->fork( 'root-item-title' ), 'Root item' ),
			)
		);
		$bar->add_group(
			array(
				'id'     => $inner_group_id,
				'parent' => $root_group_id,
				'meta'   => array( 'class' => $inner_class ),
			)
		);
		$bar->add_node(
			array(
				'id'     => $inner_item_id,
				'parent' => $inner_group_id,
				'title'  => self::fuzz_title( $ctx->fork( 'inner-title' ), 'Inner item' ),
			)
		);
		$bar->add_node(
			array(
				'id'     => $orphan_id,
				'parent' => 'missing-parent-' . $ctx->identifier( 3, 8 ),
				'title'  => self::fuzz_title( $ctx->fork( 'orphan-title' ), 'Orphan' ),
			)
		);
		$bar->add_node(
			array(
				'id'    => $removed_parent,
				'title' => 'Removed parent',
			)
		);
		$bar->add_node(
			array(
				'id'     => $removed_child,
				'parent' => $removed_parent,
				'title'  => 'Removed child',
			)
		);
		$bar->remove_node( $removed_parent );

		$output = self::render_bar( $bar );

		self::collect_failure(
			$failures,
			str_contains( $output, '<div id="wpadminbar" class="' )
				&& str_contains( $output, '<div class="quicklinks" id="wp-toolbar" role="navigation" aria-label="Toolbar">' )
				&& str_contains( $output, "role='menu'" ),
			'render emits the expected toolbar wrapper and menu tags',
			array( 'output' => self::preview_string( $output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $output, self::rendered_id( 'root-default' ) )
				&& str_contains( $output, self::rendered_id( $top_id ) )
				&& str_contains( $output, self::rendered_id( $top_id . '-default' ) )
				&& str_contains( $output, self::rendered_id( $child_id ) ),
			'top-level and item-child nodes are wrapped in default root/submenu groups',
			array(
				'top'          => $top_id,
				'child'        => $child_id,
				'rootDefault'  => self::rendered_id( 'root-default' ),
				'childDefault' => self::rendered_id( $top_id . '-default' ),
				'output'       => self::preview_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $output, self::rendered_id( $root_group_id . '-container' ) )
				&& str_contains( $output, 'ab-group-container' )
				&& str_contains( $output, self::rendered_id( $root_group_id ) )
				&& str_contains( $output, \esc_attr( trim( $root_group_class . ' ab-top-menu' ) ) )
				&& str_contains( $output, self::rendered_id( $inner_group_id ) )
				&& str_contains( $output, \esc_attr( trim( $inner_class . ' ab-submenu' ) ) )
				&& str_contains( $output, self::rendered_id( $inner_item_id ) ),
			'root groups get top-menu classes and nested groups are wrapped in containers',
			array(
				'rootGroup' => $root_group_id,
				'innerGroup' => $inner_group_id,
				'output'    => self::preview_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $output, self::rendered_id( $sub_group_id ) )
				&& str_contains( $output, \esc_attr( trim( $sub_group_class . ' ab-submenu' ) ) )
				&& str_contains( $output, self::rendered_id( $sub_group_child ) ),
			'explicit groups under item parents render as submenus',
			array(
				'subGroup' => $sub_group_id,
				'output'   => self::preview_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			! str_contains( $output, self::rendered_id( $orphan_id ) )
				&& ! str_contains( $output, self::rendered_id( $removed_child ) ),
			'orphaned and removed-parent descendants do not render',
			array(
				'orphan'       => $orphan_id,
				'removedChild' => $removed_child,
				'output'       => self::preview_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			null === $bar->get_node( $top_id ) && null === $bar->get_nodes(),
			'bound admin bars stop exposing mutable node registries after render',
			array(
				'node'  => $bar->get_node( $top_id ),
				'nodes' => $bar->get_nodes(),
			)
		);

		return self::row(
			$ctx,
			'admin-bar.render.parent-child-binding',
			array() === $failures,
			array(
				'ids'      => compact(
					'top_id',
					'child_id',
					'sub_group_id',
					'sub_group_child',
					'root_group_id',
					'root_group_item',
					'inner_group_id',
					'inner_item_id',
					'orphan_id',
					'removed_parent',
					'removed_child'
				),
				'failures' => $failures,
			)
		);
	}

	private static function check_render_escaping_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$bar      = new \WP_Admin_Bar();

		$unsafe_id = 'cfz-"\'<>&-snowman-' . $ctx->int( 100, 999 );
		$child_id  = self::node_id( $ctx->fork( 'unsafe-child' ), 'cfz-unsafe-child' );
		$safe_id   = self::node_id( $ctx->fork( 'safe-link' ), 'cfz-safe-link' );

		$raw_title  = 'Raw <strong>Title</strong><script>cfzTitle()</script> Unicode é 中文';
		$raw_html   = '<span class="cfz-html" data-raw="<unsafe>&quot;">raw & unsafe</span>';
		$class      = 'alpha "\' <unsafe-class> snowman ' . $ctx->identifier( 3, 8 );
		$meta_title = 'Tooltip "\' <unsafe-title> é';
		$onclick    = 'return confirm("<tag>&\'snowman");';
		$target     = '_blank" autofocus="bad';
		$rel        = 'nofollow "\' <rel>';
		$lang       = 'en-US" onclick="bad';
		$dir        = 'ltr" bad';
		$menu_title = 'Menu "\' <unsafe-menu> 中文';
		$bad_href   = "javascript:alert('cfz')";
		$safe_href  = "https://example.test/path/<unsafe>/?q=\"'&snow=%E2%98%83";

		$bar->add_node(
			array(
				'id'    => $unsafe_id,
				'title' => $raw_title,
				'href'  => $bad_href,
				'meta'  => array(
					'class'      => $class,
					'html'       => $raw_html,
					'onclick'    => $onclick,
					'target'     => $target,
					'title'      => $meta_title,
					'rel'        => $rel,
					'lang'       => $lang,
					'dir'        => $dir,
					'menu_title' => $menu_title,
				),
			)
		);
		$bar->add_node(
			array(
				'id'     => $child_id,
				'parent' => $unsafe_id,
				'title'  => 'Child for unsafe parent',
			)
		);
		$bar->add_node(
			array(
				'id'    => $safe_id,
				'title' => 'Safe href item',
				'href'  => $safe_href,
				'meta'  => array(
					'class' => 'safe-link-class',
				),
			)
		);

		$output = self::render_bar( $bar );

		self::collect_failure(
			$failures,
			str_contains( $output, self::rendered_id( $unsafe_id ) )
				&& str_contains( $output, 'class="' . \esc_attr( trim( 'menupop ' . $class ) ) . '"' )
				&& ! str_contains( $output, 'class="' . trim( 'menupop ' . $class ) . '"' ),
			'node IDs and classes are escaped in rendered attributes',
			array(
				'id'     => $unsafe_id,
				'class'  => $class,
				'output' => self::preview_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $output, "<a class='ab-item' role=\"menuitem\" aria-expanded=\"false\" href=''" )
				&& ! str_contains( $output, "href='{$bad_href}'" )
				&& ! str_contains( $output, 'javascript:alert' )
				&& str_contains( $output, "href='" . \esc_url( $safe_href ) . "'" ),
			'unsafe href protocols are stripped and safe hrefs are escaped',
			array(
				'badHref'  => $bad_href,
				'safeHref' => $safe_href,
				'output'   => self::preview_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $output, "onclick='" . \esc_js( $onclick ) . "'" )
				&& str_contains( $output, "target='" . \esc_attr( $target ) . "'" )
				&& str_contains( $output, "title='" . \esc_attr( $meta_title ) . "'" )
				&& str_contains( $output, "rel='" . \esc_attr( $rel ) . "'" )
				&& str_contains( $output, "lang='" . \esc_attr( $lang ) . "'" )
				&& str_contains( $output, "dir='" . \esc_attr( $dir ) . "'" )
				&& str_contains( $output, "aria-label='" . \esc_attr( $menu_title ) . "'" ),
			'meta attributes and submenu aria labels are escaped according to render rules',
			array(
				'meta'   => compact( 'onclick', 'target', 'meta_title', 'rel', 'lang', 'dir', 'menu_title' ),
				'output' => self::preview_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $output, $raw_title )
				&& str_contains( $output, $raw_html ),
			'title and meta html are rendered raw by the admin bar contract',
			array(
				'title'  => $raw_title,
				'html'   => $raw_html,
				'output' => self::preview_string( $output ),
			)
		);

		return self::row(
			$ctx,
			'admin-bar.render.escaping-and-raw-html-contracts',
			array() === $failures,
			array(
				'ids'      => compact( 'unsafe_id', 'child_id', 'safe_id' ),
				'failures' => $failures,
			)
		);
	}

	private static function check_back_compat_parents_and_tabindex( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$snapshot = self::snapshot_state();

		try {
			$bar      = new \WP_Admin_Bar();
			$avatar_id = self::node_id( $ctx->fork( 'avatar-child' ), 'cfz-avatar-child' );
			$blogs_id  = self::node_id( $ctx->fork( 'blogs-child' ), 'cfz-blogs-child' );
			$tab_id    = self::node_id( $ctx->fork( 'numeric-tab' ), 'cfz-tab' );
			$bad_id    = self::node_id( $ctx->fork( 'bad-tab' ), 'cfz-bad-tab' );
			$bad_tab   = '1" autofocus="bad ' . $ctx->identifier( 3, 8 );

			$before_deprecated = \did_action( 'deprecated_argument_run' );

			$bar->add_node(
				array(
					'id'    => 'my-account',
					'title' => 'Account root',
				)
			);
			$bar->add_node(
				array(
					'id'    => 'my-sites',
					'title' => 'Sites root',
				)
			);
			$bar->add_node(
				array(
					'id'     => $avatar_id,
					'parent' => 'my-account-with-avatar',
					'title'  => 'Avatar alias child',
					'href'   => 'https://example.test/account',
				)
			);
			$bar->add_node(
				array(
					'id'     => $blogs_id,
					'parent' => 'my-blogs',
					'title'  => 'Blogs alias child',
				)
			);
			$bar->add_node(
				array(
					'id'    => $tab_id,
					'title' => 'Numeric tabindex',
					'href'  => 'https://example.test/tab',
					'meta'  => array(
						'tabindex' => '0',
					),
				)
			);
			$bar->add_node(
				array(
					'id'    => $bad_id,
					'title' => 'Hostile tabindex',
					'href'  => 'https://example.test/bad-tab',
					'meta'  => array(
						'tabindex' => $bad_tab,
					),
				)
			);

			$avatar_node      = $bar->get_node( $avatar_id );
			$blogs_node       = $bar->get_node( $blogs_id );
			$after_deprecated = \did_action( 'deprecated_argument_run' );
			$output           = self::render_bar( $bar );
		} finally {
			self::restore_state( $snapshot );
		}

		$avatar_node      = $avatar_node ?? null;
		$blogs_node       = $blogs_node ?? null;
		$after_deprecated = $after_deprecated ?? null;
		$before_deprecated = $before_deprecated ?? null;
		$output           = $output ?? '';
		$bad_tab          = $bad_tab ?? '';

		self::collect_failure(
			$failures,
			$avatar_node instanceof \stdClass
				&& 'my-account' === $avatar_node->parent
				&& $blogs_node instanceof \stdClass
				&& 'my-sites' === $blogs_node->parent
				&& $after_deprecated === $before_deprecated + 2,
			'back-compat parent aliases normalize to current admin-bar parent IDs and fire deprecation hooks',
			array(
				'avatarNode'       => $avatar_node,
				'blogsNode'        => $blogs_node,
				'beforeDeprecated' => $before_deprecated,
				'afterDeprecated'  => $after_deprecated,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $output, self::rendered_id( $avatar_id ) )
				&& str_contains( $output, self::rendered_id( $blogs_id ) )
				&& str_contains( $output, 'tabindex="0"' )
				&& ! str_contains( $output, 'autofocus="bad' )
				&& ! str_contains( $output, 'tabindex="' . $bad_tab ),
			'numeric tabindex values render while hostile non-numeric tabindex values are omitted',
			array(
				'avatarId' => $avatar_id,
				'blogsId'  => $blogs_id,
				'tabId'    => $tab_id,
				'badTab'   => $bad_tab,
				'output'   => self::preview_string( $output ),
			)
		);

		return self::row(
			$ctx,
			'admin-bar.render.back-compat-parents-and-tabindex',
			array() === $failures,
			array(
				'failures' => $failures,
			)
		);
	}

	private static function check_initialize_side_effects( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$snapshot = self::snapshot_state();

		$theme_callback = '__return_false';

		try {
			$GLOBALS['_wp_theme_features']['admin-bar'] = array(
				array(
					'callback' => $theme_callback,
				),
			);

			$before_init_actions = \did_action( 'admin_bar_init' );
			$bar                 = new \WP_Admin_Bar();

			$bar->initialize();

			$user_is_object       = $bar->user instanceof \stdClass;
			$wp_head_header       = \has_action( 'wp_head', 'wp_admin_bar_header' );
			$admin_head_header    = \has_action( 'admin_head', 'wp_admin_bar_header' );
			$theme_bump_callback  = \has_action( 'wp_head', $theme_callback );
			$default_bump_present = \has_action( 'wp_head', '_admin_bar_bump_cb' );
			$after_init_actions   = \did_action( 'admin_bar_init' );
		} finally {
			self::restore_state( $snapshot );
		}

		$user_is_object       = $user_is_object ?? false;
		$wp_head_header       = $wp_head_header ?? false;
		$admin_head_header    = $admin_head_header ?? false;
		$theme_bump_callback  = $theme_bump_callback ?? false;
		$default_bump_present = $default_bump_present ?? false;
		$before_init_actions  = $before_init_actions ?? null;
		$after_init_actions   = $after_init_actions ?? null;

		self::collect_failure(
			$failures,
			$user_is_object
				&& false !== $wp_head_header
				&& false !== $admin_head_header
				&& false !== $theme_bump_callback
				&& false === $default_bump_present,
			'initialize registers toolbar headers and honors the theme support bump callback',
			array(
				'userIsObject'       => $user_is_object,
				'wpHeadHeader'       => $wp_head_header,
				'adminHeadHeader'    => $admin_head_header,
				'themeBumpCallback'  => $theme_bump_callback,
				'defaultBumpPresent' => $default_bump_present,
			)
		);

		self::collect_failure(
			$failures,
			$after_init_actions === $before_init_actions + 1,
			'initialize fires admin_bar_init exactly once',
			array(
				'beforeInitActions' => $before_init_actions,
				'afterInitActions'  => $after_init_actions,
			)
		);

		self::collect_failure(
			$failures,
			self::global_matches( $snapshot['globals']['_wp_theme_features'], '_wp_theme_features' )
				&& self::global_matches( $snapshot['globals']['wp_actions'], 'wp_actions' )
				&& self::global_matches( $snapshot['globals']['wp_filter'], 'wp_filter' )
				&& self::global_matches( $snapshot['globals']['wp_scripts'], 'wp_scripts' )
				&& self::global_matches( $snapshot['globals']['wp_styles'], 'wp_styles' ),
			'initialize probe restores theme support, hook counters, filters, and asset queues',
			array(
				'themeFeatures' => self::global_summary( '_wp_theme_features' ),
				'wpActions'     => self::global_summary( 'wp_actions' ),
				'wpScripts'     => self::global_summary( 'wp_scripts' ),
				'wpStyles'      => self::global_summary( 'wp_styles' ),
			)
		);

		return self::row(
			$ctx,
			'admin-bar.initialize.hooks-theme-support-and-restoration',
			array() === $failures,
			array(
				'failures' => $failures,
			)
		);
	}

	private static function check_show_admin_bar_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$snapshot        = self::snapshot_state();
		$server_snapshot = self::snapshot_server( array( 'HTTP_ACCEPT', 'CONTENT_TYPE' ) );

		$force_true  = null;
		$first_false = null;
		$last_false  = null;
		$calls_true  = array();
		$calls_false = array();

		try {
			unset( $GLOBALS['current_screen'] );
			$GLOBALS['pagenow']     = 'index.php';
			$_SERVER['HTTP_ACCEPT'] = 'text/html';
			unset( $_SERVER['CONTENT_TYPE'] );

			\show_admin_bar( true );
			$no_filter_true = \is_admin_bar_showing();
			\show_admin_bar( false );
			$no_filter_false = \is_admin_bar_showing();

			$force_true = static function ( $show ) use ( &$calls_true ) {
				$calls_true[] = array( 'force_true', (bool) $show );
				return true;
			};
			\add_filter( 'show_admin_bar', $force_true, 10, 1 );
			\show_admin_bar( false );
			$forced_true = \is_admin_bar_showing();
			\remove_filter( 'show_admin_bar', $force_true, 10 );

			$first_false = static function ( $show ) use ( &$calls_false ) {
				$calls_false[] = array( 'first', (bool) $show );
				return ! $show;
			};
			$last_false  = static function ( $show ) use ( &$calls_false ) {
				$calls_false[] = array( 'last', (bool) $show );
				return false;
			};
			\add_filter( 'show_admin_bar', $first_false, 5, 1 );
			\add_filter( 'show_admin_bar', $last_false, 15, 1 );
			\show_admin_bar( true );
			$forced_false = \is_admin_bar_showing();
			\remove_filter( 'show_admin_bar', $first_false, 5 );
			\remove_filter( 'show_admin_bar', $last_false, 15 );

			unset( $GLOBALS['show_admin_bar'] );
			$GLOBALS['pagenow'] = 'wp-login.php';
			$login_default_hidden = false === \is_admin_bar_showing();

			$filters_removed = false === \has_filter( 'show_admin_bar', $force_true )
				&& false === \has_filter( 'show_admin_bar', $first_false )
				&& false === \has_filter( 'show_admin_bar', $last_false );
		} finally {
			if ( null !== $force_true ) {
				\remove_filter( 'show_admin_bar', $force_true, 10 );
			}
			if ( null !== $first_false ) {
				\remove_filter( 'show_admin_bar', $first_false, 5 );
			}
			if ( null !== $last_false ) {
				\remove_filter( 'show_admin_bar', $last_false, 15 );
			}
			self::restore_state( $snapshot );
			self::restore_server( $server_snapshot );
		}

		$filters_removed      = $filters_removed ?? false;
		$no_filter_true      = $no_filter_true ?? null;
		$no_filter_false     = $no_filter_false ?? null;
		$forced_true         = $forced_true ?? null;
		$forced_false        = $forced_false ?? null;
		$login_default_hidden = $login_default_hidden ?? null;

		self::collect_failure(
			$failures,
			true === $no_filter_true && false === $no_filter_false,
			'show_admin_bar directly controls the default is_admin_bar_showing result',
			array(
				'trueCase'  => $no_filter_true,
				'falseCase' => $no_filter_false,
			)
		);

		self::collect_failure(
			$failures,
			true === $forced_true
				&& array( array( 'force_true', false ) ) === $calls_true,
			'show_admin_bar filters can force a hidden bar visible and receive the current value',
			array(
				'forced' => $forced_true,
				'calls'  => $calls_true,
			)
		);

		self::collect_failure(
			$failures,
			false === $forced_false
				&& array( array( 'first', true ), array( 'last', false ) ) === $calls_false,
			'show_admin_bar filters run in priority order and persist the filtered global value',
			array(
				'forced' => $forced_false,
				'calls'  => $calls_false,
			)
		);

		self::collect_failure(
			$failures,
			true === $login_default_hidden,
			'login-screen default is hidden without DB-backed user preferences',
			array( 'hidden' => $login_default_hidden )
		);

		self::collect_failure(
			$failures,
			$filters_removed
				&& self::global_matches( $snapshot['globals']['show_admin_bar'], 'show_admin_bar' )
				&& self::global_matches( $snapshot['globals']['pagenow'], 'pagenow' )
				&& self::global_matches( $snapshot['globals']['current_screen'], 'current_screen' )
				&& self::server_matches( $server_snapshot, 'HTTP_ACCEPT' )
				&& self::server_matches( $server_snapshot, 'CONTENT_TYPE' ),
			'show_admin_bar filters and globals are restored after probing',
			array(
				'filtersRemoved' => $filters_removed,
				'showAdminBar'   => self::global_summary( 'show_admin_bar' ),
				'pagenow'        => self::global_summary( 'pagenow' ),
			)
		);

		return self::row(
			$ctx,
			'admin-bar.showing.filters-and-restoration',
			array() === $failures,
			array(
				'callsTrue'  => $calls_true,
				'callsFalse' => $calls_false,
				'failures'   => $failures,
			)
		);
	}

	private static function check_default_menu_hook_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$snapshot = self::snapshot_state();

		$after_action_calls = array();
		$after_action       = static function () use ( &$after_action_calls ): void {
			$after_action_calls[] = array(
				'wpMenuPriority'      => \has_action( 'admin_bar_menu', 'wp_admin_bar_wp_menu' ),
				'secondaryPriority'   => \has_action( 'admin_bar_menu', 'wp_admin_bar_add_secondary_groups' ),
				'commentsPriority'    => \has_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu' ),
				'newContentPriority'  => \has_action( 'admin_bar_menu', 'wp_admin_bar_new_content_menu' ),
				'registeredCallbacks' => array_keys( AdminBarSurface::admin_bar_menu_callback_map() ),
			);
		};

		try {
			$bar                    = new \WP_Admin_Bar();
			$before_add_menu_action = \did_action( 'add_admin_bar_menus' );
			\add_action( 'add_admin_bar_menus', $after_action, 10, 0 );

			$bar->add_menus();
			$after_first_action = \did_action( 'add_admin_bar_menus' );
			$first_map          = self::admin_bar_menu_callback_map();

			$bar->add_menus();
			$after_second_action = \did_action( 'add_admin_bar_menus' );
			$second_map          = self::admin_bar_menu_callback_map();

			$after_action_removed = \remove_action( 'add_admin_bar_menus', $after_action, 10 );
			$expected             = self::expected_default_admin_bar_menu_priorities();

			self::collect_failure(
				$failures,
				self::callback_priorities_match( $expected, $first_map )
					&& self::callback_priorities_match( $expected, $second_map ),
				'WP_Admin_Bar::add_menus registers expected default callbacks and priorities',
				array(
					'expected' => $expected,
					'first'    => $first_map,
					'second'   => $second_map,
				)
			);
			self::collect_failure(
				$failures,
				$first_map === $second_map
					&& $after_first_action === $before_add_menu_action + 1
					&& $after_second_action === $before_add_menu_action + 2
					&& 2 === count( $after_action_calls ),
				'repeated add_menus calls fire add_admin_bar_menus but keep callback registration idempotent',
				array(
					'beforeAction' => $before_add_menu_action,
					'afterFirst'   => $after_first_action,
					'afterSecond'  => $after_second_action,
					'afterCalls'   => $after_action_calls,
					'first'        => $first_map,
					'second'       => $second_map,
				)
			);
			self::collect_failure(
				$failures,
				$after_action_removed && false === \has_filter( 'add_admin_bar_menus', $after_action ),
				'local add_admin_bar_menus observer is removed after probing',
				array(
					'removed'  => $after_action_removed,
					'hasAfter' => \has_filter( 'add_admin_bar_menus', $after_action ),
				)
			);
		} finally {
			\remove_action( 'add_admin_bar_menus', $after_action, 10 );
			self::restore_state( $snapshot );
		}

		return self::row(
			$ctx,
			'admin-bar.default-menu-hook-registration',
			array() === $failures,
			array(
				'failures' => $failures,
			)
		);
	}

	private static function check_default_callback_node_graph( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$snapshot = self::snapshot_state();
		$wpdb_snapshot = self::wpdb_runtime_state();

		$user          = self::synthetic_current_user( $ctx->fork( 'user' ) );
		$user_id       = (int) $user->ID;
		$avatar_class  = 'cfz-avatar-' . strtolower( $ctx->identifier( 4, 10 ) );
		$awaiting_mod  = $ctx->int( 2, 23 );
		$avatar_calls  = array();
		$comment_calls = array();

		$cap_filter = self::default_callback_cap_filter(
			array(
				'read',
				'edit_posts',
				'switch_themes',
				'edit_theme_options',
			),
			$user_id
		);

		$avatar_filter = static function ( $avatar, $id_or_email, array $args ) use ( &$avatar_calls, $avatar_class, $user_id ): string {
			unset( $avatar );

			$size           = (int) ( $args['size'] ?? 0 );
			$avatar_calls[] = array(
				'id'   => is_numeric( $id_or_email ) ? (int) $id_or_email : null,
				'size' => $size,
			);

			return '<span class="' . \esc_attr( $avatar_class ) . '" data-user="' . $user_id . '" data-size="' . $size . '"></span>';
		};

		$comments_filter = static function ( $count, int $post_id ) use ( &$comment_calls, $awaiting_mod ): \stdClass {
			unset( $count );

			$comment_calls[] = $post_id;
			return (object) array(
				'approved'       => 0,
				'moderated'      => $awaiting_mod,
				'spam'           => 0,
				'trash'          => 0,
				'post-trashed'   => 0,
				'total_comments' => $awaiting_mod,
				'all'            => $awaiting_mod,
			);
		};

		$blogs_of_user_filter = static function ( $sites, int $filtered_user_id, bool $all ) use ( $user_id ): array {
			unset( $sites, $all );

			if ( $filtered_user_id !== $user_id ) {
				return array();
			}

			return array(
				1 => (object) array(
					'userblog_id' => 1,
					'blogname'    => 'Component Fuzz',
					'domain'      => 'example.test',
					'path'        => '/',
					'site_id'     => 1,
					'siteurl'     => 'http://example.test',
					'archived'    => 0,
					'mature'      => 0,
					'spam'        => 0,
					'deleted'     => 0,
				),
			);
		};

		$user_meta_filter = static function ( $value, int $filtered_user_id, string $meta_key, bool $single, string $meta_type ) use ( $user_id ) {
			unset( $meta_key, $meta_type );

			if ( $filtered_user_id !== $user_id ) {
				return $value;
			}

			return $single ? '' : array();
		};

		$option_filters = array(
			'pre_option_avatar_default' => static function (): string {
				return 'mystery';
			},
			'pre_option_avatar_rating'  => static function (): string {
				return 'G';
			},
			'pre_option_blog_charset'   => static function (): string {
				return 'UTF-8';
			},
			'pre_option_home'           => static function (): string {
				return 'http://example.test';
			},
			'pre_option_siteurl'        => static function (): string {
				return 'http://example.test';
			},
			'pre_option_wp_user_roles'  => static function (): array {
				return array();
			},
		);

		try {
			$GLOBALS['current_user'] = $user;
			$GLOBALS['wp_query']     = (object) array(
				'before_loop' => false,
				'in_the_loop' => false,
			);

			foreach ( array( 'widgets', 'menus', 'custom-background', 'custom-header' ) as $feature ) {
				$GLOBALS['_wp_theme_features'][ $feature ] = true;
			}

			\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
			\add_filter( 'pre_get_avatar', $avatar_filter, 10, 3 );
			\add_filter( 'wp_count_comments', $comments_filter, 10, 2 );
			\add_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter, 10, 3 );
			\add_filter( 'get_user_metadata', $user_meta_filter, 10, 5 );
			foreach ( $option_filters as $hook => $filter ) {
				\add_filter( $hook, $filter, 10, 3 );
			}

			$bar = new \WP_Admin_Bar();
			\wp_admin_bar_add_secondary_groups( $bar );
			\wp_admin_bar_wp_menu( $bar );
			\wp_admin_bar_my_account_item( $bar );
			\wp_admin_bar_my_account_menu( $bar );
			\wp_admin_bar_appearance_menu( $bar );
			\wp_admin_bar_comments_menu( $bar );
			\wp_admin_bar_search_menu( $bar );

			$expected_urls = array(
				'about'      => \self_admin_url( 'about.php' ),
				'background' => \admin_url( 'themes.php?page=custom-background' ),
				'comments'   => \admin_url( 'edit-comments.php' ),
				'contribute' => \self_admin_url( 'contribute.php' ),
				'header'     => \admin_url( 'themes.php?page=custom-header' ),
				'home'       => \home_url( '/' ),
				'menus'      => \admin_url( 'nav-menus.php' ),
				'profile'    => \get_edit_profile_url( $user_id ),
				'themes'     => \admin_url( 'themes.php' ),
				'widgets'    => \admin_url( 'widgets.php' ),
			);
			$top_secondary   = $bar->get_node( 'top-secondary' );
			$wp_external     = $bar->get_node( 'wp-logo-external' );
			$wp_logo         = $bar->get_node( 'wp-logo' );
			$about           = $bar->get_node( 'about' );
			$contribute      = $bar->get_node( 'contribute' );
			$wporg           = $bar->get_node( 'wporg' );
			$documentation   = $bar->get_node( 'documentation' );
			$learn           = $bar->get_node( 'learn' );
			$support_forums  = $bar->get_node( 'support-forums' );
			$feedback        = $bar->get_node( 'feedback' );
			$my_account      = $bar->get_node( 'my-account' );
			$user_actions    = $bar->get_node( 'user-actions' );
			$user_info       = $bar->get_node( 'user-info' );
			$logout          = $bar->get_node( 'logout' );
			$appearance      = $bar->get_node( 'appearance' );
			$themes          = $bar->get_node( 'themes' );
			$widgets         = $bar->get_node( 'widgets' );
			$menus           = $bar->get_node( 'menus' );
			$background      = $bar->get_node( 'background' );
			$header          = $bar->get_node( 'header' );
			$comments        = $bar->get_node( 'comments' );
			$search          = $bar->get_node( 'search' );
			$all_nodes_shape = self::node_registry_shape( $bar->get_nodes() );

			\remove_filter( 'wp_count_comments', $comments_filter, 10 );
			\remove_filter( 'pre_get_avatar', $avatar_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter, 10 );
			\remove_filter( 'get_user_metadata', $user_meta_filter, 10 );
			foreach ( $option_filters as $hook => $filter ) {
				\remove_filter( $hook, $filter, 10 );
			}
			$option_filters_removed = true;
			foreach ( $option_filters as $hook => $filter ) {
				if ( false !== \has_filter( $hook, $filter ) ) {
					$option_filters_removed = false;
					break;
				}
			}
			$filters_removed = false === \has_filter( 'wp_count_comments', $comments_filter )
				&& false === \has_filter( 'pre_get_avatar', $avatar_filter )
				&& false === \has_filter( 'user_has_cap', $cap_filter )
				&& false === \has_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter )
				&& false === \has_filter( 'get_user_metadata', $user_meta_filter )
				&& $option_filters_removed;
		} finally {
			\remove_filter( 'wp_count_comments', $comments_filter, 10 );
			\remove_filter( 'pre_get_avatar', $avatar_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter, 10 );
			\remove_filter( 'get_user_metadata', $user_meta_filter, 10 );
			foreach ( $option_filters as $hook => $filter ) {
				\remove_filter( $hook, $filter, 10 );
			}
			self::restore_state( $snapshot );
		}

		$filters_removed = $filters_removed ?? false;
		$expected_urls   = $expected_urls ?? array();
		$wpdb_restored   = self::wpdb_runtime_matches( $wpdb_snapshot );

		self::collect_failure(
			$failures,
			$top_secondary instanceof \stdClass
				&& true === $top_secondary->group
				&& 'ab-top-secondary' === ( $top_secondary->meta['class'] ?? null )
				&& $wp_external instanceof \stdClass
				&& 'wp-logo' === $wp_external->parent
				&& true === $wp_external->group
				&& 'ab-sub-secondary' === ( $wp_external->meta['class'] ?? null ),
			'default secondary group callback creates exact top-secondary and wp-logo-external groups',
			array(
				'topSecondary' => $top_secondary ?? null,
				'wpExternal'   => $wp_external ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$wp_logo instanceof \stdClass
				&& 'wp-logo' === $wp_logo->id
				&& ( $expected_urls['about'] ?? null ) === $wp_logo->href
				&& 'About WordPress' === ( $wp_logo->meta['menu_title'] ?? null )
				&& $about instanceof \stdClass
				&& 'wp-logo' === $about->parent
				&& ( $expected_urls['about'] ?? null ) === $about->href
				&& $contribute instanceof \stdClass
				&& 'wp-logo' === $contribute->parent
				&& ( $expected_urls['contribute'] ?? null ) === $contribute->href,
			'wp-logo callback creates readable-user core links with exact parents and admin hrefs',
			array(
				'wpLogo'     => $wp_logo ?? null,
				'about'      => $about ?? null,
				'contribute' => $contribute ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$wporg instanceof \stdClass
				&& 'wp-logo-external' === $wporg->parent
				&& 'https://wordpress.org/' === $wporg->href
				&& $documentation instanceof \stdClass
				&& 'https://wordpress.org/documentation/' === $documentation->href
				&& $learn instanceof \stdClass
				&& 'https://learn.wordpress.org/' === $learn->href
				&& $support_forums instanceof \stdClass
				&& 'https://wordpress.org/support/forums/' === $support_forums->href
				&& $feedback instanceof \stdClass
				&& 'https://wordpress.org/support/forum/requests-and-feedback' === $feedback->href,
			'wp-logo callback creates exact external WordPress resource links under the secondary group',
			array(
				'wporg'         => $wporg ?? null,
				'documentation' => $documentation ?? null,
				'learn'         => $learn ?? null,
				'supportForums' => $support_forums ?? null,
				'feedback'      => $feedback ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$my_account instanceof \stdClass
				&& 'top-secondary' === $my_account->parent
				&& ( $expected_urls['profile'] ?? null ) === $my_account->href
				&& 'with-avatar' === ( $my_account->meta['class'] ?? null )
				&& 'Howdy, ' . $user->display_name === ( $my_account->meta['menu_title'] ?? null )
				&& str_contains( $my_account->title, '<span class="display-name">' . $user->display_name . '</span>' )
				&& str_contains( $my_account->title, 'data-size="26"' ),
			'my-account item callback binds the synthetic user display name, profile URL, avatar, and menu title',
			array(
				'node'         => $my_account ?? null,
				'profileUrl'   => $expected_urls['profile'] ?? null,
				'avatarCalls'  => $avatar_calls,
			)
		);

		self::collect_failure(
			$failures,
			$user_actions instanceof \stdClass
				&& 'my-account' === $user_actions->parent
				&& true === $user_actions->group
				&& $user_info instanceof \stdClass
				&& 'user-actions' === $user_info->parent
				&& ( $expected_urls['profile'] ?? null ) === $user_info->href
				&& str_contains( $user_info->title, 'data-size="64"' )
				&& str_contains( $user_info->title, "<span class='display-name'>{$user->display_name}</span>" )
				&& str_contains( $user_info->title, "<span class='username'>{$user->user_login}</span>" )
				&& str_contains( $user_info->title, "<span class='display-name edit-profile'>Edit Profile</span>" )
				&& $logout instanceof \stdClass
				&& 'user-actions' === $logout->parent
				&& str_contains( $logout->href, 'wp-login.php?action=logout' ),
			'my-account submenu callback creates exact user-actions group, profile info, and logout nodes',
			array(
				'userActions' => $user_actions ?? null,
				'userInfo'    => $user_info ?? null,
				'logout'      => $logout ?? null,
			)
		);

		self::collect_failure(
			$failures,
			array(
				array( 'id' => $user_id, 'size' => 26 ),
				array( 'id' => $user_id, 'size' => 64 ),
			) === $avatar_calls,
			'account callbacks request the expected avatar sizes for the same synthetic user',
			array( 'avatarCalls' => $avatar_calls )
		);

		self::collect_failure(
			$failures,
			$appearance instanceof \stdClass
				&& 'site-name' === $appearance->parent
				&& true === $appearance->group
				&& $themes instanceof \stdClass
				&& 'appearance' === $themes->parent
				&& ( $expected_urls['themes'] ?? null ) === $themes->href
				&& $widgets instanceof \stdClass
				&& ( $expected_urls['widgets'] ?? null ) === $widgets->href
				&& $menus instanceof \stdClass
				&& ( $expected_urls['menus'] ?? null ) === $menus->href
				&& $background instanceof \stdClass
				&& ( $expected_urls['background'] ?? null ) === $background->href
				&& 'hide-if-customize' === ( $background->meta['class'] ?? null )
				&& $header instanceof \stdClass
				&& ( $expected_urls['header'] ?? null ) === $header->href
				&& 'hide-if-customize' === ( $header->meta['class'] ?? null ),
			'appearance callback honors generated capabilities and theme-support branches with exact child hrefs',
			array(
				'appearance' => $appearance ?? null,
				'themes'     => $themes ?? null,
				'widgets'    => $widgets ?? null,
				'menus'      => $menus ?? null,
				'background' => $background ?? null,
				'header'     => $header ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$comments instanceof \stdClass
				&& ( $expected_urls['comments'] ?? null ) === $comments->href
				&& str_contains( $comments->title, 'pending-count count-' . $awaiting_mod )
				&& str_contains( $comments->title, '>' . \number_format_i18n( $awaiting_mod ) . '</span>' )
				&& str_contains( $comments->title, 'Comments in moderation' )
				&& array( 0 ) === $comment_calls,
			'comments callback uses the filtered moderation count in its badge and screen-reader text',
			array(
				'comments'     => $comments ?? null,
				'commentCalls' => $comment_calls,
			)
		);

		self::collect_failure(
			$failures,
			$search instanceof \stdClass
				&& 'top-secondary' === $search->parent
				&& 'admin-bar-search' === ( $search->meta['class'] ?? null )
				&& -1 === ( $search->meta['tabindex'] ?? null )
				&& str_contains( $search->title, '<form action="' . \esc_url( $expected_urls['home'] ?? '' ) . '" method="get" id="adminbarsearch">' )
				&& str_contains( $search->title, 'name="s" id="adminbar-search"' )
				&& str_contains( $search->title, 'class="adminbar-button" value="Search"' ),
			'search callback creates the top-secondary search form only on the front end',
			array( 'search' => $search ?? null )
		);

		self::collect_failure(
			$failures,
			$filters_removed
				&& self::global_matches( $snapshot['globals']['current_user'], 'current_user' )
				&& self::global_matches( $snapshot['globals']['_wp_theme_features'], '_wp_theme_features' )
				&& self::global_matches( $snapshot['globals']['wp_query'], 'wp_query' )
				&& self::global_matches( $snapshot['globals']['wp_filter'], 'wp_filter' )
				&& $wpdb_restored,
			'default callback probe removes filters and restores user, theme, query, hook, and wpdb runtime state',
			array(
				'filtersRemoved' => $filters_removed,
				'currentUser'    => self::global_summary( 'current_user' ),
				'themeFeatures'  => self::global_summary( '_wp_theme_features' ),
				'wpQuery'        => self::global_summary( 'wp_query' ),
				'wpdbRestored'   => $wpdb_restored,
			)
		);

		return self::row(
			$ctx,
			'admin-bar.default-callbacks.node-graph-basic',
			array() === $failures,
			array(
				'userId'    => $user_id,
				'nodeIds'   => array_keys( $all_nodes_shape ?? array() ),
				'failures'  => $failures,
			)
		);
	}

	private static function check_single_site_callback_node_graph( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$snapshot        = self::snapshot_state();
		$server_snapshot = self::snapshot_server( array( 'HTTP_USER_AGENT' ) );
		$wpdb_snapshot   = self::wpdb_runtime_state();
		$user            = self::synthetic_current_user( $ctx->fork( 'user' ) );
		$user_id         = (int) $user->ID;
		$token           = strtolower( $ctx->identifier( 5, 10 ) );
		$blog_name       = 'Admin Bar Site ' . $token;
		$update_total    = $ctx->int( 2, 9 );

		$cap_filter = self::default_callback_cap_filter(
			array(
				'activate_plugins',
				'create_users',
				'edit_pages',
				'edit_posts',
				'edit_theme_options',
				'manage_links',
				'manage_network',
				'read',
				'switch_themes',
				'upload_files',
			),
			$user_id
		);

		$blogs_of_user_filter = static function ( $sites, int $filtered_user_id, bool $all ) use ( $user_id ): array {
			unset( $sites, $all );

			if ( $filtered_user_id !== $user_id ) {
				return array();
			}

			return array(
				1 => (object) array(
					'userblog_id' => 1,
					'blogname'    => 'Component Fuzz',
					'domain'      => 'example.test',
					'path'        => '/',
					'site_id'     => 1,
					'siteurl'     => 'http://example.test',
					'archived'    => 0,
					'mature'      => 0,
					'spam'        => 0,
					'deleted'     => 0,
				),
			);
		};

		$user_meta_filter = static function ( $value, int $filtered_user_id, string $meta_key, bool $single, string $meta_type ) use ( $user_id ) {
			unset( $meta_key, $meta_type );

			if ( $filtered_user_id !== $user_id ) {
				return $value;
			}

			return $single ? '' : array();
		};

		$update_events           = array();
		$update_transient_events = array();
		$update_data_filter      = static function ( array $update_data, array $titles ) use ( &$update_events, $update_total ): array {
			$update_events[] = array(
				'incoming' => $update_data,
				'titles'   => $titles,
			);

			$update_data['counts'] = array(
				'plugins'      => 0,
				'themes'       => 0,
				'wordpress'    => 0,
				'translations' => 0,
				'total'        => $update_total,
			);
			$update_data['title']  = 'Component fuzz updates';

			return $update_data;
		};
		$empty_update_filter     = static function ( array $update_data, array $titles ) use ( &$update_events ): array {
			$update_events[] = array(
				'incoming' => $update_data,
				'titles'   => $titles,
			);

			$update_data['counts'] = array(
				'plugins'      => 0,
				'themes'       => 0,
				'wordpress'    => 0,
				'translations' => 0,
				'total'        => 0,
			);
			$update_data['title']  = '';

			return $update_data;
		};
		$transient_probe         = static function ( $pre, string $transient ) use ( &$update_transient_events ) {
			$update_transient_events[] = $transient;
			return $pre;
		};

		$option_filters = array(
			'pre_option_blog_charset'          => static function (): string {
				return 'UTF-8';
			},
			'pre_option_blogname'              => static function () use ( $blog_name ): string {
				return $blog_name;
			},
			'pre_option_home'                  => static function (): string {
				return 'http://example.test';
			},
			'pre_option_link_manager_enabled' => static function (): int {
				return 1;
			},
			'pre_option_siteurl'               => static function (): string {
				return 'http://example.test';
			},
			'pre_option_wp_user_roles'         => static function (): array {
				return array();
			},
		);

		try {
			if ( ! \get_post_type_object( 'post' ) && function_exists( 'create_initial_post_types' ) ) {
				\create_initial_post_types();
			}

			$GLOBALS['current_user'] = $user;
			\wp_cache_set( $user_id, $user->data, 'users' );
			unset( $GLOBALS['current_screen'] );
			$GLOBALS['wp_scripts'] = new \WP_Scripts();
			foreach ( array( 'widgets', 'menus', 'custom-background', 'custom-header' ) as $feature ) {
				$GLOBALS['_wp_theme_features'][ $feature ] = true;
			}

			\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
			\add_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter, 10, 3 );
			\add_filter( 'get_user_metadata', $user_meta_filter, 10, 5 );
			foreach ( $option_filters as $hook => $filter ) {
				\add_filter( $hook, $filter, 10, 3 );
			}

			$site_bar = new \WP_Admin_Bar();
			\wp_admin_bar_site_menu( $site_bar );

			$new_content_bar = new \WP_Admin_Bar();
			\wp_admin_bar_new_content_menu( $new_content_bar );

			\add_filter( 'pre_site_transient_update_plugins', $transient_probe, 10, 2 );
			\add_filter( 'pre_site_transient_update_themes', $transient_probe, 10, 2 );
			\add_filter( 'wp_get_update_data', $update_data_filter, 10, 2 );
			$updates_bar = new \WP_Admin_Bar();
			\wp_admin_bar_updates_menu( $updates_bar );
			\remove_filter( 'wp_get_update_data', $update_data_filter, 10 );

			\add_filter( 'wp_get_update_data', $empty_update_filter, 10, 2 );
			$empty_updates_bar = new \WP_Admin_Bar();
			\wp_admin_bar_updates_menu( $empty_updates_bar );
			\remove_filter( 'wp_get_update_data', $empty_update_filter, 10 );
			\remove_filter( 'pre_site_transient_update_plugins', $transient_probe, 10 );
			\remove_filter( 'pre_site_transient_update_themes', $transient_probe, 10 );

			$front_toggle_bar = new \WP_Admin_Bar();
			\wp_admin_bar_sidebar_toggle( $front_toggle_bar );

			\set_current_screen( 'dashboard' );
			$admin_toggle_bar = new \WP_Admin_Bar();
			\wp_admin_bar_sidebar_toggle( $admin_toggle_bar );

			$command_without_script_bar = new \WP_Admin_Bar();
			\wp_admin_bar_command_palette_menu( $command_without_script_bar );

			$GLOBALS['wp_scripts'] = new \WP_Scripts();
			\wp_register_script( 'wp-core-commands', false, array(), null, true );
			\wp_enqueue_script( 'wp-core-commands' );
			$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit';
			$command_mac_bar = new \WP_Admin_Bar();
			\wp_admin_bar_command_palette_menu( $command_mac_bar );

			$GLOBALS['wp_scripts'] = new \WP_Scripts();
			\wp_register_script( 'wp-core-commands', false, array(), null, true );
			\wp_enqueue_script( 'wp-core-commands' );
			$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) ComponentFuzz';
			$command_default_bar = new \WP_Admin_Bar();
			\wp_admin_bar_command_palette_menu( $command_default_bar );

			unset( $GLOBALS['current_screen'] );
			$command_front_bar = new \WP_Admin_Bar();
			\wp_admin_bar_command_palette_menu( $command_front_bar );

			$site_name         = $site_bar->get_node( 'site-name' );
			$dashboard         = $site_bar->get_node( 'dashboard' );
			$plugins           = $site_bar->get_node( 'plugins' );
			$new_content       = $new_content_bar->get_node( 'new-content' );
			$new_post          = $new_content_bar->get_node( 'new-post' );
			$new_media         = $new_content_bar->get_node( 'new-media' );
			$new_link          = $new_content_bar->get_node( 'new-link' );
			$new_page          = $new_content_bar->get_node( 'new-page' );
			$new_user          = $new_content_bar->get_node( 'new-user' );
			$updates           = $updates_bar->get_node( 'updates' );
			$empty_updates     = $empty_updates_bar->get_node( 'updates' );
			$front_toggle      = $front_toggle_bar->get_node( 'menu-toggle' );
			$admin_toggle      = $admin_toggle_bar->get_node( 'menu-toggle' );
			$command_no_script = $command_without_script_bar->get_node( 'command-palette' );
			$command_mac       = $command_mac_bar->get_node( 'command-palette' );
			$command_default   = $command_default_bar->get_node( 'command-palette' );
			$command_front     = $command_front_bar->get_node( 'command-palette' );

			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter, 10 );
			\remove_filter( 'get_user_metadata', $user_meta_filter, 10 );
			\remove_filter( 'wp_get_update_data', $update_data_filter, 10 );
			\remove_filter( 'wp_get_update_data', $empty_update_filter, 10 );
			\remove_filter( 'pre_site_transient_update_plugins', $transient_probe, 10 );
			\remove_filter( 'pre_site_transient_update_themes', $transient_probe, 10 );
			foreach ( $option_filters as $hook => $filter ) {
				\remove_filter( $hook, $filter, 10 );
			}

			$option_filters_removed = true;
			foreach ( $option_filters as $hook => $filter ) {
				if ( false !== \has_filter( $hook, $filter ) ) {
					$option_filters_removed = false;
					break;
				}
			}
			$filters_removed = false === \has_filter( 'user_has_cap', $cap_filter )
				&& false === \has_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter )
				&& false === \has_filter( 'get_user_metadata', $user_meta_filter )
				&& false === \has_filter( 'wp_get_update_data', $update_data_filter )
				&& false === \has_filter( 'wp_get_update_data', $empty_update_filter )
				&& false === \has_filter( 'pre_site_transient_update_plugins', $transient_probe )
				&& false === \has_filter( 'pre_site_transient_update_themes', $transient_probe )
				&& $option_filters_removed;
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
			\remove_filter( 'pre_get_blogs_of_user', $blogs_of_user_filter, 10 );
			\remove_filter( 'get_user_metadata', $user_meta_filter, 10 );
			\remove_filter( 'wp_get_update_data', $update_data_filter, 10 );
			\remove_filter( 'wp_get_update_data', $empty_update_filter, 10 );
			\remove_filter( 'pre_site_transient_update_plugins', $transient_probe, 10 );
			\remove_filter( 'pre_site_transient_update_themes', $transient_probe, 10 );
			foreach ( $option_filters as $hook => $filter ) {
				\remove_filter( $hook, $filter, 10 );
			}
			self::restore_wpdb_runtime_state( $wpdb_snapshot );
			self::restore_state( $snapshot );
			self::restore_server( $server_snapshot );
		}

		$filters_removed = $filters_removed ?? false;
		$wpdb_restored   = self::wpdb_runtime_matches( $wpdb_snapshot );

		self::collect_failure(
			$failures,
			$site_name instanceof \stdClass
				&& 'site-name' === $site_name->id
				&& empty( $site_name->parent )
				&& \admin_url() === $site_name->href
				&& $blog_name === $site_name->title
				&& $blog_name === ( $site_name->meta['menu_title'] ?? null )
				&& $dashboard instanceof \stdClass
				&& 'site-name' === $dashboard->parent
				&& \admin_url() === $dashboard->href
				&& $plugins instanceof \stdClass
				&& 'site-name' === $plugins->parent
				&& \admin_url( 'plugins.php' ) === $plugins->href,
			'site menu callback creates front-end site-name, dashboard, and plugin nodes for the synthetic user',
			array(
				'siteName'  => $site_name ?? null,
				'dashboard' => $dashboard ?? null,
				'plugins'   => $plugins ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$new_content instanceof \stdClass
				&& \admin_url( 'post-new.php' ) === $new_content->href
				&& 'New' === ( $new_content->meta['menu_title'] ?? null )
				&& $new_post instanceof \stdClass
				&& 'new-content' === $new_post->parent
				&& \admin_url( 'post-new.php' ) === $new_post->href
				&& $new_media instanceof \stdClass
				&& \admin_url( 'media-new.php' ) === $new_media->href
				&& $new_link instanceof \stdClass
				&& \admin_url( 'link-add.php' ) === $new_link->href
				&& $new_page instanceof \stdClass
				&& \admin_url( 'post-new.php?post_type=page' ) === $new_page->href
				&& $new_user instanceof \stdClass
				&& \admin_url( 'user-new.php' ) === $new_user->href,
			'new-content callback chooses the first permitted action and creates expected built-in child nodes',
			array(
				'newContent' => $new_content ?? null,
				'newPost'    => $new_post ?? null,
				'newMedia'   => $new_media ?? null,
				'newLink'    => $new_link ?? null,
				'newPage'    => $new_page ?? null,
				'newUser'    => $new_user ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$updates instanceof \stdClass
				&& \network_admin_url( 'update-core.php' ) === $updates->href
				&& str_contains( $updates->title, '>' . \number_format_i18n( $update_total ) . '</span>' )
				&& str_contains( $updates->title, 'updates available' )
				&& null === $empty_updates
				&& 2 === count( $update_events )
				&& array() === $update_transient_events
				&& 0 === (int) ( $update_events[0]['incoming']['counts']['total'] ?? -1 ),
			'updates callback uses filtered positive totals, omits zero totals, and avoids update transient lookups when caps are denied',
			array(
				'updates'         => $updates ?? null,
				'emptyUpdates'    => $empty_updates ?? null,
				'updateEvents'    => $update_events,
				'transientEvents' => $update_transient_events,
			)
		);

		self::collect_failure(
			$failures,
			null === $front_toggle
				&& $admin_toggle instanceof \stdClass
				&& 'menu-toggle' === $admin_toggle->id
				&& '#' === $admin_toggle->href
				&& str_contains( $admin_toggle->title, '<span class="ab-icon" aria-hidden="true"></span>' )
				&& str_contains( $admin_toggle->title, '<span class="screen-reader-text">Menu</span>' ),
			'sidebar toggle callback appears only in admin context with the expected accessible title',
			array(
				'frontToggle' => $front_toggle ?? null,
				'adminToggle' => $admin_toggle ?? null,
			)
		);

		$command_meta = $command_mac instanceof \stdClass ? $command_mac->meta : array();
		self::collect_failure(
			$failures,
			null === $command_no_script
				&& null === $command_front
				&& $command_mac instanceof \stdClass
				&& $command_default instanceof \stdClass
				&& '#' === $command_mac->href
				&& 'hide-if-no-js' === ( $command_meta['class'] ?? null )
				&& 'wp.data.dispatch( "core/commands" ).open(); return false;' === ( $command_meta['onclick'] ?? null )
				&& str_contains( $command_mac->title, "\xE2\x8C\x98K" )
				&& str_contains( $command_default->title, 'Ctrl+K' )
				&& str_contains( (string) ( $command_meta['html'] ?? '' ), 'sourceURL=wp_admin_bar_command_palette_menu' ),
			'command palette callback requires admin context and enqueued core commands script, then emits shortcut and inline script metadata',
			array(
				'withoutScript' => $command_no_script ?? null,
				'front'         => $command_front ?? null,
				'mac'           => $command_mac ?? null,
				'default'       => $command_default ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed
				&& self::global_matches( $snapshot['globals']['current_user'], 'current_user' )
				&& self::global_matches( $snapshot['globals']['current_screen'], 'current_screen' )
				&& self::global_matches( $snapshot['globals']['_wp_theme_features'], '_wp_theme_features' )
				&& self::global_matches( $snapshot['globals']['wp_filter'], 'wp_filter' )
				&& self::global_matches( $snapshot['globals']['wp_scripts'], 'wp_scripts' )
				&& self::server_matches( $server_snapshot, 'HTTP_USER_AGENT' )
				&& $wpdb_restored,
			'single-site callback probe removes filters and restores user, screen, script, server, hook, and wpdb runtime state',
			array(
				'filtersRemoved' => $filters_removed,
				'currentUser'    => self::global_summary( 'current_user' ),
				'currentScreen'  => self::global_summary( 'current_screen' ),
				'wpScripts'      => self::global_summary( 'wp_scripts' ),
				'wpdbRestored'   => $wpdb_restored,
			)
		);

		return self::row(
			$ctx,
			'admin-bar.default-callbacks.single-site-node-graph',
			array() === $failures,
			array(
				'userId'   => $user_id,
				'failures' => $failures,
			)
		);
	}

	private static function render_bar( \WP_Admin_Bar $bar ): string {
		ob_start();
		try {
			$bar->render();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	private static function node_id( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return $prefix . '-' . strtolower( $ctx->identifier( 3, 10 ) ) . '-' . $ctx->int( 10, 9999 );
	}

	private static function fuzz_title( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return $prefix . ' ' . $ctx->text( 0, 48 ) . ' <b>' . $ctx->identifier( 2, 8 ) . '</b>';
	}

	private static function fuzz_href( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				$ctx->url(),
				'https://example.test/admin-bar?q=' . rawurlencode( $ctx->text( 0, 16 ) ),
				'mailto:' . $ctx->identifier( 3, 8 ) . '@example.test',
				'javascript:' . $ctx->text( 0, 24 ),
			)
		);
	}

	private static function fuzz_class( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'cfz-' . $ctx->identifier( 3, 10 ) . ' "' . $ctx->text( 0, 24 ) . "'";
	}

	private static function synthetic_current_user( \ComponentFuzz\FuzzContext $ctx ): \WP_User {
		$user_id = 880000 + $ctx->int( 1, 9999 );
		$login   = 'cfz_admin_bar_' . strtolower( $ctx->identifier( 5, 12 ) );
		$data    = (object) array(
			'ID'                  => $user_id,
			'user_login'          => $login,
			'user_pass'           => 'component-fuzz-admin-bar-pass',
			'user_nicename'       => $login,
			'user_email'          => $login . '@example.test',
			'user_url'            => 'https://example.test/admin-bar-user/' . rawurlencode( $login ),
			'user_registered'     => '2026-06-26 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
			'display_name'        => 'Admin Bar User ' . $ctx->identifier( 4, 10 ),
		);
		$user    = ( new \ReflectionClass( \WP_User::class ) )->newInstanceWithoutConstructor();

		$user->data    = $data;
		$user->ID      = $user_id;
		$user->site_id = 1;
		$user->cap_key = 'wp_capabilities';
		$user->roles   = array();
		$user->caps    = array();
		$user->allcaps = array();

		return $user;
	}

	private static function default_callback_cap_filter( array $capabilities, int $user_id ): callable {
		return static function ( array $allcaps, array $caps, array $args, \WP_User $filtered_user ) use ( $capabilities, $user_id ): array {
			unset( $caps, $args );

			if ( (int) $filtered_user->ID !== $user_id ) {
				return $allcaps;
			}

			foreach ( $capabilities as $capability ) {
				$allcaps[ $capability ] = true;
			}

			return $allcaps;
		};
	}

	private static function rendered_id( string $id ): string {
		return \esc_attr( 'wp-admin-bar-' . $id );
	}

	private static function node_registry_shape( $nodes ): array {
		if ( ! is_array( $nodes ) ) {
			return array();
		}

		$shape = array();
		foreach ( $nodes as $id => $node ) {
			if ( ! is_object( $node ) ) {
				continue;
			}
			$shape[ (string) $id ] = array(
				'id'     => $node->id ?? null,
				'parent' => $node->parent ?? null,
				'title'  => $node->title ?? null,
				'href'   => $node->href ?? null,
				'group'  => $node->group ?? null,
				'meta'   => $node->meta ?? null,
			);
		}
		ksort( $shape );
		return $shape;
	}

	private static function expected_default_admin_bar_menu_priorities(): array {
		$expected = array(
			'wp_admin_bar_my_account_menu'       => 0,
			'wp_admin_bar_my_account_item'       => 9991,
			'wp_admin_bar_recovery_mode_menu'    => 9992,
			'wp_admin_bar_search_menu'           => 9999,
			'wp_admin_bar_sidebar_toggle'        => 0,
			'wp_admin_bar_wp_menu'               => 10,
			'wp_admin_bar_my_sites_menu'         => 20,
			'wp_admin_bar_site_menu'             => 30,
			'wp_admin_bar_edit_site_menu'        => 40,
			'wp_admin_bar_customize_menu'        => 40,
			'wp_admin_bar_updates_menu'          => 50,
			'wp_admin_bar_command_palette_menu'  => 55,
			'wp_admin_bar_edit_menu'             => 80,
			'wp_admin_bar_add_secondary_groups'  => 200,
		);

		if ( ! \is_network_admin() && ! \is_user_admin() ) {
			$expected['wp_admin_bar_comments_menu']    = 60;
			$expected['wp_admin_bar_new_content_menu'] = 70;
		}

		ksort( $expected );
		return $expected;
	}

	private static function admin_bar_menu_callback_map(): array {
		$hook = $GLOBALS['wp_filter']['admin_bar_menu'] ?? null;
		if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
			return array();
		}

		$map = array();
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_string( $function ) ) {
					$map[ $function ] = (int) $priority;
				}
			}
		}

		ksort( $map );
		return $map;
	}

	private static function callback_priorities_match( array $expected, array $actual ): bool {
		foreach ( $expected as $function => $priority ) {
			if ( ( $actual[ $function ] ?? null ) !== $priority ) {
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
		array $data = array()
	): array {
		return array(
			'ok'        => $ok,
			'status'    => $ok ? 'passed' : 'failed',
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'data'      => self::describe_value( $data ),
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
				if ( $i >= 18 ) {
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
			if ( $depth < 3 && $value instanceof \stdClass ) {
				return self::describe_value( get_object_vars( $value ), $depth + 1 );
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

	private static function preview_string( string $value ): array {
		return self::describe_string( $value );
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
		return array(
			'globals' => self::snapshot_globals(
				array(
					'_wp_theme_features',
					'current_screen',
					'current_user',
					'pagenow',
					'show_admin_bar',
					'user_ID',
					'user_email',
					'user_identity',
					'user_level',
					'user_login',
					'user_url',
					'userdata',
					'wp_actions',
					'wp_admin_bar',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_query',
					'wp_scripts',
					'wp_styles',
				)
			),
		);
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

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function snapshot_server( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => array_key_exists( $name, $_SERVER ) ? $_SERVER[ $name ] : null,
			);
		}
		return $snapshot;
	}

	private static function restore_server( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}
	}

	private static function global_matches( array $entry, string $name ): bool {
		if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
			return false;
		}
		if ( ! $entry['exists'] ) {
			return true;
		}
		return $entry['value'] == $GLOBALS[ $name ];
	}

	private static function server_matches( array $snapshot, string $name ): bool {
		$entry = $snapshot[ $name ];
		if ( $entry['exists'] !== array_key_exists( $name, $_SERVER ) ) {
			return false;
		}
		if ( ! $entry['exists'] ) {
			return true;
		}
		return $entry['value'] === $_SERVER[ $name ];
	}

	private static function global_summary( string $name ): array {
		return array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => array_key_exists( $name, $GLOBALS ) ? self::describe_value( $GLOBALS[ $name ] ) : null,
		);
	}

	private static function wpdb_runtime_state(): ?array {
		if (
			! isset( $GLOBALS['wpdb'] )
			|| ! is_object( $GLOBALS['wpdb'] )
			|| ! method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_runtime_state' )
		) {
			return null;
		}

		return $GLOBALS['wpdb']->component_fuzz_get_runtime_state();
	}

	private static function wpdb_runtime_matches( ?array $snapshot ): bool {
		return $snapshot === self::wpdb_runtime_state();
	}

	private static function restore_wpdb_runtime_state( ?array $snapshot ): void {
		if (
			null === $snapshot
			|| ! isset( $GLOBALS['wpdb'] )
			|| ! is_object( $GLOBALS['wpdb'] )
			|| ! method_exists( $GLOBALS['wpdb'], 'component_fuzz_restore_runtime_state' )
		) {
			return;
		}

		$GLOBALS['wpdb']->component_fuzz_restore_runtime_state( $snapshot );
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
