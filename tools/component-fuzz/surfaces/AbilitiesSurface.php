<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes the no-DB Abilities API registries and execution pipeline.
 */
final class AbilitiesSurface {
	public const NAME = 'abilities';

	private const CATEGORY_CASES = 5;
	private const ABILITY_CASES  = 6;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'abilities.bootstrap-apis-available',
					'Required WordPress Abilities APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();

		try {
			self::ensure_init_fired();
			return array(
				self::check_action_guards( $ctx ),
				self::check_category_registry( $ctx ),
				self::check_ability_registration_and_queries( $ctx ),
				self::check_category_unregistration_preserves_abilities( $ctx ),
				self::check_custom_subclass_query_and_reregister( $ctx ),
				self::check_execution_pipeline( $ctx ),
				self::check_validation_filter_and_exception_paths( $ctx ),
				self::check_invalid_registration_paths( $ctx ),
			);
		} catch ( \Throwable $e ) {
			return array(
				$ctx->fail(
					'abilities.surface-no-throw',
					array( 'throwable' => self::describe_throwable( $e ) )
				),
			);
		} finally {
			self::restore_state( $snapshot );
		}
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'WP_Abilities_Registry',
				'WP_Ability',
				'WP_Ability_Categories_Registry',
				'WP_Ability_Category',
				'WP_Error',
				'WP_Filter_Sentinel',
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
				'apply_filters',
				'did_action',
				'do_action',
				'doing_action',
				'is_wp_error',
				'remove_action',
				'remove_filter',
				'rest_validate_value_from_schema',
				'wp_get_abilities',
				'wp_get_ability',
				'wp_get_ability_categories',
				'wp_get_ability_category',
				'wp_has_ability',
				'wp_has_ability_category',
				'wp_register_ability',
				'wp_register_ability_category',
				'wp_unregister_ability',
				'wp_unregister_ability_category',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_action_guards( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		self::reset_registries();
		$category_slug = self::category_slug( $ctx->fork( 'guard-category' ), 'guard' );
		$ability_name  = self::ability_name( $ctx->fork( 'guard-ability' ), 'guard' );

		$outside_category = \wp_register_ability_category(
			$category_slug,
			self::category_args( $category_slug, array( 'guard' => true ) )
		);
		$outside_ability  = \wp_register_ability(
			$ability_name,
			self::ability_args(
				'Missing category should not matter outside action.',
				$category_slug,
				static fn( $input ) => $input,
				static fn() => true,
				array( 'type' => 'string' ),
				array( 'type' => 'string' )
			)
		);

		self::collect_failure(
			$failures,
			null === $outside_category
				&& null === $outside_ability
				&& ! \wp_has_ability_category( $category_slug )
				&& ! \wp_has_ability( $ability_name ),
			'public registration functions reject calls outside their init actions',
			array(
				'category' => $category_slug,
				'ability'  => $ability_name,
			)
		);

		$old_init = $GLOBALS['wp_actions']['init'] ?? null;
		self::reset_registries();
		unset( $GLOBALS['wp_actions']['init'] );
		$pre_init_category_registry = \WP_Ability_Categories_Registry::get_instance();
		$pre_init_ability_registry  = \WP_Abilities_Registry::get_instance();
		if ( null === $old_init ) {
			unset( $GLOBALS['wp_actions']['init'] );
		} else {
			$GLOBALS['wp_actions']['init'] = $old_init;
		}
		self::ensure_init_fired();

		self::collect_failure(
			$failures,
			null === $pre_init_category_registry && null === $pre_init_ability_registry,
			'registries are gated until init has fired',
			array(
				'categoryRegistry' => self::describe_value( $pre_init_category_registry ),
				'abilityRegistry'  => self::describe_value( $pre_init_ability_registry ),
			)
		);

		return self::result(
			$ctx,
			'abilities.registration-action-guards',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_category_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::category_cases( $ctx->fork( 'categories' ) );
		$captured = array(
			'registered'       => array(),
			'duplicate'        => null,
			'invalidSlug'      => null,
			'invalidArgs'      => null,
			'filterSeenSlugs'  => array(),
			'filterSeenLabels' => array(),
		);

		self::reset_registries();

		$filter = static function ( array $args, string $slug ) use ( &$captured ): array {
			$captured['filterSeenSlugs'][]  = $slug;
			$captured['filterSeenLabels'][] = $args['label'] ?? null;
			$args['meta']['filtered']       = $slug;
			return $args;
		};

		$action = static function () use ( $cases, &$captured ): void {
			foreach ( $cases as $case ) {
				$captured['registered'][ $case['slug'] ] = \wp_register_ability_category( $case['slug'], $case['args'] );
			}

			$captured['duplicate']   = \wp_register_ability_category( $cases[0]['slug'], $cases[0]['args'] );
			$captured['invalidSlug'] = \wp_register_ability_category( 'Bad_Slug', self::category_args( 'Bad Slug' ) );
			$captured['invalidArgs'] = \wp_register_ability_category(
				'invalid-args',
				array(
					'label'       => '',
					'description' => 'Missing a valid label.',
				)
			);
		};

		\add_filter( 'wp_register_ability_category_args', $filter, 10, 2 );
		\add_action( 'wp_abilities_api_categories_init', $action );
		$registry = \WP_Ability_Categories_Registry::get_instance();
		\remove_action( 'wp_abilities_api_categories_init', $action );
		\remove_filter( 'wp_register_ability_category_args', $filter, 10 );

		$all = \wp_get_ability_categories();
		foreach ( $cases as $case ) {
			$category = \wp_get_ability_category( $case['slug'] );
			self::collect_failure(
				$failures,
				$registry instanceof \WP_Ability_Categories_Registry
					&& $captured['registered'][ $case['slug'] ] instanceof \WP_Ability_Category
					&& $category === $captured['registered'][ $case['slug'] ]
					&& \wp_has_ability_category( $case['slug'] )
					&& isset( $all[ $case['slug'] ] )
					&& $case['slug'] === $category->get_slug()
					&& $case['args']['label'] === $category->get_label()
					&& $case['args']['description'] === $category->get_description()
					&& $case['slug'] === ( $category->get_meta()['filtered'] ?? null ),
				"category registration stores exact filtered category {$case['slug']}",
				array(
					'case'     => $case,
					'category' => self::describe_category( $category ),
				)
			);
		}

		$removed = \wp_unregister_ability_category( $cases[0]['slug'] );
		self::collect_failure(
			$failures,
			$removed === $captured['registered'][ $cases[0]['slug'] ]
				&& ! \wp_has_ability_category( $cases[0]['slug'] )
				&& null === \wp_get_ability_category( $cases[0]['slug'] ),
			'unregister category returns the exact removed instance and clears lookups',
			array(
				'removed' => self::describe_category( $removed ),
				'slug'    => $cases[0]['slug'],
			)
		);

		self::collect_failure(
			$failures,
			null === $captured['duplicate']
				&& null === $captured['invalidSlug']
				&& null === $captured['invalidArgs']
				&& count( $captured['filterSeenSlugs'] ) >= count( $cases ),
			'category invalid and duplicate registrations fail without storing entries',
			array( 'captured' => $captured )
		);

		return self::result(
			$ctx,
			'abilities.category-registry-lifecycle',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_ability_registration_and_queries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$category_ctx = $ctx->fork( 'ability-categories' );
		$ability_ctx  = $ctx->fork( 'ability-registry' );
		$categories   = self::category_cases( $category_ctx );
		$cases        = self::ability_cases( $ability_ctx, $categories );
		$captured     = array(
			'registered'      => array(),
			'duplicate'       => null,
			'missingCategory' => null,
			'invalidName'     => null,
			'invalidClass'    => null,
			'filteredNames'   => array(),
		);

		self::reset_registries();
		$category_action = self::install_category_action( $categories );

		$filter = static function ( array $args, string $name ) use ( &$captured ): array {
			$captured['filteredNames'][]    = $name;
			$args['meta']['source']['seen'] = $name;
			return $args;
		};

		$action = static function () use ( $cases, &$captured ): void {
			foreach ( $cases as $case ) {
				$captured['registered'][ $case['name'] ] = \wp_register_ability( $case['name'], $case['args'] );
			}

			$captured['duplicate']       = \wp_register_ability( $cases[0]['name'], $cases[0]['args'] );
			$captured['missingCategory'] = \wp_register_ability(
				'cfuzz-missing-category/missing',
				self::ability_args(
					'Missing category.',
					'not-registered',
					static fn() => 'missing',
					static fn() => true
				)
			);
			$captured['invalidName']     = \wp_register_ability(
				'bad/name/extra',
				self::ability_args(
					'Invalid name.',
					$cases[0]['args']['category'],
					static fn() => 'invalid',
					static fn() => true
				)
			);
			$captured['invalidClass']    = \wp_register_ability(
				'cfuzz-invalid/class',
				self::ability_args(
					'Invalid class.',
					$cases[0]['args']['category'],
					static fn() => 'invalid',
					static fn() => true,
					array(),
					array(),
					array(),
					\stdClass::class
				)
			);
		};

		\add_filter( 'wp_register_ability_args', $filter, 10, 2 );
		\add_action( 'wp_abilities_api_init', $action );
		$registry = \WP_Abilities_Registry::get_instance();
		\remove_action( 'wp_abilities_api_init', $action );
		\remove_action( 'wp_abilities_api_categories_init', $category_action );
		\remove_filter( 'wp_register_ability_args', $filter, 10 );

		$all = \wp_get_abilities();
		foreach ( $cases as $case ) {
			$ability = \wp_get_ability( $case['name'] );
			$meta    = $ability instanceof \WP_Ability ? $ability->get_meta() : array();
			self::collect_failure(
				$failures,
				$registry instanceof \WP_Abilities_Registry
					&& $captured['registered'][ $case['name'] ] instanceof \WP_Ability
					&& $ability === $captured['registered'][ $case['name'] ]
					&& \wp_has_ability( $case['name'] )
					&& isset( $all[ $case['name'] ] )
					&& $case['name'] === $ability->get_name()
					&& $case['args']['label'] === $ability->get_label()
					&& $case['args']['description'] === $ability->get_description()
					&& $case['args']['category'] === $ability->get_category()
					&& $case['inputSchema'] === $ability->get_input_schema()
					&& $case['outputSchema'] === $ability->get_output_schema()
					&& false === $ability->get_meta_item( 'missing', false )
					&& isset( $meta['annotations'] )
					&& array_key_exists( 'readonly', $meta['annotations'] )
					&& array_key_exists( 'destructive', $meta['annotations'] )
					&& array_key_exists( 'idempotent', $meta['annotations'] )
					&& is_bool( $meta['show_in_rest'] )
					&& $case['name'] === ( $meta['source']['seen'] ?? null ),
				"ability registration stores prepared ability {$case['name']}",
				array(
					'case'    => self::describe_ability_case( $case ),
					'ability' => self::describe_ability( $ability ),
				)
			);
		}

		$namespace = strtok( $cases[0]['name'], '/' );
		$by_ns     = \wp_get_abilities( array( 'namespace' => $namespace ) );
		$by_cat    = \wp_get_abilities( array( 'category' => $cases[0]['args']['category'] ) );
		$by_meta   = \wp_get_abilities(
			array(
				'meta' => array(
					'annotations' => array(
						'readonly' => true,
					),
				),
			)
		);
		$by_item   = \wp_get_abilities(
			array(
				'item_include_callback' => static fn( \WP_Ability $ability ): bool => $cases[0]['name'] === $ability->get_name(),
			)
		);
		$by_result = \wp_get_abilities(
			array(
				'result_callback' => static function ( array $abilities ) use ( $cases ): array {
					return isset( $abilities[ $cases[1]['name'] ] ) ? array( $cases[1]['name'] => $abilities[ $cases[1]['name'] ] ) : array();
				},
			)
		);

		self::collect_failure(
			$failures,
			isset( $by_ns[ $cases[0]['name'] ] )
				&& self::all_abilities_in_category( $by_cat, $cases[0]['args']['category'] )
				&& self::all_abilities_match_meta( $by_meta, array( 'annotations' => array( 'readonly' => true ) ) )
				&& array( $cases[0]['name'] ) === array_keys( $by_item )
				&& array( $cases[1]['name'] ) === array_keys( $by_result ),
			'wp_get_abilities declarative and callback filters select expected subsets',
			array(
				'namespace'  => $namespace,
				'byNs'       => array_keys( $by_ns ),
				'byCategory' => array_keys( $by_cat ),
				'byMeta'     => array_keys( $by_meta ),
				'byItem'     => array_keys( $by_item ),
				'byResult'   => array_keys( $by_result ),
			)
		);

		$removed = \wp_unregister_ability( $cases[0]['name'] );
		self::collect_failure(
			$failures,
			$removed === $captured['registered'][ $cases[0]['name'] ]
				&& ! \wp_has_ability( $cases[0]['name'] )
				&& null === \wp_get_ability( $cases[0]['name'] ),
			'unregister ability returns exact instance and clears lookups',
			array(
				'name'    => $cases[0]['name'],
				'removed' => self::describe_ability( $removed ),
			)
		);

		self::collect_failure(
			$failures,
			null === $captured['duplicate']
				&& null === $captured['missingCategory']
				&& null === $captured['invalidName']
				&& null === $captured['invalidClass'],
			'ability duplicate, missing category, invalid name, and invalid class registrations fail',
			array( 'captured' => $captured )
		);

		return self::result(
			$ctx,
			'abilities.registry-query-and-unregister',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_category_unregistration_preserves_abilities( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$categories = self::category_cases( $ctx->fork( 'cascade-categories' ) );
		$removed    = $categories[0];
		$kept       = $categories[1];
		$names      = array(
			'removedExisting' => self::ability_name( $ctx->fork( 'removed-existing' ), 'removed-existing' ),
			'keptExisting'    => self::ability_name( $ctx->fork( 'kept-existing' ), 'kept-existing' ),
			'blockedNew'      => self::ability_name( $ctx->fork( 'blocked-new' ), 'blocked-new' ),
			'reopenedNew'     => self::ability_name( $ctx->fork( 'reopened-new' ), 'reopened-new' ),
		);
		$captured   = array(
			'registered'           => array(),
			'blockedAfterRemoval'  => null,
			'reRegisteredCategory' => null,
			'reopenedAbility'      => null,
		);

		self::reset_registries();
		$category_action = self::install_category_action( array( $removed, $kept ) );
		$ability_action  = static function () use ( $removed, $kept, $names, &$captured ): void {
			$captured['registered']['removedExisting'] = \wp_register_ability(
				$names['removedExisting'],
				self::ability_args(
					'Existing ability in the category later removed.',
					$removed['slug'],
					static fn( string $value ): string => 'removed:' . $value,
					static fn(): bool => true,
					array( 'type' => 'string' ),
					array( 'type' => 'string' )
				)
			);
			$captured['registered']['keptExisting']    = \wp_register_ability(
				$names['keptExisting'],
				self::ability_args(
					'Existing ability in the category kept.',
					$kept['slug'],
					static fn( string $value ): string => 'kept:' . $value,
					static fn(): bool => true,
					array( 'type' => 'string' ),
					array( 'type' => 'string' )
				)
			);
		};

		\add_action( 'wp_abilities_api_init', $ability_action );
		\WP_Abilities_Registry::get_instance();
		\remove_action( 'wp_abilities_api_init', $ability_action );
		\remove_action( 'wp_abilities_api_categories_init', $category_action );

		$existing_removed = \wp_get_ability( $names['removedExisting'] );
		$existing_kept    = \wp_get_ability( $names['keptExisting'] );
		$removed_category = \wp_unregister_ability_category( $removed['slug'] );
		$has_removed_category_after_unregister = \wp_has_ability_category( $removed['slug'] );
		$missing_removed_category = \wp_get_ability_category( $removed['slug'] );
		$after_removed    = \wp_get_abilities( array( 'category' => $removed['slug'] ) );
		$kept_after       = \wp_get_abilities( array( 'category' => $kept['slug'] ) );

		$blocked_action = static function () use ( $removed, $names, &$captured ): void {
			$captured['blockedAfterRemoval'] = \wp_register_ability(
				$names['blockedNew'],
				self::ability_args(
					'Ability blocked while category is absent.',
					$removed['slug'],
					static fn( string $value ): string => 'blocked:' . $value,
					static fn(): bool => true,
					array( 'type' => 'string' ),
					array( 'type' => 'string' )
				)
			);
		};
		\add_action( 'wp_abilities_api_init', $blocked_action );
		\do_action( 'wp_abilities_api_init', \WP_Abilities_Registry::get_instance() );
		\remove_action( 'wp_abilities_api_init', $blocked_action );

		$reopen_category_action = static function () use ( $removed, &$captured ): void {
			$captured['reRegisteredCategory'] = \wp_register_ability_category(
				$removed['slug'],
				self::category_args(
					'Reopened ' . $removed['slug'],
					array(
						'reopened' => true,
						'original' => $removed['args']['label'],
					)
				)
			);
		};
		\add_action( 'wp_abilities_api_categories_init', $reopen_category_action );
		\do_action( 'wp_abilities_api_categories_init', \WP_Ability_Categories_Registry::get_instance() );
		\remove_action( 'wp_abilities_api_categories_init', $reopen_category_action );

		$reopened_action = static function () use ( $removed, $names, &$captured ): void {
			$captured['reopenedAbility'] = \wp_register_ability(
				$names['reopenedNew'],
				self::ability_args(
					'Ability allowed after category is re-registered.',
					$removed['slug'],
					static fn( string $value ): string => 'reopened:' . $value,
					static fn(): bool => true,
					array( 'type' => 'string' ),
					array( 'type' => 'string' )
				)
			);
		};
		\add_action( 'wp_abilities_api_init', $reopened_action );
		\do_action( 'wp_abilities_api_init', \WP_Abilities_Registry::get_instance() );
		\remove_action( 'wp_abilities_api_init', $reopened_action );

		$after_reopen = \wp_get_abilities( array( 'category' => $removed['slug'] ) );

		self::collect_failure(
			$failures,
			$existing_removed instanceof \WP_Ability
				&& $existing_kept instanceof \WP_Ability
				&& $removed_category instanceof \WP_Ability_Category
				&& $removed_category->get_slug() === $removed['slug']
				&& ! $has_removed_category_after_unregister
				&& null === $missing_removed_category,
			'category unregister removes only the category registry entry',
			array(
				'removedCategory' => $removed['slug'],
				'removedReturn'   => self::describe_category( $removed_category ),
				'missingLookup'   => self::describe_category( $missing_removed_category ),
				'hasRemoved'      => $has_removed_category_after_unregister,
			)
		);

		self::collect_failure(
			$failures,
			$existing_removed === \wp_get_ability( $names['removedExisting'] )
				&& $existing_kept === \wp_get_ability( $names['keptExisting'] )
				&& array( $names['removedExisting'] ) === array_keys( $after_removed )
				&& array( $names['keptExisting'] ) === array_keys( $kept_after ),
			'category unregister preserves existing abilities and category query membership',
			array(
				'removedCategory' => $removed['slug'],
				'keptCategory'    => $kept['slug'],
				'names'           => $names,
				'afterRemoved'    => array_keys( $after_removed ),
				'keptAfter'       => array_keys( $kept_after ),
			)
		);

		self::collect_failure(
			$failures,
			null === $captured['blockedAfterRemoval']
				&& ! \wp_has_ability( $names['blockedNew'] ),
			'new abilities cannot register into an unregistered category',
			array(
				'blockedReturn' => self::describe_ability( $captured['blockedAfterRemoval'] ),
				'blockedName'   => $names['blockedNew'],
				'hasBlocked'    => \wp_has_ability( $names['blockedNew'] ),
			)
		);

		self::collect_failure(
			$failures,
			$captured['reRegisteredCategory'] instanceof \WP_Ability_Category
				&& \wp_has_ability_category( $removed['slug'] ),
			'removed category can be re-registered during the category init action',
			array(
				'category'    => $removed['slug'],
				'reRegistered' => self::describe_category( $captured['reRegisteredCategory'] ),
				'hasCategory' => \wp_has_ability_category( $removed['slug'] ),
			)
		);

		self::collect_failure(
			$failures,
			$captured['reopenedAbility'] instanceof \WP_Ability
				&& isset( $after_reopen[ $names['removedExisting'] ], $after_reopen[ $names['reopenedNew'] ] )
				&& array( $names['removedExisting'], $names['reopenedNew'] ) === array_keys( $after_reopen ),
			're-registered category accepts new abilities without losing preserved abilities',
			array(
				'removedCategory' => $removed['slug'],
				'reopenedReturn'  => self::describe_ability( $captured['reopenedAbility'] ),
				'afterReopen'     => array_keys( $after_reopen ),
			)
		);

		return self::result(
			$ctx,
			'abilities.category-unregister-preserves-existing-abilities',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_custom_subclass_query_and_reregister( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$custom_class   = self::custom_ability_class();
		$category       = self::category_cases( $ctx->fork( 'custom-subclass-category' ) )[0];
		$other_category = self::category_cases( $ctx->fork( 'custom-subclass-other-category' ) )[0];
		$namespace      = 'cfuzz-sub-' . self::slug_piece( $ctx->fork( 'custom-subclass-namespace' ), 10 );
		$other_namespace = 'cfuzz-sub-' . self::slug_piece( $ctx->fork( 'custom-subclass-other-namespace' ), 10 );
		$group          = 'group-' . self::slug_piece( $ctx->fork( 'custom-subclass-group' ), 10 );
		$other_group    = 'group-' . self::slug_piece( $ctx->fork( 'custom-subclass-other-group' ), 10 );
		$keys           = array( 'keep', 'rescue', 'drop', 'wrong-meta', 'wrong-category', 'wrong-namespace' );
		$names          = array();
		$args           = array();
		$defaults       = array();
		$sources        = array();
		$captured       = array(
			'registered'   => array(),
			'removed'      => null,
			'secondRemove' => null,
			'reregistered' => null,
		);
		$query_log      = array(
			'itemCallback'   => array(),
			'itemFilter'     => array(),
			'resultCallback' => array(),
			'resultFilter'   => array(),
		);

		if ( $other_category['slug'] === $category['slug'] ) {
			$other_category['slug'] .= '-alt';
		}
		if ( $other_namespace === $namespace ) {
			$other_namespace .= '-alt';
		}
		if ( $other_group === $group ) {
			$other_group .= '-alt';
		}

		foreach ( $keys as $key ) {
			$case_ctx          = $ctx->fork( 'custom-subclass-' . $key );
			$case_namespace    = 'wrong-namespace' === $key ? $other_namespace : $namespace;
			$names[ $key ]    = $case_namespace . '/' . self::slug_piece( $case_ctx, 10 ) . '-' . $key;
			$defaults[ $key ] = array(
				'value' => 'default-' . self::slug_piece( $case_ctx->fork( 'default' ), 12 ),
				'count' => $case_ctx->int( 1, 30 ),
			);
			$sources[ $key ]  = 'source-' . self::slug_piece( $case_ctx->fork( 'source' ), 12 );
			$args[ $key ]     = array(
				'label'         => 'Custom subclass ' . $key,
				'description'   => 'Component fuzz custom subclass ability ' . $key . '.',
				'category'      => 'wrong-category' === $key ? $other_category['slug'] : $category['slug'],
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'value' => array( 'type' => 'string' ),
						'count' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
					),
					'additionalProperties' => false,
					'default'              => $defaults[ $key ],
				),
				'output_schema' => array(
					'type'                 => 'object',
					'required'             => array( 'name', 'value', 'count', 'source' ),
					'properties'           => array(
						'name'   => array( 'type' => 'string' ),
						'value'  => array( 'type' => 'string' ),
						'count'  => array( 'type' => 'integer' ),
						'source' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'meta'          => array(
					'annotations'  => array( 'readonly' => true ),
					'show_in_rest' => 'wrong-meta' !== $key,
					'subclass'     => array(
						'group' => 'wrong-meta' === $key ? $other_group : $group,
						'key'   => $key,
					),
					'source'       => $sources[ $key ],
				),
				'ability_class' => $custom_class,
			);
		}

		self::reset_registries();
		$category_action = self::install_category_action( array( $category, $other_category ) );

		$register_action = static function () use ( $keys, $names, $args, &$captured ): void {
			foreach ( $keys as $key ) {
				$captured['registered'][ $key ] = \wp_register_ability( $names[ $key ], $args[ $key ] );
			}

			$captured['removed']      = \wp_unregister_ability( $names['rescue'] );
			$captured['secondRemove'] = \wp_unregister_ability( $names['rescue'] );
			$captured['reregistered'] = \wp_register_ability( $names['rescue'], $args['rescue'] );
		};

		\add_action( 'wp_abilities_api_init', $register_action );
		\WP_Abilities_Registry::get_instance();
		\remove_action( 'wp_abilities_api_init', $register_action );
		\remove_action( 'wp_abilities_api_categories_init', $category_action );

		foreach ( $keys as $key ) {
			$ability = \wp_get_ability( $names[ $key ] );
			$meta    = $ability instanceof \WP_Ability ? $ability->get_meta() : array();
			self::collect_failure(
				$failures,
				$ability instanceof $custom_class
					&& $captured['registered'][ $key ] instanceof $custom_class
					&& $names[ $key ] === $ability->get_name()
					&& $args[ $key ]['input_schema'] === $ability->get_input_schema()
					&& $args[ $key ]['output_schema'] === $ability->get_output_schema()
					&& $args[ $key ]['meta']['source'] === $ability->get_meta_item( 'source' )
					&& true === ( $meta['annotations']['readonly'] ?? null )
					&& array_key_exists( 'destructive', $meta['annotations'] ?? array() )
					&& array_key_exists( 'idempotent', $meta['annotations'] ?? array() )
					&& null === $meta['annotations']['destructive']
					&& null === $meta['annotations']['idempotent']
					&& is_bool( $meta['show_in_rest'] ?? null ),
				"custom ability subclass registration prepares metadata and schemas for {$key}",
				array(
					'key'     => $key,
					'ability' => self::describe_ability( $ability ),
				)
			);
		}

		self::collect_failure(
			$failures,
			$captured['removed'] === $captured['registered']['rescue']
				&& null === $captured['secondRemove']
				&& $captured['reregistered'] instanceof $custom_class
				&& $captured['reregistered'] !== $captured['removed']
				&& \wp_get_ability( $names['rescue'] ) === $captured['reregistered']
				&& \wp_has_ability( $names['rescue'] ),
			'unregistering and re-registering a custom subclass swaps identity cleanly',
			array(
				'removed'      => self::describe_ability( $captured['removed'] ),
				'secondRemove' => self::describe_value( $captured['secondRemove'] ),
				'reregistered' => self::describe_ability( $captured['reregistered'] ),
			)
		);

		$item_callback = static function ( \WP_Ability $ability ) use ( $names, &$query_log ): bool {
			$query_log['itemCallback'][] = $ability->get_name();
			return ! in_array( $ability->get_name(), array( $names['rescue'], $names['drop'] ), true );
		};
		$item_filter   = static function ( bool $include, \WP_Ability $ability, array $query_args ) use ( $names, &$query_log ): bool {
			$query_log['itemFilter'][] = array(
				'name'    => $ability->get_name(),
				'include' => $include,
				'args'    => array_keys( $query_args ),
			);
			if ( $names['rescue'] === $ability->get_name() ) {
				return true;
			}
			if ( $names['drop'] === $ability->get_name() ) {
				return false;
			}
			return $include;
		};
		$result_callback = static function ( array $abilities ) use ( &$query_log ): array {
			$query_log['resultCallback'][] = array_keys( $abilities );
			return array_reverse( $abilities, true );
		};
		$result_filter   = static function ( array $abilities, array $query_args ) use ( &$query_log ): array {
			$query_log['resultFilter'][] = array(
				'names' => array_keys( $abilities ),
				'args'  => array_keys( $query_args ),
			);
			return $abilities;
		};
		$query_args      = array(
			'category'              => $category['slug'],
			'namespace'             => $namespace . '/',
			'meta'                  => array(
				'show_in_rest' => true,
				'subclass'     => array(
					'group' => $group,
				),
			),
			'item_include_callback' => $item_callback,
			'result_callback'       => $result_callback,
		);

		\add_filter( 'wp_get_abilities_item_include', $item_filter, 10, 3 );
		\add_filter( 'wp_get_abilities_result', $result_filter, 10, 2 );
		try {
			$queried = \wp_get_abilities( $query_args );
		} finally {
			\remove_filter( 'wp_get_abilities_item_include', $item_filter, 10 );
			\remove_filter( 'wp_get_abilities_result', $result_filter, 10 );
		}

		self::collect_failure(
			$failures,
			array( $names['rescue'], $names['keep'] ) === array_keys( $queried )
				&& self::all_abilities_instance_of( $queried, $custom_class )
				&& array( $names['keep'], $names['drop'], $names['rescue'] ) === $query_log['itemCallback']
				&& array(
					array(
						'name'    => $names['keep'],
						'include' => true,
						'args'    => array_keys( $query_args ),
					),
					array(
						'name'    => $names['drop'],
						'include' => false,
						'args'    => array_keys( $query_args ),
					),
					array(
						'name'    => $names['rescue'],
						'include' => false,
						'args'    => array_keys( $query_args ),
					),
				) === $query_log['itemFilter']
				&& array( array( $names['keep'], $names['rescue'] ) ) === $query_log['resultCallback']
				&& array(
					array(
						'names' => array( $names['rescue'], $names['keep'] ),
						'args'  => array_keys( $query_args ),
					),
				) === $query_log['resultFilter'],
			'custom subclass abilities honor declarative query filters before callback and global filter ordering',
			array(
				'resultKeys' => array_keys( $queried ),
				'queryLog'  => $query_log,
				'names'     => $names,
			)
		);

		$custom_class::reset_logs();
		$rescue           = \wp_get_ability( $names['rescue'] );
		$execution_result = $rescue instanceof \WP_Ability ? $rescue->execute() : null;
		$execution_logs   = $custom_class::get_logs();
		$expected_output  = array(
			'name'   => $names['rescue'],
			'value'  => $defaults['rescue']['value'],
			'count'  => $defaults['rescue']['count'],
			'source' => $sources['rescue'],
		);
		$expected_log     = array(
			array(
				'name'  => $names['rescue'],
				'input' => $defaults['rescue'],
			),
		);

		self::collect_failure(
			$failures,
			$expected_output === $execution_result
				&& $expected_log === $execution_logs['permission']
				&& $expected_log === $execution_logs['execute'],
			'custom subclass execution uses schema defaults and overridden permission/execute methods without callbacks',
			array(
				'expected' => $expected_output,
				'actual'   => self::describe_value( $execution_result ),
				'logs'     => $execution_logs,
			)
		);

		return self::result(
			$ctx,
			'abilities.custom-subclass-query-and-reregister',
			array() === $failures,
			array(
				'cases'    => count( $keys ),
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_execution_pipeline( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$category = self::category_cases( $ctx->fork( 'execute-category' ) )[0];
		$input    = 'payload-' . self::slug_piece( $ctx->fork( 'execute-input' ), 10 );
		$default  = 'default-' . self::slug_piece( $ctx->fork( 'execute-default' ), 10 );
		$token    = 'token-' . self::slug_piece( $ctx->fork( 'execute-token' ), 8 );
		$calls    = array(
			'invoked'          => array(),
			'before'           => array(),
			'after'            => array(),
			'executeCallbacks' => array(),
			'permissions'      => array(),
			'inputs'           => array(),
		);
		$names    = array(
			'echo'        => self::ability_name( $ctx->fork( 'execute-echo' ), 'echo' ),
			'deny'        => self::ability_name( $ctx->fork( 'execute-deny' ), 'deny' ),
			'bad-output'  => self::ability_name( $ctx->fork( 'execute-bad-output' ), 'bad-output' ),
			'no-schema'   => self::ability_name( $ctx->fork( 'execute-no-schema' ), 'no-schema' ),
			'short'       => self::ability_name( $ctx->fork( 'execute-short' ), 'short' ),
			'custom'      => self::ability_name( $ctx->fork( 'execute-custom' ), 'custom' ),
		);

		self::reset_registries();
		$category_action = self::install_category_action( array( $category ) );

		$register_action = static function () use ( $category, $names, $default, &$calls ): void {
			\wp_register_ability(
				$names['echo'],
				self::ability_args(
					'Echo string.',
					$category['slug'],
					static function ( string $value ) use ( &$calls ): string {
						$calls['executeCallbacks'][] = 'echo';
						$calls['inputs'][] = $value;
						return 'out:' . $value;
					},
					static function ( string $value ) use ( &$calls ): bool {
						$calls['permissions'][] = 'echo';
						$calls['inputs'][] = 'perm:' . $value;
						return true;
					},
					array(
						'type'    => 'string',
						'default' => $default,
					),
					array(
						'type' => 'string',
					),
					array(
						'annotations'  => array( 'readonly' => true ),
						'show_in_rest' => true,
					)
				)
			);

			\wp_register_ability(
				$names['deny'],
				self::ability_args(
					'Deny string.',
					$category['slug'],
					static function ( string $value ) use ( &$calls ): string {
						$calls['executeCallbacks'][] = 'deny';
						return 'denied:' . $value;
					},
					static function ( string $value ) use ( &$calls ): bool {
						$calls['permissions'][] = 'deny';
						return false;
					},
					array( 'type' => 'string' ),
					array( 'type' => 'string' )
				)
			);

			\wp_register_ability(
				$names['bad-output'],
				self::ability_args(
					'Bad output.',
					$category['slug'],
					static function ( string $value ) use ( &$calls ): string {
						$calls['executeCallbacks'][] = 'bad-output';
						return 'not-an-integer-' . $value;
					},
					static function () use ( &$calls ): bool {
						$calls['permissions'][] = 'bad-output';
						return true;
					},
					array( 'type' => 'string' ),
					array( 'type' => 'integer' )
				)
			);

			\wp_register_ability(
				$names['no-schema'],
				self::ability_args(
					'No schema.',
					$category['slug'],
					static function () use ( &$calls ): string {
						$calls['executeCallbacks'][] = 'no-schema';
						return 'no-args';
					},
					static function () use ( &$calls ): bool {
						$calls['permissions'][] = 'no-schema';
						return true;
					}
				)
			);

			\wp_register_ability(
				$names['short'],
				self::ability_args(
					'Short circuit.',
					$category['slug'],
					static function () use ( &$calls ): string {
						$calls['executeCallbacks'][] = 'short';
						return 'should-not-run';
					},
					static function () use ( &$calls ): bool {
						$calls['permissions'][] = 'short';
						return true;
					}
				)
			);

			\wp_register_ability(
				$names['custom'],
				self::ability_args(
					'Custom ability class.',
					$category['slug'],
					static function () use ( &$calls ): string {
						$calls['executeCallbacks'][] = 'custom';
						return 'custom';
					},
					static function () use ( &$calls ): bool {
						$calls['permissions'][] = 'custom';
						return true;
					},
					array(),
					array(),
					array(),
					\WP_Ability::class
				)
			);
		};

		\add_action( 'wp_abilities_api_init', $register_action );
		\WP_Abilities_Registry::get_instance();
		\remove_action( 'wp_abilities_api_init', $register_action );
		\remove_action( 'wp_abilities_api_categories_init', $category_action );

		$invoked = static function ( string $name ) use ( $names, &$calls ): void {
			$key = array_search( $name, $names, true );
			if ( false !== $key ) {
				$calls['invoked'][] = $key;
			}
		};
		$before  = static function ( string $name ) use ( $names, &$calls ): void {
			$key = array_search( $name, $names, true );
			if ( false !== $key ) {
				$calls['before'][] = $key;
			}
		};
		$after   = static function ( string $name ) use ( $names, &$calls ): void {
			$key = array_search( $name, $names, true );
			if ( false !== $key ) {
				$calls['after'][] = $key;
			}
		};
		$normalizer = static function ( $value, string $name ) use ( $names, $token ) {
			if ( $names['echo'] === $name ) {
				return $value . ':' . $token;
			}
			return $value;
		};
		$permission_filter = static function ( $permission, string $name ) use ( $names ) {
			if ( $names['deny'] === $name ) {
				return new \WP_Error( 'component_fuzz_permission', 'Denied by fuzzer.' );
			}
			return $permission;
		};
		$execute_filter    = static function ( $result, string $name ) use ( $names ) {
			if ( $names['echo'] === $name && is_string( $result ) ) {
				return $result . ':filtered';
			}
			return $result;
		};
		$pre_execute       = static function ( $pre, string $name ) use ( $names ) {
			if ( $names['short'] === $name ) {
				return 'short-circuit-value';
			}
			return $pre;
		};

		\add_action( 'wp_ability_invoked', $invoked, 10, 3 );
		\add_action( 'wp_before_execute_ability', $before, 10, 3 );
		\add_action( 'wp_after_execute_ability', $after, 10, 4 );
		\add_filter( 'wp_ability_normalize_input', $normalizer, 10, 3 );
		\add_filter( 'wp_ability_permission_result', $permission_filter, 10, 4 );
		\add_filter( 'wp_ability_execute_result', $execute_filter, 10, 4 );
		\add_filter( 'wp_pre_execute_ability', $pre_execute, 10, 4 );

		$echo        = \wp_get_ability( $names['echo'] );
		$deny        = \wp_get_ability( $names['deny'] );
		$bad_output  = \wp_get_ability( $names['bad-output'] );
		$no_schema   = \wp_get_ability( $names['no-schema'] );
		$short       = \wp_get_ability( $names['short'] );
		$custom      = \wp_get_ability( $names['custom'] );
		$normalized  = $echo instanceof \WP_Ability ? $echo->normalize_input() : null;
		$valid_input = $echo instanceof \WP_Ability ? $echo->validate_input( $input ) : null;
		$bad_input   = $echo instanceof \WP_Ability ? $echo->validate_input( array( 'not' => 'string' ) ) : null;
		$echo_result = $echo instanceof \WP_Ability ? $echo->execute( $input ) : null;
		$deny_result = $deny instanceof \WP_Ability ? $deny->execute( $input ) : null;
		$bad_result  = $bad_output instanceof \WP_Ability ? $bad_output->execute( $input ) : null;
		$missing_schema_validation = $no_schema instanceof \WP_Ability ? $no_schema->validate_input( $input ) : null;
		$no_schema_result          = $no_schema instanceof \WP_Ability ? $no_schema->execute() : null;
		$short_result              = $short instanceof \WP_Ability ? $short->execute( $input ) : null;
		$custom_result             = $custom instanceof \WP_Ability ? $custom->execute() : null;

		\remove_action( 'wp_ability_invoked', $invoked, 10 );
		\remove_action( 'wp_before_execute_ability', $before, 10 );
		\remove_action( 'wp_after_execute_ability', $after, 10 );
		\remove_filter( 'wp_ability_normalize_input', $normalizer, 10 );
		\remove_filter( 'wp_ability_permission_result', $permission_filter, 10 );
		\remove_filter( 'wp_ability_execute_result', $execute_filter, 10 );
		\remove_filter( 'wp_pre_execute_ability', $pre_execute, 10 );

		self::collect_failure(
			$failures,
			$default . ':' . $token === $normalized
				&& true === $valid_input
				&& self::is_error_code( $bad_input, 'ability_invalid_input' )
				&& 'out:' . $input . ':' . $token . ':filtered' === $echo_result
				&& self::is_error_code( $deny_result, 'ability_invalid_permissions' )
				&& self::is_error_code( $bad_result, 'ability_invalid_output' )
				&& self::is_error_code( $missing_schema_validation, 'ability_missing_input_schema' )
				&& 'no-args' === $no_schema_result
				&& 'short-circuit-value' === $short_result
				&& 'custom' === $custom_result
				&& $custom instanceof \WP_Ability,
			'ability execution normalizes, validates, checks permission, filters, and validates output',
			array(
				'normalized'              => $normalized,
				'validInput'              => self::describe_value( $valid_input ),
				'badInput'                => self::describe_error( $bad_input ),
				'echoResult'              => self::describe_value( $echo_result ),
				'denyResult'              => self::describe_error( $deny_result ),
				'badOutputResult'         => self::describe_error( $bad_result ),
				'missingSchemaValidation' => self::describe_error( $missing_schema_validation ),
				'noSchemaResult'          => self::describe_value( $no_schema_result ),
				'shortResult'             => self::describe_value( $short_result ),
				'customResult'            => self::describe_value( $custom_result ),
				'calls'                   => $calls,
			)
		);

		self::collect_failure(
			$failures,
			array( 'echo', 'deny', 'bad-output', 'no-schema', 'short', 'custom' ) === $calls['invoked']
				&& array( 'echo', 'bad-output', 'no-schema', 'custom' ) === $calls['before']
				&& array( 'echo', 'no-schema', 'custom' ) === $calls['after']
				&& array( 'echo', 'bad-output', 'no-schema', 'custom' ) === $calls['executeCallbacks']
				&& array( 'echo', 'deny', 'bad-output', 'no-schema', 'custom' ) === $calls['permissions']
				&& in_array( $input . ':' . $token, $calls['inputs'], true ),
			'ability execution actions fire only around non-short-circuited valid execution paths',
			array( 'calls' => $calls )
		);

		return self::result(
			$ctx,
			'abilities.execution-pipeline',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_validation_filter_and_exception_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$category = self::category_cases( $ctx->fork( 'filter-category' ) )[0];
		$input    = 'filter-input-' . self::slug_piece( $ctx->fork( 'filter-input' ), 10 );
		$names    = array(
			'success'          => self::ability_name( $ctx->fork( 'filter-success' ), 'success' ),
			'normalize-error'  => self::ability_name( $ctx->fork( 'filter-normalize-error' ), 'normalize-error' ),
			'input-false'      => self::ability_name( $ctx->fork( 'filter-input-false' ), 'input-false' ),
			'input-error'      => self::ability_name( $ctx->fork( 'filter-input-error' ), 'input-error' ),
			'output-false'     => self::ability_name( $ctx->fork( 'filter-output-false' ), 'output-false' ),
			'output-error'     => self::ability_name( $ctx->fork( 'filter-output-error' ), 'output-error' ),
			'permission-throw' => self::ability_name( $ctx->fork( 'filter-permission-throw' ), 'permission-throw' ),
			'execute-throw'    => self::ability_name( $ctx->fork( 'filter-execute-throw' ), 'execute-throw' ),
		);
		$calls    = array(
			'permission' => array(),
			'execute'    => array(),
			'after'      => array(),
			'normalize'  => array(),
			'input'      => array(),
			'output'     => array(),
		);

		self::reset_registries();
		$category_action = self::install_category_action( array( $category ) );

		$register_action = static function () use ( $category, $names, &$calls ): void {
			foreach ( $names as $key => $name ) {
				\wp_register_ability(
					$name,
					self::ability_args(
						'Filter and exception matrix ' . $key,
						$category['slug'],
						static function ( string $value ) use ( $key, &$calls ): string {
							$calls['execute'][] = $key;
							if ( 'execute-throw' === $key ) {
								throw new \RuntimeException( 'component fuzz execute exception' );
							}
							return 'ok:' . $value;
						},
						static function ( string $value ) use ( $key, &$calls ): bool {
							$calls['permission'][] = $key . ':' . $value;
							if ( 'permission-throw' === $key ) {
								throw new \RuntimeException( 'component fuzz permission exception' );
							}
							return true;
						},
						array( 'type' => 'string' ),
						array( 'type' => 'string' )
					)
				);
			}
		};

		\add_action( 'wp_abilities_api_init', $register_action );
		\WP_Abilities_Registry::get_instance();
		\remove_action( 'wp_abilities_api_init', $register_action );
		\remove_action( 'wp_abilities_api_categories_init', $category_action );

		$normalizer = static function ( $value, string $name ) use ( $names, &$calls ) {
			$key = array_search( $name, $names, true );
			if ( false !== $key ) {
				$calls['normalize'][ $key ] = $value;
			}
			if ( $names['normalize-error'] === $name ) {
				return new \WP_Error( 'component_fuzz_normalize', 'Normalize filter stopped execution.' );
			}
			return $value;
		};
		$input_filter = static function ( $validity, $value, string $name ) use ( $names, &$calls ) {
			$key = array_search( $name, $names, true );
			if ( false !== $key ) {
				$calls['input'][ $key ] = $value;
			}
			if ( $names['input-false'] === $name ) {
				return false;
			}
			if ( $names['input-error'] === $name ) {
				return new \WP_Error( 'component_fuzz_input_gate', 'Input filter stopped execution.' );
			}
			return $validity;
		};
		$output_filter = static function ( $validity, $value, string $name ) use ( $names, &$calls ) {
			$key = array_search( $name, $names, true );
			if ( false !== $key ) {
				$calls['output'][ $key ] = $value;
			}
			if ( $names['output-false'] === $name ) {
				return false;
			}
			if ( $names['output-error'] === $name ) {
				return new \WP_Error( 'component_fuzz_output_gate', 'Output filter stopped execution.' );
			}
			return $validity;
		};
		$after = static function ( string $name ) use ( $names, &$calls ): void {
			$key = array_search( $name, $names, true );
			if ( false !== $key ) {
				$calls['after'][] = $key;
			}
		};

		\add_filter( 'wp_ability_normalize_input', $normalizer, 10, 3 );
		\add_filter( 'wp_ability_validate_input', $input_filter, 10, 3 );
		\add_filter( 'wp_ability_validate_output', $output_filter, 10, 3 );
		\add_action( 'wp_after_execute_ability', $after, 10, 4 );

		try {
			$results = array();
			foreach ( $names as $key => $name ) {
				$ability          = \wp_get_ability( $name );
				$results[ $key ] = $ability instanceof \WP_Ability ? $ability->execute( $input ) : null;
			}
		} finally {
			\remove_filter( 'wp_ability_normalize_input', $normalizer, 10 );
			\remove_filter( 'wp_ability_validate_input', $input_filter, 10 );
			\remove_filter( 'wp_ability_validate_output', $output_filter, 10 );
			\remove_action( 'wp_after_execute_ability', $after, 10 );
		}

		self::collect_failure(
			$failures,
			'ok:' . $input === $results['success']
				&& self::is_error_code( $results['normalize-error'], 'component_fuzz_normalize' )
				&& self::is_error_code( $results['input-false'], 'ability_invalid_input' )
				&& self::is_error_code( $results['input-error'], 'component_fuzz_input_gate' )
				&& self::is_error_code( $results['output-false'], 'ability_invalid_output' )
				&& self::is_error_code( $results['output-error'], 'component_fuzz_output_gate' )
				&& self::is_error_code( $results['permission-throw'], 'ability_invalid_permissions' )
				&& self::is_error_code( $results['execute-throw'], 'ability_callback_exception' ),
			'ability validation filters and callback exceptions return stable error codes',
			array(
				'results' => array_map( array( __CLASS__, 'describe_error' ), $results ),
				'calls'   => $calls,
			)
		);

		self::collect_failure(
			$failures,
			array( 'success:' . $input, 'output-false:' . $input, 'output-error:' . $input, 'permission-throw:' . $input, 'execute-throw:' . $input ) === $calls['permission']
				&& array( 'success', 'output-false', 'output-error', 'execute-throw' ) === $calls['execute']
				&& array( 'success' ) === $calls['after']
				&& ! array_key_exists( 'normalize-error', $calls['input'] )
				&& ! in_array( 'normalize-error:' . $input, $calls['permission'], true )
				&& ! in_array( 'input-false:' . $input, $calls['permission'], true )
				&& ! in_array( 'input-error:' . $input, $calls['permission'], true ),
			'ability execution stops at the earliest failing pipeline stage',
			array( 'calls' => $calls )
		);

		return self::result(
			$ctx,
			'abilities.validation-filters-and-exception-paths',
			array() === $failures,
			array(
				'cases'    => count( $names ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_invalid_registration_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$category = self::category_cases( $ctx->fork( 'invalid-category' ) )[0];
		$base     = self::ability_name( $ctx->fork( 'invalid-ability' ), 'invalid' );
		$captures = array();

		self::reset_registries();
		$category_action = self::install_category_action( array( $category ) );

		$action = static function () use ( $category, $base, &$captures ): void {
			$valid = self::ability_args(
				'Valid baseline.',
				$category['slug'],
				static fn( string $value ): string => $value,
				static fn(): bool => true,
				array( 'type' => 'string' ),
				array( 'type' => 'string' )
			);

			$mutations = array(
				'missing-label'       => array_diff_key( $valid, array( 'label' => true ) ),
				'empty-description'   => array_merge( $valid, array( 'description' => '' ) ),
				'bad-execute'         => array_merge( $valid, array( 'execute_callback' => 'not_a_function' ) ),
				'bad-permission'      => array_merge( $valid, array( 'permission_callback' => 'not_a_function' ) ),
				'bad-input-schema'    => array_merge( $valid, array( 'input_schema' => 'string' ) ),
				'bad-output-schema'   => array_merge( $valid, array( 'output_schema' => 'string' ) ),
				'bad-meta'            => array_merge( $valid, array( 'meta' => 'string' ) ),
				'bad-annotations'     => array_merge( $valid, array( 'meta' => array( 'annotations' => 'string' ) ) ),
				'bad-show-in-rest'    => array_merge( $valid, array( 'meta' => array( 'show_in_rest' => 'yes' ) ) ),
			);

			foreach ( $mutations as $label => $args ) {
				$name               = $base . '-' . str_replace( '_', '-', $label );
				$captures[ $label ] = \wp_register_ability( $name, $args );
			}
		};

		\add_action( 'wp_abilities_api_init', $action );
		\WP_Abilities_Registry::get_instance();
		\remove_action( 'wp_abilities_api_init', $action );
		\remove_action( 'wp_abilities_api_categories_init', $category_action );

		foreach ( $captures as $label => $value ) {
			self::collect_failure(
				$failures,
				null === $value,
				"invalid ability args are rejected for {$label}",
				array(
					'label' => $label,
					'value' => self::describe_value( $value ),
				)
			);
		}

		return self::result(
			$ctx,
			'abilities.invalid-registration-paths',
			array() === $failures,
			array(
				'cases'    => count( $captures ),
				'failures' => array_slice( $failures, 0, 9 ),
			)
		);
	}

	private static function category_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CATEGORY_CASES; $i++ ) {
			$case_ctx = $ctx->fork( 'category-' . $i );
			$slug     = self::category_slug( $case_ctx, 'cat' . $i );
			$cases[]  = array(
				'slug' => $slug,
				'args' => self::category_args(
					'Category ' . $i . ' ' . $case_ctx->choice( array( 'content', 'media', 'data', 'settings' ) ),
					array(
						'ordinal' => $i,
						'tag'     => self::slug_piece( $case_ctx, 12 ),
					)
				),
			);
		}
		return $cases;
	}

	private static function ability_cases( \ComponentFuzz\FuzzContext $ctx, array $categories ): array {
		$cases = array();
		for ( $i = 0; $i < self::ABILITY_CASES; $i++ ) {
			$case_ctx      = $ctx->fork( 'ability-' . $i );
			$category      = $categories[ $i % count( $categories ) ];
			$name          = self::ability_name( $case_ctx, 'ability-' . $i );
			$default       = 'value-' . self::slug_piece( $case_ctx->fork( 'default' ), 10 );
			$input_schema  = 0 === $i % 3
				? array(
					'type'    => 'string',
					'default' => $default,
				)
				: array(
					'type'       => 'object',
					'required'   => array( 'text', 'count' ),
					'properties' => array(
						'text'  => array( 'type' => 'string' ),
						'count' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
					),
				);
			$output_schema = 0 === $i % 3
				? array( 'type' => 'string' )
				: array(
					'type'       => 'object',
					'required'   => array( 'ok', 'name' ),
					'properties' => array(
						'ok'   => array( 'type' => 'boolean' ),
						'name' => array( 'type' => 'string' ),
					),
				);
			$meta          = array(
				'annotations'  => array(
					'readonly'   => 0 === $i % 2,
					'idempotent' => 0 === $i % 3 ? true : null,
				),
				'show_in_rest' => 0 === $i % 2,
				'nested'       => array(
					'group' => $category['slug'],
					'index' => $i,
				),
			);

			$cases[] = array(
				'name'         => $name,
				'inputSchema'  => $input_schema,
				'outputSchema' => $output_schema,
				'args'         => self::ability_args(
					'Ability ' . $i . ' ' . $case_ctx->choice( array( 'summarize', 'transform', 'inspect', 'route' ) ),
					$category['slug'],
					static function ( $input ) use ( $i, $name ) {
						if ( is_array( $input ) ) {
							return array(
								'ok'   => true,
								'name' => $name,
							);
						}
						return (string) $input . ':' . $i;
					},
					static fn(): bool => true,
					$input_schema,
					$output_schema,
					$meta
				),
			);
		}
		return $cases;
	}

	private static function category_args( string $label, array $meta = array() ): array {
		return array(
			'label'       => $label,
			'description' => 'Component fuzz category for ' . $label . '.',
			'meta'        => $meta,
		);
	}

	private static function ability_args(
		string $label,
		string $category,
		callable $execute_callback,
		callable $permission_callback,
		array $input_schema = array(),
		array $output_schema = array(),
		array $meta = array(),
		?string $ability_class = null
	): array {
		$args = array(
			'label'               => $label,
			'description'         => 'Component fuzz ability for ' . $label,
			'category'            => $category,
			'execute_callback'    => $execute_callback,
			'permission_callback' => $permission_callback,
		);

		if ( array() !== $input_schema ) {
			$args['input_schema'] = $input_schema;
		}
		if ( array() !== $output_schema ) {
			$args['output_schema'] = $output_schema;
		}
		if ( array() !== $meta ) {
			$args['meta'] = $meta;
		}
		if ( null !== $ability_class ) {
			$args['ability_class'] = $ability_class;
		}

		return $args;
	}

	private static function install_category_action( array $categories ): callable {
		$action = static function () use ( $categories ): void {
			foreach ( $categories as $category ) {
				\wp_register_ability_category( $category['slug'], $category['args'] );
			}
		};
		\add_action( 'wp_abilities_api_categories_init', $action );
		return $action;
	}

	private static function category_slug( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return self::slug_piece( $ctx, 8 ) . '-' . self::slug_piece( $ctx->fork( $label ), 8 );
	}

	private static function ability_name( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'cfuzz-' . self::slug_piece( $ctx, 10 ) . '/' . self::slug_piece( $ctx->fork( $label ), 16 );
	}

	private static function slug_piece( \ComponentFuzz\FuzzContext $ctx, int $max ): string {
		$raw = strtolower( $ctx->identifier( 3, $max ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$raw = preg_replace( '/[^a-z0-9]+/', '-', $raw );
		$raw = trim( (string) $raw, '-' );
		$raw = preg_replace( '/-+/', '-', $raw );
		$raw = trim( substr( $raw, 0, $max ), '-' );
		return '' === $raw ? 'fuzz' . dechex( $ctx->seed() & 0xffff ) : $raw;
	}

	private static function ensure_init_fired(): void {
		if ( ! isset( $GLOBALS['wp_actions'] ) || ! is_array( $GLOBALS['wp_actions'] ) ) {
			$GLOBALS['wp_actions'] = array();
		}
		$GLOBALS['wp_actions']['init'] = max( 1, (int) ( $GLOBALS['wp_actions']['init'] ?? 0 ) );
	}

	private static function reset_registries(): void {
		self::set_static_property( 'WP_Abilities_Registry', 'instance', null );
		self::set_static_property( 'WP_Ability_Categories_Registry', 'instance', null );
	}

	private static function snapshot_state(): array {
		$abilities  = self::get_static_property( 'WP_Abilities_Registry', 'instance' );
		$categories = self::get_static_property( 'WP_Ability_Categories_Registry', 'instance' );

		return array(
			'globals'            => self::snapshot_globals( array( 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter' ) ),
			'abilities'          => $abilities,
			'registeredAbilities' => $abilities instanceof \WP_Abilities_Registry ? self::get_object_property( $abilities, 'registered_abilities' ) : null,
			'categories'         => $categories,
			'registeredCategories' => $categories instanceof \WP_Ability_Categories_Registry ? self::get_object_property( $categories, 'registered_categories' ) : null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );

		if ( $snapshot['abilities'] instanceof \WP_Abilities_Registry ) {
			self::set_object_property( $snapshot['abilities'], 'registered_abilities', $snapshot['registeredAbilities'] );
			self::set_static_property( 'WP_Abilities_Registry', 'instance', $snapshot['abilities'] );
		} else {
			self::set_static_property( 'WP_Abilities_Registry', 'instance', null );
		}

		if ( $snapshot['categories'] instanceof \WP_Ability_Categories_Registry ) {
			self::set_object_property( $snapshot['categories'], 'registered_categories', $snapshot['registeredCategories'] );
			self::set_static_property( 'WP_Ability_Categories_Registry', 'instance', $snapshot['categories'] );
		} else {
			self::set_static_property( 'WP_Ability_Categories_Registry', 'instance', null );
		}
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
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

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$reflection = new \ReflectionProperty( $class, $property );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}
		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
	}

	private static function get_object_property( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		return $reflection->getValue( $object );
	}

	private static function set_object_property( object $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setValue( $object, $value );
	}

	private static function all_abilities_in_category( array $abilities, string $category ): bool {
		if ( array() === $abilities ) {
			return false;
		}
		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof \WP_Ability || $category !== $ability->get_category() ) {
				return false;
			}
		}
		return true;
	}

	private static function all_abilities_match_meta( array $abilities, array $meta ): bool {
		if ( array() === $abilities ) {
			return false;
		}
		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof \WP_Ability || ! self::array_contains( $ability->get_meta(), $meta ) ) {
				return false;
			}
		}
		return true;
	}

	private static function all_abilities_instance_of( array $abilities, string $class ): bool {
		if ( array() === $abilities ) {
			return false;
		}
		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof $class ) {
				return false;
			}
		}
		return true;
	}

	private static function array_contains( array $haystack, array $needle ): bool {
		foreach ( $needle as $key => $value ) {
			if ( ! array_key_exists( $key, $haystack ) ) {
				return false;
			}
			if ( is_array( $value ) ) {
				if ( ! is_array( $haystack[ $key ] ) || ! self::array_contains( $haystack[ $key ], $value ) ) {
					return false;
				}
			} elseif ( $haystack[ $key ] !== $value ) {
				return false;
			}
		}
		return true;
	}

	private static function custom_ability_class(): string {
		static $custom_ability_class = null;

		if ( null === $custom_ability_class ) {
			$probe                = new class(
				'component-fuzz/probe',
				array(
					'label'       => 'Component Fuzz Probe',
					'description' => 'Probe for the component fuzz custom ability class.',
					'category'    => 'component-fuzz',
				)
			) extends \WP_Ability {
				private static $permission_log = array();
				private static $execute_log    = array();

				public static function reset_logs(): void {
					self::$permission_log = array();
					self::$execute_log    = array();
				}

				public static function get_logs(): array {
					return array(
						'permission' => self::$permission_log,
						'execute'    => self::$execute_log,
					);
				}

				public function check_permissions( $input = null ) {
					self::$permission_log[] = array(
						'name'  => $this->name,
						'input' => $input,
					);
					return true;
				}

				protected function do_execute( $input = null ) {
					self::$execute_log[] = array(
						'name'  => $this->name,
						'input' => $input,
					);

					$input  = is_array( $input ) ? $input : array();
					$source = $this->get_meta_item( 'source', '' );

					return array(
						'name'   => $this->name,
						'value'  => (string) ( $input['value'] ?? '' ),
						'count'  => (int) ( $input['count'] ?? 0 ),
						'source' => is_string( $source ) ? $source : '',
					);
				}
			};
			$custom_ability_class = get_class( $probe );
		}

		return $custom_ability_class;
	}

	private static function is_error_code( $value, string $code ): bool {
		return \is_wp_error( $value ) && $code === $value->get_error_code();
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function describe_ability_case( array $case ): array {
		return array(
			'name'         => $case['name'],
			'category'     => $case['args']['category'],
			'inputSchema'  => $case['inputSchema'],
			'outputSchema' => $case['outputSchema'],
		);
	}

	private static function describe_ability( $ability ): array {
		if ( ! $ability instanceof \WP_Ability ) {
			return array( 'value' => self::describe_value( $ability ) );
		}
		return array(
			'class'       => get_class( $ability ),
			'name'        => $ability->get_name(),
			'label'       => $ability->get_label(),
			'category'    => $ability->get_category(),
			'meta'        => $ability->get_meta(),
			'inputSchema' => $ability->get_input_schema(),
		);
	}

	private static function describe_category( $category ): array {
		if ( ! $category instanceof \WP_Ability_Category ) {
			return array( 'value' => self::describe_value( $category ) );
		}
		return array(
			'slug'        => $category->get_slug(),
			'label'       => $category->get_label(),
			'description' => $category->get_description(),
			'meta'        => $category->get_meta(),
		);
	}

	private static function describe_error( $value ): array {
		if ( ! \is_wp_error( $value ) ) {
			return array( 'value' => self::describe_value( $value ) );
		}
		return array(
			'code'    => $value->get_error_code(),
			'message' => $value->get_error_message(),
			'data'    => $value->get_error_data(),
		);
	}

	private static function describe_value( $value ) {
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}
		if ( is_array( $value ) ) {
			return $value;
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
