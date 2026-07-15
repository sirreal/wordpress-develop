<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes DB-backed content lifecycle APIs against the in-memory wpdb stub.
 */
final class ContentLifecycleSurface {
	public const NAME = 'content-lifecycle';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
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
			$rows[] = self::check_term_lifecycle( $ctx->fork( 'terms' ), $case );
			$rows[] = self::check_post_lifecycle( $ctx->fork( 'posts' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_admin_post_save_orchestration( $ctx->fork( 'admin-post-save' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_page_lookup_helpers( $ctx->fork( 'page-lookups' ), $case );

			self::prepare_runtime();
			$rows[] = self::check_set_post_type_mutation_and_cache_cleanup( $ctx->fork( 'set-post-type' ), $case );
			$rows   = array_merge(
				$rows,
				self::check_post_status_transition_hooks( $ctx->fork( 'post-status' ), $case )
			);
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
				'add_action',
				'add_filter',
				'add_meta',
				'add_post_meta',
				'clean_post_cache',
				'current_user_can',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'delete_meta',
				'delete_post_meta',
				'delete_post_thumbnail',
				'edit_post',
				'get_available_post_mime_types',
				'get_the_terms',
				'get_the_post_thumbnail',
				'get_the_post_thumbnail_caption',
				'get_the_post_thumbnail_url',
				'get_comment',
				'get_post_meta',
				'get_post_meta_by_id',
				'get_post_mime_types',
				'get_post_thumbnail_id',
				'get_post_stati',
				'get_post_status_object',
				'get_post',
				'get_post_type_object',
				'get_posts',
				'get_children',
				'get_page_by_path',
				'get_page_children',
				'get_pages',
				'get_term',
				'get_terms',
				'get_taxonomy',
				'get_user_meta',
				'get_user_by',
				'get_userdata',
				'has_action',
				'has_filter',
				'has_post_thumbnail',
				'has_term',
				'is_object_in_term',
				'is_user_logged_in',
				'is_wp_error',
				'metadata_exists',
				'post_type_exists',
				'post_type_supports',
				'get_permalink',
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
				'wp_delete_comment',
				'wp_delete_object_term_relationships',
				'wp_delete_post',
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
				'update_post_meta',
				'update_meta',
				'update_post_thumbnail_cache',
				'wp_count_attachments',
				'wp_count_posts',
				'wp_update_post',
				'_count_posts_cache_key',
				'_fix_attachment_links',
				'_transition_post_status',
				'_wp_get_allowed_postdata',
				'_wp_translate_postdata',
				'wp_post_mime_type_where',
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
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'wp_rewrite', 'current_user', 'user_ID' ) as $name ) {
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

	private static function remove_hooks( array $hooks ): void {
		foreach ( $hooks as $hook ) {
			\remove_action( $hook[0], $hook[1], $hook[2] );
		}
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
