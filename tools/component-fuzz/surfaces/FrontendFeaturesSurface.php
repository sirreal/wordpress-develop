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
			$rows[] = self::check_speculation_rules_class_validation( $ctx->fork( 'rules-class' ) );
			$rows[] = self::check_speculation_rule_shapes( $ctx->fork( 'rules' ) );
			$rows[] = self::check_speculation_printed_tag( $ctx->fork( 'printed-tag' ) );
			$rows[] = self::check_speculation_disabled_lifecycle( $ctx->fork( 'disabled-lifecycle' ) );
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
				'has_action',
				'has_filter',
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

	private static function check_speculation_rules_class_validation( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$rules    = new \WP_Speculation_Rules();
		$slug     = self::slug( $ctx, 'rules-class' );
		$list_id  = 'list-' . str_replace( '-', '_', $slug );
		$doc_id   = 'document-' . str_replace( '-', '_', $slug );
		$list_url = '/valid-list-' . $slug . '?q=<tag>&close=</script>';
		$where    = array(
			'and' => array(
				array( 'href_matches' => '/docs/' . $slug . '/*' ),
				array( 'not' => array( 'selector_matches' => '.no-prerender, .no-prerender a' ) ),
			),
		);

		$diagnostics = array();
		$diag_action = static function ( string $function_name, string $message, string $version ) use ( &$diagnostics ): void {
			$diagnostics[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};
		$suppress_doing_it_wrong = static function (): bool {
			return false;
		};

		\add_action( 'doing_it_wrong_run', $diag_action, 10, 3 );
		try {
			$valid_list     = $rules->add_rule(
				'prefetch',
				$list_id,
				array(
					'source'    => 'list',
					'urls'      => array( $list_url ),
					'eagerness' => 'immediate',
				)
			);
			$valid_document = $rules->add_rule(
				'prerender',
				$doc_id,
				array(
					'source'    => 'document',
					'where'     => $where,
					'eagerness' => 'moderate',
				)
			);
			$valid_diagnostics = $diagnostics;
			$diagnostics       = array();

			$invalid_cases = array(
				'duplicate-list'      => static function () use ( $rules, $list_id, $slug ): bool {
					return $rules->add_rule(
						'prefetch',
						$list_id,
						array(
							'source' => 'list',
							'urls'   => array( '/duplicate-' . $slug ),
						)
					);
				},
				'bad-mode'            => static function () use ( $rules, $slug ): bool {
					return $rules->add_rule( 'fetch', 'bad-mode-' . $slug, array( 'urls' => array( '/bad' ) ) );
				},
				'bad-id'              => static function () use ( $rules, $slug ): bool {
					return $rules->add_rule( 'prefetch', '1bad-' . $slug, array( 'urls' => array( '/bad' ) ) );
				},
				'missing-where-urls'  => static function () use ( $rules, $slug ): bool {
					return $rules->add_rule( 'prefetch', 'missing-' . $slug, array( 'source' => 'list' ) );
				},
				'where-and-urls'      => static function () use ( $rules, $slug ): bool {
					return $rules->add_rule( 'prefetch', 'both-' . $slug, array( 'where' => array(), 'urls' => array( '/bad' ) ) );
				},
				'list-with-where'     => static function () use ( $rules, $slug ): bool {
					return $rules->add_rule( 'prefetch', 'list-where-' . $slug, array( 'source' => 'list', 'where' => array() ) );
				},
				'document-with-urls'  => static function () use ( $rules, $slug ): bool {
					return $rules->add_rule( 'prerender', 'doc-urls-' . $slug, array( 'source' => 'document', 'urls' => array( '/bad' ) ) );
				},
				'bad-eagerness'       => static function () use ( $rules, $slug ): bool {
					return $rules->add_rule( 'prefetch', 'bad-eager-' . $slug, array( 'urls' => array( '/bad' ), 'eagerness' => 'lazy' ) );
				},
				'immediate-document'  => static function () use ( $rules, $slug, $where ): bool {
					return $rules->add_rule( 'prerender', 'immediate-doc-' . $slug, array( 'where' => $where, 'eagerness' => 'immediate' ) );
				},
			);

			$invalid_results     = array();
			$invalid_diagnostics = array();

			\add_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10, 4 );
			try {
				foreach ( $invalid_cases as $label => $callback ) {
					$before                    = count( $diagnostics );
					$invalid_results[ $label ] = $callback();
					$invalid_diagnostics[ $label ] = array_slice( $diagnostics, $before );
				}
			} finally {
				\remove_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10 );
			}
		} finally {
			\remove_action( 'doing_it_wrong_run', $diag_action, 10 );
		}

		$serialized             = $rules->jsonSerialize();
		$json                   = \wp_json_encode( $rules );
		$serialized_json        = \wp_json_encode( $serialized );
		$serialized_keys        = array_keys( $serialized );
		$expected_keys          = array( 'prefetch', 'prerender' );
		$diagnostic_counts      = array();
		$valid_diagnostic_count = count( $valid_diagnostics );
		$invalid_payloads_clear = true;
		$diagnostics_exact      = true;

		sort( $serialized_keys );

		foreach ( $invalid_diagnostics as $label => $case_diagnostics ) {
			$diagnostic_counts[ $label ] = count( $case_diagnostics );

			if ( 1 !== $diagnostic_counts[ $label ] ) {
				$diagnostics_exact = false;
			}
		}

		foreach ( array( '/duplicate-' . $slug, '/bad' ) as $needle ) {
			if ( str_contains( (string) $serialized_json, $needle ) ) {
				$invalid_payloads_clear = false;
				break;
			}
		}

		self::collect_failure(
			$failures,
			true === $valid_list
				&& true === $valid_document
				&& array_fill_keys( array_keys( $invalid_results ), false ) === $invalid_results
				&& $rules->has_rule( 'prefetch', $list_id )
				&& $rules->has_rule( 'prerender', $doc_id )
				&& ! $rules->has_rule( 'prefetch', 'missing-' . $slug )
				&& isset( $serialized['prefetch'][0], $serialized['prerender'][0] )
				&& 1 === count( $serialized['prefetch'] )
				&& 1 === count( $serialized['prerender'] )
				&& $expected_keys === $serialized_keys
				&& $list_url === ( $serialized['prefetch'][0]['urls'][0] ?? null )
				&& $where === ( $serialized['prerender'][0]['where'] ?? null )
				&& $invalid_payloads_clear
				&& ! str_contains( (string) $json, $list_id )
				&& ! str_contains( (string) $json, $doc_id )
				&& 0 === $valid_diagnostic_count
				&& $diagnostics_exact
				&& false === \has_action( 'doing_it_wrong_run', $diag_action )
				&& false === \has_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong ),
			'WP_Speculation_Rules accepts valid list/document rules, rejects invalid and duplicate rules, and omits IDs from JSON',
			array(
				'validList'             => $valid_list,
				'validDocument'         => $valid_document,
				'invalidResults'        => $invalid_results,
				'serializedKeys'        => $serialized_keys,
				'serialized'            => $serialized,
				'serializedJson'        => $serialized_json,
				'json'                  => $json,
				'validDiagnosticCount'  => $valid_diagnostic_count,
				'validDiagnostics'      => array_slice( $valid_diagnostics, 0, 10 ),
				'invalidDiagCounts'     => $diagnostic_counts,
				'invalidDiagnostics'    => array_slice( $invalid_diagnostics, 0, 10 ),
				'invalidPayloadsClear'  => $invalid_payloads_clear,
				'hasAction'             => \has_action( 'doing_it_wrong_run', $diag_action ),
				'triggerHasFilter'      => \has_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong ),
			)
		);

		return $ctx->result(
			'frontend-features.speculation.rules-class-validation',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
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

	private static function check_speculation_disabled_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures  = array();
		$slug      = self::slug( $ctx, 'lifecycle' );
		$mode      = $ctx->choice( array( 'prefetch', 'prerender' ) );
		$eagerness = $ctx->choice( array( 'conservative', 'moderate', 'eager' ) );

		$disabled_filter = static function (): ?array {
			return null;
		};
		$disabled_permalink_filter = static function (): string {
			return '/%postname%/';
		};
		$disabled_loads            = array();
		$disabled_action           = static function ( \WP_Speculation_Rules $rules ) use ( &$disabled_loads ): void {
			$disabled_loads[] = spl_object_id( $rules );
		};

		$disabled_rules          = null;
		$disabled_output         = null;
		$disabled_action_before  = \did_action( 'wp_load_speculation_rules' );
		$disabled_action_after   = null;
		$disabled_hooks_removed  = false;

		\add_filter( 'wp_speculation_rules_configuration', $disabled_filter );
		\add_filter( 'pre_option_permalink_structure', $disabled_permalink_filter );
		\add_action( 'wp_load_speculation_rules', $disabled_action );
		try {
			self::set_logged_in( false, $ctx->seed() );

			$disabled_rules        = \wp_get_speculation_rules();
			$disabled_output       = self::capture_output( static fn() => \wp_print_speculation_rules() );
			$disabled_action_after = \did_action( 'wp_load_speculation_rules' );
		} finally {
			\remove_action( 'wp_load_speculation_rules', $disabled_action );
			\remove_filter( 'pre_option_permalink_structure', $disabled_permalink_filter );
			\remove_filter( 'wp_speculation_rules_configuration', $disabled_filter );
			\wp_set_current_user( 0 );

			$disabled_hooks_removed = false === \has_action( 'wp_load_speculation_rules', $disabled_action )
				&& false === \has_filter( 'pre_option_permalink_structure', $disabled_permalink_filter )
				&& false === \has_filter( 'wp_speculation_rules_configuration', $disabled_filter );
		}

		self::collect_failure(
			$failures,
			null === $disabled_rules
				&& '' === $disabled_output
				&& array() === $disabled_loads
				&& $disabled_action_before === $disabled_action_after
				&& $disabled_hooks_removed,
			'disabled speculation configuration returns null, prints nothing, skips load action, and removes lifecycle hooks',
			array(
				'disabledRules'        => $disabled_rules,
				'disabledOutput'       => $disabled_output,
				'disabledLoads'        => $disabled_loads,
				'actionCountBefore'    => $disabled_action_before,
				'actionCountAfter'     => $disabled_action_after,
				'disabledHooksRemoved' => $disabled_hooks_removed,
			)
		);

		$per_call_urls = array(
			'/lifecycle-' . $slug . '-get-1?close=</script>&tag=<tag>',
			'/lifecycle-' . $slug . '-get-2?close=</script>&tag=<tag>',
			'/lifecycle-' . $slug . '-print-1?close=</script>&tag=<tag>',
			'/lifecycle-' . $slug . '-print-2?close=</script>&tag=<tag>',
		);
		$config_filter = static function () use ( $mode, $eagerness ): array {
			return array(
				'mode'      => $mode,
				'eagerness' => $eagerness,
			);
		};
		$permalink_filter = static function (): string {
			return '/%postname%/';
		};
		$home_filter      = static function () use ( $slug ): string {
			return 'https://example.test/front-' . $slug;
		};
		$siteurl_filter   = static function () use ( $slug ): string {
			return 'https://example.test/core-' . $slug;
		};
		$template_filter  = static function () use ( $slug ): string {
			return 'https://example.test/wp-content/themes/template-' . $slug;
		};
		$stylesheet_filter = static function () use ( $slug ): string {
			return 'https://example.test/wp-content/themes/stylesheet-' . $slug;
		};

		$enabled_loads  = array();
		$retained_rules = array();
		$load_action    = static function ( \WP_Speculation_Rules $rules ) use ( &$enabled_loads, &$retained_rules, $per_call_urls, $slug ): void {
			$index                = count( $enabled_loads );
			$url                  = $per_call_urls[ $index ] ?? '/lifecycle-' . $slug . '-extra-' . $index . '?close=</script>&tag=<tag>';
			$rule_id              = 'lifecycle-' . $slug . '-' . $index;
			$retained_rules[]     = $rules;
			$enabled_loads[]      = array(
				'objectId' => spl_object_id( $rules ),
				'class'    => get_class( $rules ),
				'url'      => $url,
				'added'    => $rules->add_rule(
					'prefetch',
					$rule_id,
					array(
						'source'    => 'list',
						'urls'      => array( $url ),
						'eagerness' => 'immediate',
					)
				),
			);
		};

		$first_get_obj         = null;
		$second_get_obj        = null;
		$first_get_serialized  = null;
		$second_get_serialized = null;
		$first_print           = '';
		$second_print          = '';
		$first_print_decoded   = array( 'ok' => false );
		$second_print_decoded  = array( 'ok' => false );
		$enabled_before        = \did_action( 'wp_load_speculation_rules' );
		$enabled_after         = null;
		$enabled_hooks_removed = false;

		\add_filter( 'wp_speculation_rules_configuration', $config_filter );
		\add_filter( 'pre_option_permalink_structure', $permalink_filter );
		\add_filter( 'pre_option_home', $home_filter );
		\add_filter( 'pre_option_siteurl', $siteurl_filter );
		\add_filter( 'template_directory_uri', $template_filter );
		\add_filter( 'stylesheet_directory_uri', $stylesheet_filter );
		\add_action( 'wp_load_speculation_rules', $load_action );

		try {
			self::set_logged_in( false, $ctx->seed() + 1 );

			$first_get_obj         = \wp_get_speculation_rules();
			$first_get_serialized  = $first_get_obj instanceof \WP_Speculation_Rules ? $first_get_obj->jsonSerialize() : null;
			$second_get_obj        = \wp_get_speculation_rules();
			$second_get_serialized = $second_get_obj instanceof \WP_Speculation_Rules ? $second_get_obj->jsonSerialize() : null;
			$first_print           = self::capture_output( static fn() => \wp_print_speculation_rules() );
			$first_print_decoded   = self::decode_speculation_output( $first_print );
			$second_print          = self::capture_output( static fn() => \wp_print_speculation_rules() );
			$second_print_decoded  = self::decode_speculation_output( $second_print );
			$enabled_after         = \did_action( 'wp_load_speculation_rules' );
		} finally {
			\remove_action( 'wp_load_speculation_rules', $load_action );
			\remove_filter( 'stylesheet_directory_uri', $stylesheet_filter );
			\remove_filter( 'template_directory_uri', $template_filter );
			\remove_filter( 'pre_option_siteurl', $siteurl_filter );
			\remove_filter( 'pre_option_home', $home_filter );
			\remove_filter( 'pre_option_permalink_structure', $permalink_filter );
			\remove_filter( 'wp_speculation_rules_configuration', $config_filter );
			\wp_set_current_user( 0 );

			$enabled_hooks_removed = false === \has_action( 'wp_load_speculation_rules', $load_action )
				&& false === \has_filter( 'stylesheet_directory_uri', $stylesheet_filter )
				&& false === \has_filter( 'template_directory_uri', $template_filter )
				&& false === \has_filter( 'pre_option_siteurl', $siteurl_filter )
				&& false === \has_filter( 'pre_option_home', $home_filter )
				&& false === \has_filter( 'pre_option_permalink_structure', $permalink_filter )
				&& false === \has_filter( 'wp_speculation_rules_configuration', $config_filter );
		}

		$object_ids     = array_column( $enabled_loads, 'objectId' );
		$load_urls      = array_column( $enabled_loads, 'url' );
		$list_urls      = array(
			'firstGet'    => self::serialized_list_rule_urls( $first_get_serialized ),
			'secondGet'   => self::serialized_list_rule_urls( $second_get_serialized ),
			'firstPrint'  => self::serialized_list_rule_urls( $first_print_decoded['rules'] ?? null ),
			'secondPrint' => self::serialized_list_rule_urls( $second_print_decoded['rules'] ?? null ),
		);
		$expected_list_urls = array(
			'firstGet'    => array( $per_call_urls[0] ),
			'secondGet'   => array( $per_call_urls[1] ),
			'firstPrint'  => array( $per_call_urls[2] ),
			'secondPrint' => array( $per_call_urls[3] ),
		);
		$expected_order = array(
			$first_get_obj instanceof \WP_Speculation_Rules ? spl_object_id( $first_get_obj ) : null,
			$second_get_obj instanceof \WP_Speculation_Rules ? spl_object_id( $second_get_obj ) : null,
			$object_ids[2] ?? null,
			$object_ids[3] ?? null,
		);
		$all_added      = array_column( $enabled_loads, 'added' );
		$printed_escape = str_contains( $first_print, '\u003C/script\u003E' )
			&& str_contains( $first_print, '\u003Ctag\u003E' )
			&& str_contains( $second_print, '\u003C/script\u003E' )
			&& str_contains( $second_print, '\u003Ctag\u003E' )
			&& ! str_contains( $first_print, '</script><tag>' )
			&& ! str_contains( $second_print, '</script><tag>' );

		self::collect_failure(
			$failures,
			$first_get_obj instanceof \WP_Speculation_Rules
				&& $second_get_obj instanceof \WP_Speculation_Rules
				&& 4 === count( $enabled_loads )
				&& $enabled_after === $enabled_before + 4
				&& array_fill( 0, 4, true ) === $all_added
				&& $expected_order === $object_ids
				&& $per_call_urls === $load_urls
				&& 4 === count( array_unique( $object_ids ) )
				&& $expected_list_urls['firstGet'] === $list_urls['firstGet']
				&& $expected_list_urls['secondGet'] === $list_urls['secondGet']
				&& $first_print_decoded['ok']
				&& $expected_list_urls['firstPrint'] === $list_urls['firstPrint']
				&& $second_print_decoded['ok']
				&& $expected_list_urls['secondPrint'] === $list_urls['secondPrint']
				&& $printed_escape
				&& $enabled_hooks_removed,
			'enabled speculation lifecycle fires one load action per get/print call with fresh objects, isolated generated rules, escaped print output, and removed hooks',
			array(
				'mode'                  => $mode,
				'eagerness'             => $eagerness,
				'actionCountBefore'     => $enabled_before,
				'actionCountAfter'      => $enabled_after,
				'loads'                 => $enabled_loads,
				'expectedObjectOrder'   => $expected_order,
				'objectIds'             => $object_ids,
				'perCallUrls'           => $per_call_urls,
				'loadUrls'              => $load_urls,
				'listUrls'              => $list_urls,
				'expectedListUrls'      => $expected_list_urls,
				'firstGet'              => $first_get_serialized,
				'secondGet'             => $second_get_serialized,
				'firstPrint'            => self::preview( $first_print ),
				'secondPrint'           => self::preview( $second_print ),
				'firstPrintDecoded'     => $first_print_decoded['ok'] ? array_keys( $first_print_decoded['rules'] ) : $first_print_decoded,
				'secondPrintDecoded'    => $second_print_decoded['ok'] ? array_keys( $second_print_decoded['rules'] ) : $second_print_decoded,
				'printedEscape'         => $printed_escape,
				'enabledHooksRemoved'   => $enabled_hooks_removed,
				'retainedRulesForIds'   => count( $retained_rules ),
			)
		);

		return $ctx->result(
			'frontend-features.speculation.disabled-lifecycle-and-load-action-isolation',
			array() === $failures,
			array(
				'mode'             => $mode,
				'eagerness'        => $eagerness,
				'disabledLoads'    => count( $disabled_loads ),
				'enabledLoads'     => count( $enabled_loads ),
				'enabledObjectIds' => $object_ids,
				'failures'         => array_slice( $failures, 0, 4 ),
			)
		);
	}

	private static function check_url_pattern_prefixer( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$slug     = self::slug( $ctx, 'prefix' );

		$contexts = array(
			'home'    => '/front-' . $slug,
			'site'    => '/core-' . $slug,
			'uploads' => '/files+' . $slug . '*drafts',
		);
		$prefixer = new \WP_URL_Pattern_Prefixer( $contexts );

		$matrix = array(
			'home-relative'           => array( 'context' => 'home', 'path' => 'products/' . $slug . '/*' ),
			'home-leading-slash'      => array( 'context' => 'home', 'path' => '/products/' . $slug . '/*' ),
			'home-double-slash'       => array( 'context' => 'home', 'path' => '//deals/' . $slug . '/*' ),
			'home-already-prefixed'   => array(
				'context' => 'home',
				'path'    => self::escaped_context_path( $contexts['home'] ) . 'products/' . $slug . '/*',
			),
			'site-admin'              => array( 'context' => 'site', 'path' => '/wp-admin/*' ),
			'site-php-pattern'        => array( 'context' => 'site', 'path' => '/wp-*.php' ),
			'uploads-pattern-chars'   => array( 'context' => 'uploads', 'path' => '/cache/{draft}/asset?.css' ),
			'uploads-already-escaped' => array(
				'context' => 'uploads',
				'path'    => self::escaped_context_path( $contexts['uploads'] ) . 'cache/{draft}/asset?.css',
			),
		);

		$matrix_results = array();
		foreach ( $matrix as $label => $case ) {
			$first  = $prefixer->prefix_path_pattern( $case['path'], $case['context'] );
			$second = $prefixer->prefix_path_pattern( $first, $case['context'] );
			$expect = self::expected_prefixed_path_pattern( $contexts[ $case['context'] ], $case['path'] );

			$matrix_results[ $label ] = array(
				'first'      => $first,
				'second'     => $second,
				'expected'   => $expect,
				'idempotent' => $first === $second,
			);
		}

		$special_context  = '/front:' . $slug . '?draft#hash';
		$special_prefixer = new \WP_URL_Pattern_Prefixer( array( 'home' => $special_context ) );
		$special          = $special_prefixer->prefix_path_pattern( '/next/*' );
		$special_expected = self::expected_prefixed_path_pattern( $special_context, '/next/*' );

		$diagnostics               = array();
		$invalid_context           = 'bad:' . $slug;
		$invalid_pattern           = '/kept/' . $slug . '/*';
		$diag_action               = static function ( string $function_name, string $message, string $version ) use ( &$diagnostics ): void {
			$diagnostics[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};
		$suppress_doing_it_wrong   = static function (): bool {
			return false;
		};
		$invalid_context_result    = null;

		\add_action( 'doing_it_wrong_run', $diag_action, 10, 3 );
		\add_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10, 4 );
		try {
			$invalid_context_result = $prefixer->prefix_path_pattern( $invalid_pattern, $invalid_context );
		} finally {
			\remove_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong, 10 );
			\remove_action( 'doing_it_wrong_run', $diag_action, 10 );
		}

		$matrix_ok = true;
		foreach ( $matrix_results as $result ) {
			if ( $result['first'] !== $result['expected'] || ! $result['idempotent'] || str_contains( $result['first'], '//' ) ) {
				$matrix_ok = false;
				break;
			}
		}

		$diagnostic = $diagnostics[0] ?? array();

		self::collect_failure(
			$failures,
			$matrix_ok
				&& $special_expected === $special
				&& str_starts_with( $special, '{/front\\:' )
				&& str_contains( $special, '\\?draft#hash}/next/*' )
				&& $invalid_pattern === $invalid_context_result
				&& 1 === count( $diagnostics )
				&& 'prefix_path_pattern' === ( $diagnostic['function'] ?? null )
				&& str_contains( (string) ( $diagnostic['message'] ?? '' ), $invalid_context )
				&& '6.8.0' === ( $diagnostic['version'] ?? null )
				&& false === \has_action( 'doing_it_wrong_run', $diag_action )
				&& false === \has_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong ),
			'URL pattern prefixing covers generated contexts, idempotence, escaping, grouping-sensitive paths, and invalid context diagnostics',
			array(
				'contexts'             => $contexts,
				'matrix'               => $matrix_results,
				'specialContext'       => $special_context,
				'special'              => $special,
				'specialExpected'      => $special_expected,
				'invalidContext'       => $invalid_context,
				'invalidContextResult' => $invalid_context_result,
				'diagnostics'          => $diagnostics,
				'hasAction'            => \has_action( 'doing_it_wrong_run', $diag_action ),
				'hasFilter'            => \has_filter( 'doing_it_wrong_trigger_error', $suppress_doing_it_wrong ),
			)
		);

		return $ctx->result(
			'frontend-features.url-pattern-prefixer.generated-context-matrix',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_view_transition_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$previous_init_count = $GLOBALS['wp_actions']['init'] ?? null;
		unset( $GLOBALS['wp_actions']['init'] );
		$GLOBALS['wp_styles'] = new \WP_Styles();
		\wp_default_styles( \wp_styles() );

		$pre_init_after_data = \wp_styles()->get_data( 'wp-view-transitions-admin', 'after' );
		$pre_init_registered = \wp_styles()->registered['wp-view-transitions-admin'] ?? null;
		$pre_init_before     = \wp_style_is( 'wp-view-transitions-admin' );
		\wp_enqueue_view_transitions_admin_css();
		$pre_init_enqueued   = \wp_style_is( 'wp-view-transitions-admin' );
		$pre_init_queue      = \wp_styles()->queue;
		$pre_init_queue_count = count( array_keys( $pre_init_queue, 'wp-view-transitions-admin', true ) );

		$GLOBALS['wp_actions']['init'] = max( 1, (int) ( $previous_init_count ?? 0 ) );
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
			$pre_init_registered instanceof \_WP_Dependency
				&& false === $pre_init_registered->src
				&& false === $pre_init_after_data
				&& false === $pre_init_before
				&& true === $pre_init_enqueued
				&& 1 === $pre_init_queue_count,
			'view transition admin style registers and enqueues before init without attaching inline CSS',
			array(
				'afterData'          => $pre_init_after_data,
				'registered'         => $pre_init_registered instanceof \_WP_Dependency ? array(
					'handle' => $pre_init_registered->handle,
					'src'    => $pre_init_registered->src,
				) : self::preview( $pre_init_registered ),
				'before'             => $pre_init_before,
				'enqueued'           => $pre_init_enqueued,
				'queue'              => $pre_init_queue,
				'queueCountForStyle' => $pre_init_queue_count,
			)
		);
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
			'frontend-features.view-transitions.css-registration-timing-and-enqueue-idempotence',
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

	private static function serialized_list_rule_urls( $serialized ): array {
		if ( ! is_array( $serialized ) ) {
			return array();
		}

		$urls = array();
		foreach ( $serialized as $rules ) {
			if ( ! is_array( $rules ) ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['urls'] ) || ! is_array( $rule['urls'] ) ) {
					continue;
				}

				foreach ( $rule['urls'] as $url ) {
					$urls[] = $url;
				}
			}
		}

		return $urls;
	}

	private static function prefix_path( string $base_path, string $path_pattern ): string {
		return rtrim( $base_path, '/' ) . '/' . ltrim( $path_pattern, '/' );
	}

	private static function escaped_context_path( string $base_path ): string {
		return addcslashes( \trailingslashit( $base_path ), '+*?:{}()\\' );
	}

	private static function expected_prefixed_path_pattern( string $base_path, string $path_pattern ): string {
		$context_path = self::escaped_context_path( $base_path );
		$prefix       = $context_path;

		if ( strcspn( $context_path, ':?#' ) !== strlen( $context_path ) ) {
			$prefix = '{' . substr( $context_path, 0, -1 ) . '}/';
		}

		if ( str_starts_with( $path_pattern, $context_path ) ) {
			$path_pattern = substr( $path_pattern, strlen( $context_path ) );
		}

		return $prefix . ltrim( $path_pattern, '/' );
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
