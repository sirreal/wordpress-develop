<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB WordPress admin workflow APIs.
 */
final class AdminWorkflowsSurface {
	public const NAME = 'admin-workflows';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-workflows.bootstrap-apis-available',
					'Required admin workflow APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot        = self::snapshot_state();
		$server_snapshot = self::snapshot_server( array( 'HTTP_HOST', 'REQUEST_URI', 'PHP_SELF' ) );
		$rows            = array();

		try {
			$rows[] = self::check_menu_globals( $ctx->fork( 'menu-globals' ) );
			$rows[] = self::check_synthetic_list_table( $ctx->fork( 'list-table' ) );
			$rows[] = self::check_referer_helpers( $ctx->fork( 'referer-helpers' ) );
			$rows[] = self::skipped_core_list_table_subclasses( $ctx->fork( 'core-list-table-skips' ) );
			$rows[] = self::skipped_exiting_ajax_wrappers( $ctx->fork( 'ajax-wrapper-skips' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'admin-workflows.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
			self::restore_server( $server_snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_List_Table', 'WP_Screen' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_management_page',
				'add_menu_page',
				'add_query_arg',
				'add_submenu_page',
				'admin_url',
				'check_admin_referer',
				'check_ajax_referer',
				'convert_to_screen',
				'current_user_can',
				'esc_attr',
				'esc_html',
				'esc_url',
				'get_admin_page_parent',
				'get_admin_page_title',
				'get_column_headers',
				'get_hidden_columns',
				'get_plugin_page_hook',
				'get_plugin_page_hookname',
				'has_action',
				'has_filter',
				'menu_page_url',
				'plugin_basename',
				'remove_action',
				'remove_filter',
				'remove_menu_page',
				'remove_query_arg',
				'remove_submenu_page',
				'sanitize_key',
				'sanitize_title',
				'set_url_scheme',
				'submit_button',
				'wp_create_nonce',
				'wp_nonce_field',
				'wp_nonce_url',
				'wp_strip_all_tags',
				'wp_verify_nonce',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_menu_globals( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$local_snapshot = self::snapshot_globals( self::menu_global_names() );
		$capability     = self::capability( $ctx->fork( 'capability' ), 'manage' );
		$denied_cap     = self::capability( $ctx->fork( 'denied-capability' ), 'denied' );
		$top_slug       = self::menu_slug( $ctx->fork( 'top-slug' ), 'top' ) . '.php';
		$second_slug    = self::menu_slug( $ctx->fork( 'second-slug' ), 'second' ) . '.php';
		$sub_slug       = self::menu_slug( $ctx->fork( 'sub-slug' ), 'sub' );
		$tools_slug     = self::menu_slug( $ctx->fork( 'tools-slug' ), 'tools' );
		$denied_slug    = self::menu_slug( $ctx->fork( 'denied-slug' ), 'denied' );
		$alias_slug     = self::menu_slug( $ctx->fork( 'alias-slug' ), 'alias' ) . '.php';
		$top_title      = 'Top Page ' . self::hostile_label( $ctx->fork( 'top-title' ) );
		$top_menu_title = 'Top Menu ' . self::hostile_label( $ctx->fork( 'top-menu-title' ) );
		$sub_title      = 'Sub Page ' . self::hostile_label( $ctx->fork( 'sub-title' ) );
		$sub_menu_title = 'Sub Menu ' . self::hostile_label( $ctx->fork( 'sub-menu-title' ) );
		$callback_calls = 0;
		$callback       = static function () use ( &$callback_calls ): void {
			++$callback_calls;
			echo '<span class="cfz-menu-callback">callback</span>';
		};
		$result         = array();
		$restored       = false;

		self::reset_menu_globals();

		try {
			$result = self::with_capabilities(
				array( $capability ),
				static function () use (
					$alias_slug,
					$callback,
					$capability,
					$denied_cap,
					$denied_slug,
					$second_slug,
					$sub_menu_title,
					$sub_slug,
					$sub_title,
					$tools_slug,
					$top_menu_title,
					$top_slug,
					$top_title
				): array {
					global $admin_page_hooks, $menu, $submenu, $_parent_pages, $_registered_pages,
						$_wp_real_parent_file, $_wp_submenu_nopriv;

					$top_hook = \add_menu_page(
						$top_title,
						$top_menu_title,
						$capability,
						$top_slug,
						$callback,
						'dashicons-admin-generic',
						65
					);
					$second_hook = \add_menu_page(
						'Second Page',
						'Second Menu',
						$capability,
						$second_slug,
						$callback,
						'none',
						65
					);

					$_wp_real_parent_file[ $alias_slug ] = $top_slug;

					$sub_hook = \add_submenu_page(
						$alias_slug,
						$sub_title,
						$sub_menu_title,
						$capability,
						$sub_slug,
						$callback,
						1
					);
					$denied_hook = \add_submenu_page(
						$top_slug,
						'Denied Page',
						'Denied Menu',
						$denied_cap,
						$denied_slug,
						$callback,
						2
					);
					$tools_hook  = \add_management_page(
						'Tools Page',
						'Tools Menu',
						$capability,
						$tools_slug,
						$callback,
						3
					);

					$sub_url = \menu_page_url( $sub_slug, false );
					ob_start();
					$tools_url = \menu_page_url( $tools_slug, true );
					$tools_url_display = (string) ob_get_clean();

					$GLOBALS['plugin_page'] = $sub_slug;
					$GLOBALS['pagenow']     = 'admin.php';
					$GLOBALS['parent_file'] = '';
					$GLOBALS['typenow']     = '';
					$GLOBALS['title']       = '';

					$resolved_parent = \get_admin_page_parent();
					$resolved_title  = \get_admin_page_title();
					$hook_lookup     = \get_plugin_page_hook( $sub_slug, $top_slug );

					$top_entry        = self::find_menu_entry( $top_slug );
					$second_entry     = self::find_menu_entry( $second_slug );
					$top_position     = self::find_menu_position( $top_slug );
					$second_position  = self::find_menu_position( $second_slug );
					$sub_entries      = $submenu[ $top_slug ] ?? array();
					$sub_entry        = self::find_submenu_entry( $top_slug, $sub_slug );
					$auto_parent      = $sub_entries[0] ?? null;
					$action_priorites = array(
						'top'    => \has_action( $top_hook, $callback ),
						'second' => \has_action( $second_hook, $callback ),
						'sub'    => \has_action( $sub_hook, $callback ),
						'tools'  => \has_action( $tools_hook, $callback ),
					);

					\remove_action( $top_hook, $callback, 10 );
					\remove_action( $second_hook, $callback, 10 );
					\remove_action( $sub_hook, $callback, 10 );
					\remove_action( $tools_hook, $callback, 10 );

					$action_cleanup = array(
						'top'    => \has_action( $top_hook, $callback ),
						'second' => \has_action( $second_hook, $callback ),
						'sub'    => \has_action( $sub_hook, $callback ),
						'tools'  => \has_action( $tools_hook, $callback ),
					);

					$removed_sub     = \remove_submenu_page( $top_slug, $sub_slug );
					$missing_sub     = \remove_submenu_page( $top_slug, $sub_slug );
					$removed_top     = \remove_menu_page( $top_slug );
					$missing_top     = \remove_menu_page( $top_slug );
					$tools_alt_hook  = \get_plugin_page_hookname( $tools_slug, 'edit.php' );
					$expected_values = array(
						'denied_hook' => \get_plugin_page_hookname( $denied_slug, $top_slug ),
						'top_hook'    => \get_plugin_page_hookname( $top_slug, '' ),
						'second_hook' => \get_plugin_page_hookname( $second_slug, '' ),
						'sub_hook'    => \get_plugin_page_hookname( $sub_slug, $top_slug ),
						'tools_hook'  => \get_plugin_page_hookname( $tools_slug, 'tools.php' ),
					);

					return compact(
						'action_cleanup',
						'action_priorites',
						'admin_page_hooks',
						'auto_parent',
						'denied_hook',
						'denied_slug',
						'expected_values',
						'hook_lookup',
						'menu',
						'missing_sub',
						'missing_top',
						'removed_sub',
						'removed_top',
						'resolved_parent',
						'resolved_title',
						'second_entry',
						'second_hook',
						'second_position',
						'sub_entry',
						'sub_hook',
						'sub_url',
						'submenu',
						'tools_alt_hook',
						'tools_hook',
						'tools_slug',
						'tools_url',
						'tools_url_display',
						'top_entry',
						'top_hook',
						'top_position',
						'_parent_pages',
						'_registered_pages',
						'_wp_submenu_nopriv'
					);
				}
			);
		} finally {
			self::restore_globals( $local_snapshot );
			$restored = self::globals_match( $local_snapshot, self::menu_global_names() );
		}

		self::collect_failure(
			$failures,
			isset( $result['top_hook'], $result['sub_hook'], $result['second_hook'], $result['tools_hook'] )
				&& $result['top_hook'] === ( $result['expected_values']['top_hook'] ?? null )
				&& $result['second_hook'] === ( $result['expected_values']['second_hook'] ?? null )
				&& $result['sub_hook'] === ( $result['expected_values']['sub_hook'] ?? null )
				&& $result['tools_hook'] === ( $result['expected_values']['tools_hook'] ?? null )
				&& $result['top_hook'] !== $result['second_hook']
				&& $result['sub_hook'] === $result['hook_lookup'],
			'menu helpers generate deterministic distinct hook suffixes',
			$result
		);

		self::collect_failure(
			$failures,
			is_array( $result['top_entry'] ?? null )
				&& is_array( $result['second_entry'] ?? null )
				&& $top_menu_title === ( $result['top_entry'][0] ?? null )
				&& $capability === ( $result['top_entry'][1] ?? null )
				&& $top_slug === ( $result['top_entry'][2] ?? null )
				&& $top_title === ( $result['top_entry'][3] ?? null )
				&& str_contains( (string) ( $result['top_entry'][4] ?? '' ), (string) $result['top_hook'] )
				&& false !== $result['top_position']
				&& false !== $result['second_position']
				&& $result['top_position'] !== $result['second_position']
				&& (float) $result['second_position'] > (float) $result['top_position'],
			'top-level menu globals preserve title/capability fields and resolve position collisions monotonically',
			$result
		);

		self::collect_failure(
			$failures,
			is_array( $result['sub_entry'] ?? null )
				&& is_array( $result['auto_parent'] ?? null )
				&& $top_slug === ( $result['auto_parent'][2] ?? null )
				&& $sub_menu_title === ( $result['sub_entry'][0] ?? null )
				&& $capability === ( $result['sub_entry'][1] ?? null )
				&& $sub_slug === ( $result['sub_entry'][2] ?? null )
				&& $sub_title === ( $result['sub_entry'][3] ?? null )
				&& $top_slug === ( $result['_parent_pages'][ $sub_slug ] ?? null )
				&& false === ( $result['_parent_pages'][ $top_slug ] ?? null )
				&& true === ( $result['_registered_pages'][ $result['sub_hook'] ] ?? null ),
			'submenu globals normalize real parents, add parent back-links, and register page hooks',
			$result
		);

		self::collect_failure(
			$failures,
			false === ( $result['denied_hook'] ?? null )
				&& true === ( $result['_wp_submenu_nopriv'][ $top_slug ][ $denied_slug ] ?? null )
				&& ! isset( $result['_registered_pages'][ $result['expected_values']['denied_hook'] ?? '' ] ),
			'submenu capability failures mark no-priv globals without registering pages',
			$result
		);

		self::collect_failure(
			$failures,
			$top_slug === ( $result['resolved_parent'] ?? null )
				&& $sub_title === ( $result['resolved_title'] ?? null )
				&& is_string( $result['sub_url'] ?? null )
				&& str_contains( $result['sub_url'], 'admin.php?page=' . $sub_slug )
				&& is_string( $result['tools_url'] ?? null )
				&& str_contains( $result['tools_url'], 'tools.php?page=' . $tools_slug )
				&& $result['tools_url'] === ( $result['tools_url_display'] ?? null )
				&& true === ( $result['_registered_pages'][ $result['tools_alt_hook'] ] ?? null )
				&& self::html_has_no_unsafe_raw_markup( $result['sub_url'] . $result['tools_url'] ),
			'menu page URLs, current parent, and current title resolve through normalized parent files',
			$result
		);

		self::collect_failure(
			$failures,
			is_array( $result['removed_sub'] ?? null )
				&& $sub_slug === ( $result['removed_sub'][2] ?? null )
				&& false === ( $result['missing_sub'] ?? null )
				&& is_array( $result['removed_top'] ?? null )
				&& $top_slug === ( $result['removed_top'][2] ?? null )
				&& false === ( $result['missing_top'] ?? null )
				&& self::all_values( $result['action_priorites'] ?? array(), 10 )
				&& self::all_values( $result['action_cleanup'] ?? array(), false )
				&& 0 === $callback_calls,
			'remove_menu_page(), remove_submenu_page(), and callback action cleanup are deterministic',
			$result
		);

		self::collect_failure(
			$failures,
			$restored,
			'admin menu globals are restored after the generated case',
			array( 'restored' => $restored )
		);

		return self::row(
			$ctx,
			'admin-workflows.menu.globals-hooks-urls-removal',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'slugs'    => compact( 'top_slug', 'second_slug', 'sub_slug', 'tools_slug', 'denied_slug', 'alias_slug' ),
			)
		);
	}

	private static function check_synthetic_list_table( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$local_snapshot = self::snapshot_globals(
			array(
				'_COOKIE',
				'_GET',
				'_POST',
				'_REQUEST',
				'current_screen',
				'hook_suffix',
				'pagenow',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
			)
		);
		$server_snapshot = self::snapshot_server( array( 'HTTP_HOST', 'REQUEST_URI', 'PHP_SELF' ) );
		$screen_id       = self::screen_id( $ctx->fork( 'screen' ) );
		$screen          = \convert_to_screen( $screen_id );
		$hidden_column   = 'status';
		$primary_column  = 'title';
		$hostile_label   = self::hostile_label( $ctx->fork( 'labels' ) );
		$columns         = array(
			'cb'     => '<span class="screen-reader-text">' . \esc_html( 'Select ' . $hostile_label ) . '</span>',
			'title'  => \esc_html( 'Title ' . $hostile_label ),
			'status' => \esc_html( 'Status ' . $hostile_label ),
			'notes'  => \esc_html( 'Notes ' . $hostile_label ),
		);
		$sortable        = array(
			'title'  => array( 'cfz_title', false, 'Title abbr "' . $ctx->identifier( 3, 8 ), 'Title order text', 'asc' ),
			'status' => array( 'cfz_status', 'desc' ),
		);
		$bulk_actions    = array(
			'trash'                    => \esc_html( 'Trash ' . $hostile_label ),
			'Change State ' . $ctx->identifier( 3, 8 ) => array(
				'feature' => \esc_html( 'Feature ' . $hostile_label ),
				'archive' => \esc_html( 'Archive ' . $hostile_label ),
			),
		);
		$item_id         = 'cfz-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		$item_title      = 'Item ' . self::hostile_label( $ctx->fork( 'item-title' ) );
		$views           = array(
			'all'      => self::view_link( '/wp-admin/admin.php?page=' . $screen_id . '&view=all', 'All ' . $hostile_label, true ),
			'featured' => self::view_link( '/wp-admin/admin.php?page=' . $screen_id . '&view=featured', 'Featured ' . $hostile_label, false ),
		);
		$config          = array(
			'bulk_actions' => $bulk_actions,
			'columns'      => $columns,
			'extra_label'  => 'Filter ' . $hostile_label,
			'items'        => array(
				array(
					'delete_url' => \admin_url( 'admin.php?page=' . $screen_id . '&action=delete&item=' . rawurlencode( $item_id ) ),
					'edit_url'   => \admin_url( 'admin.php?page=' . $screen_id . '&action=edit&item=' . rawurlencode( $item_id ) ),
					'id'         => $item_id,
					'notes'      => 'Note ' . self::hostile_label( $ctx->fork( 'notes' ) ),
					'status'     => 'Draft ' . self::hostile_label( $ctx->fork( 'status' ) ),
					'title'      => $item_title,
					'url'        => 'https://example.test/admin-workflows/?q=' . rawurlencode( $hostile_label ),
				),
				array(
					'delete_url' => \admin_url( 'admin.php?page=' . $screen_id . '&action=delete&item=secondary' ),
					'edit_url'   => \admin_url( 'admin.php?page=' . $screen_id . '&action=edit&item=secondary' ),
					'id'         => $item_id . '-secondary',
					'notes'      => 'Secondary',
					'status'     => 'Published',
					'title'      => 'Secondary ' . $ctx->identifier( 3, 8 ),
					'url'        => 'https://example.test/admin-workflows/secondary/',
				),
			),
			'pagination'   => array(
				'per_page'    => 2,
				'total_items' => 5,
			),
			'plural'       => 'cfz_items',
			'primary'      => $primary_column,
			'screen'       => $screen,
			'singular'     => 'cfz_item',
			'sortable'     => $sortable,
			'views'        => $views,
		);
		$table           = null;
		$hidden_filter   = static function ( array $hidden, \WP_Screen $filter_screen ) use ( $hidden_column, $screen ): array {
			if ( $filter_screen->id === $screen->id ) {
				return array( $hidden_column );
			}

			return $hidden;
		};
		$sortable_filter = static function ( array $sortable_columns ) use ( $screen ): array {
			if ( $screen instanceof \WP_Screen ) {
				$sortable_columns['notes'] = array( 'cfz_notes', false, 'Notes', 'Notes order text', false );
			}

			return $sortable_columns;
		};
		$primary_filter  = static function ( string $default, string $context ) use ( $primary_column, $screen ): string {
			if ( $context === $screen->id ) {
				return $primary_column;
			}

			return $default;
		};
		$views_filter    = static function ( array $views_arg ) use ( $screen_id ): array {
			$views_arg['mine'] = self::view_link( '/wp-admin/admin.php?page=' . $screen_id . '&view=mine', 'Mine <script>alert(1)</script>', false );
			return $views_arg;
		};
		$bulk_filter     = static function ( array $actions ): array {
			$actions['export'] = \esc_html( 'Export <script>alert(1)</script>' );
			return $actions;
		};
		$result          = array();
		$filters_removed = false;
		$restored        = false;

		$_GET     = array(
			'order'   => 'asc',
			'orderby' => 'cfz_title',
			'page'    => $screen_id,
			'paged'   => 2,
		);
		$_POST    = array();
		$_REQUEST = $_GET;

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['PHP_SELF']    = '/wp-admin/admin.php';
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . rawurlencode( $screen_id )
			. '&orderby=cfz_title&order=asc'
			. '&bad=%22%3E%3Cscript%3Ealert(1)%3C/script%3E'
			. '&paged=2';

		\add_filter( 'hidden_columns', $hidden_filter, 10, 3 );
		\add_filter( "manage_{$screen->id}_sortable_columns", $sortable_filter, 10, 1 );
		\add_filter( 'list_table_primary_column', $primary_filter, 10, 2 );
		\add_filter( "views_{$screen->id}", $views_filter, 10, 1 );
		\add_filter( "bulk_actions-{$screen->id}", $bulk_filter, 10, 1 );

		try {
			$table = self::new_synthetic_list_table( $config );
			$table->prepare_items();

			$column_info    = $table->get_column_info();
			$column_count   = $table->get_column_count();
			$page_number    = $table->get_pagenum();
			$total_pages    = $table->get_pagination_arg( 'total_pages' );
			$total_items    = $table->get_pagination_arg( 'total_items' );
			$per_page       = $table->get_pagination_arg( 'per_page' );
			$current_action = $table->current_action();

			ob_start();
			$table->views();
			$views_html = (string) ob_get_clean();

			ob_start();
			$table->display_tablenav( 'top' );
			$tablenav_html = (string) ob_get_clean();

			ob_start();
			$table->display();
			$display_html = (string) ob_get_clean();

			$result = compact(
				'column_count',
				'column_info',
				'current_action',
				'display_html',
				'page_number',
				'per_page',
				'tablenav_html',
				'total_items',
				'total_pages',
				'views_html'
			);
		} finally {
			if ( $table instanceof \WP_List_Table ) {
				\remove_filter( "manage_{$screen->id}_columns", array( $table, 'get_columns' ), 0 );
			}
			\remove_filter( 'hidden_columns', $hidden_filter, 10 );
			\remove_filter( "manage_{$screen->id}_sortable_columns", $sortable_filter, 10 );
			\remove_filter( 'list_table_primary_column', $primary_filter, 10 );
			\remove_filter( "views_{$screen->id}", $views_filter, 10 );
			\remove_filter( "bulk_actions-{$screen->id}", $bulk_filter, 10 );

			$filters_removed = false === \has_filter( 'hidden_columns', $hidden_filter )
				&& false === \has_filter( "manage_{$screen->id}_sortable_columns", $sortable_filter )
				&& false === \has_filter( 'list_table_primary_column', $primary_filter )
				&& false === \has_filter( "views_{$screen->id}", $views_filter )
				&& false === \has_filter( "bulk_actions-{$screen->id}", $bulk_filter )
				&& ( ! $table instanceof \WP_List_Table || false === \has_filter( "manage_{$screen->id}_columns", array( $table, 'get_columns' ) ) );

			self::restore_server( $server_snapshot );
			self::restore_globals( $local_snapshot );
			$restored = self::globals_match(
				$local_snapshot,
				array( '_COOKIE', '_GET', '_POST', '_REQUEST', 'current_screen', 'hook_suffix', 'pagenow' )
			);
		}

		$column_info = $result['column_info'] ?? array();
		$columns_out = $column_info[0] ?? array();
		$hidden_out  = $column_info[1] ?? array();
		$sortable_out = $column_info[2] ?? array();
		$primary_out = $column_info[3] ?? null;
		$display_html = (string) ( $result['display_html'] ?? '' );
		$tablenav_html = (string) ( $result['tablenav_html'] ?? '' );
		$views_html = (string) ( $result['views_html'] ?? '' );

		self::collect_failure(
			$failures,
			$columns === $columns_out
				&& array( $hidden_column ) === $hidden_out
				&& $primary_column === $primary_out
				&& isset( $sortable_out['title'], $sortable_out['status'], $sortable_out['notes'] )
				&& 'cfz_title' === ( $sortable_out['title'][0] ?? null )
				&& false === ( $sortable_out['title'][1] ?? null )
				&& str_starts_with( (string) ( $sortable_out['title'][2] ?? '' ), 'Title abbr "' )
				&& 'Title order text' === ( $sortable_out['title'][3] ?? null )
				&& 'asc' === ( $sortable_out['title'][4] ?? null )
				&& 'cfz_status' === ( $sortable_out['status'][0] ?? null )
				&& 'desc' === ( $sortable_out['status'][1] ?? null )
				&& 'cfz_notes' === ( $sortable_out['notes'][0] ?? null ),
			'list table column info is populated',
			array(
				'columnInfo' => $column_info,
				'expected'   => array( $columns, array( $hidden_column ), $sortable, $primary_column ),
			)
		);

		self::collect_failure(
			$failures,
			3 === ( $result['total_pages'] ?? null )
				&& 5 === ( $result['total_items'] ?? null )
				&& 2 === ( $result['per_page'] ?? null )
				&& 2 === ( $result['page_number'] ?? null )
				&& 3 === ( $result['column_count'] ?? null )
				&& false === ( $result['current_action'] ?? null ),
			'list table pagination args, current page, column counts, and current action are deterministic',
			$result
		);

		self::collect_failure(
			$failures,
			str_contains( $display_html, 'wp-list-table' )
				&& str_contains( $display_html, 'column-status hidden' )
				&& str_contains( $display_html, 'aria-sort="ascending"' )
				&& str_contains( $display_html, 'row-actions visible' )
				&& str_contains( $display_html, 'name="cfz_item[]"' )
				&& str_contains( $display_html, 'data-wp-lists=\'list:cfz_item\'' )
				&& str_contains( $display_html, 'bulk-action-selector-top' )
				&& str_contains( $display_html, 'bulk-action-selector-bottom' )
				&& str_contains( $display_html, 'name="action2"' )
				&& str_contains( $display_html, 'name="_wpnonce"' ),
			'list table display renders rows, hidden/sortable headers, row actions, bulk controls, and nonces',
			array(
				'display'  => self::describe_string( $display_html ),
				'tablenav' => self::describe_string( $tablenav_html ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $views_html, "class='subsubsub'" )
				&& str_contains( $views_html, 'aria-current="page"' )
				&& str_contains( $views_html, 'view=mine' )
				&& str_contains( $tablenav_html, 'class="cfz-extra-filter"' )
				&& str_contains( $tablenav_html, 'name="_wpnonce"' )
				&& self::html_has_no_unsafe_raw_markup( $views_html . $tablenav_html . $display_html ),
			'views and display_tablenav output are escaped for generated labels and URLs',
			array(
				'views'    => self::describe_string( $views_html ),
				'tablenav' => self::describe_string( $tablenav_html ),
				'display'  => self::describe_string( $display_html ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed && $restored,
			'list table filters, request globals, screen globals, and server metadata are restored',
			array(
				'filtersRemoved' => $filters_removed,
				'restored'       => $restored,
			)
		);

		return self::row(
			$ctx,
			'admin-workflows.list-table.synthetic-rendering',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screen'   => $screen->id,
			)
		);
	}

	private static function check_referer_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$local_snapshot = self::snapshot_globals(
			array(
				'_GET',
				'_POST',
				'_REQUEST',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
			)
		);
		$action         = 'cfz-admin-' . substr( hash( 'sha256', (string) $ctx->seed() ), 0, 12 );
		$ajax_action    = $action . '-ajax';
		$query_arg      = 'cfz_nonce_' . \sanitize_key( $ctx->identifier( 3, 8 ) );
		$admin_nonce    = \wp_create_nonce( $action );
		$ajax_nonce     = \wp_create_nonce( $ajax_action );
		$admin_calls    = array();
		$ajax_calls     = array();
		$admin_listener = static function ( $seen_action, $result ) use ( &$admin_calls ): void {
			$admin_calls[] = array(
				'action' => $seen_action,
				'result' => $result,
			);
		};
		$ajax_listener  = static function ( $seen_action, $result ) use ( &$ajax_calls ): void {
			$ajax_calls[] = array(
				'action' => $seen_action,
				'result' => $result,
			);
		};
		$result         = array();
		$actions_removed = false;
		$restored       = false;

		\add_action( 'check_admin_referer', $admin_listener, 10, 2 );
		\add_action( 'check_ajax_referer', $ajax_listener, 10, 2 );

		try {
			$_GET     = array( $query_arg => $admin_nonce );
			$_POST    = array();
			$_REQUEST = $_GET;

			$admin_result = \check_admin_referer( $action, $query_arg );

			$_GET     = array();
			$_POST    = array( 'nonce' => $ajax_nonce );
			$_REQUEST = $_POST;

			$ajax_result = \check_ajax_referer( $ajax_action, 'nonce', false );

			$_GET     = array();
			$_POST    = array( 'nonce' => 'not-a-valid-nonce' );
			$_REQUEST = $_POST;

			$invalid_ajax_result = \check_ajax_referer( $ajax_action, 'nonce', false );

			$nonce_target_url = \admin_url(
				'admin-post.php?action=' . rawurlencode( $action )
				. '&label=' . rawurlencode( self::hostile_label( $ctx->fork( 'url-label' ) ) )
			);
			$nonce_url        = \wp_nonce_url( $nonce_target_url, $action, $query_arg );

			$result = compact(
				'admin_calls',
				'admin_result',
				'ajax_calls',
				'ajax_result',
				'invalid_ajax_result',
				'nonce_url',
				'query_arg'
			);
		} finally {
			\remove_action( 'check_admin_referer', $admin_listener, 10 );
			\remove_action( 'check_ajax_referer', $ajax_listener, 10 );
			$actions_removed = false === \has_action( 'check_admin_referer', $admin_listener )
				&& false === \has_action( 'check_ajax_referer', $ajax_listener );
			self::restore_globals( $local_snapshot );
			$restored = self::globals_match( $local_snapshot, array( '_GET', '_POST', '_REQUEST' ) );
		}

		self::collect_failure(
			$failures,
			in_array( $result['admin_result'] ?? null, array( 1, 2 ), true )
				&& in_array( $result['ajax_result'] ?? null, array( 1, 2 ), true )
				&& false === ( $result['invalid_ajax_result'] ?? null )
				&& array( $action ) === array_column( $result['admin_calls'] ?? array(), 'action' )
				&& array( $ajax_action, $ajax_action ) === array_column( $result['ajax_calls'] ?? array(), 'action' ),
			'admin and ajax referer helpers verify valid nonces and return false without exiting when stop is disabled',
			$result
		);

		self::collect_failure(
			$failures,
			is_string( $result['nonce_url'] ?? null )
				&& str_contains( $result['nonce_url'], 'admin-post.php' )
				&& str_contains( $result['nonce_url'], $query_arg . '=' )
				&& self::html_has_no_unsafe_raw_markup( $result['nonce_url'] ),
			'admin-post nonce URLs include escaped custom nonce names and generated query labels',
			$result
		);

		self::collect_failure(
			$failures,
			$actions_removed && $restored,
			'referer helper actions and request globals are restored',
			array(
				'actionsRemoved' => $actions_removed,
				'restored'       => $restored,
			)
		);

		return self::row(
			$ctx,
			'admin-workflows.referers.nonce-admin-post-ajax',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function skipped_core_list_table_subclasses( \ComponentFuzz\FuzzContext $ctx ): array {
		return $ctx->skip(
			'admin-workflows.list-table.core-subclasses-skipped',
			'Core WP_*_List_Table subclasses are not instantiated here because their prepare_items(), '
				. 'column, and row-action paths query posts, users, comments, terms, plugins, themes, '
				. 'privacy requests, update transients, or multisite tables unless each class is heavily short-circuited.',
			array(
				'covered_instead' => 'A tiny WP_List_Table subclass exercises base rendering with synthetic items.',
				'skipped_classes' => array(
					'WP_Posts_List_Table',
					'WP_Media_List_Table',
					'WP_Terms_List_Table',
					'WP_Users_List_Table',
					'WP_Comments_List_Table',
					'WP_Plugins_List_Table',
					'WP_Themes_List_Table',
					'WP_MS_Sites_List_Table',
					'WP_Privacy_Requests_Table',
				),
			)
		);
	}

	private static function skipped_exiting_ajax_wrappers( \ComponentFuzz\FuzzContext $ctx ): array {
		return $ctx->skip(
			'admin-workflows.ajax.exiting-wrappers-skipped',
			'wp_ajax_* wrappers and wp_send_json()/wp_die() response paths are skipped in-process because '
				. 'many terminate execution; this surface covers the shared nonce and referer helpers only '
				. 'where stop=false or valid nonces avoid exits.',
			array(
				'covered_instead' => array(
					'check_admin_referer() with a valid generated nonce',
					'check_ajax_referer() with a valid generated nonce',
					'check_ajax_referer() invalid nonce with stop=false',
					'wp_nonce_url() for admin-post.php-style URLs',
				),
			)
		);
	}

	private static function new_synthetic_list_table( array $config ): \WP_List_Table {
		return new class( $config ) extends \WP_List_Table {
			private array $config;

			public function __construct( array $config ) {
				$this->config = $config;
				parent::__construct(
					array(
						'ajax'     => false,
						'plural'   => $config['plural'],
						'screen'   => $config['screen'],
						'singular' => $config['singular'],
					)
				);
			}

			public function get_columns(): array {
				return $this->config['columns'];
			}

			public function prepare_items(): void {
				$this->items = $this->config['items'];
				$this->set_pagination_args( $this->config['pagination'] );
			}

			protected function get_sortable_columns(): array {
				return $this->config['sortable'];
			}

			protected function get_bulk_actions(): array {
				return $this->config['bulk_actions'];
			}

			protected function get_views(): array {
				return $this->config['views'];
			}

			protected function column_cb( $item ): string {
				return '<input type="checkbox" name="cfz_item[]" value="' . \esc_attr( $item['id'] ) . '" />';
			}

			public function column_title( $item ): string {
				return '<strong><a href="' . \esc_url( $item['url'] ) . '">' . \esc_html( $item['title'] ) . '</a></strong>';
			}

			public function column_status( $item ): string {
				return '<span class="cfz-status">' . \esc_html( $item['status'] ) . '</span>';
			}

			protected function column_default( $item, $column_name ): string {
				return \esc_html( (string) ( $item[ $column_name ] ?? '' ) );
			}

			protected function handle_row_actions( $item, $column_name, $primary ): string {
				if ( $column_name !== $primary ) {
					return '';
				}

				$actions = array(
					'edit'   => '<a href="' . \esc_url( $item['edit_url'] ) . '">' . \esc_html__( 'Edit' ) . '</a>',
					'delete' => '<a href="'
						. \esc_url( \wp_nonce_url( $item['delete_url'], 'delete-cfz_' . $item['id'], '_wpnonce' ) )
						. '">' . \esc_html__( 'Delete' ) . '</a>',
				);

				return $this->row_actions( $actions, true );
			}

			protected function extra_tablenav( $which ): void {
				echo '<div class="alignleft actions cfz-extra">';
				echo '<label class="screen-reader-text" for="cfz-extra-filter-' . \esc_attr( $which ) . '">';
				echo \esc_html( $this->config['extra_label'] );
				echo '</label>';
				echo '<input class="cfz-extra-filter" id="cfz-extra-filter-' . \esc_attr( $which ) . '" ';
				echo 'name="cfz_extra_' . \esc_attr( $which ) . '" value="' . \esc_attr( $this->config['extra_label'] ) . '" />';
				echo '</div>';
			}
		};
	}

	private static function with_capabilities( array $capabilities, callable $callback ) {
		$cap_filter = static function ( array $allcaps ) use ( $capabilities ): array {
			foreach ( $capabilities as $capability ) {
				$allcaps[ $capability ] = true;
			}

			return $allcaps;
		};

		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		try {
			return $callback();
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}
	}

	private static function reset_menu_globals(): void {
		$GLOBALS['menu']                 = array();
		$GLOBALS['submenu']              = array();
		$GLOBALS['admin_page_hooks']     = array();
		$GLOBALS['_registered_pages']    = array();
		$GLOBALS['_parent_pages']        = array();
		$GLOBALS['_wp_real_parent_file'] = array();
		$GLOBALS['_wp_menu_nopriv']      = array();
		$GLOBALS['_wp_submenu_nopriv']   = array();
		$GLOBALS['parent_file']          = '';
		$GLOBALS['plugin_page']          = null;
		$GLOBALS['pagenow']              = 'admin.php';
		$GLOBALS['typenow']              = '';
		$GLOBALS['title']                = '';
	}

	private static function menu_global_names(): array {
		return array(
			'_GET',
			'_POST',
			'_REQUEST',
			'admin_page_hooks',
			'current_user',
			'hook_suffix',
			'menu',
			'pagenow',
			'parent_file',
			'plugin_page',
			'submenu',
			'title',
			'typenow',
			'wp_actions',
			'wp_current_filter',
			'wp_filter',
			'wp_filters',
			'_parent_pages',
			'_registered_pages',
			'_wp_menu_nopriv',
			'_wp_real_parent_file',
			'_wp_submenu_nopriv',
		);
	}

	private static function find_menu_entry( string $slug ): ?array {
		foreach ( (array) ( $GLOBALS['menu'] ?? array() ) as $entry ) {
			if ( isset( $entry[2] ) && $slug === $entry[2] ) {
				return $entry;
			}
		}

		return null;
	}

	private static function find_menu_position( string $slug ) {
		foreach ( (array) ( $GLOBALS['menu'] ?? array() ) as $position => $entry ) {
			if ( isset( $entry[2] ) && $slug === $entry[2] ) {
				return $position;
			}
		}

		return false;
	}

	private static function find_submenu_entry( string $parent_slug, string $slug ): ?array {
		foreach ( (array) ( $GLOBALS['submenu'][ $parent_slug ] ?? array() ) as $entry ) {
			if ( isset( $entry[2] ) && $slug === $entry[2] ) {
				return $entry;
			}
		}

		return null;
	}

	private static function view_link( string $url, string $label, bool $current ): string {
		return sprintf(
			'<a href="%s"%s>%s</a>',
			\esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			\esc_html( $label )
		);
	}

	private static function capability( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return \sanitize_key( 'cfz_' . $prefix . '_' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
	}

	private static function menu_slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return \sanitize_key( 'cfz-' . $prefix . '-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
	}

	private static function screen_id( \ComponentFuzz\FuzzContext $ctx ): string {
		return \sanitize_key( 'cfz-list-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
	}

	private static function hostile_label( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'label "' . $ctx->identifier( 3, 8 ) . '" <script>alert(1)</script> onclick="bad" & value';
	}

	private static function html_has_no_unsafe_raw_markup( string $html ): bool {
		$lower = strtolower( $html );

		return ! str_contains( $lower, '<script' )
			&& ! str_contains( $lower, ' onclick="' )
			&& ! str_contains( $lower, " onclick='" );
	}

	private static function all_values( array $values, $expected ): bool {
		foreach ( $values as $value ) {
			if ( $value !== $expected ) {
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
					'_COOKIE',
					'_GET',
					'_POST',
					'_REQUEST',
					'admin_page_hooks',
					'current_screen',
					'current_user',
					'hook_suffix',
					'menu',
					'pagenow',
					'parent_file',
					'plugin_page',
					'submenu',
					'title',
					'typenow',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'_parent_pages',
					'_registered_pages',
					'_wp_menu_nopriv',
					'_wp_real_parent_file',
					'_wp_submenu_nopriv',
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

	private static function snapshot_server( array $names ): array {
		$snapshot = array();

		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
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
