<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB multisite and network APIs.
 */
final class MultisiteSurface {
	public const NAME = 'multisite';

	/** @var array<int,array<string,mixed>> */
	private static array $blog_options = array();

	/** @var array<int,array<string,mixed>> */
	private static array $network_options = array();

	/** @var array<int,int> */
	private static array $main_site_ids = array();

	/** @var WP_Site[] */
	private static array $sites = array();

	/** @var WP_Network[] */
	private static array $networks = array();

	/** @var array<int,int[]> */
	private static array $user_blog_memberships = array();

	/** @var array<int,array<string,mixed>> */
	private static array $path_lookup_log = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'multisite.bootstrap-apis-available',
					'Required WordPress multisite APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			$case = self::prepare_case( $ctx );

			self::reset_runtime( $ctx, $case );
			self::install_filters();

			$rows[] = self::check_site_objects_and_normalization( $ctx );
			$rows[] = self::check_network_objects_and_main_site( $ctx );
			$rows[] = self::check_generated_site_network_fixture( $ctx, $case );
			$rows[] = self::check_legacy_blog_identity_helpers( $ctx, $case );
			$rows[] = self::check_bootstrap_resolution_helpers( $ctx, $case );
			$rows[] = self::check_switch_stack_and_cache_context( $ctx );
			$rows[] = self::check_blog_option_helpers( $ctx, $case );
			$rows[] = self::check_network_option_helpers( $ctx );
			$rows[] = self::check_large_network_helpers( $ctx, $case );
			$rows[] = self::check_site_and_network_queries( $ctx );
			$rows[] = self::check_path_lookup_helpers( $ctx, $case );
			$rows[] = self::check_url_helpers( $ctx );
			$rows[] = self::check_upload_paths( $ctx, $case );
			$rows[] = self::check_user_blog_membership_helpers( $ctx, $case );
			$rows[] = self::check_cache_group_isolation( $ctx, $case );
			$rows[] = self::check_restoration_probe( $ctx, $snapshot );
			$rows[] = self::check_true_multisite_lifecycle_subprocess( $ctx->fork( 'true-multisite-lifecycle' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'multisite.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::remove_filters();
			self::restore_state( $snapshot );
			self::clear_static_state();
		}

		$rows[] = self::check_post_restore_state( $ctx, $snapshot );

		return $rows;
	}

	public static function filter_option( $pre_option, string $option, $default_value = false ) {
		unset( $default_value );

		if ( false !== $pre_option ) {
			return $pre_option;
		}

		$blog_id = get_current_blog_id();
		if ( isset( self::$blog_options[ $blog_id ] ) && array_key_exists( $option, self::$blog_options[ $blog_id ] ) ) {
			return self::$blog_options[ $blog_id ][ $option ];
		}

		return $pre_option;
	}

	public static function filter_network_option( $pre_option, string $option, int $network_id, $default_value = false ) {
		unset( $default_value );

		if ( false !== $pre_option ) {
			return $pre_option;
		}

		if ( isset( self::$network_options[ $network_id ] ) && array_key_exists( $option, self::$network_options[ $network_id ] ) ) {
			return self::$network_options[ $network_id ][ $option ];
		}

		return $pre_option;
	}

	public static function filter_main_site_id( $main_site_id, \WP_Network $network ) {
		if ( isset( self::$main_site_ids[ $network->id ] ) ) {
			return self::$main_site_ids[ $network->id ];
		}

		return $main_site_id;
	}

	public static function filter_sites_pre_query( $site_data, \WP_Site_Query $query ) {
		unset( $site_data );

		$sites = array_values(
			array_filter(
				self::$sites,
				static function ( \WP_Site $site ) use ( $query ): bool {
					return self::site_matches_query( $site, $query->query_vars );
				}
			)
		);

		$total                  = count( $sites );
		$query->found_sites    = $total;
		$query->max_num_pages  = self::max_pages( $total, $query->query_vars['number'] ?? 0 );
		$sites                 = self::slice_results( $sites, $query->query_vars );

		if ( ! empty( $query->query_vars['count'] ) ) {
			return $total;
		}

		if ( 'ids' === $query->query_vars['fields'] ) {
			return array_map(
				static function ( \WP_Site $site ): int {
					return $site->id;
				},
				$sites
			);
		}

		return $sites;
	}

	public static function filter_networks_pre_query( $network_data, \WP_Network_Query $query ) {
		unset( $network_data );

		$networks = array_values(
			array_filter(
				self::$networks,
				static function ( \WP_Network $network ) use ( $query ): bool {
					return self::network_matches_query( $network, $query->query_vars );
				}
			)
		);

		$total                     = count( $networks );
		$query->found_networks    = $total;
		$query->max_num_pages     = self::max_pages( $total, $query->query_vars['number'] ?? 0 );
		$networks                 = self::slice_results( $networks, $query->query_vars );

		if ( ! empty( $query->query_vars['count'] ) ) {
			return $total;
		}

		if ( 'ids' === $query->query_vars['fields'] ) {
			return array_map(
				static function ( \WP_Network $network ): int {
					return $network->id;
				},
				$networks
			);
		}

		return $networks;
	}

	public static function filter_site_by_path( $pre, string $domain, string $path, ?int $segments, array $paths ) {
		unset( $pre );

		self::$path_lookup_log[] = array(
			'type'     => 'site',
			'domain'   => $domain,
			'path'     => $path,
			'segments' => $segments,
			'paths'    => $paths,
		);

		$matches = array();
		foreach ( self::$sites as $site ) {
			if ( ! self::domain_matches_request( $site->domain, $domain ) ) {
				continue;
			}

			if ( in_array( $site->path, $paths, true ) ) {
				$matches[] = $site;
			}
		}

		if ( array() === $matches ) {
			return false;
		}

		usort(
			$matches,
			static function ( \WP_Site $left, \WP_Site $right ): int {
				return ( strlen( $right->domain ) <=> strlen( $left->domain ) )
					?: ( strlen( $right->path ) <=> strlen( $left->path ) )
					?: ( $left->id <=> $right->id );
			}
		);

		return $matches[0];
	}

	public static function filter_network_by_path( $pre, string $domain, string $path, ?int $segments, array $paths ) {
		unset( $pre );

		self::$path_lookup_log[] = array(
			'type'     => 'network',
			'domain'   => $domain,
			'path'     => $path,
			'segments' => $segments,
			'paths'    => $paths,
		);

		$matches = array();
		foreach ( self::$networks as $network ) {
			if ( ! self::domain_matches_request( $network->domain, $domain, true ) ) {
				continue;
			}

			if ( in_array( $network->path, $paths, true ) ) {
				$matches[] = $network;
			}
		}

		if ( array() === $matches ) {
			return false;
		}

		usort(
			$matches,
			static function ( \WP_Network $left, \WP_Network $right ): int {
				return ( strlen( $right->domain ) <=> strlen( $left->domain ) )
					?: ( strlen( $right->path ) <=> strlen( $left->path ) )
					?: ( $left->id <=> $right->id );
			}
		);

		return $matches[0];
	}

	public static function filter_blogs_of_user( $sites, int $user_id, bool $all ) {
		if ( ! isset( self::$user_blog_memberships[ $user_id ] ) ) {
			return $sites;
		}

		$user_sites = array();
		foreach ( self::$user_blog_memberships[ $user_id ] as $site_id ) {
			$site = self::site_by_id( $site_id );
			if ( ! $site instanceof \WP_Site ) {
				continue;
			}

			if ( ! $all && ( $site->archived || $site->spam || $site->deleted ) ) {
				continue;
			}

			$user_sites[ $site->id ] = (object) array(
				'userblog_id' => $site->id,
				'blogname'    => self::$blog_options[ $site->id ]['blogname'] ?? '',
				'domain'      => $site->domain,
				'path'        => $site->path,
				'site_id'     => $site->network_id,
				'siteurl'     => self::$blog_options[ $site->id ]['siteurl'] ?? '',
				'archived'    => $site->archived,
				'mature'      => $site->mature,
				'spam'        => $site->spam,
				'deleted'     => $site->deleted,
			);
		}

		return $user_sites;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP_Site', 'WP_Network', 'WP_Site_Query', 'WP_Network_Query', 'Component_Fuzz_WPDB_Stub' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'add_blog_option',
				'add_site_option',
				'add_network_option',
				'delete_blog_option',
				'delete_site_option',
				'delete_network_option',
				'get_admin_url',
				'get_blog_option',
				'get_blog_details',
				'get_blogaddress_by_id',
				'get_blogaddress_by_name',
				'get_blog_id_from_url',
				'get_blogs_of_user',
				'get_blog_count',
				'get_current_blog_id',
				'get_id_from_blogname',
				'get_network_by_path',
				'get_network',
				'get_network_option',
				'get_networks',
				'get_site_by_path',
				'get_site',
				'get_site_option',
				'get_site_url',
				'get_sites',
				'get_user_count',
				'has_filter',
				'is_multisite',
				'domain_exists',
				'ms_load_current_site_and_network',
				'network_home_url',
				'network_site_url',
				'remove_filter',
				'restore_current_blog',
				'switch_to_blog',
				'update_blog_option',
				'update_site_option',
				'update_network_option',
				'wp_cache_add_global_groups',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_get_last_changed',
				'wp_cache_get_multiple',
				'wp_cache_init',
				'wp_cache_set',
				'wp_is_large_network',
				'wp_is_large_user_count',
				'wp_normalize_site_data',
				'wp_upload_dir',
				'wp_parse_id_list',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_site_objects_and_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		$site       = self::$sites[0];
		$raw        = array(
			'domain'       => "Bad Domain!{$ctx->int( 10, 99 )}.Example.TEST\n",
			'path'         => 'nested//site ' . $ctx->int( 1, 9 ),
			'network_id'   => (string) $site->network_id,
			'public'       => '1',
			'archived'     => false,
			'mature'       => '0',
			'spam'         => '0',
			'deleted'      => '1',
			'registered'   => '0000-00-00 00:00:00',
			'last_updated' => '',
		);
		$normalized = wp_normalize_site_data( $raw );
		$derived    = self::site_from_normalized( 7000 + $ctx->int( 1, 999 ), $normalized );
		$from_cache = get_site( $site->id );
		$from_object = get_site( $derived );
		$array      = $derived->to_array();

		$status_fields = array( 'public', 'archived', 'mature', 'spam', 'deleted' );
		$statuses_are_ints = true;
		foreach ( $status_fields as $status_field ) {
			$statuses_are_ints = $statuses_are_ints && isset( $array[ $status_field ] ) && is_int( $array[ $status_field ] );
		}

		$ok = $from_cache instanceof \WP_Site
			&& $from_cache->id === $site->id
			&& $from_cache->domain === $site->domain
			&& $from_object === $derived
			&& $derived->id === (int) $derived->blog_id
			&& $derived->network_id === (int) $derived->site_id
			&& 1 === preg_match( '/^[a-z0-9\-.:]+$/i', $normalized['domain'] )
			&& str_starts_with( $normalized['path'], '/' )
			&& str_ends_with( $normalized['path'], '/' )
			&& ! isset( $normalized['registered'], $normalized['last_updated'] )
			&& $statuses_are_ints;

		return $ctx->result(
			'multisite.site.object-normalization-and-cache-lookup',
			$ok,
			array(
				'raw'        => self::describe_value( $raw ),
				'normalized' => self::describe_value( $normalized ),
				'siteArray'  => self::describe_value( $array ),
				'fromCache'  => self::describe_site( $from_cache ),
			)
		);
	}

	private static function check_network_objects_and_main_site( \ComponentFuzz\FuzzContext $ctx ): array {
		$network_id = 9000 + $ctx->int( 1, 999 );
		$main_site  = 8000 + $ctx->int( 1, 999 );

		self::$main_site_ids[ $network_id ] = $main_site;
		self::$network_options[ $network_id ] = array(
			'site_name' => 'Filtered Network ' . $network_id,
		);

		$network = new \WP_Network(
			(object) array(
				'id'      => (string) $network_id,
				'domain'  => 'https://www.network-' . $network_id . '.example.test',
				'path'    => '/network-' . $network_id . '/',
				'blog_id' => '0',
			)
		);

		$cached_raw = (object) array(
			'id'            => '2',
			'domain'        => 'network-two.example.test',
			'path'          => '/two/',
			'blog_id'       => '23',
			'cookie_domain' => '',
			'site_name'     => 'Cached Network Two',
		);
		wp_cache_set( 2, $cached_raw, 'networks' );
		$from_cache = get_network( 2 );

		$public_vars = get_object_vars( $network );

		$ok = $network->id === $network_id
			&& $network->site_id === $main_site
			&& (string) $main_site === $network->blog_id
			&& 'Filtered Network ' . $network_id === $network->site_name
			&& 'network-' . $network_id . '.example.test' === $network->cookie_domain
			&& ! array_key_exists( 'id', $public_vars )
			&& $from_cache instanceof \WP_Network
			&& 2 === $from_cache->id
			&& 23 === $from_cache->site_id
			&& 'network-two.example.test' === $from_cache->cookie_domain;

		return $ctx->result(
			'multisite.network.object-magic-main-site-and-cache-lookup',
			$ok,
			array(
				'network'    => self::describe_network( $network ),
				'publicVars' => self::describe_value( $public_vars ),
				'fromCache'  => self::describe_network( $from_cache ),
			)
		);
	}

