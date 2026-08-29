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
			$rows[] = self::check_preview_request_dispatch( $ctx->fork( 'preview-dispatch' ), $case );
			$rows[] = self::check_rest_revision_autosave_route_dispatch( $ctx->fork( 'rest-dispatch' ), $case );
			$rows[] = self::check_rest_builtin_post_page_revision_autosave_parity( $ctx->fork( 'rest-builtin-post-page' ), $case );
			$rows[] = self::check_rest_autosave_mutation_and_revision_meta_projection( $ctx->fork( 'rest-autosave-meta' ), $case );
			$rows[] = self::check_rest_autosave_negative_write_and_malformed_meta_boundaries( $ctx->fork( 'rest-autosave-negative' ), $case );
			$rows[] = self::check_rest_parent_meta_validation_contrast( $ctx->fork( 'rest-parent-meta-contrast' ), $case );
			$rows[] = self::check_rest_revision_autosave_batch_gates( $ctx->fork( 'rest-batch-gates' ), $case );
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
		self::load_rest_endpoint_classes();

		$missing = array();

		foreach ( array( 'Component_Fuzz_WPDB_Stub', 'WP_Error', 'WP_Post', 'WP_Query', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}
		foreach ( array( 'WP_REST_Autosaves_Controller', 'WP_REST_Post_Meta_Fields', 'WP_REST_Posts_Controller', 'WP_REST_Request', 'WP_REST_Response', 'WP_REST_Revisions_Controller', 'WP_REST_Server' ) as $class ) {
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
				'_show_post_preview',
				'_wp_copy_post_meta',
				'_wp_post_revision_data',
				'_wp_post_revision_fields',
				'_wp_put_post_revision',
				'add_action',
				'add_filter',
				'add_post_meta',
				'apply_filters',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'delete_post_meta',
				'esc_attr_e',
				'esc_attr_x',
				'esc_html_e',
				'get_post',
				'get_metadata_raw',
				'get_post_meta',
				'get_edit_post_link',
				'get_userdata',
				'has_filter',
				'is_wp_error',
				'metadata_exists',
				'post_type_exists',
				'register_post_meta',
				'register_post_type',
				'register_rest_route',
				'remove_action',
				'remove_filter',
				'rest_authorization_required_code',
				'rest_ensure_response',
				'rest_get_route_for_post',
				'rest_url',
				'sanitize_key',
				'sanitize_title',
				'unregister_meta_key',
				'update_post_meta',
				'wp_cache_flush',
				'wp_check_invalid_utf8',
				'wp_check_post_lock',
				'wp_check_revisioned_meta_fields_have_changed',
				'wp_create_nonce',
				'wp_create_post_autosave',
				'wp_delete_post_revision',
				'wp_die',
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
				'wp_verify_nonce',
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

	private static function load_rest_endpoint_classes(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach (
			array(
				'wp-includes/rest-api/fields/class-wp-rest-meta-fields.php',
				'wp-includes/rest-api/fields/class-wp-rest-post-meta-fields.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-revisions-controller.php',
				'wp-includes/rest-api/endpoints/class-wp-rest-autosaves-controller.php',
			) as $file
		) {
			$path = ABSPATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
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

	private static function check_preview_request_dispatch( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		self::register_case_post_type( $case, true );

		$failures  = array();
		$author_id = self::insert_author( $case, 'preview-dispatch' );
		$post_id   = self::insert_parent_post( $case, $author_id, 'preview-dispatch' );
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

		\wp_set_current_user( $author_id );

		$action      = 'post_preview_' . $post_id;
		$valid_nonce = \wp_create_nonce( $action );

		$verify_failures       = array();
		$admin_referer_events  = array();
		$failure_recorder      = static function ( $nonce, $nonce_action, $user, $token ) use ( &$verify_failures ): void {
			$verify_failures[] = array(
				'action' => $nonce_action,
				'nonce'  => $nonce,
				'token'  => $token,
				'userId' => is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : null,
			);
		};
		$admin_referer_recorder = static function ( $referer_action, $result ) use ( &$admin_referer_events ): void {
			$admin_referer_events[] = array(
				'action' => $referer_action,
				'result' => $result,
			);
		};

		\add_action( 'wp_verify_nonce_failed', $failure_recorder, 10, 4 );
		\add_action( 'check_admin_referer', $admin_referer_recorder, 10, 2 );

		$missing_id_capture    = self::capture_preview_dispatch( array( 'preview_nonce' => $valid_nonce ) );
		$missing_nonce_capture = self::capture_preview_dispatch( array( 'preview_id' => (string) $post_id ) );
		$missing_leaked_filter = false !== \has_filter( 'the_preview', '_set_preview' );

		self::collect_failure(
			$failures,
			$missing_id_capture['returned']
				&& $missing_nonce_capture['returned']
				&& ! $missing_id_capture['captured']
				&& ! $missing_nonce_capture['captured']
				&& null === $missing_id_capture['throwable']
				&& null === $missing_nonce_capture['throwable']
				&& ! $missing_leaked_filter
				&& array() === $verify_failures
				&& array() === $admin_referer_events,
			'_show_post_preview is a no-op unless preview_id and preview_nonce are both present',
			array(
				'adminRefererEvents' => $admin_referer_events,
				'filterLeaked'       => $missing_leaked_filter,
				'missingId'          => $missing_id_capture,
				'missingNonce'       => $missing_nonce_capture,
				'verifyFailures'     => $verify_failures,
			)
		);

		$valid_get       = array(
			'preview_id'    => (string) $post_id,
			'preview_nonce' => $valid_nonce,
		);
		$valid_capture   = self::capture_preview_dispatch( $valid_get );
		$repeat_capture  = self::capture_preview_dispatch( $valid_get );
		$preview_filter  = \has_filter( 'the_preview', '_set_preview' );
		$previewed       = \apply_filters( 'the_preview', \get_post( $post_id ) );
		$terms_added     = false !== \has_filter( 'get_the_terms', '_wp_preview_terms_filter' );
		$thumbnail_added = false !== \has_filter( 'get_post_metadata', '_wp_preview_post_thumbnail_filter' );
		$meta_added      = false !== \has_filter( 'get_post_metadata', '_wp_preview_meta_filter' );

		\remove_filter( 'the_preview', '_set_preview', 10 );
		\remove_filter( 'get_the_terms', '_wp_preview_terms_filter', 10 );
		\remove_filter( 'get_post_metadata', '_wp_preview_post_thumbnail_filter', 10 );
		\remove_filter( 'get_post_metadata', '_wp_preview_meta_filter', 10 );
		$valid_filters_removed = false === \has_filter( 'the_preview', '_set_preview' )
			&& false === \has_filter( 'get_the_terms', '_wp_preview_terms_filter' )
			&& false === \has_filter( 'get_post_metadata', '_wp_preview_post_thumbnail_filter' )
			&& false === \has_filter( 'get_post_metadata', '_wp_preview_meta_filter' );

		self::collect_failure(
			$failures,
			is_int( $autosave )
				&& $valid_capture['returned']
				&& $repeat_capture['returned']
				&& ! $valid_capture['captured']
				&& ! $repeat_capture['captured']
				&& null === $valid_capture['throwable']
				&& null === $repeat_capture['throwable']
				&& 10 === $preview_filter
				&& $previewed instanceof \WP_Post
				&& $case['titleTo'] === $previewed->post_title
				&& $case['contentTo'] === $previewed->post_content
				&& $case['excerptTo'] === $previewed->post_excerpt
				&& $terms_added
				&& $thumbnail_added
				&& $meta_added
				&& $valid_filters_removed,
			'valid _show_post_preview request installs one preview filter and overlays the latest autosave',
			array(
				'postId'              => $post_id,
				'autosaveId'          => $autosave,
				'validCapture'        => $valid_capture,
				'repeatCapture'       => $repeat_capture,
				'previewFilter'       => $preview_filter,
				'previewed'           => self::post_summary( $previewed ),
				'termsFilterAdded'    => $terms_added,
				'thumbnailFilterAdded' => $thumbnail_added,
				'metaFilterAdded'     => $meta_added,
				'filtersRemoved'      => $valid_filters_removed,
			)
		);

		$casted_capture        = self::capture_preview_dispatch(
			array(
				'preview_id'    => $post_id . '-junk',
				'preview_nonce' => $valid_nonce,
			)
		);
		$casted_preview_filter = \has_filter( 'the_preview', '_set_preview' );
		\remove_filter( 'the_preview', '_set_preview', 10 );
		$casted_filter_removed = false === \has_filter( 'the_preview', '_set_preview' );

		self::collect_failure(
			$failures,
			$casted_capture['returned']
				&& ! $casted_capture['captured']
				&& null === $casted_capture['throwable']
				&& 10 === $casted_preview_filter
				&& $casted_filter_removed
				&& array() === $verify_failures
				&& array() === $admin_referer_events,
			'_show_post_preview casts preview_id to an integer before building the nonce action',
			array(
				'adminRefererEvents' => $admin_referer_events,
				'castedCapture'      => $casted_capture,
				'filterRemoved'      => $casted_filter_removed,
				'previewFilter'      => $casted_preview_filter,
				'verifyFailures'     => $verify_failures,
			)
		);

		$invalid_nonce = 'invalid-preview-' . $case['token'];
		try {
			$invalid_capture = self::capture_preview_dispatch(
				array(
					'preview_id'    => (string) $post_id,
					'preview_nonce' => $invalid_nonce,
				)
			);
		} finally {
			\remove_action( 'wp_verify_nonce_failed', $failure_recorder, 10 );
			\remove_action( 'check_admin_referer', $admin_referer_recorder, 10 );
		}

		$invalid_die      = $invalid_capture['die'];
		$invalid_args     = is_array( $invalid_die['args'] ?? null ) ? $invalid_die['args'] : array();
		$invalid_clean    = false === \has_filter( 'the_preview', '_set_preview' )
			&& false === \has_filter( 'wp_verify_nonce_failed', $failure_recorder )
			&& false === \has_filter( 'check_admin_referer', $admin_referer_recorder );
		$failure_recorded = 1 === count( $verify_failures )
			&& $invalid_nonce === ( $verify_failures[0]['nonce'] ?? null )
			&& $action === ( $verify_failures[0]['action'] ?? null )
			&& (int) $author_id === (int) ( $verify_failures[0]['userId'] ?? 0 );

		self::collect_failure(
			$failures,
			$invalid_capture['captured']
				&& ! $invalid_capture['returned']
				&& null === $invalid_capture['throwable']
				&& 403 === (int) ( $invalid_args['response'] ?? 0 )
				&& is_string( $invalid_die['message'] ?? null )
				&& str_contains( $invalid_die['message'], 'preview drafts' )
				&& $failure_recorded
				&& array() === $admin_referer_events
				&& $invalid_capture['filtersRestored']
				&& $invalid_capture['bufferBalanced']
				&& $invalid_clean,
			'invalid _show_post_preview nonce fails closed through wp_die without installing preview filters',
			array(
				'adminRefererEvents' => $admin_referer_events,
				'filtersClean'       => $invalid_clean,
				'invalidCapture'     => $invalid_capture,
				'verifyFailures'     => $verify_failures,
			)
		);

		\wp_set_current_user( 0 );

		return self::result(
			$ctx,
			'revisions-autosaves.preview-request-dispatch',
			$failures,
			array(
				'case'       => self::case_summary( $case ),
				'postId'     => $post_id,
				'autosaveId' => $autosave,
				'userId'     => $author_id,
			)
		);
	}

	private static function check_rest_revision_autosave_route_dispatch( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		$rest_base = self::register_rest_case_post_type( $case );
		$server    = self::fresh_rest_server();

		$post_type = \get_post_type_object( $case['postType'] );
		if ( ! $post_type ) {
			return self::result(
				$ctx,
				'revisions-autosaves.rest-revision-autosave-route-dispatch',
				array(
					array(
						'label'   => 'REST case post type is registered',
						'details' => array( 'postType' => $case['postType'] ),
					),
				),
				array( 'case' => self::case_summary( $case ) )
			);
		}

		$parent_controller   = $post_type->get_rest_controller();
		$revision_controller = $post_type->get_revisions_rest_controller();
		$autosave_controller = $post_type->get_autosave_rest_controller();
		if ( $parent_controller ) {
			$parent_controller->register_routes();
		}
		if ( $revision_controller ) {
			$revision_controller->register_routes();
		}
		if ( $autosave_controller ) {
			$autosave_controller->register_routes();
		}

		$failures        = array();
		$author_id       = self::insert_author( $case, 'rest-dispatch-author' );
		$editor_id       = self::insert_author( $case, 'rest-dispatch-editor' );
		$post_id         = self::insert_parent_post( $case, $author_id, 'rest-dispatch-parent' );
		$other_parent_id = self::insert_parent_post( $case, $author_id, 'rest-dispatch-other-parent' );
		$old_revision_id = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleFrom'] . ' REST old revision',
				'post_content'      => $case['contentFrom'] . "\nREST old revision",
				'post_excerpt'      => $case['excerptFrom'],
				'post_date'         => $case['dateFrom'],
				'post_date_gmt'     => $case['dateFromGmt'],
				'post_modified'     => $case['dateFrom'],
				'post_modified_gmt' => $case['dateFromGmt'],
			),
			false
		);
		$new_revision_id = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'] . ' REST latest revision',
				'post_content'      => $case['contentTo'] . "\nREST latest revision",
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateTo'],
				'post_date_gmt'     => $case['dateToGmt'],
				'post_modified'     => $case['dateTo'],
				'post_modified_gmt' => $case['dateToGmt'],
			),
			false
		);
		$wrong_parent_revision_id = self::insert_revision_row(
			$other_parent_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'] . ' REST other parent revision',
				'post_content'      => $case['contentTo'] . "\nREST other parent revision",
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateLater'],
				'post_date_gmt'     => $case['dateLaterGmt'],
				'post_modified'     => $case['dateLater'],
				'post_modified_gmt' => $case['dateLaterGmt'],
			),
			false
		);

		$revision_route = '/wp/v2/' . $rest_base . '/' . $post_id . '/revisions';
		$autosave_route = '/wp/v2/' . $rest_base . '/' . $post_id . '/autosaves';
		$grant_caps     = self::grant_all_caps_filter( $editor_id );
		$query_calls    = array();
		$prepare_revision_calls = array();
		$prepare_autosave_calls = array();
		$delete_calls   = array();
		$delete_revision_cap_filter = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $editor_id ): array {
			if ( 'delete_post' !== $cap || (int) $editor_id !== (int) $user_id || empty( $args[0] ) ) {
				return $caps;
			}

			$post = \get_post( (int) $args[0] );
			if ( $post instanceof \WP_Post && 'revision' === $post->post_type ) {
				return array( 'delete_posts' );
			}

			return $caps;
		};
		$query_filter   = static function ( array $args, \WP_REST_Request $request ) use ( &$query_calls ): array {
			$query_calls[] = array(
				'route'       => $request->get_route(),
				'method'      => $request->get_method(),
				'postParent'  => $args['post_parent'] ?? null,
				'perPage'     => $args['posts_per_page'] ?? null,
				'order'       => $args['order'] ?? null,
				'orderBy'     => $args['orderby'] ?? null,
				'suppress'    => $args['suppress_filters'] ?? null,
			);
			return $args;
		};
		$revision_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_revision_calls ): \WP_REST_Response {
			$prepare_revision_calls[] = array(
				'id'      => (int) $post->ID,
				'parent'  => (int) $post->post_parent,
				'route'   => $request->get_route(),
				'method'  => $request->get_method(),
				'context' => $request['context'],
				'fields'  => $request['_fields'],
			);
			return $response;
		};
		$autosave_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_autosave_calls ): \WP_REST_Response {
			$prepare_autosave_calls[] = array(
				'id'      => (int) $post->ID,
				'parent'  => (int) $post->post_parent,
				'route'   => $request->get_route(),
				'method'  => $request->get_method(),
				'context' => $request['context'],
				'fields'  => $request['_fields'],
			);
			return $response;
		};
		$delete_action   = static function ( $result, \WP_REST_Request $request ) use ( &$delete_calls ): void {
			$delete_calls[] = array(
				'id'       => (int) $request['id'],
				'parent'   => (int) $request['parent'],
				'force'    => (bool) $request['force'],
				'deleted'  => $result instanceof \WP_Post ? (int) $result->ID : null,
				'postType' => $result instanceof \WP_Post ? $result->post_type : null,
			);
		};

		\add_filter( 'rest_revision_query', $query_filter, 10, 2 );
		\add_filter( 'rest_prepare_revision', $revision_filter, 10, 3 );
		\add_filter( 'rest_prepare_autosave', $autosave_filter, 10, 3 );
		\add_action( 'rest_delete_revision', $delete_action, 10, 2 );

		try {
			\wp_set_current_user( 0 );
			$denied_collection = self::dispatch( $server, self::request( 'GET', $revision_route ) );

			\wp_set_current_user( $editor_id );
			\add_filter( 'user_has_cap', $grant_caps, 10, 4 );
			\add_filter( 'map_meta_cap', $delete_revision_cap_filter, 10, 4 );

			$invalid_parent = self::dispatch( $server, self::request( 'GET', '/wp/v2/' . $rest_base . '/0/revisions' ) );
			$invalid_order  = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_route,
					array(
						'orderby' => 'relevance',
					)
				)
			);
			$collection     = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_route,
					array(
						'context'  => 'edit',
						'_fields'  => 'id,parent,slug,title.raw,content.raw,_links',
						'orderby'  => 'date',
						'order'    => 'desc',
						'per_page' => 2,
					)
				)
			);
			$collection_data    = $collection instanceof \WP_REST_Response ? $collection->get_data() : array();
			$collection_headers = $collection instanceof \WP_REST_Response ? $collection->get_headers() : array();

			$mismatch_item = self::dispatch( $server, self::request( 'GET', $revision_route . '/' . $wrong_parent_revision_id ) );
			$valid_item    = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_route . '/' . $new_revision_id,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,content.raw,_links',
					)
				)
			);
			$valid_item_data  = $valid_item instanceof \WP_REST_Response ? $valid_item->get_data() : array();
			$valid_item_links = $valid_item instanceof \WP_REST_Response ? $valid_item->get_links() : array();

			$delete_without_force = self::dispatch( $server, self::request( 'DELETE', $revision_route . '/' . $old_revision_id ) );
			$after_soft_delete    = \get_post( $old_revision_id );
			$delete_with_force    = self::dispatch(
				$server,
				self::request(
					'DELETE',
					$revision_route . '/' . $old_revision_id,
					array(
						'force'   => true,
						'context' => 'edit',
					)
				)
			);
			$delete_data          = $delete_with_force instanceof \WP_REST_Response ? $delete_with_force->get_data() : array();
			$after_force_delete   = \get_post( $old_revision_id );
			$missing_deleted      = self::dispatch( $server, self::request( 'GET', $revision_route . '/' . $old_revision_id ) );

			$invalid_autosave_parent = self::dispatch( $server, self::request( 'GET', '/wp/v2/' . $rest_base . '/0/autosaves' ) );
			$autosave_id             = self::insert_revision_row(
				$post_id,
				$editor_id,
				array(
					'post_title'        => $case['titleTo'] . ' REST autosave',
					'post_content'      => $case['contentTo'] . "\nREST autosave body",
					'post_excerpt'      => $case['excerptTo'] . ' REST autosave',
					'post_date'         => $case['dateLater'],
					'post_date_gmt'     => $case['dateLaterGmt'],
					'post_modified'     => $case['dateLater'],
					'post_modified_gmt' => $case['dateLaterGmt'],
				),
				true
			);
			$autosave_post           = \get_post( $autosave_id );

			$autosave_collection = self::dispatch(
				$server,
				self::request(
					'GET',
					$autosave_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,preview_link',
					)
				)
			);
			$autosave_collection_data = $autosave_collection instanceof \WP_REST_Response ? $autosave_collection->get_data() : array();
			$autosave_item            = self::dispatch(
				$server,
				self::request(
					'GET',
					$autosave_route . '/' . $autosave_id,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,preview_link',
					)
				)
			);
			$autosave_item_data = $autosave_item instanceof \WP_REST_Response ? $autosave_item->get_data() : array();
		} finally {
			\remove_action( 'rest_delete_revision', $delete_action, 10 );
			\remove_filter( 'rest_prepare_autosave', $autosave_filter, 10 );
			\remove_filter( 'rest_prepare_revision', $revision_filter, 10 );
			\remove_filter( 'rest_revision_query', $query_filter, 10 );
			\remove_filter( 'map_meta_cap', $delete_revision_cap_filter, 10 );
			\remove_filter( 'user_has_cap', $grant_caps, 10 );
			\wp_set_current_user( 0 );
		}

		self::collect_failure(
			$failures,
			self::response_error_ok( $denied_collection, 'rest_cannot_read', \rest_authorization_required_code() ),
			'REST revisions collection denies anonymous users before returning revision rows',
			array( 'response' => self::response_summary( $denied_collection ) )
		);
		self::collect_failure(
			$failures,
			self::response_error_ok( $invalid_parent, 'rest_post_invalid_parent', 404 )
				&& self::response_error_ok( $invalid_order, 'rest_no_search_term_defined', 400 ),
			'REST revisions route fails closed for invalid parents and relevance ordering without a search term',
			array(
				'invalidParent' => self::response_summary( $invalid_parent ),
				'invalidOrder'  => self::response_summary( $invalid_order ),
			)
		);
		self::collect_failure(
			$failures,
			$collection instanceof \WP_REST_Response
				&& 200 === $collection->get_status()
				&& is_array( $collection_data )
				&& 2 === count( $collection_data )
				&& array( $new_revision_id, $old_revision_id ) === array_map( 'intval', array_column( $collection_data, 'id' ) )
				&& (int) $post_id === (int) ( $collection_data[0]['parent'] ?? 0 )
				&& $case['titleTo'] . ' REST latest revision' === ( $collection_data[0]['title']['raw'] ?? null )
				&& $case['contentTo'] . "\nREST latest revision" === ( $collection_data[0]['content']['raw'] ?? null )
				&& 2 === (int) ( $collection_headers['X-WP-Total'] ?? 0 )
				&& 1 === (int) ( $collection_headers['X-WP-TotalPages'] ?? 0 )
				&& 1 === count( $query_calls )
				&& (int) $post_id === (int) ( $query_calls[0]['postParent'] ?? 0 )
				&& true === (bool) ( $query_calls[0]['suppress'] ?? false ),
			'REST revisions collection dispatch returns only the parent revisions in date order with pagination headers and query filter payloads',
			array(
				'collection' => self::response_summary( $collection ),
				'headers'    => $collection_headers,
				'queryCalls' => $query_calls,
			)
		);
		self::collect_failure(
			$failures,
			self::response_error_ok( $mismatch_item, 'rest_revision_parent_id_mismatch', 404 )
				&& $valid_item instanceof \WP_REST_Response
				&& 200 === $valid_item->get_status()
				&& $new_revision_id === (int) ( $valid_item_data['id'] ?? 0 )
				&& (int) $post_id === (int) ( $valid_item_data['parent'] ?? 0 )
				&& $case['titleTo'] . ' REST latest revision' === ( $valid_item_data['title']['raw'] ?? null )
				&& isset( $valid_item_links['parent'][0]['href'] )
				&& str_contains( (string) $valid_item_links['parent'][0]['href'], '/' . $rest_base . '/' . $post_id ),
			'REST revision item dispatch enforces parent matching and projects requested raw fields and parent link',
			array(
				'mismatch' => self::response_summary( $mismatch_item ),
				'item'     => self::response_summary( $valid_item ),
				'links'    => $valid_item_links,
			)
		);
		self::collect_failure(
			$failures,
			self::response_error_ok( $delete_without_force, 'rest_trash_not_supported', 501 )
				&& $after_soft_delete instanceof \WP_Post
				&& $delete_with_force instanceof \WP_REST_Response
				&& 200 === $delete_with_force->get_status()
				&& true === ( $delete_data['deleted'] ?? null )
				&& $old_revision_id === (int) ( $delete_data['previous']['id'] ?? 0 )
				&& null === $after_force_delete
				&& self::response_error_ok( $missing_deleted, 'rest_post_invalid_id', 404 )
				&& 1 === count( $delete_calls )
				&& $old_revision_id === (int) ( $delete_calls[0]['id'] ?? 0 )
				&& true === ( $delete_calls[0]['force'] ?? false ),
			'REST revision delete refuses trashing, force-deletes the revision, returns previous data, and fires delete hooks',
			array(
				'withoutForce' => self::response_summary( $delete_without_force ),
				'withForce'    => self::response_summary( $delete_with_force ),
				'deleteCalls'  => $delete_calls,
				'missing'      => self::response_summary( $missing_deleted ),
			)
		);
		self::collect_failure(
			$failures,
			self::response_error_ok( $invalid_autosave_parent, 'rest_post_invalid_parent', 404 )
				&& $autosave_post instanceof \WP_Post
				&& $post_id === (int) $autosave_post->post_parent
				&& "{$post_id}-autosave-v1" === $autosave_post->post_name,
			'REST autosave routes validate parent IDs and use seeded autosave revision rows for read projection',
			array(
				'invalidParent' => self::response_summary( $invalid_autosave_parent ),
				'autosavePost'  => self::post_summary( $autosave_post ),
			)
		);
		self::collect_failure(
			$failures,
			$autosave_collection instanceof \WP_REST_Response
				&& 200 === $autosave_collection->get_status()
				&& is_array( $autosave_collection_data )
				&& 1 === count( $autosave_collection_data )
				&& $autosave_id === (int) ( $autosave_collection_data[0]['id'] ?? 0 )
				&& $autosave_id === (int) ( $autosave_item_data['id'] ?? 0 )
				&& ( $autosave_collection_data[0]['preview_link'] ?? null ) === ( $autosave_item_data['preview_link'] ?? null )
				&& $case['titleTo'] . ' REST autosave' === ( $autosave_item_data['title']['raw'] ?? null )
				&& count( $prepare_autosave_calls ) >= 2,
			'REST autosave collection and item dispatch expose the current autosave with stable preview links',
			array(
				'collection'     => self::response_summary( $autosave_collection ),
				'item'           => self::response_summary( $autosave_item ),
				'prepareAutosave' => $prepare_autosave_calls,
			)
		);
		self::collect_failure(
			$failures,
			in_array( $new_revision_id, array_map( 'intval', array_column( $prepare_revision_calls, 'id' ) ), true )
				&& false === \has_filter( 'rest_revision_query', $query_filter )
				&& false === \has_filter( 'rest_prepare_revision', $revision_filter )
				&& false === \has_filter( 'rest_prepare_autosave', $autosave_filter )
				&& false === \has_filter( 'rest_delete_revision', $delete_action )
				&& false === \has_filter( 'map_meta_cap', $delete_revision_cap_filter )
				&& false === \has_filter( 'user_has_cap', $grant_caps ),
			'REST revision/autosave dispatch hooks observe successful paths and are removed after the matrix',
			array(
				'prepareRevision' => $prepare_revision_calls,
				'prepareAutosave' => $prepare_autosave_calls,
				'filters'         => array(
					'query'    => \has_filter( 'rest_revision_query', $query_filter ),
					'revision' => \has_filter( 'rest_prepare_revision', $revision_filter ),
					'autosave' => \has_filter( 'rest_prepare_autosave', $autosave_filter ),
					'delete'   => \has_filter( 'rest_delete_revision', $delete_action ),
					'mapCaps'  => \has_filter( 'map_meta_cap', $delete_revision_cap_filter ),
					'caps'     => \has_filter( 'user_has_cap', $grant_caps ),
				),
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.rest-revision-autosave-route-dispatch',
			$failures,
			array(
				'case'              => self::case_summary( $case ),
				'postId'            => $post_id,
				'restBase'          => $rest_base,
				'revisionIds'       => array( $old_revision_id, $new_revision_id ),
				'autosaveId'        => $autosave_id,
			)
		);
	}

	private static function check_rest_builtin_post_page_revision_autosave_parity( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		$server = self::fresh_rest_server();

		$failures               = array();
		$query_calls            = array();
		$prepare_revision_calls = array();
		$prepare_autosave_calls = array();
		$matrix                 = array();

		$query_filter = static function ( array $args, \WP_REST_Request $request ) use ( &$query_calls ): array {
			$query_calls[] = array(
				'route'               => $request->get_route(),
				'method'              => $request->get_method(),
				'postParent'          => $args['post_parent'] ?? null,
				'postIn'              => isset( $args['post__in'] ) ? array_values( array_map( 'intval', (array) $args['post__in'] ) ) : null,
				'postNotIn'           => isset( $args['post__not_in'] ) ? array_values( array_map( 'intval', (array) $args['post__not_in'] ) ) : null,
				'postsPerPage'        => $args['posts_per_page'] ?? null,
				'order'               => $args['order'] ?? null,
				'orderBy'             => $args['orderby'] ?? null,
				'fields'              => $args['fields'] ?? null,
				'paged'               => $args['paged'] ?? null,
				'offset'              => $args['offset'] ?? null,
				'search'              => $args['s'] ?? null,
				'updatePostMetaCache' => $args['update_post_meta_cache'] ?? null,
				'updatePostTermCache' => $args['update_post_term_cache'] ?? null,
			);
			return $args;
		};
		$revision_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_revision_calls ): \WP_REST_Response {
			$prepare_revision_calls[] = array(
				'id'      => (int) $post->ID,
				'parent'  => (int) $post->post_parent,
				'type'    => $post->post_type,
				'route'   => $request->get_route(),
				'method'  => $request->get_method(),
				'context' => $request['context'],
				'fields'  => $request['_fields'],
			);
			return $response;
		};
		$autosave_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_autosave_calls ): \WP_REST_Response {
			$prepare_autosave_calls[] = array(
				'id'      => (int) $post->ID,
				'parent'  => (int) $post->post_parent,
				'author'  => (int) $post->post_author,
				'route'   => $request->get_route(),
				'method'  => $request->get_method(),
				'context' => $request['context'],
				'fields'  => $request['_fields'],
			);
			return $response;
		};

		\add_filter( 'rest_revision_query', $query_filter, 10, 2 );
		\add_filter( 'rest_prepare_revision', $revision_filter, 10, 3 );
		\add_filter( 'rest_prepare_autosave', $autosave_filter, 10, 3 );

		try {
			foreach ( array( 'post' => 'posts', 'page' => 'pages' ) as $post_type => $rest_base ) {
				$target_case = array_merge(
					$case,
					array(
						'postType' => $post_type,
						'slug'     => $case['slug'] . '-' . $post_type,
					)
				);
				$post_type_object = \get_post_type_object( $post_type );
				if ( ! $post_type_object ) {
					self::collect_failure(
						$failures,
						false,
						"built-in {$post_type} post type is registered for REST revision/autosave parity",
						array( 'postType' => $post_type )
					);
					continue;
				}

				$parent_controller   = $post_type_object->get_rest_controller();
				$revision_controller = $post_type_object->get_revisions_rest_controller();
				$autosave_controller = $post_type_object->get_autosave_rest_controller();
				if ( $parent_controller ) {
					$parent_controller->register_routes();
				}
				if ( $revision_controller ) {
					$revision_controller->register_routes();
				}
				if ( $autosave_controller ) {
					$autosave_controller->register_routes();
				}

				$author_id      = self::insert_author( $target_case, "builtin-{$post_type}-author" );
				$editor_id      = self::insert_author( $target_case, "builtin-{$post_type}-editor" );
				$other_user_id  = self::insert_author( $target_case, "builtin-{$post_type}-other" );
				$post_id        = self::insert_parent_post( $target_case, $author_id, "builtin-{$post_type}-parent" );
				$old_revision   = self::insert_revision_row(
					$post_id,
					$author_id,
					array(
						'post_title'        => $case['titleFrom'] . " {$post_type} built-in old revision",
						'post_content'      => $case['contentFrom'] . "\n{$post_type} built-in old revision",
						'post_excerpt'      => $case['excerptFrom'],
						'post_date'         => $case['dateFrom'],
						'post_date_gmt'     => $case['dateFromGmt'],
						'post_modified'     => $case['dateFrom'],
						'post_modified_gmt' => $case['dateFromGmt'],
					),
					false
				);
				$middle_revision = self::insert_revision_row(
					$post_id,
					$author_id,
					array(
						'post_title'        => $case['titleTo'] . " {$post_type} built-in middle revision",
						'post_content'      => $case['contentTo'] . "\n{$post_type} built-in middle revision",
						'post_excerpt'      => $case['excerptTo'],
						'post_date'         => $case['dateTo'],
						'post_date_gmt'     => $case['dateToGmt'],
						'post_modified'     => $case['dateTo'],
						'post_modified_gmt' => $case['dateToGmt'],
					),
					false
				);
				$new_revision   = self::insert_revision_row(
					$post_id,
					$author_id,
					array(
						'post_title'        => $case['titleTo'] . " {$post_type} built-in newest revision",
						'post_content'      => $case['contentTo'] . "\n{$post_type} built-in newest revision",
						'post_excerpt'      => $case['excerptTo'],
						'post_date'         => $case['dateLater'],
						'post_date_gmt'     => $case['dateLaterGmt'],
						'post_modified'     => $case['dateLater'],
						'post_modified_gmt' => $case['dateLaterGmt'],
					),
					false
				);
				$other_autosave = self::insert_revision_row(
					$post_id,
					$other_user_id,
					array(
						'post_title'        => $case['titleFrom'] . " {$post_type} other autosave",
						'post_content'      => $case['contentFrom'] . "\n{$post_type} other autosave",
						'post_excerpt'      => $case['excerptFrom'],
						'post_date'         => self::offset_mysql_date( $case['dateTo'], 7 ),
						'post_date_gmt'     => self::offset_mysql_date( $case['dateToGmt'], 7 ),
						'post_modified'     => self::offset_mysql_date( $case['dateTo'], 7 ),
						'post_modified_gmt' => self::offset_mysql_date( $case['dateToGmt'], 7 ),
					),
					true
				);
				$current_autosave = self::insert_revision_row(
					$post_id,
					$editor_id,
					array(
						'post_title'        => $case['titleTo'] . " {$post_type} current autosave",
						'post_content'      => $case['contentTo'] . "\n{$post_type} current autosave",
						'post_excerpt'      => $case['excerptTo'],
						'post_date'         => self::offset_mysql_date( $case['dateLater'], 11 ),
						'post_date_gmt'     => self::offset_mysql_date( $case['dateLaterGmt'], 11 ),
						'post_modified'     => self::offset_mysql_date( $case['dateLater'], 11 ),
						'post_modified_gmt' => self::offset_mysql_date( $case['dateLaterGmt'], 11 ),
					),
					true
				);

				$revision_route = '/wp/v2/' . $rest_base . '/' . $post_id . '/revisions';
				$autosave_route = '/wp/v2/' . $rest_base . '/' . $post_id . '/autosaves';
				$parent_route   = '/wp/v2/' . $rest_base . '/' . $post_id;
				$total_children = 5;
				$grant_caps     = self::grant_all_caps_filter( $editor_id );

				try {
					\wp_set_current_user( $editor_id );
					\add_filter( 'user_has_cap', $grant_caps, 10, 4 );

					$parent_item = self::dispatch(
						$server,
						self::request(
							'GET',
							$parent_route,
							array(
								'context' => 'edit',
								'_fields' => 'id,type,status,slug',
							)
						)
					);

					$query_calls            = array();
					$prepare_revision_calls = array();
					$include_missing        = self::dispatch(
						$server,
						self::request(
							'GET',
							$revision_route,
							array(
								'context' => 'edit',
								'orderby' => 'include',
							)
						)
					);
					$include_missing_query_calls = $query_calls;

					$query_calls            = array();
					$prepare_revision_calls = array();
					$include_collection     = self::dispatch(
						$server,
						self::request(
							'GET',
							$revision_route,
							array(
								'context'  => 'edit',
								'_fields'  => 'id,parent,slug,title.raw,_links',
								'include'  => array( $middle_revision, $old_revision ),
								'orderby'  => 'include',
								'per_page' => 2,
							)
						)
					);
					$include_query_calls    = $query_calls;
					$include_prepare_calls  = $prepare_revision_calls;
					$include_data           = $include_collection instanceof \WP_REST_Response ? $include_collection->get_data() : array();
					$include_headers        = $include_collection instanceof \WP_REST_Response ? $include_collection->get_headers() : array();

					$query_calls            = array();
					$prepare_revision_calls = array();
					$head_collection        = self::dispatch(
						$server,
						self::request(
							'HEAD',
							$revision_route,
							array(
								'context'  => 'edit',
								'orderby'  => 'date',
								'order'    => 'desc',
								'per_page' => 1,
								'page'     => 2,
							)
						)
					);
					$head_query_calls       = $query_calls;
					$head_prepare_calls     = $prepare_revision_calls;
					$head_headers           = $head_collection instanceof \WP_REST_Response ? $head_collection->get_headers() : array();

					$query_calls            = array();
					$prepare_revision_calls = array();
					$invalid_page           = self::dispatch(
						$server,
						self::request(
							'GET',
							$revision_route,
							array(
								'context'  => 'edit',
								'per_page' => 1,
								'page'     => $total_children + 1,
							)
						)
					);
					$invalid_page_query_calls   = $query_calls;
					$invalid_page_prepare_calls = $prepare_revision_calls;

					$query_calls            = array();
					$prepare_revision_calls = array();
					$invalid_offset         = self::dispatch(
						$server,
						self::request(
							'GET',
							$revision_route,
							array(
								'context'  => 'edit',
								'per_page' => 1,
								'offset'   => $total_children,
							)
						)
					);
					$invalid_offset_query_calls   = $query_calls;
					$invalid_offset_prepare_calls = $prepare_revision_calls;

					$query_calls            = array();
					$prepare_revision_calls = array();
					$offset_page_precedence = self::dispatch(
						$server,
						self::request(
							'GET',
							$revision_route,
							array(
								'context'  => 'edit',
								'_fields'  => 'id,parent',
								'per_page' => 1,
								'offset'   => 1,
								'page'     => 999,
							)
						)
					);
					$offset_page_query_calls   = $query_calls;
					$offset_page_prepare_calls = $prepare_revision_calls;
					$offset_page_data          = $offset_page_precedence instanceof \WP_REST_Response ? $offset_page_precedence->get_data() : array();
					$offset_page_headers       = $offset_page_precedence instanceof \WP_REST_Response ? $offset_page_precedence->get_headers() : array();

					$prepare_revision_calls = array();
					$prepare_autosave_calls = array();
					$autosave_collection    = self::dispatch(
						$server,
						self::request(
							'GET',
							$autosave_route,
							array(
								'context' => 'edit',
								'_fields' => 'id,parent,title.raw,preview_link',
							)
						)
					);
					$autosave_collection_data = $autosave_collection instanceof \WP_REST_Response ? $autosave_collection->get_data() : array();
					$autosave_item            = self::dispatch(
						$server,
						self::request(
							'GET',
							$autosave_route . '/' . $other_autosave,
							array(
								'context' => 'edit',
								'_fields' => 'id,parent,title.raw,preview_link',
							)
						)
					);
					$autosave_item_data       = $autosave_item instanceof \WP_REST_Response ? $autosave_item->get_data() : array();
					$autosave_prepare_before_head = $prepare_autosave_calls;
					$revision_prepare_before_head = $prepare_revision_calls;
					$autosave_head            = self::dispatch(
						$server,
						self::request(
							'HEAD',
							$autosave_route,
							array( 'context' => 'edit' )
						)
					);
					$autosave_prepare_after_head = $prepare_autosave_calls;
					$revision_prepare_after_head = $prepare_revision_calls;
				} finally {
					\remove_filter( 'user_has_cap', $grant_caps, 10 );
					\wp_set_current_user( 0 );
				}

				$parent_data = $parent_item instanceof \WP_REST_Response ? $parent_item->get_data() : array();
				self::collect_failure(
					$failures,
					$parent_item instanceof \WP_REST_Response
						&& 200 === $parent_item->get_status()
						&& $post_id === (int) ( $parent_data['id'] ?? 0 )
						&& $post_type === ( $parent_data['type'] ?? null )
						&& 'draft' === ( $parent_data['status'] ?? null ),
					"built-in {$post_type} parent route dispatches before revision/autosave parity checks",
					array(
						'postType' => $post_type,
						'parent'   => self::response_summary( $parent_item ),
					)
				);

				$include_ids       = is_array( $include_data ) ? array_values( array_map( 'intval', array_column( $include_data, 'id' ) ) ) : array();
				$include_parent_ids = is_array( $include_data ) ? array_values( array_map( 'intval', array_column( $include_data, 'parent' ) ) ) : array();
				self::collect_failure(
					$failures,
					self::response_error_ok( $include_missing, 'rest_orderby_include_missing_include', 400 )
						&& array() === $include_missing_query_calls
						&& $include_collection instanceof \WP_REST_Response
						&& 200 === $include_collection->get_status()
						&& array( $middle_revision, $old_revision ) === $include_ids
						&& array( $post_id, $post_id ) === $include_parent_ids
						&& 2 === (int) ( $include_headers['X-WP-Total'] ?? 0 )
						&& 1 === (int) ( $include_headers['X-WP-TotalPages'] ?? 0 )
						&& 1 === count( $include_query_calls )
						&& $post_id === (int) ( $include_query_calls[0]['postParent'] ?? 0 )
						&& array( $middle_revision, $old_revision ) === ( $include_query_calls[0]['postIn'] ?? array() )
						&& 'include' === ( $include_query_calls[0]['orderBy'] ?? null )
						&& 2 === count( $include_prepare_calls ),
					"built-in {$post_type} revisions collection validates include-order preconditions and preserves include ordering",
					array(
						'postType'      => $post_type,
						'missing'       => self::response_summary( $include_missing ),
						'collection'    => self::response_summary( $include_collection ),
						'queryCalls'    => $include_query_calls,
						'prepareCalls'  => $include_prepare_calls,
					)
				);

				self::collect_failure(
					$failures,
					$head_collection instanceof \WP_REST_Response
						&& 200 === $head_collection->get_status()
						&& array() === $head_collection->get_data()
						&& $total_children === (int) ( $head_headers['X-WP-Total'] ?? 0 )
						&& $total_children === (int) ( $head_headers['X-WP-TotalPages'] ?? 0 )
						&& 1 === count( $head_query_calls )
						&& 'ids' === ( $head_query_calls[0]['fields'] ?? null )
						&& false === ( $head_query_calls[0]['updatePostMetaCache'] ?? null )
						&& false === ( $head_query_calls[0]['updatePostTermCache'] ?? null )
						&& 'date ID' === ( $head_query_calls[0]['orderBy'] ?? null )
						&& 1 === (int) ( $head_query_calls[0]['postsPerPage'] ?? 0 )
						&& 2 === (int) ( $head_query_calls[0]['paged'] ?? 0 )
						&& array() === $head_prepare_calls,
					"built-in {$post_type} revisions HEAD collection uses ID-only pagination without preparing bodies",
					array(
						'postType'     => $post_type,
						'head'         => self::response_summary( $head_collection ),
						'queryCalls'   => $head_query_calls,
						'prepareCalls' => $head_prepare_calls,
					)
				);

				self::collect_failure(
					$failures,
					self::response_error_ok( $invalid_page, 'rest_revision_invalid_page_number', 400 )
						&& array() === $invalid_page_prepare_calls
						&& 1 <= count( $invalid_page_query_calls ),
					"built-in {$post_type} revisions collection fails closed for out-of-bounds page requests before response preparation",
					array(
						'postType'     => $post_type,
						'invalidPage'  => self::response_summary( $invalid_page ),
						'queryCalls'   => $invalid_page_query_calls,
						'prepareCalls' => $invalid_page_prepare_calls,
					)
				);

				self::collect_failure(
					$failures,
					self::response_error_ok( $invalid_offset, 'rest_revision_invalid_offset_number', 400 )
						&& array() === $invalid_offset_prepare_calls
						&& 1 <= count( $invalid_offset_query_calls )
						&& $offset_page_precedence instanceof \WP_REST_Response
						&& 200 === $offset_page_precedence->get_status()
						&& is_array( $offset_page_data )
						&& 1 === count( $offset_page_data )
						&& $post_id === (int) ( $offset_page_data[0]['parent'] ?? 0 )
						&& $total_children === (int) ( $offset_page_headers['X-WP-Total'] ?? 0 )
						&& $total_children === (int) ( $offset_page_headers['X-WP-TotalPages'] ?? 0 )
						&& 1 === count( $offset_page_query_calls )
						&& 1 === (int) ( $offset_page_query_calls[0]['offset'] ?? -1 )
						&& 999 === (int) ( $offset_page_query_calls[0]['paged'] ?? 0 )
						&& 1 === count( $offset_page_prepare_calls ),
					"built-in {$post_type} revisions collection rejects out-of-bounds offsets while nonzero offset takes precedence over out-of-bounds page",
					array(
						'postType'          => $post_type,
						'invalidOffset'     => self::response_summary( $invalid_offset ),
						'invalidOffsetQuery' => $invalid_offset_query_calls,
						'offsetPrecedence'  => self::response_summary( $offset_page_precedence ),
						'offsetQuery'       => $offset_page_query_calls,
						'offsetPrepare'     => $offset_page_prepare_calls,
					)
				);

				$autosave_ids          = is_array( $autosave_collection_data ) ? array_values( array_map( 'intval', array_column( $autosave_collection_data, 'id' ) ) ) : array();
				$expected_autosave_ids = array( $current_autosave, $other_autosave );
				sort( $autosave_ids );
				sort( $expected_autosave_ids );
				self::collect_failure(
					$failures,
					$autosave_collection instanceof \WP_REST_Response
						&& 200 === $autosave_collection->get_status()
						&& $expected_autosave_ids === $autosave_ids
						&& $autosave_item instanceof \WP_REST_Response
						&& 200 === $autosave_item->get_status()
						&& $current_autosave === (int) ( $autosave_item_data['id'] ?? 0 )
						&& $post_id === (int) ( $autosave_item_data['parent'] ?? 0 )
						&& $case['titleTo'] . " {$post_type} current autosave" === ( $autosave_item_data['title']['raw'] ?? null )
						&& $autosave_head instanceof \WP_REST_Response
						&& 200 === $autosave_head->get_status()
						&& array() === $autosave_head->get_data()
						&& $autosave_prepare_before_head === $autosave_prepare_after_head
						&& $revision_prepare_before_head === $revision_prepare_after_head,
					"built-in {$post_type} autosave collection lists seeded autosaves while item routes return the current user's autosave and HEAD stays body-free",
					array(
						'postType'              => $post_type,
						'collection'            => self::response_summary( $autosave_collection ),
						'item'                  => self::response_summary( $autosave_item ),
						'head'                  => self::response_summary( $autosave_head ),
						'prepareAutosaveBefore' => $autosave_prepare_before_head,
						'prepareAutosaveAfter'  => $autosave_prepare_after_head,
						'prepareRevisionBefore' => $revision_prepare_before_head,
						'prepareRevisionAfter'  => $revision_prepare_after_head,
					)
				);

				$matrix[] = array(
					'postType'        => $post_type,
					'restBase'        => $rest_base,
					'postId'          => $post_id,
					'revisions'       => array( $old_revision, $middle_revision, $new_revision ),
					'autosaves'       => array( $current_autosave, $other_autosave ),
					'totalChildren'   => $total_children,
				);
			}

			$matrix_by_type = array();
			foreach ( $matrix as $entry ) {
				$matrix_by_type[ $entry['postType'] ] = $entry;
			}
			if ( isset( $matrix_by_type['post'], $matrix_by_type['page'] ) ) {
				$post_id = (int) $matrix_by_type['post']['postId'];
				$page_id = (int) $matrix_by_type['page']['postId'];
				$cross_post_revision = self::dispatch( $server, self::request( 'GET', '/wp/v2/posts/' . $page_id . '/revisions' ) );
				$cross_page_autosave = self::dispatch( $server, self::request( 'GET', '/wp/v2/pages/' . $post_id . '/autosaves' ) );

				self::collect_failure(
					$failures,
					self::response_error_ok( $cross_post_revision, 'rest_post_invalid_parent', 404 )
						&& self::response_error_ok( $cross_page_autosave, 'rest_post_invalid_parent', 404 ),
					'built-in post/page revision and autosave routes reject cross-type parent IDs before querying',
					array(
						'postId'            => $post_id,
						'pageId'            => $page_id,
						'crossPostRevision' => self::response_summary( $cross_post_revision ),
						'crossPageAutosave' => self::response_summary( $cross_page_autosave ),
					)
				);
			}
		} finally {
			\remove_filter( 'rest_prepare_autosave', $autosave_filter, 10 );
			\remove_filter( 'rest_prepare_revision', $revision_filter, 10 );
			\remove_filter( 'rest_revision_query', $query_filter, 10 );
			\wp_set_current_user( 0 );
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'rest_revision_query', $query_filter )
				&& false === \has_filter( 'rest_prepare_revision', $revision_filter )
				&& false === \has_filter( 'rest_prepare_autosave', $autosave_filter ),
			'built-in post/page REST revision/autosave parity harness removes local filters',
			array(
				'filters' => array(
					'query'    => \has_filter( 'rest_revision_query', $query_filter ),
					'revision' => \has_filter( 'rest_prepare_revision', $revision_filter ),
					'autosave' => \has_filter( 'rest_prepare_autosave', $autosave_filter ),
				),
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.rest-builtin-post-page-parity',
			$failures,
			array(
				'case'   => self::case_summary( $case ),
				'matrix' => $matrix,
			)
		);
	}

	private static function check_rest_autosave_mutation_and_revision_meta_projection( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$missing = self::rest_autosave_meta_child_missing_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'revisions-autosaves.rest-autosave-mutation-subprocess-requirements',
				'Local PHP subprocess support is unavailable for isolated REST autosave mutation coverage.',
				array( 'missing' => $missing )
			);
		}

		$parent_doing_autosave_before = array(
			'defined' => defined( 'DOING_AUTOSAVE' ),
			'value'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
		);
		$run = self::run_rest_autosave_meta_child( $case );
		$parent_doing_autosave_after = array(
			'defined' => defined( 'DOING_AUTOSAVE' ),
			'value'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
		);

		$result   = is_array( $run['result'] ?? null ) ? $run['result'] : array();
		$matrix   = is_array( $result['matrix'] ?? null ) ? $result['matrix'] : array();
		$failures = array();

		self::collect_failure(
			$failures,
			0 === (int) ( $run['exitCode'] ?? -1 )
				&& '' === (string) ( $run['stderr'] ?? '' )
				&& is_array( $run['result'] ?? null )
				&& true === ( $run['ok'] ?? null ),
			'REST autosave mutation child returns structured JSON without stderr or invariant failures',
			array(
				'exitCode'      => $run['exitCode'] ?? null,
				'stdout'        => self::describe_output( (string) ( $run['stdout'] ?? '' ) ),
				'stderr'        => self::describe_output( (string) ( $run['stderr'] ?? '' ) ),
				'childFailures' => $result['failures'] ?? null,
			)
		);
		self::collect_failure(
			$failures,
			true === ( $result['wpRunCoreTests'] ?? null )
				&& false === ( $result['doingAutosaveDefined'] ?? true ),
			'REST autosave mutation child defines WP_RUN_CORE_TESTS before bootstrap and does not define DOING_AUTOSAVE',
			array(
				'wpRunCoreTests'        => $result['wpRunCoreTests'] ?? null,
				'doingAutosaveDefined'  => $result['doingAutosaveDefined'] ?? null,
				'doingAutosaveValue'    => $result['doingAutosaveValue'] ?? null,
			)
		);
		self::collect_failure(
			$failures,
			$parent_doing_autosave_before === $parent_doing_autosave_after,
			'REST autosave mutation child leaves parent DOING_AUTOSAVE constant state unchanged',
			array(
				'before' => $parent_doing_autosave_before,
				'after'  => $parent_doing_autosave_after,
			)
		);
		self::collect_failure(
			$failures,
			array( 'page', 'post' ) === array_values(
				array_intersect(
					array( 'page', 'post' ),
					array_map(
						static fn( array $entry ): string => (string) ( $entry['postType'] ?? '' ),
						$matrix
					)
				)
			),
			'REST autosave mutation child covers built-in post and page autosave/meta routes',
			array( 'matrix' => $matrix )
		);

		return self::result(
			$ctx,
			'revisions-autosaves.rest-autosave-mutations-and-revision-meta-projection',
			$failures,
			array(
				'case'     => self::case_summary( $case ),
				'exitCode' => $run['exitCode'] ?? null,
				'matrix'   => $matrix,
			)
		);
	}

	private static function rest_autosave_meta_child_missing_requirements(): array {
		$missing = array();

		foreach ( array( 'json_decode', 'json_encode', 'proc_close', 'proc_open', 'stream_get_contents' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}

		return $missing;
	}

	private static function run_rest_autosave_meta_child( array $case ): array {
		$payload = json_encode(
			array( 'case' => $case ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( false === $payload ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'json_encode failed',
				'result'   => null,
			);
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates REST autosave mutation paths that would otherwise define process-wide constants.
		$process = proc_open( array( PHP_BINARY, '-r', self::rest_autosave_meta_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		fwrite( $pipes[0], $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		$result    = json_decode( (string) $stdout, true );

		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && true === ( $result['ok'] ?? null ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function rest_autosave_meta_child_program(): string {
		return <<<'PHP'
if ( ! defined( 'WP_RUN_CORE_TESTS' ) ) {
	define( 'WP_RUN_CORE_TESTS', true );
}

ini_set( 'display_errors', '0' );
ob_start();

$result = array(
	'ok'       => false,
	'failures' => array(),
);

try {
	$raw     = stream_get_contents( STDIN );
	$payload = json_decode( $raw, true );
	$case    = is_array( $payload['case'] ?? null ) ? $payload['case'] : array();

	require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
	\ComponentFuzz\WpBootstrap::load();

	$result = \ComponentFuzz\Surfaces\RevisionsAutosavesSurface::rest_autosave_meta_child_entry( $case );
} catch ( Throwable $e ) {
	$result = array(
		'ok'       => false,
		'failures' => array(
			array(
				'label'   => 'REST autosave mutation child catches top-level throwables',
				'details' => array(
					'class'   => get_class( $e ),
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				),
			),
		),
	);
}

$output = ob_get_clean();
if ( '' !== $output ) {
	$result['ok']         = false;
	$result['failures'][] = array(
		'label'   => 'REST autosave mutation child produces no incidental output before JSON',
		'details' => array(
			'bytes'   => strlen( $output ),
			'sha1'    => sha1( $output ),
			'preview' => substr( $output, 0, 220 ),
		),
	);
}

$json = json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
echo false === $json ? '{"ok":false,"failures":[{"label":"REST autosave mutation child JSON encoding failed","details":[]}]}' : $json;
PHP;
	}

	public static function rest_autosave_meta_child_entry( array $case ): array {
		self::load_rest_endpoint_classes();

		$failures = array();
		$missing  = self::missing_requirements();
		foreach ( array( 'add_metadata', 'get_current_user_id', 'get_metadata_raw', 'unregister_meta_key', 'update_metadata', 'wp_autosave_post_revisioned_meta_fields' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( array() !== $missing ) {
			return array(
				'ok'                   => false,
				'failures'             => array(
					array(
						'label'   => 'REST autosave mutation child has required WordPress APIs',
						'details' => array( 'missing' => $missing ),
					),
				),
				'wpRunCoreTests'       => defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS,
				'doingAutosaveDefined' => defined( 'DOING_AUTOSAVE' ),
				'doingAutosaveValue'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
				'matrix'               => array(),
			);
		}

		self::prepare_runtime();
		$server = self::fresh_rest_server();

		$matrix                 = array();
		$creating_autosaves     = array();
		$prepare_autosave_calls = array();
		$prepare_revision_calls = array();

		$creating_action = static function ( array $new_autosave ) use ( &$creating_autosaves ): void {
			$creating_autosaves[] = array(
				'id'     => (int) ( $new_autosave['ID'] ?? 0 ),
				'parent' => (int) ( $new_autosave['post_parent'] ?? 0 ),
				'author' => (int) ( $new_autosave['post_author'] ?? 0 ),
				'title'  => (string) ( $new_autosave['post_title'] ?? '' ),
			);
		};
		$autosave_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_autosave_calls ): \WP_REST_Response {
			$prepare_autosave_calls[] = array(
				'id'      => (int) $post->ID,
				'parent'  => (int) $post->post_parent,
				'author'  => (int) $post->post_author,
				'route'   => $request->get_route(),
				'method'  => $request->get_method(),
				'fields'  => $request['_fields'],
			);
			return $response;
		};
		$revision_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_revision_calls ): \WP_REST_Response {
			$prepare_revision_calls[] = array(
				'id'     => (int) $post->ID,
				'parent' => (int) $post->post_parent,
				'route'  => $request->get_route(),
				'method' => $request->get_method(),
				'fields' => $request['_fields'],
			);
			return $response;
		};

		\add_action( 'wp_creating_autosave', $creating_action, 9, 1 );
		\add_filter( 'rest_prepare_autosave', $autosave_filter, 10, 3 );
		\add_filter( 'rest_prepare_revision', $revision_filter, 10, 3 );

		try {
			foreach ( array( 'post' => 'posts', 'page' => 'pages' ) as $post_type => $rest_base ) {
				$matrix[] = self::rest_autosave_meta_child_post_type(
					$server,
					$case,
					$post_type,
					$rest_base,
					$creating_autosaves,
					$failures
				);
			}
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				'REST autosave mutation child keeps built-in route matrix throwable-free',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			\remove_filter( 'rest_prepare_revision', $revision_filter, 10 );
			\remove_filter( 'rest_prepare_autosave', $autosave_filter, 10 );
			\remove_action( 'wp_creating_autosave', $creating_action, 9 );
			\wp_set_current_user( 0 );
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'wp_creating_autosave', $creating_action )
				&& false === \has_filter( 'rest_prepare_autosave', $autosave_filter )
				&& false === \has_filter( 'rest_prepare_revision', $revision_filter ),
			'REST autosave mutation child removes local observers before reporting',
			array(
				'filters' => array(
					'creatingAutosave' => \has_filter( 'wp_creating_autosave', $creating_action ),
					'autosave'         => \has_filter( 'rest_prepare_autosave', $autosave_filter ),
					'revision'         => \has_filter( 'rest_prepare_revision', $revision_filter ),
				),
			)
		);

		return array(
			'ok'                   => array() === $failures,
			'failures'             => array_slice( $failures, 0, 8 ),
			'wpRunCoreTests'       => defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS,
			'doingAutosaveDefined' => defined( 'DOING_AUTOSAVE' ),
			'doingAutosaveValue'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
			'matrix'               => $matrix,
			'creatingAutosaves'    => $creating_autosaves,
			'prepareAutosave'      => $prepare_autosave_calls,
			'prepareRevision'      => $prepare_revision_calls,
		);
	}

	private static function rest_autosave_meta_child_post_type( \WP_REST_Server $server, array $case, string $post_type, string $rest_base, array &$creating_autosaves, array &$failures ): array {
		$target_case = array_merge(
			$case,
			array(
				'postType' => $post_type,
				'slug'     => $case['slug'] . '-autosave-meta-' . $post_type,
			)
		);
		$entry       = array(
			'postType' => $post_type,
			'restBase' => $rest_base,
		);
		$meta_key    = 'cf_rest_autosave_' . $post_type . '_' . $case['token'];
		$registered  = false;
		$author_caps = null;
		$editor_caps = null;

		try {
			$post_type_object = \get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				self::collect_failure(
					$failures,
					false,
					"built-in {$post_type} post type exists for REST autosave mutation checks",
					array( 'postType' => $post_type )
				);
				return $entry;
			}

			$registered = \register_post_meta(
				$post_type,
				$meta_key,
				array(
					'auth_callback'     => '__return_true',
					'revisions_enabled' => true,
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);

			self::collect_failure(
				$failures,
				true === $registered && in_array( $meta_key, \wp_post_revision_meta_keys( $post_type ), true ),
				"built-in {$post_type} revisioned REST meta key registers before route schemas are cached",
				array(
					'registered'       => $registered,
					'metaKey'          => $meta_key,
					'revisionMetaKeys' => \wp_post_revision_meta_keys( $post_type ),
				)
			);

			$parent_controller   = $post_type_object->get_rest_controller();
			$revision_controller = $post_type_object->get_revisions_rest_controller();
			$autosave_controller = $post_type_object->get_autosave_rest_controller();
			if ( $parent_controller ) {
				$parent_controller->register_routes();
			}
			if ( $revision_controller ) {
				$revision_controller->register_routes();
			}
			if ( $autosave_controller ) {
				$autosave_controller->register_routes();
			}

			$author_id     = self::insert_author( $target_case, "rest-autosave-meta-{$post_type}-author" );
			$editor_id     = self::insert_author( $target_case, "rest-autosave-meta-{$post_type}-editor" );
			$other_user_id = self::insert_author( $target_case, "rest-autosave-meta-{$post_type}-other" );
			$draft_id      = self::insert_parent_post( $target_case, $author_id, "same-author-{$post_type}" );
			$parent_id     = self::insert_parent_post( $target_case, $author_id, "per-user-{$post_type}" );

			$author_caps = self::grant_all_caps_filter( $author_id );
			$editor_caps = self::grant_all_caps_filter( $editor_id );
			\add_filter( 'user_has_cap', $author_caps, 10, 4 );
			\add_filter( 'user_has_cap', $editor_caps, 10, 4 );

			$parent_meta = 'parent-meta-' . $post_type . '-' . $case['token'];
			\update_post_meta( $parent_id, $meta_key, $parent_meta );

			$same_title   = $case['titleTo'] . " {$post_type} same-author REST autosave";
			$same_content = $case['contentTo'] . "\n{$post_type} same-author REST autosave";
			$same_excerpt = $case['excerptTo'] . " {$post_type} same-author";
			\wp_set_current_user( $author_id );
			$same_response = self::dispatch(
				$server,
				self::request(
					'POST',
					'/wp/v2/' . $rest_base . '/' . $draft_id . '/autosaves',
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,content.raw',
					),
					array(),
					array(
						'title'   => $same_title,
						'content' => $same_content,
						'excerpt' => $same_excerpt,
					)
				)
			);
			$same_data     = $same_response instanceof \WP_REST_Response ? $same_response->get_data() : array();
			$same_post     = \get_post( $draft_id );
			$same_autosave = \wp_get_post_autosave( $draft_id, $author_id );

			self::collect_failure(
				$failures,
				$same_response instanceof \WP_REST_Response
					&& 200 === $same_response->get_status()
					&& $draft_id === (int) ( $same_data['id'] ?? 0 )
					&& $same_post instanceof \WP_Post
					&& $same_title === $same_post->post_title
					&& $same_content === $same_post->post_content
					&& false === $same_autosave,
				"built-in {$post_type} same-author draft autosave updates the parent draft instead of creating a per-user autosave",
				array(
					'response' => self::response_summary( $same_response ),
					'post'     => self::post_summary( $same_post ),
					'autosave' => self::post_summary( $same_autosave ),
				)
			);

			$autosave_route       = '/wp/v2/' . $rest_base . '/' . $parent_id . '/autosaves';
			$revision_route       = '/wp/v2/' . $rest_base . '/' . $parent_id . '/revisions';
			$create_meta          = 'created-meta-' . $post_type . '-' . $case['token'];
			$create_title         = $case['titleTo'] . " {$post_type} created REST autosave";
			$create_content       = $case['contentTo'] . "\n{$post_type} created REST autosave";
			$create_excerpt       = $case['excerptTo'] . " {$post_type} created autosave";
			$events_before_create = count( $creating_autosaves );

			\wp_set_current_user( $editor_id );
			$create_response = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,content.raw,meta,preview_link',
					),
					array(),
					array(
						'title'   => $create_title,
						'content' => $create_content,
						'excerpt' => $create_excerpt,
						'meta'    => array(
							$meta_key => $create_meta,
						),
					)
				)
			);
			$events_after_create = count( $creating_autosaves );
			$create_data         = $create_response instanceof \WP_REST_Response ? $create_response->get_data() : array();
			$autosave_id         = (int) ( $create_data['id'] ?? 0 );
			$created_autosave    = \get_post( $autosave_id );
			$lookup_autosave     = \wp_get_post_autosave( $parent_id, $editor_id );
			$parent_after_create = \get_post( $parent_id );
			$stored_create_meta  = \get_metadata_raw( 'post', $autosave_id, $meta_key, true );
			$parent_after_create_meta = \get_metadata_raw( 'post', $parent_id, $meta_key, true );

			self::collect_failure(
				$failures,
				$create_response instanceof \WP_REST_Response
					&& 200 === $create_response->get_status()
					&& 0 < $autosave_id
					&& $parent_id !== $autosave_id
					&& $parent_id === (int) ( $create_data['parent'] ?? 0 )
					&& $created_autosave instanceof \WP_Post
					&& $lookup_autosave instanceof \WP_Post
					&& $autosave_id === (int) $lookup_autosave->ID
					&& $create_title === ( $create_data['title']['raw'] ?? null )
					&& $create_content === ( $create_data['content']['raw'] ?? null )
					&& $create_meta === ( $create_data['meta'][ $meta_key ] ?? null )
					&& $create_meta === $stored_create_meta
					&& $parent_after_create instanceof \WP_Post
					&& $case['titleFrom'] === $parent_after_create->post_title
					&& $case['contentFrom'] === $parent_after_create->post_content
					&& $parent_meta === $parent_after_create_meta
					&& $events_before_create === $events_after_create,
				"built-in {$post_type} REST autosave creation writes per-user revision fields and revisioned meta without mutating the parent",
				array(
					'response'       => self::response_summary( $create_response ),
					'autosave'       => self::post_summary( $created_autosave ),
					'lookup'         => self::post_summary( $lookup_autosave ),
					'parent'         => self::post_summary( $parent_after_create ),
					'storedMeta'     => $stored_create_meta,
					'parentMeta'     => $parent_after_create_meta,
					'eventDelta'     => $events_after_create - $events_before_create,
				)
			);

			$update_meta          = 'updated-meta-' . $post_type . '-' . $case['token'];
			$update_title         = $case['titleTo'] . " {$post_type} updated REST autosave";
			$update_content       = $case['contentTo'] . "\n{$post_type} updated REST autosave";
			$update_excerpt       = $case['excerptTo'] . " {$post_type} updated autosave";
			$events_before_update = count( $creating_autosaves );
			$update_response      = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,content.raw,meta,preview_link',
					),
					array(),
					array(
						'title'   => $update_title,
						'content' => $update_content,
						'excerpt' => $update_excerpt,
						'meta'    => array(
							$meta_key => $update_meta,
						),
					)
				)
			);
			$events_after_update = count( $creating_autosaves );
			$update_data         = $update_response instanceof \WP_REST_Response ? $update_response->get_data() : array();
			$updated_autosave    = \get_post( $autosave_id );
			$stored_update_meta  = \get_metadata_raw( 'post', $autosave_id, $meta_key, true );
			$update_event        = $creating_autosaves[ $events_before_update ] ?? array();

			self::collect_failure(
				$failures,
				$update_response instanceof \WP_REST_Response
					&& 200 === $update_response->get_status()
					&& $autosave_id === (int) ( $update_data['id'] ?? 0 )
					&& $updated_autosave instanceof \WP_Post
					&& $update_title === $updated_autosave->post_title
					&& $update_content === $updated_autosave->post_content
					&& $update_meta === ( $update_data['meta'][ $meta_key ] ?? null )
					&& $update_meta === $stored_update_meta
					&& $events_after_update === $events_before_update + 1
					&& $autosave_id === (int) ( $update_event['id'] ?? 0 )
					&& $parent_id === (int) ( $update_event['parent'] ?? 0 )
					&& $editor_id === (int) ( $update_event['author'] ?? 0 )
					&& $update_title === ( $update_event['title'] ?? null ),
				"built-in {$post_type} REST autosave update reuses the per-user autosave and fires wp_creating_autosave once",
				array(
					'response'   => self::response_summary( $update_response ),
					'autosave'   => self::post_summary( $updated_autosave ),
					'storedMeta' => $stored_update_meta,
					'event'      => $update_event,
					'eventDelta' => $events_after_update - $events_before_update,
				)
			);

			$events_before_noop = count( $creating_autosaves );
			$noop_response      = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,content.raw,meta',
					),
					array(),
					array(
						'title'   => $case['titleFrom'],
						'content' => $case['contentFrom'],
						'excerpt' => $case['excerptFrom'],
						'meta'    => array(
							$meta_key => $parent_meta,
						),
					)
				)
			);
			$events_after_noop = count( $creating_autosaves );
			$noop_data         = $noop_response instanceof \WP_REST_Response ? $noop_response->get_data() : array();
			$stored_noop_meta  = \get_metadata_raw( 'post', $autosave_id, $meta_key, true );

			self::collect_failure(
				$failures,
				$noop_response instanceof \WP_REST_Response
					&& 200 === $noop_response->get_status()
					&& $autosave_id === (int) ( $noop_data['id'] ?? 0 )
					&& $update_title === ( $noop_data['title']['raw'] ?? null )
					&& $update_meta === ( $noop_data['meta'][ $meta_key ] ?? null )
					&& $update_meta === $stored_noop_meta
					&& $events_before_noop === $events_after_noop,
				"built-in {$post_type} REST autosave no-op returns the existing autosave without firing update hooks",
				array(
					'response'   => self::response_summary( $noop_response ),
					'storedMeta' => $stored_noop_meta,
					'eventDelta' => $events_after_noop - $events_before_noop,
				)
			);

			$other_meta        = 'other-autosave-meta-' . $post_type . '-' . $case['token'];
			$other_autosave_id = self::insert_revision_row(
				$parent_id,
				$other_user_id,
				array(
					'post_title'        => $case['titleFrom'] . " {$post_type} other REST autosave",
					'post_content'      => $case['contentFrom'] . "\n{$post_type} other REST autosave",
					'post_excerpt'      => $case['excerptFrom'],
					'post_date'         => self::offset_mysql_date( $case['dateLater'], 13 ),
					'post_date_gmt'     => self::offset_mysql_date( $case['dateLaterGmt'], 13 ),
					'post_modified'     => self::offset_mysql_date( $case['dateLater'], 13 ),
					'post_modified_gmt' => self::offset_mysql_date( $case['dateLaterGmt'], 13 ),
				),
				true
			);
			\update_metadata( 'post', $other_autosave_id, $meta_key, $other_meta );
			$advisory_response = self::dispatch(
				$server,
				self::request(
					'GET',
					$autosave_route . '/' . $other_autosave_id,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,meta,preview_link',
					)
				)
			);
			$advisory_data     = $advisory_response instanceof \WP_REST_Response ? $advisory_response->get_data() : array();

			self::collect_failure(
				$failures,
				$advisory_response instanceof \WP_REST_Response
					&& 200 === $advisory_response->get_status()
					&& $autosave_id === (int) ( $advisory_data['id'] ?? 0 )
					&& $other_autosave_id !== (int) ( $advisory_data['id'] ?? 0 )
					&& $parent_id === (int) ( $advisory_data['parent'] ?? 0 )
					&& $update_meta === ( $advisory_data['meta'][ $meta_key ] ?? null )
					&& is_string( $advisory_data['preview_link'] ?? null )
					&& '' !== ( $advisory_data['preview_link'] ?? '' ),
				"built-in {$post_type} REST autosave item route returns the current user's autosave even when another autosave ID is passed",
				array(
					'otherAutosaveId' => $other_autosave_id,
					'response'        => self::response_summary( $advisory_response ),
				)
			);

			$projection_meta = 'projected-revision-meta-' . $post_type . '-' . $case['token'];
			$parent_later_meta = 'parent-current-meta-' . $post_type . '-' . $case['token'];
			\update_post_meta( $parent_id, $meta_key, $projection_meta );
			$revision_id = self::insert_revision_row(
				$parent_id,
				$author_id,
				array(
					'post_title'        => $case['titleTo'] . " {$post_type} projected REST revision",
					'post_content'      => $case['contentTo'] . "\n{$post_type} projected REST revision",
					'post_excerpt'      => $case['excerptTo'],
					'post_date'         => self::offset_mysql_date( $case['dateLater'], 17 ),
					'post_date_gmt'     => self::offset_mysql_date( $case['dateLaterGmt'], 17 ),
					'post_modified'     => self::offset_mysql_date( $case['dateLater'], 17 ),
					'post_modified_gmt' => self::offset_mysql_date( $case['dateLaterGmt'], 17 ),
				),
				false
			);
			\wp_save_revisioned_meta_fields( $revision_id, $parent_id );
			$stored_revision_meta = \get_metadata_raw( 'post', $revision_id, $meta_key, true );
			\update_post_meta( $parent_id, $meta_key, $parent_later_meta );
			$revision_response = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_route . '/' . $revision_id,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,meta',
					)
				)
			);
			$revision_data       = $revision_response instanceof \WP_REST_Response ? $revision_response->get_data() : array();
			$parent_current_meta = \get_metadata_raw( 'post', $parent_id, $meta_key, true );

			self::collect_failure(
				$failures,
				$revision_response instanceof \WP_REST_Response
					&& 200 === $revision_response->get_status()
					&& $revision_id === (int) ( $revision_data['id'] ?? 0 )
					&& $parent_id === (int) ( $revision_data['parent'] ?? 0 )
					&& $projection_meta === ( $revision_data['meta'][ $meta_key ] ?? null )
					&& $projection_meta === $stored_revision_meta
					&& $parent_later_meta === $parent_current_meta,
				"built-in {$post_type} REST revision item projects revisioned meta from the revision row instead of current parent meta",
				array(
					'response'            => self::response_summary( $revision_response ),
					'storedRevisionMeta'  => $stored_revision_meta,
					'parentCurrentMeta'   => $parent_current_meta,
				)
			);

			$autosave_revision_response = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_route . '/' . $autosave_id,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,meta',
					)
				)
			);
			$autosave_revision_data = $autosave_revision_response instanceof \WP_REST_Response ? $autosave_revision_response->get_data() : array();
			self::collect_failure(
				$failures,
				$autosave_revision_response instanceof \WP_REST_Response
					&& 200 === $autosave_revision_response->get_status()
					&& $autosave_id === (int) ( $autosave_revision_data['id'] ?? 0 )
					&& $parent_id === (int) ( $autosave_revision_data['parent'] ?? 0 )
					&& $update_meta === ( $autosave_revision_data['meta'][ $meta_key ] ?? null ),
				"built-in {$post_type} REST revision item projection also exposes revisioned meta for autosave revisions",
				array( 'response' => self::response_summary( $autosave_revision_response ) )
			);

			$entry += array(
				'draftId'         => $draft_id,
				'parentId'        => $parent_id,
				'autosaveId'      => $autosave_id,
				'otherAutosaveId' => $other_autosave_id,
				'revisionId'      => $revision_id,
				'metaKey'         => $meta_key,
			);
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				"built-in {$post_type} REST autosave/meta mutation branch stays throwable-free",
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( $author_caps instanceof \Closure ) {
				\remove_filter( 'user_has_cap', $author_caps, 10 );
			}
			if ( $editor_caps instanceof \Closure ) {
				\remove_filter( 'user_has_cap', $editor_caps, 10 );
			}
			if ( true === $registered ) {
				\unregister_meta_key( 'post', $meta_key, $post_type );
			}
			\wp_set_current_user( 0 );
		}

		return $entry;
	}

	private static function check_rest_autosave_negative_write_and_malformed_meta_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$missing = self::rest_autosave_meta_child_missing_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'revisions-autosaves.rest-autosave-negative-subprocess-requirements',
				'Local PHP subprocess support is unavailable for isolated REST autosave negative write coverage.',
				array( 'missing' => $missing )
			);
		}

		$parent_doing_autosave_before = array(
			'defined' => defined( 'DOING_AUTOSAVE' ),
			'value'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
		);
		$run = self::run_rest_autosave_negative_child( $case );
		$parent_doing_autosave_after = array(
			'defined' => defined( 'DOING_AUTOSAVE' ),
			'value'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
		);

		$result   = is_array( $run['result'] ?? null ) ? $run['result'] : array();
		$matrix   = is_array( $result['matrix'] ?? null ) ? $result['matrix'] : array();
		$failures = array();

		self::collect_failure(
			$failures,
			0 === (int) ( $run['exitCode'] ?? -1 )
				&& '' === (string) ( $run['stderr'] ?? '' )
				&& is_array( $run['result'] ?? null )
				&& true === ( $run['ok'] ?? null ),
			'REST autosave negative child returns structured JSON without stderr or invariant failures',
			array(
				'exitCode'      => $run['exitCode'] ?? null,
				'stdout'        => self::describe_output( (string) ( $run['stdout'] ?? '' ) ),
				'stderr'        => self::describe_output( (string) ( $run['stderr'] ?? '' ) ),
				'childFailures' => $result['failures'] ?? null,
			)
		);
		self::collect_failure(
			$failures,
			true === ( $result['wpRunCoreTests'] ?? null )
				&& false === ( $result['doingAutosaveDefined'] ?? true ),
			'REST autosave negative child defines WP_RUN_CORE_TESTS before bootstrap and does not define DOING_AUTOSAVE',
			array(
				'wpRunCoreTests'       => $result['wpRunCoreTests'] ?? null,
				'doingAutosaveDefined' => $result['doingAutosaveDefined'] ?? null,
				'doingAutosaveValue'   => $result['doingAutosaveValue'] ?? null,
			)
		);
		self::collect_failure(
			$failures,
			$parent_doing_autosave_before === $parent_doing_autosave_after,
			'REST autosave negative child leaves parent DOING_AUTOSAVE constant state unchanged',
			array(
				'before' => $parent_doing_autosave_before,
				'after'  => $parent_doing_autosave_after,
			)
		);
		self::collect_failure(
			$failures,
			array( 'page', 'post' ) === array_values(
				array_intersect(
					array( 'page', 'post' ),
					array_map(
						static fn( array $entry ): string => (string) ( $entry['postType'] ?? '' ),
						$matrix
					)
				)
			),
			'REST autosave negative child covers built-in post and page write/meta boundaries',
			array( 'matrix' => $matrix )
		);

		return self::result(
			$ctx,
			'revisions-autosaves.rest-autosave-negative-write-and-malformed-meta-boundaries',
			$failures,
			array(
				'case'     => self::case_summary( $case ),
				'exitCode' => $run['exitCode'] ?? null,
				'matrix'   => $matrix,
			)
		);
	}

	private static function run_rest_autosave_negative_child( array $case ): array {
		$payload = json_encode(
			array( 'case' => $case ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( false === $payload ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'json_encode failed',
				'result'   => null,
			);
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates REST autosave write requests that would otherwise define process-wide constants.
		$process = proc_open( array( PHP_BINARY, '-r', self::rest_autosave_negative_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		fwrite( $pipes[0], $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		$result    = json_decode( (string) $stdout, true );

		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && true === ( $result['ok'] ?? null ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function rest_autosave_negative_child_program(): string {
		return <<<'PHP'
if ( ! defined( 'WP_RUN_CORE_TESTS' ) ) {
	define( 'WP_RUN_CORE_TESTS', true );
}

ini_set( 'display_errors', '0' );
ob_start();

$result = array(
	'ok'       => false,
	'failures' => array(),
);

try {
	$raw     = stream_get_contents( STDIN );
	$payload = json_decode( $raw, true );
	$case    = is_array( $payload['case'] ?? null ) ? $payload['case'] : array();

	require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
	\ComponentFuzz\WpBootstrap::load();

	$result = \ComponentFuzz\Surfaces\RevisionsAutosavesSurface::rest_autosave_negative_child_entry( $case );
} catch ( Throwable $e ) {
	$result = array(
		'ok'       => false,
		'failures' => array(
			array(
				'label'   => 'REST autosave negative child catches top-level throwables',
				'details' => array(
					'class'   => get_class( $e ),
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				),
			),
		),
	);
}

$output = ob_get_clean();
if ( '' !== $output ) {
	$result['ok']         = false;
	$result['failures'][] = array(
		'label'   => 'REST autosave negative child produces no incidental output before JSON',
		'details' => array(
			'bytes'   => strlen( $output ),
			'sha1'    => sha1( $output ),
			'preview' => substr( $output, 0, 220 ),
		),
	);
}

$json = json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
echo false === $json ? '{"ok":false,"failures":[{"label":"REST autosave negative child JSON encoding failed","details":[]}]}' : $json;
PHP;
	}

	public static function rest_autosave_negative_child_entry( array $case ): array {
		self::load_rest_endpoint_classes();

		$failures = array();
		$missing  = self::missing_requirements();
		foreach ( array( 'get_current_user_id', 'get_metadata_raw', 'unregister_meta_key' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( array() !== $missing ) {
			return array(
				'ok'                   => false,
				'failures'             => array(
					array(
						'label'   => 'REST autosave negative child has required WordPress APIs',
						'details' => array( 'missing' => $missing ),
					),
				),
				'wpRunCoreTests'       => defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS,
				'doingAutosaveDefined' => defined( 'DOING_AUTOSAVE' ),
				'doingAutosaveValue'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
				'matrix'               => array(),
			);
		}

		self::prepare_runtime();
		$server = self::fresh_rest_server();
		$matrix = array();

		try {
			foreach ( array( 'post' => 'posts', 'page' => 'pages' ) as $post_type => $rest_base ) {
				$matrix[] = self::rest_autosave_negative_child_post_type( $server, $case, $post_type, $rest_base, $failures );
			}
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				'REST autosave negative child keeps built-in route matrix throwable-free',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			\wp_set_current_user( 0 );
		}

		return array(
			'ok'                   => array() === $failures,
			'failures'             => array_slice( $failures, 0, 8 ),
			'wpRunCoreTests'       => defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS,
			'doingAutosaveDefined' => defined( 'DOING_AUTOSAVE' ),
			'doingAutosaveValue'   => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
			'matrix'               => $matrix,
		);
	}

	private static function rest_autosave_negative_child_post_type( \WP_REST_Server $server, array $case, string $post_type, string $rest_base, array &$failures ): array {
		$target_case = array_merge(
			$case,
			array(
				'postType' => $post_type,
				'slug'     => $case['slug'] . '-autosave-negative-' . $post_type,
			)
		);
		$entry       = array(
			'postType' => $post_type,
			'restBase' => $rest_base,
		);
		$revisioned_meta_key    = 'cf_rest_autosave_neg_' . $post_type . '_' . $case['token'];
		$non_revisioned_meta_key = 'cf_rest_nonrev_' . $post_type . '_' . $case['token'];
		$registered_revisioned  = false;
		$registered_non_revisioned = false;
		$editor_caps            = null;

		try {
			$post_type_object = \get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				self::collect_failure(
					$failures,
					false,
					"built-in {$post_type} post type exists for REST autosave negative checks",
					array( 'postType' => $post_type )
				);
				return $entry;
			}

			$registered_revisioned = \register_post_meta(
				$post_type,
				$revisioned_meta_key,
				array(
					'auth_callback'     => '__return_true',
					'revisions_enabled' => true,
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);
			$registered_non_revisioned = \register_post_meta(
				$post_type,
				$non_revisioned_meta_key,
				array(
					'auth_callback'     => '__return_true',
					'revisions_enabled' => false,
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);

			self::collect_failure(
				$failures,
				true === $registered_revisioned
					&& true === $registered_non_revisioned
					&& in_array( $revisioned_meta_key, \wp_post_revision_meta_keys( $post_type ), true )
					&& ! in_array( $non_revisioned_meta_key, \wp_post_revision_meta_keys( $post_type ), true ),
				"built-in {$post_type} REST meta schema distinguishes revisioned and non-revisioned keys",
				array(
					'revisionedRegistered'    => $registered_revisioned,
					'nonRevisionedRegistered' => $registered_non_revisioned,
					'revisionedMetaKey'       => $revisioned_meta_key,
					'nonRevisionedMetaKey'    => $non_revisioned_meta_key,
					'revisionMetaKeys'        => \wp_post_revision_meta_keys( $post_type ),
				)
			);

			$parent_controller   = $post_type_object->get_rest_controller();
			$revision_controller = $post_type_object->get_revisions_rest_controller();
			$autosave_controller = $post_type_object->get_autosave_rest_controller();
			if ( $parent_controller ) {
				$parent_controller->register_routes();
			}
			if ( $revision_controller ) {
				$revision_controller->register_routes();
			}
			if ( $autosave_controller ) {
				$autosave_controller->register_routes();
			}

			$author_id     = self::insert_author( $target_case, "rest-autosave-negative-{$post_type}-author" );
			$editor_id     = self::insert_author( $target_case, "rest-autosave-negative-{$post_type}-editor" );
			$subscriber_id = self::insert_author( $target_case, "rest-autosave-negative-{$post_type}-subscriber" );
			$parent_id     = self::insert_parent_post( $target_case, $author_id, "negative-parent-{$post_type}" );
			$other_case    = array_merge(
				$target_case,
				array(
					'postType' => 'post' === $post_type ? 'page' : 'post',
					'slug'     => $target_case['slug'] . '-cross',
				)
			);
			$other_parent_id = self::insert_parent_post( $other_case, $author_id, "negative-cross-{$post_type}" );
			$revision_id = self::insert_revision_row(
				$parent_id,
				$author_id,
				array(
					'post_title'        => $case['titleTo'] . " {$post_type} negative revision",
					'post_content'      => $case['contentTo'] . "\n{$post_type} negative revision",
					'post_excerpt'      => $case['excerptTo'],
					'post_date'         => $case['dateTo'],
					'post_date_gmt'     => $case['dateToGmt'],
					'post_modified'     => $case['dateTo'],
					'post_modified_gmt' => $case['dateToGmt'],
				),
				false
			);

			$autosave_route = '/wp/v2/' . $rest_base . '/' . $parent_id . '/autosaves';
			$revision_route = '/wp/v2/' . $rest_base . '/' . $parent_id . '/revisions';
			$editor_caps    = self::grant_all_caps_filter( $editor_id );

			\wp_set_current_user( 0 );
			$counts_before_denied = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();
			$anonymous_post = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'title'   => $case['titleTo'] . " {$post_type} anonymous autosave",
						'content' => $case['contentTo'],
					)
				)
			);
			$anonymous_revisions = self::dispatch( $server, self::request( 'GET', $revision_route, array( 'context' => 'edit' ) ) );
			$anonymous_delete    = self::dispatch(
				$server,
				self::request(
					'DELETE',
					$revision_route . '/' . $revision_id,
					array(
						'context' => 'edit',
						'force'   => true,
					)
				)
			);
			$counts_after_anonymous = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();

			\wp_set_current_user( $subscriber_id );
			$subscriber_post = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'title'   => $case['titleTo'] . " {$post_type} subscriber autosave",
						'content' => $case['contentTo'],
					)
				)
			);
			$subscriber_autosaves = self::dispatch( $server, self::request( 'GET', $autosave_route, array( 'context' => 'edit' ) ) );
			$subscriber_delete    = self::dispatch(
				$server,
				self::request(
					'DELETE',
					$revision_route . '/' . $revision_id,
					array(
						'context' => 'edit',
						'force'   => true,
					)
				)
			);
			$counts_after_subscriber = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();

			self::collect_failure(
				$failures,
				self::response_error_ok( $anonymous_post, 'rest_cannot_edit', 401 )
					&& self::response_error_ok( $anonymous_revisions, 'rest_cannot_read', 401 )
					&& self::response_error_ok( $anonymous_delete, 'rest_cannot_delete', 401 )
					&& $counts_before_denied === $counts_after_anonymous
					&& $counts_after_anonymous === $counts_after_subscriber
					&& self::response_error_ok( $subscriber_post, 'rest_cannot_edit', 403 )
					&& self::response_error_ok( $subscriber_autosaves, 'rest_cannot_read', 403 )
					&& self::response_error_ok( $subscriber_delete, 'rest_cannot_delete', 403 ),
				"built-in {$post_type} revision/autosave write and read routes fail closed for anonymous and logged-in users without edit/delete caps",
				array(
					'anonymousPost'       => self::response_summary( $anonymous_post ),
					'anonymousRevisions'  => self::response_summary( $anonymous_revisions ),
					'anonymousDelete'     => self::response_summary( $anonymous_delete ),
					'subscriberPost'      => self::response_summary( $subscriber_post ),
					'subscriberAutosaves' => self::response_summary( $subscriber_autosaves ),
					'subscriberDelete'    => self::response_summary( $subscriber_delete ),
					'countsBefore'        => $counts_before_denied,
					'countsAfterAnonymous' => $counts_after_anonymous,
					'countsAfterSubscriber' => $counts_after_subscriber,
				)
			);

			\wp_set_current_user( $editor_id );
			\add_filter( 'user_has_cap', $editor_caps, 10, 4 );

			$invalid_autosave_id = self::dispatch(
				$server,
				self::request(
					'POST',
					'/wp/v2/' . $rest_base . '/0/autosaves',
					array( 'context' => 'edit' ),
					array(),
					array( 'title' => $case['titleTo'] )
				)
			);
			$cross_type_post = self::dispatch(
				$server,
				self::request(
					'POST',
					'/wp/v2/' . $rest_base . '/' . $other_parent_id . '/autosaves',
					array( 'context' => 'edit' ),
					array(),
					array( 'title' => $case['titleTo'] )
				)
			);
			$cross_type_get = self::dispatch( $server, self::request( 'GET', '/wp/v2/' . $rest_base . '/' . $other_parent_id . '/autosaves', array( 'context' => 'edit' ) ) );
			$missing_autosave = self::dispatch(
				$server,
				self::request(
					'GET',
					$autosave_route . '/999999',
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,meta',
					)
				)
			);
			$mismatched_revision = self::dispatch( $server, self::request( 'GET', $revision_route . '/999999', array( 'context' => 'edit' ) ) );

			self::collect_failure(
				$failures,
				self::response_error_ok( $invalid_autosave_id, 'rest_post_invalid_id', 404 )
					&& self::response_error_ok( $cross_type_post, 'rest_post_invalid_id', 404 )
					&& self::response_error_ok( $cross_type_get, 'rest_post_invalid_parent', 404 )
					&& self::response_error_ok( $missing_autosave, 'rest_post_no_autosave', 404 )
					&& self::response_error_ok( $mismatched_revision, 'rest_post_invalid_id', 404 ),
				"built-in {$post_type} revision/autosave routes distinguish invalid item IDs, cross-type parents, and missing autosaves",
				array(
					'invalidAutosaveId' => self::response_summary( $invalid_autosave_id ),
					'crossTypePost'     => self::response_summary( $cross_type_post ),
					'crossTypeGet'      => self::response_summary( $cross_type_get ),
					'missingAutosave'   => self::response_summary( $missing_autosave ),
					'missingRevision'   => self::response_summary( $mismatched_revision ),
				)
			);

			$counts_before_invalid_meta = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();
			$meta_not_array = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'title'   => $case['titleTo'] . " {$post_type} scalar meta",
						'content' => $case['contentTo'],
						'meta'    => 'not-an-array-' . $case['token'],
					)
				)
			);
			$counts_after_meta_not_array = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();

			self::collect_failure(
				$failures,
				self::response_error_ok( $meta_not_array, 'rest_invalid_param', 400 )
					&& $counts_before_invalid_meta === $counts_after_meta_not_array
					&& false === \wp_get_post_autosave( $parent_id, $editor_id ),
				"built-in {$post_type} autosave POST rejects non-object meta payloads before creating autosave rows",
				array(
					'metaNotArray' => self::response_summary( $meta_not_array ),
					'countsBefore' => $counts_before_invalid_meta,
					'countsAfter'  => $counts_after_meta_not_array,
					'autosave'     => self::post_summary( \wp_get_post_autosave( $parent_id, $editor_id ) ),
				)
			);

			$meta_wrong_list_type = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'title'   => $case['titleTo'] . " {$post_type} array meta",
						'content' => $case['contentTo'],
						'meta'    => array(
							$revisioned_meta_key => array( 'array-value-' . $case['token'] ),
						),
					)
				)
			);
			$wrong_list_data        = $meta_wrong_list_type instanceof \WP_REST_Response ? $meta_wrong_list_type->get_data() : array();
			$wrong_list_autosave_id = (int) ( $wrong_list_data['id'] ?? 0 );
			$stored_wrong_list_meta = \get_metadata_raw( 'post', $wrong_list_autosave_id, $revisioned_meta_key, true );
			$meta_wrong_object_type = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'title'   => $case['titleTo'] . " {$post_type} object meta",
						'content' => $case['contentTo'],
						'meta'    => array(
							$revisioned_meta_key => array( 'nested' => 'object-value-' . $case['token'] ),
						),
					)
				)
			);
			$wrong_object_data        = $meta_wrong_object_type instanceof \WP_REST_Response ? $meta_wrong_object_type->get_data() : array();
			$wrong_object_autosave_id = (int) ( $wrong_object_data['id'] ?? 0 );
			$stored_wrong_object_meta = \get_metadata_raw( 'post', $wrong_object_autosave_id, $revisioned_meta_key, true );
			$parent_wrong_type_meta   = \get_metadata_raw( 'post', $parent_id, $revisioned_meta_key, true );
			$counts_after_type_boundary = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();

			self::collect_failure(
				$failures,
				$meta_wrong_list_type instanceof \WP_REST_Response
					&& 200 === $meta_wrong_list_type->get_status()
					&& $meta_wrong_object_type instanceof \WP_REST_Response
					&& 200 === $meta_wrong_object_type->get_status()
					&& 0 < $wrong_list_autosave_id
					&& $wrong_list_autosave_id === $wrong_object_autosave_id
					&& array( 'array-value-' . $case['token'] ) === $stored_wrong_list_meta
					&& array( 'nested' => 'object-value-' . $case['token'] ) === $stored_wrong_object_meta
					&& null === ( $wrong_list_data['meta'][ $revisioned_meta_key ] ?? null )
					&& null === ( $wrong_object_data['meta'][ $revisioned_meta_key ] ?? null )
					&& null === $parent_wrong_type_meta
					&& $counts_after_type_boundary['posts'] === $counts_after_meta_not_array['posts'] + 1,
				"built-in {$post_type} autosave POST accepts wrong-typed revisioned meta values as raw revision meta while REST projection nulls invalid stored values",
				array(
					'metaWrongListType'   => self::response_summary( $meta_wrong_list_type ),
					'metaWrongObjectType' => self::response_summary( $meta_wrong_object_type ),
					'storedListMeta'      => $stored_wrong_list_meta,
					'storedObjectMeta'    => $stored_wrong_object_meta,
					'parentMeta'          => $parent_wrong_type_meta,
					'countsAfterScalar'   => $counts_after_meta_not_array,
					'countsAfterTypeBoundary' => $counts_after_type_boundary,
				)
			);

			$unknown_meta_key = 'cf_rest_unknown_' . $post_type . '_' . $case['token'];
			$ignored_title    = $case['titleTo'] . " {$post_type} ignored meta autosave";
			$ignored_content  = $case['contentTo'] . "\n{$post_type} ignored meta autosave";
			$ignored_response = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,content.raw,meta',
					),
					array(),
					array(
						'title'   => $ignored_title,
						'content' => $ignored_content,
						'meta'    => array(
							$unknown_meta_key         => 'unknown-meta-' . $case['token'],
							$non_revisioned_meta_key  => 'non-revisioned-meta-' . $case['token'],
							$revisioned_meta_key      => 'revisioned-meta-' . $case['token'],
						),
					)
				)
			);
			$ignored_data = $ignored_response instanceof \WP_REST_Response ? $ignored_response->get_data() : array();
			$autosave_id  = (int) ( $ignored_data['id'] ?? 0 );
			$stored_revisioned_meta = \get_metadata_raw( 'post', $autosave_id, $revisioned_meta_key, true );
			$stored_non_revisioned_meta = \get_metadata_raw( 'post', $autosave_id, $non_revisioned_meta_key, true );
			$stored_unknown_meta = \get_metadata_raw( 'post', $autosave_id, $unknown_meta_key, true );
			$parent_revisioned_meta = \get_metadata_raw( 'post', $parent_id, $revisioned_meta_key, true );
			$parent_non_revisioned_meta = \get_metadata_raw( 'post', $parent_id, $non_revisioned_meta_key, true );

			self::collect_failure(
				$failures,
				$ignored_response instanceof \WP_REST_Response
					&& 200 === $ignored_response->get_status()
					&& 0 < $autosave_id
					&& $parent_id === (int) ( $ignored_data['parent'] ?? 0 )
					&& $ignored_title === ( $ignored_data['title']['raw'] ?? null )
					&& $ignored_content === ( $ignored_data['content']['raw'] ?? null )
					&& 'revisioned-meta-' . $case['token'] === ( $ignored_data['meta'][ $revisioned_meta_key ] ?? null )
					&& 'revisioned-meta-' . $case['token'] === $stored_revisioned_meta
					&& null === $stored_non_revisioned_meta
					&& null === $stored_unknown_meta
					&& null === $parent_revisioned_meta
					&& null === $parent_non_revisioned_meta,
				"built-in {$post_type} autosave POST ignores unregistered and non-revisioned REST meta while persisting only revisioned meta on the autosave revision",
				array(
					'response'              => self::response_summary( $ignored_response ),
					'autosaveId'            => $autosave_id,
					'storedRevisionedMeta'  => $stored_revisioned_meta,
					'storedNonRevisionedMeta' => $stored_non_revisioned_meta,
					'storedUnknownMeta'     => $stored_unknown_meta,
					'parentRevisionedMeta'  => $parent_revisioned_meta,
					'parentNonRevisionedMeta' => $parent_non_revisioned_meta,
				)
			);

			$stored_before_invalid_update = \get_metadata_raw( 'post', $autosave_id, $revisioned_meta_key, true );
			$content_before_invalid_update = \get_post( $autosave_id );
			$invalid_update_response = self::dispatch(
				$server,
				self::request(
					'POST',
					$autosave_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'title'   => $case['titleTo'] . " {$post_type} invalid update",
						'content' => $case['contentTo'] . "\n{$post_type} invalid update",
						'meta'    => array(
							$revisioned_meta_key => array( 'still' => 'wrong' ),
						),
					)
				)
			);
			$stored_after_invalid_update = \get_metadata_raw( 'post', $autosave_id, $revisioned_meta_key, true );
			$content_after_invalid_update = \get_post( $autosave_id );

			self::collect_failure(
				$failures,
				$invalid_update_response instanceof \WP_REST_Response
					&& 200 === $invalid_update_response->get_status()
					&& array( 'still' => 'wrong' ) === $stored_after_invalid_update
					&& $content_before_invalid_update instanceof \WP_Post
					&& $content_after_invalid_update instanceof \WP_Post
					&& $case['titleTo'] . " {$post_type} invalid update" === $content_after_invalid_update->post_title
					&& $case['contentTo'] . "\n{$post_type} invalid update" === $content_after_invalid_update->post_content
					&& null === ( $invalid_update_response->get_data()['meta'][ $revisioned_meta_key ] ?? null ),
				"built-in {$post_type} malformed revisioned meta update overwrites the autosave raw meta while REST projection exposes null for the invalid stored value",
				array(
					'response'    => self::response_summary( $invalid_update_response ),
					'metaBefore'  => $stored_before_invalid_update,
					'metaAfter'   => $stored_after_invalid_update,
					'beforePost'  => self::post_summary( $content_before_invalid_update ),
					'afterPost'   => self::post_summary( $content_after_invalid_update ),
				)
			);

			$entry += array(
				'parentId'              => $parent_id,
				'crossTypeParentId'     => $other_parent_id,
				'revisionId'            => $revision_id,
				'autosaveId'            => $autosave_id,
				'revisionedMetaKey'     => $revisioned_meta_key,
				'nonRevisionedMetaKey'  => $non_revisioned_meta_key,
			);
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				"built-in {$post_type} REST autosave negative/meta boundary branch stays throwable-free",
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( $editor_caps instanceof \Closure ) {
				\remove_filter( 'user_has_cap', $editor_caps, 10 );
			}
			if ( true === $registered_revisioned ) {
				\unregister_meta_key( 'post', $revisioned_meta_key, $post_type );
			}
			if ( true === $registered_non_revisioned ) {
				\unregister_meta_key( 'post', $non_revisioned_meta_key, $post_type );
			}
			\wp_set_current_user( 0 );
		}

		return $entry;
	}

	private static function check_rest_parent_meta_validation_contrast( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		$server = self::fresh_rest_server();

		$failures = array();
		$matrix   = array();

		foreach ( array( 'post' => 'posts', 'page' => 'pages' ) as $post_type => $rest_base ) {
			$matrix[] = self::rest_parent_meta_validation_contrast_post_type( $server, $case, $post_type, $rest_base, $failures );
		}

		return self::result(
			$ctx,
			'revisions-autosaves.rest-parent-meta-validation-contrast',
			$failures,
			array(
				'case'   => self::case_summary( $case ),
				'matrix' => $matrix,
			)
		);
	}

	private static function rest_parent_meta_validation_contrast_post_type( \WP_REST_Server $server, array $case, string $post_type, string $rest_base, array &$failures ): array {
		$target_case = array_merge(
			$case,
			array(
				'postType' => $post_type,
				'slug'     => $case['slug'] . '-parent-meta-contrast-' . $post_type,
			)
		);
		$entry       = array(
			'postType' => $post_type,
			'restBase' => $rest_base,
		);
		$string_key  = 'cf_rest_parent_string_' . $post_type . '_' . $case['token'];
		$object_key  = 'cf_rest_parent_object_' . $post_type . '_' . $case['token'];
		$denied_key  = 'cf_rest_parent_denied_' . $post_type . '_' . $case['token'];
		$registered  = array();
		$editor_caps = null;

		try {
			$post_type_object = \get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				self::collect_failure(
					$failures,
					false,
					"built-in {$post_type} post type exists for REST parent meta validation contrast",
					array( 'postType' => $post_type )
				);
				return $entry;
			}

			$registered[ $string_key ] = \register_post_meta(
				$post_type,
				$string_key,
				array(
					'auth_callback'     => '__return_true',
					'revisions_enabled' => true,
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);
			$registered[ $object_key ] = \register_post_meta(
				$post_type,
				$object_key,
				array(
					'auth_callback' => '__return_true',
					'show_in_rest'  => array(
						'schema' => array(
							'additionalProperties' => false,
							'properties'           => array(
								'name' => array( 'type' => 'string' ),
							),
							'required'             => array( 'name' ),
							'type'                 => 'object',
						),
					),
					'single'        => true,
					'type'          => 'object',
				)
			);
			$registered[ $denied_key ] = \register_post_meta(
				$post_type,
				$denied_key,
				array(
					'auth_callback'     => '__return_false',
					'revisions_enabled' => true,
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);

			self::collect_failure(
				$failures,
				true === $registered[ $string_key ]
					&& true === $registered[ $object_key ]
					&& true === $registered[ $denied_key ]
					&& in_array( $string_key, \wp_post_revision_meta_keys( $post_type ), true )
					&& in_array( $denied_key, \wp_post_revision_meta_keys( $post_type ), true ),
				"built-in {$post_type} parent REST meta keys register before route schemas are cached",
				array(
					'registered'       => $registered,
					'stringKey'        => $string_key,
					'objectKey'        => $object_key,
					'deniedKey'        => $denied_key,
					'revisionMetaKeys' => \wp_post_revision_meta_keys( $post_type ),
				)
			);

			$parent_controller   = $post_type_object->get_rest_controller();
			$revision_controller = $post_type_object->get_revisions_rest_controller();
			$autosave_controller = $post_type_object->get_autosave_rest_controller();
			if ( $parent_controller ) {
				$parent_controller->register_routes();
			}
			if ( $revision_controller ) {
				$revision_controller->register_routes();
			}
			if ( $autosave_controller ) {
				$autosave_controller->register_routes();
			}

			$author_id  = self::insert_author( $target_case, "rest-parent-meta-contrast-{$post_type}-author" );
			$editor_id  = self::insert_author( $target_case, "rest-parent-meta-contrast-{$post_type}-editor" );
			$parent_id  = self::insert_parent_post( $target_case, $author_id, "parent-meta-contrast-{$post_type}" );
			$parent_route = '/wp/v2/' . $rest_base . '/' . $parent_id;
			$editor_caps  = self::grant_content_edit_caps_filter( $editor_id );

			\wp_set_current_user( $editor_id );
			\add_filter( 'user_has_cap', $editor_caps, 10, 4 );

			$valid_string = 'parent-string-' . $post_type . '-' . $case['token'];
			$valid_object = array( 'name' => 'parent-object-' . $post_type . '-' . $case['token'] );
			$valid_parent = self::dispatch(
				$server,
				self::request(
					'PUT',
					$parent_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,meta',
					),
					array(),
					array(
						'meta' => array(
							$string_key => $valid_string,
							$object_key => $valid_object,
						),
					)
				)
			);
			$valid_data = $valid_parent instanceof \WP_REST_Response ? $valid_parent->get_data() : array();

			self::collect_failure(
				$failures,
				$valid_parent instanceof \WP_REST_Response
					&& 200 === $valid_parent->get_status()
					&& $parent_id === (int) ( $valid_data['id'] ?? 0 )
					&& $valid_string === ( $valid_data['meta'][ $string_key ] ?? null )
					&& $valid_object === ( $valid_data['meta'][ $object_key ] ?? null )
					&& $valid_string === \get_metadata_raw( 'post', $parent_id, $string_key, true )
					&& $valid_object === \get_metadata_raw( 'post', $parent_id, $object_key, true ),
				"built-in {$post_type} parent REST update stores valid scalar and object meta",
				array(
					'response'     => self::response_summary( $valid_parent ),
					'storedString' => \get_metadata_raw( 'post', $parent_id, $string_key, true ),
					'storedObject' => \get_metadata_raw( 'post', $parent_id, $object_key, true ),
				)
			);

			$string_before_wrong_type = \get_metadata_raw( 'post', $parent_id, $string_key, true );
			$wrong_type_parent       = self::dispatch(
				$server,
				self::request(
					'PUT',
					$parent_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'meta' => array(
							$string_key => array( 'array-value-' . $case['token'] ),
						),
					)
				)
			);
			$string_after_wrong_type = \get_metadata_raw( 'post', $parent_id, $string_key, true );

			self::collect_failure(
				$failures,
				self::response_error_ok( $wrong_type_parent, 'rest_invalid_type', 400 )
					&& $string_before_wrong_type === $string_after_wrong_type
					&& false === \wp_get_post_autosave( $parent_id, $editor_id ),
				"built-in {$post_type} parent REST update rejects wrong-typed scalar meta without creating autosaves or mutating stored meta",
				array(
					'response'   => self::response_summary( $wrong_type_parent ),
					'beforeMeta' => $string_before_wrong_type,
					'afterMeta'  => $string_after_wrong_type,
					'autosave'   => self::post_summary( \wp_get_post_autosave( $parent_id, $editor_id ) ),
				)
			);

			$object_before_extra = \get_metadata_raw( 'post', $parent_id, $object_key, true );
			$object_extra_parent = self::dispatch(
				$server,
				self::request(
					'PUT',
					$parent_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'meta' => array(
							$object_key => array(
								'extra' => 'blocked-' . $case['token'],
								'name'  => 'object-extra-' . $case['token'],
							),
						),
					)
				)
			);
			$object_after_extra = \get_metadata_raw( 'post', $parent_id, $object_key, true );

			self::collect_failure(
				$failures,
				self::response_error_ok( $object_extra_parent, 'rest_additional_properties_forbidden', 400 )
					&& $object_before_extra === $object_after_extra,
				"built-in {$post_type} parent REST update rejects object meta properties outside the registered schema",
				array(
					'response'   => self::response_summary( $object_extra_parent ),
					'beforeMeta' => $object_before_extra,
					'afterMeta'  => $object_after_extra,
				)
			);

			$meta_not_array = self::dispatch(
				$server,
				self::request(
					'PUT',
					$parent_route,
					array( 'context' => 'edit' ),
					array(),
					array( 'meta' => 'not-an-array-' . $case['token'] )
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $meta_not_array, 'rest_invalid_param', 400 )
					&& $valid_string === \get_metadata_raw( 'post', $parent_id, $string_key, true )
					&& $valid_object === \get_metadata_raw( 'post', $parent_id, $object_key, true ),
				"built-in {$post_type} parent REST update rejects non-object meta payloads before changing registered meta",
				array(
					'response'     => self::response_summary( $meta_not_array ),
					'storedString' => \get_metadata_raw( 'post', $parent_id, $string_key, true ),
					'storedObject' => \get_metadata_raw( 'post', $parent_id, $object_key, true ),
				)
			);

			$denied_update = self::dispatch(
				$server,
				self::request(
					'PUT',
					$parent_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'meta' => array(
							$denied_key => 'blocked-update-' . $case['token'],
						),
					)
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $denied_update, 'rest_cannot_update', 403 )
					&& null === \get_metadata_raw( 'post', $parent_id, $denied_key, true ),
				"built-in {$post_type} parent REST update enforces meta auth_callback false for writes",
				array(
					'response'   => self::response_summary( $denied_update ),
					'deniedMeta' => \get_metadata_raw( 'post', $parent_id, $denied_key, true ),
				)
			);

			$denied_seed = 'seed-denied-' . $post_type . '-' . $case['token'];
			\update_post_meta( $parent_id, $denied_key, $denied_seed );
			$denied_delete = self::dispatch(
				$server,
				self::request(
					'PUT',
					$parent_route,
					array( 'context' => 'edit' ),
					array(),
					array(
						'meta' => array(
							$denied_key => null,
						),
					)
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $denied_delete, 'rest_cannot_delete', 403 )
					&& $denied_seed === \get_metadata_raw( 'post', $parent_id, $denied_key, true ),
				"built-in {$post_type} parent REST null delete enforces meta auth_callback false",
				array(
					'response'   => self::response_summary( $denied_delete ),
					'deniedMeta' => \get_metadata_raw( 'post', $parent_id, $denied_key, true ),
				)
			);

			$allowed_delete = self::dispatch(
				$server,
				self::request(
					'PUT',
					$parent_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,meta',
					),
					array(),
					array(
						'meta' => array(
							$string_key => null,
						),
					)
				)
			);
			$allowed_delete_data = $allowed_delete instanceof \WP_REST_Response ? $allowed_delete->get_data() : array();

			self::collect_failure(
				$failures,
				$allowed_delete instanceof \WP_REST_Response
					&& 200 === $allowed_delete->get_status()
					&& null === \get_metadata_raw( 'post', $parent_id, $string_key, true )
					&& '' === ( $allowed_delete_data['meta'][ $string_key ] ?? null ),
				"built-in {$post_type} parent REST null delete removes allowed scalar meta and projects the registered empty value",
				array(
					'response'   => self::response_summary( $allowed_delete ),
					'storedMeta' => \get_metadata_raw( 'post', $parent_id, $string_key, true ),
				)
			);

			if ( ! $autosave_controller instanceof \WP_REST_Autosaves_Controller ) {
				self::collect_failure(
					$failures,
					false,
					"built-in {$post_type} autosave controller is available for direct raw meta contrast",
					array( 'controller' => is_object( $autosave_controller ) ? get_class( $autosave_controller ) : $autosave_controller )
				);
				return $entry;
			}

			$raw_autosave_meta = array( 'raw' => 'autosave-raw-' . $post_type . '-' . $case['token'] );
			$raw_denied_meta   = 'autosave-denied-' . $post_type . '-' . $case['token'];
			$raw_autosave_id   = $autosave_controller->create_post_autosave(
				array(
					'ID'             => $parent_id,
					'post_author'    => $editor_id,
					'post_content'   => $case['contentTo'] . "\n{$post_type} raw meta contrast",
					'post_excerpt'   => $case['excerptTo'],
					'post_title'     => $case['titleTo'] . " {$post_type} raw meta contrast",
					'post_type'      => $post_type,
				),
				array(
					$string_key => $raw_autosave_meta,
					$denied_key => $raw_denied_meta,
				)
			);
			$raw_autosave      = is_int( $raw_autosave_id ) ? \get_post( $raw_autosave_id ) : null;
			$raw_stored_meta   = is_int( $raw_autosave_id ) ? \get_metadata_raw( 'post', $raw_autosave_id, $string_key, true ) : null;
			$raw_denied_stored = is_int( $raw_autosave_id ) ? \get_metadata_raw( 'post', $raw_autosave_id, $denied_key, true ) : null;
			$revision_response = is_int( $raw_autosave_id )
				? self::dispatch(
					$server,
					self::request(
						'GET',
						'/wp/v2/' . $rest_base . '/' . $parent_id . '/revisions/' . $raw_autosave_id,
						array(
							'context' => 'edit',
							'_fields' => 'id,parent,meta',
						)
					)
				)
				: null;
			$revision_data     = $revision_response instanceof \WP_REST_Response ? $revision_response->get_data() : array();

			self::collect_failure(
				$failures,
				is_int( $raw_autosave_id )
					&& $raw_autosave instanceof \WP_Post
					&& $parent_id === (int) $raw_autosave->post_parent
					&& $raw_autosave_meta === $raw_stored_meta
					&& $raw_denied_meta === $raw_denied_stored
					&& null === \get_metadata_raw( 'post', $parent_id, $string_key, true )
					&& $denied_seed === \get_metadata_raw( 'post', $parent_id, $denied_key, true )
					&& $revision_response instanceof \WP_REST_Response
					&& 200 === $revision_response->get_status()
					&& $raw_autosave_id === (int) ( $revision_data['id'] ?? 0 )
					&& $parent_id === (int) ( $revision_data['parent'] ?? 0 )
					&& null === ( $revision_data['meta'][ $string_key ] ?? null )
					&& $raw_denied_meta === ( $revision_data['meta'][ $denied_key ] ?? null ),
				"built-in {$post_type} direct autosave storage keeps raw wrong-typed revisioned meta and bypasses parent meta update auth",
				array(
					'autosaveId'       => $raw_autosave_id,
					'autosave'         => self::post_summary( $raw_autosave ),
					'storedMeta'       => $raw_stored_meta,
					'storedDeniedMeta' => $raw_denied_stored,
					'parentMeta'       => \get_metadata_raw( 'post', $parent_id, $string_key, true ),
					'parentDeniedMeta' => \get_metadata_raw( 'post', $parent_id, $denied_key, true ),
					'revisionResponse' => self::response_summary( $revision_response ),
				)
			);

			$entry += array(
				'parentId'    => $parent_id,
				'autosaveId'  => is_int( $raw_autosave_id ) ? $raw_autosave_id : null,
				'stringKey'   => $string_key,
				'objectKey'   => $object_key,
				'deniedKey'   => $denied_key,
			);
		} catch ( \Throwable $e ) {
			self::collect_failure(
				$failures,
				false,
				"built-in {$post_type} REST parent meta validation contrast branch stays throwable-free",
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( $editor_caps instanceof \Closure ) {
				\remove_filter( 'user_has_cap', $editor_caps, 10 );
			}
			foreach ( $registered as $meta_key => $did_register ) {
				if ( true === $did_register ) {
					\unregister_meta_key( 'post', $meta_key, $post_type );
				}
			}
			\wp_set_current_user( 0 );
		}

		return $entry;
	}

	private static function check_rest_revision_autosave_batch_gates( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_runtime();
		$rest_base = self::register_rest_case_post_type( $case );
		$server    = self::fresh_rest_server();

		$post_type = \get_post_type_object( $case['postType'] );
		if ( ! $post_type ) {
			return self::result(
				$ctx,
				'revisions-autosaves.rest-revision-autosave-batch-gates',
				array(
					array(
						'label'   => 'REST case post type is registered',
						'details' => array( 'postType' => $case['postType'] ),
					),
				),
				array( 'case' => self::case_summary( $case ) )
			);
		}

		$parent_controller   = $post_type->get_rest_controller();
		$revision_controller = $post_type->get_revisions_rest_controller();
		$autosave_controller = $post_type->get_autosave_rest_controller();
		if ( $parent_controller ) {
			$parent_controller->register_routes();
		}
		if ( $revision_controller ) {
			$revision_controller->register_routes();
		}
		if ( $autosave_controller ) {
			$autosave_controller->register_routes();
		}

		$failures               = array();
		$author_id              = self::insert_author( $case, 'rest-batch-author' );
		$editor_id              = self::insert_author( $case, 'rest-batch-editor' );
		$post_id                = self::insert_parent_post( $case, $author_id, 'rest-batch-parent' );
		$old_revision_id        = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleFrom'] . ' batch old revision',
				'post_content'      => $case['contentFrom'] . "\nREST batch old revision",
				'post_excerpt'      => $case['excerptFrom'],
				'post_date'         => $case['dateFrom'],
				'post_date_gmt'     => $case['dateFromGmt'],
				'post_modified'     => $case['dateFrom'],
				'post_modified_gmt' => $case['dateFromGmt'],
			),
			false
		);
		$new_revision_id        = self::insert_revision_row(
			$post_id,
			$author_id,
			array(
				'post_title'        => $case['titleTo'] . ' batch latest revision',
				'post_content'      => $case['contentTo'] . "\nREST batch latest revision",
				'post_excerpt'      => $case['excerptTo'],
				'post_date'         => $case['dateTo'],
				'post_date_gmt'     => $case['dateToGmt'],
				'post_modified'     => $case['dateTo'],
				'post_modified_gmt' => $case['dateToGmt'],
			),
			false
		);
		$autosave_id            = self::insert_revision_row(
			$post_id,
			$editor_id,
			array(
				'post_title'        => $case['titleTo'] . ' batch autosave',
				'post_content'      => $case['contentTo'] . "\nREST batch autosave",
				'post_excerpt'      => $case['excerptTo'] . ' REST batch autosave',
				'post_date'         => $case['dateLater'],
				'post_date_gmt'     => $case['dateLaterGmt'],
				'post_modified'     => $case['dateLater'],
				'post_modified_gmt' => $case['dateLaterGmt'],
			),
			true
		);
		$revision_route         = '/wp/v2/' . $rest_base . '/' . $post_id . '/revisions';
		$revision_item_route    = $revision_route . '/' . $new_revision_id;
		$revision_delete_route  = $revision_route . '/' . $old_revision_id;
		$autosave_route         = '/wp/v2/' . $rest_base . '/' . $post_id . '/autosaves';
		$autosave_item_route    = $autosave_route . '/' . $autosave_id;
		$parent_item_route      = '/wp/v2/' . $rest_base . '/' . $post_id;
		$grant_caps             = self::grant_all_caps_filter( $editor_id );
		$batch_token            = 'component-fuzz-revisions-batch-' . $ctx->seed() . '-' . $ctx->iteration();
		$query_calls            = array();
		$prepare_revision_calls = array();
		$prepare_autosave_calls = array();
		$delete_calls           = array();
		$child_post_dispatch    = array();
		$record_child_dispatch  = false;

		$query_filter = static function ( array $args, \WP_REST_Request $request ) use ( &$query_calls ): array {
			$query_calls[] = array(
				'route'      => $request->get_route(),
				'method'     => $request->get_method(),
				'postParent' => $args['post_parent'] ?? null,
				'orderBy'    => $args['orderby'] ?? null,
			);
			return $args;
		};
		$revision_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_revision_calls ): \WP_REST_Response {
			$prepare_revision_calls[] = array(
				'id'     => (int) $post->ID,
				'parent' => (int) $post->post_parent,
				'route'  => $request->get_route(),
				'method' => $request->get_method(),
			);
			return $response;
		};
		$autosave_filter = static function ( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ) use ( &$prepare_autosave_calls ): \WP_REST_Response {
			$prepare_autosave_calls[] = array(
				'id'     => (int) $post->ID,
				'parent' => (int) $post->post_parent,
				'route'  => $request->get_route(),
				'method' => $request->get_method(),
			);
			return $response;
		};
		$delete_action = static function ( $result, \WP_REST_Request $request ) use ( &$delete_calls ): void {
			$delete_calls[] = array(
				'id'      => (int) $request['id'],
				'parent'  => (int) $request['parent'],
				'force'   => (bool) $request['force'],
				'deleted' => $result instanceof \WP_Post ? (int) $result->ID : null,
			);
		};
		$post_dispatch_filter = static function ( \WP_REST_Response $response, \WP_REST_Server $filter_server, \WP_REST_Request $request ) use ( $server, $batch_token, &$child_post_dispatch, &$record_child_dispatch ): \WP_REST_Response {
			if ( $filter_server !== $server || '/batch/v1' === $request->get_route() ) {
				return $response;
			}

			if ( $record_child_dispatch ) {
				$child_post_dispatch[] = array(
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
					'status' => $response->get_status(),
				);
			}

			$response->header( 'X-Component-Fuzz-Revisions-Batch', $batch_token );
			return $response;
		};

		\add_filter( 'rest_revision_query', $query_filter, 10, 2 );
		\add_filter( 'rest_prepare_revision', $revision_filter, 10, 3 );
		\add_filter( 'rest_prepare_autosave', $autosave_filter, 10, 3 );
		\add_filter( 'rest_post_dispatch', $post_dispatch_filter, 11, 3 );
		\add_action( 'rest_delete_revision', $delete_action, 10, 2 );

		$custom_filters_removed = false;
		try {
			\wp_set_current_user( $editor_id );
			\add_filter( 'user_has_cap', $grant_caps, 10, 4 );

			$direct_revision_collection = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_route,
					array(
						'context'  => 'edit',
						'_fields'  => 'id,parent,title.raw',
						'orderby'  => 'date',
						'order'    => 'desc',
						'per_page' => 3,
					)
				)
			);
			$direct_revision_item = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_item_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw',
					)
				)
			);
			$direct_autosave_item = self::dispatch(
				$server,
				self::request(
					'GET',
					$autosave_item_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title.raw,preview_link',
					)
				)
			);
			$direct_invalid_order = self::dispatch(
				$server,
				self::request(
					'GET',
					$revision_route,
					array( 'orderby' => 'relevance' )
				)
			);
			$direct_parent_item = self::dispatch(
				$server,
				self::request(
					'GET',
					$parent_item_route,
					array(
						'context' => 'edit',
						'_fields' => 'id,type,status',
					)
				)
			);

			$direct_revision_data = $direct_revision_collection instanceof \WP_REST_Response ? $direct_revision_collection->get_data() : array();
			$direct_item_data     = $direct_revision_item instanceof \WP_REST_Response ? $direct_revision_item->get_data() : array();
			$direct_autosave_data = $direct_autosave_item instanceof \WP_REST_Response ? $direct_autosave_item->get_data() : array();
			$direct_parent_data   = $direct_parent_item instanceof \WP_REST_Response ? $direct_parent_item->get_data() : array();
			$direct_query_count   = count( $query_calls );
			$direct_revision_prepare_count = count( $prepare_revision_calls );
			$direct_autosave_prepare_count = count( $prepare_autosave_calls );

			$query_calls            = array();
			$prepare_revision_calls = array();
			$prepare_autosave_calls = array();
			$delete_calls           = array();
			$counts_before_batch    = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();

			$normal_specs = array(
				array(
					'method' => 'DELETE',
					'route'  => $revision_delete_route,
					'body'   => array( 'force' => false ),
				),
				array(
					'method' => 'DELETE',
					'route'  => $revision_item_route,
					'body'   => array( 'force' => true ),
				),
				array(
					'method' => 'POST',
					'route'  => $autosave_route,
					'body'   => array(
						'title'   => $case['titleTo'] . ' blocked batch autosave',
						'content' => $case['contentTo'] . "\nblocked batch autosave",
					),
				),
			);

			$record_child_dispatch = true;
			$normal_batch = self::dispatch(
				$server,
				self::batch_request(
					array_map(
						static fn ( array $spec ): array => self::batch_child_from_spec( $spec ),
						$normal_specs
					),
					'normal'
				)
			);
			$record_child_dispatch = false;
			$normal_data      = $normal_batch instanceof \WP_REST_Response ? $normal_batch->get_data() : array();
			$normal_responses = is_array( $normal_data['responses'] ?? null ) ? $normal_data['responses'] : array();
			$counts_after_normal = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();

			$require_all_specs = array(
				array(
					'method' => 'PUT',
					'route'  => $parent_item_route,
					'query'  => array(
						'context' => 'edit',
						'_fields' => 'id,type,status',
					),
					'body'   => array(
						'title' => $case['titleTo'] . ' require-all parent update',
					),
				),
				array(
					'method' => 'DELETE',
					'route'  => $revision_item_route,
					'body'   => array( 'force' => true ),
				),
				array(
					'method' => 'POST',
					'route'  => $autosave_route,
					'body'   => array(
						'title' => $case['titleTo'] . ' require-all autosave',
					),
				),
			);
			$child_events_after_normal = $child_post_dispatch;

			$record_child_dispatch = true;
			$require_all_batch = self::dispatch(
				$server,
				self::batch_request(
					array_map(
						static fn ( array $spec ): array => self::batch_child_from_spec( $spec ),
						$require_all_specs
					),
					'require-all-validate'
				)
			);
			$record_child_dispatch = false;
			$require_all_data      = $require_all_batch instanceof \WP_REST_Response ? $require_all_batch->get_data() : array();
			$require_all_responses = is_array( $require_all_data['responses'] ?? null ) ? $require_all_data['responses'] : array();
			$counts_after_require_all = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array();

			$old_revision_after_batch = \get_post( $old_revision_id );
			$autosaves_after_batch    = \wp_get_post_revisions( $post_id, array( 'check_enabled' => false ) );
		} finally {
			$record_child_dispatch = false;
			\remove_action( 'rest_delete_revision', $delete_action, 10 );
			\remove_filter( 'rest_post_dispatch', $post_dispatch_filter, 11 );
			\remove_filter( 'rest_prepare_autosave', $autosave_filter, 10 );
			\remove_filter( 'rest_prepare_revision', $revision_filter, 10 );
			\remove_filter( 'rest_revision_query', $query_filter, 10 );
			\remove_filter( 'user_has_cap', $grant_caps, 10 );
			\wp_set_current_user( 0 );
			$custom_filters_removed = false === \has_filter( 'rest_delete_revision', $delete_action )
				&& false === \has_filter( 'rest_post_dispatch', $post_dispatch_filter )
				&& false === \has_filter( 'rest_prepare_autosave', $autosave_filter )
				&& false === \has_filter( 'rest_prepare_revision', $revision_filter )
				&& false === \has_filter( 'rest_revision_query', $query_filter )
				&& false === \has_filter( 'user_has_cap', $grant_caps );
		}

		$direct_ok = $direct_revision_collection instanceof \WP_REST_Response
			&& 200 === $direct_revision_collection->get_status()
			&& is_array( $direct_revision_data )
			&& array( $autosave_id, $new_revision_id, $old_revision_id ) === array_map( 'intval', array_column( $direct_revision_data, 'id' ) )
			&& $direct_revision_item instanceof \WP_REST_Response
			&& 200 === $direct_revision_item->get_status()
			&& $new_revision_id === (int) ( $direct_item_data['id'] ?? 0 )
			&& $direct_autosave_item instanceof \WP_REST_Response
			&& 200 === $direct_autosave_item->get_status()
			&& $autosave_id === (int) ( $direct_autosave_data['id'] ?? 0 )
			&& $direct_parent_item instanceof \WP_REST_Response
			&& 200 === $direct_parent_item->get_status()
			&& $post_id === (int) ( $direct_parent_data['id'] ?? 0 )
			&& self::response_error_ok( $direct_invalid_order, 'rest_no_search_term_defined', 400 )
			&& $direct_query_count >= 1
			&& $direct_revision_prepare_count >= 1
			&& $direct_autosave_prepare_count >= 1;

		$normal_ok = $normal_batch instanceof \WP_REST_Response
			&& 207 === $normal_batch->get_status()
			&& count( $normal_specs ) === count( $normal_responses );
		foreach ( $normal_responses as $offset => $envelope ) {
			$body    = is_array( $envelope['body'] ?? null ) ? $envelope['body'] : array();
			$headers = is_array( $envelope['headers'] ?? null ) ? $envelope['headers'] : array();
			$normal_ok = $normal_ok
				&& 400 === (int) ( $envelope['status'] ?? 0 )
				&& 'rest_batch_not_allowed' === ( $body['code'] ?? null )
				&& $batch_token === ( $headers['X-Component-Fuzz-Revisions-Batch'] ?? null )
				&& ( $normal_specs[ $offset ]['route'] ?? null ) === ( $child_events_after_normal[ $offset ]['route'] ?? null )
				&& ( $normal_specs[ $offset ]['method'] ?? null ) === ( $child_events_after_normal[ $offset ]['method'] ?? null )
				&& 400 === (int) ( $child_events_after_normal[ $offset ]['status'] ?? 0 );
		}
		$normal_no_side_effects = $counts_before_batch === $counts_after_normal
			&& array() === $query_calls
			&& array() === $prepare_revision_calls
			&& array() === $prepare_autosave_calls
			&& array() === $delete_calls
			&& $old_revision_after_batch instanceof \WP_Post
			&& isset( $autosaves_after_batch[ $autosave_id ] );

		$require_all_revision_error = is_array( $require_all_responses[1] ?? null ) ? $require_all_responses[1] : array();
		$require_all_revision_body  = is_array( $require_all_revision_error['body'] ?? null ) ? $require_all_revision_error['body'] : array();
		$require_all_autosave_error = is_array( $require_all_responses[2] ?? null ) ? $require_all_responses[2] : array();
		$require_all_autosave_body  = is_array( $require_all_autosave_error['body'] ?? null ) ? $require_all_autosave_error['body'] : array();
		$require_all_ok    = $require_all_batch instanceof \WP_REST_Response
			&& 207 === $require_all_batch->get_status()
			&& 'validation' === ( $require_all_data['failed'] ?? null )
			&& 3 === count( $require_all_responses )
			&& array_key_exists( 0, $require_all_responses )
			&& null === $require_all_responses[0]
			&& 400 === (int) ( $require_all_revision_error['status'] ?? 0 )
			&& 'rest_batch_not_allowed' === ( $require_all_revision_body['code'] ?? null )
			&& 400 === (int) ( $require_all_autosave_error['status'] ?? 0 )
			&& 'rest_batch_not_allowed' === ( $require_all_autosave_body['code'] ?? null )
			&& $counts_before_batch === $counts_after_require_all
			&& $child_events_after_normal === $child_post_dispatch;

		self::collect_failure(
			$failures,
			$direct_ok,
			'REST revision/autosave subroutes dispatch directly before batch gate checks are exercised',
			array(
				'revisionCollection' => self::response_summary( $direct_revision_collection ),
				'revisionItem'       => self::response_summary( $direct_revision_item ),
				'autosaveItem'       => self::response_summary( $direct_autosave_item ),
				'invalidOrder'       => self::response_summary( $direct_invalid_order ),
				'parentItem'         => self::response_summary( $direct_parent_item ),
				'queryCount'         => $direct_query_count,
				'revisionPrepare'    => $direct_revision_prepare_count,
				'autosavePrepare'    => $direct_autosave_prepare_count,
			)
		);
		self::collect_failure(
			$failures,
			$normal_ok && $normal_no_side_effects,
			'batch/v1 normal mode rejects revision and autosave subroutes before validation, callbacks, queries, deletes, or autosave writes',
			array(
				'normal'       => self::response_summary( $normal_batch ),
				'childEvents'  => $child_events_after_normal,
				'countsBefore' => $counts_before_batch,
				'countsAfter'  => $counts_after_normal,
				'queryCalls'   => $query_calls,
				'revisionPrepare' => $prepare_revision_calls,
				'autosavePrepare' => $prepare_autosave_calls,
				'deleteCalls'  => $delete_calls,
			)
		);
		self::collect_failure(
			$failures,
			$require_all_ok,
			'batch/v1 require-all validation nulls allowed parent siblings and reports revision subroute batch gates without child post-dispatch',
			array(
				'requireAll'  => self::response_summary( $require_all_batch ),
				'childEvents' => $child_post_dispatch,
				'countsAfter' => $counts_after_require_all,
			)
		);
		self::collect_failure(
			$failures,
			$custom_filters_removed,
			'REST revision/autosave batch gate harness removes local filters and restores current user',
			array(
				'filters' => array(
					'delete'       => \has_filter( 'rest_delete_revision', $delete_action ),
					'postDispatch' => \has_filter( 'rest_post_dispatch', $post_dispatch_filter ),
					'autosave'     => \has_filter( 'rest_prepare_autosave', $autosave_filter ),
					'revision'     => \has_filter( 'rest_prepare_revision', $revision_filter ),
					'query'        => \has_filter( 'rest_revision_query', $query_filter ),
					'caps'         => \has_filter( 'user_has_cap', $grant_caps ),
				),
			)
		);

		return self::result(
			$ctx,
			'revisions-autosaves.rest-revision-autosave-batch-gates',
			$failures,
			array(
				'case'        => self::case_summary( $case ),
				'postId'      => $post_id,
				'restBase'    => $rest_base,
				'revisions'   => array( $old_revision_id, $new_revision_id ),
				'autosaveId'  => $autosave_id,
				'batchRoutes' => array_column( $normal_specs, 'route' ),
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

	private static function grant_content_edit_caps_filter( int $user_id ): \Closure {
		return static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $user_id ): array {
			unset( $args );

			if ( (int) $user->ID !== (int) $user_id ) {
				return $allcaps;
			}

			foreach ( array( 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'edit_published_posts', 'edit_private_posts' ) as $cap ) {
				$allcaps[ $cap ] = true;
			}

			foreach ( $caps as $cap ) {
				if ( ! in_array( $cap, array( 'do_not_allow', 'add_post_meta', 'delete_post_meta', 'edit_post_meta' ), true ) ) {
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

	private static function register_rest_case_post_type( array $case ): string {
		$rest_base = 'cf-revisions-' . $case['token'];
		\register_post_type(
			$case['postType'],
			array(
				'public'         => true,
				'query_var'      => false,
				'rest_base'      => $rest_base,
				'rest_namespace' => 'wp/v2',
				'rewrite'        => false,
				'show_in_rest'   => true,
				'show_ui'        => true,
				'supports'       => array( 'title', 'editor', 'excerpt', 'author', 'revisions', 'custom-fields' ),
			)
		);

		return $rest_base;
	}

	private static function fresh_rest_server(): \WP_REST_Server {
		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		self::ensure_rest_default_filters();
		return $server;
	}

	private static function ensure_rest_default_filters(): void {
		$filters = array(
			array( 'rest_pre_serve_request', 'rest_send_cors_headers', 10, 1 ),
			array( 'rest_post_dispatch', 'rest_send_allow_header', 10, 3 ),
			array( 'rest_post_dispatch', 'rest_filter_response_fields', 10, 3 ),
			array( 'rest_pre_dispatch', 'rest_handle_options_request', 10, 3 ),
		);

		foreach ( $filters as $filter ) {
			list( $hook, $callback, $priority, $accepted_args ) = $filter;
			if ( function_exists( $callback ) && false === \has_filter( $hook, $callback ) ) {
				\add_filter( $hook, $callback, $priority, $accepted_args );
			}
		}
	}

	private static function dispatch( \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		return \rest_ensure_response( $server->dispatch( $request ) );
	}

	private static function request( string $method, string $route, array $query_params = array(), array $url_params = array(), array $body_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( array() !== $query_params ) {
			$request->set_query_params( $query_params );
		}
		if ( array() !== $url_params ) {
			$request->set_url_params( $url_params );
		}
		if ( array() !== $body_params ) {
			$request->set_body_params( $body_params );
		}
		return $request;
	}

	private static function batch_request( array $requests, string $validation ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'validation' => $validation,
				'requests'   => $requests,
			)
		);

		return $request;
	}

	private static function batch_child_from_spec( array $spec ): array {
		$path  = (string) ( $spec['route'] ?? '' );
		$query = is_array( $spec['query'] ?? null ) ? $spec['query'] : array();
		if ( array() !== $query ) {
			$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$child = array(
			'method' => (string) ( $spec['method'] ?? 'GET' ),
			'path'   => $path,
		);
		if ( isset( $spec['body'] ) && is_array( $spec['body'] ) ) {
			$child['body'] = $spec['body'];
		}
		if ( isset( $spec['headers'] ) && is_array( $spec['headers'] ) ) {
			$child['headers'] = $spec['headers'];
		}

		return $child;
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
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'wp_rewrite', 'wp_meta_keys', 'wp_rest_additional_fields', 'wp_rest_server', 'current_user', 'user_ID', 'post', 'wp_query', 'wp_the_query' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
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
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
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
			&& false === \has_filter( 'rest_revision_query' )
			&& false === \has_filter( 'rest_prepare_revision' )
			&& false === \has_filter( 'rest_prepare_autosave' )
			&& false === \has_filter( 'rest_delete_revision' )
			&& false === \has_filter( "wp_{$case['postType']}_revisions_to_keep" )
			&& false === \has_filter( 'the_preview', '_set_preview' )
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

	private static function capture_preview_dispatch( array $get ): array {
		$previous_get     = $_GET;
		$previous_request = $_REQUEST;
		$start_level      = ob_get_level();
		$die_call         = null;
		$captured         = false;
		$returned         = false;
		$throwable        = null;
		$output           = '';
		$filter           = static function ( $handler ) use ( &$die_call ) {
			unset( $handler );

			return static function ( $message = '', $title = '', $args = array() ) use ( &$die_call ): void {
				$die_call = array(
					'args'    => $args,
					'message' => $message,
					'title'   => $title,
				);
				throw new RevisionsAutosavesSurface_DieCaptured( 'Captured preview wp_die.' );
			};
		};

		$_GET     = $get;
		$_REQUEST = array_merge( $_POST, $_GET );
		\add_filter( 'wp_die_handler', $filter, 1 );

		ob_start();
		try {
			\_show_post_preview();
			$returned = true;
		} catch ( RevisionsAutosavesSurface_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$throwable = self::describe_throwable( $e );
		} finally {
			while ( ob_get_level() > $start_level ) {
				$chunk  = ob_get_clean();
				$output = ( false === $chunk ? '' : $chunk ) . $output;
			}

			\remove_filter( 'wp_die_handler', $filter, 1 );
			$_GET     = $previous_get;
			$_REQUEST = $previous_request;
		}

		return array(
			'bufferBalanced'  => $start_level === ob_get_level(),
			'captured'        => $captured,
			'die'             => $die_call,
			'filtersRestored' => false === \has_filter( 'wp_die_handler', $filter ),
			'getRestored'     => $previous_get === $_GET,
			'output'          => $output,
			'requestRestored' => $previous_request === $_REQUEST,
			'returned'        => $returned,
			'throwable'       => $throwable,
		);
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

	private static function response_error_ok( $response, string $code, int $status ): bool {
		if ( ! $response instanceof \WP_REST_Response || $status !== $response->get_status() ) {
			return false;
		}

		$data = $response->get_data();
		return is_array( $data )
			&& $code === ( $data['code'] ?? null )
			&& isset( $data['data'] )
			&& is_array( $data['data'] )
			&& $status === (int) ( $data['data']['status'] ?? 0 );
	}

	private static function response_summary( $response ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		return array(
			'status'  => $response->get_status(),
			'data'    => $response->get_data(),
			'headers' => $response->get_headers(),
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

final class RevisionsAutosavesSurface_DieCaptured extends \RuntimeException {
}
