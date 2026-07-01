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
			self::initialize_core_content_types();
			$case = self::case_for_context( $ctx );

			$rows[] = self::check_path_query_and_host_cleanup( $ctx->fork( 'cleanup' ), $case );
			$rows[] = self::check_cleanup_variant_matrix( $ctx->fork( 'cleanup-matrix' ), $case );
			$rows[] = self::check_early_bailouts( $ctx->fork( 'bailouts' ), $case );
			$rows[] = self::check_invalid_date_redirect( $ctx->fork( 'date' ), $case );
			$rows[] = self::check_404_guess_and_db_redirects( $ctx->fork( '404-guess' ), $case );
			$rows[] = self::check_attachment_canonical_redirects( $ctx->fork( 'attachments' ), $case );
			$rows[] = self::check_old_slug_and_date_redirects( $ctx->fork( 'old-slug-date' ), $case );
			$rows[] = self::check_feed_and_paged_redirect( $ctx->fork( 'feed' ), $case );
			$rows[] = self::check_redirect_filter_contract( $ctx->fork( 'filter' ), $case );
			$rows[] = self::check_safe_redirect_replacement_is_returned( $ctx->fork( 'safe-filter' ), $case );
			$rows[] = self::check_unsafe_redirect_replacement_is_cancelled( $ctx->fork( 'unsafe-filter' ), $case );
			$rows[] = self::check_trailing_slash_modes( $ctx->fork( 'slashes' ), $case );
			$rows[] = self::check_canonical_url_output_helpers( $ctx->fork( 'canonical-output' ), $case );
			$rows[] = self::check_canonical_helpers( $ctx->fork( 'helpers' ), $case );
			$rows[] = self::check_canonical_helper_matrix( $ctx->fork( 'helper-matrix' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'canonical-routing.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::reset_db_content();
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

	public static function filter_default_comments_page(): string {
		return 'oldest';
	}

	public static function filter_false() {
		return false;
	}

	public static function filter_zero() {
		return 0;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'WP', 'WP_Post', 'WP_Query', 'WP_Rewrite' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'_remove_qs_args_if_not_in_url',
				'add_filter',
				'add_query_arg',
				'create_initial_post_types',
				'get_day_link',
				'get_attachment_link',
				'get_comments_pagenum_link',
				'get_month_link',
				'get_permalink',
				'get_post',
				'get_post_comments_feed_link',
				'get_query_var',
				'get_year_link',
				'home_url',
				'is_404',
				'is_embed',
				'is_post_type_hierarchical',
				'is_feed',
				'_find_post_by_old_date',
				'_find_post_by_old_slug',
				'redirect_canonical',
				'redirect_guess_404_permalink',
				'remove_query_arg',
				'remove_filter',
				'sanitize_title',
				'sanitize_title_with_dashes',
				'strip_fragment_from_url',
				'trailingslashit',
				'user_trailingslashit',
				'wp_get_attachment_url',
				'wp_cache_delete',
				'wp_cache_get',
				'wp_cache_get_last_changed',
				'wp_cache_get_salted',
				'wp_cache_set',
				'wp_cache_set_last_changed',
				'wp_cache_set_salted',
				'wp_get_canonical_url',
				'wp_parse_url',
				'wp_old_slug_redirect',
				'rel_canonical',
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

	private static function check_404_guess_and_db_redirects( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! self::wpdb_stub_available() ) {
			return $ctx->skip(
				'canonical-routing.404-guess.db-backed-permalink-resolution',
				'The wpdb content stub is unavailable for DB-backed canonical redirect guessing.'
			);
		}

		self::reset_db_content();

		$failures = array();
		$prefix   = sanitize_title_with_dashes( 'canonical guess ' . $case['token'] . ' ' . $ctx->int( 100, 999 ) );
		$post_id  = 63300 + $ctx->int( 0, 300 );
		$page     = $ctx->int( 2, 5 );

		$target = self::seed_post_row(
			array(
				'ID'            => $post_id,
				'post_name'     => $prefix . '-target',
				'post_title'    => 'Canonical Guess ' . $case['token'],
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_date'     => '2026-06-15 10:20:30',
				'post_date_gmt' => '2026-06-15 10:20:30',
			)
		);
		self::seed_post_row(
			array(
				'ID'            => $post_id + 500,
				'post_name'     => $prefix . '-draft',
				'post_title'    => 'Canonical Draft ' . $case['token'],
				'post_status'   => 'draft',
				'post_type'     => 'post',
				'post_date'     => '2026-06-14 10:20:30',
				'post_date_gmt' => '2026-06-14 10:20:30',
			)
		);

		$expected_permalink = \get_permalink( $target );

		self::prepare_request( array( 'name' => $prefix ), array( 'is_404' => true ) );
		$loose_guess = \redirect_guess_404_permalink();

		$strict_filter = static fn() => true;
		\add_filter( 'strict_redirect_guess_404_permalink', $strict_filter, 10, 0 );
		try {
			self::prepare_request( array( 'name' => $prefix ), array( 'is_404' => true ) );
			$strict_prefix_guess = \redirect_guess_404_permalink();

			self::prepare_request( array( 'name' => $target->post_name ), array( 'is_404' => true ) );
			$strict_exact_guess = \redirect_guess_404_permalink();
		} finally {
			\remove_filter( 'strict_redirect_guess_404_permalink', $strict_filter, 10 );
		}

		self::prepare_request(
			array(
				'name' => $prefix,
				'feed' => 'rss2',
			),
			array( 'is_404' => true )
		);
		$feed_guess     = \redirect_guess_404_permalink();
		$expected_feed  = \get_post_comments_feed_link( $target->ID, 'rss2' );

		self::prepare_request(
			array(
				'name' => $prefix,
				'page' => $page,
			),
			array( 'is_404' => true )
		);
		$paged_guess    = \redirect_guess_404_permalink();
		$expected_paged = trailingslashit( $expected_permalink ) . user_trailingslashit( (string) $page, 'single_paged' );

		self::prepare_request(
			array(
				'name'      => $prefix,
				'post_type' => 'cfz_hidden',
			),
			array( 'is_404' => true )
		);
		$hidden_type_guess = \redirect_guess_404_permalink();

		$pre_url    = self::HOME_URL . '/pre-guessed/' . rawurlencode( $case['token'] ) . '/';
		$pre_filter = static fn() => $pre_url;
		\add_filter( 'pre_redirect_guess_404_permalink', $pre_filter, 10, 0 );
		try {
			self::prepare_request( array(), array( 'is_404' => true ) );
			$pre_guess = \redirect_guess_404_permalink();
		} finally {
			\remove_filter( 'pre_redirect_guess_404_permalink', $pre_filter, 10 );
		}

		$disable_filter = static fn() => false;
		\add_filter( 'do_redirect_guess_404_permalink', $disable_filter, 10, 0 );
		try {
			self::prepare_request( array( 'name' => $prefix ), array( 'is_404' => true ) );
			$disabled_guess = \redirect_guess_404_permalink();
		} finally {
			\remove_filter( 'do_redirect_guess_404_permalink', $disable_filter, 10 );
		}

		$requested = self::HOME_URL . '/missing/' . rawurlencode( $prefix ) . '/?name=' . rawurlencode( $prefix ) . '&keep=' . rawurlencode( $case['keep'] );
		self::prepare_request(
			array(
				'name' => $prefix,
				'keep' => $case['keep'],
			),
			array( 'is_404' => true )
		);
		$canonical_redirect = \redirect_canonical( $requested, false );
		$canonical_parts    = is_string( $canonical_redirect ) ? \wp_parse_url( $canonical_redirect ) : array();
		$canonical_query    = self::parse_query_from_parts( $canonical_parts );
		$expected_parts     = is_string( $expected_permalink ) ? \wp_parse_url( $expected_permalink ) : array();

		self::collect_failure(
			$failures,
			is_string( $expected_permalink )
				&& $expected_permalink === $loose_guess
				&& false === $strict_prefix_guess
				&& $expected_permalink === $strict_exact_guess
				&& $expected_feed === $feed_guess
				&& $expected_paged === $paged_guess
				&& false === $hidden_type_guess
				&& $pre_url === $pre_guess
				&& false === $disabled_guess
				&& false === \has_filter( 'strict_redirect_guess_404_permalink', $strict_filter )
				&& false === \has_filter( 'pre_redirect_guess_404_permalink', $pre_filter )
				&& false === \has_filter( 'do_redirect_guess_404_permalink', $disable_filter ),
			'redirect_guess_404_permalink resolves only public viewable DB-backed post guesses and honors filters',
			array(
				'expected'     => $expected_permalink,
				'loose'        => $loose_guess,
				'strictPrefix' => $strict_prefix_guess,
				'strictExact'  => $strict_exact_guess,
				'feed'         => $feed_guess,
				'paged'        => $paged_guess,
				'hiddenType'   => $hidden_type_guess,
				'pre'          => $pre_guess,
				'disabled'     => $disabled_guess,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $canonical_redirect )
				&& ( $expected_parts['path'] ?? null ) === ( $canonical_parts['path'] ?? null )
				&& $case['keep'] === ( $canonical_query['keep'] ?? null )
				&& ! isset( $canonical_query['name'], $canonical_query['post_type'], $canonical_query['feed'], $canonical_query['page'] ),
			'redirect_canonical uses the guessed permalink for 404 requests and removes obsolete query vars',
			array(
				'requested' => $requested,
				'redirect'  => $canonical_redirect,
				'expected'  => $expected_permalink,
				'parts'     => $canonical_parts,
				'query'     => $canonical_query,
			)
		);

		self::reset_db_content();

		return $ctx->result(
			'canonical-routing.404-guess.db-backed-permalink-resolution',
			array() === $failures,
			array(
				'postId'   => $target->ID,
				'prefix'   => $prefix,
				'failures' => array_slice( $failures, 0, 4 ),
			)
		);
	}

	private static function check_attachment_canonical_redirects( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! self::wpdb_stub_available() ) {
			return $ctx->skip(
				'canonical-routing.attachment-pages.permalink-and-file-redirects',
				'The wpdb content stub is unavailable for DB-backed attachment canonical redirects.'
			);
		}

		self::reset_db_content();

		$failures    = array();
		$base_id     = 64100 + ( $ctx->iteration() * 20 ) + $ctx->int( 0, 10 );
		$parent_slug = sanitize_title_with_dashes( 'canonical attachment parent ' . $case['token'] . ' ' . $ctx->int( 100, 999 ) );
		$file_slug   = sanitize_title_with_dashes( 'canonical attachment file ' . $case['token'] . ' ' . $ctx->int( 100, 999 ) );
		$relative    = '2026/07/' . $file_slug . '.jpg';
		$upload_url  = self::HOME_URL . '/wp-content/uploads/' . $relative;
		$attachment_pages_enabled = static fn() => 1;

		try {
			$parent = self::seed_post_row(
				array(
					'ID'            => $base_id,
					'post_name'     => $parent_slug,
					'post_title'    => 'Canonical Attachment Parent ' . $case['token'],
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_date'     => '2026-07-01 10:20:30',
					'post_date_gmt' => '2026-07-01 10:20:30',
				)
			);
			$attachment = self::seed_post_row(
				array(
					'ID'             => $base_id + 1,
					'post_name'      => $file_slug,
					'post_title'     => 'Canonical Attachment ' . $case['token'],
					'post_status'    => 'inherit',
					'post_type'      => 'attachment',
					'post_parent'    => $parent->ID,
					'post_mime_type' => 'image/jpeg',
					'guid'           => $upload_url,
				)
			);

			$draft_parent = self::seed_post_row(
				array(
					'ID'            => $base_id + 2,
					'post_name'     => $parent_slug . '-draft',
					'post_title'    => 'Canonical Draft Parent ' . $case['token'],
					'post_status'   => 'draft',
					'post_type'     => 'post',
					'post_date'     => '2026-07-02 10:20:30',
					'post_date_gmt' => '2026-07-02 10:20:30',
				)
			);
			$draft_attachment = self::seed_post_row(
				array(
					'ID'             => $base_id + 3,
					'post_name'      => $file_slug . '-draft',
					'post_title'     => 'Canonical Draft Attachment ' . $case['token'],
					'post_status'    => 'inherit',
					'post_type'      => 'attachment',
					'post_parent'    => $draft_parent->ID,
					'post_mime_type' => 'image/jpeg',
					'guid'           => self::HOME_URL . '/wp-content/uploads/' . dirname( $relative ) . '/' . $file_slug . '-draft.jpg',
				)
			);

			\add_filter( 'pre_option_wp_attachment_pages_enabled', $attachment_pages_enabled, 100 );
			try {
				self::prepare_attachment_request(
					$attachment,
					array(
						'attachment_id' => (string) $attachment->ID,
						'keep'          => $case['keep'],
					)
				);
				$query_requested = self::HOME_URL . '/?attachment_id=' . $attachment->ID . '&keep=' . rawurlencode( $case['keep'] );
				$query_redirect  = \redirect_canonical( $query_requested, false );
			} finally {
				\remove_filter( 'pre_option_wp_attachment_pages_enabled', $attachment_pages_enabled, 100 );
			}

			$attachment_link    = \get_attachment_link( $attachment->ID );
			$query_parts        = is_string( $query_redirect ) ? \wp_parse_url( $query_redirect ) : array();
			$query_redirect_qs  = self::parse_query_from_parts( $query_parts );
			$link_parts         = is_string( $attachment_link ) ? \wp_parse_url( $attachment_link ) : array();
			$expected_file_url  = \wp_get_attachment_url( $attachment->ID );

			self::prepare_attachment_request( $attachment );
			$page_requested = is_string( $attachment_link ) ? $attachment_link : self::HOME_URL . '/?attachment_id=' . $attachment->ID;
			$page_redirect  = \redirect_canonical( $page_requested, false );

			self::prepare_attachment_request( $draft_attachment );
			$draft_requested = \get_attachment_link( $draft_attachment->ID );
			$draft_redirect  = \redirect_canonical( is_string( $draft_requested ) ? $draft_requested : self::HOME_URL . '/?attachment_id=' . $draft_attachment->ID, false );

			self::collect_failure(
				$failures,
				is_string( $attachment_link )
					&& is_string( $query_redirect )
					&& ( $link_parts['path'] ?? null ) === ( $query_parts['path'] ?? null )
					&& $case['keep'] === ( $query_redirect_qs['keep'] ?? null )
					&& ! isset( $query_redirect_qs['attachment_id'] )
					&& false === \has_filter( 'pre_option_wp_attachment_pages_enabled', $attachment_pages_enabled ),
				'attachment_id query canonicalizes to the attachment permalink when attachment pages are enabled',
				array(
					'requested'      => $query_requested,
					'redirect'       => $query_redirect,
					'attachmentLink' => $attachment_link,
					'redirectQuery'  => $query_redirect_qs,
				)
			);

			self::collect_failure(
				$failures,
				is_string( $expected_file_url )
					&& $expected_file_url === $page_redirect,
				'disabled attachment pages canonicalize public-parent attachment pages to the raw attachment URL',
				array(
					'requested' => $page_requested,
					'redirect'  => $page_redirect,
					'expected'  => $expected_file_url,
				)
			);

			self::collect_failure(
				$failures,
				null === $draft_redirect,
				'disabled attachment pages do not expose attachments inheriting a non-public parent',
				array(
					'requested' => $draft_requested,
					'redirect'  => $draft_redirect,
				)
			);
		} finally {
			\remove_filter( 'pre_option_wp_attachment_pages_enabled', $attachment_pages_enabled, 100 );
			self::reset_db_content();
		}

		return $ctx->result(
			'canonical-routing.attachment-pages.permalink-and-file-redirects',
			array() === $failures,
			array(
				'baseId'   => $base_id,
				'file'     => $relative,
				'failures' => array_slice( $failures, 0, 4 ),
			)
		);
	}

	private static function check_old_slug_and_date_redirects( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! self::wpdb_stub_available() ) {
			return $ctx->skip(
				'canonical-routing.old-slug-date.db-backed-redirects',
				'The wpdb content stub is unavailable for DB-backed old slug/date redirects.'
			);
		}

		self::reset_db_content();

		$base_id       = 65000 + ( $ctx->iteration() * 40 ) + $ctx->int( 0, 10 );
		$month         = $ctx->int( 1, 12 );
		$day           = $ctx->int( 2, 26 );
		$current_date  = sprintf( '2026-%02d-%02d 10:20:30', $month, $day );
		$old_date      = sprintf(
			'2016-%02d-%02d 03:04:05',
			$ctx->int( 1, 12 ),
			$ctx->int( 2, 26 )
		);
		$fallback_date = sprintf(
			'2012-%02d-%02d 06:07:08',
			$ctx->int( 1, 12 ),
			$ctx->int( 2, 26 )
		);
		$old_slug      = self::old_redirect_slug( $ctx, $case, 'old slug' );
		$current_slug  = self::old_redirect_slug( $ctx, $case, 'current slug' );
		$date_slug     = self::old_redirect_slug( $ctx, $case, 'date slug' );
		$fallback_old  = self::old_redirect_slug( $ctx, $case, 'fallback old' );
		$fallback_new  = self::old_redirect_slug( $ctx, $case, 'fallback new' );
		$paged         = $ctx->int( 2, 5 );
		$failures      = array();
		$redirect_urls = array();
		$filtered_ids  = array();
		$observed      = array();

		$id_filter = static function ( $post_id ) use ( &$filtered_ids ) {
			$filtered_ids[] = (int) $post_id;
			return $post_id;
		};
		$url_filter = static function ( $url ) use ( &$redirect_urls ) {
			$redirect_urls[] = $url;
			return false;
		};

		try {
			$wrong_date_decoy = self::seed_post_row(
				array(
					'ID'            => $base_id,
					'post_name'     => $current_slug . '-decoy-date',
					'post_title'    => 'Old Slug Wrong Date ' . $case['token'],
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_date'     => '2025-01-02 10:20:30',
					'post_date_gmt' => '2025-01-02 10:20:30',
				)
			);
			self::seed_post_meta_row( $wrong_date_decoy->ID, '_wp_old_slug', $old_slug );

			$wrong_type_decoy = self::seed_post_row(
				array(
					'ID'            => $base_id + 1,
					'post_name'     => $current_slug . '-decoy-type',
					'post_title'    => 'Old Slug Wrong Type ' . $case['token'],
					'post_status'   => 'publish',
					'post_type'     => 'cfz_private',
					'post_date'     => $current_date,
					'post_date_gmt' => $current_date,
				)
			);
			self::seed_post_meta_row( $wrong_type_decoy->ID, '_wp_old_slug', $old_slug );

			$slug_target = self::seed_post_row(
				array(
					'ID'            => $base_id + 2,
					'post_name'     => $current_slug,
					'post_title'    => 'Old Slug Target ' . $case['token'],
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_date'     => $current_date,
					'post_date_gmt' => $current_date,
				)
			);
			self::seed_post_meta_row( $slug_target->ID, '_wp_old_slug', $old_slug );

			$date_target = self::seed_post_row(
				array(
					'ID'            => $base_id + 3,
					'post_name'     => $date_slug,
					'post_title'    => 'Old Date Target ' . $case['token'],
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_date'     => '2026-07-08 10:20:30',
					'post_date_gmt' => '2026-07-08 10:20:30',
				)
			);
			self::seed_post_meta_row( $date_target->ID, '_wp_old_date', $old_date );

			$fallback_target = self::seed_post_row(
				array(
					'ID'            => $base_id + 4,
					'post_name'     => $fallback_new,
					'post_title'    => 'Old Date Fallback Target ' . $case['token'],
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_date'     => '2026-08-09 10:20:30',
					'post_date_gmt' => '2026-08-09 10:20:30',
				)
			);
			self::seed_post_meta_row( $fallback_target->ID, '_wp_old_slug', $fallback_old );
			self::seed_post_meta_row( $fallback_target->ID, '_wp_old_date', $fallback_date );

			self::flush_runtime_cache();

			$current_parts = self::date_query_vars( $current_date );
			self::prepare_request(
				array_merge(
					array(
						'name' => $old_slug,
					),
					$current_parts
				),
				array( 'is_404' => true )
			);
			$queries_before_slug       = $GLOBALS['wpdb']->num_queries;
			$old_slug_id_first         = \_find_post_by_old_slug( 'post' );
			$queries_after_slug        = $GLOBALS['wpdb']->num_queries;
			$old_slug_id_second        = \_find_post_by_old_slug( 'post' );
			$queries_after_slug_cached = $GLOBALS['wpdb']->num_queries;
			\wp_cache_set_last_changed( 'posts' );
			$old_slug_id_after_invalidation  = \_find_post_by_old_slug( 'post' );
			$queries_after_slug_invalidation = $GLOBALS['wpdb']->num_queries;

			self::collect_failure(
				$failures,
				$slug_target->ID === $old_slug_id_first
					&& $slug_target->ID === $old_slug_id_second
					&& $slug_target->ID === $old_slug_id_after_invalidation
					&& $queries_after_slug > $queries_before_slug
					&& $queries_after_slug_cached === $queries_after_slug
					&& $queries_after_slug_invalidation > $queries_after_slug_cached,
				'_find_post_by_old_slug matches generated post type/date predicates and caches by posts last_changed salt',
				array(
					'expected'               => $slug_target->ID,
					'first'                  => $old_slug_id_first,
					'second'                 => $old_slug_id_second,
					'afterInvalidation'      => $old_slug_id_after_invalidation,
					'queriesBefore'          => $queries_before_slug,
					'queriesAfterFirst'      => $queries_after_slug,
					'queriesAfterCached'     => $queries_after_slug_cached,
					'queriesAfterInvalidated' => $queries_after_slug_invalidation,
				)
			);

			\add_filter( 'old_slug_redirect_post_id', $id_filter, 10, 1 );
			\add_filter( 'old_slug_redirect_url', $url_filter, 10, 1 );

			self::prepare_request(
				array_merge(
					array(
						'name'  => $old_slug,
						'paged' => $paged,
					),
					$current_parts
				),
				array( 'is_404' => true )
			);
			$slug_url_before = count( $redirect_urls );
			$id_before       = count( $filtered_ids );
			\wp_old_slug_redirect();
			$expected_slug_url = \user_trailingslashit(
				\trailingslashit( \get_permalink( $slug_target->ID ) ) . 'page/' . $paged
			);

			self::collect_failure(
				$failures,
				$slug_url_before + 1 === count( $redirect_urls )
					&& $id_before + 1 === count( $filtered_ids )
					&& $slug_target->ID === $filtered_ids[ $id_before ]
					&& $expected_slug_url === $redirect_urls[ $slug_url_before ],
				'wp_old_slug_redirect builds paged old-slug URLs and can be cancelled before wp_redirect exits',
				array(
					'expectedUrl' => $expected_slug_url,
					'captured'    => $redirect_urls[ $slug_url_before ] ?? null,
					'filteredId'  => $filtered_ids[ $id_before ] ?? null,
				)
			);

			self::flush_runtime_cache();
			$date_parts = self::date_query_vars( $old_date );
			self::prepare_request(
				array_merge(
					array(
						'name' => $date_slug,
					),
					$date_parts
				),
				array( 'is_404' => true )
			);
			$queries_before_no_date = $GLOBALS['wpdb']->num_queries;
			self::prepare_request( array( 'name' => $date_slug ), array( 'is_404' => true ) );
			$old_date_without_parts = \_find_post_by_old_date( 'post' );
			$queries_after_no_date  = $GLOBALS['wpdb']->num_queries;

			self::prepare_request(
				array_merge(
					array(
						'name' => $date_slug,
					),
					$date_parts
				),
				array(
					'is_404'   => true,
					'is_embed' => true,
				)
			);
			$old_date_slug_probe = \_find_post_by_old_slug( 'post' );
			$queries_before_date       = $GLOBALS['wpdb']->num_queries;
			$old_date_id_first         = \_find_post_by_old_date( 'post' );
			$queries_after_date        = $GLOBALS['wpdb']->num_queries;
			$old_date_id_second        = \_find_post_by_old_date( 'post' );
			$queries_after_date_cached = $GLOBALS['wpdb']->num_queries;

			$date_url_before = count( $redirect_urls );
			$date_id_before  = count( $filtered_ids );
			\wp_old_slug_redirect();
			$expected_date_url = \user_trailingslashit(
				\trailingslashit( \get_permalink( $date_target->ID ) ) . 'embed'
			);

			self::collect_failure(
				$failures,
				0 === $old_date_without_parts
					&& $queries_after_no_date === $queries_before_no_date
					&& 0 === $old_date_slug_probe
					&& $date_target->ID === $old_date_id_first
					&& $date_target->ID === $old_date_id_second
					&& $queries_after_date > $queries_before_date
					&& $queries_after_date_cached === $queries_after_date
					&& $date_url_before + 1 === count( $redirect_urls )
					&& $date_id_before + 1 === count( $filtered_ids )
					&& $date_target->ID === $filtered_ids[ $date_id_before ]
					&& $expected_date_url === $redirect_urls[ $date_url_before ],
				'old-date direct lookup requires date vars, misses old-slug lookup, caches hits, and builds embed URLs',
				array(
					'expected'            => $date_target->ID,
					'withoutDateParts'    => $old_date_without_parts,
					'oldSlugProbe'        => $old_date_slug_probe,
					'first'               => $old_date_id_first,
					'second'              => $old_date_id_second,
					'queriesBefore'       => $queries_before_date,
					'queriesAfterFirst'   => $queries_after_date,
					'queriesAfterCached'  => $queries_after_date_cached,
					'expectedUrl'         => $expected_date_url,
					'captured'            => $redirect_urls[ $date_url_before ] ?? null,
				)
			);

			self::flush_runtime_cache();
			$fallback_parts = self::date_query_vars( $fallback_date );
			self::prepare_request(
				array_merge(
					array(
						'name' => $fallback_old,
					),
					$fallback_parts
				),
				array( 'is_404' => true )
			);
			$fallback_slug_probe              = \_find_post_by_old_slug( 'post' );
			$queries_before_fallback          = $GLOBALS['wpdb']->num_queries;
			$fallback_id_first                = \_find_post_by_old_date( 'post' );
			$queries_after_fallback           = $GLOBALS['wpdb']->num_queries;
			$fallback_id_second               = \_find_post_by_old_date( 'post' );
			$queries_after_fallback_cached = $GLOBALS['wpdb']->num_queries;

			$fallback_url_before = count( $redirect_urls );
			$fallback_id_before  = count( $filtered_ids );
			\wp_old_slug_redirect();
			$expected_fallback_url = \get_permalink( $fallback_target->ID );

			self::collect_failure(
				$failures,
				0 === $fallback_slug_probe
					&& $fallback_target->ID === $fallback_id_first
					&& $fallback_target->ID === $fallback_id_second
					&& $queries_after_fallback > $queries_before_fallback
					&& $queries_after_fallback_cached === $queries_after_fallback
					&& $fallback_url_before + 1 === count( $redirect_urls )
					&& $fallback_id_before + 1 === count( $filtered_ids )
					&& $fallback_target->ID === $filtered_ids[ $fallback_id_before ]
					&& $expected_fallback_url === $redirect_urls[ $fallback_url_before ],
				'old-date fallback resolves posts matching both generated old slug and old date meta',
				array(
					'expected'           => $fallback_target->ID,
					'oldSlugProbe'       => $fallback_slug_probe,
					'first'              => $fallback_id_first,
					'second'             => $fallback_id_second,
					'queriesBefore'      => $queries_before_fallback,
					'queriesAfterFirst'  => $queries_after_fallback,
					'queriesAfterCached' => $queries_after_fallback_cached,
					'expectedUrl'        => $expected_fallback_url,
					'captured'           => $redirect_urls[ $fallback_url_before ] ?? null,
				)
			);

			$guard_url_before = count( $redirect_urls );
			$guard_id_before  = count( $filtered_ids );

			self::prepare_request(
				array(
					'name'      => $old_slug,
					'post_type' => array( 'post', 'book' ),
				),
				array( 'is_404' => true )
			);
			\wp_old_slug_redirect();

			self::prepare_request(
				array(
					'name'      => $old_slug,
					'post_type' => 'page',
				),
				array( 'is_404' => true )
			);
			\wp_old_slug_redirect();

			self::prepare_request(
				array(
					'name' => $old_slug,
				),
				array( 'is_single' => true )
			);
			\wp_old_slug_redirect();

			self::prepare_request( array(), array( 'is_404' => true ) );
			\wp_old_slug_redirect();

			self::collect_failure(
				$failures,
				$guard_url_before === count( $redirect_urls )
					&& $guard_id_before === count( $filtered_ids ),
				'old slug redirect fail-closed gates avoid redirects for multi-type, hierarchical, non-404, and empty-name requests',
				array(
					'urlCountBefore' => $guard_url_before,
					'urlCountAfter'  => count( $redirect_urls ),
					'idCountBefore'  => $guard_id_before,
					'idCountAfter'   => count( $filtered_ids ),
				)
			);

			$observed = array(
				'oldSlug' => array(
					'postId' => $slug_target->ID,
					'slug'   => $old_slug,
					'url'    => $expected_slug_url,
				),
				'oldDate' => array(
					'postId' => $date_target->ID,
					'date'   => $old_date,
					'url'    => $expected_date_url,
				),
				'fallback' => array(
					'postId' => $fallback_target->ID,
					'slug'   => $fallback_old,
					'date'   => $fallback_date,
					'url'    => $expected_fallback_url,
				),
			);
		} finally {
			\remove_filter( 'old_slug_redirect_post_id', $id_filter, 10 );
			\remove_filter( 'old_slug_redirect_url', $url_filter, 10 );
			self::reset_db_content();
		}

		$filters_removed = false === \has_filter( 'old_slug_redirect_post_id', $id_filter )
			&& false === \has_filter( 'old_slug_redirect_url', $url_filter );

		self::collect_failure(
			$failures,
			$filters_removed,
			'old slug redirect filters are removed after generated redirect checks',
			array(
				'postIdFilter' => \has_filter( 'old_slug_redirect_post_id', $id_filter ),
				'urlFilter'    => \has_filter( 'old_slug_redirect_url', $url_filter ),
			)
		);

		return $ctx->result(
			'canonical-routing.old-slug-date.db-backed-redirects',
			array() === $failures,
			array(
				'observed' => $observed,
				'failures' => array_slice( $failures, 0, 8 ),
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

	private static function check_canonical_url_output_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures   = array();
		$post       = self::make_post( 510000 + $ctx->iteration(), $case['token'], 'publish' );
		$other_post = self::make_post( 520000 + $ctx->iteration(), $case['token'] . '-other', 'publish' );
		$draft_post = self::make_post( 530000 + $ctx->iteration(), $case['token'] . '-draft', 'draft' );
		$cached_ids      = array( $post->ID, $other_post->ID, $draft_post->ID );
		$cached_snapshot = self::snapshot_cache_slots( $cached_ids, 'posts' );
		$page            = $ctx->int( 2, 9 );
		$cpage           = $ctx->int( 2, 9 );

		foreach ( array( $post, $other_post, $draft_post ) as $cached_post ) {
			\wp_cache_set( $cached_post->ID, $cached_post, 'posts' );
		}

		$plain_permalink_filter = static fn() => '';
		$canonical_filter_seen  = array();
		$canonical_marker       = 'canonical-' . $ctx->identifier( 4, 8 );
		$canonical_filter      = static function ( string $canonical_url, \WP_Post $filtered_post ) use ( &$canonical_filter_seen, $canonical_marker ): string {
			$canonical_filter_seen[] = array(
				'url'    => $canonical_url,
				'postId' => $filtered_post->ID,
				'status' => $filtered_post->post_status,
			);

			return \add_query_arg( 'cfz-canonical', $canonical_marker, $canonical_url );
		};

		try {
			self::prepare_singular_request( $post, array( 'page' => $page ) );
			$paged_url     = \wp_get_canonical_url( $post );
			$paged_parts   = is_string( $paged_url ) ? \wp_parse_url( $paged_url ) : array();
			$other_url     = \wp_get_canonical_url( $other_post );
			$expected_base = \get_permalink( $post );
			$expected_paged = is_string( $expected_base )
				? \trailingslashit( $expected_base ) . \user_trailingslashit( (string) $page, 'single_paged' )
				: false;
			$expected_paged_parts = is_string( $expected_paged ) ? \wp_parse_url( $expected_paged ) : array();
			$expected_other       = \get_permalink( $other_post );

			\add_filter( 'pre_option_permalink_structure', $plain_permalink_filter, 100 );
			try {
				$plain_url   = \wp_get_canonical_url( $post );
				$plain_parts = is_string( $plain_url ) ? \wp_parse_url( $plain_url ) : array();
				$plain_query = self::parse_query_from_parts( $plain_parts );
			} finally {
				\remove_filter( 'pre_option_permalink_structure', $plain_permalink_filter, 100 );
			}

			self::prepare_singular_request( $post, array( 'cpage' => $cpage ) );
			$comment_expected = \get_comments_pagenum_link( $cpage );
			$comment_url      = \wp_get_canonical_url( $post );

			self::prepare_singular_request( $post );
			\add_filter( 'get_canonical_url', $canonical_filter, 10, 2 );
			try {
				$filtered_url = \wp_get_canonical_url( $post );
				ob_start();
				\rel_canonical();
				$rel_output = ob_get_clean();
			} finally {
				\remove_filter( 'get_canonical_url', $canonical_filter, 10 );
			}
			$filtered_parts = is_string( $filtered_url ) ? \wp_parse_url( $filtered_url ) : array();
			$filtered_query = self::parse_query_from_parts( $filtered_parts );

			self::prepare_request( array(), array( 'is_home' => true ) );
			ob_start();
			\rel_canonical();
			$rel_non_singular = ob_get_clean();

			self::collect_failure(
				$failures,
				is_string( $expected_base )
					&& $expected_paged === $paged_url
					&& str_ends_with( $paged_url, '/' . $page . '/' )
					&& ( $expected_paged_parts['path'] ?? null ) === ( $paged_parts['path'] ?? null )
					&& $expected_other === $other_url
					&& false === \wp_get_canonical_url( $draft_post )
					&& self::HOME_URL . '/?p=' . $post->ID . '&page=' . $page === $plain_url
					&& array( 'p' => (string) $post->ID, 'page' => (string) $page ) === $plain_query
					&& $comment_expected === $comment_url
					&& 2 === count( $canonical_filter_seen )
					&& array( $post->ID, $post->ID ) === array_column( $canonical_filter_seen, 'postId' )
					&& $canonical_marker === ( $filtered_query['cfz-canonical'] ?? null )
					&& is_string( $rel_output )
					&& '<link rel="canonical" href="' . esc_url( $filtered_url ) . "\" />\n" === $rel_output
					&& '' === $rel_non_singular
					&& false === \has_filter( 'get_canonical_url', $canonical_filter )
					&& false === \has_filter( 'pre_option_permalink_structure', $plain_permalink_filter ),
				'wp_get_canonical_url and rel_canonical honor status, current pagination, comments pages, filters, and singular gates',
				array(
					'pagedUrl'       => $paged_url,
					'otherUrl'       => $other_url,
					'plainUrl'       => $plain_url,
					'plainQuery'     => $plain_query,
					'commentUrl'     => $comment_url,
					'filteredUrl'    => $filtered_url,
					'filterSeen'     => $canonical_filter_seen,
					'relOutput'      => $rel_output,
					'relNonSingular' => $rel_non_singular,
					'expectedPaged'  => $expected_paged,
				)
			);
		} finally {
			\remove_filter( 'get_canonical_url', $canonical_filter, 10 );
			\remove_filter( 'pre_option_permalink_structure', $plain_permalink_filter, 100 );

			self::restore_cache_slots( $cached_snapshot, 'posts' );
		}

		return $ctx->result(
			'canonical-routing.canonical-url-output-helpers',
			array() === $failures,
			array(
				'postId'   => $post->ID,
				'failures' => array_slice( $failures, 0, 4 ),
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
			'pre_option_home'                        => array( self::class, 'filter_home' ),
			'pre_option_siteurl'                     => array( self::class, 'filter_siteurl' ),
			'pre_option_permalink_structure'         => array( self::class, 'filter_permalink_structure' ),
			'pre_option_blog_charset'                => array( self::class, 'filter_blog_charset' ),
			'pre_option_html_type'                   => array( self::class, 'filter_html_type' ),
			'pre_option_default_feed'                => array( self::class, 'filter_default_feed' ),
			'pre_option_default_comments_page'       => array( self::class, 'filter_default_comments_page' ),
			'pre_option_page_comments'               => array( self::class, 'filter_false' ),
			'pre_option_wp_attachment_pages_enabled' => array( self::class, 'filter_zero' ),
		);

		foreach ( $options as $hook => $callback ) {
			\add_filter( $hook, $callback, 0 );
		}
	}

	private static function initialize_core_content_types(): void {
		\create_initial_post_types();
	}

	private static function remove_option_filters(): void {
		$options = array(
			'pre_option_home'                        => array( self::class, 'filter_home' ),
			'pre_option_siteurl'                     => array( self::class, 'filter_siteurl' ),
			'pre_option_permalink_structure'         => array( self::class, 'filter_permalink_structure' ),
			'pre_option_blog_charset'                => array( self::class, 'filter_blog_charset' ),
			'pre_option_html_type'                   => array( self::class, 'filter_html_type' ),
			'pre_option_default_feed'                => array( self::class, 'filter_default_feed' ),
			'pre_option_default_comments_page'       => array( self::class, 'filter_default_comments_page' ),
			'pre_option_page_comments'               => array( self::class, 'filter_false' ),
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

	private static function prepare_singular_request( \WP_Post $post, array $query_vars = array() ): void {
		self::prepare_request(
			$query_vars,
			array(
				'is_single'   => true,
				'is_singular' => true,
				'post_count'  => 1,
			)
		);

		$GLOBALS['post']                  = $post;
		$GLOBALS['wp_query']->post        = $post;
		$GLOBALS['wp_query']->posts       = array( $post );
		$GLOBALS['wp_query']->queried_object = $post;
		$GLOBALS['wp_query']->queried_object_id = $post->ID;
	}

	private static function prepare_attachment_request( \WP_Post $attachment, array $request_query = array() ): void {
		self::prepare_request(
			array(
				'attachment'    => $attachment->post_name,
				'attachment_id' => $attachment->ID,
			),
			array(
				'is_attachment' => true,
				'is_singular'   => true,
				'post_count'    => 1,
			)
		);

		$GLOBALS['post']                       = $attachment;
		$GLOBALS['wp_query']->post             = $attachment;
		$GLOBALS['wp_query']->posts            = array( $attachment );
		$GLOBALS['wp_query']->queried_object   = $attachment;
		$GLOBALS['wp_query']->queried_object_id = $attachment->ID;
		$_GET                                  = $request_query;
		$_SERVER['REQUEST_URI']                = '/site-base/';
		if ( array() !== $request_query ) {
			$_SERVER['REQUEST_URI'] .= '?' . http_build_query( $request_query, '', '&', PHP_QUERY_RFC3986 );
		}
	}

	private static function make_post( int $post_id, string $token, string $status ): \WP_Post {
		$slug = sanitize_title( 'canonical-' . $token . '-' . $status );
		$post = (object) array(
			'ID'                    => $post_id,
			'post_author'           => 1,
			'post_date'             => '2026-06-15 10:20:30',
			'post_date_gmt'         => '2026-06-15 10:20:30',
			'post_content'          => 'canonical routing fixture',
			'post_title'            => 'Canonical ' . $token,
			'post_excerpt'          => '',
			'post_status'           => $status,
			'post_name'             => $slug,
			'post_modified'         => '2026-06-16 10:20:30',
			'post_modified_gmt'     => '2026-06-16 10:20:30',
			'post_parent'           => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'to_ping'               => '',
			'pinged'                => '',
			'post_password'         => '',
			'post_content_filtered' => '',
			'post_excerpt_filtered' => '',
			'guid'                  => self::HOME_URL . '/?p=' . $post_id,
			'menu_order'            => 0,
			'filter'                => 'raw',
		);

		return new \WP_Post( $post );
	}

	private static function seed_post_row( array $overrides ): \WP_Post {
		$row = array_merge(
			array(
				'ID'                    => 0,
				'post_author'           => 1,
				'post_date'             => '2026-06-15 10:20:30',
				'post_date_gmt'         => '2026-06-15 10:20:30',
				'post_content'          => 'canonical routing db-backed fixture',
				'post_title'            => 'Canonical routing fixture',
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'canonical-routing-fixture',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2026-06-16 10:20:30',
				'post_modified_gmt'     => '2026-06-16 10:20:30',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => self::HOME_URL . '/?p=0',
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => 0,
				'filter'                => 'raw',
			),
			$overrides
		);
		$row['guid'] = $row['guid'] ?: self::HOME_URL . '/?p=' . (int) $row['ID'];

		$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->posts, $row );
		\wp_cache_delete( (int) $row['ID'], 'posts' );
		\wp_cache_delete( (int) $row['ID'], 'post_meta' );

		return new \WP_Post( (object) $row );
	}

	private static function seed_post_meta_row( int $post_id, string $meta_key, string $meta_value ): void {
		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			)
		);

		\wp_cache_delete( $post_id, 'post_meta' );
	}

	private static function date_query_vars( string $datetime ): array {
		return array(
			'year'     => (int) substr( $datetime, 0, 4 ),
			'monthnum' => (int) substr( $datetime, 5, 2 ),
			'day'      => (int) substr( $datetime, 8, 2 ),
		);
	}

	private static function old_redirect_slug( \ComponentFuzz\FuzzContext $ctx, array $case, string $label ): string {
		return sanitize_title_with_dashes(
			$label . ' ' . $case['token'] . ' ' . $ctx->int( 100, 999 )
		);
	}

	private static function wpdb_stub_available(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
			&& method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' );
	}

	private static function reset_db_content(): void {
		if ( ! self::wpdb_stub_available() ) {
			return;
		}

		$GLOBALS['wpdb']->component_fuzz_reset_content();
		self::flush_runtime_cache();
	}

	private static function flush_runtime_cache(): void {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			\wp_cache_flush_runtime();
			return;
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function snapshot_cache_slots( array $keys, string $group ): array {
		$snapshots = array();

		foreach ( $keys as $key ) {
			$found             = false;
			$value             = \wp_cache_get( $key, $group, false, $found );
			$snapshots[ $key ] = array(
				'found' => $found,
				'value' => self::clone_value( $value ),
			);
		}

		return $snapshots;
	}

	private static function restore_cache_slots( array $snapshots, string $group ): void {
		foreach ( $snapshots as $key => $snapshot ) {
			if ( ! empty( $snapshot['found'] ) ) {
				\wp_cache_set( $key, self::clone_value( $snapshot['value'] ), $group );
			} else {
				\wp_cache_delete( $key, $group );
			}
		}
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wp',
				'post',
				'wp_query',
				'wp_the_query',
				'wp_rewrite',
				'wp_filter',
				'wp_actions',
				'wp_filters',
				'wp_current_filter',
				'wp_post_statuses',
				'wp_post_types',
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
