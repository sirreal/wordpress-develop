<?php
namespace ComponentFuzz\Surfaces;

final class OptionsAutoloadSurface {
	public const NAME = 'options-autoload';

	private const GENERATED_VALUE_CASES = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'options-autoload.bootstrap-apis-available',
					'Required WordPress option/cache APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			$rows[] = self::check_value_round_trips( $ctx );
			$rows[] = self::check_duplicate_add_preserves_existing_value( $ctx );
			$rows[] = self::check_update_missing_creates_option_and_clears_notoptions( $ctx );
			$rows[] = self::check_alloptions_autoload_membership_and_transitions( $ctx );
			$rows[] = self::check_bulk_autoload_mutators_and_cache_coherence( $ctx );
			$rows[] = self::check_get_option_filters( $ctx );
			$rows[] = self::check_filter_cache_boundaries_across_prime_and_mutation( $ctx );
			$rows[] = self::check_pre_update_filters_transform_and_veto( $ctx );
			$rows[] = self::check_option_lifecycle_actions( $ctx );
			$rows[] = self::check_prime_option_caches_stability( $ctx );
			$rows[] = self::check_prime_option_caches_by_group_isolation( $ctx );
			$rows[] = self::check_notoptions_delete_add_lifecycle( $ctx );
			$rows[] = self::check_serialized_value_cache_shape( $ctx );
			$rows[] = self::check_option_name_boundaries( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'options-autoload.surface-no-throw',
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
				'remove_filter',
				'add_action',
				'remove_action',
				'get_option',
				'add_option',
				'update_option',
				'delete_option',
				'wp_load_alloptions',
				'wp_prime_option_caches',
				'wp_prime_option_caches_by_group',
				'wp_set_option_autoload',
				'wp_set_option_autoload_values',
				'wp_set_options_autoload',
				'wp_cache_get',
				'wp_cache_set',
				'wp_cache_delete',
				'wp_cache_get_multiple',
				'wp_cache_init',
				'wp_cache_flush',
				'wp_determine_option_autoload_value',
				'wp_autoload_values_to_autoload',
				'maybe_serialize',
				'maybe_unserialize',
				'is_serialized',
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

	private static function check_value_round_trips( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$case      = $ctx->fork( 'round-trips' );
		$failures  = array();
		$autoloads = array( null, true, false, 'yes', 'no', 'on', 'off' );

		foreach ( self::value_cases( $case ) as $index => $value ) {
			$key       = self::option_name( $ctx, 'round-trip-' . $index );
			$autoload  = $autoloads[ $index % count( $autoloads ) ];
			$updated   = self::wrapped_value( 'updated-' . $index, self::value( $ctx->fork( 'round-trip-update-' . $index ) ) );
			$missing   = self::missing_default( $key );
			$add       = add_option( $key, $value, '', $autoload );
			$store     = self::option_store();
			$raw       = $store[ $key ]['option_value'] ?? null;
			$raw_auto  = $store[ $key ]['autoload'] ?? null;
			wp_cache_flush();
			$got       = get_option( $key, $missing );
			$update    = update_option( $key, $updated, false );
			$got_upd   = get_option( $key, $missing );
			$delete    = delete_option( $key );
			$after_del = get_option( $key, $missing );
			$notopts   = wp_cache_get( 'notoptions', 'options' );

			self::collect_failure(
				$failures,
				true === $add
					&& self::same_value( self::returned_value_for_storage( $value ), $got )
					&& self::stored_value( $value ) === $raw
					&& self::expected_autoload( $key, $value, $autoload ) === $raw_auto
					&& true === $update
					&& self::same_value( $updated, $got_upd )
					&& true === $delete
					&& self::same_value( $missing, $after_del )
					&& is_array( $notopts )
					&& isset( $notopts[ $key ] ),
				"round trip case {$index} preserves normalized option value and delete/default behavior",
				array(
					'key'              => $key,
					'autoloadInput'    => $autoload,
					'storedAutoload'   => $raw_auto,
					'expectedAutoload' => self::expected_autoload( $key, $value, $autoload ),
					'input'            => self::describe_value( $value ),
					'expected'         => self::describe_value( self::returned_value_for_storage( $value ) ),
					'got'              => self::describe_value( $got ),
					'raw'              => self::describe_value( $raw ),
					'updatedGot'       => self::describe_value( $got_upd ),
					'afterDelete'      => self::describe_value( $after_del ),
				)
			);
		}

		return $ctx->result(
			'options-autoload.crud-round-trips-normalized-serializable-values',
			array() === $failures,
			array(
				'cases'    => count( self::value_cases( $case ) ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_duplicate_add_preserves_existing_value( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key         = self::option_name( $ctx, 'duplicate-add' );
		$first       = self::wrapped_value( 'first', self::value( $ctx->fork( 'duplicate-first' ) ) );
		$second      = self::wrapped_value( 'second', self::value( $ctx->fork( 'duplicate-second' ) ) );
		$add         = add_option( $key, $first, '', true );
		$raw_before  = self::option_store()[ $key ]['option_value'] ?? null;
		$dup         = add_option( $key, $second, '', false );
		$got         = get_option( $key, self::missing_default( $key ) );
		$store       = self::option_store();
		$alloptions  = wp_load_alloptions( true );
		$raw_after   = $store[ $key ]['option_value'] ?? null;
		$auto_after  = $store[ $key ]['autoload'] ?? null;
		$expected_raw = self::stored_value( $first );

		$ok = true === $add
			&& false === $dup
			&& self::same_value( $first, $got )
			&& $expected_raw === $raw_before
			&& $expected_raw === $raw_after
			&& 'on' === $auto_after
			&& isset( $alloptions[ $key ] )
			&& $expected_raw === $alloptions[ $key ];

		return $ctx->result(
			'options-autoload.duplicate-add-fails-without-mutating-value-or-autoload',
			$ok,
			array(
				'key'         => $key,
				'add'         => $add,
				'duplicate'   => $dup,
				'got'         => self::describe_value( $got ),
				'rawBefore'   => self::describe_value( $raw_before ),
				'rawAfter'    => self::describe_value( $raw_after ),
				'autoload'    => $auto_after,
				'inAlloptions' => isset( $alloptions[ $key ] ),
			)
		);
	}

	private static function check_update_missing_creates_option_and_clears_notoptions( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key       = self::option_name( $ctx, 'update-missing' );
		$missing   = self::missing_default( $key );
		$value     = self::wrapped_value( 'created-by-update', self::value( $ctx->fork( 'update-missing-value' ) ) );
		$before    = get_option( $key, $missing );
		$not_before = wp_cache_get( 'notoptions', 'options' );
		$update    = update_option( $key, $value, false );
		$got       = get_option( $key, $missing );
		$store     = self::option_store();
		$not_after = wp_cache_get( 'notoptions', 'options' );

		$ok = self::same_value( $missing, $before )
			&& is_array( $not_before )
			&& isset( $not_before[ $key ] )
			&& true === $update
			&& isset( $store[ $key ] )
			&& 'off' === ( $store[ $key ]['autoload'] ?? null )
			&& self::same_value( $value, $got )
			&& is_array( $not_after )
			&& ! isset( $not_after[ $key ] );

		return $ctx->result(
			'options-autoload.update-missing-adds-option-and-removes-notoption',
			$ok,
			array(
				'key'             => $key,
				'before'          => self::describe_value( $before ),
				'notoptionBefore' => is_array( $not_before ) && isset( $not_before[ $key ] ),
				'update'          => $update,
				'got'             => self::describe_value( $got ),
				'inStore'         => isset( $store[ $key ] ),
				'autoload'        => $store[ $key ]['autoload'] ?? null,
				'notoptionAfter'  => is_array( $not_after ) && isset( $not_after[ $key ] ),
			)
		);
	}

	private static function check_alloptions_autoload_membership_and_transitions( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();
		$cases    = array(
			array( 'label' => 'bool-true', 'autoload' => true ),
			array( 'label' => 'bool-false', 'autoload' => false ),
			array( 'label' => 'null-auto', 'autoload' => null ),
			array( 'label' => 'legacy-yes', 'autoload' => 'yes' ),
			array( 'label' => 'legacy-no', 'autoload' => 'no' ),
			array( 'label' => 'string-on', 'autoload' => 'on' ),
			array( 'label' => 'string-off', 'autoload' => 'off' ),
		);
		$names    = array();

		foreach ( $cases as $index => $case ) {
			$key            = self::option_name( $ctx, 'alloptions-' . $case['label'] );
			$value          = self::wrapped_value( $case['label'], self::value( $ctx->fork( 'alloptions-value-' . $index ) ) );
			$expected_auto  = self::expected_autoload( $key, $value, $case['autoload'] );
			$should_autoload = in_array( $expected_auto, wp_autoload_values_to_autoload(), true );
			$names[]        = array(
				'key'            => $key,
				'value'          => $value,
				'autoloadInput'  => $case['autoload'],
				'expectedRaw'    => self::stored_value( $value ),
				'storedAutoload' => $expected_auto,
				'shouldAutoload' => $should_autoload,
			);

			add_option( $key, $value, '', $case['autoload'] );
		}

		wp_cache_delete( 'alloptions', 'options' );
		$alloptions = wp_load_alloptions();

		foreach ( $names as $entry ) {
			$present = array_key_exists( $entry['key'], $alloptions );
			self::collect_failure(
				$failures,
				$entry['shouldAutoload'] === $present
					&& ( ! $present || $entry['expectedRaw'] === $alloptions[ $entry['key'] ] ),
				"alloptions membership matches autoload value for {$entry['key']}",
				array(
					'key'            => $entry['key'],
					'autoloadInput'  => $entry['autoloadInput'],
					'storedAutoload' => $entry['storedAutoload'],
					'shouldAutoload' => $entry['shouldAutoload'],
					'present'        => $present,
				)
			);
		}

		$to_off = self::first_entry_with_membership( $names, true );
		$to_on  = self::first_entry_with_membership( $names, false );

		$off_value = self::wrapped_value( 'transition-off', self::value( $ctx->fork( 'transition-off' ) ) );
		$on_value  = self::wrapped_value( 'transition-on', self::value( $ctx->fork( 'transition-on' ) ) );

		$off_update = update_option( $to_off['key'], $off_value, false );
		$on_update  = update_option( $to_on['key'], $on_value, true );
		$after      = wp_load_alloptions( true );
		$off_cache  = wp_cache_get( $to_off['key'], 'options' );
		$on_cache   = wp_cache_get( $to_on['key'], 'options' );

		self::collect_failure(
			$failures,
			true === $off_update
				&& true === $on_update
				&& ! isset( $after[ $to_off['key'] ] )
				&& isset( $after[ $to_on['key'] ] )
				&& self::stored_value( $on_value ) === $after[ $to_on['key'] ]
				&& self::stored_value( $off_value ) === $off_cache
				&& false === $on_cache,
			'update_option moves options between alloptions and individual option caches when autoload changes',
			array(
				'toOff'      => $to_off['key'],
				'toOn'       => $to_on['key'],
				'offUpdate'  => $off_update,
				'onUpdate'   => $on_update,
				'offPresent' => isset( $after[ $to_off['key'] ] ),
				'onPresent'  => isset( $after[ $to_on['key'] ] ),
				'offCache'   => self::describe_value( $off_cache ),
				'onCache'    => self::describe_value( $on_cache ),
			)
		);

		return $ctx->result(
			'options-autoload.alloptions-only-autoloaded-and-reflects-updates',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_bulk_autoload_mutators_and_cache_coherence( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$already_on  = self::option_name( $ctx, 'bulk-autoload-already-on' );
		$to_on       = self::option_name( $ctx, 'bulk-autoload-to-on' );
		$to_off      = self::option_name( $ctx, 'bulk-autoload-to-off' );
		$already_off = self::option_name( $ctx, 'bulk-autoload-already-off' );
		$missing     = self::option_name( $ctx, 'bulk-autoload-missing' );
		$values      = array(
			$already_on  => self::wrapped_value( 'already-on', self::value( $ctx->fork( 'bulk-already-on' ) ) ),
			$to_on       => self::wrapped_value( 'to-on', self::value( $ctx->fork( 'bulk-to-on' ) ) ),
			$to_off      => self::wrapped_value( 'to-off', self::value( $ctx->fork( 'bulk-to-off' ) ) ),
			$already_off => self::wrapped_value( 'already-off', self::value( $ctx->fork( 'bulk-already-off' ) ) ),
		);

		add_option( $already_on, $values[ $already_on ], '', true );
		add_option( $to_on, $values[ $to_on ], '', false );
		add_option( $to_off, $values[ $to_off ], '', true );
		add_option( $already_off, $values[ $already_off ], '', false );

		wp_load_alloptions( true );

		$bulk = wp_set_option_autoload_values(
			array(
				$already_on  => true,
				$to_on       => 'yes',
				$to_off      => false,
				$already_off => 'no',
				$missing     => true,
			)
		);
		$after_bulk_store      = self::option_store();
		$alloptions_after_bulk = wp_cache_get( 'alloptions', 'options' );
		$to_on_cache          = wp_cache_get( $to_on, 'options' );
		$reloaded_after_bulk  = wp_load_alloptions( true );

		$multi = wp_set_options_autoload( array( $already_on, $to_on, $to_off ), false );
		$after_multi_store      = self::option_store();
		$alloptions_after_multi = wp_cache_get( 'alloptions', 'options' );

		$single = wp_set_option_autoload( $already_off, true );
		$after_single_store      = self::option_store();
		$alloptions_after_single = wp_cache_get( 'alloptions', 'options' );
		$reloaded_after_single   = wp_load_alloptions( true );

		$ok = array(
			$already_on  => false,
			$to_on       => true,
			$to_off      => true,
			$already_off => false,
			$missing     => false,
		) === $bulk
			&& 'on' === ( $after_bulk_store[ $already_on ]['autoload'] ?? null )
			&& 'on' === ( $after_bulk_store[ $to_on ]['autoload'] ?? null )
			&& 'off' === ( $after_bulk_store[ $to_off ]['autoload'] ?? null )
			&& 'off' === ( $after_bulk_store[ $already_off ]['autoload'] ?? null )
			&& ! isset( $after_bulk_store[ $missing ] )
			&& false === $alloptions_after_bulk
			&& false === $to_on_cache
			&& isset( $reloaded_after_bulk[ $already_on ], $reloaded_after_bulk[ $to_on ] )
			&& ! isset( $reloaded_after_bulk[ $to_off ], $reloaded_after_bulk[ $already_off ] )
			&& self::stored_value( $values[ $already_on ] ) === $reloaded_after_bulk[ $already_on ]
			&& self::stored_value( $values[ $to_on ] ) === $reloaded_after_bulk[ $to_on ]
			&& array(
				$already_on => true,
				$to_on      => true,
				$to_off     => false,
			) === $multi
			&& 'off' === ( $after_multi_store[ $already_on ]['autoload'] ?? null )
			&& 'off' === ( $after_multi_store[ $to_on ]['autoload'] ?? null )
			&& 'off' === ( $after_multi_store[ $to_off ]['autoload'] ?? null )
			&& is_array( $alloptions_after_multi )
			&& ! isset( $alloptions_after_multi[ $already_on ], $alloptions_after_multi[ $to_on ], $alloptions_after_multi[ $to_off ] )
			&& true === $single
			&& 'on' === ( $after_single_store[ $already_off ]['autoload'] ?? null )
			&& false === $alloptions_after_single
			&& isset( $reloaded_after_single[ $already_off ] )
			&& self::stored_value( $values[ $already_off ] ) === $reloaded_after_single[ $already_off ];

		return $ctx->result(
			'options-autoload.bulk-autoload-mutators-update-store-and-caches',
			$ok,
			array(
				'bulk'                  => $bulk,
				'multi'                 => $multi,
				'single'                => $single,
				'afterBulkAutoloads'    => self::option_autoloads( $after_bulk_store, array( $already_on, $to_on, $to_off, $already_off, $missing ) ),
				'afterMultiAutoloads'   => self::option_autoloads( $after_multi_store, array( $already_on, $to_on, $to_off ) ),
				'afterSingleAutoloads'  => self::option_autoloads( $after_single_store, array( $already_off ) ),
				'alloptionsAfterBulk'   => self::describe_value( $alloptions_after_bulk ),
				'alloptionsAfterMulti'  => self::describe_value( $alloptions_after_multi ),
				'alloptionsAfterSingle' => self::describe_value( $alloptions_after_single ),
				'toOnCache'             => self::describe_value( $to_on_cache ),
			)
		);
	}

	private static function check_get_option_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$pre_key              = self::option_name( $ctx, 'pre-option-filter' );
		$default_missing_key  = self::option_name( $ctx, 'default-option-missing' );
		$default_existing_key = self::option_name( $ctx, 'default-option-existing' );
		$option_key           = self::option_name( $ctx, 'option-filter' );
		$stored_pre           = self::wrapped_value( 'pre-stored', self::value( $ctx->fork( 'pre-stored' ) ) );
		$pre_value            = self::wrapped_value( 'pre-short-circuit', self::value( $ctx->fork( 'pre-value' ) ) );
		$default_value        = self::wrapped_value( 'default-filter', self::value( $ctx->fork( 'default-value' ) ) );
		$stored_default       = self::wrapped_value( 'default-stored', self::value( $ctx->fork( 'default-stored' ) ) );
		$stored_option        = self::wrapped_value( 'option-stored', self::value( $ctx->fork( 'option-stored' ) ) );
		$filtered_option      = self::wrapped_value( 'option-filtered', self::value( $ctx->fork( 'option-filtered' ) ) );
		$pre_calls            = 0;
		$default_missing_calls = 0;
		$default_existing_calls = 0;
		$option_calls         = 0;

		add_option( $pre_key, $stored_pre, '', false );
		add_option( $default_existing_key, $stored_default, '', false );
		add_option( $option_key, $stored_option, '', false );

		$pre_filter = static function () use ( &$pre_calls, $pre_value ) {
			++$pre_calls;
			return $pre_value;
		};
		$default_missing_filter = static function () use ( &$default_missing_calls, $default_value ) {
			++$default_missing_calls;
			return $default_value;
		};
		$default_existing_filter = static function () use ( &$default_existing_calls ) {
			++$default_existing_calls;
			return array( 'unexpected' => true );
		};
		$option_filter = static function () use ( &$option_calls, $filtered_option ) {
			++$option_calls;
			return $filtered_option;
		};

		add_filter( "pre_option_{$pre_key}", $pre_filter, 10, 3 );
		add_filter( "default_option_{$default_missing_key}", $default_missing_filter, 10, 3 );
		add_filter( "default_option_{$default_existing_key}", $default_existing_filter, 10, 3 );
		add_filter( "option_{$option_key}", $option_filter, 10, 2 );

		try {
			$pre_got              = get_option( $pre_key, self::missing_default( $pre_key ) );
			$default_missing_got  = get_option( $default_missing_key, self::missing_default( $default_missing_key ) );
			$default_existing_got = get_option( $default_existing_key, self::missing_default( $default_existing_key ) );
			$option_got           = get_option( $option_key, self::missing_default( $option_key ) );
			$store                = self::option_store();
			$notoptions           = wp_cache_get( 'notoptions', 'options' );
		} finally {
			remove_filter( "pre_option_{$pre_key}", $pre_filter, 10 );
			remove_filter( "default_option_{$default_missing_key}", $default_missing_filter, 10 );
			remove_filter( "default_option_{$default_existing_key}", $default_existing_filter, 10 );
			remove_filter( "option_{$option_key}", $option_filter, 10 );
		}

		$ok = self::same_value( $pre_value, $pre_got )
			&& 1 === $pre_calls
			&& isset( $store[ $pre_key ] )
			&& self::stored_value( $stored_pre ) === $store[ $pre_key ]['option_value']
			&& self::same_value( $default_value, $default_missing_got )
			&& 1 === $default_missing_calls
			&& ! isset( $store[ $default_missing_key ] )
			&& is_array( $notoptions )
			&& isset( $notoptions[ $default_missing_key ] )
			&& self::same_value( $stored_default, $default_existing_got )
			&& 0 === $default_existing_calls
			&& self::same_value( $filtered_option, $option_got )
			&& 1 === $option_calls
			&& self::stored_value( $stored_option ) === ( $store[ $option_key ]['option_value'] ?? null );

		return $ctx->result(
			'options-autoload.get-option-filter-precedence-and-storage-isolation',
			$ok,
			array(
				'preKey'               => $pre_key,
				'defaultMissingKey'    => $default_missing_key,
				'defaultExistingKey'   => $default_existing_key,
				'optionKey'            => $option_key,
				'preCalls'             => $pre_calls,
				'defaultMissingCalls'  => $default_missing_calls,
				'defaultExistingCalls' => $default_existing_calls,
				'optionCalls'          => $option_calls,
				'preGot'               => self::describe_value( $pre_got ),
				'defaultMissingGot'    => self::describe_value( $default_missing_got ),
				'defaultExistingGot'   => self::describe_value( $default_existing_got ),
				'optionGot'            => self::describe_value( $option_got ),
			)
		);
	}

	private static function check_filter_cache_boundaries_across_prime_and_mutation( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$pre_key              = self::option_name( $ctx, 'pre-option-cache-boundary' );
		$default_key          = self::option_name( $ctx, 'default-option-cache-boundary' );
		$pre_value            = self::wrapped_value( 'pre-short-circuit-cache-boundary', self::value( $ctx->fork( 'pre-cache-value' ) ) );
		$pre_fallback         = self::missing_default( $pre_key );
		$pre_second_fallback  = self::wrapped_value( 'pre-second-fallback', self::value( $ctx->fork( 'pre-cache-fallback' ) ) );
		$default_return       = self::wrapped_value( 'filtered-default-cache-boundary', self::value( $ctx->fork( 'filtered-default-return' ) ) );
		$first_fallback       = self::missing_default( $default_key );
		$second_fallback      = self::wrapped_value( 'second-missing-fallback', self::value( $ctx->fork( 'second-missing-fallback' ) ) );
		$after_delete_fallback = self::wrapped_value( 'after-delete-fallback', self::value( $ctx->fork( 'after-delete-fallback' ) ) );
		$stored               = self::wrapped_value( 'filter-boundary-stored', self::value( $ctx->fork( 'filter-boundary-stored' ) ) );
		$updated              = self::wrapped_value( 'filter-boundary-updated', self::value( $ctx->fork( 'filter-boundary-updated' ) ) );
		$pre_seen             = array();
		$default_seen         = array();
		$option_seen          = array();
		$events               = array();

		$pre_filter = static function ( $pre_option, $option, $default_value ) use ( &$events, &$pre_seen, $pre_value ) {
			$events[]   = array( 'hook' => 'pre_option', 'option' => $option );
			$pre_seen[] = array(
				'pre'     => $pre_option,
				'option'  => $option,
				'default' => $default_value,
			);
			return $pre_value;
		};
		$default_filter = static function ( $default_value, $option, $passed_default ) use ( &$default_seen, &$events, $default_return ) {
			$events[]       = array( 'hook' => 'default_option', 'option' => $option );
			$default_seen[] = array(
				'default'       => $default_value,
				'option'        => $option,
				'passedDefault' => $passed_default,
			);
			return $default_return;
		};
		$option_filter = static function ( $value, $option ) use ( &$events, &$option_seen ) {
			$events[]      = array( 'hook' => 'option', 'option' => $option );
			$option_seen[] = array(
				'value'  => $value,
				'option' => $option,
			);
			return array(
				'label'   => 'option-filtered-cache-boundary',
				'payload' => $value,
			);
		};

		add_filter( "pre_option_{$pre_key}", $pre_filter, 10, 3 );
		add_filter( "default_option_{$default_key}", $default_filter, 10, 3 );
		add_filter( "option_{$default_key}", $option_filter, 10, 2 );

		try {
			wp_load_alloptions( true );
			$query_count_before_pre = self::query_count();
			$pre_first              = get_option( $pre_key, $pre_fallback );
			$query_count_after_pre  = self::query_count();
			$pre_not_after_get      = wp_cache_get( 'notoptions', 'options' );
			$pre_cache_found_after_get = null;
			$pre_cache_after_get       = wp_cache_get( $pre_key, 'options', false, $pre_cache_found_after_get );
			$all_after_pre_get = wp_cache_get( 'alloptions', 'options' );

			wp_prime_option_caches( array( $pre_key ) );
			$pre_not_after_prime         = wp_cache_get( 'notoptions', 'options' );
			$pre_cache_found_after_prime = null;
			$pre_cache_after_prime       = wp_cache_get( $pre_key, 'options', false, $pre_cache_found_after_prime );
			$all_after_pre_prime         = wp_cache_get( 'alloptions', 'options' );
			$pre_second                  = get_option( $pre_key, $pre_second_fallback );

			$missing_first             = get_option( $default_key, $first_fallback );
			$query_count_after_missing = self::query_count();
			$not_after_first           = wp_cache_get( 'notoptions', 'options' );
			$default_cache_found_after_first = null;
			$default_cache_after_first       = wp_cache_get( $default_key, 'options', false, $default_cache_found_after_first );
			$all_after_first                  = wp_cache_get( 'alloptions', 'options' );
			$missing_second                   = get_option( $default_key, $second_fallback );
			$query_count_after_second_missing = self::query_count();

			wp_prime_option_caches( array( $default_key ) );
			$query_count_after_prime_missing = self::query_count();
			$not_after_prime_missing         = wp_cache_get( 'notoptions', 'options' );
			$default_cache_found_after_prime_missing = null;
			$default_cache_after_prime_missing       = wp_cache_get( $default_key, 'options', false, $default_cache_found_after_prime_missing );
			$all_after_prime_missing = wp_cache_get( 'alloptions', 'options' );

			$add                     = add_option( $default_key, $stored, '', true );
			$default_calls_after_add = count( $default_seen );
			$option_calls_after_add  = count( $option_seen );
			$store_after_add         = self::option_store();
			$not_after_add           = wp_cache_get( 'notoptions', 'options' );
			$all_after_add_found     = null;
			$all_after_add           = wp_cache_get( 'alloptions', 'options', false, $all_after_add_found );
			$default_cache_found_after_add = null;
			$default_cache_after_add       = wp_cache_get( $default_key, 'options', false, $default_cache_found_after_add );
			$after_add_read                = get_option( $default_key, self::missing_default( $default_key ) );

			$update             = update_option( $default_key, $updated, false );
			$store_after_update = self::option_store();
			$not_after_update   = wp_cache_get( 'notoptions', 'options' );
			$all_after_update_found = null;
			$all_after_update       = wp_cache_get( 'alloptions', 'options', false, $all_after_update_found );
			$default_cache_found_after_update = null;
			$default_cache_after_update       = wp_cache_get( $default_key, 'options', false, $default_cache_found_after_update );
			$query_count_before_prime_update  = self::query_count();
			wp_prime_option_caches( array( $default_key ) );
			$query_count_after_prime_update = self::query_count();
			$default_cache_found_after_prime_update = null;
			$default_cache_after_prime_update       = wp_cache_get( $default_key, 'options', false, $default_cache_found_after_prime_update );
			$after_update_read = get_option( $default_key, self::missing_default( $default_key ) );

			$delete             = delete_option( $default_key );
			$store_after_delete = self::option_store();
			$not_after_delete   = wp_cache_get( 'notoptions', 'options' );
			$all_after_delete_found = null;
			$all_after_delete       = wp_cache_get( 'alloptions', 'options', false, $all_after_delete_found );
			$default_cache_found_after_delete = null;
			$default_cache_after_delete       = wp_cache_get( $default_key, 'options', false, $default_cache_found_after_delete );
			$after_delete_read = get_option( $default_key, $after_delete_fallback );
		} finally {
			remove_filter( "pre_option_{$pre_key}", $pre_filter, 10 );
			remove_filter( "default_option_{$default_key}", $default_filter, 10 );
			remove_filter( "option_{$default_key}", $option_filter, 10 );
		}

		$expected_event_order = array(
			"pre_option:{$pre_key}",
			"pre_option:{$pre_key}",
			"default_option:{$default_key}",
			"default_option:{$default_key}",
			"option:{$default_key}",
			"option:{$default_key}",
			"default_option:{$default_key}",
			"option:{$default_key}",
			"default_option:{$default_key}",
		);
		$event_order          = array_map(
			static function ( array $event ): string {
				return $event['hook'] . ':' . $event['option'];
			},
			$events
		);
		$expected_add_read    = array(
			'label'   => 'option-filtered-cache-boundary',
			'payload' => $stored,
		);
		$expected_update_read = array(
			'label'   => 'option-filtered-cache-boundary',
			'payload' => $updated,
		);
		$failures             = array();

		self::collect_failure(
			$failures,
			self::same_value( $pre_value, $pre_first )
				&& self::same_value( $pre_value, $pre_second )
				&& $query_count_before_pre === $query_count_after_pre
				&& ( ! is_array( $pre_not_after_get ) || ! isset( $pre_not_after_get[ $pre_key ] ) )
				&& false === $pre_cache_after_get
				&& false === $pre_cache_found_after_get
				&& is_array( $all_after_pre_get )
				&& ! isset( $all_after_pre_get[ $pre_key ] )
				&& is_array( $pre_not_after_prime )
				&& isset( $pre_not_after_prime[ $pre_key ] )
				&& false === $pre_cache_after_prime
				&& false === $pre_cache_found_after_prime
				&& is_array( $all_after_pre_prime )
				&& ! isset( $all_after_pre_prime[ $pre_key ] ),
			'pre_option short-circuits reads without positive or negative cache pollution until explicit priming',
			array(
				'preKey'             => $pre_key,
				'queryCountStableOnGet' => $query_count_before_pre === $query_count_after_pre,
				'notoptionAfterGet'  => is_array( $pre_not_after_get ) && isset( $pre_not_after_get[ $pre_key ] ),
				'notoptionAfterPrime' => is_array( $pre_not_after_prime ) && isset( $pre_not_after_prime[ $pre_key ] ),
				'cacheFoundAfterGet' => $pre_cache_found_after_get,
				'cacheFoundAfterPrime' => $pre_cache_found_after_prime,
			)
		);

		self::collect_failure(
			$failures,
			self::same_value( $default_return, $missing_first )
				&& self::same_value( $default_return, $missing_second )
				&& is_array( $not_after_first )
				&& isset( $not_after_first[ $default_key ] )
				&& false === $default_cache_after_first
				&& false === $default_cache_found_after_first
				&& is_array( $all_after_first )
				&& ! isset( $all_after_first[ $default_key ] )
				&& $query_count_after_missing === $query_count_after_second_missing
				&& $query_count_after_second_missing === $query_count_after_prime_missing
				&& is_array( $not_after_prime_missing )
				&& isset( $not_after_prime_missing[ $default_key ] )
				&& false === $default_cache_after_prime_missing
				&& false === $default_cache_found_after_prime_missing
				&& is_array( $all_after_prime_missing )
				&& ! isset( $all_after_prime_missing[ $default_key ] ),
			'default_option filtered misses repeat from notoptions and are not converted into positive caches by priming',
			array(
				'defaultKey'             => $default_key,
				'queryCountStableSecondGet' => $query_count_after_missing === $query_count_after_second_missing,
				'queryCountStablePrime' => $query_count_after_second_missing === $query_count_after_prime_missing,
				'notoptionAfterFirst'    => is_array( $not_after_first ) && isset( $not_after_first[ $default_key ] ),
				'notoptionAfterPrime'    => is_array( $not_after_prime_missing ) && isset( $not_after_prime_missing[ $default_key ] ),
				'cacheFoundAfterFirst'   => $default_cache_found_after_first,
				'cacheFoundAfterPrime'   => $default_cache_found_after_prime_missing,
			)
		);

		self::collect_failure(
			$failures,
			true === $add
				&& 2 === $default_calls_after_add
				&& 0 === $option_calls_after_add
				&& isset( $store_after_add[ $default_key ] )
				&& self::stored_value( $stored ) === ( $store_after_add[ $default_key ]['option_value'] ?? null )
				&& 'on' === ( $store_after_add[ $default_key ]['autoload'] ?? null )
				&& is_array( $not_after_add )
				&& ! isset( $not_after_add[ $default_key ] )
				&& true === $all_after_add_found
				&& is_array( $all_after_add )
				&& isset( $all_after_add[ $default_key ] )
				&& self::stored_value( $stored ) === $all_after_add[ $default_key ]
				&& false === $default_cache_after_add
				&& false === $default_cache_found_after_add
				&& self::same_value( $expected_add_read, $after_add_read )
				&& self::stored_value( $stored ) === $all_after_add[ $default_key ],
			'add_option clears the negative cache and stores raw autoloaded values despite active default and option filters',
			array(
				'defaultKey'          => $default_key,
				'add'                 => $add,
				'defaultCallsAfterAdd' => $default_calls_after_add,
				'optionCallsAfterAdd' => $option_calls_after_add,
				'autoloadAfterAdd'    => $store_after_add[ $default_key ]['autoload'] ?? null,
				'notoptionAfterAdd'   => is_array( $not_after_add ) && isset( $not_after_add[ $default_key ] ),
				'alloptionsFoundAfterAdd' => $all_after_add_found,
				'inAlloptionsAfterAdd' => isset( $all_after_add[ $default_key ] ),
				'cacheFoundAfterAdd'  => $default_cache_found_after_add,
				'afterAddRead'        => self::describe_value( $after_add_read ),
			)
		);

		self::collect_failure(
			$failures,
			true === $update
				&& isset( $store_after_update[ $default_key ] )
				&& self::stored_value( $updated ) === ( $store_after_update[ $default_key ]['option_value'] ?? null )
				&& 'off' === ( $store_after_update[ $default_key ]['autoload'] ?? null )
				&& is_array( $not_after_update )
				&& ! isset( $not_after_update[ $default_key ] )
				&& is_array( $all_after_update )
				&& ! isset( $all_after_update[ $default_key ] )
				&& true === $default_cache_found_after_update
				&& self::stored_value( $updated ) === $default_cache_after_update
				&& $query_count_before_prime_update === $query_count_after_prime_update
				&& true === $default_cache_found_after_prime_update
				&& self::stored_value( $updated ) === $default_cache_after_prime_update
				&& self::same_value( $expected_update_read, $after_update_read ),
			'update_option moves filtered options out of alloptions without stale negative or positive cache entries',
			array(
				'defaultKey'           => $default_key,
				'update'               => $update,
				'autoloadAfterUpdate'  => $store_after_update[ $default_key ]['autoload'] ?? null,
				'notoptionAfterUpdate' => is_array( $not_after_update ) && isset( $not_after_update[ $default_key ] ),
				'alloptionsFoundAfterUpdate' => $all_after_update_found,
				'inAlloptionsAfterUpdate' => is_array( $all_after_update ) && isset( $all_after_update[ $default_key ] ),
				'cacheFoundAfterUpdate' => $default_cache_found_after_update,
				'primeQueryCountStable' => $query_count_before_prime_update === $query_count_after_prime_update,
				'afterUpdateRead'      => self::describe_value( $after_update_read ),
			)
		);

		self::collect_failure(
			$failures,
			true === $delete
				&& ! isset( $store_after_delete[ $default_key ] )
				&& is_array( $not_after_delete )
				&& isset( $not_after_delete[ $default_key ] )
				&& is_array( $all_after_delete )
				&& ! isset( $all_after_delete[ $default_key ] )
				&& false === $default_cache_after_delete
				&& false === $default_cache_found_after_delete
				&& self::same_value( $default_return, $after_delete_read ),
			'delete_option restores only the negative cache while filtered defaults continue to avoid positive cache pollution',
			array(
				'defaultKey'            => $default_key,
				'delete'                => $delete,
				'notoptionAfterDelete'  => is_array( $not_after_delete ) && isset( $not_after_delete[ $default_key ] ),
				'alloptionsFoundAfterDelete' => $all_after_delete_found,
				'inAlloptionsAfterDelete' => is_array( $all_after_delete ) && isset( $all_after_delete[ $default_key ] ),
				'cacheFoundAfterDelete' => $default_cache_found_after_delete,
				'afterDeleteRead'       => self::describe_value( $after_delete_read ),
			)
		);

		self::collect_failure(
			$failures,
			$expected_event_order === $event_order
				&& 2 === count( $pre_seen )
				&& false === ( $pre_seen[0]['pre'] ?? null )
				&& false === ( $pre_seen[1]['pre'] ?? null )
				&& self::same_value( $pre_fallback, $pre_seen[0]['default'] ?? null )
				&& self::same_value( $pre_second_fallback, $pre_seen[1]['default'] ?? null )
				&& 4 === count( $default_seen )
				&& self::same_value( $first_fallback, $default_seen[0]['default'] ?? null )
				&& true === ( $default_seen[0]['passedDefault'] ?? null )
				&& self::same_value( $second_fallback, $default_seen[1]['default'] ?? null )
				&& true === ( $default_seen[1]['passedDefault'] ?? null )
				&& false === ( $default_seen[2]['default'] ?? null )
				&& false === ( $default_seen[2]['passedDefault'] ?? null )
				&& self::same_value( $after_delete_fallback, $default_seen[3]['default'] ?? null )
				&& true === ( $default_seen[3]['passedDefault'] ?? null )
				&& 3 === count( $option_seen )
				&& self::same_value( $stored, $option_seen[0]['value'] ?? null )
				&& self::same_value( $stored, $option_seen[1]['value'] ?? null )
				&& self::same_value( $updated, $option_seen[2]['value'] ?? null ),
			'filter call order and callback arguments stay local to the expected option/cache paths',
			array(
				'expectedEvents' => $expected_event_order,
				'events'         => $event_order,
				'preCalls'       => count( $pre_seen ),
				'defaultCalls'   => count( $default_seen ),
				'optionCalls'    => count( $option_seen ),
			)
		);

		return $ctx->result(
			'options-autoload.filtered-defaults-prime-and-mutations-preserve-cache-boundaries',
			array() === $failures,
			array(
				'preKey'       => $pre_key,
				'defaultKey'   => $default_key,
				'storedRawSha1' => sha1( self::stored_value( $stored ) ),
				'updatedRawSha1' => sha1( self::stored_value( $updated ) ),
				'failures'     => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_pre_update_filters_transform_and_veto( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$transform_key   = self::option_name( $ctx, 'pre-update-transform' );
		$veto_key        = self::option_name( $ctx, 'pre-update-veto' );
		$generic_key     = self::option_name( $ctx, 'pre-update-generic' );
		$initial         = self::wrapped_value( 'initial', self::value( $ctx->fork( 'pre-update-initial' ) ) );
		$incoming        = self::wrapped_value( 'incoming', self::value( $ctx->fork( 'pre-update-incoming' ) ) );
		$veto_incoming   = self::wrapped_value( 'veto-incoming', self::value( $ctx->fork( 'pre-update-veto-incoming' ) ) );
		$generic_incoming = self::wrapped_value( 'generic-incoming', self::value( $ctx->fork( 'pre-update-generic-incoming' ) ) );
		$transformed     = self::wrapped_value( 'specific-filtered', array( 'incoming' => $incoming, 'old' => $initial ) );
		$generic_value   = self::wrapped_value( 'generic-filtered', $generic_incoming );
		$specific_seen   = array();
		$veto_seen       = array();
		$generic_seen    = array();

		add_option( $transform_key, $initial, '', false );
		add_option( $veto_key, $initial, '', false );
		add_option( $generic_key, $initial, '', false );

		$specific_filter = static function ( $value, $old_value, $option ) use ( &$specific_seen, $transformed ) {
			$specific_seen = array(
				'value' => $value,
				'old'   => $old_value,
				'option' => $option,
			);
			return $transformed;
		};
		$veto_filter = static function ( $value, $old_value, $option ) use ( &$veto_seen ) {
			$veto_seen = array(
				'value' => $value,
				'old'   => $old_value,
				'option' => $option,
			);
			return $old_value;
		};
		$generic_filter = static function ( $value, $option, $old_value ) use ( &$generic_seen, $generic_key, $generic_value ) {
			if ( $option !== $generic_key ) {
				return $value;
			}

			$generic_seen = array(
				'value' => $value,
				'old'   => $old_value,
				'option' => $option,
			);
			return $generic_value;
		};

		add_filter( "pre_update_option_{$transform_key}", $specific_filter, 10, 3 );
		add_filter( "pre_update_option_{$veto_key}", $veto_filter, 10, 3 );
		add_filter( 'pre_update_option', $generic_filter, 10, 3 );

		try {
			$transform_update = update_option( $transform_key, $incoming, false );
			$transform_got    = get_option( $transform_key, self::missing_default( $transform_key ) );
			$veto_update      = update_option( $veto_key, $veto_incoming, false );
			$veto_got         = get_option( $veto_key, self::missing_default( $veto_key ) );
			$generic_update   = update_option( $generic_key, $generic_incoming, false );
			$generic_got      = get_option( $generic_key, self::missing_default( $generic_key ) );
			$store            = self::option_store();
		} finally {
			remove_filter( "pre_update_option_{$transform_key}", $specific_filter, 10 );
			remove_filter( "pre_update_option_{$veto_key}", $veto_filter, 10 );
			remove_filter( 'pre_update_option', $generic_filter, 10 );
		}

		$ok = true === $transform_update
			&& self::same_value( $incoming, $specific_seen['value'] ?? null )
			&& self::same_value( $initial, $specific_seen['old'] ?? null )
			&& $transform_key === ( $specific_seen['option'] ?? null )
			&& self::same_value( $transformed, $transform_got )
			&& self::stored_value( $transformed ) === ( $store[ $transform_key ]['option_value'] ?? null )
			&& false === $veto_update
			&& self::same_value( $veto_incoming, $veto_seen['value'] ?? null )
			&& self::same_value( $initial, $veto_seen['old'] ?? null )
			&& $veto_key === ( $veto_seen['option'] ?? null )
			&& self::same_value( $initial, $veto_got )
			&& self::stored_value( $initial ) === ( $store[ $veto_key ]['option_value'] ?? null )
			&& true === $generic_update
			&& self::same_value( $generic_incoming, $generic_seen['value'] ?? null )
			&& self::same_value( $initial, $generic_seen['old'] ?? null )
			&& $generic_key === ( $generic_seen['option'] ?? null )
			&& self::same_value( $generic_value, $generic_got );

		return $ctx->result(
			'options-autoload.pre-update-filters-transform-or-veto-before-storage',
			$ok,
			array(
				'transformKey'    => $transform_key,
				'vetoKey'         => $veto_key,
				'genericKey'      => $generic_key,
				'transformUpdate' => $transform_update,
				'vetoUpdate'      => $veto_update,
				'genericUpdate'   => $generic_update,
				'transformGot'    => self::describe_value( $transform_got ),
				'vetoGot'         => self::describe_value( $veto_got ),
				'genericGot'      => self::describe_value( $generic_got ),
			)
		);
	}

	private static function check_option_lifecycle_actions( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key     = self::option_name( $ctx, 'lifecycle-actions' );
		$initial = self::wrapped_value( 'lifecycle-initial', self::value( $ctx->fork( 'lifecycle-initial' ) ) );
		$updated = self::wrapped_value( 'lifecycle-updated', self::value( $ctx->fork( 'lifecycle-updated' ) ) );
		$events  = array();
		$record  = static function ( string $hook, array $args ) use ( &$events, $key ): void {
			$store    = self::option_store();
			$events[] = array(
				'hook'     => $hook,
				'args'     => $args,
				'stored'   => $store[ $key ] ?? null,
				'in_store' => isset( $store[ $key ] ),
			);
		};

		$add_before = static function ( $option, $value ) use ( $record ): void {
			$record( 'add_option', array( $option, $value ) );
		};
		$add_specific = static function ( $option, $value ) use ( $record, $key ): void {
			$record( "add_option_{$key}", array( $option, $value ) );
		};
		$added = static function ( $option, $value ) use ( $record ): void {
			$record( 'added_option', array( $option, $value ) );
		};
		$update_before = static function ( $option, $old_value, $value ) use ( $record ): void {
			$record( 'update_option', array( $option, $old_value, $value ) );
		};
		$update_specific = static function ( $old_value, $value, $option ) use ( $record, $key ): void {
			$record( "update_option_{$key}", array( $old_value, $value, $option ) );
		};
		$updated_action = static function ( $option, $old_value, $value ) use ( $record ): void {
			$record( 'updated_option', array( $option, $old_value, $value ) );
		};
		$delete_before = static function ( $option ) use ( $record ): void {
			$record( 'delete_option', array( $option ) );
		};
		$delete_specific = static function ( $option ) use ( $record, $key ): void {
			$record( "delete_option_{$key}", array( $option ) );
		};
		$deleted = static function ( $option ) use ( $record ): void {
			$record( 'deleted_option', array( $option ) );
		};

		add_action( 'add_option', $add_before, 10, 2 );
		add_action( "add_option_{$key}", $add_specific, 10, 2 );
		add_action( 'added_option', $added, 10, 2 );
		add_action( 'update_option', $update_before, 10, 3 );
		add_action( "update_option_{$key}", $update_specific, 10, 3 );
		add_action( 'updated_option', $updated_action, 10, 3 );
		add_action( 'delete_option', $delete_before, 10, 1 );
		add_action( "delete_option_{$key}", $delete_specific, 10, 1 );
		add_action( 'deleted_option', $deleted, 10, 1 );

		try {
			$add    = add_option( $key, $initial, '', false );
			$update = update_option( $key, $updated, false );
			$delete = delete_option( $key );
		} finally {
			remove_action( 'add_option', $add_before, 10 );
			remove_action( "add_option_{$key}", $add_specific, 10 );
			remove_action( 'added_option', $added, 10 );
			remove_action( 'update_option', $update_before, 10 );
			remove_action( "update_option_{$key}", $update_specific, 10 );
			remove_action( 'updated_option', $updated_action, 10 );
			remove_action( 'delete_option', $delete_before, 10 );
			remove_action( "delete_option_{$key}", $delete_specific, 10 );
			remove_action( 'deleted_option', $deleted, 10 );
		}

		$hooks = array_map(
			static function ( array $event ): string {
				return $event['hook'];
			},
			$events
		);

		$expected_hooks = array(
			'add_option',
			"add_option_{$key}",
			'added_option',
			'update_option',
			"update_option_{$key}",
			'updated_option',
			'delete_option',
			"delete_option_{$key}",
			'deleted_option',
		);

		$ok = true === $add
			&& true === $update
			&& true === $delete
			&& $expected_hooks === $hooks
			&& self::event_args_match( $events[0] ?? null, array( $key, $initial ) )
			&& false === ( $events[0]['in_store'] ?? null )
			&& self::event_args_match( $events[1] ?? null, array( $key, $initial ) )
			&& self::stored_value( $initial ) === ( $events[1]['stored']['option_value'] ?? null )
			&& self::event_args_match( $events[2] ?? null, array( $key, $initial ) )
			&& self::stored_value( $initial ) === ( $events[2]['stored']['option_value'] ?? null )
			&& self::event_args_match( $events[3] ?? null, array( $key, $initial, $updated ) )
			&& self::stored_value( $initial ) === ( $events[3]['stored']['option_value'] ?? null )
			&& self::event_args_match( $events[4] ?? null, array( $initial, $updated, $key ) )
			&& self::stored_value( $updated ) === ( $events[4]['stored']['option_value'] ?? null )
			&& self::event_args_match( $events[5] ?? null, array( $key, $initial, $updated ) )
			&& self::stored_value( $updated ) === ( $events[5]['stored']['option_value'] ?? null )
			&& self::event_args_match( $events[6] ?? null, array( $key ) )
			&& self::stored_value( $updated ) === ( $events[6]['stored']['option_value'] ?? null )
			&& self::event_args_match( $events[7] ?? null, array( $key ) )
			&& false === ( $events[7]['in_store'] ?? null )
			&& self::event_args_match( $events[8] ?? null, array( $key ) )
			&& false === ( $events[8]['in_store'] ?? null );

		return $ctx->result(
			'options-autoload.lifecycle-actions-preserve-order-payloads-and-storage-boundaries',
			$ok,
			array(
				'key'      => $key,
				'add'      => $add,
				'update'   => $update,
				'delete'   => $delete,
				'hooks'    => $hooks,
				'expected' => $expected_hooks,
				'events'   => self::describe_action_events( $events ),
			)
		);
	}

	private static function check_prime_option_caches_stability( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key1    = self::option_name( $ctx, 'prime-one' );
		$key2    = self::option_name( $ctx, 'prime-two' );
		$missing = self::option_name( $ctx, 'prime-missing' );
		$value1  = self::wrapped_value( 'prime-one', self::value( $ctx->fork( 'prime-one' ) ) );
		$value2  = self::wrapped_value( 'prime-two', self::value( $ctx->fork( 'prime-two' ) ) );

		add_option( $key1, $value1, '', false );
		add_option( $key2, $value2, '', false );
		wp_cache_delete( $key1, 'options' );
		wp_cache_delete( $key2, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$found_before = null;
		$before       = wp_cache_get( $key1, 'options', false, $found_before );
		wp_prime_option_caches( array( $key1, $key2, $missing, $key1 ) );
		$raw_cache    = wp_cache_get_multiple( array( $key1, $key2, $missing ), 'options' );
		$found_after  = null;
		$after_cache  = wp_cache_get( $key1, 'options', false, $found_after );
		$first_read   = get_option( $key1, self::missing_default( $key1 ) );
		$second_read  = get_option( $key1, self::missing_default( $key1 ) );
		$missing_read = get_option( $missing, self::missing_default( $missing ) );
		$notoptions   = wp_cache_get( 'notoptions', 'options' );
		$last_query   = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb']->last_query : null;
		wp_prime_option_caches( array( $key1, $key2, $missing ) );
		$second_query = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb']->last_query : null;

		$ok = false === $before
			&& false === $found_before
			&& true === $found_after
			&& self::stored_value( $value1 ) === $after_cache
			&& isset( $raw_cache[ $key1 ], $raw_cache[ $key2 ] )
			&& self::stored_value( $value1 ) === $raw_cache[ $key1 ]
			&& self::stored_value( $value2 ) === $raw_cache[ $key2 ]
			&& false === ( $raw_cache[ $missing ] ?? false )
			&& self::same_value( $value1, $first_read )
			&& self::same_value( $first_read, $second_read )
			&& self::same_value( self::missing_default( $missing ), $missing_read )
			&& is_array( $notoptions )
			&& isset( $notoptions[ $missing ] )
			&& $last_query === $second_query;

		return $ctx->result(
			'options-autoload.prime-option-caches-stabilizes-found-and-missing-reads',
			$ok,
			array(
				'key1'             => $key1,
				'key2'             => $key2,
				'missing'          => $missing,
				'foundBefore'      => $found_before,
				'foundAfter'       => $found_after,
				'cacheBefore'      => self::describe_value( $before ),
				'cacheAfter'       => self::describe_value( $after_cache ),
				'missingNotoption' => is_array( $notoptions ) && isset( $notoptions[ $missing ] ),
				'lastQueryStable'  => $last_query === $second_query,
			)
		);
	}

	private static function check_prime_option_caches_by_group_isolation( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$target_group  = 'cfz_group_' . $ctx->iteration() . '_' . substr( sha1( 'target:' . $ctx->seed() ), 0, 8 );
		$other_group   = 'cfz_group_' . $ctx->iteration() . '_' . substr( sha1( 'other:' . $ctx->seed() ), 0, 8 );
		$missing_group = 'cfz_group_' . $ctx->iteration() . '_' . substr( sha1( 'missing:' . $ctx->seed() ), 0, 8 );

		$target_found     = self::option_name( $ctx, 'group-prime-found' );
		$target_duplicate = self::option_name( $ctx, 'group-prime-duplicate' );
		$target_cached    = self::option_name( $ctx, 'group-prime-preprimed' );
		$target_autoload  = self::option_name( $ctx, 'group-prime-autoload' );
		$target_missing   = self::option_name( $ctx, 'group-prime-missing' );
		$other_option     = self::option_name( $ctx, 'group-prime-other' );

		$target_found_value     = self::wrapped_value( 'group-prime-found', self::value( $ctx->fork( 'group-prime-found' ) ) );
		$target_duplicate_value = self::wrapped_value( 'group-prime-duplicate', self::value( $ctx->fork( 'group-prime-duplicate' ) ) );
		$target_cached_db_value = self::wrapped_value( 'group-prime-preprimed-db', self::value( $ctx->fork( 'group-prime-preprimed-db' ) ) );
		$target_cached_value    = self::wrapped_value( 'group-prime-preprimed-cache', self::value( $ctx->fork( 'group-prime-preprimed-cache' ) ) );
		$target_autoload_value  = self::wrapped_value( 'group-prime-autoload', self::value( $ctx->fork( 'group-prime-autoload' ) ) );
		$other_value            = self::wrapped_value( 'group-prime-other', self::value( $ctx->fork( 'group-prime-other' ) ) );
		$missing_default        = self::missing_default( $target_missing );

		add_option( $target_found, $target_found_value, '', false );
		add_option( $target_duplicate, $target_duplicate_value, '', false );
		add_option( $target_cached, $target_cached_db_value, '', false );
		add_option( $target_autoload, $target_autoload_value, '', true );
		add_option( $other_option, $other_value, '', false );

		wp_cache_delete( $target_found, 'options' );
		wp_cache_delete( $target_duplicate, 'options' );
		wp_cache_delete( $target_autoload, 'options' );
		wp_cache_delete( $other_option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_set( $target_cached, self::stored_value( $target_cached_value ), 'options' );

		$target_cache_before_found = null;
		wp_cache_get( $target_found, 'options', false, $target_cache_before_found );
		$other_cache_before_found = null;
		wp_cache_get( $other_option, 'options', false, $other_cache_before_found );

		$GLOBALS['new_allowed_options'] = array(
			$target_group => array(
				$target_found,
				$target_missing,
				$target_cached,
				$target_autoload,
				$target_duplicate,
				$target_found,
			),
			$other_group  => array(
				$other_option,
			),
		);

		$query_count_before = self::query_count();
		wp_prime_option_caches_by_group( $target_group );
		$query_count_after_first = self::query_count();

		$found_cache_found = null;
		$found_cache       = wp_cache_get( $target_found, 'options', false, $found_cache_found );
		$duplicate_cache_found = null;
		$duplicate_cache       = wp_cache_get( $target_duplicate, 'options', false, $duplicate_cache_found );
		$cached_cache_found = null;
		$cached_cache       = wp_cache_get( $target_cached, 'options', false, $cached_cache_found );
		$autoload_cache_found = null;
		$autoload_cache       = wp_cache_get( $target_autoload, 'options', false, $autoload_cache_found );
		$missing_cache_found = null;
		$missing_cache       = wp_cache_get( $target_missing, 'options', false, $missing_cache_found );
		$other_cache_found = null;
		$other_cache       = wp_cache_get( $other_option, 'options', false, $other_cache_found );
		$alloptions        = wp_cache_get( 'alloptions', 'options' );
		$notoptions        = wp_cache_get( 'notoptions', 'options' );

		$found_read     = get_option( $target_found, self::missing_default( $target_found ) );
		$duplicate_read = get_option( $target_duplicate, self::missing_default( $target_duplicate ) );
		$cached_read    = get_option( $target_cached, self::missing_default( $target_cached ) );
		$autoload_read  = get_option( $target_autoload, self::missing_default( $target_autoload ) );
		$missing_read   = get_option( $target_missing, $missing_default );

		$query_count_before_second = self::query_count();
		wp_prime_option_caches_by_group( $target_group );
		$query_count_after_second = self::query_count();
		wp_prime_option_caches_by_group( $missing_group );
		$query_count_after_missing_group = self::query_count();

		$failures = array();

		self::collect_failure(
			$failures,
			false === $target_cache_before_found
				&& false === $other_cache_before_found
				&& $query_count_after_first > $query_count_before
				&& true === $found_cache_found
				&& true === $duplicate_cache_found
				&& self::stored_value( $target_found_value ) === $found_cache
				&& self::stored_value( $target_duplicate_value ) === $duplicate_cache
				&& self::same_value( $target_found_value, $found_read )
				&& self::same_value( $target_duplicate_value, $duplicate_read ),
			'group cache priming loads only uncached non-autoloaded members and tolerates duplicate group entries',
			array(
				'targetGroup'         => $target_group,
				'foundCacheFound'     => $found_cache_found,
				'duplicateCacheFound' => $duplicate_cache_found,
				'queriesBefore'       => $query_count_before,
				'queriesAfterFirst'   => $query_count_after_first,
				'foundRead'           => self::describe_value( $found_read ),
				'duplicateRead'       => self::describe_value( $duplicate_read ),
			)
		);

		self::collect_failure(
			$failures,
			true === $cached_cache_found
				&& self::stored_value( $target_cached_value ) === $cached_cache
				&& self::same_value( $target_cached_value, $cached_read )
				&& ! self::same_value( $target_cached_db_value, $cached_read ),
			'group cache priming does not overwrite an already primed option cache entry',
			array(
				'targetCached'   => $target_cached,
				'cacheFound'     => $cached_cache_found,
				'cachedRaw'      => self::describe_value( $cached_cache ),
				'cachedRead'     => self::describe_value( $cached_read ),
				'databaseValue'  => self::describe_value( $target_cached_db_value ),
				'preprimedValue' => self::describe_value( $target_cached_value ),
			)
		);

		self::collect_failure(
			$failures,
			false === $autoload_cache_found
				&& false === $autoload_cache
				&& is_array( $alloptions )
				&& isset( $alloptions[ $target_autoload ] )
				&& self::stored_value( $target_autoload_value ) === $alloptions[ $target_autoload ]
				&& self::same_value( $target_autoload_value, $autoload_read ),
			'group cache priming respects alloptions membership instead of creating duplicate individual caches',
			array(
				'targetAutoload'     => $target_autoload,
				'autoloadCacheFound' => $autoload_cache_found,
				'inAlloptions'       => is_array( $alloptions ) && isset( $alloptions[ $target_autoload ] ),
				'autoloadRead'       => self::describe_value( $autoload_read ),
			)
		);

		self::collect_failure(
			$failures,
			false === $missing_cache_found
				&& false === $missing_cache
				&& is_array( $notoptions )
				&& isset( $notoptions[ $target_missing ] )
				&& self::same_value( $missing_default, $missing_read ),
			'group cache priming records missing target-group options only in notoptions',
			array(
				'targetMissing'     => $target_missing,
				'missingCacheFound' => $missing_cache_found,
				'missingNotoption'  => is_array( $notoptions ) && isset( $notoptions[ $target_missing ] ),
				'missingRead'       => self::describe_value( $missing_read ),
			)
		);

		self::collect_failure(
			$failures,
			false === $other_cache_found
				&& false === $other_cache
				&& ( ! is_array( $notoptions ) || ! isset( $notoptions[ $other_option ] ) )
				&& is_array( $alloptions )
				&& ! isset( $alloptions[ $other_option ] ),
			'group cache priming leaves other registered groups untouched',
			array(
				'otherGroup'      => $other_group,
				'otherOption'     => $other_option,
				'otherCacheFound' => $other_cache_found,
				'otherNotoption'  => is_array( $notoptions ) && isset( $notoptions[ $other_option ] ),
				'otherAlloption'  => is_array( $alloptions ) && isset( $alloptions[ $other_option ] ),
			)
		);

		self::collect_failure(
			$failures,
			$query_count_before_second === $query_count_after_second
				&& $query_count_after_second === $query_count_after_missing_group,
			'repeated target-group and nonexistent-group priming are query-stable after caches are warm',
			array(
				'queriesBeforeSecond'      => $query_count_before_second,
				'queriesAfterSecond'       => $query_count_after_second,
				'queriesAfterMissingGroup' => $query_count_after_missing_group,
				'missingGroup'             => $missing_group,
			)
		);

		return $ctx->result(
			'options-autoload.prime-option-caches-by-group-isolates-registered-members-and-cache-states',
			array() === $failures,
			array(
				'targetGroup'     => $target_group,
				'otherGroup'      => $other_group,
				'missingGroup'    => $missing_group,
				'queriesFirstRun' => $query_count_after_first - $query_count_before,
				'registeredCount' => count( $GLOBALS['new_allowed_options'][ $target_group ] ),
				'failures'        => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_notoptions_delete_add_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key     = self::option_name( $ctx, 'notoptions-lifecycle' );
		$value   = self::wrapped_value( 'notoptions-value', self::value( $ctx->fork( 'notoptions-value' ) ) );
		$updated = self::wrapped_value( 'notoptions-updated', self::value( $ctx->fork( 'notoptions-updated' ) ) );
		$missing = self::missing_default( $key );

		$missing_read = get_option( $key, $missing );
		$after_missing = wp_cache_get( 'notoptions', 'options' );
		$add          = add_option( $key, $value, '', false );
		$after_add    = wp_cache_get( 'notoptions', 'options' );
		$delete       = delete_option( $key );
		$after_delete = wp_cache_get( 'notoptions', 'options' );
		$update       = update_option( $key, $updated, false );
		$after_update = wp_cache_get( 'notoptions', 'options' );
		$got          = get_option( $key, $missing );

		$ok = self::same_value( $missing, $missing_read )
			&& is_array( $after_missing )
			&& isset( $after_missing[ $key ] )
			&& true === $add
			&& is_array( $after_add )
			&& ! isset( $after_add[ $key ] )
			&& true === $delete
			&& is_array( $after_delete )
			&& isset( $after_delete[ $key ] )
			&& true === $update
			&& is_array( $after_update )
			&& ! isset( $after_update[ $key ] )
			&& self::same_value( $updated, $got );

		return $ctx->result(
			'options-autoload.notoptions-tracks-missing-delete-add-update-lifecycle',
			$ok,
			array(
				'key'          => $key,
				'add'          => $add,
				'delete'       => $delete,
				'update'       => $update,
				'afterMissing' => is_array( $after_missing ) && isset( $after_missing[ $key ] ),
				'afterAdd'     => is_array( $after_add ) && isset( $after_add[ $key ] ),
				'afterDelete'  => is_array( $after_delete ) && isset( $after_delete[ $key ] ),
				'afterUpdate'  => is_array( $after_update ) && isset( $after_update[ $key ] ),
				'got'          => self::describe_value( $got ),
			)
		);
	}

	private static function check_serialized_value_cache_shape( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$key   = self::option_name( $ctx, 'serialized-cache-shape' );
		$value = array(
			'list'              => array( null, false, 0, '0', '' ),
			'object'            => (object) array(
				'name'   => 'cache-shape',
				'nested' => array( 'seed' => $ctx->seed() ),
			),
			'serialized_string' => serialize( array( 'already' => 'serialized', 'iteration' => $ctx->iteration() ) ),
		);
		$expected_raw = self::stored_value( $value );

		$add       = add_option( $key, $value, '', false );
		$raw_add   = wp_cache_get( $key, 'options' );
		wp_cache_delete( $key, 'options' );
		$raw_gone  = wp_cache_get( $key, 'options' );
		wp_prime_option_caches( array( $key ) );
		$raw_prime = wp_cache_get( $key, 'options' );
		$got       = get_option( $key, self::missing_default( $key ) );

		$ok = true === $add
			&& is_string( $expected_raw )
			&& is_serialized( $expected_raw )
			&& $expected_raw === $raw_add
			&& false === $raw_gone
			&& $expected_raw === $raw_prime
			&& self::same_value( $value, $got );

		return $ctx->result(
			'options-autoload.serialized-values-use-raw-cache-and-public-unserialize',
			$ok,
			array(
				'key'       => $key,
				'add'       => $add,
				'rawAdd'    => self::describe_value( $raw_add ),
				'rawGone'   => self::describe_value( $raw_gone ),
				'rawPrime'  => self::describe_value( $raw_prime ),
				'got'       => self::describe_value( $got ),
				'rawSha1'   => sha1( $expected_raw ),
			)
		);
	}

	private static function check_option_name_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$trimmed_name = self::option_name( $ctx, 'trimmed-name' );
		$raw_name     = " \t" . $trimmed_name . " \n";
		$value        = self::wrapped_value( 'trimmed', self::value( $ctx->fork( 'trimmed-name-value' ) ) );
		$numeric_name = $ctx->int( 1000, 9999 );
		$numeric_value = self::wrapped_value( 'numeric', self::value( $ctx->fork( 'numeric-name-value' ) ) );

		$empty_get    = get_option( '', 'fallback' );
		$empty_add    = add_option( '', 'value', '', false );
		$space_update = update_option( " \t\n", 'value', false );
		$zero_add     = add_option( '0', 'value', '', false );
		$trim_add     = add_option( $raw_name, $value, '', false );
		$trim_got     = get_option( $raw_name, self::missing_default( $trimmed_name ) );
		$numeric_add  = add_option( $numeric_name, $numeric_value, '', false );
		$numeric_got  = get_option( $numeric_name, self::missing_default( (string) $numeric_name ) );
		$store        = self::option_store();
		$trim_delete  = delete_option( $raw_name );
		$numeric_delete = delete_option( $numeric_name );
		$after_delete_store = self::option_store();

		$ok = false === $empty_get
			&& false === $empty_add
			&& false === $space_update
			&& false === $zero_add
			&& true === $trim_add
			&& isset( $store[ $trimmed_name ] )
			&& ! isset( $store[ $raw_name ] )
			&& self::same_value( $value, $trim_got )
			&& true === $numeric_add
			&& isset( $store[ (string) $numeric_name ] )
			&& self::same_value( $numeric_value, $numeric_got )
			&& true === $trim_delete
			&& true === $numeric_delete
			&& ! isset( $after_delete_store[ $trimmed_name ] )
			&& ! isset( $after_delete_store[ (string) $numeric_name ] );

		return $ctx->result(
			'options-autoload.option-name-trimming-empty-zero-and-numeric-boundaries',
			$ok,
			array(
				'trimmedName'       => $trimmed_name,
				'numericName'       => $numeric_name,
				'emptyGet'          => self::describe_value( $empty_get ),
				'emptyAdd'          => $empty_add,
				'spaceUpdate'       => $space_update,
				'zeroAdd'           => $zero_add,
				'trimAdd'           => $trim_add,
				'trimStored'        => isset( $store[ $trimmed_name ] ),
				'rawStored'         => isset( $store[ $raw_name ] ),
				'numericAdd'        => $numeric_add,
				'numericStored'     => isset( $store[ (string) $numeric_name ] ),
				'trimDelete'        => $trim_delete,
				'numericDelete'     => $numeric_delete,
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

		foreach ( array( 'wp_object_cache', 'wpdb', 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', '_wp_using_ext_object_cache', 'new_allowed_options' ) as $name ) {
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

	private static function last_query(): ?string {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'last_query' ) ) {
			return $GLOBALS['wpdb']->last_query;
		}

		return null;
	}

	private static function query_count(): int {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'num_queries' ) ) {
			return (int) $GLOBALS['wpdb']->num_queries;
		}

		return count( self::query_log() );
	}

	private static function query_log(): array {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_queries' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_get_queries();
		}

		return array();
	}

	private static function first_entry_with_membership( array $entries, bool $should_autoload ): array {
		foreach ( $entries as $entry ) {
			if ( $should_autoload === $entry['shouldAutoload'] ) {
				return $entry;
			}
		}

		throw new \RuntimeException( 'Missing alloptions transition fixture.' );
	}

	private static function option_autoloads( array $store, array $options ): array {
		$out = array();
		foreach ( $options as $option ) {
			$out[ $option ] = $store[ $option ]['autoload'] ?? null;
		}

		return $out;
	}

	private static function event_args_match( ?array $event, array $expected ): bool {
		if ( null === $event || ! array_key_exists( 'args', $event ) ) {
			return false;
		}

		return self::same_value( $expected, $event['args'] );
	}

	private static function describe_action_events( array $events ): array {
		$out = array();
		foreach ( $events as $event ) {
			$out[] = array(
				'hook'     => $event['hook'] ?? null,
				'args'     => self::describe_value( $event['args'] ?? null ),
				'inStore'  => $event['in_store'] ?? null,
				'stored'   => self::describe_value( $event['stored'] ?? null ),
			);
		}

		return $out;
	}

	private static function value_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			null,
			false,
			true,
			0,
			'0',
			'',
			'plain text',
			"line\nbreak\tvalue",
			serialize( array( 'already' => 'serialized' ) ),
			array( 'empty' => '', 'zero' => 0, 'false' => false, 'null' => null ),
			(object) array( 'name' => 'object-like', 'nested' => array( 'x' => 1 ) ),
		);

		for ( $i = 0; $i < self::GENERATED_VALUE_CASES; $i++ ) {
			$cases[] = self::value( $ctx->fork( 'generated-value-' . $i ) );
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

		return $ctx->choice(
			array(
				'',
				'0',
				'false',
				'plain ascii',
				"line\nbreak\tvalue",
				"unicode-\xC3\xA9-\xE2\x98\x83",
				$ctx->ascii( 0, 32 ),
			)
		);
	}

	private static function wrapped_value( string $label, $payload ): array {
		return array(
			'label'   => $label,
			'payload' => $payload,
		);
	}

	private static function option_name( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'cfz_options_autoload_' . $ctx->iteration() . '_' . preg_replace( '/[^A-Za-z0-9_]+/', '_', $label ) . '_' . substr( sha1( $label . ':' . $ctx->seed() ), 0, 10 );
	}

	private static function array_key( \ComponentFuzz\FuzzContext $ctx, int $index ) {
		if ( $ctx->bool( 25 ) ) {
			return $index;
		}

		return 'k' . $index . '_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $ctx->identifier( 1, 8 ) );
	}

	private static function missing_default( string $key ): array {
		return array(
			'component_fuzz_missing_option' => $key,
		);
	}

	private static function expected_autoload( string $option, $value, $autoload ): string {
		return wp_determine_option_autoload_value( $option, $value, self::stored_value( $value ), $autoload );
	}

	private static function stored_value( $value ): string {
		return (string) maybe_serialize( $value );
	}

	private static function returned_value_for_storage( $value ) {
		return maybe_unserialize( self::stored_value( $value ) );
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
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
				++$i;
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

	private static function escape_bytes( string $value ): string {
		return preg_replace_callback(
			'/[^\x20-\x7E]/',
			static function ( array $match ): string {
				return sprintf( '\\x%02X', ord( $match[0] ) );
			},
			$value
		);
	}
}
