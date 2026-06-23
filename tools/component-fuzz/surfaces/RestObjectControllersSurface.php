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

			$case     = self::object_case( $ctx );
			$fixtures = self::seed_fixtures( $case );

			$rows[] = self::check_posts_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_terms_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_comments_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_users_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_revisions_controller( $ctx, $case, $fixtures );
			$rows[] = self::check_attachments_controller( $ctx, $case, $fixtures );
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
				'is_wp_error',
				'register_post_meta',
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

	private static function check_posts_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$controller = new \WP_REST_Posts_Controller( 'post' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		$status_request = self::request( 'GET', '/wp/v2/posts' );
		$status_request->set_attributes( array( 'args' => $params ) );
		$private_denied = call_user_func( $params['status']['sanitize_callback'], array( 'private' ), $status_request, 'status' );
		$cap_filter     = self::install_cap_filter( array( 'edit_posts', 'read_private_posts' ) );
		try {
			$private_allowed = call_user_func( $params['status']['sanitize_callback'], array( 'private' ), $status_request, 'status' );
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& isset( $params['status'], $params['orderby'], $params['search_columns'] )
				&& $private_denied instanceof \WP_Error
				&& 'rest_forbidden_status' === $private_denied->get_error_code()
				&& array( 'private' ) === $private_allowed,
			'post collection params sanitize status and expose query controls without querying',
			array(
				'params'         => self::param_summary( $params ),
				'privateDenied'  => $private_denied,
				'privateAllowed' => $private_allowed,
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
		try {
			$edit_allowed = $controller->get_item_permissions_check( $edit_request );
			$item         = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/posts/' . $fixtures['post'],
					array(
						'context' => 'edit',
						'_fields' => 'id,title,content,meta,slug,_links',
					),
					array( 'id' => $fixtures['post'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_keys = array_keys( $item_data );
		sort( $item_keys );
		$item_links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		self::collect_failure(
			$failures,
			$edit_denied instanceof \WP_Error
				&& 'rest_forbidden_context' === $edit_denied->get_error_code()
				&& true === $edit_allowed
				&& $item instanceof \WP_REST_Response
				&& array( 'content', 'id', 'meta', 'slug', 'title' ) === $item_keys
				&& $case['postTitle'] === ( $item_data['title']['raw'] ?? null )
				&& $case['postContent'] === ( $item_data['content']['raw'] ?? null )
				&& $case['postMetaStored'] === ( $item_data['meta'][ $case['postMetaKey'] ] ?? null )
				&& \rest_url( 'wp/v2/posts/' . $fixtures['post'] ) === self::link_href( $item_links, 'self' )
				&& \rest_url( 'wp/v2/posts' ) === self::link_href( $item_links, 'collection' ),
			'post get_item gates edit context, respects _fields/context, exposes meta, and emits REST links',
			array(
				'editDenied' => $edit_denied,
				'editData'   => $item_data,
				'links'      => $item_links,
			)
		);

		$head_response = $controller->prepare_item_for_response(
			\get_post( $fixtures['post'] ),
			self::request( 'HEAD', '/wp/v2/posts/' . $fixtures['post'], array(), array( 'id' => $fixtures['post'] ) )
		);
		$invalid_item  = $controller->get_item(
			self::request( 'GET', '/wp/v2/posts/999999', array( 'context' => 'view' ), array( 'id' => 999999 ) )
		);
		self::collect_failure(
			$failures,
			$head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data()
				&& $invalid_item instanceof \WP_Error
				&& 'rest_post_invalid_id' === $invalid_item->get_error_code(),
			'post HEAD and invalid ID error paths are represented',
			array(
				'headData'    => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
				'invalidItem' => $invalid_item,
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
			\remove_filter( 'rest_block_hooks_post_types', $block_filter, 10 );
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
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
				&& $delete_error instanceof \WP_Error
				&& 'rest_trash_not_supported' === $delete_error->get_error_code(),
			'post create/update/delete error paths use the in-memory post and meta stores',
			array(
				'existingCreate' => $existing_create,
				'createDenied'   => $create_denied,
				'createdData'    => $created_data,
				'updatedData'    => $updated_data,
				'deleteError'    => $delete_error,
				'storedMeta'     => $created_id > 0 ? \get_post_meta( $created_id, $case['postMetaKey'], true ) : null,
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

	private static function check_terms_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$controller = new \WP_REST_Terms_Controller( 'category' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		$slug_sanitized = $controller->sanitize_slug( $case['termSlugInput'] );
		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& isset( $params['hide_empty'], $params['post'], $params['orderby'] )
				&& 'asc' === ( $params['order']['default'] ?? null )
				&& in_array( 'desc', $params['order']['enum'] ?? array(), true )
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
					'_fields' => 'id,name,slug,taxonomy,parent,_links',
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
		try {
			$edit_allowed = $controller->get_item_permissions_check( $edit_request );
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}
		self::collect_failure(
			$failures,
			$item instanceof \WP_REST_Response
				&& $fixtures['term'] === (int) ( $item_data['id'] ?? 0 )
				&& $case['termName'] === ( $item_data['name'] ?? null )
				&& 'category' === ( $item_data['taxonomy'] ?? null )
				&& \rest_url( 'wp/v2/categories/' . $fixtures['term'] ) === self::link_href( $item_links, 'self' )
				&& \rest_url( 'wp/v2/categories' ) === self::link_href( $item_links, 'collection' )
				&& $edit_denied instanceof \WP_Error
				&& 'rest_forbidden_context' === $edit_denied->get_error_code()
				&& true === $edit_allowed,
			'term get_item respects fields, links, and edit permission gates',
			array(
				'itemData'    => $item_data,
				'links'       => $item_links,
				'editDenied'  => $edit_denied,
				'editAllowed' => $edit_allowed,
			)
		);

		$invalid_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/categories/999999', array(), array( 'id' => 999999 ) )
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
		self::collect_failure(
			$failures,
			$invalid_item instanceof \WP_Error
				&& 'rest_term_invalid' === $invalid_item->get_error_code()
				&& $invalid_parent instanceof \WP_Error
				&& 'rest_term_invalid' === $invalid_parent->get_error_code()
				&& $head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data(),
			'term invalid ID, invalid parent, and HEAD paths are represented',
			array(
				'invalidItem'   => $invalid_item,
				'invalidParent' => $invalid_parent,
				'headData'      => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
			)
		);

		$cap_filter = self::install_cap_filter( array( 'delete_categories', 'edit_categories', 'manage_categories' ) );
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
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		self::collect_failure(
			$failures,
			$created instanceof \WP_REST_Response
				&& 201 === $created->get_status()
				&& $created_id > 0
				&& $case['createdTermName'] === ( $created_data['name'] ?? null )
				&& \sanitize_title( $case['createdTermSlugInput'] ) === ( $created_data['slug'] ?? null )
				&& $updated instanceof \WP_REST_Response
				&& $case['updatedTermName'] === ( $updated_data['name'] ?? null )
				&& $delete_error instanceof \WP_Error
				&& 'rest_trash_not_supported' === $delete_error->get_error_code(),
			'term create/update/delete error paths use the in-memory term tables',
			array(
				'createdData' => $created_data,
				'updatedData' => $updated_data,
				'deleteError' => $delete_error,
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

	private static function check_comments_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
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
				&& isset( $params['author_email'], $params['post'], $params['type'] )
				&& 'approved' === $status_sanitized
				&& $invalid_email instanceof \WP_Error
				&& 'rest_invalid_email' === $invalid_email->get_error_code(),
			'comment collection params sanitize status and validate author email',
			array(
				'params'          => self::param_summary( $params ),
				'statusSanitized' => $status_sanitized,
				'invalidEmail'    => $invalid_email,
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
		try {
			$edit_item = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/comments/' . $fixtures['comment'],
					array(
						'context' => 'edit',
						'_fields' => 'id,content,author_email,status,_links',
					),
					array( 'id' => $fixtures['comment'] )
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}
		$edit_data = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		self::collect_failure(
			$failures,
			$view_item instanceof \WP_REST_Response
				&& $fixtures['comment'] === (int) ( $view_data['id'] ?? 0 )
				&& $fixtures['post'] === (int) ( $view_data['post'] ?? 0 )
				&& ! isset( $view_data['author_email'], $view_data['content']['raw'] )
				&& isset( $view_data['content']['rendered'] )
				&& 'approved' === ( $view_data['status'] ?? null )
				&& \rest_url( 'wp/v2/comments/' . $fixtures['comment'] ) === self::link_href( $view_links, 'self' )
				&& $edit_item instanceof \WP_REST_Response
				&& $case['commentAuthorEmail'] === ( $edit_data['author_email'] ?? null )
				&& $case['commentContent'] === ( $edit_data['content']['raw'] ?? null ),
			'comment get_item filters edit-only fields by context and emits links',
			array(
				'viewData' => $view_data,
				'editData' => $edit_data,
				'links'    => $view_links,
			)
		);

		$invalid_item = $controller->get_item(
			self::request( 'GET', '/wp/v2/comments/999999', array(), array( 'id' => 999999 ) )
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
		self::collect_failure(
			$failures,
			$invalid_item instanceof \WP_Error
				&& 'rest_comment_invalid_id' === $invalid_item->get_error_code()
				&& $missing_post instanceof \WP_Error
				&& 'rest_comment_invalid_post_id' === $missing_post->get_error_code()
				&& $bad_type instanceof \WP_Error
				&& 'rest_invalid_comment_type' === $bad_type->get_error_code()
				&& $head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data(),
			'comment invalid ID, missing post, invalid type, and HEAD paths are represented',
			array(
				'invalidItem' => $invalid_item,
				'missingPost' => $missing_post,
				'badType'     => $bad_type,
				'headData'    => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
			)
		);

		$trash_filter = static function (): bool {
			return false;
		};
		$cap_filter   = self::install_cap_filter( array( 'edit_posts', 'moderate_comments' ) );
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
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
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
				&& $delete_error instanceof \WP_Error
				&& 'rest_trash_not_supported' === $delete_error->get_error_code(),
			'comment create/update/delete error paths use the in-memory comment table',
			array(
				'createdData' => $created_data,
				'updatedData' => $updated_data,
				'deleteError' => $delete_error,
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

	private static function check_users_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
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
				&& isset( $params['roles'], $params['capabilities'], $params['who'] )
				&& $roles_denied instanceof \WP_Error
				&& 'rest_user_cannot_view' === $roles_denied->get_error_code()
				&& $reassign_invalid instanceof \WP_Error
				&& 'rest_invalid_param' === $reassign_invalid->get_error_code()
				&& false === $reassign_false
				&& $bad_username instanceof \WP_Error
				&& 'rest_user_invalid_username' === $bad_username->get_error_code(),
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
					'_fields' => 'id,name,email,slug,_links',
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
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$edit_data = $edit_item instanceof \WP_REST_Response ? $edit_item->get_data() : array();
		self::collect_failure(
			$failures,
			$embed_item instanceof \WP_REST_Response
				&& $fixtures['author'] === (int) ( $embed_data['id'] ?? 0 )
				&& ! isset( $embed_data['email'] )
				&& \rest_url( 'wp/v2/users/' . $fixtures['author'] ) === self::link_href( $embed_links, 'self' )
				&& true === $edit_denied
				&& true === $edit_allowed
				&& $edit_item instanceof \WP_REST_Response
				&& $case['authorEmail'] === ( $edit_data['email'] ?? null )
				&& $case['authorLogin'] === ( $edit_data['username'] ?? null ),
			'user get_item filters edit-only fields by context and allows current-user edit context',
			array(
				'embedData'   => $embed_data,
				'editData'    => $edit_data,
				'links'       => $embed_links,
				'editDenied'  => $edit_denied,
				'editAllowed' => $edit_allowed,
			)
		);

		$invalid_item    = $controller->get_item(
			self::request( 'GET', '/wp/v2/users/999999', array(), array( 'id' => 999999 ) )
		);
		$existing_create = $controller->create_item(
			self::request( 'POST', '/wp/v2/users', array(), array(), array( 'id' => $fixtures['author'] ) )
		);
		$create_denied   = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/users' ) );
		$head_response   = $controller->prepare_item_for_response(
			\get_user_by( 'id', $fixtures['author'] ),
			self::request( 'HEAD', '/wp/v2/users/' . $fixtures['author'], array(), array( 'id' => $fixtures['author'] ) )
		);
		self::collect_failure(
			$failures,
			$invalid_item instanceof \WP_Error
				&& 'rest_user_invalid_id' === $invalid_item->get_error_code()
				&& $existing_create instanceof \WP_Error
				&& 'rest_user_exists' === $existing_create->get_error_code()
				&& $create_denied instanceof \WP_Error
				&& 'rest_cannot_create_user' === $create_denied->get_error_code()
				&& $head_response instanceof \WP_REST_Response
				&& array() === $head_response->get_data(),
			'user invalid ID, existing create, create permission, and HEAD paths are represented',
			array(
				'invalidItem'    => $invalid_item,
				'existingCreate' => $existing_create,
				'createDenied'   => $create_denied,
				'headData'       => $head_response instanceof \WP_REST_Response ? $head_response->get_data() : $head_response,
			)
		);

		$cap_filter = self::install_cap_filter( array( 'create_users', 'delete_user', 'delete_users', 'edit_user', 'edit_users', 'list_users' ) );
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
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$updated_data = $updated instanceof \WP_REST_Response ? $updated->get_data() : array();
		self::collect_failure(
			$failures,
			$created instanceof \WP_REST_Response
				&& 201 === $created->get_status()
				&& $created_id > 0
				&& $case['createdUserLogin'] === ( $created_data['username'] ?? null )
				&& \sanitize_title( $case['createdUserSlugInput'] ) === ( $created_data['slug'] ?? null )
				&& $username_update instanceof \WP_Error
				&& 'rest_user_invalid_argument' === $username_update->get_error_code()
				&& $updated instanceof \WP_REST_Response
				&& $case['updatedUserName'] === ( $updated_data['name'] ?? null )
				&& $delete_error instanceof \WP_Error
				&& 'rest_trash_not_supported' === $delete_error->get_error_code(),
			'user create/update/delete error paths use the in-memory user table',
			array(
				'createdData'    => $created_data,
				'usernameUpdate' => $username_update,
				'updatedData'    => $updated_data,
				'deleteError'    => $delete_error,
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

	private static function check_revisions_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$controller = new \WP_REST_Revisions_Controller( 'post' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& isset( $params['include'], $params['exclude'], $params['orderby'] )
				&& ! isset( $params['per_page']['default'] )
				&& 'desc' === ( $params['order']['default'] ?? null )
				&& in_array( 'asc', $params['order']['enum'] ?? array(), true ),
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
		try {
			$read_allowed = $controller->get_item_permissions_check( $read_request );
			$item         = $controller->get_item(
				self::request(
					'GET',
					'/wp/v2/posts/' . $fixtures['post'] . '/revisions/' . $fixtures['revision'],
					array(
						'context' => 'edit',
						'_fields' => 'id,parent,title,content,_links',
					),
					array(
						'parent' => $fixtures['post'],
						'id'     => $fixtures['revision'],
					)
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}

		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		self::collect_failure(
			$failures,
			$read_denied instanceof \WP_Error
				&& 'rest_cannot_read' === $read_denied->get_error_code()
				&& true === $read_allowed
				&& $item instanceof \WP_REST_Response
				&& $fixtures['revision'] === (int) ( $item_data['id'] ?? 0 )
				&& $fixtures['post'] === (int) ( $item_data['parent'] ?? 0 )
				&& $case['revisionTitle'] === ( $item_data['title']['raw'] ?? null )
				&& $case['revisionContent'] === ( $item_data['content']['raw'] ?? null )
				&& \rest_url( 'wp/v2/posts/' . $fixtures['post'] ) === self::link_href( $item_links, 'parent' ),
			'revision get_item gates parent edit permission and exposes parent links',
			array(
				'readDenied'  => $read_denied,
				'readAllowed' => $read_allowed,
				'itemData'    => $item_data,
				'links'       => $item_links,
			)
		);

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
		self::collect_failure(
			$failures,
			$mismatch instanceof \WP_Error
				&& 'rest_revision_parent_id_mismatch' === $mismatch->get_error_code()
				&& $invalid_parent instanceof \WP_Error
				&& 'rest_post_invalid_parent' === $invalid_parent->get_error_code()
				&& $invalid_revision instanceof \WP_Error
				&& 'rest_post_invalid_id' === $invalid_revision->get_error_code()
				&& $delete_error instanceof \WP_Error
				&& 'rest_trash_not_supported' === $delete_error->get_error_code(),
			'revision parent mismatch, invalid parent/revision, and delete error paths are represented',
			array(
				'mismatch'        => $mismatch,
				'invalidParent'   => $invalid_parent,
				'invalidRevision' => $invalid_revision,
				'deleteError'     => $delete_error,
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

	private static function check_attachments_controller( \ComponentFuzz\FuzzContext $ctx, array $case, array $fixtures ): array {
		$controller = new \WP_REST_Attachments_Controller( 'attachment' );
		$failures   = array();
		$params     = $controller->get_collection_params();

		self::collect_failure(
			$failures,
			self::collection_context_param_ok( $params )
				&& isset( $params['media_type'], $params['mime_type'], $params['parent'] )
				&& 'inherit' === ( $params['status']['default'] ?? null ),
			'attachment collection params expose media filters and inherit status default',
			array( 'params' => self::param_summary( $params ) )
		);

		$item = $controller->get_item(
			self::request(
				'GET',
				'/wp/v2/media/' . $fixtures['attachment'],
				array(
					'context' => 'view',
					'_fields' => 'id,title,media_type,mime_type,source_url,alt_text,_links',
				),
				array( 'id' => $fixtures['attachment'] )
			)
		);
		$item_data = $item instanceof \WP_REST_Response ? $item->get_data() : array();
		$item_links = $item instanceof \WP_REST_Response ? $item->get_links() : array();
		self::collect_failure(
			$failures,
			$item instanceof \WP_REST_Response
				&& $fixtures['attachment'] === (int) ( $item_data['id'] ?? 0 )
				&& 'image' === ( $item_data['media_type'] ?? null )
				&& 'image/jpeg' === ( $item_data['mime_type'] ?? null )
				&& $case['attachmentAlt'] === ( $item_data['alt_text'] ?? null )
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
		$create_denied = $controller->create_item_permissions_check( self::request( 'POST', '/wp/v2/media' ) );
		$cap_filter    = self::install_cap_filter( array( 'create_posts', 'delete_others_posts', 'delete_posts', 'delete_published_posts', 'edit_posts', 'upload_files' ) );
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
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}
		self::collect_failure(
			$failures,
			$invalid_item instanceof \WP_Error
				&& 'rest_post_invalid_id' === $invalid_item->get_error_code()
				&& $create_denied instanceof \WP_Error
				&& 'rest_cannot_create' === $create_denied->get_error_code()
				&& true === $create_allowed
				&& $upload_error instanceof \WP_Error
				&& 'rest_upload_no_data' === $upload_error->get_error_code()
				&& $delete_error instanceof \WP_Error
				&& 'rest_trash_not_supported' === $delete_error->get_error_code(),
			'attachment invalid ID, upload permission, no-file upload, and delete error paths are represented without real uploads',
			array(
				'invalidItem'   => $invalid_item,
				'createDenied'  => $create_denied,
				'createAllowed' => $create_allowed,
				'uploadError'   => $upload_error,
				'deleteError'   => $delete_error,
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
			'createdPostTitle'            => 'Created REST Post ' . $ctx->int( 10, 999 ),
			'createdPostContent'          => '<p>Created content ' . $token . '</p>',
			'createdPostSlugInput'        => 'Created REST Slug ' . $token,
			'updatedPostTitle'            => 'Updated REST Post ' . $ctx->int( 10, 999 ),
			'termName'                    => 'REST Category ' . $ctx->int( 10, 999 ),
			'termSlugInput'               => 'Term Slug ' . $token,
			'createdTermName'             => 'Created Term ' . $ctx->int( 10, 999 ),
			'createdTermDescription'      => 'Generated term description ' . $token,
			'createdTermSlugInput'        => 'Created Term Slug ' . $token,
			'updatedTermName'             => 'Updated Term ' . $ctx->int( 10, 999 ),
			'commentAuthorName'           => 'Commenter ' . $ctx->int( 10, 999 ),
			'commentAuthorEmail'          => 'commenter-' . $token . '@example.test',
			'commentContent'              => 'Fixture comment ' . $token,
			'createdCommentContent'       => 'Created comment ' . $token,
			'createdCommentContentPadded' => "  Created comment {$token}\n",
			'updatedCommentContent'       => 'Updated comment ' . $token,
			'createdUserLogin'            => 'cfz_user_' . $token,
			'createdUserEmail'            => 'created-' . $token . '@example.test',
			'createdUserName'             => 'Created User ' . $ctx->int( 10, 999 ),
			'createdUserPassword'         => 'pass-' . $token . '-A1',
			'createdUserSlugInput'        => 'Created User ' . $token,
			'updatedUserName'             => 'Updated User ' . $ctx->int( 10, 999 ),
			'revisionTitle'               => 'Revision Title ' . $ctx->int( 10, 999 ),
			'revisionContent'             => '<p>Revision content ' . $token . '</p>',
			'attachmentTitle'             => 'Attachment ' . $ctx->int( 10, 999 ),
			'attachmentAlt'               => 'Alt text ' . $token,
			'attachmentFile'              => 'component-fuzz-' . $token . '.jpg',
		);
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
			'attachment' => (int) $attachment,
			'author'     => (int) $author,
			'comment'    => (int) $comment,
			'other_post' => (int) $other_post,
			'post'       => (int) $post,
			'revision'   => (int) $revision,
			'term'       => (int) $term['term_id'],
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
