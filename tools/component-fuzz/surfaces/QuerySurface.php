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
			self::seed_query_fixture();

			$rows = array_merge( $rows, self::check_meta_queries( $ctx ) );
			$rows = array_merge( $rows, self::check_tax_queries( $ctx ) );
			$rows = array_merge( $rows, self::check_date_queries( $ctx ) );
			$rows = array_merge( $rows, self::check_wp_query_parsing( $ctx ) );
			$rows = array_merge( $rows, self::check_wp_query_execution( $ctx ) );
			$rows = array_merge( $rows, self::check_wp_query_post_search_matrix( $ctx ) );
			$rows = array_merge( $rows, self::check_wp_query_cache_keys( $ctx ) );
			$rows = array_merge( $rows, self::check_wp_query_result_cache( $ctx ) );
			$rows = array_merge( $rows, self::check_user_query_parsing( $ctx ) );
			$rows = array_merge( $rows, self::check_user_query_sql_semantics( $ctx ) );
			$rows = array_merge( $rows, self::check_user_query_pre_query( $ctx ) );
			$rows = array_merge( $rows, self::check_comment_query_parsing( $ctx ) );
			$rows = array_merge( $rows, self::check_comment_query_pre_query( $ctx ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'query.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		$rows[] = $ctx->result(
			'query.global-state-restored',
			self::globals_match_snapshot( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot ) )
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'Component_Fuzz_WPDB_Stub',
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
				'remove_filter',
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

	private static function check_wp_query_execution( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::wp_query_execution_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::wp_query_execution_observation( $case );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-no-throw',
				$call['ok'] && self::is_wp_query_execution_observation( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! self::is_wp_query_execution_observation( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-sql-shape-safe',
				self::wp_query_request_sql_is_safe( $observation['request'] )
					&& self::wp_query_recorded_sql_is_safe( $observation['wpdbQueries'] ),
				array(
					'request' => self::describe_value( $observation['request'] ),
					'queries' => self::describe_value( $observation['wpdbQueries'] ),
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-expected-sql-features',
				self::sql_expectations_match( $observation['request'], $case['expect'] ?? array() )
					&& self::wp_query_execution_expectations_match( $observation, $case['expect'] ?? array() ),
				array(
					'expect'      => $case['expect'] ?? array(),
					'request'     => self::describe_value( $observation['request'] ),
					'foundPosts'  => $observation['foundPosts'],
					'maxNumPages' => $observation['maxNumPages'],
					'postIds'     => $observation['postIds'],
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-result-window-coherent',
				self::wp_query_result_window_is_coherent( $observation ),
				array(
					'postCount'    => $observation['postCount'],
					'resultCount'  => $observation['resultCount'],
					'postIds'      => $observation['postIds'],
					'postsPerPage' => $observation['queryVars']['posts_per_page'] ?? null,
					'nopaging'     => $observation['queryVars']['nopaging'] ?? null,
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-found-rows-contract',
				self::wp_query_found_rows_contract_holds( $observation ),
				array(
					'foundPosts'          => $observation['foundPosts'],
					'maxNumPages'         => $observation['maxNumPages'],
					'foundRowsQueryCount' => $observation['foundRowsQueryCount'],
					'queryCountDelta'     => $observation['queryCountDelta'],
					'noFoundRows'         => $observation['queryVars']['no_found_rows'] ?? null,
					'request'             => self::describe_value( $observation['request'] ),
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-stub-result-oracle',
				self::wp_query_stub_result_expectations_match( $observation, $case['expect'] ?? array() ),
				array(
					'expect'              => $case['expect'] ?? array(),
					'postIds'             => $observation['postIds'],
					'foundRowsQueryCount' => $observation['foundRowsQueryCount'],
					'foundPosts'          => $observation['foundPosts'],
					'maxNumPages'         => $observation['maxNumPages'],
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-normalized-vars-safe',
				self::query_vars_are_scalar_array_safe( $observation['queryVars'] )
					&& self::wp_query_normalization_holds( $observation['queryVars'] )
					&& self::wp_query_execution_vars_hold( $observation['queryVars'] ),
				array( 'queryVars' => self::describe_value( $observation['queryVars'] ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-flags-consistent',
				self::wp_query_flags_are_consistent( $observation['flags'] )
					&& self::wp_query_expected_flags_match( $observation['flags'], $case['expect']['flags'] ?? array() ),
				array( 'flags' => self::describe_value( $observation['flags'] ) )
			);

			$second = self::call_guarded(
				static function () use ( $case ) {
					return self::wp_query_execution_observation( $case );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-deterministic',
				$second['ok'] && self::wp_query_execution_observations_match( $observation, $second['value'] ),
				array( 'second' => self::describe_call( $second ) )
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.get-posts-globals-unchanged',
				$observation['globalsUnchanged'],
				array(
					'trackedGlobals'             => array_keys( $observation['globalSnapshot'] ),
					'allowedGlobalMismatches'     => self::wp_query_allowed_global_mismatches(),
					'globalMismatches'            => $observation['globalMismatches'],
					'unexpectedGlobalMismatches'  => $observation['unexpectedGlobalMismatches'],
				)
			);
		}

		return $rows;
	}

	private static function check_wp_query_post_search_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::wp_query_post_search_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::wp_query_post_search_observation( $case );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.post-search-no-throw',
				$call['ok'] && self::is_wp_query_post_search_observation( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! self::is_wp_query_post_search_observation( $call['value'] ) ) {
				continue;
			}

			$observation = $call['value'];

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.post-search-protected-api-rules',
				self::wp_query_post_search_parse_expectations_match( $observation, $case['expect'] ?? array() ),
				array(
					'parse'  => self::describe_value( $observation['parse'] ),
					'expect' => $case['expect'] ?? array(),
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.post-search-sql-safe',
				self::wp_query_post_search_sql_is_safe( $observation ),
				array(
					'where'   => self::describe_value( $observation['parse']['where'] ),
					'order'   => self::describe_value( $observation['parse']['order'] ),
					'request' => self::describe_value( $observation['execution']['request'] ),
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.post-search-sql-features',
				self::wp_query_post_search_sql_expectations_match( $observation, $case['expect'] ?? array() ),
				array(
					'expect'  => $case['expect'] ?? array(),
					'where'   => self::describe_value( $observation['parse']['where'] ),
					'order'   => self::describe_value( $observation['parse']['order'] ),
					'request' => self::describe_value( $observation['execution']['request'] ),
				)
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.post-search-filters-scoped',
				self::wp_query_post_search_filter_expectations_match( $observation, $case ),
				array(
					'filterHits'             => $observation['filterHits'],
					'hookCallbacksRemoved'   => $observation['hookCallbacksRemoved'],
					'hookRuntimeRestored'    => $observation['hookRuntimeRestored'],
					'currentUserRestored'    => $observation['currentUserRestored'],
					'postSearchColumnsSeen'  => $observation['postSearchColumnsSeen'],
				)
			);

			$second = self::call_guarded(
				static function () use ( $case ) {
					return self::wp_query_post_search_observation( $case );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.wp-query.post-search-deterministic',
				$second['ok'] && self::wp_query_post_search_observations_match( $observation, $second['value'] ),
				array( 'second' => self::describe_call( $second ) )
			);
		}

		return $rows;
	}

	private static function check_wp_query_cache_keys( \ComponentFuzz\FuzzContext $ctx ): array {
		$sql         = "SELECT wp_posts.* FROM wp_posts WHERE 1=1 AND wp_posts.ID IN (3,7) AND wp_posts.post_status IN ('private','publish')";
		$placeholder = $GLOBALS['wpdb']->placeholder_escape();
		$base_args   = array(
			'cache_results'          => true,
			'fields'                 => 'ids',
			'lazy_load_term_meta'    => false,
			'no_found_rows'          => true,
			'post__in'               => array( 7, '3', 7 ),
			'post_status'            => array( 'publish', 'private' ),
			'post_type'              => array( 'page', 'post' ),
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		$equivalent  = array(
			'no_found_rows'          => true,
			'orderby'                => 'date',
			'post__in'               => array( 3, 7 ),
			'post_status'            => array( 'private', 'publish' ),
			'post_type'              => array( 'post', 'page' ),
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		$base_key        = self::wp_query_cache_key_for( $base_args, $sql );
		$equivalent_key  = self::wp_query_cache_key_for( $equivalent, $sql );
		$ignored_arg_key = self::wp_query_cache_key_for(
			array_merge(
				$base_args,
				array(
					'cache_results'          => false,
					'fields'                 => '',
					'lazy_load_term_meta'    => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			),
			$sql
		);
		$different_sql_key = self::wp_query_cache_key_for( $base_args, str_replace( '3,7', '3,19', $sql ) );
		$placeholder_key   = self::wp_query_cache_key_for(
			array_merge( $base_args, array( 's' => '100' . $placeholder . ' match' ) ),
			str_replace( 'wp_posts.ID IN (3,7)', "wp_posts.post_title LIKE '100{$placeholder} match'", $sql )
		);
		$raw_percent_key   = self::wp_query_cache_key_for(
			array_merge( $base_args, array( 's' => '100% match' ) ),
			str_replace( 'wp_posts.ID IN (3,7)', "wp_posts.post_title LIKE '100% match'", $sql )
		);

		$case = array( 'label' => 'wp-query-cache-key-normalization' );

		return array(
			self::case_result(
				$ctx,
				0,
				$case,
				'query.wp-query.cache-key-normalizes-equivalent-args',
				$base_key === $equivalent_key && $base_key === $ignored_arg_key,
				array(
					'baseKey'       => $base_key,
					'equivalentKey' => $equivalent_key,
					'ignoredArgKey' => $ignored_arg_key,
				)
			),
			self::case_result(
				$ctx,
				1,
				$case,
				'query.wp-query.cache-key-distinguishes-sql-shape',
				$base_key !== $different_sql_key,
				array(
					'baseKey'         => $base_key,
					'differentSqlKey' => $different_sql_key,
				)
			),
			self::case_result(
				$ctx,
				2,
				$case,
				'query.wp-query.cache-key-placeholder-stable',
				$placeholder_key === $raw_percent_key,
				array(
					'placeholderKey' => $placeholder_key,
					'rawPercentKey'  => $raw_percent_key,
				)
			),
		);
	}

	private static function check_wp_query_result_cache( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'wp_cache_supports' ) || ! function_exists( 'wp_cache_flush_group' ) || ! \wp_cache_supports( 'flush_group' ) ) {
			return array(
				$ctx->skip(
					'query.wp-query.cache-hit-reuses-seeded-results',
					'Object cache group flushing is unavailable.',
					array( 'group' => 'post-queries' )
				),
			);
		}

		$cache_snapshot = self::object_cache_group_snapshot( 'post-queries' );
		if ( empty( $cache_snapshot['supported'] ) ) {
			return array(
				$ctx->skip(
					'query.wp-query.cache-hit-reuses-seeded-results',
					'Object cache group state cannot be restored after the cache-hit check.',
					array( 'group' => 'post-queries' )
				),
			);
		}

		$case = array(
			'label'     => 'seeded-wp-query-cache-hit',
			'queryVars' => array(
				'cache_results'          => true,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'lazy_load_term_meta'    => false,
				'no_found_rows'          => true,
				'post__in'               => array( 3, 7 ),
				'post_status'            => 'publish',
				'post_type'              => 'post',
				'posts_per_page'         => 2,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			'expect'    => array(
				'postIds' => array( 7, 3 ),
			),
		);

		$first  = array( 'ok' => false, 'value' => null );
		$second = array( 'ok' => false, 'value' => null );

		try {
			\wp_cache_flush_group( 'post-queries' );

			$first = self::call_guarded(
				static function () use ( $case ) {
					return self::wp_query_execution_observation( $case );
				}
			);
			$second = self::call_guarded(
				static function () use ( $case ) {
					return self::wp_query_execution_observation( $case );
				}
			);
		} finally {
			self::restore_object_cache_group_snapshot( 'post-queries', $cache_snapshot );
		}

		$ok = $first['ok']
			&& $second['ok']
			&& self::is_wp_query_execution_observation( $first['value'] )
			&& self::is_wp_query_execution_observation( $second['value'] )
			&& array( 7, 3 ) === $first['value']['postIds']
			&& array( 7, 3 ) === $second['value']['postIds']
			&& $first['value']['queryCountDelta'] > 0
			&& 0 === $second['value']['queryCountDelta']
			&& self::wp_query_execution_observations_match( $first['value'], $second['value'] );

		return array(
			self::case_result(
				$ctx,
				0,
				$case,
				'query.wp-query.cache-hit-reuses-seeded-results',
				$ok,
				array(
					'first'  => self::describe_call( $first ),
					'second' => self::describe_call( $second ),
				)
			),
		);
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

	private static function check_user_query_pre_query( \ComponentFuzz\FuzzContext $ctx ): array {
		$case = array(
			'label'     => 'user-pre-query-short-circuit',
			'queryVars' => array(
				'blog_id'       => 0,
				'cache_results' => true,
				'count_total'   => true,
				'fields'        => 'ID',
				'number'        => 2,
				'orderby'       => 'ID',
			),
		);
		$call = self::call_guarded(
			static function () use ( $case ) {
				return self::user_query_pre_query_observation( $case['queryVars'] );
			}
		);

		return array(
			self::case_result(
				$ctx,
				0,
				$case,
				'query.user-query.pre-query-short-circuits-db',
				$call['ok']
					&& is_array( $call['value'] )
					&& 1 === $call['value']['filterHits']
					&& 0 === $call['value']['queryCountDelta']
					&& array( 41, 43 ) === $call['value']['results']
					&& 2 === $call['value']['totalUsers'],
				array( 'call' => self::describe_call( $call ) )
			),
		);
	}

	private static function check_user_query_sql_semantics( \ComponentFuzz\FuzzContext $ctx ): array {
		$rows  = array();
		$cases = self::user_query_semantic_cases( $ctx );

		foreach ( $cases as $case_index => $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return self::user_query_semantic_observation( $case );
				}
			);

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.user-query.semantic-sql-no-throw',
				$call['ok'] && is_array( $call['value'] ),
				array( 'call' => self::describe_call( $call ) )
			);

			if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
				continue;
			}

			$rows[] = self::case_result(
				$ctx,
				$case_index,
				$case,
				'query.user-query.semantic-sql-features',
				self::user_query_sql_expectations_match( $call['value'], $case['expect'] ?? array() ),
				array(
					'expect'      => $case['expect'] ?? array(),
					'observation' => self::describe_value( $call['value'] ),
				)
			);
		}

		$hook_case = array(
			'label'     => 'pre-get-search-pre-user-query-hooks',
			'queryVars' => array(
				'blog_id'        => 0,
				'fields'         => 'ID',
				'search'         => 'ignored',
				'search_columns' => array( 'ID' ),
				'orderby'        => 'login',
				'order'          => 'DESC',
				'count_total'    => false,
			),
		);
		$hook_call = self::call_guarded(
			static function () use ( $hook_case ) {
				return self::user_query_hook_mutation_observation( $hook_case['queryVars'] );
			}
		);

		$rows[] = self::case_result(
			$ctx,
			count( $cases ),
			$hook_case,
			'query.user-query.hook-mutation-no-throw',
			$hook_call['ok'] && is_array( $hook_call['value'] ),
			array( 'call' => self::describe_call( $hook_call ) )
		);

		if ( $hook_call['ok'] && is_array( $hook_call['value'] ) ) {
			$hook_observation = $hook_call['value'];
			$rows[]           = self::case_result(
				$ctx,
				count( $cases ),
				$hook_case,
				'query.user-query.hook-mutations-scoped',
				1 === $hook_observation['preGetUsersHits']
					&& 1 === $hook_observation['userSearchColumnsHits']
					&& 1 === $hook_observation['preUserQueryHits']
					&& array( 'user_login', 'user_email' ) === $hook_observation['searchColumnsBefore']
					&& 'hooked' === $hook_observation['searchSeen']
					&& 'ORDER BY user_email DESC' === $hook_observation['queryOrderby']
					&& str_contains( $hook_observation['queryWhere'], "display_name LIKE '%hooked%'" )
					&& str_contains( $hook_observation['queryWhere'], '/*cfz_pre_user_query*/' )
					&& ! str_contains( $hook_observation['queryWhere'], 'user_login LIKE' )
					&& $hook_observation['hookCallbacksRemoved']
					&& $hook_observation['hookRuntimeRestored'],
				array( 'observation' => self::describe_value( $hook_observation ) )
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

	private static function check_comment_query_pre_query( \ComponentFuzz\FuzzContext $ctx ): array {
		$object_case = array(
			'label'     => 'comment-pre-query-objects',
			'queryVars' => array(
				'cache_results'             => true,
				'no_found_rows'             => false,
				'number'                    => 2,
				'orderby'                   => 'comment_ID',
				'update_comment_meta_cache' => false,
				'update_comment_post_cache' => false,
			),
		);
		$count_case  = array(
			'label'     => 'comment-pre-query-count',
			'queryVars' => array(
				'count'         => true,
				'no_found_rows' => false,
				'number'        => 5,
				'status'        => 'approve',
			),
		);

		$object_call = self::call_guarded(
			static function () use ( $object_case ) {
				return self::comment_query_pre_query_observation( $object_case['queryVars'], false );
			}
		);
		$count_call  = self::call_guarded(
			static function () use ( $count_case ) {
				return self::comment_query_pre_query_observation( $count_case['queryVars'], true );
			}
		);

		return array(
			self::case_result(
				$ctx,
				0,
				$object_case,
				'query.comment-query.pre-query-short-circuits-db',
				$object_call['ok']
					&& is_array( $object_call['value'] )
					&& 1 === $object_call['value']['filterHits']
					&& 0 === $object_call['value']['queryCountDelta']
					&& array( 301, 303 ) === $object_call['value']['commentIds']
					&& array( 301, 303 ) === $object_call['value']['propertyCommentIds']
					&& 2 === $object_call['value']['foundComments']
					&& 1 === $object_call['value']['maxNumPages'],
				array( 'call' => self::describe_call( $object_call ) )
			),
			self::case_result(
				$ctx,
				1,
				$count_case,
				'query.comment-query.pre-query-count-short-circuits-db',
				$count_call['ok']
					&& is_array( $count_call['value'] )
					&& 1 === $count_call['value']['filterHits']
					&& 0 === $count_call['value']['queryCountDelta']
					&& 4 === $count_call['value']['countResult'],
				array( 'call' => self::describe_call( $count_call ) )
			),
		);
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

	private static function wp_query_execution_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$execution_defaults = array(
			'cache_results'          => false,
			'ignore_sticky_posts'    => true,
			'lazy_load_term_meta'    => false,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		$cases              = array(
			array(
				'label'     => 'search-paged-found-rows',
				'queryVars' => array_merge(
					$execution_defaults,
					array(
						'ignore_sticky_posts' => true,
						'no_found_rows'       => false,
						'paged'               => 2,
						'post_status'         => 'publish',
						'post_type'           => 'post',
						'posts_per_page'      => 5,
						's'                   => "alpha quote'value -excluded",
					)
				),
				'expect'    => array(
					'contains' => array( 'FROM wp_posts', 'post_title', 'LIMIT 5, 5' ),
					'flags'    => array(
						'is_paged'  => true,
						'is_search' => true,
					),
				),
			),
			array(
				'label'     => 'meta-tax-date-sql',
				'queryVars' => array_merge(
					$execution_defaults,
					array(
						'date_query'     => self::date_cases( $ctx )[1]['query'],
						'meta_query'     => self::meta_cases( $ctx )[1]['query'],
						'post_status'    => array( 'publish', 'private' ),
						'post_type'      => 'post',
						'posts_per_page' => 7,
						's'              => 'alpha "quoted phrase" -excluded',
						'tax_query'      => array(
							array(
								'taxonomy'         => self::TAXONOMY,
								'field'            => 'term_taxonomy_id',
								'terms'            => array( 5, '9', 'bad' ),
								'include_children' => false,
							),
						),
					)
				),
				'expect'    => array(
					'contains' => array( 'JOIN wp_postmeta', 'wp_term_relationships', 'post_date', 'post_title' ),
				),
			),
			array(
				'label'     => 'post-in-order-preserved',
				'queryVars' => array_merge(
					$execution_defaults,
					array(
						'orderby'        => 'post__in',
						'post__in'       => array( '7', 3, 'bad', 7 ),
						'post_status'    => 'publish',
						'post_type'      => 'post',
						'posts_per_page' => 3,
					)
				),
				'expect'    => array(
					'contains' => array( 'wp_posts.ID IN', 'FIELD(' ),
					'postIds'  => array( 7, 3 ),
				),
			),
			array(
				'label'     => 'seeded-post-in-ids-no-found',
				'queryVars' => array_merge(
					$execution_defaults,
					array(
						'fields'         => 'ids',
						'post__in'       => array( 3, 7, 23 ),
						'post_status'    => 'publish',
						'post_type'      => 'post',
						'posts_per_page' => -1,
					)
				),
				'expect'    => array(
					'contains' => array( 'wp_posts.ID', 'FROM wp_posts' ),
					'postIds'  => array( 23, 7, 3 ),
				),
			),
			array(
				'label'     => 'seeded-offset-window-no-found',
				'queryVars' => array_merge(
					$execution_defaults,
					array(
						'fields'         => 'ids',
						'offset'         => 1,
						'post__in'       => array( 3, 7, 23 ),
						'post_status'    => 'publish',
						'post_type'      => 'post',
						'posts_per_page' => 2,
					)
				),
				'expect'    => array(
					'contains' => array( 'LIMIT 1, 2' ),
					'postIds'  => array( 7, 3 ),
				),
			),
			array(
				'label'     => 'seeded-found-rows-stub-contract',
				'queryVars' => array_merge(
					$execution_defaults,
					array(
						'fields'         => 'ids',
						'no_found_rows'  => false,
						'post__in'       => array( 3, 7, 23 ),
						'post_status'    => 'publish',
						'post_type'      => 'post',
						'posts_per_page' => 2,
					)
				),
				'expect'    => array(
					'contains'            => array( 'SQL_CALC_FOUND_ROWS', 'LIMIT 0, 2' ),
					'foundPosts'          => 3,
					'foundRowsQueryCount' => 1,
					'maxNumPages'         => 2,
					'postIds'             => array( 23, 7 ),
				),
			),
			array(
				'label'     => 'singular-id-flags',
				'queryVars' => array_merge(
					$execution_defaults,
					array(
						'p'           => 123,
						'post_status' => 'publish',
						'post_type'   => 'post',
					)
				),
				'expect'    => array(
					'contains' => array( 'wp_posts.ID = 123' ),
					'flags'    => array(
						'is_single'   => true,
						'is_singular' => true,
					),
				),
			),
		);

		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'wp-query-execution-' . $i );
			$cases[]  = array(
				'label'     => 'generated-wp-query-execution-' . $i,
				'queryVars' => array_merge( $execution_defaults, self::generated_wp_query_vars( $case_ctx ) ),
				'expect'    => array( 'contains' => array( 'FROM wp_posts' ) ),
			);
		}

		return $cases;
	}

	private static function wp_query_post_search_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$long_search = 'one two three four five six seven eight nine ten eleven';
		$cases       = array(
			array(
				'label'      => 'quoted-stopword-exclusion-columns',
				'queryVars'  => array(
					'orderby'        => 'relevance',
					's'              => 'alpha "quoted phrase" the b -excluded',
					'search_columns' => array( 'post_title', 'post_excerpt', 'bad_column' ),
				),
				'termsInput' => array( 'alpha', '"quoted phrase"', 'the', 'b', '-excluded' ),
				'expect'     => array(
					'searchTermsExact' => array( 'alpha', 'quoted phrase', '-excluded' ),
					'searchTermsCount' => 5,
					'parsedTermsExact' => array( 'alpha', 'quoted phrase', '-excluded' ),
					'whereContains'    => array(
						"wp_posts.post_title LIKE '%alpha%'",
						"wp_posts.post_excerpt LIKE '%quoted phrase%'",
						"wp_posts.post_title NOT LIKE '%excluded%'",
						"wp_posts.post_excerpt NOT LIKE '%excluded%'",
					),
					'whereNotContains' => array(
						'bad_column',
						'wp_posts.post_content LIKE',
						'wp_posts.post_content NOT LIKE',
					),
					'orderContains'    => array( '(CASE ', 'THEN 2 ', 'ELSE 6 END)' ),
					'orderNotContains' => array( 'THEN 1 ', 'THEN 4 ', 'THEN 5 ' ),
					'requestContains'  => array( "wp_posts.post_password = ''" ),
				),
			),
			array(
				'label'      => 'positive-multi-term-rank-case',
				'queryVars'  => array(
					'orderby' => 'relevance',
					's'       => 'alpha beta gamma',
				),
				'termsInput' => array( 'alpha', 'beta', 'gamma' ),
				'expect'     => array(
					'searchTermsExact' => array( 'alpha', 'beta', 'gamma' ),
					'searchTermsCount' => 3,
					'orderContains'    => array( '(CASE ', 'THEN 1 ', 'THEN 2 ', 'THEN 3 ', 'THEN 4 ', 'THEN 5 ', 'ELSE 6 END)' ),
					'requestContains'  => array(
						"wp_posts.post_title LIKE '%alpha beta gamma%'",
						"wp_posts.post_excerpt LIKE '%alpha beta gamma%'",
						"wp_posts.post_content LIKE '%alpha beta gamma%'",
					),
				),
			),
			array(
				'label'      => 'ten-plus-term-sentence-fallback',
				'queryVars'  => array(
					'orderby' => 'relevance',
					's'       => $long_search,
				),
				'termsInput' => explode( ' ', $long_search ),
				'expect'     => array(
					'searchTermsExact' => array( $long_search ),
					'searchTermsCount' => 11,
					'singleSearchTermEqualsSearch' => true,
					'whereContains'    => array( "wp_posts.post_title LIKE '%{$long_search}%'" ),
					'orderContains'    => array( 'THEN 1 ', 'THEN 2 ', 'THEN 4 ', 'THEN 5 ', 'ELSE 6 END)' ),
					'orderNotContains' => array( 'THEN 3 ' ),
				),
			),
			array(
				'label'      => 'crlf-normalized-wildcard-sensitive',
				'queryVars'  => array(
					's'              => "100%_match\r\nalpha",
					'search_columns' => array( 'post_title' ),
				),
				'termsInput' => array( "100%_match\r\nalpha" ),
				'expect'     => array(
					'normalizedSearch' => '100%_matchalpha',
					'searchTermsExact' => array( '100%_matchalpha' ),
					'whereContains'    => array( "wp_posts.post_title LIKE '%100\\\\%\\\\_matchalpha%'" ),
					'whereNotContains' => array( "\r", "\n", 'wp_posts.post_excerpt', 'wp_posts.post_content' ),
					'requestContains'  => array( "wp_posts.post_title LIKE '%100\\\\%\\\\_matchalpha%'" ),
					'requestNotContains' => array( "\r" ),
				),
			),
			array(
				'label'      => 'exact-single-term-no-surrounding-wildcards',
				'queryVars'  => array(
					'exact'          => true,
					's'              => '100%_match',
					'search_columns' => array( 'post_title' ),
				),
				'termsInput' => array( '100%_match' ),
				'expect'     => array(
					'searchTermsExact' => array( '100%_match' ),
					'whereContains'    => array( "wp_posts.post_title LIKE '100\\\\%\\\\_match'" ),
					'whereNotContains' => array( "LIKE '%100", "match%'" ),
					'requestContains'  => array( "wp_posts.post_title LIKE '100\\\\%\\\\_match'" ),
					'requestNotContains' => array( '(CASE ', "LIKE '%100" ),
				),
			),
			array(
				'label'      => 'sentence-exact-keeps-dash-literal',
				'queryVars'  => array(
					'exact'          => true,
					's'              => 'alpha beta -literal',
					'search_columns' => array( 'post_content' ),
					'sentence'       => true,
				),
				'termsInput' => array( 'alpha', 'beta', '-literal' ),
				'expect'     => array(
					'searchTermsExact' => array( 'alpha beta -literal' ),
					'searchTermsCount' => 1,
					'whereContains'    => array( "wp_posts.post_content LIKE 'alpha beta -literal'" ),
					'whereNotContains' => array( 'NOT LIKE', 'wp_posts.post_title', 'wp_posts.post_excerpt' ),
					'requestContains'  => array( "wp_posts.post_content LIKE 'alpha beta -literal'" ),
				),
			),
			array(
				'label'           => 'prefix-disabled-dash-is-positive',
				'queryVars'       => array(
					'orderby'        => 'relevance',
					's'              => 'alpha -excluded',
					'search_columns' => array( 'post_title' ),
				),
				'termsInput'      => array( 'alpha', '-excluded' ),
				'exclusionPrefix' => '',
				'expect'          => array(
					'searchTermsExact' => array( 'alpha', '-excluded' ),
					'whereContains'    => array( "wp_posts.post_title LIKE '%-excluded%'" ),
					'whereNotContains' => array( 'NOT LIKE' ),
					'orderContains'    => array( 'THEN 2 ', 'THEN 3 ', 'ELSE 6 END)' ),
					'orderNotContains' => array( 'THEN 1 ', 'THEN 4 ', 'THEN 5 ' ),
					'requestContains'  => array( "wp_posts.post_title LIKE '%-excluded%'" ),
					'requestNotContains' => array( 'NOT LIKE' ),
				),
			),
			array(
				'label'               => 'invalid-search-columns-fallback-defaults',
				'queryVars'           => array(
					's'              => 'alpha',
					'search_columns' => array( 'bad_column' ),
				),
				'termsInput'          => array( 'alpha' ),
				'filterSearchColumns' => array( 'bad_column', 'also_bad' ),
				'expect'              => array(
					'postSearchColumnsBefore' => array( array( 'bad_column' ), array( 'bad_column' ) ),
					'whereContains'           => array( 'wp_posts.post_title LIKE', 'wp_posts.post_excerpt LIKE', 'wp_posts.post_content LIKE' ),
					'whereNotContains'        => array( 'bad_column', 'also_bad' ),
					'requestNotContains'      => array( 'bad_column', 'also_bad' ),
				),
			),
			array(
				'label'               => 'post-search-columns-filter-allowlist',
				'queryVars'           => array(
					's'              => 'alpha beta',
					'search_columns' => array( 'post_title', 'post_content' ),
				),
				'termsInput'          => array( 'alpha', 'beta' ),
				'filterSearchColumns' => array( 'post_title', 'bad_column' ),
				'expect'              => array(
					'postSearchColumnsBefore' => array( array( 'post_title', 'post_content' ), array( 'post_title', 'post_content' ) ),
					'whereContains'           => array( 'wp_posts.post_title LIKE' ),
					'whereNotContains'        => array( 'wp_posts.post_content', 'wp_posts.post_excerpt', 'bad_column' ),
					'requestNotContains'      => array( 'bad_column' ),
				),
			),
			array(
				'label'      => 'stopwords-filter-reduces-terms',
				'queryVars'  => array(
					'orderby' => 'relevance',
					's'       => 'alpha beta gamma',
				),
				'termsInput' => array( 'alpha', 'beta', 'gamma' ),
				'stopwords'  => array( 'alpha', 'beta' ),
				'expect'     => array(
					'searchTermsExact' => array( 'gamma' ),
					'parsedTermsExact' => array( 'gamma' ),
					'whereContains'    => array( "wp_posts.post_title LIKE '%gamma%'" ),
					'whereNotContains' => array( "LIKE '%alpha%'", "LIKE '%beta%'" ),
				),
			),
			array(
				'label'      => 'stopword-short-only-fallback',
				'queryVars'  => array(
					's' => 'a the x -',
				),
				'termsInput' => array( 'a', 'the', 'x', '-' ),
				'expect'     => array(
					'searchTermsExact' => array( 'a the x -' ),
					'searchTermsCount' => 4,
					'singleSearchTermEqualsSearch' => true,
					'parsedTermsExact' => array(),
					'whereContains'    => array( "wp_posts.post_title LIKE '%a the x -%'" ),
				),
			),
			array(
				'label'      => 'logged-in-skips-search-password-gate',
				'queryVars'  => array(
					's' => 'alpha beta',
				),
				'termsInput' => array( 'alpha', 'beta' ),
				'loggedIn'   => true,
				'expect'     => array(
					'requestNotContains' => array( "wp_posts.post_password = ''" ),
				),
			),
			array(
				'label'      => 'explicit-post-password-clause',
				'queryVars'  => array(
					'post_password' => 'secret',
					's'             => 'alpha',
				),
				'termsInput' => array( 'alpha' ),
				'loggedIn'   => true,
				'expect'     => array(
					'requestContains'    => array( "wp_posts.post_password = 'secret'" ),
					'requestNotContains' => array( "wp_posts.post_password = ''" ),
				),
			),
			array(
				'label'                     => 'attachment-filename-search-branch',
				'queryVars'                 => array(
					'post_status'    => 'inherit',
					'post_type'      => 'attachment',
					's'              => '2020/06/photo.jpg',
					'search_columns' => array( 'post_title' ),
				),
				'termsInput'                => array( '2020/06/photo.jpg' ),
				'allowAttachmentByFilename' => true,
				'expect'                    => array(
					'requestContains' => array(
						'LEFT JOIN wp_postmeta AS sq1',
						"_wp_attached_file",
						'sq1.meta_value LIKE',
						'GROUP BY wp_posts.ID',
					),
					'whereContains'   => array( 'sq1.meta_value LIKE' ),
				),
			),
		);

		$generated_count = min( 3, self::GENERATED_CASES );
		for ( $i = 0; $i < $generated_count; $i++ ) {
			$cases[] = self::generated_wp_query_post_search_case( $ctx->fork( 'wp-query-post-search-' . $i ), $i );
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

	private static function user_query_semantic_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		unset( $ctx );

		return array(
			array(
				'label'     => 'fields-include-wins-invalid-orderby',
				'queryVars' => array(
					'blog_id'     => 0,
					'fields'      => array( 'ID', 'invalid', 'user_email' ),
					'include'     => array( 5, 'bad', 2 ),
					'exclude'     => array( 7, 8 ),
					'orderby'     => 'include bad_order',
					'order'       => 'ASC',
					'number'      => 10,
					'count_total' => false,
				),
				'expect'    => array(
					'fieldsExact'      => 'wp_users.ID,wp_users.user_email',
					'whereContains'    => array( 'wp_users.ID IN (5,0,2)' ),
					'whereNotContains' => array( 'ID NOT IN', '7,8' ),
					'orderbyContains'  => array( 'FIELD( wp_users.ID, 5,0,2 ) ASC' ),
					'orderbyNotContains' => array( 'bad_order' ),
					'limitExact'       => 'LIMIT 0, 10',
				),
			),
			array(
				'label'     => 'invalid-orderby-falls-back-to-login',
				'queryVars' => array(
					'blog_id'     => 0,
					'fields'      => 'ID',
					'orderby'     => 'bad_order another_bad_order',
					'order'       => 'sideways',
					'count_total' => false,
				),
				'expect'    => array(
					'orderbyExact'       => 'ORDER BY user_login DESC',
					'orderbyNotContains' => array( 'bad_order', 'another_bad_order' ),
				),
			),
			array(
				'label'     => 'login-and-nicename-in-field-order',
				'queryVars' => array(
					'blog_id'      => 0,
					'fields'       => 'ID',
					'login__in'    => array( 'beta', "quote'user" ),
					'nicename__in' => array( 'nice', "quote'name" ),
					'orderby'      => 'login__in nicename__in',
					'order'        => 'DESC',
					'count_total'  => false,
				),
				'expect'    => array(
					'whereContains'      => array( "user_login IN ( 'beta','quote\\'user' )", "user_nicename IN ( 'nice','quote\\'name' )" ),
					'orderbyContains'    => array( "FIELD( user_login, 'beta','quote\\'user' )", "FIELD( user_nicename, 'nice','quote\\'name' )" ),
					'orderbyNotContains' => array( ' ASC', ' DESC' ),
				),
			),
			array(
				'label'     => 'explicit-search-columns-allowlist',
				'queryVars' => array(
					'blog_id'        => 0,
					'fields'         => 'ID',
					'search'         => '*admin@example.com*',
					'search_columns' => array( 'user_email', 'bad_column' ),
					'count_total'    => false,
				),
				'expect'    => array(
					'whereContains'    => array( "user_email LIKE '%admin@example.com%'" ),
					'whereNotContains' => array( 'bad_column', 'user_login LIKE', 'display_name LIKE' ),
				),
			),
			array(
				'label'     => 'numeric-search-infers-login-and-id',
				'queryVars' => array(
					'blog_id'     => 0,
					'fields'      => 'ID',
					'search'      => '42',
					'count_total' => false,
				),
				'expect'    => array(
					'whereContains'    => array( "user_login LIKE '42'", "ID = '42'" ),
					'whereNotContains' => array( 'user_email LIKE', 'user_url LIKE', 'display_name LIKE' ),
				),
			),
			array(
				'label'     => 'url-search-infers-user-url',
				'queryVars' => array(
					'blog_id'     => 0,
					'fields'      => 'ID',
					'search'      => 'https://example.test/path',
					'count_total' => false,
				),
				'expect'    => array(
					'whereContains'    => array( "user_url LIKE 'https://example.test/path'" ),
					'whereNotContains' => array( 'user_login LIKE', 'user_email LIKE', 'display_name LIKE' ),
				),
			),
			array(
				'label'     => 'general-search-infers-public-user-columns',
				'queryVars' => array(
					'blog_id'     => 0,
					'fields'      => 'ID',
					'search'      => '*alpha*',
					'count_total' => false,
				),
				'expect'    => array(
					'whereContains' => array( 'user_login LIKE', 'user_url LIKE', 'user_email LIKE', 'user_nicename LIKE', 'display_name LIKE' ),
				),
			),
			array(
				'label'     => 'role-capability-blog-prefix-clauses',
				'queryVars' => array(
					'blog_id'            => 3,
					'fields'             => 'ID',
					'role'               => 'administrator, editor',
					'role__in'           => array( 'subscriber' ),
					'role__not_in'       => array( 'blocked' ),
					'capability'         => 'edit_posts',
					'capability__not_in' => array( 'read' ),
					'count_total'        => false,
				),
				'roles'     => true,
				'expect'    => array(
					'fromContains'  => array( 'wp_usermeta' ),
					'whereContains' => array( 'wp_capabilities', 'edit\\\\_posts', 'administrator', 'editor', 'subscriber', 'blocked', 'read', 'NOT LIKE' ),
					'whereNotContains' => array( 'wp_3_capabilities' ),
				),
			),
			array(
				'label'     => 'has-published-posts-nonzero-blog',
				'queryVars' => array(
					'blog_id'             => 4,
					'fields'              => 'ID',
					'has_published_posts' => array( 'post', 'page' ),
					'count_total'         => false,
				),
				'expect'    => array(
					'whereContains' => array( 'SELECT DISTINCT wp_posts.post_author', "wp_posts.post_status = 'publish'", "wp_posts.post_type IN ( 'post', 'page' )" ),
				),
			),
			array(
				'label'     => 'has-published-posts-zero-blog-noop',
				'queryVars' => array(
					'blog_id'             => 0,
					'fields'              => 'ID',
					'has_published_posts' => array( 'post' ),
					'count_total'         => false,
				),
				'expect'    => array(
					'whereNotContains' => array( 'SELECT DISTINCT', 'post_author', 'post_type IN' ),
				),
			),
		);
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
			'cache_results' => false,
			'fields'        => $ctx->choice( array( '', 'ids', 'id=>parent' ) ),
			'no_found_rows' => true,
			'offset'        => $ctx->int( 0, 3 ),
			'order'         => $ctx->choice( array( 'ASC', 'DESC', 'sideways' ) ),
			'orderby'       => $ctx->choice( array( 'date', 'ID', 'post__in', 'rand', 'meta_value_num' ) ),
			'paged'         => $ctx->int( 0, 3 ),
			'posts_per_page' => $ctx->choice( array( -1, 1, 3, '5' ) ),
		);

		if ( $ctx->bool( 30 ) ) {
			unset( $vars['tax_query'] );
		}
		if ( $ctx->bool( 30 ) ) {
			unset( $vars['meta_query'] );
		}

		return $vars;
	}

	private static function generated_wp_query_post_search_case( \ComponentFuzz\FuzzContext $ctx, int $index ): array {
		$search = $ctx->choice(
			array(
				'alpha "quoted generated" -omit',
				'one two three four five six seven eight nine ten',
				"line\r\nbreak wildcard_% value",
				'100%_generated',
				'a the generated',
			)
		);

		$query_vars = array(
			'exact'          => $ctx->bool( 35 ),
			'orderby'        => $ctx->choice( array( '', 'date', 'relevance' ) ),
			's'              => $search,
			'search_columns' => $ctx->choice(
				array(
					array(),
					array( 'post_title' ),
					array( 'post_title', 'post_content', 'bad_column' ),
					array( 'bad_column' ),
				)
			),
			'sentence'       => $ctx->bool( 25 ),
		);

		if ( '' === $query_vars['orderby'] ) {
			unset( $query_vars['orderby'] );
		}

		$filter_columns = $ctx->choice(
			array(
				null,
				array( 'post_title', 'post_excerpt' ),
				array( 'post_content', 'bad_column' ),
				array( 'bad_column' ),
			)
		);
		$stopwords      = $ctx->choice(
			array(
				null,
				array( 'alpha', 'generated' ),
				array( 'one', 'two', 'three' ),
			)
		);

		$case = array(
			'label'                     => 'generated-post-search-' . $index,
			'queryVars'                 => $query_vars,
			'termsInput'                => array( $search, 'a', 'the', '-omit' ),
			'exclusionPrefix'           => $ctx->choice( array( '-', '', '!' ) ),
			'allowAttachmentByFilename' => $ctx->bool( 30 ),
			'loggedIn'                  => $ctx->bool( 30 ),
			'expect'                    => array(
				'requestContains'    => array( 'FROM wp_posts' ),
				'requestNotContains' => array( 'bad_column' ),
				'whereNotContains'   => array( 'bad_column' ),
			),
		);

		if ( null !== $filter_columns ) {
			$case['filterSearchColumns'] = $filter_columns;
		}

		if ( null !== $stopwords ) {
			$case['stopwords'] = $stopwords;
		}

		return $case;
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

	private static function wp_query_execution_observation( array $case ): array {
		$query_vars                  = $case['queryVars'];
		$global_snapshot             = self::snapshot_globals();
		$queries_before              = self::wpdb_recorded_queries();
		$query                       = new \WP_Query();
		$posts                       = $query->query( $query_vars );
		$queries_after               = self::wpdb_recorded_queries();
		$new_queries                 = array_slice( $queries_after, count( $queries_before ) );
		$global_mismatches           = self::globals_snapshot_mismatches( $global_snapshot );
		$unexpected_global_mismatches = array_values( array_diff( $global_mismatches, self::wp_query_allowed_global_mismatches() ) );

		return array(
			'request'                    => (string) $query->request,
			'wpdbQueries'                => $queries_after,
			'wpdbNewQueries'             => $new_queries,
			'queryCountDelta'            => count( $new_queries ),
			'foundRowsQueryCount'        => self::found_rows_query_count( $new_queries ),
			'foundPosts'                 => (int) $query->found_posts,
			'maxNumPages'                => (int) $query->max_num_pages,
			'postCount'                  => (int) $query->post_count,
			'resultCount'                => is_array( $posts ) ? count( $posts ) : 0,
			'postIds'                    => self::post_ids_from_results( $posts ),
			'queryVars'                  => $query->query_vars,
			'flags'                      => self::wp_query_summary( $query )['flags'],
			'globalsUnchanged'           => array() === $unexpected_global_mismatches,
			'globalMismatches'           => $global_mismatches,
			'unexpectedGlobalMismatches' => $unexpected_global_mismatches,
			'globalSnapshot'             => $global_snapshot,
		);
	}

	private static function wp_query_post_search_observation( array $case ): array {
		$hook_snapshot         = self::snapshot_hook_runtime();
		$current_user_snapshot = self::snapshot_named_globals( array( 'current_user' ) );
		$filter_hits           = array(
			'postSearchColumns'        => 0,
			'searchExclusionPrefix'    => 0,
			'searchStopwords'          => 0,
			'allowAttachmentFilename'  => 0,
		);
		$post_search_columns_seen = array();
		$post_search_searches     = array();
		$observation              = array();

		$post_search_columns_filter = static function ( array $columns, string $search, \WP_Query $query ) use ( &$filter_hits, &$post_search_columns_seen, &$post_search_searches, $case ): array {
			unset( $query );

			++$filter_hits['postSearchColumns'];
			$post_search_columns_seen[] = array_values( $columns );
			$post_search_searches[]     = $search;

			return array_key_exists( 'filterSearchColumns', $case ) ? (array) $case['filterSearchColumns'] : $columns;
		};
		$exclusion_prefix_filter    = static function ( string $prefix ) use ( &$filter_hits, $case ): string {
			++$filter_hits['searchExclusionPrefix'];
			return array_key_exists( 'exclusionPrefix', $case ) ? (string) $case['exclusionPrefix'] : $prefix;
		};
		$stopwords_filter           = static function ( array $stopwords ) use ( &$filter_hits, $case ): array {
			++$filter_hits['searchStopwords'];
			return array_key_exists( 'stopwords', $case ) ? array_values( (array) $case['stopwords'] ) : $stopwords;
		};
		$allow_attachment_filter    = static function ( bool $allow ) use ( &$filter_hits, $case ): bool {
			unset( $allow );

			++$filter_hits['allowAttachmentFilename'];
			return ! empty( $case['allowAttachmentByFilename'] );
		};

		try {
			\add_filter( 'post_search_columns', $post_search_columns_filter, 10, 3 );
			\add_filter( 'wp_query_search_exclusion_prefix', $exclusion_prefix_filter, 10, 1 );
			\add_filter( 'wp_search_stopwords', $stopwords_filter, 10, 1 );
			\add_filter( 'wp_allow_query_attachment_by_filename', $allow_attachment_filter, 10, 1 );

			self::set_current_user_for_search( ! empty( $case['loggedIn'] ) );

			$parse_probe = self::wp_query_search_probe();
			$parse       = $parse_probe->component_fuzz_observe_search(
				$case['queryVars'],
				$case['termsInput'] ?? array( (string) ( $case['queryVars']['s'] ?? '' ) ),
				! empty( $case['allowAttachmentByFilename'] )
			);
			$execution   = self::wp_query_execution_observation(
				array(
					'queryVars' => self::wp_query_post_search_execution_vars( $case['queryVars'] ),
				)
			);

			$observation = array(
				'parse'                 => $parse,
				'execution'             => $execution,
				'filterHits'            => $filter_hits,
				'postSearchColumnsSeen' => $post_search_columns_seen,
				'postSearchSearches'    => $post_search_searches,
			);
		} finally {
			\remove_filter( 'wp_allow_query_attachment_by_filename', $allow_attachment_filter, 10 );
			\remove_filter( 'wp_search_stopwords', $stopwords_filter, 10 );
			\remove_filter( 'wp_query_search_exclusion_prefix', $exclusion_prefix_filter, 10 );
			\remove_filter( 'post_search_columns', $post_search_columns_filter, 10 );

			$callbacks_removed = ! self::hook_has_callback( 'post_search_columns', $post_search_columns_filter, 10 )
				&& ! self::hook_has_callback( 'wp_query_search_exclusion_prefix', $exclusion_prefix_filter, 10 )
				&& ! self::hook_has_callback( 'wp_search_stopwords', $stopwords_filter, 10 )
				&& ! self::hook_has_callback( 'wp_allow_query_attachment_by_filename', $allow_attachment_filter, 10 );

			self::restore_hook_runtime( $hook_snapshot );
			self::restore_named_globals( $current_user_snapshot );

			$observation['hookCallbacksRemoved'] = $callbacks_removed;
			$observation['hookRuntimeRestored']  = self::hook_runtime_matches( $hook_snapshot );
			$observation['currentUserRestored']  = self::named_globals_match_snapshot( $current_user_snapshot );
		}

		return $observation;
	}

	private static function wp_query_search_probe(): object {
		return new class() extends \WP_Query {
			public function component_fuzz_observe_search( array $query_vars, array $terms_input, bool $allow_attachment_by_filename ): array {
				$parsed_terms = $this->parse_search_terms( $terms_input );
				$stopwords    = $this->get_search_stopwords();
				$query_vars   = $this->fill_query_vars( $query_vars );
				$this->allow_query_attachment_by_filename = $allow_attachment_by_filename;
				$where                                   = $this->parse_search( $query_vars );
				$order                                   = '';

				if ( ! empty( $query_vars['s'] ) && ( ! empty( $query_vars['search_orderby_title'] ) || ( isset( $query_vars['orderby'] ) && 'relevance' === $query_vars['orderby'] ) ) ) {
					$order = (string) $this->parse_search_order( $query_vars );
				}

				return array(
					'where'              => $where,
					'order'              => $order,
					'queryVars'          => $query_vars,
					'searchTerms'        => array_values( (array) ( $query_vars['search_terms'] ?? array() ) ),
					'searchTermsCount'   => (int) ( $query_vars['search_terms_count'] ?? 0 ),
					'searchOrderbyTitle' => array_values( (array) ( $query_vars['search_orderby_title'] ?? array() ) ),
					'parsedTerms'        => array_values( $parsed_terms ),
					'stopwords'          => array_values( $stopwords ),
				);
			}
		};
	}

	private static function wp_query_post_search_execution_vars( array $query_vars ): array {
		return array_merge(
			array(
				'cache_results'          => false,
				'ignore_sticky_posts'    => true,
				'lazy_load_term_meta'    => false,
				'no_found_rows'          => true,
				'post_status'            => 'publish',
				'post_type'              => 'post',
				'posts_per_page'         => 3,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$query_vars
		);
	}

	private static function set_current_user_for_search( bool $logged_in ): void {
		if ( ! class_exists( '\WP_User' ) ) {
			unset( $GLOBALS['current_user'] );
			return;
		}

		$user     = new \WP_User();
		$user->ID = $logged_in ? 41 : 0;

		$GLOBALS['current_user'] = $user;
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

	private static function user_query_semantic_observation( array $case ): array {
		$roles_snapshot = array_key_exists( 'wp_roles', $GLOBALS )
			? array( 'exists' => true, 'value' => $GLOBALS['wp_roles'] )
			: array( 'exists' => false );

		try {
			if ( ! empty( $case['roles'] ) ) {
				$GLOBALS['wp_roles'] = self::user_query_roles_stub();
			}

			return self::user_query_parse_observation( $case['queryVars'] );
		} finally {
			if ( $roles_snapshot['exists'] ) {
				$GLOBALS['wp_roles'] = $roles_snapshot['value'];
			} else {
				unset( $GLOBALS['wp_roles'] );
			}
		}
	}

	private static function user_query_hook_mutation_observation( array $query_vars ): array {
		$hook_snapshot           = self::snapshot_hook_runtime();
		$pre_get_users_hits      = 0;
		$user_search_hits        = 0;
		$pre_user_query_hits     = 0;
		$search_columns_before   = array();
		$search_seen             = null;
		$hook_callbacks_removed  = false;
		$pre_get_users_callback  = static function ( \WP_User_Query $query ) use ( &$pre_get_users_hits ): void {
			++$pre_get_users_hits;
			$query->set( 'search', '*hooked*' );
			$query->set( 'search_columns', array( 'user_login', 'user_email', 'bad_column' ) );
			$query->set( 'orderby', 'ID' );
			$query->set( 'order', 'ASC' );
		};
		$search_columns_callback = static function ( array $columns, string $search, \WP_User_Query $query ) use ( &$user_search_hits, &$search_columns_before, &$search_seen ): array {
			unset( $query );

			++$user_search_hits;
			$search_columns_before = $columns;
			$search_seen           = $search;

			return array( 'display_name' );
		};
		$pre_user_query_callback = static function ( \WP_User_Query $query ) use ( &$pre_user_query_hits ): void {
			++$pre_user_query_hits;
			$query->query_orderby = 'ORDER BY user_email DESC';
			$query->query_where  .= ' AND 1=1 /*cfz_pre_user_query*/';
		};

		try {
			\add_filter( 'pre_get_users', $pre_get_users_callback, 10, 1 );
			\add_filter( 'user_search_columns', $search_columns_callback, 10, 3 );
			\add_filter( 'pre_user_query', $pre_user_query_callback, 10, 1 );

			$observation = self::user_query_parse_observation( $query_vars );
		} finally {
			\remove_filter( 'pre_get_users', $pre_get_users_callback, 10 );
			\remove_filter( 'user_search_columns', $search_columns_callback, 10 );
			\remove_filter( 'pre_user_query', $pre_user_query_callback, 10 );
			$hook_callbacks_removed = ! self::hook_has_callback( 'pre_get_users', $pre_get_users_callback, 10 )
				&& ! self::hook_has_callback( 'user_search_columns', $search_columns_callback, 10 )
				&& ! self::hook_has_callback( 'pre_user_query', $pre_user_query_callback, 10 );
			self::restore_hook_runtime( $hook_snapshot );
		}

		return $observation + array(
			'preGetUsersHits'      => $pre_get_users_hits,
			'userSearchColumnsHits' => $user_search_hits,
			'preUserQueryHits'     => $pre_user_query_hits,
			'searchColumnsBefore'  => $search_columns_before,
			'searchSeen'           => $search_seen,
			'hookCallbacksRemoved' => $hook_callbacks_removed,
			'hookRuntimeRestored'  => self::hook_runtime_matches( $hook_snapshot ),
		);
	}

	private static function user_query_roles_stub(): object {
		return new class() {
			public array $roles = array(
				'administrator' => array(
					'capabilities' => array(
						'edit_posts'     => true,
						'manage_options' => true,
					),
				),
				'editor'        => array(
					'capabilities' => array(
						'edit_posts'    => true,
						'publish_posts' => true,
					),
				),
				'subscriber'    => array(
					'capabilities' => array(
						'read' => true,
					),
				),
			);

			public function for_site( $blog_id = null ): void {
				unset( $blog_id );
			}
		};
	}

	private static function user_query_pre_query_observation( array $query_vars ): array {
		$queries_before = self::wpdb_recorded_queries();
		$filter_hits    = 0;
		$callback       = static function ( $results, \WP_User_Query $query ) use ( &$filter_hits ) {
			unset( $results );

			++$filter_hits;
			$query->total_users = 2;

			return array( 41, 43 );
		};

		\add_filter( 'users_pre_query', $callback, 10, 2 );
		try {
			$query   = new \WP_User_Query( $query_vars );
			$results = $query->get_results();
			$total   = $query->get_total();
		} finally {
			\remove_filter( 'users_pre_query', $callback, 10 );
		}

		$queries_after = self::wpdb_recorded_queries();

		return array(
			'filterHits'      => $filter_hits,
			'queryCountDelta' => count( $queries_after ) - count( $queries_before ),
			'results'         => array_map( 'intval', (array) $results ),
			'totalUsers'      => (int) $total,
			'queryVars'       => $query->query_vars,
		);
	}

	private static function comment_query_parse_observation( array $query_vars ): array {
		$query = new \WP_Comment_Query();
		$query->parse_query( $query_vars );

		return array(
			'queryVars' => $query->query_vars,
		);
	}

	private static function comment_query_pre_query_observation( array $query_vars, bool $count ): array {
		$queries_before = self::wpdb_recorded_queries();
		$filter_hits    = 0;
		$callback       = static function ( $comment_data, \WP_Comment_Query $query ) use ( &$filter_hits, $count ) {
			unset( $comment_data );

			++$filter_hits;
			if ( $count ) {
				return 4;
			}

			$query->found_comments = 2;
			$query->max_num_pages  = 1;

			return array(
				(object) array(
					'comment_ID'      => 301,
					'comment_post_ID' => 3,
					'comment_content' => 'seed alpha',
				),
				(object) array(
					'comment_ID'      => 303,
					'comment_post_ID' => 7,
					'comment_content' => 'seed beta',
				),
			);
		};

		\add_filter( 'comments_pre_query', $callback, 10, 2 );
		try {
			$query  = new \WP_Comment_Query();
			$result = $query->query( $query_vars );
		} finally {
			\remove_filter( 'comments_pre_query', $callback, 10 );
		}

		$queries_after = self::wpdb_recorded_queries();

		return array(
			'filterHits'         => $filter_hits,
			'queryCountDelta'    => count( $queries_after ) - count( $queries_before ),
			'commentIds'         => $count ? array() : self::comment_ids_from_results( $result ),
			'propertyCommentIds' => self::comment_ids_from_results( $query->comments ),
			'countResult'        => $count ? (int) $result : null,
			'foundComments'      => (int) $query->found_comments,
			'maxNumPages'        => (int) $query->max_num_pages,
			'queryVars'          => $query->query_vars,
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

	private static function is_wp_query_post_search_observation( $value ): bool {
		return is_array( $value )
			&& is_array( $value['parse'] ?? null )
			&& is_string( $value['parse']['where'] ?? null )
			&& is_string( $value['parse']['order'] ?? null )
			&& is_array( $value['parse']['queryVars'] ?? null )
			&& is_array( $value['parse']['searchTerms'] ?? null )
			&& is_int( $value['parse']['searchTermsCount'] ?? null )
			&& is_array( $value['parse']['searchOrderbyTitle'] ?? null )
			&& is_array( $value['parse']['parsedTerms'] ?? null )
			&& is_array( $value['parse']['stopwords'] ?? null )
			&& self::is_wp_query_execution_observation( $value['execution'] ?? null )
			&& is_array( $value['filterHits'] ?? null )
			&& is_array( $value['postSearchColumnsSeen'] ?? null )
			&& is_array( $value['postSearchSearches'] ?? null )
			&& is_bool( $value['hookCallbacksRemoved'] ?? null )
			&& is_bool( $value['hookRuntimeRestored'] ?? null )
			&& is_bool( $value['currentUserRestored'] ?? null );
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

	private static function wp_query_post_search_parse_expectations_match( array $observation, array $expect ): bool {
		$parse      = $observation['parse'];
		$query_vars = $parse['queryVars'];

		foreach (
			array(
				'searchTermsExact' => 'searchTerms',
				'parsedTermsExact' => 'parsedTerms',
			) as $expect_key => $parse_key
		) {
			if ( array_key_exists( $expect_key, $expect ) && array_values( $expect[ $expect_key ] ) !== array_values( $parse[ $parse_key ] ) ) {
				return false;
			}
		}

		if ( array_key_exists( 'searchTermsCount', $expect ) && (int) $expect['searchTermsCount'] !== (int) $parse['searchTermsCount'] ) {
			return false;
		}

		if ( array_key_exists( 'normalizedSearch', $expect ) && (string) $expect['normalizedSearch'] !== (string) ( $query_vars['s'] ?? '' ) ) {
			return false;
		}

		if ( ! empty( $expect['singleSearchTermEqualsSearch'] ) ) {
			if ( 1 !== count( $parse['searchTerms'] ) || (string) ( $query_vars['s'] ?? '' ) !== (string) $parse['searchTerms'][0] ) {
				return false;
			}
		}

		return self::query_vars_are_scalar_array_safe( $query_vars )
			&& self::sql_string_is_balanced( $parse['where'] )
			&& self::sql_has_no_unexpanded_placeholders( $parse['where'] )
			&& self::sql_string_is_balanced( $parse['order'] )
			&& self::sql_has_no_unexpanded_placeholders( $parse['order'] );
	}

	private static function wp_query_post_search_sql_is_safe( array $observation ): bool {
		$parse   = $observation['parse'];
		$request = $observation['execution']['request'];

		return self::sql_string_is_balanced( $parse['where'] )
			&& self::sql_has_no_unexpanded_placeholders( $parse['where'] )
			&& self::sql_has_no_empty_condition_groups( $parse['where'] )
			&& self::sql_string_is_balanced( $parse['order'] )
			&& self::sql_has_no_unexpanded_placeholders( $parse['order'] )
			&& self::wp_query_request_sql_is_safe( $request )
			&& self::wp_query_recorded_sql_is_safe( $observation['execution']['wpdbNewQueries'] );
	}

	private static function wp_query_post_search_sql_expectations_match( array $observation, array $expect ): bool {
		$targets = array(
			'where'   => $observation['parse']['where'],
			'order'   => $observation['parse']['order'],
			'request' => $observation['execution']['request'],
		);

		foreach (
			array(
				'whereContains'   => array( 'where', true ),
				'whereNotContains' => array( 'where', false ),
				'orderContains'   => array( 'order', true ),
				'orderNotContains' => array( 'order', false ),
				'requestContains' => array( 'request', true ),
				'requestNotContains' => array( 'request', false ),
			) as $expect_key => $rule
		) {
			$target = $targets[ $rule[0] ];
			foreach ( $expect[ $expect_key ] ?? array() as $needle ) {
				$contains = str_contains( $target, (string) $needle );
				if ( (bool) $rule[1] !== $contains ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function wp_query_post_search_filter_expectations_match( array $observation, array $case ): bool {
		if ( ! $observation['hookCallbacksRemoved'] || ! $observation['hookRuntimeRestored'] || ! $observation['currentUserRestored'] ) {
			return false;
		}

		$filter_hits = $observation['filterHits'];
		$stopwords_expected = ! empty( $case['queryVars']['sentence'] ) ? 1 : 2;
		foreach (
			array(
				'postSearchColumns'       => 2,
				'searchExclusionPrefix'   => 2,
				'searchStopwords'         => $stopwords_expected,
				'allowAttachmentFilename' => 1,
			) as $filter => $expected_count
		) {
			if ( (int) ( $filter_hits[ $filter ] ?? -1 ) !== $expected_count ) {
				return false;
			}
		}

		$expect = $case['expect'] ?? array();
		if ( array_key_exists( 'postSearchColumnsBefore', $expect ) && array_values( $expect['postSearchColumnsBefore'] ) !== $observation['postSearchColumnsSeen'] ) {
			return false;
		}

		foreach ( $observation['postSearchSearches'] as $search ) {
			if ( (string) ( $observation['parse']['queryVars']['s'] ?? '' ) !== (string) $search ) {
				return false;
			}
		}

		return true;
	}

	private static function user_query_sql_expectations_match( array $observation, array $expect ): bool {
		foreach (
			array(
				'fieldsExact'  => 'queryFields',
				'orderbyExact' => 'queryOrderby',
				'limitExact'   => 'queryLimit',
			) as $expect_key => $observation_key
		) {
			if ( array_key_exists( $expect_key, $expect ) && (string) $expect[ $expect_key ] !== (string) ( $observation[ $observation_key ] ?? '' ) ) {
				return false;
			}
		}

		foreach (
			array(
				'fieldsContains'  => 'queryFields',
				'fromContains'    => 'queryFrom',
				'whereContains'   => 'queryWhere',
				'orderbyContains' => 'queryOrderby',
				'limitContains'   => 'queryLimit',
				'sqlContains'     => 'sql',
			) as $expect_key => $observation_key
		) {
			foreach ( $expect[ $expect_key ] ?? array() as $needle ) {
				if ( ! str_contains( (string) ( $observation[ $observation_key ] ?? '' ), (string) $needle ) ) {
					return false;
				}
			}
		}

		foreach (
			array(
				'fieldsNotContains'  => 'queryFields',
				'fromNotContains'    => 'queryFrom',
				'whereNotContains'   => 'queryWhere',
				'orderbyNotContains' => 'queryOrderby',
				'limitNotContains'   => 'queryLimit',
				'sqlNotContains'     => 'sql',
			) as $expect_key => $observation_key
		) {
			foreach ( $expect[ $expect_key ] ?? array() as $needle ) {
				if ( str_contains( (string) ( $observation[ $observation_key ] ?? '' ), (string) $needle ) ) {
					return false;
				}
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

	private static function is_wp_query_execution_observation( $value ): bool {
		return is_array( $value )
			&& is_string( $value['request'] ?? null )
			&& is_array( $value['wpdbQueries'] ?? null )
			&& is_array( $value['wpdbNewQueries'] ?? null )
			&& is_int( $value['queryCountDelta'] ?? null )
			&& is_int( $value['foundRowsQueryCount'] ?? null )
			&& is_int( $value['foundPosts'] ?? null )
			&& is_int( $value['maxNumPages'] ?? null )
			&& is_int( $value['postCount'] ?? null )
			&& is_int( $value['resultCount'] ?? null )
			&& is_array( $value['postIds'] ?? null )
			&& is_array( $value['queryVars'] ?? null )
			&& is_array( $value['flags'] ?? null )
			&& is_bool( $value['globalsUnchanged'] ?? null )
			&& is_array( $value['globalSnapshot'] ?? null );
	}

	private static function wp_query_request_sql_is_safe( string $sql ): bool {
		return '' !== $sql
			&& str_contains( $sql, 'wp_posts' )
			&& self::sql_string_is_balanced( $sql )
			&& self::sql_has_no_unexpanded_placeholders( $sql )
			&& self::sql_has_no_empty_condition_groups( $sql );
	}

	private static function wp_query_recorded_sql_is_safe( array $queries ): bool {
		foreach ( $queries as $query ) {
			if ( ! is_string( $query ) || ! self::sql_string_is_balanced( $query ) || ! self::sql_has_no_unexpanded_placeholders( $query ) ) {
				return false;
			}
		}

		return true;
	}

	private static function wp_query_execution_expectations_match( array $observation, array $expect ): bool {
		if ( array_key_exists( 'foundPosts', $expect ) && (int) $expect['foundPosts'] !== $observation['foundPosts'] ) {
			return false;
		}

		if ( array_key_exists( 'maxNumPages', $expect ) && (int) $expect['maxNumPages'] !== $observation['maxNumPages'] ) {
			return false;
		}

		if ( array_key_exists( 'foundRowsQueryCount', $expect ) && (int) $expect['foundRowsQueryCount'] !== $observation['foundRowsQueryCount'] ) {
			return false;
		}

		if ( array_key_exists( 'postIds', $expect ) && array_values( $expect['postIds'] ) !== $observation['postIds'] ) {
			return false;
		}

		foreach ( $expect['queryVarEquals'] ?? array() as $key => $value ) {
			if ( ! array_key_exists( $key, $observation['queryVars'] ) || $value !== $observation['queryVars'][ $key ] ) {
				return false;
			}
		}

		return true;
	}

	private static function wp_query_result_window_is_coherent( array $observation ): bool {
		if ( $observation['postCount'] !== $observation['resultCount'] ) {
			return false;
		}

		if ( count( $observation['postIds'] ) !== $observation['resultCount'] ) {
			return false;
		}

		$query_vars = $observation['queryVars'];
		$per_page   = (int) ( $query_vars['posts_per_page'] ?? 0 );
		$nopaging   = (bool) ( $query_vars['nopaging'] ?? false );

		if ( ! $nopaging && $per_page > 0 && $observation['resultCount'] > $per_page ) {
			return false;
		}

		return true;
	}

	private static function wp_query_found_rows_contract_holds( array $observation ): bool {
		$query_vars    = $observation['queryVars'];
		$no_found_rows = (bool) ( $query_vars['no_found_rows'] ?? false );
		$has_limits    = self::sql_has_limit_clause( $observation['request'] );

		if ( $no_found_rows ) {
			return 0 === $observation['foundRowsQueryCount']
				&& 0 === $observation['foundPosts']
				&& 0 === $observation['maxNumPages'];
		}

		if ( $has_limits && $observation['postCount'] > 0 && 0 === $observation['foundRowsQueryCount'] ) {
			return false;
		}

		if ( ! $has_limits && $observation['foundPosts'] !== $observation['postCount'] ) {
			return false;
		}

		if ( $has_limits ) {
			$per_page          = max( 1, (int) ( $query_vars['posts_per_page'] ?? 1 ) );
			$expected_max_page = (int) ceil( $observation['foundPosts'] / $per_page );

			if ( $expected_max_page !== $observation['maxNumPages'] ) {
				return false;
			}
		}

		return true;
	}

	private static function wp_query_stub_result_expectations_match( array $observation, array $expect ): bool {
		foreach ( array( 'foundPosts', 'maxNumPages', 'foundRowsQueryCount' ) as $key ) {
			if ( array_key_exists( $key, $expect ) && (int) $expect[ $key ] !== $observation[ $key ] ) {
				return false;
			}
		}

		if ( array_key_exists( 'postIds', $expect ) && array_values( $expect['postIds'] ) !== $observation['postIds'] ) {
			return false;
		}

		return true;
	}

	private static function sql_has_limit_clause( string $sql ): bool {
		return 1 === preg_match( '/\bLIMIT\s+\d+(?:\s*,\s*\d+)?\b/i', $sql );
	}

	private static function sql_has_no_empty_condition_groups( string $sql ): bool {
		$sql_without_strings = preg_replace( "/'(?:''|\\\\'|[^'])*'/", "''", $sql );
		if ( ! is_string( $sql_without_strings ) ) {
			return false;
		}

		return 0 === preg_match( '/(?<![A-Za-z0-9_])\(\s*\)/', $sql_without_strings );
	}

	private static function found_rows_query_count( array $queries ): int {
		$count = 0;
		foreach ( $queries as $query ) {
			if ( is_string( $query ) && 1 === preg_match( '/^\s*SELECT\s+FOUND_ROWS\s*\(\s*\)/i', $query ) ) {
				++$count;
			}
		}

		return $count;
	}

	private static function wp_query_execution_vars_hold( array $query_vars ): bool {
		foreach ( array( 'posts_per_page', 'paged', 'offset' ) as $key ) {
			if ( array_key_exists( $key, $query_vars ) && ! is_int( $query_vars[ $key ] ) ) {
				return false;
			}
		}

		foreach ( array( 'cache_results', 'ignore_sticky_posts', 'no_found_rows', 'update_post_meta_cache', 'update_post_term_cache' ) as $key ) {
			if ( array_key_exists( $key, $query_vars ) && ! is_bool( $query_vars[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function wp_query_expected_flags_match( array $flags, array $expected ): bool {
		foreach ( $expected as $flag => $value ) {
			if ( ! array_key_exists( $flag, $flags ) || (bool) $value !== (bool) $flags[ $flag ] ) {
				return false;
			}
		}

		return true;
	}

	private static function wp_query_execution_observations_match( array $first, $second ): bool {
		return self::is_wp_query_execution_observation( $second )
			&& $first['request'] === $second['request']
			&& $first['queryVars'] === $second['queryVars']
			&& $first['flags'] === $second['flags']
			&& $first['foundPosts'] === $second['foundPosts']
			&& $first['maxNumPages'] === $second['maxNumPages']
			&& $first['postCount'] === $second['postCount']
			&& $first['resultCount'] === $second['resultCount']
			&& $first['postIds'] === $second['postIds'];
	}

	private static function wp_query_post_search_observations_match( array $first, $second ): bool {
		return self::is_wp_query_post_search_observation( $second )
			&& $first['parse'] === $second['parse']
			&& self::wp_query_execution_observations_match( $first['execution'], $second['execution'] )
			&& $first['filterHits'] === $second['filterHits']
			&& $first['postSearchColumnsSeen'] === $second['postSearchColumnsSeen']
			&& $first['postSearchSearches'] === $second['postSearchSearches']
			&& $first['hookCallbacksRemoved'] === $second['hookCallbacksRemoved']
			&& $first['hookRuntimeRestored'] === $second['hookRuntimeRestored']
			&& $first['currentUserRestored'] === $second['currentUserRestored'];
	}

	private static function wp_query_cache_key_for( array $args, string $sql ): string {
		$query = new class() extends \WP_Query {
			public function component_fuzz_cache_key( array $args, string $sql ): string {
				return $this->generate_cache_key( $args, $sql );
			}
		};

		return $query->component_fuzz_cache_key( $args, $sql );
	}

	private static function object_cache_group_snapshot( string $group ): array {
		$object_cache = $GLOBALS['wp_object_cache'] ?? null;
		if ( ! is_object( $object_cache ) || ! property_exists( $object_cache, 'cache' ) ) {
			return array( 'supported' => false );
		}

		$property = new \ReflectionProperty( $object_cache, 'cache' );
		$cache    = $property->getValue( $object_cache );
		if ( ! is_array( $cache ) ) {
			return array( 'supported' => false );
		}

		return array(
			'supported' => true,
			'exists'    => array_key_exists( $group, $cache ),
			'value'     => $cache[ $group ] ?? null,
		);
	}

	private static function restore_object_cache_group_snapshot( string $group, array $snapshot ): void {
		if ( empty( $snapshot['supported'] ) ) {
			return;
		}

		$object_cache = $GLOBALS['wp_object_cache'] ?? null;
		if ( ! is_object( $object_cache ) || ! property_exists( $object_cache, 'cache' ) ) {
			return;
		}

		$property = new \ReflectionProperty( $object_cache, 'cache' );
		$cache    = $property->getValue( $object_cache );
		if ( ! is_array( $cache ) ) {
			return;
		}

		if ( ! empty( $snapshot['exists'] ) ) {
			$cache[ $group ] = $snapshot['value'];
			$property->setValue( $object_cache, $cache );
			return;
		}

		unset( $cache[ $group ] );
		$property->setValue( $object_cache, $cache );
	}

	private static function post_ids_from_results( $posts ): array {
		if ( ! is_array( $posts ) ) {
			return array();
		}

		$ids = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$ids[] = (int) $post->ID;
			} elseif ( is_numeric( $post ) ) {
				$ids[] = (int) $post;
			}
		}

		return $ids;
	}

	private static function comment_ids_from_results( $comments ): array {
		if ( ! is_array( $comments ) ) {
			return array();
		}

		$ids = array();
		foreach ( $comments as $comment ) {
			if ( is_object( $comment ) && isset( $comment->comment_ID ) ) {
				$ids[] = (int) $comment->comment_ID;
			} elseif ( is_numeric( $comment ) ) {
				$ids[] = (int) $comment;
			}
		}

		return $ids;
	}

	private static function wpdb_recorded_queries(): array {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_queries' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_get_queries();
		}

		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->queries ) && is_array( $GLOBALS['wpdb']->queries ) ) {
			return $GLOBALS['wpdb']->queries;
		}

		return array();
	}

	private static function wp_query_allowed_global_mismatches(): array {
		return array(
			'current_user:exists',
			'wp_actions:value',
			'wp_filters:value',
			'wp_post_types:exists',
			'wp_the_query:exists',
		);
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

	private static function seed_query_fixture(): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return;
		}

		if ( method_exists( $wpdb, 'component_fuzz_reset_content' ) ) {
			$wpdb->component_fuzz_reset_content();
		}

		$posts = array(
			array(
				'ID'                => 3,
				'post_author'       => 41,
				'post_date'         => '2020-01-15 10:00:00',
				'post_modified'     => '2020-01-16 10:00:00',
				'post_name'         => 'alpha-seed',
				'post_status'       => 'publish',
				'post_title'        => 'Alpha quoted phrase',
				'post_type'         => 'post',
				'comment_count'     => '2',
			),
			array(
				'ID'                => 7,
				'post_author'       => 43,
				'post_date'         => '2020-02-20 11:30:00',
				'post_modified'     => '2020-02-21 11:30:00',
				'post_name'         => 'beta-seed',
				'post_status'       => 'publish',
				'post_title'        => 'Beta seed excluded',
				'post_type'         => 'post',
				'comment_count'     => '1',
			),
			array(
				'ID'                => 11,
				'post_author'       => 43,
				'post_date'         => '2020-03-01 09:00:00',
				'post_modified'     => '2020-03-02 09:00:00',
				'post_name'         => 'draft-seed',
				'post_status'       => 'draft',
				'post_title'        => 'Draft seed',
				'post_type'         => 'post',
				'comment_count'     => '0',
			),
			array(
				'ID'                => 13,
				'post_author'       => 41,
				'post_date'         => '2020-02-01 08:00:00',
				'post_modified'     => '2020-02-01 08:00:00',
				'post_name'         => 'page-seed',
				'post_status'       => 'publish',
				'post_title'        => 'Page seed',
				'post_type'         => 'page',
				'comment_count'     => '0',
			),
			array(
				'ID'                => 19,
				'post_author'       => 47,
				'post_date'         => '2020-04-01 12:00:00',
				'post_modified'     => '2020-04-02 12:00:00',
				'post_name'         => 'private-seed',
				'post_status'       => 'private',
				'post_title'        => 'Private seed',
				'post_type'         => 'post',
				'comment_count'     => '0',
			),
			array(
				'ID'                => 23,
				'post_author'       => 41,
				'post_date'         => '2020-05-05 13:00:00',
				'post_modified'     => '2020-05-06 13:00:00',
				'post_name'         => 'gamma-seed',
				'post_status'       => 'publish',
				'post_title'        => 'Gamma seed',
				'post_type'         => 'post',
				'comment_count'     => '3',
			),
		);

		foreach ( $posts as $post ) {
			$wpdb->insert( $wpdb->posts, $post );
		}

		$terms = array(
			array(
				'term_id' => 101,
				'name'    => 'Alpha Term',
				'slug'    => 'alpha-term',
			),
			array(
				'term_id' => 103,
				'name'    => 'Beta Term',
				'slug'    => 'beta-term',
			),
		);
		foreach ( $terms as $term ) {
			$wpdb->insert( $wpdb->terms, $term );
		}

		$taxonomies = array(
			array(
				'term_taxonomy_id' => 5,
				'term_id'          => 101,
				'taxonomy'         => self::TAXONOMY,
				'count'            => 2,
			),
			array(
				'term_taxonomy_id' => 9,
				'term_id'          => 103,
				'taxonomy'         => self::TAXONOMY,
				'count'            => 1,
			),
		);
		foreach ( $taxonomies as $taxonomy ) {
			$wpdb->insert( $wpdb->term_taxonomy, $taxonomy );
		}

		foreach (
			array(
				array( 'object_id' => 3, 'term_taxonomy_id' => 5 ),
				array( 'object_id' => 7, 'term_taxonomy_id' => 9 ),
				array( 'object_id' => 23, 'term_taxonomy_id' => 5 ),
			) as $relationship
		) {
			$wpdb->insert( $wpdb->term_relationships, $relationship );
		}

		foreach (
			array(
				array( 'post_id' => 3, 'meta_key' => 'color', 'meta_value' => 'blue' ),
				array( 'post_id' => 3, 'meta_key' => 'rating', 'meta_value' => '5' ),
				array( 'post_id' => 7, 'meta_key' => 'color', 'meta_value' => 'red' ),
				array( 'post_id' => 23, 'meta_key' => 'color', 'meta_value' => 'blue' ),
			) as $meta
		) {
			$wpdb->insert( $wpdb->postmeta, $meta );
		}

		foreach (
			array(
				array(
					'ID'              => 41,
					'user_login'      => 'alpha',
					'user_email'      => 'alpha@example.test',
					'user_registered' => '2020-01-01 00:00:00',
					'display_name'    => 'Alpha User',
				),
				array(
					'ID'              => 43,
					'user_login'      => 'beta',
					'user_email'      => 'beta@example.test',
					'user_registered' => '2020-02-01 00:00:00',
					'display_name'    => 'Beta User',
				),
			) as $user
		) {
			$wpdb->insert( $wpdb->users, $user );
		}

		foreach (
			array(
				array(
					'comment_ID'           => 301,
					'comment_post_ID'      => 3,
					'comment_author_email' => 'alpha@example.test',
					'comment_content'      => 'seed alpha',
					'comment_approved'     => '1',
				),
				array(
					'comment_ID'           => 303,
					'comment_post_ID'      => 7,
					'comment_author_email' => 'beta@example.test',
					'comment_content'      => 'seed beta',
					'comment_approved'     => '0',
				),
			) as $comment
		) {
			$wpdb->insert( $wpdb->comments, $comment );
		}
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
		if ( class_exists( '\Component_Fuzz_WPDB_Stub' ) ) {
			return new \Component_Fuzz_WPDB_Stub(
				array(
					'home'    => 'http://example.test',
					'siteurl' => 'http://example.test',
				)
			);
		}

		return new class() {
			public $suppress_errors = false;
			public $last_query = '';
			public $num_rows = 0;
			public $queries = array();
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
				unset( $x, $y );
				$this->record_query( $query );
				return null;
			}

			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				unset( $output, $y );
				$this->record_query( $query );
				return null;
			}

			public function get_results( $query = null, $output = OBJECT ) {
				unset( $output );
				$this->record_query( $query );
				return array();
			}

			public function get_col( $query = null, $x = 0 ) {
				unset( $x );
				$this->record_query( $query );
				return array();
			}

			public function query( $query ) {
				$this->record_query( $query );
				return false;
			}

			public function get_blog_prefix( $blog_id = null ) {
				unset( $blog_id );
				return 'wp_';
			}

			private function record_query( $query ): void {
				if ( null === $query ) {
					return;
				}

				$this->last_query = (string) $query;
				$this->queries[]  = $this->last_query;
				$this->num_rows   = 0;
			}
		};
	}

	private static function snapshot_hook_runtime(): array {
		$snapshot = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter' ) as $name ) {
			$snapshot[ $name ] = array_key_exists( $name, $GLOBALS )
				? array(
					'exists' => true,
					'value'  => self::clone_snapshot_value( $GLOBALS[ $name ] ),
				)
				: array( 'exists' => false );
		}

		return $snapshot;
	}

	private static function restore_hook_runtime( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function hook_runtime_matches( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( (bool) $entry['exists'] !== $exists ) {
				return false;
			}

			if ( $entry['exists'] && $GLOBALS[ $name ] !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function hook_has_callback( string $hook, $callback, int $priority ): bool {
		if ( function_exists( 'has_filter' ) ) {
			return true === \has_filter( $hook, $callback, $priority );
		}

		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) || ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
			return false;
		}

		$hook_value = $GLOBALS['wp_filter'][ $hook ];
		if ( is_object( $hook_value ) && isset( $hook_value->callbacks ) ) {
			$callbacks = $hook_value->callbacks[ $priority ] ?? array();
		} elseif ( is_array( $hook_value ) ) {
			$callbacks = $hook_value[ $priority ] ?? array();
		} else {
			return false;
		}

		foreach ( $callbacks as $entry ) {
			if ( is_array( $entry ) && ( $entry['function'] ?? null ) === $callback ) {
				return true;
			}
		}

		return false;
	}

	private static function clone_snapshot_value( $value ) {
		if ( is_object( $value ) ) {
			if ( $value instanceof \Closure ) {
				return $value;
			}

			return clone $value;
		}

		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $entry ) {
				$copy[ $key ] = self::clone_snapshot_value( $entry );
			}

			return $copy;
		}

		return $value;
	}

	private static function snapshot_named_globals( array $names ): array {
		$snapshot = array();

		foreach ( $names as $name ) {
			$snapshot[ $name ] = array_key_exists( $name, $GLOBALS )
				? array( 'exists' => true, 'value' => $GLOBALS[ $name ] )
				: array( 'exists' => false );
		}

		return $snapshot;
	}

	private static function restore_named_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function named_globals_match_snapshot( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( (bool) $entry['exists'] !== $exists ) {
				return false;
			}

			if ( $entry['exists'] && $entry['value'] !== $GLOBALS[ $name ] ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_globals(): array {
		$names    = array(
			'wpdb',
			'wp_filter',
			'wp_actions',
			'wp_filters',
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

	private static function globals_match_snapshot( array $snapshot ): bool {
		return array() === self::globals_snapshot_mismatches( $snapshot );
	}

	private static function globals_snapshot_mismatches( array $snapshot ): array {
		$mismatches = array();

		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( (bool) $entry['exists'] !== $exists ) {
				$mismatches[] = $name . ':exists';
				continue;
			}

			if ( ! $entry['exists'] ) {
				continue;
			}

			if ( $entry['value'] !== $GLOBALS[ $name ] ) {
				$mismatches[] = $name . ':value';
			}
		}

		return $mismatches;
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