	private static function check_generated_site_network_fixture( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$site          = get_site( $case['siteId'] );
		$network       = get_network( $case['networkId'] );
		$site_query    = get_sites(
			array(
				'domain'                 => $case['siteDomain'],
				'path'                   => $case['sitePath'],
				'number'                 => 1,
				'update_site_meta_cache' => false,
			)
		);
		$network_query = get_networks(
			array(
				'network__in' => array( $case['networkId'] ),
				'number'      => 1,
			)
		);
		$normalized    = wp_normalize_site_data( $case['rawSiteData'] );

		if ( ! $site instanceof \WP_Site ) {
			$failures[] = 'generated site cache lookup did not return WP_Site';
		} elseif (
			$site->id !== $case['siteId']
			|| $site->network_id !== $case['networkId']
			|| $site->domain !== $case['siteDomain']
			|| $site->path !== $case['sitePath']
			|| 0 !== (int) $site->archived
			|| 0 !== (int) $site->spam
			|| 0 !== (int) $site->deleted
		) {
			$failures[] = 'generated site fields diverged from fixture';
		}

		if ( ! $network instanceof \WP_Network ) {
			$failures[] = 'generated network cache lookup did not return WP_Network';
		} elseif (
			$network->id !== $case['networkId']
			|| $network->site_id !== $case['siteId']
			|| $network->domain !== $case['networkDomain']
			|| $network->path !== $case['networkPath']
			|| $network->site_name !== 'Generated Network ' . $case['caseSeed']
		) {
			$failures[] = 'generated network fields diverged from fixture';
		}

		if ( 1 !== count( $site_query ) || ! $site_query[0] instanceof \WP_Site || $site_query[0]->id !== $case['siteId'] ) {
			$failures[] = 'site pre-query filter did not return the generated site';
		}

		if ( 1 !== count( $network_query ) || ! $network_query[0] instanceof \WP_Network || $network_query[0]->id !== $case['networkId'] ) {
			$failures[] = 'network pre-query filter did not return the generated network';
		}

		if (
			$normalized['domain'] !== $case['normalizedRawDomain']
			|| $normalized['path'] !== $case['normalizedRawPath']
			|| $normalized['network_id'] !== $case['networkId']
			|| isset( $normalized['registered'], $normalized['last_updated'] )
		) {
			$failures[] = 'generated raw site data normalization changed contract';
		}

		return $ctx->result(
			'multisite.generated-fixture.site-network-cache-query-and-normalization',
			array() === $failures,
			array(
				'case'       => self::describe_case( $case ),
				'failures'   => $failures,
				'site'       => self::describe_site( $site ),
				'network'    => self::describe_network( $network ),
				'normalized' => self::describe_value( $normalized ),
			)
		);
	}

	private static function check_legacy_blog_identity_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures    = array();
		$events      = array();
		$start_blog  = get_current_blog_id();
		$start_stack = $GLOBALS['_wp_switched_stack'];
		$had_ms_loaded_action = isset( $GLOBALS['wp_actions'] ) && array_key_exists( 'ms_loaded', $GLOBALS['wp_actions'] );
		$ms_loaded_action     = $GLOBALS['wp_actions']['ms_loaded'] ?? null;
		$cache_probe_calls    = array();
		$cache_probe_filter   = static function ( $site_data, \WP_Site_Query $query ) use ( &$cache_probe_calls ): array {
			unset( $site_data );

			$cache_probe_calls[] = $query->query_vars;

			return array( 999999 );
		};
		$domain_filter = static function ( $result, string $domain, string $path, int $network_id ) use ( &$events ) {
			$events[] = array(
				'result'    => $result,
				'domain'    => $domain,
				'path'      => $path,
				'networkId' => $network_id,
			);

			return $result;
		};

		add_filter( 'domain_exists', $domain_filter, 10, 4 );

