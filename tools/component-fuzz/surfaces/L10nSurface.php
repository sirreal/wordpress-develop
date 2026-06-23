<?php
namespace ComponentFuzz\Surfaces;

final class L10nSurface {
	public const NAME = 'l10n';

	private const GENERATED_STRING_CASES = 12;
	private const GENERATED_PLURAL_CASES = 10;
	private const GENERATED_MALFORMED_CASES = 8;
	private const GENERATED_NUMBER_CASES = 8;
	private const GENERATED_DATE_CASES   = 8;
	private const MAX_TEXT_BYTES         = 160;
	private const MAX_DOMAIN_BYTES       = 64;
	private const MAX_CONTEXT_BYTES      = 80;
	private const FAILURE_LIMIT          = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing_functions = self::missing_functions(
			array(
				'translate',
				'__',
				'_e',
				'_x',
				'_ex',
				'_n',
				'_nx',
				'_n_noop',
				'_nx_noop',
				'translate_nooped_plural',
				'esc_html__',
				'esc_attr__',
				'esc_html_e',
				'esc_attr_e',
				'esc_html_x',
				'esc_attr_x',
				'esc_html',
				'esc_attr',
				'add_filter',
				'remove_filter',
				'has_filter',
				'load_textdomain',
				'unload_textdomain',
				'is_textdomain_loaded',
				'get_locale',
				'determine_locale',
			)
		);
		$missing_classes   = self::missing_classes(
			array(
				'NOOP_Translations',
				'MO',
				'Translation_Entry',
				'WP_Textdomain_Registry',
				'WP_Translation_Controller',
				'WP_Translations',
			)
		);

		if ( $missing_functions || $missing_classes ) {
			return array(
				$ctx->skip(
					'l10n.required-apis-available',
					'Required WordPress localization APIs are unavailable.',
					array(
						'missingFunctions' => $missing_functions,
						'missingClasses'   => $missing_classes,
					)
				),
			);
		}

		$snapshot = self::snapshot_global_state();

