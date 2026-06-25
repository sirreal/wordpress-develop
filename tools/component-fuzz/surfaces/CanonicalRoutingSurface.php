<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB canonical redirect and front-end routing helpers.
 */
final class CanonicalRoutingSurface {
	public const NAME = 'canonical-routing';

	private const HOME_URL = 'http://example.test/site-base';
	private const SITE_URL = 'http://example.test/site-base/wp';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'canonical-routing.bootstrap-apis-available',
					'Required WordPress canonical APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::install_option_filters();
			$case = self::case_for_context( $ctx );

			$rows[] = self::check_path_query_and_host_cleanup( $ctx->fork( 'cleanup' ), $case );
			$rows[] = self::check_cleanup_variant_matrix( $ctx->fork( 'cleanup-matrix' ), $case );
			$rows[] = self::check_early_bailouts( $ctx->fork( 'bailouts' ), $case );
			$rows[] = self::check_invalid_date_redirect( $ctx->fork( 'date' ), $case );
			$rows[] = self::check_feed_and_paged_redirect( $ctx->fork( 'feed' ), $case );
			$rows[] = self::check_redirect_filter_contract( $ctx->fork( 'filter' ), $case );
			$rows[] = self::check_safe_redirect_replacement_is_returned( $ctx->fork( 'safe-filter' ), $case );
			$rows[] = self::check_unsafe_redirect_replacement_is_cancelled( $ctx->fork( 'unsafe-filter' ), $case );
			$rows[] = self::check_trailing_slash_modes( $ctx->fork( 'slashes' ), $case );
			$rows[] = self::check_canonical_helpers( $ctx->fork( 'helpers' ), $case );
			$rows[] = self::check_canonical_helper_matrix( $ctx->fork( 'helper-matrix' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'canonical-routing.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'canonical-routing.global-state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedServer'  => array_keys( $snapshot['server'] ),
			)
		);

