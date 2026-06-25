<?php
namespace ComponentFuzz\Surfaces;

final class CronSurface {
	public const NAME = 'cron';

	private const GENERATED_CASES = 12;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'cron.bootstrap-apis-available',
					'Required WordPress cron APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$rows     = array();
		$snapshot = self::snapshot_globals();
		$store    = array( 'version' => 2 );

		try {
			self::install_memory_cron_store( $store );

			$rows = array_merge( $rows, self::check_schedules( $ctx ) );
			$rows = array_merge( $rows, self::check_rejection_contracts( $ctx ) );
			$rows = array_merge( $rows, self::check_schedule_filters( $ctx, $store ) );
			$rows = array_merge( $rows, self::check_next_scheduled_filter( $ctx, $store ) );
			$rows = array_merge( $rows, self::check_duplicate_single_event_windows( $ctx, $store ) );
			$rows = array_merge( $rows, self::check_clear_and_reschedule_filters( $ctx, $store ) );
			$rows = array_merge( $rows, self::check_scheduled_event_lookup_order( $ctx, $store ) );

			foreach ( self::cases( $ctx ) as $case_index => $case ) {
				$store = array( 'version' => 2 );
				$rows  = array_merge( $rows, self::check_case( $ctx, $case_index, $case, $store ) );
			}

			$rows = array_merge( $rows, self::check_ready_jobs_partition( $ctx, $store ) );
			$rows = array_merge( $rows, self::check_spawn_request_boundaries( $ctx, $store ) );
			$rows = array_merge( $rows, self::check_clear_and_unschedule( $ctx, $store ) );
			$rows = array_merge( $rows, self::check_reschedule_contract( $ctx, $store ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'cron.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'add_filter',
				'apply_filters',
				'has_filter',
				'remove_all_filters',
				'remove_filter',
				'wp_get_schedules',
				'wp_schedule_event',
				'wp_schedule_single_event',
				'wp_get_scheduled_event',
				'wp_next_scheduled',
				'wp_get_schedule',
				'wp_unschedule_event',
				'wp_clear_scheduled_hook',
				'wp_unschedule_hook',
				'wp_reschedule_event',
				'wp_get_ready_cron_jobs',
				'_get_cron_array',
				'_wp_cron',
				'spawn_cron',
				'get_transient',
				'set_transient',
				'wp_remote_post',
				'is_wp_error',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Error' ) ) {
			$missing[] = 'class WP_Error';
		}

		if ( ! defined( 'WP_CRON_LOCK_TIMEOUT' ) ) {
			$missing[] = 'constant WP_CRON_LOCK_TIMEOUT';
		}

		return $missing;
	}

	private static function check_schedules( \ComponentFuzz\FuzzContext $ctx ): array {
		$schedules = self::call( static fn() => \wp_get_schedules() );
		$value     = $schedules['value'] ?? array();
		$core_ok   = ! $schedules['threw']
			&& isset( $value['hourly']['interval'], $value['twicedaily']['interval'], $value['daily']['interval'], $value['weekly']['interval'] )
			&& \HOUR_IN_SECONDS === $value['hourly']['interval']
			&& \DAY_IN_SECONDS === $value['daily']['interval'];
		$custom_ok = ! $schedules['threw']
			&& isset( $value['component_fuzz_short']['interval'] )
			&& 37 === $value['component_fuzz_short']['interval'];

		return array(
			$ctx->result(
				'cron.schedules.core-intervals-present',
				$core_ok,
				array( 'schedules' => self::describe_value( $value ) )
			),
			$ctx->result(
				'cron.schedules.filter-can-add-custom-recurrence',
				$custom_ok,
				array( 'custom' => self::describe_value( $value['component_fuzz_short'] ?? null ) )
			),
		);
	}

	private static function check_rejection_contracts( \ComponentFuzz\FuzzContext $ctx ): array {
		$invalid_single       = self::call( static fn() => \wp_schedule_single_event( 0, 'component_fuzz_invalid', array(), true ) );
		$invalid_recurring    = self::call( static fn() => \wp_schedule_event( time() + \HOUR_IN_SECONDS, 'not-a-schedule', 'component_fuzz_invalid', array(), true ) );
		$invalid_single_code  = self::error_code( $invalid_single['value'] ?? null );
		$invalid_recur_code   = self::error_code( $invalid_recurring['value'] ?? null );
		$invalid_single_plain = self::call( static fn() => \wp_schedule_single_event( -50, 'component_fuzz_invalid', array(), false ) );

		return array(
			$ctx->result(
				'cron.rejects-invalid-timestamp-with-wp-error',
				! $invalid_single['threw'] && 'invalid_timestamp' === $invalid_single_code,
				array( 'result' => self::describe_call( $invalid_single ) )
			),
			$ctx->result(
				'cron.rejects-invalid-schedule-with-wp-error',
				! $invalid_recurring['threw'] && 'invalid_schedule' === $invalid_recur_code,
				array( 'result' => self::describe_call( $invalid_recurring ) )
			),
			$ctx->result(
				'cron.rejects-invalid-timestamp-as-false-without-wp-error',
				! $invalid_single_plain['threw'] && false === $invalid_single_plain['value'],
				array( 'result' => self::describe_call( $invalid_single_plain ) )
			),
		);
	}

	private static function check_schedule_filters( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$rows  = array();
		$store = array( 'version' => 2 );
		$now   = time();

		$pre_events      = array();
		$pre_short_hook  = 'component_fuzz_pre_schedule';
		$pre_short_args  = array( 'kind' => 'pre-short' );
		$pre_short_value = 'component-fuzz-short-circuit';
		$pre_filter      = static function ( $pre, object $event, bool $wp_error ) use ( &$pre_events, $pre_short_hook, $pre_short_value ) {
			$pre_events[] = array(
				'hook'    => $event->hook,
				'args'    => $event->args,
				'error'   => $wp_error,
				'preType' => gettype( $pre ),
			);

			return $pre_short_hook === $event->hook ? $pre_short_value : $pre;
		};

		\add_filter( 'pre_schedule_event', $pre_filter, 10, 3 );
		try {
			$pre_result = self::call( static fn() => \wp_schedule_event( $now + 300, 'hourly', $pre_short_hook, $pre_short_args, true ) );
			$pre_after  = self::call( static fn() => \wp_get_scheduled_event( $pre_short_hook, $pre_short_args, $now + 300 ) );
		} finally {
			\remove_filter( 'pre_schedule_event', $pre_filter, 10 );
		}

		$rows[] = $ctx->result(
			'cron.filters.pre-schedule-short-circuits-without-storage',
			! $pre_result['threw']
				&& $pre_short_value === $pre_result['value']
				&& ! $pre_after['threw']
				&& false === $pre_after['value']
				&& 1 === count( $pre_events )
				&& $pre_short_hook === ( $pre_events[0]['hook'] ?? null )
				&& $pre_short_args === ( $pre_events[0]['args'] ?? null )
				&& true === ( $pre_events[0]['error'] ?? null )
				&& false === \has_filter( 'pre_schedule_event', $pre_filter ),
			array(
				'preResult' => self::describe_call( $pre_result ),
				'after'     => self::describe_call( $pre_after ),
				'events'    => self::describe_value( $pre_events ),
				'store'     => self::describe_cron_store( $store ),
			)
		);

		$store          = array( 'version' => 2 );
		$mutate_events  = array();
		$mutate_hook    = 'component_fuzz_schedule_mutate';
		$mutated_hook   = 'component_fuzz_schedule_mutated';
		$mutate_args    = array( 'kind' => 'before' );
		$mutated_args   = array( 'kind' => 'after', 'token' => self::safe_hook_fragment( $ctx->text( 0, 12 ) ) );
		$mutated_time   = $now + 900;
		$schedule_filter = static function ( object $event ) use ( &$mutate_events, $mutate_hook, $mutated_hook, $mutated_args, $mutated_time ): object {
			$mutate_events[] = array(
				'hook'      => $event->hook,
				'timestamp' => $event->timestamp,
				'args'      => $event->args,
				'schedule'  => $event->schedule,
			);

			if ( $mutate_hook === $event->hook ) {
				$event->hook      = $mutated_hook;
				$event->timestamp = $mutated_time;
				$event->args      = $mutated_args;
			}

			return $event;
		};

		\add_filter( 'schedule_event', $schedule_filter );
		try {
			$mutate_result = self::call( static fn() => \wp_schedule_event( $now + 600, 'hourly', $mutate_hook, $mutate_args, true ) );
			$old_event     = self::call( static fn() => \wp_get_scheduled_event( $mutate_hook, $mutate_args, $now + 600 ) );
			$new_event     = self::call( static fn() => \wp_get_scheduled_event( $mutated_hook, $mutated_args, $mutated_time ) );
		} finally {
			\remove_filter( 'schedule_event', $schedule_filter );
		}

		$event = $new_event['value'] ?? null;
		$rows[] = $ctx->result(
			'cron.filters.schedule-event-mutates-stored-event',
			! $mutate_result['threw']
				&& true === $mutate_result['value']
				&& ! $old_event['threw']
				&& false === $old_event['value']
				&& ! $new_event['threw']
				&& $event instanceof \stdClass
				&& $mutated_hook === $event->hook
				&& $mutated_time === $event->timestamp
				&& $mutated_args === $event->args
				&& 1 === count( $mutate_events )
				&& $mutate_hook === ( $mutate_events[0]['hook'] ?? null )
				&& false === \has_filter( 'schedule_event', $schedule_filter ),
			array(
				'result' => self::describe_call( $mutate_result ),
				'old'    => self::describe_call( $old_event ),
				'new'    => self::describe_call( $new_event ),
				'events' => self::describe_value( $mutate_events ),
				'store'  => self::describe_cron_store( $store ),
			)
		);

		$store           = array( 'version' => 2 );
		$unschedule_hook = 'component_fuzz_pre_unschedule';
		$unschedule_args = array( 'kind' => 'keep' );
		$unschedule_time = $now + 1200;
		$unschedule_seen = array();
		\wp_schedule_single_event( $unschedule_time, $unschedule_hook, $unschedule_args, true );

		$unschedule_filter = static function ( $pre, int $timestamp, string $hook, array $args, bool $wp_error ) use ( &$unschedule_seen, $unschedule_hook ) {
			$unschedule_seen[] = array(
				'pre'       => $pre,
				'timestamp' => $timestamp,
				'hook'      => $hook,
				'args'      => $args,
				'error'     => $wp_error,
			);

			return $unschedule_hook === $hook ? true : $pre;
		};

		\add_filter( 'pre_unschedule_event', $unschedule_filter, 10, 5 );
		try {
			$unschedule = self::call( static fn() => \wp_unschedule_event( $unschedule_time, $unschedule_hook, $unschedule_args, true ) );
			$kept       = self::call( static fn() => \wp_get_scheduled_event( $unschedule_hook, $unschedule_args, $unschedule_time ) );
		} finally {
			\remove_filter( 'pre_unschedule_event', $unschedule_filter, 10 );
		}

		$kept_event = $kept['value'] ?? null;
		$rows[] = $ctx->result(
			'cron.filters.pre-unschedule-short-circuits-and-preserves-event',
			! $unschedule['threw']
				&& true === $unschedule['value']
				&& ! $kept['threw']
				&& $kept_event instanceof \stdClass
				&& $unschedule_hook === $kept_event->hook
				&& $unschedule_args === $kept_event->args
				&& 1 === count( $unschedule_seen )
				&& $unschedule_time === ( $unschedule_seen[0]['timestamp'] ?? null )
				&& $unschedule_hook === ( $unschedule_seen[0]['hook'] ?? null )
				&& true === ( $unschedule_seen[0]['error'] ?? null )
				&& false === \has_filter( 'pre_unschedule_event', $unschedule_filter ),
			array(
				'unschedule' => self::describe_call( $unschedule ),
				'kept'       => self::describe_call( $kept ),
				'events'     => self::describe_value( $unschedule_seen ),
				'store'      => self::describe_cron_store( $store ),
			)
		);

		return $rows;
	}

	private static function check_next_scheduled_filter( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$store     = array( 'version' => 2 );
		$timestamp = time() + 2 * \HOUR_IN_SECONDS + $ctx->int( 0, 600 );
		$hook      = 'component_fuzz_next_' . self::safe_hook_fragment( $ctx->text( 0, 12 ) );
		$args      = array(
			'group' => 'next-filter',
			'token' => self::safe_hook_fragment( $ctx->text( 0, 12 ) ),
		);
		$override  = $timestamp + 123;
		$seen      = array();

		$schedule = self::call( static fn() => \wp_schedule_event( $timestamp, 'hourly', $hook, $args, true ) );
		$filter   = static function ( int $next_timestamp, object $event, string $filter_hook, array $filter_args ) use ( &$seen, $hook, $args, $override ): int {
			$seen[] = array(
				'timestamp' => $next_timestamp,
				'hook'      => $filter_hook,
				'args'      => $filter_args,
				'event'     => $event,
			);

			return $hook === $filter_hook && $args === $filter_args ? $override : $next_timestamp;
		};

		\add_filter( 'wp_next_scheduled', $filter, 10, 4 );
		try {
			$next    = self::call( static fn() => \wp_next_scheduled( $hook, $args ) );
			$missing = self::call( static fn() => \wp_next_scheduled( $hook, array( 'missing' => true ) ) );
		} finally {
			\remove_filter( 'wp_next_scheduled', $filter, 10 );
		}

		$event = $seen[0]['event'] ?? null;
		return array(
			$ctx->result(
				'cron.next-scheduled.filter-overrides-existing-event-only',
				! $schedule['threw']
					&& true === $schedule['value']
					&& ! $next['threw']
					&& $override === $next['value']
					&& ! $missing['threw']
					&& false === $missing['value']
					&& 1 === count( $seen )
					&& $event instanceof \stdClass
					&& $hook === $event->hook
					&& $timestamp === $event->timestamp
					&& 'hourly' === $event->schedule
					&& $args === $event->args
					&& \HOUR_IN_SECONDS === $event->interval
					&& false === \has_filter( 'wp_next_scheduled', $filter ),
				array(
					'schedule' => self::describe_call( $schedule ),
					'next'     => self::describe_call( $next ),
					'missing'  => self::describe_call( $missing ),
					'seen'     => self::describe_value( $seen ),
					'store'    => self::describe_cron_store( $store ),
				)
			),
		);
	}

	private static function check_duplicate_single_event_windows( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$rows  = array();
		$store = array( 'version' => 2 );
		$now   = time();
		$base  = $now + 2 * \HOUR_IN_SECONDS;
		$hook  = 'component_fuzz_duplicate_' . self::safe_hook_fragment( $ctx->text( 0, 12 ) );
		$args  = array( 'group' => 'duplicate', 'token' => self::safe_hook_fragment( $ctx->text( 0, 12 ) ) );

		$first          = self::call( static fn() => \wp_schedule_single_event( $base, $hook, $args, true ) );
		$duplicate      = self::call( static fn() => \wp_schedule_single_event( $base + 5 * \MINUTE_IN_SECONDS, $hook, $args, true ) );
		$outside_window = self::call( static fn() => \wp_schedule_single_event( $base + 11 * \MINUTE_IN_SECONDS, $hook, $args, true ) );
		$different_args = self::call( static fn() => \wp_schedule_single_event( $base + 6 * \MINUTE_IN_SECONDS, $hook, array_merge( $args, array( 'variant' => 1 ) ), true ) );
		$cron_array     = self::call( static fn() => \_get_cron_array() );
		$timestamps     = $cron_array['threw'] ? array() : self::event_timestamps( $cron_array['value'], $hook, $args );

		$rows[] = $ctx->result(
			'cron.single-event-duplicate-window-blocks-identical-args-only',
			! $first['threw']
				&& true === $first['value']
				&& ! $duplicate['threw']
				&& 'duplicate_event' === self::error_code( $duplicate['value'] ?? null )
				&& ! $outside_window['threw']
				&& true === $outside_window['value']
				&& ! $different_args['threw']
				&& true === $different_args['value']
				&& array( $base, $base + 11 * \MINUTE_IN_SECONDS ) === $timestamps,
			array(
				'first'          => self::describe_call( $first ),
				'duplicate'      => self::describe_call( $duplicate ),
				'outsideWindow'  => self::describe_call( $outside_window ),
				'differentArgs'  => self::describe_call( $different_args ),
				'timestamps'     => self::describe_value( $timestamps ),
				'store'          => self::describe_cron_store( $store ),
			)
		);

		$store       = array( 'version' => 2 );
		$near_future = $now + 5 * \MINUTE_IN_SECONDS;
		$near_hook   = 'component_fuzz_duplicate_near_' . self::safe_hook_fragment( $ctx->text( 0, 12 ) );
		$near_args   = array( 'group' => 'near-now' );
		$near_first  = self::call( static fn() => \wp_schedule_single_event( $near_future, $near_hook, $near_args, true ) );
		$past_dupe   = self::call( static fn() => \wp_schedule_single_event( $now - 60, $near_hook, $near_args, true ) );

		$rows[] = $ctx->result(
			'cron.single-event-duplicate-window-treats-near-now-past-as-duplicates',
			! $near_first['threw']
				&& true === $near_first['value']
				&& ! $past_dupe['threw']
				&& 'duplicate_event' === self::error_code( $past_dupe['value'] ?? null ),
			array(
				'nearFirst' => self::describe_call( $near_first ),
				'pastDupe'  => self::describe_call( $past_dupe ),
				'store'     => self::describe_cron_store( $store ),
			)
		);

		return $rows;
	}

	private static function check_clear_and_reschedule_filters( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$rows  = array();
		$store = array( 'version' => 2 );
		$now   = time();

		$clear_hook = 'component_fuzz_pre_clear_' . self::safe_hook_fragment( $ctx->text( 0, 12 ) );
		$clear_args = array( 'group' => 'pre-clear' );
		\wp_schedule_single_event( $now + \HOUR_IN_SECONDS, $clear_hook, $clear_args, true );
		\wp_schedule_single_event( $now + 2 * \HOUR_IN_SECONDS, $clear_hook, $clear_args, true );

		$clear_seen = array();
		$clear_pre  = static function ( $pre, string $hook, array $args, bool $wp_error ) use ( &$clear_seen, $clear_hook ) {
			$clear_seen[] = array(
				'pre'   => $pre,
				'hook'  => $hook,
				'args'  => $args,
				'error' => $wp_error,
			);

			return $clear_hook === $hook ? 7 : $pre;
		};

		\add_filter( 'pre_clear_scheduled_hook', $clear_pre, 10, 4 );
		try {
			$clear_result = self::call( static fn() => \wp_clear_scheduled_hook( $clear_hook, $clear_args, true ) );
			$still_there  = self::call( static fn() => \wp_next_scheduled( $clear_hook, $clear_args ) );
		} finally {
			\remove_filter( 'pre_clear_scheduled_hook', $clear_pre, 10 );
		}

		$rows[] = $ctx->result(
			'cron.filters.pre-clear-short-circuits-and-preserves-events',
			! $clear_result['threw']
				&& 7 === $clear_result['value']
				&& ! $still_there['threw']
				&& false !== $still_there['value']
				&& 1 === count( $clear_seen )
				&& $clear_hook === ( $clear_seen[0]['hook'] ?? null )
				&& $clear_args === ( $clear_seen[0]['args'] ?? null )
				&& true === ( $clear_seen[0]['error'] ?? null )
				&& false === \has_filter( 'pre_clear_scheduled_hook', $clear_pre ),
			array(
				'clearResult' => self::describe_call( $clear_result ),
				'stillThere'  => self::describe_call( $still_there ),
				'seen'        => self::describe_value( $clear_seen ),
				'store'       => self::describe_cron_store( $store ),
			)
		);

		$store           = array( 'version' => 2 );
		$reschedule_hook = 'component_fuzz_pre_reschedule_' . self::safe_hook_fragment( $ctx->text( 0, 12 ) );
		$reschedule_args = array( 'group' => 'pre-reschedule' );
		$reschedule_time = $now - 2 * \HOUR_IN_SECONDS;
		\wp_schedule_event( $reschedule_time, 'hourly', $reschedule_hook, $reschedule_args, true );

		$reschedule_seen = array();
		$reschedule_pre  = static function ( $pre, object $event, bool $wp_error ) use ( &$reschedule_seen, $reschedule_hook ) {
			$reschedule_seen[] = array(
				'pre'       => $pre,
				'hook'      => $event->hook,
				'timestamp' => $event->timestamp,
				'schedule'  => $event->schedule,
				'interval'  => $event->interval,
				'error'     => $wp_error,
			);

			return $reschedule_hook === $event->hook ? 'component-fuzz-rescheduled' : $pre;
		};

		\add_filter( 'pre_reschedule_event', $reschedule_pre, 10, 3 );
		try {
			$reschedule = self::call( static fn() => \wp_reschedule_event( $reschedule_time, 'hourly', $reschedule_hook, $reschedule_args, true ) );
			$cron_array = self::call( static fn() => \_get_cron_array() );
		} finally {
			\remove_filter( 'pre_reschedule_event', $reschedule_pre, 10 );
		}
		$timestamps = $cron_array['threw'] ? array() : self::event_timestamps( $cron_array['value'], $reschedule_hook, $reschedule_args );

		$rows[] = $ctx->result(
			'cron.filters.pre-reschedule-short-circuits-without-new-occurrence',
			! $reschedule['threw']
				&& 'component-fuzz-rescheduled' === $reschedule['value']
				&& array( $reschedule_time ) === $timestamps
				&& 1 === count( $reschedule_seen )
				&& $reschedule_hook === ( $reschedule_seen[0]['hook'] ?? null )
				&& 'hourly' === ( $reschedule_seen[0]['schedule'] ?? null )
				&& \HOUR_IN_SECONDS === ( $reschedule_seen[0]['interval'] ?? null )
				&& true === ( $reschedule_seen[0]['error'] ?? null )
				&& false === \has_filter( 'pre_reschedule_event', $reschedule_pre ),
			array(
				'reschedule' => self::describe_call( $reschedule ),
				'timestamps' => self::describe_value( $timestamps ),
				'seen'       => self::describe_value( $reschedule_seen ),
				'store'      => self::describe_cron_store( $store ),
			)
		);

		return $rows;
	}

	private static function check_scheduled_event_lookup_order( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$store = array( 'version' => 2 );
		$base  = time() + 2 * \HOUR_IN_SECONDS + $ctx->int( 0, 600 );
		$hook  = 'component_fuzz_lookup_' . self::safe_hook_fragment( $ctx->text( 0, 12 ) );
		$args  = array(
			'group' => 'lookup',
			'token' => self::safe_hook_fragment( $ctx->text( 0, 12 ) ),
		);
		$other_args = array_merge( $args, array( 'variant' => 1 ) );

		$late_time  = $base + 2 * \HOUR_IN_SECONDS;
		$early_time = $base;
		$mid_time   = $base + \HOUR_IN_SECONDS;

		$schedule_late  = self::call( static fn() => \wp_schedule_event( $late_time, 'daily', $hook, $args, true ) );
		$schedule_early = self::call( static fn() => \wp_schedule_event( $early_time, 'hourly', $hook, $args, true ) );
		$schedule_other = self::call( static fn() => \wp_schedule_event( $mid_time, 'twicedaily', $hook, $other_args, true ) );

		$next_event   = self::call( static fn() => \wp_get_scheduled_event( $hook, $args ) );
		$late_event   = self::call( static fn() => \wp_get_scheduled_event( $hook, $args, $late_time ) );
		$wrong_args   = self::call( static fn() => \wp_get_scheduled_event( $hook, array_reverse( $args ), $early_time ) );
		$wrong_time   = self::call( static fn() => \wp_get_scheduled_event( $hook, $args, $mid_time ) );
		$unschedule   = self::call( static fn() => \wp_unschedule_event( $early_time, $hook, $args, true ) );
		$after_next   = self::call( static fn() => \wp_get_scheduled_event( $hook, $args ) );
		$other_event  = self::call( static fn() => \wp_get_scheduled_event( $hook, $other_args ) );
		$cron_array   = self::call( static fn() => \_get_cron_array() );
		$timestamps   = $cron_array['threw'] ? array() : self::event_timestamps( $cron_array['value'], $hook, $args );
		$next_value   = $next_event['value'] ?? null;
		$late_value   = $late_event['value'] ?? null;
		$after_value  = $after_next['value'] ?? null;
		$other_value  = $other_event['value'] ?? null;

		return array(
			$ctx->result(
				'cron.scheduled-event-lookup-earliest-and-exactness',
				! $schedule_late['threw']
					&& true === $schedule_late['value']
					&& ! $schedule_early['threw']
					&& true === $schedule_early['value']
					&& ! $schedule_other['threw']
					&& true === $schedule_other['value']
					&& $next_value instanceof \stdClass
					&& $early_time === $next_value->timestamp
					&& 'hourly' === $next_value->schedule
					&& \HOUR_IN_SECONDS === $next_value->interval
					&& $late_value instanceof \stdClass
					&& $late_time === $late_value->timestamp
					&& 'daily' === $late_value->schedule
					&& ! $wrong_args['threw']
					&& false === $wrong_args['value']
					&& ! $wrong_time['threw']
					&& false === $wrong_time['value']
					&& ! $unschedule['threw']
					&& true === $unschedule['value']
					&& $after_value instanceof \stdClass
					&& $late_time === $after_value->timestamp
					&& $other_value instanceof \stdClass
					&& $mid_time === $other_value->timestamp
					&& array( $late_time ) === $timestamps,
				array(
					'scheduleLate'  => self::describe_call( $schedule_late ),
					'scheduleEarly' => self::describe_call( $schedule_early ),
					'scheduleOther' => self::describe_call( $schedule_other ),
					'next'          => self::describe_call( $next_event ),
					'late'          => self::describe_call( $late_event ),
					'wrongArgs'     => self::describe_call( $wrong_args ),
					'wrongTime'     => self::describe_call( $wrong_time ),
					'unschedule'    => self::describe_call( $unschedule ),
					'afterNext'     => self::describe_call( $after_next ),
					'otherEvent'    => self::describe_call( $other_event ),
					'timestamps'    => self::describe_value( $timestamps ),
					'store'         => self::describe_cron_store( $store ),
				)
			),
		);
	}

	private static function check_case( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case, array &$store ): array {
		$rows      = array();
		$timestamp = $case['timestamp'];
		$hook      = $case['hook'];
		$args      = $case['args'];
		$schedule  = $case['schedule'];
		$key       = md5( serialize( $args ) );

		$schedule_call = self::call( static fn() => \wp_schedule_event( $timestamp, $schedule, $hook, $args, true ) );
		$event_call    = self::call( static fn() => \wp_get_scheduled_event( $hook, $args, $timestamp ) );
		$next_call     = self::call( static fn() => \wp_next_scheduled( $hook, $args ) );
		$name_call     = self::call( static fn() => \wp_get_schedule( $hook, $args ) );
		$cron_array    = self::call( static fn() => \_get_cron_array() );
		$event         = $event_call['value'] ?? null;

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'cron.schedule-event.no-throw',
			! $schedule_call['threw'] && ! $event_call['threw'] && ! $next_call['threw'] && ! $name_call['threw'] && ! $cron_array['threw'],
			array(
				'scheduleEvent' => self::describe_call( $schedule_call ),
				'event'         => self::describe_call( $event_call ),
				'next'          => self::describe_call( $next_call ),
				'scheduleName'  => self::describe_call( $name_call ),
			)
		);

		if ( $schedule_call['threw'] || $event_call['threw'] || $next_call['threw'] || $name_call['threw'] || $cron_array['threw'] ) {
			return $rows;
		}

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'cron.schedule-event.stored-and-queryable',
			true === $schedule_call['value']
				&& $event instanceof \stdClass
				&& $timestamp === $event->timestamp
				&& $hook === $event->hook
				&& $args === $event->args
				&& $schedule === $event->schedule
				&& isset( $event->interval )
				&& $timestamp === $next_call['value']
				&& $schedule === $name_call['value'],
			array(
				'event'        => self::describe_value( $event ),
				'next'         => self::describe_value( $next_call['value'] ),
				'scheduleName' => self::describe_value( $name_call['value'] ),
			)
		);

