<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB frontend feature helper APIs.
 */
final class FrontendFeaturesSurface {
	public const NAME = 'frontend-features';

	private const PREVIEW_BYTES = 220;
	private const PASS_FILTER   = '__component_fuzz_pass_filter__';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'frontend-features.bootstrap-apis-available',
					'Required WordPress frontend feature APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$ob_level = ob_get_level();
		$rows     = array();

		try {
			$rows[] = self::check_speculation_configuration( $ctx->fork( 'configuration' ) );
			$rows[] = self::check_speculation_rule_shapes( $ctx->fork( 'rules' ) );
			$rows[] = self::check_speculation_printed_tag( $ctx->fork( 'printed-tag' ) );
			$rows[] = self::check_url_pattern_prefixer( $ctx->fork( 'url-prefixer' ) );
			$rows[] = self::check_view_transition_helpers( $ctx->fork( 'view-transitions' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'frontend-features.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'frontend-features.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedServer'  => array_keys( $snapshot['server'] ),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Speculation_Rules', 'WP_URL_Pattern_Prefixer', 'WP_Styles', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_theme_support',
				'current_theme_supports',
				'did_action',
				'get_option',
				'home_url',
				'is_user_logged_in',
				'remove_action',
				'remove_filter',
				'remove_theme_support',
				'site_url',
				'wp_default_styles',
				'wp_enqueue_view_transitions_admin_css',
				'wp_get_inline_script_tag',
				'wp_get_speculation_rules',
				'wp_get_speculation_rules_configuration',
				'wp_get_view_transitions_admin_css',
				'wp_json_encode',
				'wp_print_speculation_rules',
				'wp_set_current_user',
				'wp_style_is',
				'wp_styles',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'SCRIPT_DEBUG' ) ) {
			$missing[] = 'constant SCRIPT_DEBUG';
		}

		return $missing;
	}

	private static function check_speculation_configuration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::configuration_cases( $ctx );

		foreach ( $cases as $index => $case ) {
			$raw_default       = null;
			$permalink_filter  = static function () use ( $case ): string {
				return $case['permalink'];
			};
			$config_filter     = static function ( $config ) use ( $case, &$raw_default ) {
				$raw_default = $config;

				return self::PASS_FILTER === $case['filterValue'] ? $config : $case['filterValue'];
			};
			$expected_raw      = self::default_speculation_configuration( $case['loggedIn'], $case['permalink'] );
			$expected_filtered = self::expected_speculation_configuration( $case['filterValue'], $expected_raw );

			\add_filter( 'pre_option_permalink_structure', $permalink_filter );
			\add_filter( 'wp_speculation_rules_configuration', $config_filter );
			try {
				self::set_logged_in( $case['loggedIn'], $ctx->seed() + $index + 1 );

				$first  = \wp_get_speculation_rules_configuration();
				$second = \wp_get_speculation_rules_configuration();
				$logged = \is_user_logged_in();
			} finally {
				\remove_filter( 'wp_speculation_rules_configuration', $config_filter );
				\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
				\wp_set_current_user( 0 );
			}

			self::collect_failure(
				$failures,
				$expected_raw === $raw_default
					&& $expected_filtered === $first
					&& $first === $second
					&& $case['loggedIn'] === $logged
					&& self::configuration_shape_is_safe( $first ),
				"speculation configuration sanitization case {$index}",
				array(
					'label'       => $case['label'],
					'filterValue' => $case['filterValue'],
					'rawDefault'  => $raw_default,
					'expected'    => $expected_filtered,
					'first'       => $first,
					'second'      => $second,
					'loggedIn'    => $logged,
				)
			);
		}

		return $ctx->result(
			'frontend-features.speculation.configuration-eligibility-and-sanitization',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_speculation_rule_shapes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			self::rules_case( $ctx->fork( 'prefetch' ), 'prefetch' ),
			self::rules_case( $ctx->fork( 'prerender' ), 'prerender' ),
		);

