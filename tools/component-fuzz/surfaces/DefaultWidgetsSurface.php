<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes classic default WP_Widget subclasses without live DB or network IO.
 */
final class DefaultWidgetsSurface {
	public const NAME = 'default-widgets';

	private const PREVIEW_BYTES = 220;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'default-widgets.bootstrap-apis-available',
					'Required default widget APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_constructor_contracts( $ctx->fork( 'constructors' ) );
			$rows[] = self::check_update_sanitization( $ctx->fork( 'updates' ) );
			$rows[] = self::check_widget_callback_lifecycle( $ctx->fork( 'callbacks' ) );
			$rows[] = self::check_form_escaping( $ctx->fork( 'forms' ) );
			$rows[] = self::check_text_and_html_rendering( $ctx->fork( 'text-html' ) );
			$rows[] = self::check_simple_widget_rendering( $ctx->fork( 'simple-render' ) );
			$rows[] = self::check_nav_menu_widget_rendering( $ctx->fork( 'nav-menu-render' ) );
			$rows[] = self::check_list_widget_rendering( $ctx->fork( 'list-render' ) );
			$rows[] = self::check_rss_widget_rendering( $ctx->fork( 'rss-render' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'default-widgets.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'default-widgets.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'optionsTracked' => count( $snapshot['options'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
				'WP_Widget_Archives',
				'WP_Widget_Calendar',
				'WP_Widget_Categories',
				'WP_Widget_Custom_HTML',
				'WP_Widget_Links',
				'WP_Widget_Meta',
				'WP_Widget_Pages',
				'WP_Widget_Recent_Comments',
				'WP_Widget_Recent_Posts',
				'WP_Widget_RSS',
				'WP_Widget_Search',
				'WP_Widget_Tag_Cloud',
				'WP_Widget_Text',
				'WP_Nav_Menu_Widget',
				'WP_Post',
				'WP_Query',
				'WP_Rewrite',
				'WP_Term',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'in-memory wpdb stub';
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_theme_support',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_theme_supports',
				'current_user_can',
				'delete_option',
				'fetch_feed',
				'get_calendar',
				'get_option',
				'has_filter',
				'get_search_form',
				'remove_action',
				'remove_filter',
				'remove_theme_support',
				'sanitize_text_field',
				'update_option',
				'wp_cache_flush',
				'wp_cache_get_last_changed',
				'wp_cache_set',
				'wp_cache_set_salted',
				'wp_get_archives',
				'wp_list_categories',
				'wp_list_pages',
				'wp_nav_menu',
				'wp_recursive_ksort',
				'wp_set_current_user',
				'wp_tag_cloud',
				'wp_widget_rss_form',
				'wp_widget_rss_output',
				'wp_widget_rss_process',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_constructor_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$expected = array(
			'WP_Widget_Pages'           => array( 'idBase' => 'pages', 'className' => 'widget_pages', 'rest' => true ),
			'WP_Widget_Links'           => array( 'idBase' => 'links', 'className' => 'widget_links', 'rest' => false ),
			'WP_Widget_Search'          => array( 'idBase' => 'search', 'className' => 'widget_search', 'rest' => true ),
			'WP_Widget_Archives'        => array( 'idBase' => 'archives', 'className' => 'widget_archive', 'rest' => true ),
			'WP_Widget_Meta'            => array( 'idBase' => 'meta', 'className' => 'widget_meta', 'rest' => true ),
			'WP_Widget_Calendar'        => array( 'idBase' => 'calendar', 'className' => 'widget_calendar', 'rest' => true ),
			'WP_Widget_Text'            => array( 'idBase' => 'text', 'className' => 'widget_text', 'rest' => true ),
			'WP_Widget_Categories'      => array( 'idBase' => 'categories', 'className' => 'widget_categories', 'rest' => true ),
			'WP_Widget_Recent_Posts'    => array( 'idBase' => 'recent-posts', 'className' => 'widget_recent_entries', 'rest' => true ),
			'WP_Widget_Recent_Comments' => array( 'idBase' => 'recent-comments', 'className' => 'widget_recent_comments', 'rest' => true ),
			'WP_Widget_RSS'             => array( 'idBase' => 'rss', 'className' => 'widget_rss', 'rest' => true ),
			'WP_Widget_Tag_Cloud'       => array( 'idBase' => 'tag_cloud', 'className' => 'widget_tag_cloud', 'rest' => true ),
			'WP_Nav_Menu_Widget'        => array( 'idBase' => 'nav_menu', 'className' => 'widget_nav_menu', 'rest' => true ),
			'WP_Widget_Custom_HTML'     => array( 'idBase' => 'custom_html', 'className' => 'widget_custom_html', 'rest' => true ),
		);

		$seen = array();
		foreach ( $expected as $class => $contract ) {
			$widget = self::new_widget( $class, $ctx );
			$seen[ $class ] = array(
				'idBase'    => $widget->id_base,
				'name'      => $widget->name,
				'className' => $widget->widget_options['classname'] ?? '',
				'rest'      => ! empty( $widget->widget_options['show_instance_in_rest'] ),
				'refresh'   => ! empty( $widget->widget_options['customize_selective_refresh'] ),
			);

			self::collect_failure(
				$failures,
				$contract['idBase'] === $seen[ $class ]['idBase']
					&& $contract['className'] === $seen[ $class ]['className']
					&& $contract['rest'] === $seen[ $class ]['rest'],
				"{$class} constructor exposes expected widget identity",
				array(
					'expected' => $contract,
					'actual'   => $seen[ $class ],
				)
			);
		}

		return self::result(
			$ctx,
			'default-widgets.constructors.identity-and-rest-flags',
			$failures,
			array( 'seen' => $seen )
		);
	}

	private static function check_update_sanitization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$title    = '<b>Title</b><script>alert(1)</script> ' . $ctx->identifier( 3, 8 );
		$html     = '<p>Allowed ' . $ctx->identifier( 3, 8 ) . '</p><script>alert(1)</script>';

		\wp_set_current_user( 0 );

		$search = ( new \WP_Widget_Search() )->update( array( 'title' => $title ), array() );
		$meta   = ( new \WP_Widget_Meta() )->update( array( 'title' => $title ), array() );
		$cal    = ( new \WP_Widget_Calendar() )->update( array( 'title' => $title ), array() );
		self::collect_failure(
			$failures,
			self::safe_title( $search['title'] ?? '' )
				&& self::safe_title( $meta['title'] ?? '' )
				&& self::safe_title( $cal['title'] ?? '' ),
			'Single-title widgets sanitize title fields on update',
			array(
				'search'   => $search,
				'meta'     => $meta,
				'calendar' => $cal,
			)
		);

		$pages = ( new \WP_Widget_Pages() )->update(
			array(
				'title'   => $title,
				'sortby'  => 'post_date',
				'exclude' => '<em>12, 19</em>',
			),
			array()
		);
		self::collect_failure(
			$failures,
			self::safe_title( $pages['title'] ?? '' )
				&& 'menu_order' === ( $pages['sortby'] ?? null )
				&& '12, 19' === ( $pages['exclude'] ?? null ),
			'Pages update rejects unsupported sort keys and sanitizes title/exclude',
			array( 'pages' => $pages )
		);

		$categories = ( new \WP_Widget_Categories() )->update(
			array(
				'title'        => $title,
				'count'        => 'yes',
				'hierarchical' => 'on',
				'dropdown'     => '1',
			),
			array()
		);
		$archives   = ( new \WP_Widget_Archives() )->update(
			array(
				'title'    => $title,
				'count'    => 1,
				'dropdown' => 'on',
			),
			array()
		);
		self::collect_failure(
			$failures,
			1 === ( $categories['count'] ?? null )
				&& 1 === ( $categories['hierarchical'] ?? null )
				&& 1 === ( $categories['dropdown'] ?? null )
				&& 1 === ( $archives['count'] ?? null )
				&& 1 === ( $archives['dropdown'] ?? null ),
			'Categories and Archives updates normalize checkbox fields',
			array(
				'categories' => $categories,
				'archives'   => $archives,
			)
		);

		$recent_posts    = ( new \WP_Widget_Recent_Posts() )->update(
			array(
				'title'     => $title,
				'number'    => '-7',
				'show_date' => '1',
			),
			array()
		);
		$recent_comments = ( new \WP_Widget_Recent_Comments() )->update(
			array(
				'title'  => $title,
				'number' => '-7',
			),
			array()
		);
		self::collect_failure(
			$failures,
			-7 === ( $recent_posts['number'] ?? null )
				&& true === ( $recent_posts['show_date'] ?? null )
				&& 7 === ( $recent_comments['number'] ?? null ),
			'Recent Posts preserves integer number while Recent Comments absints it',
			array(
				'recentPosts'    => $recent_posts,
				'recentComments' => $recent_comments,
			)
		);

		$text = ( new \WP_Widget_Text() )->update(
			array(
				'title'  => $title,
				'text'   => $html,
				'filter' => 'content',
				'visual' => '1',
			),
			array()
		);
		$html_widget = ( new \WP_Widget_Custom_HTML() )->update(
			array(
				'title'   => $title,
				'content' => $html,
			),
			array()
		);
		self::collect_failure(
			$failures,
			! str_contains( $text['text'] ?? '', '<script' )
				&& str_contains( $text['text'] ?? '', '<p>' )
				&& true === ( $text['filter'] ?? null )
				&& true === ( $text['visual'] ?? null )
				&& ! str_contains( $html_widget['content'] ?? '', '<script' )
				&& str_contains( $html_widget['content'] ?? '', '<p>' ),
			'Text and Custom HTML updates apply capability-sensitive KSES and visual flags',
			array(
				'text'       => $text,
				'customHtml' => $html_widget,
			)
		);

		$rss_url = 'https://example.test/feed/' . $ctx->identifier( 3, 8 );
		$rss     = ( new \WP_Widget_RSS() )->update(
			array(
				'title'        => $title,
				'url'          => $rss_url,
				'items'        => '99',
				'show_summary' => '1',
				'show_author'  => '1',
				'show_date'    => '1',
			),
			array( 'url' => $rss_url )
		);
		self::collect_failure(
			$failures,
			self::safe_title( $rss['title'] ?? '' )
				&& $rss_url === ( $rss['url'] ?? null )
				&& 10 === ( $rss['items'] ?? null )
				&& false === ( $rss['error'] ?? null )
				&& 1 === ( $rss['show_summary'] ?? null ),
			'RSS update sanitizes title/url and bounds item count without feed check when URL is unchanged',
			array( 'rss' => $rss )
		);

		$tag_cloud = ( new \WP_Widget_Tag_Cloud() )->update(
			array(
				'title'    => $title,
				'count'    => 'on',
				'taxonomy' => 'category\\',
			),
			array()
		);
		$links     = ( new \WP_Widget_Links() )->update(
			array(
				'images'      => 'on',
				'name'        => 'on',
				'description' => 'on',
				'orderby'     => 'post_date',
				'category'    => '12dogs',
				'limit'       => '',
			),
			array()
		);
		$nav_menu  = ( new \WP_Nav_Menu_Widget() )->update(
			array(
				'title'    => $title,
				'nav_menu' => '23cats',
			),
			array()
		);
		self::collect_failure(
			$failures,
			1 === ( $tag_cloud['count'] ?? null )
				&& 'category' === ( $tag_cloud['taxonomy'] ?? null )
				&& 'name' === ( $links['orderby'] ?? null )
				&& 12 === ( $links['category'] ?? null )
				&& -1 === ( $links['limit'] ?? null )
				&& 23 === ( $nav_menu['nav_menu'] ?? null )
				&& self::safe_title( $nav_menu['title'] ?? '' ),
			'Tag Cloud, Links, and Nav Menu updates normalize taxonomy, booleans, and numeric selections',
			array(
				'tagCloud' => $tag_cloud,
				'links'    => $links,
				'navMenu'  => $nav_menu,
			)
		);

		return self::result( $ctx, 'default-widgets.update.sanitization-and-normalization', $failures );
	}

	private static function check_widget_callback_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$number          = $ctx->int( 60, 95 );
		$blocked_number  = $number + 100;
		$token           = $ctx->identifier( 4, 9 );
		$widget          = new \WP_Widget_Text();
		$display_seen    = array();
		$form_seen       = array();
		$update_seen     = array();
		$original_post   = $_POST;
		$saved_instances = array(
			$number         => array(
				'title'  => 'Saved ' . $token,
				'text'   => '<p>Saved body ' . $token . '</p>',
				'filter' => true,
				'visual' => true,
			),
			$blocked_number => array(
				'title'  => 'Blocked ' . $token,
				'text'   => '<p>Blocked body ' . $token . '</p>',
				'filter' => true,
				'visual' => true,
			),
		);

		$display_filter = static function ( $instance, \WP_Widget $current_widget, array $args ) use ( &$display_seen, $token, $blocked_number ) {
			$display_seen[] = array(
				'idBase'   => $current_widget->id_base,
				'number'   => $current_widget->number,
				'title'    => $instance['title'] ?? null,
				'widgetId' => $args['widget_id'] ?? null,
			);

			if ( $blocked_number === (int) $current_widget->number ) {
				return false;
			}

			$instance['title'] .= ' filtered';
			$instance['text']  .= '<span data-display-token="' . \esc_attr( $token ) . '"></span>';
			return $instance;
		};
		$form_filter    = static function ( $instance, \WP_Widget $current_widget ) use ( &$form_seen, $token ) {
			$form_seen[] = array(
				'idBase' => $current_widget->id_base,
				'number' => $current_widget->number,
				'title'  => $instance['title'] ?? null,
			);

			$instance['title'] = ( $instance['title'] ?? '' ) . ' form-filtered';
			$instance['text']  = ( $instance['text'] ?? '' ) . "\nForm " . $token;
			return $instance;
		};
		$form_action    = static function ( \WP_Widget $current_widget, &$return, array $instance ) use ( &$form_seen ): void {
			$form_seen[] = array(
				'action' => 'in_widget_form',
				'idBase' => $current_widget->id_base,
				'number' => $current_widget->number,
				'return' => $return,
				'title'  => $instance['title'] ?? null,
			);
		};
		$update_filter  = static function ( $instance, array $new_instance, array $old_instance, \WP_Widget $current_widget ) use ( &$update_seen ) {
			$update_seen[] = array(
				'idBase' => $current_widget->id_base,
				'number' => $current_widget->number,
				'new'    => $new_instance,
				'old'    => $old_instance,
				'saved'  => $instance,
			);

			if ( is_array( $instance ) ) {
				$instance['title'] .= ' update-filtered';
			}

			return $instance;
		};

		$widget->save_settings( $saved_instances );
		$widget->_set( $number );
		$display_args = self::widget_args( $widget );
		$widget->_set( $number + 1000 );

		\add_filter( 'widget_display_callback', $display_filter, 10, 3 );
		\add_filter( 'widget_form_callback', $form_filter, 10, 2 );
		\add_action( 'in_widget_form', $form_action, 10, 3 );
		\add_filter( 'widget_update_callback', $update_filter, 10, 4 );
		try {
			$display_output = self::capture_callback_output(
				static function () use ( $widget, $display_args, $number ): void {
					$widget->display_callback( $display_args, array( 'number' => $number ) );
				}
			);

			$widget->_set( $blocked_number );
			$blocked_args    = self::widget_args( $widget );
			$widget->_set( $blocked_number + 1000 );
			$blocked_output  = self::capture_callback_output(
				static function () use ( $widget, $blocked_args, $blocked_number ): void {
					$widget->display_callback( $blocked_args, $blocked_number );
				}
			);
			$form_output     = self::capture_callback_output(
				static function () use ( $widget, $number ): void {
					$widget->form_callback( array( 'number' => $number ) );
				}
			);
			$template_output = self::capture_callback_output(
				static function () use ( $widget ): void {
					$widget->form_callback( array( 'number' => -1 ) );
				}
			);

			$_POST = array(
				'widget-text' => array(
					$number => array(
						'title'  => '<b>Updated ' . $token . '</b><script>bad</script>',
						'text'   => '<p>Updated ' . $token . '</p><script>bad</script>',
						'filter' => 'content',
						'visual' => '1',
					),
				),
			);
			$widget->update_callback();
			$settings_after_update = $widget->get_settings();
		} finally {
			$_POST = $original_post;
			\remove_filter( 'widget_update_callback', $update_filter, 10 );
			\remove_action( 'in_widget_form', $form_action, 10 );
			\remove_filter( 'widget_form_callback', $form_filter, 10 );
			\remove_filter( 'widget_display_callback', $display_filter, 10 );
		}

		$updated = $settings_after_update[ $number ] ?? array();

		self::collect_failure(
			$failures,
			str_contains( $display_output, '<section id="text-' . $number . '" class="widget widget_text">' )
				&& str_contains( $display_output, 'Saved ' . $token . ' filtered' )
				&& str_contains( $display_output, 'data-display-token="' . \esc_attr( $token ) . '"' )
				&& '' === $blocked_output
				&& 2 === count( $display_seen )
				&& $number === (int) ( $display_seen[0]['number'] ?? 0 )
				&& $blocked_number === (int) ( $display_seen[1]['number'] ?? 0 ),
			'display_callback loads saved instances, applies display filter mutations, and honors false short-circuit',
			array(
				'display' => self::preview( $display_output ),
				'blocked' => self::preview( $blocked_output ),
				'seen'    => $display_seen,
			)
		);
		self::collect_failure(
			$failures,
			str_contains( $form_output, 'Saved ' . $token . ' form-filtered' )
				&& str_contains( $form_output, 'Form ' . $token )
				&& str_contains( $template_output, 'widget-text[__i__]' )
				&& isset( $form_seen[0], $form_seen[1], $form_seen[2], $form_seen[3] )
				&& $number === (int) ( $form_seen[0]['number'] ?? 0 )
				&& 'in_widget_form' === ( $form_seen[1]['action'] ?? null )
				&& '__i__' === (string) ( $form_seen[2]['number'] ?? '' )
				&& 'in_widget_form' === ( $form_seen[3]['action'] ?? null ),
			'form_callback filters saved and template instances and fires in_widget_form action',
			array(
				'form'     => self::preview( $form_output ),
				'template' => self::preview( $template_output ),
				'seen'     => $form_seen,
			)
		);
		self::collect_failure(
			$failures,
			1 === count( $update_seen )
				&& $number === (int) ( $update_seen[0]['number'] ?? 0 )
				&& 'Saved ' . $token === ( $update_seen[0]['old']['title'] ?? null )
				&& str_contains( $updated['title'] ?? '', 'Updated ' . $token )
				&& str_contains( $updated['title'] ?? '', 'update-filtered' )
				&& self::safe_title( $updated['title'] ?? '' )
				&& str_contains( $updated['text'] ?? '', '<p>Updated ' . $token . '</p>' )
				&& ! str_contains( $updated['text'] ?? '', '<script' )
				&& true === ( $updated['filter'] ?? null )
				&& true === ( $updated['visual'] ?? null )
				&& ( $settings_after_update[ $blocked_number ] ?? null ) === $saved_instances[ $blocked_number ],
			'update_callback sanitizes one posted instance, runs update filter, and preserves sibling instances',
			array(
				'updated'  => $updated,
				'sibling'  => $settings_after_update[ $blocked_number ] ?? null,
				'settings' => $settings_after_update,
				'seen'     => $update_seen,
			)
		);

		return self::result(
			$ctx,
			'default-widgets.callbacks.display-form-update-lifecycle',
			$failures,
			array( 'number' => $number )
		);
	}

	private static function check_form_escaping( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$title    = 'Form "Title" <script>' . $ctx->identifier( 3, 8 ) . '</script>';
		$content  = '</textarea><script>alert(1)</script><p>' . $ctx->identifier( 3, 8 ) . '</p>';

		$forms = array(
			'search'          => self::capture_form( new \WP_Widget_Search(), 2, array( 'title' => $title ) ),
			'pages'           => self::capture_form( new \WP_Widget_Pages(), 3, array( 'title' => $title, 'sortby' => 'ID', 'exclude' => '2, 4' ) ),
			'categories'      => self::capture_form( new \WP_Widget_Categories(), 4, array( 'title' => $title, 'dropdown' => 1, 'count' => 1 ) ),
			'archives'        => self::capture_form( new \WP_Widget_Archives(), 5, array( 'title' => $title, 'dropdown' => 1, 'count' => 1 ) ),
			'recentPosts'     => self::capture_form( new \WP_Widget_Recent_Posts(), 6, array( 'title' => $title, 'number' => 3, 'show_date' => true ) ),
			'recentComments'  => self::capture_form( new \WP_Widget_Recent_Comments(), 7, array( 'title' => $title, 'number' => 3 ) ),
			'rss'             => self::capture_form( new \WP_Widget_RSS(), 8, array( 'title' => $title, 'url' => 'https://example.test/feed/', 'items' => 3 ) ),
			'tagCloud'        => self::capture_form( new \WP_Widget_Tag_Cloud(), 9, array( 'title' => $title, 'taxonomy' => 'post_tag', 'count' => 1 ) ),
			'text'            => self::capture_form( new \WP_Widget_Text(), 10, array( 'title' => $title, 'text' => $content, 'filter' => false, 'visual' => false ) ),
			'customHtml'      => self::capture_form( new \WP_Widget_Custom_HTML(), 11, array( 'title' => $title, 'content' => $content ) ),
			'navigationMenu'  => self::capture_form( new \WP_Nav_Menu_Widget(), 12, array( 'title' => $title, 'nav_menu' => 0 ) ),
			'links'           => self::capture_form( new \WP_Widget_Links(), 13, array( 'orderby' => 'rating', 'limit' => 4 ) ),
		);

		foreach ( $forms as $name => $form ) {
			self::collect_failure(
				$failures,
				! str_contains( $form, '<script>alert(1)</script>' )
					&& ! str_contains( $form, '</textarea><script>' )
					&& str_contains( $form, 'widget-' ),
				"{$name} form escapes fields and uses widget field names",
				array( 'form' => self::preview( $form ) )
			);
		}

		self::collect_failure(
			$failures,
			str_contains( $forms['text'], '&lt;/textarea' )
				&& str_contains( $forms['customHtml'], '&lt;/textarea&gt;' )
				&& str_contains( $forms['rss'], 'name="widget-rss[8][url]"' )
				&& str_contains( $forms['pages'], '<option value="ID" selected=' )
				&& str_contains( $forms['categories'], 'name="widget-categories[4][dropdown]"' ),
			'Representative forms preserve expected escaped controls and selected options',
			array(
				'text'       => self::preview( $forms['text'] ),
				'customHtml' => self::preview( $forms['customHtml'] ),
				'rss'        => self::preview( $forms['rss'] ),
				'pages'      => self::preview( $forms['pages'] ),
				'categories' => self::preview( $forms['categories'] ),
			)
		);

		return self::result( $ctx, 'default-widgets.forms.escaping-and-field-shape', $failures );
	}

	private static function check_text_and_html_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$seen     = array(
			'widgetText'       => array(),
			'widgetTextContent' => array(),
			'customHtmlContent' => array(),
			'titles'           => array(),
		);

		$title_filter = static function ( string $title, array $instance, string $id_base ) use ( &$seen ): string {
			$seen['titles'][] = array(
				'title'  => $title,
				'idBase' => $id_base,
			);
			return $title . ' <span data-title-filter="' . \esc_attr( $id_base ) . '">filtered</span>';
		};
		$text_filter  = static function ( string $text, array $instance, \WP_Widget $widget ) use ( &$seen ): string {
			$seen['widgetText'][] = array(
				'idBase' => $widget->id_base,
				'keys'   => array_keys( $instance ),
			);
			return $text . '<span data-widget-text="' . \esc_attr( $widget->id_base ) . '"></span>';
		};
		$content_filter = static function ( string $text, array $instance, \WP_Widget_Text $widget ) use ( &$seen ): string {
			$seen['widgetTextContent'][] = array(
				'idBase' => $widget->id_base,
				'visual' => $instance['visual'] ?? null,
			);
			return '<article data-visual="' . \esc_attr( $widget->id_base ) . '">' . $text . '</article>';
		};
		$html_filter    = static function ( string $content, array $instance, \WP_Widget_Custom_HTML $widget ) use ( &$seen ): string {
			$seen['customHtmlContent'][] = array(
				'idBase' => $widget->id_base,
				'title'  => $instance['title'] ?? null,
			);
			return $content . '<span data-custom-html="' . \esc_attr( $widget->id_base ) . '"></span>';
		};

		\add_filter( 'widget_title', $title_filter, 10, 3 );
		\add_filter( 'widget_text', $text_filter, 10, 3 );
		\add_filter( 'widget_text_content', $content_filter, 10, 3 );
		\add_filter( 'widget_custom_html_content', $html_filter, 10, 3 );
		try {
			$text_widget = new \WP_Widget_Text();
			$text_output = self::render_widget(
				$text_widget,
				21,
				array(
					'title'  => 'Text ' . $ctx->identifier( 3, 8 ),
					'text'   => '<iframe width="320" height="200" style="width: 320px" src="https://example.test/embed"></iframe>',
					'filter' => 'content',
					'visual' => true,
				)
			);

			$html_widget = new \WP_Widget_Custom_HTML();
			$html_output = self::render_widget(
				$html_widget,
				22,
				array(
					'title'   => 'HTML ' . $ctx->identifier( 3, 8 ),
					'content' => '<div class="allowed">Custom ' . \esc_html( $ctx->identifier( 3, 8 ) ) . '</div>',
				)
			);
		} finally {
			\remove_filter( 'widget_custom_html_content', $html_filter, 10 );
			\remove_filter( 'widget_text_content', $content_filter, 10 );
			\remove_filter( 'widget_text', $text_filter, 10 );
			\remove_filter( 'widget_title', $title_filter, 10 );
		}

		self::collect_failure(
			$failures,
			str_contains( $text_output, '<section id="text-21" class="widget widget_text">' )
				&& str_contains( $text_output, 'data-title-filter="text"' )
				&& str_contains( $text_output, 'data-widget-text="text"' )
				&& str_contains( $text_output, '<article data-visual="text">' )
				&& str_contains( $text_output, 'style="width:100%"' )
				&& ! str_contains( $text_output, 'width="320"' )
				&& ! str_contains( $text_output, 'height="200"' )
				&& 1 === count( $seen['widgetTextContent'] ),
			'Text widget renders filtered visual content, title, wrappers, and video width normalization',
			array(
				'output' => self::preview( $text_output ),
				'seen'   => $seen,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $html_output, '<section id="custom_html-22" class="widget_text widget widget_custom_html">' )
				&& str_contains( $html_output, 'data-title-filter="custom_html"' )
				&& str_contains( $html_output, '<div class="textwidget custom-html-widget">' )
				&& str_contains( $html_output, 'data-widget-text="custom_html"' )
				&& str_contains( $html_output, 'data-custom-html="custom_html"' )
				&& isset( $seen['customHtmlContent'][0] ),
			'Custom HTML widget simulates text-widget filters, injects compatibility class, and preserves wrapper shape',
			array(
				'output' => self::preview( $html_output ),
				'seen'   => $seen,
			)
		);

		return self::result( $ctx, 'default-widgets.render.text-and-custom-html-filter-pipeline', $failures );
	}

