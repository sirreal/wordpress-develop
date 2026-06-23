<?php
namespace ComponentFuzz\Surfaces;

final class HooksSurface {
	public const NAME = 'hooks';

	private const GENERATED_CALLBACKS = 7;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'hooks.bootstrap-apis-available',
					'Required WordPress hook APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_hook_globals();
		$rows     = array();

		try {
			$rows[] = self::check_generated_filter_pipeline( $ctx->fork( 'generated-filter-pipeline' ) );
			$rows[] = self::check_wp_hook_direct_api( $ctx->fork( 'wp-hook-direct' ) );
			$rows[] = self::check_has_filter_priority_semantics( $ctx->fork( 'has-filter-priority' ) );
			$rows[] = self::check_nested_dispatch_stack( $ctx->fork( 'nested-dispatch-stack' ) );
			$rows[] = self::check_dispatch_mutation( $ctx->fork( 'dispatch-mutation' ) );
			$rows[] = self::check_remove_all_filters( $ctx->fork( 'remove-all-filters' ) );
			$rows[] = self::check_action_and_filter_counters( $ctx->fork( 'counters' ) );
			$rows[] = self::check_reference_and_object_payloads( $ctx->fork( 'reference-object-payloads' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'hooks.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_hook_globals( $snapshot );
		}

		$rows[] = self::check_hook_globals_restored( $ctx->fork( 'state-restoration' ), $snapshot );

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'add_filter',
				'add_action',
				'apply_filters',
				'apply_filters_ref_array',
				'do_action',
				'do_action_ref_array',
				'remove_filter',
				'remove_action',
				'remove_all_filters',
				'remove_all_actions',
				'has_filter',
				'has_action',
				'current_filter',
				'current_action',
				'doing_filter',
				'doing_action',
				'did_filter',
				'did_action',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Hook', false ) ) {
			$missing[] = 'class WP_Hook';
		}

		return $missing;
	}

	private static function check_generated_filter_pipeline( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag          = self::tag( $ctx, 'generated-filter' );
		$extra_args   = array(
			'arg-' . $ctx->identifier( 3, 8 ),
			$ctx->int( -50, 50 ),
			array( 'seed' => $ctx->seed() ),
		);
		$value        = 'base-' . substr( hash( 'sha1', (string) $ctx->seed() ), 0, 8 );
		$specs        = self::generated_filter_specs( $ctx );
		$callbacks    = array();
		$actual_log   = array();
		$expected_log = array();
		$expected     = $value;
		$failures     = array();

		foreach ( $specs as $spec ) {
			$callback = static function ( ...$args ) use ( &$actual_log, $spec ) {
				$actual_log[] = array(
					'id'       => $spec['id'],
					'priority' => $spec['priority'],
					'accepted' => $spec['accepted'],
					'count'    => count( $args ),
					'args'     => self::describe_args( $args ),
				);

				if ( 0 === $spec['accepted'] ) {
					return 'zero-' . $spec['id'];
				}

				return (string) $args[0] . '|' . $spec['id'] . ':' . count( $args );
			};

			$callbacks[ $spec['id'] ] = $callback;
			add_filter( $tag, $callback, $spec['priority'], $spec['accepted'] );
		}

		foreach ( self::expected_filter_order( $specs ) as $spec ) {
			$incoming      = array_merge( array( $expected ), $extra_args );
			$received_args = self::expected_received_args( $incoming, $spec['accepted'] );

			$expected_log[] = array(
				'id'       => $spec['id'],
				'priority' => $spec['priority'],
				'accepted' => $spec['accepted'],
				'count'    => count( $received_args ),
				'args'     => self::describe_args( $received_args ),
			);

			if ( 0 === $spec['accepted'] ) {
				$expected = 'zero-' . $spec['id'];
			} else {
				$expected = (string) $received_args[0] . '|' . $spec['id'] . ':' . count( $received_args );
			}
		}

		$actual = apply_filters( $tag, $value, ...$extra_args );

		self::collect_failure(
			$failures,
			$actual === $expected,
			'Generated filter pipeline returned the expected transformed value.',
			array(
				'expected' => $expected,
				'actual'   => $actual,
			)
		);
		self::collect_failure(
			$failures,
			$actual_log === $expected_log,
			'Callbacks ran in numeric priority order with insertion order and accepted_args truncation.',
			array(
				'expectedLog' => $expected_log,
				'actualLog'   => $actual_log,
			)
		);

		$remove_spec        = $specs[ count( $specs ) - 1 ];
		$removed            = remove_filter( $tag, $callbacks[ $remove_spec['id'] ], $remove_spec['priority'] );
		$removed_again      = remove_filter( $tag, $callbacks[ $remove_spec['id'] ], $remove_spec['priority'] );
		$has_removed        = has_filter( $tag, $callbacks[ $remove_spec['id'] ] );
		$has_wrong_priority = has_filter(
			$tag,
			$callbacks[ $remove_spec['id'] ],
			$remove_spec['priority'] + 1000
		);

		self::collect_failure(
			$failures,
			true === $removed && false === $removed_again && false === $has_removed && false === $has_wrong_priority,
			'remove_filter removes exactly the requested callback/priority pair.',
			array(
				'removed'          => $removed,
				'removedAgain'     => $removed_again,
				'hasRemoved'       => $has_removed,
				'hasWrongPriority' => $has_wrong_priority,
			)
		);

		return self::result(
			$ctx,
			'hooks.generated-filter-priority-accepted-args',
			$failures,
			array(
				'tag'       => $tag,
				'specs'     => self::describe_specs( $specs ),
				'final'     => $actual,
				'logLength' => count( $actual_log ),
			)
		);
	}

