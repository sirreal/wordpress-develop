<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB WordPress taxonomy and term helpers.
 */
final class TaxonomySurface {
	public const NAME = 'taxonomy';

	private const HOME_URL = 'https://example.test/site-base';
	private const SITE_URL = 'https://example.test/wp';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'taxonomy.bootstrap-apis-available',
					'Required WordPress taxonomy APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();
		$ob_level = ob_get_level();

		try {
			$case = self::case_for_context( $ctx );
			self::install_option_filters( $case );
			self::prepare_runtime( $case );

			$rows[] = self::check_registry_lifecycle( $ctx, $case );
			$rows[] = self::check_taxonomy_list_queries( $ctx, $case );
			$rows[] = self::check_object_shape_and_normalization( $ctx, $case );
			$rows[] = self::check_registration_side_effect_boundaries( $ctx, $case );
			$rows[] = self::check_sanitization_contexts( $ctx, $case );
			$rows[] = self::check_term_field_filter_boundaries( $ctx, $case );
			$rows[] = self::check_synthetic_term_objects( $ctx, $case );
			$rows[] = self::check_hierarchy_helper_edges( $ctx, $case );
			$rows[] = self::check_term_link_cheap_paths( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'taxonomy.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}

			self::restore_globals( $snapshot );

			$after_restore = self::snapshot_globals();
			$rows[]        = $ctx->result(
				'taxonomy.globals-restored',
				$snapshot === $after_restore,
				array(
					'trackedGlobals' => array_keys( $snapshot['globals'] ),
					'difference'     => $snapshot === $after_restore
						? null
						: self::first_value_difference( $snapshot, $after_restore ),
				)
			);
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Rewrite', 'WP_Taxonomy', 'WP_Term' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'get_ancestors',
				'get_object_taxonomies',
				'get_taxonomies',
				'get_taxonomy',
				'get_taxonomy_labels',
				'get_term',
				'get_term_field',
				'get_term_link',
				'home_url',
				'has_filter',
				'is_taxonomy_hierarchical',
				'register_taxonomy',
				'register_taxonomy_for_object_type',
				'remove_filter',
				'sanitize_key',
				'sanitize_title',
				'sanitize_term',
				'sanitize_term_field',
				'sanitize_title_with_dashes',
				'taxonomy_exists',
				'term_is_ancestor_of',
				'unregister_taxonomy',
				'unregister_taxonomy_for_object_type',
				'wp_check_term_hierarchy_for_loops',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_set',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_registry_lifecycle( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$taxonomy     = $case['taxonomies']['primary'];
		$object_type  = $case['objectTypes'][0];
		$second_type  = $case['objectTypes'][1];
		$args         = self::registration_args( $case );
		$before_names = \get_object_taxonomies( $object_type, 'names' );
		$registered   = \register_taxonomy( $taxonomy, $object_type, $args );

		if ( \is_wp_error( $registered ) ) {
			return $ctx->fail(
				'taxonomy.registry.lifecycle-and-object-associations',
				self::case_data( $case ) + array(
					'registerError' => $registered->get_error_code(),
				)
			);
		}

		$object_names          = \get_object_taxonomies( $object_type, 'names' );
		$object_objects        = \get_object_taxonomies( $object_type, 'objects' );
		$exists_after_register = \taxonomy_exists( $taxonomy );
		$get_after_register    = \get_taxonomy( $taxonomy );
		$hier_after_register   = \is_taxonomy_hierarchical( $taxonomy );
		$associated            = \register_taxonomy_for_object_type( $taxonomy, $second_type );
		$associated_again      = \register_taxonomy_for_object_type( $taxonomy, $second_type );
		$second_names          = \get_object_taxonomies( $second_type, 'names' );
		$second_object_keys    = array_keys( \get_object_taxonomies( $second_type, 'objects' ) );
		$second_count          = count( array_keys( (array) $registered->object_type, $second_type, true ) );
		$disassociated         = \unregister_taxonomy_for_object_type( $taxonomy, $second_type );
		$after_second_names    = \get_object_taxonomies( $second_type, 'names' );
		$unregistered          = \unregister_taxonomy( $taxonomy );
		$missing_unregister    = \unregister_taxonomy( $taxonomy );
		$long_name             = 'cf_tax_' . str_repeat( 'x', 33 );
		$invalid_register      = \register_taxonomy(
			$long_name,
			$object_type,
			array(
				'rewrite'   => false,
				'query_var' => false,
			)
		);

		$ok = array() === $before_names
			&& $registered instanceof \WP_Taxonomy
			&& $exists_after_register
			&& $get_after_register === $registered
			&& $hier_after_register === (bool) $registered->hierarchical
			&& in_array( $taxonomy, $object_names, true )
			&& isset( $object_objects[ $taxonomy ] )
			&& $object_objects[ $taxonomy ] === $registered
			&& true === $associated
			&& true === $associated_again
			&& in_array( $taxonomy, $second_names, true )
			&& in_array( $taxonomy, $second_object_keys, true )
			&& 1 === $second_count
			&& true === $disassociated
			&& ! in_array( $taxonomy, $after_second_names, true )
			&& true === $unregistered
			&& ! \taxonomy_exists( $taxonomy )
			&& ! in_array( $taxonomy, \get_object_taxonomies( $object_type, 'names' ), true )
			&& \is_wp_error( $missing_unregister )
			&& 'invalid_taxonomy' === $missing_unregister->get_error_code()
			&& \is_wp_error( $invalid_register )
			&& 'taxonomy_length_invalid' === $invalid_register->get_error_code()
			&& ! \taxonomy_exists( $long_name );

		return $ctx->result(
			'taxonomy.registry.lifecycle-and-object-associations',
			$ok,
			self::case_data( $case ) + array(
				'objectNames'            => $object_names,
				'objectObjectKeys'       => array_keys( $object_objects ),
				'existsAfterRegister'    => $exists_after_register,
				'hierAfterRegister'      => $hier_after_register,
				'associated'             => $associated,
				'associatedAgain'        => $associated_again,
				'secondNames'            => $second_names,
				'secondObjectKeys'       => $second_object_keys,
				'secondObjectTypeCount'  => $second_count,
				'disassociated'          => $disassociated,
				'afterSecondNames'       => $after_second_names,
				'unregistered'           => $unregistered,
				'missingUnregisterError' => \is_wp_error( $missing_unregister )
					? $missing_unregister->get_error_code()
					: $missing_unregister,
				'invalidRegisterError'   => \is_wp_error( $invalid_register )
					? $invalid_register->get_error_code()
					: $invalid_register,
			)
		);
	}

	private static function check_taxonomy_list_queries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$first_taxonomy  = $case['taxonomies']['listA'];
		$second_taxonomy = $case['taxonomies']['listB'];
		$first_args      = self::registration_args(
			$case,
			array(
				'hierarchical' => true,
				'public'       => true,
				'query_var'    => 'q_' . $case['token'] . '_a',
				'rewrite'      => false,
			)
		);
		$second_args     = self::registration_args(
			$case,
			array(
				'hierarchical' => false,
				'public'       => false,
				'query_var'    => false,
				'rewrite'      => false,
			)
		);

		$first  = \register_taxonomy( $first_taxonomy, $case['objectTypes'][0], $first_args );
		$second = \register_taxonomy( $second_taxonomy, $case['objectTypes'][1], $second_args );

		if ( \is_wp_error( $first ) || \is_wp_error( $second ) ) {
			return $ctx->fail(
				'taxonomy.queries.operator-output-consistency',
				self::case_data( $case ) + array(
					'firstError'  => \is_wp_error( $first ) ? $first->get_error_code() : null,
					'secondError' => \is_wp_error( $second ) ? $second->get_error_code() : null,
				)
			);
		}

		$all_names        = \get_taxonomies( array(), 'names' );
		$all_objects      = \get_taxonomies( array(), 'objects' );
		$hier_names       = \get_taxonomies( array( 'hierarchical' => true ), 'names', 'and' );
		$hier_objects     = \get_taxonomies( array( 'hierarchical' => true ), 'objects', 'and' );
		$public_or_names  = \get_taxonomies( array( 'hierarchical' => false, 'public' => true ), 'names', 'or' );
		$public_or_objs   = \get_taxonomies( array( 'hierarchical' => false, 'public' => true ), 'objects', 'or' );
		$none_names       = \get_taxonomies( array( 'hierarchical' => true, 'public' => false ), 'names', 'and' );
		$object_a_names   = \get_object_taxonomies( $case['objectTypes'][0], 'names' );
		$object_a_objects = \get_object_taxonomies( $case['objectTypes'][0], 'objects' );
		$object_all_names = \get_object_taxonomies( $case['objectTypes'], 'names' );
		$object_like       = (object) array( 'post_type' => $case['objectTypes'][1] );
		$object_like_names = \get_object_taxonomies( $object_like, 'names' );

		$ok = self::same_name_set( $all_names, array_keys( $all_objects ) )
			&& self::same_name_set( $all_names, array( $first_taxonomy, $second_taxonomy ) )
			&& self::same_name_set( $hier_names, array_keys( $hier_objects ) )
			&& self::same_name_set( $hier_names, array( $first_taxonomy ) )
			&& self::same_name_set( $public_or_names, array_keys( $public_or_objs ) )
			&& self::same_name_set( $public_or_names, array( $first_taxonomy, $second_taxonomy ) )
			&& array() === $none_names
			&& self::same_name_set( $object_a_names, array_keys( $object_a_objects ) )
			&& self::same_name_set( $object_a_names, array( $first_taxonomy ) )
			&& self::same_name_set( $object_all_names, array( $first_taxonomy, $second_taxonomy ) )
			&& self::same_name_set( $object_like_names, array( $second_taxonomy ) )
			&& self::objects_match_names( $all_objects )
			&& self::objects_match_names( $hier_objects )
			&& self::objects_match_names( $public_or_objs );

		return $ctx->result(
			'taxonomy.queries.operator-output-consistency',
			$ok,
			self::case_data( $case ) + array(
				'allNames'        => $all_names,
				'allObjectKeys'   => array_keys( $all_objects ),
				'hierNames'       => $hier_names,
				'hierObjectKeys'  => array_keys( $hier_objects ),
				'publicOrNames'   => $public_or_names,
				'publicOrKeys'    => array_keys( $public_or_objs ),
				'noneNames'       => $none_names,
				'objectANames'    => $object_a_names,
				'objectAKeys'     => array_keys( $object_a_objects ),
				'objectAllNames'  => $object_all_names,
				'objectLikeNames' => $object_like_names,
			)
		);
	}

