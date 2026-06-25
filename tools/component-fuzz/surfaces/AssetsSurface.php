<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WordPress scripts, styles, and dependency asset APIs.
 */
final class AssetsSurface {
	public const NAME = 'assets';

	private const BASE_URL        = 'https://example.test/wp/';
	private const CONTENT_URL     = 'https://example.test/wp-content';
	private const DEFAULT_VERSION = 'component-fuzz-default-version';
	private const MAX_ITEMS       = 8;
	private const PREVIEW_BYTES   = 240;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'assets.bootstrap-apis-available',
					'Required WordPress asset APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();
		$ob_level = ob_get_level();

		try {
			self::prepare_asset_globals();

			$case = self::case_for_context( $ctx );

			$rows[] = self::check_wp_dependencies_topology( $ctx, $case );
			$rows[] = self::check_missing_dependencies_no_throw( $ctx, $case );
			$rows[] = self::check_cycle_bounded_queue_query( $ctx, $case );
			$rows[] = self::check_script_lifecycle( $ctx, $case );
			$rows[] = self::check_style_lifecycle( $ctx, $case );
			$rows[] = self::check_script_dependency_print_order( $ctx, $case );
			$rows[] = self::check_style_dependency_print_order( $ctx, $case );
			$rows[] = self::check_inline_scripts( $ctx, $case );
			$rows[] = self::check_inline_styles( $ctx, $case );
			$rows[] = self::check_style_data_output_metadata( $ctx, $case );
			$rows[] = self::check_script_data_args( $ctx, $case );
			$rows[] = self::check_printed_output_escaping( $ctx, $case );
			$rows[] = self::check_loader_tag_filters( $ctx, $case );
			$rows[] = self::check_script_modules( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'assets.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Dependencies',
				'WP_Scripts',
				'WP_Styles',
				'_WP_Dependency',
				'WP_HTML_Tag_Processor',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'has_filter',
				'remove_action',
				'remove_filter',
				'wp_scripts',
				'wp_styles',
				'wp_register_script',
				'wp_enqueue_script',
				'wp_dequeue_script',
				'wp_deregister_script',
				'wp_script_is',
				'wp_script_add_data',
				'wp_add_inline_script',
				'wp_print_scripts',
				'wp_register_style',
				'wp_enqueue_style',
				'wp_dequeue_style',
				'wp_deregister_style',
				'wp_style_is',
				'wp_style_add_data',
				'wp_add_inline_style',
				'wp_print_styles',
				'wp_get_script_tag',
				'wp_get_inline_script_tag',
				'esc_attr',
				'esc_url',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_wp_dependencies_topology( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$deps = new \WP_Dependencies();

		foreach ( $case['items'] as $item ) {
			$deps->add( $item['handle'], $item['src'], $item['deps'], $item['version'], null );
		}
		$deps->enqueue( $case['queue'] );

		$call     = self::call( static fn() => $deps->do_items() );
		$expected = self::expected_topological_order( $case['items'], $case['queue'] );
		$actual   = $deps->done;
		$ok       = ! $call['threw']
			&& $expected === $actual
			&& self::has_no_duplicates( $actual )
			&& self::dependency_order_holds( $case['items'], $actual );

		return $ctx->result(
			'assets.wp-dependencies.topological-order',
			$ok,
			self::case_data( $case ) + array(
				'queue'    => $case['queue'],
				'expected' => $expected,
				'actual'   => $actual,
				'call'     => self::describe_call( $call ),
			)
		);
	}

	private static function check_missing_dependencies_no_throw( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$warnings = array();
		$callback = static function ( $function_name, $message, $version ) use ( &$warnings ): void {
			$warnings[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};
		\add_action( 'doing_it_wrong_run', $callback, 10, 3 );

		$deps    = new class() extends \WP_Dependencies {
			protected function get_dependency_warning_message( $handle, $missing_dependency_handles ) {
				return sprintf( 'Missing dependencies for %s: %s.', $handle, implode( ', ', $missing_dependency_handles ) );
			}
		};
		$missing = $case['missing'];
		$deps->add( $missing['handle'], $missing['src'], $missing['deps'], $missing['version'], null );
		$deps->enqueue( $missing['handle'] );

		$call = self::call( static fn() => $deps->do_items() );
		\remove_action( 'doing_it_wrong_run', $callback, 10 );

		$ok = ! $call['threw']
			&& array() === $deps->done
			&& ! in_array( $missing['handle'], $deps->to_do, true )
			&& count( $warnings ) >= 1;

		return $ctx->result(
			'assets.wp-dependencies.missing-dependency-no-throw',
			$ok,
			self::case_data( $case ) + array(
				'missingCase' => $missing,
				'done'        => $deps->done,
				'toDo'        => $deps->to_do,
				'warnings'    => $warnings,
				'call'        => self::describe_call( $call ),
			)
		);
	}

	private static function check_cycle_bounded_queue_query( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		$cycle = $case['cycle'];
		\wp_register_script( $cycle[0]['handle'], $cycle[0]['src'], $cycle[0]['deps'], $cycle[0]['version'] );
		\wp_register_script( $cycle[1]['handle'], $cycle[1]['src'], $cycle[1]['deps'], $cycle[1]['version'] );

		$enqueue = self::call( static fn() => \wp_enqueue_script( $cycle[0]['handle'] ) );
		$query_a = self::call( static fn() => \wp_script_is( $cycle[0]['handle'], 'enqueued' ) );
		$query_b = self::call( static fn() => \wp_script_is( $cycle[1]['handle'], 'enqueued' ) );
		$dequeue = self::call( static fn() => \wp_dequeue_script( $cycle[0]['handle'] ) );

		$ok = ! $enqueue['threw']
			&& ! $query_a['threw']
			&& ! $query_b['threw']
			&& ! $dequeue['threw']
			&& true === $query_a['value']
			&& true === $query_b['value']
			&& false === \wp_script_is( $cycle[0]['handle'], 'enqueued' );

		return $ctx->result(
			'assets.wp-dependencies.cycle-query-bounded-no-throw',
			$ok,
			self::case_data( $case ) + array(
				'cycleCase' => $cycle,
				'queryA'    => self::describe_call( $query_a ),
				'queryB'    => self::describe_call( $query_b ),
				'enqueue'   => self::describe_call( $enqueue ),
				'dequeue'   => self::describe_call( $dequeue ),
				'note'      => 'Print/all_deps recursion is not invoked for cyclic graphs because WP_Dependencies documents that path as unbounded.',
			)
		);
	}

	private static function check_script_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();

		$item       = self::first_concrete_item( $case );
		$pre_handle = self::handle( $ctx, 'pre-script' );
		$pre_args   = 'alpha=1&beta=' . rawurlencode( $case['profile'] );

		$registered      = \wp_register_script( $item['handle'], $item['src'], array(), $item['version'], array( 'in_footer' => true ) );
		$duplicate       = \wp_register_script( $item['handle'], $item['src'], array(), $item['version'] );
		$is_registered   = \wp_script_is( $item['handle'], 'registered' );
		\wp_enqueue_script( $item['handle'] );
		$is_enqueued     = \wp_script_is( $item['handle'], 'enqueued' );
		\wp_dequeue_script( $item['handle'] );
		$is_dequeued     = ! \wp_script_is( $item['handle'], 'enqueued' );
		\wp_enqueue_script( "{$pre_handle}?{$pre_args}" );
		$pre_before      = \wp_script_is( $pre_handle, 'enqueued' );
		$pre_registered  = \wp_register_script( $pre_handle, $item['src'], array(), null );
		$pre_after       = \wp_script_is( $pre_handle, 'enqueued' );
		$pre_query       = \wp_scripts()->query( $pre_handle, 'registered' );
		$pre_args_stored = \wp_scripts()->args[ $pre_handle ] ?? null;
		\wp_deregister_script( $item['handle'] );
		$is_deregistered = ! \wp_script_is( $item['handle'], 'registered' );

		$ok = true === $registered
			&& false === $duplicate
			&& true === $is_registered
			&& true === $is_enqueued
			&& true === $is_dequeued
			&& false === $pre_before
			&& true === $pre_registered
			&& true === $pre_after
			&& $pre_query instanceof \_WP_Dependency
			&& $pre_args === $pre_args_stored
			&& true === $is_deregistered;

		return $ctx->result(
			'assets.scripts.register-enqueue-dequeue-deregister-query-lifecycle',
			$ok,
			self::case_data( $case ) + array(
				'handle'         => $item['handle'],
				'registered'     => $registered,
				'duplicate'      => $duplicate,
				'isRegistered'   => $is_registered,
				'isEnqueued'     => $is_enqueued,
				'isDequeued'     => $is_dequeued,
				'preHandle'      => $pre_handle,
				'preBefore'      => $pre_before,
				'preRegistered'  => $pre_registered,
				'preAfter'       => $pre_after,
				'preArgsStored'  => $pre_args_stored,
				'isDeregistered' => $is_deregistered,
			)
		);
	}

	private static function check_style_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_styles_global();

		$item       = self::first_concrete_item( $case );
		$pre_handle = self::handle( $ctx, 'pre-style' );
		$pre_args   = 'gamma=1&delta=' . rawurlencode( $case['profile'] );

		$registered      = \wp_register_style( $item['handle'], self::style_src_from_script_src( $item['src'] ), array(), $item['version'], $item['media'] );
		$duplicate       = \wp_register_style( $item['handle'], self::style_src_from_script_src( $item['src'] ), array(), $item['version'], $item['media'] );
		$is_registered   = \wp_style_is( $item['handle'], 'registered' );
		\wp_enqueue_style( $item['handle'] );
		$is_enqueued     = \wp_style_is( $item['handle'], 'enqueued' );
		\wp_dequeue_style( $item['handle'] );
		$is_dequeued     = ! \wp_style_is( $item['handle'], 'enqueued' );
		\wp_enqueue_style( "{$pre_handle}?{$pre_args}" );
		$pre_before      = \wp_style_is( $pre_handle, 'enqueued' );
		$pre_registered  = \wp_register_style( $pre_handle, self::style_src_from_script_src( $item['src'] ), array(), null, $item['media'] );
		$pre_after       = \wp_style_is( $pre_handle, 'enqueued' );
		$pre_query       = \wp_styles()->query( $pre_handle, 'registered' );
		$pre_args_stored = \wp_styles()->args[ $pre_handle ] ?? null;
		\wp_deregister_style( $item['handle'] );
		$is_deregistered = ! \wp_style_is( $item['handle'], 'registered' );

		$ok = true === $registered
			&& false === $duplicate
			&& true === $is_registered
			&& true === $is_enqueued
			&& true === $is_dequeued
			&& false === $pre_before
			&& true === $pre_registered
			&& true === $pre_after
			&& $pre_query instanceof \_WP_Dependency
			&& $pre_args === $pre_args_stored
			&& true === $is_deregistered;

		return $ctx->result(
			'assets.styles.register-enqueue-dequeue-deregister-query-lifecycle',
			$ok,
			self::case_data( $case ) + array(
				'handle'         => $item['handle'],
				'registered'     => $registered,
				'duplicate'      => $duplicate,
				'isRegistered'   => $is_registered,
				'isEnqueued'     => $is_enqueued,
				'isDequeued'     => $is_dequeued,
				'preHandle'      => $pre_handle,
				'preBefore'      => $pre_before,
				'preRegistered'  => $pre_registered,
				'preAfter'       => $pre_after,
				'preArgsStored'  => $pre_args_stored,
				'isDeregistered' => $is_deregistered,
			)
		);
	}

	private static function check_script_dependency_print_order( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! function_exists( 'wp_get_script_tag' ) ) {
			return $ctx->skip( 'assets.scripts.dependency-print-order', 'wp_get_script_tag() is unavailable.', self::case_data( $case ) );
		}

		self::reset_scripts_global();

		foreach ( $case['items'] as $item ) {
			\wp_register_script( $item['handle'], $item['src'], $item['deps'], $item['version'] );
		}
		\wp_enqueue_script( $case['queue'] );

		$printed  = self::capture_output( static fn() => \wp_print_scripts() );
		$expected = self::expected_topological_order( $case['items'], $case['queue'] );
		$actual   = \wp_scripts()->done;
		$ok       = ! $printed['threw']
			&& $expected === $actual
			&& self::dependency_order_holds( $case['items'], $actual )
			&& self::printed_concrete_handles_present( $case['items'], $actual, $printed['output'], 'script' );

		return $ctx->result(
			'assets.scripts.dependency-print-order',
			$ok,
			self::case_data( $case ) + array(
				'expected'      => $expected,
				'actual'        => $actual,
				'outputPreview' => self::preview( $printed['output'] ),
				'call'          => self::describe_call( $printed ),
			)
		);
	}

