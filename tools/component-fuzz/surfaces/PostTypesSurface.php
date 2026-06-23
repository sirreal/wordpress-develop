<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes post type and post status registries without requiring stored posts.
 */
final class PostTypesSurface {
	public const NAME = 'post-types';

	private const CASES               = 14;
	private const PERMALINK_STRUCTURE = '/%postname%/';
	private const PREVIEW_BYTES       = 160;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'post-types.bootstrap-apis-available',
					'Required WordPress post type/status APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$ob_level = ob_get_level();
		$rows     = array();

		try {
			self::reset_registries();

			$rows[] = self::check_post_type_registration_matrix( $ctx );
			$rows[] = self::check_invalid_post_type_names_do_not_leak( $ctx );
			$rows[] = self::check_support_feature_mutation( $ctx );
			$rows[] = self::check_post_type_unregister_cleanup( $ctx );
			$rows[] = self::check_post_status_defaults_and_filters( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'post-types.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}

			self::restore_globals( $snapshot );

			$after_restore = self::snapshot_globals();
			$rows[]        = self::row(
				$ctx,
				'post-types.globals-restored',
				self::values_equal( $snapshot, $after_restore ),
				array(
					'trackedGlobals' => array_keys( $snapshot['globals'] ),
					'difference'     => self::values_equal( $snapshot, $after_restore )
						? null
						: self::first_value_difference( $snapshot, $after_restore ),
				)
			);
		}

		return $rows;
	}

