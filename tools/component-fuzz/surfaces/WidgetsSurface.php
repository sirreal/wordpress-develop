<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes classic sidebar and widget registry behavior.
 */
final class WidgetsSurface {
	public const NAME = 'widgets';

	private const PREVIEW_BYTES = 160;

	/** @var array<int,array<string,mixed>> */
	private static array $widget_calls = array();

	/** @var array<int,array<string,mixed>> */
	private static array $the_widget_events = array();

	/** @var array<int,array<string,mixed>> */
	private static array $direct_widget_calls = array();

	/** @var array<int,array<string,mixed>> */
	private static array $direct_control_calls = array();

	/** @var array<int,array<string,mixed>> */
	private static array $dynamic_sidebar_events = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'widgets.bootstrap-apis-available',
					'Required WordPress widget APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$runtime_snapshot = null;
		$rows             = array();

		try {
			self::reset_runtime();
			$runtime_snapshot = self::snapshot_state();

			$rows[] = self::check_sidebar_registry( $ctx );
			$rows[] = self::check_widget_factory_registration( $ctx );
			$rows[] = self::check_direct_widget_registration( $ctx );
			$rows[] = self::check_sidebar_assignment( $ctx );
			$rows[] = self::check_sidebars_widgets_option_flow( $ctx );
			$rows[] = self::check_widget_rendering( $ctx );
			$rows[] = self::check_the_widget_helper( $ctx );
			$rows[] = self::check_dynamic_sidebar_action_order( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'widgets.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $runtime_snapshot ) {
				$rows[] = self::check_no_external_db_or_admin_dispatch( $ctx, $runtime_snapshot );
			}
			self::restore_state( $snapshot );
			$rows[] = self::check_state_restored( $ctx, $snapshot );
		}