		try {
			$GLOBALS['wp_actions']['ms_loaded'] = max( 1, (int) ( $GLOBALS['wp_actions']['ms_loaded'] ?? 0 ) );

			$short_details = get_blog_details( $case['siteId'], false );
			$full_details  = get_blog_details( $case['siteId'], true );
			$current       = get_blog_details();

			wp_cache_set( md5( 'www.' . $case['siteDomain'] . $case['sitePath'] ), $full_details, 'blog-lookup' );
			$cached_lookup = get_blog_details(
				array(
					'domain' => 'www.' . $case['siteDomain'],
					'path'   => $case['sitePath'],
				)
			);

			$address_by_id      = get_blogaddress_by_id( $case['siteId'] );
			$address_by_name    = get_blogaddress_by_name( $case['directorySlug'] );
			$id_from_blogname   = get_id_from_blogname( $case['directorySlug'] );
			$id_from_url        = get_blog_id_from_url( strtoupper( 'example.test' ), strtoupper( $case['directoryPath'] ) );
			$missing_url_id     = get_blog_id_from_url( 'missing.' . $case['siteDomain'], '/missing/' );

			remove_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ), 10 );
			add_filter( 'sites_pre_query', $cache_probe_filter, 10, 2 );
			$id_from_url_again  = get_blog_id_from_url( 'example.test', $case['directoryPath'] );
			$missing_url_cached = get_blog_id_from_url( 'missing.' . $case['siteDomain'], '/missing/' );
			remove_filter( 'sites_pre_query', $cache_probe_filter, 10 );
			add_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ), 10, 2 );

			$domain_hit         = domain_exists( 'example.test', untrailingslashit( $case['directoryPath'] ), 1 );
			$domain_miss        = domain_exists( 'missing.' . $case['siteDomain'], '/missing', $case['networkId'] );
		} finally {
			remove_filter( 'sites_pre_query', $cache_probe_filter, 10 );
			if ( false === has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) ) ) {
				add_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ), 10, 2 );
			}
			remove_filter( 'domain_exists', $domain_filter, 10 );
			self::clear_legacy_blog_identity_caches( $case );
			if ( $had_ms_loaded_action ) {
				$GLOBALS['wp_actions']['ms_loaded'] = $ms_loaded_action;
			} else {
				unset( $GLOBALS['wp_actions']['ms_loaded'] );
			}
			while ( ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
				restore_current_blog();
			}
		}

		if (
			! $short_details instanceof \WP_Site
			|| $short_details->id !== $case['siteId']
			|| array_key_exists( 'blogname', get_object_vars( $short_details ) )
		) {
			$failures[] = 'short blog details did not return only site-table fields';
		}

		if (
			! $full_details instanceof \WP_Site
			|| $full_details->id !== $case['siteId']
			|| $full_details->blogname !== 'Generated Component Fuzz Site ' . $case['caseSeed']
			|| $full_details->siteurl !== 'http://' . $case['siteDomain'] . untrailingslashit( $case['sitePath'] ) . '/wp'
			|| $full_details->home !== 'http://' . $case['siteDomain'] . untrailingslashit( $case['sitePath'] )
			|| (int) $full_details->post_count !== $case['postCount']
		) {
			$failures[] = 'full blog details did not hydrate filtered site options';
		}

		if ( ! $current instanceof \WP_Site || 1 !== $current->id || 'Main Component Fuzz Site' !== $current->blogname ) {
			$failures[] = 'current blog details did not use current blog option context';
		}

		if ( ! $cached_lookup instanceof \WP_Site || $cached_lookup->id !== $case['siteId'] ) {
			$failures[] = 'domain/path blog-details cache lookup did not return generated site';
		}

		if ( 'http://' . $case['siteDomain'] . $case['sitePath'] !== $address_by_id ) {
			$failures[] = 'get_blogaddress_by_id did not compose generated site URL';
		}

		if ( 'http://example.test/' . $case['directorySlug'] . '/' !== $address_by_name ) {
			$failures[] = 'get_blogaddress_by_name did not compose subdirectory network URL';
		}

		if (
			$case['directorySiteId'] !== $id_from_blogname
			|| $case['directorySiteId'] !== $id_from_url
			|| $case['directorySiteId'] !== $id_from_url_again
			|| 0 !== $missing_url_id
			|| 0 !== $missing_url_cached
		) {
			$failures[] = 'blog slug and URL ID helpers did not hit/cache expected IDs';
		}

		if ( array() !== $cache_probe_calls ) {
			$failures[] = 'cached blog URL ID helpers unexpectedly queried sites after cache priming';
		}

		if ( $case['directorySiteId'] !== $domain_hit || null !== $domain_miss ) {
			$failures[] = 'domain_exists hit/miss semantics diverged from generated site fixture';
		}

		if (
			2 !== count( $events )
			|| ( $events[0]['result'] ?? null ) !== $case['directorySiteId']
			|| ( $events[0]['path'] ?? null ) !== $case['directoryPath']
			|| null !== ( $events[1]['result'] ?? null )
			|| ( $events[1]['path'] ?? null ) !== '/missing/'
		) {
			$failures[] = 'domain_exists filter did not observe expected normalized payloads';
		}

		if ( $start_blog !== get_current_blog_id() || $start_stack !== $GLOBALS['_wp_switched_stack'] || false !== $GLOBALS['switched'] ) {
			$failures[] = 'legacy blog identity helpers did not restore blog switch globals';
		}

		return $ctx->result(
			'multisite.legacy-blog-identity.address-details-domain-and-url-helpers',
			array() === $failures,
			array(
				'case'            => self::describe_case( $case ),
				'failures'        => $failures,
				'shortDetails'    => self::describe_site( $short_details ?? null ),
				'fullDetails'     => self::describe_site( $full_details ?? null ),
				'addressById'     => $address_by_id ?? null,
				'addressByName'   => $address_by_name ?? null,
				'idFromBlogname'  => $id_from_blogname ?? null,
				'idFromUrl'       => $id_from_url ?? null,
				'missingUrlId'    => $missing_url_id ?? null,
				'cacheProbeCalls' => self::describe_value( $cache_probe_calls ),
				'domainHit'       => $domain_hit ?? null,
				'domainMiss'      => self::describe_value( $domain_miss ?? null ),
				'domainEvents'    => self::describe_value( $events ),
			)
		);
	}

	private static function check_bootstrap_resolution_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( defined( 'DOMAIN_CURRENT_SITE' ) && defined( 'PATH_CURRENT_SITE' ) ) {
			return $ctx->skip(
				'multisite.bootstrap-resolution.current-site-network',
				'DOMAIN_CURRENT_SITE/PATH_CURRENT_SITE constants force the constants branch.'
			);
		}

		$failures     = array();
		$site_events  = array();
		$snapshot     = array(
			'currentSite'  => $GLOBALS['current_site'] ?? null,
			'currentBlog'  => $GLOBALS['current_blog'] ?? null,
			'pathLog'      => self::$path_lookup_log,
			'installing'   => function_exists( 'wp_installing' ) ? wp_installing() : null,
			'httpsExists'  => array_key_exists( 'HTTPS', $_SERVER ),
			'https'        => $_SERVER['HTTPS'] ?? null,
		);
		$site_not_found = static function ( \WP_Network $current_site, string $domain, string $path ) use ( &$site_events ): void {
			$site_events[] = array(
				'networkId' => $current_site->id,
				'domain'    => $domain,
				'path'      => $path,
			);
		};

		add_action( 'ms_site_not_found', $site_not_found, 10, 3 );

		try {
			if ( function_exists( 'wp_installing' ) ) {
				wp_installing( false );
			}
			unset( $_SERVER['HTTPS'] );

			self::$path_lookup_log = array();
			wp_cache_delete( 'current_network', 'site-options' );
			$GLOBALS['current_site'] = null;
			$GLOBALS['current_blog'] = null;
			$subdirectory_loaded = ms_load_current_site_and_network( 'example.test', $case['directoryPath'] . 'child/', false );
			$subdirectory_site   = $GLOBALS['current_site'] ?? null;
			$subdirectory_blog   = $GLOBALS['current_blog'] ?? null;
			$subdirectory_log    = self::$path_lookup_log;

			self::$path_lookup_log = array();
			wp_cache_delete( 'current_network', 'site-options' );
			$GLOBALS['current_site'] = null;
			$GLOBALS['current_blog'] = null;
			$subdomain_loaded = ms_load_current_site_and_network( 'blog17.example.test', '/child/', true );
			$subdomain_site   = $GLOBALS['current_site'] ?? null;
			$subdomain_blog   = $GLOBALS['current_blog'] ?? null;
			$subdomain_log    = self::$path_lookup_log;

			self::$path_lookup_log = array();
			wp_cache_delete( 'current_network', 'site-options' );
			$GLOBALS['current_site'] = null;
			$GLOBALS['current_blog'] = null;
			$missing_result = ms_load_current_site_and_network( 'missing.example.test', '/missing/', true );
			$missing_site   = $GLOBALS['current_site'] ?? null;
			$missing_blog   = $GLOBALS['current_blog'] ?? null;
			$missing_log    = self::$path_lookup_log;
		} finally {
			remove_action( 'ms_site_not_found', $site_not_found, 10 );
			wp_cache_delete( 'current_network', 'site-options' );
			self::$path_lookup_log = $snapshot['pathLog'];
			$GLOBALS['current_site'] = $snapshot['currentSite'];
			$GLOBALS['current_blog'] = $snapshot['currentBlog'];
			if ( function_exists( 'wp_installing' ) && is_bool( $snapshot['installing'] ) ) {
				wp_installing( $snapshot['installing'] );
			}
			if ( $snapshot['httpsExists'] ) {
				$_SERVER['HTTPS'] = $snapshot['https'];
			} else {
				unset( $_SERVER['HTTPS'] );
			}
		}

		if (
			true !== $subdirectory_loaded
			|| ! $subdirectory_site instanceof \WP_Network
			|| 1 !== $subdirectory_site->id
			|| ! $subdirectory_blog instanceof \WP_Site
			|| $case['directorySiteId'] !== $subdirectory_blog->id
		) {
			$failures[] = 'subdirectory bootstrap did not resolve current network and generated directory site';
		}

		if (
			! self::lookup_log_contains( $subdirectory_log, 'network', 'example.test', '/' )
			|| ! self::lookup_log_contains( $subdirectory_log, 'site', 'example.test', $case['directoryPath'] )
		) {
			$failures[] = 'subdirectory bootstrap did not perform expected network and site path lookups';
		}

		if (
			true !== $subdomain_loaded
			|| ! $subdomain_site instanceof \WP_Network
			|| 1 !== $subdomain_site->id
			|| ! $subdomain_blog instanceof \WP_Site
			|| 17 !== $subdomain_blog->id
		) {
			$failures[] = 'subdomain bootstrap did not resolve site first and then network';
		}

		if (
			! self::lookup_log_contains( $subdomain_log, 'site', 'blog17.example.test', '/' )
			|| self::lookup_log_contains_type( $subdomain_log, 'network' )
		) {
			$failures[] = 'subdomain bootstrap lookup path did not stay on the site-first branch';
		}

		if ( defined( 'NOBLOGREDIRECT' ) ) {
			$expected_missing_result = '%siteurl%' === NOBLOGREDIRECT ? 'http://example.test/' : NOBLOGREDIRECT;
		} else {
			$expected_missing_result = 'http://example.test/wp-signup.php?new=missing';
		}

		if ( $expected_missing_result !== $missing_result ) {
			$failures[] = 'missing subdomain bootstrap did not return expected signup redirect or fail-closed value';
		}

		if (
			! $missing_site instanceof \WP_Network
			|| 1 !== $missing_site->id
			|| ! empty( $missing_blog )
			|| 1 !== count( $site_events )
			|| ( $site_events[0]['networkId'] ?? null ) !== 1
			|| ( $site_events[0]['domain'] ?? null ) !== 'missing.example.test'
			|| ( $site_events[0]['path'] ?? null ) !== '/missing/'
		) {
			$failures[] = 'missing subdomain bootstrap did not fire expected site-not-found payload';
		}

		if (
			! self::lookup_log_contains( $missing_log, 'site', 'missing.example.test', '/' )
			|| ! self::lookup_log_contains( $missing_log, 'network', 'missing.example.test', '/' )
		) {
			$failures[] = 'missing subdomain bootstrap did not perform expected site and network lookups';
		}

		if ( ( $GLOBALS['current_site'] ?? null ) !== $snapshot['currentSite'] || ( $GLOBALS['current_blog'] ?? null ) !== $snapshot['currentBlog'] ) {
			$failures[] = 'bootstrap resolution check did not restore current site/blog globals';
		}

		return $ctx->result(
			'multisite.bootstrap-resolution.current-site-network',
			array() === $failures,
			array(
				'case'            => self::describe_case( $case ),
				'failures'        => $failures,
				'subdirectory'    => array(
					'result'  => $subdirectory_loaded ?? null,
					'network' => self::describe_network( $subdirectory_site ?? null ),
					'site'    => self::describe_site( $subdirectory_blog ?? null ),
					'log'     => self::describe_value( $subdirectory_log ?? array() ),
				),
				'subdomain'       => array(
					'result'  => $subdomain_loaded ?? null,
					'network' => self::describe_network( $subdomain_site ?? null ),
					'site'    => self::describe_site( $subdomain_blog ?? null ),
					'log'     => self::describe_value( $subdomain_log ?? array() ),
				),
				'missing'         => array(
					'result'  => $missing_result ?? null,
					'expectedResult' => $expected_missing_result ?? null,
					'network' => self::describe_network( $missing_site ?? null ),
					'site'    => self::describe_site( $missing_blog ?? null ),
					'log'     => self::describe_value( $missing_log ?? array() ),
				),
				'siteNotFound'    => $site_events,
			)
		);
	}

	private static function check_switch_stack_and_cache_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$events = array();
		$action = static function ( int $new_blog_id, int $prev_blog_id, string $context ) use ( &$events ): void {
			$events[] = array(
				'new'     => $new_blog_id,
				'prev'    => $prev_blog_id,
				'context' => $context,
				'stack'   => $GLOBALS['_wp_switched_stack'],
			);
		};
		add_action( 'switch_blog', $action, 10, 3 );

		$key   = 'component-fuzz-switch-' . $ctx->seed();
		$group = 'component-fuzz-multisite-switch';

		try {
			$start_blog = get_current_blog_id();
			$start_stack = $GLOBALS['_wp_switched_stack'];

			$first_switch = switch_to_blog( 17 );
			wp_cache_set( $key, 'blog-17', $group );
			$blog_17_prefix = $GLOBALS['table_prefix'];

			$second_switch = switch_to_blog( 23 );
			$blog_23_missing_17 = wp_cache_get( $key, $group );
			wp_cache_set( $key, 'blog-23', $group );
			$blog_23_prefix = $GLOBALS['table_prefix'];

			$first_restore = restore_current_blog();
			$blog_17_again = wp_cache_get( $key, $group );

			$second_restore = restore_current_blog();
			$blog_1_again = wp_cache_get( $key, $group );

			$empty_restore = restore_current_blog();
		} finally {
			remove_action( 'switch_blog', $action, 10 );
			while ( ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
				restore_current_blog();
			}
		}

		$ok = true === $first_switch
			&& true === $second_switch
			&& false === $blog_23_missing_17
			&& 'blog-17' === $blog_17_again
			&& false === $blog_1_again
			&& true === $first_restore
			&& true === $second_restore
			&& false === $empty_restore
			&& 1 === get_current_blog_id()
			&& false === $GLOBALS['switched']
			&& $start_stack === $GLOBALS['_wp_switched_stack']
			&& 'wp_17_' === $blog_17_prefix
			&& 'wp_23_' === $blog_23_prefix
			&& array(
				array( 'new' => 17, 'prev' => $start_blog, 'context' => 'switch' ),
				array( 'new' => 23, 'prev' => 17, 'context' => 'switch' ),
				array( 'new' => 17, 'prev' => 23, 'context' => 'restore' ),
				array( 'new' => $start_blog, 'prev' => 17, 'context' => 'restore' ),
			) === array_map(
				static function ( array $event ): array {
					return array(
						'new'     => $event['new'],
						'prev'    => $event['prev'],
						'context' => $event['context'],
					);
				},
				$events
			);

		return $ctx->result(
			'multisite.switch.stack-globals-and-cache-context-restore',
			$ok,
			array(
				'events'        => self::describe_value( $events ),
				'blog17Prefix'  => $blog_17_prefix ?? null,
				'blog23Prefix'  => $blog_23_prefix ?? null,
				'blog23Read17'  => self::describe_value( $blog_23_missing_17 ?? null ),
				'blog17Restored' => self::describe_value( $blog_17_again ?? null ),
				'blog1Restored' => self::describe_value( $blog_1_again ?? null ),
			)
		);
	}

	private static function check_blog_option_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures    = array();
		$start_blog  = get_current_blog_id();
		$start_stack = $GLOBALS['_wp_switched_stack'];
		$blog_option = 'component_fuzz_blog_option_' . $case['caseSeed'];
		$site_option = 'component_fuzz_site_option_' . $case['caseSeed'];
		$first_value = array(
			'seed' => $case['caseSeed'],
			'site' => $case['siteId'],
		);
		$second_value = array(
			'seed'    => $case['caseSeed'],
			'updated' => true,
			'path'    => $case['sitePath'],
		);

		delete_blog_option( $case['siteId'], $blog_option );
		$blog_added       = add_blog_option( $case['siteId'], $blog_option, $first_value );
		$blog_read_first  = get_blog_option( $case['siteId'], $blog_option, 'fallback' );
		$blog_updated     = update_blog_option( $case['siteId'], $blog_option, $second_value );
		$blog_read_second = get_blog_option( $case['siteId'], $blog_option, 'fallback' );
		$blog_same_update = update_blog_option( $case['siteId'], $blog_option, $second_value );
		$blog_deleted     = delete_blog_option( $case['siteId'], $blog_option );
		$blog_read_gone   = get_blog_option( $case['siteId'], $blog_option, 'fallback' );
		$filtered_name    = get_blog_option( $case['siteId'], 'blogname', 'fallback' );

		delete_site_option( $site_option );
		$site_added       = add_site_option( $site_option, 'network-first-' . $case['caseSeed'] );
		$site_read_first  = get_site_option( $site_option, 'fallback' );
		$site_updated     = update_site_option( $site_option, 'network-second-' . $case['caseSeed'] );
		$site_read_second = get_site_option( $site_option, 'fallback' );
		$site_same_update = update_site_option( $site_option, 'network-second-' . $case['caseSeed'] );
		$site_deleted     = delete_site_option( $site_option );
		$site_read_gone   = get_site_option( $site_option, 'fallback' );

		if ( true !== $blog_added || ! self::same_value( $first_value, $blog_read_first ) ) {
			$failures[] = 'add/get blog option did not round-trip through switched stub context';
		}
		if ( true !== $blog_updated || ! self::same_value( $second_value, $blog_read_second ) || false !== $blog_same_update ) {
			$failures[] = 'update blog option did not preserve expected changed/same-value semantics';
		}
		if ( true !== $blog_deleted || 'fallback' !== $blog_read_gone ) {
			$failures[] = 'delete blog option did not remove stub-backed value';
		}
		if ( 'Generated Component Fuzz Site ' . $case['caseSeed'] !== $filtered_name ) {
			$failures[] = 'filtered blog option did not follow generated blog context';
		}
		if (
			true !== $site_added
			|| 'network-first-' . $case['caseSeed'] !== $site_read_first
			|| true !== $site_updated
			|| 'network-second-' . $case['caseSeed'] !== $site_read_second
			|| false !== $site_same_update
			|| true !== $site_deleted
			|| 'fallback' !== $site_read_gone
		) {
			$failures[] = 'site option aliases did not follow network option CRUD semantics';
		}
		if ( $start_blog !== get_current_blog_id() || $start_stack !== $GLOBALS['_wp_switched_stack'] || false !== $GLOBALS['switched'] ) {
			$failures[] = 'blog option helper did not restore blog switch globals';
		}

		return $ctx->result(
			'multisite.blog-options.site-options-stub-crud-and-switch-restore',
			array() === $failures,
			array(
				'case'             => self::describe_case( $case ),
				'failures'         => $failures,
				'blogAdded'        => $blog_added,
				'blogUpdated'      => $blog_updated,
				'blogSameUpdate'   => $blog_same_update,
				'blogDeleted'      => $blog_deleted,
				'blogReadGone'     => self::describe_value( $blog_read_gone ),
				'siteAdded'        => $site_added,
				'siteUpdated'      => $site_updated,
				'siteSameUpdate'   => $site_same_update,
				'siteDeleted'      => $site_deleted,
				'currentBlogId'    => get_current_blog_id(),
				'switchedStack'    => self::describe_value( $GLOBALS['_wp_switched_stack'] ),
			)
		);
	}

	private static function check_network_option_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$network_id = 2;
		$filtered_option = 'component_fuzz_filtered_' . $ctx->seed();
		$crud_option = 'component_fuzz_network_option_' . $ctx->seed();
		$value = array(
			'seed'  => $ctx->seed(),
			'label' => 'network option',
		);

		self::$network_options[ $network_id ][ $filtered_option ] = $value;

		$filtered = get_network_option( $network_id, $filtered_option, 'fallback' );
		$invalid_network = get_network_option( 'not-a-network', $filtered_option, 'fallback' );
		$missing_default = get_network_option( $network_id, $filtered_option . '_missing', 'fallback' );

		delete_network_option( null, $crud_option );
		$added      = add_network_option( null, $crud_option, 'first' );
		$read_first = get_network_option( null, $crud_option );
		$updated    = update_network_option( null, $crud_option, 'second' );
		$read_second = get_network_option( null, $crud_option );
		$same_update = update_network_option( null, $crud_option, 'second' );
		$deleted    = delete_network_option( null, $crud_option );
		$read_gone  = get_network_option( null, $crud_option, 'fallback' );

		$ok = self::same_value( $value, $filtered )
			&& false === $invalid_network
			&& 'fallback' === $missing_default
			&& true === $added
			&& 'first' === $read_first
			&& true === $updated
			&& 'second' === $read_second
			&& false === $same_update
			&& true === $deleted
			&& 'fallback' === $read_gone;

		return $ctx->result(
			'multisite.network-options.filters-and-stub-backed-crud',
			$ok,
			array(
				'filtered'       => self::describe_value( $filtered ),
				'invalidNetwork' => self::describe_value( $invalid_network ),
				'missingDefault' => self::describe_value( $missing_default ),
				'added'          => $added,
				'updated'        => $updated,
				'sameUpdate'     => $same_update,
				'deleted'        => $deleted,
				'readGone'       => self::describe_value( $read_gone ),
			)
		);
	}

	private static function check_large_network_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$network_id        = $case['networkId'];
		$small_site_count  = $ctx->int( 1, 10000 );
		$large_site_count  = 10001 + $ctx->int( 0, 7000 );
		$forced_site_count = $ctx->int( 1, 9999 );
		$small_user_count  = $ctx->int( 0, 10000 );
		$large_user_count  = 10001 + $ctx->int( 0, 7000 );
		$forced_user_count = $ctx->int( 1, 9999 );
		$events            = array();

		$network_filter = static function ( bool $is_large, string $component, int $count, int $filtered_network_id ) use ( &$events, $network_id, $forced_site_count ): bool {
			$events[] = array(
				'filter'    => 'wp_is_large_network',
				'component' => $component,
				'count'     => $count,
				'networkId' => $filtered_network_id,
				'incoming'  => $is_large,
			);

			if ( $filtered_network_id === $network_id && 'sites' === $component && $count === $forced_site_count ) {
				return true;
			}

			return $is_large;
		};
		$user_filter    = static function ( bool $is_large, int $count, ?int $filtered_network_id ) use ( &$events, $network_id, $forced_user_count ): bool {
			$events[] = array(
				'filter'    => 'wp_is_large_user_count',
				'component' => 'users',
				'count'     => $count,
				'networkId' => $filtered_network_id,
				'incoming'  => $is_large,
			);

			if ( $filtered_network_id === $network_id && $count === $forced_user_count ) {
				return true;
			}

			return $is_large;
		};

		\add_filter( 'wp_is_large_network', $network_filter, 10, 4 );
		\add_filter( 'wp_is_large_user_count', $user_filter, 10, 3 );

		try {
			self::$network_options[ $network_id ]['blog_count'] = $small_site_count;
			$sites_small = \wp_is_large_network( 'sites', $network_id );

			self::$network_options[ $network_id ]['blog_count'] = $large_site_count;
			$sites_large = \wp_is_large_network( 'sites', $network_id );

			self::$network_options[ $network_id ]['blog_count'] = $forced_site_count;
			$sites_forced = \wp_is_large_network( 'sites', $network_id );

			self::$network_options[ $network_id ]['user_count'] = $small_user_count;
			$users_small = \wp_is_large_network( 'users', $network_id );

			self::$network_options[ $network_id ]['user_count'] = $large_user_count;
			$users_large = \wp_is_large_network( 'users', $network_id );

			self::$network_options[ $network_id ]['user_count'] = $forced_user_count;
			$users_forced = \wp_is_large_network( 'users', $network_id );
		} finally {
			\remove_filter( 'wp_is_large_user_count', $user_filter, 10 );
			\remove_filter( 'wp_is_large_network', $network_filter, 10 );
		}

		$ok = false === $sites_small
			&& true === $sites_large
			&& true === $sites_forced
			&& false === $users_small
			&& true === $users_large
			&& true === $users_forced
			&& self::large_network_event_seen( $events, 'wp_is_large_network', 'sites', $small_site_count, $network_id, false )
			&& self::large_network_event_seen( $events, 'wp_is_large_network', 'sites', $large_site_count, $network_id, true )
			&& self::large_network_event_seen( $events, 'wp_is_large_network', 'sites', $forced_site_count, $network_id, false )
			&& self::large_network_event_seen( $events, 'wp_is_large_user_count', 'users', $small_user_count, $network_id, false )
			&& self::large_network_event_seen( $events, 'wp_is_large_user_count', 'users', $large_user_count, $network_id, true )
			&& self::large_network_event_seen( $events, 'wp_is_large_user_count', 'users', $forced_user_count, $network_id, false )
			&& self::large_network_event_seen( $events, 'wp_is_large_network', 'users', $forced_user_count, $network_id, true );

		return $ctx->result(
			'multisite.large-network.thresholds-and-filter-payloads',
			$ok,
			array(
				'counts'  => array(
					'sitesSmall'  => $small_site_count,
					'sitesLarge'  => $large_site_count,
					'sitesForced' => $forced_site_count,
					'usersSmall'  => $small_user_count,
					'usersLarge'  => $large_user_count,
					'usersForced' => $forced_user_count,
				),
				'results' => array(
					'sitesSmall'  => $sites_small ?? null,
					'sitesLarge'  => $sites_large ?? null,
					'sitesForced' => $sites_forced ?? null,
					'usersSmall'  => $users_small ?? null,
					'usersLarge'  => $users_large ?? null,
					'usersForced' => $users_forced ?? null,
				),
				'events'  => $events,
			)
		);
	}

	private static function large_network_event_seen( array $events, string $filter, string $component, int $count, int $network_id, bool $incoming ): bool {
		foreach ( $events as $event ) {
			if (
				is_array( $event )
				&& $filter === ( $event['filter'] ?? null )
				&& $component === ( $event['component'] ?? null )
				&& $count === (int) ( $event['count'] ?? -1 )
				&& $network_id === (int) ( $event['networkId'] ?? 0 )
				&& $incoming === ( $event['incoming'] ?? null )
			) {
				return true;
			}
		}

		return false;
	}

	private static function lookup_log_contains( array $events, string $type, string $domain, string $path ): bool {
		foreach ( $events as $event ) {
			if (
				is_array( $event )
				&& $type === ( $event['type'] ?? null )
				&& $domain === ( $event['domain'] ?? null )
				&& in_array( $path, (array) ( $event['paths'] ?? array() ), true )
			) {
				return true;
			}
		}

		return false;
	}

	private static function lookup_log_contains_type( array $events, string $type ): bool {
		foreach ( $events as $event ) {
			if ( is_array( $event ) && $type === ( $event['type'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function check_site_and_network_queries( \ComponentFuzz\FuzzContext $ctx ): array {
		$wpdb = $GLOBALS['wpdb'];
		$before_query = is_object( $wpdb ) && property_exists( $wpdb, 'last_query' ) ? $wpdb->last_query : null;

		$site_ids = get_sites(
			array(
				'fields'                 => 'ids',
				'site__in'               => array( '1', '17', 'not-numeric' ),
				'number'                 => 2,
				'update_site_meta_cache' => false,
				'orderby'                => array( 'domain' => 'DESC', 'path_length' => 'ASC' ),
				'order'                  => 'sideways',
			)
		);
		$site_query = new \WP_Site_Query();
		$site_objects = $site_query->query(
			array(
				'domain'                 => self::$sites[1]->domain,
				'path'                   => self::$sites[1]->path,
				'number'                 => 1,
				'update_site_meta_cache' => false,
			)
		);
		$site_count = get_sites(
			array(
				'count'                  => true,
				'network_id'             => 1,
				'update_site_meta_cache' => false,
			)
		);

		$network_ids = get_networks(
			array(
				'fields'      => 'ids',
				'network__in' => array( '1', '2', 'bad' ),
				'number'      => 2,
				'orderby'     => 'domain_length',
				'order'       => 'ASC',
			)
		);
		$network_query = new \WP_Network_Query();
		$network_objects = $network_query->query(
			array(
				'domain' => self::$networks[1]->domain,
				'path'   => self::$networks[1]->path,
				'number' => 1,
			)
		);
		$network_count = get_networks( array( 'count' => true ) );

		$site_parse_query = new \WP_Site_Query();
		$site_parse_query->parse_query(
			array(
				'site__in'    => array( 17, 1 ),
				'network__in' => array( 1 ),
			)
		);
		$site_order_invalid = self::invoke_protected( $site_parse_query, 'parse_order', array( 'sideways' ) );
		$site_orderby_id    = self::invoke_protected( $site_parse_query, 'parse_orderby', array( 'id' ) );
		$site_orderby_bad   = self::invoke_protected( $site_parse_query, 'parse_orderby', array( 'not_allowed' ) );

		$network_parse_query = new \WP_Network_Query();
		$network_parse_query->parse_query( array( 'network__in' => array( 2, 1 ) ) );
		$network_order_empty = self::invoke_protected( $network_parse_query, 'parse_order', array( '' ) );
		$network_orderby_domain = self::invoke_protected( $network_parse_query, 'parse_orderby', array( 'domain' ) );
		$network_orderby_bad = self::invoke_protected( $network_parse_query, 'parse_orderby', array( 'not_allowed' ) );

		$after_query = is_object( $wpdb ) && property_exists( $wpdb, 'last_query' ) ? $wpdb->last_query : null;

		$ok = array( 1, 17 ) === $site_ids
			&& 1 === count( $site_objects )
			&& $site_objects[0] instanceof \WP_Site
			&& 17 === $site_objects[0]->id
			&& 3 === $site_count
			&& array( 1, 2 ) === $network_ids
			&& 1 === count( $network_objects )
			&& $network_objects[0] instanceof \WP_Network
			&& 2 === $network_objects[0]->id
			&& count( self::$networks ) === $network_count
			&& 'DESC' === $site_order_invalid
			&& is_string( $site_orderby_id )
			&& false === $site_orderby_bad
			&& 'ASC' === $network_order_empty
			&& is_string( $network_orderby_domain )
			&& false === $network_orderby_bad
			&& $before_query === $after_query;

		return $ctx->result(
			'multisite.queries.pre-query-short-circuit-and-arg-normalization',
			$ok,
			array(
				'siteIds'              => self::describe_value( $site_ids ),
				'siteCount'            => $site_count,
				'networkIds'           => self::describe_value( $network_ids ),
				'networkCount'         => $network_count,
				'siteOrderInvalid'     => self::describe_value( $site_order_invalid ),
				'siteOrderbyId'        => self::describe_value( $site_orderby_id ),
				'networkOrderEmpty'    => self::describe_value( $network_order_empty ),
				'networkOrderbyDomain' => self::describe_value( $network_orderby_domain ),
				'lastQueryUnchanged'   => $before_query === $after_query,
			)
		);
	}

	private static function check_path_lookup_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::$path_lookup_log = array();
		$site_request_path     = $case['sitePath'] . 'child/deep/';
		$network_request_path  = $case['networkPath'] . 'child/deep/';

		$site_by_path     = get_site_by_path( 'www.' . $case['siteDomain'], $site_request_path, 2 );
		$site_miss        = get_site_by_path( 'missing.' . $case['siteDomain'], '/', 1 );
		$network_by_path  = get_network_by_path( 'edge.' . $case['networkDomain'], $network_request_path, 1 );
		$network_miss     = get_network_by_path( 'missing.invalid.test', '/', 1 );
		$site_log         = self::$path_lookup_log[0] ?? array();
		$site_miss_log    = self::$path_lookup_log[1] ?? array();
		$network_log      = self::$path_lookup_log[2] ?? array();
		$network_miss_log = self::$path_lookup_log[3] ?? array();

		$failures = array();
		if ( ! $site_by_path instanceof \WP_Site || $site_by_path->id !== $case['siteId'] ) {
			$failures[] = 'www-prefixed site path lookup did not resolve generated site';
		}
		if ( false !== $site_miss ) {
			$failures[] = 'missing site path lookup did not fail closed';
		}
		if ( ! $network_by_path instanceof \WP_Network || $network_by_path->id !== $case['networkId'] ) {
			$failures[] = 'subdomain network path lookup did not resolve generated network';
		}
		if ( false !== $network_miss ) {
			$failures[] = 'missing network path lookup did not fail closed';
		}
		if ( ( $site_log['paths'] ?? array() ) !== array( $case['sitePath'], $case['networkPath'], '/' ) ) {
			$failures[] = 'site path candidate list did not preserve closest-to-root order';
		}
		if ( ( $site_miss_log['paths'] ?? array() ) !== array( '/' ) ) {
			$failures[] = 'site miss path candidate list should contain root only';
		}
		if ( ( $network_log['paths'] ?? array() ) !== array( $case['networkPath'], '/' ) ) {
			$failures[] = 'network path segment limit did not trim to the generated network path';
		}
		if ( ( $network_miss_log['paths'] ?? array() ) !== array( '/' ) ) {
			$failures[] = 'network miss path candidate list should contain root only';
		}

		return $ctx->result(
			'multisite.path-lookups.domain-path-candidates-and-fail-closed-results',
			array() === $failures,
			array(
				'case'        => self::describe_case( $case ),
				'failures'    => $failures,
				'site'        => self::describe_site( $site_by_path ),
				'network'     => self::describe_network( $network_by_path ),
				'lookupLog'   => self::describe_value( self::$path_lookup_log ),
				'siteMiss'    => self::describe_value( $site_miss ),
				'networkMiss' => self::describe_value( $network_miss ),
			)
		);
	}

	private static function check_url_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$current_site_url = get_site_url( null, 'wp-includes/js/app.js', 'https' );
		$current_admin_url = get_admin_url( null, 'options-general.php', 'https' );
		$network_site_url = network_site_url( 'wp-admin/network/site-new.php', 'https' );
		$network_home_url = network_home_url( 'dashboard/', 'http' );

		switch_to_blog( 17 );
		$switched_site_url = get_site_url( null, 'wp-json/', 'https' );
		$switched_admin_url = get_admin_url( null, 'edit.php', 'http' );
		restore_current_blog();

		$ok = 'https://example.test/wp/wp-includes/js/app.js' === $current_site_url
			&& 'https://example.test/wp/wp-admin/options-general.php' === $current_admin_url
			&& str_starts_with( $network_site_url, 'https://example.test/wp/' )
			&& str_ends_with( $network_site_url, 'wp-admin/network/site-new.php' )
			&& 'http://example.test/dashboard/' === $network_home_url
			&& 'https://blog17.example.test/app/wp-json/' === $switched_site_url
			&& 'http://blog17.example.test/app/wp-admin/edit.php' === $switched_admin_url
			&& 1 === get_current_blog_id()
			&& false === $GLOBALS['switched'];

		return $ctx->result(
			'multisite.urls.current-network-and-switched-contexts',
			$ok,
			array(
				'currentSiteUrl'  => $current_site_url,
				'currentAdminUrl' => $current_admin_url,
				'networkSiteUrl'  => $network_site_url,
				'networkHomeUrl'  => $network_home_url,
				'switchedSiteUrl' => $switched_site_url,
				'switchedAdminUrl' => $switched_admin_url,
			)
		);
	}

	private static function check_upload_paths( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures    = array();
		$time        = $case['uploadTime'];
		$start_blog  = get_current_blog_id();
		$start_stack = $GLOBALS['_wp_switched_stack'];

		$current_uploads = wp_upload_dir( $time, false, true );
		switch_to_blog( $case['siteId'] );
		$generated_uploads = wp_upload_dir( $time, false, true );
		restore_current_blog();

		$expected_subdir = '/' . $time;
		if (
			false !== $current_uploads['error']
			|| $current_uploads['subdir'] !== $expected_subdir
			|| ! str_ends_with( $current_uploads['path'], $expected_subdir )
			|| ! str_ends_with( $current_uploads['url'], $expected_subdir )
		) {
			$failures[] = 'current-site upload paths did not honor year/month subdir without creating directories';
		}

		if (
			false !== $generated_uploads['error']
			|| $generated_uploads['subdir'] !== $expected_subdir
			|| $generated_uploads['baseurl'] !== $case['uploadUrl']
			|| $generated_uploads['url'] !== $case['uploadUrl'] . $expected_subdir
			|| ! str_ends_with( $generated_uploads['basedir'], '/' . $case['uploadPath'] )
			|| ! str_ends_with( $generated_uploads['path'], '/' . $case['uploadPath'] . $expected_subdir )
		) {
			$failures[] = 'generated-site upload paths did not honor filtered upload options';
		}

		if ( $start_blog !== get_current_blog_id() || $start_stack !== $GLOBALS['_wp_switched_stack'] || false !== $GLOBALS['switched'] ) {
			$failures[] = 'upload path check did not restore blog switch globals';
		}

		return $ctx->result(
			'multisite.uploads.filtered-options-yearmonth-and-switch-restore',
			array() === $failures,
			array(
				'case'             => self::describe_case( $case ),
				'failures'         => $failures,
				'currentUploads'   => self::describe_value( $current_uploads ),
				'generatedUploads' => self::describe_value( $generated_uploads ),
				'currentBlogId'    => get_current_blog_id(),
			)
		);
	}

	private static function check_user_blog_membership_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();

		$visible_sites = get_blogs_of_user( $case['userId'], false );
		$all_sites     = get_blogs_of_user( $case['userId'], true );
		$unknown_sites = get_blogs_of_user( $case['userId'] + 100000, true );

		$visible_ids = array_map( 'intval', array_keys( $visible_sites ) );
		$all_ids     = array_map( 'intval', array_keys( $all_sites ) );
		sort( $visible_ids );
		sort( $all_ids );

		$expected_visible = array( 1, 17, $case['siteId'] );
		$expected_all     = array( 1, 17, 23, $case['siteId'] );
		sort( $expected_visible );
		sort( $expected_all );

		if ( $expected_visible !== $visible_ids ) {
			$failures[] = 'get_blogs_of_user(false) did not exclude archived/spam/deleted memberships only';
		}
		if ( $expected_all !== $all_ids ) {
			$failures[] = 'get_blogs_of_user(true) did not include all generated memberships';
		}
		if ( array() !== $unknown_sites ) {
			$failures[] = 'unknown generated user should have no filtered memberships';
		}
		if (
			! isset( $visible_sites[ $case['siteId'] ] )
			|| $visible_sites[ $case['siteId'] ]->blogname !== 'Generated Component Fuzz Site ' . $case['caseSeed']
			|| $visible_sites[ $case['siteId'] ]->siteurl !== 'http://' . $case['siteDomain'] . untrailingslashit( $case['sitePath'] ) . '/wp'
		) {
			$failures[] = 'generated membership object did not carry expected blog metadata';
		}

		return $ctx->result(
			'multisite.user-memberships.pre-filtered-blog-lists-and-status-gates',
			array() === $failures,
			array(
				'case'       => self::describe_case( $case ),
				'failures'   => $failures,
				'visibleIds' => $visible_ids,
				'allIds'     => $all_ids,
				'unknown'    => self::describe_value( $unknown_sites ),
			)
		);
	}

	private static function check_cache_group_isolation( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures     = array();
		$global_group = 'component-fuzz-multisite-global';
		$local_group  = 'component-fuzz-multisite-local';
		$key          = 'case-' . $case['caseSeed'];
		$start_blog   = get_current_blog_id();
		$start_stack  = $GLOBALS['_wp_switched_stack'];

		wp_cache_add_global_groups( array( $global_group ) );
		wp_cache_set( $key, 'global-main', $global_group );
		wp_cache_set( $key, 'local-main', $local_group );
		$last_changed_first = wp_cache_get_last_changed( 'component-fuzz-multisite-last-changed' );
		$last_changed_again = wp_cache_get_last_changed( 'component-fuzz-multisite-last-changed' );

		switch_to_blog( $case['siteId'] );
		$global_switched = wp_cache_get( $key, $global_group );
		$local_switched_before = wp_cache_get( $key, $local_group );
		wp_cache_set( $key, 'local-generated', $local_group );
		$local_multiple = wp_cache_get_multiple( array( $key, 'missing-' . $key ), $local_group );
		restore_current_blog();

		$global_restored = wp_cache_get( $key, $global_group );
		$local_restored  = wp_cache_get( $key, $local_group );

		if ( 'global-main' !== $global_switched || 'global-main' !== $global_restored ) {
			$failures[] = 'global cache group was not shared across switched blog context';
		}
		if ( false !== $local_switched_before || 'local-main' !== $local_restored ) {
			$failures[] = 'local cache group was not isolated by switched blog context';
		}
		if ( ( $local_multiple[ $key ] ?? null ) !== 'local-generated' || false !== ( $local_multiple[ 'missing-' . $key ] ?? null ) ) {
			$failures[] = 'wp_cache_get_multiple did not preserve switched-blog local cache values';
		}
		if ( '' === $last_changed_first || $last_changed_first !== $last_changed_again ) {
			$failures[] = 'wp_cache_get_last_changed did not return stable cached marker';
		}
		if ( $start_blog !== get_current_blog_id() || $start_stack !== $GLOBALS['_wp_switched_stack'] || false !== $GLOBALS['switched'] ) {
			$failures[] = 'cache group check did not restore blog switch globals';
		}

		return $ctx->result(
			'multisite.cache-groups.global-local-last-changed-and-switch-isolation',
			array() === $failures,
			array(
				'case'                  => self::describe_case( $case ),
				'failures'              => $failures,
				'globalSwitched'        => self::describe_value( $global_switched ),
				'localSwitchedBefore'   => self::describe_value( $local_switched_before ),
				'localMultiple'         => self::describe_value( $local_multiple ),
				'globalRestored'        => self::describe_value( $global_restored ),
				'localRestored'         => self::describe_value( $local_restored ),
				'lastChangedFirst'      => $last_changed_first,
				'lastChangedAgain'      => $last_changed_again,
			)
		);
	}

	private static function check_restoration_probe( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$mutated = get_current_blog_id() !== ( $snapshot['globals']['blog_id']['value'] ?? null )
			|| $GLOBALS['wpdb'] !== ( $snapshot['globals']['wpdb']['value'] ?? null )
			|| false !== has_filter( 'pre_option', array( self::class, 'filter_option' ) )
			|| false !== has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) )
			|| false !== has_filter( 'pre_get_site_by_path', array( self::class, 'filter_site_by_path' ) )
			|| false !== has_filter( 'pre_get_blogs_of_user', array( self::class, 'filter_blogs_of_user' ) );

		return $ctx->result(
			'multisite.state.restoration-probe-mutates-scoped-state',
			$mutated,
			array(
				'currentBlogId' => get_current_blog_id(),
				'hasOptionFilter' => has_filter( 'pre_option', array( self::class, 'filter_option' ) ),
				'hasSitesFilter' => has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) ),
				'hasPathFilter'  => has_filter( 'pre_get_site_by_path', array( self::class, 'filter_site_by_path' ) ),
				'hasUserFilter'  => has_filter( 'pre_get_blogs_of_user', array( self::class, 'filter_blogs_of_user' ) ),
			)
		);
	}

	private static function check_post_restore_state( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$ok = self::globals_match_snapshot( $snapshot )
			&& false === has_filter( 'pre_option', array( self::class, 'filter_option' ) )
			&& false === has_filter( 'pre_site_option', array( self::class, 'filter_network_option' ) )
			&& false === has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) )
			&& false === has_filter( 'networks_pre_query', array( self::class, 'filter_networks_pre_query' ) )
			&& false === has_filter( 'pre_get_site_by_path', array( self::class, 'filter_site_by_path' ) )
			&& false === has_filter( 'pre_get_network_by_path', array( self::class, 'filter_network_by_path' ) )
			&& false === has_filter( 'pre_get_blogs_of_user', array( self::class, 'filter_blogs_of_user' ) )
			&& array() === self::$blog_options
			&& array() === self::$network_options
			&& array() === self::$user_blog_memberships
			&& array() === self::$path_lookup_log
			&& array() === self::$sites
			&& array() === self::$networks;

		return $ctx->result(
			'multisite.state.restored-after-case',
			$ok,
			array(
				'currentBlogId' => get_current_blog_id(),
				'hasOptionFilter' => has_filter( 'pre_option', array( self::class, 'filter_option' ) ),
				'hasSitesFilter' => has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) ),
			)
		);
	}

	private static function check_true_multisite_lifecycle_subprocess( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$missing = self::true_multisite_lifecycle_child_missing_requirements();
		if ( array() !== $missing ) {
			return $ctx->skip(
				'multisite.true-multisite.site-and-sitemeta-lifecycle',
				'Required child-process APIs are unavailable.',
				array(
					'missing' => implode( ', ', $missing ),
					'case'    => self::describe_case( $case ),
				)
			);
		}

		$run     = self::run_true_multisite_lifecycle_child( $case );
		$result  = $run['result'];
		$details = array(
			'case'     => self::describe_case( $case ),
			'exitCode' => $run['exitCode'],
			'stdout'   => self::child_preview( $run['stdout'] ),
			'stderr'   => self::child_preview( $run['stderr'] ),
		);

		if ( is_array( $result ) && ! empty( $result['skip'] ) ) {
			return $ctx->skip(
				'multisite.true-multisite.site-and-sitemeta-lifecycle',
				(string) ( $result['reason'] ?? 'True multisite child check skipped.' ),
				$details + array(
					'childDetails' => $result['details'] ?? array(),
				)
			);
		}

		$failures = array();
		if ( ! $run['ok'] ) {
			$failures[] = array(
				'message' => 'true multisite child process did not exit cleanly with successful JSON',
				'details' => $details,
			);
		}

		if ( is_array( $result ) ) {
			foreach ( $result['failures'] ?? array() as $failure ) {
				$failures[] = $failure;
			}

			if ( ! empty( $result['unexpectedOutput'] ) ) {
				$failures[] = array(
					'message' => 'true multisite child emitted unexpected stdout before its JSON result',
					'details' => array( 'unexpectedOutput' => $result['unexpectedOutput'] ),
				);
			}

			if ( ! self::true_multisite_child_events_seen( $result['events'] ?? array() ) ) {
				$failures[] = array(
					'message' => 'true multisite site lifecycle hooks were not observed in the expected order',
					'details' => array( 'events' => $result['events'] ?? array() ),
				);
			}

			$residue = $result['residue'] ?? array();
			if ( 0 !== (int) ( $residue['siteRows'] ?? 0 ) || 0 !== (int) ( $residue['networkOptionRows'] ?? 0 ) || 0 !== (int) ( $residue['aliasOptionRows'] ?? 0 ) ) {
				$failures[] = array(
					'message' => 'true multisite child left generated DB rows behind',
					'details' => array( 'residue' => $residue ),
				);
			}
		} else {
			$failures[] = array(
				'message' => 'true multisite child did not return parseable JSON',
				'details' => $details,
			);
		}

		return $ctx->result(
			'multisite.true-multisite.site-and-sitemeta-lifecycle',
			array() === $failures,
			$details + array(
				'failures' => $failures,
				'child'    => is_array( $result ) ? $result : null,
			)
		);
	}

	private static function true_multisite_lifecycle_child_missing_requirements(): array {
		$missing = array();

		foreach ( array( 'file_put_contents', 'json_decode', 'json_encode', 'proc_close', 'proc_open', 'random_bytes', 'stream_get_contents' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}

		return $missing;
	}

	private static function run_true_multisite_lifecycle_child( array $case ): array {
		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-multisite-true-lifecycle';
		\ComponentFuzz\ensure_dir( $dir );

		$script = $dir . DIRECTORY_SEPARATOR . 'child-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) ) . '.php';
		file_put_contents( $script, self::true_multisite_lifecycle_child_program() );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates MULTISITE and a real wpdb bootstrap in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			@unlink( $script );
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		$payload = json_encode(
			array(
				'repoRoot' => \ComponentFuzz\repo_root(),
				'case'     => $case,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
		fwrite( $pipes[0], false === $payload ? '{}' : $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		@unlink( $script );

		$result = json_decode( (string) $stdout, true );
		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && ( ! empty( $result['ok'] ) || ! empty( $result['skip'] ) ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function true_multisite_child_events_seen( array $events ): bool {
		$expected = array( 'wp_insert_site', 'wp_initialize_site', 'wp_update_site', 'wp_uninitialize_site', 'wp_delete_site' );
		$actual   = array();
		foreach ( $events as $event ) {
			if ( is_array( $event ) && isset( $event['hook'] ) ) {
				$actual[] = $event['hook'];
			}
		}

		return $expected === $actual;
	}

	private static function child_preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '...' : $value,
		);
	}

	private static function true_multisite_lifecycle_child_program(): string {
		return <<<'PHP'
<?php
ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

$GLOBALS['component_fuzz_ms_base_ob_level'] = ob_get_level();
ob_start();

function component_fuzz_ms_emit( array $payload, int $exit_code = 0 ): void {
	$unexpected_output = '';
	while ( ob_get_level() > ( $GLOBALS['component_fuzz_ms_base_ob_level'] ?? 0 ) ) {
		$chunk             = ob_get_clean();
		$unexpected_output = ( false === $chunk ? '' : $chunk ) . $unexpected_output;
	}
	if ( '' !== $unexpected_output ) {
		$payload['unexpectedOutput'] = strlen( $unexpected_output ) > 500 ? substr( $unexpected_output, 0, 500 ) . '...' : $unexpected_output;
	}

	$json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	echo false === $json ? '{"ok":false,"failures":[{"message":"json_encode failed"}]}' : $json;
	exit( $exit_code );
}

function component_fuzz_ms_skip( string $reason, array $details = array() ): void {
	component_fuzz_ms_emit(
		array(
			'ok'      => true,
			'skip'    => true,
			'reason'  => $reason,
			'details' => $details,
		)
	);
}

function component_fuzz_ms_config_candidates( string $repo_root ): array {
	$candidates = array();
	$env        = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );

	if ( is_string( $env ) && '' !== $env ) {
		$candidates[] = is_dir( $env ) ? rtrim( $env, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'wp-tests-config.php' : $env;
	}

	$candidates[] = rtrim( $repo_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'wp-tests-config.php';
	$candidates[] = rtrim( $repo_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'phpunit' . DIRECTORY_SEPARATOR . 'wp-tests-config.php';

	return array_values( array_unique( $candidates ) );
}

function component_fuzz_ms_find_config( string $repo_root ): ?string {
	foreach ( component_fuzz_ms_config_candidates( $repo_root ) as $candidate ) {
		if ( is_readable( $candidate ) ) {
			return $candidate;
		}
	}

	return null;
}

function component_fuzz_ms_table_exists( string $table ): bool {
	global $wpdb;

	$suppress = $wpdb->suppress_errors();
	$found    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$wpdb->suppress_errors( $suppress );

	return is_string( $found ) && strtolower( $found ) === strtolower( $table );
}

function component_fuzz_ms_sitemeta_count( int $network_id, string $key ): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s",
			$network_id,
			$key
		)
	);
}

function component_fuzz_ms_site_row_count( string $domain, int $network_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->blogs} WHERE domain = %s AND site_id = %d",
			$domain,
			$network_id
		)
	);
}

function component_fuzz_ms_cleanup_option( int $network_id, string $key ): void {
	global $wpdb;

	if ( function_exists( 'delete_network_option' ) ) {
		delete_network_option( $network_id, $key );
	}

	$wpdb->delete(
		$wpdb->sitemeta,
		array(
			'site_id'  => $network_id,
			'meta_key' => $key,
		)
	);
	wp_cache_delete( "{$network_id}:{$key}", 'site-options' );
	wp_cache_delete( "{$network_id}:notoptions", 'site-options' );
}

function component_fuzz_ms_delete_sites( string $domain, int $network_id ): void {
	global $wpdb;

	$site_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s AND site_id = %d",
			$domain,
			$network_id
		)
	);

	foreach ( array_map( 'intval', $site_ids ) as $site_id ) {
		if ( function_exists( 'clean_blog_cache' ) ) {
			clean_blog_cache( $site_id );
		}
		$wpdb->delete( $wpdb->blogs, array( 'blog_id' => $site_id ) );
		if ( function_exists( 'clean_blog_cache' ) ) {
			clean_blog_cache( $site_id );
		}
	}
}

