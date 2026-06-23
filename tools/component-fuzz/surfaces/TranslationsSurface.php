<?php
namespace ComponentFuzz\Surfaces;

final class TranslationsSurface {
	public const NAME = 'translations';

	private const TEXT_CASES      = 12;
	private const ENTRY_CASES     = 8;
	private const FILE_CASES      = 4;
	private const FAILURE_LIMIT   = 8;
	private const MAX_TEXT_BYTES  = 144;
	private const MAX_DOMAIN_LEN  = 48;
	private const MAX_CONTEXT_LEN = 80;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_optional_po();

		$missing_functions = self::missing_functions(
			array(
				'__',
				'_n',
				'_n_noop',
				'_nx',
				'_nx_noop',
				'_x',
				'esc_attr',
				'esc_attr__',
				'esc_attr_x',
				'esc_html',
				'esc_html__',
				'esc_html_x',
				'get_translations_for_domain',
				'is_textdomain_loaded',
				'load_textdomain',
				'translate',
				'translate_nooped_plural',
				'unload_textdomain',
			)
		);
		$missing_classes   = self::missing_classes(
			array(
				'Gettext_Translations',
				'MO',
				'NOOP_Translations',
				'Translation_Entry',
				'Translations',
				'WP_Textdomain_Registry',
				'WP_Translation_Controller',
				'WP_Translation_File',
				'WP_Translation_File_MO',
				'WP_Translation_File_PHP',
				'WP_Translations',
			)
		);

		if ( $missing_functions || $missing_classes ) {
			return array(
				$ctx->skip(
					'translations.required-apis-available',
					'Required WordPress translation APIs are unavailable.',
					array(
						'missingFunctions' => $missing_functions,
						'missingClasses'   => $missing_classes,
						'poAvailable'      => class_exists( 'PO' ),
					)
				),
			);
		}

		$snapshot = self::snapshot_global_state();

