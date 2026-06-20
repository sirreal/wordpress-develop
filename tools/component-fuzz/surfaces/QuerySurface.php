<?php
namespace ComponentFuzz\Surfaces;

final class QuerySurface {
	public const NAME = 'query';

	private const GENERATED_CASES = 6;
	private const SAMPLE_BYTES     = 180;
	private const TAXONOMY         = 'component_fuzz_tax';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'query.bootstrap-apis-available',
					'Required WordPress query APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();

		try {
			self::install_scoped_globals();
			self::register_scoped_taxonomies();

			$rows = array_merge( $rows, self::check_meta_queries( $ctx ) );
			$rows = array_merge( $rows, self::check_tax_queries( $ctx ) );
			$rows = array_merge( $rows, self::check_date_queries( $ctx ) );
			$rows = array_merge( $rows, self::check_wp_query_parsing( $ctx ) );
			$rows = array_merge( $rows, self::check_user_query_parsing( $ctx ) );
			$rows = array_merge( $rows, self::check_comment_query_parsing( $ctx ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'query.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Date_Query',
				'WP_Meta_Query',
				'WP_Tax_Query',
				'WP_Query',
				'WP_User_Query',
				'WP_Comment_Query',
				'WP_Taxonomy',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_get_meta_table',
				'add_filter',
				'register_taxonomy',
				'sanitize_key',
				'taxonomy_exists',
				'wp_parse_args',
				'wp_parse_id_list',
				'wp_parse_list',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_meta_queries( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::meta_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::meta_observation( $case['query'] );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.meta.no-throw-sql-shape',
				$call['ok'] && self::is_meta_observation( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! self::is_meta_observation( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.meta.sanitized-tree-idempotent',
				self::meta_sanitized_tree_is_idempotent( $case['query'] ),
				array( 'queries' => self::describe_value( $observation['queries'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.meta.relations-normalized',
				self::relations_are_normalized( $observation['queries'] )
					&& in_array( $observation['relation'], array( 'AND', 'OR', null ), true ),
				array(
					'relation' => $observation['relation'],
					'queries'  => self::describe_value( $observation['queries'] ),
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.meta.sql-balanced-deterministic',
				self::sql_fragment_is_balanced( $observation['sql'] )
					&& self::sql_has_no_unexpanded_placeholders( $observation['sql'] )
					&& self::meta_sql_is_deterministic( $case['query'] ),
				array( 'sql' => self::describe_value( $observation['sql'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.meta.or-relation-reflected',
				! isset( $case['expect']['hasOr'] ) || $case['expect']['hasOr'] === $observation['hasOr'],
				array(
					'expected' => $case['expect']['hasOr'] ?? null,
					'actual'   => $observation['hasOr'],
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.meta.expected-sql-features',
				self::sql_expectations_match( $observation['sql'], $case['expect'] ?? array() )
					&& self::meta_clause_expectations_match( $observation['clauses'], $case['expect'] ?? array() ),
				array(
					'expect'  => $case['expect'] ?? array(),
					'sql'     => self::describe_value( $observation['sql'] ),
					'clauses' => self::describe_value( $observation['clauses'] ),
				)
			);
		}

		$rows = array_merge( $rows, self::check_meta_parse_query_vars( $ctx, count( $cases ) ) );
		$rows = array_merge( $rows, self::check_meta_cast_oracles( $ctx, count( $cases ) + 1 ) );

		return $rows;
	}

	private static function check_tax_queries( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::tax_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::tax_observation( $case['query'] );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.tax.no-throw-sql-shape',
				$call['ok'] && self::is_tax_observation( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! self::is_tax_observation( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.tax.sanitized-tree-idempotent',
				self::tax_sanitized_tree_is_idempotent( $case['query'] ),
				array( 'queries' => self::describe_value( $observation['queries'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.tax.relations-normalized',
				self::relations_are_normalized( $observation['queries'] )
					&& in_array( $observation['relation'], array( 'AND', 'OR' ), true ),
				array(
					'relation' => $observation['relation'],
					'queries'  => self::describe_value( $observation['queries'] ),
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.tax.sql-balanced-deterministic',
				self::sql_fragment_is_balanced( $observation['sql'] )
					&& self::sql_has_no_unexpanded_placeholders( $observation['sql'] )
					&& self::tax_sql_is_deterministic( $case['query'] ),
				array( 'sql' => self::describe_value( $observation['sql'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.tax.expected-sql-features',
				self::sql_expectations_match( $observation['sql'], $case['expect'] ?? array() ),
				array(
					'expect' => $case['expect'] ?? array(),
					'sql'    => self::describe_value( $observation['sql'] ),
				)
			);
		}

		return $rows;
	}

	private static function check_date_queries( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::date_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::date_observation( $case['query'], $case['defaultColumn'] ?? 'post_date' );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.date.no-throw-sql-string',
				$call['ok'] && self::is_date_observation( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! self::is_date_observation( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.date.sanitized-tree-idempotent',
				self::date_sanitized_tree_is_idempotent( $case['query'], $case['defaultColumn'] ?? 'post_date' ),
				array( 'queries' => self::describe_value( $observation['queries'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.date.relations-and-column-normalized',
				self::relations_are_normalized( $observation['queries'] )
					&& in_array( $observation['relation'], array( 'AND', 'OR' ), true )
					&& self::safe_sql_identifier( $observation['column'] ),
				array(
					'relation' => $observation['relation'],
					'column'   => $observation['column'],
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.date.sql-balanced-deterministic',
				self::sql_string_is_balanced( $observation['sql'] )
					&& self::sql_has_no_unexpanded_placeholders( $observation['sql'] )
					&& self::date_sql_is_deterministic( $case['query'], $case['defaultColumn'] ?? 'post_date' ),
				array( 'sql' => self::describe_value( $observation['sql'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.date.expected-sql-features',
				self::sql_expectations_match( $observation['sql'], $case['expect'] ?? array() ),
				array(
					'expect' => $case['expect'] ?? array(),
					'sql'    => self::describe_value( $observation['sql'] ),
				)
			);
		}

		$rows = array_merge( $rows, self::check_date_oracles( $ctx, count( $cases ) ) );

		return $rows;
	}

	private static function check_wp_query_parsing( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::wp_query_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::wp_query_parse_observation( $case['queryVars'] );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.parse-query-no-db-no-throw',
				$call['ok'] && is_array( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];
			$first       = $observation['first'];
			$second      = $observation['second'];

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.parse-query-vars-idempotent',
				$first === $second,
				array( 'summary' => self::describe_value( $first ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.query-vars-normalized-safe',
				self::query_vars_are_scalar_array_safe( $first['queryVars'] )
					&& self::wp_query_normalization_holds( $first['queryVars'] ),
				array( 'queryVars' => self::describe_value( $first['queryVars'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.flags-consistent',
				self::wp_query_flags_are_consistent( $first['flags'] ),
				array( 'flags' => self::describe_value( $first['flags'] ) )
			);
		}

		return $rows;
	}

	private static function check_user_query_parsing( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::user_query_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::user_query_parse_observation( $case['queryVars'] );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.user-query.prepare-query-no-db-no-throw',
				$call['ok'] && is_array( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.user-query.fill-query-vars-complete',
				self::user_query_defaults_present( $observation['queryVars'] )
					&& self::query_vars_are_scalar_array_safe( $observation['queryVars'] ),
				array( 'queryVars' => self::describe_value( $observation['queryVars'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.user-query.sql-parts-balanced',
				self::sql_string_is_balanced( $observation['sql'] )
					&& self::sql_has_no_unexpanded_placeholders( $observation['sql'] )
					&& str_contains( $observation['queryFrom'], 'wp_users' ),
				array( 'sql' => self::describe_value( $observation['sql'] ) )
			);

			$second = self::call_guarded(
				static function () use ( $case ) {
					return self::user_query_parse_observation( $case['queryVars'] );
				}
			);
			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.user-query.prepare-query-deterministic',
				$second['ok'] && $observation === $second['value'],
				array( 'second' => self::describe_call( $second ) )
			);
		}

		return $rows;
	}

	private static function check_comment_query_parsing( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::comment_query_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::comment_query_parse_observation( $case['queryVars'] );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.comment-query.parse-query-no-db-no-throw',
				$call['ok'] && is_array( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];
			$second      = self::call_guarded(
				static function () use ( $case ) {
					return self::comment_query_parse_observation( $case['queryVars'] );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.comment-query.defaults-complete-safe',
				self::comment_query_defaults_present( $observation['queryVars'] )
					&& self::query_vars_are_scalar_array_safe( $observation['queryVars'] ),
				array( 'queryVars' => self::describe_value( $observation['queryVars'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.comment-query.parse-query-deterministic',
				$second['ok'] && $observation === $second['value'],
				array( 'second' => self::describe_call( $second ) )
			);
		}

		return $rows;
	}

	private static function check_meta_parse_query_vars( \ComponentFuzz\FuzzContext $ctx, int $case_offset ): array {
		$rows  = array();
		$cases = self::meta_query_var_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					$query = new \WP_Meta_Query();
					$query->parse_query_vars( $case['queryVars'] );
					$sql = $query->get_sql( 'post', $GLOBALS['wpdb']->posts, 'ID' );

					return array(
						'queries'  => $query->queries,
						'relation' => $query->relation ?? null,
						'sql'      => $sql,
						'clauses'  => $query->get_clauses(),
					);
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_offset + $case_index,
				$case,
				'query.meta.parse-query-vars-no-throw',
				$call['ok'] && is_array( $call['value'] ) && self::is_sql_fragment( $call['value']['sql'] ?? null ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
				continue;
			}

			$rows[] = self::case_result(
				$ctx,
				$case_offset + $case_index,
				$case,
				'query.meta.parse-query-vars-primary-clause-first',
				self::primary_meta_clause_is_first_when_expected( $case['queryVars'], $call['value']['queries'] ),
				array( 'queries' => self::describe_value( $call['value']['queries'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_offset + $case_index,
				$case,
				'query.meta.parse-query-vars-sql-balanced',
				self::sql_fragment_is_balanced( $call['value']['sql'] )
					&& self::sql_has_no_unexpanded_placeholders( $call['value']['sql'] ),
				array( 'sql' => self::describe_value( $call['value']['sql'] ) )
			);
		}

		return $rows;
	}

	private static function check_meta_cast_oracles( \ComponentFuzz\FuzzContext $ctx, int $case_index ): array {
		$query   = new \WP_Meta_Query();
		$oracles = array(
			''              => 'CHAR',
			'CHAR'          => 'CHAR',
			'NUMERIC'       => 'SIGNED',
			'numeric'       => 'SIGNED',
			'SIGNED'        => 'SIGNED',
			'UNSIGNED'      => 'UNSIGNED',
			'DECIMAL(10,2)' => 'DECIMAL(10,2)',
			'BAD TYPE'      => 'CHAR',
			'varchar(255)'  => 'CHAR',
		);
		$actual  = array();

		foreach ( $oracles as $input => $expected ) {
			$actual[ $input ] = $query->get_cast_for_type( $input );
		}

		return array(
			self::case_result(
				$ctx,
				$case_index,
				array( 'label' => 'meta-cast-oracles' ),
				'query.meta.cast-oracles',
				$oracles === $actual,
				array(
					'expected' => $oracles,
					'actual'   => $actual,
				)
			),
		);
	}

	private static function check_date_oracles( \ComponentFuzz\FuzzContext $ctx, int $case_index ): array {
		$query = new \WP_Date_Query( array() );

		$datetime_oracles = array(
			'year-min'     => $query->build_mysql_datetime( '2020', false ),
			'year-max'     => $query->build_mysql_datetime( '2020', true ),
			'month-max'    => $query->build_mysql_datetime( '2020-02', true ),
			'day-min'      => $query->build_mysql_datetime( '2020-02-29', false ),
			'array-max'    => $query->build_mysql_datetime( array( 'year' => 2020, 'month' => 2 ), true ),
			'invalid-text' => $query->build_mysql_datetime( 'not-a-date', false ),
		);

		$expected_datetime = array(
			'year-min'     => '2020-01-01 00:00:00',
			'year-max'     => '2020-12-31 23:59:59',
			'month-max'    => '2020-02-29 23:59:59',
			'day-min'      => '2020-02-29 00:00:00',
			'array-max'    => '2020-02-29 23:59:59',
			'invalid-text' => '1970-01-01 00:00:00',
		);

		$value_oracles = array(
			'in'          => $query->build_value( 'IN', array( 1, 'x', 3 ) ),
			'between'     => $query->build_value( 'BETWEEN', array( 2, 5 ) ),
			'default-int' => $query->build_value( '=', '7' ),
			'bad-scalar'  => $query->build_value( '=', 'seven' ),
		);

		$expected_values = array(
			'in'          => '(1,3)',
			'between'     => '2 AND 5',
			'default-int' => 7,
			'bad-scalar'  => false,
		);

		$column_oracles = array(
			'post-date'       => $query->validate_column( 'post_date' ),
			'user-registered' => $query->validate_column( 'user_registered' ),
			'unknown'         => $query->validate_column( 'unknown_column' ),
			'prefixed-clean'  => $query->validate_column( 'wp_posts.post_date;DROP' ),
		);

		$column_ok = 'wp_posts.post_date' === $column_oracles['post-date']
			&& 'wp_users.user_registered' === $column_oracles['user-registered']
			&& 'wp_posts.post_date' === $column_oracles['unknown']
			&& self::safe_sql_identifier( $column_oracles['prefixed-clean'] );

		return array(
			self::case_result(
				$ctx,
				$case_index,
				array( 'label' => 'date-datetime-oracles' ),
				'query.date.datetime-boundary-oracles',
				$expected_datetime === $datetime_oracles,
				array(
					'expected' => $expected_datetime,
					'actual'   => $datetime_oracles,
				)
			),
			self::case_result(
				$ctx,
				$case_index + 1,
				array( 'label' => 'date-value-oracles' ),
				'query.date.value-builder-oracles',
				$expected_values === $value_oracles,
				array(
					'expected' => $expected_values,
					'actual'   => $value_oracles,
				)
			),
			self::case_result(
				$ctx,
				$case_index + 2,
				array( 'label' => 'date-column-oracles' ),
				'query.date.column-validation-oracles',
				$column_ok,
				array( 'actual' => $column_oracles )
			),
		);
	}

	private static function meta_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'  => 'empty-meta-query',
				'query'  => array(),
				'expect' => array( 'noOp' => true ),
			),
			array(
				'label'  => 'simple-key-value',
				'query'  => array(
					array(
						'key'     => 'color',
						'value'   => 'blue',
						'compare' => '=',
						'type'    => 'CHAR',
					),
				),
				'expect' => array(
					'joinContains'  => array( 'wp_postmeta' ),
					'whereContains' => array( 'meta_key', "'color'", 'meta_value', "'blue'" ),
					'casts'         => array( 'CHAR' ),
				),
			),
			array(
				'label'  => 'or-positive-shared-alias',
				'query'  => array(
					'relation' => 'OR',
					array(
						'key'     => 'rating',
						'value'   => array( 4, 5 ),
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => 'score',
						'value'   => array( 7, 9 ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
				'expect' => array(
					'hasOr'         => true,
					'whereContains' => array( ' OR ', 'CAST(wp_postmeta.meta_value AS SIGNED)' ),
					'casts'         => array( 'SIGNED' ),
				),
			),
			array(
				'label'  => 'not-exists-left-join',
				'query'  => array(
					array(
						'key'     => 'archived',
						'compare' => 'NOT EXISTS',
						'value'   => 'ignored',
					),
				),
				'expect' => array(
					'joinContains'  => array( 'LEFT JOIN wp_postmeta' ),
					'whereContains' => array( 'wp_postmeta.post_id IS NULL' ),
				),
			),
			array(
				'label'  => 'between-date-with-slashed-unicode',
				'query'  => array(
					'relation' => 'xor',
					array(
						'key'         => "event_☃\\path",
						'compare_key' => 'LIKE',
						'value'       => array( '2020-01-01', '2020-12-31' ),
						'compare'     => 'BETWEEN',
						'type'        => 'DATE',
					),
				),
				'expect' => array(
					'whereContains' => array( 'meta_key LIKE', '☃', 'path', 'CAST(wp_postmeta.meta_value AS DATE)', 'BETWEEN' ),
					'casts'         => array( 'DATE' ),
				),
			),
			array(
				'label'  => 'key-array-not-in-value-array',
				'query'  => array(
					'named_clause' => array(
						'key'         => array( 'alpha', 'beta' ),
						'compare_key' => 'IN',
						'value'       => array( 'draft', "quote'value" ),
						'compare'     => 'NOT IN',
						'type'        => 'BINARY',
					),
				),
				'expect' => array(
					'whereContains' => array( 'meta_key IN', 'NOT IN' ),
					'casts'         => array( 'BINARY' ),
				),
			),
			array(
				'label'  => 'nested-and-or-empty-bad-operators',
				'query'  => array(
					'relation' => 'AND',
					array(),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_private',
							'value'   => '1',
							'compare' => 'BAD',
							'type'    => 'NOT_A_TYPE',
						),
						array(
							'key'     => 'path',
							'value'   => "C:\\Temp\\file",
							'compare' => 'LIKE',
						),
					),
				),
				'expect' => array(
					'hasOr'         => true,
					'whereContains' => array( '_private', 'path', 'LIKE' ),
					'casts'         => array( 'CHAR' ),
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'meta-' . $i );
			$cases[]  = array(
				'label'  => 'generated-meta-' . $i,
				'query'  => self::generated_meta_query( $case_ctx, 0 ),
				'expect' => array(),
			);
		}

		return $cases;
	}

	private static function tax_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'  => 'empty-tax-query',
				'query'  => array(),
				'expect' => array( 'noOp' => true ),
			),
			array(
				'label'  => 'term-taxonomy-id-in',
				'query'  => array(
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => array( 3, '7', -2, 'bad' ),
						'operator'         => 'IN',
						'include_children' => false,
					),
				),
				'expect' => array(
					'joinContains'  => array( 'LEFT JOIN wp_term_relationships' ),
					'whereContains' => array( 'term_taxonomy_id IN (3,7,2,0)' ),
				),
			),
			array(
				'label'  => 'empty-in-no-results',
				'query'  => array(
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => array(),
						'operator'         => 'IN',
						'include_children' => false,
					),
				),
				'expect' => array(
					'whereContains' => array( '0 = 1' ),
				),
			),
			array(
				'label'  => 'not-in-subquery',
				'query'  => array(
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => array( 11, 13 ),
						'operator'         => 'NOT IN',
						'include_children' => false,
					),
				),
				'expect' => array(
					'whereContains' => array( 'wp_posts.ID NOT IN', 'term_taxonomy_id IN (11,13)' ),
				),
			),
			array(
				'label'  => 'and-count-subquery',
				'query'  => array(
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => array( 17, 19 ),
						'operator'         => 'AND',
						'include_children' => false,
					),
				),
				'expect' => array(
					'whereContains' => array( 'SELECT COUNT(1)', ') = 2' ),
				),
			),
			array(
				'label'  => 'exists-operator',
				'query'  => array(
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => array(),
						'operator'         => 'EXISTS',
						'include_children' => false,
					),
				),
				'expect' => array(
					'whereContains' => array( 'EXISTS', 'wp_term_taxonomy.taxonomy', "'" . self::TAXONOMY . "'" ),
				),
			),
			array(
				'label'  => 'nested-or-reuses-in-alias',
				'query'  => array(
					'relation' => 'OR',
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => array( 23 ),
						'operator'         => 'IN',
						'include_children' => false,
					),
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_taxonomy_id',
						'terms'            => array( 29 ),
						'operator'         => 'IN',
						'include_children' => false,
					),
				),
				'expect' => array(
					'whereContains' => array( ' OR ', 'wp_term_relationships.term_taxonomy_id IN (23)' ),
					'joinContains'  => array( 'wp_term_relationships' ),
				),
			),
			array(
				'label'  => 'invalid-taxonomy-no-results',
				'query'  => array(
					array(
						'taxonomy' => 'missing_tax',
						'field'    => 'slug',
						'terms'    => array( 'alpha' ),
						'operator' => 'IN',
					),
				),
				'expect' => array(
					'whereContains' => array( '0 = 1' ),
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'tax-' . $i );
			$cases[]  = array(
				'label'  => 'generated-tax-' . $i,
				'query'  => self::generated_tax_query( $case_ctx, 0 ),
				'expect' => array(),
			);
		}

		return $cases;
	}

	private static function date_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'         => 'empty-date-query',
				'query'         => array(),
				'defaultColumn' => 'post_date',
				'expect'        => array( 'emptyString' => true ),
			),
			array(
				'label'         => 'inclusive-after-before',
				'query'         => array(
					array(
						'after'     => '2020-01',
						'before'    => '2020-02',
						'inclusive' => true,
					),
				),
				'defaultColumn' => 'post_date',
				'expect'        => array(
					'contains' => array( 'wp_posts.post_date >=', '2020-01-01 00:00:00', 'wp_posts.post_date <=', '2020-02-29 23:59:59' ),
				),
			),
			array(
				'label'         => 'exclusive-range',
				'query'         => array(
					array(
						'after'     => array( 'year' => 2020, 'month' => 2, 'day' => 29 ),
						'before'    => array( 'year' => 2021 ),
						'inclusive' => false,
					),
				),
				'defaultColumn' => 'post_date',
				'expect'        => array(
					'contains' => array( 'wp_posts.post_date >', '2020-02-29 23:59:59', 'wp_posts.post_date <', '2021-01-01 00:00:00' ),
				),
			),
			array(
				'label'         => 'date-units-between',
				'query'         => array(
					array(
						'column'  => 'comment_date',
						'compare' => 'BETWEEN',
						'year'    => array( 2019, 2021 ),
						'month'   => array( 1, 12 ),
						'day'     => array( 1, 31 ),
					),
				),
				'defaultColumn' => 'post_date',
				'expect'        => array(
					'contains' => array( 'YEAR( wp_comments.comment_date ) BETWEEN 2019 AND 2021', 'MONTH( wp_comments.comment_date ) BETWEEN 1 AND 12' ),
				),
			),
			array(
				'label'         => 'invalid-values-and-column-fallback',
				'query'         => array(
					array(
						'column' => 'not_a_column',
						'year'   => 2020,
						'month'  => 13,
						'day'    => 32,
						'hour'   => 25,
					),
				),
				'defaultColumn' => 'post_date',
				'expect'        => array(
					'contains' => array( 'wp_posts.post_date' ),
				),
			),
			array(
				'label'         => 'nested-or-time-query',
				'query'         => array(
					'relation' => 'OR',
					array(
						'hour'    => 9,
						'minute'  => 30,
						'compare' => '>=',
					),
					array(
						'relation' => 'AND',
						array(
							'dayofweek_iso' => array( 1, 5 ),
							'compare'       => 'IN',
						),
						array(
							'second'  => array( 0, 30 ),
							'compare' => 'NOT IN',
						),
					),
				),
				'defaultColumn' => 'user_registered',
				'expect'        => array(
					'contains' => array( 'DATE_FORMAT( wp_users.user_registered', ' OR ', 'WEEKDAY( wp_users.user_registered ) + 1 IN (1,5)' ),
				),
			),
			array(
				'label'         => 'bad-relation-and-compare',
				'query'         => array(
					'relation' => 'xor',
					array(
						'compare' => 'BAD',
						'year'    => '2024',
					),
				),
				'defaultColumn' => 'post_modified_gmt',
				'expect'        => array(
					'contains' => array( 'YEAR( wp_posts.post_modified_gmt ) = 2024' ),
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'date-' . $i );
			$cases[]  = array(
				'label'         => 'generated-date-' . $i,
				'query'         => self::generated_date_query( $case_ctx, 0 ),
				'defaultColumn' => $case_ctx->choice( array( 'post_date', 'post_modified', 'comment_date', 'user_registered', 'bad_column' ) ),
				'expect'        => array(),
			);
		}

		return $cases;
	}

	private static function wp_query_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$long_search = str_repeat( 's', 1700 );
		$cases       = array(
			array(
				'label'     => 'ids-and-date-normalization',
				'queryVars' => array(
					'p'            => '-7',
					'page_id'      => '12',
					'year'         => '2020',
					'monthnum'     => '13',
					'day'          => '31',
					'cat'          => '1, -2, bad',
					'author'       => '4,-5,x',
					's'            => 'alpha "quoted phrase" -exclude',
					'post_type'    => array( 'post', 'Bad Type', 'page' ),
					'post_status'  => array( 'publish', 'Draft!', 'private' ),
					'category__in' => array( '3', '1', 'bad' ),
				),
			),
			array(
				'label'     => 'tax-meta-date-arrays',
				'queryVars' => array(
					'tax_query'          => array(
						array(
							'taxonomy'         => self::TAXONOMY,
							'field'            => 'term_taxonomy_id',
							'terms'            => array( 5, 9 ),
							'include_children' => false,
						),
					),
					'meta_query'         => self::meta_cases( $ctx )[1]['query'],
					'date_query'         => self::date_cases( $ctx )[1]['query'],
					'post__in'           => array( '4', '2', 'bad' ),
					'post_name__in'      => array( 'Beta', 'alpha slug' ),
					'author__not_in'     => array( 8, 'bad' ),
					'ignore_sticky_posts' => true,
				),
			),
			array(
				'label'     => 'long-search-cleared',
				'queryVars' => array(
					's'       => $long_search,
					'paged'   => '-4',
					'feed'    => 'comments-rss2',
					'preview' => '1',
					'embed'   => '1',
				),
			),
			array(
				'label'     => 'time-and-m-string',
				'queryVars' => array(
					'm'          => '2020-02-29 09:30',
					'hour'       => '09',
					'minute'     => 'bad',
					'second'     => '59',
					'menu_order' => '-8',
					'fields'     => 'ids',
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'wp-query-' . $i );
			$cases[]  = array(
				'label'     => 'generated-wp-query-' . $i,
				'queryVars' => self::generated_wp_query_vars( $case_ctx ),
			);
		}

		return $cases;
	}

	private static function user_query_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'     => 'fields-include-search-meta',
				'queryVars' => array(
					'blog_id'        => 0,
					'fields'         => array( 'ID', 'user_email', 'invalid', 'display_name' ),
					'include'        => array( '5', 'bad', '2' ),
					'search'         => '*admin@example.com*',
					'search_columns' => array( 'user_email', 'bad' ),
					'orderby'        => array( 'include' => 'ASC', 'user_login' => 'DESC' ),
					'order'          => 'desc',
					'number'         => '10',
					'paged'          => '2',
					'count_total'    => false,
					'meta_key'       => 'fuzz_key',
					'meta_value'     => '42',
					'meta_compare'   => '>=',
					'meta_type'      => 'NUMERIC',
				),
			),
			array(
				'label'     => 'exclude-login-date',
				'queryVars' => array(
					'blog_id'       => 0,
					'fields'        => 'user_login',
					'exclude'       => array( 9, '11x' ),
					'login__in'     => array( 'admin', "quote'user" ),
					'nicename'      => 'nice-name',
					'date_query'    => array(
						array(
							'after'     => '2020',
							'inclusive' => true,
						),
					),
					'count_total'   => false,
					'cache_results' => false,
				),
			),
			array(
				'label'     => 'meta-query-orderby',
				'queryVars' => array(
					'blog_id'     => 0,
					'fields'      => 'ID',
					'meta_query'  => self::meta_cases( $ctx )[2]['query'],
					'orderby'     => 'meta_value_num',
					'order'       => 'ASC',
					'number'      => -1,
					'count_total' => false,
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'user-query-' . $i );
			$cases[]  = array(
				'label'     => 'generated-user-query-' . $i,
				'queryVars' => self::generated_user_query_vars( $case_ctx ),
			);
		}

		return $cases;
	}

	private static function comment_query_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'     => 'status-type-author',
				'queryVars' => array(
					'author_email' => "person@example.test\n",
					'status'       => array( 'approve', 'hold', 'bad' ),
					'type__in'     => array( 'comment', 'pingback', 'note' ),
					'post__in'     => array( '4', 'bad', '9' ),
					'number'       => '5',
					'offset'       => '2',
					'orderby'      => 'comment_date_gmt',
				),
			),
			array(
				'label'     => 'meta-date-include-unapproved',
				'queryVars' => array(
					'include_unapproved'        => array( 'pending@example.test', 99 ),
					'comment__not_in'           => array( 1, '2x' ),
					'meta_query'                => self::meta_cases( $ctx )[1]['query'],
					'date_query'                => self::date_cases( $ctx )[1]['query'],
					'hierarchical'              => 'threaded',
					'update_comment_meta_cache' => false,
					'update_comment_post_cache' => false,
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'comment-query-' . $i );
			$cases[]  = array(
				'label'     => 'generated-comment-query-' . $i,
				'queryVars' => self::generated_comment_query_vars( $case_ctx ),
			);
		}

		return $cases;
	}

	private static function meta_query_var_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			array(
				'label'     => 'primary-only',
				'queryVars' => array(
					'meta_key'         => 'primary_key',
					'meta_value'       => 'primary value',
					'meta_compare'     => 'LIKE',
					'meta_type'        => 'CHAR',
					'meta_compare_key' => '=',
					'meta_type_key'    => '',
				),
			),
			array(
				'label'     => 'primary-plus-existing',
				'queryVars' => array(
					'meta_key'     => 'primary_key',
					'meta_value'   => '12',
					'meta_compare' => '>=',
					'meta_type'    => 'NUMERIC',
					'meta_query'   => self::generated_meta_query( $ctx->fork( 'parse-query-vars' ), 0 ),
				),
			),
			array(
				'label'     => 'existing-only',
				'queryVars' => array(
					'meta_query' => self::meta_cases( $ctx )[4]['query'],
				),
			),
		);
	}

	private static function generated_meta_query( \ComponentFuzz\FuzzContext $ctx, int $depth ): array {
		if ( $depth >= 3 || $ctx->bool( 60 ) ) {
			return self::generated_meta_clause( $ctx );
		}

		$query = array(
			'relation' => self::random_relation( $ctx ),
		);
		$count = $ctx->int( 0, 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$value = $ctx->bool( 25 ) ? self::generated_meta_query( $ctx->fork( 'nested-' . $depth . '-' . $i ), $depth + 1 ) : self::generated_meta_clause( $ctx->fork( 'clause-' . $depth . '-' . $i ) );
			if ( $ctx->bool( 25 ) ) {
				$query[ 'named_' . $depth . '_' . $i ] = $value;
			} else {
				$query[] = $value;
			}
		}

		if ( $ctx->bool( 20 ) ) {
			$query[] = array();
		}

		return $query;
	}

	private static function generated_meta_clause( \ComponentFuzz\FuzzContext $ctx ): array {
		$compare     = $ctx->choice( array( '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE', 'BAD' ) );
		$compare_key = $ctx->choice( array( '=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE', 'BAD' ) );
		$clause      = array(
			'key'         => in_array( $compare_key, array( 'IN', 'NOT IN' ), true ) ? self::random_meta_key_list( $ctx ) : self::random_meta_key( $ctx ),
			'compare_key' => $compare_key,
			'compare'     => $compare,
			'type'        => $ctx->choice( array( 'CHAR', 'NUMERIC', 'SIGNED', 'UNSIGNED', 'DATE', 'DATETIME', 'DECIMAL(10,2)', 'BINARY', 'BAD TYPE' ) ),
			'type_key'    => $ctx->choice( array( '', 'BINARY', 'CHAR' ) ),
		);

		if ( 'NOT EXISTS' !== $compare || $ctx->bool( 35 ) ) {
			$clause['value'] = self::random_meta_value_for_compare( $ctx, $compare );
		}

		if ( $ctx->bool( 12 ) ) {
			unset( $clause['value'] );
		}

		return $clause;
	}

	private static function generated_tax_query( \ComponentFuzz\FuzzContext $ctx, int $depth ): array {
		if ( $depth >= 3 || $ctx->bool( 65 ) ) {
			return self::generated_tax_clause( $ctx );
		}

		$query = array(
			'relation' => self::random_relation( $ctx ),
		);
		$count = $ctx->int( 0, 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$query[] = $ctx->bool( 25 ) ? self::generated_tax_query( $ctx->fork( 'nested-' . $depth . '-' . $i ), $depth + 1 ) : self::generated_tax_clause( $ctx->fork( 'clause-' . $depth . '-' . $i ) );
		}

		if ( $ctx->bool( 20 ) ) {
			$query[] = array();
		}

		return $query;
	}

	private static function generated_tax_clause( \ComponentFuzz\FuzzContext $ctx ): array {
		$operator = $ctx->choice( array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS', 'BAD' ) );
		$field    = $ctx->choice( array( 'term_taxonomy_id', 'slug', 'name' ) );
		$terms    = in_array( $operator, array( 'EXISTS', 'NOT EXISTS' ), true ) || 'term_taxonomy_id' !== $field
			? array()
			: self::random_id_list( $ctx, 0, 4 );

		return array(
			'taxonomy'         => $ctx->choice( array( self::TAXONOMY, self::TAXONOMY, '', 'missing_tax' ) ),
			'field'            => $field,
			'terms'            => $terms,
			'operator'         => $operator,
			'include_children' => false,
		);
	}

	private static function generated_date_query( \ComponentFuzz\FuzzContext $ctx, int $depth ): array {
		if ( $depth >= 3 || $ctx->bool( 65 ) ) {
			return self::generated_date_clause( $ctx );
		}

		$query = array(
			'relation' => self::random_relation( $ctx ),
		);
		$count = $ctx->int( 0, 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$query[] = $ctx->bool( 35 ) ? self::generated_date_query( $ctx->fork( 'nested-' . $depth . '-' . $i ), $depth + 1 ) : self::generated_date_clause( $ctx->fork( 'clause-' . $depth . '-' . $i ) );
		}

		return $query;
	}

	private static function generated_date_clause( \ComponentFuzz\FuzzContext $ctx ): array {
		$compare = $ctx->choice( array( '=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'BAD' ) );
		$clause  = array(
			'column'    => $ctx->choice( array( 'post_date', 'post_modified_gmt', 'comment_date', 'user_registered', 'unknown_column', 'wp_posts.post_date;DROP' ) ),
			'compare'   => $compare,
			'inclusive' => $ctx->bool(),
		);

		foreach ( array( 'year', 'month', 'day', 'dayofweek_iso', 'hour', 'minute', 'second' ) as $unit ) {
			if ( $ctx->bool( 35 ) ) {
				$clause[ $unit ] = self::random_date_unit_value( $ctx, $unit, $compare );
			}
		}

		if ( $ctx->bool( 35 ) ) {
			$clause['after'] = self::random_date_boundary( $ctx );
		}
		if ( $ctx->bool( 35 ) ) {
			$clause['before'] = self::random_date_boundary( $ctx );
		}

		if ( 0 === count( array_intersect( array_keys( $clause ), array( 'after', 'before', 'year', 'month', 'day', 'dayofweek_iso', 'hour', 'minute', 'second' ) ) ) ) {
			$clause['year'] = $ctx->int( 1990, 2035 );
		}

		return $clause;
	}

	private static function generated_wp_query_vars( \ComponentFuzz\FuzzContext $ctx ): array {
		$vars = array(
			'p'             => $ctx->choice( array( $ctx->int( -10, 50 ), (string) $ctx->int( -10, 50 ), array( 'bad' ) ) ),
			'page_id'       => $ctx->choice( array( $ctx->int( -10, 50 ), (string) $ctx->int( -10, 50 ), array() ) ),
			'year'          => $ctx->choice( array( $ctx->int( 0, 2035 ), '20x6' ) ),
			'monthnum'      => $ctx->choice( array( $ctx->int( 0, 15 ), '13' ) ),
			'day'           => $ctx->choice( array( $ctx->int( 0, 35 ), '31' ) ),
			's'             => $ctx->choice( array( self::random_string( $ctx ), str_repeat( 'x', $ctx->int( 0, 80 ) ) ) ),
			'post__in'      => self::random_id_list( $ctx, 0, 4 ),
			'post_type'     => $ctx->choice( array( 'post', array( 'post', 'Page Type', 'bad/type' ), self::random_string( $ctx ) ) ),
			'post_status'   => $ctx->choice( array( 'publish', array( 'publish', 'Draft!', self::random_string( $ctx ) ) ) ),
			'date_query'    => self::generated_date_query( $ctx->fork( 'wp-date' ), 0 ),
			'tax_query'     => array( self::generated_tax_clause( $ctx->fork( 'wp-tax' ) ) ),
			'meta_query'    => self::generated_meta_query( $ctx->fork( 'wp-meta' ), 0 ),
			'no_found_rows' => true,
		);

		if ( $ctx->bool( 30 ) ) {
			unset( $vars['tax_query'] );
		}
		if ( $ctx->bool( 30 ) ) {
			unset( $vars['meta_query'] );
		}

		return $vars;
	}

	private static function generated_user_query_vars( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'blog_id'        => 0,
			'fields'         => $ctx->choice( array( 'ID', 'user_email', array( 'ID', 'user_login', 'bad_field' ) ) ),
			'include'        => self::random_id_list( $ctx, 0, 4 ),
			'exclude'        => self::random_id_list( $ctx, 0, 4 ),
			'search'         => $ctx->choice( array( '', '*' . self::random_string( $ctx ) . '*', 'user@example.test' ) ),
			'search_columns' => $ctx->choice( array( array(), array( 'user_login', 'user_email', 'bad' ) ) ),
			'orderby'        => $ctx->choice( array( 'login', 'email', 'meta_value_num', array( 'user_login' => 'ASC', 'ID' => 'DESC' ) ) ),
			'order'          => $ctx->choice( array( 'ASC', 'DESC', 'sideways' ) ),
			'number'         => $ctx->choice( array( '', $ctx->int( -1, 20 ), (string) $ctx->int( 0, 20 ) ) ),
			'paged'          => $ctx->int( 0, 4 ),
			'count_total'    => $ctx->bool(),
			'meta_key'       => self::random_meta_key( $ctx ),
			'meta_value'     => self::random_string( $ctx ),
			'meta_compare'   => $ctx->choice( array( '=', 'LIKE', 'IN', 'NOT EXISTS', 'BAD' ) ),
			'meta_type'      => $ctx->choice( array( 'CHAR', 'NUMERIC', 'DATE', 'BAD TYPE' ) ),
			'date_query'     => self::generated_date_query( $ctx->fork( 'user-date' ), 0 ),
			'cache_results'  => false,
		);
	}

	private static function generated_comment_query_vars( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'author_email'              => $ctx->choice( array( 'person@example.test', "bad\nemail", self::random_string( $ctx ) ) ),
			'status'                    => $ctx->choice( array( 'all', 'approve', array( 'approve', 'hold', 'bad' ) ) ),
			'type'                      => $ctx->choice( array( '', 'comment', 'pingback', self::random_string( $ctx ) ) ),
			'type__in'                  => $ctx->choice( array( '', array( 'comment', 'trackback', 'note' ) ) ),
			'post__in'                  => self::random_id_list( $ctx, 0, 4 ),
			'comment__not_in'           => self::random_id_list( $ctx, 0, 4 ),
			'user_id'                   => $ctx->choice( array( '', $ctx->int( -5, 20 ), (string) $ctx->int( 0, 20 ) ) ),
			'search'                    => self::random_string( $ctx ),
			'number'                    => $ctx->choice( array( '', $ctx->int( -1, 10 ) ) ),
			'offset'                    => $ctx->choice( array( '', $ctx->int( -5, 10 ) ) ),
			'orderby'                   => $ctx->choice( array( '', 'comment_date', 'comment__in', 'meta_value_num' ) ),
			'meta_query'                => self::generated_meta_query( $ctx->fork( 'comment-meta' ), 0 ),
			'date_query'                => self::generated_date_query( $ctx->fork( 'comment-date' ), 0 ),
			'hierarchical'              => $ctx->choice( array( false, 'flat', 'threaded', 'bad' ) ),
			'update_comment_meta_cache' => false,
			'update_comment_post_cache' => false,
		);
	}

	private static function meta_observation( array $query_args ): array {
		$query = new \WP_Meta_Query( $query_args );
		$sql   = $query->get_sql( 'post', $GLOBALS['wpdb']->posts, 'ID' );

		return array(
			'queries'  => $query->queries,
			'relation' => $query->relation ?? null,
			'hasOr'    => $query->has_or_relation(),
			'sql'      => $sql,
			'clauses'  => $query->get_clauses(),
		);
	}

	private static function tax_observation( array $query_args ): array {
		$query = new \WP_Tax_Query( $query_args );
		$sql   = $query->get_sql( $GLOBALS['wpdb']->posts, 'ID' );

		return array(
			'queries'  => $query->queries,
			'relation' => $query->relation,
			'sql'      => $sql,
		);
	}

	private static function date_observation( array $query_args, string $default_column ): array {
		$query = new \WP_Date_Query( $query_args, $default_column );

		return array(
			'queries'  => $query->queries,
			'relation' => $query->relation,
			'column'   => $query->column,
			'compare'  => $query->compare,
			'sql'      => $query->get_sql(),
		);
	}

	private static function wp_query_parse_observation( array $query_vars ): array {
		$query = new \WP_Query();
		$query->parse_query( $query_vars );
		$first = self::wp_query_summary( $query );
		$query->parse_query_vars();
		$second = self::wp_query_summary( $query );

		return array(
			'first'  => $first,
			'second' => $second,
		);
	}

	private static function user_query_parse_observation( array $query_vars ): array {
		$query = new \WP_User_Query();
		$query->prepare_query( $query_vars );

		$sql = implode(
			' ',
			array_filter(
				array(
					$query->query_fields ?? '',
					$query->query_from ?? '',
					$query->query_where ?? '',
					$query->query_orderby ?? '',
					$query->query_limit ?? '',
				),
				'strlen'
			)
		);

		return array(
			'queryVars'    => $query->query_vars,
			'queryFields'  => $query->query_fields ?? '',
			'queryFrom'    => $query->query_from ?? '',
			'queryWhere'   => $query->query_where ?? '',
			'queryOrderby' => $query->query_orderby ?? '',
			'queryLimit'   => $query->query_limit ?? '',
			'sql'          => $sql,
		);
	}

	private static function comment_query_parse_observation( array $query_vars ): array {
		$query = new \WP_Comment_Query();
		$query->parse_query( $query_vars );

		return array(
			'queryVars' => $query->query_vars,
		);
	}

	private static function meta_sanitized_tree_is_idempotent( array $query_args ): bool {
		$first  = new \WP_Meta_Query( $query_args );
		$second = new \WP_Meta_Query( $first->queries );
		$third  = new \WP_Meta_Query( $second->queries );

		return $second->queries === $third->queries;
	}

	private static function tax_sanitized_tree_is_idempotent( array $query_args ): bool {
		$first  = new \WP_Tax_Query( $query_args );
		$second = new \WP_Tax_Query( $first->queries );
		$third  = new \WP_Tax_Query( $second->queries );

		return $second->queries === $third->queries;
	}

	private static function date_sanitized_tree_is_idempotent( array $query_args, string $default_column ): bool {
		$first  = new \WP_Date_Query( $query_args, $default_column );
		$second = new \WP_Date_Query( $first->queries, $default_column );
		$third  = new \WP_Date_Query( $second->queries, $default_column );

		return $second->queries === $third->queries;
	}

	private static function meta_sql_is_deterministic( array $query_args ): bool {
		$first  = self::meta_observation( $query_args );
		$second = self::meta_observation( $query_args );

		return $first['sql'] === $second['sql'] && $first['clauses'] === $second['clauses'];
	}

	private static function tax_sql_is_deterministic( array $query_args ): bool {
		$first  = self::tax_observation( $query_args );
		$second = self::tax_observation( $query_args );

		return $first['sql'] === $second['sql'];
	}

	private static function date_sql_is_deterministic( array $query_args, string $default_column ): bool {
		$first  = self::date_observation( $query_args, $default_column );
		$second = self::date_observation( $query_args, $default_column );

		return $first['sql'] === $second['sql'];
	}

	private static function wp_query_summary( \WP_Query $query ): array {
		return array(
			'queryVars' => $query->query_vars,
			'flags'     => array(
				'is_404'               => $query->is_404,
				'is_archive'           => $query->is_archive,
				'is_attachment'        => $query->is_attachment,
				'is_author'            => $query->is_author,
				'is_category'          => $query->is_category,
				'is_date'              => $query->is_date,
				'is_day'               => $query->is_day,
				'is_embed'             => $query->is_embed,
				'is_feed'              => $query->is_feed,
				'is_home'              => $query->is_home,
				'is_month'             => $query->is_month,
				'is_page'              => $query->is_page,
				'is_paged'             => $query->is_paged,
				'is_preview'           => $query->is_preview,
				'is_search'            => $query->is_search,
				'is_single'            => $query->is_single,
				'is_singular'          => $query->is_singular,
				'is_tag'               => $query->is_tag,
				'is_tax'               => $query->is_tax,
				'is_time'              => $query->is_time,
				'is_trackback'         => $query->is_trackback,
				'is_year'              => $query->is_year,
				'is_post_type_archive' => $query->is_post_type_archive,
			),
		);
	}

	private static function is_meta_observation( $value ): bool {
		return is_array( $value )
			&& array_key_exists( 'queries', $value )
			&& array_key_exists( 'relation', $value )
			&& is_bool( $value['hasOr'] ?? null )
			&& self::is_sql_fragment( $value['sql'] ?? null )
			&& is_array( $value['clauses'] ?? null );
	}

	private static function is_tax_observation( $value ): bool {
		return is_array( $value )
			&& array_key_exists( 'queries', $value )
			&& is_string( $value['relation'] ?? null )
			&& self::is_sql_fragment( $value['sql'] ?? null );
	}

	private static function is_date_observation( $value ): bool {
		return is_array( $value )
			&& array_key_exists( 'queries', $value )
			&& is_string( $value['relation'] ?? null )
			&& is_string( $value['column'] ?? null )
			&& is_string( $value['compare'] ?? null )
			&& is_string( $value['sql'] ?? null );
	}

	private static function is_sql_fragment( $value ): bool {
		return is_array( $value )
			&& is_string( $value['join'] ?? null )
			&& is_string( $value['where'] ?? null );
	}

	private static function sql_expectations_match( $sql, array $expect ): bool {
		$sql_string = is_array( $sql ) ? implode( ' ', $sql ) : (string) $sql;

		if ( ! empty( $expect['noOp'] ) && ! ( is_array( $sql ) && '' === $sql['join'] && '' === $sql['where'] ) ) {
			return false;
		}

		if ( ! empty( $expect['emptyString'] ) && '' !== $sql_string ) {
			return false;
		}

		foreach ( $expect['contains'] ?? array() as $needle ) {
			if ( ! str_contains( $sql_string, $needle ) ) {
				return false;
			}
		}

		foreach ( $expect['joinContains'] ?? array() as $needle ) {
			if ( ! is_array( $sql ) || ! str_contains( $sql['join'], $needle ) ) {
				return false;
			}
		}

		foreach ( $expect['whereContains'] ?? array() as $needle ) {
			if ( ! is_array( $sql ) || ! str_contains( $sql['where'], $needle ) ) {
				return false;
			}
		}

		return true;
	}

	private static function meta_clause_expectations_match( array $clauses, array $expect ): bool {
		foreach ( $expect['casts'] ?? array() as $cast ) {
			$found = false;
			foreach ( $clauses as $clause ) {
				if ( is_array( $clause ) && $cast === ( $clause['cast'] ?? null ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}

		return true;
	}

	private static function primary_meta_clause_is_first_when_expected( array $query_vars, array $queries ): bool {
		$has_primary = false;
		foreach ( array( 'meta_key', 'meta_value', 'meta_compare', 'meta_type', 'meta_compare_key', 'meta_type_key' ) as $key ) {
			if ( ! empty( $query_vars[ $key ] ) ) {
				$has_primary = true;
				break;
			}
		}

		if ( ! $has_primary ) {
			return true;
		}

		$first = reset( $queries );
		if ( ! is_array( $first ) ) {
			return false;
		}

		if ( ! empty( $query_vars['meta_key'] ) && ( $first['key'] ?? null ) !== $query_vars['meta_key'] ) {
			return false;
		}

		if ( isset( $query_vars['meta_value'] ) && '' !== $query_vars['meta_value'] && ( $first['value'] ?? null ) !== $query_vars['meta_value'] ) {
			return false;
		}

		return true;
	}

	private static function relations_are_normalized( $query ): bool {
		if ( ! is_array( $query ) ) {
			return true;
		}

		foreach ( $query as $key => $value ) {
			if ( 'relation' === $key && ! in_array( $value, array( 'AND', 'OR' ), true ) ) {
				return false;
			}

			if ( is_array( $value ) && ! self::relations_are_normalized( $value ) ) {
				return false;
			}
		}

		return true;
	}

	private static function sql_fragment_is_balanced( array $sql ): bool {
		return self::sql_string_is_balanced( $sql['join'] ) && self::sql_string_is_balanced( $sql['where'] );
	}

	private static function sql_string_is_balanced( string $sql ): bool {
		$depth       = 0;
		$in_string   = false;
		$length      = strlen( $sql );
		$last        = '';
		$string_char = "'";

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];

			if ( $in_string ) {
				if ( $string_char === $char && '\\' !== $last ) {
					$in_string = false;
				}
				$last = $char;
				continue;
			}

			if ( "'" === $char || '"' === $char ) {
				$in_string   = true;
				$string_char = $char;
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
				if ( $depth < 0 ) {
					return false;
				}
			}

			$last = $char;
		}

		return 0 === $depth && ! $in_string;
	}

	private static function sql_has_no_unexpanded_placeholders( $sql ): bool {
		$sql_string = is_array( $sql ) ? implode( ' ', $sql ) : (string) $sql;
		$in_string  = false;
		$last       = '';
		$quote      = "'";
		$length     = strlen( $sql_string );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql_string[ $i ];

			if ( $in_string ) {
				if ( $quote === $char && '\\' !== $last ) {
					$in_string = false;
				}
				$last = $char;
				continue;
			}

			if ( "'" === $char || '"' === $char ) {
				$in_string = true;
				$quote     = $char;
			} elseif ( '%' === $char && isset( $sql_string[ $i + 1 ] ) && str_contains( 'sdfFi', $sql_string[ $i + 1 ] ) ) {
				return false;
			}

			$last = $char;
		}

		return ! $in_string;
	}

	private static function safe_sql_identifier( string $identifier ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_$\.]+$/', $identifier );
	}

	private static function wp_query_normalization_holds( array $query_vars ): bool {
		foreach ( array( 'p', 'page_id', 'year', 'monthnum', 'day', 'w', 'paged', 'attachment_id' ) as $key ) {
			if ( ! is_int( $query_vars[ $key ] ?? null ) ) {
				return false;
			}
		}

		foreach ( array( 'category__in', 'category__not_in', 'category__and', 'post__in', 'post__not_in', 'post_name__in', 'tag__in', 'tag__not_in', 'tag__and', 'tag_slug__in', 'tag_slug__and', 'post_parent__in', 'post_parent__not_in', 'author__in', 'author__not_in', 'search_columns' ) as $key ) {
			if ( ! is_array( $query_vars[ $key ] ?? null ) ) {
				return false;
			}
		}

		if ( is_array( $query_vars['post_type'] ?? null ) ) {
			foreach ( $query_vars['post_type'] as $post_type ) {
				if ( ! is_string( $post_type ) || 1 !== preg_match( '/^[a-z0-9_-]*$/', $post_type ) ) {
					return false;
				}
			}
		}

		return ! is_string( $query_vars['s'] ?? null ) || strlen( $query_vars['s'] ) <= 1600;
	}

	private static function wp_query_flags_are_consistent( array $flags ): bool {
		if ( ! empty( $flags['is_singular'] ) ) {
			$singular_parts = ! empty( $flags['is_single'] ) || ! empty( $flags['is_page'] ) || ! empty( $flags['is_attachment'] );
			if ( ! $singular_parts ) {
				return false;
			}
		}

		if ( ! empty( $flags['is_archive'] ) ) {
			$archive_parts = ! empty( $flags['is_date'] ) || ! empty( $flags['is_author'] ) || ! empty( $flags['is_category'] ) || ! empty( $flags['is_tag'] ) || ! empty( $flags['is_tax'] ) || ! empty( $flags['is_post_type_archive'] );
			if ( ! $archive_parts ) {
				return false;
			}
		}

		if ( ! empty( $flags['is_day'] ) && empty( $flags['is_date'] ) ) {
			return false;
		}

		if ( ! empty( $flags['is_month'] ) && empty( $flags['is_date'] ) ) {
			return false;
		}

		if ( ! empty( $flags['is_year'] ) && empty( $flags['is_date'] ) ) {
			return false;
		}

		return true;
	}

	private static function user_query_defaults_present( array $query_vars ): bool {
		foreach ( array( 'blog_id', 'meta_key', 'meta_value', 'include', 'exclude', 'search', 'orderby', 'order', 'offset', 'number', 'paged', 'count_total', 'fields', 'cache_results' ) as $key ) {
			if ( ! array_key_exists( $key, $query_vars ) ) {
				return false;
			}
		}

		return true;
	}

	private static function comment_query_defaults_present( array $query_vars ): bool {
		foreach ( array( 'author_email', 'fields', 'ID', 'number', 'offset', 'orderby', 'order', 'parent', 'post_id', 'status', 'type', 'user_id', 'search', 'count', 'meta_query', 'date_query', 'hierarchical' ) as $key ) {
			if ( ! array_key_exists( $key, $query_vars ) ) {
				return false;
			}
		}

		return true;
	}

	private static function query_vars_are_scalar_array_safe( $value ): bool {
		if ( is_scalar( $value ) || null === $value ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $key => $item ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				return false;
			}

			if ( ! self::query_vars_are_scalar_array_safe( $item ) ) {
				return false;
			}
		}

		return true;
	}

	private static function random_relation( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice( array( 'AND', 'OR', 'and', 'or', 'XOR', '', 'maybe' ) );
	}

	private static function random_meta_key( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				'alpha',
				'_private',
				'event_☃',
				'ключ',
				"quote'key",
				'path\\key',
				$ctx->identifier( 3, 12 ),
			)
		);
	}

	private static function random_meta_key_list( \ComponentFuzz\FuzzContext $ctx ): array {
		$list  = array();
		$count = $ctx->int( 1, 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$list[] = self::random_meta_key( $ctx->fork( 'key-list-' . $i ) );
		}

		return $list;
	}

	private static function random_meta_value_for_compare( \ComponentFuzz\FuzzContext $ctx, string $compare ) {
		if ( in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
			return self::random_value_list( $ctx, 1, 4 );
		}

		if ( in_array( $compare, array( 'BETWEEN', 'NOT BETWEEN' ), true ) ) {
			return array( self::random_scalar_value( $ctx->fork( 'between-a' ) ), self::random_scalar_value( $ctx->fork( 'between-b' ) ) );
		}

		return self::random_scalar_value( $ctx );
	}

	private static function random_value_list( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): array {
		$list  = array();
		$count = $ctx->int( $min, $max );
		for ( $i = 0; $i < $count; $i++ ) {
			$list[] = self::random_scalar_value( $ctx->fork( 'value-' . $i ) );
		}

		return $list;
	}

	private static function random_scalar_value( \ComponentFuzz\FuzzContext $ctx ) {
		return $ctx->choice( array( '', '0', $ctx->int( -20, 120 ), 'plain', "quote'value", 'slash\\value', 'ümlaut', '☃ snow' ) );
	}

	private static function random_id_list( \ComponentFuzz\FuzzContext $ctx, int $min, int $max ): array {
		$list  = array();
		$count = $ctx->int( $min, $max );
		for ( $i = 0; $i < $count; $i++ ) {
			$list[] = $ctx->choice( array( $ctx->int( -20, 80 ), (string) $ctx->int( 0, 80 ), 'bad' ) );
		}

		return $list;
	}

	private static function random_date_unit_value( \ComponentFuzz\FuzzContext $ctx, string $unit, string $compare ) {
		$ranges = array(
			'year'          => array( 1990, 2035, 2200 ),
			'month'         => array( 1, 12, 14 ),
			'day'           => array( 1, 31, 35 ),
			'dayofweek_iso' => array( 1, 7, 9 ),
			'hour'          => array( 0, 23, 27 ),
			'minute'        => array( 0, 59, 70 ),
			'second'        => array( 0, 59, 70 ),
		);
		$range  = $ranges[ $unit ];
		$single = $ctx->choice( array( $ctx->int( $range[0], $range[1] ), $range[2], (string) $ctx->int( $range[0], $range[1] ) ) );

		if ( in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
			return array( $single, $ctx->int( $range[0], $range[1] ) );
		}

		if ( in_array( $compare, array( 'BETWEEN', 'NOT BETWEEN' ), true ) ) {
			return array( $ctx->int( $range[0], $range[1] ), $ctx->int( $range[0], $range[1] ) );
		}

		return $single;
	}

	private static function random_date_boundary( \ComponentFuzz\FuzzContext $ctx ) {
		return $ctx->choice(
			array(
				'2020',
				'2020-02',
				'2020-02-29',
				'2020-02-29 09:30',
				'not-a-date',
				array(
					'year'  => $ctx->int( 1990, 2030 ),
					'month' => $ctx->choice( array( 1, 2, 12, 14 ) ),
					'day'   => $ctx->choice( array( 1, 15, 31, 35 ) ),
				),
			)
		);
	}

	private static function random_string( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice( array( '', 'alpha', 'two words', "quote'value", 'slash\\value', 'ümlaut', '☃ snow', $ctx->identifier( 2, 12 ) ) );
	}

	private static function install_scoped_globals(): void {
		$GLOBALS['wpdb'] = self::new_wpdb_stub();

		\add_filter( 'doing_it_wrong_trigger_error', '__return_false', 0 );
		\add_filter(
			'pre_option_show_on_front',
			static function () {
				return 'posts';
			},
			0
		);
		\add_filter( 'pre_option_page_on_front', '__return_zero', 0 );
		\add_filter( 'pre_option_page_for_posts', '__return_zero', 0 );
		\add_filter( 'pre_option_wp_page_for_privacy_policy', '__return_zero', 0 );
		\add_filter(
			'pre_option_permalink_structure',
			static function () {
				return '';
			},
			0
		);
	}

	private static function register_scoped_taxonomies(): void {
		if ( \taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		\register_taxonomy(
			self::TAXONOMY,
			'post',
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'show_ui'            => false,
			)
		);
	}

	private static function new_wpdb_stub(): object {
		return new class() {
			public $suppress_errors = false;
			public $posts = 'wp_posts';
			public $comments = 'wp_comments';
			public $terms = 'wp_terms';
			public $term_taxonomy = 'wp_term_taxonomy';
			public $term_relationships = 'wp_term_relationships';
			public $options = 'wp_options';
			public $postmeta = 'wp_postmeta';
			public $usermeta = 'wp_usermeta';
			public $commentmeta = 'wp_commentmeta';
			public $termmeta = 'wp_termmeta';
			public $blogmeta = 'wp_blogmeta';
			public $sitemeta = 'wp_sitemeta';
			public $users = 'wp_users';
			public $blogs = 'wp_blogs';
			public $blog_versions = 'wp_blog_versions';
			public $registration_log = 'wp_registration_log';
			public $signups = 'wp_signups';
			public $site = 'wp_site';
			public $prefix = 'wp_';
			public $base_prefix = 'wp_';

			public function _escape( $data ) {
				if ( is_array( $data ) ) {
					return array_map( array( $this, '_escape' ), $data );
				}

				return addslashes( (string) $data );
			}

			public function esc_like( $text ) {
				return addcslashes( (string) $text, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = array_values( $args[0] );
				}

				$index = 0;
				return (string) preg_replace_callback(
					'/%(?:s|d|F|f|i)/',
					function ( array $match ) use ( &$args, &$index ): string {
						if ( ! array_key_exists( $index, $args ) ) {
							return $match[0];
						}

						$placeholder = $match[0];
						$arg         = $args[ $index ];
						++$index;

						if ( '%d' === $placeholder ) {
							return (string) (int) $arg;
						}

						if ( '%f' === $placeholder || '%F' === $placeholder ) {
							return (string) (float) $arg;
						}

						if ( '%i' === $placeholder ) {
							return preg_replace( '/[^A-Za-z0-9_$\.]/', '', (string) $arg );
						}

						return "'" . $this->_escape( $arg ) . "'";
					},
					(string) $query
				);
			}

			public function placeholder_escape() {
				return '{component_fuzz_placeholder}';
			}

			public function remove_placeholder_escape( $query ) {
				return str_replace( $this->placeholder_escape(), '%', (string) $query );
			}

			public function suppress_errors( $suppress = true ) {
				$previous              = $this->suppress_errors;
				$this->suppress_errors = (bool) $suppress;
				return $previous;
			}

			public function get_var( $query = null, $x = 0, $y = 0 ) {
				unset( $query, $x, $y );
				return null;
			}

			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				unset( $query, $output, $y );
				return null;
			}

			public function get_results( $query = null, $output = OBJECT ) {
				unset( $query, $output );
				return array();
			}

			public function get_col( $query = null, $x = 0 ) {
				unset( $query, $x );
				return array();
			}

			public function query( $query ) {
				unset( $query );
				return false;
			}

			public function get_blog_prefix( $blog_id = null ) {
				unset( $blog_id );
				return 'wp_';
			}
		};
	}

	private static function snapshot_globals(): array {
		$names    = array(
			'wpdb',
			'wp_filter',
			'wp_actions',
			'wp_current_filter',
			'wp_taxonomies',
			'wp_post_types',
			'wp_roles',
			'wp_query',
			'wp_the_query',
			'post',
			'authordata',
			'current_user',
		);
		$snapshot = array();

		foreach ( $names as $name ) {
			$snapshot[ $name ] = array_key_exists( $name, $GLOBALS )
				? array( 'exists' => true, 'value' => $GLOBALS[ $name ] )
				: array( 'exists' => false );
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

	private static function call_guarded( callable $callback ): array {
		try {
			return array(
				'ok'    => true,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'        => false,
				'throwable' => self::describe_throwable( $e ),
			);
		}
	}

	private static function case_result( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case, string $invariant, bool $ok, array $data = array() ): array {
		$data = array_merge(
			array(
				'caseIndex' => $case_index,
				'label'     => $case['label'] ?? 'case-' . $case_index,
			),
			$data
		);

		if ( ! empty( $case['query'] ) ) {
			$data['query'] = self::describe_value( $case['query'] );
		}
		if ( ! empty( $case['queryVars'] ) ) {
			$data['queryVars'] = self::describe_value( $case['queryVars'] );
		}

		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function describe_call( array $call ) {
		if ( ! $call['ok'] ) {
			return $call['throwable'];
		}

		return self::describe_value( $call['value'] );
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function describe_value( $value ) {
		return \ComponentFuzz\preview_value( $value, self::SAMPLE_BYTES );
	}
}
