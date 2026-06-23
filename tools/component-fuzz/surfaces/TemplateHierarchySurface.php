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
			$rows[] = self::check_get_single_template_hierarchy( $ctx->fork( 'single-template' ), $case );
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
				'get_query_template',
				'get_single_template',
				'get_template_part',
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
				&& array( 'before', 'after', 'before', 'after', 'before', 'after' ) === array_column( $events, 'hook')
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
		unset( $case );

		if ( ! defined( 'COMMENTS_TEMPLATE' ) ) {
			return $ctx->skip(
				'template-hierarchy.comments-template-guarded',
				'comments_template() defines COMMENTS_TEMPLATE permanently in-process; skipped to preserve shared fuzz runtime state.'
			);
		}

		return self::result(
			'template-hierarchy.comments-template-guarded',
			array(),
			array( 'defined' => true )
		);
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

	private static function relative_to_root( string $path, string $root ): string {
		if ( str_starts_with( $path, $root . '/' ) ) {
			return substr( $path, strlen( $root ) + 1 );
		}

		return $path;
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