	private static function check_wp_hook_direct_api( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag      = self::tag( $ctx, 'wp-hook-direct' );
		$hook     = new \WP_Hook();
		$trace    = array();
		$failures = array();

		$zero = static function () use ( &$trace, $hook ) {
			$trace[] = array(
				'id'              => 'zero',
				'currentPriority' => $hook->current_priority(),
				'count'           => 0,
			);

			return 'zero';
		};
		$first = static function ( $value, ...$args ) use ( &$trace, $hook ) {
			$trace[] = array(
				'id'              => 'first',
				'currentPriority' => $hook->current_priority(),
				'count'           => 1 + count( $args ),
			);

			return $value . '|first';
		};
		$second = static function ( $value, ...$args ) use ( &$trace, $hook ) {
			$trace[] = array(
				'id'              => 'second',
				'currentPriority' => $hook->current_priority(),
				'count'           => 1 + count( $args ),
			);

			return $value . '|second';
		};
		$late = static function ( $value ) use ( &$trace, $hook ) {
			$trace[] = array(
				'id'              => 'late',
				'currentPriority' => $hook->current_priority(),
				'count'           => 1,
			);

			return $value . '|late';
		};

		$hook->add_filter( $tag, $late, 20, 1 );
		$hook->add_filter( $tag, $first, 10, 3 );
		$hook->add_filter( $tag, $zero, 0, 0 );
		$hook->add_filter( $tag, $second, 10, 2 );

		$has_any             = $hook->has_filter( $tag );
		$has_zero_priority   = $hook->has_filter( $tag, $zero );
		$has_zero_at_zero    = $hook->has_filter( $tag, $zero, 0 );
		$has_zero_at_default = $hook->has_filter( $tag, $zero, 10 );
		$result              = $hook->apply_filters( 'base', array( 'base', 'extra', 'third' ) );

		self::collect_failure(
			$failures,
			'zero|first|second|late' === $result,
			'Direct WP_Hook::apply_filters uses priority order, same-priority insertion order, and accepted_args.',
			array(
				'result' => $result,
			)
		);
		self::collect_failure(
			$failures,
			array(
				array(
					'id'              => 'zero',
					'currentPriority' => 0,
					'count'           => 0,
				),
				array(
					'id'              => 'first',
					'currentPriority' => 10,
					'count'           => 3,
				),
				array(
					'id'              => 'second',
					'currentPriority' => 10,
					'count'           => 2,
				),
				array(
					'id'              => 'late',
					'currentPriority' => 20,
					'count'           => 1,
				),
			) === $trace,
			'WP_Hook::current_priority tracks the active priority, including falsey priority 0.',
			array(
				'trace' => $trace,
			)
		);
		self::collect_failure(
			$failures,
			true === $has_any
				&& 0 === $has_zero_priority
				&& true === $has_zero_at_zero
				&& false === $has_zero_at_default,
			'Direct WP_Hook::has_filter distinguishes priority 0 from false and supports exact priority checks.',
			array(
				'hasAny'             => $has_any,
				'hasZeroPriority'    => $has_zero_priority,
				'hasZeroAtZero'      => $has_zero_at_zero,
				'hasZeroAtDefault'   => $has_zero_at_default,
			)
		);

		$removed       = $hook->remove_filter( $tag, $first, 10 );
		$removed_again = $hook->remove_filter( $tag, $first, 10 );
		$trace         = array();
		$after_remove  = $hook->apply_filters( 'base', array( 'base', 'extra', 'third' ) );

		self::collect_failure(
			$failures,
			true === $removed
				&& false === $removed_again
				&& 'zero|second|late' === $after_remove
				&& false === $hook->has_filter( $tag, $first ),
			'Direct WP_Hook::remove_filter removes one callback without disturbing neighbors.',
			array(
				'removed'      => $removed,
				'removedAgain' => $removed_again,
				'afterRemove'  => $after_remove,
				'trace'        => $trace,
			)
		);

		$hook->remove_all_filters( 10 );
		$after_priority_clear = $hook->apply_filters( 'base', array( 'base', 'extra', 'third' ) );
		$hook->remove_all_filters();

		self::collect_failure(
			$failures,
			'zero|late' === $after_priority_clear && false === $hook->has_filter( $tag ),
			'Direct WP_Hook::remove_all_filters clears a selected priority and then the whole hook.',
			array(
				'afterPriorityClear' => $after_priority_clear,
				'hasAfterClear'      => $hook->has_filter( $tag ),
			)
		);

		return self::result(
			$ctx,
			'hooks.wp-hook-direct-api',
			$failures,
			array(
				'tag' => $tag,
			)
		);
	}

