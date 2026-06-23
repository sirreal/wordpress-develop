<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB WordPress admin dashboard APIs.
 */
final class AdminDashboardSurface {
	public const NAME = 'admin-dashboard';

	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_dashboard_support();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-dashboard.bootstrap-apis-available',
					'Required admin dashboard APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_widget_registration( $ctx->fork( 'widget-registration' ) );
			$rows[] = self::check_widget_control_callbacks( $ctx->fork( 'widget-controls' ) );
			$rows[] = self::check_dashboard_rendering( $ctx->fork( 'dashboard-rendering' ) );
			$rows[] = self::check_recent_drafts_rendering( $ctx->fork( 'recent-drafts' ) );
			$rows[] = self::check_recent_comment_rows( $ctx->fork( 'recent-comment-row' ) );
			$rows[] = self::check_recent_comments_rendering( $ctx->fork( 'recent-comments' ) );
			$rows[] = $ctx->skip(
				'admin-dashboard.unsafe-dispatch-and-remote-paths',
				'wp_dashboard_setup() POST handling redirects/exits, and dashboard RSS/events widgets can reach remote HTTP; '
					. 'lower-level safe dashboard helpers are covered directly.'
			);
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'admin-dashboard.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'admin-dashboard.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals'    => array_keys( $snapshot['globals'] ),
				'contentCounts'     => self::content_counts(),
				'outputBufferLevel' => ob_get_level(),
			)
		);

		return $rows;
	}

	private static function load_dashboard_support(): void {
		$dashboard_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/dashboard.php' : '';
		if ( ! function_exists( 'wp_add_dashboard_widget' ) && $dashboard_file && file_exists( $dashboard_file ) ) {
			require_once $dashboard_file;
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Comment', 'WP_Post', 'WP_Query', 'WP_Screen', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_dashboard_control_callback',
				'_wp_dashboard_recent_comments_row',
				'add_filter',
				'add_meta_box',
				'admin_url',
				'convert_to_screen',
				'current_user_can',
				'do_meta_boxes',
				'esc_attr',
				'esc_html',
				'esc_url',
				'get_current_screen',
				'get_edit_post_link',
				'has_filter',
				'post_type_exists',
				'remove_filter',
				'set_current_screen',
				'submit_button',
				'wp_add_dashboard_widget',
				'wp_cache_flush',
				'wp_create_nonce',
				'wp_dashboard',
				'wp_dashboard_recent_comments',
				'wp_dashboard_recent_drafts',
				'wp_dashboard_trigger_widget_control',
				'wp_insert_user',
				'wp_nonce_field',
				'wp_set_current_user',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_widget_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_runtime();
		$screen = self::dashboard_screen();

		$normal_id       = self::id( $ctx->fork( 'normal' ), 'cfz_dashboard_normal' );
		$edit_id         = self::id( $ctx->fork( 'edit' ), 'cfz_dashboard_edit' );
		$denied_id       = self::id( $ctx->fork( 'denied' ), 'cfz_dashboard_denied' );
		$widget_name     = 'Widget ' . self::hostile_text( $ctx->fork( 'widget-name' ) );
		$edit_name       = 'Editable ' . self::hostile_text( $ctx->fork( 'edit-name' ) );
		$denied_name     = 'Denied ' . self::hostile_text( $ctx->fork( 'denied-name' ) );
		$callback        = static function (): void {
			echo '<span class="cfz-dashboard-widget">display</span>';
		};
		$control         = static function (): void {
			echo '<span class="cfz-dashboard-control">control</span>';
		};
		$normal_payload  = array(
			'payload' => self::hostile_text( $ctx->fork( 'payload' ) ),
			'nested'  => array( 'token' => self::slug( $ctx->fork( 'nested' ), 'nested' ) ),
		);
		$controls_before = array();
		$normal_box      = null;
		$quick_box       = null;
		$primary_box     = null;
		$browser_box     = null;
		$php_box         = null;
		$edit_box        = null;
		$denied_box      = null;

		self::with_capabilities(
			array( 'edit_dashboard' ),
			static function () use (
				$callback,
				$control,
				$normal_id,
				$normal_payload,
				$screen,
				$widget_name,
				&$browser_box,
				&$controls_before,
				&$normal_box,
				&$php_box,
				&$primary_box,
				&$quick_box
			): void {
				\wp_add_dashboard_widget( $normal_id, $widget_name, $callback, $control, $normal_payload, '', '' );
				\wp_add_dashboard_widget( 'dashboard_quick_press', 'Quick Side', $callback, null, null, 'normal', 'low' );
				\wp_add_dashboard_widget( 'dashboard_primary', 'Primary Side', $callback, null, null, 'normal', 'low' );
				\wp_add_dashboard_widget( 'dashboard_browser_nag', 'Browser Nag', $callback, null, null, 'normal', 'core' );
				\wp_add_dashboard_widget( 'dashboard_php_nag', 'PHP Nag', $callback, null, null, 'side', 'core' );

				$controls_before = $GLOBALS['wp_dashboard_control_callbacks'] ?? array();
				$normal_box      = self::find_meta_box( $screen->id, $normal_id );
				$quick_box       = self::find_meta_box( $screen->id, 'dashboard_quick_press' );
				$primary_box     = self::find_meta_box( $screen->id, 'dashboard_primary' );
				$browser_box     = self::find_meta_box( $screen->id, 'dashboard_browser_nag' );
				$php_box         = self::find_meta_box( $screen->id, 'dashboard_php_nag' );
			}
		);

		self::collect_failure(
			$failures,
			is_array( $normal_box )
				&& 'normal' === $normal_box['context']
				&& 'core' === $normal_box['priority']
				&& $callback === $normal_box['box']['callback']
				&& $normal_payload['payload'] === ( $normal_box['box']['args']['payload'] ?? null )
				&& $normal_payload['nested'] === ( $normal_box['box']['args']['nested'] ?? null )
				&& $widget_name === ( $normal_box['box']['args']['__widget_basename'] ?? null )
				&& isset( $controls_before[ $normal_id ] )
				&& $control === $controls_before[ $normal_id ]
				&& str_contains( (string) ( $normal_box['box']['title'] ?? '' ), 'class="edit-box open-box"' )
				&& str_contains( (string) ( $normal_box['box']['title'] ?? '' ), 'edit=' . rawurlencode( $normal_id ) )
				&& str_contains( (string) ( $normal_box['box']['title'] ?? '' ), '#' . $normal_id ),
			'wp_add_dashboard_widget() normalizes empty context/priority, stores private basename args, and adds Configure links for editable controls',
			array(
				'box'      => $normal_box,
				'controls' => array_keys( $controls_before ),
			)
		);

		self::collect_failure(
			$failures,
			is_array( $quick_box )
				&& 'side' === $quick_box['context']
				&& 'low' === $quick_box['priority']
				&& is_array( $primary_box )
				&& 'side' === $primary_box['context']
				&& 'low' === $primary_box['priority']
				&& is_array( $browser_box )
				&& 'normal' === $browser_box['context']
				&& 'high' === $browser_box['priority']
				&& is_array( $php_box )
				&& 'side' === $php_box['context']
				&& 'high' === $php_box['priority'],
			'special dashboard widget IDs force side contexts and high priorities while preserving unrelated values',
			array(
				'quick'   => $quick_box,
				'primary' => $primary_box,
				'browser' => $browser_box,
				'php'     => $php_box,
			)
		);

		self::reset_dashboard_boxes();
		$_GET['edit']             = $edit_id;
		$_REQUEST['edit']         = $edit_id;
		$_SERVER['REQUEST_URI']   = '/wp-admin/index.php?edit=' . rawurlencode( $edit_id ) . '&existing=1#old-fragment';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		self::with_capabilities(
			array( 'edit_dashboard' ),
			static function () use ( $callback, $control, $edit_id, $edit_name, $screen, &$edit_box ): void {
				\wp_add_dashboard_widget( $edit_id, $edit_name, $callback, $control, null, 'column3', 'default' );
				$edit_box = self::find_meta_box( $screen->id, $edit_id );
			}
		);

		self::collect_failure(
			$failures,
			is_array( $edit_box )
				&& 'column3' === $edit_box['context']
				&& '_wp_dashboard_control_callback' === $edit_box['box']['callback']
				&& array( '__widget_basename' => $edit_name ) === $edit_box['box']['args']
				&& str_contains( (string) $edit_box['box']['title'], 'Cancel' )
				&& ! str_contains( (string) $edit_box['box']['title'], 'Configure' )
				&& isset( $GLOBALS['wp_dashboard_control_callbacks'][ $edit_id ] ),
			'wp_add_dashboard_widget() swaps to the private control callback and Cancel link for the widget being edited',
			array( 'box' => $edit_box )
		);

		self::reset_dashboard_boxes();
		unset( $_GET['edit'], $_REQUEST['edit'] );

		\wp_add_dashboard_widget( $denied_id, $denied_name, $callback, $control, null, 'side', 'low' );
		$denied_box = self::find_meta_box( $screen->id, $denied_id );

		self::collect_failure(
			$failures,
			is_array( $denied_box )
				&& $callback === $denied_box['box']['callback']
				&& ! isset( $GLOBALS['wp_dashboard_control_callbacks'][ $denied_id ] )
				&& ! str_contains( (string) $denied_box['box']['title'], 'Configure' )
				&& ! str_contains( (string) $denied_box['box']['title'], 'Cancel' ),
			'control callbacks are not registered and edit links are omitted when the user cannot edit the dashboard',
			array( 'box' => $denied_box )
		);

		return self::result(
			$ctx,
			'admin-dashboard.widgets.registration-controls-context-priority',
			$failures,
			array( 'screen' => self::describe_screen( $screen ) )
		);
	}

	private static function check_widget_control_callbacks( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_runtime();
		$screen  = self::dashboard_screen();
		$box_id  = self::id( $ctx->fork( 'control' ), 'cfz_dashboard_control' );
		$payload = 'control-payload-' . self::slug( $ctx->fork( 'payload' ), 'payload' );
		$calls   = array();

		$control = static function ( $dashboard, array $meta_box ) use ( &$calls, $payload ): void {
			$calls[] = array(
				'dashboard' => $dashboard,
				'id'        => $meta_box['id'] ?? null,
				'callback'  => is_callable( $meta_box['callback'] ?? null ),
			);
			echo '<span class="cfz-control-output" data-payload="' . \esc_attr( $payload ) . '">';
			echo \esc_html( $meta_box['id'] ?? '' );
			echo '</span>';
		};

		self::with_capabilities(
			array( 'edit_dashboard' ),
			static function () use ( $box_id, $control ): void {
				\wp_add_dashboard_widget(
					$box_id,
					'Control Widget',
					static function (): void {
						echo '<span>display</span>';
					},
					$control,
					null,
					'normal',
					'core'
				);
			}
		);

		$box = self::find_meta_box( $screen->id, $box_id );

		ob_start();
		\wp_dashboard_trigger_widget_control( $box_id );
		$trigger_html = (string) ob_get_clean();
		$trigger_calls = $calls;

		ob_start();
		\wp_dashboard_trigger_widget_control( 'missing-' . $box_id );
		$missing_html = (string) ob_get_clean();
		$after_missing_calls = $calls;

		ob_start();
		\_wp_dashboard_control_callback( '', $box['box'] ?? array( 'id' => $box_id ) );
		$form_html = (string) ob_get_clean();

		self::collect_failure(
			$failures,
			1 === count( $trigger_calls )
				&& $box_id === ( $trigger_calls[0]['id'] ?? null )
				&& '' === ( $trigger_calls[0]['dashboard'] ?? null )
				&& true === ( $trigger_calls[0]['callback'] ?? null )
				&& str_contains( $trigger_html, 'cfz-control-output' )
				&& str_contains( $trigger_html, \esc_attr( $payload ) )
				&& '' === $missing_html
				&& $after_missing_calls === $trigger_calls,
			'wp_dashboard_trigger_widget_control() invokes only registered callable controls and ignores missing IDs',
			array(
				'calls'        => $calls,
				'triggerHtml'  => self::preview_string( $trigger_html ),
				'missingHtml'  => self::preview_string( $missing_html ),
			)
		);

		self::collect_failure(
			$failures,
			2 === count( $calls )
				&& str_contains( $form_html, '<form method="post" class="dashboard-widget-control-form wp-clearfix">' )
				&& str_contains( $form_html, 'name="dashboard-widget-nonce"' )
				&& str_contains( $form_html, 'name="widget_id" value="' . \esc_attr( $box_id ) . '"' )
				&& str_contains( $form_html, 'Save Changes' )
				&& str_contains( $form_html, 'cfz-control-output' )
				&& 'GET' === $_SERVER['REQUEST_METHOD'],
			'_wp_dashboard_control_callback() wraps the control in a nonce form without dispatching POST handlers',
			array( 'formHtml' => self::preview_string( $form_html ) )
		);

		return self::result(
			$ctx,
			'admin-dashboard.widgets.control-callback-output',
			$failures,
			array( 'box' => $box )
		);
	}

	private static function check_dashboard_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$seen     = array();

		foreach ( self::column_cases( $ctx ) as $columns ) {
			self::reset_runtime();
			$screen = self::dashboard_screen();
			self::set_screen_columns( $screen, $columns );

			$calls    = array();
			$contexts = array( 'normal', 'side', 'column3', 'column4' );
			foreach ( $contexts as $context ) {
				$box_id  = self::id( $ctx->fork( 'box-' . $columns . '-' . $context ), 'cfz_dash_' . $context );
				$payload = 'payload-' . $columns . '-' . self::slug( $ctx->fork( 'payload-' . $context ), 'box' );

				\wp_add_dashboard_widget(
					$box_id,
					'Dashboard ' . $context,
					static function ( $dashboard, array $box ) use ( &$calls, $context, $payload ): void {
						$calls[] = array(
							'id'                 => $box['id'] ?? null,
							'context'            => $context,
							'payload'            => $box['args']['payload'] ?? null,
							'hasPrivateBasename' => isset( $box['args']['__widget_basename'] ),
							'dashboard'          => $dashboard,
						);
						echo '<span class="cfz-dashboard-rendered" data-context="' . \esc_attr( $context ) . '">';
						echo \esc_html( (string) ( $box['args']['payload'] ?? '' ) );
						echo '</span>';
					},
					null,
					array(
						'context' => $context,
						'payload' => $payload,
					),
					$context,
					'default'
				);
			}

			ob_start();
			\wp_dashboard();
			$html = (string) ob_get_clean();

			$seen[] = array(
				'columns'  => $columns,
				'calls'    => $calls,
				'htmlHash' => sha1( $html ),
			);

			$expected_contexts = $contexts;
			$actual_contexts   = array_column( $calls, 'context' );

			self::collect_failure(
				$failures,
				$expected_contexts === $actual_contexts
					&& self::all_call_values( $calls, 'hasPrivateBasename', false )
					&& self::all_call_values( $calls, 'dashboard', '' )
					&& str_contains( $html, 'id="dashboard-widgets"' )
					&& ( 0 === $columns ? ! str_contains( $html, ' columns-' ) : str_contains( $html, ' columns-' . $columns ) )
					&& str_contains( $html, 'id="postbox-container-1"' )
					&& str_contains( $html, 'id="postbox-container-2"' )
					&& str_contains( $html, 'id="postbox-container-3"' )
					&& str_contains( $html, 'id="postbox-container-4"' )
					&& str_contains( $html, 'id="normal-sortables"' )
					&& str_contains( $html, 'id="side-sortables"' )
					&& str_contains( $html, 'id="column3-sortables"' )
					&& str_contains( $html, 'id="column4-sortables"' )
					&& str_contains( $html, 'name="closedpostboxesnonce"' )
					&& str_contains( $html, 'name="meta-box-order-nonce"' )
					&& 4 === substr_count( $html, 'cfz-dashboard-rendered' ),
				'wp_dashboard() renders all dashboard containers, nonces, contexts, and callbacks for the synthetic current screen',
				array(
					'columns' => $columns,
					'calls'   => $calls,
					'html'    => self::preview_string( $html ),
				)
			);
		}

		return self::result(
			$ctx,
			'admin-dashboard.rendering.columns-contexts-nonces',
			$failures,
			array( 'seen' => $seen )
		);
	}

	private static function check_recent_drafts_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_runtime();
		$user_id = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );

		$post_snapshot = self::snapshot_globals( array( 'post', 'id' ) );
		$drafts        = array();
		$raw_titles    = array();
		$first_ids     = array();

		for ( $i = 0; $i < 5; ++$i ) {
			$title        = 'Draft <script>alert(' . $i . ')</script> ' . self::hostile_text( $ctx->fork( 'title-' . $i ) );
			$content      = 'Intro ' . $i . ' <script>alert(1)</script> <img src=x onerror=alert(1)> ' . self::hostile_text( $ctx->fork( 'content-' . $i ) );
			$raw_titles[] = $title;
			$post_id      = self::insert_post(
				array(
					'post_author'       => $user_id,
					'post_date'         => sprintf( '2026-06-%02d 10:00:00', 20 - $i ),
					'post_date_gmt'     => sprintf( '2026-06-%02d 10:00:00', 20 - $i ),
					'post_modified'     => sprintf( '2026-06-%02d 11:00:00', 20 - $i ),
					'post_modified_gmt' => sprintf( '2026-06-%02d 11:00:00', 20 - $i ),
					'post_content'      => $content,
					'post_title'        => $title,
					'post_status'       => 'draft',
					'post_type'         => 'post',
					'post_name'         => 'draft-' . $i . '-' . self::slug( $ctx->fork( 'slug-' . $i ), 'draft' ),
				)
			);
			$drafts[]     = \get_post( $post_id );
			if ( $i < 3 ) {
				$first_ids[] = $post_id;
			}
		}

		$result = self::with_capabilities(
			array( 'edit_post', 'edit_posts', 'read_post', 'read' ),
			static function () use ( $drafts ): array {
				ob_start();
				\wp_dashboard_recent_drafts( $drafts );
				return array(
					'html' => (string) ob_get_clean(),
				);
			}
		);

		$html = $result['html'];

		self::collect_failure(
			$failures,
			str_contains( $html, '<div class="drafts">' )
				&& str_contains( $html, 'View all drafts' )
				&& str_contains( $html, 'edit.php?post_status=draft' )
				&& 3 === substr_count( $html, '<div class="draft-title">' )
				&& 3 === substr_count( $html, '<li>' )
				&& self::strings_in_order( $html, array_map( 'strval', $first_ids ) )
				&& ! str_contains( strtolower( $html ), '<script' )
				&& ! str_contains( strtolower( $html ), '<img' )
				&& ! str_contains( strtolower( $html ), 'onerror=' ),
			'wp_dashboard_recent_drafts() limits visible drafts, emits expected edit/view links, and escapes hostile titles/content',
			array(
				'ids'       => $first_ids,
				'rawTitles' => $raw_titles,
				'html'      => self::preview_string( $html ),
			)
		);

		self::collect_failure(
			$failures,
			self::globals_match( $post_snapshot ),
			'wp_dashboard_recent_drafts() does not leak post globals when passed explicit drafts',
			array( 'globals' => self::changed_globals( $post_snapshot ) )
		);

		return self::result(
			$ctx,
			'admin-dashboard.helpers.recent-drafts-output',
			$failures,
			array( 'htmlHash' => sha1( $html ) )
		);
	}

	private static function check_recent_comment_rows( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_runtime();
		$user_id = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );

		$post_id = self::insert_post(
			array(
				'post_author'   => $user_id,
				'post_date'     => '2026-06-20 09:00:00',
				'post_date_gmt' => '2026-06-20 09:00:00',
				'post_title'    => 'Commented <script>alert(1)</script> Post ' . self::hostile_text( $ctx->fork( 'post-title' ) ),
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_name'     => 'commented-' . self::slug( $ctx->fork( 'post-slug' ), 'post' ),
			)
		);
		$comment_id = self::insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Author & "quoted" ' . self::slug( $ctx->fork( 'author' ), 'author' ),
				'comment_author_email' => 'author@example.test',
				'comment_author_url'   => 'javascript:alert(1)',
				'comment_date'         => '2026-06-20 12:00:00',
				'comment_date_gmt'     => '2026-06-20 12:00:00',
				'comment_content'      => 'Comment <script>alert(1)</script> <strong>body</strong> ' . self::hostile_text( $ctx->fork( 'content' ) ),
				'comment_approved'     => '0',
				'comment_type'         => 'comment',
				'user_id'              => $user_id,
			)
		);

		$comment_snapshot = self::snapshot_globals( array( 'comment' ) );
		$comment          = \get_comment( $comment_id );

		$result = self::with_capabilities(
			array( 'edit_comment', 'edit_others_posts', 'edit_post', 'edit_posts', 'edit_published_posts', 'moderate_comments', 'read_post', 'read' ),
			static function () use ( $comment ): array {
				ob_start();
				\_wp_dashboard_recent_comments_row( $comment, true );
				return array(
					'html'        => (string) ob_get_clean(),
					'globalAfter' => array(
						'exists' => array_key_exists( 'comment', $GLOBALS ),
						'value'  => $GLOBALS['comment'] ?? null,
					),
				);
			}
		);

		self::restore_globals( $comment_snapshot );
		$html  = $result['html'];
		$lower = strtolower( $html );

		self::collect_failure(
			$failures,
			str_contains( $html, 'id="comment-' . $comment_id . '"' )
				&& str_contains( $html, 'comment.php?action=approvecomment' )
				&& str_contains( $html, 'comment.php?action=unapprovecomment' )
				&& str_contains( $html, 'comment.php?action=editcomment&amp;c=' . $comment_id )
				&& str_contains( $html, 'comment.php?action=spamcomment' )
				&& str_contains( $html, 'comment.php?action=trashcomment' )
				&& str_contains( $html, 'class="comment-link"' )
				&& str_contains( $html, 'data-wp-lists=' )
				&& str_contains( $html, 'commentReply.open' )
				&& ! str_contains( $lower, '<script' )
				&& ! str_contains( $lower, 'javascript:' )
				&& ! str_contains( $lower, 'onerror=' ),
			'_wp_dashboard_recent_comments_row() renders bounded moderation/view actions and escapes hostile comment/post fields',
			array(
				'commentId' => $comment_id,
				'postId'    => $post_id,
				'html'      => self::preview_string( $html ),
			)
		);

		self::collect_failure(
			$failures,
			( $result['globalAfter']['exists'] ?? false )
				&& null === ( $result['globalAfter']['value'] ?? null )
				&& self::globals_match( $comment_snapshot ),
			'_wp_dashboard_recent_comments_row() clears its working global and the surface restores the previous comment global',
			array(
				'globalAfter' => $result['globalAfter'],
				'changed'     => self::changed_globals( $comment_snapshot ),
			)
		);

		return self::result(
			$ctx,
			'admin-dashboard.helpers.recent-comment-row-actions',
			$failures,
			array( 'htmlHash' => sha1( $html ) )
		);
	}

	private static function check_recent_comments_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_runtime();
		$user_id = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );

		$post_id  = self::insert_post(
			array(
				'post_author'   => $user_id,
				'post_date'     => '2026-06-18 09:00:00',
				'post_date_gmt' => '2026-06-18 09:00:00',
				'post_title'    => 'Recent Comment Host ' . self::hostile_text( $ctx->fork( 'post-title' ) ),
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_name'     => 'recent-comments-' . self::slug( $ctx->fork( 'post-slug' ), 'post' ),
			)
		);
		$comments = array();
		for ( $i = 0; $i < 5; ++$i ) {
			$comments[] = new \WP_Comment(
				(object) array(
					'comment_ID'           => 5000 + $i,
					'comment_post_ID'      => $post_id,
					'comment_author'       => 'Recent Author ' . $i . ' & quoted',
					'comment_author_email' => 'recent' . $i . '@example.test',
					'comment_author_url'   => '',
					'comment_date'         => '2026-06-18 12:0' . $i . ':00',
					'comment_date_gmt'     => '2026-06-18 12:0' . $i . ':00',
					'comment_content'      => 'Recent comment ' . $i . ' <script>alert(1)</script>',
					'comment_approved'     => '1',
					'comment_type'         => 'comment',
					'comment_parent'       => 0,
					'user_id'              => $user_id,
				)
			);
		}

		$query_events = array();
		$query_filter = static function ( $comment_data, \WP_Comment_Query $query ) use ( &$query_events, $comments ) {
			$query_events[] = array(
				'number' => $query->query_vars['number'] ?? null,
				'offset' => $query->query_vars['offset'] ?? null,
				'status' => $query->query_vars['status'] ?? null,
			);

			return $comments;
		};
		$reply_filter = static function (): string {
			return '<div id="com-reply" class="cfz-reply-short-circuit"></div>';
		};

		\add_filter( 'comments_pre_query', $query_filter, 10, 2 );
		\add_filter( 'wp_comment_reply', $reply_filter, 10, 0 );
		try {
			$result = self::with_capabilities(
				array( 'read_post', 'read' ),
				static function (): array {
					ob_start();
					$return = \wp_dashboard_recent_comments( 2 );
					return array(
						'return' => $return,
						'html'   => (string) ob_get_clean(),
					);
				}
			);
		} finally {
			\remove_filter( 'wp_comment_reply', $reply_filter, 10 );
			\remove_filter( 'comments_pre_query', $query_filter, 10 );
		}

		$html = $result['html'];

		self::collect_failure(
			$failures,
			true === $result['return']
				&& 1 === count( $query_events )
				&& 10 === (int) ( $query_events[0]['number'] ?? 0 )
				&& 0 === (int) ( $query_events[0]['offset'] ?? -1 )
				&& 'approve' === ( $query_events[0]['status'] ?? null )
				&& str_contains( $html, 'id="latest-comments"' )
				&& str_contains( $html, 'id="the-comment-list"' )
				&& 2 === substr_count( $html, '<li id="comment-' )
				&& str_contains( $html, 'cfz-reply-short-circuit' )
				&& str_contains( $html, 'trash-undo-holder' )
				&& ! str_contains( strtolower( $html ), '<script' ),
			'wp_dashboard_recent_comments() honors total item limits, approved-only query shape for non-editors, and safe wrapper output',
			array(
				'queryEvents' => $query_events,
				'html'        => self::preview_string( $html ),
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'comments_pre_query', $query_filter )
				&& false === \has_filter( 'wp_comment_reply', $reply_filter ),
			'recent comment filters are removed after the check'
		);

		return self::result(
			$ctx,
			'admin-dashboard.helpers.recent-comments-count-query',
			$failures,
			array( 'htmlHash' => sha1( $html ) )
		);
	}

	private static function reset_runtime(): void {
		if ( function_exists( 'create_initial_post_types' ) ) {
			\create_initial_post_types();
		}
		if ( function_exists( 'create_initial_taxonomies' ) ) {
			\create_initial_taxonomies();
		}

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'blog_charset'          => 'UTF-8',
					'blogdescription'       => 'Component Fuzz Site',
					'blogname'              => 'Component Fuzz',
					'comments_per_page'     => 50,
					'date_format'           => 'F j, Y',
					'default_comments_page' => 'oldest',
					'gmt_offset'            => 0,
					'home'                  => 'https://example.test',
					'page_comments'         => 0,
					'permalink_structure'   => '',
					'show_avatars'          => 0,
					'siteurl'               => 'https://example.test',
					'time_format'           => 'g:i a',
					'timezone_string'       => 'UTC',
				)
			);
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		$_COOKIE  = array();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['HTTP_HOST']      = 'example.test';
		$_SERVER['PHP_SELF']       = '/wp-admin/index.php';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/wp-admin/index.php';

		$GLOBALS['hook_suffix']                    = 'index.php';
		$GLOBALS['pagenow']                        = 'index.php';
		$GLOBALS['wp_dashboard_control_callbacks'] = array();
		$GLOBALS['wp_meta_boxes']                  = array();
		$GLOBALS['wp_query']                       = new \WP_Query();
		$GLOBALS['wp_the_query']                   = $GLOBALS['wp_query'];
		if ( class_exists( 'WP_Rewrite' ) ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}
		$GLOBALS['post']                           = null;
		$GLOBALS['comment']                        = null;

		\wp_set_current_user( 0 );
		self::dashboard_screen();
	}

	private static function reset_dashboard_boxes(): void {
		$GLOBALS['wp_dashboard_control_callbacks'] = array();
		$GLOBALS['wp_meta_boxes']                  = array();
	}

	private static function dashboard_screen(): \WP_Screen {
		\set_current_screen( 'index.php' );
		$screen = \get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			throw new \RuntimeException( 'Could not initialize dashboard screen.' );
		}

		return $screen;
	}

	private static function set_screen_columns( \WP_Screen $screen, int $columns ): void {
		$property = new \ReflectionProperty( \WP_Screen::class, 'columns' );
		$property->setValue( $screen, $columns );
		$GLOBALS['screen_layout_columns'] = $columns;
	}

	private static function column_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$columns = array( 0, 1, 2, 3, 4, $ctx->int( 0, 4 ) );
		$columns = array_values( array_unique( array_map( 'intval', $columns ) ) );
		sort( $columns );

		return $columns;
	}

	private static function seed_user( \ComponentFuzz\FuzzContext $ctx ): int {
		$login = 'cfz_dash_' . self::slug( $ctx, 'user' );
		$id    = \wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => 'component-fuzz-pass',
				'user_email'   => $login . '@example.test',
				'user_nicename' => $login,
				'display_name' => 'Component Fuzz ' . $login,
			)
		);

		if ( \is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Could not seed user: ' . $id->get_error_message() );
		}

		return (int) $id;
	}

	private static function insert_post( array $data ): int {
		global $wpdb;

		$result = $wpdb->insert( $wpdb->posts, $data );
		if ( ! $result ) {
			throw new \RuntimeException( 'Could not seed post.' );
		}

		return (int) $wpdb->insert_id;
	}

	private static function insert_comment( array $data ): int {
		global $wpdb;

		$result = $wpdb->insert( $wpdb->comments, $data );
		if ( ! $result ) {
			throw new \RuntimeException( 'Could not seed comment.' );
		}

		return (int) $wpdb->insert_id;
	}

	private static function with_capabilities( array $capabilities, callable $callback ) {
		$cap_filter = static function ( array $allcaps ) use ( $capabilities ): array {
			foreach ( $capabilities as $capability ) {
				$allcaps[ $capability ] = true;
			}

			return $allcaps;
		};

		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );
		try {
			return $callback();
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );
		}
	}

	private static function find_meta_box( string $page, string $id ): ?array {
		foreach ( (array) ( $GLOBALS['wp_meta_boxes'][ $page ] ?? array() ) as $context => $priorities ) {
			foreach ( (array) $priorities as $priority => $boxes ) {
				if ( isset( $boxes[ $id ] ) && is_array( $boxes[ $id ] ) ) {
					return array(
						'context'  => $context,
						'priority' => $priority,
						'box'      => $boxes[ $id ],
					);
				}
			}
		}

		return null;
	}

	private static function id( \ComponentFuzz\FuzzContext $ctx, string $prefix, int $max = 48 ): string {
		return strtolower( substr( $prefix . '_' . hash( 'crc32b', (string) $ctx->seed() ), 0, $max ) );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $fallback ): string {
		$value = preg_replace( '/[^A-Za-z0-9_-]+/', '-', strtolower( $ctx->identifier( 3, 12 ) ) );
		$value = trim( (string) $value, '-' );

		return '' === $value ? $fallback : $value;
	}

	private static function hostile_text( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->text( 0, 24 ) . ' <b data-x="' . \esc_attr( $ctx->identifier( 3, 8 ) ) . '">bold</b> & "quoted"';
	}

	private static function strings_in_order( string $haystack, array $needles ): bool {
		$offset = 0;

		foreach ( $needles as $needle ) {
			$position = strpos( $haystack, (string) $needle, $offset );
			if ( false === $position ) {
				return false;
			}
			$offset = $position + strlen( (string) $needle );
		}

		return true;
	}

	private static function all_call_values( array $calls, string $key, $expected ): bool {
		foreach ( $calls as $call ) {
			if ( ! array_key_exists( $key, $call ) || $expected !== $call[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_state(): array {
		$options = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return array(
			'globals'           => self::snapshot_globals(
				array(
					'_COOKIE',
					'_GET',
					'_POST',
					'_REQUEST',
					'_SERVER',
					'comment',
					'current_screen',
					'current_user',
					'hook_suffix',
					'id',
					'pagenow',
					'post',
					'posts',
					'screen_layout_columns',
					'user_ID',
					'userdata',
					'wp_actions',
					'wp_current_filter',
					'wp_dashboard_control_callbacks',
					'wp_filter',
					'wp_filters',
					'wp_meta_boxes',
					'wp_query',
					'wp_rewrite',
					'wp_the_query',
				)
			),
			'options'           => $options,
			'contentCounts'     => self::content_counts(),
			'outputBufferLevel' => ob_get_level(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		while ( ob_get_level() > $snapshot['outputBufferLevel'] ) {
			ob_end_clean();
		}

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		self::restore_globals( $snapshot['globals'] );

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		$options = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return $snapshot['options'] === $options
			&& $snapshot['contentCounts'] === self::content_counts()
			&& $snapshot['outputBufferLevel'] === ob_get_level()
			&& self::globals_match( $snapshot['globals'] );
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

	private static function changed_globals( array $snapshot ): array {
		$changed = array();
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] || ( $exists && $GLOBALS[ $name ] != $entry['value'] ) ) {
				$changed[] = $name;
			}
		}

		return $changed;
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
			try {
				return clone $value;
			} catch ( \Throwable $e ) {
				return $value;
			}
		}

		return $value;
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

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => self::preview( $data ),
		);
	}

	private static function preview( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > self::PREVIEW_BYTES ? substr( $value, 0, self::PREVIEW_BYTES ) . '...' : $value;
		}
		if ( is_array( $value ) ) {
			$json = \wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			return false === $json ? '[array]' : self::preview( $json );
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		return $value;
	}

	private static function preview_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => self::escape_bytes( $value ),
		);
	}

	private static function describe_screen( $screen ): array {
		if ( ! $screen instanceof \WP_Screen ) {
			return array( 'type' => is_object( $screen ) ? get_class( $screen ) : gettype( $screen ) );
		}

		return array(
			'id'       => $screen->id,
			'base'     => $screen->base,
			'inAdmin'  => $screen->in_admin(),
			'columns'  => $screen->get_columns(),
			'postType' => $screen->post_type,
			'taxonomy' => $screen->taxonomy,
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
}
