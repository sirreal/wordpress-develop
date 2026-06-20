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

			foreach ( self::cases( $ctx ) as $case_index => $case ) {
				$store = array( 'version' => 2 );
				$rows  = array_merge( $rows, self::check_case( $ctx, $case_index, $case, $store ) );
			}

			$rows = array_merge( $rows, self::check_ready_jobs_partition( $ctx, $store ) );
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
				'remove_all_filters',
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
