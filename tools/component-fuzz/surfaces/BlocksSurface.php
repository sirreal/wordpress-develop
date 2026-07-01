<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes in-memory WordPress block API registries and support helpers.
 */
final class BlocksSurface {
	public const NAME = 'blocks';

	private const CASES = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'blocks.bootstrap-apis-available',
					'Required WordPress block APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		try {
			return array(
				self::check_block_type_registry( $ctx ),
				self::check_parser_detection_and_rendering( $ctx ),
				self::check_nested_attribute_round_trips( $ctx ),
				self::check_style_pattern_binding_registries( $ctx ),
				self::check_block_bindings_render_pipeline( $ctx ),
				self::check_builtin_block_binding_sources( $ctx->fork( 'builtin-block-bindings' ) ),
				self::check_metadata_and_pattern_categories( $ctx ),
				self::check_block_hooks_insertion_and_metadata( $ctx ),
				self::check_block_hooks_post_object_and_rest_response( $ctx->fork( 'block-hooks-post-object' ) ),
				self::check_block_supports( $ctx ),
			);
		} catch ( \Throwable $e ) {
			return array(
				$ctx->fail(
					'blocks.surface-no-throw',
					array(
						'throwable' => self::describe_throwable( $e ),
					)
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
				'WP_Block_Bindings_Registry',
				'WP_Block_Bindings_Source',
				'WP_Block_Pattern_Categories_Registry',
				'WP_Block_Patterns_Registry',
				'WP_Block_Styles_Registry',
				'WP_Block_Supports',
				'WP_Block',
				'WP_Block_Type',
				'WP_Block_Type_Registry',
				'Component_Fuzz_WPDB_Stub',
				'WP_Post',
				'WP_REST_Response',
				'WP_Taxonomy',
				'WP_Term',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'apply_filters',
				'apply_block_hooks_to_content',
				'apply_block_hooks_to_content_from_post_object',
				'block_has_support',
				'clean_post_cache',
				'clean_term_cache',
				'current_user_can',
				'get_comment_delimited_block_content',
				'get_all_registered_block_bindings_sources',
				'get_block_bindings_supported_attributes',
				'get_block_bindings_source',
				'get_block_wrapper_attributes',
				'get_hooked_blocks',
				'get_permalink',
				'get_post',
				'get_post_meta',
				'get_post_modified_time',
				'get_post_time',
				'get_registered_meta_keys',
				'get_taxonomy',
				'get_term',
				'get_term_link',
				'get_the_date',
				'get_the_modified_date',
				'has_block',
				'has_blocks',
				'has_filter',
				'insert_hooked_blocks_and_set_ignored_hooked_blocks_metadata',
				'insert_hooked_blocks_into_rest_response',
				'is_post_publicly_viewable',
				'is_post_status_viewable',
				'is_post_type_viewable',
				'is_protected_meta',
				'is_wp_error',
				'parse_blocks',
				'post_password_required',
				'register_block_bindings_source',
				'register_block_style',
				'register_block_type',
				'register_block_type_from_metadata',
				'register_meta',
				'register_taxonomy',
				'remove_filter',
				'render_block',
				'serialize_block',
				'serialize_blocks',
				'taxonomy_exists',
				'unregister_block_bindings_source',
				'unregister_block_style',
				'unregister_block_type',
				'unregister_meta_key',
				'unregister_taxonomy',
				'update_ignored_hooked_blocks_postmeta',
				'update_post_meta',
				'wp_delete_post',
				'wp_insert_post',
				'wp_json_encode',
				'wp_slash',
				'_block_bindings_pattern_overrides_get_value',
				'_block_bindings_post_data_get_value',
				'_block_bindings_post_meta_get_value',
				'_block_bindings_term_data_get_value',
				'_register_block_bindings_pattern_overrides_source',
				'_register_block_bindings_post_data_source',
				'_register_block_bindings_post_meta_source',
				'_register_block_bindings_term_data_source',
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

	private static function check_block_type_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( self::block_type_cases( $ctx->fork( 'block-types' ) ) as $index => $case ) {
			$registered = \register_block_type( $case['name'], $case['args'] );
			$looked_up  = $registry->get_registered( $case['name'] );
			$all        = $registry->get_all_registered();
			$missing    = $registry->get_registered( $case['name'] . '-missing' );

			$prepared = $registered instanceof \WP_Block_Type
				? $registered->prepare_attributes_for_render(
					array(
						'title'   => array( 'invalid' ),
						'count'   => 'not-an-integer',
						'unknown' => 'preserved',
					)
				)
				: array();
			$rendered = $registered instanceof \WP_Block_Type ? $registered->render( array(), '<span>inner</span>' ) : '';

			self::collect_failure(
				$failures,
				$registered instanceof \WP_Block_Type
					&& $registered === $looked_up
					&& $registered->name === $case['name']
					&& $registry->is_registered( $case['name'] )
					&& isset( $all[ $case['name'] ] )
					&& null === $missing
					&& $registered->is_dynamic()
					&& isset( $registered->attributes['metadata'], $registered->attributes['lock'] )
					&& isset( $prepared['title'], $prepared['count'], $prepared['enabled'], $prepared['unknown'] )
					&& $case['defaults']['title'] === $prepared['title']
					&& $case['defaults']['count'] === $prepared['count']
					&& true === $prepared['enabled']
					&& 'preserved' === $prepared['unknown']
					&& str_contains( $rendered, 'data-block="' . esc_attr( $case['name'] ) . '"' )
					&& str_contains( $rendered, esc_html( $case['defaults']['title'] ) )
					&& str_contains( $rendered, '<span>inner</span>' ),
				"register_block_type lifecycle and attribute defaults case {$index}",
				array(
					'name'     => $case['name'],
					'prepared' => $prepared,
					'rendered' => $rendered,
				)
			);

			$unregistered = \unregister_block_type( $case['name'] );
			self::collect_failure(
				$failures,
				$unregistered === $registered
					&& ! $registry->is_registered( $case['name'] )
					&& null === $registry->get_registered( $case['name'] ),
				"unregister_block_type removes the exact block instance case {$index}",
				array(
					'name'         => $case['name'],
					'unregistered' => $unregistered instanceof \WP_Block_Type ? $unregistered->name : $unregistered,
				)
			);
		}

		return self::result(
			$ctx,
			'blocks.registry.block-type-lifecycle',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_parser_detection_and_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		foreach ( self::parser_render_cases( $ctx->fork( 'parser-render' ) ) as $index => $case ) {
			$render_log = array();
			$filter_log = array(
				'pre'     => array(),
				'data'    => array(),
				'context' => array(),
			);

			$render_callback = static function ( array $attributes, string $content, \WP_Block $block ) use ( $case, &$render_log ): string {
				$render_log[] = array(
					'name'          => $block->name,
					'filteredToken' => $attributes['filteredToken'] ?? null,
					'contextToken'  => $block->context['componentFuzz/token'] ?? null,
					'contextParent' => $block->context['componentFuzz/parent'] ?? null,
					'contentLength' => strlen( $content ),
				);

				return '<div data-cfz-block="' . esc_attr( (string) $block->name ) . '" data-cfz-token="' . esc_attr( (string) ( $attributes['token'] ?? '' ) ) . '" data-cfz-filtered="' . esc_attr( (string) ( $attributes['filteredToken'] ?? '' ) ) . '" data-cfz-context="' . esc_attr( (string) ( $block->context['componentFuzz/token'] ?? '' ) ) . '">' . esc_html( (string) ( $attributes['unsafe'] ?? '' ) ) . $content . '</div>';
			};

			$pre_render_filter = static function ( $pre_render, array $parsed_block, $parent_block ) use ( $case, &$filter_log ) {
				$name = $parsed_block['blockName'] ?? null;
				$filter_log['pre'][] = array(
					'name'   => $name,
					'parent' => $parent_block instanceof \WP_Block ? $parent_block->name : null,
					'empty'  => null === $pre_render,
				);

				if ( $case['shortName'] === $name ) {
					return '<mark data-cfz-short="' . esc_attr( $case['token'] ) . '">' . esc_html( $case['unsafe'] ) . '</mark>';
				}

				return $pre_render;
			};

			$render_block_data_filter = static function ( array $parsed_block, array $source_block, $parent_block ) use ( $case, &$filter_log ): array {
				$name = $parsed_block['blockName'] ?? null;
				$filter_log['data'][] = array(
					'name'              => $name,
					'parent'            => $parent_block instanceof \WP_Block ? $parent_block->name : null,
					'sourceName'        => $source_block['blockName'] ?? null,
					'sourceHasFiltered' => isset( $source_block['attrs']['filteredToken'] ),
				);

				if ( in_array( $name, array( $case['outerName'], $case['childName'] ), true ) ) {
					$parsed_block['attrs']['filteredToken'] = $case['filteredToken'];
				}

				return $parsed_block;
			};

			$render_block_context_filter = static function ( array $context, array $parsed_block, $parent_block ) use ( $case, &$filter_log ): array {
				$name = $parsed_block['blockName'] ?? null;
				$filter_log['context'][] = array(
					'name'        => $name,
					'parent'      => $parent_block instanceof \WP_Block ? $parent_block->name : null,
					'hasFiltered' => isset( $parsed_block['attrs']['filteredToken'] ),
				);

				$context['componentFuzz/token']  = $case['token'];
				$context['componentFuzz/parent'] = $parent_block instanceof \WP_Block ? (string) $parent_block->name : 'root';

				return $context;
			};

			$registered_outer = \register_block_type(
				$case['outerName'],
				array(
					'title'            => 'Component Fuzz Render Outer',
					'attributes'       => self::parser_render_attributes(),
					'uses_context'     => array( 'componentFuzz/token', 'componentFuzz/parent' ),
					'render_callback'  => $render_callback,
					'skip_inner_blocks' => false,
				)
			);
			$registered_child = \register_block_type(
				$case['childName'],
				array(
					'title'           => 'Component Fuzz Render Child',
					'attributes'      => self::parser_render_attributes(),
					'uses_context'    => array( 'componentFuzz/token', 'componentFuzz/parent' ),
					'render_callback' => $render_callback,
				)
			);

			\add_filter( 'pre_render_block', $pre_render_filter, 99, 3 );
			\add_filter( 'render_block_data', $render_block_data_filter, 99, 3 );
			\add_filter( 'render_block_context', $render_block_context_filter, 99, 3 );

			try {
				$serialized   = \serialize_blocks( $case['blocks'] );
				$parsed       = \parse_blocks( $serialized );
				$joined       = implode( '', array_map( 'serialize_block', $parsed ) );
				$reserialized = \serialize_blocks( $parsed );
				$reparsed     = \parse_blocks( $reserialized );
				$rendered     = isset( $parsed[0] ) && is_array( $parsed[0] ) ? \render_block( $parsed[0] ) : '';

				$shape_before = self::block_tree_shape( $parsed );
				$shape_after  = self::block_tree_shape( $reparsed );
				$first_block  = $parsed[0] ?? array();
				$inner_blocks = is_array( $first_block ) && isset( $first_block['innerBlocks'] ) && is_array( $first_block['innerBlocks'] ) ? $first_block['innerBlocks'] : array();
				$inner_names  = array();
				foreach ( $inner_blocks as $inner_block ) {
					$inner_names[] = is_array( $inner_block ) ? ( $inner_block['blockName'] ?? null ) : null;
				}

				self::collect_failure(
					$failures,
					$registered_outer instanceof \WP_Block_Type
						&& $registered_child instanceof \WP_Block_Type
						&& $serialized === $reserialized
						&& $reserialized === $joined
						&& $shape_before === $shape_after
						&& 1 === count( $parsed )
						&& is_array( $first_block )
						&& $case['outerName'] === ( $first_block['blockName'] ?? null )
						&& array( $case['childName'], 'core/paragraph', $case['shortName'] ) === $inner_names
						&& 3 === self::null_marker_count( $first_block['innerContent'] ?? array() )
						&& ! str_contains( $serialized, $case['unsafe'] )
						&& \has_blocks( $serialized )
						&& \has_block( $case['outerName'], $serialized )
						&& \has_block( $case['childName'], $serialized )
						&& \has_block( $case['shortName'], $serialized )
						&& \has_block( 'core/paragraph', $serialized )
						&& \has_block( 'paragraph', $serialized )
						&& ! \has_block( $case['missingName'], $serialized )
						&& ! \has_blocks( $case['plainText'] ),
					"parse/serialize and has_block detection contracts case {$index}",
					array(
						'case'               => $case,
						'serializedLength'   => strlen( $serialized ),
						'reserializedLength' => strlen( $reserialized ),
						'innerNames'         => $inner_names,
						'shapeDifference'    => self::first_value_difference( $shape_before, $shape_after ),
					)
				);

				self::collect_failure(
					$failures,
					is_string( $rendered )
						&& strlen( $rendered ) < 8192
						&& str_contains( $rendered, 'data-cfz-block="' . esc_attr( $case['outerName'] ) . '"' )
						&& str_contains( $rendered, 'data-cfz-block="' . esc_attr( $case['childName'] ) . '"' )
						&& str_contains( $rendered, 'data-cfz-filtered="' . esc_attr( $case['filteredToken'] ) . '"' )
						&& str_contains( $rendered, 'data-cfz-context="' . esc_attr( $case['token'] ) . '"' )
						&& str_contains( $rendered, 'data-cfz-short="' . esc_attr( $case['token'] ) . '"' )
						&& str_contains( $rendered, esc_html( $case['unsafe'] ) )
						&& ! str_contains( $rendered, $case['unsafe'] )
						&& self::log_contains( $filter_log['pre'], $case['outerName'], null )
						&& self::log_contains( $filter_log['pre'], $case['childName'], $case['outerName'] )
						&& self::log_contains( $filter_log['pre'], 'core/paragraph', $case['outerName'] )
						&& self::log_contains( $filter_log['pre'], $case['shortName'], $case['outerName'] )
						&& self::log_contains( $filter_log['data'], $case['outerName'], null )
						&& self::log_contains( $filter_log['data'], $case['childName'], $case['outerName'] )
						&& ! self::log_contains( $filter_log['data'], $case['shortName'], $case['outerName'] )
						&& self::log_contains( $filter_log['context'], $case['outerName'], null )
						&& self::log_contains( $filter_log['context'], $case['childName'], $case['outerName'] )
						&& self::log_contains( $filter_log['context'], 'core/paragraph', $case['outerName'] )
						&& ! self::log_contains( $filter_log['context'], $case['shortName'], $case['outerName'] )
						&& self::render_log_contains( $render_log, $case['outerName'], $case['filteredToken'], $case['token'], 'root' )
						&& self::render_log_contains( $render_log, $case['childName'], $case['filteredToken'], $case['token'], $case['outerName'] ),
					"render_block dynamic callbacks and filter locality case {$index}",
					array(
						'case'      => $case,
						'rendered'  => $rendered,
						'filters'   => $filter_log,
						'renderLog' => $render_log,
					)
				);
			} finally {
				\remove_filter( 'render_block_context', $render_block_context_filter, 99 );
				\remove_filter( 'render_block_data', $render_block_data_filter, 99 );
				\remove_filter( 'pre_render_block', $pre_render_filter, 99 );
				\unregister_block_type( $case['childName'] );
				\unregister_block_type( $case['outerName'] );
			}
		}

		return self::result(
			$ctx,
			'blocks.parser-detection-render-filters',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_nested_attribute_round_trips( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		foreach ( self::nested_attribute_cases( $ctx->fork( 'nested-attrs' ) ) as $index => $case ) {
			$serialized      = \serialize_blocks( $case['blocks'] );
			$parsed          = \parse_blocks( $serialized );
			$reserialized    = \serialize_blocks( $parsed );
			$reparsed        = \parse_blocks( $reserialized );
			$serialized_last = \serialize_blocks( $reparsed );

			$parsed_shape    = self::block_tree_shape( $parsed );
			$reparsed_shape  = self::block_tree_shape( $reparsed );
			$expected_attrs  = self::block_attrs_by_name( $case['blocks'] );
			$parsed_attrs    = self::block_attrs_by_name( $parsed );
			$reparsed_attrs  = self::block_attrs_by_name( $reparsed );
			$parsed_root     = $parsed[0] ?? array();
			$inner_blocks    = is_array( $parsed_root ) && is_array( $parsed_root['innerBlocks'] ?? null ) ? $parsed_root['innerBlocks'] : array();
			$parsed_child    = $inner_blocks[0] ?? array();
			$child_inner     = is_array( $parsed_child ) && is_array( $parsed_child['innerBlocks'] ?? null ) ? $parsed_child['innerBlocks'] : array();

			self::collect_failure(
				$failures,
				$serialized === $reserialized
					&& $reserialized === $serialized_last
					&& $parsed_shape === $reparsed_shape
					&& $expected_attrs === $parsed_attrs
					&& $parsed_attrs === $reparsed_attrs
					&& 1 === count( $parsed )
					&& 1 === count( $inner_blocks )
					&& 1 === count( $child_inner )
					&& array( $case['childName'] ) === self::block_names( $inner_blocks )
					&& array( $case['leafName'] ) === self::block_names( $child_inner )
					&& ! str_contains( $serialized, $case['unsafeAttr'] )
					&& isset( $parsed_attrs[ $case['rootName'] ]['unsafe'] )
					&& $case['unsafeAttr'] === $parsed_attrs[ $case['rootName'] ]['unsafe'],
				"nested parser attributes survive serialize/parse fixed points case {$index}",
				array(
					'names'             => array(
						'root'  => $case['rootName'],
						'child' => $case['childName'],
						'leaf'  => $case['leafName'],
					),
					'serializedLength'  => strlen( $serialized ),
					'unsafeAttr'        => $case['unsafeAttr'],
					'attrsDifference'   => self::first_value_difference( $expected_attrs, $parsed_attrs ),
					'reparseDifference' => self::first_value_difference( $parsed_shape, $reparsed_shape ),
				)
			);
		}

		return self::result(
			$ctx,
			'blocks.parser.nested-attrs-round-trip',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_style_pattern_binding_registries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures         = array();
		$style_registry   = \WP_Block_Styles_Registry::get_instance();
		$pattern_registry = \WP_Block_Patterns_Registry::get_instance();
		$binding_registry = \WP_Block_Bindings_Registry::get_instance();

		foreach ( self::registry_cases( $ctx->fork( 'registries' ) ) as $index => $case ) {
			$style_registered = \register_block_style(
				$case['styleBlocks'],
				array(
					'name'         => $case['styleName'],
					'inline_style' => '.is-style-' . $case['styleName'] . '{outline:' . $case['outline'] . 'px solid currentColor;}',
					'style_data'   => array(
						'color' => array(
							'text' => $case['color'],
						),
					),
				)
			);
			$primary_style    = $style_registry->get_registered( $case['styleBlocks'][0], $case['styleName'] );
			$secondary_style  = $style_registry->get_registered( $case['styleBlocks'][1], $case['styleName'] );
			$style_removed    = \unregister_block_style( $case['styleBlocks'][0], $case['styleName'] );

			self::collect_failure(
				$failures,
				true === $style_registered
					&& is_array( $primary_style )
					&& is_array( $secondary_style )
					&& $case['styleName'] === $primary_style['name']
					&& $case['styleName'] === $secondary_style['label']
					&& true === $style_removed
					&& ! $style_registry->is_registered( $case['styleBlocks'][0], $case['styleName'] )
					&& $style_registry->is_registered( $case['styleBlocks'][1], $case['styleName'] ),
				"register_block_style fans out and unregisters by block case {$index}",
				array(
					'case'           => $case,
					'primaryStyle'   => $primary_style,
					'secondaryStyle' => $secondary_style,
				)
			);

			$pattern_registered = $pattern_registry->register(
				$case['patternName'],
				array(
					'title'       => $case['patternTitle'],
					'content'     => $case['patternContent'],
					'description' => $case['patternDescription'],
					'categories'  => array( 'text', 'component-fuzz' ),
					'keywords'    => array( $case['styleName'], 'fuzz' ),
					'blockTypes'  => $case['styleBlocks'],
					'inserter'    => $case['inserter'],
				)
			);
			$pattern          = $pattern_registry->get_registered( $case['patternName'] );
			$all_patterns     = $pattern_registry->get_all_registered();
			$pattern_removed  = $pattern_registry->unregister( $case['patternName'] );
			$pattern_names    = array();
			foreach ( $all_patterns as $pattern_entry ) {
				if ( isset( $pattern_entry['name'] ) ) {
					$pattern_names[] = $pattern_entry['name'];
				}
			}

			self::collect_failure(
				$failures,
				true === $pattern_registered
					&& is_array( $pattern )
					&& $case['patternName'] === $pattern['name']
					&& $case['patternTitle'] === $pattern['title']
					&& str_contains( $pattern['content'], '<!-- wp:paragraph -->' )
					&& in_array( $case['patternName'], $pattern_names, true )
					&& true === $pattern_removed
					&& ! $pattern_registry->is_registered( $case['patternName'] ),
				"WP_Block_Patterns_Registry stores complete pattern data case {$index}",
				array(
					'case'    => $case,
					'pattern' => $pattern,
				)
			);

			$source = \register_block_bindings_source(
				$case['bindingName'],
				array(
					'label'              => $case['bindingLabel'],
					'uses_context'       => array( 'postId', 'componentFuzz' ),
					'get_value_callback' => static function ( array $source_args, $block_instance, string $attribute_name ) use ( $case ) {
						unset( $block_instance );
						return $case['bindingLabel'] . ':' . ( $source_args['key'] ?? 'missing' ) . ':' . $attribute_name;
					},
				)
			);
			$binding_lookup = \get_block_bindings_source( $case['bindingName'] );
			$binding_value  = $source instanceof \WP_Block_Bindings_Source
				? $source->get_value( array( 'key' => $case['styleName'] ), null, 'content' )
				: null;
			$all_sources    = \get_all_registered_block_bindings_sources();
			$source_removed = \unregister_block_bindings_source( $case['bindingName'] );

			self::collect_failure(
				$failures,
				$source instanceof \WP_Block_Bindings_Source
					&& $source === $binding_lookup
					&& $case['bindingName'] === $source->name
					&& $case['bindingLabel'] === $source->label
					&& in_array( 'componentFuzz', $source->uses_context, true )
					&& $case['bindingLabel'] . ':' . $case['styleName'] . ':content' === $binding_value
					&& isset( $all_sources[ $case['bindingName'] ] )
					&& $source_removed === $source
					&& ! $binding_registry->is_registered( $case['bindingName'] ),
				"register_block_bindings_source stores callback sources case {$index}",
				array(
					'case'         => $case,
					'bindingValue' => $binding_value,
				)
			);
		}

		return self::result(
			$ctx,
			'blocks.registry.style-pattern-binding-lifecycle',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_block_bindings_render_pipeline( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		foreach ( self::block_bindings_render_cases( $ctx->fork( 'binding-render' ) ) as $index => $case ) {
			$supported_log    = array();
			$source_log       = array();
			$source_value_log = array();
			$context_log      = array();
			$render_log       = array();

			$supported_filter = static function ( array $supported_attributes, string $block_type ) use ( $case, &$supported_log ): array {
				$supported_log[] = array(
					'hook'      => 'global',
					'blockType' => $block_type,
					'before'    => $supported_attributes,
				);

				if ( $case['blockName'] !== $block_type ) {
					return $supported_attributes;
				}

				return array_values( array_unique( array_merge( $supported_attributes, array( 'content', 'unsupportedFromGlobal' ) ) ) );
			};
			$dynamic_filter   = static function ( array $supported_attributes ) use ( $case, &$supported_log ): array {
				$supported_log[] = array(
					'hook'      => 'dynamic',
					'blockType' => $case['blockName'],
					'before'    => $supported_attributes,
				);

				return array( 'content', 'url' );
			};
			$context_filter   = static function ( array $context, array $parsed_block, $parent_block ) use ( $case, &$context_log ): array {
				$context_log[] = array(
					'name'   => $parsed_block['blockName'] ?? null,
					'parent' => $parent_block instanceof \WP_Block ? $parent_block->name : null,
				);

				if ( $case['blockName'] === ( $parsed_block['blockName'] ?? null ) ) {
					$context['componentFuzz/bindingToken'] = $case['contextToken'];
				}

				return $context;
			};
			$source_filter    = static function ( $value, string $source_name, array $source_args, $block_instance, string $attribute_name ) use ( $case, &$source_value_log ) {
				$source_value_log[] = array(
					'value'     => $value,
					'source'    => $source_name,
					'args'      => $source_args,
					'attribute' => $attribute_name,
					'blockName' => $block_instance instanceof \WP_Block ? $block_instance->name : null,
					'context'   => $block_instance instanceof \WP_Block ? ( $block_instance->context['componentFuzz/bindingToken'] ?? null ) : null,
				);

				if ( $case['sourceName'] !== $source_name ) {
					return $value;
				}

				if ( 'content' === $attribute_name ) {
					return $case['filteredContent'];
				}
				if ( 'url' === $attribute_name ) {
					return $case['filteredUrl'];
				}

				return $value;
			};
			$get_value        = static function ( array $source_args, $block_instance, string $attribute_name ) use ( $case, &$source_log ) {
				$source_log[] = array(
					'args'      => $source_args,
					'attribute' => $attribute_name,
					'blockName' => $block_instance instanceof \WP_Block ? $block_instance->name : null,
					'context'   => $block_instance instanceof \WP_Block ? ( $block_instance->context['componentFuzz/bindingToken'] ?? null ) : null,
				);

				if ( 'content' === $attribute_name ) {
					return $case['sourceContent'];
				}
				if ( 'url' === $attribute_name ) {
					return $case['sourceUrl'];
				}

				return 'unexpected-' . $case['token'] . '-' . $attribute_name;
			};
			$render_callback  = static function ( array $attributes, string $content, \WP_Block $block ) use ( $case, &$render_log ): string {
				$render_log[] = array(
					'blockName'       => $block->name,
					'content'         => $content,
					'contentAttr'     => $attributes['content'] ?? null,
					'urlAttr'         => $attributes['url'] ?? null,
					'unsupportedAttr' => $attributes['unsupported'] ?? null,
					'hasMissingAttr'  => array_key_exists( 'missing', $attributes ),
					'hasMalformedAttr' => array_key_exists( 'malformed', $attributes ),
					'metadata'        => $attributes['metadata'] ?? null,
					'context'         => $block->context['componentFuzz/bindingToken'] ?? null,
				);

				return '<section data-cfz-bindings="' . esc_attr( $case['token'] ) . '">' . $content . '</section>';
			};

			$registered_source = \register_block_bindings_source(
				$case['sourceName'],
				array(
					'label'              => 'Component Fuzz Binding Render ' . $index,
					'uses_context'       => array( 'componentFuzz/bindingToken' ),
					'get_value_callback' => $get_value,
				)
			);
			$registered_block  = \register_block_type(
				$case['blockName'],
				array(
					'title'           => 'Component Fuzz Binding Render',
					'api_version'     => 3,
					'attributes'      => self::block_bindings_render_attributes(),
					'render_callback' => $render_callback,
				)
			);

			\add_filter( 'block_bindings_supported_attributes', $supported_filter, 10, 2 );
			\add_filter( 'block_bindings_supported_attributes_' . $case['blockName'], $dynamic_filter, 10, 1 );
			\add_filter( 'render_block_context', $context_filter, 99, 3 );
			\add_filter( 'block_bindings_source_value', $source_filter, 99, 5 );

			try {
				$supported = \get_block_bindings_supported_attributes( $case['blockName'] );
				$rendered  = \render_block( $case['block'] );

				$source_attributes       = array_column( $source_log, 'attribute' );
				$source_value_attributes = array_column( $source_value_log, 'attribute' );
				$render_entry            = $render_log[0] ?? array();

				self::collect_failure(
					$failures,
					$registered_source instanceof \WP_Block_Bindings_Source
						&& $registered_block instanceof \WP_Block_Type
						&& array( 'content', 'url' ) === $supported
						&& self::binding_supported_log_contains( $supported_log, 'global', $case['blockName'], array() )
						&& self::binding_supported_log_contains( $supported_log, 'dynamic', $case['blockName'], array( 'content', 'unsupportedFromGlobal' ) ),
					"block binding supported-attribute filters gate custom block attributes case {$index}",
					array(
						'case'         => $case,
						'supported'    => $supported,
						'supportedLog' => $supported_log,
					)
				);

				self::collect_failure(
					$failures,
					array( 'content', 'url' ) === $source_attributes
						&& array( 'content', 'url' ) === $source_value_attributes
						&& self::binding_source_log_contains( $source_log, $case, 'content', $case['contentArgs'] )
						&& self::binding_source_log_contains( $source_log, $case, 'url', $case['urlArgs'] )
						&& self::binding_source_value_log_contains( $source_value_log, $case, 'content', $case['contentArgs'], $case['sourceContent'] )
						&& self::binding_source_value_log_contains( $source_value_log, $case, 'url', $case['urlArgs'], $case['sourceUrl'] ),
					"block binding sources receive exact args, block context, and value-filter payloads case {$index}",
					array(
						'case'           => $case,
						'sourceLog'      => $source_log,
						'sourceValueLog' => $source_value_log,
					)
				);

				self::collect_failure(
					$failures,
					is_string( $rendered )
						&& str_contains( $rendered, 'data-cfz-bindings="' . esc_attr( $case['token'] ) . '"' )
						&& str_contains( $rendered, '<p>' . $case['filteredContent'] . '</p>' )
						&& str_contains( $rendered, 'href="' . esc_attr( $case['filteredUrl'] ) . '"' )
						&& str_contains( $rendered, 'title="' . esc_attr( $case['fallbackUnsupported'] ) . '"' )
						&& str_contains( $rendered, 'Fallback link ' . esc_html( $case['token'] ) )
						&& ! str_contains( $rendered, $case['fallbackContent'] )
						&& ! str_contains( $rendered, $case['fallbackUrl'] )
						&& $case['filteredContent'] === ( $render_entry['contentAttr'] ?? null )
						&& $case['filteredUrl'] === ( $render_entry['urlAttr'] ?? null )
						&& $case['fallbackUnsupported'] === ( $render_entry['unsupportedAttr'] ?? null )
						&& false === ( $render_entry['hasMissingAttr'] ?? null )
						&& false === ( $render_entry['hasMalformedAttr'] ?? null )
						&& isset( $render_entry['metadata']['bindings']['missing'], $render_entry['metadata']['bindings']['malformed'] )
						&& $case['contextToken'] === ( $render_entry['context'] ?? null ),
					"render_block merges computed binding attributes before dynamic rendering and replaces only supported HTML targets case {$index}",
					array(
						'case'        => $case,
						'rendered'    => $rendered,
						'renderLog'   => $render_log,
						'contextLog'  => $context_log,
						'sourceAttrs' => $source_attributes,
					)
				);
			} finally {
				\remove_filter( 'block_bindings_source_value', $source_filter, 99 );
				\remove_filter( 'render_block_context', $context_filter, 99 );
				\remove_filter( 'block_bindings_supported_attributes_' . $case['blockName'], $dynamic_filter, 10 );
				\remove_filter( 'block_bindings_supported_attributes', $supported_filter, 10 );
				\unregister_block_bindings_source( $case['sourceName'] );
				\unregister_block_type( $case['blockName'] );
			}
		}

		return self::result(
			$ctx,
			'blocks.block-bindings.render-pipeline',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_builtin_block_binding_sources( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$case         = self::builtin_block_binding_case( $ctx->fork( 'builtin-sources' ) );
		$post_ids     = array();
		$term_ids     = array();
		$binding_registry = \WP_Block_Bindings_Registry::get_instance();
		$previous_sources = self::get_object_property( $binding_registry, 'sources' );
		$had_meta_keys    = array_key_exists( 'wp_meta_keys', $GLOBALS );
		$previous_meta_keys = $GLOBALS['wp_meta_keys'] ?? null;
		$had_taxonomies     = array_key_exists( 'wp_taxonomies', $GLOBALS );
		$previous_taxonomies = $GLOBALS['wp_taxonomies'] ?? null;
		$had_rewrite         = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite    = $GLOBALS['wp_rewrite'] ?? null;
		$grant_private_post_read = false;
		$grant_private_taxonomy_read = false;

		$cap_filter = static function ( array $allcaps, array $caps, array $args = array() ) use ( &$grant_private_post_read, &$grant_private_taxonomy_read ): array {
			if ( $grant_private_post_read && 'read_post' === ( $args[0] ?? null ) ) {
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = true;
				}
			}

			if ( $grant_private_taxonomy_read ) {
				foreach ( $caps as $cap ) {
					if ( 'read' === $cap ) {
						$allcaps['read'] = true;
					}
				}
			}

			return $allcaps;
		};

		\add_filter( 'user_has_cap', $cap_filter, 10, 4 );

		try {
			$GLOBALS['wp_rewrite'] = class_exists( 'WP_Rewrite' )
				? new \WP_Rewrite()
				: new class() {
					public function get_extra_permastruct( $taxonomy ) {
						unset( $taxonomy );
						return false;
					}
				};

			self::set_object_property( $binding_registry, 'sources', array() );
			\_register_block_bindings_pattern_overrides_source();
			\_register_block_bindings_post_data_source();
			\_register_block_bindings_post_meta_source();
			\_register_block_bindings_term_data_source();
			$term_source_before = \get_block_bindings_source( 'core/term-data' );
			\_register_block_bindings_term_data_source();
			$term_source_after = \get_block_bindings_source( 'core/term-data' );

			self::collect_failure(
				$failures,
				self::builtin_binding_source_matches(
					\get_block_bindings_source( 'core/pattern-overrides' ),
					'core/pattern-overrides',
					'Pattern Overrides',
					'_block_bindings_pattern_overrides_get_value',
					array( 'pattern/overrides' )
				)
					&& self::builtin_binding_source_matches(
						\get_block_bindings_source( 'core/post-data' ),
						'core/post-data',
						'Post Data',
						'_block_bindings_post_data_get_value',
						array( 'postId', 'postType' )
					)
					&& self::builtin_binding_source_matches(
						\get_block_bindings_source( 'core/post-meta' ),
						'core/post-meta',
						'Post Meta',
						'_block_bindings_post_meta_get_value',
						array( 'postId', 'postType' )
					)
					&& self::builtin_binding_source_matches(
						$term_source_before,
						'core/term-data',
						'Term Data',
						'_block_bindings_term_data_get_value',
						array( 'termId', 'taxonomy' )
					)
					&& $term_source_before instanceof \WP_Block_Bindings_Source
					&& $term_source_before === $term_source_after,
				'built-in block binding source registration callbacks expose expected metadata and term-data is idempotent',
				array(
					'sources' => array_keys( \get_all_registered_block_bindings_sources() ),
				)
			);

			$pattern_block = self::builtin_binding_block(
				'core/paragraph',
				array(
					'metadata' => array(
						'name' => $case['patternName'],
					),
				),
				array(
					'pattern/overrides' => array(
						$case['patternName'] => array(
							'content' => $case['patternContent'],
							'url'     => null,
						),
					),
				)
			);
			$pattern_missing_name = self::builtin_binding_block( 'core/paragraph', array(), $pattern_block->context );

			self::collect_failure(
				$failures,
				$case['patternContent'] === \_block_bindings_pattern_overrides_get_value( array(), $pattern_block, 'content' )
					&& null === \_block_bindings_pattern_overrides_get_value( array(), $pattern_block, 'url' )
					&& null === \_block_bindings_pattern_overrides_get_value( array(), $pattern_block, 'missing' )
					&& null === \_block_bindings_pattern_overrides_get_value( array(), $pattern_missing_name, 'content' ),
				'pattern override source reads attribute-scoped overrides from named pattern context and preserves null values',
				array(
					'patternName' => $case['patternName'],
					'context'     => $pattern_block->context,
				)
			);

			$public_post_id = self::insert_builtin_binding_post(
				array(
					'post_title'        => 'Built-in Binding Public ' . $case['token'],
					'post_name'         => 'builtin-binding-public-' . $case['token'],
					'post_status'       => 'publish',
					'post_date'         => $case['postDate'],
					'post_date_gmt'     => $case['postDate'],
					'post_modified'     => $case['postModified'],
					'post_modified_gmt' => $case['postModified'],
					'guid'              => 'http://example.test/builtin-binding-public-' . $case['token'],
				)
			);
			$GLOBALS['wpdb']->update(
				$GLOBALS['wpdb']->posts,
				array(
					'post_modified'     => $case['postModified'],
					'post_modified_gmt' => $case['postModified'],
				),
				array( 'ID' => $public_post_id )
			);
			\clean_post_cache( $public_post_id );
			$same_date_post_id = self::insert_builtin_binding_post(
				array(
					'post_title'        => 'Built-in Binding Same Date ' . $case['token'],
					'post_name'         => 'builtin-binding-same-date-' . $case['token'],
					'post_status'       => 'publish',
					'post_date'         => $case['postDate'],
					'post_date_gmt'     => $case['postDate'],
					'post_modified'     => $case['postDate'],
					'post_modified_gmt' => $case['postDate'],
					'guid'              => 'http://example.test/builtin-binding-same-date-' . $case['token'],
				)
			);
			$private_post_id = self::insert_builtin_binding_post(
				array(
					'post_title'        => 'Built-in Binding Private ' . $case['token'],
					'post_name'         => 'builtin-binding-private-' . $case['token'],
					'post_status'       => 'private',
					'post_date'         => $case['postDate'],
					'post_date_gmt'     => $case['postDate'],
					'post_modified'     => $case['postModified'],
					'post_modified_gmt' => $case['postModified'],
					'guid'              => 'http://example.test/builtin-binding-private-' . $case['token'],
				)
			);
			$password_post_id = self::insert_builtin_binding_post(
				array(
					'post_title'        => 'Built-in Binding Password ' . $case['token'],
					'post_name'         => 'builtin-binding-password-' . $case['token'],
					'post_status'       => 'publish',
					'post_password'     => 'secret-' . $case['token'],
					'post_date'         => $case['postDate'],
					'post_date_gmt'     => $case['postDate'],
					'post_modified'     => $case['postModified'],
					'post_modified_gmt' => $case['postModified'],
					'guid'              => 'http://example.test/builtin-binding-password-' . $case['token'],
				)
			);
			$post_ids = array( $public_post_id, $same_date_post_id, $private_post_id, $password_post_id );

			$public_post_block    = self::builtin_binding_block( 'core/paragraph', array(), array( 'postId' => $public_post_id, 'postType' => 'post' ) );
			$same_date_post_block = self::builtin_binding_block( 'core/paragraph', array(), array( 'postId' => $same_date_post_id, 'postType' => 'post' ) );
			$private_post_block   = self::builtin_binding_block( 'core/paragraph', array(), array( 'postId' => $private_post_id, 'postType' => 'post' ) );
			$password_post_block  = self::builtin_binding_block( 'core/paragraph', array(), array( 'postId' => $password_post_id, 'postType' => 'post' ) );
			$nav_post_block       = self::builtin_binding_block( 'core/navigation-link', array( 'id' => $public_post_id ), array( 'postId' => $password_post_id, 'postType' => 'post' ) );

			$private_without_cap = \_block_bindings_post_data_get_value( array( 'field' => 'date' ), $private_post_block );
			$grant_private_post_read = true;
			$private_with_cap = \_block_bindings_post_data_get_value( array( 'field' => 'date' ), $private_post_block );
			$grant_private_post_read = false;

			$grant_private_post_read = true;
			$post_data_checks = array(
				'date'          => esc_attr( \get_the_date( 'c', $public_post_id ) ) === \_block_bindings_post_data_get_value( array( 'field' => 'date' ), $public_post_block ),
				'legacyKey'     => esc_attr( \get_the_date( 'c', $public_post_id ) ) === \_block_bindings_post_data_get_value( array( 'key' => 'date' ), $public_post_block ),
				'modified'      => esc_attr( \get_the_modified_date( 'c', $public_post_id ) ) === \_block_bindings_post_data_get_value( array( 'field' => 'modified' ), $public_post_block ),
				'sameModified'  => '' === \_block_bindings_post_data_get_value( array( 'field' => 'modified' ), $same_date_post_block ),
				'link'          => esc_url( \get_permalink( $public_post_id ) ) === \_block_bindings_post_data_get_value( array( 'field' => 'link' ), $public_post_block ),
				'navigation'    => esc_url( \get_permalink( $public_post_id ) ) === \_block_bindings_post_data_get_value( array( 'field' => 'link' ), $nav_post_block ),
				'missingField'  => null === \_block_bindings_post_data_get_value( array( 'field' => 'missing' ), $public_post_block ),
				'missingArgs'   => null === \_block_bindings_post_data_get_value( array(), $public_post_block ),
				'privateDenied' => null === $private_without_cap,
				'privateRead'   => is_string( $private_with_cap ) && '' !== $private_with_cap,
				'password'      => null === \_block_bindings_post_data_get_value( array( 'field' => 'date' ), $password_post_block ),
			);

			self::collect_failure(
				$failures,
				! in_array( false, $post_data_checks, true ),
				'post-data source values and gates',
				array(
					'checks'     => $post_data_checks,
					'values'     => array(
						'modifiedExpected' => esc_attr( \get_the_modified_date( 'c', $public_post_id ) ),
						'modifiedActual'   => \_block_bindings_post_data_get_value( array( 'field' => 'modified' ), $public_post_block ),
						'privateDenied'    => $private_without_cap,
						'privateRead'      => $private_with_cap,
					),
					'postIds'    => $post_ids,
				)
			);
			$grant_private_post_read = false;

			foreach (
				array(
					array( $case['metaKey'], 'post', true ),
					array( $case['globalMetaKey'], '', true ),
					array( $case['hiddenMetaKey'], 'post', false ),
				) as $meta_registration
			) {
				\register_meta(
					'post',
					$meta_registration[0],
					array(
						'object_subtype' => $meta_registration[1],
						'type'           => 'string',
						'single'         => true,
						'show_in_rest'   => $meta_registration[2],
					)
				);
			}

			\update_post_meta( $public_post_id, $case['metaKey'], $case['metaValue'] );
			\update_post_meta( $public_post_id, $case['globalMetaKey'], $case['globalMetaValue'] );
			\update_post_meta( $public_post_id, $case['hiddenMetaKey'], $case['hiddenMetaValue'] );
			\update_post_meta( $public_post_id, $case['protectedMetaKey'], $case['protectedMetaValue'] );
			\update_post_meta( $private_post_id, $case['metaKey'], $case['privateMetaValue'] );
			\update_post_meta( $password_post_id, $case['metaKey'], $case['passwordMetaValue'] );

			$private_meta_without_cap = \_block_bindings_post_meta_get_value( array( 'key' => $case['metaKey'] ), $private_post_block );
			$grant_private_post_read  = true;
			$private_meta_with_cap    = \_block_bindings_post_meta_get_value( array( 'key' => $case['metaKey'] ), $private_post_block );
			$grant_private_post_read  = false;

			$grant_private_post_read = true;
			self::collect_failure(
				$failures,
				$case['metaValue'] === \_block_bindings_post_meta_get_value( array( 'key' => $case['metaKey'] ), $public_post_block )
					&& $case['globalMetaValue'] === \_block_bindings_post_meta_get_value( array( 'key' => $case['globalMetaKey'] ), $public_post_block )
					&& null === \_block_bindings_post_meta_get_value( array( 'key' => $case['hiddenMetaKey'] ), $public_post_block )
					&& null === \_block_bindings_post_meta_get_value( array( 'key' => $case['protectedMetaKey'] ), $public_post_block )
					&& null === \_block_bindings_post_meta_get_value( array( 'key' => $case['metaKey'] ), self::builtin_binding_block( 'core/paragraph', array(), array( 'postType' => 'post' ) ) )
					&& null === \_block_bindings_post_meta_get_value( array(), $public_post_block )
					&& null === $private_meta_without_cap
					&& $case['privateMetaValue'] === $private_meta_with_cap
					&& null === \_block_bindings_post_meta_get_value( array( 'key' => $case['metaKey'] ), $password_post_block ),
				'post-meta source exposes only public, REST-registered, unprotected meta and enforces post visibility gates',
				array(
					'metaKeys'              => array( $case['metaKey'], $case['globalMetaKey'], $case['hiddenMetaKey'], $case['protectedMetaKey'] ),
					'privateWithoutCap'     => $private_meta_without_cap,
					'privateWithCap'        => $private_meta_with_cap,
					'registeredSubtypeKeys' => array_keys( \get_registered_meta_keys( 'post', 'post' ) ),
				)
			);
			$grant_private_post_read = false;

			$public_taxonomy = \register_taxonomy(
				$case['publicTaxonomy'],
				'post',
				array(
					'public'             => true,
					'publicly_queryable' => true,
					'query_var'          => false,
					'rewrite'            => false,
				)
			);
			$private_taxonomy = \register_taxonomy(
				$case['privateTaxonomy'],
				'post',
				array(
					'public'             => false,
					'publicly_queryable' => false,
					'query_var'          => false,
					'rewrite'            => false,
				)
			);

			if ( \is_wp_error( $public_taxonomy ) || \is_wp_error( $private_taxonomy ) ) {
				throw new \RuntimeException( 'Could not register built-in binding fixture taxonomies.' );
			}

			$public_term  = self::insert_builtin_binding_term( $case['publicTaxonomy'], $case['termName'], $case['termSlug'], $case['termDescription'], $case['termParent'], $case['termCount'] );
			$private_term = self::insert_builtin_binding_term( $case['privateTaxonomy'], $case['privateTermName'], $case['privateTermSlug'], $case['privateTermDescription'], 0, 1 );
			$term_ids     = array(
				$public_term['term_id']  => $case['publicTaxonomy'],
				$private_term['term_id'] => $case['privateTaxonomy'],
			);

			$public_term_block  = self::builtin_binding_block( 'core/paragraph', array(), array( 'termId' => $public_term['term_id'], 'taxonomy' => $case['publicTaxonomy'] ) );
			$private_term_block = self::builtin_binding_block( 'core/paragraph', array(), array( 'termId' => $private_term['term_id'], 'taxonomy' => $case['privateTaxonomy'] ) );
			$nav_term_block     = self::builtin_binding_block( 'core/navigation-submenu', array( 'id' => $public_term['term_id'], 'type' => $case['publicTaxonomy'] ), array() );
			$public_wp_term     = \get_term( $public_term['term_id'], $case['publicTaxonomy'] );

			$private_term_without_cap = \_block_bindings_term_data_get_value( array( 'field' => 'name' ), $private_term_block );
			$grant_private_taxonomy_read = true;
			$private_term_with_cap = \_block_bindings_term_data_get_value( array( 'field' => 'name' ), $private_term_block );
			$grant_private_taxonomy_read = false;

			self::collect_failure(
				$failures,
				esc_html( (string) $public_term['term_id'] ) === \_block_bindings_term_data_get_value( array( 'field' => 'id' ), $public_term_block )
					&& esc_html( $case['termName'] ) === \_block_bindings_term_data_get_value( array( 'field' => 'name' ), $public_term_block )
					&& esc_html( $case['termSlug'] ) === \_block_bindings_term_data_get_value( array( 'field' => 'slug' ), $public_term_block )
					&& esc_html( (string) $case['termParent'] ) === \_block_bindings_term_data_get_value( array( 'field' => 'parent' ), $public_term_block )
					&& esc_html( (string) $case['termCount'] ) === \_block_bindings_term_data_get_value( array( 'field' => 'count' ), $public_term_block )
					&& wp_kses_post( $case['termDescription'] ) === \_block_bindings_term_data_get_value( array( 'field' => 'description' ), $public_term_block )
					&& esc_url( \get_term_link( $public_wp_term ) ) === \_block_bindings_term_data_get_value( array( 'field' => 'link' ), $public_term_block )
					&& esc_html( $case['termSlug'] ) === \_block_bindings_term_data_get_value( array( 'field' => 'slug' ), $nav_term_block )
					&& null === \_block_bindings_term_data_get_value( array( 'field' => 'missing' ), $public_term_block )
					&& null === \_block_bindings_term_data_get_value( array(), $public_term_block )
					&& null === \_block_bindings_term_data_get_value( array( 'field' => 'name' ), self::builtin_binding_block( 'core/paragraph', array(), array( 'termId' => 999999, 'taxonomy' => $case['publicTaxonomy'] ) ) )
					&& null === $private_term_without_cap
					&& esc_html( $case['privateTermName'] ) === $private_term_with_cap,
				'term-data source reads escaped term fields from context or navigation attributes and gates non-public taxonomies on read capability',
				array(
					'taxonomies'        => array( $case['publicTaxonomy'], $case['privateTaxonomy'] ),
					'termIds'           => array_keys( $term_ids ),
					'privateWithoutCap' => $private_term_without_cap,
					'privateWithCap'    => $private_term_with_cap,
				)
			);
		} finally {
			\remove_filter( 'user_has_cap', $cap_filter, 10 );

			foreach ( array( $case['metaKey'], $case['hiddenMetaKey'], $case['protectedMetaKey'] ) as $meta_key ) {
				\unregister_meta_key( 'post', $meta_key, 'post' );
			}
			\unregister_meta_key( 'post', $case['globalMetaKey'], '' );
			if ( $had_meta_keys ) {
				$GLOBALS['wp_meta_keys'] = $previous_meta_keys;
			} else {
				unset( $GLOBALS['wp_meta_keys'] );
			}

			foreach ( array( $case['publicTaxonomy'], $case['privateTaxonomy'] ) as $taxonomy ) {
				if ( \taxonomy_exists( $taxonomy ) ) {
					\unregister_taxonomy( $taxonomy );
				}
			}
			if ( $had_taxonomies ) {
				$GLOBALS['wp_taxonomies'] = $previous_taxonomies;
			} else {
				unset( $GLOBALS['wp_taxonomies'] );
			}

			self::set_object_property( $binding_registry, 'sources', $previous_sources );

			foreach ( $post_ids as $post_id ) {
				\clean_post_cache( $post_id );
			}
			foreach ( $term_ids as $term_id => $taxonomy ) {
				\clean_term_cache( (int) $term_id, $taxonomy, false );
			}
			if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
				$GLOBALS['wpdb']->component_fuzz_reset_content();
			}

			if ( $had_rewrite ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}
		}

		$counts = isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
			? $GLOBALS['wpdb']->component_fuzz_content_counts()
			: array();

		self::collect_failure(
			$failures,
			false === \has_filter( 'user_has_cap', $cap_filter )
				&& array() === array_filter( $counts ),
			'built-in block binding source fixture removes filters, posts, terms, and metadata',
			array(
				'capFilter'     => \has_filter( 'user_has_cap', $cap_filter ),
				'contentCounts' => $counts,
			)
		);

		return self::result(
			$ctx,
			'blocks.block-bindings.builtin-sources',
			array() === $failures,
			array(
				'case'     => array_diff_key(
					$case,
					array(
						'patternContent'     => true,
						'termDescription'    => true,
						'privateTermDescription' => true,
					)
				),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_metadata_and_pattern_categories( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures          = array();
		$category_registry = \WP_Block_Pattern_Categories_Registry::get_instance();

		foreach ( self::metadata_cases( $ctx->fork( 'metadata' ) ) as $index => $case ) {
			$dir = self::write_metadata_block_files( $case );
			try {
				$block      = \register_block_type_from_metadata( $dir );
				$prepared   = $block instanceof \WP_Block_Type
					? $block->prepare_attributes_for_render( array( 'message' => array( 'invalid' ) ) )
					: array();
				$variations = $block instanceof \WP_Block_Type ? $block->get_variations() : array();

				self::collect_failure(
					$failures,
					$block instanceof \WP_Block_Type
						&& $case['name'] === $block->name
						&& 3 === $block->api_version
						&& $case['title'] === $block->title
						&& $case['message'] === $prepared['message']
						&& array( 'core/paragraph', 'core/image' ) === $block->allowed_blocks
						&& array( 'postId', 'componentFuzz/context' ) === $block->uses_context
						&& array( 'componentFuzz/message' => 'message' ) === $block->provides_context
						&& array( 'core/post-content' => 'after' ) === $block->block_hooks
						&& isset( $block->selectors['root'] )
						&& is_array( $block->styles )
						&& 1 === count( $block->styles )
						&& is_array( $variations )
						&& isset( $variations[0]['name'], $variations[0]['attributes']['message'] )
						&& $case['variationName'] === $variations[0]['name']
						&& $case['message'] === $variations[0]['attributes']['message'],
					"register_block_type_from_metadata maps block.json fields case {$index}",
					array(
						'case'       => $case,
						'prepared'   => $prepared,
						'variations' => $variations,
					)
				);

				if ( $block instanceof \WP_Block_Type ) {
					\unregister_block_type( $case['name'] );
				}
			} finally {
				self::remove_directory( $dir );
			}

			$category_registered = $category_registry->register(
				$case['categoryName'],
				array(
					'label'       => $case['categoryLabel'],
					'description' => $case['categoryDescription'],
				)
			);
			$category            = $category_registry->get_registered( $case['categoryName'] );
			$all_categories      = $category_registry->get_all_registered();
			$category_removed    = $category_registry->unregister( $case['categoryName'] );
			$category_names      = array();
			foreach ( $all_categories as $entry ) {
				if ( isset( $entry['name'] ) ) {
					$category_names[] = $entry['name'];
				}
			}

			self::collect_failure(
				$failures,
				true === $category_registered
					&& is_array( $category )
					&& $case['categoryName'] === $category['name']
					&& $case['categoryLabel'] === $category['label']
					&& in_array( $case['categoryName'], $category_names, true )
					&& true === $category_removed
					&& ! $category_registry->is_registered( $case['categoryName'] ),
				"WP_Block_Pattern_Categories_Registry lifecycle case {$index}",
				array(
					'case'     => $case,
					'category' => $category,
				)
			);
		}

		return self::result(
			$ctx,
			'blocks.metadata.pattern-categories',
			array() === $failures,
			array(
				'cases'    => self::CASES,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_block_hooks_insertion_and_metadata( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::block_hooks_case( $ctx->fork( 'block-hooks' ) );
		$events   = array();

		$filter_hooked_block_types = static function ( array $hooked_block_types, string $relative_position, string $anchor_block_type, $context ) use ( $case, &$events ): array {
			$events[] = array(
				'filter'   => 'hooked_block_types',
				'position' => $relative_position,
				'anchor'   => $anchor_block_type,
				'context'  => is_array( $context ) ? ( $context['componentFuzzContext'] ?? null ) : null,
				'types'    => array_values( $hooked_block_types ),
			);

			if ( $case['anchorName'] === $anchor_block_type && 'before' === $relative_position ) {
				if ( ! in_array( $case['suppressedName'], $hooked_block_types, true ) ) {
					$hooked_block_types[] = $case['suppressedName'];
				}
				if ( ! in_array( $case['filterName'], $hooked_block_types, true ) ) {
					$hooked_block_types[] = $case['filterName'];
				}
			}

			return array_values( $hooked_block_types );
		};
		$filter_hooked_block       = static function ( $parsed_hooked_block, string $hooked_block_type, string $relative_position, array $parsed_anchor_block, $context ) use ( $case, &$events ) {
			$events[] = array(
				'filter'   => 'hooked_block',
				'type'     => $hooked_block_type,
				'position' => $relative_position,
				'anchor'   => $parsed_anchor_block['blockName'] ?? null,
				'context'  => is_array( $context ) ? ( $context['componentFuzzContext'] ?? null ) : null,
			);

			if ( $case['suppressedName'] === $hooked_block_type ) {
				return null;
			}

			if ( is_array( $parsed_hooked_block ) ) {
				$parsed_hooked_block['attrs']['cfzToken']    = $case['token'];
				$parsed_hooked_block['attrs']['cfzPosition'] = $relative_position;
				$parsed_hooked_block['attrs']['cfzAnchor']   = $parsed_anchor_block['blockName'] ?? '';
			}

			return $parsed_hooked_block;
		};

		foreach ( $case['registrations'] as $name => $args ) {
			\register_block_type( $name, $args );
		}

		\add_filter( 'hooked_block_types', $filter_hooked_block_types, 10, 4 );
		\add_filter( 'hooked_block', $filter_hooked_block, 10, 5 );

		try {
			$hooked_blocks = \get_hooked_blocks();
			$content       = \serialize_blocks( array( $case['anchorBlock'] ) );
			$context       = array( 'componentFuzzContext' => $case['token'] );
			$augmented     = \apply_block_hooks_to_content( $content, $context );
			$augmented_tree = \parse_blocks( $augmented );
			$anchor_after  = self::find_first_block( $augmented_tree, $case['anchorName'] );
			$inner_names   = is_array( $anchor_after ) ? self::block_names( $anchor_after['innerBlocks'] ?? array() ) : array();
			$top_names     = self::block_names( $augmented_tree );
			$before_block  = self::find_first_block( $augmented_tree, $case['beforeName'] );
			$filter_block  = self::find_first_block( $augmented_tree, $case['filterName'] );
			$after_block   = self::find_first_block( $augmented_tree, $case['afterName'] );
			$single_block  = self::find_first_block( $augmented_tree, $case['singleName'] );
			$first_block   = self::find_first_block( $augmented_tree, $case['firstName'] );
			$last_block    = self::find_first_block( $augmented_tree, $case['lastName'] );

			$existing_content = \serialize_blocks(
				array(
					$case['anchorBlock'],
					self::parsed_block( $case['singleName'], array( 'existing' => true ), array(), array() ),
				)
			);
			$existing_augmented = \apply_block_hooks_to_content( $existing_content, $context );

			$metadata_content = \apply_block_hooks_to_content( $content, $context, 'set_ignored_hooked_blocks_metadata' );
			$metadata_tree    = \parse_blocks( $metadata_content );
			$metadata_anchor  = self::find_first_block( $metadata_tree, $case['anchorName'] );
			$ignored          = is_array( $metadata_anchor )
				? ( $metadata_anchor['attrs']['metadata']['ignoredHookedBlocks'] ?? array() )
				: array();
			$expected_hooked_blocks = array(
				'before'      => array( $case['beforeName'], $case['ignoredBeforeName'] ),
				'after'       => array( $case['afterName'], $case['singleName'] ),
				'first_child' => array( $case['firstName'] ),
				'last_child'  => array( $case['lastName'] ),
			);
			$expected_ignored       = array(
				$case['beforeName'],
				$case['ignoredBeforeName'],
				$case['filterName'],
				$case['afterName'],
				$case['singleName'],
				$case['firstName'],
				$case['lastName'],
			);

			self::collect_failure(
				$failures,
				self::hooked_blocks_match( $hooked_blocks[ $case['anchorName'] ] ?? array(), $expected_hooked_blocks ),
				'get_hooked_blocks groups registered hooked blocks by anchor and relative position',
				array(
					'case'                 => $case,
					'expectedHookedBlocks' => $expected_hooked_blocks,
					'hookedBlocks'         => $hooked_blocks[ $case['anchorName'] ] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				! str_contains( $augmented, '<!-- wp:' . $case['ignoredBeforeName'] )
					&& ! str_contains( $augmented, '<!-- wp:' . $case['suppressedName'] )
					&& array( $case['beforeName'], $case['filterName'], $case['anchorName'], $case['afterName'], $case['singleName'] ) === $top_names
					&& array( $case['firstName'], 'core/paragraph', $case['lastName'] ) === $inner_names
					&& 1 === self::block_comment_count( $augmented, $case['beforeName'] )
					&& 1 === self::block_comment_count( $augmented, $case['filterName'] )
					&& 1 === self::block_comment_count( $augmented, $case['afterName'] )
					&& 1 === self::block_comment_count( $augmented, $case['singleName'] )
					&& 1 === self::block_comment_count( $augmented, $case['firstName'] )
					&& 1 === self::block_comment_count( $augmented, $case['lastName'] )
					&& self::hooked_block_attrs_match( $before_block, $case, 'before' )
					&& self::hooked_block_attrs_match( $filter_block, $case, 'before' )
					&& self::hooked_block_attrs_match( $after_block, $case, 'after' )
					&& self::hooked_block_attrs_match( $single_block, $case, 'after' )
					&& self::hooked_block_attrs_match( $first_block, $case, 'first_child' )
					&& self::hooked_block_attrs_match( $last_block, $case, 'last_child' )
					&& self::hooked_block_types_event_matches( $events, $case['anchorName'], 'before', $case['token'], array( $case['beforeName'], $case['ignoredBeforeName'] ) )
					&& self::hooked_block_event_matches( $events, $case['filterName'], 'before', $case['anchorName'], $case['token'] ),
				'apply_block_hooks_to_content inserts non-ignored hooks at before/after and child boundaries with filter-mutated attrs',
				array(
					'content'       => $content,
					'augmented'     => $augmented,
					'topNames'      => $top_names,
					'innerNames'    => $inner_names,
					'parsedAttrs'   => array(
						'before' => is_array( $before_block ) ? ( $before_block['attrs'] ?? array() ) : null,
						'filter' => is_array( $filter_block ) ? ( $filter_block['attrs'] ?? array() ) : null,
						'after'  => is_array( $after_block ) ? ( $after_block['attrs'] ?? array() ) : null,
						'single' => is_array( $single_block ) ? ( $single_block['attrs'] ?? array() ) : null,
						'first'  => is_array( $first_block ) ? ( $first_block['attrs'] ?? array() ) : null,
						'last'   => is_array( $last_block ) ? ( $last_block['attrs'] ?? array() ) : null,
					),
					'events'        => $events,
				)
			);

			self::collect_failure(
				$failures,
				1 === self::block_comment_count( $existing_augmented, $case['singleName'] )
					&& 1 === self::block_comment_count( $existing_augmented, $case['afterName'] ),
				'single-instance hooked blocks are not duplicated when already present in content',
				array(
					'existingAugmented' => $existing_augmented,
					'singleCount'       => self::block_comment_count( $existing_augmented, $case['singleName'] ),
					'afterCount'        => self::block_comment_count( $existing_augmented, $case['afterName'] ),
				)
			);

			self::collect_failure(
				$failures,
				self::string_lists_match_unordered( $ignored, $expected_ignored )
					&& 0 === self::block_comment_count( $metadata_content, $case['beforeName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['ignoredBeforeName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['filterName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['afterName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['singleName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['firstName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['lastName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['suppressedName'] ),
				'set_ignored_hooked_blocks_metadata records hookable block types without emitting hooked markup',
				array(
					'metadataContent' => $metadata_content,
					'expectedIgnored' => $expected_ignored,
					'ignored'         => $ignored,
				)
			);
		} finally {
			\remove_filter( 'hooked_block', $filter_hooked_block, 10 );
			\remove_filter( 'hooked_block_types', $filter_hooked_block_types, 10 );
			foreach ( array_keys( $case['registrations'] ) as $name ) {
				\unregister_block_type( $name );
			}
		}

		return self::result(
			$ctx,
			'blocks.hooks.insertion-and-ignored-metadata',
			array() === $failures,
			array(
				'case'     => array_diff_key( $case, array( 'anchorBlock' => true, 'registrations' => true ) ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_block_hooks_post_object_and_rest_response( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures                       = array();
		$case                           = self::block_hooks_case( $ctx->fork( 'post-object' ) );
		$case                          += self::block_hooks_post_object_names( $ctx->fork( 'wrapper' ) );
		$events                         = array();
		$post_id                        = 0;
		$had_rewrite                    = array_key_exists( 'wp_rewrite', $GLOBALS );
		$previous_rewrite               = $GLOBALS['wp_rewrite'] ?? null;
		$previous_content_hook_priority = \has_filter( 'the_content', 'apply_block_hooks_to_content_from_post_object' );

		$case['registrations'] += array(
			$case['rootFirstName']   => array(
				'title'       => 'Component Fuzz Root First Child',
				'api_version' => 3,
				'block_hooks' => array( 'core/post-content' => 'first_child' ),
			),
			$case['rootIgnoredName'] => array(
				'title'       => 'Component Fuzz Root Ignored Child',
				'api_version' => 3,
				'block_hooks' => array( 'core/post-content' => 'first_child' ),
			),
			$case['rootLastName']    => array(
				'title'       => 'Component Fuzz Root Last Child',
				'api_version' => 3,
				'block_hooks' => array( 'core/post-content' => 'last_child' ),
			),
			$case['rootBeforeName']  => array(
				'title'       => 'Component Fuzz Root Before Suppressed',
				'api_version' => 3,
				'block_hooks' => array( 'core/post-content' => 'before' ),
			),
			$case['rootAfterName']   => array(
				'title'       => 'Component Fuzz Root After Suppressed',
				'api_version' => 3,
				'block_hooks' => array( 'core/post-content' => 'after' ),
			),
		);

		$filter_hooked_block_types = static function ( array $hooked_block_types, string $relative_position, string $anchor_block_type, $context ) use ( $case, &$events ): array {
			$events[] = array(
				'filter'   => 'hooked_block_types',
				'position' => $relative_position,
				'anchor'   => $anchor_block_type,
				'context'  => $context instanceof \WP_Post ? array( 'postId' => (int) $context->ID, 'postType' => $context->post_type ) : null,
				'types'    => array_values( $hooked_block_types ),
			);

			if ( $case['anchorName'] === $anchor_block_type && 'before' === $relative_position ) {
				if ( ! in_array( $case['filterName'], $hooked_block_types, true ) ) {
					$hooked_block_types[] = $case['filterName'];
				}
			}

			return array_values( $hooked_block_types );
		};
		$filter_hooked_block       = static function ( $parsed_hooked_block, string $hooked_block_type, string $relative_position, array $parsed_anchor_block, $context ) use ( $case, &$events ) {
			$events[] = array(
				'filter'   => 'hooked_block',
				'type'     => $hooked_block_type,
				'position' => $relative_position,
				'anchor'   => $parsed_anchor_block['blockName'] ?? null,
				'context'  => $context instanceof \WP_Post ? array( 'postId' => (int) $context->ID, 'postType' => $context->post_type ) : null,
			);

			if ( is_array( $parsed_hooked_block ) ) {
				$parsed_hooked_block['attrs']['cfzToken']    = $case['token'];
				$parsed_hooked_block['attrs']['cfzPosition'] = $relative_position;
				$parsed_hooked_block['attrs']['cfzAnchor']   = $parsed_anchor_block['blockName'] ?? '';
			}

			return $parsed_hooked_block;
		};
		$render_marker_filter      = static function ( string $content ) use ( $case ): string {
			return $content . '<!-- cfz-rendered-' . esc_attr( $case['token'] ) . ' -->';
		};

		foreach ( $case['registrations'] as $name => $args ) {
			\register_block_type( $name, $args );
		}

		\add_filter( 'hooked_block_types', $filter_hooked_block_types, 10, 4 );
		\add_filter( 'hooked_block', $filter_hooked_block, 10, 5 );
		if ( false === $previous_content_hook_priority ) {
			\add_filter( 'the_content', 'apply_block_hooks_to_content_from_post_object', 8 );
		}
		\add_filter( 'the_content', $render_marker_filter, 12 );

		try {
			$GLOBALS['wp_rewrite'] = class_exists( 'WP_Rewrite' )
				? new \WP_Rewrite()
				: (object) array( 'feeds' => array( 'feed', 'rdf', 'rss', 'rss2', 'atom' ) );

			$content = \serialize_blocks( array( $case['anchorBlock'] ) );
			$post_id = \wp_insert_post(
				\wp_slash(
					array(
						'post_type'    => 'post',
						'post_status'  => 'publish',
						'post_title'   => 'Block Hooks Post Object ' . $case['token'],
						'post_name'    => 'block-hooks-post-object-' . $case['token'],
						'post_content' => $content,
						'guid'         => 'http://example.test/block-hooks-post-object-' . $case['token'],
					)
				),
				true,
				false
			);

			if ( \is_wp_error( $post_id ) ) {
				throw new \RuntimeException( 'Could not insert block hooks fixture post: ' . $post_id->get_error_message() );
			}

			\update_post_meta( (int) $post_id, '_wp_ignored_hooked_blocks', \wp_json_encode( array( $case['rootIgnoredName'] ) ) );
			$post = \get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				throw new \RuntimeException( 'Could not read block hooks fixture post.' );
			}

			$ignored_at_root = array();
			$post_augmented  = \apply_block_hooks_to_content_from_post_object( $content, $post, 'insert_hooked_blocks_and_set_ignored_hooked_blocks_metadata', $ignored_at_root );
			$post_tree       = \parse_blocks( $post_augmented );
			$post_anchor     = self::find_first_block( $post_tree, $case['anchorName'] );
			$post_top_names  = self::block_names( $post_tree );
			$post_root_first = self::find_first_block( $post_tree, $case['rootFirstName'] );
			$post_root_last  = self::find_first_block( $post_tree, $case['rootLastName'] );

			$prepared = \update_ignored_hooked_blocks_postmeta(
				(object) array(
					'ID'           => (int) $post_id,
					'post_type'    => 'post',
					'post_content' => $content,
				)
			);
			$prepared_tree       = \parse_blocks( (string) ( $prepared->post_content ?? '' ) );
			$prepared_anchor     = self::find_first_block( $prepared_tree, $case['anchorName'] );
			$prepared_root_meta  = self::json_list( $prepared->meta_input['_wp_ignored_hooked_blocks'] ?? '' );
			$prepared_anchor_meta = is_array( $prepared_anchor )
				? ( $prepared_anchor['attrs']['metadata']['ignoredHookedBlocks'] ?? array() )
				: array();

			$response = new \WP_REST_Response(
				array(
					'content' => array(
						'raw'      => $content,
						'rendered' => '<p>placeholder</p>',
					),
					'meta'    => array(
						'component_fuzz_existing' => $case['token'],
					),
				)
			);
			$response       = \insert_hooked_blocks_into_rest_response( $response, $post );
			$response_data  = $response instanceof \WP_REST_Response ? $response->get_data() : array();
			$raw            = (string) ( $response_data['content']['raw'] ?? '' );
			$rendered       = (string) ( $response_data['content']['rendered'] ?? '' );
			$response_tree  = \parse_blocks( $raw );
			$response_anchor = self::find_first_block( $response_tree, $case['anchorName'] );
			$response_anchor_meta = is_array( $response_anchor )
				? ( $response_anchor['attrs']['metadata']['ignoredHookedBlocks'] ?? array() )
				: array();
			$response_root_meta = self::json_list( $response_data['meta']['_wp_ignored_hooked_blocks'] ?? '' );

			$expected_post_top_names = array(
				$case['rootFirstName'],
				$case['beforeName'],
				$case['filterName'],
				$case['anchorName'],
				$case['afterName'],
				$case['singleName'],
				$case['rootLastName'],
			);
			$expected_root_ignored   = array( $case['rootIgnoredName'], $case['rootFirstName'], $case['rootLastName'] );
			$expected_anchor_ignored = array(
				$case['beforeName'],
				$case['ignoredBeforeName'],
				$case['filterName'],
				$case['afterName'],
				$case['singleName'],
				$case['firstName'],
				$case['lastName'],
			);
			$expected_rest_anchor_ignored = array(
				$case['beforeName'],
				$case['ignoredBeforeName'],
				$case['filterName'],
				$case['afterName'],
				$case['firstName'],
				$case['lastName'],
			);

			self::collect_failure(
				$failures,
				self::block_hooks_post_object_markup_matches( $post_augmented, $case ),
				'apply_block_hooks_to_content_from_post_object inserts expected hooked block markup and suppresses ignored hooks',
				array(
					'counts' => self::block_comment_counts(
						$post_augmented,
						array(
							$case['rootFirstName'],
							$case['rootIgnoredName'],
							$case['rootBeforeName'],
							$case['rootAfterName'],
							$case['beforeName'],
							$case['ignoredBeforeName'],
							$case['filterName'],
							$case['anchorName'],
							$case['afterName'],
							$case['singleName'],
							$case['firstName'],
							$case['lastName'],
							$case['rootLastName'],
						)
					),
				)
			);

			self::collect_failure(
				$failures,
				self::string_lists_match_unordered( $ignored_at_root, $expected_root_ignored ),
				'apply_block_hooks_to_content_from_post_object reports wrapper root ignored metadata by reference',
				array(
					'expected' => $expected_root_ignored,
					'actual'   => $ignored_at_root,
				)
			);

			self::collect_failure(
				$failures,
				$expected_post_top_names === $post_top_names,
				'apply_block_hooks_to_content_from_post_object removes the temporary wrapper and preserves top-level ordering',
				array(
					'expected' => $expected_post_top_names,
					'actual'   => $post_top_names,
				)
			);

			self::collect_failure(
				$failures,
				self::hooked_block_attrs_match( $post_root_first, $case, 'first_child', 'core/post-content' )
					&& self::hooked_block_attrs_match( $post_root_last, $case, 'last_child', 'core/post-content' ),
				'apply_block_hooks_to_content_from_post_object passes wrapper anchor context through hooked_block attributes',
				array(
					'rootFirstAttrs' => is_array( $post_root_first ) ? ( $post_root_first['attrs'] ?? array() ) : null,
					'rootLastAttrs'  => is_array( $post_root_last ) ? ( $post_root_last['attrs'] ?? array() ) : null,
				)
			);

			self::collect_failure(
				$failures,
				self::hooked_block_types_event_matches_for_post(
					$events,
					(int) $post_id,
					'core/post-content',
					'first_child',
					array( $case['rootFirstName'], $case['rootIgnoredName'] )
				),
				'apply_block_hooks_to_content_from_post_object invokes root first_child hooked_block_types with the fixture WP_Post context',
				array(
					'anchorMetadata' => is_array( $post_anchor ) ? ( $post_anchor['attrs']['metadata']['ignoredHookedBlocks'] ?? array() ) : null,
					'events'         => array_slice( $events, 0, 18 ),
				)
			);

			self::collect_failure(
				$failures,
				self::hooked_block_types_event_matches_for_post(
					$events,
					(int) $post_id,
					'core/post-content',
					'last_child',
					array( $case['rootLastName'] )
				),
				'apply_block_hooks_to_content_from_post_object invokes root last_child hooked_block_types with the fixture WP_Post context',
				array( 'events' => array_slice( $events, 0, 18 ) )
			);

			self::collect_failure(
				$failures,
				self::hooked_block_types_event_matches_for_post(
					$events,
					(int) $post_id,
					'core/post-content',
					'before',
					array( $case['rootBeforeName'] )
				)
					&& self::hooked_block_types_event_matches_for_post(
						$events,
						(int) $post_id,
						'core/post-content',
						'after',
						array( $case['rootAfterName'] )
					),
				'apply_block_hooks_to_content_from_post_object exposes wrapper before/after hook types to filters before suppressing emitted markup',
				array( 'events' => array_slice( $events, 0, 18 ) )
			);

			self::collect_failure(
				$failures,
				self::hooked_block_event_matches_for_post(
					$events,
					(int) $post_id,
					$case['filterName'],
					'before',
					$case['anchorName']
				),
				'apply_block_hooks_to_content_from_post_object invokes hooked_block for filtered inner anchor hook with the fixture WP_Post context',
				array( 'events' => array_slice( $events, 0, 18 ) )
			);

			self::collect_failure(
				$failures,
				self::string_lists_match_unordered(
					$prepared_root_meta,
					array(
						$case['rootIgnoredName'],
						$case['rootBeforeName'],
						$case['rootFirstName'],
						$case['rootLastName'],
						$case['rootAfterName'],
					)
				)
					&& self::string_lists_match_unordered(
						$prepared_anchor_meta,
						array(
							$case['beforeName'],
							$case['ignoredBeforeName'],
							$case['filterName'],
							$case['afterName'],
							$case['singleName'],
							$case['firstName'],
							$case['lastName'],
						)
					)
					&& 0 === self::block_comment_count( (string) ( $prepared->post_content ?? '' ), $case['rootFirstName'] )
					&& 0 === self::block_comment_count( (string) ( $prepared->post_content ?? '' ), $case['rootLastName'] )
					&& 0 === self::block_comment_count( (string) ( $prepared->post_content ?? '' ), $case['beforeName'] )
					&& 0 === self::block_comment_count( (string) ( $prepared->post_content ?? '' ), $case['afterName'] ),
				'update_ignored_hooked_blocks_postmeta stores root ignored metadata and mutates anchor metadata without inserting hooked markup',
				array(
					'preparedRootMeta'   => $prepared_root_meta,
					'preparedAnchorMeta' => $prepared_anchor_meta,
					'preparedContent'    => (string) ( $prepared->post_content ?? '' ),
				)
			);

			self::collect_failure(
				$failures,
				$response instanceof \WP_REST_Response
					&& self::block_hooks_post_object_markup_matches( $raw, $case ),
				'insert_hooked_blocks_into_rest_response mutates content.raw with post-object hooked block markup',
				array(
					'rawCounts' => self::block_comment_counts(
						$raw,
						array(
							$case['rootFirstName'],
							$case['rootIgnoredName'],
							$case['rootBeforeName'],
							$case['rootAfterName'],
							$case['beforeName'],
							$case['ignoredBeforeName'],
							$case['filterName'],
							$case['anchorName'],
							$case['afterName'],
							$case['singleName'],
							$case['firstName'],
							$case['lastName'],
							$case['rootLastName'],
						)
					),
				)
			);

			self::collect_failure(
				$failures,
				self::string_lists_match_unordered( $response_root_meta, $expected_root_ignored )
					&& $case['token'] === ( $response_data['meta']['component_fuzz_existing'] ?? null ),
				'insert_hooked_blocks_into_rest_response stores root ignored metadata while preserving existing response meta',
				array(
					'responseRootMeta' => $response_root_meta,
					'expectedRootMeta' => $expected_root_ignored,
					'meta'             => $response_data['meta'] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				is_array( $response_anchor )
					&& self::string_lists_match_unordered( $response_anchor_meta, $expected_rest_anchor_ignored )
					&& 1 === self::block_comment_count( $raw, $case['singleName'] ),
				'insert_hooked_blocks_into_rest_response mutates inner anchor ignored metadata in content.raw',
				array(
					'delta'              => self::string_list_delta( $expected_rest_anchor_ignored, $response_anchor_meta ),
					'responseAnchorMeta' => $response_anchor_meta,
				)
			);

			self::collect_failure(
				$failures,
				str_contains( $rendered, '<!-- cfz-rendered-' . $case['token'] . ' -->' )
					&& 1 === self::block_comment_count( $rendered, $case['rootFirstName'] )
					&& 1 === self::block_comment_count( $rendered, $case['rootLastName'] ),
				'insert_hooked_blocks_into_rest_response refreshes non-empty rendered content from mutated raw content exactly once',
				array(
					'renderedCounts' => self::block_comment_counts( $rendered, array( $case['rootFirstName'], $case['rootLastName'] ) ),
					'hasMarker'      => str_contains( $rendered, '<!-- cfz-rendered-' . $case['token'] . ' -->' ),
				)
			);

			self::collect_failure(
				$failures,
				( false === $previous_content_hook_priority ? 8 : $previous_content_hook_priority ) === \has_filter( 'the_content', 'apply_block_hooks_to_content_from_post_object' ),
				'insert_hooked_blocks_into_rest_response restores the_content block-hooks filter priority',
				array(
					'expectedPriority' => false === $previous_content_hook_priority ? 8 : $previous_content_hook_priority,
					'actualPriority'   => \has_filter( 'the_content', 'apply_block_hooks_to_content_from_post_object' ),
				)
			);
		} finally {
			\remove_filter( 'the_content', $render_marker_filter, 12 );
			if ( false === $previous_content_hook_priority ) {
				\remove_filter( 'the_content', 'apply_block_hooks_to_content_from_post_object', 8 );
			}
			\remove_filter( 'hooked_block', $filter_hooked_block, 10 );
			\remove_filter( 'hooked_block_types', $filter_hooked_block_types, 10 );

			if ( is_int( $post_id ) && $post_id > 0 ) {
				\wp_delete_post( $post_id, true );
			}

			if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
				$GLOBALS['wpdb']->component_fuzz_reset_content();
			}

			foreach ( array_keys( $case['registrations'] ) as $name ) {
				\unregister_block_type( $name );
			}

			if ( $had_rewrite ) {
				$GLOBALS['wp_rewrite'] = $previous_rewrite;
			} else {
				unset( $GLOBALS['wp_rewrite'] );
			}
		}

		$counts = isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' )
			? $GLOBALS['wpdb']->component_fuzz_content_counts()
			: array();

		self::collect_failure(
			$failures,
			false === \has_filter( 'hooked_block_types', $filter_hooked_block_types )
				&& false === \has_filter( 'hooked_block', $filter_hooked_block )
				&& false === \has_filter( 'the_content', $render_marker_filter )
				&& $previous_content_hook_priority === \has_filter( 'the_content', 'apply_block_hooks_to_content_from_post_object' )
				&& array() === array_filter( $counts ),
			'block hooks post-object fixture removes filters, registrations, posts, and metadata',
			array(
				'contentCounts' => $counts,
				'filters'       => array(
					'hooked_block_types' => \has_filter( 'hooked_block_types', $filter_hooked_block_types ),
					'hooked_block'       => \has_filter( 'hooked_block', $filter_hooked_block ),
					'the_content_marker' => \has_filter( 'the_content', $render_marker_filter ),
					'the_content_hooks'  => \has_filter( 'the_content', 'apply_block_hooks_to_content_from_post_object' ),
				),
			)
		);

		return self::result(
			$ctx,
			'blocks.hooks.post-object-rest-response-metadata',
			array() === $failures,
			array(
				'case'     => array_diff_key( $case, array( 'anchorBlock' => true, 'registrations' => true ) ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_block_supports( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$supports_api = \WP_Block_Supports::get_instance();
		$case         = self::support_case( $ctx->fork( 'supports' ) );
		$block_type   = \register_block_type(
			$case['blockName'],
			array(
				'title'      => 'Component Fuzz Supports',
				'supports'   => array(
					'componentFuzz' => array(
						'enabled' => true,
					),
				),
				'attributes' => array(),
			)
		);

		$supports_api->register(
			$case['supportName'],
			array(
				'register_attribute' => static function ( \WP_Block_Type $block_type_for_support ) use ( $case ): void {
					$block_type_for_support->attributes[ $case['attributeName'] ] = array(
						'type'    => 'string',
						'default' => $case['token'],
					);
				},
				'apply'              => static function ( \WP_Block_Type $block_type_for_support, array $attributes ) use ( $case ): array {
					if ( ! \block_has_support( $block_type_for_support, array( 'componentFuzz', 'enabled' ), false ) ) {
						return array();
					}

					$token = sanitize_html_class( $attributes[ $case['attributeName'] ] ?? $case['token'] );
					return array(
						'class'      => 'has-cfz-support-' . $token,
						'style'      => 'border-width:' . $case['borderWidth'] . 'px;',
						'id'         => 'generated-' . $token,
						'aria-label' => 'Generated ' . $token,
					);
				},
			)
		);

		\WP_Block_Supports::init();
		\WP_Block_Supports::$block_to_render = array(
			'blockName' => $case['blockName'],
			'attrs'    => array(
				$case['attributeName'] => $case['token'],
			),
		);

		$prepared = $block_type instanceof \WP_Block_Type ? $block_type->prepare_attributes_for_render( array() ) : array();
		$applied  = $supports_api->apply_block_supports();
		$wrapper  = \get_block_wrapper_attributes(
			array(
				'class'      => 'extra-class ' . $case['token'],
				'style'      => 'color: red;',
				'id'         => 'explicit-id',
				'aria-label' => 'Explicit label',
				'data-cfz'   => $case['token'],
				'hidden'     => true,
				'bad'        => array( 'not-rendered' ),
			)
		);
		\WP_Block_Supports::$block_to_render = null;
		$empty_applied                       = $supports_api->apply_block_supports();
		\unregister_block_type( $case['blockName'] );

		self::collect_failure(
			$failures,
			$block_type instanceof \WP_Block_Type
				&& isset( $block_type->attributes[ $case['attributeName'] ] )
				&& $case['token'] === $prepared[ $case['attributeName'] ]
				&& \block_has_support( $block_type, array( 'componentFuzz', 'enabled' ), false )
				&& isset( $applied['class'], $applied['style'], $applied['id'], $applied['aria-label'] )
				&& str_contains( $applied['class'], 'has-cfz-support-' . $case['token'] )
				&& str_contains( $wrapper, 'class="' )
				&& str_contains( $wrapper, 'extra-class' )
				&& str_contains( $wrapper, 'has-cfz-support-' . $case['token'] )
				&& str_contains( $wrapper, 'style="' )
				&& str_contains( $wrapper, 'border-width:' . $case['borderWidth'] . 'px' )
				&& str_contains( $wrapper, 'color: red' )
				&& str_contains( $wrapper, 'id="explicit-id"' )
				&& str_contains( $wrapper, 'aria-label="Explicit label"' )
				&& str_contains( $wrapper, 'data-cfz="' . esc_attr( $case['token'] ) . '"' )
				&& ! str_contains( $wrapper, 'hidden=' )
				&& ! str_contains( $wrapper, 'bad=' )
				&& array() === $empty_applied,
			'WP_Block_Supports registers attributes and merges wrapper attributes',
			array(
				'case'         => $case,
				'prepared'     => $prepared,
				'applied'      => $applied,
				'wrapper'      => $wrapper,
				'emptyApplied' => $empty_applied,
			)
		);

		return self::result(
			$ctx,
			'blocks.supports.wrapper-attributes',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function block_type_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case   = $ctx->fork( 'block-type-' . $i );
			$name   = 'component-fuzz/' . self::slug( $case, 'block' );
			$title  = 'Fuzz Block ' . $case->int( 1, 999 );
			$count  = $case->int( 0, 999 );
			$aligns = array_values( array_unique( array( 'wide', 'full', $case->choice( array( 'left', 'right', 'center' ) ) ) ) );
			$cases[] = array(
				'name'     => $name,
				'defaults' => array(
					'title' => $title,
					'count' => $count,
				),
				'args'     => array(
					'title'           => $title,
					'category'        => 'widgets',
					'description'     => 'Generated block API fuzz case.',
					'keywords'        => array( 'fuzz', self::slug( $case, 'keyword' ) ),
					'api_version'     => 3,
					'attributes'      => array(
						'title'   => array(
							'type'    => 'string',
							'default' => $title,
						),
						'count'   => array(
							'type'    => 'integer',
							'default' => $count,
						),
						'enabled' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'supports'        => array(
						'align'     => $aligns,
						'className' => true,
					),
					'uses_context'    => array( 'postId', 'componentFuzz/' . self::slug( $case, 'context' ) ),
					'provides_context' => array(
						'componentFuzz/value' => 'title',
					),
					'render_callback' => static function ( array $attributes, string $content ) use ( $name ): string {
						return '<div data-block="' . esc_attr( $name ) . '">' . esc_html( (string) ( $attributes['title'] ?? '' ) ) . $content . '</div>';
					},
				),
			);
		}

		return $cases;
	}

	private static function parser_render_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case           = $ctx->fork( 'parser-render-' . $i );
			$token          = self::slug( $case, 'token' );
			$filtered_token = self::slug( $case, 'filtered' );
			$outer_name     = 'component-fuzz/' . self::slug( $case, 'outer' );
			$child_name     = 'component-fuzz/' . self::slug( $case, 'child' );
			$short_name     = 'component-fuzz/' . self::slug( $case, 'short' );
			$unsafe         = '<script data-token="' . $token . '">alert(1)</script>&"';

			$child = self::parsed_block(
				$child_name,
				array(
					'token'  => $token,
					'unsafe' => $unsafe,
				),
				array(),
				array(
					'<span data-cfz-child="' . esc_attr( $token ) . '">Child ' . esc_html( $token ) . '</span>',
				)
			);

			$core_paragraph = self::parsed_block(
				'core/paragraph',
				array(
					'placeholder' => $token,
				),
				array(),
				array(
					'<p>Paragraph ' . esc_html( $token ) . '</p>',
				)
			);

			$short_circuited = self::parsed_block(
				$short_name,
				array(
					'token'  => $token,
					'unsafe' => $unsafe,
				),
				array(),
				array(
					'<p>Short fallback ' . esc_html( $token ) . '</p>',
				)
			);

			$outer = self::parsed_block(
				$outer_name,
				array(
					'token'  => $token,
					'unsafe' => $unsafe,
				),
				array( $child, $core_paragraph, $short_circuited ),
				array(
					'<section data-cfz-before="' . esc_attr( $token ) . '">',
					null,
					'<hr data-cfz-mid="' . esc_attr( $token ) . '">',
					null,
					'<div data-cfz-after-core="' . esc_attr( $token ) . '"></div>',
					null,
					'</section>',
				)
			);

			$cases[] = array(
				'outerName'     => $outer_name,
				'childName'     => $child_name,
				'shortName'     => $short_name,
				'missingName'   => 'component-fuzz/' . self::slug( $case, 'missing' ),
				'token'         => $token,
				'filteredToken' => $filtered_token,
				'unsafe'        => $unsafe,
				'plainText'     => 'Plain generated text without block delimiters ' . $token,
				'blocks'        => array( $outer ),
			);
		}

		return $cases;
	}

	private static function nested_attribute_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case        = $ctx->fork( 'nested-attrs-' . $i );
			$token       = self::slug( $case, 'nested-token' );
			$root_name   = 'component-fuzz/' . self::block_name_slug( $case->fork( 'root' ), 'nested-root' );
			$child_name  = 'component-fuzz/' . self::block_name_slug( $case->fork( 'child' ), 'nested-child' );
			$leaf_name   = 'component-fuzz/' . self::block_name_slug( $case->fork( 'leaf' ), 'nested-leaf' );
			$unsafe_attr = '<!-- component-fuzz ' . $token . ' --> & "quoted" \\ slash';

			$leaf = self::parsed_block(
				$leaf_name,
				array(
					'token'  => $token,
					'nested' => self::nested_attribute_value( $case->fork( 'leaf-attrs' ) ),
				),
				array(),
				array(
					'<span data-cfz-leaf="' . esc_attr( $token ) . '">Leaf ' . esc_html( $token ) . '</span>',
				)
			);

			$child = self::parsed_block(
				$child_name,
				array(
					'token'  => $token,
					'nested' => self::nested_attribute_value( $case->fork( 'child-attrs' ) ),
					'list'   => array(
						self::nested_attribute_value( $case->fork( 'child-list-a' ), 1 ),
						self::nested_attribute_value( $case->fork( 'child-list-b' ), 1 ),
					),
				),
				array( $leaf ),
				array(
					'<div data-cfz-child="' . esc_attr( $token ) . '">',
					null,
					'</div>',
				)
			);

			$root = self::parsed_block(
				$root_name,
				array(
					'token'  => $token,
					'unsafe' => $unsafe_attr,
					'nested' => self::nested_attribute_value( $case->fork( 'root-attrs' ) ),
					'object' => array(
						'alpha' => self::nested_attribute_value( $case->fork( 'root-object-alpha' ), 1 ),
						'beta'  => array(
							'flag'  => $case->bool(),
							'count' => $case->int( -50, 50 ),
							'text'  => self::safe_attribute_text( $case->fork( 'root-object-text' ) ),
						),
					),
				),
				array( $child ),
				array(
					'<section data-cfz-root="' . esc_attr( $token ) . '">',
					null,
					'<p>Tail ' . esc_html( $token ) . '</p>',
					'</section>',
				)
			);

			$cases[] = array(
				'rootName'   => $root_name,
				'childName'  => $child_name,
				'leafName'   => $leaf_name,
				'unsafeAttr' => $unsafe_attr,
				'blocks'     => array( $root ),
			);
		}

		return $cases;
	}

	private static function nested_attribute_value( \ComponentFuzz\FuzzContext $ctx, int $depth = 0 ) {
		if ( $depth >= 3 ) {
			return self::nested_attribute_scalar( $ctx );
		}

		$shape = $ctx->int( 0, 4 );
		if ( 0 === $shape ) {
			return self::nested_attribute_scalar( $ctx->fork( 'scalar' ) );
		}

		$count = $ctx->int( 1, 3 );
		if ( $shape <= 2 ) {
			$list = array();
			for ( $i = 0; $i < $count; ++$i ) {
				$list[] = self::nested_attribute_value( $ctx->fork( 'list-' . $depth . '-' . $i ), $depth + 1 );
			}
			return $list;
		}

		$object = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$key            = 'k' . $i . '-' . self::slug( $ctx->fork( 'key-' . $depth . '-' . $i ), 'key' );
			$object[ $key ] = self::nested_attribute_value( $ctx->fork( 'object-' . $depth . '-' . $i ), $depth + 1 );
		}
		return $object;
	}

	private static function nested_attribute_scalar( \ComponentFuzz\FuzzContext $ctx ) {
		switch ( $ctx->int( 0, 5 ) ) {
			case 0:
				return null;
			case 1:
				return true;
			case 2:
				return false;
			case 3:
				return $ctx->int( -1000, 1000 );
			case 4:
				return self::safe_attribute_text( $ctx->fork( 'text' ) );
			default:
				return array(
					'label' => self::slug( $ctx->fork( 'label' ), 'scalar-label' ),
					'value' => self::safe_attribute_text( $ctx->fork( 'value' ) ),
				);
		}
	}

	private static function safe_attribute_text( \ComponentFuzz\FuzzContext $ctx ): string {
		$text = str_replace(
			array( "\r", "\n", "\t" ),
			' ',
			$ctx->ascii( 0, 18 )
		);

		return 'value-' . self::slug( $ctx, 'attr' ) . '-' . $text;
	}

	private static function block_name_slug( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$slug = self::slug( $ctx, $label );
		while ( str_contains( $slug, '--' ) ) {
			$slug = str_replace( '--', '-', $slug );
		}

		return '' === $slug ? 'fuzz-' . dechex( $ctx->seed() & 0xffff ) : $slug;
	}

	private static function parsed_block( ?string $name, array $attrs, array $inner_blocks, array $inner_content ): array {
		$inner_html = '';
		foreach ( $inner_content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$inner_html .= $chunk;
			}
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		);
	}

	private static function parser_render_attributes(): array {
		return array(
			'token'         => array(
				'type'    => 'string',
				'default' => '',
			),
			'filteredToken' => array(
				'type'    => 'string',
				'default' => '',
			),
			'unsafe'        => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	private static function registry_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case       = $ctx->fork( 'registry-' . $i );
			$style_name = self::slug( $case, 'style' );
			$cases[]    = array(
				'styleBlocks'        => array(
					'component-fuzz/' . self::slug( $case, 'style-block-a' ),
					'component-fuzz/' . self::slug( $case, 'style-block-b' ),
				),
				'styleName'          => $style_name,
				'outline'            => $case->int( 1, 8 ),
				'color'              => $case->choice( array( '#14532d', '#1d4ed8', '#7c2d12', '#4c1d95' ) ),
				'patternName'        => 'component-fuzz/' . self::slug( $case, 'pattern' ),
				'patternTitle'       => 'Pattern ' . $case->int( 1, 999 ),
				'patternDescription' => 'Generated pattern registry fuzz case.',
				'patternContent'     => '<!-- wp:paragraph --><p>' . esc_html( $style_name ) . '</p><!-- /wp:paragraph -->',
				'inserter'           => $case->bool(),
				'bindingName'        => 'component-fuzz/' . self::slug( $case, 'binding' ),
				'bindingLabel'       => 'Binding ' . $case->int( 1, 999 ),
			);
		}

		return $cases;
	}

	private static function block_bindings_render_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case                 = $ctx->fork( 'binding-render-' . $i );
			$token                = self::slug( $case, 'binding-token' );
			$block_name           = 'component-fuzz/' . self::slug( $case->fork( 'block' ), 'binding-block' );
			$source_name          = 'component-fuzz/' . self::slug( $case->fork( 'source' ), 'binding-source' );
			$missing_source       = 'component-fuzz/' . self::slug( $case->fork( 'missing-source' ), 'binding-missing' );
			$fallback_content     = 'Fallback binding content ' . $token;
			$fallback_url         = 'https://fallback.example.test/' . rawurlencode( $token );
			$fallback_unsupported = 'fallback-unsupported-' . $token;
			$source_content       = 'Source content ' . $token;
			$source_url           = 'https://source.example.test/' . rawurlencode( $token );
			$filtered_content     = 'Bound content ' . $token;
			$filtered_url         = 'https://bound.example.test/' . rawurlencode( $token );
			$content_args         = array(
				'kind'  => 'content',
				'token' => $token,
				'index' => $i,
			);
			$url_args             = array(
				'kind'  => 'url',
				'token' => $token,
				'index' => $i,
			);

			$cases[] = array(
				'token'               => $token,
				'contextToken'        => 'context-' . $token,
				'blockName'           => $block_name,
				'sourceName'          => $source_name,
				'missingSource'       => $missing_source,
				'fallbackContent'     => $fallback_content,
				'fallbackUrl'         => $fallback_url,
				'fallbackUnsupported' => $fallback_unsupported,
				'sourceContent'       => $source_content,
				'sourceUrl'           => $source_url,
				'filteredContent'     => $filtered_content,
				'filteredUrl'         => $filtered_url,
				'contentArgs'         => $content_args,
				'urlArgs'             => $url_args,
				'block'               => self::parsed_block(
					$block_name,
					array(
						'metadata'    => array(
							'bindings' => array(
								'content'     => array(
									'source' => $source_name,
									'args'   => $content_args,
								),
								'url'         => array(
									'source' => $source_name,
									'args'   => $url_args,
								),
								'unsupported' => array(
									'source' => $source_name,
									'args'   => array( 'kind' => 'unsupported', 'token' => $token ),
								),
								'missing'     => array(
									'source' => $missing_source,
									'args'   => array( 'kind' => 'missing', 'token' => $token ),
								),
								'malformed'   => array(
									'args' => array( 'kind' => 'malformed', 'token' => $token ),
								),
							),
						),
						'content'     => $fallback_content,
						'url'         => $fallback_url,
						'unsupported' => $fallback_unsupported,
					),
					array(),
					array(
						'<p>' . esc_html( $fallback_content ) . '</p><a href="' . esc_attr( $fallback_url ) . '" title="' . esc_attr( $fallback_unsupported ) . '">Fallback link ' . esc_html( $token ) . '</a>',
					)
				),
			);
		}

		return $cases;
	}

	private static function block_bindings_render_attributes(): array {
		return array(
			'content'     => array(
				'type'     => 'string',
				'source'   => 'rich-text',
				'selector' => 'p',
			),
			'url'         => array(
				'type'      => 'string',
				'source'    => 'attribute',
				'selector'  => 'a',
				'attribute' => 'href',
			),
			'unsupported' => array(
				'type'      => 'string',
				'source'    => 'attribute',
				'selector'  => 'a',
				'attribute' => 'title',
			),
		);
	}

	private static function builtin_block_binding_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::slug( $ctx, 'builtin-binding' );

		return array(
			'token'                  => $token,
			'patternName'            => 'component-fuzz-pattern-' . $token,
			'patternContent'         => 'Pattern override <b>' . $token . '</b> & "quoted"',
			'postDate'               => '2024-01-02 03:04:05',
			'postModified'           => '2024-01-03 04:05:06',
			'metaKey'                => self::short_key( $ctx->fork( 'meta' ), 'cfz_meta' ),
			'globalMetaKey'          => self::short_key( $ctx->fork( 'global-meta' ), 'cfz_global' ),
			'hiddenMetaKey'          => self::short_key( $ctx->fork( 'hidden-meta' ), 'cfz_hidden' ),
			'protectedMetaKey'       => '_' . self::short_key( $ctx->fork( 'protected-meta' ), 'cfz_secret' ),
			'metaValue'              => 'visible-meta-' . $token,
			'globalMetaValue'        => 'global-meta-' . $token,
			'hiddenMetaValue'        => 'hidden-meta-' . $token,
			'protectedMetaValue'     => 'protected-meta-' . $token,
			'privateMetaValue'       => 'private-meta-' . $token,
			'passwordMetaValue'      => 'password-meta-' . $token,
			'publicTaxonomy'         => self::short_key( $ctx->fork( 'public-taxonomy' ), 'cfz_pubtax' ),
			'privateTaxonomy'        => self::short_key( $ctx->fork( 'private-taxonomy' ), 'cfz_prvtax' ),
			'termName'               => 'Term <Fuzz> ' . $token,
			'termSlug'               => 'term-' . $token,
			'termDescription'        => '<strong>Term ' . esc_html( $token ) . '</strong><script>alert(1)</script>',
			'termParent'             => $ctx->int( 2, 25 ),
			'termCount'              => $ctx->int( 3, 30 ),
			'privateTermName'        => 'Private Term ' . $token,
			'privateTermSlug'        => 'private-term-' . $token,
			'privateTermDescription' => 'Private term ' . $token,
		);
	}

	private static function builtin_binding_block( string $name, array $attributes, array $context ): object {
		return (object) array(
			'name'       => $name,
			'attributes' => $attributes,
			'context'    => $context,
		);
	}

	private static function builtin_binding_source_matches( $source, string $name, string $label, string $callback, array $uses_context ): bool {
		return $source instanceof \WP_Block_Bindings_Source
			&& $name === $source->name
			&& $label === $source->label
			&& $callback === self::get_object_property( $source, 'get_value_callback' )
			&& $uses_context === array_values( (array) $source->uses_context );
	}

	private static function insert_builtin_binding_post( array $args ): int {
		$post_id = \wp_insert_post(
			\wp_slash(
				array_merge(
					array(
						'post_type'      => 'post',
						'post_status'    => 'publish',
						'post_content'   => '',
						'post_excerpt'   => '',
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					),
					$args
				)
			),
			true,
			false
		);

		if ( \is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Could not insert built-in block binding fixture post: ' . $post_id->get_error_message() );
		}

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			throw new \RuntimeException( 'Could not insert built-in block binding fixture post.' );
		}

		return $post_id;
	}

	private static function insert_builtin_binding_term( string $taxonomy, string $name, string $slug, string $description, int $parent, int $count ): array {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->insert(
			$wpdb->terms,
			array(
				'name'       => $name,
				'slug'       => $slug,
				'term_group' => 0,
			)
		);
		$term_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id'     => $term_id,
				'taxonomy'    => $taxonomy,
				'description' => $description,
				'parent'      => $parent,
				'count'       => $count,
			)
		);
		$term_taxonomy_id = (int) $wpdb->insert_id;
		\clean_term_cache( $term_id, $taxonomy, false );

		return array(
			'term_id'          => $term_id,
			'term_taxonomy_id' => $term_taxonomy_id,
		);
	}

	private static function metadata_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		for ( $i = 0; $i < self::CASES; ++$i ) {
			$case           = $ctx->fork( 'metadata-' . $i );
			$name           = 'component-fuzz/' . self::slug( $case, 'metadata-block' );
			$variation_name = self::slug( $case, 'variation' );
			$cases[]        = array(
				'name'                => $name,
				'title'               => 'Metadata Block ' . $case->int( 1, 999 ),
				'message'             => 'message-' . self::slug( $case, 'message' ),
				'variationName'       => $variation_name,
				'categoryName'        => 'component-fuzz-' . self::slug( $case, 'category' ),
				'categoryLabel'       => 'Category ' . $case->int( 1, 999 ),
				'categoryDescription' => 'Generated pattern category fuzz case.',
			);
		}

		return $cases;
	}

	private static function block_hooks_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token               = self::slug( $ctx, 'hook-token' );
		$anchor_name         = 'component-fuzz/' . self::slug( $ctx->fork( 'anchor' ), 'hook-anchor' );
		$before_name         = 'component-fuzz/' . self::slug( $ctx->fork( 'before' ), 'hook-before' );
		$ignored_before_name = 'component-fuzz/' . self::slug( $ctx->fork( 'ignored-before' ), 'hook-ignored-before' );
		$filter_name         = 'component-fuzz/' . self::slug( $ctx->fork( 'filter' ), 'hook-filter' );
		$after_name          = 'component-fuzz/' . self::slug( $ctx->fork( 'after' ), 'hook-after' );
		$first_name          = 'component-fuzz/' . self::slug( $ctx->fork( 'first' ), 'hook-first' );
		$last_name           = 'component-fuzz/' . self::slug( $ctx->fork( 'last' ), 'hook-last' );
		$single_name         = 'component-fuzz/' . self::slug( $ctx->fork( 'single' ), 'hook-single' );
		$suppressed_name     = 'component-fuzz/' . self::slug( $ctx->fork( 'suppressed' ), 'hook-suppressed' );

		$anchor_block = self::parsed_block(
			$anchor_name,
			array(
				'metadata' => array(
					'ignoredHookedBlocks' => array( $ignored_before_name ),
				),
				'token'    => $token,
			),
			array(
				self::parsed_block(
					'core/paragraph',
					array(),
					array(),
					array( '<p>Hook anchor child ' . esc_html( $token ) . '</p>' )
				),
			),
			array(
				'<section data-cfz-hook-anchor="' . esc_attr( $token ) . '">',
				null,
				'</section>',
			)
		);

		$registrations = array(
			$anchor_name         => array(
				'title'       => 'Component Fuzz Hook Anchor',
				'api_version' => 3,
				'attributes'  => array(
					'token' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			),
			$before_name         => array(
				'title'       => 'Component Fuzz Hook Before',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'before' ),
			),
			$ignored_before_name => array(
				'title'       => 'Component Fuzz Hook Ignored Before',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'before' ),
			),
			$filter_name         => array(
				'title'       => 'Component Fuzz Hook Filter',
				'api_version' => 3,
			),
			$after_name          => array(
				'title'       => 'Component Fuzz Hook After',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'after' ),
			),
			$first_name          => array(
				'title'       => 'Component Fuzz Hook First Child',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'first_child' ),
			),
			$last_name           => array(
				'title'       => 'Component Fuzz Hook Last Child',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'last_child' ),
			),
			$single_name         => array(
				'title'       => 'Component Fuzz Hook Single',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'after' ),
				'supports'    => array(
					'multiple' => false,
				),
			),
			$suppressed_name     => array(
				'title'       => 'Component Fuzz Hook Suppressed',
				'api_version' => 3,
				'supports'    => array(
					'multiple' => true,
				),
			),
		);

		return array(
			'token'             => $token,
			'anchorName'        => $anchor_name,
			'beforeName'        => $before_name,
			'ignoredBeforeName' => $ignored_before_name,
			'filterName'        => $filter_name,
			'afterName'         => $after_name,
			'firstName'         => $first_name,
			'lastName'          => $last_name,
			'singleName'        => $single_name,
			'suppressedName'    => $suppressed_name,
			'anchorBlock'       => $anchor_block,
			'registrations'     => $registrations,
		);
	}

	private static function block_hooks_post_object_names( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'rootFirstName'   => 'component-fuzz/' . self::slug( $ctx->fork( 'root-first' ), 'hook-root-first' ),
			'rootIgnoredName' => 'component-fuzz/' . self::slug( $ctx->fork( 'root-ignored' ), 'hook-root-ignored' ),
			'rootLastName'    => 'component-fuzz/' . self::slug( $ctx->fork( 'root-last' ), 'hook-root-last' ),
			'rootBeforeName'  => 'component-fuzz/' . self::slug( $ctx->fork( 'root-before' ), 'hook-root-before' ),
			'rootAfterName'   => 'component-fuzz/' . self::slug( $ctx->fork( 'root-after' ), 'hook-root-after' ),
		);
	}

	private static function write_metadata_block_files( array $case ): string {
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'component-fuzz-blocks-' . getmypid() . '-' . substr( sha1( $case['name'] ), 0, 12 );
		if ( is_dir( $dir ) ) {
			self::remove_directory( $dir );
		}
		if ( ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Failed to create metadata block directory.' );
		}

		$metadata = array(
			'apiVersion'      => 3,
			'name'            => $case['name'],
			'title'           => $case['title'],
			'category'        => 'widgets',
			'description'     => 'Generated metadata block fuzz case.',
			'attributes'      => array(
				'message' => array(
					'type'    => 'string',
					'default' => $case['message'],
				),
			),
			'usesContext'     => array( 'postId', 'componentFuzz/context' ),
			'providesContext' => array(
				'componentFuzz/message' => 'message',
			),
			'selectors'       => array(
				'root' => '.wp-block-component-fuzz-metadata',
			),
			'supports'        => array(
				'align'     => array( 'wide', 'full' ),
				'className' => true,
			),
			'styles'          => array(
				array(
					'name'  => 'plain',
					'label' => 'Plain',
				),
			),
			'variations'      => 'variations.php',
			'allowedBlocks'   => array( 'core/paragraph', 'core/image' ),
			'blockHooks'      => array(
				'core/post-content' => 'after',
			),
		);

		$block_json = json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $block_json ) || false === file_put_contents( $dir . DIRECTORY_SEPARATOR . 'block.json', $block_json ) ) {
			throw new \RuntimeException( 'Failed to write block.json.' );
		}

		$variation = '<?php return ' . var_export(
			array(
				array(
					'name'       => $case['variationName'],
					'title'      => 'Generated variation',
					'attributes' => array(
						'message' => $case['message'],
					),
					'isDefault'  => true,
				),
			),
			true
		) . ';';
		if ( false === file_put_contents( $dir . DIRECTORY_SEPARATOR . 'variations.php', $variation ) ) {
			throw new \RuntimeException( 'Failed to write variations.php.' );
		}

		return $dir;
	}

	private static function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				self::remove_directory( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	private static function support_case( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'blockName'     => 'component-fuzz/' . self::slug( $ctx, 'support-block' ),
			'supportName'   => 'componentFuzz' . $ctx->int( 1000, 9999 ),
			'attributeName' => 'cfzAttribute' . $ctx->int( 1000, 9999 ),
			'token'         => self::slug( $ctx, 'token' ),
			'borderWidth'   => $ctx->int( 1, 12 ),
		);
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$raw = strtolower( $label . '-' . $ctx->identifier( 3, 12 ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$raw = preg_replace( '/[^a-z0-9-]+/', '-', $raw );
		$raw = trim( (string) $raw, '-' );
		return '' === $raw ? 'fuzz-' . dechex( $ctx->seed() & 0xffff ) : substr( $raw, 0, 48 );
	}

	private static function short_key( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$key = strtolower( $prefix . '_' . str_replace( '-', '_', self::slug( $ctx, $prefix ) ) );
		$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$key = trim( (string) $key, '_' );

		return substr( '' === $key ? $prefix . '_' . dechex( $ctx->seed() & 0xffff ) : $key, 0, 32 );
	}

	private static function block_tree_shape( array $blocks ): array {
		$shape = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$shape[] = array( 'invalid' => gettype( $block ) );
				continue;
			}

			$inner_content = array();
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
				$inner_content[] = is_string( $chunk ) ? array(
					'length' => strlen( $chunk ),
					'sha1'   => sha1( $chunk ),
				) : null;
			}

			$shape[] = array(
				'blockName'    => $block['blockName'] ?? null,
				'attrs'        => self::sort_value( is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() ),
				'innerHTML'    => array(
					'length' => is_string( $block['innerHTML'] ?? null ) ? strlen( $block['innerHTML'] ) : null,
					'sha1'   => is_string( $block['innerHTML'] ?? null ) ? sha1( $block['innerHTML'] ) : null,
				),
				'innerContent' => $inner_content,
				'innerBlocks'  => self::block_tree_shape( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ),
			);
		}

		return $shape;
	}

	private static function block_attrs_by_name( array $blocks ): array {
		$attrs = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) ) {
				$attrs[ $name ] = self::sort_value( is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );
			}

			$attrs += self::block_attrs_by_name( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
		}

		ksort( $attrs );
		return $attrs;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$sorted = array();
		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = self::sort_value( $item );
		}
		ksort( $sorted );

		return $sorted;
	}

	private static function null_marker_count( $value ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $value as $item ) {
			if ( null === $item ) {
				++$count;
			}
		}

		return $count;
	}

	private static function block_names( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			$names[] = is_array( $block ) ? ( $block['blockName'] ?? null ) : null;
		}
		return $names;
	}

	private static function find_first_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( $name === ( $block['blockName'] ?? null ) ) {
				return $block;
			}

			$found = self::find_first_block( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	private static function block_comment_count( string $content, string $name ): int {
		return substr_count( $content, '<!-- wp:' . $name );
	}

	private static function block_comment_counts( string $content, array $names ): array {
		$counts = array();
		foreach ( $names as $name ) {
			$counts[ $name ] = self::block_comment_count( $content, $name );
		}
		return $counts;
	}

	private static function hooked_block_attrs_match( ?array $block, array $case, string $position, ?string $anchor = null ): bool {
		$attrs = is_array( $block ) && is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		return $case['token'] === ( $attrs['cfzToken'] ?? null )
			&& $position === ( $attrs['cfzPosition'] ?? null )
			&& ( $anchor ?? $case['anchorName'] ) === ( $attrs['cfzAnchor'] ?? null );
	}

	private static function block_hooks_post_object_markup_matches( string $content, array $case ): bool {
		foreach (
			array(
				$case['rootFirstName'],
				$case['beforeName'],
				$case['filterName'],
				$case['anchorName'],
				$case['afterName'],
				$case['singleName'],
				$case['firstName'],
				$case['lastName'],
				$case['rootLastName'],
			) as $present
		) {
			if ( 1 !== self::block_comment_count( $content, $present ) ) {
				return false;
			}
		}

		foreach (
			array(
				$case['rootIgnoredName'],
				$case['rootBeforeName'],
				$case['rootAfterName'],
				$case['ignoredBeforeName'],
				$case['suppressedName'],
			) as $absent
		) {
			if ( 0 !== self::block_comment_count( $content, $absent ) ) {
				return false;
			}
		}

		return true;
	}

	private static function hooked_block_types_event_matches_for_post( array $events, int $post_id, string $anchor, string $position, array $types ): bool {
		foreach ( $events as $event ) {
			if (
				'hooked_block_types' === ( $event['filter'] ?? null )
				&& $position === ( $event['position'] ?? null )
				&& $anchor === ( $event['anchor'] ?? null )
				&& $post_id === (int) ( $event['context']['postId'] ?? 0 )
				&& $types === ( $event['types'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function hooked_block_event_matches_for_post( array $events, int $post_id, string $type, string $position, string $anchor ): bool {
		foreach ( $events as $event ) {
			if (
				'hooked_block' === ( $event['filter'] ?? null )
				&& $type === ( $event['type'] ?? null )
				&& $position === ( $event['position'] ?? null )
				&& $anchor === ( $event['anchor'] ?? null )
				&& $post_id === (int) ( $event['context']['postId'] ?? 0 )
			) {
				return true;
			}
		}

		return false;
	}

	private static function hooked_block_types_event_matches( array $events, string $anchor, string $position, string $context, array $types ): bool {
		foreach ( $events as $event ) {
			if (
				'hooked_block_types' === ( $event['filter'] ?? null )
				&& $position === ( $event['position'] ?? null )
				&& $anchor === ( $event['anchor'] ?? null )
				&& $context === ( $event['context'] ?? null )
				&& $types === ( $event['types'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function hooked_block_event_matches( array $events, string $type, string $position, string $anchor, string $context ): bool {
		foreach ( $events as $event ) {
			if (
				'hooked_block' === ( $event['filter'] ?? null )
				&& $type === ( $event['type'] ?? null )
				&& $position === ( $event['position'] ?? null )
				&& $anchor === ( $event['anchor'] ?? null )
				&& $context === ( $event['context'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function hooked_blocks_match( array $actual, array $expected ): bool {
		ksort( $actual );
		ksort( $expected );
		return $expected === $actual;
	}

	private static function string_lists_match_unordered( $actual, array $expected ): bool {
		if ( ! is_array( $actual ) ) {
			return false;
		}

		$actual   = array_values( $actual );
		$expected = array_values( $expected );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $expected === $actual;
	}

	private static function string_list_delta( array $expected, array $actual ): array {
		return array(
			'missing' => array_values( array_diff( $expected, $actual ) ),
			'extra'   => array_values( array_diff( $actual, $expected ) ),
		);
	}

	private static function json_list( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function log_contains( array $log, ?string $name, ?string $parent ): bool {
		foreach ( $log as $entry ) {
			if ( $name === ( $entry['name'] ?? null ) && $parent === ( $entry['parent'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function render_log_contains( array $log, string $name, string $filtered_token, string $context_token, string $context_parent ): bool {
		foreach ( $log as $entry ) {
			if (
				$name === ( $entry['name'] ?? null )
				&& $filtered_token === ( $entry['filteredToken'] ?? null )
				&& $context_token === ( $entry['contextToken'] ?? null )
				&& $context_parent === ( $entry['contextParent'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function binding_supported_log_contains( array $log, string $hook, string $block_name, array $before ): bool {
		foreach ( $log as $entry ) {
			if (
				$hook === ( $entry['hook'] ?? null )
				&& $block_name === ( $entry['blockType'] ?? null )
				&& $before === ( $entry['before'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function binding_source_log_contains( array $log, array $case, string $attribute, array $args ): bool {
		foreach ( $log as $entry ) {
			if (
				$attribute === ( $entry['attribute'] ?? null )
				&& $args === ( $entry['args'] ?? null )
				&& $case['blockName'] === ( $entry['blockName'] ?? null )
				&& $case['contextToken'] === ( $entry['context'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function binding_source_value_log_contains( array $log, array $case, string $attribute, array $args, string $value ): bool {
		foreach ( $log as $entry ) {
			if (
				$attribute === ( $entry['attribute'] ?? null )
				&& $args === ( $entry['args'] ?? null )
				&& $value === ( $entry['value'] ?? null )
				&& $case['sourceName'] === ( $entry['source'] ?? null )
				&& $case['blockName'] === ( $entry['blockName'] ?? null )
				&& $case['contextToken'] === ( $entry['context'] ?? null )
			) {
				return true;
			}
		}

		return false;
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
			foreach ( $expected as $key => $expected_value ) {
				if ( ! array_key_exists( $key, $actual ) ) {
					return array(
						'path'     => $path . '[' . var_export( $key, true ) . ']',
						'expected' => 'present',
						'actual'   => 'missing',
					);
				}

				$difference = self::first_value_difference( $expected_value, $actual[ $key ], $path . '[' . var_export( $key, true ) . ']' );
				if ( null !== $difference ) {
					return $difference;
				}
			}

			foreach ( $actual as $key => $actual_value ) {
				unset( $actual_value );
				if ( ! array_key_exists( $key, $expected ) ) {
					return array(
						'path'     => $path . '[' . var_export( $key, true ) . ']',
						'expected' => 'missing',
						'actual'   => 'present',
					);
				}
			}

			return null;
		}

		if ( $expected !== $actual ) {
			return array(
				'path'     => $path,
				'expected' => is_scalar( $expected ) || null === $expected ? $expected : gettype( $expected ),
				'actual'   => is_scalar( $actual ) || null === $actual ? $actual : gettype( $actual ),
			);
		}

		return null;
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

	private static function snapshot_state(): array {
		$block_registry   = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		$style_registry   = self::get_static_property( 'WP_Block_Styles_Registry', 'instance' );
		$pattern_registry = self::get_static_property( 'WP_Block_Patterns_Registry', 'instance' );
		$category_registry = self::get_static_property( 'WP_Block_Pattern_Categories_Registry', 'instance' );
		$binding_registry = self::get_static_property( 'WP_Block_Bindings_Registry', 'instance' );
		$supports         = self::get_static_property( 'WP_Block_Supports', 'instance' );

		return array(
			'globals'                => self::snapshot_globals( array( 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter' ) ),
			'blockRegistry'          => $block_registry,
			'blockTypes'             => $block_registry instanceof \WP_Block_Type_Registry ? self::get_object_property( $block_registry, 'registered_block_types' ) : null,
			'blockTypeAttributes'    => $block_registry instanceof \WP_Block_Type_Registry ? self::snapshot_block_type_attributes( $block_registry ) : array(),
			'styleRegistry'          => $style_registry,
			'blockStyles'            => $style_registry instanceof \WP_Block_Styles_Registry ? self::get_object_property( $style_registry, 'registered_block_styles' ) : null,
			'patternRegistry'        => $pattern_registry,
			'patterns'               => $pattern_registry instanceof \WP_Block_Patterns_Registry ? self::get_object_property( $pattern_registry, 'registered_patterns' ) : null,
			'patternsOutsideInit'    => $pattern_registry instanceof \WP_Block_Patterns_Registry ? self::get_object_property( $pattern_registry, 'registered_patterns_outside_init' ) : null,
			'categoryRegistry'       => $category_registry,
			'patternCategories'      => $category_registry instanceof \WP_Block_Pattern_Categories_Registry ? self::get_object_property( $category_registry, 'registered_categories' ) : null,
			'categoriesOutsideInit'  => $category_registry instanceof \WP_Block_Pattern_Categories_Registry ? self::get_object_property( $category_registry, 'registered_categories_outside_init' ) : null,
			'bindingRegistry'        => $binding_registry,
			'bindingSources'         => $binding_registry instanceof \WP_Block_Bindings_Registry ? self::get_object_property( $binding_registry, 'sources' ) : null,
			'supports'               => $supports,
			'blockSupports'          => $supports instanceof \WP_Block_Supports ? self::get_object_property( $supports, 'block_supports' ) : null,
			'blockSupportRenderItem' => self::get_static_property( 'WP_Block_Supports', 'block_to_render' ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		self::restore_singleton( 'WP_Block_Type_Registry', 'registered_block_types', $snapshot['blockRegistry'], $snapshot['blockTypes'] );
		self::restore_block_type_attributes( $snapshot['blockTypeAttributes'] );
		self::restore_singleton( 'WP_Block_Styles_Registry', 'registered_block_styles', $snapshot['styleRegistry'], $snapshot['blockStyles'] );
		self::restore_singleton( 'WP_Block_Bindings_Registry', 'sources', $snapshot['bindingRegistry'], $snapshot['bindingSources'] );

		if ( $snapshot['patternRegistry'] instanceof \WP_Block_Patterns_Registry ) {
			self::set_object_property( $snapshot['patternRegistry'], 'registered_patterns', $snapshot['patterns'] );
			self::set_object_property( $snapshot['patternRegistry'], 'registered_patterns_outside_init', $snapshot['patternsOutsideInit'] );
			self::set_static_property( 'WP_Block_Patterns_Registry', 'instance', $snapshot['patternRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Patterns_Registry', 'instance', null );
		}

		if ( $snapshot['categoryRegistry'] instanceof \WP_Block_Pattern_Categories_Registry ) {
			self::set_object_property( $snapshot['categoryRegistry'], 'registered_categories', $snapshot['patternCategories'] );
			self::set_object_property( $snapshot['categoryRegistry'], 'registered_categories_outside_init', $snapshot['categoriesOutsideInit'] );
			self::set_static_property( 'WP_Block_Pattern_Categories_Registry', 'instance', $snapshot['categoryRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Pattern_Categories_Registry', 'instance', null );
		}

		if ( $snapshot['supports'] instanceof \WP_Block_Supports ) {
			self::set_object_property( $snapshot['supports'], 'block_supports', $snapshot['blockSupports'] );
			self::set_static_property( 'WP_Block_Supports', 'instance', $snapshot['supports'] );
		} else {
			self::set_static_property( 'WP_Block_Supports', 'instance', null );
		}
		self::set_static_property( 'WP_Block_Supports', 'block_to_render', $snapshot['blockSupportRenderItem'] );
	}

	private static function snapshot_block_type_attributes( \WP_Block_Type_Registry $registry ): array {
		$attributes = array();
		foreach ( $registry->get_all_registered() as $name => $block_type ) {
			if ( $block_type instanceof \WP_Block_Type ) {
				$attributes[ $name ] = array(
					'blockType'  => $block_type,
					'attributes' => self::clone_value( $block_type->attributes ),
				);
			}
		}

		return $attributes;
	}

	private static function restore_block_type_attributes( array $attributes ): void {
		foreach ( $attributes as $entry ) {
			if ( isset( $entry['blockType'] ) && $entry['blockType'] instanceof \WP_Block_Type ) {
				$entry['blockType']->attributes = $entry['attributes'];
			}
		}
	}

	private static function restore_singleton( string $class, string $property, $instance, $value ): void {
		if ( $instance instanceof $class ) {
			self::set_object_property( $instance, $property, $value );
			self::set_static_property( $class, 'instance', $instance );
		} else {
			self::set_static_property( $class, 'instance', null );
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

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
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