		foreach ( $cases as $case ) {
			$seen_objects       = array();
			$config_filter      = static function () use ( $case ): array {
				return array(
					'mode'      => $case['mode'],
					'eagerness' => $case['eagerness'],
				);
			};
			$permalink_filter   = static function () use ( $case ): string {
				return $case['pretty'] ? '/%postname%/' : '';
			};
			$home_filter        = static function () use ( $case ): string {
				return $case['homeUrl'];
			};
			$siteurl_filter     = static function () use ( $case ): string {
				return $case['siteUrl'];
			};
			$template_filter    = static function () use ( $case ): string {
				return $case['templateUri'];
			};
			$stylesheet_filter  = static function () use ( $case ): string {
				return $case['stylesheetUri'];
			};
			$exclude_filter     = static function ( array $paths, string $mode ) use ( $case ): array {
				$paths[10]          = $case['customProductPath'];
				$paths['members']   = $case['customMemberPath'];
				$paths['duplicate'] = $case['customProductPath'];

				if ( 'prerender' === $mode ) {
					$paths[] = $case['customPrerenderOnlyPath'];
				}

				return $paths;
			};
			$load_rules_action  = static function ( \WP_Speculation_Rules $rules ) use ( $case, &$seen_objects ): void {
				$seen_objects[] = spl_object_id( $rules );
				$rules->add_rule(
					'prefetch',
					$case['listRuleId'],
					array(
						'source'    => 'list',
						'urls'      => array(
							$case['safeUrl'],
							$case['unsafeUrl'],
						),
						'eagerness' => 'immediate',
					)
				);
			};

			\add_filter( 'wp_speculation_rules_configuration', $config_filter );
			\add_filter( 'pre_option_permalink_structure', $permalink_filter );
			\add_filter( 'pre_option_home', $home_filter );
			\add_filter( 'pre_option_siteurl', $siteurl_filter );
			\add_filter( 'template_directory_uri', $template_filter );
			\add_filter( 'stylesheet_directory_uri', $stylesheet_filter );
			\add_filter( 'wp_speculation_rules_href_exclude_paths', $exclude_filter, 10, 2 );
			\add_action( 'wp_load_speculation_rules', $load_rules_action );

			try {
				self::set_logged_in( false, $ctx->seed() );

				$first_obj  = \wp_get_speculation_rules();
				$second_obj = \wp_get_speculation_rules();
			} finally {
				\remove_action( 'wp_load_speculation_rules', $load_rules_action );
				\remove_filter( 'wp_speculation_rules_href_exclude_paths', $exclude_filter, 10 );
				\remove_filter( 'stylesheet_directory_uri', $stylesheet_filter );
				\remove_filter( 'template_directory_uri', $template_filter );
				\remove_filter( 'pre_option_siteurl', $siteurl_filter );
				\remove_filter( 'pre_option_home', $home_filter );
				\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
				\remove_filter( 'wp_speculation_rules_configuration', $config_filter );
				\wp_set_current_user( 0 );
			}

			$first  = $first_obj instanceof \WP_Speculation_Rules ? $first_obj->jsonSerialize() : null;
			$second = $second_obj instanceof \WP_Speculation_Rules ? $second_obj->jsonSerialize() : null;
			$main   = is_array( $first ) ? ( $first[ $case['mode'] ][0] ?? null ) : null;
			$where  = is_array( $main ) ? ( $main['where']['and'] ?? null ) : null;
			$hrefs  = is_array( $where ) ? ( $where[1]['not']['href_matches'] ?? null ) : null;

			$expected_excludes = self::expected_base_exclude_paths( $case );
			$expected_custom   = array(
				self::prefix_path( $case['homePath'], $case['customProductPath'] ),
				self::prefix_path( $case['homePath'], $case['customMemberPath'] ),
			);
			if ( 'prerender' === $case['mode'] ) {
				$expected_custom[] = self::prefix_path( $case['homePath'], $case['customPrerenderOnlyPath'] );
			}

			self::collect_failure(
				$failures,
				$first_obj instanceof \WP_Speculation_Rules
					&& $second_obj instanceof \WP_Speculation_Rules
					&& $first === $second
					&& array( spl_object_id( $first_obj ), spl_object_id( $second_obj ) ) === $seen_objects
					&& is_array( $main )
					&& 'document' === ( $main['source'] ?? null )
					&& $case['eagerness'] === ( $main['eagerness'] ?? null )
					&& is_array( $where )
					&& self::expected_condition_count( $case['mode'] ) === count( $where )
					&& self::prefix_path( $case['homePath'], '/*' ) === ( $where[0]['href_matches'] ?? null )
					&& '.no-' . $case['mode'] . ', .no-' . $case['mode'] . ' a' === ( $where[3]['not']['selector_matches'] ?? null )
					&& ( 'prefetch' === $case['mode'] || '.no-prefetch, .no-prefetch a' === ( $where[4]['not']['selector_matches'] ?? null ) )
					&& is_array( $hrefs )
					&& array_is_list( $hrefs )
					&& count( $hrefs ) === count( array_unique( $hrefs ) )
					&& array() === array_diff( $expected_excludes, $hrefs )
					&& array() === array_diff( $expected_custom, $hrefs )
					&& self::serialized_rules_contain_url( $first, 'prefetch', $case['unsafeUrl'] ),
				'speculation rules preserve generated document-rule shape, mode-specific exclusions, list keys, and load action additions',
				array(
					'case'              => $case['label'],
					'serialized'        => $first,
					'expectedExcludes'  => $expected_excludes,
					'expectedCustom'    => $expected_custom,
					'seenObjectIds'     => $seen_objects,
					'expectedMainCount' => self::expected_condition_count( $case['mode'] ),
				)
			);
		}

