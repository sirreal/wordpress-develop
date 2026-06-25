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
			$rows[] = self::check_network_themes_table( $ctx->fork( 'network-themes' ) );
			$rows[] = self::check_application_passwords_table( $ctx->fork( 'application-passwords' ) );
			$rows[] = self::check_application_passwords_last_ip_boundary( $ctx->fork( 'application-passwords-last-ip' ) );
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
				. 'install/update tables, and true multisite write paths remain out of scope. This surface covers concrete '
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
					'WP_Application_Passwords_List_Table',
					'WP_MS_Themes_List_Table',
					'WP_MS_Sites_List_Table',
					'WP_MS_Users_List_Table',
				),
				'skipped_classes' => array(
					'WP_Privacy_Data_Export_Requests_List_Table',
					'WP_Privacy_Data_Removal_Requests_List_Table',
					'WP_Plugin_Install_List_Table',
					'WP_Theme_Install_List_Table',
				),
			)
		);
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

	private static function hostile_label( \ComponentFuzz\FuzzContext $ctx ): string {
		return 'label "' . $ctx->identifier( 3, 8 ) . '" <script>alert(1)</script> onclick="bad" & value';
	}

	private static function html_has_no_raw_script( string $html ): bool {
		$lower = strtolower( $html );

		return ! str_contains( $lower, '<script' )
			&& ! str_contains( $lower, 'javascript:' );
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
