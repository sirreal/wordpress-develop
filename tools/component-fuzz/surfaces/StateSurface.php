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
			$rows[] = self::check_cache_object_cloning( $ctx );
			$rows[] = self::check_cache_multiple_equivalence( $ctx );
			$rows[] = self::check_cache_add_multiple_contract( $ctx );
			$rows[] = self::check_cache_increments( $ctx );
			$rows[] = self::check_cache_flushes_and_groups( $ctx );
			$rows[] = self::check_cache_last_changed( $ctx );

			$rows[] = self::check_option_filters( $ctx );
			$rows[] = self::check_option_crud( $ctx );
			$rows[] = self::check_option_cache_priming( $ctx );

			$rows[] = self::check_transient_crud_and_expiration( $ctx );
			$rows[] = self::check_transient_filters( $ctx );

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
				'wp_cache_get_last_changed',
				'wp_cache_set_last_changed',
				'get_option',
				'add_option',
				'update_option',
				'delete_option',
				'wp_prime_option_caches',
				'get_transient',
				'set_transient',
				'delete_transient',
				'maybe_serialize',
				'maybe_unserialize',
				'is_serialized',
				'is_serialized_string',
				'wp_json_encode',
				'map_deep',
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
		$value1  = self::wrapped_value( 'prime-one', self::value( $ctx->fork( 'prime-one' ) ) );
		$value2  = self::wrapped_value( 'prime-two', self::value( $ctx->fork( 'prime-two' ) ) );

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
