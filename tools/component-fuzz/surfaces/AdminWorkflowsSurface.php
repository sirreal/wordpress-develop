<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB WordPress admin workflow APIs.
 */
final class AdminWorkflowsSurface {
	public const NAME = 'admin-workflows';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::maybe_load_optional_ajax_support();

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
			$rows[] = self::check_list_table_action_and_month_helpers( $ctx->fork( 'list-table-helpers' ) );
			$rows[] = self::check_referer_helpers( $ctx->fork( 'referer-helpers' ) );
			$rows[] = self::check_referer_field_helpers( $ctx->fork( 'referer-field-helpers' ) );
			$rows[] = self::check_admin_form_controls( $ctx->fork( 'form-controls' ) );
			$rows[] = self::check_core_list_table_coverage_accounting( $ctx->fork( 'core-list-table-accounting' ) );
			$rows[] = self::check_exiting_ajax_wrappers( $ctx->fork( 'ajax-wrappers' ) );
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
				'home_url',
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
				'sanitize_option',
				'sanitize_title',
				'selected',
				'set_url_scheme',
				'submit_button',
				'date_i18n',
				'wp_create_nonce',
				'wp_get_original_referer',
				'wp_get_raw_referer',
				'wp_get_referer',
				'wp_nonce_field',
				'wp_nonce_url',
				'wp_original_referer_field',
				'wp_referer_field',
				'wp_strip_all_tags',
				'wp_ajax_date_format',
				'wp_ajax_time_format',
				'wp_unslash',
				'wp_validate_redirect',
				'wp_verify_nonce',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function maybe_load_optional_ajax_support(): void {
		if ( defined( 'ABSPATH' ) && ! function_exists( 'wp_ajax_date_format' ) ) {
			$ajax_actions = ABSPATH . 'wp-admin/includes/ajax-actions.php';
			if ( file_exists( $ajax_actions ) ) {
				require_once $ajax_actions;
			}
		}
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

	private static function check_list_table_action_and_month_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$local_snapshot  = self::snapshot_globals(
			array(
				'_GET',
				'_POST',
				'_REQUEST',
				'_wp_post_type_features',
				'current_screen',
				'post_type_meta_caps',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_post_types',
			)
		);
		$screen_id       = self::screen_id( $ctx->fork( 'screen' ) );
		$screen          = \convert_to_screen( $screen_id );
		$hostile_label   = self::hostile_label( $ctx->fork( 'bulk-label' ) );
		$month_seen      = array();
		$disable_seen    = array();
		$table           = self::new_helper_list_table(
			array(
				'bulk_actions' => array(
					'trash'                         => \esc_html( 'Trash ' . $hostile_label ),
					'Change ' . $hostile_label => array(
						'feature' => \esc_html( 'Feature ' . $hostile_label ),
						'archive' => \esc_html( 'Archive ' . $hostile_label ),
					),
				),
				'screen'       => $screen,
			)
		);
		$pre_months      = static function ( $months, string $post_type ) use ( &$month_seen ): array {
			$month_seen[] = array(
				'filter'   => 'pre',
				'postType' => $post_type,
				'input'    => $months,
			);

			return array(
				(object) array(
					'year'  => 2026,
					'month' => 6,
				),
				(object) array(
					'year'  => 2025,
					'month' => 12,
				),
				(object) array(
					'year'  => 0,
					'month' => 0,
				),
			);
		};
		$month_results   = static function ( array $months, string $post_type ) use ( &$month_seen ): array {
			$month_seen[] = array(
				'filter'   => 'results',
				'postType' => $post_type,
				'count'    => count( $months ),
			);

			return $months;
		};
		$disable_months  = static function ( bool $disabled, string $post_type ) use ( &$disable_seen ): bool {
			$disable_seen[] = array(
				'disabled' => $disabled,
				'postType' => $post_type,
			);

			return 'page' === $post_type;
		};
		$result          = array();
		$filters_removed = false;
		$restored        = false;

		if ( function_exists( 'create_initial_post_types' ) ) {
			\create_initial_post_types();
		}

		\add_filter( 'pre_months_dropdown_query', $pre_months, 10, 2 );
		\add_filter( 'months_dropdown_results', $month_results, 10, 2 );
		\add_filter( 'disable_months_dropdown', $disable_months, 10, 2 );

		try {
			$_REQUEST = array(
				'action'        => 'trash',
				'action2'       => 'archive',
				'filter_action' => 'Filter',
			);
			$filter_action = $table->current_action();

			$_REQUEST = array(
				'action'  => 'trash',
				'action2' => 'archive',
			);
			$top_action = $table->current_action();

			$_REQUEST = array(
				'action'  => '-1',
				'action2' => 'archive',
			);
			$bottom_ignored = $table->current_action();

			$bulk_top_html    = $table->expose_bulk_actions( 'top' );
			$bulk_bottom_html = $table->expose_bulk_actions( 'bottom' );

			$_GET     = array( 'm' => '202606' );
			$_POST    = array();
			$_REQUEST = $_GET;
			$months_html = $table->expose_months_dropdown( 'post' );

			$_GET     = array();
			$_REQUEST = array();
			$disabled_months_html = $table->expose_months_dropdown( 'page' );

			$result = compact(
				'bulk_bottom_html',
				'bulk_top_html',
				'bottom_ignored',
				'disabled_months_html',
				'disable_seen',
				'filter_action',
				'month_seen',
				'months_html',
				'top_action'
			);
		} finally {
			\remove_filter( 'disable_months_dropdown', $disable_months, 10 );
			\remove_filter( 'months_dropdown_results', $month_results, 10 );
			\remove_filter( 'pre_months_dropdown_query', $pre_months, 10 );

			$filters_removed = false === \has_filter( 'disable_months_dropdown', $disable_months )
				&& false === \has_filter( 'months_dropdown_results', $month_results )
				&& false === \has_filter( 'pre_months_dropdown_query', $pre_months );

			self::restore_globals( $local_snapshot );
			$restored = self::globals_match(
				$local_snapshot,
				array( '_GET', '_POST', '_REQUEST', '_wp_post_type_features', 'current_screen', 'post_type_meta_caps', 'wp_post_types' )
			);
		}

		self::collect_failure(
			$failures,
			false === ( $result['filter_action'] ?? null )
				&& 'trash' === ( $result['top_action'] ?? null )
				&& false === ( $result['bottom_ignored'] ?? null ),
			'current_action honors filter_action suppression and top bulk action precedence',
			$result
		);

		self::collect_failure(
			$failures,
			str_contains( (string) ( $result['bulk_top_html'] ?? '' ), 'name="action"' )
				&& str_contains( (string) ( $result['bulk_top_html'] ?? '' ), 'bulk-action-selector-top' )
				&& str_contains( (string) ( $result['bulk_top_html'] ?? '' ), '<optgroup label="Change ' )
				&& str_contains( (string) ( $result['bulk_bottom_html'] ?? '' ), 'name="action2"' )
				&& str_contains( (string) ( $result['bulk_bottom_html'] ?? '' ), 'bulk-action-selector-bottom' )
				&& self::html_has_no_unsafe_raw_markup( (string) ( $result['bulk_top_html'] ?? '' ) . (string) ( $result['bulk_bottom_html'] ?? '' ) ),
			'bulk_actions renders first and second dropdown names, escaped optgroup labels, pre-escaped action labels, and compact buttons',
			array(
				'top'    => self::describe_string( (string) ( $result['bulk_top_html'] ?? '' ) ),
				'bottom' => self::describe_string( (string) ( $result['bulk_bottom_html'] ?? '' ) ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( (string) ( $result['months_html'] ?? '' ), 'id="filter-by-date"' )
				&& str_contains( (string) ( $result['months_html'] ?? '' ), "value='202606'" )
				&& str_contains( (string) ( $result['months_html'] ?? '' ), "selected='selected'" )
				&& str_contains( (string) ( $result['months_html'] ?? '' ), 'June 2026' )
				&& str_contains( (string) ( $result['months_html'] ?? '' ), 'December 2025' )
				&& ! str_contains( (string) ( $result['months_html'] ?? '' ), "value='000000'" )
				&& '' === (string) ( $result['disabled_months_html'] ?? '' )
				&& array( 'post' ) === array_column( array_filter( $result['month_seen'] ?? array(), static fn( array $entry ): bool => 'pre' === $entry['filter'] ), 'postType' )
				&& array( 'post' ) === array_column( array_filter( $result['month_seen'] ?? array(), static fn( array $entry ): bool => 'results' === $entry['filter'] ), 'postType' )
				&& array( 'post', 'page' ) === array_column( $result['disable_seen'] ?? array(), 'postType' ),
			'months_dropdown uses filter-provided months, selected request state, zero-year skipping, and disable short-circuit',
			array(
				'months'      => self::describe_string( (string) ( $result['months_html'] ?? '' ) ),
				'disabled'    => self::describe_string( (string) ( $result['disabled_months_html'] ?? '' ) ),
				'monthSeen'   => $result['month_seen'] ?? array(),
				'disableSeen' => $result['disable_seen'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed && $restored,
			'list table helper filters and request globals are restored',
			array(
				'filtersRemoved' => $filters_removed,
				'restored'       => $restored,
			)
		);

		return self::row(
			$ctx,
			'admin-workflows.list-table.actions-and-month-filters',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
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

	private static function check_referer_field_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$local_snapshot  = self::snapshot_globals( array( '_GET', '_POST', '_REQUEST' ) );
		$server_snapshot = self::snapshot_server( array( 'HTTP_HOST', 'HTTP_REFERER', 'REQUEST_URI' ) );
		$page            = \sanitize_key( 'cfz_referer_' . $ctx->identifier( 3, 8 ) );
		$token           = \sanitize_key( $ctx->fork( 'token' )->identifier( 3, 8 ) );
		$current_path    = '/wp-admin/admin.php?page=' . rawurlencode( $page )
			. '&_wp_http_referer=/wp-admin/old.php?drop=<script>'
			. '&unsafe=' . self::hostile_label( $ctx->fork( 'unsafe' ) )
			. '&quote="bad"';
		$request_ref     = \admin_url( 'edit.php?page=' . rawurlencode( $page ) . '&mode=request-' . $token );
		$header_ref      = \admin_url( 'tools.php?page=' . rawurlencode( $page ) . '&mode=header-' . $token );
		$original_ref    = \admin_url( 'users.php?page=' . rawurlencode( $page ) . '&mode=original-' . $token );
		$off_host_ref    = 'https://invalid.example.test/wp-admin/edit.php?page=' . rawurlencode( $page );
		$result          = array();
		$restored        = false;

		$buffer_level = ob_get_level();
		try {
			$_SERVER['HTTP_HOST']   = parse_url( \home_url(), PHP_URL_HOST ) ?: 'example.test';
			$_SERVER['REQUEST_URI'] = $current_path;
			unset( $_SERVER['HTTP_REFERER'] );

			$_GET     = array();
			$_POST    = array();
			$_REQUEST = array();

			$result['referer_field'] = \wp_referer_field( false );

			ob_start();
			\wp_referer_field( true );
			$result['referer_field_echo'] = (string) ob_get_clean();

			$_REQUEST['_wp_http_referer'] = $request_ref;
			$_SERVER['HTTP_REFERER'] = $header_ref;
			$result['raw_request_referer'] = \wp_get_raw_referer();
			$result['request_referer']     = \wp_get_referer();

			$_REQUEST['_wp_http_referer'] = $current_path;
			$result['same_request_referer'] = \wp_get_referer();

			$_REQUEST['_wp_http_referer'] = \home_url() . $current_path;
			$result['same_home_request_referer'] = \wp_get_referer();

			$_REQUEST = array();
			$_SERVER['HTTP_REFERER'] = $header_ref;
			$result['raw_header_referer'] = \wp_get_raw_referer();
			$result['header_referer']     = \wp_get_referer();

			$_REQUEST = array( '_wp_http_referer' => $off_host_ref );
			$result['off_host_referer'] = \wp_get_referer();

			$_REQUEST['_wp_original_http_referer'] = $original_ref;
			$result['original_referer']       = \wp_get_original_referer();
			$result['original_referer_field'] = \wp_original_referer_field( false, 'previous' );

			ob_start();
			\wp_original_referer_field( true, 'previous' );
			$result['original_referer_echo'] = (string) ob_get_clean();

			$_REQUEST = array( '_wp_original_http_referer' => $off_host_ref );
			$result['off_host_original_referer'] = \wp_get_original_referer();

			$_REQUEST = array( '_wp_http_referer' => $request_ref );
			unset( $_SERVER['HTTP_REFERER'] );
			$result['previous_fallback_field'] = \wp_original_referer_field( false, 'previous' );

			$_REQUEST = array();
			$result['current_fallback_field'] = \wp_original_referer_field( false, 'current' );
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			self::restore_globals( $local_snapshot );
			self::restore_server( $server_snapshot );
			$restored = self::globals_match( $local_snapshot, array( '_GET', '_POST', '_REQUEST' ) )
				&& self::server_matches( $server_snapshot, array( 'HTTP_HOST', 'HTTP_REFERER', 'REQUEST_URI' ) );
		}

		self::collect_failure(
			$failures,
			is_string( $result['referer_field'] ?? null )
				&& str_starts_with( $result['referer_field'], '<input type="hidden" name="_wp_http_referer"' )
				&& ! str_contains( $result['referer_field'], '_wp_http_referer=' )
				&& str_contains( $result['referer_field'], 'unsafe=' )
				&& ( $result['referer_field_echo'] ?? null ) === ( $result['referer_field'] ?? null )
				&& self::html_has_no_unsafe_raw_markup( $result['referer_field'] ),
			'wp_referer_field removes nested referer query args, escapes hostile current URLs, and echoes returned markup',
			array(
				'refererField' => $result['referer_field'] ?? null,
				'echo'         => $result['referer_field_echo'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			( $result['raw_request_referer'] ?? null ) === $request_ref
				&& ( $result['request_referer'] ?? null ) === $request_ref
				&& false === ( $result['same_request_referer'] ?? null )
				&& false === ( $result['same_home_request_referer'] ?? null )
				&& ( $result['raw_header_referer'] ?? null ) === $header_ref
				&& ( $result['header_referer'] ?? null ) === $header_ref
				&& false === ( $result['off_host_referer'] ?? null ),
			'raw and validated referer helpers prefer request values over HTTP_REFERER, fall back to HTTP_REFERER, and reject current or off-host URLs',
			array(
				'requestRaw'      => $result['raw_request_referer'] ?? null,
				'requestValid'    => $result['request_referer'] ?? null,
				'samePath'        => $result['same_request_referer'] ?? null,
				'sameAbsolute'    => $result['same_home_request_referer'] ?? null,
				'headerRaw'       => $result['raw_header_referer'] ?? null,
				'headerValidated' => $result['header_referer'] ?? null,
				'offHost'         => $result['off_host_referer'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			( $result['original_referer'] ?? null ) === $original_ref
				&& str_contains( (string) ( $result['original_referer_field'] ?? '' ), 'name="_wp_original_http_referer"' )
				&& str_contains( (string) ( $result['original_referer_field'] ?? '' ), \esc_attr( $original_ref ) )
				&& ( $result['original_referer_echo'] ?? null ) === ( $result['original_referer_field'] ?? null )
				&& str_contains( (string) ( $result['previous_fallback_field'] ?? '' ), \esc_attr( $request_ref ) )
				&& str_contains( (string) ( $result['current_fallback_field'] ?? '' ), 'page=' . rawurlencode( $page ) )
				&& false === ( $result['off_host_original_referer'] ?? null )
				&& self::html_has_no_unsafe_raw_markup( (string) ( $result['original_referer_field'] ?? '' ) )
				&& self::html_has_no_unsafe_raw_markup( (string) ( $result['previous_fallback_field'] ?? '' ) )
				&& self::html_has_no_unsafe_raw_markup( (string) ( $result['current_fallback_field'] ?? '' ) ),
			'original referer fields prefer posted originals, reject off-host originals, echo returned markup, and fall back to previous or current request URLs',
			array(
				'original'         => $result['original_referer_field'] ?? null,
				'originalEcho'     => $result['original_referer_echo'] ?? null,
				'offHostOriginal'  => $result['off_host_original_referer'] ?? null,
				'previousFallback' => $result['previous_fallback_field'] ?? null,
				'currentFallback'  => $result['current_fallback_field'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			$restored,
			'direct referer field helpers restore request and server globals',
			array(
				'globals' => self::globals_match( $local_snapshot, array( '_GET', '_POST', '_REQUEST' ) ),
				'server'  => self::server_matches( $server_snapshot, array( 'HTTP_HOST', 'HTTP_REFERER', 'REQUEST_URI' ) ),
			)
		);

		return self::row(
			$ctx,
			'admin-workflows.referers.direct-field-and-original-helpers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_admin_form_controls( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$server_snapshot = self::snapshot_server( array( 'REQUEST_URI' ) );
		$action          = 'cfz_form_' . \sanitize_key( $ctx->identifier( 3, 8 ) );
		$nonce_name      = 'cfz_nonce_' . \sanitize_key( $ctx->fork( 'nonce' )->identifier( 3, 8 ) );
		$submit_name     = 'cfz_submit_' . \sanitize_key( $ctx->fork( 'submit' )->identifier( 3, 8 ) );
		$button_id       = 'cfz-button-' . \sanitize_key( $ctx->fork( 'button' )->identifier( 3, 8 ) );
		$label           = 'Save ' . self::hostile_label( $ctx->fork( 'label' ) );
		$result          = array();

		$buffer_level = ob_get_level();
		try {
			$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . rawurlencode( $action ) . '&unsafe=<script>alert(1)</script>&quote="bad"';

			$result['nonce_with_referer']    = \wp_nonce_field( $action, $nonce_name, true, false );
			$result['nonce_without_referer'] = \wp_nonce_field( $action, $nonce_name, false, false );

			ob_start();
			\wp_nonce_field( $action, $nonce_name, true, true );
			$result['nonce_echo'] = (string) ob_get_clean();

			$result['selected_match'] = \selected( $action, $action, false );
			$result['selected_miss']  = \selected( $action, $action . '-miss', false );

			ob_start();
			\selected( $action, $action, true );
			$result['selected_echo'] = (string) ob_get_clean();

			ob_start();
			\submit_button(
				$label,
				'primary large',
				$submit_name,
				false,
				array(
					'id'       => $button_id,
					'data-cfz' => $label,
				)
			);
			$result['submit_button'] = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			self::restore_server( $server_snapshot );
		}

		self::collect_failure(
			$failures,
			is_string( $result['nonce_with_referer'] ?? null )
				&& str_contains( $result['nonce_with_referer'], 'name="' . $nonce_name . '"' )
				&& str_contains( $result['nonce_with_referer'], 'name="_wp_http_referer"' )
				&& is_string( $result['nonce_without_referer'] ?? null )
				&& str_contains( $result['nonce_without_referer'], 'name="' . $nonce_name . '"' )
				&& ! str_contains( $result['nonce_without_referer'], '_wp_http_referer' )
				&& $result['nonce_echo'] === $result['nonce_with_referer']
				&& self::html_has_no_unsafe_raw_markup( $result['nonce_with_referer'] ),
			'nonce fields include custom names, optional referer fields, and escaped referer state',
			array(
				'withReferer'    => $result['nonce_with_referer'] ?? null,
				'withoutReferer' => $result['nonce_without_referer'] ?? null,
				'echo'           => $result['nonce_echo'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			" selected='selected'" === ( $result['selected_match'] ?? null )
				&& '' === ( $result['selected_miss'] ?? null )
				&& ( $result['selected_echo'] ?? null ) === ( $result['selected_match'] ?? null ),
			'selected helper returns and echoes only exact-match selection attributes',
			array(
				'match' => $result['selected_match'] ?? null,
				'miss'  => $result['selected_miss'] ?? null,
				'echo'  => $result['selected_echo'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $result['submit_button'] ?? null )
				&& str_contains( $result['submit_button'], 'type="submit"' )
				&& str_contains( $result['submit_button'], 'name="' . $submit_name . '"' )
				&& str_contains( $result['submit_button'], 'id="' . $button_id . '"' )
				&& str_contains( $result['submit_button'], 'class="button button-primary button-large"' )
				&& str_contains( $result['submit_button'], 'data-cfz=' )
				&& self::html_has_no_unsafe_raw_markup( $result['submit_button'] ),
			'submit_button renders escaped generated labels, ids, names, classes, and custom attributes',
			array( 'submitButton' => $result['submit_button'] ?? null )
		);

		self::collect_failure(
			$failures,
			self::server_matches( $server_snapshot, array( 'REQUEST_URI' ) ),
			'admin form control helper request URI state is restored',
			array( 'requestUri' => $_SERVER['REQUEST_URI'] ?? null )
		);

		return self::row(
			$ctx,
			'admin-workflows.form-controls.nonce-selected-submit',
			array() === $failures,
			array(
				'action'   => $action,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_core_list_table_coverage_accounting( \ComponentFuzz\FuzzContext $ctx ): array {
		$admin_list_surface = AdminListTablesSurface::NAME;
		$privacy_surface    = PrivacyAdminRequestsSurface::NAME;

		return self::row(
			$ctx,
			'admin-workflows.list-table.scoped-coverage-accounted',
			class_exists( AdminListTablesSurface::class ) && class_exists( PrivacyAdminRequestsSurface::class ),
			array(
				'retired_skip'                    => 'admin-workflows.list-table.core-subclasses-skipped',
				'admin_workflows_direct_coverage' => array(
					'synthetic WP_List_Table rendering',
					'columns, hidden columns, sortable columns, and primary column filters',
					'views and tablenav output',
					'bulk controls and row actions',
					'current action and month dropdown helpers',
					'referer and form-control helpers',
					'request/filter/global restoration',
				),
				'dedicated_surface_coverage'       => array(
					$admin_list_surface => array(
						'WP_Posts_List_Table',
						'WP_Media_List_Table',
						'WP_Terms_List_Table',
						'WP_Users_List_Table',
						'WP_Comments_List_Table',
						'WP_Plugins_List_Table',
						'WP_Themes_List_Table',
						'WP_Plugin_Install_List_Table',
						'WP_Theme_Install_List_Table',
						'WP_Application_Passwords_List_Table',
						'WP_MS_Themes_List_Table',
						'WP_MS_Sites_List_Table',
						'WP_MS_Users_List_Table',
					),
					$privacy_surface    => array(
						'WP_Privacy_Data_Export_Requests_List_Table',
						'WP_Privacy_Data_Removal_Requests_List_Table',
					),
				),
				'not_claimed'                      => array(
					'full admin.php/admin-ajax.php request dispatch',
					'destructive plugin/theme lifecycle operations',
					'real uploads',
					'true multisite write paths',
					'direct WP_Links_List_Table subclass-specific coverage',
					'direct WP_Post_Comments_List_Table subclass-specific coverage',
				),
			)
		);
	}

	private static function check_exiting_ajax_wrappers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$local_snapshot  = self::snapshot_globals( array( '_GET', '_POST', '_REQUEST', '_COOKIE' ) );
		$server_snapshot = self::snapshot_server( array( 'REQUEST_METHOD', 'REQUEST_URI', 'PHP_SELF' ) );
		$token           = \sanitize_key( $ctx->identifier( 3, 8 ) );
		$date_format     = 'Y-m-d \D\a\t\e "' . $token . '" <script>alert(1)</script>';
		$time_format     = 'H:i \T\i\m\e "' . $token . '" <b onclick="bad">';
		$cases           = array(
			'date' => array(
				'option'   => 'date_format',
				'format'   => $date_format,
				'function' => 'wp_ajax_date_format',
			),
			'time' => array(
				'option'   => 'time_format',
				'format'   => $time_format,
				'function' => 'wp_ajax_time_format',
			),
		);
		$results         = array();
		$restored        = false;

		try {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['REQUEST_URI']    = '/wp-admin/admin-ajax.php';
			$_SERVER['PHP_SELF']       = '/wp-admin/admin-ajax.php';

			foreach ( $cases as $name => $case ) {
				$_GET     = array();
				$_COOKIE  = array();
				$_POST    = array( 'date' => addslashes( $case['format'] ) );
				$_REQUEST = $_POST;

				$capture = self::capture_ajax_call(
					static function () use ( $case ): void {
						$case['function']();
					}
				);

				$results[ $name ] = array(
					'capture'          => $capture,
					'expected'         => \date_i18n( \sanitize_option( $case['option'], \wp_unslash( $_POST['date'] ) ) ),
					'sanitized_format' => \sanitize_option( $case['option'], \wp_unslash( $_POST['date'] ) ),
					'raw_format'       => $case['format'],
				);
			}
		} finally {
			self::restore_globals( $local_snapshot );
			self::restore_server( $server_snapshot );
			$restored = self::globals_match( $local_snapshot, array( '_GET', '_POST', '_REQUEST', '_COOKIE' ) )
				&& self::server_matches( $server_snapshot, array( 'REQUEST_METHOD', 'REQUEST_URI', 'PHP_SELF' ) );
		}

		foreach ( $results as $name => $result ) {
			$body = self::ajax_body( $result['capture'] ?? array() );
			self::collect_failure(
				$failures,
				! empty( $result['capture']['captured'] )
					&& null === ( $result['capture']['threw'] ?? null )
					&& ! empty( $result['capture']['bufferBalanced'] )
					&& ! empty( $result['capture']['filtersRestored'] )
					&& $body === ( $result['expected'] ?? null )
					&& ! str_contains( strtolower( $body ), '<script' )
					&& ! str_contains( strtolower( $body ), 'onclick=' ),
				"wp_ajax_{$name}_format() unslashes, sanitizes, formats, dies through ajax capture, and strips unsafe markup",
				array( 'result' => $result )
			);
		}

		self::collect_failure(
			$failures,
			$restored
				&& self::all_ajax_captures_restored( array_column( $results, 'capture' ) ),
			'date/time AJAX wrapper coverage restores request globals, server globals, filters, and output buffers',
			array(
				'restored' => $restored,
				'results'  => $results,
			)
		);

		return self::row(
			$ctx,
			'admin-workflows.ajax.date-time-format-wrappers',
			array() === $failures,
			array(
				'token'    => $token,
				'failures' => array_slice( $failures, 0, 6 ),
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

	private static function new_helper_list_table( array $config ): \WP_List_Table {
		return new class( $config ) extends \WP_List_Table {
			private array $config;

			public function __construct( array $config ) {
				$this->config = $config;
				parent::__construct(
					array(
						'ajax'     => false,
						'plural'   => 'cfz_helper_items',
						'screen'   => $config['screen'],
						'singular' => 'cfz_helper_item',
					)
				);
			}

			protected function get_bulk_actions(): array {
				return $this->config['bulk_actions'];
			}

			public function expose_bulk_actions( string $which ): string {
				$level = ob_get_level();
				ob_start();
				try {
					$this->bulk_actions( $which );
					return (string) ob_get_clean();
				} catch ( \Throwable $e ) {
					while ( ob_get_level() > $level ) {
						ob_end_clean();
					}
					throw $e;
				}
			}

			public function expose_months_dropdown( string $post_type ): string {
				$level = ob_get_level();
				ob_start();
				try {
					$this->months_dropdown( $post_type );
					return (string) ob_get_clean();
				} catch ( \Throwable $e ) {
					while ( ob_get_level() > $level ) {
						ob_end_clean();
					}
					throw $e;
				}
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

	private static function capture_ajax_call( callable $callback ): array {
		$die_calls      = array();
		$doing_ajax     = static fn (): bool => true;
		$handler_filter = static function () use ( &$die_calls ): callable {
			return static function ( $message = '', $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'message' => $message,
					'title'   => $title,
					'args'    => is_array( $args ) ? $args : array( 'raw' => $args ),
				);
				throw new AdminWorkflowsSurface_DieCaptured( 'Captured ajax wp_die.' );
			};
		};

		$level    = ob_get_level();
		$output   = '';
		$captured = false;
		$threw    = null;

		if ( ! headers_sent() ) {
			header_remove();
		}

		\add_filter( 'wp_doing_ajax', $doing_ajax, 1 );
		\add_filter( 'wp_die_ajax_handler', $handler_filter, 1 );
		ob_start();
		try {
			$callback();
		} catch ( AdminWorkflowsSurface_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$threw = self::describe_throwable( $e );
		} finally {
			$output = (string) ob_get_clean();
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			\remove_filter( 'wp_die_ajax_handler', $handler_filter, 1 );
			\remove_filter( 'wp_doing_ajax', $doing_ajax, 1 );
			if ( ! headers_sent() ) {
				header_remove();
			}
		}

		return array(
			'captured'        => $captured,
			'threw'           => $threw,
			'output'          => $output,
			'dieCalls'        => $die_calls,
			'filtersRestored' => false === \has_filter( 'wp_die_ajax_handler', $handler_filter )
				&& false === \has_filter( 'wp_doing_ajax', $doing_ajax ),
			'bufferBalanced'  => ob_get_level() === $level,
		);
	}

	private static function ajax_body( array $capture ): string {
		$output = (string) ( $capture['output'] ?? '' );
		if ( '' !== $output ) {
			return $output;
		}

		$message = $capture['dieCalls'][0]['message'] ?? '';
		return is_scalar( $message ) ? (string) $message : gettype( $message );
	}

	private static function all_ajax_captures_restored( array $captures ): bool {
		foreach ( $captures as $capture ) {
			if (
				empty( $capture['captured'] )
				|| ! empty( $capture['threw'] )
				|| empty( $capture['bufferBalanced'] )
				|| empty( $capture['filtersRestored'] )
			) {
				return false;
			}
		}

		return true;
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

	private static function server_matches( array $snapshot, array $names ): bool {
		foreach ( $names as $name ) {
			$exists = array_key_exists( $name, $_SERVER );
			if ( $exists !== $snapshot[ $name ]['exists'] ) {
				return false;
			}
			if ( $exists && $_SERVER[ $name ] !== $snapshot[ $name ]['value'] ) {
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

final class AdminWorkflowsSurface_DieCaptured extends \RuntimeException {}