	private static function check_object_shape_and_normalization( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$taxonomy = $case['taxonomies']['normalization'];
		$args     = self::registration_args( $case );
		$tax      = \register_taxonomy( $taxonomy, $case['objectTypes'][0], $args );

		if ( \is_wp_error( $tax ) ) {
			return $ctx->fail(
				'taxonomy.object-shape-and-argument-normalization',
				self::case_data( $case ) + array(
					'registerError' => $tax->get_error_code(),
				)
			);
		}

		$expected = self::expected_normalized_args( $taxonomy, $args );

		$default_taxonomy = $case['taxonomies']['defaults'];
		$default_tax      = \register_taxonomy(
			$default_taxonomy,
			$case['objectTypes'][0],
			array(
				'hierarchical' => $case['defaultHierarchical'],
				'public'       => true,
				'query_var'    => false,
				'rewrite'      => false,
			)
		);
		$default_labels   = \is_wp_error( $default_tax ) ? null : \get_taxonomy_labels( $default_tax );
		$expected_name    = $case['defaultHierarchical'] ? 'Categories' : 'Tags';
		$expected_single  = $case['defaultHierarchical'] ? 'Category' : 'Tag';

		$shape_ok = $tax instanceof \WP_Taxonomy
			&& $taxonomy === $tax->name
			&& $tax->labels instanceof \stdClass
			&& $tax->cap instanceof \stdClass
			&& is_array( $tax->object_type )
			&& in_array( $case['objectTypes'][0], $tax->object_type, true )
			&& false === $tax->_builtin
			&& is_bool( $tax->public )
			&& is_bool( $tax->publicly_queryable )
			&& is_bool( $tax->hierarchical )
			&& is_bool( $tax->show_ui )
			&& ( is_array( $tax->rewrite ) || false === $tax->rewrite || true === $tax->rewrite )
			&& ( is_string( $tax->query_var ) || false === $tax->query_var )
			&& null === $tax->get_rest_controller();

		$normalization_ok = $expected['publicly_queryable'] === $tax->publicly_queryable
			&& $expected['show_ui'] === $tax->show_ui
			&& $expected['show_in_menu'] === $tax->show_in_menu
			&& $expected['show_in_nav_menus'] === $tax->show_in_nav_menus
			&& $expected['show_tagcloud'] === $tax->show_tagcloud
			&& $expected['show_in_quick_edit'] === $tax->show_in_quick_edit
			&& $expected['query_var'] === $tax->query_var
			&& self::rewrite_matches_expectation( $expected['rewrite'], $tax->rewrite )
			&& $expected['capabilities'] === self::capabilities_array( $tax->cap )
			&& $case['labels']['name'] === $tax->labels->name
			&& $case['labels']['singular'] === $tax->labels->singular_name
			&& $tax->labels->menu_name === $tax->labels->name
			&& is_string( $tax->labels->template_name )
			&& str_contains( $tax->labels->template_name, $tax->labels->singular_name );

		$defaults_ok = $default_tax instanceof \WP_Taxonomy
			&& $default_labels instanceof \stdClass
			&& $expected_name === $default_labels->name
			&& $expected_single === $default_labels->singular_name
			&& $default_labels->menu_name === $default_labels->name
			&& $expected_single . ' Archives' === $default_labels->template_name
			&& \is_taxonomy_hierarchical( $default_taxonomy ) === $case['defaultHierarchical'];

		return $ctx->result(
			'taxonomy.object-shape-and-argument-normalization',
			$shape_ok && $normalization_ok && $defaults_ok,
			self::case_data( $case ) + array(
				'shapeOk'            => $shape_ok,
				'normalizationOk'    => $normalization_ok,
				'defaultsOk'         => $defaults_ok,
				'actualQueryVar'     => $tax->query_var,
				'expectedQueryVar'   => $expected['query_var'],
				'actualRewrite'      => $tax->rewrite,
				'expectedRewrite'    => $expected['rewrite'],
				'actualCapabilities' => self::capabilities_array( $tax->cap ),
				'expectedCaps'       => $expected['capabilities'],
				'defaultLabels'      => $default_labels,
			)
		);
	}

	private static function check_registration_side_effect_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$manual_taxonomy = $case['taxonomies']['callbacks'];
		$meta_box_cb     = static function () use ( $case ): string {
			return 'meta-' . $case['token'];
		};
		$sanitize_cb     = static function ( $value ): array {
			return array_map( 'intval', (array) $value );
		};

