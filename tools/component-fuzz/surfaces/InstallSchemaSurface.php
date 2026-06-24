<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes install/schema helpers without touching a real database.
 */
final class InstallSchemaSurface {
	public const NAME = 'install-schema';

	private const MAX_FAILURES = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::ensure_helpers_loaded();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'install-schema.bootstrap-apis-available',
					'Required WordPress install/schema APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$rows[] = self::check_wp_get_db_schema_table_sets( $ctx );
			$rows[] = self::check_make_db_current_scope_wrappers( $ctx );
			$rows[] = self::check_create_table_parser_variants( $ctx );
			$rows[] = self::check_dbdelta_equivalent_noops( $ctx );
			$rows[] = self::check_dbdelta_column_change_isolation( $ctx );
			$rows[] = self::check_dbdelta_index_normalization( $ctx );
			$rows[] = self::check_sql_allowlist_on_executed_diffs( $ctx );
			$rows[] = self::check_malformed_ddl_fails_closed( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'install-schema.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'install-schema.global-state-restored',
			self::state_matches( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot ) )
		);

		return $rows;
	}

	private static function ensure_helpers_loaded(): void {
		if ( function_exists( 'dbDelta' ) && function_exists( 'wp_get_db_schema' ) ) {
			return;
		}

		$previous_exists = array_key_exists( 'wpdb', $GLOBALS );
		$previous_wpdb   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new InstallSchemaWpdbDouble( 'wp_' );

		try {
			if ( ! function_exists( 'wp_get_db_schema' ) ) {
				require_once ABSPATH . 'wp-admin/includes/schema.php';
			}
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
		} finally {
			if ( $previous_exists ) {
				$GLOBALS['wpdb'] = $previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'apply_filters',
				'dbDelta',
				'is_multisite',
				'make_db_current',
				'make_db_current_silent',
				'wp_get_db_schema',
				'wp_should_upgrade_global_tables',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_wp_get_db_schema_table_sets( \ComponentFuzz\FuzzContext $ctx ): array {
		$prefix = self::prefix_for_context( $ctx->fork( 'schema-prefix' ) );
		$wpdb   = new InstallSchemaWpdbDouble( $prefix );

		$result = self::with_wpdb(
			$wpdb,
			static function () use ( $wpdb, $prefix ): array {
				$blog_sql             = wp_get_db_schema( 'blog' );
				$global_sql           = wp_get_db_schema( 'global' );
				$all_sql              = wp_get_db_schema( 'all' );
				$ms_global_sql        = wp_get_db_schema( 'ms_global' );
				$blog_for_other_site  = wp_get_db_schema( 'blog', 7 );
				$blog_tables          = self::create_table_names( $blog_sql );
				$global_tables        = self::create_table_names( $global_sql );
				$all_tables           = self::create_table_names( $all_sql );
				$ms_global_tables     = self::create_table_names( $ms_global_sql );
				$other_site_tables    = self::create_table_names( $blog_for_other_site );
				$expected_blog        = array_values( $wpdb->tables( 'blog' ) );
				$expected_global      = array_values( $wpdb->tables( 'global' ) );
				$expected_all         = array_values( $wpdb->tables( 'all' ) );
				$expected_ms_global   = array_values( $wpdb->tables( 'ms_global' ) );
				$blog_id_was_restored = 1 === (int) $wpdb->blogid && $prefix === $wpdb->prefix;

				sort( $blog_tables );
				sort( $global_tables );
				sort( $all_tables );
				sort( $ms_global_tables );
				sort( $other_site_tables );
				sort( $expected_blog );
				sort( $expected_global );
				sort( $expected_all );
				sort( $expected_ms_global );

				$global_contains_ms = array_intersect( $expected_ms_global, $global_tables );
				$all_contains_ms    = array_intersect( $expected_ms_global, $all_tables );
				$expected_ms_in_all = is_multisite();

				$ok = $expected_blog === $blog_tables
					&& $expected_global === $global_tables
					&& $expected_all === $all_tables
					&& $expected_ms_global === $ms_global_tables
					&& $blog_tables === $other_site_tables
					&& $blog_id_was_restored
					&& self::all_tables_have_allowed_prefix( $all_tables, array( $prefix ) )
					&& self::all_tables_have_allowed_prefix( $ms_global_tables, array( $prefix ) )
					&& ( $expected_ms_in_all || array() === $global_contains_ms )
					&& ( $expected_ms_in_all || array() === $all_contains_ms );

				return array(
					'ok'                  => $ok,
					'blogTables'          => $blog_tables,
					'expectedBlogTables'  => $expected_blog,
					'globalTables'        => $global_tables,
					'expectedGlobalTables' => $expected_global,
					'allTables'           => $all_tables,
					'expectedAllTables'   => $expected_all,
					'msGlobalTables'      => $ms_global_tables,
					'expectedMsGlobal'    => $expected_ms_global,
					'otherSiteTables'     => $other_site_tables,
					'blogIdRestored'      => $blog_id_was_restored,
					'prefix'              => $prefix,
					'isMultisite'         => is_multisite(),
				);
			}
		);

		return $ctx->result(
			'install-schema.wp-get-db-schema-table-sets-and-prefixes',
			$result['ok'],
			$result
		);
	}

	private static function check_make_db_current_scope_wrappers( \ComponentFuzz\FuzzContext $ctx ): array {
		$prefix = self::prefix_for_context( $ctx->fork( 'make-db-current-prefix' ) );
		$wpdb   = new InstallSchemaWpdbDouble( $prefix );
		$scopes = array_values(
			array_unique(
				array(
					'blog',
					'global',
					'ms_global',
					'all',
					$ctx->choice( array( 'blog', 'global', 'ms_global', 'all' ) ),
				)
			)
		);

		$result = self::with_wpdb(
			$wpdb,
			static function () use ( $scopes ): array {
				$observed = array();
				$failures = array();

				foreach ( $scopes as $scope ) {
					$expected_tables = self::create_table_names( wp_get_db_schema( $scope ) );
					sort( $expected_tables );

					$silent = self::capture_make_db_current_call(
						static function () use ( $scope ): void {
							make_db_current_silent( $scope );
						}
					);

					$noisy = self::capture_make_db_current_call(
						static function () use ( $scope ): void {
							make_db_current( $scope );
						}
					);

					$silent_tables = $silent['tables'];
					$noisy_tables  = $noisy['tables'];
					sort( $silent_tables );
					sort( $noisy_tables );

					$observed[ $scope ] = array(
						'expectedTables' => $expected_tables,
						'silentTables'   => $silent_tables,
						'noisyTables'    => $noisy_tables,
						'silentOutput'   => $silent['output'],
						'noisyOutput'    => $noisy['output'],
						'silentCalls'    => $silent['calls'],
						'noisyCalls'     => $noisy['calls'],
					);

					if ( $expected_tables !== $silent_tables || $expected_tables !== $noisy_tables ) {
						$failures[] = array(
							'scope'    => $scope,
							'reason'   => 'schema-scope-table-set-mismatch',
							'expected' => $expected_tables,
							'silent'   => $silent_tables,
							'noisy'    => $noisy_tables,
						);
						continue;
					}

					if ( 1 !== $silent['calls'] || 1 !== $noisy['calls'] ) {
						$failures[] = array(
							'scope'       => $scope,
							'reason'      => 'dbdelta-filter-call-count-mismatch',
							'silentCalls' => $silent['calls'],
							'noisyCalls'  => $noisy['calls'],
						);
						continue;
					}

					if ( '' !== $silent['output'] || "<ol>\n</ol>\n" !== $noisy['output'] ) {
						$failures[] = array(
							'scope'        => $scope,
							'reason'       => 'wrapper-output-mismatch',
							'silentOutput' => $silent['output'],
							'noisyOutput'  => $noisy['output'],
						);
					}
				}

				return array(
					'ok'       => array() === $failures,
					'observed' => $observed,
					'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
				);
			}
		);

		return $ctx->result(
			'install-schema.make-db-current-wrappers-preserve-schema-scopes',
			$result['ok'],
			$result
		);
	}

	private static function check_create_table_parser_variants( \ComponentFuzz\FuzzContext $ctx ): array {
		$spec     = self::schema_spec( $ctx->fork( 'parser-variants' ) );
		$variants = array(
			'canonical'      => self::ddl_from_spec( $spec, 'canonical' ),
			'backticks'      => self::ddl_from_spec( $spec, 'backticks' ),
			'lowercase'      => self::ddl_from_spec( $spec, 'lowercase' ),
			'index-synonyms' => self::ddl_from_spec( $spec, 'index-synonyms' ),
			'wide-spacing'   => self::ddl_from_spec( $spec, 'wide-spacing' ),
		);

		$failures  = array();
		$signature = null;
		$tables    = array();

		foreach ( $variants as $label => $ddl ) {
			$parsed = self::parse_create_tables( $ddl );
			$sig    = self::schema_signature( $parsed );
			$names  = array_keys( $parsed['tables'] );
			$tables[ $label ] = $names;

			if ( ! $parsed['ok'] ) {
				$failures[] = array(
					'case'   => $label,
					'reason' => 'parse-failed',
					'errors' => $parsed['errors'],
				);
				continue;
			}

			if ( null === $signature ) {
				$signature = $sig;
			} elseif ( $signature !== $sig ) {
				$failures[] = array(
					'case'     => $label,
					'reason'   => 'signature-mismatch',
					'expected' => $signature,
					'actual'   => $sig,
				);
			}

			if ( ! self::all_tables_have_allowed_prefix( $names, array( 'wp_' ) ) ) {
				$failures[] = array(
					'case'   => $label,
					'reason' => 'table-prefix-escaped-allowlist',
					'tables' => $names,
				);
			}
		}

		return $ctx->result(
			'install-schema.create-table-ddl-parses-deterministically-across-formatting',
			array() === $failures,
			array(
				'table'    => $spec['table'],
				'variants' => array_keys( $variants ),
				'tables'   => $tables,
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_dbdelta_equivalent_noops( \ComponentFuzz\FuzzContext $ctx ): array {
		$spec     = self::schema_spec( $ctx->fork( 'dbdelta-noops' ) );
		$existing = self::parse_create_tables( self::ddl_from_spec( $spec, 'canonical' ) );
		$variants = array(
			'canonical'      => self::ddl_from_spec( $spec, 'canonical' ),
			'backticks'      => self::ddl_from_spec( $spec, 'backticks' ),
			'index-synonyms' => self::ddl_from_spec( $spec, 'index-synonyms' ),
			'wide-spacing'   => self::ddl_from_spec( $spec, 'wide-spacing' ),
		);
		$failures = array();
		$results  = array();

		foreach ( $variants as $label => $ddl ) {
			$wpdb = new InstallSchemaWpdbDouble( 'wp_', $existing['tables'] );
			$out  = self::with_wpdb(
				$wpdb,
				static function () use ( $ddl ): array {
					$first  = dbDelta( $ddl, false );
					$second = dbDelta( $ddl, false );
					return array( $first, $second );
				}
			);

			$results[ $label ] = array(
				'first'            => $out[0],
				'second'           => $out[1],
				'introspectionSql' => $wpdb->introspection_log,
				'executedSql'      => $wpdb->executed_queries,
				'violations'       => $wpdb->violations,
			);

			if ( array() !== $out[0] || $out[0] !== $out[1] || array() !== $wpdb->executed_queries || array() !== $wpdb->violations ) {
				$failures[] = array(
					'case'      => $label,
					'first'     => $out[0],
					'second'    => $out[1],
					'executed'  => $wpdb->executed_queries,
					'violations'=> $wpdb->violations,
				);
			}
		}

		return $ctx->result(
			'install-schema.dbdelta-equivalent-schemas-are-stable-noops',
			array() === $failures,
			array(
				'table'    => $spec['table'],
				'cases'    => array_keys( $variants ),
				'results'  => $results,
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_dbdelta_column_change_isolation( \ComponentFuzz\FuzzContext $ctx ): array {
		$target_spec = self::schema_spec( $ctx->fork( 'column-target' ) );
		$target_ddl  = self::ddl_from_spec( $target_spec, 'canonical' );
		$table       = $target_spec['table'];
		$cases       = array(
			'type'    => self::mutate_column( $target_spec, 'status', 'type', 'varchar(12)' ),
			'default' => self::mutate_column( $target_spec, 'status', 'default', 'archived' ),
			'null'    => self::mutate_column( $target_spec, 'status', 'nullable', true ),
		);
		$failures    = array();
		$observed    = array();

		foreach ( $cases as $kind => $existing_spec ) {
			$existing = self::parse_create_tables( self::ddl_from_spec( $existing_spec, 'canonical' ) );
			$target   = self::parse_create_tables( $target_ddl );
			$diff     = self::semantic_diff( $existing, $target, $table );
			$wpdb     = new InstallSchemaWpdbDouble( 'wp_', $existing['tables'] );
			$dbdelta  = self::with_wpdb(
				$wpdb,
				static function () use ( $target_ddl ): array {
					return dbDelta( $target_ddl, false );
				}
			);

			$expected_diff = array( "column:{$table}.status:{$kind}" );
			$dbdelta_keys  = array_keys( $dbdelta );
			$observed[ $kind ] = array(
				'semanticDiff' => $diff,
				'dbDelta'      => $dbdelta,
				'queries'      => $wpdb->executed_queries,
			);

			if ( $expected_diff !== $diff ) {
				$failures[] = array(
					'case'     => $kind,
					'reason'   => 'semantic-diff-not-isolated',
					'expected' => $expected_diff,
					'actual'   => $diff,
				);
				continue;
			}

			if ( 'null' === $kind ) {
				if ( array() !== $dbdelta ) {
					$failures[] = array(
						'case'   => $kind,
						'reason' => 'dbdelta-emitted-null-only-change',
						'actual' => $dbdelta,
					);
				}
				continue;
			}

			if ( array( "{$table}.status" ) !== $dbdelta_keys ) {
				$failures[] = array(
					'case'      => $kind,
					'reason'    => 'dbdelta-change-not-isolated-to-status-column',
					'keys'      => $dbdelta_keys,
					'dbDelta'   => $dbdelta,
				);
			}
		}

		return $ctx->result(
			'install-schema.column-type-default-null-diffs-are-isolated',
			array() === $failures,
			array(
				'table'    => $table,
				'observed' => $observed,
				'failures' => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_dbdelta_index_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		$target_spec       = self::schema_spec( $ctx->fork( 'index-normalization' ) );
		$target_ddl        = self::ddl_from_spec( $target_spec, 'canonical' );
		$target_parsed     = self::parse_create_tables( $target_ddl );
		$table             = $target_spec['table'];
		$subpart_existing  = self::mutate_index_subparts( $target_spec, null );
		$subpart_parsed    = self::parse_create_tables( self::ddl_from_spec( $subpart_existing, 'canonical' ) );
		$columns_only_spec = self::without_indexes( $target_spec );
		$columns_parsed    = self::parse_create_tables( self::ddl_from_spec( $columns_only_spec, 'canonical' ) );
		$failures          = array();

		$subpart_wpdb = new InstallSchemaWpdbDouble( 'wp_', $subpart_parsed['tables'] );
		$subpart_diff = self::with_wpdb(
			$subpart_wpdb,
			static function () use ( $target_ddl ): array {
				return dbDelta( $target_ddl, false );
			}
		);

		if ( array() !== $subpart_diff || array() !== $subpart_wpdb->executed_queries ) {
			$failures[] = array(
				'case'     => 'subparts',
				'reason'   => 'equivalent-index-subparts-produced-change',
				'dbDelta'  => $subpart_diff,
				'executed' => $subpart_wpdb->executed_queries,
			);
		}

		$missing_wpdb   = new InstallSchemaWpdbDouble( 'wp_', $columns_parsed['tables'], array( $table ) );
		$missing_result = self::with_wpdb(
			$missing_wpdb,
			static function () use ( $target_ddl ): array {
				return dbDelta( $target_ddl, true );
			}
		);
		$added_indexes  = array_values(
			array_filter(
				$missing_result,
				static function ( string $message ): bool {
					return str_starts_with( $message, 'Added index ' );
				}
			)
		);
		$unique_added   = array_values( array_unique( $added_indexes ) );
		$expected_count = count( $target_parsed['tables'][ $table ]['indexes'] );

		if ( $expected_count !== count( $added_indexes ) || $added_indexes !== $unique_added || array() !== $missing_wpdb->violations ) {
			$failures[] = array(
				'case'          => 'missing-indexes',
				'reason'        => 'index-additions-not-unique-or-not-allowlisted',
				'expectedCount' => $expected_count,
				'added'         => $added_indexes,
				'executed'      => $missing_wpdb->executed_queries,
				'violations'    => $missing_wpdb->violations,
			);
		}

		return $ctx->result(
			'install-schema.dbdelta-indexes-and-subparts-normalize-without-duplicates',
			array() === $failures,
			array(
				'table'              => $table,
				'subpartDiff'        => $subpart_diff,
				'addedIndexMessages' => $added_indexes,
				'executedSql'        => $missing_wpdb->executed_queries,
				'failures'           => array_slice( $failures, 0, self::MAX_FAILURES ),
			)
		);
	}

	private static function check_sql_allowlist_on_executed_diffs( \ComponentFuzz\FuzzContext $ctx ): array {
		$target_spec   = self::schema_spec( $ctx->fork( 'allowlist-target' ) );
		$existing_spec = self::without_indexes( self::remove_column( $target_spec, 'rating' ) );
		$target_ddl    = self::ddl_from_spec( $target_spec, 'canonical' );
		$existing      = self::parse_create_tables( self::ddl_from_spec( $existing_spec, 'canonical' ) );
		$table         = $target_spec['table'];
		$wpdb          = new InstallSchemaWpdbDouble( 'wp_', $existing['tables'], array( $table ) );
		$result        = self::with_wpdb(
			$wpdb,
			static function () use ( $target_ddl ): array {
				return dbDelta( $target_ddl, true );
			}
		);
		$escaped       = self::queries_escape_table_allowlist( $wpdb->executed_queries, array( $table ) );
		$ok            = array() === $escaped
			&& array() === $wpdb->violations
			&& isset( $result[ "{$table}.rating" ] )
			&& str_starts_with( $result[ "{$table}.rating" ], 'Added column ' );

		return $ctx->result(
			'install-schema.generated-sql-stays-inside-table-allowlist',
			$ok,
			array(
				'table'      => $table,
				'dbDelta'    => $result,
				'executed'   => $wpdb->executed_queries,
				'escaped'    => $escaped,
				'violations' => $wpdb->violations,
			)
		);
	}

	private static function check_malformed_ddl_fails_closed( \ComponentFuzz\FuzzContext $ctx ): array {
		$spec      = self::schema_spec( $ctx->fork( 'malformed' ) );
		$table     = $spec['table'];
		$malformed = array(
			"CREATE\tTABLE {$table} (id int(11) NOT NULL)",
			"CREATE\nTABLE {$table} (id int(11) NOT NULL)",
			"CREATE  TABLE {$table} (id int(11) NOT NULL)",
			"ALTER TABLE {$table} ADD COLUMN escaped int(11)",
			"garbage {$table} CREATE TABLE",
		);
		$parser_bad = array(
			"CREATE TABLE {$table} id int(11) NOT NULL)",
			"CREATE TABLE {$table} (id int(11) NOT NULL",
			'CREATE TABLE ../escape (id int(11) NOT NULL)',
		);
		$warnings   = array();
		$throwable  = null;
		$wpdb       = new InstallSchemaWpdbDouble( 'wp_', array(), array( $table ) );
		$dbdelta    = array();

		$previous_handler = set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ) use ( &$warnings ): bool {
				$warnings[] = compact( 'severity', 'message', 'file', 'line' );
				return true;
			}
		);
		unset( $previous_handler );

		try {
			$dbdelta = self::with_wpdb(
				$wpdb,
				static function () use ( $malformed ): array {
					return dbDelta( $malformed, true );
				}
			);
		} catch ( \Throwable $e ) {
			$throwable = $e;
		} finally {
			restore_error_handler();
		}

		$parser_results = array();
		foreach ( $parser_bad as $case ) {
			$parsed           = self::parse_create_tables( $case );
			$parser_results[] = array(
				'ok'     => $parsed['ok'],
				'errors' => $parsed['errors'],
			);
		}

		$all_parser_cases_failed_closed = array() === array_filter(
			$parser_results,
			static function ( array $result ): bool {
				return $result['ok'];
			}
		);

		$ok = null === $throwable
			&& array() === $warnings
			&& array() === $dbdelta
			&& array() === $wpdb->executed_queries
			&& array() === $wpdb->violations
			&& $all_parser_cases_failed_closed;

		return $ctx->result(
			'install-schema.malformed-ddl-fails-closed-without-warnings',
			$ok,
			array(
				'table'         => $table,
				'dbDelta'       => $dbdelta,
				'executed'      => $wpdb->executed_queries,
				'warnings'      => $warnings,
				'throwable'     => null === $throwable ? null : self::describe_throwable( $throwable ),
				'parserResults' => $parser_results,
			)
		);
	}

	private static function schema_spec( \ComponentFuzz\FuzzContext $ctx ): array {
		$suffix = strtolower( base_convert( $ctx->int( 100000, 999999 ), 10, 36 ) );
		$table  = 'wp_cf_install_' . $ctx->iteration() . '_' . $suffix;
		$status = $ctx->choice( array( 'draft', 'open', 'queued' ) );

		return array(
			'table'   => $table,
			'columns' => array(
				'id'         => array(
					'type'     => 'bigint(20) unsigned',
					'nullable' => false,
					'default'  => null,
					'extra'    => 'auto_increment',
				),
				'slug'       => array(
					'type'     => 'varchar(' . $ctx->int( 48, 96 ) . ')',
					'nullable' => false,
					'default'  => '',
					'extra'    => '',
				),
				'status'     => array(
					'type'     => 'varchar(20)',
					'nullable' => false,
					'default'  => $status,
					'extra'    => '',
				),
				'rating'     => array(
					'type'     => 'int(11)',
					'nullable' => false,
					'default'  => '0',
					'extra'    => '',
				),
				'payload'    => array(
					'type'     => 'longtext',
					'nullable' => false,
					'default'  => null,
					'extra'    => '',
				),
				'created_at' => array(
					'type'     => 'datetime',
					'nullable' => false,
					'default'  => '0000-00-00 00:00:00',
					'extra'    => '',
				),
			),
			'indexes' => array(
				array(
					'type'    => 'PRIMARY KEY',
					'name'    => '',
					'columns' => array(
						array( 'name' => 'id', 'subpart' => null ),
					),
				),
				array(
					'type'    => 'UNIQUE KEY',
					'name'    => 'slug_key',
					'columns' => array(
						array( 'name' => 'slug', 'subpart' => null ),
					),
				),
				array(
					'type'    => 'KEY',
					'name'    => 'status_created',
					'columns' => array(
						array( 'name' => 'status', 'subpart' => null ),
						array( 'name' => 'created_at', 'subpart' => null ),
					),
				),
				array(
					'type'    => 'KEY',
					'name'    => 'payload_prefix',
					'columns' => array(
						array( 'name' => 'payload', 'subpart' => 32 ),
					),
				),
			),
		);
	}

	private static function ddl_from_spec( array $spec, string $style ): string {
		$table = $spec['table'];
		if ( 'backticks' === $style ) {
			$table = '`' . $table . '`';
		}

		$lines = array();
		foreach ( $spec['columns'] as $name => $column ) {
			$lines[] = self::column_ddl( $name, $column, $style );
		}
		foreach ( $spec['indexes'] as $index ) {
			$lines[] = self::index_ddl( $index, $style );
		}

		$create = 'lowercase' === $style ? 'create table' : 'CREATE TABLE';
		if ( 'wide-spacing' === $style ) {
			return $create . ' ' . $table . " (\n    " . implode( "  ,\n    ", $lines ) . "\n) DEFAULT CHARACTER SET utf8mb4;";
		}

		return $create . ' ' . $table . " (\n\t" . implode( ",\n\t", $lines ) . "\n) DEFAULT CHARACTER SET utf8mb4;";
	}

	private static function column_ddl( string $name, array $column, string $style ): string {
		$identifier = 'backticks' === $style || 'wide-spacing' === $style ? '`' . $name . '`' : $name;
		$type       = ( 'backticks' === $style ) ? strtoupper( $column['type'] ) : $column['type'];
		$null       = $column['nullable'] ? 'NULL' : 'NOT NULL';
		$default    = '';

		if ( null !== $column['default'] ) {
			$keyword = 'lowercase' === $style ? 'default' : 'DEFAULT';
			$default = ' ' . $keyword . " '" . str_replace( "'", "''", (string) $column['default'] ) . "'";
		}

		$extra = '' === $column['extra'] ? '' : ' ' . $column['extra'];
		if ( 'backticks' === $style ) {
			$null  = strtolower( $null );
			$extra = strtoupper( $extra );
		}

		return trim( "{$identifier} {$type} {$null}{$default}{$extra}" );
	}

	private static function index_ddl( array $index, string $style ): string {
		$type = $index['type'];
		if ( 'index-synonyms' === $style && 'PRIMARY KEY' !== $type ) {
			$type = str_replace( 'KEY', 'INDEX', $type );
		} elseif ( 'lowercase' === $style ) {
			$type = strtolower( $type );
		}

		$name = '';
		if ( '' !== $index['name'] ) {
			$name = 'backticks' === $style || 'wide-spacing' === $style ? '`' . $index['name'] . '` ' : $index['name'] . ' ';
		}

		$columns = array();
		foreach ( $index['columns'] as $column ) {
			$column_name = ( 'backticks' === $style || 'wide-spacing' === $style ) ? '`' . $column['name'] . '`' : $column['name'];
			if ( null !== $column['subpart'] ) {
				$column_name .= 'wide-spacing' === $style ? ' ( ' . $column['subpart'] . ' )' : '(' . $column['subpart'] . ')';
			}
			$columns[] = $column_name;
		}

		$space = 'wide-spacing' === $style ? ', ' : ',';
		return trim( "{$type} {$name}(" . implode( $space, $columns ) . ')' );
	}

	private static function mutate_column( array $spec, string $column_name, string $property, $value ): array {
		$mutated = $spec;
		$mutated['columns'][ $column_name ][ $property ] = $value;
		return $mutated;
	}

	private static function mutate_index_subparts( array $spec, $subpart ): array {
		$mutated = $spec;
		foreach ( $mutated['indexes'] as &$index ) {
			foreach ( $index['columns'] as &$column ) {
				if ( null !== $column['subpart'] ) {
					$column['subpart'] = $subpart;
				}
			}
		}
		unset( $index, $column );
		return $mutated;
	}

	private static function without_indexes( array $spec ): array {
		$spec['indexes'] = array();
		return $spec;
	}

	private static function remove_column( array $spec, string $column_name ): array {
		unset( $spec['columns'][ $column_name ] );
		foreach ( $spec['indexes'] as $index_id => $index ) {
			foreach ( $index['columns'] as $column ) {
				if ( $column_name === $column['name'] ) {
					unset( $spec['indexes'][ $index_id ] );
					break;
				}
			}
		}
		$spec['indexes'] = array_values( $spec['indexes'] );
		return $spec;
	}

	private static function parse_create_tables( string $sql ): array {
		$tables = array();
		$errors = array();
		$offset = 0;

		while ( preg_match( '/\bCREATE\s+TABLE\s+(`?[A-Za-z0-9_]+`?)/i', $sql, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$table_token = $matches[1][0];
			$table       = self::clean_identifier( $table_token );
			$after_name  = $matches[1][1] + strlen( $table_token );
			$open        = strpos( $sql, '(', $after_name );

			if ( false === $open ) {
				$errors[] = "missing-open-paren:{$table}";
				$offset   = $after_name;
				continue;
			}

			$close = self::find_matching_paren( $sql, $open );
			if ( null === $close ) {
				$errors[] = "missing-close-paren:{$table}";
				$offset   = $open + 1;
				continue;
			}

			if ( ! self::is_safe_table_name( $table ) ) {
				$errors[] = "unsafe-table-name:{$table}";
				$offset   = $close + 1;
				continue;
			}

			$body   = substr( $sql, $open + 1, $close - $open - 1 );
			$parsed = self::parse_table_body( $body );
			if ( array() !== $parsed['errors'] ) {
				foreach ( $parsed['errors'] as $error ) {
					$errors[] = "{$table}:{$error}";
				}
			}
			$tables[ $table ] = array(
				'columns' => $parsed['columns'],
				'indexes' => $parsed['indexes'],
			);
			$offset = $close + 1;
		}

		if ( array() === $tables ) {
			$errors[] = 'no-create-table';
		}

		return array(
			'ok'     => array() !== $tables && array() === $errors,
			'tables' => $tables,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	private static function parse_table_body( string $body ): array {
		$columns = array();
		$indexes = array();
		$errors  = array();

		foreach ( self::split_top_level_commas( $body ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$index = self::parse_index_line( $line );
			if ( null !== $index ) {
				$indexes[] = $index;
				continue;
			}

			$column = self::parse_column_line( $line );
			if ( null === $column ) {
				$errors[] = 'unparsed-line:' . self::preview( $line );
				continue;
			}
			$columns[ $column['name'] ] = $column;
		}

		if ( array() === $columns ) {
			$errors[] = 'no-columns';
		}

		return array(
			'columns' => $columns,
			'indexes' => $indexes,
			'errors'  => $errors,
		);
	}

	private static function parse_column_line( string $line ): ?array {
		if ( ! preg_match( '/^`?([A-Za-z0-9_]+)`?\s+(.+)$/s', $line, $matches ) ) {
			return null;
		}

		$name = strtolower( $matches[1] );
		$rest = trim( $matches[2] );
		if ( ! preg_match( '/^([A-Za-z]+(?:\s*\([^)]*\))?(?:\s+unsigned)?)(?:\s+|$)/i', $rest, $type_matches ) ) {
			return null;
		}

		$type     = self::normalize_type( $type_matches[1] );
		$nullable = ! preg_match( '/\bNOT\s+NULL\b/i', $rest );
		$default  = null;

		if ( preg_match( "/\bDEFAULT\s+'((?:''|[^'])*)'/i", $rest, $default_matches ) ) {
			$default = str_replace( "''", "'", $default_matches[1] );
		} elseif ( preg_match( '/\bDEFAULT\s+NULL\b/i', $rest ) ) {
			$default = null;
		} elseif ( preg_match( '/\bDEFAULT\s+([^\s,]+)/i', $rest, $default_matches ) ) {
			$default = trim( $default_matches[1], "'\"" );
		}

		return array(
			'name'       => $name,
			'type'       => $type,
			'nullable'   => $nullable,
			'default'    => $default,
			'extra'      => preg_match( '/\bauto_increment\b/i', $rest ) ? 'auto_increment' : '',
			'normalized' => self::normalize_sql_fragment( $line ),
		);
	}

	private static function parse_index_line( string $line ): ?array {
		if (
			! preg_match(
				'/^(PRIMARY\s+KEY|(?:UNIQUE|FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)|KEY|INDEX)\s+(?:(`?[A-Za-z0-9$_-]+`?)\s+)?\((.+)\)$/is',
				$line,
				$matches
			)
		) {
			return null;
		}

		$type = strtoupper( preg_replace( '/\s+/', ' ', trim( $matches[1] ) ) );
		$type = str_replace( 'INDEX', 'KEY', $type );
		$name = 'PRIMARY KEY' === $type ? '' : strtolower( self::clean_identifier( $matches[2] ?? '' ) );
		if ( 'PRIMARY KEY' !== $type && '' === $name ) {
			return null;
		}

		$columns = array();
		foreach ( self::split_top_level_commas( $matches[3] ) as $column ) {
			if ( ! preg_match( '/`?([A-Za-z0-9_]+)`?(?:\s*\(\s*(\d+)\s*\))?/i', trim( $column ), $column_matches ) ) {
				return null;
			}
			$columns[] = array(
				'name'    => strtolower( $column_matches[1] ),
				'subpart' => isset( $column_matches[2] ) && '' !== $column_matches[2] ? (int) $column_matches[2] : null,
			);
		}

		return array(
			'type'                 => $type,
			'name'                 => $name,
			'columns'              => $columns,
			'normalized'           => self::normalized_index_definition( $type, $name, $columns, true ),
			'withoutSubparts'      => self::normalized_index_definition( $type, $name, $columns, false ),
			'showIndexType'        => str_contains( $type, 'FULLTEXT' ) ? 'FULLTEXT' : ( str_contains( $type, 'SPATIAL' ) ? 'SPATIAL' : 'BTREE' ),
			'showIndexNonUnique'   => str_starts_with( $type, 'UNIQUE' ) || 'PRIMARY KEY' === $type ? '0' : '1',
			'showIndexKeyName'     => 'PRIMARY KEY' === $type ? 'PRIMARY' : $name,
		);
	}

	private static function normalized_index_definition( string $type, string $name, array $columns, bool $with_subparts ): string {
		$index_name = 'PRIMARY KEY' === $type ? '' : '`' . $name . '`';
		$parts      = array();
		foreach ( $columns as $column ) {
			$part = '`' . $column['name'] . '`';
			if ( $with_subparts && null !== $column['subpart'] ) {
				$part .= '(' . $column['subpart'] . ')';
			}
			$parts[] = $part;
		}

		return "{$type} {$index_name} (" . implode( ',', $parts ) . ')';
	}

	private static function semantic_diff( array $from, array $to, string $table ): array {
		$diff = array();
		if ( ! isset( $from['tables'][ $table ], $to['tables'][ $table ] ) ) {
			return array( "table:{$table}" );
		}

		$from_columns = $from['tables'][ $table ]['columns'];
		$to_columns   = $to['tables'][ $table ]['columns'];
		foreach ( array_diff( array_keys( $to_columns ), array_keys( $from_columns ) ) as $column ) {
			$diff[] = "column:{$table}.{$column}:added";
		}
		foreach ( array_diff( array_keys( $from_columns ), array_keys( $to_columns ) ) as $column ) {
			$diff[] = "column:{$table}.{$column}:removed";
		}
		foreach ( array_intersect( array_keys( $from_columns ), array_keys( $to_columns ) ) as $column ) {
			foreach ( array( 'type', 'default', 'nullable' ) as $property ) {
				if ( $from_columns[ $column ][ $property ] !== $to_columns[ $column ][ $property ] ) {
					$name = 'nullable' === $property ? 'null' : $property;
					$diff[] = "column:{$table}.{$column}:{$name}";
				}
			}
		}

		$from_indexes = array_column( $from['tables'][ $table ]['indexes'], 'withoutSubparts' );
		$to_indexes   = array_column( $to['tables'][ $table ]['indexes'], 'withoutSubparts' );
		foreach ( array_diff( $to_indexes, $from_indexes ) as $index ) {
			$diff[] = "index:{$table}:added:" . $index;
		}
		foreach ( array_diff( $from_indexes, $to_indexes ) as $index ) {
			$diff[] = "index:{$table}:removed:" . $index;
		}

		sort( $diff );
		return $diff;
	}

	private static function schema_signature( array $parsed ): string {
		$signature = array();
		foreach ( $parsed['tables'] as $table => $schema ) {
			$columns = $schema['columns'];
			ksort( $columns );
			$signature[ $table ] = array(
				'columns' => array_map(
					static function ( array $column ): array {
						return array(
							'type'     => $column['type'],
							'nullable' => $column['nullable'],
							'default'  => $column['default'],
							'extra'    => $column['extra'],
						);
					},
					$columns
				),
				'indexes' => array_values( array_column( $schema['indexes'], 'normalized' ) ),
			);
			sort( $signature[ $table ]['indexes'] );
		}
		ksort( $signature );
		return (string) wp_json_encode( $signature );
	}

	private static function create_table_names( string $sql ): array {
		$parsed = self::parse_create_tables( $sql );
		return array_keys( $parsed['tables'] );
	}

	private static function all_tables_have_allowed_prefix( array $tables, array $prefixes ): bool {
		foreach ( $tables as $table ) {
			$allowed = false;
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $table, $prefix ) && self::is_safe_table_name( $table ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return false;
			}
		}
		return true;
	}

	private static function queries_escape_table_allowlist( array $queries, array $allowed_tables ): array {
		$allowed  = array_fill_keys( $allowed_tables, true );
		$escaped  = array();
		$patterns = array(
			'/\bALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
			'/\bCREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
			'/\bDESCRIBE\s+`?([A-Za-z0-9_]+)`?/i',
			'/\bSHOW\s+INDEX\s+FROM\s+`?([A-Za-z0-9_]+)`?/i',
		);

		foreach ( $queries as $query ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $query, $matches ) && ! isset( $allowed[ $matches[1] ] ) ) {
					$escaped[] = array(
						'query' => $query,
						'table' => $matches[1],
					);
				}
			}
		}

		return $escaped;
	}

	private static function capture_make_db_current_call( callable $callback ): array {
		$tables = array();
		$calls  = 0;
		$filter = static function ( array $queries ) use ( &$tables, &$calls ): array {
			$calls++;
			foreach ( $queries as $query ) {
				$parsed = self::parse_create_tables( (string) $query );
				$tables = array_merge( $tables, array_keys( $parsed['tables'] ) );
			}

			return array();
		};

		add_filter( 'dbdelta_queries', $filter, PHP_INT_MAX );
		$output         = '';
		$buffer_started = false;

		try {
			ob_start();
			$buffer_started = true;
			$callback();
			$output         = (string) ob_get_clean();
			$buffer_started = false;
		} finally {
			if ( $buffer_started && ob_get_level() > 0 ) {
				ob_end_clean();
			}
			remove_filter( 'dbdelta_queries', $filter, PHP_INT_MAX );
		}

		return array(
			'tables' => $tables,
			'calls'  => $calls,
			'output' => $output,
		);
	}

	private static function with_wpdb( InstallSchemaWpdbDouble $wpdb, callable $callback ) {
		$previous_exists = array_key_exists( 'wpdb', $GLOBALS );
		$previous_wpdb   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;

		try {
			return $callback( $wpdb );
		} finally {
			if ( $previous_exists ) {
				$GLOBALS['wpdb'] = $previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	private static function split_top_level_commas( string $value ): array {
		$parts = array();
		$start = 0;
		$depth = 0;
		$quote = null;
		$len   = strlen( $value );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $value[ $i ];
			if ( null !== $quote ) {
				if ( $char === $quote && ( 0 === $i || '\\' !== $value[ $i - 1 ] ) ) {
					$quote = null;
				}
				continue;
			}
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
				continue;
			}
			if ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}
			if ( ',' === $char && 0 === $depth ) {
				$parts[] = substr( $value, $start, $i - $start );
				$start   = $i + 1;
			}
		}
		$parts[] = substr( $value, $start );
		return $parts;
	}

	private static function find_matching_paren( string $sql, int $open ): ?int {
		$depth = 0;
		$quote = null;
		$len   = strlen( $sql );

		for ( $i = $open; $i < $len; $i++ ) {
			$char = $sql[ $i ];
			if ( null !== $quote ) {
				if ( $char === $quote && ( 0 === $i || '\\' !== $sql[ $i - 1 ] ) ) {
					$quote = null;
				}
				continue;
			}
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
				continue;
			}
			if ( ')' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return null;
	}

	private static function normalize_type( string $type ): string {
		$type = strtolower( preg_replace( '/\s+/', ' ', trim( $type ) ) );
		$type = preg_replace( '/\s*\(\s*/', '(', $type );
		$type = preg_replace( '/\s*\)/', ')', $type );
		return (string) $type;
	}

	private static function normalize_sql_fragment( string $fragment ): string {
		$fragment = str_replace( '`', '', $fragment );
		$fragment = strtolower( preg_replace( '/\s+/', ' ', trim( $fragment ) ) );
		$fragment = preg_replace( '/\s*,\s*/', ',', $fragment );
		return (string) $fragment;
	}

	private static function clean_identifier( string $identifier ): string {
		return trim( $identifier, "` \t\n\r\0\x0B" );
	}

	private static function is_safe_table_name( string $table ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table );
	}

	private static function prefix_for_context( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice( array( 'wp_', 'wpfuzz_', 'wpit_' ) );
	}

	private static function snapshot_state(): array {
		$state = array();
		foreach ( array( 'wpdb', 'wp_queries', 'charset_collate', 'table_prefix' ) as $name ) {
			$state[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}
		return $state;
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function state_matches( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
			if ( $entry['exists'] && $entry['value'] !== $GLOBALS[ $name ] ) {
				return false;
			}
		}
		return true;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'type'    => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function preview( string $value ): string {
		return strlen( $value ) > 160 ? substr( $value, 0, 160 ) . '...' : $value;
	}
}

final class InstallSchemaWpdbDouble {
	public string $prefix;
	public string $base_prefix;
	public int $blogid = 1;
	public int $siteid = 1;
	public string $charset = 'utf8mb4';
	public string $collate = '';
	public bool $suppress_errors = false;
	public string $last_query = '';
	public int $num_rows = 0;
	public string $posts = '';
	public string $comments = '';
	public string $links = '';
	public string $options = '';
	public string $postmeta = '';
	public string $terms = '';
	public string $term_taxonomy = '';
	public string $term_relationships = '';
	public string $termmeta = '';
	public string $commentmeta = '';
	public string $users = '';
	public string $usermeta = '';
	public string $blogs = '';
	public string $blogmeta = '';
	public string $signups = '';
	public string $site = '';
	public string $sitemeta = '';
	public string $registration_log = '';
	public string $categories = '';
	public string $post2cat = '';
	public string $link2cat = '';
	public string $sitecategories = '';

	public array $tables = array(
		'posts',
		'comments',
		'links',
		'options',
		'postmeta',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'termmeta',
		'commentmeta',
	);
	public array $old_tables = array( 'categories', 'post2cat', 'link2cat' );
	public array $global_tables = array( 'users', 'usermeta' );
	public array $ms_global_tables = array( 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log' );
	public array $old_ms_global_tables = array( 'sitecategories' );

	public array $introspection_log = array();
	public array $executed_queries = array();
	public array $violations = array();

	private array $schemas;
	private array $allowed_tables;

	public function __construct( string $prefix = 'wp_', array $schemas = array(), array $allowed_tables = array() ) {
		$this->prefix         = $prefix;
		$this->base_prefix    = $prefix;
		$this->schemas        = $schemas;
		$this->allowed_tables = array_fill_keys( $allowed_tables ? $allowed_tables : array_keys( $schemas ), true );
		$this->refresh_table_properties();
	}

	public function get_charset_collate(): string {
		$charset_collate = '';
		if ( '' !== $this->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $this->charset;
		}
		if ( '' !== $this->collate ) {
			$charset_collate .= ' COLLATE ' . $this->collate;
		}
		return $charset_collate;
	}

	public function set_blog_id( $blog_id, $network_id = 0 ) {
		$old_blog_id = $this->blogid;
		$this->blogid = (int) $blog_id;
		if ( $network_id ) {
			$this->siteid = (int) $network_id;
		}
		$this->prefix = $this->get_blog_prefix();
		$this->refresh_table_properties();
		return $old_blog_id;
	}

	public function get_blog_prefix( $blog_id = null ): string {
		unset( $blog_id );
		return $this->base_prefix;
	}

	public function tables( $scope = 'all', $prefix = true, $blog_id = 0 ): array {
		switch ( $scope ) {
			case 'all':
				$tables = array_merge( $this->global_tables, $this->tables );
				if ( is_multisite() ) {
					$tables = array_merge( $tables, $this->ms_global_tables );
				}
				break;
			case 'blog':
				$tables = $this->tables;
				break;
			case 'global':
				$tables = $this->global_tables;
				if ( is_multisite() ) {
					$tables = array_merge( $tables, $this->ms_global_tables );
				}
				break;
			case 'ms_global':
				$tables = $this->ms_global_tables;
				break;
			case 'old':
				$tables = $this->old_tables;
				if ( is_multisite() ) {
					$tables = array_merge( $tables, $this->old_ms_global_tables );
				}
				break;
			default:
				return array();
		}

		if ( ! $prefix ) {
			return $tables;
		}

		if ( ! $blog_id ) {
			$blog_id = $this->blogid;
		}
		unset( $blog_id );

		$mapped        = array();
		$global_tables = array_merge( $this->global_tables, $this->ms_global_tables );
		foreach ( $tables as $table ) {
			$mapped[ $table ] = ( in_array( $table, $global_tables, true ) ? $this->base_prefix : $this->prefix ) . $table;
		}

		return $mapped;
	}

	public function db_version(): string {
		return '8.0.16';
	}

	public function db_server_info(): string {
		return '8.0.16 ComponentFuzz';
	}

	public function suppress_errors( $suppress = true ) {
		$previous = $this->suppress_errors;
		$this->suppress_errors = (bool) $suppress;
		return $previous;
	}

	public function get_results( $query = null, $output = OBJECT ): array {
		unset( $output );

		$this->last_query = (string) $query;
		$this->introspection_log[] = $this->last_query;

		if ( preg_match( '/^\s*DESCRIBE\s+`?([A-Za-z0-9_]+)`?\s*;?\s*$/i', $this->last_query, $matches ) ) {
			return $this->describe_table( $matches[1] );
		}

		if ( preg_match( '/^\s*SHOW\s+INDEX(?:ES)?\s+FROM\s+`?([A-Za-z0-9_]+)`?/i', $this->last_query, $matches ) ) {
			return $this->show_index( $matches[1] );
		}

		return array();
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		unset( $x, $y );

		$this->last_query = (string) $query;
		$this->introspection_log[] = $this->last_query;
		if ( preg_match( "/SHOW\s+TABLES\s+LIKE\s+'([^']+)'/i", $this->last_query, $matches ) ) {
			return isset( $this->schemas[ $matches[1] ] ) ? $matches[1] : null;
		}

		return null;
	}

	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
		unset( $output, $y );

		$this->last_query = (string) $query;
		$this->introspection_log[] = $this->last_query;
		$this->num_rows = 0;
		return null;
	}

	public function get_col( $query = null, $x = 0 ): array {
		unset( $x );

		$this->last_query = (string) $query;
		$this->introspection_log[] = $this->last_query;
		$this->num_rows = 0;
		return array();
	}

	public function query( $query ) {
		$this->last_query = (string) $query;
		$this->executed_queries[] = $this->last_query;
		$this->record_allowlist_violations( $this->last_query );
		return 0;
	}

	public function _escape( $data ) {
		if ( is_array( $data ) ) {
			return array_map( array( $this, '_escape' ), $data );
		}

		return addslashes( (string) $data );
	}

	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index = 0;
		return (string) preg_replace_callback(
			'/%[sd]/',
			function () use ( &$index, $args ): string {
				$value = $args[ $index++ ] ?? '';
				return "'" . addslashes( (string) $value ) . "'";
			},
			(string) $query
		);
	}

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	private function refresh_table_properties(): void {
		foreach ( $this->tables( 'all' ) + $this->tables( 'old' ) as $name => $table ) {
			$this->$name = $table;
		}
		foreach ( $this->tables( 'ms_global' ) as $name => $table ) {
			$this->$name = $table;
		}
	}

	private function describe_table( string $table ): array {
		if ( ! isset( $this->schemas[ $table ] ) ) {
			$this->num_rows = 0;
			return array();
		}

		$rows = array();
		foreach ( $this->schemas[ $table ]['columns'] as $name => $column ) {
			$rows[] = (object) array(
				'Field'   => $name,
				'Type'    => $column['type'],
				'Null'    => $column['nullable'] ? 'YES' : 'NO',
				'Key'     => $this->column_key( $table, $name ),
				'Default' => $column['default'],
				'Extra'   => $column['extra'],
			);
		}
		$this->num_rows = count( $rows );
		return $rows;
	}

	private function show_index( string $table ): array {
		if ( ! isset( $this->schemas[ $table ] ) ) {
			$this->num_rows = 0;
			return array();
		}

		$rows = array();
		foreach ( $this->schemas[ $table ]['indexes'] as $index ) {
			$sequence = 1;
			foreach ( $index['columns'] as $column ) {
				$rows[] = (object) array(
					'Table'        => $table,
					'Non_unique'   => $index['showIndexNonUnique'],
					'Key_name'     => $index['showIndexKeyName'],
					'Seq_in_index' => $sequence++,
					'Column_name'  => $column['name'],
					'Sub_part'     => $column['subpart'],
					'Index_type'   => $index['showIndexType'],
				);
			}
		}
		$this->num_rows = count( $rows );
		return $rows;
	}

	private function column_key( string $table, string $column_name ): string {
		foreach ( $this->schemas[ $table ]['indexes'] ?? array() as $index ) {
			if ( 'PRIMARY' === $index['showIndexKeyName'] && $column_name === $index['columns'][0]['name'] ) {
				return 'PRI';
			}
		}
		return '';
	}

	private function record_allowlist_violations( string $query ): void {
		$patterns = array(
			'/\bALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
			'/\bCREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
			'/\bINSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?/i',
			'/\bUPDATE\s+`?([A-Za-z0-9_]+)`?/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $query, $matches ) && ! isset( $this->allowed_tables[ $matches[1] ] ) ) {
				$this->violations[] = array(
					'table' => $matches[1],
					'query' => $query,
				);
			}
		}
	}
}