	private static function check_simple_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$seen     = array(
			'titles'        => array(),
			'navigationFmt' => array(),
			'calendarArgs'  => array(),
		);

		$title_filter = static function ( string $title, array $instance, string $id_base ) use ( &$seen ): string {
			$seen['titles'][] = array(
				'idBase' => $id_base,
				'title'  => $title,
			);
			return $title . ' <em data-title="' . \esc_attr( $id_base ) . '">ok</em>';
		};
		$format_filter = static function ( string $format ) use ( &$seen ): string {
			$seen['navigationFmt'][] = $format;
			return 'html5';
		};
		$powered_filter = static function ( string $html, array $instance ): string {
			unset( $html, $instance );
			return '<li class="cfz-powered"><a href="https://example.test/powered">Powered</a></li>';
		};
		$calendar_filter = static function ( string $output, array $args ) use ( &$seen ): string {
			$seen['calendarArgs'][] = $args;
			return $output . '<!-- component-fuzz-calendar -->';
		};

		\add_filter( 'widget_title', $title_filter, 10, 3 );
		\add_filter( 'navigation_widgets_format', $format_filter );
		\add_filter( 'widget_meta_poweredby', $powered_filter, 10, 2 );
		\add_filter( 'get_calendar', $calendar_filter, 10, 2 );
		try {
			self::seed_calendar_cache( '<table id="wp-calendar"><caption>June 2026</caption><tbody><tr><td>23</td></tr></tbody></table>' );

			$search_output   = self::render_widget( new \WP_Widget_Search(), 31, array( 'title' => 'Find ' . $ctx->identifier( 3, 8 ) ) );
			$meta_output     = self::render_widget( new \WP_Widget_Meta(), 32, array( 'title' => 'Meta ' . $ctx->identifier( 3, 8 ) ) );
			$calendar_output = self::render_widget( new \WP_Widget_Calendar(), 33, array( 'title' => 'Calendar ' . $ctx->identifier( 3, 8 ) ) );
		} finally {
			\remove_filter( 'get_calendar', $calendar_filter, 10 );
			\remove_filter( 'widget_meta_poweredby', $powered_filter, 10 );
			\remove_filter( 'navigation_widgets_format', $format_filter );
			\remove_filter( 'widget_title', $title_filter, 10 );
		}

