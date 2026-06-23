<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes deterministic no-DB WordPress date/time helpers.
 */
final class DateTimeSurface {
	public const NAME = 'date-time';

	private const CASES         = 18;
	private const FAILURE_LIMIT = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'date-time.required-apis-available',
					'Required WordPress date/time APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_runtime();

		try {
			$timestamps = self::timestamp_cases( $ctx->fork( 'timestamps' ) );
			$timezones  = self::timezone_cases( $ctx->fork( 'timezones' ) );
			$formats    = self::format_cases( $ctx->fork( 'formats' ) );
			$mysql      = self::mysql_cases( $ctx->fork( 'mysql' ), $timestamps, $timezones );
			$offsets    = self::offset_cases( $ctx->fork( 'offsets' ) );

			$rows = array(
				self::check_wp_date_oracle( $ctx, $timestamps, $timezones, $formats ),
				self::check_date_i18n_oracle( $ctx, $timestamps, $timezones, $formats ),
				self::check_mysql2date_oracle( $ctx, $mysql, $timezones ),
				self::check_timezone_options_and_current_time( $ctx, $timezones, $offsets ),
				self::check_gmt_local_round_trips( $ctx, $timestamps, $timezones ),
				self::check_iso8601_offsets( $ctx, $offsets ),
				self::check_weekstartend_windows( $ctx, $timestamps ),
				self::check_human_time_diff( $ctx ),
				self::check_safe_format_option_filters( $ctx, $timestamps, $timezones, $formats ),
			);
		} catch ( \Throwable $e ) {
			$rows = array(
				self::throwable_row( $ctx, 'date-time.surface-no-throw', $e ),
			);
		} finally {
			self::restore_runtime( $snapshot );
		}

		$rows[] = self::check_runtime_restored( $ctx, $snapshot );

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'wp_date',
				'date_i18n',
				'current_time',
				'mysql2date',
				'get_gmt_from_date',
				'get_date_from_gmt',
				'iso8601_timezone_to_offset',
				'wp_timezone_string',
				'wp_timezone',
				'get_weekstartend',
				'human_time_diff',
				'get_option',
				'add_filter',
				'remove_filter',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'DateTimeImmutable', 'DateTimeZone' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_wp_date_oracle( \ComponentFuzz\FuzzContext $ctx, array $timestamps, array $timezones, array $formats ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( $timestamps as $index => $timestamp ) {
			$timezone = new \DateTimeZone( $timezones[ $index % count( $timezones ) ] );
			$format   = $formats[ $index % count( $formats ) ];
			$expected = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format );
			$actual   = \wp_date( $format, $timestamp, $timezone );
			++$cases;

			self::sample(
				$samples,
				array(
					'timestamp' => $timestamp,
					'timezone'  => $timezone->getName(),
					'format'    => $format,
					'expected'  => $expected,
					'actual'    => $actual,
				)
			);

			if ( $actual !== $expected ) {
				$failures[] = array(
					'api'       => 'wp_date',
					'timestamp' => $timestamp,
					'timezone'  => $timezone->getName(),
					'format'    => $format,
					'expected'  => $expected,
					'actual'    => self::describe_value( $actual ),
				);
			}
		}

		$invalid = \wp_date( 'Y-m-d H:i:s', 'not-a-timestamp', new \DateTimeZone( 'UTC' ) );
		if ( false !== $invalid ) {
			$failures[] = array(
				'api'      => 'wp_date',
				'case'     => 'invalid timestamp',
				'expected' => false,
				'actual'   => self::describe_value( $invalid ),
			);
		}