	private static function check_has_filter_priority_semantics( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag      = self::tag( $ctx, 'has-filter' );
		$action   = self::tag( $ctx, 'has-action' );
		$failures = array();
		$zero      = static function ( $value ) {
			return $value . '|zero';
		};
		$ten       = static function ( $value ) {
			return $value . '|ten';
		};
		$action_cb = static function () {
		};

		$missing_before = has_filter( $tag );

		add_filter( $tag, $ten, 10, 1 );
		add_filter( $tag, $zero, 0, 1 );
		add_action( $action, $action_cb, 0, 0 );

		$has_any            = has_filter( $tag );
		$has_zero           = has_filter( $tag, $zero );
		$has_zero_exact     = has_filter( $tag, $zero, 0 );
		$has_zero_wrong     = has_filter( $tag, $zero, 10 );
		$has_ten            = has_filter( $tag, $ten );
		$has_ten_exact      = has_filter( $tag, $ten, 10 );
		$has_ten_wrong      = has_filter( $tag, $ten, 0 );
		$has_missing_cb     = has_filter(
			$tag,
			static function ( $value ) {
				return $value;
			}
		);
		$has_action_zero    = has_action( $action, $action_cb );
		$has_action_exact   = has_action( $action, $action_cb, 0 );
		$removed_action     = remove_action( $action, $action_cb, 0 );
		$has_action_removed = has_action( $action );

		self::collect_failure(
			$failures,
			false === $missing_before
				&& true === $has_any
				&& 0 === $has_zero
				&& true === $has_zero_exact
				&& false === $has_zero_wrong
				&& 10 === $has_ten
				&& true === $has_ten_exact
				&& false === $has_ten_wrong
				&& false === $has_missing_cb,
			'has_filter return values preserve callback priority position semantics.',
			array(
				'missingBefore' => $missing_before,
				'hasAny'        => $has_any,
				'hasZero'       => $has_zero,
				'hasZeroExact'  => $has_zero_exact,
				'hasZeroWrong'  => $has_zero_wrong,
				'hasTen'        => $has_ten,
				'hasTenExact'   => $has_ten_exact,
				'hasTenWrong'   => $has_ten_wrong,
				'hasMissingCb'  => $has_missing_cb,
			)
		);
		self::collect_failure(
			$failures,
			0 === $has_action_zero
				&& true === $has_action_exact
				&& true === $removed_action
				&& false === $has_action_removed,
			'has_action and remove_action mirror filter priority semantics.',
			array(
				'hasActionZero'    => $has_action_zero,
				'hasActionExact'   => $has_action_exact,
				'removedAction'    => $removed_action,
				'hasActionRemoved' => $has_action_removed,
			)
		);

		return self::result(
			$ctx,
			'hooks.has-filter-priority-semantics',
			$failures,
			array(
				'tag'    => $tag,
				'action' => $action,
			)
		);
	}

