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
			$rows[] = self::check_form_escaping( $ctx->fork( 'forms' ) );
			$rows[] = self::check_text_and_html_rendering( $ctx->fork( 'text-html' ) );
			$rows[] = self::check_simple_widget_rendering( $ctx->fork( 'simple-render' ) );
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

		foreach (
			array(
				'add_filter',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'fetch_feed',
				'get_calendar',
				'get_search_form',
				'remove_filter',
				'sanitize_text_field',
				'wp_cache_flush',
				'wp_cache_get_last_changed',
				'wp_cache_set',
				'wp_cache_set_salted',
				'wp_get_archives',
				'wp_list_categories',
				'wp_list_pages',
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
			'calendarInstance' => self::get_calendar_instance(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
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
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}

			if ( ! $entry['exists'] ) {
				continue;
			}

			if ( in_array( $name, array( 'wp_filter', 'wp_widget_factory', 'wp_registered_widgets', 'wp_registered_sidebars' ), true )
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
