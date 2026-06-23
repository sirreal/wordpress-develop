<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes revision and autosave helpers against the in-memory wpdb stub.
 */
final class RevisionsAutosavesSurface {
	public const NAME = 'revisions-autosaves';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'revisions-autosaves.bootstrap-apis-available',
					'Required WordPress revision and autosave APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$case     = self::case_for_context( $ctx );
		$rows     = array();

		try {
			$rows[] = self::check_revision_field_data_contracts( $ctx->fork( 'fields' ), $case );
			$rows[] = self::check_revision_insert_lookup_predicates( $ctx->fork( 'predicates' ), $case );
			$rows[] = self::check_save_restore_and_meta_helpers( $ctx->fork( 'restore-meta' ), $case );
			$rows[] = self::check_revision_ui_payloads( $ctx->fork( 'ui' ), $case );
			$rows[] = self::check_preview_helper( $ctx->fork( 'preview' ), $case );
			$rows[] = $ctx->skip(
				'revisions-autosaves.latest-count-url.stub-limited',
				'The in-memory wpdb stub does not emulate the WP_Query found_posts COUNT(*) shape for wp_posts, so latest revision count and revision URL helpers are recorded as unsupported here.',
				array( 'apis' => array( 'wp_get_latest_revision_id_and_total_count', 'wp_get_post_revisions_url' ) )
			);
			$rows[] = $ctx->skip(
				'revisions-autosaves.user-filtered-autosave.stub-limited',
				'The in-memory wpdb stub does not emulate WP_Query author filtering for wp_posts, so user-specific autosave lookup is recorded as unsupported here.',
				array( 'api' => 'wp_get_post_autosave($post_id, $user_id)' )
			);
			$rows[] = $ctx->skip(
				'revisions-autosaves.browser-template-preview.skipped',
				'Browser/admin-template and request-dispatch helpers are intentionally avoided in CLI fuzzing.',
				array( 'apis' => array( '_show_post_preview', 'wp_print_revision_templates' ) )
			);
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'revisions-autosaves.surface-no-throw',
				array(
					'case'      => self::case_summary( $case ),
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = self::check_state_restored( $ctx, $snapshot, $case );

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'Component_Fuzz_WPDB_Stub', 'WP_Error', 'WP_Post', 'WP_Query', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_set_preview',
				'_wp_copy_post_meta',
				'_wp_post_revision_data',
				'_wp_post_revision_fields',
				'_wp_put_post_revision',
				'add_filter',
				'add_post_meta',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'get_post',
				'get_post_meta',
				'has_filter',
				'is_wp_error',
				'post_type_exists',
				'register_post_meta',
				'register_post_type',
				'remove_filter',
				'sanitize_key',
				'sanitize_title',
				'unregister_meta_key',
				'update_post_meta',
				'wp_cache_flush',
				'wp_check_invalid_utf8',
				'wp_check_revisioned_meta_fields_have_changed',
				'wp_delete_post_revision',
				'wp_get_post_autosave',
				'wp_get_post_revision',
				'wp_get_post_revisions',
				'wp_get_revision_ui_diff',
				'wp_insert_post',
				'wp_insert_user',
				'wp_is_post_autosave',
				'wp_is_post_revision',
				'wp_post_revision_meta_keys',
				'wp_prepare_revisions_for_js',
				'wp_restore_post_revision',
				'wp_restore_post_revision_meta',
				'wp_revisions_enabled',
				'wp_revisions_to_keep',
				'wp_save_post_revision',
				'wp_save_revisioned_meta_fields',
				'wp_set_current_user',
				'wp_slash',
				'wp_update_post',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			$missing[] = 'constant WP_POST_REVISIONS';
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_revision_field_data_contracts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();

		$failures = array();
		$post_id  = 1000 + $ctx->int( 1, 8000 );
		$post     = array(
			'ID'                => $post_id,
			'post_author'       => 42,
			'post_content'      => $case['contentFrom'],
			'post_date'         => $case['dateFrom'],
			'post_date_gmt'     => $case['dateFromGmt'],
			'post_excerpt'      => $case['excerptFrom'],
			'post_modified'     => $case['dateTo'],
			'post_modified_gmt' => $case['dateToGmt'],
			'post_name'         => $case['slug'],
			'post_parent'       => 99,
			'post_status'       => 'publish',
			'post_title'        => $case['titleFrom'],
			'post_type'         => $case['postType'],
			'comment_count'     => 5,
		);

		$fields       = \_wp_post_revision_fields( $post );
		$revision     = \_wp_post_revision_data( $post, false );
		$autosave     = \_wp_post_revision_data( $post, true );
		$protected    = array( 'ID', 'post_name', 'post_parent', 'post_date', 'post_date_gmt', 'post_status', 'post_type', 'comment_count', 'post_author' );
		$field_names  = array_keys( $fields );
		$versioned    = array( 'post_title', 'post_content', 'post_excerpt' );
		$leaked_keys  = array_values( array_intersect( $protected, $field_names ) );
		$revision_ids = array_values( array_intersect( array( 'ID', 'post_author', 'comment_count' ), array_keys( $revision ) ) );

		self::collect_failure(
			$failures,
			array() === array_diff( $versioned, $field_names ) && array() === $leaked_keys,
			'revision fields include content fields and exclude core protected fields',
			array(
				'fields'      => $fields,
				'leakedKeys'  => $leaked_keys,
				'versioned'   => $versioned,
				'protected'   => $protected,
			)
		);
		self::collect_failure(
			$failures,
			$post['post_title'] === ( $revision['post_title'] ?? null )
				&& $post['post_content'] === ( $revision['post_content'] ?? null )
				&& $post['post_excerpt'] === ( $revision['post_excerpt'] ?? null )
				&& $post_id === (int) ( $revision['post_parent'] ?? 0 )
				&& 'inherit' === ( $revision['post_status'] ?? null )
				&& 'revision' === ( $revision['post_type'] ?? null )
				&& "{$post_id}-revision-v1" === ( $revision['post_name'] ?? null )
				&& $case['dateTo'] === ( $revision['post_date'] ?? null )
				&& $case['dateToGmt'] === ( $revision['post_date_gmt'] ?? null )
				&& array() === $revision_ids,
			'_wp_post_revision_data builds revision rows from only revisioned fields',
			array(
				'revision'    => $revision,
				'leakedKeys'   => $revision_ids,
				'expectedName' => "{$post_id}-revision-v1",
			)
		);
		self::collect_failure(
			$failures,
			"{$post_id}-autosave-v1" === ( $autosave['post_name'] ?? null )
				&& 'revision' === ( $autosave['post_type'] ?? null )
				&& 'inherit' === ( $autosave['post_status'] ?? null )
				&& $revision['post_title'] === ( $autosave['post_title'] ?? null )
				&& $revision['post_content'] === ( $autosave['post_content'] ?? null )
				&& $revision['post_excerpt'] === ( $autosave['post_excerpt'] ?? null ),
			'autosave revision data differs only by autosave post_name contract',
			array(
				'autosave'     => $autosave,
				'expectedName' => "{$post_id}-autosave-v1",
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.fields-and-data-contracts',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'fieldCount' => count( $fields ),
			)
		);
	}

	private static function check_revision_insert_lookup_predicates( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures  = array();
		$author_id = self::insert_author( $case, 'predicates' );
		$post_id   = self::insert_parent_post( $case, $author_id, 'predicates' );
		$post      = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return self::result(
				$ctx,
				'revisions-autosaves.insert-lookup-predicates',
				array(
					array(
						'label'   => 'parent post fixture is readable',
						'details' => array( 'postId' => $post_id ),
					),
				),
				array( 'case' => self::case_summary( $case ) )
			);
		}

		$revision_id         = \_wp_put_post_revision( $post, false );
		$autosave_id         = \_wp_put_post_revision( $post, true );
		$invalid_revision    = \_wp_put_post_revision( 987654321, false );
		$revision_of_revision = \_wp_put_post_revision( \get_post( $revision_id ), false );
		$revision            = is_int( $revision_id ) ? \get_post( $revision_id ) : null;
		$autosave            = is_int( $autosave_id ) ? \get_post( $autosave_id ) : null;
		$lookup_object       = \wp_get_post_revision( $revision_id );
		$lookup_array        = \wp_get_post_revision( $revision_id, ARRAY_A );
		$lookup_numeric      = \wp_get_post_revision( $revision_id, ARRAY_N );
		$lookup_parent       = \wp_get_post_revision( $post_id );
		$latest_autosave     = \wp_get_post_autosave( $post_id );
		$revisions           = \wp_get_post_revisions(
			$post_id,
			array(
				'check_enabled' => false,
				'order'         => 'ASC',
			)
		);

		self::collect_failure(
			$failures,
			is_int( $revision_id )
				&& is_int( $autosave_id )
				&& $autosave_id > $revision_id
				&& $revision instanceof \WP_Post
				&& $autosave instanceof \WP_Post
				&& 'revision' === $revision->post_type
				&& 'revision' === $autosave->post_type
				&& "{$post_id}-revision-v1" === $revision->post_name
				&& "{$post_id}-autosave-v1" === $autosave->post_name
				&& (int) $post_id === (int) $revision->post_parent
				&& (int) $post_id === (int) $autosave->post_parent,
			'_wp_put_post_revision creates normal and autosave revision rows',
			array(
				'postId'     => $post_id,
				'revisionId' => $revision_id,
				'autosaveId' => $autosave_id,
				'revision'   => self::post_summary( $revision ),
				'autosave'   => self::post_summary( $autosave ),
			)
		);
		self::collect_failure(
			$failures,
			(int) $post_id === \wp_is_post_revision( $revision_id )
				&& (int) $post_id === \wp_is_post_revision( $autosave_id )
				&& false === \wp_is_post_autosave( $revision_id )
				&& (int) $post_id === \wp_is_post_autosave( $autosave_id )
				&& false === \wp_is_post_revision( $post_id )
				&& false === \wp_is_post_autosave( $post_id ),
			'revision and autosave predicates distinguish parents, revisions, and autosaves',
			array(
				'postId'     => $post_id,
				'revisionId' => $revision_id,
				'autosaveId' => $autosave_id,
			)
		);
		self::collect_failure(
			$failures,
			$lookup_object instanceof \WP_Post
				&& is_array( $lookup_array )
				&& is_array( $lookup_numeric )
				&& (int) $revision_id === (int) ( $lookup_array['ID'] ?? 0 )
				&& in_array( (int) $revision_id, array_map( 'intval', $lookup_numeric ), true )
				&& null === $lookup_parent,
			'wp_get_post_revision returns requested output formats and rejects non-revision posts',
			array(
				'object'  => self::post_summary( $lookup_object ),
				'array'   => $lookup_array,
				'numeric' => $lookup_numeric,
				'parent'  => $lookup_parent,
			)
		);
		self::collect_failure(
			$failures,
			$latest_autosave instanceof \WP_Post
				&& (int) $post_id === (int) $latest_autosave->post_parent
				&& "{$post_id}-autosave-v1" === $latest_autosave->post_name,
			'wp_get_post_autosave locates an autosave for the parent post',
			array( 'latestAutosave' => self::post_summary( $latest_autosave ) )
		);
		self::collect_failure(
			$failures,
			isset( $revisions[ $revision_id ], $revisions[ $autosave_id ] )
				&& $revisions[ $revision_id ] instanceof \WP_Post
				&& $revisions[ $autosave_id ] instanceof \WP_Post,
			'wp_get_post_revisions returns parent revision children keyed by ID',
			array(
				'ids'        => array_map( 'intval', array_keys( $revisions ) ),
				'revisionId' => $revision_id,
				'autosaveId' => $autosave_id,
			)
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $invalid_revision )
				&& 'invalid_post' === $invalid_revision->get_error_code()
				&& \is_wp_error( $revision_of_revision )
				&& 'post_type' === $revision_of_revision->get_error_code(),
			'invalid revision insert paths return WP_Error without writes outside the stub',
			array(
				'invalid'            => self::error_summary( $invalid_revision ),
				'revisionOfRevision' => self::error_summary( $revision_of_revision ),
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.insert-lookup-predicates',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'postId'     => $post_id,
				'revisionId' => $revision_id,
				'autosaveId' => $autosave_id,
			)
		);
	}

	private static function check_save_restore_and_meta_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures             = array();
		$author_id            = self::insert_author( $case, 'restore-meta' );
		$post_id              = self::insert_parent_post( $case, $author_id, 'restore-meta' );
		$post                 = \get_post( $post_id );
		$without_revisions_pt = 'cfnr_' . $case['token'];
		$without_revisions_pt = substr( \sanitize_key( $without_revisions_pt ), 0, 20 );

		\register_post_type(
			$without_revisions_pt,
			array(
				'public'    => true,
				'query_var' => false,
				'rewrite'   => false,
				'supports'  => array( 'title', 'editor' ),
			)
		);

		$limit_filter = static function () use ( $case ): int {
			return $case['revisionLimit'];
		};
		$type_limit_filter = static function () use ( $case ): int {
			return $case['dynamicRevisionLimit'];
		};

		\add_filter( 'wp_revisions_to_keep', $limit_filter, 10, 2 );
		\add_filter( "wp_{$case['postType']}_revisions_to_keep", $type_limit_filter, 10, 2 );

		try {
			$limited_to_keep = $post instanceof \WP_Post ? \wp_revisions_to_keep( $post ) : null;
			$limited_enabled = $post instanceof \WP_Post ? \wp_revisions_enabled( $post ) : null;
		} finally {
			\remove_filter( 'wp_revisions_to_keep', $limit_filter, 10 );
			\remove_filter( "wp_{$case['postType']}_revisions_to_keep", $type_limit_filter, 10 );
		}

		$without_revisions_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => $without_revisions_pt,
					'post_status'  => 'draft',
					'post_title'   => 'No revisions ' . $case['token'],
					'post_content' => 'No revisions content',
				)
			),
			true,
			true
		);
		$without_revisions = \get_post( $without_revisions_id );

		self::collect_failure(
			$failures,
			$case['dynamicRevisionLimit'] === $limited_to_keep
				&& ( 0 !== $case['dynamicRevisionLimit'] ) === $limited_enabled
				&& $without_revisions instanceof \WP_Post
				&& 0 === \wp_revisions_to_keep( $without_revisions )
				&& false === \wp_revisions_enabled( $without_revisions ),
			'wp_revisions_to_keep honors support checks and global/type-specific filters',
			array(
				'limitedToKeep'       => $limited_to_keep,
				'limitedEnabled'      => $limited_enabled,
				'withoutRevisions'    => self::post_summary( $without_revisions ),
				'dynamicLimit'        => $case['dynamicRevisionLimit'],
				'withoutRevisionsType' => $without_revisions_pt,
			)
		);

		$first_revision = \wp_save_post_revision( $post_id );
		$second_save    = \wp_save_post_revision( $post_id );
		$updated_post   = \wp_update_post(
			\wp_slash(
				array(
					'ID'           => $post_id,
					'post_title'   => $case['titleTo'],
					'post_content' => $case['contentTo'],
					'post_excerpt' => $case['excerptTo'],
				)
			),
			true,
			true
		);
		$third_revision = \wp_save_post_revision( $post_id );

		self::collect_failure(
			$failures,
			is_int( $first_revision )
				&& null === $second_save
				&& (int) $post_id === (int) $updated_post
				&& is_int( $third_revision )
				&& $third_revision > $first_revision,
			'wp_save_post_revision saves first and changed revisions but skips unchanged content',
			array(
				'postId'        => $post_id,
				'firstRevision' => $first_revision,
				'secondSave'    => $second_save,
				'updatedPost'   => $updated_post,
				'thirdRevision' => $third_revision,
			)
		);

		$restored_title_only = is_int( $first_revision )
			? \wp_restore_post_revision( $first_revision, array( 'post_title' ) )
			: null;
		$restored_post       = \get_post( $post_id );

		self::collect_failure(
			$failures,
			(int) $post_id === (int) $restored_title_only
				&& $restored_post instanceof \WP_Post
				&& $case['titleFrom'] === $restored_post->post_title
				&& $case['contentTo'] === $restored_post->post_content
				&& $case['excerptTo'] === $restored_post->post_excerpt,
			'wp_restore_post_revision restores only requested fields',
			array(
				'restoredTitleOnly' => $restored_title_only,
				'restoredPost'      => self::post_summary( $restored_post ),
			)
		);

		$meta_key     = 'cf_revisioned_' . $case['token'];
		$meta_value   = 'meta-' . $case['token'] . '-' . $ctx->int( 10, 9999 );
		$changed_meta = $meta_value . '-changed';
		$registered   = \register_post_meta(
			$case['postType'],
			$meta_key,
			array(
				'revisions_enabled' => true,
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		\add_post_meta( $post_id, $meta_key, $meta_value, true );
		if ( is_int( $first_revision ) ) {
			\_wp_copy_post_meta( $post_id, $first_revision, $meta_key );
		}

		$meta_keys       = \wp_post_revision_meta_keys( $case['postType'] );
		$copied_meta     = is_int( $first_revision ) ? \get_post_meta( $first_revision, $meta_key, true ) : null;
		$unchanged_meta  = is_int( $first_revision ) ? \wp_check_revisioned_meta_fields_have_changed( false, \get_post( $first_revision ), \get_post( $post_id ) ) : null;
		$meta_updated    = \update_post_meta( $post_id, $meta_key, $changed_meta );
		$changed_detected = is_int( $first_revision ) ? \wp_check_revisioned_meta_fields_have_changed( false, \get_post( $first_revision ), \get_post( $post_id ) ) : null;
		$meta_restored   = is_int( $first_revision ) ? \wp_restore_post_revision_meta( $post_id, $first_revision ) : null;
		$restored_meta   = \get_post_meta( $post_id, $meta_key, true );

		if ( is_int( $third_revision ) ) {
			\wp_save_revisioned_meta_fields( $third_revision, $post_id );
		}
		$saved_revision_meta = is_int( $third_revision ) ? \get_post_meta( $third_revision, $meta_key, true ) : null;

		\unregister_meta_key( 'post', $meta_key, $case['postType'] );

		self::collect_failure(
			$failures,
			true === $registered
				&& in_array( $meta_key, $meta_keys, true )
				&& $meta_value === $copied_meta
				&& false === $unchanged_meta
				&& false !== $meta_updated
				&& true === $changed_detected
				&& null === $meta_restored
				&& $meta_value === $restored_meta
				&& $meta_value === $saved_revision_meta,
			'revisioned post meta keys, copy, change detection, save, and restore agree',
			array(
				'registered'         => $registered,
				'metaKey'            => $meta_key,
				'metaKeys'           => $meta_keys,
				'copiedMeta'         => $copied_meta,
				'unchangedMeta'      => $unchanged_meta,
				'metaUpdated'        => $meta_updated,
				'changedDetected'    => $changed_detected,
				'metaRestoredReturn' => $meta_restored,
				'restoredMeta'       => $restored_meta,
				'savedRevisionMeta'  => $saved_revision_meta,
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.save-restore-meta-helpers',
			$failures,
			array(
				'case'          => self::case_summary( $case ),
				'postId'        => $post_id,
				'firstRevision' => $first_revision,
				'thirdRevision' => $third_revision,
			)
		);
	}

	private static function check_revision_ui_payloads( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures  = array();
		$author_id = self::insert_author( $case, 'ui' );
		$post_id   = self::insert_parent_post(
			array_merge(
				$case,
				array(
					'titleFrom'   => '',
					'contentFrom' => $case['contentTo'],
					'excerptFrom' => $case['excerptTo'],
					'dateFrom'    => $case['dateTo'],
					'dateFromGmt' => $case['dateToGmt'],
				)
			),
			$author_id,
			'ui'
		);

		$from_id     = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => '',
				'post_content'      => $case['contentFrom'],
				'post_excerpt'      => $case['excerptFrom'],
				'post_date'         => $case['dateFrom'],
				'post_date_gmt'     => $case['dateFromGmt'],
				'post_modified'     => $case['dateFrom'],
				'post_modified_gmt' => $case['dateFromGmt'],
			),
			false
		);
		$to_id       = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => '',
				'post_content'      => $case['contentTo'],
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateTo'],
				'post_date_gmt'     => $case['dateToGmt'],
				'post_modified'     => $case['dateTo'],
				'post_modified_gmt' => $case['dateToGmt'],
			),
			false
		);
		$autosave_id = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'],
				'post_content'      => $case['contentTo'] . "\nAutosave " . $case['token'],
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateLater'],
				'post_date_gmt'     => $case['dateLaterGmt'],
				'post_modified'     => $case['dateLater'],
				'post_modified_gmt' => $case['dateLaterGmt'],
			),
			true
		);

		$content_filter = static function ( $value, $field, $revision, $context ) use ( $case ) {
			if ( 'post_content' !== $field ) {
				return $value;
			}

			return (string) $value . "\ncf-{$context}-{$case['token']}";
		};
		$options_filter = static function ( $args ) use ( $case ) {
			$args['show_split_view'] = $case['showSplitView'];
			return $args;
		};

		\add_filter( '_wp_post_revision_field_post_content', $content_filter, 10, 4 );
		\add_filter( 'revision_text_diff_options', $options_filter, 10, 3 );

		try {
			$swapped_diff = \wp_get_revision_ui_diff( $post_id, $to_id, $from_id );
			$initial_diff = \wp_get_revision_ui_diff( $post_id, 0, $from_id );
		} finally {
			\remove_filter( '_wp_post_revision_field_post_content', $content_filter, 10 );
			\remove_filter( 'revision_text_diff_options', $options_filter, 10 );
		}

		$foreign_post_id = self::insert_parent_post( $case, $author_id, 'ui-foreign' );
		$foreign_id      = self::insert_revision_row(
			$foreign_post_id,
			$author_id,
			array(
				'post_title'        => 'Foreign ' . $case['token'],
				'post_content'      => 'Foreign content',
				'post_excerpt'      => '',
				'post_date'         => $case['dateFrom'],
				'post_date_gmt'     => $case['dateFromGmt'],
				'post_modified'     => $case['dateFrom'],
				'post_modified_gmt' => $case['dateFromGmt'],
			),
			false
		);
		$foreign_diff    = \wp_get_revision_ui_diff( $post_id, $foreign_id, $to_id );
		$js              = \wp_prepare_revisions_for_js( \get_post( $post_id ), $to_id, $from_id );
		$diff_ids        = is_array( $swapped_diff ) ? array_column( $swapped_diff, 'id' ) : array();
		$js_ids          = isset( $js['revisionIds'] ) && is_array( $js['revisionIds'] ) ? array_map( 'intval', $js['revisionIds'] ) : array();
		$revision_data   = isset( $js['revisionData'] ) && is_array( $js['revisionData'] ) ? $js['revisionData'] : array();
		$autosave_rows   = array_filter(
			$revision_data,
			static function ( $row ) {
				return is_array( $row ) && ! empty( $row['autosave'] );
			}
		);
		$current_rows    = array_filter(
			$revision_data,
			static function ( $row ) {
				return is_array( $row ) && ! empty( $row['current'] );
			}
		);
		$restore_urls_ok = true;
		$row_shapes_ok   = true;
		foreach ( $revision_data as $row ) {
			$row_shapes_ok = $row_shapes_ok
				&& is_array( $row )
				&& isset( $row['id'], $row['title'], $row['author'], $row['date'], $row['dateShort'], $row['timeAgo'] )
				&& array_key_exists( 'autosave', $row )
				&& array_key_exists( 'current', $row )
				&& array_key_exists( 'restoreUrl', $row );
			$restore_urls_ok = $restore_urls_ok && false === ( $row['restoreUrl'] ?? null );
		}

		self::collect_failure(
			$failures,
			is_array( $swapped_diff )
				&& in_array( 'post_title', $diff_ids, true )
				&& in_array( 'post_content', $diff_ids, true )
				&& self::diffs_contain( $swapped_diff, 'cf-from-' . $case['token'] )
				&& self::diffs_contain( $swapped_diff, 'cf-to-' . $case['token'] )
				&& is_array( $initial_diff )
				&& in_array( 'post_content', array_column( $initial_diff, 'id' ), true )
				&& false === $foreign_diff,
			'wp_get_revision_ui_diff validates parents, sorts compare direction, filters fields, and preserves title fallback',
			array(
				'postId'       => $post_id,
				'fromId'       => $from_id,
				'toId'         => $to_id,
				'autosaveId'   => $autosave_id,
				'diffIds'      => $diff_ids,
				'initialIds'   => is_array( $initial_diff ) ? array_column( $initial_diff, 'id' ) : $initial_diff,
				'foreignDiff'  => $foreign_diff,
				'splitView'    => $case['showSplitView'],
			)
		);
		self::collect_failure(
			$failures,
			isset( $js['postId'], $js['nonce'], $js['revisionData'], $js['to'], $js['from'], $js['diffData'], $js['baseUrl'], $js['compareTwoMode'], $js['revisionIds'] )
				&& (int) $post_id === (int) $js['postId']
				&& (int) $to_id === (int) $js['to']
				&& (int) $from_id === (int) $js['from']
				&& 1 === (int) $js['compareTwoMode']
				&& in_array( (int) $from_id, $js_ids, true )
				&& in_array( (int) $to_id, $js_ids, true )
				&& in_array( (int) $autosave_id, $js_ids, true )
				&& 1 === count( $current_rows )
				&& 1 <= count( $autosave_rows )
				&& $row_shapes_ok
				&& $restore_urls_ok
				&& isset( $js['diffData'][0]['id'], $js['diffData'][0]['fields'] )
				&& "{$from_id}:{$to_id}" === $js['diffData'][0]['id']
				&& is_array( $js['diffData'][0]['fields'] ),
			'wp_prepare_revisions_for_js returns deterministic revision metadata and initial diff payload',
			array(
				'keys'          => array_keys( $js ),
				'revisionIds'   => $js_ids,
				'currentRows'   => array_values( $current_rows ),
				'autosaveRows'  => array_values( $autosave_rows ),
				'diffData'      => $js['diffData'] ?? null,
				'restoreUrlsOk' => $restore_urls_ok,
				'rowShapesOk'   => $row_shapes_ok,
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.revision-ui-payloads',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'postId'     => $post_id,
				'fromId'     => $from_id,
				'toId'       => $to_id,
				'autosaveId' => $autosave_id,
			)
		);
	}

	private static function check_preview_helper( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures  = array();
		$author_id = self::insert_author( $case, 'preview' );
		$post_id   = self::insert_parent_post( $case, $author_id, 'preview' );
		$autosave  = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'],
				'post_content'      => $case['contentTo'],
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateTo'],
				'post_date_gmt'     => $case['dateToGmt'],
				'post_modified'     => $case['dateTo'],
				'post_modified_gmt' => $case['dateToGmt'],
			),
			true
		);
		$previewed = \_set_preview( \get_post( $post_id ) );
		$scalar    = \_set_preview( 'not-a-post' );

		$terms_filter_added = false !== \has_filter( 'get_the_terms', '_wp_preview_terms_filter' );
		$thumb_filter_added = false !== \has_filter( 'get_post_metadata', '_wp_preview_post_thumbnail_filter' );
		$meta_filter_added  = false !== \has_filter( 'get_post_metadata', '_wp_preview_meta_filter' );

		\remove_filter( 'get_the_terms', '_wp_preview_terms_filter', 10 );
		\remove_filter( 'get_post_metadata', '_wp_preview_post_thumbnail_filter', 10 );
		\remove_filter( 'get_post_metadata', '_wp_preview_meta_filter', 10 );

		self::collect_failure(
			$failures,
			is_int( $autosave )
				&& $previewed instanceof \WP_Post
				&& $case['titleTo'] === $previewed->post_title
				&& $case['contentTo'] === $previewed->post_content
				&& $case['excerptTo'] === $previewed->post_excerpt
				&& 'not-a-post' === $scalar
				&& $terms_filter_added
				&& $thumb_filter_added
				&& $meta_filter_added
				&& false === \has_filter( 'get_the_terms', '_wp_preview_terms_filter' )
				&& false === \has_filter( 'get_post_metadata', '_wp_preview_post_thumbnail_filter' )
				&& false === \has_filter( 'get_post_metadata', '_wp_preview_meta_filter' ),
			'_set_preview overlays autosave fields and preview filters are removable',
			array(
				'postId'            => $post_id,
				'autosaveId'        => $autosave,
				'previewed'         => self::post_summary( $previewed ),
				'scalar'            => $scalar,
				'termsFilterAdded'  => $terms_filter_added,
				'thumbFilterAdded'  => $thumb_filter_added,
				'metaFilterAdded'   => $meta_filter_added,
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.preview-helper',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'postId'     => $post_id,
				'autosaveId' => $autosave,
			)
		);
	}

	private static function prepare_runtime(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->component_fuzz_reset_content();
		$wpdb->component_fuzz_reset_options(
			array(
				'admin_email'                   => 'admin@example.test',
				'blog_charset'                  => 'UTF-8',
				'blogname'                      => 'Component Fuzz Revisions',
				'default_category'              => 0,
				'default_comment_status'        => 'closed',
				'default_ping_status'           => 'closed',
				'default_role'                  => 'subscriber',
				'home'                          => 'http://example.test',
				'permalink_structure'           => '',
				'require_name_email'            => 0,
				'show_avatars'                  => 0,
				'siteurl'                       => 'http://example.test',
				'timezone_string'               => '',
				'uploads_use_yearmonth_folders' => 0,
			)
		);

		\wp_cache_flush();
		$GLOBALS['wp_post_types']    = array();
		$GLOBALS['wp_post_statuses'] = array();
		$GLOBALS['wp_taxonomies']    = array();
		$GLOBALS['wp_meta_keys']     = array();
		$GLOBALS['wp_rewrite']       = new \WP_Rewrite();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz RevisionsAutosaves';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/revisions-autosaves/';
		$_SERVER['SERVER_SOFTWARE'] = 'ComponentFuzz';
	}

	private static function register_case_post_type( array $case, bool $revisions ): void {
		\register_post_type(
			$case['postType'],
			array(
				'public'      => true,
				'query_var'   => false,
				'rewrite'     => false,
				'show_in_rest' => false,
				'show_ui'     => true,
				'supports'    => $revisions
					? array( 'title', 'editor', 'excerpt', 'author', 'revisions', 'custom-fields' )
					: array( 'title', 'editor', 'author' ),
			)
		);
	}

	private static function insert_author( array $case, string $suffix ): int {
		$user_id = \wp_insert_user(
			array(
				'display_name' => 'Revision Author ' . $case['token'] . ' ' . $suffix,
				'role'         => 'subscriber',
				'user_email'   => 'revision-' . $case['token'] . '-' . $suffix . '@example.test',
				'user_login'   => 'revision_' . $case['token'] . '_' . str_replace( '-', '_', $suffix ),
				'user_pass'    => 'component-fuzz-pass',
			)
		);

		return is_int( $user_id ) ? $user_id : 0;
	}

	private static function insert_parent_post( array $case, int $author_id, string $suffix ): int {
		$post_id = \wp_insert_post(
			\wp_slash(
				array(
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_author'    => $author_id,
					'post_content'   => $case['contentFrom'],
					'post_date'      => $case['dateFrom'],
					'post_date_gmt'  => $case['dateFromGmt'],
					'post_excerpt'   => $case['excerptFrom'],
					'post_name'      => $case['slug'] . '-' . $suffix,
					'post_status'    => 'draft',
					'post_title'     => $case['titleFrom'],
					'post_type'      => $case['postType'],
				)
			),
			true,
			true
		);

		return is_int( $post_id ) ? $post_id : 0;
	}

	private static function insert_revision_row( int $post_id, int $author_id, array $fields, bool $autosave ): int {
		$revision_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_author'       => $author_id,
					'post_content'      => $fields['post_content'] ?? '',
					'post_date'         => $fields['post_date'] ?? '',
					'post_date_gmt'     => $fields['post_date_gmt'] ?? '',
					'post_excerpt'      => $fields['post_excerpt'] ?? '',
					'post_modified'     => $fields['post_modified'] ?? ( $fields['post_date'] ?? '' ),
					'post_modified_gmt' => $fields['post_modified_gmt'] ?? ( $fields['post_date_gmt'] ?? '' ),
					'post_name'         => $post_id . ( $autosave ? '-autosave-v1' : '-revision-v1' ),
					'post_parent'       => $post_id,
					'post_status'       => 'inherit',
					'post_title'        => $fields['post_title'] ?? '',
					'post_type'         => 'revision',
				)
			),
			true,
			true
		);

		return is_int( $revision_id ) ? $revision_id : 0;
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'wp_rewrite', 'wp_meta_keys', 'current_user', 'user_ID', 'post', 'wp_query', 'wp_the_query' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_HOST', 'HTTP_USER_AGENT', 'REQUEST_URI', 'SERVER_SOFTWARE' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'get'     => $_GET,
			'globals' => $globals,
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
			'post'    => $_POST,
			'server'  => $server,
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

		$_GET  = $snapshot['get'];
		$_POST = $snapshot['post'];
	}

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot, array $case ): array {
		$wpdb      = $GLOBALS['wpdb'] ?? null;
		$counts    = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_content_counts() : array();
		$options   = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_get_options() : array();
		$count_ok  = array() === array_filter( $counts );
		$filter_ok = false === \has_filter( '_wp_post_revision_field_post_content' )
			&& false === \has_filter( 'revision_text_diff_options' )
			&& false === \has_filter( 'wp_revisions_to_keep' )
			&& false === \has_filter( "wp_{$case['postType']}_revisions_to_keep" )
			&& false === \has_filter( 'get_the_terms', '_wp_preview_terms_filter' )
			&& false === \has_filter( 'get_post_metadata', '_wp_preview_post_thumbnail_filter' )
			&& false === \has_filter( 'get_post_metadata', '_wp_preview_meta_filter' );

		return $ctx->result(
			'revisions-autosaves.state-restored-between-iterations',
			$count_ok
				&& $snapshot['options'] === $options
				&& $filter_ok
				&& ! \post_type_exists( $case['postType'] ),
			array(
				'counts'        => $counts,
				'optionsMatch'  => $snapshot['options'] === $options,
				'filtersGone'   => $filter_ok,
				'postTypeGone'  => ! \post_type_exists( $case['postType'] ),
			)
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token  = substr( hash( 'sha256', $ctx->seed() . ':' . $ctx->iteration() ), 0, 8 );
		$day    = $ctx->int( 1, 24 );
		$hour   = $ctx->int( 0, 20 );
		$minute = $ctx->int( 0, 50 );
		$base   = sprintf( '2021-03-%02d %02d:%02d:00', $day, $hour, $minute );
		$later  = sprintf( '2021-03-%02d %02d:%02d:00', $day, $hour + 1, $minute + 1 );
		$latest = sprintf( '2021-03-%02d %02d:%02d:00', $day, $hour + 2, $minute + 2 );

		return array(
			'token'                => $token,
			'postType'             => substr( \sanitize_key( 'cfrev_' . $token ), 0, 20 ),
			'slug'                 => 'revision-autosave-' . $token,
			'titleFrom'            => self::clean_text( $ctx->fork( 'title-from' )->choice( array( 'Revision title ' . $token, '<b>Title</b> ' . $token, "Title \"quoted\" {$token}" ) ), 'Revision title ' . $token ),
			'titleTo'              => self::clean_text( $ctx->fork( 'title-to' )->choice( array( 'Changed title ' . $token, '<i>Changed</i> ' . $token, "Changed 'quoted' {$token}" ) ), 'Changed title ' . $token ),
			'contentFrom'          => self::clean_content( $ctx->fork( 'content-from' )->choice( array( 'Original content ' . $token, "<p>Original {$token}</p>", "first line {$token}\nsecond line" ) ), 'Original content ' . $token ),
			'contentTo'            => self::clean_content( $ctx->fork( 'content-to' )->choice( array( 'Changed content ' . $token, "<p>Changed {$token}</p>", "first line {$token}\nchanged second line" ) ), 'Changed content ' . $token ),
			'excerptFrom'          => self::clean_text( $ctx->fork( 'excerpt-from' )->choice( array( 'Original excerpt ' . $token, '', '<em>Excerpt</em> ' . $token ) ), 'Original excerpt ' . $token ),
			'excerptTo'            => self::clean_text( $ctx->fork( 'excerpt-to' )->choice( array( 'Changed excerpt ' . $token, '', '<em>Changed excerpt</em> ' . $token ) ), 'Changed excerpt ' . $token ),
			'dateFrom'             => $base,
			'dateFromGmt'          => $base,
			'dateTo'               => $later,
			'dateToGmt'            => $later,
			'dateLater'            => $latest,
			'dateLaterGmt'         => $latest,
			'revisionLimit'        => $ctx->int( 1, 5 ),
			'dynamicRevisionLimit' => $ctx->choice( array( 1, 2, 3, 5 ) ),
			'showSplitView'        => $ctx->bool(),
		);
	}

	private static function clean_text( string $value, string $fallback ): string {
		$value = \wp_check_invalid_utf8( str_replace( "\0", '', $value ), true );
		$value = trim( strip_tags( $value ) );
		if ( '' === $value ) {
			$value = $fallback;
		}

		return substr( $value, 0, 160 );
	}

	private static function clean_content( string $value, string $fallback ): string {
		$value = \wp_check_invalid_utf8( str_replace( "\0", '', $value ), true );
		if ( '' === trim( strip_tags( $value ) ) ) {
			$value = $fallback;
		}

		return substr( $value, 0, 260 );
	}

	private static function diffs_contain( array $diffs, string $needle ): bool {
		foreach ( $diffs as $diff ) {
			if ( ! is_array( $diff ) ) {
				continue;
			}

			$html = (string) ( $diff['diff'] ?? '' );
			$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
			if ( false !== strpos( $html, $needle ) || false !== strpos( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
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
			'token'                => $case['token'],
			'postType'             => $case['postType'],
			'slug'                 => $case['slug'],
			'dateFrom'             => $case['dateFrom'],
			'dateTo'               => $case['dateTo'],
			'revisionLimit'        => $case['revisionLimit'],
			'dynamicRevisionLimit' => $case['dynamicRevisionLimit'],
			'showSplitView'        => $case['showSplitView'],
		);
	}

	private static function post_summary( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $post;
		}

		return array(
			'ID'                => $post->ID,
			'post_parent'       => $post->post_parent,
			'post_name'         => $post->post_name,
			'post_type'         => $post->post_type,
			'post_status'       => $post->post_status,
			'post_title'        => $post->post_title,
			'post_modified_gmt' => $post->post_modified_gmt,
		);
	}

	private static function error_summary( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return $value;
		}

		return array(
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
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