		$manual_taxonomy_object = new \WP_Taxonomy(
			$manual_taxonomy,
			$case['objectTypes'][0],
			self::registration_args(
				$case,
				array(
					'public'               => true,
					'publicly_queryable'   => true,
					'hierarchical'         => false,
					'query_var'            => false,
					'rewrite'              => false,
					'show_in_rest'         => true,
					'rest_namespace'       => false,
					'default_term'         => $case['defaultTermInput'],
					'args'                 => array(
						'orderby'    => 'slug',
						'hide_empty' => false,
					),
					'meta_box_cb'          => $meta_box_cb,
					'meta_box_sanitize_cb' => $sanitize_cb,
				)
			)
		);
		$default_term_expected   = self::expected_default_term( $case['defaultTermInput'] );
		$sanitize_result         = call_user_func( $manual_taxonomy_object->meta_box_sanitize_cb, array( '7', 'bad', -2 ) );

		$hierarchical_defaults = new \WP_Taxonomy(
			$manual_taxonomy . '_h',
			$case['objectTypes'][0],
			array(
				'public'       => true,
				'hierarchical' => true,
				'query_var'    => false,
				'rewrite'      => false,
			)
		);
		$flat_defaults         = new \WP_Taxonomy(
			$manual_taxonomy . '_f',
			$case['objectTypes'][0],
			array(
				'public'       => true,
				'hierarchical' => false,
				'query_var'    => false,
				'rewrite'      => false,
			)
		);

