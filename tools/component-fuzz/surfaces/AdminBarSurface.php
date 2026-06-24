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
				self::check_show_admin_bar_filters( $ctx->fork( 'show-admin-bar-filters' ) ),
				self::check_default_menu_hook_registration( $ctx->fork( 'default-menu-hooks' ) ),
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

		foreach ( array( 'WP_Admin_Bar' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_action',
				'did_action',
				'esc_attr',
				'esc_attr_e',
				'esc_js',
				'esc_url',
				'has_filter',
				'is_admin',
				'is_admin_bar_showing',
				'is_embed',
				'is_network_admin',
				'is_user_admin',
				'is_user_logged_in',
				'remove_action',
				'remove_filter',
				'sanitize_title',
				'show_admin_bar',
				'wp_admin_bar_render',
				'wp_is_json_request',
				'wp_is_mobile',
				'wp_parse_args',
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
