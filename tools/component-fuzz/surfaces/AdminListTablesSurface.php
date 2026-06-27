<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes concrete no-DB admin list table subclasses.
 */
final class AdminListTablesSurface {
	public const NAME = 'admin-list-tables';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-list-tables.bootstrap-apis-available',
					'Required admin list-table APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot        = self::snapshot_state();
		$server_snapshot = self::snapshot_server( array( 'HTTP_HOST', 'REQUEST_URI', 'PHP_SELF' ) );
		$rows            = array();

		try {
			self::bootstrap_core_types();
			self::reset_stub_content();

			$rows[] = self::check_posts_media_tables( $ctx->fork( 'posts-media' ) );
			$rows[] = self::check_comments_terms_users_tables( $ctx->fork( 'comments-terms-users' ) );
			$rows[] = self::check_plugin_theme_tables( $ctx->fork( 'plugins-themes' ) );
			$rows[] = self::check_plugin_install_table( $ctx->fork( 'plugin-install' ) );
			$rows[] = self::check_theme_install_table( $ctx->fork( 'theme-install' ) );
			$rows[] = self::check_network_themes_table( $ctx->fork( 'network-themes' ) );
			$rows[] = self::check_application_passwords_table( $ctx->fork( 'application-passwords' ) );
			$rows[] = self::check_application_passwords_last_ip_boundary( $ctx->fork( 'application-passwords-last-ip' ) );
			$rows[] = self::check_base_pagination_per_page_output( $ctx->fork( 'base-pagination' ) );
			$rows[] = self::check_network_tables( $ctx->fork( 'network-tables' ) );
			$rows[] = self::skipped_db_heavy_branches( $ctx->fork( 'skips' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'admin-list-tables.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_stub_content();
			self::restore_server( $server_snapshot );
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Comment',
				'WP_List_Table',
				'WP_Post',
				'WP_Query',
				'WP_Screen',
				'WP_Site',
				'WP_Term',
				'WP_User',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_get_list_table',
				'add_filter',
				'admin_url',
				'apply_filters',
				'convert_to_screen',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'esc_attr',
				'esc_html',
				'esc_url',
				'get_current_user_id',
				'get_post_type_object',
				'has_filter',
				'network_admin_url',
				'register_theme_directory',
				'remove_filter',
				'sanitize_key',
				'sanitize_title',
				'search_theme_directories',
				'update_user_caches',
				'wp_cache_delete',
				'wp_cache_set',
				'wp_create_nonce',
				'wp_get_current_user',
				'wp_get_theme',
				'wp_get_themes',
				'wp_insert_user',
				'wp_is_auto_update_enabled_for_type',
				'wp_nonce_url',
				'wp_strip_all_tags',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_posts_media_tables( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$filters        = array();
		$screen_post    = self::screen( 'edit-post', 'post' );
		$screen_media   = self::screen( 'upload', 'attachment' );
		$current_user   = self::synthetic_user( $ctx->fork( 'current-user' ), 51000, 'admin' );
		$post           = self::synthetic_post( $ctx->fork( 'post' ), 61000, 'post', 'publish', (int) $current_user->ID );
		$attachment     = self::synthetic_post( $ctx->fork( 'attachment' ), 62000, 'attachment', 'inherit', (int) $current_user->ID );
		$hostile_label  = self::hostile_label( $ctx->fork( 'label' ) );
		$query_events   = array();
		$hidden_events  = array();
		$primary_events = array();
		$result         = array();
		$filters_removed = false;

		$attachment->post_mime_type = 'image/jpeg';
		$attachment->post_parent    = 0;
		self::cache_post( $post );
		self::cache_post( $attachment );

		$cap_filter = self::cap_filter(
			array(
				'delete_others_posts',
				'delete_post',
				'delete_published_posts',
				'delete_posts',
				'edit_others_posts',
				'edit_post',
				'edit_posts',
				'edit_published_posts',
				'publish_posts',
				'read',
				'read_post',
				'read_private_posts',
				'upload_files',
			)
		);

		$post_query_filter = static function ( $posts, \WP_Query $query ) use ( $attachment, $post, &$query_events ) {
			$post_type = $query->get( 'post_type' );

			if ( 'post' === $post_type ) {
				$query_events[]       = array( 'post_type' => $post_type, 'orderby' => $query->get( 'orderby' ) );
				$query->found_posts   = 7;
				$query->max_num_pages = 4;
				return array( $post );
			}

			if ( 'attachment' === $post_type ) {
				$query_events[]       = array( 'post_type' => $post_type, 'orderby' => $query->get( 'orderby' ) );
				$query->found_posts   = 5;
				$query->max_num_pages = 3;
				return array( $attachment );
			}

			return $posts;
		};
		$edit_per_page_filter = static function ( int $per_page, string $post_type ): int {
			return 'post' === $post_type ? 2 : $per_page;
		};
		$upload_per_page_filter = static function (): int {
			return 2;
		};
		$hidden_filter = static function ( array $hidden, \WP_Screen $screen ) use ( $screen_media, $screen_post, &$hidden_events ): array {
			$hidden_events[] = $screen->id;

			if ( $screen->id === $screen_post->id ) {
				return array( 'date' );
			}

			if ( $screen->id === $screen_media->id ) {
				return array( 'author' );
			}

			return $hidden;
		};
		$primary_filter = static function ( string $default, string $context ) use ( $screen_media, $screen_post, &$primary_events ): string {
			$primary_events[] = $context;

			if ( $context === $screen_post->id || $context === $screen_media->id ) {
				return 'title';
			}

			return $default;
		};
		$post_columns_filter = static function ( array $columns ) use ( $hostile_label ): array {
			$columns['cfz_custom'] = \esc_html( 'Generated ' . $hostile_label );
			return $columns;
		};
		$media_columns_filter = static function ( array $columns ) use ( $hostile_label ): array {
			$columns['cfz_media'] = \esc_html( 'Media ' . $hostile_label );
			return $columns;
		};
		$post_sortable_filter = static function ( array $sortable ): array {
			$sortable['cfz_custom'] = array( 'cfz_custom', false, 'Generated', 'Table ordered by generated value.' );
			return $sortable;
		};
		$media_sortable_filter = static function ( array $sortable ): array {
			$sortable['cfz_media'] = array( 'cfz_media', false, 'Media', 'Table ordered by media fixture.' );
			return $sortable;
		};
		$show_checkbox_filter = static function (): bool {
			return true;
		};
		$quick_edit_filter = static function (): bool {
			return false;
		};
		$the_title_filter = static function ( string $title ): string {
			return \esc_html( $title );
		};
		$attached_file_filter = static function ( $file, int $attachment_id ) use ( $attachment, $hostile_label ) {
			if ( $attachment_id === (int) $attachment->ID ) {
				return '/tmp/cfz-list-table-' . rawurlencode( $hostile_label ) . '.jpg';
			}

			return $file;
		};
		$attachment_url_filter = static function ( $url, int $attachment_id ) use ( $attachment ): string {
			if ( $attachment_id === (int) $attachment->ID ) {
				return 'https://example.test/uploads/cfz-list-table.jpg';
			}

			return (string) $url;
		};

		self::add_filter_record( $filters, 'user_has_cap', $cap_filter, 10, 4 );
		self::add_filter_record( $filters, 'posts_pre_query', $post_query_filter, 10, 2 );
		self::add_filter_record( $filters, 'edit_posts_per_page', $edit_per_page_filter, 10, 2 );
		self::add_filter_record( $filters, 'upload_per_page', $upload_per_page_filter, 10, 1 );
		self::add_filter_record( $filters, 'hidden_columns', $hidden_filter, 10, 3 );
		self::add_filter_record( $filters, 'list_table_primary_column', $primary_filter, 10, 2 );
		self::add_filter_record( $filters, 'manage_post_posts_columns', $post_columns_filter, 10, 1 );
		self::add_filter_record( $filters, 'manage_media_columns', $media_columns_filter, 10, 2 );
		self::add_filter_record( $filters, "manage_{$screen_post->id}_sortable_columns", $post_sortable_filter, 10, 1 );
		self::add_filter_record( $filters, "manage_{$screen_media->id}_sortable_columns", $media_sortable_filter, 10, 1 );
		self::add_filter_record( $filters, 'wp_list_table_show_post_checkbox', $show_checkbox_filter, 10, 2 );
		self::add_filter_record( $filters, 'quick_edit_enabled_for_post_type', $quick_edit_filter, 10, 2 );
		self::add_filter_record( $filters, 'the_title', $the_title_filter, 10, 1 );
		self::add_filter_record( $filters, 'get_attached_file', $attached_file_filter, 10, 2 );
		self::add_filter_record( $filters, 'wp_get_attachment_url', $attachment_url_filter, 10, 2 );

		try {
			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'edit.php';
			$_GET                    = array(
				'orderby'  => 'date',
				'order'    => 'desc',
				'paged'    => 2,
				'post_type' => 'post',
			);
			$_POST                   = array();
			$_REQUEST                = $_GET;
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/edit.php';
			$_SERVER['REQUEST_URI']  = '/wp-admin/edit.php?post_type=post&orderby=date&order=desc&paged=2';

			$post_table = self::list_table( 'WP_Posts_List_Table', $screen_post );
			$post_table->prepare_items();
			self::set_property( $post_table, 'comment_pending_count', array( (int) $post->ID => 1 ) );

			$post_column_info = $post_table->get_column_info();
			$post_bulk        = self::invoke( $post_table, 'get_bulk_actions' );
			$post_views       = self::invoke( $post_table, 'get_views' );
			$post_ajax_allowed = $post_table->ajax_user_can();
			$post_ajax_denied = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_post ) {
					$table = self::list_table( 'WP_Posts_List_Table', $screen_post );
					return $table->ajax_user_can();
				}
			);

			$post_row = self::capture(
				static function () use ( $post, $post_table ): void {
					$post_table->single_row( $post, 0 );
				}
			);

			$GLOBALS['pagenow']     = 'upload.php';
			$_GET                   = array(
				'attachment-filter' => 'post_mime_type:image',
				'orderby'           => 'title',
				'order'             => 'asc',
				'paged'             => 2,
			);
			$_POST                  = array();
			$_REQUEST               = $_GET;
			$_SERVER['PHP_SELF']    = '/wp-admin/upload.php';
			$_SERVER['REQUEST_URI'] = '/wp-admin/upload.php?attachment-filter=post_mime_type:image&orderby=title&order=asc&paged=2';

			$media_table = self::list_table( 'WP_Media_List_Table', $screen_media );
			$media_table->prepare_items();

			$media_column_info = $media_table->get_column_info();
			$media_bulk        = self::invoke( $media_table, 'get_bulk_actions' );
			$media_views       = self::invoke( $media_table, 'get_views' );
			$media_ajax_allowed = $media_table->ajax_user_can();
			$media_ajax_denied = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_media ) {
					$table = self::list_table( 'WP_Media_List_Table', $screen_media );
					return $table->ajax_user_can();
				}
			);
			$media_row = self::capture(
				static function () use ( $media_table ): void {
					$media_table->display_rows();
				}
			);

			$result = compact(
				'media_ajax_denied',
				'media_ajax_allowed',
				'media_bulk',
				'media_column_info',
				'media_row',
				'media_table',
				'media_views',
				'post_ajax_denied',
				'post_ajax_allowed',
				'post_bulk',
				'post_column_info',
				'post_row',
				'post_table',
				'post_views',
				'query_events'
			);
		} finally {
			self::remove_filter_records( $filters );
			$filters_removed = self::filters_removed( $filters );
		}

		$post_column_info  = $result['post_column_info'] ?? array();
		$media_column_info = $result['media_column_info'] ?? array();
		$post_columns      = $post_column_info[0] ?? array();
		$media_columns     = $media_column_info[0] ?? array();
		$post_hidden       = $post_column_info[1] ?? array();
		$media_hidden      = $media_column_info[1] ?? array();
		$post_sortable     = $post_column_info[2] ?? array();
		$media_sortable    = $media_column_info[2] ?? array();
		$post_primary      = $post_column_info[3] ?? null;
		$media_primary     = $media_column_info[3] ?? null;
		$post_row          = (string) ( $result['post_row'] ?? '' );
		$media_row         = (string) ( $result['media_row'] ?? '' );

