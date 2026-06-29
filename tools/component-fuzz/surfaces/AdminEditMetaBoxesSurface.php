<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes classic edit-screen meta box callbacks and default registration.
 */
final class AdminEditMetaBoxesSurface {
	public const NAME = 'admin-edit-metaboxes';

	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-edit-metaboxes.bootstrap-apis-available',
					'Required admin edit meta box APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_publish_box_matrix( $ctx->fork( 'publish-box' ) );
			$rows[] = self::check_taxonomy_meta_boxes( $ctx->fork( 'taxonomy-boxes' ) );
			$rows[] = self::check_content_meta_boxes( $ctx->fork( 'content-boxes' ) );
			$rows[] = self::check_page_media_and_link_boxes( $ctx->fork( 'page-media-link' ) );
			$rows[] = self::check_default_registration_matrix( $ctx->fork( 'registration' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'admin-edit-metaboxes.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'admin-edit-metaboxes.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'contentCounts'  => self::content_counts(),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Post', 'WP_Screen', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_meta_box',
				'add_post_meta',
				'add_post_type_support',
				'add_theme_support',
				'attachment_id3_data_meta_box',
				'attachment_submit_meta_box',
				'comments_open',
				'current_user_can',
				'do_meta_boxes',
				'get_current_screen',
				'get_hidden_meta_boxes',
				'get_post',
				'get_post_meta',
				'get_terms_to_edit',
				'link_advanced_meta_box',
				'link_submit_meta_box',
				'link_target_meta_box',
				'link_xfn_meta_box',
				'page_attributes_meta_box',
				'post_author_meta_box',
				'post_categories_meta_box',
				'post_comment_meta_box',
				'post_comment_meta_box_thead',
				'post_comment_status_meta_box',
				'post_custom_meta_box',
				'post_excerpt_meta_box',
				'post_format_meta_box',
				'post_slug_meta_box',
				'post_submit_meta_box',
				'post_tags_meta_box',
				'post_thumbnail_meta_box',
				'post_trackback_meta_box',
				'register_and_do_post_meta_boxes',
				'register_post_type',
				'register_taxonomy',
				'remove_filter',
				'set_current_screen',
				'stick_post',
				'update_post_meta',
				'wp_insert_comment',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_set_current_user',
				'wp_set_object_terms',
				'xfn_check',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_publish_box_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );
		\set_current_screen( 'post' );

		$statuses = array( 'draft', 'pending', 'publish', 'future', 'private', 'auto-draft' );
		$cases    = array();
		foreach ( $statuses as $status ) {
			$case_ctx = $ctx->fork( 'status-' . $status );
			$cases[]  = array(
				'status'      => $status,
				'canPublish'  => $case_ctx->bool( 70 ),
				'futureDate'  => 'future' === $status || $case_ctx->bool( 35 ),
				'sticky'      => $case_ctx->bool( 45 ),
				'password'    => in_array( $status, array( 'private', 'auto-draft' ), true ) ? '' : self::safe_text( $case_ctx->fork( 'password' ), 4, 18 ),
				'revisions'   => $case_ctx->bool( 45 ) ? $case_ctx->int( 2, 7 ) : 0,
				'revision_id' => $case_ctx->int( 200, 900 ),
			);
		}

		$old_action = $GLOBALS['action'] ?? null;
		$had_action = array_key_exists( 'action', $GLOBALS );
		$GLOBALS['action'] = 'edit';

		foreach ( $cases as $case ) {
			$post_id = self::insert_post(
				array(
					'post_type'     => 'post',
					'post_status'   => $case['status'],
					'post_title'    => 'Publish box ' . $case['status'],
					'post_password' => $case['password'],
					'post_date'     => $case['futureDate'] ? '2036-05-06 07:08:09' : '2024-05-06 07:08:09',
					'post_date_gmt' => $case['futureDate'] ? '2036-05-06 07:08:09' : '2024-05-06 07:08:09',
				)
			);
			if ( $case['sticky'] ) {
				\stick_post( $post_id );
			}

			$post = \get_post( $post_id );
			$box_post = clone $post;
			$args = array(
				'id'       => 'submitdiv',
				'title'    => 'Publish',
				'callback' => 'post_submit_meta_box',
				'args'     => array(
					'revisions_count' => $case['revisions'],
					'revision_id'     => $post_id,
				),
			);

			$html = self::with_capability_denials(
				$case['canPublish'] ? array() : array( 'publish_posts' ),
				static function () use ( $box_post, $args ): string {
					return self::capture_output(
						static function () use ( $box_post, $args ): void {
							$had_post = array_key_exists( 'post', $GLOBALS );
							$old_post = $GLOBALS['post'] ?? null;
							$GLOBALS['post'] = $box_post;
							try {
								\post_submit_meta_box( $box_post, $args );
							} finally {
								if ( $had_post ) {
									$GLOBALS['post'] = $old_post;
								} else {
									unset( $GLOBALS['post'] );
								}
							}
						}
					);
				}
			);

			$expected_visibility = self::expected_visibility( $case['status'], $case['password'], $case['sticky'] );
			$expected_action     = self::expected_publish_action( $case );

			self::collect_failure(
				$failures,
				str_contains( $html, 'class="submitbox"' )
					&& str_contains( $html, 'id="submitpost"' )
					&& str_contains( $html, 'id="post-status-display"' )
					&& str_contains( $html, 'id="post-visibility-display"' ),
				'post_submit_meta_box() renders the expected publish-box shell and status/visibility sections',
				array( 'case' => $case, 'html' => self::describe_string( $html ) )
			);
			self::collect_failure(
				$failures,
				str_contains( $html, $expected_visibility )
					&& str_contains( $html, 'value="' . \esc_attr( $expected_action ) . '"' ),
				'post_submit_meta_box() selects generated visibility and primary action branches',
				array(
					'case'               => $case,
					'expectedVisibility' => $expected_visibility,
					'expectedAction'     => $expected_action,
					'html'               => self::describe_string( $html ),
				)
			);
			self::collect_failure(
				$failures,
				$case['canPublish'] === str_contains( $html, 'class="edit-visibility hide-if-no-js"' )
					&& $case['canPublish'] === str_contains( $html, 'id="timestampdiv"' ),
				'post_submit_meta_box() gates visibility and timestamp editors on publish capability',
				array( 'case' => $case, 'html' => self::describe_string( $html ) )
			);
			self::collect_failure(
				$failures,
				( 0 === $case['revisions'] ) === ! str_contains( $html, 'misc-pub-revisions' ),
				'post_submit_meta_box() includes revision browse UI only when revision callback args request it',
				array( 'case' => $case, 'html' => self::describe_string( $html ) )
			);
			self::collect_failure(
				$failures,
				'private' !== $case['status'] || '' === $box_post->post_password,
				'post_submit_meta_box() clears the working post password for private visibility',
				array( 'case' => $case, 'mutatedPassword' => $box_post->post_password )
			);
		}

		if ( $had_action ) {
			$GLOBALS['action'] = $old_action;
		} else {
			unset( $GLOBALS['action'] );
		}

		return self::result_from_failures(
			$ctx,
			'admin-edit-metaboxes.publish-box.status-visibility-actions',
			$failures,
			array( 'cases' => $cases )
		);
	}

