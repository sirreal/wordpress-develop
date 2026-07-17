<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes DB-backed content lifecycle APIs against the in-memory wpdb stub.
 */
final class ContentLifecycleSurface {
	public const NAME = 'content-lifecycle';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::maybe_load_optional_user_delete_support();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'content-lifecycle.bootstrap-apis-available',
					'Required WordPress lifecycle APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$case     = self::case_for_context( $ctx );
		$rows     = array();

		try {
			self::prepare_runtime();

			$rows[] = self::check_user_lifecycle( $ctx->fork( 'users' ), $case );
			self::prepare_runtime();
			$rows[] = self::check_user_insert_update_filter_meta_contracts( $ctx->fork( 'user-filter-meta' ), $case );
			self::prepare_runtime();
			$rows[] = self::check_user_delete_reassign_lifecycle( $ctx->fork( 'user-delete' ), $case );
			self::prepare_runtime();
			$rows[] = self::check_term_lifecycle( $ctx->fork( 'terms' ), $case );
			$rows[] = self::check_term_update_delete_lifecycle( $ctx->fork( 'term-update-delete' ), $case );
			self::prepare_runtime();
			$rows[] = self::check_post_lifecycle( $ctx->fork( 'posts' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_post_mutation_projection_cache_and_template_links( $ctx->fork( 'post-mutation-projection' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_post_slug_collision_and_canonical_projection( $ctx->fork( 'post-slug-canonical' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_post_slug_history_and_sample_permalink( $ctx->fork( 'post-slug-history-sample' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_sample_permalink_numeric_archive_and_plain_html( $ctx->fork( 'sample-permalink-numeric-plain' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_sample_permalink_hierarchical_page_matrix( $ctx->fork( 'sample-permalink-hierarchical-pages' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_sample_permalink_custom_hierarchical_post_type_matrix( $ctx->fork( 'sample-permalink-custom-hierarchical' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_custom_post_type_single_permalink_fallback_matrix( $ctx->fork( 'custom-single-permalink-fallbacks' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_admin_post_save_orchestration( $ctx->fork( 'admin-post-save' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_admin_bulk_post_edit_lifecycle( $ctx->fork( 'admin-bulk-post-edit' ), $case );
			$rows[] = self::check_admin_bulk_post_edit_edge_cases( $ctx->fork( 'admin-bulk-post-edit-edges' ), $case );
			self::prepare_runtime();
			$rows[] = self::check_admin_bulk_post_edit_capability_edges( $ctx->fork( 'admin-bulk-post-edit-capabilities' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_page_lookup_helpers( $ctx->fork( 'page-lookups' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_set_post_type_mutation_and_cache_cleanup( $ctx->fork( 'set-post-type' ), $case );
			$rows   = array_merge(
				$rows,
				self::check_post_status_transition_hooks( $ctx->fork( 'post-status' ), $case )
			);
			self::prepare_runtime();
			$rows[] = self::check_post_page_transition_render_visibility( $ctx->fork( 'post-page-transition-render' ), $case );
			$rows[] = self::check_post_term_relationship_lifecycle( $ctx->fork( 'post-terms' ), $case );
			$rows[] = self::check_comment_lifecycle( $ctx->fork( 'comments' ), $case );
			$rows[] = self::check_post_count_and_mime_helpers( $ctx->fork( 'post-counts-mime' ), $case );
			$rows[] = self::check_post_thumbnail_helpers( $ctx->fork( 'post-thumbnails' ), $case );
			$rows[] = self::check_invalid_inputs( $ctx->fork( 'invalid' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'content-lifecycle.surface-no-throw',
				array(
					'case'      => self::case_summary( $case ),
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_state( $snapshot );
			$rows[] = self::check_state_restored( $ctx, $case );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Post', 'WP_Post_Type', 'WP_Query', 'WP_Term', 'WP_User', 'WP_Comment', 'WP_Error', 'Component_Fuzz_WPDB_Stub' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_x',
				'absint',
				'add_action',
				'add_filter',
				'add_meta',
				'add_post_meta',
				'bulk_edit_posts',
				'clean_bookmark_cache',
				'clean_post_cache',
				'clean_user_cache',
				'current_time',
				'current_user_can',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'add_query_arg',
				'delete_meta',
				'delete_metadata_by_mid',
				'delete_post_meta',
				'delete_post_thumbnail',
				'edit_post',
				'email_exists',
				'get_available_post_mime_types',
				'get_bookmark',
				'get_the_terms',
				'get_the_post_thumbnail',
				'get_the_post_thumbnail_caption',
				'get_the_post_thumbnail_url',
				'get_comment',
				'get_post_meta',
				'get_post_meta_by_id',
				'get_post_mime_types',
				'get_post_class',
				'get_post_thumbnail_id',
				'get_post_format',
				'get_post_stati',
				'get_post_status',
				'get_post_status_object',
				'get_post',
				'get_post_type',
				'get_post_type_object',
				'get_post_permalink',
				'get_posts',
				'get_the_title',
				'get_object_taxonomies',
				'get_children',
				'get_page_by_path',
				'get_page_children',
				'get_pages',
				'get_term',
				'get_terms',
				'get_taxonomy',
				'get_post_types',
				'get_user_meta',
				'get_user_by',
				'get_userdata',
				'has_action',
				'has_filter',
				'has_post_thumbnail',
				'has_term',
				'is_object_in_term',
				'is_taxonomy_hierarchical',
				'is_user_logged_in',
				'is_wp_error',
				'is_post_publicly_viewable',
				'metadata_exists',
				'post_type_exists',
				'post_type_supports',
				'post_password_required',
				'get_permalink',
				'get_option',
				'get_gmt_from_date',
				'get_post_field',
				'get_current_user_id',
				'wp_get_ext_types',
				'wp_get_mime_types',
				'register_post_type',
				'register_taxonomy',
				'remove_action',
				'remove_filter',
				'sanitize_comment_cookies',
				'sanitize_email',
				'sanitize_key',
				'sanitize_post_field',
				'sanitize_term_field',
				'sanitize_title',
				'sanitize_user',
				'set_post_type',
				'set_post_thumbnail',
				'set_post_format',
				'stick_post',
				'term_exists',
				'the_post_thumbnail',
				'the_post_thumbnail_caption',
				'the_post_thumbnail_url',
				'wp_cache_flush',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_get_last_changed',
				'wp_cache_get_salted',
				'wp_cache_set',
				'wp_check_password',
				'wp_check_for_changed_slugs',
				'wp_delete_link',
				'wp_delete_comment',
				'wp_delete_term',
				'wp_die',
				'wp_delete_user',
				'wp_delete_object_term_relationships',
				'wp_delete_post',
				'wp_get_canonical_url',
				'wp_get_post_categories',
				'wp_get_object_terms',
				'wp_get_attachment_caption',
				'wp_get_attachment_image',
				'wp_get_attachment_image_url',
				'wp_insert_comment',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_new_comment',
				'wp_remove_object_terms',
				'wp_set_object_terms',
				'wp_set_current_user',
				'wp_set_post_lock',
				'wp_update_user',
				'wp_schedule_single_event',
				'wp_slash',
				'wp_trash_post',
				'wp_transition_post_status',
				'wp_unslash',
				'wp_check_post_lock',
				'wp_clear_scheduled_hook',
				'wp_next_scheduled',
				'wp_checkdate',
				'wp_update_term',
				'unstick_post',
				'update_option',
				'username_exists',
				'update_post_meta',
				'update_meta',
				'update_post_thumbnail_cache',
				'unregister_post_type',
				'wp_count_attachments',
				'wp_count_posts',
				'wp_update_post',
				'_count_posts_cache_key',
				'_fix_attachment_links',
				'_transition_post_status',
				'_wp_get_allowed_postdata',
				'_wp_translate_postdata',
				'wp_post_mime_type_where',
				'wp_publish_post',
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

	private static function maybe_load_optional_user_delete_support(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach ( array( 'wp-admin/includes/bookmark.php', 'wp-admin/includes/user.php' ) as $file ) {
			$path = ABSPATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function check_post_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures  = array();
		$post_type = $case['postType'];
		$events    = array();
		$hooks     = array_merge(
			self::install_post_hooks( $post_type, $events ),
			self::install_post_meta_hooks( $case['metaKey'], $case['metaUniqueKey'], $events )
		);

		try {
			\register_post_type(
				$post_type,
				array(
					'public'    => true,
					'rewrite'   => false,
					'query_var' => false,
					'supports'  => array( 'title', 'editor', 'excerpt', 'comments', 'author' ),
				)
			);

			$author_id = self::insert_support_user( 'post-author-' . $case['token'], $case['authorEmail'] );
			$post_id   = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => $post_type,
						'post_title'     => $case['title'],
						'post_content'   => $case['content'],
						'post_excerpt'   => $case['excerpt'],
						'post_status'    => $case['status'],
						'post_author'    => $author_id,
						'post_name'      => $case['slug'],
						'comment_status' => 'open',
						'ping_status'    => 'closed',
						'post_date'      => '2020-01-02 03:04:05',
						'post_date_gmt'  => '2020-01-02 03:04:05',
					)
				),
				true,
				true
			);

			$second_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => $post_type,
						'post_title'   => $case['title'] . ' second',
						'post_content' => 'second content',
						'post_status'  => 'draft',
						'post_author'  => $author_id,
						'post_name'    => $case['slug'] . '-second',
					)
				),
				true,
				false
			);

			$post = \get_post( $post_id );
			$raw  = \get_post( $post_id, ARRAY_A, 'raw' );
			self::collect_failure(
				$failures,
				is_int( $post_id ) && $post_id > 0 && $post instanceof \WP_Post,
				'post insert returned an ID readable by get_post',
				array( 'postId' => $post_id, 'post' => $post )
			);
			self::collect_failure(
				$failures,
				is_int( $second_id ) && $second_id > $post_id,
				'post IDs are monotonic',
				array( 'firstId' => $post_id, 'secondId' => $second_id )
			);

			if ( $post instanceof \WP_Post ) {
				self::collect_failure(
					$failures,
					$post_type === $post->post_type
						&& (string) $author_id === (string) $post->post_author
						&& $case['status'] === $post->post_status
						&& \sanitize_title( $case['slug'] ) === $post->post_name,
					'post type, author, status, and slug round-trip',
					array(
						'postType' => $post->post_type,
						'author'   => $post->post_author,
						'status'   => $post->post_status,
						'slug'     => $post->post_name,
						'expected' => \sanitize_title( $case['slug'] ),
					)
				);

				$display = \get_post( $post_id, OBJECT, 'display' );
				self::collect_failure(
					$failures,
					$display instanceof \WP_Post
						&& $display->post_title === \sanitize_post_field( 'post_title', $raw['post_title'], $post_id, 'display' ),
					'post display title agrees with sanitize_post_field',
					array(
						'rawTitle'     => $raw['post_title'] ?? null,
						'displayTitle' => $display instanceof \WP_Post ? $display->post_title : null,
					)
				);
			}

			$meta = self::check_post_meta_lifecycle( $post_id, $case, $events );
			self::collect_failure(
				$failures,
				$meta['ok'],
				'post metadata add/read/update/delete contracts hold',
				$meta
			);

			$updated_id = \wp_update_post(
				\wp_slash(
					array(
						'ID'           => $post_id,
						'post_title'   => $case['updatedTitle'],
						'post_content' => $case['updatedContent'],
						'post_status'  => $case['updatedStatus'],
					)
				),
				true,
				true
			);
			$updated    = \get_post( $post_id );
			self::collect_failure(
				$failures,
				$updated_id === $post_id
					&& $updated instanceof \WP_Post
					&& $case['updatedStatus'] === $updated->post_status
					&& $case['updatedContent'] === $updated->post_content,
				'post update returns same ID and refreshes readable fields',
				array(
					'updatedId'      => $updated_id,
					'updatedStatus'  => $updated instanceof \WP_Post ? $updated->post_status : null,
					'updatedContent' => $updated instanceof \WP_Post ? $updated->post_content : null,
				)
			);

			$trashed      = \wp_trash_post( $post_id );
			$after_trash  = \get_post( $post_id );
			$deleted      = \wp_delete_post( $post_id, true );
			$deleted_2    = \wp_delete_post( $second_id, true );
			$after_delete = \get_post( $post_id );
			$unique_after_delete = \metadata_exists( 'post', $post_id, $case['metaUniqueKey'] );
			self::collect_failure(
				$failures,
				$trashed instanceof \WP_Post
					&& $after_trash instanceof \WP_Post
					&& 'trash' === $after_trash->post_status
					&& $deleted instanceof \WP_Post
					&& $deleted_2 instanceof \WP_Post
					&& null === $after_delete
					&& false === $unique_after_delete,
				'post trash and force-delete mutate/read as expected',
				array(
					'trashed'           => $trashed instanceof \WP_Post,
					'afterTrashStatus'  => $after_trash instanceof \WP_Post ? $after_trash->post_status : null,
					'deleted'           => $deleted instanceof \WP_Post,
					'deletedSecond'     => $deleted_2 instanceof \WP_Post,
					'afterDeleteExists' => null !== $after_delete,
					'uniqueMetaExists'  => $unique_after_delete,
				)
			);

			self::collect_failure(
				$failures,
				self::events_are_ordered(
					$events,
					array(
						'pre_post_insert',
						"save_post_{$post_type}",
						'save_post',
						'wp_insert_post',
						'wp_after_insert_post',
						'add_post_meta',
						'added_post_meta',
						'update_post_meta',
						'updated_post_meta',
						'delete_post_meta',
						'deleted_post_meta',
						'pre_post_update',
						'post_updated',
					)
				),
				'post lifecycle and metadata hooks fire in expected relative order',
				array( 'events' => $events )
			);
		} finally {
			self::remove_hooks( $hooks );
		}

		return $ctx->result(
			'content-lifecycle.posts.insert-update-trash-delete',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
				'events'   => array_slice( $events, 0, 16 ),
			)
		);
	}

	private static function check_post_mutation_projection_cache_and_template_links( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures             = array();
		$post_id              = 0;
		$sibling_id           = 0;
		$post_type            = 'cf_proj_' . substr( $case['token'], 0, 8 );
		$initial_slug         = 'projection-before-' . $case['token'];
		$updated_slug         = 'projection-after-' . $case['token'];
		$updated_title        = 'Projection Updated ' . $case['updatedTitle'];
		$updated_content      = 'Projection content ' . $case['updatedContent'];
		$updated_excerpt      = 'Projection excerpt ' . $case['excerpt'];
		$permalink_structure  = '/%postname%/';
		$permalink_filter     = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$previous_rewrite_set = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite     = $GLOBALS['wp_rewrite'] ?? null;

		try {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			\register_post_type(
				$post_type,
				array(
					'public'    => true,
					'rewrite'   => array( 'slug' => 'projection-' . substr( $case['token'], 0, 6 ) ),
					'query_var' => false,
					'supports'  => array( 'title', 'editor', 'excerpt', 'comments' ),
				)
			);

			$post_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => $post_type,
						'post_title'     => 'Projection Initial ' . $case['title'],
						'post_content'   => 'Projection initial content ' . $case['content'],
						'post_excerpt'   => 'Projection initial excerpt ' . $case['excerpt'],
						'post_status'    => 'draft',
						'post_name'      => $initial_slug,
						'post_date'      => '2023-03-04 05:06:07',
						'post_date_gmt'  => '2023-03-04 05:06:07',
						'comment_status' => 'open',
						'ping_status'    => 'closed',
					)
				),
				true,
				false
			);
			$sibling_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => $post_type,
						'post_title'     => 'Projection Sibling ' . $case['token'],
						'post_content'   => 'Projection sibling content ' . $case['token'],
						'post_status'    => 'publish',
						'post_name'      => 'projection-sibling-' . $case['token'],
						'post_date'      => '2023-03-05 06:07:08',
						'post_date_gmt'  => '2023-03-05 06:07:08',
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					)
				),
				true,
				false
			);

			$before = is_int( $post_id ) && $post_id > 0 ? \get_post( $post_id ) : null;
			self::collect_failure(
				$failures,
				is_int( $post_id )
					&& $post_id > 0
					&& is_int( $sibling_id )
					&& $sibling_id > $post_id
					&& $before instanceof \WP_Post
					&& 'draft' === $before->post_status
					&& \sanitize_title( $initial_slug ) === $before->post_name,
				'post mutation projection fixtures insert as draft plus published sibling',
				array(
					'postId'    => self::error_summary( $post_id ),
					'siblingId' => self::error_summary( $sibling_id ),
					'before'    => self::post_summary( $before ),
				)
			);

			if ( $before instanceof \WP_Post ) {
				\wp_cache_set(
					$post_id,
					(object) array_merge(
						get_object_vars( $before ),
						array(
							'post_title' => 'Stale Projection ' . $case['token'],
							'post_name'  => 'stale-projection-' . $case['token'],
						)
					),
					'posts'
				);
				\wp_cache_set( 'post_parent:' . (string) $post_id, 'stale-parent-' . $case['token'], 'posts' );
				\wp_cache_set( $post_id, 'stale-meta-' . $case['token'], 'post_meta' );
				\wp_cache_set( 'wp_get_archives', 'stale-archives-' . $case['token'], 'general' );

				$query_cache_args = array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'name'                   => \sanitize_title( $updated_slug ),
					'fields'                 => 'ids',
					'cache_results'          => true,
					'posts_per_page'         => -1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'suppress_filters'       => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				);
				$cached_query_before = new \WP_Query( $query_cache_args );

				$updated_id = \wp_update_post(
					\wp_slash(
						array(
							'ID'           => $post_id,
							'post_title'   => $updated_title,
							'post_content' => $updated_content,
							'post_excerpt' => $updated_excerpt,
							'post_status'  => 'publish',
							'post_name'    => $updated_slug,
						)
					),
					true,
					true
				);

				$updated            = \get_post( $post_id );
				$cached_post        = \wp_cache_get( $post_id, 'posts' );
				$cached_parent      = \wp_cache_get( 'post_parent:' . (string) $post_id, 'posts' );
				$cached_meta        = \wp_cache_get( $post_id, 'post_meta' );
				$cached_archives    = \wp_cache_get( 'wp_get_archives', 'general' );
				$permalink          = \get_permalink( $post_id );
				$title              = \get_the_title( $post_id );
				$classes            = \get_post_class( array( 'cfz-projection-' . $case['token'] ), $post_id );
				$cached_query_after = new \WP_Query( $query_cache_args );
				$updated_query      = \get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'name'                   => \sanitize_title( $updated_slug ),
						'numberposts'            => -1,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'suppress_filters'       => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$old_slug_query     = \get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => array( 'draft', 'publish', 'private' ),
						'name'                   => \sanitize_title( $initial_slug ),
						'numberposts'            => -1,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'suppress_filters'       => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$published_query    = \get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'numberposts'            => -1,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'suppress_filters'       => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$updated_query_ids  = self::ids_from_posts( $updated_query );
				$old_slug_query_ids = self::ids_from_posts( $old_slug_query );
				$published_ids      = self::ids_from_posts( $published_query );
				$cached_before_ids  = self::ids_from_posts( $cached_query_before->posts ?? array() );
				$cached_after_ids   = self::ids_from_posts( $cached_query_after->posts ?? array() );
				$updated_post_name  = $updated instanceof \WP_Post ? $updated->post_name : '';

				self::collect_failure(
					$failures,
					$updated_id === $post_id
						&& $updated instanceof \WP_Post
						&& 'publish' === $updated->post_status
						&& \sanitize_title( $updated_slug ) === $updated->post_name
						&& $updated_title === $updated->post_title
						&& $updated_content === $updated->post_content
						&& $updated_excerpt === $updated->post_excerpt,
					'wp_update_post promotes the generated draft and persists slug, title, content, and excerpt mutations',
					array(
						'updatedId' => self::error_summary( $updated_id ),
						'updated'   => self::post_summary( $updated ),
						'title'     => $updated instanceof \WP_Post ? $updated->post_title : null,
						'slug'      => $updated instanceof \WP_Post ? $updated->post_name : null,
					)
				);

				self::collect_failure(
					$failures,
					is_object( $cached_post )
						&& (int) ( $cached_post->ID ?? 0 ) === $post_id
						&& 'publish' === (string) ( $cached_post->post_status ?? '' )
						&& $updated_title === (string) ( $cached_post->post_title ?? '' )
						&& \sanitize_title( $updated_slug ) === (string) ( $cached_post->post_name ?? '' )
						&& false === $cached_parent
						&& false === $cached_meta
						&& false === $cached_archives,
					'wp_update_post refreshes the post cache and evicts stale parent, metadata, and archive cache entries',
					array(
						'cachedPost'   => is_object( $cached_post ) ? self::post_summary( \get_post( (int) $cached_post->ID ) ) : $cached_post,
						'cachedTitle'  => is_object( $cached_post ) ? ( $cached_post->post_title ?? null ) : null,
						'cachedParent' => $cached_parent,
						'cachedMeta'   => $cached_meta,
						'archives'     => $cached_archives,
					)
				);

				self::collect_failure(
					$failures,
					array() === $cached_before_ids
						&& self::same_id_set( $cached_after_ids, array( $post_id ) )
						&& self::same_id_set( $updated_query_ids, array( $post_id ) )
						&& array() === $old_slug_query_ids
						&& self::same_id_set( $published_ids, array( $post_id, $sibling_id ) ),
					'cached WP_Query and get_posts project the updated publish status and slug without leaking the old slug',
					array(
						'cachedBeforeIds' => $cached_before_ids,
						'cachedAfterIds'  => $cached_after_ids,
						'updatedQueryIds' => $updated_query_ids,
						'oldSlugQueryIds' => $old_slug_query_ids,
						'publishedIds'    => $published_ids,
					)
				);

				self::collect_failure(
					$failures,
					is_string( $permalink )
						&& '' !== $updated_post_name
						&& str_contains( $permalink, '/' . $updated_post_name )
						&& str_contains( $permalink, '/projection-' . substr( $case['token'], 0, 6 ) . '/' )
						&& ! str_contains( $permalink, $before->post_name )
						&& $updated_title === $title
						&& in_array( 'post-' . $post_id, $classes, true )
						&& in_array( 'type-' . $post_type, $classes, true )
						&& in_array( 'status-publish', $classes, true )
						&& in_array( 'cfz-projection-' . $case['token'], $classes, true ),
					'template link helpers project the updated slug, title, type, and publish status',
					array(
						'permalink' => $permalink,
						'title'     => $title,
						'classes'   => $classes,
					)
				);
			}
		} finally {
			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			if ( is_int( $post_id ) && $post_id > 0 ) {
				\wp_delete_post( $post_id, true );
			}
			if ( is_int( $sibling_id ) && $sibling_id > 0 ) {
				\wp_delete_post( $sibling_id, true );
			}
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
				&& ( $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS ) )
				&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] ),
			'post mutation projection permalink filter and rewrite global are restored',
			array(
				'permalinkFilter' => \has_filter( 'pre_option_permalink_structure', $permalink_filter ),
				'rewriteRestored' => $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
					&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] ),
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.mutation-cache-query-template-projection',
			array() === $failures,
			array(
				'case'      => self::case_summary( $case ),
				'postId'    => $post_id,
				'siblingId' => $sibling_id,
				'postType'  => $post_type,
				'failures'  => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_slug_collision_and_canonical_projection( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures               = array();
		$post_ids               = array();
		$post_type              = 'cf_canon_' . substr( $case['token'], 0, 8 );
		$rewrite_slug           = 'canonical-' . substr( $case['token'], 0, 6 );
		$base_slug              = 'collision-' . $case['token'];
		$second_expected_slug   = $base_slug . '-2';
		$updated_expected_slug  = $base_slug . '-3';
		$permalink_structure    = '/%postname%/';
		$permalink_filter       = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$previous_rewrite_set   = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite       = $GLOBALS['wp_rewrite'] ?? null;
		$previous_query_set     = array_key_exists( 'wp_query', $GLOBALS );
		$previous_query         = $GLOBALS['wp_query'] ?? null;
		$previous_the_query_set = array_key_exists( 'wp_the_query', $GLOBALS );
		$previous_the_query     = $GLOBALS['wp_the_query'] ?? null;

		$prepare_singular_query = static function ( \WP_Post $post ): void {
			$query                    = new \WP_Query();
			$query->query             = array(
				'name'      => $post->post_name,
				'post_type' => $post->post_type,
			);
			$query->query_vars        = $query->query;
			$query->queried_object    = $post;
			$query->queried_object_id = (int) $post->ID;
			$query->post              = $post;
			$query->posts             = array( $post );
			$query->post_count        = 1;
			$query->is_single         = true;
			$query->is_singular       = true;
			$GLOBALS['wp_query']      = $query;
			$GLOBALS['wp_the_query']  = $query;
		};

		try {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			\register_post_type(
				$post_type,
				array(
					'public'    => true,
					'rewrite'   => array( 'slug' => $rewrite_slug ),
					'query_var' => false,
					'supports'  => array( 'title', 'editor', 'excerpt', 'author' ),
				)
			);

			$first_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => $post_type,
						'post_title'    => 'Collision First ' . $case['token'],
						'post_content'  => 'Collision first content ' . $case['content'],
						'post_status'   => 'publish',
						'post_name'     => $base_slug,
						'post_date'     => '2024-04-05 06:07:08',
						'post_date_gmt' => '2024-04-05 06:07:08',
					)
				),
				true,
				false
			);
			$second_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => $post_type,
						'post_title'    => 'Collision Second ' . $case['token'],
						'post_content'  => 'Collision second content ' . $case['updatedContent'],
						'post_status'   => 'publish',
						'post_name'     => $base_slug,
						'post_date'     => '2024-04-06 07:08:09',
						'post_date_gmt' => '2024-04-06 07:08:09',
					)
				),
				true,
				false
			);
			$draft_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => $post_type,
						'post_title'    => 'Collision Draft ' . $case['token'],
						'post_content'  => 'Collision draft content ' . $case['excerpt'],
						'post_status'   => 'draft',
						'post_name'     => 'collision-draft-' . $case['token'],
						'post_date'     => '2024-04-07 08:09:10',
						'post_date_gmt' => '2024-04-07 08:09:10',
					)
				),
				true,
				false
			);

			$post_ids = array_values(
				array_filter(
					array( $first_id, $second_id, $draft_id ),
					static function ( $id ): bool {
						return is_int( $id ) && $id > 0;
					}
				)
			);

			$first                  = is_int( $first_id ) ? \get_post( $first_id ) : null;
			$second                 = is_int( $second_id ) ? \get_post( $second_id ) : null;
			$draft                  = is_int( $draft_id ) ? \get_post( $draft_id ) : null;
			$draft_canonical_before = is_int( $draft_id ) ? \wp_get_canonical_url( $draft_id ) : null;

			self::collect_failure(
				$failures,
				$first instanceof \WP_Post
					&& $second instanceof \WP_Post
					&& $draft instanceof \WP_Post
					&& $base_slug === $first->post_name
					&& $second_expected_slug === $second->post_name
					&& 'draft' === $draft->post_status
					&& false === $draft_canonical_before,
				'published insert collisions suffix generated slugs while draft canonical URLs stay unavailable',
				array(
					'first'          => self::post_summary( $first ),
					'second'         => self::post_summary( $second ),
					'draft'          => self::post_summary( $draft ),
					'draftCanonical' => $draft_canonical_before,
					'expectedSecond' => $second_expected_slug,
				)
			);

			if ( $draft instanceof \WP_Post ) {
				$updated_title = 'Collision Updated ' . $case['updatedTitle'];
				$updated_id    = \wp_update_post(
					\wp_slash(
						array(
							'ID'           => (int) $draft->ID,
							'post_title'   => $updated_title,
							'post_status'  => 'publish',
							'post_name'    => $base_slug,
							'post_content' => 'Collision updated content ' . $case['updatedContent'],
						)
					),
					true,
					true
				);

				$updated = \get_post( $draft->ID );
				if ( $updated instanceof \WP_Post ) {
					$prepare_singular_query( $updated );
				}

				$first_permalink   = $first instanceof \WP_Post ? \get_permalink( $first ) : null;
				$second_permalink  = $second instanceof \WP_Post ? \get_permalink( $second ) : null;
				$updated_permalink = $updated instanceof \WP_Post ? \get_permalink( $updated ) : null;
				$updated_canonical = $updated instanceof \WP_Post ? \wp_get_canonical_url( $updated ) : null;
				$base_query        = \get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'name'                   => $base_slug,
						'fields'                 => 'ids',
						'numberposts'            => -1,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'suppress_filters'       => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$second_query      = \get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'name'                   => $second_expected_slug,
						'fields'                 => 'ids',
						'numberposts'            => -1,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'suppress_filters'       => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$updated_query     = \get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'name'                   => $updated_expected_slug,
						'fields'                 => 'ids',
						'numberposts'            => -1,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'suppress_filters'       => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$base_query_ids    = self::ids_from_posts( $base_query );
				$second_query_ids  = self::ids_from_posts( $second_query );
				$updated_query_ids = self::ids_from_posts( $updated_query );

				self::collect_failure(
					$failures,
					$updated_id === (int) $draft->ID
						&& $updated instanceof \WP_Post
						&& 'publish' === $updated->post_status
						&& $updated_expected_slug === $updated->post_name
						&& $updated_title === $updated->post_title
						&& self::same_id_set( $base_query_ids, array( $first_id ) )
						&& self::same_id_set( $second_query_ids, array( $second_id ) )
						&& self::same_id_set( $updated_query_ids, array( $draft_id ) ),
					'wp_update_post collision promotes draft to the next unique slug and queries address each generated slug separately',
					array(
						'updatedId'    => self::error_summary( $updated_id ),
						'updated'      => self::post_summary( $updated ),
						'baseQuery'    => $base_query_ids,
						'secondQuery'  => $second_query_ids,
						'updatedQuery' => $updated_query_ids,
					)
				);

				self::collect_failure(
					$failures,
					is_string( $first_permalink )
						&& is_string( $second_permalink )
						&& is_string( $updated_permalink )
						&& $first_permalink !== $second_permalink
						&& $second_permalink !== $updated_permalink
						&& str_contains( $first_permalink, '/' . $rewrite_slug . '/' . $base_slug )
						&& str_contains( $second_permalink, '/' . $rewrite_slug . '/' . $second_expected_slug )
						&& str_contains( $updated_permalink, '/' . $rewrite_slug . '/' . $updated_expected_slug )
						&& $updated_permalink === $updated_canonical,
					'permalink and canonical projections follow the stored unique collision slug',
					array(
						'firstPermalink'   => $first_permalink,
						'secondPermalink'  => $second_permalink,
						'updatedPermalink' => $updated_permalink,
						'updatedCanonical' => $updated_canonical,
					)
				);
			}
		} finally {
			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			if ( $previous_query_set ) {
				$GLOBALS['wp_query'] = $previous_query;
			} else {
				unset( $GLOBALS['wp_query'] );
			}

			if ( $previous_the_query_set ) {
				$GLOBALS['wp_the_query'] = $previous_the_query;
			} else {
				unset( $GLOBALS['wp_the_query'] );
			}

			foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}
		}

		$rewrite_restored   = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
			&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
		$query_restored     = $previous_query_set === array_key_exists( 'wp_query', $GLOBALS )
			&& ( ! $previous_query_set || $previous_query === $GLOBALS['wp_query'] );
		$the_query_restored = $previous_the_query_set === array_key_exists( 'wp_the_query', $GLOBALS )
			&& ( ! $previous_the_query_set || $previous_the_query === $GLOBALS['wp_the_query'] );

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
				&& $rewrite_restored
				&& $query_restored
				&& $the_query_restored,
			'post slug collision canonical row restores permalink, rewrite, and query globals',
			array(
				'permalinkFilter'  => \has_filter( 'pre_option_permalink_structure', $permalink_filter ),
				'rewriteRestored'  => $rewrite_restored,
				'queryRestored'    => $query_restored,
				'theQueryRestored' => $the_query_restored,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.slug-collision-canonical-projection',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postType' => $post_type,
				'postIds'  => $post_ids,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_slug_history_and_sample_permalink( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures             = array();
		$post_ids             = array();
		$history_base_slug    = 'history-before-' . $case['token'];
		$history_middle_slug  = 'history-middle-' . $case['token'];
		$draft_base_slug      = 'history-draft-before-' . $case['token'];
		$draft_after_slug     = 'history-draft-after-' . $case['token'];
		$page_base_slug       = 'history-page-before-' . $case['token'];
		$page_after_slug      = 'history-page-after-' . $case['token'];
		$sample_title         = 'Sample History ' . $case['token'];
		$sample_base_slug     = \sanitize_title( $sample_title );
		$manual_base_slug     = 'manual-history-' . $case['token'];
		$permalink_structure  = '/%postname%/';
		$permalink_filter     = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$previous_rewrite_set = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite     = $GLOBALS['wp_rewrite'] ?? null;
		$slug_action_priority = \has_action( 'post_updated', 'wp_check_for_changed_slugs' );
		$installed_slug_hook  = false;
		$previous_user_id     = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0;
		$cap_filter           = null;
		$editable_filter      = null;
		$sample_filter        = null;
		$html_filter          = null;
		$editable_events      = array();
		$sample_events        = array();
		$html_events          = array();

		try {
			if ( ! function_exists( 'get_sample_permalink' ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			if ( false === $slug_action_priority ) {
				\add_action( 'post_updated', 'wp_check_for_changed_slugs', 12, 3 );
				$installed_slug_hook = true;
			}

			$history_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => 'post',
						'post_title'    => 'Slug History ' . $case['title'],
						'post_content'  => 'Slug history content ' . $case['content'],
						'post_status'   => 'publish',
						'post_name'     => $history_base_slug,
						'post_date'     => '2024-05-01 02:03:04',
						'post_date_gmt' => '2024-05-01 02:03:04',
					)
				),
				true,
				false
			);
			$draft_history_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => 'post',
						'post_title'    => 'Draft Slug History ' . $case['title'],
						'post_content'  => 'Draft slug history content ' . $case['content'],
						'post_status'   => 'draft',
						'post_name'     => $draft_base_slug,
						'post_date'     => '2024-05-01 03:04:05',
						'post_date_gmt' => '2024-05-01 03:04:05',
					)
				),
				true,
				false
			);
			$page_history_id  = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => 'page',
						'post_title'    => 'Page Slug History ' . $case['title'],
						'post_content'  => 'Page slug history content ' . $case['content'],
						'post_status'   => 'publish',
						'post_name'     => $page_base_slug,
						'post_date'     => '2024-05-01 04:05:06',
						'post_date_gmt' => '2024-05-01 04:05:06',
					)
				),
				true,
				false
			);
			$sample_holder_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => 'post',
						'post_title'    => 'Sample Holder ' . $case['token'],
						'post_content'  => 'Sample holder content ' . $case['token'],
						'post_status'   => 'publish',
						'post_name'     => $sample_base_slug,
						'post_date'     => '2024-05-02 03:04:05',
						'post_date_gmt' => '2024-05-02 03:04:05',
					)
				),
				true,
				false
			);
			$manual_holder_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => 'post',
						'post_title'    => 'Manual Holder ' . $case['token'],
						'post_content'  => 'Manual holder content ' . $case['token'],
						'post_status'   => 'publish',
						'post_name'     => $manual_base_slug,
						'post_date'     => '2024-05-03 04:05:06',
						'post_date_gmt' => '2024-05-03 04:05:06',
					)
				),
				true,
				false
			);
			$sample_draft_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => 'post',
						'post_title'    => $sample_title,
						'post_content'  => 'Sample draft content ' . $case['updatedContent'],
						'post_status'   => 'draft',
						'post_name'     => '',
						'post_date'     => '2024-05-04 05:06:07',
						'post_date_gmt' => '2024-05-04 05:06:07',
					)
				),
				true,
				false
			);
			$post_ids        = array_values(
				array_filter(
					array( $history_id, $draft_history_id, $page_history_id, $sample_holder_id, $manual_holder_id, $sample_draft_id ),
					static function ( $id ): bool {
						return is_int( $id ) && $id > 0;
					}
				)
			);
			$history_before = is_int( $history_id ) ? \get_post( $history_id ) : null;
			$sample_before  = is_int( $sample_draft_id ) ? \get_post( $sample_draft_id ) : null;

			self::collect_failure(
				$failures,
				function_exists( 'get_sample_permalink' )
					&& $history_before instanceof \WP_Post
					&& $sample_before instanceof \WP_Post
					&& $history_base_slug === $history_before->post_name
					&& 'draft' === $sample_before->post_status
					&& '' === $sample_before->post_name,
				'slug history and sample permalink fixtures insert with expected starting status and slugs',
				array(
					'history'         => self::post_summary( $history_before ),
					'sampleBefore'    => self::post_summary( $sample_before ),
					'sampleFunction'  => function_exists( 'get_sample_permalink' ),
					'installedAction' => $installed_slug_hook,
					'actionPriority'  => $slug_action_priority,
				)
			);

			if ( $history_before instanceof \WP_Post ) {
				$first_update_id = \wp_update_post(
					\wp_slash(
						array(
							'ID'         => (int) $history_before->ID,
							'post_name'  => $history_middle_slug,
							'post_title' => 'Slug History Middle ' . $case['updatedTitle'],
						)
					),
					true,
					true
				);
				$history_middle = \get_post( $history_before->ID );
				$old_after_first = array_values( (array) \get_post_meta( $history_before->ID, '_wp_old_slug' ) );

				$duplicate_update_id = \wp_update_post(
					\wp_slash(
						array(
							'ID'        => (int) $history_before->ID,
							'post_name' => $history_middle_slug,
						)
					),
					true,
					true
				);
				$old_after_duplicate = array_values( (array) \get_post_meta( $history_before->ID, '_wp_old_slug' ) );

				$revert_update_id = \wp_update_post(
					\wp_slash(
						array(
							'ID'         => (int) $history_before->ID,
							'post_name'  => $history_base_slug,
							'post_title' => 'Slug History Reverted ' . $case['updatedTitle'],
						)
					),
					true,
					true
				);
				$history_reverted = \get_post( $history_before->ID );
				$old_after_revert = array_values( (array) \get_post_meta( $history_before->ID, '_wp_old_slug' ) );

				self::collect_failure(
					$failures,
					$first_update_id === (int) $history_before->ID
						&& $duplicate_update_id === (int) $history_before->ID
						&& $revert_update_id === (int) $history_before->ID
						&& $history_middle instanceof \WP_Post
						&& $history_reverted instanceof \WP_Post
						&& $history_middle_slug === $history_middle->post_name
						&& array( $history_base_slug ) === $old_after_first
						&& array( $history_base_slug ) === $old_after_duplicate
						&& $history_base_slug === $history_reverted->post_name
						&& array( $history_middle_slug ) === $old_after_revert,
					'wp_check_for_changed_slugs records the previous slug once and prunes the slug that becomes current again',
					array(
						'firstUpdate'       => self::error_summary( $first_update_id ),
						'duplicateUpdate'   => self::error_summary( $duplicate_update_id ),
						'revertUpdate'      => self::error_summary( $revert_update_id ),
						'middle'            => self::post_summary( $history_middle ),
						'reverted'          => self::post_summary( $history_reverted ),
						'oldAfterFirst'     => $old_after_first,
						'oldAfterDuplicate' => $old_after_duplicate,
						'oldAfterRevert'    => $old_after_revert,
					)
				);
			}

			if ( is_int( $draft_history_id ) && is_int( $page_history_id ) ) {
				$draft_update_id = \wp_update_post(
					\wp_slash(
						array(
							'ID'        => $draft_history_id,
							'post_name' => $draft_after_slug,
						)
					),
					true,
					true
				);
				$page_update_id  = \wp_update_post(
					\wp_slash(
						array(
							'ID'        => $page_history_id,
							'post_name' => $page_after_slug,
						)
					),
					true,
					true
				);
				$draft_after     = \get_post( $draft_history_id );
				$page_after      = \get_post( $page_history_id );
				$draft_old_slugs = array_values( (array) \get_post_meta( $draft_history_id, '_wp_old_slug' ) );
				$page_old_slugs  = array_values( (array) \get_post_meta( $page_history_id, '_wp_old_slug' ) );

				self::collect_failure(
					$failures,
					$draft_update_id === $draft_history_id
						&& $page_update_id === $page_history_id
						&& $draft_after instanceof \WP_Post
						&& $page_after instanceof \WP_Post
						&& 'draft' === $draft_after->post_status
						&& $draft_after_slug === $draft_after->post_name
						&& 'page' === $page_after->post_type
						&& $page_after_slug === $page_after->post_name
						&& array() === $draft_old_slugs
						&& array() === $page_old_slugs,
					'wp_check_for_changed_slugs ignores draft posts and hierarchical pages even when their slugs change',
					array(
						'draftUpdate' => self::error_summary( $draft_update_id ),
						'pageUpdate'  => self::error_summary( $page_update_id ),
						'draftAfter'  => self::post_summary( $draft_after ),
						'pageAfter'   => self::post_summary( $page_after ),
						'draftOld'    => $draft_old_slugs,
						'pageOld'     => $page_old_slugs,
					)
				);
			}

			if ( $sample_before instanceof \WP_Post && function_exists( 'get_sample_permalink' ) ) {
				$title_sample  = \get_sample_permalink( $sample_before->ID, $sample_title, '' );
				$manual_sample = \get_sample_permalink( $sample_before->ID, 'Manual Title ' . $case['token'], $manual_base_slug );
				$sample_after  = \get_post( $sample_before->ID );
				$sample_holder = is_int( $sample_holder_id ) ? \get_post( $sample_holder_id ) : null;
				$manual_holder = is_int( $manual_holder_id ) ? \get_post( $manual_holder_id ) : null;

				self::collect_failure(
					$failures,
					is_array( $title_sample )
						&& is_array( $manual_sample )
						&& $sample_base_slug . '-2' === ( $title_sample[1] ?? null )
						&& $manual_base_slug . '-2' === ( $manual_sample[1] ?? null )
						&& is_string( $title_sample[0] ?? null )
						&& is_string( $manual_sample[0] ?? null )
						&& str_contains( $title_sample[0], 'http://example.test/' )
						&& str_contains( $title_sample[0], '%postname%' )
						&& str_contains( $manual_sample[0], '%postname%' )
						&& $sample_after instanceof \WP_Post
						&& 'draft' === $sample_after->post_status
						&& '' === $sample_after->post_name
						&& $sample_holder instanceof \WP_Post
						&& $sample_base_slug === $sample_holder->post_name
						&& $manual_holder instanceof \WP_Post
						&& $manual_base_slug === $manual_holder->post_name,
					'get_sample_permalink derives collision-safe title and explicit-name slugs without mutating the stored draft or holders',
					array(
						'titleSample'  => $title_sample,
						'manualSample' => $manual_sample,
						'sampleAfter'  => self::post_summary( $sample_after ),
						'titleHolder'  => self::post_summary( $sample_holder ),
						'manualHolder' => self::post_summary( $manual_holder ),
					)
				);

				$html_user_id = self::insert_support_user( 'sample-html-' . $case['token'], 'sample-html-' . $case['token'] . '@example.test' );
				\wp_set_current_user( $html_user_id );
				$cap_filter = self::grant_all_caps_filter( $html_user_id );
				\add_filter( 'user_has_cap', $cap_filter, 10, 4 );

				$filter_slug    = 'html-filter-' . $case['token'];
				$published_slug = 'published-html-' . $case['token'];
				$filtered_slugs = array(
					$filter_slug    => $filter_slug . '-editable',
					$published_slug => $published_slug . '-editable',
				);
				$editable_filter = static function ( string $slug, \WP_Post $post ) use ( &$editable_events, $filtered_slugs ): string {
					$editable_events[] = array(
						'postId' => (int) $post->ID,
						'slug'   => $slug,
						'status' => $post->post_status,
						'name'   => $post->post_name,
						'filter' => $post->filter ?? null,
					);
					return $filtered_slugs[ $slug ] ?? $slug;
				};
				$sample_filter   = static function ( array $permalink, int $post_id, $title, $name, \WP_Post $post ) use ( &$sample_events ): array {
					$sample_events[] = array(
						'postId'     => $post_id,
						'title'      => $title,
						'name'       => $name,
						'status'     => $post->post_status,
						'postName'   => $post->post_name,
						'postFilter' => $post->filter ?? null,
						'sampleSlug' => $permalink[1] ?? null,
					);
					return $permalink;
				};
				$html_filter     = static function ( string $html, int $post_id, $new_title, $new_slug, \WP_Post $post ) use ( &$html_events, $case ): string {
					$html_events[] = array(
						'postId'   => $post_id,
						'title'    => $new_title,
						'slug'     => $new_slug,
						'status'   => $post->post_status,
						'postName' => $post->post_name,
					);
					return $html . '<span data-cfz-sample-html="' . $case['token'] . '">filtered</span>';
				};
				\add_filter( 'editable_slug', $editable_filter, 10, 2 );
				\add_filter( 'get_sample_permalink', $sample_filter, 10, 5 );
				\add_filter( 'get_sample_permalink_html', $html_filter, 10, 5 );

				$filtered_title         = 'HTML Filtered ' . $case['token'];
				$filtered_direct_sample = \get_sample_permalink( $sample_before->ID, $filtered_title, $filter_slug );
				$draft_html             = function_exists( 'get_sample_permalink_html' )
					? \get_sample_permalink_html( $sample_before->ID, $filtered_title, $filter_slug )
					: '';
				$published_html         = function_exists( 'get_sample_permalink_html' ) && $history_before instanceof \WP_Post
					? \get_sample_permalink_html( $history_before->ID, null, $published_slug )
					: '';
				$sample_after_html      = \get_post( $sample_before->ID );
				$published_after_html   = $history_before instanceof \WP_Post ? \get_post( $history_before->ID ) : null;
				$preview_link           = $sample_after_html instanceof \WP_Post ? \get_preview_post_link( $sample_after_html ) : null;
				$published_permalink    = $published_after_html instanceof \WP_Post ? \get_permalink( $published_after_html ) : null;
				$sample_event_slugs     = array_values(
					array_map(
						static function ( array $event ) {
							return $event['sampleSlug'] ?? null;
						},
						$sample_events
					)
				);
				$editable_events_ok     = 3 === count( $editable_events );
				foreach ( $editable_events as $editable_event ) {
					if (
						'publish' !== ( $editable_event['status'] ?? null )
						|| 'sample' !== ( $editable_event['filter'] ?? null )
						|| ! in_array( $editable_event['slug'] ?? null, array( $filter_slug, $filter_slug . '-editable', $published_slug ), true )
					) {
						$editable_events_ok = false;
						break;
					}
				}

				self::collect_failure(
					$failures,
					is_array( $filtered_direct_sample )
						&& $filter_slug . '-editable' === ( $filtered_direct_sample[1] ?? null )
						&& is_string( $draft_html )
						&& str_contains( $draft_html, 'id="sample-permalink"' )
						&& str_contains( $draft_html, 'id="editable-post-name"' )
						&& str_contains( $draft_html, 'id="editable-post-name-full">' . $filter_slug . '-editable</span>' )
						&& str_contains( $draft_html, "target='wp-preview-{$sample_before->ID}'" )
						&& is_string( $preview_link )
						&& str_contains( $draft_html, 'preview=true' )
						&& is_string( $published_html )
						&& is_string( $published_permalink )
						&& str_contains( $published_html, 'href="' . $published_permalink . '"' )
						&& str_contains( $published_html, 'id="editable-post-name-full">' . $published_slug . '-editable</span>' )
						&& $editable_events_ok
						&& 3 === count( $sample_events )
						&& 2 === count( $html_events )
						&& in_array( $filter_slug . '-editable', $sample_event_slugs, true )
						&& in_array( $published_slug . '-editable', $sample_event_slugs, true )
						&& $sample_after_html instanceof \WP_Post
						&& 'draft' === $sample_after_html->post_status
						&& '' === $sample_after_html->post_name,
					'get_sample_permalink_html projects draft preview and published view links through editable slug and HTML filters without mutating stored posts',
					array(
						'directSample'       => $filtered_direct_sample,
						'draftHtml'          => $draft_html,
						'publishedHtml'      => $published_html,
						'previewLink'        => $preview_link,
						'publishedPermalink' => $published_permalink,
						'editableEvents'     => $editable_events,
						'sampleEvents'       => $sample_events,
						'htmlEvents'         => $html_events,
						'sampleAfter'        => self::post_summary( $sample_after_html ),
						'publishedAfter'     => self::post_summary( $published_after_html ),
					)
				);
			}
		} finally {
			if ( null !== $html_filter ) {
				\remove_filter( 'get_sample_permalink_html', $html_filter, 10 );
			}
			if ( null !== $sample_filter ) {
				\remove_filter( 'get_sample_permalink', $sample_filter, 10 );
			}
			if ( null !== $editable_filter ) {
				\remove_filter( 'editable_slug', $editable_filter, 10 );
			}
			if ( null !== $cap_filter ) {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}
			\wp_set_current_user( $previous_user_id );

			if ( $installed_slug_hook ) {
				\remove_action( 'post_updated', 'wp_check_for_changed_slugs', 12 );
			}

			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}
		}

		$slug_action_restored = false === $slug_action_priority
			? false === \has_action( 'post_updated', 'wp_check_for_changed_slugs' )
			: $slug_action_priority === \has_action( 'post_updated', 'wp_check_for_changed_slugs' );
		$rewrite_restored    = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
			&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
		$sample_filters_restored = ( null === $editable_filter || false === \has_filter( 'editable_slug', $editable_filter ) )
			&& ( null === $sample_filter || false === \has_filter( 'get_sample_permalink', $sample_filter ) )
			&& ( null === $html_filter || false === \has_filter( 'get_sample_permalink_html', $html_filter ) )
			&& ( null === $cap_filter || false === \has_filter( 'user_has_cap', $cap_filter ) );
		$current_user_restored = $previous_user_id === ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0 );

		self::collect_failure(
			$failures,
			$slug_action_restored
				&& false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
				&& $rewrite_restored
				&& $sample_filters_restored
				&& $current_user_restored,
			'post slug history sample row restores slug action, sample filters, current user, permalink filter, and rewrite global',
			array(
				'slugAction'      => \has_action( 'post_updated', 'wp_check_for_changed_slugs' ),
				'expectedAction'  => $slug_action_priority,
				'permalinkFilter' => \has_filter( 'pre_option_permalink_structure', $permalink_filter ),
				'rewriteRestored' => $rewrite_restored,
				'sampleFilters'   => $sample_filters_restored,
				'currentUser'     => isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.slug-history-sample-permalink',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postIds'  => $post_ids,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_sample_permalink_numeric_archive_and_plain_html( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures                    = array();
		$post_ids                    = array();
		$current_permalink_structure = '/%postname%/';
		$active_unique_label         = null;
		$unique_slug_events          = array();
		$numeric_observed            = array();
		$plain_observed              = array();
		$previous_rewrite_set        = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite            = $GLOBALS['wp_rewrite'] ?? null;
		$previous_user_id            = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0;
		$manage_cap_filter           = null;
		$read_cap_filter             = null;

		$permalink_filter = static function () use ( &$current_permalink_structure ): string {
			return $current_permalink_structure;
		};
		$show_on_front_filter = static function (): string {
			return 'posts';
		};
		$page_on_front_filter = static function (): int {
			return 0;
		};
		$set_permalink_structure = static function ( string $structure ) use ( &$current_permalink_structure ): void {
			$current_permalink_structure = $structure;

			if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
				$GLOBALS['wp_rewrite']->permalink_structure = $structure;
			}
		};
		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$html_has_no_slug_editor = static function ( string $html ): bool {
			return ! str_contains( $html, 'editable-post-name' )
				&& ! str_contains( $html, 'editable-post-name-full' )
				&& ! str_contains( $html, 'edit-slug' )
				&& ! str_contains( $html, '%postname%' )
				&& ! str_contains( $html, '%pagename%' );
		};
		$unique_slug_filter = static function ( $override_slug, string $slug, int $post_id, string $post_status, string $post_type, int $post_parent ) use ( &$active_unique_label, &$unique_slug_events ) {
			if ( null !== $active_unique_label ) {
				$unique_slug_events[] = array(
					'label'    => $active_unique_label,
					'slug'     => $slug,
					'postId'   => $post_id,
					'status'   => $post_status,
					'type'     => $post_type,
					'parent'   => $post_parent,
					'override' => $override_slug,
				);
			}

			return $override_slug;
		};

		try {
			if ( ( ! function_exists( 'get_sample_permalink' ) || ! function_exists( 'get_sample_permalink_html' ) ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$set_permalink_structure( $current_permalink_structure );
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );
			\add_filter( 'pre_option_show_on_front', $show_on_front_filter );
			\add_filter( 'pre_option_page_on_front', $page_on_front_filter );
			\add_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10, 6 );

			$functions_available = function_exists( 'get_sample_permalink' ) && function_exists( 'get_sample_permalink_html' );
			self::collect_failure(
				$failures,
				$functions_available,
				'sample permalink admin helpers are available for numeric and plain permalink checks',
				array(
					'getSamplePermalink'     => function_exists( 'get_sample_permalink' ),
					'getSamplePermalinkHtml' => function_exists( 'get_sample_permalink_html' ),
				)
			);

			if ( $functions_available ) {
				$numeric_matrix = array(
					array(
						'label'     => 'year-root',
						'structure' => '/%postname%/',
						'slug'      => '2015',
						'expected'  => '2015-2',
					),
					array(
						'label'     => 'year-after-year',
						'structure' => '/%year%/%postname%/',
						'slug'      => '2015',
						'expected'  => '2015',
					),
					array(
						'label'     => 'month-after-year',
						'structure' => '/%year%/%postname%/',
						'slug'      => '11',
						'expected'  => '11-2',
					),
					array(
						'label'     => 'invalid-month-after-year',
						'structure' => '/%year%/%postname%/',
						'slug'      => '13',
						'expected'  => '13',
					),
					array(
						'label'     => 'day-after-month',
						'structure' => '/%year%/%monthnum%/%postname%/',
						'slug'      => '30',
						'expected'  => '30-2',
					),
					array(
						'label'     => 'day-after-month-iterate',
						'structure' => '/%year%/%monthnum%/%postname%/',
						'slug'      => '30',
						'expected'  => '30-3',
						'holder'    => '30-2',
					),
					array(
						'label'     => 'invalid-day-after-month',
						'structure' => '/%year%/%monthnum%/%postname%/',
						'slug'      => '32',
						'expected'  => '32',
					),
					array(
						'label'     => 'postname-after-day',
						'structure' => '/%year%/%monthnum%/%day%/%postname%/',
						'slug'      => '30',
						'expected'  => '30',
					),
				);
				$numeric_matrix_ok = true;

				foreach ( $numeric_matrix as $index => $entry ) {
					$set_permalink_structure( $entry['structure'] );
					$holder_id   = 0;
					$holder_post = null;

					if ( isset( $entry['holder'] ) ) {
						$holder_id = $remember_post(
							\wp_insert_post(
								\wp_slash(
									array(
										'post_type'    => 'post',
										'post_title'   => 'Sample Permalink Holder ' . $entry['label'] . ' ' . $case['token'],
										'post_content' => 'Sample permalink holder content ' . $case['token'],
										'post_status'  => 'publish',
										'post_name'    => $entry['holder'],
									)
								),
								true,
								false
							)
						);
						$holder_post = $holder_id > 0 ? \get_post( $holder_id ) : null;
					}

					$draft_id = $remember_post(
						\wp_insert_post(
							\wp_slash(
								array(
									'post_type'     => 'post',
									'post_title'    => 'Sample Numeric ' . $entry['label'] . ' ' . $case['token'],
									'post_content'  => 'Sample numeric content ' . $case['updatedContent'],
									'post_status'   => 'draft',
									'post_name'     => 'sample-numeric-' . $entry['label'] . '-' . $case['token'],
									'post_date'     => '2024-06-' . str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) . ' 02:03:04',
									'post_date_gmt' => '2024-06-' . str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) . ' 02:03:04',
								)
							),
							true,
							false
						)
					);
					$before = $draft_id > 0 ? \get_post( $draft_id ) : null;
					$sample = array();

					$active_unique_label = $entry['label'];
					try {
						$sample = $draft_id > 0 ? \get_sample_permalink( $draft_id, null, $entry['slug'] ) : array();
					} finally {
						$active_unique_label = null;
					}

					$after = $draft_id > 0 ? \get_post( $draft_id ) : null;
					$event = null;
					foreach ( $unique_slug_events as $unique_slug_event ) {
						if ( $entry['label'] === ( $unique_slug_event['label'] ?? null ) ) {
							$event = $unique_slug_event;
							break;
						}
					}

					$entry_ok = is_array( $sample )
						&& $entry['expected'] === ( $sample[1] ?? null )
						&& is_string( $sample[0] ?? null )
						&& str_contains( $sample[0], 'http://example.test/' )
						&& str_contains( $sample[0], '%postname%' )
						&& $before instanceof \WP_Post
						&& $after instanceof \WP_Post
						&& 'draft' === $after->post_status
						&& $before->post_name === $after->post_name
						&& $before->post_status === $after->post_status
						&& is_array( $event )
						&& $entry['slug'] === ( $event['slug'] ?? null )
						&& $draft_id === (int) ( $event['postId'] ?? 0 )
						&& 'publish' === ( $event['status'] ?? null )
						&& 'post' === ( $event['type'] ?? null )
						&& 0 === (int) ( $event['parent'] ?? -1 )
						&& (
							! isset( $entry['holder'] )
							|| (
								$holder_post instanceof \WP_Post
								&& $entry['holder'] === $holder_post->post_name
								&& 'publish' === $holder_post->post_status
							)
						);
					$numeric_matrix_ok = $numeric_matrix_ok && $entry_ok;
					$numeric_observed[] = array(
						'label'     => $entry['label'],
						'structure' => $entry['structure'],
						'slug'      => $entry['slug'],
						'expected'  => $entry['expected'],
						'sample'    => $sample,
						'before'    => self::post_summary( $before ),
						'after'     => self::post_summary( $after ),
						'holder'    => self::post_summary( $holder_post ),
						'event'     => $event,
						'ok'        => $entry_ok,
					);
				}

				self::collect_failure(
					$failures,
					$numeric_matrix_ok && count( $numeric_matrix ) === count( $unique_slug_events ),
					'get_sample_permalink applies date-archive numeric slug rules through publish-status uniqueness without mutating stored drafts or holders',
					array(
						'matrix' => $numeric_observed,
						'events' => $unique_slug_events,
					)
				);

				$set_permalink_structure( '' );
				$future_date = gmdate( 'Y-m-d H:i:s', time() + 2592000 );
				$plain_future_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'     => 'post',
								'post_title'    => 'Plain Future ' . $case['token'],
								'post_content'  => 'Plain future content ' . $case['token'],
								'post_status'   => 'future',
								'post_name'     => 'plain-future-' . $case['token'],
								'post_date'     => $future_date,
								'post_date_gmt' => $future_date,
							)
						),
						true,
						false
					)
				);
				$plain_draft_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'post',
								'post_title'   => 'Plain Draft ' . $case['token'],
								'post_content' => 'Plain draft content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => '',
							)
						),
						true,
						false
					)
				);
				$plain_read_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'post',
								'post_title'   => 'Plain Read Only ' . $case['token'],
								'post_content' => 'Plain read only content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => 'plain-read-only-' . $case['token'],
							)
						),
						true,
						false
					)
				);
				$plain_no_read_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'post',
								'post_title'   => 'Plain No Read ' . $case['token'],
								'post_content' => 'Plain no read content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => 'plain-no-read-' . $case['token'],
							)
						),
						true,
						false
					)
				);

				$manage_user_id = self::insert_support_user( 'plain-manage-' . $case['token'], 'plain-manage-' . $case['token'] . '@example.test' );
				if ( $manage_user_id > 0 ) {
					$manage_cap_filter = self::grant_all_caps_filter( (int) $manage_user_id );
					\wp_set_current_user( (int) $manage_user_id );
					\add_filter( 'user_has_cap', $manage_cap_filter, 10, 4 );
				}

				$plain_future_html = $plain_future_id > 0 ? \get_sample_permalink_html( $plain_future_id ) : '';
				$plain_draft_html  = $plain_draft_id > 0 ? \get_sample_permalink_html( $plain_draft_id ) : '';
				$plain_future_url  = $plain_future_id > 0 ? \get_option( 'home' ) . '/?p=' . $plain_future_id : '';
				$plain_draft_preview = $plain_draft_id > 0 ? \get_preview_post_link( $plain_draft_id ) : null;

				$read_user_id = self::insert_support_user( 'plain-read-' . $case['token'], 'plain-read-' . $case['token'] . '@example.test' );
				if ( $read_user_id > 0 ) {
					$read_cap_filter = static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $read_user_id ): array {
						unset( $args );

						if ( (int) $user->ID !== (int) $read_user_id ) {
							return $allcaps;
						}

						foreach ( $caps as $cap ) {
							if ( 'do_not_allow' !== $cap && 'manage_options' !== $cap ) {
								$allcaps[ $cap ] = true;
							}
						}

						$allcaps['read']           = true;
						$allcaps['read_post']      = true;
						$allcaps['manage_options'] = false;

						return $allcaps;
					};
					\wp_set_current_user( (int) $read_user_id );
					\add_filter( 'user_has_cap', $read_cap_filter, 10, 4 );
				}

				$plain_read_html = $plain_read_id > 0 ? \get_sample_permalink_html( $plain_read_id ) : '';
				$plain_read_url  = $plain_read_id > 0 ? \get_option( 'home' ) . '/?p=' . $plain_read_id : '';

				\wp_set_current_user( 0 );
				$plain_no_read_html = $plain_no_read_id > 0 ? \get_sample_permalink_html( $plain_no_read_id ) : '';
				$plain_no_read_url  = $plain_no_read_id > 0 ? \get_option( 'home' ) . '/?p=' . $plain_no_read_id : '';

				$plain_observed = array(
					'manageUser' => $manage_user_id,
					'readUser'   => $read_user_id,
					'future'     => array(
						'id'   => $plain_future_id,
						'url'  => $plain_future_url,
						'html' => $plain_future_html,
					),
					'draft'      => array(
						'id'      => $plain_draft_id,
						'preview' => $plain_draft_preview,
						'html'    => $plain_draft_html,
					),
					'readOnly'   => array(
						'id'   => $plain_read_id,
						'url'  => $plain_read_url,
						'html' => $plain_read_html,
					),
					'noRead'     => array(
						'id'   => $plain_no_read_id,
						'url'  => $plain_no_read_url,
						'html' => $plain_no_read_html,
					),
				);

				self::collect_failure(
					$failures,
					$manage_user_id > 0
						&& $read_user_id > 0
						&& is_string( $plain_future_html )
						&& str_contains( $plain_future_html, 'href="' . $plain_future_url . '"' )
						&& str_contains( $plain_future_html, '>' . $plain_future_url . '<' )
						&& str_contains( $plain_future_html, 'id="change-permalinks"' )
						&& $html_has_no_slug_editor( $plain_future_html )
						&& is_string( $plain_draft_html )
						&& str_contains( $plain_draft_html, "target='wp-preview-{$plain_draft_id}'" )
						&& str_contains( $plain_draft_html, 'preview=true' )
						&& $html_has_no_slug_editor( $plain_draft_html )
						&& is_string( $plain_read_html )
						&& str_contains( $plain_read_html, 'href="' . $plain_read_url . '"' )
						&& ! str_contains( $plain_read_html, 'id="change-permalinks"' )
						&& $html_has_no_slug_editor( $plain_read_html )
						&& is_string( $plain_no_read_html )
						&& str_contains( $plain_no_read_html, '<span id="sample-permalink">' . $plain_no_read_url . '</span>' )
						&& ! str_contains( $plain_no_read_html, '<a id="sample-permalink"' )
						&& ! str_contains( $plain_no_read_html, 'href="' )
						&& ! str_contains( $plain_no_read_html, 'id="change-permalinks"' )
						&& $html_has_no_slug_editor( $plain_no_read_html ),
					'get_sample_permalink_html uses the plain-permalink no-placeholder branch for read, preview, no-read, and manage-options variants',
					$plain_observed
				);
			}
		} finally {
			if ( null !== $read_cap_filter ) {
				\remove_filter( 'user_has_cap', $read_cap_filter, 10 );
			}
			if ( null !== $manage_cap_filter ) {
				\remove_filter( 'user_has_cap', $manage_cap_filter, 10 );
			}
			\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
			\remove_filter( 'pre_option_page_on_front', $page_on_front_filter );
			\remove_filter( 'pre_option_show_on_front', $show_on_front_filter );
			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			\wp_set_current_user( $previous_user_id );

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}
		}

		$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
			&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
		$current_user_restored = $previous_user_id === ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0 );
		$filters_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
			&& false === \has_filter( 'pre_option_show_on_front', $show_on_front_filter )
			&& false === \has_filter( 'pre_option_page_on_front', $page_on_front_filter )
			&& false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter )
			&& ( null === $manage_cap_filter || false === \has_filter( 'user_has_cap', $manage_cap_filter ) )
			&& ( null === $read_cap_filter || false === \has_filter( 'user_has_cap', $read_cap_filter ) );

		self::collect_failure(
			$failures,
			$rewrite_restored && $current_user_restored && $filters_restored,
			'sample permalink numeric/plain row restores rewrite, current user, option filters, uniqueness filter, and capability filters',
			array(
				'rewriteRestored'     => $rewrite_restored,
				'currentUserRestored' => $current_user_restored,
				'filtersRestored'     => $filters_restored,
				'postIds'             => $post_ids,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.sample-permalink-numeric-plain-html',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postIds'  => $post_ids,
				'numeric'  => $numeric_observed,
				'plain'    => $plain_observed,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_sample_permalink_hierarchical_page_matrix( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures             = array();
		$post_ids             = array();
		$unique_slug_events   = array();
		$editable_events      = array();
		$matrix_observed      = array();
		$html_observed        = array();
		$active_label         = null;
		$permalink_structure  = '/%postname%/';
		$previous_rewrite_set = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite     = $GLOBALS['wp_rewrite'] ?? null;
		$previous_user_id     = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0;
		$cap_filter           = null;

		$permalink_filter = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$unique_slug_filter = static function ( $override_slug, string $slug, int $post_id, string $post_status, string $post_type, int $post_parent ) use ( &$active_label, &$unique_slug_events ) {
			if ( null !== $active_label ) {
				$unique_slug_events[] = array(
					'label'    => $active_label,
					'slug'     => $slug,
					'postId'   => $post_id,
					'status'   => $post_status,
					'type'     => $post_type,
					'parent'   => $post_parent,
					'override' => $override_slug,
				);
			}

			return $override_slug;
		};
		$editable_filter = static function ( string $slug, \WP_Post $post ) use ( &$active_label, &$editable_events ): string {
			if ( null !== $active_label ) {
				$editable_events[] = array(
					'label'  => $active_label,
					'slug'   => $slug,
					'postId' => (int) $post->ID,
					'status' => $post->post_status,
					'name'   => $post->post_name,
					'parent' => (int) $post->post_parent,
					'filter' => $post->filter ?? null,
				);
			}

			return $slug;
		};

		try {
			if ( ( ! function_exists( 'get_sample_permalink' ) || ! function_exists( 'get_sample_permalink_html' ) ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );
			\add_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10, 6 );
			\add_filter( 'editable_slug', $editable_filter, 10, 2 );

			$functions_available = function_exists( 'get_sample_permalink' ) && function_exists( 'get_sample_permalink_html' );
			self::collect_failure(
				$failures,
				$functions_available,
				'hierarchical sample permalink admin helpers are available',
				array(
					'getSamplePermalink'     => function_exists( 'get_sample_permalink' ),
					'getSamplePermalinkHtml' => function_exists( 'get_sample_permalink_html' ),
				)
			);

			if ( $functions_available ) {
				$parent_slug        = 'sample-page-parent-' . $case['token'];
				$other_parent_slug  = 'sample-page-other-parent-' . $case['token'];
				$collision_slug     = 'sample-page-child-' . $case['token'];
				$other_parent_slug_collision = 'sample-page-other-child-' . $case['token'];
				$attachment_slug    = 'sample-page-attachment-' . $case['token'];
				$child_title        = 'Hierarchical Child ' . $case['token'];
				$child_title_slug   = \sanitize_title( $child_title );
				$expected_template  = \home_url( '/' . $parent_slug . '/%pagename%' );

				$parent_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Sample Parent ' . $case['token'],
								'post_content' => 'Sample parent content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $parent_slug,
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$other_parent_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Sample Other Parent ' . $case['token'],
								'post_content' => 'Sample other parent content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $other_parent_slug,
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$same_parent_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Same Parent Holder ' . $case['token'],
								'post_content' => 'Same parent holder content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $collision_slug,
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$same_parent_suffix_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Same Parent Suffix Holder ' . $case['token'],
								'post_content' => 'Same parent suffix holder content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $collision_slug . '-2',
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$other_parent_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Other Parent Holder ' . $case['token'],
								'post_content' => 'Other parent holder content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $other_parent_slug_collision,
								'post_parent'  => $other_parent_id,
							)
						),
						true,
						false
					)
				);
				$attachment_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'      => 'attachment',
								'post_title'     => 'Attachment Holder ' . $case['token'],
								'post_mime_type' => 'image/jpeg',
								'post_status'    => 'inherit',
								'post_name'      => $attachment_slug,
								'post_parent'    => $parent_id,
								'guid'           => 'http://example.test/' . $attachment_slug . '.jpg',
							)
						),
						true,
						false
					)
				);
				$title_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => $child_title,
								'post_content' => 'Title child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => '',
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$collision_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Collision Child ' . $case['token'],
								'post_content' => 'Collision child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'stored-collision-' . $case['token'],
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$other_parent_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Other Parent Name Child ' . $case['token'],
								'post_content' => 'Other parent name child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'stored-other-parent-' . $case['token'],
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$numeric_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Numeric Child ' . $case['token'],
								'post_content' => 'Numeric child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'stored-numeric-' . $case['token'],
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$attachment_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'Attachment Name Child ' . $case['token'],
								'post_content' => 'Attachment name child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'stored-attachment-' . $case['token'],
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);

				$matrix = array(
					array(
						'label'    => 'title-child',
						'postId'   => $title_child_id,
						'title'    => null,
						'name'     => null,
						'requestedSlug' => $child_title_slug,
						'expected' => $child_title_slug,
					),
					array(
						'label'    => 'same-parent-collision',
						'postId'   => $collision_child_id,
						'title'    => 'Collision Override ' . $case['token'],
						'name'     => $collision_slug,
						'requestedSlug' => $collision_slug,
						'expected' => $collision_slug . '-3',
					),
					array(
						'label'    => 'other-parent-allowed',
						'postId'   => $other_parent_child_id,
						'title'    => 'Other Parent Override ' . $case['token'],
						'name'     => $other_parent_slug_collision,
						'requestedSlug' => $other_parent_slug_collision,
						'expected' => $other_parent_slug_collision,
					),
					array(
						'label'    => 'numeric-page-slug',
						'postId'   => $numeric_child_id,
						'title'    => 'Numeric Override ' . $case['token'],
						'name'     => '123',
						'requestedSlug' => '123',
						'expected' => '123-2',
					),
					array(
						'label'    => 'attachment-same-parent-collision',
						'postId'   => $attachment_child_id,
						'title'    => 'Attachment Override ' . $case['token'],
						'name'     => $attachment_slug,
						'requestedSlug' => $attachment_slug,
						'expected' => $attachment_slug . '-2',
					),
				);
				$matrix_ok = $parent_id > 0
					&& $other_parent_id > 0
					&& $same_parent_holder_id > 0
					&& $same_parent_suffix_holder_id > 0
					&& $other_parent_holder_id > 0
					&& $attachment_holder_id > 0;

				foreach ( $matrix as $entry ) {
					$before = $entry['postId'] > 0 ? \get_post( $entry['postId'] ) : null;
					$sample = array();
					$active_label = $entry['label'];
					try {
						$sample = null === $entry['name']
							? ( $entry['postId'] > 0 ? \get_sample_permalink( $entry['postId'] ) : array() )
							: ( $entry['postId'] > 0 ? \get_sample_permalink( $entry['postId'], $entry['title'], $entry['name'] ) : array() );
					} finally {
						$active_label = null;
					}
					$after = $entry['postId'] > 0 ? \get_post( $entry['postId'] ) : null;
					$label_unique_events = array_values(
						array_filter(
							$unique_slug_events,
							static function ( array $event ) use ( $entry ): bool {
								return $entry['label'] === ( $event['label'] ?? null );
							}
						)
					);
					$label_editable_events = array_values(
						array_filter(
							$editable_events,
							static function ( array $event ) use ( $entry ): bool {
								return $entry['label'] === ( $event['label'] ?? null );
							}
						)
					);
					$unique_event  = $label_unique_events[0] ?? null;
					$uri_event     = $label_editable_events[0] ?? null;
					$slug_event    = $label_editable_events[1] ?? null;
					$entry_ok      = is_array( $sample )
						&& $expected_template === ( $sample[0] ?? null )
						&& $entry['expected'] === ( $sample[1] ?? null )
						&& $before instanceof \WP_Post
						&& $after instanceof \WP_Post
						&& 'draft' === $after->post_status
						&& $before->post_name === $after->post_name
						&& (int) $parent_id === (int) $after->post_parent
						&& 1 === count( $label_unique_events )
						&& is_array( $unique_event )
						&& $entry['requestedSlug'] === ( $unique_event['slug'] ?? null )
						&& $entry['postId'] === (int) ( $unique_event['postId'] ?? 0 )
						&& 'publish' === ( $unique_event['status'] ?? null )
						&& 'page' === ( $unique_event['type'] ?? null )
						&& $parent_id === (int) ( $unique_event['parent'] ?? 0 )
						&& 2 === count( $label_editable_events )
						&& is_array( $uri_event )
						&& $parent_slug === ( $uri_event['slug'] ?? null )
						&& 'publish' === ( $uri_event['status'] ?? null )
						&& 'sample' === ( $uri_event['filter'] ?? null )
						&& is_array( $slug_event )
						&& $entry['expected'] === ( $slug_event['slug'] ?? null )
						&& 'publish' === ( $slug_event['status'] ?? null )
						&& 'sample' === ( $slug_event['filter'] ?? null );
					$matrix_ok = $matrix_ok && $entry_ok;
					$matrix_observed[] = array(
						'label'    => $entry['label'],
						'sample'   => $sample,
						'before'   => self::post_summary( $before ),
						'after'    => self::post_summary( $after ),
						'unique'   => $label_unique_events,
						'editable' => $label_editable_events,
						'ok'       => $entry_ok,
					);
				}

				self::collect_failure(
					$failures,
					$matrix_ok,
					'get_sample_permalink preserves page parent paths and parent-scoped slug uniqueness for title, collision, numeric, and attachment cases',
					array(
						'parent'   => $parent_id > 0 ? self::post_summary( \get_post( $parent_id ) ) : null,
						'holders'  => array(
							self::post_summary( \get_post( $same_parent_holder_id ) ),
							self::post_summary( \get_post( $same_parent_suffix_holder_id ) ),
							self::post_summary( \get_post( $other_parent_holder_id ) ),
							self::post_summary( \get_post( $attachment_holder_id ) ),
						),
						'matrix'   => $matrix_observed,
						'expected' => $expected_template,
					)
				);

				$html_user_id = self::insert_support_user( 'page-sample-html-' . $case['token'], 'page-sample-html-' . $case['token'] . '@example.test' );
				if ( $html_user_id > 0 ) {
					$cap_filter = self::grant_all_caps_filter( (int) $html_user_id );
					\wp_set_current_user( (int) $html_user_id );
					\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
				}

				$active_label = 'html-same-parent-collision';
				try {
					$html = $collision_child_id > 0 ? \get_sample_permalink_html( $collision_child_id, null, $collision_slug ) : '';
				} finally {
					$active_label = null;
				}
				$html_after     = $collision_child_id > 0 ? \get_post( $collision_child_id ) : null;
				$preview_link   = $html_after instanceof \WP_Post ? \get_preview_post_link( $html_after ) : null;
				$html_unique    = array_values(
					array_filter(
						$unique_slug_events,
						static function ( array $event ): bool {
							return 'html-same-parent-collision' === ( $event['label'] ?? null );
						}
					)
				);
				$html_editable  = array_values(
					array_filter(
						$editable_events,
						static function ( array $event ): bool {
							return 'html-same-parent-collision' === ( $event['label'] ?? null );
						}
					)
				);
				$html_observed = array(
					'html'      => $html,
					'preview'   => $preview_link,
					'postAfter' => self::post_summary( $html_after ),
					'unique'    => $html_unique,
					'editable'  => $html_editable,
				);

				self::collect_failure(
					$failures,
					$html_user_id > 0
						&& is_string( $html )
						&& str_contains( $html, 'id="sample-permalink"' )
						&& str_contains( $html, 'id="editable-post-name-full">' . $collision_slug . '-3</span>' )
						&& str_contains( $html, $parent_slug . '/<span id="editable-post-name">' )
						&& str_contains( $html, "target='wp-preview-{$collision_child_id}'" )
						&& str_contains( $html, 'preview=true' )
						&& $html_after instanceof \WP_Post
						&& 'draft' === $html_after->post_status
						&& 'stored-collision-' . $case['token'] === $html_after->post_name
						&& 1 === count( $html_unique )
						&& 2 === count( $html_editable ),
					'get_sample_permalink_html renders hierarchical draft preview links with parent path display without mutating the stored page',
					$html_observed
				);
			}
		} finally {
			if ( null !== $cap_filter ) {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}
			\remove_filter( 'editable_slug', $editable_filter, 10 );
			\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			\wp_set_current_user( $previous_user_id );

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			foreach ( array_reverse( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}
		}

		$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
			&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
		$current_user_restored = $previous_user_id === ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0 );
		$filters_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
			&& false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter )
			&& false === \has_filter( 'editable_slug', $editable_filter )
			&& ( null === $cap_filter || false === \has_filter( 'user_has_cap', $cap_filter ) );

		self::collect_failure(
			$failures,
			$rewrite_restored && $current_user_restored && $filters_restored,
			'hierarchical sample permalink row restores rewrite, current user, permalink, uniqueness, editable slug, and capability filters',
			array(
				'rewriteRestored'     => $rewrite_restored,
				'currentUserRestored' => $current_user_restored,
				'filtersRestored'     => $filters_restored,
				'postIds'             => $post_ids,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.sample-permalink-hierarchical-pages',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postIds'  => $post_ids,
				'matrix'   => $matrix_observed,
				'html'     => $html_observed,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_sample_permalink_custom_hierarchical_post_type_matrix( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures             = array();
		$post_ids             = array();
		$unique_slug_events   = array();
		$editable_events      = array();
		$link_events          = array();
		$matrix_observed      = array();
		$html_observed        = array();
		$active_label         = null;
		$token                = substr( $case['token'], 0, 8 );
		$post_type            = 'cfh_' . $token;
		$rewrite_slug         = 'custom-hier-' . $token;
		$permalink_structure  = '/%postname%/';
		$previous_rewrite_set = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite     = $GLOBALS['wp_rewrite'] ?? null;
		$had_post_type        = isset( $GLOBALS['wp_post_types'][ $post_type ] );
		$previous_post_type   = $GLOBALS['wp_post_types'][ $post_type ] ?? null;
		$previous_user_id     = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0;
		$previous_query_vars  = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' )
			? $GLOBALS['wp']->public_query_vars
			: null;
		$cap_filter           = null;

		$permalink_filter = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$unique_slug_filter = static function ( $override_slug, string $slug, int $post_id, string $post_status, string $event_post_type, int $post_parent ) use ( &$active_label, &$unique_slug_events ) {
			if ( null !== $active_label ) {
				$unique_slug_events[] = array(
					'label'    => $active_label,
					'slug'     => $slug,
					'postId'   => $post_id,
					'status'   => $post_status,
					'type'     => $event_post_type,
					'parent'   => $post_parent,
					'override' => $override_slug,
				);
			}

			return $override_slug;
		};
		$editable_filter = static function ( string $slug, \WP_Post $post ) use ( &$active_label, &$editable_events ): string {
			if ( null !== $active_label ) {
				$editable_events[] = array(
					'label'  => $active_label,
					'slug'   => $slug,
					'postId' => (int) $post->ID,
					'status' => $post->post_status,
					'name'   => $post->post_name,
					'parent' => (int) $post->post_parent,
					'filter' => $post->filter ?? null,
				);
			}

			return $slug;
		};
		$post_type_link_filter = static function ( string $link, \WP_Post $post, bool $leavename, bool $sample ) use ( &$active_label, &$link_events ): string {
			if ( null !== $active_label ) {
				$link_events[] = array(
					'label'     => $active_label,
					'link'      => $link,
					'postId'    => (int) $post->ID,
					'type'      => $post->post_type,
					'name'      => $post->post_name,
					'filter'    => $post->filter ?? null,
					'leavename' => $leavename,
					'sample'    => $sample,
				);
			}

			return $link;
		};
		$events_for_label = static function ( array $events, string $label ): array {
			return array_values(
				array_filter(
					$events,
					static function ( array $event ) use ( $label ): bool {
						return $label === ( $event['label'] ?? null );
					}
				)
			);
		};

		try {
			if ( ( ! function_exists( 'get_sample_permalink' ) || ! function_exists( 'get_sample_permalink_html' ) ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			$registration = \register_post_type(
				$post_type,
				array(
					'capability_type' => 'page',
					'hierarchical'    => true,
					'map_meta_cap'    => true,
					'public'          => true,
					'query_var'       => false,
					'rewrite'         => array(
						'slug'       => $rewrite_slug,
						'with_front' => false,
					),
					'show_in_rest'    => true,
					'show_ui'         => true,
					'supports'        => array( 'title', 'editor', 'page-attributes' ),
				)
			);
			$post_type_object = \get_post_type_object( $post_type );

			\add_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10, 6 );
			\add_filter( 'editable_slug', $editable_filter, 10, 2 );
			\add_filter( 'post_type_link', $post_type_link_filter, 10, 4 );

			$functions_available = function_exists( 'get_sample_permalink' ) && function_exists( 'get_sample_permalink_html' );
			self::collect_failure(
				$failures,
				$functions_available
					&& $registration instanceof \WP_Post_Type
					&& $post_type_object instanceof \WP_Post_Type
					&& true === $post_type_object->hierarchical
					&& true === $post_type_object->show_in_rest
					&& $rewrite_slug === ( $post_type_object->rewrite['slug'] ?? null ),
				'custom hierarchical sample permalink fixture post type registers with REST and rewrite support',
				array(
					'getSamplePermalink'     => function_exists( 'get_sample_permalink' ),
					'getSamplePermalinkHtml' => function_exists( 'get_sample_permalink_html' ),
					'registration'           => $registration instanceof \WP_Post_Type ? $registration->name : self::error_summary( $registration ),
					'object'                 => $post_type_object instanceof \WP_Post_Type
						? array(
							'name'         => $post_type_object->name,
							'hierarchical' => $post_type_object->hierarchical,
							'showInRest'   => $post_type_object->show_in_rest,
							'rewrite'      => $post_type_object->rewrite,
						)
						: $post_type_object,
				)
			);

			if ( $functions_available && $post_type_object instanceof \WP_Post_Type ) {
				$parent_slug       = 'custom-hier-parent-' . $token;
				$other_parent_slug = 'custom-hier-other-parent-' . $token;
				$collision_slug    = 'custom-hier-child-' . $token;
				$other_scope_slug  = 'custom-hier-other-child-' . $token;
				$child_title       = 'Custom Hier Child ' . $case['token'];
				$child_title_slug  = \sanitize_title( $child_title );
				$expected_template = \home_url( '/' . $rewrite_slug . '/' . $parent_slug . '/%pagename%' );
				$expected_raw_link = \home_url( '/' . $rewrite_slug . '/%' . $post_type . '%' );

				$parent_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Hier Parent ' . $case['token'],
								'post_content' => 'Custom hier parent content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $parent_slug,
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$other_parent_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Hier Other Parent ' . $case['token'],
								'post_content' => 'Custom hier other parent content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $other_parent_slug,
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$same_parent_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Hier Holder ' . $case['token'],
								'post_content' => 'Custom hier holder content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $collision_slug,
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$same_parent_suffix_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Hier Suffix Holder ' . $case['token'],
								'post_content' => 'Custom hier suffix holder content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $collision_slug . '-2',
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$other_parent_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Hier Other Holder ' . $case['token'],
								'post_content' => 'Custom hier other holder content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => $other_scope_slug,
								'post_parent'  => $other_parent_id,
							)
						),
						true,
						false
					)
				);
				$title_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => $child_title,
								'post_content' => 'Custom title child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => '',
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$collision_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Collision Child ' . $case['token'],
								'post_content' => 'Custom collision child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'stored-custom-collision-' . $token,
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$other_scope_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Other Scope Child ' . $case['token'],
								'post_content' => 'Custom other scope child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'stored-custom-other-' . $token,
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);
				$numeric_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'Custom Numeric Child ' . $case['token'],
								'post_content' => 'Custom numeric child content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'stored-custom-numeric-' . $token,
								'post_parent'  => $parent_id,
							)
						),
						true,
						false
					)
				);

				$matrix = array(
					array(
						'label'         => 'custom-title-child',
						'postId'        => $title_child_id,
						'title'         => null,
						'name'          => null,
						'requestedSlug' => $child_title_slug,
						'expected'      => $child_title_slug,
					),
					array(
						'label'         => 'custom-same-parent-collision',
						'postId'        => $collision_child_id,
						'title'         => 'Custom Collision Override ' . $case['token'],
						'name'          => $collision_slug,
						'requestedSlug' => $collision_slug,
						'expected'      => $collision_slug . '-3',
					),
					array(
						'label'         => 'custom-other-parent-allowed',
						'postId'        => $other_scope_child_id,
						'title'         => 'Custom Other Scope Override ' . $case['token'],
						'name'          => $other_scope_slug,
						'requestedSlug' => $other_scope_slug,
						'expected'      => $other_scope_slug,
					),
					array(
						'label'         => 'custom-numeric-slug',
						'postId'        => $numeric_child_id,
						'title'         => 'Custom Numeric Override ' . $case['token'],
						'name'          => '456',
						'requestedSlug' => '456',
						'expected'      => '456-2',
					),
				);
				$matrix_ok = $parent_id > 0
					&& $other_parent_id > 0
					&& $same_parent_holder_id > 0
					&& $same_parent_suffix_holder_id > 0
					&& $other_parent_holder_id > 0;

				foreach ( $matrix as $entry ) {
					$before       = $entry['postId'] > 0 ? \get_post( $entry['postId'] ) : null;
					$active_label = $entry['label'];
					try {
						$sample = null === $entry['name']
							? ( $entry['postId'] > 0 ? \get_sample_permalink( $entry['postId'] ) : array() )
							: ( $entry['postId'] > 0 ? \get_sample_permalink( $entry['postId'], $entry['title'], $entry['name'] ) : array() );
					} finally {
						$active_label = null;
					}
					$after           = $entry['postId'] > 0 ? \get_post( $entry['postId'] ) : null;
					$unique_events   = $events_for_label( $unique_slug_events, $entry['label'] );
					$editable_events_for_label = $events_for_label( $editable_events, $entry['label'] );
					$link_events_for_label     = $events_for_label( $link_events, $entry['label'] );
					$unique_event    = $unique_events[0] ?? null;
					$uri_event       = $editable_events_for_label[0] ?? null;
					$slug_event      = $editable_events_for_label[1] ?? null;
					$link_event      = $link_events_for_label[0] ?? null;
					$entry_ok        = is_array( $sample )
						&& $expected_template === ( $sample[0] ?? null )
						&& $entry['expected'] === ( $sample[1] ?? null )
						&& $before instanceof \WP_Post
						&& $after instanceof \WP_Post
						&& $before->post_name === $after->post_name
						&& 'draft' === $after->post_status
						&& $parent_id === (int) $after->post_parent
						&& 1 === count( $unique_events )
						&& is_array( $unique_event )
						&& $entry['requestedSlug'] === ( $unique_event['slug'] ?? null )
						&& $entry['postId'] === (int) ( $unique_event['postId'] ?? 0 )
						&& 'publish' === ( $unique_event['status'] ?? null )
						&& $post_type === ( $unique_event['type'] ?? null )
						&& $parent_id === (int) ( $unique_event['parent'] ?? 0 )
						&& 2 === count( $editable_events_for_label )
						&& is_array( $uri_event )
						&& $parent_slug === ( $uri_event['slug'] ?? null )
						&& 'sample' === ( $uri_event['filter'] ?? null )
						&& is_array( $slug_event )
						&& $entry['expected'] === ( $slug_event['slug'] ?? null )
						&& 'sample' === ( $slug_event['filter'] ?? null )
						&& 1 === count( $link_events_for_label )
						&& is_array( $link_event )
						&& $expected_raw_link === ( $link_event['link'] ?? null )
						&& true === ( $link_event['leavename'] ?? null )
						&& true === ( $link_event['sample'] ?? null )
						&& $post_type === ( $link_event['type'] ?? null );
					$matrix_ok = $matrix_ok && $entry_ok;
					$matrix_observed[] = array(
						'label'    => $entry['label'],
						'sample'   => $sample,
						'before'   => self::post_summary( $before ),
						'after'    => self::post_summary( $after ),
						'unique'   => $unique_events,
						'editable' => $editable_events_for_label,
						'link'     => $link_events_for_label,
						'ok'       => $entry_ok,
					);
				}

				self::collect_failure(
					$failures,
					$matrix_ok,
					'get_sample_permalink replaces custom hierarchical post type tokens with parent-aware pagename templates and scoped slug uniqueness',
					array(
						'postType' => $post_type,
						'expected' => array(
							'template' => $expected_template,
							'rawLink'  => $expected_raw_link,
						),
						'matrix'   => $matrix_observed,
					)
				);

				$html_user_id = self::insert_support_user( 'custom-hier-html-' . $token, 'custom-hier-html-' . $token . '@example.test' );
				if ( $html_user_id > 0 ) {
					$cap_filter = self::grant_all_caps_filter( (int) $html_user_id );
					\wp_set_current_user( (int) $html_user_id );
					\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
				}

				$active_label = 'custom-html-collision';
				try {
					$html = $collision_child_id > 0 ? \get_sample_permalink_html( $collision_child_id, null, $collision_slug ) : '';
				} finally {
					$active_label = null;
				}
				$html_after = $collision_child_id > 0 ? \get_post( $collision_child_id ) : null;
				$html_observed = array(
					'html'      => $html,
					'postAfter' => self::post_summary( $html_after ),
					'unique'    => $events_for_label( $unique_slug_events, 'custom-html-collision' ),
					'editable'  => $events_for_label( $editable_events, 'custom-html-collision' ),
					'link'      => $events_for_label( $link_events, 'custom-html-collision' ),
				);
				$html_sample_link_event  = $html_observed['link'][0] ?? null;
				$html_preview_link_event = $html_observed['link'][1] ?? null;

				self::collect_failure(
					$failures,
					$html_user_id > 0
						&& is_string( $html )
						&& str_contains( $html, 'id="sample-permalink"' )
						&& str_contains( $html, $rewrite_slug . '/' . $parent_slug . '/<span id="editable-post-name">' )
						&& str_contains( $html, 'id="editable-post-name-full">' . $collision_slug . '-3</span>' )
						&& str_contains( $html, 'post_type=' . $post_type )
						&& str_contains( $html, "target='wp-preview-{$collision_child_id}'" )
						&& str_contains( $html, 'preview=true' )
						&& $html_after instanceof \WP_Post
						&& 'draft' === $html_after->post_status
						&& 'stored-custom-collision-' . $token === $html_after->post_name
						&& 1 === count( $html_observed['unique'] )
						&& 2 === count( $html_observed['editable'] )
						&& 2 === count( $html_observed['link'] )
						&& is_array( $html_sample_link_event )
						&& $expected_raw_link === ( $html_sample_link_event['link'] ?? null )
						&& true === ( $html_sample_link_event['leavename'] ?? null )
						&& true === ( $html_sample_link_event['sample'] ?? null )
						&& is_array( $html_preview_link_event )
						&& \home_url( '/?post_type=' . $post_type . '&p=' . $collision_child_id ) === ( $html_preview_link_event['link'] ?? null )
						&& false === ( $html_preview_link_event['leavename'] ?? null )
						&& false === ( $html_preview_link_event['sample'] ?? null ),
					'get_sample_permalink_html renders custom hierarchical draft preview links with custom query args and parent-path display',
					$html_observed
				);
			}
		} finally {
			if ( null !== $cap_filter ) {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}
			\remove_filter( 'post_type_link', $post_type_link_filter, 10 );
			\remove_filter( 'editable_slug', $editable_filter, 10 );
			\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			\wp_set_current_user( $previous_user_id );

			foreach ( array_reverse( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}

			if ( function_exists( 'unregister_post_type' ) && \post_type_exists( $post_type ) ) {
				\unregister_post_type( $post_type );
			}
			if ( $had_post_type ) {
				$GLOBALS['wp_post_types'][ $post_type ] = $previous_post_type;
			} else {
				unset( $GLOBALS['wp_post_types'][ $post_type ] );
			}
			if ( null !== $previous_query_vars && isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' ) ) {
				$GLOBALS['wp']->public_query_vars = $previous_query_vars;
			}

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}
		}

		$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
			&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
		$current_user_restored = $previous_user_id === ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0 );
		$post_type_restored = $had_post_type === isset( $GLOBALS['wp_post_types'][ $post_type ] )
			&& ( ! $had_post_type || $previous_post_type === $GLOBALS['wp_post_types'][ $post_type ] );
		$query_vars_restored = null === $previous_query_vars
			|| ! ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' ) )
			|| $previous_query_vars === $GLOBALS['wp']->public_query_vars;
		$filters_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
			&& false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter )
			&& false === \has_filter( 'editable_slug', $editable_filter )
			&& false === \has_filter( 'post_type_link', $post_type_link_filter )
			&& ( null === $cap_filter || false === \has_filter( 'user_has_cap', $cap_filter ) );

		self::collect_failure(
			$failures,
			$rewrite_restored && $current_user_restored && $post_type_restored && $query_vars_restored && $filters_restored,
			'custom hierarchical sample permalink row restores rewrite, current user, post type, query vars, and filters',
			array(
				'rewriteRestored'     => $rewrite_restored,
				'currentUserRestored' => $current_user_restored,
				'postTypeRestored'    => $post_type_restored,
				'queryVarsRestored'   => $query_vars_restored,
				'filtersRestored'     => $filters_restored,
				'postIds'             => $post_ids,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.sample-permalink-custom-hierarchical-post-type',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postType' => $post_type,
				'postIds'  => $post_ids,
				'matrix'   => $matrix_observed,
				'html'     => $html_observed,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_custom_post_type_single_permalink_fallback_matrix( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures             = array();
		$post_ids             = array();
		$user_ids             = array();
		$link_events          = array();
		$matrix_observed      = array();
		$active_label         = null;
		$token                = substr( $case['token'], 0, 8 );
		$pretty_type          = 'cfl_' . $token;
		$query_type           = 'cfq_' . $token;
		$plain_type           = 'cfp_' . $token;
		$query_var            = 'cfqv_' . $token;
		$pretty_rewrite_slug  = 'single-pretty-' . $token;
		$permalink_structure  = '/%postname%/';
		$post_types           = array( $pretty_type, $query_type, $plain_type );
		$previous_rewrite_set = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite     = $GLOBALS['wp_rewrite'] ?? null;
		$previous_wp_set      = array_key_exists( 'wp', $GLOBALS );
		$previous_wp          = $GLOBALS['wp'] ?? null;
		$previous_post_types  = array();
		$previous_query_vars  = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' )
			? $GLOBALS['wp']->public_query_vars
			: null;
		$counts_before        = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
			? $GLOBALS['wpdb']->component_fuzz_content_counts()
			: array();
		$counts_after_cleanup = array();

		foreach ( $post_types as $post_type ) {
			$previous_post_types[ $post_type ] = array(
				'exists' => isset( $GLOBALS['wp_post_types'][ $post_type ] ),
				'value'  => $GLOBALS['wp_post_types'][ $post_type ] ?? null,
			);
		}

		$permalink_filter = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$insert_post = static function ( string $post_type, string $title, string $slug, int $parent, string $status = 'publish', int $author_id = 0 ) use ( $remember_post ): int {
			$post_data = array(
				'post_type'    => $post_type,
				'post_title'   => $title,
				'post_content' => $title . ' content',
				'post_status'  => $status,
				'post_name'    => $slug,
				'post_parent'  => $parent,
			);
			if ( $author_id > 0 ) {
				$post_data['post_author'] = $author_id;
			}

			return $remember_post(
				\wp_insert_post(
					\wp_slash( $post_data ),
					true,
					false
				)
			);
		};
		$force_parent = static function ( int $post_id, int $parent_id ): void {
			if ( $post_id <= 0 || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
				return;
			}

			$GLOBALS['wpdb']->update(
				$GLOBALS['wpdb']->posts,
				array( 'post_parent' => $parent_id ),
				array( 'ID' => $post_id )
			);
			\wp_cache_delete( $post_id, 'posts' );
			\wp_cache_delete( 'post_parent:' . (string) $post_id, 'posts' );
		};
		$link_filter = static function ( string $link, \WP_Post $post, bool $leavename, bool $sample ) use ( &$active_label, &$link_events ): string {
			if ( null !== $active_label ) {
				$link_events[] = array(
					'label'     => $active_label,
					'link'      => $link,
					'postId'    => (int) $post->ID,
					'type'      => $post->post_type,
					'name'      => $post->post_name,
					'parent'    => (int) $post->post_parent,
					'leavename' => $leavename,
					'sample'    => $sample,
				);
			}

			return $link;
		};
		$link_events_for_label = static function ( string $label ) use ( &$link_events ): array {
			return array_values(
				array_filter(
					$link_events,
					static function ( array $event ) use ( $label ): bool {
						return $label === ( $event['label'] ?? null );
					}
				)
			);
		};
		$call_link = static function ( string $label, callable $callback ) use ( &$active_label ) {
			$active_label = $label;
			try {
				return $callback();
			} finally {
				$active_label = null;
			}
		};
		$query_link = static function ( string $query_var_name, string $slug ): string {
			return \home_url( \add_query_arg( $query_var_name, $slug, '' ) );
		};
		$raw_query_link = static function ( string $query_var_name, string $slug ): string {
			return \home_url( '?' . $query_var_name . '=' . $slug );
		};
		$plain_link = static function ( string $post_type, int $post_id ): string {
			return \home_url( \add_query_arg( array( 'post_type' => $post_type, 'p' => $post_id ), '' ) );
		};
		$wp_query_cache_key = static function ( \WP_Query $query ): string {
			$property = new \ReflectionProperty( $query, 'query_cache_key' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			$value = $property->getValue( $query );
			return is_string( $value ) ? $value : '';
		};
		$query_parent_status_bucket = static function ( string $post_type, int $parent_id, $post_status, string $fields = 'ids', array $overrides = array() ) use ( $wp_query_cache_key ): array {
			$args  = array(
				'cache_results'          => true,
				'fields'                 => $fields,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'order'                  => 'ASC',
				'orderby'                => 'name',
				'post_parent'            => $parent_id,
				'post_status'            => $post_status,
				'post_type'              => $post_type,
				'posts_per_page'         => -1,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
			$args  = array_merge( $args, $overrides );
			foreach ( $overrides as $key => $value ) {
				if ( null === $value ) {
					unset( $args[ $key ] );
				}
			}
			$query = new \WP_Query();
			$result = $query->query( $args );
			$key   = $wp_query_cache_key( $query );
			$salt  = (array) \wp_cache_get_last_changed( 'posts' );
			$posts = is_array( $query->posts ) ? $query->posts : array();
			$ids      = array();
			$parents  = array();
			$statuses = array();
			$classes  = array();

			foreach ( $posts as $post ) {
				if ( is_int( $post ) ) {
					$ids[] = $post;
					continue;
				}

				if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
					continue;
				}

				$post_id = (int) $post->ID;
				$ids[]   = $post_id;
				$classes[ $post_id ] = get_class( $post );

				if ( property_exists( $post, 'post_parent' ) ) {
					$parents[ $post_id ] = (int) $post->post_parent;
				}
				if ( property_exists( $post, 'post_status' ) ) {
					$statuses[ $post_id ] = (string) $post->post_status;
				}
			}

			ksort( $parents );
			ksort( $statuses );
			ksort( $classes );

			if ( 'id=>parent' === $query->query_vars['fields'] && is_array( $result ) ) {
				$parents = array();
				foreach ( $result as $post_id => $parent_id ) {
					if ( is_string( $post_id ) && str_starts_with( $post_id, 'post_parent:' ) ) {
						$post_id = substr( $post_id, strlen( 'post_parent:' ) );
					}

					$parents[ (int) $post_id ] = (int) $parent_id;
				}
				ksort( $parents );
			}

			return array(
				'args'        => $args,
				'ids'         => array_values( array_map( 'intval', $ids ) ),
				'parents'     => $parents,
				'statuses'    => $statuses,
				'classes'     => $classes,
				'cacheKey'    => $key,
				'cache'       => '' === $key ? false : \wp_cache_get_salted( $key, 'post-queries', $salt ),
				'lastChanged' => $salt,
				'request'     => $query->request,
				'queryVars'   => $query->query_vars,
			);
		};
		$query_bucket_has_ids = static function ( array $bucket, array $expected_ids ): bool {
			return is_array( $bucket['cache'] ?? null )
				&& isset( $bucket['cache']['posts'] )
				&& $expected_ids === array_values( array_map( 'intval', (array) $bucket['cache']['posts'] ) );
		};
		$query_bucket_has_payload = static function ( array $bucket, array $expected_ids ): bool {
			return is_array( $bucket['cache'] ?? null )
				&& isset( $bucket['cache']['posts'], $bucket['cache']['found_posts'], $bucket['cache']['max_num_pages'] )
				&& $expected_ids === array_values( array_map( 'intval', (array) $bucket['cache']['posts'] ) )
				&& 0 === (int) $bucket['cache']['found_posts']
				&& 0 === (int) $bucket['cache']['max_num_pages'];
		};
		$query_ordering_family_is_valid = static function ( array $ids_bucket, array $id_parent_bucket, array $object_bucket, array $expected_ids, array $expected_parents, array $expected_statuses ) use ( $query_bucket_has_payload ): bool {
			$expected_classes = array_fill_keys( $expected_ids, 'WP_Post' );
			ksort( $expected_classes );

			return '' !== $ids_bucket['cacheKey']
				&& '' !== $id_parent_bucket['cacheKey']
				&& '' !== $object_bucket['cacheKey']
				&& $expected_ids === $ids_bucket['ids']
				&& $expected_ids === $id_parent_bucket['ids']
				&& $expected_ids === $object_bucket['ids']
				&& $expected_parents === $id_parent_bucket['parents']
				&& $expected_parents === $object_bucket['parents']
				&& $expected_statuses === $object_bucket['statuses']
				&& $expected_classes === $object_bucket['classes']
				&& $query_bucket_has_payload( $ids_bucket, $expected_ids )
				&& $query_bucket_has_payload( $id_parent_bucket, $expected_ids )
				&& $query_bucket_has_payload( $object_bucket, $expected_ids );
		};

		try {
			if ( ! isset( $GLOBALS['wp'] ) || ! $GLOBALS['wp'] instanceof \WP ) {
				$GLOBALS['wp'] = new \WP();
			}
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );
			\add_filter( 'post_type_link', $link_filter, 10, 4 );

			$pretty_registration = \register_post_type(
				$pretty_type,
				array(
					'capability_type' => 'page',
					'hierarchical'    => true,
					'map_meta_cap'    => true,
					'public'          => true,
					'query_var'       => false,
					'rewrite'         => array(
						'slug'       => $pretty_rewrite_slug,
						'with_front' => false,
					),
					'supports'        => array( 'title', 'editor', 'page-attributes' ),
				)
			);
			$query_registration = \register_post_type(
				$query_type,
				array(
					'capability_type' => 'page',
					'hierarchical'    => true,
					'map_meta_cap'    => true,
					'public'          => true,
					'query_var'       => $query_var,
					'rewrite'         => false,
					'supports'        => array( 'title', 'editor', 'page-attributes' ),
				)
			);
			$plain_registration = \register_post_type(
				$plain_type,
				array(
					'capability_type' => 'page',
					'hierarchical'    => true,
					'map_meta_cap'    => true,
					'public'          => true,
					'query_var'       => false,
					'rewrite'         => false,
					'supports'        => array( 'title', 'editor', 'page-attributes' ),
				)
			);

			$pretty_parent_slug = 'single-pretty-parent-' . $token;
			$pretty_child_slug  = 'single-pretty-child-' . $token;
			$pretty_draft_slug  = 'single-pretty-draft-' . $token;
			$pretty_missing_parent_slug = 'single-pretty-missing-parent-' . $token;
			$pretty_self_parent_slug = 'single-pretty-self-parent-' . $token;
			$pretty_cross_type_child_slug = 'single-pretty-cross-child-' . $token;
			$pretty_private_parent_slug = 'single-pretty-private-parent-' . $token;
			$pretty_private_child_slug = 'single-pretty-private-child-' . $token;
			$pretty_trash_parent_slug = 'single-pretty-trash-parent-' . $token;
			$pretty_trash_child_slug = 'single-pretty-trash-child-' . $token;
			$pretty_mixed_root_slug = 'single-pretty-mixed-root-' . $token;
			$pretty_mixed_leaf_slug = 'single-pretty-mixed-leaf-' . $token;
			$pretty_mixed_middle_slug = 'single-pretty-mixed-middle-' . $token;
			$query_parent_slug  = 'single-query-parent-' . $token;
			$query_child_slug   = 'single-query-child-' . $token;
			$query_draft_slug   = 'single-query-draft-' . $token;
			$query_raw_parent_slug = 'single query parent ' . $token;
			$query_raw_child_slug  = 'child+plus&raw%2F' . $token;
			$query_slash_parent_slug = 'single/query-parent-' . $token;
			$query_slash_child_slug  = 'child/slash-' . $token;
			$query_missing_parent_slug = 'single-query-missing-parent-' . $token;
			$query_self_parent_slug = 'single-query-self-parent-' . $token;
			$query_cross_type_child_slug = 'single-query-cross-child-' . $token;
			$query_private_parent_slug = 'single-query-private-parent-' . $token;
			$query_private_child_slug = 'single-query-private-child-' . $token;
			$query_trash_parent_slug = 'single-query-trash-parent-' . $token;
			$query_trash_child_slug = 'single-query-trash-child-' . $token;
			$query_mixed_middle_slug = 'single-query-mixed-middle-' . $token;
			$query_mixed_root_slug = 'single-query-mixed-root-' . $token;
			$query_mixed_leaf_slug = 'single-query-mixed-leaf-' . $token;
			$plain_parent_slug  = 'single-plain-parent-' . $token;
			$plain_child_slug   = 'single-plain-child-' . $token;
			$plain_draft_slug   = 'single-plain-draft-' . $token;
			$mutation_parent_a_slug = 'single-mutation-parent-a-' . $token;
			$mutation_parent_b_slug = 'single-mutation-parent-b-' . $token;
			$mutation_child_slug    = 'single-mutation-child-' . $token;
			$mutation_pending_child_slug = 'single-mutation-pending-child-' . $token;
			$missing_parent_id  = 987654321;
			$author_excluded_a_id = self::insert_support_user( 'query-author-a-' . $token, 'query-author-a-' . $token . '@example.test' );
			$author_excluded_b_id = self::insert_support_user( 'query-author-b-' . $token, 'query-author-b-' . $token . '@example.test' );
			$user_ids             = array_filter( array( $author_excluded_a_id, $author_excluded_b_id ), static fn ( $user_id ): bool => is_int( $user_id ) && $user_id > 0 );

			$pretty_parent_id = $insert_post( $pretty_type, 'Single Pretty Parent ' . $token, $pretty_parent_slug, 0 );
			$pretty_child_id  = $insert_post( $pretty_type, 'Single Pretty Child ' . $token, $pretty_child_slug, $pretty_parent_id, 'publish', $author_excluded_a_id );
			$pretty_draft_id  = $insert_post( $pretty_type, 'Single Pretty Draft ' . $token, $pretty_draft_slug, $pretty_parent_id, 'draft' );
			$pretty_missing_parent_id = $insert_post( $pretty_type, 'Single Pretty Missing Parent ' . $token, $pretty_missing_parent_slug, 0 );
			$pretty_self_parent_id = $insert_post( $pretty_type, 'Single Pretty Self Parent ' . $token, $pretty_self_parent_slug, 0 );
			$pretty_private_parent_id = $insert_post( $pretty_type, 'Single Pretty Private Parent ' . $token, $pretty_private_parent_slug, 0, 'private' );
			$pretty_private_child_id = $insert_post( $pretty_type, 'Single Pretty Private Child ' . $token, $pretty_private_child_slug, $pretty_private_parent_id );
			$pretty_trash_parent_id = $insert_post( $pretty_type, 'Single Pretty Trash Parent ' . $token, $pretty_trash_parent_slug, 0, 'trash' );
			$pretty_trash_child_id = $insert_post( $pretty_type, 'Single Pretty Trash Child ' . $token, $pretty_trash_child_slug, $pretty_trash_parent_id );
			$pretty_mixed_root_id = $insert_post( $pretty_type, 'Single Pretty Mixed Root ' . $token, $pretty_mixed_root_slug, 0 );
			$query_mixed_middle_id = $insert_post( $query_type, 'Single Query Mixed Middle ' . $token, $query_mixed_middle_slug, $pretty_mixed_root_id );
			$pretty_mixed_leaf_id = $insert_post( $pretty_type, 'Single Pretty Mixed Leaf ' . $token, $pretty_mixed_leaf_slug, $query_mixed_middle_id );
			$query_parent_id  = $insert_post( $query_type, 'Single Query Parent ' . $token, $query_parent_slug, 0 );
			$pretty_cross_type_child_id = $insert_post( $pretty_type, 'Single Pretty Cross Parent Child ' . $token, $pretty_cross_type_child_slug, $query_parent_id, 'publish', $author_excluded_b_id );
			$query_child_id   = $insert_post( $query_type, 'Single Query Child ' . $token, $query_child_slug, $query_parent_id );
			$query_draft_id   = $insert_post( $query_type, 'Single Query Draft ' . $token, $query_draft_slug, $query_parent_id, 'draft' );
			$query_raw_parent_id = $insert_post( $query_type, 'Single Query Raw Parent ' . $token, $query_raw_parent_slug, 0 );
			$query_raw_child_id  = $insert_post( $query_type, 'Single Query Raw Child ' . $token, $query_raw_child_slug, $query_raw_parent_id );
			$query_slash_parent_id = $insert_post( $query_type, 'Single Query Slash Parent ' . $token, $query_slash_parent_slug, 0 );
			$query_slash_child_id  = $insert_post( $query_type, 'Single Query Slash Child ' . $token, $query_slash_child_slug, $query_slash_parent_id );
			$query_missing_parent_id = $insert_post( $query_type, 'Single Query Missing Parent ' . $token, $query_missing_parent_slug, 0 );
			$query_self_parent_id = $insert_post( $query_type, 'Single Query Self Parent ' . $token, $query_self_parent_slug, 0 );
			$query_cross_type_child_id = $insert_post( $query_type, 'Single Query Cross Parent Child ' . $token, $query_cross_type_child_slug, $pretty_parent_id );
			$query_private_parent_id = $insert_post( $query_type, 'Single Query Private Parent ' . $token, $query_private_parent_slug, 0, 'private' );
			$query_private_child_id = $insert_post( $query_type, 'Single Query Private Child ' . $token, $query_private_child_slug, $query_private_parent_id );
			$query_trash_parent_id = $insert_post( $query_type, 'Single Query Trash Parent ' . $token, $query_trash_parent_slug, 0, 'trash' );
			$query_trash_child_id = $insert_post( $query_type, 'Single Query Trash Child ' . $token, $query_trash_child_slug, $query_trash_parent_id );
			$query_mixed_root_id = $insert_post( $query_type, 'Single Query Mixed Root ' . $token, $query_mixed_root_slug, 0 );
			$pretty_mixed_middle_id = $insert_post( $pretty_type, 'Single Pretty Mixed Middle ' . $token, $pretty_mixed_middle_slug, $query_mixed_root_id );
			$query_mixed_leaf_id = $insert_post( $query_type, 'Single Query Mixed Leaf ' . $token, $query_mixed_leaf_slug, $pretty_mixed_middle_id );
			$plain_parent_id  = $insert_post( $plain_type, 'Single Plain Parent ' . $token, $plain_parent_slug, 0 );
			$plain_child_id   = $insert_post( $plain_type, 'Single Plain Child ' . $token, $plain_child_slug, $plain_parent_id );
			$plain_draft_id   = $insert_post( $plain_type, 'Single Plain Draft ' . $token, $plain_draft_slug, $plain_parent_id, 'draft' );
			$mutation_parent_a_id = $insert_post( $pretty_type, 'Single Mutation Parent A ' . $token, $mutation_parent_a_slug, 0 );
			$mutation_parent_b_id = $insert_post( $query_type, 'Single Mutation Parent B ' . $token, $mutation_parent_b_slug, 0 );
			$mutation_child_id    = $insert_post( $pretty_type, 'Single Mutation Child ' . $token, $mutation_child_slug, $mutation_parent_a_id );
			$mutation_pending_child_id = $insert_post( $pretty_type, 'Single Mutation Pending Child ' . $token, $mutation_pending_child_slug, $mutation_parent_b_id, 'pending' );
			$force_parent( $pretty_missing_parent_id, $missing_parent_id );
			$force_parent( $pretty_self_parent_id, $pretty_self_parent_id );
			$force_parent( $query_missing_parent_id, $missing_parent_id );
			$force_parent( $query_self_parent_id, $query_self_parent_id );

			self::collect_failure(
				$failures,
				$pretty_registration instanceof \WP_Post_Type
					&& $query_registration instanceof \WP_Post_Type
					&& $plain_registration instanceof \WP_Post_Type
					&& $pretty_parent_id > 0
					&& $pretty_child_id > 0
					&& $pretty_draft_id > 0
					&& $pretty_missing_parent_id > 0
					&& $pretty_self_parent_id > 0
					&& $pretty_cross_type_child_id > 0
					&& $pretty_private_parent_id > 0
					&& $pretty_private_child_id > 0
					&& $pretty_trash_parent_id > 0
					&& $pretty_trash_child_id > 0
					&& $pretty_mixed_root_id > 0
					&& $query_mixed_middle_id > 0
					&& $pretty_mixed_leaf_id > 0
					&& $query_parent_id > 0
					&& $query_child_id > 0
					&& $query_draft_id > 0
					&& $query_raw_parent_id > 0
					&& $query_raw_child_id > 0
					&& $query_slash_parent_id > 0
					&& $query_slash_child_id > 0
					&& $query_missing_parent_id > 0
					&& $query_self_parent_id > 0
					&& $query_cross_type_child_id > 0
					&& $query_private_parent_id > 0
					&& $query_private_child_id > 0
					&& $query_trash_parent_id > 0
					&& $query_trash_child_id > 0
					&& $query_mixed_root_id > 0
					&& $pretty_mixed_middle_id > 0
					&& $query_mixed_leaf_id > 0
					&& $plain_parent_id > 0
					&& $plain_child_id > 0
					&& $plain_draft_id > 0
					&& $mutation_parent_a_id > 0
					&& $mutation_parent_b_id > 0
					&& $mutation_child_id > 0
					&& $mutation_pending_child_id > 0
					&& $author_excluded_a_id > 0
					&& $author_excluded_b_id > 0,
				'custom post type single permalink fallback fixtures register and insert generated hierarchy rows',
				array(
					'registrations' => array(
						'pretty' => $pretty_registration instanceof \WP_Post_Type ? $pretty_registration->name : self::error_summary( $pretty_registration ),
						'query'  => $query_registration instanceof \WP_Post_Type ? $query_registration->name : self::error_summary( $query_registration ),
						'plain'  => $plain_registration instanceof \WP_Post_Type ? $plain_registration->name : self::error_summary( $plain_registration ),
					),
					'posts'         => array_map( static fn ( int $post_id ): ?array => self::post_summary( \get_post( $post_id ) ), $post_ids ),
					'authors'       => array(
						'excludedA' => $author_excluded_a_id,
						'excludedB' => $author_excluded_b_id,
					),
				)
			);

			$pretty_child_uri = $pretty_parent_slug . '/' . $pretty_child_slug;
			$pretty_draft_uri = $pretty_parent_slug . '/' . $pretty_draft_slug;
			$pretty_cross_type_child_uri = $query_parent_slug . '/' . $pretty_cross_type_child_slug;
			$pretty_private_child_uri = $pretty_private_parent_slug . '/' . $pretty_private_child_slug;
			$pretty_trash_child_uri = $pretty_trash_parent_slug . '/' . $pretty_trash_child_slug;
			$pretty_mixed_leaf_uri = $pretty_mixed_root_slug . '/' . $query_mixed_middle_slug . '/' . $pretty_mixed_leaf_slug;
			$query_child_uri  = $query_parent_slug . '/' . $query_child_slug;
			$query_draft_uri  = $query_parent_slug . '/' . $query_draft_slug;
			$query_raw_child_uri = $query_raw_parent_slug . '/' . $query_raw_child_slug;
			$query_slash_child_uri = $query_slash_parent_slug . '/' . $query_slash_child_slug;
			$query_cross_type_child_uri = $pretty_parent_slug . '/' . $query_cross_type_child_slug;
			$query_private_child_uri = $query_private_parent_slug . '/' . $query_private_child_slug;
			$query_trash_child_uri = $query_trash_parent_slug . '/' . $query_trash_child_slug;
			$query_mixed_leaf_uri = $query_mixed_root_slug . '/' . $pretty_mixed_middle_slug . '/' . $query_mixed_leaf_slug;
			$mutation_initial_path = $mutation_parent_a_slug . '/' . $mutation_child_slug;
			$mutation_reparented_path = $mutation_parent_b_slug . '/' . $mutation_child_slug;

			$matrix = array(
				array(
					'label'     => 'pretty-published',
					'actual'    => $call_link( 'pretty-published', static fn () => \get_post_permalink( $pretty_child_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_child_uri ),
					'postId'    => $pretty_child_id,
					'type'      => $pretty_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-get-permalink',
					'actual'    => $call_link( 'pretty-get-permalink', static fn () => \get_permalink( $pretty_child_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_child_uri ),
					'postId'    => $pretty_child_id,
					'type'      => $pretty_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-leavename',
					'actual'    => $call_link( 'pretty-leavename', static fn () => \get_post_permalink( $pretty_child_id, true ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/%' . $pretty_type . '%' ),
					'postId'    => $pretty_child_id,
					'type'      => $pretty_type,
					'leavename' => true,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-draft-plain',
					'actual'    => $call_link( 'pretty-draft-plain', static fn () => \get_post_permalink( $pretty_draft_id ) ),
					'expected'  => $plain_link( $pretty_type, $pretty_draft_id ),
					'postId'    => $pretty_draft_id,
					'type'      => $pretty_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-draft-sample',
					'actual'    => $call_link( 'pretty-draft-sample', static fn () => \get_post_permalink( $pretty_draft_id, false, true ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_draft_uri ),
					'postId'    => $pretty_draft_id,
					'type'      => $pretty_type,
					'leavename' => false,
					'sample'    => true,
				),
				array(
					'label'     => 'pretty-missing-parent',
					'actual'    => $call_link( 'pretty-missing-parent', static fn () => \get_post_permalink( $pretty_missing_parent_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_missing_parent_slug ),
					'postId'    => $pretty_missing_parent_id,
					'type'      => $pretty_type,
					'parent'    => $missing_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-self-parent',
					'actual'    => $call_link( 'pretty-self-parent', static fn () => \get_post_permalink( $pretty_self_parent_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_self_parent_slug ),
					'postId'    => $pretty_self_parent_id,
					'type'      => $pretty_type,
					'parent'    => $pretty_self_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-cross-type-parent',
					'actual'    => $call_link( 'pretty-cross-type-parent', static fn () => \get_post_permalink( $pretty_cross_type_child_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_cross_type_child_uri ),
					'postId'    => $pretty_cross_type_child_id,
					'type'      => $pretty_type,
					'parent'    => $query_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-private-parent',
					'actual'    => $call_link( 'pretty-private-parent', static fn () => \get_post_permalink( $pretty_private_child_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_private_child_uri ),
					'postId'    => $pretty_private_child_id,
					'type'      => $pretty_type,
					'parent'    => $pretty_private_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-trash-parent',
					'actual'    => $call_link( 'pretty-trash-parent', static fn () => \get_post_permalink( $pretty_trash_child_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_trash_child_uri ),
					'postId'    => $pretty_trash_child_id,
					'type'      => $pretty_type,
					'parent'    => $pretty_trash_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'pretty-mixed-depth-ancestors',
					'actual'    => $call_link( 'pretty-mixed-depth-ancestors', static fn () => \get_post_permalink( $pretty_mixed_leaf_id ) ),
					'expected'  => \home_url( '/' . $pretty_rewrite_slug . '/' . $pretty_mixed_leaf_uri ),
					'postId'    => $pretty_mixed_leaf_id,
					'type'      => $pretty_type,
					'parent'    => $query_mixed_middle_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-published',
					'actual'    => $call_link( 'query-published', static fn () => \get_post_permalink( $query_child_id ) ),
					'expected'  => $query_link( $query_var, $query_child_uri ),
					'postId'    => $query_child_id,
					'type'      => $query_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-leavename',
					'actual'    => $call_link( 'query-leavename', static fn () => \get_post_permalink( $query_child_id, true ) ),
					'expected'  => $query_link( $query_var, $query_child_uri ),
					'postId'    => $query_child_id,
					'type'      => $query_type,
					'leavename' => true,
					'sample'    => false,
				),
				array(
					'label'     => 'query-draft-plain',
					'actual'    => $call_link( 'query-draft-plain', static fn () => \get_post_permalink( $query_draft_id ) ),
					'expected'  => $plain_link( $query_type, $query_draft_id ),
					'postId'    => $query_draft_id,
					'type'      => $query_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-draft-sample',
					'actual'    => $call_link( 'query-draft-sample', static fn () => \get_post_permalink( $query_draft_id, false, true ) ),
					'expected'  => $plain_link( $query_type, $query_draft_id ),
					'postId'    => $query_draft_id,
					'type'      => $query_type,
					'leavename' => false,
					'sample'    => true,
				),
				array(
					'label'     => 'query-raw-reserved',
					'actual'    => $call_link( 'query-raw-reserved', static fn () => \get_post_permalink( $query_raw_child_id ) ),
					'expected'  => $raw_query_link( $query_var, $query_raw_child_uri ),
					'postId'    => $query_raw_child_id,
					'type'      => $query_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-raw-get-permalink',
					'actual'    => $call_link( 'query-raw-get-permalink', static fn () => \get_permalink( $query_raw_child_id ) ),
					'expected'  => $raw_query_link( $query_var, $query_raw_child_uri ),
					'postId'    => $query_raw_child_id,
					'type'      => $query_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-raw-leavename',
					'actual'    => $call_link( 'query-raw-leavename', static fn () => \get_post_permalink( $query_raw_child_id, true ) ),
					'expected'  => $raw_query_link( $query_var, $query_raw_child_uri ),
					'postId'    => $query_raw_child_id,
					'type'      => $query_type,
					'leavename' => true,
					'sample'    => false,
				),
				array(
					'label'     => 'query-slash-segments',
					'actual'    => $call_link( 'query-slash-segments', static fn () => \get_post_permalink( $query_slash_child_id ) ),
					'expected'  => $raw_query_link( $query_var, $query_slash_child_uri ),
					'postId'    => $query_slash_child_id,
					'type'      => $query_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-missing-parent',
					'actual'    => $call_link( 'query-missing-parent', static fn () => \get_post_permalink( $query_missing_parent_id ) ),
					'expected'  => $query_link( $query_var, $query_missing_parent_slug ),
					'postId'    => $query_missing_parent_id,
					'type'      => $query_type,
					'parent'    => $missing_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-self-parent',
					'actual'    => $call_link( 'query-self-parent', static fn () => \get_post_permalink( $query_self_parent_id ) ),
					'expected'  => $query_link( $query_var, $query_self_parent_slug ),
					'postId'    => $query_self_parent_id,
					'type'      => $query_type,
					'parent'    => $query_self_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-cross-type-parent',
					'actual'    => $call_link( 'query-cross-type-parent', static fn () => \get_post_permalink( $query_cross_type_child_id ) ),
					'expected'  => $query_link( $query_var, $query_cross_type_child_uri ),
					'postId'    => $query_cross_type_child_id,
					'type'      => $query_type,
					'parent'    => $pretty_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-private-parent',
					'actual'    => $call_link( 'query-private-parent', static fn () => \get_post_permalink( $query_private_child_id ) ),
					'expected'  => $query_link( $query_var, $query_private_child_uri ),
					'postId'    => $query_private_child_id,
					'type'      => $query_type,
					'parent'    => $query_private_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-trash-parent',
					'actual'    => $call_link( 'query-trash-parent', static fn () => \get_post_permalink( $query_trash_child_id ) ),
					'expected'  => $query_link( $query_var, $query_trash_child_uri ),
					'postId'    => $query_trash_child_id,
					'type'      => $query_type,
					'parent'    => $query_trash_parent_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'query-mixed-depth-ancestors',
					'actual'    => $call_link( 'query-mixed-depth-ancestors', static fn () => \get_post_permalink( $query_mixed_leaf_id ) ),
					'expected'  => $query_link( $query_var, $query_mixed_leaf_uri ),
					'postId'    => $query_mixed_leaf_id,
					'type'      => $query_type,
					'parent'    => $pretty_mixed_middle_id,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'plain-published',
					'actual'    => $call_link( 'plain-published', static fn () => \get_post_permalink( $plain_child_id ) ),
					'expected'  => $plain_link( $plain_type, $plain_child_id ),
					'postId'    => $plain_child_id,
					'type'      => $plain_type,
					'leavename' => false,
					'sample'    => false,
				),
				array(
					'label'     => 'plain-draft-sample',
					'actual'    => $call_link( 'plain-draft-sample', static fn () => \get_post_permalink( $plain_draft_id, false, true ) ),
					'expected'  => $plain_link( $plain_type, $plain_draft_id ),
					'postId'    => $plain_draft_id,
					'type'      => $plain_type,
					'leavename' => false,
					'sample'    => true,
				),
			);

			$matrix_ok = true;
			foreach ( $matrix as $entry ) {
				$events   = $link_events_for_label( $entry['label'] );
				$event    = $events[0] ?? null;
				$entry_ok = $entry['expected'] === $entry['actual']
					&& 1 === count( $events )
					&& is_array( $event )
					&& $entry['expected'] === ( $event['link'] ?? null )
					&& $entry['postId'] === (int) ( $event['postId'] ?? 0 )
					&& $entry['type'] === ( $event['type'] ?? null )
					&& ( ! array_key_exists( 'parent', $entry ) || $entry['parent'] === (int) ( $event['parent'] ?? -1 ) )
					&& $entry['leavename'] === ( $event['leavename'] ?? null )
					&& $entry['sample'] === ( $event['sample'] ?? null );
				$matrix_ok = $matrix_ok && $entry_ok;
				$matrix_observed[] = array(
					'label'    => $entry['label'],
					'actual'   => $entry['actual'],
					'expected' => $entry['expected'],
					'events'   => $events,
					'ok'       => $entry_ok,
				);
			}

			$missing_link = $call_link( 'missing-post', static fn () => \get_post_permalink( 987654321 ) );

			self::collect_failure(
				$failures,
				$matrix_ok && false === $missing_link && array() === $link_events_for_label( 'missing-post' ),
				'get_post_permalink covers custom post type pretty, query-var, raw encoded, plain, leavename, draft, and sample fallback branches',
				array(
					'matrix'      => $matrix_observed,
					'missingLink' => $missing_link,
					'events'      => $link_events,
				)
			);

			self::collect_failure(
				$failures,
				$pretty_cross_type_child_uri === \get_page_uri( $pretty_cross_type_child_id )
					&& $query_cross_type_child_uri === \get_page_uri( $query_cross_type_child_id )
					&& $query_parent_id === (int) ( \get_post( $pretty_cross_type_child_id )->post_parent ?? 0 )
					&& $pretty_parent_id === (int) ( \get_post( $query_cross_type_child_id )->post_parent ?? 0 ),
				'get_page_uri follows generated custom hierarchical cross-type ancestor permalink paths',
				array(
					'prettyCrossType' => array(
						'uri'      => \get_page_uri( $pretty_cross_type_child_id ),
						'expected' => $pretty_cross_type_child_uri,
						'post'     => self::post_summary( \get_post( $pretty_cross_type_child_id ) ),
						'parent'   => self::post_summary( \get_post( $query_parent_id ) ),
					),
					'queryCrossType'  => array(
						'uri'      => \get_page_uri( $query_cross_type_child_id ),
						'expected' => $query_cross_type_child_uri,
						'post'     => self::post_summary( \get_post( $query_cross_type_child_id ) ),
						'parent'   => self::post_summary( \get_post( $pretty_parent_id ) ),
					),
				)
			);

			self::collect_failure(
				$failures,
				$pretty_mixed_leaf_uri === \get_page_uri( $pretty_mixed_leaf_id )
					&& $query_mixed_leaf_uri === \get_page_uri( $query_mixed_leaf_id )
					&& $query_mixed_middle_id === (int) ( \get_post( $pretty_mixed_leaf_id )->post_parent ?? 0 )
					&& $pretty_mixed_root_id === (int) ( \get_post( $query_mixed_middle_id )->post_parent ?? 0 )
					&& $pretty_mixed_middle_id === (int) ( \get_post( $query_mixed_leaf_id )->post_parent ?? 0 )
					&& $query_mixed_root_id === (int) ( \get_post( $pretty_mixed_middle_id )->post_parent ?? 0 ),
				'get_page_uri follows generated custom hierarchical mixed-depth cross-type ancestor paths',
				array(
					'prettyMixedLeaf' => array(
						'uri'      => \get_page_uri( $pretty_mixed_leaf_id ),
						'expected' => $pretty_mixed_leaf_uri,
						'post'     => self::post_summary( \get_post( $pretty_mixed_leaf_id ) ),
						'parent'   => self::post_summary( \get_post( $query_mixed_middle_id ) ),
						'root'     => self::post_summary( \get_post( $pretty_mixed_root_id ) ),
					),
					'queryMixedLeaf'  => array(
						'uri'      => \get_page_uri( $query_mixed_leaf_id ),
						'expected' => $query_mixed_leaf_uri,
						'post'     => self::post_summary( \get_post( $query_mixed_leaf_id ) ),
						'parent'   => self::post_summary( \get_post( $pretty_mixed_middle_id ) ),
						'root'     => self::post_summary( \get_post( $query_mixed_root_id ) ),
					),
				)
			);

			$mixed_lookup_types = array( $pretty_type, $query_type );
			$pretty_mixed_lookup = \get_page_by_path( $pretty_mixed_leaf_uri, OBJECT, $mixed_lookup_types );
			$query_mixed_lookup = \get_page_by_path( $query_mixed_leaf_uri, OBJECT, $mixed_lookup_types );
			$pretty_mixed_encoded_lookup = \get_page_by_path(
				'/' . rawurlencode( $pretty_mixed_root_slug ) . '/' . rawurlencode( $query_mixed_middle_slug ) . '/' . rawurlencode( $pretty_mixed_leaf_slug ) . '/',
				ARRAY_A,
				$mixed_lookup_types
			);
			$pretty_mixed_scalar_miss = \get_page_by_path( $pretty_mixed_leaf_uri, OBJECT, $pretty_type );
			$query_mixed_scalar_miss = \get_page_by_path( $query_mixed_leaf_uri, OBJECT, $query_type );
			$mixed_missing_path = $pretty_mixed_root_slug . '/' . $query_mixed_middle_slug . '/missing-' . $token;
			$mixed_missing_lookup = \get_page_by_path( $mixed_missing_path, OBJECT, $mixed_lookup_types );
			$mixed_lookup_last_changed = \wp_cache_get_last_changed( 'posts' );
			$pretty_mixed_hit_hash = md5( $pretty_mixed_leaf_uri . serialize( $mixed_lookup_types ) );
			$mixed_missing_hash = md5( $mixed_missing_path . serialize( $mixed_lookup_types ) );
			$pretty_mixed_cached_hit = \wp_cache_get_salted( 'get_page_by_path:' . $pretty_mixed_hit_hash, 'post-queries', $mixed_lookup_last_changed );
			$mixed_cached_miss = \wp_cache_get_salted( 'get_page_by_path:' . $mixed_missing_hash, 'post-queries', $mixed_lookup_last_changed );
			\clean_post_cache( $pretty_mixed_leaf_id );
			$mixed_lookup_changed = \wp_cache_get_last_changed( 'posts' );
			$pretty_mixed_cache_after_clean = \wp_cache_get_salted( 'get_page_by_path:' . $pretty_mixed_hit_hash, 'post-queries', $mixed_lookup_changed );

			self::collect_failure(
				$failures,
				$pretty_mixed_lookup instanceof \WP_Post
					&& $pretty_mixed_leaf_id === (int) $pretty_mixed_lookup->ID
					&& $query_mixed_lookup instanceof \WP_Post
					&& $query_mixed_leaf_id === (int) $query_mixed_lookup->ID
					&& is_array( $pretty_mixed_encoded_lookup )
					&& $pretty_mixed_leaf_id === (int) ( $pretty_mixed_encoded_lookup['ID'] ?? 0 )
					&& null === $pretty_mixed_scalar_miss
					&& null === $query_mixed_scalar_miss
					&& null === $mixed_missing_lookup
					&& $pretty_mixed_leaf_id === (int) $pretty_mixed_cached_hit
					&& 0 === (int) $mixed_cached_miss
					&& $mixed_lookup_changed !== $mixed_lookup_last_changed
					&& false === $pretty_mixed_cache_after_clean,
				'get_page_by_path resolves generated custom hierarchical mixed-depth paths only when all ancestor post types are included',
				array(
					'lookupTypes'      => $mixed_lookup_types,
					'prettyLookup'     => self::post_summary( $pretty_mixed_lookup ),
					'queryLookup'      => self::post_summary( $query_mixed_lookup ),
					'encodedLookup'    => $pretty_mixed_encoded_lookup,
					'prettyScalarMiss' => self::post_summary( $pretty_mixed_scalar_miss ),
					'queryScalarMiss'  => self::post_summary( $query_mixed_scalar_miss ),
					'missingLookup'    => self::post_summary( $mixed_missing_lookup ),
					'cachedHit'        => $pretty_mixed_cached_hit,
					'cachedMiss'       => $mixed_cached_miss,
					'lastChangedBefore' => $mixed_lookup_last_changed,
					'lastChangedAfter' => $mixed_lookup_changed,
					'cacheAfterClean'  => $pretty_mixed_cache_after_clean,
				)
			);

			$mutation_lookup_types = array( $pretty_type, $query_type );
			$mutation_initial_lookup = \get_page_by_path( $mutation_initial_path, OBJECT, $mutation_lookup_types );
			$mutation_reparented_before = \get_page_by_path( $mutation_reparented_path, OBJECT, $mutation_lookup_types );
			$mutation_private_bucket_before = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, 'private' );
			$mutation_pending_bucket_before = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, 'pending' );
			$mutation_combined_bucket_before = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, array( 'private', 'pending' ) );
			$mutation_old_parent_publish_before = \get_posts(
				array(
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'post_parent'    => $mutation_parent_a_id,
					'post_status'    => 'publish',
					'post_type'      => $pretty_type,
					'suppress_filters' => false,
				)
			);
			$mutation_new_parent_private_before = \get_posts(
				array(
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'post_parent'    => $mutation_parent_b_id,
					'post_status'    => 'private',
					'post_type'      => $pretty_type,
					'suppress_filters' => false,
				)
			);
			\_prime_post_parent_id_caches( array( $mutation_child_id ) );
			$mutation_parent_cache_before = \wp_cache_get( 'post_parent:' . (string) $mutation_child_id, 'posts' );
			$mutation_last_changed_before = \wp_cache_get_last_changed( 'posts' );
			$mutation_initial_hash = md5( $mutation_initial_path . serialize( $mutation_lookup_types ) );
			$mutation_reparented_hash = md5( $mutation_reparented_path . serialize( $mutation_lookup_types ) );
			$mutation_initial_cached = \wp_cache_get_salted( 'get_page_by_path:' . $mutation_initial_hash, 'post-queries', $mutation_last_changed_before );
			$mutation_reparented_cached_before = \wp_cache_get_salted( 'get_page_by_path:' . $mutation_reparented_hash, 'post-queries', $mutation_last_changed_before );
			$mutation_update_result = \wp_update_post(
				\wp_slash(
					array(
						'ID'          => $mutation_child_id,
						'post_parent' => $mutation_parent_b_id,
						'post_status' => 'private',
					)
				),
				true,
				false
			);
			$mutation_after = \get_post( $mutation_child_id );
			$mutation_parent_cache_after_update = \wp_cache_get( 'post_parent:' . (string) $mutation_child_id, 'posts' );
			$mutation_last_changed_after = \wp_cache_get_last_changed( 'posts' );
			$mutation_initial_cache_after_update = \wp_cache_get_salted( 'get_page_by_path:' . $mutation_initial_hash, 'post-queries', $mutation_last_changed_after );
			$mutation_reparented_cache_after_update = \wp_cache_get_salted( 'get_page_by_path:' . $mutation_reparented_hash, 'post-queries', $mutation_last_changed_after );
			$mutation_private_bucket_stale_after_update = \wp_cache_get_salted( $mutation_private_bucket_before['cacheKey'], 'post-queries', (array) $mutation_last_changed_after );
			$mutation_pending_bucket_stale_after_update = \wp_cache_get_salted( $mutation_pending_bucket_before['cacheKey'], 'post-queries', (array) $mutation_last_changed_after );
			$mutation_combined_bucket_stale_after_update = \wp_cache_get_salted( $mutation_combined_bucket_before['cacheKey'], 'post-queries', (array) $mutation_last_changed_after );
			$mutation_old_parent_publish_after = \get_posts(
				array(
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'post_parent'    => $mutation_parent_a_id,
					'post_status'    => 'publish',
					'post_type'      => $pretty_type,
					'suppress_filters' => false,
				)
			);
			$mutation_new_parent_private_after = \get_posts(
				array(
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'post_parent'    => $mutation_parent_b_id,
					'post_status'    => 'private',
					'post_type'      => $pretty_type,
					'suppress_filters' => false,
				)
			);
			$mutation_private_bucket_after = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, 'private' );
			$mutation_pending_bucket_after = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, 'pending' );
			$mutation_combined_bucket_after = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, array( 'private', 'pending' ) );
			$mutation_combined_after_expected = array( $mutation_child_id, $mutation_pending_child_id );
			sort( $mutation_combined_after_expected );
			$mutation_combined_parent_expected = array();
			$mutation_combined_status_expected = array(
				$mutation_child_id         => 'private',
				$mutation_pending_child_id => 'pending',
			);
			foreach ( $mutation_combined_after_expected as $post_id ) {
				\wp_cache_delete( 'post_parent:' . (string) $post_id, 'posts' );
				$mutation_combined_parent_expected[ $post_id ] = $mutation_parent_b_id;
			}
			ksort( $mutation_combined_status_expected );
			$mutation_combined_id_parent_bucket_after = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, array( 'private', 'pending' ), 'id=>parent' );
			$mutation_combined_object_bucket_after = $query_parent_status_bucket( $pretty_type, $mutation_parent_b_id, array( 'private', 'pending' ), 'all' );
			$ordering_parent_args = array(
				'post_parent'     => null,
				'post_parent__in' => array( $query_parent_id, $pretty_parent_id ),
			);
			$ordering_id_expected = array( $pretty_child_id, $pretty_cross_type_child_id );
			sort( $ordering_id_expected );
			$ordering_parent_in_expected = array( $pretty_cross_type_child_id, $pretty_child_id );
			$ordering_parent_expected = array(
				$pretty_child_id            => $pretty_parent_id,
				$pretty_cross_type_child_id => $query_parent_id,
			);
			$ordering_status_expected = array_fill_keys( array_keys( $ordering_parent_expected ), 'publish' );
			ksort( $ordering_parent_expected );
			ksort( $ordering_status_expected );
			$ordering_id_args = array_merge(
				$ordering_parent_args,
				array(
					'order'   => 'ASC',
					'orderby' => 'ID',
				)
			);
			$ordering_parent_in_args = array_merge(
				$ordering_parent_args,
				array(
					'order'   => 'ASC',
					'orderby' => 'post_parent__in',
				)
			);
			$ordering_id_ids_bucket = $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $ordering_id_args );
			$ordering_id_id_parent_bucket = $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $ordering_id_args );
			$ordering_id_object_bucket = $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $ordering_id_args );
			$ordering_parent_in_ids_bucket = $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $ordering_parent_in_args );
			$ordering_parent_in_id_parent_bucket = $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $ordering_parent_in_args );
			$ordering_parent_in_object_bucket = $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $ordering_parent_in_args );
			$normalized_parent_variants = array(
				'canonical'  => array(
					'parents' => array( $query_parent_id, $pretty_parent_id ),
					'buckets' => array(
						'ids'      => $ordering_id_ids_bucket,
						'idParent' => $ordering_id_id_parent_bucket,
						'object'   => $ordering_id_object_bucket,
					),
				),
				'reversed'   => array(
					'parents' => array( $pretty_parent_id, $query_parent_id ),
				),
				'duplicated' => array(
					'parents' => array( $query_parent_id, $pretty_parent_id, $pretty_parent_id, $query_parent_id ),
				),
			);
			foreach ( $normalized_parent_variants as $variant => $config ) {
				if ( isset( $config['buckets'] ) ) {
					continue;
				}

				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in' => $config['parents'],
					)
				);
				$normalized_parent_variants[ $variant ]['buckets'] = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
			}
			$ordering_id_keys = array(
				$ordering_id_ids_bucket['cacheKey'],
				$ordering_id_id_parent_bucket['cacheKey'],
				$ordering_id_object_bucket['cacheKey'],
			);
			$ordering_parent_in_keys = array(
				$ordering_parent_in_ids_bucket['cacheKey'],
				$ordering_parent_in_id_parent_bucket['cacheKey'],
				$ordering_parent_in_object_bucket['cacheKey'],
			);
			$ordering_id_family_valid = $query_ordering_family_is_valid( $ordering_id_ids_bucket, $ordering_id_id_parent_bucket, $ordering_id_object_bucket, $ordering_id_expected, $ordering_parent_expected, $ordering_status_expected );
			$ordering_parent_in_family_valid = $query_ordering_family_is_valid( $ordering_parent_in_ids_bucket, $ordering_parent_in_id_parent_bucket, $ordering_parent_in_object_bucket, $ordering_parent_in_expected, $ordering_parent_expected, $ordering_status_expected );
			$ordering_keys_disjoint = array() === array_intersect( $ordering_id_keys, $ordering_parent_in_keys );
			$normalized_status_variants = array(
				'canonical'  => array(
					'statuses' => array( 'publish', 'private' ),
				),
				'reversed'   => array(
					'statuses' => array( 'private', 'publish' ),
				),
				'duplicated' => array(
					'statuses' => array( 'publish', 'private', 'publish', 'private' ),
				),
			);
			$normalized_status_parent_args = array(
				$pretty_parent_id,
				$mutation_parent_b_id,
			);
			$normalized_status_expected = array(
				$pretty_child_id,
				$mutation_child_id,
			);
			sort( $normalized_status_expected );
			$normalized_status_parent_expected = array(
				$pretty_child_id    => $pretty_parent_id,
				$mutation_child_id  => $mutation_parent_b_id,
			);
			$normalized_status_status_expected = array(
				$pretty_child_id    => 'publish',
				$mutation_child_id  => 'private',
			);
			ksort( $normalized_status_parent_expected );
			ksort( $normalized_status_status_expected );
			$normalized_status_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$normalized_status_checks = array();
			$normalized_status_query_var_checks = array();
			$normalized_status_actual = array();
			foreach ( $normalized_status_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => $normalized_status_parent_args,
						'post_parent__not_in' => null,
						'post__not_in'        => null,
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, $config['statuses'], 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, $config['statuses'], 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, $config['statuses'], 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_unique( array_map( 'sanitize_key', $config['statuses'] ) ) );
				sort( $expected_query_var );

				$normalized_status_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $normalized_status_expected, $normalized_status_parent_expected, $normalized_status_status_expected );
				$normalized_status_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_status'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_status'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_status'] ?? array() ) ) );
				$normalized_status_keys['ids'][] = $buckets['ids']['cacheKey'];
				$normalized_status_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$normalized_status_keys['object'][] = $buckets['object']['cacheKey'];
				$normalized_status_actual[ $variant ] = array(
					'statusesArg' => $config['statuses'],
					'queryVar'    => array(
						'ids'      => array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_status'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_status'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_status'] ?? array() ) ) ),
					),
					'keys'        => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'    => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'         => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'     => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'    => $buckets['object']['statuses'],
				);
			}
			$normalized_status_valid = ! in_array( false, $normalized_status_checks, true )
				&& ! in_array( false, $normalized_status_query_var_checks, true );
			$normalized_status_keys_shared_by_field = array();
			foreach ( $normalized_status_keys as $field => $keys ) {
				$normalized_status_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$normalized_status_keys_shared = ! in_array( false, $normalized_status_keys_shared_by_field, true );
			$normalized_type_variants = array(
				'canonical'  => array(
					'types' => array( $pretty_type, $query_type ),
				),
				'reversed'   => array(
					'types' => array( $query_type, $pretty_type ),
				),
				'duplicated' => array(
					'types' => array( $pretty_type, $query_type, $pretty_type, $query_type ),
				),
			);
			$normalized_type_expected = array(
				$pretty_child_id,
				$pretty_cross_type_child_id,
				$query_child_id,
				$query_cross_type_child_id,
			);
			sort( $normalized_type_expected );
			$normalized_type_parent_expected = array(
				$pretty_child_id            => $pretty_parent_id,
				$pretty_cross_type_child_id => $query_parent_id,
				$query_child_id             => $query_parent_id,
				$query_cross_type_child_id  => $pretty_parent_id,
			);
			$normalized_type_status_expected = array_fill_keys( $normalized_type_expected, 'publish' );
			ksort( $normalized_type_parent_expected );
			ksort( $normalized_type_status_expected );
			$normalized_type_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$normalized_type_checks = array();
			$normalized_type_query_var_checks = array();
			$normalized_type_actual = array();
			foreach ( $normalized_type_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__not_in' => null,
						'post__not_in'        => null,
						'post_type'           => $config['types'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_unique( array_map( 'sanitize_key', $config['types'] ) ) );
				sort( $expected_query_var );

				$normalized_type_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $normalized_type_expected, $normalized_type_parent_expected, $normalized_type_status_expected );
				$normalized_type_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_type'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_type'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_type'] ?? array() ) ) );
				$normalized_type_keys['ids'][] = $buckets['ids']['cacheKey'];
				$normalized_type_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$normalized_type_keys['object'][] = $buckets['object']['cacheKey'];
				$normalized_type_actual[ $variant ] = array(
					'typesArg' => $config['types'],
					'queryVar' => array(
						'ids'      => array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_type'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_type'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_type'] ?? array() ) ) ),
					),
					'keys'     => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests' => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'      => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'  => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses' => $buckets['object']['statuses'],
				);
			}
			$normalized_type_valid = ! in_array( false, $normalized_type_checks, true )
				&& ! in_array( false, $normalized_type_query_var_checks, true );
			$normalized_type_keys_shared_by_field = array();
			foreach ( $normalized_type_keys as $field => $keys ) {
				$normalized_type_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$normalized_type_keys_shared = ! in_array( false, $normalized_type_keys_shared_by_field, true );
			$normalized_parent_filter_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$normalized_parent_filter_checks = array();
			$normalized_parent_filter_actual = array();
			foreach ( $normalized_parent_variants as $variant => $config ) {
				$buckets = $config['buckets'];
				$normalized_parent_filter_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $ordering_id_expected, $ordering_parent_expected, $ordering_status_expected );
				$normalized_parent_filter_keys['ids'][] = $buckets['ids']['cacheKey'];
				$normalized_parent_filter_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$normalized_parent_filter_keys['object'][] = $buckets['object']['cacheKey'];
				$normalized_parent_filter_actual[ $variant ] = array(
					'parentsArg' => $config['parents'],
					'keys'       => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'   => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'        => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'    => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'   => $buckets['object']['statuses'],
				);
			}
			$normalized_parent_filter_valid = ! in_array( false, $normalized_parent_filter_checks, true );
			$normalized_parent_filter_keys_shared_by_field = array();
			foreach ( $normalized_parent_filter_keys as $field => $keys ) {
				$normalized_parent_filter_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$normalized_parent_filter_keys_shared = ! in_array( false, $normalized_parent_filter_keys_shared_by_field, true );
			$parent_order_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$parent_order_checks = array();
			$parent_order_query_var_checks = array();
			$parent_order_expected = array();
			$parent_order_actual = array();
			$parent_to_child = array(
				$pretty_parent_id => $pretty_child_id,
				$query_parent_id  => $pretty_cross_type_child_id,
			);
			foreach ( $normalized_parent_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_parent_in_args,
					array(
						'post_parent__in' => $config['parents'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_map( 'intval', $config['parents'] ) );
				$expected_ids       = array();
				foreach ( array_values( array_unique( $expected_query_var ) ) as $parent_id ) {
					if ( isset( $parent_to_child[ $parent_id ] ) ) {
						$expected_ids[] = $parent_to_child[ $parent_id ];
					}
				}

				$parent_order_expected[ $variant ] = $expected_ids;
				$parent_order_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $expected_ids, $ordering_parent_expected, $ordering_status_expected );
				$parent_order_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__in'] ?? array() ) ) );
				$parent_order_keys['ids'][] = $buckets['ids']['cacheKey'];
				$parent_order_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$parent_order_keys['object'][] = $buckets['object']['cacheKey'];
				$parent_order_actual[ $variant ] = array(
					'parentsArg' => $config['parents'],
					'expected'   => $expected_ids,
					'queryVar'   => array(
						'ids'      => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__in'] ?? array() ) ) ),
					),
					'keys'       => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'   => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'        => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'    => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'   => $buckets['object']['statuses'],
				);
			}
			$parent_order_valid = ! in_array( false, $parent_order_checks, true )
				&& ! in_array( false, $parent_order_query_var_checks, true );
			$parent_order_keys_distinct_by_field = array();
			foreach ( $parent_order_keys as $field => $keys ) {
				$parent_order_keys_distinct_by_field[ $field ] = count( $keys ) === count( array_unique( $keys ) );
			}
			$parent_order_key_boundaries_hold = ! in_array( false, $parent_order_keys_distinct_by_field, true );
			$included_excluded_parent_variants = array(
				'overlapCanonical'  => array(
					'included' => array( $query_parent_id, $pretty_parent_id ),
					'excluded' => array( $query_parent_id, $pretty_parent_id ),
				),
				'overlapReversed'   => array(
					'included' => array( $query_parent_id, $pretty_parent_id ),
					'excluded' => array( $pretty_parent_id, $query_parent_id ),
				),
				'overlapDuplicated' => array(
					'included' => array( $query_parent_id, $pretty_parent_id ),
					'excluded' => array( $query_parent_id, $pretty_parent_id, $pretty_parent_id, $query_parent_id ),
				),
			);
			$included_excluded_parent_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_excluded_parent_requests = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_excluded_parent_checks = array();
			$included_excluded_parent_query_var_checks = array();
			$included_excluded_parent_sql_checks = array();
			$included_excluded_parent_actual = array();
			foreach ( $included_excluded_parent_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => $config['included'],
						'post_parent__not_in' => $config['excluded'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_in_query_var = array_values( array_map( 'intval', $config['included'] ) );
				$expected_not_in_query_var = array_values( array_map( 'intval', $config['excluded'] ) );

				$included_excluded_parent_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $ordering_id_expected, $ordering_parent_expected, $ordering_status_expected );
				$included_excluded_parent_query_var_checks[ $variant ] = $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__in'] ?? array() ) ) )
					&& $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__in'] ?? array() ) ) )
					&& $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__not_in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__not_in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__not_in'] ?? array() ) ) );
				$included_excluded_parent_sql_checks[ $variant ] = false !== strpos( $buckets['ids']['request'], 'post_parent IN' )
					&& false !== strpos( $buckets['idParent']['request'], 'post_parent IN' )
					&& false !== strpos( $buckets['object']['request'], 'post_parent IN' )
					&& false === strpos( $buckets['ids']['request'], 'post_parent NOT IN' )
					&& false === strpos( $buckets['idParent']['request'], 'post_parent NOT IN' )
					&& false === strpos( $buckets['object']['request'], 'post_parent NOT IN' );
				$included_excluded_parent_keys['ids'][] = $buckets['ids']['cacheKey'];
				$included_excluded_parent_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$included_excluded_parent_keys['object'][] = $buckets['object']['cacheKey'];
				$included_excluded_parent_requests['ids'][] = $buckets['ids']['request'];
				$included_excluded_parent_requests['idParent'][] = $buckets['idParent']['request'];
				$included_excluded_parent_requests['object'][] = $buckets['object']['request'];
				$included_excluded_parent_actual[ $variant ] = array(
					'includedArg' => $config['included'],
					'excludedArg' => $config['excluded'],
					'queryVar'    => array(
						'ids'      => array(
							'post_parent__in'     => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__in'] ?? array() ) ) ),
							'post_parent__not_in' => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__not_in'] ?? array() ) ) ),
						),
						'idParent' => array(
							'post_parent__in'     => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__in'] ?? array() ) ) ),
							'post_parent__not_in' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__not_in'] ?? array() ) ) ),
						),
						'object'   => array(
							'post_parent__in'     => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__in'] ?? array() ) ) ),
							'post_parent__not_in' => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__not_in'] ?? array() ) ) ),
						),
					),
					'keys'        => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'    => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'         => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'     => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'    => $buckets['object']['statuses'],
				);
			}
			$included_excluded_parent_valid = ! in_array( false, $included_excluded_parent_checks, true )
				&& ! in_array( false, $included_excluded_parent_query_var_checks, true )
				&& ! in_array( false, $included_excluded_parent_sql_checks, true );
			$included_excluded_parent_requests_shared_by_field = array();
			foreach ( $included_excluded_parent_requests as $field => $requests ) {
				$included_excluded_parent_requests_shared_by_field[ $field ] = 1 === count( array_unique( $requests ) );
			}
			$included_excluded_parent_requests_shared = ! in_array( false, $included_excluded_parent_requests_shared_by_field, true );
			$included_excluded_parent_keys_distinct_by_field = array();
			foreach ( $included_excluded_parent_keys as $field => $keys ) {
				$included_excluded_parent_keys_distinct_by_field[ $field ] = count( $keys ) === count( array_unique( $keys ) );
			}
			$included_excluded_parent_key_boundaries_hold = ! in_array( false, $included_excluded_parent_keys_distinct_by_field, true );
			$excluded_parent_variants = array(
				'canonical'  => array(
					'parents' => array( $query_parent_id, $pretty_parent_id ),
				),
				'reversed'   => array(
					'parents' => array( $pretty_parent_id, $query_parent_id ),
				),
				'duplicated' => array(
					'parents' => array( $query_parent_id, $pretty_parent_id, $pretty_parent_id, $query_parent_id ),
				),
			);
			$excluded_parent_expected = array(
				$pretty_parent_id,
				$pretty_missing_parent_id,
				$pretty_self_parent_id,
				$pretty_private_child_id,
				$pretty_trash_child_id,
				$pretty_mixed_root_id,
				$pretty_mixed_leaf_id,
				$pretty_mixed_middle_id,
				$mutation_parent_a_id,
			);
			sort( $excluded_parent_expected );
			$excluded_parent_map_expected = array(
				$pretty_parent_id         => 0,
				$pretty_missing_parent_id => $missing_parent_id,
				$pretty_self_parent_id    => $pretty_self_parent_id,
				$pretty_private_child_id  => $pretty_private_parent_id,
				$pretty_trash_child_id    => $pretty_trash_parent_id,
				$pretty_mixed_root_id     => 0,
				$pretty_mixed_leaf_id     => $query_mixed_middle_id,
				$pretty_mixed_middle_id   => $query_mixed_root_id,
				$mutation_parent_a_id     => 0,
			);
			$excluded_parent_status_expected = array_fill_keys( $excluded_parent_expected, 'publish' );
			ksort( $excluded_parent_map_expected );
			ksort( $excluded_parent_status_expected );
			$excluded_parent_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$excluded_parent_checks = array();
			$excluded_parent_query_var_checks = array();
			$excluded_parent_actual = array();
			foreach ( $excluded_parent_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => null,
						'post_parent__not_in' => $config['parents'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_map( 'intval', $config['parents'] ) );
				sort( $expected_query_var );

				$excluded_parent_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $excluded_parent_expected, $excluded_parent_map_expected, $excluded_parent_status_expected );
				$excluded_parent_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__not_in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__not_in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__not_in'] ?? array() ) ) );
				$excluded_parent_keys['ids'][] = $buckets['ids']['cacheKey'];
				$excluded_parent_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$excluded_parent_keys['object'][] = $buckets['object']['cacheKey'];
				$excluded_parent_actual[ $variant ] = array(
					'parentsArg' => $config['parents'],
					'queryVar'   => array(
						'ids'      => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post_parent__not_in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post_parent__not_in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post_parent__not_in'] ?? array() ) ) ),
					),
					'keys'       => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'   => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'        => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'    => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'   => $buckets['object']['statuses'],
				);
			}
			$excluded_parent_valid = ! in_array( false, $excluded_parent_checks, true )
				&& ! in_array( false, $excluded_parent_query_var_checks, true );
			$excluded_parent_canonical_reversed_keys_shared = array(
				'ids'      => ( $excluded_parent_keys['ids'][0] ?? null ) === ( $excluded_parent_keys['ids'][1] ?? false ),
				'idParent' => ( $excluded_parent_keys['idParent'][0] ?? null ) === ( $excluded_parent_keys['idParent'][1] ?? false ),
				'object'   => ( $excluded_parent_keys['object'][0] ?? null ) === ( $excluded_parent_keys['object'][1] ?? false ),
			);
			$excluded_parent_duplicate_keys_distinct = array(
				'ids'      => ( $excluded_parent_keys['ids'][0] ?? null ) !== ( $excluded_parent_keys['ids'][2] ?? null ),
				'idParent' => ( $excluded_parent_keys['idParent'][0] ?? null ) !== ( $excluded_parent_keys['idParent'][2] ?? null ),
				'object'   => ( $excluded_parent_keys['object'][0] ?? null ) !== ( $excluded_parent_keys['object'][2] ?? null ),
			);
			$excluded_parent_key_boundaries_hold = ! in_array( false, $excluded_parent_canonical_reversed_keys_shared, true )
				&& ! in_array( false, $excluded_parent_duplicate_keys_distinct, true );
			$excluded_post_variants = array(
				'canonical'  => array(
					'ids' => array( $pretty_child_id, $pretty_cross_type_child_id ),
				),
				'reversed'   => array(
					'ids' => array( $pretty_cross_type_child_id, $pretty_child_id ),
				),
				'duplicated' => array(
					'ids' => array( $pretty_child_id, $pretty_cross_type_child_id, $pretty_child_id, $pretty_cross_type_child_id ),
				),
			);
			$excluded_post_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$excluded_post_checks = array();
			$excluded_post_query_var_checks = array();
			$excluded_post_actual = array();
			foreach ( $excluded_post_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => null,
						'post_parent__not_in' => null,
						'post__not_in'        => $config['ids'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_map( 'intval', $config['ids'] ) );
				sort( $expected_query_var );

				$excluded_post_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $excluded_parent_expected, $excluded_parent_map_expected, $excluded_parent_status_expected );
				$excluded_post_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__not_in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__not_in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__not_in'] ?? array() ) ) );
				$excluded_post_keys['ids'][] = $buckets['ids']['cacheKey'];
				$excluded_post_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$excluded_post_keys['object'][] = $buckets['object']['cacheKey'];
				$excluded_post_actual[ $variant ] = array(
					'idsArg'   => $config['ids'],
					'queryVar' => array(
						'ids'      => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__not_in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__not_in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__not_in'] ?? array() ) ) ),
					),
					'keys'     => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests' => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'      => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'  => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses' => $buckets['object']['statuses'],
				);
			}
			$excluded_post_valid = ! in_array( false, $excluded_post_checks, true )
				&& ! in_array( false, $excluded_post_query_var_checks, true );
			$excluded_post_canonical_reversed_keys_shared = array(
				'ids'      => ( $excluded_post_keys['ids'][0] ?? null ) === ( $excluded_post_keys['ids'][1] ?? false ),
				'idParent' => ( $excluded_post_keys['idParent'][0] ?? null ) === ( $excluded_post_keys['idParent'][1] ?? false ),
				'object'   => ( $excluded_post_keys['object'][0] ?? null ) === ( $excluded_post_keys['object'][1] ?? false ),
			);
			$excluded_post_duplicate_keys_distinct = array(
				'ids'      => ( $excluded_post_keys['ids'][0] ?? null ) !== ( $excluded_post_keys['ids'][2] ?? null ),
				'idParent' => ( $excluded_post_keys['idParent'][0] ?? null ) !== ( $excluded_post_keys['idParent'][2] ?? null ),
				'object'   => ( $excluded_post_keys['object'][0] ?? null ) !== ( $excluded_post_keys['object'][2] ?? null ),
			);
			$excluded_post_key_boundaries_hold = ! in_array( false, $excluded_post_canonical_reversed_keys_shared, true )
				&& ! in_array( false, $excluded_post_duplicate_keys_distinct, true );
			$included_post_variants = array(
				'canonical'  => array(
					'ids' => array( $pretty_child_id, $pretty_cross_type_child_id ),
				),
				'reversed'   => array(
					'ids' => array( $pretty_cross_type_child_id, $pretty_child_id ),
				),
				'duplicated' => array(
					'ids' => array( $pretty_child_id, $pretty_cross_type_child_id, $pretty_child_id, $pretty_cross_type_child_id ),
				),
			);
			$included_post_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_post_checks = array();
			$included_post_query_var_checks = array();
			$included_post_actual = array();
			foreach ( $included_post_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__not_in' => null,
						'post__in'            => $config['ids'],
						'post__not_in'        => null,
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_map( 'intval', $config['ids'] ) );

				$included_post_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $ordering_id_expected, $ordering_parent_expected, $ordering_status_expected );
				$included_post_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__in'] ?? array() ) ) );
				$included_post_keys['ids'][] = $buckets['ids']['cacheKey'];
				$included_post_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$included_post_keys['object'][] = $buckets['object']['cacheKey'];
				$included_post_actual[ $variant ] = array(
					'idsArg'   => $config['ids'],
					'queryVar' => array(
						'ids'      => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__in'] ?? array() ) ) ),
					),
					'keys'     => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests' => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'      => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'  => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses' => $buckets['object']['statuses'],
				);
			}
			$included_post_valid = ! in_array( false, $included_post_checks, true )
				&& ! in_array( false, $included_post_query_var_checks, true );
			$included_post_keys_shared_by_field = array();
			foreach ( $included_post_keys as $field => $keys ) {
				$included_post_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$included_post_keys_shared = ! in_array( false, $included_post_keys_shared_by_field, true );
			$included_excluded_post_variants = array(
				'overlapCanonical'  => array(
					'included' => array( $pretty_child_id, $pretty_cross_type_child_id ),
					'excluded' => array( $pretty_child_id, $pretty_cross_type_child_id ),
				),
				'overlapReversed'   => array(
					'included' => array( $pretty_child_id, $pretty_cross_type_child_id ),
					'excluded' => array( $pretty_cross_type_child_id, $pretty_child_id ),
				),
				'overlapDuplicated' => array(
					'included' => array( $pretty_child_id, $pretty_cross_type_child_id ),
					'excluded' => array( $pretty_child_id, $pretty_cross_type_child_id, $pretty_child_id, $pretty_cross_type_child_id ),
				),
			);
			$included_excluded_post_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_excluded_post_requests = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_excluded_post_checks = array();
			$included_excluded_post_query_var_checks = array();
			$included_excluded_post_actual = array();
			foreach ( $included_excluded_post_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__not_in' => null,
						'post__in'            => $config['included'],
						'post__not_in'        => $config['excluded'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_in_query_var = array_values( array_map( 'intval', $config['included'] ) );
				$expected_not_in_query_var = array_values( array_map( 'intval', $config['excluded'] ) );

				$included_excluded_post_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $ordering_id_expected, $ordering_parent_expected, $ordering_status_expected );
				$included_excluded_post_query_var_checks[ $variant ] = $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__in'] ?? array() ) ) )
					&& $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__in'] ?? array() ) ) )
					&& $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__not_in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__not_in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__not_in'] ?? array() ) ) );
				$included_excluded_post_keys['ids'][] = $buckets['ids']['cacheKey'];
				$included_excluded_post_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$included_excluded_post_keys['object'][] = $buckets['object']['cacheKey'];
				$included_excluded_post_requests['ids'][] = $buckets['ids']['request'];
				$included_excluded_post_requests['idParent'][] = $buckets['idParent']['request'];
				$included_excluded_post_requests['object'][] = $buckets['object']['request'];
				$included_excluded_post_actual[ $variant ] = array(
					'includedArg' => $config['included'],
					'excludedArg' => $config['excluded'],
					'queryVar'    => array(
						'ids'      => array(
							'post__in'     => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__in'] ?? array() ) ) ),
							'post__not_in' => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__not_in'] ?? array() ) ) ),
						),
						'idParent' => array(
							'post__in'     => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__in'] ?? array() ) ) ),
							'post__not_in' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__not_in'] ?? array() ) ) ),
						),
						'object'   => array(
							'post__in'     => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__in'] ?? array() ) ) ),
							'post__not_in' => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__not_in'] ?? array() ) ) ),
						),
					),
					'keys'        => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'    => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'         => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'     => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'    => $buckets['object']['statuses'],
				);
			}
			$included_excluded_post_valid = ! in_array( false, $included_excluded_post_checks, true )
				&& ! in_array( false, $included_excluded_post_query_var_checks, true );
			$included_excluded_post_requests_shared_by_field = array();
			foreach ( $included_excluded_post_requests as $field => $requests ) {
				$included_excluded_post_requests_shared_by_field[ $field ] = 1 === count( array_unique( $requests ) );
			}
			$included_excluded_post_requests_shared = ! in_array( false, $included_excluded_post_requests_shared_by_field, true );
			$included_excluded_post_keys_distinct_by_field = array();
			foreach ( $included_excluded_post_keys as $field => $keys ) {
				$included_excluded_post_keys_distinct_by_field[ $field ] = count( $keys ) === count( array_unique( $keys ) );
			}
			$included_excluded_post_key_boundaries_hold = ! in_array( false, $included_excluded_post_keys_distinct_by_field, true );
			$included_post_order_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_post_order_checks = array();
			$included_post_order_query_var_checks = array();
			$included_post_order_expected = array();
			$included_post_order_actual = array();
			foreach ( $included_post_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'orderby'             => 'post__in',
						'post_parent__not_in' => null,
						'post__in'            => $config['ids'],
						'post__not_in'        => null,
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_map( 'intval', $config['ids'] ) );
				$expected_ids       = array_values( array_unique( $expected_query_var ) );

				$included_post_order_expected[ $variant ] = $expected_ids;
				$included_post_order_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $expected_ids, $ordering_parent_expected, $ordering_status_expected );
				$included_post_order_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__in'] ?? array() ) ) );
				$included_post_order_keys['ids'][] = $buckets['ids']['cacheKey'];
				$included_post_order_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$included_post_order_keys['object'][] = $buckets['object']['cacheKey'];
				$included_post_order_actual[ $variant ] = array(
					'idsArg'   => $config['ids'],
					'expected' => $expected_ids,
					'queryVar' => array(
						'ids'      => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['post__in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['post__in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['post__in'] ?? array() ) ) ),
					),
					'keys'     => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests' => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'      => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'  => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses' => $buckets['object']['statuses'],
				);
			}
			$included_post_order_valid = ! in_array( false, $included_post_order_checks, true )
				&& ! in_array( false, $included_post_order_query_var_checks, true );
			$included_post_order_keys_distinct_by_field = array();
			foreach ( $included_post_order_keys as $field => $keys ) {
				$included_post_order_keys_distinct_by_field[ $field ] = count( $keys ) === count( array_unique( $keys ) );
			}
			$included_post_order_key_boundaries_hold = ! in_array( false, $included_post_order_keys_distinct_by_field, true );
			$excluded_author_variants = array(
				'canonical'  => array(
					'authors' => array( $author_excluded_a_id, $author_excluded_b_id ),
				),
				'reversed'   => array(
					'authors' => array( $author_excluded_b_id, $author_excluded_a_id ),
				),
				'duplicated' => array(
					'authors' => array( $author_excluded_a_id, $author_excluded_b_id, $author_excluded_a_id, $author_excluded_b_id ),
				),
			);
			$excluded_author_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$excluded_author_checks = array();
			$excluded_author_query_var_checks = array();
			$excluded_author_actual = array();
			foreach ( $excluded_author_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => null,
						'post_parent__not_in' => null,
						'post__not_in'        => null,
						'author__not_in'      => $config['authors'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_unique( array_map( 'absint', $config['authors'] ) ) );
				sort( $expected_query_var );

				$excluded_author_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $excluded_parent_expected, $excluded_parent_map_expected, $excluded_parent_status_expected );
				$excluded_author_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__not_in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__not_in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__not_in'] ?? array() ) ) );
				$excluded_author_keys['ids'][] = $buckets['ids']['cacheKey'];
				$excluded_author_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$excluded_author_keys['object'][] = $buckets['object']['cacheKey'];
				$excluded_author_actual[ $variant ] = array(
					'authorsArg' => $config['authors'],
					'queryVar'   => array(
						'ids'      => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__not_in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__not_in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__not_in'] ?? array() ) ) ),
					),
					'keys'       => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'   => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'        => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'    => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'   => $buckets['object']['statuses'],
				);
			}
			$excluded_author_valid = ! in_array( false, $excluded_author_checks, true )
				&& ! in_array( false, $excluded_author_query_var_checks, true );
			$excluded_author_keys_shared_by_field = array();
			foreach ( $excluded_author_keys as $field => $keys ) {
				$excluded_author_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$excluded_author_keys_shared = ! in_array( false, $excluded_author_keys_shared_by_field, true );
			$included_author_variants = array(
				'canonical'  => array(
					'authors' => array( $author_excluded_a_id, $author_excluded_b_id ),
				),
				'reversed'   => array(
					'authors' => array( $author_excluded_b_id, $author_excluded_a_id ),
				),
				'duplicated' => array(
					'authors' => array( $author_excluded_a_id, $author_excluded_b_id, $author_excluded_a_id, $author_excluded_b_id ),
				),
			);
			$included_author_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_author_checks = array();
			$included_author_query_var_checks = array();
			$included_author_actual = array();
			foreach ( $included_author_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__not_in' => null,
						'post__not_in'        => null,
						'author__in'          => $config['authors'],
						'author__not_in'      => null,
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_unique( array_map( 'absint', $config['authors'] ) ) );
				sort( $expected_query_var );

				$included_author_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $ordering_id_expected, $ordering_parent_expected, $ordering_status_expected );
				$included_author_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__in'] ?? array() ) ) );
				$included_author_keys['ids'][] = $buckets['ids']['cacheKey'];
				$included_author_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$included_author_keys['object'][] = $buckets['object']['cacheKey'];
				$included_author_actual[ $variant ] = array(
					'authorsArg' => $config['authors'],
					'queryVar'   => array(
						'ids'      => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__in'] ?? array() ) ) ),
					),
					'keys'       => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'   => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'        => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'    => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'   => $buckets['object']['statuses'],
				);
			}
			$included_author_valid = ! in_array( false, $included_author_checks, true )
				&& ! in_array( false, $included_author_query_var_checks, true );
			$included_author_keys_shared_by_field = array();
			foreach ( $included_author_keys as $field => $keys ) {
				$included_author_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$included_author_keys_shared = ! in_array( false, $included_author_keys_shared_by_field, true );
			$included_excluded_author_variants = array(
				'overlapCanonical'  => array(
					'included' => array( $author_excluded_a_id, $author_excluded_b_id ),
					'excluded' => array( $author_excluded_a_id, $author_excluded_b_id ),
				),
				'overlapReversed'   => array(
					'included' => array( $author_excluded_b_id, $author_excluded_a_id ),
					'excluded' => array( $author_excluded_b_id, $author_excluded_a_id ),
				),
				'overlapDuplicated' => array(
					'included' => array( $author_excluded_a_id, $author_excluded_b_id, $author_excluded_a_id, $author_excluded_b_id ),
					'excluded' => array( $author_excluded_a_id, $author_excluded_b_id, $author_excluded_a_id, $author_excluded_b_id ),
				),
			);
			$included_excluded_author_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_excluded_author_requests = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$included_excluded_author_checks = array();
			$included_excluded_author_query_var_checks = array();
			$included_excluded_author_sql_checks = array();
			$included_excluded_author_actual = array();
			foreach ( $included_excluded_author_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => null,
						'post_parent__not_in' => null,
						'post__not_in'        => null,
						'author__in'          => $config['included'],
						'author__not_in'      => $config['excluded'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_in_query_var = array_values( array_map( 'intval', $config['included'] ) );
				$expected_not_in_query_var = array_values( array_unique( array_map( 'absint', $config['excluded'] ) ) );
				sort( $expected_not_in_query_var );

				$included_excluded_author_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $excluded_parent_expected, $excluded_parent_map_expected, $excluded_parent_status_expected );
				$included_excluded_author_query_var_checks[ $variant ] = $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__not_in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__not_in'] ?? array() ) ) )
					&& $expected_not_in_query_var === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__not_in'] ?? array() ) ) );
				$included_excluded_author_sql_checks[ $variant ] = false !== strpos( $buckets['ids']['request'], 'post_author NOT IN' )
					&& false !== strpos( $buckets['idParent']['request'], 'post_author NOT IN' )
					&& false !== strpos( $buckets['object']['request'], 'post_author NOT IN' )
					&& false === strpos( $buckets['ids']['request'], 'post_author IN' )
					&& false === strpos( $buckets['idParent']['request'], 'post_author IN' )
					&& false === strpos( $buckets['object']['request'], 'post_author IN' );
				$included_excluded_author_keys['ids'][] = $buckets['ids']['cacheKey'];
				$included_excluded_author_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$included_excluded_author_keys['object'][] = $buckets['object']['cacheKey'];
				$included_excluded_author_requests['ids'][] = $buckets['ids']['request'];
				$included_excluded_author_requests['idParent'][] = $buckets['idParent']['request'];
				$included_excluded_author_requests['object'][] = $buckets['object']['request'];
				$included_excluded_author_actual[ $variant ] = array(
					'includedArg' => $config['included'],
					'excludedArg' => $config['excluded'],
					'queryVar'    => array(
						'ids'      => array(
							'author__in'     => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__in'] ?? array() ) ) ),
							'author__not_in' => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__not_in'] ?? array() ) ) ),
						),
						'idParent' => array(
							'author__in'     => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__in'] ?? array() ) ) ),
							'author__not_in' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__not_in'] ?? array() ) ) ),
						),
						'object'   => array(
							'author__in'     => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__in'] ?? array() ) ) ),
							'author__not_in' => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__not_in'] ?? array() ) ) ),
						),
					),
					'keys'        => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'    => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'         => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'     => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'    => $buckets['object']['statuses'],
				);
			}
			$included_excluded_author_valid = ! in_array( false, $included_excluded_author_checks, true )
				&& ! in_array( false, $included_excluded_author_query_var_checks, true )
				&& ! in_array( false, $included_excluded_author_sql_checks, true );
			$included_excluded_author_requests_shared_by_field = array();
			foreach ( $included_excluded_author_requests as $field => $requests ) {
				$included_excluded_author_requests_shared_by_field[ $field ] = 1 === count( array_unique( $requests ) );
			}
			$included_excluded_author_requests_shared = ! in_array( false, $included_excluded_author_requests_shared_by_field, true );
			$included_excluded_author_keys_distinct_by_field = array();
			foreach ( $included_excluded_author_keys as $field => $keys ) {
				$included_excluded_author_keys_distinct_by_field[ $field ] = count( $keys ) === count( array_unique( $keys ) );
			}
			$included_excluded_author_key_boundaries_hold = ! in_array( false, $included_excluded_author_keys_distinct_by_field, true );
			$author_name_user = \get_userdata( $author_excluded_a_id );
			$author_name_slug = $author_name_user instanceof \WP_User ? (string) $author_name_user->user_nicename : '';
			$author_name_expected = array( $pretty_child_id );
			$author_name_parent_expected = array(
				$pretty_child_id => $pretty_parent_id,
			);
			$author_name_status_expected = array_fill_keys( $author_name_expected, 'publish' );
			ksort( $author_name_parent_expected );
			ksort( $author_name_status_expected );
			$author_name_variants = array(
				'canonical'     => array(
					'authorName' => $author_name_slug,
				),
				'nestedPath'    => array(
					'authorName' => 'archive/' . $author_name_slug,
				),
				'trailingSlash' => array(
					'authorName' => 'archive/' . $author_name_slug . '/',
				),
			);
			$author_name_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$author_name_requests = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$author_name_checks = array();
			$author_name_query_var_checks = array();
			$author_name_sql_checks = array();
			$author_name_actual = array();
			foreach ( $author_name_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'author'         => null,
						'author_name'    => $config['authorName'],
						'author__in'     => null,
						'author__not_in' => null,
						'post__not_in'   => null,
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_author_name = sanitize_title_for_query( $author_name_slug );

				$author_name_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $author_name_expected, $author_name_parent_expected, $author_name_status_expected );
				$author_name_query_var_checks[ $variant ] = $expected_author_name === (string) ( $buckets['ids']['queryVars']['author_name'] ?? '' )
					&& $expected_author_name === (string) ( $buckets['idParent']['queryVars']['author_name'] ?? '' )
					&& $expected_author_name === (string) ( $buckets['object']['queryVars']['author_name'] ?? '' )
					&& $author_excluded_a_id === (int) ( $buckets['ids']['queryVars']['author'] ?? 0 )
					&& $author_excluded_a_id === (int) ( $buckets['idParent']['queryVars']['author'] ?? 0 )
					&& $author_excluded_a_id === (int) ( $buckets['object']['queryVars']['author'] ?? 0 );
				$author_name_sql_checks[ $variant ] = false !== strpos( $buckets['ids']['request'], 'post_author = ' . (string) $author_excluded_a_id )
					&& false !== strpos( $buckets['idParent']['request'], 'post_author = ' . (string) $author_excluded_a_id )
					&& false !== strpos( $buckets['object']['request'], 'post_author = ' . (string) $author_excluded_a_id );
				$author_name_keys['ids'][] = $buckets['ids']['cacheKey'];
				$author_name_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$author_name_keys['object'][] = $buckets['object']['cacheKey'];
				$author_name_requests['ids'][] = $buckets['ids']['request'];
				$author_name_requests['idParent'][] = $buckets['idParent']['request'];
				$author_name_requests['object'][] = $buckets['object']['request'];
				$author_name_actual[ $variant ] = array(
					'authorNameArg' => $config['authorName'],
					'queryVar'      => array(
						'ids'      => array(
							'author_name' => (string) ( $buckets['ids']['queryVars']['author_name'] ?? '' ),
							'author'      => (int) ( $buckets['ids']['queryVars']['author'] ?? 0 ),
						),
						'idParent' => array(
							'author_name' => (string) ( $buckets['idParent']['queryVars']['author_name'] ?? '' ),
							'author'      => (int) ( $buckets['idParent']['queryVars']['author'] ?? 0 ),
						),
						'object'   => array(
							'author_name' => (string) ( $buckets['object']['queryVars']['author_name'] ?? '' ),
							'author'      => (int) ( $buckets['object']['queryVars']['author'] ?? 0 ),
						),
					),
					'keys'          => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'      => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'           => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'       => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'      => $buckets['object']['statuses'],
				);
			}
			$author_name_valid = ! in_array( false, $author_name_checks, true )
				&& ! in_array( false, $author_name_query_var_checks, true )
				&& ! in_array( false, $author_name_sql_checks, true );
			$author_name_requests_shared_by_field = array();
			foreach ( $author_name_requests as $field => $requests ) {
				$author_name_requests_shared_by_field[ $field ] = 1 === count( array_unique( $requests ) );
			}
			$author_name_requests_shared = ! in_array( false, $author_name_requests_shared_by_field, true );
			$author_name_keys_shared_by_field = array();
			foreach ( $author_name_keys as $field => $keys ) {
				$author_name_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$author_name_keys_shared = ! in_array( false, $author_name_keys_shared_by_field, true );
			$legacy_author_variants = array(
				'canonical'  => array(
					'author' => $author_excluded_a_id . ',-' . $author_excluded_b_id,
				),
				'reversed'   => array(
					'author' => '-' . $author_excluded_b_id . ',' . $author_excluded_a_id,
				),
				'duplicated' => array(
					'author' => $author_excluded_a_id . ',-' . $author_excluded_b_id . ',' . $author_excluded_a_id . ',-' . $author_excluded_b_id,
				),
			);
			$normalize_legacy_author_arg = static function ( string $author_arg ): array {
				$author_arg = preg_replace( '|[^0-9,-]|', '', $author_arg );
				$authors    = array_unique( array_map( 'intval', preg_split( '/[,\s]+/', $author_arg ) ) );
				sort( $authors );

				$included_authors = array();
				$excluded_authors = array();
				foreach ( $authors as $author_id ) {
					if ( $author_id > 0 ) {
						$included_authors[] = abs( $author_id );
					} else {
						$excluded_authors[] = abs( $author_id );
					}
				}

				return array(
					'author'         => implode( ',', $authors ),
					'author__in'     => $included_authors,
					'author__not_in' => $excluded_authors,
				);
			};
			$legacy_author_parent_args = array(
				$pretty_parent_id,
				$query_parent_id,
				$query_mixed_middle_id,
			);
			$legacy_author_expected = array(
				$pretty_child_id,
				$pretty_mixed_leaf_id,
			);
			sort( $legacy_author_expected );
			$legacy_author_parent_expected = array(
				$pretty_child_id       => $pretty_parent_id,
				$pretty_mixed_leaf_id  => $query_mixed_middle_id,
			);
			$legacy_author_status_expected = array_fill_keys( $legacy_author_expected, 'publish' );
			ksort( $legacy_author_parent_expected );
			ksort( $legacy_author_status_expected );
			$legacy_author_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$legacy_author_checks = array();
			$legacy_author_query_var_checks = array();
			$legacy_author_actual = array();
			foreach ( $legacy_author_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => $legacy_author_parent_args,
						'post_parent__not_in' => null,
						'post__not_in'        => null,
						'author'              => $config['author'],
						'author__in'          => null,
						'author__not_in'      => null,
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_vars = $normalize_legacy_author_arg( $config['author'] );

				$legacy_author_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $legacy_author_expected, $legacy_author_parent_expected, $legacy_author_status_expected );
				$legacy_author_query_var_checks[ $variant ] = $expected_query_vars['author'] === (string) ( $buckets['ids']['queryVars']['author'] ?? '' )
					&& $expected_query_vars['author'] === (string) ( $buckets['idParent']['queryVars']['author'] ?? '' )
					&& $expected_query_vars['author'] === (string) ( $buckets['object']['queryVars']['author'] ?? '' )
					&& $expected_query_vars['author__in'] === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_query_vars['author__in'] === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_query_vars['author__in'] === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__in'] ?? array() ) ) )
					&& $expected_query_vars['author__not_in'] === array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__not_in'] ?? array() ) ) )
					&& $expected_query_vars['author__not_in'] === array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__not_in'] ?? array() ) ) )
					&& $expected_query_vars['author__not_in'] === array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__not_in'] ?? array() ) ) );
				$legacy_author_keys['ids'][] = $buckets['ids']['cacheKey'];
				$legacy_author_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$legacy_author_keys['object'][] = $buckets['object']['cacheKey'];
				$legacy_author_actual[ $variant ] = array(
					'authorArg' => $config['author'],
					'queryVar'  => array(
						'expected' => $expected_query_vars,
						'ids'      => array(
							'author'         => (string) ( $buckets['ids']['queryVars']['author'] ?? '' ),
							'author__in'     => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__in'] ?? array() ) ) ),
							'author__not_in' => array_values( array_map( 'intval', (array) ( $buckets['ids']['queryVars']['author__not_in'] ?? array() ) ) ),
						),
						'idParent' => array(
							'author'         => (string) ( $buckets['idParent']['queryVars']['author'] ?? '' ),
							'author__in'     => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__in'] ?? array() ) ) ),
							'author__not_in' => array_values( array_map( 'intval', (array) ( $buckets['idParent']['queryVars']['author__not_in'] ?? array() ) ) ),
						),
						'object'   => array(
							'author'         => (string) ( $buckets['object']['queryVars']['author'] ?? '' ),
							'author__in'     => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__in'] ?? array() ) ) ),
							'author__not_in' => array_values( array_map( 'intval', (array) ( $buckets['object']['queryVars']['author__not_in'] ?? array() ) ) ),
						),
					),
					'keys'      => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests'  => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'       => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'   => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses'  => $buckets['object']['statuses'],
				);
			}
			$legacy_author_valid = ! in_array( false, $legacy_author_checks, true )
				&& ! in_array( false, $legacy_author_query_var_checks, true );
			$legacy_author_keys_shared_by_field = array();
			foreach ( $legacy_author_keys as $field => $keys ) {
				$legacy_author_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$legacy_author_keys_shared = ! in_array( false, $legacy_author_keys_shared_by_field, true );
			$normalized_slug_variants = array(
				'canonical'  => array(
					'slugs' => array( $pretty_child_slug, $pretty_cross_type_child_slug ),
				),
				'reversed'   => array(
					'slugs' => array( $pretty_cross_type_child_slug, $pretty_child_slug ),
				),
				'duplicated' => array(
					'slugs' => array( $pretty_child_slug, $pretty_cross_type_child_slug, $pretty_child_slug, $pretty_cross_type_child_slug ),
				),
			);
			$normalized_slug_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$normalized_slug_checks = array();
			$normalized_slug_query_var_checks = array();
			$normalized_slug_actual = array();
			foreach ( $normalized_slug_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'post_parent__in'     => null,
						'post_parent__not_in' => null,
						'post__not_in'        => null,
						'post_name__in'       => $config['slugs'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_map( 'sanitize_title_for_query', $config['slugs'] ) );

				$normalized_slug_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $ordering_id_expected, $ordering_parent_expected, $ordering_status_expected );
				$normalized_slug_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_name__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_name__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_name__in'] ?? array() ) ) );
				$normalized_slug_keys['ids'][] = $buckets['ids']['cacheKey'];
				$normalized_slug_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$normalized_slug_keys['object'][] = $buckets['object']['cacheKey'];
				$normalized_slug_actual[ $variant ] = array(
					'slugsArg' => $config['slugs'],
					'queryVar' => array(
						'ids'      => array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_name__in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_name__in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_name__in'] ?? array() ) ) ),
					),
					'keys'     => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests' => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'      => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'  => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses' => $buckets['object']['statuses'],
				);
			}
			$normalized_slug_valid = ! in_array( false, $normalized_slug_checks, true )
				&& ! in_array( false, $normalized_slug_query_var_checks, true );
			$normalized_slug_keys_shared_by_field = array();
			foreach ( $normalized_slug_keys as $field => $keys ) {
				$normalized_slug_keys_shared_by_field[ $field ] = 1 === count( array_unique( $keys ) );
			}
			$normalized_slug_keys_shared = ! in_array( false, $normalized_slug_keys_shared_by_field, true );
			$slug_order_keys = array(
				'ids'      => array(),
				'idParent' => array(),
				'object'   => array(),
			);
			$slug_order_checks = array();
			$slug_order_query_var_checks = array();
			$slug_order_expected = array();
			$slug_order_actual = array();
			$slug_to_id = array(
				$pretty_child_slug            => $pretty_child_id,
				$pretty_cross_type_child_slug => $pretty_cross_type_child_id,
			);
			foreach ( $normalized_slug_variants as $variant => $config ) {
				$variant_args = array_merge(
					$ordering_id_args,
					array(
						'orderby'              => 'post_name__in',
						'post_parent__in'      => null,
						'post_parent__not_in'  => null,
						'post__not_in'         => null,
						'post_name__in'        => $config['slugs'],
					)
				);
				$buckets = array(
					'ids'      => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'ids', $variant_args ),
					'idParent' => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'id=>parent', $variant_args ),
					'object'   => $query_parent_status_bucket( $pretty_type, 0, 'publish', 'all', $variant_args ),
				);
				$expected_query_var = array_values( array_map( 'sanitize_title_for_query', $config['slugs'] ) );
				$expected_ids       = array();
				foreach ( array_values( array_unique( $expected_query_var ) ) as $slug ) {
					if ( isset( $slug_to_id[ $slug ] ) ) {
						$expected_ids[] = $slug_to_id[ $slug ];
					}
				}

				$slug_order_expected[ $variant ] = $expected_ids;
				$slug_order_checks[ $variant ] = $query_ordering_family_is_valid( $buckets['ids'], $buckets['idParent'], $buckets['object'], $expected_ids, $ordering_parent_expected, $ordering_status_expected );
				$slug_order_query_var_checks[ $variant ] = $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_name__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_name__in'] ?? array() ) ) )
					&& $expected_query_var === array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_name__in'] ?? array() ) ) );
				$slug_order_keys['ids'][] = $buckets['ids']['cacheKey'];
				$slug_order_keys['idParent'][] = $buckets['idParent']['cacheKey'];
				$slug_order_keys['object'][] = $buckets['object']['cacheKey'];
				$slug_order_actual[ $variant ] = array(
					'slugsArg' => $config['slugs'],
					'expected' => $expected_ids,
					'queryVar' => array(
						'ids'      => array_values( array_map( 'strval', (array) ( $buckets['ids']['queryVars']['post_name__in'] ?? array() ) ) ),
						'idParent' => array_values( array_map( 'strval', (array) ( $buckets['idParent']['queryVars']['post_name__in'] ?? array() ) ) ),
						'object'   => array_values( array_map( 'strval', (array) ( $buckets['object']['queryVars']['post_name__in'] ?? array() ) ) ),
					),
					'keys'     => array(
						'ids'      => substr( md5( $buckets['ids']['cacheKey'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['cacheKey'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['cacheKey'] ), 0, 8 ),
					),
					'requests' => array(
						'ids'      => substr( md5( $buckets['ids']['request'] ), 0, 8 ),
						'idParent' => substr( md5( $buckets['idParent']['request'] ), 0, 8 ),
						'object'   => substr( md5( $buckets['object']['request'] ), 0, 8 ),
					),
					'ids'      => array(
						'ids'      => $buckets['ids']['ids'],
						'idParent' => $buckets['idParent']['ids'],
						'object'   => $buckets['object']['ids'],
					),
					'parents'  => array(
						'idParent' => $buckets['idParent']['parents'],
						'object'   => $buckets['object']['parents'],
					),
					'statuses' => $buckets['object']['statuses'],
				);
			}
			$slug_order_valid = ! in_array( false, $slug_order_checks, true )
				&& ! in_array( false, $slug_order_query_var_checks, true );
			$slug_order_keys_distinct_by_field = array();
			foreach ( $slug_order_keys as $field => $keys ) {
				$slug_order_keys_distinct_by_field[ $field ] = count( $keys ) === count( array_unique( $keys ) );
			}
			$slug_order_key_boundaries_hold = ! in_array( false, $slug_order_keys_distinct_by_field, true );
			$mutation_initial_after = \get_page_by_path( $mutation_initial_path, OBJECT, $mutation_lookup_types );
			$mutation_reparented_after = \get_page_by_path( $mutation_reparented_path, ARRAY_A, $mutation_lookup_types );
			$mutation_initial_cached_after_lookup = \wp_cache_get_salted( 'get_page_by_path:' . $mutation_initial_hash, 'post-queries', $mutation_last_changed_after );
			$mutation_reparented_cached_after_lookup = \wp_cache_get_salted( 'get_page_by_path:' . $mutation_reparented_hash, 'post-queries', $mutation_last_changed_after );

			self::collect_failure(
				$failures,
				$mutation_combined_bucket_after['cacheKey'] === $mutation_combined_id_parent_bucket_after['cacheKey']
					&& $mutation_combined_bucket_after['cacheKey'] === $mutation_combined_object_bucket_after['cacheKey']
					&& $mutation_combined_after_expected === $mutation_combined_id_parent_bucket_after['ids']
					&& $mutation_combined_after_expected === $mutation_combined_object_bucket_after['ids']
					&& $mutation_combined_parent_expected === $mutation_combined_id_parent_bucket_after['parents']
					&& $mutation_combined_parent_expected === $mutation_combined_object_bucket_after['parents']
					&& $mutation_combined_status_expected === $mutation_combined_object_bucket_after['statuses']
					&& array_fill_keys( $mutation_combined_after_expected, 'WP_Post' ) === $mutation_combined_object_bucket_after['classes']
					&& $query_bucket_has_ids( $mutation_combined_id_parent_bucket_after, $mutation_combined_after_expected )
					&& $query_bucket_has_ids( $mutation_combined_object_bucket_after, $mutation_combined_after_expected ),
				'WP_Query reuses generated custom hierarchical parent/status cache across ID, ID-parent, and object result shapes',
				array(
					'checks'         => array(
						'keyIdParent' => $mutation_combined_bucket_after['cacheKey'] === $mutation_combined_id_parent_bucket_after['cacheKey'],
						'keyObject'   => $mutation_combined_bucket_after['cacheKey'] === $mutation_combined_object_bucket_after['cacheKey'],
						'idsParent'   => $mutation_combined_after_expected === $mutation_combined_id_parent_bucket_after['ids'],
						'idsObject'   => $mutation_combined_after_expected === $mutation_combined_object_bucket_after['ids'],
						'mapParent'   => $mutation_combined_parent_expected === $mutation_combined_id_parent_bucket_after['parents'],
						'mapObject'   => $mutation_combined_parent_expected === $mutation_combined_object_bucket_after['parents'],
						'statuses'    => $mutation_combined_status_expected === $mutation_combined_object_bucket_after['statuses'],
						'classes'     => array_fill_keys( $mutation_combined_after_expected, 'WP_Post' ) === $mutation_combined_object_bucket_after['classes'],
						'cacheParent' => $query_bucket_has_ids( $mutation_combined_id_parent_bucket_after, $mutation_combined_after_expected ),
						'cacheObject' => $query_bucket_has_ids( $mutation_combined_object_bucket_after, $mutation_combined_after_expected ),
					),
					'hashes'         => array(
						'keyIds'      => substr( md5( $mutation_combined_bucket_after['cacheKey'] ), 0, 8 ),
						'keyParent'   => substr( md5( $mutation_combined_id_parent_bucket_after['cacheKey'] ), 0, 8 ),
						'keyObject'   => substr( md5( $mutation_combined_object_bucket_after['cacheKey'] ), 0, 8 ),
						'reqIds'      => substr( md5( $mutation_combined_bucket_after['request'] ), 0, 8 ),
						'reqParent'   => substr( md5( $mutation_combined_id_parent_bucket_after['request'] ), 0, 8 ),
						'reqObject'   => substr( md5( $mutation_combined_object_bucket_after['request'] ), 0, 8 ),
					),
					'expectedIds'    => $mutation_combined_after_expected,
					'idParentIds'    => $mutation_combined_id_parent_bucket_after['ids'],
					'objectIds'      => $mutation_combined_object_bucket_after['ids'],
					'expectedParent' => $mutation_combined_parent_expected,
					'idParentMap'    => $mutation_combined_id_parent_bucket_after['parents'],
					'objectParents'  => $mutation_combined_object_bucket_after['parents'],
					'expectedStatus' => $mutation_combined_status_expected,
					'objectStatuses' => $mutation_combined_object_bucket_after['statuses'],
					'objectClasses'  => $mutation_combined_object_bucket_after['classes'],
				)
			);

			self::collect_failure(
				$failures,
				$ordering_id_family_valid
					&& $ordering_parent_in_family_valid
					&& $ordering_keys_disjoint,
				'WP_Query preserves generated custom hierarchical parent/status cache payloads when selected-field ordering changes ID order',
				array(
					'checks'      => array(
						'idFamily'     => $ordering_id_family_valid,
						'parentFamily' => $ordering_parent_in_family_valid,
						'keysDisjoint' => $ordering_keys_disjoint,
					),
					'keysOverlap' => array_values( array_intersect( $ordering_id_keys, $ordering_parent_in_keys ) ),
					'idOrdered'   => array(
						'expectedIds' => $ordering_id_expected,
						'keys'        => array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $ordering_id_keys ),
						'requests'    => array(
							'ids'      => substr( md5( $ordering_id_ids_bucket['request'] ), 0, 8 ),
							'idParent' => substr( md5( $ordering_id_id_parent_bucket['request'] ), 0, 8 ),
							'object'   => substr( md5( $ordering_id_object_bucket['request'] ), 0, 8 ),
						),
						'ids'         => array(
							'ids'      => $ordering_id_ids_bucket['ids'],
							'idParent' => $ordering_id_id_parent_bucket['ids'],
							'object'   => $ordering_id_object_bucket['ids'],
						),
						'parents'     => array(
							'expected' => $ordering_parent_expected,
							'idParent' => $ordering_id_id_parent_bucket['parents'],
							'object'   => $ordering_id_object_bucket['parents'],
						),
					),
					'parentOrdered' => array(
						'expectedIds' => $ordering_parent_in_expected,
						'keys'        => array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $ordering_parent_in_keys ),
						'requests'    => array(
							'ids'      => substr( md5( $ordering_parent_in_ids_bucket['request'] ), 0, 8 ),
							'idParent' => substr( md5( $ordering_parent_in_id_parent_bucket['request'] ), 0, 8 ),
							'object'   => substr( md5( $ordering_parent_in_object_bucket['request'] ), 0, 8 ),
						),
						'ids'         => array(
							'ids'      => $ordering_parent_in_ids_bucket['ids'],
							'idParent' => $ordering_parent_in_id_parent_bucket['ids'],
							'object'   => $ordering_parent_in_object_bucket['ids'],
						),
						'parents'     => array(
							'expected' => $ordering_parent_expected,
							'idParent' => $ordering_parent_in_id_parent_bucket['parents'],
							'object'   => $ordering_parent_in_object_bucket['parents'],
						),
					),
					'expectedStatuses' => $ordering_status_expected,
					'statuses'         => array(
						'idOrdered'     => $ordering_id_object_bucket['statuses'],
						'parentOrdered' => $ordering_parent_in_object_bucket['statuses'],
					),
				)
			);

			self::collect_failure(
				$failures,
				$normalized_status_valid
					&& $normalized_status_keys_shared,
				'WP_Query normalizes generated custom hierarchical post status cache keys across duplicate and reversed status arrays',
				array(
					'checks'           => array(
						'variantsValid'           => $normalized_status_checks,
						'queryVarsSortedUnique'   => $normalized_status_query_var_checks,
						'keysSharedByField'       => $normalized_status_keys_shared_by_field,
					),
					'expectedIds'      => $normalized_status_expected,
					'expectedParents'  => $normalized_status_parent_expected,
					'expectedStatuses' => $normalized_status_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$normalized_status_keys
					),
					'variants'         => $normalized_status_actual,
				)
			);

			self::collect_failure(
				$failures,
				$normalized_type_valid
					&& $normalized_type_keys_shared,
				'WP_Query normalizes generated custom hierarchical post type cache keys across duplicate and reversed post type arrays',
				array(
					'checks'           => array(
						'variantsValid'         => $normalized_type_checks,
						'queryVarsSortedUnique' => $normalized_type_query_var_checks,
						'keysSharedByField'     => $normalized_type_keys_shared_by_field,
					),
					'postTypes'        => array(
						$pretty_type,
						$query_type,
					),
					'expectedIds'      => $normalized_type_expected,
					'expectedParents'  => $normalized_type_parent_expected,
					'expectedStatuses' => $normalized_type_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$normalized_type_keys
					),
					'variants'         => $normalized_type_actual,
				)
			);

			self::collect_failure(
				$failures,
				$normalized_parent_filter_valid
					&& $normalized_parent_filter_keys_shared,
				'WP_Query normalizes generated custom hierarchical parent filter cache keys across duplicate and reversed parent arrays',
				array(
					'checks'           => array(
						'variantsValid'     => $normalized_parent_filter_checks,
						'keysSharedByField' => $normalized_parent_filter_keys_shared_by_field,
					),
					'expectedIds'      => $ordering_id_expected,
					'expectedParents'  => $ordering_parent_expected,
					'expectedStatuses' => $ordering_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$normalized_parent_filter_keys
					),
					'variants'         => $normalized_parent_filter_actual,
				)
			);

			self::collect_failure(
				$failures,
				$parent_order_valid
					&& $parent_order_key_boundaries_hold,
				'WP_Query preserves generated custom hierarchical parent inclusion ordering cache-key boundaries across duplicate and reversed parent arrays',
				array(
					'checks'            => array(
						'variantsValid'               => $parent_order_checks,
						'queryVarsPreserveRawParents' => $parent_order_query_var_checks,
						'rawOrderKeysDistinctByField' => $parent_order_keys_distinct_by_field,
					),
					'expectedByVariant' => $parent_order_expected,
					'expectedParents'   => $ordering_parent_expected,
					'expectedStatuses'  => $ordering_status_expected,
					'uniqueKeyHashes'   => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$parent_order_keys
					),
					'variants'          => $parent_order_actual,
				)
			);

			self::collect_failure(
				$failures,
				$included_excluded_parent_valid
					&& $included_excluded_parent_requests_shared
					&& $included_excluded_parent_key_boundaries_hold,
				'WP_Query lets generated custom hierarchical parent inclusion take precedence over overlapping parent exclusions across selected fields',
				array(
					'checks'              => array(
						'variantsValid'             => $included_excluded_parent_checks,
						'queryVarsPreserveRawArrays' => $included_excluded_parent_query_var_checks,
						'sqlUsesOnlyParentInclude'   => $included_excluded_parent_sql_checks,
						'requestsSharedByField'      => $included_excluded_parent_requests_shared_by_field,
						'ignoredExcludeKeysDistinct' => $included_excluded_parent_keys_distinct_by_field,
					),
					'includedParents'     => array(
						$query_parent_id,
						$pretty_parent_id,
					),
					'expectedIds'         => $ordering_id_expected,
					'expectedParents'     => $ordering_parent_expected,
					'expectedStatuses'    => $ordering_status_expected,
					'uniqueRequestHashes' => array_map(
						static fn ( array $requests ): array => array_values( array_unique( array_map( static fn ( string $request ): string => substr( md5( $request ), 0, 8 ), $requests ) ) ),
						$included_excluded_parent_requests
					),
					'uniqueKeyHashes'     => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$included_excluded_parent_keys
					),
					'variants'            => $included_excluded_parent_actual,
				)
			);

			self::collect_failure(
				$failures,
				$excluded_parent_valid
					&& $excluded_parent_key_boundaries_hold,
				'WP_Query preserves generated custom hierarchical parent exclusion cache-key boundaries across duplicate and reversed parent arrays',
				array(
					'checks'           => array(
						'variantsValid'                 => $excluded_parent_checks,
						'queryVarsSortedWithDuplicates' => $excluded_parent_query_var_checks,
						'canonicalReversedKeysShared'   => $excluded_parent_canonical_reversed_keys_shared,
						'duplicateKeysDistinct'         => $excluded_parent_duplicate_keys_distinct,
					),
					'expectedIds'      => $excluded_parent_expected,
					'expectedParents'  => $excluded_parent_map_expected,
					'expectedStatuses' => $excluded_parent_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$excluded_parent_keys
					),
					'variants'         => $excluded_parent_actual,
				)
			);

			self::collect_failure(
				$failures,
				$excluded_post_valid
					&& $excluded_post_key_boundaries_hold,
				'WP_Query preserves generated custom hierarchical post exclusion cache-key boundaries across duplicate and reversed post arrays',
				array(
					'checks'           => array(
						'variantsValid'                 => $excluded_post_checks,
						'queryVarsSortedWithDuplicates' => $excluded_post_query_var_checks,
						'canonicalReversedKeysShared'   => $excluded_post_canonical_reversed_keys_shared,
						'duplicateKeysDistinct'         => $excluded_post_duplicate_keys_distinct,
					),
					'excludedIds'      => array(
						$pretty_child_id,
						$pretty_cross_type_child_id,
					),
					'expectedIds'      => $excluded_parent_expected,
					'expectedParents'  => $excluded_parent_map_expected,
					'expectedStatuses' => $excluded_parent_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$excluded_post_keys
					),
					'variants'         => $excluded_post_actual,
				)
			);

			self::collect_failure(
				$failures,
				$included_post_valid
					&& $included_post_keys_shared,
				'WP_Query normalizes generated custom hierarchical post inclusion cache keys across duplicate and reversed post arrays',
				array(
					'checks'           => array(
						'variantsValid'           => $included_post_checks,
						'queryVarsPreserveRawIds' => $included_post_query_var_checks,
						'keysSharedByField'       => $included_post_keys_shared_by_field,
					),
					'includedIds'      => array(
						$pretty_child_id,
						$pretty_cross_type_child_id,
					),
					'expectedIds'      => $ordering_id_expected,
					'expectedParents'  => $ordering_parent_expected,
					'expectedStatuses' => $ordering_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$included_post_keys
					),
					'variants'         => $included_post_actual,
				)
			);

			self::collect_failure(
				$failures,
				$included_excluded_post_valid
					&& $included_excluded_post_requests_shared
					&& $included_excluded_post_key_boundaries_hold,
				'WP_Query lets generated custom hierarchical post inclusion take precedence over overlapping post exclusions across selected fields',
				array(
					'checks'              => array(
						'variantsValid'             => $included_excluded_post_checks,
						'queryVarsPreserveRawArrays' => $included_excluded_post_query_var_checks,
						'requestsSharedByField'      => $included_excluded_post_requests_shared_by_field,
						'ignoredExcludeKeysDistinct' => $included_excluded_post_keys_distinct_by_field,
					),
					'includedIds'         => array(
						$pretty_child_id,
						$pretty_cross_type_child_id,
					),
					'expectedIds'         => $ordering_id_expected,
					'expectedParents'     => $ordering_parent_expected,
					'expectedStatuses'    => $ordering_status_expected,
					'uniqueRequestHashes' => array_map(
						static fn ( array $requests ): array => array_values( array_unique( array_map( static fn ( string $request ): string => substr( md5( $request ), 0, 8 ), $requests ) ) ),
						$included_excluded_post_requests
					),
					'uniqueKeyHashes'     => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$included_excluded_post_keys
					),
					'variants'            => $included_excluded_post_actual,
				)
			);

			self::collect_failure(
				$failures,
				$included_post_order_valid
					&& $included_post_order_key_boundaries_hold,
				'WP_Query preserves generated custom hierarchical post inclusion ordering cache-key boundaries across duplicate and reversed post arrays',
				array(
					'checks'            => array(
						'variantsValid'               => $included_post_order_checks,
						'queryVarsPreserveRawIds'     => $included_post_order_query_var_checks,
						'rawOrderKeysDistinctByField' => $included_post_order_keys_distinct_by_field,
					),
					'includedIds'       => array(
						$pretty_child_id,
						$pretty_cross_type_child_id,
					),
					'expectedByVariant' => $included_post_order_expected,
					'expectedParents'   => $ordering_parent_expected,
					'expectedStatuses'  => $ordering_status_expected,
					'uniqueKeyHashes'   => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$included_post_order_keys
					),
					'variants'          => $included_post_order_actual,
				)
			);

			self::collect_failure(
				$failures,
				$excluded_author_valid
					&& $excluded_author_keys_shared,
				'WP_Query normalizes generated custom hierarchical author exclusion cache keys across duplicate and reversed author arrays',
				array(
					'checks'           => array(
						'variantsValid'               => $excluded_author_checks,
						'queryVarsSortedUnique'       => $excluded_author_query_var_checks,
						'keysSharedByField'           => $excluded_author_keys_shared_by_field,
					),
					'excludedAuthors'  => array(
						$author_excluded_a_id,
						$author_excluded_b_id,
					),
					'excludedIds'      => array(
						$pretty_child_id,
						$pretty_cross_type_child_id,
					),
					'expectedIds'      => $excluded_parent_expected,
					'expectedParents'  => $excluded_parent_map_expected,
					'expectedStatuses' => $excluded_parent_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$excluded_author_keys
					),
					'variants'         => $excluded_author_actual,
				)
			);

			self::collect_failure(
				$failures,
				$included_author_valid
					&& $included_author_keys_shared,
				'WP_Query normalizes generated custom hierarchical author inclusion cache keys across duplicate and reversed author arrays',
				array(
					'checks'           => array(
						'variantsValid'         => $included_author_checks,
						'queryVarsSortedUnique' => $included_author_query_var_checks,
						'keysSharedByField'     => $included_author_keys_shared_by_field,
					),
					'includedAuthors'  => array(
						$author_excluded_a_id,
						$author_excluded_b_id,
					),
					'expectedIds'      => $ordering_id_expected,
					'expectedParents'  => $ordering_parent_expected,
					'expectedStatuses' => $ordering_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$included_author_keys
					),
					'variants'         => $included_author_actual,
				)
			);

			self::collect_failure(
				$failures,
				$included_excluded_author_valid
					&& $included_excluded_author_requests_shared
					&& $included_excluded_author_key_boundaries_hold,
				'WP_Query lets generated custom hierarchical author exclusions take precedence over overlapping author inclusions across selected fields',
				array(
					'checks'              => array(
						'variantsValid'             => $included_excluded_author_checks,
						'queryVarsPreserveRawInclude' => $included_excluded_author_query_var_checks,
						'sqlUsesOnlyAuthorExclude'   => $included_excluded_author_sql_checks,
						'requestsSharedByField'      => $included_excluded_author_requests_shared_by_field,
						'ignoredIncludeKeysDistinct' => $included_excluded_author_keys_distinct_by_field,
					),
					'includedAuthors'     => array(
						$author_excluded_a_id,
						$author_excluded_b_id,
					),
					'expectedIds'         => $excluded_parent_expected,
					'expectedParents'     => $excluded_parent_map_expected,
					'expectedStatuses'    => $excluded_parent_status_expected,
					'uniqueRequestHashes' => array_map(
						static fn ( array $requests ): array => array_values( array_unique( array_map( static fn ( string $request ): string => substr( md5( $request ), 0, 8 ), $requests ) ) ),
						$included_excluded_author_requests
					),
					'uniqueKeyHashes'     => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$included_excluded_author_keys
					),
					'variants'            => $included_excluded_author_actual,
				)
			);

			self::collect_failure(
				$failures,
				$author_name_valid
					&& $author_name_requests_shared
					&& $author_name_keys_shared,
				'WP_Query normalizes generated custom hierarchical author_name slug paths across selected fields',
				array(
					'checks'              => array(
						'variantsValid'                => $author_name_checks,
						'queryVarsNormalizeAuthorName' => $author_name_query_var_checks,
						'sqlUsesResolvedAuthor'        => $author_name_sql_checks,
						'requestsSharedByField'        => $author_name_requests_shared_by_field,
						'keysSharedByField'            => $author_name_keys_shared_by_field,
					),
					'authorNameSlug'      => $author_name_slug,
					'expectedAuthorName'  => sanitize_title_for_query( $author_name_slug ),
					'authorId'            => $author_excluded_a_id,
					'expectedIds'         => $author_name_expected,
					'expectedParents'     => $author_name_parent_expected,
					'expectedStatuses'    => $author_name_status_expected,
					'uniqueRequestHashes' => array_map(
						static fn ( array $requests ): array => array_values( array_unique( array_map( static fn ( string $request ): string => substr( md5( $request ), 0, 8 ), $requests ) ) ),
						$author_name_requests
					),
					'uniqueKeyHashes'     => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$author_name_keys
					),
					'variants'            => $author_name_actual,
				)
			);

			self::collect_failure(
				$failures,
				$legacy_author_valid
					&& $legacy_author_keys_shared,
				'WP_Query normalizes generated custom hierarchical legacy author query-var cache keys across duplicate and reversed mixed author lists',
				array(
					'checks'           => array(
						'variantsValid'               => $legacy_author_checks,
						'queryVarsNormalized'         => $legacy_author_query_var_checks,
						'keysSharedByField'           => $legacy_author_keys_shared_by_field,
					),
					'includedAuthors'  => array(
						$author_excluded_a_id,
					),
					'excludedAuthors'  => array(
						$author_excluded_b_id,
					),
					'notInPrecedenceSentinel' => array(
						'id'     => $pretty_mixed_leaf_id,
						'author' => 0,
						'parent' => $query_mixed_middle_id,
					),
					'expectedIds'      => $legacy_author_expected,
					'expectedParents'  => $legacy_author_parent_expected,
					'expectedStatuses' => $legacy_author_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$legacy_author_keys
					),
					'variants'         => $legacy_author_actual,
				)
			);

			self::collect_failure(
				$failures,
				$normalized_slug_valid
					&& $normalized_slug_keys_shared,
				'WP_Query normalizes generated custom hierarchical slug filter cache keys across duplicate and reversed post_name arrays',
				array(
					'checks'           => array(
						'variantsValid'                 => $normalized_slug_checks,
						'queryVarsSanitizedWithOrder'   => $normalized_slug_query_var_checks,
						'keysSharedByField'             => $normalized_slug_keys_shared_by_field,
					),
					'expectedIds'      => $ordering_id_expected,
					'expectedParents'  => $ordering_parent_expected,
					'expectedStatuses' => $ordering_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$normalized_slug_keys
					),
					'variants'         => $normalized_slug_actual,
				)
			);

			self::collect_failure(
				$failures,
				$slug_order_valid
					&& $slug_order_key_boundaries_hold,
				'WP_Query preserves generated custom hierarchical slug inclusion ordering cache-key boundaries across duplicate and reversed post_name arrays',
				array(
					'checks'           => array(
						'variantsValid'               => $slug_order_checks,
						'queryVarsSanitizedWithOrder' => $slug_order_query_var_checks,
						'rawOrderKeysDistinctByField' => $slug_order_keys_distinct_by_field,
					),
					'expectedByVariant' => $slug_order_expected,
					'expectedParents'  => $ordering_parent_expected,
					'expectedStatuses' => $ordering_status_expected,
					'uniqueKeyHashes'  => array_map(
						static fn ( array $keys ): array => array_values( array_unique( array_map( static fn ( string $key ): string => substr( md5( $key ), 0, 8 ), $keys ) ) ),
						$slug_order_keys
					),
					'variants'         => $slug_order_actual,
				)
			);

			self::collect_failure(
				$failures,
				$mutation_initial_lookup instanceof \WP_Post
					&& $mutation_child_id === (int) $mutation_initial_lookup->ID
					&& null === $mutation_reparented_before
					&& array() === $mutation_private_bucket_before['ids']
					&& array( $mutation_pending_child_id ) === $mutation_pending_bucket_before['ids']
					&& array( $mutation_pending_child_id ) === $mutation_combined_bucket_before['ids']
					&& '' !== $mutation_private_bucket_before['cacheKey']
					&& '' !== $mutation_pending_bucket_before['cacheKey']
					&& '' !== $mutation_combined_bucket_before['cacheKey']
					&& $mutation_private_bucket_before['cacheKey'] !== $mutation_pending_bucket_before['cacheKey']
					&& $mutation_private_bucket_before['cacheKey'] !== $mutation_combined_bucket_before['cacheKey']
					&& $mutation_pending_bucket_before['cacheKey'] !== $mutation_combined_bucket_before['cacheKey']
					&& $query_bucket_has_ids( $mutation_private_bucket_before, array() )
					&& $query_bucket_has_ids( $mutation_pending_bucket_before, array( $mutation_pending_child_id ) )
					&& $query_bucket_has_ids( $mutation_combined_bucket_before, array( $mutation_pending_child_id ) )
					&& array( $mutation_child_id ) === array_values( array_map( 'intval', $mutation_old_parent_publish_before ) )
					&& array() === array_values( array_map( 'intval', $mutation_new_parent_private_before ) )
					&& $mutation_parent_a_id === (int) $mutation_parent_cache_before
					&& $mutation_child_id === (int) $mutation_initial_cached
					&& 0 === (int) $mutation_reparented_cached_before
					&& $mutation_child_id === (int) $mutation_update_result
					&& $mutation_after instanceof \WP_Post
					&& $mutation_parent_b_id === (int) $mutation_after->post_parent
					&& 'private' === $mutation_after->post_status
					&& false === $mutation_parent_cache_after_update
					&& $mutation_last_changed_after !== $mutation_last_changed_before
					&& false === $mutation_initial_cache_after_update
					&& false === $mutation_reparented_cache_after_update
					&& false === $mutation_private_bucket_stale_after_update
					&& false === $mutation_pending_bucket_stale_after_update
					&& false === $mutation_combined_bucket_stale_after_update
					&& array() === array_values( array_map( 'intval', $mutation_old_parent_publish_after ) )
					&& array( $mutation_child_id ) === array_values( array_map( 'intval', $mutation_new_parent_private_after ) )
					&& array( $mutation_child_id ) === $mutation_private_bucket_after['ids']
					&& array( $mutation_pending_child_id ) === $mutation_pending_bucket_after['ids']
					&& $mutation_combined_after_expected === $mutation_combined_bucket_after['ids']
					&& $mutation_private_bucket_before['cacheKey'] === $mutation_private_bucket_after['cacheKey']
					&& $mutation_pending_bucket_before['cacheKey'] === $mutation_pending_bucket_after['cacheKey']
					&& $mutation_combined_bucket_before['cacheKey'] === $mutation_combined_bucket_after['cacheKey']
					&& $query_bucket_has_ids( $mutation_private_bucket_after, array( $mutation_child_id ) )
					&& $query_bucket_has_ids( $mutation_pending_bucket_after, array( $mutation_pending_child_id ) )
					&& $query_bucket_has_ids( $mutation_combined_bucket_after, $mutation_combined_after_expected )
					&& null === $mutation_initial_after
					&& is_array( $mutation_reparented_after )
					&& $mutation_child_id === (int) ( $mutation_reparented_after['ID'] ?? 0 )
					&& 0 === (int) $mutation_initial_cached_after_lookup
					&& $mutation_child_id === (int) $mutation_reparented_cached_after_lookup,
				'get_page_by_path invalidates generated custom hierarchical parent/status mutation cache boundaries',
				array(
					'lookupTypes'              => $mutation_lookup_types,
					'initialPath'              => $mutation_initial_path,
					'reparentedPath'           => $mutation_reparented_path,
					'initialLookup'            => self::post_summary( $mutation_initial_lookup ),
					'reparentedBefore'         => self::post_summary( $mutation_reparented_before ),
					'privateBucketBefore'      => $mutation_private_bucket_before,
					'pendingBucketBefore'      => $mutation_pending_bucket_before,
					'combinedBucketBefore'     => $mutation_combined_bucket_before,
					'oldParentPublishBefore'   => $mutation_old_parent_publish_before,
					'newParentPrivateBefore'   => $mutation_new_parent_private_before,
					'parentCacheBefore'        => $mutation_parent_cache_before,
					'initialCached'            => $mutation_initial_cached,
					'reparentedCachedBefore'   => $mutation_reparented_cached_before,
					'updateResult'             => self::error_summary( $mutation_update_result ),
					'afterMutation'            => self::post_summary( $mutation_after ),
					'parentCacheAfterUpdate'   => $mutation_parent_cache_after_update,
					'lastChangedBefore'        => $mutation_last_changed_before,
					'lastChangedAfter'         => $mutation_last_changed_after,
					'initialCacheAfterUpdate'  => $mutation_initial_cache_after_update,
					'reparentedCacheAfterUpdate' => $mutation_reparented_cache_after_update,
					'privateBucketStaleAfterUpdate' => $mutation_private_bucket_stale_after_update,
					'pendingBucketStaleAfterUpdate' => $mutation_pending_bucket_stale_after_update,
					'combinedBucketStaleAfterUpdate' => $mutation_combined_bucket_stale_after_update,
					'oldParentPublishAfter'    => $mutation_old_parent_publish_after,
					'newParentPrivateAfter'    => $mutation_new_parent_private_after,
					'privateBucketAfter'       => $mutation_private_bucket_after,
					'pendingBucketAfter'       => $mutation_pending_bucket_after,
					'combinedBucketAfter'      => $mutation_combined_bucket_after,
					'initialAfter'             => self::post_summary( $mutation_initial_after ),
					'reparentedAfter'          => $mutation_reparented_after,
					'initialCachedAfterLookup' => $mutation_initial_cached_after_lookup,
					'reparentedCachedAfterLookup' => $mutation_reparented_cached_after_lookup,
				)
			);

			self::collect_failure(
				$failures,
				$pretty_private_child_uri === \get_page_uri( $pretty_private_child_id )
					&& $pretty_trash_child_uri === \get_page_uri( $pretty_trash_child_id )
					&& $query_private_child_uri === \get_page_uri( $query_private_child_id )
					&& $query_trash_child_uri === \get_page_uri( $query_trash_child_id )
					&& ! \is_post_publicly_viewable( $pretty_private_parent_id )
					&& ! \is_post_publicly_viewable( $pretty_trash_parent_id )
					&& ! \is_post_publicly_viewable( $query_private_parent_id )
					&& ! \is_post_publicly_viewable( $query_trash_parent_id )
					&& \is_post_publicly_viewable( $pretty_private_child_id )
					&& \is_post_publicly_viewable( $pretty_trash_child_id )
					&& \is_post_publicly_viewable( $query_private_child_id )
					&& \is_post_publicly_viewable( $query_trash_child_id ),
				'get_page_uri follows generated custom hierarchical private and trashed ancestor paths while visibility remains post-local',
				array(
					'prettyPrivate' => array(
						'uri'      => \get_page_uri( $pretty_private_child_id ),
						'expected' => $pretty_private_child_uri,
						'parent'   => self::post_summary( \get_post( $pretty_private_parent_id ) ),
						'child'    => self::post_summary( \get_post( $pretty_private_child_id ) ),
					),
					'prettyTrash'   => array(
						'uri'      => \get_page_uri( $pretty_trash_child_id ),
						'expected' => $pretty_trash_child_uri,
						'parent'   => self::post_summary( \get_post( $pretty_trash_parent_id ) ),
						'child'    => self::post_summary( \get_post( $pretty_trash_child_id ) ),
					),
					'queryPrivate'  => array(
						'uri'      => \get_page_uri( $query_private_child_id ),
						'expected' => $query_private_child_uri,
						'parent'   => self::post_summary( \get_post( $query_private_parent_id ) ),
						'child'    => self::post_summary( \get_post( $query_private_child_id ) ),
					),
					'queryTrash'    => array(
						'uri'      => \get_page_uri( $query_trash_child_id ),
						'expected' => $query_trash_child_uri,
						'parent'   => self::post_summary( \get_post( $query_trash_parent_id ) ),
						'child'    => self::post_summary( \get_post( $query_trash_child_id ) ),
					),
				)
			);

			self::collect_failure(
				$failures,
				$pretty_missing_parent_slug === \get_page_uri( $pretty_missing_parent_id )
					&& $pretty_self_parent_slug === \get_page_uri( $pretty_self_parent_id )
					&& $query_missing_parent_slug === \get_page_uri( $query_missing_parent_id )
					&& $query_self_parent_slug === \get_page_uri( $query_self_parent_id )
					&& $missing_parent_id === (int) ( \get_post( $pretty_missing_parent_id )->post_parent ?? 0 )
					&& $pretty_self_parent_id === (int) ( \get_post( $pretty_self_parent_id )->post_parent ?? 0 )
					&& $missing_parent_id === (int) ( \get_post( $query_missing_parent_id )->post_parent ?? 0 )
					&& $query_self_parent_id === (int) ( \get_post( $query_self_parent_id )->post_parent ?? 0 ),
				'get_page_uri fails closed for generated custom hierarchical missing-parent and self-parent permalink paths',
				array(
					'prettyMissing' => array(
						'uri'  => \get_page_uri( $pretty_missing_parent_id ),
						'post' => self::post_summary( \get_post( $pretty_missing_parent_id ) ),
					),
					'prettySelf'    => array(
						'uri'  => \get_page_uri( $pretty_self_parent_id ),
						'post' => self::post_summary( \get_post( $pretty_self_parent_id ) ),
					),
					'queryMissing'  => array(
						'uri'  => \get_page_uri( $query_missing_parent_id ),
						'post' => self::post_summary( \get_post( $query_missing_parent_id ) ),
					),
					'querySelf'     => array(
						'uri'  => \get_page_uri( $query_self_parent_id ),
						'post' => self::post_summary( \get_post( $query_self_parent_id ) ),
					),
				)
			);
		} finally {
			\remove_filter( 'post_type_link', $link_filter, 10 );
			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );

			foreach ( array_reverse( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}

			if ( function_exists( 'wp_delete_user' ) ) {
				foreach ( array_reverse( array_unique( array_map( 'intval', $user_ids ) ) ) as $user_id ) {
					if ( $user_id > 0 ) {
						\wp_delete_user( $user_id );
					}
				}
			}

			foreach ( $post_types as $post_type ) {
				if ( function_exists( 'unregister_post_type' ) && \post_type_exists( $post_type ) ) {
					\unregister_post_type( $post_type );
				}
				if ( $previous_post_types[ $post_type ]['exists'] ) {
					$GLOBALS['wp_post_types'][ $post_type ] = $previous_post_types[ $post_type ]['value'];
				} else {
					unset( $GLOBALS['wp_post_types'][ $post_type ] );
				}
			}
			if ( null !== $previous_query_vars && isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' ) ) {
				$GLOBALS['wp']->public_query_vars = $previous_query_vars;
			}
			if ( $previous_wp_set ) {
				$GLOBALS['wp'] = $previous_wp;
			} else {
				unset( $GLOBALS['wp'] );
			}

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			$counts_after_cleanup = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();
		}

		$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
			&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
		$wp_restored = $previous_wp_set === array_key_exists( 'wp', $GLOBALS )
			&& ( ! $previous_wp_set || $previous_wp === $GLOBALS['wp'] );
		$post_types_restored = true;
		foreach ( $post_types as $post_type ) {
			$post_types_restored = $post_types_restored
				&& $previous_post_types[ $post_type ]['exists'] === isset( $GLOBALS['wp_post_types'][ $post_type ] )
				&& ( ! $previous_post_types[ $post_type ]['exists'] || $previous_post_types[ $post_type ]['value'] === $GLOBALS['wp_post_types'][ $post_type ] );
		}
		$query_vars_restored = null === $previous_query_vars
			|| ! ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' ) )
			|| $previous_query_vars === $GLOBALS['wp']->public_query_vars;
		$filters_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
			&& false === \has_filter( 'post_type_link', $link_filter );
		$counts_restored = array() !== $counts_before && $counts_before === $counts_after_cleanup;

		self::collect_failure(
			$failures,
			$rewrite_restored && $wp_restored && $post_types_restored && $query_vars_restored && $filters_restored && $counts_restored,
			'custom post type single permalink fallback row restores rewrite, wp, generated post types, query vars, filters, and content counts',
			array(
				'rewriteRestored'   => $rewrite_restored,
				'wpRestored'        => $wp_restored,
				'postTypesRestored' => $post_types_restored,
				'queryVarsRestored' => $query_vars_restored,
				'filtersRestored'   => $filters_restored,
				'countsBefore'      => $counts_before,
				'countsAfter'       => $counts_after_cleanup,
				'postIds'           => $post_ids,
				'userIds'           => $user_ids,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.custom-post-type-single-permalink-fallbacks',
			array() === $failures,
			array(
				'case'      => self::case_summary( $case ),
				'postTypes' => $post_types,
				'postIds'   => $post_ids,
				'matrix'    => $matrix_observed,
				'failures'  => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_admin_post_save_orchestration( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures         = array();
		$events           = array();
		$taxonomy_events  = array();
		$hooks            = array();
		$cap_filter       = null;
		$post_snapshot    = $_POST;
		$get_snapshot     = $_GET;
		$request_snapshot = $_REQUEST;
		$post_type        = $case['postType'];
		$taxonomy         = 'cf_admin_tax_' . substr( $case['token'], 0, 8 );
		$existing_key     = 'cf_admin_existing_' . $case['token'];
		$renamed_key      = 'cf_admin_renamed_' . $case['token'];
		$delete_key       = 'cf_admin_delete_' . $case['token'];
		$added_key        = 'cf_admin_added_' . $case['token'];
		$original_guid    = 'http://example.test/original-admin-save-' . $case['token'];
		$original_mime    = 'text/plain';
		$visibility       = $ctx->choice( array( 'public', 'password', 'private' ) );
		$expected_status  = 'private' === $visibility ? 'private' : $case['updatedStatus'];
		$expected_pass    = 'password' === $visibility && 'private' !== $expected_status ? $case['password'] : '';

		try {
			\register_post_type(
				$post_type,
				array(
					'public'       => true,
					'rewrite'      => false,
					'query_var'    => false,
					'show_ui'      => true,
					'supports'     => array( 'title', 'editor', 'excerpt', 'author', 'custom-fields' ),
					'taxonomies'   => array( $taxonomy ),
					'map_meta_cap' => true,
				)
			);

			\register_taxonomy(
				$taxonomy,
				$post_type,
				array(
					'public'               => false,
					'hierarchical'         => true,
					'show_ui'              => true,
					'show_in_quick_edit'   => true,
					'meta_box_sanitize_cb' => static function ( string $taxonomy_name, $terms ) use ( &$taxonomy_events ): array {
						$taxonomy_events[] = array(
							'taxonomy' => $taxonomy_name,
							'terms'    => array_values( (array) $terms ),
						);
						return array_map( 'intval', (array) $terms );
					},
				)
			);

			$user_id = self::insert_support_user( 'admin-save-' . $case['token'], 'admin-save-' . $case['token'] . '@example.test' );
			\wp_set_current_user( $user_id );
			$cap_filter = self::grant_all_caps_filter( $user_id );
			\add_filter( 'user_has_cap', $cap_filter, 10, 4 );

			$term    = \wp_insert_term( 'Admin Save ' . $case['token'], $taxonomy, array( 'slug' => 'admin-save-' . $case['token'] ) );
			$post_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => $post_type,
						'post_title'     => $case['title'],
						'post_content'   => $case['content'],
						'post_excerpt'   => $case['excerpt'],
						'post_status'    => 'draft',
						'post_author'    => $user_id,
						'post_name'      => $case['slug'] . '-admin-original',
						'post_password'  => 'original-password',
						'post_mime_type' => $original_mime,
						'comment_status' => 'open',
						'ping_status'    => 'closed',
						'guid'           => $original_guid,
					)
				),
				true,
				true
			);

			$existing_meta_id = is_int( $post_id ) ? \add_post_meta( $post_id, $existing_key, \wp_slash( $case['metaValue'] ) ) : false;
			$delete_meta_id   = is_int( $post_id ) ? \add_post_meta( $post_id, $delete_key, \wp_slash( $case['metaSecondValue'] ) ) : false;
			$fixture_post     = is_int( $post_id ) ? \get_post( $post_id ) : null;
			$fixture_raw      = is_int( $post_id ) ? \get_post( $post_id, ARRAY_A, 'raw' ) : array();
			$stored_mime      = $fixture_post instanceof \WP_Post ? $fixture_post->post_mime_type : $original_mime;
			$stored_guid      = is_array( $fixture_raw ) ? (string) ( $fixture_raw['guid'] ?? '' ) : $original_guid;

			self::collect_failure(
				$failures,
				is_int( $post_id )
					&& is_int( $existing_meta_id )
					&& is_int( $delete_meta_id )
					&& $fixture_post instanceof \WP_Post
					&& is_array( $term )
					&& isset( $term['term_id'] ),
				'admin save fixtures insert before edit_post orchestration',
				array(
					'postId'         => $post_id,
					'existingMetaId' => $existing_meta_id,
					'deleteMetaId'   => $delete_meta_id,
					'fixturePost'    => self::post_summary( $fixture_post ),
					'storedMime'     => $stored_mime,
					'storedGuid'     => $stored_guid,
					'term'           => $term,
				)
			);

			if ( ! is_int( $post_id ) || ! is_int( $existing_meta_id ) || ! is_int( $delete_meta_id ) || ! $fixture_post instanceof \WP_Post || ! is_array( $term ) || ! isset( $term['term_id'] ) ) {
				return $ctx->result(
					'content-lifecycle.posts.admin-save-orchestration',
					false,
					array(
						'case'     => self::case_summary( $case ),
						'failures' => array_slice( $failures, 0, 6 ),
					)
				);
			}

			$hooks = array_merge(
				self::install_post_hooks( $post_type, $events ),
				self::install_post_meta_hooks_for_keys( array( $existing_key, $renamed_key, $delete_key, $added_key ), $events )
			);

			$_POST = \wp_slash(
				array(
					'post_ID'        => $post_id,
					'post_type'      => 'attachment',
					'post_mime_type' => 'image/png',
					'post_title'     => $case['updatedTitle'],
					'content'        => $case['updatedContent'],
					'excerpt'        => $case['excerpt'] . ' admin save',
					'post_status'    => $case['updatedStatus'],
					'post_author'    => $user_id,
					'post_name'      => $case['slug'] . '-admin-saved',
					'post_password'  => $case['password'],
					'visibility'     => $visibility,
					'comment_status' => 'closed',
					'ping_status'    => 'open',
					'guid'           => 'http://example.test/poison-admin-save-' . $case['token'],
					'meta_input'     => array( 'cf_admin_poison_' . $case['token'] => 'should-not-persist' ),
					'file'           => 'poison-upload-field',
					'filter'         => 'poison-filter-field',
					'meta'           => array(
						$existing_meta_id => array(
							'key'   => $renamed_key,
							'value' => $case['metaUpdatedValue'],
						),
					),
					'deletemeta'     => array(
						$delete_meta_id => '1',
					),
					'tax_input'      => array(
						$taxonomy => array( (string) $term['term_id'] ),
					),
					'metakeyselect'  => '#NONE#',
					'metakeyinput'   => $added_key,
					'metavalue'      => $case['metaArrayValue']['text'],
				)
			);
			$_GET     = array();
			$_REQUEST = $_POST;

			$result       = \edit_post();
			$saved        = \get_post( $post_id );
			$raw          = \get_post( $post_id, ARRAY_A, 'raw' );
			$renamed_meta = \get_post_meta_by_id( $existing_meta_id );
			$deleted_meta = \get_post_meta_by_id( $delete_meta_id );
			$lock         = \get_post_meta( $post_id, '_edit_lock', true );
			$terms        = \wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

			self::collect_failure(
				$failures,
				$result === $post_id,
				'edit_post returns the original post ID',
				array( 'result' => $result, 'postId' => $post_id )
			);
			self::collect_failure(
				$failures,
				$saved instanceof \WP_Post
					&& $post_type === $saved->post_type
					&& $stored_mime === $saved->post_mime_type,
				'edit_post preserves the stored post type and MIME type',
				array(
					'post'         => self::post_summary( $saved ),
					'mime'         => $saved instanceof \WP_Post ? $saved->post_mime_type : null,
					'expectedMime' => $stored_mime,
				)
			);
			self::collect_failure(
				$failures,
				$saved instanceof \WP_Post
					&& $expected_status === $saved->post_status
					&& $expected_pass === $saved->post_password,
				'edit_post applies classic visibility status and password rules',
				array(
					'post'           => self::post_summary( $saved ),
					'password'       => $saved instanceof \WP_Post ? $saved->post_password : null,
					'visibility'     => $visibility,
					'expectedStatus' => $expected_status,
					'expectedPass'   => $expected_pass,
				)
			);
			self::collect_failure(
				$failures,
				$saved instanceof \WP_Post
					&& 'closed' === $saved->comment_status
					&& 'open' === $saved->ping_status,
				'edit_post maps classic discussion fields',
				array(
					'post'           => self::post_summary( $saved ),
					'commentStatus'  => $saved instanceof \WP_Post ? $saved->comment_status : null,
					'pingStatus'     => $saved instanceof \WP_Post ? $saved->ping_status : null,
				)
			);
			self::collect_failure(
				$failures,
				$stored_guid === ( $raw['guid'] ?? null ),
				'edit_post does not let guarded guid input overwrite storage',
				array(
					'rawGuid'        => $raw['guid'] ?? null,
					'expectedGuid'   => $stored_guid,
				)
			);
			self::collect_failure(
				$failures,
				$saved instanceof \WP_Post
					&& $case['updatedTitle'] === $saved->post_title
					&& $case['updatedContent'] === $saved->post_content
					&& $case['excerpt'] . ' admin save' === $saved->post_excerpt,
				'edit_post maps classic title, content, and excerpt fields',
				array(
					'title'           => $saved instanceof \WP_Post ? self::describe_string( $saved->post_title ) : null,
					'expectedTitle'   => self::describe_string( $case['updatedTitle'] ),
					'content'         => $saved instanceof \WP_Post ? self::describe_string( $saved->post_content ) : null,
					'expectedContent' => self::describe_string( $case['updatedContent'] ),
					'excerpt'         => $saved instanceof \WP_Post ? self::describe_string( $saved->post_excerpt ) : null,
					'expectedExcerpt' => self::describe_string( $case['excerpt'] . ' admin save' ),
				)
			);
			self::collect_failure(
				$failures,
				! isset( $_POST['filter'] )
					&& $post_type === ( $_POST['post_type'] ?? null )
					&& $stored_mime === ( $_POST['post_mime_type'] ?? null ),
				'edit_post normalizes global admin request internals',
				array(
					'hasFilter'    => isset( $_POST['filter'] ),
					'postType'     => $_POST['post_type'] ?? null,
					'postMimeType' => $_POST['post_mime_type'] ?? null,
					'storedMime'   => $stored_mime,
				)
			);
			self::collect_failure(
				$failures,
				$renamed_meta
					&& (int) $renamed_meta->post_id === $post_id
					&& $renamed_key === $renamed_meta->meta_key
					&& $case['metaUpdatedValue'] === $renamed_meta->meta_value
					&& false === \metadata_exists( 'post', $post_id, $existing_key )
					&& false === $deleted_meta
					&& false === \metadata_exists( 'post', $post_id, $delete_key )
					&& $case['metaArrayValue']['text'] === \get_post_meta( $post_id, $added_key, true )
					&& false === \metadata_exists( 'post', $post_id, 'cf_admin_poison_' . $case['token'] ),
				'admin custom-field add, update-by-ID, delete-by-ID, and guarded meta_input paths hold',
				array(
					'renamedMeta'  => $renamed_meta,
					'deletedMeta'  => $deleted_meta,
					'addedValue'   => \get_post_meta( $post_id, $added_key, true ),
					'poisonExists' => \metadata_exists( 'post', $post_id, 'cf_admin_poison_' . $case['token'] ),
				)
			);
			self::collect_failure(
				$failures,
				(string) $user_id === (string) \get_post_meta( $post_id, '_edit_last', true )
					&& is_string( $lock )
					&& 1 === preg_match( '/^\d+:' . preg_quote( (string) $user_id, '/' ) . '$/', $lock ),
				'admin save records edit author and post lock metadata',
				array(
					'editLast' => \get_post_meta( $post_id, '_edit_last', true ),
					'lock'     => $lock,
					'userId'   => $user_id,
				)
			);
			self::collect_failure(
				$failures,
				array( (int) $term['term_id'] ) === self::normalize_int_list( (array) $terms )
					&& array() !== $taxonomy_events
					&& $taxonomy === ( $taxonomy_events[0]['taxonomy'] ?? null ),
				'edit_post sanitizes tax_input through the registered taxonomy callback',
				array(
					'terms'          => $terms,
					'expectedTermId' => (int) $term['term_id'],
					'taxonomyEvents' => $taxonomy_events,
				)
			);
			self::collect_failure(
				$failures,
				self::events_are_ordered(
					$events,
					array(
						'update_post_meta',
						'updated_post_meta',
						'delete_post_meta',
						'deleted_post_meta',
						'add_post_meta',
						'added_post_meta',
						'pre_post_update',
						"save_post_{$post_type}",
						'save_post',
						'wp_insert_post',
						'wp_after_insert_post',
					)
				),
				'admin meta orchestration completes before the final post update hooks',
				array( 'events' => $events )
			);
		} finally {
			if ( null !== $cap_filter ) {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}
			self::remove_hooks( $hooks );
			$_POST    = $post_snapshot;
			$_GET     = $get_snapshot;
			$_REQUEST = $request_snapshot;
		}

		return $ctx->result(
			'content-lifecycle.posts.admin-save-orchestration',
			array() === $failures,
			array(
				'case'           => self::case_summary( $case ),
				'visibility'     => $visibility,
				'failures'       => array_slice( $failures, 0, 8 ),
				'events'         => array_slice( $events, 0, 18 ),
				'taxonomyEvents' => $taxonomy_events,
			)
		);
	}

	private static function check_admin_bulk_post_edit_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures         = array();
		$post_events      = array();
		$bulk_events      = array();
		$hooks            = array();
		$bulk_hooks       = array();
		$cap_filter       = null;
		$post_snapshot    = $_POST;
		$get_snapshot     = $_GET;
		$request_snapshot = $_REQUEST;
		$token            = $case['token'];
		$missing_id       = 987654321;
		$original_date    = '2020-01-02 03:04:05';
		$bulk_title       = self::usable_name( $case['updatedTitle'], 'Bulk Edited ' . $token );
		$bulk_content     = self::usable_content( $case['updatedContent'], 'Bulk content ' . $token );
		$bulk_excerpt     = self::usable_content( $case['excerpt'], 'Bulk excerpt ' . $token );
		$hooks_removed    = false;

		try {
			$editor_id = self::insert_support_user( 'bulk-editor-' . $token, 'bulk-editor-' . $token . '@example.test' );
			$author_id = self::insert_support_user( 'bulk-author-' . $token, 'bulk-author-' . $token . '@example.test' );
			$locker_id = self::insert_support_user( 'bulk-locker-' . $token, 'bulk-locker-' . $token . '@example.test' );

			\wp_set_current_user( $editor_id );
			$cap_filter = self::grant_all_caps_filter( $editor_id );
			\add_filter( 'user_has_cap', $cap_filter, 10, 4 );

			$original_cat      = \wp_insert_term( 'Bulk Original ' . $token, 'category', array( 'slug' => 'bulk-original-' . $token ) );
			$bulk_cat          = \wp_insert_term( 'Bulk Category ' . $token, 'category', array( 'slug' => 'bulk-category-' . $token ) );
			$original_tag_name = 'Bulk Original Tag ' . $token;
			$bulk_tag_name     = 'Bulk Tag ' . $token;
			$added_tag         = 'Bulk Added Tag ' . $token;
			$original_tag      = \wp_insert_term( $original_tag_name, 'post_tag', array( 'slug' => 'bulk-original-tag-' . $token ) );
			$bulk_tag          = \wp_insert_term( $bulk_tag_name, 'post_tag', array( 'slug' => 'bulk-tag-' . $token ) );
			$post_ids          = array();

			$insert_post = static function ( string $title, string $status ) use ( $editor_id, $original_date, &$post_ids ) {
				$post_id = \wp_insert_post(
					\wp_slash(
						array(
							'post_type'      => 'post',
							'post_title'     => $title,
							'post_content'   => $title . ' content',
							'post_excerpt'   => $title . ' excerpt',
							'post_status'    => $status,
							'post_author'    => $editor_id,
							'post_date'      => $original_date,
							'post_date_gmt'  => $original_date,
							'comment_status' => 'open',
							'ping_status'    => 'closed',
						)
					),
					true,
					false
				);

				if ( is_int( $post_id ) ) {
					$post_ids[] = $post_id;
				}

				return $post_id;
			};

			$first_id  = $insert_post( 'Bulk First ' . $token, 'draft' );
			$second_id = $insert_post( 'Bulk Second ' . $token, 'draft' );
			$locked_id = $insert_post( 'Bulk Locked ' . $token, 'draft' );

			if ( is_array( $original_cat ) && isset( $original_cat['term_id'] ) ) {
				foreach ( array( $first_id, $second_id, $locked_id ) as $post_id ) {
					if ( is_int( $post_id ) ) {
						\wp_set_object_terms( $post_id, array( (int) $original_cat['term_id'] ), 'category' );
					}
				}
			}
			if ( is_array( $original_tag ) && isset( $original_tag['term_id'] ) ) {
				foreach ( array( $first_id, $second_id, $locked_id ) as $post_id ) {
					if ( is_int( $post_id ) ) {
						\wp_set_object_terms( $post_id, array( (int) $original_tag['term_id'] ), 'post_tag' );
					}
				}
			}
			if ( is_int( $locked_id ) ) {
				\update_post_meta( $locked_id, '_edit_lock', time() . ':' . $locker_id );
			}

			self::collect_failure(
				$failures,
				$editor_id > 0
					&& $author_id > 0
					&& $locker_id > 0
					&& is_array( $original_cat )
					&& is_array( $bulk_cat )
					&& is_array( $original_tag )
					&& is_array( $bulk_tag )
					&& is_int( $first_id )
					&& is_int( $second_id )
					&& is_int( $locked_id ),
				'bulk edit fixtures insert before classic admin helper runs',
				array(
					'editorId'    => $editor_id,
					'authorId'    => $author_id,
					'lockerId'    => $locker_id,
					'originalCat' => $original_cat,
					'bulkCat'     => $bulk_cat,
					'originalTag' => $original_tag,
					'bulkTag'     => $bulk_tag,
					'postIds'     => $post_ids,
				)
			);

			if (
				$editor_id <= 0
				|| $author_id <= 0
				|| $locker_id <= 0
				|| ! is_array( $original_cat )
				|| ! is_array( $bulk_cat )
				|| ! is_array( $original_tag )
				|| ! is_array( $bulk_tag )
				|| ! is_int( $first_id )
				|| ! is_int( $second_id )
				|| ! is_int( $locked_id )
			) {
				return $ctx->result(
					'content-lifecycle.posts.admin-bulk-edit-lifecycle',
					false,
					array(
						'case'     => self::case_summary( $case ),
						'failures' => array_slice( $failures, 0, 6 ),
					)
				);
			}

			$hooks      = self::install_post_hooks( 'post', $post_events );
			$bulk_hooks = self::install_admin_bulk_edit_hooks( $bulk_events );

			$bulk_data = \wp_slash(
				array(
					'post_type'                   => 'post',
					'post'                        => array( $first_id, $second_id, $locked_id, $missing_id ),
					'_status'                     => 'publish',
					'post_author'                 => $author_id,
					'post_title'                  => $bulk_title,
					'content'                     => $bulk_content,
					'excerpt'                     => $bulk_excerpt,
					'comment_status'              => 'closed',
					'ping_status'                 => 'open',
					'post_category'               => array( (int) $original_cat['term_id'], (int) $bulk_cat['term_id'] ),
					'indeterminate_post_category' => array( (int) $original_cat['term_id'] ),
					'tax_input'                   => array(
						'post_tag' => $bulk_tag_name . ',' . $added_tag,
					),
					'post_format'                 => 'aside',
					'sticky'                      => 'sticky',
				)
			);

			$result         = \bulk_edit_posts( $bulk_data );
			$first          = \get_post( $first_id );
			$second         = \get_post( $second_id );
			$locked         = \get_post( $locked_id );
			$first_cats     = \wp_get_object_terms( $first_id, 'category', array( 'fields' => 'ids' ) );
			$second_cats    = \wp_get_object_terms( $second_id, 'category', array( 'fields' => 'ids' ) );
			$locked_cats    = \wp_get_object_terms( $locked_id, 'category', array( 'fields' => 'ids' ) );
			$first_tags     = \wp_get_object_terms( $first_id, 'post_tag', array( 'fields' => 'names' ) );
			$second_tags    = \wp_get_object_terms( $second_id, 'post_tag', array( 'fields' => 'names' ) );
			$locked_tags    = \wp_get_object_terms( $locked_id, 'post_tag', array( 'fields' => 'names' ) );
			$sticky_posts   = \get_option( 'sticky_posts' );
			$post_counts    = array_count_values( array_filter( $post_events, 'is_string' ) );
			$expected_cats  = array( (int) $original_cat['term_id'], (int) $bulk_cat['term_id'] );
			$expected_tags  = self::sorted_string_values( array( $original_tag_name, $bulk_tag_name, $added_tag ) );
			$updated_ids    = isset( $result['updated'] ) ? self::normalize_int_list( (array) $result['updated'] ) : array();
			$locked_ids     = isset( $result['locked'] ) ? self::normalize_int_list( (array) $result['locked'] ) : array();
			$skipped_ids    = isset( $result['skipped'] ) ? self::normalize_int_list( (array) $result['skipped'] ) : array();

			self::collect_failure(
				$failures,
				is_array( $result )
					&& self::normalize_int_list( array( $first_id, $second_id ) ) === $updated_ids
					&& array( $locked_id ) === $locked_ids
					&& array( $missing_id ) === $skipped_ids,
				'bulk_edit_posts returns updated, locked, and skipped IDs by branch',
				array(
					'result'      => $result,
					'expected'    => array(
						'updated' => self::normalize_int_list( array( $first_id, $second_id ) ),
						'locked'  => array( $locked_id ),
						'skipped' => array( $missing_id ),
					),
					'updatedIds'  => $updated_ids,
					'lockedIds'   => $locked_ids,
					'skippedIds'  => $skipped_ids,
				)
			);

			foreach ( array( $first_id => $first, $second_id => $second ) as $post_id => $post ) {
				self::collect_failure(
					$failures,
					$post instanceof \WP_Post
						&& 'publish' === $post->post_status
						&& $author_id === (int) $post->post_author
						&& $bulk_title === $post->post_title
						&& $bulk_content === $post->post_content
						&& $bulk_excerpt === $post->post_excerpt
						&& 'closed' === $post->comment_status
						&& 'open' === $post->ping_status
						&& $original_date !== $post->post_date
						&& 'aside' === \get_post_format( $post_id )
						&& (string) $editor_id === (string) \get_post_meta( $post_id, '_edit_last', true ),
					'bulk_edit_posts applies classic fields to each editable post',
					array(
						'postId'        => $post_id,
						'post'          => self::post_summary( $post ),
						'author'        => $post instanceof \WP_Post ? $post->post_author : null,
						'title'         => $post instanceof \WP_Post ? self::describe_string( $post->post_title ) : null,
						'content'       => $post instanceof \WP_Post ? self::describe_string( $post->post_content ) : null,
						'excerpt'       => $post instanceof \WP_Post ? self::describe_string( $post->post_excerpt ) : null,
						'commentStatus' => $post instanceof \WP_Post ? $post->comment_status : null,
						'pingStatus'    => $post instanceof \WP_Post ? $post->ping_status : null,
						'postDate'      => $post instanceof \WP_Post ? $post->post_date : null,
						'format'        => \get_post_format( $post_id ),
						'editLast'      => \get_post_meta( $post_id, '_edit_last', true ),
					)
				);
			}

			self::collect_failure(
				$failures,
				self::same_id_set( (array) $first_cats, $expected_cats )
					&& self::same_id_set( (array) $second_cats, $expected_cats )
					&& self::same_id_set( (array) $locked_cats, array( (int) $original_cat['term_id'] ) )
					&& self::sorted_string_values( (array) $first_tags ) === $expected_tags
					&& self::sorted_string_values( (array) $second_tags ) === $expected_tags
					&& self::sorted_string_values( (array) $locked_tags ) === self::sorted_string_values( array( $original_tag_name ) ),
				'bulk_edit_posts merges category and tag edits only into editable posts',
				array(
					'firstCats'    => $first_cats,
					'secondCats'   => $second_cats,
					'lockedCats'   => $locked_cats,
					'expectedCats' => $expected_cats,
					'firstTags'    => $first_tags,
					'secondTags'   => $second_tags,
					'lockedTags'   => $locked_tags,
					'expectedTags' => $expected_tags,
				)
			);

			self::collect_failure(
				$failures,
				$locked instanceof \WP_Post
					&& 'draft' === $locked->post_status
					&& $editor_id === (int) $locked->post_author
					&& 'Bulk Locked ' . $token === $locked->post_title
					&& 'open' === $locked->comment_status
					&& 'closed' === $locked->ping_status
					&& false === \get_post_format( $locked_id ),
				'bulk_edit_posts leaves locked posts unchanged',
				array(
					'lockedPost' => self::post_summary( $locked ),
					'author'     => $locked instanceof \WP_Post ? $locked->post_author : null,
					'format'     => \get_post_format( $locked_id ),
				)
			);

			self::collect_failure(
				$failures,
				is_array( $sticky_posts )
					&& self::same_id_set( $sticky_posts, array( $first_id, $second_id ) )
					&& self::admin_bulk_event_present( $bulk_events, 'post_stuck', array( 'postId' => $first_id ) )
					&& self::admin_bulk_event_present( $bulk_events, 'post_stuck', array( 'postId' => $second_id ) )
					&& ! self::admin_bulk_event_present( $bulk_events, 'post_stuck', array( 'postId' => $locked_id ) ),
				'bulk_edit_posts sticks only updated posts when the current user can edit others posts',
				array(
					'stickyPosts' => $sticky_posts,
					'bulkEvents'  => $bulk_events,
				)
			);

			self::collect_failure(
				$failures,
				self::admin_bulk_event_present(
					$bulk_events,
					'bulk_edit_posts',
					array(
						'updated'    => self::normalize_int_list( array( $first_id, $second_id ) ),
						'postStatus' => 'publish',
						'postIds'    => self::normalize_int_list( array( $first_id, $second_id, $locked_id, $missing_id ) ),
					)
				)
					&& ( $post_counts['pre_post_update'] ?? 0 ) >= 2
					&& ( $post_counts['save_post_post'] ?? 0 ) >= 2
					&& ( $post_counts['wp_after_insert_post'] ?? 0 ) >= 2,
				'bulk edit post hooks and final action fire for updated posts',
				array(
					'postEvents' => $post_events,
					'postCounts' => $post_counts,
					'bulkEvents' => $bulk_events,
				)
			);
		} finally {
			if ( null !== $cap_filter ) {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}
			self::remove_hooks( array_merge( $hooks, $bulk_hooks ) );
			$hooks_removed = self::hooks_are_removed( array_merge( $hooks, $bulk_hooks ) );
			$_POST         = $post_snapshot;
			$_GET          = $get_snapshot;
			$_REQUEST      = $request_snapshot;
		}

		self::collect_failure(
			$failures,
			$hooks_removed,
			'admin bulk edit hooks are removed after lifecycle check',
			array( 'hooks' => array_map( static fn( array $hook ): string => $hook[0], array_merge( $hooks, $bulk_hooks ) ) )
		);

		return $ctx->result(
			'content-lifecycle.posts.admin-bulk-edit-lifecycle',
			array() === $failures,
			array(
				'case'       => self::case_summary( $case ),
				'failures'   => array_slice( $failures, 0, 8 ),
				'postEvents' => array_slice( $post_events, 0, 24 ),
				'bulkEvents' => array_slice( $bulk_events, 0, 12 ),
			)
		);
	}

	private static function check_admin_bulk_post_edit_edge_cases( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures         = array();
		$events           = array();
		$hooks            = array();
		$cap_filter       = null;
		$post_snapshot    = $_POST;
		$get_snapshot     = $_GET;
		$request_snapshot = $_REQUEST;
		$token            = $case['token'];
		$hooks_removed    = false;

		try {
			$editor_id = self::insert_support_user( 'bulk-edge-editor-' . $token, 'bulk-edge-editor-' . $token . '@example.test' );
			$other_id  = self::insert_support_user( 'bulk-edge-other-' . $token, 'bulk-edge-other-' . $token . '@example.test' );

			\wp_set_current_user( $editor_id );
			$cap_filter = self::grant_all_caps_filter( $editor_id );
			\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
			$hooks = self::install_admin_bulk_edit_hooks( $events );

			$cat_a = \wp_insert_term( 'Bulk Edge Cat A ' . $token, 'category', array( 'slug' => 'bulk-edge-cat-a-' . $token ) );
			$cat_b = \wp_insert_term( 'Bulk Edge Cat B ' . $token, 'category', array( 'slug' => 'bulk-edge-cat-b-' . $token ) );
			$tag_a_name = 'Bulk Edge Tag A ' . $token;
			$tag_b_name = 'Bulk Edge Tag B ' . $token;
			$tag_a      = \wp_insert_term( $tag_a_name, 'post_tag', array( 'slug' => 'bulk-edge-tag-a-' . $token ) );
			$tag_b      = \wp_insert_term( $tag_b_name, 'post_tag', array( 'slug' => 'bulk-edge-tag-b-' . $token ) );

			$sentinel_first = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => 'post',
						'post_title'     => 'Sentinel First ' . $token,
						'post_content'   => 'Sentinel first content ' . $token,
						'post_excerpt'   => 'Sentinel first excerpt ' . $token,
						'post_status'    => 'draft',
						'post_author'    => $editor_id,
						'post_password'  => 'alpha-' . $token,
						'comment_status' => 'open',
						'ping_status'    => 'closed',
						'post_date'      => '2021-01-02 03:04:05',
						'post_date_gmt'  => '2021-01-02 03:04:05',
					)
				),
				true,
				false
			);
			$sentinel_second = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => 'post',
						'post_title'     => 'Sentinel Second ' . $token,
						'post_content'   => 'Sentinel second content ' . $token,
						'post_excerpt'   => 'Sentinel second excerpt ' . $token,
						'post_status'    => 'pending',
						'post_author'    => $other_id,
						'post_password'  => 'beta-' . $token,
						'comment_status' => 'closed',
						'ping_status'    => 'open',
						'post_date'      => '2021-02-03 04:05:06',
						'post_date_gmt'  => '2021-02-03 04:05:06',
					)
				),
				true,
				false
			);

			if ( is_int( $sentinel_first ) && is_array( $cat_a ) && is_array( $tag_a ) ) {
				\wp_set_object_terms( $sentinel_first, array( (int) $cat_a['term_id'] ), 'category' );
				\wp_set_object_terms( $sentinel_first, array( (int) $tag_a['term_id'] ), 'post_tag' );
				\set_post_format( $sentinel_first, 'aside' );
			}
			if ( is_int( $sentinel_second ) && is_array( $cat_b ) && is_array( $tag_b ) ) {
				\wp_set_object_terms( $sentinel_second, array( (int) $cat_b['term_id'] ), 'category' );
				\wp_set_object_terms( $sentinel_second, array( (int) $tag_b['term_id'] ), 'post_tag' );
			}
			if ( is_int( $sentinel_first ) ) {
				\update_option( 'sticky_posts', array( $sentinel_first ) );
			}

			self::collect_failure(
				$failures,
				$editor_id > 0
					&& $other_id > 0
					&& is_int( $sentinel_first )
					&& is_int( $sentinel_second )
					&& is_array( $cat_a )
					&& is_array( $cat_b )
					&& is_array( $tag_a )
					&& is_array( $tag_b ),
				'bulk edit edge fixtures insert before sentinel checks',
				array(
					'editorId'       => $editor_id,
					'otherId'        => $other_id,
					'sentinelFirst'  => $sentinel_first,
					'sentinelSecond' => $sentinel_second,
					'catA'           => $cat_a,
					'catB'           => $cat_b,
					'tagA'           => $tag_a,
					'tagB'           => $tag_b,
				)
			);

			if (
				$editor_id <= 0
				|| $other_id <= 0
				|| ! is_int( $sentinel_first )
				|| ! is_int( $sentinel_second )
				|| ! is_array( $cat_a )
				|| ! is_array( $cat_b )
				|| ! is_array( $tag_a )
				|| ! is_array( $tag_b )
			) {
				return $ctx->result(
					'content-lifecycle.posts.admin-bulk-edit-edge-cases',
					false,
					array(
						'case'     => self::case_summary( $case ),
						'failures' => array_slice( $failures, 0, 6 ),
					)
				);
			}

			$sentinel_result = \bulk_edit_posts(
				\wp_slash(
					array(
						'post_type'      => 'post',
						'post'           => array( $sentinel_first, $sentinel_second ),
						'_status'        => '-1',
						'post_author'    => '-1',
						'post_password'  => '-1',
						'comment_status' => '-1',
						'ping_status'    => '',
						'post_category'  => '-1',
						'tax_input'      => '',
						'post_format'    => '-1',
						'sticky'         => '-1',
					)
				)
			);
			$first_after_sentinel  = \get_post( $sentinel_first );
			$second_after_sentinel = \get_post( $sentinel_second );
			$first_cats            = \wp_get_object_terms( $sentinel_first, 'category', array( 'fields' => 'ids' ) );
			$second_cats           = \wp_get_object_terms( $sentinel_second, 'category', array( 'fields' => 'ids' ) );
			$first_tags            = \wp_get_object_terms( $sentinel_first, 'post_tag', array( 'fields' => 'names' ) );
			$second_tags           = \wp_get_object_terms( $sentinel_second, 'post_tag', array( 'fields' => 'names' ) );
			$sticky_after_sentinel = \get_option( 'sticky_posts' );

			self::collect_failure(
				$failures,
				is_array( $sentinel_result )
					&& self::normalize_int_list( array( $sentinel_first, $sentinel_second ) ) === self::normalize_int_list( (array) $sentinel_result['updated'] )
					&& array() === (array) $sentinel_result['skipped']
					&& array() === (array) $sentinel_result['locked'],
				'bulk edit sentinel request still updates both rows without skip or lock branches',
				array( 'result' => $sentinel_result )
			);
			self::collect_failure(
				$failures,
				$first_after_sentinel instanceof \WP_Post
					&& $second_after_sentinel instanceof \WP_Post
					&& 'draft' === $first_after_sentinel->post_status
					&& 'pending' === $second_after_sentinel->post_status
					&& $editor_id === (int) $first_after_sentinel->post_author
					&& $other_id === (int) $second_after_sentinel->post_author
					&& 'alpha-' . $token === $first_after_sentinel->post_password
					&& 'beta-' . $token === $second_after_sentinel->post_password
					&& 'open' === $first_after_sentinel->comment_status
					&& 'closed' === $second_after_sentinel->comment_status
					&& 'closed' === $first_after_sentinel->ping_status
					&& 'open' === $second_after_sentinel->ping_status
					&& 'Sentinel First ' . $token === $first_after_sentinel->post_title
					&& 'Sentinel Second ' . $token === $second_after_sentinel->post_title,
				'bulk edit sentinel fields preserve per-post status, author, password, discussion, and title values',
				array(
					'first'  => self::post_summary( $first_after_sentinel ),
					'second' => self::post_summary( $second_after_sentinel ),
					'firstPassword' => $first_after_sentinel instanceof \WP_Post ? $first_after_sentinel->post_password : null,
					'secondPassword' => $second_after_sentinel instanceof \WP_Post ? $second_after_sentinel->post_password : null,
				)
			);
			self::collect_failure(
				$failures,
				self::same_id_set( (array) $first_cats, array( (int) $cat_a['term_id'] ) )
					&& self::same_id_set( (array) $second_cats, array( (int) $cat_b['term_id'] ) )
					&& self::sorted_string_values( (array) $first_tags ) === self::sorted_string_values( array( $tag_a_name ) )
					&& self::sorted_string_values( (array) $second_tags ) === self::sorted_string_values( array( $tag_b_name ) )
					&& 'aside' === \get_post_format( $sentinel_first )
					&& false === \get_post_format( $sentinel_second )
					&& is_array( $sticky_after_sentinel )
					&& self::same_id_set( $sticky_after_sentinel, array( $sentinel_first ) )
					&& self::admin_bulk_event_present(
						$events,
						'bulk_edit_posts',
						array(
							'updated'    => array( $sentinel_first, $sentinel_second ),
							'postIds'    => array( $sentinel_first, $sentinel_second ),
							'hasStatus'  => false,
							'hasPostId'  => false,
							'hasPostParent' => false,
							'sticky'     => null,
						)
					),
				'bulk edit sentinel fields preserve categories, tags, format, sticky option, and normalized shared payload',
				array(
					'firstCats'  => $first_cats,
					'secondCats' => $second_cats,
					'firstTags'  => $first_tags,
					'secondTags' => $second_tags,
					'firstFormat' => \get_post_format( $sentinel_first ),
					'secondFormat' => \get_post_format( $sentinel_second ),
					'sticky'     => $sticky_after_sentinel,
					'events'     => $events,
				)
			);

			$invalid_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => 'post',
						'post_title'     => 'Invalid Date ' . $token,
						'post_content'   => 'Invalid date content ' . $token,
						'post_status'    => 'draft',
						'post_author'    => $editor_id,
						'post_date'      => '2022-03-04 05:06:07',
						'post_date_gmt'  => '2022-03-04 05:06:07',
						'comment_status' => 'open',
						'ping_status'    => 'closed',
					)
				),
				true,
				false
			);
			$invalid_result = is_int( $invalid_id )
				? \bulk_edit_posts(
					\wp_slash(
						array(
							'post_type'   => 'post',
							'post'        => array( $invalid_id ),
							'_status'     => 'publish',
							'post_title'  => 'Invalid Date Mutated ' . $token,
							'edit_date'   => '1',
							'aa'          => '2023',
							'mm'          => '02',
							'jj'          => '31',
							'hh'          => '12',
							'mn'          => '34',
							'ss'          => '00',
						)
					)
				)
				: null;
			$invalid_after = is_int( $invalid_id ) ? \get_post( $invalid_id ) : null;

			self::collect_failure(
				$failures,
				is_int( $invalid_id )
					&& is_array( $invalid_result )
					&& array() === (array) $invalid_result['updated']
					&& array( $invalid_id ) === self::normalize_int_list( (array) $invalid_result['skipped'] )
					&& array() === (array) $invalid_result['locked']
					&& $invalid_after instanceof \WP_Post
					&& 'draft' === $invalid_after->post_status
					&& 'Invalid Date ' . $token === $invalid_after->post_title
					&& '2022-03-04 05:06:07' === $invalid_after->post_date
					&& '' === (string) \get_post_meta( $invalid_id, '_edit_last', true ),
				'bulk edit invalid explicit dates skip without mutating the post',
				array(
					'invalidId' => $invalid_id,
					'result'    => $invalid_result,
					'post'      => self::post_summary( $invalid_after ),
					'postDate'  => $invalid_after instanceof \WP_Post ? $invalid_after->post_date : null,
					'editLast'  => is_int( $invalid_id ) ? \get_post_meta( $invalid_id, '_edit_last', true ) : null,
				)
			);

			$root_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => 'page',
						'post_title'   => 'Bulk Root ' . $token,
						'post_status'  => 'publish',
						'post_author'  => $editor_id,
						'post_parent'  => 0,
						'menu_order'   => 1,
					)
				),
				true,
				false
			);
			$child_id = is_int( $root_id ) ? \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => 'page',
						'post_title'   => 'Bulk Child ' . $token,
						'post_status'  => 'publish',
						'post_author'  => $editor_id,
						'post_parent'  => $root_id,
						'menu_order'   => 2,
					)
				),
				true,
				false
			) : $root_id;
			$sibling_id = is_int( $root_id ) ? \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => 'page',
						'post_title'   => 'Bulk Sibling ' . $token,
						'post_status'  => 'publish',
						'post_author'  => $editor_id,
						'post_parent'  => 0,
						'menu_order'   => 3,
					)
				),
				true,
				false
			) : $root_id;
			$page_result = is_int( $root_id ) && is_int( $child_id ) && is_int( $sibling_id )
				? \bulk_edit_posts(
					\wp_slash(
						array(
							'post_type'   => 'page',
							'post'        => array( $root_id, $child_id, $sibling_id ),
							'_status'     => '-1',
							'post_parent' => $child_id,
							'post_title'  => 'Bulk Page Reparented ' . $token,
						)
					)
				)
				: null;
			$root_after    = is_int( $root_id ) ? \get_post( $root_id ) : null;
			$child_after   = is_int( $child_id ) ? \get_post( $child_id ) : null;
			$sibling_after = is_int( $sibling_id ) ? \get_post( $sibling_id ) : null;

			self::collect_failure(
				$failures,
				is_int( $root_id )
					&& is_int( $child_id )
					&& is_int( $sibling_id )
					&& is_array( $page_result )
					&& array( $sibling_id ) === self::normalize_int_list( (array) $page_result['updated'] )
					&& self::normalize_int_list( array( $root_id, $child_id ) ) === self::normalize_int_list( (array) $page_result['skipped'] )
					&& array() === (array) $page_result['locked']
					&& $root_after instanceof \WP_Post
					&& $child_after instanceof \WP_Post
					&& $sibling_after instanceof \WP_Post
					&& 0 === (int) $root_after->post_parent
					&& $root_id === (int) $child_after->post_parent
					&& $child_id === (int) $sibling_after->post_parent
					&& 'Bulk Root ' . $token === $root_after->post_title
					&& 'Bulk Child ' . $token === $child_after->post_title
					&& 'Bulk Page Reparented ' . $token === $sibling_after->post_title
					&& self::admin_bulk_event_present(
						$events,
						'bulk_edit_posts',
						array(
							'updated'       => array( $sibling_id ),
							'postIds'       => array( $root_id, $child_id, $sibling_id ),
							'hasStatus'     => false,
							'hasPostParent' => true,
							'postParent'    => $child_id,
						)
					),
				'bulk edit page parent ancestry skips selected ancestors and updates only safe siblings',
				array(
					'rootId'     => $root_id,
					'childId'    => $child_id,
					'siblingId'  => $sibling_id,
					'result'     => $page_result,
					'root'       => self::post_summary( $root_after ),
					'child'      => self::post_summary( $child_after ),
					'sibling'    => self::post_summary( $sibling_after ),
					'events'     => $events,
				)
			);
		} finally {
			if ( null !== $cap_filter ) {
				\remove_filter( 'user_has_cap', $cap_filter, 10 );
			}
			self::remove_hooks( $hooks );
			$hooks_removed = self::hooks_are_removed( $hooks );
			$_POST         = $post_snapshot;
			$_GET          = $get_snapshot;
			$_REQUEST      = $request_snapshot;
		}

		self::collect_failure(
			$failures,
			$hooks_removed,
			'admin bulk edit edge hooks are removed after lifecycle check',
			array( 'hooks' => array_map( static fn( array $hook ): string => $hook[0], $hooks ) )
		);

		return $ctx->result(
			'content-lifecycle.posts.admin-bulk-edit-edge-cases',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'events'   => array_slice( $events, 0, 18 ),
			)
		);
	}

	private static function check_admin_bulk_post_edit_capability_edges( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures             = array();
		$bulk_events          = array();
		$cap_events           = array();
		$bulk_hooks           = array();
		$post_deny_filter     = null;
		$assign_filter        = null;
		$status_filter        = null;
		$sticky_filter        = null;
		$post_snapshot        = $_POST;
		$get_snapshot         = $_GET;
		$request_snapshot     = $_REQUEST;
		$token                = $case['token'];
		$hooks_removed        = false;
		$cap_filters_removed  = false;

		try {
			$user_id = self::insert_support_user( 'bulk-cap-editor-' . $token, 'bulk-cap-editor-' . $token . '@example.test' );
			\wp_set_current_user( $user_id );
			$bulk_hooks = self::install_admin_bulk_edit_hooks( $bulk_events );

			$denied_cat      = \wp_insert_term( 'Bulk Cap Denied Cat ' . $token, 'category', array( 'slug' => 'bulk-cap-denied-cat-' . $token ) );
			$denied_tag_name = 'Bulk Cap Denied Tag ' . $token;
			$denied_tag      = \wp_insert_term( $denied_tag_name, 'post_tag', array( 'slug' => 'bulk-cap-denied-tag-' . $token ) );
			$denied_post_id  = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => 'post',
						'post_title'     => 'Bulk Cap Denied ' . $token,
						'post_content'   => 'Bulk cap denied content ' . $token,
						'post_status'    => 'draft',
						'post_author'    => $user_id,
						'comment_status' => 'open',
						'ping_status'    => 'closed',
						'post_date'      => '2022-04-05 06:07:08',
						'post_date_gmt'  => '2022-04-05 06:07:08',
					)
				),
				true,
				false
			);
			if ( is_int( $denied_post_id ) && is_array( $denied_cat ) && is_array( $denied_tag ) ) {
				\wp_set_object_terms( $denied_post_id, array( (int) $denied_cat['term_id'] ), 'category' );
				\wp_set_object_terms( $denied_post_id, array( (int) $denied_tag['term_id'] ), 'post_tag' );
				\update_option( 'sticky_posts', array( $denied_post_id ) );
			}

			self::collect_failure(
				$failures,
				$user_id > 0
					&& is_array( $denied_cat )
					&& is_array( $denied_tag )
					&& is_int( $denied_post_id ),
				'bulk edit capability denial fixtures insert before wp_die capture',
				array(
					'userId'       => $user_id,
					'deniedCat'    => $denied_cat,
					'deniedTag'    => $denied_tag,
					'deniedPostId' => $denied_post_id,
				)
			);

			if ( $user_id <= 0 || ! is_array( $denied_cat ) || ! is_array( $denied_tag ) || ! is_int( $denied_post_id ) ) {
				return $ctx->result(
					'content-lifecycle.posts.admin-bulk-edit-capability-edges',
					false,
					array(
						'case'     => self::case_summary( $case ),
						'failures' => array_slice( $failures, 0, 6 ),
					)
				);
			}

			$post_deny_filter = self::grant_caps_except_filter( $user_id, array( 'edit_posts' ), $cap_events );
			\add_filter( 'user_has_cap', $post_deny_filter, 10, 4 );
			$denial_capture = self::capture_wp_die_call(
				static function () use ( $denied_post_id, $token ) {
					return \bulk_edit_posts(
						\wp_slash(
							array(
								'post_type'  => 'post',
								'post'       => array( $denied_post_id ),
								'_status'    => 'publish',
								'post_title' => 'Bulk Cap Denied Mutated ' . $token,
								'sticky'     => 'unsticky',
							)
						)
					);
				}
			);
			\remove_filter( 'user_has_cap', $post_deny_filter, 10 );
			$denied_post_after  = \get_post( $denied_post_id );
			$denied_post_cats   = \wp_get_object_terms( $denied_post_id, 'category', array( 'fields' => 'ids' ) );
			$denied_post_tags   = \wp_get_object_terms( $denied_post_id, 'post_tag', array( 'fields' => 'names' ) );
			$sticky_after_denial = \get_option( 'sticky_posts' );

			self::collect_failure(
				$failures,
				$denial_capture['captured']
					&& ! $denial_capture['threwUnexpected']
					&& $denial_capture['filtersRestored']
					&& $denial_capture['bufferBalanced']
					&& str_contains( (string) ( $denial_capture['dieCalls'][0]['message'] ?? '' ), 'not allowed to edit posts' )
					&& self::capability_event_present( $cap_events, 'edit_posts' )
					&& array() === $bulk_events,
				'bulk_edit_posts captures top-level edit_posts denial through wp_die before any bulk action fires',
				array(
					'capture'    => $denial_capture,
					'bulkEvents' => $bulk_events,
					'capEvents'  => $cap_events,
				)
			);
			self::collect_failure(
				$failures,
				$denied_post_after instanceof \WP_Post
					&& 'draft' === $denied_post_after->post_status
					&& 'Bulk Cap Denied ' . $token === $denied_post_after->post_title
					&& self::same_id_set( (array) $denied_post_cats, array( (int) $denied_cat['term_id'] ) )
					&& self::sorted_string_values( (array) $denied_post_tags ) === self::sorted_string_values( array( $denied_tag_name ) )
					&& is_array( $sticky_after_denial )
					&& self::same_id_set( $sticky_after_denial, array( $denied_post_id ) )
					&& '' === (string) \get_post_meta( $denied_post_id, '_edit_last', true ),
				'bulk_edit_posts wp_die denial leaves post, terms, sticky state, and edit metadata unchanged',
				array(
					'post'     => self::post_summary( $denied_post_after ),
					'cats'     => $denied_post_cats,
					'tags'     => $denied_post_tags,
					'sticky'   => $sticky_after_denial,
					'editLast' => \get_post_meta( $denied_post_id, '_edit_last', true ),
				)
			);

			$cat_a        = \wp_insert_term( 'Bulk Cap Cat A ' . $token, 'category', array( 'slug' => 'bulk-cap-cat-a-' . $token ) );
			$cat_b        = \wp_insert_term( 'Bulk Cap Cat B ' . $token, 'category', array( 'slug' => 'bulk-cap-cat-b-' . $token ) );
			$tag_a_name   = 'Bulk Cap Tag A ' . $token;
			$tag_b_name   = 'Bulk Cap Tag B ' . $token;
			$new_tag_name = 'Bulk Cap New Tag ' . $token;
			$tag_a        = \wp_insert_term( $tag_a_name, 'post_tag', array( 'slug' => 'bulk-cap-tag-a-' . $token ) );
			$tag_b        = \wp_insert_term( $tag_b_name, 'post_tag', array( 'slug' => 'bulk-cap-tag-b-' . $token ) );
			$assign_first_id  = self::insert_bulk_capability_post( 'Assign First ' . $token, $user_id );
			$assign_second_id = self::insert_bulk_capability_post( 'Assign Second ' . $token, $user_id );

			foreach ( array( $assign_first_id, $assign_second_id ) as $post_id ) {
				if ( is_int( $post_id ) && is_array( $cat_a ) && is_array( $tag_a ) ) {
					\wp_set_object_terms( $post_id, array( (int) $cat_a['term_id'] ), 'category' );
					\wp_set_object_terms( $post_id, array( (int) $tag_a['term_id'] ), 'post_tag' );
				}
			}

			self::collect_failure(
				$failures,
				is_array( $cat_a )
					&& is_array( $cat_b )
					&& is_array( $tag_a )
					&& is_array( $tag_b )
					&& is_int( $assign_first_id )
					&& is_int( $assign_second_id ),
				'bulk edit assign-term capability fixtures insert before filtered term update',
				array(
					'catA'           => $cat_a,
					'catB'           => $cat_b,
					'tagA'           => $tag_a,
					'tagB'           => $tag_b,
					'assignFirstId'  => $assign_first_id,
					'assignSecondId' => $assign_second_id,
				)
			);

			if (
				! is_array( $cat_a )
				|| ! is_array( $cat_b )
				|| ! is_array( $tag_a )
				|| ! is_array( $tag_b )
				|| ! is_int( $assign_first_id )
				|| ! is_int( $assign_second_id )
			) {
				return $ctx->result(
					'content-lifecycle.posts.admin-bulk-edit-capability-edges',
					false,
					array(
						'case'     => self::case_summary( $case ),
						'failures' => array_slice( $failures, 0, 8 ),
					)
				);
			}

			$assign_filter = self::grant_caps_except_filter( $user_id, array( 'assign_post_tags' ), $cap_events );
			\add_filter( 'user_has_cap', $assign_filter, 10, 4 );
			$assign_result = \bulk_edit_posts(
				\wp_slash(
					array(
						'post_type'                   => 'post',
						'post'                        => array( $assign_first_id, $assign_second_id ),
						'_status'                     => '-1',
						'post_title'                  => 'Bulk Cap Assign Updated ' . $token,
						'post_category'               => array( (int) $cat_a['term_id'], (int) $cat_b['term_id'] ),
						'indeterminate_post_category' => array( (int) $cat_a['term_id'] ),
						'tax_input'                   => array(
							'post_tag' => $tag_b_name . ',' . $new_tag_name,
						),
					)
				)
			);
			\remove_filter( 'user_has_cap', $assign_filter, 10 );
			$assign_first_after  = \get_post( $assign_first_id );
			$assign_second_after = \get_post( $assign_second_id );
			$assign_first_cats   = \wp_get_object_terms( $assign_first_id, 'category', array( 'fields' => 'ids' ) );
			$assign_second_cats  = \wp_get_object_terms( $assign_second_id, 'category', array( 'fields' => 'ids' ) );
			$assign_first_tags   = \wp_get_object_terms( $assign_first_id, 'post_tag', array( 'fields' => 'names' ) );
			$assign_second_tags  = \wp_get_object_terms( $assign_second_id, 'post_tag', array( 'fields' => 'names' ) );

			self::collect_failure(
				$failures,
				is_array( $assign_result )
					&& self::normalize_int_list( array( $assign_first_id, $assign_second_id ) ) === self::normalize_int_list( (array) $assign_result['updated'] )
					&& array() === (array) $assign_result['skipped']
					&& array() === (array) $assign_result['locked']
					&& $assign_first_after instanceof \WP_Post
					&& $assign_second_after instanceof \WP_Post
					&& 'Bulk Cap Assign Updated ' . $token === $assign_first_after->post_title
					&& 'Bulk Cap Assign Updated ' . $token === $assign_second_after->post_title
					&& (string) $user_id === (string) \get_post_meta( $assign_first_id, '_edit_last', true )
					&& (string) $user_id === (string) \get_post_meta( $assign_second_id, '_edit_last', true ),
				'bulk_edit_posts updates editable posts while post_tag assignment is denied',
				array(
					'result' => $assign_result,
					'first'  => self::post_summary( $assign_first_after ),
					'second' => self::post_summary( $assign_second_after ),
				)
			);
			self::collect_failure(
				$failures,
				self::same_id_set( (array) $assign_first_cats, array( (int) $cat_a['term_id'], (int) $cat_b['term_id'] ) )
					&& self::same_id_set( (array) $assign_second_cats, array( (int) $cat_a['term_id'], (int) $cat_b['term_id'] ) )
					&& self::sorted_string_values( (array) $assign_first_tags ) === self::sorted_string_values( array( $tag_a_name ) )
					&& self::sorted_string_values( (array) $assign_second_tags ) === self::sorted_string_values( array( $tag_a_name ) )
					&& self::capability_event_present( $cap_events, 'assign_post_tags' ),
				'bulk_edit_posts applies allowed categories while denied post_tag assign_terms preserves existing tags',
				array(
					'firstCats'  => $assign_first_cats,
					'secondCats' => $assign_second_cats,
					'firstTags'  => $assign_first_tags,
					'secondTags' => $assign_second_tags,
					'capEvents'  => $cap_events,
				)
			);

			$status_publish_id = self::insert_bulk_capability_post( 'Status Publish Requested ' . $token, $user_id );
			$status_private_id = self::insert_bulk_capability_post( 'Status Private Requested ' . $token, $user_id );

			self::collect_failure(
				$failures,
				is_int( $status_publish_id )
					&& is_int( $status_private_id ),
				'bulk edit no-publish status fixtures insert editable draft posts',
				array(
					'publishId' => $status_publish_id,
					'privateId' => $status_private_id,
				)
			);

			$status_filter = self::grant_caps_except_filter( $user_id, array( 'publish_posts' ), $cap_events );
			\add_filter( 'user_has_cap', $status_filter, 10, 4 );
			$status_publish_result = is_int( $status_publish_id )
				? \bulk_edit_posts(
					\wp_slash(
						array(
							'post_type'  => 'post',
							'post'       => array( $status_publish_id ),
							'_status'    => 'publish',
							'post_title' => 'Bulk Cap Publish Coerced ' . $token,
							'content'    => 'Bulk cap publish coerced content ' . $token,
						)
					)
				)
				: null;
			$status_private_result = is_int( $status_private_id )
				? \bulk_edit_posts(
					\wp_slash(
						array(
							'post_type'  => 'post',
							'post'       => array( $status_private_id ),
							'_status'    => 'private',
							'post_title' => 'Bulk Cap Private Preserved ' . $token,
							'content'    => 'Bulk cap private preserved content ' . $token,
						)
					)
				)
				: null;
			\remove_filter( 'user_has_cap', $status_filter, 10 );
			$status_publish_after = is_int( $status_publish_id ) ? \get_post( $status_publish_id ) : null;
			$status_private_after = is_int( $status_private_id ) ? \get_post( $status_private_id ) : null;

			self::collect_failure(
				$failures,
				is_int( $status_publish_id )
					&& is_array( $status_publish_result )
					&& array( $status_publish_id ) === self::normalize_int_list( (array) $status_publish_result['updated'] )
					&& array() === (array) $status_publish_result['skipped']
					&& array() === (array) $status_publish_result['locked']
					&& $status_publish_after instanceof \WP_Post
					&& 'pending' === $status_publish_after->post_status
					&& 'Bulk Cap Publish Coerced ' . $token === $status_publish_after->post_title
					&& 'Bulk cap publish coerced content ' . $token === $status_publish_after->post_content
					&& (string) $user_id === (string) \get_post_meta( $status_publish_id, '_edit_last', true )
					&& self::admin_bulk_event_present(
						$bulk_events,
						'bulk_edit_posts',
						array(
							'updated'    => array( $status_publish_id ),
							'postIds'    => array( $status_publish_id ),
							'postStatus' => 'publish',
						)
					)
					&& self::capability_event_present( $cap_events, 'publish_posts' ),
				'bulk_edit_posts coerces requested publish to pending when publish_posts is denied while preserving other edits',
				array(
					'result'    => $status_publish_result,
					'post'      => self::post_summary( $status_publish_after ),
					'editLast'  => is_int( $status_publish_id ) ? \get_post_meta( $status_publish_id, '_edit_last', true ) : null,
					'bulkEvents' => $bulk_events,
					'capEvents' => $cap_events,
				)
			);
			self::collect_failure(
				$failures,
				is_int( $status_private_id )
					&& is_array( $status_private_result )
					&& array( $status_private_id ) === self::normalize_int_list( (array) $status_private_result['updated'] )
					&& array() === (array) $status_private_result['skipped']
					&& array() === (array) $status_private_result['locked']
					&& $status_private_after instanceof \WP_Post
					&& 'draft' === $status_private_after->post_status
					&& 'Bulk Cap Private Preserved ' . $token === $status_private_after->post_title
					&& 'Bulk cap private preserved content ' . $token === $status_private_after->post_content
					&& (string) $user_id === (string) \get_post_meta( $status_private_id, '_edit_last', true )
					&& self::admin_bulk_event_present(
						$bulk_events,
						'bulk_edit_posts',
						array(
							'updated'    => array( $status_private_id ),
							'postIds'    => array( $status_private_id ),
							'postStatus' => 'private',
						)
					)
					&& self::capability_event_present( $cap_events, 'publish_posts' ),
				'bulk_edit_posts preserves previous draft status for requested private when publish_posts is denied while preserving other edits',
				array(
					'result'    => $status_private_result,
					'post'      => self::post_summary( $status_private_after ),
					'editLast'  => is_int( $status_private_id ) ? \get_post_meta( $status_private_id, '_edit_last', true ) : null,
					'bulkEvents' => $bulk_events,
					'capEvents' => $cap_events,
				)
			);

			$sticky_first_id  = self::insert_bulk_capability_post( 'Sticky First ' . $token, $user_id );
			$sticky_second_id = self::insert_bulk_capability_post( 'Sticky Second ' . $token, $user_id );
			if ( is_int( $sticky_first_id ) && is_int( $sticky_second_id ) ) {
				\update_option( 'sticky_posts', array() );
			}

			$sticky_filter = self::grant_caps_except_filter( $user_id, array( 'edit_others_posts' ), $cap_events );
			\add_filter( 'user_has_cap', $sticky_filter, 10, 4 );
			$stick_result = is_int( $sticky_first_id ) && is_int( $sticky_second_id )
				? \bulk_edit_posts(
					\wp_slash(
						array(
							'post_type'  => 'post',
							'post'       => array( $sticky_first_id, $sticky_second_id ),
							'_status'    => '-1',
							'post_title' => 'Bulk Cap Sticky Add Denied ' . $token,
							'sticky'     => 'sticky',
						)
					)
				)
				: null;
			$sticky_after_add_denied = \get_option( 'sticky_posts' );
			if ( is_int( $sticky_first_id ) && is_int( $sticky_second_id ) ) {
				\update_option( 'sticky_posts', array( $sticky_first_id, $sticky_second_id ) );
			}
			$unstick_result = is_int( $sticky_first_id ) && is_int( $sticky_second_id )
				? \bulk_edit_posts(
					\wp_slash(
						array(
							'post_type'  => 'post',
							'post'       => array( $sticky_first_id, $sticky_second_id ),
							'_status'    => '-1',
							'post_title' => 'Bulk Cap Sticky Remove Denied ' . $token,
							'sticky'     => 'unsticky',
						)
					)
				)
				: null;
			\remove_filter( 'user_has_cap', $sticky_filter, 10 );
			$sticky_after_remove_denied = \get_option( 'sticky_posts' );

			self::collect_failure(
				$failures,
				is_int( $sticky_first_id )
					&& is_int( $sticky_second_id )
					&& is_array( $stick_result )
					&& self::normalize_int_list( array( $sticky_first_id, $sticky_second_id ) ) === self::normalize_int_list( (array) $stick_result['updated'] )
					&& array() === (array) $stick_result['skipped']
					&& array() === (array) $stick_result['locked']
					&& is_array( $sticky_after_add_denied )
					&& array() === self::normalize_int_list( $sticky_after_add_denied )
					&& is_array( $unstick_result )
					&& self::normalize_int_list( array( $sticky_first_id, $sticky_second_id ) ) === self::normalize_int_list( (array) $unstick_result['updated'] )
					&& is_array( $sticky_after_remove_denied )
					&& self::same_id_set( $sticky_after_remove_denied, array( $sticky_first_id, $sticky_second_id ) )
					&& self::capability_event_present( $cap_events, 'edit_others_posts' )
					&& ! self::admin_bulk_event_present( $bulk_events, 'post_stuck', array( 'postId' => $sticky_first_id ) )
					&& ! self::admin_bulk_event_present( $bulk_events, 'post_stuck', array( 'postId' => $sticky_second_id ) )
					&& ! self::admin_bulk_event_present( $bulk_events, 'post_unstuck', array( 'postId' => $sticky_first_id ) )
					&& ! self::admin_bulk_event_present( $bulk_events, 'post_unstuck', array( 'postId' => $sticky_second_id ) ),
				'bulk_edit_posts gates sticky add and remove mutations behind edit_others_posts while still updating posts',
				array(
					'stickyFirstId'           => $sticky_first_id,
					'stickySecondId'          => $sticky_second_id,
					'stickResult'             => $stick_result,
					'stickyAfterAddDenied'    => $sticky_after_add_denied,
					'unstickResult'           => $unstick_result,
					'stickyAfterRemoveDenied' => $sticky_after_remove_denied,
					'bulkEvents'              => $bulk_events,
					'capEvents'               => $cap_events,
				)
			);
			self::collect_failure(
				$failures,
				self::admin_bulk_event_present(
					$bulk_events,
					'bulk_edit_posts',
					array(
						'updated' => array( $assign_first_id, $assign_second_id ),
						'postIds' => array( $assign_first_id, $assign_second_id ),
					)
				)
					&& self::admin_bulk_event_present(
						$bulk_events,
						'bulk_edit_posts',
						array(
							'updated' => array( $sticky_first_id, $sticky_second_id ),
							'postIds' => array( $sticky_first_id, $sticky_second_id ),
							'sticky'  => 'sticky',
						)
					)
					&& self::admin_bulk_event_present(
						$bulk_events,
						'bulk_edit_posts',
						array(
							'updated' => array( $sticky_first_id, $sticky_second_id ),
							'postIds' => array( $sticky_first_id, $sticky_second_id ),
							'sticky'  => 'unsticky',
						)
					),
				'bulk_edit_posts capability edge final actions preserve selected IDs and denied sticky intent',
				array( 'bulkEvents' => $bulk_events )
			);
		} finally {
			if ( null !== $post_deny_filter ) {
				\remove_filter( 'user_has_cap', $post_deny_filter, 10 );
			}
			if ( null !== $assign_filter ) {
				\remove_filter( 'user_has_cap', $assign_filter, 10 );
			}
			if ( null !== $status_filter ) {
				\remove_filter( 'user_has_cap', $status_filter, 10 );
			}
			if ( null !== $sticky_filter ) {
				\remove_filter( 'user_has_cap', $sticky_filter, 10 );
			}
			self::remove_hooks( $bulk_hooks );
			$hooks_removed       = self::hooks_are_removed( $bulk_hooks );
			$cap_filters_removed = ( null === $post_deny_filter || false === \has_filter( 'user_has_cap', $post_deny_filter ) )
				&& ( null === $assign_filter || false === \has_filter( 'user_has_cap', $assign_filter ) )
				&& ( null === $status_filter || false === \has_filter( 'user_has_cap', $status_filter ) )
				&& ( null === $sticky_filter || false === \has_filter( 'user_has_cap', $sticky_filter ) );
			$_POST              = $post_snapshot;
			$_GET               = $get_snapshot;
			$_REQUEST           = $request_snapshot;
		}

		self::collect_failure(
			$failures,
			$hooks_removed && $cap_filters_removed,
			'admin bulk edit capability hooks and filters are removed after edge checks',
			array(
				'hooksRemoved'      => $hooks_removed,
				'capFiltersRemoved' => $cap_filters_removed,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.admin-bulk-edit-capability-edges',
			array() === $failures,
			array(
				'case'       => self::case_summary( $case ),
				'failures'   => array_slice( $failures, 0, 8 ),
				'bulkEvents' => array_slice( $bulk_events, 0, 16 ),
				'capEvents'  => array_slice( $cap_events, 0, 32 ),
			)
		);
	}

	private static function check_page_lookup_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures      = array();
		$post_ids      = array();
		$token         = substr( $case['token'], 0, 8 );
		$tree_type     = 'cf_tree_' . $token;
		$flat_type     = 'cf_flat_' . $token;
		$root_slug     = 'lookup-root-' . $token;
		$child_slug    = 'lookup-child-' . $token;
		$grand_slug    = 'lookup-grand-' . $token;
		$sibling_slug  = 'lookup-sibling-' . $token;
		$draft_slug    = 'lookup-draft-' . $token;
		$media_slug    = 'lookup-media-' . $token;
		$custom_root_slug = 'lookup-custom-root-' . $token;
		$custom_child_slug = 'lookup-custom-child-' . $token;
		$previous_post_exists = array_key_exists( 'post', $GLOBALS );
		$previous_post        = $GLOBALS['post'] ?? null;
		$query_events         = array();
		$page_events          = array();
		$query_filter         = static function ( array $query_args, array $parsed_args ) use ( &$query_events ): array {
			$query_events[] = array(
				'query'  => $query_args,
				'parsed' => $parsed_args,
			);
			return $query_args;
		};
		$pages_filter         = static function ( array $pages, array $parsed_args ) use ( &$page_events ): array {
			$page_events[] = array(
				'ids'    => ContentLifecycleSurface::ids_from_posts( $pages ),
				'parsed' => $parsed_args,
			);
			return $pages;
		};
		$insert_post = static function ( array $args ) use ( &$post_ids ) {
			$post_id = \wp_insert_post(
				\wp_slash(
					array_merge(
						array(
							'post_status'   => 'publish',
							'post_title'    => 'Lookup fixture',
							'post_content'  => 'Lookup content',
							'post_date'     => '2022-01-02 03:04:05',
							'post_date_gmt' => '2022-01-02 03:04:05',
						),
						$args
					)
				),
				true,
				false
			);

			if ( is_int( $post_id ) ) {
				$post_ids[] = $post_id;
			}

			return $post_id;
		};

		try {
			$tree_registration = \register_post_type(
				$tree_type,
				array(
					'public'       => true,
					'hierarchical' => true,
					'rewrite'      => false,
					'query_var'    => false,
					'supports'     => array( 'title', 'page-attributes' ),
				)
			);
			$flat_registration = \register_post_type(
				$flat_type,
				array(
					'public'       => true,
					'hierarchical' => false,
					'rewrite'      => false,
					'query_var'    => false,
					'supports'     => array( 'title' ),
				)
			);
			$tree_object = \get_post_type_object( $tree_type );
			$flat_object = \get_post_type_object( $flat_type );

			self::collect_failure(
				$failures,
				$tree_registration instanceof \WP_Post_Type
					&& $flat_registration instanceof \WP_Post_Type
					&& $tree_object instanceof \WP_Post_Type
					&& $flat_object instanceof \WP_Post_Type
					&& true === $tree_object->hierarchical
					&& false === $flat_object->hierarchical,
				'page lookup fixture post types register with the expected hierarchical flags',
				array(
					'treeRegistration' => $tree_registration instanceof \WP_Post_Type ? $tree_registration->name : self::error_summary( $tree_registration ),
					'flatRegistration' => $flat_registration instanceof \WP_Post_Type ? $flat_registration->name : self::error_summary( $flat_registration ),
					'treeObject'       => $tree_object instanceof \WP_Post_Type ? array( 'name' => $tree_object->name, 'hierarchical' => $tree_object->hierarchical ) : $tree_object,
					'flatObject'       => $flat_object instanceof \WP_Post_Type ? array( 'name' => $flat_object->name, 'hierarchical' => $flat_object->hierarchical ) : $flat_object,
				)
			);

			$root_id = $insert_post(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Lookup Root ' . $token,
					'post_name'   => $root_slug,
					'menu_order'  => 2,
					'post_parent' => 0,
				)
			);
			$child_id = is_int( $root_id ) ? $insert_post(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Lookup Child ' . $token,
					'post_name'   => $child_slug,
					'menu_order'  => 4,
					'post_parent' => $root_id,
				)
			) : $root_id;
			$grand_id = is_int( $child_id ) ? $insert_post(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Lookup Grand ' . $token,
					'post_name'   => $grand_slug,
					'menu_order'  => 6,
					'post_parent' => $child_id,
				)
			) : $child_id;
			$sibling_id = is_int( $root_id ) ? $insert_post(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Lookup Sibling ' . $token,
					'post_name'   => $sibling_slug,
					'menu_order'  => 8,
					'post_parent' => $root_id,
				)
			) : $root_id;
			$draft_id = is_int( $root_id ) ? $insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => 'Lookup Draft ' . $token,
					'post_name'   => $draft_slug,
					'menu_order'  => 10,
					'post_parent' => $root_id,
				)
			) : $root_id;
			$attachment_collision_id = is_int( $root_id ) ? $insert_post(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => 'Lookup Attachment Collision ' . $token,
					'post_name'      => 'attachment-collision-' . $token,
					'post_parent'    => $root_id,
					'post_mime_type' => 'image/jpeg',
					'guid'           => 'http://example.test/uploads/collision-' . $token . '.jpg',
				)
			) : $root_id;
			$attachment_orphan_id = $insert_post(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => 'Lookup Attachment Orphan ' . $token,
					'post_name'      => $media_slug,
					'post_parent'    => 0,
					'post_mime_type' => 'image/jpeg',
					'guid'           => 'http://example.test/uploads/orphan-' . $token . '.jpg',
				)
			);
			$custom_root_id = $insert_post(
				array(
					'post_type'   => $tree_type,
					'post_title'  => 'Lookup Custom Root ' . $token,
					'post_name'   => $custom_root_slug,
					'post_parent' => 0,
				)
			);
			$custom_child_id = is_int( $custom_root_id ) ? $insert_post(
				array(
					'post_type'   => $tree_type,
					'post_title'  => 'Lookup Custom Child ' . $token,
					'post_name'   => $custom_child_slug,
					'post_parent' => $custom_root_id,
				)
			) : $custom_root_id;

			$fixture_ids = array( $root_id, $child_id, $grand_id, $sibling_id, $draft_id, $attachment_collision_id, $attachment_orphan_id, $custom_root_id, $custom_child_id );
			if ( array_filter( $fixture_ids, 'is_wp_error' ) || count( array_filter( $fixture_ids, 'is_int' ) ) !== count( $fixture_ids ) ) {
				self::collect_failure(
					$failures,
					false,
					'page lookup fixtures insert as posts without WP_Error',
					array_map( array( self::class, 'error_summary' ), $fixture_ids )
				);

				return $ctx->result(
					'content-lifecycle.pages.lookup-helpers',
					false,
					array(
						'case'     => self::case_summary( $case ),
						'failures' => array_slice( $failures, 0, 8 ),
					)
				);
			}

			$collision_updated = $GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_name' => $child_slug ), array( 'ID' => $attachment_collision_id ) );
			\clean_post_cache( $attachment_collision_id );
			$collision_attachment = \get_post( $attachment_collision_id );
			\wp_cache_flush();

			self::collect_failure(
				$failures,
				1 === $collision_updated
					&& $collision_attachment instanceof \WP_Post
					&& $child_slug === $collision_attachment->post_name
					&& (int) $root_id === (int) $collision_attachment->post_parent,
				'page lookup fixture forces an attachment/page slug collision before path lookup',
				array(
					'updated'    => $collision_updated,
					'attachment' => self::post_summary( $collision_attachment ),
					'expected'   => array(
						'post_name'   => $child_slug,
						'post_parent' => $root_id,
					),
				)
			);

			$full_path       = $root_slug . '/' . $child_slug . '/' . $grand_slug;
			$encoded_path    = '/' . rawurlencode( $root_slug ) . '/' . rawurlencode( $child_slug ) . '/' . rawurlencode( $grand_slug ) . '/';
			$path_object     = \get_page_by_path( $full_path, OBJECT, 'page' );
			$path_array_a    = \get_page_by_path( $encoded_path, ARRAY_A, 'page' );
			$path_array_n    = \get_page_by_path( $full_path, ARRAY_N, 'page' );
			$collision       = \get_page_by_path( $root_slug . '/' . $child_slug, OBJECT, 'page' );
			$attachment_fallback = \get_page_by_path( $media_slug, OBJECT, 'page' );
			$attachment_blocked  = \get_page_by_path( $media_slug, OBJECT, array( 'page' ) );
			$custom_lookup       = \get_page_by_path( $custom_root_slug . '/' . $custom_child_slug, OBJECT, array( 'page', $tree_type ) );
			$missing_lookup      = \get_page_by_path( $root_slug . '/missing-' . $token, OBJECT, 'page' );

			$last_changed = \wp_cache_get_last_changed( 'posts' );
			$hit_hash     = md5( $full_path . serialize( 'page' ) );
			$miss_path    = $root_slug . '/missing-' . $token;
			$miss_hash    = md5( $miss_path . serialize( 'page' ) );
			$cached_hit   = \wp_cache_get_salted( 'get_page_by_path:' . $hit_hash, 'post-queries', $last_changed );
			$cached_miss  = \wp_cache_get_salted( 'get_page_by_path:' . $miss_hash, 'post-queries', $last_changed );
			\clean_post_cache( $grand_id );
			$changed_after_clean = \wp_cache_get_last_changed( 'posts' );
			$cache_after_clean   = \wp_cache_get_salted( 'get_page_by_path:' . $hit_hash, 'post-queries', $changed_after_clean );

			self::collect_failure(
				$failures,
				$path_object instanceof \WP_Post
					&& (int) $grand_id === (int) $path_object->ID
					&& is_array( $path_array_a )
					&& (int) $grand_id === (int) ( $path_array_a['ID'] ?? 0 )
					&& is_array( $path_array_n )
					&& (int) $grand_id === (int) ( $path_array_n[0] ?? 0 )
					&& $collision instanceof \WP_Post
					&& (int) $child_id === (int) $collision->ID
					&& $attachment_fallback instanceof \WP_Post
					&& (int) $attachment_orphan_id === (int) $attachment_fallback->ID
					&& null === $attachment_blocked
					&& $custom_lookup instanceof \WP_Post
					&& (int) $custom_child_id === (int) $custom_lookup->ID
					&& null === $missing_lookup
					&& (int) $grand_id === (int) $cached_hit
					&& 0 === (int) $cached_miss
					&& $changed_after_clean !== $last_changed
					&& false === $cache_after_clean,
				'get_page_by_path resolves ancestry, output formats, custom post types, attachment fallback, and salted hit/miss caches',
				array(
					'pathObject'        => self::post_summary( $path_object ),
					'pathArrayA'        => $path_array_a,
					'pathArrayN'        => array_slice( is_array( $path_array_n ) ? $path_array_n : array(), 0, 4 ),
					'collision'         => self::post_summary( $collision ),
					'attachmentFallback' => self::post_summary( $attachment_fallback ),
					'attachmentBlocked' => self::post_summary( $attachment_blocked ),
					'customLookup'      => self::post_summary( $custom_lookup ),
					'cachedHit'         => $cached_hit,
					'cachedMiss'        => $cached_miss,
					'lastChangedBefore' => $last_changed,
					'lastChangedAfter'  => $changed_after_clean,
					'cacheAfterClean'   => $cache_after_clean,
				)
			);

			$page_inputs = array(
				\get_post( $root_id ),
				\get_post( $child_id ),
				\get_post( $grand_id ),
				\get_post( $sibling_id ),
				\get_post( $draft_id ),
				\get_post( $custom_root_id ),
				\get_post( $custom_child_id ),
			);
			$root_children    = \get_page_children( $root_id, $page_inputs );
			$child_children   = \get_page_children( $child_id, $page_inputs );
			$unknown_children = \get_page_children( 987654321, $page_inputs );

			self::collect_failure(
				$failures,
				self::all_wp_posts( $root_children )
					&& self::all_wp_posts( $child_children )
					&& array( $child_id, $grand_id, $sibling_id, $draft_id ) === self::ids_from_posts( $root_children )
					&& array( $grand_id ) === self::ids_from_posts( $child_children )
					&& array() === $unknown_children,
				'get_page_children preserves input-order depth-first descendants and excludes siblings/unknown roots',
				array(
					'rootChildren'    => self::ids_from_posts( $root_children ),
					'childChildren'   => self::ids_from_posts( $child_children ),
					'unknownChildren' => $unknown_children,
				)
			);

			$all_pages = \get_pages(
				array(
					'post_type'    => 'page',
					'post_status'  => array( 'publish', 'draft' ),
					'hierarchical' => false,
					'sort_column'  => 'ID',
					'sort_order'   => 'ASC',
				)
			);
			$root_descendants = \get_pages(
				array(
					'post_type'    => 'page',
					'post_status'  => array( 'publish', 'draft' ),
					'child_of'     => $root_id,
					'sort_column'  => 'ID',
					'sort_order'   => 'ASC',
				)
			);
			$direct_children = \get_pages(
				array(
					'post_type'    => 'page',
					'post_status'  => array( 'publish', 'draft' ),
					'parent'       => $root_id,
					'sort_column'  => 'ID',
					'sort_order'   => 'ASC',
				)
			);
			$exclude_tree = \get_pages(
				array(
					'post_type'    => 'page',
					'post_status'  => array( 'publish', 'draft' ),
					'exclude_tree' => array( $child_id ),
					'hierarchical' => false,
					'sort_column'  => 'ID',
					'sort_order'   => 'ASC',
				)
			);
			$limited = \get_pages(
				array(
					'post_type'    => 'page',
					'post_status'  => array( 'publish', 'draft' ),
					'hierarchical' => false,
					'number'       => 2,
					'offset'       => 1,
					'sort_column'  => 'ID',
					'sort_order'   => 'ASC',
				)
			);

			\add_filter( 'get_pages_query_args', $query_filter, 10, 2 );
			\add_filter( 'get_pages', $pages_filter, 10, 2 );
			try {
				$included = \get_pages(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'include'     => array( $grand_id, $root_id ),
						'child_of'    => $root_id,
						'parent'      => $child_id,
						'exclude'     => array( $root_id ),
						'meta_key'    => '_should_be_ignored_with_include',
						'meta_value'  => 'ignored',
					)
				);
			} finally {
				\remove_filter( 'get_pages_query_args', $query_filter, 10 );
				\remove_filter( 'get_pages', $pages_filter, 10 );
			}
			$non_hierarchical = \get_pages( array( 'post_type' => $flat_type ) );
			$invalid_status   = \get_pages( array( 'post_type' => 'page', 'post_status' => 'component-fuzz-status' ) );

			self::collect_failure(
				$failures,
				self::all_wp_posts( $all_pages )
					&& self::all_wp_posts( $root_descendants )
					&& self::all_wp_posts( $direct_children )
					&& self::all_wp_posts( $exclude_tree )
					&& self::all_wp_posts( $limited )
					&& self::all_wp_posts( $included )
					&& self::same_id_set( self::ids_from_posts( $all_pages ), array( $root_id, $child_id, $grand_id, $sibling_id, $draft_id ) )
					&& array( $child_id, $grand_id, $sibling_id, $draft_id ) === self::ids_from_posts( $root_descendants )
					&& self::same_id_set( self::ids_from_posts( $direct_children ), array( $child_id, $sibling_id, $draft_id ) )
					&& self::same_id_set( self::ids_from_posts( $exclude_tree ), array( $root_id, $sibling_id, $draft_id ) )
					&& array( $child_id, $grand_id ) === self::ids_from_posts( $limited )
					&& self::same_id_set( self::ids_from_posts( $included ), array( $root_id, $grand_id ) )
					&& false === $non_hierarchical
					&& false === $invalid_status
					&& 1 === count( $query_events )
					&& 1 === count( $page_events )
					&& self::same_id_set( $query_events[0]['query']['post__in'] ?? array(), array( $root_id, $grand_id ) )
					&& ! isset( $query_events[0]['query']['post__not_in'], $query_events[0]['query']['post_parent'], $query_events[0]['query']['meta_key'], $query_events[0]['query']['meta_value'] )
					&& self::same_id_set( $page_events[0]['ids'] ?? array(), array( $root_id, $grand_id ) )
					&& false === \has_filter( 'get_pages_query_args', $query_filter )
					&& false === \has_filter( 'get_pages', $pages_filter ),
				'get_pages enforces hierarchical types/statuses, child/parent/exclude/limit/include rewrites, and scoped filters',
				array(
					'allPages'          => self::ids_from_posts( $all_pages ),
					'rootDescendants'   => self::ids_from_posts( $root_descendants ),
					'directChildren'    => self::ids_from_posts( $direct_children ),
					'excludeTree'       => self::ids_from_posts( $exclude_tree ),
					'limited'           => self::ids_from_posts( $limited ),
					'included'          => self::ids_from_posts( $included ),
					'nonHierarchical'   => $non_hierarchical,
					'invalidStatus'     => $invalid_status,
					'queryEvents'       => $query_events,
					'pageEvents'        => $page_events,
					'queryFilter'       => \has_filter( 'get_pages_query_args', $query_filter ),
					'pagesFilter'       => \has_filter( 'get_pages', $pages_filter ),
				)
			);

			$numeric_children = \get_children( $root_id, OBJECT );
			$object_children  = \get_children( (object) array( 'post_parent' => $root_id ), ARRAY_A );
			$GLOBALS['post']  = \get_post( $grand_id );
			$global_children  = \get_children();
			$field_children   = \get_children(
				array(
					'post_parent' => $root_id,
					'post_type'   => 'page',
					'post_status' => array( 'publish', 'draft' ),
					'fields'      => 'ids',
				)
			);
			unset( $GLOBALS['post'] );
			$empty_global_children = \get_children();

			self::collect_failure(
				$failures,
				self::all_wp_posts( array_values( $numeric_children ) )
					&& self::all_arrays_with_id( array_values( $object_children ) )
					&& self::all_wp_posts( array_values( $global_children ) )
					&& self::all_ints( $field_children )
					&& self::same_id_set( array_keys( $numeric_children ), array( $child_id, $sibling_id, $draft_id, $attachment_collision_id ) )
					&& self::same_id_set( array_keys( $object_children ), array( $child_id, $sibling_id, $draft_id, $attachment_collision_id ) )
					&& isset( $object_children[ $child_id ]['ID'] )
					&& self::same_id_set( array_keys( $global_children ), array( $grand_id ) )
					&& self::same_id_set( $field_children, array( $child_id, $sibling_id, $draft_id ) )
					&& array() === $empty_global_children,
				'get_children normalizes numeric/object/global args, keys object and array outputs by child ID, passes through fields, and fails closed without a global post',
				array(
					'numericChildren'     => array_keys( $numeric_children ),
					'objectChildren'      => array_keys( $object_children ),
					'globalChildren'      => array_keys( $global_children ),
					'fieldChildren'       => $field_children,
					'emptyGlobalChildren' => $empty_global_children,
				)
			);
		} finally {
			\remove_filter( 'get_pages_query_args', $query_filter, 10 );
			\remove_filter( 'get_pages', $pages_filter, 10 );

			if ( $previous_post_exists ) {
				$GLOBALS['post'] = $previous_post;
			} else {
				unset( $GLOBALS['post'] );
			}

			foreach ( array_reverse( array_unique( array_filter( $post_ids, 'is_int' ) ) ) as $post_id ) {
				if ( \get_post( $post_id ) instanceof \WP_Post ) {
					\wp_delete_post( $post_id, true );
				}
			}

			unset( $GLOBALS['wp_post_types'][ $tree_type ], $GLOBALS['wp_post_types'][ $flat_type ] );
		}

		return $ctx->result(
			'content-lifecycle.pages.lookup-helpers',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_set_post_type_mutation_and_cache_cleanup( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures      = array();
		$events        = array();
		$post_ids      = array();
		$source_type   = $case['postType'];
		$filtered_type = 'cf_set_' . substr( $case['token'], 0, 8 );
		$raw_type      = 'Raw Type ' . $case['token'] . ' / <script>';
		$author_id     = self::insert_support_user( 'set-post-type-' . $case['token'], 'set-post-type-' . $case['token'] . '@example.test' );
		$pre_filter    = static function ( $value ) use ( &$events, $filtered_type ) {
			$events[] = array(
				'hook'  => 'pre_post_type',
				'value' => (string) $value,
			);

			return $filtered_type;
		};
		$save_filter   = static function ( $value ) use ( &$events ) {
			$events[] = array(
				'hook'  => 'type_save_pre',
				'value' => (string) $value,
			);

			return $value;
		};
		$clean_action  = static function ( int $post_id, \WP_Post $post ) use ( &$events ): void {
			$events[] = array(
				'hook'   => 'clean_post_cache',
				'postId' => $post_id,
				'type'   => (string) $post->post_type,
			);
		};
		$page_action   = static function ( int $post_id ) use ( &$events ): void {
			$events[] = array(
				'hook'   => 'clean_page_cache',
				'postId' => $post_id,
			);
		};

		try {
			\register_post_type(
				$source_type,
				array(
					'public'    => true,
					'rewrite'   => false,
					'query_var' => false,
					'supports'  => array( 'title', 'editor' ),
				)
			);

			$main_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => $source_type,
						'post_title'   => 'Set post type ' . $case['title'],
						'post_content' => 'set-post-type main ' . $case['token'],
						'post_status'  => 'publish',
						'post_author'  => $author_id,
						'post_name'    => 'set-main-' . $case['token'],
					)
				),
				true,
				true
			);
			$page_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => $source_type,
						'post_title'   => 'Page branch ' . $case['title'],
						'post_content' => 'set-post-type page ' . $case['token'],
						'post_status'  => 'draft',
						'post_author'  => $author_id,
						'post_name'    => 'set-page-' . $case['token'],
					)
				),
				true,
				true
			);
			$sibling_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => $source_type,
						'post_title'   => 'Sibling ' . $case['title'],
						'post_content' => 'set-post-type sibling ' . $case['token'],
						'post_status'  => 'private',
						'post_author'  => $author_id,
						'post_name'    => 'set-sibling-' . $case['token'],
					)
				),
				true,
				true
			);

			foreach ( array( $main_id, $page_id, $sibling_id ) as $post_id ) {
				if ( is_int( $post_id ) && $post_id > 0 ) {
					$post_ids[] = $post_id;
				}
			}

			if ( \is_wp_error( $main_id ) || \is_wp_error( $page_id ) || \is_wp_error( $sibling_id ) ) {
				self::collect_failure(
					$failures,
					false,
					'set_post_type fixtures insert without WP_Error',
					array(
						'main'    => self::error_summary( $main_id ),
						'page'    => self::error_summary( $page_id ),
						'sibling' => self::error_summary( $sibling_id ),
					)
				);

				return $ctx->result(
					'content-lifecycle.posts.set-post-type-mutation-cache',
					false,
					array(
						'case'     => self::case_summary( $case ),
						'failures' => array_slice( $failures, 0, 6 ),
					)
				);
			}

			$cached_before = \get_post( $main_id );
			\wp_cache_set( 'post_parent:' . (string) $main_id, 'stale-parent-' . $case['token'], 'posts' );
			\wp_cache_set( $main_id, 'stale-meta-' . $case['token'], 'post_meta' );
			\wp_cache_set( 'wp_get_archives', 'stale-archives-' . $case['token'], 'general' );

			\add_filter( 'pre_post_type', $pre_filter );
			\add_filter( 'type_save_pre', $save_filter );
			\add_action( 'clean_post_cache', $clean_action, 10, 2 );
			\add_action( 'clean_page_cache', $page_action, 10, 1 );

			$main_return       = \set_post_type( $main_id, $raw_type );
			$post_cache_after  = \wp_cache_get( $main_id, 'posts' );
			$parent_cache_after = \wp_cache_get( 'post_parent:' . (string) $main_id, 'posts' );
			$meta_cache_after  = \wp_cache_get( $main_id, 'post_meta' );
			$archive_after     = \wp_cache_get( 'wp_get_archives', 'general' );
			$main_after        = \get_post( $main_id );
			$sibling_after     = \get_post( $sibling_id );

			\remove_filter( 'pre_post_type', $pre_filter, 10 );
			\remove_filter( 'type_save_pre', $save_filter, 10 );

			\wp_cache_set( 'all_page_ids', array( $page_id, 123456 ), 'posts' );
			\wp_cache_delete( $page_id, 'posts' );
			$page_return        = \set_post_type( $page_id, 'page' );
			$page_after         = \get_post( $page_id );
			$all_page_ids_after = \wp_cache_get( 'all_page_ids', 'posts' );

			$missing_id     = 987654321;
			$events_before  = count( $events );
			$missing_return = \set_post_type( $missing_id, 'post' );
			$missing_events = array_slice( $events, $events_before );

			$pre_events   = array_values(
				array_filter(
					$events,
					static function ( array $event ): bool {
						return 'pre_post_type' === ( $event['hook'] ?? '' );
					}
				)
			);
			$save_events  = array_values(
				array_filter(
					$events,
					static function ( array $event ): bool {
						return 'type_save_pre' === ( $event['hook'] ?? '' );
					}
				)
			);
			$clean_events = array_values(
				array_filter(
					$events,
					static function ( array $event ): bool {
						return 'clean_post_cache' === ( $event['hook'] ?? '' );
					}
				)
			);
			$page_events  = array_values(
				array_filter(
					$events,
					static function ( array $event ): bool {
						return 'clean_page_cache' === ( $event['hook'] ?? '' );
					}
				)
			);

			self::collect_failure(
				$failures,
				1 === $main_return
					&& $main_after instanceof \WP_Post
					&& $filtered_type === $main_after->post_type
					&& $cached_before instanceof \WP_Post
					&& $source_type === $cached_before->post_type
					&& false === $post_cache_after
					&& false === $parent_cache_after
					&& false === $meta_cache_after
					&& false === $archive_after,
				'set_post_type() writes the filtered post type and evicts stale post, parent, meta, and archive caches',
				array(
					'return'      => $main_return,
					'before'      => self::post_summary( $cached_before ),
					'after'       => self::post_summary( $main_after ),
					'postCache'   => $post_cache_after,
					'parentCache' => $parent_cache_after,
					'metaCache'   => $meta_cache_after,
					'archives'    => $archive_after,
				)
			);

			self::collect_failure(
				$failures,
				array( array( 'hook' => 'pre_post_type', 'value' => $raw_type ) ) === $pre_events
					&& array( array( 'hook' => 'type_save_pre', 'value' => $filtered_type ) ) === $save_events
					&& false === \has_filter( 'pre_post_type', $pre_filter )
					&& false === \has_filter( 'type_save_pre', $save_filter ),
				'set_post_type() passes the raw target through pre_post_type and the filtered target through type_save_pre exactly once, then filter cleanup holds',
				array(
					'preEvents'  => $pre_events,
					'saveEvents' => $save_events,
					'preFilter'  => \has_filter( 'pre_post_type', $pre_filter ),
					'saveFilter' => \has_filter( 'type_save_pre', $save_filter ),
				)
			);

			self::collect_failure(
				$failures,
				1 === $page_return
					&& $page_after instanceof \WP_Post
					&& 'page' === $page_after->post_type
					&& false === $all_page_ids_after
					&& array( array( 'hook' => 'clean_page_cache', 'postId' => $page_id ) ) === $page_events,
				'set_post_type() to page follows the page-specific cache cleanup branch when no stale post object is cached',
				array(
					'return'      => $page_return,
					'after'       => self::post_summary( $page_after ),
					'allPageIds'  => $all_page_ids_after,
					'pageEvents'  => $page_events,
				)
			);

			self::collect_failure(
				$failures,
				$sibling_after instanceof \WP_Post
					&& $source_type === $sibling_after->post_type
					&& 0 === $missing_return
					&& array() === $missing_events
					&& 2 === count( $clean_events )
					&& $main_id === ( $clean_events[0]['postId'] ?? null )
					&& $page_id === ( $clean_events[1]['postId'] ?? null ),
				'set_post_type() updates only matching IDs, leaves siblings untouched, and missing IDs fail closed without cache-clean actions',
				array(
					'sibling'       => self::post_summary( $sibling_after ),
					'missingReturn' => $missing_return,
					'missingEvents' => $missing_events,
					'cleanEvents'   => $clean_events,
				)
			);
		} finally {
			\remove_filter( 'pre_post_type', $pre_filter, 10 );
			\remove_filter( 'type_save_pre', $save_filter, 10 );
			\remove_action( 'clean_post_cache', $clean_action, 10 );
			\remove_action( 'clean_page_cache', $page_action, 10 );

			foreach ( array_unique( $post_ids ) as $post_id ) {
				if ( \get_post( $post_id ) instanceof \WP_Post ) {
					\wp_delete_post( $post_id, true );
				}
			}
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_post_type', $pre_filter )
				&& false === \has_filter( 'type_save_pre', $save_filter )
				&& false === \has_action( 'clean_post_cache', $clean_action )
				&& false === \has_action( 'clean_page_cache', $page_action ),
			'set_post_type filters and cache-clean observers are removed after the row',
			array(
				'preFilter'   => \has_filter( 'pre_post_type', $pre_filter ),
				'saveFilter'  => \has_filter( 'type_save_pre', $save_filter ),
				'cleanAction' => \has_action( 'clean_post_cache', $clean_action ),
				'pageAction'  => \has_action( 'clean_page_cache', $page_action ),
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.set-post-type-mutation-cache',
			array() === $failures,
			array(
				'case'         => self::case_summary( $case ),
				'filteredType' => $filtered_type,
				'events'       => array_slice( $events, 0, 8 ),
				'failures'     => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_status_transition_hooks( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$hook_failures    = array();
		$storage_failures = array();
		$cache_failures   = array();
		$side_failures    = array();
		$events           = array();
		$hooks            = array();
		$post_id          = 0;
		$post_type        = self::status_transition_post_type( $case );

		try {
			\register_post_type(
				$post_type,
				array(
					'public'    => true,
					'rewrite'   => false,
					'query_var' => false,
					'supports'  => array( 'title' ),
				)
			);

			$post_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'     => $post_type,
						'post_title'    => 'Status transition ' . $case['token'],
						'post_content'  => 'Status transition content ' . $case['token'],
						'post_status'   => 'draft',
						'post_name'     => 'status-transition-' . $case['token'],
						'post_date'     => '2021-02-03 04:05:06',
						'post_date_gmt' => '2021-02-03 04:05:06',
						'guid'          => 'http://example.test/status-transition-' . $case['token'],
					)
				),
				true,
				false
			);
			$post    = \get_post( $post_id );

			if ( ! is_int( $post_id ) || ! $post instanceof \WP_Post ) {
				$details = array(
					'postId' => self::error_summary( $post_id ),
					'post'   => self::post_summary( $post ),
				);
				self::collect_failure( $hook_failures, false, 'status transition host post inserted', $details );
				self::collect_failure( $storage_failures, false, 'status transition host post inserted', $details );
				self::collect_failure( $cache_failures, false, 'status transition host post inserted', $details );
				self::collect_failure( $side_failures, false, 'status transition host post inserted', $details );
			} else {
				$transitions = array(
					array( 'draft', 'publish' ),
					array( 'publish', 'publish' ),
					array( 'publish', 'trash' ),
					array( 'future', 'draft' ),
				);

				if ( false === \has_action( 'transition_post_status', '_transition_post_status' ) ) {
					\add_action( 'transition_post_status', '_transition_post_status', 5, 3 );
					$hooks[] = array( 'transition_post_status', '_transition_post_status', 5 );
				}

				$hooks = array_merge( $hooks, self::install_post_status_transition_hooks( $post_type, $transitions, $events ) );

				$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'guid' => '' ), array( 'ID' => $post_id ) );
				\clean_post_cache( $post_id );
				$post = \get_post( $post_id );

				foreach ( $transitions as $transition_index => $transition ) {
					list( $old_status, $new_status ) = $transition;
					$label           = $old_status . '_to_' . $new_status;
					$event_offset    = count( $events );
					$transition_post = clone $post;
					$transition_post->post_status = $old_status;
					$cron_timestamp  = 2000000000 + $transition_index;
					$scheduled       = \wp_schedule_single_event( $cron_timestamp, 'publish_future_post', array( $post_id ) );
					$next_before     = \wp_next_scheduled( 'publish_future_post', array( $post_id ) );
					$guid_before     = $transition_post instanceof \WP_Post ? $transition_post->guid : null;

					self::seed_transition_caches( $post_type, $label );
					\wp_transition_post_status( $new_status, $old_status, $transition_post );

					$transition_events = array_slice( $events, $event_offset );
					$stored_after      = \get_post( $post_id );
					$next_after        = \wp_next_scheduled( 'publish_future_post', array( $post_id ) );

					self::collect_failure(
						$hook_failures,
						self::transition_events_match( $transition_events, $old_status, $new_status, $post_id, $post_type ),
						'status transition hooks fire in generic, old-to-new, and new-status-type order',
						array(
							'label'  => $label,
							'events' => $transition_events,
						)
					);
					self::collect_failure(
						$storage_failures,
						$stored_after instanceof \WP_Post && 'draft' === $stored_after->post_status,
						'direct wp_transition_post_status does not mutate stored post status',
						array(
							'label'        => $label,
							'storedStatus' => $stored_after instanceof \WP_Post ? $stored_after->post_status : null,
						)
					);
					self::collect_failure(
						$cache_failures,
						self::transition_caches_match_expectations( $post_type, $old_status, $new_status, $label ),
						'status transition cache invalidation follows publish and status-change branches',
						array(
							'label'  => $label,
							'caches' => self::transition_cache_summary( $post_type ),
						)
					);
					self::collect_failure(
						$side_failures,
						true === $scheduled
							&& $cron_timestamp === $next_before
							&& false === $next_after,
						'status transition clears scheduled publish_future_post hooks for the post',
						array(
							'label'       => $label,
							'scheduled'   => $scheduled,
							'nextBefore'  => $next_before,
							'nextAfter'   => $next_after,
							'timestamp'   => $cron_timestamp,
						)
					);

					if ( 'draft' === $old_status && 'publish' === $new_status ) {
						\clean_post_cache( $post_id );
						$guid_after = \get_post( $post_id );
						self::collect_failure(
							$side_failures,
							'' === $guid_before
								&& $guid_after instanceof \WP_Post
								&& \get_permalink( $post_id ) === $guid_after->guid,
							'empty post GUID is reset when transitioning into publish',
							array(
								'label'        => $label,
								'guidBefore'   => $guid_before,
								'guidAfter'    => self::post_summary( $guid_after ),
								'expectedGuid' => \get_permalink( $post_id ),
							)
						);
					}
				}
			}
		} finally {
			self::remove_hooks( $hooks );
			if ( is_int( $post_id ) && $post_id > 0 ) {
				\wp_delete_post( $post_id, true );
				\wp_clear_scheduled_hook( 'publish_future_post', array( $post_id ) );
			}
			self::clear_transition_caches( $post_type );
		}

		$data = array(
			'case'     => self::case_summary( $case ),
			'postType' => $post_type,
			'events'   => array_slice( $events, 0, 18 ),
		);

		return array(
			$ctx->result(
				'content-lifecycle.posts.status-transition-hook-order',
				array() === $hook_failures,
				array_merge( $data, array( 'failures' => array_slice( $hook_failures, 0, 6 ) ) )
			),
			$ctx->result(
				'content-lifecycle.posts.status-transition-does-not-mutate-storage',
				array() === $storage_failures,
				array_merge( $data, array( 'failures' => array_slice( $storage_failures, 0, 6 ) ) )
			),
			$ctx->result(
				'content-lifecycle.posts.status-transition-cache-branches',
				array() === $cache_failures,
				array_merge( $data, array( 'failures' => array_slice( $cache_failures, 0, 6 ) ) )
			),
			$ctx->result(
				'content-lifecycle.posts.status-transition-default-side-effects',
				array() === $side_failures,
				array_merge( $data, array( 'failures' => array_slice( $side_failures, 0, 6 ) ) )
			),
		);
	}

	private static function check_post_page_transition_render_visibility( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures          = array();
		$events            = array();
		$cap_events        = array();
		$hooks             = array();
		$render_events     = array();
		$post_id           = 0;
		$page_id           = 0;
		$user_id           = 0;
		$viewer_id         = 0;
		$cap_filters       = array();
		$post_class_filter = null;
		$token             = $case['token'];
		$previous_post_set = array_key_exists( 'post', $GLOBALS );
		$previous_post     = $GLOBALS['post'] ?? null;
		$cookie_key        = defined( 'COOKIEHASH' ) ? 'wp-postpass_' . COOKIEHASH : null;
		$cookie_had        = null !== $cookie_key && array_key_exists( $cookie_key, $_COOKIE );
		$cookie_previous   = null !== $cookie_key ? ( $_COOKIE[ $cookie_key ] ?? null ) : null;
		$previous_user_id  = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0;
		$hooks_removed     = false;
		$filters_removed   = false;
		$cap_removed       = false;
		$globals_restored  = false;

		$get_counts = static function (): array {
			return isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();
		};

		$event_for_post = static function ( string $hook, $post, array $extra = array() ) use ( &$events ): void {
			$post_obj = $post instanceof \WP_Post ? $post : \get_post( $post );
			$events[] = array_merge(
				array(
					'hook'       => $hook,
					'postId'     => $post_obj instanceof \WP_Post ? (int) $post_obj->ID : 0,
					'postType'   => $post_obj instanceof \WP_Post ? $post_obj->post_type : null,
					'postStatus' => $post_obj instanceof \WP_Post ? $post_obj->post_status : null,
				),
				$extra
			);
		};

		$add = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};

		try {
			$user_id = \wp_insert_user(
				array(
					'user_login'   => 'cf_transition_' . $token,
					'user_pass'    => 'transition-pass-' . $token,
					'user_email'   => 'transition-' . $token . '@example.test',
					'display_name' => 'Transition User ' . $token,
					'role'         => 'editor',
				)
			);
			$viewer_id = \wp_insert_user(
				array(
					'user_login'   => 'cf_transition_viewer_' . $token,
					'user_pass'    => 'transition-viewer-pass-' . $token,
					'user_email'   => 'transition-viewer-' . $token . '@example.test',
					'display_name' => 'Transition Viewer ' . $token,
					'role'         => 'editor',
				)
			);
			$post_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => 'post',
						'post_title'     => 'Transition Render Post ' . $token,
						'post_content'   => 'Transition render post content ' . $token,
						'post_excerpt'   => 'Transition render excerpt ' . $token,
						'post_status'    => 'draft',
						'post_name'      => 'transition-render-post-' . $token,
						'post_author'    => is_int( $user_id ) ? $user_id : 0,
						'post_password'  => 'secret-' . $token,
						'post_date'      => '2022-08-09 10:11:12',
						'post_date_gmt'  => '2022-08-09 10:11:12',
						'guid'           => '',
						'comment_status' => 'open',
						'ping_status'    => 'closed',
					)
				),
				true,
				false
			);
			$page_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => 'page',
						'post_title'     => 'Transition Render Page ' . $token,
						'post_content'   => 'Transition render page content ' . $token,
						'post_status'    => 'publish',
						'post_name'      => 'transition-render-page-' . $token,
						'post_author'    => is_int( $user_id ) ? $user_id : 0,
						'post_date'      => '2022-09-10 11:12:13',
						'post_date_gmt'  => '2022-09-10 11:12:13',
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					)
				),
				true,
				false
			);

			$fixtures_ok = is_int( $user_id )
				&& $user_id > 0
				&& is_int( $viewer_id )
				&& $viewer_id > 0
				&& is_int( $post_id )
				&& $post_id > 0
				&& is_int( $page_id )
				&& $page_id > 0;

			self::collect_failure(
				$failures,
				$fixtures_ok,
				'post/page transition render fixtures insert with integer IDs',
				array(
					'userId'   => self::error_summary( $user_id ),
					'viewerId' => self::error_summary( $viewer_id ),
					'postId'   => self::error_summary( $post_id ),
					'pageId'   => self::error_summary( $page_id ),
				)
			);

			if ( $fixtures_ok ) {
				if ( false === \has_action( 'transition_post_status', '_transition_post_status' ) ) {
					\add_action( 'transition_post_status', '_transition_post_status', 5, 3 );
					$hooks[] = array( 'transition_post_status', '_transition_post_status', 5 );
				}

				$add(
					'transition_post_status',
					static function ( string $new_status, string $old_status, \WP_Post $post ) use ( $event_for_post ): void {
						$event_for_post(
							'transition_post_status',
							$post,
							array(
								'new' => $new_status,
								'old' => $old_status,
							)
						);
					},
					3
				);
				$add(
					'draft_to_publish',
					static function ( \WP_Post $post ) use ( $event_for_post ): void {
						$event_for_post(
							'draft_to_publish',
							$post,
							array(
								'new' => 'publish',
								'old' => 'draft',
							)
						);
					},
					1
				);
				$add(
					'publish_to_private',
					static function ( \WP_Post $post ) use ( $event_for_post ): void {
						$event_for_post(
							'publish_to_private',
							$post,
							array(
								'new' => 'private',
								'old' => 'publish',
							)
						);
					},
					1
				);
				foreach ( array( 'publish_post', 'private_page' ) as $dynamic_hook ) {
					$add(
						$dynamic_hook,
						static function ( $post_id, \WP_Post $post, $old_status ) use ( $event_for_post, $dynamic_hook ): void {
							unset( $post_id );
							$event_for_post(
								$dynamic_hook,
								$post,
								array(
									'new' => str_starts_with( $dynamic_hook, 'publish_' ) ? 'publish' : 'private',
									'old' => (string) $old_status,
								)
							);
						},
						3
					);
				}
				foreach ( array( 'edit_post_post', 'edit_post_page' ) as $edit_hook ) {
					$add(
						$edit_hook,
						static function ( $post_id, \WP_Post $post ) use ( $event_for_post, $edit_hook ): void {
							unset( $post_id );
							$event_for_post( $edit_hook, $post );
						},
						2
					);
				}
				$add(
					'edit_post',
					static function ( $post_id, \WP_Post $post ) use ( $event_for_post ): void {
						unset( $post_id );
						$event_for_post( 'edit_post', $post );
					},
					2
				);
				$add(
					'post_updated',
					static function ( $post_id, \WP_Post $post_after, \WP_Post $post_before ) use ( $event_for_post ): void {
						unset( $post_id );
						$event_for_post(
							'post_updated',
							$post_after,
							array(
								'beforeStatus' => $post_before->post_status,
							)
						);
					},
					3
				);
				foreach ( array( 'save_post_post', 'save_post_page' ) as $save_hook ) {
					$add(
						$save_hook,
						static function ( $post_id, \WP_Post $post, $update ) use ( $event_for_post, $save_hook ): void {
							unset( $post_id );
							$event_for_post( $save_hook, $post, array( 'update' => (bool) $update ) );
						},
						3
					);
				}
				foreach ( array( 'save_post', 'wp_insert_post' ) as $save_hook ) {
					$add(
						$save_hook,
						static function ( $post_id, \WP_Post $post, $update ) use ( $event_for_post, $save_hook ): void {
							unset( $post_id );
							$event_for_post( $save_hook, $post, array( 'update' => (bool) $update ) );
						},
						3
					);
				}
				$add(
					'wp_after_insert_post',
					static function ( $post, $update, $post_before ) use ( $event_for_post ): void {
						$event_for_post(
							'wp_after_insert_post',
							$post,
							array(
								'update'       => (bool) $update,
								'beforeStatus' => $post_before instanceof \WP_Post ? $post_before->post_status : null,
							)
						);
					},
					3
				);

				$counts_before_transition = $get_counts();
				\wp_publish_post( $post_id );
				$page_update = \wp_update_post(
					array(
						'ID'          => $page_id,
						'post_status' => 'private',
						'post_title'  => 'Private Transition Render Page ' . $token,
					),
					true,
					true
				);
				$counts_after_transition = $get_counts();
				$published_post          = \get_post( $post_id );
				$private_page            = \get_post( $page_id );

				self::collect_failure(
					$failures,
					$page_id === $page_update
						&& $post_id === ( $published_post instanceof \WP_Post ? (int) $published_post->ID : 0 )
						&& $page_id === ( $private_page instanceof \WP_Post ? (int) $private_page->ID : 0 )
						&& 'publish' === \get_post_status( $post_id )
						&& 'private' === \get_post_status( $page_id )
						&& $published_post instanceof \WP_Post
						&& '' !== $published_post->guid
						&& $private_page instanceof \WP_Post
						&& 'Private Transition Render Page ' . $token === $private_page->post_title
						&& \is_post_publicly_viewable( $post_id )
						&& ! \is_post_publicly_viewable( $page_id ),
					'wp_publish_post and wp_update_post persist generated post/page status transitions and visibility state',
					array(
						'pageUpdate' => self::error_summary( $page_update ),
						'post'       => self::post_summary( $published_post ),
						'page'       => self::post_summary( $private_page ),
						'postStatus' => \get_post_status( $post_id ),
						'pageStatus' => \get_post_status( $page_id ),
					)
				);

				self::collect_failure(
					$failures,
					self::transition_render_events_include_sequence(
						$events,
						array(
							array( 'hook' => 'transition_post_status', 'postId' => $post_id, 'postType' => 'post', 'old' => 'draft', 'new' => 'publish' ),
							array( 'hook' => 'draft_to_publish', 'postId' => $post_id, 'postType' => 'post', 'old' => 'draft', 'new' => 'publish' ),
							array( 'hook' => 'publish_post', 'postId' => $post_id, 'postType' => 'post', 'old' => 'draft', 'new' => 'publish' ),
							array( 'hook' => 'edit_post_post', 'postId' => $post_id, 'postType' => 'post' ),
							array( 'hook' => 'edit_post', 'postId' => $post_id, 'postType' => 'post' ),
							array( 'hook' => 'save_post_post', 'postId' => $post_id, 'postType' => 'post', 'update' => true ),
							array( 'hook' => 'save_post', 'postId' => $post_id, 'postType' => 'post', 'update' => true ),
							array( 'hook' => 'wp_insert_post', 'postId' => $post_id, 'postType' => 'post', 'update' => true ),
							array( 'hook' => 'wp_after_insert_post', 'postId' => $post_id, 'postType' => 'post', 'update' => true ),
						)
					)
						&& self::transition_render_events_include_sequence(
							$events,
							array(
								array( 'hook' => 'transition_post_status', 'postId' => $page_id, 'postType' => 'page', 'old' => 'publish', 'new' => 'private' ),
								array( 'hook' => 'publish_to_private', 'postId' => $page_id, 'postType' => 'page', 'old' => 'publish', 'new' => 'private' ),
								array( 'hook' => 'private_page', 'postId' => $page_id, 'postType' => 'page', 'old' => 'publish', 'new' => 'private' ),
								array( 'hook' => 'edit_post_page', 'postId' => $page_id, 'postType' => 'page' ),
								array( 'hook' => 'edit_post', 'postId' => $page_id, 'postType' => 'page' ),
								array( 'hook' => 'post_updated', 'postId' => $page_id, 'postType' => 'page', 'beforeStatus' => 'publish' ),
								array( 'hook' => 'save_post_page', 'postId' => $page_id, 'postType' => 'page', 'update' => true ),
								array( 'hook' => 'save_post', 'postId' => $page_id, 'postType' => 'page', 'update' => true ),
								array( 'hook' => 'wp_insert_post', 'postId' => $page_id, 'postType' => 'page', 'update' => true ),
								array( 'hook' => 'wp_after_insert_post', 'postId' => $page_id, 'postType' => 'page', 'update' => true ),
							)
						),
					'stored post/page transitions fire transition, dynamic status, edit, save, insert, and after-insert hooks in order',
					array( 'events' => $events )
				);

				self::collect_failure(
					$failures,
					$counts_before_transition === $counts_after_transition,
					'post/page status transitions update rows without creating or deleting content records',
					array(
						'before' => $counts_before_transition,
						'after'  => $counts_after_transition,
					)
				);

				$event_count_before_noop = count( $events );
				$counts_before_noop      = $get_counts();
				\wp_publish_post( $post_id );
				self::collect_failure(
					$failures,
					$event_count_before_noop === count( $events )
						&& $counts_before_noop === $get_counts()
						&& 'publish' === \get_post_status( $post_id ),
					'wp_publish_post on an already-published post is a no-op for hooks, counts, and stored status',
					array(
						'eventsBefore' => $event_count_before_noop,
						'eventsAfter'  => count( $events ),
						'countsBefore' => $counts_before_noop,
						'countsAfter'  => $get_counts(),
						'status'       => \get_post_status( $post_id ),
					)
				);

				if ( null !== $cookie_key ) {
					unset( $_COOKIE[ $cookie_key ] );
				}

				$post_class_filter = static function ( array $classes, array $css_class, $post_id ) use ( &$render_events, $token ): array {
					$render_events[] = array(
						'postId'   => (int) $post_id,
						'classes'  => $classes,
						'requested' => $css_class,
					);
					$classes[]       = 'cfz-rendered-' . $token;
					return $classes;
				};
				\add_filter( 'post_class', $post_class_filter, 10, 3 );

				$counts_before_render = $get_counts();
				$GLOBALS['post']      = $published_post;
				$post_classes         = \get_post_class( array( 'cfz-requested-' . $token ), $post_id );
				$GLOBALS['post']      = $private_page;
				$page_classes         = \get_post_class( array( 'cfz-page-' . $token ), $page_id );

				\wp_set_current_user( $viewer_id );

				$private_denied_filter = self::grant_caps_except_filter( $viewer_id, array( 'read_private_pages' ), $cap_events );
				\add_filter( 'user_has_cap', $private_denied_filter, 10, 4 );
				$cap_filters[]          = array( 'user_has_cap', $private_denied_filter, 10 );
				$denied_read_page       = \current_user_can( 'read_post', $page_id );
				$denied_read_post       = \current_user_can( 'read_post', $post_id );
				\remove_filter( 'user_has_cap', $private_denied_filter, 10 );

				$all_caps_filter = self::grant_caps_except_filter( $viewer_id, array(), $cap_events );
				\add_filter( 'user_has_cap', $all_caps_filter, 10, 4 );
				$cap_filters[]       = array( 'user_has_cap', $all_caps_filter, 10 );
				$all_caps_read_page = \current_user_can( 'read_post', $page_id );
				$all_caps_read_post = \current_user_can( 'read_post', $post_id );
				$counts_after_render = $get_counts();

				self::collect_failure(
					$failures,
					in_array( 'post-' . $post_id, $post_classes, true )
						&& in_array( 'post', $post_classes, true )
						&& in_array( 'type-post', $post_classes, true )
						&& in_array( 'status-publish', $post_classes, true )
						&& in_array( 'post-password-required', $post_classes, true )
						&& in_array( 'hentry', $post_classes, true )
						&& in_array( 'cfz-requested-' . $token, $post_classes, true )
						&& in_array( 'cfz-rendered-' . $token, $post_classes, true )
						&& in_array( 'post-' . $page_id, $page_classes, true )
						&& in_array( 'page', $page_classes, true )
						&& in_array( 'type-page', $page_classes, true )
						&& in_array( 'status-private', $page_classes, true )
						&& in_array( 'cfz-page-' . $token, $page_classes, true )
						&& in_array( 'cfz-rendered-' . $token, $page_classes, true ),
					'get_post_class renders stored publish/private status and password state through filterable classes',
					array(
						'postClasses'  => $post_classes,
						'pageClasses'  => $page_classes,
						'renderEvents' => $render_events,
					)
				);

				self::collect_failure(
					$failures,
					true === $denied_read_post
						&& false === $denied_read_page
						&& true === $all_caps_read_page
						&& true === $all_caps_read_post
						&& self::capability_event_present( $cap_events, 'read_post' )
						&& self::capability_event_present( $cap_events, 'read_private_pages' ),
					'read_post capability follows published post versus private page visibility after transition',
					array(
						'deniedPost'  => $denied_read_post,
						'deniedPage'  => $denied_read_page,
						'allCapsPost' => $all_caps_read_post,
						'allCapsPage' => $all_caps_read_page,
						'capEvents'   => $cap_events,
					)
				);

				self::collect_failure(
					$failures,
					$counts_before_render === $counts_after_render,
					'post/page render and capability probes do not mutate content tables',
					array(
						'before' => $counts_before_render,
						'after'  => $counts_after_render,
					)
				);
			}
		} finally {
			self::remove_hooks( $hooks );
			$hooks_removed = self::hooks_are_removed( $hooks );

			foreach ( $cap_filters as $cap_filter ) {
				\remove_filter( $cap_filter[0], $cap_filter[1], $cap_filter[2] );
			}
			$cap_removed = self::hooks_are_removed( $cap_filters );

			if ( null !== $post_class_filter ) {
				\remove_filter( 'post_class', $post_class_filter, 10 );
				$filters_removed = false === \has_filter( 'post_class', $post_class_filter );
			} else {
				$filters_removed = true;
			}

			\wp_set_current_user( $previous_user_id );

			if ( $previous_post_set ) {
				$GLOBALS['post'] = $previous_post;
			} else {
				unset( $GLOBALS['post'] );
			}

			if ( null !== $cookie_key ) {
				if ( $cookie_had ) {
					$_COOKIE[ $cookie_key ] = $cookie_previous;
				} else {
					unset( $_COOKIE[ $cookie_key ] );
				}
			}

			$globals_restored = ( $previous_user_id === ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0 ) )
				&& ( $previous_post_set === array_key_exists( 'post', $GLOBALS ) )
				&& ( ! $previous_post_set || $previous_post === $GLOBALS['post'] )
				&& ( null === $cookie_key || $cookie_had === array_key_exists( $cookie_key, $_COOKIE ) )
				&& ( null === $cookie_key || ! $cookie_had || $cookie_previous === $_COOKIE[ $cookie_key ] );

			if ( is_int( $post_id ) && $post_id > 0 ) {
				\wp_delete_post( $post_id, true );
			}
			if ( is_int( $page_id ) && $page_id > 0 ) {
				\wp_delete_post( $page_id, true );
			}
			if ( is_int( $user_id ) && $user_id > 0 && function_exists( 'wp_delete_user' ) ) {
				\wp_delete_user( $user_id );
			}
			if ( is_int( $viewer_id ) && $viewer_id > 0 && function_exists( 'wp_delete_user' ) ) {
				\wp_delete_user( $viewer_id );
			}
		}

		self::collect_failure(
			$failures,
			$hooks_removed && $filters_removed && $cap_removed && $globals_restored,
			'post/page transition render hooks, filters, caps, user, post, and cookie state are restored',
			array(
				'hooksRemoved'   => $hooks_removed,
				'filtersRemoved' => $filters_removed,
				'capRemoved'     => $cap_removed,
				'globalsRestored' => $globals_restored,
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.transition-render-visibility',
			array() === $failures,
			array(
				'case'         => self::case_summary( $case ),
				'postId'       => $post_id,
				'pageId'       => $page_id,
				'viewerId'     => $viewer_id,
				'events'       => array_slice( $events, 0, 28 ),
				'renderEvents' => $render_events,
				'capEvents'    => array_slice( $cap_events, 0, 12 ),
				'failures'     => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_meta_lifecycle( int $post_id, array $case, array &$events ): array {
		$key        = $case['metaKey'];
		$unique_key = $case['metaUniqueKey'];

		$first_id = \add_post_meta( $post_id, $key, \wp_slash( $case['metaValue'] ) );
		$second_id = \add_post_meta( $post_id, $key, \wp_slash( $case['metaSecondValue'] ) );
		$unique_id = \add_post_meta( $post_id, $unique_key, \wp_slash( $case['metaArrayValue'] ), true );
		$unique_duplicate = \add_post_meta( $post_id, $unique_key, \wp_slash( array( 'duplicate' => true ) ), true );

		$all_before    = \get_post_meta( $post_id, $key, false );
		$single_before = \get_post_meta( $post_id, $key, true );
		$array_before  = \get_post_meta( $post_id, $unique_key, true );
		$exists_before = \metadata_exists( 'post', $post_id, $key );

		$updated = \update_post_meta( $post_id, $key, \wp_slash( $case['metaUpdatedValue'] ), $case['metaValue'] );
		$all_after_update = \get_post_meta( $post_id, $key, false );
		$stale_absent     = ! in_array( $case['metaValue'], $all_after_update, true );

		$delete_second = \delete_post_meta( $post_id, $key, \wp_slash( $case['metaSecondValue'] ) );
		$after_value_delete = \get_post_meta( $post_id, $key, false );
		$delete_remaining   = \delete_post_meta( $post_id, $key );
		$after_key_delete   = \get_post_meta( $post_id, $key, false );
		$exists_after       = \metadata_exists( 'post', $post_id, $key );

		$meta_events = array_values(
			array_filter(
				$events,
				static function ( string $event ): bool {
					return str_contains( $event, 'post_meta' );
				}
			)
		);

		$ok = is_int( $first_id )
			&& is_int( $second_id )
			&& $second_id > $first_id
			&& is_int( $unique_id )
			&& false === $unique_duplicate
			&& true === $exists_before
			&& $case['metaValue'] === $single_before
			&& is_array( $all_before )
			&& in_array( $case['metaValue'], $all_before, true )
			&& in_array( $case['metaSecondValue'], $all_before, true )
			&& $case['metaArrayValue'] === $array_before
			&& true === $updated
			&& in_array( $case['metaUpdatedValue'], $all_after_update, true )
			&& in_array( $case['metaSecondValue'], $all_after_update, true )
			&& $stale_absent
			&& true === $delete_second
			&& array( $case['metaUpdatedValue'] ) === array_values( $after_value_delete )
			&& true === $delete_remaining
			&& array() === $after_key_delete
			&& false === $exists_after
			&& self::events_are_ordered(
				$meta_events,
				array(
					'add_post_meta',
					'added_post_meta',
					'update_post_meta',
					'updated_post_meta',
					'delete_post_meta',
					'deleted_post_meta',
				)
			);

		return array(
			'ok'                 => $ok,
			'firstId'            => $first_id,
			'secondId'           => $second_id,
			'uniqueId'           => $unique_id,
			'uniqueDuplicate'    => $unique_duplicate,
			'allBefore'          => $all_before,
			'singleBefore'       => $single_before,
			'arrayBefore'        => $array_before,
			'allAfterUpdate'     => $all_after_update,
			'afterValueDelete'   => $after_value_delete,
			'afterKeyDelete'     => $after_key_delete,
			'existsBefore'       => $exists_before,
			'existsAfter'        => $exists_after,
			'metaEvents'         => $meta_events,
			'cacheInvalidated'   => $stale_absent,
		);
	}

	private static function check_post_term_relationship_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$events   = array();
		$hooks    = self::install_term_relationship_hooks( $events );
		$post_id  = 0;
		$deleted  = false;

		try {
			$author_id = self::insert_support_user( 'term-author-' . $case['token'], 'term-author-' . $case['token'] . '@example.test' );
			$post_id   = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => 'post',
						'post_title'   => 'Term relationship ' . $case['token'],
						'post_content' => 'Relationship content ' . $case['token'],
						'post_status'  => 'publish',
						'post_author'  => $author_id,
						'post_name'    => 'term-relationship-' . $case['token'],
					)
				),
				true,
				false
			);
			$primary   = \wp_insert_term( 'Primary ' . $case['token'], 'category', array( 'slug' => 'primary-' . $case['token'] ) );
			$secondary = \wp_insert_term( 'Secondary ' . $case['token'], 'category', array( 'slug' => 'secondary-' . $case['token'] ) );
			$tag       = \wp_insert_term( 'Taggy ' . $case['token'], 'post_tag', array( 'slug' => 'taggy-' . $case['token'] ) );

			self::collect_failure(
				$failures,
				is_int( $post_id )
					&& is_array( $primary )
					&& is_array( $secondary )
					&& is_array( $tag ),
				'post and term fixtures insert before relationship checks',
				array(
					'postId'    => $post_id,
					'primary'   => $primary,
					'secondary' => $secondary,
					'tag'       => $tag,
				)
			);

			if ( ! is_int( $post_id ) || ! is_array( $primary ) || ! is_array( $secondary ) || ! is_array( $tag ) ) {
				return $ctx->result(
					'content-lifecycle.posts.term-relationships',
					false,
					array( 'failures' => $failures )
				);
			}

			$primary_term   = \get_term( (int) $primary['term_id'], 'category' );
			$secondary_term = \get_term( (int) $secondary['term_id'], 'category' );
			$tag_term       = \get_term( (int) $tag['term_id'], 'post_tag' );

			$first_tt_ids = \wp_set_object_terms(
				$post_id,
				array( (int) $primary['term_id'], (int) $secondary['term_id'] ),
				'category',
				false
			);
			$ids          = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids', 'orderby' => 'term_id' ) );
			$slugs        = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'slugs', 'orderby' => 'term_id' ) );
			$names        = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'names', 'orderby' => 'term_id' ) );
			$tt_ids       = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'tt_ids', 'orderby' => 'term_id' ) );
			$all_with_ids = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'all_with_object_id', 'orderby' => 'term_id' ) );
			$the_terms    = \get_the_terms( $post_id, 'category' );
			$has_primary  = $primary_term instanceof \WP_Term ? \has_term( $primary_term->slug, 'category', $post_id ) : false;
			$in_secondary = \is_object_in_term( $post_id, 'category', (int) $secondary['term_id'] );
			$all_terms_ok = is_array( $all_with_ids )
				&& self::term_objects_match_relationships(
					$all_with_ids,
					$post_id,
					array( (int) $primary['term_id'], (int) $secondary['term_id'] ),
					array( (int) $primary['term_taxonomy_id'], (int) $secondary['term_taxonomy_id'] )
				);

			self::collect_failure(
				$failures,
				array_values( array_map( 'intval', $first_tt_ids ) ) === array( (int) $primary['term_taxonomy_id'], (int) $secondary['term_taxonomy_id'] )
					&& array_values( array_map( 'intval', $ids ) ) === array( (int) $primary['term_id'], (int) $secondary['term_id'] )
					&& $primary_term instanceof \WP_Term
					&& $secondary_term instanceof \WP_Term
					&& array_values( $slugs ) === array( $primary_term->slug, $secondary_term->slug )
					&& array_values( $names ) === array( $primary_term->name, $secondary_term->name )
					&& array_values( array_map( 'intval', $tt_ids ) ) === array( (int) $primary['term_taxonomy_id'], (int) $secondary['term_taxonomy_id'] )
					&& true === $all_terms_ok
					&& is_array( $the_terms )
					&& 2 === count( $the_terms )
					&& true === $has_primary
					&& true === $in_secondary,
				'initial category relationships round-trip across field modes and helpers',
				array(
					'firstTtIds' => $first_tt_ids,
					'ids'        => $ids,
					'slugs'      => $slugs,
						'names'      => $names,
						'ttIds'      => $tt_ids,
						'allCount'   => is_array( $all_with_ids ) ? count( $all_with_ids ) : null,
						'allTermsOk' => $all_terms_ok,
						'theTerms'   => is_array( $the_terms ) ? count( $the_terms ) : $the_terms,
						'hasPrimary' => $has_primary,
						'inSecond'   => $in_secondary,
				)
			);

			$append_tt_ids = \wp_set_object_terms( $post_id, array( (int) $tag['term_id'] ), 'post_tag', true );
			$tag_ids       = \wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
			self::collect_failure(
				$failures,
				array_values( array_map( 'intval', $append_tt_ids ) ) === array( (int) $tag['term_taxonomy_id'] )
					&& array_values( array_map( 'intval', $tag_ids ) ) === array( (int) $tag['term_id'] )
					&& $tag_term instanceof \WP_Term
					&& true === \has_term( $tag_term->slug, 'post_tag', $post_id ),
				'append mode adds post tags without disturbing category relationships',
				array(
					'appendTtIds' => $append_tt_ids,
					'tagIds'      => $tag_ids,
					'categories'  => \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) ),
				)
			);

			$replace_tt_ids = \wp_set_object_terms( $post_id, array( (int) $secondary['term_id'] ), 'category', false );
			$ids_after_replace = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
			$primary_after_replace = $primary_term instanceof \WP_Term ? \has_term( $primary_term->slug, 'category', $post_id ) : true;
			$secondary_after_replace = $secondary_term instanceof \WP_Term ? \has_term( $secondary_term->slug, 'category', $post_id ) : false;
			self::collect_failure(
				$failures,
				array_values( array_map( 'intval', $replace_tt_ids ) ) === array( (int) $secondary['term_taxonomy_id'] )
					&& array_values( array_map( 'intval', $ids_after_replace ) ) === array( (int) $secondary['term_id'] )
					&& false === $primary_after_replace
					&& true === $secondary_after_replace,
				'replace mode removes stale category relationships and keeps requested term',
				array(
					'replaceTtIds' => $replace_tt_ids,
					'idsAfter'     => $ids_after_replace,
					'hasPrimary'   => $primary_after_replace,
					'hasSecondary' => $secondary_after_replace,
				)
				);

				$removed = \wp_remove_object_terms( $post_id, array( (int) $secondary['term_id'] ), 'category' );
				$ids_after_remove = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
				$delete_all = \wp_delete_object_term_relationships( $post_id, array( 'post_tag' ) );
				$tag_ids_after_delete = \wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
				$tag_after_delete = $tag_term instanceof \WP_Term ? \has_term( $tag_term->slug, 'post_tag', $post_id ) : true;
				self::collect_failure(
					$failures,
					true === $removed
						&& array() === array_values( $ids_after_remove )
						&& null === $delete_all
						&& array() === array_values( $tag_ids_after_delete )
						&& false === $tag_after_delete
						&& false === \is_object_in_term( $post_id, 'category', (int) $secondary['term_id'] ),
					'remove and delete relationship helpers clear taxonomy relationships',
					array(
						'removed'           => $removed,
						'idsAfterRemove'    => $ids_after_remove,
						'deleteAll'         => $delete_all,
						'tagsAfterDelete'   => $tag_ids_after_delete,
						'hasTagAfterDelete' => $tag_after_delete,
					)
				);

				$post_delete_category_tt_ids = \wp_set_object_terms( $post_id, array( (int) $primary['term_id'] ), 'category', false );
				$post_delete_tag_tt_ids      = \wp_set_object_terms( $post_id, array( (int) $tag['term_id'] ), 'post_tag', false );
				$category_terms_before_delete = \get_the_terms( $post_id, 'category' );
				$tag_terms_before_delete      = \get_the_terms( $post_id, 'post_tag' );
				$category_cache_before_delete = \wp_cache_get( $post_id, 'category_relationships' );
				$tag_cache_before_delete      = \wp_cache_get( $post_id, 'post_tag_relationships' );
				$deleted_post                 = \wp_delete_post( $post_id, true );
				$deleted                      = $deleted_post instanceof \WP_Post;
				$category_cache_after_delete  = \wp_cache_get( $post_id, 'category_relationships' );
				$tag_cache_after_delete       = \wp_cache_get( $post_id, 'post_tag_relationships' );
				$category_ids_after_post_delete = \wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
				$tag_ids_after_post_delete      = \wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
				self::collect_failure(
					$failures,
					array_values( array_map( 'intval', $post_delete_category_tt_ids ) ) === array( (int) $primary['term_taxonomy_id'] )
						&& array_values( array_map( 'intval', $post_delete_tag_tt_ids ) ) === array( (int) $tag['term_taxonomy_id'] )
						&& is_array( $category_terms_before_delete )
						&& 1 === count( $category_terms_before_delete )
						&& is_array( $tag_terms_before_delete )
						&& 1 === count( $tag_terms_before_delete )
						&& array( (int) $primary['term_id'] ) === array_values( array_map( 'intval', (array) $category_cache_before_delete ) )
						&& array( (int) $tag['term_id'] ) === array_values( array_map( 'intval', (array) $tag_cache_before_delete ) )
						&& true === $deleted
						&& false === $category_cache_after_delete
						&& false === $tag_cache_after_delete
						&& array() === array_values( $category_ids_after_post_delete )
						&& array() === array_values( $tag_ids_after_post_delete ),
					'post deletion clears remaining term relationships and relationship caches',
					array(
						'categoryTtIds'      => $post_delete_category_tt_ids,
						'tagTtIds'           => $post_delete_tag_tt_ids,
						'categoryBefore'     => is_array( $category_terms_before_delete ) ? count( $category_terms_before_delete ) : $category_terms_before_delete,
						'tagBefore'          => is_array( $tag_terms_before_delete ) ? count( $tag_terms_before_delete ) : $tag_terms_before_delete,
						'categoryCacheBefore' => $category_cache_before_delete,
						'tagCacheBefore'     => $tag_cache_before_delete,
						'deletedPost'        => $deleted,
						'categoryCacheAfter' => $category_cache_after_delete,
						'tagCacheAfter'      => $tag_cache_after_delete,
						'categoryAfter'      => $category_ids_after_post_delete,
						'tagAfter'           => $tag_ids_after_post_delete,
					)
				);

				self::collect_failure(
					$failures,
					self::term_relationship_events_match(
						$events,
						array(
							array(
								'hook'     => 'set_object_terms',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'append'   => false,
								'ttIds'    => array(),
								'oldTtIds' => array(),
							),
							array(
								'hook'     => 'add_term_relationship',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $primary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'added_term_relationship',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $primary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'add_term_relationship',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $secondary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'added_term_relationship',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $secondary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'set_object_terms',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'append'   => false,
								'ttIds'    => array( (int) $primary['term_taxonomy_id'], (int) $secondary['term_taxonomy_id'] ),
								'oldTtIds' => array(),
							),
							array(
								'hook'     => 'set_object_terms',
								'objectId' => $post_id,
								'taxonomy' => 'post_tag',
								'append'   => true,
								'ttIds'    => array( (int) $tag['term_taxonomy_id'] ),
								'oldTtIds' => array(),
							),
							array(
								'hook'     => 'delete_term_relationships',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $primary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'set_object_terms',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'append'   => false,
								'ttIds'    => array( (int) $secondary['term_taxonomy_id'] ),
								'oldTtIds' => array( (int) $primary['term_taxonomy_id'], (int) $secondary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'delete_term_relationships',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $secondary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'deleted_term_relationships',
								'objectId' => $post_id,
								'taxonomy' => 'post_tag',
								'ttIds'    => array( (int) $tag['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'add_term_relationship',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $primary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'set_object_terms',
								'objectId' => $post_id,
								'taxonomy' => 'post_tag',
								'append'   => false,
								'ttIds'    => array( (int) $tag['term_taxonomy_id'] ),
								'oldTtIds' => array(),
							),
							array(
								'hook'     => 'delete_term_relationships',
								'objectId' => $post_id,
								'taxonomy' => 'category',
								'ttIds'    => array( (int) $primary['term_taxonomy_id'] ),
							),
							array(
								'hook'     => 'deleted_term_relationships',
								'objectId' => $post_id,
								'taxonomy' => 'post_tag',
								'ttIds'    => array( (int) $tag['term_taxonomy_id'] ),
							),
						)
						)
						&& self::term_relationship_event_counts_match(
						$events,
						array(
							'add_term_relationship'     => 5,
							'added_term_relationship'   => 5,
							'delete_term_relationships' => 5,
							'deleted_term_relationships' => 5,
							'set_object_terms'          => 6,
						)
					),
					'term relationship hooks fire with expected relative order and arguments',
					array(
						'eventCounts' => self::term_relationship_event_counts( $events ),
						'events'      => $events,
					)
				);
			} finally {
				if ( ! $deleted && is_int( $post_id ) && $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
				self::remove_hooks( $hooks );
			}

			self::collect_failure(
				$failures,
				self::hooks_are_removed( $hooks ),
				'term relationship hooks are removed after lifecycle check',
				array( 'hooks' => array_map( static fn( array $hook ): string => $hook[0], $hooks ) )
			);

			return $ctx->result(
				'content-lifecycle.posts.term-relationships',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
				'events'   => array_slice( $events, 0, 20 ),
			)
		);
	}

	private static function check_term_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$cat_name = self::usable_name( $case['categoryName'], 'Category ' . $case['token'] );
		$tag_name = self::usable_name( $case['tagName'], 'Tag ' . $case['token'] );
		$cat_slug = self::usable_slug( $case['categorySlug'], 'category-' . $case['token'] );
		$tag_slug = self::usable_slug( $case['tagSlug'], 'tag-' . $case['token'] );
		$dup_name = 'Duplicate ' . $case['token'];
		$dup_slug = 'duplicate-' . $case['token'];

		$category = \wp_insert_term(
			$cat_name,
			'category',
			array(
				'slug'        => $cat_slug,
				'description' => $case['termDescription'],
			)
		);
		$tag      = \wp_insert_term(
			$tag_name,
			'post_tag',
			array(
				'slug'        => $tag_slug,
				'description' => $case['tagDescription'],
			)
		);

		$category_term = ! \is_wp_error( $category ) ? \get_term( $category['term_id'], 'category' ) : null;
		$tag_term      = ! \is_wp_error( $tag ) ? \get_term( $tag['term_id'], 'post_tag' ) : null;
		$id_terms      = \get_terms(
			array(
				'taxonomy'               => 'category',
				'include'                => is_array( $category ) ? array( (int) $category['term_id'] ) : array( 0 ),
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			)
		);
		$exists       = is_array( $category ) ? \term_exists( (int) $category['term_id'], 'category' ) : null;
		$slug_exists  = \term_exists( $cat_slug, 'category' );
		$dup_first    = \wp_insert_term( $dup_name, 'category', array( 'slug' => $dup_slug ) );
		$duplicate    = \wp_insert_term( $dup_name, 'category', array( 'slug' => $dup_slug ) );
		$invalid_tax  = \wp_insert_term( $tag_name, 'missing_tax_' . $case['token'] );
		$empty_name   = \wp_insert_term( '', 'category' );

		self::collect_failure(
			$failures,
			is_array( $category )
				&& is_array( $tag )
				&& $category['term_id'] > 0
				&& $tag['term_id'] > $category['term_id']
				&& $tag['term_taxonomy_id'] > $category['term_taxonomy_id'],
			'term and term_taxonomy IDs are assigned and monotonic',
			array( 'category' => $category, 'tag' => $tag )
		);
		self::collect_failure(
			$failures,
			$category_term instanceof \WP_Term
				&& $tag_term instanceof \WP_Term
				&& 'category' === $category_term->taxonomy
				&& 'post_tag' === $tag_term->taxonomy
				&& $cat_slug === $category_term->slug
				&& $tag_slug === $tag_term->slug,
			'term taxonomy and slug relationships round-trip',
			array(
				'categoryTerm' => self::term_summary( $category_term ),
				'tagTerm'      => self::term_summary( $tag_term ),
				'expected'     => array( $cat_slug, $tag_slug ),
			)
		);
		self::collect_failure(
			$failures,
			is_array( $exists )
				&& (string) $category['term_id'] === (string) $exists['term_id']
				&& is_array( $id_terms )
				&& isset( $id_terms[0] )
				&& (int) $id_terms[0]->term_id === (int) $category['term_id'],
			'term_exists and get_terms find inserted category',
			array(
				'exists'     => $exists,
				'slugExists' => $slug_exists,
				'idTermCount' => is_array( $id_terms ) ? count( $id_terms ) : null,
			)
		);
		self::collect_failure(
			$failures,
			$category_term instanceof \WP_Term
				&& \sanitize_term_field( 'name', $category_term->name, $category_term->term_id, 'category', 'display' )
					=== \get_term( $category_term->term_id, 'category', OBJECT, 'display' )->name,
			'term display name agrees with sanitize_term_field',
			array( 'term' => self::term_summary( $category_term ) )
		);
		self::collect_failure(
			$failures,
			is_array( $dup_first )
				&& \is_wp_error( $duplicate )
				&& 'term_exists' === $duplicate->get_error_code()
				&& (int) $dup_first['term_id'] === (int) $duplicate->get_error_data(),
			'duplicate term constraint matches WordPress term_exists error',
			array(
				'first'     => $dup_first,
				'duplicate' => self::error_summary( $duplicate ),
			)
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $invalid_tax )
				&& 'invalid_taxonomy' === $invalid_tax->get_error_code()
				&& \is_wp_error( $empty_name )
				&& 'empty_term_name' === $empty_name->get_error_code(),
			'invalid term inputs return WP_Error codes',
			array(
				'invalidTaxonomy' => self::error_summary( $invalid_tax ),
				'emptyName'       => self::error_summary( $empty_name ),
			)
		);

		return $ctx->result(
			'content-lifecycle.terms.insert-read-duplicates-invalid',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_term_update_delete_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$events   = array();
		$hooks    = self::install_term_lifecycle_hooks( $events );
		$token    = $case['token'];

		$update_parent_name = 'Update Parent ' . $token;
		$update_child_name  = 'Update Child ' . $token;
		$updated_name       = 'Updated Category ' . $token;
		$updated_desc       = 'Updated term description ' . $token;
		$update_parent_slug = self::usable_slug( 'update-parent-' . $token, 'update-parent-' . $token );
		$update_child_slug  = self::usable_slug( 'update-child-' . $token, 'update-child-' . $token );
		$updated_slug       = self::usable_slug( 'updated-category-' . $token, 'updated-category-' . $token );
		$duplicate_slug     = self::usable_slug( 'update-duplicate-' . $token, 'update-duplicate-' . $token );
		$delete_parent_slug = self::usable_slug( 'delete-parent-' . $token, 'delete-parent-' . $token );
		$delete_child_slug  = self::usable_slug( 'delete-child-' . $token, 'delete-child-' . $token );
		$hooks_removed      = false;

		try {
			$update_parent = \wp_insert_term( $update_parent_name, 'category', array( 'slug' => $update_parent_slug ) );
			$update_child  = is_array( $update_parent )
				? \wp_insert_term(
					$update_child_name,
					'category',
					array(
						'slug'   => $update_child_slug,
						'parent' => 0,
					)
				)
				: null;
			$duplicate     = \wp_insert_term( 'Update Duplicate ' . $token, 'category', array( 'slug' => $duplicate_slug ) );
			$delete_parent = \wp_insert_term( 'Delete Parent ' . $token, 'category', array( 'slug' => $delete_parent_slug ) );
			$delete_child  = is_array( $delete_parent )
				? \wp_insert_term(
					'Delete Child ' . $token,
					'category',
					array(
						'slug'   => $delete_child_slug,
						'parent' => (int) $delete_parent['term_id'],
					)
				)
				: null;

			$update_parent_id = is_array( $update_parent ) ? (int) $update_parent['term_id'] : 0;
			$update_child_id  = is_array( $update_child ) ? (int) $update_child['term_id'] : 0;
			$update_child_tt  = is_array( $update_child ) ? (int) $update_child['term_taxonomy_id'] : 0;
			$duplicate_id     = is_array( $duplicate ) ? (int) $duplicate['term_id'] : 0;
			$delete_parent_id = is_array( $delete_parent ) ? (int) $delete_parent['term_id'] : 0;
			$delete_parent_tt = is_array( $delete_parent ) ? (int) $delete_parent['term_taxonomy_id'] : 0;
			$delete_child_id  = is_array( $delete_child ) ? (int) $delete_child['term_id'] : 0;

			self::collect_failure(
				$failures,
				is_array( $update_parent )
					&& is_array( $update_child )
					&& is_array( $duplicate )
					&& is_array( $delete_parent )
					&& is_array( $delete_child ),
				'term update/delete seed terms are created',
				array(
					'updateParent' => $update_parent,
					'updateChild'  => $update_child,
					'duplicate'    => $duplicate,
					'deleteParent' => $delete_parent,
					'deleteChild'  => $delete_child,
				)
			);

			$primed_child       = $update_child_id > 0 ? \get_term( $update_child_id, 'category' ) : null;
			$updated            = $update_child_id > 0
				? \wp_update_term(
					$update_child_id,
					'category',
					array(
						'name'        => $updated_name,
						'slug'        => $updated_slug,
						'description' => $updated_desc,
						'parent'      => $update_parent_id,
					)
				)
				: null;
			$updated_term       = $update_child_id > 0 ? \get_term( $update_child_id, 'category' ) : null;
			$updated_exists     = \term_exists( $updated_slug, 'category' );
			$stale_slug_exists  = \term_exists( $update_child_slug, 'category' );
			$updated_term_query = \get_terms(
				array(
					'taxonomy'               => 'category',
					'include'                => array( $update_child_id ),
					'hide_empty'             => false,
					'update_term_meta_cache' => false,
				)
			);

			self::collect_failure(
				$failures,
				is_array( $updated )
					&& $update_child_id === (int) $updated['term_id']
					&& $update_child_tt === (int) $updated['term_taxonomy_id']
					&& $primed_child instanceof \WP_Term
					&& $updated_term instanceof \WP_Term
					&& $updated_name === $updated_term->name
					&& $updated_slug === $updated_term->slug
					&& $updated_desc === $updated_term->description
					&& $update_parent_id === (int) $updated_term->parent
					&& is_array( $updated_exists )
					&& $update_child_id === (int) $updated_exists['term_id']
					&& null === $stale_slug_exists
					&& is_array( $updated_term_query )
					&& isset( $updated_term_query[0] )
					&& $update_child_id === (int) $updated_term_query[0]->term_id,
				'wp_update_term preserves IDs and refreshes readable term fields',
				array(
					'updated'       => $updated,
					'primedChild'   => self::term_summary( $primed_child ),
					'updatedTerm'   => self::term_summary( $updated_term ),
					'updatedExists' => $updated_exists,
					'staleExists'   => $stale_slug_exists,
					'queryCount'    => is_array( $updated_term_query ) ? count( $updated_term_query ) : null,
				)
			);

			$duplicate_update = $update_child_id > 0 ? \wp_update_term( $update_child_id, 'category', array( 'slug' => $duplicate_slug ) ) : null;
			$missing_parent   = $update_child_id > 0 ? \wp_update_term( $update_child_id, 'category', array( 'parent' => 987654321 ) ) : null;
			$empty_name       = $update_child_id > 0 ? \wp_update_term( $update_child_id, 'category', array( 'name' => '' ) ) : null;
			$missing_term     = \wp_update_term( 987654321, 'category', array( 'name' => 'Missing Term ' . $token ) );
			$after_errors     = $update_child_id > 0 ? \get_term( $update_child_id, 'category' ) : null;

			self::collect_failure(
				$failures,
				\is_wp_error( $duplicate_update )
					&& 'duplicate_term_slug' === $duplicate_update->get_error_code()
					&& \is_wp_error( $missing_parent )
					&& 'missing_parent' === $missing_parent->get_error_code()
					&& \is_wp_error( $empty_name )
					&& 'empty_term_name' === $empty_name->get_error_code()
					&& \is_wp_error( $missing_term )
					&& 'invalid_term' === $missing_term->get_error_code()
					&& $after_errors instanceof \WP_Term
					&& $updated_slug === $after_errors->slug
					&& $update_parent_id === (int) $after_errors->parent,
				'wp_update_term rejects invalid updates without mutating the term',
				array(
					'duplicateTermId' => $duplicate_id,
					'duplicate'       => self::error_summary( $duplicate_update ),
					'missingParent'   => self::error_summary( $missing_parent ),
					'emptyName'       => self::error_summary( $empty_name ),
					'missingTerm'     => self::error_summary( $missing_term ),
					'afterErrors'     => self::term_summary( $after_errors ),
				)
			);

			$delete_post_id = \wp_insert_post(
				array(
					'post_title'  => 'Term Delete Post ' . $token,
					'post_status' => 'publish',
					'post_type'   => 'post',
				),
				true
			);
			$assigned       = is_int( $delete_post_id ) && $delete_post_id > 0
				? \wp_set_object_terms( $delete_post_id, array( $delete_parent_id, $delete_child_id ), 'category' )
				: null;
			$assigned_terms = is_int( $delete_post_id ) && $delete_post_id > 0
				? \wp_get_object_terms(
					$delete_post_id,
					'category',
					array(
						'fields'  => 'ids',
						'orderby' => 'none',
					)
				)
				: null;
			$deleted        = $delete_parent_id > 0 ? \wp_delete_term( $delete_parent_id, 'category' ) : null;
			$deleted_again  = \wp_delete_term( 987654321, 'category' );
			$deleted_parent = $delete_parent_id > 0 ? \get_term( $delete_parent_id, 'category' ) : null;
			$child_after    = $delete_child_id > 0 ? \get_term( $delete_child_id, 'category' ) : null;
			$parent_exists  = $delete_parent_id > 0 ? \term_exists( $delete_parent_id, 'category' ) : null;
			$slug_exists    = \term_exists( $delete_parent_slug, 'category' );
			$remaining      = is_int( $delete_post_id ) && $delete_post_id > 0
				? \wp_get_object_terms(
					$delete_post_id,
					'category',
					array(
						'fields'  => 'ids',
						'orderby' => 'none',
					)
				)
				: null;
			$has_deleted    = is_int( $delete_post_id ) && $delete_post_id > 0 ? \has_term( $delete_parent_id, 'category', $delete_post_id ) : null;
			$has_child      = is_int( $delete_post_id ) && $delete_post_id > 0 ? \has_term( $delete_child_id, 'category', $delete_post_id ) : null;
			$object_deleted = is_int( $delete_post_id ) && $delete_post_id > 0 ? \is_object_in_term( $delete_post_id, 'category', $delete_parent_id ) : null;

			self::collect_failure(
				$failures,
				is_int( $delete_post_id )
					&& $delete_post_id > 0
					&& is_array( $assigned )
					&& is_array( $assigned_terms )
					&& self::same_id_set( $assigned_terms, array( $delete_parent_id, $delete_child_id ) ),
				'term delete seed post receives parent and child categories',
				array(
					'postId'        => $delete_post_id,
					'assigned'      => $assigned,
					'assignedTerms' => $assigned_terms,
				)
			);

			self::collect_failure(
				$failures,
				true === $deleted
					&& false === $deleted_again
					&& ! $deleted_parent instanceof \WP_Term
					&& null === $parent_exists
					&& null === $slug_exists
					&& $child_after instanceof \WP_Term
					&& 0 === (int) $child_after->parent
					&& is_array( $remaining )
					&& self::same_id_set( $remaining, array( $delete_child_id ) )
					&& false === $has_deleted
					&& true === $has_child
					&& false === $object_deleted,
				'wp_delete_term removes the deleted term, reparents children, and removes relationships',
				array(
					'deleted'       => $deleted,
					'deletedAgain'  => $deleted_again,
					'deletedParent' => self::term_summary( $deleted_parent ),
					'childAfter'    => self::term_summary( $child_after ),
					'parentExists'  => $parent_exists,
					'slugExists'    => $slug_exists,
					'remaining'     => $remaining,
					'hasDeleted'    => $has_deleted,
					'hasChild'      => $has_child,
					'objectDeleted' => $object_deleted,
				)
			);

			$hook_names = array_map(
				static fn( array $event ): string => $event['hook'],
				$events
			);
			self::collect_failure(
				$failures,
				self::events_are_ordered(
					$hook_names,
					array(
						'edit_terms',
						'edited_terms',
						'edit_term_taxonomy',
						'edited_term_taxonomy',
						'edit_term',
						'edit_category',
						'edited_term',
						'edited_category',
						'saved_term',
						'saved_category',
						'pre_delete_term',
						'edit_term_taxonomies',
						'edited_term_taxonomies',
						'delete_term_taxonomy',
						'deleted_term_taxonomy',
						'delete_term',
						'delete_category',
					)
				)
					&& self::term_lifecycle_event_present( $events, 'edit_terms', $update_child_id, null, 'category' )
					&& self::term_lifecycle_event_present( $events, 'edited_category', $update_child_id, $update_child_tt, 'category' )
					&& self::term_lifecycle_event_present( $events, 'saved_category', $update_child_id, $update_child_tt, 'category' )
					&& self::term_lifecycle_event_present( $events, 'pre_delete_term', $delete_parent_id, null, 'category' )
					&& self::term_lifecycle_event_present( $events, 'delete_term_taxonomy', null, $delete_parent_tt, null )
					&& self::term_lifecycle_event_present( $events, 'deleted_term_taxonomy', null, $delete_parent_tt, null )
					&& self::term_lifecycle_event_present( $events, 'delete_term', $delete_parent_id, $delete_parent_tt, 'category' )
					&& self::term_lifecycle_event_present( $events, 'delete_category', $delete_parent_id, $delete_parent_tt, 'category' ),
				'term update/delete hooks fire in order with expected IDs',
				array(
					'events' => array_slice( $events, 0, 30 ),
				)
			);
		} finally {
			self::remove_hooks( $hooks );
			$hooks_removed = self::hooks_are_removed( $hooks );
		}

		self::collect_failure(
			$failures,
			$hooks_removed,
			'term lifecycle hooks are removed after update/delete check',
			array( 'hooks' => array_map( static fn( array $hook ): string => $hook[0], $hooks ) )
		);

		return $ctx->result(
			'content-lifecycle.terms.update-delete-hooks-relationships',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
				'events'   => array_slice( $events, 0, 30 ),
			)
		);
	}

	private static function check_user_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures      = array();
		$login         = $case['userLogin'];
		$email         = $case['userEmail'];
		$second_login  = $login . '_next';
		$second_email  = 'next-' . $email;
		$user_id       = \wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => $case['password'],
				'user_email'   => $email,
				'display_name' => $case['displayName'],
				'nickname'     => $case['nickname'],
				'description'  => $case['description'],
				'role'         => 'subscriber',
			)
		);
		$second_id     = \wp_insert_user(
			array(
				'user_login' => $second_login,
				'user_pass'  => $case['password'],
				'user_email' => $second_email,
				'role'       => 'subscriber',
			)
		);
		$by_id         = \get_userdata( $user_id );
		$by_login      = \get_user_by( 'login', $login );
		$by_email      = \get_user_by( 'email', $email );
		$by_slug       = $by_id instanceof \WP_User ? \get_user_by( 'slug', $by_id->user_nicename ) : false;
		$duplicate     = \wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => $case['password'],
				'user_email' => 'duplicate-' . $email,
			)
		);
		$email_dupe    = \wp_insert_user(
			array(
				'user_login' => $login . '_email_dupe',
				'user_pass'  => $case['password'],
				'user_email' => $email,
			)
		);
		$updated_id    = \wp_insert_user(
			array(
				'ID'           => $user_id,
				'user_login'   => $login,
				'user_email'   => 'updated-' . $email,
				'display_name' => $case['updatedDisplayName'],
			)
		);
		$updated_user  = \get_userdata( $user_id );
		$expected_display = '' === $case['updatedDisplayName'] ? $login : \wp_unslash( $case['updatedDisplayName'] );
		$empty_login   = \wp_insert_user(
			array(
				'user_login' => '',
				'user_pass'  => $case['password'],
				'user_email' => 'empty-' . $email,
			)
		);
		$long_login    = \wp_insert_user(
			array(
				'user_login' => str_repeat( 'x', 61 ),
				'user_pass'  => $case['password'],
				'user_email' => 'long-' . $email,
			)
		);

		self::collect_failure(
			$failures,
			is_int( $user_id )
				&& is_int( $second_id )
				&& $second_id > $user_id,
			'user IDs are assigned and monotonic',
			array( 'userId' => $user_id, 'secondId' => $second_id )
		);
		self::collect_failure(
			$failures,
			$by_id instanceof \WP_User
				&& $by_login instanceof \WP_User
				&& $by_email instanceof \WP_User
				&& $by_slug instanceof \WP_User
				&& $by_login->ID === $user_id
				&& $by_email->ID === $user_id
				&& $by_slug->ID === $user_id,
			'get_userdata and get_user_by indexes resolve inserted user',
			array(
				'byId'    => self::user_summary( $by_id ),
				'byLogin' => self::user_summary( $by_login ),
				'byEmail' => self::user_summary( $by_email ),
				'bySlug'  => self::user_summary( $by_slug ),
			)
		);
		self::collect_failure(
			$failures,
			$by_id instanceof \WP_User
				&& \sanitize_user( $login, true ) === $by_id->user_login
				&& \sanitize_title( \sanitize_user( $login, true ) ) === $by_id->user_nicename,
			'user login and nicename agree with WordPress sanitizers',
			array(
				'login'    => $by_id instanceof \WP_User ? $by_id->user_login : null,
				'nicename' => $by_id instanceof \WP_User ? $by_id->user_nicename : null,
			)
		);
		self::collect_failure(
			$failures,
			$updated_id === $user_id
				&& $updated_user instanceof \WP_User
				&& 'updated-' . $email === $updated_user->user_email
				&& $expected_display === $updated_user->display_name,
			'user update preserves ID and updates readable fields',
			array( 'updated' => self::user_summary( $updated_user ), 'updatedId' => $updated_id, 'expectedDisplay' => $expected_display )
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $duplicate )
				&& 'existing_user_login' === $duplicate->get_error_code()
				&& \is_wp_error( $email_dupe )
				&& 'existing_user_email' === $email_dupe->get_error_code(),
			'duplicate user login and email constraints match WordPress',
			array(
				'duplicateLogin' => self::error_summary( $duplicate ),
				'duplicateEmail' => self::error_summary( $email_dupe ),
			)
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $empty_login )
				&& 'empty_user_login' === $empty_login->get_error_code()
				&& \is_wp_error( $long_login )
				&& 'user_login_too_long' === $long_login->get_error_code(),
			'invalid user inputs return WP_Error codes',
			array(
				'emptyLogin' => self::error_summary( $empty_login ),
				'longLogin'  => self::error_summary( $long_login ),
			)
		);

		return $ctx->result(
			'content-lifecycle.users.insert-read-update-duplicates-invalid',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_user_insert_update_filter_meta_contracts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures             = array();
		$token                = substr( $case['token'], 0, 10 );
		$phase                = 'insert';
		$raw_login            = 'cf_user_raw_' . $token;
		$filtered_login       = 'cf_user_filtered_' . $token;
		$illegal_login        = 'cf_user_illegal_' . $token;
		$insert_email         = 'filtered-' . $case['userEmail'];
		$raw_updated_email    = 'raw-updated-' . $case['userEmail'];
		$updated_email        = 'updated-filtered-' . $case['userEmail'];
		$insert_url           = 'https://example.test/users/' . rawurlencode( $token ) . '/?a=1&b=two';
		$updated_url          = 'https://example.test/users/' . rawurlencode( $token ) . '/updated/';
		$insert_nicename      = 'filtered-nicename-' . $token;
		$updated_nicename     = 'updated-nicename-' . $token;
		$insert_display       = 'Filtered Display ' . $token;
		$updated_display      = 'Updated Filtered Display ' . $token;
		$insert_nickname      = 'Filtered Nickname ' . $token;
		$updated_nickname     = 'Updated Filtered Nickname ' . $token;
		$insert_meta_nickname  = 'Meta Nickname ' . $token;
		$updated_meta_nickname = 'Updated Meta Nickname ' . $token;
		$first_name           = 'First ' . $token;
		$last_name            = 'Last ' . $token;
		$description          = 'Description ' . $token;
		$updated_first_name   = 'Updated First ' . $token;
		$updated_last_name    = 'Updated Last ' . $token;
		$updated_description  = 'Updated Description ' . $token;
		$default_meta_key     = 'cf_user_default_meta_' . $token;
		$custom_meta_key      = 'cf_user_custom_meta_' . $token;
		$poison_meta_key      = 'cf_user_poison_meta_' . $token;
		$insert_default_meta  = 'default meta ' . $token;
		$updated_default_meta = 'updated default meta ' . $token;
		$insert_custom_meta   = 'custom meta ' . $token;
		$updated_custom_meta  = 'updated custom meta ' . $token;
		$activation_key       = 'activation-' . $token;
		$updated_password     = $case['password'] . '-updated';
		$events               = array(
			'pre'          => array(),
			'preData'      => array(),
			'insertMeta'   => array(),
			'customMeta'   => array(),
			'userRegister' => array(),
			'profileUpdate' => array(),
			'wpUpdateUser' => array(),
			'setPassword'  => array(),
			'sendPassword' => array(),
			'sendEmail'    => array(),
			'illegal'      => array(),
		);
		$hooks                = array();
		$add_hook             = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_filter( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};

		$pre_user_login = static function ( string $value ) use ( &$events, &$phase, $filtered_login, $illegal_login ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_login',
				'phase' => $phase,
				'value' => $value,
			);

			if ( 'illegal' === $phase ) {
				return $illegal_login;
			}

			if ( 'empty-data' === $phase ) {
				return $value;
			}

			return $filtered_login;
		};
		$pre_user_nicename = static function ( string $value ) use ( &$events, &$phase, $insert_nicename, $updated_nicename ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_nicename',
				'phase' => $phase,
				'value' => $value,
			);

			return 'update' === $phase ? $updated_nicename : $insert_nicename;
		};
		$pre_user_email = static function ( string $value ) use ( &$events, &$phase, $insert_email, $updated_email ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_email',
				'phase' => $phase,
				'value' => $value,
			);

			if ( 'empty-data' === $phase ) {
				return $value;
			}

			return 'update' === $phase ? $updated_email : $insert_email;
		};
		$pre_user_url = static function ( string $value ) use ( &$events, &$phase, $insert_url, $updated_url ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_url',
				'phase' => $phase,
				'value' => $value,
			);

			return 'update' === $phase ? $updated_url : $insert_url;
		};
		$pre_user_display_name = static function ( string $value ) use ( &$events, &$phase, $insert_display, $updated_display ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_display_name',
				'phase' => $phase,
				'value' => $value,
			);

			return 'update' === $phase ? $updated_display : $insert_display;
		};
		$pre_user_nickname = static function ( string $value ) use ( &$events, &$phase, $insert_nickname, $updated_nickname ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_nickname',
				'phase' => $phase,
				'value' => $value,
			);

			return 'update' === $phase ? $updated_nickname : $insert_nickname;
		};
		$pre_user_first_name = static function ( string $value ) use ( &$events, &$phase, $first_name, $updated_first_name ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_first_name',
				'phase' => $phase,
				'value' => $value,
			);

			return 'update' === $phase ? $updated_first_name : $first_name;
		};
		$pre_user_last_name = static function ( string $value ) use ( &$events, &$phase, $last_name, $updated_last_name ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_last_name',
				'phase' => $phase,
				'value' => $value,
			);

			return 'update' === $phase ? $updated_last_name : $last_name;
		};
		$pre_user_description = static function ( string $value ) use ( &$events, &$phase, $description, $updated_description ): string {
			$events['pre'][] = array(
				'hook'  => 'pre_user_description',
				'phase' => $phase,
				'value' => $value,
			);

			return 'update' === $phase ? $updated_description : $description;
		};
		$wp_pre_insert_user_data = static function ( array $data, bool $update, $user_id, array $userdata ) use ( &$events, &$phase ): array {
			$events['preData'][] = array(
				'phase'       => $phase,
				'update'      => $update,
				'userId'      => $user_id,
				'dataKeys'    => array_keys( $data ),
				'userdataKeys' => array_keys( $userdata ),
				'login'       => $data['user_login'] ?? null,
				'email'       => $data['user_email'] ?? null,
			);

			if ( 'empty-data' === $phase ) {
				return array();
			}

			return $data;
		};
		$insert_user_meta = static function ( array $meta, \WP_User $user, bool $update, array $userdata ) use ( &$events, &$phase, $default_meta_key, $insert_default_meta, $updated_default_meta, $insert_meta_nickname, $updated_meta_nickname ): array {
			$events['insertMeta'][] = array(
				'phase'        => $phase,
				'update'       => $update,
				'userId'       => (int) $user->ID,
				'userdataKeys' => array_keys( $userdata ),
				'nickname'     => $meta['nickname'] ?? null,
				'keys'         => array_keys( $meta ),
			);

			$meta['nickname']      = $update ? $updated_meta_nickname : $insert_meta_nickname;
			$meta[ $default_meta_key ] = $update ? $updated_default_meta : $insert_default_meta;

			return $meta;
		};
		$insert_custom_user_meta = static function ( array $custom_meta, \WP_User $user, bool $update, array $userdata ) use ( &$events, &$phase, $custom_meta_key, $poison_meta_key, $insert_custom_meta, $updated_custom_meta ): array {
			$events['customMeta'][] = array(
				'phase'        => $phase,
				'update'       => $update,
				'userId'       => (int) $user->ID,
				'userdataKeys' => array_keys( $userdata ),
				'keys'         => array_keys( $custom_meta ),
			);

			unset( $custom_meta[ $poison_meta_key ] );
			$custom_meta[ $custom_meta_key ] = $update ? $updated_custom_meta : $insert_custom_meta;

			return $custom_meta;
		};
		$user_register = static function ( int $user_id, array $userdata ) use ( &$events, $custom_meta_key ): void {
			$events['userRegister'][] = array(
				'userId'       => $user_id,
				'userdataKeys' => array_keys( $userdata ),
				'customMeta'   => \get_user_meta( $user_id, $custom_meta_key, true ),
				'caps'         => \get_user_meta( $user_id, 'wp_capabilities', true ),
			);
		};
		$profile_update = static function ( int $user_id, \WP_User $old_user_data, array $userdata ) use ( &$events, $custom_meta_key ): void {
			$events['profileUpdate'][] = array(
				'userId'       => $user_id,
				'oldEmail'     => $old_user_data->user_email,
				'oldDisplay'   => $old_user_data->display_name,
				'oldActivation' => $old_user_data->user_activation_key,
				'userdataKeys' => array_keys( $userdata ),
				'customMeta'   => \get_user_meta( $user_id, $custom_meta_key, true ),
				'caps'         => \get_user_meta( $user_id, 'wp_capabilities', true ),
			);
		};
		$wp_update_user_action = static function ( int $user_id, array $userdata, array $userdata_raw ) use ( &$events ): void {
			$events['wpUpdateUser'][] = array(
				'userId'      => $user_id,
				'email'       => $userdata['user_email'] ?? null,
				'rawEmail'    => $userdata_raw['user_email'] ?? null,
				'userdataKeys' => array_keys( $userdata ),
				'rawKeys'     => array_keys( $userdata_raw ),
			);
		};
		$wp_set_password = static function ( string $password, int $user_id, \WP_User $user ) use ( &$events, &$phase ): void {
			$events['setPassword'][] = array(
				'phase'    => $phase,
				'userId'   => $user_id,
				'password' => $password,
				'userLogin' => $user->user_login,
			);
		};
		$send_password_change_email = static function ( bool $send, array $user, array $userdata ) use ( &$events ): bool {
			$events['sendPassword'][] = array(
				'send'        => $send,
				'oldEmail'    => $user['user_email'] ?? null,
				'newEmail'    => $userdata['user_email'] ?? null,
				'hasUserPass' => isset( $userdata['user_pass'] ),
			);

			return false;
		};
		$send_email_change_email = static function ( bool $send, array $user, array $userdata ) use ( &$events ): bool {
			$events['sendEmail'][] = array(
				'send'     => $send,
				'oldEmail' => $user['user_email'] ?? null,
				'newEmail' => $userdata['user_email'] ?? null,
			);

			return false;
		};
		$illegal_user_logins = static function ( array $logins ) use ( &$events, &$phase, $illegal_login ): array {
			$events['illegal'][] = array(
				'phase' => $phase,
				'count' => count( $logins ),
			);

			return 'illegal' === $phase ? array_merge( $logins, array( $illegal_login ) ) : $logins;
		};

		foreach (
			array(
				array( 'pre_user_login', $pre_user_login, 1 ),
				array( 'pre_user_nicename', $pre_user_nicename, 1 ),
				array( 'pre_user_email', $pre_user_email, 1 ),
				array( 'pre_user_url', $pre_user_url, 1 ),
				array( 'pre_user_display_name', $pre_user_display_name, 1 ),
				array( 'pre_user_nickname', $pre_user_nickname, 1 ),
				array( 'pre_user_first_name', $pre_user_first_name, 1 ),
				array( 'pre_user_last_name', $pre_user_last_name, 1 ),
				array( 'pre_user_description', $pre_user_description, 1 ),
				array( 'wp_pre_insert_user_data', $wp_pre_insert_user_data, 4 ),
				array( 'insert_user_meta', $insert_user_meta, 4 ),
				array( 'insert_custom_user_meta', $insert_custom_user_meta, 4 ),
				array( 'user_register', $user_register, 2 ),
				array( 'profile_update', $profile_update, 3 ),
				array( 'wp_update_user', $wp_update_user_action, 3 ),
				array( 'wp_set_password', $wp_set_password, 3 ),
				array( 'send_password_change_email', $send_password_change_email, 3 ),
				array( 'send_email_change_email', $send_email_change_email, 3 ),
				array( 'illegal_user_logins', $illegal_user_logins, 1 ),
			) as $hook
		) {
			$add_hook( $hook[0], $hook[1], $hook[2] );
		}

		try {
			$user_id = \wp_insert_user(
				array(
					'user_login'           => $raw_login,
					'user_pass'            => $case['password'],
					'user_email'           => 'raw-' . $case['userEmail'],
					'user_url'             => 'https://raw.example.test/users/' . rawurlencode( $token ),
					'user_nicename'        => 'raw nicename ' . $token,
					'display_name'         => 'Raw Display ' . $token,
					'nickname'             => 'Raw Nickname ' . $token,
					'first_name'           => 'Raw First ' . $token,
					'last_name'            => 'Raw Last ' . $token,
					'description'          => 'Raw Description ' . $token,
					'rich_editing'         => 'false',
					'syntax_highlighting'  => 'false',
					'comment_shortcuts'    => '1',
					'admin_color'          => 'modern<script>',
					'use_ssl'              => 1,
					'show_admin_bar_front' => 'false',
					'locale'               => 'es_ES',
					'user_activation_key'   => $activation_key,
					'meta_input'           => array(
						$custom_meta_key => 'raw custom ' . $token,
						$poison_meta_key => 'poison ' . $token,
					),
				)
			);

			$inserted_user = is_int( $user_id ) ? \get_userdata( $user_id ) : false;
			$inserted_caps = is_int( $user_id ) ? \get_user_meta( $user_id, 'wp_capabilities', true ) : null;

			self::collect_failure(
				$failures,
				is_int( $user_id )
					&& $inserted_user instanceof \WP_User
					&& $filtered_login === $inserted_user->user_login
					&& $insert_email === $inserted_user->user_email
					&& $insert_url === $inserted_user->user_url
					&& $insert_nicename === $inserted_user->user_nicename
					&& $insert_display === $inserted_user->display_name
					&& $activation_key === $inserted_user->user_activation_key
					&& is_array( $inserted_caps )
					&& true === ( $inserted_caps['subscriber'] ?? null ),
				'wp_insert_user applies pre-user filters, default role, and activation key before persistence',
				array(
					'userId' => $user_id,
					'user'   => self::user_summary( $inserted_user ),
					'caps'   => $inserted_caps,
				)
			);

			self::collect_failure(
				$failures,
				is_int( $user_id )
					&& $insert_meta_nickname === \get_user_meta( $user_id, 'nickname', true )
					&& $first_name === \get_user_meta( $user_id, 'first_name', true )
					&& $last_name === \get_user_meta( $user_id, 'last_name', true )
					&& $description === \get_user_meta( $user_id, 'description', true )
					&& 'false' === \get_user_meta( $user_id, 'rich_editing', true )
					&& 'false' === \get_user_meta( $user_id, 'syntax_highlighting', true )
					&& 'true' === \get_user_meta( $user_id, 'comment_shortcuts', true )
					&& '1' === (string) \get_user_meta( $user_id, 'use_ssl', true )
					&& 'false' === \get_user_meta( $user_id, 'show_admin_bar_front', true )
					&& 'es_ES' === \get_user_meta( $user_id, 'locale', true )
					&& $insert_default_meta === \get_user_meta( $user_id, $default_meta_key, true )
					&& $insert_custom_meta === \get_user_meta( $user_id, $custom_meta_key, true )
					&& '' === \get_user_meta( $user_id, $poison_meta_key, true ),
				'wp_insert_user stores filtered default and custom user meta while custom-meta filter can remove inputs',
				array(
					'userId'      => $user_id,
					'nickname'    => is_int( $user_id ) ? \get_user_meta( $user_id, 'nickname', true ) : null,
					'defaultMeta' => is_int( $user_id ) ? \get_user_meta( $user_id, $default_meta_key, true ) : null,
					'customMeta'  => is_int( $user_id ) ? \get_user_meta( $user_id, $custom_meta_key, true ) : null,
					'poisonMeta'  => is_int( $user_id ) ? \get_user_meta( $user_id, $poison_meta_key, true ) : null,
				)
			);

			self::collect_failure(
				$failures,
				1 === count( $events['userRegister'] )
					&& $user_id === ( $events['userRegister'][0]['userId'] ?? null )
					&& $insert_custom_meta === ( $events['userRegister'][0]['customMeta'] ?? null )
					&& true === ( $events['userRegister'][0]['caps']['subscriber'] ?? null )
					&& isset( $events['setPassword'][0] )
					&& 'insert' === ( $events['setPassword'][0]['phase'] ?? null )
					&& $case['password'] === ( $events['setPassword'][0]['password'] ?? null ),
				'wp_insert_user fires password and user_register hooks after meta and default role are available',
				array(
					'userRegister' => $events['userRegister'],
					'setPassword'  => $events['setPassword'],
				)
			);

			$phase      = 'update';
			$updated_id = \wp_update_user(
				array(
					'ID'            => $user_id,
					'user_pass'     => $updated_password,
					'user_email'    => $raw_updated_email,
					'user_url'      => 'https://raw.example.test/users/' . rawurlencode( $token ) . '/updated/',
					'display_name'  => 'Raw Updated Display ' . $token,
					'nickname'      => 'Raw Updated Nickname ' . $token,
					'first_name'    => 'Raw Updated First ' . $token,
					'last_name'     => 'Raw Updated Last ' . $token,
					'description'   => 'Raw Updated Description ' . $token,
					'role'          => 'editor',
					'meta_input'    => array(
						$custom_meta_key => 'raw updated custom ' . $token,
						$poison_meta_key => 'updated poison ' . $token,
					),
				)
			);
			$updated_user = is_int( $updated_id ) ? \get_userdata( $updated_id ) : false;
			$updated_caps = is_int( $updated_id ) ? \get_user_meta( $updated_id, 'wp_capabilities', true ) : null;

			self::collect_failure(
				$failures,
				$updated_id === $user_id
					&& $updated_user instanceof \WP_User
					&& $updated_email === $updated_user->user_email
					&& $updated_url === $updated_user->user_url
					&& $updated_nicename === $updated_user->user_nicename
					&& $updated_display === $updated_user->display_name
					&& '' === $updated_user->user_activation_key
					&& \wp_check_password( $updated_password, $updated_user->user_pass, $user_id )
					&& is_array( $updated_caps )
					&& true === ( $updated_caps['editor'] ?? null ),
				'wp_update_user reuses insert pipeline, hashes changed password, clears activation key, and adds explicit role',
				array(
					'updatedId' => $updated_id,
					'user'      => self::user_summary( $updated_user ),
					'caps'      => $updated_caps,
				)
			);

			self::collect_failure(
				$failures,
				$updated_meta_nickname === \get_user_meta( $user_id, 'nickname', true )
					&& $updated_first_name === \get_user_meta( $user_id, 'first_name', true )
					&& $updated_last_name === \get_user_meta( $user_id, 'last_name', true )
					&& $updated_description === \get_user_meta( $user_id, 'description', true )
					&& $updated_default_meta === \get_user_meta( $user_id, $default_meta_key, true )
					&& $updated_custom_meta === \get_user_meta( $user_id, $custom_meta_key, true )
					&& '' === \get_user_meta( $user_id, $poison_meta_key, true ),
				'wp_update_user refreshes filtered default and custom user meta and preserves custom-meta removal',
				array(
					'nickname'    => \get_user_meta( $user_id, 'nickname', true ),
					'defaultMeta' => \get_user_meta( $user_id, $default_meta_key, true ),
					'customMeta'  => \get_user_meta( $user_id, $custom_meta_key, true ),
					'poisonMeta'  => \get_user_meta( $user_id, $poison_meta_key, true ),
				)
			);

			self::collect_failure(
				$failures,
				1 === count( $events['profileUpdate'] )
					&& $user_id === ( $events['profileUpdate'][0]['userId'] ?? null )
					&& $insert_email === ( $events['profileUpdate'][0]['oldEmail'] ?? null )
					&& $insert_display === ( $events['profileUpdate'][0]['oldDisplay'] ?? null )
					&& $activation_key === ( $events['profileUpdate'][0]['oldActivation'] ?? null )
					&& 1 === count( $events['wpUpdateUser'] )
					&& $user_id === ( $events['wpUpdateUser'][0]['userId'] ?? null )
					&& $raw_updated_email === ( $events['wpUpdateUser'][0]['email'] ?? null )
					&& $raw_updated_email === ( $events['wpUpdateUser'][0]['rawEmail'] ?? null )
					&& isset( $events['setPassword'][1] )
					&& 'update' === ( $events['setPassword'][1]['phase'] ?? null )
					&& $updated_password === ( $events['setPassword'][1]['password'] ?? null )
					&& 1 === count( $events['sendPassword'] )
					&& true === ( $events['sendPassword'][0]['send'] ?? null )
					&& 1 === count( $events['sendEmail'] )
					&& true === ( $events['sendEmail'][0]['send'] ?? null ),
				'wp_update_user fires profile/update/password/change-mail filters with old, raw, and final user payloads',
				array(
					'profileUpdate' => $events['profileUpdate'],
					'wpUpdateUser'  => $events['wpUpdateUser'],
					'setPassword'   => $events['setPassword'],
					'sendPassword'  => $events['sendPassword'],
					'sendEmail'     => $events['sendEmail'],
				)
			);

			$phase        = 'illegal';
			$illegal_user = \wp_insert_user(
				array(
					'user_login' => $illegal_login,
					'user_pass'  => $case['password'],
					'user_email' => 'illegal-' . $case['userEmail'],
				)
			);

			$phase      = 'empty-data';
			$empty_data = \wp_insert_user(
				array(
					'user_login' => 'cf_user_empty_data_' . $token,
					'user_pass'  => $case['password'],
					'user_email' => 'empty-data-' . $case['userEmail'],
				)
			);

			self::collect_failure(
				$failures,
				\is_wp_error( $illegal_user )
					&& 'invalid_username' === $illegal_user->get_error_code()
					&& \is_wp_error( $empty_data )
					&& 'empty_data' === $empty_data->get_error_code()
					&& 1 === count( $events['userRegister'] ),
				'wp_insert_user fails closed for illegal-login and empty-data filters without firing extra user_register hooks',
				array(
					'illegal'      => self::error_summary( $illegal_user ),
					'emptyData'    => self::error_summary( $empty_data ),
					'userRegister' => $events['userRegister'],
					'preData'      => $events['preData'],
				)
			);
		} finally {
			self::remove_hooks( $hooks );
		}

		self::collect_failure(
			$failures,
			self::hooks_are_removed( $hooks ),
			'user insert/update filter hooks are removed',
			array(
				'remaining' => array_values(
					array_filter(
						array_map(
							static function ( array $hook ) {
								return false === \has_filter( $hook[0], $hook[1] ) ? null : $hook[0];
							},
							$hooks
						)
					)
				),
			)
		);

		return $ctx->result(
			'content-lifecycle.users.insert-update-filter-meta-hooks',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'events'   => array(
					'preData'       => array_slice( $events['preData'], 0, 4 ),
					'insertMeta'    => array_slice( $events['insertMeta'], 0, 4 ),
					'customMeta'    => array_slice( $events['customMeta'], 0, 4 ),
					'userRegister'  => $events['userRegister'],
					'profileUpdate' => $events['profileUpdate'],
					'wpUpdateUser'  => $events['wpUpdateUser'],
				),
			)
		);
	}

	private static function check_user_delete_reassign_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$wpdb             = $GLOBALS['wpdb'];
		$failures         = array();
		$token            = substr( $case['token'], 0, 10 );
		$phase            = 'setup';
		$active_meta_key  = '';
		$tracked_posts    = array();
		$tracked_links    = array();
		$sequence         = 0;
		$events           = array(
			'deleteUser'  => array(),
			'deletedUser' => array(),
			'postTypes'   => array(),
			'deleteLink'  => array(),
			'deletedLink' => array(),
		);
		$hooks            = array();
		$add_hook         = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_filter( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};
		$post_author_map  = static function ( array $post_ids ): array {
			$authors = array();

			foreach ( $post_ids as $label => $post_id ) {
				$post              = is_numeric( $post_id ) ? \get_post( (int) $post_id ) : null;
				$authors[ $label ] = $post instanceof \WP_Post ? (int) $post->post_author : null;
			}

			return $authors;
		};
		$link_owner_map   = static function ( array $link_ids ): array {
			$owners = array();

			foreach ( $link_ids as $label => $link_id ) {
				$bookmark          = is_numeric( $link_id ) ? \get_bookmark( (int) $link_id ) : null;
				$owners[ $label ] = is_object( $bookmark ) && ! \is_wp_error( $bookmark ) ? (int) $bookmark->link_owner : null;
			}

			return $owners;
		};
		$insert_link      = static function ( int $owner, string $suffix ) use ( $wpdb, $token ): int {
			$wpdb->insert(
				$wpdb->links,
				array(
					'link_url'         => 'https://example.test/delete-user/' . rawurlencode( $token ) . '/' . rawurlencode( $suffix ),
					'link_name'        => 'Delete User Link ' . $suffix . ' ' . $token,
					'link_image'       => '',
					'link_target'      => '',
					'link_description' => 'Delete user link fixture ' . $suffix,
					'link_visible'     => 'Y',
					'link_owner'       => $owner,
					'link_rating'      => 0,
					'link_rel'         => '',
					'link_notes'       => '',
					'link_rss'         => '',
					'link_updated'     => '2026-01-01 00:00:00',
				)
			);

			return (int) $wpdb->insert_id;
		};
		$insert_post      = static function ( int $author, string $post_type, string $suffix ) use ( $token ) {
			return \wp_insert_post(
				\wp_slash(
					array(
						'post_author'  => $author,
						'post_type'    => $post_type,
						'post_title'   => 'Delete User ' . $suffix . ' ' . $token,
						'post_content' => 'Delete user content ' . $suffix . ' ' . $token,
						'post_status'  => 'publish',
						'post_name'    => 'delete-user-' . $suffix . '-' . $token,
					)
				),
				true,
				false
			);
		};
		$events_for_phase = static function ( array $rows, string $event_phase ): array {
			return array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $event_phase ): bool {
						return $event_phase === ( $row['phase'] ?? null );
					}
				)
			);
		};

		$user_event = static function ( string $hook, $id, $reassign, $user ) use ( &$events, &$phase, &$active_meta_key, &$tracked_posts, &$tracked_links, &$sequence, $post_author_map, $link_owner_map ): void {
			$row = array(
				'seq'         => ++$sequence,
				'phase'       => $phase,
				'id'          => (int) $id,
				'reassign'    => null === $reassign ? null : (int) $reassign,
				'userLogin'   => $user instanceof \WP_User ? $user->user_login : null,
				'userExists'  => \get_userdata( (int) $id ) instanceof \WP_User,
				'meta'        => '' === $active_meta_key ? null : \get_user_meta( (int) $id, $active_meta_key, true ),
				'postAuthors' => $post_author_map( $tracked_posts ),
				'linkOwners'  => $link_owner_map( $tracked_links ),
			);

			$events[ $hook ][] = $row;
		};
		$delete_user = static function ( $id, $reassign, $user ) use ( $user_event ): void {
			$user_event( 'deleteUser', $id, $reassign, $user );
		};
		$deleted_user = static function ( $id, $reassign, $user ) use ( $user_event ): void {
			$user_event( 'deletedUser', $id, $reassign, $user );
		};
		$post_types_to_delete = static function ( array $post_types, int $id ) use ( &$events, &$phase ): array {
			$events['postTypes'][] = array(
				'phase' => $phase,
				'id'    => $id,
				'types' => array_values( $post_types ),
			);

			return $post_types;
		};
		$delete_link = static function ( int $link_id ) use ( &$events, &$phase, &$sequence ): void {
			$events['deleteLink'][] = array(
				'seq'    => ++$sequence,
				'phase'  => $phase,
				'linkId' => $link_id,
			);
		};
		$deleted_link = static function ( int $link_id ) use ( &$events, &$phase, &$sequence ): void {
			$events['deletedLink'][] = array(
				'seq'    => ++$sequence,
				'phase'  => $phase,
				'linkId' => $link_id,
			);
		};

		foreach (
			array(
				array( 'delete_user', $delete_user, 3 ),
				array( 'deleted_user', $deleted_user, 3 ),
				array( 'post_types_to_delete_with_user', $post_types_to_delete, 2 ),
				array( 'delete_link', $delete_link, 1 ),
				array( 'deleted_link', $deleted_link, 1 ),
			) as $hook
		) {
			$add_hook( $hook[0], $hook[1], $hook[2] );
		}

		try {
			$reassign_meta_key      = 'cf_delete_reassign_meta_' . $token;
			$reassign_source_login  = 'cf_del_src_' . $token;
			$reassign_source_email  = 'delete-src-' . $case['userEmail'];
			$reassign_target_login  = 'cf_del_target_' . $token;
			$reassign_target_email  = 'delete-target-' . $case['userEmail'];
			$reassign_source_id     = \wp_insert_user(
				array(
					'user_login' => $reassign_source_login,
					'user_pass'  => $case['password'],
					'user_email' => $reassign_source_email,
					'role'       => 'author',
					'meta_input' => array(
						$reassign_meta_key => 'reassign source meta ' . $token,
					),
				)
			);
			$reassign_target_id     = \wp_insert_user(
				array(
					'user_login' => $reassign_target_login,
					'user_pass'  => $case['password'],
					'user_email' => $reassign_target_email,
					'role'       => 'editor',
				)
			);
			$reassign_source_user   = is_int( $reassign_source_id ) ? \get_userdata( $reassign_source_id ) : false;
			$reassign_source_slug   = $reassign_source_user instanceof \WP_User ? $reassign_source_user->user_nicename : '';
			$reassign_post_id       = is_int( $reassign_source_id ) ? $insert_post( $reassign_source_id, 'post', 'reassign-post' ) : 0;
			$reassign_page_id       = is_int( $reassign_source_id ) ? $insert_post( $reassign_source_id, 'page', 'reassign-page' ) : 0;
			$reassign_link_id       = is_int( $reassign_source_id ) ? $insert_link( $reassign_source_id, 'reassign' ) : 0;
			$tracked_posts          = array(
				'post' => $reassign_post_id,
				'page' => $reassign_page_id,
			);
			$tracked_links          = array( 'link' => $reassign_link_id );
			$active_meta_key        = $reassign_meta_key;
			$phase                  = 'reassign';
			$reassign_result        = is_int( $reassign_source_id ) && is_int( $reassign_target_id ) ? \wp_delete_user( (string) $reassign_source_id, (string) $reassign_target_id ) : false;
			$reassign_post_authors  = $post_author_map( $tracked_posts );
			$reassign_link_owners   = $link_owner_map( $tracked_links );
			$reassign_delete_events = $events_for_phase( $events['deleteUser'], 'reassign' );
			$reassign_done_events   = $events_for_phase( $events['deletedUser'], 'reassign' );

			self::collect_failure(
				$failures,
				true === $reassign_result
					&& is_int( $reassign_target_id )
					&& false === \get_userdata( $reassign_source_id )
					&& false === \get_user_by( 'login', $reassign_source_login )
					&& false === \get_user_by( 'email', $reassign_source_email )
					&& ( '' === $reassign_source_slug || false === \get_user_by( 'slug', $reassign_source_slug ) )
					&& false === \username_exists( $reassign_source_login )
					&& false === \email_exists( $reassign_source_email )
					&& \get_userdata( $reassign_target_id ) instanceof \WP_User
					&& '' === \get_user_meta( $reassign_source_id, $reassign_meta_key, true )
					&& array(
						'post' => $reassign_target_id,
						'page' => $reassign_target_id,
					) === $reassign_post_authors
					&& array( 'link' => $reassign_target_id ) === $reassign_link_owners,
				'wp_delete_user with reassignment removes source user/meta/lookups and reassigns posts and links',
				array(
					'result'      => $reassign_result,
					'sourceId'    => $reassign_source_id,
					'targetId'    => $reassign_target_id,
					'postAuthors' => $reassign_post_authors,
					'linkOwners'  => $reassign_link_owners,
				)
			);
			self::collect_failure(
				$failures,
				1 === count( $reassign_delete_events )
					&& 1 === count( $reassign_done_events )
					&& $reassign_source_id === ( $reassign_delete_events[0]['id'] ?? null )
					&& $reassign_target_id === ( $reassign_delete_events[0]['reassign'] ?? null )
					&& true === ( $reassign_delete_events[0]['userExists'] ?? null )
					&& 'reassign source meta ' . $token === ( $reassign_delete_events[0]['meta'] ?? null )
					&& $reassign_source_id === ( $reassign_delete_events[0]['postAuthors']['post'] ?? null )
					&& $reassign_source_id === ( $reassign_delete_events[0]['linkOwners']['link'] ?? null )
					&& $reassign_source_id === ( $reassign_done_events[0]['id'] ?? null )
					&& $reassign_target_id === ( $reassign_done_events[0]['reassign'] ?? null )
					&& false === ( $reassign_done_events[0]['userExists'] ?? null )
					&& '' === ( $reassign_done_events[0]['meta'] ?? null )
					&& $reassign_target_id === ( $reassign_done_events[0]['postAuthors']['post'] ?? null )
					&& $reassign_target_id === ( $reassign_done_events[0]['linkOwners']['link'] ?? null )
					&& ( $reassign_delete_events[0]['seq'] ?? 0 ) < ( $reassign_done_events[0]['seq'] ?? 0 ),
				'reassign delete_user/deleted_user hooks receive normalized IDs and bracket content reassignment',
				array(
					'deleteUser'  => $reassign_delete_events,
					'deletedUser' => $reassign_done_events,
				)
			);

			$delete_type       = 'cf_del_' . $token;
			$keep_type         = 'cf_keep_' . $token;
			\register_post_type(
				$delete_type,
				array(
					'public'           => false,
					'show_ui'          => false,
					'supports'         => array( 'title', 'author' ),
					'delete_with_user' => true,
				)
			);
			\register_post_type(
				$keep_type,
				array(
					'public'           => false,
					'show_ui'          => false,
					'supports'         => array( 'title', 'author' ),
					'delete_with_user' => false,
				)
			);

			$delete_meta_key      = 'cf_delete_user_meta_' . $token;
			$delete_source_login  = 'cf_delete_user_' . $token;
			$delete_source_email  = 'delete-user-' . $case['userEmail'];
			$delete_source_id     = \wp_insert_user(
				array(
					'user_login' => $delete_source_login,
					'user_pass'  => $case['password'],
					'user_email' => $delete_source_email,
					'role'       => 'author',
					'meta_input' => array(
						$delete_meta_key => 'delete source meta ' . $token,
					),
				)
			);
			$delete_source_user   = is_int( $delete_source_id ) ? \get_userdata( $delete_source_id ) : false;
			$delete_source_slug   = $delete_source_user instanceof \WP_User ? $delete_source_user->user_nicename : '';
			$delete_post_id       = is_int( $delete_source_id ) ? $insert_post( $delete_source_id, $delete_type, 'delete-custom' ) : 0;
			$keep_post_id         = is_int( $delete_source_id ) ? $insert_post( $delete_source_id, $keep_type, 'keep-custom' ) : 0;
			$delete_link_id       = is_int( $delete_source_id ) ? $insert_link( $delete_source_id, 'delete' ) : 0;
			$tracked_posts        = array(
				'deleteType' => $delete_post_id,
				'keepFalse'  => $keep_post_id,
			);
			$tracked_links        = array( 'deleteLink' => $delete_link_id );
			$active_meta_key      = $delete_meta_key;
			$phase                = 'delete';
			$delete_result        = is_int( $delete_source_id ) ? \wp_delete_user( $delete_source_id, 'novalue' ) : false;
			$delete_post_authors  = $post_author_map( $tracked_posts );
			$delete_link_owners   = $link_owner_map( $tracked_links );
			$delete_events        = $events_for_phase( $events['deleteUser'], 'delete' );
			$deleted_events       = $events_for_phase( $events['deletedUser'], 'delete' );
			$post_type_events     = $events_for_phase( $events['postTypes'], 'delete' );
			$delete_link_events   = $events_for_phase( $events['deleteLink'], 'delete' );
			$deleted_link_events  = $events_for_phase( $events['deletedLink'], 'delete' );
			$received_post_types  = $post_type_events[0]['types'] ?? array();

			self::collect_failure(
				$failures,
				true === $delete_result
					&& false === \get_userdata( $delete_source_id )
					&& false === \get_user_by( 'login', $delete_source_login )
					&& false === \get_user_by( 'email', $delete_source_email )
					&& ( '' === $delete_source_slug || false === \get_user_by( 'slug', $delete_source_slug ) )
					&& false === \username_exists( $delete_source_login )
					&& false === \email_exists( $delete_source_email )
					&& '' === \get_user_meta( $delete_source_id, $delete_meta_key, true )
					&& array(
						'deleteType' => null,
						'keepFalse'  => $delete_source_id,
					) === $delete_post_authors
					&& array( 'deleteLink' => null ) === $delete_link_owners,
				'wp_delete_user without reassignment deletes eligible content and links while preserving delete_with_user=false posts',
				array(
					'result'      => $delete_result,
					'sourceId'    => $delete_source_id,
					'postAuthors' => $delete_post_authors,
					'linkOwners'  => $delete_link_owners,
				)
			);
			self::collect_failure(
				$failures,
				1 === count( $delete_events )
					&& 1 === count( $deleted_events )
					&& $delete_source_id === ( $delete_events[0]['id'] ?? null )
					&& null === ( $delete_events[0]['reassign'] ?? null )
					&& true === ( $delete_events[0]['userExists'] ?? null )
					&& 'delete source meta ' . $token === ( $delete_events[0]['meta'] ?? null )
					&& $delete_source_id === ( $delete_events[0]['postAuthors']['deleteType'] ?? null )
					&& $delete_source_id === ( $delete_events[0]['linkOwners']['deleteLink'] ?? null )
					&& $delete_source_id === ( $deleted_events[0]['id'] ?? null )
					&& null === ( $deleted_events[0]['reassign'] ?? null )
					&& false === ( $deleted_events[0]['userExists'] ?? null )
					&& '' === ( $deleted_events[0]['meta'] ?? null )
					&& null === ( $deleted_events[0]['postAuthors']['deleteType'] ?? null )
					&& $delete_source_id === ( $deleted_events[0]['postAuthors']['keepFalse'] ?? null )
					&& null === ( $deleted_events[0]['linkOwners']['deleteLink'] ?? null )
					&& ( $delete_events[0]['seq'] ?? 0 ) < ( $deleted_events[0]['seq'] ?? 0 ),
				'novalue delete_user/deleted_user hooks normalize reassignment to null and bracket content deletion',
				array(
					'deleteUser'  => $delete_events,
					'deletedUser' => $deleted_events,
				)
			);
			self::collect_failure(
				$failures,
				1 === count( $post_type_events )
					&& $delete_source_id === ( $post_type_events[0]['id'] ?? null )
					&& in_array( $delete_type, $received_post_types, true )
					&& ! in_array( $keep_type, $received_post_types, true )
					&& 1 === count( $delete_link_events )
					&& 1 === count( $deleted_link_events )
					&& $delete_link_id === ( $delete_link_events[0]['linkId'] ?? null )
					&& $delete_link_id === ( $deleted_link_events[0]['linkId'] ?? null )
					&& ( $delete_link_events[0]['seq'] ?? 0 ) < ( $deleted_link_events[0]['seq'] ?? 0 )
					&& ( $delete_events[0]['seq'] ?? 0 ) < ( $delete_link_events[0]['seq'] ?? 0 )
					&& ( $deleted_link_events[0]['seq'] ?? 0 ) < ( $deleted_events[0]['seq'] ?? 0 ),
				'no-reassign deletion exposes post-type filter payloads and link deletion hooks in order',
				array(
					'postTypes'   => $post_type_events,
					'deleteLink'  => $delete_link_events,
					'deletedLink' => $deleted_link_events,
				)
			);

			$delete_user_count  = count( $events['deleteUser'] );
			$deleted_user_count = count( $events['deletedUser'] );
			$phase              = 'invalid';
			$invalid_delete     = \wp_delete_user( 'not-a-user-id' );
			$missing_delete     = \wp_delete_user( 987654321 );

			self::collect_failure(
				$failures,
				false === $invalid_delete
					&& false === $missing_delete
					&& $delete_user_count === count( $events['deleteUser'] )
					&& $deleted_user_count === count( $events['deletedUser'] ),
				'wp_delete_user fails closed for non-numeric and missing users without firing delete hooks',
				array(
					'invalid'           => $invalid_delete,
					'missing'           => $missing_delete,
					'deleteUserCount'   => count( $events['deleteUser'] ),
					'deletedUserCount'  => count( $events['deletedUser'] ),
					'expectedHookCount' => $delete_user_count,
				)
			);
		} finally {
			self::remove_hooks( $hooks );
		}

		self::collect_failure(
			$failures,
			self::hooks_are_removed( $hooks ),
			'user delete/reassign hooks are removed',
			array(
				'remaining' => array_values(
					array_filter(
						array_map(
							static function ( array $hook ) {
								return false === \has_filter( $hook[0], $hook[1] ) ? null : $hook[0];
							},
							$hooks
						)
					)
				),
			)
		);

		return $ctx->result(
			'content-lifecycle.users.delete-reassign-hooks-content',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'events'   => array(
					'deleteUser'  => $events['deleteUser'],
					'deletedUser' => $events['deletedUser'],
					'postTypes'   => $events['postTypes'],
					'deleteLink'  => $events['deleteLink'],
					'deletedLink' => $events['deletedLink'],
				),
			)
		);
	}

	private static function check_comment_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$approve  = static function () {
			return 1;
		};
		\add_filter( 'pre_comment_approved', $approve, 10, 2 );

		try {
			$post_id  = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'      => 'page',
						'post_title'     => 'Comment Host ' . $case['token'],
						'post_content'   => 'Comment host content',
						'post_status'    => 'publish',
						'comment_status' => 'open',
						'post_name'      => 'comment-host-' . $case['token'],
					)
				),
				true,
				false
			);
			$direct   = \wp_insert_comment(
				array(
					'comment_post_ID'      => $post_id,
					'comment_author'       => $case['commentAuthor'],
					'comment_author_email' => $case['commentEmail'],
					'comment_author_url'   => $case['commentUrl'],
					'comment_content'      => $case['commentContent'],
					'comment_approved'     => '1',
					'comment_type'         => 'comment',
					'user_id'              => 0,
				)
			);
			$new_data = array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Commenter ' . $case['token'],
				'comment_author_email' => $case['commentEmail'],
				'comment_author_url'   => $case['commentUrl'],
				'comment_content'      => 'New comment ' . $case['token'],
			);
			$new_id   = \wp_new_comment( $new_data, true );
			$direct_o = \get_comment( $direct );
			$new_o    = \get_comment( $new_id );
			$count_2  = \get_post( $post_id )->comment_count ?? null;
			$dupe     = \wp_new_comment( $new_data, true );
			$delete_1 = \wp_delete_comment( $direct, true );
			$count_1  = \get_post( $post_id )->comment_count ?? null;
			$delete_2 = \wp_delete_comment( $new_id, true );
			$count_0  = \get_post( $post_id )->comment_count ?? null;
			$gone     = \get_comment( $direct );

			self::collect_failure(
				$failures,
				is_int( $direct )
					&& is_int( $new_id )
					&& $new_id > $direct
					&& $direct_o instanceof \WP_Comment
					&& $new_o instanceof \WP_Comment,
				'comment IDs are assigned, monotonic, and readable',
				array( 'direct' => $direct, 'new' => $new_id )
			);
			self::collect_failure(
				$failures,
				$direct_o instanceof \WP_Comment
					&& (string) $post_id === (string) $direct_o->comment_post_ID
					&& 'comment' === $direct_o->comment_type
					&& $case['commentEmail'] === $direct_o->comment_author_email,
				'comment post/type/email fields round-trip',
				array( 'comment' => self::comment_summary( $direct_o ) )
			);
			self::collect_failure(
				$failures,
				2 === (int) $count_2
					&& 1 === (int) $count_1
					&& 0 === (int) $count_0,
				'approved comment counts update on insert and delete',
				array( 'countAfterInsert' => $count_2, 'countAfterFirstDelete' => $count_1, 'countAfterSecondDelete' => $count_0 )
			);
			self::collect_failure(
				$failures,
				\is_wp_error( $dupe )
					&& 'comment_duplicate' === $dupe->get_error_code(),
				'wp_new_comment duplicate check returns WP_Error',
				array( 'duplicate' => self::error_summary( $dupe ) )
			);
			self::collect_failure(
				$failures,
				true === $delete_1
					&& true === $delete_2
					&& null === $gone,
				'wp_delete_comment force delete removes readable comment rows',
				array( 'deleteDirect' => $delete_1, 'deleteNew' => $delete_2, 'gone' => $gone )
			);

			\wp_delete_post( $post_id, true );
		} finally {
			\remove_filter( 'pre_comment_approved', $approve, 10 );
		}

		return $ctx->result(
			'content-lifecycle.comments.insert-new-read-delete-counts',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_post_count_and_mime_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures                 = array();
		$post_type                = 'cf_count_' . substr( $case['token'], 0, 8 );
		$custom_mime              = 'application/x-component-fuzz-' . substr( $case['token'], 0, 8 );
		$sentinel_mime_group      = 'application/x-component-fuzz-group-' . substr( $case['token'], 0, 8 );
		$post_count_events        = array();
		$attachment_count_events  = array();
		$post_mime_events         = array();
		$available_mime_events    = array();
		$available_short_circuit  = null;
		$post_count_filter        = static function ( $counts, $type, $perm ) use ( &$post_count_events ) {
			$post_count_events[] = array(
				'type'    => (string) $type,
				'perm'    => (string) $perm,
				'counts'  => is_object( $counts ) ? get_object_vars( $counts ) : $counts,
				'userId'  => \get_current_user_id(),
				'loggedIn' => \is_user_logged_in(),
			);

			return $counts;
		};
		$attachment_count_filter  = static function ( $counts, $mime_type ) use ( &$attachment_count_events ) {
			$attachment_count_events[] = array(
				'mimeType' => $mime_type,
				'counts'   => is_object( $counts ) ? get_object_vars( $counts ) : $counts,
			);

			return $counts;
		};
		$post_mime_filter         = static function ( $mime_types ) use ( &$post_mime_events, $sentinel_mime_group ) {
			$post_mime_events[] = array(
				'keys' => array_keys( (array) $mime_types ),
			);

			$mime_types[ $sentinel_mime_group ] = array(
				'Component Fuzz',
				'Manage Component Fuzz',
				array(
					'singular' => 'Component Fuzz <span class="count">(%s)</span>',
					'plural'   => 'Component Fuzz <span class="count">(%s)</span>',
					'context'  => null,
					'domain'   => null,
				),
			);

			return $mime_types;
		};
		$available_mime_filter    = static function ( $mime_types, $type ) use ( &$available_mime_events, &$available_short_circuit ) {
			$available_mime_events[] = array(
				'type'         => (string) $type,
				'shortCircuit' => is_array( $available_short_circuit ),
			);

			return is_array( $available_short_circuit ) ? $available_short_circuit : $mime_types;
		};
		$filters_removed          = false;

		\add_filter( 'wp_count_posts', $post_count_filter, 10, 3 );
		\add_filter( 'wp_count_attachments', $attachment_count_filter, 10, 2 );
		\add_filter( 'post_mime_types', $post_mime_filter );
		\add_filter( 'pre_get_available_post_mime_types', $available_mime_filter, 10, 2 );

		try {
			\register_post_type(
				$post_type,
				array(
					'public'          => true,
					'rewrite'         => false,
					'query_var'       => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'author' ),
				)
			);

			$author_id = self::insert_support_user( 'count-author-' . $case['token'], 'count-author-' . $case['token'] . '@example.test' );
			$other_id  = self::insert_support_user( 'count-other-' . $case['token'], 'count-other-' . $case['token'] . '@example.test' );
			$insert    = static function ( array $args ) use ( $case ) {
				return \wp_insert_post(
					\wp_slash(
						array_merge(
							array(
								'post_title'   => 'Count Fixture ' . $case['token'],
								'post_content' => 'Count fixture content ' . $case['token'],
								'post_status'  => 'draft',
								'post_name'    => 'count-fixture-' . $case['token'],
							),
							$args
						)
					),
					true,
					false
				);
			};

			$count_post_ids = array(
				$insert(
					array(
						'post_type'   => $post_type,
						'post_status' => 'publish',
						'post_author' => $author_id,
						'post_name'   => 'count-publish-a-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'   => $post_type,
						'post_status' => 'publish',
						'post_author' => $other_id,
						'post_name'   => 'count-publish-b-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'   => $post_type,
						'post_status' => 'draft',
						'post_author' => $author_id,
						'post_name'   => 'count-draft-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'   => $post_type,
						'post_status' => 'private',
						'post_author' => $author_id,
						'post_name'   => 'count-private-a-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'   => $post_type,
						'post_status' => 'private',
						'post_author' => $other_id,
						'post_name'   => 'count-private-b-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'     => $post_type,
						'post_status'   => 'future',
						'post_author'   => $author_id,
						'post_name'     => 'count-future-' . $case['token'],
						'post_date'     => '2035-01-02 03:04:05',
						'post_date_gmt' => '2035-01-02 03:04:05',
					)
				),
				$insert(
					array(
						'post_type'   => $post_type,
						'post_status' => 'trash',
						'post_author' => $author_id,
						'post_name'   => 'count-trash-' . $case['token'],
					)
				),
			);
			$count_posts_ok = array() === array_filter(
				$count_post_ids,
				static function ( $post_id ) {
					return ! is_int( $post_id ) || $post_id <= 0;
				}
			);

			self::collect_failure(
				$failures,
				$count_posts_ok,
				'count fixture posts insert with integer IDs',
				array( 'postIds' => $count_post_ids )
			);

			$missing_count = \wp_count_posts( $post_type . '_missing' );
			$all_counts    = \wp_count_posts( $post_type );
			$all_cache_key = \_count_posts_cache_key( $post_type, '' );
			$all_cached    = \wp_cache_get( $all_cache_key, 'counts' );

			self::collect_failure(
				$failures,
				$missing_count instanceof \stdClass && array() === get_object_vars( $missing_count ),
				'wp_count_posts returns an empty object for missing post types',
				array( 'missingCount' => $missing_count )
			);
			self::collect_failure(
				$failures,
				self::object_counts_include(
					$all_counts,
					array(
						'publish' => 2,
						'draft'   => 1,
						'private' => 2,
						'future'  => 1,
						'trash'   => 1,
					)
				)
					&& self::count_object_has_post_status_keys( $all_counts )
					&& self::object_counts_include(
						$all_cached,
						array(
							'publish' => 2,
							'draft'   => 1,
							'private' => 2,
							'future'  => 1,
							'trash'   => 1,
						)
					),
				'wp_count_posts groups seeded rows by status and caches zero-filled statuses',
				array(
					'counts'   => $all_counts,
					'cacheKey' => $all_cache_key,
					'cached'   => $all_cached,
				)
			);

			\wp_set_current_user( $author_id );
			$read_private_cap = \get_post_type_object( $post_type )->cap->read_private_posts;
			$readable_counts  = \wp_count_posts( $post_type, 'readable' );
			$readable_key     = \_count_posts_cache_key( $post_type, 'readable' );
			$readable_cached  = \wp_cache_get( $readable_key, 'counts' );

			self::collect_failure(
				$failures,
				! \current_user_can( $read_private_cap )
					&& str_contains( $readable_key, '_readable_' . $author_id )
					&& self::object_counts_include(
						$readable_counts,
						array(
							'publish' => 2,
							'draft'   => 1,
							'private' => 1,
							'future'  => 1,
							'trash'   => 1,
						)
					)
					&& self::object_counts_include(
						$readable_cached,
						array(
							'publish' => 2,
							'draft'   => 1,
							'private' => 1,
							'future'  => 1,
							'trash'   => 1,
						)
					),
				'readable wp_count_posts includes own private posts and excludes other private posts',
				array(
					'cap'      => $read_private_cap,
					'key'      => $readable_key,
					'counts'   => $readable_counts,
					'cached'   => $readable_cached,
					'authorId' => $author_id,
				)
			);

			$post_filter_keys = array();
			foreach ( $post_count_events as $event ) {
				$post_filter_keys[] = $event['type'] . ':' . $event['perm'];
			}
			self::collect_failure(
				$failures,
				in_array( $post_type . ':', $post_filter_keys, true )
					&& in_array( $post_type . ':readable', $post_filter_keys, true ),
				'wp_count_posts filter receives type and permission context',
				array( 'events' => $post_count_events )
			);

			$attachment_ids = array(
				$insert(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post_mime_type' => 'image/jpeg',
						'post_name'      => 'count-image-jpeg-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post_mime_type' => 'image/png',
						'post_name'      => 'count-image-png-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post_mime_type' => 'application/pdf',
						'post_name'      => 'count-pdf-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'publish',
						'post_mime_type' => 'text/plain',
						'post_name'      => 'count-text-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'trash',
						'post_mime_type' => 'image/jpeg',
						'post_name'      => 'count-image-trash-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'trash',
						'post_mime_type' => 'application/pdf',
						'post_name'      => 'count-pdf-trash-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post_mime_type' => '',
						'post_name'      => 'count-empty-mime-' . $case['token'],
					)
				),
			);
			$custom_mime_ids = array(
				$insert(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'publish',
						'post_author'    => $author_id,
						'post_mime_type' => $custom_mime,
						'post_name'      => 'count-custom-mime-' . $case['token'],
					)
				),
				$insert(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'publish',
						'post_author'    => $author_id,
						'post_mime_type' => '',
						'post_name'      => 'count-custom-empty-mime-' . $case['token'],
					)
				),
			);
			$attachment_rows_ok = array() === array_filter(
				array_merge( $attachment_ids, $custom_mime_ids ),
				static function ( $post_id ) {
					return ! is_int( $post_id ) || $post_id <= 0;
				}
			);

			self::collect_failure(
				$failures,
				$attachment_rows_ok,
				'MIME fixture posts and attachments insert with integer IDs',
				array(
					'attachmentIds' => $attachment_ids,
					'customIds'     => $custom_mime_ids,
				)
			);

			$all_attachment_counts = \wp_count_attachments();
			$all_attachment_cache  = \wp_cache_get( 'attachments', 'counts' );
			$image_counts          = \wp_count_attachments( 'image' );
			$jpeg_counts           = \wp_count_attachments( 'image/jpeg' );
			$jpeg_pdf_counts       = \wp_count_attachments( array( 'image/jpeg', 'application/pdf' ) );
			$jpeg_cache            = \wp_cache_get( 'attachments:image_jpeg', 'counts' );
			$jpeg_pdf_cache        = \wp_cache_get( 'attachments:image_jpeg-application_pdf', 'counts' );

			self::collect_failure(
				$failures,
				self::object_counts_include(
					$all_attachment_counts,
					array(
						'image/jpeg'      => 1,
						'image/png'       => 1,
						'application/pdf' => 1,
						'text/plain'      => 1,
						'trash'           => 2,
					)
				)
					&& self::object_counts_include(
						$all_attachment_cache,
						array(
							'image/jpeg'      => 1,
							'image/png'       => 1,
							'application/pdf' => 1,
							'text/plain'      => 1,
							'trash'           => 2,
						)
					),
				'wp_count_attachments groups non-trash attachments and separately counts trash',
				array(
					'counts' => $all_attachment_counts,
					'cached' => $all_attachment_cache,
				)
			);
			self::collect_failure(
				$failures,
				self::object_counts_include(
					$image_counts,
					array(
						'image/jpeg' => 1,
						'image/png'  => 1,
						'trash'      => 1,
					)
				)
					&& self::object_counts_omit( $image_counts, array( 'application/pdf', 'text/plain' ) )
					&& self::object_counts_include(
						$jpeg_counts,
						array(
							'image/jpeg' => 1,
							'trash'      => 1,
						)
					)
					&& self::object_counts_omit( $jpeg_counts, array( 'image/png', 'application/pdf' ) )
					&& self::object_counts_include(
						$jpeg_pdf_counts,
						array(
							'image/jpeg'      => 1,
							'application/pdf' => 1,
							'trash'           => 2,
						)
					)
					&& self::object_counts_include(
						$jpeg_cache,
						array(
							'image/jpeg' => 1,
							'trash'      => 1,
						)
					)
					&& self::object_counts_include(
						$jpeg_pdf_cache,
						array(
							'image/jpeg'      => 1,
							'application/pdf' => 1,
							'trash'           => 2,
						)
					),
				'wp_count_attachments honors exact, wildcard, array MIME filters and cache keys',
				array(
					'image'        => $image_counts,
					'jpeg'         => $jpeg_counts,
					'jpegPdf'      => $jpeg_pdf_counts,
					'jpegCache'    => $jpeg_cache,
					'jpegPdfCache' => $jpeg_pdf_cache,
				)
			);
			self::collect_failure(
				$failures,
				count( $attachment_count_events ) >= 4,
				'wp_count_attachments filter receives MIME query context',
				array( 'events' => $attachment_count_events )
			);

			$mime_groups       = \get_post_mime_types();
			$mime_group_keys   = array_keys( $mime_groups );
			$has_document_key  = false;
			foreach ( $mime_group_keys as $mime_group_key ) {
				if ( str_contains( (string) $mime_group_key, 'application/pdf' ) ) {
					$has_document_key = true;
					break;
				}
			}

			self::collect_failure(
				$failures,
				isset( $mime_groups['image'], $mime_groups['audio'], $mime_groups['video'], $mime_groups[ $sentinel_mime_group ] )
					&& ! isset( $mime_groups['document'] )
					&& $has_document_key
					&& self::mime_label_tuple_has_shape( $mime_groups['image'] )
					&& self::mime_label_tuple_has_shape( $mime_groups[ $sentinel_mime_group ] ),
				'get_post_mime_types returns converted MIME groups and applies filter additions',
				array(
					'keys'   => $mime_group_keys,
					'events' => $post_mime_events,
				)
			);
			self::collect_failure(
				$failures,
				isset( $post_mime_events[0] )
					&& in_array( 'image', $post_mime_events[0]['keys'], true )
					&& ! in_array( $sentinel_mime_group, $post_mime_events[0]['keys'], true ),
				'post_mime_types filter observes converted groups before appending sentinel data',
				array( 'events' => $post_mime_events )
			);

			$attachment_mimes = \get_available_post_mime_types( 'attachment' );
			$custom_mimes     = \get_available_post_mime_types( $post_type );
			$missing_mimes    = \get_available_post_mime_types( $post_type . '_missing' );

			self::collect_failure(
				$failures,
				self::sorted_string_values( $attachment_mimes ) === self::sorted_string_values(
					array(
						'image/jpeg',
						'image/png',
						'application/pdf',
						'text/plain',
					)
				)
					&& self::sorted_string_values( $custom_mimes ) === array( $custom_mime )
					&& array() === $missing_mimes,
				'get_available_post_mime_types returns unique non-empty MIME values by post type',
				array(
					'attachment' => $attachment_mimes,
					'custom'     => $custom_mimes,
					'missing'    => $missing_mimes,
				)
			);

			$query_count_before      = $GLOBALS['wpdb']->num_queries ?? null;
			$available_short_circuit = array( '', null, false, 'image/from-filter', 'application/from-filter' );
			$short_circuit_mimes     = \get_available_post_mime_types( 'attachment' );
			$query_count_after       = $GLOBALS['wpdb']->num_queries ?? null;

			self::collect_failure(
				$failures,
				array( 'image/from-filter', 'application/from-filter' ) === $short_circuit_mimes
					&& $query_count_before === $query_count_after,
				'pre_get_available_post_mime_types short-circuits DB queries and filters falsey entries',
				array(
					'before' => $query_count_before,
					'after'  => $query_count_after,
					'result' => $short_circuit_mimes,
					'events' => $available_mime_events,
				)
			);
		} finally {
			\remove_filter( 'wp_count_posts', $post_count_filter, 10 );
			\remove_filter( 'wp_count_attachments', $attachment_count_filter, 10 );
			\remove_filter( 'post_mime_types', $post_mime_filter, 10 );
			\remove_filter( 'pre_get_available_post_mime_types', $available_mime_filter, 10 );
			$filters_removed = false === \has_filter( 'wp_count_posts', $post_count_filter )
				&& false === \has_filter( 'wp_count_attachments', $attachment_count_filter )
				&& false === \has_filter( 'post_mime_types', $post_mime_filter )
				&& false === \has_filter( 'pre_get_available_post_mime_types', $available_mime_filter );
			\wp_set_current_user( 0 );
		}

		self::collect_failure(
			$failures,
			$filters_removed,
			'post count and MIME helper filters are removed',
			array(
				'wpCountPosts'      => \has_filter( 'wp_count_posts', $post_count_filter ),
				'wpCountAttachments' => \has_filter( 'wp_count_attachments', $attachment_count_filter ),
				'postMimeTypes'     => \has_filter( 'post_mime_types', $post_mime_filter ),
				'availableMimes'    => \has_filter( 'pre_get_available_post_mime_types', $available_mime_filter ),
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.counts-and-mime-helpers',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postType' => $post_type,
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_thumbnail_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures            = array();
		$token               = $case['token'];
		$image_sources       = array();
		$source_events       = array();
		$attribute_events    = array();
		$loading_events      = array();
		$thumbnail_id_events = array();
		$has_events          = array();
		$size_events         = array();
		$fetch_events        = array();
		$html_events         = array();
		$url_events          = array();
		$caption_events      = array();
		$display_events      = array();
		$previous_post_set   = array_key_exists( 'post', $GLOBALS );
		$previous_post       = $GLOBALS['post'] ?? null;
		$filters_removed     = false;

		$image_source_filter = static function ( $image, int $attachment_id, $size, bool $icon ) use ( &$image_sources, &$source_events ) {
			$size_key        = is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size;
			$source_events[] = array(
				'id'      => $attachment_id,
				'size'    => $size_key,
				'icon'    => $icon,
				'matched' => isset( $image_sources[ $attachment_id ] ),
			);

			if ( ! isset( $image_sources[ $attachment_id ] ) ) {
				return $image;
			}

			$source = $image_sources[ $attachment_id ];
			return array(
				$source['base'] . '-' . $size_key . '.jpg',
				$source['width'],
				$source['height'],
				true,
			);
		};
		$attribute_filter    = static function ( array $attr, \WP_Post $attachment, $size ) use ( &$attribute_events, $token ): array {
			$size_key           = is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size;
			$attribute_events[] = array(
				'id'       => (int) $attachment->ID,
				'size'     => $size_key,
				'class'    => $attr['class'] ?? '',
				'hasAlt'   => array_key_exists( 'alt', $attr ),
				'hasWidth' => array_key_exists( 'width', $attr ),
			);
			$attr['class']      = trim( (string) ( $attr['class'] ?? '' ) . ' component-thumbnail-' . $token );
			$attr['data-thumb'] = 'filtered <' . $token . '>';

			return $attr;
		};
		$auto_sizes_filter   = static function (): bool {
			return false;
		};
		$loading_filter      = static function ( $loading_attrs, string $tag_name, array $attr, string $context ) use ( &$loading_events ): array {
			$loading_events[] = array(
				'tag'     => $tag_name,
				'context' => $context,
				'keys'    => array_keys( $attr ),
			);

			return array();
		};
		$thumbnail_id_filter = static function ( $thumbnail_id, $post ) use ( &$thumbnail_id_events ) {
			$thumbnail_id_events[] = array(
				'thumbnailId' => (int) $thumbnail_id,
				'post'        => $post instanceof \WP_Post ? (int) $post->ID : $post,
			);

			return $thumbnail_id;
		};
		$has_filter          = static function ( bool $has_thumbnail, $post, $thumbnail_id ) use ( &$has_events ): bool {
			$has_events[] = array(
				'has'         => $has_thumbnail,
				'post'        => $post instanceof \WP_Post ? (int) $post->ID : $post,
				'thumbnailId' => $thumbnail_id,
			);

			return $has_thumbnail;
		};
		$size_filter         = static function ( $size, int $post_id ) use ( &$size_events ) {
			$size_events[] = array(
				'postId' => $post_id,
				'size'   => is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size,
			);

			return 'post-thumbnail' === $size ? 'medium' : $size;
		};
		$begin_fetch_action  = static function ( int $post_id, int $thumbnail_id, $size ) use ( &$fetch_events ): void {
			$fetch_events[] = array(
				'hook'        => 'begin',
				'postId'      => $post_id,
				'thumbnailId' => $thumbnail_id,
				'size'        => is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size,
			);
		};
		$end_fetch_action    = static function ( int $post_id, int $thumbnail_id, $size ) use ( &$fetch_events ): void {
			$fetch_events[] = array(
				'hook'        => 'end',
				'postId'      => $post_id,
				'thumbnailId' => $thumbnail_id,
				'size'        => is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size,
			);
		};
		$html_filter         = static function ( string $html, int $post_id, int $thumbnail_id, $size, $attr ) use ( &$html_events, $token ): string {
			$html_events[] = array(
				'postId'      => $post_id,
				'thumbnailId' => $thumbnail_id,
				'size'        => is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size,
				'hasHtml'     => '' !== $html,
				'attrKeys'    => is_array( $attr ) ? array_keys( $attr ) : array(),
			);

			if ( '' === $html ) {
				return $html;
			}

			return $html . '<span data-component-fuzz-thumbnail="' . $token . '"></span>';
		};
		$url_filter          = static function ( $thumbnail_url, $post, $size ) use ( &$url_events, $token ) {
			$url_events[] = array(
				'url'  => $thumbnail_url,
				'post' => $post instanceof \WP_Post ? (int) $post->ID : $post,
				'size' => is_array( $size ) ? implode( 'x', array_map( 'intval', $size ) ) : (string) $size,
			);

			return is_string( $thumbnail_url ) ? $thumbnail_url . '?thumb=' . $token : $thumbnail_url;
		};
		$caption_filter      = static function ( string $caption, int $post_id ) use ( &$caption_events, $token ): string {
			$caption_events[] = array(
				'postId'  => $post_id,
				'caption' => $caption,
			);

			return $caption . ' attachment-filter-' . $token;
		};
		$display_filter      = static function ( string $caption ) use ( &$display_events, $token ): string {
			$display_events[] = $caption;

			return 'display-' . $token . ':' . $caption;
		};

		\add_filter( 'wp_get_attachment_image_src', $image_source_filter, 10, 4 );
		\add_filter( 'wp_get_attachment_image_attributes', $attribute_filter, 10, 3 );
		\add_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter );
		\add_filter( 'pre_wp_get_loading_optimization_attributes', $loading_filter, 10, 4 );
		\add_filter( 'post_thumbnail_id', $thumbnail_id_filter, 10, 2 );
		\add_filter( 'has_post_thumbnail', $has_filter, 10, 3 );
		\add_filter( 'post_thumbnail_size', $size_filter, 10, 2 );
		\add_action( 'begin_fetch_post_thumbnail_html', $begin_fetch_action, 10, 3 );
		\add_action( 'end_fetch_post_thumbnail_html', $end_fetch_action, 10, 3 );
		\add_filter( 'post_thumbnail_html', $html_filter, 10, 5 );
		\add_filter( 'post_thumbnail_url', $url_filter, 10, 3 );
		\add_filter( 'wp_get_attachment_caption', $caption_filter, 10, 2 );
		\add_filter( 'the_post_thumbnail_caption', $display_filter );

		try {
			$insert = static function ( array $args ) use ( $case ) {
				return \wp_insert_post(
					\wp_slash(
						array_merge(
							array(
								'post_type'    => 'post',
								'post_title'   => 'Thumbnail Fixture ' . $case['token'],
								'post_content' => 'Thumbnail fixture content ' . $case['token'],
								'post_status'  => 'publish',
								'post_name'    => 'thumbnail-fixture-' . $case['token'],
							),
							$args
						)
					),
					true,
					false
				);
			};

			$post_id        = $insert(
				array(
					'post_name' => 'thumbnail-host-' . $token,
				)
			);
			$cache_post_id  = $insert(
				array(
					'post_name' => 'thumbnail-cache-host-' . $token,
				)
			);
			$image_id       = $insert(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_parent'    => $post_id,
					'post_mime_type' => 'image/jpeg',
					'post_excerpt'   => 'Caption <strong>' . $token . '</strong>',
					'post_name'      => 'thumbnail-image-' . $token,
				)
			);
			$second_image_id = $insert(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_parent'    => $post_id,
					'post_mime_type' => 'image/png',
					'post_excerpt'   => 'Second caption ' . $token,
					'post_name'      => 'thumbnail-second-image-' . $token,
				)
			);
			$non_image_id   = $insert(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_parent'    => $post_id,
					'post_mime_type' => 'text/plain',
					'post_name'      => 'thumbnail-non-image-' . $token,
				)
			);

			$fixture_ids_ok = array() === array_filter(
				array( $post_id, $cache_post_id, $image_id, $second_image_id, $non_image_id ),
				static function ( $id ) {
					return ! is_int( $id ) || $id <= 0;
				}
			);

			self::collect_failure(
				$failures,
				$fixture_ids_ok,
				'post thumbnail fixture posts and attachments insert with integer IDs',
				array(
					'postId'        => $post_id,
					'cachePostId'   => $cache_post_id,
					'imageId'       => $image_id,
					'secondImageId' => $second_image_id,
					'nonImageId'    => $non_image_id,
				)
			);

			$image_sources = array(
				$image_id        => array(
					'base'   => 'https://example.test/uploads/' . $token . '/primary',
					'width'  => 640,
					'height' => 360,
				),
				$second_image_id => array(
					'base'   => 'https://example.test/uploads/' . $token . '/secondary',
					'width'  => 320,
					'height' => 180,
				),
			);

			$missing_thumbnail_id = \get_post_thumbnail_id( $post_id );
			$missing_has         = \has_post_thumbnail( $post_id );
			$missing_html        = \get_the_post_thumbnail( $post_id );
			$missing_url         = \get_the_post_thumbnail_url( $post_id );
			$missing_caption     = \get_the_post_thumbnail_caption( $post_id );

			self::collect_failure(
				$failures,
				0 === $missing_thumbnail_id
					&& false === $missing_has
					&& '' === $missing_html
					&& false === $missing_url
					&& '' === $missing_caption
					&& false === \get_post_thumbnail_id( 987654321 ),
				'missing thumbnails fail closed across ID, boolean, HTML, URL, and caption helpers',
				array(
					'id'      => $missing_thumbnail_id,
					'has'     => $missing_has,
					'html'    => $missing_html,
					'url'     => $missing_url,
					'caption' => $missing_caption,
				)
			);

			$first_set     = \set_post_thumbnail( $post_id, $image_id );
			$after_set_id  = \get_post_thumbnail_id( $post_id );
			$after_set_has = \has_post_thumbnail( \get_post( $post_id ) );
			$same_set      = \set_post_thumbnail( \get_post( $post_id ), $image_id );

			self::collect_failure(
				$failures,
				is_int( $first_set )
					&& $first_set > 0
					&& $image_id === $after_set_id
					&& true === $after_set_has
					&& false === $same_set
					&& \metadata_exists( 'post', $post_id, '_thumbnail_id' ),
				'set_post_thumbnail inserts thumbnail metadata, exposes it through helpers, and rejects unchanged updates',
				array(
					'firstSet'    => $first_set,
					'thumbnailId' => $after_set_id,
					'has'         => $after_set_has,
					'sameSet'     => $same_set,
				)
			);

			$GLOBALS['post'] = \get_post( $post_id );
			$thumbnail_html  = \get_the_post_thumbnail(
				$post_id,
				'post-thumbnail',
				array(
					'alt'             => 'Featured <Alt> ' . $token,
					'class'           => 'featured-thumb',
					'loading'         => false,
					'decoding'        => 'sync',
					'fetchpriority'   => false,
					'data-raw'        => 'raw "' . $token,
				)
			);
			ob_start();
			\the_post_thumbnail(
				'post-thumbnail',
				array(
					'alt'           => 'Featured <Alt> ' . $token,
					'class'         => 'featured-thumb',
					'loading'       => false,
					'decoding'      => 'sync',
					'fetchpriority' => false,
					'data-raw'      => 'raw "' . $token,
				)
			);
			$echoed_thumbnail = (string) ob_get_clean();

			self::collect_failure(
				$failures,
				$thumbnail_html === $echoed_thumbnail
					&& str_contains( $thumbnail_html, '<img ' )
					&& str_contains( $thumbnail_html, 'src="https://example.test/uploads/' . $token . '/primary-medium.jpg"' )
					&& str_contains( $thumbnail_html, 'width="640"' )
					&& str_contains( $thumbnail_html, 'height="360"' )
					&& str_contains( $thumbnail_html, 'alt="Featured &lt;Alt&gt; ' . $token . '"' )
					&& str_contains( $thumbnail_html, 'class="featured-thumb component-thumbnail-' . $token . '"' )
					&& str_contains( $thumbnail_html, 'decoding="sync"' )
					&& str_contains( $thumbnail_html, 'data-raw="raw &quot;' . $token . '"' )
					&& str_contains( $thumbnail_html, 'data-thumb="filtered &lt;' . $token . '&gt;"' )
					&& str_contains( $thumbnail_html, 'data-component-fuzz-thumbnail="' . $token . '"' )
					&& ! str_contains( $thumbnail_html, ' loading=' )
					&& ! str_contains( $thumbnail_html, ' fetchpriority=' ),
				'get_the_post_thumbnail and the_post_thumbnail render filtered, escaped image markup consistently',
				array(
					'html'      => self::describe_string( $thumbnail_html ),
					'echoed'    => self::describe_string( $echoed_thumbnail ),
					'attrs'     => $attribute_events,
					'loading'   => $loading_events,
					'htmlHooks' => $html_events,
				)
			);

			$thumbnail_url = \get_the_post_thumbnail_url( $post_id, array( 320, 180 ) );
			ob_start();
			\the_post_thumbnail_url( array( 320, 180 ) );
			$echoed_url = (string) ob_get_clean();

			self::collect_failure(
				$failures,
				'https://example.test/uploads/' . $token . '/primary-320x180.jpg?thumb=' . $token === $thumbnail_url
					&& \esc_url( $thumbnail_url ) === $echoed_url,
				'post thumbnail URL helpers use attachment image URLs, filters, and escaped echo output',
				array(
					'url'    => $thumbnail_url,
					'echoed' => $echoed_url,
					'events' => $url_events,
				)
			);

			$caption = \get_the_post_thumbnail_caption( $post_id );
			ob_start();
			\the_post_thumbnail_caption( $post_id );
			$echoed_caption = (string) ob_get_clean();

			self::collect_failure(
				$failures,
				'Caption <strong>' . $token . '</strong> attachment-filter-' . $token === $caption
					&& 'display-' . $token . ':' . $caption === $echoed_caption,
				'post thumbnail caption helpers read attachment excerpts and apply attachment/display filters',
				array(
					'caption'       => $caption,
					'echoedCaption' => $echoed_caption,
					'captionEvents' => $caption_events,
					'displayEvents' => $display_events,
				)
			);

			\update_post_meta( $cache_post_id, '_thumbnail_id', $image_id );
			$query              = (object) array(
				'posts'              => array( \get_post( $post_id ), $cache_post_id ),
				'thumbnails_cached' => false,
			);
			\update_post_thumbnail_cache( $query );
			$query_count_before = $GLOBALS['wpdb']->num_queries ?? null;
			\update_post_thumbnail_cache( $query );
			$query_count_after  = $GLOBALS['wpdb']->num_queries ?? null;

			self::collect_failure(
				$failures,
				true === $query->thumbnails_cached
					&& $query_count_before === $query_count_after,
				'update_post_thumbnail_cache primes mixed post object/ID loops once and marks the query cached',
				array(
					'query'  => $query,
					'before' => $query_count_before,
					'after'  => $query_count_after,
				)
			);

			$second_set       = \set_post_thumbnail( $post_id, $second_image_id );
			$after_second_id = \get_post_thumbnail_id( $post_id );
			$non_image_set   = \set_post_thumbnail( $post_id, $non_image_id );
			$after_non_image = \get_post_thumbnail_id( $post_id );
			$reset_set       = \set_post_thumbnail( $post_id, $image_id );
			$delete_thumb    = \delete_post_thumbnail( \get_post( $post_id ) );
			$after_delete    = \get_post_thumbnail_id( $post_id );
			$missing_set     = \set_post_thumbnail( 987654321, $image_id );
			$missing_image   = \set_post_thumbnail( $post_id, 987654321 );
			$missing_delete  = \delete_post_thumbnail( 987654321 );

			self::collect_failure(
				$failures,
				true === $second_set
					&& $second_image_id === $after_second_id
					&& true === $non_image_set
					&& 0 === $after_non_image
					&& is_int( $reset_set )
					&& true === $delete_thumb
					&& 0 === $after_delete
					&& ! \metadata_exists( 'post', $post_id, '_thumbnail_id' )
					&& false === $missing_set
					&& false === $missing_image
					&& false === $missing_delete,
				'post thumbnail update/delete paths handle new images, non-image cleanup, and missing objects',
				array(
					'secondSet'     => $second_set,
					'afterSecond'   => $after_second_id,
					'nonImageSet'   => $non_image_set,
					'afterNonImage' => $after_non_image,
					'resetSet'      => $reset_set,
					'delete'        => $delete_thumb,
					'afterDelete'   => $after_delete,
					'missingSet'    => $missing_set,
					'missingImage'  => $missing_image,
					'missingDelete' => $missing_delete,
				)
			);

			self::collect_failure(
				$failures,
				self::events_contain_thumbnail_fetch_pair( $fetch_events, $post_id, $image_id, 'medium' )
					&& count( $source_events ) >= 6
					&& count( $thumbnail_id_events ) >= 6
					&& count( $has_events ) >= 2
					&& in_array(
						array(
							'postId' => $post_id,
							'size'   => 'post-thumbnail',
						),
						$size_events,
						true
					),
				'post thumbnail hooks observe expected IDs, sizes, source lookups, and boolean states',
				array(
					'sources'      => $source_events,
					'thumbnailIds' => $thumbnail_id_events,
					'hasEvents'    => $has_events,
					'sizeEvents'   => $size_events,
					'fetchEvents'  => $fetch_events,
				)
			);
		} finally {
			\remove_filter( 'the_post_thumbnail_caption', $display_filter, 10 );
			\remove_filter( 'wp_get_attachment_caption', $caption_filter, 10 );
			\remove_filter( 'post_thumbnail_url', $url_filter, 10 );
			\remove_filter( 'post_thumbnail_html', $html_filter, 10 );
			\remove_action( 'end_fetch_post_thumbnail_html', $end_fetch_action, 10 );
			\remove_action( 'begin_fetch_post_thumbnail_html', $begin_fetch_action, 10 );
			\remove_filter( 'post_thumbnail_size', $size_filter, 10 );
			\remove_filter( 'has_post_thumbnail', $has_filter, 10 );
			\remove_filter( 'post_thumbnail_id', $thumbnail_id_filter, 10 );
			\remove_filter( 'pre_wp_get_loading_optimization_attributes', $loading_filter, 10 );
			\remove_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter, 10 );
			\remove_filter( 'wp_get_attachment_image_attributes', $attribute_filter, 10 );
			\remove_filter( 'wp_get_attachment_image_src', $image_source_filter, 10 );
			$filters_removed = false === \has_filter( 'wp_get_attachment_image_src', $image_source_filter )
				&& false === \has_filter( 'wp_get_attachment_image_attributes', $attribute_filter )
				&& false === \has_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter )
				&& false === \has_filter( 'pre_wp_get_loading_optimization_attributes', $loading_filter )
				&& false === \has_filter( 'post_thumbnail_id', $thumbnail_id_filter )
				&& false === \has_filter( 'has_post_thumbnail', $has_filter )
				&& false === \has_filter( 'post_thumbnail_size', $size_filter )
				&& false === \has_filter( 'begin_fetch_post_thumbnail_html', $begin_fetch_action )
				&& false === \has_filter( 'end_fetch_post_thumbnail_html', $end_fetch_action )
				&& false === \has_filter( 'post_thumbnail_html', $html_filter )
				&& false === \has_filter( 'post_thumbnail_url', $url_filter )
				&& false === \has_filter( 'wp_get_attachment_caption', $caption_filter )
				&& false === \has_filter( 'the_post_thumbnail_caption', $display_filter );

			if ( $previous_post_set ) {
				$GLOBALS['post'] = $previous_post;
			} else {
				unset( $GLOBALS['post'] );
			}
		}

		self::collect_failure(
			$failures,
			$filters_removed,
			'post thumbnail helper filters and actions are removed',
			array(
				'imageSrc'       => \has_filter( 'wp_get_attachment_image_src', $image_source_filter ),
				'imageAttrs'     => \has_filter( 'wp_get_attachment_image_attributes', $attribute_filter ),
				'autoSizes'      => \has_filter( 'wp_img_tag_add_auto_sizes', $auto_sizes_filter ),
				'loadingAttrs'   => \has_filter( 'pre_wp_get_loading_optimization_attributes', $loading_filter ),
				'thumbnailId'    => \has_filter( 'post_thumbnail_id', $thumbnail_id_filter ),
				'hasThumbnail'   => \has_filter( 'has_post_thumbnail', $has_filter ),
				'thumbnailSize'  => \has_filter( 'post_thumbnail_size', $size_filter ),
				'beginFetch'     => \has_filter( 'begin_fetch_post_thumbnail_html', $begin_fetch_action ),
				'endFetch'       => \has_filter( 'end_fetch_post_thumbnail_html', $end_fetch_action ),
				'thumbnailHtml'  => \has_filter( 'post_thumbnail_html', $html_filter ),
				'thumbnailUrl'   => \has_filter( 'post_thumbnail_url', $url_filter ),
				'caption'        => \has_filter( 'wp_get_attachment_caption', $caption_filter ),
				'displayCaption' => \has_filter( 'the_post_thumbnail_caption', $display_filter ),
			)
		);

		return $ctx->result(
			'content-lifecycle.posts.thumbnail-helpers',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_invalid_inputs( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures       = array();
		$missing_post   = \wp_update_post( array( 'ID' => 987654321, 'post_title' => $case['title'] ), true );
		$missing_term   = \get_term( 987654321, 'category' );
		$missing_user   = \get_userdata( 987654321 );
		$missing_comment = \get_comment( 987654321 );

		self::collect_failure(
			$failures,
			\is_wp_error( $missing_post ) && 'invalid_post' === $missing_post->get_error_code(),
			'updating a missing post returns invalid_post WP_Error',
			array( 'missingPost' => self::error_summary( $missing_post ) )
		);
		self::collect_failure(
			$failures,
			null === $missing_term
				&& false === $missing_user
				&& null === $missing_comment,
			'missing read APIs fail closed without creating rows',
			array(
				'missingTerm'    => $missing_term,
				'missingUser'    => $missing_user,
				'missingComment' => $missing_comment,
			)
		);

		return $ctx->result(
			'content-lifecycle.invalid-inputs-fail-closed',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 4 ),
			)
		);
	}

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$wpdb             = $GLOBALS['wpdb'] ?? null;
		$status_post_type = self::status_transition_post_type( $case );
		$status_hooks     = array(
			'publish_' . $status_post_type,
			'trash_' . $status_post_type,
			'draft_' . $status_post_type,
			'future_' . $status_post_type,
		);
		$counts           = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_content_counts() : array();
		$count_ok         = array() === array_filter( $counts );
		$cache_ok         = false === \wp_cache_get( $case['token'], 'component-fuzz-lifecycle' );
		$type_ok          = ! \post_type_exists( $case['postType'] );
		$status_type_ok   = ! \post_type_exists( $status_post_type );
		$dynamic_ok       = false === has_filter( 'save_post_' . $case['postType'] );
		$status_hooks_ok  = self::hooks_absent( $status_hooks );

		return $ctx->result(
			'content-lifecycle.state-restored-between-iterations',
			$count_ok && $cache_ok && $type_ok && $status_type_ok && $dynamic_ok && $status_hooks_ok,
			array(
				'counts'             => $counts,
				'cacheSentinelEmpty' => $cache_ok,
				'postTypeGone'       => $type_ok,
				'statusPostTypeGone' => $status_type_ok,
				'dynamicHookGone'    => $dynamic_ok,
				'statusHooksGone'    => $status_hooks_ok,
			)
		);
	}

	private static function prepare_runtime(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->component_fuzz_reset_content();
		$wpdb->component_fuzz_reset_options(
			array(
				'admin_email'            => 'admin@example.test',
				'blog_charset'           => 'UTF-8',
				'blogname'               => 'Component Fuzz',
				'comment_max_links'      => 2,
				'comment_moderation'     => 0,
				'comment_registration'   => 0,
				'default_category'       => 0,
				'default_comment_status' => 'open',
				'default_ping_status'    => 'closed',
				'default_role'           => 'subscriber',
				'disallowed_keys'        => '',
				'home'                   => 'http://example.test',
				'moderation_keys'        => '',
				'permalink_structure'    => '',
				'require_name_email'     => 0,
				'siteurl'                => 'http://example.test',
				'sticky_posts'           => array(),
			)
		);

		\wp_cache_flush();
		$GLOBALS['wp_rewrite']      = new \WP_Rewrite();
		$GLOBALS['wp_post_types']   = array();
		$GLOBALS['wp_taxonomies']   = array();
		$GLOBALS['wp_post_statuses'] = array();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_SERVER['REMOTE_ADDR']      = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT']  = 'ComponentFuzz ContentLifecycle';
		$_SERVER['REQUEST_URI']      = '/component-fuzz/content-lifecycle/';
		$_SERVER['HTTP_HOST']        = 'example.test';
		$_SERVER['SERVER_SOFTWARE']  = 'ComponentFuzz';
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', '_wp_post_type_features', 'post_type_meta_caps', 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'wp_rewrite', 'current_user', 'user_ID' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_SOFTWARE' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'server'  => $server,
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}
	}

	private static function install_post_hooks( string $post_type, array &$events ): array {
		$hooks = array();
		$add   = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};

		$add(
			'pre_post_insert',
			static function () use ( &$events ): void {
				$events[] = 'pre_post_insert';
			},
			1
		);
		$add(
			'pre_post_update',
			static function () use ( &$events ): void {
				$events[] = 'pre_post_update';
			},
			2
		);
		$add(
			"save_post_{$post_type}",
			static function () use ( &$events, $post_type ): void {
				$events[] = "save_post_{$post_type}";
			},
			3
		);
		$add(
			'save_post',
			static function () use ( &$events ): void {
				$events[] = 'save_post';
			},
			3
		);
		$add(
			'wp_insert_post',
			static function () use ( &$events ): void {
				$events[] = 'wp_insert_post';
			},
			3
		);
		$add(
			'post_updated',
			static function () use ( &$events ): void {
				$events[] = 'post_updated';
			},
			3
		);
		$add(
			'wp_after_insert_post',
			static function () use ( &$events ): void {
				$events[] = 'wp_after_insert_post';
			},
			4
		);

		return $hooks;
	}

	private static function install_admin_bulk_edit_hooks( array &$events ): array {
		$hooks = array();
		$add   = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};

		$add(
			'bulk_edit_posts',
			static function ( array $updated, array $shared_post_data ) use ( &$events ): void {
				$events[] = array(
					'hook'         => 'bulk_edit_posts',
					'updated'      => self::normalize_int_list( $updated ),
					'postIds'      => self::normalize_int_list( (array) ( $shared_post_data['post'] ?? array() ) ),
					'postStatus'   => $shared_post_data['post_status'] ?? null,
					'postParent'   => isset( $shared_post_data['post_parent'] ) ? (int) $shared_post_data['post_parent'] : null,
					'hasStatus'    => array_key_exists( 'post_status', $shared_post_data ),
					'hasRawStatus' => array_key_exists( '_status', $shared_post_data ),
					'hasPostId'     => array_key_exists( 'post_ID', $shared_post_data ),
					'hasPostParent' => array_key_exists( 'post_parent', $shared_post_data ),
					'sticky'       => $shared_post_data['sticky'] ?? null,
				);
			},
			2
		);
		$add(
			'post_stuck',
			static function ( $post_id ) use ( &$events ): void {
				$events[] = array(
					'hook'   => 'post_stuck',
					'postId' => (int) $post_id,
				);
			},
			1
		);
		$add(
			'post_unstuck',
			static function ( $post_id ) use ( &$events ): void {
				$events[] = array(
					'hook'   => 'post_unstuck',
					'postId' => (int) $post_id,
				);
			},
			1
		);

		return $hooks;
	}

	private static function install_post_status_transition_hooks( string $post_type, array $transitions, array &$events ): array {
		$hooks = array();
		$add   = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 20, $accepted_args );
			$hooks[] = array( $hook, $callback, 20 );
		};

		$add(
			'transition_post_status',
			static function ( string $new_status, string $old_status, \WP_Post $post ) use ( &$events ): void {
				$events[] = array(
					'hook'   => 'transition_post_status',
					'new'    => $new_status,
					'old'    => $old_status,
					'postId' => (int) $post->ID,
					'type'   => (string) $post->post_type,
				);
			},
			3
		);

		foreach ( $transitions as $transition ) {
			list( $old_status, $new_status ) = $transition;
			$add(
				$old_status . '_to_' . $new_status,
				static function ( \WP_Post $post ) use ( &$events, $old_status, $new_status ): void {
					$events[] = array(
						'hook'   => $old_status . '_to_' . $new_status,
						'new'    => $new_status,
						'old'    => $old_status,
						'postId' => (int) $post->ID,
						'type'   => (string) $post->post_type,
					);
				},
				1
			);
		}

		foreach ( array_unique( array_column( $transitions, 1 ) ) as $new_status ) {
			$add(
				$new_status . '_' . $post_type,
				static function ( int $post_id, \WP_Post $post, string $old_status ) use ( &$events, $new_status, $post_type ): void {
					$events[] = array(
						'hook'   => $new_status . '_' . $post_type,
						'new'    => $new_status,
						'old'    => $old_status,
						'postId' => $post_id,
						'type'   => (string) $post->post_type,
					);
				},
				3
			);
		}

		return $hooks;
	}

	private static function transition_events_match( array $events, string $old_status, string $new_status, int $post_id, string $post_type ): bool {
		$expected = array(
			array(
				'hook'   => 'transition_post_status',
				'new'    => $new_status,
				'old'    => $old_status,
				'postId' => $post_id,
				'type'   => $post_type,
			),
			array(
				'hook'   => $old_status . '_to_' . $new_status,
				'new'    => $new_status,
				'old'    => $old_status,
				'postId' => $post_id,
				'type'   => $post_type,
			),
			array(
				'hook'   => $new_status . '_' . $post_type,
				'new'    => $new_status,
				'old'    => $old_status,
				'postId' => $post_id,
				'type'   => $post_type,
			),
		);

		return array_values( $events ) === $expected;
	}

	private static function transition_render_events_include_sequence( array $events, array $expected ): bool {
		$offset = -1;
		foreach ( $expected as $expected_event ) {
			$matched = false;
			for ( $i = $offset + 1, $count = count( $events ); $i < $count; ++$i ) {
				if ( ! is_array( $events[ $i ] ) ) {
					continue;
				}

				$event_matches = true;
				foreach ( $expected_event as $key => $value ) {
					if ( ! array_key_exists( $key, $events[ $i ] ) || $events[ $i ][ $key ] !== $value ) {
						$event_matches = false;
						break;
					}
				}

				if ( $event_matches ) {
					$offset  = $i;
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				return false;
			}
		}

		return true;
	}

	private static function seed_transition_caches( string $post_type, string $label ): void {
		\wp_cache_set( \_count_posts_cache_key( $post_type ), 'counts-' . $label, 'counts' );
		\wp_cache_set( \_count_posts_cache_key( $post_type, 'readable' ), 'readable-' . $label, 'counts' );

		foreach ( array( 'server', 'gmt', 'blog' ) as $timezone ) {
			\wp_cache_set( "lastpostmodified:{$timezone}", 'modified-' . $label, 'timeinfo' );
			\wp_cache_set( "lastpostmodified:{$timezone}:{$post_type}", 'modified-type-' . $label, 'timeinfo' );
			\wp_cache_set( "lastpostdate:{$timezone}", 'date-' . $label, 'timeinfo' );
			\wp_cache_set( "lastpostdate:{$timezone}:{$post_type}", 'date-type-' . $label, 'timeinfo' );
		}
	}

	private static function clear_transition_caches( string $post_type ): void {
		foreach ( array_unique( array( \_count_posts_cache_key( $post_type ), \_count_posts_cache_key( $post_type, 'readable' ) ) ) as $key ) {
			\wp_cache_delete( $key, 'counts' );
		}

		foreach ( array( 'server', 'gmt', 'blog' ) as $timezone ) {
			\wp_cache_delete( "lastpostmodified:{$timezone}", 'timeinfo' );
			\wp_cache_delete( "lastpostmodified:{$timezone}:{$post_type}", 'timeinfo' );
			\wp_cache_delete( "lastpostdate:{$timezone}", 'timeinfo' );
			\wp_cache_delete( "lastpostdate:{$timezone}:{$post_type}", 'timeinfo' );
		}
	}

	private static function transition_caches_match_expectations( string $post_type, string $old_status, string $new_status, string $label ): bool {
		$summary         = self::transition_cache_summary( $post_type );
		$status_changed  = $new_status !== $old_status;
		$publish_touched = 'publish' === $new_status || 'publish' === $old_status;

		if ( $status_changed ) {
			if ( false !== $summary['counts']['base']['value'] || false !== $summary['counts']['readable']['value'] ) {
				return false;
			}
		} else {
			$count_expected = self::transition_count_cache_expected_values( $post_type, $label );
			if (
				$summary['counts']['base']['value'] !== $count_expected['base']
				|| $summary['counts']['readable']['value'] !== $count_expected['readable']
			) {
				return false;
			}
		}

		foreach ( $summary['timeinfo'] as $timezone => $values ) {
			if ( $publish_touched ) {
				if (
					false !== $values['modified']
					|| false !== $values['date']
					|| false !== $values['datePostType']
					|| $values['modifiedPostType'] !== 'modified-type-' . $label
				) {
					return false;
				}
				continue;
			}

			if (
				$values['modified'] !== 'modified-' . $label
				|| $values['modifiedPostType'] !== 'modified-type-' . $label
				|| $values['date'] !== 'date-' . $label
				|| $values['datePostType'] !== 'date-type-' . $label
			) {
				return false;
			}
		}

		return true;
	}

	private static function transition_cache_summary( string $post_type ): array {
		$base_count_key     = \_count_posts_cache_key( $post_type );
		$readable_count_key = \_count_posts_cache_key( $post_type, 'readable' );
		$summary            = array(
			'counts'   => array(
				'base'     => array(
					'key'   => $base_count_key,
					'value' => \wp_cache_get( $base_count_key, 'counts' ),
				),
				'readable' => array(
					'key'   => $readable_count_key,
					'value' => \wp_cache_get( $readable_count_key, 'counts' ),
				),
			),
			'timeinfo' => array(),
		);

		foreach ( array( 'server', 'gmt', 'blog' ) as $timezone ) {
			$summary['timeinfo'][ $timezone ] = array(
				'modified'         => \wp_cache_get( "lastpostmodified:{$timezone}", 'timeinfo' ),
				'modifiedPostType' => \wp_cache_get( "lastpostmodified:{$timezone}:{$post_type}", 'timeinfo' ),
				'date'             => \wp_cache_get( "lastpostdate:{$timezone}", 'timeinfo' ),
				'datePostType'     => \wp_cache_get( "lastpostdate:{$timezone}:{$post_type}", 'timeinfo' ),
			);
		}

		return $summary;
	}

	private static function transition_count_cache_expected_values( string $post_type, string $label ): array {
		$base_key     = \_count_posts_cache_key( $post_type );
		$readable_key = \_count_posts_cache_key( $post_type, 'readable' );
		$expected     = array(
			'base'     => 'counts-' . $label,
			'readable' => 'readable-' . $label,
		);

		if ( $base_key === $readable_key ) {
			$expected['base'] = $expected['readable'];
		}

		return $expected;
	}

	private static function install_post_meta_hooks( string $meta_key, string $unique_key, array &$events ): array {
		return self::install_post_meta_hooks_for_keys( array( $meta_key, $unique_key ), $events );
	}

	private static function install_post_meta_hooks_for_keys( array $meta_keys, array &$events ): array {
		$hooks = array();
		$watch = array_fill_keys( array_map( 'strval', $meta_keys ), true );
		$add   = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};
		$record = static function ( string $event, $key ) use ( &$events, $watch ): void {
			if ( isset( $watch[ (string) $key ] ) ) {
				$events[] = $event;
			}
		};

		$add(
			'add_post_meta',
			static function ( $object_id, $key ) use ( $record ): void {
				$record( 'add_post_meta', $key );
			},
			3
		);
		$add(
			'added_post_meta',
			static function ( $mid, $object_id, $key ) use ( $record ): void {
				$record( 'added_post_meta', $key );
			},
			4
		);
		$add(
			'update_post_meta',
			static function ( $meta_id, $object_id, $key ) use ( $record ): void {
				$record( 'update_post_meta', $key );
			},
			4
		);
		$add(
			'updated_post_meta',
			static function ( $meta_id, $object_id, $key ) use ( $record ): void {
				$record( 'updated_post_meta', $key );
			},
			4
		);
		$add(
			'delete_post_meta',
			static function ( $meta_ids, $object_id, $key ) use ( $record ): void {
				$record( 'delete_post_meta', $key );
			},
			4
		);
		$add(
			'deleted_post_meta',
			static function ( $meta_ids, $object_id, $key ) use ( $record ): void {
				$record( 'deleted_post_meta', $key );
			},
			4
		);

		return $hooks;
	}

	private static function install_term_relationship_hooks( array &$events ): array {
		$hooks = array();
		$add   = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};

		$add(
			'add_term_relationship',
			static function ( $object_id, $tt_id, $taxonomy ) use ( &$events ): void {
				$events[] = array(
					'hook'     => 'add_term_relationship',
					'objectId' => (int) $object_id,
					'taxonomy' => (string) $taxonomy,
					'ttIds'    => array( (int) $tt_id ),
				);
			},
			3
		);
		$add(
			'added_term_relationship',
			static function ( $object_id, $tt_id, $taxonomy ) use ( &$events ): void {
				$events[] = array(
					'hook'     => 'added_term_relationship',
					'objectId' => (int) $object_id,
					'taxonomy' => (string) $taxonomy,
					'ttIds'    => array( (int) $tt_id ),
				);
			},
			3
		);
		$add(
			'delete_term_relationships',
			static function ( $object_id, $tt_ids, $taxonomy ) use ( &$events ): void {
				$events[] = array(
					'hook'     => 'delete_term_relationships',
					'objectId' => (int) $object_id,
					'taxonomy' => (string) $taxonomy,
					'ttIds'    => self::normalize_int_list( (array) $tt_ids ),
				);
			},
			3
		);
		$add(
			'deleted_term_relationships',
			static function ( $object_id, $tt_ids, $taxonomy ) use ( &$events ): void {
				$events[] = array(
					'hook'     => 'deleted_term_relationships',
					'objectId' => (int) $object_id,
					'taxonomy' => (string) $taxonomy,
					'ttIds'    => self::normalize_int_list( (array) $tt_ids ),
				);
			},
			3
		);
		$add(
			'set_object_terms',
			static function ( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) use ( &$events ): void {
				$events[] = array(
					'hook'     => 'set_object_terms',
					'objectId' => (int) $object_id,
					'taxonomy' => (string) $taxonomy,
					'append'   => (bool) $append,
					'terms'    => array_values( (array) $terms ),
					'ttIds'    => self::normalize_int_list( (array) $tt_ids ),
					'oldTtIds' => self::normalize_int_list( (array) $old_tt_ids ),
				);
			},
			6
		);

		return $hooks;
	}

	private static function install_term_lifecycle_hooks( array &$events ): array {
		$hooks  = array();
		$add    = static function ( string $hook, callable $callback, int $accepted_args ) use ( &$hooks ): void {
			\add_action( $hook, $callback, 10, $accepted_args );
			$hooks[] = array( $hook, $callback, 10 );
		};
		$record = static function ( array $event ) use ( &$events ): void {
			$events[] = $event;
		};

		$add(
			'edit_terms',
			static function ( $term_id, $taxonomy, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edit_terms',
						'termId'   => (int) $term_id,
						'taxonomy' => (string) $taxonomy,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			3
		);
		$add(
			'edited_terms',
			static function ( $term_id, $taxonomy, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edited_terms',
						'termId'   => (int) $term_id,
						'taxonomy' => (string) $taxonomy,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			3
		);
		$add(
			'edit_term_taxonomy',
			static function ( $tt_id, $taxonomy, $args = array() ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edit_term_taxonomy',
						'ttId'     => (int) $tt_id,
						'taxonomy' => (string) $taxonomy,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			3
		);
		$add(
			'edited_term_taxonomy',
			static function ( $tt_id, $taxonomy, $args = array() ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edited_term_taxonomy',
						'ttId'     => (int) $tt_id,
						'taxonomy' => (string) $taxonomy,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			3
		);
		$add(
			'edit_term',
			static function ( $term_id, $tt_id, $taxonomy, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edit_term',
						'termId'   => (int) $term_id,
						'ttId'     => (int) $tt_id,
						'taxonomy' => (string) $taxonomy,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			4
		);
		$add(
			'edit_category',
			static function ( $term_id, $tt_id, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edit_category',
						'termId'   => (int) $term_id,
						'ttId'     => (int) $tt_id,
						'taxonomy' => 'category',
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			3
		);
		$add(
			'edited_term',
			static function ( $term_id, $tt_id, $taxonomy, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edited_term',
						'termId'   => (int) $term_id,
						'ttId'     => (int) $tt_id,
						'taxonomy' => (string) $taxonomy,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			4
		);
		$add(
			'edited_category',
			static function ( $term_id, $tt_id, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'edited_category',
						'termId'   => (int) $term_id,
						'ttId'     => (int) $tt_id,
						'taxonomy' => 'category',
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			3
		);
		$add(
			'saved_term',
			static function ( $term_id, $tt_id, $taxonomy, $update, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'saved_term',
						'termId'   => (int) $term_id,
						'ttId'     => (int) $tt_id,
						'taxonomy' => (string) $taxonomy,
						'update'   => (bool) $update,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			5
		);
		$add(
			'saved_category',
			static function ( $term_id, $tt_id, $update, $args ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'saved_category',
						'termId'   => (int) $term_id,
						'ttId'     => (int) $tt_id,
						'taxonomy' => 'category',
						'update'   => (bool) $update,
						'args'     => self::term_hook_args_summary( $args ),
					)
				);
			},
			4
		);
		$add(
			'pre_delete_term',
			static function ( $term_id, $taxonomy ) use ( $record ): void {
				$record(
					array(
						'hook'     => 'pre_delete_term',
						'termId'   => (int) $term_id,
						'taxonomy' => (string) $taxonomy,
					)
				);
			},
			2
		);
		$add(
			'edit_term_taxonomies',
			static function ( $tt_ids ) use ( $record ): void {
				$record(
					array(
						'hook'  => 'edit_term_taxonomies',
						'ttIds' => self::normalize_int_list( (array) $tt_ids ),
					)
				);
			},
			1
		);
		$add(
			'edited_term_taxonomies',
			static function ( $tt_ids ) use ( $record ): void {
				$record(
					array(
						'hook'  => 'edited_term_taxonomies',
						'ttIds' => self::normalize_int_list( (array) $tt_ids ),
					)
				);
			},
			1
		);
		$add(
			'delete_term_taxonomy',
			static function ( $tt_id ) use ( $record ): void {
				$record(
					array(
						'hook' => 'delete_term_taxonomy',
						'ttId' => (int) $tt_id,
					)
				);
			},
			1
		);
		$add(
			'deleted_term_taxonomy',
			static function ( $tt_id ) use ( $record ): void {
				$record(
					array(
						'hook' => 'deleted_term_taxonomy',
						'ttId' => (int) $tt_id,
					)
				);
			},
			1
		);
		$add(
			'delete_term',
			static function ( $term_id, $tt_id, $taxonomy, $deleted_term, $object_ids ) use ( $record ): void {
				$record(
					array(
						'hook'      => 'delete_term',
						'termId'    => (int) $term_id,
						'ttId'      => (int) $tt_id,
						'taxonomy'  => (string) $taxonomy,
						'deleted'   => self::term_summary( $deleted_term ),
						'objectIds' => self::normalize_int_list( (array) $object_ids ),
					)
				);
			},
			5
		);
		$add(
			'delete_category',
			static function ( $term_id, $tt_id, $deleted_term, $object_ids ) use ( $record ): void {
				$record(
					array(
						'hook'      => 'delete_category',
						'termId'    => (int) $term_id,
						'ttId'      => (int) $tt_id,
						'taxonomy'  => 'category',
						'deleted'   => self::term_summary( $deleted_term ),
						'objectIds' => self::normalize_int_list( (array) $object_ids ),
					)
				);
			},
			4
		);

		return $hooks;
	}

	private static function remove_hooks( array $hooks ): void {
		foreach ( $hooks as $hook ) {
			\remove_action( $hook[0], $hook[1], $hook[2] );
		}
	}

	private static function term_hook_args_summary( $args ): array {
		if ( ! is_array( $args ) ) {
			return array();
		}

		$summary = array();
		foreach ( array( 'name', 'slug', 'description', 'parent' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$summary[ $key ] = $args[ $key ];
			}
		}

		return $summary;
	}

	private static function term_lifecycle_event_present( array $events, string $hook, ?int $term_id, ?int $tt_id, ?string $taxonomy ): bool {
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ( $event['hook'] ?? null ) !== $hook ) {
				continue;
			}

			if ( null !== $term_id && ( ! array_key_exists( 'termId', $event ) || (int) $event['termId'] !== $term_id ) ) {
				continue;
			}

			if ( null !== $tt_id && ( ! array_key_exists( 'ttId', $event ) || (int) $event['ttId'] !== $tt_id ) ) {
				continue;
			}

			if ( null !== $taxonomy && ( ! array_key_exists( 'taxonomy', $event ) || (string) $event['taxonomy'] !== $taxonomy ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	private static function admin_bulk_event_present( array $events, string $hook, array $expected ): bool {
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ( $event['hook'] ?? null ) !== $hook ) {
				continue;
			}

			$matched = true;
			foreach ( $expected as $key => $value ) {
				if ( ! array_key_exists( $key, $event ) ) {
					$matched = false;
					break;
				}

				if ( in_array( $key, array( 'updated', 'postIds' ), true ) ) {
					if ( self::normalize_int_list( (array) $event[ $key ] ) !== self::normalize_int_list( (array) $value ) ) {
						$matched = false;
						break;
					}
					continue;
				}

				if ( $event[ $key ] !== $value ) {
					$matched = false;
					break;
				}
			}

			if ( $matched ) {
				return true;
			}
		}

		return false;
	}

	private static function capability_event_present( array $events, string $capability ): bool {
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( $capability === (string) ( $event['requested'] ?? '' ) ) {
				return true;
			}

			if ( in_array( $capability, (array) ( $event['caps'] ?? array() ), true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function events_are_ordered( array $events, array $expected ): bool {
		$offset = -1;
		foreach ( $expected as $event ) {
			$found = array_search( $event, array_slice( $events, $offset + 1 ), true );
			if ( false === $found ) {
				return false;
			}
			$offset += $found + 1;
		}

		return true;
	}

	private static function term_objects_match_relationships( array $terms, int $object_id, array $term_ids, array $tt_ids ): bool {
		if ( count( $terms ) !== count( $term_ids ) || count( $terms ) !== count( $tt_ids ) ) {
			return false;
		}

		$actual = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term || ! isset( $term->object_id ) ) {
				return false;
			}

			$actual[] = array(
				'objectId' => (int) $term->object_id,
				'termId'   => (int) $term->term_id,
				'ttId'     => (int) $term->term_taxonomy_id,
			);
		}

		$expected = array();
		foreach ( array_values( $term_ids ) as $index => $term_id ) {
			$expected[] = array(
				'objectId' => $object_id,
				'termId'   => (int) $term_id,
				'ttId'     => (int) $tt_ids[ $index ],
			);
		}

		self::sort_relationship_rows( $actual );
		self::sort_relationship_rows( $expected );

		return $actual === $expected;
	}

	private static function term_relationship_events_match( array $events, array $expected ): bool {
		$offset = -1;
		foreach ( $expected as $event ) {
			$matched = false;
			for ( $i = $offset + 1, $count = count( $events ); $i < $count; ++$i ) {
				if ( is_array( $events[ $i ] ) && self::term_relationship_event_matches( $events[ $i ], $event ) ) {
					$offset  = $i;
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				return false;
			}
		}

		return true;
	}

	private static function term_relationship_event_matches( array $actual, array $expected ): bool {
		foreach ( array( 'hook', 'objectId', 'taxonomy', 'append' ) as $key ) {
			if ( array_key_exists( $key, $expected ) && ( ! array_key_exists( $key, $actual ) || $actual[ $key ] !== $expected[ $key ] ) ) {
				return false;
			}
		}

		foreach ( array( 'ttIds', 'oldTtIds' ) as $key ) {
			if (
				array_key_exists( $key, $expected )
				&& ( ! array_key_exists( $key, $actual ) || self::normalize_int_list( (array) $actual[ $key ] ) !== self::normalize_int_list( (array) $expected[ $key ] ) )
			) {
				return false;
			}
		}

		return true;
	}

	private static function term_relationship_event_counts_match( array $events, array $expected ): bool {
		return self::term_relationship_event_counts( $events ) === $expected;
	}

	private static function term_relationship_event_counts( array $events ): array {
		$counts = array(
			'add_term_relationship'      => 0,
			'added_term_relationship'    => 0,
			'delete_term_relationships'  => 0,
			'deleted_term_relationships' => 0,
			'set_object_terms'           => 0,
		);

		foreach ( $events as $event ) {
			if ( is_array( $event ) && isset( $counts[ $event['hook'] ] ) ) {
				++$counts[ $event['hook'] ];
			}
		}

		return $counts;
	}

	private static function hooks_are_removed( array $hooks ): bool {
		foreach ( $hooks as $hook ) {
			if ( false !== \has_filter( $hook[0], $hook[1] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function hooks_absent( array $hooks ): bool {
		foreach ( $hooks as $hook ) {
			if ( false !== \has_filter( $hook ) ) {
				return false;
			}
		}

		return true;
	}

	private static function events_contain_thumbnail_fetch_pair( array $events, int $post_id, int $thumbnail_id, string $size ): bool {
		$begin = false;
		$end   = false;

		foreach ( $events as $event ) {
			if (
				! is_array( $event )
				|| (int) ( $event['postId'] ?? 0 ) !== $post_id
				|| (int) ( $event['thumbnailId'] ?? 0 ) !== $thumbnail_id
				|| (string) ( $event['size'] ?? '' ) !== $size
			) {
				continue;
			}

			if ( 'begin' === ( $event['hook'] ?? '' ) ) {
				$begin = true;
			}

			if ( 'end' === ( $event['hook'] ?? '' ) ) {
				$end = true;
			}
		}

		return $begin && $end;
	}

	private static function object_counts_include( $counts, array $expected ): bool {
		if ( ! is_object( $counts ) ) {
			return false;
		}

		$actual = get_object_vars( $counts );
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) || (int) $actual[ $key ] !== (int) $value ) {
				return false;
			}
		}

		return true;
	}

	private static function object_counts_omit( $counts, array $keys ): bool {
		if ( ! is_object( $counts ) ) {
			return false;
		}

		$actual = get_object_vars( $counts );
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $actual ) ) {
				return false;
			}
		}

		return true;
	}

	private static function count_object_has_post_status_keys( $counts ): bool {
		if ( ! is_object( $counts ) ) {
			return false;
		}

		$actual = get_object_vars( $counts );
		foreach ( \get_post_stati() as $status ) {
			if ( ! array_key_exists( $status, $actual ) ) {
				return false;
			}
		}

		return true;
	}

	private static function sorted_string_values( array $values ): array {
		$values = array_values( array_map( 'strval', $values ) );
		sort( $values, SORT_STRING );

		return $values;
	}

	private static function mime_label_tuple_has_shape( $labels ): bool {
		return is_array( $labels )
			&& count( $labels ) >= 3
			&& is_scalar( $labels[0] ?? null )
			&& is_scalar( $labels[1] ?? null )
			&& is_array( $labels[2] ?? null );
	}

	private static function normalize_int_list( array $values ): array {
		$values = array_values( array_map( 'intval', $values ) );
		sort( $values, SORT_NUMERIC );

		return $values;
	}

	private static function sort_relationship_rows( array &$rows ): void {
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a['objectId'], $a['termId'], $a['ttId'] ) <=> array( $b['objectId'], $b['termId'], $b['ttId'] );
			}
		);
	}

	private static function insert_support_user( string $label, string $email ) {
		$login = \sanitize_user( 'cf_' . $label, true );
		$id    = \wp_insert_user(
			array(
				'user_login' => substr( $login, 0, 60 ),
				'user_pass'  => 'component-fuzz-pass',
				'user_email' => $email,
				'role'       => 'subscriber',
			)
		);

		return \is_wp_error( $id ) ? 0 : $id;
	}

	private static function insert_bulk_capability_post( string $title, int $user_id ) {
		return \wp_insert_post(
			\wp_slash(
				array(
					'post_type'      => 'post',
					'post_title'     => $title,
					'post_content'   => $title . ' content',
					'post_status'    => 'draft',
					'post_author'    => $user_id,
					'comment_status' => 'open',
					'ping_status'    => 'closed',
					'post_date'      => '2022-06-07 08:09:10',
					'post_date_gmt'  => '2022-06-07 08:09:10',
				)
			),
			true,
			false
		);
	}

	private static function grant_all_caps_filter( int $user_id ): \Closure {
		return static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $user_id ): array {
			unset( $args );

			if ( (int) $user->ID !== $user_id ) {
				return $allcaps;
			}

			foreach (
				array(
					'add_post_meta',
					'assign_terms',
					'delete_post_meta',
					'edit_others_posts',
					'edit_post',
					'edit_post_meta',
					'edit_posts',
					'edit_private_posts',
					'edit_published_posts',
					'manage_categories',
					'publish_posts',
					'read',
					'read_post',
					'read_private_posts',
				) as $cap
			) {
				$allcaps[ $cap ] = true;
			}

			foreach ( $caps as $cap ) {
				if ( 'do_not_allow' !== $cap ) {
					$allcaps[ $cap ] = true;
				}
			}

			return $allcaps;
		};
	}

	private static function grant_caps_except_filter( int $user_id, array $denied_caps, array &$events ): \Closure {
		$denied_caps = array_values( array_map( 'strval', $denied_caps ) );
		$denied_map  = array_fill_keys( $denied_caps, true );
		$base_caps   = array(
			'add_post_meta',
			'assign_terms',
			'delete_post_meta',
			'edit_others_pages',
			'edit_others_posts',
			'edit_page',
			'edit_pages',
			'edit_post',
			'edit_post_meta',
			'edit_posts',
			'edit_private_pages',
			'edit_private_posts',
			'edit_published_pages',
			'edit_published_posts',
			'manage_categories',
			'publish_pages',
			'publish_posts',
			'read',
			'read_page',
			'read_post',
			'read_private_pages',
			'read_private_posts',
		);

		return static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $user_id, $denied_caps, $denied_map, $base_caps, &$events ): array {
			if ( (int) $user->ID !== $user_id ) {
				return $allcaps;
			}

			$requested = (string) ( $args[0] ?? '' );
			$caps      = array_values( array_map( 'strval', $caps ) );
			$deny_requested = isset( $denied_map[ $requested ] );
			$events[]  = array(
				'requested' => $requested,
				'caps'      => $caps,
				'denied'    => array_values(
					array_unique(
						array_merge(
							array_intersect( array_merge( array( $requested ), $caps ), $denied_caps ),
							$deny_requested ? $caps : array()
						)
					)
				),
			);

			foreach ( array_merge( $base_caps, $caps, array( $requested ) ) as $cap ) {
				if ( '' !== $cap && 'do_not_allow' !== $cap ) {
					$allcaps[ $cap ] = true;
				}
			}

			foreach ( $denied_map as $cap => $ignored ) {
				unset( $ignored );
				$allcaps[ $cap ] = false;
			}

			if ( $deny_requested ) {
				foreach ( $caps as $cap ) {
					if ( '' !== $cap && 'do_not_allow' !== $cap ) {
						$allcaps[ $cap ] = false;
					}
				}
			}

			return $allcaps;
		};
	}

	private static function capture_wp_die_call( callable $callback ): array {
		$die_calls  = array();
		$ob_level   = ob_get_level();
		$value      = null;
		$captured   = false;
		$unexpected = null;
		$output     = '';

		$die_filter = static function () use ( &$die_calls ): callable {
			return static function ( $message = '', $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'message' => self::describe_wp_die_value( $message ),
					'title'   => self::describe_wp_die_value( $title ),
					'args'    => $args,
				);

				throw new ContentLifecycleSurface_DieCaptured( 'Captured content lifecycle wp_die.' );
			};
		};

		\add_filter( 'wp_die_handler', $die_filter, PHP_INT_MAX );
		ob_start();

		try {
			$value = $callback();
		} catch ( ContentLifecycleSurface_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$unexpected = self::describe_throwable( $e );
		} finally {
			while ( ob_get_level() > $ob_level ) {
				$chunk   = ob_get_clean();
				$output .= false === $chunk ? '' : (string) $chunk;
			}

			\remove_filter( 'wp_die_handler', $die_filter, PHP_INT_MAX );
		}

		return array(
			'captured'        => $captured,
			'threwUnexpected' => null !== $unexpected,
			'unexpected'      => $unexpected,
			'value'           => $value,
			'output'          => $output,
			'dieCalls'        => $die_calls,
			'filtersRestored' => false === \has_filter( 'wp_die_handler', $die_filter ),
			'bufferBalanced'  => $ob_level === ob_get_level(),
		);
	}

	private static function describe_wp_die_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		if ( \is_wp_error( $value ) ) {
			return self::error_summary( $value );
		}

		return get_debug_type( $value );
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'sha256', $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );

		return array(
			'token'              => $token,
			'postType'           => 'cf_life_' . substr( $token, 0, 10 ),
			'title'              => self::edge_text( $ctx->fork( 'title' ), 'title' ),
			'updatedTitle'       => self::edge_text( $ctx->fork( 'updated-title' ), 'updated title' ),
			'content'            => self::edge_text( $ctx->fork( 'content' ), 'content' ),
			'updatedContent'     => self::edge_text( $ctx->fork( 'updated-content' ), 'updated content' ),
			'excerpt'            => self::edge_text( $ctx->fork( 'excerpt' ), 'excerpt' ),
			'slug'               => self::usable_slug( self::edge_text( $ctx->fork( 'slug' ), 'slug' ), 'post-' . $token ),
			'status'             => $ctx->choice( array( 'draft', 'publish', 'private' ) ),
			'updatedStatus'      => $ctx->choice( array( 'draft', 'publish', 'private' ) ),
			'authorEmail'        => 'author-' . $token . '@example.test',
			'userLogin'          => 'cf_user_' . $token,
			'userEmail'          => 'user-' . $token . '@example.test',
			'password'           => 'pass-' . $token,
			'displayName'        => self::edge_text( $ctx->fork( 'display' ), 'display' ),
			'updatedDisplayName' => self::edge_text( $ctx->fork( 'updated-display' ), 'updated display' ),
			'nickname'           => self::edge_text( $ctx->fork( 'nickname' ), 'nickname' ),
			'description'        => self::edge_text( $ctx->fork( 'description' ), 'description' ),
			'categoryName'       => self::edge_text( $ctx->fork( 'category-name' ), 'category' ),
			'categorySlug'       => self::edge_text( $ctx->fork( 'category-slug' ), 'category slug' ),
			'tagName'            => self::edge_text( $ctx->fork( 'tag-name' ), 'tag' ),
			'tagSlug'            => self::edge_text( $ctx->fork( 'tag-slug' ), 'tag slug' ),
			'termDescription'    => self::edge_text( $ctx->fork( 'term-description' ), 'term description' ),
			'tagDescription'     => self::edge_text( $ctx->fork( 'tag-description' ), 'tag description' ),
			'commentAuthor'      => self::usable_name( self::edge_text( $ctx->fork( 'comment-author' ), 'comment author' ), 'Commenter ' . $token ),
			'commentEmail'       => 'comment-' . $token . '@example.test',
			'commentUrl'         => 'http://example.test/comment-' . $token,
			'commentContent'     => self::usable_content( self::edge_text( $ctx->fork( 'comment-content' ), 'comment content' ), 'Comment ' . $token ),
			'metaKey'            => '_cf_life_meta_' . $token,
			'metaUniqueKey'      => '_cf_life_unique_' . $token,
			'metaValue'          => 'meta-alpha-' . $token,
			'metaSecondValue'    => 'meta-beta-' . $token,
			'metaUpdatedValue'   => 'meta-gamma-' . $token,
			'metaArrayValue'     => array(
				'token' => $token,
				'text'  => self::edge_text( $ctx->fork( 'meta-array' ), 'meta array' ),
				'flags' => array( true, 7, 'component-fuzz' ),
			),
		);
	}

	private static function status_transition_post_type( array $case ): string {
		return 'cf_status_' . substr( $case['token'], 0, 8 );
	}

	private static function edge_text( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$unicode = html_entity_decode( 'Unicode &#233; &#9731; &#20013;&#25991; ' . $label, ENT_QUOTES, 'UTF-8' );
		$cases   = array(
			'',
			'plain ' . $label . ' ' . $ctx->identifier( 3, 10 ),
			"slashes \\\\ / ' \" " . $label,
			'<p class="x" onclick="bad">' . $label . '</p><script>alert(1)</script>',
			$unicode,
			"invalid-\xC3\x28-" . $label,
			str_repeat( $label . '-long-', $ctx->int( 8, 24 ) ),
			$ctx->text( 0, 96 ),
		);

		return (string) $ctx->choice( $cases );
	}

	private static function usable_name( string $value, string $fallback ): string {
		$value = function_exists( 'wp_check_invalid_utf8' ) ? \wp_check_invalid_utf8( $value, true ) : $value;
		$value = trim( strip_tags( $value ) );
		if ( '' === $value ) {
			$value = $fallback;
		}

		return substr( $value, 0, 80 );
	}

	private static function usable_slug( string $value, string $fallback ): string {
		$slug = \sanitize_title( $value );
		if ( '' === $slug ) {
			$slug = \sanitize_title( $fallback );
		}

		return substr( $slug, 0, 80 );
	}

	private static function usable_content( string $value, string $fallback ): string {
		$probe = function_exists( 'wp_check_invalid_utf8' ) ? \wp_check_invalid_utf8( $value, true ) : $value;
		if ( '' === trim( strip_tags( $probe ) ) ) {
			return $fallback;
		}

		return $value;
	}

	private static function collect_failure( array &$failures, bool $ok, string $label, array $details = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function ids_from_posts( $items ): array {
		$ids = array();

		foreach ( (array) $items as $item ) {
			if ( $item instanceof \WP_Post ) {
				$ids[] = (int) $item->ID;
			} elseif ( is_array( $item ) && array_key_exists( 'ID', $item ) ) {
				$ids[] = (int) $item['ID'];
			} elseif ( is_object( $item ) && isset( $item->ID ) ) {
				$ids[] = (int) $item->ID;
			} elseif ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			}
		}

		return $ids;
	}

	private static function same_id_set( array $actual, array $expected ): bool {
		$actual   = array_map( 'intval', $actual );
		$expected = array_map( 'intval', $expected );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	private static function all_wp_posts( $items ): bool {
		foreach ( (array) $items as $item ) {
			if ( ! $item instanceof \WP_Post ) {
				return false;
			}
		}

		return true;
	}

	private static function all_arrays_with_id( $items ): bool {
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'ID', $item ) ) {
				return false;
			}
		}

		return true;
	}

	private static function all_ints( $items ): bool {
		foreach ( (array) $items as $item ) {
			if ( ! is_int( $item ) ) {
				return false;
			}
		}

		return true;
	}

	private static function case_summary( array $case ): array {
		return array(
			'token'          => $case['token'],
			'postType'       => $case['postType'],
			'status'         => $case['status'],
			'updatedStatus'  => $case['updatedStatus'],
			'slug'           => $case['slug'],
			'userLogin'      => $case['userLogin'],
			'userEmail'      => $case['userEmail'],
			'categoryName'   => $case['categoryName'],
			'tagName'        => $case['tagName'],
			'commentAuthor'  => $case['commentAuthor'],
			'commentEmail'   => $case['commentEmail'],
		);
	}

	private static function post_summary( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $post;
		}

		return array(
			'ID'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'post_name'   => $post->post_name,
			'post_author' => $post->post_author,
		);
	}

	private static function term_summary( $term ) {
		if ( ! $term instanceof \WP_Term ) {
			return $term;
		}

		return array(
			'term_id'          => $term->term_id,
			'term_taxonomy_id' => $term->term_taxonomy_id,
			'taxonomy'         => $term->taxonomy,
			'name'             => $term->name,
			'slug'             => $term->slug,
			'parent'           => $term->parent,
		);
	}

	private static function user_summary( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		return array(
			'ID'            => $user->ID,
			'user_login'    => $user->user_login,
			'user_nicename' => $user->user_nicename,
			'user_email'    => $user->user_email,
			'display_name'  => $user->display_name,
			'roles'         => $user->roles,
		);
	}

	private static function comment_summary( $comment ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return $comment;
		}

		return array(
			'comment_ID'           => $comment->comment_ID,
			'comment_post_ID'      => $comment->comment_post_ID,
			'comment_author'       => $comment->comment_author,
			'comment_author_email' => $comment->comment_author_email,
			'comment_approved'     => $comment->comment_approved,
			'comment_type'         => $comment->comment_type,
		);
	}

	private static function error_summary( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return $value;
		}

		return array(
			'code' => $value->get_error_code(),
			'data' => $value->get_error_data(),
		);
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => addcslashes( substr( $value, 0, 240 ), "\0..\37\177..\377" ),
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
}

final class ContentLifecycleSurface_DieCaptured extends \RuntimeException {}