		self::collect_failure(
			$failures,
			array( 'post', 'attachment' ) === array_column( $query_events, 'post_type' )
				&& 7 === ( $result['post_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 4 === ( $result['post_table']->get_pagination_arg( 'total_pages' ) ?? null )
				&& 2 === ( $result['post_table']->get_pagination_arg( 'per_page' ) ?? null )
				&& 5 === ( $result['media_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 3 === ( $result['media_table']->get_pagination_arg( 'total_pages' ) ?? null )
				&& 2 === ( $result['media_table']->get_pagination_arg( 'per_page' ) ?? null ),
			'post and media prepare_items use posts_pre_query fixtures and deterministic pagination',
			$result
		);

		self::collect_failure(
			$failures,
			self::stable_column_ids( $post_columns )
				&& self::stable_column_ids( $media_columns )
				&& isset( $post_columns['title'], $post_columns['date'], $post_columns['cfz_custom'] )
				&& isset( $media_columns['title'], $media_columns['author'], $media_columns['cfz_media'] )
				&& array( 'date' ) === $post_hidden
				&& array( 'author' ) === $media_hidden
				&& 'title' === $post_primary
				&& 'title' === $media_primary
				&& isset( $post_sortable['title'], $post_sortable['date'], $post_sortable['cfz_custom'] )
				&& isset( $media_sortable['title'], $media_sortable['date'], $media_sortable['cfz_media'] )
				&& in_array( $screen_post->id, $hidden_events, true )
				&& in_array( $screen_media->id, $hidden_events, true )
				&& in_array( $screen_post->id, $primary_events, true )
				&& in_array( $screen_media->id, $primary_events, true ),
			'post and media column IDs, hidden columns, sortable columns, and primary columns are stable and screen-local',
			array(
				'mediaColumnInfo' => $media_column_info,
				'postColumnInfo'  => $post_column_info,
			)
		);

		self::collect_failure(
			$failures,
			isset( $result['post_bulk']['edit'] )
				&& ( isset( $result['post_bulk']['trash'] ) || isset( $result['post_bulk']['delete'] ) )
				&& ( isset( $result['media_bulk']['delete'] ) || isset( $result['media_bulk']['trash'] ) )
				&& false === $result['post_ajax_denied']
				&& false === $result['media_ajax_denied']
				&& true === $result['post_ajax_allowed']
				&& true === $result['media_ajax_allowed'],
			'post and media bulk/action capability gates respect synthetic capabilities',
			array(
				'mediaBulk'       => $result['media_bulk'] ?? array(),
				'postBulk'        => $result['post_bulk'] ?? array(),
				'mediaAjaxAllowed' => $result['media_ajax_allowed'] ?? null,
				'postAjaxAllowed' => $result['post_ajax_allowed'] ?? null,
				'postAjaxDenied'  => $result['post_ajax_denied'] ?? null,
				'mediaAjaxDenied' => $result['media_ajax_denied'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $post_row, 'id="post-' . (int) $post->ID . '"' )
				&& str_contains( $post_row, 'name="post[]"' )
				&& str_contains( $post_row, 'row-title' )
				&& str_contains( $post_row, '_wpnonce=' )
				&& str_contains( $media_row, 'id="post-' . (int) $attachment->ID . '"' )
				&& str_contains( $media_row, 'name="media[]"' )
				&& str_contains( $media_row, 'filename' )
				&& self::html_has_no_raw_script( $post_row . $media_row ),
			'post and media row output contains valid object IDs, row actions, and no raw script leakage',
			array(
				'postContainsId'       => str_contains( $post_row, 'id="post-' . (int) $post->ID . '"' ),
				'postContainsCheckbox' => str_contains( $post_row, 'name="post[]"' ),
				'postContainsTitle'    => str_contains( $post_row, 'row-title' ),
				'postContainsNonce'    => str_contains( $post_row, '_wpnonce=' ),
				'mediaContainsId'      => str_contains( $media_row, 'id="post-' . (int) $attachment->ID . '"' ),
				'mediaContainsCheckbox' => str_contains( $media_row, 'name="media[]"' ),
				'mediaContainsFilename' => str_contains( $media_row, 'filename' ),
				'postMediaNoScript'    => self::html_has_no_raw_script( $post_row . $media_row ),
				'mediaRow'             => $media_row,
				'postRow'              => $post_row,
			)
		);

		self::collect_failure(
			$failures,
			isset( $result['post_views']['all'], $result['media_views']['all'], $result['media_views']['detached'] )
				&& self::html_has_no_raw_script( implode( '', $result['post_views'] ?? array() ) . implode( '', $result['media_views'] ?? array() ) ),
			'post and media concrete views render escaped status/type URLs',
			array(
				'mediaViews' => $result['media_views'] ?? array(),
				'postViews'  => $result['post_views'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed,
			'post/media pre-query, column, primary, title, capability, and attachment filters are removed',
			array( 'filtersRemoved' => $filters_removed )
		);

		return self::row(
			$ctx,
			'admin-list-tables.posts-media.prepare-columns-rows-actions',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screens'  => array( $screen_post->id, $screen_media->id ),
			)
		);
	}

	private static function check_comments_terms_users_tables( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures          = array();
		$filters           = array();
		$current_user      = self::synthetic_user( $ctx->fork( 'current-user' ), 53000, 'moderator' );
		$post              = self::synthetic_post( $ctx->fork( 'comment-post' ), 63000, 'post', 'publish', (int) $current_user->ID );
		$comment           = self::synthetic_comment( $ctx->fork( 'comment' ), 64000, (int) $post->ID, (int) $current_user->ID );
		$term              = self::synthetic_term( $ctx->fork( 'term' ), 65000, 'post_tag' );
		$user_id           = self::insert_fixture_user( $ctx->fork( 'listed-user' ), 66000 );
		$screen_comments   = self::screen( 'edit-comments' );
		$screen_terms      = self::screen( 'edit-post_tag', 'post', 'post_tag' );
		$screen_users      = self::screen( 'users' );
		$query_events      = array();
		$hidden_events     = array();
		$primary_events    = array();
		$user_query_events = array();
		$result            = array();
		$filters_removed   = false;

		self::cache_post( $post );
		self::cache_comment( $comment );
		self::cache_term( $term );

		$cap_filter = self::cap_filter(
			array(
				'delete_posts',
				'delete_published_posts',
				'delete_terms',
				'delete_users',
				'edit_comments',
				'edit_posts',
				'edit_published_posts',
				'edit_terms',
				'edit_users',
				'list_users',
				'manage_categories',
				'moderate_comments',
				'publish_posts',
				'promote_users',
				'read',
				'read_post',
			)
		);
		$comments_pre_query = static function ( $comment_data, \WP_Comment_Query $query ) use ( $comment, &$query_events ) {
			$query_events[] = array(
				'kind'   => 'comments',
				'count'  => (bool) $query->query_vars['count'],
				'status' => $query->query_vars['status'] ?? '',
			);

			if ( $query->query_vars['count'] ) {
				$query->found_comments = 3;
				$query->max_num_pages  = 3;
				return 3;
			}

			$query->found_comments = 3;
			$query->max_num_pages  = 3;
			return array( $comment, $comment );
		};
		$wp_count_comments_filter = static function () {
			return (object) array(
				'all'       => 3,
				'approved'  => 1,
				'moderated' => 1,
				'mine'      => 1,
				'spam'      => 1,
				'trash'     => 1,
			);
		};
		$comment_text_filter = static function ( string $text ): string {
			return \esc_html( $text );
		};
		$term_text_filter = static function ( string $text ): string {
			return \esc_html( $text );
		};
		$editable_slug_filter = static function ( string $slug ): string {
			return \esc_attr( $slug );
		};
		$term_link_filter = static function ( string $url ): string {
			return \esc_url( $url );
		};
		$comments_per_page_filter = static function (): int {
			return 1;
		};
		$terms_pre_query = static function ( $terms, \WP_Term_Query $query ) use ( $term, &$query_events ) {
			$query_events[] = array(
				'kind'     => 'terms',
				'fields'   => $query->query_vars['fields'] ?? '',
				'taxonomy' => $query->query_vars['taxonomy'] ?? array(),
			);

			if ( 'count' === ( $query->query_vars['fields'] ?? '' ) ) {
				return 5;
			}

			return array( $term );
		};
		$terms_per_page_filter = static function (): int {
			return 2;
		};
		$quick_edit_tax_filter = static function (): bool {
			return false;
		};
		$users_pre_query = static function ( $users, \WP_User_Query $query ) use ( $user_id, &$user_query_events ) {
			$user_query_events[] = $query->query_vars;
			self::set_property( $query, 'total_users', 4 );
			return array( $user_id );
		};
		$count_users_filter = static function () {
			return array(
				'total_users' => 4,
				'avail_roles' => array(
					'administrator' => 1,
					'editor'        => 1,
					'none'          => 1,
				),
			);
		};
		$users_per_page_filter = static function (): int {
			return 2;
		};
		$hidden_filter = static function ( array $hidden, \WP_Screen $screen ) use ( $screen_comments, $screen_terms, $screen_users, &$hidden_events ): array {
			$hidden_events[] = $screen->id;

			if ( $screen->id === $screen_comments->id ) {
				return array( 'response' );
			}

			if ( $screen->id === $screen_terms->id ) {
				return array( 'slug' );
			}

			if ( $screen->id === $screen_users->id ) {
				return array( 'email' );
			}

			return $hidden;
		};
		$primary_filter = static function ( string $default, string $context ) use ( $screen_comments, $screen_terms, $screen_users, &$primary_events ): string {
			$primary_events[] = $context;

			if ( $context === $screen_comments->id ) {
				return 'comment';
			}
			if ( $context === $screen_terms->id ) {
				return 'name';
			}
			if ( $context === $screen_users->id ) {
				return 'username';
			}

			return $default;
		};

		self::add_filter_record( $filters, 'user_has_cap', $cap_filter, 10, 4 );
		self::add_filter_record( $filters, 'comments_pre_query', $comments_pre_query, 10, 2 );
		self::add_filter_record( $filters, 'wp_count_comments', $wp_count_comments_filter, 10, 2 );
		self::add_filter_record( $filters, 'comment_text', $comment_text_filter, 10, 1 );
		self::add_filter_record( $filters, 'term_name', $term_text_filter, 10, 1 );
		self::add_filter_record( $filters, 'term_description', $term_text_filter, 10, 1 );
		self::add_filter_record( $filters, 'post_tag_description', $term_text_filter, 10, 1 );
		self::add_filter_record( $filters, 'editable_slug', $editable_slug_filter, 10, 1 );
		self::add_filter_record( $filters, 'tag_link', $term_link_filter, 10, 1 );
		self::add_filter_record( $filters, 'term_link', $term_link_filter, 10, 1 );
		self::add_filter_record( $filters, 'comments_per_page', $comments_per_page_filter, 10, 2 );
		self::add_filter_record( $filters, 'terms_pre_query', $terms_pre_query, 10, 2 );
		self::add_filter_record( $filters, 'edit_tags_per_page', $terms_per_page_filter, 10, 1 );
		self::add_filter_record( $filters, 'quick_edit_enabled_for_taxonomy', $quick_edit_tax_filter, 10, 2 );
		self::add_filter_record( $filters, 'users_pre_query', $users_pre_query, 10, 2 );
		self::add_filter_record( $filters, 'pre_count_users', $count_users_filter, 10, 3 );
		self::add_filter_record( $filters, 'users_per_page', $users_per_page_filter, 10, 1 );
		self::add_filter_record( $filters, 'hidden_columns', $hidden_filter, 10, 3 );
		self::add_filter_record( $filters, 'list_table_primary_column', $primary_filter, 10, 2 );

		try {
			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'edit-comments.php';
			$GLOBALS['post_id']      = 0;
			$_GET                    = array(
				'comment_status' => 'all',
				'orderby'        => 'comment_author',
				'order'          => 'asc',
				'paged'          => 2,
			);
			$_POST                   = array();
			$_REQUEST                = $_GET;
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/edit-comments.php';
			$_SERVER['REQUEST_URI']  = '/wp-admin/edit-comments.php?comment_status=all&orderby=comment_author&order=asc&paged=2';

			$comments_table = self::list_table( 'WP_Comments_List_Table', $screen_comments );
			$comments_table->prepare_items();
			$comments_column_info = $comments_table->get_column_info();
			$comments_bulk        = self::invoke( $comments_table, 'get_bulk_actions' );
			$comments_views       = self::invoke( $comments_table, 'get_views' );
			$comments_ajax_allowed = $comments_table->ajax_user_can();
			$comments_row         = self::capture(
				static function () use ( $comments_table ): void {
					$comments_table->display();
				}
			);
			$comments_ajax_denied = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_comments ) {
					$table = self::list_table( 'WP_Comments_List_Table', $screen_comments );
					return $table->ajax_user_can();
				}
			);

			$GLOBALS['pagenow']     = 'edit-tags.php';
			$GLOBALS['taxonomy']    = 'post_tag';
			$GLOBALS['post_type']   = 'post';
			$_GET                   = array(
				'taxonomy' => 'post_tag',
				'post_type' => 'post',
				'orderby'  => 'name',
				'order'    => 'asc',
				'paged'    => 2,
			);
			$_POST                  = array();
			$_REQUEST               = $_GET;
			$_SERVER['PHP_SELF']    = '/wp-admin/edit-tags.php';
			$_SERVER['REQUEST_URI'] = '/wp-admin/edit-tags.php?taxonomy=post_tag&post_type=post&orderby=name&order=asc&paged=2';

			$terms_table = self::list_table( 'WP_Terms_List_Table', $screen_terms );
			$terms_table->prepare_items();
			$terms_column_info = $terms_table->get_column_info();
			$terms_bulk        = self::invoke( $terms_table, 'get_bulk_actions' );
			$terms_row         = self::capture(
				static function () use ( $term, $terms_table ): void {
					$terms_table->single_row( $term, 0 );
				}
			);
			$_REQUEST['action']      = 'delete';
			$_REQUEST['delete_tags'] = array( (int) $term->term_id );
			$terms_current_action    = $terms_table->current_action();

			$GLOBALS['pagenow']     = 'users.php';
			$_GET                   = array(
				'orderby' => 'email',
				'order'   => 'desc',
				'paged'   => 2,
				'role'    => 'editor',
			);
			$_POST                  = array();
			$_REQUEST               = $_GET;
			$_SERVER['PHP_SELF']    = '/wp-admin/users.php';
			$_SERVER['REQUEST_URI'] = '/wp-admin/users.php?role=editor&orderby=email&order=desc&paged=2';

			$users_table = self::list_table( 'WP_Users_List_Table', $screen_users );
			$users_table->prepare_items();
			$users_column_info = $users_table->get_column_info();
			$users_bulk        = self::invoke( $users_table, 'get_bulk_actions' );
			$users_views       = self::invoke( $users_table, 'get_views' );
			$user_object       = $users_table->items[ $user_id ] ?? new \WP_User( $user_id );
			$users_row         = $users_table->single_row( $user_object, '', '', 2 );
			$_REQUEST['changeit'] = '1';
			$users_current_action = $users_table->current_action();
			$users_ajax_allowed   = $users_table->ajax_user_can();
			$users_ajax_denied    = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_users ) {
					$table = self::list_table( 'WP_Users_List_Table', $screen_users );
					return $table->ajax_user_can();
				}
			);

			$result = compact(
				'comment',
				'comments_ajax_denied',
				'comments_ajax_allowed',
				'comments_bulk',
				'comments_column_info',
				'comments_row',
				'comments_table',
				'comments_views',
				'query_events',
				'term',
				'terms_bulk',
				'terms_column_info',
				'terms_current_action',
				'terms_row',
				'terms_table',
				'user_id',
				'user_query_events',
				'users_ajax_denied',
				'users_ajax_allowed',
				'users_bulk',
				'users_column_info',
				'users_current_action',
				'users_row',
				'users_table',
				'users_views'
			);
		} finally {
			self::remove_filter_records( $filters );
			$filters_removed = self::filters_removed( $filters );
		}

		$comments_column_info = $result['comments_column_info'] ?? array();
		$terms_column_info    = $result['terms_column_info'] ?? array();
		$users_column_info    = $result['users_column_info'] ?? array();
		$comments_columns     = $comments_column_info[0] ?? array();
		$terms_columns        = $terms_column_info[0] ?? array();
		$users_columns        = $users_column_info[0] ?? array();
		$comments_row         = (string) ( $result['comments_row'] ?? '' );
		$terms_row            = (string) ( $result['terms_row'] ?? '' );
		$users_row            = (string) ( $result['users_row'] ?? '' );

		self::collect_failure(
			$failures,
			count( $result['query_events'] ?? array() ) >= 4
				&& 3 === ( $result['comments_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 3 === ( $result['comments_table']->get_pagination_arg( 'total_pages' ) ?? null )
				&& 1 === ( $result['comments_table']->get_pagination_arg( 'per_page' ) ?? null )
				&& 5 === ( $result['terms_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 3 === ( $result['terms_table']->get_pagination_arg( 'total_pages' ) ?? null )
				&& 2 === ( $result['terms_table']->get_pagination_arg( 'per_page' ) ?? null )
				&& 4 === ( $result['users_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 2 === ( $result['users_table']->get_pagination_arg( 'total_pages' ) ?? null )
				&& 2 === ( $result['users_table']->get_pagination_arg( 'per_page' ) ?? null )
				&& isset( $result['user_query_events'][0]['role'] )
				&& 'editor' === $result['user_query_events'][0]['role'],
			'comments, terms, and users prepare_items use pre-query fixtures and deterministic counts',
			$result
		);

		self::collect_failure(
			$failures,
			self::stable_column_ids( $comments_columns )
				&& self::stable_column_ids( $terms_columns )
				&& self::stable_column_ids( $users_columns )
				&& isset( $comments_columns['author'], $comments_columns['comment'], $comments_columns['response'] )
				&& isset( $terms_columns['name'], $terms_columns['description'], $terms_columns['slug'] )
				&& isset( $users_columns['username'], $users_columns['email'], $users_columns['role'] )
				&& array( 'response' ) === ( $comments_column_info[1] ?? array() )
				&& array( 'slug' ) === ( $terms_column_info[1] ?? array() )
				&& array( 'email' ) === ( $users_column_info[1] ?? array() )
				&& 'comment' === ( $comments_column_info[3] ?? null )
				&& 'name' === ( $terms_column_info[3] ?? null )
				&& 'username' === ( $users_column_info[3] ?? null )
				&& in_array( $screen_comments->id, $hidden_events, true )
				&& in_array( $screen_terms->id, $hidden_events, true )
				&& in_array( $screen_users->id, $hidden_events, true )
				&& in_array( $screen_comments->id, $primary_events, true )
				&& in_array( $screen_terms->id, $primary_events, true )
				&& in_array( $screen_users->id, $primary_events, true ),
			'comments, terms, and users column IDs, hidden columns, sortable columns, and primary columns are stable and screen-local',
			array(
				'commentsColumnInfo' => $comments_column_info,
				'termsColumnInfo'    => $terms_column_info,
				'usersColumnInfo'    => $users_column_info,
			)
		);

		self::collect_failure(
			$failures,
			isset( $result['comments_bulk']['approve'], $result['comments_bulk']['trash'] )
				&& isset( $result['terms_bulk']['delete'] )
				&& isset( $result['users_bulk']['delete'], $result['users_bulk']['resetpassword'] )
				&& 'bulk-delete' === ( $result['terms_current_action'] ?? null )
				&& 'promote' === ( $result['users_current_action'] ?? null )
				&& false === ( $result['comments_ajax_denied'] ?? null )
				&& false === ( $result['users_ajax_denied'] ?? null )
				&& true === ( $result['comments_ajax_allowed'] ?? null )
				&& true === ( $result['users_ajax_allowed'] ?? null ),
			'comments, terms, and users actions and synthetic capability gates are deterministic',
			array(
				'commentsBulk'       => $result['comments_bulk'] ?? array(),
				'termsBulk'          => $result['terms_bulk'] ?? array(),
				'usersBulk'          => $result['users_bulk'] ?? array(),
				'commentsAjaxAllowed' => $result['comments_ajax_allowed'] ?? null,
				'usersAjaxAllowed'   => $result['users_ajax_allowed'] ?? null,
				'termsCurrentAction' => $result['terms_current_action'] ?? null,
				'usersCurrentAction' => $result['users_current_action'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $comments_row, "id='comment-" . (int) $comment->comment_ID . "'" )
				&& str_contains( $comments_row, 'delete_comments[]' )
				&& str_contains( $comments_row, 'the-extra-comment-list' )
				&& str_contains( $terms_row, 'id="tag-' . (int) $term->term_id . '"' )
				&& str_contains( $terms_row, 'delete_tags[]' )
				&& str_contains( $users_row, "id='user-" . (int) $result['user_id'] . "'" )
				&& str_contains( $users_row, 'users[]' )
				&& str_contains( $users_row, 'row-actions' )
				&& self::html_has_no_raw_script( $comments_row . $terms_row . $users_row ),
			'comments, terms, and users row output contains valid IDs, actions, hidden rows, and no raw script leakage',
			array(
				'commentsContainsId'         => str_contains( $comments_row, "id='comment-" . (int) $comment->comment_ID . "'" ),
				'commentsContainsCheckbox'   => str_contains( $comments_row, 'delete_comments[]' ),
				'commentsContainsExtraList'  => str_contains( $comments_row, 'the-extra-comment-list' ),
				'termsContainsId'            => str_contains( $terms_row, 'id="tag-' . (int) $term->term_id . '"' ),
				'termsContainsCheckbox'      => str_contains( $terms_row, 'delete_tags[]' ),
				'usersContainsId'            => str_contains( $users_row, "id='user-" . (int) $result['user_id'] . "'" ),
				'usersContainsCheckbox'      => str_contains( $users_row, 'users[]' ),
				'usersContainsActions'       => str_contains( $users_row, 'row-actions' ),
				'commentsNoScript'           => self::html_has_no_raw_script( $comments_row ),
				'termsNoScript'              => self::html_has_no_raw_script( $terms_row ),
				'usersNoScript'              => self::html_has_no_raw_script( $users_row ),
				'commentsTermsUsersNoScript' => self::html_has_no_raw_script( $comments_row . $terms_row . $users_row ),
				'termsScriptContext'         => self::raw_script_context( $terms_row ),
				'commentsRow'                => $comments_row,
				'termsRow'                   => $terms_row,
				'usersRow'                   => $users_row,
			)
		);

		self::collect_failure(
			$failures,
			isset( $result['comments_views']['all'], $result['comments_views']['mine'], $result['users_views']['all'] )
				&& ( isset( $result['users_views']['editor'] ) || isset( $result['users_views']['none'] ) )
				&& self::html_has_no_raw_script( implode( '', $result['comments_views'] ?? array() ) . implode( '', $result['users_views'] ?? array() ) ),
			'comments and users concrete views render escaped status/role links',
			array(
				'commentsViewKeys' => implode( ',', array_keys( $result['comments_views'] ?? array() ) ),
				'usersViewKeys'    => implode( ',', array_keys( $result['users_views'] ?? array() ) ),
				'viewsNoScript'    => self::html_has_no_raw_script( implode( '', $result['comments_views'] ?? array() ) . implode( '', $result['users_views'] ?? array() ) ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed,
			'comments/terms/users pre-query, count, column, primary, text, and capability filters are removed',
			array( 'filtersRemoved' => $filters_removed )
		);

		return self::row(
			$ctx,
			'admin-list-tables.comments-terms-users.prepare-columns-rows-actions',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screens'  => array( $screen_comments->id, $screen_terms->id, $screen_users->id ),
			)
		);
	}

	private static function check_plugin_theme_tables( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$filters         = array();
		$current_user    = self::synthetic_user( $ctx->fork( 'current-user' ), 54000, 'extensions' );
		$screen_plugins  = self::screen( 'plugins' );
		$screen_themes   = self::screen( 'themes' );
		$plugin_file     = 'cfz-rich-list-table/cfz-rich-list-table.php';
		$hostile_label   = self::hostile_label( $ctx->fork( 'extension-label' ) );
		$theme_slug      = 'cfz-list-table-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 );
		$theme_dir       = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $theme_slug;
		$result          = array();
		$filters_removed = false;

		$all_plugins_filter = static function () use ( $hostile_label, $plugin_file ): array {
			return array(
				$plugin_file => array(
					'Name'        => 'CFZ Plugin ' . $hostile_label,
					'PluginURI'   => 'https://example.test/plugins/?q=' . rawurlencode( $hostile_label ),
					'Version'     => '1.2.3',
					'Description' => 'Plugin description ' . $hostile_label,
					'Author'      => 'Plugin Author ' . $hostile_label,
					'AuthorURI'   => 'https://example.test/authors/plugin',
					'TextDomain'  => 'cfz-rich-list-table',
					'DomainPath'  => '',
					'Network'     => false,
					'RequiresWP'  => '6.0',
					'RequiresPHP' => PHP_VERSION,
					'UpdateURI'   => '',
					'Title'       => 'CFZ Plugin ' . $hostile_label,
					'AuthorName'  => 'Plugin Author ' . $hostile_label,
				),
			);
		};
		$plugin_status_filter = static function ( string $text, int $count, string $type ): string {
			if ( 'cfz-custom' === $type ) {
				return 'Custom <script>alert(1)</script>';
			}

			return $text;
		};
		$plugins_list_filter = static function ( array $plugins ) use ( $plugin_file ): array {
			$plugins['cfz-custom'] = isset( $plugins['all'][ $plugin_file ] ) ? array( $plugin_file => $plugins['all'][ $plugin_file ] ) : array();
			return $plugins;
		};
		$cap_filter = self::cap_filter(
			array(
				'activate_plugins',
				'delete_plugins',
				'install_plugins',
				'read',
				'switch_themes',
				'update_plugins',
			)
		);

		self::add_filter_record( $filters, 'all_plugins', $all_plugins_filter, 10, 1 );
		self::add_filter_record( $filters, 'plugins_list_status_text', $plugin_status_filter, 10, 3 );
		self::add_filter_record( $filters, 'plugins_list', $plugins_list_filter, 10, 1 );
		self::add_filter_record( $filters, 'user_has_cap', $cap_filter, 10, 4 );

		try {
			self::write_theme_fixture( $theme_dir, $hostile_label );
			\register_theme_directory( trailingslashit( WP_CONTENT_DIR ) . 'themes' );
			\search_theme_directories( true );
			\wp_cache_delete( 'theme_roots', 'site-transient' );

			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'plugins.php';
			$GLOBALS['status']       = 'all';
			$GLOBALS['page']         = 1;
			$GLOBALS['s']            = '';
			$_GET                    = array(
				'plugin_status' => 'all',
				'orderby'       => 'name',
				'order'         => 'asc',
				'paged'         => 1,
			);
			$_POST                   = array();
			$_REQUEST                = $_GET;
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/plugins.php';
			$_SERVER['REQUEST_URI']  = '/wp-admin/plugins.php?plugin_status=all&orderby=name&order=asc';

			$plugins_table = self::list_table( 'WP_Plugins_List_Table', $screen_plugins );
			$plugins_table->prepare_items();
			$plugins_column_info = $plugins_table->get_column_info();
			$plugins_bulk        = self::invoke( $plugins_table, 'get_bulk_actions' );
			$plugins_views       = self::invoke( $plugins_table, 'get_views' );
			$plugins_row         = self::capture(
				static function () use ( $plugins_table ): void {
					$plugins_table->display_rows();
				}
			);
			$_POST['clear-recent-list'] = '1';
			$plugins_current_action     = $plugins_table->current_action();
			$plugins_ajax_allowed       = $plugins_table->ajax_user_can();
			$plugins_ajax_denied        = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_plugins ) {
					$table = self::list_table( 'WP_Plugins_List_Table', $screen_plugins );
					return $table->ajax_user_can();
				}
			);

			$GLOBALS['pagenow']     = 'themes.php';
			$_GET                   = array(
				'paged' => 1,
				's'     => 'cfz',
			);
			$_POST                  = array();
			$_REQUEST               = $_GET;
			$_SERVER['PHP_SELF']    = '/wp-admin/themes.php';
			$_SERVER['REQUEST_URI'] = '/wp-admin/themes.php?s=cfz';

			$themes_table = self::list_table( 'WP_Themes_List_Table', $screen_themes );
			$themes_table->prepare_items();
			$themes_columns      = $themes_table->get_columns();
			$themes_row          = self::capture(
				static function () use ( $themes_table ): void {
					$themes_table->display_rows();
				}
			);
			$themes_display      = self::capture(
				static function () use ( $themes_table ): void {
					$themes_table->display();
				}
			);
			$themes_ajax_allowed = $themes_table->ajax_user_can();
			$themes_ajax_denied  = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_themes ) {
					$table = self::list_table( 'WP_Themes_List_Table', $screen_themes );
					return $table->ajax_user_can();
				}
			);

			$result = compact(
				'plugin_file',
				'plugins_ajax_denied',
				'plugins_ajax_allowed',
				'plugins_bulk',
				'plugins_column_info',
				'plugins_current_action',
				'plugins_row',
				'plugins_table',
				'plugins_views',
				'theme_slug',
				'themes_ajax_denied',
				'themes_ajax_allowed',
				'themes_columns',
				'themes_display',
				'themes_row',
				'themes_table'
			);
		} finally {
			self::remove_filter_records( $filters );
			$filters_removed = self::filters_removed( $filters );
			self::remove_theme_fixture( $theme_dir );
			\search_theme_directories( true );
			\wp_cache_delete( 'theme_roots', 'site-transient' );
		}

		$plugins_column_info = $result['plugins_column_info'] ?? array();
		$plugins_columns     = $plugins_column_info[0] ?? array();
		$plugins_row         = (string) ( $result['plugins_row'] ?? '' );
		$themes_row          = (string) ( $result['themes_row'] ?? '' );
		$themes_display      = (string) ( $result['themes_display'] ?? '' );

		self::collect_failure(
			$failures,
			1 === count( $result['plugins_table']->items ?? array() )
				&& 1 === ( $result['plugins_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& isset( $result['plugins_table']->items[ $plugin_file ] )
				&& count( $result['themes_table']->items ?? array() ) >= 1
				&& 36 === ( $result['themes_table']->get_pagination_arg( 'per_page' ) ?? null ),
			'plugins and themes prepare_items use generated local fixtures and deterministic pagination',
			$result
		);

		self::collect_failure(
			$failures,
			self::stable_column_ids( $plugins_columns )
				&& isset( $plugins_columns['cb'], $plugins_columns['name'], $plugins_columns['description'] )
				&& array() === ( $plugins_column_info[1] ?? array() )
				&& 'name' === ( $plugins_column_info[3] ?? null )
				&& array() === ( $result['themes_columns'] ?? null ),
			'plugins columns are stable and themes preserve their concrete non-column grid contract',
			array(
				'pluginsColumnInfo' => $plugins_column_info,
				'themesColumns'     => $result['themes_columns'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			isset( $result['plugins_bulk']['activate-selected'], $result['plugins_bulk']['delete-selected'] )
				&& 'clear-recent-list' === ( $result['plugins_current_action'] ?? null )
				&& false === ( $result['plugins_ajax_denied'] ?? null )
				&& true === ( $result['plugins_ajax_allowed'] ?? null )
				&& false === ( $result['themes_ajax_denied'] ?? null )
				&& true === ( $result['themes_ajax_allowed'] ?? null ),
			'plugins and themes action capability gates respect synthetic capabilities without executing lifecycle operations',
			array(
				'pluginsBulk'          => $result['plugins_bulk'] ?? array(),
				'pluginsCurrentAction' => $result['plugins_current_action'] ?? null,
				'pluginsAjaxAllowed'   => $result['plugins_ajax_allowed'] ?? null,
				'pluginsAjaxDenied'    => $result['plugins_ajax_denied'] ?? null,
				'themesAjaxAllowed'    => $result['themes_ajax_allowed'] ?? null,
				'themesAjaxDenied'     => $result['themes_ajax_denied'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			isset( $result['plugins_views']['all'], $result['plugins_views']['inactive'], $result['plugins_views']['cfz-custom'] )
				&& self::html_has_no_raw_script( implode( '', $result['plugins_views'] ?? array() ) ),
			'plugins views escape generated custom status labels',
			array( 'pluginsViews' => $result['plugins_views'] ?? array() )
		);

		self::collect_failure(
			$failures,
			str_contains( $plugins_row, 'data-plugin="' . \esc_attr( $plugin_file ) . '"' )
				&& str_contains( $plugins_row, 'activate-' )
				&& str_contains( $themes_row, 'available-theme' )
				&& str_contains( $themes_display, '_ajax_fetch_list_nonce' )
				&& self::html_has_no_raw_script( $plugins_row . $themes_row . $themes_display ),
			'plugins and themes row/display output contains fixture actions and no raw script leakage',
			array(
				'pluginsRow'    => self::describe_string( $plugins_row ),
				'themesDisplay' => self::describe_string( $themes_display ),
				'themesRow'     => self::describe_string( $themes_row ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed,
			'plugin/theme fixture, status, list, and capability filters are removed',
			array( 'filtersRemoved' => $filters_removed )
		);

		return self::row(
			$ctx,
			'admin-list-tables.plugins-themes.generated-fixtures-actions-rows',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screens'  => array( $screen_plugins->id, $screen_themes->id ),
			)
		);
	}

	private static function check_plugin_install_table( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures          = array();
		$filters           = array();
		$current_user      = self::synthetic_user( $ctx->fork( 'current-user' ), 54200, 'plugin-install' );
		$screen            = self::screen( 'plugin-install' );
		$hostile_label     = self::hostile_label( $ctx->fork( 'install-label' ) );
		$token             = substr( hash( 'sha1', (string) $ctx->seed() . ':' . (string) $ctx->iteration() ), 0, 10 );
		$install_slug      = 'cfz-install-new-' . $token;
		$update_slug       = 'cfz-install-update-' . $token;
		$installed_slug    = 'cfz-install-installed-' . $token;
		$incompatible_slug = 'cfz-install-incompatible-' . $token;
		$installed_file    = $installed_slug . '/' . $installed_slug . '.php';
		$update_file       = $update_slug . '/' . $update_slug . '.php';
		$installed_dir     = \trailingslashit( WP_PLUGIN_DIR ) . $installed_slug;
		$installed_path    = \trailingslashit( $installed_dir ) . $installed_slug . '.php';
		$marker            = 'cfz-install-marker-' . $token;
		$api_total         = 91;
		$api_plugins       = array(
			self::plugin_install_api_item(
				$install_slug,
				'Installable Plugin ' . $hostile_label,
				'1.0.0',
				'Generated install description ' . $hostile_label,
				array(
					'svg'     => 'https://example.test/icons/' . rawurlencode( $install_slug ) . '.svg?label=' . rawurlencode( $hostile_label ) . '&raw=<script>alert(1)</script>',
					'default' => 'https://example.test/icons/default.png',
				)
			),
			self::plugin_install_api_item(
				$update_slug,
				'Update Plugin ' . $hostile_label,
				'2.0.0',
				'Generated update description ' . $hostile_label,
				array(
					'svg'     => 'javascript:alert(1)',
					'2x'      => 'https://example.test/icons/' . rawurlencode( $update_slug ) . '-2x.png?raw=' . rawurlencode( $hostile_label ),
					'default' => 'https://example.test/icons/default.png',
				)
			),
			self::plugin_install_api_item(
				$installed_slug,
				'Installed Plugin ' . $hostile_label,
				'1.0.0',
				'Generated installed description ' . $hostile_label,
				array(
					'1x'      => 'https://example.test/icons/' . rawurlencode( $installed_slug ) . '.png?raw=' . rawurlencode( $hostile_label ),
					'default' => 'https://example.test/icons/default.png',
				)
			),
			self::plugin_install_api_item(
				$incompatible_slug,
				'Incompatible Plugin ' . $hostile_label,
				'1.0.0',
				'Generated incompatible description ' . $hostile_label,
				array(
					'default' => 'https://example.test/icons/' . rawurlencode( $incompatible_slug ) . '.png?raw=' . rawurlencode( $hostile_label ),
				),
				array(
					'requires'     => '99.0',
					'requires_php' => '999.0',
					'tested'       => '99.0',
				)
			),
		);
		$search_modes      = array(
			array(
				'type' => 'term',
				'term' => 'Generated Search ' . $hostile_label,
			),
			array(
				'type' => 'tag',
				'term' => 'Tag Search ' . $hostile_label,
			),
			array(
				'type' => 'author',
				'term' => 'Author Search ' . $hostile_label,
			),
		);
		$global_names      = array( '_GET', '_POST', '_REQUEST', 'current_user', 'paged', 'pagenow', 'tab', 'tabs', 'term', 'type', 'wp_scripts', 'wp_styles', 'wp_version' );
		$server_names      = array( 'HTTP_HOST', 'PHP_SELF', 'REQUEST_URI' );
		$global_snapshot   = self::snapshot_globals( $global_names );
		$server_snapshot   = self::snapshot_server( $server_names );
		$api_events        = array();
		$table_arg_events  = array();
		$tabs_events       = array();
		$action_events     = array();
		$description_events = array();
		$localized_update_counts = false;
		$mode_results      = array();
		$result            = array();
		$active_mode       = '';
		$filters_removed   = false;
		$globals_restored  = false;
		$fixture_removed   = false;

		$update_plugins = (object) array(
			'last_checked' => 1763980800,
			'checked'      => array(
				$installed_file => '1.0.0',
				$update_file    => '1.0.0',
			),
			'response'     => array(
				$update_file => (object) array(
					'id'            => 'w.org/plugins/' . $update_slug,
					'slug'          => $update_slug,
					'plugin'        => $update_file,
					'new_version'   => '2.0.0',
					'url'           => 'https://example.test/plugins/' . rawurlencode( $update_slug ),
					'package'       => 'https://downloads.example.test/' . rawurlencode( $update_slug ) . '.zip',
					'requires'      => '5.0',
					'requires_php'  => '5.6',
					'requires_plugins' => array(),
				),
			),
			'no_update'    => array(
				$installed_file => (object) array(
					'id'            => 'w.org/plugins/' . $installed_slug,
					'slug'          => $installed_slug,
					'plugin'        => $installed_file,
					'new_version'   => '1.0.0',
					'url'           => 'https://example.test/plugins/' . rawurlencode( $installed_slug ),
					'package'       => '',
					'requires'      => '5.0',
					'requires_php'  => '5.6',
					'requires_plugins' => array(),
				),
			),
		);

		$tabs_filter = static function ( array $tabs ) use ( $token, &$tabs_events ): array {
			$tabs['cfz-custom'] = 'Generated Tab ' . $token;
			$tabs_events[]      = array_keys( $tabs );
			return $tabs;
		};
		$table_api_args_filter = static function ( $args ) use ( $marker, &$table_arg_events ) {
			if ( is_array( $args ) ) {
				$args['cfz_marker'] = $marker;
				$table_arg_events[] = $args;
			}

			return $args;
		};
		$plugins_api_args_filter = static function ( $args, string $action ) use ( &$api_events, &$active_mode ) {
			$api_events[] = array(
				'phase'    => 'args',
				'mode'     => $active_mode,
				'action'   => $action,
				'args'     => self::clone_value( $args ),
			);

			return $args;
		};
		$plugins_api_filter = static function ( $result, string $action, $args ) use ( $api_plugins, $api_total, &$api_events, &$active_mode ) {
			$api_events[] = array(
				'phase'  => 'response',
				'mode'   => $active_mode,
				'action' => $action,
				'args'   => self::clone_value( $args ),
			);

			if ( 'query_plugins' !== $action ) {
				return new \WP_Error(
					'component_fuzz_unexpected_plugins_api_action',
					'Unexpected plugins_api action short-circuited by component fuzz.',
					array( 'action' => $action )
				);
			}

			return (object) array(
				'plugins' => $api_plugins,
				'info'    => array(
					'groups'  => array( 'cfz-group' => 'Performance' ),
					'results' => $api_total,
				),
			);
		};
		$update_plugins_filter = static function () use ( $update_plugins ) {
			return $update_plugins;
		};
		$active_plugins_filter = static function (): array {
			return array();
		};
		$description_filter = static function ( string $description, array $plugin ) use ( $hostile_label, &$description_events ): string {
			$description_events[] = $plugin['slug'] ?? '';
			return \esc_html( $description . ' ' . $hostile_label );
		};
		$action_links_filter = static function ( array $action_links, array $plugin ) use ( $hostile_label, &$action_events ): array {
			$slug            = (string) ( $plugin['slug'] ?? '' );
			$action_events[] = $slug;
			$action_links[]  = sprintf(
				'<a class="cfz-plugin-install-action" href="%s" data-slug="%s">%s</a>',
				\esc_url( 'https://example.test/install-action/?plugin=' . rawurlencode( $slug ) . '&label=' . rawurlencode( $hostile_label ) ),
				\esc_attr( $slug ),
				\esc_html( 'Generated Action ' . $hostile_label )
			);

			return $action_links;
		};
		$cap_filter = self::cap_filter(
			array(
				'activate_plugin',
				'activate_plugins',
				'install_plugins',
				'read',
				'update_core',
				'update_php',
				'update_plugins',
			)
		);

		self::add_filter_record( $filters, 'install_plugins_tabs', $tabs_filter, 10, 1 );
		self::add_filter_record( $filters, 'install_plugins_table_api_args_search', $table_api_args_filter, 10, 1 );
		self::add_filter_record( $filters, 'plugins_api_args', $plugins_api_args_filter, 10, 2 );
		self::add_filter_record( $filters, 'plugins_api', $plugins_api_filter, 10, 3 );
		self::add_filter_record( $filters, 'pre_site_transient_update_plugins', $update_plugins_filter, 10, 2 );
		self::add_filter_record( $filters, 'pre_option_active_plugins', $active_plugins_filter, 10, 3 );
		self::add_filter_record( $filters, 'plugin_install_description', $description_filter, 10, 2 );
		self::add_filter_record( $filters, 'plugin_install_action_links', $action_links_filter, 10, 2 );
		self::add_filter_record( $filters, 'user_has_cap', $cap_filter, 10, 4 );

		try {
			self::write_plugin_fixture( $installed_dir, $installed_path, 'Installed Plugin ' . $hostile_label, '1.0.0' );
			\wp_cache_delete( 'plugins', 'plugins' );

			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'plugin-install.php';
			$GLOBALS['wp_version']   = \wp_get_wp_version();
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/plugin-install.php';
			\wp_register_script( 'updates', false, array(), false, true );

			foreach ( $search_modes as $mode ) {
				$active_mode            = $mode['type'];
				$_GET                   = array(
					'tab'   => 'search',
					'type'  => $mode['type'],
					's'     => $mode['term'],
					'paged' => 2,
					'from'  => 'component-fuzz-' . $hostile_label,
				);
				$_POST                  = array();
				$_REQUEST               = $_GET;
				$_SERVER['REQUEST_URI'] = '/wp-admin/plugin-install.php?tab=search&type=' . rawurlencode( $mode['type'] ) . '&s=' . rawurlencode( $mode['term'] ) . '&paged=2';

				$table = self::list_table( 'WP_Plugin_Install_List_Table', $screen );
				$table->prepare_items();
				$views = self::invoke( $table, 'get_views' );

				$mode_results[ $mode['type'] ] = array(
					'item_count'   => count( $table->items ?? array() ),
					'items'        => array_column( array_map( 'get_object_vars', array_map( static fn( $item ) => (object) $item, $table->items ?? array() ) ), 'slug' ),
					'per_page'     => $table->get_pagination_arg( 'per_page' ),
					'total_items'  => $table->get_pagination_arg( 'total_items' ),
					'total_pages'  => $table->get_pagination_arg( 'total_pages' ),
					'view_keys'    => array_keys( $views ),
					'views_html'   => implode( '', $views ),
				);
				$localized_update_counts = self::script_data_contains_all(
					'updates',
					array( '_wpUpdatesItemCounts', $installed_file, $update_file )
				);

				$result['table'] = $table;
				$result['views'] = $views;
			}

			$row = self::capture(
				static function () use ( $table ): void {
					$table->display_rows();
				}
			);
			$display = self::capture(
				static function () use ( $table ): void {
					$table->display();
				}
			);
			$ajax_allowed = $table->ajax_user_can();
			$ajax_denied  = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen ) {
					$table = self::list_table( 'WP_Plugin_Install_List_Table', $screen );
					return $table->ajax_user_can();
				}
			);
			$icon_sources = self::image_srcs_by_class( $row, 'plugin-icon' );

			$result = array_merge(
				$result,
				compact(
					'action_events',
					'ajax_denied',
					'ajax_allowed',
					'api_events',
					'description_events',
					'display',
					'icon_sources',
					'installed_file',
					'installed_slug',
					'incompatible_slug',
					'install_slug',
					'localized_update_counts',
					'mode_results',
					'row',
					'table_arg_events',
					'tabs_events',
					'update_file',
					'update_slug'
				)
			);
		} finally {
			self::remove_filter_records( $filters );
			$filters_removed = self::filters_removed( $filters );
			self::remove_plugin_fixture( $installed_dir, $installed_path );
			\wp_cache_delete( 'plugins', 'plugins' );
			self::restore_server( $server_snapshot );
			self::restore_globals( $global_snapshot );
			$globals_restored = self::globals_match( $global_snapshot, $global_names ) && self::server_match( $server_snapshot, $server_names );
			$fixture_removed  = ! is_file( $installed_path ) && ! is_dir( $installed_dir );
		}

		$row          = (string) ( $result['row'] ?? '' );
		$display      = (string) ( $result['display'] ?? '' );
		$views_html   = implode( '', array_column( $result['mode_results'] ?? array(), 'views_html' ) );
		$icon_sources = $result['icon_sources'] ?? array();
		$action_slugs = array_values( array_unique( $result['action_events'] ?? array() ) );
		sort( $action_slugs );
		$expected_action_slugs = array( $incompatible_slug, $install_slug, $installed_slug, $update_slug );
		sort( $expected_action_slugs );

		self::collect_failure(
			$failures,
			self::plugin_install_request_events_match( $result['api_events'] ?? array(), $result['table_arg_events'] ?? array(), $search_modes, $marker ),
			'plugin install search tabs and API arguments are generated, mode-specific, and short-circuited before network',
			array(
				'apiEvents'       => $result['api_events'] ?? array(),
				'tableArgEvents'  => $result['table_arg_events'] ?? array(),
				'tabsEvents'      => $result['tabs_events'] ?? array(),
				'expectedModes'   => array_column( $search_modes, 'type' ),
			)
		);

		self::collect_failure(
			$failures,
			self::plugin_install_mode_results_match( $result['mode_results'] ?? array(), array_column( $api_plugins, 'slug' ), $api_total )
				&& self::plugin_install_views_match( $result['mode_results'] ?? array() ),
			'plugin install prepare_items preserves API result ordering, pagination totals, and generated install tabs',
			array(
				'modeResults' => $result['mode_results'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			false === ( $result['ajax_denied'] ?? null )
				&& true === ( $result['ajax_allowed'] ?? null )
				&& str_contains( $row, 'class="install-now button button-compact"' )
				&& str_contains( $row, 'data-slug="' . \esc_attr( $install_slug ) . '"' )
				&& str_contains( $row, 'action=install-plugin' )
				&& str_contains( $row, 'class="update-now button button-compact aria-button-if-js"' )
				&& str_contains( $row, 'data-plugin="' . \esc_attr( $update_file ) . '"' )
				&& str_contains( $row, 'action=upgrade-plugin' )
				&& str_contains( $row, 'class="button button-compact button-primary activate-now"' )
				&& str_contains( $row, 'data-plugin="' . \esc_attr( $installed_file ) . '"' )
				&& str_contains( $row, 'action=activate' )
				&& str_contains( $row, 'cfz-plugin-install-action' )
				&& $expected_action_slugs === $action_slugs,
			'plugin install row actions cover install, update, installed activation, custom action filters, and capability gates',
			array(
				'actionSlugs' => $action_slugs,
				'ajaxAllowed' => $result['ajax_allowed'] ?? null,
				'ajaxDenied'  => $result['ajax_denied'] ?? null,
				'row'         => self::describe_string( $row ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $row, 'button-disabled' )
				&& str_contains( $row, 'compatibility-incompatible' )
				&& str_contains( $row, 'notice-error' )
				&& count( $icon_sources ) >= 4
				&& self::escaped_url_attributes( $icon_sources )
				&& self::html_has_no_raw_script( $row . $display . $views_html ),
			'plugin install compatibility notices, disabled buttons, icon URLs, descriptions, views, and display output are escaped',
			array(
				'iconSources'  => $icon_sources,
				'rowNoScript'  => self::html_has_no_raw_script( $row ),
				'display'      => self::describe_string( $display ),
				'viewsNoScript' => self::html_has_no_raw_script( $views_html ),
				'rowScriptContext' => self::raw_script_context( $row ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed && $globals_restored && $fixture_removed && true === ( $result['localized_update_counts'] ?? null ),
			'plugin install API, transient, action, description, capability filters, localized update-count scripts, globals, server values, plugin cache, and temp fixtures are restored',
			array(
				'filtersRemoved'        => $filters_removed,
				'fixtureRemoved'        => $fixture_removed,
				'globalsRestored'       => $globals_restored,
				'localizedUpdateCounts' => $result['localized_update_counts'] ?? null,
			)
		);

		return self::row(
			$ctx,
			'admin-list-tables.plugin-install.search-api-actions-escaping',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screen'   => $screen->id,
			)
		);
	}

	private static function check_theme_install_table( \ComponentFuzz\FuzzContext $ctx ): array {
		static $theme_install_prepare_items_used = false;

		if ( $theme_install_prepare_items_used || function_exists( 'install_themes_feature_list' ) ) {
			$theme_install_prepare_items_used = true;
			return $ctx->skip(
				'admin-list-tables.theme-install.search-api-actions-escaping',
				'WP_Theme_Install_List_Table::prepare_items() requires theme-install.php with require, so this invariant runs once per PHP process to avoid redeclaring theme-install functions.',
				array( 'prepareItemsAlreadyUsed' => true )
			);
		}

		$theme_install_prepare_items_used = true;

		$failures        = array();
		$filters         = array();
		$current_user    = self::synthetic_user( $ctx->fork( 'current-user' ), 54400, 'theme-install' );
		$screen          = self::screen( 'theme-install' );
		$hostile_label   = self::hostile_label( $ctx->fork( 'theme-install-label' ) );
		$token           = substr( hash( 'sha1', (string) $ctx->seed() . ':' . (string) $ctx->iteration() . ':theme' ), 0, 10 );
		$install_slug    = 'cfz-theme-install-new-' . $token;
		$update_slug     = 'cfz-theme-install-update-' . $token;
		$installed_slug  = 'cfz-theme-install-installed-' . $token;
		$newer_slug      = 'cfz-theme-install-newer-' . $token;
		$theme_root      = \trailingslashit( WP_CONTENT_DIR ) . 'themes';
		$theme_root_preexisting = is_dir( $theme_root );
		$update_dir      = \trailingslashit( $theme_root ) . $update_slug;
		$installed_dir   = \trailingslashit( $theme_root ) . $installed_slug;
		$newer_dir       = \trailingslashit( $theme_root ) . $newer_slug;
		$marker          = 'cfz-theme-install-marker-' . $token;
		$api_total       = 83;
		$api_themes      = array(
			self::theme_install_api_item(
				$install_slug,
				'Installable Theme ' . $hostile_label,
				'1.0.0',
				'Theme install description ' . $hostile_label,
				array(
					'preview_url'    => 'https://preview.example.test/themes/' . rawurlencode( $install_slug ) . '/?label=' . rawurlencode( $hostile_label ),
					'screenshot_url' => 'https://example.test/screens/' . rawurlencode( $install_slug ) . '.png?label=' . rawurlencode( $hostile_label ) . '&raw=<script>alert(1)</script>',
				)
			),
			self::theme_install_api_item(
				$update_slug,
				'Update Theme ' . $hostile_label,
				'2.0.0',
				'Theme update description ' . $hostile_label,
				array(
					'preview_url'    => 'javascript:alert(1)',
					'screenshot_url' => 'https://example.test/screens/' . rawurlencode( $update_slug ) . '.png?raw=' . rawurlencode( $hostile_label ),
				)
			),
			self::theme_install_api_item(
				$installed_slug,
				'Installed Theme ' . $hostile_label,
				'1.0.0',
				'Theme installed description ' . $hostile_label,
				array(
					'preview_url'    => 'https://preview.example.test/themes/' . rawurlencode( $installed_slug ) . '/',
					'screenshot_url' => 'https://example.test/screens/' . rawurlencode( $installed_slug ) . '.png?raw=' . rawurlencode( $hostile_label ),
				)
			),
			self::theme_install_api_item(
				$newer_slug,
				'Newer Installed Theme ' . $hostile_label,
				'0.5.0',
				'Theme newer installed description ' . $hostile_label,
				array(
					'preview_url'    => 'https://preview.example.test/themes/' . rawurlencode( $newer_slug ) . '/',
					'screenshot_url' => 'https://example.test/screens/' . rawurlencode( $newer_slug ) . '.png?raw=' . rawurlencode( $hostile_label ),
				)
			),
		);
		$request_modes   = array(
			array(
				'id'    => 'search-term',
				'tab'   => 'search',
				'type'  => 'term',
				'term'  => 'Generated Theme Search ' . $hostile_label,
				'paged' => 2,
			),
			array(
				'id'    => 'search-tag',
				'tab'   => 'search',
				'type'  => 'tag',
				'term'  => 'grid layout ' . $token . ',accessibility ready',
				'paged' => 2,
			),
			array(
				'id'    => 'search-author',
				'tab'   => 'search',
				'type'  => 'author',
				'term'  => 'Theme Author ' . $hostile_label,
				'paged' => 2,
			),
			array(
				'features' => array( 'blog', 'custom-background', 'full-site-editing' ),
				'id'       => 'feature-filter',
				'tab'      => 'search',
				'type'     => 'term',
				'term'     => 'Feature Theme ' . $hostile_label,
				'paged'    => 2,
			),
			array(
				'browse' => 'featured',
				'id'     => 'browse-featured',
				'tab'    => 'featured',
				'paged'  => 2,
			),
			array(
				'browse' => 'new',
				'id'     => 'browse-new',
				'tab'    => 'new',
				'paged'  => 2,
			),
			array(
				'browse' => 'updated',
				'id'     => 'browse-updated',
				'tab'    => 'updated',
				'paged'  => 2,
			),
		);
		$request_modes   = array( $ctx->choice( $request_modes ) );
		$global_names    = array( '_GET', '_POST', '_REQUEST', 'current_user', 'paged', 'pagenow', 'tab', 'tabs', 'term', 'theme_field_defaults', 'themes_allowedtags', 'type', 'wp_scripts', 'wp_styles', 'wp_theme_directories', 'wp_version' );
		$server_names    = array( 'HTTP_HOST', 'PHP_SELF', 'REQUEST_URI' );
		$global_snapshot = self::snapshot_globals( $global_names );
		$server_snapshot = self::snapshot_server( $server_names );
		$options_snapshot = self::snapshot_options();
		$api_events      = array();
		$table_arg_events = array();
		$tabs_events     = array();
		$action_events   = array();
		$http_events     = array();
		$mode_results    = array();
		$result          = array();
		$active_mode     = '';
		$filters_removed = false;
		$globals_restored = false;
		$options_restored = false;
		$fixtures_removed = false;
		$header_action_restored = false;
		$had_search_form_action = false !== \has_filter( 'install_themes_table_header', 'install_theme_search_form' );

		$tabs_filter = static function ( array $tabs ) use ( $token, &$tabs_events ): array {
			$tabs['cfz-custom'] = 'Generated Theme Tab ' . $token;
			$tabs_events[]      = array_keys( $tabs );
			return $tabs;
		};
		$table_api_args_filter = static function ( $args ) use ( $marker, &$active_mode, &$table_arg_events ) {
			if ( is_array( $args ) ) {
				$args['cfz_marker'] = $marker;
				$table_arg_events[] = array(
					'args' => $args,
					'mode' => $active_mode,
				);
			}

			return $args;
		};
		$themes_api_args_filter = static function ( $args, string $action ) use ( &$active_mode, &$api_events ) {
			$api_events[] = array(
				'action' => $action,
				'args'   => self::clone_value( $args ),
				'mode'   => $active_mode,
				'phase'  => 'args',
			);

			return $args;
		};
		$themes_api_filter = static function ( $result, string $action, $args ) use ( $api_themes, $api_total, &$active_mode, &$api_events ) {
			$api_events[] = array(
				'action' => $action,
				'args'   => self::clone_value( $args ),
				'mode'   => $active_mode,
				'phase'  => 'response',
			);

			if ( 'query_themes' !== $action ) {
				return new \WP_Error(
					'component_fuzz_unexpected_themes_api_action',
					'Unexpected themes_api action short-circuited by component fuzz.',
					array( 'action' => $action )
				);
			}

			return (object) array(
				'info'   => array(
					'page'    => 2,
					'pages'   => (int) ceil( $api_total / 36 ),
					'results' => $api_total,
				),
				'themes' => $api_themes,
			);
		};
		$themes_api_result_filter = static function ( $result, string $action, $args ) use ( &$active_mode, &$api_events ) {
			$api_events[] = array(
				'action' => $action,
				'args'   => self::clone_value( $args ),
				'mode'   => $active_mode,
				'phase'  => 'result',
			);

			return $result;
		};
		$theme_install_actions_filter = static function ( array $actions, \stdClass $theme ) use ( $token, &$action_events ): array {
			$slug            = (string) ( $theme->slug ?? '' );
			$action_events[] = $slug;
			$actions[]       = sprintf(
				'<a class="cfz-theme-install-action" href="%s" data-slug="%s">%s</a>',
				\esc_url( 'https://example.test/theme-install-action/?theme=' . rawurlencode( $slug ) . '&marker=' . rawurlencode( $token ) ),
				\esc_attr( $slug ),
				\esc_html( 'Generated Theme Action ' . $token )
			);

			return $actions;
		};
		$pre_http_request_filter = static function ( $preempt, array $parsed_args, string $url ) use ( &$http_events ) {
			unset( $preempt, $parsed_args );
			$http_events[] = $url;

			return new \WP_Error(
				'component_fuzz_unexpected_theme_install_http',
				'Unexpected HTTP request from WP_Theme_Install_List_Table.',
				array( 'url' => $url )
			);
		};
		$cap_filter = self::cap_filter(
			array(
				'install_themes',
				'read',
				'update_themes',
			)
		);

		self::add_filter_record( $filters, 'install_themes_tabs', $tabs_filter, 10, 1 );
		foreach ( array( 'search', 'featured', 'new', 'updated' ) as $api_tab ) {
			self::add_filter_record( $filters, 'install_themes_table_api_args_' . $api_tab, $table_api_args_filter, 10, 1 );
		}
		self::add_filter_record( $filters, 'themes_api_args', $themes_api_args_filter, 10, 2 );
		self::add_filter_record( $filters, 'themes_api', $themes_api_filter, 10, 3 );
		self::add_filter_record( $filters, 'themes_api_result', $themes_api_result_filter, 10, 3 );
		self::add_filter_record( $filters, 'theme_install_actions', $theme_install_actions_filter, 10, 2 );
		self::add_filter_record( $filters, 'pre_http_request', $pre_http_request_filter, 10, 3 );
		self::add_filter_record( $filters, 'user_has_cap', $cap_filter, 10, 4 );

		try {
			self::write_theme_fixture( $update_dir, 'Theme Update ' . $hostile_label );
			self::write_theme_fixture( $installed_dir, 'Theme Installed ' . $hostile_label );
			self::write_theme_fixture( $newer_dir, 'Theme Newer Installed ' . $hostile_label );
			\register_theme_directory( $theme_root );
			\search_theme_directories( true );
			\wp_cache_delete( 'theme_roots', 'site-transient' );

			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'theme-install.php';
			$GLOBALS['wp_version']   = \wp_get_wp_version();
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/theme-install.php';

			foreach ( $request_modes as $mode ) {
				$active_mode = $mode['id'];
				$request     = array(
					'paged' => $mode['paged'],
					'tab'   => $mode['tab'],
				);

				if ( isset( $mode['type'] ) ) {
					$request['type'] = $mode['type'];
					$request['s']    = $mode['term'];
				}
				if ( isset( $mode['features'] ) ) {
					$request['features'] = $mode['features'];
				}

				$_GET                   = $request;
				$_POST                  = array();
				$_REQUEST               = $request;
				$_SERVER['REQUEST_URI'] = '/wp-admin/theme-install.php?' . http_build_query( $request, '', '&', PHP_QUERY_RFC3986 );

				$table = self::list_table( 'WP_Theme_Install_List_Table', $screen );
				$table->prepare_items();
				$views = self::invoke( $table, 'get_views' );

				$mode_results[ $mode['id'] ] = array(
					'features'    => $table->features,
					'item_count'  => count( $table->items ?? array() ),
					'items'       => array_map(
						static function ( $item ): string {
							return (string) ( $item->slug ?? '' );
						},
						$table->items ?? array()
					),
					'per_page'    => $table->get_pagination_arg( 'per_page' ),
					'total_items' => $table->get_pagination_arg( 'total_items' ),
					'total_pages' => $table->get_pagination_arg( 'total_pages' ),
					'view_keys'   => array_keys( $views ),
					'views_html'  => implode( '', $views ),
				);

				$result['table'] = $table;
				$result['views'] = $views;
			}

			$row = self::capture(
				static function () use ( $table ): void {
					$table->display_rows();
				}
			);
			$display = self::capture(
				static function () use ( $table ): void {
					$table->display();
				}
			);
			$ajax_allowed = $table->ajax_user_can();
			$ajax_denied  = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen ) {
					$table = self::list_table( 'WP_Theme_Install_List_Table', $screen );
					return $table->ajax_user_can();
				}
			);
			$image_sources = self::image_srcs( $row );

			$result = array_merge(
				$result,
				compact(
					'action_events',
					'ajax_denied',
					'ajax_allowed',
					'api_events',
					'display',
					'http_events',
					'image_sources',
					'install_slug',
					'installed_slug',
					'mode_results',
					'row',
					'table_arg_events',
					'tabs_events',
					'update_slug'
				)
			);
		} finally {
			self::remove_filter_records( $filters );
			if ( ! $had_search_form_action ) {
				\remove_filter( 'install_themes_table_header', 'install_theme_search_form', 10 );
			}
			$filters_removed = self::filters_removed( $filters );
			$header_action_restored = $had_search_form_action === ( false !== \has_filter( 'install_themes_table_header', 'install_theme_search_form' ) );
			self::remove_theme_fixture( $update_dir );
			self::remove_theme_fixture( $installed_dir );
			self::remove_theme_fixture( $newer_dir );
			self::restore_server( $server_snapshot );
			self::restore_globals( $global_snapshot );
			self::reset_theme_directory_cache_after_restore( $global_snapshot, $theme_root );
			if ( ! $theme_root_preexisting && is_dir( $theme_root ) ) {
				@rmdir( $theme_root );
			}
			\wp_cache_delete( 'theme_roots', 'site-transient' );
			self::restore_options( $options_snapshot );
			\wp_cache_delete( 'theme_roots', 'site-transient' );
			$globals_restored = self::globals_match( $global_snapshot, $global_names ) && self::server_match( $server_snapshot, $server_names );
			$options_restored = self::options_match( $options_snapshot );
			$fixtures_removed = ! is_dir( $update_dir ) && ! is_dir( $installed_dir ) && ! is_dir( $newer_dir )
				&& ( $theme_root_preexisting || ! is_dir( $theme_root ) );
		}

		$row             = (string) ( $result['row'] ?? '' );
		$display         = (string) ( $result['display'] ?? '' );
		$views_html      = implode( '', array_column( $result['mode_results'] ?? array(), 'views_html' ) );
		$image_sources   = $result['image_sources'] ?? array();
		$action_slugs    = array_values( array_unique( $result['action_events'] ?? array() ) );
		$expected_slugs  = array_column( $api_themes, 'slug' );
		$expected_action_slugs = $expected_slugs;
		sort( $action_slugs );
		sort( $expected_action_slugs );

		self::collect_failure(
			$failures,
			self::theme_install_request_events_match( $result['api_events'] ?? array(), $result['table_arg_events'] ?? array(), $request_modes, $marker ),
			'theme install selected request mode API arguments are generated and short-circuited before network',
			array(
				'apiEvents'      => $result['api_events'] ?? array(),
				'httpEvents'     => $result['http_events'] ?? array(),
				'selectedMode'   => $request_modes[0]['id'] ?? '',
				'tableArgEvents' => $result['table_arg_events'] ?? array(),
				'tabsEvents'     => $result['tabs_events'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			array() === ( $result['http_events'] ?? array() )
				&& self::theme_install_mode_results_match( $result['mode_results'] ?? array(), $expected_slugs, $api_total, $request_modes )
				&& self::theme_install_views_match( $result['mode_results'] ?? array() ),
			'theme install prepare_items preserves selected-mode API result ordering, pagination totals, generated tabs, and features when selected',
			array(
				'httpEvents'  => $result['http_events'] ?? array(),
				'modeResults' => $result['mode_results'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			false === ( $result['ajax_denied'] ?? null )
				&& true === ( $result['ajax_allowed'] ?? null )
				&& str_contains( $row, 'action=install-theme' )
				&& str_contains( $row, 'theme=' . rawurlencode( $install_slug ) )
				&& str_contains( $row, 'action=upgrade-theme' )
				&& str_contains( $row, 'theme=' . rawurlencode( $update_slug ) )
				&& substr_count( $row, '<span class="install-now">' ) >= 2
				&& substr_count( $row, '<span class="theme-install">' ) >= 2
				&& str_contains( $row, 'cfz-theme-install-action' )
				&& $expected_action_slugs === $action_slugs,
			'theme install rows cover install, update, latest-installed and newer-installed states, custom action filters, and capability gates',
			array(
				'actionSlugs' => $action_slugs,
				'ajaxAllowed' => $result['ajax_allowed'] ?? null,
				'ajaxDenied'  => $result['ajax_denied'] ?? null,
				'row'         => self::describe_string( $row ),
			)
		);

		self::collect_failure(
			$failures,
			count( $image_sources ) >= count( $expected_slugs )
				&& self::escaped_url_attributes_with_prefix( $image_sources, 'https://example.test/screens/' )
				&& str_contains( $row, 'tab=theme-information' )
				&& str_contains( $row, 'class="theme-preview-url"' )
				&& self::html_has_no_raw_script( $row . $display . $views_html ),
			'theme install screenshots, preview metadata, descriptions, views, and display output are escaped',
			array(
				'display'          => self::describe_string( $display ),
				'imageSources'     => $image_sources,
				'rowNoScript'      => self::html_has_no_raw_script( $row ),
				'rowScriptContext' => self::raw_script_context( $row ),
				'viewsNoScript'    => self::html_has_no_raw_script( $views_html ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed && $header_action_restored && $globals_restored && $options_restored && $fixtures_removed,
			'theme install API, tab, action, capability, HTTP guard filters, header action, globals, server values, theme-root options/cache, and temp fixtures are restored',
			array(
				'filtersRemoved'      => $filters_removed,
				'fixturesRemoved'     => $fixtures_removed,
				'globalsRestored'     => $globals_restored,
				'headerActionRestored' => $header_action_restored,
				'optionsRestored'      => $options_restored,
			)
		);

		return self::row(
			$ctx,
			'admin-list-tables.theme-install.search-api-actions-escaping',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screen'   => $screen->id,
				'selectedMode' => $request_modes[0]['id'] ?? '',
			)
		);
	}

	private static function check_network_themes_table( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$filters         = array();
		$current_user    = self::synthetic_user( $ctx->fork( 'current-user' ), 54800, 'network-themes' );
		$screen          = self::screen( 'themes-network' );
		$enabled_slug    = 'cfz-network-enabled';
		$disabled_slug   = 'cfz-network-secondary';
		$theme_root      = trailingslashit( WP_CONTENT_DIR ) . 'themes';
		$enabled_dir     = trailingslashit( $theme_root ) . $enabled_slug;
		$disabled_dir    = trailingslashit( $theme_root ) . $disabled_slug;
		$hostile_label   = self::hostile_label( $ctx->fork( 'network-theme-label' ) );
		$custom_events   = array();
		$result          = array();
		$filters_removed = false;

		$all_themes_filter = static function ( array $themes ) use ( $disabled_slug, $enabled_slug, $theme_root ): array {
			unset( $themes );

			return array(
				$enabled_slug  => \wp_get_theme( $enabled_slug, $theme_root ),
				$disabled_slug => \wp_get_theme( $disabled_slug, $theme_root ),
			);
		};
		$allowed_themes_filter = static function () use ( $enabled_slug ): array {
			return array( $enabled_slug => true );
		};
		$update_themes_filter = static function () use ( $disabled_slug, $enabled_slug ) {
			return (object) array(
				'last_checked' => 1763980800,
				'checked'      => array(
					$enabled_slug  => '1.0.0',
					$disabled_slug => '1.0.0',
				),
				'response'     => array(
					$disabled_slug => (object) array(
						'theme'        => $disabled_slug,
						'new_version'  => '9.9.9',
						'url'          => 'https://example.test/themes/update/' . rawurlencode( $disabled_slug ),
						'package'      => '',
						'requires'     => '6.0',
						'requires_php' => PHP_VERSION,
					),
				),
				'no_update'    => array(
					$enabled_slug => (object) array(
						'theme'        => $enabled_slug,
						'new_version'  => '1.0.0',
						'url'          => 'https://example.test/themes/current/' . rawurlencode( $enabled_slug ),
						'package'      => '',
						'requires'     => '6.0',
						'requires_php' => PHP_VERSION,
					),
				),
			);
		};
		$auto_update_themes_filter = static function () use ( $enabled_slug ): array {
			return array( $enabled_slug );
		};
		$themes_auto_update_filter = static function (): bool {
			return true;
		};
		$columns_filter = static function ( array $columns ) use ( $hostile_label ): array {
			$columns['cfz_ms_theme'] = \esc_html( 'Generated Network Theme ' . $hostile_label );
			return $columns;
		};
		$custom_column_action = static function ( string $column_name, string $stylesheet, \WP_Theme $theme ) use ( &$custom_events ): void {
			$custom_events[] = array(
				'column'     => $column_name,
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
			);

			if ( 'cfz_ms_theme' === $column_name ) {
				echo '<span class="cfz-ms-theme" data-theme="' . \esc_attr( $stylesheet ) . '">' . \esc_html( $theme->get( 'Name' ) ) . '</span>';
			}
		};
		$active_theme_filter = static function (): string {
			return 'cfz-active-theme-not-under-test';
		};
		$cap_filter = self::cap_filter(
			array(
				'delete_themes',
				'manage_network_themes',
				'read',
				'update_themes',
			)
		);

		self::add_filter_record( $filters, 'all_themes', $all_themes_filter, 10, 1 );
		self::add_filter_record( $filters, 'allowed_themes', $allowed_themes_filter, 10, 1 );
		self::add_filter_record( $filters, 'pre_site_transient_update_themes', $update_themes_filter, 10, 1 );
		self::add_filter_record( $filters, 'pre_site_option_auto_update_themes', $auto_update_themes_filter, 10, 3 );
		self::add_filter_record( $filters, 'themes_auto_update_enabled', $themes_auto_update_filter, 10, 1 );
		self::add_filter_record( $filters, "manage_{$screen->id}_columns", $columns_filter, 10, 1 );
		self::add_filter_record( $filters, 'manage_themes_custom_column', $custom_column_action, 10, 3 );
		self::add_filter_record( $filters, 'pre_option_stylesheet', $active_theme_filter, 10, 3 );
		self::add_filter_record( $filters, 'pre_option_template', $active_theme_filter, 10, 3 );
		self::add_filter_record( $filters, 'user_has_cap', $cap_filter, 10, 4 );

		try {
			self::write_theme_fixture( $enabled_dir, 'Network Enabled ' . $hostile_label );
			self::write_theme_fixture( $disabled_dir, 'Network Disabled ' . $hostile_label );
			\register_theme_directory( $theme_root );
			\search_theme_directories( true );
			\wp_cache_delete( 'theme_roots', 'site-transient' );

			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'themes.php';
			$GLOBALS['status']       = 'all';
			$GLOBALS['page']         = 1;
			$GLOBALS['s']            = '';
			$_GET                    = array(
				'theme_status' => 'all',
				'orderby'      => 'name',
				'order'        => 'asc',
				'paged'        => 1,
			);
			$_POST                   = array();
			$_REQUEST                = $_GET;
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/network/themes.php';
			$_SERVER['REQUEST_URI']  = '/wp-admin/network/themes.php?theme_status=all&orderby=name&order=asc';

			$table = self::list_table( 'WP_MS_Themes_List_Table', $screen );
			$table->prepare_items();

			$column_info  = $table->get_column_info();
			$bulk         = self::invoke( $table, 'get_bulk_actions' );
			$views        = self::invoke( $table, 'get_views' );
			$row          = self::capture(
				static function () use ( $table ): void {
					$table->display_rows();
				}
			);
			$ajax_allowed = $table->ajax_user_can();
			$ajax_denied  = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen ) {
					$table = self::list_table( 'WP_MS_Themes_List_Table', $screen );
					return $table->ajax_user_can();
				}
			);

			$result = compact(
				'ajax_denied',
				'ajax_allowed',
				'bulk',
				'column_info',
				'custom_events',
				'disabled_slug',
				'enabled_slug',
				'row',
				'table',
				'views'
			);
		} finally {
			self::remove_filter_records( $filters );
			$filters_removed = self::filters_removed( $filters );
			self::remove_theme_fixture( $enabled_dir );
			self::remove_theme_fixture( $disabled_dir );
			\search_theme_directories( true );
			\wp_cache_delete( 'theme_roots', 'site-transient' );
		}

		$column_info   = $result['column_info'] ?? array();
		$columns       = $column_info[0] ?? array();
		$sortable      = $column_info[2] ?? array();
		$primary       = $column_info[3] ?? null;
		$row           = (string) ( $result['row'] ?? '' );
		$views_html    = implode( '', $result['views'] ?? array() );
		$enabled_slug  = (string) ( $result['enabled_slug'] ?? $enabled_slug );
		$disabled_slug = (string) ( $result['disabled_slug'] ?? $disabled_slug );
		$is_multisite  = \is_multisite();
		$custom_columns = array_values( array_unique( array_column( $custom_events, 'column' ) ) );
		$custom_stylesheets = array_values( array_unique( array_column( $custom_events, 'stylesheet' ) ) );
		$expected_custom_stylesheets = array( $disabled_slug, $enabled_slug );
		$expected_view_keys = array( 'all', 'enabled', 'upgrade', 'auto-update-enabled', 'auto-update-disabled' );
		if ( $is_multisite ) {
			$expected_view_keys[] = 'disabled';
		}
		sort( $custom_stylesheets );
		sort( $expected_custom_stylesheets );

		self::collect_failure(
			$failures,
			2 === count( $result['table']->items ?? array() )
				&& isset( $result['table']->items[ $enabled_slug ], $result['table']->items[ $disabled_slug ] )
				&& 2 === ( $result['table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 1 === ( $result['table']->get_pagination_arg( 'total_pages' ) ?? null )
				&& array() === array_diff( $expected_view_keys, array_keys( $result['views'] ?? array() ) ),
			'network themes prepare_items partitions generated allowed, upgrade, and auto-update fixtures',
			array(
				'expectedViewKeys' => $expected_view_keys,
				'itemKeys'         => array_keys( $result['table']->items ?? array() ),
				'isMultisite'      => $is_multisite,
				'views'            => $result['views'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			self::stable_column_ids( $columns )
				&& isset( $columns['cb'], $columns['name'], $columns['description'], $columns['auto-updates'], $columns['cfz_ms_theme'] )
				&& isset( $sortable['name'] )
				&& 'name' === $primary
				&& $expected_custom_stylesheets === $custom_stylesheets
				&& array( 'cfz_ms_theme' ) === $custom_columns,
			'network themes columns, sortable primary column, and custom-column dispatch are stable',
			array(
				'columnInfo'        => $column_info,
				'customColumns'     => $custom_columns,
				'customEvents'      => $custom_events,
				'customStylesheets' => $custom_stylesheets,
			)
		);

		self::collect_failure(
			$failures,
			isset(
				$result['bulk']['enable-selected'],
				$result['bulk']['disable-selected'],
				$result['bulk']['update-selected'],
				$result['bulk']['delete-selected'],
				$result['bulk']['enable-auto-update-selected'],
				$result['bulk']['disable-auto-update-selected']
			)
				&& false === ( $result['ajax_denied'] ?? null )
				&& true === ( $result['ajax_allowed'] ?? null ),
			'network themes bulk actions and ajax capability gates respect synthetic capabilities',
			array(
				'ajaxAllowed' => $result['ajax_allowed'] ?? null,
				'ajaxDenied'  => $result['ajax_denied'] ?? null,
				'bulk'        => $result['bulk'] ?? array(),
			)
		);

		$enable_disabled_nonce  = \wp_create_nonce( 'enable-theme_' . $disabled_slug );
		$disable_enabled_nonce  = \wp_create_nonce( 'disable-theme_' . $enabled_slug );
		$disable_disabled_nonce = \wp_create_nonce( 'disable-theme_' . $disabled_slug );
		$delete_nonce           = \wp_create_nonce( 'bulk-themes' );
		$updates_nonce          = \wp_create_nonce( 'updates' );
		$disabled_theme_action  = $is_multisite
			? str_contains( $row, 'action=enable' )
				&& str_contains( $row, 'theme=' . rawurlencode( $disabled_slug ) )
				&& str_contains( $row, '_wpnonce=' . $enable_disabled_nonce )
				&& str_contains( $row, 'action=delete-selected' )
				&& str_contains( $row, '_wpnonce=' . $delete_nonce )
			: str_contains( $row, 'action=disable' )
				&& str_contains( $row, 'theme=' . rawurlencode( $disabled_slug ) )
				&& str_contains( $row, '_wpnonce=' . $disable_disabled_nonce )
				&& ! str_contains( $row, 'action=delete-selected' );

		self::collect_failure(
			$failures,
			str_contains( $row, 'data-slug="' . \esc_attr( $enabled_slug ) . '"' )
				&& str_contains( $row, 'data-slug="' . \esc_attr( $disabled_slug ) . '"' )
				&& str_contains( $row, 'name="checked[]"' )
				&& str_contains( $row, 'action=disable' )
				&& str_contains( $row, 'theme=' . rawurlencode( $enabled_slug ) )
				&& str_contains( $row, '_wpnonce=' . $disable_enabled_nonce )
				&& $disabled_theme_action
				&& str_contains( $row, 'action=enable-auto-update' )
				&& str_contains( $row, 'action=disable-auto-update' )
				&& str_contains( $row, '_wpnonce=' . $updates_nonce )
				&& str_contains( $row, 'cfz-ms-theme' )
				&& self::html_has_no_raw_script( $row . $views_html ),
			'network theme row actions render exact generated theme URLs, nonces, auto-update controls, custom cells, and escaped metadata',
			array(
				'containsCheckbox'            => str_contains( $row, 'name="checked[]"' ),
				'containsCustomCell'          => str_contains( $row, 'cfz-ms-theme' ),
				'containsDisabledThemeAction' => $disabled_theme_action,
				'containsDisabledDisableNonce' => str_contains( $row, '_wpnonce=' . $disable_disabled_nonce ),
				'containsDisabledEnableNonce' => str_contains( $row, '_wpnonce=' . $enable_disabled_nonce ),
				'containsDisabledSlug'        => str_contains( $row, 'data-slug="' . \esc_attr( $disabled_slug ) . '"' ),
				'containsEnableAutoUpdate'    => str_contains( $row, 'action=enable-auto-update' ),
				'containsEnabledDisableNonce' => str_contains( $row, '_wpnonce=' . $disable_enabled_nonce ),
				'containsEnabledSlug'         => str_contains( $row, 'data-slug="' . \esc_attr( $enabled_slug ) . '"' ),
				'containsDisableAutoUpdate'   => str_contains( $row, 'action=disable-auto-update' ),
				'containsDeleteNonce'         => str_contains( $row, '_wpnonce=' . $delete_nonce ),
				'containsUpdatesNonce'        => str_contains( $row, '_wpnonce=' . $updates_nonce ),
				'isMultisite'                 => $is_multisite,
				'rowNoScript'                 => self::html_has_no_raw_script( $row ),
				'viewsNoScript'               => self::html_has_no_raw_script( $views_html ),
				'row'                         => self::describe_string( $row ),
				'rowScriptContext'            => self::raw_script_context( $row ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed,
			'network theme fixture, option, column, update, auto-update, and capability filters are removed',
			array( 'filtersRemoved' => $filters_removed )
		);

		return self::row(
			$ctx,
			'admin-list-tables.network-themes.generated-actions-nonces-columns',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screen'   => $screen->id,
			)
		);
	}

	private static function check_application_passwords_table( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures         = array();
		$filters          = array();
		$current_user     = self::synthetic_user( $ctx->fork( 'current-user' ), 54500, 'app-passwords' );
		$screen           = self::screen( 'application-passwords-user-' . $ctx->iteration() . '-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
		$first_password   = self::synthetic_application_password( $ctx->fork( 'first-password' ), 0 );
		$second_password  = self::synthetic_application_password( $ctx->fork( 'second-password' ), 1 );
		$passwords        = array( $first_password, $second_password );
		$custom_events    = array();
		$template_events  = array();
		$hidden_events    = array();
		$primary_events   = array();
		$result           = array();
		$filters_removed  = false;

		$meta_filter = static function ( $value, int $object_id, string $meta_key, bool $single ) use ( $current_user, $passwords ) {
			if (
				$object_id === (int) $current_user->ID
				&& '_application_passwords' === $meta_key
				&& $single
			) {
				return array( $passwords );
			}

			return $value;
		};
		$columns_filter = static function ( array $columns ): array {
			$columns['cfz_ap_custom'] = \esc_html( 'Generated <script>alert(1)</script> Application Password Column' );
			return $columns;
		};
		$hidden_filter = static function ( array $hidden, \WP_Screen $current_screen ) use ( $screen, &$hidden_events ): array {
			$hidden_events[] = $current_screen->id;

			if ( $current_screen->id === $screen->id ) {
				return array( 'last_ip' );
			}

			return $hidden;
		};
		$primary_filter = static function ( string $default, string $context ) use ( $screen, &$primary_events ): string {
			$primary_events[] = $context;

			if ( $context === $screen->id ) {
				return 'name';
			}

			return $default;
		};
		$custom_column_action = static function ( string $column_name, array $item ) use ( &$custom_events ): void {
			$custom_events[] = array(
				'column' => $column_name,
				'uuid'   => $item['uuid'] ?? '',
			);

			if ( 'cfz_ap_custom' === $column_name ) {
				echo '<span class="cfz-ap-custom" data-uuid="' . \esc_attr( (string) ( $item['uuid'] ?? '' ) ) . '">custom</span>';
			}
		};
		$template_column_action = static function ( string $column_name ) use ( &$template_events ): void {
			$template_events[] = $column_name;

			if ( 'cfz_ap_custom' === $column_name ) {
				echo '<span class="cfz-ap-template">{{ data.uuid }}</span>';
			}
		};

		self::add_filter_record( $filters, 'get_user_metadata', $meta_filter, 10, 5 );
		self::add_filter_record( $filters, "manage_{$screen->id}_columns", $columns_filter, 10, 1 );
		self::add_filter_record( $filters, 'hidden_columns', $hidden_filter, 10, 3 );
		self::add_filter_record( $filters, 'list_table_primary_column', $primary_filter, 10, 2 );
		self::add_filter_record( $filters, "manage_{$screen->id}_custom_column", $custom_column_action, 10, 2 );
		self::add_filter_record( $filters, "manage_{$screen->id}_custom_column_js_template", $template_column_action, 10, 1 );

		try {
			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['user_id']      = (int) $current_user->ID;
			$GLOBALS['pagenow']      = 'user-edit.php';
			$_GET                    = array( 'user_id' => (string) $current_user->ID );
			$_POST                   = array();
			$_REQUEST                = $_GET;
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/user-edit.php';
			$_SERVER['REQUEST_URI']  = '/wp-admin/user-edit.php?user_id=' . (int) $current_user->ID;

			$table = self::list_table( 'WP_Application_Passwords_List_Table', $screen );
			$table->prepare_items();

			$column_info = $table->get_column_info();
			$row         = self::capture(
				static function () use ( $table ): void {
					$table->single_row( $table->items[0] );
				}
			);
			$display     = self::capture(
				static function () use ( $table ): void {
					$table->display();
				}
			);
			$template    = self::capture(
				static function () use ( $table ): void {
					$table->print_js_template_row();
				}
			);

			$result = compact(
				'column_info',
				'custom_events',
				'display',
				'first_password',
				'row',
				'second_password',
				'table',
				'template',
				'template_events'
			);
		} finally {
			self::remove_filter_records( $filters );
			$filters_removed = self::filters_removed( $filters );
		}

		$column_info     = $result['column_info'] ?? array();
		$columns         = $column_info[0] ?? array();
		$hidden          = $column_info[1] ?? array();
		$primary         = $column_info[3] ?? null;
		$row             = (string) ( $result['row'] ?? '' );
		$display         = (string) ( $result['display'] ?? '' );
		$template        = (string) ( $result['template'] ?? '' );
		$prepared_items  = $result['table']->items ?? array();
		$first_prepared  = $prepared_items[0] ?? array();
		$second_prepared = $prepared_items[1] ?? array();

		self::collect_failure(
			$failures,
			2 === count( $prepared_items )
				&& ( $result['second_password']['uuid'] ?? null ) === ( $first_prepared['uuid'] ?? null )
				&& ( $result['first_password']['uuid'] ?? null ) === ( $second_prepared['uuid'] ?? null ),
			'application passwords prepare_items reads filtered user meta and reverses newest credentials first',
			array(
				'preparedUuids' => array_column( $prepared_items, 'uuid' ),
				'fixtureUuids'  => array( $first_password['uuid'], $second_password['uuid'] ),
			)
		);

		self::collect_failure(
			$failures,
			self::stable_column_ids( $columns )
				&& isset( $columns['name'], $columns['created'], $columns['last_used'], $columns['last_ip'], $columns['revoke'], $columns['cfz_ap_custom'] )
				&& array( 'last_ip' ) === $hidden
				&& 'name' === $primary
				&& in_array( $screen->id, $hidden_events, true )
				&& in_array( $screen->id, $primary_events, true ),
			'application password columns, hidden columns, and primary column are stable and screen-local',
			array( 'columnInfo' => $column_info )
		);

		self::collect_failure(
			$failures,
			str_contains( $row, 'data-uuid="' . \esc_attr( $second_password['uuid'] ) . '"' )
				&& str_contains( $row, \esc_html( $second_password['name'] ) )
				&& ! str_contains( $row, $second_password['password'] )
				&& str_contains( $row, $second_password['last_ip'] )
				&& str_contains( $row, 'revoke-application-password-' . \esc_attr( $second_password['uuid'] ) )
				&& str_contains( $row, 'cfz-ap-custom' )
				&& array( 'cfz_ap_custom' ) === array_values( array_unique( array_column( $custom_events, 'column' ) ) )
				&& self::html_has_no_raw_script( $row ),
			'application password row output escapes generated names, renders bounded IP literals, hides hashes, renders revoke/custom controls, and has no raw script leakage',
			array(
				'customEvents' => $custom_events,
				'row'          => self::describe_string( $row ),
				'rowScript'    => self::raw_script_context( $row ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $display, 'revoke-all-application-passwords' )
				&& str_contains( $display, 'data-uuid="' . \esc_attr( $second_password['uuid'] ) . '"' )
				&& str_contains( $template, 'data-uuid="{{ data.uuid }}"' )
				&& str_contains( $template, 'wp.date.dateI18n' )
				&& str_contains( $template, 'cfz-ap-template' )
				&& in_array( 'cfz_ap_custom', $template_events, true )
				&& self::html_has_no_raw_script( $display . $template ),
			'application password full display and JS template preserve row UUIDs, date formatting, revoke-all tablenav, and custom template hooks',
			array(
				'display'        => self::describe_string( $display ),
				'template'       => self::describe_string( $template ),
				'templateEvents' => $template_events,
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed,
			'application password metadata, column, hidden, primary, and custom-column filters are removed',
			array( 'filtersRemoved' => $filters_removed )
		);

		return self::row(
			$ctx,
			'admin-list-tables.application-passwords.user-meta-rows-template',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screen'   => $screen->id,
			)
		);
	}

	private static function check_application_passwords_last_ip_boundary( \ComponentFuzz\FuzzContext $ctx ): array {
		$item   = self::synthetic_application_password( $ctx->fork( 'password' ), 9 );
		$screen = self::screen( 'application-passwords-user-last-ip-' . $ctx->iteration() );
		$table  = self::list_table( 'WP_Application_Passwords_List_Table', $screen );

		$item['last_ip'] = '198.51.100.' . $ctx->int( 1, 254 ) . '<script>alert(1)</script>';
		$row             = self::capture(
			static function () use ( $item, $table ): void {
				$table->single_row( $item );
			}
		);

		if ( ! self::html_has_no_raw_script( $row ) ) {
			return $ctx->skip(
				'admin-list-tables.application-passwords.last-ip-escaped',
				'Current core prints stored application-password last_ip values without escaping; hostile last_ip row coverage is documented as a boundary until core changes.',
				array(
					'rowScript' => self::raw_script_context( $row ),
				)
			);
		}

		if ( ! str_contains( $row, \esc_html( $item['last_ip'] ) ) ) {
			return $ctx->fail(
				'admin-list-tables.application-passwords.last-ip-escaped',
				array(
					'expectedEscapedLastIp' => \esc_html( $item['last_ip'] ),
					'row'                   => self::describe_string( $row ),
				)
			);
		}

		return $ctx->pass(
			'admin-list-tables.application-passwords.last-ip-escaped',
			array(
				'escapedLastIpPresent' => true,
				'row'                  => self::describe_string( $row ),
			)
		);
	}

	private static function check_base_pagination_per_page_output( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures           = array();
		$filters            = array();
		$screen             = self::screen( 'cfz-base-pagination-' . $ctx->iteration() . '-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
		$current_user       = self::synthetic_user( $ctx->fork( 'current-user' ), 55500, 'base-pagination' );
		$absent_option      = 'cfz_base_absent_per_page_' . $ctx->iteration();
		$invalid_option     = 'cfz_base_invalid_per_page_' . $ctx->iteration();
		$per_page_events    = array();
		$user_option_events = array();
		$result             = array();
		$filters_removed    = false;
		$restored           = false;
		$probe              = null;
		$local_snapshot     = self::snapshot_globals(
			array(
				'_GET',
				'_POST',
				'_REQUEST',
				'current_screen',
				'current_user',
				'pagenow',
			)
		);
		$server_snapshot = self::snapshot_server( array( 'HTTP_HOST', 'REQUEST_URI', 'PHP_SELF' ) );

		$absent_per_page_filter = static function ( int $per_page ) use ( &$per_page_events ): int {
			$per_page_events['absent'][] = $per_page;
			return $per_page + 4;
		};
		$invalid_user_option_filter = static function ( $value, string $option, \WP_User $user ) use ( &$user_option_events ) {
			$user_option_events[] = array(
				'option' => $option,
				'user'   => (int) $user->ID,
				'value'  => $value,
			);

			return '0';
		};
		$invalid_per_page_filter = static function ( int $per_page ) use ( &$per_page_events ): int {
			$per_page_events['invalid'][] = $per_page;
			return $per_page + 7;
		};

		self::add_filter_record( $filters, $absent_option, $absent_per_page_filter, 10, 1 );
		self::add_filter_record( $filters, 'get_user_option_' . $invalid_option, $invalid_user_option_filter, 10, 3 );
		self::add_filter_record( $filters, $invalid_option, $invalid_per_page_filter, 10, 1 );

		try {
			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'admin.php';
			$_GET                    = array(
				'page'  => $screen->id,
				'paged' => 3,
			);
			$_POST                   = array();
			$_REQUEST                = $_GET;
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/admin.php';
			$_SERVER['REQUEST_URI']  = '/wp-admin/admin.php?page=' . rawurlencode( $screen->id )
				. '&mode=list&order=asc&s=stable-value'
				. '&updated=1&deleted=2'
				. '&raw=<script>alert(1)</script>'
				. '&proto=javascript:alert(1)'
				. '&paged=3';

			$probe = self::new_pagination_probe( $screen );

			$absent_per_page  = $probe->expose_get_items_per_page( $absent_option, 19 );
			$invalid_per_page = $probe->expose_get_items_per_page( $invalid_option, 23 );

			$probe->expose_set_pagination_args(
				array(
					'total_items' => 23,
					'per_page'    => 5,
				)
			);

			$derived_total_pages  = $probe->get_pagination_arg( 'total_pages' );
			$page_before_clamp    = $probe->get_pagenum();
			$page_arg_before_clamp = $probe->get_pagination_arg( 'page' );
			$top_output           = self::capture(
				static function () use ( $probe ): void {
					$probe->expose_pagination( 'top' );
				}
			);
			$bottom_output        = self::capture(
				static function () use ( $probe ): void {
					$probe->expose_pagination( 'bottom' );
				}
			);

			$_REQUEST['paged']    = 999;
			$page_after_clamp     = $probe->get_pagenum();
			$page_arg_after_clamp = $probe->get_pagination_arg( 'page' );

			$_REQUEST['paged'] = 2;
			$probe->expose_set_pagination_args(
				array(
					'infinite_scroll' => true,
					'per_page'        => 5,
					'total_items'     => 23,
				)
			);
			$infinite_output = self::capture(
				static function () use ( $probe ): void {
					$probe->expose_pagination( 'top' );
				}
			);

			$_REQUEST['paged'] = 1;
			$probe->expose_set_pagination_args(
				array(
					'per_page'    => 5,
					'total_items' => 0,
				)
			);
			$empty_output = self::capture(
				static function () use ( $probe ): void {
					$probe->expose_pagination( 'top' );
				}
			);

			$result = compact(
				'absent_per_page',
				'bottom_output',
				'derived_total_pages',
				'empty_output',
				'infinite_output',
				'invalid_per_page',
				'page_after_clamp',
				'page_arg_after_clamp',
				'page_arg_before_clamp',
				'page_before_clamp',
				'per_page_events',
				'top_output',
				'user_option_events'
			);
		} finally {
			self::remove_filter_records( $filters );
			if ( $probe instanceof \WP_List_Table ) {
				\remove_filter( "manage_{$screen->id}_columns", array( $probe, 'get_columns' ), 0 );
			}

			$filters_removed = self::filters_removed( $filters )
				&& ( ! ( $probe instanceof \WP_List_Table ) || false === \has_filter( "manage_{$screen->id}_columns", array( $probe, 'get_columns' ) ) );

			self::restore_server( $server_snapshot );
			self::restore_globals( $local_snapshot );
			$restored = self::globals_match(
				$local_snapshot,
				array( '_GET', '_POST', '_REQUEST', 'current_screen', 'current_user', 'pagenow' )
			)
				&& self::server_match( $server_snapshot, array( 'HTTP_HOST', 'REQUEST_URI', 'PHP_SELF' ) );
		}

		$top_output      = (string) ( $result['top_output'] ?? '' );
		$bottom_output   = (string) ( $result['bottom_output'] ?? '' );
		$infinite_output = (string) ( $result['infinite_output'] ?? '' );
		$empty_output    = (string) ( $result['empty_output'] ?? '' );
		$displaying_count = sprintf(
			\_n( '%s item', '%s items', 23 ),
			\number_format_i18n( 23 )
		);
		$hrefs            = self::anchor_hrefs_by_class(
			$top_output,
			array(
				'first-page',
				'prev-page',
				'next-page',
				'last-page',
			)
		);
		$first_href       = (string) ( $hrefs['first-page'] ?? '' );
		$prev_href        = (string) ( $hrefs['prev-page'] ?? '' );
		$next_href        = (string) ( $hrefs['next-page'] ?? '' );
		$last_href        = (string) ( $hrefs['last-page'] ?? '' );

		self::collect_failure(
			$failures,
			23 === ( $result['absent_per_page'] ?? null )
				&& 30 === ( $result['invalid_per_page'] ?? null )
				&& array( 19 ) === ( $result['per_page_events']['absent'] ?? array() )
				&& array( 23 ) === ( $result['per_page_events']['invalid'] ?? array() )
				&& 1 === count( $result['user_option_events'] ?? array() )
				&& false === ( $result['user_option_events'][0]['value'] ?? null ),
			'get_items_per_page falls back for absent/invalid user options before applying dynamic per-page filters',
			array(
				'absentPerPage'    => $result['absent_per_page'] ?? null,
				'invalidPerPage'   => $result['invalid_per_page'] ?? null,
				'perPageEvents'    => $result['per_page_events'] ?? array(),
				'userOptionEvents' => $result['user_option_events'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			5 === ( $result['derived_total_pages'] ?? null )
				&& 3 === ( $result['page_before_clamp'] ?? null )
				&& 3 === ( $result['page_arg_before_clamp'] ?? null )
				&& 5 === ( $result['page_after_clamp'] ?? null )
				&& 5 === ( $result['page_arg_after_clamp'] ?? null ),
			'set_pagination_args derives total_pages and get_pagenum/get_pagination_arg clamp after totals are known',
			array(
				'derivedTotalPages' => $result['derived_total_pages'] ?? null,
				'pageBeforeClamp'    => $result['page_before_clamp'] ?? null,
				'pageArgBeforeClamp' => $result['page_arg_before_clamp'] ?? null,
				'pageAfterClamp'     => $result['page_after_clamp'] ?? null,
				'pageArgAfterClamp'  => $result['page_arg_after_clamp'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $top_output, 'displaying-num' )
				&& str_contains( $top_output, $displaying_count )
				&& str_contains( $top_output, "id='current-page-selector'" )
				&& str_contains( $top_output, "name='paged'" )
				&& str_contains( $top_output, "value='3'" )
				&& str_contains( $top_output, "class='total-pages'>5</span>" )
				&& '' !== $first_href
				&& '' !== $prev_href
				&& '' !== $next_href
				&& '' !== $last_href
				&& self::pagination_href_matches( $first_href, $screen->id, null )
				&& self::pagination_href_matches( $prev_href, $screen->id, 2 )
				&& self::pagination_href_matches( $next_href, $screen->id, 4 )
				&& self::pagination_href_matches( $last_href, $screen->id, 5 )
				&& str_contains( $top_output, 'mode=list' )
				&& str_contains( $top_output, 'order=asc' )
				&& str_contains( $top_output, 's=stable-value' )
				&& ! str_contains( $top_output, 'updated=1' )
				&& ! str_contains( $top_output, 'deleted=2' )
				&& ! str_contains( strtolower( $top_output ), '<script' )
				&& ! str_contains( strtolower( $top_output ), 'javascript:' ),
			"pagination('top') renders editable middle-page controls, enabled links, preserved safe query args, and escaped hostile query values",
			array(
				'firstHref' => $first_href,
				'lastHref'  => $last_href,
				'nextHref'  => $next_href,
				'prevHref'  => $prev_href,
				'top'       => self::describe_string( $top_output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $bottom_output, 'displaying-num' )
				&& str_contains( $bottom_output, 'tablenav-paging-text' )
				&& str_contains( $bottom_output, "class='total-pages'>5</span>" )
				&& ! str_contains( $bottom_output, 'current-page-selector' )
				&& ! str_contains( $bottom_output, "name='paged'" )
				&& self::html_has_no_raw_script( $bottom_output ),
			"pagination('bottom') renders static current-page text rather than an editable paged input",
			array( 'bottom' => self::describe_string( $bottom_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $infinite_output, "class='pagination-links hide-if-js'" )
				&& '' === $empty_output,
			'infinite_scroll adds hide-if-js and zero total_items suppresses pagination output',
			array(
				'empty'    => self::describe_string( $empty_output ),
				'infinite' => self::describe_string( $infinite_output ),
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed && $restored,
			'base pagination filters, constructor column filter, request globals, and server metadata are restored',
			array(
				'filtersRemoved' => $filters_removed,
				'restored'       => $restored,
			)
		);

		return self::row(
			$ctx,
			'admin-list-tables.base-pagination-per-page-output',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screen'   => $screen->id,
			)
		);
	}

	private static function check_network_tables( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$filters         = array();
		$current_user    = self::synthetic_user( $ctx->fork( 'current-user' ), 55000, 'network' );
		$network_user_id = self::insert_fixture_user( $ctx->fork( 'network-user' ), 67000 );
		$network_user    = new \WP_User( $network_user_id );
		$site            = self::synthetic_site( $ctx->fork( 'site' ), 68000 );
		$screen_sites    = self::screen( 'sites-network' );
		$screen_users    = self::screen( 'users-network' );
		$site_events     = array();
		$user_events     = array();
		$result          = array();
		$filters_removed = false;

		$network_user->spam    = '0';
		$network_user->deleted = '0';
		\wp_cache_set( (int) $site->blog_id, $site, 'sites' );
		\wp_cache_set( (int) $site->blog_id . '_user_count', 1, 'blog-details' );

		$cap_filter = self::cap_filter(
			array(
				'delete_sites',
				'delete_users',
				'edit_user',
				'list_users',
				'manage_network_users',
				'manage_sites',
				'read',
			)
		);
		$sites_pre_query = static function ( $site_data, \WP_Site_Query $query ) use ( $site, &$site_events ) {
			$vars     = $query->query_vars;
			$status   = '';
			$statuses = array( 'public', 'archived', 'mature', 'spam', 'deleted' );
			foreach ( $statuses as $candidate ) {
				if ( ! empty( $vars[ $candidate ] ) ) {
					$status = $candidate;
					break;
				}
			}
			$count = '' === $status || 'public' === $status ? 1 : 0;

			$site_events[]        = array(
				'count'  => ! empty( $vars['count'] ),
				'fields' => $vars['fields'] ?? '',
				'status' => $status,
			);
			$query->found_sites   = $count;
			$query->max_num_pages = $count ? 1 : 0;

			if ( ! empty( $vars['count'] ) ) {
				return $count;
			}

			if ( 'ids' === ( $vars['fields'] ?? '' ) ) {
				return $count ? array( (int) $site->blog_id ) : array();
			}

			return $count ? array( $site ) : array();
		};
		$users_pre_query = static function ( $users, \WP_User_Query $query ) use ( $network_user_id, &$user_events ) {
			$user_events[] = $query->query_vars;
			self::set_property( $query, 'total_users', 2 );
			return array( $network_user_id );
		};
		$user_count_filter = static function (): int {
			return 2;
		};
		$super_admins_filter = static function (): array {
			return array();
		};
		$blogs_of_user_filter = static function ( $sites, int $user_id ) use ( $network_user_id, $site ) {
			if ( $user_id !== $network_user_id ) {
				return $sites;
			}

			return array(
				(int) $site->blog_id => (object) array(
					'userblog_id' => (int) $site->blog_id,
					'blog_id'     => (int) $site->blog_id,
					'site_id'     => (int) $site->site_id,
					'domain'      => $site->domain,
					'path'        => $site->path,
					'spam'        => $site->spam,
					'mature'      => $site->mature,
					'deleted'     => $site->deleted,
					'archived'    => $site->archived,
				),
			);
		};
		$large_network_filter = static function (): bool {
			return false;
		};

		self::add_filter_record( $filters, 'user_has_cap', $cap_filter, 10, 4 );
		self::add_filter_record( $filters, 'sites_pre_query', $sites_pre_query, 10, 2 );
		self::add_filter_record( $filters, 'users_pre_query', $users_pre_query, 10, 2 );
		self::add_filter_record( $filters, 'pre_site_option_user_count', $user_count_filter, 10, 3 );
		self::add_filter_record( $filters, 'site_option_site_admins', $super_admins_filter, 10, 1 );
		self::add_filter_record( $filters, 'pre_get_blogs_of_user', $blogs_of_user_filter, 10, 3 );
		self::add_filter_record( $filters, 'wp_is_large_network', $large_network_filter, 10, 4 );

		try {
			$GLOBALS['current_user'] = $current_user;
			$GLOBALS['pagenow']      = 'sites.php';
			$GLOBALS['mode']         = 'list';
			$_GET                    = array(
				'orderby' => 'blogname',
				'order'   => 'asc',
				'paged'   => 1,
				'status'  => 'public',
			);
			$_POST                   = array();
			$_REQUEST                = $_GET;
			$_SERVER['HTTP_HOST']    = 'example.test';
			$_SERVER['PHP_SELF']     = '/wp-admin/network/sites.php';
			$_SERVER['REQUEST_URI']  = '/wp-admin/network/sites.php?status=public&orderby=blogname&order=asc';

			$sites_table = self::list_table( 'WP_MS_Sites_List_Table', $screen_sites );
			$sites_table->prepare_items();
			$sites_column_info = $sites_table->get_column_info();
			$sites_bulk        = self::invoke( $sites_table, 'get_bulk_actions' );
			$sites_views       = self::invoke( $sites_table, 'get_views' );
			$sites_row         = self::capture(
				static function () use ( $sites_table ): void {
					$sites_table->display_rows();
				}
			);
			$sites_ajax_allowed = $sites_table->ajax_user_can();
			$sites_ajax_denied = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_sites ) {
					$table = self::list_table( 'WP_MS_Sites_List_Table', $screen_sites );
					return $table->ajax_user_can();
				}
			);

			$GLOBALS['pagenow']     = 'users.php';
			$_GET                   = array(
				'orderby' => 'email',
				'order'   => 'desc',
				'paged'   => 1,
			);
			$_POST                  = array();
			$_REQUEST               = $_GET;
			$_SERVER['PHP_SELF']    = '/wp-admin/network/users.php';
			$_SERVER['REQUEST_URI'] = '/wp-admin/network/users.php?orderby=email&order=desc';

			$network_users_table = self::list_table( 'WP_MS_Users_List_Table', $screen_users );
			$network_users_table->prepare_items();
			foreach ( $network_users_table->items as $item ) {
				$item->spam    = '0';
				$item->deleted = '0';
			}
			$network_users_column_info = $network_users_table->get_column_info();
			$network_users_bulk        = self::invoke( $network_users_table, 'get_bulk_actions' );
			$network_users_views       = self::invoke( $network_users_table, 'get_views' );
			$network_users_row         = self::capture(
				static function () use ( $network_users_table ): void {
					$network_users_table->display_rows();
				}
			);
			$network_users_ajax_allowed = $network_users_table->ajax_user_can();
			$network_users_ajax_denied = self::without_filter(
				'user_has_cap',
				$cap_filter,
				static function () use ( $screen_users ) {
					$table = self::list_table( 'WP_MS_Users_List_Table', $screen_users );
					return $table->ajax_user_can();
				}
			);

			$result = compact(
				'network_user_id',
				'network_users_ajax_denied',
				'network_users_ajax_allowed',
				'network_users_bulk',
				'network_users_column_info',
				'network_users_row',
				'network_users_table',
				'network_users_views',
				'site',
				'site_events',
				'sites_ajax_denied',
				'sites_ajax_allowed',
				'sites_bulk',
				'sites_column_info',
				'sites_row',
				'sites_table',
				'sites_views',
				'user_events'
			);
		} finally {
			self::remove_filter_records( $filters );
			$filters_removed = self::filters_removed( $filters );
			\wp_cache_delete( (int) $site->blog_id, 'sites' );
			\wp_cache_delete( (int) $site->blog_id . '_user_count', 'blog-details' );
		}

		$sites_column_info         = $result['sites_column_info'] ?? array();
		$network_users_column_info = $result['network_users_column_info'] ?? array();
		$sites_columns             = $sites_column_info[0] ?? array();
		$network_users_columns     = $network_users_column_info[0] ?? array();
		$sites_row                 = (string) ( $result['sites_row'] ?? '' );
		$network_users_row         = (string) ( $result['network_users_row'] ?? '' );
		$site                      = $result['site'] ?? $site;

		self::collect_failure(
			$failures,
			count( $result['site_events'] ?? array() ) >= 3
				&& count( $result['user_events'] ?? array() ) >= 1
				&& 1 === ( $result['sites_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 1 === ( $result['sites_table']->get_pagination_arg( 'total_pages' ) ?? null )
				&& 2 === ( $result['network_users_table']->get_pagination_arg( 'total_items' ) ?? null )
				&& 1 === ( $result['network_users_table']->get_pagination_arg( 'total_pages' ) ?? null ),
			'network sites/users prepare_items use pre-query fixtures and deterministic counts',
			$result
		);

		self::collect_failure(
			$failures,
			self::stable_column_ids( $sites_columns )
				&& self::stable_column_ids( $network_users_columns )
				&& isset( $sites_columns['cb'], $sites_columns['blogname'], $sites_columns['users'] )
				&& isset( $network_users_columns['cb'], $network_users_columns['username'], $network_users_columns['blogs'] )
				&& 'blogname' === ( $sites_column_info[3] ?? null )
				&& 'username' === ( $network_users_column_info[3] ?? null )
				&& isset( $sites_column_info[2]['blogname'], $sites_column_info[2]['registered'] )
				&& isset( $network_users_column_info[2]['username'], $network_users_column_info[2]['registered'] ),
			'network sites/users column IDs, sortables, and primary columns are stable',
			array(
				'networkUsersColumnInfo' => $network_users_column_info,
				'sitesColumnInfo'        => $sites_column_info,
			)
		);

		self::collect_failure(
			$failures,
			isset( $result['sites_bulk']['delete'], $result['sites_bulk']['spam'] )
				&& isset( $result['network_users_bulk']['delete'], $result['network_users_bulk']['spam'] )
				&& false === ( $result['sites_ajax_denied'] ?? null )
				&& true === ( $result['sites_ajax_allowed'] ?? null )
				&& false === ( $result['network_users_ajax_denied'] ?? null )
				&& true === ( $result['network_users_ajax_allowed'] ?? null ),
			'network sites/users bulk actions and capability gates respect synthetic capabilities',
			array(
				'networkUsersBulk'       => $result['network_users_bulk'] ?? array(),
				'sitesBulk'              => $result['sites_bulk'] ?? array(),
				'sitesAjaxAllowed'       => $result['sites_ajax_allowed'] ?? null,
				'sitesAjaxDenied'        => $result['sites_ajax_denied'] ?? null,
				'networkUsersAjaxAllowed' => $result['network_users_ajax_allowed'] ?? null,
				'networkUsersAjaxDenied' => $result['network_users_ajax_denied'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
				isset( $result['sites_views']['all'], $result['sites_views']['public'], $result['network_users_views']['all'] )
					&& str_contains( $sites_row, 'site-info.php?id=' . (int) $site->blog_id )
					&& str_contains( $sites_row, 'site-users.php?id=' . (int) $site->blog_id )
					&& str_contains( $network_users_row, 'site-info.php?id=' . (int) $site->blog_id )
					&& self::html_has_no_raw_script( $sites_row . $network_users_row . implode( '', $result['sites_views'] ?? array() ) . implode( '', $result['network_users_views'] ?? array() ) ),
			'network sites/users views and rows contain valid synthetic IDs and no raw script leakage',
			array(
				'sitesViewKeys'              => implode( ',', array_keys( $result['sites_views'] ?? array() ) ),
				'networkUsersViewKeys'       => implode( ',', array_keys( $result['network_users_views'] ?? array() ) ),
					'sitesContainsInfoLink'      => str_contains( $sites_row, 'site-info.php?id=' . (int) $site->blog_id ),
					'sitesContainsUsersLink'     => str_contains( $sites_row, 'site-users.php?id=' . (int) $site->blog_id ),
					'networkUsersContainsInput'  => str_contains( $network_users_row, 'allusers[]' ),
				'networkUsersContainsSite'   => str_contains( $network_users_row, 'site-info.php?id=' . (int) $site->blog_id ),
				'networkSitesUsersNoScript'  => self::html_has_no_raw_script( $sites_row . $network_users_row . implode( '', $result['sites_views'] ?? array() ) . implode( '', $result['network_users_views'] ?? array() ) ),
				'networkUsersScriptContext'  => self::raw_script_context( $network_users_row ),
				'sitesScriptContext'         => self::raw_script_context( $sites_row ),
				'networkUsersRow'            => $network_users_row,
				'sitesRow'                   => $sites_row,
			)
		);

		self::collect_failure(
			$failures,
			$filters_removed,
			'network site/user pre-query, blog-list, count, large-network, and capability filters are removed',
			array( 'filtersRemoved' => $filters_removed )
		);

		return self::row(
			$ctx,
			'admin-list-tables.network-sites-users.prepare-columns-rows-actions',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'screens'  => array( $screen_sites->id, $screen_users->id ),
			)
		);
	}

	private static function skipped_db_heavy_branches( \ComponentFuzz\FuzzContext $ctx ): array {
		return $ctx->skip(
			'admin-list-tables.db-heavy-branches-skipped',
			'Full admin page dispatch, destructive plugin/theme lifecycle operations, real uploads, privacy request tables, '
				. 'and true multisite write paths remain out of scope. This surface covers concrete '
				. 'core list-table constructors, columns, views, actions, row rendering, and pagination through synthetic rows, '
				. 'object-cache fixtures, generated temp plugin/theme metadata, and pre-query filters.',
			array(
				'covered_classes' => array(
					'WP_Posts_List_Table',
					'WP_Media_List_Table',
					'WP_Comments_List_Table',
					'WP_Terms_List_Table',
					'WP_Users_List_Table',
					'WP_Plugins_List_Table',
					'WP_Themes_List_Table',
					'WP_Plugin_Install_List_Table',
					'WP_Theme_Install_List_Table',
					'WP_Application_Passwords_List_Table',
					'WP_MS_Themes_List_Table',
					'WP_MS_Sites_List_Table',
					'WP_MS_Users_List_Table',
				),
				'skipped_classes' => array(
					'WP_Privacy_Data_Export_Requests_List_Table',
					'WP_Privacy_Data_Removal_Requests_List_Table',
				),
			)
		);
	}

	private static function new_pagination_probe( \WP_Screen $screen ): \WP_List_Table {
		return new class( $screen ) extends \WP_List_Table {
			public function __construct( \WP_Screen $screen ) {
				parent::__construct(
					array(
						'ajax'     => false,
						'plural'   => 'cfz_base_items',
						'screen'   => $screen,
						'singular' => 'cfz_base_item',
					)
				);
			}

			public function get_columns(): array {
				return array(
					'title' => 'Title',
				);
			}

			public function prepare_items(): void {
				$this->items = array();
			}

			public function expose_set_pagination_args( array $args ): void {
				$this->set_pagination_args( $args );
			}

			public function expose_get_items_per_page( string $option, int $default_value ): int {
				return $this->get_items_per_page( $option, $default_value );
			}

			public function expose_pagination( string $which ): void {
				$this->pagination( $which );
			}
		};
	}

	private static function list_table( string $class_name, \WP_Screen $screen ): \WP_List_Table {
		$table = \_get_list_table( $class_name, array( 'screen' => $screen ) );
		if ( ! $table instanceof \WP_List_Table ) {
			throw new \RuntimeException( "Could not load {$class_name}." );
		}

		return $table;
	}

	private static function screen( string $id, string $post_type = '', string $taxonomy = '' ): \WP_Screen {
		$screen = \convert_to_screen( $id );
		if ( '' !== $post_type ) {
			$screen->post_type = $post_type;
		}
		if ( '' !== $taxonomy ) {
			$screen->taxonomy = $taxonomy;
		}

		return $screen;
	}

	private static function bootstrap_core_types(): void {
		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/comment.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/comment.php';
		}
		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/ms.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}
		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-includes/ms-load.php' ) ) {
			require_once ABSPATH . 'wp-includes/ms-load.php';
		}
		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-includes/ms-functions.php' ) ) {
			require_once ABSPATH . 'wp-includes/ms-functions.php';
		}

		\create_initial_post_types();
		\create_initial_taxonomies();

		if ( ! isset( $GLOBALS['wp'] ) && class_exists( 'WP' ) ) {
			$GLOBALS['wp'] = new \WP();
		}
		if ( ! isset( $GLOBALS['wp_rewrite'] ) && class_exists( 'WP_Rewrite' ) ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}
		if ( ! isset( $GLOBALS['wp_the_query'] ) && class_exists( 'WP_Query' ) ) {
			$GLOBALS['wp_the_query'] = new \WP_Query();
		}
		if ( ! isset( $GLOBALS['wp_query'] ) && isset( $GLOBALS['wp_the_query'] ) ) {
			$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
		}
	}

	private static function synthetic_user( \ComponentFuzz\FuzzContext $ctx, int $base_id, string $prefix ): \WP_User {
		$id    = $base_id + $ctx->int( 1, 999 );
		$login = 'cfz_' . $prefix . '_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 );
		$data  = (object) array(
			'ID'                  => $id,
			'user_login'          => $login,
			'user_pass'           => 'component-fuzz-pass',
			'user_nicename'       => $login,
			'user_email'          => $login . '@example.test',
			'user_url'            => 'https://example.test/users/' . rawurlencode( $login ),
			'user_registered'     => '2026-06-22 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
			'display_name'        => 'List Table User ' . $id,
		);

		\update_user_caches( $data );

		$user          = new \WP_User( $data );
		$user->roles   = array();
		$user->caps    = array();
		$user->allcaps = array();

		return $user;
	}

	private static function insert_fixture_user( \ComponentFuzz\FuzzContext $ctx, int $base_id ): int {
		$fixture = $base_id + $ctx->int( 1, 999 );
		$login   = 'cfz_list_user_' . substr( hash( 'sha1', (string) $ctx->seed() . ':' . $fixture ), 0, 12 );

		$inserted = \wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => 'component-fuzz-pass-' . $fixture,
				'user_email'   => $login . '@example.test',
				'user_url'     => 'https://example.test/users/' . rawurlencode( $login ),
				'display_name' => 'Listed User ' . $fixture,
				'first_name'   => 'Listed',
				'last_name'    => 'User',
				'role'         => 'editor',
			)
		);

		if ( is_wp_error( $inserted ) || ! is_int( $inserted ) ) {
			throw new \RuntimeException( 'Could not insert synthetic user fixture.' );
		}

		return $inserted;
	}

	private static function synthetic_post(
		\ComponentFuzz\FuzzContext $ctx,
		int $base_id,
		string $post_type,
		string $status,
		int $author
	): \WP_Post {
		$id    = $base_id + $ctx->int( 1, 999 );
		$title = 'List Table ' . $post_type . ' ' . self::hostile_label( $ctx->fork( 'title' ) );

		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => (string) $author,
				'post_date'             => '2026-06-22 10:00:00',
				'post_date_gmt'         => '2026-06-22 08:00:00',
				'post_content'          => 'Content ' . self::hostile_label( $ctx->fork( 'content' ) ),
				'post_title'            => $title,
				'post_excerpt'          => 'Excerpt ' . self::hostile_label( $ctx->fork( 'excerpt' ) ),
				'post_status'           => $status,
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => \sanitize_title( $title ),
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-22 11:00:00',
				'post_modified_gmt'     => '2026-06-22 09:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'https://example.test/?p=' . $id,
				'menu_order'            => 0,
				'post_type'             => $post_type,
				'post_mime_type'        => '',
				'comment_count'         => '1',
			)
		);
	}

	private static function synthetic_comment(
		\ComponentFuzz\FuzzContext $ctx,
		int $base_id,
		int $post_id,
		int $user_id
	): \WP_Comment {
		$id = $base_id + $ctx->int( 1, 999 );

		return new \WP_Comment(
			(object) array(
				'comment_ID'           => (string) $id,
				'comment_post_ID'      => (string) $post_id,
				'comment_author'       => 'Comment Author',
				'comment_author_email' => 'commenter' . $id . '@example.test',
				'comment_author_url'   => 'https://example.test/commenter/?id=' . $id,
				'comment_author_IP'    => '192.0.2.' . ( $id % 250 ),
				'comment_date'         => '2026-06-22 12:00:00',
				'comment_date_gmt'     => '2026-06-22 10:00:00',
				'comment_content'      => 'Comment ' . self::hostile_label( $ctx->fork( 'content' ) ),
				'comment_karma'        => '0',
				'comment_approved'     => '1',
				'comment_agent'        => 'ComponentFuzz',
				'comment_type'         => 'comment',
				'comment_parent'       => '0',
				'user_id'              => (string) $user_id,
			)
		);
	}

	private static function synthetic_term( \ComponentFuzz\FuzzContext $ctx, int $base_id, string $taxonomy ): \WP_Term {
		$id          = $base_id + $ctx->int( 1, 999 );
		$raw_name    = 'Term ' . self::hostile_label( $ctx->fork( 'name' ) );
		$name        = \_wp_specialchars( \wp_filter_kses( \sanitize_text_field( $raw_name ) ) );
		$description = \wp_filter_kses( 'Description ' . self::hostile_label( $ctx->fork( 'description' ) ) );

		$term = new \WP_Term(
			(object) array(
				'term_id'          => $id,
				'name'             => $name,
				'slug'             => \sanitize_title_with_dashes( $raw_name ),
				'term_group'       => 0,
				'term_taxonomy_id' => $id,
				'taxonomy'         => $taxonomy,
				'description'      => $description,
				'parent'           => 0,
				'count'            => 3,
				'filter'           => 'raw',
			)
		);

		return $term;
	}

	private static function synthetic_site( \ComponentFuzz\FuzzContext $ctx, int $base_id ): \WP_Site {
		$id = $base_id + $ctx->int( 1, 999 );

		return new \WP_Site(
			(object) array(
				'blog_id'      => (string) $id,
				'domain'       => 'site-' . $id . '.example.test',
				'path'         => '/network-' . $id . '/',
				'site_id'      => '1',
				'registered'   => '2026-06-20 00:00:00',
				'last_updated' => '2026-06-22 00:00:00',
				'public'       => '1',
				'archived'     => '0',
				'mature'       => '0',
				'spam'         => '0',
				'deleted'      => '0',
				'lang_id'      => '0',
			)
		);
	}

	private static function synthetic_application_password( \ComponentFuzz\FuzzContext $ctx, int $index ): array {
		$token = substr( hash( 'sha1', (string) $ctx->seed() . ':' . $index ), 0, 12 );

		return array(
			'uuid'      => sprintf( '00000000-0000-4000-8000-%012s', $token ),
			'app_id'    => sprintf( '11111111-1111-4111-8111-%012s', substr( hash( 'sha1', 'app:' . $token ), 0, 12 ) ),
			'name'      => 'Generated App Password ' . self::hostile_label( $ctx->fork( 'name' ) ),
			'password'  => '$generic$component-fuzz-secret-' . $token,
			'created'   => 1763980800 + ( $index * DAY_IN_SECONDS ),
			'last_used' => 1764067200 + ( $index * DAY_IN_SECONDS ),
			'last_ip'   => '192.0.2.' . ( 10 + $index ),
		);
	}

	private static function plugin_install_api_item(
		string $slug,
		string $name,
		string $version,
		string $description,
		array $icons,
		array $overrides = array()
	): array {
		$icons = array_merge(
			array(
				'svg'     => '',
				'2x'      => '',
				'1x'      => '',
				'default' => 'https://example.test/icons/default.png',
			),
			$icons
		);

		return array_merge(
			array(
				'active_installs'   => 1200,
				'author'            => '<a href="https://example.test/authors/' . rawurlencode( $slug ) . '">Plugin Author</a>',
				'download_link'     => 'https://downloads.example.test/' . rawurlencode( $slug ) . '.zip',
				'group'             => 'cfz-group',
				'homepage'          => 'https://example.test/plugins/' . rawurlencode( $slug ),
				'icons'             => $icons,
				'last_updated'      => '2026-06-01',
				'name'              => $name,
				'num_ratings'       => 12,
				'rating'            => 83,
				'requires'          => '5.0',
				'requires_php'      => '5.6',
				'requires_plugins'  => array(),
				'short_description' => $description,
				'slug'              => $slug,
				'tested'            => '99.0',
				'version'           => $version,
			),
			$overrides
		);
	}

	private static function theme_install_api_item(
		string $slug,
		string $name,
		string $version,
		string $description,
		array $overrides = array()
	): \stdClass {
		return (object) array_merge(
			array(
				'author'         => 'Theme Author ' . $slug,
				'description'    => $description,
				'download_link'  => 'https://downloads.example.test/themes/' . rawurlencode( $slug ) . '.zip',
				'homepage'       => 'https://example.test/themes/' . rawurlencode( $slug ),
				'name'           => $name,
				'num_ratings'    => 9,
				'preview_url'    => 'https://preview.example.test/themes/' . rawurlencode( $slug ) . '/',
				'rating'         => 88,
				'screenshot_url' => 'https://example.test/screens/' . rawurlencode( $slug ) . '.png',
				'slug'           => $slug,
				'version'        => $version,
			),
			$overrides
		);
	}

	private static function cache_post( \WP_Post $post ): void {
		\wp_cache_set( (int) $post->ID, (object) $post->to_array(), 'posts' );
	}

	private static function cache_comment( \WP_Comment $comment ): void {
		\wp_cache_set( (int) $comment->comment_ID, (object) $comment->to_array(), 'comment' );
	}

	private static function cache_term( \WP_Term $term ): void {
		\wp_cache_set( (int) $term->term_id, $term, 'terms' );
	}

	private static function write_theme_fixture( string $theme_dir, string $hostile_label ): void {
		if ( ! is_dir( $theme_dir ) && ! mkdir( $theme_dir, 0777, true ) && ! is_dir( $theme_dir ) ) {
			throw new \RuntimeException( 'Could not create synthetic theme directory.' );
		}

		$stylesheet = implode(
			"\n",
			array(
				'/*',
				'Theme Name: CFZ Theme ' . $hostile_label,
				'Theme URI: https://example.test/themes/?q=' . rawurlencode( $hostile_label ),
				'Author: Theme Author ' . $hostile_label,
				'Author URI: https://example.test/theme-author/',
				'Description: Theme description ' . $hostile_label,
				'Version: 1.0.0',
				'Tags: blog, custom-background',
				'*/',
				'body { background: #fff; }',
			)
		);

		if ( false === file_put_contents( $theme_dir . '/style.css', $stylesheet ) ) {
			throw new \RuntimeException( 'Could not write synthetic theme stylesheet.' );
		}
		if ( false === file_put_contents( $theme_dir . '/index.php', "<?php\n// Synthetic component-fuzz theme fixture.\n" ) ) {
			throw new \RuntimeException( 'Could not write synthetic theme index.' );
		}
	}

	private static function write_plugin_fixture( string $plugin_dir, string $plugin_path, string $name, string $version ): void {
		if ( ! is_dir( WP_PLUGIN_DIR ) && ! mkdir( WP_PLUGIN_DIR, 0777, true ) && ! is_dir( WP_PLUGIN_DIR ) ) {
			throw new \RuntimeException( 'Could not create synthetic plugin root.' );
		}
		if ( ! is_dir( $plugin_dir ) && ! mkdir( $plugin_dir, 0777, true ) && ! is_dir( $plugin_dir ) ) {
			throw new \RuntimeException( 'Could not create synthetic plugin directory.' );
		}

		$plugin = implode(
			"\n",
			array(
				'<?php',
				'/**',
				' * Plugin Name: ' . $name,
				' * Plugin URI: https://example.test/plugins/installed',
				' * Description: Synthetic component-fuzz plugin install fixture.',
				' * Version: ' . $version,
				' * Author: Component Fuzz',
				' * Requires at least: 5.0',
				' * Requires PHP: 5.6',
				' */',
				'// Synthetic component-fuzz plugin fixture.',
				'',
			)
		);

		if ( false === file_put_contents( $plugin_path, $plugin ) ) {
			throw new \RuntimeException( 'Could not write synthetic plugin fixture.' );
		}
	}

	private static function remove_plugin_fixture( string $plugin_dir, string $plugin_path ): void {
		if ( is_file( $plugin_path ) ) {
			unlink( $plugin_path );
		}
		if ( is_dir( $plugin_dir ) ) {
			rmdir( $plugin_dir );
		}
	}

	private static function remove_theme_fixture( string $theme_dir ): void {
		$index = $theme_dir . '/index.php';
		$style = $theme_dir . '/style.css';
		if ( is_file( $index ) ) {
			unlink( $index );
		}
		if ( is_file( $style ) ) {
			unlink( $style );
		}
		if ( is_dir( $theme_dir ) ) {
			rmdir( $theme_dir );
		}
	}

	private static function reset_theme_directory_cache_after_restore( array $global_snapshot, string $fallback_theme_root ): void {
		if ( empty( $GLOBALS['wp_theme_directories'] ) && is_dir( $fallback_theme_root ) ) {
			$GLOBALS['wp_theme_directories'] = array( \untrailingslashit( $fallback_theme_root ) );
		}

		\search_theme_directories( true );

		if ( isset( $global_snapshot['wp_theme_directories'] ) ) {
			self::restore_globals(
				array(
					'wp_theme_directories' => $global_snapshot['wp_theme_directories'],
				)
			);
		} else {
			unset( $GLOBALS['wp_theme_directories'] );
		}
	}

	private static function snapshot_options(): ?array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return self::clone_value( $GLOBALS['wpdb']->component_fuzz_get_options() );
		}

		return null;
	}

	private static function restore_options( ?array $snapshot ): void {
		if ( null !== $snapshot && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot );
		}
	}

	private static function options_match( ?array $snapshot ): bool {
		if ( null === $snapshot ) {
			return ! ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) );
		}
		if ( ! isset( $GLOBALS['wpdb'] ) || ! method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return false;
		}

		return $snapshot === $GLOBALS['wpdb']->component_fuzz_get_options();
	}

	private static function cap_filter( array $capabilities ): callable {
		return static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $capabilities ): array {
			unset( $caps, $args );

			$current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
				? (int) $GLOBALS['current_user']->ID
				: 0;
			if ( 0 !== $current_user_id && (int) $user->ID !== $current_user_id ) {
				return $allcaps;
			}

			foreach ( $capabilities as $capability ) {
				$allcaps[ $capability ] = true;
			}

			return $allcaps;
		};
	}

	private static function without_filter( string $hook, callable $callback, callable $body ) {
		\remove_filter( $hook, $callback, 10 );
		try {
			return $body();
		} finally {
			\add_filter( $hook, $callback, 10, 4 );
		}
	}

	private static function invoke( object $object, string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invokeArgs( $object, $args );
	}

	private static function set_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setValue( $object, $value );
	}

	private static function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	private static function add_filter_record( array &$filters, string $hook, callable $callback, int $priority, int $accepted_args ): void {
		\add_filter( $hook, $callback, $priority, $accepted_args );
		$filters[] = array( $hook, $callback, $priority );
	}

	private static function remove_filter_records( array $filters ): void {
		foreach ( array_reverse( $filters ) as $filter ) {
			\remove_filter( $filter[0], $filter[1], $filter[2] );
		}
	}

	private static function filters_removed( array $filters ): bool {
		foreach ( $filters as $filter ) {
			if ( false !== \has_filter( $filter[0], $filter[1] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function stable_column_ids( array $columns ): bool {
		foreach ( array_keys( $columns ) as $column ) {
			if ( ! is_string( $column ) || '' === $column || preg_match( '/[\s<>"\']/', $column ) ) {
				return false;
			}
		}

		return true;
	}

	private static function plugin_install_request_events_match( array $api_events, array $table_arg_events, array $search_modes, string $marker ): bool {
		$args_events     = array_values(
			array_filter(
				$api_events,
				static function ( array $event ): bool {
					return 'args' === ( $event['phase'] ?? null );
				}
			)
		);
		$response_events = array_values(
			array_filter(
				$api_events,
				static function ( array $event ): bool {
					return 'response' === ( $event['phase'] ?? null );
				}
			)
		);

		if (
			count( $search_modes ) !== count( $table_arg_events )
			|| count( $search_modes ) !== count( $args_events )
			|| count( $search_modes ) !== count( $response_events )
		) {
			return false;
		}

		foreach ( array_values( $search_modes ) as $index => $mode ) {
			foreach ( array( $args_events[ $index ], $response_events[ $index ] ) as $event ) {
				if (
					( $event['mode'] ?? null ) !== $mode['type']
					|| 'query_plugins' !== ( $event['action'] ?? null )
					|| ! self::plugin_install_args_match( $event['args'] ?? array(), $mode, $marker )
				) {
					return false;
				}
			}

			if ( ! self::plugin_install_args_match( $table_arg_events[ $index ], $mode, $marker ) ) {
				return false;
			}
		}

		return true;
	}

	private static function plugin_install_args_match( $args, array $mode, string $marker ): bool {
		$args = (array) $args;
		if (
			2 !== (int) ( $args['page'] ?? 0 )
			|| 36 !== (int) ( $args['per_page'] ?? 0 )
			|| $marker !== (string) ( $args['cfz_marker'] ?? '' )
			|| '' === (string) ( $args['locale'] ?? '' )
		) {
			return false;
		}

		if ( 'term' === $mode['type'] ) {
			return (string) ( $args['search'] ?? '' ) === $mode['term']
				&& ! isset( $args['tag'], $args['author'] );
		}

		if ( 'tag' === $mode['type'] ) {
			return (string) ( $args['tag'] ?? '' ) === \sanitize_title_with_dashes( $mode['term'] )
				&& ! isset( $args['search'], $args['author'] );
		}

		if ( 'author' === $mode['type'] ) {
			return (string) ( $args['author'] ?? '' ) === $mode['term']
				&& ! isset( $args['search'], $args['tag'] );
		}

		return false;
	}

	private static function plugin_install_mode_results_match( array $mode_results, array $expected_slugs, int $api_total ): bool {
		foreach ( array( 'term', 'tag', 'author' ) as $mode ) {
			$result = $mode_results[ $mode ] ?? null;
			if ( ! is_array( $result ) ) {
				return false;
			}

			if (
				count( $expected_slugs ) !== (int) ( $result['item_count'] ?? -1 )
				|| $expected_slugs !== ( $result['items'] ?? array() )
				|| 36 !== (int) ( $result['per_page'] ?? 0 )
				|| $api_total !== (int) ( $result['total_items'] ?? 0 )
				|| (int) ceil( $api_total / 36 ) !== (int) ( $result['total_pages'] ?? 0 )
			) {
				return false;
			}
		}

		return true;
	}

	private static function plugin_install_views_match( array $mode_results ): bool {
		$required = array(
			'plugin-install-cfz-custom',
			'plugin-install-favorites',
			'plugin-install-featured',
			'plugin-install-popular',
			'plugin-install-recommended',
			'plugin-install-search',
		);

		foreach ( array( 'term', 'tag', 'author' ) as $mode ) {
			$result    = $mode_results[ $mode ] ?? array();
			$view_keys = $result['view_keys'] ?? array();
			if ( array() !== array_diff( $required, $view_keys ) ) {
				return false;
			}
			if ( in_array( 'plugin-install-upload', $view_keys, true ) ) {
				return false;
			}
			if ( ! self::html_has_no_raw_script( (string) ( $result['views_html'] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	private static function theme_install_request_events_match( array $api_events, array $table_arg_events, array $request_modes, string $marker ): bool {
		$args_events     = array_values(
			array_filter(
				$api_events,
				static function ( array $event ): bool {
					return 'args' === ( $event['phase'] ?? null );
				}
			)
		);
		$response_events = array_values(
			array_filter(
				$api_events,
				static function ( array $event ): bool {
					return 'response' === ( $event['phase'] ?? null );
				}
			)
		);
		$result_events   = array_values(
			array_filter(
				$api_events,
				static function ( array $event ): bool {
					return 'result' === ( $event['phase'] ?? null );
				}
			)
		);

		if (
			count( $request_modes ) !== count( $table_arg_events )
			|| count( $request_modes ) !== count( $args_events )
			|| count( $request_modes ) !== count( $response_events )
			|| count( $request_modes ) !== count( $result_events )
		) {
			return false;
		}

		foreach ( array_values( $request_modes ) as $index => $mode ) {
			if (
				( $table_arg_events[ $index ]['mode'] ?? null ) !== $mode['id']
				|| ! self::theme_install_args_match( $table_arg_events[ $index ]['args'] ?? array(), $mode, $marker, false )
			) {
				return false;
			}

			foreach ( array( $args_events[ $index ], $response_events[ $index ], $result_events[ $index ] ) as $event ) {
				if (
					( $event['mode'] ?? null ) !== $mode['id']
					|| 'query_themes' !== ( $event['action'] ?? null )
					|| ! self::theme_install_args_match( $event['args'] ?? array(), $mode, $marker, true )
				) {
					return false;
				}
			}
		}

		return true;
	}

	private static function theme_install_args_match( $args, array $mode, string $marker, bool $expect_api_defaults ): bool {
		$args = (array) $args;
		if (
			(int) ( $mode['paged'] ?? 0 ) !== (int) ( $args['page'] ?? 0 )
			|| 36 !== (int) ( $args['per_page'] ?? 0 )
			|| $marker !== (string) ( $args['cfz_marker'] ?? '' )
			|| ! array_key_exists( 'fields', $args )
		) {
			return false;
		}

		if (
			$expect_api_defaults
			&& ( '' === (string) ( $args['locale'] ?? '' ) || '' === (string) ( $args['wp_version'] ?? '' ) )
		) {
			return false;
		}

		if ( isset( $mode['browse'] ) ) {
			return (string) ( $args['browse'] ?? '' ) === $mode['browse']
				&& ! isset( $args['search'], $args['tag'], $args['author'] );
		}

		$search_string = strtolower( (string) ( $mode['term'] ?? '' ) );

		if ( isset( $mode['features'] ) ) {
			return ( $mode['features'] ?? array() ) === ( $args['tag'] ?? null )
				&& (string) ( $args['search'] ?? '' ) === $search_string
				&& ! isset( $args['author'], $args['browse'] );
		}

		if ( 'term' === ( $mode['type'] ?? '' ) ) {
			return (string) ( $args['search'] ?? '' ) === $search_string
				&& ! isset( $args['tag'], $args['author'], $args['browse'] );
		}

		if ( 'tag' === ( $mode['type'] ?? '' ) ) {
			$expected_terms = array_map( 'sanitize_key', array_unique( array_filter( array_map( 'trim', explode( ',', $search_string ) ) ) ) );

			return array_values( $expected_terms ) === array_values( (array) ( $args['tag'] ?? array() ) )
				&& ! isset( $args['search'], $args['author'], $args['browse'] );
		}

		if ( 'author' === ( $mode['type'] ?? '' ) ) {
			return (string) ( $args['author'] ?? '' ) === $search_string
				&& ! isset( $args['search'], $args['tag'], $args['browse'] );
		}

		return false;
	}

	private static function theme_install_mode_results_match( array $mode_results, array $expected_slugs, int $api_total, array $request_modes ): bool {
		foreach ( $request_modes as $mode ) {
			$result = $mode_results[ $mode['id'] ] ?? null;
			if ( ! is_array( $result ) ) {
				return false;
			}

			if (
				count( $expected_slugs ) !== (int) ( $result['item_count'] ?? -1 )
				|| $expected_slugs !== ( $result['items'] ?? array() )
				|| 36 !== (int) ( $result['per_page'] ?? 0 )
				|| $api_total !== (int) ( $result['total_items'] ?? 0 )
				|| (int) ceil( $api_total / 36 ) !== (int) ( $result['total_pages'] ?? 0 )
			) {
				return false;
			}

			if ( isset( $mode['features'] ) && ( $mode['features'] ?? array() ) !== ( $result['features'] ?? array() ) ) {
				return false;
			}
		}

		return true;
	}

	private static function theme_install_views_match( array $mode_results ): bool {
		$base_required = array(
			'theme-install-cfz-custom',
			'theme-install-dashboard',
			'theme-install-featured',
			'theme-install-new',
			'theme-install-updated',
			'theme-install-upload',
		);

		foreach ( $mode_results as $mode_id => $result ) {
			$view_keys = $result['view_keys'] ?? array();
			if ( array() !== array_diff( $base_required, $view_keys ) ) {
				return false;
			}
			if ( str_starts_with( (string) $mode_id, 'search-' ) || 'feature-filter' === $mode_id ) {
				if ( ! in_array( 'theme-install-search', $view_keys, true ) ) {
					return false;
				}
			} elseif ( in_array( 'theme-install-search', $view_keys, true ) ) {
				return false;
			}
			if ( ! self::html_has_no_raw_script( (string) ( $result['views_html'] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	private static function hostile_label( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'label "' . $ctx->identifier( 3, 8 ) . '" <script>alert(1)</script> onclick="bad" & value';
	}

	private static function html_has_no_raw_script( string $html ): bool {
		$lower = strtolower( $html );

		return ! str_contains( $lower, '<script' )
			&& ! str_contains( $lower, 'javascript:' );
	}

	private static function anchor_hrefs_by_class( string $html, array $classes ): array {
		$wanted = array_fill_keys( $classes, true );
		$hrefs  = array();

		if ( ! preg_match_all( '/<a\b([^>]*)>/i', $html, $anchors ) ) {
			return $hrefs;
		}

		foreach ( $anchors[1] as $attribute_text ) {
			$attrs = self::html_attributes( $attribute_text );
			if ( ! isset( $attrs['class'], $attrs['href'] ) ) {
				continue;
			}

			$class_tokens = preg_split( '/\s+/', html_entity_decode( $attrs['class'], ENT_QUOTES, 'UTF-8' ) );
			foreach ( is_array( $class_tokens ) ? $class_tokens : array() as $class ) {
				if ( isset( $wanted[ $class ] ) && ! isset( $hrefs[ $class ] ) ) {
					$hrefs[ $class ] = $attrs['href'];
				}
			}
		}

		return $hrefs;
	}

	private static function image_srcs_by_class( string $html, string $class ): array {
		$srcs = array();
		if ( ! preg_match_all( '/<img\b([^>]*)>/i', $html, $images ) ) {
			return $srcs;
		}

		foreach ( $images[1] as $attribute_text ) {
			$attrs = self::html_attributes( $attribute_text );
			if ( ! isset( $attrs['class'], $attrs['src'] ) ) {
				continue;
			}

			$class_tokens = preg_split( '/\s+/', html_entity_decode( $attrs['class'], ENT_QUOTES, 'UTF-8' ) );
			if ( is_array( $class_tokens ) && in_array( $class, $class_tokens, true ) ) {
				$srcs[] = $attrs['src'];
			}
		}

		return $srcs;
	}

	private static function image_srcs( string $html ): array {
		$srcs = array();
		if ( ! preg_match_all( '/<img\b([^>]*)>/i', $html, $images ) ) {
			return $srcs;
		}

		foreach ( $images[1] as $attribute_text ) {
			$attrs = self::html_attributes( $attribute_text );
			if ( isset( $attrs['src'] ) ) {
				$srcs[] = $attrs['src'];
			}
		}

		return $srcs;
	}

	private static function escaped_url_attributes( array $urls ): bool {
		return self::escaped_url_attributes_with_prefix( $urls, 'https://example.test/icons/' );
	}

	private static function escaped_url_attributes_with_prefix( array $urls, string $required_prefix ): bool {
		$has_required_prefix = false;
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || preg_match( '/[<>"\']/', $url ) ) {
				return false;
			}

			$decoded = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
			$lower   = strtolower( $decoded );
			if ( str_contains( $lower, '<script' ) || str_contains( $lower, 'javascript:' ) ) {
				return false;
			}

			if ( str_contains( $decoded, $required_prefix ) ) {
				$has_required_prefix = true;
			}
		}

		return $has_required_prefix;
	}

	private static function script_data_contains_all( string $handle, array $needles ): bool {
		$scripts = $GLOBALS['wp_scripts'] ?? null;
		if ( ! $scripts instanceof \WP_Scripts || ! isset( $scripts->registered[ $handle ] ) ) {
			return false;
		}

		$data = $scripts->registered[ $handle ]->extra['data'] ?? '';
		if ( is_array( $data ) ) {
			$data = implode( "\n", array_map( 'strval', $data ) );
		}
		if ( ! is_string( $data ) ) {
			return false;
		}

		foreach ( $needles as $needle ) {
			if ( ! str_contains( $data, (string) $needle ) ) {
				return false;
			}
		}

		return true;
	}

	private static function html_attributes( string $attribute_text ): array {
		$attrs = array();
		preg_match_all(
			"/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s\"'=<>`]+))/",
			$attribute_text,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$value = $match[2] ?? '';
			if ( '' === $value && isset( $match[3] ) && '' !== $match[3] ) {
				$value = $match[3];
			} elseif ( '' === $value && isset( $match[4] ) ) {
				$value = $match[4];
			}

			$attrs[ strtolower( $match[1] ) ] = $value;
		}

		return $attrs;
	}

	private static function pagination_href_matches( string $href, string $screen_id, ?int $expected_paged ): bool {
		if ( '' === $href ) {
			return false;
		}

		if ( preg_match( '/[<>"\']/', $href ) || preg_match( '/&(?!#\d+;|#x[0-9a-f]+;|[a-z][a-z0-9]+;)/i', $href ) ) {
			return false;
		}

		if ( ! preg_match( '/(?:&#0*38;|&amp;)/i', $href ) ) {
			return false;
		}

		$lower_href = strtolower( $href );
		if ( str_contains( $lower_href, '<script' ) || str_contains( $lower_href, 'javascript:' ) ) {
			return false;
		}

		$decoded = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
		$parts   = \wp_parse_url( $decoded );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		$expected = array(
			'page'  => $screen_id,
			'mode'  => 'list',
			'order' => 'asc',
			's'     => 'stable-value',
			'raw'   => '<script>alert(1)</script>',
			'proto' => 'javascript:alert(1)',
		);
		foreach ( $expected as $key => $value ) {
			if ( (string) ( $query[ $key ] ?? '' ) !== $value ) {
				return false;
			}
		}

		if ( isset( $query['updated'] ) || isset( $query['deleted'] ) ) {
			return false;
		}

		if ( null === $expected_paged ) {
			return ! isset( $query['paged'] );
		}

		return (string) ( $query['paged'] ?? '' ) === (string) $expected_paged;
	}

	private static function raw_script_context( string $html ): string {
		$lower   = strtolower( $html );
		$offsets = array(
			'scriptTag'  => stripos( $lower, '<script' ),
			'javascript' => stripos( $lower, 'javascript:' ),
		);
		$offset  = false !== $offsets['scriptTag'] ? $offsets['scriptTag'] : $offsets['javascript'];

		if ( false === $offset ) {
			return 'scriptTag=false javascript=false context=';
		}

		$start = max( 0, $offset - 80 );

		return sprintf(
			'scriptTag=%s javascript=%s context=%s',
			false === $offsets['scriptTag'] ? 'false' : (string) $offsets['scriptTag'],
			false === $offsets['javascript'] ? 'false' : (string) $offsets['javascript'],
			substr( $html, $start, 200 )
		);
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
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

			if ( $value instanceof \WP_List_Table ) {
				return array(
					'type'  => 'object',
					'class' => get_class( $value ),
				);
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
					'comment',
					'comment_status',
					'comment_type',
					'current_screen',
					'current_user',
					'hook_suffix',
					'mode',
					'orderby',
					'order',
					'page',
					'pagenow',
					'per_page',
					'plugins',
					'post',
					'post_id',
					'post_type',
					'post_type_object',
					'role',
					's',
					'status',
					'tax',
					'taxonomy',
					'totals',
					'user_id',
					'usersearch',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp',
					'wp_post_types',
					'wp_query',
					'wp_rewrite',
					'wp_taxonomies',
					'wp_the_query',
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

	private static function reset_stub_content(): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
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

	private static function globals_match( array $snapshot, array $names ): bool {
		foreach ( $names as $name ) {
			$expected_exists = (bool) ( $snapshot[ $name ]['exists'] ?? false );
			$actual_exists   = array_key_exists( $name, $GLOBALS );

			if ( $expected_exists !== $actual_exists ) {
				return false;
			}

			if ( $expected_exists && self::snapshot_token( $snapshot[ $name ]['value'] ) !== self::snapshot_token( $GLOBALS[ $name ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function server_match( array $snapshot, array $names ): bool {
		foreach ( $names as $name ) {
			$expected_exists = (bool) ( $snapshot[ $name ]['exists'] ?? false );
			$actual_exists   = array_key_exists( $name, $_SERVER );

			if ( $expected_exists !== $actual_exists ) {
				return false;
			}

			if ( $expected_exists && (string) $snapshot[ $name ]['value'] !== (string) $_SERVER[ $name ] ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_token( $value ): string {
		return serialize( $value );
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
			if (
				( class_exists( 'WP_Dependencies' ) && $value instanceof \WP_Dependencies )
				|| ( class_exists( '_WP_Dependency' ) && $value instanceof \_WP_Dependency )
			) {
				$copy = clone $value;
				foreach ( array_keys( get_object_vars( $copy ) ) as $property ) {
					$copy->$property = self::clone_value( $copy->$property );
				}
				return $copy;
			}

			return clone $value;
		}

		return $value;
	}
}