		return $rows;
	}

	public static function record_widget_call( string $id, array $args, array $instance ): void {
		self::$the_widget_events[] = array(
			'type'     => 'callback',
			'widgetId' => $id,
			'title'    => $instance['title'] ?? null,
		);

		self::$widget_calls[] = array(
			'id'       => $id,
			'args'     => array(
				'sidebar_id'    => $args['id'] ?? null,
				'sidebar_name'  => $args['name'] ?? null,
				'widget_id'     => $args['widget_id'] ?? null,
				'widget_name'   => $args['widget_name'] ?? null,
				'before_widget' => $args['before_widget'] ?? null,
				'after_widget'  => $args['after_widget'] ?? null,
				'before_title'  => $args['before_title'] ?? null,
				'after_title'   => $args['after_title'] ?? null,
			),
			'instance' => $instance,
		);
	}

	public static function direct_widget_callback( array $args, array $payload = array() ): void {
		self::$direct_widget_calls[] = array(
			'args'    => array(
				'sidebar_id'    => $args['id'] ?? null,
				'sidebar_name'  => $args['name'] ?? null,
				'widget_id'     => $args['widget_id'] ?? null,
				'widget_name'   => $args['widget_name'] ?? null,
				'before_widget' => $args['before_widget'] ?? null,
				'after_widget'  => $args['after_widget'] ?? null,
				'before_title'  => $args['before_title'] ?? null,
				'after_title'   => $args['after_title'] ?? null,
			),
			'payload' => $payload,
		);

		$label = isset( $payload['label'] ) ? (string) $payload['label'] : '';
		echo $args['before_widget'] ?? '';
		echo $args['before_title'] ?? '';
		echo '<span class="cfz-direct-widget-label">' . \esc_html( $label ) . '</span>';
		echo $args['after_title'] ?? '';
		echo $args['after_widget'] ?? '';
	}

	public static function direct_control_callback( array $payload = array() ): void {
		self::$direct_control_calls[] = array( 'payload' => $payload );

		$id    = isset( $payload['id'] ) ? (string) $payload['id'] : 'cfz-direct-control';
		$label = isset( $payload['label'] ) ? (string) $payload['label'] : '';
		echo '<input id="' . \esc_attr( $id ) . '" name="' . \esc_attr( $id ) . '" value="' . \esc_attr( $label ) . '">';
	}

	public static function action_order_widget_callback( array $args, array $payload = array() ): void {
		self::$dynamic_sidebar_events[] = array(
			'type'      => 'callback',
			'widgetId'  => isset( $args['widget_id'] ) ? (string) $args['widget_id'] : '',
			'sidebarId' => isset( $args['id'] ) ? (string) $args['id'] : '',
		);

		self::direct_widget_callback( $args, $payload );
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'Component_Fuzz_WPDB_Stub', 'WP_Widget', 'WP_Widget_Factory' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'dynamic_sidebar',
				'has_action',
				'has_filter',
				'is_registered_sidebar',
				'remove_action',
				'remove_filter',
				'register_sidebars',
				'register_sidebar',
				'register_widget',
				'unregister_sidebar',
				'unregister_widget',
				'wp_assign_widget_to_sidebar',
				'wp_find_widgets_sidebar',
				'wp_get_sidebars_widgets',
				'wp_parse_widget_id',
				'wp_register_sidebar_widget',
				'wp_register_widget_control',
				'wp_render_widget',
				'wp_render_widget_control',
				'wp_set_sidebars_widgets',
				'wp_sidebar_description',
				'the_widget',
				'wp_unregister_sidebar_widget',
				'wp_unregister_widget_control',
				'wp_widget_description',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_sidebar_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_sidebars;

		$failures = array();
		$case     = self::sidebar_case( $ctx->fork( 'sidebar' ), 'primary' );
		$id       = $case['id'];
		$args     = $case['args'];

		$registered = \register_sidebar( $args );
		$description = \wp_sidebar_description( $id );
		self::collect_failure(
			$failures,
			$id === $registered
				&& \is_registered_sidebar( $id )
				&& isset( $wp_registered_sidebars[ $id ] )
				&& $args['name'] === $wp_registered_sidebars[ $id ]['name']
				&& $args['before_widget'] === $wp_registered_sidebars[ $id ]['before_widget']
				&& $args['after_sidebar'] === $wp_registered_sidebars[ $id ]['after_sidebar']
				&& is_string( $description )
				&& ! str_contains( strtolower( $description ), '<script' )
				&& ! str_contains( strtolower( $description ), 'onerror' ),
			'register_sidebar preserves explicit sidebar shape',
			array(
				'args'        => $args,
				'registered'  => $registered,
				'actual'      => $wp_registered_sidebars[ $id ] ?? null,
				'description' => $description,
			)
		);

		\unregister_sidebar( $id );
		self::collect_failure(
			$failures,
			! \is_registered_sidebar( $id ) && ! isset( $wp_registered_sidebars[ $id ] ),
			'unregister_sidebar removes registered sidebar',
			array(
				'id'      => $id,
				'current' => array_keys( $wp_registered_sidebars ),
			)
		);

		$generated_base = self::id( $ctx->fork( 'generated-sidebars' ), 'generated_sidebar' );
		\register_sidebars(
			3,
			array(
				'id'             => $generated_base,
				'name'           => 'Generated Sidebar %d',
				'description'    => 'Generated ' . self::hostile_label( $ctx->fork( 'generated-description' ) ),
				'class'          => 'generated-sidebar',
				'before_widget'  => '<article id="%1$s" class="generated %2$s">',
				'after_widget'   => '</article>',
				'before_sidebar' => '<nav id="%1$s" class="%2$s">',
				'after_sidebar'  => '</nav>',
			)
		);

		$generated_ids = array( $generated_base, $generated_base . '-2', $generated_base . '-3' );
		self::collect_failure(
			$failures,
			isset(
				$wp_registered_sidebars[ $generated_ids[0] ],
				$wp_registered_sidebars[ $generated_ids[1] ],
				$wp_registered_sidebars[ $generated_ids[2] ]
			)
				&& 'Generated Sidebar 1' === $wp_registered_sidebars[ $generated_ids[0] ]['name']
				&& 'Generated Sidebar 2' === $wp_registered_sidebars[ $generated_ids[1] ]['name']
				&& 'Generated Sidebar 3' === $wp_registered_sidebars[ $generated_ids[2] ]['name']
				&& $generated_ids === array_slice( array_keys( $wp_registered_sidebars ), -3 ),
			'register_sidebars generates deterministic suffixed IDs and names',
			array(
				'base'       => $generated_base,
				'expected'   => $generated_ids,
				'registered' => array_intersect_key( $wp_registered_sidebars, array_flip( $generated_ids ) ),
			)
		);

		foreach ( $generated_ids as $generated_id ) {
			\unregister_sidebar( $generated_id );
		}
		self::collect_failure(
			$failures,
			! \is_registered_sidebar( $generated_ids[0] )
				&& ! \is_registered_sidebar( $generated_ids[1] )
				&& ! \is_registered_sidebar( $generated_ids[2] ),
			'unregister_sidebar removes generated sidebars without disturbing remaining registry',
			array(
				'removed' => $generated_ids,
				'current' => array_keys( $wp_registered_sidebars ),
			)
		);

		return self::row(
			$ctx,
			'widgets.sidebars.registry-lifecycle',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_widget_factory_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_widget_controls, $wp_registered_widget_updates, $wp_registered_widgets, $wp_widget_factory;

		$failures = array();
		$case     = self::widget_case( $ctx->fork( 'factory' ), 'factory' );
		$id_base  = $case['idBase'];
		$number   = $case['number'];
		$alt_number = $case['altNumber'];
		$widget   = self::widget_instance( $id_base, $case );

		\update_option(
			'widget_' . $id_base,
			array(
				$number        => array(
					'title'   => $case['title'],
					'content' => $case['content'],
				),
				$alt_number    => array(
					'title'   => $case['altTitle'],
					'content' => $case['altContent'],
				),
				'_multiwidget' => 1,
			)
		);
		\register_widget( $widget );
		$key = $wp_widget_factory->get_widget_key( $id_base );
		$wp_widget_factory->_register_widgets();

		$widget_id = $id_base . '-' . $number;
		$alt_widget_id = $id_base . '-' . $alt_number;
		$parsed    = \wp_parse_widget_id( $widget_id );
		$alt_parsed = \wp_parse_widget_id( $alt_widget_id );
		$widget->_set( $number );
		$field_id = $widget->get_field_id( 'nested[title][]' );
		$field_name = $widget->get_field_name( 'nested[title][]' );
		$control_output = \wp_render_widget_control( $widget_id );
		$expected_title = \esc_attr( $case['title'] );

		self::collect_failure(
			$failures,
			'' !== $key
				&& $widget === $wp_widget_factory->get_widget_object( $id_base )
				&& isset( $wp_registered_widgets[ $widget_id ] )
				&& isset( $wp_registered_widgets[ $alt_widget_id ] )
				&& isset( $wp_registered_widget_controls[ $widget_id ] )
				&& isset( $wp_registered_widget_controls[ $alt_widget_id ] )
				&& isset( $wp_registered_widget_updates[ $id_base ] )
				&& $id_base === $parsed['id_base']
				&& $number === $parsed['number']
				&& $id_base === $alt_parsed['id_base']
				&& $alt_number === $alt_parsed['number']
				&& $number === ( $wp_registered_widgets[ $widget_id ]['params'][0]['number'] ?? null )
				&& $number === ( $wp_registered_widget_controls[ $widget_id ]['params'][0]['number'] ?? null )
				&& -1 === ( $wp_registered_widget_updates[ $id_base ]['params'][0]['number'] ?? null )
				&& 'widget-' . $id_base . '-' . $number . '-nested-title' === $field_id
				&& 'widget-' . $id_base . '[' . $number . '][nested][title][]' === $field_name
				&& is_string( $control_output )
				&& str_contains( $control_output, 'id="widget-' . $id_base . '-' . $number . '-title"' )
				&& str_contains( $control_output, 'name="widget-' . $id_base . '[' . $number . '][title]"' )
				&& str_contains( $control_output, 'value="' . $expected_title . '"' )
				&& ! str_contains( $control_output, $case['title'] ),
			'register_widget adds factory instances, controls, update callback, and escaped form fields',
			array(
				'idBase'        => $id_base,
				'key'           => $key,
				'widgetId'      => $widget_id,
				'altWidgetId'   => $alt_widget_id,
				'parsed'        => $parsed,
				'altParsed'     => $alt_parsed,
				'fieldId'       => $field_id,
				'fieldName'     => $field_name,
				'registered'    => $wp_registered_widgets[ $widget_id ] ?? null,
				'control'       => $wp_registered_widget_controls[ $widget_id ] ?? null,
				'update'        => $wp_registered_widget_updates[ $id_base ] ?? null,
				'controlOutput' => self::describe_string( is_string( $control_output ) ? $control_output : '' ),
			)
		);

		\unregister_widget( $widget );
		self::collect_failure(
			$failures,
			null === $wp_widget_factory->get_widget_object( $id_base ),
			'unregister_widget removes factory object for instance registration',
			array(
				'idBase' => $id_base,
				'key'    => $wp_widget_factory->get_widget_key( $id_base ),
			)
		);

		return self::row(
			$ctx,
			'widgets.factory.register-unregister-instance',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_direct_widget_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_widget_controls, $wp_registered_widget_updates, $wp_registered_widgets;

		$failures   = array();
		$sidebar    = self::sidebar_case( $ctx->fork( 'direct-sidebar' ), 'direct' );
		$payload    = self::direct_widget_case( $ctx->fork( 'direct-widget' ) );
		$raw_id     = strtoupper( $payload['id'] );
		$widget_id  = strtolower( $raw_id );
		$id_base    = \wp_parse_widget_id( $widget_id )['id_base'];
		$class_name = 'cfz-direct_' . $payload['variantClass'];

		\register_sidebar( $sidebar['args'] );
		\wp_register_sidebar_widget(
			$raw_id,
			$payload['name'],
			array( self::class, 'direct_widget_callback' ),
			array(
				'classname'             => array( 'cfz-direct', $payload['variantClass'] ),
				'description'           => $payload['description'],
				'show_instance_in_rest' => true,
			),
			$payload
		);
		\wp_register_widget_control(
			$raw_id,
			$payload['name'],
			array( self::class, 'direct_control_callback' ),
			array(
				'id_base' => $id_base,
				'height'  => '321',
				'width'   => '456',
			),
			$payload
		);

		\wp_set_sidebars_widgets(
			array(
				$sidebar['id']         => array( $widget_id ),
				'wp_inactive_widgets' => array(),
			)
		);
		$GLOBALS['_wp_sidebars_widgets'] = array(
			$sidebar['id']           => array( $widget_id ),
			'wp_inactive_widgets' => array(),
		);
		$GLOBALS['sidebars_widgets'] = $GLOBALS['_wp_sidebars_widgets'];

		self::$direct_widget_calls  = array();
		self::$direct_control_calls = array();
		$rendered                  = \wp_render_widget( $widget_id, $sidebar['id'] );
		$render_calls              = self::$direct_widget_calls;
		$control_output            = \wp_render_widget_control( $widget_id );
		$control_calls             = self::$direct_control_calls;
		$description               = \wp_widget_description( $widget_id );

		self::$direct_widget_calls = array();
		ob_start();
		$dynamic_result = \dynamic_sidebar( $sidebar['id'] );
		$dynamic_output = ob_get_clean();
		$dynamic_calls  = self::$direct_widget_calls;

		self::collect_failure(
			$failures,
			isset( $wp_registered_widgets[ $widget_id ] )
				&& isset( $wp_registered_widget_controls[ $widget_id ] )
				&& isset( $wp_registered_widget_updates[ $id_base ] )
				&& $widget_id === ( $wp_registered_widgets[ $widget_id ]['id'] ?? null )
				&& $payload['name'] === ( $wp_registered_widgets[ $widget_id ]['name'] ?? null )
				&& 456 === ( $wp_registered_widget_controls[ $widget_id ]['width'] ?? null )
				&& 321 === ( $wp_registered_widget_controls[ $widget_id ]['height'] ?? null )
				&& $payload['number'] === ( $wp_registered_widget_controls[ $widget_id ]['params'][0]['number'] ?? null )
				&& -1 === ( $wp_registered_widget_updates[ $id_base ]['params'][0]['number'] ?? null )
				&& is_string( $description )
				&& str_contains( $description, '&lt;script&gt;' )
				&& ! str_contains( strtolower( $description ), '<script' )
				&& 1 === count( $render_calls )
				&& $widget_id === ( $render_calls[0]['args']['widget_id'] ?? null )
				&& $payload['name'] === ( $render_calls[0]['args']['widget_name'] ?? null )
				&& str_contains( $rendered, 'class="widget ' . $class_name . '"' )
				&& str_contains( $rendered, \esc_html( $payload['label'] ) )
				&& ! str_contains( $rendered, $payload['label'] )
				&& is_string( $control_output )
				&& 1 === count( $control_calls )
				&& str_contains( $control_output, 'value="' . \esc_attr( $payload['label'] ) . '"' )
				&& ! str_contains( $control_output, $payload['label'] )
				&& true === $dynamic_result
				&& 1 === count( $dynamic_calls )
				&& str_contains( $dynamic_output, '<aside id="' . $sidebar['id'] . '"' )
				&& str_contains( $dynamic_output, $rendered ),
			'wp_register_sidebar_widget and wp_register_widget_control preserve callbacks, params, and escaping',
			array(
				'rawId'          => $raw_id,
				'widgetId'       => $widget_id,
				'idBase'         => $id_base,
				'registered'     => $wp_registered_widgets[ $widget_id ] ?? null,
				'control'        => $wp_registered_widget_controls[ $widget_id ] ?? null,
				'update'         => $wp_registered_widget_updates[ $id_base ] ?? null,
				'description'    => $description,
				'rendered'       => self::describe_string( $rendered ),
				'controlOutput'  => self::describe_string( is_string( $control_output ) ? $control_output : '' ),
				'dynamicOutput'  => self::describe_string( $dynamic_output ),
				'renderCalls'    => $render_calls,
				'controlCalls'   => $control_calls,
				'dynamicCalls'   => $dynamic_calls,
				'dynamicResult'  => $dynamic_result,
			)
		);

		\wp_unregister_sidebar_widget( $widget_id );
		self::collect_failure(
			$failures,
			! isset( $wp_registered_widgets[ $widget_id ] )
				&& ! isset( $wp_registered_widget_controls[ $widget_id ] )
				&& ! isset( $wp_registered_widget_updates[ $id_base ] ),
			'wp_unregister_sidebar_widget clears direct widget, control, and update registries',
			array(
				'widgetId' => $widget_id,
				'widgets'  => array_keys( $wp_registered_widgets ),
				'controls' => array_keys( $wp_registered_widget_controls ),
				'updates'  => array_keys( $wp_registered_widget_updates ),
			)
		);

		return self::row(
			$ctx,
			'widgets.direct.registry-controls-and-callbacks',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_sidebar_assignment( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$widget   = self::id( $ctx->fork( 'assign-widget' ), 'cfz_widget' ) . '-1';
		$side_a   = self::id( $ctx->fork( 'side-a' ), 'sidebar_a' );
		$side_b   = self::id( $ctx->fork( 'side-b' ), 'sidebar_b' );
		$other    = self::id( $ctx->fork( 'other-widget' ), 'other' ) . '-2';
		$inactive = self::id( $ctx->fork( 'inactive-widget' ), 'inactive' ) . '-3';
		$initial  = array(
			$side_a               => array( $widget, $other, $inactive ),
			$side_b               => array(),
			'wp_inactive_widgets' => array(),
		);
		$updates  = array();
		$capture  = static function ( $value, $old_value, string $option ) use ( &$updates ) {
			unset( $old_value, $option );
			$updates[] = $value;
			return $value;
		};

		\wp_set_sidebars_widgets( $initial );
		$GLOBALS['_wp_sidebars_widgets'] = $initial;

		try {
			\add_filter( 'pre_update_option_sidebars_widgets', $capture, 10, 3 );
			$before = $initial;

			\wp_assign_widget_to_sidebar( $widget, $side_b );
			$raw_after_move = self::last_update( $updates );
			$after_move = self::normalize_sidebars_widgets( $raw_after_move );
			$GLOBALS['_wp_sidebars_widgets'] = $after_move;
			$sidebar_after_move = \wp_find_widgets_sidebar( $widget );

			$updates = array();
			\wp_assign_widget_to_sidebar( $inactive, 'wp_inactive_widgets' );
			$raw_after_inactive = self::last_update( $updates );
			$after_inactive = self::normalize_sidebars_widgets( $raw_after_inactive );
			$GLOBALS['_wp_sidebars_widgets'] = $after_inactive;
			$sidebar_after_inactive = \wp_find_widgets_sidebar( $inactive );

			$updates = array();
			\wp_assign_widget_to_sidebar( $widget, '' );
			$raw_after_remove = self::last_update( $updates );
			$after_remove = self::normalize_sidebars_widgets( $raw_after_remove );
			$GLOBALS['_wp_sidebars_widgets'] = $after_remove;
			$sidebar_after_remove = \wp_find_widgets_sidebar( $widget );
			$other_sidebar_after_remove = \wp_find_widgets_sidebar( $other );
			$inactive_sidebar_after_remove = \wp_find_widgets_sidebar( $inactive );
		} finally {
			\remove_filter( 'pre_update_option_sidebars_widgets', $capture, 10 );
		}

		self::collect_failure(
			$failures,
			$side_a === $other_sidebar_after_remove
				&& 'wp_inactive_widgets' === $inactive_sidebar_after_remove
				&& isset( $before[ $side_a ], $before[ $side_b ] )
				&& in_array( $widget, $before[ $side_a ], true )
				&& in_array( $inactive, $before[ $side_a ], true )
				&& 3 === ( $raw_after_move['array_version'] ?? null )
				&& $side_b === $sidebar_after_move
				&& ! in_array( $widget, $after_move[ $side_a ], true )
				&& array( $widget ) === array_values( $after_move[ $side_b ] )
				&& 3 === ( $raw_after_inactive['array_version'] ?? null )
				&& 'wp_inactive_widgets' === $sidebar_after_inactive
				&& ! in_array( $inactive, $after_inactive[ $side_a ], true )
				&& array( $inactive ) === array_values( $after_inactive['wp_inactive_widgets'] )
				&& 3 === ( $raw_after_remove['array_version'] ?? null )
				&& null === $sidebar_after_remove
				&& ! in_array( $widget, $after_remove[ $side_b ] ?? array(), true )
				&& in_array( $other, $after_remove[ $side_a ] ?? array(), true )
				&& in_array( $inactive, $after_remove['wp_inactive_widgets'] ?? array(), true ),
			'wp_assign_widget_to_sidebar moves active widgets, parks inactive widgets, and removes only the target id',
			array(
				'widget'        => $widget,
				'other'         => $other,
				'inactive'      => $inactive,
				'before'        => $before,
				'rawAfterMove'  => $raw_after_move,
				'afterMove'     => $after_move,
				'moveSidebar'   => $sidebar_after_move,
				'rawAfterInactive' => $raw_after_inactive,
				'afterInactive' => $after_inactive,
				'inactiveSidebar' => $sidebar_after_inactive,
				'rawAfterRemove' => $raw_after_remove,
				'afterRemove'   => $after_remove,
				'removeSidebar' => $sidebar_after_remove,
				'otherSidebar'  => $other_sidebar_after_remove,
				'inactiveSidebarAfterRemove' => $inactive_sidebar_after_remove,
			)
		);

		return self::row(
			$ctx,
			'widgets.sidebars.assignment-move-remove',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_sidebars_widgets_option_flow( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		$side_a          = self::id( $ctx->fork( 'option-side-a' ), 'sidebar_option_a' );
		$side_b          = self::id( $ctx->fork( 'option-side-b' ), 'sidebar_option_b' );
		$filtered_side   = self::id( $ctx->fork( 'filtered-side' ), 'sidebar_filtered' );
		$hyphen_base     = self::id( $ctx->fork( 'hyphen-base' ), 'cfz-hyphen-base' );
		$hyphen_widget   = $hyphen_base . '-' . $ctx->int( 10, 30 );
		$legacy_widget   = self::id( $ctx->fork( 'legacy-widget' ), 'legacy_widget' );
		$other_widget    = self::id( $ctx->fork( 'other-widget' ), 'other_widget' ) . '-2';
		$inactive_widget = self::id( $ctx->fork( 'inactive-widget' ), 'inactive_widget' ) . '-3';
		$replacement     = self::id( $ctx->fork( 'replacement-widget' ), 'replacement_widget' ) . '-4';
		$filtered_widget = self::id( $ctx->fork( 'filtered-widget' ), 'filtered_widget' ) . '-5';
		$initial         = array(
			$side_a               => array( $hyphen_widget, $legacy_widget ),
			$side_b               => array( $other_widget ),
			'wp_inactive_widgets' => array( $inactive_widget ),
			'array_version'       => 3,
		);
		$updated_option  = array(
			$side_a               => array( self::id( $ctx->fork( 'stale-widget' ), 'stale_widget' ) . '-6' ),
			$side_b               => array( $replacement ),
			'wp_inactive_widgets' => array(),
		);
		$filter_seen     = array();
		$filter          = static function ( $sidebars_widgets ) use ( &$filter_seen, $filtered_side, $filtered_widget ) {
			$filter_seen[] = is_array( $sidebars_widgets ) ? $sidebars_widgets : array( 'non_array' => gettype( $sidebars_widgets ) );
			if ( is_array( $sidebars_widgets ) ) {
				$sidebars_widgets[ $filtered_side ] = array( $filtered_widget );
			}
			return $sidebars_widgets;
		};

		\update_option( 'sidebars_widgets', $initial );
		$GLOBALS['_wp_sidebars_widgets'] = array();
		$GLOBALS['sidebars_widgets']     = array();

		try {
			\add_filter( 'sidebars_widgets', $filter );
			$first = \wp_get_sidebars_widgets();

			\update_option(
				'sidebars_widgets',
				array(
					$side_b               => array( self::id( $ctx->fork( 'changed-option' ), 'changed_widget' ) . '-7' ),
					'wp_inactive_widgets' => array(),
					'array_version'       => 3,
				)
			);
			$cached = \wp_get_sidebars_widgets();

			\wp_set_sidebars_widgets( $updated_option );
			$stored_after_set = \get_option( 'sidebars_widgets', array() );
			$after_set        = \wp_get_sidebars_widgets();
			$replacement_side = \wp_find_widgets_sidebar( $replacement );
			$filtered_lookup  = \wp_find_widgets_sidebar( $filtered_widget );
		} finally {
			\remove_filter( 'sidebars_widgets', $filter );
		}

		$parse_cases = array(
			$hyphen_widget                 => array(
				'id_base' => $hyphen_base,
				'number'  => (int) substr( strrchr( $hyphen_widget, '-' ), 1 ),
			),
			$hyphen_base . '-00012'       => array(
				'id_base' => $hyphen_base,
				'number'  => 12,
			),
			$hyphen_base . '--12'         => array(
				'id_base' => $hyphen_base . '-',
				'number'  => 12,
			),
			$hyphen_base . '-12-extra'    => array(
				'id_base' => $hyphen_base . '-12-extra',
			),
			$legacy_widget                => array(
				'id_base' => $legacy_widget,
			),
		);
		$parse_observed = array();
		$parse_ok       = true;
		foreach ( $parse_cases as $widget_id => $expected ) {
			$parsed                       = \wp_parse_widget_id( $widget_id );
			$parse_observed[ $widget_id ] = $parsed;
			$has_number                   = array_key_exists( 'number', $expected );
			$parse_ok                     = $parse_ok
				&& $expected['id_base'] === ( $parsed['id_base'] ?? null )
				&& $has_number === array_key_exists( 'number', $parsed )
				&& ( ! $has_number || $expected['number'] === ( $parsed['number'] ?? null ) );
		}

		self::collect_failure(
			$failures,
			isset( $filter_seen[0], $filter_seen[1], $filter_seen[2] )
				&& ! isset( $filter_seen[0]['array_version'] )
				&& ! isset( $first['array_version'], $cached['array_version'], $after_set['array_version'] )
				&& array( $hyphen_widget, $legacy_widget ) === array_values( $first[ $side_a ] ?? array() )
				&& array( $filtered_widget ) === array_values( $first[ $filtered_side ] ?? array() )
				&& array( $hyphen_widget, $legacy_widget ) === array_values( $cached[ $side_a ] ?? array() )
				&& ! in_array( $replacement, $cached[ $side_b ] ?? array(), true )
				&& 3 === ( $stored_after_set['array_version'] ?? null )
				&& array( $replacement ) === array_values( $after_set[ $side_b ] ?? array() )
				&& $side_b === $replacement_side
				&& $filtered_side === $filtered_lookup
				&& $parse_ok,
			'wp_get_sidebars_widgets caches front-end option state, filters returned sidebars, and parses final numeric suffixes',
			array(
				'initial'         => $initial,
				'first'           => $first,
				'cached'          => $cached,
				'storedAfterSet'  => $stored_after_set,
				'afterSet'        => $after_set,
				'filterSeen'      => $filter_seen,
				'replacementSide' => $replacement_side,
				'filteredLookup'  => $filtered_lookup,
				'parseExpected'   => $parse_cases,
				'parseObserved'   => $parse_observed,
			)
		);

		return self::row(
			$ctx,
			'widgets.sidebars.option-cache-filter-and-id-parsing',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_widgets;

		$failures   = array();
		$case       = self::widget_case( $ctx->fork( 'render-widget' ), 'render' );
		$sidebar    = self::sidebar_case( $ctx->fork( 'render-sidebar' ), 'render' );
		$id_base    = $case['idBase'];
		$sidebar_id = $sidebar['id'];
		$title      = $case['title'];
		$inactive_title = $case['altTitle'];
		$widget     = self::widget_instance( $id_base, $case );

		\register_sidebar( $sidebar['args'] );
		\update_option(
			'widget_' . $id_base,
			array(
				$case['number'] => array(
					'title'   => $title,
					'content' => $case['content'],
				),
				$case['altNumber'] => array(
					'title'   => $inactive_title,
					'content' => $case['altContent'],
				),
				'_multiwidget' => 1,
			)
		);
		\register_widget( $widget );
		$GLOBALS['wp_widget_factory']->_register_widgets();

		$widget_id = $id_base . '-' . $case['number'];
		$inactive_widget_id = $id_base . '-' . $case['altNumber'];
		\wp_set_sidebars_widgets(
			array(
				$sidebar_id            => array( $widget_id ),
				'wp_inactive_widgets'  => array( $inactive_widget_id ),
			)
		);
		$GLOBALS['_wp_sidebars_widgets'] = array(
			$sidebar_id           => array( $widget_id ),
			'wp_inactive_widgets' => array( $inactive_widget_id ),
		);
		$GLOBALS['sidebars_widgets'] = $GLOBALS['_wp_sidebars_widgets'];

		self::$widget_calls = array();
		$rendered           = \wp_render_widget( $widget_id, $sidebar_id );
		$render_calls       = self::$widget_calls;
		self::$widget_calls = array();
		ob_start();
		$dynamic_result = \dynamic_sidebar( $sidebar['args']['name'] );
		$dynamic_output = ob_get_clean();
		$dynamic_calls  = self::$widget_calls;

		self::collect_failure(
			$failures,
			isset( $wp_registered_widgets[ $widget_id ] )
				&& 1 === count( $render_calls )
				&& $sidebar_id === ( $render_calls[0]['args']['sidebar_id'] ?? null )
				&& $widget_id === ( $render_calls[0]['args']['widget_id'] ?? null )
				&& $widget->name === ( $render_calls[0]['args']['widget_name'] ?? null )
				&& $title === ( $render_calls[0]['instance']['title'] ?? null )
				&& str_contains( $rendered, '<section id="' . $widget_id . '"' )
				&& str_contains( $rendered, '<h3 class="cfz-title"><span class="cfz-widget-title">' . \esc_html( $title ) . '</span></h3>' )
				&& str_contains( $rendered, '<div class="cfz-widget-content">' . \esc_html( $case['content'] ) . '</div>' )
				&& ! str_contains( $rendered, $title )
				&& str_ends_with( $rendered, '</section>' )
				&& true === $dynamic_result
				&& 1 === count( $dynamic_calls )
				&& $sidebar_id === ( $dynamic_calls[0]['args']['sidebar_id'] ?? null )
				&& str_contains( $dynamic_output, '<aside id="' . $sidebar_id . '" class="' . $sidebar['args']['class'] . '">' )
				&& str_contains( $dynamic_output, $rendered )
				&& ! str_contains( $dynamic_output, \esc_html( $inactive_title ) )
				&& str_ends_with( $dynamic_output, '</aside>' ),
			'wp_render_widget and dynamic_sidebar pass callback args, escape hostile HTML, and leave inactive widgets out of active sidebars',
			array(
				'widgetId'         => $widget_id,
				'inactiveWidgetId' => $inactive_widget_id,
				'registered'       => $wp_registered_widgets[ $widget_id ] ?? null,
				'rendered'         => self::describe_string( $rendered ),
				'dynamicOutput'    => self::describe_string( $dynamic_output ),
				'dynamicResult'    => $dynamic_result,
				'renderCalls'      => $render_calls,
				'dynamicCalls'     => $dynamic_calls,
			)
		);

		return self::row(
			$ctx,
			'widgets.render.callbacks-and-wrappers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_the_widget_helper( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::widget_case( $ctx->fork( 'the-widget' ), 'the_widget' );
		$widget   = self::widget_instance( $case['idBase'], $case );
		$key      = (string) spl_object_id( $widget );
		$instance = array(
			'title'   => 'Original ' . $case['title'],
			'content' => 'Original ' . $case['content'],
		);
		$args     = array(
			'before_widget' => '<article class="direct %s" data-widget="yes">',
			'after_widget'  => '</article>',
			'before_title'  => '<h4 class="direct-title">',
			'after_title'   => '</h4>',
		);
		self::$the_widget_events = array();

		$display_filter = static function ( $filtered_instance, \WP_Widget $widget_obj, array $filtered_args ) use ( $case, $widget ) {
			self::$the_widget_events[] = array(
				'type'         => 'display-filter',
				'sameWidget'   => $widget_obj === $widget,
				'beforeWidget' => $filtered_args['before_widget'] ?? null,
				'title'        => $filtered_instance['title'] ?? null,
			);

			$filtered_instance['title']   = 'Filtered ' . $case['title'];
			$filtered_instance['content'] = 'Filtered ' . $case['content'];
			return $filtered_instance;
		};
		$cancel_filter  = static function () {
			return false;
		};
		$action         = static function ( string $widget_name, array $action_instance, array $action_args ) use ( $key ): void {
			self::$the_widget_events[] = array(
				'type'         => 'action',
				'widget'       => $widget_name,
				'isKey'        => $key === $widget_name,
				'title'        => $action_instance['title'] ?? null,
				'beforeWidget' => $action_args['before_widget'] ?? null,
			);
		};

		\register_widget( $widget );
		self::$widget_calls = array();
		\add_filter( 'widget_display_callback', $display_filter, 10, 3 );
		\add_action( 'the_widget', $action, 10, 3 );
		try {
			$output = self::capture_output(
				static function () use ( $key, $instance, $args ): void {
					\the_widget( $key, $instance, $args );
				}
			);
			$calls  = self::$widget_calls;
			$events = self::$the_widget_events;

			self::$widget_calls = array();
			self::$the_widget_events = array();
			\remove_filter( 'widget_display_callback', $display_filter, 10 );
			\add_filter( 'widget_display_callback', $cancel_filter, 10, 3 );
			$cancelled_output = self::capture_output(
				static function () use ( $key, $instance, $args ): void {
					\the_widget( $key, $instance, $args );
				}
			);
			$cancelled_calls  = self::$widget_calls;
			$cancelled_events = self::$the_widget_events;
		} finally {
			\remove_filter( 'widget_display_callback', $display_filter, 10 );
			\remove_filter( 'widget_display_callback', $cancel_filter, 10 );
			\remove_action( 'the_widget', $action, 10 );
			\unregister_widget( $widget );
		}

		self::collect_failure(
			$failures,
			3 === count( $events )
				&& 'display-filter' === ( $events[0]['type'] ?? null )
				&& true === ( $events[0]['sameWidget'] ?? null )
				&& str_contains( (string) ( $events[0]['beforeWidget'] ?? '' ), $case['className'] )
				&& 'action' === ( $events[1]['type'] ?? null )
				&& true === ( $events[1]['isKey'] ?? null )
				&& 'Filtered ' . $case['title'] === ( $events[1]['title'] ?? null )
				&& str_contains( (string) ( $events[1]['beforeWidget'] ?? '' ), $case['className'] )
				&& 'callback' === ( $events[2]['type'] ?? null )
				&& 'Filtered ' . $case['title'] === ( $events[2]['title'] ?? null ),
			'the_widget() applies display filters, runs the action, then invokes the widget callback with substituted args',
			array( 'events' => $events )
		);

		self::collect_failure(
			$failures,
			1 === count( $calls )
				&& 'Filtered ' . $case['title'] === ( $calls[0]['instance']['title'] ?? null )
				&& 'Filtered ' . $case['content'] === ( $calls[0]['instance']['content'] ?? null )
				&& str_contains( $output, '<article class="direct ' . $case['className'] . '" data-widget="yes">' )
				&& str_contains( $output, '<h4 class="direct-title"><span class="cfz-widget-title">' . \esc_html( 'Filtered ' . $case['title'] ) . '</span></h4>' )
				&& str_contains( $output, '<div class="cfz-widget-content">' . \esc_html( 'Filtered ' . $case['content'] ) . '</div>' )
				&& ! str_contains( $output, 'Original ' . $case['title'] )
				&& ! str_contains( $output, 'Original ' . $case['content'] )
				&& ! str_contains( strtolower( $output ), '<script' ),
			'the_widget() renders filtered instance data through the widget callback with escaped wrappers and content',
			array(
				'output' => self::describe_string( $output ),
				'calls'  => $calls,
			)
		);

		self::collect_failure(
			$failures,
			'' === $cancelled_output
				&& array() === $cancelled_calls
				&& array() === $cancelled_events
				&& false === \has_filter( 'widget_display_callback', $display_filter )
				&& false === \has_filter( 'widget_display_callback', $cancel_filter )
				&& false === \has_action( 'the_widget', $action ),
			'the_widget() honors false display filter cancellation and removes filters/actions after the check',
			array(
				'cancelledOutput' => self::describe_string( $cancelled_output ),
				'cancelledCalls'  => $cancelled_calls,
				'cancelledEvents' => $cancelled_events,
				'displayFilter'   => \has_filter( 'widget_display_callback', $display_filter ),
				'cancelFilter'    => \has_filter( 'widget_display_callback', $cancel_filter ),
				'action'          => \has_action( 'the_widget', $action ),
			)
		);

		return self::row(
			$ctx,
			'widgets.render.the-widget-display-filter',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_dynamic_sidebar_action_order( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_widgets;

		$failures      = array();
		$sidebar       = self::sidebar_case( $ctx->fork( 'ordered-sidebar' ), 'ordered' );
		$empty_sidebar = self::sidebar_case( $ctx->fork( 'empty-sidebar' ), 'ordered_empty' );
		$payload_a     = self::direct_widget_case( $ctx->fork( 'ordered-widget-a' ) );
		$payload_b     = self::direct_widget_case( $ctx->fork( 'ordered-widget-b' ) );
		$sidebar_id    = $sidebar['id'];
		$empty_id      = $empty_sidebar['id'];
		$widget_a      = $payload_a['id'];
		$widget_b      = $payload_b['id'];
		$class_a       = 'cfz-order-a_' . $payload_a['variantClass'];
		$class_b       = 'cfz-order-b_' . $payload_b['variantClass'];
		$sidebars      = array(
			$sidebar_id            => array( $widget_a, $widget_b ),
			$empty_id              => array(),
			'wp_inactive_widgets'  => array(),
		);
		$active_checks = array();

		$before_action = static function ( $index, $has_widgets ): void {
			self::$dynamic_sidebar_events[] = array(
				'type'       => 'before',
				'index'      => (string) $index,
				'hasWidgets' => (bool) $has_widgets,
			);
		};
		$after_action  = static function ( $index, $has_widgets ): void {
			self::$dynamic_sidebar_events[] = array(
				'type'       => 'after',
				'index'      => (string) $index,
				'hasWidgets' => (bool) $has_widgets,
			);
		};
		$params_filter = static function ( $params ) {
			$widget_id  = is_array( $params ) && isset( $params[0]['widget_id'] ) ? (string) $params[0]['widget_id'] : '';
			$sidebar_id = is_array( $params ) && isset( $params[0]['id'] ) ? (string) $params[0]['id'] : '';

			self::$dynamic_sidebar_events[] = array(
				'type'      => 'params',
				'widgetId'  => $widget_id,
				'sidebarId' => $sidebar_id,
			);

			if ( is_array( $params ) && isset( $params[0] ) && is_array( $params[0] ) ) {
				$params[0]['before_title'] = '<h4 data-cfz-title="' . \esc_attr( $widget_id ) . '">';
				$params[0]['after_title']  = '</h4>';
			}

			return $params;
		};
		$widget_action = static function ( $widget ): void {
			self::$dynamic_sidebar_events[] = array(
				'type'     => 'widget',
				'widgetId' => is_array( $widget ) && isset( $widget['id'] ) ? (string) $widget['id'] : '',
				'name'     => is_array( $widget ) && isset( $widget['name'] ) ? (string) $widget['name'] : '',
			);
		};
		$has_filter    = static function ( $did_one, $index ) {
			self::$dynamic_sidebar_events[] = array(
				'type'   => 'has',
				'index'  => (string) $index,
				'didOne' => (bool) $did_one,
			);
			return $did_one;
		};
		$active_filter = static function ( $is_active, $index ) use ( &$active_checks ) {
			$active_checks[] = array(
				'index'    => (string) $index,
				'isActive' => (bool) $is_active,
			);
			return $is_active;
		};

		\register_sidebar( $sidebar['args'] );
		\register_sidebar( $empty_sidebar['args'] );
		\wp_register_sidebar_widget(
			$widget_a,
			$payload_a['name'],
			array( self::class, 'action_order_widget_callback' ),
			array(
				'classname'   => array( 'cfz-order-a', $payload_a['variantClass'] ),
				'description' => $payload_a['description'],
			),
			$payload_a
		);
		\wp_register_sidebar_widget(
			$widget_b,
			$payload_b['name'],
			array( self::class, 'action_order_widget_callback' ),
			array(
				'classname'   => array( 'cfz-order-b', $payload_b['variantClass'] ),
				'description' => $payload_b['description'],
			),
			$payload_b
		);
		\wp_set_sidebars_widgets( $sidebars );
		$GLOBALS['_wp_sidebars_widgets'] = $sidebars;
		$GLOBALS['sidebars_widgets']     = $sidebars;

		try {
			\add_action( 'dynamic_sidebar_before', $before_action, 10, 2 );
			\add_filter( 'dynamic_sidebar_params', $params_filter );
			\add_action( 'dynamic_sidebar', $widget_action );
			\add_action( 'dynamic_sidebar_after', $after_action, 10, 2 );
			\add_filter( 'dynamic_sidebar_has_widgets', $has_filter, 10, 2 );
			\add_filter( 'is_active_sidebar', $active_filter, 10, 2 );

			$active_by_id = \is_active_sidebar( $sidebar_id );
			$empty_active = \is_active_sidebar( $empty_id );

			self::$direct_widget_calls     = array();
			self::$dynamic_sidebar_events = array();
			ob_start();
			$active_result = \dynamic_sidebar( $sidebar_id );
			$active_output = ob_get_clean();
			$active_calls  = self::$direct_widget_calls;

			self::$direct_widget_calls = array();
			ob_start();
			$empty_result = \dynamic_sidebar( $empty_id );
			$empty_output = ob_get_clean();
			$empty_calls  = self::$direct_widget_calls;
			$events       = self::$dynamic_sidebar_events;
		} finally {
			\remove_action( 'dynamic_sidebar_before', $before_action, 10 );
			\remove_filter( 'dynamic_sidebar_params', $params_filter );
			\remove_action( 'dynamic_sidebar', $widget_action );
			\remove_action( 'dynamic_sidebar_after', $after_action, 10 );
			\remove_filter( 'dynamic_sidebar_has_widgets', $has_filter, 10 );
			\remove_filter( 'is_active_sidebar', $active_filter, 10 );
		}

		$event_summary = array_map( array( self::class, 'dynamic_sidebar_event_label' ), $events );
		$expected_events = array(
			'before:' . $sidebar_id . ':1',
			'params:' . $widget_a,
			'widget:' . $widget_a,
			'callback:' . $widget_a,
			'params:' . $widget_b,
			'widget:' . $widget_b,
			'callback:' . $widget_b,
			'after:' . $sidebar_id . ':1',
			'has:' . $sidebar_id . ':1',
			'before:' . $empty_id . ':0',
			'after:' . $empty_id . ':0',
			'has:' . $empty_id . ':0',
		);
		$expected_active_checks = array(
			array(
				'index'    => $sidebar_id,
				'isActive' => true,
			),
			array(
				'index'    => $empty_id,
				'isActive' => false,
			),
		);
		$first_widget_position  = strpos( $active_output, 'id="' . $widget_a . '"' );
		$second_widget_position = strpos( $active_output, 'id="' . $widget_b . '"' );
		$active_registered      = array_intersect_key( $wp_registered_widgets, array_flip( array( $widget_a, $widget_b ) ) );

		self::collect_failure(
			$failures,
			true === $active_by_id
				&& false === $empty_active
				&& $expected_active_checks === $active_checks
				&& true === $active_result
				&& false === $empty_result
				&& '' === $empty_output
				&& array() === $empty_calls
				&& $expected_events === $event_summary
				&& 2 === count( $active_calls )
				&& $widget_a === ( $active_calls[0]['args']['widget_id'] ?? null )
				&& $widget_b === ( $active_calls[1]['args']['widget_id'] ?? null )
				&& $payload_a['label'] === ( $active_calls[0]['payload']['label'] ?? null )
				&& $payload_b['label'] === ( $active_calls[1]['payload']['label'] ?? null )
				&& '<h4 data-cfz-title="' . $widget_a . '">' === ( $active_calls[0]['args']['before_title'] ?? null )
				&& '<h4 data-cfz-title="' . $widget_b . '">' === ( $active_calls[1]['args']['before_title'] ?? null )
				&& str_starts_with( $active_output, '<aside id="' . $sidebar_id . '" class="' . $sidebar['args']['class'] . '">' )
				&& str_contains( $active_output, 'class="widget ' . $class_a . '"' )
				&& str_contains( $active_output, 'class="widget ' . $class_b . '"' )
				&& str_contains( $active_output, '<h4 data-cfz-title="' . $widget_a . '"><span class="cfz-direct-widget-label">' . \esc_html( $payload_a['label'] ) . '</span></h4>' )
				&& str_contains( $active_output, '<h4 data-cfz-title="' . $widget_b . '"><span class="cfz-direct-widget-label">' . \esc_html( $payload_b['label'] ) . '</span></h4>' )
				&& false !== $first_widget_position
				&& false !== $second_widget_position
				&& $first_widget_position < $second_widget_position
				&& str_ends_with( $active_output, '</aside>' ),
			'dynamic_sidebar preserves active/empty hook order, callback params, and rendered widget ordering',
			array(
				'sidebarId'      => $sidebar_id,
				'emptySidebarId' => $empty_id,
				'widgets'        => array( $widget_a, $widget_b ),
				'registered'     => $active_registered,
				'activeChecks'   => $active_checks,
				'expectedEvents' => $expected_events,
				'events'         => $events,
				'eventSummary'   => $event_summary,
				'activeResult'   => $active_result,
				'emptyResult'    => $empty_result,
				'activeOutput'   => self::describe_string( $active_output ),
				'emptyOutput'    => self::describe_string( $empty_output ),
				'activeCalls'    => $active_calls,
				'emptyCalls'     => $empty_calls,
			)
		);

		\wp_unregister_sidebar_widget( $widget_a );
		\wp_unregister_sidebar_widget( $widget_b );
		\unregister_sidebar( $sidebar_id );
		\unregister_sidebar( $empty_id );
		self::$direct_widget_calls     = array();
		self::$dynamic_sidebar_events = array();

		return self::row(
			$ctx,
			'widgets.dynamic-sidebar.actions-order-and-empty-state',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function widget_instance( string $id_base, array $case = array() ): \WP_Widget {
		return new class( $id_base, $case ) extends \WP_Widget {
			/** @var array<string,mixed> */
			private array $case;

			public function __construct( string $id_base, array $case ) {
				$this->case = $case;
				parent::__construct(
					$id_base,
					(string) ( $case['name'] ?? 'Component Fuzz Widget' ),
					array(
						'classname'                   => (string) ( $case['className'] ?? 'component_fuzz_widget' ),
						'description'                 => (string) ( $case['description'] ?? 'Synthetic component fuzz widget.' ),
						'customize_selective_refresh' => (bool) ( $case['selectiveRefresh'] ?? true ),
						'show_instance_in_rest'       => true,
					),
					array(
						'height' => (int) ( $case['controlHeight'] ?? 240 ),
						'width'  => (int) ( $case['controlWidth'] ?? 320 ),
					)
				);
			}

			public function widget( $args, $instance ): void {
				$title   = isset( $instance['title'] ) ? (string) $instance['title'] : '';
				$content = isset( $instance['content'] ) ? (string) $instance['content'] : '';
				WidgetsSurface::record_widget_call( (string) $this->id, $args, $instance );
				echo $args['before_widget'] ?? '';
				if ( '' !== $title ) {
					echo $args['before_title'] ?? '';
					echo '<span class="cfz-widget-title">' . \esc_html( $title ) . '</span>';
					echo $args['after_title'] ?? '';
				}
				echo '<div class="cfz-widget-content">' . \esc_html( $content ) . '</div>';
				echo $args['after_widget'] ?? '';
			}

			public function form( $instance ) {
				$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
				echo '<p class="cfz-widget-control">';
				echo '<label for="' . \esc_attr( $this->get_field_id( 'title' ) ) . '">Title</label>';
				echo '<input id="' . \esc_attr( $this->get_field_id( 'title' ) ) . '" name="' . \esc_attr( $this->get_field_name( 'title' ) ) . '" value="' . \esc_attr( $title ) . '">';
				echo '</p>';
				return null;
			}

			public function update( $new_instance, $old_instance ) {
				unset( $old_instance );
				return is_array( $new_instance ) ? $new_instance : array();
			}
		};
	}

	private static function sidebar_case( \ComponentFuzz\FuzzContext $ctx, string $label ): array {
		$id = self::id( $ctx->fork( 'id' ), 'sidebar_' . $label );
		return array(
			'id'   => $id,
			'args' => array(
				'id'             => $id,
				'name'           => 'Component Fuzz ' . ucfirst( $label ) . ' ' . $ctx->int( 10, 999 ),
				'description'    => '<strong>' . $label . '</strong> ' . self::hostile_label( $ctx->fork( 'description' ) ),
				'class'          => 'cfz-' . $label . '-sidebar',
				'before_widget'  => '<section id="%1$s" class="widget %2$s">',
				'after_widget'   => '</section>',
				'before_title'   => '<h3 class="cfz-title">',
				'after_title'    => '</h3>',
				'before_sidebar' => '<aside id="%1$s" class="%2$s">',
				'after_sidebar'  => '</aside>',
				'show_in_rest'   => $ctx->bool(),
			),
		);
	}

	private static function widget_case( \ComponentFuzz\FuzzContext $ctx, string $label ): array {
		$number = $ctx->int( 2, 8 );
		$alt_number = $number + $ctx->int( 9, 17 );
		return array(
			'idBase'           => self::id( $ctx->fork( 'id-base' ), 'cfz_' . $label ),
			'name'             => 'Component Fuzz ' . ucfirst( $label ),
			'className'        => 'component_fuzz_' . $label,
			'description'      => 'Widget description ' . self::hostile_label( $ctx->fork( 'description' ) ),
			'number'           => $number,
			'altNumber'        => $alt_number,
			'title'            => ucfirst( $label ) . ' ' . self::hostile_label( $ctx->fork( 'title' ) ),
			'altTitle'         => ucfirst( $label ) . ' inactive ' . self::hostile_label( $ctx->fork( 'alt-title' ) ),
			'content'          => 'Body ' . self::hostile_label( $ctx->fork( 'content' ) ),
			'altContent'       => 'Inactive body ' . self::hostile_label( $ctx->fork( 'alt-content' ) ),
			'controlHeight'    => 210 + $ctx->int( 1, 30 ),
			'controlWidth'     => 300 + $ctx->int( 1, 40 ),
			'selectiveRefresh' => $ctx->bool(),
		);
	}

	private static function direct_widget_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$number        = $ctx->int( 3, 11 );
		$id_base       = self::id( $ctx->fork( 'id-base' ), 'direct_widget' );
		$variant_class = 'variant_' . strtolower( $ctx->identifier( 3, 7 ) );

		return array(
			'id'           => $id_base . '-' . $number,
			'idBase'       => $id_base,
			'name'         => 'Direct Widget ' . $ctx->int( 100, 999 ),
			'number'       => $number,
			'variantClass' => $variant_class,
			'label'        => 'Direct ' . self::hostile_label( $ctx->fork( 'label' ) ),
			'description'  => 'Direct description ' . self::hostile_label( $ctx->fork( 'description' ) ),
		);
	}

	private static function hostile_label( \ComponentFuzz\FuzzContext $ctx ): string {
		return $ctx->identifier( 3, 8 ) . ' <script>alert(1)</script> & "quoted" <img src=x onerror=alert(2)>';
	}

	private static function normalize_sidebars_widgets( array $sidebars_widgets ): array {
		unset( $sidebars_widgets['array_version'] );
		foreach ( $sidebars_widgets as $sidebar => $widgets ) {
			$sidebars_widgets[ $sidebar ] = array_values( (array) $widgets );
		}

		return $sidebars_widgets;
	}

	private static function last_update( array $updates ): array {
		if ( array() === $updates ) {
			return array();
		}

		$last = end( $updates );
		return is_array( $last ) ? $last : array();
	}

	private static function dynamic_sidebar_event_label( array $event ): string {
		if ( in_array( $event['type'] ?? '', array( 'before', 'after' ), true ) ) {
			return $event['type'] . ':' . ( $event['index'] ?? '' ) . ':' . ( ! empty( $event['hasWidgets'] ) ? '1' : '0' );
		}

		if ( 'has' === ( $event['type'] ?? '' ) ) {
			return 'has:' . ( $event['index'] ?? '' ) . ':' . ( ! empty( $event['didOne'] ) ? '1' : '0' );
		}

		return ( $event['type'] ?? '' ) . ':' . ( $event['widgetId'] ?? '' );
	}

	private static function id( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		return strtolower( substr( $prefix . '_' . hash( 'crc32b', (string) $ctx->seed() ), 0, 32 ) );
	}

	private static function reset_runtime(): void {
		$GLOBALS['wp_registered_sidebars']        = array();
		$GLOBALS['wp_registered_widgets']         = array();
		$GLOBALS['wp_registered_widget_controls'] = array();
		$GLOBALS['wp_registered_widget_updates']  = array();
		$GLOBALS['_wp_sidebars_widgets']          = array();
		$GLOBALS['sidebars_widgets']              = array();
		$GLOBALS['wp_widget_factory']             = new \WP_Widget_Factory();
		$GLOBALS['_wp_theme_features']            = array();
		unset( $GLOBALS['current_screen'] );
		self::$widget_calls                       = array();
		self::$the_widget_events                  = array();
		self::$direct_widget_calls                = array();
		self::$direct_control_calls               = array();
		self::$dynamic_sidebar_events             = array();

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'blog_charset'     => 'UTF-8',
					'blogdescription'  => 'Component Fuzz Site',
					'blogname'         => 'Component Fuzz',
					'home'             => 'http://example.test',
					'sidebars_widgets' => array(),
					'siteurl'          => 'http://example.test',
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
		if ( function_exists( 'wp_set_current_user' ) ) {
			\wp_set_current_user( 0 );
		}
	}

	private static function check_no_external_db_or_admin_dispatch( \ComponentFuzz\FuzzContext $ctx, array $runtime_snapshot ): array {
		$failures   = array();
		$using_stub = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub;
		$pagenow    = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		self::collect_failure(
			$failures,
			$using_stub && false === (bool) $GLOBALS['wpdb']->is_mysql,
			'widgets surface must use the in-memory WPDB stub instead of a live MySQL connection',
			array(
				'wpdb'    => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? get_class( $GLOBALS['wpdb'] ) : gettype( $GLOBALS['wpdb'] ?? null ),
				'isMysql' => $using_stub ? $GLOBALS['wpdb']->is_mysql : null,
			)
		);

		if ( $using_stub ) {
			self::collect_failure(
				$failures,
				( $runtime_snapshot['contentCounts'] ?? array() ) === $GLOBALS['wpdb']->component_fuzz_content_counts(),
				'widgets surface does not create content rows while exercising widget options',
				array(
					'before' => $runtime_snapshot['contentCounts'] ?? array(),
					'after'  => $GLOBALS['wpdb']->component_fuzz_content_counts(),
				)
			);
		}

		self::collect_failure(
			$failures,
			! in_array( $pagenow, array( 'widgets.php', 'customize.php', 'index.php' ), true ),
			'widgets surface avoids admin page dispatch and dashboard entrypoints',
			array( 'pagenow' => $pagenow )
		);

		return self::row(
			$ctx,
			'widgets.environment.no-external-db-or-admin-dispatch',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_state_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$failures = array();

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			self::collect_failure(
				$failures,
				( $snapshot['options'] ?? array() ) === $GLOBALS['wpdb']->component_fuzz_get_options(),
				'WPDB stub options are restored after widgets fuzzing',
				array(
					'expected' => $snapshot['options'] ?? array(),
					'actual'   => $GLOBALS['wpdb']->component_fuzz_get_options(),
				)
			);

			self::collect_failure(
				$failures,
				( $snapshot['contentCounts'] ?? array() ) === $GLOBALS['wpdb']->component_fuzz_content_counts(),
				'WPDB stub content counts are unchanged after widgets fuzzing',
				array(
					'expected' => $snapshot['contentCounts'] ?? array(),
					'actual'   => $GLOBALS['wpdb']->component_fuzz_content_counts(),
				)
			);
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			self::collect_failure(
				$failures,
				(bool) $entry['exists'] === array_key_exists( $name, $GLOBALS ),
				"global {$name} existence is restored",
				array(
					'expectedExists' => (bool) $entry['exists'],
					'actualExists'   => array_key_exists( $name, $GLOBALS ),
				)
			);

			if ( ! $entry['exists'] || ! array_key_exists( $name, $GLOBALS ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				self::state_signature( $entry['value'] ) === self::state_signature( $GLOBALS[ $name ] ),
				"global {$name} registry signature is restored",
				array(
					'expected' => self::state_signature( $entry['value'] ),
					'actual'   => self::state_signature( $GLOBALS[ $name ] ),
				)
			);
		}

		return self::row(
			$ctx,
			'widgets.environment.global-registry-restored',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function capture_output( callable $callback ): string {
		$level = ob_get_level();
		ob_start();

		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			throw $e;
		}
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

	private static function snapshot_state(): array {
		$snapshot = array(
			'widgetCalls'        => self::$widget_calls,
			'theWidgetEvents'    => self::$the_widget_events,
			'directWidgetCalls'  => self::$direct_widget_calls,
			'directControlCalls' => self::$direct_control_calls,
			'dynamicSidebarEvents' => self::$dynamic_sidebar_events,
			'contentCounts'      => array(),
			'options'            => null,
			'globals'            => array(),
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$snapshot['options'] = $GLOBALS['wpdb']->component_fuzz_get_options();
		}
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			$snapshot['contentCounts'] = $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		foreach (
			array(
				'_wp_theme_features',
				'_wp_sidebars_widgets',
				'current_screen',
				'current_user',
				'pagenow',
				'sidebars_widgets',
				'user_ID',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_object_cache',
				'wp_registered_sidebars',
				'wp_registered_widgets',
				'wp_registered_widget_controls',
				'wp_registered_widget_updates',
				'wp_widget_factory',
			) as $name
		) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_state( array $snapshot ): void {
		self::$widget_calls         = $snapshot['widgetCalls'];
		self::$the_widget_events    = $snapshot['theWidgetEvents'];
		self::$direct_widget_calls  = $snapshot['directWidgetCalls'];
		self::$direct_control_calls = $snapshot['directControlCalls'];
		self::$dynamic_sidebar_events = $snapshot['dynamicSidebarEvents'];

		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function state_signature( $value ): array {
		if ( is_array( $value ) ) {
			$keys = array_map(
				static function ( $key ): string {
					return (string) $key;
				},
				array_slice( array_keys( $value ), 0, 24 )
			);

			$signature = array(
				'type'  => 'array',
				'count' => count( $value ),
				'keys'  => $keys,
			);

			if ( isset( $value['widgets_init'] ) ) {
				$signature['widgetsInitCallbacks'] = self::hook_callback_count( $value['widgets_init'] );
			}

			return $signature;
		}

		if ( is_object( $value ) ) {
			$signature = array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);

			if ( $value instanceof \WP_Widget_Factory ) {
				$signature['widgets'] = array_map(
					static function ( $key ): string {
						return (string) $key;
					},
					array_keys( $value->widgets )
				);
			}

			if ( class_exists( 'WP_Hook', false ) && $value instanceof \WP_Hook ) {
				$signature['callbacks'] = self::hook_callback_count( $value );
			}

			return $signature;
		}

		return array(
			'type'  => gettype( $value ),
			'value' => is_scalar( $value ) || null === $value ? $value : null,
		);
	}

	private static function hook_callback_count( $hook ): int {
		if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $hook->callbacks as $priority_callbacks ) {
			$count += is_array( $priority_callbacks ) ? count( $priority_callbacks ) : 0;
		}

		return $count;
	}

	private static function clone_value( $value ) {
		if ( $value instanceof \Closure ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			try {
				return clone $value;
			} catch ( \Throwable $e ) {
				unset( $e );
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
}
