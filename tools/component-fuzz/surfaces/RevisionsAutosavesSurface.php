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
			$rows[] = self::check_revision_field_filter_contracts( $ctx->fork( 'field-filters' ), $case );
			$rows[] = self::check_revision_insert_lookup_predicates( $ctx->fork( 'predicates' ), $case );
			$rows[] = self::check_save_restore_and_meta_helpers( $ctx->fork( 'restore-meta' ), $case );
			$rows[] = self::check_revision_retention_pruning( $ctx->fork( 'retention-pruning' ), $case );
			$rows[] = self::check_autosave_create_update_delete_and_locks( $ctx->fork( 'autosave-locks' ), $case );
			$rows[] = self::check_revision_support_restore_edges_and_titles( $ctx->fork( 'support-restore-ui' ), $case );
			$rows[] = self::check_revision_ui_payloads( $ctx->fork( 'ui' ), $case );
			$rows[] = self::check_preview_helper( $ctx->fork( 'preview' ), $case );
			$rows[] = self::check_latest_revision_count_and_url_helpers( $ctx->fork( 'latest-count-url' ), $case );
			$rows[] = self::check_user_filtered_autosave_lookup( $ctx->fork( 'user-filtered-autosave' ), $case );
			$rows[] = self::check_revision_template_output( $ctx->fork( 'templates' ), $case );
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
				'__',
				'_e',
				'_ex',
				'_set_preview',
				'_wp_copy_post_meta',
				'_wp_post_revision_data',
				'_wp_post_revision_fields',
				'_wp_put_post_revision',
				'add_action',
				'add_filter',
				'add_post_meta',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'delete_post_meta',
				'esc_attr_e',
				'esc_attr_x',
				'esc_html_e',
				'get_post',
				'get_post_meta',
				'get_edit_post_link',
				'get_userdata',
				'has_filter',
				'is_wp_error',
				'metadata_exists',
				'post_type_exists',
				'register_post_meta',
				'register_post_type',
				'remove_action',
				'remove_filter',
				'sanitize_key',
				'sanitize_title',
				'unregister_meta_key',
				'update_post_meta',
				'wp_cache_flush',
				'wp_check_invalid_utf8',
				'wp_check_post_lock',
				'wp_check_revisioned_meta_fields_have_changed',
				'wp_create_post_autosave',
				'wp_delete_post_revision',
				'wp_get_post_autosave',
				'wp_get_latest_revision_id_and_total_count',
				'wp_get_post_revision',
				'wp_get_post_revisions',
				'wp_get_post_revisions_url',
				'wp_get_revision_ui_diff',
				'wp_insert_post',
				'wp_insert_user',
				'wp_is_post_autosave',
				'wp_is_post_revision',
				'wp_list_post_revisions',
				'wp_post_revision_title',
				'wp_post_revision_title_expanded',
				'wp_post_revision_meta_keys',
				'wp_prepare_revisions_for_js',
				'wp_print_revision_templates',
				'wp_restore_post_revision',
				'wp_restore_post_revision_meta',
				'wp_revisions_enabled',
				'wp_revisions_to_keep',
				'wp_save_post_revision',
				'wp_save_revisioned_meta_fields',
				'wp_set_post_lock',
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

	private static function check_revision_field_filter_contracts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();

		$failures = array();
		$post_id  = 1000 + $ctx->int( 1, 8000 );
		$post     = array(
			'ID'                => $post_id,
			'post_author'       => 77,
			'post_content'      => $case['contentFrom'],
			'post_date'         => $case['dateFrom'],
			'post_date_gmt'     => $case['dateFromGmt'],
			'post_excerpt'      => $case['excerptFrom'],
			'post_modified'     => $case['dateTo'],
			'post_modified_gmt' => $case['dateToGmt'],
			'post_name'         => $case['slug'],
			'post_parent'       => 11,
			'post_status'       => 'publish',
			'post_title'        => $case['titleFrom'],
			'post_type'         => $case['postType'],
			'comment_count'     => 9,
		);
		$protected = array( 'ID', 'post_name', 'post_parent', 'post_date', 'post_date_gmt', 'post_status', 'post_type', 'comment_count', 'post_author' );
		$filter    = static function ( array $fields ) use ( $protected ): array {
			foreach ( $protected as $field ) {
				$fields[ $field ] = 'Component fuzz protected field';
			}

			return $fields;
		};

		\add_filter( '_wp_post_revision_fields', $filter, 10, 2 );
		try {
			$filtered_fields   = \_wp_post_revision_fields( $post );
			$filtered_revision = \_wp_post_revision_data( $post, false );
			$second_fields     = \_wp_post_revision_fields( $post );
		} finally {
			\remove_filter( '_wp_post_revision_fields', $filter, 10 );
		}

		$field_leaks    = array_values( array_intersect( $protected, array_keys( $filtered_fields ) ) );
		$revision_leaks = array_values( array_intersect( $protected, array_keys( $filtered_revision ) ) );
		$expected_leaks = array( 'post_date', 'post_date_gmt', 'post_name', 'post_parent', 'post_status', 'post_type' );

		sort( $revision_leaks );
		sort( $expected_leaks );

		self::collect_failure(
			$failures,
			array() === $field_leaks
				&& $expected_leaks === $revision_leaks
				&& $post_id === (int) ( $filtered_revision['post_parent'] ?? 0 )
				&& 'revision' === ( $filtered_revision['post_type'] ?? null )
				&& 'inherit' === ( $filtered_revision['post_status'] ?? null )
				&& $case['dateTo'] === ( $filtered_revision['post_date'] ?? null )
				&& $case['dateToGmt'] === ( $filtered_revision['post_date_gmt'] ?? null ),
			'_wp_post_revision_fields removes protected fields even when filters add them',
			array(
				'fieldLeaks'    => $field_leaks,
				'revisionLeaks' => $revision_leaks,
				'fields'        => $filtered_fields,
				'revision'      => $filtered_revision,
			)
		);
		self::collect_failure(
			$failures,
			array() === array_values( array_intersect( $protected, array_keys( $second_fields ) ) )
				&& isset( $second_fields['post_title'], $second_fields['post_content'], $second_fields['post_excerpt'] ),
			'protected field filtering does not leak into later revision field lookups',
			array( 'secondFields' => $second_fields )
		);

		return self::result(
			$ctx,
			'revisions-autosaves.protected-field-filter-contracts',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'fieldCount' => count( $filtered_fields ),
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

	private static function check_revision_retention_pruning( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures            = array();
		$author_id           = self::insert_author( $case, 'retention' );
		$post_id             = self::insert_parent_post( $case, $author_id, 'retention' );
		$revision_limit      = $ctx->int( 1, 3 );
		$normal_save_count   = $revision_limit + $ctx->int( 2, 4 );
		$normal_revision_ids = array();
		$update_results      = array();
		$autosave_id         = null;
		$grant_caps          = self::grant_all_caps_filter( $author_id );

		\wp_set_current_user( $author_id );

		for ( $i = 0; $i < $normal_save_count; $i++ ) {
			$step      = $i + 1;
			$timestamp = self::offset_mysql_date( $case['dateFrom'], $step * 7 );

			$update_results[] = \wp_update_post(
				\wp_slash(
					array(
						'ID'                => $post_id,
						'post_content'      => $case['contentFrom'] . "\nRetention normal {$step} {$case['token']}",
						'post_date'         => $timestamp,
						'post_date_gmt'     => $timestamp,
						'post_excerpt'      => $case['excerptFrom'] . ' retention ' . $step,
						'post_modified'     => $timestamp,
						'post_modified_gmt' => $timestamp,
						'post_title'        => $case['titleFrom'] . ' retention ' . $step,
					)
				),
				true,
				false
			);

			$revision_id = \wp_save_post_revision( $post_id );
			if ( is_int( $revision_id ) ) {
				$normal_revision_ids[] = $revision_id;
			}

			if ( 0 === $i ) {
				\add_filter( 'user_has_cap', $grant_caps, 10, 4 );
				try {
					$autosave_id = \wp_create_post_autosave(
						\wp_slash(
							self::autosave_post_data(
								$post_id,
								$case,
								$case['titleTo'] . ' retention autosave',
								$case['contentTo'] . "\nRetention autosave {$case['token']}",
								$case['excerptTo'] . ' retention autosave'
							)
						)
					);
				} finally {
					\remove_filter( 'user_has_cap', $grant_caps, 10 );
				}
			}
		}

		$deleted_revisions      = array();
		$deletion_candidate_ids = array();
		$limit_filter           = static function ( int $num, \WP_Post $post ) use ( $post_id, $revision_limit ): int {
			if ( (int) $post_id !== (int) $post->ID ) {
				return $num;
			}

			return $revision_limit;
		};
		$deletion_filter        = static function ( array $revisions, int $seen_post_id ) use ( &$deletion_candidate_ids, $post_id ): array {
			if ( (int) $post_id === (int) $seen_post_id ) {
				$deletion_candidate_ids = array_map(
					static function ( \WP_Post $revision ): int {
						return (int) $revision->ID;
					},
					array_values( $revisions )
				);
			}

			return $revisions;
		};
		$delete_recorder        = static function ( int $revision_id, \WP_Post $revision ) use ( &$deleted_revisions ): void {
			$deleted_revisions[] = array(
				'id'       => (int) $revision_id,
				'parent'   => (int) $revision->post_parent,
				'postName' => (string) $revision->post_name,
			);
		};
		$final_timestamp        = self::offset_mysql_date( $case['dateFrom'], ( $normal_save_count + 1 ) * 7 );
		$final_update           = \wp_update_post(
			\wp_slash(
				array(
					'ID'                => $post_id,
					'post_content'      => $case['contentTo'] . "\nRetention final {$case['token']}",
					'post_date'         => $final_timestamp,
					'post_date_gmt'     => $final_timestamp,
					'post_excerpt'      => $case['excerptTo'] . ' retention final',
					'post_modified'     => $final_timestamp,
					'post_modified_gmt' => $final_timestamp,
					'post_title'        => $case['titleTo'] . ' retention final',
				)
			),
			true,
			false
		);

		\add_filter( 'wp_revisions_to_keep', $limit_filter, 10, 2 );
		\add_filter( 'wp_save_post_revision_revisions_before_deletion', $deletion_filter, 10, 2 );
		\add_action( 'wp_delete_post_revision', $delete_recorder, 10, 2 );
		try {
			$final_revision = \wp_save_post_revision( $post_id );
		} finally {
			\remove_action( 'wp_delete_post_revision', $delete_recorder, 10 );
			\remove_filter( 'wp_save_post_revision_revisions_before_deletion', $deletion_filter, 10 );
			\remove_filter( 'wp_revisions_to_keep', $limit_filter, 10 );
		}

		$remaining_revisions = \wp_get_post_revisions(
			$post_id,
			array(
				'check_enabled' => false,
				'order'         => 'ASC',
			)
		);
		$remaining_ids       = array_map( 'intval', array_keys( $remaining_revisions ) );
		$normal_ids          = $normal_revision_ids;
		if ( is_int( $final_revision ) ) {
			$normal_ids[] = $final_revision;
		}
		$remaining_normal_ids = array_values( array_intersect( $normal_ids, $remaining_ids ) );
		$delete_window        = max( 0, count( $deletion_candidate_ids ) - $revision_limit );
		$delete_candidates    = array_slice( $deletion_candidate_ids, 0, $delete_window );
		$autosave_int         = is_int( $autosave_id ) ? $autosave_id : 0;
		$expected_deleted_ids = array_values(
			array_filter(
				$delete_candidates,
				static function ( int $revision_id ) use ( $autosave_int ): bool {
					return (int) $revision_id !== (int) $autosave_int;
				}
			)
		);
		$actual_deleted_ids   = array_map( 'intval', array_column( $deleted_revisions, 'id' ) );
		$expected_remaining   = array_values( array_diff( $deletion_candidate_ids, $expected_deleted_ids ) );
		$autosave_post        = is_int( $autosave_id ) ? \wp_get_post_revision( $autosave_id ) : null;
		$updates_ok           = array() === array_filter(
			$update_results,
			static function ( $result ) use ( $post_id ): bool {
				return ! is_int( $result ) || (int) $post_id !== (int) $result;
			}
		);
		$deleted_parents_ok   = array() === array_filter(
			$deleted_revisions,
			static function ( array $row ) use ( $post_id ): bool {
				return (int) $post_id !== (int) $row['parent'] || false !== strpos( $row['postName'], 'autosave' );
			}
		);

		sort( $expected_deleted_ids );
		sort( $actual_deleted_ids );
		sort( $expected_remaining );
		sort( $remaining_ids );

		self::collect_failure(
			$failures,
			$updates_ok
				&& count( $normal_revision_ids ) === $normal_save_count
				&& is_int( $autosave_id )
				&& (int) $post_id === \wp_is_post_autosave( $autosave_id ),
			'bounded revision and autosave timeline is created before retention pruning',
			array(
				'postId'            => $post_id,
				'normalSaveCount'   => $normal_save_count,
				'normalRevisionIds' => $normal_revision_ids,
				'autosaveId'        => self::error_summary( $autosave_id ),
				'updates'           => array_map( array( self::class, 'error_summary' ), $update_results ),
			)
		);
		self::collect_failure(
			$failures,
			is_int( $final_revision )
				&& is_int( $final_update )
				&& (int) $post_id === (int) $final_update
				&& count( $deletion_candidate_ids ) === count( $normal_revision_ids ) + 2
				&& in_array( (int) $autosave_int, $deletion_candidate_ids, true )
				&& in_array( (int) $final_revision, $deletion_candidate_ids, true ),
			'wp_save_post_revision retention filter sees normal revisions, autosaves, and the new revision before deletion',
			array(
				'finalUpdate'       => self::error_summary( $final_update ),
				'finalRevision'     => self::error_summary( $final_revision ),
				'candidateIds'      => $deletion_candidate_ids,
				'autosaveId'        => self::error_summary( $autosave_id ),
				'normalRevisionIds' => $normal_revision_ids,
			)
		);
		self::collect_failure(
			$failures,
			$expected_deleted_ids === $actual_deleted_ids
				&& $expected_remaining === $remaining_ids
				&& count( $remaining_normal_ids ) === $revision_limit
				&& $autosave_post instanceof \WP_Post
				&& $deleted_parents_ok,
			'retention pruning deletes the oldest normal revisions, keeps the configured normal limit, and preserves autosaves',
			array(
				'revisionLimit'       => $revision_limit,
				'deleteWindow'        => $delete_window,
				'deleteCandidates'    => $delete_candidates,
				'expectedDeletedIds'  => $expected_deleted_ids,
				'actualDeletedIds'    => $actual_deleted_ids,
				'expectedRemaining'   => $expected_remaining,
				'remainingIds'        => $remaining_ids,
				'remainingNormalIds'  => $remaining_normal_ids,
				'autosave'            => self::post_summary( $autosave_post ),
				'deletedRevisionRows' => $deleted_revisions,
			)
		);
		self::collect_failure(
			$failures,
			false === \has_filter( 'wp_revisions_to_keep', $limit_filter )
				&& false === \has_filter( 'wp_save_post_revision_revisions_before_deletion', $deletion_filter )
				&& false === \has_filter( 'wp_delete_post_revision', $delete_recorder ),
			'retention pruning filters and deletion recorder are removed after the save',
			array(
				'wpRevisionsToKeep'       => \has_filter( 'wp_revisions_to_keep', $limit_filter ),
				'beforeDeletionFilter'    => \has_filter( 'wp_save_post_revision_revisions_before_deletion', $deletion_filter ),
				'deleteRevisionRecorder'  => \has_filter( 'wp_delete_post_revision', $delete_recorder ),
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.retention-pruning-preserves-autosaves',
			$failures,
			array(
				'case'            => self::case_summary( $case ),
				'postId'          => $post_id,
				'revisionLimit'   => $revision_limit,
				'normalSaveCount' => $normal_save_count,
				'autosaveId'      => self::error_summary( $autosave_id ),
				'finalRevision'   => self::error_summary( $final_revision ),
			)
		);
	}

	private static function check_autosave_create_update_delete_and_locks( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures       = array();
		$author_id      = self::insert_author( $case, 'autosave-author' );
		$other_user_id  = self::insert_author( $case, 'autosave-other' );
		$post_id        = self::insert_parent_post( $case, $author_id, 'autosave-locks' );
		$meta_key       = 'cf_autosave_' . $case['token'];
		$meta_value     = 'autosave-meta-' . $case['token'] . '-' . $ctx->int( 10, 999 );
		$updated_title  = $case['titleTo'] . ' autosave update';
		$updated_body   = $case['contentTo'] . "\nAutosave update " . $case['token'];
		$updated_excerpt = $case['excerptTo'] . ' autosave update';
		$events         = array();
		$grant_caps     = self::grant_all_caps_filter( $author_id );
		$meta_saver     = static function ( array $new_autosave ): void {
			\wp_autosave_post_revisioned_meta_fields( $new_autosave );
		};
		$recorder       = static function ( array $new_autosave, bool $is_update = false ) use ( &$events ): void {
			$events[] = array(
				'id'       => (int) ( $new_autosave['ID'] ?? 0 ),
				'parent'   => (int) ( $new_autosave['post_parent'] ?? 0 ),
				'isUpdate' => $is_update,
				'title'    => (string) ( $new_autosave['post_title'] ?? '' ),
			);
		};
		$registered     = \register_post_meta(
			$case['postType'],
			$meta_key,
			array(
				'revisions_enabled' => true,
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		\wp_set_current_user( $author_id );
		$denied = \wp_create_post_autosave(
			\wp_slash(
				self::autosave_post_data(
					$post_id,
					$case,
					$case['titleTo'],
					$case['contentTo'],
					$case['excerptTo']
				)
			)
		);

		\add_filter( 'user_has_cap', $grant_caps, 10, 4 );
		\add_action( 'wp_creating_autosave', $meta_saver, 10, 1 );
		\add_action( 'wp_creating_autosave', $recorder, 11, 2 );

		try {
			$_POST[ $meta_key ] = $meta_value;
			$created            = \wp_create_post_autosave(
				\wp_slash(
					self::autosave_post_data(
						$post_id,
						$case,
						$case['titleTo'],
						$case['contentTo'],
						$case['excerptTo']
					)
				)
			);
			$created_post       = is_int( $created ) ? \get_post( $created ) : null;
			$created_meta       = is_int( $created ) ? \get_post_meta( $created, $meta_key, true ) : null;

			$_POST[ $meta_key ] = '';
			$updated            = \wp_create_post_autosave(
				\wp_slash(
					self::autosave_post_data(
						$post_id,
						$case,
						$updated_title,
						$updated_body,
						$updated_excerpt
					)
				)
			);
			$updated_post       = is_int( $updated ) ? \get_post( $updated ) : null;
			$updated_meta       = is_int( $updated ) ? \get_post_meta( $updated, $meta_key, true ) : null;

			$deleted_same       = \wp_create_post_autosave(
				\wp_slash(
					self::autosave_post_data(
						$post_id,
						$case,
						$case['titleFrom'],
						$case['contentFrom'],
						$case['excerptFrom']
					)
				)
			);
			$after_delete       = \wp_get_post_autosave( $post_id, $author_id );
		} finally {
			unset( $_POST[ $meta_key ] );
			\remove_action( 'wp_creating_autosave', $recorder, 11 );
			\remove_action( 'wp_creating_autosave', $meta_saver, 10 );
			\remove_filter( 'user_has_cap', $grant_caps, 10 );
			\unregister_meta_key( 'post', $meta_key, $case['postType'] );
		}

		self::collect_failure(
			$failures,
			\is_wp_error( $denied )
				&& 'edit_others_posts' === $denied->get_error_code(),
			'wp_create_post_autosave returns a capability error before writes when editing is denied',
			array( 'denied' => self::error_summary( $denied ) )
		);
		self::collect_failure(
			$failures,
			true === $registered
				&& is_int( $created )
				&& $created_post instanceof \WP_Post
				&& (int) $post_id === (int) $created_post->post_parent
				&& "{$post_id}-autosave-v1" === $created_post->post_name
				&& $case['titleTo'] === $created_post->post_title
				&& $case['contentTo'] === $created_post->post_content
				&& $case['excerptTo'] === $created_post->post_excerpt
				&& $meta_value === $created_meta,
			'wp_create_post_autosave creates an autosave revision and saves posted revisioned meta',
			array(
				'created'     => $created,
				'createdPost' => self::post_summary( $created_post ),
				'createdMeta' => $created_meta,
				'metaKey'     => $meta_key,
			)
		);
		self::collect_failure(
			$failures,
			is_int( $created )
				&& (int) $created === (int) $updated
				&& $updated_post instanceof \WP_Post
				&& $updated_title === $updated_post->post_title
				&& $updated_body === $updated_post->post_content
				&& $updated_excerpt === $updated_post->post_excerpt
				&& '' === $updated_meta,
			'wp_create_post_autosave updates the existing autosave for the author and clears blank posted meta',
			array(
				'created'     => $created,
				'updated'     => $updated,
				'updatedPost' => self::post_summary( $updated_post ),
				'updatedMeta' => $updated_meta,
			)
		);
		$event_pair_ok = false === ( $events[0]['isUpdate'] ?? null )
			&& true === ( $events[1]['isUpdate'] ?? null )
			&& (int) ( $events[0]['id'] ?? 0 ) === (int) ( $events[1]['id'] ?? -1 )
			&& (int) $post_id === (int) ( $events[0]['parent'] ?? 0 )
			&& (int) $post_id === (int) ( $events[1]['parent'] ?? 0 );
		$delete_path   = 0 === $deleted_same
			&& false === $after_delete
			&& 2 === count( $events )
			&& $event_pair_ok;
		$update_path   = is_int( $deleted_same )
			&& (int) $deleted_same === (int) $updated
			&& $after_delete instanceof \WP_Post
			&& 3 === count( $events )
			&& $event_pair_ok
			&& true === ( $events[2]['isUpdate'] ?? null )
			&& (int) ( $events[1]['id'] ?? 0 ) === (int) ( $events[2]['id'] ?? -1 )
			&& (int) $post_id === (int) ( $events[2]['parent'] ?? 0 );

		self::collect_failure(
			$failures,
			$delete_path || $update_path,
			'wp_create_post_autosave deletes unchanged autosaves or updates changed autosaves with bounded action shapes',
			array(
				'deletedSame' => $deleted_same,
				'afterDelete' => self::post_summary( $after_delete ),
				'events'      => $events,
				'path'        => $delete_path ? 'delete' : ( $update_path ? 'update' : 'unexpected' ),
			)
		);

		\wp_set_current_user( 0 );
		$anonymous_lock = \wp_set_post_lock( $post_id );
		\wp_set_current_user( $author_id );
		$own_lock        = \wp_set_post_lock( $post_id );
		$same_user_check = \wp_check_post_lock( $post_id );
		\wp_set_current_user( $other_user_id );
		$other_check     = \wp_check_post_lock( $post_id );
		$window_filter   = static function () use ( $case ): int {
			return $case['lockWindow'];
		};

		\update_post_meta( $post_id, '_edit_lock', ( time() - $case['lockWindow'] - 5 ) . ':' . $author_id );
		\add_filter( 'wp_check_post_lock_window', $window_filter, 10, 1 );
		try {
			$stale_check = \wp_check_post_lock( $post_id );
		} finally {
			\remove_filter( 'wp_check_post_lock_window', $window_filter, 10 );
		}

		\update_post_meta( $post_id, '_edit_lock', time() . ':999999' );
		$missing_user_check = \wp_check_post_lock( $post_id );

		self::collect_failure(
			$failures,
			false === $anonymous_lock
				&& is_array( $own_lock )
				&& (int) $author_id === (int) ( $own_lock[1] ?? 0 )
				&& false === $same_user_check
				&& (int) $author_id === (int) $other_check
				&& false === $stale_check
				&& false === $missing_user_check,
			'wp_set_post_lock and wp_check_post_lock distinguish anonymous, owner, other, stale, and missing-user locks',
			array(
				'anonymousLock'    => $anonymous_lock,
				'ownLock'          => self::lock_summary( $own_lock ),
				'sameUserCheck'    => $same_user_check,
				'otherCheck'       => $other_check,
				'staleCheck'       => $stale_check,
				'missingUserCheck' => $missing_user_check,
				'lockWindow'       => $case['lockWindow'],
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.autosave-create-update-delete-and-locks',
			$failures,
			array(
				'case'        => self::case_summary( $case ),
				'postId'      => $post_id,
				'created'     => $created,
				'updated'     => $updated,
				'deletedSame' => $deleted_same,
			)
		);
	}

	private static function check_revision_support_restore_edges_and_titles( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures             = array();
		$author_id            = self::insert_author( $case, 'support-restore' );
		$post_id              = self::insert_parent_post( $case, $author_id, 'support-restore' );
		$revision_id          = self::insert_revision_row(
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
			false
		);
		$autosave_id          = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'],
				'post_content'      => $case['contentTo'] . "\nAutosave title helper " . $case['token'],
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateLater'],
				'post_date_gmt'     => $case['dateLaterGmt'],
				'post_modified'     => $case['dateLater'],
				'post_modified_gmt' => $case['dateLaterGmt'],
			),
			true
		);
		$events               = array();
		$restore_recorder     = static function ( int $restored_post_id, int $restored_revision_id ) use ( &$events ): void {
			$events[] = array(
				'postId'     => $restored_post_id,
				'revisionId' => $restored_revision_id,
			);
		};
		$restore_non_revision = \wp_restore_post_revision( $post_id );
		$restore_empty_fields = \wp_restore_post_revision( $revision_id, array() );

		\wp_set_current_user( $author_id );
		\add_action( 'wp_restore_post_revision', $restore_recorder, 10, 2 );
		try {
			$restore_all = \wp_restore_post_revision( $revision_id );
		} finally {
			\remove_action( 'wp_restore_post_revision', $restore_recorder, 10 );
		}

		$restored_post  = \get_post( $post_id );
		$edit_last      = \get_post_meta( $post_id, '_edit_last', true );
		$revision_title = \wp_post_revision_title( $revision_id, false );
		$autosave_title = \wp_post_revision_title( $autosave_id, false );
		$expanded_title = \wp_post_revision_title_expanded( $revision_id, false );
		$grant_caps     = self::grant_all_caps_filter( $author_id );

		\add_filter( 'user_has_cap', $grant_caps, 10, 4 );
		try {
			ob_start();
			\wp_list_post_revisions( $post_id, 'all' );
			$list_all = ob_get_clean();
			ob_start();
			\wp_list_post_revisions( $post_id, 'autosave' );
			$list_autosaves = ob_get_clean();
		} finally {
			while ( ob_get_level() > 0 && ! isset( $list_autosaves ) ) {
				ob_end_clean();
			}
			\remove_filter( 'user_has_cap', $grant_caps, 10 );
		}

		self::collect_failure(
			$failures,
			null === $restore_non_revision
				&& false === $restore_empty_fields
				&& (int) $post_id === (int) $restore_all
				&& $restored_post instanceof \WP_Post
				&& $case['titleTo'] === $restored_post->post_title
				&& $case['contentTo'] === $restored_post->post_content
				&& $case['excerptTo'] === $restored_post->post_excerpt
				&& (int) $author_id === (int) $edit_last
				&& array( array( 'postId' => (int) $post_id, 'revisionId' => (int) $revision_id ) ) === $events,
			'wp_restore_post_revision rejects non-revisions and empty fields, restores all revisioned fields, and records edit user/action',
			array(
				'restoreNonRevision' => $restore_non_revision,
				'restoreEmptyFields' => $restore_empty_fields,
				'restoreAll'         => $restore_all,
				'restoredPost'       => self::post_summary( $restored_post ),
				'editLast'           => $edit_last,
				'events'             => $events,
			)
		);
		self::collect_failure(
			$failures,
			is_string( $revision_title )
				&& false === strpos( $revision_title, '[Autosave]' )
				&& is_string( $autosave_title )
				&& false !== strpos( $autosave_title, '[Autosave]' )
				&& is_string( $expanded_title )
				&& false !== strpos( $expanded_title, 'Revision Author ' . $case['token'] )
				&& is_string( $list_all )
				&& is_string( $list_autosaves )
				&& 2 === substr_count( $list_all, '<li>' )
				&& 1 === substr_count( $list_autosaves, '<li>' )
				&& false !== strpos( $list_autosaves, '[Autosave]' ),
			'revision title and list helpers distinguish normal revisions and autosaves without admin dispatch',
			array(
				'revisionTitle' => $revision_title,
				'autosaveTitle' => $autosave_title,
				'expandedTitle' => $expanded_title,
				'allItems'      => is_string( $list_all ) ? substr_count( $list_all, '<li>' ) : null,
				'autosaveItems' => is_string( $list_autosaves ) ? substr_count( $list_autosaves, '<li>' ) : null,
			)
		);

		$unsupported_type = substr( \sanitize_key( 'cfnosup_' . $case['token'] ), 0, 20 );
		\register_post_type(
			$unsupported_type,
			array(
				'public'    => true,
				'query_var' => false,
				'rewrite'   => false,
				'supports'  => array( 'title', 'editor' ),
			)
		);
		$unsupported_case = array_merge( $case, array( 'postType' => $unsupported_type ) );
		$unsupported_id   = self::insert_parent_post( $unsupported_case, $author_id, 'unsupported' );
		$manual_revision  = self::insert_revision_row(
			$unsupported_id,
			$author_id,
			array(
				'post_title'        => 'Unsupported revision ' . $case['token'],
				'post_content'      => 'Unsupported revision content',
				'post_excerpt'      => '',
				'post_date'         => $case['dateFrom'],
				'post_date_gmt'     => $case['dateFromGmt'],
				'post_modified'     => $case['dateFrom'],
				'post_modified_gmt' => $case['dateFromGmt'],
			),
			false
		);
		$checked_revisions = \wp_get_post_revisions( $unsupported_id );
		$raw_revisions     = \wp_get_post_revisions(
			$unsupported_id,
			array(
				'check_enabled' => false,
			)
		);
		$unsupported_save  = \wp_save_post_revision( $unsupported_id );
		$auto_draft_id     = \wp_insert_post(
			\wp_slash(
				array(
					'post_author'  => $author_id,
					'post_content' => 'Auto draft content ' . $case['token'],
					'post_status'  => 'auto-draft',
					'post_title'   => 'Auto draft ' . $case['token'],
					'post_type'    => $case['postType'],
				)
			),
			true,
			true
		);
		$auto_draft_save   = is_int( $auto_draft_id ) ? \wp_save_post_revision( $auto_draft_id ) : null;

		self::collect_failure(
			$failures,
			array() === $checked_revisions
				&& isset( $raw_revisions[ $manual_revision ] )
				&& null === $unsupported_save,
			'post type support gates checked revision reads and saves while raw revision queries can opt out',
			array(
				'unsupportedType'    => $unsupported_type,
				'manualRevision'     => $manual_revision,
				'checkedRevisionIds' => array_map( 'intval', array_keys( $checked_revisions ) ),
				'rawRevisionIds'     => array_map( 'intval', array_keys( $raw_revisions ) ),
				'unsupportedSave'    => $unsupported_save,
			)
		);
		self::collect_failure(
			$failures,
			is_int( $auto_draft_id )
				&& null === $auto_draft_save,
			'auto-draft status gates revision saves',
			array(
				'autoDraftId'   => $auto_draft_id,
				'autoDraftSave' => $auto_draft_save,
			)
		);
		return self::result(
			$ctx,
			'revisions-autosaves.support-restore-edges-and-title-helpers',
			$failures,
			array(
				'case'         => self::case_summary( $case ),
				'postId'       => $post_id,
				'revisionId'   => $revision_id,
				'autosaveId'   => $autosave_id,
				'unsupported'  => $unsupported_id,
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

	private static function check_latest_revision_count_and_url_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures       = array();
		$author_id      = self::insert_author( $case, 'latest-count-url' );
		$post_id        = self::insert_parent_post( $case, $author_id, 'latest-count-url' );
		$revision_ids   = array();
		$revision_ids[] = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleFrom'] . ' first revision',
				'post_content'      => $case['contentFrom'] . "\nFirst revision",
				'post_excerpt'      => $case['excerptFrom'],
				'post_date'         => $case['dateFrom'],
				'post_date_gmt'     => $case['dateFromGmt'],
				'post_modified'     => $case['dateFrom'],
				'post_modified_gmt' => $case['dateFromGmt'],
			),
			false
		);
		$revision_ids[] = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'] . ' second revision',
				'post_content'      => $case['contentTo'] . "\nSecond revision",
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateTo'],
				'post_date_gmt'     => $case['dateToGmt'],
				'post_modified'     => $case['dateTo'],
				'post_modified_gmt' => $case['dateToGmt'],
			),
			false
		);
		$revision_ids[] = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'] . ' latest revision',
				'post_content'      => $case['contentTo'] . "\nLatest revision",
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateLater'],
				'post_date_gmt'     => $case['dateLaterGmt'],
				'post_modified'     => $case['dateLater'],
				'post_modified_gmt' => $case['dateLaterGmt'],
			),
			false
		);

		$latest_revision_id = (int) end( $revision_ids );
		$latest_count       = \wp_get_latest_revision_id_and_total_count( $post_id );
		$expected_url       = \admin_url( 'revision.php?revision=' . $latest_revision_id );
		$grant_caps         = self::grant_all_caps_filter( $author_id );

		\wp_set_current_user( $author_id );
		\add_filter( 'user_has_cap', $grant_caps, 10, 4 );
		try {
			$parent_url   = \wp_get_post_revisions_url( $post_id );
			$revision_url = \wp_get_post_revisions_url( $latest_revision_id );
		} finally {
			\remove_filter( 'user_has_cap', $grant_caps, 10 );
		}

		$empty_post_id = self::insert_parent_post( $case, $author_id, 'no-revisions' );
		$empty_count   = \wp_get_latest_revision_id_and_total_count( $empty_post_id );
		$empty_url     = \wp_get_post_revisions_url( $empty_post_id );

		$disabled_type = substr( \sanitize_key( 'cfnorev_' . $case['token'] ), 0, 20 );
		\register_post_type(
			$disabled_type,
			array(
				'public'    => true,
				'query_var' => false,
				'rewrite'   => false,
				'show_ui'   => true,
				'supports'  => array( 'title', 'editor', 'author' ),
			)
		);
		$disabled_case     = array_merge( $case, array( 'postType' => $disabled_type ) );
		$disabled_post_id  = self::insert_parent_post( $disabled_case, $author_id, 'revisions-disabled' );
		$disabled_revision = self::insert_revision_row(
			$disabled_post_id,
			$author_id,
			array(
				'post_title'        => 'Disabled revision ' . $case['token'],
				'post_content'      => 'Disabled revision content ' . $case['token'],
				'post_excerpt'      => '',
				'post_date'         => $case['dateLater'],
				'post_date_gmt'     => $case['dateLaterGmt'],
				'post_modified'     => $case['dateLater'],
				'post_modified_gmt' => $case['dateLaterGmt'],
			),
			false
		);
		$disabled_count    = \wp_get_latest_revision_id_and_total_count( $disabled_post_id );
		$disabled_url      = \wp_get_post_revisions_url( $disabled_post_id );

		self::collect_failure(
			$failures,
			is_array( $latest_count )
				&& $latest_revision_id === (int) ( $latest_count['latest_id'] ?? 0 )
				&& count( $revision_ids ) === (int) ( $latest_count['count'] ?? -1 ),
			'wp_get_latest_revision_id_and_total_count returns the newest revision ID and total revision count',
			array(
				'postId'           => $post_id,
				'revisionIds'      => array_map( 'intval', $revision_ids ),
				'latestRevisionId' => $latest_revision_id,
				'latestCount'      => $latest_count,
			)
		);
		self::collect_failure(
			$failures,
			$expected_url === $parent_url
				&& $expected_url === $revision_url,
			'wp_get_post_revisions_url returns the latest revision edit link and returns a revision edit link early for revision input',
			array(
				'expectedUrl' => $expected_url,
				'parentUrl'   => $parent_url,
				'revisionUrl' => $revision_url,
			)
		);
		self::collect_failure(
			$failures,
			is_array( $empty_count )
				&& 0 === (int) ( $empty_count['latest_id'] ?? -1 )
				&& 0 === (int) ( $empty_count['count'] ?? -1 )
				&& null === $empty_url,
			'posts without revisions report a zero latest revision ID/count and no revisions URL',
			array(
				'emptyPostId' => $empty_post_id,
				'emptyCount'  => $empty_count,
				'emptyUrl'    => $empty_url,
			)
		);
		self::collect_failure(
			$failures,
			\is_wp_error( $disabled_count )
				&& 'revisions_not_enabled' === $disabled_count->get_error_code()
				&& null === $disabled_url,
			'revisions-disabled post types return a revisions_not_enabled error and no revisions URL even when revision children exist',
			array(
				'disabledPostId'  => $disabled_post_id,
				'disabledType'    => $disabled_type,
				'manualRevision'  => $disabled_revision,
				'disabledCount'   => self::error_summary( $disabled_count ),
				'disabledUrl'     => $disabled_url,
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.latest-count-url',
			$failures,
			array(
				'case'             => self::case_summary( $case ),
				'postId'           => $post_id,
				'revisionIds'      => array_map( 'intval', $revision_ids ),
				'latestRevisionId' => $latest_revision_id,
				'emptyPostId'      => $empty_post_id,
				'disabledPostId'   => $disabled_post_id,
			)
		);
	}

	private static function check_user_filtered_autosave_lookup( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures       = array();
		$author_id      = self::insert_author( $case, 'autosave-match' );
		$other_user_id  = self::insert_author( $case, 'autosave-other-match' );
		$wrong_user_id  = self::insert_author( $case, 'autosave-wrong-user' );
		$post_id        = self::insert_parent_post( $case, $author_id, 'user-filtered-autosave' );
		$matching_id    = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'] . ' matching autosave',
				'post_content'      => $case['contentTo'] . "\nMatching autosave",
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateTo'],
				'post_date_gmt'     => $case['dateToGmt'],
				'post_modified'     => $case['dateTo'],
				'post_modified_gmt' => $case['dateToGmt'],
			),
			true
		);
		$other_id       = self::insert_revision_row(
			$post_id,
			$other_user_id,
			array(
				'post_title'        => $case['titleTo'] . ' other autosave',
				'post_content'      => $case['contentTo'] . "\nOther autosave",
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateLater'],
				'post_date_gmt'     => $case['dateLaterGmt'],
				'post_modified'     => $case['dateLater'],
				'post_modified_gmt' => $case['dateLaterGmt'],
			),
			true
		);
		$user_autosave  = \wp_get_post_autosave( $post_id, $author_id );
		$other_autosave = \wp_get_post_autosave( $post_id, $other_user_id );
		$wrong_autosave = \wp_get_post_autosave( $post_id, $wrong_user_id );

		self::collect_failure(
			$failures,
			$user_autosave instanceof \WP_Post
				&& $matching_id === (int) $user_autosave->ID
				&& $author_id === (int) $user_autosave->post_author
				&& "{$post_id}-autosave-v1" === $user_autosave->post_name,
			'wp_get_post_autosave($post_id, $user_id) selects the autosave authored by the requested user',
			array(
				'postId'       => $post_id,
				'matchingId'   => $matching_id,
				'otherId'      => $other_id,
				'userAutosave' => self::post_summary( $user_autosave ),
			)
		);
		self::collect_failure(
			$failures,
			$other_autosave instanceof \WP_Post
				&& $other_id === (int) $other_autosave->ID
				&& $other_user_id === (int) $other_autosave->post_author
				&& false === $wrong_autosave,
			'user-filtered autosave lookup does not fall back to another author when the requested user has no autosave',
			array(
				'otherAutosave' => self::post_summary( $other_autosave ),
				'wrongUserId'   => $wrong_user_id,
				'wrongAutosave' => $wrong_autosave,
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.user-filtered-autosave',
			$failures,
			array(
				'case'          => self::case_summary( $case ),
				'postId'        => $post_id,
				'matchingId'    => $matching_id,
				'otherId'       => $other_id,
				'wrongUserId'   => $wrong_user_id,
			)
		);
	}

	private static function check_revision_template_output( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures  = array();
		$author_id = self::insert_author( $case, 'revision-templates' );
		$post_id   = self::insert_parent_post( $case, $author_id, 'revision-templates' );
		$post      = \get_post( $post_id );

		$GLOBALS['post'] = $post;
		\wp_set_current_user( 0 );
		$unlocked_output = self::capture_revision_templates();

		\add_post_meta( $post_id, '_edit_lock', time() . ':' . $author_id, true );
		$GLOBALS['post'] = \get_post( $post_id );
		$locked_output   = self::capture_revision_templates();

		$template_ids = self::revision_template_ids( $unlocked_output );

		self::collect_failure(
			$failures,
			$post instanceof \WP_Post
				&& array(
					'tmpl-revisions-frame',
					'tmpl-revisions-buttons',
					'tmpl-revisions-slider-hidden-help',
					'tmpl-revisions-checkbox',
					'tmpl-revisions-meta',
					'tmpl-revisions-diff',
				) === $template_ids
				&& 6 === substr_count( $unlocked_output, 'type="text/html"' )
				&& self::revision_template_order_ok( $unlocked_output ),
			'wp_print_revision_templates() emits the expected revision template script set in stable order',
			array(
				'postId'      => $post_id,
				'templateIds' => $template_ids,
				'output'      => self::describe_output( $unlocked_output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $unlocked_output, 'class="revisions-control-frame"' )
				&& str_contains( $unlocked_output, 'class="revisions-diff-frame"' )
				&& str_contains( $unlocked_output, 'class="button button-compact" type="button" value="Previous"' )
				&& str_contains( $unlocked_output, 'class="button button-compact" type="button" value="Next"' )
				&& str_contains( $unlocked_output, 'Compare any two revisions' )
				&& str_contains( $unlocked_output, 'Change revision by using the left and right arrow keys' )
				&& str_contains( $unlocked_output, 'Restore This Autosave' )
				&& str_contains( $unlocked_output, 'Restore This Revision' ),
			'wp_print_revision_templates() includes controls, accessibility help, compare mode, and restore-button labels',
			array( 'output' => self::describe_output( $unlocked_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $unlocked_output, '<# if ( data.attributes.current ) { #>' )
				&& 1 === substr_count( $unlocked_output, 'disabled="disabled"' )
				&& false !== \wp_check_post_lock( $post_id )
				&& ! str_contains( $locked_output, '<# if ( data.attributes.current ) { #>' )
				&& 1 === substr_count( $locked_output, 'disabled="disabled"' )
				&& strlen( $locked_output ) < strlen( $unlocked_output ),
			'wp_print_revision_templates() switches the restore-button disabled branch when wp_check_post_lock() reports a lock',
			array(
				'postId'         => $post_id,
				'lockUser'       => \wp_check_post_lock( $post_id ),
				'unlockedOutput' => self::describe_output( $unlocked_output ),
				'lockedOutput'   => self::describe_output( $locked_output ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $unlocked_output, '{{{ data.attributes.author.avatar }}}' )
				&& str_contains( $unlocked_output, '{{ data.attributes.author.name }}' )
				&& str_contains( $unlocked_output, '{{ data.attributes.timeAgo }}' )
				&& str_contains( $unlocked_output, '{{ data.attributes.dateShort }}' )
				&& str_contains( $unlocked_output, '<# _.each( data.fields, function( field ) { #>' )
				&& str_contains( $unlocked_output, '{{ field.name }}' )
				&& str_contains( $unlocked_output, '{{{ field.diff }}}' )
				&& self::strings_absent(
					$unlocked_output . $locked_output,
					array(
						$case['token'],
						$case['titleFrom'],
						$case['titleTo'],
						$case['contentFrom'],
						$case['contentTo'],
						$case['excerptFrom'],
						$case['excerptTo'],
					)
				)
				&& ! str_contains( strtolower( $unlocked_output ), '<script>alert' )
				&& ! str_contains( strtolower( $locked_output ), '<script>alert' ),
			'wp_print_revision_templates() preserves expected underscore interpolation markers without leaking generated post content',
			array(
				'absentNeedles' => array_filter(
					array(
						$case['token'],
						$case['titleFrom'],
						$case['titleTo'],
						$case['contentFrom'],
						$case['contentTo'],
						$case['excerptFrom'],
						$case['excerptTo'],
					),
					static fn( string $value ): bool => '' !== $value
				),
				'output'        => self::describe_output( $unlocked_output ),
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.revision-template-output',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'postId'     => $post_id,
				'templateIds' => $template_ids,
				'notCovered'  => array( '_show_post_preview request dispatch' ),
			)
		);
	}

	private static function autosave_post_data( int $post_id, array $case, string $title, string $content, string $excerpt ): array {
		return array(
			'comment_status' => 'closed',
			'content'        => $content,
			'excerpt'        => $excerpt,
			'ping_status'    => 'closed',
			'post_ID'        => $post_id,
			'post_status'    => 'draft',
			'post_title'     => $title,
			'post_type'      => $case['postType'],
		);
	}

	private static function grant_all_caps_filter( int $user_id ): \Closure {
		return static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $user_id ): array {
			unset( $args );

			if ( (int) $user->ID !== (int) $user_id ) {
				return $allcaps;
			}

			foreach ( array( 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'edit_published_posts', 'edit_private_posts' ) as $cap ) {
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
		$GLOBALS['wp_query']         = new \WP_Query();
		$GLOBALS['wp_the_query']     = $GLOBALS['wp_query'];

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
			'get'      => $_GET,
			'globals'  => $globals,
			'options'  => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
			'post'     => $_POST,
			'request'  => $_REQUEST,
			'server'   => $server,
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
		$_REQUEST = $snapshot['request'];
	}

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot, array $case ): array {
		$wpdb      = $GLOBALS['wpdb'] ?? null;
		$counts    = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_content_counts() : array();
		$options   = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_get_options() : array();
		$count_ok  = array() === array_filter( $counts );
		$filter_ok = false === \has_filter( '_wp_post_revision_field_post_content' )
			&& false === \has_filter( '_wp_post_revision_fields' )
			&& false === \has_filter( 'revision_text_diff_options' )
			&& false === \has_filter( 'user_has_cap' )
			&& false === \has_filter( 'wp_check_post_lock_window' )
			&& false === \has_filter( 'wp_creating_autosave' )
			&& false === \has_filter( 'wp_restore_post_revision' )
			&& false === \has_filter( 'wp_revisions_to_keep' )
			&& false === \has_filter( 'wp_save_post_revision_revisions_before_deletion' )
			&& false === \has_filter( 'wp_delete_post_revision' )
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
			'lockWindow'           => $ctx->int( 45, 240 ),
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

	private static function offset_mysql_date( string $mysql, int $minutes ): string {
		$timestamp = strtotime( $mysql );
		if ( false === $timestamp ) {
			return $mysql;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp + ( $minutes * MINUTE_IN_SECONDS ) );
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

	private static function capture_revision_templates(): string {
		$level = ob_get_level();
		ob_start();
		try {
			\wp_print_revision_templates();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			throw $e;
		}
	}

	private static function revision_template_ids( string $output ): array {
		if ( ! preg_match_all( '/<script\\s+id="([^"]+)"\\s+type="text\\/html">/', $output, $matches ) ) {
			return array();
		}

		return $matches[1];
	}

	private static function revision_template_order_ok( string $output ): bool {
		$offset = 0;
		foreach (
			array(
				'tmpl-revisions-frame',
				'tmpl-revisions-buttons',
				'tmpl-revisions-slider-hidden-help',
				'tmpl-revisions-checkbox',
				'tmpl-revisions-meta',
				'tmpl-revisions-diff',
			) as $template_id
		) {
			$position = strpos( $output, 'id="' . $template_id . '"', $offset );
			if ( false === $position ) {
				return false;
			}
			$offset = $position + strlen( $template_id );
		}

		return true;
	}

	private static function strings_absent( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			$needle = (string) $needle;
			if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	private static function describe_output( string $output ): array {
		return array(
			'bytes'   => strlen( $output ),
			'sha1'    => sha1( $output ),
			'preview' => substr( $output, 0, 220 ),
		);
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
			'lockWindow'           => $case['lockWindow'],
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

	private static function lock_summary( $lock ) {
		if ( ! is_array( $lock ) ) {
			return $lock;
		}

		return array(
			'hasTimestamp' => isset( $lock[0] ) && is_int( $lock[0] ),
			'userId'       => isset( $lock[1] ) ? (int) $lock[1] : null,
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