		$rows[] = self::case_result(
			$ctx,
			$case_index,
			$case,
			'cron.cron-array-keyed-by-serialized-args',
			isset( $cron_array['value'][ $timestamp ][ $hook ][ $key ] )
				&& $args === $cron_array['value'][ $timestamp ][ $hook ][ $key ]['args'],
			array(
				'key'       => $key,
				'cronEntry' => self::describe_value( $cron_array['value'][ $timestamp ][ $hook ][ $key ] ?? null ),
			)
		);

		$unschedule = self::call( static fn() => \wp_unschedule_event( $timestamp, $hook, $args, true ) );
		$after      = self::call( static fn() => \wp_get_scheduled_event( $hook, $args, $timestamp ) );
		$rows[]     = self::case_result(
			$ctx,
			$case_index,
			$case,
			'cron.unschedule-event-removes-exact-event',
			! $unschedule['threw'] && true === $unschedule['value'] && ! $after['threw'] && false === $after['value'],
			array(
				'unschedule' => self::describe_call( $unschedule ),
				'after'      => self::describe_call( $after ),
				'store'      => self::describe_cron_store( $store ),
			)
		);

		return $rows;
	}

	private static function check_ready_jobs_partition( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$store = array( 'version' => 2 );
		$past  = time() - 60;
		$now   = time();
		$future = $now + \HOUR_IN_SECONDS;

		$past_call   = self::call( static fn() => \wp_schedule_single_event( $past, 'component_fuzz_ready_past', array( 'slot' => 'past' ), true ) );
		$future_call = self::call( static fn() => \wp_schedule_single_event( $future, 'component_fuzz_ready_future', array( 'slot' => 'future' ), true ) );
		$ready       = self::call( static fn() => \wp_get_ready_cron_jobs() );
		$value       = $ready['value'] ?? array();

		$ok = ! $past_call['threw']
			&& ! $future_call['threw']
			&& ! $ready['threw']
			&& true === $past_call['value']
			&& true === $future_call['value']
			&& isset( $value[ $past ]['component_fuzz_ready_past'] )
			&& ! isset( $value[ $future ]['component_fuzz_ready_future'] );

		return array(
			$ctx->result(
				'cron.ready-jobs-partitions-past-from-future',
				$ok,
				array(
					'past'   => $past,
					'future' => $future,
					'ready'  => self::describe_value( $value ),
				)
			),
		);
	}

	private static function check_spawn_request_boundaries( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$store              = array( 'version' => 2 );
		$gmt_time           = microtime( true );
		$past               = (int) $gmt_time - 60;
		$future             = (int) $gmt_time + \HOUR_IN_SECONDS;
		$transients         = array();
		$transient_sets     = array();
		$http_requests      = array();
		$cron_requests      = array();
		$ssl_decisions      = array();
		$return_http_error  = false;
		$server_snapshot    = self::snapshot_array_keys( $_SERVER, array( 'HTTP_HOST', 'HTTPS', 'REQUEST_METHOD', 'REQUEST_URI', 'SERVER_NAME' ) );
		$get_snapshot       = self::snapshot_array_keys( $_GET, array( 'doing_wp_cron' ) );

		$pre_transient_filter = static function ( $pre, string $transient ) use ( &$transients ) {
			unset( $pre, $transient );
			return $transients['doing_cron'] ?? 0;
		};
		$pre_set_transient_filter = static function ( $value, int $expiration, string $transient ) use ( &$transients, &$transient_sets ) {
			$transients[ $transient ] = $value;
			$transient_sets[]         = array(
				'transient'  => $transient,
				'value'      => $value,
				'expiration' => $expiration,
			);

			return $value;
		};
		$pre_option_transient_filter = static function ( $pre, string $option, $default ) use ( &$transients ) {
			unset( $pre, $option, $default );
			return $transients['doing_cron'] ?? 0;
		};
		$pre_option_siteurl_filter = static function ( $pre, string $option, $default ): string {
			unset( $pre, $option, $default );
			return 'http://example.test';
		};
		$ssl_filter = static function ( bool $sslverify, ?string $url = null ) use ( &$ssl_decisions ): bool {
			$ssl_decisions[] = array(
				'input' => $sslverify,
				'url'   => $url,
			);

			return true;
		};
		$cron_request_filter = static function ( array $request, string $doing_wp_cron ) use ( &$cron_requests ): array {
			$cron_requests[] = array(
				'request'        => $request,
				'doing_wp_cron' => $doing_wp_cron,
			);

			return $request;
		};
		$http_filter = static function ( $response, array $parsed_args, string $url ) use ( &$http_requests, &$return_http_error ) {
			$http_requests[] = array(
				'url'     => $url,
				'args'    => $parsed_args,
				'preType' => gettype( $response ),
			);

			if ( $return_http_error ) {
				return new \WP_Error( 'component_fuzz_cron_loopback_failed', 'Synthetic cron loopback failure.' );
			}

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 204,
					'message' => 'No Content',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		\add_filter( 'pre_transient_doing_cron', $pre_transient_filter, 10, 2 );
		\add_filter( 'pre_set_transient_doing_cron', $pre_set_transient_filter, 10, 3 );
		\add_filter( 'pre_option__transient_doing_cron', $pre_option_transient_filter, 10, 3 );
		\add_filter( 'pre_option_siteurl', $pre_option_siteurl_filter, 10, 3 );
		\add_filter( 'https_local_ssl_verify', $ssl_filter, 10, 2 );
		\add_filter( 'cron_request', $cron_request_filter, 10, 2 );
		\add_filter( 'pre_http_request', $http_filter, 10, 3 );

		try {
			$_SERVER['HTTP_HOST']      = 'example.test';
			$_SERVER['SERVER_NAME']    = 'example.test';
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['REQUEST_URI']    = '/component-fuzz/cron-spawn/';
			unset( $_SERVER['HTTPS'], $_GET['doing_wp_cron'] );

			$no_ready_http_before      = count( $http_requests );
			$no_ready_transient_before = count( $transient_sets );
			$no_ready_spawn            = self::call( static fn() => \spawn_cron( $gmt_time ) );
			$no_ready_wp_cron          = self::call( static fn() => \_wp_cron() );
			$no_ready_request_count    = count( $http_requests ) - $no_ready_http_before;
			$no_ready_transient_count  = count( $transient_sets ) - $no_ready_transient_before;

			$store             = array( 'version' => 2 );
			$transients        = array();
			$future_schedule   = self::call( static fn() => \wp_schedule_single_event( $future, 'component_fuzz_spawn_future', array( 'case' => 'future' ), true ) );
			$future_http_before = count( $http_requests );
			$future_transient_before = count( $transient_sets );
			$future_spawn     = self::call( static fn() => \spawn_cron( $gmt_time ) );
			$future_wp_cron   = self::call( static fn() => \_wp_cron() );
			$future_request_count = count( $http_requests ) - $future_http_before;
			$future_transient_count = count( $transient_sets ) - $future_transient_before;

			$store       = array( 'version' => 2 );
			$transients  = array( 'doing_cron' => sprintf( '%.22F', $gmt_time ) );
			$active_schedule = self::call( static fn() => \wp_schedule_single_event( $past, 'component_fuzz_spawn_active_lock', array( 'case' => 'active-lock' ), true ) );
			$active_http_before = count( $http_requests );
			$active_transient_before = count( $transient_sets );
			$active_spawn = self::call( static fn() => \spawn_cron( $gmt_time ) );
			$active_request_count = count( $http_requests ) - $active_http_before;
			$active_transient_count = count( $transient_sets ) - $active_transient_before;

			$store              = array( 'version' => 2 );
			$transients         = array( 'doing_cron' => sprintf( '%.22F', $gmt_time - \WP_CRON_LOCK_TIMEOUT - 5 ) );
			$return_http_error  = false;
			$stale_schedule     = self::call( static fn() => \wp_schedule_single_event( $past, 'component_fuzz_spawn_stale_lock', array( 'case' => 'stale-lock' ), true ) );
			$stale_http_before  = count( $http_requests );
			$stale_cron_before  = count( $cron_requests );
			$stale_transient_before = count( $transient_sets );
			$stale_spawn        = self::call( static fn() => \spawn_cron( $gmt_time ) );
			$stale_http_requests = array_slice( $http_requests, $stale_http_before );
			$stale_cron_requests = array_slice( $cron_requests, $stale_cron_before );
			$stale_request_count = count( $stale_http_requests );
			$stale_transient_count = count( $transient_sets ) - $stale_transient_before;

			$store                     = array( 'version' => 2 );
			$transients                = array( 'doing_cron' => sprintf( '%.22F', $gmt_time + 11 * \MINUTE_IN_SECONDS ) );
			$future_invalid_schedule   = self::call( static fn() => \wp_schedule_single_event( $past, 'component_fuzz_spawn_future_invalid_lock', array( 'case' => 'future-invalid-lock' ), true ) );
			$future_invalid_http_before = count( $http_requests );
			$future_invalid_cron_before = count( $cron_requests );
			$future_invalid_transient_before = count( $transient_sets );
			$future_invalid_spawn      = self::call( static fn() => \spawn_cron( $gmt_time ) );
			$future_invalid_http_requests = array_slice( $http_requests, $future_invalid_http_before );
			$future_invalid_cron_requests = array_slice( $cron_requests, $future_invalid_cron_before );
			$future_invalid_request_count = count( $future_invalid_http_requests );
			$future_invalid_transient_count = count( $transient_sets ) - $future_invalid_transient_before;

			$store                 = array( 'version' => 2 );
			$transients            = array();
			$return_http_error     = true;
			$error_schedule        = self::call( static fn() => \wp_schedule_single_event( $past, 'component_fuzz_spawn_error', array( 'case' => 'request-error' ), true ) );
			$error_http_before     = count( $http_requests );
			$error_transient_before = count( $transient_sets );
			$error_spawn           = self::call( static fn() => \spawn_cron( $gmt_time ) );
			$error_request_count   = count( $http_requests ) - $error_http_before;
			$error_transient_count = count( $transient_sets ) - $error_transient_before;
			$return_http_error     = false;

			$store                 = array( 'version' => 2 );
			$transients            = array();
			$_GET['doing_wp_cron'] = 'component-fuzz-guard';
			$guard_schedule        = self::call( static fn() => \wp_schedule_single_event( $past, 'component_fuzz_spawn_get_guard', array( 'case' => 'get-guard' ), true ) );
			$guard_http_before     = count( $http_requests );
			$guard_transient_before = count( $transient_sets );
			$guard_spawn           = self::call( static fn() => \spawn_cron( $gmt_time ) );
			$guard_request_count   = count( $http_requests ) - $guard_http_before;
			$guard_transient_count = count( $transient_sets ) - $guard_transient_before;
		} finally {
			\remove_filter( 'pre_transient_doing_cron', $pre_transient_filter, 10 );
			\remove_filter( 'pre_set_transient_doing_cron', $pre_set_transient_filter, 10 );
			\remove_filter( 'pre_option__transient_doing_cron', $pre_option_transient_filter, 10 );
			\remove_filter( 'pre_option_siteurl', $pre_option_siteurl_filter, 10 );
			\remove_filter( 'https_local_ssl_verify', $ssl_filter, 10 );
			\remove_filter( 'cron_request', $cron_request_filter, 10 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
			self::restore_array_keys( $_SERVER, $server_snapshot );
			self::restore_array_keys( $_GET, $get_snapshot );
		}

		$success_http = $future_invalid_http_requests[0] ?? null;
		$success_cron = $future_invalid_cron_requests[0] ?? null;
		$success_url = is_array( $success_http ) ? ( $success_http['url'] ?? '' ) : '';
		$success_query = array();
		$query_string = is_string( $success_url ) ? parse_url( $success_url, PHP_URL_QUERY ) : null;
		if ( is_string( $query_string ) ) {
			parse_str( $query_string, $success_query );
		}

		$cron_request = is_array( $success_cron ) ? ( $success_cron['request'] ?? array() ) : array();
		$cron_args    = is_array( $cron_request['args'] ?? null ) ? $cron_request['args'] : array();
		$http_args    = is_array( $success_http['args'] ?? null ) ? $success_http['args'] : array();
		$cron_key     = $cron_request['key'] ?? null;
		$payload_ok   = is_string( $success_url )
			&& false !== strpos( $success_url, '/wp-cron.php' )
			&& isset( $success_query['doing_wp_cron'] )
			&& $cron_key === $success_query['doing_wp_cron']
			&& $cron_key === ( $success_cron['doing_wp_cron'] ?? null )
			&& $success_url === ( $cron_request['url'] ?? null )
			&& isset( $cron_args['timeout'], $http_args['timeout'] )
			&& abs( 0.01 - (float) $cron_args['timeout'] ) < 0.000001
			&& abs( 0.01 - (float) $http_args['timeout'] ) < 0.000001
			&& false === ( $cron_args['blocking'] ?? null )
			&& false === ( $http_args['blocking'] ?? null )
			&& true === ( $cron_args['sslverify'] ?? null )
			&& true === ( $http_args['sslverify'] ?? null )
			&& 'POST' === ( $http_args['method'] ?? null )
			&& 'boolean' === ( $success_http['preType'] ?? null );

		$filters_restored = false === \has_filter( 'pre_transient_doing_cron', $pre_transient_filter )
			&& false === \has_filter( 'pre_set_transient_doing_cron', $pre_set_transient_filter )
			&& false === \has_filter( 'pre_option__transient_doing_cron', $pre_option_transient_filter )
			&& false === \has_filter( 'pre_option_siteurl', $pre_option_siteurl_filter )
			&& false === \has_filter( 'https_local_ssl_verify', $ssl_filter )
			&& false === \has_filter( 'cron_request', $cron_request_filter )
			&& false === \has_filter( 'pre_http_request', $http_filter );

		$superglobals_restored = self::array_keys_match_snapshot( $_SERVER, $server_snapshot )
			&& self::array_keys_match_snapshot( $_GET, $get_snapshot );

		$ok = ! $no_ready_spawn['threw']
			&& false === $no_ready_spawn['value']
			&& ! $no_ready_wp_cron['threw']
			&& 0 === $no_ready_wp_cron['value']
			&& 0 === $no_ready_request_count
			&& 0 === $no_ready_transient_count
			&& ! $future_schedule['threw']
			&& true === $future_schedule['value']
			&& ! $future_spawn['threw']
			&& false === $future_spawn['value']
			&& ! $future_wp_cron['threw']
			&& 0 === $future_wp_cron['value']
			&& 0 === $future_request_count
			&& 0 === $future_transient_count
			&& ! $active_schedule['threw']
			&& true === $active_schedule['value']
			&& ! $active_spawn['threw']
			&& false === $active_spawn['value']
			&& 0 === $active_request_count
			&& 0 === $active_transient_count
			&& ! $stale_schedule['threw']
			&& true === $stale_schedule['value']
			&& ! $stale_spawn['threw']
			&& true === $stale_spawn['value']
			&& 1 === $stale_request_count
			&& 1 === $stale_transient_count
			&& ! $future_invalid_schedule['threw']
			&& true === $future_invalid_schedule['value']
			&& ! $future_invalid_spawn['threw']
			&& true === $future_invalid_spawn['value']
			&& 1 === $future_invalid_request_count
			&& 1 === $future_invalid_transient_count
			&& $payload_ok
			&& ! $error_schedule['threw']
			&& true === $error_schedule['value']
			&& ! $error_spawn['threw']
			&& false === $error_spawn['value']
			&& 1 === $error_request_count
			&& 1 === $error_transient_count
			&& ! $guard_schedule['threw']
			&& true === $guard_schedule['value']
			&& ! $guard_spawn['threw']
			&& false === $guard_spawn['value']
			&& 0 === $guard_request_count
			&& 0 === $guard_transient_count
			&& array() !== $ssl_decisions
			&& $filters_restored
			&& $superglobals_restored;

		return array(
			$ctx->result(
				'cron.spawn.request-lock-boundaries',
				$ok,
				array(
					'calls' => array(
						'noReadySpawn'       => self::describe_call( $no_ready_spawn ),
						'noReadyWpCron'      => self::describe_call( $no_ready_wp_cron ),
						'futureSchedule'     => self::describe_call( $future_schedule ),
						'futureSpawn'        => self::describe_call( $future_spawn ),
						'futureWpCron'       => self::describe_call( $future_wp_cron ),
						'activeSchedule'     => self::describe_call( $active_schedule ),
						'activeSpawn'        => self::describe_call( $active_spawn ),
						'staleSchedule'      => self::describe_call( $stale_schedule ),
						'staleSpawn'         => self::describe_call( $stale_spawn ),
						'futureInvalidSchedule' => self::describe_call( $future_invalid_schedule ),
						'futureInvalidSpawn' => self::describe_call( $future_invalid_spawn ),
						'errorSchedule'      => self::describe_call( $error_schedule ),
						'errorSpawn'         => self::describe_call( $error_spawn ),
						'guardSchedule'      => self::describe_call( $guard_schedule ),
						'guardSpawn'         => self::describe_call( $guard_spawn ),
					),
					'requestCounts' => array(
						'noReady'       => $no_ready_request_count,
						'futureOnly'    => $future_request_count,
						'activeLock'    => $active_request_count,
						'staleLock'     => $stale_request_count,
						'futureInvalid' => $future_invalid_request_count,
						'requestError'  => $error_request_count,
						'getGuard'      => $guard_request_count,
					),
					'transientSetCounts' => array(
						'noReady'       => $no_ready_transient_count,
						'futureOnly'    => $future_transient_count,
						'activeLock'    => $active_transient_count,
						'staleLock'     => $stale_transient_count,
						'futureInvalid' => $future_invalid_transient_count,
						'requestError'  => $error_transient_count,
						'getGuard'      => $guard_transient_count,
					),
					'payloadOk'             => $payload_ok,
					'capturedSuccessHttp'   => self::describe_value( $success_http ),
					'capturedSuccessRequest' => self::describe_value( $success_cron ),
					'sslDecisions'          => self::describe_value( $ssl_decisions ),
					'transientSets'         => self::describe_value( $transient_sets ),
					'filtersRestored'       => $filters_restored,
					'superglobalsRestored'  => $superglobals_restored,
					'store'                 => self::describe_cron_store( $store ),
				)
			),
		);
	}

	private static function check_clear_and_unschedule( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$store = array( 'version' => 2 );
		$base  = time() + 2 * \HOUR_IN_SECONDS;
		$args  = array( 'group' => 'clear', 'n' => 1 );

		\wp_schedule_single_event( $base, 'component_fuzz_clear_args', $args, true );
		\wp_schedule_single_event( $base + 1200, 'component_fuzz_clear_args', $args, true );
		\wp_schedule_single_event( $base + 2400, 'component_fuzz_clear_args', array( 'group' => 'keep', 'n' => 2 ), true );
		\wp_schedule_single_event( $base + 3600, 'component_fuzz_clear_all', array( 'n' => 1 ), true );
		\wp_schedule_single_event( $base + 4800, 'component_fuzz_clear_all', array( 'n' => 2 ), true );

		$clear_exact = self::call( static fn() => \wp_clear_scheduled_hook( 'component_fuzz_clear_args', $args, true ) );
		$kept        = self::call( static fn() => \wp_next_scheduled( 'component_fuzz_clear_args', array( 'group' => 'keep', 'n' => 2 ) ) );
		$removed     = self::call( static fn() => \wp_next_scheduled( 'component_fuzz_clear_args', $args ) );
		$clear_all   = self::call( static fn() => \wp_unschedule_hook( 'component_fuzz_clear_all', true ) );
		$after_all   = self::call( static fn() => \wp_next_scheduled( 'component_fuzz_clear_all', array( 'n' => 1 ) ) );

		return array(
			$ctx->result(
				'cron.clear-scheduled-hook-removes-only-matching-args',
				! $clear_exact['threw']
					&& 2 === $clear_exact['value']
					&& ! $kept['threw']
					&& false !== $kept['value']
					&& ! $removed['threw']
					&& false === $removed['value'],
				array(
					'clearExact' => self::describe_call( $clear_exact ),
					'kept'       => self::describe_call( $kept ),
					'removed'    => self::describe_call( $removed ),
				)
			),
			$ctx->result(
				'cron.unschedule-hook-removes-all-hook-events',
				! $clear_all['threw'] && 2 === $clear_all['value'] && ! $after_all['threw'] && false === $after_all['value'],
				array(
					'clearAll' => self::describe_call( $clear_all ),
					'afterAll' => self::describe_call( $after_all ),
				)
			),
		);
	}

	private static function check_reschedule_contract( \ComponentFuzz\FuzzContext $ctx, array &$store ): array {
		$store     = array( 'version' => 2 );
		$timestamp = time() - 2 * \HOUR_IN_SECONDS;
		$hook      = 'component_fuzz_reschedule';
		$args      = array( 'kind' => 'past-recurring' );
		\wp_schedule_event( $timestamp, 'hourly', $hook, $args, true );

		$reschedule = self::call( static fn() => \wp_reschedule_event( $timestamp, 'hourly', $hook, $args, true ) );
		$cron_array = self::call( static fn() => \_get_cron_array() );
		$timestamps = $cron_array['threw'] ? array() : self::event_timestamps( $cron_array['value'], $hook, $args );
		$future     = array_values(
			array_filter(
				$timestamps,
				static fn( int $event_timestamp ): bool => $event_timestamp >= time()
			)
		);

		$ok = ! $reschedule['threw']
			&& true === $reschedule['value']
			&& ! $cron_array['threw']
			&& in_array( $timestamp, $timestamps, true )
			&& array() !== $future;

		return array(
			$ctx->result(
				'cron.reschedule-event-creates-next-future-occurrence',
				$ok,
				array(
					'reschedule' => self::describe_call( $reschedule ),
					'timestamps' => self::describe_value( $timestamps ),
					'future'     => self::describe_value( $future ),
					'store'      => self::describe_cron_store( $store ),
				)
			),
		);
	}

	private static function cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$now   = time();
		$cases = array(
			self::case( 'hourly-simple', 'component_fuzz_hourly', array( 'alpha', 1 ), $now + \HOUR_IN_SECONDS, 'hourly', array( 'corpus', 'recurring' ) ),
			self::case( 'daily-assoc-args', 'component_fuzz_daily', array( 'post_id' => 123, 'refresh' => true ), $now + \DAY_IN_SECONDS, 'daily', array( 'corpus', 'assocArgs' ) ),
			self::case( 'unicode-hook', "component_fuzz_gr\u{00E5}", array( "jos\u{00E9}", 'x' => "snowman-\u{2603}" ), $now + 2 * \HOUR_IN_SECONDS, 'twicedaily', array( 'corpus', 'unicode' ) ),
			self::case( 'custom-schedule', 'component_fuzz_custom', array( 'interval' => 37 ), $now + 90, 'component_fuzz_short', array( 'corpus', 'customSchedule' ) ),
			self::case( 'punctuation-hook', 'component-fuzz/punctuation.hook', array( 'a@b', 'c.d', 'space value' ), $now + 3 * \HOUR_IN_SECONDS, 'weekly', array( 'corpus', 'punctuation' ) ),
		);

		$schedules = array( 'hourly', 'twicedaily', 'daily', 'weekly', 'component_fuzz_short' );
		for ( $i = 0; $i < self::GENERATED_CASES; $i++ ) {
			$hook      = 'component_fuzz_generated_' . $i . '_' . self::safe_hook_fragment( $ctx->text( 0, 14 ) );
			$schedule  = $ctx->choice( $schedules );
			$timestamp = $now + $ctx->int( 60, 4 * \DAY_IN_SECONDS );
			$args      = array(
				'index' => $i,
				'label' => $ctx->text( 0, 24 ),
				'flag'  => $ctx->bool(),
				'n'     => $ctx->int( -20, 20 ),
			);
			if ( $ctx->bool( 25 ) ) {
				$args['nested'] = array(
					'a' => $ctx->text( 0, 12 ),
					'b' => $ctx->int( 0, 5 ),
				);
			}

			$cases[] = self::case(
				'generated-' . $i,
				$hook,
				$args,
				$timestamp,
				$schedule,
				array( 'generated' )
			);
		}

		return $cases;
	}

	private static function case( string $label, string $hook, array $args, int $timestamp, string $schedule, array $traits ): array {
		return array(
			'label'     => $label,
			'hook'      => $hook,
			'args'      => $args,
			'timestamp' => $timestamp,
			'schedule'  => $schedule,
			'traits'    => $traits,
		);
	}

	private static function install_memory_cron_store( array &$store ): void {
		$GLOBALS['wpdb'] = new class() {
			public $options = 'wp_options';
			public $prefix = 'wp_';
			public $suppress_errors = false;

			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}

				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[sdFf]/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
				}
				return $query;
			}

			public function _escape( $data ) {
				if ( is_array( $data ) ) {
					return array_map( array( $this, '_escape' ), $data );
				}
				return addslashes( (string) $data );
			}

			public function update( $table, $data, $where ) {
				unset( $table, $data, $where );
				return 1;
			}

			public function suppress_errors( $suppress = true ) {
				$previous = $this->suppress_errors;
				$this->suppress_errors = (bool) $suppress;
				return $previous;
			}

			public function get_var( $query = null, $x = 0, $y = 0 ) {
				unset( $query, $x, $y );
				return null;
			}

			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				unset( $query, $output, $y );
				return null;
			}

			public function get_results( $query = null, $output = OBJECT ) {
				unset( $query, $output );
				return array();
			}

			public function get_blog_prefix( $blog_id = null ) {
				unset( $blog_id );
				return 'wp_';
			}
		};

		\add_filter(
			'pre_option_cron',
			static function () use ( &$store ) {
				return $store;
			},
			0,
			3
		);

		\add_filter(
			'pre_update_option_cron',
			static function ( $value ) use ( &$store ) {
				$store = is_array( $value ) ? $value : array( 'version' => 2 );
				return $value;
			},
			0,
			3
		);

		\add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['component_fuzz_short'] = array(
					'interval' => 37,
					'display'  => 'Component fuzz short interval',
				);
				return $schedules;
			}
		);
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'     => true,
				'throwable' => self::describe_throwable( $e ),
			);
		}
	}

	private static function case_result( \ComponentFuzz\FuzzContext $ctx, int $case_index, array $case, string $invariant, bool $ok, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			$ok,
			array_merge(
				array(
					'caseIndex' => $case_index,
					'label'     => $case['label'],
					'traits'    => implode( ',', $case['traits'] ),
					'hook'      => self::describe_string( $case['hook'] ),
					'args'      => self::describe_value( $case['args'] ),
					'timestamp' => $case['timestamp'],
					'schedule'  => $case['schedule'],
				),
				$data
			)
		);
	}

	private static function error_code( $value ): ?string {
		return $value instanceof \WP_Error ? $value->get_error_code() : null;
	}

	private static function event_timestamps( array $cron, string $hook, array $args ): array {
		$key        = md5( serialize( $args ) );
		$timestamps = array();
		foreach ( $cron as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ][ $key ] ) ) {
				$timestamps[] = (int) $timestamp;
			}
		}
		sort( $timestamps );
		return $timestamps;
	}

	private static function safe_hook_fragment( string $value ): string {
		$fragment = preg_replace( '/[^A-Za-z0-9_]+/', '_', self::escape_bytes( $value, 32 ) );
		$fragment = trim( (string) $fragment, '_' );
		return '' === $fragment ? 'empty' : substr( $fragment, 0, 32 );
	}

	private static function describe_call( array $call ) {
		if ( $call['threw'] ) {
			return array(
				'threw'     => true,
				'throwable' => $call['throwable'],
			);
		}
		return array(
			'threw' => false,
			'value' => self::describe_value( $call['value'] ),
		);
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( $value instanceof \WP_Error ) {
			return array(
				'class' => 'WP_Error',
				'code'  => $value->get_error_code(),
			);
		}
		if ( is_object( $value ) ) {
			$out = array( 'class' => get_class( $value ) );
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}
		if ( is_array( $value ) ) {
			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 12 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item );
				$i++;
			}
			return $out;
		}
		return $value;
	}

	private static function describe_cron_store( array $store ): array {
		$copy = $store;
		unset( $copy['version'] );
		return array(
			'version'    => $store['version'] ?? null,
			'timestamps' => array_keys( $copy ),
			'eventCount' => self::count_events( $copy ),
		);
	}

	private static function count_events( array $cron ): int {
		$count = 0;
		foreach ( $cron as $hooks ) {
			foreach ( (array) $hooks as $events ) {
				$count += count( (array) $events );
			}
		}
		return $count;
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => self::escape_bytes( $value ),
			'sha1'    => sha1( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
		);
	}

	private static function escape_bytes( string $value, int $limit = 160 ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; $i++ ) {
			$byte = ord( $value[ $i ] );
			if ( $byte >= 0x20 && $byte <= 0x7e && 0x5c !== $byte ) {
				$out .= chr( $byte );
			} elseif ( 0x5c === $byte ) {
				$out .= '\\\\';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $limit ) {
			$out .= '...';
		}

		return $out;
	}

	private static function snapshot_array_keys( array $source, array $keys ): array {
		$snapshot = array();
		foreach ( $keys as $key ) {
			$snapshot[ $key ] = array(
				'exists' => array_key_exists( $key, $source ),
				'value'  => array_key_exists( $key, $source ) ? self::clone_value( $source[ $key ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_array_keys( array &$target, array $snapshot ): void {
		foreach ( $snapshot as $key => $entry ) {
			if ( $entry['exists'] ) {
				$target[ $key ] = $entry['value'];
			} else {
				unset( $target[ $key ] );
			}
		}
	}

	private static function array_keys_match_snapshot( array $target, array $snapshot ): bool {
		foreach ( $snapshot as $key => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $key, $target ) ) {
				return false;
			}

			if ( $entry['exists'] && $entry['value'] !== $target[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_globals(): array {
		$snapshot = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wpdb' ) as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
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