		self::collect_failure(
			$failures,
			str_contains( $search_output, '<section id="search-31" class="widget widget_search">' )
				&& str_contains( $search_output, 'data-title="search"' )
				&& str_contains( $search_output, '<form role="search"' )
				&& str_contains( $search_output, 'name="s"' ),
			'Search widget renders title wrapper and default search form without theme template',
			array( 'output' => self::preview( $search_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $meta_output, '<section id="meta-32" class="widget widget_meta">' )
				&& str_contains( $meta_output, '<nav aria-label="Meta' )
				&& str_contains( $meta_output, 'class="cfz-powered"' )
				&& str_contains( $meta_output, 'Entries feed' )
				&& str_contains( $meta_output, 'Comments feed' ),
			'Meta widget renders html5 navigation wrapper, core links, and powered-by filter output',
			array( 'output' => self::preview( $meta_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $calendar_output, '<section id="calendar-33" class="widget widget_calendar">' )
				&& str_contains( $calendar_output, 'id="calendar_wrap"' )
				&& str_contains( $calendar_output, 'June 2026' )
				&& str_contains( $calendar_output, 'component-fuzz-calendar' )
				&& isset( $seen['calendarArgs'][0] ),
			'Calendar widget renders cached calendar output and preserves first-instance wrapper id',
			array(
				'output' => self::preview( $calendar_output ),
				'seen'   => $seen,
			)
		);

		return self::result( $ctx, 'default-widgets.render.search-meta-calendar-wrappers', $failures );
	}

	private static function check_nav_menu_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = $ctx->identifier( 4, 9 );
		$menu     = self::make_term( 850 + $ctx->int( 1, 99 ), 'Menu ' . $token, 'menu-' . strtolower( $token ), 'nav_menu', 2 );
		$fixture  = self::seed_nav_menu_fixture( $menu, $token );
		$cases    = array(
			array(
				'label'      => 'html5',
				'number'     => 36,
				'title'      => 'Primary ' . $token,
				'format'     => 'html5',
				'themeHtml5' => true,
			),
			array(
				'label'      => 'xhtml',
				'number'     => 37,
				'title'      => 'Legacy ' . $token,
				'format'     => 'xhtml',
				'themeHtml5' => false,
			),
		);
		$seen     = array(
			'titles'      => array(),
			'formats'     => array(),
			'args'        => array(),
			'itemLookups' => array(),
			'objects'     => array(),
			'htmlItems'   => array(),
			'menus'       => array(),
		);
		$outputs  = array();

		$current_case = null;

		$title_filter = static function ( string $title, array $instance, string $id_base ) use ( &$seen ): string {
			$seen['titles'][] = array(
				'idBase'     => $id_base,
				'title'      => $title,
				'navMenuSet' => isset( $instance['nav_menu'] ),
			);
			return $title . ' <span data-nav-title="' . \esc_attr( $id_base ) . '">filtered</span>';
		};
		$format_filter = static function ( string $format ) use ( &$seen, &$current_case ): string {
			$seen['formats'][] = array(
				'case'  => $current_case['label'] ?? null,
				'input' => $format,
			);
			return $current_case['format'] ?? $format;
		};
		$widget_args_filter = static function ( array $nav_menu_args, \WP_Term $nav_menu, array $args, array $instance ) use ( &$seen, &$current_case ): array {
			$nav_menu_args['menu_id'] = 'component-fuzz-' . $current_case['label'];

			$seen['args'][] = array(
				'case'          => $current_case['label'],
				'menuId'        => $nav_menu->term_id,
				'widgetId'      => $args['widget_id'] ?? null,
				'instanceTitle' => $instance['title'] ?? null,
				'hasContainer'  => array_key_exists( 'container', $nav_menu_args ),
				'container'     => $nav_menu_args['container'] ?? null,
				'ariaLabel'     => $nav_menu_args['container_aria_label'] ?? null,
				'itemsWrap'     => $nav_menu_args['items_wrap'] ?? null,
				'menuIdArg'     => $nav_menu_args['menu_id'],
			);

			return $nav_menu_args;
		};
		$get_items_filter = static function ( $items, \WP_Term $queried_menu, array $args ) use ( &$seen, &$current_case ) {
			$ids = array();
			foreach ( (array) $items as $item ) {
				if ( is_object( $item ) && isset( $item->ID ) ) {
					$ids[] = (int) $item->ID;
				}
			}

			$seen['itemLookups'][] = array(
				'case'        => $current_case['label'] ?? null,
				'menuId'      => (int) $queried_menu->term_id,
				'count'       => count( (array) $items ),
				'ids'         => $ids,
				'postType'    => $args['post_type'] ?? null,
				'postStatus'  => $args['post_status'] ?? null,
				'taxTerms'    => $args['tax_query'][0]['terms'] ?? null,
				'updateCache' => $args['update_menu_item_cache'] ?? null,
			);

			return $items;
		};
		$objects_filter   = static function ( array $sorted_menu_items, \stdClass $nav_args ) use ( &$seen, &$current_case ): array {
			$classes = array();
			$ids     = array();

			foreach ( $sorted_menu_items as $item ) {
				if ( ! is_object( $item ) || ! isset( $item->ID ) ) {
					continue;
				}

				$ids[]                      = (int) $item->ID;
				$classes[ (int) $item->ID ] = array_values( (array) ( $item->classes ?? array() ) );
			}

			$seen['objects'][] = array(
				'case'      => $current_case['label'] ?? null,
				'menuIdArg' => $nav_args->menu_id ?? null,
				'ids'       => $ids,
				'classes'   => $classes,
			);

			return $sorted_menu_items;
		};
		$html_items_filter = static function ( string $items, \stdClass $nav_args ) use ( &$seen, &$current_case, $token ): string {
			$seen['htmlItems'][] = array(
				'case'              => $current_case['label'] ?? null,
				'menuIdArg'         => $nav_args->menu_id ?? null,
				'hasParentTitle'    => str_contains( $items, 'Parent ' . $token ),
				'hasChildTitle'     => str_contains( $items, 'Child ' . $token ),
				'hasChildClass'     => str_contains( $items, 'menu-item-has-children' ),
				'hasCustomItemLink' => str_contains( $items, 'https://example.test/nav/' . rawurlencode( strtolower( $token ) ) . '/parent' ),
			);

			return $items;
		};
		$nav_menu_filter  = static function ( string $nav_menu, \stdClass $nav_args ) use ( &$seen, &$current_case ): string {
			$seen['menus'][] = array(
				'case'       => $current_case['label'],
				'menuId'     => $nav_args->menu instanceof \WP_Term ? $nav_args->menu->term_id : null,
				'container'  => $nav_args->container ?? null,
				'ariaLabel'  => $nav_args->container_aria_label ?? null,
				'menuIdArg'  => $nav_args->menu_id ?? null,
				'itemsWrap'  => $nav_args->items_wrap ?? null,
				'fallbackCb' => $nav_args->fallback_cb ?? null,
				'preview'    => self::preview( $nav_menu ),
			);

			return $nav_menu;
		};

		$theme_features_before = $GLOBALS['_wp_theme_features'] ?? null;

		\add_filter( 'widget_title', $title_filter, 10, 3 );
		\add_filter( 'navigation_widgets_format', $format_filter );
		\add_filter( 'widget_nav_menu_args', $widget_args_filter, 10, 4 );
		\add_filter( 'wp_get_nav_menu_items', $get_items_filter, 10, 3 );
		\add_filter( 'wp_nav_menu_objects', $objects_filter, 10, 2 );
		\add_filter( 'wp_nav_menu_items', $html_items_filter, 10, 2 );
		\add_filter( 'wp_nav_menu', $nav_menu_filter, 10, 2 );
		try {
			foreach ( $cases as $case ) {
				$current_case = $case;
				if ( $case['themeHtml5'] ) {
					\add_theme_support( 'html5', array( 'navigation-widgets' ) );
				} else {
					\remove_theme_support( 'html5' );
				}

				$outputs[ $case['label'] ] = self::render_widget(
					new \WP_Nav_Menu_Widget(),
					$case['number'],
					array(
						'title'    => $case['title'],
						'nav_menu' => $menu,
					)
				);
			}

			$current_case = array(
				'label'  => 'empty',
				'format' => 'html5',
			);
			$empty_output = self::render_widget(
				new \WP_Nav_Menu_Widget(),
				38,
				array(
					'title'    => 'Empty ' . $token,
					'nav_menu' => 0,
				)
			);
		} finally {
			\remove_filter( 'wp_nav_menu', $nav_menu_filter, 10 );
			\remove_filter( 'wp_nav_menu_items', $html_items_filter, 10 );
			\remove_filter( 'wp_nav_menu_objects', $objects_filter, 10 );
			\remove_filter( 'wp_get_nav_menu_items', $get_items_filter, 10 );
			\remove_filter( 'widget_nav_menu_args', $widget_args_filter, 10 );
			\remove_filter( 'navigation_widgets_format', $format_filter );
			\remove_filter( 'widget_title', $title_filter, 10 );

			if ( null === $theme_features_before ) {
				unset( $GLOBALS['_wp_theme_features'] );
			} else {
				$GLOBALS['_wp_theme_features'] = $theme_features_before;
			}
		}

		self::collect_failure(
			$failures,
			str_contains( $outputs['html5'] ?? '', '<section id="nav_menu-36" class="widget widget_nav_menu">' )
				&& str_contains( $outputs['html5'] ?? '', 'data-nav-title="nav_menu"' )
				&& str_contains( $outputs['html5'] ?? '', '<nav ' )
				&& str_contains( $outputs['html5'] ?? '', 'aria-label="Primary ' . $token . ' filtered"' )
				&& str_contains( $outputs['html5'] ?? '', 'id="component-fuzz-html5"' )
				&& str_contains( $outputs['html5'] ?? '', 'Parent ' . $token )
				&& str_contains( $outputs['html5'] ?? '', 'Child ' . $token )
				&& str_contains( $outputs['html5'] ?? '', 'menu-item-has-children' )
				&& str_contains( $outputs['xhtml'] ?? '', '<section id="nav_menu-37" class="widget widget_nav_menu">' )
				&& str_contains( $outputs['xhtml'] ?? '', 'id="component-fuzz-xhtml"' )
				&& str_contains( $outputs['xhtml'] ?? '', 'Parent ' . $token )
				&& ! str_contains( $outputs['xhtml'] ?? '', '<nav ' )
				&& '' === $empty_output,
			'Nav Menu widget renders real selected menu output through core walker and returns early when no menu is selected',
			array(
				'html5'  => self::preview( $outputs['html5'] ?? '' ),
				'xhtml'  => self::preview( $outputs['xhtml'] ?? '' ),
				'empty'  => self::preview( $empty_output ),
				'fixture' => $fixture,
			)
		);

		self::collect_failure(
			$failures,
			2 === count( $seen['titles'] )
				&& 2 === count( $seen['formats'] )
				&& 2 === count( $seen['args'] )
				&& 2 === count( $seen['itemLookups'] )
				&& 2 === count( $seen['objects'] )
				&& 2 === count( $seen['htmlItems'] )
				&& 2 === count( $seen['menus'] )
				&& 'nav_menu' === ( $seen['titles'][0]['idBase'] ?? null )
				&& 'html5' === ( $seen['formats'][0]['case'] ?? null )
				&& 'xhtml' === ( $seen['formats'][1]['case'] ?? null )
				&& array( $fixture['parentId'], $fixture['childId'] ) === ( $seen['itemLookups'][0]['ids'] ?? null )
				&& array( $fixture['parentId'], $fixture['childId'] ) === ( $seen['objects'][0]['ids'] ?? null )
				&& in_array( 'menu-item-has-children', $seen['objects'][0]['classes'][ $fixture['parentId'] ] ?? array(), true )
				&& true === ( $seen['htmlItems'][0]['hasParentTitle'] ?? null )
				&& true === ( $seen['htmlItems'][0]['hasChildTitle'] ?? null )
				&& true === ( $seen['htmlItems'][0]['hasChildClass'] ?? null ),
			'Nav Menu widget performs real item lookup, object filtering, and walker rendering for each selected menu render',
			array( 'seen' => $seen )
		);

		self::collect_failure(
			$failures,
			true === ( $seen['args'][0]['hasContainer'] ?? null )
				&& 'nav' === ( $seen['args'][0]['container'] ?? null )
				&& 'Primary ' . $token . ' filtered' === ( $seen['args'][0]['ariaLabel'] ?? null )
				&& '<ul id="%1$s" class="%2$s">%3$s</ul>' === ( $seen['args'][0]['itemsWrap'] ?? null )
				&& false === ( $seen['args'][1]['hasContainer'] ?? null )
				&& 'component-fuzz-html5' === ( $seen['args'][0]['menuIdArg'] ?? null )
				&& 'component-fuzz-xhtml' === ( $seen['args'][1]['menuIdArg'] ?? null ),
			'widget_nav_menu_args receives HTML5 container args only in HTML5 mode and can mutate menu_id',
			array( 'args' => $seen['args'] )
		);

		self::collect_failure(
			$failures,
			'nav' === ( $seen['menus'][0]['container'] ?? null )
				&& 'Primary ' . $token . ' filtered' === ( $seen['menus'][0]['ariaLabel'] ?? null )
				&& 'component-fuzz-html5' === ( $seen['menus'][0]['menuIdArg'] ?? null )
				&& 'div' === ( $seen['menus'][1]['container'] ?? null )
				&& '' === ( $seen['menus'][1]['ariaLabel'] ?? null )
				&& 'component-fuzz-xhtml' === ( $seen['menus'][1]['menuIdArg'] ?? null )
				&& false === \has_filter( 'widget_nav_menu_args', $widget_args_filter )
				&& false === \has_filter( 'wp_get_nav_menu_items', $get_items_filter )
				&& false === \has_filter( 'wp_nav_menu_objects', $objects_filter )
				&& false === \has_filter( 'wp_nav_menu_items', $html_items_filter )
				&& false === \has_filter( 'wp_nav_menu', $nav_menu_filter ),
			'wp_nav_menu reaches final normalized defaults after widget args filtering and filters are restored',
			array(
				'menus'                 => $seen['menus'],
				'widgetArgsFilter'      => \has_filter( 'widget_nav_menu_args', $widget_args_filter ),
				'getNavMenuItemsFilter' => \has_filter( 'wp_get_nav_menu_items', $get_items_filter ),
				'navMenuObjectsFilter'  => \has_filter( 'wp_nav_menu_objects', $objects_filter ),
				'navMenuItemsFilter'    => \has_filter( 'wp_nav_menu_items', $html_items_filter ),
				'wpNavMenuFilter'       => \has_filter( 'wp_nav_menu', $nav_menu_filter ),
			)
		);

		return self::result(
			$ctx,
			'default-widgets.render.nav-menu-widget-filtered-output',
			$failures,
			array( 'seen' => $seen )
		);
	}

