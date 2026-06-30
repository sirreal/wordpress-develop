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
		self::maybe_load_ajax_actions();

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
			$rows[] = self::check_invalid_ip_request_arg_minimization( $ctx->fork( 'invalid-ip-request-args' ) );
			$rows[] = self::check_cache_keys_and_event_trimming( $ctx->fork( 'cache-trim' ) );
			$rows[] = self::check_coordinates_and_cache_expiration( $ctx->fork( 'coordinates-cache-expiration' ) );
			$rows[] = self::check_successful_api_fetch_and_cache_hit( $ctx->fork( 'success-cache' ) );
			$rows[] = self::check_search_bypasses_cache_and_refreshes( $ctx->fork( 'search-cache-refresh' ) );
			$rows[] = self::check_api_failure_paths( $ctx->fork( 'failure-paths' ) );
			$rows[] = self::check_ajax_handler_envelopes_and_location_persistence( $ctx->fork( 'ajax-handler' ) );
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

	private static function maybe_load_ajax_actions(): void {
		if ( defined( 'ABSPATH' ) && ! function_exists( 'wp_ajax_get_community_events' ) ) {
			$ajax_actions = ABSPATH . 'wp-admin/includes/ajax-actions.php';
			if ( is_readable( $ajax_actions ) ) {
				require_once $ajax_actions;
			}
		}
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
				'check_ajax_referer',
				'delete_user_meta',
				'delete_site_transient',
				'remove_filter',
				'get_current_user_id',
				'get_site_transient',
				'get_user_meta',
				'get_user_option',
				'has_filter',
				'is_wp_error',
				'set_site_transient',
				'update_user_meta',
				'wp_cache_flush',
				'wp_create_nonce',
				'wp_insert_user',
				'wp_send_json_error',
				'wp_send_json_success',
				'wp_set_current_user',
				'wp_http_supports',
				'wp_json_encode',
				'wp_list_pluck',
				'wp_privacy_anonymize_ip',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_response_code',
				'wp_slash',
				'wp_unslash',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! function_exists( 'wp_ajax_get_community_events' ) ) {
			$missing[] = 'function wp_ajax_get_community_events';
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

	private static function check_invalid_ip_request_arg_minimization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$coords   = self::coordinate_location( $ctx->fork( 'coords' ), 'Invalid IP Coordinates' );
		$search   = 'Invalid IP ' . $ctx->identifier( 4, 10 );
		$timezone = $ctx->choice( array( '', 'UTC', 'Europe/Madrid' ) );
		$cases    = array(
			array(
				'label'        => 'invalid-ip-with-coordinates',
				'headers'      => array( 'REMOTE_ADDR' => 'not-an-ip-' . $ctx->identifier( 3, 8 ) ),
				'userLocation' => $coords,
				'search'       => '',
				'expects'      => 'coordinates',
			),
			array(
				'label'        => 'missing-ip-with-search',
				'headers'      => array(),
				'userLocation' => $coords,
				'search'       => $search,
				'expects'      => 'search',
			),
		);

		foreach ( $cases as $index => $case ) {
			self::set_address_headers( $case['headers'] );
			$probe = self::probe( 7200 + $ctx->iteration() + $index, $case['userLocation'] );
			$args  = $probe->fuzz_request_args( $case['search'], $timezone );
			$body  = $args['body'] ?? array();

			$base_ok = array( 'body' ) === array_keys( $args )
				&& false === ( $body['ip'] ?? null )
				&& 5 === ( $body['number'] ?? null );

			if ( 'coordinates' === $case['expects'] ) {
				$shape_ok = $coords['latitude'] === ( $body['latitude'] ?? null )
					&& $coords['longitude'] === ( $body['longitude'] ?? null )
					&& ! isset( $body['locale'], $body['timezone'], $body['location'] );
			} else {
				$shape_ok = $search === ( $body['location'] ?? null )
					&& isset( $body['locale'] )
					&& ( '' === $timezone || $timezone === ( $body['timezone'] ?? null ) )
					&& ! isset( $body['latitude'], $body['longitude'] );
			}

			self::collect_failure(
				$failures,
				$base_ok && $shape_ok,
				"invalid IP request args case {$index}",
				array(
					'label'    => $case['label'],
					'headers'  => $case['headers'],
					'body'     => $body,
					'timezone' => $timezone,
				)
			);
		}

		return $ctx->result(
			'community-events.request-args.invalid-ip-fails-closed',
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

	private static function check_search_bypasses_cache_and_refreshes( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$remote_addr = self::ipv4( $ctx->fork( 'remote' ), 198, 51, 100 );
		$ip          = self::expected_anonymized_ip( $remote_addr );
		$search      = 'Search ' . $ctx->identifier( 4, 12 );
		$timezone    = $ctx->choice( array( 'UTC', 'Europe/Madrid', 'America/New_York' ) );
		$location    = array(
			'ip'          => $ip,
			'description' => 'Cached Location',
		);
		$cached_raw  = self::event_corpus( $ctx->fork( 'cached' ) );
		$fresh_raw   = self::event_corpus( $ctx->fork( 'fresh' ) );
		$cached_raw[0]['title'] = 'Cached &amp; Meetup';
		$fresh_raw[0]['title']  = 'Fresh &amp; Meetup';
		$response_body = array(
			'location' => array(
				'ip'          => self::ipv4( $ctx->fork( 'api-ip' ), 203, 0, 113 ),
				'description' => 'Fresh Search Location',
			),
			'events'   => $fresh_raw,
			'ttl'      => $ctx->int( 60, 7200 ),
		);

		self::reset_runtime_state();
		self::set_address_headers( array( 'REMOTE_ADDR' => $remote_addr ) );

		$probe = self::probe( 8300 + $ctx->iteration(), $location );
		$probe->fuzz_cache_events(
			array(
				'location' => $location,
				'events'   => $cached_raw,
			),
			HOUR_IN_SECONDS
		);

		$calls  = array();
		$filter = static function ( $preempt, array $parsed_args, string $url ) use ( &$calls, $response_body ) {
			unset( $preempt );

			$calls[] = array(
				'url'  => $url,
				'body' => $parsed_args['body'] ?? array(),
			);

			return self::http_response( 200, $response_body );
		};

		\add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$cached = $probe->get_events( '', $timezone );
			$fresh  = $probe->get_events( $search, $timezone );
			$after  = $probe->get_events( '', $timezone );
		} finally {
			\remove_filter( 'pre_http_request', $filter, 10 );
		}

		$cached_titles = is_array( $cached ) && isset( $cached['events'] ) ? \wp_list_pluck( $cached['events'], 'title' ) : array();
		$fresh_titles  = is_array( $fresh ) && isset( $fresh['events'] ) ? \wp_list_pluck( $fresh['events'], 'title' ) : array();
		$after_titles  = is_array( $after ) && isset( $after['events'] ) ? \wp_list_pluck( $after['events'], 'title' ) : array();
		$request_body  = $calls[0]['body'] ?? array();

		self::collect_failure(
			$failures,
			is_array( $cached )
				&& array( 'Cached & Meetup', 'Meetup Two', 'WordCamp & Pinned' ) === $cached_titles
				&& is_array( $fresh )
				&& array( 'Fresh & Meetup', 'Meetup Two', 'WordCamp & Pinned' ) === $fresh_titles
				&& is_array( $after )
				&& $fresh_titles === $after_titles
				&& $ip === ( $fresh['location']['ip'] ?? null )
				&& 'Fresh Search Location' === ( $fresh['location']['description'] ?? null ),
			'manual location searches bypass cached events, normalize the response IP, and refresh cache contents',
			array(
				'cached' => $cached,
				'fresh'  => $fresh,
				'after'  => $after,
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $calls )
				&& str_ends_with( $calls[0]['url'] ?? '', '://api.wordpress.org/events/1.0/' )
				&& $search === ( $request_body['location'] ?? null )
				&& $timezone === ( $request_body['timezone'] ?? null )
				&& $ip === ( $request_body['ip'] ?? null )
				&& isset( $request_body['locale'] )
				&& ! isset( $request_body['latitude'], $request_body['longitude'] ),
			'search refresh sends minimal search request body exactly once despite an existing cache hit',
			array(
				'calls'       => $calls,
				'requestBody' => $request_body,
			)
		);

		return $ctx->result(
			'community-events.get-events.search-bypasses-cache-and-refreshes',
			array() === $failures,
			array(
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

	private static function check_ajax_handler_envelopes_and_location_persistence( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$user_id  = self::insert_user( $ctx->fork( 'ajax-user' ) );

		if ( \is_wp_error( $user_id ) ) {
			return $ctx->fail(
				'community-events.ajax-handler.envelopes-and-location-persistence',
				array( 'user' => $user_id )
			);
		}

		$remote_addr   = self::ipv4( $ctx->fork( 'ajax-ip' ), 203, 0, 113 );
		$request_ip    = self::expected_anonymized_ip( $remote_addr );
		$search        = 'Search ' . $ctx->identifier( 4, 10 ) . ' "Quoted"';
		$timezone      = $ctx->choice( array( 'UTC', 'Europe/Madrid', 'America/New_York' ) );
		$saved_ip      = array(
			'ip'          => $request_ip,
			'description' => 'Saved IP Location',
		);
		$saved_error   = array(
			'description' => 'Saved Error Location',
			'latitude'    => '41.3874',
			'longitude'   => '2.1686',
			'country'     => 'ES',
		);
		$searched_loc  = array(
			'description' => 'Searched Location',
			'latitude'    => '40.4168',
			'longitude'   => '-3.7038',
			'country'     => 'ES',
		);
		$cases         = array(
			array(
				'label'           => 'initial-ip-location-persists',
				'initialLocation' => false,
				'search'          => '',
				'timezone'        => '',
				'response'        => self::http_response(
					200,
					array(
						'location' => array(
							'ip'          => self::ipv4( $ctx->fork( 'api-ip' ), 198, 51, 100 ),
							'description' => 'API IP Location',
						),
						'events'   => self::event_corpus( $ctx->fork( 'ajax-initial-events' ) ),
						'ttl'      => $ctx->int( 60, 7200 ),
					)
				),
				'expectSuccess'   => true,
				'expectUpdated'   => true,
				'expectedMeta'    => static function ( array $data ) {
					return $data['location'] ?? null;
				},
				'requestOracle'   => static function ( array $body ) use ( $request_ip ): bool {
					return 5 === ( $body['number'] ?? null )
						&& $request_ip === ( $body['ip'] ?? null )
						&& isset( $body['locale'] )
						&& ! isset( $body['timezone'], $body['location'], $body['latitude'], $body['longitude'] );
				},
			),
			array(
				'label'           => 'same-ip-preserves-saved-location',
				'initialLocation' => $saved_ip,
				'search'          => '',
				'timezone'        => '',
				'response'        => self::http_response(
					200,
					array(
						'location' => array(
							'ip'          => self::ipv4( $ctx->fork( 'same-api-ip' ), 198, 51, 100 ),
							'description' => 'API Same IP Location',
						),
						'events'   => self::event_corpus( $ctx->fork( 'ajax-same-ip-events' ) ),
					)
				),
				'expectSuccess'   => true,
				'expectUpdated'   => false,
				'expectedMeta'    => static function () use ( $saved_ip ) {
					return $saved_ip;
				},
				'requestOracle'   => static function ( array $body ) use ( $request_ip ): bool {
					return $request_ip === ( $body['ip'] ?? null )
						&& isset( $body['locale'] )
						&& ! isset( $body['location'], $body['latitude'], $body['longitude'] );
				},
			),
			array(
				'label'           => 'manual-search-persists-response-location',
				'initialLocation' => $saved_ip,
				'search'          => $search,
				'timezone'        => $timezone,
				'response'        => self::http_response(
					200,
					array(
						'location' => $searched_loc,
						'events'   => self::event_corpus( $ctx->fork( 'ajax-search-events' ) ),
					)
				),
				'expectSuccess'   => true,
				'expectUpdated'   => true,
				'expectedMeta'    => static function () use ( $searched_loc ) {
					return $searched_loc;
				},
				'requestOracle'   => static function ( array $body ) use ( $request_ip, $search, $timezone ): bool {
					return $request_ip === ( $body['ip'] ?? null )
						&& $search === ( $body['location'] ?? null )
						&& $timezone === ( $body['timezone'] ?? null )
						&& isset( $body['locale'] )
						&& ! isset( $body['latitude'], $body['longitude'] );
				},
			),
			array(
				'label'           => 'api-error-preserves-location',
				'initialLocation' => $saved_error,
				'search'          => $search,
				'timezone'        => $timezone,
				'response'        => new \WP_Error( 'component_fuzz_events_blocked', 'Component fuzz blocked events API.' ),
				'expectSuccess'   => false,
				'expectUpdated'   => false,
				'expectedMeta'    => static function () use ( $saved_error ) {
					return $saved_error;
				},
				'requestOracle'   => static function ( array $body ) use ( $request_ip, $search, $timezone ): bool {
					return $request_ip === ( $body['ip'] ?? null )
						&& $search === ( $body['location'] ?? null )
						&& $timezone === ( $body['timezone'] ?? null );
				},
			),
		);

		foreach ( $cases as $index => $case ) {
			$result = self::run_ajax_handler_case( $ctx->fork( 'ajax-case-' . $index ), (int) $user_id, $remote_addr, $case );
			$json   = $result['capture']['json'];
			$data   = is_array( $json ) ? ( $json['data'] ?? array() ) : array();
			$meta   = $result['meta'];
			$body   = $result['httpCalls'][0]['body'] ?? array();

			$expected_meta = $case['expectedMeta']( is_array( $data ) ? $data : array() );
			$checks        = array(
				'captured'        => true === ( $result['capture']['captured'] ?? false ),
				'filtersRestored' => true === ( $result['capture']['filtersRestored'] ?? false ),
				'bufferBalanced'  => true === ( $result['capture']['bufferBalanced'] ?? false ),
				'noThrowable'     => null === ( $result['capture']['threw'] ?? null ),
				'dieCallCount'    => 1 === count( $result['capture']['dieCalls'] ?? array() ),
				'httpCallCount'   => 1 === count( $result['httpCalls'] ),
				'requestOracle'   => $case['requestOracle']( $body ),
				'meta'            => $expected_meta === $meta,
				'updateCalls'     => (int) $case['expectUpdated'] === count( $result['updateCalls'] ?? array() ),
			);
			$base_ok       = ! in_array( false, $checks, true );

			$shape_ok = false;
			if ( $case['expectSuccess'] ) {
				$shape_ok = true === ( $json['success'] ?? null )
					&& is_array( $data )
					&& isset( $data['location'], $data['events'] )
					&& ! isset( $data['ttl'] )
					&& 3 === count( $data['events'] )
					&& in_array( 'WordCamp & Pinned', \wp_list_pluck( $data['events'], 'title' ), true );
			} else {
				$shape_ok = false === ( $json['success'] ?? null )
					&& 'Component fuzz blocked events API.' === ( $data['error'] ?? null );
			}

			self::collect_failure(
				$failures,
				$base_ok && $shape_ok,
				"community events AJAX handler case {$index}",
				array(
					'label'        => $case['label'],
					'failedChecks' => array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) ),
					'shapeOk'      => $shape_ok,
					'meta'         => $meta,
					'expectedMeta' => $expected_meta,
					'updateCount'  => count( $result['updateCalls'] ),
				)
			);
		}

		return $ctx->result(
			'community-events.ajax-handler.envelopes-and-location-persistence',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'userId'   => (int) $user_id,
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

	private static function insert_user( \ComponentFuzz\FuzzContext $ctx ) {
		$suffix = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 8, 16 ) ) );
		$suffix = '' === $suffix ? substr( sha1( (string) $ctx->seed() ), 0, 8 ) : $suffix;
		$login  = 'cfz_events_' . $ctx->iteration() . '_' . $suffix;

		return \wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => 'pass-' . $ctx->identifier( 12, 20 ),
				'user_email'   => $login . '@example.test',
				'display_name' => 'Community Events ' . $suffix,
				'role'         => 'subscriber',
			)
		);
	}

	private static function run_ajax_handler_case( \ComponentFuzz\FuzzContext $ctx, int $user_id, string $remote_addr, array $case ): array {
		self::reset_runtime_state();
		self::set_address_headers( array( 'REMOTE_ADDR' => $remote_addr ) );
		self::delete_cached_events_for_location(
			array(
				'ip' => self::expected_anonymized_ip( $remote_addr ),
			)
		);
		self::delete_cached_events_for_location( $case['initialLocation'] );
		\wp_set_current_user( $user_id );
		\delete_user_meta( $user_id, 'community-events-location' );

		if ( false !== $case['initialLocation'] ) {
			\update_user_meta( $user_id, 'community-events-location', $case['initialLocation'] );
		}

		$nonce = \wp_create_nonce( 'community_events' );
		$_POST = array(
			'_ajax_nonce' => $nonce,
			'location'    => \wp_slash( $case['search'] ),
			'timezone'    => \wp_slash( $case['timezone'] ),
		);
		$_REQUEST = $_POST;

		$http_calls    = array();
		$update_calls  = array();
		$http_filter   = static function ( $preempt, array $parsed_args, string $url ) use ( &$http_calls, $case ) {
			unset( $preempt );

			$http_calls[] = array(
				'url'  => $url,
				'body' => $parsed_args['body'] ?? array(),
			);

			return $case['response'];
		};
		$update_filter = static function ( $check, int $object_id, string $meta_key, $meta_value ) use ( &$update_calls, $user_id ) {
			if ( $user_id === $object_id && 'community-events-location' === $meta_key ) {
				$update_calls[] = $meta_value;
			}

			return $check;
		};

		\add_filter( 'pre_http_request', $http_filter, 10, 3 );
		\add_filter( 'update_user_metadata', $update_filter, 10, 4 );
		try {
			$capture = self::capture_ajax_call(
				static function (): void {
					\wp_ajax_get_community_events();
				}
			);
		} finally {
			\remove_filter( 'update_user_metadata', $update_filter, 10 );
			\remove_filter( 'pre_http_request', $http_filter, 10 );
		}

		return array(
			'capture'     => $capture,
			'httpCalls'   => $http_calls,
			'updateCalls' => $update_calls,
			'meta'        => \get_user_meta( $user_id, 'community-events-location', true ),
		);
	}

	private static function delete_cached_events_for_location( $location ): void {
		if ( ! is_array( $location ) || ! function_exists( 'delete_site_transient' ) ) {
			return;
		}

		$key = self::probe( 0, $location )->fuzz_transient_key( $location );
		if ( false !== $key ) {
			\delete_site_transient( $key );
		}
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

	private static function capture_ajax_call( callable $callback ): array {
		$die_calls      = array();
		$doing_ajax     = static fn() => true;
		$handler_filter = static function () use ( &$die_calls ) {
			return static function ( $message = '', $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'message' => $message,
					'title'   => $title,
					'args'    => $args,
				);
				throw new CommunityEventsSurface_DieCaptured( 'Captured ajax wp_die.' );
			};
		};

		$level    = ob_get_level();
		$output   = '';
		$captured = false;
		$threw    = null;

		\add_filter( 'wp_doing_ajax', $doing_ajax, 1 );
		\add_filter( 'wp_die_ajax_handler', $handler_filter, 1 );
		ob_start();
		try {
			$callback();
		} catch ( CommunityEventsSurface_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$threw = self::describe_throwable( $e );
		} finally {
			$output = (string) ob_get_clean();
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			\remove_filter( 'wp_die_ajax_handler', $handler_filter, 1 );
			\remove_filter( 'wp_doing_ajax', $doing_ajax, 1 );
		}

		return array(
			'captured'        => $captured,
			'threw'           => $threw,
			'output'          => self::describe_string( $output ),
			'json'            => self::decode_json_object( $output ),
			'dieCalls'        => $die_calls,
			'filtersRestored' => false === \has_filter( 'wp_die_ajax_handler', $handler_filter ) && false === \has_filter( 'wp_doing_ajax', $doing_ajax ),
			'bufferBalanced'  => ob_get_level() === $level,
		);
	}

	private static function decode_json_object( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function describe_string( string $value ): array {
		return array(
			'length'  => strlen( $value ),
			'preview' => strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '...' : $value,
		);
	}

	private static function reset_runtime_state(): void {
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function snapshot_state(): array {
		$options        = null;
		$content_counts = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			$content_counts = $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array(
			'post'          => $_POST,
			'request'       => $_REQUEST,
			'server'        => $_SERVER,
			'currentUserId' => function_exists( 'get_current_user_id' ) ? \get_current_user_id() : 0,
			'options'       => $options,
			'contentCounts' => $content_counts,
		);
	}

	private static function restore_state( array $snapshot ): void {
		$_POST    = $snapshot['post'];
		$_REQUEST = $snapshot['request'];
		$_SERVER  = $snapshot['server'];

		if ( function_exists( 'wp_set_current_user' ) ) {
			\wp_set_current_user( (int) $snapshot['currentUserId'] );
		}

		if ( null !== $snapshot['options'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
		}
		if ( null !== $snapshot['contentCounts'] && isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		$options        = null;
		$content_counts = null;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_get_options' ) ) {
			$options = $GLOBALS['wpdb']->component_fuzz_get_options();
		}
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_content_counts' ) ) {
			$content_counts = $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return $snapshot['server'] === $_SERVER
			&& $snapshot['post'] === $_POST
			&& $snapshot['request'] === $_REQUEST
			&& ( ! function_exists( 'get_current_user_id' ) || (int) $snapshot['currentUserId'] === \get_current_user_id() )
			&& $snapshot['options'] === $options
			&& $snapshot['contentCounts'] === $content_counts;
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

final class CommunityEventsSurface_DieCaptured extends \RuntimeException {}
