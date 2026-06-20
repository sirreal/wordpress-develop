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
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_sidebar_registry( $ctx );
			$rows[] = self::check_widget_factory_registration( $ctx );
			$rows[] = self::check_sidebar_assignment( $ctx );
			$rows[] = self::check_widget_rendering( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'widgets.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	public static function record_widget_call( string $id, array $args, array $instance ): void {
		self::$widget_calls[] = array(
			'id'       => $id,
			'args'     => array(
				'widget_id'     => $args['widget_id'] ?? null,
				'widget_name'   => $args['widget_name'] ?? null,
				'before_widget' => $args['before_widget'] ?? null,
				'after_widget'  => $args['after_widget'] ?? null,
			),
			'instance' => $instance,
		);
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Widget', 'WP_Widget_Factory' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'dynamic_sidebar',
				'is_registered_sidebar',
				'register_sidebar',
				'register_widget',
				'unregister_sidebar',
				'unregister_widget',
				'wp_assign_widget_to_sidebar',
				'wp_find_widgets_sidebar',
				'wp_get_sidebars_widgets',
				'wp_parse_widget_id',
				'wp_render_widget',
				'wp_set_sidebars_widgets',
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
		$id       = self::id( $ctx->fork( 'sidebar' ), 'sidebar' );
		$args     = array(
			'id'             => $id,
			'name'           => 'Component Fuzz ' . $ctx->int( 10, 999 ),
			'description'    => '<b>desc</b> ' . $ctx->text( 0, 32 ),
			'class'          => 'cfz-class',
			'before_widget'  => '<section id="%1$s" class="widget %2$s">',
			'after_widget'   => '</section>',
			'before_title'   => '<h3>',
			'after_title'    => '</h3>',
			'before_sidebar' => '<aside id="%1$s" class="%2$s">',
			'after_sidebar'  => '</aside>',
			'show_in_rest'   => $ctx->bool(),
		);

		$registered = \register_sidebar( $args );
		self::collect_failure(
			$failures,
			$id === $registered
				&& \is_registered_sidebar( $id )
				&& isset( $wp_registered_sidebars[ $id ] )
				&& $args['name'] === $wp_registered_sidebars[ $id ]['name']
				&& $args['before_widget'] === $wp_registered_sidebars[ $id ]['before_widget']
				&& $args['after_sidebar'] === $wp_registered_sidebars[ $id ]['after_sidebar'],
			'register_sidebar preserves explicit sidebar shape',
			array(
				'args'       => $args,
				'registered' => $registered,
				'actual'     => $wp_registered_sidebars[ $id ] ?? null,
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

		return self::row(
			$ctx,
			'widgets.sidebars.registry-lifecycle',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_widget_factory_registration( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_widgets, $wp_widget_factory;

		$failures = array();
		$id_base  = self::id( $ctx->fork( 'factory' ), 'cfz_widget' );
		$widget   = self::widget_instance( $id_base );

		\update_option(
			'widget_' . $id_base,
			array(
				1              => array( 'title' => 'Factory ' . $ctx->int( 1, 99 ) ),
				'_multiwidget' => 1,
			)
		);
		\register_widget( $widget );
		$key = $wp_widget_factory->get_widget_key( $id_base );
		$wp_widget_factory->_register_widgets();

		$widget_id = $id_base . '-1';
		$parsed    = \wp_parse_widget_id( $widget_id );

		self::collect_failure(
			$failures,
			'' !== $key
				&& $widget === $wp_widget_factory->get_widget_object( $id_base )
				&& isset( $wp_registered_widgets[ $widget_id ] )
				&& $id_base === $parsed['id_base']
				&& 1 === $parsed['number'],
			'register_widget adds factory object and concrete widget instance',
			array(
				'idBase'     => $id_base,
				'key'        => $key,
				'widgetId'   => $widget_id,
				'parsed'     => $parsed,
				'registered' => $wp_registered_widgets[ $widget_id ] ?? null,
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

	private static function check_sidebar_assignment( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$widget   = self::id( $ctx->fork( 'assign-widget' ), 'cfz_widget' ) . '-1';
		$side_a   = self::id( $ctx->fork( 'side-a' ), 'sidebar_a' );
		$side_b   = self::id( $ctx->fork( 'side-b' ), 'sidebar_b' );
		$other    = self::id( $ctx->fork( 'other-widget' ), 'other' ) . '-2';
		$initial  = array(
			$side_a               => array( $widget, $other ),
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
			$after_move = self::normalize_sidebars_widgets( end( $updates ) ?: array() );
			$GLOBALS['_wp_sidebars_widgets'] = $after_move;
			$sidebar_after_move = \wp_find_widgets_sidebar( $widget );

			$updates = array();
			\wp_assign_widget_to_sidebar( $widget, '' );
			$after_remove = self::normalize_sidebars_widgets( end( $updates ) ?: array() );
			$GLOBALS['_wp_sidebars_widgets'] = $after_remove;
			$sidebar_after_remove = \wp_find_widgets_sidebar( $widget );
			$other_sidebar_after_remove = \wp_find_widgets_sidebar( $other );
		} finally {
			\remove_filter( 'pre_update_option_sidebars_widgets', $capture, 10 );
		}

		self::collect_failure(
			$failures,
			$side_a === $other_sidebar_after_remove
				&& isset( $before[ $side_a ], $before[ $side_b ] )
				&& in_array( $widget, $before[ $side_a ], true )
				&& $side_b === $sidebar_after_move
				&& ! in_array( $widget, $after_move[ $side_a ], true )
				&& array( $widget ) === array_values( $after_move[ $side_b ] )
				&& null === $sidebar_after_remove
				&& ! in_array( $widget, $after_remove[ $side_b ] ?? array(), true )
				&& in_array( $other, $after_remove[ $side_a ] ?? array(), true ),
			'wp_assign_widget_to_sidebar moves and removes exactly one widget id',
			array(
				'widget'      => $widget,
				'other'       => $other,
				'before'      => $before,
				'afterMove'   => $after_move,
				'moveSidebar' => $sidebar_after_move,
				'afterRemove' => $after_remove,
				'removeSidebar' => $sidebar_after_remove,
				'otherSidebar'  => $other_sidebar_after_remove,
			)
		);

		return self::row(
			$ctx,
			'widgets.sidebars.assignment-move-remove',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_widget_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_registered_widgets;

		$failures   = array();
		$id_base    = self::id( $ctx->fork( 'render-widget' ), 'cfz_render' );
		$sidebar_id = self::id( $ctx->fork( 'render-sidebar' ), 'render_sidebar' );
		$title      = 'Rendered ' . $ctx->int( 100, 999 );
		$widget     = self::widget_instance( $id_base );

		\register_sidebar(
			array(
				'id'             => $sidebar_id,
				'name'           => 'Render Sidebar',
				'class'          => 'render-class',
				'before_widget'  => '<section id="%1$s" class="%2$s">',
				'after_widget'   => '</section>',
				'before_sidebar' => '<aside id="%1$s" class="%2$s">',
				'after_sidebar'  => '</aside>',
			)
		);
		\update_option(
			'widget_' . $id_base,
			array(
				1              => array( 'title' => $title ),
				'_multiwidget' => 1,
			)
		);
		\register_widget( $widget );
		$GLOBALS['wp_widget_factory']->_register_widgets();

		$widget_id = $id_base . '-1';
		\wp_set_sidebars_widgets(
			array(
				$sidebar_id            => array( $widget_id ),
				'wp_inactive_widgets'  => array(),
			)
		);
		$GLOBALS['_wp_sidebars_widgets'] = array(
			$sidebar_id           => array( $widget_id ),
			'wp_inactive_widgets' => array(),
		);
		$GLOBALS['sidebars_widgets'] = $GLOBALS['_wp_sidebars_widgets'];

		self::$widget_calls = array();
		$rendered           = \wp_render_widget( $widget_id, $sidebar_id );
		$render_calls       = self::$widget_calls;
		self::$widget_calls = array();
		ob_start();
		$dynamic_result = \dynamic_sidebar( 'Render Sidebar' );
		$dynamic_output = ob_get_clean();
		$dynamic_calls  = self::$widget_calls;

		self::collect_failure(
			$failures,
			isset( $wp_registered_widgets[ $widget_id ] )
				&& 1 === count( $render_calls )
				&& str_contains( $rendered, '<section id="' . $widget_id . '"' )
				&& str_contains( $rendered, '<span class="cfz-widget-title">' . $title . '</span>' )
				&& str_ends_with( $rendered, '</section>' )
				&& true === $dynamic_result
				&& 1 === count( $dynamic_calls )
				&& str_contains( $dynamic_output, '<aside id="' . $sidebar_id . '" class="render-class">' )
				&& str_contains( $dynamic_output, $rendered )
				&& str_ends_with( $dynamic_output, '</aside>' ),
			'wp_render_widget and dynamic_sidebar invoke widget once with wrappers',
			array(
				'widgetId'      => $widget_id,
				'registered'    => $wp_registered_widgets[ $widget_id ] ?? null,
				'rendered'      => self::describe_string( $rendered ),
				'dynamicOutput' => self::describe_string( $dynamic_output ),
				'dynamicResult' => $dynamic_result,
				'renderCalls'   => $render_calls,
				'dynamicCalls'  => $dynamic_calls,
			)
		);

		return self::row(
			$ctx,
			'widgets.render.callbacks-and-wrappers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function widget_instance( string $id_base ): \WP_Widget {
		return new class( $id_base ) extends \WP_Widget {
			public function __construct( string $id_base ) {
				parent::__construct(
					$id_base,
					'Component Fuzz Widget',
					array(
						'classname'   => 'component_fuzz_widget',
						'description' => 'Synthetic component fuzz widget.',
					)
				);
			}

			public function widget( $args, $instance ): void {
				$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
				WidgetsSurface::record_widget_call( (string) $this->id, $args, $instance );
				echo $args['before_widget'];
				echo '<span class="cfz-widget-title">' . \esc_html( $title ) . '</span>';
				echo $args['after_widget'];
			}
		};
	}

	private static function normalize_sidebars_widgets( array $sidebars_widgets ): array {
		unset( $sidebars_widgets['array_version'] );
		foreach ( $sidebars_widgets as $sidebar => $widgets ) {
			$sidebars_widgets[ $sidebar ] = array_values( (array) $widgets );
		}

		return $sidebars_widgets;
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
		self::$widget_calls                       = array();
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
			'widgetCalls' => self::$widget_calls,
			'options'     => null,
			'globals'     => array(),
		);

		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$snapshot['options'] = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		foreach (
			array(
				'_wp_sidebars_widgets',
				'sidebars_widgets',
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
		self::$widget_calls = $snapshot['widgetCalls'];

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

	private static function clone_value( $value ) {
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
}