	private static function check_list_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$fixture  = self::list_fixture( $ctx );
		$seen     = array(
			'postsQueries'    => array(),
			'commentQueries'  => array(),
			'termQueries'     => array(),
			'archiveArgs'     => array(),
			'categoryArgs'    => array(),
			'tagCloudArgs'    => array(),
			'titles'          => array(),
			'navigationFmt'   => array(),
		);

		foreach ( array_merge( $fixture['pages'], $fixture['posts'] ) as $post ) {
			\wp_cache_set( $post->ID, $post, 'posts' );
		}

		$title_filter = static function ( string $title, array $instance, string $id_base ) use ( &$seen ): string {
			$seen['titles'][] = array(
				'idBase' => $id_base,
				'title'  => $title,
			);
			return $title . ' <span data-list-title="' . \esc_attr( $id_base ) . '">ok</span>';
		};
		$format_filter = static function ( string $format ) use ( &$seen ): string {
			$seen['navigationFmt'][] = $format;
			return 'html5';
		};
		$posts_filter = static function ( $posts, \WP_Query $query ) use ( $fixture, &$seen ) {
			unset( $posts );
			$post_type = $query->get( 'post_type' );
			$query->set( 'update_post_meta_cache', false );
			$query->set( 'update_post_term_cache', false );
			$seen['postsQueries'][] = array(
				'postType'     => $post_type,
				'postsPerPage' => $query->get( 'posts_per_page' ),
				'postStatus'   => $query->get( 'post_status' ),
			);

			if ( 'page' === $post_type ) {
				$query->found_posts   = count( $fixture['pages'] );
				$query->max_num_pages = 1;
				return $fixture['pages'];
			}

			$query->found_posts   = count( $fixture['posts'] );
			$query->max_num_pages = 1;
			return $fixture['posts'];
		};
		$comments_filter = static function ( $comments, \WP_Comment_Query $query ) use ( $fixture, &$seen ) {
			unset( $comments );
			$seen['commentQueries'][] = $query->query_vars;
			return $fixture['comments'];
		};
		$terms_filter = static function ( $terms, \WP_Term_Query $query ) use ( $fixture, &$seen ) {
			unset( $terms );
			$taxonomies             = (array) ( $query->query_vars['taxonomy'] ?? array() );
			$seen['termQueries'][] = $taxonomies;
			if ( in_array( 'category', $taxonomies, true ) ) {
				return $fixture['categories'];
			}
			if ( in_array( 'post_tag', $taxonomies, true ) ) {
				return $fixture['tags'];
			}
			return array();
		};
		$archives_filter = static function ( array $args, array $instance ) use ( &$seen ): array {
			$seen['archiveArgs'][] = array(
				'args'     => $args,
				'instance' => $instance,
			);
			$args['type'] = 'monthly';
			return $args;
		};
		$category_filter = static function ( array $args, array $instance ) use ( &$seen ): array {
			$seen['categoryArgs'][] = array(
				'args'     => $args,
				'instance' => $instance,
			);
			$args['hide_empty'] = false;
			return $args;
		};
		$tag_cloud_filter = static function ( array $args, array $instance ) use ( &$seen ): array {
			$seen['tagCloudArgs'][] = array(
				'args'     => $args,
				'instance' => $instance,
			);
			$args['number'] = 3;
			return $args;
		};