		$query_taxonomy = $case['taxonomies']['rewriteBoundary'];
		$query_input    = 'Query Var ' . $case['token'] . ' / ' . $case['unicode'];
		$query_var      = \sanitize_title_with_dashes( $query_input );
		$rewrite_slug   = 'topics-' . $case['token'];
		$ajax_hook      = 'wp_ajax_add-' . $query_taxonomy;
		$registered     = \register_taxonomy(
			$query_taxonomy,
			$case['objectTypes'][0],
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'hierarchical'       => $case['hierarchical'],
				'query_var'          => $query_input,
				'rewrite'            => array(
					'slug'         => $rewrite_slug,
					'with_front'   => false,
					'hierarchical' => true,
					'ep_mask'      => EP_TAGS,
				),
			)
		);

		if ( \is_wp_error( $registered ) ) {
			return $ctx->fail(
				'taxonomy.registration.callbacks-query-rewrite-boundaries',
				self::case_data( $case ) + array(
					'registerError' => $registered->get_error_code(),
				)
			);
		}

		$rewrite_tag          = '%' . $query_taxonomy . '%';
		$rewrite_codes        = array_values( $GLOBALS['wp_rewrite']->rewritecode );
		$rewrite_replacements = array_values( $GLOBALS['wp_rewrite']->rewritereplace );
		$query_replacements   = array_values( $GLOBALS['wp_rewrite']->queryreplace );
		$tag_position         = array_search( $rewrite_tag, $rewrite_codes, true );
		$tag_regex            = is_int( $tag_position ) ? ( $rewrite_replacements[ $tag_position ] ?? null ) : null;
		$tag_query            = is_int( $tag_position ) ? ( $query_replacements[ $tag_position ] ?? null ) : null;
		$permastruct          = $GLOBALS['wp_rewrite']->extra_permastructs[ $query_taxonomy ] ?? null;
		$expected_tag_regex   = $registered->hierarchical && $registered->rewrite['hierarchical'] ? '(.+?)' : '([^/]+)';
		$hook_registered      = \has_filter( $ajax_hook, '_wp_ajax_add_hierarchical_term' );
		$query_var_count      = count( array_keys( $GLOBALS['wp']->public_query_vars, $query_var, true ) );
		$unregistered         = \unregister_taxonomy( $query_taxonomy );
		$query_var_removed    = ! in_array( $query_var, $GLOBALS['wp']->public_query_vars, true );
		$rewrite_removed      = false === array_search( $rewrite_tag, array_values( $GLOBALS['wp_rewrite']->rewritecode ), true )
			&& ! isset( $GLOBALS['wp_rewrite']->extra_permastructs[ $query_taxonomy ] );
		$hook_removed         = false === \has_filter( $ajax_hook, '_wp_ajax_add_hierarchical_term' );

		$manual_ok = $manual_taxonomy_object->meta_box_cb === $meta_box_cb
			&& $manual_taxonomy_object->meta_box_sanitize_cb === $sanitize_cb
			&& array( 7, 0, -2 ) === $sanitize_result
			&& true === $manual_taxonomy_object->show_in_rest
			&& 'wp/v2' === $manual_taxonomy_object->rest_namespace
			&& $default_term_expected === $manual_taxonomy_object->default_term
			&& array(
				'orderby'    => 'slug',
				'hide_empty' => false,
			) === $manual_taxonomy_object->args;

		$meta_box_defaults_ok = 'post_categories_meta_box' === $hierarchical_defaults->meta_box_cb
			&& 'taxonomy_meta_box_sanitize_cb_checkboxes' === $hierarchical_defaults->meta_box_sanitize_cb
			&& 'post_tags_meta_box' === $flat_defaults->meta_box_cb
			&& 'taxonomy_meta_box_sanitize_cb_input' === $flat_defaults->meta_box_sanitize_cb;

		$side_effects_ok = $registered instanceof \WP_Taxonomy
			&& $query_var === $registered->query_var
			&& 1 === $query_var_count
			&& is_int( $tag_position )
			&& $expected_tag_regex === $tag_regex
			&& $query_var . '=' === $tag_query
			&& is_array( $permastruct )
			&& str_contains( $permastruct['struct'], $rewrite_slug . '/' . $rewrite_tag )
			&& false === $permastruct['with_front']
			&& EP_TAGS === $permastruct['ep_mask']
			&& 10 === $hook_registered
			&& true === $unregistered
			&& $query_var_removed
			&& $rewrite_removed
			&& $hook_removed
			&& ! \taxonomy_exists( $query_taxonomy );

		return $ctx->result(
			'taxonomy.registration.callbacks-query-rewrite-boundaries',
			$manual_ok && $meta_box_defaults_ok && $side_effects_ok,
			self::case_data( $case ) + array(
				'manualOk'            => $manual_ok,
				'metaBoxDefaultsOk'   => $meta_box_defaults_ok,
				'sideEffectsOk'       => $side_effects_ok,
				'queryInput'          => $query_input,
				'queryVar'            => $query_var,
				'queryVarCount'       => $query_var_count,
				'registeredRewrite'   => $registered->rewrite,
				'rewriteTagPosition'  => $tag_position,
				'rewriteTagRegex'     => $tag_regex,
				'rewriteTagQuery'     => $tag_query,
				'permastruct'         => $permastruct,
				'hookRegistered'      => $hook_registered,
				'unregistered'        => $unregistered,
				'queryVarRemoved'     => $query_var_removed,
				'rewriteRemoved'      => $rewrite_removed,
				'hookRemoved'         => $hook_removed,
				'defaultTermExpected' => $default_term_expected,
				'defaultTermActual'   => $manual_taxonomy_object->default_term,
			)
		);
	}

	private static function check_sanitization_contexts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$taxonomy = $case['taxonomies']['sanitize'];
		$tax      = \register_taxonomy(
			$taxonomy,
			$case['objectTypes'][0],
			array(
				'public'    => true,
				'query_var' => false,
				'rewrite'   => false,
			)
		);

		if ( \is_wp_error( $tax ) ) {
			return $ctx->fail(
				'taxonomy.sanitize.context-invariants',
				self::case_data( $case ) + array(
					'registerError' => $tax->get_error_code(),
				)
			);
		}

		$raw_term        = self::synthetic_term_data( $case, $taxonomy );
		$contexts        = array( 'raw', 'db', 'display', 'edit', 'rss' );
		$context_results = array();
		$failures        = array();

		foreach ( $contexts as $context ) {
			$object_term = clone $raw_term;
			$array_term  = (array) clone $raw_term;

			$sanitized_object = \sanitize_term( $object_term, $taxonomy, $context );
			$sanitized_array  = \sanitize_term( $array_term, $taxonomy, $context );

			$context_ok = is_object( $sanitized_object )
				&& is_array( $sanitized_array )
				&& $context === $sanitized_object->filter
				&& $context === $sanitized_array['filter']
				&& self::term_object_and_array_agree( $sanitized_object, $sanitized_array )
				&& self::integer_term_fields_are_normalized( $sanitized_object );

			if ( 'raw' === $context ) {
				$context_ok = $context_ok
					&& $raw_term->name === $sanitized_object->name
					&& $raw_term->description === $sanitized_object->description
					&& $raw_term->slug === $sanitized_object->slug
					&& max( 0, (int) $raw_term->parent ) === $sanitized_object->parent
					&& max( 0, (int) $raw_term->count ) === $sanitized_object->count;
			}

			$context_results[ $context ] = array(
				'ok'          => $context_ok,
				'name'        => $sanitized_object->name,
				'slug'        => $sanitized_object->slug,
				'description' => $sanitized_object->description,
				'parent'      => $sanitized_object->parent,
				'count'       => $sanitized_object->count,
				'filter'      => $sanitized_object->filter,
			);

			if ( ! $context_ok ) {
				$failures[] = array(
					'context' => $context,
					'object'  => $sanitized_object,
					'array'   => $sanitized_array,
				);
			}
		}

		$plain_value         = 'plain value & "' . $case['unicode'];
		$plain_display      = \sanitize_term_field( 'name', $plain_value, $raw_term->term_id, $taxonomy, 'display' );
		$html_value          = '<em>' . $case['labels']['singular'] . '</em><script>alert(1)</script>';
		$html_display        = \sanitize_term_field( 'name', $html_value, $raw_term->term_id, $taxonomy, 'display' );
		$edit_name           = \sanitize_term_field( 'name', $html_value, $raw_term->term_id, $taxonomy, 'edit' );
		$edit_description    = \sanitize_term_field( 'description', $html_value, $raw_term->term_id, $taxonomy, 'edit' );
		$raw_negative_parent = \sanitize_term_field( 'parent', -25, $raw_term->term_id, $taxonomy, 'raw' );
		$db_invalid_slug     = \sanitize_term_field( 'slug', $case['invalidBytes'], $raw_term->term_id, $taxonomy, 'db' );

		$field_ok = is_string( $plain_display )
			&& ! str_contains( $plain_display, '<' )
			&& ! str_contains( $plain_display, '>' )
			&& substr_count( (string) $html_display, '<' ) <= substr_count( $html_value, '<' )
			&& substr_count( (string) $html_display, '>' ) <= substr_count( $html_value, '>' )
			&& is_string( $edit_name )
			&& ! str_contains( $edit_name, '<' )
			&& ! str_contains( $edit_name, '>' )
			&& is_string( $edit_description )
			&& ! str_contains( $edit_description, '<script>' )
			&& 0 === $raw_negative_parent
			&& is_string( $db_invalid_slug );

		return $ctx->result(
			'taxonomy.sanitize.context-invariants',
			array() === $failures && $field_ok,
			self::case_data( $case ) + array(
				'rawTerm'           => $raw_term,
				'contexts'          => $context_results,
				'fieldOk'           => $field_ok,
				'plainDisplay'      => $plain_display,
				'htmlDisplay'       => $html_display,
				'editName'          => $edit_name,
				'editDescription'   => $edit_description,
				'rawNegativeParent' => $raw_negative_parent,
				'dbInvalidSlug'     => $db_invalid_slug,
				'failures'          => array_slice( $failures, 0, 3 ),
			)
		);
	}

	private static function check_term_field_filter_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$taxonomy = $case['taxonomies']['filter'];
		$tax      = \register_taxonomy(
			$taxonomy,
			$case['objectTypes'][0],
			array(
				'public'    => true,
				'query_var' => false,
				'rewrite'   => false,
			)
		);

		if ( \is_wp_error( $tax ) ) {
			return $ctx->fail(
				'taxonomy.term-field.filters-and-slug-normalization',
				self::case_data( $case ) + array(
					'registerError' => $tax->get_error_code(),
				)
			);
		}

		$events  = array();
		$filters = array();
		$term_id = 400 + strlen( $case['token'] );

		$edit_generic = static function ( $value, int $seen_term_id, string $seen_taxonomy ) use ( &$events ): string {
			$events[] = array(
				'hook'     => 'edit_term_name',
				'termId'   => $seen_term_id,
				'taxonomy' => $seen_taxonomy,
				'value'    => $value,
			);
			return $value . '-generic-edit';
		};
		$edit_specific = static function ( $value, int $seen_term_id ) use ( &$events, $taxonomy ): string {
			$events[] = array(
				'hook'   => 'edit_' . $taxonomy . '_name',
				'termId' => $seen_term_id,
				'value'  => $value,
			);
			return $value . '-specific-edit';
		};
		$pre_slug_generic = static function ( string $value, string $seen_taxonomy ) use ( &$events ): string {
			$events[] = array(
				'hook'     => 'pre_term_slug',
				'taxonomy' => $seen_taxonomy,
				'value'    => $value,
			);
			return $value . '-generic-db';
		};
		$pre_slug_specific = static function ( string $value ) use ( &$events, $taxonomy ): string {
			$events[] = array(
				'hook'  => 'pre_' . $taxonomy . '_slug',
				'value' => $value,
			);
			return $value . '-specific-db';
		};
		$display_generic = static function ( string $value, int $seen_term_id, string $seen_taxonomy, string $context ) use ( &$events ): string {
			$events[] = array(
				'hook'     => 'term_description',
				'termId'   => $seen_term_id,
				'taxonomy' => $seen_taxonomy,
				'context'  => $context,
				'value'    => $value,
			);
			return $value . '-generic-display';
		};
		$display_specific = static function ( string $value, int $seen_term_id, string $context ) use ( &$events, $taxonomy ): string {
			$events[] = array(
				'hook'    => $taxonomy . '_description',
				'termId'  => $seen_term_id,
				'context' => $context,
				'value'   => $value,
			);
			return $value . '-specific-display';
		};
		$rss_generic = static function ( string $value, string $seen_taxonomy ) use ( &$events ): string {
			$events[] = array(
				'hook'     => 'term_slug_rss',
				'taxonomy' => $seen_taxonomy,
				'value'    => $value,
			);
			return $value . '-generic-rss';
		};
		$rss_specific = static function ( string $value ) use ( &$events, $taxonomy ): string {
			$events[] = array(
				'hook'  => $taxonomy . '_slug_rss',
				'value' => $value,
			);
			return $value . '-specific-rss';
		};

		$filters_restored = false;
		try {
			self::add_filter_record( $filters, 'edit_term_name', $edit_generic, 20, 3 );
			self::add_filter_record( $filters, 'edit_' . $taxonomy . '_name', $edit_specific, 20, 2 );
			self::add_filter_record( $filters, 'pre_term_slug', $pre_slug_generic, 20, 2 );
			self::add_filter_record( $filters, 'pre_' . $taxonomy . '_slug', $pre_slug_specific, 20, 1 );
			self::add_filter_record( $filters, 'term_description', $display_generic, 20, 4 );
			self::add_filter_record( $filters, $taxonomy . '_description', $display_specific, 20, 3 );
			self::add_filter_record( $filters, 'term_slug_rss', $rss_generic, 20, 2 );
			self::add_filter_record( $filters, $taxonomy . '_slug_rss', $rss_specific, 20, 1 );

			$raw_name        = '<b>Name</b> & ' . $case['token'];
			$raw_slug        = 'Résumé ' . $case['token'] . ' / <tag>';
			$raw_description = '<strong>Description</strong> ' . $case['unicode'];
			$edit_name       = \sanitize_term_field( 'name', $raw_name, $term_id, $taxonomy, 'edit' );
			$db_slug         = \sanitize_term_field( 'slug', $raw_slug, $term_id, $taxonomy, 'db' );
			$display_desc    = \sanitize_term_field( 'description', $raw_description, $term_id, $taxonomy, 'display' );
			$rss_slug        = \sanitize_term_field( 'slug', $raw_slug, $term_id, $taxonomy, 'rss' );
		} finally {
			self::remove_filter_records( $filters );
			$filters_restored = self::filters_absent( $filters );
		}

		$expected_slug   = $raw_slug . '-generic-db-specific-db';
		$dash_slug       = \sanitize_title_with_dashes( $raw_slug );
		$expected_events = array(
			'edit_term_name',
			'edit_' . $taxonomy . '_name',
			'pre_term_slug',
			'pre_' . $taxonomy . '_slug',
			'term_description',
			$taxonomy . '_description',
			'term_slug_rss',
			$taxonomy . '_slug_rss',
		);
		$actual_events   = array_column( $events, 'hook' );
		$edit_ok         = is_string( $edit_name )
			&& ! str_contains( $edit_name, '<' )
			&& ! str_contains( $edit_name, '>' )
			&& str_contains( $edit_name, 'generic-edit' )
			&& str_contains( $edit_name, 'specific-edit' );
		$db_ok           = $expected_slug === $db_slug;
		$dash_slug_ok    = '' !== $dash_slug
			&& strtolower( $dash_slug ) === $dash_slug
			&& ! str_contains( $dash_slug, ' ' )
			&& ! str_contains( $dash_slug, '<' )
			&& ! str_contains( $dash_slug, '>' );
		$display_ok      = is_string( $display_desc )
			&& str_contains( $display_desc, 'generic-display' )
			&& str_contains( $display_desc, 'specific-display' );
		$rss_ok          = $raw_slug . '-generic-rss-specific-rss' === $rss_slug;
		$events_ok       = $expected_events === $actual_events;

		return $ctx->result(
			'taxonomy.term-field.filters-and-slug-normalization',
			$edit_ok && $db_ok && $dash_slug_ok && $display_ok && $rss_ok && $events_ok && $filters_restored,
			self::case_data( $case ) + array(
				'editOk'          => $edit_ok,
				'dbOk'            => $db_ok,
				'dashSlugOk'      => $dash_slug_ok,
				'displayOk'       => $display_ok,
				'rssOk'           => $rss_ok,
				'eventsOk'        => $events_ok,
				'filtersRestored' => $filters_restored,
				'expectedEvents'  => $expected_events,
				'actualEvents'    => $actual_events,
				'rawSlug'         => $raw_slug,
				'expectedSlug'    => $expected_slug,
				'dbSlug'          => $db_slug,
				'dashSlug'        => $dash_slug,
				'editName'        => $edit_name,
				'displayDesc'     => $display_desc,
				'rssSlug'         => $rss_slug,
				'events'          => $events,
			)
		);
	}

	private static function check_synthetic_term_objects( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$taxonomy = $case['taxonomies']['termObject'];
		$tax      = \register_taxonomy(
			$taxonomy,
			$case['objectTypes'][0],
			array(
				'public'    => true,
				'query_var' => false,
				'rewrite'   => false,
			)
		);

		if ( \is_wp_error( $tax ) ) {
			return $ctx->fail(
				'taxonomy.term.synthetic-object-behavior',
				self::case_data( $case ) + array(
					'registerError' => $tax->get_error_code(),
				)
			);
		}

		$raw        = self::synthetic_term_data( $case, $taxonomy );
		$wp_term    = new \WP_Term( clone $raw );
		$get_object  = \get_term( $wp_term, $taxonomy, OBJECT, 'raw' );
		$get_array   = \get_term( $wp_term, $taxonomy, ARRAY_A, 'raw' );
		$get_numeric = \get_term( $wp_term, $taxonomy, ARRAY_N, 'raw' );
		$field_raw   = \get_term_field( 'name', new \WP_Term( clone $raw ), $taxonomy, 'raw' );
		$field_edit  = \get_term_field( 'name', new \WP_Term( clone $raw ), $taxonomy, 'edit' );
		$field_none  = \get_term_field( 'missing_field', new \WP_Term( clone $raw ), $taxonomy, 'raw' );

		$filtered = new \WP_Term( clone $raw );
		$filtered->filter( 'display' );
		$data = $filtered->data;

		$cache_id    = (int) $raw->term_id + 1000;
		$cached_term = clone $raw;
		$cached_term->term_id = $cache_id;
		\wp_cache_set( $cache_id, $cached_term, 'terms' );
		$instance = \WP_Term::get_instance( $cache_id, $taxonomy );
		\wp_cache_delete( $cache_id, 'terms' );

		$invalid_instance = \WP_Term::get_instance( 0, $taxonomy );

		$ok = $get_object instanceof \WP_Term
			&& $raw->name === $get_object->name
			&& is_array( $get_array )
			&& $raw->name === $get_array['name']
			&& is_array( $get_numeric )
			&& count( $get_numeric ) === count( $get_array )
			&& $raw->name === $field_raw
			&& is_string( $field_edit )
			&& ! str_contains( $field_edit, '<' )
			&& '' === $field_none
			&& 'display' === $filtered->filter
			&& self::integer_term_fields_are_normalized( $filtered )
			&& is_object( $data )
			&& $data->taxonomy === $taxonomy
			&& 'raw' === $data->filter
			&& $instance instanceof \WP_Term
			&& $cache_id === (int) $instance->term_id
			&& $taxonomy === $instance->taxonomy
			&& false === $invalid_instance;

		return $ctx->result(
			'taxonomy.term.synthetic-object-behavior',
			$ok,
			self::case_data( $case ) + array(
				'termId'          => $raw->term_id,
				'fieldRaw'        => $field_raw,
				'fieldEdit'       => $field_edit,
				'fieldMissing'    => $field_none,
				'filtered'        => $filtered,
				'data'            => $data,
				'arrayKeys'       => array_keys( $get_array ),
				'numericCount'    => count( $get_numeric ),
				'cacheInstance'   => $instance,
				'invalidInstance' => $invalid_instance,
			)
		);
	}

	private static function check_hierarchy_helper_edges( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$taxonomy = $case['taxonomies']['hierarchy'];
		$tax      = \register_taxonomy(
			$taxonomy,
			$case['objectTypes'][0],
			array(
				'public'       => true,
				'hierarchical' => true,
				'query_var'    => false,
				'rewrite'      => false,
			)
		);

		if ( \is_wp_error( $tax ) ) {
			return $ctx->fail(
				'taxonomy.hierarchy.cache-backed-ancestor-edges',
				self::case_data( $case ) + array(
					'registerError' => $tax->get_error_code(),
				)
			);
		}

		$base       = 700000 + (int) hexdec( substr( sha1( $case['token'] ), 0, 4 ) );
		$grand_id   = $base + 1;
		$parent_id  = $base + 2;
		$child_id   = $base + 3;
		$loop_a_id  = $base + 10;
		$loop_b_id  = $base + 11;
		$cached_ids = array();

		$unregistered = false;
		try {
			$terms = array(
				self::hierarchy_term( $grand_id, $taxonomy, 'grand-' . $case['token'], 'Grand ' . $case['token'], 0 ),
				self::hierarchy_term( $parent_id, $taxonomy, 'parent-' . $case['token'], 'Parent ' . $case['token'], $grand_id ),
				self::hierarchy_term( $child_id, $taxonomy, 'child-' . $case['token'], 'Child ' . $case['token'], $parent_id ),
				self::hierarchy_term( $loop_a_id, $taxonomy, 'loop-a-' . $case['token'], 'Loop A ' . $case['token'], $loop_b_id ),
				self::hierarchy_term( $loop_b_id, $taxonomy, 'loop-b-' . $case['token'], 'Loop B ' . $case['token'], $loop_a_id ),
			);

			foreach ( $terms as $term ) {
				\wp_cache_set( (int) $term->term_id, $term, 'terms' );
				$cached_ids[] = (int) $term->term_id;
			}

			$explicit_ancestors = \get_ancestors( $child_id, $taxonomy, 'taxonomy' );
			$detected_ancestors = \get_ancestors( $child_id, $taxonomy );
			$loop_ancestors     = \get_ancestors( $loop_a_id, $taxonomy, 'taxonomy' );
			$grand_is_ancestor  = \term_is_ancestor_of( $grand_id, $child_id, $taxonomy );
			$parent_is_ancestor = \term_is_ancestor_of( $parent_id, $child_id, $taxonomy );
			$child_is_ancestor  = \term_is_ancestor_of( $child_id, $grand_id, $taxonomy );
			$self_parent        = \wp_check_term_hierarchy_for_loops( $child_id, $child_id, $taxonomy );
			$zero_parent        = \wp_check_term_hierarchy_for_loops( 0, $child_id, $taxonomy );
			$valid_parent       = \wp_check_term_hierarchy_for_loops( $parent_id, $child_id, $taxonomy );
			$loop_parent        = \wp_check_term_hierarchy_for_loops( $loop_b_id, $loop_a_id, $taxonomy );
			$unregistered       = \unregister_taxonomy( $taxonomy );
		} finally {
			foreach ( $cached_ids as $cached_id ) {
				\wp_cache_delete( $cached_id, 'terms' );
			}
			if ( \taxonomy_exists( $taxonomy ) ) {
				\unregister_taxonomy( $taxonomy );
			}
		}

		$chain_ok = array( $parent_id, $grand_id ) === $explicit_ancestors
			&& $explicit_ancestors === $detected_ancestors
			&& true === $grand_is_ancestor
			&& true === $parent_is_ancestor
			&& false === $child_is_ancestor;
		$loop_ok  = array( $loop_b_id, $loop_a_id ) === $loop_ancestors
			&& 0 === $self_parent
			&& 0 === $zero_parent
			&& $parent_id === $valid_parent
			&& 0 === $loop_parent;
		$cleanup_ok = true === $unregistered && ! \taxonomy_exists( $taxonomy );

		return $ctx->result(
			'taxonomy.hierarchy.cache-backed-ancestor-edges',
			$chain_ok && $loop_ok && $cleanup_ok,
			self::case_data( $case ) + array(
				'chainOk'           => $chain_ok,
				'loopOk'            => $loop_ok,
				'cleanupOk'         => $cleanup_ok,
				'ids'               => array(
					'grand'  => $grand_id,
					'parent' => $parent_id,
					'child'  => $child_id,
					'loopA'  => $loop_a_id,
					'loopB'  => $loop_b_id,
				),
				'explicitAncestors' => $explicit_ancestors,
				'detectedAncestors' => $detected_ancestors,
				'loopAncestors'     => $loop_ancestors,
				'grandIsAncestor'   => $grand_is_ancestor,
				'parentIsAncestor'  => $parent_is_ancestor,
				'childIsAncestor'   => $child_is_ancestor,
				'selfParent'        => $self_parent,
				'zeroParent'        => $zero_parent,
				'validParent'       => $valid_parent,
				'loopParent'        => $loop_parent,
				'unregistered'      => $unregistered,
			)
		);
	}

	private static function check_term_link_cheap_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::reset_taxonomy_registry();

		$query_taxonomy   = $case['taxonomies']['linkQuery'];
		$rewrite_taxonomy = $case['taxonomies']['linkRewrite'];
		$query_var        = 'cfq_' . $case['token'];
		$link_slug        = 'term-' . $case['token'];
		$rewrite_slug     = 'topics-' . $case['token'];

		$query_tax = \register_taxonomy(
			$query_taxonomy,
			$case['objectTypes'][0],
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'hierarchical'       => false,
				'query_var'          => $query_var,
				'rewrite'            => false,
			)
		);
		$rewrite_tax = \register_taxonomy(
			$rewrite_taxonomy,
			$case['objectTypes'][0],
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'hierarchical'       => false,
				'query_var'          => false,
				'rewrite'            => array(
					'slug'         => $rewrite_slug,
					'with_front'   => false,
					'hierarchical' => false,
					'ep_mask'      => EP_NONE,
				),
			)
		);

		if ( \is_wp_error( $query_tax ) || \is_wp_error( $rewrite_tax ) ) {
			return $ctx->fail(
				'taxonomy.term-links.cheap-object-paths',
				self::case_data( $case ) + array(
					'queryError'   => \is_wp_error( $query_tax ) ? $query_tax->get_error_code() : null,
					'rewriteError' => \is_wp_error( $rewrite_tax ) ? $rewrite_tax->get_error_code() : null,
				)
			);
		}

		$query_term   = new \WP_Term( self::synthetic_term_data( $case, $query_taxonomy, $link_slug ) );
		$rewrite_term = new \WP_Term( self::synthetic_term_data( $case, $rewrite_taxonomy, $link_slug ) );
		$query_link   = \get_term_link( $query_term, $query_taxonomy );
		$rewrite_link = \get_term_link( $rewrite_term, $rewrite_taxonomy );

		$ok = is_string( $query_link )
			&& str_starts_with( $query_link, self::HOME_URL )
			&& str_contains( $query_link, '?' . $query_var . '=' . $link_slug )
			&& is_string( $rewrite_link )
			&& str_starts_with( $rewrite_link, self::HOME_URL )
			&& str_contains( $rewrite_link, '/' . $rewrite_slug . '/' )
			&& str_contains( $rewrite_link, '/' . $link_slug )
			&& ! \is_wp_error( $query_link )
			&& ! \is_wp_error( $rewrite_link );

		return $ctx->result(
			'taxonomy.term-links.cheap-object-paths',
			$ok,
			self::case_data( $case ) + array(
				'queryVar'    => $query_var,
				'rewriteSlug' => $rewrite_slug,
				'termSlug'    => $link_slug,
				'queryLink'   => $query_link,
				'rewriteLink' => $rewrite_link,
			)
		);
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::safe_token(
			$ctx->identifier( 4, 10 ) . '_' . dechex( $ctx->seed() & 0xffff ) . '_' . $ctx->iteration()
		);
		$token = substr( $token, 0, 13 );

		$unicode = $ctx->choice( array( 'é', '☃', '中文', 'مرحبا' ) );
		$invalid = "invalid-\x80\xFF-" . $token;

		$query_var = $ctx->choice(
			array(
				true,
				false,
				'query_' . $token,
				'Query Var ' . $token,
				'query/var?' . $token,
				"query\x80" . $token,
			)
		);

		$rewrite = $ctx->choice(
			array(
				false,
				true,
				array(
					'slug'         => 'topics/' . $unicode . '/' . $token,
					'with_front'   => $ctx->bool(),
					'hierarchical' => $ctx->bool(),
					'ep_mask'      => EP_NONE,
				),
				array(
					'slug'         => '',
					'with_front'   => $ctx->bool(),
					'hierarchical' => $ctx->bool(),
					'ep_mask'      => EP_TAGS,
				),
			)
		);

		return array(
			'token'               => $token,
			'taxonomies'          => array(
				'primary'       => 'cf_tax_' . substr( $token, 0, 18 ),
				'listA'         => 'cf_list_a_' . substr( $token, 0, 15 ),
				'listB'         => 'cf_list_b_' . substr( $token, 0, 15 ),
				'normalization' => 'cf_norm_' . substr( $token, 0, 18 ),
				'defaults'      => 'cf_def_' . substr( $token, 0, 19 ),
				'sanitize'      => 'cf_san_' . substr( $token, 0, 19 ),
				'callbacks'     => 'cf_cb_' . substr( $token, 0, 21 ),
				'rewriteBoundary' => 'cf_rew_' . substr( $token, 0, 20 ),
				'filter'        => 'cf_flt_' . substr( $token, 0, 20 ),
				'termObject'    => 'cf_term_' . substr( $token, 0, 18 ),
				'hierarchy'     => 'cf_hier_' . substr( $token, 0, 19 ),
				'linkQuery'     => 'cf_lq_' . substr( $token, 0, 20 ),
				'linkRewrite'   => 'cf_lr_' . substr( $token, 0, 20 ),
			),
			'objectTypes'         => array(
				'cfp_' . substr( $token, 0, 16 ),
				'cfs_' . substr( $token, 0, 16 ),
			),
			'permalinkStructure'  => $ctx->choice( array( '/%postname%/', '/index.php/%postname%/' ) ),
			'public'              => $ctx->bool(),
			'publiclyQueryable'   => $ctx->choice( array( null, true, false ) ),
			'hierarchical'        => $ctx->bool(),
			'showUi'              => $ctx->choice( array( null, true, false ) ),
			'showInMenu'          => $ctx->choice( array( null, true, false ) ),
			'showInNavMenus'      => $ctx->choice( array( null, true, false ) ),
			'showTagcloud'        => $ctx->choice( array( null, true, false ) ),
			'showQuickEdit'       => $ctx->choice( array( null, true, false ) ),
			'showAdminColumn'     => $ctx->bool(),
			'defaultHierarchical' => $ctx->bool(),
			'queryVar'            => $query_var,
			'rewrite'             => $rewrite,
			'sort'                => $ctx->choice( array( null, true, false ) ),
			'unicode'             => $unicode,
			'invalidBytes'        => $invalid,
			'labels'              => array(
				'name'     => $ctx->choice(
					array( 'Topics ' . $unicode, 'Terms & Things', '<b>Labels</b>', $invalid )
				) . ' ' . $token,
				'singular' => $ctx->choice(
					array( 'Topic ' . $unicode, 'One & Only', '<i>Single</i>', "Single\xC3\x28" )
				) . ' ' . $token,
			),
			'description'         => $ctx->choice(
				array(
					'Description for ' . $token,
					'<script>alert(1)</script> ' . $unicode,
					$invalid,
					str_repeat( 'long-name-', 12 ) . $token,
				)
			),
			'slugInput'           => $ctx->choice(
				array(
					'plain-' . $token,
					'résumé ' . $token,
					'a/b?x=1&y=' . $token,
					$invalid,
					str_repeat( 'long-', 16 ) . $token,
				)
			),
			'capabilities'        => array(
				'manage_terms' => 'manage_' . $token,
				'edit_terms'   => 'edit-' . $token,
				'delete_terms' => 'delete ' . $token,
				'assign_terms' => "assign\x80" . $token,
			),
			'defaultTermInput'    => $ctx->choice(
				array(
					'Default ' . $unicode . ' ' . $token,
					array(
						'name'        => 'Default ' . $token,
						'slug'        => 'Default Slug ' . $token,
						'description' => 'Default description ' . $unicode,
					),
					array(
						'name' => '<b>Default</b> ' . $token,
					),
				)
			),
		);
	}

	private static function registration_args( array $case, array $overrides = array() ): array {
		$args = array(
			'labels'               => array(
				'name'          => $case['labels']['name'],
				'singular_name' => $case['labels']['singular'],
			),
			'description'          => $case['description'],
			'public'               => $case['public'],
			'publicly_queryable'   => $case['publiclyQueryable'],
			'hierarchical'         => $case['hierarchical'],
			'show_ui'              => $case['showUi'],
			'show_in_menu'         => $case['showInMenu'],
			'show_in_nav_menus'    => $case['showInNavMenus'],
			'show_tagcloud'        => $case['showTagcloud'],
			'show_in_quick_edit'   => $case['showQuickEdit'],
			'show_admin_column'    => $case['showAdminColumn'],
			'capabilities'         => $case['capabilities'],
			'rewrite'              => $case['rewrite'],
			'query_var'            => $case['queryVar'],
			'show_in_rest'         => false,
			'default_term'         => null,
			'sort'                 => $case['sort'],
			'args'                 => array(
				'orderby'         => 'name',
				'component_fuzz'  => $case['token'],
				'suppress_filter' => false,
			),
			'meta_box_cb'          => null,
			'meta_box_sanitize_cb' => null,
		);

		foreach ( $overrides as $key => $value ) {
			$args[ $key ] = $value;
		}

		return $args;
	}

	private static function expected_normalized_args( string $taxonomy, array $args ): array {
		$public             = (bool) $args['public'];
		$publicly_queryable = null === $args['publicly_queryable'] ? $public : (bool) $args['publicly_queryable'];
		$show_ui            = null === $args['show_ui'] ? $public : (bool) $args['show_ui'];
		$show_in_menu       = ( null === $args['show_in_menu'] || ! $show_ui ) ? $show_ui : (bool) $args['show_in_menu'];
		$show_in_nav_menus  = null === $args['show_in_nav_menus'] ? $public : (bool) $args['show_in_nav_menus'];
		$show_tagcloud      = null === $args['show_tagcloud'] ? $show_ui : (bool) $args['show_tagcloud'];
		$show_quick_edit    = null === $args['show_in_quick_edit'] ? $show_ui : (bool) $args['show_in_quick_edit'];
		$query_var          = false;

		if ( false !== $args['query_var'] && false !== $publicly_queryable ) {
			$query_var = true === $args['query_var'] ? $taxonomy : \sanitize_title_with_dashes( $args['query_var'] );
		}

		$rewrite = $args['rewrite'];
		if ( false !== $rewrite && \get_option( 'permalink_structure' ) ) {
			$rewrite = wp_parse_args(
				$rewrite,
				array(
					'with_front'   => true,
					'hierarchical' => false,
					'ep_mask'      => EP_NONE,
				)
			);

			if ( empty( $rewrite['slug'] ) ) {
				$rewrite['slug'] = \sanitize_title_with_dashes( $taxonomy );
			}
		}

		$capabilities = array_merge(
			array(
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_posts',
			),
			$args['capabilities']
		);

		return array(
			'publicly_queryable'  => $publicly_queryable,
			'show_ui'            => $show_ui,
			'show_in_menu'       => $show_in_menu,
			'show_in_nav_menus'  => $show_in_nav_menus,
			'show_tagcloud'      => $show_tagcloud,
			'show_in_quick_edit' => $show_quick_edit,
			'query_var'          => $query_var,
			'rewrite'            => $rewrite,
			'capabilities'       => $capabilities,
		);
	}

	private static function synthetic_term_data( array $case, string $taxonomy, ?string $slug = null ): \stdClass {
		return (object) array(
			'term_id'          => 100 + strlen( $case['token'] ),
			'name'             => '<b>' . $case['labels']['singular'] . '</b> & ' . $case['invalidBytes'],
			'slug'             => $slug ?? $case['slugInput'],
			'term_group'       => -3,
			'term_taxonomy_id' => 200 + strlen( $taxonomy ),
			'taxonomy'         => $taxonomy,
			'description'      => $case['description'],
			'parent'           => -12,
			'count'            => -7,
			'object_id'        => -19,
			'filter'           => 'raw',
		);
	}

	private static function hierarchy_term( int $term_id, string $taxonomy, string $slug, string $name, int $parent ): \stdClass {
		return (object) array(
			'term_id'          => $term_id,
			'name'             => $name,
			'slug'             => $slug,
			'term_group'       => 0,
			'term_taxonomy_id' => $term_id + 1000,
			'taxonomy'         => $taxonomy,
			'description'      => 'Synthetic hierarchy term ' . $term_id,
			'parent'           => $parent,
			'count'            => 0,
			'filter'           => 'raw',
		);
	}

	private static function expected_default_term( $default_term ): array {
		if ( ! is_array( $default_term ) ) {
			$default_term = array( 'name' => $default_term );
		}

		return array_merge(
			array(
				'name'        => '',
				'slug'        => '',
				'description' => '',
			),
			$default_term
		);
	}

	private static function install_option_filters( array $case ): void {
		$options = array(
			'home'                => self::HOME_URL,
			'siteurl'             => self::SITE_URL,
			'permalink_structure' => $case['permalinkStructure'],
			'blog_charset'        => 'UTF-8',
		);

		foreach ( $options as $option => $value ) {
			\add_filter(
				'pre_option_' . $option,
				static function () use ( $value ) {
					return $value;
				},
				0,
				3
			);
		}
	}

	private static function prepare_runtime( array $case ): void {
		$GLOBALS['wp_taxonomies'] = array();
		$GLOBALS['wp']            = new \WP();
		$GLOBALS['wp_rewrite']    = new \WP_Rewrite();
		$GLOBALS['wp_post_types'] = array();

		foreach ( $case['objectTypes'] as $object_type ) {
			$GLOBALS['wp_post_types'][ $object_type ] = (object) array(
				'name' => $object_type,
			);
		}

		$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
		$_SERVER['PHP_SELF']    = $_SERVER['PHP_SELF'] ?? '/index.php';
		$_SERVER['HTTPS']       = $_SERVER['HTTPS'] ?? 'off';
	}

	private static function reset_taxonomy_registry(): void {
		$GLOBALS['wp_taxonomies'] = array();

		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ) {
			$GLOBALS['wp']->public_query_vars = array();
			$GLOBALS['wp']->private_query_vars = array();
		}

		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}
	}

	private static function term_object_and_array_agree( object $object_term, array $array_term ): bool {
		$fields = array(
			'term_id',
			'name',
			'description',
			'slug',
			'count',
			'parent',
			'term_group',
			'term_taxonomy_id',
			'object_id',
			'filter',
		);

		foreach ( $fields as $field ) {
			if ( ( $object_term->$field ?? null ) !== ( $array_term[ $field ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function integer_term_fields_are_normalized( object $term ): bool {
		foreach ( array( 'term_id', 'count', 'parent', 'term_group', 'term_taxonomy_id', 'object_id' ) as $field ) {
			if ( ! isset( $term->$field ) || ! is_int( $term->$field ) || $term->$field < 0 ) {
				return false;
			}
		}

		return true;
	}

	private static function rewrite_matches_expectation( $expected, $actual ): bool {
		if ( false === $expected ) {
			return false === $actual;
		}

		if ( true === $expected ) {
			return true === $actual;
		}

		if ( ! is_array( $expected ) || ! is_array( $actual ) ) {
			return false;
		}

		foreach ( array( 'slug', 'with_front', 'hierarchical', 'ep_mask' ) as $key ) {
			if ( ! array_key_exists( $key, $actual ) || $expected[ $key ] !== $actual[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	private static function capabilities_array( \stdClass $capabilities ): array {
		return array(
			'manage_terms' => $capabilities->manage_terms ?? null,
			'edit_terms'   => $capabilities->edit_terms ?? null,
			'delete_terms' => $capabilities->delete_terms ?? null,
			'assign_terms' => $capabilities->assign_terms ?? null,
		);
	}

	private static function same_name_set( array $actual, array $expected ): bool {
		$actual   = array_values( array_map( 'strval', $actual ) );
		$expected = array_values( array_map( 'strval', $expected ) );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	private static function objects_match_names( array $objects ): bool {
		foreach ( $objects as $name => $object ) {
			if ( ! ( $object instanceof \WP_Taxonomy ) || $name !== $object->name ) {
				return false;
			}
		}

		return true;
	}

	private static function add_filter_record( array &$filters, string $hook, callable $callback, int $priority, int $accepted_args ): void {
		\add_filter( $hook, $callback, $priority, $accepted_args );
		$filters[] = array( $hook, $callback, $priority );
	}

	private static function remove_filter_records( array $filters ): void {
		foreach ( $filters as $filter ) {
			\remove_filter( $filter[0], $filter[1], $filter[2] );
		}
	}

	private static function filters_absent( array $filters ): bool {
		foreach ( $filters as $filter ) {
			if ( false !== \has_filter( $filter[0], $filter[1] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_globals(): array {
		$globals = array();
		foreach (
			array(
				'wp_taxonomies',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'wp_rewrite',
				'wp',
				'wp_post_types',
				'wp_object_cache',
				'wpdb',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'_SERVER' => $_SERVER,
			'_GET'    => $_GET,
		);
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		$_SERVER = $snapshot['_SERVER'];
		$_GET    = $snapshot['_GET'];
	}

	private static function case_data( array $case ): array {
		return array(
			'token'              => $case['token'],
			'primaryTaxonomy'    => $case['taxonomies']['primary'],
			'objectTypes'        => $case['objectTypes'],
			'permalinkStructure' => $case['permalinkStructure'],
			'queryVarInput'      => $case['queryVar'],
			'rewriteInput'       => $case['rewrite'],
			'labelName'          => $case['labels']['name'],
			'labelSingular'      => $case['labels']['singular'],
			'slugInput'          => $case['slugInput'],
		);
	}

	private static function safe_token( string $value ): string {
		$value = strtolower( preg_replace( '/[^A-Za-z0-9_]+/', '_', $value ) ?? '' );
		$value = trim( $value, '_' );

		return '' === $value ? 'x' : $value;
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
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
			$keys = array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) );
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $expected ) || ! array_key_exists( $key, $actual ) ) {
					return array(
						'path'     => $path . '[' . var_export( $key, true ) . ']',
						'expected' => array_key_exists( $key, $expected ) ? 'present' : 'missing',
						'actual'   => array_key_exists( $key, $actual ) ? 'present' : 'missing',
					);
				}

				$difference = self::first_value_difference(
					$expected[ $key ],
					$actual[ $key ],
					$path . '[' . var_export( $key, true ) . ']'
				);
				if ( null !== $difference ) {
					return $difference;
				}
			}

			return null;
		}

		if ( is_object( $expected ) ) {
			if ( $expected !== $actual ) {
				return array(
					'path'     => $path,
					'expected' => '[object ' . get_class( $expected ) . ']',
					'actual'   => '[object ' . get_class( $actual ) . ']',
				);
			}

			return null;
		}

		if ( $expected !== $actual ) {
			return array(
				'path'     => $path,
				'expected' => $expected,
				'actual'   => $actual,
			);
		}

		return null;
	}
}
