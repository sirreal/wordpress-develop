<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB Metadata API contracts.
 */
final class MetadataSurface {
	public const NAME = 'metadata';

	/**
	 * @var string[]
	 */
	private const OBJECT_TYPES = array( 'post', 'term', 'comment', 'user' );

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'metadata.bootstrap-apis-available',
					'Required Metadata API symbols are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_registration_visibility( $ctx->fork( 'registration' ) );
			$rows[] = self::check_registration_argument_edges( $ctx->fork( 'registration-edges' ) );
			$rows[] = self::check_defaults_agree_with_lookup( $ctx->fork( 'defaults' ) );
			$rows[] = self::check_object_type_and_subtype_boundaries( $ctx->fork( 'boundaries' ) );
			$rows[] = self::check_sanitize_and_auth_callback_locality( $ctx->fork( 'callbacks' ) );
			$rows[] = self::check_protected_meta_locality( $ctx->fork( 'protected' ) );
			$rows[] = self::check_no_db_crud_filters_and_cache( $ctx->fork( 'crud' ) );
			$rows[] = self::check_no_db_by_mid_filters_and_fail_closed( $ctx->fork( 'by-mid' ) );
			$rows[] = self::check_in_memory_crud_cache_invalidation( $ctx->fork( 'crud-cache' ) );
			$rows[] = self::check_lazyloader_queue_and_cache( $ctx->fork( 'lazyloader' ) );
			$rows[] = self::check_registered_metadata_by_object_subtype( $ctx->fork( 'by-subtype' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'metadata.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_Error',
				'WP_Metadata_Lazyloader',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'add_metadata',
				'apply_filters',
				'delete_metadata',
				'delete_metadata_by_mid',
				'get_metadata',
				'get_metadata_default',
				'get_metadata_by_mid',
				'get_metadata_raw',
				'get_object_subtype',
				'get_registered_meta_keys',
				'get_registered_metadata',
				'has_filter',
				'is_protected_meta',
				'metadata_exists',
				'register_meta',
				'register_post_meta',
				'registered_meta_key_exists',
				'remove_action',
				'remove_filter',
				'sanitize_meta',
				'unregister_meta_key',
				'update_metadata',
				'update_metadata_by_mid',
				'update_meta_cache',
				'wp_cache_delete',
				'wp_cache_delete_multiple',
				'wp_cache_flush',
				'wp_cache_get',
				'wp_cache_set',
				'wp_slash',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_registration_visibility( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case             = $ctx->fork( 'registration-' . $object_type );
			$object_id        = 1100 + $case->int( 1, 8000 ) + $index;
			$subtype          = self::object_subtype( $case, $object_type );
			$meta_key         = self::meta_key( $case, 'shared' );
			$other_key        = self::meta_key( $case->fork( 'other' ), 'unregistered' );
			$global_single    = $case->bool();
			$subtype_single   = ! $global_single;
			$duplicate_single = ! $subtype_single;

			$subtype_filter = static function ( $current, $id ) use ( $object_id, $subtype ) {
				return (int) $id === $object_id ? $subtype : $current;
			};
			\add_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10, 2 );

			$global_args = array(
				'type'        => 'string',
				'description' => 'Component fuzz global metadata',
				'single'      => $global_single,
				'label'       => 'Component Fuzz Global',
			);
			$subtype_args = array(
				'type'        => 'string',
				'description' => 'Component fuzz subtype metadata',
				'single'      => $subtype_single,
				'label'       => 'Component Fuzz Subtype',
			);

			$registered_global    = \register_meta( $object_type, $meta_key, $global_args );
			$registered_subtype   = self::register_subtype_meta( $object_type, $subtype, $meta_key, $subtype_args );
			$duplicate_args       = $subtype_args;
			$duplicate_args['single'] = $duplicate_single;
			$registered_duplicate = self::register_subtype_meta( $object_type, $subtype, $meta_key, $duplicate_args );

			$global_keys  = \get_registered_meta_keys( $object_type );
			$subtype_keys = \get_registered_meta_keys( $object_type, $subtype );

			$first_value  = 'first-' . substr( dechex( $case->seed() ), -6 );
			$second_value = 'second-' . $case->identifier( 3, 8 );
			\wp_cache_set(
				$object_id,
				array(
					$meta_key  => array( \maybe_serialize( $first_value ), \maybe_serialize( $second_value ) ),
					$other_key => array( \maybe_serialize( 'other-value' ) ),
				),
				$object_type . '_meta'
			);

			$specific_value = \get_registered_metadata( $object_type, $object_id, $meta_key );
			$all_values     = \get_registered_metadata( $object_type, $object_id );
			$exists_global  = \registered_meta_key_exists( $object_type, $meta_key );
			$exists_subtype = \registered_meta_key_exists( $object_type, $meta_key, $subtype );

			$expected_specific = $duplicate_single ? $first_value : array( $first_value, $second_value );

			$unregistered_subtype = \unregister_meta_key( $object_type, $meta_key, $subtype );
			$after_subtype_drop   = \get_registered_metadata( $object_type, $object_id, $meta_key );
			$expected_global      = $global_single ? $first_value : array( $first_value, $second_value );
			$unregistered_global  = \unregister_meta_key( $object_type, $meta_key );
			$unregistered_again   = \unregister_meta_key( $object_type, $meta_key );
			$missing_value        = \get_registered_metadata( $object_type, $object_id, $meta_key );

			\remove_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10 );

			self::collect_failure(
				$failures,
				true === $registered_global
					&& true === $registered_subtype
					&& true === $registered_duplicate
					&& isset( $global_keys[ $meta_key ], $subtype_keys[ $meta_key ] )
					&& $global_single === $global_keys[ $meta_key ]['single']
					&& $duplicate_single === $subtype_keys[ $meta_key ]['single']
					&& $exists_global
					&& $exists_subtype
					&& self::same_value( $expected_specific, $specific_value )
					&& array_key_exists( $meta_key, $all_values )
					&& ! array_key_exists( $other_key, $all_values )
					&& true === $unregistered_subtype
					&& self::same_value( $expected_global, $after_subtype_drop )
					&& true === $unregistered_global
					&& false === $unregistered_again
					&& false === $missing_value,
				"registered {$object_type} metadata is visible by object type and subtype",
				array(
					'objectType'       => $object_type,
					'subtype'          => $subtype,
					'metaKey'          => $meta_key,
					'globalSingle'     => $global_single,
					'duplicateSingle'  => $duplicate_single,
					'specificValue'    => $specific_value,
					'afterSubtypeDrop' => $after_subtype_drop,
					'allKeys'          => array_keys( $all_values ),
					'existsGlobal'     => $exists_global,
					'existsSubtype'    => $exists_subtype,
					'registered'       => array( $registered_global, $registered_subtype, $registered_duplicate ),
					'unregistered'     => array( $unregistered_subtype, $unregistered_global, $unregistered_again ),
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.registration.visibility-duplicates-unregister',
			array() === $failures,
			array(
				'objectTypes' => self::OBJECT_TYPES,
				'failures'    => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_registration_argument_edges( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case                = $ctx->fork( 'registration-edges-' . $object_type );
			$object_id           = 1800 + $case->int( 1, 8000 ) + $index;
			$subtype             = self::object_subtype( $case, $object_type );
			$args_key            = self::meta_key( $case->fork( 'args-key' ), 'args' );
			$invalid_array_key   = self::meta_key( $case->fork( 'invalid-array' ), 'invalid-array' );
			$invalid_default_key = self::meta_key( $case->fork( 'invalid-default' ), 'invalid-default' );
			$legacy_key          = self::meta_key( $case->fork( 'legacy' ), 'legacy' );
			$expected_label      = 'Component Fuzz ' . $case->identifier( 4, 10 );
			$expected_description = 'Filtered registration ' . $case->identifier( 4, 10 );
			$filter_records      = array();
			$legacy_calls        = array(
				'sanitize' => 0,
				'auth'     => 0,
			);
			$allowed_list_filter_present = false !== \has_filter( 'register_meta_args', '_wp_register_meta_args_allowed_list' );

			$args_filter = static function ( $args, $defaults, $filtered_object_type, $filtered_meta_key ) use (
				&$filter_records,
				$object_type,
				$args_key,
				$expected_label,
				$expected_description
			) {
				$target           = $object_type === $filtered_object_type && $args_key === $filtered_meta_key;
				$filter_records[] = array(
					'objectType'          => $filtered_object_type,
					'metaKey'             => $filtered_meta_key,
					'target'              => $target,
					'hasLabelDefault'     => array_key_exists( 'label', $defaults ),
					'hasRevisionsDefault' => array_key_exists( 'revisions_enabled', $defaults ),
				);

				if ( $target ) {
					$args['single']                 = true;
					$args['label']                  = $expected_label;
					$args['description']            = $expected_description;
					$args['component_fuzz_extra']   = 'must be dropped by the allowed-list filter';
					$args['revisions_enabled']      = false;
				}

				return $args;
			};
			$legacy_sanitize = static function ( $meta_value, $key, $type ) use (
				&$legacy_calls,
				$legacy_key,
				$object_type
			) {
				++$legacy_calls['sanitize'];
				return array(
					'legacy'   => true,
					'keyMatch' => $legacy_key === $key,
					'type'     => $type,
					'typeMatch' => $object_type === $type,
					'value'    => $meta_value,
				);
			};
			$legacy_auth = static function ( $allowed, $key, $object_id_arg ) use (
				&$legacy_calls,
				$legacy_key,
				$object_id
			) {
				unset( $allowed );
				++$legacy_calls['auth'];
				return $legacy_key === $key && $object_id === (int) $object_id_arg;
			};

			\add_filter( 'register_meta_args', $args_filter, 5, 4 );
			$args_registered = self::register_subtype_meta(
				$object_type,
				$subtype,
				$args_key,
				array(
					'type'        => 'string',
					'description' => 'Unfiltered registration',
					'single'      => false,
					'label'       => 'Unfiltered',
				)
			);
			\remove_filter( 'register_meta_args', $args_filter, 5 );

			$args_keys    = \get_registered_meta_keys( $object_type, $subtype );
			$args_record  = $args_keys[ $args_key ] ?? array();
			$target_seen  = false;
			$defaults_seen = false;
			foreach ( $filter_records as $record ) {
				if ( true === $record['target'] ) {
					$target_seen   = true;
					$defaults_seen = true === $record['hasLabelDefault']
						&& true === $record['hasRevisionsDefault'];
				}
			}

			$invalid_array_registered = \register_meta(
				$object_type,
				$invalid_array_key,
				array(
					'type'         => 'array',
					'single'       => true,
					'show_in_rest' => true,
				)
			);
			$invalid_default_registered = \register_meta(
				$object_type,
				$invalid_default_key,
				array(
					'type'    => 'integer',
					'single'  => true,
					'default' => 'not-an-integer-' . $case->identifier( 4, 8 ),
				)
			);
			\remove_filter( "auth_{$object_type}_meta_{$invalid_default_key}", '__return_true', 10 );

			$legacy_registered = \register_meta( $object_type, $legacy_key, $legacy_sanitize, $legacy_auth );
			$legacy_value      = self::metadata_value( $case->fork( 'legacy-value' ) );
			$legacy_sanitized  = \sanitize_meta( $legacy_key, $legacy_value, $object_type );
			$legacy_auth_ok    = \apply_filters(
				"auth_{$object_type}_meta_{$legacy_key}",
				null,
				$legacy_key,
				$object_id,
				0,
				'edit',
				array()
			);
			$legacy_unregistered = \unregister_meta_key( $object_type, $legacy_key );
			$legacy_keys         = \get_registered_meta_keys( $object_type );
			\remove_filter( "sanitize_{$object_type}_meta_{$legacy_key}", $legacy_sanitize, 10 );
			\remove_filter( "auth_{$object_type}_meta_{$legacy_key}", $legacy_auth, 10 );

			self::collect_failure(
				$failures,
				true === $args_registered
					&& $target_seen
					&& $defaults_seen
					&& true === ( $args_record['single'] ?? null )
					&& $expected_label === ( $args_record['label'] ?? null )
					&& $expected_description === ( $args_record['description'] ?? null )
					&& (
						$allowed_list_filter_present
							? ! array_key_exists( 'component_fuzz_extra', $args_record )
							: 'must be dropped by the allowed-list filter' === ( $args_record['component_fuzz_extra'] ?? null )
					)
					&& false === $invalid_array_registered
					&& false === \registered_meta_key_exists( $object_type, $invalid_array_key )
					&& false === $invalid_default_registered
					&& false === \registered_meta_key_exists( $object_type, $invalid_default_key )
					&& false === $legacy_registered
					&& ! isset( $legacy_keys[ $legacy_key ] )
					&& true === ( $legacy_sanitized['legacy'] ?? null )
					&& self::same_value( $legacy_value, $legacy_sanitized['value'] ?? null )
					&& true === $legacy_auth_ok
					&& array( 'sanitize' => 1, 'auth' => 1 ) === $legacy_calls
					&& false === $legacy_unregistered
					&& false === \has_filter( "sanitize_{$object_type}_meta_{$legacy_key}", $legacy_sanitize )
					&& false === \has_filter( "auth_{$object_type}_meta_{$legacy_key}", $legacy_auth ),
				"registration argument filtering and legacy callbacks are deterministic for {$object_type}",
				array(
					'objectType'               => $object_type,
					'subtype'                  => $subtype,
					'argsKey'                  => $args_key,
					'argsRecord'               => $args_record,
					'filterRecords'            => $filter_records,
					'allowedListFilterPresent' => $allowed_list_filter_present,
					'invalidArrayRegistered'   => $invalid_array_registered,
					'invalidDefaultRegistered' => $invalid_default_registered,
					'legacyRegistered'         => $legacy_registered,
					'legacySanitized'          => $legacy_sanitized,
					'legacyAuthOk'             => $legacy_auth_ok,
					'legacyCalls'              => $legacy_calls,
					'legacyUnregistered'       => $legacy_unregistered,
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.registration.args-validation-legacy-callbacks',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_defaults_agree_with_lookup( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case      = $ctx->fork( 'defaults-' . $object_type );
			$object_id = 2100 + $case->int( 1, 7000 ) + $index;
			$subtype   = self::object_subtype( $case, $object_type );
			$global    = self::schema_case( $case->fork( 'global-schema' ) );
			$subtyped  = self::schema_case( $case->fork( 'subtype-schema' ) );

			$global_key  = self::meta_key( $case->fork( 'global-key' ), 'default-global' );
			$subtype_key = self::meta_key( $case->fork( 'subtype-key' ), 'default-subtype' );
			$missing_key = self::meta_key( $case->fork( 'missing-key' ), 'default-missing' );

			$subtype_filter = static function ( $current, $id ) use ( $object_id, $subtype ) {
				return (int) $id === $object_id ? $subtype : $current;
			};
			\add_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10, 2 );

			$global_registered = \register_meta(
				$object_type,
				$global_key,
				array(
					'type'         => $global['type'],
					'single'       => $global['single'],
					'default'      => $global['default'],
					'show_in_rest' => $global['showInRest'],
				)
			);
			$subtype_registered = self::register_subtype_meta(
				$object_type,
				$subtype,
				$subtype_key,
				array(
					'type'         => $subtyped['type'],
					'single'       => $subtyped['single'],
					'default'      => $subtyped['default'],
					'show_in_rest' => $subtyped['showInRest'],
				)
			);

			\wp_cache_set(
				$object_id,
				array(
					self::meta_key( $case->fork( 'sentinel' ), 'sentinel' ) => array( \maybe_serialize( 'sentinel' ) ),
				),
				$object_type . '_meta'
			);
			\wp_cache_set(
				$object_id + 1,
				array(
					self::meta_key( $case->fork( 'sentinel-two' ), 'sentinel' ) => array( \maybe_serialize( 'sentinel' ) ),
				),
				$object_type . '_meta'
			);

			$global_default = \get_metadata_default( $object_type, $object_id, $global_key, $global['single'] );
			$global_lookup  = \get_metadata( $object_type, $object_id, $global_key, $global['single'] );
			$global_reg     = \get_registered_metadata( $object_type, $object_id, $global_key );

			$subtype_default = \get_metadata_default( $object_type, $object_id, $subtype_key, $subtyped['single'] );
			$subtype_lookup  = \get_metadata( $object_type, $object_id, $subtype_key, $subtyped['single'] );
			$subtype_reg     = \get_registered_metadata( $object_type, $object_id, $subtype_key );

			$unmatched_single = \get_metadata_default( $object_type, $object_id + 1, $subtype_key, true );
			$unmatched_multi  = \get_metadata_default( $object_type, $object_id + 1, $subtype_key, false );
			$missing_single   = \get_metadata( $object_type, $object_id, $missing_key, true );
			$missing_multi    = \get_metadata( $object_type, $object_id, $missing_key, false );

			\remove_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10 );

			$expected_global  = self::expected_default( $global['default'], $global['single'] );
			$expected_subtype = self::expected_default( $subtyped['default'], $subtyped['single'] );

			self::collect_failure(
				$failures,
				true === $global_registered
					&& true === $subtype_registered
					&& self::same_value( $expected_global, $global_default )
					&& self::same_value( $expected_global, $global_lookup )
					&& self::same_value( $expected_global, $global_reg )
					&& self::same_value( $expected_subtype, $subtype_default )
					&& self::same_value( $expected_subtype, $subtype_lookup )
					&& self::same_value( $expected_subtype, $subtype_reg )
					&& '' === $unmatched_single
					&& array() === $unmatched_multi
					&& '' === $missing_single
					&& array() === $missing_multi,
				"registered {$object_type} defaults agree with metadata fallback",
				array(
					'objectType'       => $object_type,
					'subtype'          => $subtype,
					'globalType'       => $global['type'],
					'subtypeType'      => $subtyped['type'],
					'globalSingle'     => $global['single'],
					'subtypeSingle'    => $subtyped['single'],
					'globalDefault'    => $global_default,
					'globalLookup'     => $global_lookup,
					'subtypeDefault'   => $subtype_default,
					'subtypeLookup'    => $subtype_lookup,
					'unmatchedSingle'  => $unmatched_single,
					'unmatchedMulti'   => $unmatched_multi,
					'missingSingle'    => $missing_single,
					'missingMulti'     => $missing_multi,
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.defaults.match-lookup-fallbacks',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_object_type_and_subtype_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$object_id  = 2800 + $ctx->int( 1, 7000 );
		$shared_key = self::meta_key( $ctx->fork( 'shared-key' ), 'boundary-shared' );
		$owner_key  = self::meta_key( $ctx->fork( 'owner-key' ), 'boundary-owner' );
		$owner_type = $ctx->choice( self::OBJECT_TYPES );
		$failures   = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case         = $ctx->fork( 'boundary-' . $object_type );
			$subtype      = self::object_subtype( $case, $object_type );
			$shared_single = $case->bool();
			$first_value  = $object_type . '-first-' . $case->identifier( 4, 9 );
			$second_value = $object_type . '-second-' . $case->identifier( 4, 9 );
			$owner_value  = $object_type . '-owner-' . $case->identifier( 4, 9 );

			$subtype_filter = static function ( $current, $id ) use ( $object_id, $subtype ) {
				return (int) $id === $object_id ? $subtype : $current;
			};
			\add_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10, 2 );

			$shared_registered = self::register_subtype_meta(
				$object_type,
				$subtype,
				$shared_key,
				array(
					'type'        => 'string',
					'description' => 'Boundary shared key',
					'single'      => $shared_single,
				)
			);
			$owner_registered  = true;
			if ( $owner_type === $object_type ) {
				$owner_registered = \register_meta(
					$object_type,
					$owner_key,
					array(
						'type'   => 'string',
						'single' => true,
					)
				);
			}

			\wp_cache_set(
				$object_id,
				array(
					$shared_key => array( \maybe_serialize( $first_value ), \maybe_serialize( $second_value ) ),
					$owner_key  => array( \maybe_serialize( $owner_value ) ),
				),
				$object_type . '_meta'
			);

			$specific_shared = \get_registered_metadata( $object_type, $object_id, $shared_key );
			$specific_owner  = \get_registered_metadata( $object_type, $object_id, $owner_key );
			$registered_all  = \get_registered_metadata( $object_type, $object_id );
			$raw_all         = \get_metadata( $object_type, $object_id );
			$raw_shared      = \get_metadata_raw( $object_type, $object_id, $shared_key, false );
			$wrong_subtype_keys = \get_registered_meta_keys(
				self::different_object_type( $object_type ),
				$subtype
			);

			\remove_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10 );

			$expected_shared = $shared_single ? $first_value : array( $first_value, $second_value );
			$expected_owner  = $owner_type === $object_type ? $owner_value : false;

			self::collect_failure(
				$failures,
				true === $shared_registered
					&& true === $owner_registered
					&& \registered_meta_key_exists( $object_type, $shared_key, $subtype )
					&& ! \registered_meta_key_exists( self::different_object_type( $object_type ), $shared_key, $subtype )
					&& ! isset( $wrong_subtype_keys[ $shared_key ] )
					&& self::same_value( $expected_shared, $specific_shared )
					&& self::same_value( array( $first_value, $second_value ), $raw_shared )
					&& self::same_value( $expected_owner, $specific_owner )
					&& isset( $registered_all[ $shared_key ] )
					&& ( $owner_type === $object_type ) === isset( $registered_all[ $owner_key ] )
					&& isset( $raw_all[ $owner_key ] )
					&& isset( $raw_all[ $shared_key ] ),
				"metadata cache and registry boundaries hold for {$object_type}",
				array(
					'objectType'       => $object_type,
					'ownerType'        => $owner_type,
					'subtype'          => $subtype,
					'sharedKey'        => $shared_key,
					'ownerKey'         => $owner_key,
					'sharedSingle'     => $shared_single,
					'specificShared'   => $specific_shared,
					'specificOwner'    => $specific_owner,
					'registeredAllKeys' => is_array( $registered_all ) ? array_keys( $registered_all ) : $registered_all,
					'rawAllKeys'       => is_array( $raw_all ) ? array_keys( $raw_all ) : $raw_all,
					'wrongSubtypeKeys' => array_keys( $wrong_subtype_keys ),
					'index'            => $index,
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.boundaries.object-type-subtype-cache-isolation',
			array() === $failures,
			array(
				'objectId'  => $object_id,
				'ownerType' => $owner_type,
				'failures'  => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_sanitize_and_auth_callback_locality( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case      = $ctx->fork( 'callbacks-' . $object_type );
			$object_id = 3100 + $case->int( 1, 6000 ) + $index;
			$subtype   = self::object_subtype( $case, $object_type );
			$meta_key  = self::meta_key( $case, 'callback key' );
			$value     = self::metadata_value( $case->fork( 'value' ) );

			$calls = array(
				'genericSanitize' => 0,
				'subtypeSanitize' => 0,
				'genericAuth'     => 0,
				'subtypeAuth'     => 0,
			);

			$generic_sanitize = static function ( $meta_value, $key, $type ) use ( &$calls, $meta_key, $object_type ) {
				++$calls['genericSanitize'];
				return array(
					'scope'      => 'generic',
					'keyMatches' => $meta_key === $key,
					'type'       => $type,
					'typeMatch'  => $object_type === $type,
					'value'      => $meta_value,
				);
			};
			$subtype_sanitize = static function ( $meta_value, $key, $type, $subtype_value ) use (
				&$calls,
				$meta_key,
				$object_type,
				$subtype
			) {
				++$calls['subtypeSanitize'];
				return array(
					'scope'        => 'subtype',
					'keyMatches'   => $meta_key === $key,
					'type'         => $type,
					'typeMatch'    => $object_type === $type,
					'subtype'      => $subtype_value,
					'subtypeMatch' => $subtype === $subtype_value,
					'value'        => $meta_value,
				);
			};
			$generic_auth = static function ( $allowed, $key, $object_id_arg, $user_id, $cap, $caps ) use (
				&$calls,
				$meta_key,
				$object_id
			) {
				unset( $allowed, $user_id, $cap, $caps );
				++$calls['genericAuth'];
				return $meta_key === $key && $object_id === (int) $object_id_arg;
			};
			$subtype_auth = static function ( $allowed, $key, $object_id_arg, $user_id, $cap, $caps ) use (
				&$calls,
				$meta_key,
				$object_id
			) {
				unset( $allowed, $user_id, $cap, $caps );
				++$calls['subtypeAuth'];
				return $meta_key === $key && $object_id === (int) $object_id_arg;
			};

			$registered_global = \register_meta(
				$object_type,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => $generic_sanitize,
					'auth_callback'     => $generic_auth,
				)
			);
			$registered_subtype = self::register_subtype_meta(
				$object_type,
				$subtype,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => $subtype_sanitize,
					'auth_callback'     => $subtype_auth,
				)
			);

			$subtype_sanitized = \sanitize_meta( $meta_key, $value, $object_type, $subtype );
			$generic_sanitized = \sanitize_meta( $meta_key, $value, $object_type, $subtype . '-other' );
			$missing_sanitized = \sanitize_meta( $meta_key . '-missing', $value, $object_type, $subtype );
			$subtype_auth_ok   = \apply_filters(
				"auth_{$object_type}_meta_{$meta_key}_for_{$subtype}",
				null,
				$meta_key,
				$object_id,
				0,
				'edit',
				array()
			);
			$generic_auth_ok   = \apply_filters(
				"auth_{$object_type}_meta_{$meta_key}",
				null,
				$meta_key,
				$object_id,
				0,
				'edit',
				array()
			);
			$calls_before_drop = $calls;

			$unregistered_subtype = \unregister_meta_key( $object_type, $meta_key, $subtype );
			$after_subtype_drop   = \sanitize_meta( $meta_key, $value, $object_type, $subtype );
			$subtype_filter_gone  = false === \has_filter(
				"sanitize_{$object_type}_meta_{$meta_key}_for_{$subtype}",
				$subtype_sanitize
			)
				&& false === \has_filter( "auth_{$object_type}_meta_{$meta_key}_for_{$subtype}", $subtype_auth );

			$unregistered_global = \unregister_meta_key( $object_type, $meta_key );
			$global_filter_gone  = false === \has_filter( "sanitize_{$object_type}_meta_{$meta_key}", $generic_sanitize )
				&& false === \has_filter( "auth_{$object_type}_meta_{$meta_key}", $generic_auth );

			self::collect_failure(
				$failures,
				true === $registered_global
					&& true === $registered_subtype
					&& 'subtype' === ( $subtype_sanitized['scope'] ?? null )
					&& 'generic' === ( $generic_sanitized['scope'] ?? null )
					&& self::same_value( $value, $missing_sanitized )
					&& true === $subtype_auth_ok
					&& true === $generic_auth_ok
					&& array(
						'genericSanitize' => 1,
						'subtypeSanitize' => 1,
						'genericAuth'     => 1,
						'subtypeAuth'     => 1,
					) === $calls_before_drop
					&& true === $unregistered_subtype
					&& 'generic' === ( $after_subtype_drop['scope'] ?? null )
					&& $subtype_filter_gone
					&& true === $unregistered_global
					&& $global_filter_gone,
				"sanitize/auth callbacks for {$object_type} are local to generic and subtype hooks",
				array(
					'objectType'          => $object_type,
					'subtype'             => $subtype,
					'metaKey'             => $meta_key,
					'subtypeSanitized'    => $subtype_sanitized,
					'genericSanitized'    => $generic_sanitized,
					'missingSanitized'    => $missing_sanitized,
					'afterSubtypeDrop'    => $after_subtype_drop,
					'callsBeforeDrop'     => $calls_before_drop,
					'callsAfterDrop'      => $calls,
					'registered'          => array( $registered_global, $registered_subtype ),
					'unregistered'        => array( $unregistered_subtype, $unregistered_global ),
					'subtypeFilterGone'   => $subtype_filter_gone,
					'globalFilterGone'    => $global_filter_gone,
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.callbacks.sanitize-auth-locality',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_protected_meta_locality( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$object_type = $ctx->choice( self::OBJECT_TYPES );
		$other_type  = self::different_object_type( $object_type );
		$private_key = '_' . self::meta_key( $ctx->fork( 'private' ), 'private' );
		$plain_key   = self::meta_key( $ctx->fork( 'plain' ), 'plain' );
		$control_key = chr( 0 ) . '_' . self::meta_key( $ctx->fork( 'control' ), 'control' );
		$records     = array();

		$default_private = \is_protected_meta( $private_key, $object_type );
		$default_plain   = \is_protected_meta( $plain_key, $object_type );
		$default_control = \is_protected_meta( $control_key, $object_type );

		$filter = static function ( $protected, $meta_key, $meta_type ) use (
			&$records,
			$object_type,
			$private_key,
			$plain_key
		) {
			$records[] = array(
				'protected' => $protected,
				'metaKey'   => $meta_key,
				'metaType'  => $meta_type,
			);

			if ( $object_type === $meta_type && $private_key === $meta_key ) {
				return false;
			}

			if ( $object_type === $meta_type && $plain_key === $meta_key ) {
				return true;
			}

			return $protected;
		};
		\add_filter( 'is_protected_meta', $filter, 10, 3 );

		$filtered_private       = \is_protected_meta( $private_key, $object_type );
		$filtered_private_other = \is_protected_meta( $private_key, $other_type );
		$filtered_plain         = \is_protected_meta( $plain_key, $object_type );
		$filtered_plain_other   = \is_protected_meta( $plain_key, $other_type );

		$registered_private = \register_meta( $object_type, $private_key, array( 'type' => 'string' ) );
		$registered_plain   = \register_meta( $object_type, $plain_key, array( 'type' => 'string' ) );
		$registered_keys    = \get_registered_meta_keys( $object_type );
		\remove_filter( 'is_protected_meta', $filter, 10 );

		$private_auth = $registered_keys[ $private_key ]['auth_callback'] ?? null;
		$plain_auth   = $registered_keys[ $plain_key ]['auth_callback'] ?? null;

		$ok = true === $default_private
			&& false === $default_plain
			&& true === $default_control
			&& false === $filtered_private
			&& true === $filtered_private_other
			&& true === $filtered_plain
			&& false === $filtered_plain_other
			&& true === $registered_private
			&& true === $registered_plain
			&& '__return_true' === $private_auth
			&& '__return_false' === $plain_auth
			&& count( $records ) >= 6;

		return self::result(
			$ctx,
			'metadata.protected-meta.filter-locality-and-auth-defaults',
			$ok,
			array(
				'objectType'           => $object_type,
				'otherType'            => $other_type,
				'privateKey'           => $private_key,
				'plainKey'             => $plain_key,
				'defaultPrivate'       => $default_private,
				'defaultPlain'         => $default_plain,
				'defaultControl'       => $default_control,
				'filteredPrivate'      => $filtered_private,
				'filteredPrivateOther' => $filtered_private_other,
				'filteredPlain'        => $filtered_plain,
				'filteredPlainOther'   => $filtered_plain_other,
				'privateAuth'          => $private_auth,
				'plainAuth'            => $plain_auth,
				'records'              => $records,
			)
		);
	}

	private static function check_no_db_crud_filters_and_cache( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case        = $ctx->fork( 'crud-' . $object_type );
			$object_id   = 4100 + $case->int( 1, 5000 ) + $index;
			$subtype     = self::object_subtype( $case, $object_type );
			$meta_key    = self::meta_key( $case, 'crud' );
			$other_key   = self::meta_key( $case->fork( 'other' ), 'crud-other' );
			$value       = self::metadata_value( $case->fork( 'value' ) );
			$read_value  = self::metadata_value( $case->fork( 'read-value' ) );
			$cache_value = self::metadata_value( $case->fork( 'cache-value' ) );
			$cache_value_two = self::metadata_value( $case->fork( 'cache-value-two' ) );
			$table_exists    = false !== \_get_meta_table( $object_type );

			$calls = array(
				'sanitize' => 0,
				'add'      => 0,
				'update'   => 0,
				'delete'   => 0,
				'get'      => 0,
			);

			$subtype_filter = static function ( $current, $id ) use ( $object_id, $subtype ) {
				return (int) $id === $object_id ? $subtype : $current;
			};
			\add_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10, 2 );

			$sanitize = static function ( $meta_value, $key, $type, $subtype_value = '' ) use (
				&$calls,
				$meta_key,
				$object_type,
				$subtype
			) {
				++$calls['sanitize'];
				return array(
					'sanitized' => true,
					'keyMatch'  => $meta_key === $key,
					'typeMatch' => $object_type === $type,
					'subtype'   => $subtype_value,
					'subMatch'  => '' === $subtype_value || $subtype === $subtype_value,
					'value'     => $meta_value,
				);
			};
			self::register_subtype_meta(
				$object_type,
				$subtype,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => $sanitize,
				)
			);

			$add_filter = static function ( $check, $id, $key, $meta_value, $unique ) use ( &$calls, $object_id, $meta_key ) {
				unset( $check, $unique );
				++$calls['add'];
				if ( (int) $id === $object_id && $key === $meta_key ) {
					return 7001;
				}
				return false;
			};
			$update_filter = static function ( $check, $id, $key, $meta_value, $prev_value ) use (
				&$calls,
				$object_id,
				$meta_key
			) {
				unset( $check, $meta_value, $prev_value );
				++$calls['update'];
				return (int) $id === $object_id && $key === $meta_key;
			};
			$delete_filter = static function ( $check, $id, $key, $meta_value, $delete_all ) use (
				&$calls,
				$object_id,
				$meta_key
			) {
				unset( $check, $meta_value, $delete_all );
				++$calls['delete'];
				return (int) $id === $object_id && $key === $meta_key;
			};
			$get_filter = static function ( $check, $id, $key, $single, $type ) use (
				&$calls,
				$object_id,
				$meta_key,
				$read_value,
				$object_type
			) {
				unset( $check );
				++$calls['get'];
				if ( (int) $id !== $object_id || $key !== $meta_key || $type !== $object_type ) {
					return null;
				}

				return $single ? array( $read_value, 'ignored' ) : array( $read_value, 'second' );
			};

			\add_filter( "add_{$object_type}_metadata", $add_filter, 10, 5 );
			\add_filter( "update_{$object_type}_metadata", $update_filter, 10, 5 );
			\add_filter( "delete_{$object_type}_metadata", $delete_filter, 10, 5 );
			\add_filter( "get_{$object_type}_metadata", $get_filter, 10, 5 );

			$slashed_meta_key  = \wp_slash( $meta_key );
			$slashed_other_key = \wp_slash( $other_key );
			$add_exact         = \add_metadata( $object_type, $object_id, $slashed_meta_key, $value, true );
			$add_mismatch      = \add_metadata( $object_type, $object_id, $slashed_other_key, $value, false );
			$update_exact      = \update_metadata( $object_type, $object_id, $slashed_meta_key, $value );
			$update_mismatch   = \update_metadata( $object_type, $object_id, $slashed_other_key, $value );
			$delete_exact      = \delete_metadata( $object_type, $object_id, $slashed_meta_key, $value );
			$delete_mismatch   = \delete_metadata( $object_type, $object_id, $slashed_other_key, $value );

			$exists_filtered = \metadata_exists( $object_type, $object_id, $meta_key );
			$raw_single      = \get_metadata_raw( $object_type, $object_id, $meta_key, true );
			$raw_multi       = \get_metadata_raw( $object_type, $object_id, $meta_key, false );
			$get_single      = \get_metadata( $object_type, $object_id, $meta_key, true );

			\remove_filter( "get_{$object_type}_metadata", $get_filter, 10 );

			$cache_id  = $object_id + 10000;
			$cache_key = self::meta_key( $case->fork( 'cache-key' ), "cache key {$object_type}" );
			\wp_cache_set(
				$cache_id,
				array(
					$cache_key => array( \maybe_serialize( $cache_value ), \maybe_serialize( $cache_value_two ) ),
					'sentinel' => array( \maybe_serialize( 'sentinel' ) ),
				),
				$object_type . '_meta'
			);

			$cache_exists        = \metadata_exists( $object_type, $cache_id, $cache_key );
			$cache_raw_single    = \get_metadata_raw( $object_type, $cache_id, $cache_key, true );
			$cache_raw_multi     = \get_metadata_raw( $object_type, $cache_id, $cache_key, false );
			$cache_missing_single = \get_metadata( $object_type, $cache_id, $other_key, true );
			$cache_missing_multi  = \get_metadata( $object_type, $cache_id, $other_key, false );

			\remove_filter( "add_{$object_type}_metadata", $add_filter, 10 );
			\remove_filter( "update_{$object_type}_metadata", $update_filter, 10 );
			\remove_filter( "delete_{$object_type}_metadata", $delete_filter, 10 );
			\remove_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10 );

			$invalid_after_remove = array(
				'add'    => \add_metadata( $object_type, 0, $meta_key, $value ),
				'update' => \update_metadata( $object_type, 0, $meta_key, $value ),
				'delete' => \delete_metadata( $object_type, 0, $meta_key, $value ),
			);

			$expected_add_exact       = $table_exists ? 7001 : false;
			$expected_update_exact    = $table_exists;
			$expected_delete_exact    = $table_exists;
			$expected_crud_call_count = $table_exists ? 2 : 0;
			$expected_sanitize_count  = $table_exists ? 2 : 0;
			$expected_get_single      = null === $read_value ? '' : $read_value;

			self::collect_failure(
				$failures,
				$expected_add_exact === $add_exact
					&& false === $add_mismatch
					&& $expected_update_exact === $update_exact
					&& false === $update_mismatch
					&& $expected_delete_exact === $delete_exact
					&& false === $delete_mismatch
					&& $expected_crud_call_count === $calls['add']
					&& $expected_crud_call_count === $calls['update']
					&& $expected_crud_call_count === $calls['delete']
					&& $expected_sanitize_count === $calls['sanitize']
					&& true === $exists_filtered
					&& self::same_value( $read_value, $raw_single )
					&& self::same_value( array( $read_value, 'second' ), $raw_multi )
					&& self::same_value( $expected_get_single, $get_single )
					&& true === $cache_exists
					&& self::same_value( $cache_value, $cache_raw_single )
					&& self::same_value( array( $cache_value, $cache_value_two ), $cache_raw_multi )
					&& '' === $cache_missing_single
					&& array() === $cache_missing_multi
					&& array( 'add' => false, 'update' => false, 'delete' => false ) === $invalid_after_remove
					&& false === \has_filter( "add_{$object_type}_metadata", $add_filter )
					&& false === \has_filter( "update_{$object_type}_metadata", $update_filter )
					&& false === \has_filter( "delete_{$object_type}_metadata", $delete_filter ),
				"no-DB {$object_type} metadata CRUD short-circuits and cache reads are deterministic",
				array(
					'objectType'          => $object_type,
					'tableExists'         => $table_exists,
					'metaKey'             => $meta_key,
					'addExact'            => $add_exact,
					'addMismatch'         => $add_mismatch,
					'updateExact'         => $update_exact,
					'updateMismatch'      => $update_mismatch,
					'deleteExact'         => $delete_exact,
					'deleteMismatch'      => $delete_mismatch,
					'calls'               => $calls,
					'rawSingle'           => $raw_single,
					'rawMulti'            => $raw_multi,
					'cacheRawSingle'      => $cache_raw_single,
					'cacheRawMulti'       => $cache_raw_multi,
					'expectedGetSingle'   => $expected_get_single,
					'cacheMissingSingle'  => $cache_missing_single,
					'cacheMissingMulti'   => $cache_missing_multi,
					'invalidAfterRemove'  => $invalid_after_remove,
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.no-db-crud.filters-cache-fail-closed',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'note'     => 'Term CRUD filters are unreachable with the no-DB stub because termmeta is not exposed.',
			)
		);
	}

	private static function check_no_db_by_mid_filters_and_fail_closed( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case          = $ctx->fork( 'by-mid-' . $object_type );
			$meta_id       = 7200 + $case->int( 1, 6000 ) + $index;
			$meta_id_arg   = $case->bool() ? (string) $meta_id . '.0' : $meta_id;
			$mismatch_id   = $meta_id + 11000 + $index;
			$meta_value    = self::metadata_value( $case->fork( 'value' ) );
			$meta_key_arg  = $case->choice(
				array(
					false,
					self::meta_key( $case->fork( 'meta-key' ), 'by-mid' ),
					array( 'invalid-key-shape' => $case->identifier( 3, 8 ) ),
				)
			);
			$table_exists  = false !== \_get_meta_table( $object_type );
			$get_records   = array();
			$update_records = array();
			$delete_records = array();

			$sentinel = (object) array(
				'meta_id'    => (string) $meta_id,
				'meta_key'   => is_string( $meta_key_arg ) ? $meta_key_arg : 'filtered-key',
				'meta_value' => self::metadata_value( $case->fork( 'read-value' ) ),
			);

			$update_short_circuit = $case->choice( array( true, false, 1, 0, '1', '', 'nonempty' ) );
			$delete_short_circuit = $case->choice( array( true, false, 1, 0, '1', '', 'nonempty' ) );

			$get_filter = static function ( $check, $id ) use ( &$get_records, $meta_id, $sentinel ) {
				unset( $check );
				$get_records[] = array(
					'metaId'     => $id,
					'metaIdType' => gettype( $id ),
					'target'     => $meta_id === $id,
				);

				return $meta_id === $id ? $sentinel : false;
			};
			$update_filter = static function ( $check, $id, $value, $key ) use (
				&$update_records,
				$meta_id,
				$update_short_circuit
			) {
				unset( $check );
				$target           = $meta_id === $id;
				$update_records[] = array(
					'metaId'     => $id,
					'metaIdType' => gettype( $id ),
					'target'     => $target,
					'value'      => $value,
					'metaKey'    => $key,
				);

				return $target ? $update_short_circuit : false;
			};
			$delete_filter = static function ( $check, $id ) use (
				&$delete_records,
				$meta_id,
				$delete_short_circuit
			) {
				unset( $check );
				$target           = $meta_id === $id;
				$delete_records[] = array(
					'metaId'     => $id,
					'metaIdType' => gettype( $id ),
					'target'     => $target,
				);

				return $target ? $delete_short_circuit : false;
			};

			\add_filter( "get_{$object_type}_metadata_by_mid", $get_filter, 10, 2 );
			\add_filter( "update_{$object_type}_metadata_by_mid", $update_filter, 10, 4 );
			\add_filter( "delete_{$object_type}_metadata_by_mid", $delete_filter, 10, 2 );

			try {
				$get_exact       = \get_metadata_by_mid( $object_type, $meta_id_arg );
				$get_mismatch    = \get_metadata_by_mid( $object_type, $mismatch_id );
				$update_exact    = \update_metadata_by_mid( $object_type, $meta_id_arg, $meta_value, $meta_key_arg );
				$update_mismatch = \update_metadata_by_mid( $object_type, $mismatch_id, $meta_value, $meta_key_arg );
				$delete_exact    = \delete_metadata_by_mid( $object_type, $meta_id_arg );
				$delete_mismatch = \delete_metadata_by_mid( $object_type, $mismatch_id );

				$invalid_id_results = array();
				foreach (
					array(
						0,
						-$meta_id,
						$meta_id + 0.25,
						array( $meta_id ),
						'not-a-mid-' . $case->identifier( 3, 8 ),
					) as $invalid_id
				) {
					$invalid_id_results[] = array(
						'id'     => $invalid_id,
						'get'    => \get_metadata_by_mid( $object_type, $invalid_id ),
						'update' => \update_metadata_by_mid( $object_type, $invalid_id, $meta_value, $meta_key_arg ),
						'delete' => \delete_metadata_by_mid( $object_type, $invalid_id ),
					);
				}

				$invalid_type = 'invalid-' . self::slug( $case->fork( 'invalid-type' ), 'meta-type' );
				$invalid_type_calls = array(
					'get'    => 0,
					'update' => 0,
					'delete' => 0,
				);
				$invalid_get_filter = static function ( $check, $id ) use ( &$invalid_type_calls ) {
					unset( $check, $id );
					++$invalid_type_calls['get'];
					return (object) array( 'unexpected' => true );
				};
				$invalid_update_filter = static function ( $check, $id, $value, $key ) use ( &$invalid_type_calls ) {
					unset( $check, $id, $value, $key );
					++$invalid_type_calls['update'];
					return true;
				};
				$invalid_delete_filter = static function ( $check, $id ) use ( &$invalid_type_calls ) {
					unset( $check, $id );
					++$invalid_type_calls['delete'];
					return true;
				};

				\add_filter( "get_{$invalid_type}_metadata_by_mid", $invalid_get_filter, 10, 2 );
				\add_filter( "update_{$invalid_type}_metadata_by_mid", $invalid_update_filter, 10, 4 );
				\add_filter( "delete_{$invalid_type}_metadata_by_mid", $invalid_delete_filter, 10, 2 );

				try {
					$invalid_type_results = array(
						'get'    => \get_metadata_by_mid( $invalid_type, $meta_id_arg ),
						'update' => \update_metadata_by_mid( $invalid_type, $meta_id_arg, $meta_value, $meta_key_arg ),
						'delete' => \delete_metadata_by_mid( $invalid_type, $meta_id_arg ),
					);
				} finally {
					\remove_filter( "get_{$invalid_type}_metadata_by_mid", $invalid_get_filter, 10 );
					\remove_filter( "update_{$invalid_type}_metadata_by_mid", $invalid_update_filter, 10 );
					\remove_filter( "delete_{$invalid_type}_metadata_by_mid", $invalid_delete_filter, 10 );
				}
			} finally {
				\remove_filter( "get_{$object_type}_metadata_by_mid", $get_filter, 10 );
				\remove_filter( "update_{$object_type}_metadata_by_mid", $update_filter, 10 );
				\remove_filter( "delete_{$object_type}_metadata_by_mid", $delete_filter, 10 );
			}

			$expected_get_records = $table_exists
				? array(
					array(
						'metaId'     => $meta_id,
						'metaIdType' => 'integer',
						'target'     => true,
					),
					array(
						'metaId'     => $mismatch_id,
						'metaIdType' => 'integer',
						'target'     => false,
					),
				)
				: array();
			$expected_update_records = $table_exists
				? array(
					array(
						'metaId'     => $meta_id,
						'metaIdType' => 'integer',
						'target'     => true,
						'value'      => $meta_value,
						'metaKey'    => $meta_key_arg,
					),
					array(
						'metaId'     => $mismatch_id,
						'metaIdType' => 'integer',
						'target'     => false,
						'value'      => $meta_value,
						'metaKey'    => $meta_key_arg,
					),
				)
				: array();
			$expected_delete_records = $table_exists
				? array(
					array(
						'metaId'     => $meta_id,
						'metaIdType' => 'integer',
						'target'     => true,
					),
					array(
						'metaId'     => $mismatch_id,
						'metaIdType' => 'integer',
						'target'     => false,
					),
				)
				: array();

			$invalid_ids_fail_closed = true;
			foreach ( $invalid_id_results as $invalid_result ) {
				if (
					false !== $invalid_result['get']
					|| false !== $invalid_result['update']
					|| false !== $invalid_result['delete']
				) {
					$invalid_ids_fail_closed = false;
					break;
				}
			}

			self::collect_failure(
				$failures,
				( $table_exists ? self::same_value( $sentinel, $get_exact ) : false === $get_exact )
					&& false === $get_mismatch
					&& ( $table_exists ? (bool) $update_short_circuit === $update_exact : false === $update_exact )
					&& false === $update_mismatch
					&& ( $table_exists ? (bool) $delete_short_circuit === $delete_exact : false === $delete_exact )
					&& false === $delete_mismatch
					&& self::same_value( $expected_get_records, $get_records )
					&& self::same_value( $expected_update_records, $update_records )
					&& self::same_value( $expected_delete_records, $delete_records )
					&& $invalid_ids_fail_closed
					&& array( 'get' => false, 'update' => false, 'delete' => false ) === $invalid_type_results
					&& array( 'get' => 0, 'update' => 0, 'delete' => 0 ) === $invalid_type_calls
					&& false === \has_filter( "get_{$object_type}_metadata_by_mid", $get_filter )
					&& false === \has_filter( "update_{$object_type}_metadata_by_mid", $update_filter )
					&& false === \has_filter( "delete_{$object_type}_metadata_by_mid", $delete_filter ),
				"no-DB {$object_type} by-mid filters preserve payloads and invalid inputs fail closed",
				array(
					'objectType'              => $object_type,
					'tableExists'             => $table_exists,
					'metaIdArg'               => $meta_id_arg,
					'metaValue'               => $meta_value,
					'metaKeyArg'              => $meta_key_arg,
					'updateShortCircuit'      => $update_short_circuit,
					'deleteShortCircuit'      => $delete_short_circuit,
					'getExact'                => self::describe_value( $get_exact ),
					'getMismatch'             => $get_mismatch,
					'updateExact'             => $update_exact,
					'updateMismatch'          => $update_mismatch,
					'deleteExact'             => $delete_exact,
					'deleteMismatch'          => $delete_mismatch,
					'getRecords'              => $get_records,
					'expectedGetRecords'      => $expected_get_records,
					'updateRecords'           => $update_records,
					'expectedUpdateRecords'   => $expected_update_records,
					'deleteRecords'           => $delete_records,
					'expectedDeleteRecords'   => $expected_delete_records,
					'invalidIdResults'        => $invalid_id_results,
					'invalidType'             => $invalid_type,
					'invalidTypeResults'      => $invalid_type_results,
					'invalidTypeCalls'        => $invalid_type_calls,
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.no-db-by-mid.filters-payloads-fail-closed',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function check_in_memory_crud_cache_invalidation( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! self::has_in_memory_metadata_store() ) {
			return $ctx->skip(
				'metadata.in-memory-crud-cache.stub-available',
				'Metadata CRUD cache invalidation is intentionally limited to the component fuzzer in-memory DB stub.'
			);
		}

		self::reset_runtime();

		$failures = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case             = $ctx->fork( 'crud-cache-' . $object_type );
			$object_id        = 6100 + $case->int( 1, 6000 ) + $index;
			$other_object_id  = $object_id + 9000 + $index;
			$cached_id        = $object_id + 19000;
			$primed_id        = $object_id + 29000;
			$missing_id       = $object_id + 39000;
			$group            = $object_type . '_meta';
			$object_id_column = $object_type . '_id';
			$meta_key         = self::meta_key( $case->fork( 'meta-key' ), 'crud-cache' );
			$prime_key        = self::meta_key( $case->fork( 'prime-key' ), 'prime-cache' );
			$first_value      = self::metadata_db_value( $case->fork( 'first' ) );
			$updated_value    = self::metadata_db_value( $case->fork( 'updated' ) );
			$second_value     = self::metadata_db_value( $case->fork( 'second' ) );
			$by_mid_value     = self::metadata_db_value( $case->fork( 'by-mid' ) );
			$other_value      = self::metadata_db_value( $case->fork( 'other' ) );
			$cache_hit_value  = self::metadata_db_value( $case->fork( 'cache-hit' ) );
			$db_prime_value   = self::metadata_db_value( $case->fork( 'db-prime' ) );

			\wp_cache_set(
				$object_id,
				array(
					'stale_' . $meta_key => array( \maybe_serialize( 'stale' ) ),
				),
				$group
			);

			$add_id            = \add_metadata(
				$object_type,
				$object_id,
				\wp_slash( $meta_key ),
				\wp_slash( $first_value ),
				true
			);
			$cache_after_add   = \wp_cache_get( $object_id, $group );
			$read_after_add    = \get_metadata_raw( $object_type, $object_id, $meta_key, false );
			$cache_after_prime = \wp_cache_get( $object_id, $group );
			$same_update       = \update_metadata(
				$object_type,
				$object_id,
				\wp_slash( $meta_key ),
				\wp_slash( $first_value )
			);
			$cache_after_same  = \wp_cache_get( $object_id, $group );
			$update_result     = \update_metadata(
				$object_type,
				$object_id,
				\wp_slash( $meta_key ),
				\wp_slash( $updated_value )
			);
			$cache_after_update = \wp_cache_get( $object_id, $group );
			$read_after_update = \get_metadata_raw( $object_type, $object_id, $meta_key, true );

			$second_id          = \add_metadata(
				$object_type,
				$object_id,
				\wp_slash( $meta_key ),
				\wp_slash( $second_value ),
				false
			);
			$cache_after_second = \wp_cache_get( $object_id, $group );
			$multi_after_second = \get_metadata_raw( $object_type, $object_id, $meta_key, false );
			$by_mid_before      = \get_metadata_by_mid( $object_type, $second_id );
			$by_mid_update      = \update_metadata_by_mid( $object_type, $second_id, $by_mid_value, $meta_key );
			$cache_after_by_mid_update = \wp_cache_get( $object_id, $group );
			$by_mid_after       = \get_metadata_by_mid( $object_type, $second_id );
			$delete_mid         = \delete_metadata_by_mid( $object_type, $second_id );
			$cache_after_delete_mid = \wp_cache_get( $object_id, $group );
			$after_delete_mid   = \get_metadata_raw( $object_type, $object_id, $meta_key, false );

			$other_add_id = \add_metadata(
				$object_type,
				$other_object_id,
				\wp_slash( $meta_key ),
				\wp_slash( $other_value ),
				false
			);
			$other_before_delete_all = \get_metadata_raw( $object_type, $other_object_id, $meta_key, true );
			$delete_all              = \delete_metadata( $object_type, 0, \wp_slash( $meta_key ), '', true );
			$cache_after_delete_all  = \wp_cache_get( $object_id, $group );
			$other_cache_after_delete_all = \wp_cache_get( $other_object_id, $group );
			$raw_after_delete_all    = \get_metadata_raw( $object_type, $object_id, $meta_key, true );
			$default_after_delete_all = \get_metadata( $object_type, $object_id, $meta_key, true );

			$prime_add_id = \add_metadata(
				$object_type,
				$primed_id,
				\wp_slash( $prime_key ),
				\wp_slash( $db_prime_value ),
				false
			);
			\wp_cache_set(
				$cached_id,
				array(
					$prime_key => array( \maybe_serialize( $cache_hit_value ) ),
				),
				$group
			);
			$primed_cache  = \update_meta_cache( $object_type, array( $cached_id, $primed_id, $missing_id ) );
			$cached_read   = \get_metadata_raw( $object_type, $cached_id, $prime_key, true );
			$db_read       = \get_metadata_raw( $object_type, $primed_id, $prime_key, true );
			$missing_cache = \wp_cache_get( $missing_id, $group );

			self::collect_failure(
				$failures,
				is_int( $add_id )
					&& $add_id > 0
					&& false === $cache_after_add
					&& self::same_value( array( $first_value ), $read_after_add )
					&& false === $same_update
					&& self::same_value( $cache_after_prime, $cache_after_same )
					&& true === $update_result
					&& false === $cache_after_update
					&& self::same_value( $updated_value, $read_after_update )
					&& is_int( $second_id )
					&& $second_id > $add_id
					&& false === $cache_after_second
					&& self::same_value( array( $updated_value, $second_value ), $multi_after_second )
					&& $by_mid_before instanceof \stdClass
					&& $meta_key === $by_mid_before->meta_key
					&& $object_id === (int) $by_mid_before->{$object_id_column}
					&& self::same_value( $second_value, $by_mid_before->meta_value )
					&& true === $by_mid_update
					&& false === $cache_after_by_mid_update
					&& $by_mid_after instanceof \stdClass
					&& self::same_value( $by_mid_value, $by_mid_after->meta_value )
					&& true === $delete_mid
					&& false === $cache_after_delete_mid
					&& self::same_value( array( $updated_value ), $after_delete_mid )
					&& is_int( $other_add_id )
					&& $other_add_id > 0
					&& self::same_value( $other_value, $other_before_delete_all )
					&& true === $delete_all
					&& false === $cache_after_delete_all
					&& false === $other_cache_after_delete_all
					&& null === $raw_after_delete_all
					&& '' === $default_after_delete_all
					&& is_int( $prime_add_id )
					&& is_array( $primed_cache )
					&& isset( $primed_cache[ $cached_id ], $primed_cache[ $primed_id ], $primed_cache[ $missing_id ] )
					&& array() === $primed_cache[ $missing_id ]
					&& array() === $missing_cache
					&& self::same_value( $cache_hit_value, $cached_read )
					&& self::same_value( $db_prime_value, $db_read ),
				"in-memory {$object_type} metadata CRUD invalidates and primes caches deterministically",
				array(
					'objectType'                 => $object_type,
					'objectId'                   => $object_id,
					'metaKey'                    => $meta_key,
					'addId'                      => $add_id,
					'secondId'                   => $second_id,
					'otherAddId'                 => $other_add_id,
					'primeAddId'                 => $prime_add_id,
					'readAfterAdd'               => $read_after_add,
					'readAfterUpdate'            => $read_after_update,
					'multiAfterSecond'           => $multi_after_second,
					'byMidBefore'                => self::describe_value( $by_mid_before ),
					'byMidAfter'                 => self::describe_value( $by_mid_after ),
					'afterDeleteMid'             => $after_delete_mid,
					'rawAfterDeleteAll'          => $raw_after_delete_all,
					'defaultAfterDeleteAll'      => $default_after_delete_all,
					'primedCacheKeys'            => is_array( $primed_cache ) ? array_keys( $primed_cache ) : $primed_cache,
					'missingCache'               => $missing_cache,
				)
			);
		}

		return self::result(
			$ctx,
			'metadata.in-memory-crud.cache-invalidation-and-priming',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
				'note'     => 'Uses only the component fuzzer in-memory wpdb stub; no live database writes are required.',
			)
		);
	}

	private static function check_lazyloader_queue_and_cache( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$lazyloader  = new \WP_Metadata_Lazyloader();
		$object_ids  = array( 5101 + $ctx->int( 1, 500 ), 5102 + $ctx->int( 1, 500 ) );
		$queued_ids  = array( $object_ids[0], $object_ids[1], $object_ids[0] );
		$unique_ids  = array_values( array_unique( $object_ids ) );
		$extra_id    = 5999 + $ctx->int( 1, 500 );
		$cache_key   = self::meta_key( $ctx->fork( 'lazy-cache-key' ), 'lazy' );
		$cache_value = self::metadata_value( $ctx->fork( 'lazy-cache-value' ) );
		$events      = array();
		$cache_calls = array();

		$action = static function ( $queued_ids, $object_type, $loader ) use ( &$events, $lazyloader ) {
			$events[] = array(
				'ids'          => $queued_ids,
				'objectType'   => $object_type,
				'sameInstance' => $loader === $lazyloader,
			);
		};
		$cache_filter = static function ( $check, $ids ) use ( &$cache_calls, $cache_key, $cache_value ) {
			unset( $check );
			$cache_calls[] = $ids;
			foreach ( $ids as $id ) {
				\wp_cache_set(
					(int) $id,
					array(
						$cache_key => array( \maybe_serialize( $cache_value ) ),
					),
					'comment_meta'
				);
			}
			return true;
		};

		\add_action( 'metadata_lazyloader_queued_objects', $action, 10, 3 );
		\add_filter( 'update_comment_metadata_cache', $cache_filter, 10, 2 );

		$invalid_queue = $lazyloader->queue_objects( 'post', $object_ids );
		$invalid_reset = $lazyloader->reset_queue( 'user' );
		$lazyloader->queue_objects( 'comment', $queued_ids );
		$pending_before = self::get_object_property( $lazyloader, 'pending_objects' );
		$has_filter     = false !== \has_filter( 'get_comment_metadata', array( $lazyloader, 'lazyload_meta_callback' ) );
		$callback_value = $lazyloader->lazyload_meta_callback( null, $extra_id, '', false, 'comment' );
		$pending_after  = self::get_object_property( $lazyloader, 'pending_objects' );
		$has_after      = false !== \has_filter( 'get_comment_metadata', array( $lazyloader, 'lazyload_meta_callback' ) );
		$cached_first   = \get_metadata_raw( 'comment', $object_ids[0], $cache_key, true );
		$cached_extra   = \get_metadata_raw( 'comment', $extra_id, $cache_key, true );

		$lazyloader->queue_objects( 'term', array( $object_ids[0] ) );
		$term_has_filter = false !== \has_filter( 'get_term_metadata', array( $lazyloader, 'lazyload_meta_callback' ) );
		$term_reset      = $lazyloader->reset_queue( 'term' );
		$term_after      = false !== \has_filter( 'get_term_metadata', array( $lazyloader, 'lazyload_meta_callback' ) );

		\remove_action( 'metadata_lazyloader_queued_objects', $action, 10 );
		\remove_filter( 'update_comment_metadata_cache', $cache_filter, 10 );

		$pending_ids        = array_keys( $pending_before['comment'] ?? array() );
		$expected_cache_ids = array_merge( $unique_ids, array( $extra_id ) );

		$ok = $invalid_queue instanceof \WP_Error
			&& 'invalid_object_type' === $invalid_queue->get_error_code()
			&& $invalid_reset instanceof \WP_Error
			&& 'invalid_object_type' === $invalid_reset->get_error_code()
			&& $has_filter
			&& self::same_value( $unique_ids, $pending_ids )
			&& null === $callback_value
			&& isset( $pending_after['comment'] )
			&& array() === $pending_after['comment']
			&& ! $has_after
			&& isset( $cache_calls[0] )
			&& $expected_cache_ids === array_values( $cache_calls[0] )
			&& self::same_value( $cache_value, $cached_first )
			&& self::same_value( $cache_value, $cached_extra )
			&& isset( $events[0] )
			&& 'comment' === $events[0]['objectType']
			&& $queued_ids === $events[0]['ids']
			&& true === $events[0]['sameInstance']
			&& $term_has_filter
			&& null === $term_reset
			&& ! $term_after;

		return self::result(
			$ctx,
			'metadata.lazyloader.queue-reset-cache-invariants',
			$ok,
			array(
				'objectIds'        => $object_ids,
				'queuedIds'        => $queued_ids,
				'uniqueIds'        => $unique_ids,
				'extraId'          => $extra_id,
				'pendingBefore'    => $pending_before,
				'pendingIds'       => $pending_ids,
				'pendingAfter'     => $pending_after,
				'cacheCalls'       => $cache_calls,
				'cachedFirst'      => $cached_first,
				'cachedExtra'      => $cached_extra,
				'events'           => $events,
				'termHasFilter'    => $term_has_filter,
				'termAfterReset'   => $term_after,
			)
		);
	}

	private static function check_registered_metadata_by_object_subtype( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! function_exists( 'get_registered_metadata_by_object_subtype' ) ) {
			return self::check_registered_metadata_by_object_subtype_current_api( $ctx );
		}

		self::reset_runtime();

		$object_type = 'post';
		$subtype     = self::object_subtype( $ctx, $object_type );
		$meta_key    = self::meta_key( $ctx, 'by-subtype' );
		self::register_subtype_meta(
			$object_type,
			$subtype,
			$meta_key,
			array(
				'type'   => 'string',
				'single' => true,
			)
		);

		try {
			$reflection = new \ReflectionFunction( 'get_registered_metadata_by_object_subtype' );
			$args       = array( $object_type, $subtype );
			if ( $reflection->getNumberOfParameters() >= 3 ) {
				$args[] = $meta_key;
			}
			$value = $reflection->invokeArgs( $args );
		} catch ( \Throwable $e ) {
			return $ctx->fail(
				'metadata.registered-metadata-by-object-subtype.callable',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		}

		return self::result(
			$ctx,
			'metadata.registered-metadata-by-object-subtype.callable',
			is_array( $value ),
			array(
				'objectType' => $object_type,
				'subtype'    => $subtype,
				'metaKey'    => $meta_key,
				'value'      => $value,
			)
		);
	}

	private static function check_registered_metadata_by_object_subtype_current_api( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$failures = array();
		$cases    = array();

		foreach ( self::OBJECT_TYPES as $index => $object_type ) {
			$case            = $ctx->fork( 'current-api-' . $object_type );
			$object_id       = 6400 + ( $index * 1000 ) + $case->int( 1, 900 );
			$other_object_id = $object_id + 50000;
			$subtype         = self::object_subtype( $case, $object_type );
			$other_subtype   = $subtype . '-other';
			$meta_key        = self::meta_key( $case, 'by-subtype-current-api' );
			$meta_value      = 'stored-' . $case->identifier( 4, 10 );
			$default         = 'default-' . $case->identifier( 4, 10 );
			$raw_value       = ' raw ' . $case->identifier( 3, 8 );
			$sanitized       = 'sanitized-' . $case->identifier( 4, 10 );
			$auth_log        = array();
			$sanitize_log    = array();

			$subtype_filter = static function ( $current, $id ) use ( $object_id, $other_object_id, $subtype, $other_subtype ) {
				if ( (int) $id === $object_id ) {
					return $subtype;
				}
				if ( (int) $id === $other_object_id ) {
					return $other_subtype;
				}
				return $current;
			};
			$sanitize_callback = static function ( $value, $key, $type, $filtered_subtype ) use ( &$sanitize_log, $meta_key, $object_type, $subtype, $sanitized ) {
				$sanitize_log[] = array(
					'value'   => $value,
					'key'     => $key,
					'type'    => $type,
					'subtype' => $filtered_subtype,
				);

				return $meta_key === $key && $object_type === $type && $subtype === $filtered_subtype ? $sanitized : $value;
			};
			$auth_callback = static function ( $allowed, $key, $filtered_object_id, $user_id, $cap, $caps ) use ( &$auth_log, $meta_key, $object_id ) {
				$auth_log[] = array(
					'allowed'  => $allowed,
					'key'      => $key,
					'objectId' => $filtered_object_id,
					'userId'   => $user_id,
					'cap'      => $cap,
					'caps'     => $caps,
				);

				return $meta_key === $key && $object_id === (int) $filtered_object_id;
			};

			\add_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10, 2 );
			$registered = self::register_subtype_meta(
				$object_type,
				$subtype,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => $default,
					'sanitize_callback' => $sanitize_callback,
					'auth_callback'     => $auth_callback,
				)
			);

			\wp_cache_set(
				$object_id,
				array(
					$meta_key => array( \maybe_serialize( $meta_value ) ),
				),
				$object_type . '_meta'
			);

			$registered_keys       = \get_registered_meta_keys( $object_type, $subtype );
			$other_registered_keys = \get_registered_meta_keys( $object_type, $other_subtype );
			$exists_subtype        = \registered_meta_key_exists( $object_type, $meta_key, $subtype );
			$exists_other_subtype  = \registered_meta_key_exists( $object_type, $meta_key, $other_subtype );
			$exists_global         = \registered_meta_key_exists( $object_type, $meta_key );
			$registered_meta       = \get_registered_metadata( $object_type, $object_id, $meta_key );
			$other_registered_meta = \get_registered_metadata( $object_type, $other_object_id, $meta_key );
			$default_value         = \get_metadata_default( $object_type, $object_id, $meta_key, true );
			$other_default_value   = \get_metadata_default( $object_type, $other_object_id, $meta_key, true );
			$sanitized_value       = \sanitize_meta( $meta_key, $raw_value, $object_type, $subtype );
			$other_sanitized_value = \sanitize_meta( $meta_key, $raw_value, $object_type, $other_subtype );
			$auth_allowed          = \apply_filters(
				"auth_{$object_type}_meta_{$meta_key}_for_{$subtype}",
				null,
				$meta_key,
				$object_id,
				0,
				'edit',
				array()
			);
			$other_auth_allowed    = \apply_filters(
				"auth_{$object_type}_meta_{$meta_key}_for_{$other_subtype}",
				null,
				$meta_key,
				$other_object_id,
				0,
				'edit',
				array()
			);

			$unregistered     = \unregister_meta_key( $object_type, $meta_key, $subtype );
			$after_unregister = \get_registered_metadata( $object_type, $object_id, $meta_key );
			\remove_filter( "get_object_subtype_{$object_type}", $subtype_filter, 10 );
			$filter_restored = false === \has_filter( "get_object_subtype_{$object_type}", $subtype_filter )
				&& false === \has_filter( "sanitize_{$object_type}_meta_{$meta_key}_for_{$subtype}", $sanitize_callback )
				&& false === \has_filter( "auth_{$object_type}_meta_{$meta_key}_for_{$subtype}", $auth_callback );

			$case_details = array(
				'objectType'           => $object_type,
				'subtype'              => $subtype,
				'otherSubtype'         => $other_subtype,
				'metaKey'              => $meta_key,
				'registeredKeys'       => $registered_keys,
				'otherRegisteredKeys'  => $other_registered_keys,
				'registeredMeta'       => $registered_meta,
				'otherRegisteredMeta'  => $other_registered_meta,
				'afterUnregister'      => $after_unregister,
				'defaultValue'         => $default_value,
				'otherDefaultValue'    => $other_default_value,
				'sanitizedValue'       => $sanitized_value,
				'otherSanitizedValue'  => $other_sanitized_value,
				'authAllowed'          => $auth_allowed,
				'otherAuthAllowed'     => $other_auth_allowed,
				'sanitizeLog'          => $sanitize_log,
				'authLog'              => $auth_log,
				'filterRestored'       => $filter_restored,
			);
			$cases[]      = $case_details;

			self::collect_failure(
				$failures,
				true === $registered
					&& isset( $registered_keys[ $meta_key ] )
					&& ! isset( $other_registered_keys[ $meta_key ] )
					&& true === ( $registered_keys[ $meta_key ]['single'] ?? null )
					&& $default === ( $registered_keys[ $meta_key ]['default'] ?? null )
					&& $exists_subtype
					&& ! $exists_other_subtype
					&& ! $exists_global
					&& $meta_value === $registered_meta
					&& false === $other_registered_meta
					&& $default === $default_value
					&& '' === $other_default_value
					&& $sanitized === $sanitized_value
					&& $raw_value === $other_sanitized_value
					&& true === $auth_allowed
					&& null === $other_auth_allowed
					&& 1 === count( $sanitize_log )
					&& $subtype === ( $sanitize_log[0]['subtype'] ?? null )
					&& 1 === count( $auth_log )
					&& $object_id === (int) ( $auth_log[0]['objectId'] ?? 0 )
					&& true === $unregistered
					&& false === $after_unregister
					&& $filter_restored,
				"current subtype-aware metadata APIs cover absent helper behavior for {$object_type}",
				$case_details
			);
		}

		return self::result(
			$ctx,
			'metadata.registered-metadata-by-object-subtype.current-api-accounting',
			array() === $failures,
			array(
				'helperAvailable' => false,
				'objectTypes'     => self::OBJECT_TYPES,
				'cases'           => $cases,
				'coveredApis'     => array(
					'register_meta',
					'register_post_meta',
					'get_registered_meta_keys',
					'registered_meta_key_exists',
					'get_registered_metadata',
					'get_metadata_default',
					'sanitize_meta',
					'auth_*_meta_*_for_subtype',
				),
				'failures'        => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function register_subtype_meta(
		string $object_type,
		string $subtype,
		string $meta_key,
		array $args
	): bool {
		if ( 'post' === $object_type ) {
			return \register_post_meta( $subtype, $meta_key, $args );
		}

		$args['object_subtype'] = $subtype;
		return \register_meta( $object_type, $meta_key, $args );
	}

	private static function schema_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$type = $ctx->choice( array( 'string', 'boolean', 'integer', 'number', 'array', 'object' ) );

		if ( 'boolean' === $type ) {
			return array(
				'type'       => $type,
				'single'     => $ctx->bool(),
				'default'    => $ctx->bool(),
				'showInRest' => false,
			);
		}

		if ( 'integer' === $type ) {
			return array(
				'type'       => $type,
				'single'     => $ctx->bool(),
				'default'    => $ctx->int( -5000, 5000 ),
				'showInRest' => false,
			);
		}

		if ( 'number' === $type ) {
			return array(
				'type'       => $type,
				'single'     => $ctx->bool(),
				'default'    => $ctx->int( -5000, 5000 ) / 10,
				'showInRest' => false,
			);
		}

		if ( 'array' === $type ) {
			return array(
				'type'       => $type,
				'single'     => $ctx->bool(),
				'default'    => array( $ctx->identifier( 3, 8 ), $ctx->text( 0, 16 ) ),
				'showInRest' => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			);
		}

		if ( 'object' === $type ) {
			return array(
				'type'       => $type,
				'single'     => $ctx->bool(),
				'default'    => array(
					'label' => $ctx->identifier( 3, 8 ),
					'count' => $ctx->int( 0, 20 ),
				),
				'showInRest' => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'label' => array( 'type' => 'string' ),
							'count' => array( 'type' => 'integer' ),
						),
					),
				),
			);
		}

		return array(
			'type'       => 'string',
			'single'     => $ctx->bool(),
			'default'    => $ctx->text( 0, 32 ),
			'showInRest' => false,
		);
	}

	private static function expected_default( $default, bool $single ) {
		return $single ? $default : array( $default );
	}

	private static function object_subtype( \ComponentFuzz\FuzzContext $ctx, string $object_type ): string {
		$prefix = array(
			'post'    => 'post-type',
			'term'    => 'taxonomy',
			'comment' => 'comment-kind',
			'user'    => 'user-kind',
		)[ $object_type ] ?? 'subtype';

		return self::slug( $ctx->fork( 'subtype' ), $prefix );
	}

	private static function different_object_type( string $object_type ): string {
		foreach ( self::OBJECT_TYPES as $candidate ) {
			if ( $candidate !== $object_type ) {
				return $candidate;
			}
		}

		return 'post';
	}

	private static function meta_key( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$weird = $ctx->choice(
			array(
				'',
				'-dash',
				'_under',
				' space',
				'/slash',
				':colon',
				'.dot',
				' utf8-' . $ctx->text( 1, 8 ),
			)
		);
		$key   = 'cf_meta_' . self::slug( $ctx, $prefix ) . '_' . substr( dechex( $ctx->seed() ), -6 ) . $weird;

		return '' === $key ? 'cf_meta_' . substr( dechex( $ctx->seed() ), -8 ) : $key;
	}

	private static function metadata_value( \ComponentFuzz\FuzzContext $ctx ) {
		$type = $ctx->choice( array( 'null', 'bool', 'int', 'float', 'string', 'array', 'object' ) );

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
		if ( 'array' === $type ) {
			return array(
				'text'  => $ctx->text( 0, 24 ),
				'bytes' => $ctx->bytes( 0, 8 ),
				'list'  => array( $ctx->int( -10, 10 ), $ctx->bool() ),
			);
		}
		if ( 'object' === $type ) {
			return (object) array(
				'label' => $ctx->identifier( 3, 10 ),
				'value' => $ctx->text( 0, 24 ),
			);
		}

		return $ctx->text( 0, 48 ) . $ctx->bytes( 0, 8 );
	}

	private static function metadata_db_value( \ComponentFuzz\FuzzContext $ctx ) {
		$type = $ctx->choice( array( 'string', 'quoted-string', 'array' ) );

		if ( 'array' === $type ) {
			return array(
				'label' => $ctx->identifier( 3, 10 ),
				'path'  => 'segment\\' . $ctx->identifier( 3, 10 ),
				'count' => $ctx->int( -1000, 1000 ),
				'list'  => array( $ctx->identifier( 3, 8 ), (string) $ctx->int( 0, 99 ) ),
			);
		}

		if ( 'quoted-string' === $type ) {
			return "quote ' slash \\" . $ctx->identifier( 3, 12 );
		}

		return 'value-' . $ctx->identifier( 3, 12 ) . '-' . $ctx->int( -1000, 1000 );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$slug = strtolower( $prefix . '-' . $ctx->identifier( 3, 10 ) );
		$slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', (string) $slug );
		$slug = trim( $slug, '-_' );

		return '' === $slug ? 'fuzz-' . substr( dechex( $ctx->seed() ), -4 ) : $slug;
	}

	private static function reset_runtime(): void {
		$GLOBALS['wp_meta_keys'] = array();

		if ( function_exists( 'wp_installing' ) ) {
			\wp_installing( false );
		}

		if ( self::has_in_memory_metadata_store() ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		\wp_cache_flush();
	}

	private static function has_in_memory_metadata_store(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& is_object( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' );
	}

	private static function snapshot_state(): array {
		return array(
			'globals'     => self::snapshot_globals(
				array(
					'wp_meta_keys',
					'wp_filter',
					'wp_filters',
					'wp_actions',
					'wp_current_filter',
					'wp_object_cache',
					'wpdb',
				)
			),
			'installing'  => function_exists( 'wp_installing' ) ? \wp_installing() : null,
			'wpdbOptions' => isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' )
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( function_exists( 'wp_installing' ) && is_bool( $snapshot['installing'] ) ) {
			\wp_installing( $snapshot['installing'] );
		}

		if (
			null !== $snapshot['wpdbOptions']
			&& isset( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' )
		) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['wpdbOptions'] );
		}
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
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

	private static function get_object_property( object $target, string $property ) {
		$reflection = new \ReflectionProperty( $target, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->getValue( $target );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function result(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		bool $ok,
		array $data = array()
	): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function same_value( $expected, $actual ): bool {
		return serialize( $expected ) === serialize( $actual );
	}

	private static function clone_value( $value ) {
		if ( $value instanceof \Closure ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		return $value;
	}

	private static function describe_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Closure ) {
				return '[closure]';
			}
			return '[object ' . get_class( $value ) . ']';
		}

		if ( is_string( $value ) && strlen( $value ) > 240 ) {
			return substr( $value, 0, 240 ) . '...';
		}

		return $value;
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