		return $ctx->result(
			'frontend-features.speculation.rules-url-shapes-and-filters',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_speculation_printed_tag( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$slug           = self::slug( $ctx, 'tag' );
		$mode           = $ctx->bool() ? 'prefetch' : 'prerender';
		$unsafe_url     = '/unsafe-' . $slug . '</script><script>alert(1)</script>';
		$config_filter  = static function () use ( $mode ): array {
			return array(
				'mode'      => $mode,
				'eagerness' => 'moderate',
			);
		};
		$load_action    = static function ( \WP_Speculation_Rules $rules ) use ( $slug, $unsafe_url ): void {
			$rules->add_rule(
				'prefetch',
				'tag-' . $slug,
				array(
					'source'    => 'list',
					'urls'      => array( $unsafe_url ),
					'eagerness' => 'eager',
				)
			);
		};
		$disabled_filter = static function (): ?array {
			return null;
		};
		$theme_results   = array();

		\add_filter( 'wp_speculation_rules_configuration', $config_filter );
		\add_action( 'wp_load_speculation_rules', $load_action );
		try {
			foreach ( array( true, false ) as $html5_script_support ) {
				if ( $html5_script_support ) {
					\add_theme_support( 'html5', array( 'script' ) );
				} else {
					\remove_theme_support( 'html5' );
				}

				$first   = self::capture_output( static fn() => \wp_print_speculation_rules() );
				$second  = self::capture_output( static fn() => \wp_print_speculation_rules() );
				$decoded = self::decode_speculation_output( $first );
				$lower   = strtolower( $first );

				$theme_results[] = array(
					'html5ScriptSupport' => $html5_script_support,
					'currentSupport'     => \current_theme_supports( 'html5', 'script' ),
					'first'              => self::preview( $first ),
					'secondMatches'      => $first === $second,
					'decoded'            => $decoded['ok'] ? array_keys( $decoded['rules'] ) : $decoded,
				);

				self::collect_failure(
					$failures,
					$first === $second
						&& $html5_script_support === \current_theme_supports( 'html5', 'script' )
						&& $decoded['ok']
						&& isset( $decoded['rules'][ $mode ] )
						&& self::serialized_rules_contain_url( $decoded['rules'], 'prefetch', $unsafe_url )
						&& 1 === substr_count( $lower, '<script' )
						&& 1 === substr_count( $lower, '</script>' )
						&& str_contains( $first, '<script type="speculationrules">' )
						&& ! str_contains( $first, '</script><script' )
						&& ! str_contains( $first, '<script>alert' )
						&& str_contains( $first, '\u003C/script\u003E' )
						&& str_contains( $first, '\u003Cscript\u003E' ),
					'speculation rules script tag remains parseable, escaped, and deterministic across theme HTML5 support states',
					array(
						'html5ScriptSupport' => $html5_script_support,
						'output'             => $first,
						'decoded'            => $decoded,
						'unsafeUrl'          => $unsafe_url,
					)
				);
			}
		} finally {
			\remove_action( 'wp_load_speculation_rules', $load_action );
			\remove_filter( 'wp_speculation_rules_configuration', $config_filter );
		}

		\add_filter( 'wp_speculation_rules_configuration', $disabled_filter );
		try {
			$disabled_output = self::capture_output( static fn() => \wp_print_speculation_rules() );
		} finally {
			\remove_filter( 'wp_speculation_rules_configuration', $disabled_filter );
		}

		self::collect_failure(
			$failures,
			'' === $disabled_output,
			'disabled speculation configuration prints no script tag',
			array( 'disabledOutput' => $disabled_output )
		);

		return $ctx->result(
			'frontend-features.speculation.printed-tag-escaping-idempotence-and-theme-support',
			array() === $failures,
			array(
				'mode'          => $mode,
				'themeResults'  => $theme_results,
				'disabledEmpty' => '' === $disabled_output,
				'failures'      => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_url_pattern_prefixer( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$slug     = self::slug( $ctx, 'prefix' );

		$home_path = '/front-' . $slug;
		$site_path = '/core-' . $slug;
		$prefixer  = new \WP_URL_Pattern_Prefixer(
			array(
				'home' => $home_path,
				'site' => $site_path,
			)
		);

		$home_pattern      = '/products/' . $slug . '/*';
		$prefixed_home     = $prefixer->prefix_path_pattern( $home_pattern );
		$prefixed_home_two = $prefixer->prefix_path_pattern( $prefixed_home );
		$prefixed_site     = $prefixer->prefix_path_pattern( '/wp-admin/*', 'site' );

		$special_context  = '/front:' . $slug . '?draft';
		$special_prefixer = new \WP_URL_Pattern_Prefixer( array( 'home' => $special_context ) );
		$special          = $special_prefixer->prefix_path_pattern( '/next/*' );

		self::collect_failure(
			$failures,
			self::prefix_path( $home_path, $home_pattern ) === $prefixed_home
				&& $prefixed_home === $prefixed_home_two
				&& self::prefix_path( $site_path, '/wp-admin/*' ) === $prefixed_site
				&& ! str_contains( $prefixed_home, '//' )
				&& str_starts_with( $special, '{/front\\:' )
				&& str_contains( $special, '\\?draft}/next/*' ),
			'URL pattern prefixing is deterministic, does not double-prefix, and escapes grouping-sensitive context paths',
			array(
				'homePath'        => $home_path,
				'sitePath'        => $site_path,
				'homePattern'     => $home_pattern,
				'prefixedHome'    => $prefixed_home,
				'prefixedHomeTwo' => $prefixed_home_two,
				'prefixedSite'    => $prefixed_site,
				'specialContext'  => $special_context,
				'special'         => $special,
			)
		);

		return $ctx->result(
			'frontend-features.url-pattern-prefixer.idempotence-and-escaping',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_view_transition_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$GLOBALS['wp_actions']['init'] = max( 1, (int) ( $GLOBALS['wp_actions']['init'] ?? 0 ) );
		$GLOBALS['wp_styles']         = new \WP_Styles();
		\wp_default_styles( \wp_styles() );

		$css_first  = \wp_get_view_transitions_admin_css();
		$css_second = \wp_get_view_transitions_admin_css();
		$after_data = \wp_styles()->get_data( 'wp-view-transitions-admin', 'after' );
		$registered = \wp_styles()->registered['wp-view-transitions-admin'] ?? null;
		$before     = \wp_style_is( 'wp-view-transitions-admin' );

		\wp_enqueue_view_transitions_admin_css();
		$after_first_enqueue = \wp_style_is( 'wp-view-transitions-admin' );
		\wp_enqueue_view_transitions_admin_css();
		$queue_count = count( array_keys( \wp_styles()->queue, 'wp-view-transitions-admin', true ) );

		self::collect_failure(
			$failures,
			is_string( $css_first )
				&& $css_first === $css_second
				&& str_contains( $css_first, '@view-transition' )
				&& str_contains( $css_first, 'navigation: auto' )
				&& ! str_contains( strtolower( $css_first ), '<script' )
				&& is_array( $after_data )
				&& in_array( $css_first, $after_data, true )
				&& $registered instanceof \_WP_Dependency
				&& false === $registered->src
				&& false === $before
				&& true === $after_first_enqueue
				&& 1 === $queue_count,
			'view transition admin CSS is readable, registered inline after init, and enqueue is idempotent',
			array(
				'cssPreview'         => self::preview( $css_first ),
				'afterData'          => $after_data,
				'registered'         => $registered instanceof \_WP_Dependency ? array(
					'handle' => $registered->handle,
					'src'    => $registered->src,
				) : self::preview( $registered ),
				'before'             => $before,
				'afterFirstEnqueue'  => $after_first_enqueue,
				'queue'              => \wp_styles()->queue,
				'queueCountForStyle' => $queue_count,
			)
		);

		return $ctx->result(
			'frontend-features.view-transitions.css-and-enqueue-idempotence',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function configuration_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$valid_mode       = $ctx->choice( array( 'prefetch', 'prerender' ) );
		$valid_eagerness  = $ctx->choice( array( 'conservative', 'moderate', 'eager' ) );
		$invalid_mode     = $ctx->choice( array( 'fetch', 'PREfetch', '', $ctx->identifier( 4, 10 ) ) );
		$invalid_eagerness = $ctx->choice( array( 'lazy', 'Immediate', '', $ctx->identifier( 4, 10 ) ) );

		return array(
			array(
				'label'       => 'default eligible pretty permalinks',
				'loggedIn'    => false,
				'permalink'   => '/%postname%/',
				'filterValue' => self::PASS_FILTER,
			),
			array(
				'label'       => 'default disabled without pretty permalinks',
				'loggedIn'    => false,
				'permalink'   => '',
				'filterValue' => self::PASS_FILTER,
			),
			array(
				'label'       => 'default disabled for logged-in user',
				'loggedIn'    => true,
				'permalink'   => '/%year%/%postname%/',
				'filterValue' => self::PASS_FILTER,
			),
			array(
				'label'       => 'valid filtered configuration',
				'loggedIn'    => (bool) $ctx->bool(),
				'permalink'   => $ctx->bool() ? '/%postname%/' : '',
				'filterValue' => array(
					'mode'      => $valid_mode,
					'eagerness' => $valid_eagerness,
				),
			),
			array(
				'label'       => 'auto and immediate collapse to safe defaults',
				'loggedIn'    => false,
				'permalink'   => '/%postname%/',
				'filterValue' => array(
					'mode'      => 'auto',
					'eagerness' => 'immediate',
				),
			),
			array(
				'label'       => 'invalid filtered values collapse to safe defaults',
				'loggedIn'    => false,
				'permalink'   => '/%postname%/',
				'filterValue' => array(
					'mode'      => $invalid_mode,
					'eagerness' => $invalid_eagerness,
				),
			),
			array(
				'label'       => 'non-array filter falls back to defaults',
				'loggedIn'    => true,
				'permalink'   => '',
				'filterValue' => $ctx->bool() ? true : 'not-a-config',
			),
			array(
				'label'       => 'null filter disables speculation rules',
				'loggedIn'    => false,
				'permalink'   => '/%postname%/',
				'filterValue' => null,
			),
		);
	}

	private static function rules_case( \ComponentFuzz\FuzzContext $ctx, string $mode ): array {
		$slug = self::slug( $ctx, $mode );

		return array(
			'label'                   => $mode . '-' . $slug,
			'mode'                    => $mode,
			'eagerness'               => $ctx->choice( array( 'conservative', 'moderate', 'eager' ) ),
			'pretty'                  => $ctx->bool(),
			'homePath'                => '/front-' . $slug,
			'sitePath'                => '/core-' . $slug,
			'homeUrl'                 => 'https://example.test/front-' . $slug,
			'siteUrl'                 => 'https://example.test/core-' . $slug,
			'templateUri'             => 'https://example.test/wp-content/themes/template-' . $slug,
			'stylesheetUri'           => 'https://example.test/wp-content/themes/stylesheet-' . $slug,
			'customProductPath'       => 'products/' . $slug . '/*',
			'customMemberPath'        => '/members/' . $slug . '/?token=*',
			'customPrerenderOnlyPath' => '/checkout/' . $slug . '/*',
			'listRuleId'              => 'list-' . $slug,
			'safeUrl'                 => '/safe-' . $slug,
			'unsafeUrl'               => '/unsafe-' . $slug . '?q=<tag>&close=</script>',
		);
	}

	private static function default_speculation_configuration( bool $logged_in, string $permalink ): ?array {
		if ( ! $logged_in && '' !== $permalink ) {
			return array(
				'mode'      => 'auto',
				'eagerness' => 'auto',
			);
		}

		return null;
	}

	private static function expected_speculation_configuration( $filter_value, ?array $raw_default ): ?array {
		$config = self::PASS_FILTER === $filter_value ? $raw_default : $filter_value;

		if ( null === $config ) {
			return null;
		}

		$default = array(
			'mode'      => 'prefetch',
			'eagerness' => 'conservative',
		);

		if ( ! is_array( $config ) ) {
			return $default;
		}

		$mode = $config['mode'] ?? 'auto';
		if ( ! is_scalar( $mode ) || 'auto' === $mode || ! \WP_Speculation_Rules::is_valid_mode( (string) $mode ) ) {
			$mode = $default['mode'];
		}

		$eagerness = $config['eagerness'] ?? 'auto';
		if (
			! is_scalar( $eagerness )
			|| 'auto' === $eagerness
			|| 'immediate' === $eagerness
			|| ! \WP_Speculation_Rules::is_valid_eagerness( (string) $eagerness )
		) {
			$eagerness = $default['eagerness'];
		}

		return array(
			'mode'      => (string) $mode,
			'eagerness' => (string) $eagerness,
		);
	}

	private static function configuration_shape_is_safe( ?array $configuration ): bool {
		if ( null === $configuration ) {
			return true;
		}

		return array( 'mode', 'eagerness' ) === array_keys( $configuration )
			&& \WP_Speculation_Rules::is_valid_mode( $configuration['mode'] )
			&& \WP_Speculation_Rules::is_valid_eagerness( $configuration['eagerness'] )
			&& 'immediate' !== $configuration['eagerness'];
	}

	private static function expected_base_exclude_paths( array $case ): array {
		$query_exclude = $case['pretty'] ? '/*\\?(.+)' : '/*\\?*(^|&)*nonce*=*';

		return array(
			self::prefix_path( $case['sitePath'], '/wp-*.php' ),
			self::prefix_path( $case['sitePath'], '/wp-admin/*' ),
			'/wp-content/uploads/*',
			'/wp-content/*',
			'/wp-content/plugins/*',
			'/wp-content/themes/stylesheet-' . self::case_slug( $case ) . '/*',
			'/wp-content/themes/template-' . self::case_slug( $case ) . '/*',
			self::prefix_path( $case['homePath'], $query_exclude ),
		);
	}

	private static function expected_condition_count( string $mode ): int {
		return 'prerender' === $mode ? 5 : 4;
	}

	private static function serialized_rules_contain_url( $serialized, string $mode, string $url ): bool {
		if ( ! is_array( $serialized ) || ! isset( $serialized[ $mode ] ) || ! is_array( $serialized[ $mode ] ) ) {
			return false;
		}

		foreach ( $serialized[ $mode ] as $rule ) {
			if ( is_array( $rule ) && isset( $rule['urls'] ) && is_array( $rule['urls'] ) && in_array( $url, $rule['urls'], true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function prefix_path( string $base_path, string $path_pattern ): string {
		return rtrim( $base_path, '/' ) . '/' . ltrim( $path_pattern, '/' );
	}

	private static function case_slug( array $case ): string {
		return substr( (string) $case['label'], strlen( (string) $case['mode'] ) + 1 );
	}

	private static function set_logged_in( bool $logged_in, int $user_id ): void {
		if ( ! $logged_in ) {
			\wp_set_current_user( 0 );
			return;
		}

		$user_id                  = max( 1, $user_id % 100000 );
		$GLOBALS['current_user']  = new \WP_User(
			(object) array(
				'ID'            => $user_id,
				'user_login'    => 'component_fuzz_' . $user_id,
				'user_nicename' => 'component-fuzz-' . $user_id,
				'user_email'    => 'component-fuzz-' . $user_id . '@example.test',
				'display_name'  => 'Component Fuzz ' . $user_id,
			)
		);
		$GLOBALS['user_ID']       = $user_id;
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

	private static function decode_speculation_output( string $output ): array {
		if ( ! preg_match( '/^<script\b[^>]*type=(["\'])speculationrules\1[^>]*>\s*(.*?)\s*<\/script>\s*$/s', $output, $matches ) ) {
			return array(
				'ok'     => false,
				'reason' => 'script tag pattern did not match',
				'output' => self::preview( $output ),
			);
		}

		$decoded = json_decode( trim( $matches[2] ), true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'        => false,
				'reason'    => 'script contents were not JSON',
				'jsonError' => json_last_error_msg(),
				'json'      => self::preview( $matches[2] ),
			);
		}

		return array(
			'ok'    => true,
			'rules' => $decoded,
		);
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return strtolower( $prefix . '-' . substr( hash( 'sha256', $ctx->seed() . ':' . $prefix ), 0, 8 ) );
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

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'_wp_theme_features',
					'current_user',
					'editor_styles',
					'user_ID',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_styles',
				)
			),
			'server'  => self::snapshot_server( array( 'HTTP_HOST', 'HTTPS', 'REQUEST_URI', 'SERVER_NAME' ) ),
		);
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

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && ! self::values_match( $GLOBALS[ $name ], $entry['value'] ) ) {
				return false;
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			$exists = array_key_exists( $name, $_SERVER );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $_SERVER[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function values_match( $actual, $expected ): bool {
		if ( is_array( $actual ) || is_array( $expected ) || is_object( $actual ) || is_object( $expected ) ) {
			return $actual == $expected;
		}

		return $actual === $expected;
	}

	private static function clone_value( $value ) {
		if ( $value instanceof \Closure ) {
			return $value;
		}

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

	private static function preview( $value ) {
		return \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES );
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