	private static function check_nested_dispatch_stack( \ComponentFuzz\FuzzContext $ctx ): array {
		$outer    = self::tag( $ctx, 'outer' );
		$inner    = self::tag( $ctx, 'inner' );
		$seen     = array();
		$all_seen = array();
		$failures = array();

		$all = static function ( $hook_name, ...$args ) use ( &$all_seen, $outer, $inner ) {
			if ( ! in_array( $hook_name, array( $outer, $inner ), true ) ) {
				return;
			}

			$all_seen[] = array(
				'hook'    => $hook_name,
				'current' => current_filter(),
				'stack'   => self::current_filter_stack(),
				'count'   => 1 + count( $args ),
			);
		};

		add_action( 'all', $all, 10, 99 );
		add_filter(
			$inner,
			static function ( $value ) use ( &$seen, $outer, $inner ) {
				$seen[] = array(
					'phase'         => 'inner',
					'current'       => current_filter(),
					'currentAction' => current_action(),
					'stack'         => self::current_filter_stack(),
					'doingOuter'    => doing_filter( $outer ),
					'doingInner'    => doing_filter( $inner ),
					'doingAny'      => doing_filter(),
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
					'stack'   => self::current_filter_stack(),
				);

				$value = apply_filters( $inner, $value . '|outer', 'inner-extra' );

				$seen[] = array(
					'phase'   => 'outer-after',
					'current' => current_filter(),
					'stack'   => self::current_filter_stack(),
				);

				return $value . '|done';
			},
			10,
			1
		);

		$before_current = current_filter();
		$result         = apply_filters( $outer, 'base', 'outer-extra' );
		$after_current  = current_filter();
		$after_doing    = doing_filter();
		$removed_all    = remove_filter( 'all', $all, 10 );

		self::collect_failure(
			$failures,
			'base|outer|inner|done' === $result,
			'Nested filter dispatch returns through the outer callback after inner dispatch.',
			array(
				'result' => $result,
			)
		);
		self::collect_failure(
			$failures,
			array( 'outer-before', 'inner', 'outer-after' ) === array_column( $seen, 'phase' )
				&& $outer === $seen[0]['current']
				&& array( $outer ) === $seen[0]['stack']
				&& $inner === $seen[1]['current']
				&& array( $outer, $inner ) === $seen[1]['stack']
				&& true === $seen[1]['doingOuter']
				&& true === $seen[1]['doingInner']
				&& true === $seen[1]['doingAny']
				&& $outer === $seen[2]['current']
				&& array( $outer ) === $seen[2]['stack'],
			'current_filter, current_action, doing_filter, and the global stack stay coherent during nesting.',
			array(
				'seen' => $seen,
			)
		);
		self::collect_failure(
			$failures,
			array(
				array(
					'hook'    => $outer,
					'current' => $outer,
					'stack'   => array( $outer ),
					'count'   => 3,
				),
				array(
					'hook'    => $inner,
					'current' => $inner,
					'stack'   => array( $outer, $inner ),
					'count'   => 3,
				),
			) === $all_seen,
			'The all hook observes each nested hook with the same current stack WordPress exposes to regular callbacks.',
			array(
				'allSeen' => $all_seen,
			)
		);
		self::collect_failure(
			$failures,
			false === $before_current
				&& false === $after_current
				&& false === $after_doing
				&& true === $removed_all,
			'Hook stack state is empty before and after nested dispatch.',
			array(
				'beforeCurrent' => $before_current,
				'afterCurrent'  => $after_current,
				'afterDoing'    => $after_doing,
				'removedAll'    => $removed_all,
			)
		);

		return self::result(
			$ctx,
			'hooks.nested-dispatch-current-stack',
			$failures,
			array(
				'outer' => $outer,
				'inner' => $inner,
			)
		);
	}