function component_fuzz_ms_remove_core_site_hooks(): void {
	$actions = array(
		array( 'wp_insert_site', 'wp_maybe_update_network_site_counts_on_update', 10 ),
		array( 'wp_update_site', 'wp_maybe_update_network_site_counts_on_update', 10 ),
		array( 'wp_delete_site', 'wp_maybe_update_network_site_counts_on_update', 10 ),
		array( 'wp_insert_site', 'wp_maybe_transition_site_statuses_on_update', 10 ),
		array( 'wp_update_site', 'wp_maybe_transition_site_statuses_on_update', 10 ),
		array( 'wp_update_site', 'wp_maybe_clean_new_site_cache_on_update', 10 ),
		array( 'wp_initialize_site', 'wp_initialize_site', 10 ),
		array( 'wp_initialize_site', 'wpmu_log_new_registrations', 100 ),
		array( 'wp_initialize_site', 'newblog_notify_siteadmin', 100 ),
		array( 'wp_uninitialize_site', 'wp_uninitialize_site', 10 ),
		array( 'update_network_counts', 'wp_update_network_counts', 10 ),
	);

	foreach ( $actions as $action ) {
		remove_action( $action[0], $action[1], $action[2] );
	}
}

function component_fuzz_ms_add_event_observers( array &$events ): void {
	add_action(
		'wp_insert_site',
		static function ( WP_Site $site ) use ( &$events ): void {
			$events[] = array(
				'hook'   => 'wp_insert_site',
				'siteId' => $site->id,
			);
		},
		20,
		1
	);
	add_action(
		'wp_initialize_site',
		static function ( WP_Site $site, array $args ) use ( &$events ): void {
			$events[] = array(
				'hook'    => 'wp_initialize_site',
				'siteId'  => $site->id,
				'hasArgs' => array() !== $args,
			);
		},
		20,
		2
	);
	add_action(
		'wp_update_site',
		static function ( WP_Site $new_site, WP_Site $old_site ) use ( &$events ): void {
			$events[] = array(
				'hook'    => 'wp_update_site',
				'siteId'  => $new_site->id,
				'oldPath' => $old_site->path,
				'newPath' => $new_site->path,
			);
		},
		20,
		2
	);
	add_action(
		'wp_uninitialize_site',
		static function ( WP_Site $site ) use ( &$events ): void {
			$events[] = array(
				'hook'   => 'wp_uninitialize_site',
				'siteId' => $site->id,
			);
		},
		20,
		1
	);
	add_action(
		'wp_delete_site',
		static function ( WP_Site $site ) use ( &$events ): void {
			$events[] = array(
				'hook'   => 'wp_delete_site',
				'siteId' => $site->id,
			);
		},
		20,
		1
	);
}