		\add_filter( 'widget_title', $title_filter, 10, 3 );
		\add_filter( 'navigation_widgets_format', $format_filter );
		\add_filter( 'posts_pre_query', $posts_filter, 10, 2 );
		\add_filter( 'comments_pre_query', $comments_filter, 10, 2 );
		\add_filter( 'terms_pre_query', $terms_filter, 10, 2 );
		\add_filter( 'widget_archives_args', $archives_filter, 10, 2 );
		\add_filter( 'widget_categories_args', $category_filter, 10, 2 );
		\add_filter( 'widget_tag_cloud_args', $tag_cloud_filter, 10, 2 );
		try {
			self::seed_archives_cache(
				array(
					(object) array(
						'year'  => '2026',
						'month' => '6',
						'posts' => '2',
					),
				)
			);

			$pages_output = self::render_widget(
				new \WP_Widget_Pages(),
				41,
				array(
					'title'   => 'Pages ' . $ctx->identifier( 3, 8 ),
					'sortby'  => 'menu_order',
					'exclude' => '',
				)
			);
			$categories_output = self::render_widget(
				new \WP_Widget_Categories(),
				42,
				array(
					'title'        => 'Categories ' . $ctx->identifier( 3, 8 ),
					'count'        => 1,
					'hierarchical' => 1,
					'dropdown'     => 0,
				)
			);
			$archives_output = self::render_widget(
				new \WP_Widget_Archives(),
				43,
				array(
					'title'    => 'Archives ' . $ctx->identifier( 3, 8 ),
					'count'    => 1,
					'dropdown' => 0,
				)
			);
			$recent_posts_output = self::render_widget(
				new \WP_Widget_Recent_Posts(),
				44,
				array(
					'title'     => 'Recent Posts ' . $ctx->identifier( 3, 8 ),
					'number'    => 2,
					'show_date' => true,
				)
			);
			$recent_comments_output = self::render_widget(
				new \WP_Widget_Recent_Comments(),
				45,
				array(
					'title'  => 'Recent Comments ' . $ctx->identifier( 3, 8 ),
					'number' => 2,
				)
			);
			$tag_cloud_output = self::render_widget(
				new \WP_Widget_Tag_Cloud(),
				46,
				array(
					'title'    => 'Tags ' . $ctx->identifier( 3, 8 ),
					'taxonomy' => 'post_tag',
					'count'    => 1,
				)
			);
		} finally {
			\remove_filter( 'widget_tag_cloud_args', $tag_cloud_filter, 10 );
			\remove_filter( 'widget_categories_args', $category_filter, 10 );
			\remove_filter( 'widget_archives_args', $archives_filter, 10 );
			\remove_filter( 'terms_pre_query', $terms_filter, 10 );
			\remove_filter( 'comments_pre_query', $comments_filter, 10 );
			\remove_filter( 'posts_pre_query', $posts_filter, 10 );
			\remove_filter( 'navigation_widgets_format', $format_filter );
			\remove_filter( 'widget_title', $title_filter, 10 );
		}

