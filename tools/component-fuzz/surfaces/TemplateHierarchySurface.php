<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes classic PHP template hierarchy and loader helpers without dispatch.
 */
final class TemplateHierarchySurface {
	public const NAME = 'template-hierarchy';

	private const FAILURE_LIMIT = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'template-hierarchy.bootstrap-apis-available',
					'Required classic template APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot  = self::snapshot_state();
		$temp_root = self::temp_root( $ctx );
		$rows      = array();

		try {
			$case = self::prepare_case( $ctx, $temp_root );
			self::install_theme_filters( $case );

			$rows[] = self::check_locate_template_priority( $ctx->fork( 'locate' ), $case );
			$rows[] = self::check_get_query_template_filters( $ctx->fork( 'query-template' ), $case );
			$rows[] = self::check_direct_template_helpers_and_theme_paths( $ctx->fork( 'direct-templates' ), $case );
			$rows[] = self::check_rich_direct_template_helpers( $ctx->fork( 'rich-direct-templates' ), $case );
			$rows[] = self::check_get_single_template_hierarchy( $ctx->fork( 'single-template' ), $case );
			$rows[] = self::check_term_template_hierarchy_decoding( $ctx->fork( 'term-templates' ), $case );
			$rows[] = self::check_load_template_include_semantics( $ctx->fork( 'load-template' ), $case );
			$rows[] = self::check_get_template_part_hooks_and_args( $ctx->fork( 'template-part' ), $case );
			$rows[] = self::check_comments_template_guard( $ctx->fork( 'comments-template' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'template-hierarchy.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
			self::remove_dir_recursive( $temp_root );
		}

		$rows[] = self::check_runtime_restored( $ctx, $snapshot );

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Query',
				'WP_Post',
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
				'comments_template',
				'get_404_template',
				'get_archive_template',
				'get_attachment_template',
				'get_author_template',
				'get_category_template',
				'get_date_template',
				'get_embed_template',
				'get_front_page_template',
				'get_home_template',
				'get_page_template',
				'get_privacy_policy_template',
				'get_query_template',
				'get_queried_object',
				'get_search_template',
				'get_single_template',
				'get_singular_template',
				'get_stylesheet',
				'get_stylesheet_directory',
				'get_tag_template',
				'get_taxonomy_template',
				'get_template',
				'get_template_directory',
				'get_template_part',
				'get_theme_root',
				'locate_template',
				'load_template',
				'remove_filter',
				'wp_cache_delete',
				'wp_set_template_globals',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_locate_template_priority( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		unset( $ctx );
		self::reset_template_globals();

		$failures   = array();
		$shared     = \locate_template( array( 'missing.php', 'shared.php' ) );
		$parent     = \locate_template( array( 'parent-only.php' ) );
		$empty      = \locate_template( array( '', 'absent.php' ) );
		unset( $GLOBALS['cfz_template_hierarchy_loaded'] );
		$no_load    = self::capture_output(
			static function (): void {
				\locate_template( array( 'shared.php' ), false );
			}
		);
		$no_load_events = $GLOBALS['cfz_template_hierarchy_loaded'] ?? array();
		unset( $GLOBALS['cfz_template_hierarchy_loaded'] );
		$loaded_out = self::capture_output(
			static function () use ( $case ): void {
				\locate_template(
					array( 'shared.php' ),
					true,
					false,
					array( 'token' => $case['token'], 'mode' => 'locate-load' )
				);
			}
		);

		self::collect_failure(
			$failures,
			$shared === $case['paths']['child'] . '/shared.php'
				&& $parent === $case['paths']['parent'] . '/parent-only.php'
				&& '' === $empty
				&& self::path_is_allowed( $shared, $case )
				&& self::path_is_allowed( $parent, $case ),
			'locate_template checks child before parent and returns only expected theme paths',
			array(
				'shared' => $shared,
				'parent' => $parent,
				'empty'  => $empty,
				'roots'  => array( $case['paths']['child'], $case['paths']['parent'] ),
			)
		);

		self::collect_failure(
			$failures,
			'' === $no_load && array() === $no_load_events,
			'locate_template does not include located files when load is false',
			array(
				'output' => self::describe_string( $no_load ),
				'loaded' => $no_load_events,
			)
		);

		$loaded = $GLOBALS['cfz_template_hierarchy_loaded'] ?? array();
		self::collect_failure(
			$failures,
			str_contains( $loaded_out, 'template:child-shared:' . $case['token'] )
				&& ! str_contains( $loaded_out, 'template:parent-shared:' )
				&& 1 === count( $loaded )
				&& 'child-shared' === ( $loaded[0]['label'] ?? null )
				&& array( 'token' => $case['token'], 'mode' => 'locate-load' ) === ( $loaded[0]['args'] ?? null ),
			'locate_template load path passes args and includes the selected child file only',
			array(
				'output' => self::describe_string( $loaded_out ),
				'loaded' => $loaded,
			)
		);

		unset( $GLOBALS['cfz_template_hierarchy_loaded'] );

		return self::result(
			'template-hierarchy.locate-template-child-parent-priority',
			$failures,
			array(
				'shared' => self::relative_to_root( $shared, $case['paths']['root'] ),
				'parent' => self::relative_to_root( $parent, $case['paths']['root'] ),
			)
		);
	}

	private static function check_get_query_template_filters( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_template_globals();

		$failures        = array();
		$hierarchy_seen  = array();
		$template_seen   = array();
		$preferred       = 'filtered-' . self::safe_fragment( $ctx->fork( 'preferred' ), 8 ) . '.php';
		$preferred_path  = $case['paths']['child'] . '/' . $preferred;
		$hierarchy_filter = static function ( array $templates ) use ( &$hierarchy_seen, $preferred ): array {
			$hierarchy_seen[] = $templates;
			array_unshift( $templates, $preferred );
			return $templates;
		};
		$template_filter = static function ( string $template, string $type, array $templates ) use ( &$template_seen ): string {
			$template_seen[] = array(
				'template'  => $template,
				'type'      => $type,
				'templates' => $templates,
			);
			return $template;
		};

		self::write_template_file( $preferred_path, 'filtered-query' );

		\add_filter( 'index_template_hierarchy', $hierarchy_filter );
		\add_filter( 'index_template', $template_filter, 10, 3 );
		try {
			$located = \get_query_template( 'in!dex', array( 'missing-index.php', 'index.php' ) );
		} finally {
			\remove_filter( 'index_template_hierarchy', $hierarchy_filter );
			\remove_filter( 'index_template', $template_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$located === $preferred_path
				&& array( array( 'missing-index.php', 'index.php' ) ) === $hierarchy_seen
				&& 1 === count( $template_seen )
				&& 'index' === $template_seen[0]['type']
				&& $preferred === ( $template_seen[0]['templates'][0] ?? null )
				&& $preferred_path === $template_seen[0]['template'],
			'get_query_template sanitizes type hooks, applies hierarchy filters, and returns the selected file',
			array(
				'located'       => self::relative_to_root( $located, $case['paths']['root'] ),
				'hierarchySeen' => $hierarchy_seen,
				'templateSeen'  => $template_seen,
				'preferred'     => $preferred,
			)
		);

		return self::result(
			'template-hierarchy.get-query-template-filter-contract',
			$failures,
			array( 'preferred' => $preferred )
		);
	}

	private static function check_direct_template_helpers_and_theme_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_template_globals();

		$failures      = array();
		$observed      = array();
		$directory_log = array();
		$types         = array( 'archive', 'page', 'search', '404', 'embed' );
		$filters       = array();

		foreach ( $types as $type ) {
			$hierarchy_filter = static function ( array $templates ) use ( &$observed, $type ): array {
				$observed[ $type ]['hierarchy'][] = $templates;
				return $templates;
			};
			$template_filter  = static function ( string $template, string $seen_type, array $templates ) use ( &$observed, $type ): string {
				$observed[ $type ]['template'][] = array(
					'template'  => $template,
					'type'      => $seen_type,
					'templates' => $templates,
				);
				return $template;
			};

			\add_filter( "{$type}_template_hierarchy", $hierarchy_filter );
			\add_filter( "{$type}_template", $template_filter, 10, 3 );
			$filters[] = array( "{$type}_template_hierarchy", $hierarchy_filter );
			$filters[] = array( "{$type}_template", $template_filter );
		}

		$stylesheet_directory_filter = static function ( string $stylesheet_dir, string $stylesheet, string $theme_root ) use ( &$directory_log ): string {
			$directory_log[] = array(
				'hook'      => 'stylesheet',
				'directory' => $stylesheet_dir,
				'theme'     => $stylesheet,
				'root'      => $theme_root,
			);
			return $stylesheet_dir;
		};
		$template_directory_filter   = static function ( string $template_dir, string $template, string $theme_root ) use ( &$directory_log ): string {
			$directory_log[] = array(
				'hook'      => 'template',
				'directory' => $template_dir,
				'theme'     => $template,
				'root'      => $theme_root,
			);
			return $template_dir;
		};

		\add_filter( 'stylesheet_directory', $stylesheet_directory_filter, 10, 3 );
		\add_filter( 'template_directory', $template_directory_filter, 10, 3 );

		$meta_filter = null;

		try {
			$stylesheet           = \get_stylesheet();
			$template             = \get_template();
			$theme_root           = \get_theme_root( $stylesheet );
			$stylesheet_directory = \get_stylesheet_directory();
			$template_directory   = \get_template_directory();
			\wp_set_template_globals();
			$wp_stylesheet_path = $GLOBALS['wp_stylesheet_path'] ?? null;
			$wp_template_path   = $GLOBALS['wp_template_path'] ?? null;

			$expected_directory_log = array(
				array(
					'hook'      => 'stylesheet',
					'directory' => $case['paths']['child'],
					'theme'     => $case['theme']['child'],
					'root'      => $case['paths']['themes'],
				),
				array(
					'hook'      => 'template',
					'directory' => $case['paths']['parent'],
					'theme'     => $case['theme']['parent'],
					'root'      => $case['paths']['themes'],
				),
				array(
					'hook'      => 'stylesheet',
					'directory' => $case['paths']['child'],
					'theme'     => $case['theme']['child'],
					'root'      => $case['paths']['themes'],
				),
				array(
					'hook'      => 'template',
					'directory' => $case['paths']['parent'],
					'theme'     => $case['theme']['parent'],
					'root'      => $case['paths']['themes'],
				),
			);

			self::collect_failure(
				$failures,
				$case['theme']['child'] === $stylesheet
					&& $case['theme']['parent'] === $template
					&& $case['paths']['themes'] === $theme_root
					&& $case['paths']['child'] === $stylesheet_directory
					&& $case['paths']['parent'] === $template_directory
					&& $case['paths']['child'] === $wp_stylesheet_path
					&& $case['paths']['parent'] === $wp_template_path
					&& $expected_directory_log === $directory_log,
				'stylesheet/template helpers keep child and parent paths distinct under the generated theme root',
				array(
					'stylesheet'       => $stylesheet,
					'template'         => $template,
					'themeRoot'        => $theme_root,
					'stylesheetDir'    => $stylesheet_directory,
					'templateDir'      => $template_directory,
					'wpStylesheetPath' => $wp_stylesheet_path,
					'wpTemplatePath'   => $wp_template_path,
					'directoryLog'     => $directory_log,
				)
			);

			$archive_type     = 'report-' . self::safe_fragment( $ctx->fork( 'archive-type' ), 7 );
			$archive_template = "archive-{$archive_type}.php";
			self::write_template_file( $case['paths']['child'] . '/' . $archive_template, 'archive-type' );
			self::write_template_file( $case['paths']['parent'] . '/archive.php', 'archive-fallback' );
			self::set_query_context( array( 'post_type' => array( $archive_type ) ), null, array( 'is_archive' => true ) );
			$archive = \get_archive_template();

			$page_id           = $ctx->fork( 'page-id' )->int( 2000, 9999 );
			$page_slug_raw     = 'landing-' . self::safe_fragment( $ctx->fork( 'page-a' ), 5 ) . '%2b' . self::safe_fragment( $ctx->fork( 'page-b' ), 5 );
			$page_slug_decoded = urldecode( $page_slug_raw );
			$page_post         = self::make_post( $page_id, 'page', $page_slug_decoded );
			$page_template     = "page-{$page_slug_decoded}.php";
			self::write_template_file( $case['paths']['child'] . '/' . $page_template, 'page-decoded' );
			self::write_template_file( $case['paths']['parent'] . "/page-{$page_slug_raw}.php", 'page-raw' );
			self::write_template_file( $case['paths']['parent'] . "/page-{$page_id}.php", 'page-id' );
			self::write_template_file( $case['paths']['parent'] . '/page.php', 'page-fallback' );

			$meta_filter = static function ( $value, $object_id, $meta_key, $single ) use ( $page_id ) {
				if ( (int) $object_id === $page_id && '_wp_page_template' === $meta_key && $single ) {
					return 'default';
				}
				return $value;
			};
			\add_filter( 'get_post_metadata', $meta_filter, 10, 4 );

			self::set_query_context(
				array( 'pagename' => $page_slug_raw ),
				$page_post,
				array(
					'is_page'     => true,
					'is_singular' => true,
				)
			);
			$page = \get_page_template();

			self::write_template_file( $case['paths']['child'] . '/search.php', 'search-child' );
			self::set_query_context( array( 's' => 'lookup-' . $case['token'] ), null, array( 'is_search' => true ) );
			$search = \get_search_template();

			self::write_template_file( $case['paths']['child'] . '/404.php', '404-child' );
			self::set_query_context( array(), null, array( 'is_404' => true ) );
			$not_found = \get_404_template();

			$embed_type     = 'clip-' . self::safe_fragment( $ctx->fork( 'embed-type' ), 7 );
			$embed_template = "embed-{$embed_type}.php";
			$embed_post     = self::make_post( $ctx->fork( 'embed-id' )->int( 10000, 19999 ), $embed_type, 'embedded-' . self::safe_fragment( $ctx->fork( 'embed-name' ), 6 ) );
			self::write_template_file( $case['paths']['child'] . '/' . $embed_template, 'embed-type' );
			self::write_template_file( $case['paths']['parent'] . '/embed.php', 'embed-fallback' );
			self::set_query_context( array(), $embed_post, array( 'is_singular' => true ) );
			$embed = \get_embed_template();
		} finally {
			foreach ( $filters as $filter ) {
				\remove_filter( $filter[0], $filter[1], 10 );
			}
			\remove_filter( 'stylesheet_directory', $stylesheet_directory_filter, 10 );
			\remove_filter( 'template_directory', $template_directory_filter, 10 );
			if ( null !== $meta_filter ) {
				\remove_filter( 'get_post_metadata', $meta_filter, 10 );
			}
		}

		$selected = array(
			'archive' => $archive,
			'page'    => $page,
			'search'  => $search,
			'404'     => $not_found,
			'embed'   => $embed,
		);
		$expected_basenames = array(
			'archive' => $archive_template,
			'page'    => $page_template,
			'search'  => 'search.php',
			'404'     => '404.php',
			'embed'   => $embed_template,
		);
		self::collect_failure(
			$failures,
			$expected_basenames === self::path_basenames( $selected )
				&& self::all_paths_in_theme_roots( $selected, $case ),
			'direct template helpers select expected basenames and stay confined to generated theme paths',
			array(
				'selected' => self::relative_paths_to_root( $selected, $case['paths']['root'] ),
				'expected' => $expected_basenames,
			)
		);

		$expected_hierarchies = array(
			'archive' => array( $archive_template, 'archive.php' ),
			'page'    => array( $page_template, "page-{$page_slug_raw}.php", "page-{$page_id}.php", 'page.php' ),
			'search'  => array( 'search.php' ),
			'404'     => array( '404.php' ),
			'embed'   => array( $embed_template, 'embed.php' ),
		);
		$template_contracts   = array();
		foreach ( $expected_hierarchies as $type => $templates ) {
			$type_name      = (string) $type;
			$hierarchy_seen = $observed[ $type ]['hierarchy'][0] ?? null;
			$template_seen  = $observed[ $type ]['template'][0] ?? null;
			$template_contracts[ $type ] = array(
				'hierarchy' => $hierarchy_seen,
				'template'  => $template_seen,
			);
			self::collect_failure(
				$failures,
				$templates === $hierarchy_seen
					&& is_array( $template_seen )
					&& $type_name === $template_seen['type']
					&& $templates === $template_seen['templates']
					&& ( $selected[ $type ] ?? null ) === $template_seen['template'],
				"{$type_name} template filters receive local hierarchy and selected path arguments",
				array(
					'expected' => $templates,
					'seen'     => $template_contracts[ $type ],
				)
			);
		}

		return self::result(
			'template-hierarchy.direct-template-helper-paths-and-filters',
			$failures,
			array(
				'selected'  => self::relative_paths_to_root( $selected, $case['paths']['root'] ),
				'contracts' => $template_contracts,
			)
		);
	}

	private static function check_rich_direct_template_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_template_globals();

		$failures = array();
		$observed = array();
		$filters  = array();
		$types    = array( 'author', 'date', 'home', 'frontpage', 'privacypolicy', 'singular', 'attachment' );

		foreach ( $types as $type ) {
			$hierarchy_filter = static function ( array $templates ) use ( &$observed, $type ): array {
				$observed[ $type ]['hierarchy'][] = $templates;
				return $templates;
			};
			$template_filter  = static function ( string $template, string $seen_type, array $templates ) use ( &$observed, $type ): string {
				$observed[ $type ]['template'][] = array(
					'template'  => $template,
					'type'      => $seen_type,
					'templates' => $templates,
				);
				return $template;
			};

			\add_filter( "{$type}_template_hierarchy", $hierarchy_filter );
			\add_filter( "{$type}_template", $template_filter, 10, 3 );
			$filters[] = array( "{$type}_template_hierarchy", $hierarchy_filter );
			$filters[] = array( "{$type}_template", $template_filter );
		}

		try {
			$author_id       = $ctx->fork( 'author-id' )->int( 100, 9999 );
			$author_nicename = 'author-' . self::safe_fragment( $ctx->fork( 'author-name' ), 8 );
			$author          = self::make_user( $author_id, $author_nicename );
			$author_template = "author-{$author_nicename}.php";
			self::write_template_file( $case['paths']['child'] . '/' . $author_template, 'author-nicename' );
			self::write_template_file( $case['paths']['parent'] . "/author-{$author_id}.php", 'author-id' );
			self::write_template_file( $case['paths']['parent'] . '/author.php', 'author-fallback' );
			self::set_queried_object( $author, array( 'is_author' => true, 'is_archive' => true ) );
			$author_selected = \get_author_template();

			self::write_template_file( $case['paths']['child'] . '/date.php', 'date-child' );
			self::set_query_context( array( 'year' => '2026' ), null, array( 'is_date' => true, 'is_archive' => true ) );
			$date_selected = \get_date_template();

			self::write_template_file( $case['paths']['child'] . '/home.php', 'home-child' );
			self::write_template_file( $case['paths']['parent'] . '/home.php', 'home-parent' );
			self::set_query_context( array(), null, array( 'is_home' => true ) );
			$home_selected = \get_home_template();

			self::write_template_file( $case['paths']['child'] . '/front-page.php', 'frontpage-child' );
			self::write_template_file( $case['paths']['parent'] . '/front-page.php', 'frontpage-parent' );
			self::set_query_context( array(), null, array( 'is_front_page' => true ) );
			$frontpage_selected = \get_front_page_template();

			self::write_template_file( $case['paths']['child'] . '/privacy-policy.php', 'privacy-child' );
			self::write_template_file( $case['paths']['parent'] . '/privacy-policy.php', 'privacy-parent' );
			self::set_query_context( array(), null, array( 'is_privacy_policy' => true ) );
			$privacypolicy_selected = \get_privacy_policy_template();

			$singular_post = self::make_post(
				$ctx->fork( 'singular-id' )->int( 20000, 29999 ),
				'story',
				'singular-' . self::safe_fragment( $ctx->fork( 'singular-name' ), 6 )
			);
			self::write_template_file( $case['paths']['child'] . '/singular.php', 'singular-child' );
			self::write_template_file( $case['paths']['parent'] . '/singular.php', 'singular-parent' );
			self::set_query_context( array(), $singular_post, array( 'is_singular' => true ) );
			$singular_selected = \get_singular_template();

			$attachment_mime = 'image/jpeg';
			list( $attachment_type, $attachment_subtype ) = explode( '/', $attachment_mime, 2 );
			$attachment_post     = self::make_post(
				$ctx->fork( 'attachment-id' )->int( 30000, 39999 ),
				'attachment',
				'attachment-' . self::safe_fragment( $ctx->fork( 'attachment-name' ), 6 ),
				$attachment_mime
			);
			$attachment_template = "{$attachment_type}-{$attachment_subtype}.php";
			self::write_template_file( $case['paths']['child'] . '/' . $attachment_template, 'attachment-mime-subtype' );
			self::write_template_file( $case['paths']['parent'] . "/{$attachment_subtype}.php", 'attachment-subtype' );
			self::write_template_file( $case['paths']['parent'] . "/{$attachment_type}.php", 'attachment-type' );
			self::write_template_file( $case['paths']['parent'] . '/attachment.php', 'attachment-fallback' );
			self::set_query_context(
				array(),
				$attachment_post,
				array(
					'is_attachment' => true,
					'is_singular'   => true,
				)
			);
			$attachment_selected = \get_attachment_template();
		} finally {
			foreach ( $filters as $filter ) {
				\remove_filter( $filter[0], $filter[1], 10 );
			}
		}

		$selected = array(
			'author'        => $author_selected,
			'date'          => $date_selected,
			'home'          => $home_selected,
			'frontpage'     => $frontpage_selected,
			'privacypolicy' => $privacypolicy_selected,
			'singular'      => $singular_selected,
			'attachment'    => $attachment_selected,
		);

		$expected_selected = array(
			'author'        => $case['paths']['child'] . '/' . $author_template,
			'date'          => $case['paths']['child'] . '/date.php',
			'home'          => $case['paths']['child'] . '/home.php',
			'frontpage'     => $case['paths']['child'] . '/front-page.php',
			'privacypolicy' => $case['paths']['child'] . '/privacy-policy.php',
			'singular'      => $case['paths']['child'] . '/singular.php',
			'attachment'    => $case['paths']['child'] . '/' . $attachment_template,
		);
		self::collect_failure(
			$failures,
			$expected_selected === $selected
				&& self::all_paths_in_theme_roots( $selected, $case ),
			'rich direct template helpers select exact generated child paths and stay confined to generated theme roots',
			array(
				'selected' => self::relative_paths_to_root( $selected, $case['paths']['root'] ),
				'expected' => self::relative_paths_to_root( $expected_selected, $case['paths']['root'] ),
			)
		);

		$expected_hierarchies = array(
			'author'        => array( $author_template, "author-{$author_id}.php", 'author.php' ),
			'date'          => array( 'date.php' ),
			'home'          => array( 'home.php', 'index.php' ),
			'frontpage'     => array( 'front-page.php' ),
			'privacypolicy' => array( 'privacy-policy.php' ),
			'singular'      => array( 'singular.php' ),
			'attachment'    => array( $attachment_template, "{$attachment_subtype}.php", "{$attachment_type}.php", 'attachment.php' ),
		);
		$template_contracts   = array();

		foreach ( $expected_hierarchies as $type => $templates ) {
			$hierarchy_events = $observed[ $type ]['hierarchy'] ?? array();
			$template_events  = $observed[ $type ]['template'] ?? array();
			$hierarchy_seen   = $hierarchy_events[0] ?? null;
			$template_seen    = $template_events[0] ?? null;

			$template_contracts[ $type ] = array(
				'hierarchy' => $hierarchy_seen,
				'template'  => $template_seen,
			);

			self::collect_failure(
				$failures,
				1 === count( $hierarchy_events )
					&& 1 === count( $template_events )
					&& $templates === $hierarchy_seen
					&& is_array( $template_seen )
					&& $type === $template_seen['type']
					&& $templates === $template_seen['templates']
					&& ( $selected[ $type ] ?? null ) === $template_seen['template'],
				"{$type} rich direct template filters receive exact hierarchy, type, and selected path",
				array(
					'expected' => $templates,
					'seen'     => array(
						'hierarchy' => $hierarchy_events,
						'template'  => $template_events,
					),
				)
			);
		}

		return self::result(
			'template-hierarchy.rich-direct-template-helper-paths-and-filters',
			$failures,
			array(
				'mimeType'  => $attachment_mime,
				'selected'  => self::relative_paths_to_root( $selected, $case['paths']['root'] ),
				'contracts' => $template_contracts,
			)
		);
	}

	private static function check_get_single_template_hierarchy( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_template_globals();

		$failures = array();
		$post     = (object) array(
			'ID'                  => $ctx->int( 1000, 9999 ),
			'post_author'         => 1,
			'post_date'           => '2026-06-23 12:00:00',
			'post_date_gmt'       => '2026-06-23 10:00:00',
			'post_content'        => 'Template hierarchy host',
			'post_title'          => 'Template hierarchy host',
			'post_excerpt'        => '',
			'post_status'         => 'publish',
			'comment_status'      => 'open',
			'ping_status'         => 'closed',
			'post_password'       => '',
			'post_name'           => 'story-' . self::safe_fragment( $ctx->fork( 'name' ), 7 ),
			'to_ping'             => '',
			'pinged'              => '',
			'post_modified'       => '2026-06-23 12:00:00',
			'post_modified_gmt'   => '2026-06-23 10:00:00',
			'post_content_filtered' => '',
			'post_parent'         => 0,
			'guid'                => 'https://example.test/template-hierarchy',
			'menu_order'          => 0,
			'post_type'           => 'book',
			'post_mime_type'      => '',
			'comment_count'       => 0,
			'filter'              => 'raw',
		);
		$query    = new \WP_Query();
		$query->is_singular = true;
		$query->post        = new \WP_Post( $post );
		$GLOBALS['wp_query'] = $query;
		$GLOBALS['post']     = $query->post;

		$slug_template = 'single-book-' . $post->post_name . '.php';
		$fallback      = 'single-book.php';
		self::write_template_file( $case['paths']['child'] . '/' . $slug_template, 'single-slug' );
		self::write_template_file( $case['paths']['parent'] . '/' . $fallback, 'single-type-parent' );

		$meta_filter = static function ( $value, $object_id, $meta_key, $single ) use ( $post ) {
			if ( (int) $object_id === (int) $post->ID && '_wp_page_template' === $meta_key && $single ) {
				return 'default';
			}
			return $value;
		};
		\add_filter( 'get_post_metadata', $meta_filter, 10, 4 );
		try {
			$located = \get_single_template();
		} finally {
			\remove_filter( 'get_post_metadata', $meta_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$located === $case['paths']['child'] . '/' . $slug_template,
			'get_single_template prefers post-type/post-name template before post-type fallback',
			array(
				'located'      => self::relative_to_root( $located, $case['paths']['root'] ),
				'slugTemplate' => $slug_template,
				'fallback'     => $fallback,
			)
		);

		return self::result(
			'template-hierarchy.single-template-order',
			$failures,
			array(
				'postType' => $post->post_type,
				'postName' => $post->post_name,
			)
		);
	}

	private static function check_term_template_hierarchy_decoding( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_template_globals();

		$failures = array();
		$observed = array();
		$filters  = array();

		foreach ( array( 'category', 'tag', 'taxonomy' ) as $type ) {
			$hierarchy_filter = static function ( array $templates ) use ( &$observed, $type ): array {
				$observed[ $type ]['hierarchy'][] = $templates;
				return $templates;
			};
			$template_filter  = static function ( string $template, string $seen_type, array $templates ) use ( &$observed, $type ): string {
				$observed[ $type ]['template'][] = array(
					'template'  => $template,
					'type'      => $seen_type,
					'templates' => $templates,
				);
				return $template;
			};

			\add_filter( "{$type}_template_hierarchy", $hierarchy_filter );
			\add_filter( "{$type}_template", $template_filter, 10, 3 );
			$filters[] = array( "{$type}_template_hierarchy", $hierarchy_filter );
			$filters[] = array( "{$type}_template", $template_filter );
		}

		try {
			$category_id      = $ctx->fork( 'category-id' )->int( 100, 999 );
			$category_raw     = 'cat-' . self::safe_fragment( $ctx->fork( 'category-a' ), 5 ) . '%c3%a9-' . self::safe_fragment( $ctx->fork( 'category-b' ), 5 );
			$category_decoded = urldecode( $category_raw );
			self::write_template_file( $case['paths']['child'] . "/category-{$category_decoded}.php", 'category-decoded' );
			self::write_template_file( $case['paths']['parent'] . "/category-{$category_raw}.php", 'category-raw' );
			self::write_template_file( $case['paths']['parent'] . "/category-{$category_id}.php", 'category-id' );
			self::write_template_file( $case['paths']['parent'] . '/category.php', 'category-fallback' );
			self::set_queried_object(
				(object) array(
					'term_id'  => $category_id,
					'slug'     => $category_raw,
					'taxonomy' => 'category',
				),
				array( 'is_category' => true, 'is_archive' => true )
			);
			$category = \get_category_template();

			$tag_id      = $ctx->fork( 'tag-id' )->int( 1000, 1999 );
			$tag_raw     = 'tag-' . self::safe_fragment( $ctx->fork( 'tag-a' ), 5 ) . '%c3%a9-' . self::safe_fragment( $ctx->fork( 'tag-b' ), 5 );
			$tag_decoded = urldecode( $tag_raw );
			self::write_template_file( $case['paths']['child'] . "/tag-{$tag_raw}.php", 'tag-raw' );
			self::write_template_file( $case['paths']['parent'] . "/tag-{$tag_id}.php", 'tag-id' );
			self::write_template_file( $case['paths']['parent'] . '/tag.php', 'tag-fallback' );
			self::set_queried_object(
				(object) array(
					'term_id'  => $tag_id,
					'slug'     => $tag_raw,
					'taxonomy' => 'post_tag',
				),
				array( 'is_tag' => true, 'is_archive' => true )
			);
			$tag = \get_tag_template();

			$taxonomy      = 'genre-' . self::safe_fragment( $ctx->fork( 'taxonomy' ), 6 );
			$taxonomy_id   = $ctx->fork( 'taxonomy-id' )->int( 2000, 2999 );
			$taxonomy_raw  = 'topic-' . self::safe_fragment( $ctx->fork( 'tax-a' ), 5 ) . '%c3%a9-' . self::safe_fragment( $ctx->fork( 'tax-b' ), 5 );
			$taxonomy_decoded = urldecode( $taxonomy_raw );
			self::write_template_file( $case['paths']['child'] . "/taxonomy-{$taxonomy}-{$taxonomy_id}.php", 'taxonomy-id' );
			self::write_template_file( $case['paths']['parent'] . "/taxonomy-{$taxonomy}.php", 'taxonomy-type' );
			self::write_template_file( $case['paths']['parent'] . '/taxonomy.php', 'taxonomy-fallback' );
			self::set_queried_object(
				(object) array(
					'term_id'  => $taxonomy_id,
					'slug'     => $taxonomy_raw,
					'taxonomy' => $taxonomy,
				),
				array( 'is_tax' => true, 'is_archive' => true )
			);
			$taxonomy_template = \get_taxonomy_template();
		} finally {
			foreach ( $filters as $filter ) {
				\remove_filter( $filter[0], $filter[1], 10 );
			}
		}

		$selected = array(
			'category' => $category,
			'tag'      => $tag,
			'taxonomy' => $taxonomy_template,
		);
		$expected_basenames = array(
			'category' => "category-{$category_decoded}.php",
			'tag'      => "tag-{$tag_raw}.php",
			'taxonomy' => "taxonomy-{$taxonomy}-{$taxonomy_id}.php",
		);
		self::collect_failure(
			$failures,
			$expected_basenames === self::path_basenames( $selected )
				&& self::all_paths_in_theme_roots( $selected, $case ),
			'term template helpers select decoded, raw, and term-ID templates in priority order',
			array(
				'selected' => self::relative_paths_to_root( $selected, $case['paths']['root'] ),
				'expected' => $expected_basenames,
			)
		);

		$expected_hierarchies = array(
			'category' => array( "category-{$category_decoded}.php", "category-{$category_raw}.php", "category-{$category_id}.php", 'category.php' ),
			'tag'      => array( "tag-{$tag_decoded}.php", "tag-{$tag_raw}.php", "tag-{$tag_id}.php", 'tag.php' ),
			'taxonomy' => array( "taxonomy-{$taxonomy}-{$taxonomy_decoded}.php", "taxonomy-{$taxonomy}-{$taxonomy_raw}.php", "taxonomy-{$taxonomy}-{$taxonomy_id}.php", "taxonomy-{$taxonomy}.php", 'taxonomy.php' ),
		);

		foreach ( $expected_hierarchies as $type => $templates ) {
			$hierarchy_seen = $observed[ $type ]['hierarchy'][0] ?? null;
			$template_seen  = $observed[ $type ]['template'][0] ?? null;
			self::collect_failure(
				$failures,
				$templates === $hierarchy_seen
					&& is_array( $template_seen )
					&& $type === $template_seen['type']
					&& $templates === $template_seen['templates']
					&& ( $selected[ $type ] ?? null ) === $template_seen['template'],
				"{$type} term template filters receive decoded/raw/id hierarchy and selected path",
				array(
					'expected' => $templates,
					'seen'     => array(
						'hierarchy' => $hierarchy_seen,
						'template'  => $template_seen,
					),
				)
			);
		}

		return self::result(
			'template-hierarchy.term-template-decoding-and-id-order',
			$failures,
			array(
				'selected' => self::relative_paths_to_root( $selected, $case['paths']['root'] ),
			)
		);
	}

	private static function check_load_template_include_semantics( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$file     = $case['paths']['child'] . '/load-probe-' . self::safe_fragment( $ctx->fork( 'file' ), 8 ) . '.php';
		$events   = array();
		$before   = static function ( string $template_file, bool $load_once, array $args ) use ( &$events ): void {
			$events[] = array(
				'hook'      => 'before',
				'file'      => basename( $template_file ),
				'loadOnce'  => $load_once,
				'argsToken' => $args['token'] ?? null,
			);
		};
		$after    = static function ( string $template_file, bool $load_once, array $args ) use ( &$events ): void {
			$events[] = array(
				'hook'      => 'after',
				'file'      => basename( $template_file ),
				'loadOnce'  => $load_once,
				'argsToken' => $args['token'] ?? null,
			);
		};

		self::write_load_probe_file( $file );
		$GLOBALS['wp_query'] = new \WP_Query();
		$GLOBALS['wp_query']->query_vars = array(
			's'         => '<search ' . $case['token'] . '>',
			'cfz_local' => 'query-local-' . $case['token'],
		);
		unset( $GLOBALS['cfz_template_hierarchy_load_probe'] );

		\add_action( 'wp_before_load_template', $before, 10, 3 );
		\add_action( 'wp_after_load_template', $after, 10, 3 );
		try {
			$out_once_first  = self::capture_output(
				static function () use ( $file, $case ): void {
					\load_template( $file, true, array( 'token' => $case['token'], 'step' => 'once-first' ) );
				}
			);
			$out_once_second = self::capture_output(
				static function () use ( $file, $case ): void {
					\load_template( $file, true, array( 'token' => $case['token'], 'step' => 'once-second' ) );
				}
			);
			$out_again       = self::capture_output(
				static function () use ( $file, $case ): void {
					\load_template( $file, false, array( 'token' => $case['token'], 'step' => 'require' ) );
				}
			);
		} finally {
			\remove_action( 'wp_before_load_template', $before, 10 );
			\remove_action( 'wp_after_load_template', $after, 10 );
		}

		$loads = $GLOBALS['cfz_template_hierarchy_load_probe'] ?? array();
		self::collect_failure(
			$failures,
			1 === substr_count( $out_once_first, 'load-probe:' )
				&& '' === $out_once_second
				&& 1 === substr_count( $out_again, 'load-probe:' )
				&& 2 === count( $loads )
				&& 'once-first' === ( $loads[0]['args']['step'] ?? null )
				&& 'require' === ( $loads[1]['args']['step'] ?? null )
				&& '&lt;search ' . $case['token'] . '&gt;' === ( $loads[0]['s'] ?? null )
				&& 'query-local-' . $case['token'] === ( $loads[0]['cfzLocal'] ?? null ),
			'load_template honors require_once vs require and exposes args plus sanitized query vars',
			array(
				'outOnceFirst'  => self::describe_string( $out_once_first ),
				'outOnceSecond' => self::describe_string( $out_once_second ),
				'outAgain'      => self::describe_string( $out_again ),
				'loads'         => $loads,
			)
		);

		self::collect_failure(
			$failures,
			6 === count( $events )
				&& array( 'before', 'after', 'before', 'after', 'before', 'after' ) === array_column( $events, 'hook' )
				&& array( true, true, true, true, false, false ) === array_column( $events, 'loadOnce' ),
			'load_template before/after hooks fire for each call with load_once metadata',
			array( 'events' => $events )
		);

		unset( $GLOBALS['cfz_template_hierarchy_load_probe'] );

		return self::result(
			'template-hierarchy.load-template-args-hooks-once',
			$failures,
			array( 'file' => basename( $file ) )
		);
	}

	private static function check_get_template_part_hooks_and_args( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_template_globals();

		$failures = array();
		$slug     = 'parts/card-' . self::safe_fragment( $ctx->fork( 'slug' ), 6 );
		$name     = 'special-' . self::safe_fragment( $ctx->fork( 'name' ), 6 );
		$args     = array(
			'token' => $case['token'],
			'mode'  => 'template-part',
		);
		$events   = array();
		$dynamic  = static function ( string $seen_slug, ?string $seen_name, array $seen_args ) use ( &$events ): void {
			$events[] = array(
				'hook' => 'dynamic',
				'slug' => $seen_slug,
				'name' => $seen_name,
				'args' => $seen_args,
			);
		};
		$generic  = static function ( string $seen_slug, string $seen_name, array $templates, array $seen_args ) use ( &$events ): void {
			$events[] = array(
				'hook'      => 'generic',
				'slug'      => $seen_slug,
				'name'      => $seen_name,
				'templates' => $templates,
				'args'      => $seen_args,
			);
		};

		self::write_template_file( $case['paths']['child'] . '/' . $slug . '-' . $name . '.php', 'part-special' );
		self::write_template_file( $case['paths']['child'] . '/' . $slug . '.php', 'part-generic' );

		unset( $GLOBALS['cfz_template_hierarchy_loaded'] );
		\add_action( "get_template_part_{$slug}", $dynamic, 10, 3 );
		\add_action( 'get_template_part', $generic, 10, 4 );
		try {
			$output = self::capture_output(
				static function () use ( $slug, $name, $args ): void {
					\get_template_part( $slug, $name, $args );
				}
			);
		} finally {
			\remove_action( "get_template_part_{$slug}", $dynamic, 10 );
			\remove_action( 'get_template_part', $generic, 10 );
		}

		$loaded = $GLOBALS['cfz_template_hierarchy_loaded'] ?? array();
		self::collect_failure(
			$failures,
			str_contains( $output, 'template:part-special:' . $case['token'] )
				&& ! str_contains( $output, 'template:part-generic:' )
				&& 1 === count( $loaded )
				&& 'part-special' === ( $loaded[0]['label'] ?? null )
				&& $args === ( $loaded[0]['args'] ?? null )
				&& 2 === count( $events )
				&& 'dynamic' === ( $events[0]['hook'] ?? null )
				&& 'generic' === ( $events[1]['hook'] ?? null )
				&& $slug === ( $events[1]['slug'] ?? null )
				&& $name === ( $events[1]['name'] ?? null )
				&& array( "{$slug}-{$name}.php", "{$slug}.php" ) === ( $events[1]['templates'] ?? null ),
			'get_template_part fires hooks, passes args, and loads specialized template before generic',
			array(
				'output' => self::describe_string( $output ),
				'events' => $events,
				'loaded' => $loaded,
			)
		);

		unset( $GLOBALS['cfz_template_hierarchy_loaded'] );

		return self::result(
			'template-hierarchy.get-template-part-hooks-and-specialization',
			$failures,
			array(
				'slug' => $slug,
				'name' => $name,
			)
		);
	}

	private static function check_comments_template_guard( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$missing                = self::missing_comments_template_guard_requirements();
		$parent_constant_before = self::comments_template_constant_state();

		if ( array() !== $missing ) {
			$parent_constant_after = self::comments_template_constant_state();
			return $ctx->skip(
				'template-hierarchy.comments-template-guarded',
				'Child-process support for isolated comments_template() coverage is unavailable.',
				array(
					'missing'                 => implode( ', ', $missing ),
					'parentConstantBefore'    => $parent_constant_before,
					'parentConstantAfter'     => $parent_constant_after,
					'parentConstantUnchanged' => $parent_constant_before === $parent_constant_after,
				)
			);
		}

		$custom_basename = 'comments-' . self::safe_fragment( $ctx->fork( 'custom-file' ), 8 ) . '.php';
		$post_id         = $ctx->fork( 'post-id' )->int( 40000, 49999 );
		$comment_ids     = array(
			$ctx->fork( 'comment-one' )->int( 50000, 59999 ),
			$ctx->fork( 'comment-two' )->int( 60000, 69999 ),
		);

		self::write_comments_template_file( $case['paths']['child'] . '/comments.php', 'child-comments', $case['token'] );
		self::write_comments_template_file( $case['paths']['parent'] . '/comments.php', 'parent-comments', $case['token'] );
		self::write_comments_template_file( $case['paths']['child'] . '/' . $custom_basename, 'child-custom-comments', $case['token'] );

		$fixture = array(
			'repoRoot' => \ComponentFuzz\repo_root(),
			'token'    => $case['token'],
			'paths'    => $case['paths'],
			'theme'    => $case['theme'],
			'files'    => array(
				'defaultFile'   => '/comments.php',
				'customFile'    => '/' . $custom_basename,
				'childDefault'  => $case['paths']['child'] . '/comments.php',
				'parentDefault' => $case['paths']['parent'] . '/comments.php',
				'childCustom'   => $case['paths']['child'] . '/' . $custom_basename,
			),
			'post'     => array(
				'ID'             => $post_id,
				'post_type'      => 'page',
				'post_name'      => 'comments-template-' . self::safe_fragment( $ctx->fork( 'post-name' ), 7 ),
				'post_status'    => 'publish',
				'comment_status' => 'open',
			),
			'comments' => array(
				self::comments_template_comment_fixture( $comment_ids[0], $post_id, 'first', $case['token'] ),
				self::comments_template_comment_fixture( $comment_ids[1], $post_id, 'second', $case['token'] ),
			),
			'expected' => array(
				'postId'      => $post_id,
				'commentIds'  => $comment_ids,
				'queryStatus' => 'approve',
				'queryOrder'  => 'ASC',
			),
		);

		$worker                = self::run_child_comments_template_guard( $fixture );
		$parent_constant_after = self::comments_template_constant_state();
		$failures              = array();
		$child_result          = is_array( $worker['result'] ?? null ) ? $worker['result'] : array();

		self::collect_failure(
			$failures,
			$parent_constant_before === $parent_constant_after,
			'comments_template() child coverage leaves the parent COMMENTS_TEMPLATE constant unchanged',
			array(
				'before' => $parent_constant_before,
				'after'  => $parent_constant_after,
			)
		);

		self::collect_failure(
			$failures,
			! empty( $worker['ok'] ),
			'comments_template() child process exits successfully and reports a passing JSON result',
			array(
				'exitCode' => $worker['exitCode'] ?? null,
				'stdout'   => self::describe_string( (string) ( $worker['stdout'] ?? '' ) ),
				'stderr'   => self::describe_string( (string) ( $worker['stderr'] ?? '' ) ),
				'result'   => $child_result,
			)
		);

		if ( is_array( $worker['result'] ?? null ) ) {
			self::collect_failure(
				$failures,
				self::comments_template_guard_child_result_has_expected_shape( $child_result ),
				'comments_template() child result has the expected shape',
				array( 'resultKeys' => array_keys( $child_result ) )
			);

			self::collect_failure(
				$failures,
				false === ( $child_result['commentsTemplateDefinedBefore'] ?? null )
					&& true === ( $child_result['commentsTemplateDefinedAfter'] ?? null ),
				'comments_template() defines COMMENTS_TEMPLATE in the isolated child process',
				array(
					'before' => $child_result['commentsTemplateDefinedBefore'] ?? null,
					'after'  => $child_result['commentsTemplateDefinedAfter'] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				'' === ( $worker['stderr'] ?? '' )
					&& '' === ( $child_result['unexpectedOutput'] ?? null ),
				'comments_template() child emits no stderr or stray stdout beyond captured template output',
				array(
					'stderr'          => self::describe_string( (string) ( $worker['stderr'] ?? '' ) ),
					'unexpectedOutput' => $child_result['unexpectedOutput'] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				true === ( $child_result['childStateRestored'] ?? null ),
				'comments_template() child restores tracked globals, hooks, and output buffers before exit',
				array( 'childStateRestored' => $child_result['childStateRestored'] ?? null )
			);

			self::collect_comments_template_child_failures( $failures, $child_result, $fixture );
		}

		return self::result(
			'template-hierarchy.comments-template-guarded',
			$failures,
			array(
				'parentConstantBefore' => $parent_constant_before,
				'parentConstantAfter'  => $parent_constant_after,
				'childExitCode'        => $worker['exitCode'] ?? null,
				'childStdout'          => self::describe_string( (string) ( $worker['stdout'] ?? '' ) ),
				'childStderr'          => self::describe_string( (string) ( $worker['stderr'] ?? '' ) ),
				'templates'            => self::relative_paths_to_root(
					array(
						'childDefault'  => $fixture['files']['childDefault'],
						'parentDefault' => $fixture['files']['parentDefault'],
						'childCustom'   => $fixture['files']['childCustom'],
					),
					$case['paths']['root']
				),
				'filterEvents'         => array_slice( $child_result['filterEvents'] ?? array(), 0, 4 ),
				'queryEvents'          => self::summarize_comments_template_query_events( $child_result['queryEvents'] ?? array() ),
				'templateLoads'        => array_slice( $child_result['templateLoads'] ?? array(), 0, 4 ),
			)
		);
	}

	private static function missing_comments_template_guard_requirements(): array {
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

	private static function comments_template_constant_state(): array {
		return array(
			'defined' => defined( 'COMMENTS_TEMPLATE' ),
			'value'   => defined( 'COMMENTS_TEMPLATE' ) ? constant( 'COMMENTS_TEMPLATE' ) : null,
		);
	}

	private static function comments_template_comment_fixture( int $comment_id, int $post_id, string $label, string $token ): array {
		return array(
			'comment_ID'           => $comment_id,
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'Template ' . $label,
			'comment_author_email' => $label . '-' . $token . '@example.test',
			'comment_author_url'   => 'https://example.test/' . $label,
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2026-06-23 12:00:00',
			'comment_date_gmt'     => '2026-06-23 10:00:00',
			'comment_content'      => 'Synthetic comments_template comment ' . $label . ' ' . $token,
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => 'ComponentFuzz',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
		);
	}

	private static function run_child_comments_template_guard( array $fixture ): array {
		$payload = json_encode( $fixture, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates COMMENTS_TEMPLATE in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::comments_template_guard_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function comments_template_guard_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& is_bool( $result['ok'] )
			&& array_key_exists( 'commentsTemplateDefinedBefore', $result )
			&& is_bool( $result['commentsTemplateDefinedBefore'] )
			&& array_key_exists( 'commentsTemplateDefinedAfter', $result )
			&& is_bool( $result['commentsTemplateDefinedAfter'] )
			&& array_key_exists( 'filterEvents', $result )
			&& is_array( $result['filterEvents'] )
			&& array_key_exists( 'queryEvents', $result )
			&& is_array( $result['queryEvents'] )
			&& array_key_exists( 'commentsArrayEvents', $result )
			&& is_array( $result['commentsArrayEvents'] )
			&& array_key_exists( 'templateLoads', $result )
			&& is_array( $result['templateLoads'] )
			&& array_key_exists( 'calls', $result )
			&& is_array( $result['calls'] )
			&& array_key_exists( 'childStateRestored', $result )
			&& is_bool( $result['childStateRestored'] )
			&& array_key_exists( 'unexpectedOutput', $result )
			&& is_string( $result['unexpectedOutput'] );
	}

	private static function collect_comments_template_child_failures( array &$failures, array $result, array $fixture ): void {
		$expected_default_filter = $fixture['paths']['child'] . '//comments.php';
		$expected_custom_filter  = $fixture['paths']['child'] . '/' . $fixture['files']['customFile'];
		$expected_child_default  = realpath( $fixture['files']['childDefault'] ) ?: $fixture['files']['childDefault'];
		$expected_child_custom   = realpath( $fixture['files']['childCustom'] ) ?: $fixture['files']['childCustom'];
		$expected_comment_ids    = array_map( 'intval', $fixture['expected']['commentIds'] );
		$filter_events           = $result['filterEvents'] ?? array();
		$template_loads          = $result['templateLoads'] ?? array();
		$query_events            = $result['queryEvents'] ?? array();
		$comments_array_events   = $result['commentsArrayEvents'] ?? array();
		$calls                   = $result['calls'] ?? array();

		self::collect_failure(
			$failures,
			2 === count( $filter_events )
				&& $expected_default_filter === ( $filter_events[0]['received'] ?? null )
				&& $expected_default_filter === ( $filter_events[0]['returned'] ?? null )
				&& $expected_custom_filter === ( $filter_events[1]['received'] ?? null )
				&& $expected_custom_filter === ( $filter_events[1]['returned'] ?? null ),
			'comments_template filter receives and returns the expected generated child paths',
			array(
				'expected' => array( $expected_default_filter, $expected_custom_filter ),
				'seen'     => $filter_events,
			)
		);

		self::collect_failure(
			$failures,
			2 === count( $template_loads )
				&& 'child-comments' === ( $template_loads[0]['label'] ?? null )
				&& $expected_child_default === ( $template_loads[0]['fileRealpath'] ?? null )
				&& 'child-custom-comments' === ( $template_loads[1]['label'] ?? null )
				&& $expected_child_custom === ( $template_loads[1]['fileRealpath'] ?? null ),
			'comments_template loads the child default before the parent fallback and loads the generated custom child file',
			array(
				'expected' => array( $expected_child_default, $expected_child_custom ),
				'seen'     => $template_loads,
			)
		);

		self::collect_failure(
			$failures,
			self::comments_template_query_events_match( $query_events, $fixture ),
			'comments_template comment queries are short-circuited with the generated post ID, status, and order',
			array(
				'expected' => $fixture['expected'],
				'seen'     => self::summarize_comments_template_query_events( $query_events ),
			)
		);

		self::collect_failure(
			$failures,
			self::comments_template_comment_events_match( $comments_array_events, $expected_comment_ids, (int) $fixture['expected']['postId'] )
				&& self::comments_template_loads_received_comments( $template_loads, $expected_comment_ids ),
			'synthetic comments are passed through comments_array and into the included comments templates',
			array(
				'commentsArrayEvents' => $comments_array_events,
				'templateLoads'       => $template_loads,
				'expectedCommentIds'  => $expected_comment_ids,
			)
		);

		self::collect_failure(
			$failures,
			"comments-template:child-comments:{$fixture['token']}:2\n" === ( $calls['default']['output'] ?? null )
				&& "comments-template:child-custom-comments:{$fixture['token']}:2\n" === ( $calls['custom']['output'] ?? null ),
			'comments_template template output is captured for both default and custom calls',
			array( 'calls' => $calls )
		);
	}

	private static function comments_template_query_events_match( array $query_events, array $fixture ): bool {
		if ( 2 !== count( $query_events ) ) {
			return false;
		}

		foreach ( $query_events as $event ) {
			$query_vars = is_array( $event['queryVars'] ?? null ) ? $event['queryVars'] : array();
			if (
				(int) ( $query_vars['post_id'] ?? 0 ) !== (int) $fixture['expected']['postId']
				|| ( $query_vars['status'] ?? null ) !== $fixture['expected']['queryStatus']
				|| ( $query_vars['order'] ?? null ) !== $fixture['expected']['queryOrder']
				|| false !== ( $query_vars['hierarchical'] ?? null )
			) {
				return false;
			}
		}

		return true;
	}

	private static function comments_template_comment_events_match( array $events, array $expected_comment_ids, int $post_id ): bool {
		if ( 2 !== count( $events ) ) {
			return false;
		}

		foreach ( $events as $event ) {
			if (
				$post_id !== (int) ( $event['postId'] ?? 0 )
				|| $expected_comment_ids !== array_map( 'intval', is_array( $event['commentIds'] ?? null ) ? $event['commentIds'] : array() )
			) {
				return false;
			}
		}

		return true;
	}

	private static function comments_template_loads_received_comments( array $template_loads, array $expected_comment_ids ): bool {
		if ( 2 !== count( $template_loads ) ) {
			return false;
		}

		foreach ( $template_loads as $load ) {
			if (
				2 !== (int) ( $load['commentsCount'] ?? -1 )
				|| 2 !== (int) ( $load['queryCommentCount'] ?? -1 )
				|| $expected_comment_ids !== array_map( 'intval', is_array( $load['commentIds'] ?? null ) ? $load['commentIds'] : array() )
			) {
				return false;
			}
		}

		return true;
	}

	private static function summarize_comments_template_query_events( array $query_events ): array {
		$summary = array();

		foreach ( $query_events as $event ) {
			$query_vars = is_array( $event['queryVars'] ?? null ) ? $event['queryVars'] : array();
			$summary[]  = array(
				'postId'        => $query_vars['post_id'] ?? null,
				'status'        => $query_vars['status'] ?? null,
				'order'         => $query_vars['order'] ?? null,
				'hierarchical'  => $query_vars['hierarchical'] ?? null,
				'returnedIds'   => $event['returnedIds'] ?? array(),
				'maxNumPages'   => $event['maxNumPages'] ?? null,
				'foundComments' => $event['foundComments'] ?? null,
			);
		}

		return $summary;
	}

	private static function comments_template_guard_child_program(): string {
		return <<<'PHP'
ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

function component_fuzz_template_hierarchy_preview( string $value, int $limit = 240 ): string {
	$printable = preg_replace_callback(
		'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
		static function ( array $m ): string {
			return sprintf( '\\x%02X', ord( $m[0] ) );
		},
		$value
	);

	if ( strlen( $printable ) > $limit ) {
		return substr( $printable, 0, $limit ) . '...';
	}

	return $printable;
}

function component_fuzz_template_hierarchy_snapshot(): array {
	$snapshot = array(
		'globals' => array(),
		'obLevel' => ob_get_level(),
	);

	foreach (
		array(
			'wp_filter',
			'wp_actions',
			'wp_filters',
			'wp_current_filter',
			'wp_theme_directories',
			'wp_stylesheet_path',
			'wp_template_path',
			'wp_query',
			'wp_the_query',
			'post',
			'id',
			'comment',
			'user_login',
			'user_identity',
			'withcomments',
			'overridden_cpage',
			'cfz_template_hierarchy_comments_template_loaded',
		) as $name
	) {
		$snapshot['globals'][ $name ] = array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => $GLOBALS[ $name ] ?? null,
		);
	}

	return $snapshot;
}

function component_fuzz_template_hierarchy_restore( array $snapshot ): void {
	foreach ( $snapshot['globals'] as $name => $entry ) {
		if ( $entry['exists'] ) {
			$GLOBALS[ $name ] = $entry['value'];
		} else {
			unset( $GLOBALS[ $name ] );
		}
	}

	if ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( 'theme_roots', 'site-transient' );
	}
	if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
		wp_clean_theme_json_cache();
	}
}

function component_fuzz_template_hierarchy_state_matches( array $snapshot ): bool {
	if ( ob_get_level() !== (int) $snapshot['obLevel'] ) {
		return false;
	}

	foreach ( $snapshot['globals'] as $name => $entry ) {
		$exists = array_key_exists( $name, $GLOBALS );
		if ( $exists !== $entry['exists'] ) {
			return false;
		}
		if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
			return false;
		}
	}

	return true;
}

function component_fuzz_template_hierarchy_reset_template_globals(): void {
	unset( $GLOBALS['wp_stylesheet_path'], $GLOBALS['wp_template_path'] );
	if ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( 'theme_roots', 'site-transient' );
	}
	if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
		wp_clean_theme_json_cache();
	}
}

function component_fuzz_template_hierarchy_install_theme_filters( array $fixture ): void {
	$stylesheet = static function () use ( $fixture ): string {
		return (string) $fixture['theme']['child'];
	};
	$template = static function () use ( $fixture ): string {
		return (string) $fixture['theme']['parent'];
	};
	$theme_root = static function () use ( $fixture ): string {
		return (string) $fixture['paths']['themes'];
	};
	$theme_root_uri = static function () use ( $fixture ): string {
		return (string) $fixture['theme']['uri'];
	};

	$GLOBALS['wp_theme_directories'] = array_values(
		array_unique(
			array_merge(
				is_array( $GLOBALS['wp_theme_directories'] ?? null ) ? $GLOBALS['wp_theme_directories'] : array(),
				array( (string) $fixture['paths']['themes'] )
			)
		)
	);

	add_filter( 'stylesheet', $stylesheet );
	add_filter( 'template', $template );
	add_filter( 'theme_root', $theme_root );
	add_filter( 'theme_root_uri', $theme_root_uri );
	add_filter( 'pre_option_stylesheet', $stylesheet );
	add_filter( 'pre_option_template', $template );
	add_filter( 'pre_option_stylesheet_root', $theme_root );
	add_filter( 'pre_option_template_root', $theme_root );
	component_fuzz_template_hierarchy_reset_template_globals();
	wp_set_template_globals();
}

function component_fuzz_template_hierarchy_make_post( array $data ) {
	if ( ! class_exists( 'WP_Post' ) ) {
		throw new RuntimeException( 'WP_Post is unavailable in comments_template child.' );
	}

	return new WP_Post(
		(object) array(
			'ID'                    => (int) $data['ID'],
			'post_author'           => 1,
			'post_date'             => '2026-06-23 12:00:00',
			'post_date_gmt'         => '2026-06-23 10:00:00',
			'post_content'          => 'Template hierarchy comments_template host',
			'post_title'            => 'Template hierarchy comments_template host',
			'post_excerpt'          => '',
			'post_status'           => (string) $data['post_status'],
			'comment_status'        => (string) $data['comment_status'],
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => (string) $data['post_name'],
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2026-06-23 12:00:00',
			'post_modified_gmt'     => '2026-06-23 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'https://example.test/template-hierarchy-comments-template',
			'menu_order'            => 0,
			'post_type'             => (string) $data['post_type'],
			'post_mime_type'        => '',
			'comment_count'         => 2,
			'filter'                => 'raw',
		)
	);
}

function component_fuzz_template_hierarchy_make_comment( array $data ) {
	$comment = (object) $data;

	if ( class_exists( 'WP_Comment' ) ) {
		return new WP_Comment( $comment );
	}

	return $comment;
}

function component_fuzz_template_hierarchy_comment_ids( $comments ): array {
	$ids = array();
	foreach ( is_array( $comments ) ? $comments : array() as $comment ) {
		if ( is_object( $comment ) && isset( $comment->comment_ID ) ) {
			$ids[] = (int) $comment->comment_ID;
		}
	}

	return $ids;
}

function component_fuzz_template_hierarchy_set_query_context( WP_Post $post ): void {
	$query                    = new WP_Query();
	$query->query_vars        = array(
		'page_id' => (int) $post->ID,
		'p'       => (int) $post->ID,
		'cpage'   => '',
	);
	$query->is_page           = true;
	$query->is_singular       = true;
	$query->queried_object    = $post;
	$query->queried_object_id = (int) $post->ID;
	$query->post              = $post;
	$query->posts             = array( $post );
	$query->post_count        = 1;

	$GLOBALS['wp_query']     = $query;
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['post']         = $post;
	$GLOBALS['id']           = (int) $post->ID;
	$GLOBALS['withcomments'] = true;
}

function component_fuzz_template_hierarchy_capture_output( callable $callback ): string {
	$level = ob_get_level();
	ob_start();
	try {
		$callback();
		return (string) ob_get_clean();
	} catch ( Throwable $e ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		throw $e;
	}
}

$component_fuzz_template_hierarchy_outer_ob_level = ob_get_level();
ob_start();

$component_fuzz_template_hierarchy_snapshot          = null;
$component_fuzz_template_hierarchy_unexpected_output = '';
$component_fuzz_template_hierarchy_result            = array(
	'ok'                            => false,
	'commentsTemplateDefinedBefore' => false,
	'commentsTemplateDefinedAfter'  => false,
	'filterEvents'                  => array(),
	'queryEvents'                   => array(),
	'commentsArrayEvents'           => array(),
	'templateLoads'                 => array(),
	'calls'                         => array(),
	'wpStylesheetPath'              => null,
	'wpTemplatePath'                => null,
	'childStateRestored'            => false,
	'unexpectedOutput'              => '',
);

try {
	$component_fuzz_template_hierarchy_raw     = stream_get_contents( STDIN );
	$component_fuzz_template_hierarchy_fixture = json_decode( $component_fuzz_template_hierarchy_raw, true );

	if (
		! is_array( $component_fuzz_template_hierarchy_fixture )
		|| empty( $component_fuzz_template_hierarchy_fixture['repoRoot'] )
		|| ! is_array( $component_fuzz_template_hierarchy_fixture['paths'] ?? null )
		|| ! is_array( $component_fuzz_template_hierarchy_fixture['theme'] ?? null )
		|| ! is_array( $component_fuzz_template_hierarchy_fixture['files'] ?? null )
		|| ! is_array( $component_fuzz_template_hierarchy_fixture['post'] ?? null )
		|| ! is_array( $component_fuzz_template_hierarchy_fixture['comments'] ?? null )
	) {
		throw new RuntimeException( 'Invalid comments_template fixture.' );
	}

	require_once $component_fuzz_template_hierarchy_fixture['repoRoot'] . '/tools/component-fuzz/lib/autoload.php';

	\ComponentFuzz\WpBootstrap::load();

	$component_fuzz_template_hierarchy_snapshot = component_fuzz_template_hierarchy_snapshot();
	$component_fuzz_template_hierarchy_result['commentsTemplateDefinedBefore'] = defined( 'COMMENTS_TEMPLATE' );

	component_fuzz_template_hierarchy_install_theme_filters( $component_fuzz_template_hierarchy_fixture );

	$component_fuzz_template_hierarchy_false_option = static function () {
		return 0;
	};
	add_filter( 'pre_option_require_name_email', $component_fuzz_template_hierarchy_false_option );
	add_filter( 'pre_option_thread_comments', $component_fuzz_template_hierarchy_false_option );
	add_filter( 'pre_option_page_comments', $component_fuzz_template_hierarchy_false_option );

	$component_fuzz_template_hierarchy_template_filter = static function ( string $theme_template ) use ( &$component_fuzz_template_hierarchy_result ): string {
		$component_fuzz_template_hierarchy_result['filterEvents'][] = array(
			'received' => $theme_template,
			'returned' => $theme_template,
		);
		return $theme_template;
	};
	add_filter( 'comments_template', $component_fuzz_template_hierarchy_template_filter );

	$component_fuzz_template_hierarchy_comments = array();
	foreach ( $component_fuzz_template_hierarchy_fixture['comments'] as $component_fuzz_template_hierarchy_comment ) {
		if ( ! is_array( $component_fuzz_template_hierarchy_comment ) ) {
			throw new RuntimeException( 'Invalid synthetic comment fixture.' );
		}
		$component_fuzz_template_hierarchy_comments[] = component_fuzz_template_hierarchy_make_comment( $component_fuzz_template_hierarchy_comment );
	}

	$component_fuzz_template_hierarchy_comments_pre_query = static function ( $comment_data, WP_Comment_Query $query ) use ( &$component_fuzz_template_hierarchy_result, $component_fuzz_template_hierarchy_comments ): array {
		unset( $comment_data );
		$query->found_comments = count( $component_fuzz_template_hierarchy_comments );
		$query->max_num_pages  = 1;

		$component_fuzz_template_hierarchy_result['queryEvents'][] = array(
			'queryVars'     => $query->query_vars,
			'returnedIds'   => component_fuzz_template_hierarchy_comment_ids( $component_fuzz_template_hierarchy_comments ),
			'foundComments' => $query->found_comments,
			'maxNumPages'   => $query->max_num_pages,
		);

		return $component_fuzz_template_hierarchy_comments;
	};
	add_filter( 'comments_pre_query', $component_fuzz_template_hierarchy_comments_pre_query, 10, 2 );

	$component_fuzz_template_hierarchy_comments_array = static function ( array $comments, int $post_id ) use ( &$component_fuzz_template_hierarchy_result ): array {
		$component_fuzz_template_hierarchy_result['commentsArrayEvents'][] = array(
			'postId'     => $post_id,
			'commentIds' => component_fuzz_template_hierarchy_comment_ids( $comments ),
		);
		return $comments;
	};
	add_filter( 'comments_array', $component_fuzz_template_hierarchy_comments_array, 10, 2 );

	$component_fuzz_template_hierarchy_post = component_fuzz_template_hierarchy_make_post( $component_fuzz_template_hierarchy_fixture['post'] );

	$component_fuzz_template_hierarchy_result['wpStylesheetPath'] = $GLOBALS['wp_stylesheet_path'] ?? null;
	$component_fuzz_template_hierarchy_result['wpTemplatePath']   = $GLOBALS['wp_template_path'] ?? null;

	component_fuzz_template_hierarchy_set_query_context( $component_fuzz_template_hierarchy_post );
	$component_fuzz_template_hierarchy_result['calls']['default'] = array(
		'file'   => $component_fuzz_template_hierarchy_fixture['files']['defaultFile'],
		'output' => component_fuzz_template_hierarchy_capture_output(
			static function (): void {
				comments_template();
			}
		),
	);

	component_fuzz_template_hierarchy_set_query_context( $component_fuzz_template_hierarchy_post );
	$component_fuzz_template_hierarchy_result['calls']['custom'] = array(
		'file'   => $component_fuzz_template_hierarchy_fixture['files']['customFile'],
		'output' => component_fuzz_template_hierarchy_capture_output(
			static function () use ( $component_fuzz_template_hierarchy_fixture ): void {
				comments_template( $component_fuzz_template_hierarchy_fixture['files']['customFile'] );
			}
		),
	);

	$component_fuzz_template_hierarchy_result['commentsTemplateDefinedAfter'] = defined( 'COMMENTS_TEMPLATE' );
	$component_fuzz_template_hierarchy_result['templateLoads']                = $GLOBALS['cfz_template_hierarchy_comments_template_loaded'] ?? array();
	$component_fuzz_template_hierarchy_result['ok']                           = true;
} catch ( Throwable $e ) {
	$component_fuzz_template_hierarchy_result['throwable'] = array(
		'class'   => get_class( $e ),
		'message' => $e->getMessage(),
		'file'    => $e->getFile(),
		'line'    => $e->getLine(),
	);
} finally {
	if ( is_array( $component_fuzz_template_hierarchy_snapshot ) ) {
		component_fuzz_template_hierarchy_restore( $component_fuzz_template_hierarchy_snapshot );
		$component_fuzz_template_hierarchy_result['childStateRestored'] = component_fuzz_template_hierarchy_state_matches( $component_fuzz_template_hierarchy_snapshot );
	}

	while ( ob_get_level() > $component_fuzz_template_hierarchy_outer_ob_level ) {
		$component_fuzz_template_hierarchy_unexpected_output = ob_get_clean() . $component_fuzz_template_hierarchy_unexpected_output;
	}

	$component_fuzz_template_hierarchy_result['unexpectedOutput'] = component_fuzz_template_hierarchy_preview( $component_fuzz_template_hierarchy_unexpected_output );
}

$component_fuzz_template_hierarchy_json = json_encode( $component_fuzz_template_hierarchy_result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
echo false === $component_fuzz_template_hierarchy_json ? '{"ok":false,"error":"json_encode failed"}' : $component_fuzz_template_hierarchy_json;
exit( ! empty( $component_fuzz_template_hierarchy_result['ok'] ) ? 0 : 1 );
PHP;
	}

	private static function prepare_case( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		if ( file_exists( $temp_root ) ) {
			self::remove_dir_recursive( $temp_root );
		}

		$token       = substr( sha1( $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );
		$theme_root  = $temp_root . '/themes';
		$parent_slug = 'cfz-parent-' . self::safe_fragment( $ctx->fork( 'parent' ), 8 );
		$child_slug  = 'cfz-child-' . self::safe_fragment( $ctx->fork( 'child' ), 8 );
		$parent_dir  = $theme_root . '/' . $parent_slug;
		$child_dir   = $theme_root . '/' . $child_slug;

		self::ensure_dir( $parent_dir . '/parts' );
		self::ensure_dir( $child_dir . '/parts' );
		self::write_file( $parent_dir . '/style.css', "/*\nTheme Name: Component Fuzz Parent\n*/\n" );
		self::write_file( $child_dir . '/style.css', "/*\nTheme Name: Component Fuzz Child\nTemplate: {$parent_slug}\n*/\n" );
		self::write_template_file( $parent_dir . '/shared.php', 'parent-shared' );
		self::write_template_file( $child_dir . '/shared.php', 'child-shared' );
		self::write_template_file( $parent_dir . '/parent-only.php', 'parent-only' );
		self::write_template_file( $parent_dir . '/index.php', 'parent-index' );
		self::write_template_file( $child_dir . '/index.php', 'child-index' );

		return array(
			'token' => $token,
			'paths' => array(
				'root'   => $temp_root,
				'themes' => $theme_root,
				'parent' => $parent_dir,
				'child'  => $child_dir,
			),
			'theme' => array(
				'parent' => $parent_slug,
				'child'  => $child_slug,
				'uri'    => 'https://example.test/component-fuzz-template-hierarchy',
			),
		);
	}

	private static function install_theme_filters( array $case ): void {
		$stylesheet = static function () use ( $case ): string {
			return $case['theme']['child'];
		};
		$template = static function () use ( $case ): string {
			return $case['theme']['parent'];
		};
		$theme_root = static function () use ( $case ): string {
			return $case['paths']['themes'];
		};
		$theme_root_uri = static function () use ( $case ): string {
			return $case['theme']['uri'];
		};

		$GLOBALS['wp_theme_directories'] = array_values(
			array_unique(
				array_merge(
					is_array( $GLOBALS['wp_theme_directories'] ?? null ) ? $GLOBALS['wp_theme_directories'] : array(),
					array( $case['paths']['themes'] )
				)
			)
		);

		\add_filter( 'stylesheet', $stylesheet );
		\add_filter( 'template', $template );
		\add_filter( 'theme_root', $theme_root );
		\add_filter( 'theme_root_uri', $theme_root_uri );
		\add_filter( 'pre_option_stylesheet', $stylesheet );
		\add_filter( 'pre_option_template', $template );
		\add_filter( 'pre_option_stylesheet_root', $theme_root );
		\add_filter( 'pre_option_template_root', $theme_root );
		self::reset_template_globals();
		self::ensure_query_context();
	}

	private static function write_template_file( string $path, string $label ): void {
		self::ensure_dir( dirname( $path ) );
		$label_export = var_export( $label, true );
		$body         = <<<'PHP'
<?php
$__cfz_label = LABEL_PLACEHOLDER;
$GLOBALS['cfz_template_hierarchy_loaded'][] = array(
	'label' => $__cfz_label,
	'file'  => __FILE__,
	'args'  => isset( $args ) && is_array( $args ) ? $args : array(),
);
echo 'template:' . $__cfz_label . ':' . ( isset( $args['token'] ) ? $args['token'] : 'no-token' ) . "\n";
PHP;
		self::write_file( $path, str_replace( 'LABEL_PLACEHOLDER', $label_export, $body ) );
	}

	private static function write_comments_template_file( string $path, string $label, string $token ): void {
		self::ensure_dir( dirname( $path ) );
		$label_export = var_export( $label, true );
		$token_export = var_export( $token, true );
		$body         = <<<'PHP'
<?php
$__cfz_label    = LABEL_PLACEHOLDER;
$__cfz_token    = TOKEN_PLACEHOLDER;
$__cfz_comments = isset( $comments ) && is_array( $comments ) ? $comments : array();
$__cfz_ids      = array();
foreach ( $__cfz_comments as $__cfz_comment ) {
	if ( is_object( $__cfz_comment ) && isset( $__cfz_comment->comment_ID ) ) {
		$__cfz_ids[] = (int) $__cfz_comment->comment_ID;
	}
}

$GLOBALS['cfz_template_hierarchy_comments_template_loaded'][] = array(
	'label'             => $__cfz_label,
	'file'              => __FILE__,
	'fileRealpath'      => realpath( __FILE__ ) ?: __FILE__,
	'token'             => $__cfz_token,
	'commentsCount'     => count( $__cfz_comments ),
	'commentIds'        => $__cfz_ids,
	'queryCommentCount' => isset( $wp_query ) && is_object( $wp_query ) && isset( $wp_query->comment_count ) ? (int) $wp_query->comment_count : null,
	'commentArgs'       => isset( $comment_args ) && is_array( $comment_args ) ? $comment_args : array(),
);
echo 'comments-template:' . $__cfz_label . ':' . $__cfz_token . ':' . count( $__cfz_comments ) . "\n";
PHP;
		self::write_file(
			$path,
			str_replace(
				array( 'LABEL_PLACEHOLDER', 'TOKEN_PLACEHOLDER' ),
				array( $label_export, $token_export ),
				$body
			)
		);
	}

	private static function write_load_probe_file( string $path ): void {
		self::ensure_dir( dirname( $path ) );
		self::write_file(
			$path,
			<<<'PHP'
<?php
$GLOBALS['cfz_template_hierarchy_load_probe'][] = array(
	'args'     => isset( $args ) && is_array( $args ) ? $args : array(),
	's'        => isset( $s ) ? $s : null,
	'cfzLocal' => isset( $cfz_local ) ? $cfz_local : null,
);
echo 'load-probe:' . ( isset( $args['step'] ) ? $args['step'] : 'missing-step' ) . "\n";
PHP
		);
	}

	private static function result( string $invariant, array $failures, array $data = array() ): array {
		return array(
			'ok'        => array() === $failures,
			'status'    => array() === $failures ? 'passed' : 'failed',
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'data'      => $data + array(
				'failureCount' => count( $failures ),
				'failures'     => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			),
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private static function snapshot_state(): array {
		$snapshot = array(
			'globals' => array(),
		);

		foreach (
			array(
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'wp_theme_directories',
				'wp_stylesheet_path',
				'wp_template_path',
				'wp_query',
				'wp_the_query',
				'post',
				'id',
				'withcomments',
				'overridden_cpage',
				'cfz_template_hierarchy_loaded',
				'cfz_template_hierarchy_load_probe',
			) as $name
		) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		return $snapshot;
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		\wp_cache_delete( 'theme_roots', 'site-transient' );
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			\wp_clean_theme_json_cache();
		}
	}

	private static function check_runtime_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$failures = array();

		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				$failures[] = array(
					'global'   => $name,
					'expected' => $entry['exists'] ? 'exists' : 'absent',
					'actual'   => $exists ? 'exists' : 'absent',
				);
				continue;
			}

			if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
				$failures[] = array(
					'global' => $name,
					'issue'  => 'value changed',
				);
			}
		}