function component_fuzz_ms_expect( array &$failures, bool $condition, string $message, array $details = array() ): void {
	if ( $condition ) {
		return;
	}

	$failures[] = array(
		'message' => $message,
		'details' => $details,
	);
}

function component_fuzz_ms_check_network_option( array &$failures, int $network_id, string $key, $first_value, $second_value, bool $alias ): array {
	component_fuzz_ms_cleanup_option( $network_id, $key );

	$add       = $alias ? 'add_site_option' : 'add_network_option';
	$get       = $alias ? 'get_site_option' : 'get_network_option';
	$update    = $alias ? 'update_site_option' : 'update_network_option';
	$delete    = $alias ? 'delete_site_option' : 'delete_network_option';
	$add_args  = $alias ? array( $key, $first_value ) : array( $network_id, $key, $first_value );
	$get_args  = $alias ? array( $key, 'fallback' ) : array( $network_id, $key, 'fallback' );
	$upd_args1 = $alias ? array( $key, $first_value ) : array( $network_id, $key, $first_value );
	$upd_args2 = $alias ? array( $key, $second_value ) : array( $network_id, $key, $second_value );
	$del_args  = $alias ? array( $key ) : array( $network_id, $key );

	$added          = $add( ...$add_args );
	$duplicate     = $add( ...$add_args );
	$read_first    = $get( ...$get_args );
	$count_added   = component_fuzz_ms_sitemeta_count( $network_id, $key );
	$cache_added   = wp_cache_get( "{$network_id}:{$key}", 'site-options' );
	$same_update   = $update( ...$upd_args1 );
	$changed       = $update( ...$upd_args2 );
	$read_second   = $get( ...$get_args );
	$cache_updated = wp_cache_get( "{$network_id}:{$key}", 'site-options' );
	$deleted       = $delete( ...$del_args );
	$delete_again  = $delete( ...$del_args );
	$read_gone     = $get( ...$get_args );
	$count_deleted = component_fuzz_ms_sitemeta_count( $network_id, $key );

	component_fuzz_ms_expect(
		$failures,
		true === $added && false === $duplicate && 1 === $count_added,
		( $alias ? 'site option alias' : 'network option' ) . ' add/duplicate row-count semantics diverged',
		compact( 'added', 'duplicate', 'count_added', 'key' )
	);
	component_fuzz_ms_expect(
		$failures,
		$first_value === $read_first && $first_value === $cache_added,
		( $alias ? 'site option alias' : 'network option' ) . ' add did not populate read/cache value',
		array(
			'readFirst'  => $read_first,
			'cacheAdded' => $cache_added,
			'key'        => $key,
		)
	);
	component_fuzz_ms_expect(
		$failures,
		false === $same_update && true === $changed && $second_value === $read_second && $second_value === $cache_updated,
		( $alias ? 'site option alias' : 'network option' ) . ' update/cache semantics diverged',
		array(
			'sameUpdate'   => $same_update,
			'changed'      => $changed,
			'readSecond'   => $read_second,
			'cacheUpdated' => $cache_updated,
			'key'          => $key,
		)
	);
	component_fuzz_ms_expect(
		$failures,
		true === $deleted && false === $delete_again && 'fallback' === $read_gone && 0 === $count_deleted,
		( $alias ? 'site option alias' : 'network option' ) . ' delete/fallback row-count semantics diverged',
		array(
			'deleted'      => $deleted,
			'deleteAgain'  => $delete_again,
			'readGone'     => $read_gone,
			'countDeleted' => $count_deleted,
			'key'          => $key,
		)
	);

	return array(
		'key'          => $key,
		'countAdded'   => $count_added,
		'countDeleted' => $count_deleted,
	);
}