	private static function check_style_dependency_print_order( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_styles_global();

		foreach ( $case['items'] as $item ) {
			\wp_register_style( $item['handle'], self::style_src_from_script_src( $item['src'] ), $item['deps'], $item['version'], $item['media'] );
		}
		\wp_enqueue_style( $case['queue'] );

		$printed  = self::capture_output( static fn() => \wp_print_styles() );
		$expected = self::expected_topological_order( $case['items'], $case['queue'] );
		$actual   = \wp_styles()->done;
		$ok       = ! $printed['threw']
			&& $expected === $actual
			&& self::dependency_order_holds( $case['items'], $actual )
			&& self::printed_concrete_handles_present( $case['items'], $actual, $printed['output'], 'style' );

		return $ctx->result(
			'assets.styles.dependency-print-order',
			$ok,
			self::case_data( $case ) + array(
				'expected'      => $expected,
				'actual'        => $actual,
				'outputPreview' => self::preview( $printed['output'] ),
				'call'          => self::describe_call( $printed ),
			)
		);
	}

	private static function check_inline_scripts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! method_exists( 'WP_Scripts', 'get_inline_script_data' ) ) {
			return $ctx->skip( 'assets.scripts.inline-before-after-order', 'WP_Scripts inline data accessors are unavailable.', self::case_data( $case ) );
		}

		self::reset_scripts_global();

		$handle = self::handle( $ctx, 'inline-script' );
		$src    = 'assets/' . rawurlencode( $handle ) . '.js';
		\wp_register_script( $handle, $src, array(), '1.0.0' );

		$before_one = 'window.assetBefore = "one < &";';
		$before_two = 'window.assetBeforeTwo = "two";';
		$after_one  = 'window.assetAfter = "one";';
		$after_two  = 'window.assetAfterTwo = "two";';
		$added      = array(
			\wp_add_inline_script( $handle, $before_one, 'before' ),
			\wp_add_inline_script( $handle, $before_two, 'sideways' ),
			\wp_add_inline_script( $handle, $after_one, 'after' ),
			\wp_add_inline_script( $handle, $after_two ),
		);

		\wp_enqueue_script( $handle );
		$printed     = self::capture_output( static fn() => \wp_print_scripts() );
		$output      = $printed['output'];
		$before_data = \wp_scripts()->get_inline_script_data( $handle, 'before' );
		$after_data  = \wp_scripts()->get_inline_script_data( $handle, 'after' );

		$before_pos = self::attribute_position( $output, 'id', "{$handle}-js-before" );
		$main_pos   = self::attribute_position( $output, 'id', "{$handle}-js" );
		$after_pos  = self::attribute_position( $output, 'id', "{$handle}-js-after" );

		$ok = ! $printed['threw']
			&& array( true, true, true, true ) === $added
			&& false !== $before_pos
			&& false !== $main_pos
			&& false !== $after_pos
			&& $before_pos < $main_pos
			&& $main_pos < $after_pos
			&& self::contains_in_order( $before_data, array( $before_one, $before_two ) )
			&& self::contains_in_order( $after_data, array( $after_one, $after_two ) )
			&& str_contains( $before_data, rawurlencode( "{$handle}-js-before" ) )
			&& str_contains( $after_data, rawurlencode( "{$handle}-js-after" ) );

		return $ctx->result(
			'assets.scripts.inline-before-after-order',
			$ok,
			self::case_data( $case ) + array(
				'handle'        => $handle,
				'added'         => $added,
				'beforeData'    => $before_data,
				'afterData'     => $after_data,
				'positions'     => array(
					'before' => $before_pos,
					'main'   => $main_pos,
					'after'  => $after_pos,
				),
				'outputPreview' => self::preview( $output ),
				'call'          => self::describe_call( $printed ),
			)
		);
	}

	private static function check_inline_styles( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_styles_global();

		$handle = self::handle( $ctx, 'inline-style' );
		$src    = 'assets/' . rawurlencode( $handle ) . '.css';
		\wp_register_style( $handle, $src, array(), '1.0.0', '(min-width: 10px)' );

		$style_one = '.asset-one { content: "< &"; }';
		$style_two = '.asset-two { color: #123456; }';
		$added     = array(
			\wp_add_inline_style( $handle, $style_one ),
			\wp_add_inline_style( $handle, $style_two ),
		);

		\wp_enqueue_style( $handle );
		$inline_before_print = \wp_styles()->print_inline_style( $handle, false );
		$printed             = self::capture_output( static fn() => \wp_print_styles() );
		$output              = $printed['output'];

		$link_pos  = strpos( $output, "{$handle}-css" );
		$style_pos = strpos( $output, "{$handle}-inline-css" );

		$ok = ! $printed['threw']
			&& array( true, true ) === $added
			&& is_string( $inline_before_print )
			&& self::contains_in_order( $inline_before_print, array( $style_one, $style_two ) )
			&& str_contains( $inline_before_print, rawurlencode( "{$handle}-inline-css" ) )
			&& false !== $link_pos
			&& false !== $style_pos
			&& $link_pos < $style_pos;

		return $ctx->result(
			'assets.styles.inline-after-order',
			$ok,
			self::case_data( $case ) + array(
				'handle'        => $handle,
				'added'         => $added,
				'inlineData'    => $inline_before_print,
				'positions'     => array(
					'link'  => $link_pos,
					'style' => $style_pos,
				),
				'outputPreview' => self::preview( $output ),
				'call'          => self::describe_call( $printed ),
			)
		);
	}

	private static function check_style_data_output_metadata( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_styles_global();

		$ltr_handle = self::handle( $ctx, 'style-data-ltr-rtl' );
		$ltr_media  = 'screen and (min-width: 12px)';
		\wp_register_style( $ltr_handle, 'assets/style-data/ltr.min.css', array(), 'ltr-1', $ltr_media );
		$ltr_added = array(
			\wp_style_add_data( $ltr_handle, 'rtl', true ),
			\wp_style_add_data( $ltr_handle, 'suffix', '.min' ),
		);
		\wp_enqueue_style( $ltr_handle );
		$ltr_print = self::capture_output( static fn() => \wp_print_styles() );
		$ltr_links = self::link_tags_by_id( $ltr_print['output'] );

		self::reset_styles_global();
		\wp_styles()->text_direction = 'rtl';

		$alt_handle         = self::handle( $ctx, 'style-data-alt-rtl' );
		$custom_handle      = self::handle( $ctx, 'style-data-custom-rtl' );
		$conditional_handle = self::handle( $ctx, 'style-data-conditional' );
		$conditional_dep    = self::handle( $ctx, 'style-data-conditional-dep' );
		$alt_title          = 'Alt "<Choice>" & contrast';
		$alt_media          = 'screen and (orientation: landscape)';
		$custom_media       = 'print';
		$filter_seen        = array();

		\wp_register_style( $alt_handle, 'assets/style-data/theme.min.css', array(), 'meta-1', $alt_media );
		$alt_added = array(
			\wp_style_add_data( $alt_handle, 'alt', true ),
			\wp_style_add_data( $alt_handle, 'title', $alt_title ),
			\wp_style_add_data( $alt_handle, 'rtl', true ),
			\wp_style_add_data( $alt_handle, 'suffix', '.min' ),
		);

		\wp_register_style( $custom_handle, 'assets/style-data/editor.css', array(), 'meta-2', $custom_media );
		$custom_added = array(
			\wp_style_add_data( $custom_handle, 'rtl', 'assets/style-data/editor-custom-rtl.css' ),
		);

		\wp_register_style( $conditional_dep, 'assets/style-data/conditional-dep.css', array(), 'meta-3' );
		\wp_register_style( $conditional_handle, 'assets/style-data/conditional.css', array( $conditional_dep ), 'meta-4' );
		$conditional_added = \wp_style_add_data( $conditional_handle, 'conditional', '_required-conditional-dependency_' );
		$conditional_deps  = \wp_styles()->registered[ $conditional_handle ]->deps ?? null;

		$style_filter = static function ( string $html, string $handle, string $href, string $media ) use ( &$filter_seen, $alt_handle, $custom_handle ): string {
			if ( in_array( $handle, array( $alt_handle, $custom_handle ), true ) ) {
				$filter_seen[] = array(
					'handle' => $handle,
					'id'     => self::first_attribute( $html, 'id' ),
					'href'   => $href,
					'media'  => $media,
				);
			}

			return str_replace( '<link ', '<link data-cfz-style-meta="' . esc_attr( (string) count( $filter_seen ) ) . '" ', $html );
		};

		\wp_enqueue_style( $alt_handle );
		\wp_enqueue_style( $custom_handle );
		\wp_enqueue_style( $conditional_handle );
		\add_filter( 'style_loader_tag', $style_filter, 10, 4 );
		try {
			$rtl_print = self::capture_output( static fn() => \wp_print_styles() );
		} finally {
			\remove_filter( 'style_loader_tag', $style_filter, 10 );
		}

		$rtl_links = self::link_tags_by_id( $rtl_print['output'] );

		$ltr_link     = $ltr_links[ "{$ltr_handle}-css" ] ?? array();
		$alt_link     = $rtl_links[ "{$alt_handle}-css" ] ?? array();
		$alt_rtl_link = $rtl_links[ "{$alt_handle}-rtl-css" ] ?? array();
		$custom_link  = $rtl_links[ "{$custom_handle}-css" ] ?? array();
		$custom_rtl   = $rtl_links[ "{$custom_handle}-rtl-css" ] ?? array();

		$filter_ids     = array_column( $filter_seen, 'id' );
		$filter_handles = array_column( $filter_seen, 'handle' );
		$filter_media   = array_column( $filter_seen, 'media' );
		$filter_hrefs   = array_column( $filter_seen, 'href' );

		$alt_pos         = self::attribute_position( $rtl_print['output'], 'id', "{$alt_handle}-css" );
		$alt_rtl_pos     = self::attribute_position( $rtl_print['output'], 'id', "{$alt_handle}-rtl-css" );
		$custom_pos      = self::attribute_position( $rtl_print['output'], 'id', "{$custom_handle}-css" );
		$custom_rtl_pos  = self::attribute_position( $rtl_print['output'], 'id', "{$custom_handle}-rtl-css" );
		$conditional_pos = self::attribute_position( $rtl_print['output'], 'id', "{$conditional_handle}-css" );
		$dep_pos         = self::attribute_position( $rtl_print['output'], 'id', "{$conditional_dep}-css" );

		$ok = ! $ltr_print['threw']
			&& ! $rtl_print['threw']
			&& array( true, true ) === $ltr_added
			&& isset( $ltr_link['href'] )
			&& 'stylesheet' === ( $ltr_link['rel'] ?? null )
			&& $ltr_media === ( $ltr_link['media'] ?? null )
			&& str_contains( $ltr_link['href'], 'assets/style-data/ltr.min.css' )
			&& ! isset( $ltr_links[ "{$ltr_handle}-rtl-css" ] )
			&& array( true, true, true, true ) === $alt_added
			&& array( true ) === $custom_added
			&& true === $conditional_added
			&& array() === $conditional_deps
			&& 'alternate stylesheet' === ( $alt_link['rel'] ?? null )
			&& 'alternate stylesheet' === ( $alt_rtl_link['rel'] ?? null )
			&& $alt_title === ( $alt_link['title'] ?? null )
			&& $alt_title === ( $alt_rtl_link['title'] ?? null )
			&& $alt_media === ( $alt_link['media'] ?? null )
			&& $alt_media === ( $alt_rtl_link['media'] ?? null )
			&& 'stylesheet' === ( $custom_link['rel'] ?? null )
			&& 'stylesheet' === ( $custom_rtl['rel'] ?? null )
			&& ! isset( $custom_link['title'] )
			&& ! isset( $custom_rtl['title'] )
			&& $custom_media === ( $custom_link['media'] ?? null )
			&& $custom_media === ( $custom_rtl['media'] ?? null )
			&& isset( $alt_link['href'], $alt_rtl_link['href'], $custom_link['href'], $custom_rtl['href'] )
			&& str_contains( $alt_link['href'], 'assets/style-data/theme.min.css' )
			&& str_contains( $alt_rtl_link['href'], 'assets/style-data/theme-rtl.min.css' )
			&& str_contains( $custom_link['href'], 'assets/style-data/editor.css' )
			&& str_contains( $custom_rtl['href'], 'assets/style-data/editor-custom-rtl.css' )
			&& false !== $alt_pos
			&& false !== $alt_rtl_pos
			&& false !== $custom_pos
			&& false !== $custom_rtl_pos
			&& $alt_pos < $alt_rtl_pos
			&& $alt_rtl_pos < $custom_pos
			&& $custom_pos < $custom_rtl_pos
			&& false === $conditional_pos
			&& false === $dep_pos
			&& array( $alt_handle, $alt_handle, $custom_handle, $custom_handle ) === $filter_handles
			&& array( "{$alt_handle}-css", "{$alt_handle}-rtl-css", "{$custom_handle}-css", "{$custom_handle}-rtl-css" ) === $filter_ids
			&& array( $alt_media, $alt_media, $custom_media, $custom_media ) === $filter_media
			&& str_contains( $filter_hrefs[0] ?? '', 'assets/style-data/theme.min.css' )
			&& str_contains( $filter_hrefs[1] ?? '', 'assets/style-data/theme-rtl.min.css' )
			&& str_contains( $filter_hrefs[2] ?? '', 'assets/style-data/editor.css' )
			&& str_contains( $filter_hrefs[3] ?? '', 'assets/style-data/editor-custom-rtl.css' )
			&& '0' !== ( $alt_link['data-cfz-style-meta'] ?? '0' )
			&& '0' !== ( $alt_rtl_link['data-cfz-style-meta'] ?? '0' )
			&& '0' !== ( $custom_link['data-cfz-style-meta'] ?? '0' )
			&& '0' !== ( $custom_rtl['data-cfz-style-meta'] ?? '0' )
			&& false === \has_filter( 'style_loader_tag', $style_filter );

		return $ctx->result(
			'assets.styles.add-data-output-metadata',
			$ok,
			self::case_data( $case ) + array(
				'ltrHandle'         => $ltr_handle,
				'altHandle'         => $alt_handle,
				'customHandle'      => $custom_handle,
				'conditionalHandle' => $conditional_handle,
				'conditionalDep'    => $conditional_dep,
				'ltrAdded'          => $ltr_added,
				'altAdded'          => $alt_added,
				'customAdded'       => $custom_added,
				'conditionalAdded'  => $conditional_added,
				'conditionalDeps'   => $conditional_deps,
				'ltrLinks'          => $ltr_links,
				'rtlLinks'          => $rtl_links,
				'filterSeen'        => $filter_seen,
				'positions'         => array(
					'alt'            => $alt_pos,
					'altRtl'         => $alt_rtl_pos,
					'custom'         => $custom_pos,
					'customRtl'      => $custom_rtl_pos,
					'conditional'    => $conditional_pos,
					'conditionalDep' => $dep_pos,
				),
				'ltrOutputPreview'  => self::preview( $ltr_print['output'] ),
				'rtlOutputPreview'  => self::preview( $rtl_print['output'] ),
				'ltrCall'           => self::describe_call( $ltr_print ),
				'rtlCall'           => self::describe_call( $rtl_print ),
			)
		);
	}

	private static function check_script_data_args( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();

		$handle     = self::handle( $ctx, 'script-data' );
		$module_ids = array(
			self::module_id( $ctx, 'classic-dep-one' ),
			array(
				'id'     => self::module_id( $ctx, 'classic-dep-two' ),
				'import' => 'dynamic',
			),
			array( 'not-id' => 'ignored' ),
		);
		$args       = array(
			'in_footer'           => true,
			'strategy'            => 'defer',
			'fetchpriority'       => 'high',
			'module_dependencies' => $module_ids,
		);

		$registered      = \wp_register_script( $handle, 'assets/data.js', array(), '2.0.0', $args );
		$strategy        = \wp_scripts()->get_data( $handle, 'strategy' );
		$fetchpriority   = \wp_scripts()->get_data( $handle, 'fetchpriority' );
		$module_deps     = \wp_scripts()->get_data( $handle, 'module_dependencies' );
		$group           = \wp_scripts()->get_data( $handle, 'group' );
		$invalid_strategy = \wp_script_add_data( $handle, 'strategy', 'blocking' );
		$type_added      = \wp_script_add_data( $handle, 'type', 'module' );
		$type_data       = \wp_scripts()->get_data( $handle, 'type' );

		\wp_enqueue_script( $handle );
		$printed = self::capture_output( static fn() => \wp_print_scripts() );
		$output  = $printed['output'];

		$ok = true === $registered
			&& 'defer' === $strategy
			&& 'high' === $fetchpriority
			&& 1 === $group
			&& is_array( $module_deps )
			&& 2 === count( $module_deps )
			&& false === $invalid_strategy
			&& true === $type_added
			&& 'module' === $type_data
			&& ! $printed['threw']
			&& str_contains( $output, 'defer' )
			&& str_contains( $output, 'fetchpriority="high"' )
			&& str_contains( $output, 'data-wp-strategy="defer"' );

		return $ctx->result(
			'assets.scripts.args-strategy-fetchpriority-module-data',
			$ok,
			self::case_data( $case ) + array(
				'handle'          => $handle,
				'registered'      => $registered,
				'strategy'        => $strategy,
				'fetchpriority'   => $fetchpriority,
				'moduleDeps'      => $module_deps,
				'group'           => $group,
				'invalidStrategy' => $invalid_strategy,
				'typeData'        => $type_data,
				'outputPreview'   => self::preview( $output ),
				'call'            => self::describe_call( $printed ),
			)
		);
	}

	private static function check_printed_output_escaping( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		$script_handle = self::handle( $ctx, 'quote"<script>&☃' );
		$style_handle  = self::handle( $ctx, 'style\'"<tag>&☃' );
		$version       = 'v 1&<"☃';
		$script_src    = 'assets/preserve script.js?x=1&unsafe=<tag>#frag';
		$style_src     = 'assets/preserve style.css?x=1&unsafe=<tag>#frag';

		\wp_register_script( $script_handle, $script_src, array(), $version );
		\wp_enqueue_script( $script_handle );
		$script_print = self::capture_output( static fn() => \wp_print_scripts() );

		\wp_register_style( $style_handle, $style_src, array(), $version, 'screen and (min-width: 1px)' );
		\wp_enqueue_style( $style_handle );
		$style_print = self::capture_output( static fn() => \wp_print_styles() );

		$script_url = self::first_attribute( $script_print['output'], 'src' );
		$style_url  = self::first_attribute( $style_print['output'], 'href' );
		$encoded_ver = rawurlencode( $version );

		$decoded_script_url = is_string( $script_url ) ? self::decode_html_attribute( $script_url ) : '';
		$decoded_style_url  = is_string( $style_url ) ? self::decode_html_attribute( $style_url ) : '';

		$ok = ! $script_print['threw']
			&& ! $style_print['threw']
			&& is_string( $script_url )
			&& is_string( $style_url )
			&& str_starts_with( $decoded_script_url, self::BASE_URL . 'assets/preserve%20script.js?' )
			&& str_starts_with( $decoded_style_url, self::BASE_URL . 'assets/preserve%20style.css?' )
			&& str_contains( $decoded_script_url, 'x=1' )
			&& str_contains( $decoded_style_url, 'x=1' )
			&& str_contains( $decoded_script_url, 'ver=' . $encoded_ver )
			&& str_contains( $decoded_style_url, 'ver=' . $encoded_ver )
			&& str_ends_with( $decoded_script_url, '#frag' )
			&& str_ends_with( $decoded_style_url, '#frag' )
			&& ! str_contains( $script_url, '<tag>' )
			&& ! str_contains( $style_url, '<tag>' )
			&& ! str_contains( $script_print['output'], $script_handle . '-js' )
			&& ! str_contains( $style_print['output'], $style_handle . '-css' );

		return $ctx->result(
			'assets.printed-output.source-version-preserved-and-escaped',
			$ok,
			self::case_data( $case ) + array(
				'scriptHandle'       => $script_handle,
				'styleHandle'        => $style_handle,
				'version'            => $version,
				'encodedVersion'     => $encoded_ver,
				'scriptUrl'          => $script_url,
				'styleUrl'           => $style_url,
				'decodedScriptUrl'   => $decoded_script_url,
				'decodedStyleUrl'    => $decoded_style_url,
				'scriptOutputPreview' => self::preview( $script_print['output'] ),
				'styleOutputPreview' => self::preview( $style_print['output'] ),
			)
		);
	}

	private static function check_loader_tag_filters( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_scripts_global();
		self::reset_styles_global();

		$script_handle = self::handle( $ctx, 'loader-script-filter' );
		$style_handle  = self::handle( $ctx, 'loader-style-filter' );
		$script_src    = 'assets/filter-script.js?profile=' . rawurlencode( $case['profile'] );
		$style_src     = 'assets/filter-style.css?profile=' . rawurlencode( $case['profile'] );
		$style_media   = 'screen and (orientation: landscape)';
		$script_seen   = array();
		$style_seen    = array();

		$script_filter = static function ( string $tag, string $handle, string $src ) use ( &$script_seen, $script_handle ): string {
			$script_seen[] = array(
				'handle' => $handle,
				'src'    => $src,
			);

			if ( $script_handle !== $handle ) {
				return $tag;
			}

			return str_replace( '<script ', '<script data-cfz-script="' . esc_attr( $handle ) . '" ', $tag );
		};
		$style_filter  = static function ( string $html, string $handle, string $href, string $media ) use ( &$style_seen, $style_handle ): string {
			$style_seen[] = array(
				'handle' => $handle,
				'href'   => $href,
				'media'  => $media,
			);

			if ( $style_handle !== $handle ) {
				return $html;
			}

			return str_replace( '<link ', '<link data-cfz-style="' . esc_attr( $handle ) . '" ', $html );
		};

		\wp_register_script( $script_handle, $script_src, array(), 'filter-1' );
		\wp_enqueue_script( $script_handle );
		\wp_register_style( $style_handle, $style_src, array(), 'filter-1', $style_media );
		\wp_enqueue_style( $style_handle );

		\add_filter( 'script_loader_tag', $script_filter, 10, 3 );
		\add_filter( 'style_loader_tag', $style_filter, 10, 4 );
		try {
			$script_print = self::capture_output( static fn() => \wp_print_scripts() );
			$style_print  = self::capture_output( static fn() => \wp_print_styles() );
		} finally {
			\remove_filter( 'script_loader_tag', $script_filter, 10 );
			\remove_filter( 'style_loader_tag', $style_filter, 10 );
		}

		$script_srcs = array_column( $script_seen, 'src', 'handle' );
		$style_hrefs = array_column( $style_seen, 'href', 'handle' );
		$style_media_seen = array_column( $style_seen, 'media', 'handle' );

		$ok = ! $script_print['threw']
			&& ! $style_print['threw']
			&& array( $script_handle ) === array_column( $script_seen, 'handle' )
			&& array( $style_handle ) === array_column( $style_seen, 'handle' )
			&& str_contains( (string) ( $script_srcs[ $script_handle ] ?? '' ), 'assets/filter-script.js' )
			&& str_contains( (string) ( $style_hrefs[ $style_handle ] ?? '' ), 'assets/filter-style.css' )
			&& $style_media === ( $style_media_seen[ $style_handle ] ?? null )
			&& str_contains( $script_print['output'], 'data-cfz-script="' . $script_handle . '"' )
			&& str_contains( $style_print['output'], 'data-cfz-style="' . $style_handle . '"' )
			&& false === \has_filter( 'script_loader_tag', $script_filter )
			&& false === \has_filter( 'style_loader_tag', $style_filter );

		return $ctx->result(
			'assets.loader-tag-filters.scoped-attributes',
			$ok,
			self::case_data( $case ) + array(
				'scriptHandle'        => $script_handle,
				'styleHandle'         => $style_handle,
				'scriptSeen'          => $script_seen,
				'styleSeen'           => $style_seen,
				'scriptOutputPreview' => self::preview( $script_print['output'] ),
				'styleOutputPreview'  => self::preview( $style_print['output'] ),
				'scriptCall'          => self::describe_call( $script_print ),
				'styleCall'           => self::describe_call( $style_print ),
			)
		);
	}

	private static function check_script_modules( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! class_exists( 'WP_Script_Modules' ) || ! function_exists( 'wp_script_modules' ) || ! method_exists( 'WP_Script_Modules', 'get_registered' ) ) {
			return $ctx->skip( 'assets.script-modules.register-print-import-map', 'Script Modules API is unavailable.', self::case_data( $case ) );
		}

		self::reset_scripts_global();
		self::reset_script_modules_global();

		$dep_id  = self::module_id( $ctx, 'dep' );
		$dyn_id  = self::module_id( $ctx, 'dynamic' );
		$main_id = self::module_id( $ctx, 'main' );

		\wp_register_script_module( $dep_id, 'assets/module-dep.js', array(), '1.0.0', array( 'fetchpriority' => 'low' ) );
		\wp_register_script_module( $dyn_id, 'assets/module-dynamic.js', array(), null );
		\wp_register_script_module(
			$main_id,
			'assets/module-main.js',
			array(
				$dep_id,
				array(
					'id'     => $dyn_id,
					'import' => 'dynamic',
				),
			),
			'2.0.0',
			array( 'fetchpriority' => 'high' )
		);
		\wp_enqueue_script_module( $main_id );

		$classic_handle = self::handle( $ctx, 'classic-with-module' );
		\wp_register_script(
			$classic_handle,
			'assets/classic-with-module.js',
			array(),
			'3.0.0',
			array(
				'strategy'            => 'defer',
				'module_dependencies' => array( $dep_id ),
			)
		);
		\wp_enqueue_script( $classic_handle );

		$import_map = self::capture_output( static fn() => \wp_script_modules()->print_import_map() );
		$preloads   = self::capture_output( static fn() => \wp_script_modules()->print_script_module_preloads() );
		$modules    = self::capture_output( static fn() => \wp_script_modules()->print_enqueued_script_modules() );
		$registered = \wp_script_modules()->get_registered( $main_id );
		$queue      = method_exists( \wp_script_modules(), 'get_queue' ) ? \wp_script_modules()->get_queue() : array();

		$ok = ! $import_map['threw']
			&& ! $preloads['threw']
			&& ! $modules['threw']
			&& is_array( $registered )
			&& in_array( $main_id, $queue, true )
			&& str_contains( $import_map['output'], '"imports"' )
			&& str_contains( $import_map['output'], $dep_id )
			&& str_contains( $import_map['output'], $dyn_id )
			&& str_contains( $preloads['output'], 'rel="modulepreload"' )
			&& str_contains( $preloads['output'], $dep_id . '-js-modulepreload' )
			&& str_contains( $modules['output'], 'type="module"' )
			&& str_contains( $modules['output'], $main_id . '-js-module' )
			&& str_contains( $modules['output'], 'fetchpriority="high"' );

		return $ctx->result(
			'assets.script-modules.register-print-import-map',
			$ok,
			self::case_data( $case ) + array(
				'mainId'           => $main_id,
				'dependencyId'     => $dep_id,
				'dynamicId'        => $dyn_id,
				'queue'            => $queue,
				'registeredMain'   => $registered,
				'importMapPreview' => self::preview( $import_map['output'] ),
				'preloadPreview'   => self::preview( $preloads['output'] ),
				'modulePreview'    => self::preview( $modules['output'] ),
			)
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$corpus    = self::corpus_case( $ctx );
		$generated = self::generated_items( $ctx->fork( 'generated-items' ) );
		$items     = array_merge( $corpus['items'], $generated['items'] );
		$items     = array_slice( self::unique_items( $items ), 0, self::MAX_ITEMS );
		$handles   = array_column( $items, 'handle' );
		$queue     = array_values(
			array_filter(
				array_merge( $corpus['queue'], $generated['queue'] ),
				static fn( $handle ) => in_array( $handle, $handles, true )
			)
		);

		if ( array() === $queue && array() !== $handles ) {
			$queue[] = $handles[ count( $handles ) - 1 ];
		}

		$missing_handle = self::handle( $ctx, 'missing-parent' );
		$missing_dep    = self::handle( $ctx, 'missing-child' );
		$cycle_a        = self::handle( $ctx, 'cycle-a' );
		$cycle_b        = self::handle( $ctx, 'cycle-b' );

		return array(
			'profile' => $corpus['profile'] . '+generated',
			'items'   => $items,
			'queue'   => $queue,
			'missing' => array(
				'handle'  => $missing_handle,
				'src'     => 'assets/missing-parent.js',
				'deps'    => array( $missing_dep ),
				'version' => 'missing-1',
			),
			'cycle'   => array(
				array(
					'handle'  => $cycle_a,
					'src'     => 'assets/cycle-a.js',
					'deps'    => array( $cycle_b ),
					'version' => 'cycle-1',
				),
				array(
					'handle'  => $cycle_b,
					'src'     => 'assets/cycle-b.js',
					'deps'    => array( $cycle_a ),
					'version' => 'cycle-1',
				),
			),
		);
	}

	private static function corpus_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$prefix = 'cf-assets-' . $ctx->iteration() . '-' . substr( sha1( (string) $ctx->seed() ), 0, 8 );
		$cases  = array(
			array(
				'profile' => 'corpus-diamond',
				'items'   => array(
					self::item( "{$prefix}-base", 'assets/base.js', array(), '1.0.0' ),
					self::item( "{$prefix}-shared.dep", 'assets/shared.js', array( "{$prefix}-base" ), null ),
					self::item( "{$prefix}-feature:one", 'assets/feature.js', array( "{$prefix}-base" ), false ),
					self::item( "{$prefix}-entry", 'assets/entry.js', array( "{$prefix}-shared.dep", "{$prefix}-feature:one", "{$prefix}-shared.dep" ), 'entry&<1' ),
				),
				'queue'   => array( "{$prefix}-entry", "{$prefix}-feature:one", "{$prefix}-entry" ),
			),
			array(
				'profile' => 'corpus-punctuation-unicode',
				'items'   => array(
					self::item( "{$prefix}-ümlaut", 'assets/unicode.js', array(), '☃-1' ),
					self::item( "{$prefix}-quote\"amp&", 'assets/quote amp.js?x=1&y=2#frag', array( "{$prefix}-ümlaut" ), 'v 2' ),
					self::item( "{$prefix}-slash/name", 'assets/slash-name.js', array( "{$prefix}-quote\"amp&" ), null ),
					self::item( "{$prefix}-alias", false, array( "{$prefix}-slash/name" ), false ),
				),
				'queue'   => array( "{$prefix}-alias", "{$prefix}-slash/name" ),
			),
			array(
				'profile' => 'corpus-alias-and-duplicates',
				'items'   => array(
					self::item( "{$prefix}-leaf-a", 'assets/leaf-a.js', array(), '' ),
					self::item( "{$prefix}-leaf-b", 'assets/leaf-b.js', array(), 'leaf-b' ),
					self::item( "{$prefix}-alias-only", false, array( "{$prefix}-leaf-a", "{$prefix}-leaf-b", "{$prefix}-leaf-a" ), null ),
					self::item( "{$prefix}-consumer", 'assets/consumer.js', array( "{$prefix}-alias-only" ), 'consumer' ),
				),
				'queue'   => array( "{$prefix}-consumer", "{$prefix}-alias-only", "{$prefix}-consumer" ),
			),
		);

		return $cases[ $ctx->iteration() % count( $cases ) ];
	}

	private static function generated_items( \ComponentFuzz\FuzzContext $ctx ): array {
		$count   = $ctx->int( 3, 4 );
		$items   = array();
		$handles = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$handle = self::generated_handle( $ctx, $i );
			while ( in_array( $handle, $handles, true ) ) {
				$handle .= '-dup';
			}

			$deps = array();
			if ( $i > 0 ) {
				$dep_count = $ctx->int( 0, min( 2, $i ) );
				for ( $j = 0; $j < $dep_count; $j++ ) {
					$deps[] = $handles[ $ctx->int( 0, $i - 1 ) ];
				}
				if ( $deps && $ctx->bool( 35 ) ) {
					$deps[] = $deps[0];
				}
			}

			$items[]   = self::item( $handle, self::generated_src( $ctx, $i ), $deps, self::generated_version( $ctx ), self::generated_media( $ctx ) );
			$handles[] = $handle;
		}

		$queue = array();
		if ( $handles ) {
			$queue[] = $handles[ count( $handles ) - 1 ];
			$queue[] = $handles[ $ctx->int( 0, count( $handles ) - 1 ) ];
			$queue[] = $handles[ count( $handles ) - 1 ];
		}

		return array(
			'items' => $items,
			'queue' => $queue,
		);
	}

	private static function generated_handle( \ComponentFuzz\FuzzContext $ctx, int $index ): string {
		$atoms = array(
			'alpha',
			'dep.shared',
			'colon:module',
			'slash/name',
			'quote"mark',
			'apos\'mark',
			'amp&mark',
			'ümlaut',
			'中',
			$ctx->identifier( 3, 10 ),
		);

		return 'cf-gen-' . $ctx->iteration() . '-' . $index . '-' . $ctx->choice( $atoms );
	}

	private static function generated_src( \ComponentFuzz\FuzzContext $ctx, int $index ) {
		$sources = array(
			'assets/generated-' . $index . '.js',
			'assets/generated-' . $index . '.js?x=' . $ctx->int( 0, 99 ) . '#frag',
			'https://cdn.example.test/assets/generated-' . $index . '.js',
			'//cdn.example.test/assets/generated-' . $index . '.js',
			false,
		);

		return $ctx->choice( $sources );
	}

	private static function generated_version( \ComponentFuzz\FuzzContext $ctx ) {
		return $ctx->choice(
			array(
				false,
				null,
				'',
				(string) $ctx->int( 0, 9 ) . '.' . $ctx->int( 0, 99 ),
				'v ' . $ctx->int( 1, 9 ) . '&<"☃',
			)
		);
	}

	private static function generated_media( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				'all',
				'screen',
				'print',
				'(min-width: ' . $ctx->int( 1, 900 ) . 'px)',
				'screen and (orientation: landscape)',
			)
		);
	}

	private static function item( string $handle, $src, array $deps, $version, string $media = 'all' ): array {
		return array(
			'handle'  => $handle,
			'src'     => $src,
			'deps'    => array_values( $deps ),
			'version' => $version,
			'media'   => $media,
		);
	}

	private static function unique_items( array $items ): array {
		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			if ( isset( $seen[ $item['handle'] ] ) ) {
				continue;
			}
			$seen[ $item['handle'] ] = true;
			$out[] = $item;
		}
		return $out;
	}

	private static function first_concrete_item( array $case ): array {
		foreach ( $case['items'] as $item ) {
			if ( false !== $item['src'] ) {
				return $item;
			}
		}
		return $case['items'][0];
	}

	private static function expected_topological_order( array $items, array $queue ): array {
		$by_handle = array();
		foreach ( $items as $item ) {
			$by_handle[ $item['handle'] ] = $item;
		}

		$out      = array();
		$visiting = array();
		$visited  = array();
		$visit    = static function ( string $handle ) use ( &$visit, &$out, &$visiting, &$visited, $by_handle ): void {
			if ( isset( $visited[ $handle ] ) || isset( $visiting[ $handle ] ) || ! isset( $by_handle[ $handle ] ) ) {
				return;
			}

			$visiting[ $handle ] = true;
			foreach ( $by_handle[ $handle ]['deps'] as $dep ) {
				$visit( $dep );
			}
			unset( $visiting[ $handle ] );

			$visited[ $handle ] = true;
			$out[]              = $handle;
		};

		foreach ( $queue as $queued ) {
			$visit( explode( '?', $queued )[0] );
		}

		return $out;
	}

	private static function dependency_order_holds( array $items, array $actual ): bool {
		$positions = array_flip( $actual );
		foreach ( $items as $item ) {
			if ( ! isset( $positions[ $item['handle'] ] ) ) {
				continue;
			}
			foreach ( array_unique( $item['deps'] ) as $dep ) {
				if ( isset( $positions[ $dep ] ) && $positions[ $dep ] > $positions[ $item['handle'] ] ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function has_no_duplicates( array $values ): bool {
		return count( $values ) === count( array_unique( $values ) );
	}

	private static function printed_concrete_handles_present( array $items, array $actual, string $output, string $type ): bool {
		$by_handle = array();
		foreach ( $items as $item ) {
			$by_handle[ $item['handle'] ] = $item;
		}

		$ids = self::attribute_values( $output, 'id' );
		foreach ( $actual as $handle ) {
			if ( ! isset( $by_handle[ $handle ] ) || false === $by_handle[ $handle ]['src'] ) {
				continue;
			}

			$suffix = 'script' === $type ? '-js' : '-css';
			if ( ! in_array( $handle . $suffix, $ids, true ) ) {
				return false;
			}
		}

		return true;
	}

	private static function style_src_from_script_src( $src ) {
		if ( false === $src ) {
			return false;
		}
		return preg_replace( '/\.js(\?|#|$)/', '.css$1', (string) $src );
	}

	private static function first_attribute( string $html, string $attribute ): ?string {
		if ( preg_match( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $html, $matches ) ) {
			return $matches[2];
		}
		return null;
	}

	private static function attribute_values( string $html, string $attribute ): array {
		if ( ! preg_match_all( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $html, $matches ) ) {
			return array();
		}

		return array_map(
			static fn( string $value ): string => self::decode_html_attribute( $value ),
			$matches[2]
		);
	}

	private static function attribute_position( string $html, string $attribute, string $decoded_value ) {
		if ( ! preg_match_all( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		foreach ( $matches[2] as $index => $match ) {
			if ( $decoded_value === self::decode_html_attribute( $match[0] ) ) {
				return $matches[0][ $index ][1];
			}
		}

		return false;
	}

	private static function link_tags_by_id( string $html ): array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$links     = array();

		while ( $processor->next_tag( 'LINK' ) ) {
			$attributes = array();
			foreach ( array( 'id', 'rel', 'href', 'media', 'title', 'data-cfz-style-meta' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( null !== $value ) {
					$attributes[ $attribute ] = true === $value ? true : (string) $value;
				}
			}

			if ( isset( $attributes['id'] ) && is_string( $attributes['id'] ) ) {
				$links[ $attributes['id'] ] = $attributes;
			}
		}

		return $links;
	}

	private static function decode_html_attribute( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function contains_in_order( string $haystack, array $needles ): bool {
		$offset = 0;
		foreach ( $needles as $needle ) {
			$pos = strpos( $haystack, $needle, $offset );
			if ( false === $pos ) {
				return false;
			}
			$offset = $pos + strlen( $needle );
		}
		return true;
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
			);
		}
	}

	private static function capture_output( callable $callback ): array {
		$level = ob_get_level();
		ob_start();
		try {
			$value  = $callback();
			$output = ob_get_clean();
			return array(
				'threw'  => false,
				'value'  => $value,
				'output' => $output,
			);
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
				'value'     => null,
				'output'    => '',
			);
		}
	}

	private static function describe_call( array $call ): array {
		return array(
			'threw'     => (bool) ( $call['threw'] ?? false ),
			'value'     => self::describe_value( $call['value'] ?? null ),
			'throwable' => $call['throwable'] ?? null,
		);
	}

	private static function describe_value( $value ) {
		if ( is_array( $value ) ) {
			return array(
				'type'  => 'array',
				'count' => count( $value ),
				'value' => $value,
			);
		}
		if ( is_object( $value ) ) {
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

	private static function case_data( array $case ): array {
		return array(
			'profile'      => $case['profile'],
			'itemCount'    => count( $case['items'] ),
			'handles'      => array_column( $case['items'], 'handle' ),
			'itemsPreview' => self::preview_items( $case['items'] ),
		);
	}

	private static function preview_items( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'handle'  => self::preview( $item['handle'] ),
				'src'     => self::preview( is_string( $item['src'] ) ? $item['src'] : '[alias]' ),
				'deps'    => $item['deps'],
				'version' => self::preview( self::version_label( $item['version'] ) ),
				'media'   => $item['media'],
			);
		}
		return $out;
	}

	private static function version_label( $version ): string {
		if ( false === $version ) {
			return '[default]';
		}
		if ( null === $version ) {
			return '[none]';
		}
		return (string) $version;
	}

	private static function preview( string $value, int $limit = self::PREVIEW_BYTES ): string {
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

	private static function handle( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'cf-assets-' . $ctx->iteration() . '-' . substr( sha1( $ctx->seed() . ':' . $label ), 0, 10 ) . '-' . $label;
	}

	private static function module_id( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return '@component-fuzz/' . $ctx->iteration() . '/' . substr( sha1( $ctx->seed() . ':module:' . $label ), 0, 8 ) . '/' . $label;
	}

	private static function prepare_asset_globals(): void {
		$GLOBALS['wp_actions']['init'] = max( 1, (int) ( $GLOBALS['wp_actions']['init'] ?? 0 ) );
		self::reset_scripts_global();
		self::reset_styles_global();
		if ( class_exists( 'WP_Script_Modules' ) ) {
			self::reset_script_modules_global();
		}
	}

	private static function reset_scripts_global(): void {
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		\wp_scripts()->base_url        = self::BASE_URL;
		\wp_scripts()->content_url     = self::CONTENT_URL;
		\wp_scripts()->default_version = self::DEFAULT_VERSION;
		\wp_scripts()->default_dirs    = array();
	}

	private static function reset_styles_global(): void {
		$GLOBALS['wp_styles'] = new \WP_Styles();
		\wp_styles()->base_url        = self::BASE_URL;
		\wp_styles()->content_url     = self::CONTENT_URL;
		\wp_styles()->default_version = self::DEFAULT_VERSION;
		\wp_styles()->default_dirs    = array();
	}

	private static function reset_script_modules_global(): void {
		$GLOBALS['wp_script_modules'] = new \WP_Script_Modules();
	}

	private static function snapshot_globals(): array {
		$snapshot = array();
		foreach (
			array(
				'wp_scripts',
				'wp_styles',
				'wp_script_modules',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'pagenow',
			) as $name
		) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
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
}
