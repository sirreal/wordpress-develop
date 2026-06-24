<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-network Community Events API client behavior.
 */
final class CommunityEventsSurface {
	public const NAME = 'community-events';

	private const ADDRESS_HEADERS = array(
		'HTTP_CLIENT_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_FORWARDED',
		'HTTP_X_CLUSTER_CLIENT_IP',
		'HTTP_FORWARDED_FOR',
		'HTTP_FORWARDED',
		'REMOTE_ADDR',
	);

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'community-events.bootstrap-apis-available',
					'Required Community Events APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime_state();

			$rows[] = self::check_client_ip_anonymization( $ctx->fork( 'client-ip' ) );
			$rows[] = self::check_request_arg_minimization( $ctx->fork( 'request-args' ) );
			$rows[] = self::check_cache_keys_and_event_trimming( $ctx->fork( 'cache-trim' ) );
			$rows[] = self::check_coordinates_and_cache_expiration( $ctx->fork( 'coordinates-cache-expiration' ) );
			$rows[] = self::check_successful_api_fetch_and_cache_hit( $ctx->fork( 'success-cache' ) );
			$rows[] = self::check_api_failure_paths( $ctx->fork( 'failure-paths' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'community-events.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'community-events.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedServer' => self::ADDRESS_HEADERS,
				'options'       => null !== $snapshot['options'] ? 'wpdb-stub' : 'unavailable',
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Community_Events', 'WP_Error' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'remove_filter',
				'get_site_transient',
				'has_filter',
				'set_site_transient',
				'wp_cache_flush',
				'wp_http_supports',
				'wp_json_encode',
				'wp_list_pluck',
				'wp_privacy_anonymize_ip',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_client_ip_anonymization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$client_ip    = self::ipv4( $ctx, 203, 0, 113 );
		$forwarded_ip = self::ipv4( $ctx->fork( 'forwarded' ), 198, 51, 100 );
		$ipv6         = self::ipv6( $ctx->fork( 'ipv6' ) );
		$cases        = array(
			array(
				'label'    => 'client-header-wins',
				'headers'  => array(
					'HTTP_CLIENT_IP' => $client_ip,
					'REMOTE_ADDR'    => self::ipv4( $ctx->fork( 'remote' ), 198, 51, 100 ),
				),
				'expected' => self::expected_anonymized_ip( $client_ip ),
			),
			array(
				'label'    => 'forwarded-chain-first-address',
				'headers'  => array(
					'HTTP_X_FORWARDED_FOR' => $forwarded_ip . ', 10.0.0.9',
					'REMOTE_ADDR'          => self::ipv4( $ctx->fork( 'proxy' ), 192, 0, 2 ),
				),
				'expected' => self::expected_anonymized_ip( $forwarded_ip ),
			),
			array(
				'label'    => 'ipv6-network-id',
				'headers'  => array(
					'REMOTE_ADDR' => $ipv6,
				),
				'expected' => self::expected_anonymized_ip( $ipv6 ),
			),
			array(
				'label'    => 'invalid-fails-closed',
				'headers'  => array(
					'REMOTE_ADDR' => 'not-an-ip-' . $ctx->identifier( 3, 8 ),
				),
				'expected' => false,
			),
			array(
				'label'    => 'absent-fails-closed',
				'headers'  => array(),
				'expected' => false,
			),
		);

		foreach ( $cases as $index => $case ) {
			self::set_address_headers( $case['headers'] );
			$actual = \WP_Community_Events::get_unsafe_client_ip();

			self::collect_failure(
				$failures,
				$case['expected'] === $actual,
				"client IP anonymization case {$index}",
				array(
					'label'    => $case['label'],
					'headers'  => $case['headers'],
					'expected' => $case['expected'],
					'actual'   => $actual,
				)
			);
		}

		return $ctx->result(
			'community-events.client-ip.header-precedence-and-anonymization',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_request_arg_minimization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$timezone    = $ctx->choice( array( '', 'Europe/Madrid', 'America/Los_Angeles', 'Asia/Tokyo' ) );
		$search      = 'City ' . $ctx->identifier( 3, 10 );
		$raw_ip      = self::ipv4( $ctx->fork( 'request-ip' ), 203, 0, 113 );
		$expected_ip = self::expected_anonymized_ip( $raw_ip );
		$coords      = self::coordinate_location( $ctx->fork( 'coords' ), 'Known Coordinates' );
		$cases       = array(
			array(
				'label'        => 'coordinates-without-search',
				'userLocation' => $coords,
				'search'       => '',
				'timezone'     => $timezone,
				'expects'      => 'coordinates',
			),
			array(
				'label'        => 'search-uses-locale',
				'userLocation' => $coords,
				'search'       => $search,
				'timezone'     => $timezone,
				'expects'      => 'search',
			),
			array(
				'label'        => 'missing-coordinates-uses-locale',
				'userLocation' => array( 'description' => 'No Coordinates' ),
				'search'       => '',
				'timezone'     => $timezone,
				'expects'      => 'locale',
			),
		);

		self::set_address_headers( array( 'REMOTE_ADDR' => $raw_ip ) );

		foreach ( $cases as $index => $case ) {
			$probe = self::probe( 7000 + $ctx->iteration() + $index, $case['userLocation'] );
			$args  = $probe->fuzz_request_args( $case['search'], $case['timezone'] );
			$body  = $args['body'] ?? array();

			$base_ok = array( 'body' ) === array_keys( $args )
				&& 5 === ( $body['number'] ?? null )
				&& $expected_ip === ( $body['ip'] ?? null );

			$shape_ok = false;
			if ( 'coordinates' === $case['expects'] ) {
				$shape_ok = isset( $body['latitude'], $body['longitude'] )
					&& $case['userLocation']['latitude'] === $body['latitude']
					&& $case['userLocation']['longitude'] === $body['longitude']
					&& ! isset( $body['locale'], $body['timezone'], $body['location'] );
			} elseif ( 'search' === $case['expects'] ) {
				$shape_ok = isset( $body['locale'], $body['location'] )
					&& $case['search'] === $body['location']
					&& ! isset( $body['latitude'], $body['longitude'] )
					&& ( '' === $case['timezone'] || $case['timezone'] === ( $body['timezone'] ?? null ) );
			} else {
				$shape_ok = isset( $body['locale'] )
					&& ! isset( $body['latitude'], $body['longitude'], $body['location'] )
					&& ( '' === $case['timezone'] || $case['timezone'] === ( $body['timezone'] ?? null ) );
			}

			self::collect_failure(
				$failures,
				$base_ok && $shape_ok,
				"request args minimization case {$index}",
				array(
					'label'    => $case['label'],
					'args'     => $args,
					'expected' => array(
						'ip'      => $expected_ip,
						'expects' => $case['expects'],
					),
				)
			);
		}

		return $ctx->result(
			'community-events.request-args.minimal-location-body',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_cache_keys_and_event_trimming( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$probe    = self::probe( 0, false );
		$ip       = self::expected_anonymized_ip( self::ipv4( $ctx->fork( 'cache-ip' ), 198, 51, 100 ) );
		$coords   = self::coordinate_location( $ctx->fork( 'cache-coords' ), 'Cache Coordinates' );

		$key_cases = array(
			array(
				'label'    => 'ip',
				'location' => array( 'ip' => $ip ),
				'expected' => 'community-events-' . md5( $ip ),
			),
			array(
				'label'    => 'coordinates',
				'location' => $coords,
				'expected' => 'community-events-' . md5( $coords['latitude'] . $coords['longitude'] ),
			),
			array(
				'label'    => 'incomplete-location',
				'location' => array( 'description' => 'Only a label' ),
				'expected' => false,
			),
		);

		foreach ( $key_cases as $index => $case ) {
			$actual = $probe->fuzz_transient_key( $case['location'] );
			self::collect_failure(
				$failures,
				$case['expected'] === $actual,
				"transient key case {$index}",
				array(
					'label'    => $case['label'],
					'location' => $case['location'],
					'expected' => $case['expected'],
					'actual'   => $actual,
				)
			);
		}

		$events  = self::event_corpus( $ctx->fork( 'trim' ) );
		$trimmed = $probe->fuzz_trim_events( $events );
		$types   = \wp_list_pluck( $trimmed, 'type' );
		$titles  = \wp_list_pluck( $trimmed, 'title' );

		self::collect_failure(
			$failures,
			3 === count( $trimmed )
				&& ! in_array( 'past', \wp_list_pluck( $trimmed, 'slug' ), true )
				&& in_array( 'wordcamp', $types, true )
				&& in_array( 'Meetup & One', $titles, true )
				&& in_array( 'WordCamp & Pinned', $titles, true ),
			'event trimming keeps future events, decodes titles, and pins a WordCamp',
			array(
				'trimmed' => $trimmed,
				'types'   => $types,
				'titles'  => $titles,
			)
		);

		$cache_probe = self::probe( 0, $coords );
		$cached      = array(
			'location' => $coords,
			'events'   => $events,
		);
		$cache_set   = $cache_probe->fuzz_cache_events( $cached, $ctx->int( -120, 7200 ) );
		$cache_read  = $cache_probe->get_cached_events();

		self::collect_failure(
			$failures,
			true === $cache_set
				&& is_array( $cache_read )
				&& 3 === count( $cache_read['events'] ?? array() )
				&& $coords === ( $cache_read['location'] ?? null ),
			'cache write and read trims shared raw event list',
			array(
				'cacheSet'  => $cache_set,
				'cacheRead' => $cache_read,
			)
		);

		return $ctx->result(
			'community-events.cache-and-trim.contracts',
			array() === $failures,
			array(
				'keyCases' => count( $key_cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_coordinates_and_cache_expiration( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$coords   = self::coordinate_location( $ctx->fork( 'coords' ), 'Coordinate Match' );
		$probe    = self::probe( 7100 + $ctx->iteration(), $coords );

		$same             = $probe->fuzz_coordinates_match( $coords, $coords );
		$same_numeric     = $probe->fuzz_coordinates_match(
			$coords,
			array(
				'latitude'  => (float) $coords['latitude'],
				'longitude' => (float) $coords['longitude'],
			)
		);
		$missing_longitude = $probe->fuzz_coordinates_match(
			$coords,
			array(
				'latitude' => $coords['latitude'],
			)
		);
		$different         = $probe->fuzz_coordinates_match(
			$coords,
			array(
				'latitude'  => $coords['latitude'],
				'longitude' => number_format( ( (float) $coords['longitude'] ) + 0.0001, 4, '.', '' ),
			)
		);

		self::collect_failure(
			$failures,
			true === $same
				&& false === $same_numeric
				&& false === $missing_longitude
				&& false === $different,
			'coordinate matching is strict and rejects missing or drifted longitude pairs',
			array(
				'coords'           => $coords,
				'same'             => $same,
				'sameNumeric'      => $same_numeric,
				'missingLongitude' => $missing_longitude,
				'different'        => $different,
			)
		);

		$key = $probe->fuzz_transient_key( $coords );
		if ( false === $key ) {
			return $ctx->fail(
				'community-events.coordinates-and-cache-expiration',
				array(
					'coords' => $coords,
					'key'    => $key,
				)
			);
		}

		$expiration_events = array();
		$expiration_filter = static function ( int $expiration, $value, string $transient ) use ( &$expiration_events ): int {
			$expiration_events[] = array(
				'expiration' => $expiration,
				'transient'  => $transient,
				'eventCount' => isset( $value['events'] ) && is_array( $value['events'] ) ? count( $value['events'] ) : null,
			);

			return $expiration;
		};
		$hook              = 'expiration_of_site_transient_' . $key;
		$events            = array(
			'location' => $coords,
			'events'   => self::event_corpus( $ctx->fork( 'expiration-events' ) ),
		);
		$negative_events   = $events;
		$negative_events['expiration_marker'] = 'negative-' . $ctx->seed();
		$negative_expire   = -1 * $ctx->int( 1, 7200 );
		$expected_default  = HOUR_IN_SECONDS * 12;
		$expected_negative = abs( $negative_expire );

		\add_filter( $hook, $expiration_filter, 10, 3 );
		try {
			$default_set  = $probe->fuzz_cache_events( $events, false );
			$negative_set = $probe->fuzz_cache_events( $negative_events, $negative_expire );
		} finally {
			$removed = \remove_filter( $hook, $expiration_filter, 10 );
		}

		self::collect_failure(
			$failures,
			true === $default_set
				&& true === $negative_set
				&& array(
					array(
						'expiration' => $expected_default,
						'transient'  => $key,
						'eventCount' => 5,
					),
					array(
						'expiration' => $expected_negative,
						'transient'  => $key,
						'eventCount' => 5,
					),
				) === $expiration_events
				&& $removed
				&& false === \has_filter( $hook, $expiration_filter ),
			'cache_events uses the default half-day expiration and absint-normalizes explicit expirations',
			array(
				'key'                => $key,
				'negativeExpiration' => $negative_expire,
				'events'             => $expiration_events,
				'defaultSet'         => $default_set,
				'negativeSet'        => $negative_set,
				'removed'            => $removed ?? null,
				'hasAfter'           => \has_filter( $hook, $expiration_filter ),
			)
		);

		return $ctx->result(
			'community-events.coordinates-and-cache-expiration',
			array() === $failures,
			array(
				'failures' => $failures,
				'key'      => $key,
			)
		);
	}

	private static function check_successful_api_fetch_and_cache_hit( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			self::ip_fetch_case( $ctx->fork( 'ip-fetch' ) ),
			self::coordinate_fetch_case( $ctx->fork( 'coords-fetch' ) ),
		);

		foreach ( $cases as $index => $case ) {
			self::reset_runtime_state();
			self::set_address_headers( array( 'REMOTE_ADDR' => $case['remoteAddr'] ) );

			$calls  = array();
			$filter = static function ( $preempt, array $parsed_args, string $url ) use ( &$calls, $case ) {
				unset( $preempt );

				$calls[] = array(
					'url'  => $url,
					'args' => $parsed_args,
				);

				return array(
					'headers'  => array(),
					'body'     => \wp_json_encode( $case['responseBody'] ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			};

			\add_filter( 'pre_http_request', $filter, 10, 3 );
			try {
				$client = new \WP_Community_Events( $case['userId'], $case['userLocation'] );
				$first  = $client->get_events( '', $case['timezone'] );
				$second = $client->get_events( '', $case['timezone'] );
			} finally {
				\remove_filter( 'pre_http_request', $filter, 10 );
			}

			$request_body = $calls[0]['args']['body'] ?? array();
			$location     = is_array( $first ) ? ( $first['location'] ?? array() ) : array();
			$events       = is_array( $first ) ? ( $first['events'] ?? array() ) : array();

			$base_ok = is_array( $first )
				&& ! \is_wp_error( $first )
				&& $first === $second
				&& 1 === count( $calls )
				&& isset( $calls[0]['url'] )
				&& str_ends_with( $calls[0]['url'], '://api.wordpress.org/events/1.0/' )
				&& $case['expectedRequest']( $request_body )
				&& ! isset( $first['ttl'] )
				&& 3 === count( $events )
				&& in_array( 'WordCamp & Pinned', \wp_list_pluck( $events, 'title' ), true );

			self::collect_failure(
				$failures,
				$base_ok && $case['expectedLocation']( $location ),
				"successful API fetch and cache hit case {$index}",
				array(
					'label'       => $case['label'],
					'calls'       => $calls,
					'first'       => $first,
					'second'      => $second,
					'location'    => $location,
					'requestBody' => $request_body,
				)
			);
		}

		return $ctx->result(
			'community-events.get-events.success-cache-and-response-normalization',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function check_api_failure_paths( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'    => 'transport-error',
				'response' => new \WP_Error( 'component-fuzz-http-blocked', 'HTTP blocked by fuzzer.' ),
				'code'     => 'component-fuzz-http-blocked',
			),
			array(
				'label'    => 'status-error',
				'response' => self::http_response( 503, array( 'error' => 'maintenance' ) ),
				'code'     => 'api-error',
			),
			array(
				'label'    => 'missing-events',
				'response' => self::http_response( 200, array( 'location' => array( 'description' => 'Missing events' ) ) ),
				'code'     => 'api-invalid-response',
			),
			array(
				'label'    => 'invalid-json',
				'response' => array(
					'headers'  => array(),
					'body'     => '{"location":',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				),
				'code'     => 'api-invalid-response',
			),
		);

		foreach ( $cases as $index => $case ) {
			self::reset_runtime_state();
			self::set_address_headers( array( 'REMOTE_ADDR' => self::ipv4( $ctx->fork( 'failure-' . $index ), 203, 0, 113 ) ) );

			$calls  = 0;
			$filter = static function () use ( &$calls, $case ) {
				++$calls;
				return $case['response'];
			};

			\add_filter( 'pre_http_request', $filter, 10, 3 );
			try {
				$client = new \WP_Community_Events( 8000 + $index, false );
				$result = $client->get_events( 'Search ' . $ctx->identifier( 3, 8 ), 'UTC' );
			} finally {
				\remove_filter( 'pre_http_request', $filter, 10 );
			}

			self::collect_failure(
				$failures,
				1 === $calls
					&& \is_wp_error( $result )
					&& $case['code'] === $result->get_error_code(),
				"API failure path case {$index}",
				array(
					'label'    => $case['label'],
					'expected' => $case['code'],
					'actual'   => \is_wp_error( $result ) ? $result->get_error_code() : $result,
					'calls'    => $calls,
				)
			);
		}

		return $ctx->result(
			'community-events.get-events.failure-paths-return-wp-error',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => $failures,
			)
		);
	}

	private static function ip_fetch_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$remote_addr = self::ipv4( $ctx, 198, 51, 100 );
		$ip          = self::expected_anonymized_ip( $remote_addr );
		$timezone    = $ctx->choice( array( '', 'UTC', 'Europe/Madrid' ) );

		return array(
			'label'            => 'ip-location-cache',
			'userId'           => 8100 + $ctx->iteration(),
			'remoteAddr'       => $remote_addr,
			'timezone'         => $timezone,
			'userLocation'     => array(
				'ip'          => $ip,
				'description' => 'IP Location',
			),
			'responseBody'     => array(
				'location' => array(
					'ip'          => self::ipv4( $ctx->fork( 'api-ip' ), 203, 0, 113 ),
					'description' => 'API Location',
				),
				'events'   => self::event_corpus( $ctx->fork( 'events' ) ),
				'ttl'      => $ctx->int( 30, 7200 ),
			),
			'expectedRequest'  => static function ( array $body ) use ( $ip, $timezone ): bool {
				return 5 === ( $body['number'] ?? null )
					&& $ip === ( $body['ip'] ?? null )
					&& isset( $body['locale'] )
					&& ! isset( $body['latitude'], $body['longitude'], $body['location'] )
					&& ( '' === $timezone || $timezone === ( $body['timezone'] ?? null ) );
			},
			'expectedLocation' => static function ( array $location ) use ( $ip ): bool {
				return $ip === ( $location['ip'] ?? null )
					&& 'API Location' === ( $location['description'] ?? null );
			},
		);
	}

	private static function coordinate_fetch_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$coords = self::coordinate_location( $ctx->fork( 'coords' ), 'Stored Coordinates' );

		return array(
			'label'            => 'coordinate-location-cache',
			'userId'           => 8200 + $ctx->iteration(),
			'remoteAddr'       => self::ipv4( $ctx, 203, 0, 113 ),
			'timezone'         => $ctx->choice( array( '', 'UTC', 'America/New_York' ) ),
			'userLocation'     => $coords,
			'responseBody'     => array(
				'location' => array(
					'latitude'    => $coords['latitude'],
					'longitude'   => $coords['longitude'],
					'description' => '',
					'country'     => $coords['country'],
				),
				'events'   => self::event_corpus( $ctx->fork( 'events' ) ),
				'ttl'      => $ctx->int( 30, 7200 ),
			),
			'expectedRequest'  => static function ( array $body ) use ( $coords ): bool {
				return 5 === ( $body['number'] ?? null )
					&& isset( $body['ip'] )
					&& $coords['latitude'] === ( $body['latitude'] ?? null )
					&& $coords['longitude'] === ( $body['longitude'] ?? null )
					&& ! isset( $body['locale'], $body['timezone'], $body['location'] );
			},
			'expectedLocation' => static function ( array $location ) use ( $coords ): bool {
				return $coords['latitude'] === ( $location['latitude'] ?? null )
					&& $coords['longitude'] === ( $location['longitude'] ?? null )
					&& $coords['description'] === ( $location['description'] ?? null );
			},
		);
	}

	private static function probe( int $user_id, $location ): object {
		return new class( $user_id, $location ) extends \WP_Community_Events {
			public function fuzz_request_args( string $search = '', string $timezone = '' ): array {
				return $this->get_request_args( $search, $timezone );
			}

			public function fuzz_transient_key( $location ) {
				return $this->get_events_transient_key( $location );
			}

			public function fuzz_cache_events( array $events, $expiration = false ): bool {
				return $this->cache_events( $events, $expiration );
			}

			public function fuzz_trim_events( array $events ): array {
				return $this->trim_events( $events );
			}

			public function fuzz_coordinates_match( array $a, array $b ): bool {
				return $this->coordinates_match( $a, $b );
			}
		};
	}

	private static function event_corpus( \ComponentFuzz\FuzzContext $ctx ): array {
		$now = time();

		return array(
			self::event_row( 'future-a', 'meetup', 'Meetup &amp; One', $now + $ctx->int( 3600, 90000 ) ),
			self::event_row( 'future-b', 'meetup', 'Meetup Two', $now + $ctx->int( 90001, 160000 ) ),
			self::event_row( 'future-c', 'meetup', 'Meetup Three', $now + $ctx->int( 160001, 250000 ) ),
			self::event_row( 'future-wordcamp', 'wordcamp', 'WordCamp &amp; Pinned', $now + $ctx->int( 250001, 400000 ) ),
			self::event_row( 'past', 'wordcamp', 'Past WordCamp', $now - $ctx->int( 3600, 90000 ) ),
		);
	}

	private static function event_row( string $slug, string $type, string $title, int $end_timestamp ): array {
		return array(
			'slug'               => $slug,
			'type'               => $type,
			'title'              => $title,
			'url'                => 'https://example.test/events/' . rawurlencode( $slug ),
			'date'               => gmdate( 'Y-m-d H:i:s', $end_timestamp - HOUR_IN_SECONDS ),
			'end_date'           => gmdate( 'Y-m-d H:i:s', $end_timestamp ),
			'end_unix_timestamp' => $end_timestamp,
			'location'           => array(
				'location' => 'Component Fuzz',
				'country'  => 'ES',
			),
		);
	}

	private static function coordinate_location( \ComponentFuzz\FuzzContext $ctx, string $description ): array {
		return array(
			'description' => $description,
			'latitude'    => number_format( $ctx->int( -850000, 850000 ) / 10000, 4, '.', '' ),
			'longitude'   => number_format( $ctx->int( -1700000, 1700000 ) / 10000, 4, '.', '' ),
			'country'     => $ctx->choice( array( 'ES', 'US', 'BR', 'JP' ) ),
		);
	}

	private static function http_response( int $code, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => \wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
		);
	}

	private static function set_address_headers( array $headers ): void {
		foreach ( self::ADDRESS_HEADERS as $header ) {
			unset( $_SERVER[ $header ] );
		}

		foreach ( $headers as $header => $value ) {
			$_SERVER[ $header ] = $value;
		}
	}

	private static function expected_anonymized_ip( string $ip ) {
		$anon_ip = \wp_privacy_anonymize_ip( $ip, true );

		if ( '0.0.0.0' === $anon_ip || '::' === $anon_ip ) {
			return false;
		}

		return $anon_ip;
	}

	private static function ipv4( \ComponentFuzz\FuzzContext $ctx, int $a, int $b, int $c ): string {
		return $a . '.' . $b . '.' . $c . '.' . $ctx->int( 1, 254 );
	}

	private static function ipv6( \ComponentFuzz\FuzzContext $ctx ): string {
		return sprintf(
			'2001:db8:%x:%x:%x:%x:%x:%x',
			$ctx->int( 0, 65535 ),
			$ctx->int( 0, 65535 ),
			$ctx->int( 0, 65535 ),
			$ctx->int( 0, 65535 ),
			$ctx->int( 0, 65535 ),
			$ctx->int( 1, 65535 )
		);
	}

	private static function reset_runtime_state(): void {
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function snapshot_state(): array {
		$options = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return array(
			'server'  => $_SERVER,
			'options' => $options,
		);
	}

	private static function restore_state( array $snapshot ): void {
		$_SERVER = $snapshot['server'];

		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		$options = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}

		return $snapshot['server'] === $_SERVER
			&& $snapshot['options'] === $options;
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => self::compact( $data ),
		);
	}

	private static function compact( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '...' : $value;
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 20, true ) as $key => $item ) {
				$out[ $key ] = self::compact( $item );
			}
			if ( count( $value ) > 20 ) {
				$out['__truncated__'] = count( $value ) - 20;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			return method_exists( $value, 'get_error_code' )
				? array(
					'class' => get_class( $value ),
					'code'  => $value->get_error_code(),
				)
				: array( 'class' => get_class( $value ) );
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