try {
	$input = json_decode( stream_get_contents( STDIN ), true );
	if ( ! is_array( $input ) ) {
		$input = array();
	}

	$repo_root = isset( $input['repoRoot'] ) && is_string( $input['repoRoot'] ) ? $input['repoRoot'] : (string) getcwd();
	$case      = isset( $input['case'] ) && is_array( $input['case'] ) ? $input['case'] : array();
	$token     = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $case['trueMsToken'] ?? 'cfms' ) ) );
	if ( '' === $token ) {
		$token = 'cfms';
	}

	$config = component_fuzz_ms_find_config( $repo_root );
	if ( null === $config ) {
		component_fuzz_ms_skip(
			'No readable wp-tests-config.php is available for a true multisite DB child process.',
			array( 'candidates' => component_fuzz_ms_config_candidates( $repo_root ) )
		);
	}

	defined( 'MULTISITE' ) || define( 'MULTISITE', true );
	defined( 'SUBDOMAIN_INSTALL' ) || define( 'SUBDOMAIN_INSTALL', false );
	defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );
	defined( 'WP_USE_THEMES' ) || define( 'WP_USE_THEMES', false );

	require_once $config;

	if ( ! defined( 'ABSPATH' ) || ! is_dir( ABSPATH ) ) {
		component_fuzz_ms_skip(
			'wp-tests-config.php does not define a readable ABSPATH.',
			array( 'config' => $config )
		);
	}

	$host = defined( 'WP_TESTS_DOMAIN' ) ? WP_TESTS_DOMAIN : 'example.org';
	$_SERVER['HTTP_HOST']   = $host;
	$_SERVER['SERVER_NAME'] = $host;
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['PHP_SELF']    = '/index.php';
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

	require_once ABSPATH . 'wp-settings.php';

	global $wpdb;
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		component_fuzz_ms_skip( 'WordPress did not boot in multisite mode.', array( 'config' => $config ) );
	}
	if (
		is_object( $wpdb )
		&& (
			'Component_Fuzz_WPDB_Stub' === get_class( $wpdb )
			|| is_subclass_of( $wpdb, 'Component_Fuzz_WPDB_Stub' )
		)
	) {
		component_fuzz_ms_skip( 'WordPress booted with the component fuzz wpdb stub instead of a real database connection.' );
	}

	$missing_tables = array();
	foreach ( array( $wpdb->blogs, $wpdb->sitemeta ) as $table ) {
		if ( ! component_fuzz_ms_table_exists( $table ) ) {
			$missing_tables[] = $table;
		}
	}
	if ( array() !== $missing_tables ) {
		component_fuzz_ms_skip(
			'The configured test database is missing required multisite tables.',
			array( 'missingTables' => $missing_tables )
		);
	}

	$network_id = (int) get_current_network_id();
	if ( $network_id <= 0 || ! get_network( $network_id ) ) {
		component_fuzz_ms_skip( 'No current network is available for true multisite lifecycle fuzzing.', array( 'networkId' => $network_id ) );
	}

	component_fuzz_ms_remove_core_site_hooks();

	$domain             = $token . '.component-fuzz-ms.test';
	$path               = '/' . $token . '/';
	$updated_path       = '/' . $token . '-updated/';
	$network_option_key = 'component_fuzz_ms_network_' . $token;
	$alias_option_key   = 'component_fuzz_ms_alias_' . $token;
	$events             = array();
	$failures           = array();
	$created_site_id    = 0;

	component_fuzz_ms_delete_sites( $domain, $network_id );
	component_fuzz_ms_cleanup_option( $network_id, $network_option_key );
	component_fuzz_ms_cleanup_option( $network_id, $alias_option_key );
	component_fuzz_ms_add_event_observers( $events );

	try {
		$network_option_details = component_fuzz_ms_check_network_option(
			$failures,
			$network_id,
			$network_option_key,
			array(
				'token' => $token,
				'kind'  => 'network',
			),
			array(
				'token' => $token,
				'kind'  => 'network-updated',
				'count' => 2,
			),
			false
		);
		$alias_option_details   = component_fuzz_ms_check_network_option(
			$failures,
			$network_id,
			$alias_option_key,
			'alias-first-' . $token,
			'alias-second-' . $token,
			true
		);

		$site_data = array(
			'domain'     => $domain,
			'path'       => $path,
			'network_id' => $network_id,
			'public'     => 1,
			'archived'   => 0,
			'mature'     => 0,
			'spam'       => 0,
			'deleted'    => 0,
			'title'      => 'Component Fuzz Multisite ' . $token,
			'options'    => array(),
			'meta'       => array(),
		);

		$inserted = wp_insert_site( $site_data );
		component_fuzz_ms_expect(
			$failures,
			is_int( $inserted ) && $inserted > 0,
			'wp_insert_site did not return a positive integer site ID',
			array( 'inserted' => is_wp_error( $inserted ) ? $inserted->get_error_code() : $inserted )
		);

		if ( is_int( $inserted ) && $inserted > 0 ) {
			$created_site_id = $inserted;
			$site            = get_site( $inserted );
			$site_ids        = get_sites(
				array(
					'site__in'               => array( $inserted ),
					'fields'                 => 'ids',
					'update_site_meta_cache' => false,
				)
			);
			$duplicate      = wp_insert_site( $site_data );
			$duplicate_code = is_wp_error( $duplicate ) ? $duplicate->get_error_code() : null;

			component_fuzz_ms_expect(
				$failures,
				$site instanceof WP_Site
					&& $site->id === $inserted
					&& $site->domain === $domain
					&& $site->path === $path
					&& $site->network_id === $network_id
					&& 1 === (int) $site->public
					&& 0 === (int) $site->archived
					&& 0 === (int) $site->spam
					&& 0 === (int) $site->deleted,
				'get_site did not return the inserted site with expected normalized fields',
				array( 'site' => $site instanceof WP_Site ? $site->to_array() : $site )
			);
			component_fuzz_ms_expect(
				$failures,
				array( $inserted ) === array_map( 'intval', $site_ids ),
				'get_sites(fields=ids) did not find exactly the inserted site',
				array( 'siteIds' => $site_ids )
			);
			component_fuzz_ms_expect(
				$failures,
				'site_taken' === $duplicate_code,
				'duplicate wp_insert_site call did not return site_taken',
				array( 'duplicate' => is_wp_error( $duplicate ) ? $duplicate->get_error_messages() : $duplicate )
			);

			$updated_id   = wp_update_site(
				$inserted,
				array(
					'path'   => $updated_path,
					'public' => 0,
				)
			);
			$updated_site = get_site( $inserted );
			component_fuzz_ms_expect(
				$failures,
				$updated_id === $inserted
					&& $updated_site instanceof WP_Site
					&& $updated_site->path === $updated_path
					&& 0 === (int) $updated_site->public,
				'wp_update_site did not return the same ID or refresh cached site fields',
				array(
					'updatedId'   => is_wp_error( $updated_id ) ? $updated_id->get_error_code() : $updated_id,
					'updatedSite' => $updated_site instanceof WP_Site ? $updated_site->to_array() : $updated_site,
				)
			);

			if ( function_exists( 'get_main_network_id' ) ) {
				wp_cache_set( get_main_network_id() . ':site_meta_supported', 0, 'site-options' );
			}
			$deleted = wp_delete_site( $inserted );
			if ( $deleted instanceof WP_Site ) {
				$created_site_id = 0;
			}

			$site_after_delete   = get_site( $inserted );
			$domain_after_delete = domain_exists( $domain, $updated_path, $network_id );
			$row_count_after     = component_fuzz_ms_site_row_count( $domain, $network_id );
			component_fuzz_ms_expect(
				$failures,
				$deleted instanceof WP_Site
					&& $deleted->id === $inserted
					&& null === $site_after_delete
					&& null === $domain_after_delete
					&& 0 === $row_count_after,
				'wp_delete_site did not remove the site row, cache lookup, and domain lookup',
				array(
					'deleted'           => $deleted instanceof WP_Site ? $deleted->to_array() : $deleted,
					'siteAfterDelete'   => $site_after_delete,
					'domainAfterDelete' => $domain_after_delete,
					'rowCountAfter'     => $row_count_after,
				)
			);
		} else {
			$network_option_details = $network_option_details ?? array();
			$alias_option_details   = $alias_option_details ?? array();
		}
	} finally {
		if ( $created_site_id > 0 ) {
			wp_delete_site( $created_site_id );
		}
		component_fuzz_ms_cleanup_option( $network_id, $network_option_key );
		component_fuzz_ms_cleanup_option( $network_id, $alias_option_key );
		component_fuzz_ms_delete_sites( $domain, $network_id );
		while ( ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
			restore_current_blog();
		}
	}

	$residue = array(
		'siteRows'          => component_fuzz_ms_site_row_count( $domain, $network_id ),
		'networkOptionRows' => component_fuzz_ms_sitemeta_count( $network_id, $network_option_key ),
		'aliasOptionRows'   => component_fuzz_ms_sitemeta_count( $network_id, $alias_option_key ),
	);

	component_fuzz_ms_emit(
		array(
			'ok'                   => array() === $failures,
			'failures'             => $failures,
			'events'               => $events,
			'networkId'            => $network_id,
			'token'                => $token,
			'domain'               => $domain,
			'path'                 => $path,
			'updatedPath'          => $updated_path,
			'networkOptionDetails' => $network_option_details ?? array(),
			'aliasOptionDetails'   => $alias_option_details ?? array(),
			'residue'              => $residue,
		)
	);
} catch ( Throwable $e ) {
	component_fuzz_ms_emit(
		array(
			'ok'        => false,
			'failures'  => array(
				array(
					'message' => 'true multisite child threw',
					'details' => array(
						'class'   => get_class( $e ),
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					),
				),
			),
			'throwable' => array(
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			),
		),
		1
	);
}
PHP;
	}

	private static function prepare_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$case_ctx     = $ctx->fork( 'multisite-rich-case' );
		$network_slug = self::slug( $case_ctx, 'net' );
		$site_slug    = self::slug( $case_ctx, 'site' );
		$directory_slug = self::slug( $case_ctx->fork( 'directory-site' ), 'dir' );
		$case_seed    = $case_ctx->seed();
		$network_id   = 300 + $case_ctx->int( 1, 400 );
		$site_id      = 5000 + $case_ctx->int( 1, 4000 );
		$directory_site_id = 9200 + $case_ctx->int( 1, 500 );
		$month        = $case_ctx->int( 1, 12 );
		$network_path = '/' . $network_slug . '/';
		$site_path    = $network_path . $site_slug . '/';
		$directory_path = '/' . $directory_slug . '/';
		$domain_label = self::slug( $case_ctx, 'domain' );
		$site_domain  = $site_slug . '.' . $domain_label . '.example.test';
		$network_domain = $network_slug . '.' . $domain_label . '.example.test';
		$mature         = $case_ctx->bool( 30 ) ? '1' : '0';
		$post_count     = $case_ctx->int( 1, 999 );
		$true_ms_token  = sprintf(
			'cfms%si%sh%s',
			preg_replace( '/[^0-9]/', '', (string) abs( $ctx->seed() ) ),
			$ctx->iteration(),
			substr( hash( 'sha256', $network_slug . '|' . $site_slug . '|' . $case_seed ), 0, 8 )
		);
		$raw_site_data = array(
			'domain'       => " HTTPS://{$site_domain}/Bad Path?x=1 \n",
			'path'         => '//' . trim( $site_path, '/' ) . '//',
			'network_id'   => (string) $network_id,
			'public'       => '1',
			'archived'     => '0',
			'mature'       => $mature,
			'spam'         => false,
			'deleted'      => '0',
			'registered'   => '0000-00-00 00:00:00',
			'last_updated' => '',
		);

		return array(
			'caseSeed'            => $case_seed,
			'networkId'           => $network_id,
			'siteId'              => $site_id,
			'directorySiteId'     => $directory_site_id,
			'userId'              => 7000 + $case_ctx->int( 1, 3000 ),
			'networkDomain'       => $network_domain,
			'siteDomain'          => $site_domain,
			'networkPath'         => $network_path,
			'sitePath'            => $site_path,
			'directorySlug'       => $directory_slug,
			'directoryPath'       => $directory_path,
			'mature'              => (int) $mature,
			'postCount'           => $post_count,
			'rawSiteData'         => $raw_site_data,
			'normalizedRawDomain' => preg_replace( '/[^a-z0-9\-.:]+/i', '', $raw_site_data['domain'] ),
			'normalizedRawPath'   => trailingslashit( '/' . trim( $raw_site_data['path'], '/' ) ),
			'uploadPath'          => 'component-fuzz-uploads/' . $site_slug,
			'uploadUrl'           => 'https://uploads.' . $site_domain . '/media',
			'uploadTime'          => sprintf( '2026/%02d', $month ),
			'trueMsToken'         => $true_ms_token,
		);
	}

	private static function reset_runtime( \ComponentFuzz\FuzzContext $ctx, array $case ): void {
		if ( function_exists( 'wp_using_ext_object_cache' ) ) {
			wp_using_ext_object_cache( false );
		}
		if ( function_exists( 'wp_installing' ) ) {
			wp_installing( false );
		}
		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( false );
		}

		wp_cache_init();
		self::force_cache_multisite_mode( 1 );

		$GLOBALS['wpdb'] = self::make_wpdb_stub(
			array(
				'blog_charset' => array(
					'option_value' => 'UTF-8',
					'autoload'     => 'on',
				),
			)
		);

		$GLOBALS['blog_id']            = 1;
		$GLOBALS['table_prefix']       = 'wp_';
		$GLOBALS['_wp_switched_stack'] = array();
		$GLOBALS['switched']           = false;

		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['SERVER_NAME'] = 'example.test';
		$_SERVER['REQUEST_URI'] = '/component-fuzz/multisite/';
		unset( $_SERVER['HTTPS'] );

		self::$blog_options = array(
			1  => array(
				'home'                         => 'http://example.test',
				'siteurl'                      => 'http://example.test/wp',
				'blogname'                     => 'Main Component Fuzz Site',
				'admin_email'                  => 'main@example.test',
				'upload_path'                  => 'wp-content/uploads',
				'upload_url_path'              => '',
				'uploads_use_yearmonth_folders' => '1',
				'post_count'                   => '17',
			),
			17 => array(
				'home'                         => 'http://blog17.example.test',
				'siteurl'                      => 'http://blog17.example.test/app',
				'blogname'                     => 'Switched Component Fuzz Site',
				'admin_email'                  => 'blog17@example.test',
				'upload_path'                  => 'wp-content/uploads/blog17',
				'upload_url_path'              => '',
				'uploads_use_yearmonth_folders' => '1',
				'post_count'                   => '23',
			),
			23 => array(
				'home'                         => 'http://network-two.example.test/archive',
				'siteurl'                      => 'http://network-two.example.test/archive/wp',
				'blogname'                     => 'Archived Component Fuzz Site',
				'admin_email'                  => 'blog23@example.test',
				'upload_path'                  => 'wp-content/uploads/archive',
				'upload_url_path'              => '',
				'uploads_use_yearmonth_folders' => '0',
				'post_count'                   => '0',
			),
			$case['siteId'] => array(
				'home'                         => 'http://' . $case['siteDomain'] . untrailingslashit( $case['sitePath'] ),
				'siteurl'                      => 'http://' . $case['siteDomain'] . untrailingslashit( $case['sitePath'] ) . '/wp',
				'blogname'                     => 'Generated Component Fuzz Site ' . $case['caseSeed'],
				'admin_email'                  => 'generated-' . $case['caseSeed'] . '@example.test',
				'upload_path'                  => $case['uploadPath'],
				'upload_url_path'              => $case['uploadUrl'],
				'uploads_use_yearmonth_folders' => '1',
				'post_count'                   => (string) $case['postCount'],
			),
			$case['directorySiteId'] => array(
				'home'                         => 'http://example.test' . untrailingslashit( $case['directoryPath'] ),
				'siteurl'                      => 'http://example.test' . untrailingslashit( $case['directoryPath'] ) . '/wp',
				'blogname'                     => 'Directory Component Fuzz Site ' . $case['caseSeed'],
				'admin_email'                  => 'directory-' . $case['caseSeed'] . '@example.test',
				'upload_path'                  => 'wp-content/uploads/' . $case['directorySlug'],
				'upload_url_path'              => '',
				'uploads_use_yearmonth_folders' => '1',
				'post_count'                   => '5',
			),
		);

		self::$network_options = array(
			1 => array(
				'site_name'   => 'Primary Component Fuzz Network',
				'admin_email' => 'network@example.test',
				'main_site'   => 1,
			),
			2 => array(
				'site_name'   => 'Secondary Component Fuzz Network',
				'admin_email' => 'network-two@example.test',
				'main_site'   => 23,
			),
			$case['networkId'] => array(
				'site_name'   => 'Generated Network ' . $case['caseSeed'],
				'admin_email' => 'network-' . $case['caseSeed'] . '@example.test',
				'main_site'   => $case['siteId'],
			),
		);
		self::$main_site_ids = array(
			1                    => 1,
			2                    => 23,
			$case['networkId']   => $case['siteId'],
		);

		self::$sites = array(
			self::site_from_parts( 1, 'example.test', '/', 1, 1, 0, 0, 0, 0 ),
			self::site_from_parts( 17, 'blog17.example.test', '/', 1, 1, 0, 0, 0, 0 ),
			self::site_from_parts( 23, 'network-two.example.test', '/archive/', 2, 0, 1, 0, 0, 0 ),
			self::site_from_parts( $case['siteId'], $case['siteDomain'], $case['sitePath'], $case['networkId'], 1, 0, $case['mature'], 0, 0 ),
			self::site_from_parts( $case['directorySiteId'], 'example.test', $case['directoryPath'], 1, 1, 0, 0, 0, 0 ),
		);
		self::$networks = array(
			self::network_from_parts( 1, 'example.test', '/', 1, 'Primary Component Fuzz Network' ),
			self::network_from_parts( 2, 'network-two.example.test', '/archive/', 23, 'Secondary Component Fuzz Network' ),
			self::network_from_parts( $case['networkId'], $case['networkDomain'], $case['networkPath'], $case['siteId'], 'Generated Network ' . $case['caseSeed'] ),
		);
		self::$user_blog_memberships = array(
			$case['userId'] => array( 1, 17, 23, $case['siteId'] ),
		);
		self::$path_lookup_log       = array();

		$GLOBALS['current_blog'] = self::$sites[0];
		$GLOBALS['current_site'] = self::$networks[0];

		foreach ( self::$sites as $site ) {
			wp_cache_set( $site->id, (object) $site->to_array(), 'sites' );
		}
		foreach ( self::$networks as $network ) {
			wp_cache_set( $network->id, self::network_raw_object( $network ), 'networks' );
		}
	}

	private static function install_filters(): void {
		add_filter( 'pre_option', array( self::class, 'filter_option' ), 10, 3 );
		add_filter( 'pre_site_option', array( self::class, 'filter_network_option' ), 10, 4 );
		add_filter( 'pre_get_main_site_id', array( self::class, 'filter_main_site_id' ), 10, 2 );
		add_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ), 10, 2 );
		add_filter( 'networks_pre_query', array( self::class, 'filter_networks_pre_query' ), 10, 2 );
		add_filter( 'pre_get_site_by_path', array( self::class, 'filter_site_by_path' ), 10, 5 );
		add_filter( 'pre_get_network_by_path', array( self::class, 'filter_network_by_path' ), 10, 5 );
		add_filter( 'pre_get_blogs_of_user', array( self::class, 'filter_blogs_of_user' ), 10, 3 );
	}

	private static function remove_filters(): void {
		remove_filter( 'pre_option', array( self::class, 'filter_option' ), 10 );
		remove_filter( 'pre_site_option', array( self::class, 'filter_network_option' ), 10 );
		remove_filter( 'pre_get_main_site_id', array( self::class, 'filter_main_site_id' ), 10 );
		remove_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ), 10 );
		remove_filter( 'networks_pre_query', array( self::class, 'filter_networks_pre_query' ), 10 );
		remove_filter( 'pre_get_site_by_path', array( self::class, 'filter_site_by_path' ), 10 );
		remove_filter( 'pre_get_network_by_path', array( self::class, 'filter_network_by_path' ), 10 );
		remove_filter( 'pre_get_blogs_of_user', array( self::class, 'filter_blogs_of_user' ), 10 );
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wpdb',
				'wp_object_cache',
				'blog_id',
				'table_prefix',
				'_wp_switched_stack',
				'switched',
				'current_blog',
				'current_site',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'_wp_using_ext_object_cache',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		$server = array();
		foreach ( array( 'HTTP_HOST', 'SERVER_NAME', 'REQUEST_URI', 'HTTPS' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals'                  => $globals,
			'server'                   => $server,
			'installing'               => function_exists( 'wp_installing' ) ? wp_installing() : null,
			'using_ext_object_cache'   => function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : null,
			'cache_addition_suspended' => function_exists( 'wp_suspend_cache_addition' ) ? wp_suspend_cache_addition() : null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		if ( function_exists( 'wp_installing' ) && is_bool( $snapshot['installing'] ) ) {
			wp_installing( $snapshot['installing'] );
		}
		if ( function_exists( 'wp_using_ext_object_cache' ) && is_bool( $snapshot['using_ext_object_cache'] ) ) {
			wp_using_ext_object_cache( $snapshot['using_ext_object_cache'] );
		}
		if ( function_exists( 'wp_suspend_cache_addition' ) && is_bool( $snapshot['cache_addition_suspended'] ) ) {
			wp_suspend_cache_addition( $snapshot['cache_addition_suspended'] );
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $name ] = $entry['value'];
			} else {
				unset( $_SERVER[ $name ] );
			}
		}
	}

	private static function globals_match_snapshot( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
			if ( $entry['exists'] && $entry['value'] !== $GLOBALS[ $name ] ) {
				return false;
			}
		}

		foreach ( $snapshot['server'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $_SERVER ) ) {
				return false;
			}
			if ( $entry['exists'] && $entry['value'] !== $_SERVER[ $name ] ) {
				return false;
			}
		}

		return true;
	}

	private static function clear_static_state(): void {
		self::$blog_options    = array();
		self::$network_options = array();
		self::$main_site_ids   = array();
		self::$user_blog_memberships = array();
		self::$path_lookup_log       = array();
		self::$sites           = array();
		self::$networks        = array();
	}

	private static function clear_legacy_blog_identity_caches( array $case ): void {
		foreach ( array( 1, $case['siteId'], $case['directorySiteId'] ) as $site_id ) {
			wp_cache_delete( $site_id, 'site-details' );
			wp_cache_delete( $site_id, 'blog-details' );
			wp_cache_delete( $site_id . 'short', 'blog-details' );
		}

		foreach (
			array(
				'example.test/',
				$case['siteDomain'] . $case['sitePath'],
				'www.' . $case['siteDomain'] . $case['sitePath'],
				'example.test' . $case['directoryPath'],
				'missing.' . $case['siteDomain'] . '/missing/',
			) as $domain_path
		) {
			$key = md5( strtolower( $domain_path ) );
			wp_cache_delete( $key, 'blog-lookup' );
			wp_cache_delete( $key, 'blog-id-cache' );
		}
	}

	private static function make_wpdb_stub( array $options ): \Component_Fuzz_WPDB_Stub {
		return new class( $options ) extends \Component_Fuzz_WPDB_Stub {
			public $blogs = 'wp_blogs';
			public $site = 'wp_site';
			public $sitemeta = 'wp_sitemeta';
			public $blogid = 1;

			public function set_blog_id( $blog_id, $network_id = 0 ) {
				unset( $network_id );

				$old_blog_id  = $this->blogid;
				$this->blogid = (int) $blog_id;
				$this->prefix = $this->get_blog_prefix( $this->blogid );

				return $old_blog_id;
			}

			public function get_blog_prefix( $blog_id = null ) {
				$blog_id = null === $blog_id ? $this->blogid : (int) $blog_id;

				return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
			}
		};
	}

	private static function force_cache_multisite_mode( int $blog_id ): void {
		if ( ! isset( $GLOBALS['wp_object_cache'] ) || ! is_object( $GLOBALS['wp_object_cache'] ) ) {
			return;
		}

		$reflection = new \ReflectionObject( $GLOBALS['wp_object_cache'] );
		foreach (
			array(
				'multisite'   => true,
				'blog_prefix' => $blog_id . ':',
			) as $property => $value
		) {
			if ( ! $reflection->hasProperty( $property ) ) {
				continue;
			}

			$reflected_property = $reflection->getProperty( $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflected_property->setAccessible( true );
			}
			$reflected_property->setValue( $GLOBALS['wp_object_cache'], $value );
		}
	}

	private static function site_from_parts(
		int $blog_id,
		string $domain,
		string $path,
		int $network_id,
		int $public,
		int $archived,
		int $mature,
		int $spam,
		int $deleted
	): \WP_Site {
		$normalized = wp_normalize_site_data(
			array(
				'domain'     => $domain,
				'path'       => $path,
				'network_id' => $network_id,
				'public'     => $public,
				'archived'   => $archived,
				'mature'     => $mature,
				'spam'       => $spam,
				'deleted'    => $deleted,
			)
		);

		return self::site_from_normalized( $blog_id, $normalized );
	}

	private static function site_from_normalized( int $blog_id, array $normalized ): \WP_Site {
		return new \WP_Site(
			(object) array(
				'blog_id'      => (string) $blog_id,
				'domain'       => (string) $normalized['domain'],
				'path'         => (string) $normalized['path'],
				'site_id'      => (string) $normalized['network_id'],
				'registered'   => '2026-06-22 00:00:00',
				'last_updated' => '2026-06-22 00:00:00',
				'public'       => $normalized['public'] ?? 1,
				'archived'     => $normalized['archived'] ?? 0,
				'mature'       => $normalized['mature'] ?? 0,
				'spam'         => $normalized['spam'] ?? 0,
				'deleted'      => $normalized['deleted'] ?? 0,
				'lang_id'      => $normalized['lang_id'] ?? 0,
			)
		);
	}

	private static function network_from_parts( int $network_id, string $domain, string $path, int $main_site_id, string $site_name ): \WP_Network {
		return new \WP_Network(
			(object) array(
				'id'            => (string) $network_id,
				'domain'        => $domain,
				'path'          => trailingslashit( '/' . trim( $path, '/' ) ),
				'blog_id'       => (string) $main_site_id,
				'cookie_domain' => '',
				'site_name'     => $site_name,
			)
		);
	}

	private static function network_raw_object( \WP_Network $network ): object {
		return (object) array(
			'id'            => (string) $network->id,
			'domain'        => $network->domain,
			'path'          => $network->path,
			'blog_id'       => (string) $network->site_id,
			'cookie_domain' => '',
			'site_name'     => $network->site_name,
		);
	}

	private static function site_by_id( int $site_id ): ?\WP_Site {
		foreach ( self::$sites as $site ) {
			if ( $site->id === $site_id ) {
				return $site;
			}
		}

		return null;
	}

	private static function domain_matches_request( string $stored_domain, string $request_domain, bool $allow_suffix = false ): bool {
		$stored_domain  = strtolower( $stored_domain );
		$request_domain = strtolower( $request_domain );

		if ( $stored_domain === $request_domain ) {
			return true;
		}

		if ( str_starts_with( $request_domain, 'www.' ) && substr( $request_domain, 4 ) === $stored_domain ) {
			return true;
		}

		return $allow_suffix && str_ends_with( $request_domain, '.' . $stored_domain );
	}

	private static function site_matches_query( \WP_Site $site, array $vars ): bool {
		if ( ! empty( $vars['ID'] ) && $site->id !== (int) $vars['ID'] ) {
			return false;
		}
		if ( ! empty( $vars['site__in'] ) && ! in_array( $site->id, wp_parse_id_list( $vars['site__in'] ), true ) ) {
			return false;
		}
		if ( ! empty( $vars['site__not_in'] ) && in_array( $site->id, wp_parse_id_list( $vars['site__not_in'] ), true ) ) {
			return false;
		}
		if ( ! empty( $vars['network_id'] ) && $site->network_id !== (int) $vars['network_id'] ) {
			return false;
		}
		if ( ! empty( $vars['network__in'] ) && ! in_array( $site->network_id, wp_parse_id_list( $vars['network__in'] ), true ) ) {
			return false;
		}
		if ( ! empty( $vars['network__not_in'] ) && in_array( $site->network_id, wp_parse_id_list( $vars['network__not_in'] ), true ) ) {
			return false;
		}
		if ( '' !== ( $vars['domain'] ?? '' ) && $site->domain !== $vars['domain'] ) {
			return false;
		}
		if ( is_array( $vars['domain__in'] ?? null ) && ! in_array( $site->domain, $vars['domain__in'], true ) ) {
			return false;
		}
		if ( is_array( $vars['domain__not_in'] ?? null ) && in_array( $site->domain, $vars['domain__not_in'], true ) ) {
			return false;
		}
		if ( '' !== ( $vars['path'] ?? '' ) && $site->path !== $vars['path'] ) {
			return false;
		}
		if ( is_array( $vars['path__in'] ?? null ) && ! in_array( $site->path, $vars['path__in'], true ) ) {
			return false;
		}
		if ( is_array( $vars['path__not_in'] ?? null ) && in_array( $site->path, $vars['path__not_in'], true ) ) {
			return false;
		}

		foreach ( array( 'public', 'archived', 'mature', 'spam', 'deleted' ) as $status ) {
			if ( is_numeric( $vars[ $status ] ?? null ) && (int) $site->$status !== (int) $vars[ $status ] ) {
				return false;
			}
		}

		if ( '' !== ( $vars['search'] ?? '' ) ) {
			$needle = strtolower( (string) $vars['search'] );
			if ( ! str_contains( strtolower( $site->domain ), $needle ) && ! str_contains( strtolower( $site->path ), $needle ) ) {
				return false;
			}
		}

		return true;
	}

	private static function network_matches_query( \WP_Network $network, array $vars ): bool {
		if ( ! empty( $vars['network__in'] ) && ! in_array( $network->id, wp_parse_id_list( $vars['network__in'] ), true ) ) {
			return false;
		}
		if ( ! empty( $vars['network__not_in'] ) && in_array( $network->id, wp_parse_id_list( $vars['network__not_in'] ), true ) ) {
			return false;
		}
		if ( '' !== ( $vars['domain'] ?? '' ) && $network->domain !== $vars['domain'] ) {
			return false;
		}
		if ( is_array( $vars['domain__in'] ?? null ) && ! in_array( $network->domain, $vars['domain__in'], true ) ) {
			return false;
		}
		if ( is_array( $vars['domain__not_in'] ?? null ) && in_array( $network->domain, $vars['domain__not_in'], true ) ) {
			return false;
		}
		if ( '' !== ( $vars['path'] ?? '' ) && $network->path !== $vars['path'] ) {
			return false;
		}
		if ( is_array( $vars['path__in'] ?? null ) && ! in_array( $network->path, $vars['path__in'], true ) ) {
			return false;
		}
		if ( is_array( $vars['path__not_in'] ?? null ) && in_array( $network->path, $vars['path__not_in'], true ) ) {
			return false;
		}
		if ( '' !== ( $vars['search'] ?? '' ) ) {
			$needle = strtolower( (string) $vars['search'] );
			if ( ! str_contains( strtolower( $network->domain ), $needle ) && ! str_contains( strtolower( $network->path ), $needle ) ) {
				return false;
			}
		}

		return true;
	}

	private static function slice_results( array $items, array $vars ): array {
		$offset = isset( $vars['offset'] ) ? max( 0, (int) $vars['offset'] ) : 0;
		$number = isset( $vars['number'] ) ? max( 0, (int) $vars['number'] ) : 0;

		if ( 0 < $number ) {
			return array_slice( $items, $offset, $number );
		}

		if ( 0 < $offset ) {
			return array_slice( $items, $offset );
		}

		return $items;
	}

	private static function max_pages( int $total, $number ): int {
		$number = (int) $number;
		if ( 0 >= $number ) {
			return 0;
		}

		return (int) ceil( $total / $number );
	}

	private static function invoke_protected( object $object, string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invokeArgs( $object, $args );
	}

	private static function same_value( $left, $right ): bool {
		return maybe_serialize( $left ) === maybe_serialize( $right );
	}

	private static function slug( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$slug = strtolower( $prefix . '-' . $ctx->identifier( 4, 14 ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		return '' === $slug ? $prefix : $slug;
	}

	private static function describe_case( array $case ): array {
		return array(
			'caseSeed'      => $case['caseSeed'],
			'networkId'     => $case['networkId'],
			'siteId'        => $case['siteId'],
			'directorySiteId' => $case['directorySiteId'],
			'userId'        => $case['userId'],
			'networkDomain' => $case['networkDomain'],
			'siteDomain'    => $case['siteDomain'],
			'networkPath'   => $case['networkPath'],
			'sitePath'      => $case['sitePath'],
			'directorySlug' => $case['directorySlug'],
			'directoryPath' => $case['directoryPath'],
			'uploadTime'    => $case['uploadTime'],
			'trueMsToken'   => $case['trueMsToken'],
		);
	}

	private static function describe_site( $site ): array {
		if ( ! $site instanceof \WP_Site ) {
			return array( 'type' => is_object( $site ) ? get_class( $site ) : gettype( $site ) );
		}

		return array(
			'id'         => $site->id,
			'networkId'  => $site->network_id,
			'domain'     => $site->domain,
			'path'       => $site->path,
			'public'     => $site->public,
			'archived'   => $site->archived,
			'deleted'    => $site->deleted,
		);
	}

	private static function describe_network( $network ): array {
		if ( ! $network instanceof \WP_Network ) {
			return array( 'type' => is_object( $network ) ? get_class( $network ) : gettype( $network ) );
		}

		return array(
			'id'           => $network->id,
			'mainSiteId'   => $network->site_id,
			'domain'       => $network->domain,
			'path'         => $network->path,
			'cookieDomain' => $network->cookie_domain,
			'siteName'     => $network->site_name,
		);
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) > 180 ? substr( $value, 0, 180 ) . '...' : $value;
		}
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			return '[' . gettype( $value ) . ']';
		}

		return strlen( $json ) > 180 ? substr( $json, 0, 180 ) . '...' : $json;
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
