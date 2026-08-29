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
			$rows[] = self::check_duplicate_post_type_registration_replacement( $ctx->fork( 'duplicate-registration' ) );
			$rows[] = self::check_registration_filters_actions_and_meta_box_lifecycle( $ctx );
			$rows[] = self::check_rest_route_registration_boundaries( $ctx );
			$rows[] = self::check_invalid_post_type_names_do_not_leak( $ctx );
			$rows[] = self::check_post_type_query_operators( $ctx );
			$rows[] = self::check_support_feature_mutation( $ctx );
			$rows[] = self::check_post_type_unregister_cleanup( $ctx );
			$rows[] = self::check_post_type_archive_link_helpers( $ctx );
			$rows[] = self::check_custom_post_type_rewrite_link_matrix( $ctx->fork( 'custom-rewrite-link-matrix' ) );
			$rows[] = self::check_post_status_defaults_and_filters( $ctx );
			$rows[] = self::check_post_status_viewability( $ctx->fork( 'status-viewability' ) );
			$rows[] = self::check_post_status_name_sanitization_and_overwrite( $ctx->fork( 'status-name-sanitization' ) );
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

		self::load_rest_route_dependencies();

		foreach (
			array(
				'WP',
				'WP_Error',
				'WP_Post_Type',
				'WP_Rewrite',
				'WP_REST_Autosaves_Controller',
				'WP_REST_Controller',
				'WP_REST_Meta_Fields',
				'WP_REST_Post_Meta_Fields',
				'WP_REST_Posts_Controller',
				'WP_REST_Revisions_Controller',
				'WP_REST_Server',
				'WP_Taxonomy',
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
				'add_post_type_support',
				'add_query_arg',
				'do_action',
				'get_post_type_archive_feed_link',
				'get_post_type_archive_link',
				'get_all_post_type_supports',
				'home_url',
				'get_object_taxonomies',
				'get_post_stati',
				'get_post_status_object',
				'get_post_type_labels',
				'get_post_type_object',
				'get_post_types',
				'get_post_types_by_support',
				'has_action',
				'has_filter',
				'is_post_type_viewable',
				'is_post_status_viewable',
				'is_wp_error',
				'post_type_exists',
				'post_type_supports',
				'register_post_status',
				'register_post_type',
				'register_rest_route',
				'register_taxonomy',
				'remove_action',
				'remove_filter',
				'remove_post_type_support',
				'rest_get_server',
				'sanitize_key',
				'sanitize_title_with_dashes',
				'trailingslashit',
				'unregister_post_type',
				'user_trailingslashit',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function load_rest_route_dependencies(): void {
		$root  = dirname( __DIR__, 3 );
		$files = array(
			'wp-includes/rest-api/class-wp-rest-server.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-controller.php',
			'wp-includes/rest-api/fields/class-wp-rest-meta-fields.php',
			'wp-includes/rest-api/fields/class-wp-rest-post-meta-fields.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-revisions-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-autosaves-controller.php',
		);

		foreach ( $files as $file ) {
			$path = $root . '/src/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
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

	private static function check_duplicate_post_type_registration_replacement( \ComponentFuzz\FuzzContext $ctx ): array {
		global $_wp_post_type_features, $post_type_meta_caps, $wp, $wp_rewrite;

		self::reset_registries();

		$failures     = array();
		$token        = self::safe_token( substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ) );
		$post_type    = self::post_type_name( $ctx->fork( 'name' ), 'dupe' );
		$duplicate    = strtoupper( $post_type );
		$tax_first    = self::taxonomy_name( $ctx->fork( 'tax-first' ), 'tax_first' );
		$tax_second   = self::taxonomy_name( $ctx->fork( 'tax-second' ), 'tax_second' );
		$query_first  = 'First Query ' . $token;
		$query_second = 'Second Query ' . $token;

		$tax_first_object  = \register_taxonomy(
			$tax_first,
			array(),
			array(
				'public'    => false,
				'rewrite'   => false,
				'query_var' => false,
			)
		);
		$tax_second_object = \register_taxonomy(
			$tax_second,
			array(),
			array(
				'public'    => false,
				'rewrite'   => false,
				'query_var' => false,
			)
		);

		$first_args = array(
			'label'           => 'Duplicate First ' . $token,
			'public'          => true,
			'has_archive'     => true,
			'rewrite'         => array(
				'slug'       => 'duplicate-first/' . $token,
				'with_front' => false,
				'pages'      => true,
				'feeds'      => true,
			),
			'query_var'       => $query_first,
			'taxonomies'      => array( $tax_first ),
			'supports'        => array( 'title', 'editor', 'thumbnail' ),
			'capability_type' => array( 'first_item', 'first_items' ),
			'map_meta_cap'    => true,
		);
		$second_args = array(
			'labels'          => array(
				'name'          => 'Duplicate Second ' . $token,
				'singular_name' => 'Duplicate Second Item ' . $token,
			),
			'public'          => true,
			'hierarchical'    => true,
			'has_archive'     => true,
			'rewrite'         => array(
				'slug'       => 'duplicate-second/' . $token,
				'with_front' => false,
				'pages'      => false,
				'feeds'      => false,
				'ep_mask'    => EP_NONE,
			),
			'query_var'       => $query_second,
			'taxonomies'      => array( $tax_second ),
			'supports'        => array( 'excerpt', 'comments' ),
			'capability_type' => array( 'second_item', 'second_items' ),
			'map_meta_cap'    => true,
			'_edit_link'      => 'post.php?post=%d&duplicate=1',
		);

		$first       = \register_post_type( $post_type, $first_args );
		$after_first = array(
			'queryVars'       => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
			'permastruct'     => is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_permastructs[ $post_type ] )
				? $wp_rewrite->extra_permastructs[ $post_type ]
				: null,
			'rewriteRegexes'  => self::rewrite_rule_regexes_for_post_type( $post_type ),
			'supports'        => \get_all_post_type_supports( $post_type ),
			'taxonomies'      => \get_object_taxonomies( $post_type, 'names' ),
			'metaCaps'        => $first instanceof \WP_Post_Type ? self::cap_meta_registry_subset( $first->cap ) : array(),
			'futureHook'      => \has_action( 'future_' . $post_type, '_future_post_hook' ),
			'registeredClass' => is_object( $first ) ? get_class( $first ) : gettype( $first ),
		);

		$second            = \register_post_type( $duplicate, $second_args );
		$expected          = self::expected_post_type_props( $post_type, $second_args );
		$expected_cap      = self::expected_capabilities( $second_args );
		$first_supports    = self::expected_supports( $first_args['supports'] );
		$second_supports   = self::expected_supports( $second_args['supports'] );
		$rewrite_regexes   = self::rewrite_rule_regexes_for_post_type( $post_type );
		$first_query_var   = self::expected_query_var( $post_type, $query_first );
		$second_query_var  = self::expected_query_var( $post_type, $query_second );
		$first_rewrite     = self::expected_rewrite( $post_type, $first_args );
		$second_rewrite    = self::expected_rewrite( $post_type, $second_args );
		$after_second      = array(
			'object'         => $second instanceof \WP_Post_Type ? self::post_type_summary( $second ) : self::describe_value( $second ),
			'queryVars'      => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
			'permastruct'    => is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_permastructs[ $post_type ] )
				? $wp_rewrite->extra_permastructs[ $post_type ]
				: null,
			'rewriteRegexes' => $rewrite_regexes,
			'supports'       => \get_all_post_type_supports( $post_type ),
			'taxonomies'     => \get_object_taxonomies( $post_type, 'names' ),
			'metaCaps'       => $post_type_meta_caps ?? array(),
			'futureHook'     => \has_action( 'future_' . $post_type, '_future_post_hook' ),
		);

		self::collect_failure(
			$failures,
			$tax_first_object instanceof \WP_Taxonomy
				&& $tax_second_object instanceof \WP_Taxonomy
				&& $first instanceof \WP_Post_Type
				&& $second instanceof \WP_Post_Type
				&& $first !== $second
				&& $post_type === \sanitize_key( $duplicate )
				&& $second === \get_post_type_object( $post_type )
				&& \post_type_exists( $post_type )
				&& self::post_type_props_match( $second, $expected )
				&& self::maps_match( $expected_cap, (array) $second->cap ),
			'duplicate post type registration replaces the global object with latest args',
			array(
				'postType'    => $post_type,
				'duplicate'   => $duplicate,
				'taxonomies'  => array( $tax_first, $tax_second ),
				'afterFirst'  => $after_first,
				'afterSecond' => $after_second,
				'expected'    => $expected,
				'expectedCap' => $expected_cap,
			)
		);

		self::collect_failure(
			$failures,
			$first instanceof \WP_Post_Type
				&& $second instanceof \WP_Post_Type
				&& self::support_maps_match( $first_supports, $after_first['supports'] )
				&& in_array( $first_query_var, $after_first['queryVars'], true )
				&& self::rewrite_regexes_include_slug( $after_first['rewriteRegexes'], $first_rewrite['slug'] )
				&& self::sets_match( array( $tax_first ), $after_first['taxonomies'] )
				&& array() !== $after_first['metaCaps']
				&& self::support_maps_match( $second_supports, \get_all_post_type_supports( $post_type ) )
				&& self::sets_match( array( $tax_second ), \get_object_taxonomies( $post_type, 'names' ) )
				&& ! self::taxonomy_contains_object_type( $tax_first, $post_type )
				&& self::taxonomy_contains_object_type( $tax_second, $post_type )
				&& ! self::query_var_is_public( $first_query_var )
				&& self::query_var_is_public( $second_query_var )
				&& self::permastruct_uses_slug( $post_type, $second_rewrite['slug'] )
				&& ! self::rewrite_regexes_include_slug( $rewrite_regexes, $first_rewrite['slug'] )
				&& self::rewrite_regexes_include_slug( $rewrite_regexes, $second_rewrite['slug'] )
				&& ! self::post_type_meta_caps_contain( $first->cap )
				&& self::meta_cap_registry_matches( $second->cap, true )
				&& 5 === \has_action( 'future_' . $post_type, '_future_post_hook' ),
			'duplicate post type registration replaces supports, taxonomy links, query vars, rewrite rules, and meta caps',
			array(
				'postType'         => $post_type,
				'firstSupports'    => $first_supports,
				'secondSupports'   => $second_supports,
				'afterFirst'       => $after_first,
				'afterSecond'      => $after_second,
				'firstRewrite'     => $first_rewrite,
				'secondRewrite'    => $second_rewrite,
			)
		);

		$unregistered     = \unregister_post_type( $post_type );
		$after_unregister = array(
			'exists'               => \post_type_exists( $post_type ),
			'object'               => \get_post_type_object( $post_type ),
			'supports'             => $_wp_post_type_features[ $post_type ] ?? null,
			'queryVars'            => is_object( $wp ) && isset( $wp->public_query_vars ) ? $wp->public_query_vars : array(),
			'permastructs'         => is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_permastructs )
				? array_keys( $wp_rewrite->extra_permastructs )
				: array(),
			'rewriteRegexes'       => self::rewrite_rule_regexes_for_post_type( $post_type ),
			'taxonomies'           => \get_object_taxonomies( $post_type, 'names' ),
			'firstTaxonomyLinked'  => self::taxonomy_contains_object_type( $tax_first, $post_type ),
			'secondTaxonomyLinked' => self::taxonomy_contains_object_type( $tax_second, $post_type ),
			'firstQueryVarPublic'  => self::query_var_is_public( $first_query_var ),
			'secondQueryVarPublic' => self::query_var_is_public( $second_query_var ),
			'firstMetaCaps'        => $first instanceof \WP_Post_Type ? self::cap_meta_registry_subset( $first->cap ) : array(),
			'secondMetaCaps'       => $second instanceof \WP_Post_Type ? self::cap_meta_registry_subset( $second->cap ) : array(),
			'futureHook'           => \has_action( 'future_' . $post_type, '_future_post_hook' ),
		);

		self::collect_failure(
			$failures,
			$second instanceof \WP_Post_Type
				&& true === $unregistered
				&& ! \post_type_exists( $post_type )
				&& null === \get_post_type_object( $post_type )
				&& ! isset( $_wp_post_type_features[ $post_type ] )
				&& ! self::taxonomy_contains_object_type( $tax_first, $post_type )
				&& ! self::taxonomy_contains_object_type( $tax_second, $post_type )
				&& ! self::query_var_is_public( $first_query_var )
				&& ! self::query_var_is_public( $second_query_var )
				&& (
					! is_object( $wp_rewrite )
					|| ! isset( $wp_rewrite->extra_permastructs )
					|| ! array_key_exists( $post_type, $wp_rewrite->extra_permastructs )
				)
				&& ! self::rewrite_rules_contain_post_type( $post_type )
				&& ! self::post_type_meta_caps_contain( $first->cap )
				&& ! self::post_type_meta_caps_contain( $second->cap )
				&& ! \has_action( 'future_' . $post_type, '_future_post_hook' ),
			'unregister_post_type after duplicate registration removes the latest object and shared registries',
			array(
				'postType'          => $post_type,
				'unregistered'      => $unregistered,
				'afterUnregistered' => $after_unregister,
			)
		);

		return self::row(
			$ctx,
			'post-types.duplicate-registration.replaces-object-and-cleans-side-registries',
			array() === $failures,
			array(
				'postType' => $post_type,
				'failures' => $failures,
			)
		);
	}

	private static function check_registration_filters_actions_and_meta_box_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$failures       = array();
		$case           = $ctx->fork( 'lifecycle-hooks' );
		$post_type      = self::post_type_name( $case->fork( 'type' ), 'hooks' );
		$token          = self::safe_token( $case->identifier( 4, 10 ) );
		$query_var      = 'filtered query ' . $token;
		$sanitized_qv   = \sanitize_title_with_dashes( $query_var );
		$global_calls   = array();
		$dynamic_calls  = array();
		$action_calls   = array();
		$meta_box_calls = array();
		$view_calls     = array();
		$unregistered   = array();

		$meta_box_cb = static function ( $post ) use ( &$meta_box_calls ): void {
			$meta_box_calls[] = is_object( $post ) ? ( $post->post_type ?? null ) : null;
		};

		$global_filter = static function ( array $args, string $seen_post_type ) use ( &$global_calls, $post_type, $token, $meta_box_cb ): array {
			$global_calls[] = array(
				'postType'     => $seen_post_type,
				'basePublic'   => $args['public'] ?? null,
				'baseRest'     => $args['show_in_rest'] ?? null,
				'baseSupports' => $args['supports'] ?? null,
			);

			if ( $seen_post_type !== $post_type ) {
				return $args;
			}

			$args['public']               = true;
			$args['show_in_rest']         = true;
			$args['rest_base']            = 'global-rest-' . $token;
			$args['register_meta_box_cb'] = $meta_box_cb;
			$args['labels']               = array(
				'name'          => 'Global Items ' . $token,
				'singular_name' => 'Global Item ' . $token,
			);

			return $args;
		};

		$dynamic_filter = static function ( array $args, string $seen_post_type ) use ( &$dynamic_calls, $post_type, $token, $query_var ): array {
			$dynamic_calls[] = array(
				'postType'     => $seen_post_type,
				'globalPublic' => $args['public'] ?? null,
				'globalRest'   => $args['show_in_rest'] ?? null,
				'globalBase'   => $args['rest_base'] ?? null,
			);

			if ( $seen_post_type !== $post_type ) {
				return $args;
			}

			$args['publicly_queryable'] = false;
			$args['show_ui']            = true;
			$args['show_in_menu']       = 'tools.php';
			$args['query_var']          = $query_var;
			$args['rest_base']          = 'dynamic-rest-' . $token;
			$args['supports']           = array( 'editor', 'revisions' );
			$args['capability_type']    = array( 'filtered_item', 'filtered_items' );
			$args['map_meta_cap']       = true;
			$args['rewrite']            = false;
			$args['labels']['name']     = 'Dynamic Items ' . $token;

			return $args;
		};

		$registered_action = static function ( string $seen_post_type, \WP_Post_Type $object ) use ( &$action_calls, $post_type, $meta_box_cb ): void {
			$action_calls[] = array(
				'hook'          => 'registered_post_type',
				'postType'      => $seen_post_type,
				'objectSame'    => $object === \get_post_type_object( $seen_post_type ),
				'exists'        => \post_type_exists( $seen_post_type ),
				'metaBoxHook'   => \has_action( 'add_meta_boxes_' . $post_type, $meta_box_cb ),
				'futureHook'    => \has_action( 'future_' . $seen_post_type, '_future_post_hook' ),
			);
		};

		$registered_specific_action = static function ( string $seen_post_type, \WP_Post_Type $object ) use ( &$action_calls ): void {
			$action_calls[] = array(
				'hook'       => 'registered_post_type_dynamic',
				'postType'   => $seen_post_type,
				'objectSame' => $object === \get_post_type_object( $seen_post_type ),
				'exists'     => \post_type_exists( $seen_post_type ),
			);
		};

		$unregistered_action = static function ( string $seen_post_type ) use ( &$unregistered, $meta_box_cb ): void {
			$unregistered[] = array(
				'postType'      => $seen_post_type,
				'exists'        => \post_type_exists( $seen_post_type ),
				'object'        => \get_post_type_object( $seen_post_type ),
				'metaBoxHook'   => \has_action( 'add_meta_boxes_' . $seen_post_type, $meta_box_cb ),
				'futureHook'    => \has_action( 'future_' . $seen_post_type, '_future_post_hook' ),
			);
		};

		$viewability_non_bool = static function ( bool $is_viewable, \WP_Post_Type $object ) use ( &$view_calls, $post_type ) {
			if ( $object->name === $post_type ) {
				$view_calls[] = array( 'mode' => 'string', 'base' => $is_viewable );
				return '1';
			}

			return $is_viewable;
		};

		$viewability_bool = static function ( bool $is_viewable, \WP_Post_Type $object ) use ( &$view_calls, $post_type ): bool {
			if ( $object->name === $post_type ) {
				$view_calls[] = array( 'mode' => 'bool', 'base' => $is_viewable );
				return true;
			}

			return $is_viewable;
		};

		$object            = null;
		$view_default      = null;
		$view_non_bool     = null;
		$view_bool         = null;
		$unregister_result = null;
		$supports_after_register = array();
		$meta_box_hook_after_register = false;

		\add_filter( 'register_post_type_args', $global_filter, 10, 2 );
		\add_filter( 'register_' . $post_type . '_post_type_args', $dynamic_filter, 10, 2 );
		\add_action( 'registered_post_type', $registered_action, 10, 2 );
		\add_action( 'registered_post_type_' . $post_type, $registered_specific_action, 10, 2 );
		\add_action( 'unregistered_post_type', $unregistered_action, 10, 1 );

		try {
			$object = \register_post_type(
				$post_type,
				array(
					'public'       => false,
					'show_in_rest' => false,
					'rewrite'      => true,
					'query_var'    => false,
					'supports'     => false,
				)
			);

			\do_action(
				'add_meta_boxes_' . $post_type,
				(object) array(
					'ID'        => $case->int( 1, 9999 ),
					'post_type' => $post_type,
				)
			);

			$supports_after_register      = \get_all_post_type_supports( $post_type );
			$meta_box_hook_after_register = \has_action( 'add_meta_boxes_' . $post_type, $meta_box_cb );
			$view_default = \is_post_type_viewable( $post_type );
			\add_filter( 'is_post_type_viewable', $viewability_non_bool, 10, 2 );
			$view_non_bool = \is_post_type_viewable( $post_type );
			\remove_filter( 'is_post_type_viewable', $viewability_non_bool, 10 );
			\add_filter( 'is_post_type_viewable', $viewability_bool, 10, 2 );
			$view_bool = \is_post_type_viewable( $post_type );
			\remove_filter( 'is_post_type_viewable', $viewability_bool, 10 );

			$unregister_result = \unregister_post_type( $post_type );
		} finally {
			\remove_filter( 'register_post_type_args', $global_filter, 10 );
			\remove_filter( 'register_' . $post_type . '_post_type_args', $dynamic_filter, 10 );
			\remove_filter( 'is_post_type_viewable', $viewability_non_bool, 10 );
			\remove_filter( 'is_post_type_viewable', $viewability_bool, 10 );
			\remove_action( 'registered_post_type', $registered_action, 10 );
			\remove_action( 'registered_post_type_' . $post_type, $registered_specific_action, 10 );
			\remove_action( 'unregistered_post_type', $unregistered_action, 10 );
		}

		self::collect_failure(
			$failures,
			$object instanceof \WP_Post_Type
				&& array(
					array(
						'postType'     => $post_type,
						'basePublic'   => false,
						'baseRest'     => false,
						'baseSupports' => false,
					),
				) === $global_calls
				&& array(
					array(
						'postType'     => $post_type,
						'globalPublic' => true,
						'globalRest'   => true,
						'globalBase'   => 'global-rest-' . $token,
					),
				) === $dynamic_calls
				&& true === $object->public
				&& false === $object->publicly_queryable
				&& true === $object->show_ui
				&& 'tools.php' === $object->show_in_menu
				&& $sanitized_qv === $object->query_var
				&& 'dynamic-rest-' . $token === $object->rest_base
				&& 'Dynamic Items ' . $token === $object->labels->name
				&& isset( $supports_after_register['editor'], $supports_after_register['autosave'], $supports_after_register['revisions'] ),
			'registration filters apply in global-then-dynamic order before object hooks',
			array(
				'postType'     => $post_type,
				'globalCalls'  => $global_calls,
				'dynamicCalls' => $dynamic_calls,
				'object'       => $object instanceof \WP_Post_Type ? self::post_type_summary( $object ) : self::describe_value( $object ),
				'supports'     => $supports_after_register,
			)
		);

		self::collect_failure(
			$failures,
			array(
				array(
					'hook'          => 'registered_post_type',
					'postType'      => $post_type,
					'objectSame'    => true,
					'exists'        => true,
					'metaBoxHook'   => 10,
					'futureHook'    => 5,
				),
				array(
					'hook'       => 'registered_post_type_dynamic',
					'postType'   => $post_type,
					'objectSame' => true,
					'exists'     => true,
				),
			) === $action_calls
				&& array( $post_type ) === $meta_box_calls
				&& 10 === $meta_box_hook_after_register,
			'registered post type actions see live object and meta-box callback is invocable',
			array(
				'actionCalls'  => $action_calls,
				'metaBoxCalls' => $meta_box_calls,
				'metaBoxHook'  => $meta_box_hook_after_register,
			)
		);

		self::collect_failure(
			$failures,
			false === $view_default
				&& false === $view_non_bool
				&& true === $view_bool
				&& array(
					array( 'mode' => 'string', 'base' => false ),
					array( 'mode' => 'bool', 'base' => false ),
				) === $view_calls,
			'is_post_type_viewable filter accepts strict boolean true only',
			array(
				'default' => $view_default,
				'string'  => $view_non_bool,
				'bool'    => $view_bool,
				'calls'   => $view_calls,
			)
		);

		self::collect_failure(
			$failures,
			true === $unregister_result
				&& array(
					array(
						'postType'      => $post_type,
						'exists'        => false,
						'object'        => null,
						'metaBoxHook'   => false,
						'futureHook'    => false,
					),
				) === $unregistered
				&& false === \has_filter( 'register_post_type_args', $global_filter )
				&& false === \has_filter( 'register_' . $post_type . '_post_type_args', $dynamic_filter )
				&& false === \has_filter( 'is_post_type_viewable', $viewability_non_bool )
				&& false === \has_filter( 'is_post_type_viewable', $viewability_bool )
				&& false === \has_action( 'registered_post_type', $registered_action )
				&& false === \has_action( 'registered_post_type_' . $post_type, $registered_specific_action )
				&& false === \has_action( 'unregistered_post_type', $unregistered_action )
				&& false === \has_action( 'add_meta_boxes_' . $post_type, $meta_box_cb ),
			'unregistered_post_type action runs after object, hooks, and meta boxes are removed',
			array(
				'unregisterResult' => $unregister_result,
				'unregistered'     => $unregistered,
				'filterState'      => array(
					'global'      => \has_filter( 'register_post_type_args', $global_filter ),
					'dynamic'     => \has_filter( 'register_' . $post_type . '_post_type_args', $dynamic_filter ),
					'viewString'  => \has_filter( 'is_post_type_viewable', $viewability_non_bool ),
					'viewBool'    => \has_filter( 'is_post_type_viewable', $viewability_bool ),
					'registered'  => \has_action( 'registered_post_type', $registered_action ),
					'specific'    => \has_action( 'registered_post_type_' . $post_type, $registered_specific_action ),
					'unregister'  => \has_action( 'unregistered_post_type', $unregistered_action ),
					'metaBoxHook' => \has_action( 'add_meta_boxes_' . $post_type, $meta_box_cb ),
				),
			)
		);

		return self::row(
			$ctx,
			'post-types.registration-filters-actions-and-meta-box-lifecycle',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_rest_route_registration_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_rest_server;

		self::reset_registries();

		$failures = array();
		$case     = $ctx->fork( 'rest-routes' );
		$token    = self::safe_token( $case->identifier( 4, 10 ) );
		$routes   = array();

		$route_cases = array(
			array(
				'label'     => 'early full controllers',
				'postType'  => self::post_type_name( $case->fork( 'early' ), 'rte' ),
				'namespace' => 'component-fuzz/v' . $case->int( 1, 4 ),
				'base'      => 'early-' . $token,
				'args'      => array(
					'public'                  => true,
					'show_in_rest'            => true,
					'rewrite'                 => false,
					'query_var'               => false,
					'supports'                => array( 'editor', 'revisions' ),
					'late_route_registration' => false,
				),
				'main'      => true,
				'revisions' => true,
				'autosaves' => true,
				'late'      => false,
			),
			array(
				'label'     => 'late full controllers',
				'postType'  => self::post_type_name( $case->fork( 'late' ), 'rtl' ),
				'namespace' => 'component-fuzz/v' . $case->int( 5, 8 ),
				'base'      => 'late-' . $token,
				'args'      => array(
					'public'                  => true,
					'show_in_rest'            => true,
					'rewrite'                 => false,
					'query_var'               => false,
					'supports'                => array( 'editor', 'revisions' ),
					'late_route_registration' => true,
				),
				'main'      => true,
				'revisions' => true,
				'autosaves' => true,
				'late'      => true,
			),
			array(
				'label'     => 'editor only autosaves',
				'postType'  => self::post_type_name( $case->fork( 'autosaves' ), 'rta' ),
				'namespace' => 'component-fuzz/v' . $case->int( 9, 12 ),
				'base'      => 'autosaves-' . $token,
				'args'      => array(
					'public'       => true,
					'show_in_rest' => true,
					'rewrite'      => false,
					'query_var'    => false,
					'supports'     => array( 'editor' ),
				),
				'main'      => true,
				'revisions' => false,
				'autosaves' => true,
				'late'      => false,
			),
			array(
				'label'     => 'revisions without autosaves',
				'postType'  => self::post_type_name( $case->fork( 'revisions' ), 'rtr' ),
				'namespace' => 'component-fuzz/v' . $case->int( 13, 16 ),
				'base'      => 'revisions-' . $token,
				'args'      => array(
					'public'       => true,
					'show_in_rest' => true,
					'rewrite'      => false,
					'query_var'    => false,
					'supports'     => array( 'title', 'revisions' ),
				),
				'main'      => true,
				'revisions' => true,
				'autosaves' => false,
				'late'      => false,
			),
			array(
				'label'     => 'rest disabled',
				'postType'  => self::post_type_name( $case->fork( 'disabled' ), 'rtd' ),
				'namespace' => 'component-fuzz/v' . $case->int( 17, 20 ),
				'base'      => 'disabled-' . $token,
				'args'      => array(
					'public'       => true,
					'show_in_rest' => false,
					'rewrite'      => false,
					'query_var'    => false,
					'supports'     => array( 'editor', 'revisions' ),
				),
				'main'      => false,
				'revisions' => false,
				'autosaves' => false,
				'late'      => false,
			),
			array(
				'label'     => 'invalid main controller',
				'postType'  => self::post_type_name( $case->fork( 'invalid-main' ), 'rtm' ),
				'namespace' => 'component-fuzz/v' . $case->int( 21, 24 ),
				'base'      => 'invalid-main-' . $token,
				'args'      => array(
					'public'                => true,
					'show_in_rest'          => true,
					'rewrite'               => false,
					'query_var'             => false,
					'supports'              => array( 'editor', 'revisions' ),
					'rest_controller_class' => \stdClass::class,
				),
				'main'      => false,
				'revisions' => false,
				'autosaves' => false,
				'late'      => false,
			),
			array(
				'label'     => 'invalid ancillary controllers',
				'postType'  => self::post_type_name( $case->fork( 'invalid-ancillary' ), 'rti' ),
				'namespace' => 'component-fuzz/v' . $case->int( 25, 28 ),
				'base'      => 'invalid-ancillary-' . $token,
				'args'      => array(
					'public'                          => true,
					'show_in_rest'                    => true,
					'rewrite'                         => false,
					'query_var'                       => false,
					'supports'                        => array( 'editor', 'revisions' ),
					'autosave_rest_controller_class'  => \stdClass::class,
					'revisions_rest_controller_class' => \stdClass::class,
				),
				'main'      => true,
				'revisions' => false,
				'autosaves' => false,
				'late'      => false,
			),
		);

		foreach ( $route_cases as $index => &$route_case ) {
			$route_case['args']['rest_namespace'] = $route_case['namespace'];
			$route_case['args']['rest_base']      = $route_case['base'];
			$registered = \register_post_type( $route_case['postType'], $route_case['args'] );

			self::collect_failure(
				$failures,
				$registered instanceof \WP_Post_Type,
				"REST route post type registration succeeds case {$index}",
				array(
					'case'   => self::rest_route_case_summary( $route_case ),
					'actual' => self::describe_value( $registered ),
				)
			);
		}
		unset( $route_case );

		$had_rest_server      = array_key_exists( 'wp_rest_server', $GLOBALS );
		$previous_rest_server = $had_rest_server ? $GLOBALS['wp_rest_server'] : null;

		try {
			$wp_rest_server = new \WP_REST_Server();
			$route_diagnostics = self::register_post_type_rest_routes(
				array_map(
					static function ( array $route_case ): string {
						return $route_case['postType'];
					},
					$route_cases
				)
			);

			self::collect_failure(
				$failures,
				array() === $route_diagnostics,
				'REST route registration runs inside rest_api_init without diagnostics',
				array( 'diagnostics' => $route_diagnostics )
			);

			foreach ( $route_cases as $index => $route_case ) {
				$namespace = $route_case['namespace'];
				$base      = $route_case['base'];
				$routes    = \rest_get_server()->get_routes( $namespace );
				$route_keys = array_keys( $routes );
				$route_base = '/' . trim( $namespace, '/' ) . '/' . $base;
				$expected   = array(
					'mainCollection'      => $route_base,
					'mainItem'            => $route_base . '/(?P<id>[\d]+)',
					'revisionsCollection' => $route_base . '/(?P<parent>[\d]+)/revisions',
					'revisionsItem'       => $route_base . '/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)',
					'autosavesCollection' => $route_base . '/(?P<id>[\d]+)/autosaves',
					'autosavesItem'       => $route_base . '/(?P<parent>[\d]+)/autosaves/(?P<id>[\d]+)',
				);
				$present    = array(
					'mainCollection'      => self::route_exists( $routes, $expected['mainCollection'] ),
					'mainItem'            => self::route_exists( $routes, $expected['mainItem'] ),
					'revisionsCollection' => self::route_exists( $routes, $expected['revisionsCollection'] ),
					'revisionsItem'       => self::route_exists( $routes, $expected['revisionsItem'] ),
					'autosavesCollection' => self::route_exists( $routes, $expected['autosavesCollection'] ),
					'autosavesItem'       => self::route_exists( $routes, $expected['autosavesItem'] ),
				);

				self::collect_failure(
					$failures,
					$route_case['main'] === ( $present['mainCollection'] && $present['mainItem'] )
						&& $route_case['revisions'] === ( $present['revisionsCollection'] && $present['revisionsItem'] )
						&& $route_case['autosaves'] === ( $present['autosavesCollection'] && $present['autosavesItem'] ),
					"REST route presence follows show_in_rest, controller validity, and supports case {$index}",
					array(
						'case'     => self::rest_route_case_summary( $route_case ),
						'expected' => array(
							'main'      => $route_case['main'],
							'revisions' => $route_case['revisions'],
							'autosaves' => $route_case['autosaves'],
						),
						'present'  => $present,
						'routes'   => $route_keys,
					)
				);

				if ( $route_case['main'] && $route_case['revisions'] && $route_case['autosaves'] ) {
					$main_index      = self::route_index( $route_keys, $expected['mainCollection'] );
					$revisions_index = self::route_index( $route_keys, $expected['revisionsCollection'] );
					$autosaves_index = self::route_index( $route_keys, $expected['autosavesCollection'] );

					self::collect_failure(
						$failures,
						null !== $main_index
							&& null !== $revisions_index
							&& null !== $autosaves_index
							&& (
								$route_case['late']
									? $revisions_index < $main_index && $autosaves_index < $main_index
									: $main_index < $revisions_index && $main_index < $autosaves_index
							),
						"REST route order follows late_route_registration case {$index}",
						array(
							'case'     => self::rest_route_case_summary( $route_case ),
							'indexes'  => array(
								'main'      => $main_index,
								'revisions' => $revisions_index,
								'autosaves' => $autosaves_index,
							),
							'routes'   => $route_keys,
						)
					);
				}
			}
		} finally {
			if ( $had_rest_server ) {
				$GLOBALS['wp_rest_server'] = $previous_rest_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}
		}

		return self::row(
			$ctx,
			'post-types.rest-routes.show-in-rest-supports-and-late-registration',
			array() === $failures,
			array(
				'cases'    => count( $route_cases ),
				'failures' => array_slice( $failures, 0, 8 ),
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

	private static function check_post_type_query_operators( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$case     = $ctx->fork( 'query-operators' );
		$failures = array();
		$matrix   = array(
			'alpha' => array(
				'name' => self::post_type_name( $case->fork( 'alpha' ), 'qalpha' ),
				'args' => array(
					'public'       => true,
					'hierarchical' => false,
					'show_ui'      => true,
					'supports'     => array( 'title', 'editor' ),
				),
			),
			'beta'  => array(
				'name' => self::post_type_name( $case->fork( 'beta' ), 'qbeta' ),
				'args' => array(
					'public'       => false,
					'hierarchical' => true,
					'show_ui'      => true,
					'supports'     => array( 'thumbnail' ),
				),
			),
			'gamma' => array(
				'name' => self::post_type_name( $case->fork( 'gamma' ), 'qgamma' ),
				'args' => array(
					'public'       => true,
					'hierarchical' => true,
					'show_ui'      => false,
					'supports'     => array( 'revisions' ),
				),
			),
			'delta' => array(
				'name' => self::post_type_name( $case->fork( 'delta' ), 'qdelta' ),
				'args' => array(
					'public'       => false,
					'hierarchical' => false,
					'show_ui'      => false,
					'supports'     => array( 'editor', 'comments' ),
				),
			),
		);

		foreach ( $matrix as $key => $definition ) {
			$object = \register_post_type( $definition['name'], $definition['args'] );
			self::collect_failure(
				$failures,
				$object instanceof \WP_Post_Type,
				"query operator fixture post type registers {$key}",
				array(
					'key'    => $key,
					'name'   => $definition['name'],
					'result' => self::describe_value( $object ),
				)
			);
		}

		$names = array(
			'alpha' => $matrix['alpha']['name'],
			'beta'  => $matrix['beta']['name'],
			'gamma' => $matrix['gamma']['name'],
			'delta' => $matrix['delta']['name'],
		);

		$public_names              = \get_post_types( array( 'public' => true ), 'names', 'and' );
		$public_or_hierarchical    = \get_post_types( array( 'public' => true, 'hierarchical' => true ), 'names', 'or' );
		$public_and_hierarchical   = \get_post_types( array( 'public' => true, 'hierarchical' => true ), 'names', 'and' );
		$not_public_names          = \get_post_types( array( 'public' => true ), 'names', 'not' );
		$not_public_objects        = \get_post_types( array( 'public' => true ), 'objects', 'not' );
		$show_ui_names             = \get_post_types( array( 'show_ui' => true ), 'names', 'and' );
		$not_hierarchical_names    = \get_post_types( array( 'hierarchical' => true ), 'names', 'not' );
		$editor_or_thumbnail       = \get_post_types_by_support( array( 'editor', 'thumbnail' ), 'or' );
		$editor_and_comments       = \get_post_types_by_support( array( 'editor', 'comments' ), 'and' );
		$not_editor_support        = \get_post_types_by_support( 'editor', 'not' );
		$not_public_object_names   = self::post_type_object_names( $not_public_objects );
		$not_public_object_classes = array_map(
			static function ( $object ): bool {
				return $object instanceof \WP_Post_Type;
			},
			$not_public_objects
		);

		self::collect_failure(
			$failures,
			self::sets_match( array( $names['alpha'], $names['gamma'] ), $public_names )
				&& self::sets_match( array( $names['alpha'], $names['beta'], $names['gamma'] ), $public_or_hierarchical )
				&& self::sets_match( array( $names['gamma'] ), $public_and_hierarchical )
				&& self::sets_match( array( $names['beta'], $names['delta'] ), $not_public_names )
				&& self::sets_match( array( $names['beta'], $names['delta'] ), $not_public_object_names )
				&& ! in_array( false, $not_public_object_classes, true )
				&& self::sets_match( array( $names['alpha'], $names['beta'] ), $show_ui_names )
				&& self::sets_match( array( $names['alpha'], $names['delta'] ), $not_hierarchical_names )
				&& self::sets_match( array( $names['alpha'], $names['beta'], $names['delta'] ), $editor_or_thumbnail )
				&& self::sets_match( array( $names['delta'] ), $editor_and_comments )
				&& self::sets_match( array( $names['beta'], $names['gamma'] ), $not_editor_support ),
			'get_post_types and get_post_types_by_support honor and/or/not operators and object output',
			array(
				'names'                 => $names,
				'publicNames'           => $public_names,
				'publicOrHierarchical'  => $public_or_hierarchical,
				'publicAndHierarchical' => $public_and_hierarchical,
				'notPublicNames'        => $not_public_names,
				'notPublicObjects'      => $not_public_object_names,
				'showUiNames'           => $show_ui_names,
				'notHierarchicalNames'  => $not_hierarchical_names,
				'editorOrThumbnail'     => $editor_or_thumbnail,
				'editorAndComments'     => $editor_and_comments,
				'notEditorSupport'      => $not_editor_support,
			)
		);

		return self::row(
			$ctx,
			'post-types.registry-query-operators',
			array() === $failures,
			array(
				'registered' => $names,
				'failures'   => array_slice( $failures, 0, 6 ),
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

	private static function check_post_type_archive_link_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$failures       = array();
		$token          = self::safe_token( $ctx->identifier( 4, 10 ) );
		$pretty_type    = self::post_type_name( $ctx->fork( 'pretty-type' ), 'arch' );
		$plain_type     = self::post_type_name( $ctx->fork( 'plain-type' ), 'plain' );
		$no_archive     = self::post_type_name( $ctx->fork( 'no-archive' ), 'noarch' );
		$pretty_slug    = 'library/' . $token;
		$archive_slug   = $ctx->bool() ? true : 'archive-' . $token;
		$archive_struct = true === $archive_slug ? $pretty_slug : $archive_slug;
		$archive_filter_calls = array();
		$feed_filter_calls    = array();

		$pretty = \register_post_type(
			$pretty_type,
			array(
				'public'      => true,
				'has_archive' => $archive_slug,
				'rewrite'     => array(
					'slug'       => $pretty_slug,
					'with_front' => false,
					'feeds'      => true,
					'pages'      => true,
				),
				'query_var'   => true,
				'supports'    => array( 'title' ),
			)
		);
		$plain  = \register_post_type(
			$plain_type,
			array(
				'public'      => true,
				'has_archive' => true,
				'rewrite'     => false,
				'query_var'   => true,
				'supports'    => false,
			)
		);
		$none   = \register_post_type(
			$no_archive,
			array(
				'public'      => true,
				'has_archive' => false,
				'rewrite'     => true,
				'query_var'   => true,
				'supports'    => false,
			)
		);

		$expected_pretty_link = \home_url( \user_trailingslashit( $archive_struct, 'post_type_archive' ) );
		$expected_plain_link  = \home_url( '?post_type=' . $plain_type );
		$raw_pretty_link      = \get_post_type_archive_link( $pretty_type );
		$raw_plain_link       = \get_post_type_archive_link( $plain_type );
		$no_archive_link      = \get_post_type_archive_link( $no_archive );
		$missing_link         = \get_post_type_archive_link( 'cfz_missing_' . $token );

		$archive_filter = static function ( string $link, string $post_type ) use ( &$archive_filter_calls, $token ): string {
			$archive_filter_calls[] = array(
				'link'     => $link,
				'postType' => $post_type,
			);

			return \add_query_arg( 'cfz_archive_filter', $token, $link );
		};
		$feed_filter    = static function ( string $link, string $feed ) use ( &$feed_filter_calls, $token ): string {
			$feed_filter_calls[] = array(
				'link' => $link,
				'feed' => $feed,
			);

			return \add_query_arg( 'cfz_feed_filter', $token, $link );
		};

		\add_filter( 'post_type_archive_link', $archive_filter, 10, 2 );
		$filtered_archive_link = \get_post_type_archive_link( $pretty_type );
		$archive_removed       = \remove_filter( 'post_type_archive_link', $archive_filter, 10 );

		$expected_pretty_default_feed = \trailingslashit( $raw_pretty_link ) . 'feed/';
		$expected_pretty_atom_feed    = \trailingslashit( $raw_pretty_link ) . 'feed/atom/';
		$expected_plain_rss_feed      = \add_query_arg( 'feed', 'rss2', $raw_plain_link );

		\add_filter( 'post_type_archive_feed_link', $feed_filter, 10, 2 );
		$pretty_default_feed = \get_post_type_archive_feed_link( $pretty_type, '' );
		$pretty_atom_feed    = \get_post_type_archive_feed_link( $pretty_type, 'atom' );
		$plain_rss_feed      = \get_post_type_archive_feed_link( $plain_type, 'rss2' );
		$no_archive_feed     = \get_post_type_archive_feed_link( $no_archive, 'atom' );
		$feed_removed        = \remove_filter( 'post_type_archive_feed_link', $feed_filter, 10 );

		self::collect_failure(
			$failures,
			$pretty instanceof \WP_Post_Type
				&& $plain instanceof \WP_Post_Type
				&& $none instanceof \WP_Post_Type
				&& $expected_pretty_link === $raw_pretty_link
				&& $expected_plain_link === $raw_plain_link
				&& false === $no_archive_link
				&& false === $missing_link,
			'post type archive links follow has_archive and rewrite branches',
			array(
				'prettyType'     => $pretty_type,
				'plainType'      => $plain_type,
				'noArchiveType'  => $no_archive,
				'archiveSlug'    => $archive_slug,
				'expectedPretty' => $expected_pretty_link,
				'actualPretty'   => $raw_pretty_link,
				'expectedPlain'  => $expected_plain_link,
				'actualPlain'    => $raw_plain_link,
				'noArchive'      => $no_archive_link,
				'missing'        => $missing_link,
			)
		);
		self::collect_failure(
			$failures,
			\add_query_arg( 'cfz_archive_filter', $token, $raw_pretty_link ) === $filtered_archive_link
				&& array(
					array(
						'link'     => $raw_pretty_link,
						'postType' => $pretty_type,
					),
				) === $archive_filter_calls
				&& $archive_removed
				&& false === \has_filter( 'post_type_archive_link', $archive_filter ),
			'post_type_archive_link filter receives raw link and post type and is removable',
			array(
				'filtered' => $filtered_archive_link,
				'calls'    => $archive_filter_calls,
				'removed'  => $archive_removed,
				'hasAfter' => \has_filter( 'post_type_archive_link', $archive_filter ),
			)
		);
		self::collect_failure(
			$failures,
			\add_query_arg( 'cfz_feed_filter', $token, $expected_pretty_default_feed ) === $pretty_default_feed
				&& \add_query_arg( 'cfz_feed_filter', $token, $expected_pretty_atom_feed ) === $pretty_atom_feed
				&& \add_query_arg( 'cfz_feed_filter', $token, $expected_plain_rss_feed ) === $plain_rss_feed
				&& false === $no_archive_feed
				&& array(
					array(
						'link' => $expected_pretty_default_feed,
						'feed' => 'rss2',
					),
					array(
						'link' => $expected_pretty_atom_feed,
						'feed' => 'atom',
					),
					array(
						'link' => $expected_plain_rss_feed,
						'feed' => 'rss2',
					),
				) === $feed_filter_calls
				&& $feed_removed
				&& false === \has_filter( 'post_type_archive_feed_link', $feed_filter ),
			'post type archive feed links use pretty/feed and query branches with filter locality',
			array(
				'prettyDefault' => $pretty_default_feed,
				'prettyAtom'    => $pretty_atom_feed,
				'plainRss'      => $plain_rss_feed,
				'noArchive'     => $no_archive_feed,
				'calls'         => $feed_filter_calls,
				'removed'       => $feed_removed,
				'hasAfter'      => \has_filter( 'post_type_archive_feed_link', $feed_filter ),
			)
		);

		return self::row(
			$ctx,
			'post-types.archive-link-helpers.filters-and-feed-branches',
			array() === $failures,
			array(
				'prettyType' => $pretty_type,
				'plainType'  => $plain_type,
				'failures'   => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_custom_post_type_rewrite_link_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_rewrite;

		self::reset_registries();

		$failures      = array();
		$token         = self::safe_token( $ctx->identifier( 4, 10 ) );
		$hier_type     = self::post_type_name( $ctx->fork( 'hier-pretty' ), 'hlink' );
		$plain_type    = self::post_type_name( $ctx->fork( 'plain-query' ), 'qlink' );
		$front_type    = self::post_type_name( $ctx->fork( 'fronted' ), 'flink' );
		$hidden_type   = self::post_type_name( $ctx->fork( 'hidden' ), 'nlink' );
		$hier_slug     = 'hier-library/' . $token;
		$front_slug    = 'front-library/' . $token;
		$front_archive = 'archive-' . $token;
		$plain_qv      = 'qv_' . substr( $token, 0, 12 );

		if ( is_object( $wp_rewrite ) ) {
			$wp_rewrite->front = '/front/';
			$wp_rewrite->root  = '';
		}

		$rewrite_tag_shape = static function ( string $post_type ): array {
			global $wp_rewrite;

			if (
				! is_object( $wp_rewrite )
				|| ! isset( $wp_rewrite->rewritecode, $wp_rewrite->rewritereplace, $wp_rewrite->queryreplace )
				|| ! is_array( $wp_rewrite->rewritecode )
			) {
				return array(
					'regex' => null,
					'query' => null,
				);
			}

			$position = array_search( '%' . $post_type . '%', $wp_rewrite->rewritecode, true );
			if ( false === $position || null === $position ) {
				return array(
					'regex' => null,
					'query' => null,
				);
			}

			return array(
				'regex' => $wp_rewrite->rewritereplace[ $position ] ?? null,
				'query' => $wp_rewrite->queryreplace[ $position ] ?? null,
			);
		};
		$permastruct = static function ( string $post_type ): ?array {
			global $wp_rewrite;

			return is_object( $wp_rewrite ) && isset( $wp_rewrite->extra_permastructs[ $post_type ] )
				? $wp_rewrite->extra_permastructs[ $post_type ]
				: null;
		};

		$hier = \register_post_type(
			$hier_type,
			array(
				'has_archive'  => true,
				'hierarchical' => true,
				'public'       => true,
				'query_var'    => false,
				'rewrite'      => array(
					'feeds'      => true,
					'pages'      => true,
					'slug'       => $hier_slug,
					'with_front' => false,
				),
				'supports'     => false,
			)
		);
		$plain = \register_post_type(
			$plain_type,
			array(
				'has_archive'  => true,
				'hierarchical' => false,
				'public'       => true,
				'query_var'    => $plain_qv,
				'rewrite'      => false,
				'supports'     => false,
			)
		);
		$front = \register_post_type(
			$front_type,
			array(
				'has_archive'  => $front_archive,
				'hierarchical' => true,
				'public'       => true,
				'query_var'    => true,
				'rewrite'      => array(
					'feeds'      => false,
					'pages'      => false,
					'slug'       => $front_slug,
					'with_front' => true,
				),
				'supports'     => false,
			)
		);
		$hidden = \register_post_type(
			$hidden_type,
			array(
				'has_archive'  => false,
				'hierarchical' => true,
				'public'       => true,
				'query_var'    => false,
				'rewrite'      => false,
				'supports'     => false,
			)
		);

		$hier_obj   = \get_post_type_object( $hier_type );
		$plain_obj  = \get_post_type_object( $plain_type );
		$front_obj  = \get_post_type_object( $front_type );
		$hidden_obj = \get_post_type_object( $hidden_type );

		$hier_struct   = $permastruct( $hier_type );
		$front_struct  = $permastruct( $front_type );
		$plain_struct  = $permastruct( $plain_type );
		$hidden_struct = $permastruct( $hidden_type );
		$hier_tag      = $rewrite_tag_shape( $hier_type );
		$front_tag     = $rewrite_tag_shape( $front_type );
		$plain_tag     = $rewrite_tag_shape( $plain_type );
		$hidden_tag    = $rewrite_tag_shape( $hidden_type );
		$hier_regexes  = self::rewrite_rule_regexes_for_post_type( $hier_type );
		$front_regexes = self::rewrite_rule_regexes_for_post_type( $front_type );
		$plain_regexes = self::rewrite_rule_regexes_for_post_type( $plain_type );

		$expected_hier_link      = \home_url( \user_trailingslashit( $hier_slug, 'post_type_archive' ) );
		$expected_plain_link     = \home_url( '?post_type=' . $plain_type );
		$expected_front_link     = \home_url( \user_trailingslashit( '/front/' . $front_archive, 'post_type_archive' ) );
		$expected_hier_atom_feed = \trailingslashit( $expected_hier_link ) . 'feed/atom/';
		$expected_plain_feed     = \add_query_arg( 'feed', 'rss2', $expected_plain_link );
		$expected_front_feed     = \add_query_arg( 'feed', 'atom', $expected_front_link );

		$actual_hier_link   = \get_post_type_archive_link( $hier_type );
		$actual_plain_link  = \get_post_type_archive_link( $plain_type );
		$actual_front_link  = \get_post_type_archive_link( $front_type );
		$actual_hidden_link = \get_post_type_archive_link( $hidden_type );
		$actual_hier_feed   = \get_post_type_archive_feed_link( $hier_type, 'atom' );
		$actual_plain_feed  = \get_post_type_archive_feed_link( $plain_type, 'rss2' );
		$actual_front_feed  = \get_post_type_archive_feed_link( $front_type, 'atom' );
		$actual_hidden_feed = \get_post_type_archive_feed_link( $hidden_type, 'atom' );

		self::collect_failure(
			$failures,
			$hier instanceof \WP_Post_Type
				&& $plain instanceof \WP_Post_Type
				&& $front instanceof \WP_Post_Type
				&& $hidden instanceof \WP_Post_Type
				&& $hier_obj instanceof \WP_Post_Type
				&& $plain_obj instanceof \WP_Post_Type
				&& $front_obj instanceof \WP_Post_Type
				&& $hidden_obj instanceof \WP_Post_Type
				&& true === $hier_obj->hierarchical
				&& false === $hier_obj->query_var
				&& true === $front_obj->hierarchical
				&& $front_type === $front_obj->query_var
				&& $plain_qv === $plain_obj->query_var
				&& false === $hidden_obj->query_var
				&& self::query_var_is_public( $plain_qv )
				&& self::query_var_is_public( $front_type )
				&& ! self::query_var_is_public( $hier_type )
				&& ! self::query_var_is_public( $hidden_type ),
			'custom post type rewrite matrix registers query vars only for enabled public query branches',
			array(
				'hierType'   => $hier_type,
				'plainType'  => $plain_type,
				'frontType'  => $front_type,
				'hiddenType' => $hidden_type,
				'queryVars'  => is_object( $GLOBALS['wp'] ?? null ) && isset( $GLOBALS['wp']->public_query_vars )
					? $GLOBALS['wp']->public_query_vars
					: array(),
			)
		);
		self::collect_failure(
			$failures,
			is_array( $hier_struct )
				&& $hier_slug . '/%' . $hier_type . '%' === ( $hier_struct['struct'] ?? null )
				&& false === ( $hier_struct['with_front'] ?? null )
				&& true === ( $hier_struct['feed'] ?? null )
				&& true === ( $hier_struct['paged'] ?? null )
				&& is_array( $front_struct )
				&& '/front/' . $front_slug . '/%' . $front_type . '%' === ( $front_struct['struct'] ?? null )
				&& true === ( $front_struct['with_front'] ?? null )
				&& false === ( $front_struct['feed'] ?? null )
				&& true === ( $front_struct['paged'] ?? null )
				&& null === $plain_struct
				&& null === $hidden_struct,
			'custom post type rewrite matrix stores permastructs only for pretty rewrite branches with normalized feed flags',
			array(
				'hierStruct'   => $hier_struct,
				'frontStruct'  => $front_struct,
				'plainStruct'  => $plain_struct,
				'hiddenStruct' => $hidden_struct,
			)
		);
		self::collect_failure(
			$failures,
			array(
				'regex' => '(.+?)',
				'query' => 'post_type=' . $hier_type . '&pagename=',
			) === $hier_tag
				&& array(
					'regex' => '(.+?)',
					'query' => $front_type . '=',
				) === $front_tag
				&& array(
					'regex' => null,
					'query' => null,
				) === $plain_tag
				&& array(
					'regex' => null,
					'query' => null,
				) === $hidden_tag
				&& self::rewrite_regexes_include_slug( $hier_regexes, $hier_slug )
				&& self::rewrite_regexes_include_slug( $front_regexes, 'front/' . $front_archive )
				&& array() === $plain_regexes,
			'custom post type rewrite matrix distinguishes hierarchical rewrite tags from rewrite-disabled archive branches',
			array(
				'hierTag'      => $hier_tag,
				'frontTag'     => $front_tag,
				'plainTag'     => $plain_tag,
				'hiddenTag'    => $hidden_tag,
				'hierRegexes'  => $hier_regexes,
				'frontRegexes' => $front_regexes,
				'plainRegexes' => $plain_regexes,
			)
		);
		self::collect_failure(
			$failures,
			$expected_hier_link === $actual_hier_link
				&& $expected_plain_link === $actual_plain_link
				&& $expected_front_link === $actual_front_link
				&& false === $actual_hidden_link
				&& $expected_hier_atom_feed === $actual_hier_feed
				&& $expected_plain_feed === $actual_plain_feed
				&& $expected_front_feed === $actual_front_feed
				&& false === $actual_hidden_feed,
			'custom post type archive and feed links follow rewrite, with_front, and query fallback branches',
			array(
				'expected' => array(
					'hierLink'   => $expected_hier_link,
					'plainLink'  => $expected_plain_link,
					'frontLink'  => $expected_front_link,
					'hierFeed'   => $expected_hier_atom_feed,
					'plainFeed'  => $expected_plain_feed,
					'frontFeed'  => $expected_front_feed,
				),
				'actual'   => array(
					'hierLink'   => $actual_hier_link,
					'plainLink'  => $actual_plain_link,
					'frontLink'  => $actual_front_link,
					'hiddenLink' => $actual_hidden_link,
					'hierFeed'   => $actual_hier_feed,
					'plainFeed'  => $actual_plain_feed,
					'frontFeed'  => $actual_front_feed,
					'hiddenFeed' => $actual_hidden_feed,
				),
			)
		);

		$before_unregister = self::registry_shape();
		$unregister_results = array(
			'hier'   => \unregister_post_type( $hier_type ),
			'plain'  => \unregister_post_type( $plain_type ),
			'front'  => \unregister_post_type( $front_type ),
			'hidden' => \unregister_post_type( $hidden_type ),
		);
		$after_unregister = self::registry_shape();

		self::collect_failure(
			$failures,
			true === $unregister_results['hier']
				&& true === $unregister_results['plain']
				&& true === $unregister_results['front']
				&& true === $unregister_results['hidden']
				&& ! \post_type_exists( $hier_type )
				&& ! \post_type_exists( $plain_type )
				&& ! \post_type_exists( $front_type )
				&& ! \post_type_exists( $hidden_type )
				&& null === $permastruct( $hier_type )
				&& null === $permastruct( $front_type )
				&& array() === self::rewrite_rule_regexes_for_post_type( $hier_type )
				&& array() === self::rewrite_rule_regexes_for_post_type( $front_type )
				&& array( 'regex' => null, 'query' => null ) === $rewrite_tag_shape( $hier_type )
				&& array( 'regex' => null, 'query' => null ) === $rewrite_tag_shape( $front_type )
				&& ! self::query_var_is_public( $plain_qv )
				&& ! self::query_var_is_public( $front_type ),
			'custom post type rewrite matrix unregisters generated types, permastructs, archive rules, tags, and query vars',
			array(
				'results' => $unregister_results,
				'before'  => $before_unregister,
				'after'   => $after_unregister,
			)
		);

		return self::row(
			$ctx,
			'post-types.rewrite-link-matrix.custom-post-types',
			array() === $failures,
			array(
				'hierType'   => $hier_type,
				'plainType'  => $plain_type,
				'frontType'  => $front_type,
				'hiddenType' => $hidden_type,
				'failures'   => array_slice( $failures, 0, 8 ),
			)
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
					&& $expected['_builtin'] === $status->_builtin
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

	private static function check_post_status_viewability( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$failures = array();
		$cases    = self::status_viewability_cases( $ctx->fork( 'statuses' ) );

		foreach ( $cases as $index => $case ) {
			$status = \register_post_status( $case['name'], $case['args'] );
			if ( ! is_object( $status ) ) {
				$failures[] = array(
					'label'  => "post status viewability registration case {$index}",
					'case'   => $case,
					'result' => self::describe_value( $status ),
				);
				continue;
			}

			$expected      = self::expected_status_viewable( $status );
			$string_result = \is_post_status_viewable( $case['name'] );
			$object_result = \is_post_status_viewable( $status );
			$lookup_result = \is_post_status_viewable( \get_post_status_object( $case['name'] ) );
			self::collect_failure(
				$failures,
				$expected === $string_result
					&& $expected === $object_result
					&& $expected === $lookup_result,
				"post status viewability matches object properties case {$index}",
				array(
					'case'         => $case,
					'status'       => self::status_summary( $status ),
					'expected'     => $expected,
					'stringResult' => $string_result,
					'objectResult' => $object_result,
					'lookupResult' => $lookup_result,
				)
			);
		}

		$raw_name       = 'Viewable Status ' . $ctx->int( 100, 999 ) . '!';
		$sanitized_name = \sanitize_key( $raw_name );
		$raw_status     = \register_post_status(
			$raw_name,
			array(
				'publicly_queryable' => true,
				'public'             => false,
				'_builtin'           => false,
			)
		);
		$raw_result     = \is_post_status_viewable( $raw_name );
		$clean_result   = \is_post_status_viewable( $sanitized_name );
		self::collect_failure(
			$failures,
			is_object( $raw_status )
				&& true === $clean_result
				&& false === $raw_result,
			'post status viewability requires exact registered key and does not sanitize lookup strings',
			array(
				'rawName'       => $raw_name,
				'sanitizedName' => $sanitized_name,
				'status'        => is_object( $raw_status ) ? self::status_summary( $raw_status ) : self::describe_value( $raw_status ),
				'rawResult'     => $raw_result,
				'cleanResult'   => $clean_result,
			)
		);

		$invalid_inputs = array(
			'unknown-string' => 'missing_' . $ctx->identifier( 4, 8 ),
			'empty-string'   => '',
			'false'          => false,
			'true'           => true,
			'int'            => $ctx->int( 1, 999 ),
			'float'          => (float) $ctx->int( 1, 999 ),
			'array'          => array( 'publish' ),
			'null'           => null,
		);
		foreach ( $invalid_inputs as $label => $input ) {
			$result = \is_post_status_viewable( $input );
			self::collect_failure(
				$failures,
				false === $result,
				"post status viewability rejects invalid input {$label}",
				array(
					'label'  => $label,
					'input'  => self::describe_value( $input ),
					'result' => $result,
				)
			);
		}

		$synthetic_status = (object) array(
			'name'               => 'synthetic_' . $ctx->identifier( 4, 8 ),
			'internal'           => false,
			'protected'          => false,
			'publicly_queryable' => false,
			'_builtin'           => true,
			'public'             => true,
		);
		$synthetic_result = \is_post_status_viewable( $synthetic_status );
		self::collect_failure(
			$failures,
			true === $synthetic_result,
			'post status viewability accepts complete unregistered status objects',
			array(
				'status' => self::status_summary( $synthetic_status ),
				'result' => $synthetic_result,
			)
		);

		$filter_calls  = array();
		$filter_return = null;
		$filter        = static function ( bool $is_viewable, object $post_status ) use ( &$filter_calls, &$filter_return ) {
			$filter_calls[] = array(
				'base'   => $is_viewable,
				'name'   => $post_status->name ?? null,
				'return' => $filter_return,
			);

			return $filter_return;
		};

		$filterable_status = \register_post_status(
			self::status_name( $ctx->fork( 'filterable' ), 'view' ),
			array(
				'publicly_queryable' => false,
				'public'             => false,
				'_builtin'           => false,
			)
		);
		$strict_status     = \register_post_status(
			self::status_name( $ctx->fork( 'strict' ), 'view' ),
			array(
				'publicly_queryable' => true,
				'public'             => false,
				'_builtin'           => false,
			)
		);
		$internal_status   = \register_post_status(
			self::status_name( $ctx->fork( 'internal' ), 'view' ),
			array(
				'internal'           => true,
				'publicly_queryable' => true,
				'public'             => true,
				'_builtin'           => true,
			)
		);
		$protected_status  = \register_post_status(
			self::status_name( $ctx->fork( 'protected' ), 'view' ),
			array(
				'protected'          => true,
				'publicly_queryable' => true,
				'public'             => true,
				'_builtin'           => true,
			)
		);

		\add_filter( 'is_post_status_viewable', $filter, 10, 2 );
		try {
			$before_early_returns = count( $filter_calls );
			$filter_return        = true;
			$forced_true          = \is_post_status_viewable( $filterable_status );
			$internal_result      = \is_post_status_viewable( $internal_status );
			$protected_result     = \is_post_status_viewable( $protected_status );
			$after_early_returns  = count( $filter_calls );

			$filter_return = 'truthy';
			$truthy_result = \is_post_status_viewable( $strict_status );

			$filter_return = 1;
			$one_result    = \is_post_status_viewable( $strict_status );

			$filter_return = false;
			$forced_false  = \is_post_status_viewable( $strict_status );
		} finally {
			\remove_filter( 'is_post_status_viewable', $filter, 10 );
		}

		$expected_filter_calls = array(
			array(
				'base'   => false,
				'name'   => is_object( $filterable_status ) ? $filterable_status->name : null,
				'return' => true,
			),
			array(
				'base'   => true,
				'name'   => is_object( $strict_status ) ? $strict_status->name : null,
				'return' => 'truthy',
			),
			array(
				'base'   => true,
				'name'   => is_object( $strict_status ) ? $strict_status->name : null,
				'return' => 1,
			),
			array(
				'base'   => true,
				'name'   => is_object( $strict_status ) ? $strict_status->name : null,
				'return' => false,
			),
		);

		self::collect_failure(
			$failures,
			true === $forced_true
				&& false === $internal_result
				&& false === $protected_result
				&& $after_early_returns === $before_early_returns + 1
				&& false === $truthy_result
				&& false === $one_result
				&& false === $forced_false
				&& $expected_filter_calls === $filter_calls
				&& false === \has_filter( 'is_post_status_viewable', $filter, 10 ),
			'post status viewability filter is strict boolean and skipped for internal/protected statuses',
			array(
				'forcedTrue'         => $forced_true,
				'internalResult'     => $internal_result,
				'protectedResult'    => $protected_result,
				'truthyStringResult' => $truthy_result,
				'intOneResult'       => $one_result,
				'forcedFalse'        => $forced_false,
				'calls'              => $filter_calls,
				'expectedCalls'       => $expected_filter_calls,
				'activeAfter'        => \has_filter( 'is_post_status_viewable', $filter, 10 ),
			)
		);

		return self::row(
			$ctx,
			'post-types.status.viewability-and-filter-strictness',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_post_status_name_sanitization_and_overwrite( \ComponentFuzz\FuzzContext $ctx ): array {
		self::reset_registries();

		$failures = array();
		$base     = self::status_name( $ctx->fork( 'alias' ), 'alias' );
		$alias_a  = strtoupper( $base ) . '!!';
		$alias_b  = $base . '$$';
		$raw_cases = array(
			array(
				'label' => 'alias-first',
				'raw'   => $alias_a,
				'args'  => array(
					'public' => true,
					'label'  => 'Alias First ' . $ctx->int( 10, 99 ),
				),
			),
			array(
				'label' => 'alias-overwrite',
				'raw'   => $alias_b,
				'args'  => array(
					'private' => true,
					'label'   => 'Alias Second ' . $ctx->int( 100, 199 ),
				),
			),
			array(
				'label' => 'spaces-and-slashes',
				'raw'   => '  ' . $ctx->identifier( 4, 8 ) . '/Status ' . $ctx->int( 1, 9 ) . '  ',
				'args'  => array(
					'protected' => true,
				),
			),
			array(
				'label' => 'unicode-empty-key',
				'raw'   => $ctx->choice( array( "☃", "éé", "中文", "مرحبا" ) ),
				'args'  => array(
					'internal' => true,
					'label'    => 'Unicode Empty Key',
				),
			),
		);

		$expected_by_key = array();
		$registered      = array();

		foreach ( $raw_cases as $index => $case ) {
			$key      = \sanitize_key( $case['raw'] );
			$status   = \register_post_status( $case['raw'], $case['args'] );
			$expected = self::expected_status_props( $key, $case['args'] );
			$raw_key_leaked = $case['raw'] !== $key && array_key_exists( $case['raw'], $GLOBALS['wp_post_statuses'] ?? array() );

			self::collect_failure(
				$failures,
				is_object( $status )
					&& $status === ( $GLOBALS['wp_post_statuses'][ $key ] ?? null )
					&& $key === $status->name
					&& $expected['label'] === $status->label
					&& $expected['public'] === $status->public
					&& $expected['private'] === $status->private
					&& $expected['protected'] === $status->protected
					&& $expected['internal'] === $status->internal
					&& $expected['_builtin'] === $status->_builtin
					&& ! $raw_key_leaked,
				"post status sanitized key registration case {$index}",
				array(
					'case'     => $case,
					'key'      => $key,
					'expected' => $expected,
					'actual'   => is_object( $status ) ? self::status_summary( $status ) : self::describe_value( $status ),
					'rawLeaked' => $raw_key_leaked,
				)
			);

			$expected_by_key[ $key ] = $expected;
			$registered[]           = array(
				'label' => $case['label'],
				'raw'   => $case['raw'],
				'key'   => $key,
			);
		}

		foreach ( $expected_by_key as $key => $expected ) {
			$status = \get_post_status_object( $key );
			self::collect_failure(
				$failures,
				is_object( $status )
					&& $expected['label'] === $status->label
					&& $expected['public'] === $status->public
					&& $expected['private'] === $status->private
					&& $expected['protected'] === $status->protected
					&& $expected['internal'] === $status->internal
					&& $expected['_builtin'] === $status->_builtin,
				"post status lookup matches last sanitized registration {$key}",
				array(
					'key'      => $key,
					'expected' => $expected,
					'actual'   => is_object( $status ) ? self::status_summary( $status ) : self::describe_value( $status ),
				)
			);
		}

		$private_names = \get_post_stati( array( 'private' => true ), 'names' );
		$public_names  = \get_post_stati( array( 'public' => true ), 'names' );
		self::collect_failure(
			$failures,
			in_array( \sanitize_key( $alias_b ), $private_names, true )
				&& ! in_array( \sanitize_key( $alias_a ), $public_names, true ),
			'post status duplicate sanitized key uses latest registration in filters',
			array(
				'aliasKey'     => \sanitize_key( $alias_a ),
				'privateNames' => $private_names,
				'publicNames'  => $public_names,
			)
		);

		return self::row(
			$ctx,
			'post-types.status.sanitized-name-overwrite-contract',
			array() === $failures,
			array(
				'registered' => $registered,
				'keys'       => array_keys( $expected_by_key ),
				'failures'   => array_slice( $failures, 0, 8 ),
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

	private static function status_viewability_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'label' => 'implicit-internal-default',
				'name'  => self::status_name( $ctx->fork( 'implicit' ), 'view' ),
				'args'  => array(),
			),
			array(
				'label' => 'public-default-queryable',
				'name'  => self::status_name( $ctx->fork( 'public' ), 'view' ),
				'args'  => array(
					'public' => true,
				),
			),
			array(
				'label' => 'custom-public-not-queryable',
				'name'  => self::status_name( $ctx->fork( 'custom-public' ), 'view' ),
				'args'  => array(
					'public'             => true,
					'publicly_queryable' => false,
					'_builtin'           => false,
				),
			),
			array(
				'label' => 'custom-queryable-not-public',
				'name'  => self::status_name( $ctx->fork( 'custom-queryable' ), 'view' ),
				'args'  => array(
					'public'             => false,
					'publicly_queryable' => true,
					'_builtin'           => false,
				),
			),
			array(
				'label' => 'builtin-public-not-queryable',
				'name'  => self::status_name( $ctx->fork( 'builtin-public' ), 'view' ),
				'args'  => array(
					'public'             => true,
					'publicly_queryable' => false,
					'_builtin'           => true,
				),
			),
			array(
				'label' => 'builtin-not-public',
				'name'  => self::status_name( $ctx->fork( 'builtin-private' ), 'view' ),
				'args'  => array(
					'public'             => false,
					'publicly_queryable' => false,
					'_builtin'           => true,
				),
			),
			array(
				'label' => 'private-queryable',
				'name'  => self::status_name( $ctx->fork( 'private-queryable' ), 'view' ),
				'args'  => array(
					'private'            => true,
					'publicly_queryable' => true,
				),
			),
			array(
				'label' => 'private-default-not-queryable',
				'name'  => self::status_name( $ctx->fork( 'private-default' ), 'view' ),
				'args'  => array(
					'private' => true,
				),
			),
			array(
				'label' => 'internal-short-circuit',
				'name'  => self::status_name( $ctx->fork( 'internal' ), 'view' ),
				'args'  => array(
					'internal'           => true,
					'publicly_queryable' => true,
					'public'             => true,
					'_builtin'           => true,
				),
			),
			array(
				'label' => 'protected-short-circuit',
				'name'  => self::status_name( $ctx->fork( 'protected' ), 'view' ),
				'args'  => array(
					'protected'          => true,
					'publicly_queryable' => true,
					'public'             => true,
					'_builtin'           => true,
				),
			),
		);

		for ( $i = count( $cases ); $i < self::CASES; ++$i ) {
			$case = $ctx->fork( 'view-' . $i );
			$args = array(
				'public'             => $case->choice( array( null, true, false ) ),
				'internal'           => $case->choice( array( null, true, false ) ),
				'protected'          => $case->choice( array( null, true, false ) ),
				'private'            => $case->choice( array( null, true, false ) ),
				'publicly_queryable' => $case->choice( array( null, true, false ) ),
				'_builtin'           => $case->bool(),
			);
			$args = array_filter(
				$args,
				static function ( $value ): bool {
					return null !== $value;
				}
			);

			$cases[] = array(
				'label' => 'generated ' . $i,
				'name'  => self::status_name( $case->fork( 'name' ), 'view' ),
				'args'  => $args,
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
			'_builtin'                  => (bool) ( $args['_builtin'] ?? false ),
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

	private static function expected_status_viewable( object $status ): bool {
		return empty( $status->internal )
			&& empty( $status->protected )
			&& (
				! empty( $status->publicly_queryable )
				|| ( ! empty( $status->_builtin ) && ! empty( $status->public ) )
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

	private static function post_type_object_names( array $objects ): array {
		$names = array();
		foreach ( $objects as $object ) {
			if ( $object instanceof \WP_Post_Type ) {
				$names[] = $object->name;
			}
		}

		return $names;
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

	private static function rewrite_rule_regexes_for_post_type( string $post_type ): array {
		global $wp_rewrite;

		if ( ! is_object( $wp_rewrite ) || ! isset( $wp_rewrite->extra_rules_top ) || ! is_array( $wp_rewrite->extra_rules_top ) ) {
			return array();
		}

		$regexes = array();
		foreach ( $wp_rewrite->extra_rules_top as $regex => $query ) {
			if ( is_string( $query ) && str_contains( $query, 'post_type=' . $post_type ) ) {
				$regexes[] = (string) $regex;
			}
		}

		sort( $regexes );
		return $regexes;
	}

	private static function rewrite_regexes_include_slug( array $regexes, string $slug ): bool {
		foreach ( $regexes as $regex ) {
			if ( is_string( $regex ) && str_starts_with( $regex, $slug ) ) {
				return true;
			}
		}

		return false;
	}

	private static function permastruct_uses_slug( string $post_type, string $slug ): bool {
		global $wp_rewrite;

		if ( ! is_object( $wp_rewrite ) || ! isset( $wp_rewrite->extra_permastructs[ $post_type ] ) ) {
			return false;
		}

		return ( $wp_rewrite->extra_permastructs[ $post_type ]['struct'] ?? null ) === $slug . '/%' . $post_type . '%';
	}

	private static function query_var_is_public( $query_var ): bool {
		global $wp;

		return is_string( $query_var )
			&& is_object( $wp )
			&& isset( $wp->public_query_vars )
			&& in_array( $query_var, $wp->public_query_vars, true );
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
			'builtin'               => $status->_builtin ?? null,
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

	private static function register_post_type_rest_routes( array $post_type_names ): array {
		$diagnostics         = array();
		$had_wp_actions      = array_key_exists( 'wp_actions', $GLOBALS );
		$previous_wp_actions = $had_wp_actions ? $GLOBALS['wp_actions'] : null;

		$doing_it_wrong = static function ( string $function_name, string $message, string $version ) use ( &$diagnostics ): void {
			if ( 'register_rest_route' !== $function_name ) {
				return;
			}

			$diagnostics[] = array(
				'function' => $function_name,
				'version'  => $version,
				'message'  => $message,
			);
		};

		\add_action( 'doing_it_wrong_run', $doing_it_wrong, 10, 3 );

		try {
			if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
				$GLOBALS['wp_actions'] = array();
			}

			$GLOBALS['wp_actions']['rest_api_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['rest_api_init'] ?? 0 ) );

			foreach ( $post_type_names as $post_type_name ) {
				$post_type = \get_post_type_object( $post_type_name );
				if ( ! ( $post_type instanceof \WP_Post_Type ) ) {
					continue;
				}

				$controller = $post_type->get_rest_controller();
				if ( ! $controller ) {
					continue;
				}

				if ( ! $post_type->late_route_registration ) {
					$controller->register_routes();
				}

				$revisions_controller = $post_type->get_revisions_rest_controller();
				if ( $revisions_controller ) {
					$revisions_controller->register_routes();
				}

				$autosaves_controller = $post_type->get_autosave_rest_controller();
				if ( $autosaves_controller ) {
					$autosaves_controller->register_routes();
				}

				if ( $post_type->late_route_registration ) {
					$controller->register_routes();
				}
			}
		} finally {
			if ( $had_wp_actions ) {
				$GLOBALS['wp_actions'] = $previous_wp_actions;
			} else {
				unset( $GLOBALS['wp_actions'] );
			}

			\remove_action( 'doing_it_wrong_run', $doing_it_wrong, 10 );
		}

		return $diagnostics;
	}

	private static function rest_route_case_summary( array $case ): array {
		return array(
			'label'       => $case['label'],
			'postType'    => $case['postType'],
			'namespace'   => $case['namespace'],
			'base'        => $case['base'],
			'showInRest'  => $case['args']['show_in_rest'],
			'supports'    => $case['args']['supports'],
			'mainClass'   => $case['args']['rest_controller_class'] ?? null,
			'revisionCls' => $case['args']['revisions_rest_controller_class'] ?? null,
			'autosaveCls' => $case['args']['autosave_rest_controller_class'] ?? null,
			'late'        => $case['late'],
		);
	}

	private static function route_exists( array $routes, string $route ): bool {
		return array_key_exists( $route, $routes );
	}

	private static function route_index( array $routes, string $route ): ?int {
		$index = array_search( $route, $routes, true );

		return false === $index ? null : $index;
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
