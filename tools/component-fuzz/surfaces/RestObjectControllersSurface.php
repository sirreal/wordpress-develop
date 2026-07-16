<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes DB-backed WordPress REST object controllers against the in-memory wpdb stub.
 */
final class RestObjectControllersSurface {
	public const NAME = 'rest-object-controllers';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_template_endpoint_classes();

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
			$rows[] = self::check_templates_controller( $ctx, $case );
			$rows[] = self::check_short_circuited_collection_queries( $ctx->fork( 'collection-queries' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_collection_get_edges( $ctx->fork( 'collection-get-dispatch' ), $case, $fixtures, $additional_field_calls );
			$rows[] = self::check_route_dispatched_object_write_edges( $ctx->fork( 'object-write-dispatch' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_object_create_delete_edges( $ctx->fork( 'object-create-delete-dispatch' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_page_parent_slug_edges( $ctx->fork( 'page-parent-slug-dispatch' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_custom_hierarchical_post_type_permalink_edges( $ctx->fork( 'custom-hierarchical-permalink-dispatch' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_custom_hierarchical_parent_assignment_edges( $ctx->fork( 'custom-hierarchical-parent-assignment-dispatch' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_custom_hierarchical_collection_parent_filters( $ctx->fork( 'custom-hierarchical-collection-parent-filters' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_object_force_delete_edges( $ctx->fork( 'object-force-delete-dispatch' ), $case, $fixtures );
			$rows[] = self::check_route_dispatched_term_mutation_edges( $ctx->fork( 'term-mutation-dispatch' ), $case, $fixtures );
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

	private static function load_template_endpoint_classes(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		foreach (
			array(
				'wp-includes/rest-api/endpoints/class-wp-rest-templates-controller.php',
			) as $file
		) {
			$path = ABSPATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
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
				'WP_REST_Templates_Controller',
				'WP_REST_User_Meta_Fields',
				'WP_REST_Users_Controller',
				'WP_Block_Template',
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
				'get_term_meta',
				'get_user_by',
				'has_filter',
				'is_wp_error',
				'post_type_exists',
				'register_post_type',
				'register_post_meta',
				'register_rest_field',
				'register_rest_route',
				'register_term_meta',
				'remove_filter',
				'rest_api_default_filters',
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
				'update_term_meta',
				'unregister_post_type',
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

	private static function check_short_circuited_collection_queries( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$observed = array();

		$post_controller     = new \WP_REST_Posts_Controller( 'post' );
		$post_rest_args      = array();
		$post_query_vars     = array();
		$post_total          = 5;
		$post_per_page       = 2;
		$post_sentinel       = 'component-fuzz-rest-object-post-query-' . $ctx->seed() . '-' . $ctx->iteration();
		$post_last_query_set = self::set_wpdb_last_query( $post_sentinel );
		$post_rest_filter    = static function ( array $args, \WP_REST_Request $request ) use ( &$post_rest_args ): array {
			$args['no_found_rows'] = true;
			$post_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$post_pre_filter     = static function ( $posts, \WP_Query $query ) use ( &$post_query_vars, $fixtures, $post_total, $post_per_page ): array {
			$post_query_vars[]    = $query->query_vars;
			$query->found_posts   = $post_total;
			$query->max_num_pages = (int) ceil( $post_total / $post_per_page );
			return array( $fixtures['post'], $fixtures['other_post'] );
		};
		\add_filter( 'rest_post_query', $post_rest_filter, 10, 2 );
		\add_filter( 'posts_pre_query', $post_pre_filter, 10, 2 );
		try {
			$post_response = $post_controller->get_items(
				self::request(
					'HEAD',
					'/wp/v2/posts',
					array(
						'after'    => '2026-01-01T00:00:00',
						'author'   => array( $fixtures['author'] ),
						'before'   => '2026-12-31T23:59:59',
						'context'  => 'view',
						'include'  => array( $fixtures['post'], $fixtures['other_post'] ),
						'order'    => 'asc',
						'orderby'  => 'include',
						'page'     => 1,
						'per_page' => $post_per_page,
						'search'   => $case['token'],
						'status'   => array( 'publish' ),
					)
				)
			);
		} finally {
			\remove_filter( 'rest_post_query', $post_rest_filter, 10 );
			\remove_filter( 'posts_pre_query', $post_pre_filter, 10 );
		}
		$post_headers            = $post_response instanceof \WP_REST_Response ? $post_response->get_headers() : array();
		$post_last_query_current = self::wpdb_last_query();
		$post_filter_restored    = false === \has_filter( 'rest_post_query', $post_rest_filter )
			&& false === \has_filter( 'posts_pre_query', $post_pre_filter );
		$observed['posts']       = array(
			'restArgs'              => $post_rest_args,
			'queryVars'             => $post_query_vars,
			'headers'               => $post_headers,
			'lastQuerySet'          => $post_last_query_set,
			'lastQueryAfter'        => $post_last_query_current,
			'shortCircuitRestored'  => $post_filter_restored,
		);
		self::collect_failure(
			$failures,
			$post_response instanceof \WP_REST_Response
				&& array() === $post_response->get_data()
				&& (string) $post_total === (string) ( $post_headers['X-WP-Total'] ?? '' )
				&& (string) ceil( $post_total / $post_per_page ) === (string) ( $post_headers['X-WP-TotalPages'] ?? '' )
				&& 1 === count( $post_rest_args )
				&& 1 === count( $post_query_vars )
				&& 'HEAD' === ( $post_rest_args[0]['method'] ?? null )
				&& array( $fixtures['post'], $fixtures['other_post'] ) === array_values( array_map( 'intval', (array) ( $post_rest_args[0]['args']['post__in'] ?? array() ) ) )
				&& array( $fixtures['author'] ) === array_values( array_map( 'intval', (array) ( $post_rest_args[0]['args']['author__in'] ?? array() ) ) )
				&& 'include' === ( $post_rest_args[0]['args']['orderby'] ?? null )
				&& 'post__in' === ( $post_query_vars[0]['orderby'] ?? null )
				&& 'ids' === ( $post_query_vars[0]['fields'] ?? null )
				&& true === ( $post_query_vars[0]['no_found_rows'] ?? null )
				&& false === ( $post_query_vars[0]['update_post_meta_cache'] ?? null )
				&& false === ( $post_query_vars[0]['update_post_term_cache'] ?? null )
				&& $post_per_page === (int) ( $post_query_vars[0]['posts_per_page'] ?? 0 )
				&& ( ! $post_last_query_set || $post_sentinel === $post_last_query_current )
				&& $post_filter_restored,
			'post collection HEAD request maps REST params through rest_post_query and posts_pre_query without executing SQL',
			$observed['posts']
		);

		$attachment_controller     = new \WP_REST_Attachments_Controller( 'attachment' );
		$attachment_rest_args      = array();
		$attachment_query_vars     = array();
		$attachment_total          = 2;
		$attachment_per_page       = 2;
		$attachment_sentinel       = 'component-fuzz-rest-object-attachment-query-' . $ctx->seed() . '-' . $ctx->iteration();
		$attachment_last_query_set = self::set_wpdb_last_query( $attachment_sentinel );
		$attachment_rest_filter    = static function ( array $args, \WP_REST_Request $request ) use ( &$attachment_rest_args ): array {
			$args['no_found_rows'] = true;
			$attachment_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$attachment_pre_filter     = static function ( $posts, \WP_Query $query ) use ( &$attachment_query_vars, $fixtures, $attachment_total, $attachment_per_page ): array {
			$attachment_query_vars[] = $query->query_vars;
			$query->found_posts      = $attachment_total;
			$query->max_num_pages    = (int) ceil( $attachment_total / $attachment_per_page );
			return array( $fixtures['attachment'] );
		};
		\add_filter( 'rest_attachment_query', $attachment_rest_filter, 10, 2 );
		\add_filter( 'posts_pre_query', $attachment_pre_filter, 10, 2 );
		try {
			$attachment_response = $attachment_controller->get_items(
				self::request(
					'HEAD',
					'/wp/v2/media',
					array(
						'context'    => 'view',
						'include'    => array( $fixtures['attachment'] ),
						'media_type' => array( 'image' ),
						'mime_type'  => array( 'image/jpeg' ),
						'order'      => 'desc',
						'orderby'    => 'include',
						'page'       => 1,
						'parent'     => array( $fixtures['post'] ),
						'per_page'   => $attachment_per_page,
					)
				)
			);
		} finally {
			\remove_filter( 'rest_attachment_query', $attachment_rest_filter, 10 );
			\remove_filter( 'posts_pre_query', $attachment_pre_filter, 10 );
		}
		$attachment_headers            = $attachment_response instanceof \WP_REST_Response ? $attachment_response->get_headers() : array();
		$attachment_last_query_current = self::wpdb_last_query();
		$attachment_filter_restored    = false === \has_filter( 'rest_attachment_query', $attachment_rest_filter )
			&& false === \has_filter( 'posts_pre_query', $attachment_pre_filter );
		$observed['attachments']       = array(
			'restArgs'             => $attachment_rest_args,
			'queryVars'            => $attachment_query_vars,
			'headers'              => $attachment_headers,
			'lastQuerySet'         => $attachment_last_query_set,
			'lastQueryAfter'       => $attachment_last_query_current,
			'shortCircuitRestored' => $attachment_filter_restored,
		);
		self::collect_failure(
			$failures,
			$attachment_response instanceof \WP_REST_Response
				&& array() === $attachment_response->get_data()
				&& (string) $attachment_total === (string) ( $attachment_headers['X-WP-Total'] ?? '' )
				&& (string) ceil( $attachment_total / $attachment_per_page ) === (string) ( $attachment_headers['X-WP-TotalPages'] ?? '' )
				&& 1 === count( $attachment_rest_args )
				&& 1 === count( $attachment_query_vars )
				&& 'HEAD' === ( $attachment_rest_args[0]['method'] ?? null )
				&& array( $fixtures['attachment'] ) === array_values( array_map( 'intval', (array) ( $attachment_rest_args[0]['args']['post__in'] ?? array() ) ) )
				&& array( $fixtures['post'] ) === array_values( array_map( 'intval', (array) ( $attachment_rest_args[0]['args']['post_parent__in'] ?? array() ) ) )
				&& 'attachment' === ( $attachment_rest_args[0]['args']['post_type'] ?? null )
				&& 'include' === ( $attachment_rest_args[0]['args']['orderby'] ?? null )
				&& 'attachment' === ( $attachment_query_vars[0]['post_type'] ?? null )
				&& 'inherit' === ( $attachment_query_vars[0]['post_status'] ?? null )
				&& 'post__in' === ( $attachment_query_vars[0]['orderby'] ?? null )
				&& 'ids' === ( $attachment_query_vars[0]['fields'] ?? null )
				&& true === ( $attachment_query_vars[0]['no_found_rows'] ?? null )
				&& in_array( 'image/jpeg', (array) ( $attachment_query_vars[0]['post_mime_type'] ?? array() ), true )
				&& $attachment_per_page === (int) ( $attachment_query_vars[0]['posts_per_page'] ?? 0 )
				&& ( ! $attachment_last_query_set || $attachment_sentinel === $attachment_last_query_current )
				&& $attachment_filter_restored,
			'attachment collection HEAD request maps REST params through rest_attachment_query and posts_pre_query without executing SQL',
			$observed['attachments']
		);

		$revision_controller     = new \WP_REST_Revisions_Controller( 'post' );
		$revision_rest_args      = array();
		$revision_query_vars     = array();
		$revision_total          = 2;
		$revision_per_page       = 2;
		$revision_sentinel       = 'component-fuzz-rest-object-revision-query-' . $ctx->seed() . '-' . $ctx->iteration();
		$revision_last_query_set = self::set_wpdb_last_query( $revision_sentinel );
		$revision_rest_filter    = static function ( array $args, \WP_REST_Request $request ) use ( &$revision_rest_args ): array {
			$args['no_found_rows'] = true;
			$revision_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$revision_pre_filter     = static function ( $posts, \WP_Query $query ) use ( &$revision_query_vars, $fixtures, $revision_total, $revision_per_page ): array {
			$revision_query_vars[] = $query->query_vars;
			$query->found_posts    = $revision_total;
			$query->max_num_pages  = (int) ceil( $revision_total / $revision_per_page );
			return array( $fixtures['revision'] );
		};
		\add_filter( 'rest_revision_query', $revision_rest_filter, 10, 2 );
		\add_filter( 'posts_pre_query', $revision_pre_filter, 10, 2 );
		try {
			$revision_response = $revision_controller->get_items(
				self::request(
					'HEAD',
					'/wp/v2/posts/' . $fixtures['post'] . '/revisions',
					array(
						'context'  => 'view',
						'include'  => array( $fixtures['revision'] ),
						'order'    => 'asc',
						'orderby'  => 'include',
						'page'     => 1,
						'per_page' => $revision_per_page,
						'search'   => $case['token'],
					),
					array( 'parent' => $fixtures['post'] )
				)
			);
		} finally {
			\remove_filter( 'rest_revision_query', $revision_rest_filter, 10 );
			\remove_filter( 'posts_pre_query', $revision_pre_filter, 10 );
		}
		$revision_headers            = $revision_response instanceof \WP_REST_Response ? $revision_response->get_headers() : array();
		$revision_last_query_current = self::wpdb_last_query();
		$revision_filter_restored    = false === \has_filter( 'rest_revision_query', $revision_rest_filter )
			&& false === \has_filter( 'posts_pre_query', $revision_pre_filter );
		$observed['revisions']       = array(
			'restArgs'             => $revision_rest_args,
			'queryVars'            => $revision_query_vars,
			'headers'              => $revision_headers,
			'lastQuerySet'         => $revision_last_query_set,
			'lastQueryAfter'       => $revision_last_query_current,
			'shortCircuitRestored' => $revision_filter_restored,
		);
		self::collect_failure(
			$failures,
			$revision_response instanceof \WP_REST_Response
				&& array() === $revision_response->get_data()
				&& (string) $revision_total === (string) ( $revision_headers['X-WP-Total'] ?? '' )
				&& (string) ceil( $revision_total / $revision_per_page ) === (string) ( $revision_headers['X-WP-TotalPages'] ?? '' )
				&& 1 === count( $revision_rest_args )
				&& 1 === count( $revision_query_vars )
				&& 'HEAD' === ( $revision_rest_args[0]['method'] ?? null )
				&& $fixtures['post'] === (int) ( $revision_rest_args[0]['args']['post_parent'] ?? 0 )
				&& array( $fixtures['revision'] ) === array_values( array_map( 'intval', (array) ( $revision_rest_args[0]['args']['post__in'] ?? array() ) ) )
				&& 'revision' === ( $revision_rest_args[0]['args']['post_type'] ?? null )
				&& 'inherit' === ( $revision_rest_args[0]['args']['post_status'] ?? null )
				&& 'include' === ( $revision_rest_args[0]['args']['orderby'] ?? null )
				&& 'revision' === ( $revision_query_vars[0]['post_type'] ?? null )
				&& 'inherit' === ( $revision_query_vars[0]['post_status'] ?? null )
				&& 'post__in' === ( $revision_query_vars[0]['orderby'] ?? null )
				&& 'ids' === ( $revision_query_vars[0]['fields'] ?? null )
				&& true === ( $revision_query_vars[0]['no_found_rows'] ?? null )
				&& $revision_per_page === (int) ( $revision_query_vars[0]['posts_per_page'] ?? 0 )
				&& ( ! $revision_last_query_set || $revision_sentinel === $revision_last_query_current )
				&& $revision_filter_restored,
			'revision collection HEAD request maps REST params through rest_revision_query and posts_pre_query without executing SQL',
			$observed['revisions']
		);

		$user_controller     = new \WP_REST_Users_Controller();
		$user_rest_args      = array();
		$user_query_vars     = array();
		$user_total          = 3;
		$user_per_page       = 2;
		$user_sentinel       = 'component-fuzz-rest-object-user-query-' . $ctx->seed() . '-' . $ctx->iteration();
		$user_last_query_set = self::set_wpdb_last_query( $user_sentinel );
		$user_cap_filter     = self::install_cap_filter( array( 'list_users' ) );
		$user_rest_filter    = static function ( array $args, \WP_REST_Request $request ) use ( &$user_rest_args ): array {
			$user_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$user_pre_filter     = static function ( $results, \WP_User_Query $query ) use ( &$user_query_vars, $fixtures, $user_total ): array {
			$user_query_vars[]  = $query->query_vars;
			$query->total_users = $user_total;
			return array( $fixtures['author'] );
		};
		\add_filter( 'rest_user_query', $user_rest_filter, 10, 2 );
		\add_filter( 'users_pre_query', $user_pre_filter, 10, 2 );
		try {
			$user_response = $user_controller->get_items(
				self::request(
					'HEAD',
					'/wp/v2/users',
					array(
						'context'        => 'view',
						'include'        => array( $fixtures['author'] ),
						'order'          => 'desc',
						'orderby'        => 'email',
						'page'           => 1,
						'per_page'       => $user_per_page,
						'search'         => $case['token'],
						'search_columns' => array( 'email', 'name' ),
						'slug'           => array( $case['authorLogin'] ),
					)
				)
			);
		} finally {
			\remove_filter( 'rest_user_query', $user_rest_filter, 10 );
			\remove_filter( 'users_pre_query', $user_pre_filter, 10 );
			$user_cap_filter_restored = self::remove_cap_filter( $user_cap_filter );
		}
		$user_headers            = $user_response instanceof \WP_REST_Response ? $user_response->get_headers() : array();
		$user_last_query_current = self::wpdb_last_query();
		$user_filter_restored    = false === \has_filter( 'rest_user_query', $user_rest_filter )
			&& false === \has_filter( 'users_pre_query', $user_pre_filter )
			&& $user_cap_filter_restored;
		$observed['users']       = array(
			'restArgs'             => $user_rest_args,
			'queryVars'            => $user_query_vars,
			'headers'              => $user_headers,
			'lastQuerySet'         => $user_last_query_set,
			'lastQueryAfter'       => $user_last_query_current,
			'shortCircuitRestored' => $user_filter_restored,
		);
		self::collect_failure(
			$failures,
			$user_response instanceof \WP_REST_Response
				&& array() === $user_response->get_data()
				&& (string) $user_total === (string) ( $user_headers['X-WP-Total'] ?? '' )
				&& (string) ceil( $user_total / $user_per_page ) === (string) ( $user_headers['X-WP-TotalPages'] ?? '' )
				&& 1 === count( $user_rest_args )
				&& 1 === count( $user_query_vars )
				&& 'HEAD' === ( $user_rest_args[0]['method'] ?? null )
				&& array( $fixtures['author'] ) === array_values( array_map( 'intval', (array) ( $user_rest_args[0]['args']['include'] ?? array() ) ) )
				&& array( $case['authorLogin'] ) === array_values( (array) ( $user_rest_args[0]['args']['nicename__in'] ?? array() ) )
				&& 'user_email' === ( $user_rest_args[0]['args']['orderby'] ?? null )
				&& '*' . $case['token'] . '*' === ( $user_rest_args[0]['args']['search'] ?? null )
				&& array( 'user_email', 'display_name' ) === array_values( (array) ( $user_rest_args[0]['args']['search_columns'] ?? array() ) )
				&& 'id' === ( $user_query_vars[0]['fields'] ?? null )
				&& $user_per_page === (int) ( $user_query_vars[0]['number'] ?? 0 )
				&& 0 === (int) ( $user_query_vars[0]['offset'] ?? -1 )
				&& ( ! $user_last_query_set || $user_sentinel === $user_last_query_current )
				&& $user_filter_restored,
			'user collection HEAD request maps REST params through rest_user_query and users_pre_query without executing SQL',
			$observed['users']
		);

		$comment_controller     = new \WP_REST_Comments_Controller();
		$comment_rest_args      = array();
		$comment_query_vars     = array();
		$comment_total          = 4;
		$comment_per_page       = 2;
		$comment_sentinel       = 'component-fuzz-rest-object-comment-query-' . $ctx->seed() . '-' . $ctx->iteration();
		$comment_last_query_set = self::set_wpdb_last_query( $comment_sentinel );
		$comment_rest_filter    = static function ( array $args, \WP_REST_Request $request ) use ( &$comment_rest_args ): array {
			$comment_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$comment_pre_filter     = static function ( $comments, \WP_Comment_Query $query ) use ( &$comment_query_vars, $fixtures, $comment_total, $comment_per_page ): array {
			$comment_query_vars[]     = $query->query_vars;
			$query->found_comments   = $comment_total;
			$query->max_num_pages    = (int) ceil( $comment_total / $comment_per_page );
			return array( $fixtures['comment'], $fixtures['second_comment'] );
		};
		\add_filter( 'rest_comment_query', $comment_rest_filter, 10, 2 );
		\add_filter( 'comments_pre_query', $comment_pre_filter, 10, 2 );
		try {
			$comment_response = $comment_controller->get_items(
				self::request(
					'HEAD',
					'/wp/v2/comments',
					array(
						'author'   => array( $fixtures['author'] ),
						'context'  => 'view',
						'include'  => array( $fixtures['comment'], $fixtures['second_comment'] ),
						'order'    => 'asc',
						'orderby'  => 'date_gmt',
						'page'     => 1,
						'parent'   => array( $fixtures['comment'] ),
						'per_page' => $comment_per_page,
						'post'     => array( $fixtures['post'] ),
						'search'   => $case['token'],
						'status'   => 'approved',
						'type'     => 'comment',
					)
				)
			);
		} finally {
			\remove_filter( 'rest_comment_query', $comment_rest_filter, 10 );
			\remove_filter( 'comments_pre_query', $comment_pre_filter, 10 );
		}
		$comment_headers            = $comment_response instanceof \WP_REST_Response ? $comment_response->get_headers() : array();
		$comment_last_query_current = self::wpdb_last_query();
		$comment_filter_restored    = false === \has_filter( 'rest_comment_query', $comment_rest_filter )
			&& false === \has_filter( 'comments_pre_query', $comment_pre_filter );
		$observed['comments']       = array(
			'restArgs'             => $comment_rest_args,
			'queryVars'            => $comment_query_vars,
			'headers'              => $comment_headers,
			'lastQuerySet'         => $comment_last_query_set,
			'lastQueryAfter'       => $comment_last_query_current,
			'shortCircuitRestored' => $comment_filter_restored,
		);
		self::collect_failure(
			$failures,
			$comment_response instanceof \WP_REST_Response
				&& array() === $comment_response->get_data()
				&& (string) $comment_total === (string) ( $comment_headers['X-WP-Total'] ?? '' )
				&& (string) ceil( $comment_total / $comment_per_page ) === (string) ( $comment_headers['X-WP-TotalPages'] ?? '' )
				&& 1 === count( $comment_rest_args )
				&& 1 === count( $comment_query_vars )
				&& 'HEAD' === ( $comment_rest_args[0]['method'] ?? null )
				&& array( $fixtures['comment'], $fixtures['second_comment'] ) === array_values( array_map( 'intval', (array) ( $comment_rest_args[0]['args']['comment__in'] ?? array() ) ) )
				&& array( $fixtures['post'] ) === array_values( array_map( 'intval', (array) ( $comment_rest_args[0]['args']['post__in'] ?? array() ) ) )
				&& array( $fixtures['author'] ) === array_values( array_map( 'intval', (array) ( $comment_rest_args[0]['args']['author__in'] ?? array() ) ) )
				&& array( $fixtures['comment'] ) === array_values( array_map( 'intval', (array) ( $comment_rest_args[0]['args']['parent__in'] ?? array() ) ) )
				&& 'comment_date_gmt' === ( $comment_rest_args[0]['args']['orderby'] ?? null )
				&& 'ids' === ( $comment_query_vars[0]['fields'] ?? null )
				&& $comment_per_page === (int) ( $comment_query_vars[0]['number'] ?? 0 )
				&& 0 === (int) ( $comment_query_vars[0]['offset'] ?? -1 )
				&& ( ! $comment_last_query_set || $comment_sentinel === $comment_last_query_current )
				&& $comment_filter_restored,
			'comment collection HEAD request maps REST params through rest_comment_query and comments_pre_query without executing SQL',
			$observed['comments']
		);

		$term_controller     = new \WP_REST_Terms_Controller( 'category' );
		$term_rest_args      = array();
		$term_query_vars     = array();
		$term_total          = 6;
		$term_per_page       = 2;
		$term_sentinel       = 'component-fuzz-rest-object-term-query-' . $ctx->seed() . '-' . $ctx->iteration();
		$term_last_query_set = self::set_wpdb_last_query( $term_sentinel );
		$term_rest_filter    = static function ( array $args, \WP_REST_Request $request ) use ( &$term_rest_args ): array {
			$term_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$term_pre_filter     = static function ( $terms, \WP_Term_Query $query ) use ( &$term_query_vars, $fixtures, $term_total ) {
			$term_query_vars[] = $query->query_vars;
			if ( 'count' === ( $query->query_vars['fields'] ?? null ) ) {
				return (string) $term_total;
			}
			return array( $fixtures['term'], $fixtures['child_term'] );
		};
		\add_filter( 'rest_category_query', $term_rest_filter, 10, 2 );
		\add_filter( 'terms_pre_query', $term_pre_filter, 10, 2 );
		try {
			$term_response = $term_controller->get_items(
				self::request(
					'HEAD',
					'/wp/v2/categories',
					array(
						'context'    => 'view',
						'hide_empty' => '0',
						'include'    => array( $fixtures['term'], $fixtures['child_term'] ),
						'order'      => 'desc',
						'orderby'    => 'slug',
						'page'       => 1,
						'per_page'   => $term_per_page,
						'search'     => $case['token'],
						'slug'       => array(
							\sanitize_title( $case['termSlugInput'] ),
							\sanitize_title( $case['childTermSlugInput'] ),
						),
					)
				)
			);
		} finally {
			\remove_filter( 'rest_category_query', $term_rest_filter, 10 );
			\remove_filter( 'terms_pre_query', $term_pre_filter, 10 );
		}
		$term_headers            = $term_response instanceof \WP_REST_Response ? $term_response->get_headers() : array();
		$term_last_query_current = self::wpdb_last_query();
		$term_filter_restored    = false === \has_filter( 'rest_category_query', $term_rest_filter )
			&& false === \has_filter( 'terms_pre_query', $term_pre_filter );
		$observed['terms']       = array(
			'restArgs'             => $term_rest_args,
			'queryVars'            => $term_query_vars,
			'headers'              => $term_headers,
			'lastQuerySet'         => $term_last_query_set,
			'lastQueryAfter'       => $term_last_query_current,
			'shortCircuitRestored' => $term_filter_restored,
		);
		self::collect_failure(
			$failures,
			$term_response instanceof \WP_REST_Response
				&& array() === $term_response->get_data()
				&& (string) $term_total === (string) ( $term_headers['X-WP-Total'] ?? '' )
				&& (string) ceil( $term_total / $term_per_page ) === (string) ( $term_headers['X-WP-TotalPages'] ?? '' )
				&& 1 === count( $term_rest_args )
				&& 2 === count( $term_query_vars )
				&& 'HEAD' === ( $term_rest_args[0]['method'] ?? null )
				&& 'category' === ( $term_rest_args[0]['args']['taxonomy'] ?? null )
				&& array( $fixtures['term'], $fixtures['child_term'] ) === array_values( array_map( 'intval', (array) ( $term_rest_args[0]['args']['include'] ?? array() ) ) )
				&& 'slug' === ( $term_rest_args[0]['args']['orderby'] ?? null )
				&& 'ids' === ( $term_query_vars[0]['fields'] ?? null )
				&& 'count' === ( $term_query_vars[1]['fields'] ?? null )
				&& $term_per_page === (int) ( $term_query_vars[0]['number'] ?? 0 )
				&& 0 === (int) ( $term_query_vars[0]['offset'] ?? -1 )
				&& ( ! $term_last_query_set || $term_sentinel === $term_last_query_current )
				&& $term_filter_restored,
			'term collection HEAD request maps REST params through rest_category_query and terms_pre_query without executing SQL',
			$observed['terms']
		);

		return self::row(
			$ctx,
			'rest-object-controllers.collections.query-translation-short-circuited',
			array() === $failures,
			array(
				'case'       => self::case_summary( $case ),
				'controllers' => array( 'posts', 'attachments', 'revisions', 'users', 'comments', 'terms' ),
				'failures'   => array_slice( $failures, 0, 8 ),
				'observed'   => $observed,
				'notClaimed' => array(
					'fullSqlExecution' => 'The in-memory wpdb SQL parser is intentionally bypassed; this invariant covers REST argument translation, query-class short-circuit hooks, HEAD pagination, and filter restoration.',
					'templates'        => 'Template collection query execution remains covered by schema/param checks and dedicated site-editor surfaces, not this query-class short-circuit invariant.',
				),
			)
		);
	}

	private static function check_route_dispatched_collection_get_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures, array &$additional_field_calls ): array {
		$failures       = array();
		$observed       = array();
		$prepare_events = array();

		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$filter_snapshot = self::rest_default_filter_state();
		$cap_filter      = null;
		$counts_before   = self::content_counts();

		$post_rest_args    = array();
		$post_query_vars   = array();
		$term_rest_args    = array();
		$term_query_vars   = array();
		$comment_rest_args = array();
		$comment_query_vars = array();
		$user_rest_args    = array();
		$user_query_vars   = array();

		$post_total    = 5;
		$post_per_page = 2;
		$term_total    = 6;
		$term_per_page = 2;
		$comment_total = 4;
		$comment_per_page = 2;
		$user_total    = 3;
		$user_per_page = 1;

		$post_objects = array_values(
			array_filter(
				array( \get_post( $fixtures['post'] ), \get_post( $fixtures['other_post'] ) ),
				static fn ( $post ): bool => $post instanceof \WP_Post
			)
		);
		$term_objects = array_values(
			array_filter(
				array( \get_term( $fixtures['term'], 'category' ), \get_term( $fixtures['child_term'], 'category' ) ),
				static fn ( $term ): bool => $term instanceof \WP_Term
			)
		);
		$comment_objects = array_values(
			array_filter(
				array( \get_comment( $fixtures['comment'] ), \get_comment( $fixtures['second_comment'] ) ),
				static fn ( $comment ): bool => $comment instanceof \WP_Comment
			)
		);
		$user_ids = array( $fixtures['author'] );

		$post_rest_filter = static function ( array $args, \WP_REST_Request $request ) use ( &$post_rest_args ): array {
			$args['component_fuzz_collection_get'] = 'posts';
			$post_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$post_pre_filter  = static function ( $posts, \WP_Query $query ) use ( &$post_query_vars, $post_objects, $post_total, $post_per_page ): array {
			if ( 'posts' !== ( $query->query_vars['component_fuzz_collection_get'] ?? null ) ) {
				return $posts;
			}

			$post_query_vars[]    = $query->query_vars;
			$query->found_posts   = $post_total;
			$query->max_num_pages = (int) ceil( $post_total / $post_per_page );
			return $post_objects;
		};

		$term_rest_filter = static function ( array $args, \WP_REST_Request $request ) use ( &$term_rest_args ): array {
			$args['component_fuzz_collection_get'] = 'terms';
			$term_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$term_pre_filter  = static function ( $terms, \WP_Term_Query $query ) use ( &$term_query_vars, $term_objects, $term_total ) {
			if ( 'terms' !== ( $query->query_vars['component_fuzz_collection_get'] ?? null ) ) {
				return $terms;
			}

			$term_query_vars[] = $query->query_vars;
			if ( 'count' === ( $query->query_vars['fields'] ?? null ) ) {
				return (string) $term_total;
			}
			return $term_objects;
		};

		$comment_rest_filter = static function ( array $args, \WP_REST_Request $request ) use ( &$comment_rest_args ): array {
			$args['component_fuzz_collection_get'] = 'comments';
			$comment_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$comment_pre_filter  = static function ( $comments, \WP_Comment_Query $query ) use ( &$comment_query_vars, $comment_objects, $comment_total, $comment_per_page ): array {
			if ( 'comments' !== ( $query->query_vars['component_fuzz_collection_get'] ?? null ) ) {
				return $comments;
			}

			$comment_query_vars[]   = $query->query_vars;
			$query->found_comments  = $comment_total;
			$query->max_num_pages   = (int) ceil( $comment_total / $comment_per_page );
			return $comment_objects;
		};

		$user_rest_filter = static function ( array $args, \WP_REST_Request $request ) use ( &$user_rest_args ): array {
			$args['component_fuzz_collection_get'] = 'users';
			$user_rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$user_pre_filter  = static function ( $results, \WP_User_Query $query ) use ( &$user_query_vars, $user_ids, $user_total ): array {
			if ( 'users' !== ( $query->query_vars['component_fuzz_collection_get'] ?? null ) ) {
				return $results;
			}

			$user_query_vars[]  = $query->query_vars;
			$query->total_users = $user_total;
			return $user_ids;
		};

		$post_prepare_filter = static function ( $response, $post, \WP_REST_Request $request ) use ( &$prepare_events ) {
			if ( $response instanceof \WP_REST_Response && $post instanceof \WP_Post ) {
				$prepare_events[] = self::prepare_event( 'post', (int) $post->ID, $request );
			}
			return $response;
		};
		$term_prepare_filter = static function ( $response, $term, \WP_REST_Request $request ) use ( &$prepare_events ) {
			if ( $response instanceof \WP_REST_Response && $term instanceof \WP_Term ) {
				$prepare_events[] = self::prepare_event( 'term', (int) $term->term_id, $request );
			}
			return $response;
		};
		$comment_prepare_filter = static function ( $response, $comment, \WP_REST_Request $request ) use ( &$prepare_events ) {
			if ( $response instanceof \WP_REST_Response && $comment instanceof \WP_Comment ) {
				$prepare_events[] = self::prepare_event( 'comment', (int) $comment->comment_ID, $request );
			}
			return $response;
		};
		$user_prepare_filter = static function ( $response, $user, \WP_REST_Request $request ) use ( &$prepare_events ) {
			if ( $response instanceof \WP_REST_Response && $user instanceof \WP_User ) {
				$prepare_events[] = self::prepare_event( 'user', (int) $user->ID, $request );
			}
			return $response;
		};

		$default_filters_restored = false;
		$custom_filters_restored  = false;
		$cap_filter_restored      = false;
		$server_restored          = false;
		$actions_restored         = false;
		$current_user_restored    = false;

		try {
			$controllers = array(
				new \WP_REST_Posts_Controller( 'post' ),
				new \WP_REST_Terms_Controller( 'category' ),
				new \WP_REST_Comments_Controller(),
				new \WP_REST_Users_Controller(),
			);

			foreach ( $controllers as $controller ) {
				$controller->register_routes();
			}

			\rest_api_default_filters();

			\wp_set_current_user( 0 );
			$invalid_post_response = self::dispatch_with_rest_post_dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/posts',
					array(
						'_fields'  => 'id',
						'per_page' => 0,
					)
				)
			);
			$denied_user_response = self::dispatch_with_rest_post_dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/users',
					array(
						'_fields' => 'id',
						'roles'   => array( 'administrator' ),
					)
				)
			);

			\add_filter( 'rest_post_query', $post_rest_filter, 10, 2 );
			\add_filter( 'posts_pre_query', $post_pre_filter, 10, 2 );
			\add_filter( 'rest_category_query', $term_rest_filter, 10, 2 );
			\add_filter( 'terms_pre_query', $term_pre_filter, 10, 2 );
			\add_filter( 'rest_comment_query', $comment_rest_filter, 10, 2 );
			\add_filter( 'comments_pre_query', $comment_pre_filter, 10, 2 );
			\add_filter( 'rest_user_query', $user_rest_filter, 10, 2 );
			\add_filter( 'users_pre_query', $user_pre_filter, 10, 2 );
			\add_filter( 'rest_prepare_post', $post_prepare_filter, 10, 3 );
			\add_filter( 'rest_prepare_category', $term_prepare_filter, 10, 3 );
			\add_filter( 'rest_prepare_comment', $comment_prepare_filter, 10, 3 );
			\add_filter( 'rest_prepare_user', $user_prepare_filter, 10, 3 );

			\wp_set_current_user( $fixtures['author'] );
			$cap_filter = self::install_cap_filter( array( 'edit_user', 'edit_users', 'list_users', 'read' ) );
			$sentinel   = 'component-fuzz-rest-object-collection-get-' . $ctx->seed() . '-' . $ctx->iteration();
			$last_query_set = self::set_wpdb_last_query( $sentinel );
			$term_extra_calls_before = self::additional_field_call_count( $additional_field_calls, 'category', $case['termAdditionalField'], 'view' );
			$user_extra_calls_before = self::additional_field_call_count( $additional_field_calls, 'user', $case['userAdditionalField'], 'edit' );

			$post_response = self::dispatch_with_rest_post_dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/posts',
					array(
						'_fields'  => 'id,slug,title',
						'context'  => 'view',
						'include'  => array( $fixtures['post'], $fixtures['other_post'] ),
						'order'    => 'asc',
						'orderby'  => 'include',
						'page'     => 1,
						'per_page' => $post_per_page,
					)
				)
			);
			$term_response = self::dispatch_with_rest_post_dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/categories',
					array(
						'_fields'    => 'id,name,parent,slug,taxonomy,' . $case['termAdditionalField'],
						'context'    => 'view',
						'hide_empty' => '0',
						'include'    => array( $fixtures['term'], $fixtures['child_term'] ),
						'order'      => 'asc',
						'orderby'    => 'include',
						'page'       => 1,
						'per_page'   => $term_per_page,
					)
				)
			);
			$comment_response = self::dispatch_with_rest_post_dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/comments',
					array(
						'_fields'  => 'id,parent,post,status,type',
						'context'  => 'view',
						'include'  => array( $fixtures['comment'], $fixtures['second_comment'] ),
						'order'    => 'asc',
						'orderby'  => 'include',
						'page'     => 1,
						'per_page' => $comment_per_page,
						'post'     => array( $fixtures['post'] ),
						'status'   => 'approve',
						'type'     => 'comment',
					)
				)
			);
			$user_response = self::dispatch_with_rest_post_dispatch(
				$server,
				self::request(
					'GET',
					'/wp/v2/users',
					array(
						'_fields'  => 'email,id,name,slug,username,' . $case['userAdditionalField'],
						'context'  => 'edit',
						'include'  => array( $fixtures['author'] ),
						'order'    => 'desc',
						'orderby'  => 'include',
						'page'     => 1,
						'per_page' => $user_per_page,
					)
				)
			);

			$last_query_after = self::wpdb_last_query();
			$counts_after     = self::content_counts();

			$post_data       = $post_response instanceof \WP_REST_Response ? $post_response->get_data() : array();
			$term_data       = $term_response instanceof \WP_REST_Response ? $term_response->get_data() : array();
			$comment_data    = $comment_response instanceof \WP_REST_Response ? $comment_response->get_data() : array();
			$user_data       = $user_response instanceof \WP_REST_Response ? $user_response->get_data() : array();
			$post_headers    = $post_response instanceof \WP_REST_Response ? $post_response->get_headers() : array();
			$term_headers    = $term_response instanceof \WP_REST_Response ? $term_response->get_headers() : array();
			$comment_headers = $comment_response instanceof \WP_REST_Response ? $comment_response->get_headers() : array();
			$user_headers    = $user_response instanceof \WP_REST_Response ? $user_response->get_headers() : array();
			$term_extra_calls_after = self::additional_field_call_count( $additional_field_calls, 'category', $case['termAdditionalField'], 'view' );
			$user_extra_calls_after = self::additional_field_call_count( $additional_field_calls, 'user', $case['userAdditionalField'], 'edit' );

			$observed = array(
				'invalidPost' => $invalid_post_response,
				'deniedUser'  => $denied_user_response,
				'posts'       => array(
					'data'      => $post_data,
					'headers'   => $post_headers,
					'restArgs'  => $post_rest_args,
					'queryVars' => $post_query_vars,
				),
				'terms'       => array(
					'data'      => $term_data,
					'headers'   => $term_headers,
					'restArgs'  => $term_rest_args,
					'queryVars' => $term_query_vars,
				),
				'comments'    => array(
					'data'      => $comment_data,
					'headers'   => $comment_headers,
					'restArgs'  => $comment_rest_args,
					'queryVars' => $comment_query_vars,
				),
				'users'       => array(
					'data'      => $user_data,
					'headers'   => $user_headers,
					'restArgs'  => $user_rest_args,
					'queryVars' => $user_query_vars,
				),
				'prepareEvents' => $prepare_events,
				'additionalFields' => array(
					'termBefore' => $term_extra_calls_before,
					'termAfter'  => $term_extra_calls_after,
					'userBefore' => $user_extra_calls_before,
					'userAfter'  => $user_extra_calls_after,
				),
				'lastQuery'     => array(
					'set'    => $last_query_set,
					'before' => $sentinel,
					'after'  => $last_query_after,
				),
				'counts'        => array(
					'before' => $counts_before,
					'after'  => $counts_after,
				),
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $invalid_post_response, 'rest_invalid_param', 400 )
					&& (
						self::response_error_ok( $denied_user_response, 'rest_user_cannot_view', 401 )
						|| self::response_error_ok( $denied_user_response, 'rest_user_cannot_view', 403 )
					),
				'route-dispatched collection GET rejects invalid parameters and privileged filters before query dispatch',
				array(
					'invalidPost' => $invalid_post_response,
					'deniedUser'  => $denied_user_response,
				)
			);

			self::collect_failure(
				$failures,
				$post_response instanceof \WP_REST_Response
					&& 200 === $post_response->get_status()
					&& self::collection_projected_rows_ok( new \WP_REST_Posts_Controller( 'post' ), $post_data, array( $fixtures['post'], $fixtures['other_post'] ), array( 'id', 'slug', 'title' ), 'view' )
					&& (string) $post_total === (string) ( $post_headers['X-WP-Total'] ?? '' )
					&& (string) ceil( $post_total / $post_per_page ) === (string) ( $post_headers['X-WP-TotalPages'] ?? '' )
					&& self::header_has_link_rel( $post_headers, 'next' )
					&& 1 === count( $post_rest_args )
					&& 1 === count( $post_query_vars )
					&& 'GET' === ( $post_rest_args[0]['method'] ?? null )
					&& array( $fixtures['post'], $fixtures['other_post'] ) === array_values( array_map( 'intval', (array) ( $post_rest_args[0]['args']['post__in'] ?? array() ) ) )
					&& 'post__in' === ( $post_query_vars[0]['orderby'] ?? null ),
				'post collection route dispatch applies _fields projection, pagination headers, and include ordering',
				$observed['posts']
			);

			self::collect_failure(
				$failures,
				$term_response instanceof \WP_REST_Response
					&& 200 === $term_response->get_status()
					&& self::collection_projected_rows_ok( new \WP_REST_Terms_Controller( 'category' ), $term_data, array( $fixtures['term'], $fixtures['child_term'] ), array( 'id', 'name', 'parent', 'slug', 'taxonomy', $case['termAdditionalField'] ), 'view' )
					&& self::collection_field_value_ok( $term_data, $case['termAdditionalField'], $case['termAdditionalValue'] )
					&& (string) $term_total === (string) ( $term_headers['X-WP-Total'] ?? '' )
					&& (string) ceil( $term_total / $term_per_page ) === (string) ( $term_headers['X-WP-TotalPages'] ?? '' )
					&& self::header_has_link_rel( $term_headers, 'next' )
					&& 1 === count( $term_rest_args )
					&& in_array( 'count', array_map( static fn ( array $vars ): string => (string) ( $vars['fields'] ?? '' ), $term_query_vars ), true )
					&& 'category' === ( $term_rest_args[0]['args']['taxonomy'] ?? null )
					&& $term_extra_calls_before + 2 === $term_extra_calls_after,
				'term collection route dispatch applies _fields projection, pagination headers, and count short-circuiting',
				$observed['terms']
			);

			self::collect_failure(
				$failures,
				$comment_response instanceof \WP_REST_Response
					&& 200 === $comment_response->get_status()
					&& self::collection_projected_rows_ok( new \WP_REST_Comments_Controller(), $comment_data, array( $fixtures['comment'], $fixtures['second_comment'] ), array( 'id', 'parent', 'post', 'status', 'type' ), 'view' )
					&& (string) $comment_total === (string) ( $comment_headers['X-WP-Total'] ?? '' )
					&& (string) ceil( $comment_total / $comment_per_page ) === (string) ( $comment_headers['X-WP-TotalPages'] ?? '' )
					&& self::header_has_link_rel( $comment_headers, 'next' )
					&& 1 === count( $comment_rest_args )
					&& 1 === count( $comment_query_vars )
					&& array( $fixtures['comment'], $fixtures['second_comment'] ) === array_values( array_map( 'intval', (array) ( $comment_rest_args[0]['args']['comment__in'] ?? array() ) ) )
					&& 'comment__in' === ( $comment_query_vars[0]['orderby'] ?? null ),
				'comment collection route dispatch applies _fields projection, pagination headers, and include ordering',
				$observed['comments']
			);

			self::collect_failure(
				$failures,
				$user_response instanceof \WP_REST_Response
					&& 200 === $user_response->get_status()
					&& self::collection_projected_rows_ok( new \WP_REST_Users_Controller(), $user_data, array( $fixtures['author'] ), array( 'email', 'id', 'name', 'slug', 'username', $case['userAdditionalField'] ), 'edit' )
					&& self::collection_field_value_ok( $user_data, $case['userAdditionalField'], $case['userAdditionalValue'] )
					&& (string) $user_total === (string) ( $user_headers['X-WP-Total'] ?? '' )
					&& (string) ceil( $user_total / $user_per_page ) === (string) ( $user_headers['X-WP-TotalPages'] ?? '' )
					&& self::header_has_link_rel( $user_headers, 'next' )
					&& 1 === count( $user_rest_args )
					&& 1 === count( $user_query_vars )
					&& array( $fixtures['author'] ) === array_values( array_map( 'intval', (array) ( $user_rest_args[0]['args']['include'] ?? array() ) ) )
					&& 'include' === ( $user_query_vars[0]['orderby'] ?? null )
					&& $user_extra_calls_before + 1 === $user_extra_calls_after,
				'user collection route dispatch applies edit-context _fields projection, pagination headers, and permission-gated query dispatch',
				$observed['users']
			);

			self::collect_failure(
				$failures,
				self::prepare_events_match( $prepare_events, 'post', array( $fixtures['post'], $fixtures['other_post'] ), '/wp/v2/posts', 'GET', 'view' )
					&& self::prepare_events_match( $prepare_events, 'term', array( $fixtures['term'], $fixtures['child_term'] ), '/wp/v2/categories', 'GET', 'view' )
					&& self::prepare_events_match( $prepare_events, 'comment', array( $fixtures['comment'], $fixtures['second_comment'] ), '/wp/v2/comments', 'GET', 'view' )
					&& self::prepare_events_match( $prepare_events, 'user', array( $fixtures['author'] ), '/wp/v2/users', 'GET', 'edit' ),
				'collection prepare hooks receive the route-dispatched request and generated fixture IDs before projection',
				array( 'prepareEvents' => $prepare_events )
			);

			self::collect_failure(
				$failures,
				self::content_count_delta_matches( $counts_before, $counts_after, array(), array() )
					&& 1 === count( $post_query_vars )
					&& 2 <= count( $term_query_vars )
					&& 1 === count( $comment_query_vars )
					&& 1 === count( $user_query_vars ),
				'route-dispatched collection GET paths do not mutate content tables and use generated query short-circuits',
				array(
					'countsBefore' => $counts_before,
					'countsAfter'  => $counts_after,
					'lastQuerySet' => $last_query_set,
					'lastQuery'    => $last_query_after,
				)
			);
		} finally {
			\remove_filter( 'rest_post_query', $post_rest_filter, 10 );
			\remove_filter( 'posts_pre_query', $post_pre_filter, 10 );
			\remove_filter( 'rest_category_query', $term_rest_filter, 10 );
			\remove_filter( 'terms_pre_query', $term_pre_filter, 10 );
			\remove_filter( 'rest_comment_query', $comment_rest_filter, 10 );
			\remove_filter( 'comments_pre_query', $comment_pre_filter, 10 );
			\remove_filter( 'rest_user_query', $user_rest_filter, 10 );
			\remove_filter( 'users_pre_query', $user_pre_filter, 10 );
			\remove_filter( 'rest_prepare_post', $post_prepare_filter, 10 );
			\remove_filter( 'rest_prepare_category', $term_prepare_filter, 10 );
			\remove_filter( 'rest_prepare_comment', $comment_prepare_filter, 10 );
			\remove_filter( 'rest_prepare_user', $user_prepare_filter, 10 );

			$custom_filters_restored = false === \has_filter( 'rest_post_query', $post_rest_filter )
				&& false === \has_filter( 'posts_pre_query', $post_pre_filter )
				&& false === \has_filter( 'rest_category_query', $term_rest_filter )
				&& false === \has_filter( 'terms_pre_query', $term_pre_filter )
				&& false === \has_filter( 'rest_comment_query', $comment_rest_filter )
				&& false === \has_filter( 'comments_pre_query', $comment_pre_filter )
				&& false === \has_filter( 'rest_user_query', $user_rest_filter )
				&& false === \has_filter( 'users_pre_query', $user_pre_filter )
				&& false === \has_filter( 'rest_prepare_post', $post_prepare_filter )
				&& false === \has_filter( 'rest_prepare_category', $term_prepare_filter )
				&& false === \has_filter( 'rest_prepare_comment', $comment_prepare_filter )
				&& false === \has_filter( 'rest_prepare_user', $user_prepare_filter );

			if ( null !== $cap_filter ) {
				$cap_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter          = null;
			} else {
				$cap_filter_restored = true;
			}

			self::restore_rest_default_filters( $filter_snapshot );
			$default_filters_restored = $filter_snapshot === self::rest_default_filter_state();

			\wp_set_current_user( $previous_current_user_id );
			$current_user_restored = $previous_current_user_id === ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ? (int) $GLOBALS['current_user']->ID : 0 );

			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $previous_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}
			$actions_restored = $had_wp_actions === array_key_exists( 'wp_actions', $GLOBALS )
				&& ( ! $had_wp_actions || $previous_actions === $GLOBALS['wp_actions'] );

			if ( null !== $previous_server ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
			$server_restored = ( null !== $previous_server && $previous_server === ( $GLOBALS['wp_rest_server'] ?? null ) )
				|| ( null === $previous_server && ! isset( $GLOBALS['wp_rest_server'] ) );
		}

		self::collect_failure(
			$failures,
			$custom_filters_restored
				&& $cap_filter_restored
				&& $default_filters_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored,
			'collection route dispatch restores query, prepare, capability, default REST filter, server, action, and current-user state',
			array(
				'customFiltersRestored'  => $custom_filters_restored,
				'capFilterRestored'      => $cap_filter_restored,
				'defaultFiltersRestored' => $default_filters_restored,
				'serverRestored'         => $server_restored,
				'actionsRestored'        => $actions_restored,
				'currentUserRestored'    => $current_user_restored,
				'observed'               => $observed,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.collections.route-dispatched-get-projection-pagination',
			array() === $failures,
			array(
				'case'       => self::case_summary( $case ),
				'controllers' => array( 'posts', 'terms', 'comments', 'users' ),
				'failures'   => array_slice( $failures, 0, 8 ),
				'observed'   => $observed,
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
		$pipeline_request = self::request( 'GET', $request->get_route(), array( $param => $case['value'] ) );
		$pipeline_request->set_attributes( array( 'args' => $params ) );
		$pipeline_valid       = $pipeline_request->has_valid_params();
		$pipeline_sanitized   = null;
		$pipeline_param       = null;
		$pipeline_error       = $pipeline_valid instanceof \WP_Error ? $pipeline_valid : null;
		$pipeline_error_phase = $pipeline_error instanceof \WP_Error ? 'validate' : null;
		if ( true === $pipeline_valid ) {
			$pipeline_sanitized = $pipeline_request->sanitize_params();
			if ( $pipeline_sanitized instanceof \WP_Error ) {
				$pipeline_error       = $pipeline_sanitized;
				$pipeline_error_phase = 'sanitize';
			} else {
				$pipeline_param = $pipeline_request->get_param( $param );
			}
		}

		$pipeline_error_data = $pipeline_error instanceof \WP_Error ? $pipeline_error->get_error_data() : null;
		$callback_valid      = null;
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

		if ( $case['schemaValid'] ) {
			$expected_pipeline_param = $case['requestSanitized'] ?? $case['callbackSanitized'] ?? $case['schemaSanitized'] ?? null;
			$ok                      = $ok
				&& true === $pipeline_valid
				&& true === $pipeline_sanitized
				&& $expected_pipeline_param === $pipeline_param;
		} else {
			$ok = $ok
				&& $pipeline_error instanceof \WP_Error
				&& 'rest_invalid_param' === $pipeline_error->get_error_code()
				&& is_array( $pipeline_error_data )
				&& isset( $pipeline_error_data['params'][ $param ] );
		}

		self::collect_failure(
			$failures,
			$ok,
			'collection parameter schema, callbacks, and request pipeline match deterministic matrix',
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
				'pipelineValid'     => $pipeline_valid,
				'pipelineSanitized' => $pipeline_sanitized,
				'pipelineParam'     => $pipeline_param,
				'pipelineError'     => $pipeline_error,
				'pipelineErrorData' => $pipeline_error_data,
				'pipelineErrorPhase' => $pipeline_error_phase,
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

	private static function check_route_dispatched_object_write_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$observed = array();

		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$cap_filter             = null;
		$write_filter_restored  = false;
		$server_restored        = false;
		$actions_restored       = false;
		$current_user_restored  = false;
		$slug_collision_id      = 0;

		try {
			$controllers = array(
				new \WP_REST_Posts_Controller( 'post' ),
				new \WP_REST_Terms_Controller( 'category' ),
				new \WP_REST_Comments_Controller(),
				new \WP_REST_Users_Controller(),
			);

			foreach ( $controllers as $controller ) {
				$controller->register_routes();
			}

			$post_update_slug_input = 'rest-collision-' . $case['token'];
			$sanitized_update_slug  = \sanitize_title( $post_update_slug_input );
			$expected_update_slug   = $sanitized_update_slug . '-2';
			$slug_collision_id      = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => 'post',
						'post_title'   => 'REST Slug Collision Holder ' . $case['token'],
						'post_content' => 'REST slug collision holder ' . $case['token'],
						'post_status'  => 'publish',
						'post_name'    => $sanitized_update_slug,
						'post_author'  => $fixtures['author'],
					)
				),
				true,
				false
			);
			$slug_collision_post    = is_int( $slug_collision_id ) ? \get_post( $slug_collision_id ) : null;
			$counts_before          = self::content_counts();

			\wp_set_current_user( 0 );
			$denied_post_response = $server->dispatch(
				self::request(
					'PUT',
					'/wp/v2/posts/' . $fixtures['post'],
					array( '_fields' => 'id,title' ),
					array(),
					array( 'title' => $case['updatedPostTitle'] )
				)
			);
			$post_after_denial    = \get_post( $fixtures['post'] );
			$denied_post_data     = $denied_post_response instanceof \WP_REST_Response
				? $denied_post_response->get_data()
				: array();
			$denied_post_status   = $denied_post_response instanceof \WP_REST_Response
				? $denied_post_response->get_status()
				: 0;

			\wp_set_current_user( $fixtures['author'] );
			$cap_filter = self::install_cap_filter(
				array(
					'edit_categories',
					'edit_comment',
					'edit_comments',
					'edit_others_posts',
					'edit_post',
					'edit_posts',
					'edit_published_posts',
					'edit_term',
					'edit_terms',
					'edit_user',
					'edit_users',
					'list_users',
					'manage_categories',
					'moderate_comments',
					'publish_posts',
					'read',
				)
			);
			try {
				$invalid_post_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/posts/' . $fixtures['post'],
						array( '_fields' => 'author,id,title' ),
						array(),
						array( 'author' => 'not-an-integer-' . $case['token'] )
					)
				);
				$invalid_term_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/categories/' . $fixtures['term'],
						array( '_fields' => 'id,parent' ),
						array(),
						array( 'parent' => 'not-an-integer-' . $case['token'] )
					)
				);
				$invalid_comment_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/comments/' . $fixtures['comment'],
						array( '_fields' => 'id,author_email' ),
						array(),
						array( 'author_email' => 'not-an-email-' . $case['token'] )
					)
				);
				$invalid_user_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/users/' . $fixtures['author'],
						array( '_fields' => 'id,email' ),
						array(),
						array( 'email' => 'not-an-email-' . $case['token'] )
					)
				);

				$valid_post_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/posts/' . $fixtures['post'],
						array( '_fields' => 'id,link,slug,status,title' ),
						array(),
						array(
							'slug'  => $post_update_slug_input,
							'title' => $case['updatedPostTitle'],
						)
					)
				);
				$valid_term_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/categories/' . $fixtures['term'],
						array( '_fields' => 'id,name,slug' ),
						array(),
						array( 'name' => $case['updatedTermName'] )
					)
				);
				$valid_comment_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/comments/' . $fixtures['comment'],
						array( '_fields' => 'id,content,status' ),
						array(),
						array(
							'content' => $case['updatedCommentContent'],
							'status'  => 'hold',
						)
					)
				);
				$valid_user_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/users/' . $fixtures['author'],
						array( '_fields' => 'id,email,name' ),
						array(),
						array( 'name' => $case['updatedUserName'] )
					)
				);
			} finally {
				if ( null !== $cap_filter ) {
					$write_filter_restored = self::remove_cap_filter( $cap_filter );
					$cap_filter            = null;
				}
			}

