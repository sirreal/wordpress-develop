<?php
namespace ComponentFuzz\Surfaces;

final class StateSurface {
	public const NAME = 'state';

	private const GENERATED_VALUE_CASES = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'state.bootstrap-apis-available',
					'Required WordPress state APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_cache_basic_semantics( $ctx );
			$rows[] = self::check_cache_group_isolation( $ctx );
			$rows[] = self::check_cache_blog_switch_and_global_groups( $ctx );
			$rows[] = self::check_cache_object_cloning( $ctx );
			$rows[] = self::check_cache_multiple_equivalence( $ctx );
			$rows[] = self::check_cache_add_multiple_contract( $ctx );
			$rows[] = self::check_cache_addition_suspension( $ctx );
			$rows[] = self::check_cache_increments( $ctx );
			$rows[] = self::check_cache_flushes_and_groups( $ctx );
			$rows[] = self::check_cache_last_changed( $ctx );

			$rows[] = self::check_option_filters( $ctx );
			$rows[] = self::check_option_crud( $ctx );
			$rows[] = self::check_option_cache_priming( $ctx );

			$rows[] = self::check_transient_crud_and_expiration( $ctx );
			$rows[] = self::check_transient_filters( $ctx );
			$rows[] = self::check_site_transient_cache_branch( $ctx );
			$rows[] = self::check_site_transient_option_branch( $ctx );

