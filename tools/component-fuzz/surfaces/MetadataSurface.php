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
			$rows[] = self::check_defaults_agree_with_lookup( $ctx->fork( 'defaults' ) );
			$rows[] = self::check_sanitize_and_auth_callback_locality( $ctx->fork( 'callbacks' ) );
			$rows[] = self::check_protected_meta_locality( $ctx->fork( 'protected' ) );
			$rows[] = self::check_no_db_crud_filters_and_cache( $ctx->fork( 'crud' ) );
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
				'get_metadata',
				'get_metadata_default',
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
				'wp_cache_delete',
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

	private static function check_lazyloader_queue_and_cache( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_runtime();

		$lazyloader  = new \WP_Metadata_Lazyloader();
		$object_ids  = array( 5101 + $ctx->int( 1, 500 ), 5102 + $ctx->int( 1, 500 ) );
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
		$lazyloader->queue_objects( 'comment', array( $object_ids[0], $object_ids[1], $object_ids[0] ) );
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

		$expected_cache_ids = array( $object_ids[0], $object_ids[1], $extra_id );

		$ok = $invalid_queue instanceof \WP_Error
			&& 'invalid_object_type' === $invalid_queue->get_error_code()
			&& $invalid_reset instanceof \WP_Error
			&& 'invalid_object_type' === $invalid_reset->get_error_code()
			&& $has_filter
			&& isset( $pending_before['comment'][ $object_ids[0] ], $pending_before['comment'][ $object_ids[1] ] )
			&& 2 === count( $pending_before['comment'] )
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
			&& array( $object_ids[0], $object_ids[1], $object_ids[0] ) === $events[0]['ids']
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
				'extraId'          => $extra_id,
				'pendingBefore'    => $pending_before,
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
			return $ctx->skip(
				'metadata.registered-metadata-by-object-subtype.available',
				'get_registered_metadata_by_object_subtype() is not available in this WordPress checkout.'
			);
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

		\wp_cache_flush();
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
