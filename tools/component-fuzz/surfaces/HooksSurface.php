<?php
namespace ComponentFuzz\Surfaces;

final class HooksSurface {
	public const NAME = 'hooks';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$required = array(
			'add_filter',
			'add_action',
			'apply_filters',
			'do_action',
			'remove_filter',
			'remove_all_filters',
			'has_filter',
			'current_filter',
			'doing_filter',
			'did_action',
		);

		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				return array(
					$ctx->skip( 'hooks.unavailable', "Function {$function} is unavailable." ),
				);
			}
		}

		$snapshot = self::snapshot_hook_globals();
		$rows     = array();

		try {
			$rows[] = self::check_filter_priority_and_removal( $ctx );
			$rows[] = self::check_action_state( $ctx );
			$rows[] = self::check_nested_filter_stack( $ctx );
			$rows[] = self::check_remove_all_filters( $ctx );
		} finally {
			self::restore_hook_globals( $snapshot );
		}

		return $rows;
	}

	private static function check_filter_priority_and_removal( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag   = self::tag( $ctx, 'priority' );
		$calls = array();

		$early = static function ( $value ) use ( &$calls ) {
			$calls[] = 'early:' . $value;
			return $value . '|early';
		};
		$middle = static function ( $value, $extra ) use ( &$calls ) {
			$calls[] = 'middle:' . $extra;
			return $value . '|middle:' . $extra;
		};
		$late = static function ( $value ) use ( &$calls ) {
			$calls[] = 'late:' . $value;
			return $value . '|late';
		};

		add_filter( $tag, $middle, 20, 2 );
		add_filter( $tag, $early, 5, 1 );
		add_filter( $tag, $late, 20, 1 );

		$first = apply_filters( $tag, 'base', 'extra' );
		$first_calls = $calls;
		$has   = has_filter( $tag, $middle );
		$gone  = remove_filter( $tag, $middle, 20 );
		$calls = array();
		$second = apply_filters( $tag, 'base', 'extra' );
		$calls_after_remove = $calls;

		$ok = 'base|early|middle:extra|late' === $first
			&& 20 === $has
			&& true === $gone
			&& 'base|early|late' === $second
			&& array( 'early:base', 'middle:extra', 'late:base|early|middle:extra' ) === $first_calls
			&& array( 'early:base', 'late:base|early' ) === $calls_after_remove;

		return $ctx->result(
			'hooks.filter-priority-removal',
			$ok,
			array(
				'first'            => $first,
				'second'           => $second,
				'hasMiddle'        => $has,
				'removedMiddle'    => $gone,
				'firstCalls'       => $first_calls,
				'callsAfterRemove' => $calls_after_remove,
			)
		);
	}

	private static function check_action_state( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag        = self::tag( $ctx, 'action' );
		$before     = did_action( $tag );
		$seen       = array();
		$callback   = static function ( $first, $second ) use ( &$seen, $tag ) {
			$seen[] = array(
				'args'          => array( $first, $second ),
				'currentFilter' => current_filter(),
				'doingTag'      => doing_filter( $tag ),
				'doingAny'      => doing_filter(),
			);
		};

		add_action( $tag, $callback, 10, 2 );
		do_action( $tag, 'alpha', 'beta' );
		$after = did_action( $tag );

		$ok = 0 === $before
			&& 1 === $after
			&& array(
				array(
					'args'          => array( 'alpha', 'beta' ),
					'currentFilter' => $tag,
					'doingTag'      => true,
					'doingAny'      => true,
				),
			) === $seen;

		return $ctx->result(
			'hooks.action-current-filter-state',
			$ok,
			array(
				'didBefore' => $before,
				'didAfter'  => $after,
				'seen'      => $seen,
			)
		);
	}

	private static function check_nested_filter_stack( \ComponentFuzz\FuzzContext $ctx ): array {
		$outer = self::tag( $ctx, 'outer' );
		$inner = self::tag( $ctx, 'inner' );
		$seen  = array();

		add_filter(
			$inner,
			static function ( $value ) use ( &$seen, $outer, $inner ) {
				$seen[] = array(
					'phase'        => 'inner',
					'current'      => current_filter(),
					'doingOuter'   => doing_filter( $outer ),
					'doingInner'   => doing_filter( $inner ),
					'inputPreview' => $value,
				);
				return $value . '|inner';
			},
			10,
			1
		);

		add_filter(
			$outer,
			static function ( $value ) use ( &$seen, $inner ) {
				$seen[] = array(
					'phase'   => 'outer-before',
					'current' => current_filter(),
				);
				$value = apply_filters( $inner, $value . '|outer' );
				$seen[] = array(
					'phase'   => 'outer-after',
					'current' => current_filter(),
				);
				return $value . '|done';
			},
			10,
			1
		);

		$result = apply_filters( $outer, 'base' );

		$ok = 'base|outer|inner|done' === $result
			&& array( 'outer-before', 'inner', 'outer-after' ) === array_column( $seen, 'phase' )
			&& $outer === $seen[0]['current']
			&& $inner === $seen[1]['current']
			&& true === $seen[1]['doingOuter']
			&& true === $seen[1]['doingInner']
			&& $outer === $seen[2]['current'];

		return $ctx->result(
			'hooks.nested-filter-stack',
			$ok,
			array(
				'result' => $result,
				'seen'   => $seen,
			)
		);
	}

	private static function check_remove_all_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag = self::tag( $ctx, 'remove-all' );

		add_filter(
			$tag,
			static function ( $value ) {
				return $value . '|first';
			},
			9,
			1
		);
		add_filter(
			$tag,
			static function ( $value ) {
				return $value . '|second';
			},
			11,
			1
		);

		remove_all_filters( $tag, 9 );
		$after_priority_remove = apply_filters( $tag, 'base' );
		remove_all_filters( $tag );
		$after_all_remove = apply_filters( $tag, 'base' );

		$ok = 'base|second' === $after_priority_remove
			&& 'base' === $after_all_remove
			&& false === has_filter( $tag );

		return $ctx->result(
			'hooks.remove-all-filters',
			$ok,
			array(
				'afterPriorityRemove' => $after_priority_remove,
				'afterAllRemove'      => $after_all_remove,
				'hasFilter'           => has_filter( $tag ),
			)
		);
	}

	private static function tag( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'component_fuzz_' . self::NAME . '_' . $label . '_' . $ctx->seed() . '_' . $ctx->iteration();
	}

	private static function snapshot_hook_globals(): array {
		return array(
			'wp_filter'         => $GLOBALS['wp_filter'] ?? null,
			'wp_actions'        => $GLOBALS['wp_actions'] ?? null,
			'wp_filters'        => $GLOBALS['wp_filters'] ?? null,
			'wp_current_filter' => $GLOBALS['wp_current_filter'] ?? null,
		);
	}

	private static function restore_hook_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $value;
			}
		}
	}
}
