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
			self::reset_runtime( $ctx );
			self::install_filters();

			$rows[] = self::check_site_objects_and_normalization( $ctx );
			$rows[] = self::check_network_objects_and_main_site( $ctx );
			$rows[] = self::check_switch_stack_and_cache_context( $ctx );
			$rows[] = self::check_network_option_helpers( $ctx );
			$rows[] = self::check_site_and_network_queries( $ctx );
			$rows[] = self::check_url_helpers( $ctx );
			$rows[] = self::check_restoration_probe( $ctx, $snapshot );

			if ( ! is_multisite() ) {
				$rows[] = $ctx->skip(
					'multisite.network-options.true-multisite-sitemeta-writes',
					'The harness does not enable MULTISITE globally, so direct sitemeta write paths are skipped; '
						. 'reads and non-multisite network-option CRUD remain covered without DB writes.'
				);
			}
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
				'add_network_option',
				'delete_network_option',
				'get_admin_url',
				'get_current_blog_id',
				'get_network',
				'get_network_option',
				'get_networks',
				'get_site',
				'get_site_url',
				'get_sites',
				'has_filter',
				'is_multisite',
				'network_home_url',
				'network_site_url',
				'remove_filter',
				'restore_current_blog',
				'switch_to_blog',
				'update_network_option',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_init',
				'wp_cache_set',
				'wp_normalize_site_data',
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
			&& 2 === $site_count
			&& array( 1, 2 ) === $network_ids
			&& 1 === count( $network_objects )
			&& $network_objects[0] instanceof \WP_Network
			&& 2 === $network_objects[0]->id
			&& 2 === $network_count
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

	private static function check_restoration_probe( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$mutated = get_current_blog_id() !== ( $snapshot['globals']['blog_id']['value'] ?? null )
			|| $GLOBALS['wpdb'] !== ( $snapshot['globals']['wpdb']['value'] ?? null )
			|| false !== has_filter( 'pre_option', array( self::class, 'filter_option' ) )
			|| false !== has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) );

		return $ctx->result(
			'multisite.state.restoration-probe-mutates-scoped-state',
			$mutated,
			array(
				'currentBlogId' => get_current_blog_id(),
				'hasOptionFilter' => has_filter( 'pre_option', array( self::class, 'filter_option' ) ),
				'hasSitesFilter' => has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) ),
			)
		);
	}

	private static function check_post_restore_state( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$ok = self::globals_match_snapshot( $snapshot )
			&& false === has_filter( 'pre_option', array( self::class, 'filter_option' ) )
			&& false === has_filter( 'pre_site_option', array( self::class, 'filter_network_option' ) )
			&& false === has_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ) )
			&& false === has_filter( 'networks_pre_query', array( self::class, 'filter_networks_pre_query' ) )
			&& array() === self::$blog_options
			&& array() === self::$network_options
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

	private static function reset_runtime( \ComponentFuzz\FuzzContext $ctx ): void {
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
				'home'        => 'http://example.test',
				'siteurl'     => 'http://example.test/wp',
				'blogname'    => 'Main Component Fuzz Site',
				'admin_email' => 'main@example.test',
			),
			17 => array(
				'home'        => 'http://blog17.example.test',
				'siteurl'     => 'http://blog17.example.test/app',
				'blogname'    => 'Switched Component Fuzz Site',
				'admin_email' => 'blog17@example.test',
			),
			23 => array(
				'home'        => 'http://network-two.example.test/archive',
				'siteurl'     => 'http://network-two.example.test/archive/wp',
				'blogname'    => 'Archived Component Fuzz Site',
				'admin_email' => 'blog23@example.test',
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
		);
		self::$main_site_ids = array(
			1 => 1,
			2 => 23,
		);

		self::$sites = array(
			self::site_from_parts( 1, 'example.test', '/', 1, 1, 0, 0, 0, 0 ),
			self::site_from_parts( 17, 'blog17.example.test', '/', 1, 1, 0, 0, 0, 0 ),
			self::site_from_parts( 23, 'network-two.example.test', '/archive/', 2, 0, 1, 0, 0, 0 ),
		);
		self::$networks = array(
			self::network_from_parts( 1, 'example.test', '/', 1, 'Primary Component Fuzz Network' ),
			self::network_from_parts( 2, 'network-two.example.test', '/archive/', 23, 'Secondary Component Fuzz Network' ),
		);

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
	}

	private static function remove_filters(): void {
		remove_filter( 'pre_option', array( self::class, 'filter_option' ), 10 );
		remove_filter( 'pre_site_option', array( self::class, 'filter_network_option' ), 10 );
		remove_filter( 'pre_get_main_site_id', array( self::class, 'filter_main_site_id' ), 10 );
		remove_filter( 'sites_pre_query', array( self::class, 'filter_sites_pre_query' ), 10 );
		remove_filter( 'networks_pre_query', array( self::class, 'filter_networks_pre_query' ), 10 );
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
		self::$sites           = array();
		self::$networks        = array();
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