	private static function check_taxonomy_meta_boxes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );
		\set_current_screen( 'post' );

		$flat_tax = self::key( $ctx->fork( 'flat-tax' ), 'cfz_flat_tax', 28 );
		$tree_tax = self::key( $ctx->fork( 'tree-tax' ), 'cfz_tree_tax', 28 );
		$assign_cap = 'assign_' . $flat_tax;
		$edit_cap   = 'edit_' . $tree_tax;

		\register_taxonomy(
			$flat_tax,
			'post',
			array(
				'public'       => false,
				'show_ui'      => true,
				'hierarchical' => false,
				'labels'       => array(
					'name'                       => 'Flat Terms & ' . self::safe_text( $ctx->fork( 'flat-label' ), 2, 10 ),
					'add_new_item'               => 'Add Flat Term',
					'add_or_remove_items'        => 'Add or remove flat terms',
					'choose_from_most_used'      => 'Choose from used flat terms',
					'separate_items_with_commas' => 'Separate generated terms',
					'no_terms'                   => 'No generated terms',
				),
				'capabilities' => array(
					'assign_terms' => $assign_cap,
				),
			)
		);
		\register_taxonomy(
			$tree_tax,
			'post',
			array(
				'public'       => false,
				'show_ui'      => true,
				'hierarchical' => true,
				'labels'       => array(
					'name'              => 'Tree Terms & ' . self::safe_text( $ctx->fork( 'tree-label' ), 2, 10 ),
					'all_items'         => 'All generated tree terms',
					'most_used'         => 'Most used generated tree terms',
					'add_new_item'      => 'Add Tree Term',
					'new_item_name'     => 'New generated tree term',
					'parent_item'       => 'Parent generated tree term',
					'parent_item_colon' => 'Parent generated tree term:',
				),
				'capabilities' => array(
					'assign_terms' => 'assign_' . $tree_tax,
					'edit_terms'   => $edit_cap,
				),
			)
		);

		$post_id = self::insert_post( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$terms   = array(
			\wp_insert_term( 'Alpha ' . self::safe_text( $ctx->fork( 'flat-alpha' ), 2, 8 ), $flat_tax ),
			\wp_insert_term( 'Beta ' . self::safe_text( $ctx->fork( 'flat-beta' ), 2, 8 ), $flat_tax ),
			\wp_insert_term( 'Parent ' . self::safe_text( $ctx->fork( 'tree-parent' ), 2, 8 ), $tree_tax ),
		);
		$flat_term_ids = array();
		foreach ( $terms as $term ) {
			if ( is_array( $term ) && isset( $term['term_id'] ) ) {
				$flat_term_ids[] = (int) $term['term_id'];
			}
		}
		\wp_set_object_terms( $post_id, array_slice( $flat_term_ids, 0, 2 ), $flat_tax, false );

		$post = \get_post( $post_id );
		$flat_allowed = self::with_capability_denials(
			array(),
			static function () use ( $post, $flat_tax ): string {
				return self::capture_output(
					static function () use ( $post, $flat_tax ): void {
						\post_tags_meta_box( $post, array( 'args' => array( 'taxonomy' => $flat_tax ) ) );
					}
				);
			}
		);
		$flat_denied = self::with_capability_denials(
			array( $assign_cap ),
			static function () use ( $post, $flat_tax ): string {
				return self::capture_output(
					static function () use ( $post, $flat_tax ): void {
						\post_tags_meta_box( $post, array( 'args' => array( 'taxonomy' => $flat_tax ) ) );
					}
				);
			}
		);

		$dropdown_args_seen = array();
		$dropdown_filter = static function ( array $args ) use ( &$dropdown_args_seen ): array {
			$dropdown_args_seen[] = $args;
			return $args;
		};
		\add_filter( 'post_edit_category_parent_dropdown_args', $dropdown_filter );
		$tree_allowed = self::with_capability_denials(
			array(),
			static function () use ( $post, $tree_tax ): string {
				return self::capture_output(
					static function () use ( $post, $tree_tax ): void {
						\post_categories_meta_box( $post, array( 'args' => array( 'taxonomy' => $tree_tax ) ) );
					}
				);
			}
		);
		\remove_filter( 'post_edit_category_parent_dropdown_args', $dropdown_filter );

		$tree_denied = self::with_capability_denials(
			array( $edit_cap ),
			static function () use ( $post, $tree_tax ): string {
				return self::capture_output(
					static function () use ( $post, $tree_tax ): void {
						\post_categories_meta_box( $post, array( 'args' => array( 'taxonomy' => $tree_tax ) ) );
					}
				);
			}
		);

		self::collect_failure(
			$failures,
			str_contains( $flat_allowed, 'class="tagsdiv"' )
				&& str_contains( $flat_allowed, 'id="' . \esc_attr( $flat_tax ) . '"' )
				&& str_contains( $flat_allowed, 'name="tax_input[' . $flat_tax . ']"' )
				&& str_contains( $flat_allowed, 'data-wp-taxonomy="' . $flat_tax . '"' )
				&& str_contains( $flat_allowed, 'tagcloud-link' )
				&& ! str_contains( $flat_allowed, ' disabled=' ),
			'post_tags_meta_box() renders assignable flat taxonomy controls and the JS tag adder',
			array( 'taxonomy' => $flat_tax, 'html' => self::describe_string( $flat_allowed ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $flat_denied, ' disabled=' )
				&& ! str_contains( $flat_denied, 'class="newtag form-input-tip"' )
				&& ! str_contains( $flat_denied, 'tagcloud-link' ),
			'post_tags_meta_box() disables textarea input and hides add/cloud controls without assign_terms',
			array( 'taxonomy' => $flat_tax, 'html' => self::describe_string( $flat_denied ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $tree_allowed, 'id="taxonomy-' . $tree_tax . '"' )
				&& str_contains( $tree_allowed, "name='tax_input[{$tree_tax}][]' value='0'" )
				&& str_contains( $tree_allowed, 'data-wp-lists="list:' . $tree_tax . '"' )
				&& str_contains( $tree_allowed, 'id="' . $tree_tax . '-adder"' )
				&& isset( $dropdown_args_seen[0]['taxonomy'] )
				&& $tree_tax === $dropdown_args_seen[0]['taxonomy']
				&& 'new' . $tree_tax . '_parent' === $dropdown_args_seen[0]['name'],
			'post_categories_meta_box() renders hierarchical checklist, zero sentinel, adder, and filtered parent dropdown args',
			array(
				'taxonomy'     => $tree_tax,
				'dropdownArgs' => $dropdown_args_seen,
				'html'         => self::describe_string( $tree_allowed ),
			)
		);
		self::collect_failure(
			$failures,
			! str_contains( $tree_denied, 'id="' . $tree_tax . '-adder"' )
				&& str_contains( $tree_denied, "name='tax_input[{$tree_tax}][]' value='0'" ),
			'post_categories_meta_box() keeps submitted empty-set sentinel but hides the adder without edit_terms',
			array( 'taxonomy' => $tree_tax, 'html' => self::describe_string( $tree_denied ) )
		);

		return self::result_from_failures(
			$ctx,
			'admin-edit-metaboxes.taxonomy.flat-and-hierarchical-callbacks',
			$failures,
			array(
				'flatTaxonomy' => $flat_tax,
				'treeTaxonomy' => $tree_tax,
			)
		);
	}

	private static function check_content_meta_boxes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );
		\set_current_screen( 'post' );

		$excerpt = self::safe_text( $ctx->fork( 'excerpt' ), 8, 36 );
		$slug    = 'slug-' . self::key( $ctx->fork( 'slug' ), 'piece', 16 );
		$post_id = self::insert_post(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post_title'     => 'Content boxes',
				'post_excerpt'   => $excerpt,
				'post_name'      => $slug,
				'to_ping'        => "https://one.example/a\nhttps://two.example/b",
				'pinged'         => "https://pinged.example/a?x=<tag>\nhttps://pinged.example/b&y=1",
				'comment_status' => $ctx->bool() ? 'open' : 'closed',
				'ping_status'    => $ctx->bool() ? 'open' : 'closed',
			)
		);
		\add_post_meta( $post_id, 'visible_' . self::key( $ctx->fork( 'meta-key' ), 'key', 12 ), self::safe_text( $ctx->fork( 'meta-value' ), 4, 20 ) );
		\add_post_meta( $post_id, '_protected_' . self::key( $ctx->fork( 'hidden-key' ), 'key', 12 ), 'hidden-value' );

		$post = \get_post( $post_id );

		$excerpt_html = self::capture_output(
			static function () use ( $post ): void {
				\post_excerpt_meta_box( $post );
			}
		);
		$trackback_html = self::capture_output(
			static function () use ( $post ): void {
				\post_trackback_meta_box( $post );
			}
		);
		$custom_html = self::with_capability_denials(
			array(),
			static function () use ( $post ): string {
				return self::capture_output(
					static function () use ( $post ): void {
						\post_custom_meta_box( $post );
					}
				);
			}
		);
		$comment_status_html = self::capture_output(
			static function () use ( $post ): void {
				\post_comment_status_meta_box( $post );
			}
		);

		$filtered_slug = 'filtered-&quot;' . self::key( $ctx->fork( 'filtered-slug' ), 'slug', 14 );
		$slug_filter = static function () use ( $filtered_slug ): string {
			return $filtered_slug;
		};
		\add_filter( 'editable_slug', $slug_filter );
		$slug_html = self::capture_output(
			static function () use ( $post ): void {
				\post_slug_meta_box( $post );
			}
		);
		\remove_filter( 'editable_slug', $slug_filter );

		$thead = \post_comment_meta_box_thead(
			array(
				'cb'       => 'checkbox',
				'author'   => 'Author',
				'comment'  => 'Comment',
				'response' => 'Response',
				'date'     => 'Date',
			)
		);

		$comment_id = \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Fuzzer',
				'comment_author_email' => 'fuzzer@example.test',
				'comment_content'      => 'Generated comment ' . self::safe_text( $ctx->fork( 'comment' ), 4, 16 ),
				'comment_approved'     => '1',
			)
		);
		$comment_html = self::capture_output(
			static function () use ( $post ): void {
				\post_comment_meta_box( $post );
			}
		);

		self::collect_failure(
			$failures,
			str_contains( $excerpt_html, 'name="excerpt" id="excerpt"' )
				&& str_contains( $excerpt_html, $excerpt ),
			'post_excerpt_meta_box() preserves generated excerpt content inside the textarea',
			array( 'excerpt' => $excerpt, 'html' => self::describe_string( $excerpt_html ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $trackback_html, 'name="trackback_url" id="trackback_url"' )
				&& str_contains( $trackback_html, 'https://one.example/a https://two.example/b' )
				&& str_contains( $trackback_html, 'https://pinged.example/a?x=&lt;tag&gt;' )
				&& str_contains( $trackback_html, 'https://pinged.example/b&amp;y=1' ),
			'post_trackback_meta_box() normalizes to_ping newlines and escapes pinged URLs',
			array( 'html' => self::describe_string( $trackback_html ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $custom_html, 'id="postcustomstuff"' )
				&& str_contains( $custom_html, 'visible_' )
				&& ! str_contains( $custom_html, '_protected_' ),
			'post_custom_meta_box() lists editable custom fields while omitting protected meta keys',
			array( 'html' => self::describe_string( $custom_html ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $comment_status_html, 'name="advanced_view" type="hidden" value="1"' )
				&& ( ( 'open' === $post->comment_status ) === self::input_is_checked( $comment_status_html, 'comment_status', 'open' ) )
				&& ( ( 'open' === $post->ping_status ) === self::input_is_checked( $comment_status_html, 'ping_status', 'open' ) ),
			'post_comment_status_meta_box() mirrors generated comment and ping openness in checkbox state',
			array(
				'commentStatus' => $post->comment_status,
				'pingStatus'    => $post->ping_status,
				'html'          => self::describe_string( $comment_status_html ),
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $slug_html, 'name="post_name" type="text"' )
				&& str_contains( $slug_html, 'value="' . \esc_attr( $filtered_slug ) . '"' ),
			'post_slug_meta_box() applies editable_slug and escapes the filtered value',
			array( 'filteredSlug' => $filtered_slug, 'html' => self::describe_string( $slug_html ) )
		);
		self::collect_failure(
			$failures,
			! isset( $thead['cb'], $thead['response'] )
				&& isset( $thead['author'], $thead['comment'], $thead['date'] ),
			'post_comment_meta_box_thead() removes checkbox/response columns and keeps content columns',
			array( 'thead' => $thead )
		);
		self::collect_failure(
			$failures,
			$comment_id > 0
				&& str_contains( $comment_html, 'id="add-new-comment"' )
				&& str_contains( $comment_html, 'comments-box' )
				&& str_contains( $comment_html, 'id="no-comments"' )
				&& ! str_contains( $comment_html, 'id="show-comments"' ),
			'post_comment_meta_box() renders add-comment controls, list table shell, and the empty-comments fallback under the stubbed comment count query',
			array( 'commentId' => $comment_id, 'html' => self::describe_string( $comment_html ) )
		);

		return self::result_from_failures(
			$ctx,
			'admin-edit-metaboxes.content-comment-and-custom-field-callbacks',
			$failures,
			array( 'postId' => $post_id )
		);
	}

	private static function check_page_media_and_link_boxes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );

		$page_type = self::key( $ctx->fork( 'page-type' ), 'cfz_page_type', 20 );
		\register_post_type(
			$page_type,
			array(
				'label'        => 'Generated Pages',
				'public'       => false,
				'show_ui'      => true,
				'hierarchical' => true,
				'supports'     => array( 'title', 'page-attributes', 'author', 'thumbnail' ),
			)
		);
		\set_current_screen( $page_type );

		$parent_id = self::insert_post(
			array(
				'post_type'   => $page_type,
				'post_status' => 'publish',
				'post_title'  => 'Parent page',
			)
		);
		$page_id = self::insert_post(
			array(
				'post_type'     => $page_type,
				'post_status'   => 'draft',
				'post_title'    => 'Child page',
				'post_parent'   => $parent_id,
				'menu_order'    => $ctx->int( -5, 15 ),
			)
		);
		$page = \get_post( $page_id );
		$page->page_template = 'templates/generated.php';

		$page_dropdown_args = array();
		$page_dropdown_filter = static function ( array $args ) use ( &$page_dropdown_args ): array {
			$page_dropdown_args[] = $args;
			return $args;
		};
		$template_filter = static function ( array $templates ) use ( $page_type ): array {
			$templates['templates/generated.php'] = 'Generated Template ' . $page_type;
			return $templates;
		};
		$default_title_filter = static function (): string {
			return 'Generated Default Template';
		};
		\add_filter( 'page_attributes_dropdown_pages_args', $page_dropdown_filter, 10, 2 );
		\add_filter( 'theme_' . $page_type . '_templates', $template_filter, 10, 4 );
		\add_filter( 'default_page_template_title', $default_title_filter, 10, 2 );
		$page_html = self::capture_output(
			static function () use ( $page ): void {
				\page_attributes_meta_box( $page );
			}
		);
		\remove_filter( 'page_attributes_dropdown_pages_args', $page_dropdown_filter, 10 );
		\remove_filter( 'theme_' . $page_type . '_templates', $template_filter, 10 );
		\remove_filter( 'default_page_template_title', $default_title_filter, 10 );

		\add_theme_support( 'post-formats', array( 'aside', 'gallery', 'quote' ) );
		\add_post_type_support( 'post', 'post-formats' );
		$format_post_id = self::insert_post( array( 'post_type' => 'post', 'post_status' => 'draft' ) );
		\set_post_format( $format_post_id, 'quote' );
		$format_html = self::capture_output(
			static function () use ( $format_post_id ): void {
				\post_format_meta_box( \get_post( $format_post_id ), array( 'args' => array() ) );
			}
		);

		$attachment_id = self::insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Generated audio',
				'post_mime_type' => 'audio/mpeg',
				'post_date'      => '2025-02-03 04:05:06',
				'post_date_gmt'  => '2025-02-03 04:05:06',
			)
		);
		\update_post_meta(
			$attachment_id,
			'_wp_attachment_metadata',
			array(
				'artist' => 'Artist ' . self::safe_text( $ctx->fork( 'artist' ), 2, 12 ),
				'album'  => 'Album ' . self::safe_text( $ctx->fork( 'album' ), 2, 12 ),
				'length' => $ctx->int( 15, 300 ),
			)
		);
		\update_post_meta( $attachment_id, '_thumbnail_id', $format_post_id );
		$attachment = \get_post( $attachment_id );
		$attachment_submit_html = self::with_capability_denials(
			array(),
			static function () use ( $attachment ): string {
				return self::capture_output(
					static function () use ( $attachment ): void {
						\attachment_submit_meta_box( $attachment );
					}
				);
			}
		);
		$id3_html = self::capture_output(
			static function () use ( $attachment ): void {
				\attachment_id3_data_meta_box( $attachment );
			}
		);
		$thumbnail_html = self::capture_output(
			static function () use ( $attachment ): void {
				\post_thumbnail_meta_box( $attachment );
			}
		);

		$link = (object) array(
			'link_id'      => $ctx->int( 10, 999),
			'link_name'    => 'Generated Link ' . self::safe_text( $ctx->fork( 'link-name' ), 2, 10 ),
			'link_url'     => 'https://example.test/' . self::key( $ctx->fork( 'link-url' ), 'path', 12 ),
			'link_visible' => $ctx->bool() ? 'Y' : 'N',
			'link_target'  => $ctx->choice( array( '_blank', '_top', '' ) ),
			'link_rel'     => 'friend met co-worker ' . ( $ctx->bool() ? 'me' : '' ),
			'link_image'   => 'https://example.test/image.png?x=1&y=2',
			'link_rss'     => 'https://example.test/feed?x=1&y=2',
			'link_notes'   => 'Notes ' . self::safe_text( $ctx->fork( 'notes' ), 4, 18 ),
			'link_rating'  => $ctx->int( 0, 10 ),
		);
		$GLOBALS['link'] = $link;
		$old_get = $_GET;
		$_GET['action'] = 'edit';

		$link_submit_html = self::with_capability_denials(
			array(),
			static function () use ( $link ): string {
				return self::capture_output(
					static function () use ( $link ): void {
						\link_submit_meta_box( $link );
					}
				);
			}
		);
		$link_target_html = self::capture_output(
			static function () use ( $link ): void {
				\link_target_meta_box( $link );
			}
		);
		$link_xfn_html = self::capture_output(
			static function () use ( $link ): void {
				\link_xfn_meta_box( $link );
			}
		);
		$link_advanced_html = self::capture_output(
			static function () use ( $link ): void {
				\link_advanced_meta_box( $link );
			}
		);
		$_GET = $old_get;

		self::collect_failure(
			$failures,
			isset( $page_dropdown_args[0]['post_type'], $page_dropdown_args[0]['exclude_tree'], $page_dropdown_args[0]['selected'] )
				&& $page_type === $page_dropdown_args[0]['post_type']
				&& $page_id === (int) $page_dropdown_args[0]['exclude_tree']
				&& $parent_id === (int) $page_dropdown_args[0]['selected']
				&& str_contains( $page_html, 'name="page_template" id="page_template"' )
				&& str_contains( $page_html, 'Generated Default Template' )
				&& self::option_is_selected( $page_html, 'templates/generated.php' )
				&& str_contains( $page_html, 'name="menu_order" type="text"' ),
			'page_attributes_meta_box() filters parent dropdown args and renders template/menu-order controls',
			array(
				'pageType'     => $page_type,
				'dropdownArgs' => $page_dropdown_args,
				'html'         => self::describe_string( $page_html ),
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $format_html, 'id="post-formats-select"' )
				&& self::input_is_checked( $format_html, 'post_format', 'quote' )
				&& str_contains( $format_html, 'value="aside"' )
				&& str_contains( $format_html, 'value="gallery"' ),
			'post_format_meta_box() renders supported formats and checks the generated current format',
			array( 'html' => self::describe_string( $format_html ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $attachment_submit_html, 'Uploaded on:' )
				&& str_contains( $attachment_submit_html, 'id="publish" value="Update"' )
				&& str_contains( $attachment_submit_html, 'Delete permanently' ),
			'attachment_submit_meta_box() renders uploaded timestamp, update button, and current delete branch',
			array( 'html' => self::describe_string( $attachment_submit_html ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $id3_html, 'name="id3_artist"' )
				&& str_contains( $id3_html, 'name="id3_album"' )
				&& str_contains( $id3_html, 'class="large-text"' ),
			'attachment_id3_data_meta_box() renders editable ID3 fields for generated audio metadata',
			array( 'html' => self::describe_string( $id3_html ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $thumbnail_html, 'name="_thumbnail_id"' )
				|| str_contains( $thumbnail_html, 'set-post-thumbnail' )
				|| str_contains( $thumbnail_html, 'postimagediv' ),
			'post_thumbnail_meta_box() delegates to thumbnail HTML helper without dropping the thumbnail control shell',
			array( 'html' => self::describe_string( $thumbnail_html ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $link_submit_html, 'id="submitlink"' )
				&& str_contains( $link_submit_html, 'Visit Link' )
				&& ! str_contains( $link_submit_html, '>Delete<' )
				&& str_contains( $link_submit_html, 'value="Update Link"' )
				&& ( ( 'N' === $link->link_visible ) === self::input_is_checked( $link_submit_html, 'link_visible', 'N' ) ),
			'link_submit_meta_box() renders visit/update/private branches and hides delete when manage_links maps to do_not_allow',
			array( 'link' => self::object_summary( $link ), 'html' => self::describe_string( $link_submit_html ) )
		);
		self::collect_failure(
			$failures,
			self::input_is_checked( $link_target_html, 'link_target', $link->link_target )
				&& self::input_is_checked( $link_xfn_html, 'friendship', 'friend' )
				&& self::input_is_checked( $link_xfn_html, 'physical', 'met' )
				&& self::input_is_checked( $link_xfn_html, 'professional', 'co-worker' )
				&& str_contains( $link_advanced_html, 'name="link_image"')
				&& str_contains( $link_advanced_html, 'value="' . \esc_attr( $link->link_image ) . '"' )
				&& str_contains( $link_advanced_html, 'name="link_rating"')
				&& self::option_is_selected( $link_advanced_html, (string) $link->link_rating ),
			'link target, XFN, and advanced boxes mirror generated link fields and escape attributes',
			array(
				'link'         => self::object_summary( $link ),
				'targetHtml'   => self::describe_string( $link_target_html ),
				'xfnHtml'      => self::describe_string( $link_xfn_html ),
				'advancedHtml' => self::describe_string( $link_advanced_html ),
			)
		);

		return self::result_from_failures(
			$ctx,
			'admin-edit-metaboxes.page-media-format-and-link-callbacks',
			$failures,
			array( 'pageType' => $page_type, 'attachmentId' => $attachment_id )
		);
	}

	private static function check_default_registration_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );

		$post_type = self::key( $ctx->fork( 'post-type' ), 'cfz_edit', 20 );
		$flat_tax  = self::key( $ctx->fork( 'flat-tax' ), 'cfz_reg_flat', 24 );
		$tree_tax  = self::key( $ctx->fork( 'tree-tax' ), 'cfz_reg_tree', 24 );
		$supports  = array( 'title', 'editor', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'author', 'page-attributes', 'thumbnail', 'revisions', 'post-formats' );

		\register_post_type(
			$post_type,
			array(
				'label'        => 'Generated Edit Surface',
				'public'       => false,
				'show_ui'      => true,
				'hierarchical' => true,
				'supports'     => $supports,
			)
		);
		\register_taxonomy(
			$flat_tax,
			$post_type,
			array(
				'label'        => 'Generated Flat Registration',
				'public'       => false,
				'show_ui'      => true,
				'hierarchical' => false,
			)
		);
		\register_taxonomy(
			$tree_tax,
			$post_type,
			array(
				'label'        => 'Generated Tree Registration',
				'public'       => false,
				'show_ui'      => true,
				'hierarchical' => true,
			)
		);
		\add_theme_support( 'post-thumbnails', array( $post_type ) );
		\add_theme_support( 'post-formats', array( 'aside', 'quote' ) );
		\set_current_screen( $post_type );

		$post_id = self::insert_post(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'post_title'     => 'Registration matrix',
				'post_name'      => 'registration-matrix',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'comment_count'  => 1,
			)
		);
		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_author'   => 'Registration',
				'comment_content'  => 'Registration matrix comment',
				'comment_approved' => '1',
			)
		);

		$events = array();
		$generic_hook = static function ( string $object_type, $object ) use ( &$events ): void {
			$events[] = array( 'hook' => 'add_meta_boxes', 'object_type' => $object_type, 'object_id' => is_object( $object ) && isset( $object->ID ) ? (int) $object->ID : null );
		};
		$specific_hook = static function ( $object ) use ( &$events, $post_type ): void {
			$events[] = array( 'hook' => 'add_meta_boxes_' . $post_type, 'object_id' => is_object( $object ) && isset( $object->ID ) ? (int) $object->ID : null );
		};
		$do_hook = static function ( string $object_type, string $context, $object ) use ( &$events ): void {
			$events[] = array( 'hook' => 'do_meta_boxes', 'object_type' => $object_type, 'context' => $context, 'object_id' => is_object( $object ) && isset( $object->ID ) ? (int) $object->ID : null );
		};
		\add_action( 'add_meta_boxes', $generic_hook, 10, 2 );
		\add_action( 'add_meta_boxes_' . $post_type, $specific_hook, 10, 1 );
		\add_action( 'do_meta_boxes', $do_hook, 10, 3 );

		self::with_capability_denials(
			array(),
			static function () use ( $post_id ): void {
				\register_and_do_post_meta_boxes( \get_post( $post_id ) );
			}
		);

		\remove_action( 'add_meta_boxes', $generic_hook, 10 );
		\remove_action( 'add_meta_boxes_' . $post_type, $specific_hook, 10 );
		\remove_action( 'do_meta_boxes', $do_hook, 10 );

		$boxes = self::flatten_meta_boxes( $GLOBALS['wp_meta_boxes'][ $post_type ] ?? array() );
		$expected = array(
			'submitdiv'         => array( 'context' => 'side', 'priority' => 'core', 'callback' => 'post_submit_meta_box' ),
			'tagsdiv-' . $flat_tax => array( 'context' => 'side', 'priority' => 'core', 'callback' => 'post_tags_meta_box' ),
			$tree_tax . 'div'  => array( 'context' => 'side', 'priority' => 'core', 'callback' => 'post_categories_meta_box' ),
			'pageparentdiv'    => array( 'context' => 'side', 'priority' => 'core', 'callback' => 'page_attributes_meta_box' ),
			'postimagediv'     => array( 'context' => 'side', 'priority' => 'low', 'callback' => 'post_thumbnail_meta_box' ),
			'postexcerpt'      => array( 'context' => 'normal', 'priority' => 'core', 'callback' => 'post_excerpt_meta_box' ),
			'trackbacksdiv'    => array( 'context' => 'normal', 'priority' => 'core', 'callback' => 'post_trackback_meta_box' ),
			'postcustom'       => array( 'context' => 'normal', 'priority' => 'core', 'callback' => 'post_custom_meta_box' ),
			'commentstatusdiv' => array( 'context' => 'normal', 'priority' => 'core', 'callback' => 'post_comment_status_meta_box' ),
			'commentsdiv'      => array( 'context' => 'normal', 'priority' => 'core', 'callback' => 'post_comment_meta_box' ),
			'slugdiv'          => array( 'context' => 'normal', 'priority' => 'core', 'callback' => 'post_slug_meta_box' ),
			'authordiv'        => array( 'context' => 'normal', 'priority' => 'core', 'callback' => 'post_author_meta_box' ),
			'formatdiv'        => array( 'context' => 'side', 'priority' => 'core', 'callback' => 'post_format_meta_box' ),
		);

		foreach ( $expected as $id => $expect ) {
			$actual = $boxes[ $id ] ?? null;
			self::collect_failure(
				$failures,
				is_array( $actual )
					&& $expect['context'] === $actual['context']
					&& $expect['priority'] === $actual['priority']
					&& $expect['callback'] === $actual['callback']
					&& true === ( $actual['args']['__back_compat_meta_box'] ?? false ),
				'register_and_do_post_meta_boxes() registers expected built-in box ' . $id,
				array( 'expected' => $expect, 'actual' => $actual )
			);
		}

		self::collect_failure(
			$failures,
			self::event_seen( $events, 'add_meta_boxes', $post_type, null, $post_id )
				&& self::event_seen( $events, 'add_meta_boxes_' . $post_type, null, null, $post_id ),
			'register_and_do_post_meta_boxes() fires generic and post-type-specific add_meta_boxes hooks with the post object',
			array( 'events' => $events )
		);
		self::collect_failure(
			$failures,
			self::event_seen( $events, 'do_meta_boxes', $post_type, 'normal', $post_id )
				&& self::event_seen( $events, 'do_meta_boxes', $post_type, 'advanced', $post_id )
				&& self::event_seen( $events, 'do_meta_boxes', $post_type, 'side', $post_id ),
			'register_and_do_post_meta_boxes() runs do_meta_boxes for normal, advanced, and side contexts',
			array( 'events' => $events )
		);

		return self::result_from_failures(
			$ctx,
			'admin-edit-metaboxes.registration.default-boxes-hooks-and-contexts',
			$failures,
			array(
				'postType' => $post_type,
				'boxes'    => $boxes,
				'events'   => $events,
			)
		);
	}

	private static function reset_runtime(): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		if ( function_exists( 'create_initial_post_types' ) ) {
			\create_initial_post_types();
		}
		if ( function_exists( 'create_initial_taxonomies' ) ) {
			\create_initial_taxonomies();
		}

		$GLOBALS['wp_meta_boxes'] = array();
		$GLOBALS['typenow']       = '';
		$GLOBALS['taxnow']        = '';
		$GLOBALS['pagenow']       = 'post.php';
		if ( class_exists( 'WP_Rewrite' ) ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}
		$_GET                    = array();
		$_POST                   = array();
		$_REQUEST                = array();

		\set_current_screen( 'dashboard' );
	}

	private static function seed_user( \ComponentFuzz\FuzzContext $ctx ): int {
		$id = \wp_insert_user(
			array(
				'user_login'   => 'cfz_admin_meta_' . self::key( $ctx, 'user', 12 ),
				'user_pass'    => 'password',
				'user_email'   => 'cfz-admin-meta-' . $ctx->int( 1000, 9999 ) . '@example.test',
				'display_name' => 'Meta Box Fuzzer ' . self::safe_text( $ctx, 2, 8 ),
				'role'         => 'administrator',
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private static function insert_post( array $overrides ): int {
		$postarr = array_merge(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'post_title'     => 'Generated Post',
				'post_content'   => 'Generated content',
				'post_excerpt'   => '',
				'post_author'    => \get_current_user_id(),
				'post_date'      => '2025-01-02 03:04:05',
				'post_date_gmt'  => '2025-01-02 03:04:05',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			$overrides
		);

		$id = \wp_insert_post( \wp_slash( $postarr ), true, false );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Could not insert generated post: ' . $id->get_error_message() );
		}

		return (int) $id;
	}

	private static function with_capability_denials( array $denied_caps, callable $callback ) {
		$denied = array_fill_keys( $denied_caps, true );
		$filter = static function ( array $allcaps, array $caps ) use ( $denied ): array {
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = ! isset( $denied[ $cap ] );
			}
			foreach ( $denied as $cap => $_ ) {
				$allcaps[ $cap ] = false;
			}
			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, 1000, 4 );
		try {
			return $callback();
		} finally {
			\remove_filter( 'user_has_cap', $filter, 1000 );
		}
	}

	private static function expected_visibility( string $status, string $password, bool $sticky ): string {
		if ( 'private' === $status ) {
			return 'Private';
		}
		if ( '' !== $password ) {
			return 'Password protected';
		}
		if ( $sticky ) {
			return 'Public, Sticky';
		}
		return 'Public';
	}

	private static function expected_publish_action( array $case ): string {
		if ( ! in_array( $case['status'], array( 'publish', 'future', 'private' ), true ) ) {
			if ( ! $case['canPublish'] ) {
				return 'Submit for Review';
			}
			return $case['futureDate'] ? 'Schedule' : 'Publish';
		}

		return 'Update';
	}

	private static function key( \ComponentFuzz\FuzzContext $ctx, string $prefix, int $max = 24 ): string {
		$key = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '_', $prefix . '_' . $ctx->identifier( 3, 12 ) ) );
		$key = trim( preg_replace( '/_+/', '_', $key ), '_' );
		return substr( $key, 0, $max );
	}

	private static function safe_text( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): string {
		$text = preg_replace( '/[^A-Za-z0-9 _.,:&-]/', '', $ctx->text( $min, $max ) );
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) < $min ) {
			$text .= substr( ' generated value', 0, $min - strlen( $text ) );
		}
		return substr( $text, 0, $max );
	}

	private static function input_is_checked( string $html, string $name, string $value ): bool {
		$quoted_name  = preg_quote( $name, '/' );
		$quoted_value = preg_quote( $value, '/' );
		return 1 === preg_match(
			'/<input\b(?=[^>]*\bname=(["\'])' . $quoted_name . '\1)(?=[^>]*\bvalue=(["\'])' . $quoted_value . '\2)(?=[^>]*\bchecked=(["\'])checked\3)[^>]*>/i',
			$html
		);
	}

	private static function option_is_selected( string $html, string $value ): bool {
		$quoted_value = preg_quote( $value, '/' );
		return 1 === preg_match(
			'/<option\b(?=[^>]*\bvalue=(["\'])' . $quoted_value . '\1)(?=[^>]*\bselected=(["\'])selected\2)[^>]*>/i',
			$html
		);
	}

	private static function flatten_meta_boxes( array $meta_boxes ): array {
		$flat = array();
		foreach ( $meta_boxes as $context => $priorities ) {
			foreach ( (array) $priorities as $priority => $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( ! is_array( $box ) ) {
						continue;
					}
					$flat[ $id ] = array(
						'context'  => (string) $context,
						'priority' => (string) $priority,
						'callback' => is_string( $box['callback'] ?? null ) ? $box['callback'] : self::callback_name( $box['callback'] ?? null ),
						'title'    => (string) ( $box['title'] ?? '' ),
						'args'     => is_array( $box['args'] ?? null ) ? $box['args'] : array(),
					);
				}
			}
		}

		ksort( $flat );
		return $flat;
	}

	private static function callback_name( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) ) {
			$class = is_object( $callback[0] ?? null ) ? get_class( $callback[0] ) : (string) ( $callback[0] ?? '' );
			return $class . '::' . (string) ( $callback[1] ?? '' );
		}
		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}
		return gettype( $callback );
	}

	private static function event_seen( array $events, string $hook, ?string $object_type, ?string $context, ?int $object_id ): bool {
		foreach ( $events as $event ) {
			if ( $hook !== ( $event['hook'] ?? null ) ) {
				continue;
			}
			if ( null !== $object_type && $object_type !== ( $event['object_type'] ?? null ) ) {
				continue;
			}
			if ( null !== $context && $context !== ( $event['context'] ?? null ) ) {
				continue;
			}
			if ( null !== $object_id && $object_id !== ( $event['object_id'] ?? null ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	private static function result_from_failures( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failureCount'] = count( $failures );
		if ( $failures ) {
			$data['failures'] = $failures;
			return $ctx->fail( $invariant, $data );
		}
		return $ctx->pass( $invariant, $data );
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

	private static function capture_output( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	private static function object_summary( object $object ): array {
		$out = array();
		foreach ( get_object_vars( $object ) as $key => $value ) {
			$out[ $key ] = self::describe_value( $value );
		}
		return $out;
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( is_array( $value ) ) {
			if ( $depth >= 4 ) {
				return array( 'type' => 'array', 'count' => count( $value ) );
			}
			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 18 ) {
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
			return array( 'type' => 'object', 'class' => get_class( $value ) );
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
					'action',
					'current_screen',
					'hook_suffix',
					'link',
					'pagenow',
					'post',
					'taxnow',
					'typenow',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_meta_boxes',
					'wp_post_types',
					'wp_rewrite',
					'wp_scripts',
					'wp_styles',
					'wp_taxonomies',
					'wp_theme_features',
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
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		self::restore_globals( $snapshot['globals'] );
	}

	private static function state_matches( array $snapshot ): bool {
		if ( ! self::globals_match( $snapshot['globals'] ) ) {
			return false;
		}
		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return $snapshot['options'] === $GLOBALS['wpdb']->component_fuzz_get_options();
		}
		return true;
	}

	private static function content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}
		return array();
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

	private static function globals_match( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}
		return true;
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