	private static function check_dispatch_mutation( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag             = self::tag( $ctx, 'mutation' );
		$events          = array();
		$removed_late    = array();
		$removed_all     = array();
		$failures        = array();
		$added_past      = static function ( $value ) use ( &$events ) {
			$events[] = 'added-past';
			return $value . '|past';
		};
		$added_future    = static function ( $value ) use ( &$events ) {
			$events[] = 'added-future';
			return $value . '|future';
		};
		$late_removed    = static function ( $value ) use ( &$events ) {
			$events[] = 'late-removed';
			return $value . '|late-removed';
		};
		$late_remove_all = static function ( $value ) use ( &$events ) {
			$events[] = 'late-remove-all';
			return $value . '|late-remove-all';
		};
		$middle          = static function ( $value ) use ( &$events ) {
			$events[] = 'middle';
			return $value . '|middle';
		};
		$mutator         = static function ( $value ) use (
			$tag,
			&$events,
			&$removed_late,
			&$removed_all,
			$added_past,
			$added_future,
			$late_removed
		) {
			$events[]       = 'mutator';
			$removed_late[] = remove_filter( $tag, $late_removed, 30 );
			add_filter( $tag, $added_future, 20, 1 );
			add_filter( $tag, $added_past, 1, 1 );
			$removed_all[] = remove_all_filters( $tag, 40 );

			return $value . '|mutator';
		};

		add_filter( $tag, $mutator, 10, 1 );
		add_filter( $tag, $middle, 15, 1 );
		add_filter( $tag, $late_removed, 30, 1 );
		add_filter( $tag, $late_remove_all, 40, 1 );

		$first        = apply_filters( $tag, 'base' );
		$first_events = $events;
		$events       = array();
		$second       = apply_filters( $tag, 'base' );
		$second_events = $events;

		self::collect_failure(
			$failures,
			'base|mutator|middle|future' === $first
				&& array( 'mutator', 'middle', 'added-future' ) === $first_events,
			'Callbacks added at a future priority during dispatch run in the same pass, while removed future callbacks do not.',
			array(
				'first'       => $first,
				'firstEvents' => $first_events,
			)
		);
		self::collect_failure(
			$failures,
			'base|past|mutator|middle|future' === $second
				&& array( 'added-past', 'mutator', 'middle', 'added-future' ) === $second_events,
			'Callbacks added at an already-passed priority wait until the next dispatch.',
			array(
				'second'       => $second,
				'secondEvents' => $second_events,
			)
		);
		self::collect_failure(
			$failures,
			array( true, false ) === $removed_late && array( true, true ) === $removed_all,
			'Dispatch-time remove_filter and remove_all_filters report stable results across repeated dispatches.',
			array(
				'removedLate' => $removed_late,
				'removedAll'  => $removed_all,
			)
		);
		self::collect_failure(
			$failures,
			false === has_filter( $tag, $late_removed )
				&& false === has_filter( $tag, $late_remove_all )
				&& 1 === has_filter( $tag, $added_past )
				&& 20 === has_filter( $tag, $added_future ),
			'Registry state after dispatch-time mutation matches the surviving callbacks.',
			array(
				'hasLateRemoved'   => has_filter( $tag, $late_removed ),
				'hasLateRemoveAll' => has_filter( $tag, $late_remove_all ),
				'hasAddedPast'     => has_filter( $tag, $added_past ),
				'hasAddedFuture'   => has_filter( $tag, $added_future ),
			)
		);

		return self::result(
			$ctx,
			'hooks.add-remove-during-dispatch',
			$failures,
			array(
				'tag' => $tag,
			)
		);
	}

	private static function check_remove_all_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag      = self::tag( $ctx, 'remove-all' );
		$failures = array();
		$first    = static function ( $value ) {
			return $value . '|first';
		};
		$second   = static function ( $value ) {
			return $value . '|second';
		};
		$third    = static function ( $value ) {
			return $value . '|third';
		};
		$action   = static function () {
		};

		add_filter( $tag, $first, 3, 1 );
		add_filter( $tag, $second, 8, 1 );
		add_filter( $tag, $third, 13, 1 );