			$counts_after          = self::content_counts();
			$invalid_post_data     = $invalid_post_response instanceof \WP_REST_Response ? $invalid_post_response->get_data() : array();
			$invalid_term_data     = $invalid_term_response instanceof \WP_REST_Response ? $invalid_term_response->get_data() : array();
			$invalid_comment_data  = $invalid_comment_response instanceof \WP_REST_Response ? $invalid_comment_response->get_data() : array();
			$invalid_user_data     = $invalid_user_response instanceof \WP_REST_Response ? $invalid_user_response->get_data() : array();
			$valid_post_data       = $valid_post_response instanceof \WP_REST_Response ? $valid_post_response->get_data() : array();
			$valid_term_data       = $valid_term_response instanceof \WP_REST_Response ? $valid_term_response->get_data() : array();
			$valid_comment_data    = $valid_comment_response instanceof \WP_REST_Response ? $valid_comment_response->get_data() : array();
			$valid_user_data       = $valid_user_response instanceof \WP_REST_Response ? $valid_user_response->get_data() : array();
			$updated_post          = \get_post( $fixtures['post'] );
			$updated_term          = \get_term( $fixtures['term'], 'category' );
			$updated_comment       = \get_comment( $fixtures['comment'] );
			$updated_user          = \get_user_by( 'id', $fixtures['author'] );
			$updated_post_link     = $updated_post instanceof \WP_Post ? \get_permalink( $updated_post ) : null;

			$observed = array(
				'deniedPost'     => array(
					'data'            => $denied_post_data,
					'status'          => $denied_post_status,
					'titleAfterDenial' => $post_after_denial instanceof \WP_Post ? $post_after_denial->post_title : null,
				),
				'invalidPayloads' => array(
					'post'    => $invalid_post_data,
					'term'    => $invalid_term_data,
					'comment' => $invalid_comment_data,
					'user'    => $invalid_user_data,
				),
				'validResponses'  => array(
					'post'    => $valid_post_data,
					'term'    => $valid_term_data,
					'comment' => $valid_comment_data,
					'user'    => $valid_user_data,
				),
				'storedRows'      => array(
					'postTitle'       => $updated_post instanceof \WP_Post ? $updated_post->post_title : null,
					'postSlug'        => $updated_post instanceof \WP_Post ? $updated_post->post_name : null,
					'postLink'        => $updated_post_link,
					'termName'        => $updated_term instanceof \WP_Term ? $updated_term->name : null,
					'commentContent'  => $updated_comment instanceof \WP_Comment ? $updated_comment->comment_content : null,
					'commentApproved' => $updated_comment instanceof \WP_Comment ? $updated_comment->comment_approved : null,
					'userName'        => $updated_user instanceof \WP_User ? $updated_user->display_name : null,
					'userEmail'       => $updated_user instanceof \WP_User ? $updated_user->user_email : null,
				),
				'slugCollision'   => array(
					'id'       => self::describe_value( $slug_collision_id ),
					'postSlug' => $slug_collision_post instanceof \WP_Post ? $slug_collision_post->post_name : null,
					'expected' => $sanitized_update_slug,
				),
				'countsBefore'    => $counts_before,
				'countsAfter'     => $counts_after,
			);