			$rows[] = self::check_serialization_helpers( $ctx );
			$rows[] = self::check_json_encoding( $ctx );
			$rows[] = self::check_deep_mapping_and_parse_args( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'state.surface-no-throw',
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
				'add_filter',
				'wp_cache_init',
				'wp_cache_add',
				'wp_cache_add_multiple',
				'wp_cache_replace',
				'wp_cache_set',
				'wp_cache_set_multiple',
				'wp_cache_get',
				'wp_cache_get_multiple',
				'wp_cache_delete',
				'wp_cache_delete_multiple',
				'wp_cache_incr',
				'wp_cache_decr',
				'wp_cache_flush',
				'wp_cache_flush_runtime',
				'wp_cache_flush_group',
				'wp_cache_supports',
				'wp_cache_add_global_groups',
				'wp_cache_add_non_persistent_groups',
				'wp_cache_switch_to_blog',
				'wp_cache_get_last_changed',
				'wp_cache_set_last_changed',
				'wp_suspend_cache_addition',
				'get_option',
				'add_option',
				'update_option',
				'delete_option',
				'wp_prime_option_caches',
				'get_transient',
				'set_transient',
				'delete_transient',
				'get_site_transient',
				'set_site_transient',
				'delete_site_transient',
				'get_site_option',
				'add_site_option',
				'update_site_option',
				'delete_site_option',
				'wp_prime_site_option_caches',
				'maybe_serialize',
				'maybe_unserialize',
				'is_serialized',
				'is_serialized_string',
				'wp_json_encode',
				'map_deep',
				'remove_filter',
				'has_filter',
				'wp_using_ext_object_cache',
				'wp_parse_args',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'Component_Fuzz_WPDB_Stub', false ) ) {
			$missing[] = 'class Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function check_cache_basic_semantics( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$case   = $ctx->fork( 'cache-basic' );
		$key    = self::key( $ctx, 'basic' );
		$group  = self::group( $ctx, 'basic' );
		$first  = self::wrapped_value( 'first', self::value( $case ) );
		$second = self::wrapped_value( 'second', self::value( $case ) );
		$third  = self::wrapped_value( 'third', self::value( $case ) );

		$found_missing = null;
		$missing       = wp_cache_get( $key, $group, false, $found_missing );
		$set           = wp_cache_set( $key, $first, $group );
		$found_first   = null;
		$got_first     = wp_cache_get( $key, $group, false, $found_first );
		$add_existing  = wp_cache_add( $key, $second, $group );
		$after_add     = wp_cache_get( $key, $group );
		$replace       = wp_cache_replace( $key, $second, $group );
		$after_replace = wp_cache_get( $key, $group );
		$delete        = wp_cache_delete( $key, $group );
		$found_deleted = null;
		$after_delete  = wp_cache_get( $key, $group, false, $found_deleted );
		$replace_gone  = wp_cache_replace( $key, $first, $group );
		$add_after     = wp_cache_add( $key, $third, $group );
		$after_readd   = wp_cache_get( $key, $group );

		$ok = false === $missing
			&& false === $found_missing
			&& true === $set
			&& true === $found_first
			&& self::same_value( $first, $got_first )
			&& false === $add_existing
			&& self::same_value( $first, $after_add )
			&& true === $replace
			&& self::same_value( $second, $after_replace )
			&& true === $delete
			&& false === $after_delete
			&& false === $found_deleted
			&& false === $replace_gone
			&& true === $add_after
			&& self::same_value( $third, $after_readd );

		return $ctx->result(
			'state.cache.set-add-replace-delete-semantics',
			$ok,
			array(
				'key'            => $key,
				'group'          => $group,
				'missing'        => $missing,
				'foundMissing'   => $found_missing,
				'set'            => $set,
				'addExisting'    => $add_existing,
				'replace'        => $replace,
				'delete'         => $delete,
				'replaceMissing' => $replace_gone,
				'addAfterDelete' => $add_after,
			)
		);
	}

	private static function check_cache_group_isolation( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$case   = $ctx->fork( 'cache-groups' );
		$key    = self::key( $ctx, 'shared' );
		$group1 = self::group( $ctx, 'group-a' );
		$group2 = self::group( $ctx, 'group-b' );
		$value1 = self::wrapped_value( 'group-a', self::value( $case ) );
		$value2 = self::wrapped_value( 'group-b', self::value( $case ) );

		wp_cache_add_global_groups( array( self::group( $ctx, 'global' ) ) );
		wp_cache_set( $key, $value1, $group1 );
		wp_cache_set( $key, $value2, $group2 );

		$got1  = wp_cache_get( $key, $group1 );
		$got2  = wp_cache_get( $key, $group2 );
		$del1  = wp_cache_delete( $key, $group1 );
		$post1 = wp_cache_get( $key, $group1 );
		$post2 = wp_cache_get( $key, $group2 );

		$ok = self::same_value( $value1, $got1 )
			&& self::same_value( $value2, $got2 )
			&& true === $del1
			&& false === $post1
			&& self::same_value( $value2, $post2 );

		return $ctx->result(
			'state.cache.groups-isolate-shared-keys',
			$ok,
			array(
				'key'    => $key,
				'group1' => $group1,
				'group2' => $group2,
				'got1'   => self::describe_value( $got1 ),
				'got2'   => self::describe_value( $got2 ),
				'post1'  => self::describe_value( $post1 ),
				'post2'  => self::describe_value( $post2 ),
			)
		);
	}

	private static function check_cache_blog_switch_and_global_groups( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$cache = $GLOBALS['wp_object_cache'] ?? null;
		if ( ! is_object( $cache ) ) {
			return $ctx->skip(
				'state.cache.blog-switch-local-and-global-groups',
				'Object cache instance is unavailable.',
				array()
			);
		}

		$old_blog_exists = array_key_exists( 'blog_id', $GLOBALS );
		$old_blog_id     = $GLOBALS['blog_id'] ?? null;

		try {
			$cache->multisite = true;

			$case         = $ctx->fork( 'cache-blog-switch' );
			$blog_a       = 100 + $ctx->int( 1, 250 );
			$blog_b       = $blog_a + $ctx->int( 1, 25 );
			$key          = self::key( $ctx, 'blog-switch-shared' );
			$local_group  = self::group( $ctx, 'blog-local' );
			$global_group = self::group( $ctx, 'blog-global' );
			$local_a      = self::wrapped_value( 'local-a', self::value( $case ) );
			$local_b      = self::wrapped_value( 'local-b', self::value( $case ) );
			$default_a    = self::wrapped_value( 'default-a', self::value( $case ) );
			$default_b    = self::wrapped_value( 'default-b', self::value( $case ) );
			$global_a     = self::wrapped_value( 'global-a', self::value( $case ) );
			$global_b     = self::wrapped_value( 'global-b', self::value( $case ) );

			wp_cache_add_global_groups( array( $global_group ) );

			$GLOBALS['blog_id'] = $blog_a;
			wp_cache_switch_to_blog( $blog_a );
			$prefix_a      = $cache->blog_prefix ?? null;
			$set_local_a   = wp_cache_set( $key, $local_a, $local_group );
			$set_default_a = wp_cache_set( $key, $default_a, '' );
			$set_global_a  = wp_cache_set( $key, $global_a, $global_group );

			wp_cache_switch_to_blog( (string) $blog_b );
			$prefix_b              = $cache->blog_prefix ?? null;
			$global_blog_during_b  = get_current_blog_id();

			$found_local_b_before   = null;
			$found_default_b_before = null;
			$local_b_before         = wp_cache_get( $key, $local_group, false, $found_local_b_before );
			$default_b_before       = wp_cache_get( $key, '', false, $found_default_b_before );
			$global_b_before        = wp_cache_get( $key, $global_group );
			$set_local_b            = wp_cache_set( $key, $local_b, $local_group );
			$set_default_b          = wp_cache_set( $key, $default_b, '' );
			$set_global_b           = wp_cache_set( $key, $global_b, $global_group );

			$raw_cache         = is_array( $cache->cache ?? null ) ? $cache->cache : array();
			$raw_local_group   = is_array( $raw_cache[ $local_group ] ?? null ) ? $raw_cache[ $local_group ] : array();
			$raw_default_group = is_array( $raw_cache['default'] ?? null ) ? $raw_cache['default'] : array();
			$raw_global_group  = is_array( $raw_cache[ $global_group ] ?? null ) ? $raw_cache[ $global_group ] : array();
			$local_key_a       = $blog_a . ':' . $key;
			$local_key_b       = $blog_b . ':' . $key;

			wp_cache_switch_to_blog( $blog_a );
			$global_blog_after_a = get_current_blog_id();
			$local_a_after        = wp_cache_get( $key, $local_group );
			$default_a_after      = wp_cache_get( $key, '' );
			$global_a_after       = wp_cache_get( $key, $global_group );
			$delete_global_from_a = wp_cache_delete( $key, $global_group );

			wp_cache_switch_to_blog( $blog_b );
			$global_b_after_delete = wp_cache_get( $key, $global_group );
			$local_b_after         = wp_cache_get( $key, $local_group );
			$default_b_after       = wp_cache_get( $key, '' );
			$delete_local_b        = wp_cache_delete( $key, $local_group );

			wp_cache_switch_to_blog( $blog_a );
			$local_a_after_delete_b = wp_cache_get( $key, $local_group );

			$checks = array(
				'prefixA'              => $blog_a . ':' === $prefix_a,
				'prefixBStringCast'    => $blog_b . ':' === $prefix_b,
				'directSwitchKeepsGlobalBlog' => $blog_a === $global_blog_during_b && $blog_a === $global_blog_after_a,
				'setsSucceeded'        => true === $set_local_a && true === $set_default_a && true === $set_global_a && true === $set_local_b && true === $set_default_b && true === $set_global_b,
				'localMissingOnBlogB'  => false === $local_b_before && false === $found_local_b_before,
				'defaultMissingOnBlogB' => false === $default_b_before && false === $found_default_b_before,
				'globalSharedToBlogB'  => self::same_value( $global_a, $global_b_before ),
				'rawLocalPrefixed'     => isset( $raw_local_group[ $local_key_a ], $raw_local_group[ $local_key_b ] ) && ! isset( $raw_local_group[ $key ] ),
				'rawDefaultPrefixed'   => isset( $raw_default_group[ $local_key_a ], $raw_default_group[ $local_key_b ] ) && ! isset( $raw_default_group[ $key ] ),
				'rawGlobalUnprefixed'  => isset( $raw_global_group[ $key ] ) && ! isset( $raw_global_group[ $local_key_a ], $raw_global_group[ $local_key_b ] ),
				'localARestored'       => self::same_value( $local_a, $local_a_after ),
				'defaultARestored'     => self::same_value( $default_a, $default_a_after ),
				'globalUpdatedForA'    => self::same_value( $global_b, $global_a_after ),
				'globalDeleteShared'   => true === $delete_global_from_a && false === $global_b_after_delete,
				'localBStillPresent'   => self::same_value( $local_b, $local_b_after ) && self::same_value( $default_b, $default_b_after ),
				'deleteLocalBOnly'     => true === $delete_local_b && self::same_value( $local_a, $local_a_after_delete_b ),
			);

			return $ctx->result(
				'state.cache.blog-switch-local-and-global-groups',
				! in_array( false, $checks, true ),
				array(
					'blogA'              => $blog_a,
					'blogB'              => $blog_b,
					'key'                => $key,
					'localGroup'         => $local_group,
					'globalGroup'        => $global_group,
					'checks'             => $checks,
					'globalBlogDuringB'  => $global_blog_during_b,
					'globalBlogAfterA'   => $global_blog_after_a,
					'localBeforeBlogB'   => self::describe_value( $local_b_before ),
					'defaultBeforeBlogB' => self::describe_value( $default_b_before ),
					'globalBeforeBlogB'  => self::describe_value( $global_b_before ),
					'rawLocalKeys'       => array_keys( $raw_local_group ),
					'rawDefaultKeys'     => array_keys( $raw_default_group ),
					'rawGlobalKeys'      => array_keys( $raw_global_group ),
				)
			);
		} finally {
			self::reset_runtime();
			if ( $old_blog_exists ) {
				$GLOBALS['blog_id'] = $old_blog_id;
			} else {
				unset( $GLOBALS['blog_id'] );
			}
		}
	}

	private static function check_cache_object_cloning( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key   = self::key( $ctx, 'object-clone' );
		$group = self::group( $ctx, 'objects' );
		$value = (object) array(
			'label' => 'stored',
			'count' => 1,
		);

		wp_cache_set( $key, $value, $group );
		$value->label = 'changed outside';
		$value->count = 2;

		$first_get        = wp_cache_get( $key, $group );
		$first_get_label  = $first_get->label ?? null;
		$first_get_count  = $first_get->count ?? null;
		$first_get->label = 'changed fetched';
		$first_get->count = 3;
		$second_get       = wp_cache_get( $key, $group );

		$ok = $first_get instanceof \stdClass
			&& $second_get instanceof \stdClass
			&& 'stored' === $first_get_label
			&& 1 === $first_get_count
			&& 'stored' === $second_get->label
			&& 1 === $second_get->count;

		return $ctx->result(
			'state.cache.object-values-are-top-level-cloned-on-set-and-get',
			$ok,
			array(
				'firstGet'  => self::describe_value( $first_get ),
				'firstRead' => array(
					'label' => $first_get_label,
					'count' => $first_get_count,
				),
				'secondGet' => self::describe_value( $second_get ),
			)
		);
	}

	private static function check_cache_multiple_equivalence( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$case    = $ctx->fork( 'cache-multiple' );
		$group   = self::group( $ctx, 'multi' );
		$entries = self::cache_entries( $ctx, $case, 'multi', 5 );
		$keys    = array_keys( $entries );

		$set_multi = wp_cache_set_multiple( $entries, $group );
		$get_multi = wp_cache_get_multiple( $keys, $group );
		$get_single = array();
		foreach ( $keys as $key ) {
			$get_single[ $key ] = wp_cache_get( $key, $group );
		}
		$delete_multi = wp_cache_delete_multiple( $keys, $group );
		$after_delete = wp_cache_get_multiple( $keys, $group );

		$ok = self::all_same_scalar( $set_multi, true )
			&& self::same_value( $entries, $get_multi )
			&& self::same_value( $get_single, $get_multi )
			&& self::all_same_scalar( $delete_multi, true )
			&& self::all_same_scalar( $after_delete, false );

		return $ctx->result(
			'state.cache.multi-operations-match-single-operations',
			$ok,
			array(
				'group'       => $group,
				'keys'        => implode( ',', $keys ),
				'setMulti'    => $set_multi,
				'deleteMulti' => $delete_multi,
				'afterDelete' => self::describe_value( $after_delete ),
			)
		);
	}

	private static function check_cache_add_multiple_contract( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$case    = $ctx->fork( 'cache-add-multiple' );
		$group   = self::group( $ctx, 'add-multi' );
		$entries = self::cache_entries( $ctx, $case, 'add-multi', 4 );
		$keys    = array_keys( $entries );

		$first_add  = wp_cache_add_multiple( $entries, $group );
		$second_add = wp_cache_add_multiple( $entries, $group );
		$values     = wp_cache_get_multiple( $keys, $group );

		$ok = self::all_same_scalar( $first_add, true )
			&& self::all_same_scalar( $second_add, false )
			&& self::same_value( $entries, $values );

		return $ctx->result(
			'state.cache.add-multiple-only-adds-missing-keys',
			$ok,
			array(
				'group'     => $group,
				'firstAdd'  => $first_add,
				'secondAdd' => $second_add,
				'values'    => self::describe_value( $values ),
			)
		);
	}

	private static function check_cache_addition_suspension( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$case          = $ctx->fork( 'cache-addition-suspension' );
		$group         = self::group( $ctx, 'addition-suspended' );
		$key_add       = self::key( $ctx, 'suspended-add' );
		$key_set       = self::key( $ctx, 'suspended-set' );
		$key_after     = self::key( $ctx, 'suspended-after' );
		$add_value     = self::wrapped_value( 'add', self::value( $case ) );
		$set_value     = self::wrapped_value( 'set', self::value( $case ) );
		$after_value   = self::wrapped_value( 'after', self::value( $case ) );
		$multi_entries = self::cache_entries( $ctx, $case, 'suspended-add-multi', 3 );
		$multi_keys    = array_keys( $multi_entries );

		$initial_suspended = wp_suspend_cache_addition();
		$suspended_now     = null;
		$add_suspended     = null;
		$found_add         = null;
		$stored_add        = null;
		$multi_suspended   = null;
		$stored_multi      = null;
		$set_suspended     = null;
		$stored_set        = null;
		$restored_now      = null;
		$add_after         = null;
		$stored_after      = null;

		try {
			$suspended_now   = wp_suspend_cache_addition( true );
			$add_suspended   = wp_cache_add( $key_add, $add_value, $group );
			$stored_add      = wp_cache_get( $key_add, $group, false, $found_add );
			$multi_suspended = wp_cache_add_multiple( $multi_entries, $group );
			$stored_multi    = wp_cache_get_multiple( $multi_keys, $group );
			$set_suspended   = wp_cache_set( $key_set, $set_value, $group );
			$stored_set      = wp_cache_get( $key_set, $group );
		} finally {
			$restored_now = wp_suspend_cache_addition( false );
		}

		$add_after    = wp_cache_add( $key_after, $after_value, $group );
		$stored_after = wp_cache_get( $key_after, $group );

		$ok = false === $initial_suspended
			&& true === $suspended_now
			&& false === $add_suspended
			&& false === $stored_add
			&& false === $found_add
			&& self::all_same_scalar( $multi_suspended, false )
			&& self::all_same_scalar( $stored_multi, false )
			&& true === $set_suspended
			&& self::same_value( $set_value, $stored_set )
			&& false === $restored_now
			&& false === wp_suspend_cache_addition()
			&& true === $add_after
			&& self::same_value( $after_value, $stored_after );

		return $ctx->result(
			'state.cache.suspended-addition-blocks-add-but-not-set',
			$ok,
			array(
				'group'          => $group,
				'initial'        => $initial_suspended,
				'suspendedNow'   => $suspended_now,
				'addSuspended'   => $add_suspended,
				'foundAdd'       => $found_add,
				'multiSuspended' => $multi_suspended,
				'storedMulti'    => self::describe_value( $stored_multi ),
				'setSuspended'   => $set_suspended,
				'storedSet'      => self::describe_value( $stored_set ),
				'restoredNow'    => $restored_now,
				'addAfter'       => $add_after,
			)
		);
	}

	private static function check_cache_increments( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key        = self::key( $ctx, 'counter' );
		$missing    = self::key( $ctx, 'counter-missing' );
		$group      = self::group( $ctx, 'counters' );
		$start      = $ctx->int( 0, 250 );
		$increment  = $ctx->int( 1, 50 );
		$decrement  = $ctx->int( 1, 350 );
		$after_incr = $start + $increment;
		$after_decr = max( 0, $after_incr - $decrement );

		wp_cache_set( $key, $start, $group );
		$incr         = wp_cache_incr( $key, $increment, $group );
		$decr         = wp_cache_decr( $key, $decrement, $group );
		$missing_incr = wp_cache_incr( $missing, 1, $group );
		wp_cache_set( $key . '_non_numeric', 'not-a-number', $group );
		$non_numeric = wp_cache_incr( $key . '_non_numeric', $increment, $group );

		$ok = $after_incr === $incr
			&& $after_decr === $decr
			&& false === $missing_incr
			&& $increment === $non_numeric;

		return $ctx->result(
			'state.cache.increments-decrements-and-missing-counters',
			$ok,
			array(
				'start'         => $start,
				'increment'     => $increment,
				'decrement'     => $decrement,
				'incr'          => $incr,
				'decr'          => $decr,
				'missingIncr'   => $missing_incr,
				'nonNumericInc' => $non_numeric,
			)
		);
	}

	private static function check_cache_flushes_and_groups( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key     = self::key( $ctx, 'flush' );
		$group_a = self::group( $ctx, 'flush-a' );
		$group_b = self::group( $ctx, 'flush-b' );
		$value_a = array( 'group' => 'a' );
		$value_b = array( 'group' => 'b' );

		wp_cache_add_non_persistent_groups( array( $group_a ) );
		wp_cache_set( $key, $value_a, $group_a );
		wp_cache_set( $key, $value_b, $group_b );

		$flush_group_supported = wp_cache_supports( 'flush_group' );
		$flush_group           = $flush_group_supported ? wp_cache_flush_group( $group_a ) : null;
		$after_group_a         = wp_cache_get( $key, $group_a );
		$after_group_b         = wp_cache_get( $key, $group_b );

		$runtime_key             = self::key( $ctx, 'flush-runtime' );
		$flush_runtime_supported = wp_cache_supports( 'flush_runtime' );
		wp_cache_set( $runtime_key, 'runtime', $group_b );
		$flush_runtime = $flush_runtime_supported ? wp_cache_flush_runtime() : null;
		$after_runtime = wp_cache_get( $runtime_key, $group_b );

		$ok = true === $flush_group_supported
			&& true === $flush_group
			&& false === $after_group_a
			&& self::same_value( $value_b, $after_group_b )
			&& true === $flush_runtime_supported
			&& true === $flush_runtime
			&& false === $after_runtime;

		return $ctx->result(
			'state.cache.flush-group-runtime-and-non-persistent-groups',
			$ok,
			array(
				'flushGroupSupported'   => $flush_group_supported,
				'flushGroup'            => $flush_group,
				'afterGroupA'           => self::describe_value( $after_group_a ),
				'afterGroupB'           => self::describe_value( $after_group_b ),
				'flushRuntimeSupported' => $flush_runtime_supported,
				'flushRuntime'          => $flush_runtime,
				'afterRuntime'          => self::describe_value( $after_runtime ),
			)
		);
	}

	private static function check_cache_last_changed( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$group       = self::group( $ctx, 'last-changed' );
		$first       = wp_cache_get_last_changed( $group );
		$cached      = wp_cache_get( 'last_changed', $group );
		$second      = wp_cache_get_last_changed( $group );
		$updated     = wp_cache_set_last_changed( $group );
		$updated_get = wp_cache_get( 'last_changed', $group );

		$ok = is_string( $first )
			&& '' !== $first
			&& $first === $cached
			&& $first === $second
			&& is_string( $updated )
			&& '' !== $updated
			&& $updated === $updated_get;

		return $ctx->result(
			'state.cache.last-changed-is-stored-and-reused',
			$ok,
			array(
				'first'      => $first,
				'cached'     => $cached,
				'second'     => $second,
				'updated'    => $updated,
				'updatedGet' => $updated_get,
			)
		);
	}

	private static function check_option_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$pre_key     = self::key( $ctx, 'pre-option' );
		$default_key = self::key( $ctx, 'default-option' );
		$option_key  = self::key( $ctx, 'option-filter' );
		$pre_value   = array( 'source' => 'pre_option', 'payload' => self::value( $ctx->fork( 'pre-option-value' ) ) );
		$default     = array( 'source' => 'default_option', 'payload' => self::value( $ctx->fork( 'default-option-value' ) ) );
		$filtered    = array( 'source' => 'option_filter', 'payload' => self::value( $ctx->fork( 'option-filter-value' ) ) );
		$stored      = array( 'source' => 'stored', 'payload' => self::value( $ctx->fork( 'stored-option-value' ) ) );

		add_filter(
			"pre_option_{$pre_key}",
			static function () use ( $pre_value ) {
				return $pre_value;
			},
			10,
			3
		);
		add_filter(
			"default_option_{$default_key}",
			static function () use ( $default ) {
				return $default;
			},
			10,
			3
		);
		add_option( $option_key, $stored, '', false );
		add_filter(
			"option_{$option_key}",
			static function () use ( $filtered ) {
				return $filtered;
			},
			10,
			2
		);

		$pre_got     = get_option( $pre_key, 'fallback' );
		$default_got = get_option( $default_key, 'fallback' );
		$option_got  = get_option( $option_key, 'fallback' );
		$store       = self::option_store();

		$ok = self::same_value( $pre_value, $pre_got )
			&& self::same_value( $default, $default_got )
			&& self::same_value( $filtered, $option_got )
			&& ! isset( $store[ $pre_key ] )
			&& ! isset( $store[ $default_key ] )
			&& isset( $store[ $option_key ] );

		return $ctx->result(
			'state.options.pre-default-and-option-filters-short-circuit-without-db',
			$ok,
			array(
				'preKeyStored'     => isset( $store[ $pre_key ] ),
				'defaultKeyStored' => isset( $store[ $default_key ] ),
				'optionKeyStored'  => isset( $store[ $option_key ] ),
				'preGot'           => self::describe_value( $pre_got ),
				'defaultGot'       => self::describe_value( $default_got ),
				'optionGot'        => self::describe_value( $option_got ),
			)
		);
	}

	private static function check_option_crud( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key       = self::key( $ctx, 'crud-option' );
		$other_key = self::key( $ctx, 'crud-other' );
		$value1    = self::wrapped_value( 'option-first', self::value( $ctx->fork( 'option-first' ) ) );
		$value2    = self::wrapped_value( 'option-second', self::value( $ctx->fork( 'option-second' ) ) );
		$other     = self::wrapped_value( 'option-other', self::value( $ctx->fork( 'option-other' ) ) );

		$missing_before = get_option( $key, 'fallback' );
		$add            = add_option( $key, $value1, '', false );
		$duplicate_add  = add_option( $key, $value2, '', false );
		$get_after_add  = get_option( $key, 'fallback' );
		$add_other      = add_option( $other_key, $other, '', false );
		$update         = update_option( $key, $value2, false );
		$get_after_upd  = get_option( $key, 'fallback' );
		$same_update    = update_option( $key, $value2, false );
		$get_other      = get_option( $other_key, 'fallback' );
		$delete         = delete_option( $key );
		$get_deleted    = get_option( $key, 'fallback' );
		$delete_again   = delete_option( $key );
		$store          = self::option_store();

		$ok = 'fallback' === $missing_before
			&& true === $add
			&& false === $duplicate_add
			&& self::same_value( $value1, $get_after_add )
			&& true === $add_other
			&& true === $update
			&& self::same_value( $value2, $get_after_upd )
			&& false === $same_update
			&& self::same_value( $other, $get_other )
			&& true === $delete
			&& 'fallback' === $get_deleted
			&& false === $delete_again
			&& ! isset( $store[ $key ] )
			&& isset( $store[ $other_key ] );

		return $ctx->result(
			'state.options.add-update-delete-isolated-in-stub-store',
			$ok,
			array(
				'key'           => $key,
				'otherKey'      => $other_key,
				'add'           => $add,
				'duplicateAdd'  => $duplicate_add,
				'update'        => $update,
				'sameUpdate'    => $same_update,
				'delete'        => $delete,
				'deleteAgain'   => $delete_again,
				'getDeleted'    => self::describe_value( $get_deleted ),
				'otherInStore'  => isset( $store[ $other_key ] ),
				'deletedInStore' => isset( $store[ $key ] ),
			)
		);
	}

	private static function check_option_cache_priming( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key1    = self::key( $ctx, 'prime-one' );
		$key2    = self::key( $ctx, 'prime-two' );
		$missing = self::key( $ctx, 'prime-missing' );
		$value1  = self::wrapped_value( 'prime-one', array( 'stable' => 'one', 'truthy' => 1 ) );
		$value2  = self::wrapped_value( 'prime-two', array( 'stable' => 'two', 'truthy' => true ) );

		add_option( $key1, $value1, '', false );
		add_option( $key2, $value2, '', false );
		wp_cache_flush();

		wp_prime_option_caches( array( $key1, $key2, $missing ) );
		$raw_cache = wp_cache_get_multiple( array( $key1, $key2 ), 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$got1       = get_option( $key1, 'fallback' );
		$got2       = get_option( $key2, 'fallback' );
		$got_missing = get_option( $missing, 'fallback' );

		$ok = self::same_value( $value1, $got1 )
			&& self::same_value( $value2, $got2 )
			&& 'fallback' === $got_missing
			&& is_array( $notoptions )
			&& isset( $notoptions[ $missing ] )
			&& isset( $raw_cache[ $key1 ], $raw_cache[ $key2 ] );

		return $ctx->result(
			'state.options.prime-caches-loads-found-and-marks-missing',
			$ok,
			array(
				'key1Cached'      => isset( $raw_cache[ $key1 ] ),
				'key2Cached'      => isset( $raw_cache[ $key2 ] ),
				'missingNotoption' => is_array( $notoptions ) && isset( $notoptions[ $missing ] ),
				'got1'            => self::describe_value( $got1 ),
				'got2'            => self::describe_value( $got2 ),
				'gotMissing'      => self::describe_value( $got_missing ),
			)
		);
	}

	private static function check_transient_crud_and_expiration( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$name     = self::key( $ctx, 'transient' );
		$expiring = self::key( $ctx, 'transient-expiring' );
		$value    = self::wrapped_value( 'transient', self::value( $ctx->fork( 'transient-value' ) ) );
		$expired  = self::wrapped_value( 'expired-transient', self::value( $ctx->fork( 'expired-transient-value' ) ) );

		$set          = set_transient( $name, $value, 0 );
		$got          = get_transient( $name );
		$delete       = delete_transient( $name );
		$after_delete = get_transient( $name );
		$delete_again = delete_transient( $name );

		$set_expiring = set_transient( $expiring, $expired, 60 );
		$timeout_key  = '_transient_timeout_' . $expiring;
		$value_key    = '_transient_' . $expiring;
		$timeout_upd  = update_option( $timeout_key, time() - 1, false );
		$after_expire = get_transient( $expiring );
		$value_after  = get_option( $value_key, 'missing' );
		$time_after   = get_option( $timeout_key, 'missing' );

		$ok = true === $set
			&& self::same_value( $value, $got )
			&& true === $delete
			&& false === $after_delete
			&& false === $delete_again
			&& true === $set_expiring
			&& true === $timeout_upd
			&& false === $after_expire
			&& 'missing' === $value_after
			&& 'missing' === $time_after;

		return $ctx->result(
			'state.transients.set-get-delete-and-expire-through-options',
			$ok,
			array(
				'name'          => $name,
				'expiring'      => $expiring,
				'set'           => $set,
				'delete'        => $delete,
				'deleteAgain'   => $delete_again,
				'setExpiring'   => $set_expiring,
				'timeoutUpdate' => $timeout_upd,
				'afterExpire'   => self::describe_value( $after_expire ),
				'valueAfter'    => self::describe_value( $value_after ),
				'timeAfter'     => self::describe_value( $time_after ),
			)
		);
	}

	private static function check_transient_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$pre_name = self::key( $ctx, 'pre-transient' );
		$set_name = self::key( $ctx, 'set-transient-filter' );
		$pre_value = self::wrapped_value( 'pre-transient', self::value( $ctx->fork( 'pre-transient-value' ) ) );
		$set_value = self::wrapped_value( 'set-transient', self::value( $ctx->fork( 'set-transient-value' ) ) );

		add_filter(
			"pre_transient_{$pre_name}",
			static function () use ( $pre_value ) {
				return $pre_value;
			},
			10,
			2
		);
		add_filter(
			"pre_set_transient_{$set_name}",
			static function () use ( $set_value ) {
				return $set_value;
			},
			10,
			3
		);
		add_filter(
			"expiration_of_transient_{$set_name}",
			static function () {
				return 0;
			},
			10,
			3
		);

		$pre_got = get_transient( $pre_name );
		$set     = set_transient( $set_name, array( 'raw' => 'ignored' ), 99 );
		$set_got = get_transient( $set_name );
		$timeout = get_option( '_transient_timeout_' . $set_name, 'missing' );
		$store   = self::option_store();

		$ok = self::same_value( $pre_value, $pre_got )
			&& true === $set
			&& self::same_value( $set_value, $set_got )
			&& 'missing' === $timeout
			&& ! isset( $store[ '_transient_' . $pre_name ] )
			&& isset( $store[ '_transient_' . $set_name ] );

		return $ctx->result(
			'state.transients.pre-set-and-expiration-filters-apply-without-db',
			$ok,
			array(
				'preStored' => isset( $store[ '_transient_' . $pre_name ] ),
				'setStored' => isset( $store[ '_transient_' . $set_name ] ),
				'timeout'   => self::describe_value( $timeout ),
				'preGot'    => self::describe_value( $pre_got ),
				'setGot'    => self::describe_value( $set_got ),
			)
		);
	}

	private static function check_site_transient_cache_branch( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$name             = self::key( $ctx, 'site-transient' );
		$pre_name         = self::key( $ctx, 'pre-site-transient' );
		$filtered_name    = self::key( $ctx, 'filtered-site-transient' );
		$value            = self::wrapped_value( 'site-transient', self::value( $ctx->fork( 'site-transient-value' ) ) );
		$pre_value        = self::wrapped_value( 'pre-site-transient', self::value( $ctx->fork( 'pre-site-transient-value' ) ) );
		$set_value        = self::wrapped_value( 'set-site-transient', self::value( $ctx->fork( 'set-site-transient-value' ) ) );
		$read_value       = self::wrapped_value( 'read-site-transient', self::value( $ctx->fork( 'read-site-transient-value' ) ) );
		$pre_set_calls    = array();
		$expiration_calls = array();
		$pre_filter       = static function () use ( $pre_value ) {
			return $pre_value;
		};
		$pre_set_filter   = static function ( $raw, string $transient ) use ( $set_value, &$pre_set_calls ) {
			$pre_set_calls[] = array(
				'raw'       => $raw,
				'transient' => $transient,
			);
			return $set_value;
		};
		$expiration_filter = static function ( int $expiration, $filtered_value, string $transient ) use ( &$expiration_calls ): int {
			$expiration_calls[] = array(
				'expiration' => $expiration,
				'value'      => $filtered_value,
				'transient'  => $transient,
			);
			return 17;
		};
		$read_filter       = static function () use ( $read_value ) {
			return $read_value;
		};
		$previous_ext      = wp_using_ext_object_cache( true );

		try {
			$missing_before = get_site_transient( $name );
			$set            = set_site_transient( $name, $value, 60 );
			$got            = get_site_transient( $name );
			$found_cached   = null;
			$cached         = wp_cache_get( $name, 'site-transient', false, $found_cached );
			$delete         = delete_site_transient( $name );
			$after_delete   = get_site_transient( $name );

			add_filter( "pre_site_transient_{$pre_name}", $pre_filter, 10, 2 );
			$pre_got    = get_site_transient( $pre_name );
			$pre_cached = wp_cache_get( $pre_name, 'site-transient' );

			add_filter( "pre_set_site_transient_{$filtered_name}", $pre_set_filter, 10, 2 );
			add_filter( "expiration_of_site_transient_{$filtered_name}", $expiration_filter, 10, 3 );
			$set_filtered = set_site_transient( $filtered_name, array( 'raw' => 'ignored' ), 99 );
			$got_filtered = get_site_transient( $filtered_name );
			$cached_filtered = wp_cache_get( $filtered_name, 'site-transient' );

			add_filter( "site_transient_{$filtered_name}", $read_filter, 10, 2 );
			$read_filtered = get_site_transient( $filtered_name );
		} finally {
			remove_filter( "pre_site_transient_{$pre_name}", $pre_filter, 10 );
			remove_filter( "pre_set_site_transient_{$filtered_name}", $pre_set_filter, 10 );
			remove_filter( "expiration_of_site_transient_{$filtered_name}", $expiration_filter, 10 );
			remove_filter( "site_transient_{$filtered_name}", $read_filter, 10 );
			wp_using_ext_object_cache( $previous_ext );
		}

		$store = self::option_store();
		$ok    = false === $missing_before
			&& true === $set
			&& self::same_value( $value, $got )
			&& true === $found_cached
			&& self::same_value( $value, $cached )
			&& true === $delete
			&& false === $after_delete
			&& self::same_value( $pre_value, $pre_got )
			&& false === $pre_cached
			&& true === $set_filtered
			&& self::same_value( $set_value, $got_filtered )
			&& self::same_value( $set_value, $cached_filtered )
			&& self::same_value( $read_value, $read_filtered )
			&& 1 === count( $pre_set_calls )
			&& array( 'raw' => array( 'raw' => 'ignored' ), 'transient' => $filtered_name ) === $pre_set_calls[0]
			&& 1 === count( $expiration_calls )
			&& 99 === $expiration_calls[0]['expiration']
			&& self::same_value( $set_value, $expiration_calls[0]['value'] )
			&& $filtered_name === $expiration_calls[0]['transient']
			&& ! isset( $store[ '_site_transient_' . $name ] )
			&& ! isset( $store[ '_site_transient_timeout_' . $name ] )
			&& ! isset( $store[ '_site_transient_' . $filtered_name ] );

		return $ctx->result(
			'state.site-transients.external-cache-branch-and-filters',
			$ok,
			array(
				'name'            => $name,
				'filteredName'    => $filtered_name,
				'set'             => $set,
				'delete'          => $delete,
				'foundCached'     => $found_cached,
				'preCached'       => self::describe_value( $pre_cached ),
				'preSetCalls'     => self::describe_value( $pre_set_calls ),
				'expirationCalls' => self::describe_value( $expiration_calls ),
				'readFiltered'    => self::describe_value( $read_filtered ),
				'optionKeys'      => implode( ',', array_keys( $store ) ),
			)
		);
	}

	private static function check_site_transient_option_branch( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$name          = self::key( $ctx, 'site-transient-option' );
		$zero_name     = self::key( $ctx, 'site-transient-option-zero' );
		$expired_name  = self::key( $ctx, 'site-transient-option-expired' );
		$pre_name      = self::key( $ctx, 'pre-site-transient-option' );
		$filtered_name = self::key( $ctx, 'filtered-site-transient-option' );

		$value         = self::wrapped_value( 'site-transient-option', self::value( $ctx->fork( 'site-transient-option-value' ) ) );
		$updated_value = self::wrapped_value( 'site-transient-option-updated', self::value( $ctx->fork( 'site-transient-option-updated-value' ) ) );
		$zero_value    = self::wrapped_value( 'site-transient-option-zero', self::value( $ctx->fork( 'site-transient-option-zero-value' ) ) );
		$expired_value = self::wrapped_value( 'site-transient-option-expired', self::value( $ctx->fork( 'site-transient-option-expired-value' ) ) );
		$pre_value     = self::wrapped_value( 'pre-site-transient-option', self::value( $ctx->fork( 'pre-site-transient-option-value' ) ) );
		$set_value     = self::wrapped_value( 'set-site-transient-option', self::value( $ctx->fork( 'set-site-transient-option-value' ) ) );
		$read_value    = self::wrapped_value( 'read-site-transient-option', self::value( $ctx->fork( 'read-site-transient-option-value' ) ) );

		$value_key            = '_site_transient_' . $name;
		$timeout_key          = '_site_transient_timeout_' . $name;
		$zero_value_key       = '_site_transient_' . $zero_name;
		$zero_timeout_key     = '_site_transient_timeout_' . $zero_name;
		$expired_value_key    = '_site_transient_' . $expired_name;
		$expired_timeout_key  = '_site_transient_timeout_' . $expired_name;
		$pre_value_key        = '_site_transient_' . $pre_name;
		$filtered_value_key   = '_site_transient_' . $filtered_name;
		$filtered_timeout_key = '_site_transient_timeout_' . $filtered_name;

		$pre_calls        = array();
		$pre_set_calls    = array();
		$expiration_calls = array();
		$read_calls       = array();

		$pre_filter        = static function ( $pre, string $transient ) use ( $pre_value, &$pre_calls ) {
			$pre_calls[] = array(
				'pre'       => $pre,
				'transient' => $transient,
			);
			return $pre_value;
		};
		$pre_set_filter    = static function ( $raw, string $transient ) use ( $set_value, &$pre_set_calls ) {
			$pre_set_calls[] = array(
				'raw'       => $raw,
				'transient' => $transient,
			);
			return $set_value;
		};
		$expiration_filter = static function ( int $expiration, $filtered_value, string $transient ) use ( &$expiration_calls ): int {
			$expiration_calls[] = array(
				'expiration' => $expiration,
				'value'      => $filtered_value,
				'transient'  => $transient,
			);
			return 0;
		};
		$read_filter       = static function ( $current, string $transient ) use ( $read_value, &$read_calls ) {
			$read_calls[] = array(
				'value'     => $current,
				'transient' => $transient,
			);
			return $read_value;
		};

		$original_ext = wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );
		$previous_ext = wp_using_ext_object_cache( false );

		$missing_before          = null;
		$set                     = null;
		$got                     = null;
		$stored_value            = null;
		$stored_timeout          = null;
		$update_set              = null;
		$updated_got             = null;
		$updated_stored_value    = null;
		$updated_stored_timeout  = null;
		$found_site_cache        = null;
		$site_cache              = null;
		$delete                  = null;
		$delete_again            = null;
		$after_delete            = null;
		$zero_set                = null;
		$zero_got                = null;
		$zero_stored_value       = null;
		$zero_delete             = null;
		$expired_set             = null;
		$expired_stored_value    = null;
		$expired_timeout         = null;
		$timeout_update          = null;
		$after_expire            = null;
		$pre_got                 = null;
		$filtered_set            = null;
		$filtered_got            = null;
		$filtered_stored_value   = null;
		$read_filtered           = null;
		$ext_forced              = null;
		$set_started             = 0;
		$set_finished            = 0;
		$update_started          = 0;
		$update_finished         = 0;
		$expired_set_started     = 0;
		$expired_set_finished    = 0;
		$hooks_removed            = false;
		$ext_restored_to_previous = false;
		$ext_restored_to_original = false;
		$store_after_set         = array();
		$store_after_update      = array();
		$store_after_delete      = array();
		$store_after_zero        = array();
		$store_after_zero_delete = array();
		$store_after_expired_set = array();
		$store_after_expire      = array();
		$store_after_pre         = array();
		$store_after_filtered    = array();

		try {
			$ext_forced     = wp_using_ext_object_cache();
			$missing_before = get_site_transient( $name );
			$set_started    = time();
			$set            = set_site_transient( $name, $value, 60 );
			$set_finished   = time();
			$got             = get_site_transient( $name );
			$store_after_set = self::option_store();
			$stored_value    = isset( $store_after_set[ $value_key ] )
				? maybe_unserialize( $store_after_set[ $value_key ]['option_value'] )
				: null;
			$stored_timeout  = $store_after_set[ $timeout_key ]['option_value'] ?? null;
			$site_cache      = wp_cache_get( $name, 'site-transient', false, $found_site_cache );

			$update_started         = time();
			$update_set             = set_site_transient( $name, $updated_value, 120 );
			$update_finished        = time();
			$updated_got            = get_site_transient( $name );
			$store_after_update     = self::option_store();
			$updated_stored_value   = isset( $store_after_update[ $value_key ] )
				? maybe_unserialize( $store_after_update[ $value_key ]['option_value'] )
				: null;
			$updated_stored_timeout = $store_after_update[ $timeout_key ]['option_value'] ?? null;

			$delete             = delete_site_transient( $name );
			$after_delete       = get_site_transient( $name );
			$delete_again       = delete_site_transient( $name );
			$store_after_delete = self::option_store();

			$zero_set          = set_site_transient( $zero_name, $zero_value, 0 );
			$zero_got          = get_site_transient( $zero_name );
			$store_after_zero  = self::option_store();
			$zero_stored_value = isset( $store_after_zero[ $zero_value_key ] )
				? maybe_unserialize( $store_after_zero[ $zero_value_key ]['option_value'] )
				: null;
			$zero_delete       = delete_site_transient( $zero_name );
			$store_after_zero_delete = self::option_store();

			$expired_set_started  = time();
			$expired_set          = set_site_transient( $expired_name, $expired_value, 60 );
			$expired_set_finished = time();
			$store_after_expired_set = self::option_store();
			$expired_stored_value = isset( $store_after_expired_set[ $expired_value_key ] )
				? maybe_unserialize( $store_after_expired_set[ $expired_value_key ]['option_value'] )
				: null;
			$expired_timeout      = $store_after_expired_set[ $expired_timeout_key ]['option_value'] ?? null;
			$timeout_update       = update_site_option( $expired_timeout_key, time() - 1 );
			$after_expire         = get_site_transient( $expired_name );
			$store_after_expire   = self::option_store();

			add_filter( "pre_site_transient_{$pre_name}", $pre_filter, 10, 2 );
			$pre_got         = get_site_transient( $pre_name );
			$store_after_pre = self::option_store();

			add_filter( "pre_set_site_transient_{$filtered_name}", $pre_set_filter, 10, 2 );
			add_filter( "expiration_of_site_transient_{$filtered_name}", $expiration_filter, 10, 3 );
			$filtered_set          = set_site_transient( $filtered_name, array( 'raw' => 'ignored' ), 99 );
			$filtered_got          = get_site_transient( $filtered_name );
			$store_after_filtered  = self::option_store();
			$filtered_stored_value = isset( $store_after_filtered[ $filtered_value_key ] )
				? maybe_unserialize( $store_after_filtered[ $filtered_value_key ]['option_value'] )
				: null;

			add_filter( "site_transient_{$filtered_name}", $read_filter, 10, 2 );
			$read_filtered = get_site_transient( $filtered_name );
		} finally {
			remove_filter( "pre_site_transient_{$pre_name}", $pre_filter, 10 );
			remove_filter( "pre_set_site_transient_{$filtered_name}", $pre_set_filter, 10 );
			remove_filter( "expiration_of_site_transient_{$filtered_name}", $expiration_filter, 10 );
			remove_filter( "site_transient_{$filtered_name}", $read_filter, 10 );
			$hooks_removed = false === has_filter( "pre_site_transient_{$pre_name}", $pre_filter )
				&& false === has_filter( "pre_set_site_transient_{$filtered_name}", $pre_set_filter )
				&& false === has_filter( "expiration_of_site_transient_{$filtered_name}", $expiration_filter )
				&& false === has_filter( "site_transient_{$filtered_name}", $read_filter );
			wp_using_ext_object_cache( $previous_ext );
			$ext_restored_to_previous = wp_using_ext_object_cache() === $previous_ext;
			wp_using_ext_object_cache( $original_ext );
			$ext_restored_to_original = wp_using_ext_object_cache() === $original_ext;
		}

		$timeout_created = isset( $store_after_set[ $timeout_key ] )
			&& is_numeric( $stored_timeout )
			&& (int) $stored_timeout >= $set_started + 60
			&& (int) $stored_timeout <= $set_finished + 60;
		$updated_timeout_created = isset( $store_after_update[ $timeout_key ] )
			&& is_numeric( $updated_stored_timeout )
			&& (int) $updated_stored_timeout >= $update_started + 120
			&& (int) $updated_stored_timeout <= $update_finished + 120
			&& (int) $updated_stored_timeout > (int) $stored_timeout;
		$expired_timeout_created = isset( $store_after_expired_set[ $expired_timeout_key ] )
			&& is_numeric( $expired_timeout )
			&& (int) $expired_timeout >= $expired_set_started + 60
			&& (int) $expired_timeout <= $expired_set_finished + 60;

		$ok = false === $original_ext
			&& true === $previous_ext
			&& false === $ext_forced
			&& false === $missing_before
			&& true === $set
			&& self::same_value( $value, $got )
			&& isset( $store_after_set[ $value_key ] )
			&& self::same_value( $value, $stored_value )
			&& $timeout_created
			&& true === $update_set
			&& self::same_value( $updated_value, $updated_got )
			&& isset( $store_after_update[ $value_key ] )
			&& self::same_value( $updated_value, $updated_stored_value )
			&& $updated_timeout_created
			&& false === $found_site_cache
			&& false === $site_cache
			&& true === $delete
			&& false === $after_delete
			&& false === $delete_again
			&& ! isset( $store_after_delete[ $value_key ] )
			&& ! isset( $store_after_delete[ $timeout_key ] )
			&& true === $zero_set
			&& self::same_value( $zero_value, $zero_got )
			&& isset( $store_after_zero[ $zero_value_key ] )
			&& self::same_value( $zero_value, $zero_stored_value )
			&& ! isset( $store_after_zero[ $zero_timeout_key ] )
			&& true === $zero_delete
			&& ! isset( $store_after_zero_delete[ $zero_value_key ] )
			&& ! isset( $store_after_zero_delete[ $zero_timeout_key ] )
			&& true === $expired_set
			&& self::same_value( $expired_value, $expired_stored_value )
			&& $expired_timeout_created
			&& true === $timeout_update
			&& false === $after_expire
			&& ! isset( $store_after_expire[ $expired_value_key ] )
			&& ! isset( $store_after_expire[ $expired_timeout_key ] )
			&& self::same_value( $pre_value, $pre_got )
			&& 1 === count( $pre_calls )
			&& array( 'pre' => false, 'transient' => $pre_name ) === $pre_calls[0]
			&& ! isset( $store_after_pre[ $pre_value_key ] )
			&& true === $filtered_set
			&& self::same_value( $set_value, $filtered_got )
			&& self::same_value( $set_value, $filtered_stored_value )
			&& ! isset( $store_after_filtered[ $filtered_timeout_key ] )
			&& 1 === count( $pre_set_calls )
			&& array( 'raw' => array( 'raw' => 'ignored' ), 'transient' => $filtered_name ) === $pre_set_calls[0]
			&& 1 === count( $expiration_calls )
			&& 99 === $expiration_calls[0]['expiration']
			&& self::same_value( $set_value, $expiration_calls[0]['value'] )
			&& $filtered_name === $expiration_calls[0]['transient']
			&& self::same_value( $read_value, $read_filtered )
			&& 1 === count( $read_calls )
			&& self::same_value( $set_value, $read_calls[0]['value'] )
			&& $filtered_name === $read_calls[0]['transient']
			&& $hooks_removed
			&& $ext_restored_to_previous
			&& $ext_restored_to_original;

		return $ctx->result(
			'state.site-transients.option-branch-uses-options-and-filters',
			$ok,
			array(
				'name'                  => $name,
				'zeroName'              => $zero_name,
				'expiredName'           => $expired_name,
				'filteredName'          => $filtered_name,
				'set'                   => $set,
				'delete'                => $delete,
				'deleteAgain'           => $delete_again,
				'timeoutCreated'        => $timeout_created,
				'storedTimeout'         => self::describe_value( $stored_timeout ),
				'updateSet'             => $update_set,
				'updatedTimeoutCreated' => $updated_timeout_created,
				'updatedStoredTimeout'  => self::describe_value( $updated_stored_timeout ),
				'zeroTimeoutStored'     => isset( $store_after_zero[ $zero_timeout_key ] ),
				'expiredTimeoutCreated' => $expired_timeout_created,
				'timeoutUpdate'         => $timeout_update,
				'afterExpire'           => self::describe_value( $after_expire ),
				'preCalls'              => self::describe_value( $pre_calls ),
				'preSetCalls'           => self::describe_value( $pre_set_calls ),
				'expirationCalls'       => self::describe_value( $expiration_calls ),
				'readCalls'             => self::describe_value( $read_calls ),
				'hooksRemoved'          => $hooks_removed,
				'extRestoredPrevious'   => $ext_restored_to_previous,
				'extRestoredOriginal'   => $ext_restored_to_original,
				'optionKeysAfterFilter' => implode( ',', array_keys( $store_after_filtered ) ),
			)
		);
	}

	private static function check_serialization_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$cases = self::value_cases( $ctx );
		$failures = array();
		foreach ( $cases as $index => $value ) {
			$maybe_serialized = maybe_serialize( $value );
			$round_trip       = maybe_unserialize( $maybe_serialized );
			$native_serialized = serialize( $value );
			$double_round     = maybe_unserialize( maybe_serialize( $native_serialized ) );
			$string_expected  = is_string( $value );

			if ( ! self::same_value( $value, $round_trip ) ) {
				$failures[] = array( 'case' => $index, 'check' => 'maybe round trip' );
			}
			if ( ! is_serialized( $native_serialized, true ) ) {
				$failures[] = array( 'case' => $index, 'check' => 'native serialized strict' );
			}
			if ( $string_expected !== is_serialized_string( $native_serialized ) ) {
				$failures[] = array( 'case' => $index, 'check' => 'serialized string classifier' );
			}
			if ( $native_serialized !== $double_round ) {
				$failures[] = array( 'case' => $index, 'check' => 'double serialization preserves serialized string' );
			}
			if ( is_string( $value ) && ! is_serialized( $value, true ) && $value !== maybe_unserialize( $value ) ) {
				$failures[] = array( 'case' => $index, 'check' => 'nonserialized string unchanged' );
			}
		}

		$loose_serialized = serialize( array( 'tail' => true ) ) . ' trailing-bytes';
		if ( is_serialized( $loose_serialized, true ) || ! is_serialized( $loose_serialized, false ) ) {
			$failures[] = array( 'case' => 'loose', 'check' => 'strict rejects and loose accepts trailing bytes' );
		}

		return $ctx->result(
			'state.values.serialization-round-trips-and-classifiers',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_json_encoding( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$cases = self::value_cases( $ctx );
		$failures = array();
		foreach ( $cases as $index => $value ) {
			$json = wp_json_encode( $value );
			if ( ! is_string( $json ) ) {
				$failures[] = array( 'case' => $index, 'check' => 'json string result', 'json' => $json );
				continue;
			}

			json_decode( $json, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$failures[] = array(
					'case'  => $index,
					'check' => 'json decodes cleanly',
					'error' => json_last_error_msg(),
				);
			}
		}

		return $ctx->result(
			'state.values.wp-json-encode-produces-valid-json-for-bounded-values',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_deep_mapping_and_parse_args( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$object        = (object) array( 'leaf' => false );
		$input         = array(
			'a'      => 1,
			'nested' => array( 'b' => 'two', 'c' => null ),
			'object' => $object,
		);
		$mapped        = map_deep(
			$input,
			static function ( $value ): string {
				return 'leaf:' . gettype( $value ) . ':' . ( is_scalar( $value ) ? (string) $value : 'null' );
			}
		);
		$parsed_string = wp_parse_args( 'a=override&b=two&nested%5Bx%5D=1', array( 'a' => 'default', 'c' => 'fallback' ) );
		$parsed_object = wp_parse_args( (object) array( 'mode' => 'object', 'n' => 3 ), array( 'mode' => 'default', 'extra' => true ) );

		$ok = array(
			'a'      => 'leaf:integer:1',
			'nested' => array(
				'b' => 'leaf:string:two',
				'c' => 'leaf:NULL:null',
			),
			'object' => (object) array( 'leaf' => 'leaf:boolean:' ),
		) == $mapped
			&& array(
				'a'      => 'override',
				'c'      => 'fallback',
				'b'      => 'two',
				'nested' => array( 'x' => '1' ),
			) === $parsed_string
			&& array(
				'mode'  => 'object',
				'extra' => true,
				'n'     => 3,
			) === $parsed_object;

		return $ctx->result(
			'state.values.map-deep-and-parse-args-keep-expected-structure',
			$ok,
			array(
				'mapped'       => self::describe_value( $mapped ),
				'parsedString' => self::describe_value( $parsed_string ),
				'parsedObject' => self::describe_value( $parsed_object ),
			)
		);
	}

	private static function reset_runtime(): void {
		if ( function_exists( 'wp_using_ext_object_cache' ) ) {
			wp_using_ext_object_cache( false );
		}
		if ( function_exists( 'wp_installing' ) ) {
			wp_installing( false );
		}
		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( false );
		}

		wp_cache_init();
		$GLOBALS['wpdb'] = new \Component_Fuzz_WPDB_Stub(
			array(
				'component_fuzz_autoload_anchor' => array(
					'option_value' => '1',
					'autoload'     => 'on',
				),
				'blog_charset' => array(
					'option_value' => 'UTF-8',
					'autoload'     => 'on',
				),
			)
		);
		wp_cache_flush();
	}

	private static function snapshot_globals(): array {
		$snapshot = array(
			'cache_addition_suspended' => function_exists( 'wp_suspend_cache_addition' ) ? wp_suspend_cache_addition() : null,
			'installing'               => function_exists( 'wp_installing' ) ? wp_installing() : null,
			'globals'                  => array(),
		);

		foreach ( array( 'wp_object_cache', 'wpdb', 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', '_wp_using_ext_object_cache' ) as $name ) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		if ( function_exists( 'wp_suspend_cache_addition' ) && is_bool( $snapshot['cache_addition_suspended'] ) ) {
			wp_suspend_cache_addition( $snapshot['cache_addition_suspended'] );
		}
		if ( function_exists( 'wp_installing' ) && is_bool( $snapshot['installing'] ) ) {
			wp_installing( $snapshot['installing'] );
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function option_store(): array {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return array();
	}

	private static function value_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			null,
			true,
			false,
			0,
			-17,
			42.125,
			'',
			'plain text',
			"unicode-\xC3\xA9-\xE2\x98\x83",
			"invalid-\x80\xFF-bytes",
			serialize( array( 'already' => 'serialized' ) ),
			array( 'null' => null, 'bool' => false, 'list' => array( 1, 'two', "bad\x80" ) ),
			(object) array( 'name' => 'object-like', 'nested' => array( 'x' => 1 ) ),
		);

		for ( $i = 0; $i < self::GENERATED_VALUE_CASES; $i++ ) {
			$cases[] = self::value( $ctx->fork( 'value-case-' . $i ) );
		}

		return $cases;
	}

	private static function value( \ComponentFuzz\FuzzContext $ctx, int $depth = 0 ) {
		if ( $depth >= 3 ) {
			return self::scalar_value( $ctx );
		}

		$type = $ctx->weightedChoice(
			array(
				array( 8, 'null' ),
				array( 10, 'bool' ),
				array( 14, 'int' ),
				array( 8, 'float' ),
				array( 24, 'string' ),
				array( 24, 'array' ),
				array( 12, 'object' ),
			)
		);

		if ( 'array' === $type ) {
			$out   = array();
			$count = $ctx->int( 0, 4 );
			$list  = $ctx->bool();
			for ( $i = 0; $i < $count; $i++ ) {
				if ( $list ) {
					$out[] = self::value( $ctx, $depth + 1 );
				} else {
					$out[ self::array_key( $ctx, $i ) ] = self::value( $ctx, $depth + 1 );
				}
			}
			return $out;
		}

		if ( 'object' === $type ) {
			$object = new \stdClass();
			$count  = $ctx->int( 0, 3 );
			for ( $i = 0; $i < $count; $i++ ) {
				$property          = 'p' . $i . '_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $ctx->identifier( 1, 8 ) );
				$object->$property = self::value( $ctx, $depth + 1 );
			}
			return $object;
		}

		return self::scalar_value( $ctx, $type );
	}

	private static function scalar_value( \ComponentFuzz\FuzzContext $ctx, ?string $type = null ) {
		$type = $type ?? $ctx->choice( array( 'null', 'bool', 'int', 'float', 'string' ) );

		if ( 'null' === $type ) {
			return null;
		}
		if ( 'bool' === $type ) {
			return $ctx->bool();
		}
		if ( 'int' === $type ) {
			return $ctx->int( -100000, 100000 );
		}
		if ( 'float' === $type ) {
			return $ctx->int( -100000, 100000 ) / max( 1, $ctx->int( 1, 1000 ) );
		}

		return self::string_value( $ctx );
	}

	private static function string_value( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->choice(
			array(
				'',
				'0',
				'false',
				'plain ascii',
				"line\nbreak\tvalue",
				"unicode-\xC3\xA9-\xE2\x98\x83",
				"invalid-\x80\xFF-bytes",
				$ctx->ascii( 0, 32 ),
				$ctx->bytes( 0, 16 ),
			)
		);
	}

	private static function wrapped_value( string $label, $payload ): array {
		return array(
			'label'   => $label,
			'payload' => $payload,
		);
	}

	private static function cache_entries( \ComponentFuzz\FuzzContext $ctx, \ComponentFuzz\FuzzContext $case, string $label, int $count ): array {
		$entries = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$entries[ self::key( $ctx, $label . '-' . $i ) ] = self::wrapped_value( $label . '-' . $i, self::value( $case ) );
		}

		return $entries;
	}

	private static function key( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'component_fuzz_' . $ctx->iteration() . '_' . preg_replace( '/[^A-Za-z0-9_]+/', '_', $label ) . '_' . substr( sha1( $label . ':' . $ctx->seed() ), 0, 10 );
	}

	private static function group( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'component_fuzz_group_' . $ctx->iteration() . '_' . preg_replace( '/[^A-Za-z0-9_]+/', '_', $label );
	}

	private static function array_key( \ComponentFuzz\FuzzContext $ctx, int $index ) {
		if ( $ctx->bool( 25 ) ) {
			return $index;
		}

		return 'k' . $index . '_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $ctx->identifier( 1, 8 ) );
	}

	private static function all_same_scalar( array $values, $expected ): bool {
		foreach ( $values as $value ) {
			if ( $value !== $expected ) {
				return false;
			}
		}

		return true;
	}

	private static function same_value( $left, $right ): bool {
		return serialize( $left ) === serialize( $right );
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 8 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item );
				$i++;
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			$out = array( 'class' => get_class( $value ) );
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => self::escape_bytes( $value ),
			'sha1'    => sha1( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function escape_bytes( string $value, int $limit = 160 ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; $i++ ) {
			$byte = ord( $value[ $i ] );
			if ( $byte >= 0x20 && $byte <= 0x7e && 0x5c !== $byte ) {
				$out .= chr( $byte );
			} elseif ( 0x5c === $byte ) {
				$out .= '\\\\';
			} elseif ( 0x0a === $byte ) {
				$out .= '\\n';
			} elseif ( 0x0d === $byte ) {
				$out .= '\\r';
			} elseif ( 0x09 === $byte ) {
				$out .= '\\t';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $limit ) {
			$out .= '...';
		}

		return $out;
	}
}