		$row              = self::result(
			'template-hierarchy.runtime-restored',
			$failures,
			array( 'trackedGlobals' => array_keys( $snapshot['globals'] ) )
		);
		$row['seed']      = $ctx->seed();
		$row['iteration'] = $ctx->iteration();

		return $row;
	}

	private static function reset_template_globals(): void {
		unset( $GLOBALS['wp_stylesheet_path'], $GLOBALS['wp_template_path'] );
		\wp_cache_delete( 'theme_roots', 'site-transient' );
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			\wp_clean_theme_json_cache();
		}
	}

	private static function ensure_query_context(): void {
		if ( ! isset( $GLOBALS['wp_query'] ) || ! $GLOBALS['wp_query'] instanceof \WP_Query ) {
			$GLOBALS['wp_query'] = new \WP_Query();
		}
		if ( ! is_array( $GLOBALS['wp_query']->query_vars ) ) {
			$GLOBALS['wp_query']->query_vars = array();
		}
	}

	private static function set_query_context( array $query_vars = array(), $post = null, array $flags = array() ): void {
		$query             = new \WP_Query();
		$query->query_vars = $query_vars;

		foreach ( $flags as $flag => $value ) {
			$query->{$flag} = $value;
		}

		if ( $post instanceof \WP_Post ) {
			$query->post     = $post;
			$GLOBALS['post'] = $post;
		} else {
			unset( $GLOBALS['post'] );
		}

		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
	}

	private static function set_queried_object( object $object, array $flags = array() ): void {
		$query                 = new \WP_Query();
		$query->query_vars     = array();
		$query->queried_object = $object;
		if ( isset( $object->term_id ) ) {
			$query->queried_object_id = (int) $object->term_id;
		} elseif ( isset( $object->ID ) ) {
			$query->queried_object_id = (int) $object->ID;
		}

		foreach ( $flags as $flag => $value ) {
			$query->{$flag} = $value;
		}

		unset( $GLOBALS['post'] );
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
	}

	private static function make_user( int $id, string $user_nicename ): \WP_User {
		$reflection = new \ReflectionClass( \WP_User::class );
		$user       = $reflection->newInstanceWithoutConstructor();
		$user->ID   = $id;
		$user->data = (object) array(
			'ID'            => $id,
			'user_login'    => $user_nicename,
			'user_nicename' => $user_nicename,
			'display_name'  => $user_nicename,
			'user_email'    => "{$user_nicename}@example.test",
		);

		return $user;
	}

	private static function make_post( int $id, string $post_type, string $post_name, string $post_mime_type = '' ): \WP_Post {
		return new \WP_Post(
			(object) array(
				'ID'                    => $id,
				'post_author'           => 1,
				'post_date'             => '2026-06-23 12:00:00',
				'post_date_gmt'         => '2026-06-23 10:00:00',
				'post_content'          => 'Template hierarchy direct helper host',
				'post_title'            => 'Template hierarchy direct helper host',
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $post_name,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-23 12:00:00',
				'post_modified_gmt'     => '2026-06-23 10:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'https://example.test/template-hierarchy-direct',
				'menu_order'            => 0,
				'post_type'             => $post_type,
				'post_mime_type'        => $post_mime_type,
				'comment_count'         => 0,
				'filter'                => 'raw',
			)
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

	private static function temp_root( \ComponentFuzz\FuzzContext $ctx ): string {
		return \sys_get_temp_dir() . '/component-fuzz-template-hierarchy-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . getmypid();
	}

	private static function safe_fragment( \ComponentFuzz\FuzzContext $ctx, int $max_length ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$length   = max( 1, $ctx->int( 1, $max_length ) );
		$out      = '';

		for ( $i = 0; $i < $length; ++$i ) {
			$out .= $alphabet[ $ctx->int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $out;
	}

	private static function ensure_dir( string $path ): void {
		if ( is_dir( $path ) ) {
			return;
		}

		if ( ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
			throw new \RuntimeException( 'Unable to create directory: ' . $path );
		}
	}

	private static function write_file( string $path, string $contents ): void {
		self::ensure_dir( dirname( $path ) );
		if ( false === file_put_contents( $path, $contents ) ) {
			throw new \RuntimeException( 'Unable to write file: ' . $path );
		}
	}

	private static function remove_dir_recursive( string $path ): bool {
		if ( '' === $path || ! file_exists( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path );
		}

		$items = scandir( $path );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( ! self::remove_dir_recursive( $path . DIRECTORY_SEPARATOR . $item ) ) {
				return false;
			}
		}

		return @rmdir( $path );
	}

	private static function path_is_allowed( string $path, array $case ): bool {
		return str_starts_with( $path, $case['paths']['child'] . '/' )
			|| str_starts_with( $path, $case['paths']['parent'] . '/' )
			|| str_starts_with( $path, ABSPATH . WPINC . '/theme-compat/' );
	}

	private static function all_paths_in_theme_roots( array $paths, array $case ): bool {
		foreach ( $paths as $path ) {
			if (
				! is_string( $path )
				|| (
					! str_starts_with( $path, $case['paths']['child'] . '/' )
					&& ! str_starts_with( $path, $case['paths']['parent'] . '/' )
				)
			) {
				return false;
			}
		}

		return true;
	}

	private static function relative_to_root( string $path, string $root ): string {
		if ( str_starts_with( $path, $root . '/' ) ) {
			return substr( $path, strlen( $root ) + 1 );
		}

		return $path;
	}

	private static function relative_paths_to_root( array $paths, string $root ): array {
		$relative = array();
		foreach ( $paths as $name => $path ) {
			$relative[ $name ] = is_string( $path ) ? self::relative_to_root( $path, $root ) : $path;
		}

		return $relative;
	}

	private static function path_basenames( array $paths ): array {
		$basenames = array();
		foreach ( $paths as $name => $path ) {
			$basenames[ $name ] = is_string( $path ) ? basename( $path ) : $path;
		}

		return $basenames;
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => addcslashes( substr( $value, 0, 160 ), "\0..\37\177..\377" ),
			'sha1'    => sha1( $value ),
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
