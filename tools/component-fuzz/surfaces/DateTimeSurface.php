<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes deterministic no-DB WordPress date/time helpers and timezone-choice markup.
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
				self::check_timezone_choice_markup( $ctx, $timezones ),
				self::check_gmt_local_round_trips( $ctx, $timestamps, $timezones ),
				self::check_iso8601_offsets( $ctx, $offsets ),
				self::check_weekstartend_windows( $ctx, $timestamps ),
				self::check_human_time_diff( $ctx ),
				self::check_safe_format_option_filters( $ctx, $timestamps, $timezones, $formats ),
				self::check_current_datetime_timezone_override_and_iso8601( $ctx, $timestamps, $timezones, $offsets ),
				self::check_date_and_human_diff_filter_contracts( $ctx, $timestamps, $timezones, $formats ),
				self::check_named_timezone_dst_boundaries_and_iso8601_modes( $ctx, $timezones, $offsets ),
				self::check_wp_checkdate_contract( $ctx ),
				self::check_maybe_decline_date_locale_contract( $ctx->fork( 'decline-date' ) ),
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
				'current_datetime',
				'mysql2date',
				'get_gmt_from_date',
				'get_date_from_gmt',
				'iso8601_timezone_to_offset',
				'iso8601_to_datetime',
				'wp_timezone_string',
				'wp_timezone',
				'wp_timezone_override_offset',
				'wp_timezone_choice',
				'wp_checkdate',
				'get_weekstartend',
				'human_time_diff',
				'get_option',
				'get_locale',
				'add_filter',
				'has_filter',
				'remove_filter',
				'wp_maybe_decline_date',
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

	private static function check_timezone_choice_markup( \ComponentFuzz\FuzzContext $ctx, array $timezones ): array {
		$failures          = array();
		$samples           = array();
		$textdomain_events = array();
		$locale            = 'cf_' . preg_replace( '/[^A-Za-z0-9_]+/', '_', $ctx->identifier( 5, 12 ) );
		$hostile           = 'UTC+5.75"><script data-cf="' . $ctx->identifier( 4, 8 ) . '">&/';

		$pre_load_filter = static function ( $loaded, string $domain, string $mofile, ?string $filter_locale = null ) use ( &$textdomain_events ) {
			if ( 'continents-cities' !== $domain ) {
				return $loaded;
			}

			$textdomain_events[] = array(
				'domain' => $domain,
				'mofile' => $mofile,
				'locale' => $filter_locale,
			);

			return true;
		};

		\add_filter( 'pre_load_textdomain', $pre_load_filter, 10, 4 );

		try {
			$empty_html = \wp_timezone_choice( '', $locale );
			$empty      = self::timezone_choice_summary( $empty_html );
			self::sample( $samples, array( 'case' => 'empty', 'selected' => $empty['selectedValues'], 'preview' => self::describe_value( $empty_html ) ) );
			self::collect_failure(
				$failures,
				array( '' ) === $empty['selectedValues']
					&& 1 === count( $empty['selectedValues'] )
					&& 1 === self::timezone_choice_value_count( $empty, '' )
					&& self::timezone_choice_structure_ok( $empty ),
				'wp_timezone_choice empty selection emits one selected placeholder and balanced option groups',
				array( 'summary' => $empty )
			);

			$utc_html = \wp_timezone_choice( 'UTC', $locale );
			$utc      = self::timezone_choice_summary( $utc_html );
			self::collect_failure(
				$failures,
				array( 'UTC' ) === $utc['selectedValues']
					&& 1 === self::timezone_choice_value_count( $utc, 'UTC' )
					&& str_contains( $utc_html, '<optgroup label="UTC" dir="auto">' )
					&& self::timezone_choice_structure_ok( $utc ),
				'wp_timezone_choice selects the UTC option inside the UTC group',
				array( 'summary' => $utc )
			);

			foreach ( self::timezone_choice_named_zone_cases( $ctx->fork( 'named-zones' ), $timezones ) as $zone ) {
				$html    = \wp_timezone_choice( $zone, $locale );
				$summary = self::timezone_choice_summary( $html );
				self::sample( $samples, array( 'case' => 'named', 'zone' => $zone, 'selected' => $summary['selectedValues'] ) );
				self::collect_failure(
					$failures,
					array( $zone ) === $summary['selectedValues']
						&& 1 === self::timezone_choice_value_count( $summary, $zone )
						&& self::timezone_choice_structure_ok( $summary ),
					'wp_timezone_choice named timezone values round-trip exactly',
					array(
						'zone'    => $zone,
						'summary' => $summary,
					)
				);
			}

			$bc_zone = self::timezone_choice_bc_only_zone( $ctx->fork( 'bc-zone' ) );
			if ( null !== $bc_zone ) {
				$bc_html = \wp_timezone_choice( $bc_zone, $locale );
				$bc      = self::timezone_choice_summary( $bc_html );
				self::collect_failure(
					$failures,
					array( $bc_zone ) === $bc['selectedValues']
						&& 1 === self::timezone_choice_value_count( $bc, $bc_zone )
						&& isset( $bc['options'][0] )
						&& $bc_zone === ( $bc['options'][0]['value'] ?? null )
						&& true === ( $bc['options'][0]['selected'] ?? false )
						&& self::timezone_choice_structure_ok( $bc ),
					'wp_timezone_choice emits selected top option for deprecated but valid BC timezone IDs',
					array(
						'zone'    => $bc_zone,
						'summary' => $bc,
					)
				);
			}

			foreach ( self::timezone_choice_manual_offset_cases( $ctx->fork( 'manual-offsets' ) ) as $offset_value => $expected_label ) {
				$html    = \wp_timezone_choice( $offset_value, $locale );
				$summary = self::timezone_choice_summary( $html );
				$option  = self::timezone_choice_option_by_value( $summary, $offset_value );
				self::sample( $samples, array( 'case' => 'manual', 'offset' => $offset_value, 'selected' => $summary['selectedValues'] ) );
				self::collect_failure(
					$failures,
					array( $offset_value ) === $summary['selectedValues']
						&& is_array( $option )
						&& $expected_label === ( $option['label'] ?? null )
						&& str_contains( $html, '<optgroup label="Manual Offsets" dir="auto">' )
						&& self::timezone_choice_structure_ok( $summary ),
					'wp_timezone_choice manual UTC offsets keep decimal values while labels normalize quarter-hour clocks',
					array(
						'offset'        => $offset_value,
						'expectedLabel' => $expected_label,
						'option'        => $option,
						'summary'       => $summary,
					)
				);
			}

			$hostile_html = \wp_timezone_choice( $hostile, $locale );
			$hostile_sum  = self::timezone_choice_summary( $hostile_html );
			self::collect_failure(
				$failures,
				array() === $hostile_sum['selectedValues']
					&& ! str_contains( $hostile_html, $hostile )
					&& ! str_contains( strtolower( $hostile_html ), '<script' )
					&& self::timezone_choice_structure_ok( $hostile_sum ),
				'wp_timezone_choice ignores hostile non-zone selected strings without raw payload leakage',
				array(
					'hostile' => $hostile,
					'summary' => $hostile_sum,
					'preview' => self::describe_value( $hostile_html ),
				)
			);

			$locale_zone       = self::timezone_choice_named_zone_cases( $ctx->fork( 'locale-zone' ), $timezones )[0] ?? 'Europe/Madrid';
			$default_locale    = self::timezone_choice_summary( \wp_timezone_choice( $locale_zone, null ) );
			$explicit_locale   = self::timezone_choice_summary( \wp_timezone_choice( $locale_zone, 'en_US' ) );
			$generated_locale  = self::timezone_choice_summary( \wp_timezone_choice( $locale_zone, $locale . '_ALT' ) );
			$default_values    = self::timezone_choice_values( $default_locale );
			$explicit_values   = self::timezone_choice_values( $explicit_locale );
			$generated_values  = self::timezone_choice_values( $generated_locale );
			self::collect_failure(
				$failures,
				$default_values === $explicit_values
					&& $default_values === $generated_values
					&& array( $locale_zone ) === $default_locale['selectedValues']
					&& $default_locale['selectedValues'] === $explicit_locale['selectedValues']
					&& $default_locale['selectedValues'] === $generated_locale['selectedValues'],
				'wp_timezone_choice option values and selected state are locale-invariant',
				array(
					'zone'             => $locale_zone,
					'defaultSelected'  => $default_locale['selectedValues'],
					'explicitSelected' => $explicit_locale['selectedValues'],
					'generatedSelected' => $generated_locale['selectedValues'],
					'valueCounts'      => array(
						'default'   => count( $default_values ),
						'explicit'  => count( $explicit_values ),
						'generated' => count( $generated_values ),
					),
				)
			);
		} finally {
			\remove_filter( 'pre_load_textdomain', $pre_load_filter, 10 );
		}

		self::collect_failure(
			$failures,
			array() !== $textdomain_events
				&& false === \has_filter( 'pre_load_textdomain', $pre_load_filter ),
			'wp_timezone_choice textdomain pre-load filter is scoped and removed',
			array(
				'events'    => array_slice( $textdomain_events, 0, 6 ),
				'hasFilter' => \has_filter( 'pre_load_textdomain', $pre_load_filter ),
			)
		);

		return self::result(
			$ctx,
			'date-time.timezone-choice-markup-and-locale-contracts',
			$failures,
			array(
				'samples'          => $samples,
				'textdomainEvents' => array_slice( $textdomain_events, 0, 6 ),
				'failures'         => array_slice( $failures, 0, self::FAILURE_LIMIT ),
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

	private static function check_current_datetime_timezone_override_and_iso8601( \ComponentFuzz\FuzzContext $ctx, array $timestamps, array $timezones, array $offsets ): array {
		$failures = array();
		$samples  = array();
		$cases    = 0;

		foreach ( array_slice( $timezones, 0, min( 8, count( $timezones ) ) ) as $index => $timezone_name ) {
			self::with_option_filters(
				array(
					'timezone_string' => $timezone_name,
					'gmt_offset'      => $offsets[ $index % count( $offsets ) ],
				),
				static function () use ( $timezone_name, $timestamps, $index, &$failures, &$samples, &$cases ): void {
					$zone            = new \DateTimeZone( $timezone_name );
					$before          = time();
					$current         = \current_datetime();
					$after           = time();
					$override        = \wp_timezone_override_offset();
					$expected_offset = round( ( new \DateTimeImmutable( 'now', $zone ) )->getOffset() / HOUR_IN_SECONDS, 2 );
					$timestamp       = $timestamps[ $index % count( $timestamps ) ];
					$offset_token    = self::iso8601_token_from_offset( $expected_offset );
					$iso             = gmdate( 'Ymd\TH:i:s', $timestamp ) . $offset_token;
					$expected_gmt    = ( new \DateTimeImmutable( $iso ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
					$expected_user   = ( new \DateTimeImmutable( $iso ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
					$actual_gmt      = \iso8601_to_datetime( $iso, 'gmt' );
					$actual_user     = \iso8601_to_datetime( $iso, 'user' );
					$actual_invalid  = \iso8601_to_datetime( $iso, 'site' );
					++$cases;

					self::sample(
						$samples,
						array(
							'timezone'       => $timezone_name,
							'override'       => $override,
							'expectedOffset' => $expected_offset,
							'iso'            => $iso,
							'actualUser'     => $actual_user,
							'actualGmt'      => $actual_gmt,
						)
					);

					if (
						! ( $current instanceof \DateTimeImmutable )
						|| $current->getTimezone()->getName() !== $timezone_name
						|| $current->getTimestamp() < $before
						|| $current->getTimestamp() > $after
						|| ! is_float( $override )
						|| abs( $override - $expected_offset ) > 0.01
						|| $actual_gmt !== $expected_gmt
						|| $actual_user !== $expected_user
						|| false !== $actual_invalid
					) {
						$failures[] = array(
							'timezone'       => $timezone_name,
							'currentClass'   => is_object( $current ) ? get_class( $current ) : get_debug_type( $current ),
							'currentZone'    => $current instanceof \DateTimeImmutable ? $current->getTimezone()->getName() : null,
							'currentWindow'  => array( $before, $after, $current instanceof \DateTimeImmutable ? $current->getTimestamp() : null ),
							'override'       => self::describe_value( $override ),
							'expectedOffset' => $expected_offset,
							'iso'            => $iso,
							'expectedGmt'    => $expected_gmt,
							'actualGmt'      => self::describe_value( $actual_gmt ),
							'expectedUser'   => $expected_user,
							'actualUser'     => self::describe_value( $actual_user ),
							'invalidMode'    => self::describe_value( $actual_invalid ),
						);
					}
				}
			);
		}

		self::with_option_filters(
			array(
				'timezone_string' => '',
				'gmt_offset'      => $offsets[0] ?? 0,
			),
			static function () use ( &$failures ): void {
				$override = \wp_timezone_override_offset();
				if ( false !== $override ) {
					$failures[] = array(
						'case'     => 'override without timezone_string',
						'expected' => false,
						'actual'   => self::describe_value( $override ),
					);
				}
			}
		);

		self::collect_invalid_iso8601_case( $failures );

		return self::result(
			$ctx,
			'date-time.current-datetime-timezone-override-and-iso8601',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_date_and_human_diff_filter_contracts( \ComponentFuzz\FuzzContext $ctx, array $timestamps, array $timezones, array $formats ): array {
		$failures       = array();
		$timestamp      = $timestamps[ $ctx->int( 0, count( $timestamps ) - 1 ) ];
		$timezone_name  = $timezones[ $ctx->int( 0, count( $timezones ) - 1 ) ];
		$timezone       = new \DateTimeZone( $timezone_name );
		$format         = self::date_i18n_format( $formats[ $ctx->int( 0, count( $formats ) - 1 ) ] );
		$human_delta    = $ctx->int( MINUTE_IN_SECONDS, 3 * DAY_IN_SECONDS );
		$wp_date_seen   = array();
		$date_i18n_seen = array();
		$human_seen     = array();
		$wp_date_filter = static function ( string $date, string $seen_format, int $seen_timestamp, \DateTimeZone $seen_timezone ) use ( &$wp_date_seen ): string {
			$wp_date_seen[] = array(
				'date'      => $date,
				'format'    => $seen_format,
				'timestamp' => $seen_timestamp,
				'timezone'  => $seen_timezone->getName(),
			);

			return 'wp-filtered:' . $date;
		};
		$date_i18n_filter = static function ( string $date, string $seen_format, int $seen_timestamp, bool $seen_gmt ) use ( &$date_i18n_seen ): string {
			$date_i18n_seen[] = array(
				'date'      => $date,
				'format'    => $seen_format,
				'timestamp' => $seen_timestamp,
				'gmt'       => $seen_gmt,
			);

			return 'i18n-filtered:' . $date;
		};
		$human_filter = static function ( string $since, int $diff, int $from, int $to ) use ( &$human_seen ): string {
			$human_seen[] = compact( 'since', 'diff', 'from', 'to' );
			return 'human-filtered:' . $since . ':' . $diff;
		};

		\add_filter( 'wp_date', $wp_date_filter, 10, 4 );
		try {
			$wp_date = \wp_date( $format, $timestamp, $timezone );
		} finally {
			\remove_filter( 'wp_date', $wp_date_filter, 10 );
		}

		self::with_option_filters(
			array(
				'timezone_string' => $timezone_name,
				'gmt_offset'      => 0,
			),
			static function () use ( $date_i18n_filter, $human_filter, $format, $timestamp, $human_delta, &$date_i18n_seen, &$human_seen, &$date_i18n, &$human ): void {
				\add_filter( 'date_i18n', $date_i18n_filter, 10, 4 );
				try {
					$date_i18n = \date_i18n( $format, $timestamp, false );
				} finally {
					\remove_filter( 'date_i18n', $date_i18n_filter, 10 );
				}

				\add_filter( 'human_time_diff', $human_filter, 10, 4 );
				try {
					$human = \human_time_diff( $timestamp, $timestamp + $human_delta );
				} finally {
					\remove_filter( 'human_time_diff', $human_filter, 10 );
				}
			}
		);

		self::collect_failure(
			$failures,
			1 === count( $wp_date_seen )
				&& str_starts_with( $wp_date, 'wp-filtered:' )
				&& $format === $wp_date_seen[0]['format']
				&& (int) $timestamp === (int) $wp_date_seen[0]['timestamp']
				&& $timezone_name === $wp_date_seen[0]['timezone'],
			'wp_date filter receives formatted date, format, timestamp, and timezone object',
			array(
				'output' => self::describe_value( $wp_date ),
				'seen'   => $wp_date_seen,
			)
		);
		self::collect_failure(
			$failures,
			1 === count( $date_i18n_seen )
				&& str_starts_with( $date_i18n, 'i18n-filtered:' )
				&& $format === $date_i18n_seen[0]['format']
				&& (int) $timestamp === (int) $date_i18n_seen[0]['timestamp']
				&& false === $date_i18n_seen[0]['gmt'],
			'date_i18n filter receives explicit timestamp and GMT flag without leaking outside the call',
			array(
				'output' => self::describe_value( $date_i18n ),
				'seen'   => $date_i18n_seen,
			)
		);
		self::collect_failure(
			$failures,
			1 === count( $human_seen )
				&& str_starts_with( $human, 'human-filtered:' )
				&& $human_delta === $human_seen[0]['diff']
				&& $timestamp === $human_seen[0]['from']
				&& $timestamp + $human_delta === $human_seen[0]['to']
				&& null !== self::parse_human_time_diff_seconds( $human_seen[0]['since'] ),
			'human_time_diff filter receives normalized text, absolute diff, and original endpoints',
			array(
				'output' => self::describe_value( $human ),
				'seen'   => $human_seen,
			)
		);

		return self::result(
			$ctx,
			'date-time.date-and-human-diff-filter-argument-locality',
			$failures,
			array(
				'timestamp' => $timestamp,
				'timezone'  => $timezone_name,
				'format'    => $format,
				'failures'  => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_named_timezone_dst_boundaries_and_iso8601_modes( \ComponentFuzz\FuzzContext $ctx, array $timezones, array $offsets ): array {
		$boundary_cases = self::dst_boundary_cases( $ctx->fork( 'dst-boundaries' ), $timezones );
		$failures       = array();
		$samples        = array();
		$cases          = 0;
		$utc            = new \DateTimeZone( 'UTC' );

		foreach ( $boundary_cases as $index => $case ) {
			$timezone_name = $case['timezone'];
			$decoy_offset  = $offsets[ $index % count( $offsets ) ];

			self::with_option_filters(
				array(
					'timezone_string' => $timezone_name,
					'gmt_offset'      => $decoy_offset,
				),
				static function () use ( $case, $timezone_name, $decoy_offset, $utc, &$failures, &$samples, &$cases ): void {
					$wp_timezone_name = \wp_timezone_string();
					$timezone         = \wp_timezone();
					$instant          = ( new \DateTimeImmutable( '@' . $case['timestamp'] ) )->setTimezone( $utc );
					$local            = $instant->setTimezone( $timezone );
					$explicit_iso     = $local->format( 'Ymd\TH:i:sO' );
					$local_iso        = $local->format( 'Ymd\TH:i:s' );
					$utc_iso          = $instant->format( 'Ymd\TH:i:s\Z' );
					$expected_user    = $local->format( 'Y-m-d H:i:s' );
					$expected_gmt     = $instant->format( 'Y-m-d H:i:s' );
					$local_assumed    = new \DateTimeImmutable( $local_iso, $timezone );
					$actual_default   = \iso8601_to_datetime( $explicit_iso );
					$actual_user      = \iso8601_to_datetime( $explicit_iso, 'user' );
					$actual_gmt       = \iso8601_to_datetime( $explicit_iso, 'gmt' );
					$actual_gmt_upper = \iso8601_to_datetime( $explicit_iso, 'GMT' );
					$actual_z_user    = \iso8601_to_datetime( $utc_iso, 'user' );
					$actual_local     = \iso8601_to_datetime( $local_iso, 'user' );
					$actual_local_gmt = \iso8601_to_datetime( $local_iso, 'gmt' );
					$actual_from_gmt  = \get_date_from_gmt( $expected_gmt );
					++$cases;

					self::sample(
						$samples,
						array(
							'timezone'     => $timezone_name,
							'transition'   => $case['transition'],
							'delta'        => $case['delta'],
							'decoyOffset'  => $decoy_offset,
							'explicitIso'  => $explicit_iso,
							'localIso'     => $local_iso,
							'expectedUser' => $expected_user,
							'expectedGmt'  => $expected_gmt,
						)
					);

					if (
						$wp_timezone_name !== $timezone_name
						|| $timezone->getName() !== $timezone_name
						|| $actual_default !== $expected_user
						|| $actual_user !== $expected_user
						|| $actual_z_user !== $expected_user
						|| $actual_gmt !== $expected_gmt
						|| $actual_gmt_upper !== $expected_gmt
						|| $actual_local !== $local_assumed->format( 'Y-m-d H:i:s' )
						|| $actual_local_gmt !== $local_assumed->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
						|| $actual_from_gmt !== $expected_user
					) {
						$failures[] = array(
							'api'             => 'iso8601_to_datetime/get_date_from_gmt/wp_timezone',
							'timezone'        => $timezone_name,
							'wpTimezoneString' => $wp_timezone_name,
							'wpTimezoneName'   => $timezone->getName(),
							'transition'      => $case['transition'],
							'delta'           => $case['delta'],
							'decoyOffset'     => $decoy_offset,
							'explicitIso'     => $explicit_iso,
							'localIso'        => $local_iso,
							'utcIso'          => $utc_iso,
							'expectedUser'    => $expected_user,
							'actualDefault'   => self::describe_value( $actual_default ),
							'actualUser'      => self::describe_value( $actual_user ),
							'actualZUser'     => self::describe_value( $actual_z_user ),
							'expectedGmt'     => $expected_gmt,
							'actualGmt'       => self::describe_value( $actual_gmt ),
							'actualGmtUpper'  => self::describe_value( $actual_gmt_upper ),
							'expectedLocal'   => $local_assumed->format( 'Y-m-d H:i:s' ),
							'actualLocal'     => self::describe_value( $actual_local ),
							'expectedLocalGmt' => $local_assumed->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
							'actualLocalGmt'  => self::describe_value( $actual_local_gmt ),
							'actualFromGmt'   => self::describe_value( $actual_from_gmt ),
						);
					}
				}
			);
		}

		return self::result(
			$ctx,
			'date-time.named-timezone-dst-boundary-iso8601-modes',
			$failures,
			array(
				'cases'    => $cases,
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_wp_checkdate_contract( \ComponentFuzz\FuzzContext $ctx ): array {
		$date_cases = self::checkdate_cases( $ctx->fork( 'wp-checkdate' ) );
		$failures   = array();
		$samples    = array();
		$seen       = array();
		$filter     = static function ( bool $is_valid_date, string $source_date ) use ( &$seen ): bool {
			$seen[] = array(
				'isValidDate' => $is_valid_date,
				'sourceDate'  => $source_date,
			);

			return ! $is_valid_date;
		};

		foreach ( $date_cases as $case ) {
			$expected = self::expected_wp_checkdate( $case['month'], $case['day'], $case['year'] );
			$actual   = \wp_checkdate( $case['month'], $case['day'], $case['year'], $case['source'] );

			self::sample(
				$samples,
				array(
					'month'    => $case['month'],
					'day'      => $case['day'],
					'year'     => $case['year'],
					'source'   => $case['source'],
					'expected' => $expected,
					'actual'   => $actual,
				)
			);

			if ( $actual !== $expected ) {
				$failures[] = array(
					'api'      => 'wp_checkdate',
					'case'     => 'unfiltered validity',
					'month'    => $case['month'],
					'day'      => $case['day'],
					'year'     => $case['year'],
					'source'   => $case['source'],
					'expected' => $expected,
					'actual'   => self::describe_value( $actual ),
				);
			}
		}

		\add_filter( 'wp_checkdate', $filter, 10, 2 );
		try {
			foreach ( $date_cases as $index => $case ) {
				$expected_default = self::expected_wp_checkdate( $case['month'], $case['day'], $case['year'] );
				$actual_filtered  = \wp_checkdate( $case['month'], $case['day'], $case['year'], $case['source'] );
				$seen_case        = $seen[ $index ] ?? null;

				if (
					$actual_filtered !== ( ! $expected_default )
					|| ! is_array( $seen_case )
					|| $seen_case['isValidDate'] !== $expected_default
					|| $seen_case['sourceDate'] !== $case['source']
				) {
					$failures[] = array(
						'api'             => 'wp_checkdate',
						'case'            => 'filter payload and override',
						'month'           => $case['month'],
						'day'             => $case['day'],
						'year'            => $case['year'],
						'source'          => $case['source'],
						'expectedDefault' => $expected_default,
						'actualFiltered'  => self::describe_value( $actual_filtered ),
						'seen'            => self::describe_value( $seen_case ),
					);
				}
			}
		} finally {
			\remove_filter( 'wp_checkdate', $filter, 10 );
		}

		return self::result(
			$ctx,
			'date-time.wp-checkdate-generated-validity-and-filter-contract',
			$failures,
			array(
				'cases'    => count( $date_cases ),
				'samples'  => $samples,
				'failures' => array_slice( $failures, 0, self::FAILURE_LIMIT ),
			)
		);
	}

	private static function check_maybe_decline_date_locale_contract( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$samples         = array();
		$translation_log = array();
		$original_locale = $GLOBALS['locale'] ?? null;
		$locale_existed  = array_key_exists( 'locale', $GLOBALS );
		$original_wp_locale = $GLOBALS['wp_locale'] ?? null;

		if ( ! is_object( $original_wp_locale ) ) {
			return $ctx->skip(
				'date-time.maybe-decline-date-locale-rules',
				'Global WP_Locale object is unavailable.'
			);
		}

		$decline_filter = static function ( string $translation, string $text, string $context ) use ( &$translation_log ): string {
			$translation_log[] = array(
				'translation' => $translation,
				'text'        => $text,
				'context'     => $context,
			);

			if ( 'decline months names: on or off' === $context ) {
				return 'on';
			}

			return $translation;
		};
		$off_filter     = static function ( string $translation, string $text, string $context ): string {
			unset( $text );

			return 'decline months names: on or off' === $context ? 'off' : $translation;
		};

		$months = self::declension_month_names();
		$cases  = array(
			array(
				'name'     => 'day-month-explicit',
				'locale'   => 'component_decline',
				'format'   => 'j F Y',
				'date'     => '21 Foxtrot 2026',
				'expected' => '21 foxtrot 2026',
			),
			array(
				'name'     => 'day-dot-month-explicit',
				'locale'   => 'component_decline',
				'format'   => 'j. F',
				'date'     => '1. Delta',
				'expected' => '1. delta',
			),
			array(
				'name'     => 'month-day-ordinal-explicit',
				'locale'   => 'component_decline',
				'format'   => 'F jS Y',
				'date'     => 'Alpha 1st 2026',
				'expected' => '1 alpha 2026',
			),
			array(
				'name'     => 'month-day-range-explicit',
				'locale'   => 'component_decline',
				'format'   => 'F j-j Y',
				'date'     => 'Hotel 3-5 2026',
				'expected' => '3-5 hotel 2026',
			),
			array(
				'name'     => 'format-without-day-does-not-decline',
				'locale'   => 'component_decline',
				'format'   => 'F Y',
				'date'     => 'India 2026',
				'expected' => 'India 2026',
			),
			array(
				'name'     => 'guess-day-month',
				'locale'   => 'component_decline',
				'format'   => '',
				'date'     => '5 Bravo',
				'expected' => '5 bravo',
			),
			array(
				'name'     => 'guess-month-day',
				'locale'   => 'component_decline',
				'format'   => '',
				'date'     => 'Charlie 12th',
				'expected' => '12 charlie',
			),
			array(
				'name'     => 'catalan-apostrophe',
				'locale'   => 'ca',
				'format'   => 'j F',
				'date'     => '1 de abril',
				'expected' => "1 d'abril",
				'months'   => self::catalan_month_names(),
			),
		);

		$mutated_locale = clone $original_wp_locale;
		$GLOBALS['wp_locale'] = $mutated_locale;

		\add_filter( 'gettext_with_context', $decline_filter, 10, 3 );
		try {
			foreach ( $cases as $case ) {
				$case_months                         = $case['months'] ?? $months;
				$GLOBALS['locale']                   = $case['locale'];
				$GLOBALS['wp_locale']->month          = $case_months['month'];
				$GLOBALS['wp_locale']->month_genitive = $case_months['month_genitive'];

				$actual = \wp_maybe_decline_date( $case['date'], $case['format'] );

				self::sample(
					$samples,
					array(
						'name'     => $case['name'],
						'locale'   => \get_locale(),
						'format'   => $case['format'],
						'date'     => $case['date'],
						'expected' => $case['expected'],
						'actual'   => $actual,
					)
				);

				self::collect_failure(
					$failures,
					$case['expected'] === $actual,
					"wp_maybe_decline_date applies locale month rules for {$case['name']}",
					array(
						'case'     => $case,
						'actual'   => self::describe_value( $actual ),
						'locale'   => \get_locale(),
						'month'    => $GLOBALS['wp_locale']->month,
						'genitive' => $GLOBALS['wp_locale']->month_genitive,
					)
				);
			}
		} finally {
			\remove_filter( 'gettext_with_context', $decline_filter, 10 );
		}

		$GLOBALS['locale']                   = 'component_decline';
		$GLOBALS['wp_locale']->month          = $months['month'];
		$GLOBALS['wp_locale']->month_genitive = $months['month_genitive'];
		\add_filter( 'gettext_with_context', $off_filter, 10, 3 );
		try {
			$off_actual = \wp_maybe_decline_date( '7 Golf 2026', 'j F Y' );
		} finally {
			\remove_filter( 'gettext_with_context', $off_filter, 10 );
			$GLOBALS['wp_locale'] = $original_wp_locale;
			if ( $locale_existed ) {
				$GLOBALS['locale'] = $original_locale;
			} else {
				unset( $GLOBALS['locale'] );
			}
		}

		self::collect_failure(
			$failures,
			'7 Golf 2026' === $off_actual,
			'wp_maybe_decline_date leaves dates unchanged when declension translation is off',
			array( 'actual' => self::describe_value( $off_actual ) )
		);
		self::collect_failure(
			$failures,
			array() !== $translation_log
				&& false === \has_filter( 'gettext_with_context', $decline_filter )
				&& false === \has_filter( 'gettext_with_context', $off_filter )
				&& ( $GLOBALS['wp_locale'] ?? null ) === $original_wp_locale
				&& (
					$locale_existed
						? ( $GLOBALS['locale'] ?? null ) === $original_locale
						: ! array_key_exists( 'locale', $GLOBALS )
				),
			'wp_maybe_decline_date translation filter and locale globals are restored',
			array(
				'translationEvents' => array_slice( $translation_log, 0, 6 ),
				'declineFilter'     => \has_filter( 'gettext_with_context', $decline_filter ),
				'offFilter'         => \has_filter( 'gettext_with_context', $off_filter ),
				'localeExisted'     => $locale_existed,
				'currentLocale'     => $GLOBALS['locale'] ?? null,
			)
		);

		return self::result(
			$ctx,
			'date-time.maybe-decline-date-locale-rules',
			$failures,
			array(
				'cases'             => count( $cases ) + 1,
				'samples'           => $samples,
				'translationEvents' => array_slice( $translation_log, 0, 6 ),
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

		$l10n_fingerprint = self::l10n_state_fingerprint();
		if ( $l10n_fingerprint !== ( $snapshot['l10nFingerprint'] ?? array() ) ) {
			$failures[] = array(
				'global' => 'l10n',
				'before' => $snapshot['l10nFingerprint'] ?? array(),
				'after'  => $l10n_fingerprint,
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

	private static function declension_month_names(): array {
		$months = array(
			'Alpha',
			'Bravo',
			'Charlie',
			'Delta',
			'Echo',
			'Foxtrot',
			'Golf',
			'Hotel',
			'India',
			'Juliet',
			'Kilo',
			'Lima',
		);

		return array(
			'month'          => $months,
			'month_genitive' => array_map( 'strtolower', $months ),
		);
	}

	private static function catalan_month_names(): array {
		$months = array(
			'gener',
			'febrer',
			'marc',
			'abril',
			'maig',
			'juny',
			'juliol',
			'agost',
			'setembre',
			'octubre',
			'novembre',
			'desembre',
		);

		return array(
			'month'          => $months,
			'month_genitive' => $months,
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

	private static function dst_boundary_cases( \ComponentFuzz\FuzzContext $ctx, array $timezones ): array {
		$preferred = array(
			'Europe/Madrid',
			'Europe/London',
			'America/New_York',
			'America/St_Johns',
			'Australia/Lord_Howe',
			'Pacific/Auckland',
			'Pacific/Chatham',
		);
		$names     = array_values( array_unique( array_merge( $preferred, $timezones ) ) );
		$deltas    = array( -7200, -3600, -1, 0, 1, 1800, 3600, 7200 );
		$cases     = array();
		$start     = gmmktime( 0, 0, 0, 1, 1, 2019 );
		$end       = gmmktime( 23, 59, 59, 12, 31, 2026 );

		foreach ( $names as $timezone_name ) {
			try {
				$timezone = new \DateTimeZone( $timezone_name );
			} catch ( \Throwable $e ) {
				continue;
			}

			$transitions = $timezone->getTransitions( $start, $end );
			if ( ! is_array( $transitions ) || count( $transitions ) < 2 ) {
				continue;
			}

			$offset_changes = array();
			$previous       = null;
			foreach ( $transitions as $transition ) {
				if ( null !== $previous && (int) $previous['offset'] !== (int) $transition['offset'] ) {
					$offset_changes[] = $transition;
				}
				$previous = $transition;
			}

			if ( array() === $offset_changes ) {
				continue;
			}

			$transition = $offset_changes[ $ctx->int( 0, count( $offset_changes ) - 1 ) ];
			foreach ( $deltas as $delta ) {
				$cases[] = array(
					'timezone'   => $timezone_name,
					'transition' => (int) $transition['ts'],
					'delta'      => $delta,
					'timestamp'  => (int) $transition['ts'] + $delta,
				);

				if ( count( $cases ) >= self::CASES + 14 ) {
					break 2;
				}
			}
		}

		return $cases;
	}

	private static function checkdate_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'month'  => 1,
				'day'    => 1,
				'year'   => 1,
				'source' => '1-1-1',
			),
			array(
				'month'  => '02',
				'day'    => '29',
				'year'   => '2024',
				'source' => '02/29/2024',
			),
			array(
				'month'  => '2',
				'day'    => '29',
				'year'   => '2023',
				'source' => '2/29/2023',
			),
			array(
				'month'  => 0,
				'day'    => 10,
				'year'   => 2024,
				'source' => '0/10/2024',
			),
			array(
				'month'  => 13,
				'day'    => 1,
				'year'   => 2024,
				'source' => '13/1/2024',
			),
			array(
				'month'  => 12,
				'day'    => 32,
				'year'   => 2024,
				'source' => '12/32/2024',
			),
			array(
				'month'  => 1,
				'day'    => 1,
				'year'   => 0,
				'source' => '1/1/0',
			),
			array(
				'month'  => 1,
				'day'    => 1,
				'year'   => 32768,
				'source' => '1/1/32768',
			),
			array(
				'month'  => 'month',
				'day'    => '1',
				'year'   => '2024',
				'source' => 'month/1/2024',
			),
			array(
				'month'  => '2.9',
				'day'    => '29.1',
				'year'   => '2024.8',
				'source' => '2.9/29.1/2024.8',
			),
		);

		while ( count( $cases ) < self::CASES + 8 ) {
			$month = $ctx->int( -2, 15 );
			$day   = $ctx->int( -2, 35 );
			$year  = $ctx->choice(
				array(
					$ctx->int( -2, 10 ),
					$ctx->int( 1890, 2050 ),
					$ctx->int( 32760, 32775 ),
				)
			);

			if ( $ctx->bool() ) {
				$month = (string) $month;
			}

			if ( $ctx->bool() ) {
				$day = (string) $day;
			}

			if ( $ctx->bool() ) {
				$year = (string) $year;
			}

			$cases[] = array(
				'month'  => $month,
				'day'    => $day,
				'year'   => $year,
				'source' => "{$month}/{$day}/{$year}",
			);
		}

		$unique = array();
		foreach ( $cases as $case ) {
			$unique[ $case['source'] . '|' . gettype( $case['month'] ) . '|' . gettype( $case['day'] ) . '|' . gettype( $case['year'] ) ] = $case;
		}

		return array_values( $unique );
	}

	private static function expected_wp_checkdate( $month, $day, $year ): bool {
		if ( ! is_numeric( $month ) || ! is_numeric( $day ) || ! is_numeric( $year ) ) {
			return false;
		}

		return checkdate( (int) $month, (int) $day, (int) $year );
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

	private static function collect_invalid_iso8601_case( array &$failures ): void {
		$invalid_user = \iso8601_to_datetime( 'not-a-date', 'user' );
		$invalid_gmt  = \iso8601_to_datetime( '2026-13-40T25:61:61+0000', 'gmt' );

		if ( false !== $invalid_user || false !== $invalid_gmt ) {
			$failures[] = array(
				'api'         => 'iso8601_to_datetime',
				'case'        => 'invalid date strings',
				'invalidUser' => self::describe_value( $invalid_user ),
				'invalidGmt'  => self::describe_value( $invalid_gmt ),
			);
		}
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

	private static function collect_failure( array &$failures, bool $condition, string $message, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'details' => $details,
		);
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

	private static function timezone_choice_named_zone_cases( \ComponentFuzz\FuzzContext $ctx, array $timezones ): array {
		$available = array_flip( \DateTimeZone::listIdentifiers() );
		$preferred = array(
			'Europe/Madrid',
			'America/St_Johns',
			'America/Argentina/Buenos_Aires',
			'Asia/Kathmandu',
			'Australia/Lord_Howe',
			'Pacific/Chatham',
		);
		$zones     = array();

		foreach ( array_merge( $preferred, $timezones ) as $zone ) {
			if ( 'UTC' !== $zone && isset( $available[ $zone ] ) ) {
				$zones[] = $zone;
			}
		}

		$zones = array_values( array_unique( $zones ) );
		while ( count( $zones ) < 5 && array() !== $available ) {
			$candidate = array_keys( $available )[ $ctx->int( 0, count( $available ) - 1 ) ];
			if ( 'UTC' !== $candidate ) {
				$zones[] = $candidate;
				$zones   = array_values( array_unique( $zones ) );
			}
		}

		return array_slice( $zones, 0, 5 );
	}

	private static function timezone_choice_bc_only_zone( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$current = \DateTimeZone::listIdentifiers();
		$all     = \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC );
		$bc_only = array_values( array_diff( $all, $current ) );

		if ( array() === $bc_only ) {
			return null;
		}

		sort( $bc_only, SORT_STRING );
		return $bc_only[ $ctx->int( 0, count( $bc_only ) - 1 ) ];
	}

	private static function timezone_choice_manual_offset_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$values = array( 'UTC-12', 'UTC-0.5', 'UTC+0', 'UTC+5.75', 'UTC+8.75', 'UTC+12.75', 'UTC+14' );
		$extra  = array( 'UTC-9.5', 'UTC-3.5', 'UTC+3.5', 'UTC+5.5', 'UTC+13.75' );
		$values[] = $extra[ $ctx->int( 0, count( $extra ) - 1 ) ];

		$cases = array();
		foreach ( array_values( array_unique( $values ) ) as $value ) {
			$cases[ $value ] = self::timezone_choice_offset_label( $value );
		}

		return $cases;
	}

	private static function timezone_choice_offset_label( string $value ): string {
		return 'UTC' . str_replace( array( '.25', '.5', '.75' ), array( ':15', ':30', ':45' ), substr( $value, 3 ) );
	}

	private static function timezone_choice_summary( string $html ): array {
		$options = self::timezone_choice_parse_options( $html );

		return array(
			'optionCount'     => count( $options ),
			'options'         => $options,
			'selectedValues'  => array_values(
				array_map(
					static fn ( array $option ): string => (string) ( $option['value'] ?? '' ),
					array_filter( $options, static fn ( array $option ): bool => true === ( $option['selected'] ?? false ) )
				)
			),
			'optgroupOpen'    => substr_count( $html, '<optgroup ' ),
			'optgroupClose'   => substr_count( $html, '</optgroup>' ),
			'hasUtcGroup'     => str_contains( $html, '<optgroup label="UTC" dir="auto">' ),
			'hasManualGroup'  => str_contains( $html, '<optgroup label="Manual Offsets" dir="auto">' ),
			'multiSelected'   => self::timezone_choice_has_multi_selected_option( $options ),
			'rawScriptLeaked' => str_contains( strtolower( $html ), '<script' ),
			'preview'         => self::describe_value( $html ),
		);
	}

	private static function timezone_choice_parse_options( string $html ): array {
		if ( 1 > preg_match_all( '/<option\b([^>]*)>(.*?)<\/option>/s', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$options = array();
		foreach ( $matches as $match ) {
			$attrs     = self::timezone_choice_parse_attrs( $match[1] );
			$options[] = array(
				'value'         => $attrs['value'] ?? null,
				'label'         => html_entity_decode( strip_tags( $match[2] ), ENT_QUOTES, 'UTF-8' ),
				'selected'      => isset( $attrs['selected'] ),
				'selectedCount' => substr_count( $match[1], 'selected="selected"' ),
				'attrs'         => $attrs,
			);
		}

		return $options;
	}

	private static function timezone_choice_parse_attrs( string $attrs ): array {
		if ( 1 > preg_match_all( '/([A-Za-z0-9:_-]+)="([^"]*)"/', $attrs, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$parsed = array();
		foreach ( $matches as $match ) {
			$parsed[ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );
		}

		return $parsed;
	}

	private static function timezone_choice_has_multi_selected_option( array $options ): bool {
		foreach ( $options as $option ) {
			if ( ( $option['selectedCount'] ?? 0 ) > 1 ) {
				return true;
			}
		}

		return false;
	}

	private static function timezone_choice_structure_ok( array $summary ): bool {
		return ( $summary['optionCount'] ?? 0 ) > 50
			&& ( $summary['optgroupOpen'] ?? null ) === ( $summary['optgroupClose'] ?? null )
			&& true === ( $summary['hasUtcGroup'] ?? false )
			&& true === ( $summary['hasManualGroup'] ?? false )
			&& false === ( $summary['multiSelected'] ?? true )
			&& false === ( $summary['rawScriptLeaked'] ?? true );
	}

	private static function timezone_choice_values( array $summary ): array {
		return array_values(
			array_map(
				static fn ( array $option ): ?string => $option['value'] ?? null,
				$summary['options'] ?? array()
			)
		);
	}

	private static function timezone_choice_value_count( array $summary, string $value ): int {
		return count(
			array_filter(
				$summary['options'] ?? array(),
				static fn ( array $option ): bool => $value === ( $option['value'] ?? null )
			)
		);
	}

	private static function timezone_choice_option_by_value( array $summary, string $value ): ?array {
		foreach ( $summary['options'] ?? array() as $option ) {
			if ( $value === ( $option['value'] ?? null ) ) {
				return $option;
			}
		}

		return null;
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
			'wp_checkdate',
			'pre_load_textdomain',
			'gettext_with_context',
		);

		$tracked_filters = array();
		foreach ( $hooks as $hook ) {
			$tracked_filters[ $hook ] = self::filter_fingerprint( $hook );
		}

		return array(
			'phpTimezone'    => date_default_timezone_get(),
			'wpLocale'       => $GLOBALS['wp_locale'] ?? null,
			'options'        => self::option_store_snapshot(),
			'l10nState'      => self::snapshot_l10n_state(),
			'l10nFingerprint' => self::l10n_state_fingerprint(),
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
		self::restore_l10n_state( $snapshot['l10nState'] );
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

	private static function snapshot_l10n_state(): array {
		$globals = array();
		foreach ( array( 'l10n', 'l10n_unloaded', 'locale', 'wp_textdomain_registry' ) as $name ) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::snapshot_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'globals'                => $globals,
			'translationController'  => self::snapshot_translation_controller(),
		);
	}

	private static function restore_l10n_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( ! empty( $entry['exists'] ) ) {
				$GLOBALS[ $name ] = self::snapshot_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		self::restore_translation_controller( $snapshot['translationController'] ?? null );
	}

	private static function l10n_state_fingerprint(): array {
		$controller = self::translation_controller_fingerprint();

		return array(
			'l10nDomains'       => isset( $GLOBALS['l10n'] ) && is_array( $GLOBALS['l10n'] ) ? array_keys( $GLOBALS['l10n'] ) : array(),
			'unloadedDomains'   => isset( $GLOBALS['l10n_unloaded'] ) && is_array( $GLOBALS['l10n_unloaded'] ) ? array_keys( $GLOBALS['l10n_unloaded'] ) : array(),
			'locale'            => $GLOBALS['locale'] ?? null,
			'registryClass'     => isset( $GLOBALS['wp_textdomain_registry'] ) && is_object( $GLOBALS['wp_textdomain_registry'] ) ? get_class( $GLOBALS['wp_textdomain_registry'] ) : null,
			'controller'        => $controller,
		);
	}

	private static function snapshot_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::snapshot_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) && ! $value instanceof \Closure ) {
			return clone $value;
		}

		return $value;
	}

	private static function snapshot_translation_controller(): ?array {
		if ( ! class_exists( 'WP_Translation_Controller' ) ) {
			return null;
		}

		$reflection = new \ReflectionClass( 'WP_Translation_Controller' );
		if ( ! $reflection->hasProperty( 'instance' ) ) {
			return null;
		}

		$instance_property = $reflection->getProperty( 'instance' );
		$instance          = $instance_property->getValue();
		$snapshot          = array(
			'instanceExists' => null !== $instance,
			'instance'       => $instance,
			'properties'     => array(),
		);

		if ( null === $instance ) {
			return $snapshot;
		}

		$object_reflection = new \ReflectionObject( $instance );
		foreach ( array( 'current_locale', 'loaded_translations', 'loaded_files' ) as $property_name ) {
			if ( ! $object_reflection->hasProperty( $property_name ) ) {
				continue;
			}

			$property = $object_reflection->getProperty( $property_name );
			$snapshot['properties'][ $property_name ] = self::snapshot_value( $property->getValue( $instance ) );
		}

		return $snapshot;
	}

	private static function restore_translation_controller( ?array $snapshot ): void {
		if ( null === $snapshot || ! class_exists( 'WP_Translation_Controller' ) ) {
			return;
		}

		$reflection = new \ReflectionClass( 'WP_Translation_Controller' );
		if ( ! $reflection->hasProperty( 'instance' ) ) {
			return;
		}

		$instance_property = $reflection->getProperty( 'instance' );
		if ( empty( $snapshot['instanceExists'] ) ) {
			$instance_property->setValue( null, null );
			return;
		}

		$instance          = $snapshot['instance'];
		$object_reflection = new \ReflectionObject( $instance );
		foreach ( $snapshot['properties'] as $property_name => $value ) {
			if ( ! $object_reflection->hasProperty( $property_name ) ) {
				continue;
			}

			$property = $object_reflection->getProperty( $property_name );
			$property->setValue( $instance, self::snapshot_value( $value ) );
		}

		$instance_property->setValue( null, $instance );
	}

	private static function translation_controller_fingerprint(): ?array {
		if ( ! class_exists( 'WP_Translation_Controller' ) ) {
			return null;
		}

		$controller = \WP_Translation_Controller::get_instance();
		$reflection = new \ReflectionObject( $controller );
		$fingerprint = array(
			'class' => get_class( $controller ),
		);

		foreach ( array( 'current_locale', 'loaded_translations', 'loaded_files' ) as $property_name ) {
			if ( ! $reflection->hasProperty( $property_name ) ) {
				continue;
			}

			$value = $reflection->getProperty( $property_name )->getValue( $controller );
			if ( is_array( $value ) ) {
				$fingerprint[ $property_name ] = array_keys( $value );
			} else {
				$fingerprint[ $property_name ] = $value;
			}
		}

		return $fingerprint;
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
