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
			$rows[] = self::check_term_lifecycle( $ctx->fork( 'terms' ), $case );
			$rows[] = self::check_post_lifecycle( $ctx->fork( 'posts' ), $case );
			$rows   = array_merge(
				$rows,
				self::check_post_status_transition_hooks( $ctx->fork( 'post-status' ), $case )
			);
			$rows[] = self::check_post_term_relationship_lifecycle( $ctx->fork( 'post-terms' ), $case );
			$rows[] = self::check_comment_lifecycle( $ctx->fork( 'comments' ), $case );
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

		foreach ( array( 'WP_Post', 'WP_Term', 'WP_User', 'WP_Comment', 'WP_Error', 'Component_Fuzz_WPDB_Stub' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_post_meta',
				'clean_post_cache',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'delete_post_meta',
				'get_the_terms',
				'get_comment',
				'get_post_meta',
				'get_post',
				'get_term',
				'get_terms',
				'get_user_by',
				'get_userdata',
				'has_action',
				'has_term',
				'is_object_in_term',
				'is_wp_error',
				'metadata_exists',
				'post_type_exists',
				'register_post_type',
				'remove_action',
				'sanitize_comment_cookies',
				'sanitize_email',
				'sanitize_post_field',
				'sanitize_term_field',
				'sanitize_title',
				'sanitize_user',
				'term_exists',
				'wp_cache_flush',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
				'wp_delete_comment',
				'wp_delete_object_term_relationships',
				'wp_delete_post',
				'wp_get_object_terms',
				'wp_insert_comment',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_new_comment',
				'wp_remove_object_terms',
				'wp_set_object_terms',
				'wp_set_current_user',
				'wp_slash',
				'wp_trash_post',
				'wp_transition_post_status',
				'wp_unslash',
				'wp_clear_scheduled_hook',
				'update_post_meta',
				'wp_update_post',
				'_count_posts_cache_key',
				'_transition_post_status',
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

	private static function check_post_status_transition_hooks( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$hook_failures    = array();
		$storage_failures = array();
		$cache_failures   = array();
		$events           = array();
		$hooks            = array();
		$post_id          = 0;
		$post_type        = 'cf_status_' . substr( $case['token'], 0, 8 );

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

				foreach ( $transitions as $transition ) {
					list( $old_status, $new_status ) = $transition;
					$label           = $old_status . '_to_' . $new_status;
					$event_offset    = count( $events );
					$transition_post = clone $post;
					$transition_post->post_status = $old_status;

					self::seed_transition_caches( $post_type, $label );
					\wp_transition_post_status( $new_status, $old_status, $transition_post );

					$transition_events = array_slice( $events, $event_offset );
					$stored_after      = \get_post( $post_id );

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
				}
			}
		} finally {
			self::remove_hooks( $hooks );
			if ( is_int( $post_id ) && $post_id > 0 ) {
				\wp_delete_post( $post_id, true );
			}
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
		$wpdb       = $GLOBALS['wpdb'] ?? null;
		$counts     = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_content_counts() : array();
		$count_ok   = array() === array_filter( $counts );
		$cache_ok   = false === \wp_cache_get( $case['token'], 'component-fuzz-lifecycle' );
		$type_ok    = ! \post_type_exists( $case['postType'] );
		$dynamic_ok = false === has_filter( 'save_post_' . $case['postType'] );

		return $ctx->result(
			'content-lifecycle.state-restored-between-iterations',
			$count_ok && $cache_ok && $type_ok && $dynamic_ok,
			array(
				'counts'             => $counts,
				'cacheSentinelEmpty' => $cache_ok,
				'postTypeGone'       => $type_ok,
				'dynamicHookGone'    => $dynamic_ok,
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
			\wp_cache_set( "lastpostdate:{$timezone}", 'date-' . $label, 'timeinfo' );
			\wp_cache_set( "lastpostdate:{$timezone}:{$post_type}", 'date-type-' . $label, 'timeinfo' );
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
				if ( false !== $values['modified'] || false !== $values['date'] || false !== $values['datePostType'] ) {
					return false;
				}
				continue;
			}

			if (
				$values['modified'] !== 'modified-' . $label
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
				'modified'     => \wp_cache_get( "lastpostmodified:{$timezone}", 'timeinfo' ),
				'date'         => \wp_cache_get( "lastpostdate:{$timezone}", 'timeinfo' ),
				'datePostType' => \wp_cache_get( "lastpostdate:{$timezone}:{$post_type}", 'timeinfo' ),
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
		$hooks = array();
		$watch = array(
			$meta_key   => true,
			$unique_key => true,
		);
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

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
