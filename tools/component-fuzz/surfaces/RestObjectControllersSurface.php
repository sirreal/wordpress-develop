<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes DB-backed WordPress REST object controllers against the in-memory wpdb stub.
 */
final class RestObjectControllersSurface {
	public const NAME = 'rest-object-controllers';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'rest-object-controllers.bootstrap-apis-available',
					'Required WordPress REST object controller APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot           = self::snapshot_state();
		$before_fingerprint = self::state_fingerprint();
		$rows               = array();
		$ob_level           = ob_get_level();

		try {
			self::reset_runtime_state();

			$case                   = self::object_case( $ctx );
			$additional_field_calls = array();
			self::register_additional_fields( $case, $additional_field_calls );
			$fixtures = self::seed_fixtures( $case );

			$rows[] = self::check_posts_controller( $ctx, $case, $fixtures, $additional_field_calls );
			$rows[] = self::check_terms_controller( $ctx, $case, $fixtures, $additional_field_calls );
			$rows[] = self::check_comments_controller( $ctx, $case, $fixtures, $additional_field_calls );
			$rows[] = self::check_users_controller( $ctx, $case, $fixtures, $additional_field_calls );
			$rows[] = self::check_revisions_controller( $ctx, $case, $fixtures, $additional_field_calls );
			$rows[] = self::check_attachments_controller( $ctx, $case, $fixtures, $additional_field_calls );
			$rows[] = self::check_collection_parameter_matrix( $ctx, $case );
			$rows[] = self::check_additional_field_registry( $ctx, $case, $additional_field_calls );
			$rows[] = self::check_route_registry_behavior( $ctx, $case, $fixtures );
			$rows[] = self::skip(
				$ctx,
				'rest-object-controllers.templates-controller.skipped',
				'Template controllers combine block-theme filesystem state with template CPT queries, so this surface keeps them documented rather than weakening object-controller invariants.',
				array( 'controller' => 'WP_REST_Templates_Controller' )
			);
			$rows[] = self::skip(
				$ctx,
				'rest-object-controllers.collections.query-sql.skipped',
				'Broad collection queries through WP_Query, WP_User_Query, and template queries exceed the intentionally small SQL parser in the in-memory wpdb stub; this surface focuses collection-param validation plus direct object read/write/error paths.',
				array( 'controllers' => array( 'posts', 'users', 'revisions', 'templates' ) )
			);
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'rest-object-controllers.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}

			self::restore_state( $snapshot );

			$after_fingerprint = self::state_fingerprint();
			$rows[]            = self::row(
				$ctx,
				'rest-object-controllers.state-restored',
				$before_fingerprint === $after_fingerprint,
				array(
					'before'     => $before_fingerprint,
					'after'      => $after_fingerprint,
					'difference' => $before_fingerprint === $after_fingerprint
						? null
						: self::first_difference( $before_fingerprint, $after_fingerprint ),
				)
			);
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Comment',
				'WP_Error',
				'WP_Post',
				'WP_REST_Attachments_Controller',
				'WP_REST_Comment_Meta_Fields',
				'WP_REST_Comments_Controller',
				'WP_REST_Controller',
				'WP_REST_Post_Meta_Fields',
				'WP_REST_Posts_Controller',
				'WP_REST_Request',
				'WP_REST_Response',
				'WP_REST_Revisions_Controller',
				'WP_REST_Server',
				'WP_REST_Term_Meta_Fields',
				'WP_REST_Terms_Controller',
				'WP_REST_User_Meta_Fields',
				'WP_REST_Users_Controller',
				'WP_Rewrite',
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
				'add_filter',
				'add_post_meta',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'get_comment',
				'get_post',
				'get_post_meta',
				'get_term',
				'get_user_by',
				'has_filter',
				'is_wp_error',
				'register_post_meta',
				'register_rest_field',
				'register_rest_route',
				'remove_filter',
				'rest_ensure_response',
				'rest_get_route_for_post',
				'rest_get_route_for_taxonomy_items',
				'rest_sanitize_value_from_schema',
				'rest_url',
				'rest_validate_value_from_schema',
				'sanitize_email',
				'sanitize_key',
				'sanitize_text_field',
				'sanitize_title',
				'update_comment_meta',
				'update_option',
				'update_post_meta',
				'wp_cache_flush',
				'wp_insert_comment',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_set_current_user',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_posts_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures, array &$additional_field_calls ): array {
		$controller = new \WP_REST_Posts_Controller( 'post' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		$status_request = self::request( 'GET', '/wp/v2/posts' );
		$status_request->set_attributes( array( 'args' => $params ) );
		$private_denied = call_user_func( $params['status']['sanitize_callback'], array( 'private' ), $status_request, 'status' );
		$cap_filter     = self::install_cap_filter( array( 'edit_posts', 'read_private_posts' ) );
		$status_filter_restored = false;
		try {
			$private_allowed = call_user_func( $params['status']['sanitize_callback'], array( 'private' ), $status_request, 'status' );
		} finally {
			$status_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& self::collection_has_params(
					$params,
					array( 'after', 'author', 'before', 'categories', 'order', 'orderby', 'per_page', 'search', 'search_columns', 'slug', 'status' )
				)
				&& self::collection_default_ok( $params, 'per_page', 10 )
				&& self::collection_default_ok( $params, 'order', 'desc' )
				&& self::collection_default_ok( $params, 'status', 'publish' )
				&& self::collection_enum_contains( $params, 'order', array( 'asc', 'desc' ) )
				&& self::error_matches( $private_denied, 'rest_forbidden_status', 403 )
				&& array( 'private' ) === $private_allowed
				&& $status_filter_restored,
			'post collection params sanitize status and expose query controls without querying',
			array(
				'params'               => self::param_summary( $params ),
				'privateDenied'        => $private_denied,
				'privateAllowed'       => $private_allowed,
				'statusFilterRestored' => $status_filter_restored,
			)
		);

		$edit_request = self::request(
			'GET',
			'/wp/v2/posts/' . $fixtures['post'],
			array( 'context' => 'edit' ),
			array( 'id' => $fixtures['post'] )
		);
		$edit_denied  = $controller->get_item_permissions_check( $edit_request );
		$cap_filter   = self::install_cap_filter( array( 'edit_posts', 'edit_others_posts', 'edit_published_posts' ) );
		$edit_filter_restored = false;
		$post_extra_calls_before = self::additional_field_call_count( $additional_field_calls, 'post', $case['postAdditionalField'] );
		try {
			$edit_allowed = $controller->get_item_permissions_check( $edit_request );
			$item         = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/posts/' . $fixtures['post'],
					array(
						'context' => 'edit',
						'_fields' => 'id,title,content,meta,slug,' . $case['postAdditionalField'] . ',_links',
					),
					array( 'id' => $fixtures['post'] )
				)
			);
			$post_extra_calls_after_item = self::additional_field_call_count( $additional_field_calls, 'post', $case['postAdditionalField'] );
			$projected_item             = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/posts/' . $fixtures['post'],
					array(
						'context' => 'edit',
						'_fields' => 'id,title',
					),
					array( 'id' => $fixtures['post'] )
				)
			);
			$post_extra_calls_after_projection = self::additional_field_call_count( $additional_field_calls, 'post', $case['postAdditionalField'] );
		} finally {
			$edit_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		$item_data      = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links     = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		$projected_data = $projected_item instanceof \WP_REST_Response ? $projected_item->get_data() : array();
		self::collect_failure(
			$failures,
			self::error_matches( $edit_denied, 'rest_forbidden_context', 403 )
				&& true === $edit_allowed
				&& $item instanceof \WP_REST_Response
				&& self::projected_keys_match(
					$item_data,
					array( 'content', 'id', 'meta', 'slug', 'title', $case['postAdditionalField'] )
				)
				&& self::response_matches_schema_context( $controller, $item_data, 'edit' )
				&& $case['postTitle'] === ( $item_data['title']['raw'] ?? null )
				&& $case['postContent'] === ( $item_data['content']['raw'] ?? null )
				&& $case['postMetaStored'] === ( $item_data['meta'][ $case['postMetaKey'] ] ?? null )
				&& $case['postAdditionalValue'] === ( $item_data[ $case['postAdditionalField'] ] ?? null )
				&& $post_extra_calls_before + 1 === $post_extra_calls_after_item
				&& $post_extra_calls_after_item === $post_extra_calls_after_projection
				&& $projected_item instanceof \WP_REST_Response
				&& self::projected_keys_match( $projected_data, array( 'id', 'title' ) )
				&& \rest_url( 'wp/v2/posts/' . $fixtures['post'] ) === self::link_href( $item_links, 'self' )
				&& \rest_url( 'wp/v2/posts' ) === self::link_href( $item_links, 'collection' )
				&& $edit_filter_restored,
			'post get_item gates edit context, respects _fields/context, exposes meta, and emits REST links',
			array(
				'editDenied'         => $edit_denied,
				'editData'           => $item_data,
				'links'              => $item_links,
				'projectedData'      => $projected_data,
				'additionalCalls'    => array(
					'before'          => $post_extra_calls_before,
					'afterItem'       => $post_extra_calls_after_item,
					'afterProjection' => $post_extra_calls_after_projection,
				),
				'editFilterRestored' => $edit_filter_restored,
			)
		);

		$head_response = $controller->prepare_item_for_response(
			\get_post( $fixtures['post'] ),
			self::request( 'HEAD', '/wp/v2/posts/' . $fixtures['post'], array(), array( 'id' => $fixtures['post'] ) )
		);
		$invalid_item  = $controller->get_item(
			self::request( 'GET', '/wp/v2/posts/999999', array( 'context' => 'view' ), array( 'id' => 999999 ) )
		);
		$malformed_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/posts/' . $case['malformedId'], array( 'context' => 'view' ), array( 'id' => $case['malformedId'] ) )
		);
		self::collect_failure(
			$failures,
			$head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data()
				&& self::error_matches( $invalid_item, 'rest_post_invalid_id', 404 )
				&& self::error_matches( $malformed_item, 'rest_post_invalid_id', 404 ),
			'post HEAD and invalid/malformed ID error paths are represented',
			array(
				'headData'      => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
				'invalidItem'   => $invalid_item,
				'malformedItem' => $malformed_item,
			)
		);

		$existing_create = $controller->create_item_permissions_check(
			self::request( 'POST', '/wp/v2/posts', array(), array(), array( 'id' => $fixtures['post'] ) )
		);
		$create_denied  = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/posts' ) );
		$trash_filter   = static function (): bool {
			return false;
		};
		$block_filter   = static function (): array {
			return array();
		};
		$cap_filter     = self::install_cap_filter(
			array(
				'create_posts',
				'delete_others_posts',
				'delete_posts',
				'delete_published_posts',
				'edit_others_posts',
				'edit_posts',
				'edit_published_posts',
				'publish_posts',
				'read',
				'unfiltered_html',
			)
		);
		\add_filter( 'rest_block_hooks_post_types', $block_filter, 10, 3 );
		\add_filter( 'rest_post_trashable', $trash_filter, 10, 2 );
		$post_counts_before = self::content_counts();
		$write_filter_restored = false;
		$trash_filter_restored = false;
		$block_filter_restored = false;
		try {
			$created = $controller->create_item(
				self::request(
					'POST',
					'/wp/v2/posts',
					array( '_fields' => 'id,title,content,meta,status,slug' ),
					array(),
					array(
						'title'   => array( 'raw' => $case['createdPostTitle'] ),
						'content' => array( 'raw' => $case['createdPostContent'] ),
						'status'  => 'publish',
						'slug'    => $case['createdPostSlugInput'],
						'meta'    => array( $case['postMetaKey'] => $case['postMetaInput'] ),
					)
				)
			);

			$created_data = $created instanceof \WP_REST_Response ? $created->get_data() : array();
			$created_id   = (int) ( $created_data['id'] ?? 0 );
			$updated      = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/posts/' . $created_id,
					array( '_fields' => 'id,title,meta,status' ),
					array( 'id' => $created_id ),
					array(
						'title' => array( 'raw' => $case['updatedPostTitle'] ),
						'meta'  => array( $case['postMetaKey'] => $case['postMetaUpdateInput'] ),
					)
				)
			);
			$delete_error = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/posts/' . $created_id,
					array(),
					array( 'id' => $created_id ),
					array( 'force' => false )
				)
			);
		} finally {
			\remove_filter( 'rest_post_trashable', $trash_filter, 10 );
			$trash_filter_restored = false === \has_filter( 'rest_post_trashable', $trash_filter );
			\remove_filter( 'rest_block_hooks_post_types', $block_filter, 10 );
			$block_filter_restored = false === \has_filter( 'rest_block_hooks_post_types', $block_filter );
			$write_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		$post_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			$existing_create instanceof \WP_Error
				&& 'rest_post_exists' === $existing_create->get_error_code()
				&& $create_denied instanceof \WP_Error
				&& 'rest_cannot_create' === $create_denied->get_error_code()
				&& $created instanceof \WP_REST_Response
				&& 201 === $created->get_status()
				&& $created_id > 0
				&& $case['createdPostSlugInput'] === ( $created_data['slug'] ?? null )
				&& $case['postMetaSanitized'] === ( $created_data['meta'][ $case['postMetaKey'] ] ?? null )
				&& $updated instanceof \WP_REST_Response
				&& $case['updatedPostTitle'] === ( $updated_data['title']['raw'] ?? null )
				&& $case['postMetaUpdateSanitized'] === \get_post_meta( $created_id, $case['postMetaKey'], true )
				&& self::error_matches( $delete_error, 'rest_trash_not_supported', 501 )
				&& self::content_count_delta_matches(
					$post_counts_before,
					$post_counts_after,
					array(
						'post_meta' => 1,
						'posts'     => 1,
					),
					array( 'post_meta', 'posts' )
				)
				&& $write_filter_restored
				&& $trash_filter_restored
				&& $block_filter_restored,
			'post create/update/delete error paths use the in-memory post and meta stores',
			array(
				'existingCreate'       => $existing_create,
				'createDenied'         => $create_denied,
				'createdData'          => $created_data,
				'updatedData'          => $updated_data,
				'deleteError'          => $delete_error,
				'storedMeta'           => $created_id > 0 ? \get_post_meta( $created_id, $case['postMetaKey'], true ) : null,
				'contentCountsBefore'  => $post_counts_before,
				'contentCountsAfter'   => $post_counts_after,
				'writeFilterRestored'  => $write_filter_restored,
				'trashFilterRestored'  => $trash_filter_restored,
				'blockFilterRestored'  => $block_filter_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.posts.schema-permissions-read-write-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_terms_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures, array &$additional_field_calls ): array {
		$controller = new \WP_REST_Terms_Controller( 'category' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		$slug_sanitized = $controller->sanitize_slug( $case['termSlugInput'] );
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& self::collection_has_params(
					$params,
					array( 'hide_empty', 'order', 'orderby', 'parent', 'per_page', 'post', 'search', 'slug' )
				)
				&& self::collection_default_ok( $params, 'order', 'asc' )
				&& self::collection_default_ok( $params, 'orderby', 'name' )
				&& self::collection_default_ok( $params, 'hide_empty', false )
				&& self::collection_enum_contains( $params, 'order', array( 'asc', 'desc' ) )
				&& \sanitize_title( $case['termSlugInput'] ) === $slug_sanitized,
			'term collection params expose enum defaults and slug sanitization is stable',
			array(
				'params'        => self::param_summary( $params ),
				'slugSanitized' => $slug_sanitized,
			)
		);

		$item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/categories/' . $fixtures['term'],
				array(
					'context' => 'view',
					'_fields' => 'id,name,slug,taxonomy,parent,' . $case['termAdditionalField'] . ',_links',
				),
				array( 'id' => $fixtures['term'] )
			)
		);
		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		$edit_request = self::request(
			'GET',
			'/wp/v2/categories/' . $fixtures['term'],
			array( 'context' => 'edit' ),
			array( 'id' => $fixtures['term'] )
		);
		$edit_denied = $controller->get_item_permissions_check( $edit_request );
		$cap_filter  = self::install_cap_filter( array( 'edit_categories', 'manage_categories' ) );
		$edit_filter_restored = false;
		try {
			$edit_allowed = $controller->get_item_permissions_check( $edit_request );
		} finally {
			$edit_filter_restored = self::remove_cap_filter( $cap_filter );
		}
		self::collect_failure(
			$failures,
			$item instanceof \WP_REST_Response
				&& self::projected_keys_match(
					$item_data,
					array( 'id', 'name', 'parent', 'slug', 'taxonomy', $case['termAdditionalField'] )
				)
				&& self::response_matches_schema_context( $controller, $item_data, 'view' )
				&& $fixtures['term'] === (int) ( $item_data['id'] ?? 0 )
				&& $case['termName'] === ( $item_data['name'] ?? null )
				&& 'category' === ( $item_data['taxonomy'] ?? null )
				&& $case['termAdditionalValue'] === ( $item_data[ $case['termAdditionalField'] ] ?? null )
				&& self::additional_field_call_count( $additional_field_calls, 'category', $case['termAdditionalField'], 'view', $fixtures['term'] ) > 0
				&& \rest_url( 'wp/v2/categories/' . $fixtures['term'] ) === self::link_href( $item_links, 'self' )
				&& \rest_url( 'wp/v2/categories' ) === self::link_href( $item_links, 'collection' )
				&& self::error_matches( $edit_denied, 'rest_forbidden_context', 403 )
				&& true === $edit_allowed
				&& $edit_filter_restored,
			'term get_item respects fields, links, and edit permission gates',
			array(
				'itemData'           => $item_data,
				'links'              => $item_links,
				'editDenied'         => $edit_denied,
				'editAllowed'        => $edit_allowed,
				'editFilterRestored' => $edit_filter_restored,
			)
		);

		$term_slug_map = array(
			\sanitize_title( $case['childTermSlugInput'] ) => $fixtures['child_term'],
			\sanitize_title( $case['termSlugInput'] )      => $fixtures['term'],
		);
		ksort( $term_slug_map, SORT_STRING );
		$term_query_args     = array();
		$term_query_filter   = static function ( array $prepared_args ) use ( &$term_query_args ): array {
			$term_query_args[] = $prepared_args;
			return $prepared_args;
		};
		$term_filter_restored = false;
		\add_filter( 'rest_category_query', $term_query_filter, 10, 2 );
		try {
			$slug_collection = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/categories',
					array(
						'context'    => 'view',
						'_fields'    => 'id,slug,parent',
						'hide_empty' => '0',
						'order'      => 'asc',
						'orderby'    => 'slug',
						'per_page'   => 2,
						'slug'       => array_keys( $term_slug_map ),
					)
				)
			);
			$parent_collection = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/categories',
					array(
						'context'    => 'view',
						'_fields'    => 'id,parent',
						'hide_empty' => '0',
						'order'      => 'asc',
						'orderby'    => 'name',
						'parent'     => $fixtures['term'],
						'per_page'   => 5,
					)
				)
			);
		} finally {
			\remove_filter( 'rest_category_query', $term_query_filter, 10 );
			$term_filter_restored = false === \has_filter( 'rest_category_query', $term_query_filter );
		}
		$slug_collection_data = $slug_collection instanceof \WP_REST_Response ? $slug_collection->get_data() : array();
		$parent_collection_data = $parent_collection instanceof \WP_REST_Response ? $parent_collection->get_data() : array();
		self::collect_failure(
			$failures,
			$slug_collection instanceof \WP_REST_Response
				&& $parent_collection instanceof \WP_REST_Response
				&& 2 === count( $term_query_args )
				&& 'category' === ( $term_query_args[0]['taxonomy'] ?? null )
				&& array_keys( $term_slug_map ) === ( $term_query_args[0]['slug'] ?? null )
				&& 'asc' === ( $term_query_args[0]['order'] ?? null )
				&& 'slug' === ( $term_query_args[0]['orderby'] ?? null )
				&& 2 === (int) ( $term_query_args[0]['number'] ?? 0 )
				&& '0' === (string) ( $term_query_args[0]['hide_empty'] ?? '' )
				&& $fixtures['term'] === (int) ( $term_query_args[1]['parent'] ?? 0 )
				&& 'name' === ( $term_query_args[1]['orderby'] ?? null )
				&& 5 === (int) ( $term_query_args[1]['number'] ?? 0 )
				&& $term_filter_restored,
			'term collection filtering/order maps slug, parent, per_page, and order deterministically',
			array(
				'queryArgs'          => $term_query_args,
				'slugData'           => $slug_collection_data,
				'parentData'         => $parent_collection_data,
				'headers'            => $slug_collection instanceof \WP_REST_Response ? $slug_collection->get_headers() : array(),
				'termFilterRestored' => $term_filter_restored,
			)
		);

		$term_error_counts_before = self::content_counts();
		$invalid_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/categories/999999', array(), array( 'id' => 999999 ) )
		);
		$malformed_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/categories/' . $case['malformedId'], array(), array( 'id' => $case['malformedId'] ) )
		);
		$invalid_parent = $controller->create_item(
			self::request(
				'POST',
				'/wp/v2/categories',
				array(),
				array(),
				array(
					'name'   => $case['createdTermName'],
					'parent' => 999999,
				)
			)
		);
		$head_response = $controller->prepare_item_for_response(
			\get_term( $fixtures['term'], 'category' ),
			self::request( 'HEAD', '/wp/v2/categories/' . $fixtures['term'], array(), array( 'id' => $fixtures['term'] ) )
		);
		$term_error_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			self::error_matches( $invalid_item, 'rest_term_invalid', 404 )
				&& self::error_matches( $malformed_item, 'rest_term_invalid', 404 )
				&& self::error_matches( $invalid_parent, 'rest_term_invalid', 400 )
				&& $head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data()
				&& self::content_count_delta_matches( $term_error_counts_before, $term_error_counts_after, array(), array() ),
			'term invalid/malformed ID, invalid parent, and HEAD paths are represented without row changes',
			array(
				'invalidItem'  => $invalid_item,
				'malformedItem' => $malformed_item,
				'invalidParent' => $invalid_parent,
				'headData'     => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
				'countsBefore' => $term_error_counts_before,
				'countsAfter'  => $term_error_counts_after,
			)
		);

		$cap_filter = self::install_cap_filter( array( 'delete_categories', 'edit_categories', 'manage_categories' ) );
		$term_counts_before = self::content_counts();
		$write_filter_restored = false;
		try {
			$created = $controller->create_item(
				self::request(
					'POST',
					'/wp/v2/categories',
					array( '_fields' => 'id,name,slug,taxonomy,parent' ),
					array(),
					array(
						'name'        => $case['createdTermName'],
						'description' => $case['createdTermDescription'],
						'slug'        => $case['createdTermSlugInput'],
					)
				)
			);
			$created_data = $created instanceof \WP_REST_Response ? $created->get_data() : array();
			$created_id   = (int) ( $created_data['id'] ?? 0 );
			$updated      = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/categories/' . $created_id,
					array( '_fields' => 'id,name,slug' ),
					array( 'id' => $created_id ),
					array( 'name' => $case['updatedTermName'] )
				)
			);
			$delete_error = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/categories/' . $created_id,
					array(),
					array( 'id' => $created_id ),
					array( 'force' => false )
				)
			);
		} finally {
			$write_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		$term_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			$created instanceof \WP_REST_Response
				&& 201 === $created->get_status()
				&& $created_id > 0
				&& $case['createdTermName'] === ( $created_data['name'] ?? null )
				&& \sanitize_title( $case['createdTermSlugInput'] ) === ( $created_data['slug'] ?? null )
				&& $updated instanceof \WP_REST_Response
				&& $case['updatedTermName'] === ( $updated_data['name'] ?? null )
				&& self::error_matches( $delete_error, 'rest_trash_not_supported', 501 )
				&& self::content_count_delta_matches(
					$term_counts_before,
					$term_counts_after,
					array(
						'term_taxonomy' => 1,
						'terms'         => 1,
					),
					array( 'term_taxonomy', 'terms' )
				)
				&& $write_filter_restored,
			'term create/update/delete error paths use the in-memory term tables',
			array(
				'createdData'         => $created_data,
				'updatedData'         => $updated_data,
				'deleteError'         => $delete_error,
				'contentCountsBefore' => $term_counts_before,
				'contentCountsAfter'  => $term_counts_after,
				'writeFilterRestored' => $write_filter_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.terms.schema-permissions-read-write-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_comments_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures, array &$additional_field_calls ): array {
		$controller = new \WP_REST_Comments_Controller();
		$failures   = array();
		$params     = $controller->get_collection_params();

		$status_sanitized = call_user_func( $params['status']['sanitize_callback'], 'Approved!!', self::request( 'GET', '/wp/v2/comments' ), 'status' );
		$email_request    = self::request( 'POST', '/wp/v2/comments' );
		$email_request->set_attributes( array( 'args' => $controller->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ) ) );
		$invalid_email = $controller->check_comment_author_email( 'not an email', $email_request, 'author_email' );
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& self::collection_has_params(
					$params,
					array( 'author_email', 'order', 'orderby', 'parent', 'per_page', 'post', 'search', 'status', 'type' )
				)
				&& self::collection_default_ok( $params, 'order', 'desc' )
				&& self::collection_default_ok( $params, 'orderby', 'date_gmt' )
				&& self::collection_default_ok( $params, 'status', 'approve' )
				&& self::collection_enum_contains( $params, 'order', array( 'asc', 'desc' ) )
				&& 'approved' === $status_sanitized
				&& self::error_matches( $invalid_email, 'rest_invalid_email' ),
			'comment collection params sanitize status and validate author email',
			array(
				'params'          => self::param_summary( $params ),
				'statusSanitized' => $status_sanitized,
				'invalidEmail'    => $invalid_email,
			)
		);

		$comment_query_args     = array();
		$comment_query_filter   = static function ( array $prepared_args ) use ( &$comment_query_args ): array {
			$comment_query_args[] = $prepared_args;
			return $prepared_args;
		};
		$comment_filter_restored = false;
		\add_filter( 'rest_comment_query', $comment_query_filter, 10, 2 );
		try {
			$comment_collection = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/comments',
					array(
						'context'  => 'view',
						'_fields'  => 'id,post,parent,status,type',
						'order'    => 'desc',
						'orderby'  => 'id',
						'page'     => 1,
						'per_page' => 2,
						'post'     => array( $fixtures['post'] ),
						'status'   => 'approve',
						'type'     => 'comment',
					)
				)
			);
			$child_comment_collection = $controller->get_items(
				self::request(
					'GET',
					'/wp/v2/comments',
					array(
						'context'  => 'view',
						'_fields'  => 'id,parent',
						'order'    => 'asc',
						'orderby'  => 'id',
						'page'     => 1,
						'parent'   => array( $fixtures['comment'] ),
						'per_page' => 5,
						'post'     => array( $fixtures['post'] ),
						'status'   => 'approve',
					)
				)
			);
		} finally {
			\remove_filter( 'rest_comment_query', $comment_query_filter, 10 );
			$comment_filter_restored = false === \has_filter( 'rest_comment_query', $comment_query_filter );
		}
		$comment_collection_data = $comment_collection instanceof \WP_REST_Response ? $comment_collection->get_data() : array();
		$child_comment_data = $child_comment_collection instanceof \WP_REST_Response ? $child_comment_collection->get_data() : array();
		self::collect_failure(
			$failures,
			$comment_collection instanceof \WP_REST_Response
				&& $child_comment_collection instanceof \WP_REST_Response
				&& 2 === count( $comment_query_args )
				&& array( $fixtures['post'] ) === ( $comment_query_args[0]['post__in'] ?? null )
				&& 'approve' === ( $comment_query_args[0]['status'] ?? null )
				&& 'comment' === ( $comment_query_args[0]['type'] ?? null )
				&& 'comment_ID' === ( $comment_query_args[0]['orderby'] ?? null )
				&& 'desc' === ( $comment_query_args[0]['order'] ?? null )
				&& 2 === (int) ( $comment_query_args[0]['number'] ?? 0 )
				&& 0 === (int) ( $comment_query_args[0]['offset'] ?? -1 )
				&& array( $fixtures['comment'] ) === ( $comment_query_args[1]['parent__in'] ?? null )
				&& 'asc' === ( $comment_query_args[1]['order'] ?? null )
				&& 5 === (int) ( $comment_query_args[1]['number'] ?? 0 )
				&& $comment_filter_restored,
			'comment collection filtering/order maps post, status, parent, per_page, and id ordering deterministically',
			array(
				'queryArgs'      => $comment_query_args,
				'collectionData' => $comment_collection_data,
				'childData'      => $child_comment_data,
				'headers'        => $comment_collection instanceof \WP_REST_Response ? $comment_collection->get_headers() : array(),
				'filterRestored' => $comment_filter_restored,
			)
		);

		$view_item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/comments/' . $fixtures['comment'],
				array(
					'context' => 'view',
					'_fields' => 'id,post,author_name,author_email,content,status,type,_links',
				),
				array( 'id' => $fixtures['comment'] )
			)
		);
		$view_data = $view_item instanceof \WP_REST_Response ? $view_item->get_data() : array();
		$view_links = $view_item instanceof \WP_REST_Response ? $view_item->get_links() : array();
		$cap_filter = self::install_cap_filter( array( 'edit_posts', 'moderate_comments' ) );
		$edit_filter_restored = false;
		$comment_extra_calls_before = self::additional_field_call_count( $additional_field_calls, 'comment', $case['commentAdditionalField'] );
		try {
			$edit_item = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/comments/' . $fixtures['comment'],
					array(
						'context' => 'edit',
						'_fields' => 'id,content,author_email,status,' . $case['commentAdditionalField'] . ',_links',
					),
					array( 'id' => $fixtures['comment'] )
				)
			);
			$comment_extra_calls_after = self::additional_field_call_count( $additional_field_calls, 'comment', $case['commentAdditionalField'] );
		} finally {
			$edit_filter_restored = self::remove_cap_filter( $cap_filter );
		}
		$edit_data = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		self::collect_failure(
			$failures,
			$view_item instanceof \WP_REST_Response
				&& self::response_matches_schema_context( $controller, $view_data, 'view' )
				&& $fixtures['comment'] === (int) ( $view_data['id'] ?? 0 )
				&& $fixtures['post'] === (int) ( $view_data['post'] ?? 0 )
				&& ! isset( $view_data['author_email'], $view_data['content']['raw'], $view_data[ $case['commentAdditionalField'] ] )
				&& isset( $view_data['content']['rendered'] )
				&& 'approved' === ( $view_data['status'] ?? null )
				&& \rest_url( 'wp/v2/comments/' . $fixtures['comment'] ) === self::link_href( $view_links, 'self' )
				&& $edit_item instanceof \WP_REST_Response
				&& self::projected_keys_match( $edit_data, array( 'author_email', 'content', 'id', 'status', $case['commentAdditionalField'] ) )
				&& self::response_matches_schema_context( $controller, $edit_data, 'edit' )
				&& $case['commentAuthorEmail'] === ( $edit_data['author_email'] ?? null )
				&& $case['commentContent'] === ( $edit_data['content']['raw'] ?? null )
				&& $case['commentAdditionalValue'] === ( $edit_data[ $case['commentAdditionalField'] ] ?? null )
				&& $comment_extra_calls_before + 1 === $comment_extra_calls_after
				&& $edit_filter_restored,
			'comment get_item filters edit-only fields by context and emits links',
			array(
				'viewData'           => $view_data,
				'editData'           => $edit_data,
				'links'              => $view_links,
				'additionalCalls'    => array(
					'before' => $comment_extra_calls_before,
					'after'  => $comment_extra_calls_after,
				),
				'editFilterRestored' => $edit_filter_restored,
				'checkViewSchema'    => self::response_matches_schema_context( $controller, $view_data, 'view' ),
				'checkEditProjection' => self::projected_keys_match( $edit_data, array( 'author_email', 'content', 'id', 'status', $case['commentAdditionalField'] ) ),
				'checkEditSchema'    => self::response_matches_schema_context( $controller, $edit_data, 'edit' ),
				'checkEmail'         => $case['commentAuthorEmail'] === ( $edit_data['author_email'] ?? null ),
				'checkContent'       => $case['commentContent'] === ( $edit_data['content']['raw'] ?? null ),
				'checkAdditional'    => $case['commentAdditionalValue'] === ( $edit_data[ $case['commentAdditionalField'] ] ?? null ),
				'checkCallDelta'     => $comment_extra_calls_before + 1 === $comment_extra_calls_after,
			)
		);

		$comment_error_counts_before = self::content_counts();
		$invalid_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/comments/999999', array(), array( 'id' => 999999 ) )
		);
		$malformed_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/comments/' . $case['malformedId'], array(), array( 'id' => $case['malformedId'] ) )
		);
		$missing_post = $controller->create_item_permissions_check(
			self::request( 'POST', '/wp/v2/comments', array(), array(), array( 'content' => $case['createdCommentContent'] ) )
		);
		$bad_type = $controller->create_item(
			self::request(
				'POST',
				'/wp/v2/comments',
				array(),
				array(),
				array(
						'content' => $case['createdCommentContent'],
						'parent'  => 0,
						'post'    => $fixtures['post'],
						'type'    => 'not-core',
				)
			)
		);
		$head_response = $controller->prepare_item_for_response(
			\get_comment( $fixtures['comment'] ),
			self::request( 'HEAD', '/wp/v2/comments/' . $fixtures['comment'], array(), array( 'id' => $fixtures['comment'] ) )
		);
		$comment_error_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			self::error_matches( $invalid_item, 'rest_comment_invalid_id', 404 )
				&& self::error_matches( $malformed_item, 'rest_comment_invalid_id', 404 )
				&& self::error_matches( $missing_post, 'rest_comment_invalid_post_id', 403 )
				&& self::error_matches( $bad_type, 'rest_invalid_comment_type', 400 )
				&& $head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data()
				&& self::content_count_delta_matches( $comment_error_counts_before, $comment_error_counts_after, array(), array() ),
			'comment invalid/malformed ID, missing post, invalid type, and HEAD paths are represented without row changes',
			array(
				'invalidItem'  => $invalid_item,
				'malformedItem' => $malformed_item,
				'missingPost'  => $missing_post,
				'badType'      => $bad_type,
				'headData'     => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
				'countsBefore' => $comment_error_counts_before,
				'countsAfter'  => $comment_error_counts_after,
			)
		);

		$trash_filter = static function (): bool {
			return false;
		};
		$cap_filter   = self::install_cap_filter( array( 'edit_posts', 'moderate_comments' ) );
		$comment_counts_before = self::content_counts();
		$trash_filter_restored = false;
		$write_filter_restored = false;
		\add_filter( 'rest_comment_trashable', $trash_filter, 10, 2 );
		try {
			$created = $controller->create_item(
				self::request(
					'POST',
					'/wp/v2/comments',
					array( '_fields' => 'id,post,content,status,type' ),
					array(),
					array(
						'author'       => $fixtures['author'],
						'author_email' => $case['commentAuthorEmail'],
						'author_name'  => $case['commentAuthorName'],
						'content'      => $case['createdCommentContentPadded'],
						'parent'       => 0,
						'post'         => $fixtures['post'],
						'status'       => 'approve',
						'type'         => 'comment',
					)
				)
			);
			$created_data = $created instanceof \WP_REST_Response ? $created->get_data() : array();
			$created_id   = (int) ( $created_data['id'] ?? 0 );
			$updated      = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/comments/' . $created_id,
					array( '_fields' => 'id,content,status' ),
					array( 'id' => $created_id ),
					array(
						'content' => $case['updatedCommentContent'],
						'status'  => 'hold',
					)
				)
			);
			$delete_error = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/comments/' . $created_id,
					array(),
					array( 'id' => $created_id ),
					array( 'force' => false )
				)
			);
		} finally {
			\remove_filter( 'rest_comment_trashable', $trash_filter, 10 );
			$trash_filter_restored = false === \has_filter( 'rest_comment_trashable', $trash_filter );
			$write_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		$comment_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			$created instanceof \WP_REST_Response
				&& 201 === $created->get_status()
				&& $created_id > 0
				&& $fixtures['post'] === (int) ( $created_data['post'] ?? 0 )
				&& trim( $case['createdCommentContentPadded'] ) === ( $created_data['content']['raw'] ?? null )
				&& 'approved' === ( $created_data['status'] ?? null )
				&& $updated instanceof \WP_REST_Response
				&& $case['updatedCommentContent'] === ( $updated_data['content']['raw'] ?? null )
				&& 'hold' === ( $updated_data['status'] ?? null )
				&& self::error_matches( $delete_error, 'rest_trash_not_supported', 501 )
				&& self::content_count_delta_matches(
					$comment_counts_before,
					$comment_counts_after,
					array( 'comments' => 1 ),
					array( 'comments' )
				)
				&& $trash_filter_restored
				&& $write_filter_restored,
			'comment create/update/delete error paths use the in-memory comment table',
			array(
				'createdData'         => $created_data,
				'updatedData'         => $updated_data,
				'deleteError'         => $delete_error,
				'contentCountsBefore' => $comment_counts_before,
				'contentCountsAfter'  => $comment_counts_after,
				'trashFilterRestored' => $trash_filter_restored,
				'writeFilterRestored' => $write_filter_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.comments.schema-permissions-read-write-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_users_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures, array &$additional_field_calls ): array {
		$controller = new \WP_REST_Users_Controller();
		$failures   = array();
		$params     = $controller->get_collection_params();

		$roles_denied     = $controller->get_items_permissions_check(
			self::request( 'GET', '/wp/v2/users', array( 'roles' => array( 'administrator' ) ) )
		);
		$reassign_invalid = $controller->check_reassign( 'not-a-user', self::request( 'DELETE', '/wp/v2/users/1' ), 'reassign' );
		$reassign_false   = $controller->check_reassign( 'false', self::request( 'DELETE', '/wp/v2/users/1' ), 'reassign' );
		$bad_username    = $controller->check_username( 'bad user!', self::request( 'POST', '/wp/v2/users' ), 'username' );
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& self::collection_has_params(
					$params,
					array( 'capabilities', 'order', 'orderby', 'per_page', 'roles', 'search', 'search_columns', 'slug', 'who' )
				)
				&& self::collection_default_ok( $params, 'order', 'asc' )
				&& self::collection_default_ok( $params, 'orderby', 'name' )
				&& self::collection_enum_contains( $params, 'order', array( 'asc', 'desc' ) )
				&& self::error_matches( $roles_denied, 'rest_user_cannot_view', 403 )
				&& $reassign_invalid instanceof \WP_Error
				&& 'rest_invalid_param' === $reassign_invalid->get_error_code()
				&& false === $reassign_false
				&& self::error_matches( $bad_username, 'rest_user_invalid_username', 400 ),
			'user collection params and helper sanitizers reject privileged filters and invalid values',
			array(
				'params'          => self::param_summary( $params ),
				'rolesDenied'     => $roles_denied,
				'reassignInvalid' => $reassign_invalid,
				'reassignFalse'   => $reassign_false,
				'badUsername'     => $bad_username,
			)
		);

		$embed_item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/users/' . $fixtures['author'],
				array(
					'context' => 'embed',
					'_fields' => 'id,name,email,slug,' . $case['userAdditionalField'] . ',_links',
				),
				array( 'id' => $fixtures['author'] )
			)
		);
		$embed_data = $embed_item instanceof \WP_REST_Response ? $embed_item->get_data() : array();
		$embed_links = $embed_item instanceof \WP_REST_Response ? $embed_item->get_links() : array();
		$edit_request = self::request(
			'GET',
			'/wp/v2/users/' . $fixtures['author'],
			array( 'context' => 'edit' ),
			array( 'id' => $fixtures['author'] )
		);
		$edit_denied = $controller->get_item_permissions_check( $edit_request );
		$cap_filter  = self::install_cap_filter( array( 'edit_user', 'edit_users', 'list_users' ) );
		$edit_filter_restored = false;
		try {
			$edit_allowed = $controller->get_item_permissions_check( $edit_request );
			$edit_item    = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/users/' . $fixtures['author'],
					array(
						'context' => 'edit',
						'_fields' => 'id,username,name,email,slug,_links',
					),
					array( 'id' => $fixtures['author'] )
				)
			);
		} finally {
			$edit_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		$edit_data = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		self::collect_failure(
			$failures,
			$embed_item instanceof \WP_REST_Response
				&& self::projected_keys_match( $embed_data, array( 'id', 'name', 'slug', $case['userAdditionalField'] ) )
				&& self::response_matches_schema_context( $controller, $embed_data, 'embed' )
				&& $fixtures['author'] === (int) ( $embed_data['id'] ?? 0 )
				&& ! isset( $embed_data['email'] )
				&& $case['userAdditionalValue'] === ( $embed_data[ $case['userAdditionalField'] ] ?? null )
				&& self::additional_field_call_count( $additional_field_calls, 'user', $case['userAdditionalField'], 'embed', $fixtures['author'] ) > 0
				&& \rest_url( 'wp/v2/users/' . $fixtures['author'] ) === self::link_href( $embed_links, 'self' )
				&& true === $edit_denied
				&& true === $edit_allowed
				&& $edit_item instanceof \WP_REST_Response
				&& self::response_matches_schema_context( $controller, $edit_data, 'edit' )
				&& $case['authorEmail'] === ( $edit_data['email'] ?? null )
				&& $case['authorLogin'] === ( $edit_data['username'] ?? null )
				&& $edit_filter_restored,
			'user get_item filters edit-only fields by context and allows current-user edit context',
			array(
				'embedData'          => $embed_data,
				'editData'           => $edit_data,
				'links'              => $embed_links,
				'editDenied'         => $edit_denied,
				'editAllowed'        => $edit_allowed,
				'editFilterRestored' => $edit_filter_restored,
			)
		);

		$user_error_counts_before = self::content_counts();
		$invalid_item    = $controller->get_item(
			self::request( 'GET', '/wp/v2/users/999999', array(), array( 'id' => 999999 ) )
		);
		$malformed_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/users/' . $case['malformedId'], array(), array( 'id' => $case['malformedId'] ) )
		);
		$existing_create = $controller->create_item(
			self::request( 'POST', '/wp/v2/users', array(), array(), array( 'id' => $fixtures['author'] ) )
		);
		$create_denied   = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/users' ) );
		$head_response   = $controller->prepare_item_for_response(
			\get_user_by( 'id', $fixtures['author'] ),
			self::request( 'HEAD', '/wp/v2/users/' . $fixtures['author'], array(), array( 'id' => $fixtures['author'] ) )
		);
		$user_error_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			self::error_matches( $invalid_item, 'rest_user_invalid_id', 404 )
				&& self::error_matches( $malformed_item, 'rest_user_invalid_id', 404 )
				&& self::error_matches( $existing_create, 'rest_user_exists', 400 )
				&& self::error_matches( $create_denied, 'rest_cannot_create_user', 403 )
				&& $head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data()
				&& self::content_count_delta_matches( $user_error_counts_before, $user_error_counts_after, array(), array() ),
			'user invalid/malformed ID, existing create, create permission, and HEAD paths are represented without row changes',
			array(
				'invalidItem'    => $invalid_item,
				'malformedItem'  => $malformed_item,
				'existingCreate' => $existing_create,
				'createDenied'   => $create_denied,
				'headData'       => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
				'countsBefore'   => $user_error_counts_before,
				'countsAfter'    => $user_error_counts_after,
			)
		);

		$cap_filter = self::install_cap_filter( array( 'create_users', 'delete_user', 'delete_users', 'edit_user', 'edit_users', 'list_users' ) );
		$user_counts_before = self::content_counts();
		$write_filter_restored = false;
		try {
			$created = $controller->create_item(
				self::request(
					'POST',
					'/wp/v2/users',
					array( '_fields' => 'id,username,name,email,slug' ),
					array(),
					array(
						'email'    => $case['createdUserEmail'],
						'name'     => $case['createdUserName'],
						'password' => $case['createdUserPassword'],
						'slug'     => $case['createdUserSlugInput'],
						'username' => $case['createdUserLogin'],
					)
				)
			);
			$created_data = $created instanceof \WP_REST_Response ? $created->get_data() : array();
			$created_id   = (int) ( $created_data['id'] ?? 0 );
			$username_update = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/users/' . $created_id,
					array(),
					array( 'id' => $created_id ),
					array( 'username' => $case['createdUserLogin'] . '_changed' )
				)
			);
			$updated = $controller->update_item(
				self::request(
					'PUT',
					'/wp/v2/users/' . $created_id,
					array( '_fields' => 'id,name,email,slug' ),
					array( 'id' => $created_id ),
					array( 'name' => $case['updatedUserName'] )
				)
			);
			$delete_error = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/users/' . $created_id,
					array(),
					array( 'id' => $created_id ),
					array(
						'force'    => false,
						'reassign' => false,
					)
				)
			);
		} finally {
			$write_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		$user_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			$created instanceof \WP_REST_Response
				&& 201 === $created->get_status()
				&& $created_id > 0
				&& $case['createdUserLogin'] === ( $created_data['username'] ?? null )
				&& \sanitize_title( $case['createdUserSlugInput'] ) === ( $created_data['slug'] ?? null )
				&& self::error_matches( $username_update, 'rest_user_invalid_argument', 400 )
				&& $updated instanceof \WP_REST_Response
				&& $case['updatedUserName'] === ( $updated_data['name'] ?? null )
				&& self::error_matches( $delete_error, 'rest_trash_not_supported', 501 )
				&& self::content_count_delta_matches(
					$user_counts_before,
					$user_counts_after,
					array( 'users' => 1 ),
					array( 'user_meta', 'users' )
				)
				&& $write_filter_restored,
			'user create/update/delete error paths use the in-memory user table',
			array(
				'createdData'         => $created_data,
				'usernameUpdate'      => $username_update,
				'updatedData'         => $updated_data,
				'deleteError'         => $delete_error,
				'contentCountsBefore' => $user_counts_before,
				'contentCountsAfter'  => $user_counts_after,
				'writeFilterRestored' => $write_filter_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.users.schema-permissions-read-write-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_revisions_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures, array &$additional_field_calls ): array {
		$controller = new \WP_REST_Revisions_Controller( 'post' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& self::collection_has_params( $params, array( 'exclude', 'include', 'order', 'orderby', 'per_page' ) )
				&& ! isset( $params['per_page']['default'] )
				&& self::collection_default_ok( $params, 'order', 'desc' )
				&& self::collection_default_ok( $params, 'orderby', 'date' )
				&& self::collection_enum_contains( $params, 'order', array( 'asc', 'desc' ) ),
			'revision collection params expose bounded controls without a default per_page',
			array(
				'params' => self::param_summary( $params ),
			)
		);

		$read_request = self::request(
			'GET',
			'/wp/v2/posts/' . $fixtures['post'] . '/revisions/' . $fixtures['revision'],
			array( 'context' => 'edit' ),
			array(
				'parent' => $fixtures['post'],
				'id'     => $fixtures['revision'],
			)
		);
		$read_denied = $controller->get_item_permissions_check( $read_request );
		$cap_filter  = self::install_cap_filter( array( 'edit_others_posts', 'edit_posts', 'edit_published_posts' ) );
		$read_filter_restored = false;
		try {
			$read_allowed = $controller->get_item_permissions_check( $read_request );
			$item         = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/posts/' . $fixtures['post'] . '/revisions/' . $fixtures['revision'],
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title,content,' . $case['revisionAdditionalField'] . ',_links',
					),
					array(
						'parent' => $fixtures['post'],
						'id'     => $fixtures['revision'],
					)
				)
			);
		} finally {
			$read_filter_restored = self::remove_cap_filter( $cap_filter );
		}

		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		self::collect_failure(
			$failures,
			self::error_matches( $read_denied, 'rest_cannot_read', 403 )
				&& true === $read_allowed
				&& $item instanceof \WP_REST_Response
				&& self::projected_keys_match( $item_data, array( 'content', 'id', 'parent', 'title', $case['revisionAdditionalField'] ) )
				&& self::response_matches_schema_context( $controller, $item_data, 'edit' )
				&& $fixtures['revision'] === (int) ( $item_data['id'] ?? 0 )
				&& $fixtures['post'] === (int) ( $item_data['parent'] ?? 0 )
				&& $case['revisionTitle'] === ( $item_data['title']['raw'] ?? null )
				&& $case['revisionContent'] === ( $item_data['content']['raw'] ?? null )
				&& $case['revisionAdditionalValue'] === ( $item_data[ $case['revisionAdditionalField'] ] ?? null )
				&& self::additional_field_call_count( $additional_field_calls, 'post-revision', $case['revisionAdditionalField'], 'edit', $fixtures['revision'] ) > 0
				&& \rest_url( 'wp/v2/posts/' . $fixtures['post'] ) === self::link_href( $item_links, 'parent' )
				&& $read_filter_restored,
			'revision get_item gates parent edit permission and exposes parent links',
			array(
				'readDenied'         => $read_denied,
				'readAllowed'        => $read_allowed,
				'itemData'           => $item_data,
				'links'              => $item_links,
				'readFilterRestored' => $read_filter_restored,
			)
		);

		$revision_error_counts_before = self::content_counts();
		$mismatch = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/posts/' . $fixtures['other_post'] . '/revisions/' . $fixtures['revision'],
				array( 'context' => 'view' ),
				array(
					'parent' => $fixtures['other_post'],
					'id'     => $fixtures['revision'],
				)
			)
		);
		$invalid_parent = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/posts/999999/revisions/' . $fixtures['revision'],
				array( 'context' => 'view' ),
				array(
					'parent' => 999999,
					'id'     => $fixtures['revision'],
				)
			)
		);
		$malformed_parent = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/posts/' . $case['malformedId'] . '/revisions/' . $fixtures['revision'],
				array( 'context' => 'view' ),
				array(
					'parent' => $case['malformedId'],
					'id'     => $fixtures['revision'],
				)
			)
		);
		$invalid_revision = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/posts/' . $fixtures['post'] . '/revisions/999999',
				array( 'context' => 'view' ),
				array(
					'parent' => $fixtures['post'],
					'id'     => 999999,
				)
			)
		);
		$delete_error = $controller->delete_item(
			self::request(
				'DELETE',
				'/wp/v2/posts/' . $fixtures['post'] . '/revisions/' . $fixtures['revision'],
				array(),
				array(
					'parent' => $fixtures['post'],
					'id'     => $fixtures['revision'],
				),
				array( 'force' => false )
			)
		);
		$revision_error_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			self::error_matches( $mismatch, 'rest_revision_parent_id_mismatch', 404 )
				&& self::error_matches( $invalid_parent, 'rest_post_invalid_parent', 404 )
				&& self::error_matches( $malformed_parent, 'rest_post_invalid_parent', 404 )
				&& self::error_matches( $invalid_revision, 'rest_post_invalid_id', 404 )
				&& self::error_matches( $delete_error, 'rest_trash_not_supported', 501 )
				&& self::content_count_delta_matches( $revision_error_counts_before, $revision_error_counts_after, array(), array() ),
			'revision parent mismatch, invalid/malformed parent/revision, and delete error paths are represented without row changes',
			array(
				'mismatch'        => $mismatch,
				'invalidParent'   => $invalid_parent,
				'malformedParent' => $malformed_parent,
				'invalidRevision' => $invalid_revision,
				'deleteError'     => $delete_error,
				'countsBefore'    => $revision_error_counts_before,
				'countsAfter'     => $revision_error_counts_after,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.revisions.schema-permissions-read-delete-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_attachments_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures, array &$additional_field_calls ): array {
		$controller = new \WP_REST_Attachments_Controller( 'attachment' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& self::collection_has_params(
					$params,
					array( 'media_type', 'mime_type', 'order', 'orderby', 'parent', 'per_page', 'search', 'slug', 'status' )
				)
				&& self::collection_default_ok( $params, 'status', 'inherit' )
				&& self::collection_default_ok( $params, 'order', 'desc' )
				&& self::collection_items_enum_contains( $params, 'media_type', array( 'image' ) ),
			'attachment collection params expose media filters and inherit status default',
			array( 'params' => self::param_summary( $params ) )
		);

		$item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/media/' . $fixtures['attachment'],
				array(
					'context' => 'view',
					'_fields' => 'id,title,media_type,mime_type,source_url,alt_text,' . $case['attachmentAdditionalField'] . ',_links',
				),
				array( 'id' => $fixtures['attachment'] )
			)
		);
		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		self::collect_failure(
			$failures,
			$item instanceof \WP_REST_Response
				&& self::projected_keys_match(
					$item_data,
					array( 'alt_text', 'id', 'media_type', 'mime_type', 'source_url', 'title', $case['attachmentAdditionalField'] )
				)
				&& self::response_matches_schema_context( $controller, $item_data, 'view' )
				&& $fixtures['attachment'] === (int) ( $item_data['id'] ?? 0 )
				&& 'image' === ( $item_data['media_type'] ?? null )
				&& 'image/jpeg' === ( $item_data['mime_type'] ?? null )
				&& $case['attachmentAlt'] === ( $item_data['alt_text'] ?? null )
				&& $case['attachmentAdditionalValue'] === ( $item_data[ $case['attachmentAdditionalField'] ] ?? null )
				&& self::additional_field_call_count( $additional_field_calls, 'attachment', $case['attachmentAdditionalField'], 'view', $fixtures['attachment'] ) > 0
				&& is_string( $item_data['source_url'] ?? null )
				&& '' !== ( $item_data['source_url'] ?? '' )
				&& \rest_url( 'wp/v2/media/' . $fixtures['attachment'] ) === self::link_href( $item_links, 'self' ),
			'attachment get_item exposes media fields, cache/meta-backed alt text, source URL, and links',
			array(
				'itemData' => $item_data,
				'links'    => $item_links,
			)
		);

		$invalid_item  = $controller->get_item(
			self::request( 'GET', '/wp/v2/media/999999', array(), array( 'id' => 999999 ) )
		);
		$malformed_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/media/' . $case['malformedId'], array(), array( 'id' => $case['malformedId'] ) )
		);
		$create_denied = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/media' ) );
		$cap_filter    = self::install_cap_filter( array( 'create_posts', 'delete_others_posts', 'delete_posts', 'delete_published_posts', 'edit_posts', 'upload_files' ) );
		$attachment_counts_before = self::content_counts();
		$write_filter_restored = false;
		try {
			$create_allowed = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/media' ) );
			$upload_error   = $controller->create_item( self::request( 'POST', '/wp/v2/media' ) );
			$delete_error   = $controller->delete_item(
				self::request(
					'DELETE',
					'/wp/v2/media/' . $fixtures['attachment'],
					array(),
					array( 'id' => $fixtures['attachment'] ),
					array( 'force' => false )
				)
			);
		} finally {
			$write_filter_restored = self::remove_cap_filter( $cap_filter );
		}
		$attachment_counts_after = self::content_counts();
		self::collect_failure(
			$failures,
			self::error_matches( $invalid_item, 'rest_post_invalid_id', 404 )
				&& self::error_matches( $malformed_item, 'rest_post_invalid_id', 404 )
				&& self::error_matches( $create_denied, 'rest_cannot_create', 403 )
				&& true === $create_allowed
				&& self::error_matches( $upload_error, 'rest_upload_no_data', 400 )
				&& self::error_matches( $delete_error, 'rest_trash_not_supported', 501 )
				&& self::content_count_delta_matches( $attachment_counts_before, $attachment_counts_after, array(), array() )
				&& $write_filter_restored,
			'attachment invalid ID, upload permission, no-file upload, and delete error paths are represented without real uploads',
			array(
				'invalidItem'         => $invalid_item,
				'malformedItem'       => $malformed_item,
				'createDenied'        => $create_denied,
				'createAllowed'       => $create_allowed,
				'uploadError'         => $upload_error,
				'deleteError'         => $delete_error,
				'contentCountsBefore' => $attachment_counts_before,
				'contentCountsAfter'  => $attachment_counts_after,
				'writeFilterRestored' => $write_filter_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.attachments.schema-permissions-read-upload-delete-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'skipped'  => array(
					'realUploads' => 'Multipart/raw upload bodies, sideloads, image editor post-processing, and remote fetches are intentionally not invoked.',
				),
			)
		);
	}

	private static function check_collection_parameter_matrix( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures    = array();
		$matrix_size = 0;
		$wp_sprintf_filter_added = false;
		if ( function_exists( 'wp_sprintf_l' ) && false === \has_filter( 'wp_sprintf', 'wp_sprintf_l' ) ) {
			\add_filter( 'wp_sprintf', 'wp_sprintf_l', 10, 2 );
			$wp_sprintf_filter_added = true;
		}

		$controllers = array(
			'posts'       => array(
				'controller'       => new \WP_REST_Posts_Controller( 'post' ),
				'route'            => '/wp/v2/posts',
				'orderbyAllowed'   => array( 'author', 'date', 'id', 'include', 'include_slugs', 'modified', 'parent', 'relevance', 'slug', 'title' ),
				'orderbyRejected'  => array( 'date_gmt', 'email', 'term_group' ),
				'parameterCases'   => self::post_collection_parameter_cases( $case ),
			),
			'terms'       => array(
				'controller'       => new \WP_REST_Terms_Controller( 'category' ),
				'route'            => '/wp/v2/categories',
				'orderbyAllowed'   => array( 'count', 'description', 'id', 'include', 'include_slugs', 'name', 'slug', 'term_group' ),
				'orderbyRejected'  => array( 'date', 'email', 'parent' ),
				'parameterCases'   => self::term_collection_parameter_cases( $case ),
			),
			'comments'    => array(
				'controller'       => new \WP_REST_Comments_Controller(),
				'route'            => '/wp/v2/comments',
				'orderbyAllowed'   => array( 'date', 'date_gmt', 'id', 'include', 'parent', 'post', 'type' ),
				'orderbyRejected'  => array( 'email', 'name', 'slug' ),
				'parameterCases'   => self::comment_collection_parameter_cases( $case ),
			),
			'users'       => array(
				'controller'       => new \WP_REST_Users_Controller(),
				'route'            => '/wp/v2/users',
				'orderbyAllowed'   => array( 'email', 'id', 'include', 'include_slugs', 'name', 'registered_date', 'slug', 'url' ),
				'orderbyRejected'  => array( 'date_gmt', 'parent', 'term_group' ),
				'parameterCases'   => self::user_collection_parameter_cases( $case ),
			),
			'revisions'   => array(
				'controller'       => new \WP_REST_Revisions_Controller( 'post' ),
				'route'            => '/wp/v2/posts/1/revisions',
				'orderbyAllowed'   => array( 'date', 'id', 'include', 'include_slugs', 'relevance', 'slug', 'title' ),
				'orderbyRejected'  => array( 'author', 'modified', 'parent' ),
				'parameterCases'   => self::revision_collection_parameter_cases(),
			),
			'attachments' => array(
				'controller'       => new \WP_REST_Attachments_Controller( 'attachment' ),
				'route'            => '/wp/v2/media',
				'orderbyAllowed'   => array( 'author', 'date', 'id', 'include', 'include_slugs', 'modified', 'parent', 'relevance', 'slug', 'title' ),
				'orderbyRejected'  => array( 'date_gmt', 'email', 'term_group' ),
				'parameterCases'   => self::attachment_collection_parameter_cases( $case ),
			),
		);

		foreach ( $controllers as $name => $spec ) {
			$params  = $spec['controller']->get_collection_params();
			$request = self::request( 'GET', $spec['route'] );
			$request->set_attributes( array( 'args' => $params ) );

			self::collect_failure(
				$failures,
				isset( $params['context'], $params['order'], $params['orderby'], $params['per_page'] ),
				'collection controller exposes shared collection params for matrix checks',
				array(
					'controller' => $name,
					'params'     => self::param_summary( $params ),
				)
			);

			$cases = array_merge( self::shared_collection_parameter_cases( $case ), $spec['parameterCases'] );
			foreach ( $cases as $param_case ) {
				++$matrix_size;
				self::assert_collection_parameter_case( $failures, $name, $params, $request, $param_case );
			}

			self::assert_orderby_matrix( $failures, $name, $params, $spec['orderbyAllowed'], $spec['orderbyRejected'] );
		}

		self::assert_private_post_status_gate( $failures );

		$wp_sprintf_filter_restored = true;
		if ( $wp_sprintf_filter_added ) {
			\remove_filter( 'wp_sprintf', 'wp_sprintf_l', 10 );
			$wp_sprintf_filter_restored = false === \has_filter( 'wp_sprintf', 'wp_sprintf_l' );
			self::collect_failure(
				$failures,
				$wp_sprintf_filter_restored,
				'temporary wp_sprintf list formatter filter is restored after enum matrix checks',
				array( 'filterRestored' => $wp_sprintf_filter_restored )
			);
		}

		return self::row(
			$ctx,
			'rest-object-controllers.collections.param-sanitize-validate-matrix',
			array() === $failures,
			array(
				'case'       => self::case_summary( $case ),
				'matrixSize' => $matrix_size,
				'failures'   => array_slice( $failures, 0, 10 ),
			)
		);
	}

	private static function shared_collection_parameter_cases( array $case ): array {
		$search_raw = "  Find <b>{$case['token']}</b>\n";

		return array(
			array(
				'param'             => 'context',
				'value'             => 'View!!',
				'schemaValid'       => false,
				'schemaError'       => 'rest_not_in_enum',
				'schemaSanitized'   => 'View!!',
				'callbackValid'     => false,
				'callbackError'     => 'rest_not_in_enum',
				'callbackSanitized' => 'view',
			),
			array(
				'param'             => 'search',
				'value'             => $search_raw,
				'schemaValid'       => true,
				'schemaSanitized'   => $search_raw,
				'callbackValid'     => true,
				'callbackSanitized' => \sanitize_text_field( $search_raw ),
			),
			array(
				'param'             => 'per_page',
				'value'             => 1,
				'schemaValid'       => true,
				'schemaSanitized'   => 1,
				'callbackValid'     => true,
				'callbackSanitized' => 1,
			),
			array(
				'param'             => 'per_page',
				'value'             => 100,
				'schemaValid'       => true,
				'schemaSanitized'   => 100,
				'callbackValid'     => true,
				'callbackSanitized' => 100,
			),
			array(
				'param'             => 'per_page',
				'value'             => 0,
				'schemaValid'       => false,
				'schemaError'       => 'rest_out_of_bounds',
				'schemaSanitized'   => 0,
				'callbackValid'     => false,
				'callbackError'     => 'rest_out_of_bounds',
				'callbackSanitized' => 0,
			),
			array(
				'param'             => 'per_page',
				'value'             => 101,
				'schemaValid'       => false,
				'schemaError'       => 'rest_out_of_bounds',
				'schemaSanitized'   => 101,
				'callbackValid'     => false,
				'callbackError'     => 'rest_out_of_bounds',
				'callbackSanitized' => 101,
			),
			array(
				'param'           => 'order',
				'value'           => 'desc',
				'schemaValid'     => true,
				'schemaSanitized' => 'desc',
			),
			array(
				'param'           => 'order',
				'value'           => 'ASC',
				'schemaValid'     => false,
				'schemaError'     => 'rest_not_in_enum',
				'schemaSanitized' => 'ASC',
			),
		);
	}

	private static function post_collection_parameter_cases( array $case ): array {
		$slug_value = 'Post-Slug-' . $case['token'] . ',second-' . $case['token'];

		return array(
			array(
				'param'           => 'include',
				'value'           => array( '7', '09' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 7, 9 ),
			),
			array(
				'param'           => 'exclude',
				'value'           => '4,8',
				'schemaValid'     => true,
				'schemaSanitized' => array( 4, 8 ),
			),
			array(
				'param'           => 'slug',
				'value'           => $slug_value,
				'schemaValid'     => true,
				'schemaSanitized' => array( 'Post-Slug-' . $case['token'], 'second-' . $case['token'] ),
			),
			array(
				'param'           => 'search_columns',
				'value'           => 'post_title,post_excerpt',
				'schemaValid'     => true,
				'schemaSanitized' => array( 'post_title', 'post_excerpt' ),
			),
			array(
				'param'           => 'search_columns',
				'value'           => array( 'post_title', 'not_a_post_column' ),
				'schemaValid'     => false,
				'schemaError'     => 'rest_not_in_enum',
				'schemaSanitized' => array( 'post_title', 'not_a_post_column' ),
			),
			array(
				'param'             => 'status',
				'value'             => 'publish',
				'schemaValid'       => true,
				'schemaSanitized'   => array( 'publish' ),
				'callbackSanitized' => array( 'publish' ),
			),
		);
	}

	private static function term_collection_parameter_cases( array $case ): array {
		return array(
			array(
				'param'           => 'include',
				'value'           => array( '11', '12' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 11, 12 ),
			),
			array(
				'param'           => 'exclude',
				'value'           => '13,14',
				'schemaValid'     => true,
				'schemaSanitized' => array( 13, 14 ),
			),
			array(
				'param'           => 'slug',
				'value'           => 'Term-Slug-' . $case['token'] . ',child-' . $case['token'],
				'schemaValid'     => true,
				'schemaSanitized' => array( 'Term-Slug-' . $case['token'], 'child-' . $case['token'] ),
			),
		);
	}

	private static function comment_collection_parameter_cases( array $case ): array {
		return array(
			array(
				'param'           => 'include',
				'value'           => array( '21', '22' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 21, 22 ),
			),
			array(
				'param'           => 'exclude',
				'value'           => '23,24',
				'schemaValid'     => true,
				'schemaSanitized' => array( 23, 24 ),
			),
			array(
				'param'           => 'post',
				'value'           => '25,26',
				'schemaValid'     => true,
				'schemaSanitized' => array( 25, 26 ),
			),
			array(
				'param'           => 'parent',
				'value'           => array( '27', '28' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 27, 28 ),
			),
			array(
				'param'             => 'status',
				'value'             => 'Approved!!',
				'schemaValid'       => true,
				'schemaSanitized'   => 'Approved!!',
				'callbackValid'     => true,
				'callbackSanitized' => 'approved',
			),
			array(
				'param'             => 'type',
				'value'             => 'Comment!!',
				'schemaValid'       => true,
				'schemaSanitized'   => 'Comment!!',
				'callbackValid'     => true,
				'callbackSanitized' => 'comment',
			),
		);
	}

	private static function user_collection_parameter_cases( array $case ): array {
		return array(
			array(
				'param'           => 'include',
				'value'           => array( '31', '32' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 31, 32 ),
			),
			array(
				'param'           => 'exclude',
				'value'           => '33,34',
				'schemaValid'     => true,
				'schemaSanitized' => array( 33, 34 ),
			),
			array(
				'param'           => 'slug',
				'value'           => 'User-Slug-' . $case['token'] . ',author-' . $case['token'],
				'schemaValid'     => true,
				'schemaSanitized' => array( 'User-Slug-' . $case['token'], 'author-' . $case['token'] ),
			),
			array(
				'param'           => 'roles',
				'value'           => 'editor,subscriber',
				'schemaValid'     => true,
				'schemaSanitized' => array( 'editor', 'subscriber' ),
			),
			array(
				'param'           => 'capabilities',
				'value'           => array( 'edit_posts', 'read' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 'edit_posts', 'read' ),
			),
			array(
				'param'           => 'search_columns',
				'value'           => 'email,username',
				'schemaValid'     => true,
				'schemaSanitized' => array( 'email', 'username' ),
			),
			array(
				'param'           => 'search_columns',
				'value'           => array( 'email', 'post_title' ),
				'schemaValid'     => false,
				'schemaError'     => 'rest_not_in_enum',
				'schemaSanitized' => array( 'email', 'post_title' ),
			),
		);
	}

	private static function revision_collection_parameter_cases(): array {
		return array(
			array(
				'param'           => 'include',
				'value'           => array( '41', '42' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 41, 42 ),
			),
			array(
				'param'           => 'exclude',
				'value'           => '43,44',
				'schemaValid'     => true,
				'schemaSanitized' => array( 43, 44 ),
			),
		);
	}

	private static function attachment_collection_parameter_cases( array $case ): array {
		return array(
			array(
				'param'           => 'include',
				'value'           => array( '51', '52' ),
				'schemaValid'     => true,
				'schemaSanitized' => array( 51, 52 ),
			),
			array(
				'param'           => 'exclude',
				'value'           => '53,54',
				'schemaValid'     => true,
				'schemaSanitized' => array( 53, 54 ),
			),
			array(
				'param'           => 'slug',
				'value'           => 'Media-Slug-' . $case['token'] . ',attachment-' . $case['token'],
				'schemaValid'     => true,
				'schemaSanitized' => array( 'Media-Slug-' . $case['token'], 'attachment-' . $case['token'] ),
			),
			array(
				'param'           => 'media_type',
				'value'           => 'image',
				'schemaValid'     => true,
				'schemaSanitized' => array( 'image' ),
			),
			array(
				'param'           => 'media_type',
				'value'           => 'not-a-media-type',
				'schemaValid'     => false,
				'schemaError'     => 'rest_not_in_enum',
				'schemaSanitized' => array( 'not-a-media-type' ),
			),
			array(
				'param'           => 'mime_type',
				'value'           => 'image/jpeg,text/plain',
				'schemaValid'     => true,
				'schemaSanitized' => array( 'image/jpeg', 'text/plain' ),
			),
			array(
				'param'             => 'status',
				'value'             => 'inherit',
				'schemaValid'       => true,
				'schemaSanitized'   => array( 'inherit' ),
				'callbackSanitized' => array( 'inherit' ),
			),
		);
	}

	private static function assert_collection_parameter_case( array &$failures, string $controller, array $params, \WP_REST_Request $request, array $case ): void {
		$param = $case['param'];
		if ( ! isset( $params[ $param ] ) || ! is_array( $params[ $param ] ) ) {
			self::collect_failure(
				$failures,
				false,
				'collection parameter matrix target is present',
				array(
					'controller' => $controller,
					'param'      => $param,
					'available'  => array_keys( $params ),
				)
			);
			return;
		}

		$schema           = $params[ $param ];
		$schema_valid     = \rest_validate_value_from_schema( $case['value'], $schema, $param );
		$schema_sanitized = \rest_sanitize_value_from_schema( $case['value'], $schema, $param );
		$callback_valid   = null;
		$callback_sanitized = null;
		$callback_failures = array();

		if ( isset( $schema['validate_callback'] ) ) {
			if ( is_callable( $schema['validate_callback'] ) ) {
				$callback_valid = call_user_func( $schema['validate_callback'], $case['value'], $request, $param );
			} else {
				$callback_failures[] = 'validate_callback is not callable';
			}
		}

		if ( isset( $schema['sanitize_callback'] ) ) {
			if ( is_callable( $schema['sanitize_callback'] ) ) {
				$callback_sanitized = call_user_func( $schema['sanitize_callback'], $case['value'], $request, $param );
			} else {
				$callback_failures[] = 'sanitize_callback is not callable';
			}
		}

		$expected_callback_valid = $case['callbackValid'] ?? $case['schemaValid'];
		$ok = array() === $callback_failures
			&& self::validation_result_matches( $schema_valid, (bool) $case['schemaValid'], $case['schemaError'] ?? null )
			&& self::sanitized_result_matches( $schema_sanitized, $case['schemaSanitized'] ?? null, $case['schemaSanitizeError'] ?? null );

		if ( null !== $callback_valid || array_key_exists( 'callbackValid', $case ) || array_key_exists( 'callbackError', $case ) ) {
			$ok = $ok && self::validation_result_matches( $callback_valid, (bool) $expected_callback_valid, $case['callbackError'] ?? null );
		}

		if ( null !== $callback_sanitized || array_key_exists( 'callbackSanitized', $case ) || array_key_exists( 'callbackSanitizeError', $case ) ) {
			$ok = $ok && self::sanitized_result_matches( $callback_sanitized, $case['callbackSanitized'] ?? null, $case['callbackSanitizeError'] ?? null );
		}

		self::collect_failure(
			$failures,
			$ok,
			'collection parameter schema and callbacks match deterministic matrix',
			array(
				'controller'        => $controller,
				'param'             => $param,
				'value'             => $case['value'],
				'expected'          => $case,
				'schemaValid'       => $schema_valid,
				'schemaSanitized'   => $schema_sanitized,
				'callbackValid'     => $callback_valid,
				'callbackSanitized' => $callback_sanitized,
				'callbackFailures'  => $callback_failures,
				'paramSummary'      => self::param_summary( array( $param => $schema ) ),
			)
		);
	}

	private static function assert_orderby_matrix( array &$failures, string $controller, array $params, array $allowed, array $rejected ): void {
		$actual = $params['orderby']['enum'] ?? null;
		if ( ! is_array( $actual ) ) {
			self::collect_failure(
				$failures,
				false,
				'collection orderby enum is available for matrix checks',
				array(
					'controller' => $controller,
					'params'     => self::param_summary( $params ),
				)
			);
			return;
		}

		$actual_sorted  = array_values( $actual );
		$allowed_sorted = array_values( $allowed );
		sort( $actual_sorted );
		sort( $allowed_sorted );

		$allowed_ok = true;
		foreach ( $allowed as $value ) {
			$allowed_ok = $allowed_ok && true === \rest_validate_value_from_schema( $value, $params['orderby'], 'orderby' );
		}

		$rejected_results = array();
		$rejected_ok      = true;
		foreach ( $rejected as $value ) {
			$result                    = \rest_validate_value_from_schema( $value, $params['orderby'], 'orderby' );
			$rejected_results[ $value ] = $result;
			$rejected_ok               = $rejected_ok && self::validation_result_matches( $result, false, 'rest_not_in_enum' );
		}

		self::collect_failure(
			$failures,
			$actual_sorted === $allowed_sorted && $allowed_ok && $rejected_ok,
			'collection orderby enum differs by controller and rejects foreign values',
			array(
				'controller'      => $controller,
				'actual'          => $actual,
				'expectedAllowed' => $allowed,
				'rejected'        => $rejected_results,
			)
		);
	}

	private static function assert_private_post_status_gate( array &$failures ): void {
		$controller = new \WP_REST_Posts_Controller( 'post' );
		$params     = $controller->get_collection_params();
		$request    = self::request( 'GET', '/wp/v2/posts' );
		$request->set_attributes( array( 'args' => $params ) );

		$private_denied = call_user_func( $params['status']['sanitize_callback'], 'private', $request, 'status' );
		$cap_filter     = self::install_cap_filter( array( 'edit_posts', 'read_private_posts' ) );
		$filter_restored = false;
		try {
			$private_allowed = call_user_func( $params['status']['sanitize_callback'], 'private', $request, 'status' );
		} finally {
			$filter_restored = self::remove_cap_filter( $cap_filter );
		}

		self::collect_failure(
			$failures,
			self::error_matches( $private_denied, 'rest_forbidden_status', 403 )
				&& array( 'private' ) === $private_allowed
				&& $filter_restored,
			'private post status collection sanitizer remains capability-gated and restores filters',
			array(
				'privateDenied' => $private_denied,
				'privateAllowed' => $private_allowed,
				'filterRestored' => $filter_restored,
			)
		);
	}

	private static function validation_result_matches( $result, bool $expected_valid, ?string $expected_error = null ): bool {
		if ( $expected_valid ) {
			return true === $result;
		}

		if ( ! $result instanceof \WP_Error ) {
			return false;
		}

		return null === $expected_error || $expected_error === $result->get_error_code();
	}

	private static function sanitized_result_matches( $result, $expected_value = null, ?string $expected_error = null ): bool {
		if ( null !== $expected_error ) {
			return $result instanceof \WP_Error && $expected_error === $result->get_error_code();
		}

		return $expected_value === $result;
	}

	private static function check_additional_field_registry( \ComponentFuzz\FuzzContext $ctx, array $case, array $additional_field_calls ): array {
		$failures = array();
		$registry = $GLOBALS['wp_rest_additional_fields'] ?? array();
		$fields   = array(
			array(
				'controller' => new \WP_REST_Posts_Controller( 'post' ),
				'contexts'   => array( 'edit' ),
				'field'      => $case['postAdditionalField'],
				'object'     => 'post',
				'value'      => $case['postAdditionalValue'],
			),
			array(
				'controller' => new \WP_REST_Terms_Controller( 'category' ),
				'contexts'   => array( 'view', 'edit' ),
				'field'      => $case['termAdditionalField'],
				'object'     => 'category',
				'value'      => $case['termAdditionalValue'],
			),
			array(
				'controller' => new \WP_REST_Comments_Controller(),
				'contexts'   => array( 'edit' ),
				'field'      => $case['commentAdditionalField'],
				'object'     => 'comment',
				'value'      => $case['commentAdditionalValue'],
			),
			array(
				'controller' => new \WP_REST_Users_Controller(),
				'contexts'   => array( 'embed', 'view', 'edit' ),
				'field'      => $case['userAdditionalField'],
				'object'     => 'user',
				'value'      => $case['userAdditionalValue'],
			),
			array(
				'controller' => new \WP_REST_Revisions_Controller( 'post' ),
				'contexts'   => array( 'edit' ),
				'field'      => $case['revisionAdditionalField'],
				'object'     => 'post-revision',
				'value'      => $case['revisionAdditionalValue'],
			),
			array(
				'controller' => new \WP_REST_Attachments_Controller( 'attachment' ),
				'contexts'   => array( 'view', 'edit', 'embed' ),
				'field'      => $case['attachmentAdditionalField'],
				'object'     => 'attachment',
				'value'      => $case['attachmentAdditionalValue'],
			),
		);

		foreach ( $fields as $field ) {
			self::collect_failure(
				$failures,
				isset( $registry[ $field['object'] ][ $field['field'] ] )
					&& self::schema_property_matches( $field['controller'], $field['field'], $field['contexts'], $field['value'] )
					&& self::additional_field_call_count( $additional_field_calls, $field['object'], $field['field'] ) > 0,
				'registered additional field is schema-visible and exercised',
				array(
					'object' => $field['object'],
					'field'  => $field['field'],
					'calls'  => self::additional_field_call_count( $additional_field_calls, $field['object'], $field['field'] ),
				)
			);
		}

		return self::row(
			$ctx,
			'rest-object-controllers.additional-fields.schema-callbacks-registry',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'calls'    => array_slice( $additional_field_calls, 0, 20 ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_route_registry_behavior( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();

		$previous_server  = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions   = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions = $GLOBALS['wp_actions'] ?? null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		try {
			$controllers = array(
				new \WP_REST_Posts_Controller( 'post' ),
				new \WP_REST_Terms_Controller( 'category' ),
				new \WP_REST_Comments_Controller(),
				new \WP_REST_Users_Controller(),
				new \WP_REST_Revisions_Controller( 'post' ),
				new \WP_REST_Attachments_Controller( 'attachment' ),
			);

			foreach ( $controllers as $controller ) {
				$controller->register_routes();
			}

			$routes          = $server->get_routes( 'wp/v2' );
			$registered_keys = array_keys( $routes );
			sort( $registered_keys );
			$expected_routes = array(
				'/wp/v2/categories',
				'/wp/v2/categories/(?P<id>[\d]+)',
				'/wp/v2/comments',
				'/wp/v2/comments/(?P<id>[\d]+)',
				'/wp/v2/media',
				'/wp/v2/media/(?P<id>[\d]+)',
				'/wp/v2/media/(?P<id>[\d]+)/edit',
				'/wp/v2/media/(?P<id>[\d]+)/post-process',
				'/wp/v2/posts',
				'/wp/v2/posts/(?P<id>[\d]+)',
				'/wp/v2/posts/(?P<parent>[\d]+)/revisions',
				'/wp/v2/posts/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)',
				'/wp/v2/users',
				'/wp/v2/users/(?P<id>[\d]+)',
				'/wp/v2/users/me',
			);
			$missing_routes = array_values( array_diff( $expected_routes, $registered_keys ) );

			$posts_methods         = self::route_methods( $routes['/wp/v2/posts'] ?? array() );
			$post_item_methods     = self::route_methods( $routes['/wp/v2/posts/(?P<id>[\d]+)'] ?? array() );
			$term_item_methods     = self::route_methods( $routes['/wp/v2/categories/(?P<id>[\d]+)'] ?? array() );
			$revision_item_methods = self::route_methods( $routes['/wp/v2/posts/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)'] ?? array() );
			$media_edit_methods    = self::route_methods( $routes['/wp/v2/media/(?P<id>[\d]+)/edit'] ?? array() );
			$media_process_methods = self::route_methods( $routes['/wp/v2/media/(?P<id>[\d]+)/post-process'] ?? array() );
			$users_me_methods      = self::route_methods( $routes['/wp/v2/users/me'] ?? array() );

			self::collect_failure(
				$failures,
				in_array( 'wp/v2', $server->get_namespaces(), true )
					&& array() === $missing_routes
					&& in_array( 'GET', $posts_methods, true )
					&& in_array( 'POST', $posts_methods, true )
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $post_item_methods
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $term_item_methods
					&& array( 'DELETE', 'GET' ) === $revision_item_methods
					&& array( 'POST' ) === $media_edit_methods
					&& array( 'POST' ) === $media_process_methods
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $users_me_methods
					&& is_callable( $server->get_route_options( '/wp/v2/posts' )['schema'] ?? null )
					&& is_callable( $server->get_route_options( '/wp/v2/categories' )['schema'] ?? null )
					&& is_callable( $server->get_route_options( '/wp/v2/comments' )['schema'] ?? null )
					&& is_callable( $server->get_route_options( '/wp/v2/users' )['schema'] ?? null )
					&& is_callable( $server->get_route_options( '/wp/v2/posts/(?P<parent>[\d]+)/revisions' )['schema'] ?? null )
					&& is_callable( $server->get_route_options( '/wp/v2/media' )['schema'] ?? null ),
				'object controllers register expected wp/v2 routes, methods, and schemas',
				array(
					'expectedRoutes'       => $expected_routes,
					'registeredRoutes'     => $registered_keys,
					'missingRoutes'        => $missing_routes,
					'postsMethods'         => $posts_methods,
					'postItemMethods'      => $post_item_methods,
					'termItemMethods'      => $term_item_methods,
					'revisionItemMethods'  => $revision_item_methods,
					'mediaEditMethods'     => $media_edit_methods,
					'mediaProcessMethods'  => $media_process_methods,
					'usersMeMethods'       => $users_me_methods,
				)
			);

			$post_collection_data = $server->get_data_for_route(
				'/wp/v2/posts',
				$routes['/wp/v2/posts'] ?? array(),
				'help'
			);
			$post_item_data       = $server->get_data_for_route(
				'/wp/v2/posts/(?P<id>[\d]+)',
				$routes['/wp/v2/posts/(?P<id>[\d]+)'] ?? array(),
				'help'
			);
			$users_me_data        = $server->get_data_for_route(
				'/wp/v2/users/me',
				$routes['/wp/v2/users/me'] ?? array(),
				'help'
			);

			self::collect_failure(
				$failures,
				self::route_data_has_methods( $post_collection_data, array( 'GET', 'POST' ) )
					&& self::route_data_has_methods( $post_item_data, array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) )
					&& self::route_data_has_methods( $users_me_data, array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) )
					&& self::route_data_has_endpoint_args(
						$post_collection_data,
						array( 'GET' ),
						array( 'context', 'page', 'per_page', 'status' )
					)
					&& self::route_data_has_endpoint_args(
						$post_collection_data,
						array( 'POST' ),
						array( 'content', 'status', 'title' )
					)
					&& self::route_data_has_endpoint_args(
						$post_item_data,
						array( 'GET' ),
						array( 'context' )
					)
					&& self::route_data_has_endpoint_args(
						$post_item_data,
						array( 'DELETE' ),
						array( 'force' )
					)
					&& self::route_data_endpoint_allows_batch( $post_collection_data, array( 'GET' ) )
					&& self::route_data_endpoint_allows_batch( $post_collection_data, array( 'POST' ) )
					&& self::route_data_schema_has_properties(
						$post_collection_data,
						array( 'id', 'slug', 'title', $case['postAdditionalField'] )
					)
					&& self::route_data_schema_contexts_match(
						$post_collection_data,
						$case['postAdditionalField'],
						array( 'edit' )
					)
					&& \rest_url( 'wp/v2/posts' ) === self::route_data_self_href( $post_collection_data )
					&& null === self::route_data_self_href( $post_item_data ),
				'object route index data exposes schemas, endpoint args, batch flags, and link projection',
				array(
					'postCollectionData' => $post_collection_data,
					'postItemData'       => $post_item_data,
					'usersMeData'        => $users_me_data,
				)
			);

			$cap_filter = self::install_cap_filter(
				array(
					'edit_categories',
					'edit_others_posts',
					'edit_post',
					'edit_posts',
					'edit_published_posts',
					'edit_user',
					'edit_users',
					'list_users',
					'manage_categories',
					'read',
					'upload_files',
				)
			);
			$dispatch_filter_restored = false;
			try {
				$post_request = self::request(
					'GET',
					'/wp/v2/posts/' . $fixtures['post'],
					array(
						'context' => 'edit',
						'_fields' => 'id,title,slug',
					)
				);
				$post_response = $server->dispatch( $post_request );

				$term_request = self::request(
					'GET',
					'/wp/v2/categories/' . $fixtures['term'],
					array( '_fields' => 'id,name,slug' )
				);
				$term_response = $server->dispatch( $term_request );

				$comment_request = self::request(
					'GET',
					'/wp/v2/comments/' . $fixtures['comment'],
					array( '_fields' => 'id,post,status' )
				);
				$comment_response = $server->dispatch( $comment_request );

				$user_request = self::request(
					'GET',
					'/wp/v2/users/' . $fixtures['author'],
					array(
						'context' => 'edit',
						'_fields' => 'id,username,email',
					)
				);
				$user_response = $server->dispatch( $user_request );

				$revision_request = self::request(
					'GET',
					'/wp/v2/posts/' . $fixtures['post'] . '/revisions/' . $fixtures['revision'],
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title',
					)
				);
				$revision_response = $server->dispatch( $revision_request );

				$attachment_request = self::request(
					'GET',
					'/wp/v2/media/' . $fixtures['attachment'],
					array( '_fields' => 'id,media_type,mime_type' )
				);
				$attachment_response = $server->dispatch( $attachment_request );
			} finally {
				$dispatch_filter_restored = self::remove_cap_filter( $cap_filter );
			}

			$post_data       = $post_response instanceof \WP_REST_Response ? $post_response->get_data() : array();
			$term_data       = $term_response instanceof \WP_REST_Response ? $term_response->get_data() : array();
			$comment_data    = $comment_response instanceof \WP_REST_Response ? $comment_response->get_data() : array();
			$user_data       = $user_response instanceof \WP_REST_Response ? $user_response->get_data() : array();
			$revision_data   = $revision_response instanceof \WP_REST_Response ? $revision_response->get_data() : array();
			$attachment_data = $attachment_response instanceof \WP_REST_Response ? $attachment_response->get_data() : array();

			self::collect_failure(
				$failures,
				$post_response instanceof \WP_REST_Response
					&& 200 === $post_response->get_status()
					&& $fixtures['post'] === (int) ( $post_data['id'] ?? 0 )
					&& $case['postTitle'] === ( $post_data['title']['raw'] ?? null )
					&& (string) $fixtures['post'] === (string) ( $post_request->get_url_params()['id'] ?? '' )
					&& $term_response instanceof \WP_REST_Response
					&& 200 === $term_response->get_status()
					&& $fixtures['term'] === (int) ( $term_data['id'] ?? 0 )
					&& $case['termName'] === ( $term_data['name'] ?? null )
					&& (string) $fixtures['term'] === (string) ( $term_request->get_url_params()['id'] ?? '' )
					&& $comment_response instanceof \WP_REST_Response
					&& 200 === $comment_response->get_status()
					&& $fixtures['comment'] === (int) ( $comment_data['id'] ?? 0 )
					&& $fixtures['post'] === (int) ( $comment_data['post'] ?? 0 )
					&& (string) $fixtures['comment'] === (string) ( $comment_request->get_url_params()['id'] ?? '' )
					&& $user_response instanceof \WP_REST_Response
					&& 200 === $user_response->get_status()
					&& $fixtures['author'] === (int) ( $user_data['id'] ?? 0 )
					&& $case['authorEmail'] === ( $user_data['email'] ?? null )
					&& (string) $fixtures['author'] === (string) ( $user_request->get_url_params()['id'] ?? '' )
					&& $revision_response instanceof \WP_REST_Response
					&& 200 === $revision_response->get_status()
					&& $fixtures['revision'] === (int) ( $revision_data['id'] ?? 0 )
					&& $fixtures['post'] === (int) ( $revision_data['parent'] ?? 0 )
					&& (string) $fixtures['post'] === (string) ( $revision_request->get_url_params()['parent'] ?? '' )
					&& (string) $fixtures['revision'] === (string) ( $revision_request->get_url_params()['id'] ?? '' )
					&& $attachment_response instanceof \WP_REST_Response
					&& 200 === $attachment_response->get_status()
					&& $fixtures['attachment'] === (int) ( $attachment_data['id'] ?? 0 )
					&& 'image' === ( $attachment_data['media_type'] ?? null )
					&& 'image/jpeg' === ( $attachment_data['mime_type'] ?? null )
					&& (string) $fixtures['attachment'] === (string) ( $attachment_request->get_url_params()['id'] ?? '' )
					&& $dispatch_filter_restored,
				'registered object routes dispatch to controller callbacks with URL params and fixture-backed data',
				array(
					'postData'               => $post_data,
					'postUrlParams'          => $post_request->get_url_params(),
					'termData'               => $term_data,
					'termUrlParams'          => $term_request->get_url_params(),
					'commentData'            => $comment_data,
					'commentUrlParams'       => $comment_request->get_url_params(),
					'userData'               => $user_data,
					'userUrlParams'          => $user_request->get_url_params(),
					'revisionData'           => $revision_data,
					'revisionUrlParams'      => $revision_request->get_url_params(),
					'attachmentData'         => $attachment_data,
					'attachmentUrlParams'    => $attachment_request->get_url_params(),
					'dispatchFilterRestored' => $dispatch_filter_restored,
				)
			);

			$head_response     = $server->dispatch( self::request( 'HEAD', '/wp/v2/posts/' . $fixtures['post'] ) );
			$missing_response  = $server->dispatch( self::request( 'GET', '/wp/v2/not-an-object-controller' ) );
			$readonly_response = $server->dispatch( self::request( 'DELETE', '/wp/v2/posts' ) );

			self::collect_failure(
				$failures,
				$head_response instanceof \WP_REST_Response
					&& 200 === $head_response->get_status()
					&& array() === $head_response->get_data()
					&& self::response_error_ok( $missing_response, 'rest_no_route', 404 )
					&& self::response_error_ok( $readonly_response, 'rest_no_route', 404 ),
				'registered object route dispatch preserves HEAD fallback and represented no-route errors',
				array(
					'headData'      => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
					'missingRoute'  => $missing_response,
					'readonlyRoute' => $readonly_response,
				)
			);
		} finally {
			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $previous_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}

			if ( null !== $previous_server ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		return self::row(
			$ctx,
			'rest-object-controllers.route-registry-dispatch-errors',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function object_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'sha1', (string) $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );

		return array(
			'token'                       => $token,
			'authorLogin'                 => 'cfz_author_' . $token,
			'authorEmail'                 => 'author-' . $token . '@example.test',
			'authorName'                  => 'Author ' . $ctx->int( 10, 999 ),
			'postTitle'                   => 'REST Object Post ' . $ctx->int( 10, 999 ),
			'postContent'                 => '<p>Fixture content ' . $token . '</p>',
			'postExcerpt'                 => 'Fixture excerpt ' . $token,
			'postMetaKey'                 => 'cfz_rest_meta_' . $token,
			'postMetaInput'               => " <b>Meta {$token}</b>\n",
			'postMetaUpdateInput'         => " Updated\t{$token}<script>x</script> ",
			'postMetaStored'              => 'stored-' . $token,
			'postMetaSanitized'           => \sanitize_text_field( " <b>Meta {$token}</b>\n" ),
			'postMetaUpdateSanitized'     => \sanitize_text_field( " Updated\t{$token}<script>x</script> " ),
			'postAdditionalField'         => 'cfz_post_field_' . $token,
			'postAdditionalValue'         => 'post-extra-' . $token,
			'createdPostTitle'            => 'Created REST Post ' . $ctx->int( 10, 999 ),
			'createdPostContent'          => '<p>Created content ' . $token . '</p>',
			'createdPostSlugInput'        => 'Created REST Slug ' . $token,
			'updatedPostTitle'            => 'Updated REST Post ' . $ctx->int( 10, 999 ),
			'termName'                    => 'REST Category ' . $ctx->int( 10, 999 ),
			'termSlugInput'               => 'Term Slug ' . $token,
			'childTermName'               => 'Child Term ' . $ctx->int( 10, 999 ),
			'childTermSlugInput'          => 'Child Term Slug ' . $token,
			'createdTermName'             => 'Created Term ' . $ctx->int( 10, 999 ),
			'createdTermDescription'      => 'Generated term description ' . $token,
			'createdTermSlugInput'        => 'Created Term Slug ' . $token,
			'updatedTermName'             => 'Updated Term ' . $ctx->int( 10, 999 ),
			'termAdditionalField'         => 'cfz_term_field_' . $token,
			'termAdditionalValue'         => 'term-extra-' . $token,
			'commentAuthorName'           => 'Commenter ' . $ctx->int( 10, 999 ),
			'commentAuthorEmail'          => 'commenter-' . $token . '@example.test',
			'commentContent'              => 'Fixture comment ' . $token,
			'secondCommentContent'        => 'Second fixture comment ' . $token,
			'createdCommentContent'       => 'Created comment ' . $token,
			'createdCommentContentPadded' => "  Created comment {$token}\n",
			'updatedCommentContent'       => 'Updated comment ' . $token,
			'commentAdditionalField'      => 'cfz_comment_field_' . $token,
			'commentAdditionalValue'      => 'comment-extra-' . $token,
			'createdUserLogin'            => 'cfz_user_' . $token,
			'createdUserEmail'            => 'created-' . $token . '@example.test',
			'createdUserName'             => 'Created User ' . $ctx->int( 10, 999 ),
			'createdUserPassword'         => 'pass-' . $token . '-A1',
			'createdUserSlugInput'        => 'Created User ' . $token,
			'updatedUserName'             => 'Updated User ' . $ctx->int( 10, 999 ),
			'userAdditionalField'         => 'cfz_user_field_' . $token,
			'userAdditionalValue'         => 'user-extra-' . $token,
			'revisionTitle'               => 'Revision Title ' . $ctx->int( 10, 999 ),
			'revisionContent'             => '<p>Revision content ' . $token . '</p>',
			'revisionAdditionalField'     => 'cfz_revision_field_' . $token,
			'revisionAdditionalValue'     => 'revision-extra-' . $token,
			'attachmentTitle'             => 'Attachment ' . $ctx->int( 10, 999 ),
			'attachmentAlt'               => 'Alt text ' . $token,
			'attachmentFile'              => 'component-fuzz-' . $token . '.jpg',
			'attachmentAdditionalField'   => 'cfz_attachment_field_' . $token,
			'attachmentAdditionalValue'   => 'attachment-extra-' . $token,
			'malformedId'                 => -1 * $ctx->int( 1, 999 ),
		);
	}

	private static function register_additional_fields( array $case, array &$calls ): void {
		$fields = array(
			'post'          => array(
				'field'    => $case['postAdditionalField'],
				'value'    => $case['postAdditionalValue'],
				'contexts' => array( 'edit' ),
			),
			'category'      => array(
				'field'    => $case['termAdditionalField'],
				'value'    => $case['termAdditionalValue'],
				'contexts' => array( 'view', 'edit' ),
			),
			'comment'       => array(
				'field'    => $case['commentAdditionalField'],
				'value'    => $case['commentAdditionalValue'],
				'contexts' => array( 'edit' ),
			),
			'user'          => array(
				'field'    => $case['userAdditionalField'],
				'value'    => $case['userAdditionalValue'],
				'contexts' => array( 'embed', 'view', 'edit' ),
			),
			'post-revision' => array(
				'field'    => $case['revisionAdditionalField'],
				'value'    => $case['revisionAdditionalValue'],
				'contexts' => array( 'edit' ),
			),
			'attachment'    => array(
				'field'    => $case['attachmentAdditionalField'],
				'value'    => $case['attachmentAdditionalValue'],
				'contexts' => array( 'view', 'edit', 'embed' ),
			),
		);

		foreach ( $fields as $object_type => $spec ) {
			\register_rest_field(
				$object_type,
				$spec['field'],
				array(
					'get_callback' => static function ( array $prepared, string $field_name, \WP_REST_Request $request, string $registered_object_type ) use ( &$calls, $object_type, $spec ): string {
						$calls[] = array(
							'context'        => $request['context'],
							'field'          => $field_name,
							'id'             => isset( $prepared['id'] ) ? (int) $prepared['id'] : null,
							'method'         => $request->get_method(),
							'objectType'     => $object_type,
							'registeredType' => $registered_object_type,
						);
						return $spec['value'];
					},
					'schema'       => array(
						'description' => 'Component fuzz deterministic additional field.',
						'type'        => 'string',
						'context'     => $spec['contexts'],
						'readonly'    => true,
						'default'     => $spec['value'],
					),
				)
			);
		}
	}

	private static function seed_fixtures( array $case ): array {
		$registered_meta = \register_post_meta(
			'post',
			$case['postMetaKey'],
			array(
				'auth_callback'     => '__return_true',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);
		if ( ! $registered_meta ) {
			throw new \RuntimeException( 'Could not register REST post meta fixture.' );
		}

		$author = \wp_insert_user(
			array(
				'user_login'   => $case['authorLogin'],
				'user_email'   => $case['authorEmail'],
				'display_name' => $case['authorName'],
				'user_pass'    => 'author-pass-' . $case['token'],
			)
		);
		if ( \is_wp_error( $author ) ) {
			throw new \RuntimeException( 'Could not create author fixture: ' . $author->get_error_code() );
		}
		\wp_set_current_user( (int) $author );

		$post = \wp_insert_post(
			array(
				'post_author'   => (int) $author,
				'post_content'  => $case['postContent'],
				'post_excerpt'  => $case['postExcerpt'],
				'post_name'     => 'rest-object-' . $case['token'],
				'post_status'   => 'publish',
				'post_title'    => $case['postTitle'],
				'post_type'     => 'post',
				'comment_status' => 'open',
			),
			true,
			false
		);
		if ( \is_wp_error( $post ) ) {
			throw new \RuntimeException( 'Could not create post fixture: ' . $post->get_error_code() );
		}
		\update_post_meta( (int) $post, $case['postMetaKey'], $case['postMetaStored'] );

		$other_post = \wp_insert_post(
			array(
				'post_author'  => (int) $author,
				'post_content' => 'Other post ' . $case['token'],
				'post_status'  => 'publish',
				'post_title'   => 'Other Post ' . $case['token'],
				'post_type'    => 'post',
			),
			true,
			false
		);
		if ( \is_wp_error( $other_post ) ) {
			throw new \RuntimeException( 'Could not create other post fixture: ' . $other_post->get_error_code() );
		}

		$term = \wp_insert_term(
			$case['termName'],
			'category',
			array(
				'description' => 'Fixture term ' . $case['token'],
				'slug'        => \sanitize_title( $case['termSlugInput'] ),
			)
		);
		if ( \is_wp_error( $term ) || ! is_array( $term ) || empty( $term['term_id'] ) ) {
			throw new \RuntimeException( 'Could not create term fixture.' );
		}

		$child_term = \wp_insert_term(
			$case['childTermName'],
			'category',
			array(
				'description' => 'Fixture child term ' . $case['token'],
				'parent'      => (int) $term['term_id'],
				'slug'        => \sanitize_title( $case['childTermSlugInput'] ),
			)
		);
		if ( \is_wp_error( $child_term ) || ! is_array( $child_term ) || empty( $child_term['term_id'] ) ) {
			throw new \RuntimeException( 'Could not create child term fixture.' );
		}

		$comment = \wp_insert_comment(
			array(
				'comment_approved'     => '1',
				'comment_author'       => $case['commentAuthorName'],
				'comment_author_email' => $case['commentAuthorEmail'],
				'comment_content'      => $case['commentContent'],
				'comment_post_ID'      => (int) $post,
				'comment_type'         => 'comment',
				'user_id'              => (int) $author,
			)
		);
		if ( ! $comment ) {
			throw new \RuntimeException( 'Could not create comment fixture.' );
		}

		$second_comment = \wp_insert_comment(
			array(
				'comment_approved'     => '1',
				'comment_author'       => $case['commentAuthorName'] . ' Two',
				'comment_author_email' => $case['commentAuthorEmail'],
				'comment_content'      => $case['secondCommentContent'],
				'comment_parent'       => (int) $comment,
				'comment_post_ID'      => (int) $post,
				'comment_type'         => 'comment',
				'user_id'              => (int) $author,
			)
		);
		if ( ! $second_comment ) {
			throw new \RuntimeException( 'Could not create second comment fixture.' );
		}

		$pending_comment = \wp_insert_comment(
			array(
				'comment_approved'     => '0',
				'comment_author'       => $case['commentAuthorName'] . ' Pending',
				'comment_author_email' => $case['commentAuthorEmail'],
				'comment_content'      => 'Pending fixture comment ' . $case['token'],
				'comment_post_ID'      => (int) $post,
				'comment_type'         => 'comment',
				'user_id'              => (int) $author,
			)
		);
		if ( ! $pending_comment ) {
			throw new \RuntimeException( 'Could not create pending comment fixture.' );
		}

		$revision = \wp_insert_post(
			array(
				'post_author'   => (int) $author,
				'post_content'  => $case['revisionContent'],
				'post_excerpt'  => 'Revision excerpt ' . $case['token'],
				'post_name'     => $post . '-revision-v1',
				'post_parent'   => (int) $post,
				'post_status'   => 'inherit',
				'post_title'    => $case['revisionTitle'],
				'post_type'     => 'revision',
				'post_date'     => '2026-06-23 10:00:00',
				'post_date_gmt' => '2026-06-23 08:00:00',
			),
			true,
			false
		);
		if ( \is_wp_error( $revision ) ) {
			throw new \RuntimeException( 'Could not create revision fixture: ' . $revision->get_error_code() );
		}

		$attachment_url = 'http://example.test/wp-content/uploads/' . rawurlencode( $case['attachmentFile'] );
		$attachment    = \wp_insert_post(
			array(
				'guid'           => $attachment_url,
				'post_author'    => (int) $author,
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => (int) $post,
				'post_status'    => 'inherit',
				'post_title'     => $case['attachmentTitle'],
				'post_type'      => 'attachment',
			),
			true,
			false
		);
		if ( \is_wp_error( $attachment ) ) {
			throw new \RuntimeException( 'Could not create attachment fixture: ' . $attachment->get_error_code() );
		}
		\update_post_meta( (int) $attachment, '_wp_attached_file', $case['attachmentFile'] );
		\update_post_meta( (int) $attachment, '_wp_attachment_image_alt', $case['attachmentAlt'] );

		return array(
			'attachment'      => (int) $attachment,
			'author'          => (int) $author,
			'child_term'      => (int) $child_term['term_id'],
			'comment'         => (int) $comment,
			'other_post'      => (int) $other_post,
			'pending_comment' => (int) $pending_comment,
			'post'            => (int) $post,
			'revision'        => (int) $revision,
			'second_comment'  => (int) $second_comment,
			'term'            => (int) $term['term_id'],
		);
	}

	private static function reset_runtime_state(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'admin_email'                 => 'admin@example.test',
					'blog_charset'                => 'UTF-8',
					'blogname'                    => 'Component Fuzz',
					'comment_max_links'           => 2,
					'comment_moderation'          => 0,
					'comment_registration'        => 0,
					'default_category'            => 0,
					'default_comment_status'      => 'open',
					'default_ping_status'         => 'closed',
					'default_role'                => 'subscriber',
					'disallowed_keys'             => '',
					'home'                        => 'http://example.test',
					'moderation_keys'             => '',
					'permalink_structure'         => '',
					'require_name_email'          => 0,
					'show_avatars'                => 0,
					'siteurl'                     => 'http://example.test',
					'upload_path'                 => '',
					'upload_url_path'             => '',
					'uploads_use_yearmonth_folders' => 0,
					'wp_attachment_pages_enabled' => '0',
				)
			);
		}

		\wp_cache_flush();

		$GLOBALS['_wp_post_type_features']    = array();
		$GLOBALS['post_type_meta_caps']       = array();
		$GLOBALS['wp_meta_keys']              = array();
		$GLOBALS['wp_post_statuses']          = array();
		$GLOBALS['wp_post_types']             = array();
		$GLOBALS['wp_registered_settings']    = array();
		$GLOBALS['wp_rest_additional_fields'] = array();
		$GLOBALS['wp_rest_server']            = new \WP_REST_Server();
		$GLOBALS['wp_rewrite']                = new \WP_Rewrite();
		$GLOBALS['wp_taxonomies']             = array();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz RestObjectControllers';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/rest-object-controllers/';
		$_SERVER['HTTP_HOST']       = 'example.test';
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

	private static function collection_context_param_ok( array $params ): bool {
		if ( ! isset( $params['context'] ) || ! is_array( $params['context'] ) ) {
			return false;
		}

		$context  = $params['context'];
		$sanitize = $context['sanitize_callback'] ?? null;
		$enum     = $context['enum'] ?? array();

		return 'view' === ( $context['default'] ?? null )
			&& is_callable( $sanitize )
			&& 'view' === call_user_func( $sanitize, 'View!!' )
			&& is_array( $enum )
			&& in_array( 'view', $enum, true );
	}

	private static function collection_has_params( array $params, array $names ): bool {
		foreach ( $names as $name ) {
			if ( ! isset( $params[ $name ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function collection_default_ok( array $params, string $name, $expected ): bool {
		return isset( $params[ $name ] ) && array_key_exists( 'default', $params[ $name ] )
			&& $expected === $params[ $name ]['default'];
	}

	private static function collection_enum_contains( array $params, string $name, array $values ): bool {
		if ( ! isset( $params[ $name ]['enum'] ) || ! is_array( $params[ $name ]['enum'] ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! in_array( $value, $params[ $name ]['enum'], true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function collection_items_enum_contains( array $params, string $name, array $values ): bool {
		if ( ! isset( $params[ $name ]['items']['enum'] ) || ! is_array( $params[ $name ]['items']['enum'] ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! in_array( $value, $params[ $name ]['items']['enum'], true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function projected_keys_match( array $data, array $expected_keys ): bool {
		$actual_keys = array_keys( $data );
		sort( $actual_keys );
		sort( $expected_keys );
		return $expected_keys === $actual_keys;
	}

	private static function response_matches_schema_context( \WP_REST_Controller $controller, array $data, string $context ): bool {
		$schema     = $controller->get_item_schema();
		$properties = $schema['properties'] ?? array();

		foreach ( $data as $field => $value ) {
			if ( in_array( $field, array( '_embedded', '_links' ), true ) ) {
				continue;
			}

			if ( ! isset( $properties[ $field ] ) ) {
				return false;
			}

			$field_contexts = $properties[ $field ]['context'] ?? array();
			if ( array() !== $field_contexts && ! in_array( $context, $field_contexts, true ) ) {
				return false;
			}

			if ( array_key_exists( 'type', $properties[ $field ] ) ) {
				if ( ! self::value_matches_schema_type( $value, $properties[ $field ]['type'] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function value_matches_schema_type( $value, $type ): bool {
		$types = is_array( $type ) ? $type : array( $type );

		foreach ( $types as $single_type ) {
			if ( 'null' === $single_type && null === $value ) {
				return true;
			}
			if ( 'string' === $single_type && is_string( $value ) ) {
				return true;
			}
			if ( 'integer' === $single_type && is_int( $value ) ) {
				return true;
			}
			if ( 'number' === $single_type && ( is_int( $value ) || is_float( $value ) ) ) {
				return true;
			}
			if ( 'boolean' === $single_type && is_bool( $value ) ) {
				return true;
			}
			if ( 'array' === $single_type && is_array( $value ) ) {
				return true;
			}
			if ( 'object' === $single_type && is_array( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function schema_property_matches( \WP_REST_Controller $controller, string $field, array $contexts, $default ): bool {
		$schema   = $controller->get_item_schema();
		$property = $schema['properties'][ $field ] ?? null;
		if ( ! is_array( $property ) ) {
			return false;
		}

		$actual_contexts = $property['context'] ?? array();
		sort( $actual_contexts );
		sort( $contexts );

		return 'string' === ( $property['type'] ?? null )
			&& $contexts === $actual_contexts
			&& array_key_exists( 'default', $property )
			&& $default === $property['default'];
	}

	private static function error_matches( $value, string $code, ?int $status = null ): bool {
		if ( ! $value instanceof \WP_Error || $code !== $value->get_error_code() ) {
			return false;
		}

		if ( null === $status ) {
			return true;
		}

		$data = $value->get_error_data();
		return is_array( $data ) && $status === (int) ( $data['status'] ?? 0 );
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

	private static function route_methods( array $handlers ): array {
		$methods = array();
		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
				continue;
			}
			foreach ( $handler['methods'] as $method => $enabled ) {
				if ( $enabled ) {
					$methods[] = (string) $method;
				}
			}
		}

		$methods = array_values( array_unique( $methods ) );
		sort( $methods );
		return $methods;
	}

	private static function route_data_has_methods( ?array $data, array $expected_methods ): bool {
		if ( null === $data || ! isset( $data['methods'] ) || ! is_array( $data['methods'] ) ) {
			return false;
		}

		$methods = array_values( array_unique( array_map( 'strval', $data['methods'] ) ) );
		sort( $methods );
		sort( $expected_methods );
		return $expected_methods === $methods;
	}

	private static function route_data_has_endpoint_args( ?array $data, array $methods, array $args ): bool {
		$endpoint = self::route_data_endpoint_for_methods( $data, $methods );
		if ( null === $endpoint || ! isset( $endpoint['args'] ) || ! is_array( $endpoint['args'] ) ) {
			return false;
		}

		foreach ( $args as $arg ) {
			if ( ! array_key_exists( $arg, $endpoint['args'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function route_data_endpoint_allows_batch( ?array $data, array $methods ): bool {
		$endpoint = self::route_data_endpoint_for_methods( $data, $methods );
		if ( null === $endpoint || ! isset( $endpoint['allow_batch'] ) || ! is_array( $endpoint['allow_batch'] ) ) {
			return false;
		}

		return true === ( $endpoint['allow_batch']['v1'] ?? null );
	}

	private static function route_data_schema_has_properties( ?array $data, array $properties ): bool {
		if ( null === $data || ! isset( $data['schema']['properties'] ) || ! is_array( $data['schema']['properties'] ) ) {
			return false;
		}

		foreach ( $properties as $property ) {
			if ( ! array_key_exists( $property, $data['schema']['properties'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function route_data_schema_contexts_match( ?array $data, string $property, array $contexts ): bool {
		if ( null === $data || ! isset( $data['schema']['properties'][ $property ] ) ) {
			return false;
		}

		$actual_contexts = $data['schema']['properties'][ $property ]['context'] ?? null;
		if ( ! is_array( $actual_contexts ) ) {
			return false;
		}

		sort( $actual_contexts );
		sort( $contexts );
		return $contexts === $actual_contexts;
	}

	private static function route_data_self_href( ?array $data ): ?string {
		return is_array( $data )
			? ( $data['_links']['self'][0]['href'] ?? null )
			: null;
	}

	private static function route_data_endpoint_for_methods( ?array $data, array $methods ): ?array {
		if ( null === $data || ! isset( $data['endpoints'] ) || ! is_array( $data['endpoints'] ) ) {
			return null;
		}

		sort( $methods );
		foreach ( $data['endpoints'] as $endpoint ) {
			if ( ! isset( $endpoint['methods'] ) || ! is_array( $endpoint['methods'] ) ) {
				continue;
			}

			$endpoint_methods = array_values( array_unique( array_map( 'strval', $endpoint['methods'] ) ) );
			sort( $endpoint_methods );
			if ( $methods === $endpoint_methods ) {
				return $endpoint;
			}
		}

		return null;
	}

	private static function content_counts(): array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return array();
		}

		return $GLOBALS['wpdb']->component_fuzz_content_counts();
	}

	private static function content_count_delta_matches( array $before, array $after, array $expected_delta, array $allowed_changed ): bool {
		if ( array() === $before || array() === $after ) {
			return false;
		}

		$allowed_map = array_fill_keys( $allowed_changed, true );
		$keys        = array_unique( array_merge( array_keys( $before ), array_keys( $after ), array_keys( $expected_delta ) ) );

		foreach ( $keys as $key ) {
			$delta = (int) ( $after[ $key ] ?? 0 ) - (int) ( $before[ $key ] ?? 0 );
			if ( array_key_exists( $key, $expected_delta ) ) {
				if ( (int) $expected_delta[ $key ] !== $delta ) {
					return false;
				}
				continue;
			}

			if ( 0 !== $delta && ! isset( $allowed_map[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function additional_field_call_count( array $calls, string $object_type, string $field, ?string $context = null, ?int $id = null ): int {
		$count = 0;
		foreach ( $calls as $call ) {
			if ( $object_type !== ( $call['objectType'] ?? null ) || $field !== ( $call['field'] ?? null ) ) {
				continue;
			}
			if ( null !== $context && $context !== ( $call['context'] ?? null ) ) {
				continue;
			}
			if ( null !== $id && $id !== ( $call['id'] ?? null ) ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	private static function remove_cap_filter( callable $filter ): bool {
		\remove_filter( 'user_has_cap', $filter, 10 );
		return false === \has_filter( 'user_has_cap', $filter );
	}

	private static function install_cap_filter( array $granted_caps ): callable {
		$granted_caps = array_fill_keys( $granted_caps, true );
		$filter       = static function ( array $allcaps, array $caps = array() ) use ( $granted_caps ): array {
			foreach ( $granted_caps as $cap => $grant ) {
				$allcaps[ $cap ] = $grant;
			}
			foreach ( $caps as $cap ) {
				if ( 'do_not_allow' !== $cap ) {
					$allcaps[ $cap ] = true;
				}
			}
			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, 10, 4 );
		return $filter;
	}

	private static function link_href( array $links, string $rel ): ?string {
		if ( ! isset( $links[ $rel ][0]['href'] ) ) {
			return null;
		}

		return $links[ $rel ][0]['href'];
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array(), ?string $status = null ): array {
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

	private static function skip( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		$data['reason'] = $reason;
		return self::row( $ctx, $invariant, true, $data, 'skipped' );
	}

	private static function param_summary( array $params ): array {
		$summary = array();
		foreach ( $params as $name => $param ) {
			$summary[ $name ] = array(
				'type'    => $param['type'] ?? null,
				'default' => $param['default'] ?? null,
				'enum'    => $param['enum'] ?? null,
			);
		}
		return $summary;
	}

	private static function case_summary( array $case ): array {
		return array(
			'token'       => $case['token'],
			'postMetaKey' => $case['postMetaKey'],
		);
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'_wp_post_type_features',
					'authordata',
					'current_user',
					'id',
					'post',
					'post_type_meta_caps',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_meta_keys',
					'wp_post_statuses',
					'wp_post_types',
					'wp_registered_settings',
					'wp_rest_additional_fields',
					'wp_rest_server',
					'wp_rewrite',
					'wp_taxonomies',
				)
			),
			'server'  => self::snapshot_server(
				array(
					'HTTP_HOST',
					'HTTP_USER_AGENT',
					'REMOTE_ADDR',
					'REQUEST_URI',
				)
			),
			'wpdb'    => self::snapshot_wpdb(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_wpdb( $snapshot['wpdb'] );

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		self::restore_globals( $snapshot['globals'] );
		self::restore_server( $snapshot['server'] );
	}

	private static function state_fingerprint(): array {
		return array(
			'globals' => self::stable_hash(
				self::summarize_for_hash(
					self::snapshot_globals(
						array(
							'_wp_post_type_features',
							'current_user',
							'post_type_meta_caps',
							'user_ID',
							'wp_filter',
							'wp_meta_keys',
							'wp_post_statuses',
							'wp_post_types',
							'wp_registered_settings',
							'wp_rest_additional_fields',
							'wp_taxonomies',
						)
					)
				)
			),
			'server'  => self::stable_hash(
				self::snapshot_server(
					array(
						'HTTP_HOST',
						'HTTP_USER_AGENT',
						'REMOTE_ADDR',
						'REQUEST_URI',
					)
				)
			),
			'wpdb'    => self::stable_hash( self::summarize_for_hash( self::snapshot_wpdb() ) ),
		);
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
			);
		}
		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
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
				'value'  => array_key_exists( $name, $_SERVER ) ? $_SERVER[ $name ] : null,
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

	private static function snapshot_wpdb(): ?array {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return null;
		}

		$wpdb       = $GLOBALS['wpdb'];
		$reflection = new \ReflectionClass( $wpdb );
		$state      = array(
			'public'  => array(
				'insert_id'     => $wpdb->insert_id,
				'last_error'    => $wpdb->last_error,
				'last_query'    => $wpdb->last_query,
				'num_rows'      => $wpdb->num_rows,
				'rows_affected' => $wpdb->rows_affected,
			),
			'private' => array(),
		);

		foreach ( $reflection->getProperties() as $property ) {
			$name = $property->getName();
			if ( str_starts_with( $name, 'component_fuzz_' ) ) {
				$state['private'][ $name ] = $property->getValue( $wpdb );
			}
		}

		return $state;
	}

	private static function restore_wpdb( ?array $snapshot ): void {
		if ( null === $snapshot || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return;
		}

		$wpdb = $GLOBALS['wpdb'];
		foreach ( $snapshot['public'] as $name => $value ) {
			$wpdb->{$name} = $value;
		}

		$reflection = new \ReflectionClass( $wpdb );
		foreach ( $snapshot['private'] as $name => $value ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}
			$property = $reflection->getProperty( $name );
			$property->setValue( $wpdb, $value );
		}
	}

	private static function first_difference( $before, $after, string $path = '' ) {
		if ( $before === $after ) {
			return null;
		}

		if ( is_array( $before ) && is_array( $after ) ) {
			$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
			foreach ( $keys as $key ) {
				$next_path = '' === $path ? (string) $key : $path . '.' . $key;
				if ( ! array_key_exists( $key, $before ) || ! array_key_exists( $key, $after ) ) {
					return array(
						'path'   => $next_path,
						'before' => array_key_exists( $key, $before ) ? $before[ $key ] : '[missing]',
						'after'  => array_key_exists( $key, $after ) ? $after[ $key ] : '[missing]',
					);
				}
				$diff = self::first_difference( $before[ $key ], $after[ $key ], $next_path );
				if ( null !== $diff ) {
					return $diff;
				}
			}
		}

		return array(
			'path'   => $path,
			'before' => self::describe_value( $before ),
			'after'  => self::describe_value( $after ),
		);
	}

	private static function stable_hash( $value ): ?string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? null : sha1( $json );
	}

	private static function summarize_for_hash( $value, int $depth = 0 ) {
		if ( $depth > 4 ) {
			return is_array( $value ) ? array( 'array' => count( $value ) ) : gettype( $value );
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ (string) $key ] = self::summarize_for_hash( $item, $depth + 1 );
			}
			ksort( $out );
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \WP_Error ) {
				return array( 'WP_Error' => $value->get_error_code() );
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array( 'WP_REST_Response' => self::summarize_for_hash( $value->get_data(), $depth + 1 ) );
			}
			if ( $value instanceof \Closure ) {
				return array( 'Closure' => true );
			}
			return array( 'object' => get_class( $value ) );
		}

		return $value;
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			$value = preg_replace_callback(
				'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
				static function ( array $matches ): string {
					return sprintf( '\\x%02X', ord( $matches[0] ) );
				},
				$value
			);
			return strlen( $value ) > 220 ? substr( $value, 0, 220 ) . '...' : $value;
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
				if ( $i >= 20 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : (string) $key ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}
			if ( $value instanceof \WP_Error ) {
				return array(
					'type'    => 'WP_Error',
					'code'    => $value->get_error_code(),
					'message' => $value->get_error_message(),
				);
			}
			if ( $value instanceof \WP_REST_Response ) {
				return array(
					'type'   => 'WP_REST_Response',
					'status' => $value->get_status(),
					'data'   => self::describe_value( $value->get_data(), $depth + 1 ),
				);
			}
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
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