		try {
			self::ensure_runtime_globals();

			return array(
				self::check_noop_identity( $ctx->fork( 'noop' ) ),
				self::check_translation_entry_lookup( $ctx->fork( 'entries' ) ),
				self::check_gettext_plural_rules( $ctx->fork( 'plural-rules' ) ),
				self::check_pomo_file_round_trips( $ctx->fork( 'pomo-files' ) ),
				self::check_load_textdomain_paths( $ctx->fork( 'load-textdomain' ) ),
				self::check_malformed_files_fail_closed( $ctx->fork( 'malformed-files' ) ),
			);
		} finally {
			self::restore_global_state( $snapshot );
		}
	}

	private static function check_noop_identity( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases    = self::text_cases( $ctx );
		$noop     = new \NOOP_Translations();
		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $index => $case ) {
				$count           = $case['count'];
				$expected_plural = 1 === (int) $count ? $case['singular'] : $case['plural'];
				$entry           = new \Translation_Entry(
					array(
						'singular'     => $case['singular'],
						'plural'       => $case['plural'],
						'context'      => $case['context'],
						'translations' => array( $case['translation'], $case['pluralTranslation'] ),
					)
				);

				$actuals = array(
					'add_entry'              => $noop->add_entry( $entry ),
					'get_header'             => $noop->get_header( 'Plural-Forms' ),
					'translate'              => $noop->translate( $case['singular'], $case['context'] ),
					'translate_plural'       => $noop->translate_plural( $case['singular'], $case['plural'], $count, $case['context'] ),
					'translate_entry'        => $noop->translate_entry( $entry ),
					'select_plural_form'     => $noop->select_plural_form( $count ),
					'get_plural_forms_count' => $noop->get_plural_forms_count(),
				);
				$expected_plural_index = 1 === (int) $count ? 0 : 1;

				self::sample_case( $samples, $case );

				self::collect_failure(
					$failures,
					true === $actuals['add_entry'],
					"NOOP_Translations add_entry accepts entry case {$index}",
					array( 'actual' => $actuals['add_entry'] )
				);
				self::collect_failure(
					$failures,
					false === $actuals['get_header'],
					"NOOP_Translations get_header returns false case {$index}",
					array( 'actual' => self::describe_value( $actuals['get_header'] ) )
				);
				self::collect_failure(
					$failures,
					$case['singular'] === $actuals['translate'],
					"NOOP_Translations translate returns input case {$index}",
					array(
						'expected' => self::describe_value( $case['singular'] ),
						'actual'   => self::describe_value( $actuals['translate'] ),
					)
				);
				self::collect_failure(
					$failures,
					$expected_plural === $actuals['translate_plural'],
					"NOOP_Translations translate_plural returns English fallback case {$index}",
					array(
						'count'    => $count,
						'expected' => self::describe_value( $expected_plural ),
						'actual'   => self::describe_value( $actuals['translate_plural'] ),
					)
				);
				self::collect_failure(
					$failures,
					false === $actuals['translate_entry'],
					"NOOP_Translations translate_entry returns false case {$index}",
					array( 'actual' => self::describe_value( $actuals['translate_entry'] ) )
				);
				self::collect_failure(
					$failures,
					$expected_plural_index === $actuals['select_plural_form'],
					"NOOP_Translations select_plural_form uses English rule case {$index}",
					array(
						'count'    => $count,
						'expected' => $expected_plural_index,
						'actual'   => $actuals['select_plural_form'],
					)
				);
				self::collect_failure(
					$failures,
					2 === $actuals['get_plural_forms_count'],
					"NOOP_Translations plural form count is two case {$index}",
					array( 'actual' => $actuals['get_plural_forms_count'] )
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'translations.noop-identity', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'translations.noop-identity',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_translation_entry_lookup( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases    = self::entry_cases( $ctx );
		$failures = array();
		$samples  = array();

		try {
			foreach ( $cases as $index => $case ) {
				$translations = new \Translations();

				$empty_added = $translations->add_entry( new \Translation_Entry() );
				self::collect_failure(
					$failures,
					false === $empty_added,
					"Translations rejects empty entry case {$index}",
					array( 'actual' => $empty_added )
				);

				$base_entry = new \Translation_Entry(
					array(
						'singular'     => $case['source'],
						'translations' => array( $case['translation'] ),
						'references'   => array( 'base.php:1' ),
					)
				);
				$context_entry_a = new \Translation_Entry(
					array(
						'singular'     => $case['source'],
						'context'      => $case['contextA'],
						'translations' => array( $case['contextTranslationA'] ),
						'flags'        => array( 'php-format' ),
						'references'   => array( 'context-a.php:7' ),
					)
				);
				$context_entry_b = new \Translation_Entry(
					array(
						'singular'     => $case['source'],
						'context'      => $case['contextB'],
						'translations' => array( $case['contextTranslationB'] ),
					)
				);
				$plural_entry = new \Translation_Entry(
					array(
						'singular'     => $case['pluralSingular'],
						'plural'       => $case['pluralPlural'],
						'context'      => $case['contextA'],
						'translations' => $case['pluralTranslations'],
					)
				);
				$merge_entry = new \Translation_Entry(
					array(
						'singular'            => $case['source'],
						'context'             => $case['contextA'],
						'translations'        => array( $case['translation'] . '-should-not-overwrite' ),
						'flags'               => array( 'fuzzy' ),
						'references'          => array( 'merged.php:11' ),
						'extracted_comments'  => 'merged-comment',
						'translator_comments' => 'merged-translator-comment',
					)
				);

				$translations->add_entry( $base_entry );
				$translations->add_entry( $context_entry_a );
				$translations->add_entry( $context_entry_b );
				$translations->add_entry( $plural_entry );
				$translations->add_entry_or_merge( $merge_entry );

				$lookup_entry = new \Translation_Entry(
					array(
						'singular' => $case['source'],
						'context'  => $case['contextA'],
					)
				);
				$found        = $translations->translate_entry( $lookup_entry );
				$missing      = $translations->translate( $case['source'], $case['missingContext'] );

				self::sample_case( $samples, $case );

				$actuals = array(
					'translate:no-context'    => $translations->translate( $case['source'] ),
					'translate:context-a'     => $translations->translate( $case['source'], $case['contextA'] ),
					'translate:context-b'     => $translations->translate( $case['source'], $case['contextB'] ),
					'translate:missing'       => $missing,
					'plural:0'                => $translations->translate_plural( $case['pluralSingular'], $case['pluralPlural'], 0, $case['contextA'] ),
					'plural:1'                => $translations->translate_plural( $case['pluralSingular'], $case['pluralPlural'], 1, $case['contextA'] ),
					'plural:2'                => $translations->translate_plural( $case['pluralSingular'], $case['pluralPlural'], 2, $case['contextA'] ),
					'plural:missing-context'  => $translations->translate_plural( $case['pluralSingular'], $case['pluralPlural'], 2, $case['missingContext'] ),
					'key:no-context'          => $base_entry->key(),
					'key:context-a'           => $context_entry_a->key(),
					'key:context-b'           => $context_entry_b->key(),
					'key:duplicate-context-a' => $merge_entry->key(),
				);
				$expecteds = array(
					'translate:no-context'   => $case['translation'],
					'translate:context-a'    => $case['contextTranslationA'],
					'translate:context-b'    => $case['contextTranslationB'],
					'translate:missing'      => $case['source'],
					'plural:0'               => $case['pluralTranslations'][1],
					'plural:1'               => $case['pluralTranslations'][0],
					'plural:2'               => $case['pluralTranslations'][1],
					'plural:missing-context' => $case['pluralPlural'],
				);

				foreach ( $expecteds as $api => $expected ) {
					self::collect_failure(
						$failures,
						$actuals[ $api ] === $expected,
						"Translations lookup {$api} case {$index}",
						array(
							'expected' => self::describe_value( $expected ),
							'actual'   => self::describe_value( $actuals[ $api ] ),
						)
					);
				}

				self::collect_failure(
					$failures,
					$actuals['key:no-context'] !== $actuals['key:context-a']
						&& $actuals['key:context-a'] !== $actuals['key:context-b']
						&& $actuals['key:context-a'] === $actuals['key:duplicate-context-a'],
					"Translation_Entry keys preserve context distinction case {$index}",
					array(
						'keys' => array(
							'noContext'         => self::describe_value( $actuals['key:no-context'] ),
							'contextA'          => self::describe_value( $actuals['key:context-a'] ),
							'contextB'          => self::describe_value( $actuals['key:context-b'] ),
							'duplicateContextA' => self::describe_value( $actuals['key:duplicate-context-a'] ),
						),
					)
				);

				self::collect_failure(
					$failures,
					$found instanceof \Translation_Entry
						&& $found->translations[0] === $case['contextTranslationA']
						&& in_array( 'php-format', $found->flags, true )
						&& in_array( 'fuzzy', $found->flags, true )
						&& in_array( 'context-a.php:7', $found->references, true )
						&& in_array( 'merged.php:11', $found->references, true )
						&& str_contains( $found->extracted_comments, 'merged-comment' ),
					"Translations add_entry_or_merge preserves translation and merges metadata case {$index}",
					array(
						'found' => self::describe_value( $found ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'translations.entry-lookup-merge', $e, array( 'cases' => count( $cases ) ) );
		}

		return $ctx->result(
			'translations.entry-lookup-merge',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_gettext_plural_rules( \ComponentFuzz\FuzzContext $ctx ): array {
		$rules    = self::plural_rule_cases( $ctx );
		$failures = array();
		$samples  = array();

		try {
			foreach ( $rules as $index => $rule ) {
				$gettext = new \Gettext_Translations();
				$gettext->set_header( 'Plural-Forms', $rule['header'] );
				$mo = new \MO();
				$mo->set_header( 'Plural-Forms', $rule['header'] );

				$singular     = 'plural-rule-' . $index . '-one';
				$plural       = 'plural-rule-' . $index . '-many';
				$translations = array(
					'rule-' . $index . '-form-zero',
					'rule-' . $index . '-form-one',
				);
				$mo->add_entry(
					new \Translation_Entry(
						array(
							'singular'     => $singular,
							'plural'       => $plural,
							'translations' => $translations,
						)
					)
				);

				$samples[] = array(
					'name'   => $rule['name'],
					'header' => $rule['header'],
				);

				foreach ( array( 0, 1, 2 ) as $count ) {
					$expected_index = $rule['expected'][ $count ];
					$gettext_index  = $gettext->gettext_select_plural_form( $count );
					$mo_index       = $mo->select_plural_form( $count );
					$actual_text    = $mo->translate_plural( $singular, $plural, $count );
					$expected_text  = $translations[ $expected_index ];

					self::collect_failure(
						$failures,
						$gettext_index === $expected_index,
						"Gettext_Translations plural parser rule {$rule['name']} count {$count}",
						array(
							'header'   => $rule['header'],
							'count'    => $count,
							'expected' => $expected_index,
							'actual'   => $gettext_index,
						)
					);
					self::collect_failure(
						$failures,
						$mo_index === $expected_index,
						"MO plural index rule {$rule['name']} count {$count}",
						array(
							'header'   => $rule['header'],
							'count'    => $count,
							'expected' => $expected_index,
							'actual'   => $mo_index,
						)
					);
					self::collect_failure(
						$failures,
						$actual_text === $expected_text,
						"MO plural translation rule {$rule['name']} count {$count}",
						array(
							'header'   => $rule['header'],
							'count'    => $count,
							'expected' => self::describe_value( $expected_text ),
							'actual'   => self::describe_value( $actual_text ),
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'translations.gettext-plural-rules', $e, array( 'rules' => count( $rules ) ) );
		}

		return $ctx->result(
			'translations.gettext-plural-rules',
			array() === $failures,
			array(
				'rules'    => count( $rules ),
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_pomo_file_round_trips( \ComponentFuzz\FuzzContext $ctx ): array {
		$temp_dir      = self::make_temp_dir( $ctx, 'round-trip' );
		$cases         = self::file_cases( $ctx );
		$failures      = array();
		$samples       = array();
		$po_available  = self::load_optional_po();
		$po_roundtrips = 0;

		try {
			foreach ( $cases as $index => $case ) {
				$mo_path = $temp_dir . DIRECTORY_SEPARATOR . 'case-' . $index . '.mo';
				$po_path = $temp_dir . DIRECTORY_SEPARATOR . 'case-' . $index . '.po';

				$mo_written = self::write_mo_file( $mo_path, $case );
				self::collect_failure(
					$failures,
					true === $mo_written && is_readable( $mo_path ),
					"POMO MO export writes readable file case {$index}",
					array( 'path' => self::preview_path( $mo_path ) )
				);

				if ( $mo_written ) {
					$imported_mo = new \MO();
					$mo_imported = $imported_mo->import_from_file( $mo_path );

					self::collect_failure(
						$failures,
						true === $mo_imported,
						"POMO MO import succeeds case {$index}",
						array( 'path' => self::preview_path( $mo_path ) )
					);
					if ( $mo_imported ) {
						self::assert_gettext_translations( $failures, $imported_mo, $case, "POMO MO case {$index}" );
					}

					$modern_mo = \WP_Translation_File::create( $mo_path );
					self::collect_failure(
						$failures,
						$modern_mo instanceof \WP_Translation_File,
						"WP_Translation_File creates MO parser case {$index}",
						array( 'actual' => self::describe_value( $modern_mo ) )
					);
					if ( $modern_mo instanceof \WP_Translation_File ) {
						self::assert_modern_translation_file( $failures, $modern_mo, $case, "modern MO case {$index}" );

						$php_contents = \WP_Translation_File::transform( $mo_path, 'php' );
						self::collect_failure(
							$failures,
							is_string( $php_contents ) && str_starts_with( $php_contents, '<?php' ),
							"WP_Translation_File transforms MO to PHP case {$index}",
							array( 'actual' => self::describe_value( $php_contents ) )
						);
						if ( is_string( $php_contents ) ) {
							$php_path = $temp_dir . DIRECTORY_SEPARATOR . 'case-' . $index . '.l10n.php';
							file_put_contents( $php_path, $php_contents );
							$modern_php = \WP_Translation_File::create( $php_path );
							if ( $modern_php instanceof \WP_Translation_File ) {
								self::assert_modern_translation_file( $failures, $modern_php, $case, "transformed PHP case {$index}" );
							} else {
								self::collect_failure(
									$failures,
									false,
									"WP_Translation_File creates transformed PHP parser case {$index}",
									array( 'actual' => self::describe_value( $modern_php ) )
								);
							}
						}
					}
				}

				if ( $po_available ) {
					$po_written = self::write_po_file( $po_path, $case );
					self::collect_failure(
						$failures,
						true === $po_written && is_readable( $po_path ),
						"POMO PO export writes readable file case {$index}",
						array( 'path' => self::preview_path( $po_path ) )
					);

					if ( $po_written ) {
						$imported_po = new \PO();
						$po_imported = $imported_po->import_from_file( $po_path );
						++$po_roundtrips;

						self::collect_failure(
							$failures,
							true === $po_imported,
							"POMO PO import succeeds case {$index}",
							array( 'path' => self::preview_path( $po_path ) )
						);
						if ( $po_imported ) {
							self::assert_gettext_translations( $failures, $imported_po, $case, "POMO PO case {$index}", false );
						}
					}
				}

				self::sample_file_case( $samples, $case );
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'translations.pomo-file-round-trips', $e, array( 'cases' => count( $cases ) ) );
		} finally {
			self::remove_temp_dir( $temp_dir );
		}

		return $ctx->result(
			'translations.pomo-file-round-trips',
			array() === $failures,
			array(
				'cases'         => count( $cases ),
				'poAvailable'   => $po_available,
				'poRoundTrips'  => $po_roundtrips,
				'samples'       => $samples,
				'failures'      => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_load_textdomain_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$temp_dir     = self::make_temp_dir( $ctx, 'load' );
		$snapshot     = self::snapshot_global_state();
		$restored     = false;
		$failures     = array();
		$locale       = 'en_US';
		$case         = self::file_cases( $ctx )[0];
		$domain_mo    = self::generated_safe_domain( $ctx, 'mo' );
		$domain_php   = self::generated_safe_domain( $ctx, 'php' );
		$domain_empty = self::generated_safe_domain( $ctx, 'missing' );
		$domains      = array( $domain_mo, $domain_php, $domain_empty );
		$before_state = self::domain_state_for( $domains, $locale );

		try {
			self::ensure_runtime_globals();
			self::clear_l10n_filters( $domains );

			$mo_path = $temp_dir . DIRECTORY_SEPARATOR . $domain_mo . '-' . $locale . '.mo';
			if ( ! self::write_mo_file( $mo_path, $case ) ) {
				return $ctx->skip(
					'translations.load-textdomain-file-paths',
					'Could not write temporary MO file.',
					array( 'path' => self::preview_path( $mo_path ) )
				);
			}

			$php_mofile = $temp_dir . DIRECTORY_SEPARATOR . $domain_php . '-' . $locale . '.mo';
			$php_path   = substr_replace( $php_mofile, '.l10n.php', - strlen( '.mo' ) );
			if ( ! self::write_php_translation_file( $php_path, $case, $locale ) ) {
				return $ctx->skip(
					'translations.load-textdomain-file-paths',
					'Could not write temporary PHP translation file.',
					array( 'path' => self::preview_path( $php_path ) )
				);
			}

			$missing_loaded = \load_textdomain( $domain_empty, $temp_dir . DIRECTORY_SEPARATOR . 'does-not-exist.mo', $locale );
			self::collect_failure(
				$failures,
				false === $missing_loaded && ! \is_textdomain_loaded( $domain_empty ),
				'load_textdomain missing file does not expose domain',
				array(
					'loaded' => $missing_loaded,
					'state'  => self::domain_state_for( array( $domain_empty ), $locale ),
				)
			);

			foreach (
				array(
					'mo'  => array( $domain_mo, $mo_path ),
					'php' => array( $domain_php, $php_mofile ),
				) as $format => $load_case
			) {
				list( $domain, $path ) = $load_case;

				for ( $cycle = 0; $cycle < 2; ++$cycle ) {
					self::collect_failure(
						$failures,
						! \is_textdomain_loaded( $domain ),
						"load_textdomain {$format} starts unloaded cycle {$cycle}",
						array( 'state' => self::domain_state_for( array( $domain ), $locale ) )
					);

					$first_load  = \load_textdomain( $domain, $path, $locale );
					$second_load = \load_textdomain( $domain, $path, $locale );

					self::collect_failure(
						$failures,
						true === $first_load && true === $second_load && \is_textdomain_loaded( $domain ),
						"load_textdomain {$format} repeated loads are stable cycle {$cycle}",
						array(
							'firstLoad'  => $first_load,
							'secondLoad' => $second_load,
							'state'      => self::domain_state_for( array( $domain ), $locale ),
						)
					);

					$other_domain = $domain === $domain_mo ? $domain_php : $domain_mo;
					self::collect_failure(
						$failures,
						! \is_textdomain_loaded( $other_domain ) && ! \is_textdomain_loaded( $domain_empty ),
						"load_textdomain {$format} exposes only requested domain cycle {$cycle}",
						array( 'state' => self::domain_state_for( $domains, $locale ) )
					);

					self::assert_loaded_domain_helpers( $failures, $domain, $case, $format . " cycle {$cycle}" );

					$first_unload  = \unload_textdomain( $domain );
					$second_unload = \unload_textdomain( $domain );

					self::collect_failure(
						$failures,
						true === $first_unload && false === $second_unload && ! \is_textdomain_loaded( $domain ),
						"unload_textdomain {$format} repeated unloads settle unloaded cycle {$cycle}",
						array(
							'firstUnload'  => $first_unload,
							'secondUnload' => $second_unload,
							'state'        => self::domain_state_for( array( $domain ), $locale ),
						)
					);
				}
			}

			self::restore_global_state( $snapshot );
			$restored    = true;
			$after_state = self::domain_state_for( $domains, $locale );

			self::collect_failure(
				$failures,
				$before_state === $after_state,
				'translations surface restores l10n globals, unloaded map, registry, and controller state',
				array(
					'before' => $before_state,
					'after'  => $after_state,
				)
			);
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'translations.load-textdomain-file-paths', $e, array( 'domains' => $domains ) );
		} finally {
			if ( ! $restored ) {
				self::restore_global_state( $snapshot );
			}
			self::remove_temp_dir( $temp_dir );
		}

		return $ctx->result(
			'translations.load-textdomain-file-paths',
			array() === $failures,
			array(
				'locale'   => $locale,
				'domains'  => $domains,
				'samples'  => array( self::file_case_summary( $case ) ),
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_malformed_files_fail_closed( \ComponentFuzz\FuzzContext $ctx ): array {
		$temp_dir     = self::make_temp_dir( $ctx, 'malformed' );
		$failures     = array();
		$po_available = self::load_optional_po();

		try {
			$short_mo = $temp_dir . DIRECTORY_SEPARATOR . 'short.mo';
			$bad_mo   = $temp_dir . DIRECTORY_SEPARATOR . 'bad-magic.mo';
			$bad_php  = $temp_dir . DIRECTORY_SEPARATOR . 'bad.l10n.php';
			$bad_po   = $temp_dir . DIRECTORY_SEPARATOR . 'bad.po';

			file_put_contents( $short_mo, $ctx->bytes( 1, 12 ) );
			file_put_contents( $bad_mo, str_repeat( 'x', 32 ) . $ctx->bytes( 0, 16 ) );
			file_put_contents( $bad_php, "<?php\nreturn 'not translation data';\n" );
			file_put_contents( $bad_po, "msgid \"missing-msgstr\"\n" );

			foreach ( array( $short_mo, $bad_mo ) as $path ) {
				$pomo_mo  = new \MO();
				$imported = $pomo_mo->import_from_file( $path );
				self::collect_failure(
					$failures,
					false === $imported && array() === $pomo_mo->entries,
					'Malformed POMO MO file returns false without entries',
					array(
						'path'     => self::preview_path( $path ),
						'imported' => $imported,
						'entries'  => count( $pomo_mo->entries ),
					)
				);

				$modern_mo = \WP_Translation_File::create( $path );
				self::collect_failure(
					$failures,
					$modern_mo instanceof \WP_Translation_File,
					'Malformed readable MO still creates lazy parser',
					array(
						'path'   => self::preview_path( $path ),
						'actual' => self::describe_value( $modern_mo ),
					)
				);
				if ( $modern_mo instanceof \WP_Translation_File ) {
					$entries     = $modern_mo->entries();
					$translation = $modern_mo->translate( 'missing-msgid' );
					self::collect_failure(
						$failures,
						array() === $entries && false === $translation && null !== $modern_mo->error(),
						'Malformed modern MO parser exposes no translations after parse',
						array(
							'path'        => self::preview_path( $path ),
							'entries'     => count( $entries ),
							'translation' => self::describe_value( $translation ),
							'error'       => $modern_mo->error(),
						)
					);
				}
			}

			$modern_php = \WP_Translation_File::create( $bad_php );
			self::collect_failure(
				$failures,
				$modern_php instanceof \WP_Translation_File,
				'Malformed readable PHP translation file creates lazy parser',
				array( 'actual' => self::describe_value( $modern_php ) )
			);
			if ( $modern_php instanceof \WP_Translation_File ) {
				$entries     = $modern_php->entries();
				$translation = $modern_php->translate( 'missing-msgid' );
				self::collect_failure(
					$failures,
					array() === $entries && false === $translation && null !== $modern_php->error(),
					'Malformed PHP translation parser exposes no translations after parse',
					array(
						'entries'     => count( $entries ),
						'translation' => self::describe_value( $translation ),
						'error'       => $modern_php->error(),
					)
				);
			}

			if ( $po_available ) {
				$po       = new \PO();
				$imported = $po->import_from_file( $bad_po );
				self::collect_failure(
					$failures,
					false === $imported && array() === $po->entries,
					'Malformed POMO PO file returns false without entries',
					array(
						'imported' => $imported,
						'entries'  => count( $po->entries ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_row( $ctx, 'translations.malformed-files-fail-closed', $e );
		} finally {
			self::remove_temp_dir( $temp_dir );
		}

		return $ctx->result(
			'translations.malformed-files-fail-closed',
			array() === $failures,
			array(
				'poAvailable' => $po_available,
				'failures'    => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function assert_gettext_translations( array &$failures, \Translations $translations, array $case, string $label, bool $honors_plural_header = true ): void {
		$checks = array(
			'translate'         => array( $translations->translate( $case['source'] ), $case['translation'] ),
			'translate-context' => array( $translations->translate( $case['contextSource'], $case['context'] ), $case['contextTranslation'] ),
		);

		foreach ( array( 0, 1, 2 ) as $count ) {
			$expected_index                             = $honors_plural_header ? $case['pluralRule']['expected'][ $count ] : ( 1 === $count ? 0 : 1 );
			$checks[ 'plural:' . $count ]              = array(
				$translations->translate_plural( $case['pluralSingular'], $case['pluralPlural'], $count ),
				$case['pluralTranslations'][ $expected_index ],
			);
			$checks[ 'plural-context:' . $count ]      = array(
				$translations->translate_plural( $case['contextPluralSingular'], $case['contextPluralPlural'], $count, $case['context'] ),
				$case['contextPluralTranslations'][ $expected_index ],
			);
			$checks[ 'select-plural-form:' . $count ]  = array(
				$translations->select_plural_form( $count ),
				$expected_index,
			);

			if ( $translations instanceof \Gettext_Translations ) {
				$checks[ 'gettext-select-plural-form:' . $count ] = array(
					$translations->gettext_select_plural_form( $count ),
					$case['pluralRule']['expected'][ $count ],
				);
			}
		}

		foreach ( $checks as $name => $pair ) {
			list( $actual, $expected ) = $pair;
			self::collect_failure(
				$failures,
				$actual === $expected,
				"{$label} {$name}",
				array(
					'expected' => self::describe_value( $expected ),
					'actual'   => self::describe_value( $actual ),
				)
			);
		}
	}

	private static function assert_modern_translation_file( array &$failures, \WP_Translation_File $file, array $case, string $label ): void {
		$headers = $file->headers();
		$entries = $file->entries();

		self::collect_failure(
			$failures,
			null === $file->error()
				&& isset( $headers['plural-forms'] )
				&& $headers['plural-forms'] === $case['pluralRule']['header'],
			"{$label} parses headers",
			array(
				'headers' => $headers,
				'error'   => $file->error(),
			)
		);

		foreach ( self::modern_file_messages( $case ) as $key => $expected ) {
			$actual = $file->translate( $key );
			self::collect_failure(
				$failures,
				$actual === $expected && isset( $entries[ $key ] ),
				"{$label} entry lookup",
				array(
					'key'      => self::describe_value( $key ),
					'expected' => self::describe_value( $expected ),
					'actual'   => self::describe_value( $actual ),
				)
			);
		}

		foreach ( array( 0, 1, 2 ) as $count ) {
			self::collect_failure(
				$failures,
				$file->get_plural_form( $count ) === $case['pluralRule']['expected'][ $count ],
				"{$label} plural form count {$count}",
				array(
					'expected' => $case['pluralRule']['expected'][ $count ],
					'actual'   => $file->get_plural_form( $count ),
				)
			);
		}
	}

	private static function assert_loaded_domain_helpers( array &$failures, string $domain, array $case, string $label ): void {
		$translations = \get_translations_for_domain( $domain );
		$actuals      = array(
			'translate'                       => \translate( $case['source'], $domain ),
			'__'                              => \__( $case['source'], $domain ),
			'get_translations_for_domain'     => $translations->translate( $case['source'] ),
			'_x'                              => \_x( $case['contextSource'], $case['context'], $domain ),
			'esc_html__'                      => \esc_html__( $case['source'], $domain ),
			'esc_attr__'                      => \esc_attr__( $case['source'], $domain ),
			'esc_html_x'                      => \esc_html_x( $case['contextSource'], $case['context'], $domain ),
			'esc_attr_x'                      => \esc_attr_x( $case['contextSource'], $case['context'], $domain ),
			'_n:0'                            => \_n( $case['pluralSingular'], $case['pluralPlural'], 0, $domain ),
			'_n:1'                            => \_n( $case['pluralSingular'], $case['pluralPlural'], 1, $domain ),
			'_n:2'                            => \_n( $case['pluralSingular'], $case['pluralPlural'], 2, $domain ),
			'_nx:0'                           => \_nx( $case['contextPluralSingular'], $case['contextPluralPlural'], 0, $case['context'], $domain ),
			'_nx:1'                           => \_nx( $case['contextPluralSingular'], $case['contextPluralPlural'], 1, $case['context'], $domain ),
			'_nx:2'                           => \_nx( $case['contextPluralSingular'], $case['contextPluralPlural'], 2, $case['context'], $domain ),
			'translate_nooped_plural'         => \translate_nooped_plural( \_n_noop( $case['pluralSingular'], $case['pluralPlural'], $domain ), 2 ),
			'translate_nooped_plural_context' => \translate_nooped_plural( \_nx_noop( $case['contextPluralSingular'], $case['contextPluralPlural'], $case['context'], $domain ), 2 ),
		);
		$expecteds    = array(
			'translate'                       => $case['translation'],
			'__'                              => $case['translation'],
			'get_translations_for_domain'     => $case['translation'],
			'_x'                              => $case['contextTranslation'],
			'esc_html__'                      => \esc_html( $case['translation'] ),
			'esc_attr__'                      => \esc_attr( $case['translation'] ),
			'esc_html_x'                      => \esc_html( $case['contextTranslation'] ),
			'esc_attr_x'                      => \esc_attr( $case['contextTranslation'] ),
			'_n:0'                            => $case['pluralTranslations'][ $case['pluralRule']['expected'][0] ],
			'_n:1'                            => $case['pluralTranslations'][ $case['pluralRule']['expected'][1] ],
			'_n:2'                            => $case['pluralTranslations'][ $case['pluralRule']['expected'][2] ],
			'_nx:0'                           => $case['contextPluralTranslations'][ $case['pluralRule']['expected'][0] ],
			'_nx:1'                           => $case['contextPluralTranslations'][ $case['pluralRule']['expected'][1] ],
			'_nx:2'                           => $case['contextPluralTranslations'][ $case['pluralRule']['expected'][2] ],
			'translate_nooped_plural'         => $case['pluralTranslations'][ $case['pluralRule']['expected'][2] ],
			'translate_nooped_plural_context' => $case['contextPluralTranslations'][ $case['pluralRule']['expected'][2] ],
		);

		self::collect_failure(
			$failures,
			$translations instanceof \WP_Translations,
			"{$label} get_translations_for_domain returns loaded translations object",
			array( 'actual' => self::describe_value( $translations ) )
		);

		foreach ( $expecteds as $api => $expected ) {
			self::collect_failure(
				$failures,
				$actuals[ $api ] === $expected,
				"{$label} helper {$api}",
				array(
					'expected' => self::describe_value( $expected ),
					'actual'   => self::describe_value( $actuals[ $api ] ),
				)
			);
		}
	}

	private static function text_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$corpus = array(
			'',
			'plain source',
			"line\nbreak\twith-tab",
			"control\x00nul\x1Fdel\x7F",
			"invalid utf8 \xC3\x28 tail",
			"overlong-ish \xF0\x28\x8C\x28",
			"snowman \xE2\x98\x83 and cafe \xC3\xA9",
			"arabic \xD9\x85\xD8\xB1\xD8\xAD\xD8\xA8\xD8\xA7",
			"chinese \xE4\xB8\xAD\xE6\x96\x87",
			'HTML <b>source</b> & "quotes"',
			'%s item',
			str_repeat( 'A', 96 ),
		);

		$cases = array();
		foreach ( $corpus as $index => $text ) {
			$singular = self::bound_string( $text, self::MAX_TEXT_BYTES );
			$plural   = self::bound_string( $text . ' plural ' . $index, self::MAX_TEXT_BYTES );
			if ( $plural === $singular ) {
				$plural .= '-p';
			}

			$cases[] = array(
				'singular'          => $singular,
				'plural'            => $plural,
				'context'           => self::bound_string( 'context-' . $index . '-' . $ctx->text( 0, 24 ), self::MAX_CONTEXT_LEN ),
				'translation'       => self::bound_string( 'translated-' . $index . '-' . $text, self::MAX_TEXT_BYTES ),
				'pluralTranslation' => self::bound_string( 'translated-plural-' . $index . '-' . $text, self::MAX_TEXT_BYTES ),
				'count'             => $ctx->choice( array( -1, 0, 1, 2, $ctx->int( 3, 20 ) ) ),
			);
		}

		for ( $i = count( $cases ); $i < self::TEXT_CASES + count( $corpus ); ++$i ) {
			$singular = self::bound_string( $ctx->text( 0, self::MAX_TEXT_BYTES ), self::MAX_TEXT_BYTES );
			$plural   = self::bound_string( $ctx->text( 0, self::MAX_TEXT_BYTES ), self::MAX_TEXT_BYTES );
			if ( $plural === $singular ) {
				$plural .= '-plural';
			}

			$cases[] = array(
				'singular'          => $singular,
				'plural'            => self::bound_string( $plural, self::MAX_TEXT_BYTES ),
				'context'           => self::bound_string( $ctx->text( 0, self::MAX_CONTEXT_LEN ), self::MAX_CONTEXT_LEN ),
				'translation'       => self::bound_string( 'tx-' . $i . '-' . $ctx->text( 0, 80 ), self::MAX_TEXT_BYTES ),
				'pluralTranslation' => self::bound_string( 'txp-' . $i . '-' . $ctx->text( 0, 80 ), self::MAX_TEXT_BYTES ),
				'count'             => $ctx->choice( array( -1, 0, 1, 2, $ctx->int( 3, 20 ) ) ),
			);
		}

		return $cases;
	}

	private static function entry_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::ENTRY_CASES; ++$i ) {
			$suffix = $i . '-' . $ctx->identifier( 4, 12 );
			$cases[] = array(
				'source'                 => self::safe_file_text( $ctx, 'entry-source-' . $suffix ),
				'translation'            => self::safe_file_text( $ctx, 'entry-translation-' . $suffix ),
				'contextA'               => self::safe_file_text( $ctx, 'context-a-' . $suffix ),
				'contextB'               => self::safe_file_text( $ctx, 'context-b-' . $suffix ),
				'missingContext'         => self::safe_file_text( $ctx, 'missing-context-' . $suffix ),
				'contextTranslationA'    => self::safe_file_text( $ctx, 'context-a-translation-' . $suffix ),
				'contextTranslationB'    => self::safe_file_text( $ctx, 'context-b-translation-' . $suffix ),
				'pluralSingular'         => self::safe_file_text( $ctx, 'plural-singular-' . $suffix ),
				'pluralPlural'           => self::safe_file_text( $ctx, 'plural-plural-' . $suffix ),
				'pluralTranslations'     => array(
					self::safe_file_text( $ctx, 'plural-zero-' . $suffix ),
					self::safe_file_text( $ctx, 'plural-one-' . $suffix ),
				),
			);
		}

		return $cases;
	}

	private static function file_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		$rules = self::plural_rule_cases( $ctx );
		for ( $i = 0; $i < self::FILE_CASES; ++$i ) {
			$suffix = $i . '-' . $ctx->identifier( 4, 12 );
			$cases[] = array(
				'source'                    => self::safe_file_text( $ctx, 'file-source-' . $suffix ),
				'translation'               => self::safe_file_text( $ctx, 'file-translation-' . $suffix . ' <>&"' ),
				'context'                   => self::safe_file_text( $ctx, 'file-context-' . $suffix ),
				'contextSource'             => self::safe_file_text( $ctx, 'file-context-source-' . $suffix ),
				'contextTranslation'        => self::safe_file_text( $ctx, 'file-context-translation-' . $suffix ),
				'pluralSingular'            => self::safe_file_text( $ctx, 'file-plural-one-' . $suffix ),
				'pluralPlural'              => self::safe_file_text( $ctx, 'file-plural-many-' . $suffix ),
				'pluralTranslations'        => array(
					self::safe_file_text( $ctx, 'file-plural-form-zero-' . $suffix ),
					self::safe_file_text( $ctx, 'file-plural-form-one-' . $suffix ),
				),
				'contextPluralSingular'     => self::safe_file_text( $ctx, 'file-context-plural-one-' . $suffix ),
				'contextPluralPlural'       => self::safe_file_text( $ctx, 'file-context-plural-many-' . $suffix ),
				'contextPluralTranslations' => array(
					self::safe_file_text( $ctx, 'file-context-plural-zero-' . $suffix ),
					self::safe_file_text( $ctx, 'file-context-plural-one-' . $suffix ),
				),
				'pluralRule'                => $rules[ $i % count( $rules ) ],
			);
		}

		return $cases;
	}

	private static function plural_rule_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$generated = $ctx->choice(
			array(
				array(
					'name'     => 'generated-not-one',
					'header'   => 'nplurals=2; plural=(n != 1);',
					'expected' => array( 0 => 1, 1 => 0, 2 => 1 ),
				),
				array(
					'name'     => 'generated-greater-than-one',
					'header'   => 'nplurals=2; plural=(n > 1);',
					'expected' => array( 0 => 0, 1 => 0, 2 => 1 ),
				),
			)
		);

		return array(
			array(
				'name'     => 'english-not-one',
				'header'   => 'nplurals=2; plural=(n != 1);',
				'expected' => array( 0 => 1, 1 => 0, 2 => 1 ),
			),
			array(
				'name'     => 'greater-than-one',
				'header'   => 'nplurals=2; plural=(n > 1);',
				'expected' => array( 0 => 0, 1 => 0, 2 => 1 ),
			),
			array(
				'name'     => 'always-zero',
				'header'   => 'nplurals=2; plural=0;',
				'expected' => array( 0 => 0, 1 => 0, 2 => 0 ),
			),
			$generated,
		);
	}

	private static function write_mo_file( string $path, array $case ): bool {
		$mo = new \MO();
		$mo->set_headers(
			array(
				'Content-Type' => 'text/plain; charset=UTF-8',
				'Plural-Forms' => $case['pluralRule']['header'],
			)
		);

		foreach ( self::pomo_entries( $case ) as $entry ) {
			$mo->add_entry( new \Translation_Entry( $entry ) );
		}

		return (bool) $mo->export_to_file( $path );
	}

	private static function write_po_file( string $path, array $case ): bool {
		if ( ! class_exists( 'PO' ) ) {
			return false;
		}

		$po = new \PO();
		$po->set_headers(
			array(
				'Content-Type' => 'text/plain; charset=UTF-8',
				'Plural-Forms' => $case['pluralRule']['header'],
			)
		);

		foreach ( self::pomo_entries( $case ) as $entry ) {
			$po->add_entry( new \Translation_Entry( $entry ) );
		}

		return (bool) $po->export_to_file( $path );
	}

	private static function write_php_translation_file( string $path, array $case, string $locale ): bool {
		$data = array(
			'Project-Id-Version' => 'component-fuzz',
			'Language'           => $locale,
			'Content-Type'       => 'text/plain; charset=UTF-8',
			'Plural-Forms'       => $case['pluralRule']['header'],
			'messages'           => self::modern_file_messages( $case ),
		);

		return false !== file_put_contents( $path, "<?php\nreturn " . var_export( $data, true ) . ";\n" );
	}

	private static function pomo_entries( array $case ): array {
		return array(
			array(
				'singular'     => $case['source'],
				'translations' => array( $case['translation'] ),
			),
			array(
				'singular'     => $case['contextSource'],
				'context'      => $case['context'],
				'translations' => array( $case['contextTranslation'] ),
			),
			array(
				'singular'     => $case['pluralSingular'],
				'plural'       => $case['pluralPlural'],
				'translations' => $case['pluralTranslations'],
			),
			array(
				'singular'     => $case['contextPluralSingular'],
				'plural'       => $case['contextPluralPlural'],
				'context'      => $case['context'],
				'translations' => $case['contextPluralTranslations'],
			),
		);
	}

	private static function modern_file_messages( array $case ): array {
		return array(
			$case['source']                                      => $case['translation'],
			$case['context'] . "\4" . $case['contextSource']     => $case['contextTranslation'],
			$case['pluralSingular']                              => implode( "\0", $case['pluralTranslations'] ),
			$case['context'] . "\4" . $case['contextPluralSingular'] => implode( "\0", $case['contextPluralTranslations'] ),
		);
	}

	private static function safe_file_text( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$tail = $ctx->choice(
			array(
				$ctx->ascii( 0, 18 ),
				"line\nbreak",
				"tab\tvalue",
				"unicode-\xE2\x98\x83-\xC3\xA9",
				'html <b>& "quote"',
			)
		);
		$text = $prefix . '-' . $tail;
		$text = str_replace( array( "\0", "\4", "\r" ), array( '', '', "\n" ), $text );
		$text = self::bound_string( $text, self::MAX_TEXT_BYTES );

		return '' === $text ? $prefix : $text;
	}

	private static function generated_safe_domain( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$domain = 'component-fuzz-translations-' . $label . '-' . substr( sha1( $ctx->seed() . ':' . $ctx->iteration() . ':' . $label ), 0, 12 );
		return substr( $domain, 0, self::MAX_DOMAIN_LEN );
	}

	private static function make_temp_dir( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$dir = \ComponentFuzz\repo_root()
			. DIRECTORY_SEPARATOR . 'artifacts'
			. DIRECTORY_SEPARATOR . 'temp'
			. DIRECTORY_SEPARATOR . 'component-fuzz'
			. DIRECTORY_SEPARATOR . 'translations'
			. DIRECTORY_SEPARATOR . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration() . '-' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $label );

		\ComponentFuzz\ensure_dir( $dir );
		return $dir;
	}

	private static function remove_temp_dir( string $dir ): void {
		$root = \ComponentFuzz\repo_root()
			. DIRECTORY_SEPARATOR . 'artifacts'
			. DIRECTORY_SEPARATOR . 'temp'
			. DIRECTORY_SEPARATOR . 'component-fuzz'
			. DIRECTORY_SEPARATOR . 'translations'
			. DIRECTORY_SEPARATOR;

		if ( ! str_starts_with( $dir, $root ) || ! file_exists( $dir ) ) {
			return;
		}

		if ( ! is_dir( $dir ) ) {
			@unlink( $dir );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}

	private static function load_optional_po(): bool {
		if ( class_exists( 'PO' ) ) {
			return true;
		}

		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			$path = ABSPATH . WPINC . '/pomo/po.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		return class_exists( 'PO' );
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
	}

	private static function clear_l10n_filters( array $domains ): void {
		$tags = array(
			'gettext',
			'gettext_with_context',
			'lang_dir_for_domain',
			'load_textdomain_mofile',
			'load_translation_file',
			'ngettext',
			'ngettext_with_context',
			'override_load_textdomain',
			'override_unload_textdomain',
			'pre_load_textdomain',
			'translation_file_format',
		);

		foreach ( $domains as $domain ) {
			$tags[] = "gettext_{$domain}";
			$tags[] = "gettext_with_context_{$domain}";
			$tags[] = "ngettext_{$domain}";
			$tags[] = "ngettext_with_context_{$domain}";
		}

		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return;
		}

		foreach ( $tags as $tag ) {
			unset( $GLOBALS['wp_filter'][ $tag ] );
		}
	}

	private static function domain_state_for( array $domains, string $locale ): array {
		$state = array();

		foreach ( $domains as $domain ) {
			$l10n_entry       = $GLOBALS['l10n'][ $domain ] ?? null;
			$state[ $domain ] = array(
				'l10nExists'       => isset( $GLOBALS['l10n'] ) && array_key_exists( $domain, (array) $GLOBALS['l10n'] ),
				'l10nClass'        => is_object( $l10n_entry ) ? get_class( $l10n_entry ) : null,
				'unloaded'         => isset( $GLOBALS['l10n_unloaded'] ) && array_key_exists( $domain, (array) $GLOBALS['l10n_unloaded'] ),
				'registry'         => self::registry_state_for_domain( $domain ),
				'controllerLoaded' => self::controller_loaded_for_domain( $domain, $locale ),
			);
		}

		return $state;
	}

	private static function registry_state_for_domain( string $domain ): array {
		if ( ! isset( $GLOBALS['wp_textdomain_registry'] ) || ! is_object( $GLOBALS['wp_textdomain_registry'] ) ) {
			return array();
		}

		$reflection = new \ReflectionObject( $GLOBALS['wp_textdomain_registry'] );
		$state      = array();

		foreach ( array( 'all', 'current', 'custom_paths', 'domains_with_translations' ) as $property_name ) {
			if ( ! $reflection->hasProperty( $property_name ) ) {
				continue;
			}

			$property = $reflection->getProperty( $property_name );
			$value    = $property->getValue( $GLOBALS['wp_textdomain_registry'] );

			if ( is_array( $value ) ) {
				$state[ $property_name ] = $value[ $domain ] ?? ( in_array( $domain, $value, true ) ? true : null );
			}
		}

		return $state;
	}

	private static function controller_loaded_for_domain( string $domain, string $locale ): ?bool {
		if ( ! class_exists( 'WP_Translation_Controller' ) ) {
			return null;
		}

		$controller = \WP_Translation_Controller::get_instance();
		return $controller->is_textdomain_loaded( $domain, $locale );
	}

	private static function snapshot_global_state(): array {
		$names    = array(
			'l10n',
			'l10n_unloaded',
			'locale',
			'wp_locale',
			'wp_textdomain_registry',
			'wp_filter',
			'wp_actions',
			'wp_filters',
			'wp_current_filter',
		);
		$snapshot = array(
			'globals'                => array(),
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
		$instance          = $instance_property->getValue();

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

		$instance          = $snapshot['instance'];
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

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok || count( $failures ) >= self::FAILURE_LIMIT ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private static function sample_case( array &$samples, array $case ): void {
		if ( count( $samples ) >= 5 ) {
			return;
		}

		$samples[] = self::case_summary( $case );
	}

	private static function sample_file_case( array &$samples, array $case ): void {
		if ( count( $samples ) >= 4 ) {
			return;
		}

		$samples[] = self::file_case_summary( $case );
	}

	private static function case_summary( array $case ): array {
		$summary = array();

		foreach ( array( 'singular', 'plural', 'context', 'source', 'translation' ) as $key ) {
			if ( isset( $case[ $key ] ) && is_string( $case[ $key ] ) ) {
				$summary[ $key ] = self::describe_value( $case[ $key ] );
			}
		}

		if ( isset( $case['count'] ) ) {
			$summary['count'] = $case['count'];
		}

		return $summary;
	}

	private static function file_case_summary( array $case ): array {
		return array(
			'source'     => self::describe_value( $case['source'] ),
			'context'    => self::describe_value( $case['context'] ),
			'pluralRule' => array(
				'name'     => $case['pluralRule']['name'],
				'header'   => $case['pluralRule']['header'],
				'expected' => $case['pluralRule']['expected'],
			),
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

	private static function preview_path( string $path ): string {
		return str_replace( \ComponentFuzz\repo_root(), '<repo>', $path );
	}

	private static function bound_string( string $value, int $max_bytes ): string {
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}

		return substr( $value, 0, $max_bytes );
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
}
