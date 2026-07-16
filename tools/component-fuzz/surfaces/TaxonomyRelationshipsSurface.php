<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes DB-backed taxonomy object relationship APIs against the in-memory wpdb stub.
 */
final class TaxonomyRelationshipsSurface {
	public const NAME = 'taxonomy-relationships';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'taxonomy-relationships.bootstrap-apis-available',
					'Required WordPress taxonomy relationship APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$case     = self::case_for_context( $ctx );
		$rows     = array();

		try {
			self::prepare_runtime( $case );
			$rows[] = self::check_replace_and_append( $ctx->fork( 'set' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_generated_assignment_cases( $ctx->fork( 'generated-assignments' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_field_variants_and_membership( $ctx->fork( 'fields' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_ordering_and_counts( $ctx->fork( 'ordering-counts' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_remove_scope( $ctx->fork( 'remove' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_invalid_inputs( $ctx->fork( 'invalid' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_get_the_terms_cache( $ctx->fork( 'cache' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_object_term_cache_priming_and_cleaning( $ctx->fork( 'cache-prime-clean' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_generated_mutation_sequence( $ctx->fork( 'mutation-sequence' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_filter_action_locality( $ctx->fork( 'hooks' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'taxonomy-relationships.surface-no-throw',
				array(
					'case'      => self::case_summary( $case ),
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_state( $snapshot );
			$rows[] = self::check_state_restored( $ctx, $case, $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Error', 'WP_Post', 'WP_Rewrite', 'WP_Taxonomy', 'WP_Term', 'Component_Fuzz_WPDB_Stub' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'get_objects_in_term',
				'get_object_taxonomies',
				'get_post',
				'get_taxonomy',
				'get_term',
				'get_the_terms',
				'has_filter',
				'is_object_in_term',
				'is_wp_error',
				'post_type_exists',
				'register_post_type',
				'register_taxonomy',
				'register_taxonomy_for_object_type',
				'remove_action',
				'remove_filter',
				'sanitize_title',
				'term_exists',
				'taxonomy_exists',
				'clean_object_term_cache',
				'update_object_term_cache',
				'wp_cache_add_multiple',
				'wp_cache_delete',
				'wp_cache_delete_multiple',
				'wp_cache_flush',
				'wp_cache_get',
				'wp_cache_get_multiple',
				'wp_get_object_terms',
				'wp_insert_post',
				'wp_insert_term',
				'wp_delete_object_term_relationships',
				'wp_remove_object_terms',
				'wp_set_current_user',
				'wp_set_object_terms',
				'wp_slash',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_replace_and_append( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'replace' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.set-replace-append',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$failures  = array();
		$post_id   = $fixture['postId'];
		$primary   = $fixture['primary'];
		$secondary = $fixture['secondary'];

		$initial = \wp_set_object_terms(
			$post_id,
			array( $primary['alpha']['term_id'], $primary['beta']['slug'] ),
			$case['primaryTaxonomy'],
			false
		);
		self::collect_failure(
			$failures,
			self::same_int_set( $initial, self::tt_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $primary ),
					self::tt_ids( array( $primary['alpha'], $primary['beta'] ) )
				),
			'append=false creates exactly the requested primary relationships',
			array(
				'returned'      => $initial,
				'relationships' => self::relationship_rows( $post_id ),
			)
		);

		$secondary_set = \wp_set_object_terms( $post_id, array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], false );
		$replace       = \wp_set_object_terms(
			$post_id,
			array( $primary['beta']['term_id'], $primary['gamma']['slug'] ),
			$case['primaryTaxonomy'],
			false
		);
		self::collect_failure(
			$failures,
			self::same_int_set( $secondary_set, self::tt_ids( array( $secondary['one'] ) ) )
				&& self::same_int_set( $replace, self::tt_ids( array( $primary['beta'], $primary['gamma'] ) ) )
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $primary ),
					self::tt_ids( array( $primary['beta'], $primary['gamma'] ) )
				)
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $secondary ),
					self::tt_ids( array( $secondary['one'] ) )
				),
			'append=false replaces only the target taxonomy relationship set',
			array(
				'returned'      => $replace,
				'relationships' => self::relationship_rows( $post_id ),
			)
		);

		$before_append = self::relationship_rows( $post_id );
		$append        = \wp_set_object_terms(
			$post_id,
			array( $primary['alpha']['term_id'], $primary['delta']['slug'], $primary['delta']['slug'] ),
			$case['primaryTaxonomy'],
			true
		);
		$after_append  = self::relationship_rows( $post_id );
		$append_again  = \wp_set_object_terms(
			$post_id,
			array( $primary['alpha']['term_id'], $primary['delta']['term_id'] ),
			$case['primaryTaxonomy'],
			true
		);
		$after_repeat  = self::relationship_rows( $post_id );

		self::collect_failure(
			$failures,
			self::same_int_set(
				self::relationship_tt_ids_for_taxonomy( $post_id, $primary ),
				self::tt_ids( array( $primary['alpha'], $primary['beta'], $primary['gamma'], $primary['delta'] ) )
			)
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $secondary ),
					self::tt_ids( array( $secondary['one'] ) )
				)
				&& self::relationship_rows_equal( $after_append, $after_repeat )
				&& self::relationship_rows_have_unique_keys( $after_append ),
			'append=true preserves existing relationships and adds missing terms once',
			array(
				'beforeAppend' => $before_append,
				'appendReturn' => $append,
				'repeatReturn' => $append_again,
				'afterAppend'  => $after_append,
				'afterRepeat'  => $after_repeat,
			)
		);

		return $ctx->result(
			'taxonomy-relationships.set-replace-append',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_generated_assignment_cases( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'generated' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.generated-assignment-cases',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$failures  = array();
		$primary   = $fixture['primary'];
		$secondary = $fixture['secondary'];
		$scenarios = $case['assignmentCases'];

		foreach ( $scenarios as $index => $scenario ) {
			$post_id = self::create_host_post( $case, 'generated-' . $index . '-' . $scenario['label'] );
			if ( \is_wp_error( $post_id ) ) {
				self::collect_failure(
					$failures,
					false,
					"generated assignment case {$index} host post is created",
					array(
						'scenario' => $scenario,
						'error'    => self::error_summary( $post_id ),
					)
				);
				continue;
			}

			$post_id        = (int) $post_id;
			$initial_values = self::term_input_values( $primary, $scenario['initial'] );
			$input_values   = self::term_input_values( $primary, $scenario['input'] );
			$initial_terms  = self::terms_for_keys( $primary, self::unique_term_keys_from_specs( $scenario['initial'] ) );
			$expected_keys  = $scenario['append']
				? self::merge_unique_term_keys(
					self::unique_term_keys_from_specs( $scenario['initial'] ),
					self::unique_term_keys_from_specs( $scenario['input'] )
				)
				: self::unique_term_keys_from_specs( $scenario['input'] );
			$expected_terms = self::terms_for_keys( $primary, $expected_keys );

			$initial_result   = \wp_set_object_terms( $post_id, $initial_values, $case['primaryTaxonomy'], false );
			$secondary_result = \wp_set_object_terms( $post_id, array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], false );
			$before_set       = self::relationship_rows( $post_id );
			$set_result       = \wp_set_object_terms( $post_id, $input_values, $case['primaryTaxonomy'], $scenario['append'] );
			$after_set        = self::relationship_rows( $post_id );
			$get_ids          = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'ids' ) );
			$get_tt_ids       = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'tt_ids' ) );
			$get_slugs        = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'slugs' ) );

			self::collect_failure(
				$failures,
				self::same_int_set( $initial_result, self::tt_ids( $initial_terms ) )
					&& self::same_int_set( $secondary_result, self::tt_ids( array( $secondary['one'] ) ) )
					&& self::same_int_list( self::to_ints( $set_result ), self::term_input_tt_ids( $primary, $scenario['input'] ) )
					&& self::same_int_set( self::relationship_tt_ids_for_taxonomy( $post_id, $primary ), self::tt_ids( $expected_terms ) )
					&& self::same_int_set( self::relationship_tt_ids_for_taxonomy( $post_id, $secondary ), self::tt_ids( array( $secondary['one'] ) ) )
					&& self::same_int_set( $get_ids, self::term_ids( $expected_terms ) )
					&& self::same_int_set( $get_tt_ids, self::tt_ids( $expected_terms ) )
					&& self::same_string_set( $get_slugs, self::term_slugs( $expected_terms ) )
					&& self::relationship_rows_have_unique_keys( $after_set ),
				"generated assignment case {$index} matches append/replace oracle",
				array(
					'scenario'       => $scenario,
					'postId'         => $post_id,
					'initialReturn'  => $initial_result,
					'secondaryReturn'=> $secondary_result,
					'setReturn'      => $set_result,
					'beforeSet'      => $before_set,
					'afterSet'       => $after_set,
					'expectedKeys'   => $expected_keys,
					'getIds'         => $get_ids,
					'getTtIds'       => $get_tt_ids,
					'getSlugs'       => $get_slugs,
				)
			);
		}

		return $ctx->result(
			'taxonomy-relationships.generated-assignment-cases',
			array() === $failures,
			array(
				'case'       => self::case_summary( $case ),
				'scenarioCt' => count( $scenarios ),
				'scenarios'  => self::assignment_case_summaries( $scenarios ),
				'failures'   => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_field_variants_and_membership( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'fields' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.fields-and-membership-agree',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$failures       = array();
		$post_id        = $fixture['postId'];
		$second_post_id = $fixture['secondPostId'];
		$primary        = $fixture['primary'];
		$secondary      = $fixture['secondary'];
		$assigned       = array( $primary['alpha'], $primary['gamma'], $primary['delta'] );

		\wp_set_object_terms(
			$post_id,
			array( $primary['alpha']['term_id'], $primary['gamma']['slug'], $primary['delta']['term_id'] ),
			$case['primaryTaxonomy'],
			false
		);
		\wp_set_object_terms( $second_post_id, array( $primary['alpha']['term_id'] ), $case['primaryTaxonomy'], false );
		\wp_set_object_terms( $post_id, array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], false );

		$all                = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'all' ) );
		$ids                = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'ids' ) );
		$tt_ids             = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'tt_ids' ) );
		$slugs              = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'slugs' ) );
		$names              = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'names' ) );
		$id_to_name         = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'id=>name' ) );
		$id_to_slug         = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'id=>slug' ) );
		$with_object_id     = \wp_get_object_terms( array( $post_id ), $case['primaryTaxonomy'], self::term_query_args( 'all_with_object_id' ) );
		$with_object_ids    = self::pluck_terms( $with_object_id, 'object_id' );
		$multi_object_terms = \wp_get_object_terms( array( $post_id, $second_post_id ), $case['primaryTaxonomy'], self::term_query_args( 'all_with_object_id' ) );
		$multi_object_ids   = self::pluck_terms( $multi_object_terms, 'object_id' );
		$multi_object_map   = self::term_ids_by_object_id( $multi_object_terms );
		$multi_taxonomy_ids = \wp_get_object_terms(
			$post_id,
			array( $case['primaryTaxonomy'], $case['secondaryTaxonomy'] ),
			self::term_query_args( 'ids' )
		);

		self::collect_failure(
			$failures,
			is_array( $all )
				&& ! \is_wp_error( $all )
				&& self::same_int_set( $ids, self::pluck_terms( $all, 'term_id' ) )
				&& self::same_int_set( $tt_ids, self::pluck_terms( $all, 'term_taxonomy_id' ) )
				&& self::same_string_set( $slugs, self::pluck_terms( $all, 'slug' ) )
				&& self::same_string_set( $names, self::pluck_terms( $all, 'name' ) )
				&& self::same_int_set( $ids, self::term_ids( $assigned ) )
				&& self::same_int_set( $tt_ids, self::tt_ids( $assigned ) )
				&& self::same_string_set( $slugs, self::term_slugs( $assigned ) )
				&& self::same_string_set( $names, self::term_names( $assigned ) )
				&& self::same_string_map( $id_to_name, self::term_field_map( $assigned, 'term_id', 'name' ) )
				&& self::same_string_map( $id_to_slug, self::term_field_map( $assigned, 'term_id', 'slug' ) )
				&& self::same_int_set( self::pluck_terms( $with_object_id, 'term_id' ), self::term_ids( $assigned ) )
				&& ( array() === $with_object_ids || self::same_int_set( $with_object_ids, array( $post_id ) ) )
				&& (
					array() === $multi_object_ids
					|| (
						self::same_int_set( array_keys( $multi_object_map ), array( $post_id, $second_post_id ) )
						&& self::same_int_set( $multi_object_map[ $post_id ] ?? array(), self::term_ids( $assigned ) )
						&& self::same_int_set( $multi_object_map[ $second_post_id ] ?? array(), self::term_ids( array( $primary['alpha'] ) ) )
					)
				)
				&& self::same_int_set(
					$multi_taxonomy_ids,
					array_merge( self::term_ids( $assigned ), self::term_ids( array( $secondary['one'] ) ) )
				),
			'wp_get_object_terms fields variants agree after canonicalization',
			array(
				'all'              => self::term_summaries( $all ),
				'ids'              => $ids,
				'ttIds'            => $tt_ids,
				'slugs'            => $slugs,
				'names'            => $names,
				'idToName'         => $id_to_name,
				'idToSlug'         => $id_to_slug,
				'withObjectId'     => self::term_summaries( $with_object_id ),
				'objectIdSupport'  => array() !== $with_object_ids,
				'multiObjectTerms' => self::term_summaries( $multi_object_terms ),
				'multiObjectMap'   => $multi_object_map,
				'multiTaxonomyIds' => $multi_taxonomy_ids,
				'assign'           => self::term_summaries( $assigned ),
			)
		);

		$is_any            = \is_object_in_term( $post_id, $case['primaryTaxonomy'] );
		$is_alpha_id       = \is_object_in_term( $post_id, $case['primaryTaxonomy'], $primary['alpha']['term_id'] );
		$is_alpha_str      = \is_object_in_term( $post_id, $case['primaryTaxonomy'], (string) $primary['alpha']['term_id'] );
		$is_gamma_slug     = \is_object_in_term( $post_id, $case['primaryTaxonomy'], $primary['gamma']['slug'] );
		$is_delta_name     = \is_object_in_term( $post_id, $case['primaryTaxonomy'], $primary['delta']['name'] );
		$is_beta           = \is_object_in_term( $post_id, $case['primaryTaxonomy'], $primary['beta']['term_id'] );
		$objects_alpha     = \get_objects_in_term( $primary['alpha']['term_id'], $case['primaryTaxonomy'], array( 'order' => 'ASC' ) );
		$objects_alpha_desc = \get_objects_in_term( $primary['alpha']['term_id'], $case['primaryTaxonomy'], array( 'order' => 'DESC' ) );
		$objects_beta      = \get_objects_in_term( $primary['beta']['term_id'], $case['primaryTaxonomy'] );

		self::collect_failure(
			$failures,
			true === $is_any
				&& true === $is_alpha_id
				&& true === $is_alpha_str
				&& true === $is_gamma_slug
				&& true === $is_delta_name
				&& false === $is_beta
				&& self::same_int_list( self::to_ints( $objects_alpha ), array( $post_id, $second_post_id ) )
				&& self::same_int_list( self::to_ints( $objects_alpha_desc ), array( $second_post_id, $post_id ) )
				&& array() === $objects_beta,
			'is_object_in_term and get_objects_in_term agree with assigned object terms',
			array(
				'isAny'       => $is_any,
				'isAlphaId'   => $is_alpha_id,
				'isAlphaStr'  => $is_alpha_str,
				'isGammaSlug' => $is_gamma_slug,
				'isDeltaName' => $is_delta_name,
				'isBeta'      => $is_beta,
				'objectsAsc'  => $objects_alpha,
				'objectsDesc' => $objects_alpha_desc,
				'objectsBeta' => $objects_beta,
			)
		);

		return $ctx->result(
			'taxonomy-relationships.fields-and-membership-agree',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_ordering_and_counts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'ordering' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.ordering-and-counts',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$failures       = array();
		$post_id        = $fixture['postId'];
		$second_post_id = $fixture['secondPostId'];
		$primary        = $fixture['primary'];
		$ordered_specs  = array(
			array(
				'term'  => 'delta',
				'field' => 'id',
			),
			array(
				'term'  => 'alpha',
				'field' => 'slug',
			),
			array(
				'term'  => 'gamma',
				'field' => 'id',
			),
		);
		$ordered_terms  = self::terms_for_keys( $primary, array( 'delta', 'alpha', 'gamma' ) );
		$set_ordered    = \wp_set_object_terms( $post_id, self::term_input_values( $primary, $ordered_specs ), $case['primaryTaxonomy'], false );
		$order_rows     = self::relationship_rows( $post_id );
		$expected_order = self::expected_term_order_map( $ordered_terms, $case['sortPrimary'] );

		self::collect_failure(
			$failures,
			self::same_int_list( self::to_ints( $set_ordered ), self::term_input_tt_ids( $primary, $ordered_specs ) )
				&& self::relationship_term_order_matches( $post_id, $expected_order )
				&& self::relationship_rows_have_unique_keys( $order_rows ),
			'taxonomy sort flag controls persisted term_order without changing relationships',
			array(
				'sortPrimary'   => $case['sortPrimary'],
				'setReturn'     => $set_ordered,
				'expectedOrder' => $expected_order,
				'rows'          => $order_rows,
			)
		);

		$draft_post_id = self::create_host_post( $case, 'ordering-draft', 'draft' );
		if ( \is_wp_error( $draft_post_id ) ) {
			self::collect_failure(
				$failures,
				false,
				'ordering/counts draft post is created',
				array( 'error' => self::error_summary( $draft_post_id ) )
			);
			$draft_post_id = 0;
		}

		$set_second = \wp_set_object_terms( $second_post_id, array( $primary['alpha']['term_id'] ), $case['primaryTaxonomy'], false );
		$set_draft  = 0 < (int) $draft_post_id
			? \wp_set_object_terms(
				(int) $draft_post_id,
				array( $primary['alpha']['term_id'], $primary['gamma']['term_id'] ),
				$case['primaryTaxonomy'],
				false
			)
			: array();
		$counts_after_assign = self::term_counts( $primary );
		$counts_supported    = self::term_counts_supported( $counts_after_assign );

		self::collect_failure(
			$failures,
			! $counts_supported
				|| (
					self::same_int_set( $set_second, self::tt_ids( array( $primary['alpha'] ) ) )
					&& self::same_int_set( $set_draft, self::tt_ids( array( $primary['alpha'], $primary['gamma'] ) ) )
					&& self::same_named_counts(
						$counts_after_assign,
						array(
							'alpha' => 2,
							'beta'  => 0,
							'gamma' => 1,
							'delta' => 1,
						)
					)
				),
			'term counts track published object relationships and exclude draft posts when the stub supports counts',
			array(
				'countsSupported' => $counts_supported,
				'setSecond'       => $set_second,
				'setDraft'        => $set_draft,
				'counts'          => $counts_after_assign,
				'draftPostId'     => $draft_post_id,
			)
		);

		$remove_alpha       = \wp_remove_object_terms( $post_id, $primary['alpha']['term_id'], $case['primaryTaxonomy'] );
		$clear_first        = \wp_set_object_terms( $post_id, array(), $case['primaryTaxonomy'], false );
		$counts_after_clear = self::term_counts( $primary );

		self::collect_failure(
			$failures,
			! $counts_supported
				|| (
					true === $remove_alpha
					&& array() === $clear_first
					&& self::same_named_counts(
						$counts_after_clear,
						array(
							'alpha' => 1,
							'beta'  => 0,
							'gamma' => 0,
							'delta' => 0,
						)
					)
					&& array() === self::relationship_tt_ids_for_taxonomy( $post_id, $primary )
				),
			'relationship deletion and empty replacement update counts and clear only the target object terms',
			array(
				'countsSupported' => $counts_supported,
				'removeAlpha'     => $remove_alpha,
				'clearFirst'      => $clear_first,
				'counts'          => $counts_after_clear,
				'rows'            => self::relationship_rows( $post_id ),
			)
		);

		return $ctx->result(
			'taxonomy-relationships.ordering-and-counts',
			array() === $failures,
			array(
				'case'            => self::case_summary( $case ),
				'countsSupported' => $counts_supported,
				'failures'        => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_remove_scope( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'remove' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.remove-taxonomy-scope',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$failures  = array();
		$post_id   = $fixture['postId'];
		$primary   = $fixture['primary'];
		$secondary = $fixture['secondary'];

		\wp_set_object_terms( $post_id, array( $primary['alpha']['term_id'], $primary['beta']['term_id'] ), $case['primaryTaxonomy'], false );
		\wp_set_object_terms( $post_id, array( $secondary['one']['term_id'], $secondary['two']['slug'] ), $case['secondaryTaxonomy'], false );

		$remove_primary = \wp_remove_object_terms( $post_id, $primary['alpha']['term_id'], $case['primaryTaxonomy'] );
		self::collect_failure(
			$failures,
			true === $remove_primary
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $primary ),
					self::tt_ids( array( $primary['beta'] ) )
				)
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $secondary ),
					self::tt_ids( array( $secondary['one'], $secondary['two'] ) )
				),
			'removing a primary term preserves secondary taxonomy relationships',
			array(
				'removePrimary' => $remove_primary,
				'relationships' => self::relationship_rows( $post_id ),
			)
		);

		$remove_secondary = \wp_remove_object_terms( $post_id, $secondary['one']['slug'], $case['secondaryTaxonomy'] );
		$before_invalid   = self::relationship_rows( $post_id );
		$remove_cross     = \wp_remove_object_terms( $post_id, $primary['beta']['term_id'], $case['secondaryTaxonomy'] );
		$remove_missing   = \wp_remove_object_terms( $post_id, 987654321, $case['primaryTaxonomy'] );
		$after_invalid    = self::relationship_rows( $post_id );

		self::collect_failure(
			$failures,
			true === $remove_secondary
				&& false === $remove_cross
				&& false === $remove_missing
				&& self::relationship_rows_equal( $before_invalid, $after_invalid )
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $primary ),
					self::tt_ids( array( $primary['beta'] ) )
				)
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $secondary ),
					self::tt_ids( array( $secondary['two'] ) )
				),
			'remove is taxonomy-scoped and missing integer terms fail closed',
			array(
				'removeSecondary' => $remove_secondary,
				'removeCross'     => $remove_cross,
				'removeMissing'   => $remove_missing,
				'beforeInvalid'   => $before_invalid,
				'afterInvalid'    => $after_invalid,
			)
		);

		$re_add_primary   = \wp_set_object_terms(
			$post_id,
			array( $primary['alpha']['term_id'], $primary['gamma']['slug'] ),
			$case['primaryTaxonomy'],
			true
		);
		$re_add_secondary = \wp_set_object_terms( $post_id, array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], true );
		$remove_duplicate = \wp_remove_object_terms(
			$post_id,
			array( $primary['beta']['term_id'], $primary['beta']['slug'], $primary['alpha']['term_id'] ),
			$case['primaryTaxonomy']
		);
		$after_duplicate  = self::relationship_rows( $post_id );
		$primary_after_duplicate = self::relationship_tt_ids_for_taxonomy( $post_id, $primary );
		$secondary_after_duplicate = self::relationship_tt_ids_for_taxonomy( $post_id, $secondary );
		$clear_primary    = \wp_set_object_terms( $post_id, array(), $case['primaryTaxonomy'], false );
		$after_clear      = self::relationship_rows( $post_id );
		$primary_after_clear = self::relationship_tt_ids_for_taxonomy( $post_id, $primary );
		$secondary_after_clear = self::relationship_tt_ids_for_taxonomy( $post_id, $secondary );

		self::collect_failure(
			$failures,
			self::same_int_set( $re_add_primary, self::tt_ids( array( $primary['alpha'], $primary['gamma'] ) ) )
				&& self::same_int_set( $re_add_secondary, self::tt_ids( array( $secondary['one'] ) ) )
				&& true === $remove_duplicate
				&& self::same_int_set(
					$primary_after_duplicate,
					self::tt_ids( array( $primary['gamma'] ) )
				)
				&& self::same_int_set(
					$secondary_after_duplicate,
					self::tt_ids( array( $secondary['one'], $secondary['two'] ) )
				)
				&& array() === $clear_primary
				&& array() === $primary_after_clear
				&& self::same_int_set(
					$secondary_after_clear,
					self::tt_ids( array( $secondary['one'], $secondary['two'] ) )
				)
				&& self::relationship_rows_have_unique_keys( $after_clear ),
			'duplicate remove inputs and empty replacement delete only matching target-taxonomy relationships',
			array(
				'reAddPrimary'   => $re_add_primary,
				'reAddSecondary' => $re_add_secondary,
				'removeDuplicate'=> $remove_duplicate,
				'afterDuplicate' => $after_duplicate,
				'primaryAfterDuplicate' => $primary_after_duplicate,
				'secondaryAfterDuplicate' => $secondary_after_duplicate,
				'clearPrimary'   => $clear_primary,
				'afterClear'     => $after_clear,
				'primaryAfterClear' => $primary_after_clear,
				'secondaryAfterClear' => $secondary_after_clear,
			)
		);

		return $ctx->result(
			'taxonomy-relationships.remove-taxonomy-scope',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_invalid_inputs( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'invalid' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.invalid-inputs-fail-closed',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$failures  = array();
		$post_id   = $fixture['postId'];
		$primary   = $fixture['primary'];
		$secondary = $fixture['secondary'];
		$missing   = $case['missingTaxonomy'];

		\wp_set_object_terms( $post_id, array( $primary['alpha']['term_id'] ), $case['primaryTaxonomy'], false );
		\wp_set_object_terms( $post_id, array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], false );
		$before = self::relationship_rows( $post_id );

		$set_invalid_tax     = \wp_set_object_terms( $post_id, array( $primary['alpha']['term_id'] ), $missing, false );
		$get_invalid_tax     = \wp_get_object_terms( $post_id, $missing );
		$remove_invalid_tax  = \wp_remove_object_terms( $post_id, $primary['alpha']['term_id'], $missing );
		$objects_invalid_tax = \get_objects_in_term( $primary['alpha']['term_id'], $missing );
		$invalid_object      = \is_object_in_term( 0, $case['primaryTaxonomy'] );
		$set_missing_term    = \wp_set_object_terms( $post_id, array( 987654321 ), $case['primaryTaxonomy'], true );
		$remove_missing_term = \wp_remove_object_terms( $post_id, 987654321, $case['primaryTaxonomy'] );
		$after              = self::relationship_rows( $post_id );

		self::collect_failure(
			$failures,
			self::is_error_code( $set_invalid_tax, 'invalid_taxonomy' )
				&& self::is_error_code( $get_invalid_tax, 'invalid_taxonomy' )
				&& self::is_error_code( $remove_invalid_tax, 'invalid_taxonomy' )
				&& self::is_error_code( $objects_invalid_tax, 'invalid_taxonomy' )
				&& self::is_error_code( $invalid_object, 'invalid_object' )
				&& array() === $set_missing_term
				&& false === $remove_missing_term
				&& self::relationship_rows_equal( $before, $after ),
			'invalid taxonomy/object/term inputs return core failures without mutating relationships',
			array(
				'setInvalidTax'     => self::error_summary( $set_invalid_tax ),
				'getInvalidTax'     => self::error_summary( $get_invalid_tax ),
				'removeInvalidTax'  => self::error_summary( $remove_invalid_tax ),
				'objectsInvalidTax' => self::error_summary( $objects_invalid_tax ),
				'invalidObject'     => self::error_summary( $invalid_object ),
				'setMissingTerm'    => $set_missing_term,
				'removeMissingTerm' => $remove_missing_term,
				'before'            => $before,
				'after'             => $after,
			)
		);

		$before_blank       = self::relationship_rows( $post_id );
		$set_blank_terms    = \wp_set_object_terms( $post_id, array( '', '   ', 0 ), $case['primaryTaxonomy'], true );
		$after_blank        = self::relationship_rows( $post_id );
		$new_term_name      = 'Generated Relationship Term ' . $case['token'];
		$set_new_term       = \wp_set_object_terms( $post_id, array( $new_term_name, $new_term_name ), $case['primaryTaxonomy'], true );
		$new_term_info      = \term_exists( $new_term_name, $case['primaryTaxonomy'] );
		$new_term           = is_array( $new_term_info ) ? \get_term( (int) $new_term_info['term_id'], $case['primaryTaxonomy'] ) : null;
		$new_term_summary   = $new_term instanceof \WP_Term
			? array(
				'term_id'          => (int) $new_term->term_id,
				'term_taxonomy_id' => (int) $new_term->term_taxonomy_id,
				'taxonomy'         => $new_term->taxonomy,
				'name'             => $new_term->name,
				'slug'             => $new_term->slug,
			)
			: null;
		$new_tt_id          = is_array( $new_term_info ) ? (int) $new_term_info['term_taxonomy_id'] : 0;
		$after_new_term     = self::relationship_rows( $post_id );
		$new_relationships  = array_filter(
			$after_new_term,
			static function ( $row ) use ( $new_tt_id ) {
				return (int) $row['term_taxonomy_id'] === $new_tt_id;
			}
		);

		self::collect_failure(
			$failures,
			array() === $set_blank_terms
				&& self::relationship_rows_equal( $before_blank, $after_blank )
				&& 0 < $new_tt_id
				&& self::same_int_list( self::to_ints( $set_new_term ), array( $new_tt_id, $new_tt_id ) )
				&& 1 === count( $new_relationships )
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy(
						$post_id,
						array(
							'alpha' => $primary['alpha'],
							'new'   => array(
								'term_taxonomy_id' => $new_tt_id,
							),
						)
					),
					array( $primary['alpha']['term_taxonomy_id'], $new_tt_id )
				)
				&& self::same_int_set(
					self::relationship_tt_ids_for_taxonomy( $post_id, $secondary ),
					self::tt_ids( array( $secondary['one'] ) )
				)
				&& self::relationship_rows_have_unique_keys( $after_new_term ),
			'blank and missing integer terms do not mutate, while duplicated missing string terms create one relationship',
			array(
				'setBlankTerms'   => $set_blank_terms,
				'beforeBlank'     => $before_blank,
				'afterBlank'      => $after_blank,
				'setNewTerm'      => $set_new_term,
				'newTermInfo'     => $new_term_info,
				'newTerm'         => $new_term_summary,
				'newRelationships'=> array_values( $new_relationships ),
				'afterNewTerm'    => $after_new_term,
			)
		);

		return $ctx->result(
			'taxonomy-relationships.invalid-inputs-fail-closed',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_get_the_terms_cache( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'cache' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.get-the-terms-cache',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$failures = array();
		$post_id  = $fixture['postId'];
		$primary  = $fixture['primary'];
		$group    = $case['primaryTaxonomy'] . '_relationships';

		\wp_set_object_terms( $post_id, array( $primary['alpha']['term_id'], $primary['beta']['term_id'] ), $case['primaryTaxonomy'], false );
		\wp_cache_delete( $post_id, $group );

		$cache_before = \wp_cache_get( $post_id, $group );
		$first        = \get_the_terms( $post_id, $case['primaryTaxonomy'] );
		$cache_first  = \wp_cache_get( $post_id, $group );
		$second       = \get_the_terms( $post_id, $case['primaryTaxonomy'] );

		self::collect_failure(
			$failures,
			false === $cache_before
				&& is_array( $first )
				&& is_array( $second )
				&& self::same_int_set( self::pluck_terms( $first, 'term_id' ), self::term_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& self::same_int_set( self::to_ints( $cache_first ), self::term_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& self::same_int_set( self::pluck_terms( $second, 'term_id' ), self::term_ids( array( $primary['alpha'], $primary['beta'] ) ) ),
			'get_the_terms populates the object relationship cache with term IDs',
			array(
				'cacheBefore' => $cache_before,
				'first'       => self::term_summaries( $first ),
				'cacheFirst'  => $cache_first,
				'second'      => self::term_summaries( $second ),
			)
		);

		$update       = \wp_set_object_terms(
			$post_id,
			array( $primary['beta']['term_id'], $primary['gamma']['term_id'] ),
			$case['primaryTaxonomy'],
			false
		);
		$cache_update = \wp_cache_get( $post_id, $group );
		$after_update = \get_the_terms( $post_id, $case['primaryTaxonomy'] );
		$cache_second = \wp_cache_get( $post_id, $group );
		$remove       = \wp_remove_object_terms( $post_id, $primary['beta']['term_id'], $case['primaryTaxonomy'] );
		$cache_remove = \wp_cache_get( $post_id, $group );

		self::collect_failure(
			$failures,
			self::same_int_set( $update, self::tt_ids( array( $primary['beta'], $primary['gamma'] ) ) )
				&& false === $cache_update
				&& is_array( $after_update )
				&& self::same_int_set( self::pluck_terms( $after_update, 'term_id' ), self::term_ids( array( $primary['beta'], $primary['gamma'] ) ) )
				&& self::same_int_set( self::to_ints( $cache_second ), self::term_ids( array( $primary['beta'], $primary['gamma'] ) ) )
				&& true === $remove
				&& false === $cache_remove,
			'relationship updates invalidate get_the_terms relationship cache entries',
			array(
				'updateReturn' => $update,
				'cacheUpdate'  => $cache_update,
				'afterUpdate'  => self::term_summaries( $after_update ),
				'cacheSecond'  => $cache_second,
				'removeReturn' => $remove,
				'cacheRemove'  => $cache_remove,
			)
		);

		return $ctx->result(
			'taxonomy-relationships.get-the-terms-cache',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_object_term_cache_priming_and_cleaning( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$post_type_ready = \register_taxonomy_for_object_type( $case['primaryTaxonomy'], 'post' )
			&& \register_taxonomy_for_object_type( $case['secondaryTaxonomy'], 'post' );

		$primary   = self::insert_fixture_terms( $case['primaryTaxonomy'], $case['primaryTerms'], 'cache-prime' );
		$secondary = self::insert_fixture_terms( $case['secondaryTaxonomy'], $case['secondaryTerms'], 'cache-prime' );

		if ( isset( $primary['error'] ) || isset( $secondary['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.object-term-cache-prime-clean',
				array(
					'case'       => self::case_summary( $case ),
					'registered' => $post_type_ready,
					'primary'    => $primary['error'] ?? null,
					'secondary'  => $secondary['error'] ?? null,
				)
			);
		}

		$first_post_id  = self::create_host_post_for_type( $case, 'cache-prime-first', 'post' );
		$second_post_id = self::create_host_post_for_type( $case, 'cache-prime-second', 'post' );
		$third_post_id  = self::create_host_post_for_type( $case, 'cache-prime-empty-primary', 'post' );

		if ( \is_wp_error( $first_post_id ) || \is_wp_error( $second_post_id ) || \is_wp_error( $third_post_id ) ) {
			return $ctx->fail(
				'taxonomy-relationships.object-term-cache-prime-clean',
				array(
					'case'   => self::case_summary( $case ),
					'errors' => array(
						'first'  => self::error_summary( $first_post_id ),
						'second' => self::error_summary( $second_post_id ),
						'third'  => self::error_summary( $third_post_id ),
					),
				)
			);
		}

		$object_ids = self::to_ints( array( $first_post_id, $second_post_id, $third_post_id ) );
		$taxonomies  = \get_object_taxonomies( 'post' );
		$expected_cache = self::empty_relationship_cache_map( $object_ids, $taxonomies );
		$expected_cache[ $case['primaryTaxonomy'] ][ $object_ids[0] ] = self::term_ids( array( $primary['alpha'], $primary['beta'] ) );
		$expected_cache[ $case['primaryTaxonomy'] ][ $object_ids[1] ] = self::term_ids( array( $primary['gamma'] ) );
		$expected_cache[ $case['secondaryTaxonomy'] ][ $object_ids[0] ] = self::term_ids( array( $secondary['one'] ) );
		$expected_cache[ $case['secondaryTaxonomy'] ][ $object_ids[2] ] = self::term_ids( array( $secondary['two'] ) );
		$expected_cache = self::normalize_relationship_cache_map( $expected_cache );

		$set_first_primary = \wp_set_object_terms(
			$object_ids[0],
			array( $primary['alpha']['term_id'], $primary['beta']['slug'] ),
			$case['primaryTaxonomy'],
			false
		);
		$set_first_secondary = \wp_set_object_terms( $object_ids[0], array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], false );
		$set_second_primary  = \wp_set_object_terms( $object_ids[1], array( $primary['gamma']['term_id'] ), $case['primaryTaxonomy'], false );
		$set_third_secondary = \wp_set_object_terms( $object_ids[2], array( $secondary['two']['slug'] ), $case['secondaryTaxonomy'], false );

		foreach ( $taxonomies as $taxonomy ) {
			\wp_cache_delete_multiple( $object_ids, "{$taxonomy}_relationships" );
		}

		$cache_before = self::relationship_cache_map( $object_ids, $taxonomies );
		$update_shape = $ctx->choice( array( 'array', 'csv' ) );
		$repeat_shape = 'array' === $update_shape ? 'csv' : 'array';
		$first_update  = \update_object_term_cache( self::object_id_input( $object_ids, $update_shape ), 'post' );
		$cache_first   = self::relationship_cache_map( $object_ids, $taxonomies );
		$second_update = \update_object_term_cache( self::object_id_input( $object_ids, $repeat_shape ), 'post' );
		$cache_second  = self::relationship_cache_map( $object_ids, $taxonomies );

		$clean_events = array();
		$clean_object_term_cache = static function ( $cleaned_object_ids, $object_type ) use ( &$clean_events ) {
			$clean_events[] = array(
				'objectIds'  => self::to_ints( $cleaned_object_ids ),
				'objectType' => (string) $object_type,
			);
		};

		\add_action( 'clean_object_term_cache', $clean_object_term_cache, 10, 2 );
		try {
			$clean_result = \clean_object_term_cache( $object_ids, 'post' );
		} finally {
			\remove_action( 'clean_object_term_cache', $clean_object_term_cache, 10 );
		}

		$cache_cleaned = self::relationship_cache_map( $object_ids, $taxonomies );
		$reprime_update = \update_object_term_cache( self::object_id_input( $object_ids, $update_shape ), 'post' );
		$cache_reprime  = self::relationship_cache_map( $object_ids, $taxonomies );

		$failures = array();
		self::collect_failure(
			$failures,
			$post_type_ready
				&& self::same_int_set( $set_first_primary, self::tt_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& self::same_int_set( $set_first_secondary, self::tt_ids( array( $secondary['one'] ) ) )
				&& self::same_int_set( $set_second_primary, self::tt_ids( array( $primary['gamma'] ) ) )
				&& self::same_int_set( $set_third_secondary, self::tt_ids( array( $secondary['two'] ) ) )
				&& self::relationship_cache_all_false( $cache_before ),
			'deleted relationship cache groups are empty before explicit priming',
			array(
				'postTypeReady'      => $post_type_ready,
				'setFirstPrimary'    => $set_first_primary,
				'setFirstSecondary'  => $set_first_secondary,
				'setSecondPrimary'   => $set_second_primary,
				'setThirdSecondary'  => $set_third_secondary,
				'cacheBefore'        => $cache_before,
				'taxonomies'         => $taxonomies,
			)
		);

		self::collect_failure(
			$failures,
			null === $first_update
				&& false === $second_update
				&& self::relationship_cache_maps_equal( $cache_first, $expected_cache )
				&& self::relationship_cache_maps_equal( $cache_second, $expected_cache ),
			'update_object_term_cache primes every post taxonomy relationship group and is false on a warm cache',
			array(
				'updateShape'  => $update_shape,
				'repeatShape'  => $repeat_shape,
				'firstUpdate'  => $first_update,
				'secondUpdate' => $second_update,
				'expected'     => $expected_cache,
				'cacheFirst'   => $cache_first,
				'cacheSecond'  => $cache_second,
			)
		);

		self::collect_failure(
			$failures,
			null === $clean_result
				&& self::relationship_cache_all_false( $cache_cleaned )
				&& 1 === count( $clean_events )
				&& self::same_int_list( $clean_events[0]['objectIds'] ?? array(), $object_ids )
				&& 'post' === ( $clean_events[0]['objectType'] ?? null )
				&& false === \has_filter( 'clean_object_term_cache', $clean_object_term_cache ),
			'clean_object_term_cache clears every post taxonomy group and restores action locality',
			array(
				'cleanResult'  => $clean_result,
				'cleanEvents'  => $clean_events,
				'cacheCleaned' => $cache_cleaned,
			)
		);

		self::collect_failure(
			$failures,
			null === $reprime_update
				&& self::relationship_cache_maps_equal( $cache_reprime, $expected_cache )
				&& self::relationship_cache_maps_equal( $cache_reprime, $cache_first ),
			're-priming after cleaning restores the same object/taxonomy cache map',
			array(
				'reprimeUpdate' => $reprime_update,
				'cacheReprime'  => $cache_reprime,
				'cacheFirst'    => $cache_first,
			)
		);

		return $ctx->result(
			'taxonomy-relationships.object-term-cache-prime-clean',
			array() === $failures,
			array(
				'case'        => self::case_summary( $case ),
				'objectIds'   => $object_ids,
				'taxonomies'  => $taxonomies,
				'updateShape' => $update_shape,
				'failures'    => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_generated_mutation_sequence( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'mutations' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.generated-mutation-sequence',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$third_post_id = self::create_host_post( $case, 'third-mutations' );
		if ( \is_wp_error( $third_post_id ) ) {
			return $ctx->fail(
				'taxonomy-relationships.generated-mutation-sequence',
				array(
					'case'  => self::case_summary( $case ),
					'error' => self::error_summary( $third_post_id ),
				)
			);
		}

		$failures   = array();
		$primary    = $fixture['primary'];
		$secondary  = $fixture['secondary'];
		$objects    = array(
			'first'  => (int) $fixture['postId'],
			'second' => (int) $fixture['secondPostId'],
			'third'  => (int) $third_post_id,
		);
		$taxonomies = array(
			'primary'   => $case['primaryTaxonomy'],
			'secondary' => $case['secondaryTaxonomy'],
		);
		$term_sets  = array(
			'primary'   => $primary,
			'secondary' => $secondary,
		);
		$expected   = self::empty_expected_relationship_keys( array_values( $objects ), array_values( $taxonomies ) );

		$initial_first_primary = \wp_set_object_terms(
			$objects['first'],
			array( $primary['alpha']['term_id'], $primary['beta']['slug'] ),
			$case['primaryTaxonomy'],
			false
		);
		self::expected_set_relationship_keys( $expected, $objects['first'], $case['primaryTaxonomy'], array( 'alpha', 'beta' ), false );

		$initial_first_secondary = \wp_set_object_terms( $objects['first'], array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], false );
		self::expected_set_relationship_keys( $expected, $objects['first'], $case['secondaryTaxonomy'], array( 'one' ), false );

		$initial_second_primary = \wp_set_object_terms(
			$objects['second'],
			array( $primary['alpha']['term_id'], $primary['gamma']['term_id'] ),
			$case['primaryTaxonomy'],
			false
		);
		self::expected_set_relationship_keys( $expected, $objects['second'], $case['primaryTaxonomy'], array( 'alpha', 'gamma' ), false );

		$initial_second_secondary = \wp_set_object_terms( $objects['second'], array( $secondary['two']['slug'] ), $case['secondaryTaxonomy'], false );
		self::expected_set_relationship_keys( $expected, $objects['second'], $case['secondaryTaxonomy'], array( 'two' ), false );

		$initial_third_primary = \wp_set_object_terms( $objects['third'], array( $primary['delta']['term_id'] ), $case['primaryTaxonomy'], false );
		self::expected_set_relationship_keys( $expected, $objects['third'], $case['primaryTaxonomy'], array( 'delta' ), false );

		$initial_third_secondary = \wp_set_object_terms( $objects['third'], array(), $case['secondaryTaxonomy'], false );

		self::collect_failure(
			$failures,
			self::same_int_set( $initial_first_primary, self::tt_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& self::same_int_set( $initial_first_secondary, self::tt_ids( array( $secondary['one'] ) ) )
				&& self::same_int_set( $initial_second_primary, self::tt_ids( array( $primary['alpha'], $primary['gamma'] ) ) )
				&& self::same_int_set( $initial_second_secondary, self::tt_ids( array( $secondary['two'] ) ) )
				&& self::same_int_set( $initial_third_primary, self::tt_ids( array( $primary['delta'] ) ) )
				&& array() === $initial_third_secondary
				&& array() === self::expected_relationship_mismatches( $expected, $term_sets, $taxonomies ),
			'generated mutation fixture starts with the expected multi-object taxonomy map',
			array(
				'objects'          => $objects,
				'firstPrimary'     => $initial_first_primary,
				'firstSecondary'   => $initial_first_secondary,
				'secondPrimary'    => $initial_second_primary,
				'secondSecondary'  => $initial_second_secondary,
				'thirdPrimary'     => $initial_third_primary,
				'thirdSecondary'   => $initial_third_secondary,
				'expected'         => self::expected_relationship_summary( $expected, $term_sets, $taxonomies ),
				'relationshipRows' => self::relationship_rows(),
			)
		);

		$all_object_ids  = array_values( $objects );
		$all_taxonomies  = array_values( $taxonomies );
		$expected_cache  = self::expected_relationship_cache_map( $expected, $term_sets, $taxonomies );
		$prime_result    = \update_object_term_cache( $all_object_ids, $case['postType'] );
		$cache_after_prime = self::relationship_cache_map( $all_object_ids, $all_taxonomies );
		self::collect_failure(
			$failures,
			( null === $prime_result || false === $prime_result )
				&& self::relationship_cache_maps_equal( $cache_after_prime, $expected_cache ),
			'initial generated mutation fixture primes the object relationship caches',
			array(
				'primeResult' => $prime_result,
				'expected'    => $expected_cache,
				'actual'      => $cache_after_prime,
			)
		);

		$events = array(
			'actions' => array(),
			'cache'   => array(),
			'counts'  => array(),
		);

		$add_term_relationship = static function ( $object_id, $tt_id, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'add_term_relationship',
				'objectId' => (int) $object_id,
				'ttIds'    => array( (int) $tt_id ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$added_term_relationship = static function ( $object_id, $tt_id, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'added_term_relationship',
				'objectId' => (int) $object_id,
				'ttIds'    => array( (int) $tt_id ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$set_object_terms = static function ( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'set_object_terms',
				'objectId' => (int) $object_id,
				'terms'    => self::compact_scalars( $terms ),
				'ttIds'    => self::to_ints( $tt_ids ),
				'taxonomy' => (string) $taxonomy,
				'append'   => (bool) $append,
				'oldTtIds' => self::to_ints( $old_tt_ids ),
			);
		};
		$delete_term_relationships = static function ( $object_id, $tt_ids, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'delete_term_relationships',
				'objectId' => (int) $object_id,
				'ttIds'    => self::to_ints( $tt_ids ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$deleted_term_relationships = static function ( $object_id, $tt_ids, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'deleted_term_relationships',
				'objectId' => (int) $object_id,
				'ttIds'    => self::to_ints( $tt_ids ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$clean_object_term_cache = static function ( $object_ids, $object_type ) use ( &$events ) {
			$events['cache'][] = array(
				'objectIds'  => self::to_ints( $object_ids ),
				'objectType' => (string) $object_type,
			);
		};
		$update_term_count = static function ( $tt_id, $taxonomy, $count ) use ( &$events ) {
			$events['counts'][] = array(
				'ttIds'    => array( (int) $tt_id ),
				'taxonomy' => (string) $taxonomy,
				'count'    => (int) $count,
			);
		};

		\add_action( 'add_term_relationship', $add_term_relationship, 10, 3 );
		\add_action( 'added_term_relationship', $added_term_relationship, 10, 3 );
		\add_action( 'set_object_terms', $set_object_terms, 10, 6 );
		\add_action( 'delete_term_relationships', $delete_term_relationships, 10, 3 );
		\add_action( 'deleted_term_relationships', $deleted_term_relationships, 10, 3 );
		\add_action( 'clean_object_term_cache', $clean_object_term_cache, 10, 2 );
		\add_action( 'update_term_count', $update_term_count, 10, 3 );

		$operation_summaries = array();
		$expected_added_tt_ids = array();
		$expected_deleted_tt_ids = array();
		$set_operation_count = 0;

		try {
			foreach ( $case['mutationCases'] as $index => $operation ) {
				$object_id = $objects[ $operation['object'] ];
				$before    = $expected;

				\update_object_term_cache( $all_object_ids, $case['postType'] );
				$cache_before = self::relationship_cache_map( $all_object_ids, $all_taxonomies );
				$cache_expected_before = self::expected_relationship_cache_map( $expected, $term_sets, $taxonomies );
				self::collect_failure(
					$failures,
					self::relationship_cache_maps_equal( $cache_before, $cache_expected_before ),
					"generated mutation {$index} starts from a warm expected cache",
					array(
						'operation' => $operation,
						'expected'  => $cache_expected_before,
						'actual'    => $cache_before,
					)
				);

				$result = null;
				$return_ok = false;
				$changed_taxonomies = array();

				if ( 'delete' === $operation['type'] ) {
					$taxonomy_names = array();
					foreach ( $operation['taxonomies'] as $role ) {
						$taxonomy_names[] = $taxonomies[ $role ];
					}

					$result = \wp_delete_object_term_relationships( $object_id, $taxonomy_names );
					foreach ( $operation['taxonomies'] as $role ) {
						if ( array() !== ( $before[ $object_id ][ $taxonomies[ $role ] ] ?? array() ) ) {
							$changed_taxonomies[] = $taxonomies[ $role ];
						}
						self::expected_set_relationship_keys( $expected, $object_id, $taxonomies[ $role ], array(), false );
					}
					$return_ok = null === $result;
				} else {
					$role     = $operation['taxonomy'];
					$taxonomy = $taxonomies[ $role ];
					$terms    = $term_sets[ $role ];
					$input    = self::term_input_values( $terms, $operation['terms'] );
					$keys     = self::unique_term_keys_from_specs( $operation['terms'] );
					$changed_taxonomies = array( $taxonomy );

					if ( 'set' === $operation['type'] ) {
						++$set_operation_count;
						$result = \wp_set_object_terms( $object_id, $input, $taxonomy, $operation['append'] );
						self::expected_set_relationship_keys( $expected, $object_id, $taxonomy, $keys, $operation['append'] );
						$return_ok = self::same_int_list( self::to_ints( $result ), self::term_input_tt_ids( $terms, $operation['terms'] ) );
					} elseif ( 'remove' === $operation['type'] ) {
						$before_keys = $expected[ $object_id ][ $taxonomy ] ?? array();
						$removed_any = array() !== array_intersect( $before_keys, $keys );
						$result      = \wp_remove_object_terms( $object_id, $input, $taxonomy );
						self::expected_remove_relationship_keys( $expected, $object_id, $taxonomy, $keys );
						$return_ok = $removed_any ? true === $result : false === $result;
					}
				}

				$added_tt_ids = self::expected_added_tt_ids( $before, $expected, $object_id, $term_sets, $taxonomies );
				$deleted_tt_ids = self::expected_deleted_tt_ids( $before, $expected, $object_id, $term_sets, $taxonomies );
				$expected_added_tt_ids = array_merge( $expected_added_tt_ids, $added_tt_ids );
				$expected_deleted_tt_ids = array_merge( $expected_deleted_tt_ids, $deleted_tt_ids );
				$relationship_changed = array() !== $added_tt_ids || array() !== $deleted_tt_ids;
				$cache_after_mutation = self::relationship_cache_map( $all_object_ids, $all_taxonomies );
				$cache_ok = self::mutation_cache_effect_matches( $cache_before, $cache_after_mutation, $all_object_ids, $all_taxonomies, $object_id, $changed_taxonomies, $relationship_changed );
				$relationship_mismatches = self::expected_relationship_mismatches( $expected, $term_sets, $taxonomies );
				$counts_mismatches = self::expected_count_mismatches( $expected, $term_sets, $taxonomies );

				self::collect_failure(
					$failures,
					$return_ok
						&& $cache_ok
						&& array() === $relationship_mismatches
						&& array() === $counts_mismatches
						&& self::relationship_rows_have_unique_keys( self::relationship_rows() ),
					"generated mutation {$index} preserves relationship, cache, and count invariants",
					array(
						'operation'              => $operation,
						'result'                 => $result,
						'returnOk'               => $return_ok,
						'relationshipChanged'    => $relationship_changed,
						'addedTtIds'             => $added_tt_ids,
						'deletedTtIds'           => $deleted_tt_ids,
						'cacheBefore'            => $cache_before,
						'cacheAfterMutation'     => $cache_after_mutation,
						'cacheOk'                => $cache_ok,
						'relationshipMismatches' => $relationship_mismatches,
						'countMismatches'        => $counts_mismatches,
						'expected'               => self::expected_relationship_summary( $expected, $term_sets, $taxonomies ),
						'rows'                   => self::relationship_rows(),
					)
				);

				$operation_summaries[] = array(
					'label'        => $operation['label'],
					'type'         => $operation['type'],
					'object'       => $operation['object'],
					'changed'      => $relationship_changed,
					'addedTtIds'   => $added_tt_ids,
					'deletedTtIds' => $deleted_tt_ids,
				);
			}
		} finally {
			\remove_action( 'add_term_relationship', $add_term_relationship, 10 );
			\remove_action( 'added_term_relationship', $added_term_relationship, 10 );
			\remove_action( 'set_object_terms', $set_object_terms, 10 );
			\remove_action( 'delete_term_relationships', $delete_term_relationships, 10 );
			\remove_action( 'deleted_term_relationships', $deleted_term_relationships, 10 );
			\remove_action( 'clean_object_term_cache', $clean_object_term_cache, 10 );
			\remove_action( 'update_term_count', $update_term_count, 10 );
		}

		$known_tt_ids = array_merge( self::tt_ids( $primary ), self::tt_ids( $secondary ) );
		self::collect_failure(
			$failures,
			$set_operation_count === count( self::events_by_hook( $events['actions'], 'set_object_terms' ) )
				&& self::contains_int_set( self::event_tt_ids_by_hook( $events['actions'], 'add_term_relationship' ), $expected_added_tt_ids )
				&& self::contains_int_set( self::event_tt_ids_by_hook( $events['actions'], 'added_term_relationship' ), $expected_added_tt_ids )
				&& self::contains_int_set( self::event_tt_ids_by_hook( $events['actions'], 'delete_term_relationships' ), $expected_deleted_tt_ids )
				&& self::contains_int_set( self::event_tt_ids_by_hook( $events['actions'], 'deleted_term_relationships' ), $expected_deleted_tt_ids )
				&& self::events_only_reference( $events['actions'], $all_object_ids, $all_taxonomies )
				&& self::cache_events_only_reference( $events['cache'], $all_object_ids, array( $case['postType'] ) )
				&& self::count_events_only_reference( $events['counts'], $known_tt_ids, $all_taxonomies )
				&& false === \has_filter( 'add_term_relationship', $add_term_relationship )
				&& false === \has_filter( 'added_term_relationship', $added_term_relationship )
				&& false === \has_filter( 'set_object_terms', $set_object_terms )
				&& false === \has_filter( 'delete_term_relationships', $delete_term_relationships )
				&& false === \has_filter( 'deleted_term_relationships', $deleted_term_relationships )
				&& false === \has_filter( 'clean_object_term_cache', $clean_object_term_cache )
				&& false === \has_filter( 'update_term_count', $update_term_count ),
			'generated mutation sequence relationship hooks and cache/count hooks remain local and restored',
			array(
				'setOperationCount'   => $set_operation_count,
				'actionCounts'        => self::event_counts_by_hook( $events['actions'] ),
				'cacheEventCount'     => count( $events['cache'] ),
				'countEventCount'     => count( $events['counts'] ),
				'expectedAddedTtIds'  => self::sorted_ints( $expected_added_tt_ids ),
				'expectedDeletedTtIds'=> self::sorted_ints( $expected_deleted_tt_ids ),
				'actions'             => array_slice( $events['actions'], 0, 20 ),
				'cacheEvents'         => array_slice( $events['cache'], 0, 10 ),
				'countEvents'         => array_slice( $events['counts'], 0, 10 ),
			)
		);

		$final_cache_prime = \update_object_term_cache( $all_object_ids, $case['postType'] );
		$final_cache       = self::relationship_cache_map( $all_object_ids, $all_taxonomies );
		$expected_final_cache = self::expected_relationship_cache_map( $expected, $term_sets, $taxonomies );
		self::collect_failure(
			$failures,
			( null === $final_cache_prime || false === $final_cache_prime )
				&& self::relationship_cache_maps_equal( $final_cache, $expected_final_cache ),
			'generated mutation sequence can re-prime caches to the final expected relationship map',
			array(
				'primeResult' => $final_cache_prime,
				'expected'    => $expected_final_cache,
				'actual'      => $final_cache,
			)
		);

		return $ctx->result(
			'taxonomy-relationships.generated-mutation-sequence',
			array() === $failures,
			array(
				'case'        => self::case_summary( $case ),
				'objects'     => $objects,
				'operations'  => $operation_summaries,
				'eventCounts' => array(
					'actions' => self::event_counts_by_hook( $events['actions'] ),
					'cache'   => count( $events['cache'] ),
					'counts'  => count( $events['counts'] ),
				),
				'failures'    => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_filter_action_locality( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$fixture = self::create_fixture( $case, 'hooks' );
		if ( isset( $fixture['error'] ) ) {
			return $ctx->fail(
				'taxonomy-relationships.filters-actions-local',
				array(
					'case'  => self::case_summary( $case ),
					'error' => $fixture['error'],
				)
			);
		}

		$events    = array(
			'actions' => array(),
			'filters' => array(),
		);
		$post_id   = $fixture['postId'];
		$primary   = $fixture['primary'];
		$secondary = $fixture['secondary'];

		$add_term_relationship = static function ( $object_id, $tt_id, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'add_term_relationship',
				'objectId' => (int) $object_id,
				'ttIds'    => array( (int) $tt_id ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$added_term_relationship = static function ( $object_id, $tt_id, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'added_term_relationship',
				'objectId' => (int) $object_id,
				'ttIds'    => array( (int) $tt_id ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$set_object_terms = static function ( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'set_object_terms',
				'objectId' => (int) $object_id,
				'terms'    => self::compact_scalars( $terms ),
				'ttIds'    => self::to_ints( $tt_ids ),
				'taxonomy' => (string) $taxonomy,
				'append'   => (bool) $append,
				'oldTtIds' => self::to_ints( $old_tt_ids ),
			);
		};
		$delete_term_relationships = static function ( $object_id, $tt_ids, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'delete_term_relationships',
				'objectId' => (int) $object_id,
				'ttIds'    => self::to_ints( $tt_ids ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$deleted_term_relationships = static function ( $object_id, $tt_ids, $taxonomy ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'deleted_term_relationships',
				'objectId' => (int) $object_id,
				'ttIds'    => self::to_ints( $tt_ids ),
				'taxonomy' => (string) $taxonomy,
			);
		};
		$update_term_count = static function ( $tt_id, $taxonomy, $count ) use ( &$events ) {
			$events['actions'][] = array(
				'hook'     => 'update_term_count',
				'objectId' => null,
				'ttIds'    => array( (int) $tt_id ),
				'taxonomy' => (string) $taxonomy,
				'count'    => (int) $count,
			);
		};
		$wp_get_object_terms_args = static function ( $args, $object_ids, $taxonomies ) use ( &$events ) {
			$events['filters'][] = array(
				'hook'       => 'wp_get_object_terms_args',
				'objectIds'  => self::to_ints( $object_ids ),
				'taxonomies' => array_values( array_map( 'strval', (array) $taxonomies ) ),
				'fields'     => $args['fields'] ?? null,
			);
			return $args;
		};
		$get_object_terms = static function ( $terms, $object_ids, $taxonomies, $args ) use ( &$events ) {
			$events['filters'][] = array(
				'hook'       => 'get_object_terms',
				'objectIds'  => self::to_ints( $object_ids ),
				'taxonomies' => array_values( array_map( 'strval', (array) $taxonomies ) ),
				'fields'     => $args['fields'] ?? null,
				'termCount'  => is_array( $terms ) ? count( $terms ) : null,
			);
			return $terms;
		};
		$wp_get_object_terms = static function ( $terms, $object_ids, $taxonomies, $args ) use ( &$events ) {
			$events['filters'][] = array(
				'hook'       => 'wp_get_object_terms',
				'objectIds'  => self::csv_ints( $object_ids ),
				'taxonomies' => self::taxonomy_sql_fragment_to_names( $taxonomies ),
				'fields'     => $args['fields'] ?? null,
				'termCount'  => is_array( $terms ) ? count( $terms ) : null,
			);
			return $terms;
		};

		\add_action( 'add_term_relationship', $add_term_relationship, 10, 3 );
		\add_action( 'added_term_relationship', $added_term_relationship, 10, 3 );
		\add_action( 'set_object_terms', $set_object_terms, 10, 6 );
		\add_action( 'delete_term_relationships', $delete_term_relationships, 10, 3 );
		\add_action( 'deleted_term_relationships', $deleted_term_relationships, 10, 3 );
		\add_action( 'update_term_count', $update_term_count, 10, 3 );
		\add_filter( 'wp_get_object_terms_args', $wp_get_object_terms_args, 10, 3 );
		\add_filter( 'get_object_terms', $get_object_terms, 10, 4 );
		\add_filter( 'wp_get_object_terms', $wp_get_object_terms, 10, 4 );

		try {
			$set_primary   = \wp_set_object_terms(
				$post_id,
				array( $primary['alpha']['term_id'], $primary['beta']['slug'] ),
				$case['primaryTaxonomy'],
				false
			);
			$set_secondary = \wp_set_object_terms( $post_id, array( $secondary['one']['term_id'] ), $case['secondaryTaxonomy'], false );
			$get_primary   = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'ids' ) );
			$remove_beta   = \wp_remove_object_terms( $post_id, $primary['beta']['term_id'], $case['primaryTaxonomy'] );
		} finally {
			\remove_action( 'add_term_relationship', $add_term_relationship, 10 );
			\remove_action( 'added_term_relationship', $added_term_relationship, 10 );
			\remove_action( 'set_object_terms', $set_object_terms, 10 );
			\remove_action( 'delete_term_relationships', $delete_term_relationships, 10 );
			\remove_action( 'deleted_term_relationships', $deleted_term_relationships, 10 );
			\remove_action( 'update_term_count', $update_term_count, 10 );
			\remove_filter( 'wp_get_object_terms_args', $wp_get_object_terms_args, 10 );
			\remove_filter( 'get_object_terms', $get_object_terms, 10 );
			\remove_filter( 'wp_get_object_terms', $wp_get_object_terms, 10 );
		}

		$failures     = array();
		$known_taxons = array( $case['primaryTaxonomy'], $case['secondaryTaxonomy'] );

		self::collect_failure(
			$failures,
			self::same_int_set( $set_primary, self::tt_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& self::same_int_set( $set_secondary, self::tt_ids( array( $secondary['one'] ) ) )
				&& self::same_int_set( $get_primary, self::term_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& true === $remove_beta,
			'hook locality setup operations satisfy relationship API contracts',
			array(
				'setPrimary'   => $set_primary ?? null,
				'setSecondary' => $set_secondary ?? null,
				'getPrimary'   => $get_primary ?? null,
				'removeBeta'   => $remove_beta ?? null,
				'events'       => $events,
			)
		);

		self::collect_failure(
			$failures,
			self::same_int_set(
				self::event_tt_ids( $events['actions'], 'add_term_relationship', $case['primaryTaxonomy'] ),
				self::tt_ids( array( $primary['alpha'], $primary['beta'] ) )
			)
				&& self::same_int_set(
					self::event_tt_ids( $events['actions'], 'added_term_relationship', $case['primaryTaxonomy'] ),
					self::tt_ids( array( $primary['alpha'], $primary['beta'] ) )
				)
				&& self::same_int_set(
					self::event_tt_ids( $events['actions'], 'add_term_relationship', $case['secondaryTaxonomy'] ),
					self::tt_ids( array( $secondary['one'] ) )
				)
				&& self::same_int_set(
					self::event_tt_ids( $events['actions'], 'delete_term_relationships', $case['primaryTaxonomy'] ),
					self::tt_ids( array( $primary['beta'] ) )
				)
				&& self::same_int_set(
					self::event_tt_ids( $events['actions'], 'deleted_term_relationships', $case['primaryTaxonomy'] ),
					self::tt_ids( array( $primary['beta'] ) )
				)
				&& self::events_only_reference( $events['actions'], array( $post_id ), $known_taxons ),
			'term relationship actions are scoped to the touched object, taxonomy, and term-taxonomy IDs',
			array( 'actions' => $events['actions'] )
		);

		$primary_set_events = self::events_by_hook_and_taxonomy( $events['actions'], 'set_object_terms', $case['primaryTaxonomy'] );
		$secondary_set_events = self::events_by_hook_and_taxonomy( $events['actions'], 'set_object_terms', $case['secondaryTaxonomy'] );

		self::collect_failure(
			$failures,
			1 === count( $primary_set_events )
				&& 1 === count( $secondary_set_events )
				&& self::same_int_set( $primary_set_events[0]['ttIds'] ?? array(), self::tt_ids( array( $primary['alpha'], $primary['beta'] ) ) )
				&& array() === ( $primary_set_events[0]['oldTtIds'] ?? array( null ) )
				&& false === ( $primary_set_events[0]['append'] ?? true )
				&& self::same_int_set( $secondary_set_events[0]['ttIds'] ?? array(), self::tt_ids( array( $secondary['one'] ) ) ),
			'set_object_terms action reports append flag, old terms, and target taxonomy locally',
			array(
				'primarySetEvents'   => $primary_set_events,
				'secondarySetEvents' => $secondary_set_events,
			)
		);

		self::collect_failure(
			$failures,
			self::filters_only_reference( $events['filters'], array( $post_id ), $known_taxons )
				&& self::has_filter_event( $events['filters'], 'wp_get_object_terms_args', $case['primaryTaxonomy'], 'ids' )
				&& self::has_filter_event( $events['filters'], 'get_object_terms', $case['primaryTaxonomy'], 'ids' )
				&& self::has_filter_event( $events['filters'], 'wp_get_object_terms', $case['primaryTaxonomy'], 'ids' ),
			'wp_get_object_terms filters stay local to queried object IDs and taxonomies',
			array( 'filters' => $events['filters'] )
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'add_term_relationship', $add_term_relationship )
				&& false === \has_filter( 'added_term_relationship', $added_term_relationship )
				&& false === \has_filter( 'set_object_terms', $set_object_terms )
				&& false === \has_filter( 'delete_term_relationships', $delete_term_relationships )
				&& false === \has_filter( 'deleted_term_relationships', $deleted_term_relationships )
				&& false === \has_filter( 'update_term_count', $update_term_count )
				&& false === \has_filter( 'wp_get_object_terms_args', $wp_get_object_terms_args )
				&& false === \has_filter( 'get_object_terms', $get_object_terms )
				&& false === \has_filter( 'wp_get_object_terms', $wp_get_object_terms ),
			'surface hook callbacks are removed before returning',
			array()
		);

		return $ctx->result(
			'taxonomy-relationships.filters-actions-local',
			array() === $failures,
			array(
				'case'     => self::case_summary( $case ),
				'events'   => array(
					'actionCount' => count( $events['actions'] ),
					'filterCount' => count( $events['filters'] ),
				),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $case, array $snapshot ): array {
		$wpdb             = $GLOBALS['wpdb'] ?? null;
		$globals_restored = self::globals_match_snapshot(
			$snapshot['globals'],
			array(
				'wp',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'wp_object_cache',
				'_wp_post_type_features',
				'post_type_meta_caps',
				'wp_post_types',
				'wp_post_statuses',
				'wp_taxonomies',
				'wp_rewrite',
				'current_user',
				'user_ID',
			)
		);
		$server_restored  = self::server_matches_snapshot( $snapshot['server'] );
		$wpdb_snapshot    = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? self::snapshot_wpdb_stub( $wpdb ) : array();
		$wpdb_ok          = $wpdb instanceof \Component_Fuzz_WPDB_Stub && self::wpdb_snapshots_equal( $wpdb_snapshot, $snapshot['wpdb'] );
		$counts           = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_content_counts() : array();
		$cache_ok         = false === \wp_cache_get( $case['token'], 'taxonomy-relationships' );
		$type_ok          = ! \post_type_exists( $case['postType'] );
		$primary_ok       = ! \taxonomy_exists( $case['primaryTaxonomy'] );
		$second_ok        = ! \taxonomy_exists( $case['secondaryTaxonomy'] );

		return $ctx->result(
			'taxonomy-relationships.state-restored-between-iterations',
			array() === $globals_restored
				&& array() === $server_restored
				&& $wpdb_ok
				&& $cache_ok
				&& $type_ok
				&& $primary_ok
				&& $second_ok,
			array(
				'globalMismatches' => $globals_restored,
				'serverMismatches' => $server_restored,
				'wpdbRestored'     => $wpdb_ok,
				'counts'           => $counts,
				'cacheEmpty'       => $cache_ok,
				'postTypeGone'     => $type_ok,
				'primaryGone'      => $primary_ok,
				'secondaryGone'    => $second_ok,
			)
		);
	}

	private static function create_fixture( array $case, string $label ): array {
		$post_id        = self::create_host_post( $case, 'host-' . $label );
		$second_post_id = self::create_host_post( $case, 'peer-' . $label );

		if ( \is_wp_error( $post_id ) || \is_wp_error( $second_post_id ) ) {
			return array(
				'error' => array(
					'post'       => self::error_summary( $post_id ),
					'secondPost' => self::error_summary( $second_post_id ),
				),
			);
		}

		$primary   = self::insert_fixture_terms( $case['primaryTaxonomy'], $case['primaryTerms'], $label );
		$secondary = self::insert_fixture_terms( $case['secondaryTaxonomy'], $case['secondaryTerms'], $label );

		if ( isset( $primary['error'] ) || isset( $secondary['error'] ) ) {
			return array(
				'error' => array(
					'primary'   => $primary['error'] ?? null,
					'secondary' => $secondary['error'] ?? null,
				),
			);
		}

		return array(
			'postId'       => (int) $post_id,
			'secondPostId' => (int) $second_post_id,
			'primary'      => $primary,
			'secondary'    => $secondary,
		);
	}

	private static function create_host_post( array $case, string $label, string $status = 'publish' ) {
		return self::create_host_post_for_type( $case, $label, $case['postType'], $status );
	}

	private static function create_host_post_for_type( array $case, string $label, string $post_type, string $status = 'publish' ) {
		return \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => $post_type,
					'post_title'   => 'Relationship ' . $label . ' ' . $case['token'],
					'post_content' => 'Relationship fixture content ' . $label,
					'post_status'  => $status,
					'post_name'    => substr( 'rel-' . $label . '-' . $case['token'], 0, 180 ),
				)
			),
			true,
			false
		);
	}

	private static function insert_fixture_terms( string $taxonomy, array $terms, string $label ): array {
		$created = array();

		foreach ( $terms as $key => $term ) {
			$result = \wp_insert_term(
				$term['name'],
				$taxonomy,
				array(
					'slug'        => $term['slug'] . '-' . $label,
					'description' => $term['description'],
				)
			);

			if ( \is_wp_error( $result ) ) {
				return array(
					'error' => array(
						'key'    => $key,
						'result' => self::error_summary( $result ),
					),
				);
			}

			$term_object = \get_term( $result['term_id'], $taxonomy );
			if ( ! $term_object instanceof \WP_Term ) {
				return array(
					'error' => array(
						'key'    => $key,
						'result' => $result,
						'term'   => $term_object,
					),
				);
			}

			$created[ $key ] = array(
				'term_id'          => (int) $term_object->term_id,
				'term_taxonomy_id' => (int) $term_object->term_taxonomy_id,
				'taxonomy'         => $term_object->taxonomy,
				'name'             => $term_object->name,
				'slug'             => $term_object->slug,
			);
		}

		return $created;
	}

	private static function prepare_runtime( array $case ): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->component_fuzz_reset_content();
		$wpdb->component_fuzz_reset_options(
			array(
				'admin_email'            => 'admin@example.test',
				'blog_charset'           => 'UTF-8',
				'blogname'               => 'Component Fuzz',
				'db_version'             => 60000,
				'default_category'       => 0,
				'default_comment_status' => 'open',
				'default_ping_status'    => 'closed',
				'home'                   => 'http://example.test',
				'permalink_structure'    => '',
				'siteurl'                => 'http://example.test',
			)
		);

		\wp_cache_flush();
		\wp_cache_set( $case['token'], 'prepared', 'taxonomy-relationships' );

		$GLOBALS['wp']               = new \WP();
		$GLOBALS['wp_rewrite']       = new \WP_Rewrite();
		$GLOBALS['wp_actions']       = array();
		$GLOBALS['wp_filters']       = array();
		$GLOBALS['wp_current_filter'] = array();
		$GLOBALS['wp_post_types']    = array();
		$GLOBALS['wp_taxonomies']    = array();
		$GLOBALS['wp_post_statuses'] = array();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );

		\register_post_type(
			$case['postType'],
			array(
				'public'    => true,
				'rewrite'   => false,
				'query_var' => false,
				'supports'  => array( 'title', 'editor' ),
			)
		);

		foreach (
			array(
				$case['primaryTaxonomy']   => array(
					'hierarchical' => $case['primaryHierarchical'],
					'sort'         => $case['sortPrimary'],
				),
				$case['secondaryTaxonomy'] => array(
					'hierarchical' => $case['secondaryHierarchical'],
					'sort'         => $case['sortSecondary'],
				),
			) as $taxonomy => $args
		) {
			\register_taxonomy(
				$taxonomy,
				$case['postType'],
				array(
					'public'       => true,
					'hierarchical' => $args['hierarchical'],
					'rewrite'      => false,
					'query_var'    => false,
					'show_in_rest' => false,
					'sort'         => $args['sort'],
				)
			);
		}

		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz TaxonomyRelationships';
		$_SERVER['REQUEST_URI']     = '/component-fuzz/taxonomy-relationships/';
		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['SERVER_SOFTWARE'] = 'ComponentFuzz';
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wp',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'wp_object_cache',
				'_wp_post_type_features',
				'post_type_meta_caps',
				'wp_post_types',
				'wp_post_statuses',
				'wp_taxonomies',
				'wp_rewrite',
				'current_user',
				'user_ID',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::snapshot_value( $GLOBALS[ $name ] ) : null,
			);
		}

		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_SOFTWARE' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'server'  => $server,
			'wpdb'    => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? self::snapshot_wpdb_stub( $GLOBALS['wpdb'] )
				: array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			self::restore_wpdb_stub( $GLOBALS['wpdb'], $snapshot['wpdb'] );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
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

	private static function snapshot_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'snapshot_value' ), $value );
		}

		if ( is_object( $value ) ) {
			try {
				return clone $value;
			} catch ( \Throwable $e ) {
				return $value;
			}
		}

		return $value;
	}

	private static function snapshot_wpdb_stub( \Component_Fuzz_WPDB_Stub $wpdb ): array {
		$reflection = new \ReflectionObject( $wpdb );
		$private    = array();

		foreach ( self::wpdb_private_property_names() as $name ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}

			$property = $reflection->getProperty( $name );
			$private[ $name ] = self::snapshot_value( $property->getValue( $wpdb ) );
		}

		$public = array();
		foreach ( self::wpdb_public_property_names() as $name ) {
			if ( property_exists( $wpdb, $name ) ) {
				$public[ $name ] = self::snapshot_value( $wpdb->$name );
			}
		}

		return array(
			'private'       => $private,
			'public'        => $public,
			'contentCounts' => $wpdb->component_fuzz_content_counts(),
			'options'       => $wpdb->component_fuzz_get_options(),
		);
	}

	private static function restore_wpdb_stub( \Component_Fuzz_WPDB_Stub $wpdb, array $snapshot ): void {
		$reflection = new \ReflectionObject( $wpdb );

		foreach ( $snapshot['private'] ?? array() as $name => $value ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}

			$property = $reflection->getProperty( $name );
			$property->setValue( $wpdb, self::snapshot_value( $value ) );
		}

		foreach ( $snapshot['public'] ?? array() as $name => $value ) {
			if ( property_exists( $wpdb, $name ) ) {
				$wpdb->$name = self::snapshot_value( $value );
			}
		}
	}

	private static function wpdb_snapshots_equal( array $actual, array $expected ): bool {
		return ( $actual['private'] ?? array() ) === ( $expected['private'] ?? array() )
			&& ( $actual['public'] ?? array() ) === ( $expected['public'] ?? array() )
			&& ( $actual['options'] ?? array() ) === ( $expected['options'] ?? array() )
			&& ( $actual['contentCounts'] ?? array() ) === ( $expected['contentCounts'] ?? array() );
	}

	private static function wpdb_private_property_names(): array {
		return array(
			'component_fuzz_options',
			'component_fuzz_posts',
			'component_fuzz_terms',
			'component_fuzz_term_taxonomy_rows',
			'component_fuzz_term_relationship_rows',
			'component_fuzz_users',
			'component_fuzz_comments',
			'component_fuzz_links',
			'component_fuzz_meta',
			'component_fuzz_next_ids',
		);
	}

	private static function wpdb_public_property_names(): array {
		return array(
			'suppress_errors',
			'last_query',
			'last_error',
			'rows_affected',
			'insert_id',
			'num_rows',
		);
	}

	private static function globals_match_snapshot( array $snapshot, array $names ): array {
		$mismatches = array();

		foreach ( $names as $name ) {
			$expected_exists = (bool) ( $snapshot[ $name ]['exists'] ?? false );
			$actual_exists   = array_key_exists( $name, $GLOBALS );
			if ( $expected_exists !== $actual_exists ) {
				$mismatches[] = array(
					'name'           => $name,
					'expectedExists' => $expected_exists,
					'actualExists'   => $actual_exists,
				);
				continue;
			}

			if ( $actual_exists && $GLOBALS[ $name ] != $snapshot[ $name ]['value'] ) {
				$mismatches[] = array(
					'name' => $name,
					'type' => 'value',
				);
			}
		}

		return $mismatches;
	}

	private static function server_matches_snapshot( array $snapshot ): array {
		$mismatches = array();

		foreach ( $snapshot as $name => $entry ) {
			$expected_exists = (bool) ( $entry['exists'] ?? false );
			$actual_exists   = array_key_exists( $name, $_SERVER );
			if ( $expected_exists !== $actual_exists || ( $actual_exists && $_SERVER[ $name ] !== $entry['value'] ) ) {
				$mismatches[] = array(
					'name'           => $name,
					'expectedExists' => $expected_exists,
					'actualExists'   => $actual_exists,
				);
			}
		}

		return $mismatches;
	}

	private static function relationship_rows( ?int $object_id = null ): array {
		$wpdb  = $GLOBALS['wpdb'];
		$query = "SELECT * FROM {$wpdb->term_relationships}";
		if ( null !== $object_id ) {
			$query = $wpdb->prepare( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id = %d", $object_id );
		}

		$rows = $wpdb->get_results( $query, ARRAY_A );
		$rows = array_map(
			static function ( $row ) {
				return array(
					'object_id'        => (int) $row['object_id'],
					'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
					'term_order'       => (int) ( $row['term_order'] ?? 0 ),
				);
			},
			is_array( $rows ) ? $rows : array()
		);

		usort(
			$rows,
			static function ( $a, $b ) {
				$comparison = $a['object_id'] <=> $b['object_id'];
				if ( 0 === $comparison ) {
					$comparison = $a['term_taxonomy_id'] <=> $b['term_taxonomy_id'];
				}
				return $comparison;
			}
		);

		return $rows;
	}

	private static function relationship_tt_ids_for_taxonomy( int $object_id, array $terms ): array {
		$allowed = array_fill_keys( self::tt_ids( $terms ), true );
		$out     = array();

		foreach ( self::relationship_rows( $object_id ) as $row ) {
			if ( isset( $allowed[ $row['term_taxonomy_id'] ] ) ) {
				$out[] = $row['term_taxonomy_id'];
			}
		}

		return $out;
	}

	private static function relationship_cache_map( array $object_ids, array $taxonomies ): array {
		$map = array();

		foreach ( array_map( 'strval', $taxonomies ) as $taxonomy ) {
			$group = "{$taxonomy}_relationships";
			foreach ( self::to_ints( $object_ids ) as $object_id ) {
				$value = \wp_cache_get( $object_id, $group );
				$map[ $taxonomy ][ $object_id ] = false === $value ? false : self::sorted_ints( $value );
			}
		}

		return self::normalize_relationship_cache_map( $map );
	}

	private static function empty_relationship_cache_map( array $object_ids, array $taxonomies ): array {
		$map = array();

		foreach ( array_map( 'strval', $taxonomies ) as $taxonomy ) {
			foreach ( self::to_ints( $object_ids ) as $object_id ) {
				$map[ $taxonomy ][ $object_id ] = array();
			}
		}

		return $map;
	}

	private static function normalize_relationship_cache_map( array $map ): array {
		ksort( $map );

		foreach ( $map as &$by_object_id ) {
			ksort( $by_object_id, SORT_NUMERIC );
			foreach ( $by_object_id as &$term_ids ) {
				if ( false !== $term_ids ) {
					$term_ids = self::sorted_ints( $term_ids );
				}
			}
			unset( $term_ids );
		}
		unset( $by_object_id );

		return $map;
	}

	private static function relationship_cache_all_false( array $map ): bool {
		foreach ( $map as $by_object_id ) {
			foreach ( $by_object_id as $value ) {
				if ( false !== $value ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function relationship_cache_maps_equal( array $actual, array $expected ): bool {
		return self::normalize_relationship_cache_map( $actual ) === self::normalize_relationship_cache_map( $expected );
	}

	private static function object_id_input( array $object_ids, string $shape ) {
		$object_ids = self::to_ints( $object_ids );
		if ( 'csv' === $shape ) {
			return implode( ', ', $object_ids );
		}

		return $object_ids;
	}

	private static function term_query_args( string $fields ): array {
		return array(
			'fields'                 => $fields,
			'orderby'                => 'term_id',
			'order'                  => 'ASC',
			'update_term_meta_cache' => false,
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token          = substr( hash( 'sha256', $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );
		$assignment_ctx = $ctx->fork( 'assignment-cases' );
		$mutation_ctx   = $ctx->fork( 'mutation-cases' );

		return array(
			'token'                 => $token,
			'postType'              => 'cf_rel_p_' . $token,
			'primaryTaxonomy'       => 'cf_rel_a_' . $token,
			'secondaryTaxonomy'     => 'cf_rel_b_' . $token,
			'missingTaxonomy'       => 'cf_rel_missing_' . $token,
			'primaryHierarchical'   => $ctx->bool(),
			'secondaryHierarchical' => $ctx->bool(),
			'sortPrimary'           => $ctx->bool(),
			'sortSecondary'         => $ctx->bool(),
			'primaryTerms'          => self::term_specs(
				$ctx->fork( 'primary-terms' ),
				'primary',
				$token,
				array( 'alpha', 'beta', 'gamma', 'delta' )
			),
			'secondaryTerms'        => self::term_specs( $ctx->fork( 'secondary-terms' ), 'secondary', $token, array( 'one', 'two' ) ),
			'assignmentSeed'        => $assignment_ctx->seed(),
			'assignmentCases'       => self::assignment_cases( $assignment_ctx ),
			'mutationSeed'          => $mutation_ctx->seed(),
			'mutationCases'         => self::mutation_cases( $mutation_ctx ),
		);
	}

	private static function term_specs( \ComponentFuzz\FuzzContext $ctx, string $prefix, string $token, array $keys ): array {
		$terms = array();

		foreach ( $keys as $key ) {
			$name = self::usable_name(
				$ctx->choice(
					array(
						ucfirst( $key ) . ' ' . $token,
						$prefix . ' ' . $key . ' & value',
						'<b>' . $prefix . '</b> ' . $key,
						html_entity_decode( 'Entity &#233; ' . $key . ' ' . $token, ENT_QUOTES, 'UTF-8' ),
						"slashes \\\\ / ' \" " . $key,
					)
				),
				ucfirst( $key ) . ' ' . $token
			);
			$slug = self::usable_slug( $prefix . '-' . $key . '-' . $token, $prefix . '-' . $key . '-' . $token );

			$terms[ $key ] = array(
				'name'        => $name,
				'slug'        => $slug,
				'description' => 'Relationship term ' . $prefix . ' ' . $key . ' ' . $token,
			);
		}

		return $terms;
	}

	private static function assignment_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'   => 'replace-empty-clears',
				'seed'    => $ctx->fork( 'explicit-replace-empty-clears' )->seed(),
				'append'  => false,
				'initial' => array(
					self::term_input_spec( 'alpha', 'id' ),
					self::term_input_spec( 'beta', 'slug' ),
				),
				'input'   => array(),
			),
			array(
				'label'   => 'append-empty-preserves',
				'seed'    => $ctx->fork( 'explicit-append-empty-preserves' )->seed(),
				'append'  => true,
				'initial' => array(
					self::term_input_spec( 'alpha', 'id' ),
					self::term_input_spec( 'gamma', 'id' ),
				),
				'input'   => array(),
			),
			array(
				'label'   => 'replace-mixed-duplicates',
				'seed'    => $ctx->fork( 'explicit-replace-mixed-duplicates' )->seed(),
				'append'  => false,
				'initial' => array(
					self::term_input_spec( 'delta', 'slug' ),
				),
				'input'   => array(
					self::term_input_spec( 'beta', 'slug' ),
					self::term_input_spec( 'alpha', 'id' ),
					self::term_input_spec( 'beta', 'id' ),
					self::term_input_spec( 'gamma', 'slug' ),
					self::term_input_spec( 'alpha', 'id' ),
				),
			),
			array(
				'label'   => 'append-existing-new-duplicates',
				'seed'    => $ctx->fork( 'explicit-append-existing-new-duplicates' )->seed(),
				'append'  => true,
				'initial' => array(
					self::term_input_spec( 'alpha', 'id' ),
					self::term_input_spec( 'beta', 'slug' ),
				),
				'input'   => array(
					self::term_input_spec( 'beta', 'id' ),
					self::term_input_spec( 'delta', 'slug' ),
					self::term_input_spec( 'alpha', 'slug' ),
					self::term_input_spec( 'delta', 'slug' ),
				),
			),
		);

		$keys = array( 'alpha', 'beta', 'gamma', 'delta' );
		for ( $i = count( $cases ); $i < 8; ++$i ) {
			$case_ctx = $ctx->fork( 'generated-' . $i );
			$cases[]  = array(
				'label'   => 'generated-' . $i,
				'seed'    => $case_ctx->seed(),
				'append'  => $case_ctx->bool(),
				'initial' => self::generated_term_input_specs( $case_ctx->fork( 'initial' ), $keys, $case_ctx->int( 0, 3 ) ),
				'input'   => self::generated_term_input_specs( $case_ctx->fork( 'input' ), $keys, $case_ctx->int( 0, 5 ) ),
			);
		}

		return $cases;
	}

	private static function mutation_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label'    => 'replace-first-primary-with-overlap',
				'type'     => 'set',
				'object'   => 'first',
				'taxonomy' => 'primary',
				'append'   => false,
				'terms'    => array(
					self::term_input_spec( 'beta', 'slug' ),
					self::term_input_spec( 'gamma', 'id' ),
					self::term_input_spec( 'beta', 'id' ),
				),
			),
			array(
				'label'    => 'append-second-primary-existing-and-new',
				'type'     => 'set',
				'object'   => 'second',
				'taxonomy' => 'primary',
				'append'   => true,
				'terms'    => array(
					self::term_input_spec( 'gamma', 'id' ),
					self::term_input_spec( 'delta', 'slug' ),
					self::term_input_spec( 'alpha', 'slug' ),
				),
			),
			array(
				'label'    => 'remove-third-primary-present-and-missing',
				'type'     => 'remove',
				'object'   => 'third',
				'taxonomy' => 'primary',
				'terms'    => array(
					self::term_input_spec( 'delta', 'slug' ),
					self::term_input_spec( 'alpha', 'id' ),
				),
			),
			array(
				'label'    => 'append-first-secondary-duplicate',
				'type'     => 'set',
				'object'   => 'first',
				'taxonomy' => 'secondary',
				'append'   => true,
				'terms'    => array(
					self::term_input_spec( 'one', 'id' ),
					self::term_input_spec( 'two', 'slug' ),
					self::term_input_spec( 'two', 'slug' ),
				),
			),
			array(
				'label'      => 'delete-second-primary-relationships',
				'type'       => 'delete',
				'object'     => 'second',
				'taxonomies' => array( 'primary' ),
			),
			array(
				'label'    => 'clear-first-primary-via-empty-replace',
				'type'     => 'set',
				'object'   => 'first',
				'taxonomy' => 'primary',
				'append'   => false,
				'terms'    => array(),
			),
			array(
				'label'      => 'delete-first-all-taxonomy-relationships',
				'type'       => 'delete',
				'object'     => 'first',
				'taxonomies' => array( 'primary', 'secondary' ),
			),
		);

		$objects = array( 'first', 'second', 'third' );
		for ( $i = 0; $i < 4; ++$i ) {
			$case_ctx = $ctx->fork( 'generated-' . $i );
			$type     = $case_ctx->weightedChoice(
				array(
					array( 5, 'set' ),
					array( 3, 'remove' ),
					array( 2, 'delete' ),
				)
			);
			$object   = $case_ctx->choice( $objects );

			if ( 'delete' === $type ) {
				$roles = $case_ctx->bool( 30 ) ? array( 'primary', 'secondary' ) : array( $case_ctx->choice( array( 'primary', 'secondary' ) ) );
				$cases[] = array(
					'label'      => 'generated-' . $i . '-delete',
					'type'       => 'delete',
					'object'     => $object,
					'taxonomies' => $roles,
				);
				continue;
			}

			$role = $case_ctx->choice( array( 'primary', 'secondary' ) );
			$keys = 'primary' === $role ? array( 'alpha', 'beta', 'gamma', 'delta' ) : array( 'one', 'two' );
			$count = 'set' === $type ? $case_ctx->int( 0, 4 ) : $case_ctx->int( 1, 3 );

			$cases[] = array(
				'label'    => 'generated-' . $i . '-' . $type,
				'type'     => $type,
				'object'   => $object,
				'taxonomy' => $role,
				'append'   => 'set' === $type ? $case_ctx->bool() : false,
				'terms'    => self::generated_term_input_specs( $case_ctx->fork( 'terms' ), $keys, $count ),
			);
		}

		return $cases;
	}

	private static function generated_term_input_specs( \ComponentFuzz\FuzzContext $ctx, array $keys, int $count ): array {
		$fields = array( 'id', 'slug' );
		$specs  = array();

		for ( $i = 0; $i < $count; ++$i ) {
			if ( array() !== $specs && $ctx->bool( 30 ) ) {
				$specs[] = $ctx->choice( $specs );
				continue;
			}

			$specs[] = self::term_input_spec( $ctx->choice( $keys ), $ctx->choice( $fields ) );
		}

		return $specs;
	}

	private static function term_input_spec( string $term, string $field ): array {
		return array(
			'term'  => $term,
			'field' => $field,
		);
	}

	private static function assignment_case_summaries( array $scenarios ): array {
		return array_map(
			static function ( $scenario ) {
				return array(
					'label'        => $scenario['label'],
					'seed'         => $scenario['seed'],
					'append'       => $scenario['append'],
					'initialSpecs' => $scenario['initial'],
					'inputSpecs'   => $scenario['input'],
				);
			},
			$scenarios
		);
	}

	private static function usable_name( string $value, string $fallback ): string {
		$value = function_exists( 'wp_check_invalid_utf8' ) ? \wp_check_invalid_utf8( $value, true ) : $value;
		$value = trim( strip_tags( $value ) );
		if ( '' === $value ) {
			$value = $fallback;
		}

		return substr( $value, 0, 80 );
	}

	private static function usable_slug( string $value, string $fallback ): string {
		$slug = \sanitize_title( $value );
		if ( '' === $slug ) {
			$slug = \sanitize_title( $fallback );
		}

		return substr( $slug, 0, 80 );
	}

	private static function same_int_set( $actual, array $expected ): bool {
		if ( \is_wp_error( $actual ) || ! is_array( $actual ) ) {
			return false;
		}

		$actual   = array_values( array_unique( self::to_ints( $actual ) ) );
		$expected = array_values( array_unique( self::to_ints( $expected ) ) );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	private static function same_int_list( array $actual, array $expected ): bool {
		return self::to_ints( $actual ) === self::to_ints( $expected );
	}

	private static function same_string_set( $actual, array $expected ): bool {
		if ( \is_wp_error( $actual ) || ! is_array( $actual ) ) {
			return false;
		}

		$actual   = array_values( array_unique( array_map( 'strval', $actual ) ) );
		$expected = array_values( array_unique( array_map( 'strval', $expected ) ) );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	private static function same_string_map( $actual, array $expected ): bool {
		if ( \is_wp_error( $actual ) || ! is_array( $actual ) ) {
			return false;
		}

		$actual_map = array();
		foreach ( $actual as $key => $value ) {
			$actual_map[ (string) $key ] = (string) $value;
		}

		$expected_map = array();
		foreach ( $expected as $key => $value ) {
			$expected_map[ (string) $key ] = (string) $value;
		}

		ksort( $actual_map );
		ksort( $expected_map );

		return $actual_map === $expected_map;
	}

	private static function relationship_rows_equal( array $first, array $second ): bool {
		return array_values( $first ) === array_values( $second );
	}

	private static function relationship_rows_have_unique_keys( array $rows ): bool {
		$keys = array();
		foreach ( $rows as $row ) {
			$key = $row['object_id'] . ':' . $row['term_taxonomy_id'];
			if ( isset( $keys[ $key ] ) ) {
				return false;
			}
			$keys[ $key ] = true;
		}

		return true;
	}

	private static function empty_expected_relationship_keys( array $object_ids, array $taxonomies ): array {
		$expected = array();

		foreach ( self::to_ints( $object_ids ) as $object_id ) {
			$expected[ $object_id ] = array();
			foreach ( array_map( 'strval', $taxonomies ) as $taxonomy ) {
				$expected[ $object_id ][ $taxonomy ] = array();
			}
		}

		return $expected;
	}

	private static function expected_set_relationship_keys( array &$expected, int $object_id, string $taxonomy, array $keys, bool $append ): void {
		$keys = self::unique_strings( $keys );
		if ( $append ) {
			$expected[ $object_id ][ $taxonomy ] = self::unique_strings(
				array_merge( $expected[ $object_id ][ $taxonomy ] ?? array(), $keys )
			);
			return;
		}

		$expected[ $object_id ][ $taxonomy ] = $keys;
	}

	private static function expected_remove_relationship_keys( array &$expected, int $object_id, string $taxonomy, array $keys ): void {
		$remove = array_fill_keys( array_map( 'strval', $keys ), true );
		$kept   = array();

		foreach ( $expected[ $object_id ][ $taxonomy ] ?? array() as $key ) {
			if ( ! isset( $remove[ (string) $key ] ) ) {
				$kept[] = (string) $key;
			}
		}

		$expected[ $object_id ][ $taxonomy ] = $kept;
	}

	private static function unique_strings( array $values ): array {
		$out = array();
		$set = array();

		foreach ( $values as $value ) {
			$value = (string) $value;
			if ( isset( $set[ $value ] ) ) {
				continue;
			}
			$set[ $value ] = true;
			$out[]         = $value;
		}

		return $out;
	}

	private static function expected_relationship_mismatches( array $expected, array $term_sets, array $taxonomies ): array {
		$mismatches = array();

		foreach ( $expected as $object_id => $by_taxonomy ) {
			foreach ( $taxonomies as $role => $taxonomy ) {
				$keys           = $by_taxonomy[ $taxonomy ] ?? array();
				$expected_terms = self::terms_for_keys( $term_sets[ $role ], $keys );
				$expected_ids   = self::term_ids( $expected_terms );
				$expected_tt_ids = self::tt_ids( $expected_terms );
				$expected_slugs = self::term_slugs( $expected_terms );
				$ids            = \wp_get_object_terms( (int) $object_id, $taxonomy, self::term_query_args( 'ids' ) );
				$tt_ids         = \wp_get_object_terms( (int) $object_id, $taxonomy, self::term_query_args( 'tt_ids' ) );
				$slugs          = \wp_get_object_terms( (int) $object_id, $taxonomy, self::term_query_args( 'slugs' ) );
				$rows           = self::relationship_tt_ids_for_taxonomy( (int) $object_id, $term_sets[ $role ] );
				$has_any        = \is_object_in_term( (int) $object_id, $taxonomy );
				$first_key      = $keys[0] ?? null;
				$first_term     = null === $first_key ? null : $term_sets[ $role ][ $first_key ];
				$has_first      = null === $first_term ? false : \is_object_in_term( (int) $object_id, $taxonomy, $first_term['term_id'] );

				if (
					! self::same_int_set( $ids, $expected_ids )
					|| ! self::same_int_set( $tt_ids, $expected_tt_ids )
					|| ! self::same_string_set( $slugs, $expected_slugs )
					|| ! self::same_int_set( $rows, $expected_tt_ids )
					|| ( array() === $expected_ids ? false !== $has_any : true !== $has_any )
					|| ( null !== $first_term && true !== $has_first )
				) {
					$mismatches[] = array(
						'objectId'    => (int) $object_id,
						'taxonomy'    => $taxonomy,
						'expectedIds' => $expected_ids,
						'ids'         => $ids,
						'expectedTtIds' => $expected_tt_ids,
						'ttIds'       => $tt_ids,
						'expectedSlugs' => $expected_slugs,
						'slugs'       => $slugs,
						'rows'        => $rows,
						'hasAny'      => $has_any,
						'hasFirst'    => $has_first,
					);
				}
			}
		}

		return $mismatches;
	}

	private static function expected_relationship_cache_map( array $expected, array $term_sets, array $taxonomies ): array {
		$map = array();

		foreach ( $expected as $object_id => $by_taxonomy ) {
			foreach ( $taxonomies as $role => $taxonomy ) {
				$terms = self::terms_for_keys( $term_sets[ $role ], $by_taxonomy[ $taxonomy ] ?? array() );
				$map[ $taxonomy ][ (int) $object_id ] = self::term_ids( $terms );
			}
		}

		return self::normalize_relationship_cache_map( $map );
	}

	private static function expected_count_mismatches( array $expected, array $term_sets, array $taxonomies ): array {
		$mismatches = array();

		foreach ( $taxonomies as $role => $taxonomy ) {
			$actual = self::term_counts( $term_sets[ $role ] );
			if ( ! self::term_counts_supported( $actual ) ) {
				continue;
			}

			$expected_counts = array_fill_keys( array_keys( $term_sets[ $role ] ), 0 );
			foreach ( $expected as $by_taxonomy ) {
				foreach ( $by_taxonomy[ $taxonomy ] ?? array() as $key ) {
					if ( array_key_exists( $key, $expected_counts ) ) {
						++$expected_counts[ $key ];
					}
				}
			}

			foreach ( $expected_counts as $key => $count ) {
				if ( ! array_key_exists( $key, $actual ) || (int) $actual[ $key ] !== (int) $count ) {
					$mismatches[] = array(
						'taxonomy' => $taxonomy,
						'term'     => $key,
						'expected' => (int) $count,
						'actual'   => $actual[ $key ] ?? null,
						'allActual'=> $actual,
					);
				}
			}
		}

		return $mismatches;
	}

	private static function expected_added_tt_ids( array $before, array $after, int $object_id, array $term_sets, array $taxonomies ): array {
		return self::expected_tt_id_diff( $before, $after, $object_id, $term_sets, $taxonomies );
	}

	private static function expected_deleted_tt_ids( array $before, array $after, int $object_id, array $term_sets, array $taxonomies ): array {
		return self::expected_tt_id_diff( $after, $before, $object_id, $term_sets, $taxonomies );
	}

	private static function expected_tt_id_diff( array $left, array $right, int $object_id, array $term_sets, array $taxonomies ): array {
		$out = array();

		foreach ( $taxonomies as $role => $taxonomy ) {
			$left_terms  = self::terms_for_keys( $term_sets[ $role ], $left[ $object_id ][ $taxonomy ] ?? array() );
			$right_terms = self::terms_for_keys( $term_sets[ $role ], $right[ $object_id ][ $taxonomy ] ?? array() );
			$left_ids    = array_fill_keys( self::tt_ids( $left_terms ), true );

			foreach ( self::tt_ids( $right_terms ) as $tt_id ) {
				if ( ! isset( $left_ids[ $tt_id ] ) ) {
					$out[] = (int) $tt_id;
				}
			}
		}

		return $out;
	}

	private static function mutation_cache_effect_matches( array $before, array $after, array $object_ids, array $taxonomies, int $changed_object_id, array $changed_taxonomies, bool $relationship_changed ): bool {
		$changed_taxonomy_map = array_fill_keys( array_map( 'strval', $changed_taxonomies ), true );

		foreach ( self::to_ints( $object_ids ) as $object_id ) {
			foreach ( array_map( 'strval', $taxonomies ) as $taxonomy ) {
				$actual = $after[ $taxonomy ][ $object_id ] ?? null;
				if ( $object_id === $changed_object_id && $relationship_changed && isset( $changed_taxonomy_map[ $taxonomy ] ) ) {
					if ( false !== $actual ) {
						return false;
					}
					continue;
				}

				if ( $actual !== ( $before[ $taxonomy ][ $object_id ] ?? null ) && false !== $actual ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function expected_relationship_summary( array $expected, array $term_sets, array $taxonomies ): array {
		$summary = array();

		foreach ( $expected as $object_id => $by_taxonomy ) {
			foreach ( $taxonomies as $role => $taxonomy ) {
				$summary[ (int) $object_id ][ $taxonomy ] = self::tt_ids(
					self::terms_for_keys( $term_sets[ $role ], $by_taxonomy[ $taxonomy ] ?? array() )
				);
			}
		}

		return $summary;
	}

	private static function contains_int_set( array $actual, array $expected ): bool {
		$actual_map = array_fill_keys( self::to_ints( $actual ), true );

		foreach ( self::to_ints( $expected ) as $value ) {
			if ( ! isset( $actual_map[ $value ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function to_ints( $values ): array {
		return array_map( 'intval', is_array( $values ) ? $values : array() );
	}

	private static function sorted_ints( $values ): array {
		$out = self::to_ints( $values );
		sort( $out, SORT_NUMERIC );

		return $out;
	}

	private static function compact_scalars( $values ): array {
		return array_map(
			static function ( $value ) {
				if ( is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) || null === $value ) {
					return $value;
				}

				return gettype( $value );
			},
			is_array( $values ) ? $values : array( $values )
		);
	}

	private static function tt_ids( array $terms ): array {
		return array_map(
			static function ( $term ) {
				return (int) $term['term_taxonomy_id'];
			},
			array_values( $terms )
		);
	}

	private static function term_ids( array $terms ): array {
		return array_map(
			static function ( $term ) {
				return (int) $term['term_id'];
			},
			array_values( $terms )
		);
	}

	private static function term_slugs( array $terms ): array {
		return array_map(
			static function ( $term ) {
				return (string) $term['slug'];
			},
			array_values( $terms )
		);
	}

	private static function term_names( array $terms ): array {
		return array_map(
			static function ( $term ) {
				return (string) $term['name'];
			},
			array_values( $terms )
		);
	}

	private static function term_input_values( array $terms, array $specs ): array {
		return array_map(
			static function ( $spec ) use ( $terms ) {
				$term = $terms[ $spec['term'] ];
				if ( 'id' === $spec['field'] ) {
					return (int) $term['term_id'];
				}
				if ( 'slug' === $spec['field'] ) {
					return (string) $term['slug'];
				}
				return (string) $term['name'];
			},
			$specs
		);
	}

	private static function term_input_tt_ids( array $terms, array $specs ): array {
		return array_map(
			static function ( $spec ) use ( $terms ) {
				return (int) $terms[ $spec['term'] ]['term_taxonomy_id'];
			},
			$specs
		);
	}

	private static function unique_term_keys_from_specs( array $specs ): array {
		$out = array();
		$set = array();

		foreach ( $specs as $spec ) {
			$key = (string) $spec['term'];
			if ( isset( $set[ $key ] ) ) {
				continue;
			}
			$set[ $key ] = true;
			$out[]       = $key;
		}

		return $out;
	}

	private static function merge_unique_term_keys( array ...$lists ): array {
		$out = array();
		$set = array();

		foreach ( $lists as $list ) {
			foreach ( $list as $key ) {
				$key = (string) $key;
				if ( isset( $set[ $key ] ) ) {
					continue;
				}
				$set[ $key ] = true;
				$out[]       = $key;
			}
		}

		return $out;
	}

	private static function terms_for_keys( array $terms, array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			if ( isset( $terms[ $key ] ) ) {
				$out[ $key ] = $terms[ $key ];
			}
		}

		return $out;
	}

	private static function term_field_map( array $terms, string $key_field, string $value_field ): array {
		$out = array();
		foreach ( $terms as $term ) {
			$out[ (string) $term[ $key_field ] ] = (string) $term[ $value_field ];
		}

		return $out;
	}

	private static function term_counts( array $terms ): array {
		$out = array();
		foreach ( $terms as $key => $term ) {
			$term_object = \get_term( $term['term_id'], $term['taxonomy'] );
			$out[ $key ] = $term_object instanceof \WP_Term ? (int) $term_object->count : null;
		}

		return $out;
	}

	private static function term_counts_supported( array $counts ): bool {
		foreach ( $counts as $count ) {
			if ( null === $count ) {
				return false;
			}
		}

		return array() !== $counts;
	}

	private static function same_named_counts( array $actual, array $expected ): bool {
		foreach ( $expected as $key => $count ) {
			if ( ! array_key_exists( $key, $actual ) || (int) $actual[ $key ] !== (int) $count ) {
				return false;
			}
		}

		return true;
	}

	private static function expected_term_order_map( array $terms, bool $sort_enabled ): array {
		$out   = array();
		$order = 0;

		foreach ( $terms as $term ) {
			$out[ (int) $term['term_taxonomy_id'] ] = $sort_enabled ? ++$order : 0;
		}

		return $out;
	}

	private static function relationship_term_order_matches( int $object_id, array $expected ): bool {
		$actual = array();
		foreach ( self::relationship_rows( $object_id ) as $row ) {
			if ( isset( $expected[ (int) $row['term_taxonomy_id'] ] ) ) {
				$actual[ (int) $row['term_taxonomy_id'] ] = (int) $row['term_order'];
			}
		}

		ksort( $actual );
		ksort( $expected );

		return $actual === $expected;
	}

	private static function pluck_terms( $terms, string $field ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->$field ) ) {
				$out[] = $term->$field;
			} elseif ( is_array( $term ) && isset( $term[ $field ] ) ) {
				$out[] = $term[ $field ];
			}
		}

		return $out;
	}

	private static function term_ids_by_object_id( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$object_id = null;
			$term_id   = null;

			if ( is_object( $term ) && isset( $term->object_id, $term->term_id ) ) {
				$object_id = (int) $term->object_id;
				$term_id   = (int) $term->term_id;
			} elseif ( is_array( $term ) && isset( $term['object_id'], $term['term_id'] ) ) {
				$object_id = (int) $term['object_id'];
				$term_id   = (int) $term['term_id'];
			}

			if ( null === $object_id || null === $term_id ) {
				continue;
			}

			if ( ! isset( $out[ $object_id ] ) ) {
				$out[ $object_id ] = array();
			}
			$out[ $object_id ][] = $term_id;
		}

		ksort( $out );
		foreach ( $out as &$term_ids ) {
			$term_ids = self::to_ints( $term_ids );
			sort( $term_ids, SORT_NUMERIC );
		}
		unset( $term_ids );

		return $out;
	}

	private static function term_summaries( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			static function ( $term ) {
				if ( $term instanceof \WP_Term ) {
					$summary = array(
						'term_id'          => (int) $term->term_id,
						'term_taxonomy_id' => (int) $term->term_taxonomy_id,
						'taxonomy'         => $term->taxonomy,
						'name'             => $term->name,
						'slug'             => $term->slug,
					);
					if ( isset( $term->count ) ) {
						$summary['count'] = (int) $term->count;
					}
					if ( isset( $term->object_id ) ) {
						$summary['object_id'] = (int) $term->object_id;
					}
					return $summary;
				}

				if ( is_array( $term ) ) {
					$summary = array(
						'term_id'          => (int) ( $term['term_id'] ?? 0 ),
						'term_taxonomy_id' => (int) ( $term['term_taxonomy_id'] ?? 0 ),
						'taxonomy'         => $term['taxonomy'] ?? null,
						'name'             => $term['name'] ?? null,
						'slug'             => $term['slug'] ?? null,
					);
					if ( array_key_exists( 'count', $term ) ) {
						$summary['count'] = (int) $term['count'];
					}
					if ( array_key_exists( 'object_id', $term ) ) {
						$summary['object_id'] = (int) $term['object_id'];
					}
					return $summary;
				}

				return $term;
			},
			$terms
		);
	}

	private static function csv_ints( $value ): array {
		if ( is_array( $value ) ) {
			return self::to_ints( $value );
		}

		$out = array();
		foreach ( explode( ',', (string) $value ) as $piece ) {
			$piece = trim( $piece );
			if ( '' !== $piece ) {
				$out[] = (int) $piece;
			}
		}

		return $out;
	}

	private static function taxonomy_sql_fragment_to_names( string $taxonomies ): array {
		$names = array();
		foreach ( explode( ',', $taxonomies ) as $taxonomy ) {
			$taxonomy = trim( $taxonomy, " \t\n\r\0\x0B'" );
			if ( '' !== $taxonomy ) {
				$names[] = $taxonomy;
			}
		}

		return $names;
	}

	private static function event_tt_ids( array $events, string $hook, string $taxonomy ): array {
		$out = array();
		foreach ( self::events_by_hook_and_taxonomy( $events, $hook, $taxonomy ) as $event ) {
			$out = array_merge( $out, self::to_ints( $event['ttIds'] ?? array() ) );
		}

		return $out;
	}

	private static function events_by_hook_and_taxonomy( array $events, string $hook, string $taxonomy ): array {
		return array_values(
			array_filter(
				$events,
				static function ( $event ) use ( $hook, $taxonomy ) {
					return $hook === ( $event['hook'] ?? null ) && $taxonomy === ( $event['taxonomy'] ?? null );
				}
			)
		);
	}

	private static function events_by_hook( array $events, string $hook ): array {
		return array_values(
			array_filter(
				$events,
				static function ( $event ) use ( $hook ) {
					return $hook === ( $event['hook'] ?? null );
				}
			)
		);
	}

	private static function event_tt_ids_by_hook( array $events, string $hook ): array {
		$out = array();
		foreach ( self::events_by_hook( $events, $hook ) as $event ) {
			$out = array_merge( $out, self::to_ints( $event['ttIds'] ?? array() ) );
		}

		return $out;
	}

	private static function event_counts_by_hook( array $events ): array {
		$counts = array();
		foreach ( $events as $event ) {
			$hook = (string) ( $event['hook'] ?? '' );
			if ( '' === $hook ) {
				continue;
			}
			if ( ! isset( $counts[ $hook ] ) ) {
				$counts[ $hook ] = 0;
			}
			++$counts[ $hook ];
		}
		ksort( $counts );

		return $counts;
	}

	private static function events_only_reference( array $events, array $object_ids, array $taxonomies ): bool {
		$object_map  = array_fill_keys( self::to_ints( $object_ids ), true );
		$taxonomy_map = array_fill_keys( array_map( 'strval', $taxonomies ), true );

		foreach ( $events as $event ) {
			if ( null !== ( $event['objectId'] ?? null ) && ! isset( $object_map[ (int) $event['objectId'] ] ) ) {
				return false;
			}
			if ( ! isset( $taxonomy_map[ (string) ( $event['taxonomy'] ?? '' ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function cache_events_only_reference( array $events, array $object_ids, array $object_types ): bool {
		$object_map = array_fill_keys( self::to_ints( $object_ids ), true );
		$type_map   = array_fill_keys( array_map( 'strval', $object_types ), true );

		foreach ( $events as $event ) {
			foreach ( self::to_ints( $event['objectIds'] ?? array() ) as $object_id ) {
				if ( ! isset( $object_map[ $object_id ] ) ) {
					return false;
				}
			}

			if ( ! isset( $type_map[ (string) ( $event['objectType'] ?? '' ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function count_events_only_reference( array $events, array $tt_ids, array $taxonomies ): bool {
		$tt_map       = array_fill_keys( self::to_ints( $tt_ids ), true );
		$taxonomy_map = array_fill_keys( array_map( 'strval', $taxonomies ), true );

		foreach ( $events as $event ) {
			foreach ( self::to_ints( $event['ttIds'] ?? array() ) as $tt_id ) {
				if ( ! isset( $tt_map[ $tt_id ] ) ) {
					return false;
				}
			}

			if ( ! isset( $taxonomy_map[ (string) ( $event['taxonomy'] ?? '' ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function filters_only_reference( array $events, array $object_ids, array $taxonomies ): bool {
		$object_map   = array_fill_keys( self::to_ints( $object_ids ), true );
		$taxonomy_map = array_fill_keys( array_map( 'strval', $taxonomies ), true );

		foreach ( $events as $event ) {
			foreach ( self::to_ints( $event['objectIds'] ?? array() ) as $object_id ) {
				if ( ! isset( $object_map[ $object_id ] ) ) {
					return false;
				}
			}

			foreach ( (array) ( $event['taxonomies'] ?? array() ) as $taxonomy ) {
				if ( ! isset( $taxonomy_map[ (string) $taxonomy ] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function has_filter_event( array $events, string $hook, string $taxonomy, string $fields ): bool {
		foreach ( $events as $event ) {
			if ( $hook !== ( $event['hook'] ?? null ) ) {
				continue;
			}
			if ( $fields !== ( $event['fields'] ?? null ) ) {
				continue;
			}
			if ( in_array( $taxonomy, (array) ( $event['taxonomies'] ?? array() ), true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_error_code( $value, string $code ): bool {
		return \is_wp_error( $value ) && $code === $value->get_error_code();
	}

	private static function collect_failure( array &$failures, bool $ok, string $label, array $details = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function case_summary( array $case ): array {
		return array(
			'token'             => $case['token'],
			'postType'          => $case['postType'],
			'primaryTaxonomy'   => $case['primaryTaxonomy'],
			'secondaryTaxonomy' => $case['secondaryTaxonomy'],
			'assignmentSeed'    => $case['assignmentSeed'],
			'primaryHier'       => $case['primaryHierarchical'],
			'secondaryHier'     => $case['secondaryHierarchical'],
			'sortPrimary'       => $case['sortPrimary'],
			'sortSecondary'     => $case['sortSecondary'],
		);
	}

	private static function error_summary( $value ) {
		if ( ! \is_wp_error( $value ) ) {
			return $value;
		}

		return array(
			'code' => $value->get_error_code(),
			'data' => $value->get_error_data(),
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