		return self::result(
			$ctx,
			'date-time.wp-date-datetimeimmutable-oracle',
			$failures,
			array(
				'cases'     => $cases,
				'timezones' => array_values( array_unique( $timezones ) ),
				'formats'   => $formats,
				'preEpoch'  => count( array_filter( $timestamps, static fn ( int $timestamp ): bool => $timestamp < 0 ) ),
				'samples'   => $samples,
				'failures'  => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_date_i18n_oracle( \ComponentFuzz\FuzzContext $ctx, array $timestamps, array $timezones, array $formats ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( $timestamps as $index => $timestamp ) {
			$timezone_name = $timezones[ $index % count( $timezones ) ];
			$timezone      = new \DateTimeZone( $timezone_name );
			$format        = self::date_i18n_format( $formats[ ( $index + 3 ) % count( $formats ) ] );

			self::with_option_filters(
				array(
					'timezone_string' => $timezone_name,
					'gmt_offset'      => 0,
				),
				static function () use ( $timestamp, $timezone, $format, &$failures, &$samples, &$cases ): void {
					$local_time = gmdate( 'Y-m-d H:i:s', $timestamp );
					$expected   = ( new \DateTimeImmutable( $local_time, $timezone ) )->format( $format );
					$actual     = \date_i18n( $format, $timestamp, false );
					$actual_u   = \date_i18n( 'U', $timestamp, false );
					++$cases;

					self::sample(
						$samples,
						array(
							'timestampWithOffset' => $timestamp,
							'timezone'            => $timezone->getName(),
							'format'              => $format,
							'expected'            => $expected,
							'actual'              => $actual,
						)
					);

					if ( $actual !== $expected ) {
						$failures[] = array(
							'api'                 => 'date_i18n',
							'timestampWithOffset' => $timestamp,
							'timezone'            => $timezone->getName(),
							'format'              => $format,
							'expected'            => $expected,
							'actual'              => self::describe_value( $actual ),
						);
					}

					if ( (string) $timestamp !== (string) $actual_u ) {
						$failures[] = array(
							'api'                 => 'date_i18n',
							'case'                => 'U preserves explicit timestamp-with-offset',
							'timestampWithOffset' => $timestamp,
							'expected'            => (string) $timestamp,
							'actual'              => self::describe_value( $actual_u ),
						);
					}
				}
			);
		}

		return self::result(
			$ctx,
			'date-time.date-i18n-explicit-timestamp-oracle',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_mysql2date_oracle( \ComponentFuzz\FuzzContext $ctx, array $mysql_cases, array $timezones ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( $mysql_cases as $index => $case ) {
			$mysql         = $case['mysql'];
			$timezone_name = $case['timezone'] ?? $timezones[ $index % count( $timezones ) ];
			$timezone      = new \DateTimeZone( $timezone_name );
			$format        = 0 === $index % 3 ? 'Y-m-d H:i:s' : 'Y-m-d\TH:i:sP';

			self::with_option_filters(
				array(
					'timezone_string' => $timezone_name,
					'gmt_offset'      => 0,
				),
				static function () use ( $mysql, $timezone, $format, &$failures, &$samples, &$cases ): void {
					$expected_u         = strtotime( $mysql . ' UTC' );
					$actual_u           = \mysql2date( 'U', $mysql, false );
					$expected_formatted = ( new \DateTimeImmutable( $mysql, $timezone ) )->format( $format );
					$actual_formatted   = \mysql2date( $format, $mysql, false );
					++$cases;

					self::sample(
						$samples,
						array(
							'mysql'             => $mysql,
							'timezone'          => $timezone->getName(),
							'expectedTimestamp' => $expected_u,
							'actualTimestamp'   => $actual_u,
							'format'            => $format,
						)
					);

					if ( false === $expected_u || (string) $expected_u !== (string) $actual_u ) {
						$failures[] = array(
							'api'      => 'mysql2date',
							'format'   => 'U',
							'mysql'    => $mysql,
							'timezone' => $timezone->getName(),
							'expected' => self::describe_value( $expected_u ),
							'actual'   => self::describe_value( $actual_u ),
						);
					}

					if ( $actual_formatted !== $expected_formatted ) {
						$failures[] = array(
							'api'      => 'mysql2date',
							'format'   => $format,
							'mysql'    => $mysql,
							'timezone' => $timezone->getName(),
							'expected' => $expected_formatted,
							'actual'   => self::describe_value( $actual_formatted ),
						);
					}
				}
			);
		}

		$invalid = \mysql2date( 'U', '', false );
		if ( false !== $invalid ) {
			$failures[] = array(
				'api'      => 'mysql2date',
				'case'     => 'empty date',
				'expected' => false,
				'actual'   => self::describe_value( $invalid ),
			);
		}

		return self::result(
			$ctx,
			'date-time.mysql2date-strtotime-oracle',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_timezone_options_and_current_time( \ComponentFuzz\FuzzContext $ctx, array $timezones, array $offsets ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( array_slice( $timezones, 0, min( 8, count( $timezones ) ) ) as $timezone_name ) {
			self::with_option_filters(
				array(
					'timezone_string' => $timezone_name,
					'gmt_offset'      => 0,
				),
				static function () use ( $timezone_name, &$failures, &$samples, &$cases ): void {
					$string = \wp_timezone_string();
					$zone   = \wp_timezone();
					++$cases;

					self::sample(
						$samples,
						array(
							'mode'              => 'timezone_string',
							'timezoneString'    => $timezone_name,
							'wpTimezoneString'  => $string,
							'wpTimezoneGetName' => $zone->getName(),
						)
					);

					if ( $string !== $timezone_name || $zone->getName() !== $timezone_name ) {
						$failures[] = array(
							'case'              => 'timezone_string option wins',
							'timezoneString'    => $timezone_name,
							'wpTimezoneString'  => $string,
							'wpTimezoneGetName' => $zone->getName(),
						);
					}
				}
			);
		}

		foreach ( array_slice( $offsets, 0, min( 10, count( $offsets ) ) ) as $offset ) {
			self::with_option_filters(
				array(
					'timezone_string' => '',
					'gmt_offset'      => $offset,
				),
				static function () use ( $offset, &$failures, &$samples, &$cases ): void {
					$expected_string = self::format_gmt_offset( $offset );
					$string          = \wp_timezone_string();
					$zone            = \wp_timezone();

					$before = time();
					$gmt    = \time();
					$local  = \current_time( 'timestamp', false );
					$after  = time();
					++$cases;

					self::sample(
						$samples,
						array(
							'mode'             => 'gmt_offset',
							'offset'           => $offset,
							'expectedTimezone' => $expected_string,
							'actualTimezone'   => $string,
							'currentDelta'     => $local - $gmt,
						)
					);

					if ( $string !== $expected_string || $zone->getName() !== $expected_string ) {
						$failures[] = array(
							'case'              => 'gmt_offset fallback',
							'offset'            => $offset,
							'expected'          => $expected_string,
							'wpTimezoneString'  => $string,
							'wpTimezoneGetName' => $zone->getName(),
						);
					}

					$expected_delta = (int) ( (float) $offset * HOUR_IN_SECONDS );
					if ( $gmt < $before || $gmt > $after || abs( ( $local - $gmt ) - $expected_delta ) > 2 ) {
						$failures[] = array(
							'api'           => 'current_time',
							'offset'        => $offset,
							'expectedDelta' => $expected_delta,
							'actualDelta'   => $local - $gmt,
							'gmtWindow'     => array( $before, $after ),
							'gmt'           => $gmt,
							'local'         => $local,
						);
					}
				}
			);
		}

		return self::result(
			$ctx,
			'date-time.timezone-options-and-current-time',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_gmt_local_round_trips( \ComponentFuzz\FuzzContext $ctx, array $timestamps, array $timezones ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( $timestamps as $index => $timestamp ) {
			$timezone_name = $timezones[ $index % count( $timezones ) ];
			$timezone      = new \DateTimeZone( $timezone_name );
			$local         = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( 'Y-m-d H:i:s' );

			self::with_option_filters(
				array(
					'timezone_string' => $timezone_name,
					'gmt_offset'      => 0,
				),
				static function () use ( $local, $timezone, $timestamp, &$failures, &$samples, &$cases ): void {
					$gmt           = \get_gmt_from_date( $local );
					$round_trip    = \get_date_from_gmt( $gmt );
					$expected_gmt  = ( new \DateTimeImmutable( $local, $timezone ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
					$expected_year = ( new \DateTimeImmutable( $local, $timezone ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y' );
					$actual_year   = \get_gmt_from_date( $local, 'Y' );
					++$cases;

					self::sample(
						$samples,
						array(
							'timestamp' => $timestamp,
							'timezone'  => $timezone->getName(),
							'local'     => $local,
							'gmt'       => $gmt,
							'roundTrip' => $round_trip,
						)
					);

					if ( $gmt !== $expected_gmt || $round_trip !== $local || $actual_year !== $expected_year ) {
						$failures[] = array(
							'api'          => 'get_gmt_from_date/get_date_from_gmt',
							'timestamp'    => $timestamp,
							'timezone'     => $timezone->getName(),
							'local'        => $local,
							'expectedGmt'  => $expected_gmt,
							'actualGmt'    => $gmt,
							'roundTrip'    => $round_trip,
							'expectedYear' => $expected_year,
							'actualYear'   => $actual_year,
						);
					}
				}
			);
		}

		return self::result(
			$ctx,
			'date-time.gmt-local-conversion-roundtrip',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_iso8601_offsets( \ComponentFuzz\FuzzContext $ctx, array $offsets ): array {
		$tokens   = array( 'Z', '+0000', '-0000', '+1245', '-0330', '+0545', '-1100' );
		$failures = array();
		$samples  = array();

		foreach ( $offsets as $offset ) {
			$tokens[] = self::iso8601_token_from_offset( $offset );
		}

		$tokens = array_values( array_unique( $tokens ) );
		foreach ( $tokens as $token ) {
			$expected = self::parse_iso8601_offset( $token );
			$actual   = \iso8601_timezone_to_offset( $token );

			self::sample(
				$samples,
				array(
					'token'    => $token,
					'expected' => $expected,
					'actual'   => $actual,
				)
			);

			if ( ! is_numeric( $actual ) || abs( (float) $actual - (float) $expected ) > 0.000001 ) {
				$failures[] = array(
					'api'      => 'iso8601_timezone_to_offset',
					'token'    => $token,
					'expected' => $expected,
					'actual'   => self::describe_value( $actual ),
				);
			}
		}

		return self::result(
			$ctx,
			'date-time.iso8601-offset-independent-parser',
			$failures,
			array(
				'cases'    => count( $tokens ),
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_weekstartend_windows( \ComponentFuzz\FuzzContext $ctx, array $timestamps ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( $timestamps as $index => $timestamp ) {
			$start_of_week = $index % 7;
			$mysql         = gmdate( 'Y-m-d H:i:s', $timestamp );
			$week          = \get_weekstartend( $mysql, $start_of_week );
			++$cases;

			self::sample(
				$samples,
				array(
					'timestamp'   => $timestamp,
					'mysql'       => $mysql,
					'startOfWeek' => $start_of_week,
					'week'        => $week,
				)
			);

			$valid_shape = is_array( $week ) && isset( $week['start'], $week['end'] ) && is_int( $week['start'] ) && is_int( $week['end'] );
			$contains    = $valid_shape && $week['start'] <= $timestamp && $timestamp <= $week['end'];
			$seven_days  = $valid_shape && WEEK_IN_SECONDS - 1 === $week['end'] - $week['start'];
			$starts_day  = $valid_shape && (int) gmdate( 'w', $week['start'] ) === $start_of_week;

			if ( ! $valid_shape || ! $contains || ! $seven_days || ! $starts_day ) {
				$failures[] = array(
					'api'         => 'get_weekstartend',
					'timestamp'   => $timestamp,
					'mysql'       => $mysql,
					'startOfWeek' => $start_of_week,
					'week'        => self::describe_value( $week ),
					'validShape'  => $valid_shape,
					'contains'    => $contains,
					'sevenDays'   => $seven_days,
					'startsOnDay' => $starts_day,
				);
			}
		}

		return self::result(
			$ctx,
			'date-time.weekstartend-seven-day-containing-window',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_human_time_diff( \ComponentFuzz\FuzzContext $ctx ): array {
		$base     = 1700000000 + $ctx->int( -100000, 100000 );
		$deltas   = self::human_delta_cases( $ctx->fork( 'human-deltas' ) );
		$failures = array();
		$samples  = array();
		$previous = null;

		foreach ( $deltas as $delta ) {
			$output     = \human_time_diff( $base, $base + $delta );
			$normalized = self::parse_human_time_diff_seconds( $output );
			$safe       = is_string( $output )
				&& strip_tags( $output ) === $output
				&& ! str_contains( $output, '<' )
				&& ! str_contains( $output, '>' );

			self::sample(
				$samples,
				array(
					'delta'      => $delta,
					'output'     => $output,
					'normalized' => $normalized,
				)
			);

			if ( ! $safe || null === $normalized || ( null !== $previous && $previous > $normalized ) ) {
				$failures[] = array(
					'api'        => 'human_time_diff',
					'delta'      => $delta,
					'output'     => self::describe_value( $output ),
					'normalized' => $normalized,
					'previous'   => $previous,
					'safeMarkup' => $safe,
				);
			}

			if ( null !== $normalized ) {
				$previous = $normalized;
			}
		}

		return self::result(
			$ctx,
			'date-time.human-time-diff-monotonic-safe-text',
			$failures,
			array(
				'cases'    => count( $deltas ),
				'base'     => $base,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_safe_format_option_filters( \ComponentFuzz\FuzzContext $ctx, array $timestamps, array $timezones, array $formats ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( array_slice( $timestamps, 0, self::CASES ) as $index => $timestamp ) {
			$timezone_name = $timezones[ ( $index + 2 ) % count( $timezones ) ];
			$timezone      = new \DateTimeZone( $timezone_name );
			$date_format   = self::date_option_format( $formats[ $index % count( $formats ) ] );
			$time_format   = self::time_option_format( $formats[ ( $index + 5 ) % count( $formats ) ] );
			$format        = $date_format . ' ' . $time_format;

			self::with_option_filters(
				array(
					'timezone_string' => $timezone_name,
					'gmt_offset'      => 0,
					'date_format'     => $date_format,
					'time_format'     => $time_format,
				),
				static function () use ( $timestamp, $timezone, $date_format, $time_format, $format, &$failures, &$samples, &$cases ): void {
					$actual_date_format = \get_option( 'date_format' );
					$actual_time_format = \get_option( 'time_format' );
					$expected           = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format );
					$actual             = \wp_date( $actual_date_format . ' ' . $actual_time_format, $timestamp );
					$safe               = is_string( $actual )
						&& strip_tags( $actual ) === $actual
						&& ! str_contains( $actual, '<' )
						&& ! str_contains( $actual, '>' );
					++$cases;

					self::sample(
						$samples,
						array(
							'timestamp'  => $timestamp,
							'timezone'   => $timezone->getName(),
							'dateFormat' => $date_format,
							'timeFormat' => $time_format,
							'actual'     => $actual,
						)
					);

					if ( $date_format !== $actual_date_format || $time_format !== $actual_time_format || $expected !== $actual || ! $safe ) {
						$failures[] = array(
							'api'                => 'date/time format option filters with wp_date',
							'timestamp'          => $timestamp,
							'timezone'           => $timezone->getName(),
							'expectedDateFormat' => $date_format,
							'actualDateFormat'   => self::describe_value( $actual_date_format ),
							'expectedTimeFormat' => $time_format,
							'actualTimeFormat'   => self::describe_value( $actual_time_format ),
							'expected'           => $expected,
							'actual'             => self::describe_value( $actual ),
							'safeMarkup'         => $safe,
						);
					}
				}
			);
		}

		return self::result(
			$ctx,
			'date-time.safe-format-and-timezone-option-filters',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_runtime_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$failures = array();

		if ( date_default_timezone_get() !== $snapshot['phpTimezone'] ) {
			$failures[] = array(
				'global'   => 'date_default_timezone',
				'expected' => $snapshot['phpTimezone'],
				'actual'   => date_default_timezone_get(),
			);
		}

		if ( ( $GLOBALS['wp_locale'] ?? null ) !== $snapshot['wpLocale'] ) {
			$failures[] = array(
				'global' => 'wp_locale',
				'issue'  => 'Global locale object reference changed.',
			);
		}

		$current_options = self::option_store_snapshot();
		if ( $current_options !== $snapshot['options'] ) {
			$failures[] = array(
				'global' => 'wpdb options',
				'issue'  => 'Option store changed across date-time surface.',
			);
		}

		foreach ( $snapshot['trackedFilters'] as $hook => $before ) {
			$after = self::filter_fingerprint( $hook );
			if ( $after !== $before ) {
				$failures[] = array(
					'hook'   => $hook,
					'before' => $before,
					'after'  => $after,
				);
			}
		}

		return self::result(
			$ctx,
			'date-time.runtime-state-restored',
			$failures,
			array(
				'checkedFilters' => array_keys( $snapshot['trackedFilters'] ),
				'failures'       => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function timestamp_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			-2208988800, // 1900-01-01.
			-31536000,
			-86400,
			-1,
			0,
			1,
			946684800,
			1583650799,
			1583650800,
			1615705199,
			1615705200,
			1636264799,
			1636264800,
			1711846799,
			1711846800,
			2147483647,
			4102444799,
		);

		while ( count( $cases ) < self::CASES + 10 ) {
			$cases[] = $ctx->int( -2208988800, 4102444799 );
		}

		return array_values( array_unique( $cases ) );
	}

	private static function timezone_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			'UTC',
			'Europe/Madrid',
			'Europe/London',
			'America/New_York',
			'America/St_Johns',
			'America/Sao_Paulo',
			'Asia/Kathmandu',
			'Asia/Tokyo',
			'Australia/Lord_Howe',
			'Pacific/Auckland',
			'Pacific/Chatham',
			'Etc/GMT+12',
		);

		$available = \DateTimeZone::listIdentifiers();
		for ( $i = 0; $i < 6; ++$i ) {
			$cases[] = $available[ $ctx->int( 0, count( $available ) - 1 ) ];
		}

		return array_values( array_unique( $cases ) );
	}

	private static function format_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			'Y-m-d H:i:s',
			'Y-m-d\TH:i:sP',
			'c',
			'U',
			'O P T e',
			'z W N t L o',
			'D, d M Y H:i:s O',
			'\w\e\e\k-W \d\a\y-N H:i:s.u',
			'Y/m/d g:i:s A',
			'Y-m-d H:i:s.v',
		);

		$tokens     = array( 'Y', 'm', 'd', 'H', 'i', 's', 'O', 'P', 'T', 'U', 'W', 'N', 'z' );
		$separators = array( '-', '/', ':', ' ', 'T', '_', '.' );
		for ( $i = 0; $i < 8; ++$i ) {
			$format = '';
			$parts  = $ctx->int( 3, 7 );
			for ( $j = 0; $j < $parts; ++$j ) {
				if ( '' !== $format ) {
					$format .= $ctx->choice( $separators );
				}
				$format .= $ctx->choice( $tokens );
			}
			$cases[] = $format;
		}

		return array_values( array_unique( $cases ) );
	}

	private static function mysql_cases( \ComponentFuzz\FuzzContext $ctx, array $timestamps, array $timezones ): array {
		$cases = array(
			array(
				'mysql'    => '1969-12-31 23:59:59',
				'timezone' => 'UTC',
			),
			array(
				'mysql'    => '1970-01-01 00:00:00',
				'timezone' => 'Europe/Madrid',
			),
			array(
				'mysql'    => '2024-02-29 12:34:56',
				'timezone' => 'America/New_York',
			),
			array(
				'mysql'    => '2038-01-19 03:14:07',
				'timezone' => 'Asia/Kathmandu',
			),
		);

		foreach ( array_slice( $timestamps, 0, self::CASES ) as $index => $timestamp ) {
			$timezone_name = $timezones[ $index % count( $timezones ) ];
			$timezone      = new \DateTimeZone( $timezone_name );
			$cases[]       = array(
				'mysql'    => ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( 'Y-m-d H:i:s' ),
				'timezone' => $timezone_name,
			);
		}

		while ( count( $cases ) < self::CASES + 8 ) {
			$timestamp     = $ctx->int( -2208988800, 4102444799 );
			$timezone_name = $timezones[ $ctx->int( 0, count( $timezones ) - 1 ) ];
			$timezone      = new \DateTimeZone( $timezone_name );
			$cases[]       = array(
				'mysql'    => ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( 'Y-m-d H:i:s' ),
				'timezone' => $timezone_name,
			);
		}

		$unique = array();
		foreach ( $cases as $case ) {
			$unique[ $case['timezone'] . '|' . $case['mysql'] ] = $case;
		}

		return array_values( $unique );
	}

	private static function offset_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array( -12.0, -9.5, -5.0, -3.5, -0.5, 0.0, 3.0, 5.5, 5.75, 8.75, 12.75, 14.0 );

		for ( $i = 0; $i < 10; ++$i ) {
			$quarters = $ctx->int( -48, 56 );
			$cases[]  = $quarters / 4;
		}

		return array_values( array_unique( $cases ) );
	}

	private static function human_delta_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			0,
			1,
			2,
			59,
			60,
			89,
			90,
			HOUR_IN_SECONDS - 1,
			HOUR_IN_SECONDS,
			DAY_IN_SECONDS - 1,
			DAY_IN_SECONDS,
			WEEK_IN_SECONDS - 1,
			WEEK_IN_SECONDS,
			MONTH_IN_SECONDS - 1,
			MONTH_IN_SECONDS,
			YEAR_IN_SECONDS - 1,
			YEAR_IN_SECONDS,
			3 * YEAR_IN_SECONDS,
		);

		for ( $i = 0; $i < 10; ++$i ) {
			$cases[] = $ctx->int( 0, 5 * YEAR_IN_SECONDS );
		}

		$cases = array_values( array_unique( $cases ) );
		sort( $cases, SORT_NUMERIC );
		return $cases;
	}

	private static function date_i18n_format( string $format ): string {
		if ( 'U' === $format ) {
			return 'Y-m-d H:i:s';
		}

		return str_replace( array( 'e', 'T' ), array( 'P', 'O' ), $format );
	}

	private static function date_option_format( string $format ): string {
		$format = preg_replace( '/[^A-Za-z0-9_ .:\/\\\\,\-]+/', '', $format );
		$format = str_replace( array( '<', '>' ), '', (string) $format );
		return '' === $format || 'U' === $format ? 'Y-m-d' : substr( $format, 0, 40 );
	}

	private static function time_option_format( string $format ): string {
		$format = preg_replace( '/[^A-Za-z0-9_ .:\/\\\\,\-]+/', '', $format );
		$format = str_replace( array( '<', '>' ), '', (string) $format );
		return '' === $format || 'U' === $format ? 'H:i:s' : substr( $format, 0, 40 );
	}

	private static function format_gmt_offset( $offset ): string {
		$offset  = (float) $offset;
		$sign    = $offset < 0 ? '-' : '+';
		$hours   = (int) $offset;
		$minutes = $offset - $hours;

		return sprintf( '%s%02d:%02d', $sign, abs( $hours ), abs( $minutes * 60 ) );
	}

	private static function iso8601_token_from_offset( $offset ): string {
		$minutes = (int) round( (float) $offset * 60 );
		$sign    = $minutes < 0 ? '-' : '+';
		$minutes = abs( $minutes );

		return sprintf( '%s%02d%02d', $sign, intdiv( $minutes, 60 ), $minutes % 60 );
	}

	private static function parse_iso8601_offset( string $token ) {
		if ( 'Z' === $token ) {
			return 0;
		}

		if ( 1 !== preg_match( '/\A([+-])(\d{2})(\d{2})\z/', $token, $matches ) ) {
			return null;
		}

		$seconds = ( (int) $matches[2] * HOUR_IN_SECONDS ) + ( (int) $matches[3] * MINUTE_IN_SECONDS );
		return '-' === $matches[1] ? -$seconds : $seconds;
	}

	private static function parse_human_time_diff_seconds( string $value ): ?int {
		if ( 1 !== preg_match( '/\A(\d+)\s+(second|minute|hour|day|week|month|year)s?\z/', $value, $matches ) ) {
			return null;
		}

		$unit_seconds = array(
			'second' => 1,
			'minute' => MINUTE_IN_SECONDS,
			'hour'   => HOUR_IN_SECONDS,
			'day'    => DAY_IN_SECONDS,
			'week'   => WEEK_IN_SECONDS,
			'month'  => MONTH_IN_SECONDS,
			'year'   => YEAR_IN_SECONDS,
		);

		return (int) $matches[1] * $unit_seconds[ $matches[2] ];
	}

	private static function with_option_filters( array $options, callable $callback ): void {
		$filters = array();

		foreach ( $options as $option => $value ) {
			$filter = static function () use ( $value ) {
				return $value;
			};
			\add_filter( "pre_option_{$option}", $filter, 10, 3 );
			$filters[] = array(
				'hook'     => "pre_option_{$option}",
				'callback' => $filter,
				'priority' => 10,
			);
		}

		try {
			$callback();
		} finally {
			for ( $i = count( $filters ) - 1; $i >= 0; --$i ) {
				\remove_filter( $filters[ $i ]['hook'], $filters[ $i ]['callback'], $filters[ $i ]['priority'] );
			}
		}
	}

	private static function snapshot_runtime(): array {
		$hooks = array(
			'pre_option_timezone_string',
			'pre_option_gmt_offset',
			'pre_option_date_format',
			'pre_option_time_format',
			'date_i18n',
			'wp_date',
			'human_time_diff',
		);

		$tracked_filters = array();
		foreach ( $hooks as $hook ) {
			$tracked_filters[ $hook ] = self::filter_fingerprint( $hook );
		}

		return array(
			'phpTimezone'    => date_default_timezone_get(),
			'wpLocale'       => $GLOBALS['wp_locale'] ?? null,
			'options'        => self::option_store_snapshot(),
			'trackedFilters' => $tracked_filters,
		);
	}

	private static function restore_runtime( array $snapshot ): void {
		if ( date_default_timezone_get() !== $snapshot['phpTimezone'] ) {
			date_default_timezone_set( $snapshot['phpTimezone'] );
		}

		if ( array_key_exists( 'wpLocale', $snapshot ) ) {
			$GLOBALS['wp_locale'] = $snapshot['wpLocale'];
		}

		self::restore_option_store( $snapshot['options'] );
	}

	private static function option_store_snapshot(): array {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			return $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return array();
	}

	private static function restore_option_store( array $options ): void {
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $options );
		}
	}

	private static function filter_fingerprint( string $hook ): array {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) || ! $GLOBALS['wp_filter'][ $hook ] instanceof \WP_Hook ) {
			return array();
		}

		$fingerprint = array();
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $priority => $callbacks ) {
			$fingerprint[ (string) $priority ] = array_keys( $callbacks );
		}

		return $fingerprint;
	}

	private static function sample( array &$samples, array $sample ): void {
		if ( count( $samples ) < 5 ) {
			$samples[] = $sample;
		}
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failures'] = array_slice( $failures, 0, self::FAILURE_LIMIT );
		return array() === $failures ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function throwable_row( \ComponentFuzz\FuzzContext $ctx, string $invariant, \Throwable $e ): array {
		return $ctx->fail(
			$invariant,
			array(
				'throwable' => array(
					'class'   => get_class( $e ),
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				),
			)
		);
	}

	private static function describe_value( $value ): array {
		if ( is_string( $value ) ) {
			return array(
				'type'   => 'string',
				'length' => strlen( $value ),
				'value'  => substr( $value, 0, 160 ),
			);
		}

		if ( is_scalar( $value ) || null === $value ) {
			return array(
				'type'  => gettype( $value ),
				'value' => $value,
			);
		}

		return array(
			'type'  => gettype( $value ),
			'value' => $value,
		);
	}
}