		$remove_missing         = remove_all_filters( $tag, 99 );
		$after_missing_remove   = apply_filters( $tag, 'base' );
		$remove_priority        = remove_all_filters( $tag, 8 );
		$after_priority_remove  = apply_filters( $tag, 'base' );
		$has_second_after_clear = has_filter( $tag, $second );
		$remove_all             = remove_all_filters( $tag );
		$after_all_remove       = apply_filters( $tag, 'base' );
		$has_after_all          = has_filter( $tag );
		$registry_has_tag       = isset( $GLOBALS['wp_filter'][ $tag ] );

		add_action( $tag, $action, 4, 0 );
		$remove_all_actions = remove_all_actions( $tag );
		$has_action_after   = has_action( $tag );

		self::collect_failure(
			$failures,
			true === $remove_missing
				&& 'base|first|second|third' === $after_missing_remove
				&& true === $remove_priority
				&& 'base|first|third' === $after_priority_remove
				&& false === $has_second_after_clear,
			'remove_all_filters with a priority only removes that priority and ignores missing priorities.',
			array(
				'removeMissing'        => $remove_missing,
				'afterMissingRemove'   => $after_missing_remove,
				'removePriority'       => $remove_priority,
				'afterPriorityRemove'  => $after_priority_remove,
				'hasSecondAfterClear'  => $has_second_after_clear,
			)
		);
		self::collect_failure(
			$failures,
			true === $remove_all
				&& 'base' === $after_all_remove
				&& false === $has_after_all
				&& false === $registry_has_tag,
			'remove_all_filters without a priority clears the hook and removes the public registry entry.',
			array(
				'removeAll'      => $remove_all,
				'afterAllRemove' => $after_all_remove,
				'hasAfterAll'    => $has_after_all,
				'registryHasTag' => $registry_has_tag,
			)
		);
		self::collect_failure(
			$failures,
			true === $remove_all_actions && false === $has_action_after,
			'remove_all_actions aliases remove_all_filters for action hooks.',
			array(
				'removeAllActions' => $remove_all_actions,
				'hasActionAfter'   => $has_action_after,
			)
		);