		return $rows;
	}

	public static function filter_home(): string {
		return self::HOME_URL;
	}

	public static function filter_siteurl(): string {
		return self::SITE_URL;
	}

	public static function filter_permalink_structure(): string {
		return '/%year%/%monthnum%/%postname%/';
	}

	public static function filter_blog_charset(): string {
		return 'UTF-8';
	}

	public static function filter_html_type(): string {
		return 'text/html';
	}

	public static function filter_default_feed(): string {
		return 'rss2';
	}

	public static function filter_false() {
		return false;
	}

	public static function filter_zero() {
		return 0;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Query', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_remove_qs_args_if_not_in_url',
				'add_filter',
				'add_query_arg',
				'get_day_link',
				'get_month_link',
				'get_query_var',
				'get_year_link',
				'home_url',
				'is_404',
				'is_feed',
				'redirect_canonical',
				'remove_query_arg',
				'remove_filter',
				'strip_fragment_from_url',
				'user_trailingslashit',
				'wp_parse_url',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_path_query_and_host_cleanup( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_request(
			array(),
			array(
				'is_home' => true,
			)
		);

		$post_id   = (string) $ctx->int( 10, 9999 );
		$requested = 'http://www.example.test/site-base/' . $case['dirtyPath'] . '?p=' . $post_id . '.&feed=rss&keep=' . rawurlencode( $case['keep'] );
		$redirect  = \redirect_canonical( $requested, false );
		$parts     = is_string( $redirect ) ? \wp_parse_url( $redirect ) : array();
		$query     = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		$idempotent = is_string( $redirect ) ? \redirect_canonical( $redirect, false ) : 'not-checked';

		$path = (string) ( $parts['path'] ?? '' );
		$ok   = is_string( $redirect )
			&& 'example.test' === ( $parts['host'] ?? null )
			&& ! str_contains( $path, '//' )
			&& ! str_contains( $path, '/index.php/' )
			&& ! preg_match( '/(?:%20|[ !"\',.;{}]|%21|%22|%27|%2C|%2E|%3B|%7B|%7D)$/i', $path )
			&& $post_id . '.' === ( $query['p'] ?? null )
			&& 'rss2' === ( $query['feed'] ?? null )
			&& $case['keep'] === ( $query['keep'] ?? null )
			&& null === $idempotent;

		return $ctx->result(
			'canonical-routing.cleanup.host-path-query-idempotent',
			$ok,
			array(
				'requested'  => $requested,
				'redirect'   => $redirect,
				'parts'      => $parts,
				'query'      => $query,
				'idempotent' => $idempotent,
			)
		);
	}

	private static function check_cleanup_variant_matrix( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$variants = array(
			array(
				'label'         => 'terminal-tag-space',
				'host'          => 'example.test',
				'path'          => 'archive//' . rawurlencode( $case['token'] ) . '/index.php/%E2%80%9D',
				'query'         => 'feed=rss&tag=' . rawurlencode( 'tag-' . $case['token'] ) . '%20',
				'expectQuery'   => array(
					'feed' => 'rss2',
					'tag'  => 'tag-' . $case['token'],
				),
				'pathMissing'   => array( '//', '/index.php/', '%E2%80%9D' ),
				'pathContains'  => array( '/site-base/archive/' ),
				'expectedHost'  => 'example.test',
			),
			array(
				'label'         => 'malformed-octet-preserved',
				'host'          => 'example.test',
				'path'          => 'bad/%zz//' . rawurlencode( $case['token'] ) . '/index.php/.',
				'query'         => 'keep=' . rawurlencode( $case['keep'] ),
				'expectQuery'   => array(
					'keep' => $case['keep'],
				),
				'pathMissing'   => array( '//', '/index.php/' ),
				'pathContains'  => array( '%zz' ),
				'expectedHost'  => 'example.test',
			),
			array(
				'label'         => 'www-host-normalized',
				'host'          => 'www.example.test',
				'path'          => 'library//' . rawurlencode( $case['token'] ) . '/%C2%A0%C2%A0',
				'query'         => 'p=' . $ctx->int( 100, 999 ) . '.&keep=' . rawurlencode( $case['keep'] ),
				'expectQuery'   => array(
					'keep' => $case['keep'],
				),
				'pathMissing'   => array( '//', '%C2%A0' ),
				'pathContains'  => array( '/site-base/library/' ),
				'expectedHost'  => 'example.test',
			),
		);

		$failures = array();
		foreach ( $variants as $variant ) {
			self::prepare_request( array(), array( 'is_home' => true ) );

			$requested = 'http://' . $variant['host'] . '/site-base/' . $variant['path'] . '?' . $variant['query'];
			$redirect  = \redirect_canonical( $requested, false );
			$parts     = is_string( $redirect ) ? \wp_parse_url( $redirect ) : array();
			$query     = self::parse_query_from_parts( $parts );
			$path      = (string) ( $parts['path'] ?? '' );

			$case_ok = is_string( $redirect )
				&& $variant['expectedHost'] === ( $parts['host'] ?? null )
				&& ! self::path_has_trailing_cleanup_junk( $path );

			foreach ( $variant['pathMissing'] as $needle ) {
				$case_ok = $case_ok && ! str_contains( $path, $needle );
			}

			foreach ( $variant['pathContains'] as $needle ) {
				$case_ok = $case_ok && str_contains( $path, $needle );
			}

			foreach ( $variant['expectQuery'] as $key => $value ) {
				$case_ok = $case_ok && $value === ( $query[ $key ] ?? null );
			}

			if ( ! $case_ok ) {
				$failures[] = array(
					'label'     => $variant['label'],
					'requested' => $requested,
					'redirect'  => $redirect,
					'parts'     => $parts,
					'query'     => $query,
				);
			}
		}

		return $ctx->result(
			'canonical-routing.cleanup-variant-matrix',
			array() === $failures,
			array(
				'checked'  => count( $variants ),
				'failures' => array_slice( $failures, 0, 3 ),
			)
		);
	}

	private static function check_early_bailouts( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_request( array(), array( 'is_home' => true ), 'POST' );
		$post_result = \redirect_canonical( self::HOME_URL . '/' . $case['dirtyPath'] . '?feed=rss', false );

		self::prepare_request( array( 's' => $case['search'] ), array( 'is_search' => true ), 'GET' );
		$search_result = \redirect_canonical( self::HOME_URL . '/' . $case['dirtyPath'] . '?s=' . rawurlencode( $case['search'] ), false );

		self::prepare_request( array( 'preview' => 1 ), array( 'is_preview' => true ), 'GET' );
		$preview_result = \redirect_canonical( self::HOME_URL . '/' . $case['dirtyPath'] . '?preview=1', false );

		return $ctx->result(
			'canonical-routing.early-bailouts-no-redirect',
			null === $post_result && null === $search_result && null === $preview_result,
			array(
				'post'    => $post_result,
				'search'  => $search_result,
				'preview' => $preview_result,
			)
		);
	}

	private static function check_invalid_date_redirect( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$year  = (string) $ctx->int( 1999, 2032 );
		$month = '02';
		$day   = (string) $ctx->choice( array( 30, 31 ) );
		$vars  = array(
			'year'     => $year,
			'monthnum' => $month,
			'day'      => $day,
		);

		self::prepare_request(
			$vars,
			array(
				'is_404'  => true,
				'is_date' => true,
				'is_day'  => true,
			)
		);

		$requested = self::HOME_URL . '/?year=' . $year . '&monthnum=' . $month . '&day=' . $day . '&keep=' . rawurlencode( $case['keep'] );
		$redirect  = \redirect_canonical( $requested, false );
		$parts     = is_string( $redirect ) ? \wp_parse_url( $redirect ) : array();
		$query     = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		$expected_path = \wp_parse_url( \get_month_link( $year, $month ), PHP_URL_PATH );
		$ok            = is_string( $redirect )
			&& $expected_path === ( $parts['path'] ?? null )
			&& $case['keep'] === ( $query['keep'] ?? null )
			&& ! isset( $query['year'], $query['monthnum'], $query['day'] );

		return $ctx->result(
			'canonical-routing.invalid-day-canonicalizes-to-month',
			$ok,
			array(
				'requested'    => $requested,
				'redirect'     => $redirect,
				'expectedPath' => $expected_path,
				'query'        => $query,
			)
		);
	}

	private static function check_feed_and_paged_redirect( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_request(
			array(
				'feed'  => 'rss',
				'paged' => 2,
			),
			array(
				'is_home' => true,
				'is_feed' => true,
			)
		);

		$requested = self::HOME_URL . '/' . $case['feedBase'] . '/?feed=rss&paged=2';
		$redirect  = \redirect_canonical( $requested, false );
		$parts     = is_string( $redirect ) ? \wp_parse_url( $redirect ) : array();
		$query     = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		$ok = is_string( $redirect )
			&& str_ends_with( (string) ( $parts['path'] ?? '' ), '/feed/' )
			&& '2' === ( $query['paged'] ?? null )
			&& ! isset( $query['feed'] );

		return $ctx->result(
			'canonical-routing.feed-and-paged-query-vars-move-to-path',
			$ok,
			array(
				'requested' => $requested,
				'redirect'  => $redirect,
				'path'      => $parts['path'] ?? null,
				'query'     => $query,
			)
		);
	}

	private static function check_redirect_filter_contract( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_request( array(), array( 'is_home' => true ) );

		$seen   = array();
		$filter = static function ( $redirect_url, $requested_url ) use ( &$seen ) {
			$seen[] = array(
				'redirect'  => $redirect_url,
				'requested' => $requested_url,
			);
			return false;
		};

		$requested = self::HOME_URL . '/' . $case['dirtyPath'] . '?feed=rss';
		\add_filter( 'redirect_canonical', $filter, 10, 2 );
		try {
			$result = \redirect_canonical( $requested, false );
		} finally {
			\remove_filter( 'redirect_canonical', $filter, 10 );
		}

		$ok = null === $result
			&& 1 === count( $seen )
			&& is_string( $seen[0]['redirect'] ?? null )
			&& self::lowercase_octets( $requested ) === ( $seen[0]['requested'] ?? null )
			&& false === \has_filter( 'redirect_canonical', $filter );

		return $ctx->result(
			'canonical-routing.redirect-filter-can-cancel',
			$ok,
			array(
				'requested' => $requested,
				'result'    => $result,
				'seen'      => $seen,
			)
		);
	}

	private static function check_safe_redirect_replacement_is_returned( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_request( array(), array( 'is_home' => true ) );

		$seen         = array();
		$replacement  = self::HOME_URL . '/filtered/' . rawurlencode( $case['token'] ) . '/?keep=' . rawurlencode( $case['keep'] );
		$final_marker = 'filtered-' . $ctx->identifier( 4, 10 );
		$replace      = static function ( $redirect_url, $requested_url ) use ( &$seen, $replacement ) {
			$seen[] = array(
				'priority'  => 9,
				'redirect'  => $redirect_url,
				'requested' => $requested_url,
			);

			return $replacement;
		};
		$append       = static function ( $redirect_url, $requested_url ) use ( &$seen, $final_marker ) {
			$seen[] = array(
				'priority'  => 11,
				'redirect'  => $redirect_url,
				'requested' => $requested_url,
			);

			return add_query_arg( 'cfz', $final_marker, $redirect_url );
		};

		$requested = self::HOME_URL . '/' . $case['dirtyPath'] . '?feed=rss&keep=' . rawurlencode( $case['keep'] );
		\add_filter( 'redirect_canonical', $replace, 9, 2 );
		\add_filter( 'redirect_canonical', $append, 11, 2 );
		try {
			$result = \redirect_canonical( $requested, false );
		} finally {
			\remove_filter( 'redirect_canonical', $replace, 9 );
			\remove_filter( 'redirect_canonical', $append, 11 );
		}

		$parts = is_string( $result ) ? \wp_parse_url( $result ) : array();
		$query = self::parse_query_from_parts( $parts );
		$ok    = is_string( $result )
			&& 2 === count( $seen )
			&& array( 9, 11 ) === array_column( $seen, 'priority' )
			&& is_string( $seen[0]['redirect'] ?? null )
			&& $replacement === ( $seen[1]['redirect'] ?? null )
			&& self::lowercase_octets( $requested ) === ( $seen[0]['requested'] ?? null )
			&& self::lowercase_octets( $requested ) === ( $seen[1]['requested'] ?? null )
			&& 'example.test' === ( $parts['host'] ?? null )
			&& '/site-base/filtered/' . rawurlencode( $case['token'] ) . '/' === ( $parts['path'] ?? null )
			&& $case['keep'] === ( $query['keep'] ?? null )
			&& $final_marker === ( $query['cfz'] ?? null )
			&& false === \has_filter( 'redirect_canonical', $replace )
			&& false === \has_filter( 'redirect_canonical', $append );

		return $ctx->result(
			'canonical-routing.redirect-filter-safe-replacement-cascade',
			$ok,
			array(
				'requested'    => $requested,
				'replacement'  => $replacement,
				'result'       => $result,
				'parts'        => $parts,
				'query'        => $query,
				'seen'         => $seen,
				'finalMarker'  => $final_marker,
			)
		);
	}

	private static function check_unsafe_redirect_replacement_is_cancelled( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::prepare_request( array(), array( 'is_home' => true ) );

		$seen    = array();
		$replace = static function ( $redirect_url, $requested_url ) use ( &$seen ) {
			$seen['originalRedirect'] = $redirect_url;
			$seen['requested']        = $requested_url;

			return 'https://evil.test/collect?next=' . rawurlencode( $requested_url );
		};
		$cancel  = static function ( $redirect_url, $requested_url ) use ( &$seen ) {
			$parts                 = \wp_parse_url( (string) $redirect_url );
			$seen['candidateHost'] = $parts['host'] ?? null;
			$seen['candidateUrl']  = $redirect_url;

			if ( 'example.test' !== ( $parts['host'] ?? null ) ) {
				return false;
			}

			return $redirect_url;
		};

		$requested = self::HOME_URL . '/' . $case['dirtyPath'] . '?feed=rss&keep=' . rawurlencode( $case['keep'] );
		\add_filter( 'redirect_canonical', $replace, 9, 2 );
		\add_filter( 'redirect_canonical', $cancel, 10, 2 );
		try {
			$result = \redirect_canonical( $requested, false );
		} finally {
			\remove_filter( 'redirect_canonical', $replace, 9 );
			\remove_filter( 'redirect_canonical', $cancel, 10 );
		}

		$ok = null === $result
			&& is_string( $seen['originalRedirect'] ?? null )
			&& self::lowercase_octets( $requested ) === ( $seen['requested'] ?? null )
			&& 'evil.test' === ( $seen['candidateHost'] ?? null )
			&& false === \has_filter( 'redirect_canonical', $replace )
			&& false === \has_filter( 'redirect_canonical', $cancel );

		return $ctx->result(
			'canonical-routing.redirect-filter-cancels-unsafe-replacement',
			$ok,
			array(
				'requested' => $requested,
				'result'    => $result,
				'seen'      => $seen,
			)
		);
	}

	private static function check_trailing_slash_modes( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		global $wp_rewrite;

		self::prepare_request( array(), array( 'is_home' => true ) );

		$seen   = array();
		$filter = static function ( $url, $type ) use ( &$seen ) {
			$seen[] = array(
				'url'  => $url,
				'type' => $type,
			);

			return $url;
		};

		\add_filter( 'user_trailingslashit', $filter, 10, 2 );
		try {
			$wp_rewrite->use_trailing_slashes = true;
			$single_with_slash                = \user_trailingslashit( '/site-base/' . $case['token'], 'single' );

			$wp_rewrite->use_trailing_slashes = false;
			$paged_without_slash              = \user_trailingslashit( '/site-base/' . $case['token'] . '/', 'paged' );
		} finally {
			\remove_filter( 'user_trailingslashit', $filter, 10 );
		}

		$ok = '/site-base/' . $case['token'] . '/' === $single_with_slash
			&& '/site-base/' . $case['token'] === $paged_without_slash
			&& array( 'single', 'paged' ) === array_column( $seen, 'type' )
			&& false === \has_filter( 'user_trailingslashit', $filter );

		return $ctx->result(
			'canonical-routing.trailing-slash-modes-respect-rewrite-state',
			$ok,
			array(
				'singleWithSlash'   => $single_with_slash,
				'pagedWithoutSlash' => $paged_without_slash,
				'filterSeen'        => $seen,
			)
		);
	}

	private static function check_canonical_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$url            = self::HOME_URL . '/route/' . rawurlencode( $case['keep'] ) . '/?p=123&keep=' . rawurlencode( $case['keep'] ) . '#frag-' . rawurlencode( $case['token'] );
		$stripped       = \strip_fragment_from_url( $url );
		$query          = 'p=123&page_id=55&attachment_id=77&keep=' . rawurlencode( $case['keep'] );
		$args_removed   = \_remove_qs_args_if_not_in_url( $query, array( 'p', 'page_id', 'attachment_id' ), self::HOME_URL . '/target/?page_id=55' );
		$args_preserved = \_remove_qs_args_if_not_in_url( $query, array( 'p', 'page_id' ), self::HOME_URL . '/target/?p=123&page_id=55' );

		parse_str( $args_removed, $removed_query );
		parse_str( $args_preserved, $preserved_query );

		$ok = ! str_contains( $stripped, '#frag' )
			&& str_contains( $stripped, '?p=123&keep=' )
			&& ! isset( $removed_query['p'], $removed_query['attachment_id'] )
			&& '55' === ( $removed_query['page_id'] ?? null )
			&& $case['keep'] === ( $removed_query['keep'] ?? null )
			&& '123' === ( $preserved_query['p'] ?? null )
			&& '55' === ( $preserved_query['page_id'] ?? null );

		return $ctx->result(
			'canonical-routing.helper-fragment-and-query-arg-contracts',
			$ok,
			array(
				'url'            => $url,
				'stripped'       => $stripped,
				'argsRemoved'    => $args_removed,
				'removedQuery'   => $removed_query,
				'argsPreserved'  => $args_preserved,
				'preservedQuery' => $preserved_query,
			)
		);
	}

	private static function check_canonical_helper_matrix( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$base_query = array(
			'p'             => (string) $ctx->int( 100, 999 ),
			'page_id'       => (string) $ctx->int( 1000, 1999 ),
			'attachment_id' => (string) $ctx->int( 2000, 2999 ),
			'name'          => 'post ' . $case['token'],
			'empty'         => '',
			'keep'          => $case['keep'],
		);
		$query_string = http_build_query( $base_query, '', '&', PHP_QUERY_RFC3986 );

		$removal_cases = array(
			array(
				'label' => 'present-target-keys-survive-by-name',
				'args'  => array( 'p', 'page_id', 'attachment_id', 'empty' ),
				'url'   => self::HOME_URL . '/target/?p=999&attachment_id=0&empty=',
			),
			array(
				'label' => 'empty-target-query-removes-all-checked-keys',
				'args'  => array( 'p', 'page_id', 'attachment_id', 'name' ),
				'url'   => self::HOME_URL . '/target/?',
			),
			array(
				'label' => 'unrelated-target-query-removes-checked-preserves-other',
				'args'  => array( 'p', 'page_id', 'attachment_id', 'missing' ),
				'url'   => self::HOME_URL . '/target/?keep=' . rawurlencode( $case['keep'] ),
			),
			array(
				'label' => 'encoded-values-do-not-affect-presence',
				'args'  => array( 'name', 'keep', 'empty' ),
				'url'   => self::HOME_URL . '/target/?name=' . rawurlencode( 'different ' . $case['token'] ) . '&keep=&empty=',
			),
		);

		$failures = array();
		foreach ( $removal_cases as $removal_case ) {
			$result   = \_remove_qs_args_if_not_in_url( $query_string, $removal_case['args'], $removal_case['url'] );
			$actual   = self::parse_query_string( $result );
			$expected = self::expected_query_after_qs_removal( $base_query, $removal_case['args'], $removal_case['url'] );

			if ( $expected !== $actual ) {
				$failures[] = array(
					'label'    => $removal_case['label'],
					'url'      => $removal_case['url'],
					'args'     => $removal_case['args'],
					'result'   => $result,
					'expected' => $expected,
					'actual'   => $actual,
				);
			}
		}

		$fragment_query = http_build_query(
			array(
				'keep' => $case['keep'],
				'name' => $base_query['name'],
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$fragment_cases = array(
			array(
				'label'    => 'absolute-url-keeps-scheme-port-path-query',
				'url'      => 'https://example.test:8443/site-base/path?' . $fragment_query . '#frag-' . rawurlencode( $case['token'] ),
				'expected' => 'https://example.test:8443/site-base/path?' . $fragment_query,
			),
			array(
				'label'    => 'protocol-relative-keeps-host-path-query',
				'url'      => '//example.test/site-base/path?' . $fragment_query . '#frag',
				'expected' => '//example.test/site-base/path?' . $fragment_query,
			),
			array(
				'label'    => 'host-only-drops-fragment',
				'url'      => 'http://example.test#frag-' . rawurlencode( $case['token'] ),
				'expected' => 'http://example.test',
			),
			array(
				'label'    => 'relative-url-without-host-is-unchanged',
				'url'      => 'relative/path?' . $fragment_query . '#frag-' . rawurlencode( $case['token'] ),
				'expected' => 'relative/path?' . $fragment_query . '#frag-' . rawurlencode( $case['token'] ),
			),
		);

		foreach ( $fragment_cases as $fragment_case ) {
			$stripped = \strip_fragment_from_url( $fragment_case['url'] );
			if ( $fragment_case['expected'] !== $stripped ) {
				$failures[] = array(
					'label'    => $fragment_case['label'],
					'url'      => $fragment_case['url'],
					'expected' => $fragment_case['expected'],
					'actual'   => $stripped,
				);
			}
		}

		return $ctx->result(
			'canonical-routing.helper-query-fragment-matrix',
			array() === $failures,
			array(
				'queryCases'    => count( $removal_cases ),
				'fragmentCases' => count( $fragment_cases ),
				'failures'      => array_slice( $failures, 0, 5 ),
			)
		);
	}

	private static function install_option_filters(): void {
		$options = array(
			'pre_option_home'                     => array( self::class, 'filter_home' ),
			'pre_option_siteurl'                  => array( self::class, 'filter_siteurl' ),
			'pre_option_permalink_structure'      => array( self::class, 'filter_permalink_structure' ),
			'pre_option_blog_charset'             => array( self::class, 'filter_blog_charset' ),
			'pre_option_html_type'                => array( self::class, 'filter_html_type' ),
			'pre_option_default_feed'             => array( self::class, 'filter_default_feed' ),
			'pre_option_page_comments'            => array( self::class, 'filter_false' ),
			'pre_option_wp_attachment_pages_enabled' => array( self::class, 'filter_zero' ),
		);

		foreach ( $options as $hook => $callback ) {
			\add_filter( $hook, $callback, 0 );
		}
	}

	private static function remove_option_filters(): void {
		$options = array(
			'pre_option_home'                     => array( self::class, 'filter_home' ),
			'pre_option_siteurl'                  => array( self::class, 'filter_siteurl' ),
			'pre_option_permalink_structure'      => array( self::class, 'filter_permalink_structure' ),
			'pre_option_blog_charset'             => array( self::class, 'filter_blog_charset' ),
			'pre_option_html_type'                => array( self::class, 'filter_html_type' ),
			'pre_option_default_feed'             => array( self::class, 'filter_default_feed' ),
			'pre_option_page_comments'            => array( self::class, 'filter_false' ),
			'pre_option_wp_attachment_pages_enabled' => array( self::class, 'filter_zero' ),
		);

		foreach ( $options as $hook => $callback ) {
			\remove_filter( $hook, $callback, 0 );
		}
	}

	private static function prepare_request( array $query_vars, array $flags = array(), string $method = 'GET' ): void {
		global $wp, $wp_query, $wp_rewrite, $is_IIS;

		$wp                 = new \WP();
		$wp->query_vars     = $query_vars;
		$wp_rewrite         = new \WP_Rewrite();
		$wp_rewrite->index  = 'index.php';
		$wp_query           = new \WP_Query();
		$wp_query->query    = $query_vars;
		$wp_query->query_vars = $query_vars;
		$wp_query->post_count = $flags['post_count'] ?? 0;

		foreach ( $flags as $flag => $value ) {
			$wp_query->{$flag} = $value;
		}

		$is_IIS = false;

		$_SERVER['REQUEST_METHOD']  = $method;
		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['REQUEST_URI']     = '/site-base/';
		$_SERVER['PHP_SELF']        = '/site-base/index.php';
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

		$_GET  = $query_vars;
		$_POST = array();
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wp',
				'wp_query',
				'wp_the_query',
				'wp_rewrite',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'is_IIS',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		$server = array();
		foreach ( array( 'REQUEST_METHOD', 'HTTP_HOST', 'REQUEST_URI', 'PHP_SELF', 'SERVER_PROTOCOL' ) as $name ) {
			$server[ $name ] = array(
				'exists' => array_key_exists( $name, $_SERVER ),
				'value'  => $_SERVER[ $name ] ?? null,
			);
		}

		return array(
			'globals' => $globals,
			'server'  => $server,
			'get'     => self::clone_value( $_GET ),
			'post'    => self::clone_value( $_POST ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::remove_option_filters();

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
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

		$_GET  = self::clone_value( $snapshot['get'] );
		$_POST = self::clone_value( $snapshot['post'] );
	}

	private static function state_matches( array $snapshot ): bool {
		$current = self::snapshot_state();
		return $snapshot == $current
			&& false === \has_filter( 'pre_option_home', array( self::class, 'filter_home' ) )
			&& false === \has_filter( 'redirect_canonical' );
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = strtolower( preg_replace( '/[^a-z0-9_]+/', '_', $ctx->identifier( 4, 10 ) ) );
		$keep  = $ctx->choice(
			array(
				'plain-' . $token,
				'a b',
				'a+b=c',
				'unicode-' . html_entity_decode( '&#233;&#9731;', ENT_QUOTES, 'UTF-8' ),
				$ctx->text( 1, 24 ),
			)
		);

		$dirty_segments = array(
			'library//' . rawurlencode( $token ) . '/index.php/%20.',
			'archive//' . rawurlencode( $token ) . '/%7D',
			'items/' . rawurlencode( $token ) . '//%E2%80%9D',
		);

		return array(
			'token'     => $token,
			'keep'      => (string) $keep,
			'dirtyPath' => $ctx->choice( $dirty_segments ),
			'feedBase'  => 'feed-base-' . $token,
			'search'    => 'search ' . $token,
		);
	}

	private static function lowercase_octets( string $url ): string {
		return preg_replace_callback(
			'|%[a-fA-F0-9][a-fA-F0-9]|',
			static function ( array $matches ): string {
				return strtolower( $matches[0] );
			},
			$url
		);
	}

	private static function parse_query_from_parts( array $parts ): array {
		$query = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		return $query;
	}

	private static function parse_query_string( string $query_string ): array {
		$query = array();
		parse_str( $query_string, $query );

		return $query;
	}

	private static function expected_query_after_qs_removal( array $base_query, array $args_to_check, string $url ): array {
		$parts = parse_url( $url );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $target_query );

			foreach ( $args_to_check as $arg ) {
				if ( ! isset( $target_query[ $arg ] ) ) {
					unset( $base_query[ $arg ] );
				}
			}

			return $base_query;
		}

		foreach ( $args_to_check as $arg ) {
			unset( $base_query[ $arg ] );
		}

		return $base_query;
	}

	private static function path_has_trailing_cleanup_junk( string $path ): bool {
		return 1 === preg_match( '/(?:%20|[ !"\',.;{}()]|%21|%22|%27|%28|%29|%2C|%2E|%3B|%7B|%7D|%C2%A0|%E2%80%9C|%E2%80%9D)$/i', $path );
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

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
