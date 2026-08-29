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
			$rows[] = self::check_right_now_widget_rendering( $ctx->fork( 'right-now' ) );
			$rows[] = self::check_recent_drafts_rendering( $ctx->fork( 'recent-drafts' ) );
			$rows[] = self::check_recent_posts_rendering( $ctx->fork( 'recent-posts' ) );
			$rows[] = self::check_recent_comment_rows( $ctx->fork( 'recent-comment-row' ) );
			$rows[] = self::check_recent_comments_rendering( $ctx->fork( 'recent-comments' ) );
			$rows[] = self::check_cached_rss_widget( $ctx->fork( 'cached-rss' ) );
			$rows[] = self::check_browser_nag_remote_cache( $ctx->fork( 'browser-nag' ) );
			$rows[] = self::check_community_events_markup_and_templates( $ctx->fork( 'community-events-markup' ) );
			$rows[] = self::check_dashboard_setup_direct_registration( $ctx->fork( 'setup' ) );
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

		$misc_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/misc.php' : '';
		if ( ! function_exists( 'wp_check_php_version' ) && $misc_file && file_exists( $misc_file ) ) {
			require_once $misc_file;
		}

		$update_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/update.php' : '';
		if ( ! function_exists( 'update_right_now_message' ) && $update_file && file_exists( $update_file ) ) {
			require_once $update_file;
		}
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Comment', 'WP_Error', 'WP_Post', 'WP_Query', 'WP_Screen', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_wp_dashboard_control_callback',
				'_wp_dashboard_recent_comments_row',
				'add_action',
				'add_filter',
				'add_query_arg',
				'add_meta_box',
				'admin_url',
				'convert_to_screen',
				'current_user_can',
				'delete_site_transient',
				'delete_transient',
				'do_meta_boxes',
				'esc_attr',
				'esc_html',
				'esc_url',
				'get_current_screen',
				'get_edit_post_link',
				'get_bloginfo',
				'get_option',
				'get_post_type_object',
				'get_transient',
				'get_user_locale',
				'has_filter',
				'is_network_admin',
				'is_user_admin',
				'number_format_i18n',
				'post_type_exists',
				'remove_action',
				'remove_filter',
				'set_current_screen',
				'set_site_transient',
				'set_transient',
				'submit_button',
				'update_right_now_message',
				'wp_add_dashboard_widget',
				'wp_admin_notice',
				'wp_cache_flush',
				'wp_count_comments',
				'wp_count_posts',
				'wp_create_nonce',
				'wp_dashboard',
				'wp_dashboard_cached_rss_widget',
				'wp_dashboard_right_now',
				'wp_dashboard_recent_comments',
				'wp_dashboard_recent_drafts',
				'wp_dashboard_recent_posts',
				'wp_dashboard_setup',
				'wp_dashboard_trigger_widget_control',
				'wp_check_php_version',
				'wp_doing_ajax',
				'wp_get_admin_notice',
				'wp_insert_user',
				'wp_get_theme',
				'wp_nonce_field',
				'wp_print_community_events_markup',
				'wp_print_community_events_templates',
				'wp_set_current_user',
				'wp_strip_all_tags',
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

	private static function check_right_now_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$case_summaries = array();
		$cases         = array(
			'singular' => array(
				'posts'     => 1,
				'pages'     => 1,
				'approved'  => 1,
				'moderated' => 1,
			),
			'plural'   => array(
				'posts'     => 2 + $ctx->int( 0, 2 ),
				'pages'     => 3 + $ctx->int( 0, 2 ),
				'approved'  => 3 + $ctx->int( 0, 3 ),
				'moderated' => 0,
			),
		);

		foreach ( $cases as $label => $case ) {
			self::reset_runtime();
			$options = self::options_snapshot();
			if ( is_array( $options ) && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
				$options['blog_public'] = array(
					'autoload'     => 'auto',
					'option_value' => '0',
				);
				$GLOBALS['wpdb']->component_fuzz_reset_options( $options );
			}

			$user_id = self::seed_user( $ctx->fork( 'right-now-user-' . $label ) );
			\wp_set_current_user( $user_id );
			self::seed_right_now_posts( $ctx->fork( 'right-now-posts-' . $label ), 'post', (int) $case['posts'], 'Right Now Post ' . $label );
			self::seed_right_now_posts( $ctx->fork( 'right-now-pages-' . $label ), 'page', (int) $case['pages'], 'Right Now Page ' . $label );
			self::insert_post(
				array(
					'post_author' => $user_id,
					'post_status' => 'draft',
					'post_type'   => 'post',
					'post_title'  => 'Draft should not appear ' . $label,
				)
			);
			if ( function_exists( 'wp_cache_flush' ) ) {
				\wp_cache_flush();
			}

			$glance_calls = 0;
			$comment_calls = array();
			$privacy_title_calls = 0;
			$privacy_text_calls = 0;
			$version_text_calls = 0;
			$rightnow_calls = 0;
			$activity_calls = 0;
			$glance_marker = 'cfz-glance-' . self::slug( $ctx->fork( 'glance-' . $label ), 'glance' );
			$privacy_title = 'Search indexing disabled ' . self::slug( $ctx->fork( 'privacy-title-' . $label ), 'privacy' );
			$privacy_text  = 'Indexing off ' . self::slug( $ctx->fork( 'privacy-text-' . $label ), 'privacy' );
			$version_text  = 'CFZ version %1$s using %2$s.';

			$glance_filter = static function ( array $items ) use ( &$glance_calls, $glance_marker ): array {
				++$glance_calls;
				$items[] = '<span class="cfz-glance-item">' . \esc_html( $glance_marker ) . '</span>';
				return $items;
			};
			$comment_filter = static function ( $count, int $post_id ) use ( &$comment_calls, $case ) {
				$comment_calls[] = array(
					'inputType' => is_object( $count ) ? get_class( $count ) : gettype( $count ),
					'postId'    => $post_id,
				);
				return (object) array(
					'approved'       => (int) $case['approved'],
					'moderated'      => (int) $case['moderated'],
					'spam'           => 0,
					'trash'          => 0,
					'post-trashed'   => 0,
					'total_comments' => (int) $case['approved'] + (int) $case['moderated'],
					'all'            => (int) $case['approved'] + (int) $case['moderated'],
				);
			};
			$privacy_title_filter = static function () use ( &$privacy_title_calls, $privacy_title ): string {
				++$privacy_title_calls;
				return $privacy_title;
			};
			$privacy_text_filter = static function () use ( &$privacy_text_calls, $privacy_text ): string {
				++$privacy_text_calls;
				return $privacy_text;
			};
			$version_text_filter = static function () use ( &$version_text_calls, $version_text ): string {
				++$version_text_calls;
				return $version_text;
			};
			$rightnow_action = static function () use ( &$rightnow_calls, $label ): void {
				++$rightnow_calls;
				echo '<span class="cfz-rightnow-end">rightnow-' . \esc_html( $label ) . '</span>';
			};
			$activity_action = static function () use ( &$activity_calls, $label ): void {
				++$activity_calls;
				echo '<span class="cfz-activity-end">activity-' . \esc_html( $label ) . '</span>';
			};

			try {
				\add_filter( 'dashboard_glance_items', $glance_filter, 10, 1 );
				\add_filter( 'wp_count_comments', $comment_filter, 10, 2 );
				\add_filter( 'privacy_on_link_title', $privacy_title_filter, 10, 1 );
				\add_filter( 'privacy_on_link_text', $privacy_text_filter, 10, 1 );
				\add_filter( 'update_right_now_text', $version_text_filter, 10, 1 );
				\add_action( 'rightnow_end', $rightnow_action, 10, 0 );
				\add_action( 'activity_box_end', $activity_action, 10, 0 );

				$html = self::with_capabilities(
					array( 'edit_posts', 'edit_pages', 'manage_options', 'read', 'switch_themes' ),
					static function (): string {
						return self::capture_output(
							static function (): void {
								\wp_dashboard_right_now();
							}
						);
					}
				);
			} finally {
				\remove_action( 'activity_box_end', $activity_action, 10 );
				\remove_action( 'rightnow_end', $rightnow_action, 10 );
				\remove_filter( 'update_right_now_text', $version_text_filter, 10 );
				\remove_filter( 'privacy_on_link_text', $privacy_text_filter, 10 );
				\remove_filter( 'privacy_on_link_title', $privacy_title_filter, 10 );
				\remove_filter( 'wp_count_comments', $comment_filter, 10 );
				\remove_filter( 'dashboard_glance_items', $glance_filter, 10 );
			}

			$post_text = sprintf( _n( '%s Published post', '%s Published posts', (int) $case['posts'] ), \number_format_i18n( (int) $case['posts'] ) );
			$page_text = sprintf( _n( '%s Published page', '%s Published pages', (int) $case['pages'] ), \number_format_i18n( (int) $case['pages'] ) );
			$comment_text = sprintf( _n( '%s Comment', '%s Comments', (int) $case['approved'] ), \number_format_i18n( (int) $case['approved'] ) );
			$moderated_text = sprintf( _n( '%s Comment in moderation', '%s Comments in moderation', (int) $case['moderated'] ), \number_format_i18n( (int) $case['moderated'] ) );
			$expected_post_url = \esc_url(
				add_query_arg(
					array(
						'post_status' => 'publish',
						'post_type'   => 'post',
					),
					admin_url( 'edit.php' )
				)
			);
			$expected_page_url = \esc_url(
				add_query_arg(
					array(
						'post_status' => 'publish',
						'post_type'   => 'page',
					),
					admin_url( 'edit.php' )
				)
			);
			$hooks_removed = false === \has_filter( 'dashboard_glance_items', $glance_filter )
				&& false === \has_filter( 'wp_count_comments', $comment_filter )
				&& false === \has_filter( 'privacy_on_link_title', $privacy_title_filter )
				&& false === \has_filter( 'privacy_on_link_text', $privacy_text_filter )
				&& false === \has_filter( 'update_right_now_text', $version_text_filter )
				&& false === \has_filter( 'rightnow_end', $rightnow_action )
				&& false === \has_filter( 'activity_box_end', $activity_action );

			$case_summaries[ $label ] = array(
				'postText'     => $post_text,
				'pageText'     => $page_text,
				'commentText'  => $comment_text,
				'moderated'    => (int) $case['moderated'],
				'htmlHash'     => sha1( $html ),
				'glanceCalls'  => $glance_calls,
				'commentCalls' => $comment_calls,
			);

			self::collect_failure(
				$failures,
				str_contains( $html, '<li class="post-count"><a href="' . $expected_post_url . '">' . \esc_html( $post_text ) . '</a></li>' )
					&& str_contains( $html, '<li class="page-count"><a href="' . $expected_page_url . '">' . \esc_html( $page_text ) . '</a></li>' )
					&& ! str_contains( $html, 'Draft should not appear' )
					&& 1 === substr_count( $html, 'class="post-count"' )
					&& 1 === substr_count( $html, 'class="page-count"' ),
				'wp_dashboard_right_now() renders publish-only post and page counts with capability-gated edit links',
				array(
					'label'       => $label,
					'postText'    => $post_text,
					'pageText'    => $page_text,
					'postUrl'     => $expected_post_url,
					'pageUrl'     => $expected_page_url,
					'htmlPreview' => self::preview_string( $html ),
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $html, '<li class="comment-count">' )
					&& str_contains( $html, '<a href="edit-comments.php">' . $comment_text . '</a>' )
					&& str_contains( $html, 'class="comments-in-moderation-text">' . $moderated_text . '</a>' )
					&& ( (int) $case['moderated'] > 0 ? ! str_contains( $html, 'comment-mod-count hidden' ) : str_contains( $html, 'comment-mod-count hidden' ) )
					&& array( array( 'inputType' => 'array', 'postId' => 0 ) ) === $comment_calls,
				'wp_dashboard_right_now() renders approved and moderation comment counts from wp_count_comments()',
				array(
					'label'         => $label,
					'commentText'   => $comment_text,
					'moderatedText' => $moderated_text,
					'commentCalls'  => $comment_calls,
				)
			);

			self::collect_failure(
				$failures,
				1 === $glance_calls
					&& str_contains( $html, '<span class="cfz-glance-item">' . \esc_html( $glance_marker ) . '</span>' )
					&& 1 === $privacy_title_calls
					&& 1 === $privacy_text_calls
					&& str_contains( $html, "<p class='search-engines-info'><a href='options-reading.php' title='" . $privacy_title . "'>" . $privacy_text . '</a></p>' ),
				'wp_dashboard_right_now() renders dashboard_glance_items and search-engine privacy filters exactly once',
				array(
					'label'             => $label,
					'glanceCalls'       => $glance_calls,
					'privacyTitleCalls' => $privacy_title_calls,
					'privacyTextCalls'  => $privacy_text_calls,
					'marker'            => $glance_marker,
				)
			);

			self::collect_failure(
				$failures,
				1 === $version_text_calls
					&& str_contains( $html, '<p id=\'wp-version-message\'>' )
					&& str_contains( $html, '<span id="wp-version">CFZ version ' )
					&& str_contains( $html, '<a href="themes.php">' )
					&& 1 === $rightnow_calls
					&& 1 === $activity_calls
					&& str_contains( $html, '<div class="sub">' )
					&& str_contains( $html, '<span class="cfz-rightnow-end">rightnow-' . $label . '</span>' )
					&& str_contains( $html, '<span class="cfz-activity-end">activity-' . $label . '</span>' )
					&& $hooks_removed,
				'wp_dashboard_right_now() renders version/theme text plus rightnow/activity hook output and cleans temporary hooks',
				array(
					'label'            => $label,
					'versionTextCalls' => $version_text_calls,
					'rightnowCalls'    => $rightnow_calls,
					'activityCalls'    => $activity_calls,
					'hooksRemoved'     => $hooks_removed,
				)
			);
		}

		return self::result(
			$ctx,
			'admin-dashboard.right-now.counts-filters-hooks',
			$failures,
			array( 'cases' => $case_summaries )
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

	private static function check_recent_posts_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_runtime();
		$user_id = self::seed_user( $ctx->fork( 'user' ) );
		\wp_set_current_user( $user_id );

		$today     = \current_datetime();
		$tomorrow  = $today->modify( '+1 day' );
		$last_year = $today->modify( '-1 year' );
		$marker    = 'cfz_dashboard_recent_posts_' . self::slug( $ctx->fork( 'marker' ), 'marker' );

		$future_posts = array(
			self::recent_post_fixture( $ctx->fork( 'future-0' ), $user_id, 'future', $tomorrow->setTime( 8, 15 ), 'Soon zero' ),
			self::recent_post_fixture( $ctx->fork( 'future-1' ), $user_id, 'future', $tomorrow->setTime( 16, 45 ), 'Soon one' ),
			self::recent_post_fixture( $ctx->fork( 'future-2' ), $user_id, 'future', $today->modify( '+3 days' )->setTime( 11, 30 ), 'Soon two' ),
			self::recent_post_fixture( $ctx->fork( 'future-3' ), $user_id, 'future', $today->modify( '+4 days' )->setTime( 9, 0 ), 'Soon three' ),
		);
		$publish_posts = array(
			self::recent_post_fixture( $ctx->fork( 'publish-0' ), $user_id, 'publish', $today->setTime( 13, 20 ), 'Published zero' ),
			self::recent_post_fixture( $ctx->fork( 'publish-1' ), $user_id, 'publish', $today->modify( '-2 days' )->setTime( 7, 10 ), 'Published one' ),
			self::recent_post_fixture( $ctx->fork( 'publish-2' ), $user_id, 'publish', $last_year->setTime( 18, 5 ), 'Published two' ),
		);
		$posts_by_status = array(
			'future'  => $future_posts,
			'publish' => $publish_posts,
		);
		$query_events    = array();

		$query_arg_filter = static function ( array $query_args ) use ( &$query_events, $marker ): array {
			$query_events[] = array(
				'filter'       => 'dashboard_recent_posts_query_args',
				'postStatus'   => $query_args['post_status'] ?? null,
				'order'        => $query_args['order'] ?? null,
				'postsPerPage' => $query_args['posts_per_page'] ?? null,
				'perm'         => $query_args['perm'] ?? null,
				'noFoundRows'  => $query_args['no_found_rows'] ?? null,
				'cacheResults' => $query_args['cache_results'] ?? null,
			);

			$query_args['component_fuzz_dashboard_marker'] = $marker;

			return $query_args;
		};
		$pre_query_filter = static function ( $posts, \WP_Query $query ) use ( &$query_events, $marker, $posts_by_status ) {
			if ( $marker !== ( $query->query_vars['component_fuzz_dashboard_marker'] ?? null ) ) {
				return $posts;
			}

			$status   = (string) ( $query->query_vars['post_status'] ?? '' );
			$order    = strtoupper( (string) ( $query->query_vars['order'] ?? 'DESC' ) );
			$limit    = max( 0, (int) ( $query->query_vars['posts_per_page'] ?? 0 ) );
			$selected = $posts_by_status[ $status ] ?? array();

			usort(
				$selected,
				static function ( \WP_Post $a, \WP_Post $b ) use ( $order ): int {
					$compare = strcmp( (string) $a->post_date_gmt, (string) $b->post_date_gmt );
					return 'ASC' === $order ? $compare : -$compare;
				}
			);

			$total                = count( $selected );
			$selected             = array_slice( $selected, 0, $limit );
			$query->found_posts   = $total;
			$query->max_num_pages = 1;

			$query_events[] = array(
				'filter'       => 'posts_pre_query',
				'postStatus'   => $status,
				'order'        => $order,
				'limit'        => $limit,
				'perm'         => $query->query_vars['perm'] ?? null,
				'noFoundRows'  => $query->query_vars['no_found_rows'] ?? null,
				'cacheResults' => $query->query_vars['cache_results'] ?? null,
				'returnedIds'  => self::post_ids( $selected ),
			);

			return $selected;
		};

		$post_snapshot = self::snapshot_globals( array( 'post' ) );
		\add_filter( 'dashboard_recent_posts_query_args', $query_arg_filter, 10, 1 );
		\add_filter( 'posts_pre_query', $pre_query_filter, 10, 2 );
		try {
			$future_result = self::with_capabilities(
				array( 'edit_post', 'edit_posts', 'edit_published_posts', 'edit_others_posts', 'read_post', 'read' ),
				static function (): array {
					ob_start();
					$return = \wp_dashboard_recent_posts(
						array(
							'max'    => 3,
							'status' => 'future',
							'order'  => 'ASC',
							'title'  => 'Publishing Soon',
							'id'     => 'future-posts',
						)
					);
					return array(
						'return' => $return,
						'html'   => (string) ob_get_clean(),
					);
				}
			);
			$publish_result = self::with_capabilities(
				array( 'read_post', 'read' ),
				static function (): array {
					ob_start();
					$return = \wp_dashboard_recent_posts(
						array(
							'max'    => 2,
							'status' => 'publish',
							'order'  => 'DESC',
							'title'  => 'Recently Published',
							'id'     => 'published-posts',
						)
					);
					return array(
						'return' => $return,
						'html'   => (string) ob_get_clean(),
					);
				}
			);
		} finally {
			\remove_filter( 'posts_pre_query', $pre_query_filter, 10 );
			\remove_filter( 'dashboard_recent_posts_query_args', $query_arg_filter, 10 );
			self::restore_globals( $post_snapshot );
		}

		$future_html      = $future_result['html'];
		$publish_html     = $publish_result['html'];
		$future_fragments = array_map(
			static function ( \WP_Post $post ): string {
				return 'post=' . $post->ID . '&amp;action=edit';
			},
			array_slice( $future_posts, 0, 3 )
		);
		$publish_fragments = array_map(
			static function ( \WP_Post $post ): string {
				return '?p=' . $post->ID;
			},
			array_slice( $publish_posts, 0, 2 )
		);
		$query_arg_events  = array_values(
			array_filter(
				$query_events,
				static function ( array $event ): bool {
					return 'dashboard_recent_posts_query_args' === ( $event['filter'] ?? null );
				}
			)
		);
		$pre_query_events  = array_values(
			array_filter(
				$query_events,
				static function ( array $event ): bool {
					return 'posts_pre_query' === ( $event['filter'] ?? null );
				}
			)
		);

		self::collect_failure(
			$failures,
			2 === count( $query_arg_events )
				&& 2 === count( $pre_query_events )
				&& 'future' === ( $query_arg_events[0]['postStatus'] ?? null )
				&& 'ASC' === ( $query_arg_events[0]['order'] ?? null )
				&& 3 === (int) ( $query_arg_events[0]['postsPerPage'] ?? 0 )
				&& 'editable' === ( $query_arg_events[0]['perm'] ?? null )
				&& true === ( $query_arg_events[0]['noFoundRows'] ?? null )
				&& true === ( $query_arg_events[0]['cacheResults'] ?? null )
				&& 'publish' === ( $query_arg_events[1]['postStatus'] ?? null )
				&& 'DESC' === ( $query_arg_events[1]['order'] ?? null )
				&& 2 === (int) ( $query_arg_events[1]['postsPerPage'] ?? 0 )
				&& 'readable' === ( $query_arg_events[1]['perm'] ?? null )
				&& self::post_ids( array_slice( $future_posts, 0, 3 ) ) === ( $pre_query_events[0]['returnedIds'] ?? null )
				&& self::post_ids( array_slice( $publish_posts, 0, 2 ) ) === ( $pre_query_events[1]['returnedIds'] ?? null ),
			'wp_dashboard_recent_posts() builds bounded query arguments for future/editable and publish/readable activity sections',
			array( 'events' => $query_events )
		);

		self::collect_failure(
			$failures,
			true === $future_result['return']
				&& str_contains( $future_html, 'id="future-posts"' )
				&& str_contains( $future_html, '<h3>Publishing Soon</h3>' )
				&& 3 === substr_count( $future_html, '<li><span>' )
				&& str_contains( $future_html, 'Tomorrow' )
				&& self::strings_in_order( $future_html, $future_fragments )
				&& ! str_contains( strtolower( $future_html ), '<script' )
				&& ! str_contains( strtolower( $future_html ), 'onerror=' ),
			'future recent-post activity renders editable links, relative dates, bounded rows, and escaped hostile titles',
			array( 'html' => self::preview_string( $future_html ) )
		);

		self::collect_failure(
			$failures,
			true === $publish_result['return']
				&& str_contains( $publish_html, 'id="published-posts"' )
				&& str_contains( $publish_html, '<h3>Recently Published</h3>' )
				&& 2 === substr_count( $publish_html, '<li><span>' )
				&& str_contains( $publish_html, 'Today' )
				&& self::strings_in_order( $publish_html, $publish_fragments )
				&& ! str_contains( $publish_html, 'post.php?post=' )
				&& ! str_contains( strtolower( $publish_html ), '<script' )
				&& ! str_contains( strtolower( $publish_html ), 'onerror=' ),
			'published recent-post activity falls back to permalinks for non-editors while preserving safe output',
			array( 'html' => self::preview_string( $publish_html ) )
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'dashboard_recent_posts_query_args', $query_arg_filter )
				&& false === \has_filter( 'posts_pre_query', $pre_query_filter )
				&& self::globals_match( $post_snapshot ),
			'recent-post query filters and post globals are restored after the check',
			array( 'changedGlobals' => self::changed_globals( $post_snapshot ) )
		);

		return self::result(
			$ctx,
			'admin-dashboard.helpers.recent-posts-query-output',
			$failures,
			array(
				'events'      => $query_events,
				'futureHash'  => sha1( $future_html ),
				'publishHash' => sha1( $publish_html ),
			)
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

	private static function check_cached_rss_widget( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures  = array();
		$token     = self::slug( $ctx->fork( 'token' ), 'rss' );
		$widget_id = 'cfz_dashboard_rss_' . $token;
		$feed_url  = 'https://example.test/dashboard/' . rawurlencode( $token ) . '/news.xml';
		$feed_args = array(
			'url'          => $feed_url,
			'title'        => 'News <b>' . $token . '</b>',
			'items'        => $ctx->int( 1, 4 ),
			'show_summary' => $ctx->bool(),
			'show_author'  => $ctx->bool(),
			'show_date'    => $ctx->bool(),
		);
		$feeds     = array( $feed_url => $feed_args );
		$cache_key = 'dash_v2_' . md5( $widget_id . '_' . \get_user_locale() );
		$calls     = array();
		$callback  = static function ( string $called_widget_id, array $check_urls, array $called_feed_args ) use ( &$calls, $token ): void {
			$calls[] = array(
				'widgetId'  => $called_widget_id,
				'checkUrls' => array_keys( $check_urls ),
				'title'     => $called_feed_args['title'] ?? null,
				'items'     => $called_feed_args['items'] ?? null,
			);

			echo '<section class="cfz-dashboard-rss" data-widget="' . \esc_attr( $called_widget_id ) . '">';
			echo '<a href="' . \esc_url( $called_feed_args['url'] ?? '' ) . '">' . \esc_html( \wp_strip_all_tags( $called_feed_args['title'] ?? '' ) ) . '</a>';
			echo '<span data-rss-token="' . \esc_attr( $token ) . '"></span>';
			echo '</section>';
		};

		$loading_return = null;
		$ajax_return    = null;
		$cached_return  = null;
		$loading         = '';
		$loading_calls   = array();
		$ajax            = '';
		$cached          = '';
		$cached_value    = false;
		$cleaned_value   = null;
		$http_requests   = array();
		$http_filter     = static function ( $preempt, array $parsed_args, string $url ) use ( &$http_requests ) {
			$http_requests[] = array(
				'url'    => $url,
				'method' => $parsed_args['method'] ?? null,
			);

			return new \WP_Error( 'component_fuzz_unexpected_http', 'Component fuzz blocked an unexpected dashboard RSS HTTP request.' );
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		try {
			\delete_transient( $cache_key );
			$loading = self::capture_output(
				static function () use ( $callback, $feeds, $feed_args, $widget_id, &$loading_return ): void {
					$loading_return = \wp_dashboard_cached_rss_widget( $widget_id, $callback, $feeds, $feed_args );
				}
			);
			$loading_calls = $calls;

			$ajax_filter = static function (): bool {
				return true;
			};

			\add_filter( 'wp_doing_ajax', $ajax_filter );
			try {
				$ajax = self::capture_output(
					static function () use ( $callback, $feeds, $feed_args, $widget_id, &$ajax_return ): void {
						$ajax_return = \wp_dashboard_cached_rss_widget( $widget_id, $callback, $feeds, $feed_args );
					}
				);
			} finally {
				\remove_filter( 'wp_doing_ajax', $ajax_filter );
			}

			$cached_value = \get_transient( $cache_key );
			$cached       = self::capture_output(
				static function () use ( $callback, $feeds, $feed_args, $widget_id, &$cached_return ): void {
					$cached_return = \wp_dashboard_cached_rss_widget( $widget_id, $callback, $feeds, $feed_args );
				}
			);
			\delete_transient( $cache_key );
			$cleaned_value = \get_transient( $cache_key );
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			\delete_transient( $cache_key );
		}

		self::collect_failure(
			$failures,
			array() === $http_requests
				&& false === \has_filter( 'pre_http_request', $http_filter ),
			'cached dashboard RSS widget does not issue HTTP requests and removes the HTTP tripwire',
			array(
				'httpRequests' => $http_requests,
				'filter'       => \has_filter( 'pre_http_request', $http_filter ),
			)
		);

		self::collect_failure(
			$failures,
			false === $cleaned_value,
			'cached dashboard RSS widget transient is explicitly cleaned after replay',
			array( 'cleanedValue' => self::preview( $cleaned_value ) )
		);

		self::collect_failure(
			$failures,
			false === $loading_return
				&& str_contains( $loading, 'widget-loading hide-if-no-js' )
				&& str_contains( $loading, 'This widget requires JavaScript.' )
				&& array() === $loading_calls,
			'cached dashboard RSS widget emits loading fallback without AJAX or cache',
			array(
				'return' => $loading_return,
				'html'   => self::preview_string( $loading ),
				'calls'  => $loading_calls,
			)
		);

		self::collect_failure(
			$failures,
			true === $ajax_return
				&& 1 === count( $calls )
				&& $widget_id === ( $calls[0]['widgetId'] ?? null )
				&& array_keys( $feeds ) === ( $calls[0]['checkUrls'] ?? null )
				&& str_contains( $ajax, 'class="cfz-dashboard-rss"' )
				&& str_contains( $ajax, 'data-rss-token="' . \esc_attr( $token ) . '"' )
				&& $ajax === $cached_value,
			'cached dashboard RSS widget calls the callback only during AJAX generation and stores exact output',
			array(
				'return' => $ajax_return,
				'calls'  => $calls,
				'html'   => self::preview_string( $ajax ),
				'cache'  => self::preview( $cached_value ),
			)
		);

		self::collect_failure(
			$failures,
			true === $cached_return
				&& $ajax === $cached
				&& 1 === count( $calls )
				&& false === \has_filter( 'wp_doing_ajax', $ajax_filter ),
			'cached dashboard RSS widget returns cached output without rerunning the callback and removes AJAX filter',
			array(
				'return'     => $cached_return,
				'cachedHtml' => self::preview_string( $cached ),
				'calls'      => $calls,
				'filter'     => \has_filter( 'wp_doing_ajax', $ajax_filter ),
			)
		);

		return self::result(
			$ctx,
			'admin-dashboard.helpers.cached-rss-widget-cache-branches',
			$failures,
			array(
				'widgetId' => $widget_id,
				'cacheKey' => $cache_key,
			)
		);
	}

	private static function check_browser_nag_remote_cache( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_browser_nag_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'admin-dashboard.helpers.browser-nag-remote-cache',
				'Browser Happy dashboard helper dependencies are unavailable in this checkout.',
				array( 'missing' => $missing )
			);
		}

		$failures        = array();
		$requests        = array();
		$case_summaries  = array();
		$active_response = null;
		$active_failure  = null;
		$expected_url    = self::browser_happy_endpoint();
		$expected_agent  = 'WordPress/' . \wp_get_wp_version() . '; ' . \home_url( '/' );
		$transients      = array();
		$global_snapshot = self::snapshot_globals( array( '_SERVER', 'is_IE' ) );
		$options_before  = self::options_snapshot();
		$http_filter     = static function ( $preempt, array $parsed_args, string $url ) use ( &$active_failure, &$active_response, &$requests ) {
			$requests[] = array(
				'url'       => $url,
				'method'    => $parsed_args['method'] ?? null,
				'body'      => $parsed_args['body'] ?? null,
				'userAgent' => $parsed_args['user-agent'] ?? null,
				'headers'   => $parsed_args['headers'] ?? null,
			);

			if ( null === $active_response ) {
				return new \WP_Error( 'component_fuzz_unexpected_browser_http', 'Component fuzz blocked an unexpected Browser Happy HTTP request.' );
			}

			if ( is_array( $active_failure ) ) {
				if ( 'wp-error' === ( $active_failure['type'] ?? '' ) ) {
					return new \WP_Error( 'component_fuzz_browser_http_failure', 'Generated Browser Happy HTTP failure.' );
				}

				return array(
					'headers'  => array(),
					'body'     => $active_failure['body'] ?? '',
					'response' => array(
						'code'    => $active_failure['code'] ?? 500,
						'message' => $active_failure['message'] ?? 'Generated failure',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			return array(
				'headers'  => array(),
				'body'     => \wp_json_encode( $active_response ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		$cases           = array(
			'insecure'    => array(
				'userAgent'       => self::browser_user_agent( $ctx->fork( 'ua-insecure' ), 'Chrome' ),
				'response'        => self::browser_response_fixture( $ctx->fork( 'response-insecure' ), true, true ),
				'ssl'             => true,
				'expectsClass'    => true,
				'ie'              => false,
				'messageFragment' => 'insecure version',
			),
			'recommended' => array(
				'userAgent'       => self::browser_user_agent( $ctx->fork( 'ua-recommended' ), 'Firefox' ),
				'response'        => self::browser_response_fixture( $ctx->fork( 'response-recommended' ), true, false ),
				'ssl'             => false,
				'expectsClass'    => false,
				'ie'              => false,
				'messageFragment' => 'old version',
			),
			'ie'          => array(
				'userAgent'       => self::browser_user_agent( $ctx->fork( 'ua-ie' ), 'MSIE' ),
				'response'        => self::browser_response_fixture( $ctx->fork( 'response-ie' ), true, true ),
				'ssl'             => true,
				'expectsClass'    => true,
				'ie'              => true,
				'messageFragment' => 'Internet Explorer does not give you the best WordPress experience',
			),
		);

		foreach ( $cases as $case ) {
			$transients[] = 'browser_' . md5( $case['userAgent'] );
		}

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		try {
			foreach ( $cases as $label => $case ) {
				$user_agent = $case['userAgent'];
				$response   = $case['response'];
				$transient  = 'browser_' . md5( $user_agent );

				\delete_site_transient( $transient );

				$active_response              = $response;
				$active_failure               = null;
				$_SERVER['HTTP_USER_AGENT']   = $user_agent;
				$_SERVER['HTTPS']             = $case['ssl'] ? 'on' : 'off';
				$_SERVER['SERVER_PORT']       = $case['ssl'] ? '443' : '80';
				$GLOBALS['is_IE']             = (bool) $case['ie'];
				$request_count_before         = count( $requests );
				$first_response               = \wp_check_browser_version();
				$cached_response              = \get_site_transient( $transient );
				$timeout_option               = \get_site_option( '_site_transient_timeout_' . $transient );
				$timeout_remaining            = is_numeric( $timeout_option ) ? ( (int) $timeout_option - time() ) : null;
				$second_response              = \wp_check_browser_version();
				$base_classes                 = array( 'postbox', 'cfz-browser-nag' );
				$classes                      = \dashboard_browser_nag_class( $base_classes );
				$nag_html                     = self::capture_output(
					static function (): void {
						\wp_dashboard_browser_nag();
					}
				);
				$request_count_after_helpers  = count( $requests );
				$request                      = $requests[ $request_count_before ] ?? array();
				$expected_image               = ( $case['ssl'] && ! empty( $response['img_src_ssl'] ) ) ? $response['img_src_ssl'] : $response['img_src'];
				$expected_browser_name_link   = sprintf(
					'<a href="%s">%s</a>',
					\esc_url( $response['update_url'] ),
					\esc_html( $response['name'] )
				);
				$expected_update_action_link  = $case['ie']
					? '<a href="https://browsehappy.com/" class="update-browser-link">browse happy</a>'
					: sprintf(
						'<a href="%1$s" class="update-browser-link">Update %2$s</a>',
						\esc_attr( $response['update_url'] ),
						\esc_html( $response['name'] )
					);
				$expected_image_fragment      = '<img src="' . \esc_url( $expected_image ) . '" alt="" />';
				$has_insecure_class           = in_array( 'browser-insecure', $classes, true );
				$base_classes_preserved       = array_values( array_intersect( $base_classes, $classes ) ) === $base_classes;
				$cleaned_response             = false;

				\delete_site_transient( $transient );
				$cleaned_response = \get_site_transient( $transient );

				$case_summaries[ $label ] = array(
					'transient'    => $transient,
					'requestCount' => $request_count_after_helpers - $request_count_before,
					'request'      => $request,
					'classes'      => $classes,
					'htmlHash'     => sha1( $nag_html ),
					'timeout'      => $timeout_remaining,
				);

				self::collect_failure(
					$failures,
					1 === ( $request_count_after_helpers - $request_count_before )
						&& $response === $first_response
						&& $response === $second_response
						&& $response === $cached_response
						&& $expected_url === ( $request['url'] ?? null )
						&& 'POST' === ( $request['method'] ?? null )
						&& array( 'useragent' => $user_agent ) === ( $request['body'] ?? null )
						&& $expected_agent === ( $request['userAgent'] ?? null )
						&& is_int( $timeout_remaining )
						&& WEEK_IN_SECONDS - 5 <= $timeout_remaining
						&& WEEK_IN_SECONDS >= $timeout_remaining,
					'wp_check_browser_version() posts the generated user agent once, stores the site transient for one week, and reuses cached results',
					array(
						'label'          => $label,
						'expectedUrl'    => $expected_url,
						'expectedAgent'  => $expected_agent,
						'request'        => $request,
						'firstResponse'  => $first_response,
						'cachedResponse' => $cached_response,
						'timeout'        => $timeout_option,
					)
				);

				self::collect_failure(
					$failures,
					$base_classes_preserved
						&& $case['expectsClass'] === $has_insecure_class
						&& ( ! $case['expectsClass'] || count( $base_classes ) + 1 === count( $classes ) )
						&& ( $case['expectsClass'] || count( $base_classes ) === count( $classes ) ),
					'dashboard_browser_nag_class() preserves caller classes and adds browser-insecure only for insecure Browse Happy responses',
					array(
						'label'                => $label,
						'baseClassesPreserved' => $base_classes_preserved,
						'classes'              => $classes,
					)
				);

				self::collect_failure(
					$failures,
					str_contains( $nag_html, $case['messageFragment'] )
						&& ( $case['ie'] || str_contains( $nag_html, $expected_browser_name_link ) )
						&& str_contains( $nag_html, $expected_update_action_link )
						&& str_contains( $nag_html, $expected_image_fragment )
						&& str_contains( $nag_html, 'browser-update-nag has-browser-icon' )
						&& str_contains( $nag_html, 'browsehappy.com' )
						&& str_contains( $nag_html, 'class="dismiss"' )
						&& ( $case['ie'] || ! str_contains( $nag_html, $response['name'] ) )
						&& ! str_contains( strtolower( $nag_html ), '<script' ),
					'wp_dashboard_browser_nag() renders the expected regular or IE branch and escapes browser names, update URLs, and icon URLs',
					array(
						'label'              => $label,
						'ie'                 => $case['ie'],
						'expectedNameLink'   => $expected_browser_name_link,
						'expectedUpdateLink' => $expected_update_action_link,
						'expectedImage'      => $expected_image_fragment,
						'html'               => self::preview_string( $nag_html ),
					)
				);

				self::collect_failure(
					$failures,
					false === $cleaned_response,
					'browser version site transient is cleaned after each Browser Happy fixture replay',
					array(
						'label'   => $label,
						'cleaned' => self::preview( $cleaned_response ),
					)
				);
			}

			foreach ( self::browser_failure_cases( $ctx->fork( 'failure-cases' ) ) as $label => $failure_case ) {
				$user_agent = $failure_case['userAgent'];
				$transient  = 'browser_' . md5( $user_agent );

				$transients[] = $transient;
				\delete_site_transient( $transient );

				$active_response              = array();
				$active_failure               = $failure_case['failure'];
				$_SERVER['HTTP_USER_AGENT']   = $user_agent;
				$_SERVER['HTTPS']             = 'off';
				$_SERVER['SERVER_PORT']       = '80';
				$GLOBALS['is_IE']             = false;
				$request_count_before_failure = count( $requests );
				$failure_response             = \wp_check_browser_version();
				$cached_failure_response      = \get_site_transient( $transient );
				$missing_marker               = 'component_fuzz_missing_browser_transient_' . $label;
				$raw_failure_response         = \get_site_option( '_site_transient_' . $transient, $missing_marker );
				$raw_failure_timeout          = \get_site_option( '_site_transient_timeout_' . $transient, $missing_marker );
				$second_failure_response      = \wp_check_browser_version();
				$failure_request_count        = count( $requests ) - $request_count_before_failure;

				\delete_site_transient( $transient );

				self::collect_failure(
					$failures,
					2 === $failure_request_count
						&& false === $failure_response
						&& false === $second_failure_response
						&& false === $cached_failure_response
						&& $missing_marker === $raw_failure_response
						&& $missing_marker === $raw_failure_timeout,
					'wp_check_browser_version() fails closed and does not cache generated HTTP errors, non-200 responses, or invalid JSON',
					array(
						'label'          => $label,
						'failure'        => $failure_case['failure'],
						'firstResponse'  => $failure_response,
						'secondResponse' => $second_failure_response,
						'cachedValue'    => self::preview( $cached_failure_response ),
						'rawValue'       => self::preview( $raw_failure_response ),
						'rawTimeout'     => self::preview( $raw_failure_timeout ),
					)
				);
			}

			$active_response            = null;
			$active_failure             = null;
			$request_count_before_empty = count( $requests );
			unset( $_SERVER['HTTP_USER_AGENT'] );
			$empty_user_agent_response  = \wp_check_browser_version();

			self::collect_failure(
				$failures,
				false === $empty_user_agent_response
					&& $request_count_before_empty === count( $requests ),
				'wp_check_browser_version() returns false without issuing HTTP when HTTP_USER_AGENT is empty',
				array(
					'response'      => $empty_user_agent_response,
					'requestBefore' => $request_count_before_empty,
					'requestAfter'  => count( $requests ),
				)
			);
		} finally {
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			foreach ( $transients as $transient ) {
				\delete_site_transient( $transient );
			}
			self::restore_globals( $global_snapshot );
		}

		$transients_after = array();
		foreach ( $transients as $transient ) {
			$transients_after[ $transient ] = \get_site_transient( $transient );
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'pre_http_request', $http_filter )
				&& self::globals_match( $global_snapshot )
				&& self::all_call_values( array_map(
					static function ( $value ): array {
						return array( 'value' => $value );
					},
					$transients_after
				), 'value', false )
				&& $options_before === self::options_snapshot(),
			'Browser Happy HTTP filter, server globals, browser global, site transient options, and cache state are restored',
			array(
				'filter'          => \has_filter( 'pre_http_request', $http_filter ),
				'changedGlobals'  => self::changed_globals( $global_snapshot ),
				'transientsAfter' => self::preview( $transients_after ),
			)
		);

		return self::result(
			$ctx,
			'admin-dashboard.helpers.browser-nag-remote-cache',
			$failures,
			array(
				'cases'        => $case_summaries,
				'requestCount' => count( $requests ),
			)
		);
	}

	private static function check_community_events_markup_and_templates( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures      = array();
		$buffer_before = ob_get_level();
		$markup        = self::capture_output(
			static function (): void {
				\wp_print_community_events_markup();
			}
		);
		$templates     = self::capture_output(
			static function (): void {
				\wp_print_community_events_templates();
			}
		);
		$expected_form_action = \esc_url( \admin_url( 'admin-ajax.php' ) );
		$markup_lower         = strtolower( $markup );
		$template_lower       = strtolower( $templates );

		$markup_fragments = array(
			'community-events-errors',
			'community-events-error-occurred',
			'community-events-could-not-locate',
			'hide-if-js',
			'id="community-events"',
			'class="community-events"',
			'aria-hidden="true"',
			'id="community-events-location-message"',
			'button-link community-events-toggle-location',
			'aria-expanded="false"',
			'class="community-events-form"',
			'action="' . $expected_form_action . '"',
			'method="post"',
			'for="community-events-location"',
			'id="community-events-location"',
			'name="community-events-location"',
			'placeholder="Cincinnati"',
			'name="community-events-submit"',
			'id="community-events-submit"',
			'community-events-cancel button-link',
			'class="spinner"',
			'community-events-results activity-block last',
		);
		$missing_markup   = array();
		foreach ( $markup_fragments as $fragment ) {
			if ( ! str_contains( $markup, $fragment ) ) {
				$missing_markup[] = $fragment;
			}
		}

		self::collect_failure(
			$failures,
			array() === $missing_markup,
			'wp_print_community_events_markup() renders the hidden dashboard shell, error notice, location form, AJAX endpoint, controls, and result list',
			array(
				'missing' => $missing_markup,
				'html'    => self::preview_string( $markup ),
			)
		);

		$id_counts = array(
			'community-events'                  => substr_count( $markup, 'id="community-events"' ),
			'community-events-location-message' => substr_count( $markup, 'id="community-events-location-message"' ),
			'community-events-location'         => substr_count( $markup, 'id="community-events-location"' ),
			'community-events-submit'           => substr_count( $markup, 'id="community-events-submit"' ),
		);
		self::collect_failure(
			$failures,
			1 === $id_counts['community-events']
				&& 1 === $id_counts['community-events-location-message']
				&& 1 === $id_counts['community-events-location']
				&& 1 === $id_counts['community-events-submit']
				&& ! str_contains( $markup_lower, '<script' )
				&& ! str_contains( $markup_lower, 'javascript:' )
				&& ! str_contains( $markup_lower, 'onerror=' ),
			'Community Events markup keeps stable unique IDs and does not emit executable inline script or generated event handlers',
			array(
				'idCounts' => $id_counts,
				'html'     => self::preview_string( $markup ),
			)
		);

		$template_ids = array(
			'tmpl-community-events-attend-event-near',
			'tmpl-community-events-could-not-locate',
			'tmpl-community-events-event-list',
			'tmpl-community-events-no-upcoming-events',
		);
		$template_id_counts = array();
		foreach ( $template_ids as $template_id ) {
			$template_id_counts[ $template_id ] = substr_count( $templates, 'id="' . $template_id . '"' );
		}

		$template_fragments = array(
			'<script id="tmpl-community-events-attend-event-near" type="text/template">',
			'{{ data.location.description }}',
			'<script id="tmpl-community-events-could-not-locate" type="text/template">',
			'{{data.unknownCity}}',
			'<script id="tmpl-community-events-event-list" type="text/template">',
			'_.each( data.events',
			'class="event event-{{ event.type }} wp-clearfix"',
			'href="{{ event.url }}"',
			'{{ event.title }}',
			'{{ event.location.location }}',
			'{{ event.user_formatted_date }}',
			'{{ event.user_formatted_time }} {{ event.timeZoneAbbreviation }}',
			'class="event-none"',
			'make.wordpress.org/community/organize-event-landing-page',
			'<script id="tmpl-community-events-no-upcoming-events" type="text/template">',
			'if ( data.location.description )',
			'make.wordpress.org/community/handbook/meetup-organizer/welcome',
		);
		$missing_templates  = array();
		foreach ( $template_fragments as $fragment ) {
			if ( ! str_contains( $templates, $fragment ) ) {
				$missing_templates[] = $fragment;
			}
		}

		self::collect_failure(
			$failures,
			array() === $missing_templates
				&& 4 === substr_count( $templates, '<script id="tmpl-community-events-' )
				&& self::all_call_values(
					array_map(
						static function ( int $count ): array {
							return array( 'count' => $count );
						},
						$template_id_counts
					),
					'count',
					1
				)
				&& ! str_contains( $template_lower, 'javascript:' )
				&& ! str_contains( $template_lower, 'onerror=' ),
			'wp_print_community_events_templates() renders the four expected Underscore templates with event fields, city placeholders, organizer links, and no javascript URLs',
			array(
				'missing'  => $missing_templates,
				'idCounts' => $template_id_counts,
				'html'     => self::preview_string( $templates ),
			)
		);

		self::collect_failure(
			$failures,
			$buffer_before === ob_get_level(),
			'Community Events markup/template capture restores the output buffer level',
			array(
				'before' => $buffer_before,
				'after'  => ob_get_level(),
			)
		);

		return self::result(
			$ctx,
			'admin-dashboard.community-events.markup-and-templates',
			$failures,
			array(
				'markupHash'    => sha1( $markup ),
				'templatesHash' => sha1( $templates ),
				'formAction'    => $expected_form_action,
				'templateCount' => substr_count( $templates, '<script id="tmpl-community-events-' ),
			)
		);
	}

	private static function check_dashboard_setup_direct_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures             = array();
		$case_summaries       = array();
		$actions              = array(
			'wp_dashboard_setup'         => 0,
			'wp_network_dashboard_setup' => 0,
			'wp_user_dashboard_setup'    => 0,
		);
		$filters              = array(
			'wp_dashboard_widgets'         => array(),
			'wp_network_dashboard_widgets' => array(),
			'wp_user_dashboard_widgets'    => array(),
		);
		$meta_box_actions     = array();
		$redirects            = array();
		$http_requests        = array();
		$active_filter        = null;
		$active_filter_widget = null;
		$action_callbacks     = array();
		$filter_callbacks     = array();
		$registered_snapshot  = self::snapshot_globals( array( 'wp_registered_widgets', 'wp_registered_widget_controls' ) );
		$transients           = array();

		foreach ( array_keys( $actions ) as $action ) {
			$action_callbacks[ $action ] = static function () use ( &$actions, $action ): void {
				++$actions[ $action ];
			};
			\add_action( $action, $action_callbacks[ $action ] );
		}

		foreach ( array_keys( $filters ) as $filter ) {
			$filter_callbacks[ $filter ] = static function ( array $dashboard_widgets ) use ( &$active_filter, &$active_filter_widget, &$filters, $filter ): array {
				$return = $dashboard_widgets;
				if ( $filter === $active_filter && is_string( $active_filter_widget ) && '' !== $active_filter_widget ) {
					$return = array( $active_filter_widget );
				}

				$filters[ $filter ][] = array(
					'input'    => $dashboard_widgets,
					'returned' => $return,
				);

				return $return;
			};
			\add_filter( $filter, $filter_callbacks[ $filter ], 10, 1 );
		}

		$meta_box_callback = static function ( $screen_id, $context, $object ) use ( &$meta_box_actions ): void {
			$meta_box_actions[] = array(
				'screen'  => $screen_id,
				'context' => $context,
				'object'  => $object,
			);
		};
		$redirect_filter   = static function ( $location, $status = null ) use ( &$redirects ) {
			$redirects[] = array(
				'location' => $location,
				'status'   => $status,
			);

			return $location;
		};
		$http_filter       = static function ( $preempt, array $parsed_args, string $url ) use ( &$http_requests ) {
			$http_requests[] = array(
				'url'    => $url,
				'method' => $parsed_args['method'] ?? null,
			);

			return new \WP_Error( 'component_fuzz_unexpected_dashboard_setup_http', 'Component fuzz blocked unexpected dashboard setup HTTP.' );
		};

		\add_action( 'do_meta_boxes', $meta_box_callback, 10, 3 );
		\add_filter( 'wp_redirect', $redirect_filter, 10, 2 );
		\add_filter( 'pre_http_request', $http_filter, 10, 3 );

		$cases = array(
			'site'    => array(
				'hook'             => 'index.php',
				'capabilities'     => array( 'create_posts', 'edit_dashboard', 'edit_posts', 'read' ),
				'expectedAction'   => 'wp_dashboard_setup',
				'expectedFilter'   => 'wp_dashboard_widgets',
				'expectedScreen'   => array(
					'id'      => 'dashboard',
					'site'    => true,
					'network' => false,
					'user'    => false,
				),
				'expectedBoxes'    => array(
					'dashboard_right_now'   => array( 'context' => 'normal', 'priority' => 'core' ),
					'dashboard_activity'    => array( 'context' => 'normal', 'priority' => 'core' ),
					'dashboard_quick_press' => array( 'context' => 'side', 'priority' => 'core' ),
					'dashboard_primary'     => array( 'context' => 'side', 'priority' => 'core' ),
				),
				'unexpectedBoxes'  => array( 'network_dashboard_right_now', 'dashboard_browser_nag', 'dashboard_php_nag', 'dashboard_site_health' ),
			),
			'network' => array(
				'hook'             => 'index-network',
				'capabilities'     => array( 'edit_dashboard', 'read' ),
				'expectedAction'   => 'wp_network_dashboard_setup',
				'expectedFilter'   => 'wp_network_dashboard_widgets',
				'expectedScreen'   => array(
					'id'      => 'dashboard-network',
					'site'    => false,
					'network' => true,
					'user'    => false,
				),
				'expectedBoxes'    => array(
					'network_dashboard_right_now' => array( 'context' => 'normal', 'priority' => 'core' ),
					'dashboard_primary'           => array( 'context' => 'side', 'priority' => 'core' ),
				),
				'unexpectedBoxes'  => array( 'dashboard_right_now', 'dashboard_activity', 'dashboard_quick_press', 'dashboard_browser_nag', 'dashboard_php_nag', 'dashboard_site_health' ),
			),
			'user'    => array(
				'hook'             => 'index-user',
				'capabilities'     => array( 'edit_dashboard', 'read' ),
				'expectedAction'   => 'wp_user_dashboard_setup',
				'expectedFilter'   => 'wp_user_dashboard_widgets',
				'expectedScreen'   => array(
					'id'      => 'dashboard-user',
					'site'    => false,
					'network' => false,
					'user'    => true,
				),
				'expectedBoxes'    => array(
					'dashboard_primary' => array( 'context' => 'side', 'priority' => 'core' ),
				),
				'unexpectedBoxes'  => array( 'dashboard_right_now', 'dashboard_activity', 'dashboard_quick_press', 'network_dashboard_right_now', 'dashboard_browser_nag', 'dashboard_php_nag', 'dashboard_site_health' ),
			),
		);

		try {
			foreach ( $cases as $label => $case ) {
				self::reset_runtime();
				$screen = self::dashboard_screen_for_hook( $case['hook'] );

				if ( ! isset( $GLOBALS['wp_registered_widgets'] ) || ! is_array( $GLOBALS['wp_registered_widgets'] ) ) {
					$GLOBALS['wp_registered_widgets'] = array();
				}
				if ( ! isset( $GLOBALS['wp_registered_widget_controls'] ) || ! is_array( $GLOBALS['wp_registered_widget_controls'] ) ) {
					$GLOBALS['wp_registered_widget_controls'] = array();
				}

				$filter_widget_id   = self::id( $ctx->fork( 'filtered-' . $label ), 'cfz_dashboard_filtered' );
				$filter_widget_name = 'Filtered ' . $label . ' ' . self::hostile_text( $ctx->fork( 'filtered-name-' . $label ) );
				$widget_callback    = static function (): void {
					echo '<span class="cfz-dashboard-filtered-widget"></span>';
				};
				$control_callback   = static function (): void {
					echo '<span class="cfz-dashboard-filtered-control"></span>';
				};
				$php_transient      = 'php_check_' . md5( PHP_VERSION );
				$transients[]       = $php_transient;

				$GLOBALS['wp_registered_widgets'][ $filter_widget_id ]         = array(
					'name'     => $filter_widget_name,
					'callback' => $widget_callback,
				);
				$GLOBALS['wp_registered_widget_controls'][ $filter_widget_id ] = array(
					'callback' => $control_callback,
				);

				$active_filter        = $case['expectedFilter'];
				$active_filter_widget = $filter_widget_id;
				$_SERVER['HTTP_USER_AGENT'] = '';
				$_SERVER['REQUEST_METHOD']  = 'GET';
				$_POST['widget_id']         = 'cfz_post_branch_probe';
				$_REQUEST['widget_id']      = 'cfz_post_branch_probe';
				\set_site_transient( $php_transient, array( 'is_acceptable' => true ), WEEK_IN_SECONDS );

				$actions_before  = $actions;
				$filters_before  = array_map( 'count', $filters );
				$meta_before     = count( $meta_box_actions );
				$redirect_before = count( $redirects );
				$http_before     = count( $http_requests );

				self::with_capabilities(
					$case['capabilities'],
					static function (): void {
						\wp_dashboard_setup();
					}
				);

				$action_deltas = array();
				foreach ( $actions as $action => $count ) {
					$action_deltas[ $action ] = $count - $actions_before[ $action ];
				}

				$filter_deltas = array();
				foreach ( $filters as $filter => $records ) {
					$filter_deltas[ $filter ] = count( $records ) - $filters_before[ $filter ];
				}

				$expected_action_only = true;
				foreach ( $action_deltas as $action => $delta ) {
					$expected_action_only = $expected_action_only
						&& ( $case['expectedAction'] === $action ? 1 === $delta : 0 === $delta );
				}

				$expected_filter_only = true;
				foreach ( $filter_deltas as $filter => $delta ) {
					$expected_filter_only = $expected_filter_only
						&& ( $case['expectedFilter'] === $filter ? 1 === $delta : 0 === $delta );
				}

				$expected_filter_records = array_slice( $filters[ $case['expectedFilter'] ], $filters_before[ $case['expectedFilter'] ] );
				$expected_filter_input   = $expected_filter_records[0]['input'] ?? null;
				$expected_filter_return  = $expected_filter_records[0]['returned'] ?? null;
				$screen_expectation      = $case['expectedScreen'];
				$screen_matches          = $screen_expectation['id'] === $screen->id
					&& $screen_expectation['site'] === $screen->in_admin( 'site' )
					&& $screen_expectation['network'] === $screen->in_admin( 'network' )
					&& $screen_expectation['user'] === $screen->in_admin( 'user' );

				$expected_box_details = array();
				$expected_boxes_ok    = true;
				foreach ( $case['expectedBoxes'] as $box_id => $expected ) {
					$box = self::find_meta_box( $screen->id, $box_id );
					$expected_box_details[ $box_id ] = is_array( $box )
						? array(
							'context'  => $box['context'],
							'priority' => $box['priority'],
						)
						: null;
					$expected_boxes_ok = $expected_boxes_ok
						&& is_array( $box )
						&& $expected['context'] === $box['context']
						&& $expected['priority'] === $box['priority'];
				}

				$unexpected_present = array();
				foreach ( $case['unexpectedBoxes'] as $box_id ) {
					$box = self::find_meta_box( $screen->id, $box_id );
					if ( is_array( $box ) ) {
						$unexpected_present[ $box_id ] = array(
							'context'  => $box['context'],
							'priority' => $box['priority'],
						);
					}
				}

				$filtered_box = self::find_meta_box( $screen->id, $filter_widget_id );
				$filtered_box_summary = is_array( $filtered_box )
					? array(
						'context'  => $filtered_box['context'],
						'priority' => $filtered_box['priority'],
						'title'    => self::preview( (string) ( $filtered_box['box']['title'] ?? '' ) ),
					)
					: null;
				$filtered_box_ok = is_array( $filtered_box )
					&& 'normal' === $filtered_box['context']
					&& 'core' === $filtered_box['priority']
					&& $widget_callback === ( $filtered_box['box']['callback'] ?? null )
					&& $filter_widget_name === ( $filtered_box['box']['args']['__widget_basename'] ?? null )
					&& isset( $GLOBALS['wp_dashboard_control_callbacks'][ $filter_widget_id ] )
					&& $control_callback === $GLOBALS['wp_dashboard_control_callbacks'][ $filter_widget_id ]
					&& str_contains( (string) ( $filtered_box['box']['title'] ?? '' ), 'class="edit-box open-box"' );
				$new_meta_box_actions = array_slice( $meta_box_actions, $meta_before );
				$meta_boxes_ok        = array(
					array(
						'screen'  => $screen->id,
						'context' => 'normal',
						'object'  => '',
					),
					array(
						'screen'  => $screen->id,
						'context' => 'side',
						'object'  => '',
					),
				) === $new_meta_box_actions;
				$no_dispatch_or_http  = $redirect_before === count( $redirects )
					&& $http_before === count( $http_requests );

				$case_summaries[ $label ] = array(
					'screen'          => self::describe_screen( $screen ),
					'actionDeltas'    => $action_deltas,
					'filterDeltas'    => $filter_deltas,
					'expectedBoxes'   => $expected_box_details,
					'unexpectedBoxes' => $unexpected_present,
					'filteredBox'     => $filtered_box_summary,
					'metaBoxActions'  => $new_meta_box_actions,
				);

				self::collect_failure(
					$failures,
					$screen_matches
						&& $expected_action_only
						&& $expected_filter_only
						&& array() === $expected_filter_input
						&& array( $filter_widget_id ) === $expected_filter_return,
					'wp_dashboard_setup() selects the matching site, network, or user dashboard action and widget filter',
					array(
						'label'              => $label,
						'screen'             => self::describe_screen( $screen ),
						'actionDeltas'       => $action_deltas,
						'filterDeltas'       => $filter_deltas,
						'expectedFilterInput' => $expected_filter_input,
						'expectedReturn'      => $expected_filter_return,
					)
				);

				self::collect_failure(
					$failures,
					$expected_boxes_ok
						&& array() === $unexpected_present
						&& $filtered_box_ok,
					'wp_dashboard_setup() registers only the core widgets for the current admin mode and re-adds filtered registered widgets with controls',
					array(
						'label'             => $label,
						'expectedBoxes'     => $expected_box_details,
						'unexpectedPresent' => $unexpected_present,
						'filteredBox'       => $filtered_box_summary,
						'controlRegistered' => isset( $GLOBALS['wp_dashboard_control_callbacks'][ $filter_widget_id ] ),
					)
				);

				self::collect_failure(
					$failures,
					$meta_boxes_ok
						&& $no_dispatch_or_http,
					'wp_dashboard_setup() GET registration triggers normal and side meta-box hooks without redirects, exits, or live HTTP',
					array(
						'label'          => $label,
						'metaBoxActions' => $new_meta_box_actions,
						'redirects'      => array_slice( $redirects, $redirect_before ),
						'httpRequests'   => array_slice( $http_requests, $http_before ),
					)
				);
			}
		} finally {
			$active_filter        = null;
			$active_filter_widget = null;
			foreach ( $action_callbacks as $action => $callback ) {
				\remove_action( $action, $callback );
			}
			foreach ( $filter_callbacks as $filter => $callback ) {
				\remove_filter( $filter, $callback, 10 );
			}
			\remove_action( 'do_meta_boxes', $meta_box_callback, 10 );
			\remove_filter( 'wp_redirect', $redirect_filter, 10 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			foreach ( array_unique( $transients ) as $transient ) {
				\delete_site_transient( $transient );
			}
			self::restore_globals( $registered_snapshot );
		}

		$hooks_removed = false === \has_filter( 'do_meta_boxes', $meta_box_callback )
			&& false === \has_filter( 'wp_redirect', $redirect_filter )
			&& false === \has_filter( 'pre_http_request', $http_filter );
		foreach ( $action_callbacks as $action => $callback ) {
			$hooks_removed = $hooks_removed && false === \has_filter( $action, $callback );
		}
		foreach ( $filter_callbacks as $filter => $callback ) {
			$hooks_removed = $hooks_removed && false === \has_filter( $filter, $callback );
		}

		self::collect_failure(
			$failures,
			$hooks_removed
				&& self::globals_match( $registered_snapshot ),
			'dashboard setup hook counters and temporary registered widgets are cleaned after direct setup checks',
			array(
				'hooksRemoved'          => $hooks_removed,
				'registeredGlobalsDiff' => self::changed_globals( $registered_snapshot ),
			)
		);

		return self::result(
			$ctx,
			'admin-dashboard.setup.direct-registration-hooks',
			$failures,
			array(
				'cases'                => $case_summaries,
				'requestMethodCovered' => 'GET',
				'remoteChecks'         => 'seeded-transients-and-empty-browser-user-agent',
			)
		);
	}

	private static function missing_browser_nag_requirements(): array {
		$missing = array();

		foreach (
			array(
				'__',
				'add_filter',
				'add_query_arg',
				'apply_filters',
				'dashboard_browser_nag_class',
				'delete_site_transient',
				'esc_attr',
				'esc_attr__',
				'esc_html',
				'esc_url',
				'get_site_option',
				'get_site_transient',
				'get_user_locale',
				'has_filter',
				'home_url',
				'is_ssl',
				'is_wp_error',
				'remove_filter',
				'set_site_transient',
				'set_url_scheme',
				'wp_check_browser_version',
				'wp_dashboard_browser_nag',
				'wp_get_wp_version',
				'wp_http_supports',
				'wp_json_encode',
				'wp_remote_post',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Error' ) ) {
			$missing[] = 'class WP_Error';
		}
		if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
			$missing[] = 'constant WEEK_IN_SECONDS';
		}

		return $missing;
	}

	private static function browser_user_agent( \ComponentFuzz\FuzzContext $ctx, string $browser ): string {
		$token = self::slug( $ctx->fork( 'token' ), 'ua' );

		return sprintf(
			'Mozilla/5.0 (ComponentFuzz %1$s; %2$s) AppleWebKit/537.36 %2$s/%3$d.0.%4$d.0 Safari/537.36',
			$token,
			$browser,
			$ctx->int( 1, 99 ),
			$ctx->int( 1000, 9999 )
		);
	}

	private static function browser_response_fixture( \ComponentFuzz\FuzzContext $ctx, bool $upgrade, bool $insecure ): array {
		$token = self::slug( $ctx->fork( 'token' ), 'browser' );

		return array(
			'platform'        => 'FuzzOS ' . $token,
			'name'            => 'Browser ' . $token . ' <script>alert(1)</script> & "quoted"',
			'version'         => '1.' . $ctx->int( 0, 9 ) . '.' . $ctx->int( 0, 99 ),
			'current_version' => '120.' . $ctx->int( 0, 9 ) . '.' . $ctx->int( 0, 99 ),
			'upgrade'         => $upgrade,
			'insecure'        => $insecure,
			'update_url'      => 'https://updates.example.test/' . rawurlencode( $token ) . '/download?channel=stable&name="<tag>"',
			'img_src'         => 'http://images.example.test/' . rawurlencode( $token ) . '.png?label="<img>"',
			'img_src_ssl'     => 'https://images.example.test/' . rawurlencode( $token ) . '.png?label="<img>"',
		);
	}

	private static function browser_failure_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'wp-error'     => array(
				'userAgent' => self::browser_user_agent( $ctx->fork( 'ua-error' ), 'ErrorBrowser' ),
				'failure'   => array(
					'type' => 'wp-error',
				),
			),
			'non-200'      => array(
				'userAgent' => self::browser_user_agent( $ctx->fork( 'ua-non-200' ), 'StatusBrowser' ),
				'failure'   => array(
					'body'    => \wp_json_encode( self::browser_response_fixture( $ctx->fork( 'response-non-200' ), true, true ) ),
					'code'    => 503,
					'message' => 'Service Unavailable',
				),
			),
			'invalid-json' => array(
				'userAgent' => self::browser_user_agent( $ctx->fork( 'ua-invalid-json' ), 'JsonBrowser' ),
				'failure'   => array(
					'body'    => '{"not-valid-json":',
					'code'    => 200,
					'message' => 'OK',
				),
			),
		);
	}

	private static function browser_happy_endpoint(): string {
		$url = 'http://api.wordpress.org/core/browse-happy/1.1/';

		if ( \wp_http_supports( array( 'ssl' ) ) ) {
			$url = \set_url_scheme( $url, 'https' );
		}

		return $url;
	}

	private static function options_snapshot(): ?array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return null;
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
		return self::dashboard_screen_for_hook( 'index.php' );
	}

	private static function dashboard_screen_for_hook( string $hook ): \WP_Screen {
		\set_current_screen( $hook );
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

	private static function seed_right_now_posts( \ComponentFuzz\FuzzContext $ctx, string $post_type, int $count, string $label ): array {
		$ids = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$date = new \DateTimeImmutable( '2026-07-01 12:00:00', new \DateTimeZone( 'UTC' ) );
			$date = $date->modify( '+' . $i . ' minutes' );
			$ids[] = self::insert_post(
				array(
					'post_author'       => 1,
					'post_date'         => $date->format( 'Y-m-d H:i:s' ),
					'post_date_gmt'     => $date->format( 'Y-m-d H:i:s' ),
					'post_modified'     => $date->format( 'Y-m-d H:i:s' ),
					'post_modified_gmt' => $date->format( 'Y-m-d H:i:s' ),
					'post_content'      => 'Right Now count fixture ' . $post_type . ' ' . $i,
					'post_title'        => $label . ' ' . $i . ' ' . self::hostile_text( $ctx->fork( 'title-' . $i ) ),
					'post_status'       => 'publish',
					'post_type'         => $post_type,
					'post_name'         => \sanitize_title( $label . '-' . $post_type . '-' . $i . '-' . self::slug( $ctx->fork( 'slug-' . $i ), 'right-now' ) ),
				)
			);
		}

		return $ids;
	}

	private static function recent_post_fixture( \ComponentFuzz\FuzzContext $ctx, int $user_id, string $status, \DateTimeInterface $date, string $label ): \WP_Post {
		$title = $label . ' <script>alert(1)</script> ' . self::hostile_text( $ctx->fork( 'title' ) );
		$id    = self::insert_post(
			array(
				'post_author'       => $user_id,
				'post_date'         => $date->format( 'Y-m-d H:i:s' ),
				'post_date_gmt'     => $date->format( 'Y-m-d H:i:s' ),
				'post_modified'     => $date->format( 'Y-m-d H:i:s' ),
				'post_modified_gmt' => $date->format( 'Y-m-d H:i:s' ),
				'post_content'      => 'Activity body <img src=x onerror=alert(1)> ' . self::hostile_text( $ctx->fork( 'content' ) ),
				'post_title'        => $title,
				'post_status'       => $status,
				'post_type'         => 'post',
				'post_name'         => \sanitize_title( $label . '-' . self::slug( $ctx->fork( 'slug' ), 'activity' ) ),
			)
		);
		$post  = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( 'Could not load recent post fixture.' );
		}

		return $post;
	}

	private static function post_ids( array $posts ): array {
		return array_map(
			static function ( \WP_Post $post ): int {
				return (int) $post->ID;
			},
			$posts
		);
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

	private static function capture_output( callable $callback ): string {
		$level = ob_get_level();
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			throw $e;
		}
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
