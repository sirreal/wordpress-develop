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
				self::check_style_pattern_binding_registries( $ctx ),
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
				'WP_Block_Patterns_Registry',
				'WP_Block_Styles_Registry',
				'WP_Block_Supports',
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
				'block_has_support',
				'get_all_registered_block_bindings_sources',
				'get_block_bindings_source',
				'get_block_wrapper_attributes',
				'register_block_bindings_source',
				'register_block_style',
				'register_block_type',
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