			self::collect_failure(
				$failures,
				$denied_post_response instanceof \WP_REST_Response
					&& in_array( $denied_post_status, array( 401, 403 ), true )
					&& 'rest_cannot_edit' === ( $denied_post_data['code'] ?? null )
					&& $case['postTitle'] === ( $post_after_denial instanceof \WP_Post ? $post_after_denial->post_title : null ),
				'route-dispatched post update denies unauthenticated requests before mutation',
				$observed['deniedPost']
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $invalid_post_response, 'rest_invalid_param', 400 )
					&& isset( $invalid_post_data['data']['params']['author'] )
					&& self::response_error_ok( $invalid_term_response, 'rest_invalid_param', 400 )
					&& isset( $invalid_term_data['data']['params']['parent'] )
					&& self::response_error_ok( $invalid_comment_response, 'rest_invalid_param', 400 )
					&& isset( $invalid_comment_data['data']['params']['author_email'] )
					&& self::response_error_ok( $invalid_user_response, 'rest_invalid_param', 400 )
					&& isset( $invalid_user_data['data']['params']['email'] ),
				'route-dispatched object updates reject invalid body params through request validation',
				$observed['invalidPayloads']
			);

			self::collect_failure(
				$failures,
				$valid_post_response instanceof \WP_REST_Response
					&& 200 === $valid_post_response->get_status()
					&& self::projected_keys_match( $valid_post_data, array( 'id', 'link', 'slug', 'status', 'title' ) )
					&& $fixtures['post'] === (int) ( $valid_post_data['id'] ?? 0 )
					&& $case['updatedPostTitle'] === ( $valid_post_data['title']['raw'] ?? null )
					&& $slug_collision_post instanceof \WP_Post
					&& $sanitized_update_slug === $slug_collision_post->post_name
					&& $expected_update_slug === ( $valid_post_data['slug'] ?? null )
					&& $updated_post_link === ( $valid_post_data['link'] ?? null )
					&& $case['updatedPostTitle'] === ( $updated_post instanceof \WP_Post ? $updated_post->post_title : null )
					&& $expected_update_slug === ( $updated_post instanceof \WP_Post ? $updated_post->post_name : null )
					&& $valid_term_response instanceof \WP_REST_Response
					&& 200 === $valid_term_response->get_status()
					&& self::projected_keys_match( $valid_term_data, array( 'id', 'name', 'slug' ) )
					&& $fixtures['term'] === (int) ( $valid_term_data['id'] ?? 0 )
					&& $case['updatedTermName'] === ( $valid_term_data['name'] ?? null )
					&& $case['updatedTermName'] === ( $updated_term instanceof \WP_Term ? $updated_term->name : null )
					&& $valid_comment_response instanceof \WP_REST_Response
					&& 200 === $valid_comment_response->get_status()
					&& self::projected_keys_match( $valid_comment_data, array( 'content', 'id', 'status' ) )
					&& $fixtures['comment'] === (int) ( $valid_comment_data['id'] ?? 0 )
					&& $case['updatedCommentContent'] === ( $valid_comment_data['content']['raw'] ?? null )
					&& 'hold' === ( $valid_comment_data['status'] ?? null )
					&& $case['updatedCommentContent'] === ( $updated_comment instanceof \WP_Comment ? $updated_comment->comment_content : null )
					&& '0' === (string) ( $updated_comment instanceof \WP_Comment ? $updated_comment->comment_approved : null )
					&& $valid_user_response instanceof \WP_REST_Response
					&& 200 === $valid_user_response->get_status()
					&& self::projected_keys_match( $valid_user_data, array( 'email', 'id', 'name' ) )
					&& $fixtures['author'] === (int) ( $valid_user_data['id'] ?? 0 )
					&& $case['updatedUserName'] === ( $valid_user_data['name'] ?? null )
					&& $case['updatedUserName'] === ( $updated_user instanceof \WP_User ? $updated_user->display_name : null )
					&& $case['authorEmail'] === ( $updated_user instanceof \WP_User ? $updated_user->user_email : null )
					&& self::content_count_delta_matches( $counts_before, $counts_after, array(), array() ),
				'route-dispatched object updates project requested response fields and persist only row updates',
				$observed
			);
		} finally {
			if ( null !== $cap_filter ) {
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
			}

			if ( is_int( $slug_collision_id ) && $slug_collision_id > 0 ) {
				\wp_delete_post( $slug_collision_id, true );
			}

			\wp_set_current_user( $previous_current_user_id );

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

			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
		}

		self::collect_failure(
			$failures,
			$write_filter_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored,
			'route-dispatched object write harness restores caps, server, actions, and current user',
			array(
				'writeFilterRestored' => $write_filter_restored,
				'serverRestored'      => $server_restored,
				'actionsRestored'     => $actions_restored,
				'currentUserRestored' => $current_user_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.route-dispatched-write-validation-projection-cleanup',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_route_dispatched_object_create_delete_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$observed = array();

		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$cap_filter              = null;
		$post_trash_filter       = static function (): bool {
			return false;
		};
		$comment_trash_filter    = static function (): bool {
			return false;
		};
		$permalink_structure     = '/%postname%/';
		$permalink_filter        = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$previous_rewrite_set    = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite        = $GLOBALS['wp_rewrite'] ?? null;
		$write_filter_restored   = false;
		$post_filter_restored    = false;
		$comment_filter_restored = false;
		$email_filter_restored   = false;
		$sanitize_filter_restored = false;
		$permalink_filter_restored = false;
		$rewrite_restored        = false;
		$server_restored         = false;
		$actions_restored        = false;
		$current_user_restored   = false;
		$create_slug_collision_id = 0;
		$draft_create_id        = 0;
		$pending_create_id      = 0;
		$draft_update_id        = 0;
		$pending_update_id      = 0;
		$unique_slug_filter      = null;
		$unique_slug_restored    = false;
		$unique_slug_events      = array();
		$draft_pending_observed = array();

		try {
			$controllers = array(
				new \WP_REST_Posts_Controller( 'post' ),
				new \WP_REST_Terms_Controller( 'category' ),
				new \WP_REST_Comments_Controller(),
				new \WP_REST_Users_Controller(),
			);

			foreach ( $controllers as $controller ) {
				$controller->register_routes();
			}

			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			$route_post_title         = 'Route Created REST Post ' . $case['token'];
			$route_post_content       = '<p>Route created content ' . $case['token'] . '</p>';
			$route_post_slug_base     = \sanitize_title( $route_post_title );
			$route_post_slug_expected = $route_post_slug_base . '-2';
			$route_term_name          = 'Route Created Term ' . $case['token'];
			$route_term_slug          = 'Route Created Term Slug ' . $case['token'];
			$route_comment_body       = "  Route created comment {$case['token']}\n";
			$route_comment_email      = 'route-comment-' . $case['token'] . '@example.com';
			$route_user_login         = 'cfz_route_user_' . $case['token'];
			$route_user_email         = 'route-user-' . $case['token'] . '@example.com';
			$route_user_name          = 'Route Created User ' . $case['token'];
			$route_user_password      = 'route-pass-' . $case['token'] . '-A1';
			$route_user_slug          = 'Route Created User ' . $case['token'];
			$accepted_emails          = array_fill_keys(
				array(
					$route_comment_email,
					$route_user_email,
				),
				true
			);
			$email_filter         = static function ( $is_email, string $email ) use ( $accepted_emails ) {
				return isset( $accepted_emails[ $email ] ) ? $email : $is_email;
			};
			$sanitize_email_filter = static function ( string $sanitized, string $email ) use ( $accepted_emails ): string {
				return isset( $accepted_emails[ $email ] ) ? $email : $sanitized;
			};
			$create_slug_collision_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => 'post',
						'post_title'   => 'REST Create Collision Holder ' . $case['token'],
						'post_content' => 'REST create collision holder ' . $case['token'],
						'post_status'  => 'publish',
						'post_name'    => $route_post_slug_base,
						'post_author'  => $fixtures['author'],
					)
				),
				true,
				false
			);
			$create_slug_collision_post = is_int( $create_slug_collision_id ) ? \get_post( $create_slug_collision_id ) : null;
			$counts_before             = self::content_counts();

			\wp_set_current_user( 0 );
			$denied_create_response = $server->dispatch(
				self::request(
					'POST',
					'/wp/v2/posts',
					array( '_fields' => 'id,title' ),
					array(),
					array( 'title' => $route_post_title )
				)
			);
			$counts_after_denial = self::content_counts();

			\wp_set_current_user( $fixtures['author'] );
			$cap_filter = self::install_cap_filter(
				array(
					'create_users',
					'delete_categories',
					'delete_comment',
					'delete_comments',
					'delete_post',
					'delete_posts',
					'delete_user',
					'delete_users',
					'edit_categories',
					'edit_comment',
					'edit_comments',
					'edit_others_posts',
					'edit_post',
					'edit_posts',
					'edit_published_posts',
					'edit_term',
					'edit_terms',
					'edit_user',
					'edit_users',
					'list_users',
					'manage_categories',
					'moderate_comments',
					'publish_posts',
					'read',
				)
			);
			\add_filter( 'rest_post_trashable', $post_trash_filter, 10, 2 );
			\add_filter( 'rest_comment_trashable', $comment_trash_filter, 10, 2 );
			\add_filter( 'is_email', $email_filter, 10, 2 );
			\add_filter( 'sanitize_email', $sanitize_email_filter, 10, 2 );

			try {
				$draft_collision_title   = 'Route Draft Collision REST Post ' . $case['token'];
				$pending_collision_title = 'Route Pending Collision REST Post ' . $case['token'];
				$draft_request_body      = array(
					'author'  => $fixtures['author'],
					'content' => '<p>Route draft collision content ' . $case['token'] . '</p>',
					'slug'    => $route_post_slug_base,
					'status'  => 'draft',
					'title'   => $draft_collision_title,
				);
				$pending_request_body    = array(
					'author'  => $fixtures['author'],
					'content' => '<p>Route pending collision content ' . $case['token'] . '</p>',
					'slug'    => $route_post_slug_base,
					'status'  => 'pending',
					'title'   => $pending_collision_title,
				);
				$draft_counts_before     = self::content_counts();
				$draft_create_error      = null;
				$pending_create_error    = null;
				$draft_create_response   = null;
				$pending_create_response = null;
				$unique_slug_filter = static function ( $override_slug, string $slug, int $post_id, string $post_status, string $post_type, int $post_parent ) use ( &$unique_slug_events ) {
					$unique_slug_events[] = array(
						'slug'     => $slug,
						'postId'   => $post_id,
						'status'   => $post_status,
						'type'     => $post_type,
						'parent'   => $post_parent,
						'override' => $override_slug,
					);
					return $override_slug;
				};
				\add_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10, 6 );

				try {
					$draft_create_response = $server->dispatch(
						self::request(
							'POST',
							'/wp/v2/posts',
							array(
								'_fields' => 'id,slug,status,title',
								'context' => 'edit',
							),
							array(),
							$draft_request_body
						)
					);
				} catch ( \Throwable $e ) {
					$draft_create_error = $e;
				}
				$draft_create_data = $draft_create_response instanceof \WP_REST_Response ? $draft_create_response->get_data() : array();
				$draft_create_id   = (int) ( $draft_create_data['id'] ?? 0 );
				$draft_create_post = $draft_create_id > 0 ? \get_post( $draft_create_id ) : null;

				try {
					$pending_create_response = $server->dispatch(
						self::request(
							'POST',
							'/wp/v2/posts',
							array(
								'_fields' => 'id,slug,status,title',
								'context' => 'edit',
							),
							array(),
							$pending_request_body
						)
					);
				} catch ( \Throwable $e ) {
					$pending_create_error = $e;
				}
				$pending_create_data = $pending_create_response instanceof \WP_REST_Response ? $pending_create_response->get_data() : array();
				$pending_create_id   = (int) ( $pending_create_data['id'] ?? 0 );
				$pending_create_post = $pending_create_id > 0 ? \get_post( $pending_create_id ) : null;
				$draft_update_id     = \wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'post',
							'post_title'   => 'Draft Update Collision Target ' . $case['token'],
							'post_content' => 'Draft update collision target ' . $case['token'],
							'post_status'  => 'draft',
							'post_name'    => 'draft-update-target-' . $case['token'],
							'post_author'  => $fixtures['author'],
						)
					),
					true,
					false
				);
				$pending_update_id = \wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'post',
							'post_title'   => 'Pending Update Collision Target ' . $case['token'],
							'post_content' => 'Pending update collision target ' . $case['token'],
							'post_status'  => 'pending',
							'post_name'    => 'pending-update-target-' . $case['token'],
							'post_author'  => $fixtures['author'],
						)
					),
					true,
					false
				);
				$draft_update_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/posts/' . (int) $draft_update_id,
						array(
							'_fields' => 'id,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'slug'   => $route_post_slug_base,
							'status' => 'draft',
							'title'  => 'Draft Update Collision Result ' . $case['token'],
						)
					)
				);
				$pending_update_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/posts/' . (int) $pending_update_id,
						array(
							'_fields' => 'id,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'slug'   => $route_post_slug_base,
							'status' => 'pending',
							'title'  => 'Pending Update Collision Result ' . $case['token'],
						)
					)
				);
				$draft_update_data       = $draft_update_response instanceof \WP_REST_Response ? $draft_update_response->get_data() : array();
				$pending_update_data     = $pending_update_response instanceof \WP_REST_Response ? $pending_update_response->get_data() : array();
				$draft_update_post       = is_int( $draft_update_id ) && $draft_update_id > 0 ? \get_post( $draft_update_id ) : null;
				$pending_update_post     = is_int( $pending_update_id ) && $pending_update_id > 0 ? \get_post( $pending_update_id ) : null;
				\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
				$unique_slug_restored = false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter );
				$draft_holder_after   = $create_slug_collision_post instanceof \WP_Post ? \get_post( $create_slug_collision_post->ID ) : null;
				if ( $draft_create_id > 0 ) {
					\wp_delete_post( $draft_create_id, true );
					$draft_create_id = 0;
				}
				if ( $pending_create_id > 0 ) {
					\wp_delete_post( $pending_create_id, true );
					$pending_create_id = 0;
				}
				if ( is_int( $draft_update_id ) && $draft_update_id > 0 ) {
					\wp_delete_post( $draft_update_id, true );
					$draft_update_id = 0;
				}
				if ( is_int( $pending_update_id ) && $pending_update_id > 0 ) {
					\wp_delete_post( $pending_update_id, true );
					$pending_update_id = 0;
				}
				$draft_counts_after   = self::content_counts();
				$draft_pending_observed = array(
					'draftCreate' => array(
						'response' => $draft_create_data,
						'status'   => $draft_create_response instanceof \WP_REST_Response ? $draft_create_response->get_status() : null,
						'error'    => $draft_create_error instanceof \Throwable ? self::describe_throwable( $draft_create_error ) : null,
						'stored'   => $draft_create_post instanceof \WP_Post
							? array(
								'id'     => (int) $draft_create_post->ID,
								'slug'   => $draft_create_post->post_name,
								'status' => $draft_create_post->post_status,
							)
							: null,
					),
					'pendingCreate' => array(
						'response' => $pending_create_data,
						'status'   => $pending_create_response instanceof \WP_REST_Response ? $pending_create_response->get_status() : null,
						'error'    => $pending_create_error instanceof \Throwable ? self::describe_throwable( $pending_create_error ) : null,
						'stored'   => $pending_create_post instanceof \WP_Post
							? array(
								'id'     => (int) $pending_create_post->ID,
								'slug'   => $pending_create_post->post_name,
								'status' => $pending_create_post->post_status,
							)
							: null,
					),
					'updates'  => array(
						'draft'   => array(
							'response' => $draft_update_data,
							'status'   => $draft_update_response instanceof \WP_REST_Response ? $draft_update_response->get_status() : null,
							'stored'   => $draft_update_post instanceof \WP_Post
								? array(
									'id'     => (int) $draft_update_post->ID,
									'slug'   => $draft_update_post->post_name,
									'status' => $draft_update_post->post_status,
								)
								: null,
						),
						'pending' => array(
							'response' => $pending_update_data,
							'status'   => $pending_update_response instanceof \WP_REST_Response ? $pending_update_response->get_status() : null,
							'stored'   => $pending_update_post instanceof \WP_Post
								? array(
									'id'     => (int) $pending_update_post->ID,
									'slug'   => $pending_update_post->post_name,
									'status' => $pending_update_post->post_status,
								)
								: null,
						),
					),
					'holder'   => $draft_holder_after instanceof \WP_Post
						? array(
							'id'     => (int) $draft_holder_after->ID,
							'slug'   => $draft_holder_after->post_name,
							'status' => $draft_holder_after->post_status,
						)
						: null,
					'unique'   => $unique_slug_events,
					'counts'    => array(
						'before' => $draft_counts_before,
						'after'  => $draft_counts_after,
					),
				);

				$created_post_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/posts',
						array(
							'_fields' => 'author,content,generated_slug,id,link,permalink_template,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'author'  => $fixtures['author'],
							'content' => $route_post_content,
							'slug'    => $route_post_slug_base,
							'status'  => 'publish',
							'title'   => $route_post_title,
						)
					)
				);
				$created_post_data = $created_post_response instanceof \WP_REST_Response ? $created_post_response->get_data() : array();
				$created_post_id   = (int) ( $created_post_data['id'] ?? 0 );

				$created_term_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/categories',
						array( '_fields' => 'id,name,parent,slug,taxonomy' ),
						array(),
						array(
							'description' => 'Route term description ' . $case['token'],
							'name'        => $route_term_name,
							'parent'      => $fixtures['term'],
							'slug'        => $route_term_slug,
						)
					)
				);
				$created_term_data = $created_term_response instanceof \WP_REST_Response ? $created_term_response->get_data() : array();
				$created_term_id   = (int) ( $created_term_data['id'] ?? 0 );

				$created_comment_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/comments',
						array( '_fields' => 'content,id,parent,post,status,type' ),
						array(),
						array(
							'author'       => $fixtures['author'],
							'author_email' => $route_comment_email,
							'author_name'  => $case['commentAuthorName'],
							'content'      => $route_comment_body,
							'parent'       => $fixtures['comment'],
							'post'         => $fixtures['post'],
							'status'       => 'approve',
							'type'         => 'comment',
						)
					)
				);
				$created_comment_data = $created_comment_response instanceof \WP_REST_Response ? $created_comment_response->get_data() : array();
				$created_comment_id   = (int) ( $created_comment_data['id'] ?? 0 );

				$created_user_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/users',
						array( '_fields' => 'email,id,name,slug,username' ),
						array(),
						array(
							'email'    => $route_user_email,
							'name'     => $route_user_name,
							'password' => $route_user_password,
							'slug'     => $route_user_slug,
							'username' => $route_user_login,
						)
					)
				);
				$created_user_data = $created_user_response instanceof \WP_REST_Response ? $created_user_response->get_data() : array();
				$created_user_id   = (int) ( $created_user_data['id'] ?? 0 );

				$post_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/posts/' . $created_post_id,
						array(),
						array(),
						array( 'force' => false )
					)
				);
				$term_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/categories/' . $created_term_id,
						array(),
						array(),
						array( 'force' => false )
					)
				);
				$comment_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/comments/' . $created_comment_id,
						array(),
						array(),
						array( 'force' => false )
					)
				);
				$user_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/users/' . $created_user_id,
						array(),
						array(),
						array(
							'force'    => false,
							'reassign' => false,
						)
					)
				);
			} finally {
				\remove_filter( 'sanitize_email', $sanitize_email_filter, 10 );
				$sanitize_filter_restored = false === \has_filter( 'sanitize_email', $sanitize_email_filter );
				\remove_filter( 'is_email', $email_filter, 10 );
				$email_filter_restored = false === \has_filter( 'is_email', $email_filter );
				\remove_filter( 'rest_comment_trashable', $comment_trash_filter, 10 );
				$comment_filter_restored = false === \has_filter( 'rest_comment_trashable', $comment_trash_filter );
				\remove_filter( 'rest_post_trashable', $post_trash_filter, 10 );
				$post_filter_restored  = false === \has_filter( 'rest_post_trashable', $post_trash_filter );
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter            = null;
			}

			$counts_after    = self::content_counts();
			$created_post    = \get_post( $created_post_id );
			$created_term    = \get_term( $created_term_id, 'category' );
			$created_comment = \get_comment( $created_comment_id );
			$created_user    = \get_user_by( 'id', $created_user_id );
			$created_post_link = $created_post instanceof \WP_Post ? \get_permalink( $created_post ) : null;

			$observed = array(
				'deniedCreate'   => $denied_create_response instanceof \WP_REST_Response ? $denied_create_response->get_data() : $denied_create_response,
				'created'        => array(
					'post'    => $created_post_data,
					'term'    => $created_term_data,
					'comment' => $created_comment_data,
					'user'    => $created_user_data,
				),
				'deleteErrors'   => array(
					'post'    => $post_delete_response instanceof \WP_REST_Response ? $post_delete_response->get_data() : $post_delete_response,
					'term'    => $term_delete_response instanceof \WP_REST_Response ? $term_delete_response->get_data() : $term_delete_response,
					'comment' => $comment_delete_response instanceof \WP_REST_Response ? $comment_delete_response->get_data() : $comment_delete_response,
					'user'    => $user_delete_response instanceof \WP_REST_Response ? $user_delete_response->get_data() : $user_delete_response,
				),
				'storedRows'     => array(
					'post'    => $created_post instanceof \WP_Post
						? array(
							'id'      => (int) $created_post->ID,
							'author'  => (int) $created_post->post_author,
							'content' => $created_post->post_content,
							'link'    => $created_post_link,
							'slug'    => $created_post->post_name,
							'status'  => $created_post->post_status,
							'title'   => $created_post->post_title,
						)
						: null,
					'term'    => $created_term instanceof \WP_Term
						? array(
							'id'     => (int) $created_term->term_id,
							'name'   => $created_term->name,
							'parent' => (int) $created_term->parent,
							'slug'   => $created_term->slug,
						)
						: null,
					'comment' => $created_comment instanceof \WP_Comment
						? array(
							'id'       => (int) $created_comment->comment_ID,
							'approved' => $created_comment->comment_approved,
							'content'  => $created_comment->comment_content,
							'parent'   => (int) $created_comment->comment_parent,
							'post'     => (int) $created_comment->comment_post_ID,
						)
						: null,
					'user'    => $created_user instanceof \WP_User
						? array(
							'id'       => (int) $created_user->ID,
							'email'    => $created_user->user_email,
							'login'    => $created_user->user_login,
							'nicename' => $created_user->user_nicename,
							'name'     => $created_user->display_name,
						)
						: null,
				),
				'slugCollision'  => array(
					'id'       => self::describe_value( $create_slug_collision_id ),
					'postSlug' => $create_slug_collision_post instanceof \WP_Post ? $create_slug_collision_post->post_name : null,
					'expected' => $route_post_slug_base,
				),
				'createdPostLocation' => $created_post_response instanceof \WP_REST_Response ? $created_post_response->get_headers()['Location'] ?? null : null,
				'countsBefore'   => $counts_before,
				'countsDenied'   => $counts_after_denial,
				'countsAfter'    => $counts_after,
				'filterRestored' => array(
					'cap'           => $write_filter_restored,
					'post'          => $post_filter_restored,
					'comment'       => $comment_filter_restored,
					'email'         => $email_filter_restored,
					'sanitizeEmail' => $sanitize_filter_restored,
				),
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $denied_create_response, 'rest_cannot_create', 401 )
					&& self::content_count_delta_matches( $counts_before, $counts_after_denial, array(), array() ),
				'route-dispatched post create denies unauthenticated requests before row changes',
				array(
					'deniedCreate' => $observed['deniedCreate'],
					'countsBefore' => $counts_before,
					'countsDenied' => $counts_after_denial,
				)
			);

			$unique_slug_publish_statuses = array_filter(
				$unique_slug_events,
				static function ( array $event ) use ( $route_post_slug_base ): bool {
					return $route_post_slug_base === ( $event['slug'] ?? null )
						&& 'publish' === ( $event['status'] ?? null )
						&& 'post' === ( $event['type'] ?? null )
						&& 0 === (int) ( $event['parent'] ?? -1 );
				}
			);

			self::collect_failure(
				$failures,
				null === $draft_create_error
					&& null === $pending_create_error
					&& $unique_slug_restored
					&& $draft_create_response instanceof \WP_REST_Response
					&& 201 === $draft_create_response->get_status()
					&& self::projected_keys_match( $draft_create_data, array( 'id', 'slug', 'status', 'title' ) )
					&& $route_post_slug_expected === ( $draft_create_data['slug'] ?? null )
					&& 'draft' === ( $draft_create_data['status'] ?? null )
					&& $draft_create_post instanceof \WP_Post
					&& $route_post_slug_expected === $draft_create_post->post_name
					&& 'draft' === $draft_create_post->post_status
					&& $pending_create_response instanceof \WP_REST_Response
					&& 201 === $pending_create_response->get_status()
					&& self::projected_keys_match( $pending_create_data, array( 'id', 'slug', 'status', 'title' ) )
					&& $route_post_slug_base . '-3' === ( $pending_create_data['slug'] ?? null )
					&& 'pending' === ( $pending_create_data['status'] ?? null )
					&& $pending_create_post instanceof \WP_Post
					&& $route_post_slug_base . '-3' === $pending_create_post->post_name
					&& 'pending' === $pending_create_post->post_status
					&& $draft_update_response instanceof \WP_REST_Response
					&& 200 === $draft_update_response->get_status()
					&& self::projected_keys_match( $draft_update_data, array( 'id', 'slug', 'status', 'title' ) )
					&& $route_post_slug_base . '-4' === ( $draft_update_data['slug'] ?? null )
					&& 'draft' === ( $draft_update_data['status'] ?? null )
					&& $draft_update_post instanceof \WP_Post
					&& $route_post_slug_base . '-4' === $draft_update_post->post_name
					&& 'draft' === $draft_update_post->post_status
					&& $pending_update_response instanceof \WP_REST_Response
					&& 200 === $pending_update_response->get_status()
					&& self::projected_keys_match( $pending_update_data, array( 'id', 'slug', 'status', 'title' ) )
					&& $route_post_slug_base . '-5' === ( $pending_update_data['slug'] ?? null )
					&& 'pending' === ( $pending_update_data['status'] ?? null )
					&& $pending_update_post instanceof \WP_Post
					&& $route_post_slug_base . '-5' === $pending_update_post->post_name
					&& 'pending' === $pending_update_post->post_status
					&& 4 === count( $unique_slug_events )
					&& 4 === count( $unique_slug_publish_statuses )
					&& $draft_holder_after instanceof \WP_Post
					&& $route_post_slug_base === $draft_holder_after->post_name
					&& self::content_count_delta_matches( $draft_counts_before, $draft_counts_after, array(), array() ),
				'route-dispatched draft and pending post create/update slug uniqueness uses unguarded publish-status checks without leaking warnings or rows',
				$draft_pending_observed
			);

			self::collect_failure(
				$failures,
				$created_post_response instanceof \WP_REST_Response
					&& 201 === $created_post_response->get_status()
					&& self::projected_keys_match( $created_post_data, array( 'author', 'content', 'generated_slug', 'id', 'link', 'permalink_template', 'slug', 'status', 'title' ) )
					&& $created_post_id > 0
					&& $fixtures['author'] === (int) ( $created_post_data['author'] ?? 0 )
					&& $route_post_title === ( $created_post_data['title']['raw'] ?? null )
					&& $route_post_content === ( $created_post_data['content']['raw'] ?? null )
					&& $create_slug_collision_post instanceof \WP_Post
					&& $route_post_slug_base === $create_slug_collision_post->post_name
					&& $route_post_slug_expected === ( $created_post_data['slug'] ?? null )
					&& $route_post_slug_expected === ( $created_post_data['generated_slug'] ?? null )
					&& $created_post_link === ( $created_post_data['link'] ?? null )
					&& \rest_url( 'wp/v2/posts/' . $created_post_id ) === ( $created_post_response->get_headers()['Location'] ?? null )
					&& is_string( $created_post_data['permalink_template'] ?? null )
					&& str_contains( $created_post_data['permalink_template'], 'http://example.test/' )
					&& str_contains( $created_post_data['permalink_template'], '%postname%' )
					&& $created_post instanceof \WP_Post
					&& $route_post_title === $created_post->post_title
					&& $route_post_content === $created_post->post_content
					&& 'publish' === $created_post->post_status
					&& $route_post_slug_expected === $created_post->post_name
					&& $fixtures['author'] === (int) $created_post->post_author
					&& $created_term_response instanceof \WP_REST_Response
					&& 201 === $created_term_response->get_status()
					&& self::projected_keys_match( $created_term_data, array( 'id', 'name', 'parent', 'slug', 'taxonomy' ) )
					&& $created_term_id > 0
					&& $route_term_name === ( $created_term_data['name'] ?? null )
					&& $fixtures['term'] === (int) ( $created_term_data['parent'] ?? 0 )
					&& 'category' === ( $created_term_data['taxonomy'] ?? null )
					&& $created_term instanceof \WP_Term
					&& $route_term_name === $created_term->name
					&& $fixtures['term'] === (int) $created_term->parent
					&& $created_comment_response instanceof \WP_REST_Response
					&& 201 === $created_comment_response->get_status()
					&& self::projected_keys_match( $created_comment_data, array( 'content', 'id', 'parent', 'post', 'status', 'type' ) )
					&& $created_comment_id > 0
					&& trim( $route_comment_body ) === ( $created_comment_data['content']['raw'] ?? null )
					&& $fixtures['comment'] === (int) ( $created_comment_data['parent'] ?? 0 )
					&& $fixtures['post'] === (int) ( $created_comment_data['post'] ?? 0 )
					&& 'approved' === ( $created_comment_data['status'] ?? null )
					&& $created_comment instanceof \WP_Comment
					&& trim( $route_comment_body ) === $created_comment->comment_content
					&& '1' === (string) $created_comment->comment_approved
					&& $fixtures['comment'] === (int) $created_comment->comment_parent
					&& $created_user_response instanceof \WP_REST_Response
					&& 201 === $created_user_response->get_status()
					&& self::projected_keys_match( $created_user_data, array( 'email', 'id', 'name', 'slug', 'username' ) )
					&& $created_user_id > 0
					&& $route_user_login === ( $created_user_data['username'] ?? null )
					&& $route_user_email === ( $created_user_data['email'] ?? null )
					&& $route_user_name === ( $created_user_data['name'] ?? null )
					&& $created_user instanceof \WP_User
					&& $route_user_login === $created_user->user_login
					&& $route_user_email === $created_user->user_email
					&& $route_user_name === $created_user->display_name,
				'route-dispatched creates normalize payloads, project requested fields, and persist stored rows',
				$observed
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $post_delete_response, 'rest_trash_not_supported', 501 )
					&& self::response_error_ok( $term_delete_response, 'rest_trash_not_supported', 501 )
					&& self::response_error_ok( $comment_delete_response, 'rest_trash_not_supported', 501 )
					&& self::response_error_ok( $user_delete_response, 'rest_trash_not_supported', 501 )
					&& $created_post instanceof \WP_Post
					&& $created_term instanceof \WP_Term
					&& $created_comment instanceof \WP_Comment
					&& $created_user instanceof \WP_User
					&& self::content_count_delta_matches(
						$counts_before,
						$counts_after,
						array(
							'comments'      => 1,
							'posts'         => 1,
							'term_taxonomy' => 1,
							'terms'         => 1,
							'users'         => 1,
						),
						array( 'comments', 'posts', 'term_taxonomy', 'terms', 'user_meta', 'users' )
					),
				'route-dispatched delete requests without trash support fail closed and preserve created rows',
				$observed
			);
		} finally {
			if ( isset( $sanitize_email_filter ) && false !== \has_filter( 'sanitize_email', $sanitize_email_filter ) ) {
				\remove_filter( 'sanitize_email', $sanitize_email_filter, 10 );
				$sanitize_filter_restored = false === \has_filter( 'sanitize_email', $sanitize_email_filter );
			}
			if ( isset( $email_filter ) && false !== \has_filter( 'is_email', $email_filter ) ) {
				\remove_filter( 'is_email', $email_filter, 10 );
				$email_filter_restored = false === \has_filter( 'is_email', $email_filter );
			}
			if ( false !== \has_filter( 'rest_comment_trashable', $comment_trash_filter ) ) {
				\remove_filter( 'rest_comment_trashable', $comment_trash_filter, 10 );
				$comment_filter_restored = false === \has_filter( 'rest_comment_trashable', $comment_trash_filter );
			}
			if ( false !== \has_filter( 'rest_post_trashable', $post_trash_filter ) ) {
				\remove_filter( 'rest_post_trashable', $post_trash_filter, 10 );
				$post_filter_restored = false === \has_filter( 'rest_post_trashable', $post_trash_filter );
			}
			if ( null !== $cap_filter ) {
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
			}

			if ( null !== $unique_slug_filter && false !== \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter ) ) {
				\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
				$unique_slug_restored = false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter );
			}
			if ( is_int( $draft_create_id ) && $draft_create_id > 0 ) {
				\wp_delete_post( $draft_create_id, true );
			}
			if ( is_int( $pending_create_id ) && $pending_create_id > 0 ) {
				\wp_delete_post( $pending_create_id, true );
			}
			if ( is_int( $draft_update_id ) && $draft_update_id > 0 ) {
				\wp_delete_post( $draft_update_id, true );
			}
			if ( is_int( $pending_update_id ) && $pending_update_id > 0 ) {
				\wp_delete_post( $pending_update_id, true );
			}
			if ( is_int( $create_slug_collision_id ) && $create_slug_collision_id > 0 ) {
				\wp_delete_post( $create_slug_collision_id, true );
			}

			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			$permalink_filter_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter );

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			\wp_set_current_user( $previous_current_user_id );

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

			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
				&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
		}

		self::collect_failure(
			$failures,
			$write_filter_restored
				&& $post_filter_restored
				&& $comment_filter_restored
				&& $email_filter_restored
				&& $sanitize_filter_restored
				&& $permalink_filter_restored
				&& $rewrite_restored
				&& $unique_slug_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored,
			'route-dispatched object create/delete harness restores caps, trash filters, server, actions, and current user',
			array(
				'writeFilterRestored'   => $write_filter_restored,
				'postFilterRestored'    => $post_filter_restored,
				'commentFilterRestored' => $comment_filter_restored,
				'emailFilterRestored'   => $email_filter_restored,
				'sanitizeFilterRestored' => $sanitize_filter_restored,
				'permalinkFilterRestored' => $permalink_filter_restored,
				'rewriteRestored'        => $rewrite_restored,
				'uniqueSlugRestored'     => $unique_slug_restored,
				'serverRestored'        => $server_restored,
				'actionsRestored'       => $actions_restored,
				'currentUserRestored'   => $current_user_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.route-dispatched-create-delete-validation-cleanup',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_route_dispatched_page_parent_slug_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$observed = array();
		$post_ids = array();

		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;
		$previous_rewrite_set     = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite         = $GLOBALS['wp_rewrite'] ?? null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$permalink_structure      = '/%postname%/';
		$permalink_filter         = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$cap_filter               = null;
		$active_unique_label      = null;
		$unique_slug_events       = array();
		$write_filter_restored    = false;
		$permalink_filter_restored = false;
		$unique_slug_restored     = false;
		$rewrite_restored         = false;
		$server_restored          = false;
		$actions_restored         = false;
		$current_user_restored    = false;
		$counts_before            = self::content_counts();
		$counts_after_cleanup     = array();

		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$delete_posts = static function () use ( &$post_ids ): void {
			foreach ( array_reverse( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}

			$post_ids = array();
		};
		$post_summary = static function ( $post ): ?array {
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}

			return array(
				'id'     => (int) $post->ID,
				'parent' => (int) $post->post_parent,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
				'title'  => $post->post_title,
			);
		};
		$events_for_label = static function ( string $label ) use ( &$unique_slug_events ): array {
			return array_values(
				array_filter(
					$unique_slug_events,
					static function ( array $event ) use ( $label ): bool {
						return $label === ( $event['label'] ?? null );
					}
				)
			);
		};
		$event_matches = static function ( array $events, string $slug, int $post_id, int $parent ): bool {
			if ( 1 !== count( $events ) ) {
				return false;
			}

			$event = $events[0];
			return $slug === ( $event['slug'] ?? null )
				&& $post_id === (int) ( $event['postId'] ?? -1 )
				&& 'publish' === ( $event['status'] ?? null )
				&& 'page' === ( $event['type'] ?? null )
				&& $parent === (int) ( $event['parent'] ?? -1 )
				&& null === ( $event['override'] ?? null );
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
			$controller = new \WP_REST_Posts_Controller( 'page' );
			$controller->register_routes();

			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			$parent_a_slug     = 'rest-page-parent-a-' . $case['token'];
			$parent_b_slug     = 'rest-page-parent-b-' . $case['token'];
			$base_slug         = 'rest-page-child-' . $case['token'];
			$base_title        = 'Rest Page Child ' . $case['token'];
			$base_generated_slug = \sanitize_title( $base_title );
			$root_slug         = 'rest-page-root-' . $case['token'];
			$expected_template = \home_url( '/' . $parent_a_slug . '/%pagename%' );

			$parent_a_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Parent A ' . $case['token'],
							'post_content' => 'REST page parent A ' . $case['token'],
							'post_status'  => 'publish',
							'post_name'    => $parent_a_slug,
							'post_author'  => $fixtures['author'],
							'post_parent'  => 0,
						)
					),
					true,
					false
				)
			);
			$parent_b_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Parent B ' . $case['token'],
							'post_content' => 'REST page parent B ' . $case['token'],
							'post_status'  => 'publish',
							'post_name'    => $parent_b_slug,
							'post_author'  => $fixtures['author'],
							'post_parent'  => 0,
						)
					),
					true,
					false
				)
			);
			$parent_a_holder_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Parent A Holder ' . $case['token'],
							'post_content' => 'REST page parent A holder ' . $case['token'],
							'post_status'  => 'publish',
							'post_name'    => $base_slug,
							'post_author'  => $fixtures['author'],
							'post_parent'  => $parent_a_id,
						)
					),
					true,
					false
				)
			);
			$parent_a_suffix_holder_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Parent A Suffix Holder ' . $case['token'],
							'post_content' => 'REST page parent A suffix holder ' . $case['token'],
							'post_status'  => 'publish',
							'post_name'    => $base_slug . '-2',
							'post_author'  => $fixtures['author'],
							'post_parent'  => $parent_a_id,
						)
					),
					true,
					false
				)
			);
			$parent_b_holder_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Parent B Holder ' . $case['token'],
							'post_content' => 'REST page parent B holder ' . $case['token'],
							'post_status'  => 'publish',
							'post_name'    => $base_slug,
							'post_author'  => $fixtures['author'],
							'post_parent'  => $parent_b_id,
						)
					),
					true,
					false
				)
			);
			$root_holder_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Root Holder ' . $case['token'],
							'post_content' => 'REST page root holder ' . $case['token'],
							'post_status'  => 'publish',
							'post_name'    => $root_slug,
							'post_author'  => $fixtures['author'],
							'post_parent'  => 0,
						)
					),
					true,
					false
				)
			);
			$draft_update_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Omitted Parent Target ' . $case['token'],
							'post_content' => 'REST page omitted parent target ' . $case['token'],
							'post_status'  => 'draft',
							'post_name'    => 'rest-page-omitted-parent-target-' . $case['token'],
							'post_author'  => $fixtures['author'],
							'post_parent'  => $parent_a_id,
						)
					),
					true,
					false
				)
			);
			$pending_update_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Explicit Parent Target ' . $case['token'],
							'post_content' => 'REST page explicit parent target ' . $case['token'],
							'post_status'  => 'pending',
							'post_name'    => 'rest-page-explicit-parent-target-' . $case['token'],
							'post_author'  => $fixtures['author'],
							'post_parent'  => $parent_b_id,
						)
					),
					true,
					false
				)
			);
			$root_update_id = $remember_post(
				\wp_insert_post(
					\wp_slash(
						array(
							'post_type'    => 'page',
							'post_title'   => 'REST Page Explicit Root Target ' . $case['token'],
							'post_content' => 'REST page explicit root target ' . $case['token'],
							'post_status'  => 'draft',
							'post_name'    => 'rest-page-explicit-root-target-' . $case['token'],
							'post_author'  => $fixtures['author'],
							'post_parent'  => $parent_a_id,
						)
					),
					true,
					false
				)
			);

			\wp_set_current_user( $fixtures['author'] );
			$cap_filter = self::install_cap_filter(
				array(
					'delete_others_pages',
					'delete_pages',
					'delete_post',
					'edit_others_pages',
					'edit_page',
					'edit_pages',
					'edit_post',
					'edit_posts',
					'edit_private_pages',
					'edit_published_pages',
					'publish_pages',
					'read',
					'read_page',
					'read_post',
				)
			);
			\add_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10, 6 );

			$active_unique_label = 'draft-create-parent-a';
			try {
				$draft_create_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/pages',
						array(
							'_fields' => 'id,parent,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'content' => '<p>REST draft page parent slug content ' . $case['token'] . '</p>',
							'parent'  => $parent_a_id,
							'slug'    => $base_slug,
							'status'  => 'draft',
							'title'   => $base_title,
						)
					)
				);
			} finally {
				$active_unique_label = null;
			}
			$draft_create_data = $draft_create_response instanceof \WP_REST_Response ? $draft_create_response->get_data() : array();
			$draft_create_id   = (int) ( $draft_create_data['id'] ?? 0 );
			if ( $draft_create_id > 0 ) {
				$post_ids[] = $draft_create_id;
			}
			$draft_create_post = $draft_create_id > 0 ? \get_post( $draft_create_id ) : null;

			$active_unique_label = 'pending-create-parent-b';
			try {
				$pending_create_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/pages',
						array(
							'_fields' => 'id,parent,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'content' => '<p>REST pending page parent slug content ' . $case['token'] . '</p>',
							'parent'  => $parent_b_id,
							'slug'    => $base_slug,
							'status'  => 'pending',
							'title'   => $base_title,
						)
					)
				);
			} finally {
				$active_unique_label = null;
			}
			$pending_create_data = $pending_create_response instanceof \WP_REST_Response ? $pending_create_response->get_data() : array();
			$pending_create_id   = (int) ( $pending_create_data['id'] ?? 0 );
			if ( $pending_create_id > 0 ) {
				$post_ids[] = $pending_create_id;
			}
			$pending_create_post = $pending_create_id > 0 ? \get_post( $pending_create_id ) : null;

			$active_unique_label = 'draft-update-omitted-parent';
			try {
				$draft_update_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/pages/' . $draft_update_id,
						array(
							'_fields' => 'id,parent,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'slug'   => $base_slug,
							'status' => 'draft',
							'title'  => $base_title,
						)
					)
				);
			} finally {
				$active_unique_label = null;
			}
			$draft_update_data = $draft_update_response instanceof \WP_REST_Response ? $draft_update_response->get_data() : array();
			$draft_update_post = $draft_update_id > 0 ? \get_post( $draft_update_id ) : null;

			$active_unique_label = 'pending-update-explicit-parent';
			try {
				$pending_update_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/pages/' . $pending_update_id,
						array(
							'_fields' => 'id,parent,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $parent_b_id,
							'slug'   => $base_slug,
							'status' => 'pending',
							'title'  => $base_title,
						)
					)
				);
			} finally {
				$active_unique_label = null;
			}
			$pending_update_data = $pending_update_response instanceof \WP_REST_Response ? $pending_update_response->get_data() : array();
			$pending_update_post = $pending_update_id > 0 ? \get_post( $pending_update_id ) : null;

			$active_unique_label = 'draft-update-explicit-root';
			try {
				$root_update_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/pages/' . $root_update_id,
						array(
							'_fields' => 'id,parent,slug,status,title',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => 0,
							'slug'   => $root_slug,
							'status' => 'draft',
							'title'  => 'Rest Page Root ' . $case['token'],
						)
					)
				);
			} finally {
				$active_unique_label = null;
			}
			$root_update_data = $root_update_response instanceof \WP_REST_Response ? $root_update_response->get_data() : array();
			$root_update_post = $root_update_id > 0 ? \get_post( $root_update_id ) : null;

			\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
			$unique_slug_restored = false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter );

			$edit_get_response = $server->dispatch(
				self::request(
					'GET',
					'/wp/v2/pages/' . $draft_update_id,
					array(
						'_fields' => 'generated_slug,permalink_template,parent,slug,status',
						'context' => 'edit',
					)
				)
			);
			$edit_get_data = $edit_get_response instanceof \WP_REST_Response ? $edit_get_response->get_data() : array();

			$draft_create_events          = $events_for_label( 'draft-create-parent-a' );
			$pending_create_events        = $events_for_label( 'pending-create-parent-b' );
			$draft_update_events          = $events_for_label( 'draft-update-omitted-parent' );
			$pending_update_events        = $events_for_label( 'pending-update-explicit-parent' );
			$root_update_events           = $events_for_label( 'draft-update-explicit-root' );
			$parent_a_holder_after        = $parent_a_holder_id > 0 ? \get_post( $parent_a_holder_id ) : null;
			$parent_a_suffix_holder_after = $parent_a_suffix_holder_id > 0 ? \get_post( $parent_a_suffix_holder_id ) : null;
			$parent_b_holder_after        = $parent_b_holder_id > 0 ? \get_post( $parent_b_holder_id ) : null;
			$root_holder_after            = $root_holder_id > 0 ? \get_post( $root_holder_id ) : null;

			$observed = array(
				'fixtures' => array(
					'parentA'             => $post_summary( $parent_a_id > 0 ? \get_post( $parent_a_id ) : null ),
					'parentB'             => $post_summary( $parent_b_id > 0 ? \get_post( $parent_b_id ) : null ),
					'parentAHolder'       => $post_summary( $parent_a_holder_after ),
					'parentASuffixHolder' => $post_summary( $parent_a_suffix_holder_after ),
					'parentBHolder'       => $post_summary( $parent_b_holder_after ),
					'rootHolder'          => $post_summary( $root_holder_after ),
				),
				'responses' => array(
					'draftCreate'   => array(
						'status' => $draft_create_response instanceof \WP_REST_Response ? $draft_create_response->get_status() : null,
						'data'   => $draft_create_data,
						'stored' => $post_summary( $draft_create_post ),
					),
					'pendingCreate' => array(
						'status' => $pending_create_response instanceof \WP_REST_Response ? $pending_create_response->get_status() : null,
						'data'   => $pending_create_data,
						'stored' => $post_summary( $pending_create_post ),
					),
					'draftUpdate'   => array(
						'status' => $draft_update_response instanceof \WP_REST_Response ? $draft_update_response->get_status() : null,
						'data'   => $draft_update_data,
						'stored' => $post_summary( $draft_update_post ),
					),
					'pendingUpdate' => array(
						'status' => $pending_update_response instanceof \WP_REST_Response ? $pending_update_response->get_status() : null,
						'data'   => $pending_update_data,
						'stored' => $post_summary( $pending_update_post ),
					),
					'rootUpdate'    => array(
						'status' => $root_update_response instanceof \WP_REST_Response ? $root_update_response->get_status() : null,
						'data'   => $root_update_data,
						'stored' => $post_summary( $root_update_post ),
					),
					'editGet'       => array(
						'status' => $edit_get_response instanceof \WP_REST_Response ? $edit_get_response->get_status() : null,
						'data'   => $edit_get_data,
					),
				),
				'unique'   => array(
					'all'                   => $unique_slug_events,
					'draftCreate'           => $draft_create_events,
					'pendingCreate'         => $pending_create_events,
					'draftUpdateOmitted'    => $draft_update_events,
					'pendingUpdateExplicit' => $pending_update_events,
					'rootUpdateExplicit'    => $root_update_events,
				),
				'expected' => array(
					'baseSlug'          => $base_slug,
					'rootSlug'          => $root_slug,
					'parentTemplate'    => $expected_template,
					'editGeneratedSlug' => $base_generated_slug,
					'draftCreateSlug'   => $base_slug . '-3',
					'pendingCreateSlug' => $base_slug . '-2',
					'draftUpdateSlug'   => $base_slug . '-4',
					'pendingUpdateSlug' => $base_slug . '-3',
					'rootUpdateSlug'    => $root_slug . '-2',
				),
			);

			self::collect_failure(
				$failures,
				$parent_a_id > 0
					&& $parent_b_id > 0
					&& $parent_a_holder_id > 0
					&& $parent_a_suffix_holder_id > 0
					&& $parent_b_holder_id > 0
					&& $root_holder_id > 0
					&& $draft_update_id > 0
					&& $pending_update_id > 0
					&& $root_update_id > 0
					&& $parent_a_holder_after instanceof \WP_Post
					&& $base_slug === $parent_a_holder_after->post_name
					&& $parent_a_id === (int) $parent_a_holder_after->post_parent
					&& $parent_a_suffix_holder_after instanceof \WP_Post
					&& $base_slug . '-2' === $parent_a_suffix_holder_after->post_name
					&& $parent_a_id === (int) $parent_a_suffix_holder_after->post_parent
					&& $parent_b_holder_after instanceof \WP_Post
					&& $base_slug === $parent_b_holder_after->post_name
					&& $parent_b_id === (int) $parent_b_holder_after->post_parent
					&& $root_holder_after instanceof \WP_Post
					&& $root_slug === $root_holder_after->post_name
					&& 0 === (int) $root_holder_after->post_parent,
				'page parent slug fixtures create separate parent and root collision scopes',
				$observed['fixtures']
			);

			self::collect_failure(
				$failures,
				$unique_slug_restored
					&& 5 === count( $unique_slug_events )
					&& $event_matches( $draft_create_events, $base_slug, 0, $parent_a_id )
					&& $event_matches( $pending_create_events, $base_slug, 0, $parent_b_id )
					&& $event_matches( $draft_update_events, $base_slug, $draft_update_id, $parent_a_id )
					&& $event_matches( $pending_update_events, $base_slug, $pending_update_id, $parent_b_id )
					&& $event_matches( $root_update_events, $root_slug, $root_update_id, 0 ),
				'page draft/pending create and update route dispatch passes publish-status uniqueness the correct parent scope',
				$observed['unique']
			);

			self::collect_failure(
				$failures,
				$draft_create_response instanceof \WP_REST_Response
					&& 201 === $draft_create_response->get_status()
					&& self::projected_keys_match( $draft_create_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
					&& $base_slug . '-3' === ( $draft_create_data['slug'] ?? null )
					&& 'draft' === ( $draft_create_data['status'] ?? null )
					&& $parent_a_id === (int) ( $draft_create_data['parent'] ?? 0 )
					&& $draft_create_post instanceof \WP_Post
					&& $base_slug . '-3' === $draft_create_post->post_name
					&& 'draft' === $draft_create_post->post_status
					&& $parent_a_id === (int) $draft_create_post->post_parent
					&& $pending_create_response instanceof \WP_REST_Response
					&& 201 === $pending_create_response->get_status()
					&& self::projected_keys_match( $pending_create_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
					&& $base_slug . '-2' === ( $pending_create_data['slug'] ?? null )
					&& 'pending' === ( $pending_create_data['status'] ?? null )
					&& $parent_b_id === (int) ( $pending_create_data['parent'] ?? 0 )
					&& $pending_create_post instanceof \WP_Post
					&& $base_slug . '-2' === $pending_create_post->post_name
					&& 'pending' === $pending_create_post->post_status
					&& $parent_b_id === (int) $pending_create_post->post_parent,
				'route-dispatched page draft and pending creates use parent-scoped slug collisions before insert',
				array(
					'draftCreate'   => $observed['responses']['draftCreate'],
					'pendingCreate' => $observed['responses']['pendingCreate'],
				)
			);

			self::collect_failure(
				$failures,
				$draft_update_response instanceof \WP_REST_Response
					&& 200 === $draft_update_response->get_status()
					&& self::projected_keys_match( $draft_update_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
					&& $base_slug . '-4' === ( $draft_update_data['slug'] ?? null )
					&& 'draft' === ( $draft_update_data['status'] ?? null )
					&& $parent_a_id === (int) ( $draft_update_data['parent'] ?? 0 )
					&& $draft_update_post instanceof \WP_Post
					&& $base_slug . '-4' === $draft_update_post->post_name
					&& 'draft' === $draft_update_post->post_status
					&& $parent_a_id === (int) $draft_update_post->post_parent
					&& $pending_update_response instanceof \WP_REST_Response
					&& 200 === $pending_update_response->get_status()
					&& self::projected_keys_match( $pending_update_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
					&& $base_slug . '-3' === ( $pending_update_data['slug'] ?? null )
					&& 'pending' === ( $pending_update_data['status'] ?? null )
					&& $parent_b_id === (int) ( $pending_update_data['parent'] ?? 0 )
					&& $pending_update_post instanceof \WP_Post
					&& $base_slug . '-3' === $pending_update_post->post_name
					&& 'pending' === $pending_update_post->post_status
					&& $parent_b_id === (int) $pending_update_post->post_parent
					&& $root_update_response instanceof \WP_REST_Response
					&& 200 === $root_update_response->get_status()
					&& self::projected_keys_match( $root_update_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
					&& $root_slug . '-2' === ( $root_update_data['slug'] ?? null )
					&& 'draft' === ( $root_update_data['status'] ?? null )
					&& 0 === (int) ( $root_update_data['parent'] ?? -1 )
					&& $root_update_post instanceof \WP_Post
					&& $root_slug . '-2' === $root_update_post->post_name
					&& 'draft' === $root_update_post->post_status
					&& 0 === (int) $root_update_post->post_parent,
				'route-dispatched page draft and pending updates distinguish omitted parent, explicit parent, and explicit root slug scopes',
				array(
					'draftUpdate'   => $observed['responses']['draftUpdate'],
					'pendingUpdate' => $observed['responses']['pendingUpdate'],
					'rootUpdate'    => $observed['responses']['rootUpdate'],
				)
			);

			self::collect_failure(
				$failures,
				$edit_get_response instanceof \WP_REST_Response
					&& 200 === $edit_get_response->get_status()
					&& self::projected_keys_match( $edit_get_data, array( 'generated_slug', 'id', 'parent', 'permalink_template', 'slug', 'status' ) )
					&& $draft_update_id === (int) ( $edit_get_data['id'] ?? 0 )
					&& $base_slug . '-4' === ( $edit_get_data['slug'] ?? null )
					&& $base_generated_slug === ( $edit_get_data['generated_slug'] ?? null )
					&& $parent_a_id === (int) ( $edit_get_data['parent'] ?? 0 )
					&& 'draft' === ( $edit_get_data['status'] ?? null )
					&& $expected_template === ( $edit_get_data['permalink_template'] ?? null ),
				'route-dispatched page edit response exposes parent-aware generated_slug and permalink_template',
				$observed['responses']['editGet']
			);

			$delete_posts();
			$counts_after_cleanup = self::content_counts();
			$observed['counts']   = array(
				'before' => $counts_before,
				'after'  => $counts_after_cleanup,
			);

			self::collect_failure(
				$failures,
				self::content_count_delta_matches( $counts_before, $counts_after_cleanup, array(), array() ),
				'page parent slug route dispatch cleans up all generated page fixtures',
				$observed['counts']
			);
		} finally {
			if ( false !== \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter ) ) {
				\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
			}
			$unique_slug_restored = false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter );

			$delete_posts();

			if ( null !== $cap_filter ) {
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter            = null;
			} else {
				$write_filter_restored = true;
			}

			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			$permalink_filter_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter );

			if ( $previous_rewrite_set ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}

			\wp_set_current_user( $previous_current_user_id );

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

			$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
				&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
		}

		self::collect_failure(
			$failures,
			$write_filter_restored
				&& $permalink_filter_restored
				&& $unique_slug_restored
				&& $rewrite_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored,
			'page parent slug route dispatch restores caps, permalink, uniqueness, rewrite, server, actions, and current user',
			array(
				'writeFilterRestored'     => $write_filter_restored,
				'permalinkFilterRestored' => $permalink_filter_restored,
				'uniqueSlugRestored'      => $unique_slug_restored,
				'rewriteRestored'         => $rewrite_restored,
				'serverRestored'          => $server_restored,
				'actionsRestored'         => $actions_restored,
				'currentUserRestored'     => $current_user_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.route-dispatched-page-parent-slug-cleanup',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_route_dispatched_custom_hierarchical_post_type_permalink_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$observed = array();
		$post_ids = array();

		$token                    = substr( $case['token'], 0, 8 );
		$post_type                = 'cfh_' . $token;
		$rest_base                = 'custom-hier-' . $token;
		$rewrite_slug             = 'rest-custom-hier-' . $token;
		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;
		$previous_rewrite_set     = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite         = $GLOBALS['wp_rewrite'] ?? null;
		$had_post_type            = isset( $GLOBALS['wp_post_types'][ $post_type ] );
		$previous_post_type       = $GLOBALS['wp_post_types'][ $post_type ] ?? null;
		$previous_query_vars      = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' )
			? $GLOBALS['wp']->public_query_vars
			: null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$permalink_structure       = '/%postname%/';
		$permalink_filter          = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$cap_filter                = null;
		$active_unique_label       = null;
		$unique_slug_events        = array();
		$write_filter_restored     = false;
		$permalink_filter_restored = false;
		$unique_slug_restored      = false;
		$rewrite_restored          = false;
		$server_restored           = false;
		$actions_restored          = false;
		$current_user_restored     = false;
		$post_type_restored        = false;
		$query_vars_restored       = false;
		$counts_before             = self::content_counts();
		$counts_after_cleanup      = array();

		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$delete_posts = static function () use ( &$post_ids ): void {
			foreach ( array_reverse( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}

			$post_ids = array();
		};
		$post_summary = static function ( $post ): ?array {
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}

			return array(
				'id'     => (int) $post->ID,
				'parent' => (int) $post->post_parent,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
				'title'  => $post->post_title,
				'type'   => $post->post_type,
			);
		};
		$events_for_label = static function ( string $label ) use ( &$unique_slug_events ): array {
			return array_values(
				array_filter(
					$unique_slug_events,
					static function ( array $event ) use ( $label ): bool {
						return $label === ( $event['label'] ?? null );
					}
				)
			);
		};
		$event_matches = static function ( array $events, string $slug, int $post_id, int $parent ) use ( $post_type ): bool {
			if ( 1 !== count( $events ) ) {
				return false;
			}

			$event = $events[0];
			return $slug === ( $event['slug'] ?? null )
				&& $post_id === (int) ( $event['postId'] ?? -1 )
				&& 'publish' === ( $event['status'] ?? null )
				&& $post_type === ( $event['type'] ?? null )
				&& $parent === (int) ( $event['parent'] ?? -1 )
				&& null === ( $event['override'] ?? null );
		};
		$unique_slug_filter = static function ( $override_slug, string $slug, int $post_id, string $post_status, string $event_post_type, int $post_parent ) use ( &$active_unique_label, &$unique_slug_events ) {
			if ( null !== $active_unique_label ) {
				$unique_slug_events[] = array(
					'label'    => $active_unique_label,
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

		try {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			$registration = \register_post_type(
				$post_type,
				array(
					'capability_type'       => 'page',
					'hierarchical'          => true,
					'map_meta_cap'          => true,
					'public'                => true,
					'query_var'             => false,
					'rest_base'             => $rest_base,
					'rest_controller_class' => 'WP_REST_Posts_Controller',
					'rewrite'               => array(
						'slug'       => $rewrite_slug,
						'with_front' => false,
					),
					'show_in_rest'          => true,
					'show_ui'               => true,
					'supports'              => array( 'title', 'editor', 'author', 'page-attributes' ),
				)
			);
			$post_type_object = \get_post_type_object( $post_type );
			$controller       = $post_type_object instanceof \WP_Post_Type ? new \WP_REST_Posts_Controller( $post_type ) : null;
			if ( $controller instanceof \WP_REST_Posts_Controller ) {
				$controller->register_routes();
			}

			$routes = $server->get_routes();
			self::collect_failure(
				$failures,
				$registration instanceof \WP_Post_Type
					&& $post_type_object instanceof \WP_Post_Type
					&& $controller instanceof \WP_REST_Posts_Controller
					&& true === $post_type_object->hierarchical
					&& true === $post_type_object->show_in_rest
					&& $rest_base === $post_type_object->rest_base
					&& $rewrite_slug === ( $post_type_object->rewrite['slug'] ?? null )
					&& isset( $routes[ '/wp/v2/' . $rest_base ], $routes[ '/wp/v2/' . $rest_base . '/(?P<id>[\\d]+)' ] ),
				'custom hierarchical REST fixture post type registers REST routes and rewrite support',
				array(
					'postType'     => $post_type,
					'restBase'     => $rest_base,
					'rewriteSlug'  => $rewrite_slug,
					'registration' => $registration instanceof \WP_Post_Type ? $registration->name : self::describe_value( $registration ),
					'routes'       => array_keys( $routes ),
				)
			);

			if ( $controller instanceof \WP_REST_Posts_Controller ) {
				$parent_a_slug       = 'rest-custom-parent-a-' . $token;
				$parent_b_slug       = 'rest-custom-parent-b-' . $token;
				$base_slug           = 'rest-custom-child-' . $token;
				$base_title          = 'Rest Custom Child ' . $token;
				$base_generated_slug = \sanitize_title( $base_title );
				$root_slug           = 'rest-custom-root-' . $token;
				$expected_template   = \home_url( '/' . $rewrite_slug . '/' . $parent_a_slug . '/%pagename%' );

				$parent_a_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Parent A ' . $token,
								'post_content' => 'REST custom parent A ' . $token,
								'post_status'  => 'publish',
								'post_name'    => $parent_a_slug,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$parent_b_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Parent B ' . $token,
								'post_content' => 'REST custom parent B ' . $token,
								'post_status'  => 'publish',
								'post_name'    => $parent_b_slug,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$parent_a_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Parent A Holder ' . $token,
								'post_content' => 'REST custom parent A holder ' . $token,
								'post_status'  => 'publish',
								'post_name'    => $base_slug,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $parent_a_id,
							)
						),
						true,
						false
					)
				);
				$parent_a_suffix_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Parent A Suffix Holder ' . $token,
								'post_content' => 'REST custom parent A suffix holder ' . $token,
								'post_status'  => 'publish',
								'post_name'    => $base_slug . '-2',
								'post_author'  => $fixtures['author'],
								'post_parent'  => $parent_a_id,
							)
						),
						true,
						false
					)
				);
				$parent_b_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Parent B Holder ' . $token,
								'post_content' => 'REST custom parent B holder ' . $token,
								'post_status'  => 'publish',
								'post_name'    => $base_slug,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $parent_b_id,
							)
						),
						true,
						false
					)
				);
				$root_holder_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Root Holder ' . $token,
								'post_content' => 'REST custom root holder ' . $token,
								'post_status'  => 'publish',
								'post_name'    => $root_slug,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$draft_update_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Omitted Parent Target ' . $token,
								'post_content' => 'REST custom omitted parent target ' . $token,
								'post_status'  => 'draft',
								'post_name'    => 'rest-custom-omitted-parent-target-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $parent_a_id,
							)
						),
						true,
						false
					)
				);
				$pending_update_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Explicit Parent Target ' . $token,
								'post_content' => 'REST custom explicit parent target ' . $token,
								'post_status'  => 'pending',
								'post_name'    => 'rest-custom-explicit-parent-target-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $parent_b_id,
							)
						),
						true,
						false
					)
				);
				$root_update_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Custom Explicit Root Target ' . $token,
								'post_content' => 'REST custom explicit root target ' . $token,
								'post_status'  => 'draft',
								'post_name'    => 'rest-custom-explicit-root-target-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $parent_a_id,
							)
						),
						true,
						false
					)
				);

				\wp_set_current_user( $fixtures['author'] );
				$cap_filter = self::install_cap_filter(
					array(
						'delete_others_pages',
						'delete_pages',
						'delete_post',
						'edit_others_pages',
						'edit_page',
						'edit_pages',
						'edit_post',
						'edit_posts',
						'edit_private_pages',
						'edit_published_pages',
						'publish_pages',
						'read',
						'read_page',
						'read_post',
					)
				);
				\add_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10, 6 );

				$active_unique_label = 'custom-draft-create-parent-a';
				try {
					$draft_create_response = $server->dispatch(
						self::request(
							'POST',
							'/wp/v2/' . $rest_base,
							array(
								'_fields' => 'id,parent,slug,status,title',
								'context' => 'edit',
							),
							array(),
							array(
								'content' => '<p>REST custom draft parent slug content ' . $token . '</p>',
								'parent'  => $parent_a_id,
								'slug'    => $base_slug,
								'status'  => 'draft',
								'title'   => $base_title,
							)
						)
					);
				} finally {
					$active_unique_label = null;
				}
				$draft_create_data = $draft_create_response instanceof \WP_REST_Response ? $draft_create_response->get_data() : array();
				$draft_create_id   = (int) ( $draft_create_data['id'] ?? 0 );
				if ( $draft_create_id > 0 ) {
					$post_ids[] = $draft_create_id;
				}
				$draft_create_post = $draft_create_id > 0 ? \get_post( $draft_create_id ) : null;

				$active_unique_label = 'custom-pending-create-parent-b';
				try {
					$pending_create_response = $server->dispatch(
						self::request(
							'POST',
							'/wp/v2/' . $rest_base,
							array(
								'_fields' => 'id,parent,slug,status,title',
								'context' => 'edit',
							),
							array(),
							array(
								'content' => '<p>REST custom pending parent slug content ' . $token . '</p>',
								'parent'  => $parent_b_id,
								'slug'    => $base_slug,
								'status'  => 'pending',
								'title'   => $base_title,
							)
						)
					);
				} finally {
					$active_unique_label = null;
				}
				$pending_create_data = $pending_create_response instanceof \WP_REST_Response ? $pending_create_response->get_data() : array();
				$pending_create_id   = (int) ( $pending_create_data['id'] ?? 0 );
				if ( $pending_create_id > 0 ) {
					$post_ids[] = $pending_create_id;
				}
				$pending_create_post = $pending_create_id > 0 ? \get_post( $pending_create_id ) : null;

				$active_unique_label = 'custom-draft-update-omitted-parent';
				try {
					$draft_update_response = $server->dispatch(
						self::request(
							'PUT',
							'/wp/v2/' . $rest_base . '/' . $draft_update_id,
							array(
								'_fields' => 'id,parent,slug,status,title',
								'context' => 'edit',
							),
							array(),
							array(
								'slug'   => $base_slug,
								'status' => 'draft',
								'title'  => $base_title,
							)
						)
					);
				} finally {
					$active_unique_label = null;
				}
				$draft_update_data = $draft_update_response instanceof \WP_REST_Response ? $draft_update_response->get_data() : array();
				$draft_update_post = $draft_update_id > 0 ? \get_post( $draft_update_id ) : null;

				$active_unique_label = 'custom-pending-update-explicit-parent';
				try {
					$pending_update_response = $server->dispatch(
						self::request(
							'PUT',
							'/wp/v2/' . $rest_base . '/' . $pending_update_id,
							array(
								'_fields' => 'id,parent,slug,status,title',
								'context' => 'edit',
							),
							array(),
							array(
								'parent' => $parent_b_id,
								'slug'   => $base_slug,
								'status' => 'pending',
								'title'  => $base_title,
							)
						)
					);
				} finally {
					$active_unique_label = null;
				}
				$pending_update_data = $pending_update_response instanceof \WP_REST_Response ? $pending_update_response->get_data() : array();
				$pending_update_post = $pending_update_id > 0 ? \get_post( $pending_update_id ) : null;

				$active_unique_label = 'custom-draft-update-explicit-root';
				try {
					$root_update_response = $server->dispatch(
						self::request(
							'PUT',
							'/wp/v2/' . $rest_base . '/' . $root_update_id,
							array(
								'_fields' => 'id,parent,slug,status,title',
								'context' => 'edit',
							),
							array(),
							array(
								'parent' => 0,
								'slug'   => $root_slug,
								'status' => 'draft',
								'title'  => 'Rest Custom Root ' . $token,
							)
						)
					);
				} finally {
					$active_unique_label = null;
				}
				$root_update_data = $root_update_response instanceof \WP_REST_Response ? $root_update_response->get_data() : array();
				$root_update_post = $root_update_id > 0 ? \get_post( $root_update_id ) : null;

				\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
				$unique_slug_restored = false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter );

				$edit_get_response = $server->dispatch(
					self::request(
						'GET',
						'/wp/v2/' . $rest_base . '/' . $draft_update_id,
						array(
							'_fields' => 'generated_slug,permalink_template,parent,slug,status',
							'context' => 'edit',
						)
					)
				);
				$edit_get_data = $edit_get_response instanceof \WP_REST_Response ? $edit_get_response->get_data() : array();

				$draft_create_events          = $events_for_label( 'custom-draft-create-parent-a' );
				$pending_create_events        = $events_for_label( 'custom-pending-create-parent-b' );
				$draft_update_events          = $events_for_label( 'custom-draft-update-omitted-parent' );
				$pending_update_events        = $events_for_label( 'custom-pending-update-explicit-parent' );
				$root_update_events           = $events_for_label( 'custom-draft-update-explicit-root' );
				$parent_a_holder_after        = $parent_a_holder_id > 0 ? \get_post( $parent_a_holder_id ) : null;
				$parent_a_suffix_holder_after = $parent_a_suffix_holder_id > 0 ? \get_post( $parent_a_suffix_holder_id ) : null;
				$parent_b_holder_after        = $parent_b_holder_id > 0 ? \get_post( $parent_b_holder_id ) : null;
				$root_holder_after            = $root_holder_id > 0 ? \get_post( $root_holder_id ) : null;

				$observed = array(
					'fixtures' => array(
						'parentA'             => $post_summary( $parent_a_id > 0 ? \get_post( $parent_a_id ) : null ),
						'parentB'             => $post_summary( $parent_b_id > 0 ? \get_post( $parent_b_id ) : null ),
						'parentAHolder'       => $post_summary( $parent_a_holder_after ),
						'parentASuffixHolder' => $post_summary( $parent_a_suffix_holder_after ),
						'parentBHolder'       => $post_summary( $parent_b_holder_after ),
						'rootHolder'          => $post_summary( $root_holder_after ),
					),
					'responses' => array(
						'draftCreate'   => array(
							'status' => $draft_create_response instanceof \WP_REST_Response ? $draft_create_response->get_status() : null,
							'data'   => $draft_create_data,
							'stored' => $post_summary( $draft_create_post ),
						),
						'pendingCreate' => array(
							'status' => $pending_create_response instanceof \WP_REST_Response ? $pending_create_response->get_status() : null,
							'data'   => $pending_create_data,
							'stored' => $post_summary( $pending_create_post ),
						),
						'draftUpdate'   => array(
							'status' => $draft_update_response instanceof \WP_REST_Response ? $draft_update_response->get_status() : null,
							'data'   => $draft_update_data,
							'stored' => $post_summary( $draft_update_post ),
						),
						'pendingUpdate' => array(
							'status' => $pending_update_response instanceof \WP_REST_Response ? $pending_update_response->get_status() : null,
							'data'   => $pending_update_data,
							'stored' => $post_summary( $pending_update_post ),
						),
						'rootUpdate'    => array(
							'status' => $root_update_response instanceof \WP_REST_Response ? $root_update_response->get_status() : null,
							'data'   => $root_update_data,
							'stored' => $post_summary( $root_update_post ),
						),
						'editGet'       => array(
							'status' => $edit_get_response instanceof \WP_REST_Response ? $edit_get_response->get_status() : null,
							'data'   => $edit_get_data,
						),
					),
					'unique'   => array(
						'all'                   => $unique_slug_events,
						'draftCreate'           => $draft_create_events,
						'pendingCreate'         => $pending_create_events,
						'draftUpdateOmitted'    => $draft_update_events,
						'pendingUpdateExplicit' => $pending_update_events,
						'rootUpdateExplicit'    => $root_update_events,
					),
					'expected' => array(
						'baseSlug'          => $base_slug,
						'rootSlug'          => $root_slug,
						'parentTemplate'    => $expected_template,
						'editGeneratedSlug' => $base_generated_slug,
						'draftCreateSlug'   => $base_slug . '-3',
						'pendingCreateSlug' => $base_slug . '-2',
						'draftUpdateSlug'   => $base_slug . '-4',
						'pendingUpdateSlug' => $base_slug . '-3',
						'rootUpdateSlug'    => $root_slug . '-2',
					),
				);

				self::collect_failure(
					$failures,
					$parent_a_id > 0
						&& $parent_b_id > 0
						&& $parent_a_holder_id > 0
						&& $parent_a_suffix_holder_id > 0
						&& $parent_b_holder_id > 0
						&& $root_holder_id > 0
						&& $draft_update_id > 0
						&& $pending_update_id > 0
						&& $root_update_id > 0
						&& $parent_a_holder_after instanceof \WP_Post
						&& $post_type === $parent_a_holder_after->post_type
						&& $base_slug === $parent_a_holder_after->post_name
						&& $parent_a_id === (int) $parent_a_holder_after->post_parent
						&& $parent_a_suffix_holder_after instanceof \WP_Post
						&& $post_type === $parent_a_suffix_holder_after->post_type
						&& $base_slug . '-2' === $parent_a_suffix_holder_after->post_name
						&& $parent_a_id === (int) $parent_a_suffix_holder_after->post_parent
						&& $parent_b_holder_after instanceof \WP_Post
						&& $post_type === $parent_b_holder_after->post_type
						&& $base_slug === $parent_b_holder_after->post_name
						&& $parent_b_id === (int) $parent_b_holder_after->post_parent
						&& $root_holder_after instanceof \WP_Post
						&& $post_type === $root_holder_after->post_type
						&& $root_slug === $root_holder_after->post_name
						&& 0 === (int) $root_holder_after->post_parent,
					'custom hierarchical parent slug fixtures create separate parent and root collision scopes',
					$observed['fixtures']
				);

				self::collect_failure(
					$failures,
					$unique_slug_restored
						&& 5 === count( $unique_slug_events )
						&& $event_matches( $draft_create_events, $base_slug, 0, $parent_a_id )
						&& $event_matches( $pending_create_events, $base_slug, 0, $parent_b_id )
						&& $event_matches( $draft_update_events, $base_slug, $draft_update_id, $parent_a_id )
						&& $event_matches( $pending_update_events, $base_slug, $pending_update_id, $parent_b_id )
						&& $event_matches( $root_update_events, $root_slug, $root_update_id, 0 ),
					'custom hierarchical draft/pending route dispatch passes publish-status uniqueness the correct post type and parent scope',
					$observed['unique']
				);

				self::collect_failure(
					$failures,
					$draft_create_response instanceof \WP_REST_Response
						&& 201 === $draft_create_response->get_status()
						&& self::projected_keys_match( $draft_create_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
						&& $base_slug . '-3' === ( $draft_create_data['slug'] ?? null )
						&& 'draft' === ( $draft_create_data['status'] ?? null )
						&& $parent_a_id === (int) ( $draft_create_data['parent'] ?? 0 )
						&& $draft_create_post instanceof \WP_Post
						&& $post_type === $draft_create_post->post_type
						&& $base_slug . '-3' === $draft_create_post->post_name
						&& 'draft' === $draft_create_post->post_status
						&& $parent_a_id === (int) $draft_create_post->post_parent
						&& $pending_create_response instanceof \WP_REST_Response
						&& 201 === $pending_create_response->get_status()
						&& self::projected_keys_match( $pending_create_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
						&& $base_slug . '-2' === ( $pending_create_data['slug'] ?? null )
						&& 'pending' === ( $pending_create_data['status'] ?? null )
						&& $parent_b_id === (int) ( $pending_create_data['parent'] ?? 0 )
						&& $pending_create_post instanceof \WP_Post
						&& $post_type === $pending_create_post->post_type
						&& $base_slug . '-2' === $pending_create_post->post_name
						&& 'pending' === $pending_create_post->post_status
						&& $parent_b_id === (int) $pending_create_post->post_parent,
					'route-dispatched custom hierarchical draft and pending creates use parent-scoped slug collisions before insert',
					array(
						'draftCreate'   => $observed['responses']['draftCreate'],
						'pendingCreate' => $observed['responses']['pendingCreate'],
					)
				);

				self::collect_failure(
					$failures,
					$draft_update_response instanceof \WP_REST_Response
						&& 200 === $draft_update_response->get_status()
						&& self::projected_keys_match( $draft_update_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
						&& $base_slug . '-4' === ( $draft_update_data['slug'] ?? null )
						&& 'draft' === ( $draft_update_data['status'] ?? null )
						&& $parent_a_id === (int) ( $draft_update_data['parent'] ?? 0 )
						&& $draft_update_post instanceof \WP_Post
						&& $post_type === $draft_update_post->post_type
						&& $base_slug . '-4' === $draft_update_post->post_name
						&& 'draft' === $draft_update_post->post_status
						&& $parent_a_id === (int) $draft_update_post->post_parent
						&& $pending_update_response instanceof \WP_REST_Response
						&& 200 === $pending_update_response->get_status()
						&& self::projected_keys_match( $pending_update_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
						&& $base_slug . '-3' === ( $pending_update_data['slug'] ?? null )
						&& 'pending' === ( $pending_update_data['status'] ?? null )
						&& $parent_b_id === (int) ( $pending_update_data['parent'] ?? 0 )
						&& $pending_update_post instanceof \WP_Post
						&& $post_type === $pending_update_post->post_type
						&& $base_slug . '-3' === $pending_update_post->post_name
						&& 'pending' === $pending_update_post->post_status
						&& $parent_b_id === (int) $pending_update_post->post_parent
						&& $root_update_response instanceof \WP_REST_Response
						&& 200 === $root_update_response->get_status()
						&& self::projected_keys_match( $root_update_data, array( 'id', 'parent', 'slug', 'status', 'title' ) )
						&& $root_slug . '-2' === ( $root_update_data['slug'] ?? null )
						&& 'draft' === ( $root_update_data['status'] ?? null )
						&& 0 === (int) ( $root_update_data['parent'] ?? -1 )
						&& $root_update_post instanceof \WP_Post
						&& $post_type === $root_update_post->post_type
						&& $root_slug . '-2' === $root_update_post->post_name
						&& 'draft' === $root_update_post->post_status
						&& 0 === (int) $root_update_post->post_parent,
					'route-dispatched custom hierarchical draft and pending updates distinguish omitted parent, explicit parent, and explicit root slug scopes',
					array(
						'draftUpdate'   => $observed['responses']['draftUpdate'],
						'pendingUpdate' => $observed['responses']['pendingUpdate'],
						'rootUpdate'    => $observed['responses']['rootUpdate'],
					)
				);

				self::collect_failure(
					$failures,
					$edit_get_response instanceof \WP_REST_Response
						&& 200 === $edit_get_response->get_status()
						&& self::projected_keys_match( $edit_get_data, array( 'generated_slug', 'id', 'parent', 'permalink_template', 'slug', 'status' ) )
						&& $draft_update_id === (int) ( $edit_get_data['id'] ?? 0 )
						&& $base_slug . '-4' === ( $edit_get_data['slug'] ?? null )
						&& $base_generated_slug === ( $edit_get_data['generated_slug'] ?? null )
						&& $parent_a_id === (int) ( $edit_get_data['parent'] ?? 0 )
						&& 'draft' === ( $edit_get_data['status'] ?? null )
						&& $expected_template === ( $edit_get_data['permalink_template'] ?? null ),
					'route-dispatched custom hierarchical edit response exposes parent-aware generated_slug and permalink_template',
					$observed['responses']['editGet']
				);

				$delete_posts();
				$counts_after_cleanup = self::content_counts();
				$observed['counts']   = array(
					'before' => $counts_before,
					'after'  => $counts_after_cleanup,
				);

				self::collect_failure(
					$failures,
					self::content_count_delta_matches( $counts_before, $counts_after_cleanup, array(), array() ),
					'custom hierarchical route dispatch cleans up all generated post fixtures',
					$observed['counts']
				);
			}
		} finally {
			if ( false !== \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter ) ) {
				\remove_filter( 'pre_wp_unique_post_slug', $unique_slug_filter, 10 );
			}
			$unique_slug_restored = false === \has_filter( 'pre_wp_unique_post_slug', $unique_slug_filter );

			$delete_posts();

			if ( null !== $cap_filter ) {
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter            = null;
			} else {
				$write_filter_restored = true;
			}

			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			$permalink_filter_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter );

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

			\wp_set_current_user( $previous_current_user_id );

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

			$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
				&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
			$post_type_restored = $had_post_type === isset( $GLOBALS['wp_post_types'][ $post_type ] )
				&& ( ! $had_post_type || $previous_post_type === $GLOBALS['wp_post_types'][ $post_type ] );
			$query_vars_restored = null === $previous_query_vars
				|| ! ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' ) )
				|| $previous_query_vars === $GLOBALS['wp']->public_query_vars;
		}

		self::collect_failure(
			$failures,
			$write_filter_restored
				&& $permalink_filter_restored
				&& $unique_slug_restored
				&& $rewrite_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored
				&& $post_type_restored
				&& $query_vars_restored,
			'custom hierarchical route dispatch restores caps, permalink, uniqueness, rewrite, server, actions, current user, post type, and query vars',
			array(
				'writeFilterRestored'     => $write_filter_restored,
				'permalinkFilterRestored' => $permalink_filter_restored,
				'uniqueSlugRestored'      => $unique_slug_restored,
				'rewriteRestored'         => $rewrite_restored,
				'serverRestored'          => $server_restored,
				'actionsRestored'         => $actions_restored,
				'currentUserRestored'     => $current_user_restored,
				'postTypeRestored'        => $post_type_restored,
				'queryVarsRestored'       => $query_vars_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.route-dispatched-custom-hierarchical-permalink-cleanup',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postType' => $post_type,
				'restBase' => $rest_base,
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_route_dispatched_custom_hierarchical_parent_assignment_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures       = array();
		$observed       = array();
		$post_ids       = array();
		$prepare_events = array();
		$hierarchy_events = array();
		$status_events  = array();
		$status_filter_events = array();
		$publish_split_events = array();
		$delete_events  = array();
		$untrash_events = array();

		$token                    = substr( $case['token'], 0, 8 );
		$post_type                = 'cpa_' . $token;
		$rest_base                = 'custom-parent-assign-' . $token;
		$query_var                = 'cpaqv_' . $token;
		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;
		$previous_rewrite_set     = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite         = $GLOBALS['wp_rewrite'] ?? null;
		$previous_wp_set          = array_key_exists( 'wp', $GLOBALS );
		$previous_wp              = $GLOBALS['wp'] ?? null;
		$had_post_type            = isset( $GLOBALS['wp_post_types'][ $post_type ] );
		$previous_post_type       = $GLOBALS['wp_post_types'][ $post_type ] ?? null;
		$previous_query_vars      = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' )
			? $GLOBALS['wp']->public_query_vars
			: null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$filter_snapshot          = self::rest_default_filter_state();
		$cap_filter               = null;
		$hierarchy_before_filter  = null;
		$hierarchy_after_filter   = null;
		$status_transition_filter = null;
		$status_pre_insert_filter = null;
		$status_insert_action     = null;
		$untrash_pre_filter       = null;
		$untrash_post_action      = null;
		$untrash_status_filter    = null;
		$untrash_transition_filter = null;
		$untrashed_post_action    = null;
		$delete_filter            = null;
		$publish_split_cap_filter = null;
		$added_hierarchy_loop_filter = false;
		$custom_filters_restored  = false;
		$cap_filter_restored      = false;
		$hierarchy_filters_restored = false;
		$hierarchy_loop_filter_restored = false;
		$status_filter_restored   = false;
		$untrash_filter_restored  = false;
		$delete_filter_restored   = false;
		$publish_split_filter_restored = false;
		$default_filters_restored = false;
		$rewrite_restored         = false;
		$server_restored          = false;
		$actions_restored         = false;
		$current_user_restored    = false;
		$post_type_restored       = false;
		$query_vars_restored      = false;
		$counts_before            = self::content_counts();
		$counts_after_cleanup     = array();

		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$delete_posts  = static function () use ( &$post_ids ): void {
			foreach ( array_reverse( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}

			$post_ids = array();
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
		$post_summary = static function ( $post ): ?array {
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}

			return array(
				'id'     => (int) $post->ID,
				'parent' => (int) $post->post_parent,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
				'title'  => $post->post_title,
				'type'   => $post->post_type,
			);
		};
		$prepare_filter = static function ( $response, $post, \WP_REST_Request $request ) use ( &$prepare_events, $post_type ) {
			if ( $response instanceof \WP_REST_Response && $post instanceof \WP_Post && $post_type === $post->post_type ) {
				$prepare_events[] = self::prepare_event( $post_type, (int) $post->ID, $request );
			}
			return $response;
		};
		$delete_filter = static function ( \WP_Post $post, \WP_REST_Response $response, \WP_REST_Request $request ) use ( &$delete_events, $post_type ): void {
			if ( $post_type !== $post->post_type ) {
				return;
			}

			$data            = $response->get_data();
			$delete_events[] = array(
				'id'      => (int) $post->ID,
				'status'  => $post->post_status,
				'parent'  => (int) $post->post_parent,
				'method'  => $request->get_method(),
				'force'   => (bool) $request['force'],
				'route'   => $request->get_route(),
				'deleted' => $data['deleted'] ?? false,
			);
		};

		try {
			if ( ! isset( $GLOBALS['wp'] ) || ! $GLOBALS['wp'] instanceof \WP ) {
				$GLOBALS['wp'] = new \WP();
			}
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = '/%postname%/';

			$registration = \register_post_type(
				$post_type,
				array(
					'capability_type'       => 'page',
					'hierarchical'          => true,
					'map_meta_cap'          => true,
					'public'                => true,
					'query_var'             => $query_var,
					'rest_base'             => $rest_base,
					'rest_controller_class' => 'WP_REST_Posts_Controller',
					'rewrite'               => false,
					'show_in_rest'          => true,
					'show_ui'               => true,
					'supports'              => array( 'title', 'editor', 'author', 'page-attributes' ),
				)
			);
			$post_type_object = \get_post_type_object( $post_type );
			$controller       = $post_type_object instanceof \WP_Post_Type ? new \WP_REST_Posts_Controller( $post_type ) : null;
			if ( $controller instanceof \WP_REST_Posts_Controller ) {
				$controller->register_routes();
			}
			\rest_api_default_filters();
			\add_filter( 'rest_prepare_' . $post_type, $prepare_filter, 10, 3 );
			\add_action( 'rest_delete_' . $post_type, $delete_filter, 10, 3 );

			$routes = $server->get_routes();
			self::collect_failure(
				$failures,
				$registration instanceof \WP_Post_Type
					&& $post_type_object instanceof \WP_Post_Type
					&& $controller instanceof \WP_REST_Posts_Controller
					&& true === $post_type_object->hierarchical
					&& true === $post_type_object->show_in_rest
					&& $rest_base === $post_type_object->rest_base
					&& false === $post_type_object->rewrite
					&& $query_var === $post_type_object->query_var
					&& isset( $routes[ '/wp/v2/' . $rest_base ], $routes[ '/wp/v2/' . $rest_base . '/(?P<id>[\\d]+)' ] ),
				'custom hierarchical parent-assignment fixture post type registers REST routes without rewrite support',
				array(
					'postType'     => $post_type,
					'restBase'     => $rest_base,
					'registration' => $registration instanceof \WP_Post_Type ? $registration->name : self::describe_value( $registration ),
					'routes'       => array_keys( $routes ),
				)
			);

			if ( $controller instanceof \WP_REST_Posts_Controller ) {
				$same_parent_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Same Type ' . $token,
								'post_content' => 'REST parent same type ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-parent-same-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$page_parent_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => 'page',
								'post_title'   => 'REST Parent Page ' . $token,
								'post_content' => 'REST parent page ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-parent-page-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$update_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Update Child ' . $token,
								'post_content' => 'REST parent update child ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-parent-update-child-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $same_parent_id,
							)
						),
						true,
						false
					)
				);
				$status_update_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Status Update ' . $token,
								'post_content' => 'REST parent status update ' . $token,
								'post_status'  => 'draft',
								'post_name'    => 'rest-parent-status-update-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $same_parent_id,
							)
						),
						true,
						false
					)
				);
				$filtered_status_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Filtered Status ' . $token,
								'post_content' => 'REST parent filtered status ' . $token,
								'post_status'  => 'draft',
								'post_name'    => 'rest-parent-filtered-status-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $same_parent_id,
							)
						),
						true,
						false
					)
				);
				$publish_split_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Publish Split ' . $token,
								'post_content' => 'REST parent publish split ' . $token,
								'post_status'  => 'draft',
								'post_name'    => 'rest-parent-publish-split-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $same_parent_id,
							)
						),
						true,
						false
					)
				);
				$trash_lifecycle_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Trash Lifecycle ' . $token,
								'post_content' => 'REST parent trash lifecycle ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-parent-trash-lifecycle-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $same_parent_id,
							)
						),
						true,
						false
					)
				);
				$delete_lifecycle_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Delete Lifecycle ' . $token,
								'post_content' => 'REST parent delete lifecycle ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-parent-delete-lifecycle-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $same_parent_id,
							)
						),
						true,
						false
					)
				);
				$loop_parent_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Loop Parent ' . $token,
								'post_content' => 'REST parent loop parent ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-loop-parent-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$loop_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Loop Child ' . $token,
								'post_content' => 'REST parent loop child ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-loop-child-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $loop_parent_id,
							)
						),
						true,
						false
					)
				);
				$loop_grandchild_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Loop Grandchild ' . $token,
								'post_content' => 'REST parent loop grandchild ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-loop-grandchild-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $loop_child_id,
							)
						),
						true,
						false
					)
				);
				$unrelated_loop_a_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Unrelated Loop A ' . $token,
								'post_content' => 'REST parent unrelated loop A ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-unrelated-loop-a-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$unrelated_loop_b_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Unrelated Loop B ' . $token,
								'post_content' => 'REST parent unrelated loop B ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-unrelated-loop-b-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$unrelated_update_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Unrelated Loop Update ' . $token,
								'post_content' => 'REST parent unrelated loop update ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-unrelated-loop-update-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => 0,
							)
						),
						true,
						false
					)
				);
				$denied_update_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'post_type'    => $post_type,
								'post_title'   => 'REST Parent Denied Update ' . $token,
								'post_content' => 'REST parent denied update ' . $token,
								'post_status'  => 'publish',
								'post_name'    => 'rest-parent-denied-update-' . $token,
								'post_author'  => $fixtures['author'],
								'post_parent'  => $same_parent_id,
							)
						),
						true,
						false
					)
				);
				$force_parent( $unrelated_loop_a_id, $unrelated_loop_b_id );
				$force_parent( $unrelated_loop_b_id, $unrelated_loop_a_id );

				\wp_set_current_user( $fixtures['author'] );
				$parent_assignment_caps = array(
					'delete_others_pages',
					'delete_page',
					'delete_pages',
					'delete_post',
					'delete_posts',
					'delete_published_pages',
					'edit_others_pages',
					'edit_page',
					'edit_pages',
					'edit_post',
					'edit_posts',
					'edit_private_pages',
					'edit_published_pages',
					'publish_pages',
					'read',
					'read_page',
					'read_post',
				);
				$cap_filter = self::install_cap_filter( $parent_assignment_caps );

				$record_untrash_transition = false;
				$status_transition_filter = static function ( string $new_status, string $old_status, \WP_Post $post ) use ( &$status_events, &$status_filter_events, &$publish_split_events, $post_type, $status_update_id, $filtered_status_id, $publish_split_id ): void {
					if ( $post_type === $post->post_type && $status_update_id === (int) $post->ID ) {
						$status_events[] = array(
							'postId' => (int) $post->ID,
							'type'   => $post->post_type,
							'old'    => $old_status,
							'new'    => $new_status,
						);
					}
					if ( $post_type === $post->post_type && $filtered_status_id === (int) $post->ID ) {
						$status_filter_events[] = array(
							'hook'   => 'transition_post_status',
							'postId' => (int) $post->ID,
							'type'   => $post->post_type,
							'old'    => $old_status,
							'new'    => $new_status,
							'parent' => (int) $post->post_parent,
						);
					}
					if ( $post_type === $post->post_type && $publish_split_id === (int) $post->ID ) {
						$publish_split_events[] = array(
							'hook'   => 'transition_post_status',
							'postId' => (int) $post->ID,
							'type'   => $post->post_type,
							'old'    => $old_status,
							'new'    => $new_status,
							'parent' => (int) $post->post_parent,
						);
					}
				};
				\add_action( 'transition_post_status', $status_transition_filter, 10, 3 );
				$status_pre_insert_filter = static function ( $prepared_post, \WP_REST_Request $request ) use ( &$status_filter_events, $post_type, $filtered_status_id, $page_parent_id ) {
					if ( $filtered_status_id === (int) $request['id'] && is_object( $prepared_post ) && ! \is_wp_error( $prepared_post ) ) {
						$status_filter_events[] = array(
							'hook'           => 'rest_pre_insert_' . $post_type,
							'postId'         => (int) $request['id'],
							'method'         => $request->get_method(),
							'requestedStatus' => $request['status'],
							'preparedStatus' => $prepared_post->post_status ?? null,
							'preparedParent' => (int) ( $prepared_post->post_parent ?? -1 ),
						);
						if ( 'publish' === $request['status'] && $page_parent_id === (int) ( $prepared_post->post_parent ?? 0 ) ) {
							$prepared_post->post_status = 'pending';
						}
					}

					return $prepared_post;
				};
				\add_filter( 'rest_pre_insert_' . $post_type, $status_pre_insert_filter, 10, 2 );
				$status_insert_action = static function ( \WP_Post $post, \WP_REST_Request $request, bool $creating ) use ( &$status_filter_events, $post_type, $filtered_status_id ): void {
					if ( $post_type === $post->post_type && $filtered_status_id === (int) $post->ID ) {
						$status_filter_events[] = array(
							'hook'     => 'rest_insert_' . $post_type,
							'postId'   => (int) $post->ID,
							'method'   => $request->get_method(),
							'creating' => $creating,
							'status'   => $post->post_status,
							'parent'   => (int) $post->post_parent,
						);
					}
				};
				\add_action( 'rest_insert_' . $post_type, $status_insert_action, 10, 3 );
				$untrash_pre_filter = static function ( $check, \WP_Post $post, string $previous_status ) use ( &$untrash_events, $post_type, $trash_lifecycle_id ) {
					if ( $post_type === $post->post_type && $trash_lifecycle_id === (int) $post->ID ) {
						$untrash_events[] = array(
							'hook'           => 'pre_untrash_post',
							'postId'         => (int) $post->ID,
							'type'           => $post->post_type,
							'status'         => $post->post_status,
							'parent'         => (int) $post->post_parent,
							'previousStatus' => $previous_status,
							'check'          => $check,
						);
					}

					return $check;
				};
				\add_filter( 'pre_untrash_post', $untrash_pre_filter, 10, 3 );
				$untrash_post_action = static function ( int $post_id, string $previous_status ) use ( &$untrash_events, $post_type, $trash_lifecycle_id ): void {
					$post = \get_post( $post_id );
					if ( $post instanceof \WP_Post && $post_type === $post->post_type && $trash_lifecycle_id === (int) $post->ID ) {
						$untrash_events[] = array(
							'hook'           => 'untrash_post',
							'postId'         => (int) $post->ID,
							'type'           => $post->post_type,
							'status'         => $post->post_status,
							'parent'         => (int) $post->post_parent,
							'slug'           => $post->post_name,
							'previousStatus' => $previous_status,
						);
					}
				};
				\add_action( 'untrash_post', $untrash_post_action, 10, 2 );
				$untrash_status_filter = static function ( string $new_status, int $post_id, string $previous_status ) use ( &$untrash_events, $trash_lifecycle_id ): string {
					if ( $trash_lifecycle_id === $post_id ) {
						$untrash_events[] = array(
							'hook'           => 'wp_untrash_post_status',
							'postId'         => $post_id,
							'newStatus'      => $new_status,
							'previousStatus' => $previous_status,
						);
					}

					return $new_status;
				};
				\add_filter( 'wp_untrash_post_status', $untrash_status_filter, 10, 3 );
				$untrash_transition_filter = static function ( string $new_status, string $old_status, \WP_Post $post ) use ( &$untrash_events, &$record_untrash_transition, $post_type, $trash_lifecycle_id ): void {
					if ( $record_untrash_transition && $post_type === $post->post_type && $trash_lifecycle_id === (int) $post->ID ) {
						$untrash_events[] = array(
							'hook'   => 'transition_post_status',
							'postId' => (int) $post->ID,
							'type'   => $post->post_type,
							'old'    => $old_status,
							'new'    => $new_status,
							'parent' => (int) $post->post_parent,
							'slug'   => $post->post_name,
						);
					}
				};
				\add_action( 'transition_post_status', $untrash_transition_filter, 10, 3 );
				$untrashed_post_action = static function ( int $post_id, string $previous_status ) use ( &$untrash_events, $post_type, $trash_lifecycle_id ): void {
					$post = \get_post( $post_id );
					if ( $post instanceof \WP_Post && $post_type === $post->post_type && $trash_lifecycle_id === (int) $post->ID ) {
						$untrash_events[] = array(
							'hook'           => 'untrashed_post',
							'postId'         => (int) $post->ID,
							'type'           => $post->post_type,
							'status'         => $post->post_status,
							'parent'         => (int) $post->post_parent,
							'slug'           => $post->post_name,
							'previousStatus' => $previous_status,
						);
					}
				};
				\add_action( 'untrashed_post', $untrashed_post_action, 10, 2 );

				$missing_parent = 987654321;
				$counts_before_invalid_create = self::content_counts();
				$invalid_create_response      = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'POST',
						'/wp/v2/' . $rest_base,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $missing_parent,
							'slug'   => 'rest-parent-invalid-create-' . $token,
							'status' => 'publish',
							'title'  => 'REST Parent Invalid Create ' . $token,
						)
					)
				);
				$counts_after_invalid_create = self::content_counts();

				$same_parent_create_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'POST',
						'/wp/v2/' . $rest_base,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $same_parent_id,
							'slug'   => 'rest-parent-same-child-' . $token,
							'status' => 'publish',
							'title'  => 'REST Parent Same Child ' . $token,
						)
					)
				);
				$same_parent_create_data = $same_parent_create_response instanceof \WP_REST_Response ? $same_parent_create_response->get_data() : array();
				$same_parent_create_id   = (int) ( $same_parent_create_data['id'] ?? 0 );
				if ( $same_parent_create_id > 0 ) {
					$post_ids[] = $same_parent_create_id;
				}

				$cross_type_create_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'POST',
						'/wp/v2/' . $rest_base,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $page_parent_id,
							'slug'   => 'rest-parent-page-child-' . $token,
							'status' => 'publish',
							'title'  => 'REST Parent Page Child ' . $token,
						)
					)
				);
				$cross_type_create_data = $cross_type_create_response instanceof \WP_REST_Response ? $cross_type_create_response->get_data() : array();
				$cross_type_create_id   = (int) ( $cross_type_create_data['id'] ?? 0 );
				if ( $cross_type_create_id > 0 ) {
					$post_ids[] = $cross_type_create_id;
				}

				$cross_type_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $update_child_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $page_parent_id,
							'status' => 'private',
						)
					)
				);
				$cross_type_update_data = $cross_type_update_response instanceof \WP_REST_Response ? $cross_type_update_response->get_data() : array();
				$after_cross_update     = $update_child_id > 0 ? \get_post( $update_child_id ) : null;

				$root_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $update_child_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => 0,
						)
					)
				);
				$root_update_data = $root_update_response instanceof \WP_REST_Response ? $root_update_response->get_data() : array();
				$after_root_update = $update_child_id > 0 ? \get_post( $update_child_id ) : null;

				$invalid_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $update_child_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $missing_parent,
						)
					)
				);
				$after_invalid_update = $update_child_id > 0 ? \get_post( $update_child_id ) : null;

				$status_parent_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $status_update_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $page_parent_id,
							'status' => 'private',
						)
					)
				);
				$status_parent_update_data = $status_parent_update_response instanceof \WP_REST_Response ? $status_parent_update_response->get_data() : array();
				$after_status_parent_update = $status_update_id > 0 ? \get_post( $status_update_id ) : null;

				$filtered_status_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $filtered_status_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $page_parent_id,
							'status' => 'publish',
						)
					)
				);
				$filtered_status_update_data = $filtered_status_update_response instanceof \WP_REST_Response ? $filtered_status_update_response->get_data() : array();
				$after_filtered_status_update = $filtered_status_id > 0 ? \get_post( $filtered_status_id ) : null;

				$filtered_status_collection_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'GET',
						'/wp/v2/' . $rest_base,
						array(
							'_fields'  => 'id,parent,slug,status,link,_links',
							'context'  => 'edit',
							'status'   => array( 'pending' ),
							'include'  => array( $filtered_status_id ),
							'orderby'  => 'include',
							'parent'   => array( $page_parent_id ),
							'per_page' => 1,
						)
					)
				);
				$filtered_status_collection_data = $filtered_status_collection_response instanceof \WP_REST_Response ? $filtered_status_collection_response->get_data() : array();

				$publish_split_broad_filter_removed = false;
				if ( null !== $cap_filter ) {
					$publish_split_broad_filter_removed = self::remove_cap_filter( $cap_filter );
					$cap_filter                         = null;
				}
				$publish_split_allowed_caps = array_fill_keys(
					array(
						'edit_others_pages',
						'edit_page',
						'edit_pages',
						'edit_post',
						'edit_posts',
						'edit_private_pages',
						'edit_published_pages',
						'read',
						'read_page',
						'read_post',
					),
					true
				);
				$publish_split_cap_filter = static function ( array $allcaps, array $caps = array() ) use ( $publish_split_allowed_caps ): array {
					foreach ( $publish_split_allowed_caps as $cap => $grant ) {
						$allcaps[ $cap ] = $grant;
					}
					foreach ( $caps as $cap ) {
						if ( isset( $publish_split_allowed_caps[ $cap ] ) ) {
							$allcaps[ $cap ] = true;
						} elseif ( 'do_not_allow' !== $cap ) {
							$allcaps[ $cap ] = false;
						}
					}
					$allcaps['publish_pages'] = false;

					return $allcaps;
				};
				\add_filter( 'user_has_cap', $publish_split_cap_filter, 10, 4 );
				$before_publish_split_update  = $publish_split_id > 0 ? \get_post( $publish_split_id ) : null;
				$publish_split_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $publish_split_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $page_parent_id,
							'status' => 'publish',
						)
					)
				);
				$after_publish_split_update = $publish_split_id > 0 ? \get_post( $publish_split_id ) : null;
				\remove_filter( 'user_has_cap', $publish_split_cap_filter, 10 );
				$publish_split_filter_restored = false === \has_filter( 'user_has_cap', $publish_split_cap_filter );
				$publish_split_cap_filter      = null;
				$publish_split_prepare_events  = array_values(
					array_filter(
						$prepare_events,
						static fn ( array $event ): bool => $publish_split_id === (int) ( $event['id'] ?? 0 )
					)
				);
				$cap_filter                    = self::install_cap_filter( $parent_assignment_caps );

				$trash_lifecycle_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'DELETE',
						'/wp/v2/' . $rest_base . '/' . $trash_lifecycle_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'force' => false,
						)
					)
				);
				$trash_lifecycle_data = $trash_lifecycle_response instanceof \WP_REST_Response ? $trash_lifecycle_response->get_data() : array();
				$after_trash_lifecycle = $trash_lifecycle_id > 0 ? \get_post( $trash_lifecycle_id ) : null;

				$already_trashed_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'DELETE',
						'/wp/v2/' . $rest_base . '/' . $trash_lifecycle_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'force' => false,
						)
					)
				);
				$after_repeat_trash_lifecycle = $trash_lifecycle_id > 0 ? \get_post( $trash_lifecycle_id ) : null;

				$record_untrash_transition = true;
				$untrash_result = $trash_lifecycle_id > 0 ? \wp_untrash_post( $trash_lifecycle_id ) : false;
				$record_untrash_transition = false;
				$after_untrash = $trash_lifecycle_id > 0 ? \get_post( $trash_lifecycle_id ) : null;
				$untrash_meta_after = array(
					'status'      => $trash_lifecycle_id > 0 ? \get_post_meta( $trash_lifecycle_id, '_wp_trash_meta_status', true ) : null,
					'time'        => $trash_lifecycle_id > 0 ? \get_post_meta( $trash_lifecycle_id, '_wp_trash_meta_time', true ) : null,
					'desiredSlug' => $trash_lifecycle_id > 0 ? \get_post_meta( $trash_lifecycle_id, '_wp_desired_post_slug', true ) : null,
				);

				$force_delete_lifecycle_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'DELETE',
						'/wp/v2/' . $rest_base . '/' . $delete_lifecycle_id,
						array( 'context' => 'edit' ),
						array(),
						array(
							'force' => true,
						)
					)
				);
				$force_delete_lifecycle_data = $force_delete_lifecycle_response instanceof \WP_REST_Response ? $force_delete_lifecycle_response->get_data() : array();
				$after_force_delete_lifecycle = $delete_lifecycle_id > 0 ? \get_post( $delete_lifecycle_id ) : null;

				$untrash_reparent_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $trash_lifecycle_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $page_parent_id,
						)
					)
				);
				$untrash_reparent_data = $untrash_reparent_response instanceof \WP_REST_Response ? $untrash_reparent_response->get_data() : array();
				$after_untrash_reparent = $trash_lifecycle_id > 0 ? \get_post( $trash_lifecycle_id ) : null;

				$untrash_collection_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'GET',
						'/wp/v2/' . $rest_base,
						array(
							'_fields'  => 'id,parent,slug,status,link,_links',
							'context'  => 'edit',
							'status'   => array( 'draft' ),
							'include'  => array( $trash_lifecycle_id ),
							'orderby'  => 'include',
							'parent'   => array( $page_parent_id ),
							'per_page' => 1,
						)
					)
				);
				$untrash_collection_data = $untrash_collection_response instanceof \WP_REST_Response ? $untrash_collection_response->get_data() : array();

				$untrash_collection_head_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'HEAD',
						'/wp/v2/' . $rest_base,
						array(
							'context'        => 'edit',
							'status'         => array( 'draft' ),
							'include'        => array( $trash_lifecycle_id ),
							'orderby'        => 'include',
							'parent_exclude' => array( $same_parent_id ),
							'per_page'       => 1,
						)
					)
				);
				$untrash_collection_head_data = $untrash_collection_head_response instanceof \WP_REST_Response ? $untrash_collection_head_response->get_data() : array();
				$untrash_collection_head_headers = $untrash_collection_head_response instanceof \WP_REST_Response ? $untrash_collection_head_response->get_headers() : array();

				if ( false === \has_filter( 'wp_insert_post_parent', 'wp_check_post_hierarchy_for_loops' ) ) {
					\add_filter( 'wp_insert_post_parent', 'wp_check_post_hierarchy_for_loops', 10, 2 );
					$added_hierarchy_loop_filter = true;
				}
				$hierarchy_before_filter = static function ( int $post_parent, int $post_id, array $new_postarr ) use ( &$hierarchy_events, $post_type ): int {
					if ( $post_type === ( $new_postarr['post_type'] ?? null ) ) {
						$hierarchy_events[] = array(
							'stage'  => 'before',
							'postId' => $post_id,
							'parent' => $post_parent,
							'type'   => $new_postarr['post_type'],
						);
					}
					return $post_parent;
				};
				$hierarchy_after_filter = static function ( int $post_parent, int $post_id, array $new_postarr ) use ( &$hierarchy_events, $post_type ): int {
					if ( $post_type === ( $new_postarr['post_type'] ?? null ) ) {
						$hierarchy_events[] = array(
							'stage'  => 'after',
							'postId' => $post_id,
							'parent' => $post_parent,
							'type'   => $new_postarr['post_type'],
						);
					}
					return $post_parent;
				};
				\add_filter( 'wp_insert_post_parent', $hierarchy_before_filter, 9, 4 );
				\add_filter( 'wp_insert_post_parent', $hierarchy_after_filter, 11, 4 );

				$self_parent_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $update_child_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $update_child_id,
						)
					)
				);
				$self_parent_update_data = $self_parent_update_response instanceof \WP_REST_Response ? $self_parent_update_response->get_data() : array();
				$after_self_parent_update = $update_child_id > 0 ? \get_post( $update_child_id ) : null;

				$descendant_loop_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $loop_parent_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $loop_grandchild_id,
						)
					)
				);
				$descendant_loop_update_data = $descendant_loop_update_response instanceof \WP_REST_Response ? $descendant_loop_update_response->get_data() : array();
				$after_loop_parent           = $loop_parent_id > 0 ? \get_post( $loop_parent_id ) : null;
				$after_loop_child            = $loop_child_id > 0 ? \get_post( $loop_child_id ) : null;
				$after_loop_grandchild       = $loop_grandchild_id > 0 ? \get_post( $loop_grandchild_id ) : null;

				$unrelated_loop_break_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $unrelated_update_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $unrelated_loop_a_id,
						)
					)
				);
				$unrelated_loop_break_update_data = $unrelated_loop_break_update_response instanceof \WP_REST_Response ? $unrelated_loop_break_update_response->get_data() : array();
				$after_unrelated_loop_a           = $unrelated_loop_a_id > 0 ? \get_post( $unrelated_loop_a_id ) : null;
				$after_unrelated_loop_b           = $unrelated_loop_b_id > 0 ? \get_post( $unrelated_loop_b_id ) : null;
				$after_unrelated_update           = $unrelated_update_id > 0 ? \get_post( $unrelated_update_id ) : null;

				$denial_cap_filter_removed = false;
				if ( null !== $cap_filter ) {
					$denial_cap_filter_removed = self::remove_cap_filter( $cap_filter );
					$cap_filter                = null;
				}
				$before_denied_update  = $denied_update_id > 0 ? \get_post( $denied_update_id ) : null;
				$denied_update_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'PUT',
						'/wp/v2/' . $rest_base . '/' . $denied_update_id,
						array(
							'_fields' => 'id,parent,slug,status,link,_links',
							'context' => 'edit',
						),
						array(),
						array(
							'parent' => $page_parent_id,
						)
					)
				);
				$after_denied_update = $denied_update_id > 0 ? \get_post( $denied_update_id ) : null;
				$denied_update_prepare_events = array_values(
					array_filter(
						$prepare_events,
						static fn ( array $event ): bool => $denied_update_id === (int) ( $event['id'] ?? 0 )
					)
				);
				$denied_update_hierarchy_events = array_values(
					array_filter(
						$hierarchy_events,
						static fn ( array $event ): bool => $denied_update_id === (int) ( $event['postId'] ?? 0 )
					)
				);

				$response_keys              = array( 'id', 'link', 'parent', 'slug', 'status' );
				$query_link                 = static function ( string $path ) use ( $query_var ): string {
					return \home_url( '?' . $query_var . '=' . $path );
				};
				$plain_link                 = static function ( string $post_type, int $post_id ): string {
					return \home_url( \add_query_arg( array( 'post_type' => $post_type, 'p' => $post_id ), '' ) );
				};
				$same_parent_create_link    = $query_link( 'rest-parent-same-' . $token . '/rest-parent-same-child-' . $token );
				$cross_type_create_link     = $query_link( 'rest-parent-page-' . $token . '/rest-parent-page-child-' . $token );
				$cross_type_update_link     = $query_link( 'rest-parent-page-' . $token . '/rest-parent-update-child-' . $token );
				$root_update_link           = $query_link( 'rest-parent-update-child-' . $token );
				$status_parent_update_link  = $query_link( 'rest-parent-page-' . $token . '/rest-parent-status-update-' . $token );
				$filtered_status_update_link = $plain_link( $post_type, $filtered_status_id );
				$trash_lifecycle_link       = $plain_link( $post_type, $trash_lifecycle_id );
				$trash_lifecycle_trashed_slug = 'rest-parent-trash-lifecycle-' . $token . '__trashed';
				$force_delete_previous_link = $query_link( 'rest-parent-same-' . $token . '/rest-parent-delete-lifecycle-' . $token );
				$untrash_reparent_link      = $plain_link( $post_type, $trash_lifecycle_id );
				$self_parent_update_link    = $query_link( 'rest-parent-update-child-' . $token );
				$descendant_loop_update_link = $query_link( 'rest-loop-parent-' . $token );
				$unrelated_loop_break_update_link = $query_link( 'rest-unrelated-loop-a-' . $token . '/rest-unrelated-loop-update-' . $token );
				$same_parent_up_link        = \rest_url( \rest_get_route_for_post( $same_parent_id ) );
				$page_parent_up_link        = \rest_url( \rest_get_route_for_post( $page_parent_id ) );
				$unrelated_loop_a_up_link   = \rest_url( \rest_get_route_for_post( $unrelated_loop_a_id ) );
				$same_parent_create_links   = $same_parent_create_response instanceof \WP_REST_Response ? $same_parent_create_response->get_links() : array();
				$cross_type_create_links    = $cross_type_create_response instanceof \WP_REST_Response ? $cross_type_create_response->get_links() : array();
				$cross_type_update_links    = $cross_type_update_response instanceof \WP_REST_Response ? $cross_type_update_response->get_links() : array();
				$root_update_response_links = $root_update_response instanceof \WP_REST_Response ? $root_update_response->get_links() : array();
				$status_parent_update_links = $status_parent_update_response instanceof \WP_REST_Response ? $status_parent_update_response->get_links() : array();
				$filtered_status_update_links = $filtered_status_update_response instanceof \WP_REST_Response ? $filtered_status_update_response->get_links() : array();
				$trash_lifecycle_links      = $trash_lifecycle_response instanceof \WP_REST_Response ? $trash_lifecycle_response->get_links() : array();
				$untrash_reparent_links     = $untrash_reparent_response instanceof \WP_REST_Response ? $untrash_reparent_response->get_links() : array();
				$self_parent_update_links   = $self_parent_update_response instanceof \WP_REST_Response ? $self_parent_update_response->get_links() : array();
				$descendant_loop_update_links = $descendant_loop_update_response instanceof \WP_REST_Response ? $descendant_loop_update_response->get_links() : array();
				$unrelated_loop_break_update_links = $unrelated_loop_break_update_response instanceof \WP_REST_Response ? $unrelated_loop_break_update_response->get_links() : array();

				$observed = array(
					'postType' => $post_type,
					'restBase' => $rest_base,
					'fixtures' => array(
						'sameParent'     => $post_summary( \get_post( $same_parent_id ) ),
						'pageParent'     => $post_summary( \get_post( $page_parent_id ) ),
						'updateChild'    => $post_summary( \get_post( $update_child_id ) ),
						'statusUpdate'   => $post_summary( \get_post( $status_update_id ) ),
						'filteredStatus' => $post_summary( \get_post( $filtered_status_id ) ),
						'publishSplit'   => $post_summary( \get_post( $publish_split_id ) ),
						'trashLifecycle' => $post_summary( \get_post( $trash_lifecycle_id ) ),
						'deleteLifecycle' => $post_summary( \get_post( $delete_lifecycle_id ) ),
						'trashLifecycleAfterUntrash' => $post_summary( $after_untrash ),
						'trashLifecycleAfterReparent' => $post_summary( $after_untrash_reparent ),
						'loopParent'     => $post_summary( \get_post( $loop_parent_id ) ),
						'loopChild'      => $post_summary( \get_post( $loop_child_id ) ),
						'loopGrandchild' => $post_summary( \get_post( $loop_grandchild_id ) ),
						'unrelatedLoopA' => $post_summary( \get_post( $unrelated_loop_a_id ) ),
						'unrelatedLoopB' => $post_summary( \get_post( $unrelated_loop_b_id ) ),
						'unrelatedUpdate' => $post_summary( \get_post( $unrelated_update_id ) ),
						'deniedUpdate'   => $post_summary( \get_post( $denied_update_id ) ),
					),
					'responses' => array(
						'invalidCreate' => $invalid_create_response,
						'sameCreate'    => array(
							'status' => $same_parent_create_response instanceof \WP_REST_Response ? $same_parent_create_response->get_status() : null,
							'data'   => $same_parent_create_data,
							'stored' => $post_summary( $same_parent_create_id > 0 ? \get_post( $same_parent_create_id ) : null ),
						),
						'crossCreate'   => array(
							'status' => $cross_type_create_response instanceof \WP_REST_Response ? $cross_type_create_response->get_status() : null,
							'data'   => $cross_type_create_data,
							'stored' => $post_summary( $cross_type_create_id > 0 ? \get_post( $cross_type_create_id ) : null ),
						),
						'crossUpdate'   => array(
							'status' => $cross_type_update_response instanceof \WP_REST_Response ? $cross_type_update_response->get_status() : null,
							'data'   => $cross_type_update_data,
							'stored' => $post_summary( $after_cross_update ),
						),
						'rootUpdate'    => array(
							'status' => $root_update_response instanceof \WP_REST_Response ? $root_update_response->get_status() : null,
							'data'   => $root_update_data,
							'stored' => $post_summary( $after_root_update ),
						),
						'statusParentUpdate' => array(
							'status'       => $status_parent_update_response instanceof \WP_REST_Response ? $status_parent_update_response->get_status() : null,
							'data'         => $status_parent_update_data,
							'stored'       => $post_summary( $after_status_parent_update ),
							'statusEvents' => $status_events,
						),
						'filteredStatusUpdate' => array(
							'status' => $filtered_status_update_response instanceof \WP_REST_Response ? $filtered_status_update_response->get_status() : null,
							'data'   => $filtered_status_update_data,
							'stored' => $post_summary( $after_filtered_status_update ),
							'links'  => $filtered_status_update_links,
							'events' => $status_filter_events,
						),
						'filteredStatusCollection' => array(
							'status' => $filtered_status_collection_response instanceof \WP_REST_Response ? $filtered_status_collection_response->get_status() : null,
							'data'   => $filtered_status_collection_data,
						),
						'publishSplitUpdate' => array(
							'status'             => $publish_split_update_response instanceof \WP_REST_Response ? $publish_split_update_response->get_status() : null,
							'data'               => $publish_split_update_response instanceof \WP_REST_Response ? $publish_split_update_response->get_data() : null,
							'before'             => $post_summary( $before_publish_split_update ),
							'after'              => $post_summary( $after_publish_split_update ),
							'broadFilterRemoved' => $publish_split_broad_filter_removed,
							'strictFilterRestored' => $publish_split_filter_restored,
							'prepareEvents'      => $publish_split_prepare_events,
							'statusEvents'       => $publish_split_events,
						),
						'trashLifecycle' => array(
							'status'       => $trash_lifecycle_response instanceof \WP_REST_Response ? $trash_lifecycle_response->get_status() : null,
							'data'         => $trash_lifecycle_data,
							'stored'       => $post_summary( $after_trash_lifecycle ),
							'links'        => $trash_lifecycle_links,
						),
						'alreadyTrashed' => array(
							'status' => $already_trashed_response instanceof \WP_REST_Response ? $already_trashed_response->get_status() : null,
							'data'   => $already_trashed_response instanceof \WP_REST_Response ? $already_trashed_response->get_data() : null,
							'stored' => $post_summary( $after_repeat_trash_lifecycle ),
						),
						'forceDeleteLifecycle' => array(
							'status' => $force_delete_lifecycle_response instanceof \WP_REST_Response ? $force_delete_lifecycle_response->get_status() : null,
							'data'   => $force_delete_lifecycle_data,
							'stored' => $post_summary( $after_force_delete_lifecycle ),
						),
						'untrash' => array(
							'result' => $post_summary( $untrash_result ),
							'stored' => $post_summary( $after_untrash ),
							'meta'   => $untrash_meta_after,
							'events' => $untrash_events,
						),
						'untrashReparent' => array(
							'status' => $untrash_reparent_response instanceof \WP_REST_Response ? $untrash_reparent_response->get_status() : null,
							'data'   => $untrash_reparent_data,
							'stored' => $post_summary( $after_untrash_reparent ),
							'links'  => $untrash_reparent_links,
						),
						'untrashCollection' => array(
							'status' => $untrash_collection_response instanceof \WP_REST_Response ? $untrash_collection_response->get_status() : null,
							'data'   => $untrash_collection_data,
						),
						'untrashCollectionHead' => array(
							'status'  => $untrash_collection_head_response instanceof \WP_REST_Response ? $untrash_collection_head_response->get_status() : null,
							'data'    => $untrash_collection_head_data,
							'headers' => $untrash_collection_head_headers,
						),
						'selfParentUpdate' => array(
							'status' => $self_parent_update_response instanceof \WP_REST_Response ? $self_parent_update_response->get_status() : null,
							'data'   => $self_parent_update_data,
							'stored' => $post_summary( $after_self_parent_update ),
						),
						'descendantLoopUpdate' => array(
							'status'     => $descendant_loop_update_response instanceof \WP_REST_Response ? $descendant_loop_update_response->get_status() : null,
							'data'       => $descendant_loop_update_data,
							'loopParent' => $post_summary( $after_loop_parent ),
							'loopChild'  => $post_summary( $after_loop_child ),
							'loopGrandchild' => $post_summary( $after_loop_grandchild ),
						),
						'unrelatedLoopBreakUpdate' => array(
							'status'          => $unrelated_loop_break_update_response instanceof \WP_REST_Response ? $unrelated_loop_break_update_response->get_status() : null,
							'data'            => $unrelated_loop_break_update_data,
							'unrelatedLoopA'  => $post_summary( $after_unrelated_loop_a ),
							'unrelatedLoopB'  => $post_summary( $after_unrelated_loop_b ),
							'unrelatedUpdate' => $post_summary( $after_unrelated_update ),
						),
						'invalidUpdate' => $invalid_update_response,
						'afterInvalid'  => $post_summary( $after_invalid_update ),
						'deniedUpdate'  => array(
							'status'            => $denied_update_response instanceof \WP_REST_Response ? $denied_update_response->get_status() : null,
							'data'              => $denied_update_response instanceof \WP_REST_Response ? $denied_update_response->get_data() : null,
							'before'            => $post_summary( $before_denied_update ),
							'after'             => $post_summary( $after_denied_update ),
							'capFilterRemoved'  => $denial_cap_filter_removed,
							'prepareEvents'     => $denied_update_prepare_events,
							'hierarchyEvents'   => $denied_update_hierarchy_events,
						),
					),
					'prepareEvents' => $prepare_events,
					'hierarchyEvents' => $hierarchy_events,
					'statusEvents' => $status_events,
					'statusFilterEvents' => $status_filter_events,
					'publishSplitEvents' => $publish_split_events,
					'deleteEvents' => $delete_events,
					'untrashEvents' => $untrash_events,
					'invalidCounts' => array(
						'before' => $counts_before_invalid_create,
						'after'  => $counts_after_invalid_create,
					),
					'expected'      => array(
						'sameCreateLink'  => $same_parent_create_link,
						'crossCreateLink' => $cross_type_create_link,
						'crossUpdateLink' => $cross_type_update_link,
						'rootUpdateLink'  => $root_update_link,
						'statusParentLink' => $status_parent_update_link,
						'filteredStatusLink' => $filtered_status_update_link,
						'trashLifecycleLink' => $trash_lifecycle_link,
						'forceDeletePreviousLink' => $force_delete_previous_link,
						'untrashReparentLink' => $untrash_reparent_link,
						'selfParentLink'  => $self_parent_update_link,
						'descendantLoopLink' => $descendant_loop_update_link,
						'unrelatedLoopBreakLink' => $unrelated_loop_break_update_link,
						'sameParentUp'    => $same_parent_up_link,
						'pageParentUp'    => $page_parent_up_link,
						'unrelatedLoopAUp' => $unrelated_loop_a_up_link,
					),
				);

				self::collect_failure(
					$failures,
					$same_parent_id > 0
						&& $page_parent_id > 0
						&& $update_child_id > 0
						&& $status_update_id > 0
						&& $filtered_status_id > 0
						&& $publish_split_id > 0
						&& $trash_lifecycle_id > 0
						&& $delete_lifecycle_id > 0
						&& $loop_parent_id > 0
						&& $loop_child_id > 0
						&& $loop_grandchild_id > 0
						&& $unrelated_loop_a_id > 0
						&& $unrelated_loop_b_id > 0
						&& $unrelated_update_id > 0
						&& $denied_update_id > 0
						&& self::response_error_ok( $invalid_create_response, 'rest_post_invalid_id', 400 )
						&& self::content_count_delta_matches( $counts_before_invalid_create, $counts_after_invalid_create, array(), array() ),
					'custom hierarchical parent assignment rejects missing parent IDs before insertion',
					array(
						'response' => $invalid_create_response,
						'counts'   => $observed['invalidCounts'],
					)
				);

				self::collect_failure(
					$failures,
					$same_parent_create_response instanceof \WP_REST_Response
						&& 201 === $same_parent_create_response->get_status()
						&& self::projected_keys_match( $same_parent_create_data, $response_keys )
						&& $same_parent_id === (int) ( $same_parent_create_data['parent'] ?? 0 )
						&& $same_parent_create_link === ( $same_parent_create_data['link'] ?? null )
						&& $same_parent_up_link === self::link_href( $same_parent_create_links, 'up' )
						&& $same_parent_create_id > 0
						&& $post_type === ( \get_post( $same_parent_create_id )->post_type ?? null )
						&& $same_parent_id === (int) ( \get_post( $same_parent_create_id )->post_parent ?? 0 )
						&& $cross_type_create_response instanceof \WP_REST_Response
						&& 201 === $cross_type_create_response->get_status()
						&& self::projected_keys_match( $cross_type_create_data, $response_keys )
						&& $page_parent_id === (int) ( $cross_type_create_data['parent'] ?? 0 )
						&& $cross_type_create_link === ( $cross_type_create_data['link'] ?? null )
						&& $page_parent_up_link === self::link_href( $cross_type_create_links, 'up' )
						&& $cross_type_create_id > 0
						&& $post_type === ( \get_post( $cross_type_create_id )->post_type ?? null )
						&& $page_parent_id === (int) ( \get_post( $cross_type_create_id )->post_parent ?? 0 ),
					'custom hierarchical route-dispatched creates accept same-type and cross-type parents with fallback links',
					array(
						'sameCreate'  => $observed['responses']['sameCreate'],
						'crossCreate' => $observed['responses']['crossCreate'],
						'expected'    => $observed['expected'],
					)
				);

				self::collect_failure(
					$failures,
					$cross_type_update_response instanceof \WP_REST_Response
						&& 200 === $cross_type_update_response->get_status()
						&& self::projected_keys_match( $cross_type_update_data, $response_keys )
						&& $page_parent_id === (int) ( $cross_type_update_data['parent'] ?? 0 )
						&& $cross_type_update_link === ( $cross_type_update_data['link'] ?? null )
						&& $page_parent_up_link === self::link_href( $cross_type_update_links, 'up' )
						&& $after_cross_update instanceof \WP_Post
						&& $page_parent_id === (int) $after_cross_update->post_parent
						&& $root_update_response instanceof \WP_REST_Response
						&& 200 === $root_update_response->get_status()
						&& self::projected_keys_match( $root_update_data, $response_keys )
						&& 0 === (int) ( $root_update_data['parent'] ?? -1 )
						&& $root_update_link === ( $root_update_data['link'] ?? null )
						&& null === self::link_href( $root_update_response_links, 'up' )
						&& $after_root_update instanceof \WP_Post
						&& 0 === (int) $after_root_update->post_parent
						&& self::response_error_ok( $invalid_update_response, 'rest_post_invalid_id', 400 )
						&& $after_invalid_update instanceof \WP_Post
						&& 0 === (int) $after_invalid_update->post_parent,
					'custom hierarchical route-dispatched updates maintain fallback links while rejected missing parents preserve stored parent',
					array(
						'crossUpdate'   => $observed['responses']['crossUpdate'],
						'rootUpdate'    => $observed['responses']['rootUpdate'],
						'invalidUpdate' => $observed['responses']['invalidUpdate'],
						'afterInvalid'  => $observed['responses']['afterInvalid'],
						'expected'      => $observed['expected'],
					)
				);

				self::collect_failure(
					$failures,
					$status_parent_update_response instanceof \WP_REST_Response
						&& 200 === $status_parent_update_response->get_status()
						&& self::projected_keys_match( $status_parent_update_data, $response_keys )
						&& $page_parent_id === (int) ( $status_parent_update_data['parent'] ?? 0 )
						&& 'private' === ( $status_parent_update_data['status'] ?? null )
						&& $status_parent_update_link === ( $status_parent_update_data['link'] ?? null )
						&& $page_parent_up_link === self::link_href( $status_parent_update_links, 'up' )
						&& $after_status_parent_update instanceof \WP_Post
						&& $page_parent_id === (int) $after_status_parent_update->post_parent
						&& 'private' === $after_status_parent_update->post_status
						&& array(
							array(
								'postId' => $status_update_id,
								'type'   => $post_type,
								'old'    => 'draft',
								'new'    => 'private',
							),
						) === $status_events,
					'custom hierarchical route-dispatched parent reassignment preserves link parity across draft-to-private status transition',
					array(
						'statusParentUpdate' => $observed['responses']['statusParentUpdate'],
						'expected'           => $observed['expected'],
					)
				);

				$filtered_status_collection_item = is_array( $filtered_status_collection_data[0] ?? null ) ? $filtered_status_collection_data[0] : array();
				self::collect_failure(
					$failures,
					$filtered_status_update_response instanceof \WP_REST_Response
						&& 200 === $filtered_status_update_response->get_status()
						&& self::projected_keys_match( $filtered_status_update_data, $response_keys )
						&& $filtered_status_id === (int) ( $filtered_status_update_data['id'] ?? 0 )
						&& $page_parent_id === (int) ( $filtered_status_update_data['parent'] ?? 0 )
						&& 'pending' === ( $filtered_status_update_data['status'] ?? null )
						&& 'rest-parent-filtered-status-' . $token === ( $filtered_status_update_data['slug'] ?? null )
						&& $filtered_status_update_link === ( $filtered_status_update_data['link'] ?? null )
						&& $page_parent_up_link === self::link_href( $filtered_status_update_links, 'up' )
						&& $after_filtered_status_update instanceof \WP_Post
						&& $page_parent_id === (int) $after_filtered_status_update->post_parent
						&& 'pending' === $after_filtered_status_update->post_status
						&& $filtered_status_collection_response instanceof \WP_REST_Response
						&& 200 === $filtered_status_collection_response->get_status()
						&& 1 === count( $filtered_status_collection_data )
						&& self::projected_keys_match( $filtered_status_collection_item, array( '_links', 'id', 'link', 'parent', 'slug', 'status' ) )
						&& $filtered_status_id === (int) ( $filtered_status_collection_item['id'] ?? 0 )
						&& $page_parent_id === (int) ( $filtered_status_collection_item['parent'] ?? 0 )
						&& 'pending' === ( $filtered_status_collection_item['status'] ?? null )
						&& 'rest-parent-filtered-status-' . $token === ( $filtered_status_collection_item['slug'] ?? null )
						&& $filtered_status_update_link === ( $filtered_status_collection_item['link'] ?? null )
						&& $page_parent_up_link === self::link_href( is_array( $filtered_status_collection_item['_links'] ?? null ) ? $filtered_status_collection_item['_links'] : array(), 'up' )
						&& array(
							array(
								'hook'           => 'rest_pre_insert_' . $post_type,
								'postId'         => $filtered_status_id,
								'method'         => 'PUT',
								'requestedStatus' => 'publish',
								'preparedStatus' => 'publish',
								'preparedParent' => $page_parent_id,
							),
							array(
								'hook'   => 'transition_post_status',
								'postId' => $filtered_status_id,
								'type'   => $post_type,
								'old'    => 'draft',
								'new'    => 'pending',
								'parent' => $page_parent_id,
							),
							array(
								'hook'     => 'rest_insert_' . $post_type,
								'postId'   => $filtered_status_id,
								'method'   => 'PUT',
								'creating' => false,
								'status'   => 'pending',
								'parent'   => $page_parent_id,
							),
						) === $status_filter_events,
					'custom hierarchical route-dispatched parent reassignment honors scoped REST status filter before pending collection readback',
					array(
						'filteredStatusUpdate' => $observed['responses']['filteredStatusUpdate'],
						'filteredStatusCollection' => $observed['responses']['filteredStatusCollection'],
						'statusFilterEvents'   => $status_filter_events,
						'expected'             => $observed['expected'],
					)
				);

				self::collect_failure(
					$failures,
					$publish_split_broad_filter_removed
						&& $publish_split_filter_restored
						&& self::response_error_ok( $publish_split_update_response, 'rest_cannot_publish', 403 )
						&& $before_publish_split_update instanceof \WP_Post
						&& $after_publish_split_update instanceof \WP_Post
						&& $same_parent_id === (int) $before_publish_split_update->post_parent
						&& $same_parent_id === (int) $after_publish_split_update->post_parent
						&& 'draft' === $before_publish_split_update->post_status
						&& 'draft' === $after_publish_split_update->post_status
						&& 'rest-parent-publish-split-' . $token === $before_publish_split_update->post_name
						&& 'rest-parent-publish-split-' . $token === $after_publish_split_update->post_name
						&& array() === $publish_split_prepare_events
						&& array() === $publish_split_events,
					'custom hierarchical route-dispatched edit-only parent/status update cannot publish and preserves storage',
					array(
						'publishSplitUpdate' => $observed['responses']['publishSplitUpdate'],
					)
				);

				self::collect_failure(
					$failures,
					$trash_lifecycle_response instanceof \WP_REST_Response
						&& 200 === $trash_lifecycle_response->get_status()
						&& self::projected_keys_match( $trash_lifecycle_data, $response_keys )
						&& $trash_lifecycle_id === (int) ( $trash_lifecycle_data['id'] ?? 0 )
						&& $same_parent_id === (int) ( $trash_lifecycle_data['parent'] ?? 0 )
						&& 'trash' === ( $trash_lifecycle_data['status'] ?? null )
						&& $trash_lifecycle_trashed_slug === ( $trash_lifecycle_data['slug'] ?? null )
						&& $trash_lifecycle_link === ( $trash_lifecycle_data['link'] ?? null )
						&& $same_parent_up_link === self::link_href( $trash_lifecycle_links, 'up' )
						&& $after_trash_lifecycle instanceof \WP_Post
						&& 'trash' === $after_trash_lifecycle->post_status
						&& $same_parent_id === (int) $after_trash_lifecycle->post_parent
						&& self::response_error_ok( $already_trashed_response, 'rest_already_trashed', 410 )
						&& $after_repeat_trash_lifecycle instanceof \WP_Post
						&& 'trash' === $after_repeat_trash_lifecycle->post_status
						&& $same_parent_id === (int) $after_repeat_trash_lifecycle->post_parent
						&& $force_delete_lifecycle_response instanceof \WP_REST_Response
						&& 200 === $force_delete_lifecycle_response->get_status()
						&& true === ( $force_delete_lifecycle_data['deleted'] ?? null )
						&& $delete_lifecycle_id === (int) ( $force_delete_lifecycle_data['previous']['id'] ?? 0 )
						&& $same_parent_id === (int) ( $force_delete_lifecycle_data['previous']['parent'] ?? 0 )
						&& 'publish' === ( $force_delete_lifecycle_data['previous']['status'] ?? null )
						&& 'rest-parent-delete-lifecycle-' . $token === ( $force_delete_lifecycle_data['previous']['slug'] ?? null )
						&& $force_delete_previous_link === ( $force_delete_lifecycle_data['previous']['link'] ?? null )
						&& null === $after_force_delete_lifecycle
						&& array(
							array(
								'id'      => $trash_lifecycle_id,
								'status'  => 'trash',
								'parent'  => $same_parent_id,
								'method'  => 'DELETE',
								'force'   => false,
								'route'   => '/wp/v2/' . $rest_base . '/' . $trash_lifecycle_id,
								'deleted' => false,
							),
							array(
								'id'      => $delete_lifecycle_id,
								'status'  => 'publish',
								'parent'  => $same_parent_id,
								'method'  => 'DELETE',
								'force'   => true,
								'route'   => '/wp/v2/' . $rest_base . '/' . $delete_lifecycle_id,
								'deleted' => true,
							),
						) === $delete_events,
					'custom hierarchical route-dispatched trash/delete lifecycle preserves parent, link, and delete-hook parity',
					array(
						'trashLifecycle'       => $observed['responses']['trashLifecycle'],
						'alreadyTrashed'       => $observed['responses']['alreadyTrashed'],
						'forceDeleteLifecycle' => $observed['responses']['forceDeleteLifecycle'],
						'deleteEvents'         => $delete_events,
						'expected'             => $observed['expected'],
					)
				);

				$untrash_collection_item = is_array( $untrash_collection_data[0] ?? null ) ? $untrash_collection_data[0] : array();
				self::collect_failure(
					$failures,
					$untrash_result instanceof \WP_Post
						&& $trash_lifecycle_id === (int) $untrash_result->ID
						&& 'trash' === $untrash_result->post_status
						&& $same_parent_id === (int) $untrash_result->post_parent
						&& $trash_lifecycle_trashed_slug === $untrash_result->post_name
						&& $after_untrash instanceof \WP_Post
						&& 'draft' === $after_untrash->post_status
						&& $same_parent_id === (int) $after_untrash->post_parent
						&& 'rest-parent-trash-lifecycle-' . $token === $after_untrash->post_name
						&& '' === (string) ( $untrash_meta_after['status'] ?? null )
						&& '' === (string) ( $untrash_meta_after['time'] ?? null )
						&& '' === (string) ( $untrash_meta_after['desiredSlug'] ?? null )
						&& $untrash_reparent_response instanceof \WP_REST_Response
						&& 200 === $untrash_reparent_response->get_status()
						&& self::projected_keys_match( $untrash_reparent_data, $response_keys )
						&& $trash_lifecycle_id === (int) ( $untrash_reparent_data['id'] ?? 0 )
						&& $page_parent_id === (int) ( $untrash_reparent_data['parent'] ?? 0 )
						&& 'draft' === ( $untrash_reparent_data['status'] ?? null )
						&& 'rest-parent-trash-lifecycle-' . $token === ( $untrash_reparent_data['slug'] ?? null )
						&& $untrash_reparent_link === ( $untrash_reparent_data['link'] ?? null )
						&& $page_parent_up_link === self::link_href( $untrash_reparent_links, 'up' )
						&& $after_untrash_reparent instanceof \WP_Post
						&& 'draft' === $after_untrash_reparent->post_status
						&& $page_parent_id === (int) $after_untrash_reparent->post_parent
						&& 'rest-parent-trash-lifecycle-' . $token === $after_untrash_reparent->post_name
						&& $untrash_collection_response instanceof \WP_REST_Response
						&& 200 === $untrash_collection_response->get_status()
						&& 1 === count( $untrash_collection_data )
						&& self::projected_keys_match( $untrash_collection_item, array( '_links', 'id', 'link', 'parent', 'slug', 'status' ) )
						&& $trash_lifecycle_id === (int) ( $untrash_collection_item['id'] ?? 0 )
						&& $page_parent_id === (int) ( $untrash_collection_item['parent'] ?? 0 )
						&& 'draft' === ( $untrash_collection_item['status'] ?? null )
						&& 'rest-parent-trash-lifecycle-' . $token === ( $untrash_collection_item['slug'] ?? null )
						&& $untrash_reparent_link === ( $untrash_collection_item['link'] ?? null )
						&& $page_parent_up_link === self::link_href( is_array( $untrash_collection_item['_links'] ?? null ) ? $untrash_collection_item['_links'] : array(), 'up' )
						&& $untrash_collection_head_response instanceof \WP_REST_Response
						&& 200 === $untrash_collection_head_response->get_status()
						&& array() === $untrash_collection_head_data
						&& '1' === (string) ( $untrash_collection_head_headers['X-WP-Total'] ?? '' )
						&& '1' === (string) ( $untrash_collection_head_headers['X-WP-TotalPages'] ?? '' )
						&& array(
							array(
								'hook'           => 'pre_untrash_post',
								'postId'         => $trash_lifecycle_id,
								'type'           => $post_type,
								'status'         => 'trash',
								'parent'         => $same_parent_id,
								'previousStatus' => 'publish',
								'check'          => null,
							),
							array(
								'hook'           => 'untrash_post',
								'postId'         => $trash_lifecycle_id,
								'type'           => $post_type,
								'status'         => 'trash',
								'parent'         => $same_parent_id,
								'slug'           => $trash_lifecycle_trashed_slug,
								'previousStatus' => 'publish',
							),
							array(
								'hook'           => 'wp_untrash_post_status',
								'postId'         => $trash_lifecycle_id,
								'newStatus'      => 'draft',
								'previousStatus' => 'publish',
							),
							array(
								'hook'   => 'transition_post_status',
								'postId' => $trash_lifecycle_id,
								'type'   => $post_type,
								'old'    => 'trash',
								'new'    => 'draft',
								'parent' => $same_parent_id,
								'slug'   => 'rest-parent-trash-lifecycle-' . $token,
							),
							array(
								'hook'           => 'untrashed_post',
								'postId'         => $trash_lifecycle_id,
								'type'           => $post_type,
								'status'         => 'draft',
								'parent'         => $same_parent_id,
								'slug'           => 'rest-parent-trash-lifecycle-' . $token,
								'previousStatus' => 'publish',
							),
						) === $untrash_events,
					'custom hierarchical wp_untrash_post lifecycle restores slug before route-dispatched draft reparent collection readback',
					array(
						'untrash'           => $observed['responses']['untrash'],
						'untrashReparent'   => $observed['responses']['untrashReparent'],
						'untrashCollection' => $observed['responses']['untrashCollection'],
						'untrashCollectionHead' => $observed['responses']['untrashCollectionHead'],
						'expected'          => $observed['expected'],
					)
				);

				self::collect_failure(
					$failures,
					$self_parent_update_response instanceof \WP_REST_Response
						&& 200 === $self_parent_update_response->get_status()
						&& self::projected_keys_match( $self_parent_update_data, $response_keys )
						&& 0 === (int) ( $self_parent_update_data['parent'] ?? -1 )
						&& $self_parent_update_link === ( $self_parent_update_data['link'] ?? null )
						&& null === self::link_href( $self_parent_update_links, 'up' )
						&& $after_self_parent_update instanceof \WP_Post
						&& 0 === (int) $after_self_parent_update->post_parent
						&& $descendant_loop_update_response instanceof \WP_REST_Response
						&& 200 === $descendant_loop_update_response->get_status()
						&& self::projected_keys_match( $descendant_loop_update_data, $response_keys )
						&& 0 === (int) ( $descendant_loop_update_data['parent'] ?? -1 )
						&& $descendant_loop_update_link === ( $descendant_loop_update_data['link'] ?? null )
						&& null === self::link_href( $descendant_loop_update_links, 'up' )
						&& $after_loop_parent instanceof \WP_Post
						&& 0 === (int) $after_loop_parent->post_parent
						&& $after_loop_child instanceof \WP_Post
						&& $loop_parent_id === (int) $after_loop_child->post_parent
						&& $after_loop_grandchild instanceof \WP_Post
						&& $loop_child_id === (int) $after_loop_grandchild->post_parent
						&& array(
							array(
								'stage'  => 'before',
								'postId' => $update_child_id,
								'parent' => $update_child_id,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'after',
								'postId' => $update_child_id,
								'parent' => 0,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'before',
								'postId' => $loop_parent_id,
								'parent' => $loop_grandchild_id,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'after',
								'postId' => $loop_parent_id,
								'parent' => 0,
								'type'   => $post_type,
							),
						) === array_slice( $hierarchy_events, 0, 4 ),
					'custom hierarchical route-dispatched self-parent and descendant-loop updates normalize to root without breaking descendants',
					array(
						'selfParentUpdate'     => $observed['responses']['selfParentUpdate'],
						'descendantLoopUpdate' => $observed['responses']['descendantLoopUpdate'],
						'hierarchyEvents'      => $hierarchy_events,
						'expected'             => $observed['expected'],
					)
				);

				self::collect_failure(
					$failures,
					$unrelated_loop_break_update_response instanceof \WP_REST_Response
						&& 200 === $unrelated_loop_break_update_response->get_status()
						&& self::projected_keys_match( $unrelated_loop_break_update_data, $response_keys )
						&& $unrelated_loop_a_id === (int) ( $unrelated_loop_break_update_data['parent'] ?? 0 )
						&& $unrelated_loop_break_update_link === ( $unrelated_loop_break_update_data['link'] ?? null )
						&& $unrelated_loop_a_up_link === self::link_href( $unrelated_loop_break_update_links, 'up' )
						&& $after_unrelated_loop_a instanceof \WP_Post
						&& 0 === (int) $after_unrelated_loop_a->post_parent
						&& $after_unrelated_loop_b instanceof \WP_Post
						&& 0 === (int) $after_unrelated_loop_b->post_parent
						&& $after_unrelated_update instanceof \WP_Post
						&& $unrelated_loop_a_id === (int) $after_unrelated_update->post_parent
						&& array(
							array(
								'stage'  => 'before',
								'postId' => $update_child_id,
								'parent' => $update_child_id,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'after',
								'postId' => $update_child_id,
								'parent' => 0,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'before',
								'postId' => $loop_parent_id,
								'parent' => $loop_grandchild_id,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'after',
								'postId' => $loop_parent_id,
								'parent' => 0,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'before',
								'postId' => $unrelated_update_id,
								'parent' => $unrelated_loop_a_id,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'before',
								'postId' => $unrelated_loop_a_id,
								'parent' => 0,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'after',
								'postId' => $unrelated_loop_a_id,
								'parent' => 0,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'before',
								'postId' => $unrelated_loop_b_id,
								'parent' => 0,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'after',
								'postId' => $unrelated_loop_b_id,
								'parent' => 0,
								'type'   => $post_type,
							),
							array(
								'stage'  => 'after',
								'postId' => $unrelated_update_id,
								'parent' => $unrelated_loop_a_id,
								'type'   => $post_type,
							),
						) === $hierarchy_events,
					'custom hierarchical route-dispatched update breaks preexisting parent loops outside the updated post',
					array(
						'unrelatedLoopBreakUpdate' => $observed['responses']['unrelatedLoopBreakUpdate'],
						'hierarchyEvents'          => $hierarchy_events,
						'expected'                 => $observed['expected'],
					)
				);

				self::collect_failure(
					$failures,
					$denial_cap_filter_removed
						&& self::response_error_ok( $denied_update_response, 'rest_cannot_edit', 403 )
						&& $before_denied_update instanceof \WP_Post
						&& $after_denied_update instanceof \WP_Post
						&& $same_parent_id === (int) $before_denied_update->post_parent
						&& $same_parent_id === (int) $after_denied_update->post_parent
						&& 'publish' === $before_denied_update->post_status
						&& 'publish' === $after_denied_update->post_status
						&& array() === $denied_update_prepare_events
						&& array() === $denied_update_hierarchy_events,
					'custom hierarchical route-dispatched capability-denied parent/status update preserves storage and skips mutation hooks',
					array(
						'deniedUpdate' => $observed['responses']['deniedUpdate'],
					)
				);

				self::collect_failure(
					$failures,
					array( $same_parent_create_id, $cross_type_create_id, $update_child_id, $update_child_id, $status_update_id, $filtered_status_id, $filtered_status_id, $trash_lifecycle_id, $delete_lifecycle_id, $trash_lifecycle_id, $trash_lifecycle_id, $update_child_id, $loop_parent_id, $unrelated_update_id ) === array_values( array_map( static fn ( array $event ): int => (int) ( $event['id'] ?? 0 ), $prepare_events ) )
						&& array( 'POST', 'POST', 'PUT', 'PUT', 'PUT', 'PUT', 'GET', 'DELETE', 'DELETE', 'PUT', 'GET', 'PUT', 'PUT', 'PUT' ) === array_values( array_map( static fn ( array $event ): string => (string) ( $event['method'] ?? '' ), $prepare_events ) )
						&& array( 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit', 'edit' ) === array_values( array_map( static fn ( array $event ): string => (string) ( $event['context'] ?? '' ), $prepare_events ) )
						&& array( '/wp/v2/' . $rest_base, '/wp/v2/' . $rest_base, '/wp/v2/' . $rest_base . '/' . $update_child_id, '/wp/v2/' . $rest_base . '/' . $update_child_id, '/wp/v2/' . $rest_base . '/' . $status_update_id, '/wp/v2/' . $rest_base . '/' . $filtered_status_id, '/wp/v2/' . $rest_base, '/wp/v2/' . $rest_base . '/' . $trash_lifecycle_id, '/wp/v2/' . $rest_base . '/' . $delete_lifecycle_id, '/wp/v2/' . $rest_base . '/' . $trash_lifecycle_id, '/wp/v2/' . $rest_base, '/wp/v2/' . $rest_base . '/' . $update_child_id, '/wp/v2/' . $rest_base . '/' . $loop_parent_id, '/wp/v2/' . $rest_base . '/' . $unrelated_update_id ) === array_values( array_map( static fn ( array $event ): string => (string) ( $event['route'] ?? '' ), $prepare_events ) ),
					'custom hierarchical parent assignment prepare hook receives only successful create/update/delete responses',
					array( 'prepareEvents' => $prepare_events )
				);
			}
		} finally {
			if ( null !== $delete_filter ) {
				\remove_action( 'rest_delete_' . $post_type, $delete_filter, 10 );
			}
			$delete_filter_restored = null === $delete_filter
				|| false === \has_filter( 'rest_delete_' . $post_type, $delete_filter );
			\remove_filter( 'rest_prepare_' . $post_type, $prepare_filter, 10 );
			$custom_filters_restored = false === \has_filter( 'rest_prepare_' . $post_type, $prepare_filter );
			if ( null !== $hierarchy_before_filter ) {
				\remove_filter( 'wp_insert_post_parent', $hierarchy_before_filter, 9 );
			}
			if ( null !== $hierarchy_after_filter ) {
				\remove_filter( 'wp_insert_post_parent', $hierarchy_after_filter, 11 );
			}
			if ( $added_hierarchy_loop_filter ) {
				\remove_filter( 'wp_insert_post_parent', 'wp_check_post_hierarchy_for_loops', 10 );
			}
			$hierarchy_filters_restored = ( null === $hierarchy_before_filter || false === \has_filter( 'wp_insert_post_parent', $hierarchy_before_filter ) )
				&& ( null === $hierarchy_after_filter || false === \has_filter( 'wp_insert_post_parent', $hierarchy_after_filter ) );
			$hierarchy_loop_filter_restored = ! $added_hierarchy_loop_filter
				|| false === \has_filter( 'wp_insert_post_parent', 'wp_check_post_hierarchy_for_loops' );
			if ( null !== $status_transition_filter ) {
				\remove_action( 'transition_post_status', $status_transition_filter, 10 );
			}
			if ( null !== $status_pre_insert_filter ) {
				\remove_filter( 'rest_pre_insert_' . $post_type, $status_pre_insert_filter, 10 );
			}
			if ( null !== $status_insert_action ) {
				\remove_action( 'rest_insert_' . $post_type, $status_insert_action, 10 );
			}
			$status_filter_restored = ( null === $status_transition_filter || false === \has_filter( 'transition_post_status', $status_transition_filter ) )
				&& ( null === $status_pre_insert_filter || false === \has_filter( 'rest_pre_insert_' . $post_type, $status_pre_insert_filter ) )
				&& ( null === $status_insert_action || false === \has_filter( 'rest_insert_' . $post_type, $status_insert_action ) );
			if ( null !== $untrash_pre_filter ) {
				\remove_filter( 'pre_untrash_post', $untrash_pre_filter, 10 );
			}
			if ( null !== $untrash_post_action ) {
				\remove_action( 'untrash_post', $untrash_post_action, 10 );
			}
			if ( null !== $untrash_status_filter ) {
				\remove_filter( 'wp_untrash_post_status', $untrash_status_filter, 10 );
			}
			if ( null !== $untrash_transition_filter ) {
				\remove_action( 'transition_post_status', $untrash_transition_filter, 10 );
			}
			if ( null !== $untrashed_post_action ) {
				\remove_action( 'untrashed_post', $untrashed_post_action, 10 );
			}
			$untrash_filter_restored = ( null === $untrash_pre_filter || false === \has_filter( 'pre_untrash_post', $untrash_pre_filter ) )
				&& ( null === $untrash_post_action || false === \has_filter( 'untrash_post', $untrash_post_action ) )
				&& ( null === $untrash_status_filter || false === \has_filter( 'wp_untrash_post_status', $untrash_status_filter ) )
				&& ( null === $untrash_transition_filter || false === \has_filter( 'transition_post_status', $untrash_transition_filter ) )
				&& ( null === $untrashed_post_action || false === \has_filter( 'untrashed_post', $untrashed_post_action ) );

			$delete_posts();
			$counts_after_cleanup = self::content_counts();

			if ( null !== $publish_split_cap_filter ) {
				\remove_filter( 'user_has_cap', $publish_split_cap_filter, 10 );
			}
			$publish_split_filter_restored = $publish_split_filter_restored
				|| null === $publish_split_cap_filter
				|| false === \has_filter( 'user_has_cap', $publish_split_cap_filter );

			if ( null !== $cap_filter ) {
				$cap_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter          = null;
			} else {
				$cap_filter_restored = true;
			}

			self::restore_rest_default_filters( $filter_snapshot );
			$default_filters_restored = $filter_snapshot === self::rest_default_filter_state();

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

			\wp_set_current_user( $previous_current_user_id );

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

			$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
				&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
			$wp_restored = $previous_wp_set === array_key_exists( 'wp', $GLOBALS )
				&& ( ! $previous_wp_set || $previous_wp === $GLOBALS['wp'] );
			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
			$post_type_restored = $had_post_type === isset( $GLOBALS['wp_post_types'][ $post_type ] )
				&& ( ! $had_post_type || $previous_post_type === $GLOBALS['wp_post_types'][ $post_type ] );
			$query_vars_restored = null === $previous_query_vars
				|| ! ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' ) )
				|| $previous_query_vars === $GLOBALS['wp']->public_query_vars;
		}

		$observed['counts'] = array(
			'before' => $counts_before,
			'after'  => $counts_after_cleanup,
		);
		self::collect_failure(
			$failures,
			self::content_count_delta_matches( $counts_before, $counts_after_cleanup, array(), array() ),
			'custom hierarchical parent assignment fixtures are deleted after route dispatch',
			$observed['counts']
		);
		self::collect_failure(
			$failures,
			$custom_filters_restored
				&& $cap_filter_restored
				&& $hierarchy_filters_restored
				&& $hierarchy_loop_filter_restored
				&& $status_filter_restored
				&& $untrash_filter_restored
				&& $delete_filter_restored
				&& $publish_split_filter_restored
				&& $default_filters_restored
				&& $rewrite_restored
				&& $wp_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored
				&& $post_type_restored
				&& $query_vars_restored,
			'custom hierarchical parent assignment restores prepare, delete, status, cap, publish-split, default REST, rewrite, wp, server, actions, current user, post type, and query-var filters',
			array(
				'customFiltersRestored'  => $custom_filters_restored,
				'capFilterRestored'      => $cap_filter_restored,
				'hierarchyFiltersRestored' => $hierarchy_filters_restored,
				'hierarchyLoopFilterRestored' => $hierarchy_loop_filter_restored,
				'statusFilterRestored'    => $status_filter_restored,
				'untrashFilterRestored'   => $untrash_filter_restored,
				'deleteFilterRestored'    => $delete_filter_restored,
				'publishSplitFilterRestored' => $publish_split_filter_restored,
				'defaultFiltersRestored' => $default_filters_restored,
				'rewriteRestored'        => $rewrite_restored,
				'wpRestored'             => $wp_restored,
				'serverRestored'         => $server_restored,
				'actionsRestored'        => $actions_restored,
				'currentUserRestored'    => $current_user_restored,
				'postTypeRestored'       => $post_type_restored,
				'queryVarsRestored'      => $query_vars_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.route-dispatched-custom-hierarchical-parent-assignment',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postType' => $post_type,
				'restBase' => $rest_base,
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_route_dispatched_custom_hierarchical_collection_parent_filters( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures       = array();
		$observed       = array();
		$post_ids       = array();
		$prepare_events = array();

		$token                    = substr( $case['token'], 0, 8 );
		$post_type                = 'cfc_' . $token;
		$rest_base                = 'custom-hier-list-' . $token;
		$rewrite_slug             = 'rest-custom-list-' . $token;
		$marker                   = 'component-fuzz-custom-hier-collection-' . $ctx->seed() . '-' . $ctx->iteration();
		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;
		$previous_rewrite_set     = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite         = $GLOBALS['wp_rewrite'] ?? null;
		$had_post_type            = isset( $GLOBALS['wp_post_types'][ $post_type ] );
		$previous_post_type       = $GLOBALS['wp_post_types'][ $post_type ] ?? null;
		$previous_query_vars      = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' )
			? $GLOBALS['wp']->public_query_vars
			: null;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$filter_snapshot           = self::rest_default_filter_state();
		$permalink_structure       = '/%postname%/';
		$permalink_filter          = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		$cap_filter                = null;
		$private_status_cap_filter = null;
		$custom_filters_restored   = false;
		$cap_filter_restored       = false;
		$private_status_filter_restored = false;
		$default_filters_restored  = false;
		$permalink_filter_restored = false;
		$rewrite_restored          = false;
		$server_restored           = false;
		$actions_restored          = false;
		$current_user_restored     = false;
		$post_type_restored        = false;
		$query_vars_restored       = false;
		$counts_before             = self::content_counts();
		$counts_after_cleanup      = array();
		$rest_args                 = array();
		$query_vars                = array();
		$objects_by_case           = array();
		$totals_by_case            = array(
			'parent'         => 4,
			'parent_exclude' => 3,
			'private_status' => 1,
			'root'           => 1,
		);

		$remember_post = static function ( $post_id ) use ( &$post_ids ): int {
			if ( is_int( $post_id ) && $post_id > 0 ) {
				$post_ids[] = $post_id;
				return $post_id;
			}

			return 0;
		};
		$delete_posts  = static function () use ( &$post_ids ): void {
			foreach ( array_reverse( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					\wp_delete_post( $post_id, true );
				}
			}

			$post_ids = array();
		};
		$post_summary = static function ( $post ): ?array {
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}

			return array(
				'id'        => (int) $post->ID,
				'menuOrder' => (int) $post->menu_order,
				'parent'    => (int) $post->post_parent,
				'slug'      => $post->post_name,
				'status'    => $post->post_status,
				'type'      => $post->post_type,
			);
		};
		$case_for_request = static function ( \WP_REST_Request $request ): string {
			if ( array() !== (array) $request->get_param( 'parent_exclude' ) ) {
				return 'parent_exclude';
			}

			$statuses = array_values( array_map( 'strval', (array) $request->get_param( 'status' ) ) );
			if ( in_array( 'private', $statuses, true ) ) {
				return 'private_status';
			}

			$parents = array_values( array_map( 'intval', (array) $request->get_param( 'parent' ) ) );
			if ( array( 0 ) === $parents ) {
				return 'root';
			}
			if ( array() !== $parents ) {
				return 'parent';
			}

			return 'unknown';
		};

		$rest_filter = static function ( array $args, \WP_REST_Request $request ) use ( &$rest_args, $case_for_request, $marker ): array {
			$args['component_fuzz_custom_hier_collection'] = $case_for_request( $request );
			$args['component_fuzz_custom_hier_marker']     = $marker;
			$args['no_found_rows']                         = true;
			$rest_args[] = array(
				'args'   => $args,
				'method' => $request->get_method(),
				'params' => $request->get_params(),
				'route'  => $request->get_route(),
			);
			return $args;
		};
		$pre_filter  = static function ( $posts, \WP_Query $query ) use ( &$query_vars, &$objects_by_case, $marker, $totals_by_case ): array {
			if ( $marker !== ( $query->query_vars['component_fuzz_custom_hier_marker'] ?? null ) ) {
				return $posts;
			}

			$query_vars[]          = $query->query_vars;
			$case_name             = (string) ( $query->query_vars['component_fuzz_custom_hier_collection'] ?? 'unknown' );
			$total                 = (int) ( $totals_by_case[ $case_name ] ?? 0 );
			$query->found_posts    = $total;
			$query->max_num_pages  = (int) ceil( $total / max( 1, (int) ( $query->query_vars['posts_per_page'] ?? 1 ) ) );
			$objects               = $objects_by_case[ $case_name ] ?? array();

			if ( 'ids' === ( $query->query_vars['fields'] ?? null ) ) {
				return array_values(
					array_map(
						static function ( \WP_Post $post ): int {
							return (int) $post->ID;
						},
						$objects
					)
				);
			}

			return $objects;
		};
		$prepare_filter = static function ( $response, $post, \WP_REST_Request $request ) use ( &$prepare_events, $post_type ) {
			if ( $response instanceof \WP_REST_Response && $post instanceof \WP_Post && $post_type === $post->post_type ) {
				$prepare_events[] = self::prepare_event( $post_type, (int) $post->ID, $request );
			}
			return $response;
		};

		try {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$GLOBALS['wp_rewrite']->permalink_structure = $permalink_structure;
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );

			$registration = \register_post_type(
				$post_type,
				array(
					'capability_type'       => 'page',
					'hierarchical'          => true,
					'map_meta_cap'          => true,
					'public'                => true,
					'query_var'             => false,
					'rest_base'             => $rest_base,
					'rest_controller_class' => 'WP_REST_Posts_Controller',
					'rewrite'               => array(
						'slug'       => $rewrite_slug,
						'with_front' => false,
					),
					'show_in_rest'          => true,
					'show_ui'               => true,
					'supports'              => array( 'title', 'editor', 'author', 'page-attributes' ),
				)
			);
			$post_type_object = \get_post_type_object( $post_type );
			$controller       = $post_type_object instanceof \WP_Post_Type ? new \WP_REST_Posts_Controller( $post_type ) : null;
			if ( $controller instanceof \WP_REST_Posts_Controller ) {
				$controller->register_routes();
			}
			\rest_api_default_filters();

			$routes            = $server->get_routes();
			$collection_params = $controller instanceof \WP_REST_Posts_Controller ? $controller->get_collection_params() : array();
			$item_schema       = $controller instanceof \WP_REST_Posts_Controller ? $controller->get_item_schema() : array();
			$schema_props      = is_array( $item_schema['properties'] ?? null ) ? $item_schema['properties'] : array();

			self::collect_failure(
				$failures,
				$registration instanceof \WP_Post_Type
					&& $post_type_object instanceof \WP_Post_Type
					&& $controller instanceof \WP_REST_Posts_Controller
					&& true === $post_type_object->hierarchical
					&& true === $post_type_object->show_in_rest
					&& $rest_base === $post_type_object->rest_base
					&& isset( $routes[ '/wp/v2/' . $rest_base ], $routes[ '/wp/v2/' . $rest_base . '/(?P<id>[\\d]+)' ] )
					&& isset( $collection_params['parent'], $collection_params['parent_exclude'], $collection_params['menu_order'] )
					&& array() === ( $collection_params['parent']['default'] ?? null )
					&& array() === ( $collection_params['parent_exclude']['default'] ?? null )
					&& self::collection_enum_contains( $collection_params, 'orderby', array( 'parent', 'include_slugs', 'menu_order' ) )
					&& array_key_exists( 'parent', $schema_props )
					&& array_key_exists( 'menu_order', $schema_props ),
				'custom hierarchical collection route registers parent filters, menu order params, and item schema fields',
				array(
					'postType'         => $post_type,
					'restBase'         => $rest_base,
					'collectionParams' => array_keys( $collection_params ),
					'orderbyEnum'      => $collection_params['orderby']['enum'] ?? null,
					'schemaProps'      => array_keys( $schema_props ),
					'routes'           => array_keys( $routes ),
				)
			);

			if ( $controller instanceof \WP_REST_Posts_Controller ) {
				$parent_a_slug = 'rest-list-parent-a-' . $token;
				$parent_b_slug = 'rest-list-parent-b-' . $token;
				$child_a1_slug = 'rest-list-child-a1-' . $token;
				$child_a2_slug = 'rest-list-child-a2-' . $token;
				$child_b_slug  = 'rest-list-child-b-' . $token;
				$private_child_slug = 'rest-list-private-child-' . $token;
				$root_slug     = 'rest-list-root-' . $token;

				$parent_a_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'menu_order'   => 10,
								'post_author'  => $fixtures['author'],
								'post_content' => 'REST list parent A ' . $token,
								'post_name'    => $parent_a_slug,
								'post_parent'  => 0,
								'post_status'  => 'publish',
								'post_title'   => 'REST List Parent A ' . $token,
								'post_type'    => $post_type,
							)
						),
						true,
						false
					)
				);
				$parent_b_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'menu_order'   => 20,
								'post_author'  => $fixtures['author'],
								'post_content' => 'REST list parent B ' . $token,
								'post_name'    => $parent_b_slug,
								'post_parent'  => 0,
								'post_status'  => 'publish',
								'post_title'   => 'REST List Parent B ' . $token,
								'post_type'    => $post_type,
							)
						),
						true,
						false
					)
				);
				$child_a1_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'menu_order'   => 2,
								'post_author'  => $fixtures['author'],
								'post_content' => 'REST list child A1 ' . $token,
								'post_name'    => $child_a1_slug,
								'post_parent'  => $parent_a_id,
								'post_status'  => 'publish',
								'post_title'   => 'REST List Child A1 ' . $token,
								'post_type'    => $post_type,
							)
						),
						true,
						false
					)
				);
				$child_a2_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'menu_order'   => 1,
								'post_author'  => $fixtures['author'],
								'post_content' => 'REST list child A2 ' . $token,
								'post_name'    => $child_a2_slug,
								'post_parent'  => $parent_a_id,
								'post_status'  => 'publish',
								'post_title'   => 'REST List Child A2 ' . $token,
								'post_type'    => $post_type,
							)
						),
						true,
						false
					)
				);
				$child_b_id  = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'menu_order'   => 3,
								'post_author'  => $fixtures['author'],
								'post_content' => 'REST list child B ' . $token,
								'post_name'    => $child_b_slug,
								'post_parent'  => $parent_b_id,
								'post_status'  => 'publish',
								'post_title'   => 'REST List Child B ' . $token,
								'post_type'    => $post_type,
							)
						),
						true,
						false
					)
				);
				$private_child_id = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'menu_order'   => 5,
								'post_author'  => $fixtures['author'],
								'post_content' => 'REST list private child ' . $token,
								'post_name'    => $private_child_slug,
								'post_parent'  => $parent_a_id,
								'post_status'  => 'private',
								'post_title'   => 'REST List Private Child ' . $token,
								'post_type'    => $post_type,
							)
						),
						true,
						false
					)
				);
				$root_id     = $remember_post(
					\wp_insert_post(
						\wp_slash(
							array(
								'menu_order'   => 4,
								'post_author'  => $fixtures['author'],
								'post_content' => 'REST list root ' . $token,
								'post_name'    => $root_slug,
								'post_parent'  => 0,
								'post_status'  => 'publish',
								'post_title'   => 'REST List Root ' . $token,
								'post_type'    => $post_type,
							)
						),
						true,
						false
					)
				);

				$objects_by_case = array(
					'parent'         => array_values(
						array_filter(
							array( \get_post( $child_a2_id ), \get_post( $child_a1_id ) ),
							static fn ( $post ): bool => $post instanceof \WP_Post
						)
					),
					'parent_exclude' => array_values(
						array_filter(
							array( \get_post( $root_id ), \get_post( $child_b_id ) ),
							static fn ( $post ): bool => $post instanceof \WP_Post
						)
					),
					'private_status' => array_values(
						array_filter(
							array( \get_post( $private_child_id ) ),
							static fn ( $post ): bool => $post instanceof \WP_Post
						)
					),
					'root'           => array_values(
						array_filter(
							array( \get_post( $root_id ) ),
							static fn ( $post ): bool => $post instanceof \WP_Post
						)
					),
				);

				self::collect_failure(
					$failures,
					$parent_a_id > 0
						&& $parent_b_id > 0
						&& $child_a1_id > 0
						&& $child_a2_id > 0
						&& $child_b_id > 0
						&& $private_child_id > 0
						&& $root_id > 0,
					'custom hierarchical collection fixtures create parent, child, and root scopes',
					array(
						'parentA' => $post_summary( \get_post( $parent_a_id ) ),
						'parentB' => $post_summary( \get_post( $parent_b_id ) ),
						'childA1' => $post_summary( \get_post( $child_a1_id ) ),
						'childA2' => $post_summary( \get_post( $child_a2_id ) ),
						'childB'  => $post_summary( \get_post( $child_b_id ) ),
						'privateChild' => $post_summary( \get_post( $private_child_id ) ),
						'root'    => $post_summary( \get_post( $root_id ) ),
					)
				);

				\add_filter( 'rest_' . $post_type . '_query', $rest_filter, 10, 2 );
				\add_filter( 'posts_pre_query', $pre_filter, 10, 2 );
				\add_filter( 'rest_prepare_' . $post_type, $prepare_filter, 10, 3 );
				\wp_set_current_user( $fixtures['author'] );
				$private_status_allowed_caps = array_fill_keys(
					array(
						'read',
						'read_page',
						'read_post',
					),
					true
				);
				$private_status_cap_filter = static function ( array $allcaps, array $caps = array() ) use ( $private_status_allowed_caps ): array {
					foreach ( $private_status_allowed_caps as $cap => $grant ) {
						$allcaps[ $cap ] = $grant;
					}
					foreach ( $caps as $cap ) {
						if ( isset( $private_status_allowed_caps[ $cap ] ) ) {
							$allcaps[ $cap ] = true;
						} elseif ( 'do_not_allow' !== $cap ) {
							$allcaps[ $cap ] = false;
						}
					}
					$allcaps['read_private_pages'] = false;
					$allcaps['read_private_posts'] = false;
					$allcaps['edit_pages']         = false;
					$allcaps['edit_posts']         = false;

					return $allcaps;
				};
				\add_filter( 'user_has_cap', $private_status_cap_filter, 10, 4 );
				$private_denied_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'GET',
						'/wp/v2/' . $rest_base,
						array(
							'_fields'  => 'id,parent,slug,status,menu_order,_links',
							'context'  => 'view',
							'page'     => 1,
							'parent'   => array( $parent_a_id ),
							'per_page' => 1,
							'status'   => array( 'private' ),
						)
					)
				);
				\remove_filter( 'user_has_cap', $private_status_cap_filter, 10 );
				$private_status_filter_restored = false === \has_filter( 'user_has_cap', $private_status_cap_filter );
				$private_status_cap_filter      = null;
				$cap_filter = self::install_cap_filter(
					array(
						'edit_others_pages',
						'edit_page',
						'edit_pages',
						'edit_post',
						'edit_posts',
						'read',
						'read_page',
						'read_post',
					)
				);

				$parent_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'GET',
						'/wp/v2/' . $rest_base,
						array(
							'_fields'  => 'id,parent,slug,status,menu_order,_links',
							'context'  => 'edit',
							'order'    => 'asc',
							'orderby'  => 'menu_order',
							'page'     => 1,
							'parent'   => array( $parent_a_id ),
							'per_page' => 2,
						)
					)
				);
				$exclude_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'HEAD',
						'/wp/v2/' . $rest_base,
						array(
							'context'        => 'view',
							'order'          => 'asc',
							'orderby'        => 'parent',
							'page'           => 1,
							'parent_exclude' => array( $parent_a_id ),
							'per_page'       => 2,
						)
					)
				);
				$root_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'GET',
						'/wp/v2/' . $rest_base,
						array(
							'_fields'  => 'id,parent,slug',
							'context'  => 'view',
							'page'     => 1,
							'parent'   => array( 0 ),
							'per_page' => 1,
						)
					)
				);
				$private_status_broad_filter_removed = false;
				if ( null !== $cap_filter ) {
					$private_status_broad_filter_removed = self::remove_cap_filter( $cap_filter );
					$cap_filter                          = null;
				}
				$private_status_allowed_caps = array_fill_keys(
					array(
						'read',
						'read_page',
						'read_post',
						'read_private_pages',
						'read_private_posts',
					),
					true
				);
				$private_status_cap_filter = static function ( array $allcaps, array $caps = array() ) use ( $private_status_allowed_caps ): array {
					foreach ( $private_status_allowed_caps as $cap => $grant ) {
						$allcaps[ $cap ] = $grant;
					}
					foreach ( $caps as $cap ) {
						if ( isset( $private_status_allowed_caps[ $cap ] ) ) {
							$allcaps[ $cap ] = true;
						} elseif ( 'do_not_allow' !== $cap ) {
							$allcaps[ $cap ] = false;
						}
					}
					$allcaps['edit_pages']   = false;
					$allcaps['edit_posts']   = false;
					$allcaps['publish_pages'] = false;

					return $allcaps;
				};
				\add_filter( 'user_has_cap', $private_status_cap_filter, 10, 4 );
				$private_allowed_response = self::dispatch_with_rest_post_dispatch(
					$server,
					self::request(
						'GET',
						'/wp/v2/' . $rest_base,
						array(
							'_fields'  => 'id,parent,slug,status,menu_order,_links',
							'context'  => 'view',
							'orderby'  => 'menu_order',
							'page'     => 1,
							'parent'   => array( $parent_a_id ),
							'per_page' => 1,
							'status'   => array( 'private' ),
						)
					)
				);
				\remove_filter( 'user_has_cap', $private_status_cap_filter, 10 );
				$private_status_filter_restored = $private_status_filter_restored
					&& false === \has_filter( 'user_has_cap', $private_status_cap_filter );
				$private_status_cap_filter      = null;

				$parent_data     = $parent_response instanceof \WP_REST_Response ? $parent_response->get_data() : array();
				$parent_headers  = $parent_response instanceof \WP_REST_Response ? $parent_response->get_headers() : array();
				$exclude_data    = $exclude_response instanceof \WP_REST_Response ? $exclude_response->get_data() : array();
				$exclude_headers = $exclude_response instanceof \WP_REST_Response ? $exclude_response->get_headers() : array();
				$root_data       = $root_response instanceof \WP_REST_Response ? $root_response->get_data() : array();
				$root_headers    = $root_response instanceof \WP_REST_Response ? $root_response->get_headers() : array();
				$private_denied_data = $private_denied_response instanceof \WP_REST_Response ? $private_denied_response->get_data() : array();
				$private_allowed_data = $private_allowed_response instanceof \WP_REST_Response ? $private_allowed_response->get_data() : array();
				$private_allowed_headers = $private_allowed_response instanceof \WP_REST_Response ? $private_allowed_response->get_headers() : array();
				$expected_up     = \rest_url( \rest_get_route_for_post( $parent_a_id ) );

				$observed = array(
					'postType'      => $post_type,
					'restBase'      => $rest_base,
					'responses'     => array(
						'parent'        => array(
							'status'  => $parent_response instanceof \WP_REST_Response ? $parent_response->get_status() : null,
							'data'    => $parent_data,
							'headers' => $parent_headers,
						),
						'parentExclude' => array(
							'status'  => $exclude_response instanceof \WP_REST_Response ? $exclude_response->get_status() : null,
							'data'    => $exclude_data,
							'headers' => $exclude_headers,
						),
						'root'          => array(
							'status'  => $root_response instanceof \WP_REST_Response ? $root_response->get_status() : null,
							'data'    => $root_data,
							'headers' => $root_headers,
						),
						'privateDenied' => array(
							'status' => $private_denied_response instanceof \WP_REST_Response ? $private_denied_response->get_status() : null,
							'data'   => $private_denied_data,
						),
						'privateAllowed' => array(
							'status'  => $private_allowed_response instanceof \WP_REST_Response ? $private_allowed_response->get_status() : null,
							'data'    => $private_allowed_data,
							'headers' => $private_allowed_headers,
							'broadFilterRemoved' => $private_status_broad_filter_removed,
							'strictFilterRestored' => $private_status_filter_restored,
						),
					),
					'restArgs'      => $rest_args,
					'queryVars'     => $query_vars,
					'prepareEvents' => $prepare_events,
					'expected'      => array(
						'parentIds' => array( $child_a2_id, $child_a1_id ),
						'privateId' => $private_child_id,
						'rootId'    => $root_id,
						'upHref'    => $expected_up,
					),
				);

				self::collect_failure(
					$failures,
					$parent_response instanceof \WP_REST_Response
						&& 200 === $parent_response->get_status()
						&& 2 === count( $parent_data )
						&& array( $child_a2_id, $child_a1_id ) === array_values( array_map( static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ), $parent_data ) )
						&& self::projected_keys_match( $parent_data[0] ?? array(), array( '_links', 'id', 'menu_order', 'parent', 'slug', 'status' ) )
						&& self::projected_keys_match( $parent_data[1] ?? array(), array( '_links', 'id', 'menu_order', 'parent', 'slug', 'status' ) )
						&& $parent_a_id === (int) ( $parent_data[0]['parent'] ?? 0 )
						&& $parent_a_id === (int) ( $parent_data[1]['parent'] ?? 0 )
						&& 1 === (int) ( $parent_data[0]['menu_order'] ?? 0 )
						&& 2 === (int) ( $parent_data[1]['menu_order'] ?? 0 )
						&& $expected_up === self::link_href( is_array( $parent_data[0]['_links'] ?? null ) ? $parent_data[0]['_links'] : array(), 'up' )
						&& $expected_up === self::link_href( is_array( $parent_data[1]['_links'] ?? null ) ? $parent_data[1]['_links'] : array(), 'up' )
						&& (string) $totals_by_case['parent'] === (string) ( $parent_headers['X-WP-Total'] ?? '' )
						&& (string) ceil( $totals_by_case['parent'] / 2 ) === (string) ( $parent_headers['X-WP-TotalPages'] ?? '' )
						&& self::header_has_link_rel( $parent_headers, 'next' ),
					'custom hierarchical collection GET applies parent filter, menu_order sorting, projection, pagination, and up links',
					$observed['responses']['parent']
				);

				self::collect_failure(
					$failures,
					$exclude_response instanceof \WP_REST_Response
						&& 200 === $exclude_response->get_status()
						&& array() === $exclude_data
						&& (string) $totals_by_case['parent_exclude'] === (string) ( $exclude_headers['X-WP-Total'] ?? '' )
						&& (string) ceil( $totals_by_case['parent_exclude'] / 2 ) === (string) ( $exclude_headers['X-WP-TotalPages'] ?? '' ),
					'custom hierarchical collection HEAD applies parent_exclude while returning only pagination headers',
					$observed['responses']['parentExclude']
				);

				self::collect_failure(
					$failures,
					$root_response instanceof \WP_REST_Response
						&& 200 === $root_response->get_status()
						&& 1 === count( $root_data )
						&& self::projected_keys_match( $root_data[0] ?? array(), array( 'id', 'parent', 'slug' ) )
						&& $root_id === (int) ( $root_data[0]['id'] ?? 0 )
						&& 0 === (int) ( $root_data[0]['parent'] ?? -1 )
						&& $root_slug === ( $root_data[0]['slug'] ?? null )
						&& (string) $totals_by_case['root'] === (string) ( $root_headers['X-WP-Total'] ?? '' )
						&& '1' === (string) ( $root_headers['X-WP-TotalPages'] ?? '' ),
					'custom hierarchical collection GET maps parent=0 to root item filtering',
					$observed['responses']['root']
				);

				$private_allowed_item = is_array( $private_allowed_data[0] ?? null ) ? $private_allowed_data[0] : array();
				self::collect_failure(
					$failures,
					$private_status_filter_restored
						&& $private_status_broad_filter_removed
						&& $private_denied_response instanceof \WP_REST_Response
						&& 400 === $private_denied_response->get_status()
						&& 'rest_invalid_param' === ( $private_denied_data['code'] ?? null )
						&& 'rest_forbidden_status' === ( $private_denied_data['data']['details']['status']['code'] ?? null )
						&& 403 === (int) ( $private_denied_data['data']['details']['status']['data']['status'] ?? 0 )
						&& $private_allowed_response instanceof \WP_REST_Response
						&& 200 === $private_allowed_response->get_status()
						&& 1 === count( $private_allowed_data )
						&& self::projected_keys_match( $private_allowed_item, array( '_links', 'id', 'menu_order', 'parent', 'slug', 'status' ) )
						&& $private_child_id === (int) ( $private_allowed_item['id'] ?? 0 )
						&& $parent_a_id === (int) ( $private_allowed_item['parent'] ?? 0 )
						&& 5 === (int) ( $private_allowed_item['menu_order'] ?? 0 )
						&& 'private' === ( $private_allowed_item['status'] ?? null )
						&& $private_child_slug === ( $private_allowed_item['slug'] ?? null )
						&& $expected_up === self::link_href( is_array( $private_allowed_item['_links'] ?? null ) ? $private_allowed_item['_links'] : array(), 'up' )
						&& (string) $totals_by_case['private_status'] === (string) ( $private_allowed_headers['X-WP-Total'] ?? '' )
						&& '1' === (string) ( $private_allowed_headers['X-WP-TotalPages'] ?? '' ),
					'custom hierarchical collection status=private remains capability-gated and returns parent-scoped private child when allowed',
					array(
						'privateDenied'  => $observed['responses']['privateDenied'],
						'privateAllowed' => $observed['responses']['privateAllowed'],
					)
				);

				self::collect_failure(
					$failures,
					4 === count( $rest_args )
						&& 4 === count( $query_vars )
						&& 'GET' === ( $rest_args[0]['method'] ?? null )
						&& 'parent' === ( $rest_args[0]['args']['component_fuzz_custom_hier_collection'] ?? null )
						&& array( $parent_a_id ) === array_values( array_map( 'intval', (array) ( $rest_args[0]['args']['post_parent__in'] ?? array() ) ) )
						&& 'menu_order' === ( $rest_args[0]['args']['orderby'] ?? null )
						&& 'menu_order' === ( $query_vars[0]['orderby'] ?? null )
						&& 'asc' === strtolower( (string) ( $query_vars[0]['order'] ?? '' ) )
						&& true === ( $query_vars[0]['no_found_rows'] ?? null )
						&& $post_type === ( $query_vars[0]['post_type'] ?? null )
						&& array( $parent_a_id ) === array_values( array_map( 'intval', (array) ( $query_vars[0]['post_parent__in'] ?? array() ) ) )
						&& 'HEAD' === ( $rest_args[1]['method'] ?? null )
						&& 'parent_exclude' === ( $rest_args[1]['args']['component_fuzz_custom_hier_collection'] ?? null )
						&& array( $parent_a_id ) === array_values( array_map( 'intval', (array) ( $rest_args[1]['args']['post_parent__not_in'] ?? array() ) ) )
						&& 'parent' === ( $query_vars[1]['orderby'] ?? null )
						&& 'ids' === ( $query_vars[1]['fields'] ?? null )
						&& true === ( $query_vars[1]['no_found_rows'] ?? null )
						&& false === ( $query_vars[1]['update_post_meta_cache'] ?? null )
						&& false === ( $query_vars[1]['update_post_term_cache'] ?? null )
						&& $post_type === ( $query_vars[1]['post_type'] ?? null )
						&& array( $parent_a_id ) === array_values( array_map( 'intval', (array) ( $query_vars[1]['post_parent__not_in'] ?? array() ) ) )
						&& 'root' === ( $rest_args[2]['args']['component_fuzz_custom_hier_collection'] ?? null )
						&& array( 0 ) === array_values( array_map( 'intval', (array) ( $rest_args[2]['args']['post_parent__in'] ?? array() ) ) )
						&& true === ( $query_vars[2]['no_found_rows'] ?? null )
						&& $post_type === ( $query_vars[2]['post_type'] ?? null )
						&& array( 0 ) === array_values( array_map( 'intval', (array) ( $query_vars[2]['post_parent__in'] ?? array() ) ) )
						&& 'GET' === ( $rest_args[3]['method'] ?? null )
						&& 'private_status' === ( $rest_args[3]['args']['component_fuzz_custom_hier_collection'] ?? null )
						&& array( 'private' ) === array_values( array_map( 'strval', (array) ( $rest_args[3]['args']['post_status'] ?? array() ) ) )
						&& array( $parent_a_id ) === array_values( array_map( 'intval', (array) ( $rest_args[3]['args']['post_parent__in'] ?? array() ) ) )
						&& 'menu_order' === ( $query_vars[3]['orderby'] ?? null )
						&& array( 'private' ) === array_values( array_map( 'strval', (array) ( $query_vars[3]['post_status'] ?? array() ) ) )
						&& true === ( $query_vars[3]['no_found_rows'] ?? null )
						&& $post_type === ( $query_vars[3]['post_type'] ?? null )
						&& array( $parent_a_id ) === array_values( array_map( 'intval', (array) ( $query_vars[3]['post_parent__in'] ?? array() ) ) ),
					'custom hierarchical collection parent filters map through rest query args into WP_Query vars',
					array(
						'restArgs'  => $rest_args,
						'queryVars' => $query_vars,
					)
				);

				self::collect_failure(
					$failures,
					array( $child_a2_id, $child_a1_id, $root_id, $private_child_id ) === array_values( array_map( static fn ( array $event ): int => (int) ( $event['id'] ?? 0 ), $prepare_events ) )
						&& array( 'edit', 'edit', 'view', 'view' ) === array_values( array_map( static fn ( array $event ): string => (string) ( $event['context'] ?? '' ), $prepare_events ) )
						&& array( 'GET', 'GET', 'GET', 'GET' ) === array_values( array_map( static fn ( array $event ): string => (string) ( $event['method'] ?? '' ), $prepare_events ) )
						&& array( '/wp/v2/' . $rest_base, '/wp/v2/' . $rest_base, '/wp/v2/' . $rest_base, '/wp/v2/' . $rest_base ) === array_values( array_map( static fn ( array $event ): string => (string) ( $event['route'] ?? '' ), $prepare_events ) ),
					'custom hierarchical collection prepare hook receives only GET item responses and preserves request context',
					array( 'prepareEvents' => $prepare_events )
				);
			}
		} finally {
			\remove_filter( 'rest_' . $post_type . '_query', $rest_filter, 10 );
			\remove_filter( 'posts_pre_query', $pre_filter, 10 );
			\remove_filter( 'rest_prepare_' . $post_type, $prepare_filter, 10 );
			$custom_filters_restored = false === \has_filter( 'rest_' . $post_type . '_query', $rest_filter )
				&& false === \has_filter( 'posts_pre_query', $pre_filter )
				&& false === \has_filter( 'rest_prepare_' . $post_type, $prepare_filter );

			$delete_posts();
			$counts_after_cleanup = self::content_counts();

			if ( null !== $private_status_cap_filter ) {
				\remove_filter( 'user_has_cap', $private_status_cap_filter, 10 );
			}
			$private_status_filter_restored = $private_status_filter_restored
				|| null === $private_status_cap_filter
				|| false === \has_filter( 'user_has_cap', $private_status_cap_filter );

			if ( null !== $cap_filter ) {
				$cap_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter          = null;
			} else {
				$cap_filter_restored = true;
			}

			self::restore_rest_default_filters( $filter_snapshot );
			$default_filters_restored = $filter_snapshot === self::rest_default_filter_state();

			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			$permalink_filter_restored = false === \has_filter( 'pre_option_permalink_structure', $permalink_filter );

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

			\wp_set_current_user( $previous_current_user_id );

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

			$rewrite_restored = $previous_rewrite_set === array_key_exists( 'wp_rewrite', $GLOBALS )
				&& ( ! $previous_rewrite_set || $previous_rewrite === $GLOBALS['wp_rewrite'] );
			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
			$post_type_restored = $had_post_type === isset( $GLOBALS['wp_post_types'][ $post_type ] )
				&& ( ! $had_post_type || $previous_post_type === $GLOBALS['wp_post_types'][ $post_type ] );
			$query_vars_restored = null === $previous_query_vars
				|| ! ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && property_exists( $GLOBALS['wp'], 'public_query_vars' ) )
				|| $previous_query_vars === $GLOBALS['wp']->public_query_vars;
		}

		$observed['counts'] = array(
			'before' => $counts_before,
			'after'  => $counts_after_cleanup,
		);
		self::collect_failure(
			$failures,
			self::content_count_delta_matches( $counts_before, $counts_after_cleanup, array(), array() ),
			'custom hierarchical collection parent filter fixtures are deleted after route dispatch',
			$observed['counts']
		);
		self::collect_failure(
			$failures,
			$custom_filters_restored
				&& $cap_filter_restored
				&& $private_status_filter_restored
				&& $default_filters_restored
				&& $permalink_filter_restored
				&& $rewrite_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored
				&& $post_type_restored
				&& $query_vars_restored,
			'custom hierarchical collection parent filters restore REST filters, caps, private-status caps, permalink, rewrite, server, actions, current user, post type, and query vars',
			array(
				'customFiltersRestored'   => $custom_filters_restored,
				'capFilterRestored'       => $cap_filter_restored,
				'privateStatusFilterRestored' => $private_status_filter_restored,
				'defaultFiltersRestored'  => $default_filters_restored,
				'permalinkFilterRestored' => $permalink_filter_restored,
				'rewriteRestored'         => $rewrite_restored,
				'serverRestored'          => $server_restored,
				'actionsRestored'         => $actions_restored,
				'currentUserRestored'     => $current_user_restored,
				'postTypeRestored'        => $post_type_restored,
				'queryVarsRestored'       => $query_vars_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.collections.custom-hierarchical-parent-filters',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'postType' => $post_type,
				'restBase' => $rest_base,
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_route_dispatched_object_force_delete_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$observed = array();

		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$cap_filter               = null;
		$write_filter_restored    = false;
		$email_filter_restored    = false;
		$sanitize_filter_restored = false;
		$server_restored          = false;
		$actions_restored         = false;
		$current_user_restored    = false;

		try {
			$controllers = array(
				new \WP_REST_Posts_Controller( 'post' ),
				new \WP_REST_Terms_Controller( 'category' ),
				new \WP_REST_Comments_Controller(),
				new \WP_REST_Users_Controller(),
			);

			foreach ( $controllers as $controller ) {
				$controller->register_routes();
			}

			$force_post_title       = 'Force Delete REST Post ' . $case['token'];
			$force_post_content     = '<p>Force delete content ' . $case['token'] . '</p>';
			$force_post_slug        = 'Force Delete REST Slug ' . $case['token'];
			$force_term_name        = 'Force Delete Term ' . $case['token'];
			$force_term_slug        = 'Force Delete Term Slug ' . $case['token'];
			$force_comment_body     = 'Force delete comment ' . $case['token'];
			$force_comment_email    = 'force-comment-' . $case['token'] . '@example.com';
			$force_user_login       = 'cfz_force_user_' . $case['token'];
			$force_user_email       = 'force-user-' . $case['token'] . '@example.com';
			$force_user_name        = 'Force Delete User ' . $case['token'];
			$force_user_password    = 'force-pass-' . $case['token'] . '-A1';
			$force_user_slug        = 'Force Delete User ' . $case['token'];
			$reassigned_post_title  = 'Force Reassigned Post ' . $case['token'];
			$reassigned_post_slug   = 'Force Reassigned Slug ' . $case['token'];
			$force_post_meta_key    = $case['postMetaKey'] . '_force_delete';
			$force_term_meta_key    = 'cfz_force_term_meta_' . $case['token'];
			$force_comment_meta_key = 'cfz_force_comment_meta_' . $case['token'];
			$force_user_meta_key    = 'cfz_force_user_meta_' . $case['token'];
			$accepted_emails        = array_fill_keys(
				array(
					$force_comment_email,
					$force_user_email,
				),
				true
			);
			$email_filter           = static function ( $is_email, string $email ) use ( $accepted_emails ) {
				return isset( $accepted_emails[ $email ] ) ? $email : $is_email;
			};
			$sanitize_email_filter  = static function ( string $sanitized, string $email ) use ( $accepted_emails ): string {
				return isset( $accepted_emails[ $email ] ) ? $email : $sanitized;
			};

			$counts_before = self::content_counts();

			\wp_set_current_user( 0 );
			$denied_delete_response = $server->dispatch(
				self::request(
					'DELETE',
					'/wp/v2/posts/' . $fixtures['post'],
					array(),
					array(),
					array( 'force' => true )
				)
			);
			$counts_after_denial = self::content_counts();

			\wp_set_current_user( $fixtures['author'] );
			$cap_filter = self::install_cap_filter(
				array(
					'create_users',
					'delete_categories',
					'delete_comment',
					'delete_comments',
					'delete_post',
					'delete_posts',
					'delete_user',
					'delete_users',
					'edit_categories',
					'edit_comment',
					'edit_comments',
					'edit_others_posts',
					'edit_post',
					'edit_posts',
					'edit_published_posts',
					'edit_term',
					'edit_terms',
					'edit_user',
					'edit_users',
					'list_users',
					'manage_categories',
					'moderate_comments',
					'publish_posts',
					'read',
				)
			);
			\add_filter( 'is_email', $email_filter, 10, 2 );
			\add_filter( 'sanitize_email', $sanitize_email_filter, 10, 2 );

			try {
				$force_post_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/posts',
						array( '_fields' => 'author,content,id,slug,status,title' ),
						array(),
						array(
							'author'  => $fixtures['author'],
							'content' => $force_post_content,
							'slug'    => $force_post_slug,
							'status'  => 'publish',
							'title'   => $force_post_title,
						)
					)
				);
				$force_post_data = $force_post_response instanceof \WP_REST_Response ? $force_post_response->get_data() : array();
				$force_post_id   = (int) ( $force_post_data['id'] ?? 0 );
				if ( $force_post_id > 0 ) {
					\update_post_meta( $force_post_id, $force_post_meta_key, 'force-post-meta-' . $case['token'] );
				}

				$force_term_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/categories',
						array( '_fields' => 'id,name,parent,slug,taxonomy' ),
						array(),
						array(
							'description' => 'Force term description ' . $case['token'],
							'name'        => $force_term_name,
							'parent'      => $fixtures['term'],
							'slug'        => $force_term_slug,
						)
					)
				);
				$force_term_data = $force_term_response instanceof \WP_REST_Response ? $force_term_response->get_data() : array();
				$force_term_id   = (int) ( $force_term_data['id'] ?? 0 );
				if ( $force_term_id > 0 ) {
					\update_term_meta( $force_term_id, $force_term_meta_key, 'force-term-meta-' . $case['token'] );
				}

				$force_comment_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/comments',
						array( '_fields' => 'content,id,parent,post,status,type' ),
						array(),
						array(
							'author'       => $fixtures['author'],
							'author_email' => $force_comment_email,
							'author_name'  => $case['commentAuthorName'],
							'content'      => $force_comment_body,
							'parent'       => 0,
							'post'         => $fixtures['post'],
							'status'       => 'approve',
							'type'         => 'comment',
						)
					)
				);
				$force_comment_data = $force_comment_response instanceof \WP_REST_Response ? $force_comment_response->get_data() : array();
				$force_comment_id   = (int) ( $force_comment_data['id'] ?? 0 );
				if ( $force_comment_id > 0 ) {
					\update_comment_meta( $force_comment_id, $force_comment_meta_key, 'force-comment-meta-' . $case['token'] );
				}

				$force_user_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/users',
						array( '_fields' => 'email,id,name,slug,username' ),
						array(),
						array(
							'email'    => $force_user_email,
							'name'     => $force_user_name,
							'password' => $force_user_password,
							'slug'     => $force_user_slug,
							'username' => $force_user_login,
						)
					)
				);
				$force_user_data = $force_user_response instanceof \WP_REST_Response ? $force_user_response->get_data() : array();
				$force_user_id   = (int) ( $force_user_data['id'] ?? 0 );
				if ( $force_user_id > 0 ) {
					\update_user_meta( $force_user_id, $force_user_meta_key, 'force-user-meta-' . $case['token'] );
				}

				$reassigned_post_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/posts',
						array( '_fields' => 'author,id,slug,title' ),
						array(),
						array(
							'author' => $force_user_id,
							'slug'   => $reassigned_post_slug,
							'status' => 'publish',
							'title'  => $reassigned_post_title,
						)
					)
				);
				$reassigned_post_data = $reassigned_post_response instanceof \WP_REST_Response ? $reassigned_post_response->get_data() : array();
				$reassigned_post_id   = (int) ( $reassigned_post_data['id'] ?? 0 );

				$counts_after_setup = self::content_counts();

				$invalid_reassign_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/users/' . $force_user_id,
						array(),
						array(),
						array(
							'force'    => true,
							'reassign' => $force_user_id,
						)
					)
				);
				$counts_after_invalid_reassign = self::content_counts();
				$post_after_invalid_reassign   = \get_post( $reassigned_post_id );
				$user_after_invalid_reassign   = \get_user_by( 'id', $force_user_id );

				$post_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/posts/' . $force_post_id,
						array(),
						array(),
						array( 'force' => true )
					)
				);
				$term_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/categories/' . $force_term_id,
						array(),
						array(),
						array( 'force' => true )
					)
				);
				$comment_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/comments/' . $force_comment_id,
						array(),
						array(),
						array( 'force' => true )
					)
				);
				$user_delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/users/' . $force_user_id,
						array(),
						array(),
						array(
							'force'    => true,
							'reassign' => $fixtures['author'],
						)
					)
				);
			} finally {
				\remove_filter( 'sanitize_email', $sanitize_email_filter, 10 );
				$sanitize_filter_restored = false === \has_filter( 'sanitize_email', $sanitize_email_filter );
				\remove_filter( 'is_email', $email_filter, 10 );
				$email_filter_restored = false === \has_filter( 'is_email', $email_filter );
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter            = null;
			}

			$counts_after = self::content_counts();
			$post_delete_data = $post_delete_response instanceof \WP_REST_Response ? $post_delete_response->get_data() : array();
			$term_delete_data = $term_delete_response instanceof \WP_REST_Response ? $term_delete_response->get_data() : array();
			$comment_delete_data = $comment_delete_response instanceof \WP_REST_Response ? $comment_delete_response->get_data() : array();
			$user_delete_data = $user_delete_response instanceof \WP_REST_Response ? $user_delete_response->get_data() : array();
			$deleted_post = \get_post( $force_post_id );
			$deleted_term = \get_term( $force_term_id, 'category' );
			$deleted_comment = \get_comment( $force_comment_id );
			$deleted_user = \get_user_by( 'id', $force_user_id );
			$reassigned_post_after_delete = \get_post( $reassigned_post_id );

			$observed = array(
				'deniedDelete'           => $denied_delete_response instanceof \WP_REST_Response ? $denied_delete_response->get_data() : $denied_delete_response,
				'created'                => array(
					'post'           => $force_post_data,
					'term'           => $force_term_data,
					'comment'        => $force_comment_data,
					'user'           => $force_user_data,
					'reassignedPost' => $reassigned_post_data,
				),
				'invalidReassign'        => $invalid_reassign_response instanceof \WP_REST_Response ? $invalid_reassign_response->get_data() : $invalid_reassign_response,
				'deleteResponses'        => array(
					'post'    => $post_delete_data,
					'term'    => $term_delete_data,
					'comment' => $comment_delete_data,
					'user'    => $user_delete_data,
				),
				'storedAfterInvalid'      => array(
					'userExists'  => $user_after_invalid_reassign instanceof \WP_User,
					'postAuthor'  => $post_after_invalid_reassign instanceof \WP_Post ? (int) $post_after_invalid_reassign->post_author : null,
				),
				'storedAfterDelete'       => array(
					'postExists'           => $deleted_post instanceof \WP_Post,
					'termExists'           => $deleted_term instanceof \WP_Term,
					'commentExists'        => $deleted_comment instanceof \WP_Comment,
					'userExists'           => $deleted_user instanceof \WP_User,
					'reassignedPostAuthor' => $reassigned_post_after_delete instanceof \WP_Post ? (int) $reassigned_post_after_delete->post_author : null,
				),
				'metadataAfterDelete'     => array(
					'post'    => $force_post_id > 0 ? \get_post_meta( $force_post_id, $force_post_meta_key, true ) : null,
					'term'    => $force_term_id > 0 ? \get_term_meta( $force_term_id, $force_term_meta_key, true ) : null,
					'comment' => $force_comment_id > 0 ? \get_comment_meta( $force_comment_id, $force_comment_meta_key, true ) : null,
					'user'    => $force_user_id > 0 ? \get_user_meta( $force_user_id, $force_user_meta_key, true ) : null,
				),
				'countsBefore'           => $counts_before,
				'countsDenied'           => $counts_after_denial,
				'countsAfterSetup'       => $counts_after_setup,
				'countsAfterInvalid'     => $counts_after_invalid_reassign,
				'countsAfter'            => $counts_after,
				'filtersRestored'        => array(
					'cap'           => $write_filter_restored,
					'email'         => $email_filter_restored,
					'sanitizeEmail' => $sanitize_filter_restored,
				),
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $denied_delete_response, 'rest_cannot_delete', 401 )
					&& self::content_count_delta_matches( $counts_before, $counts_after_denial, array(), array() ),
				'route-dispatched force delete denies unauthenticated post deletion before row changes',
				array(
					'deniedDelete' => $observed['deniedDelete'],
					'countsBefore' => $counts_before,
					'countsDenied' => $counts_after_denial,
				)
			);

			self::collect_failure(
				$failures,
				$force_post_response instanceof \WP_REST_Response
					&& 201 === $force_post_response->get_status()
					&& $force_post_id > 0
					&& $force_post_title === ( $force_post_data['title']['raw'] ?? null )
					&& $force_term_response instanceof \WP_REST_Response
					&& 201 === $force_term_response->get_status()
					&& $force_term_id > 0
					&& $force_term_name === ( $force_term_data['name'] ?? null )
					&& $force_comment_response instanceof \WP_REST_Response
					&& 201 === $force_comment_response->get_status()
					&& $force_comment_id > 0
					&& $force_comment_body === ( $force_comment_data['content']['raw'] ?? null )
					&& $force_user_response instanceof \WP_REST_Response
					&& 201 === $force_user_response->get_status()
					&& $force_user_id > 0
					&& $force_user_login === ( $force_user_data['username'] ?? null )
					&& $reassigned_post_response instanceof \WP_REST_Response
					&& 201 === $reassigned_post_response->get_status()
					&& $reassigned_post_id > 0
					&& $force_user_id === (int) ( $reassigned_post_data['author'] ?? 0 )
					&& self::content_count_delta_matches(
						$counts_before,
						$counts_after_setup,
						array(
							'comments'      => 1,
							'comment_meta'  => 1,
							'post_meta'     => 1,
							'posts'         => 2,
							'term_meta'     => 1,
							'term_taxonomy' => 1,
							'terms'         => 1,
							'users'         => 1,
						),
						array( 'user_meta' )
					),
				'route-dispatched setup creates force-delete targets, metadata, and reassignment content',
				$observed['created']
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $invalid_reassign_response, 'rest_user_invalid_reassign', 400 )
					&& $user_after_invalid_reassign instanceof \WP_User
					&& $post_after_invalid_reassign instanceof \WP_Post
					&& $force_user_id === (int) $post_after_invalid_reassign->post_author
					&& self::content_count_delta_matches( $counts_after_setup, $counts_after_invalid_reassign, array(), array() ),
				'route-dispatched user force delete rejects self-reassign before deleting or reassigning rows',
				array(
					'invalidReassign'   => $observed['invalidReassign'],
					'storedAfterInvalid' => $observed['storedAfterInvalid'],
					'countsAfterSetup'  => $counts_after_setup,
					'countsAfterInvalid' => $counts_after_invalid_reassign,
				)
			);

			self::collect_failure(
				$failures,
				$post_delete_response instanceof \WP_REST_Response
					&& 200 === $post_delete_response->get_status()
					&& true === ( $post_delete_data['deleted'] ?? null )
					&& $force_post_id === (int) ( $post_delete_data['previous']['id'] ?? 0 )
					&& $force_post_title === ( $post_delete_data['previous']['title']['raw'] ?? null )
					&& $term_delete_response instanceof \WP_REST_Response
					&& 200 === $term_delete_response->get_status()
					&& true === ( $term_delete_data['deleted'] ?? null )
					&& $force_term_id === (int) ( $term_delete_data['previous']['id'] ?? 0 )
					&& $force_term_name === ( $term_delete_data['previous']['name'] ?? null )
					&& $comment_delete_response instanceof \WP_REST_Response
					&& 200 === $comment_delete_response->get_status()
					&& true === ( $comment_delete_data['deleted'] ?? null )
					&& $force_comment_id === (int) ( $comment_delete_data['previous']['id'] ?? 0 )
					&& $force_comment_body === ( $comment_delete_data['previous']['content']['raw'] ?? null )
					&& $user_delete_response instanceof \WP_REST_Response
					&& 200 === $user_delete_response->get_status()
					&& true === ( $user_delete_data['deleted'] ?? null )
					&& $force_user_id === (int) ( $user_delete_data['previous']['id'] ?? 0 )
					&& $force_user_email === ( $user_delete_data['previous']['email'] ?? null )
					&& ! ( $deleted_post instanceof \WP_Post )
					&& ! ( $deleted_term instanceof \WP_Term )
					&& ! ( $deleted_comment instanceof \WP_Comment )
					&& ! ( $deleted_user instanceof \WP_User )
					&& $reassigned_post_after_delete instanceof \WP_Post
					&& $fixtures['author'] === (int) $reassigned_post_after_delete->post_author
					&& '' === (string) $observed['metadataAfterDelete']['post']
					&& '' === (string) $observed['metadataAfterDelete']['term']
					&& '' === (string) $observed['metadataAfterDelete']['comment']
					&& '' === (string) $observed['metadataAfterDelete']['user']
					&& self::content_count_delta_matches(
						$counts_before,
						$counts_after,
						array( 'posts' => 1 ),
						array()
					),
				'route-dispatched force deletes remove target rows and metadata while reassigning user-owned content',
				$observed
			);
		} finally {
			if ( isset( $sanitize_email_filter ) && false !== \has_filter( 'sanitize_email', $sanitize_email_filter ) ) {
				\remove_filter( 'sanitize_email', $sanitize_email_filter, 10 );
				$sanitize_filter_restored = false === \has_filter( 'sanitize_email', $sanitize_email_filter );
			}
			if ( isset( $email_filter ) && false !== \has_filter( 'is_email', $email_filter ) ) {
				\remove_filter( 'is_email', $email_filter, 10 );
				$email_filter_restored = false === \has_filter( 'is_email', $email_filter );
			}
			if ( null !== $cap_filter ) {
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
			}

			\wp_set_current_user( $previous_current_user_id );

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

			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
		}

		self::collect_failure(
			$failures,
			$write_filter_restored
				&& $email_filter_restored
				&& $sanitize_filter_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored,
			'route-dispatched object force-delete harness restores caps, email filters, server, actions, and current user',
			array(
				'writeFilterRestored'    => $write_filter_restored,
				'emailFilterRestored'    => $email_filter_restored,
				'sanitizeFilterRestored' => $sanitize_filter_restored,
				'serverRestored'         => $server_restored,
				'actionsRestored'        => $actions_restored,
				'currentUserRestored'    => $current_user_restored,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.route-dispatched-force-delete-reassign-cleanup',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_route_dispatched_term_mutation_edges( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$failures = array();
		$observed = array();

		$previous_server          = $GLOBALS['wp_rest_server'] ?? null;
		$had_wp_actions           = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_actions         = $GLOBALS['wp_actions'] ?? null;
		$previous_current_user_id = isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
			? (int) $GLOBALS['current_user']->ID
			: 0;

		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

		$pre_insert_events   = array();
		$insert_events       = array();
		$after_insert_events = array();
		$prepare_events      = array();
		$delete_events       = array();

		$pre_insert_filter = static function ( object $prepared_term, \WP_REST_Request $request ) use ( &$pre_insert_events ): object {
			$pre_insert_events[] = array(
				'method'   => $request->get_method(),
				'name'     => $prepared_term->name ?? null,
				'parent'   => isset( $prepared_term->parent ) ? (int) $prepared_term->parent : null,
				'metaKeys' => array_keys( (array) $request['meta'] ),
			);
			return $prepared_term;
		};
		$insert_action = static function ( \WP_Term $term, \WP_REST_Request $request, bool $creating ) use ( &$insert_events, $case ): void {
			$insert_events[] = array(
				'creating'   => $creating,
				'id'         => (int) $term->term_id,
				'method'     => $request->get_method(),
				'name'       => $term->name,
				'parent'     => (int) $term->parent,
				'metaStored' => \get_term_meta( (int) $term->term_id, $case['termMetaKey'], true ),
			);
		};
		$after_insert_action = static function ( \WP_Term $term, \WP_REST_Request $request, bool $creating ) use ( &$after_insert_events, $case ): void {
			$after_insert_events[] = array(
				'creating'   => $creating,
				'id'         => (int) $term->term_id,
				'method'     => $request->get_method(),
				'name'       => $term->name,
				'parent'     => (int) $term->parent,
				'metaStored' => \get_term_meta( (int) $term->term_id, $case['termMetaKey'], true ),
			);
		};
		$prepare_filter = static function ( \WP_REST_Response $response, \WP_Term $term, \WP_REST_Request $request ) use ( &$prepare_events ): \WP_REST_Response {
			$prepare_events[] = array(
				'id'     => (int) $term->term_id,
				'method' => $request->get_method(),
				'fields' => $request['_fields'],
				'status' => $response->get_status(),
			);
			return $response;
		};
		$delete_action = static function ( \WP_Term $term, \WP_REST_Response $response, \WP_REST_Request $request ) use ( &$delete_events, $case ): void {
			$data            = $response->get_data();
			$delete_events[] = array(
				'id'              => (int) $term->term_id,
				'method'          => $request->get_method(),
				'deleted'         => $data['deleted'] ?? null,
				'previousMeta'    => $data['previous']['meta'][ $case['termMetaKey'] ] ?? null,
				'metaAfterDelete' => \get_term_meta( (int) $term->term_id, $case['termMetaKey'], true ),
			);
		};

		$cap_filter              = null;
		$term_meta_registered    = false;
		$write_filter_restored   = false;
		$pre_filter_restored     = false;
		$insert_filter_restored  = false;
		$after_filter_restored   = false;
		$prepare_filter_restored = false;
		$delete_filter_restored  = false;
		$server_restored         = false;
		$actions_restored        = false;
		$current_user_restored   = false;

		try {
			$term_meta_registered = \register_term_meta(
				'category',
				$case['termMetaKey'],
				array(
					'auth_callback'     => '__return_true',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);

			$category_controller = new \WP_REST_Terms_Controller( 'category' );
			$tag_controller      = new \WP_REST_Terms_Controller( 'post_tag' );

			$category_controller->register_routes();
			$tag_controller->register_routes();

			\add_filter( 'rest_pre_insert_category', $pre_insert_filter, 10, 2 );
			\add_filter( 'rest_insert_category', $insert_action, 10, 3 );
			\add_filter( 'rest_after_insert_category', $after_insert_action, 10, 3 );
			\add_filter( 'rest_prepare_category', $prepare_filter, 10, 3 );
			\add_filter( 'rest_delete_category', $delete_action, 10, 3 );

			$routes                   = $server->get_routes( 'wp/v2' );
			$category_collection_data = $server->get_data_for_route( '/wp/v2/categories', $routes['/wp/v2/categories'] ?? array(), 'help' );
			$category_item_data       = $server->get_data_for_route( '/wp/v2/categories/(?P<id>[\d]+)', $routes['/wp/v2/categories/(?P<id>[\d]+)'] ?? array(), 'help' );
			$category_schema          = $category_controller->get_item_schema();
			$tag_schema               = $tag_controller->get_item_schema();

			$schema_observed = array(
				'termMetaRegistered' => $term_meta_registered,
				'categoryRoutes'     => array(
					'collection' => self::route_methods( $routes['/wp/v2/categories'] ?? array() ),
					'item'       => self::route_methods( $routes['/wp/v2/categories/(?P<id>[\d]+)'] ?? array() ),
				),
				'tagRoutes'          => array(
					'collection' => self::route_methods( $routes['/wp/v2/tags'] ?? array() ),
					'item'       => self::route_methods( $routes['/wp/v2/tags/(?P<id>[\d]+)'] ?? array() ),
				),
				'categorySchemaKeys' => array_keys( $category_schema['properties'] ?? array() ),
				'tagSchemaKeys'      => array_keys( $tag_schema['properties'] ?? array() ),
			);
			self::collect_failure(
				$failures,
				$term_meta_registered
					&& array( 'GET', 'POST' ) === $schema_observed['categoryRoutes']['collection']
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $schema_observed['categoryRoutes']['item']
					&& array( 'GET', 'POST' ) === $schema_observed['tagRoutes']['collection']
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $schema_observed['tagRoutes']['item']
					&& isset( $category_schema['properties']['parent'] )
					&& ! isset( $tag_schema['properties']['parent'] )
					&& isset( $category_schema['properties']['meta']['properties'][ $case['termMetaKey'] ] )
					&& self::route_data_has_endpoint_args( $category_collection_data, array( 'POST' ), array( 'description', 'meta', 'name', 'parent', 'slug' ) )
					&& self::route_data_has_endpoint_args( $category_item_data, array( 'PATCH', 'POST', 'PUT' ), array( 'description', 'meta', 'name', 'parent', 'slug' ) )
					&& self::route_data_schema_has_properties( $category_collection_data, array( 'id', 'meta', 'name', 'parent', 'slug', 'taxonomy' ) ),
				'route-dispatched term routes expose hierarchical parent args and registered term meta schema',
				$schema_observed
			);

			$counts_before = self::content_counts();

			\wp_set_current_user( 0 );
			$denied_create_response = $server->dispatch(
				self::request(
					'POST',
					'/wp/v2/categories',
					array( '_fields' => 'id,name,meta' ),
					array(),
					array(
						'meta' => array( $case['termMetaKey'] => $case['termMetaInput'] ),
						'name' => 'Denied Term ' . $case['token'],
					)
				)
			);
			$counts_after_denial = self::content_counts();
			$hook_counts_after_denial = array(
				'preInsert'   => count( $pre_insert_events ),
				'insert'      => count( $insert_events ),
				'afterInsert' => count( $after_insert_events ),
			);

			\wp_set_current_user( $fixtures['author'] );
			$cap_filter = self::install_cap_filter(
				array(
					'add_term_meta',
					'delete_categories',
					'delete_term',
					'delete_term_meta',
					'delete_terms',
					'edit_categories',
					'edit_term',
					'edit_term_meta',
					'edit_terms',
					'manage_categories',
					'read',
				)
			);

			try {
				$invalid_parent_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/categories',
						array( '_fields' => 'id,name,parent' ),
						array(),
						array(
							'name'   => 'Invalid Parent Term ' . $case['token'],
							'parent' => 999999,
						)
					)
				);
				$flat_parent_response    = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/tags',
						array( '_fields' => 'id,name,parent' ),
						array(),
						array(
							'name'   => 'Flat Parent Term ' . $case['token'],
							'parent' => $fixtures['term'],
						)
					)
				);
				$counts_after_invalids   = self::content_counts();
				$hook_counts_after_invalids = array(
					'preInsert'   => count( $pre_insert_events ),
					'insert'      => count( $insert_events ),
					'afterInsert' => count( $after_insert_events ),
				);

				$route_term_name = 'Route Meta Term ' . $case['token'];
				$route_term_slug = 'Route Meta Term Slug ' . $case['token'];
				$create_response = $server->dispatch(
					self::request(
						'POST',
						'/wp/v2/categories',
						array( '_fields' => 'id,name,parent,slug,taxonomy,meta' ),
						array(),
						array(
							'description' => 'Route meta term description ' . $case['token'],
							'meta'        => array( $case['termMetaKey'] => $case['termMetaInput'] ),
							'name'        => $route_term_name,
							'parent'      => $fixtures['term'],
							'slug'        => $route_term_slug,
						)
					)
				);
				$create_data     = $create_response instanceof \WP_REST_Response ? $create_response->get_data() : array();
				$created_term_id = (int) ( $create_data['id'] ?? 0 );
				$created_term    = \get_term( $created_term_id, 'category' );
				$created_meta    = $created_term_id > 0 ? \get_term_meta( $created_term_id, $case['termMetaKey'], true ) : null;
				$counts_after_create = self::content_counts();

				$invalid_update_response = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/categories/' . $created_term_id,
						array( '_fields' => 'id,parent,meta' ),
						array(),
						array( 'parent' => 999999 )
					)
				);
				$term_after_invalid_update = \get_term( $created_term_id, 'category' );
				$meta_after_invalid_update = $created_term_id > 0 ? \get_term_meta( $created_term_id, $case['termMetaKey'], true ) : null;
				$counts_after_invalid_update = self::content_counts();

				$updated_term_slug = 'Updated Route Meta Term Slug ' . $case['token'];
				$update_response   = $server->dispatch(
					self::request(
						'PUT',
						'/wp/v2/categories/' . $created_term_id,
						array( '_fields' => 'id,name,parent,slug,meta' ),
						array(),
						array(
							'meta'   => array( $case['termMetaKey'] => $case['termMetaUpdateInput'] ),
							'name'   => $case['updatedTermName'],
							'parent' => $fixtures['child_term'],
							'slug'   => $updated_term_slug,
						)
					)
				);
				$update_data      = $update_response instanceof \WP_REST_Response ? $update_response->get_data() : array();
				$updated_term     = \get_term( $created_term_id, 'category' );
				$updated_meta     = $created_term_id > 0 ? \get_term_meta( $created_term_id, $case['termMetaKey'], true ) : null;
				$counts_after_update = self::content_counts();

				$delete_response = $server->dispatch(
					self::request(
						'DELETE',
						'/wp/v2/categories/' . $created_term_id,
						array(),
						array(),
						array( 'force' => true )
					)
				);
				$delete_data      = $delete_response instanceof \WP_REST_Response ? $delete_response->get_data() : array();
				$deleted_term     = \get_term( $created_term_id, 'category' );
				$deleted_meta     = $created_term_id > 0 ? \get_term_meta( $created_term_id, $case['termMetaKey'], true ) : null;
				$counts_after_delete = self::content_counts();
			} finally {
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
				$cap_filter            = null;
			}

			$observed = array(
				'schema'               => $schema_observed,
				'deniedCreate'         => $denied_create_response instanceof \WP_REST_Response ? $denied_create_response->get_data() : $denied_create_response,
				'invalidParents'       => array(
					'hierarchical'    => $invalid_parent_response instanceof \WP_REST_Response ? $invalid_parent_response->get_data() : $invalid_parent_response,
					'nonHierarchical' => $flat_parent_response instanceof \WP_REST_Response ? $flat_parent_response->get_data() : $flat_parent_response,
				),
				'created'              => array(
					'response'     => $create_data,
					'status'       => $create_response instanceof \WP_REST_Response ? $create_response->get_status() : null,
					'location'     => $create_response instanceof \WP_REST_Response ? $create_response->get_headers()['Location'] ?? null : null,
					'stored'       => $created_term instanceof \WP_Term
						? array(
							'id'     => (int) $created_term->term_id,
							'name'   => $created_term->name,
							'parent' => (int) $created_term->parent,
							'slug'   => $created_term->slug,
						)
						: null,
					'storedMeta'   => $created_meta,
				),
				'invalidUpdate'        => array(
					'response'   => $invalid_update_response instanceof \WP_REST_Response ? $invalid_update_response->get_data() : $invalid_update_response,
					'termParent' => $term_after_invalid_update instanceof \WP_Term ? (int) $term_after_invalid_update->parent : null,
					'meta'       => $meta_after_invalid_update,
				),
				'updated'              => array(
					'response'   => $update_data,
					'status'     => $update_response instanceof \WP_REST_Response ? $update_response->get_status() : null,
					'termName'   => $updated_term instanceof \WP_Term ? $updated_term->name : null,
					'termParent' => $updated_term instanceof \WP_Term ? (int) $updated_term->parent : null,
					'termSlug'   => $updated_term instanceof \WP_Term ? $updated_term->slug : null,
					'meta'       => $updated_meta,
				),
				'deleted'              => array(
					'response'    => $delete_data,
					'status'      => $delete_response instanceof \WP_REST_Response ? $delete_response->get_status() : null,
					'termExists'  => $deleted_term instanceof \WP_Term,
					'meta'        => $deleted_meta,
				),
				'hooks'                => array(
					'preInsert'   => $pre_insert_events,
					'insert'      => $insert_events,
					'afterInsert' => $after_insert_events,
					'prepare'     => $prepare_events,
					'delete'      => $delete_events,
				),
				'hookCountsAfterDenial' => $hook_counts_after_denial,
				'hookCountsAfterInvalids' => $hook_counts_after_invalids,
				'counts'               => array(
					'before'        => $counts_before,
					'afterDenial'   => $counts_after_denial,
					'afterInvalids' => $counts_after_invalids,
					'afterCreate'   => $counts_after_create,
					'afterInvalidUpdate' => $counts_after_invalid_update,
					'afterUpdate'   => $counts_after_update,
					'afterDelete'   => $counts_after_delete,
				),
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $denied_create_response, 'rest_cannot_create', 401 )
					&& self::content_count_delta_matches( $counts_before, $counts_after_denial, array(), array() )
					&& array( 'preInsert' => 0, 'insert' => 0, 'afterInsert' => 0 ) === $hook_counts_after_denial,
				'route-dispatched term create denies anonymous requests before validation hooks or row changes',
				array(
					'deniedCreate' => $observed['deniedCreate'],
					'countsBefore' => $counts_before,
					'countsDenied' => $counts_after_denial,
					'hookCounts'   => $hook_counts_after_denial,
				)
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $invalid_parent_response, 'rest_term_invalid', 400 )
					&& self::response_error_ok( $flat_parent_response, 'rest_taxonomy_not_hierarchical', 400 )
					&& self::content_count_delta_matches( $counts_after_denial, $counts_after_invalids, array(), array() )
					&& array( 'preInsert' => 0, 'insert' => 0, 'afterInsert' => 0 ) === $hook_counts_after_invalids,
				'route-dispatched term parent errors reject missing parents and flat taxonomy parents before mutation',
				array(
					'invalidParents' => $observed['invalidParents'],
					'countsDenied'   => $counts_after_denial,
					'countsInvalids' => $counts_after_invalids,
					'hookCounts'     => $hook_counts_after_invalids,
				)
			);

			self::collect_failure(
				$failures,
				$create_response instanceof \WP_REST_Response
					&& 201 === $create_response->get_status()
					&& self::projected_keys_match( $create_data, array( 'id', 'meta', 'name', 'parent', 'slug', 'taxonomy' ) )
					&& $created_term_id > 0
					&& $route_term_name === ( $create_data['name'] ?? null )
					&& $fixtures['term'] === (int) ( $create_data['parent'] ?? 0 )
					&& \sanitize_title( $route_term_slug ) === ( $create_data['slug'] ?? null )
					&& 'category' === ( $create_data['taxonomy'] ?? null )
					&& $case['termMetaSanitized'] === ( $create_data['meta'][ $case['termMetaKey'] ] ?? null )
					&& \rest_url( 'wp/v2/categories/' . $created_term_id ) === ( $create_response->get_headers()['Location'] ?? null )
					&& $created_term instanceof \WP_Term
					&& $route_term_name === $created_term->name
					&& $fixtures['term'] === (int) $created_term->parent
					&& $case['termMetaSanitized'] === $created_meta
					&& self::content_count_delta_matches(
						$counts_before,
						$counts_after_create,
						array(
							'term_meta'     => 1,
							'term_taxonomy' => 1,
							'terms'         => 1,
						),
						array( 'term_meta', 'term_taxonomy', 'terms' )
					),
				'route-dispatched term create sanitizes meta, projects response fields, and persists parented rows',
				$observed['created']
			);

			self::collect_failure(
				$failures,
				self::response_error_ok( $invalid_update_response, 'rest_term_invalid', 400 )
					&& $fixtures['term'] === ( $term_after_invalid_update instanceof \WP_Term ? (int) $term_after_invalid_update->parent : null )
					&& $case['termMetaSanitized'] === $meta_after_invalid_update
					&& self::content_count_delta_matches( $counts_after_create, $counts_after_invalid_update, array(), array() )
					&& $update_response instanceof \WP_REST_Response
					&& 200 === $update_response->get_status()
					&& self::projected_keys_match( $update_data, array( 'id', 'meta', 'name', 'parent', 'slug' ) )
					&& $created_term_id === (int) ( $update_data['id'] ?? 0 )
					&& $case['updatedTermName'] === ( $update_data['name'] ?? null )
					&& $fixtures['child_term'] === (int) ( $update_data['parent'] ?? 0 )
					&& \sanitize_title( $updated_term_slug ) === ( $update_data['slug'] ?? null )
					&& $case['termMetaUpdateSanitized'] === ( $update_data['meta'][ $case['termMetaKey'] ] ?? null )
					&& $updated_term instanceof \WP_Term
					&& $case['updatedTermName'] === $updated_term->name
					&& $fixtures['child_term'] === (int) $updated_term->parent
					&& \sanitize_title( $updated_term_slug ) === $updated_term->slug
					&& $case['termMetaUpdateSanitized'] === $updated_meta
					&& self::content_count_delta_matches( $counts_after_create, $counts_after_update, array(), array() ),
				'route-dispatched term update rejects invalid parents before mutating and updates parent/meta atomically',
				array(
					'invalidUpdate' => $observed['invalidUpdate'],
					'updated'       => $observed['updated'],
					'counts'        => array(
						'afterCreate'        => $counts_after_create,
						'afterInvalidUpdate' => $counts_after_invalid_update,
						'afterUpdate'        => $counts_after_update,
					),
				)
			);

			self::collect_failure(
				$failures,
				$delete_response instanceof \WP_REST_Response
					&& 200 === $delete_response->get_status()
					&& true === ( $delete_data['deleted'] ?? null )
					&& $created_term_id === (int) ( $delete_data['previous']['id'] ?? 0 )
					&& $case['updatedTermName'] === ( $delete_data['previous']['name'] ?? null )
					&& $fixtures['child_term'] === (int) ( $delete_data['previous']['parent'] ?? 0 )
					&& $case['termMetaUpdateSanitized'] === ( $delete_data['previous']['meta'][ $case['termMetaKey'] ] ?? null )
					&& ! ( $deleted_term instanceof \WP_Term )
					&& '' === (string) $deleted_meta
					&& self::content_count_delta_matches( $counts_before, $counts_after_delete, array(), array() ),
				'route-dispatched term force delete returns previous meta projection and removes rows plus term meta',
				array(
					'deleted'      => $observed['deleted'],
					'countsBefore' => $counts_before,
					'countsAfter'  => $counts_after_delete,
				)
			);
		} finally {
			if ( false !== \has_filter( 'rest_delete_category', $delete_action ) ) {
				\remove_filter( 'rest_delete_category', $delete_action, 10 );
			}
			$delete_filter_restored = false === \has_filter( 'rest_delete_category', $delete_action );
			if ( false !== \has_filter( 'rest_prepare_category', $prepare_filter ) ) {
				\remove_filter( 'rest_prepare_category', $prepare_filter, 10 );
			}
			$prepare_filter_restored = false === \has_filter( 'rest_prepare_category', $prepare_filter );
			if ( false !== \has_filter( 'rest_after_insert_category', $after_insert_action ) ) {
				\remove_filter( 'rest_after_insert_category', $after_insert_action, 10 );
			}
			$after_filter_restored = false === \has_filter( 'rest_after_insert_category', $after_insert_action );
			if ( false !== \has_filter( 'rest_insert_category', $insert_action ) ) {
				\remove_filter( 'rest_insert_category', $insert_action, 10 );
			}
			$insert_filter_restored = false === \has_filter( 'rest_insert_category', $insert_action );
			if ( false !== \has_filter( 'rest_pre_insert_category', $pre_insert_filter ) ) {
				\remove_filter( 'rest_pre_insert_category', $pre_insert_filter, 10 );
			}
			$pre_filter_restored = false === \has_filter( 'rest_pre_insert_category', $pre_insert_filter );

			if ( null !== $cap_filter ) {
				$write_filter_restored = self::remove_cap_filter( $cap_filter );
			}

			\wp_set_current_user( $previous_current_user_id );

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

			$actions_restored = $had_wp_actions
				? $previous_actions === ( $GLOBALS['wp_actions'] ?? null )
				: ! array_key_exists( 'wp_actions', $GLOBALS );
			$server_restored = null !== $previous_server
				? $previous_server === ( $GLOBALS['wp_rest_server'] ?? null )
				: ! array_key_exists( 'wp_rest_server', $GLOBALS );
			$current_user_restored = $previous_current_user_id === (
				isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User
					? (int) $GLOBALS['current_user']->ID
					: 0
			);
		}

		$created_insert  = $insert_events[0] ?? array();
		$updated_insert  = $insert_events[1] ?? array();
		$created_after   = $after_insert_events[0] ?? array();
		$updated_after   = $after_insert_events[1] ?? array();
		$prepare_methods = array_values( array_unique( array_column( $prepare_events, 'method' ) ) );
		sort( $prepare_methods );

		self::collect_failure(
			$failures,
			2 === count( $pre_insert_events )
				&& 2 === count( $insert_events )
				&& 2 === count( $after_insert_events )
				&& 1 === count( $delete_events )
				&& true === ( $created_insert['creating'] ?? null )
				&& '' === (string) ( $created_insert['metaStored'] ?? null )
				&& true === ( $created_after['creating'] ?? null )
				&& $case['termMetaSanitized'] === ( $created_after['metaStored'] ?? null )
				&& false === ( $updated_insert['creating'] ?? null )
				&& $case['termMetaSanitized'] === ( $updated_insert['metaStored'] ?? null )
				&& false === ( $updated_after['creating'] ?? null )
				&& $case['termMetaUpdateSanitized'] === ( $updated_after['metaStored'] ?? null )
				&& array( 'DELETE', 'POST', 'PUT' ) === $prepare_methods
				&& true === ( $delete_events[0]['deleted'] ?? null )
				&& $case['termMetaUpdateSanitized'] === ( $delete_events[0]['previousMeta'] ?? null )
				&& '' === (string) ( $delete_events[0]['metaAfterDelete'] ?? null )
				&& $write_filter_restored
				&& $pre_filter_restored
				&& $insert_filter_restored
				&& $after_filter_restored
				&& $prepare_filter_restored
				&& $delete_filter_restored
				&& $server_restored
				&& $actions_restored
				&& $current_user_restored,
			'route-dispatched term mutation hooks fire in meta-aware order and harness state is restored',
			array(
				'hooks' => array(
					'preInsert'   => $pre_insert_events,
					'insert'      => $insert_events,
					'afterInsert' => $after_insert_events,
					'prepare'     => $prepare_events,
					'delete'      => $delete_events,
				),
				'restored' => array(
					'cap'         => $write_filter_restored,
					'preInsert'   => $pre_filter_restored,
					'insert'      => $insert_filter_restored,
					'afterInsert' => $after_filter_restored,
					'prepare'     => $prepare_filter_restored,
					'delete'      => $delete_filter_restored,
					'server'      => $server_restored,
					'actions'     => $actions_restored,
					'currentUser' => $current_user_restored,
				),
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.route-dispatched-term-parent-meta-hooks-delete-cleanup',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 8 ),
				'observed' => $observed,
			)
		);
	}

	private static function check_templates_controller( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$template_controller      = new \WP_REST_Templates_Controller( 'wp_template' );
		$template_part_controller = new \WP_REST_Templates_Controller( 'wp_template_part' );

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
			$template_controller->register_routes();
			$template_part_controller->register_routes();

			$routes                  = $server->get_routes( 'wp/v2' );
			$template_item_route      = self::route_key_for_prefix( $routes, '/wp/v2/templates/(?P<id>' );
			$template_part_item_route = self::route_key_for_prefix( $routes, '/wp/v2/template-parts/(?P<id>' );

			$template_collection_methods      = self::route_methods( $routes['/wp/v2/templates'] ?? array() );
			$template_lookup_methods          = self::route_methods( $routes['/wp/v2/templates/lookup'] ?? array() );
			$template_item_methods            = null === $template_item_route ? array() : self::route_methods( $routes[ $template_item_route ] ?? array() );
			$template_part_collection_methods = self::route_methods( $routes['/wp/v2/template-parts'] ?? array() );
			$template_part_lookup_methods     = self::route_methods( $routes['/wp/v2/template-parts/lookup'] ?? array() );
			$template_part_item_methods       = null === $template_part_item_route ? array() : self::route_methods( $routes[ $template_part_item_route ] ?? array() );

			self::collect_failure(
				$failures,
				in_array( 'wp/v2', $server->get_namespaces(), true )
					&& array( 'GET', 'POST' ) === $template_collection_methods
					&& array( 'GET' ) === $template_lookup_methods
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $template_item_methods
					&& array( 'GET', 'POST' ) === $template_part_collection_methods
					&& array( 'GET' ) === $template_part_lookup_methods
					&& array( 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ) === $template_part_item_methods
					&& is_callable( $server->get_route_options( '/wp/v2/templates' )['schema'] ?? null )
					&& is_callable( $server->get_route_options( '/wp/v2/template-parts' )['schema'] ?? null ),
				'template controllers register bounded collection, lookup, item routes, methods, and schemas',
				array(
					'templateItemRoute'              => $template_item_route,
					'templatePartItemRoute'          => $template_part_item_route,
					'templateCollectionMethods'      => $template_collection_methods,
					'templateLookupMethods'          => $template_lookup_methods,
					'templateItemMethods'            => $template_item_methods,
					'templatePartCollectionMethods'  => $template_part_collection_methods,
					'templatePartLookupMethods'      => $template_part_lookup_methods,
					'templatePartItemMethods'        => $template_part_item_methods,
				)
			);

			$template_collection_data = $server->get_data_for_route(
				'/wp/v2/templates',
				$routes['/wp/v2/templates'] ?? array(),
				'help'
			);
			$template_part_collection_data = $server->get_data_for_route(
				'/wp/v2/template-parts',
				$routes['/wp/v2/template-parts'] ?? array(),
				'help'
			);

			self::collect_failure(
				$failures,
				self::route_data_has_methods( $template_collection_data, array( 'GET', 'POST' ) )
					&& self::route_data_has_methods( $template_part_collection_data, array( 'GET', 'POST' ) )
					&& self::route_data_has_endpoint_args( $template_collection_data, array( 'GET' ), array( 'area', 'context', 'post_type', 'wp_id' ) )
					&& self::route_data_has_endpoint_args( $template_collection_data, array( 'POST' ), array( 'content', 'slug', 'theme', 'title' ) )
					&& self::route_data_has_endpoint_args( $template_part_collection_data, array( 'GET' ), array( 'area', 'context', 'post_type', 'wp_id' ) )
					&& self::route_data_has_endpoint_args( $template_part_collection_data, array( 'POST' ), array( 'area', 'content', 'slug', 'theme', 'title' ) )
					&& self::route_data_schema_has_properties( $template_collection_data, array( 'id', 'slug', 'theme', 'content', 'is_custom', 'plugin' ) )
					&& self::route_data_schema_has_properties( $template_part_collection_data, array( 'id', 'slug', 'theme', 'content', 'area' ) )
					&& \rest_url( 'wp/v2/templates' ) === self::route_data_self_href( $template_collection_data )
					&& \rest_url( 'wp/v2/template-parts' ) === self::route_data_self_href( $template_part_collection_data ),
				'template route index data exposes collection args, create args, schema fields, and self links',
				array(
					'templateCollectionData'     => $template_collection_data,
					'templatePartCollectionData' => $template_part_collection_data,
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

		$sanitize_cases = array(
			'theme/home'            => 'theme//home',
			'theme//home'           => 'theme//home',
			'subdir/theme/index'    => 'subdir/theme//index',
			'theme%2Fsingle'        => 'theme//single',
			'noslash'               => 'noslash',
		);
		$sanitize_actual = array();
		foreach ( $sanitize_cases as $input => $expected ) {
			$sanitize_actual[ $input ] = $template_controller->_sanitize_template_id( $input );
		}

		self::collect_failure(
			$failures,
			$sanitize_cases === $sanitize_actual,
			'template ID sanitization doubles only the final route slash and decodes encoded slashes',
			array(
				'expected' => $sanitize_cases,
				'actual'   => $sanitize_actual,
			)
		);

		$params       = $template_controller->get_collection_params();
		$part_params  = $template_part_controller->get_collection_params();
		$schema       = $template_controller->get_item_schema();
		$part_schema  = $template_part_controller->get_item_schema();

		self::collect_failure(
			$failures,
			self::template_collection_params_ok( $params )
				&& self::template_collection_params_ok( $part_params )
				&& self::template_schema_ok( $schema, false )
				&& self::template_schema_ok( $part_schema, true ),
			'template controllers expose bounded collection params and expected schema fields without querying',
			array(
				'params'      => self::param_summary( $params ),
				'partParams'  => self::param_summary( $part_params ),
				'schemaKeys'  => array_keys( $schema['properties'] ?? array() ),
				'partKeys'    => array_keys( $part_schema['properties'] ?? array() ),
			)
		);

		$permission_request = self::request( 'GET', '/wp/v2/templates' );
		$denied_status      = \rest_authorization_required_code();
		$denied            = array(
			'items'  => $template_controller->get_items_permissions_check( $permission_request ),
			'item'   => $template_controller->get_item_permissions_check( $permission_request ),
			'create' => $template_controller->create_item_permissions_check( $permission_request ),
			'update' => $template_controller->update_item_permissions_check( $permission_request ),
			'delete' => $template_controller->delete_item_permissions_check( $permission_request ),
		);

		$read_filter          = self::install_cap_filter( array( 'edit_posts' ) );
		$read_filter_restored = false;
		try {
			$read_allowed = array(
				'items' => $template_controller->get_items_permissions_check( $permission_request ),
				'item'  => $template_controller->get_item_permissions_check( $permission_request ),
			);
		} finally {
			$read_filter_restored = self::remove_cap_filter( $read_filter );
		}

		$write_filter          = self::install_cap_filter( array( 'edit_theme_options' ) );
		$write_filter_restored = false;
		try {
			$write_allowed = array(
				'create' => $template_controller->create_item_permissions_check( $permission_request ),
				'update' => $template_controller->update_item_permissions_check( $permission_request ),
				'delete' => $template_controller->delete_item_permissions_check( $permission_request ),
			);
		} finally {
			$write_filter_restored = self::remove_cap_filter( $write_filter );
		}

		self::collect_failure(
			$failures,
			self::error_matches( $denied['items'], 'rest_cannot_manage_templates', $denied_status )
				&& self::error_matches( $denied['item'], 'rest_cannot_manage_templates', $denied_status )
				&& self::error_matches( $denied['create'], 'rest_cannot_manage_templates', $denied_status )
				&& self::error_matches( $denied['update'], 'rest_cannot_manage_templates', $denied_status )
				&& self::error_matches( $denied['delete'], 'rest_cannot_manage_templates', $denied_status )
				&& array( 'items' => true, 'item' => true ) === $read_allowed
				&& array( 'create' => true, 'update' => true, 'delete' => true ) === $write_allowed
				&& $read_filter_restored
				&& $write_filter_restored,
			'template controller permission gates fail closed and open only with scoped capabilities',
			array(
				'denied'              => $denied,
				'deniedStatus'        => $denied_status,
				'readAllowed'         => $read_allowed,
				'writeAllowed'        => $write_allowed,
				'readFilterRestored'  => $read_filter_restored,
				'writeFilterRestored' => $write_filter_restored,
			)
		);

		$template      = self::synthetic_block_template( $case, 'wp_template' );
		$template_part = self::synthetic_block_template( $case, 'wp_template_part' );
		$template_raw  = $template->content;
		$part_raw      = $template_part->content;

		$template_request = self::request(
			'GET',
			'/wp/v2/templates/' . rawurlencode( $template->id ),
			array(
				'context' => 'edit',
				'_fields' => 'id,theme,content.raw,content.block_version,slug,source,origin,type,description,title.raw,title.rendered,status,wp_id,has_theme_file,is_custom,author,modified',
			)
		);
		$template_part_request = self::request(
			'GET',
			'/wp/v2/template-parts/' . rawurlencode( $template_part->id ),
			array(
				'context' => 'edit',
				'_fields' => 'id,theme,content.raw,content.block_version,slug,source,origin,type,description,title.raw,title.rendered,status,wp_id,has_theme_file,author,modified,area',
			)
		);
		$template_response      = $template_controller->prepare_item_for_response( $template, $template_request );
		$template_part_response = $template_part_controller->prepare_item_for_response( $template_part, $template_part_request );
		$template_data          = $template_response instanceof \WP_REST_Response ? $template_response->get_data() : array();
		$template_part_data     = $template_part_response instanceof \WP_REST_Response ? $template_part_response->get_data() : array();
		$head_response          = $template_controller->prepare_item_for_response(
			self::synthetic_block_template( $case, 'wp_template' ),
			self::request( 'HEAD', '/wp/v2/templates/' . rawurlencode( $template->id ) )
		);

		self::collect_failure(
			$failures,
			$template_response instanceof \WP_REST_Response
				&& $template_part_response instanceof \WP_REST_Response
				&& self::projected_keys_match(
					$template_data,
					array( 'author', 'content', 'description', 'has_theme_file', 'id', 'is_custom', 'modified', 'origin', 'slug', 'source', 'status', 'theme', 'title', 'type', 'wp_id' )
				)
				&& self::projected_keys_match(
					$template_part_data,
					array( 'area', 'author', 'content', 'description', 'has_theme_file', 'id', 'modified', 'origin', 'slug', 'source', 'status', 'theme', 'title', 'type', 'wp_id' )
				)
				&& self::response_matches_schema_context( $template_controller, $template_data, 'edit' )
				&& self::response_matches_schema_context( $template_part_controller, $template_part_data, 'edit' )
				&& $template->id === ( $template_data['id'] ?? null )
				&& $template->theme === ( $template_data['theme'] ?? null )
				&& $template->slug === ( $template_data['slug'] ?? null )
				&& $template->source === ( $template_data['source'] ?? null )
				&& $template->origin === ( $template_data['origin'] ?? null )
				&& $template->type === ( $template_data['type'] ?? null )
				&& $template->description === ( $template_data['description'] ?? null )
				&& $template->title === ( $template_data['title']['raw'] ?? null )
				&& $template->title === ( $template_data['title']['rendered'] ?? null )
				&& $template->status === ( $template_data['status'] ?? null )
				&& 0 === (int) ( $template_data['wp_id'] ?? -1 )
				&& false === ( $template_data['has_theme_file'] ?? null )
				&& true === ( $template_data['is_custom'] ?? null )
				&& (int) $template->author === (int) ( $template_data['author'] ?? -1 )
				&& \mysql_to_rfc3339( $template->modified ) === ( $template_data['modified'] ?? null )
				&& self::serialized_template_content( $template_raw ) === ( $template_data['content']['raw'] ?? null )
				&& \block_version( (string) ( $template_data['content']['raw'] ?? '' ) ) === ( $template_data['content']['block_version'] ?? null )
				&& $template_part->area === ( $template_part_data['area'] ?? null )
				&& self::serialized_template_content( $part_raw ) === ( $template_part_data['content']['raw'] ?? null )
				&& $head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data(),
			'synthetic template responses project schema-valid fields without links, author text, plugin, filesystem, or CPT queries',
			array(
				'templateData'     => $template_data,
				'templatePartData' => $template_part_data,
				'headData'         => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
			)
		);

		return self::row(
			$ctx,
			'rest-object-controllers.templates-controller.bounded-schema-routes-permissions-response',
			array() === $failures,
			array(
				'case'       => self::case_summary( $case ),
				'failures'   => array_slice( $failures, 0, 8 ),
				'notCovered' => 'Template collection queries, fallback lookup, item lookup, writes, deletes, links, author text, plugin fields, active theme files, and template CPT queries remain covered by rest-site-editor or explicit query-boundary skips.',
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
			'termMetaKey'                 => 'cfz_term_meta_' . $token,
			'termMetaInput'               => " <b>Term Meta {$token}</b>\n",
			'termMetaUpdateInput'         => " Updated\tTerm {$token}<script>x</script> ",
			'termMetaSanitized'           => \sanitize_text_field( " <b>Term Meta {$token}</b>\n" ),
			'termMetaUpdateSanitized'     => \sanitize_text_field( " Updated\tTerm {$token}<script>x</script> " ),
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

	private static function header_has_link_rel( array $headers, string $rel ): bool {
		$link = $headers['Link'] ?? '';
		return is_string( $link ) && false !== strpos( $link, 'rel="' . $rel . '"' );
	}

	private static function collection_projected_rows_ok( \WP_REST_Controller $controller, array $rows, array $expected_ids, array $expected_keys, string $context ): bool {
		if ( count( $expected_ids ) !== count( $rows ) ) {
			return false;
		}

		$actual_ids = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				return false;
			}

			$actual_ids[] = (int) $row['id'];
			if ( ! self::projected_keys_match( $row, $expected_keys ) || ! self::response_matches_schema_context( $controller, $row, $context ) ) {
				return false;
			}
		}

		return array_values( $expected_ids ) === $actual_ids;
	}

	private static function collection_field_value_ok( array $rows, string $field, $expected_value ): bool {
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! array_key_exists( $field, $row ) || $expected_value !== $row[ $field ] ) {
				return false;
			}
		}

		return array() !== $rows;
	}

	private static function prepare_event( string $type, int $id, \WP_REST_Request $request ): array {
		return array(
			'type'    => $type,
			'id'      => $id,
			'method'  => $request->get_method(),
			'route'   => $request->get_route(),
			'context' => $request->get_param( 'context' ) ?: 'view',
			'fields'  => $request->get_param( '_fields' ),
		);
	}

	private static function prepare_events_match( array $events, string $type, array $expected_ids, string $route, string $method, string $context ): bool {
		$matched = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || $type !== ( $event['type'] ?? null ) ) {
				continue;
			}
			if ( $route !== ( $event['route'] ?? null ) || $method !== ( $event['method'] ?? null ) || $context !== ( $event['context'] ?? null ) ) {
				return false;
			}
			$matched[] = (int) ( $event['id'] ?? 0 );
		}

		return array_values( $expected_ids ) === $matched;
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
			if ( 'bool' === $single_type && is_bool( $value ) ) {
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

	private static function dispatch_with_rest_post_dispatch( \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		return \apply_filters(
			'rest_post_dispatch',
			\rest_ensure_response( $server->dispatch( $request ) ),
			$server,
			$request
		);
	}

	private static function rest_default_filter_callbacks(): array {
		return array(
			'rest_pre_serve_request:rest_send_cors_headers'      => array( 'rest_pre_serve_request', 'rest_send_cors_headers', 10 ),
			'rest_post_dispatch:rest_send_allow_header'          => array( 'rest_post_dispatch', 'rest_send_allow_header', 10 ),
			'rest_post_dispatch:rest_filter_response_fields'     => array( 'rest_post_dispatch', 'rest_filter_response_fields', 10 ),
			'rest_pre_dispatch:rest_handle_options_request'      => array( 'rest_pre_dispatch', 'rest_handle_options_request', 10 ),
			'rest_index:rest_add_application_passwords_to_index' => array( 'rest_index', 'rest_add_application_passwords_to_index', 10 ),
		);
	}

	private static function rest_default_filter_state(): array {
		$state = array();

		foreach ( self::rest_default_filter_callbacks() as $key => $callback ) {
			list( $hook, $function ) = $callback;
			$priority = \has_filter( $hook, $function );
			if ( false !== $priority ) {
				$state[ $key ] = (int) $priority;
			}
		}

		return $state;
	}

	private static function restore_rest_default_filters( array $snapshot ): void {
		foreach ( self::rest_default_filter_callbacks() as $key => $callback ) {
			list( $hook, $function, $priority ) = $callback;
			if ( ! array_key_exists( $key, $snapshot ) ) {
				\remove_filter( $hook, $function, $priority );
			}
		}
	}

	private static function template_collection_params_ok( array $params ): bool {
		return self::collection_context_param_ok( $params )
			&& self::collection_has_params( $params, array( 'context', 'wp_id', 'area', 'post_type' ) )
			&& 4 === count( array_intersect( array_keys( $params ), array( 'context', 'wp_id', 'area', 'post_type' ) ) )
			&& 'integer' === ( $params['wp_id']['type'] ?? null )
			&& 'string' === ( $params['area']['type'] ?? null )
			&& 'string' === ( $params['post_type']['type'] ?? null );
	}

	private static function template_schema_ok( array $schema, bool $template_part ): bool {
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		$common     = array(
			'id',
			'slug',
			'theme',
			'type',
			'source',
			'origin',
			'content',
			'title',
			'description',
			'status',
			'wp_id',
			'has_theme_file',
			'author',
			'modified',
			'author_text',
			'original_source',
		);

		foreach ( $common as $property ) {
			if ( ! array_key_exists( $property, $properties ) ) {
				return false;
			}
		}

		$slug = is_array( $properties['slug'] ?? null ) ? $properties['slug'] : array();
		$content = is_array( $properties['content']['properties'] ?? null ) ? $properties['content']['properties'] : array();
		$original_source = is_array( $properties['original_source'] ?? null ) ? $properties['original_source'] : array();

		$original_source_enum = $original_source['enum'] ?? array();
		sort( $original_source_enum );
		$expected_original_source = array( 'plugin', 'site', 'theme', 'user' );

		$ok = 'object' === ( $schema['type'] ?? null )
			&& 'string' === ( $slug['type'] ?? null )
			&& true === ( $slug['required'] ?? null )
			&& 1 === (int) ( $slug['minLength'] ?? 0 )
			&& '[a-zA-Z0-9_\%-]+' === ( $slug['pattern'] ?? null )
			&& isset( $content['raw'], $content['block_version'] )
			&& array( 'view', 'edit' ) === ( $content['raw']['context'] ?? null )
			&& array( 'edit' ) === ( $content['block_version']['context'] ?? null )
			&& $expected_original_source === $original_source_enum;

		if ( $template_part ) {
			return $ok
				&& array_key_exists( 'area', $properties )
				&& ! array_key_exists( 'is_custom', $properties )
				&& ! array_key_exists( 'plugin', $properties );
		}

		return $ok
			&& array_key_exists( 'is_custom', $properties )
			&& array_key_exists( 'plugin', $properties )
			&& ! array_key_exists( 'area', $properties );
	}

	private static function synthetic_block_template( array $case, string $type ): \WP_Block_Template {
		$template = new \WP_Block_Template();
		$slug     = 'template-' . $case['token'];
		if ( 'wp_template_part' === $type ) {
			$slug = 'part-' . $case['token'];
		}

		$template->type           = $type;
		$template->theme          = 'component-fuzz-theme-' . $case['token'];
		$template->slug           = $slug;
		$template->id             = $template->theme . '//' . $slug;
		$template->title          = 'Template ' . $case['token'];
		$template->content        = '<!-- wp:paragraph --><p>Template ' . $case['token'] . '</p><!-- /wp:paragraph -->';
		$template->description    = 'Template description ' . $case['token'];
		$template->source         = 'custom';
		$template->origin         = 'theme';
		$template->wp_id          = 0;
		$template->status         = 'publish';
		$template->has_theme_file = false;
		$template->is_custom      = true;
		$template->author         = 0;
		$template->plugin         = null;
		$template->post_types     = 'wp_template' === $type ? array( 'post' ) : null;
		$template->area           = 'wp_template_part' === $type ? 'header' : null;
		$template->modified       = '2026-06-30 12:34:56';

		return $template;
	}

	private static function serialized_template_content( string $content ): string {
		$blocks = \parse_blocks( $content );
		$blocks = \resolve_pattern_blocks( $blocks );
		return \serialize_blocks( $blocks );
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

	private static function route_key_for_prefix( array $routes, string $prefix ): ?string {
		foreach ( array_keys( $routes ) as $route ) {
			if ( str_starts_with( $route, $prefix ) ) {
				return $route;
			}
		}

		return null;
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

	private static function wpdb_last_query() {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'last_query' ) ) {
			return $GLOBALS['wpdb']->last_query;
		}
		return null;
	}

	private static function set_wpdb_last_query( string $last_query ): bool {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'last_query' ) ) {
			$GLOBALS['wpdb']->last_query = $last_query;
			return true;
		}
		return false;
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