		try {
			self::ensure_runtime_globals();

			$string_cases = self::string_cases( $ctx->fork( 'string-cases' ) );
			$plural_cases = self::plural_cases( $ctx->fork( 'plural-cases' ) );

			return array(
				self::check_singular_fallbacks( $ctx->fork( 'singular' ), $string_cases ),
				self::check_escaping_helpers( $ctx->fork( 'escaping' ), $string_cases ),
				self::check_plural_fallbacks( $ctx->fork( 'plural' ), $plural_cases ),
				self::check_nooped_plural_structures( $ctx->fork( 'nooped-plurals' ), $plural_cases ),
				self::check_malformed_string_boundaries( $ctx->fork( 'malformed-strings' ) ),
				self::check_number_format_i18n( $ctx->fork( 'number-format' ) ),
				self::check_wp_date( $ctx->fork( 'dates' ) ),
				self::check_locale_behaviour( $ctx->fork( 'locale' ) ),
				self::check_locale_switching( $ctx->fork( 'locale-switching' ) ),
				self::check_textdomain_loading( $ctx->fork( 'textdomain-loading' ) ),
				self::check_translation_path_guards( $ctx->fork( 'path-guards' ) ),
				self::check_script_translation_helpers( $ctx->fork( 'script-translations' ) ),
				self::check_filter_action_cleanup( $ctx->fork( 'filter-action-cleanup' ) ),
			);
		} finally {
			self::restore_global_state( $snapshot );
		}
	}

	private static function check_singular_fallbacks( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		self::clear_filters(
			array(
				'gettext',
				'gettext_with_context',
			)
		);

		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $case ) {
				self::install_noop_domain( $case['domain'] );

				$translated = \translate( $case['text'], $case['domain'] );
				$alias      = \__( $case['text'], $case['domain'] );
				$contextual = \_x( $case['text'], $case['context'], $case['domain'] );

				self::sample_case( $samples, $case );

				if ( $translated !== $case['text'] ) {
					self::add_failure(
						$failures,
						'translate',
						$case,
						$case['text'],
						$translated
					);
				}

				if ( $alias !== $case['text'] ) {
					self::add_failure(
						$failures,
						'__',
						$case,
						$case['text'],
						$alias
					);
				}

				if ( $contextual !== $case['text'] ) {
					self::add_failure(
						$failures,
						'_x',
						$case,
						$case['text'],
						$contextual
					);
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.singular-fallback-no-loaded-domain', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'l10n.singular-fallback-no-loaded-domain',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'samples'  => $samples,
				'failures' => $failures,
			)
		);
	}

	private static function check_escaping_helpers( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		self::clear_filters(
			array(
				'gettext',
				'esc_html',
				'attribute_escape',
				'gettext_with_context',
			)
		);

		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $case ) {
				self::install_noop_domain( $case['domain'] );

				$translated = \translate( $case['text'], $case['domain'] );
				$contextual = \_x( $case['text'], $case['context'], $case['domain'] );
				$html       = \esc_html__( $case['text'], $case['domain'] );
				$attr       = \esc_attr__( $case['text'], $case['domain'] );
				$html_x     = \esc_html_x( $case['text'], $case['context'], $case['domain'] );
				$attr_x     = \esc_attr_x( $case['text'], $case['context'], $case['domain'] );
				$echo       = self::capture_echo(
					static function () use ( $case ): void {
						\_e( $case['text'], $case['domain'] );
					}
				);
				$echo_x     = self::capture_echo(
					static function () use ( $case ): void {
						\_ex( $case['text'], $case['context'], $case['domain'] );
					}
				);
				$echo_html  = self::capture_echo(
					static function () use ( $case ): void {
						\esc_html_e( $case['text'], $case['domain'] );
					}
				);
				$echo_attr  = self::capture_echo(
					static function () use ( $case ): void {
						\esc_attr_e( $case['text'], $case['domain'] );
					}
				);

				self::sample_case( $samples, $case );

				$html_expected = \esc_html( $translated );
				if ( $html !== $html_expected ) {
					self::add_failure(
						$failures,
						'esc_html__',
						$case,
						$html_expected,
						$html
					);
				}

				$attr_expected = \esc_attr( $translated );
				if ( $attr !== $attr_expected ) {
					self::add_failure(
						$failures,
						'esc_attr__',
						$case,
						$attr_expected,
						$attr
					);
				}

				$html_x_expected = \esc_html( $contextual );
				if ( $html_x !== $html_x_expected ) {
					self::add_failure(
						$failures,
						'esc_html_x',
						$case,
						$html_x_expected,
						$html_x
					);
				}

				$attr_x_expected = \esc_attr( $contextual );
				if ( $attr_x !== $attr_x_expected ) {
					self::add_failure(
						$failures,
						'esc_attr_x',
						$case,
						$attr_x_expected,
						$attr_x
					);
				}

				$output_expectations = array(
					'_e'         => array( $translated, $echo ),
					'_ex'        => array( $contextual, $echo_x ),
					'esc_html_e' => array( $html_expected, $echo_html ),
					'esc_attr_e' => array( $attr_expected, $echo_attr ),
				);

				foreach ( $output_expectations as $api => $pair ) {
					if ( $pair[0] !== $pair[1] ) {
						self::add_failure(
							$failures,
							$api,
							$case,
							$pair[0],
							$pair[1]
						);
					}
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.escaping-helpers-match-escaped-translation', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'l10n.escaping-helpers-match-escaped-translation',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'samples'  => $samples,
				'failures' => $failures,
			)
		);
	}

	private static function check_plural_fallbacks( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		self::clear_filters(
			array(
				'ngettext',
				'ngettext_with_context',
			)
		);

		$failures       = array();
		$samples        = array();
		$singular_count = 0;
		$plural_count   = 0;

		try {
			foreach ( $cases as $case ) {
				self::install_noop_domain( $case['domain'] );

				$expected = 1 === (int) $case['count'] ? $case['singular'] : $case['plural'];
				if ( $expected === $case['singular'] ) {
					++$singular_count;
				} else {
					++$plural_count;
				}

				$actuals = array(
					'_n'                         => \_n( $case['singular'], $case['plural'], $case['count'], $case['domain'] ),
					'_nx'                        => \_nx( $case['singular'], $case['plural'], $case['count'], $case['context'], $case['domain'] ),
					'translate_nooped_plural'    => \translate_nooped_plural( \_n_noop( $case['singular'], $case['plural'], $case['domain'] ), $case['count'], 'fallback-domain' ),
					'translate_nooped_plural_x'  => \translate_nooped_plural( \_nx_noop( $case['singular'], $case['plural'], $case['context'], $case['domain'] ), $case['count'], 'fallback-domain' ),
					'translate_nooped_fallback'  => \translate_nooped_plural( \_n_noop( $case['singular'], $case['plural'] ), $case['count'], $case['domain'] ),
					'translate_nooped_x_fallback' => \translate_nooped_plural( \_nx_noop( $case['singular'], $case['plural'], $case['context'] ), $case['count'], $case['domain'] ),
				);

				self::sample_plural_case( $samples, $case, $expected );

				foreach ( $actuals as $api => $actual ) {
					if ( $actual !== $expected ) {
						self::add_failure(
							$failures,
							$api,
							$case,
							$expected,
							$actual
						);
					}
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.plural-fallback-english-selection', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'l10n.plural-fallback-english-selection',
			array() === $failures,
			array(
				'cases'         => count( $cases ),
				'singularCases' => $singular_count,
				'pluralCases'   => $plural_count,
				'samples'       => $samples,
				'failures'      => $failures,
			)
		);
	}

	private static function check_nooped_plural_structures( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		self::clear_filters(
			array(
				'ngettext',
				'ngettext_with_context',
			)
		);

		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $index => $case ) {
				$domain          = self::unique_domain( $ctx, 'nooped-' . $index );
				$fallback_domain = self::unique_domain( $ctx, 'nooped-fallback-' . $index );
				$context         = empty( $case['context'] ) ? 'component-fuzz-context-' . $index : $case['context'];
				$selection_index = 1 === (int) $case['count'] ? 0 : 1;

				$domain_translations = array(
					$case['singular'] . ' translated in nooped domain ' . $index,
					$case['plural'] . ' translated in nooped domain ' . $index,
				);
				$fallback_translations = array(
					$case['singular'] . ' translated in fallback domain ' . $index,
					$case['plural'] . ' translated in fallback domain ' . $index,
				);
				$domain_context_translations = array(
					$case['singular'] . ' contextual nooped domain ' . $index,
					$case['plural'] . ' contextual nooped domain ' . $index,
				);
				$fallback_context_translations = array(
					$case['singular'] . ' contextual fallback domain ' . $index,
					$case['plural'] . ' contextual fallback domain ' . $index,
				);

				self::install_mo_domain(
					$domain,
					array(
						array(
							'singular'     => $case['singular'],
							'plural'       => $case['plural'],
							'translations' => $domain_translations,
						),
						array(
							'singular'     => $case['singular'],
							'plural'       => $case['plural'],
							'context'      => $context,
							'translations' => $domain_context_translations,
						),
					)
				);
				self::install_mo_domain(
					$fallback_domain,
					array(
						array(
							'singular'     => $case['singular'],
							'plural'       => $case['plural'],
							'translations' => $fallback_translations,
						),
						array(
							'singular'     => $case['singular'],
							'plural'       => $case['plural'],
							'context'      => $context,
							'translations' => $fallback_context_translations,
						),
					)
				);

				$noop    = \_n_noop( $case['singular'], $case['plural'], $domain );
				$noop_x  = \_nx_noop( $case['singular'], $case['plural'], $context, $domain );
				$bare    = \_n_noop( $case['singular'], $case['plural'] );
				$bare_x  = \_nx_noop( $case['singular'], $case['plural'], $context );
				$actuals = array(
					'domain-wins'          => \translate_nooped_plural( $noop, $case['count'], $fallback_domain ),
					'fallback-domain-used' => \translate_nooped_plural( $bare, $case['count'], $fallback_domain ),
					'domain-wins-context'  => \translate_nooped_plural( $noop_x, $case['count'], $fallback_domain ),
					'fallback-context'     => \translate_nooped_plural( $bare_x, $case['count'], $fallback_domain ),
				);
				$expected = array(
					'domain-wins'          => $domain_translations[ $selection_index ],
					'fallback-domain-used' => $fallback_translations[ $selection_index ],
					'domain-wins-context'  => $domain_context_translations[ $selection_index ],
					'fallback-context'     => $fallback_context_translations[ $selection_index ],
				);

				if ( count( $samples ) < 5 ) {
					$summary                    = self::case_summary( $case );
					$summary['count']           = $case['count'];
					$summary['selectionIndex']  = $selection_index;
					$summary['domain']          = $domain;
					$summary['fallbackDomain']  = $fallback_domain;
					$summary['effectiveContext'] = self::preview_string( $context );
					$samples[]                  = $summary;
				}

				$noop_expected = array(
					0          => $case['singular'],
					1          => $case['plural'],
					'singular' => $case['singular'],
					'plural'   => $case['plural'],
					'context'  => null,
					'domain'   => $domain,
				);
				$bare_expected = $noop_expected;
				$bare_expected['domain'] = null;
				$noop_x_expected         = array(
					0          => $case['singular'],
					1          => $case['plural'],
					2          => $context,
					'singular' => $case['singular'],
					'plural'   => $case['plural'],
					'context'  => $context,
					'domain'   => $domain,
				);
				$bare_x_expected = $noop_x_expected;
				$bare_x_expected['domain'] = null;

				foreach (
					array(
						'_n_noop'    => array( $noop_expected, $noop ),
						'_n_noop:null-domain' => array( $bare_expected, $bare ),
						'_nx_noop'   => array( $noop_x_expected, $noop_x ),
						'_nx_noop:null-domain' => array( $bare_x_expected, $bare_x ),
					) as $api => $pair
				) {
					if ( $pair[0] !== $pair[1] ) {
						self::add_failure( $failures, $api, $case, $pair[0], $pair[1] );
					}
				}

				foreach ( $expected as $api => $expected_value ) {
					if ( $actuals[ $api ] !== $expected_value ) {
						self::add_failure( $failures, 'translate_nooped_plural:' . $api, $case, $expected_value, $actuals[ $api ] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.nooped-plurals-structure-domain-precedence', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'l10n.nooped-plurals-structure-domain-precedence',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_malformed_string_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		self::clear_filters(
			array(
				'gettext',
				'gettext_with_context',
				'ngettext',
				'ngettext_with_context',
				'esc_html',
				'attribute_escape',
			)
		);

		$cases    = self::malformed_string_cases( $ctx );
		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $case ) {
				self::install_noop_domain( $case['domain'] );

				$plural_expected = 1 === (int) $case['count'] ? $case['singular'] : $case['plural'];
				$actuals         = array(
					'translate'                     => \translate( $case['text'], $case['domain'] ),
					'__'                            => \__( $case['text'], $case['domain'] ),
					'_x'                            => \_x( $case['text'], $case['context'], $case['domain'] ),
					'_n'                            => \_n( $case['singular'], $case['plural'], $case['count'], $case['domain'] ),
					'_nx'                           => \_nx( $case['singular'], $case['plural'], $case['count'], $case['context'], $case['domain'] ),
					'translate_nooped_plural'       => \translate_nooped_plural( \_n_noop( $case['singular'], $case['plural'], $case['domain'] ), $case['count'] ),
					'esc_html__'                    => \esc_html__( $case['text'], $case['domain'] ),
					'esc_attr__'                    => \esc_attr__( $case['text'], $case['domain'] ),
					'esc_html_x'                    => \esc_html_x( $case['text'], $case['context'], $case['domain'] ),
					'esc_attr_x'                    => \esc_attr_x( $case['text'], $case['context'], $case['domain'] ),
				);
				$expected        = array(
					'translate'                     => $case['text'],
					'__'                            => $case['text'],
					'_x'                            => $case['text'],
					'_n'                            => $plural_expected,
					'_nx'                           => $plural_expected,
					'translate_nooped_plural'       => $plural_expected,
					'esc_html__'                    => \esc_html( $case['text'] ),
					'esc_attr__'                    => \esc_attr( $case['text'] ),
					'esc_html_x'                    => \esc_html( $case['text'] ),
					'esc_attr_x'                    => \esc_attr( $case['text'] ),
				);

				self::sample_plural_case( $samples, $case, $plural_expected );

				foreach ( $expected as $api => $expected_value ) {
					if ( $actuals[ $api ] !== $expected_value ) {
						self::add_failure( $failures, $api, $case, $expected_value, $actuals[ $api ] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.malformed-utf8-boundary-helpers-no-throw', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'l10n.malformed-utf8-boundary-helpers-no-throw',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_number_format_i18n( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'number_format_i18n' ) ) {
			return $ctx->skip( 'l10n.number-format-i18n-locale-oracle', 'number_format_i18n() is unavailable.' );
		}

		$cases    = self::number_cases( $ctx->fork( 'number-format' ) );
		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $case ) {
				$expected = self::expected_number_format( $case['number'], $case['decimals'] );
				$actual   = \number_format_i18n( $case['number'], $case['decimals'] );

				if ( count( $samples ) < 5 ) {
					$samples[] = array(
						'number'   => $case['number'],
						'decimals' => $case['decimals'],
						'expected' => $expected,
						'actual'   => $actual,
					);
				}

				if ( $actual !== $expected ) {
					$failures[] = array(
						'case'     => $case,
						'expected' => $expected,
						'actual'   => $actual,
					);
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.number-format-i18n-locale-oracle', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'l10n.number-format-i18n-locale-oracle',
			array() === $failures,
			array(
				'cases'        => count( $cases ),
				'localeFormat' => self::number_format_settings(),
				'samples'      => $samples,
				'failures'     => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_wp_date( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'wp_date' ) ) {
			return $ctx->skip( 'l10n.wp-date-explicit-utc-oracle', 'wp_date() is unavailable.' );
		}

		$cases             = self::date_cases( $ctx->fork( 'dates' ) );
		$timezone          = new \DateTimeZone( 'UTC' );
		$failures          = array();
		$samples           = array();
		$date_i18n_checked = function_exists( 'date_i18n' );

		try {
			foreach ( $cases as $case ) {
				$datetime = new \DateTimeImmutable( '@' . $case['timestamp'] );
				$expected = $datetime->setTimezone( $timezone )->format( $case['format'] );
				$actual   = \wp_date( $case['format'], $case['timestamp'], $timezone );

				if ( count( $samples ) < 5 ) {
					$samples[] = array(
						'timestamp' => $case['timestamp'],
						'format'    => $case['format'],
						'expected'  => $expected,
						'actual'    => $actual,
					);
				}

				if ( $actual !== $expected ) {
					$failures[] = array(
						'api'      => 'wp_date',
						'case'     => $case,
						'expected' => $expected,
						'actual'   => self::describe_value( $actual ),
					);
				}

				if ( $date_i18n_checked ) {
					$date_i18n = \date_i18n( 'U', $case['timestamp'], false );
					if ( (string) $case['timestamp'] !== (string) $date_i18n ) {
						$failures[] = array(
							'api'      => 'date_i18n',
							'case'     => array(
								'timestamp' => $case['timestamp'],
								'format'    => 'U',
							),
							'expected' => (string) $case['timestamp'],
							'actual'   => self::describe_value( $date_i18n ),
						);
					}
				}
			}

			$invalid = \wp_date( 'Y-m-d', 'not-a-timestamp', $timezone );
			if ( false !== $invalid ) {
				$failures[] = array(
					'api'      => 'wp_date',
					'case'     => array(
						'timestamp' => 'not-a-timestamp',
						'format'    => 'Y-m-d',
					),
					'expected' => false,
					'actual'   => self::describe_value( $invalid ),
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.wp-date-explicit-utc-oracle', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'l10n.wp-date-explicit-utc-oracle',
			array() === $failures,
			array(
				'cases'           => count( $cases ),
				'timezone'        => $timezone->getName(),
				'dateI18nChecked' => $date_i18n_checked,
				'samples'         => $samples,
				'failures'        => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_locale_behaviour( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'sanitize_locale_name' ) || ! function_exists( 'add_filter' ) ) {
			return $ctx->skip( 'l10n.locale-determination-no-db', 'Locale sanitization or filter APIs are unavailable.' );
		}

		$snapshot = self::snapshot_global_state();
		$failures = array();

		try {
			self::clear_filters(
				array(
					'locale',
					'pre_determine_locale',
					'determine_locale',
				)
			);

			$base_locale          = self::generated_locale( $ctx->fork( 'locale-base' ) );
			$GLOBALS['locale']   = $base_locale;
			$get_locale_actual   = \get_locale();
			$get_locale_expected = $base_locale;
			if ( $get_locale_actual !== $get_locale_expected ) {
				$failures[] = array(
					'api'      => 'get_locale',
					'expected' => $get_locale_expected,
					'actual'   => $get_locale_actual,
				);
			}

			$filtered_locale = self::generated_locale( $ctx->fork( 'locale-filtered' ) );
			\add_filter(
				'locale',
				static function () use ( $filtered_locale ) {
					return $filtered_locale;
				},
				10,
				1
			);

			$filtered_actual = \get_locale();
			if ( $filtered_actual !== $filtered_locale ) {
				$failures[] = array(
					'api'      => 'get_locale:locale-filter',
					'expected' => $filtered_locale,
					'actual'   => $filtered_actual,
				);
			}

			self::clear_filters( array( 'locale', 'pre_determine_locale', 'determine_locale' ) );

			$pre_determined = self::generated_locale( $ctx->fork( 'locale-pre-determine' ) );
			\add_filter(
				'pre_determine_locale',
				static function () use ( $pre_determined ) {
					return $pre_determined;
				}
			);

			$determined_actual = \determine_locale();
			if ( $determined_actual !== $pre_determined ) {
				$failures[] = array(
					'api'      => 'determine_locale:pre-filter',
					'expected' => $pre_determined,
					'actual'   => $determined_actual,
				);
			}

			self::clear_filters( array( 'locale', 'pre_determine_locale', 'determine_locale' ) );

			$raw_login_locale      = 'de_DE<script>' . $ctx->fork( 'login-locale' )->identifier( 1, 6 );
			$sanitized_login       = \sanitize_locale_name( $raw_login_locale );
			$GLOBALS['pagenow']    = 'wp-login.php';
			$_GET['wp_lang']       = $raw_login_locale;
			$_COOKIE['wp_lang']    = '';
			$login_locale_actual   = \determine_locale();
			$login_locale_expected = $sanitized_login;

			if ( $login_locale_actual !== $login_locale_expected ) {
				$failures[] = array(
					'api'        => 'determine_locale:login-wp-lang',
					'rawLocale'  => self::preview_string( $raw_login_locale ),
					'expected'   => $login_locale_expected,
					'actual'     => $login_locale_actual,
					'sanitized'  => $sanitized_login,
					'usesDbStub' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub,
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.locale-determination-no-db', $e );
		} finally {
			self::restore_global_state( $snapshot );
		}

		return $ctx->result(
			'l10n.locale-determination-no-db',
			array() === $failures,
			array(
				'baseLocale'       => $base_locale ?? null,
				'filteredLocale'   => $filtered_locale ?? null,
				'preDetermined'    => $pre_determined ?? null,
				'loginRawPreview'  => isset( $raw_login_locale ) ? self::preview_string( $raw_login_locale ) : null,
				'loginSanitized'   => $sanitized_login ?? null,
				'usesDbStub'       => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub,
				'failures'         => $failures,
			)
		);
	}

	private static function check_locale_switching( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing_functions = self::missing_functions(
			array(
				'get_user_locale',
				'switch_to_locale',
				'restore_previous_locale',
				'restore_current_locale',
				'is_locale_switched',
				'add_action',
				'remove_action',
				'has_filter',
			)
		);
		$missing_classes   = self::missing_classes(
			array(
				'WP_Locale',
				'WP_Locale_Switcher',
				'WP_User',
			)
		);

		if ( $missing_functions || $missing_classes ) {
			return $ctx->skip(
				'l10n.locale-switch-user-locale-stack',
				'Locale switcher or user locale APIs are unavailable.',
				array(
					'missingFunctions' => $missing_functions,
					'missingClasses'   => $missing_classes,
				)
			);
		}

		$snapshot = self::snapshot_global_state();
		$failures = array();
		$events   = array();

		$base_locale   = 'en_US';
		$first_locale  = 'es_ES';
		$second_locale = 'de_DE';
		$user_locale   = 'fr_FR';
		$user_id       = 2000 + $ctx->int( 1, 7000 );

		$switch_action = static function ( $locale, $action_user_id ) use ( &$events ): void {
			$events[] = array(
				'action' => 'switch_locale',
				'locale' => $locale,
				'userId' => $action_user_id,
			);
		};
		$restore_action = static function ( $locale, $previous_locale ) use ( &$events ): void {
			$events[] = array(
				'action'   => 'restore_previous_locale',
				'locale'   => $locale,
				'previous' => $previous_locale,
			);
		};
		$change_action = static function ( $locale ) use ( &$events ): void {
			$events[] = array(
				'action' => 'change_locale',
				'locale' => $locale,
			);
		};

		try {
			self::clear_filters(
				array(
					'locale',
					'determine_locale',
					'pre_determine_locale',
					'switch_locale',
					'restore_previous_locale',
					'change_locale',
				)
			);
			$GLOBALS['locale']        = $base_locale;
			$GLOBALS['l10n']          = array();
			$GLOBALS['l10n_unloaded'] = array();

			$switcher = self::locale_switcher( $base_locale, array( $base_locale, $first_locale, $second_locale, $user_locale ) );
			$switcher->init();
			$GLOBALS['wp_locale_switcher'] = $switcher;

			\add_action( 'switch_locale', $switch_action, 10, 2 );
			\add_action( 'restore_previous_locale', $restore_action, 10, 2 );
			\add_action( 'change_locale', $change_action, 10, 1 );

			$user              = self::fake_wp_user( $user_id, $user_locale );
			$user_without_pref = self::fake_wp_user( $user_id + 1, '' );
			$user_actual       = \get_user_locale( $user );
			$user_fallback     = \get_user_locale( $user_without_pref );

			$switched_first          = \switch_to_locale( $first_locale );
			$same_locale_rejected    = \switch_to_locale( $first_locale );
			$missing_locale_rejected = \switch_to_locale( 'zz_ZZ' );
			$after_first             = array(
				'getLocale'       => \get_locale(),
				'determineLocale' => \determine_locale(),
				'isSwitched'      => \is_locale_switched(),
			);

			$switched_second = $switcher->switch_to_locale( $second_locale, $user_id );
			$after_second    = array(
				'getLocale'        => \get_locale(),
				'determineLocale'  => \determine_locale(),
				'isSwitched'       => \is_locale_switched(),
				'switchedLocale'   => method_exists( $switcher, 'get_switched_locale' ) ? $switcher->get_switched_locale() : null,
				'switchedUserId'   => method_exists( $switcher, 'get_switched_user_id' ) ? $switcher->get_switched_user_id() : null,
			);

			$restored_previous = \restore_previous_locale();
			$after_restore     = array(
				'getLocale'       => \get_locale(),
				'determineLocale' => \determine_locale(),
				'isSwitched'      => \is_locale_switched(),
			);
			$restored_current  = \restore_current_locale();
			$after_current     = array(
				'getLocale'       => \get_locale(),
				'determineLocale' => \determine_locale(),
				'isSwitched'      => \is_locale_switched(),
			);
			$restore_empty     = \restore_previous_locale();

			\remove_action( 'switch_locale', $switch_action, 10 );
			\remove_action( 'restore_previous_locale', $restore_action, 10 );
			\remove_action( 'change_locale', $change_action, 10 );

			$expectations = array(
				'get_user_locale:explicit'      => array( $user_locale, $user_actual ),
				'get_user_locale:fallback'      => array( $base_locale, $user_fallback ),
				'switch_to_locale:first'        => array( true, $switched_first ),
				'switch_to_locale:same'         => array( false, $same_locale_rejected ),
				'switch_to_locale:unavailable'  => array( false, $missing_locale_rejected ),
				'after-first:get_locale'        => array( $first_locale, $after_first['getLocale'] ),
				'after-first:determine_locale'  => array( $first_locale, $after_first['determineLocale'] ),
				'after-first:is_switched'       => array( true, $after_first['isSwitched'] ),
				'switcher:user-context'         => array( true, $switched_second ),
				'after-second:get_locale'       => array( $second_locale, $after_second['getLocale'] ),
				'after-second:determine_locale' => array( $second_locale, $after_second['determineLocale'] ),
				'after-second:is_switched'      => array( true, $after_second['isSwitched'] ),
				'after-second:switched-locale'  => array( $second_locale, $after_second['switchedLocale'] ),
				'after-second:switched-user'    => array( $user_id, $after_second['switchedUserId'] ),
				'restore_previous_locale'       => array( $first_locale, $restored_previous ),
				'after-restore:get_locale'      => array( $first_locale, $after_restore['getLocale'] ),
				'after-restore:is_switched'     => array( true, $after_restore['isSwitched'] ),
				'restore_current_locale'        => array( $base_locale, $restored_current ),
				'after-current:get_locale'      => array( $base_locale, $after_current['getLocale'] ),
				'after-current:is_switched'     => array( false, $after_current['isSwitched'] ),
				'restore-empty-stack'           => array( false, $restore_empty ),
				'cleanup:switch-action'         => array( false, \has_filter( 'switch_locale', $switch_action ) ),
				'cleanup:restore-action'        => array( false, \has_filter( 'restore_previous_locale', $restore_action ) ),
				'cleanup:change-action'         => array( false, \has_filter( 'change_locale', $change_action ) ),
			);

			foreach ( $expectations as $api => $pair ) {
				if ( $pair[0] !== $pair[1] ) {
					$failures[] = array(
						'api'      => $api,
						'expected' => self::describe_value( $pair[0] ),
						'actual'   => self::describe_value( $pair[1] ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.locale-switch-user-locale-stack', $e );
		} finally {
			self::restore_global_state( $snapshot );
		}

		return $ctx->result(
			'l10n.locale-switch-user-locale-stack',
			array() === $failures,
			array(
				'baseLocale'   => $base_locale,
				'locales'      => array( $first_locale, $second_locale, $user_locale ),
				'userId'       => $user_id,
				'events'       => array_slice( $events, 0, self::FAILURE_LIMIT ),
				'eventCount'   => count( $events ),
				'failures'     => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_textdomain_loading( \ComponentFuzz\FuzzContext $ctx ): array {
		$snapshot = self::snapshot_global_state();
		$path     = self::temp_mo_path( $ctx );
		$path_two = self::temp_mo_path( $ctx, 'merge' );
		$domain   = 'component-fuzz-l10n-' . substr( sha1( (string) $ctx->seed() . ':' . (string) $ctx->iteration() ), 0, 12 );
		$locale   = 'en_US';

		$source              = 'Loaded <source> & "quoted" ' . $ctx->iteration();
		$translation         = 'Translated <value> & "quoted" ' . $ctx->iteration();
		$context             = 'button-context-' . $ctx->fork( 'loaded-context' )->identifier( 3, 12 );
		$context_source      = 'Open';
		$context_translation = 'Open translated ' . $ctx->iteration();
		$single              = '%s loaded item';
		$plural              = '%s loaded items';
		$single_translation  = '%s translated item';
		$plural_translation  = '%s translated items';
		$second_source       = 'Second loaded source ' . $ctx->fork( 'second-source' )->identifier( 4, 12 );
		$second_translation  = 'Second loaded translation ' . $ctx->iteration();
		$failures            = array();

		try {
			self::clear_filters(
				array(
					'gettext',
					"gettext_{$domain}",
					'gettext_with_context',
					"gettext_with_context_{$domain}",
					'ngettext',
					"ngettext_{$domain}",
					'ngettext_with_context',
					"ngettext_with_context_{$domain}",
					'pre_load_textdomain',
					'override_load_textdomain',
					'load_textdomain_mofile',
					'load_translation_file',
					'translation_file_format',
					'override_unload_textdomain',
				)
			);

			if ( file_exists( $path ) ) {
				unlink( $path );
			}
			if ( file_exists( $path_two ) ) {
				unlink( $path_two );
			}

			$exported = self::write_mo_file(
				$path,
				array(
					array(
						'singular'     => $source,
						'translations' => array( $translation ),
					),
					array(
						'singular'     => $context_source,
						'context'      => $context,
						'translations' => array( $context_translation ),
					),
					array(
						'singular'     => $single,
						'plural'       => $plural,
						'translations' => array( $single_translation, $plural_translation ),
					),
					array(
						'singular'     => $single,
						'plural'       => $plural,
						'context'      => $context,
						'translations' => array( $single_translation, $plural_translation ),
					),
				)
			);
			$exported_second = self::write_mo_file(
				$path_two,
				array(
					array(
						'singular'     => $second_source,
						'translations' => array( $second_translation ),
					),
				)
			);

			if ( ! $exported || ! $exported_second ) {
				return $ctx->skip(
					'l10n.textdomain-load-unload-restores-globals',
					'Could not write a temporary MO file.',
					array(
						'pathPreview' => self::preview_string( $path ),
						'pathTwoPreview' => self::preview_string( $path_two ),
					)
				);
			}

			$never_unloaded = \unload_textdomain( $domain . '-never-loaded' );
			if ( false !== $never_unloaded ) {
				$failures[] = array(
					'api'      => 'unload_textdomain:never-loaded',
					'expected' => false,
					'actual'   => $never_unloaded,
				);
			}

			$noop_domain = $domain . '-noop';
			self::install_noop_domain( $noop_domain );
			$noop_unloaded = \unload_textdomain( $noop_domain );
			if ( false !== $noop_unloaded || \is_textdomain_loaded( $noop_domain ) || isset( $GLOBALS['l10n'][ $noop_domain ] ) ) {
				$failures[] = array(
					'api'      => 'unload_textdomain:noop-domain',
					'expected' => array(
						'unloaded'     => false,
						'textdomainOn' => false,
						'l10nEntry'    => false,
					),
					'actual'   => array(
						'unloaded'     => $noop_unloaded,
						'textdomainOn' => \is_textdomain_loaded( $noop_domain ),
						'l10nEntry'    => isset( $GLOBALS['l10n'][ $noop_domain ] ),
					),
				);
			}

			$missing_path_loaded = \load_textdomain( $domain . '-missing', $path . '.missing', $locale );
			if ( false !== $missing_path_loaded || \is_textdomain_loaded( $domain . '-missing' ) ) {
				$failures[] = array(
					'api'      => 'load_textdomain:missing-file',
					'expected' => false,
					'actual'   => array(
						'loaded'       => $missing_path_loaded,
						'textdomainOn' => \is_textdomain_loaded( $domain . '-missing' ),
					),
				);
			}

			$loaded = \load_textdomain( $domain, $path, $locale );
			if ( true !== $loaded || ! \is_textdomain_loaded( $domain ) ) {
				$failures[] = array(
					'api'      => 'load_textdomain',
					'expected' => true,
					'actual'   => array(
						'loaded'       => $loaded,
						'textdomainOn' => \is_textdomain_loaded( $domain ),
					),
				);
			}

			$loaded_second = \load_textdomain( $domain, $path_two, $locale );
			if ( true !== $loaded_second || \translate( $second_source, $domain ) !== $second_translation ) {
				$failures[] = array(
					'api'      => 'load_textdomain:merge-second-file',
					'expected' => array(
						'loaded'      => true,
						'translation' => self::describe_value( $second_translation ),
					),
					'actual'   => array(
						'loaded'      => $loaded_second,
						'translation' => self::describe_value( \translate( $second_source, $domain ) ),
					),
				);
			}

			$expected_loaded = array(
				'translate'               => $translation,
				'__'                      => $translation,
				'_x'                      => $context_translation,
				'esc_html__'              => \esc_html( $translation ),
				'esc_attr__'              => \esc_attr( $translation ),
				'esc_html_x'              => \esc_html( $context_translation ),
				'esc_attr_x'              => \esc_attr( $context_translation ),
				'_n:1'                    => $single_translation,
				'_n:2'                    => $plural_translation,
				'translate_nooped_plural:1' => $single_translation,
				'translate_nooped_plural:2' => $plural_translation,
				'translate_nooped_plural_x:2' => $plural_translation,
				'translate:second-file'   => $second_translation,
			);
			$actual_loaded   = array(
				'translate'               => \translate( $source, $domain ),
				'__'                      => \__( $source, $domain ),
				'_x'                      => \_x( $context_source, $context, $domain ),
				'esc_html__'              => \esc_html__( $source, $domain ),
				'esc_attr__'              => \esc_attr__( $source, $domain ),
				'esc_html_x'              => \esc_html_x( $context_source, $context, $domain ),
				'esc_attr_x'              => \esc_attr_x( $context_source, $context, $domain ),
				'_n:1'                    => \_n( $single, $plural, 1, $domain ),
				'_n:2'                    => \_n( $single, $plural, 2, $domain ),
				'translate_nooped_plural:1' => \translate_nooped_plural( \_n_noop( $single, $plural, $domain ), 1 ),
				'translate_nooped_plural:2' => \translate_nooped_plural( \_n_noop( $single, $plural, $domain ), 2 ),
				'translate_nooped_plural_x:2' => \translate_nooped_plural( \_nx_noop( $single, $plural, $context, $domain ), 2 ),
				'translate:second-file'   => \translate( $second_source, $domain ),
			);

			foreach ( $expected_loaded as $api => $expected ) {
				if ( $actual_loaded[ $api ] !== $expected ) {
					$failures[] = array(
						'api'      => $api,
						'expected' => self::describe_value( $expected ),
						'actual'   => self::describe_value( $actual_loaded[ $api ] ),
					);
				}
			}

			$unloaded = \unload_textdomain( $domain );
			if ( true !== $unloaded || \is_textdomain_loaded( $domain ) ) {
				$failures[] = array(
					'api'      => 'unload_textdomain',
					'expected' => true,
					'actual'   => array(
						'unloaded'     => $unloaded,
						'textdomainOn' => \is_textdomain_loaded( $domain ),
					),
				);
			}

			$after_unload = \translate( $source, $domain );
			if ( $after_unload !== $source ) {
				$failures[] = array(
					'api'      => 'translate:after-unload',
					'expected' => self::describe_value( $source ),
					'actual'   => self::describe_value( $after_unload ),
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.textdomain-load-unload-restores-globals', $e );
		} finally {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
			if ( file_exists( $path_two ) ) {
				unlink( $path_two );
			}
			self::restore_global_state( $snapshot );
		}

		return $ctx->result(
			'l10n.textdomain-load-unload-restores-globals',
			array() === $failures,
			array(
				'domain'            => $domain,
				'locale'            => $locale,
				'loadedPreview'     => self::preview_string( $source ),
				'translationSha1'   => sha1( $translation ),
				'pluralCounts'      => array( 1, 2 ),
				'missingFileTested' => true,
				'mergeFileTested'   => true,
				'failures'          => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_translation_path_guards( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing_functions = self::missing_functions(
			array(
				'load_plugin_textdomain',
				'load_muplugin_textdomain',
				'load_theme_textdomain',
				'get_translations_for_domain',
				'add_filter',
				'remove_filter',
				'has_filter',
			)
		);

		if ( $missing_functions ) {
			return $ctx->skip(
				'l10n.translation-file-path-guards',
				'Optional textdomain path APIs are unavailable.',
				array( 'missingFunctions' => $missing_functions )
			);
		}

		$snapshot = self::snapshot_global_state();
		$path     = self::temp_mo_path( $ctx, 'path-guards' );
		$locale   = 'en_US';
		$source   = 'Path guarded source ' . $ctx->identifier( 4, 12 );
		$target   = 'Path guarded translation ' . $ctx->iteration();
		$failures = array();

		$loaded_domain   = self::unique_domain( $ctx, 'path-loaded' );
		$blocked_domain  = self::unique_domain( $ctx, 'path-blocked' );
		$redirect_domain = self::unique_domain( $ctx, 'path-redirect' );
		$plugin_domain   = self::unique_domain( $ctx, 'plugin-path' );
		$mu_domain       = self::unique_domain( $ctx, 'mu-path' );
		$theme_domain    = self::unique_domain( $ctx, 'theme-path' );

		$format_calls = array();
		$file_calls   = array();
		$mofile_calls = array();

		$format_filter = static function ( $preferred_format, $domain ) use ( &$format_calls, $loaded_domain ) {
			$format_calls[] = array(
				'domain' => $domain,
				'format' => $preferred_format,
			);
			if ( $loaded_domain === $domain ) {
				return 'invalid-format';
			}
			return $preferred_format;
		};
		$file_filter   = static function ( $file, $domain, $file_locale ) use ( &$file_calls, $blocked_domain ) {
			$file_calls[] = array(
				'domain' => $domain,
				'locale' => $file_locale,
				'file'   => basename( (string) $file ),
			);
			if ( $blocked_domain === $domain ) {
				return (string) $file . '.blocked';
			}
			return $file;
		};
		$mofile_filter = static function ( $mofile, $domain ) use ( &$mofile_calls, $redirect_domain, $path ) {
			$mofile_calls[] = array(
				'domain' => $domain,
				'file'   => basename( (string) $mofile ),
			);
			if ( $redirect_domain === $domain ) {
				return $path;
			}
			return $mofile;
		};

		try {
			self::clear_filters(
				array(
					'translation_file_format',
					'load_translation_file',
					'load_textdomain_mofile',
				)
			);

			if ( file_exists( $path ) ) {
				unlink( $path );
			}

			if (
				! self::write_mo_file(
					$path,
					array(
						array(
							'singular'     => $source,
							'translations' => array( $target ),
						),
					)
				)
			) {
				return $ctx->skip(
					'l10n.translation-file-path-guards',
					'Could not write a temporary MO file.',
					array( 'pathPreview' => self::preview_string( $path ) )
				);
			}

			\add_filter( 'translation_file_format', $format_filter, 10, 2 );
			\add_filter( 'load_translation_file', $file_filter, 10, 3 );
			\add_filter( 'load_textdomain_mofile', $mofile_filter, 10, 2 );

			$loaded = \load_textdomain( $loaded_domain, $path, $locale );
			$loaded_translation = \translate( $source, $loaded_domain );
			$blocked = \load_textdomain( $blocked_domain, $path, $locale );
			$redirected = \load_textdomain( $redirect_domain, $path . '.missing', $locale );
			$redirected_translation = \translate( $source, $redirect_domain );

			$non_string_load   = \load_textdomain( array( 'not-a-domain' ), $path, $locale );
			$non_string_plugin = \load_plugin_textdomain( array( 'not-a-domain' ) );
			$non_string_theme  = \load_theme_textdomain( array( 'not-a-domain' ), sys_get_temp_dir() );

			\get_translations_for_domain( $plugin_domain );
			$plugin_registered = \load_plugin_textdomain( $plugin_domain, false, 'component-fuzz/languages/' );
			$mu_registered     = \load_muplugin_textdomain( $mu_domain, '/component-fuzz/mu-languages/' );
			$theme_path        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'component-fuzz-theme-languages';
			$theme_registered  = \load_theme_textdomain( $theme_domain, $theme_path );

			\remove_filter( 'translation_file_format', $format_filter, 10 );
			\remove_filter( 'load_translation_file', $file_filter, 10 );
			\remove_filter( 'load_textdomain_mofile', $mofile_filter, 10 );

			$expectations = array(
				'load_textdomain:invalid-format-falls-back-to-mo' => array( true, $loaded ),
				'translate:invalid-format-fallback' => array( $target, $loaded_translation ),
				'load_textdomain:filtered-missing-file' => array( false, $blocked ),
				'load_textdomain:mofile-filter-redirect' => array( true, $redirected ),
				'translate:mofile-filter-redirect' => array( $target, $redirected_translation ),
				'load_textdomain:non-string-domain' => array( false, $non_string_load ),
				'load_plugin_textdomain:non-string-domain' => array( false, $non_string_plugin ),
				'load_theme_textdomain:non-string-domain' => array( false, $non_string_theme ),
				'load_plugin_textdomain:registers-path' => array( true, $plugin_registered ),
				'load_muplugin_textdomain:registers-path' => array( true, $mu_registered ),
				'load_theme_textdomain:registers-path' => array( true, $theme_registered ),
				'load_plugin_textdomain:clears-noop' => array( false, isset( $GLOBALS['l10n'][ $plugin_domain ] ) ),
				'cleanup:translation_file_format' => array( false, \has_filter( 'translation_file_format', $format_filter ) ),
				'cleanup:load_translation_file' => array( false, \has_filter( 'load_translation_file', $file_filter ) ),
				'cleanup:load_textdomain_mofile' => array( false, \has_filter( 'load_textdomain_mofile', $mofile_filter ) ),
			);

			if ( defined( 'WP_PLUGIN_DIR' ) ) {
				$expectations['registry:plugin-custom-path'] = array(
					rtrim( WP_PLUGIN_DIR . '/component-fuzz/languages', '/' ),
					self::registry_custom_path( $plugin_domain ),
				);
			}
			if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
				$expectations['registry:mu-custom-path'] = array(
					rtrim( WPMU_PLUGIN_DIR . '/component-fuzz/mu-languages', '/' ),
					self::registry_custom_path( $mu_domain ),
				);
			}
			$expectations['registry:theme-custom-path'] = array(
				rtrim( $theme_path, '/' ),
				self::registry_custom_path( $theme_domain ),
			);

			foreach ( $expectations as $api => $pair ) {
				if ( $pair[0] !== $pair[1] ) {
					$failures[] = array(
						'api'      => $api,
						'expected' => self::describe_value( $pair[0] ),
						'actual'   => self::describe_value( $pair[1] ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.translation-file-path-guards', $e );
		} finally {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
			self::restore_global_state( $snapshot );
		}

		return $ctx->result(
			'l10n.translation-file-path-guards',
			array() === $failures,
			array(
				'domains'     => array( $loaded_domain, $blocked_domain, $redirect_domain ),
				'formatCalls' => array_slice( $format_calls, 0, self::FAILURE_LIMIT ),
				'fileCalls'   => array_slice( $file_calls, 0, self::FAILURE_LIMIT ),
				'mofileCalls' => array_slice( $mofile_calls, 0, self::FAILURE_LIMIT ),
				'failures'    => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_script_translation_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing_functions = self::missing_functions(
			array(
				'wp_register_script',
				'wp_set_script_translations',
				'wp_scripts',
				'load_script_textdomain',
				'wp_json_encode',
				'add_filter',
				'remove_filter',
				'has_filter',
			)
		);
		$missing_classes   = self::missing_classes( array( 'WP_Scripts', '_WP_Dependency' ) );

		if ( $missing_functions || $missing_classes ) {
			return $ctx->skip(
				'l10n.script-translation-helpers-filtered-json',
				'Script translation helpers are unavailable in this bootstrap.',
				array(
					'missingFunctions' => $missing_functions,
					'missingClasses'   => $missing_classes,
				)
			);
		}

		$snapshot = self::snapshot_global_state();
		$failures = array();
		$calls    = array();

		$handle = 'cf-l10n-' . $ctx->identifier( 4, 14 );
		$domain = self::unique_domain( $ctx, 'script' );
		$path   = sys_get_temp_dir();
		$json   = \wp_json_encode(
			array(
				'locale_data' => array(
					$domain => array(
						''      => array(
							'domain' => $domain,
							'lang'   => 'en_US',
						),
						'Hello' => array( 'Translated <hello> & "quoted"' ),
					),
				),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);

		$pre_filter = static function ( $translations, $file, $filter_handle, $filter_domain ) use ( &$calls, $handle, $domain, $json ) {
			$calls[] = array(
				'file'   => false === $file ? false : basename( (string) $file ),
				'handle' => $filter_handle,
				'domain' => $filter_domain,
			);
			if ( $handle === $filter_handle && $domain === $filter_domain ) {
				return $json;
			}
			return $translations;
		};

		try {
			self::clear_filters(
				array(
					'pre_load_script_translations',
					'load_script_translation_file',
					'load_script_textdomain_relative_path',
				)
			);
			$GLOBALS['wp_scripts'] = new \WP_Scripts();

			\add_filter( 'pre_load_script_translations', $pre_filter, 10, 4 );

			\wp_register_script( 'wp-i18n', '/wp-includes/js/dist/i18n.js', array(), '1.0.0' );
			\wp_register_script( $handle, '/wp-includes/js/dist/' . $handle . '.min.js', array( 'wp-i18n' ), '1.0.0' );

			$set_first       = \wp_set_script_translations( $handle, $domain, $path );
			$set_second      = \wp_set_script_translations( $handle, $domain, $path );
			$set_missing     = \wp_set_script_translations( $handle . '-missing', $domain, $path );
			$loaded          = \load_script_textdomain( $handle, $domain, $path );
			$missing_loaded  = \load_script_textdomain( $handle . '-missing', $domain, $path );
			$printed         = \wp_scripts()->print_translations( $handle, false );
			$dependency      = \wp_scripts()->registered[ $handle ] ?? null;
			$wp_i18n_deps    = $dependency instanceof \_WP_Dependency ? count( array_keys( $dependency->deps, 'wp-i18n', true ) ) : null;

			\remove_filter( 'pre_load_script_translations', $pre_filter, 10 );

			$expectations = array(
				'wp_set_script_translations:first' => array( true, $set_first ),
				'wp_set_script_translations:idempotent' => array( true, $set_second ),
				'wp_set_script_translations:missing-handle' => array( false, $set_missing ),
				'load_script_textdomain:filtered-json' => array( $json, $loaded ),
				'load_script_textdomain:missing-handle' => array( false, $missing_loaded ),
				'dependency:wp-i18n-once' => array( 1, $wp_i18n_deps ),
				'cleanup:pre_load_script_translations' => array( false, \has_filter( 'pre_load_script_translations', $pre_filter ) ),
			);

			foreach ( $expectations as $api => $pair ) {
				if ( $pair[0] !== $pair[1] ) {
					$failures[] = array(
						'api'      => $api,
						'expected' => self::describe_value( $pair[0] ),
						'actual'   => self::describe_value( $pair[1] ),
					);
				}
			}

			if (
				! is_string( $printed )
				|| ! str_contains( $printed, 'wp.i18n.setLocaleData' )
				|| ! str_contains( $printed, $domain )
				|| str_contains( $printed, '<hello>' )
			) {
				$failures[] = array(
					'api'      => 'print_translations:escaped-inline-json',
					'expected' => 'setLocaleData output with escaped JSON payload',
					'actual'   => self::describe_value( $printed ),
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.script-translation-helpers-filtered-json', $e );
		} finally {
			self::restore_global_state( $snapshot );
		}

		return $ctx->result(
			'l10n.script-translation-helpers-filtered-json',
			array() === $failures,
			array(
				'handle'   => $handle,
				'domain'   => $domain,
				'calls'    => array_slice( $calls, 0, self::FAILURE_LIMIT ),
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_filter_action_cleanup( \ComponentFuzz\FuzzContext $ctx ): array {
		$snapshot = self::snapshot_global_state();
		$domain   = self::unique_domain( $ctx, 'filters' );
		$source   = 'Filter source ' . $ctx->identifier( 4, 12 );
		$context  = 'Filter context ' . $ctx->identifier( 4, 12 );
		$single   = '%s filtered item';
		$plural   = '%s filtered items';
		$count    = 2;
		$calls    = array();
		$failures = array();

		$gettext = static function ( $translation, $text, $filter_domain ) use ( &$calls, $domain, $source ) {
			$calls[] = array(
				'hook'   => 'gettext',
				'text'   => $text,
				'domain' => $filter_domain,
			);
			if ( $domain === $filter_domain && $source === $text ) {
				return 'global-' . $translation;
			}
			return $translation;
		};
		$gettext_domain = static function ( $translation, $text, $filter_domain ) use ( &$calls, $domain, $source ) {
			$calls[] = array(
				'hook'   => 'gettext-domain',
				'text'   => $text,
				'domain' => $filter_domain,
			);
			if ( $domain === $filter_domain && $source === $text ) {
				return 'domain-' . $translation;
			}
			return $translation;
		};
		$context_filter = static function ( $translation, $text, $filter_context, $filter_domain ) use ( &$calls, $domain, $source, $context ) {
			$calls[] = array(
				'hook'    => 'gettext_with_context',
				'text'    => $text,
				'context' => $filter_context,
				'domain'  => $filter_domain,
			);
			if ( $domain === $filter_domain && $source === $text && $context === $filter_context ) {
				return 'context-' . $translation;
			}
			return $translation;
		};
		$ngettext = static function ( $translation, $filter_single, $filter_plural, $number, $filter_domain ) use ( &$calls, $domain, $plural, $count ) {
			$calls[] = array(
				'hook'   => 'ngettext',
				'number' => $number,
				'domain' => $filter_domain,
			);
			if ( $domain === $filter_domain && $plural === $filter_plural && $count === (int) $number ) {
				return 'plural-' . $translation;
			}
			return $translation;
		};
		$ngettext_context = static function ( $translation, $filter_single, $filter_plural, $number, $filter_context, $filter_domain ) use ( &$calls, $domain, $plural, $context, $count ) {
			$calls[] = array(
				'hook'    => 'ngettext_with_context',
				'number'  => $number,
				'context' => $filter_context,
				'domain'  => $filter_domain,
			);
			if ( $domain === $filter_domain && $plural === $filter_plural && $context === $filter_context && $count === (int) $number ) {
				return 'plural-context-' . $translation;
			}
			return $translation;
		};
		$load_action = static function ( $action_domain, $mofile ) use ( &$calls, $domain ) {
			if ( $domain === $action_domain ) {
				$calls[] = array(
					'hook' => 'load_textdomain',
					'file' => basename( (string) $mofile ),
				);
			}
		};
		$unload_action = static function ( $action_domain, $reloadable ) use ( &$calls, $domain ) {
			if ( $domain === $action_domain ) {
				$calls[] = array(
					'hook'       => 'unload_textdomain',
					'reloadable' => $reloadable,
				);
			}
		};

		try {
			self::clear_filters(
				array(
					'gettext',
					"gettext_{$domain}",
					'gettext_with_context',
					'ngettext',
					'ngettext_with_context',
					'load_textdomain',
					'unload_textdomain',
				)
			);
			self::install_noop_domain( $domain );

			\add_filter( 'gettext', $gettext, 10, 3 );
			\add_filter( "gettext_{$domain}", $gettext_domain, 10, 3 );
			\add_filter( 'gettext_with_context', $context_filter, 10, 4 );
			\add_filter( 'ngettext', $ngettext, 10, 5 );
			\add_filter( 'ngettext_with_context', $ngettext_context, 10, 6 );
			\add_action( 'load_textdomain', $load_action, 10, 2 );
			\add_action( 'unload_textdomain', $unload_action, 10, 2 );

			$actuals = array(
				'translate' => \translate( $source, $domain ),
				'_x'       => \_x( $source, $context, $domain ),
				'_n'       => \_n( $single, $plural, $count, $domain ),
				'_nx'      => \_nx( $single, $plural, $count, $context, $domain ),
			);
			\load_textdomain( $domain, self::temp_mo_path( $ctx, 'missing-for-action' ), 'en_US' );
			\unload_textdomain( $domain );

			\remove_filter( 'gettext', $gettext, 10 );
			\remove_filter( "gettext_{$domain}", $gettext_domain, 10 );
			\remove_filter( 'gettext_with_context', $context_filter, 10 );
			\remove_filter( 'ngettext', $ngettext, 10 );
			\remove_filter( 'ngettext_with_context', $ngettext_context, 10 );
			\remove_action( 'load_textdomain', $load_action, 10 );
			\remove_action( 'unload_textdomain', $unload_action, 10 );

			$expected = array(
				'translate' => 'domain-global-' . $source,
				'_x'       => 'context-' . $source,
				'_n'       => 'plural-' . $plural,
				'_nx'      => 'plural-context-' . $plural,
			);
			foreach ( $expected as $api => $expected_value ) {
				if ( $actuals[ $api ] !== $expected_value ) {
					$failures[] = array(
						'api'      => $api,
						'expected' => self::describe_value( $expected_value ),
						'actual'   => self::describe_value( $actuals[ $api ] ),
					);
				}
			}

			$cleanup = array(
				'gettext'              => \has_filter( 'gettext', $gettext ),
				'gettext-domain'       => \has_filter( "gettext_{$domain}", $gettext_domain ),
				'gettext-with-context' => \has_filter( 'gettext_with_context', $context_filter ),
				'ngettext'             => \has_filter( 'ngettext', $ngettext ),
				'ngettext-context'     => \has_filter( 'ngettext_with_context', $ngettext_context ),
				'load-action'          => \has_filter( 'load_textdomain', $load_action ),
				'unload-action'        => \has_filter( 'unload_textdomain', $unload_action ),
			);
			foreach ( $cleanup as $api => $actual ) {
				if ( false !== $actual ) {
					$failures[] = array(
						'api'      => 'cleanup:' . $api,
						'expected' => false,
						'actual'   => $actual,
					);
				}
			}

			if ( \translate( $source, $domain ) !== $source ) {
				$failures[] = array(
					'api'      => 'post-cleanup-translate',
					'expected' => self::describe_value( $source ),
					'actual'   => self::describe_value( \translate( $source, $domain ) ),
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'l10n.filters-actions-clean-up', $e );
		} finally {
			self::restore_global_state( $snapshot );
		}

		return $ctx->result(
			'l10n.filters-actions-clean-up',
			array() === $failures,
			array(
				'domain'    => $domain,
				'callCount' => count( $calls ),
				'calls'     => array_slice( $calls, 0, self::FAILURE_LIMIT ),
				'failures'  => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function string_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$corpus = array(
			'',
			'plain text',
			'Hello <b>world</b> & "quotes"',
			"line\nbreak\twith tab",
			"control\x00nul\x1Fdel\x7F",
			"invalid utf8 \xC3\x28 tail",
			"overlong-ish \xF0\x28\x8C\x28",
			'emoji 😀 snowman ☃',
			'مرحبا بالعالم',
			'中文字符 and é',
			'%s item &amp; entity',
			'entities &lt;tag&gt; &amp; &#039;quote&#039;',
			'</script><img src=x onerror=alert(1)>',
			"truncated euro \xE2\x82",
			str_repeat( 'é', 90 ),
			str_repeat( 'A', 96 ),
		);

		$cases = array();
		foreach ( $corpus as $index => $text ) {
			$cases[] = array(
				'text'    => self::bound_string( $text, self::MAX_TEXT_BYTES ),
				'domain'  => self::domain_for_index( $ctx, $index ),
				'context' => self::context_for_index( $ctx, $index ),
			);
		}

		for ( $i = 0; $i < self::GENERATED_STRING_CASES; ++$i ) {
			$cases[] = array(
				'text'    => self::bound_string( $ctx->text( 0, self::MAX_TEXT_BYTES ), self::MAX_TEXT_BYTES ),
				'domain'  => self::generated_domain( $ctx ),
				'context' => self::bound_string( $ctx->text( 0, self::MAX_CONTEXT_BYTES ), self::MAX_CONTEXT_BYTES ),
			);
		}

		return $cases;
	}

	private static function malformed_string_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$raw_cases = array(
			array(
				'text'     => "lone continuation \x80 byte",
				'singular' => "singular continuation \x80",
				'plural'   => "plural continuation \x80",
				'context'  => "context continuation \x80",
				'count'    => 0,
			),
			array(
				'text'     => "truncated two-byte \xC3",
				'singular' => "truncated singular \xC3",
				'plural'   => "truncated plural \xC3",
				'context'  => "truncated context \xC3",
				'count'    => 1,
			),
			array(
				'text'     => "truncated three-byte \xE2\x82",
				'singular' => "truncated singular \xE2\x82",
				'plural'   => "truncated plural \xE2\x82",
				'context'  => "truncated context \xE2\x82",
				'count'    => 2,
			),
			array(
				'text'     => "overlong-ish \xF0\x28\x8C\x28",
				'singular' => "overlong singular \xF0\x28\x8C\x28",
				'plural'   => "overlong plural \xF0\x28\x8C\x28",
				'context'  => "overlong context \xF0\x28\x8C\x28",
				'count'    => -1,
			),
			array(
				'text'     => "nul\x00inside and del\x7F",
				'singular' => "nul\x00singular",
				'plural'   => "nul\x00plural",
				'context'  => "nul\x00context",
				'count'    => 3,
			),
			array(
				'text'     => substr( str_repeat( 'é', 90 ), 0, self::MAX_TEXT_BYTES ),
				'singular' => substr( str_repeat( '☃', 60 ), 0, self::MAX_TEXT_BYTES ),
				'plural'   => substr( str_repeat( '😀', 50 ), 0, self::MAX_TEXT_BYTES ),
				'context'  => substr( str_repeat( '文', 40 ), 0, self::MAX_CONTEXT_BYTES ),
				'count'    => 99,
			),
		);

		for ( $i = 0; $i < self::GENERATED_MALFORMED_CASES; ++$i ) {
			$bytes = $ctx->bytes( 1, 24 );
			$raw_cases[] = array(
				'text'     => self::bound_string( "generated {$i} " . $bytes, self::MAX_TEXT_BYTES ),
				'singular' => self::bound_string( "generated singular {$i} " . $ctx->bytes( 1, 18 ), self::MAX_TEXT_BYTES ),
				'plural'   => self::bound_string( "generated plural {$i} " . $ctx->bytes( 1, 18 ), self::MAX_TEXT_BYTES ),
				'context'  => self::bound_string( "generated context {$i} " . $ctx->bytes( 1, 12 ), self::MAX_CONTEXT_BYTES ),
				'count'    => $ctx->choice( array( -3, -1, 0, 1, 2, $ctx->int( 3, 400 ) ) ),
			);
		}

		$cases = array();
		foreach ( $raw_cases as $index => $case ) {
			$case['text']     = self::bound_string( $case['text'], self::MAX_TEXT_BYTES );
			$case['singular'] = self::bound_string( $case['singular'], self::MAX_TEXT_BYTES );
			$case['plural']   = self::bound_string( $case['plural'], self::MAX_TEXT_BYTES );
			$case['context']  = self::bound_string( $case['context'], self::MAX_CONTEXT_BYTES );
			$case['domain']   = self::unique_domain( $ctx, 'malformed-' . $index );
			$cases[]          = $case;
		}

		return $cases;
	}

	private static function plural_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases  = array();
		$counts = array( -2, -1, 0, 1, 2, 3, 10, 101 );

		foreach ( $counts as $index => $count ) {
			$cases[] = array(
				'singular' => self::bound_string( '%s corpus item ' . $index, self::MAX_TEXT_BYTES ),
				'plural'   => self::bound_string( '%s corpus items ' . $index, self::MAX_TEXT_BYTES ),
				'count'    => $count,
				'domain'   => self::domain_for_index( $ctx, $index ),
				'context'  => self::context_for_index( $ctx, $index ),
			);
		}

		for ( $i = 0; $i < self::GENERATED_PLURAL_CASES; ++$i ) {
			$count = $ctx->choice(
				array(
					$ctx->int( -20, 20 ),
					$ctx->int( 21, 5000 ),
					1,
					0,
				)
			);

			$singular = self::bound_string( $ctx->text( 0, 96 ), 96 );
			if ( '' === $singular ) {
				$singular = '%s generated item';
			}

			$plural = self::bound_string( $ctx->text( 0, 96 ), 96 );
			if ( '' === $plural || $plural === $singular ) {
				$plural = $singular . 's';
			}

			$cases[] = array(
				'singular' => $singular,
				'plural'   => $plural,
				'count'    => $count,
				'domain'   => self::generated_domain( $ctx ),
				'context'  => self::bound_string( $ctx->text( 0, self::MAX_CONTEXT_BYTES ), self::MAX_CONTEXT_BYTES ),
			);
		}

		return $cases;
	}

	private static function number_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'number'   => 0,
				'decimals' => 0,
			),
			array(
				'number'   => 1,
				'decimals' => 0,
			),
			array(
				'number'   => -1,
				'decimals' => 0,
			),
			array(
				'number'   => 1234.567,
				'decimals' => 2,
			),
			array(
				'number'   => -987654.321,
				'decimals' => 3,
			),
		);

		for ( $i = 0; $i < self::GENERATED_NUMBER_CASES; ++$i ) {
			$divisor = $ctx->int( 1, 1000 );
			$cases[] = array(
				'number'   => $ctx->int( -1000000, 1000000 ) / $divisor,
				'decimals' => $ctx->int( 0, 5 ),
			);
		}

		return $cases;
	}

	private static function date_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$formats    = array( 'Y-m-d H:i:s', 'U', 'c', 'Y-m-d\TH:i:sP' );
		$timestamps = array( 0, 1, -1, 946684800, 1609459200, 2147483647 );
		$cases      = array();

		foreach ( $timestamps as $index => $timestamp ) {
			$cases[] = array(
				'timestamp' => $timestamp,
				'format'    => $formats[ $index % count( $formats ) ],
			);
		}

		for ( $i = 0; $i < self::GENERATED_DATE_CASES; ++$i ) {
			$cases[] = array(
				'timestamp' => $ctx->int( -2208988800, 2147483647 ),
				'format'    => $ctx->choice( $formats ),
			);
		}

		return $cases;
	}

	private static function write_mo_file( string $path, array $entries ): bool {
		$mo = new \MO();
		$mo->set_headers(
			array(
				'Content-Type' => 'text/plain; charset=UTF-8',
				'Plural-Forms' => 'nplurals=2; plural=(n != 1);',
			)
		);

		foreach ( $entries as $entry ) {
			$mo->add_entry( new \Translation_Entry( $entry ) );
		}

		return (bool) $mo->export_to_file( $path );
	}

	private static function expected_number_format( $number, int $decimals ): string {
		$settings = self::number_format_settings();
		if ( $settings['hasWpLocale'] ) {
			return number_format( $number, abs( $decimals ), $settings['decimalPoint'], $settings['thousandsSeparator'] );
		}

		return number_format( $number, abs( $decimals ) );
	}

	private static function number_format_settings(): array {
		if (
			isset( $GLOBALS['wp_locale'] )
			&& is_object( $GLOBALS['wp_locale'] )
			&& isset( $GLOBALS['wp_locale']->number_format )
			&& is_array( $GLOBALS['wp_locale']->number_format )
		) {
			return array(
				'hasWpLocale'        => true,
				'decimalPoint'       => $GLOBALS['wp_locale']->number_format['decimal_point'] ?? '.',
				'thousandsSeparator' => $GLOBALS['wp_locale']->number_format['thousands_sep'] ?? ',',
			);
		}

		return array(
			'hasWpLocale'        => false,
			'decimalPoint'       => '.',
			'thousandsSeparator' => ',',
		);
	}

	private static function install_noop_domain( string $domain ): void {
		if ( ! isset( $GLOBALS['l10n'] ) || ! is_array( $GLOBALS['l10n'] ) ) {
			$GLOBALS['l10n'] = array();
		}

		$GLOBALS['l10n'][ $domain ] = new \NOOP_Translations();
	}

	private static function install_mo_domain( string $domain, array $entries ): void {
		if ( ! isset( $GLOBALS['l10n'] ) || ! is_array( $GLOBALS['l10n'] ) ) {
			$GLOBALS['l10n'] = array();
		}

		$mo = new \MO();
		$mo->set_headers(
			array(
				'Content-Type' => 'text/plain; charset=UTF-8',
				'Plural-Forms' => 'nplurals=2; plural=(n != 1);',
			)
		);

		foreach ( $entries as $entry ) {
			$mo->add_entry( new \Translation_Entry( $entry ) );
		}

		$GLOBALS['l10n'][ $domain ] = $mo;
	}

	private static function ensure_runtime_globals(): void {
		if ( ! isset( $GLOBALS['l10n'] ) || ! is_array( $GLOBALS['l10n'] ) ) {
			$GLOBALS['l10n'] = array();
		}

		if ( ! isset( $GLOBALS['l10n_unloaded'] ) || ! is_array( $GLOBALS['l10n_unloaded'] ) ) {
			$GLOBALS['l10n_unloaded'] = array();
		}

		if ( class_exists( 'WP_Textdomain_Registry' ) && ! isset( $GLOBALS['wp_textdomain_registry'] ) ) {
			$GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();
			$GLOBALS['wp_textdomain_registry']->init();
		}

		if ( class_exists( 'WP_Locale' ) && ! isset( $GLOBALS['wp_locale'] ) ) {
			$GLOBALS['wp_locale'] = new \WP_Locale();
		}
	}

	private static function unique_domain( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'component-fuzz-l10n-' . preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $label ) ) . '-' . substr( sha1( $ctx->seed() . ':' . $ctx->iteration() . ':' . $label ), 0, 12 );
	}

	private static function locale_switcher( string $original_locale, array $available_languages ): \WP_Locale_Switcher {
		$switcher   = new \WP_Locale_Switcher();
		$reflection = new \ReflectionObject( $switcher );

		foreach (
			array(
				'original_locale'     => $original_locale,
				'available_languages' => array_values( array_unique( $available_languages ) ),
			) as $property_name => $value
		) {
			if ( ! $reflection->hasProperty( $property_name ) ) {
				continue;
			}
			$property = $reflection->getProperty( $property_name );
			$property->setValue( $switcher, $value );
		}

		return $switcher;
	}

	private static function fake_wp_user( int $user_id, string $locale ): \WP_User {
		$reflection = new \ReflectionClass( 'WP_User' );
		$user       = $reflection->newInstanceWithoutConstructor();
		$user->ID   = $user_id;
		$user->data = (object) array(
			'ID'     => $user_id,
			'locale' => $locale,
		);
		$user->locale = $locale;

		return $user;
	}

	private static function registry_custom_path( string $domain ): ?string {
		if ( ! isset( $GLOBALS['wp_textdomain_registry'] ) || ! $GLOBALS['wp_textdomain_registry'] instanceof \WP_Textdomain_Registry ) {
			return null;
		}

		$reflection = new \ReflectionObject( $GLOBALS['wp_textdomain_registry'] );
		if ( ! $reflection->hasProperty( 'custom_paths' ) ) {
			return null;
		}

		$property = $reflection->getProperty( 'custom_paths' );
		$paths    = $property->getValue( $GLOBALS['wp_textdomain_registry'] );

		if ( ! is_array( $paths ) ) {
			return null;
		}

		return $paths[ $domain ] ?? null;
	}

	private static function generated_domain( \ComponentFuzz\FuzzContext $ctx ): string {
		$value = $ctx->weightedChoice(
			array(
				array( 2, 'default' ),
				array( 3, 'component-fuzz-l10n' ),
				array( 5, $ctx->identifier( 1, 24 ) ),
				array( 2, $ctx->text( 0, self::MAX_DOMAIN_BYTES ) ),
				array( 1, "domain\x00with-control" ),
			)
		);

		return self::bound_string( (string) $value, self::MAX_DOMAIN_BYTES );
	}

	private static function domain_for_index( \ComponentFuzz\FuzzContext $ctx, int $index ): string {
		$domains = array(
			'default',
			'component-fuzz-l10n',
			'messages',
			'admin',
			'emoji-domain',
			"context\x04domain",
			"null\x00domain",
			$ctx->identifier( 1, 20 ),
		);

		return self::bound_string( $domains[ $index % count( $domains ) ], self::MAX_DOMAIN_BYTES );
	}

	private static function context_for_index( \ComponentFuzz\FuzzContext $ctx, int $index ): string {
		$contexts = array(
			'button',
			'menu item',
			'post type',
			'verb',
			'noun',
			'emoji 😀 context',
			"control\x00context",
			$ctx->text( 0, self::MAX_CONTEXT_BYTES ),
		);

		return self::bound_string( $contexts[ $index % count( $contexts ) ], self::MAX_CONTEXT_BYTES );
	}

	private static function generated_locale( \ComponentFuzz\FuzzContext $ctx ): string {
		$language = strtolower( substr( preg_replace( '/[^A-Za-z]/', '', $ctx->identifier( 2, 8 ) ), 0, 2 ) );
		if ( strlen( $language ) < 2 ) {
			$language = 'en';
		}

		$region = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $ctx->identifier( 2, 8 ) ), 0, 2 ) );
		if ( strlen( $region ) < 2 ) {
			$region = 'US';
		}

		return $language . '_' . $region;
	}

	private static function bound_string( string $value, int $max_bytes ): string {
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}

		return substr( $value, 0, $max_bytes );
	}

	private static function sample_case( array &$samples, array $case ): void {
		if ( count( $samples ) >= 5 ) {
			return;
		}

		$samples[] = self::case_summary( $case );
	}

	private static function sample_plural_case( array &$samples, array $case, string $expected ): void {
		if ( count( $samples ) >= 5 ) {
			return;
		}

		$summary              = self::case_summary( $case );
		$summary['count']     = $case['count'];
		$summary['selection'] = $expected === $case['singular'] ? 'singular' : 'plural';
		$summary['expected']  = self::describe_value( $expected );
		$samples[]            = $summary;
	}

	private static function case_summary( array $case ): array {
		$summary = array();

		foreach ( array( 'text', 'singular', 'plural', 'domain', 'context' ) as $key ) {
			if ( isset( $case[ $key ] ) && is_string( $case[ $key ] ) ) {
				$summary[ $key . 'Bytes' ]   = strlen( $case[ $key ] );
				$summary[ $key . 'Sha1' ]    = sha1( $case[ $key ] );
				$summary[ $key . 'Preview' ] = self::preview_string( $case[ $key ] );
			}
		}

		return $summary;
	}

	private static function add_failure( array &$failures, string $api, array $case, $expected, $actual ): void {
		if ( count( $failures ) >= self::FAILURE_LIMIT ) {
			return;
		}

		$failures[] = array(
			'api'      => $api,
			'case'     => self::case_summary( $case ),
			'expected' => self::describe_value( $expected ),
			'actual'   => self::describe_value( $actual ),
		);
	}

	private static function capture_echo( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return array(
				'type'    => 'string',
				'bytes'   => strlen( $value ),
				'sha1'    => sha1( $value ),
				'preview' => self::preview_string( $value ),
			);
		}

		if ( is_array( $value ) ) {
			return array(
				'type'  => 'array',
				'count' => count( $value ),
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

	private static function preview_string( string $value, int $limit = 120 ): string {
		$out    = '';
		$length = strlen( $value );

		for ( $i = 0; $i < $length; ++$i ) {
			$byte = ord( $value[ $i ] );
			if ( 0x20 <= $byte && $byte <= 0x7e ) {
				$out .= $value[ $i ];
			} elseif ( 0x0a === $byte ) {
				$out .= '\n';
			} elseif ( 0x09 === $byte ) {
				$out .= '\t';
			} elseif ( 0x0d === $byte ) {
				$out .= '\r';
			} else {
				$out .= sprintf( '\x%02X', $byte );
			}

			if ( strlen( $out ) >= $limit ) {
				return substr( $out, 0, $limit ) . '...';
			}
		}

		return $out;
	}

	private static function temp_mo_path( \ComponentFuzz\FuzzContext $ctx, string $suffix = '' ): string {
		$suffix = '' === $suffix ? '' : '-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $suffix );
		return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'component-fuzz-l10n-' . $ctx->seed() . '-' . $ctx->iteration() . $suffix . '.mo';
	}

	private static function missing_functions( array $functions ): array {
		$missing = array();
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = $function;
			}
		}

		return $missing;
	}

	private static function missing_classes( array $classes ): array {
		$missing = array();
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = $class;
			}
		}

		return $missing;
	}

	private static function throwable_row( \ComponentFuzz\FuzzContext $ctx, string $invariant, \Throwable $e, array $data = array() ): array {
		return $ctx->fail(
			$invariant,
			$data + array(
				'throwable' => get_class( $e ),
				'message'   => self::preview_string( $e->getMessage() ),
				'file'      => $e->getFile(),
				'line'      => $e->getLine(),
			)
		);
	}

	private static function clear_filters( array $tags ): void {
		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return;
		}

		foreach ( $tags as $tag ) {
			unset( $GLOBALS['wp_filter'][ $tag ] );
		}
	}

	private static function snapshot_global_state(): array {
		$names    = array(
			'l10n',
			'l10n_unloaded',
			'locale',
			'wp_local_package',
			'wp_locale',
			'wp_locale_switcher',
			'wp_textdomain_registry',
			'text_direction',
			'wp_filter',
			'wp_actions',
			'wp_filters',
			'wp_current_filter',
			'wp_scripts',
			'pagenow',
			'current_screen',
		);
		$snapshot = array(
			'globals'                => array(),
			'_GET'                   => self::snapshot_value( $_GET ),
			'_COOKIE'                => self::snapshot_value( $_COOKIE ),
			'_REQUEST'               => self::snapshot_value( $_REQUEST ),
			'translation_controller' => self::snapshot_translation_controller(),
		);

		foreach ( $names as $name ) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::snapshot_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_global_state( array $snapshot ): void {
		$_GET     = self::snapshot_value( $snapshot['_GET'] );
		$_COOKIE  = self::snapshot_value( $snapshot['_COOKIE'] );
		$_REQUEST = self::snapshot_value( $snapshot['_REQUEST'] );

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( ! empty( $entry['exists'] ) ) {
				$GLOBALS[ $name ] = self::snapshot_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		self::restore_translation_controller( $snapshot['translation_controller'] );
	}

	private static function snapshot_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::snapshot_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) && ! $value instanceof \Closure ) {
			return clone $value;
		}

		return $value;
	}

	private static function snapshot_translation_controller(): ?array {
		if ( ! class_exists( 'WP_Translation_Controller' ) ) {
			return null;
		}

		$reflection = new \ReflectionClass( 'WP_Translation_Controller' );
		if ( ! $reflection->hasProperty( 'instance' ) ) {
			return null;
		}

		$instance_property = $reflection->getProperty( 'instance' );
		$instance = $instance_property->getValue();

		$snapshot = array(
			'instanceExists' => null !== $instance,
			'instance'       => $instance,
			'properties'     => array(),
		);

		if ( null === $instance ) {
			return $snapshot;
		}

		$object_reflection = new \ReflectionObject( $instance );
		foreach ( array( 'current_locale', 'loaded_translations', 'loaded_files' ) as $property_name ) {
			if ( ! $object_reflection->hasProperty( $property_name ) ) {
				continue;
			}

			$property = $object_reflection->getProperty( $property_name );
			$snapshot['properties'][ $property_name ] = self::snapshot_value( $property->getValue( $instance ) );
		}

		return $snapshot;
	}

	private static function restore_translation_controller( ?array $snapshot ): void {
		if ( null === $snapshot || ! class_exists( 'WP_Translation_Controller' ) ) {
			return;
		}

		$reflection = new \ReflectionClass( 'WP_Translation_Controller' );
		if ( ! $reflection->hasProperty( 'instance' ) ) {
			return;
		}

		$instance_property = $reflection->getProperty( 'instance' );

		if ( empty( $snapshot['instanceExists'] ) ) {
			$instance_property->setValue( null, null );
			return;
		}

		$instance = $snapshot['instance'];
		$object_reflection = new \ReflectionObject( $instance );
		foreach ( $snapshot['properties'] as $property_name => $value ) {
			if ( ! $object_reflection->hasProperty( $property_name ) ) {
				continue;
			}

			$property = $object_reflection->getProperty( $property_name );
			$property->setValue( $instance, self::snapshot_value( $value ) );
		}

		$instance_property->setValue( null, $instance );
	}
}
