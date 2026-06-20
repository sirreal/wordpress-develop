<?php
namespace ComponentFuzz\Surfaces;

final class L10nSurface {
	public const NAME = 'l10n';

	private const GENERATED_STRING_CASES = 12;
	private const GENERATED_PLURAL_CASES = 10;
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
				'_x',
				'_n',
				'_nx',
				'_n_noop',
				'_nx_noop',
				'translate_nooped_plural',
				'esc_html__',
				'esc_attr__',
				'esc_html',
				'esc_attr',
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
				self::check_singular_fallbacks( $ctx, $string_cases ),
				self::check_escaping_helpers( $ctx, $string_cases ),
				self::check_plural_fallbacks( $ctx, $plural_cases ),
				self::check_number_format_i18n( $ctx ),
				self::check_wp_date( $ctx ),
				self::check_locale_behaviour( $ctx ),
				self::check_textdomain_loading( $ctx ),
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
			)
		);

		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $case ) {
				self::install_noop_domain( $case['domain'] );

				$translated = \translate( $case['text'], $case['domain'] );
				$html       = \esc_html__( $case['text'], $case['domain'] );
				$attr       = \esc_attr__( $case['text'], $case['domain'] );

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

	private static function check_textdomain_loading( \ComponentFuzz\FuzzContext $ctx ): array {
		$snapshot = self::snapshot_global_state();
		$path     = self::temp_mo_path( $ctx );
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
				)
			);

			if ( ! $exported ) {
				return $ctx->skip(
					'l10n.textdomain-load-unload-restores-globals',
					'Could not write a temporary MO file.',
					array(
						'pathPreview' => self::preview_string( $path ),
					)
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

			$expected_loaded = array(
				'translate'               => $translation,
				'__'                      => $translation,
				'_x'                      => $context_translation,
				'esc_html__'              => \esc_html( $translation ),
				'esc_attr__'              => \esc_attr( $translation ),
				'_n:1'                    => $single_translation,
				'_n:2'                    => $plural_translation,
				'translate_nooped_plural' => $plural_translation,
			);
			$actual_loaded   = array(
				'translate'               => \translate( $source, $domain ),
				'__'                      => \__( $source, $domain ),
				'_x'                      => \_x( $context_source, $context, $domain ),
				'esc_html__'              => \esc_html__( $source, $domain ),
				'esc_attr__'              => \esc_attr__( $source, $domain ),
				'_n:1'                    => \_n( $single, $plural, 1, $domain ),
				'_n:2'                    => \_n( $single, $plural, 2, $domain ),
				'translate_nooped_plural' => \translate_nooped_plural( \_n_noop( $single, $plural, $domain ), 2 ),
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
				'failures'          => array_slice( $failures, 0, self::FAILURE_LIMIT ),
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

	private static function temp_mo_path( \ComponentFuzz\FuzzContext $ctx ): string {
		return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'component-fuzz-l10n-' . $ctx->seed() . '-' . $ctx->iteration() . '.mo';
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