		return self::result(
			$ctx,
			'hooks.remove-all-filters-actions',
			$failures,
			array(
				'tag' => $tag,
			)
		);
	}

	private static function check_action_and_filter_counters( \ComponentFuzz\FuzzContext $ctx ): array {
		$filter   = self::tag( $ctx, 'counter-filter' );
		$action   = self::tag( $ctx, 'counter-action' );
		$seen     = array();
		$failures = array();

		add_filter(
			$filter,
			static function ( $value, $extra ) use ( &$seen, $filter ) {
				$seen[] = array(
					'type'          => 'filter',
					'value'         => $value,
					'extra'         => $extra,
					'currentFilter' => current_filter(),
					'doingFilter'   => doing_filter( $filter ),
					'doingAny'      => doing_filter(),
					'didFilter'     => did_filter( $filter ),
				);

				return $value . '|filtered-' . $extra;
			},
			10,
			2
		);
		add_action(
			$action,
			static function ( $first, $second ) use ( &$seen, $action, $filter ) {
				$seen[] = array(
					'type'          => 'action',
					'args'          => array( $first, $second ),
					'currentAction' => current_action(),
					'currentFilter' => current_filter(),
					'doingAction'   => doing_action( $action ),
					'doingFilter'   => doing_filter( $filter ),
					'doingAny'      => doing_action(),
					'didAction'     => did_action( $action ),
					'didFilter'     => did_filter( $filter ),
				);
			},
			10,
			2
		);

		$filter_before = did_filter( $filter );
		$action_before = did_action( $action );
		$first_filter  = apply_filters( $filter, 'base', 'one' );
		$second_filter = apply_filters_ref_array( $filter, array( 'base', 'two' ) );
		do_action( $action, 'alpha', 'beta' );
		do_action_ref_array( $action, array( 'gamma', 'delta' ) );
		$filter_after = did_filter( $filter );
		$action_after = did_action( $action );

		self::collect_failure(
			$failures,
			0 === $filter_before
				&& 2 === $filter_after
				&& 'base|filtered-one' === $first_filter
				&& 'base|filtered-two' === $second_filter,
			'did_filter counts apply_filters and apply_filters_ref_array dispatches.',
			array(
				'filterBefore' => $filter_before,
				'filterAfter'  => $filter_after,
				'firstFilter'  => $first_filter,
				'secondFilter' => $second_filter,
			)
		);
		self::collect_failure(
			$failures,
			0 === $action_before && 2 === $action_after,
			'did_action counts do_action and do_action_ref_array dispatches.',
			array(
				'actionBefore' => $action_before,
				'actionAfter'  => $action_after,
			)
		);
		self::collect_failure(
			$failures,
			array(
				array(
					'type'          => 'filter',
					'value'         => 'base',
					'extra'         => 'one',
					'currentFilter' => $filter,
					'doingFilter'   => true,
					'doingAny'      => true,
					'didFilter'     => 1,
				),
				array(
					'type'          => 'filter',
					'value'         => 'base',
					'extra'         => 'two',
					'currentFilter' => $filter,
					'doingFilter'   => true,
					'doingAny'      => true,
					'didFilter'     => 2,
				),
				array(
					'type'          => 'action',
					'args'          => array( 'alpha', 'beta' ),
					'currentAction' => $action,
					'currentFilter' => $action,
					'doingAction'   => true,
					'doingFilter'   => false,
					'doingAny'      => true,
					'didAction'     => 1,
					'didFilter'     => 2,
				),
				array(
					'type'          => 'action',
					'args'          => array( 'gamma', 'delta' ),
					'currentAction' => $action,
					'currentFilter' => $action,
					'doingAction'   => true,
					'doingFilter'   => false,
					'doingAny'      => true,
					'didAction'     => 2,
					'didFilter'     => 2,
				),
			) === $seen,
			'Current hook helpers and counters expose in-dispatch state for filters and actions.',
			array(
				'seen' => $seen,
			)
		);

		return self::result(
			$ctx,
			'hooks.action-filter-counters',
			$failures,
			array(
				'filter' => $filter,
				'action' => $action,
			)
		);
	}

	private static function check_reference_and_object_payloads( \ComponentFuzz\FuzzContext $ctx ): array {
		$filter      = self::tag( $ctx, 'object-filter' );
		$meta_filter = self::tag( $ctx, 'object-meta-filter' );
		$action      = self::tag( $ctx, 'reference-action' );
		$failures    = array();
		$payload     = (object) array(
			'count' => 1,
			'trail' => array( 'start' ),
		);
		$meta        = (object) array(
			'seen' => array(),
		);
		$box         = array(
			'count' => 0,
			'trail' => array(),
		);

		add_filter(
			$filter,
			static function ( $value, $extra ) {
				$value->count += $extra;
				$value->trail[] = 'filter';

				return $value;
			},
			10,
			2
		);
		add_filter(
			$meta_filter,
			static function ( $value, $meta ) {
				$meta->seen[] = $value->count;
				$value->trail[] = 'meta';

				return $value;
			},
			12,
			2
		);
		add_action(
			$action,
			static function ( &$box_arg, $object_arg ) {
				$box_arg['count']++;
				$box_arg['trail'][] = 'action-ref';
				$object_arg->count++;
				$object_arg->trail[] = 'action-object';
			},
			10,
			2
		);

		$filter_args = array( $payload, 4 );
		$filtered    = apply_filters_ref_array( $filter, $filter_args );
		$meta_args   = array( $payload, $meta );
		$filtered2   = apply_filters_ref_array( $meta_filter, $meta_args );
		$action_args = array( &$box, $payload );
		do_action_ref_array( $action, $action_args );

		self::collect_failure(
			$failures,
			$filtered === $payload
				&& $filtered2 === $payload
				&& 6 === $payload->count
				&& array( 'start', 'filter', 'meta', 'action-object' ) === $payload->trail
				&& array( 5 ) === $meta->seen,
			'Object payloads keep identity and intentional mutations across ref-array filters.',
			array(
				'payload' => self::describe_object_payload( $payload ),
				'meta'    => self::describe_object_payload( $meta ),
			)
		);
		self::collect_failure(
			$failures,
			array(
				'count' => 1,
				'trail' => array( 'action-ref' ),
			) === $box,
			'do_action_ref_array preserves safe by-reference argument mutation.',
			array(
				'box' => $box,
			)
		);

		return self::result(
			$ctx,
			'hooks.reference-object-payloads',
			$failures,
			array(
				'filter'     => $filter,
				'metaFilter' => $meta_filter,
				'action'     => $action,
			)
		);
	}

	private static function check_hook_globals_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$after    = self::snapshot_hook_globals();
		$failures = array();

		self::collect_failure(
			$failures,
			self::snapshots_identical( $snapshot, $after ),
			'Hook globals are restored after the surface mutates registries, counters, and current stacks.',
			array(
				'before' => self::describe_snapshot( $snapshot ),
				'after'  => self::describe_snapshot( $after ),
			)
		);

		return self::result(
			$ctx,
			'hooks.state-restoration',
			$failures,
			array(
				'globals' => array_keys( $snapshot ),
			)
		);
	}

	private static function generated_filter_specs( \ComponentFuzz\FuzzContext $ctx ): array {
		$priorities = array( -10, -1, 0, 1, 5, 10, 10, 20 );
		$specs      = array();

		for ( $i = 0; $i < self::GENERATED_CALLBACKS; $i++ ) {
			$specs[] = array(
				'id'       => 'cb' . $i,
				'priority' => $ctx->choice( $priorities ),
				'accepted' => $ctx->int( 0, 5 ),
				'index'    => $i,
			);
		}

		$specs[] = array(
			'id'       => 'forced-zero-priority',
			'priority' => 0,
			'accepted' => 1,
			'index'    => count( $specs ),
		);
		$specs[] = array(
			'id'       => 'forced-truncate',
			'priority' => 10,
			'accepted' => 2,
			'index'    => count( $specs ),
		);
		$specs[] = array(
			'id'       => 'forced-zero-args',
			'priority' => 10,
			'accepted' => 0,
			'index'    => count( $specs ),
		);

		return $specs;
	}

	private static function expected_filter_order( array $specs ): array {
		usort(
			$specs,
			static function ( array $a, array $b ): int {
				if ( $a['priority'] === $b['priority'] ) {
					return $a['index'] <=> $b['index'];
				}

				return $a['priority'] <=> $b['priority'];
			}
		);

		return $specs;
	}

	private static function expected_received_args( array $args, int $accepted ): array {
		if ( 0 === $accepted ) {
			return array();
		}

		if ( $accepted >= count( $args ) ) {
			return $args;
		}

		return array_slice( $args, 0, $accepted );
	}

	private static function describe_specs( array $specs ): array {
		return array_map(
			static function ( array $spec ): array {
				return array(
					'id'       => $spec['id'],
					'priority' => $spec['priority'],
					'accepted' => $spec['accepted'],
					'index'    => $spec['index'],
				);
			},
			$specs
		);
	}

	private static function describe_args( array $args ): array {
		return array_map(
			static function ( $arg ) {
				if ( is_array( $arg ) ) {
					return array(
						'type' => 'array',
						'keys' => array_keys( $arg ),
					);
				}

				if ( is_object( $arg ) ) {
					return array(
						'type'  => 'object',
						'class' => get_class( $arg ),
					);
				}

				return $arg;
			},
			$args
		);
	}

	private static function current_filter_stack(): array {
		return array_values( $GLOBALS['wp_current_filter'] ?? array() );
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private static function result(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		array $failures,
		array $data = array()
	): array {
		if ( array() !== $failures ) {
			$data['failures'] = $failures;
		}

		return $ctx->result( $invariant, array() === $failures, $data );
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

	private static function snapshots_identical( array $before, array $after ): bool {
		foreach ( $before as $name => $value ) {
			if ( ! array_key_exists( $name, $after ) || $after[ $name ] !== $value ) {
				return false;
			}
		}

		return array_keys( $before ) === array_keys( $after );
	}

	private static function describe_snapshot( array $snapshot ): array {
		$description = array();

		foreach ( $snapshot as $name => $value ) {
			if ( null === $value ) {
				$description[ $name ] = array(
					'type' => 'unset',
				);
				continue;
			}

			$description[ $name ] = array(
				'type'  => gettype( $value ),
				'count' => is_array( $value ) ? count( $value ) : null,
				'keys'  => is_array( $value ) ? array_slice( array_keys( $value ), 0, 8 ) : null,
			);
		}

		return $description;
	}

	private static function describe_object_payload( object $object ): array {
		return array(
			'class'      => get_class( $object ),
			'properties' => get_object_vars( $object ),
		);
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