		self::collect_failure(
			$failures,
			str_contains( $pages_output, 'widget_pages' )
				&& str_contains( $pages_output, 'data-list-title="pages"' )
				&& str_contains( $pages_output, 'About ' . $fixture['token'] )
				&& str_contains( $pages_output, '<nav aria-label="Pages' ),
			'Pages widget renders filter-backed page list with html5 navigation wrapper',
			array( 'output' => self::preview( $pages_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $categories_output, 'widget_categories' )
				&& str_contains( $categories_output, 'News ' . $fixture['token'] )
				&& str_contains( $categories_output, 'Events ' . $fixture['token'] )
				&& str_contains( $categories_output, 'data-list-title="categories"' )
				&& isset( $seen['categoryArgs'][0] ),
			'Categories widget renders terms_pre_query categories through filtered args',
			array( 'output' => self::preview( $categories_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $archives_output, 'widget_archive' )
				&& str_contains( $archives_output, 'June 2026' )
				&& str_contains( $archives_output, '&nbsp;(2)' )
				&& isset( $seen['archiveArgs'][0] ),
			'Archives widget renders cache-backed monthly archive results',
			array( 'output' => self::preview( $archives_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $recent_posts_output, 'widget_recent_entries' )
				&& str_contains( $recent_posts_output, 'Post One ' . $fixture['token'] )
				&& str_contains( $recent_posts_output, 'Post Two ' . $fixture['token'] )
				&& str_contains( $recent_posts_output, 'class="post-date"' ),
			'Recent Posts widget renders posts_pre_query fixtures and date branch',
			array( 'output' => self::preview( $recent_posts_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $recent_comments_output, 'widget_recent_comments' )
				&& str_contains( $recent_comments_output, 'recentcomments' )
				&& str_contains( $recent_comments_output, 'Ada ' . $fixture['token'] )
				&& str_contains( $recent_comments_output, 'Post One ' . $fixture['token'] ),
			'Recent Comments widget renders comments_pre_query fixtures linked to cached posts',
			array( 'output' => self::preview( $recent_comments_output ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $tag_cloud_output, 'tagcloud' )
				&& str_contains( $tag_cloud_output, 'Alpha ' . $fixture['token'] )
				&& str_contains( $tag_cloud_output, 'Beta ' . $fixture['token'] )
				&& isset( $seen['tagCloudArgs'][0] ),
			'Tag Cloud widget renders terms_pre_query tags through filtered args',
			array( 'output' => self::preview( $tag_cloud_output ) )
		);

		return self::result(
			$ctx,
			'default-widgets.render.filtered-list-and-query-widgets',
			$failures,
			array( 'seen' => $seen )
		);
	}

	private static function check_rss_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = $ctx->identifier( 4, 10 );
		$url      = 'https://example.test/component-fuzz-feed-' . strtolower( $token ) . '.xml';
		$seen     = array(
			'requests'  => array(),
			'feedLinks' => array(),
			'titles'    => array(),
		);
		$feed_xml = self::rss_fixture_xml( $token );

		$http_filter = static function ( $preempt, array $args, string $request_url ) use ( $url, $feed_xml, &$seen ) {
			unset( $args );
			if ( $request_url !== $url ) {
				return $preempt;
			}

			$seen['requests'][] = $request_url;
			return array(
				'headers'  => array( 'content-type' => 'application/rss+xml; charset=UTF-8' ),
				'body'     => $feed_xml,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		$title_filter = static function ( string $title, array $instance, string $id_base ) use ( &$seen ): string {
			$seen['titles'][] = array(
				'idBase' => $id_base,
				'title'  => $title,
			);
			return $title . ' <span data-rss-title="' . \esc_attr( $id_base ) . '">ok</span>';
		};
		$feed_link_filter = static function ( string $feed_link, array $instance ) use ( &$seen ): string {
			$seen['feedLinks'][] = array(
				'feedLink' => $feed_link,
				'url'      => $instance['url'] ?? null,
			);
			return '<a class="cfz-rss-feed" href="' . \esc_url( $instance['url'] ?? '' ) . '">Feed</a> ';
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		\add_filter( 'widget_title', $title_filter, 10, 3 );
		\add_filter( 'rss_widget_feed_link', $feed_link_filter, 10, 2 );
		try {
			$rss_output = self::render_widget(
				new \WP_Widget_RSS(),
				51,
				array(
					'title'        => '',
					'url'          => $url,
					'items'        => 2,
					'show_summary' => 1,
					'show_author'  => 1,
					'show_date'    => 1,
				)
			);
			$processed = \wp_widget_rss_process(
				array(
					'title'        => '<b>RSS ' . $token . '</b>',
					'url'          => $url,
					'items'        => 99,
					'show_summary' => 1,
					'show_author'  => 1,
					'show_date'    => 1,
				),
				false
			);
		} finally {
			\remove_filter( 'rss_widget_feed_link', $feed_link_filter, 10 );
			\remove_filter( 'widget_title', $title_filter, 10 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		self::collect_failure(
			$failures,
			str_contains( $rss_output, '<section id="rss-51" class="widget widget_rss">' )
				&& str_contains( $rss_output, 'class="cfz-rss-feed"' )
				&& str_contains( $rss_output, 'Feed Title ' . $token )
				&& str_contains( $rss_output, 'First Item ' . $token )
				&& str_contains( $rss_output, 'Second Item ' . $token )
				&& str_contains( $rss_output, 'class="rssSummary"' )
				&& str_contains( $rss_output, '<cite>Ada ' . $token . '</cite>' )
				&& 1 <= count( $seen['requests'] )
				&& isset( $seen['feedLinks'][0], $seen['titles'][0] ),
			'RSS widget renders local pre_http_request feed with title/feed-link filters and item branches',
			array(
				'output' => self::preview( $rss_output ),
				'seen'   => $seen,
			)
		);

		self::collect_failure(
			$failures,
			'RSS ' . $token === ( $processed['title'] ?? null )
				&& 10 === ( $processed['items'] ?? null )
				&& false === ( $processed['error'] ?? null )
				&& '' === ( $processed['link'] ?? null ),
			'wp_widget_rss_process sanitizes title and bounds items without remote feed check',
			array( 'processed' => $processed )
		);

		return self::result( $ctx, 'default-widgets.render.rss-local-feed-and-processing', $failures );
	}

	private static function new_widget( string $class, \ComponentFuzz\FuzzContext $ctx ): \WP_Widget {
		unset( $ctx );
		return new $class();
	}

	private static function capture_form( \WP_Widget $widget, int $number, array $instance ): string {
		$widget->_set( $number );
		$level = ob_get_level();
		ob_start();
		try {
			$widget->form( $instance );
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			throw $e;
		}
	}

	private static function render_widget( \WP_Widget $widget, int $number, array $instance ): string {
		$widget->_set( $number );
		$level = ob_get_level();
		ob_start();
		try {
			$widget->widget( self::widget_args( $widget ), $instance );
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			throw $e;
		}
	}

	private static function capture_callback_output( callable $callback ): string {
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

	private static function widget_args( \WP_Widget $widget ): array {
		return array(
			'before_widget' => '<section id="' . \esc_attr( $widget->id ) . '" class="widget ' . \esc_attr( $widget->widget_options['classname'] ?? 'widget_' . $widget->id_base ) . '">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
			'widget_id'     => $widget->id,
			'widget_name'   => $widget->name,
		);
	}

	private static function safe_title( string $title ): bool {
		return '' !== $title
			&& ! str_contains( $title, '<' )
			&& ! str_contains( $title, '>' )
			&& ! str_contains( strtolower( $title ), 'script' );
	}

	private static function list_fixture( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = $ctx->identifier( 4, 8 );
		$post_one = self::make_post(
			601,
			array(
				'post_title' => 'Post One ' . $token,
				'post_name'  => 'post-one-' . strtolower( $token ),
				'post_date'  => '2026-06-21 10:00:00',
			)
		);
		$post_two = self::make_post(
			602,
			array(
				'post_title' => 'Post Two ' . $token,
				'post_name'  => 'post-two-' . strtolower( $token ),
				'post_date'  => '2026-06-22 11:00:00',
			)
		);
		$page_one = self::make_post(
			701,
			array(
				'post_title' => 'About ' . $token,
				'post_name'  => 'about-' . strtolower( $token ),
				'post_type'  => 'page',
				'menu_order' => 1,
			)
		);
		$page_two = self::make_post(
			702,
			array(
				'post_title' => 'Contact ' . $token,
				'post_name'  => 'contact-' . strtolower( $token ),
				'post_type'  => 'page',
				'menu_order' => 2,
			)
		);

		return array(
			'token'      => $token,
			'posts'      => array( $post_one, $post_two ),
			'pages'      => array( $page_one, $page_two ),
			'categories' => array(
				self::make_term( 801, 'News ' . $token, 'news-' . strtolower( $token ), 'category', 3 ),
				self::make_term( 802, 'Events ' . $token, 'events-' . strtolower( $token ), 'category', 1 ),
			),
			'tags'       => array(
				self::make_term( 901, 'Alpha ' . $token, 'alpha-' . strtolower( $token ), 'post_tag', 5 ),
				self::make_term( 902, 'Beta ' . $token, 'beta-' . strtolower( $token ), 'post_tag', 2 ),
			),
			'comments'   => array(
				self::make_comment( 1001, 601, 'Ada ' . $token, 'First comment ' . $token ),
				self::make_comment( 1002, 602, 'Grace ' . $token, 'Second comment ' . $token ),
			),
		);
	}

	private static function make_post( int $id, array $overrides = array() ): \WP_Post {
		$post = (object) array_merge(
			array(
				'ID'                    => $id,
				'post_author'           => 1,
				'post_date'             => '2026-06-23 12:00:00',
				'post_date_gmt'         => '2026-06-23 10:00:00',
				'post_content'          => '',
				'post_title'            => 'Post ' . $id,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'post-' . $id,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 12:00:00',
				'post_modified_gmt'     => '2026-06-23 10:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'https://example.test/?p=' . $id,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
			),
			$overrides
		);

		return new \WP_Post( $post );
	}

	private static function make_term( int $id, string $name, string $slug, string $taxonomy, int $count ): \WP_Term {
		$term = new \WP_Term(
			(object) array(
				'term_id'          => $id,
				'name'             => $name,
				'slug'             => $slug,
				'term_group'       => 0,
				'term_taxonomy_id' => $id,
				'taxonomy'         => $taxonomy,
				'description'      => '',
				'parent'           => 0,
				'count'            => $count,
				'filter'           => 'raw',
			)
		);
		\wp_cache_set( $id, $term, 'terms' );
		return $term;
	}

	private static function seed_nav_menu_fixture( \WP_Term $menu, string $token ): array {
		$wpdb     = self::stub_wpdb();
		$term_id  = (int) $menu->term_id;
		$tt_id    = (int) $menu->term_taxonomy_id;
		$base_url = 'https://example.test/nav/' . rawurlencode( strtolower( $token ) );

		$wpdb->insert(
			$wpdb->terms,
			array(
				'term_id'    => $term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'term_group' => 0,
			)
		);
		$wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_taxonomy_id' => $tt_id,
				'term_id'          => $term_id,
				'taxonomy'         => 'nav_menu',
				'description'      => '',
				'parent'           => 0,
				'count'            => 2,
			)
		);

		$parent_id = 50000 + ( $term_id * 2 );
		$child_id  = $parent_id + 1;

		self::seed_nav_menu_item(
			$parent_id,
			$tt_id,
			'Parent ' . $token,
			$base_url . '/parent',
			1,
			0,
			array( 'cfz-parent-' . strtolower( $token ) )
		);
		self::seed_nav_menu_item(
			$child_id,
			$tt_id,
			'Child ' . $token,
			$base_url . '/child',
			2,
			$parent_id,
			array( 'cfz-child-' . strtolower( $token ) )
		);

		return array(
			'menuId'   => $term_id,
			'ttId'     => $tt_id,
			'parentId' => $parent_id,
			'childId'  => $child_id,
		);
	}

	private static function seed_nav_menu_item(
		int $post_id,
		int $term_taxonomy_id,
		string $title,
		string $url,
		int $order,
		int $parent_id,
		array $classes
	): void {
		$wpdb = self::stub_wpdb();

		$wpdb->insert(
			$wpdb->posts,
			array(
				'ID'                    => $post_id,
				'post_author'           => 1,
				'post_date'             => '2026-06-23 12:00:00',
				'post_date_gmt'         => '2026-06-23 10:00:00',
				'post_content'          => '',
				'post_title'            => $title,
				'post_excerpt'          => 'Title attribute for ' . $title,
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'menu-item-' . $post_id,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 12:00:00',
				'post_modified_gmt'     => '2026-06-23 10:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => $url,
				'menu_order'            => $order,
				'post_type'             => 'nav_menu_item',
				'post_mime_type'        => '',
				'comment_count'         => 0,
			)
		);
		$wpdb->insert(
			$wpdb->term_relationships,
			array(
				'object_id'        => $post_id,
				'term_taxonomy_id' => $term_taxonomy_id,
				'term_order'       => $order,
			)
		);

		foreach (
			array(
				'_menu_item_type'             => 'custom',
				'_menu_item_object'           => 'custom',
				'_menu_item_object_id'        => $post_id,
				'_menu_item_menu_item_parent' => $parent_id,
				'_menu_item_url'              => $url,
				'_menu_item_target'           => '',
				'_menu_item_classes'          => $classes,
				'_menu_item_xfn'              => '',
			) as $meta_key => $meta_value
		) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => $meta_key,
					'meta_value' => \maybe_serialize( $meta_value ),
				)
			);
		}
	}

	private static function stub_wpdb(): \Component_Fuzz_WPDB_Stub {
		return $GLOBALS['wpdb'];
	}

	private static function make_comment( int $id, int $post_id, string $author, string $content ): \WP_Comment {
		return new \WP_Comment(
			(object) array(
				'comment_ID'           => $id,
				'comment_post_ID'      => $post_id,
				'comment_author'       => $author,
				'comment_author_email' => strtolower( str_replace( ' ', '.', $author ) ) . '@example.test',
				'comment_author_url'   => '',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2026-06-23 12:00:00',
				'comment_date_gmt'     => '2026-06-23 10:00:00',
				'comment_content'      => $content,
				'comment_karma'        => '0',
				'comment_approved'     => '1',
				'comment_agent'        => 'ComponentFuzz',
				'comment_type'         => 'comment',
				'comment_parent'       => '0',
				'user_id'              => '0',
			)
		);
	}

	private static function seed_calendar_cache( string $html ): void {
		$args = array(
			'initial'   => true,
			'display'   => true,
			'post_type' => 'post',
		);
		$cache_args = $args;
		unset( $cache_args['display'] );

		$cache_args['globals'] = array(
			'm'        => $GLOBALS['m'] ?? null,
			'monthnum' => $GLOBALS['monthnum'] ?? null,
			'year'     => $GLOBALS['year'] ?? null,
			'week'     => isset( $_GET['w'] ) ? (int) $_GET['w'] : 0,
		);

		\wp_recursive_ksort( $cache_args );
		$key = md5( serialize( $cache_args ) );
		\wp_cache_set( 'get_calendar', array( $key => $html ), 'calendar' );
	}

	private static function seed_archives_cache( array $results ): void {
		global $wpdb;

		$parsed_args = array(
			'type'            => 'monthly',
			'limit'           => '',
			'format'          => 'html',
			'before'          => '',
			'after'           => '',
			'show_post_count' => '1',
			'echo'            => 1,
			'order'           => 'DESC',
			'post_type'       => 'post',
			'year'            => '',
			'monthnum'        => '',
			'day'             => '',
			'w'               => '',
		);
		$where       = $wpdb->prepare( "WHERE post_type = %s AND post_status = 'publish'", $parsed_args['post_type'] );
		$query       = "SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts FROM $wpdb->posts  $where GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY post_date DESC ";
		$key         = 'wp_get_archives:' . md5( $query );

		\wp_cache_set_salted( $key, $results, 'post-queries', \wp_cache_get_last_changed( 'posts' ) );
	}

	private static function rss_fixture_xml( string $token ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'
			. '<title>Feed Title ' . \esc_html( $token ) . '</title>'
			. '<link>https://example.test/feed-home</link>'
			. '<description>Feed Description ' . \esc_html( $token ) . '</description>'
			. '<item><title>First Item ' . \esc_html( $token ) . '</title><link>https://example.test/first</link><description>Summary ' . \esc_html( $token ) . '</description><dc:creator>Ada ' . \esc_html( $token ) . '</dc:creator><pubDate>Tue, 23 Jun 2026 10:00:00 +0000</pubDate></item>'
			. '<item><title>Second Item ' . \esc_html( $token ) . '</title><link>https://example.test/second</link><description>Second Summary ' . \esc_html( $token ) . '</description><dc:creator>Grace ' . \esc_html( $token ) . '</dc:creator><pubDate>Tue, 23 Jun 2026 11:00:00 +0000</pubDate></item>'
			. '</channel></rss>';
	}

	private static function reset_runtime(): void {
		if ( function_exists( 'create_initial_post_types' ) ) {
			\create_initial_post_types();
		}
		if ( function_exists( 'create_initial_taxonomies' ) ) {
			\create_initial_taxonomies();
		}

		$GLOBALS['wp_registered_sidebars']        = array();
		$GLOBALS['wp_registered_widgets']         = array();
		$GLOBALS['wp_registered_widget_controls'] = array();
		$GLOBALS['wp_registered_widget_updates']  = array();
		$GLOBALS['_wp_sidebars_widgets']          = array();
		$GLOBALS['sidebars_widgets']              = array();
		$GLOBALS['wp_widget_factory']             = new \WP_Widget_Factory();
		$GLOBALS['wp_rewrite']                    = new \WP_Rewrite();
		$GLOBALS['wp_query']                      = new \WP_Query();
		$GLOBALS['wp_the_query']                  = $GLOBALS['wp_query'];
		$GLOBALS['post']                          = null;
		$GLOBALS['posts']                         = array();
		$GLOBALS['m']                             = '';
		$GLOBALS['monthnum']                      = '';
		$GLOBALS['year']                          = '';
		$_GET                                    = array();

		self::set_calendar_instance( 0 );

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'blog_charset'          => 'UTF-8',
					'blogdescription'       => 'Component Fuzz Site',
					'blogname'              => 'Component Fuzz',
					'comments_per_page'     => 50,
					'date_format'           => 'F j, Y',
					'default_comments_page' => 'oldest',
					'home'                  => 'https://example.test',
					'page_comments'         => 0,
					'page_for_posts'        => 0,
					'permalink_structure'   => '',
					'posts_per_rss'         => 10,
					'rss_language'          => 'en',
					'show_on_front'         => 'posts',
					'siteurl'               => 'https://example.test',
					'start_of_week'         => 1,
					'time_format'           => 'g:i a',
					'users_can_register'    => 0,
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		\wp_set_current_user( 0 );
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'_GET',
				'_POST',
				'_REQUEST',
				'_SERVER',
				'_wp_sidebars_widgets',
				'_wp_theme_features',
				'current_user',
				'm',
				'monthnum',
				'post',
				'posts',
				'sidebars_widgets',
				'user_ID',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_post_statuses',
				'wp_post_types',
				'wp_query',
				'wp_registered_sidebars',
				'wp_registered_widget_controls',
				'wp_registered_widget_updates',
				'wp_registered_widgets',
				'wp_rewrite',
				'wp_taxonomies',
				'wp_the_query',
				'wp_widget_factory',
				'year',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'globals'          => $globals,
			'options'          => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
			'contentCounts'    => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array(),
			'wpdbRuntime'      => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_runtime_state()
				: array(),
			'calendarInstance' => self::get_calendar_instance(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_restore_runtime_state( $snapshot['wpdbRuntime'] ?? array() );
		}
		self::set_calendar_instance( (int) $snapshot['calendarInstance'] );
	}

	private static function state_matches( array $snapshot ): bool {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			if ( $snapshot['options'] !== $GLOBALS['wpdb']->component_fuzz_get_options() ) {
				return false;
			}
			if ( $snapshot['contentCounts'] !== $GLOBALS['wpdb']->component_fuzz_content_counts() ) {
				return false;
			}
			if ( $snapshot['wpdbRuntime'] !== $GLOBALS['wpdb']->component_fuzz_get_runtime_state() ) {
				return false;
			}
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}

			if ( ! $entry['exists'] ) {
				continue;
			}

			if ( in_array( $name, array( 'wp_filter', 'wp_object_cache', 'wp_widget_factory', 'wp_registered_widgets', 'wp_registered_sidebars' ), true )
				&& $entry['value'] != $GLOBALS[ $name ]
			) {
				return false;
			}
		}

		return (int) $snapshot['calendarInstance'] === self::get_calendar_instance();
	}

	private static function get_calendar_instance(): int {
		$property = new \ReflectionProperty( \WP_Widget_Calendar::class, 'instance' );
		return (int) $property->getValue();
	}

	private static function set_calendar_instance( int $value ): void {
		$property = new \ReflectionProperty( \WP_Widget_Calendar::class, 'instance' );
		$property->setValue( null, $value );
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failures'] = array_slice( $failures, 0, 8 );
		return $ctx->result( $invariant, array() === $failures, $data );
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $details = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'details' => $details,
			);
		}
	}

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'line'    => $e->getLine(),
			'file'    => $e->getFile(),
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
}
