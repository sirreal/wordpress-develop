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
				self::check_style_pattern_binding_registries( $ctx ),
				self::check_metadata_and_pattern_categories( $ctx ),
				self::check_block_hooks_insertion_and_metadata( $ctx ),
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
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'apply_block_hooks_to_content',
				'block_has_support',
				'get_all_registered_block_bindings_sources',
				'get_block_bindings_source',
				'get_block_wrapper_attributes',
				'get_hooked_blocks',
				'has_block',
				'has_blocks',
				'parse_blocks',
				'register_block_bindings_source',
				'register_block_style',
				'register_block_type',
				'register_block_type_from_metadata',
				'remove_filter',
				'render_block',
				'serialize_block',
				'serialize_blocks',
				'unregister_block_bindings_source',
				'unregister_block_style',
				'unregister_block_type',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
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
				$hooked_block_types[] = $case['suppressedName'];
			}

			return array_values( array_unique( $hooked_block_types ) );
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

			self::collect_failure(
				$failures,
				isset(
					$hooked_blocks[ $case['anchorName'] ]['before'],
					$hooked_blocks[ $case['anchorName'] ]['after'],
					$hooked_blocks[ $case['anchorName'] ]['first_child'],
					$hooked_blocks[ $case['anchorName'] ]['last_child']
				)
					&& in_array( $case['beforeName'], $hooked_blocks[ $case['anchorName'] ]['before'], true )
					&& in_array( $case['afterName'], $hooked_blocks[ $case['anchorName'] ]['after'], true )
					&& in_array( $case['singleName'], $hooked_blocks[ $case['anchorName'] ]['after'], true )
					&& in_array( $case['firstName'], $hooked_blocks[ $case['anchorName'] ]['first_child'], true )
					&& in_array( $case['lastName'], $hooked_blocks[ $case['anchorName'] ]['last_child'], true ),
				'get_hooked_blocks groups registered hooked blocks by anchor and relative position',
				array(
					'case'         => $case,
					'hookedBlocks' => $hooked_blocks[ $case['anchorName'] ] ?? null,
				)
			);

			self::collect_failure(
				$failures,
				! str_contains( $augmented, '<!-- wp:' . $case['beforeName'] )
					&& ! str_contains( $augmented, '<!-- wp:' . $case['suppressedName'] )
					&& array( $case['anchorName'], $case['afterName'], $case['singleName'] ) === $top_names
					&& array( $case['firstName'], 'core/paragraph', $case['lastName'] ) === $inner_names
					&& 1 === self::block_comment_count( $augmented, $case['afterName'] )
					&& 1 === self::block_comment_count( $augmented, $case['singleName'] )
					&& 1 === self::block_comment_count( $augmented, $case['firstName'] )
					&& 1 === self::block_comment_count( $augmented, $case['lastName'] )
					&& self::hooked_block_attrs_match( $after_block, $case, 'after' )
					&& self::hooked_block_attrs_match( $single_block, $case, 'after' )
					&& self::hooked_block_attrs_match( $first_block, $case, 'first_child' )
					&& self::hooked_block_attrs_match( $last_block, $case, 'last_child' ),
				'apply_block_hooks_to_content inserts non-ignored hooks at before/after and child boundaries with filter-mutated attrs',
				array(
					'content'       => $content,
					'augmented'     => $augmented,
					'topNames'      => $top_names,
					'innerNames'    => $inner_names,
					'parsedAttrs'   => array(
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
				is_array( $ignored )
					&& in_array( $case['beforeName'], $ignored, true )
					&& in_array( $case['afterName'], $ignored, true )
					&& in_array( $case['singleName'], $ignored, true )
					&& in_array( $case['firstName'], $ignored, true )
					&& in_array( $case['lastName'], $ignored, true )
					&& ! in_array( $case['suppressedName'], $ignored, true )
					&& count( $ignored ) === count( array_unique( $ignored ) )
					&& 0 === self::block_comment_count( $metadata_content, $case['beforeName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['afterName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['singleName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['firstName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['lastName'] )
					&& 0 === self::block_comment_count( $metadata_content, $case['suppressedName'] ),
				'set_ignored_hooked_blocks_metadata records hookable block types without emitting hooked markup',
				array(
					'metadataContent' => $metadata_content,
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
		$token           = self::slug( $ctx, 'hook-token' );
		$anchor_name     = 'component-fuzz/' . self::slug( $ctx->fork( 'anchor' ), 'hook-anchor' );
		$before_name     = 'component-fuzz/' . self::slug( $ctx->fork( 'before' ), 'hook-before' );
		$after_name      = 'component-fuzz/' . self::slug( $ctx->fork( 'after' ), 'hook-after' );
		$first_name      = 'component-fuzz/' . self::slug( $ctx->fork( 'first' ), 'hook-first' );
		$last_name       = 'component-fuzz/' . self::slug( $ctx->fork( 'last' ), 'hook-last' );
		$single_name     = 'component-fuzz/' . self::slug( $ctx->fork( 'single' ), 'hook-single' );
		$suppressed_name = 'component-fuzz/' . self::slug( $ctx->fork( 'suppressed' ), 'hook-suppressed' );

		$anchor_block = self::parsed_block(
			$anchor_name,
			array(
				'metadata' => array(
					'ignoredHookedBlocks' => array( $before_name ),
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
			$anchor_name     => array(
				'title'       => 'Component Fuzz Hook Anchor',
				'api_version' => 3,
				'attributes'  => array(
					'token' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			),
			$before_name     => array(
				'title'       => 'Component Fuzz Hook Before',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'before' ),
			),
			$after_name      => array(
				'title'       => 'Component Fuzz Hook After',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'after' ),
			),
			$first_name      => array(
				'title'       => 'Component Fuzz Hook First Child',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'first_child' ),
			),
			$last_name       => array(
				'title'       => 'Component Fuzz Hook Last Child',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'last_child' ),
			),
			$single_name     => array(
				'title'       => 'Component Fuzz Hook Single',
				'api_version' => 3,
				'block_hooks' => array( $anchor_name => 'after' ),
				'supports'    => array(
					'multiple' => false,
				),
			),
			$suppressed_name => array(
				'title'       => 'Component Fuzz Hook Suppressed',
				'api_version' => 3,
				'supports'    => array(
					'multiple' => true,
				),
			),
		);

		return array(
			'token'          => $token,
			'anchorName'     => $anchor_name,
			'beforeName'     => $before_name,
			'afterName'      => $after_name,
			'firstName'      => $first_name,
			'lastName'       => $last_name,
			'singleName'     => $single_name,
			'suppressedName' => $suppressed_name,
			'anchorBlock'    => $anchor_block,
			'registrations'  => $registrations,
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

	private static function hooked_block_attrs_match( ?array $block, array $case, string $position ): bool {
		$attrs = is_array( $block ) && is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		return $case['token'] === ( $attrs['cfzToken'] ?? null )
			&& $position === ( $attrs['cfzPosition'] ?? null )
			&& $case['anchorName'] === ( $attrs['cfzAnchor'] ?? null );
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
