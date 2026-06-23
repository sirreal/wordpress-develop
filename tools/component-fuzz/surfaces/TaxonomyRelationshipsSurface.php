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
			$rows[] = self::check_field_variants_and_membership( $ctx->fork( 'fields' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_remove_scope( $ctx->fork( 'remove' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_invalid_inputs( $ctx->fork( 'invalid' ), $case );

			self::prepare_runtime( $case );
			$rows[] = self::check_get_the_terms_cache( $ctx->fork( 'cache' ), $case );
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
			$rows[] = self::check_state_restored( $ctx, $case );
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
				'create_initial_post_types',
				'create_initial_taxonomies',
				'get_objects_in_term',
				'get_post',
				'get_term',
				'get_the_terms',
				'is_object_in_term',
				'is_wp_error',
				'post_type_exists',
				'register_post_type',
				'register_taxonomy',
				'sanitize_title',
				'term_exists',
				'taxonomy_exists',
				'wp_cache_delete',
				'wp_cache_flush',
				'wp_cache_get',
				'wp_get_object_terms',
				'wp_insert_post',
				'wp_insert_term',
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
		$assigned       = array( $primary['alpha'], $primary['gamma'], $primary['delta'] );

		\wp_set_object_terms(
			$post_id,
			array( $primary['alpha']['term_id'], $primary['gamma']['slug'], $primary['delta']['term_id'] ),
			$case['primaryTaxonomy'],
			false
		);
		\wp_set_object_terms( $second_post_id, array( $primary['alpha']['term_id'] ), $case['primaryTaxonomy'], false );

		$all    = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'all' ) );
		$ids    = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'ids' ) );
		$tt_ids = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'tt_ids' ) );
		$slugs  = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'slugs' ) );
		$names  = \wp_get_object_terms( $post_id, $case['primaryTaxonomy'], self::term_query_args( 'names' ) );

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
				&& self::same_string_set( $names, self::term_names( $assigned ) ),
			'wp_get_object_terms fields variants agree after canonicalization',
			array(
				'all'    => self::term_summaries( $all ),
				'ids'    => $ids,
				'ttIds'  => $tt_ids,
				'slugs'  => $slugs,
				'names'  => $names,
				'assign' => self::term_summaries( $assigned ),
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

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$wpdb       = $GLOBALS['wpdb'] ?? null;
		$counts     = $wpdb instanceof \Component_Fuzz_WPDB_Stub ? $wpdb->component_fuzz_content_counts() : array();
		$count_ok   = array() === array_filter( $counts );
		$cache_ok   = false === \wp_cache_get( $case['token'], 'taxonomy-relationships' );
		$type_ok    = ! \post_type_exists( $case['postType'] );
		$primary_ok = ! \taxonomy_exists( $case['primaryTaxonomy'] );
		$second_ok  = ! \taxonomy_exists( $case['secondaryTaxonomy'] );

		return $ctx->result(
			'taxonomy-relationships.state-restored-between-iterations',
			$count_ok && $cache_ok && $type_ok && $primary_ok && $second_ok,
			array(
				'counts'        => $counts,
				'cacheEmpty'    => $cache_ok,
				'postTypeGone'  => $type_ok,
				'primaryGone'   => $primary_ok,
				'secondaryGone' => $second_ok,
			)
		);
	}

	private static function create_fixture( array $case, string $label ): array {
		$post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => $case['postType'],
					'post_title'   => 'Relationship Host ' . $label . ' ' . $case['token'],
					'post_content' => 'Relationship fixture content ' . $label,
					'post_status'  => 'publish',
					'post_name'    => 'rel-host-' . $label . '-' . $case['token'],
				)
			),
			true,
			false
		);

		$second_post_id = \wp_insert_post(
			\wp_slash(
				array(
					'post_type'    => $case['postType'],
					'post_title'   => 'Relationship Peer ' . $label . ' ' . $case['token'],
					'post_content' => 'Relationship peer fixture content ' . $label,
					'post_status'  => 'publish',
					'post_name'    => 'rel-peer-' . $label . '-' . $case['token'],
				)
			),
			true,
			false
		);

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
				'value'  => isset( $GLOBALS[ $name ] ) && is_object( $GLOBALS[ $name ] ) ? clone $GLOBALS[ $name ] : ( $GLOBALS[ $name ] ?? null ),
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
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
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

	private static function relationship_rows( ?int $object_id = null ): array {
		$wpdb  = $GLOBALS['wpdb'];
		$query = "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships}";
		if ( null !== $object_id ) {
			$query = $wpdb->prepare( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d", $object_id );
		}

		$rows = $wpdb->get_results( $query, ARRAY_A );
		$rows = array_map(
			static function ( $row ) {
				return array(
					'object_id'        => (int) $row['object_id'],
					'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
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

	private static function term_query_args( string $fields ): array {
		return array(
			'fields'                 => $fields,
			'orderby'                => 'term_id',
			'order'                  => 'ASC',
			'update_term_meta_cache' => false,
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = substr( hash( 'sha256', $ctx->seed() . ':' . $ctx->iteration() ), 0, 10 );

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

	private static function to_ints( $values ): array {
		return array_map( 'intval', is_array( $values ) ? $values : array() );
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

	private static function term_summaries( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			static function ( $term ) {
				if ( $term instanceof \WP_Term ) {
					return array(
						'term_id'          => (int) $term->term_id,
						'term_taxonomy_id' => (int) $term->term_taxonomy_id,
						'taxonomy'         => $term->taxonomy,
						'name'             => $term->name,
						'slug'             => $term->slug,
					);
				}

				if ( is_array( $term ) ) {
					return array(
						'term_id'          => (int) ( $term['term_id'] ?? 0 ),
						'term_taxonomy_id' => (int) ( $term['term_taxonomy_id'] ?? 0 ),
						'taxonomy'         => $term['taxonomy'] ?? null,
						'name'             => $term['name'] ?? null,
						'slug'             => $term['slug'] ?? null,
					);
				}

				return $term;
			},
			$terms
		);
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