	public static function filter_permalink_structure() {
		return self::PERMALINK_STRUCTURE;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Error', 'WP_Post_Type', 'WP_Rewrite', 'WP_Taxonomy' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_post_type_support',
				'get_all_post_type_supports',
				'get_object_taxonomies',
				'get_post_stati',
				'get_post_status_object',
				'get_post_type_labels',
				'get_post_type_object',
				'get_post_types',
				'get_post_types_by_support',
				'has_action',
				'is_post_type_viewable',
				'is_wp_error',
				'post_type_exists',
				'post_type_supports',
				'register_post_status',
				'register_post_type',
				'register_taxonomy',
				'remove_post_type_support',
				'sanitize_key',
				'sanitize_title_with_dashes',
				'unregister_post_type',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_post_type_registration_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		global $_wp_post_type_features, $post_type_meta_caps, $wp, $wp_rewrite;

		self::reset_registries();

		$failures = array();
		$cases    = self::post_type_cases( $ctx->fork( 'types-rich' ) );

		foreach ( $cases as $index => $case ) {
			$taxonomy_failures = self::register_case_taxonomies( $case );
			if ( array() !== $taxonomy_failures ) {
				$failures[] = array(
					'label'   => "taxonomy setup failed case {$index}",
					'details' => self::describe_value(
						array(
							'case'     => self::case_summary( $case ),
							'failures' => $taxonomy_failures,
						)
					),
				);
				continue;
			}

			$post_type = \sanitize_key( $case['name'] );
			$args      = $case['args'];
			$object    = \register_post_type( $case['name'], $args );

			if ( ! $object instanceof \WP_Post_Type ) {
				$failures[] = array(
					'label'   => "register_post_type returned failure case {$index}",
					'details' => self::describe_value(
						array(
							'case'   => self::case_summary( $case ),
							'actual' => $object,
						)
					),
				);
				continue;
			}

			$expected_props         = self::expected_post_type_props( $post_type, $args );
			$expected_caps          = self::expected_capabilities( $args );
			$expected_supports      = self::expected_supports( $args['supports'] ?? array() );
			$expected_labels        = self::expected_label_subset( $post_type, $args, $expected_props['hierarchical'] );
			$expected_taxonomies    = $args['taxonomies'] ?? array();
			$actual_taxonomies      = \get_object_taxonomies( $post_type, 'names' );
			$expected_query_var_set = false !== $expected_props['query_var'] && $expected_props['publicly_queryable'];
			$has_query_var          = is_object( $wp )
				&& isset( $wp->public_query_vars )
				&& in_array( $expected_props['query_var'], $wp->public_query_vars, true );
			$has_permastruct        = is_object( $wp_rewrite )
				&& isset( $wp_rewrite->extra_permastructs )
				&& array_key_exists( $post_type, $wp_rewrite->extra_permastructs );
			$actual_capabilities    = (array) $object->cap;
			$public_names           = \get_post_types( array( 'public' => true ), 'names' );
			$hierarchical_objects   = \get_post_types( array( 'hierarchical' => true ), 'objects' );

			self::collect_failure(
				$failures,
				\post_type_exists( $post_type )
					&& $object === \get_post_type_object( $post_type )
					&& in_array( $post_type, \get_post_types( array(), 'names' ), true )
					&& ( $expected_props['public'] === in_array( $post_type, $public_names, true ) )
					&& ( $expected_props['hierarchical'] === array_key_exists( $post_type, $hierarchical_objects ) )
					&& self::post_type_props_match( $object, $expected_props )
					&& \is_post_type_viewable( $object ) === $expected_props['publicly_queryable']
					&& $expected_query_var_set === $has_query_var
					&& ( false !== $expected_props['rewrite'] ) === $has_permastruct,
				"post type default inference and registry identity case {$index}",
				array(
					'case'      => self::case_summary( $case ),
					'expected'  => $expected_props,
					'actual'    => self::post_type_summary( $object ),
					'queryVars' => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
					'rewrite'   => $has_permastruct && is_object( $wp_rewrite )
						? $wp_rewrite->extra_permastructs[ $post_type ]
						: null,
				)
			);

			self::collect_failure(
				$failures,
				self::support_maps_match( $expected_supports, \get_all_post_type_supports( $post_type ) )
					&& self::support_queries_match( $post_type, $expected_supports ),
				"post type support registration case {$index}",
				array(
					'case'     => self::case_summary( $case ),
					'expected' => $expected_supports,
					'actual'   => \get_all_post_type_supports( $post_type ),
					'byEditor' => \get_post_types_by_support( 'editor' ),
					'byBoth'   => \get_post_types_by_support( array( 'editor', 'autosave' ), 'and' ),
				)
			);

			self::collect_failure(
				$failures,
				self::maps_match( $expected_caps, $actual_capabilities )
					&& $expected_props['capability_type'] === $object->capability_type
					&& $expected_props['map_meta_cap'] === $object->map_meta_cap
					&& self::meta_cap_registry_matches( $object->cap, $expected_props['map_meta_cap'] ),
				"post type capability object shape case {$index}",
				array(
					'case'       => self::case_summary( $case ),
					'expected'   => $expected_caps,
					'actual'     => self::cap_summary( $object->cap ),
					'metaCaps'   => $post_type_meta_caps,
					'capability' => $object->capability_type,
					'mapMetaCap' => $object->map_meta_cap,
				)
			);

			self::collect_failure(
				$failures,
				self::label_subset_matches( $expected_labels, $object->labels )
					&& $object->label === $object->labels->name,
				"post type label fallback case {$index}",
				array(
					'case'     => self::case_summary( $case ),
					'expected' => $expected_labels,
					'actual'   => self::label_summary( $object->labels ),
					'label'    => $object->label,
				)
			);

			self::collect_failure(
				$failures,
				self::sets_match( $expected_taxonomies, $actual_taxonomies )
					&& self::taxonomies_contain_object_type( $expected_taxonomies, $post_type )
					&& self::rest_expectation_matches( $object )
					&& $expected_props['template'] === $object->template
					&& $expected_props['template_lock'] === $object->template_lock,
				"post type taxonomy, REST, and template boundaries case {$index}",
				array(
					'case'       => self::case_summary( $case ),
					'taxonomies' => $actual_taxonomies,
					'rest'       => self::rest_summary( $object ),
					'template'   => $object->template,
				)
			);

			$unregistered = \unregister_post_type( $post_type );
			self::collect_failure(
				$failures,
				true === $unregistered
					&& ! \post_type_exists( $post_type )
					&& ! isset( $_wp_post_type_features[ $post_type ] )
					&& self::taxonomies_do_not_contain_object_type( $expected_taxonomies, $post_type )
					&& ! self::post_type_meta_caps_contain( $object->cap )
					&& (
						false === $expected_props['query_var']
						|| ! is_object( $wp )
						|| ! isset( $wp->public_query_vars )
						|| ! in_array( $expected_props['query_var'], $wp->public_query_vars, true )
					),
				"post type per-case unregister cleanup case {$index}",
				array(
					'case'         => self::case_summary( $case ),
					'unregistered' => $unregistered,
					'features'     => $_wp_post_type_features[ $post_type ] ?? null,
					'metaCaps'     => $post_type_meta_caps,
				)
			);
		}

		return self::row(
			$ctx,
			'post-types.register.rich-defaults-labels-capabilities-rest',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 10 ),
			)
		);
	}

	private static function check_invalid_post_type_names_do_not_leak( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$failures = array();
		$baseline = self::post_type_name( $ctx->fork( 'invalid-baseline' ), 'valid' );
		$valid    = \register_post_type(
			$baseline,
			array(
				'public'    => true,
				'rewrite'   => false,
				'query_var' => false,
				'supports'  => array( 'title' ),
			)
		);

		if ( ! $valid instanceof \WP_Post_Type ) {
			return self::row(
				$ctx,
				'post-types.invalid-names-do-not-leak',
				false,
				array(
					'baseline' => $baseline,
					'actual'   => self::describe_value( $valid ),
				)
			);
		}

		$before = self::registry_shape();
		$cases  = array(
			'',
			'!!!!',
			"\x80\xFF",
			str_repeat( 'a', 21 ),
			'cf_' . str_repeat( 'x', 24 ),
			' spaces / ' . str_repeat( 'y', 21 ),
		);

		foreach ( $cases as $index => $name ) {
			$sanitized = \sanitize_key( $name );
			$result    = \register_post_type(
				$name,
				array(
					'public'          => true,
					'show_in_rest'    => true,
					'rewrite'         => true,
					'query_var'       => true,
					'supports'        => array( 'editor' ),
					'capability_type' => array( 'leak', 'leaks' ),
					'map_meta_cap'    => true,
				)
			);
			$after     = self::registry_shape();

			self::collect_failure(
				$failures,
				$result instanceof \WP_Error
					&& 'post_type_length_invalid' === $result->get_error_code()
					&& $before === $after
					&& ( '' === $sanitized || ! \post_type_exists( $sanitized ) ),
				"invalid post type name rejected without registry leak case {$index}",
				array(
					'input'     => $name,
					'sanitized' => $sanitized,
					'result'    => self::describe_value( $result ),
					'before'    => $before,
					'after'     => $after,
				)
			);
		}

		\unregister_post_type( $baseline );

		return self::row(
			$ctx,
			'post-types.invalid-names-do-not-leak',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_support_feature_mutation( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$failures  = array();
		$post_type = self::post_type_name( $ctx->fork( 'support-mutation' ), 'support' );
		$object    = \register_post_type(
			$post_type,
			array(
				'public'   => true,
				'rewrite'  => false,
				'supports' => array( 'editor' ),
			)
		);

		if ( ! $object instanceof \WP_Post_Type ) {
			return self::row(
				$ctx,
				'post-types.support-feature-add-remove',
				false,
				array(
					'postType' => $post_type,
					'actual'   => self::describe_value( $object ),
				)
			);
		}

		$after_register = \get_all_post_type_supports( $post_type );

		\add_post_type_support( $post_type, array( 'thumbnail', 'custom-fields' ) );
		\add_post_type_support( $post_type, 'component-fuzz-feature', array( 'seed' => $ctx->seed() ) );
		$after_add = \get_all_post_type_supports( $post_type );

		\remove_post_type_support( $post_type, 'editor' );
		$after_remove_editor = \get_all_post_type_supports( $post_type );

		\remove_post_type_support( $post_type, 'autosave' );
		\remove_post_type_support( $post_type, 'missing-feature' );
		$after_remove_autosave = \get_all_post_type_supports( $post_type );

		self::collect_failure(
			$failures,
			self::maps_match(
				array(
					'editor'  => true,
					'autosave' => true,
				),
				$after_register
			)
				&& true === \post_type_supports( $post_type, 'thumbnail' )
				&& true === \post_type_supports( $post_type, 'custom-fields' )
				&& isset( $after_add['component-fuzz-feature'] )
				&& array( array( 'seed' => $ctx->seed() ) ) === $after_add['component-fuzz-feature']
				&& ! isset( $after_remove_editor['editor'] )
				&& isset( $after_remove_editor['autosave'] )
				&& isset( $after_remove_editor['thumbnail'], $after_remove_editor['custom-fields'] )
				&& false === \post_type_supports( $post_type, 'autosave' )
				&& ! isset( $after_remove_autosave['autosave'] )
				&& isset( $after_remove_autosave['component-fuzz-feature'] ),
			'post type support add/remove behavior is feature-local',
			array(
				'postType'            => $post_type,
				'afterRegister'       => $after_register,
				'afterAdd'            => $after_add,
				'afterRemoveEditor'   => $after_remove_editor,
				'afterRemoveAutosave' => $after_remove_autosave,
			)
		);

		$unregistered = \unregister_post_type( $post_type );
		self::collect_failure(
			$failures,
			true === $unregistered && array() === \get_all_post_type_supports( $post_type ),
			'post type support features are removed on unregister',
			array(
				'postType'      => $post_type,
				'unregistered'  => $unregistered,
				'afterCleanup'  => \get_all_post_type_supports( $post_type ),
			)
		);

		return self::row(
			$ctx,
			'post-types.support-feature-add-remove',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_post_type_unregister_cleanup( \ComponentFuzz\FuzzContext $ctx ): array {
		global $_wp_post_type_features, $post_type_meta_caps, $wp, $wp_rewrite;

		self::reset_registries();

		$failures  = array();
		$post_type = self::post_type_name( $ctx->fork( 'cleanup-name' ), 'cleanup' );
		$tax       = self::taxonomy_name( $ctx->fork( 'cleanup-tax' ), 'tax' );
		$query_var = 'query_' . $post_type;
		$tax_obj   = \register_taxonomy(
			$tax,
			array(),
			array(
				'public'    => false,
				'rewrite'   => false,
				'query_var' => false,
			)
		);
		$args      = array(
			'public'          => true,
			'has_archive'     => true,
			'rewrite'         => array(
				'slug'       => 'cleanup/' . $post_type,
				'with_front' => false,
				'pages'      => true,
				'feeds'      => true,
			),
			'query_var'       => $query_var,
			'taxonomies'      => array( $tax ),
			'supports'        => array( 'title', 'editor', 'custom-fields' ),
			'capability_type' => array( 'entry', 'entries' ),
			'map_meta_cap'    => true,
		);

		$object = \register_post_type( $post_type, $args );
		$before = array(
			'taxonomyObject'   => $tax_obj instanceof \WP_Taxonomy,
			'exists'           => \post_type_exists( $post_type ),
			'supports'         => $_wp_post_type_features[ $post_type ] ?? null,
			'queryVars'        => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
			'permastructs'     => is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_permastructs )
				? array_keys( $wp_rewrite->extra_permastructs )
				: array(),
			'archiveRules'     => self::rewrite_rules_contain_post_type( $post_type ),
			'taxonomyLinked'   => self::taxonomy_contains_object_type( $tax, $post_type ),
			'futureHook'       => \has_action( 'future_' . $post_type, '_future_post_hook' ),
			'metaCapabilities' => self::cap_meta_registry_subset( $object instanceof \WP_Post_Type ? $object->cap : null ),
		);
		$result = \unregister_post_type( $post_type );

		self::collect_failure(
			$failures,
			$object instanceof \WP_Post_Type
				&& $tax_obj instanceof \WP_Taxonomy
				&& true === $before['exists']
				&& is_array( $before['supports'] )
				&& in_array( $query_var, $before['queryVars'], true )
				&& in_array( $post_type, $before['permastructs'], true )
				&& true === $before['archiveRules']
				&& true === $before['taxonomyLinked']
				&& 5 === $before['futureHook']
				&& array() !== $before['metaCapabilities']
				&& true === $result
				&& ! \post_type_exists( $post_type )
				&& null === \get_post_type_object( $post_type )
				&& ! isset( $_wp_post_type_features[ $post_type ] )
				&& ! in_array( $post_type, \get_post_types_by_support( 'editor' ), true )
				&& ! self::post_type_meta_caps_contain( $object->cap )
				&& ! self::taxonomy_contains_object_type( $tax, $post_type )
				&& ! \has_action( 'future_' . $post_type, '_future_post_hook' )
				&& (
					! is_object( $wp )
					|| ! isset( $wp->public_query_vars )
					|| ! in_array( $query_var, $wp->public_query_vars, true )
				)
				&& (
					! is_object( $wp_rewrite )
					|| ! isset( $wp_rewrite->extra_permastructs )
					|| ! array_key_exists( $post_type, $wp_rewrite->extra_permastructs )
				)
				&& ! self::rewrite_rules_contain_post_type( $post_type ),
			'unregister_post_type removes registry, supports, query vars, rewrite, hooks, taxonomies, and meta caps',
			array(
				'postType' => $post_type,
				'before'   => $before,
				'result'   => $result,
				'after'    => array(
					'exists'           => \post_type_exists( $post_type ),
					'supports'         => $_wp_post_type_features[ $post_type ] ?? null,
					'queryVars'        => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
					'permastructs'     => is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_permastructs )
						? array_keys( $wp_rewrite->extra_permastructs )
						: array(),
					'archiveRules'     => self::rewrite_rules_contain_post_type( $post_type ),
					'taxonomyLinked'   => self::taxonomy_contains_object_type( $tax, $post_type ),
					'futureHook'       => \has_action( 'future_' . $post_type, '_future_post_hook' ),
					'metaCapabilities' => $post_type_meta_caps,
				),
			)
		);

		$missing = \unregister_post_type( $post_type );
		self::collect_failure(
			$failures,
			$missing instanceof \WP_Error && 'invalid_post_type' === $missing->get_error_code(),
			'unregister_post_type reports invalid post type after cleanup',
			array(
				'postType' => $post_type,
				'missing'  => self::describe_value( $missing ),
			)
		);

		return self::row(
			$ctx,
			'post-types.unregister.cleans-registries',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_post_status_defaults_and_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$failures = array();
		$cases    = self::status_cases( $ctx->fork( 'statuses' ) );

		foreach ( $cases as $index => $case ) {
			$status = \register_post_status( $case['name'], $case['args'] );
			$args   = $case['args'];

			$expected = self::expected_status_props( $case['name'], $args );

			self::collect_failure(
				$failures,
				is_object( $status )
					&& $status === \get_post_status_object( $case['name'] )
					&& $expected['public'] === $status->public
					&& $expected['private'] === $status->private
					&& $expected['protected'] === $status->protected
					&& $expected['internal'] === $status->internal
					&& $expected['publicly_queryable'] === $status->publicly_queryable
					&& $expected['exclude_from_search'] === $status->exclude_from_search
					&& $expected['show_in_admin_all_list'] === $status->show_in_admin_all_list
					&& $expected['show_in_admin_status_list'] === $status->show_in_admin_status_list
					&& $expected['date_floating'] === $status->date_floating
					&& $expected['label'] === $status->label
					&& self::status_label_count_matches( $expected['label_count'], $status->label_count ),
				"post status default cascade case {$index}",
				array(
					'status'   => $case['name'],
					'args'     => $args,
					'expected' => $expected,
					'actual'   => self::status_summary( $status ),
				)
			);
		}

		$public_names   = \get_post_stati( array( 'public' => true ), 'names' );
		$internal_names = \get_post_stati( array( 'internal' => true ), 'names' );
		$admin_objects  = \get_post_stati( array( 'show_in_admin_all_list' => true ), 'objects' );
		$or_names       = \get_post_stati( array( 'public' => true, 'private' => true ), 'names', 'or' );
		$and_names      = \get_post_stati( array( 'public' => true, 'private' => true ), 'names', 'and' );

		self::collect_failure(
			$failures,
			self::filter_expectation_matches( $cases, 'public', true, $public_names )
				&& self::filter_expectation_matches( $cases, 'internal', true, $internal_names )
				&& self::filter_expectation_matches( $cases, 'show_in_admin_all_list', true, array_keys( $admin_objects ) )
				&& self::status_or_expectation_matches( $cases, array( 'public' => true, 'private' => true ), $or_names )
				&& self::status_and_expectation_matches( $cases, array( 'public' => true, 'private' => true ), $and_names ),
			'get_post_stati filters names and objects deterministically',
			array(
				'publicNames'   => $public_names,
				'internalNames' => $internal_names,
				'adminObjects'  => array_keys( $admin_objects ),
				'orNames'       => $or_names,
				'andNames'      => $and_names,
				'cases'         => $cases,
			)
		);

		return self::row(
			$ctx,
			'post-types.status.defaults-and-filters',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function post_type_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::safe_token( $ctx->identifier( 4, 10 ) . '_' . dechex( $ctx->seed() & 0xffff ) );
		$token = substr( $token, 0, 8 );

		$cases = array(
			array(
				'label' => 'public rest rewrite taxonomies',
				'name'  => self::post_type_name( $ctx->fork( 'public-rest' ), 'pub' ),
				'args'  => array(
					'description'           => 'Public REST fuzz ' . $token,
					'public'                => true,
					'hierarchical'          => false,
					'has_archive'           => true,
					'rewrite'               => array(
						'slug'       => 'library/' . $token,
						'with_front' => false,
						'pages'      => false,
						'feeds'      => true,
						'ep_mask'    => EP_NONE,
					),
					'query_var'             => 'Query Var ' . $token,
					'taxonomies'            => array(
						self::taxonomy_name( $ctx->fork( 'tax-a' ), 'tax_a' ),
						self::taxonomy_name( $ctx->fork( 'tax-b' ), 'tax_b' ),
					),
					'supports'              => array( 'title', 'editor', 'thumbnail' ),
					'capability_type'       => array( 'story', 'stories' ),
					'map_meta_cap'          => true,
					'show_in_rest'          => true,
					'rest_base'             => 'items-' . $token,
					'rest_controller_class' => false,
					'template'              => array(
						array(
							'core/paragraph',
							array( 'placeholder' => 'Fuzz ' . $token ),
						),
					),
					'template_lock'         => 'insert',
					'menu_position'         => 23,
					'menu_icon'             => 'dashicons-admin-post',
					'labels'                => array(
						'name'          => 'Fuzz Items ' . $token,
						'singular_name' => 'Fuzz Item ' . $token,
					),
				),
			),
			array(
				'label' => 'private ui without supports',
				'name'  => self::post_type_name( $ctx->fork( 'private-ui' ), 'priv' ),
				'args'  => array(
					'label'           => 'Private Notes ' . $token,
					'public'          => false,
					'show_ui'         => true,
					'show_in_menu'    => false,
					'show_in_rest'    => false,
					'can_export'      => false,
					'delete_with_user' => false,
					'rewrite'         => false,
					'query_var'       => false,
					'supports'        => false,
					'capability_type' => 'note',
					'map_meta_cap'    => false,
				),
			),
			array(
				'label' => 'hierarchical invalid rest controller',
				'name'  => self::post_type_name( $ctx->fork( 'hier-rest' ), 'hier' ),
				'args'  => array(
					'labels'                          => array(
						'singular_name' => 'Chapter ' . $token,
						'menu_name'     => 'Chapters Menu ' . $token,
					),
					'public'                          => true,
					'hierarchical'                    => true,
					'publicly_queryable'              => false,
					'embeddable'                      => false,
					'exclude_from_search'             => false,
					'show_in_nav_menus'               => false,
					'has_archive'                     => 'archive-' . $token,
					'rewrite'                         => true,
					'query_var'                       => true,
					'supports'                        => array( 'editor', 'revisions', 'page-attributes' ),
					'capability_type'                 => array( 'chapter', 'chapters' ),
					'map_meta_cap'                    => true,
					'show_in_rest'                    => true,
					'rest_namespace'                  => 'component-fuzz/v1',
					'rest_controller_class'           => \stdClass::class,
					'autosave_rest_controller_class'  => 'ComponentFuzzMissingAutosavesController',
					'revisions_rest_controller_class' => \stdClass::class,
					'late_route_registration'         => true,
				),
			),
			array(
				'label' => 'reserved-ish post name',
				'name'  => 'post',
				'args'  => array(
					'public'          => false,
					'rewrite'         => false,
					'query_var'       => false,
					'supports'        => array(),
					'capability_type' => 'page',
				),
			),
			array(
				'label' => 'boundary length slug',
				'name'  => str_repeat( 'a', 20 ),
				'args'  => array(
					'labels'          => array(
						'name' => 'Boundary ' . $token,
					),
					'public'          => true,
					'show_ui'         => false,
					'show_in_menu'    => 'tools.php',
					'rewrite'         => array( 'slug' => '' ),
					'query_var'       => true,
					'supports'        => array(
						'author',
						'custom-fields' => array( 'source' => 'boundary' ),
					),
					'capability_type' => array( 'artifact', 'artifacts' ),
					'map_meta_cap'    => true,
					'_edit_link'      => '',
				),
			),
			array(
				'label' => 'custom capabilities',
				'name'  => self::post_type_name( $ctx->fork( 'custom-caps' ), 'caps' ),
				'args'  => array(
					'public'          => true,
					'rewrite'         => false,
					'query_var'       => 'capability query ' . $token,
					'supports'        => array( 'title', 'comments' ),
					'capability_type' => array( 'artifact', 'artifacts' ),
					'capabilities'    => array(
						'edit_posts'   => 'edit_fuzz_bundle',
						'create_posts' => 'create_fuzz_bundle',
						'read'         => 'read_fuzz_bundle',
					),
					'map_meta_cap'    => true,
				),
			),
			array(
				'label' => 'rest class missing',
				'name'  => self::post_type_name( $ctx->fork( 'rest-missing' ), 'rest' ),
				'args'  => array(
					'public'                => false,
					'publicly_queryable'    => true,
					'show_ui'               => false,
					'rewrite'               => false,
					'query_var'             => 'rest_' . $token,
					'supports'              => false,
					'show_in_rest'          => true,
					'rest_base'             => false,
					'rest_controller_class' => 'ComponentFuzzMissingPostsController',
				),
			),
		);

		for ( $i = count( $cases ); $i < self::CASES; ++$i ) {
			$case         = $ctx->fork( 'generated-' . $i );
			$public       = $case->bool();
			$show_ui      = $case->choice( array( null, true, false ) );
			$show_in_rest = $case->bool();
			$rewrite      = $case->choice(
				array(
					false,
					true,
					array(
						'slug'       => 'generated/' . self::safe_token( $case->identifier( 3, 8 ) ),
						'with_front' => $case->bool(),
						'pages'      => $case->bool(),
						'feeds'      => $case->bool(),
					),
				)
			);
			$supports     = $case->choice(
				array(
					array( 'title', 'editor' ),
					array( 'excerpt', 'comments' ),
					false,
					array(
						'author',
						'revisions' => array( 'bounded' => true ),
					),
				)
			);
			$args         = array(
				'label'           => 'Generated ' . $i . ' ' . $token,
				'public'          => $public,
				'hierarchical'    => $case->bool(),
				'has_archive'     => $case->choice( array( false, true, 'archive-gen-' . $i . '-' . $token ) ),
				'rewrite'         => $rewrite,
				'query_var'       => $case->choice( array( true, false, 'query/generated ' . $i . ' ' . $token ) ),
				'supports'        => $supports,
				'capability_type' => array(
					self::cap_base( $case->fork( 'singular' ), 'item' ),
					self::cap_base( $case->fork( 'plural' ), 'items' ),
				),
				'map_meta_cap'    => $case->bool(),
				'show_in_rest'    => $show_in_rest,
				'menu_icon'       => $case->choice( array( null, 'dashicons-chart-pie', 'none' ) ),
			);

			if ( null !== $show_ui ) {
				$args['show_ui'] = $show_ui;
			}

			if ( $case->bool() ) {
				$args['show_in_menu'] = $case->choice( array( true, false, 'tools.php' ) );
			}

			if ( $show_in_rest && $case->bool() ) {
				$args['rest_controller_class'] = $case->choice(
					array(
						false,
						\WP_REST_Posts_Controller::class,
						\ComponentFuzz\Surfaces\PostTypesSurface::class,
					)
				);
			}

			if ( $case->bool( 35 ) ) {
				$args['taxonomies'] = array( self::taxonomy_name( $case->fork( 'tax' ), 'gen_tax' ) );
			}

			$cases[] = array(
				'label' => 'generated ' . $i,
				'name'  => self::post_type_name( $case->fork( 'name' ), 'gen' ),
				'args'  => $args,
			);
		}

		return $cases;
	}

	private static function status_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'name' => self::status_name( $ctx->fork( 'implicit' ), 'implicit' ), 'args' => array() ),
			array( 'name' => self::status_name( $ctx->fork( 'public' ), 'public' ), 'args' => array( 'public' => true ) ),
			array(
				'name' => self::status_name( $ctx->fork( 'private' ), 'private' ),
				'args' => array(
					'private'     => true,
					'label'       => 'Private Fuzz',
					'label_count' => array(
						'singular' => 'Private Fuzz <span class="count">(%s)</span>',
						'plural'   => 'Private Fuzz <span class="count">(%s)</span>',
						'context'  => null,
						'domain'   => null,
					),
				),
			),
			array( 'name' => self::status_name( $ctx->fork( 'protected' ), 'protected' ), 'args' => array( 'protected' => true, 'show_in_admin_status_list' => false ) ),
			array( 'name' => self::status_name( $ctx->fork( 'query' ), 'query' ), 'args' => array( 'public' => true, 'publicly_queryable' => false, 'exclude_from_search' => true ) ),
		);

		for ( $i = count( $cases ); $i < self::CASES; ++$i ) {
			$case = $ctx->fork( 'status-' . $i );
			$args = array(
				'public'                    => $case->choice( array( null, true, false ) ),
				'internal'                  => $case->choice( array( null, true, false ) ),
				'protected'                 => $case->choice( array( null, true, false ) ),
				'private'                   => $case->choice( array( null, true, false ) ),
				'publicly_queryable'        => $case->choice( array( null, true, false ) ),
				'exclude_from_search'       => $case->choice( array( null, true, false ) ),
				'show_in_admin_all_list'    => $case->choice( array( null, true, false ) ),
				'show_in_admin_status_list' => $case->choice( array( null, true, false ) ),
				'date_floating'             => $case->bool(),
			);

			$args = array_filter(
				$args,
				static function ( $value ): bool {
					return null !== $value;
				}
			);

			if ( $case->bool() ) {
				$args['label'] = 'Status ' . $i . ' ' . substr( hash( 'crc32b', (string) $case->seed() ), 0, 6 );
			}

			$cases[] = array(
				'name' => self::status_name( $case->fork( 'name' ), 'gen' ),
				'args' => $args,
			);
		}

		return $cases;
	}

	private static function register_case_taxonomies( array $case ): array {
		$failures = array();

		foreach ( $case['args']['taxonomies'] ?? array() as $taxonomy ) {
			$object = \register_taxonomy(
				$taxonomy,
				array(),
				array(
					'public'       => false,
					'show_ui'      => false,
					'show_in_rest' => false,
					'rewrite'      => false,
					'query_var'    => false,
				)
			);

			if ( ! $object instanceof \WP_Taxonomy ) {
				$failures[] = array(
					'taxonomy' => $taxonomy,
					'result'   => self::describe_value( $object ),
				);
			}
		}

		return $failures;
	}

	private static function expected_post_type_props( string $post_type, array $args ): array {
		$public             = (bool) ( $args['public'] ?? false );
		$hierarchical       = (bool) ( $args['hierarchical'] ?? false );
		$publicly_queryable = array_key_exists( 'publicly_queryable', $args ) ? (bool) $args['publicly_queryable'] : $public;
		$embeddable         = array_key_exists( 'embeddable', $args ) ? (bool) $args['embeddable'] : $public;
		$show_ui            = array_key_exists( 'show_ui', $args ) ? (bool) $args['show_ui'] : $public;
		$show_in_menu       = array_key_exists( 'show_in_menu', $args ) ? $args['show_in_menu'] : null;

		if ( null === $show_in_menu || ! $show_ui ) {
			$show_in_menu = $show_ui;
		}

		$has_edit_link = ! empty( $args['_edit_link'] );
		$edit_link     = $args['_edit_link'] ?? 'post.php?post=%d';
		if ( ! $show_ui && ! $has_edit_link ) {
			$edit_link = '';
		}

		$rest_namespace = $args['rest_namespace'] ?? false;
		if ( false === $rest_namespace && ! empty( $args['show_in_rest'] ) ) {
			$rest_namespace = 'wp/v2';
		}

		$capability_type = $args['capability_type'] ?? 'post';
		$singular_cap    = is_array( $capability_type ) ? $capability_type[0] : $capability_type;

		$map_meta_cap = $args['map_meta_cap'] ?? null;
		if (
			empty( $args['capabilities'] )
			&& null === $map_meta_cap
			&& in_array( $capability_type, array( 'post', 'page' ), true )
		) {
			$map_meta_cap = true;
		}
		if ( null === $map_meta_cap ) {
			$map_meta_cap = false;
		}

		return array(
			'name'                            => $post_type,
			'description'                     => $args['description'] ?? '',
			'public'                          => $public,
			'hierarchical'                    => $hierarchical,
			'publicly_queryable'              => $publicly_queryable,
			'embeddable'                      => $embeddable,
			'show_ui'                         => $show_ui,
			'show_in_menu'                    => $show_in_menu,
			'show_in_admin_bar'               => array_key_exists( 'show_in_admin_bar', $args ) ? (bool) $args['show_in_admin_bar'] : (bool) $show_in_menu,
			'show_in_nav_menus'               => array_key_exists( 'show_in_nav_menus', $args ) ? (bool) $args['show_in_nav_menus'] : $public,
			'exclude_from_search'             => array_key_exists( 'exclude_from_search', $args ) ? (bool) $args['exclude_from_search'] : ! $public,
			'menu_position'                   => $args['menu_position'] ?? null,
			'menu_icon'                       => $args['menu_icon'] ?? null,
			'capability_type'                 => $singular_cap,
			'map_meta_cap'                    => (bool) $map_meta_cap,
			'has_archive'                     => $args['has_archive'] ?? false,
			'query_var'                       => self::expected_query_var( $post_type, $args['query_var'] ?? true ),
			'can_export'                      => $args['can_export'] ?? true,
			'delete_with_user'                => $args['delete_with_user'] ?? null,
			'show_in_rest'                    => $args['show_in_rest'] ?? false,
			'rest_base'                       => $args['rest_base'] ?? false,
			'rest_namespace'                  => $rest_namespace,
			'rest_controller_class'           => $args['rest_controller_class'] ?? false,
			'autosave_rest_controller_class'  => $args['autosave_rest_controller_class'] ?? false,
			'revisions_rest_controller_class' => $args['revisions_rest_controller_class'] ?? false,
			'late_route_registration'         => $args['late_route_registration'] ?? false,
			'template'                        => $args['template'] ?? array(),
			'template_lock'                   => $args['template_lock'] ?? false,
			'rewrite'                         => self::expected_rewrite( $post_type, $args ),
			'_edit_link'                      => $edit_link,
		);
	}

	private static function expected_query_var( string $post_type, $query_var ) {
		if ( false === $query_var ) {
			return false;
		}

		if ( true === $query_var ) {
			return $post_type;
		}

		return \sanitize_title_with_dashes( $query_var );
	}

	private static function expected_rewrite( string $post_type, array $args ) {
		$rewrite = $args['rewrite'] ?? true;
		if ( false === $rewrite ) {
			return false;
		}

		if ( ! is_array( $rewrite ) ) {
			$rewrite = array();
		}

		if ( empty( $rewrite['slug'] ) ) {
			$rewrite['slug'] = $post_type;
		}

		if ( ! isset( $rewrite['with_front'] ) ) {
			$rewrite['with_front'] = true;
		}

		if ( ! isset( $rewrite['pages'] ) ) {
			$rewrite['pages'] = true;
		}

		if ( ! isset( $rewrite['feeds'] ) || ! ( $args['has_archive'] ?? false ) ) {
			$rewrite['feeds'] = (bool) ( $args['has_archive'] ?? false );
		}

		if ( ! isset( $rewrite['ep_mask'] ) ) {
			$rewrite['ep_mask'] = $args['permalink_epmask'] ?? EP_PERMALINK;
		}

		return $rewrite;
	}

	private static function expected_supports( $supports ): array {
		if ( false === $supports ) {
			return array();
		}

		if ( empty( $supports ) ) {
			return array(
				'title'   => true,
				'editor'  => true,
				'autosave' => true,
			);
		}

		$expected = array();
		foreach ( $supports as $feature => $args ) {
			if ( is_array( $args ) ) {
				$expected[ $feature ] = array( $args );
			} else {
				$expected[ $args ] = true;
			}
		}

		if ( isset( $expected['editor'] ) && ! isset( $expected['autosave'] ) ) {
			$expected['autosave'] = true;
		}

		return $expected;
	}

	private static function expected_capabilities( array $args ): array {
		$capability_type = $args['capability_type'] ?? 'post';
		if ( is_array( $capability_type ) ) {
			$singular_base = $capability_type[0];
			$plural_base   = $capability_type[1];
		} else {
			$singular_base = $capability_type;
			$plural_base   = $capability_type . 's';
		}

		$map_meta_cap = self::expected_post_type_props( 'cfz_caps_preview', $args )['map_meta_cap'];
		$capabilities = array(
			'edit_post'          => 'edit_' . $singular_base,
			'read_post'          => 'read_' . $singular_base,
			'delete_post'        => 'delete_' . $singular_base,
			'edit_posts'         => 'edit_' . $plural_base,
			'edit_others_posts'  => 'edit_others_' . $plural_base,
			'delete_posts'       => 'delete_' . $plural_base,
			'publish_posts'      => 'publish_' . $plural_base,
			'read_private_posts' => 'read_private_' . $plural_base,
		);

		if ( $map_meta_cap ) {
			$capabilities = array_merge(
				$capabilities,
				array(
					'read'                   => 'read',
					'delete_private_posts'   => 'delete_private_' . $plural_base,
					'delete_published_posts' => 'delete_published_' . $plural_base,
					'delete_others_posts'    => 'delete_others_' . $plural_base,
					'edit_private_posts'     => 'edit_private_' . $plural_base,
					'edit_published_posts'   => 'edit_published_' . $plural_base,
				)
			);
		}

		$capabilities = array_merge( $capabilities, $args['capabilities'] ?? array() );

		if ( ! isset( $capabilities['create_posts'] ) ) {
			$capabilities['create_posts'] = $capabilities['edit_posts'];
		}

		ksort( $capabilities );
		return $capabilities;
	}

	private static function expected_label_subset( string $post_type, array $args, bool $hierarchical ): array {
		$custom = $args['labels'] ?? array();

		if ( isset( $args['label'] ) && empty( $custom['name'] ) ) {
			$custom['name'] = $args['label'];
		}

		if ( ! isset( $custom['singular_name'] ) && isset( $custom['name'] ) ) {
			$custom['singular_name'] = $custom['name'];
		}

		if ( ! isset( $custom['name_admin_bar'] ) ) {
			$custom['name_admin_bar'] = $custom['singular_name'] ?? $post_type;
		}

		if ( ! isset( $custom['menu_name'] ) && isset( $custom['name'] ) ) {
			$custom['menu_name'] = $custom['name'];
		}

		if ( ! isset( $custom['all_items'] ) && isset( $custom['menu_name'] ) ) {
			$custom['all_items'] = $custom['menu_name'];
		}

		if ( ! isset( $custom['archives'] ) && isset( $custom['all_items'] ) ) {
			$custom['archives'] = $custom['all_items'];
		}

		$defaults              = \WP_Post_Type::get_default_labels();
		$defaults['menu_name'] = $defaults['name'];
		$index                 = $hierarchical ? 1 : 0;
		$expected              = array();

		foreach ( $defaults as $key => $value ) {
			$expected[ $key ] = $value[ $index ];
		}

		$expected = array_merge( $expected, $custom );

		if ( ! isset( $custom['template_name'] ) && isset( $custom['singular_name'] ) ) {
			$expected['template_name'] = sprintf( __( 'Single item: %s' ), $custom['singular_name'] );
		}

		return array_intersect_key(
			$expected,
			array_flip(
				array(
					'name',
					'singular_name',
					'name_admin_bar',
					'menu_name',
					'all_items',
					'archives',
					'parent_item_colon',
					'item_link',
					'item_link_description',
					'template_name',
				)
			)
		);
	}

	private static function expected_status_props( string $name, array $args ): array {
		$public    = $args['public'] ?? null;
		$internal  = $args['internal'] ?? null;
		$protected = $args['protected'] ?? null;
		$private   = $args['private'] ?? null;

		if ( null === $public && null === $internal && null === $protected && null === $private ) {
			$internal = true;
		}

		$public    = (bool) ( $public ?? false );
		$private   = (bool) ( $private ?? false );
		$protected = (bool) ( $protected ?? false );
		$internal  = (bool) ( $internal ?? false );
		$label     = false === ( $args['label'] ?? false ) ? $name : $args['label'];

		return array(
			'name'                      => $name,
			'public'                    => $public,
			'private'                   => $private,
			'protected'                 => $protected,
			'internal'                  => $internal,
			'publicly_queryable'        => (bool) ( $args['publicly_queryable'] ?? $public ),
			'exclude_from_search'       => (bool) ( $args['exclude_from_search'] ?? $internal ),
			'show_in_admin_all_list'    => (bool) ( $args['show_in_admin_all_list'] ?? ! $internal ),
			'show_in_admin_status_list' => (bool) ( $args['show_in_admin_status_list'] ?? ! $internal ),
			'date_floating'             => (bool) ( $args['date_floating'] ?? false ),
			'label'                     => $label,
			'label_count'               => false === ( $args['label_count'] ?? false )
				? array(
					'singular' => $label,
					'plural'   => $label,
					'context'  => null,
					'domain'   => null,
				)
				: $args['label_count'],
		);
	}

	private static function post_type_props_match( \WP_Post_Type $object, array $expected ): bool {
		foreach (
			array(
				'name',
				'description',
				'public',
				'hierarchical',
				'publicly_queryable',
				'embeddable',
				'show_ui',
				'show_in_menu',
				'show_in_admin_bar',
				'show_in_nav_menus',
				'exclude_from_search',
				'menu_position',
				'menu_icon',
				'has_archive',
				'query_var',
				'can_export',
				'delete_with_user',
				'show_in_rest',
				'rest_base',
				'rest_namespace',
				'rest_controller_class',
				'autosave_rest_controller_class',
				'revisions_rest_controller_class',
				'late_route_registration',
				'template',
				'template_lock',
				'rewrite',
				'_edit_link',
			) as $property
		) {
			if ( $object->$property !== $expected[ $property ] ) {
				return false;
			}
		}

		return true;
	}

	private static function support_maps_match( array $expected, array $actual ): bool {
		return self::maps_match( $expected, $actual );
	}

	private static function support_queries_match( string $post_type, array $expected_supports ): bool {
		foreach ( $expected_supports as $feature => $value ) {
			if ( ! \post_type_supports( $post_type, $feature ) || ! in_array( $post_type, \get_post_types_by_support( $feature ), true ) ) {
				return false;
			}
		}

		foreach ( array( 'title', 'editor', 'autosave', 'thumbnail', 'custom-fields', 'revisions', 'comments' ) as $feature ) {
			if ( ! array_key_exists( $feature, $expected_supports ) && \post_type_supports( $post_type, $feature ) ) {
				return false;
			}
		}

		if (
			isset( $expected_supports['editor'], $expected_supports['autosave'] )
			&& ! in_array( $post_type, \get_post_types_by_support( array( 'editor', 'autosave' ), 'and' ), true )
		) {
			return false;
		}

		return true;
	}

	private static function maps_match( array $expected, array $actual ): bool {
		ksort( $expected );
		ksort( $actual );

		return $expected === $actual;
	}

	private static function sets_match( array $expected, array $actual ): bool {
		sort( $expected );
		sort( $actual );

		return $expected === $actual;
	}

	private static function label_subset_matches( array $expected, object $actual ): bool {
		foreach ( $expected as $property => $value ) {
			if ( ! property_exists( $actual, $property ) || $actual->$property !== $value ) {
				return false;
			}
		}

		return true;
	}

	private static function meta_cap_registry_matches( object $cap, bool $map_meta_cap ): bool {
		global $post_type_meta_caps;

		$expected = array(
			$cap->edit_post   => 'edit_post',
			$cap->read_post   => 'read_post',
			$cap->delete_post => 'delete_post',
		);

		foreach ( $expected as $custom => $core ) {
			if ( $map_meta_cap ) {
				if ( ( $post_type_meta_caps[ $custom ] ?? null ) !== $core ) {
					return false;
				}
			} elseif ( isset( $post_type_meta_caps[ $custom ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function post_type_meta_caps_contain( object $cap ): bool {
		global $post_type_meta_caps;

		foreach ( (array) $cap as $value ) {
			if ( isset( $post_type_meta_caps[ $value ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function cap_meta_registry_subset( ?object $cap ): array {
		global $post_type_meta_caps;

		if ( ! $cap ) {
			return array();
		}

		$subset = array();
		foreach ( (array) $cap as $value ) {
			if ( isset( $post_type_meta_caps[ $value ] ) ) {
				$subset[ $value ] = $post_type_meta_caps[ $value ];
			}
		}

		return $subset;
	}

	private static function taxonomies_contain_object_type( array $taxonomies, string $post_type ): bool {
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! self::taxonomy_contains_object_type( $taxonomy, $post_type ) ) {
				return false;
			}
		}

		return true;
	}

	private static function taxonomies_do_not_contain_object_type( array $taxonomies, string $post_type ): bool {
		foreach ( $taxonomies as $taxonomy ) {
			if ( self::taxonomy_contains_object_type( $taxonomy, $post_type ) ) {
				return false;
			}
		}

		return true;
	}

	private static function taxonomy_contains_object_type( string $taxonomy, string $post_type ): bool {
		global $wp_taxonomies;

		return isset( $wp_taxonomies[ $taxonomy ] )
			&& in_array( $post_type, (array) $wp_taxonomies[ $taxonomy ]->object_type, true );
	}

	private static function rest_expectation_matches( \WP_Post_Type $object ): bool {
		$controller = $object->get_rest_controller();
		if ( ! self::rest_controller_result_matches( $object->show_in_rest, $object->rest_controller_class, $controller, \WP_REST_Posts_Controller::class ) ) {
			return false;
		}

		$revisions = $object->get_revisions_rest_controller();
		if (
			! self::rest_controller_result_matches(
				$object->show_in_rest && \post_type_supports( $object->name, 'revisions' ),
				$object->revisions_rest_controller_class,
				$revisions,
				\WP_REST_Revisions_Controller::class
			)
		) {
			return false;
		}

		$autosaves = $object->get_autosave_rest_controller();
		return self::rest_controller_result_matches(
			$object->show_in_rest && \post_type_supports( $object->name, 'autosave' ),
			$object->autosave_rest_controller_class,
			$autosaves,
			\WP_REST_Autosaves_Controller::class
		);
	}

	private static function rest_controller_result_matches( bool $enabled, $class_arg, $controller, string $default_class ): bool {
		if ( ! $enabled ) {
			return null === $controller;
		}

		$class = $class_arg ? $class_arg : $default_class;
		if ( ! class_exists( $class ) || ! is_subclass_of( $class, \WP_REST_Controller::class ) ) {
			return null === $controller;
		}

		return $controller instanceof $class;
	}

	private static function status_label_count_matches( array $expected, $actual ): bool {
		return is_array( $actual )
			&& ( $actual['singular'] ?? null ) === $expected['singular']
			&& ( $actual['plural'] ?? null ) === $expected['plural']
			&& ( $actual['context'] ?? null ) === $expected['context']
			&& ( $actual['domain'] ?? null ) === $expected['domain'];
	}

	private static function filter_expectation_matches( array $cases, string $property, bool $value, array $actual ): bool {
		$actual_map = array_fill_keys( $actual, true );
		foreach ( $cases as $case ) {
			$expected = self::expected_status_props( $case['name'], $case['args'] );
			$present  = isset( $actual_map[ $case['name'] ] );
			if ( ( $expected[ $property ] === $value ) !== $present ) {
				return false;
			}
		}

		return true;
	}

	private static function status_or_expectation_matches( array $cases, array $criteria, array $actual ): bool {
		$actual_map = array_fill_keys( $actual, true );
		foreach ( $cases as $case ) {
			$expected_props = self::expected_status_props( $case['name'], $case['args'] );
			$expected_match = false;
			foreach ( $criteria as $property => $value ) {
				if ( $expected_props[ $property ] === $value ) {
					$expected_match = true;
					break;
				}
			}

			if ( $expected_match !== isset( $actual_map[ $case['name'] ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function status_and_expectation_matches( array $cases, array $criteria, array $actual ): bool {
		$actual_map = array_fill_keys( $actual, true );
		foreach ( $cases as $case ) {
			$expected_props = self::expected_status_props( $case['name'], $case['args'] );
			$expected_match = true;
			foreach ( $criteria as $property => $value ) {
				if ( $expected_props[ $property ] !== $value ) {
					$expected_match = false;
					break;
				}
			}

			if ( $expected_match !== isset( $actual_map[ $case['name'] ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function rewrite_rules_contain_post_type( string $post_type ): bool {
		global $wp_rewrite;

		if ( ! is_object( $wp_rewrite ) || ! isset( $wp_rewrite->extra_rules_top ) || ! is_array( $wp_rewrite->extra_rules_top ) ) {
			return false;
		}

		foreach ( $wp_rewrite->extra_rules_top as $query ) {
			if ( is_string( $query ) && str_contains( $query, 'post_type=' . $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	private static function registry_shape(): array {
		global $_wp_post_type_features, $post_type_meta_caps, $wp, $wp_rewrite;

		return array(
			'postTypes'    => array_keys( $GLOBALS['wp_post_types'] ?? array() ),
			'supports'     => $_wp_post_type_features ?? array(),
			'metaCaps'     => $post_type_meta_caps ?? array(),
			'queryVars'    => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
			'permastructs' => is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_permastructs )
				? array_keys( $wp_rewrite->extra_permastructs )
				: array(),
			'rewriteRules' => is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_rules_top )
				? $wp_rewrite->extra_rules_top
				: array(),
		);
	}

	private static function reset_registries(): void {
		global $wp, $wp_rewrite;

		$GLOBALS['wp_post_types']          = array();
		$GLOBALS['wp_post_statuses']       = array();
		$GLOBALS['_wp_post_type_features'] = array();
		$GLOBALS['post_type_meta_caps']    = array();
		$GLOBALS['wp_taxonomies']          = array();

		\add_filter( 'pre_option_permalink_structure', array( __CLASS__, 'filter_permalink_structure' ), 0 );

		$wp                    = new \WP();
		$wp->public_query_vars = array();

		$wp_rewrite                  = new \WP_Rewrite();
		$wp_rewrite->permalink_structure = self::PERMALINK_STRUCTURE;
		$wp_rewrite->front          = '/';
		$wp_rewrite->root           = '';
		$wp_rewrite->feeds          = array( 'feed', 'rss2' );
		$wp_rewrite->pagination_base = 'page';
	}

	private static function post_type_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$name = \sanitize_key( $prefix . '_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 10 ) );
		return substr( $name, 0, 20 );
	}

	private static function taxonomy_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$name = \sanitize_key( $prefix . '_' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 16 ) );
		return substr( $name, 0, 32 );
	}

	private static function status_name( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return self::post_type_name( $ctx, 'st_' . substr( $prefix, 0, 6 ) );
	}

	private static function cap_base( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$base = \sanitize_key( $prefix . '_' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
		return '' === $base ? 'cfz_cap' : $base;
	}

	private static function safe_token( string $value ): string {
		$value = strtolower( preg_replace( '/[^A-Za-z0-9_]+/', '_', $value ) ?? '' );
		$value = trim( $value, '_' );

		return '' === $value ? 'x' : $value;
	}

	private static function case_summary( array $case ): array {
		return array(
			'label'         => $case['label'] ?? null,
			'inputName'     => $case['name'],
			'sanitizedName' => \sanitize_key( $case['name'] ),
			'public'        => $case['args']['public'] ?? null,
			'hierarchical'  => $case['args']['hierarchical'] ?? null,
			'showInRest'    => $case['args']['show_in_rest'] ?? null,
			'rewrite'       => $case['args']['rewrite'] ?? null,
			'queryVar'      => $case['args']['query_var'] ?? null,
			'supports'      => $case['args']['supports'] ?? null,
			'taxonomies'    => $case['args']['taxonomies'] ?? array(),
		);
	}

	private static function post_type_summary( \WP_Post_Type $object ): array {
		return array(
			'name'                            => $object->name,
			'public'                          => $object->public,
			'hierarchical'                    => $object->hierarchical,
			'publiclyQueryable'               => $object->publicly_queryable,
			'embeddable'                      => $object->embeddable,
			'showUi'                          => $object->show_ui,
			'showInMenu'                      => $object->show_in_menu,
			'showInAdminBar'                  => $object->show_in_admin_bar,
			'showInNavMenus'                  => $object->show_in_nav_menus,
			'excludeFromSearch'               => $object->exclude_from_search,
			'queryVar'                        => $object->query_var,
			'rewrite'                         => $object->rewrite,
			'hasArchive'                      => $object->has_archive,
			'capabilityType'                  => $object->capability_type,
			'mapMetaCap'                      => $object->map_meta_cap,
			'showInRest'                      => $object->show_in_rest,
			'restBase'                        => $object->rest_base,
			'restNamespace'                   => $object->rest_namespace,
			'restControllerClass'             => $object->rest_controller_class,
			'autosaveRestControllerClass'     => $object->autosave_rest_controller_class,
			'revisionsRestControllerClass'    => $object->revisions_rest_controller_class,
			'lateRouteRegistration'           => $object->late_route_registration,
			'templateLock'                    => $object->template_lock,
		);
	}

	private static function status_summary( object $status ): array {
		return array(
			'name'                  => $status->name ?? null,
			'label'                 => $status->label ?? null,
			'labelCount'            => $status->label_count ?? null,
			'public'                => $status->public ?? null,
			'private'               => $status->private ?? null,
			'protected'             => $status->protected ?? null,
			'internal'              => $status->internal ?? null,
			'publiclyQueryable'     => $status->publicly_queryable ?? null,
			'excludeFromSearch'     => $status->exclude_from_search ?? null,
			'showInAdminAllList'    => $status->show_in_admin_all_list ?? null,
			'showInAdminStatusList' => $status->show_in_admin_status_list ?? null,
			'dateFloating'          => $status->date_floating ?? null,
		);
	}

	private static function cap_summary( object $cap ): array {
		$summary = (array) $cap;
		ksort( $summary );

		return $summary;
	}

	private static function label_summary( object $labels ): array {
		$summary = array();
		foreach (
			array(
				'name',
				'singular_name',
				'name_admin_bar',
				'menu_name',
				'all_items',
				'archives',
				'parent_item_colon',
				'item_link',
				'item_link_description',
				'template_name',
			) as $property
		) {
			$summary[ $property ] = $labels->$property ?? null;
		}

		return $summary;
	}

	private static function rest_summary( \WP_Post_Type $object ): array {
		$controller = $object->get_rest_controller();
		$revisions  = $object->get_revisions_rest_controller();
		$autosaves  = $object->get_autosave_rest_controller();

		return array(
			'showInRest'          => $object->show_in_rest,
			'restBase'            => $object->rest_base,
			'restNamespace'       => $object->rest_namespace,
			'controllerClass'     => $object->rest_controller_class,
			'controller'          => is_object( $controller ) ? get_class( $controller ) : null,
			'revisionsClass'      => $object->revisions_rest_controller_class,
			'revisionsController' => is_object( $revisions ) ? get_class( $revisions ) : null,
			'autosavesClass'      => $object->autosave_rest_controller_class,
			'autosavesController' => is_object( $autosaves ) ? get_class( $autosaves ) : null,
		);
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

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array(), ?string $status = null ): array {
		return array(
			'ok'        => $ok,
			'status'    => $status ?? ( $ok ? 'passed' : 'failed' ),
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'data'      => self::describe_value( $data ),
		);
	}

	private static function skip( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		$data['reason'] = $reason;
		return self::row( $ctx, $invariant, true, $data, 'skipped' );
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_array( $value ) ) {
			if ( $depth >= 4 ) {
				return array(
					'type'  => 'array',
					'count' => count( $value ),
				);
			}

			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 16 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}

			if ( $value instanceof \WP_Error ) {
				return array(
					'type'    => 'WP_Error',
					'code'    => $value->get_error_code(),
					'message' => $value->get_error_message(),
				);
			}

			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'type'    => 'string',
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => self::escape_bytes( $value ),
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

	private static function escape_bytes( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; ++$i ) {
			$byte = ord( $value[ $i ] );
			if ( 0x5C === $byte ) {
				$out .= '\\\\';
			} elseif ( $byte >= 0x20 && $byte <= 0x7E ) {
				$out .= chr( $byte );
			} elseif ( 0x0A === $byte ) {
				$out .= '\\n';
			} elseif ( 0x0D === $byte ) {
				$out .= '\\r';
			} elseif ( 0x09 === $byte ) {
				$out .= '\\t';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $shown ) {
			$out .= '...';
		}

		return $out;
	}

	private static function snapshot_globals(): array {
		$globals = array();
		foreach (
			array(
				'_wp_post_type_features',
				'post_type_meta_caps',
				'wp',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_post_statuses',
				'wp_post_types',
				'wp_rewrite',
				'wp_taxonomies',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array( 'globals' => $globals );
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function clone_value( $value ) {
		if ( $value instanceof \Closure ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			try {
				return clone $value;
			} catch ( \Throwable $e ) {
				return $value;
			}
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

	private static function values_equal( $expected, $actual ): bool {
		if ( gettype( $expected ) !== gettype( $actual ) ) {
			return false;
		}

		if ( is_array( $expected ) ) {
			if ( array_keys( $expected ) !== array_keys( $actual ) ) {
				return false;
			}

			foreach ( $expected as $key => $value ) {
				if ( ! self::values_equal( $value, $actual[ $key ] ) ) {
					return false;
				}
			}

			return true;
		}

		if ( is_object( $expected ) ) {
			if ( $expected instanceof \Closure || $actual instanceof \Closure ) {
				return $expected === $actual;
			}

			if ( get_class( $expected ) !== get_class( $actual ) ) {
				return false;
			}

			return self::values_equal( (array) $expected, (array) $actual );
		}

		return $expected === $actual;
	}

	private static function first_value_difference( $expected, $actual, string $path = '$' ): ?array {
		if ( gettype( $expected ) !== gettype( $actual ) ) {
			return array(
				'path'     => $path,
				'expected' => gettype( $expected ),
				'actual'   => gettype( $actual ),
			);
		}

		if ( is_array( $expected ) ) {
			$expected_keys = array_keys( $expected );
			$actual_keys   = array_keys( $actual );
			if ( $expected_keys !== $actual_keys ) {
				return array(
					'path'     => $path,
					'expected' => $expected_keys,
					'actual'   => $actual_keys,
				);
			}

			foreach ( $expected as $key => $value ) {
				$difference = self::first_value_difference( $value, $actual[ $key ], $path . '[' . var_export( $key, true ) . ']' );
				if ( null !== $difference ) {
					return $difference;
				}
			}

			return null;
		}

		if ( is_object( $expected ) ) {
			if ( $expected instanceof \Closure || $actual instanceof \Closure ) {
				return $expected === $actual
					? null
					: array(
						'path'     => $path,
						'expected' => 'closure',
						'actual'   => 'different closure',
					);
			}

			if ( get_class( $expected ) !== get_class( $actual ) ) {
				return array(
					'path'     => $path,
					'expected' => get_class( $expected ),
					'actual'   => get_class( $actual ),
				);
			}

			return self::first_value_difference( (array) $expected, (array) $actual, $path . '<' . get_class( $expected ) . '>' );
		}

		if ( $expected !== $actual ) {
			return array(
				'path'     => $path,
				'expected' => self::describe_value( $expected ),
				'actual'   => self::describe_value( $actual ),
			);
		}

		return null;
	}
}
