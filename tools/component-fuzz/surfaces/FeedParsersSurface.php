<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes local feed parser and legacy feed utility APIs without network IO.
 */
final class FeedParsersSurface {
	public const NAME = 'feed-parsers';

	private const PREVIEW_BYTES = 220;

	/** @var array<int,array<string,mixed>> */
	private static array $http_requests = array();

	/** @var array<int,string> */
	private static array $temp_dirs = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$load_errors = self::load_feed_parser_files();
		$missing     = self::missing_requirements();
		if ( array() !== $load_errors || array() !== $missing ) {
			return array(
				$ctx->skip(
					'feed-parsers.bootstrap-apis-available',
					'Required feed parser APIs are unavailable.',
					array(
						'loadErrors' => $load_errors,
						'missing'    => $missing,
					)
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();
			self::install_no_network_guard();

			$case = self::prepare_case( $ctx );

			$rows[] = self::check_magpie_rss_parser( $ctx->fork( 'magpie-rss' ), $case );
			$rows[] = self::check_magpie_atom_normalization( $ctx->fork( 'magpie-atom' ), $case );
			$rows[] = self::check_atomlib_file_parser( $ctx->fork( 'atomlib' ), $case );
			$rows[] = self::check_simplepie_raw_parser_and_sanitizer( $ctx->fork( 'simplepie' ), $case );
			$rows[] = self::check_simplepie_file_http_adapter( $ctx->fork( 'simplepie-file-http' ), $case );
			$rows[] = self::check_feed_cache_adapters( $ctx->fork( 'cache' ), $case );
			$rows[] = self::check_legacy_helpers_and_file_boundaries( $ctx->fork( 'legacy' ), $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'feed-parsers.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			$http_requests = self::$http_requests;
			self::restore_state( $snapshot );
			self::cleanup_temp_dirs();
		}

		$rows[] = $ctx->result(
			'feed-parsers.no-network-requests',
			array() === $http_requests,
			array( 'requests' => array_slice( $http_requests, 0, 5 ) )
		);

		$rows[] = $ctx->result(
			'feed-parsers.state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedServer'  => array_keys( $snapshot['server'] ),
			)
		);

		self::reset_runtime();

		return $rows;
	}

	public static function block_http_request( $preempt, array $parsed_args, string $url ) {
		self::$http_requests[] = array(
			'url'     => $url,
			'timeout' => $parsed_args['timeout'] ?? null,
			'headers' => array_keys( (array) ( $parsed_args['headers'] ?? array() ) ),
		);

		return new \WP_Error( 'component_fuzz_no_network', 'Component fuzz feed parsers surface blocks live HTTP requests.' );
	}

	private static function load_feed_parser_files(): array {
		if ( ! isset( $GLOBALS['wp_version'] ) ) {
			$GLOBALS['wp_version'] = 'component-fuzz';
		}

		$errors = array();
		foreach (
			array(
				'class-feed.php',
				'rss.php',
				'rss-functions.php',
				'atomlib.php',
			) as $file
		) {
			$path = ABSPATH . WPINC . '/' . $file;
			if ( ! file_exists( $path ) ) {
				$errors[] = "missing {$file}";
				continue;
			}

			try {
				require_once $path;
			} catch ( \Throwable $e ) {
				$errors[] = $file . ': ' . $e->getMessage();
			}
		}

		if ( function_exists( 'init' ) ) {
			try {
				\init();
			} catch ( \Throwable $e ) {
				$errors[] = 'Magpie init: ' . $e->getMessage();
			}
		}

		return $errors;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'AtomParser',
				'MagpieRSS',
				'RSSCache',
				'SimplePie\SimplePie',
				'SimplePie\Sanitize',
				'WP_Error',
				'WP_Feed_Cache_Transient',
				'WP_SimplePie_File',
				'WP_SimplePie_Sanitize_KSES',
			) as $class
		) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'delete_site_transient',
				'delete_transient',
				'get_bloginfo',
				'get_site_transient',
				'get_transient',
				'is_client_error',
				'is_error',
				'is_wp_error',
				'is_info',
				'is_redirect',
				'is_server_error',
				'is_success',
				'parse_w3cdtf',
				'remove_filter',
				'set_site_transient',
				'set_transient',
				'simplexml_load_string',
				'wp_cache_flush',
				'wp_safe_remote_request',
				'wp_remote_retrieve_body',
				'wp_remote_retrieve_headers',
				'wp_remote_retrieve_response_code',
				'wp_kses_post',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'xml_parser_create', 'xml_parser_create_ns' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_magpie_rss_parser( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$captured = self::capture_legacy_warnings(
			static function () use ( $case ) {
				return new \MagpieRSS( $case['rssXml'] );
			}
		);
		$rss      = $captured['value'];

		self::collect_failure(
			$failures,
			$rss instanceof \MagpieRSS && '2.0' === $rss->is_rss() && false === $rss->is_atom(),
			'MagpieRSS identifies bounded RSS 2.0 fixtures',
			array(
				'type'    => $rss instanceof \MagpieRSS ? $rss->feed_type : null,
				'version' => $rss instanceof \MagpieRSS ? $rss->feed_version : null,
			)
		);
		self::collect_failure(
			$failures,
			$rss instanceof \MagpieRSS
				&& $case['channel']['title'] === ( $rss->channel['title'] ?? null )
				&& $case['channel']['description'] === ( $rss->channel['description'] ?? null )
				&& ( $rss->channel['description'] ?? null ) === ( $rss->channel['tagline'] ?? null ),
			'RSS channel text is entity-decoded and normalized into tagline',
			array( 'channel' => $rss instanceof \MagpieRSS ? $rss->channel : null )
		);
		self::collect_failure(
			$failures,
			$rss instanceof \MagpieRSS && count( $case['items'] ) === count( $rss->items ),
			'RSS item count matches the bounded fixture',
			array(
				'expected' => count( $case['items'] ),
				'actual'   => $rss instanceof \MagpieRSS ? count( $rss->items ) : null,
			)
		);

		if ( $rss instanceof \MagpieRSS ) {
			foreach ( $case['items'] as $index => $expected ) {
				$item = $rss->items[ $index ] ?? array();
				self::collect_failure(
					$failures,
					$expected['title'] === ( $item['title'] ?? null )
						&& $expected['description'] === ( $item['description'] ?? null )
						&& $expected['description'] === ( $item['summary'] ?? null )
						&& $expected['content'] === ( $item['content']['encoded'] ?? null )
						&& $expected['author'] === ( $item['dc']['creator'] ?? null )
						&& str_contains( $item['title'] ?? '', ']]>' ),
					'RSS item title/description/content namespace fields survive entity and CDATA boundaries',
					array(
						'index' => $index,
						'item'  => $item,
					)
				);
			}
		}

		$response          = (object) array(
			'headers' => array(
				'etag: "' . $case['token'] . '"',
				'last-modified: ' . $case['items'][0]['rssDate'],
			),
			'results' => $case['rssXml'],
		);
		$response_captured = self::capture_legacy_warnings(
			static function () use ( $response ) {
				return \_response_to_rss( $response );
			}
		);
		$response_rss      = $response_captured['value'];
		self::collect_failure(
			$failures,
			$response_rss instanceof \MagpieRSS
				&& '"' . $case['token'] . '"' === ( $response_rss->etag ?? null )
				&& $case['items'][0]['rssDate'] === ( $response_rss->last_modified ?? null ),
			'_response_to_rss preserves lower-case ETag and Last-Modified headers on parsed feeds',
			array(
				'etag'         => $response_rss instanceof \MagpieRSS ? ( $response_rss->etag ?? null ) : null,
				'lastModified' => $response_rss instanceof \MagpieRSS ? ( $response_rss->last_modified ?? null ) : null,
			)
		);

		$bad_captured = self::capture_legacy_warnings(
			static function () use ( $case ) {
				return new \MagpieRSS( $case['malformedRssXml'] );
			}
		);
		$bad          = $bad_captured['value'];
		self::collect_failure(
			$failures,
			$bad instanceof \MagpieRSS && count( $bad->items ) <= 1 && strlen( $case['malformedRssXml'] ) < 512,
			'Malformed RSS fixtures stay bounded and do not synthesize extra items',
			array(
				'items' => $bad instanceof \MagpieRSS ? $bad->items : null,
				'bytes' => strlen( $case['malformedRssXml'] ),
			)
		);

		$warnings = array_merge( $captured['warnings'], $response_captured['warnings'], $bad_captured['warnings'] );
		self::collect_failure(
			$failures,
			self::only_known_magpie_warnings( $warnings ),
			'Magpie parser emits only the known PHP 8 XML callback by-reference warning',
			array( 'warnings' => array_slice( $warnings, 0, 8 ) )
		);

		return self::result(
			$ctx,
			'feed-parsers.magpie-rss.structure-cdata-and-response-headers',
			$failures,
			array(
				'fixture'  => self::preview( $case['rssXml'] ),
				'warnings' => count( $warnings ),
			)
		);
	}

	private static function check_magpie_atom_normalization( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$captured = self::capture_legacy_warnings(
			static function () use ( $case ) {
				return new \MagpieRSS( $case['magpieAtomXml'] );
			}
		);
		$atom     = $captured['value'];

		self::collect_failure(
			$failures,
			$atom instanceof \MagpieRSS && false === $atom->is_rss() && '0.3' === $atom->is_atom(),
			'MagpieRSS identifies bounded Atom fixtures',
			array(
				'type'    => $atom instanceof \MagpieRSS ? $atom->feed_type : null,
				'version' => $atom instanceof \MagpieRSS ? $atom->feed_version : null,
			)
		);
		self::collect_failure(
			$failures,
			$atom instanceof \MagpieRSS
				&& $case['channel']['description'] === ( $atom->channel['tagline'] ?? null )
				&& $case['channel']['description'] === ( $atom->channel['description'] ?? null )
				&& count( $case['items'] ) === count( $atom->items ),
			'Atom channel tagline is normalized to description and entry count is preserved',
			array( 'channel' => $atom instanceof \MagpieRSS ? $atom->channel : null )
		);

		if ( $atom instanceof \MagpieRSS ) {
			foreach ( $case['items'] as $index => $expected ) {
				$item = $atom->items[ $index ] ?? array();
				self::collect_failure(
					$failures,
					$expected['title'] === ( $item['title'] ?? null )
						&& $expected['description'] === ( $item['description'] ?? null )
						&& $expected['description'] === ( $item['summary'] ?? null )
						&& $expected['content'] === ( $item['content']['encoded'] ?? null )
						&& $expected['link'] === ( $item['link'] ?? null ),
					'Atom entries are normalized onto legacy RSS item keys',
					array(
						'index' => $index,
						'item'  => $item,
					)
				);
			}
		}

		self::collect_failure(
			$failures,
			self::only_known_magpie_warnings( $captured['warnings'] ),
			'Atom parsing emits only the known Magpie PHP 8 callback warning',
			array( 'warnings' => array_slice( $captured['warnings'], 0, 8 ) )
		);

		return self::result(
			$ctx,
			'feed-parsers.magpie-atom.normalization',
			$failures,
			array( 'fixture' => self::preview( $case['magpieAtomXml'] ) )
		);
	}

	private static function check_atomlib_file_parser( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		if ( ! function_exists( 'xml_parser_create_ns' ) ) {
			return $ctx->skip(
				'feed-parsers.atomlib.file-parser',
				'PHP XML namespace parser is unavailable.'
			);
		}

		$failures = array();
		$dir      = self::temp_dir( 'atomlib-' . $ctx->identifier( 4, 8 ) );
		$valid    = $dir . DIRECTORY_SEPARATOR . 'valid.atom';
		$invalid  = $dir . DIRECTORY_SEPARATOR . 'invalid.atom';

		file_put_contents( $valid, $case['atomlibXml'] );
		file_put_contents( $invalid, $case['malformedAtomXml'] );

		$parser             = new \AtomParser();
		$parser->FILE       = $valid;
		$parser->in_content = array();
		$ok                 = false;
		$throwable          = null;

		try {
			$ok = $parser->parse();
		} catch ( \Throwable $e ) {
			$throwable = self::describe_throwable( $e );
		}

		self::collect_failure(
			$failures,
			true === $ok
				&& null === $throwable
				&& 1 === count( $parser->feed->links )
				&& 1 === count( $parser->feed->categories )
				&& count( $case['items'] ) === count( $parser->feed->entries )
				&& $case['channel']['link'] === ( $parser->feed->links[0]['href'] ?? null )
				&& $case['category'] === ( $parser->feed->categories[0]['term'] ?? null ),
			'AtomParser reads local bounded Atom files and preserves link/category attributes',
			array(
				'ok'        => $ok,
				'throwable' => $throwable,
				'feed'      => self::describe_atom_feed( $parser->feed ),
			)
		);

		foreach ( $parser->feed->entries as $index => $entry ) {
			self::collect_failure(
				$failures,
				isset( $case['items'][ $index ] )
					&& $case['items'][ $index ]['link'] === ( $entry->links[0]['href'] ?? null )
					&& $case['category'] === ( $entry->categories[0]['term'] ?? null ),
				'AtomParser entry link/category attributes match generated fixtures',
				array(
					'index' => $index,
					'entry' => self::describe_atom_entry( $entry ),
				)
			);
		}

		$bad             = new \AtomParser();
		$bad->FILE       = $invalid;
		$bad->in_content = array();
		$bad_ok          = true;
		$bad_throwable   = null;
		try {
			$bad_ok = $bad->parse();
		} catch ( \Throwable $e ) {
			$bad_throwable = self::describe_throwable( $e );
		}

		self::collect_failure(
			$failures,
			false === $bad_ok
				&& null === $bad_throwable
				&& is_string( $bad->error )
				&& str_contains( $bad->error, 'XML Error' )
				&& 0 === count( $bad->feed->entries )
				&& strlen( $case['malformedAtomXml'] ) < 512,
			'Malformed AtomParser files fail closed with a parser error and no synthesized entries',
			array(
				'ok'        => $bad_ok,
				'throwable' => $bad_throwable,
				'error'     => $bad->error,
				'entries'   => count( $bad->feed->entries ),
			)
		);

		return self::result(
			$ctx,
			'feed-parsers.atomlib.local-file-parser-and-malformed-input',
			$failures,
			array(
				'valid'   => self::preview( $case['atomlibXml'] ),
				'invalid' => self::preview( $case['malformedAtomXml'] ),
			)
		);
	}

	private static function check_simplepie_raw_parser_and_sanitizer( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$rss      = self::new_simplepie_for_raw_data( $case['rssXml'] );
		$rss_ok   = $rss->init();
		$rss->set_output_encoding( self::blog_charset() );

		self::collect_failure(
			$failures,
			true === $rss_ok
				&& null === $rss->error()
				&& count( $case['items'] ) === $rss->get_item_quantity()
				&& str_contains( $rss->get_title(), 'Feed' ),
			'SimplePie parses bounded RSS raw data without URL fetching or cache writes',
			array(
				'ok'       => $rss_ok,
				'error'    => $rss->error(),
				'quantity' => $rss->get_item_quantity(),
				'title'    => $rss->get_title(),
			)
		);

		foreach ( $rss->get_items( 0, count( $case['items'] ) ) as $index => $item ) {
			$title       = $item->get_title();
			$description = $item->get_description();
			self::collect_failure(
				$failures,
				is_string( $title )
					&& self::contains_item_token( $title, $case['items'] )
					&& ! str_contains( strtolower( $title ), '<script' )
					&& is_string( $description )
					&& ! str_contains( strtolower( $description ), '<script' )
					&& false !== $item->get_date( 'U' ),
				'SimplePie RSS items expose sanitized titles/descriptions and parseable dates',
				array(
					'index'       => $index,
					'title'       => $title,
					'description' => self::preview( (string) $description ),
					'date'        => $item->get_date( 'U' ),
				)
			);
		}

		$atom    = self::new_simplepie_for_raw_data( $case['simplepieAtomXml'] );
		$atom_ok = $atom->init();
		$atom->set_output_encoding( self::blog_charset() );
		self::collect_failure(
			$failures,
			true === $atom_ok
				&& null === $atom->error()
				&& count( $case['items'] ) === $atom->get_item_quantity()
				&& str_contains( $atom->get_title(), 'Feed' ),
			'SimplePie parses bounded Atom raw data with matching item count',
			array(
				'ok'       => $atom_ok,
				'error'    => $atom->error(),
				'quantity' => $atom->get_item_quantity(),
				'title'    => $atom->get_title(),
			)
		);

		foreach ( $atom->get_items( 0, count( $case['items'] ) ) as $index => $item ) {
			$title = $item->get_title();
			self::collect_failure(
				$failures,
				is_string( $title )
					&& self::contains_item_token( $title, $case['items'] )
					&& ! str_contains( strtolower( $title ), '<script' )
					&& false !== $item->get_date( 'U' ),
				'SimplePie Atom items expose sanitized titles and parseable W3C dates',
				array(
					'index' => $index,
					'title' => $title,
					'date'  => $item->get_date( 'U' ),
				)
			);
		}

		$sanitizer = new \WP_SimplePie_Sanitize_KSES();
		$dirty     = '<p>Allowed ' . $case['token'] . '</p><script>alert(1)</script><a href="javascript:alert(1)" onclick="bad()">bad</a>';
		$clean     = $sanitizer->sanitize( $dirty, \SimplePie\SimplePie::CONSTRUCT_HTML );
		$base64    = $sanitizer->sanitize( base64_encode( $dirty ), \SimplePie\SimplePie::CONSTRUCT_HTML | \SimplePie\SimplePie::CONSTRUCT_BASE64 );
		self::collect_failure(
			$failures,
			is_string( $clean )
				&& str_contains( $clean, '<p>Allowed ' . $case['token'] . '</p>' )
				&& ! str_contains( strtolower( $clean ), '<script' )
				&& ! str_contains( strtolower( $clean ), 'javascript:' )
				&& ! str_contains( strtolower( $clean ), 'onclick' )
				&& is_string( $base64 )
				&& ! str_contains( strtolower( $base64 ), '<script' )
				&& str_contains( $base64, '<p>Allowed ' . $case['token'] . '</p>' ),
			'WP_SimplePie_Sanitize_KSES applies KSES to HTML and base64 HTML constructs',
			array(
				'clean'  => self::preview( (string) $clean ),
				'base64' => self::preview( (string) $base64 ),
			)
		);

		$malformed = self::new_simplepie_for_raw_data( $case['malformedRssXml'] );
		$previous_error_reporting = error_reporting();
		error_reporting( $previous_error_reporting & ~E_USER_NOTICE );
		try {
			$bad_ok = $malformed->init();
		} finally {
			error_reporting( $previous_error_reporting );
		}
		self::collect_failure(
			$failures,
			false === $bad_ok
				&& is_string( $malformed->error() )
				&& str_contains( $malformed->error(), 'invalid XML' )
				&& 0 === $malformed->get_item_quantity(),
			'SimplePie malformed raw data fails closed with no items',
			array(
				'ok'       => $bad_ok,
				'error'    => $malformed->error(),
				'quantity' => $malformed->get_item_quantity(),
			)
		);

		return self::result(
			$ctx,
			'feed-parsers.simplepie.raw-data-cacheless-parsing-and-sanitizer',
			$failures,
			array(
				'rss'  => self::preview( $case['rssXml'] ),
				'atom' => self::preview( $case['simplepieAtomXml'] ),
			)
		);
	}

	private static function check_simplepie_file_http_adapter( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures         = array();
		$status_code      = $ctx->choice( array( 200, 203, 206 ) );
		$success_url      = 'https://feeds.example.test/' . rawurlencode( $case['token'] ) . '/http-adapter.xml?v=' . rawurlencode( $ctx->identifier( 3, 8 ) );
		$error_url        = 'https://feeds.example.test/' . rawurlencode( $case['token'] ) . '/http-error.xml?v=' . rawurlencode( $ctx->identifier( 3, 8 ) );
		$timeout          = $ctx->int( 2, 8 );
		$redirects        = $ctx->int( 0, 4 );
		$request_headers  = array(
			'Accept'        => 'application/rss+xml',
			'If-None-Match' => '"' . $case['token'] . '"',
			'X-Fuzz'       => $case['items'][0]['token'],
		);
		$user_agent       = 'ComponentFuzz/' . $case['token'];
		$success_requests = array();
		$error_requests   = array();

		$success_stub = static function ( $preempt, array $parsed_args, string $url ) use ( $case, &$success_requests, $status_code, $success_url ) {
			$success_requests[] = array(
				'url'         => $url,
				'timeout'     => $parsed_args['timeout'] ?? null,
				'redirection' => $parsed_args['redirection'] ?? null,
				'headers'     => $parsed_args['headers'] ?? null,
				'userAgent'   => $parsed_args['user-agent'] ?? null,
				'preempt'     => false === $preempt ? 'false' : get_debug_type( $preempt ),
			);

			if ( $success_url !== $url ) {
				return new \WP_Error( 'component_fuzz_unexpected_feed_url', 'Unexpected feed adapter URL.' );
			}

			return array(
				'headers'  => array(
					'content-type' => array(
						'text/plain; charset=US-ASCII',
						'application/rss+xml; charset=UTF-8',
					),
					'etag'         => '"' . $case['token'] . '"',
					'set-cookie'   => array(
						'first=' . $case['token'],
						'second=' . $case['items'][0]['token'],
					),
					'x-fuzz'       => array( 'alpha', 'beta' ),
				),
				'body'     => $case['rssXml'],
				'response' => array(
					'code'    => $status_code,
					'message' => 'Component Fuzz',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		$file = self::with_preempted_http_request(
			$success_stub,
			static function () use ( $request_headers, $success_url, $timeout, $redirects, $user_agent ) {
				return new \WP_SimplePie_File( $success_url, $timeout, $redirects, $request_headers, $user_agent );
			}
		);

		self::collect_failure(
			$failures,
			$file instanceof \WP_SimplePie_File
				&& true === $file->success
				&& null === $file->error
				&& $success_url === $file->url
				&& \SimplePie\SimplePie::FILE_SOURCE_REMOTE === $file->method
				&& $case['rssXml'] === $file->body
				&& $status_code === $file->status_code,
			'WP_SimplePie_File maps a preempted HTTP success response onto SimplePie file state',
			array(
				'url'        => $file instanceof \WP_SimplePie_File ? $file->url : null,
				'success'    => $file instanceof \WP_SimplePie_File ? $file->success : null,
				'error'      => $file instanceof \WP_SimplePie_File ? $file->error : null,
				'method'     => $file instanceof \WP_SimplePie_File ? $file->method : null,
				'statusCode' => $file instanceof \WP_SimplePie_File ? $file->status_code : null,
			)
		);

		self::collect_failure(
			$failures,
			$file instanceof \WP_SimplePie_File
				&& 'application/rss+xml; charset=UTF-8' === ( $file->headers['content-type'] ?? null )
				&& '"' . $case['token'] . '"' === ( $file->headers['etag'] ?? null )
				&& 'first=' . $case['token'] . ', second=' . $case['items'][0]['token'] === ( $file->headers['set-cookie'] ?? null )
				&& 'alpha, beta' === ( $file->headers['x-fuzz'] ?? null ),
			'WP_SimplePie_File normalizes repeated HTTP headers using SimplePie-compatible rules',
			array( 'headers' => $file instanceof \WP_SimplePie_File ? $file->headers : null )
		);

		self::collect_failure(
			$failures,
			1 === count( $success_requests )
				&& $success_url === ( $success_requests[0]['url'] ?? null )
				&& $timeout === ( $success_requests[0]['timeout'] ?? null )
				&& $redirects === ( $success_requests[0]['redirection'] ?? null )
				&& $request_headers === ( $success_requests[0]['headers'] ?? null )
				&& $user_agent === ( $success_requests[0]['userAgent'] ?? null )
				&& 'false' === ( $success_requests[0]['preempt'] ?? null ),
			'WP_SimplePie_File passes generated timeout, redirect, header, and user-agent arguments to the HTTP layer',
			array( 'requests' => $success_requests )
		);

		$error_stub = static function ( $preempt, array $parsed_args, string $url ) use ( &$error_requests, $error_url ) {
			$error_requests[] = array(
				'url'     => $url,
				'timeout' => $parsed_args['timeout'] ?? null,
				'preempt' => false === $preempt ? 'false' : get_debug_type( $preempt ),
			);

			if ( $error_url !== $url ) {
				return new \WP_Error( 'component_fuzz_unexpected_feed_url', 'Unexpected feed adapter URL.' );
			}

			return new \WP_Error( 'component_fuzz_http_error', 'Synthetic feed transport failure.' );
		};

		$error_file = self::with_preempted_http_request(
			$error_stub,
			static function () use ( $error_url, $timeout, $redirects, $request_headers, $user_agent ) {
				return new \WP_SimplePie_File( $error_url, $timeout, $redirects, $request_headers, $user_agent );
			}
		);

		self::collect_failure(
			$failures,
			$error_file instanceof \WP_SimplePie_File
				&& false === $error_file->success
				&& 'WP HTTP Error: Synthetic feed transport failure.' === $error_file->error
				&& $request_headers === $error_file->headers
				&& null === $error_file->body
				&& 0 === $error_file->status_code
				&& 1 === count( $error_requests ),
			'WP_SimplePie_File maps preempted WP_Error responses to bounded failure state',
			array(
				'error'    => $error_file instanceof \WP_SimplePie_File ? $error_file->error : null,
				'headers'  => $error_file instanceof \WP_SimplePie_File ? $error_file->headers : null,
				'body'     => $error_file instanceof \WP_SimplePie_File ? $error_file->body : null,
				'requests' => $error_requests,
			)
		);

		return self::result(
			$ctx,
			'feed-parsers.simplepie-file.http-response-normalization-and-error-state',
			$failures,
			array(
				'successUrl' => $success_url,
				'errorUrl'   => $error_url,
				'statusCode' => $status_code,
			)
		);
	}

	private static function check_feed_cache_adapters( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$name     = 'cfz_' . strtolower( $case['token'] ) . '_' . strtolower( $ctx->identifier( 4, 10 ) );
		$lifetime = $ctx->int( 30, 900 );

		$lifetime_filter = static function () use ( $lifetime ): int {
				return $lifetime;
		};
		\add_filter( 'wp_feed_cache_transient_lifetime', $lifetime_filter, 10, 2 );
		$cache = new \WP_Feed_Cache_Transient( 'wp_transient', $name, 'spc' );
		\remove_filter( 'wp_feed_cache_transient_lifetime', $lifetime_filter, 10 );

		self::collect_failure(
			$failures,
			'feed_' . $name === $cache->name
				&& 'feed_mod_' . $name === $cache->mod_name
				&& $lifetime === $cache->lifetime
				&& ! str_contains( $cache->name, DIRECTORY_SEPARATOR )
				&& strlen( $cache->name ) < 128,
			'WP_Feed_Cache_Transient builds bounded transient names and applies lifetime filter',
			array(
				'name'     => $cache->name,
				'modName'  => $cache->mod_name,
				'lifetime' => $cache->lifetime,
			)
		);

		$data       = array(
			'feed'  => $case['channel']['title'],
			'items' => array_column( $case['items'], 'guid' ),
		);
		$saved      = $cache->save( $data );
		$loaded     = $cache->load();
		$mtime      = $cache->mtime();
		$touched    = $cache->touch();
		$touched_at = $cache->mtime();
		$unlinked   = $cache->unlink();
		$after      = $cache->load();

		self::collect_failure(
			$failures,
			true === $saved
				&& $data === $loaded
				&& is_int( $mtime )
				&& is_bool( $touched )
				&& is_int( $touched_at )
				&& $touched_at >= $mtime
				&& true === $unlinked
				&& false === $after,
			'WP_Feed_Cache_Transient save/load/touch/unlink round trips through site transients',
			array(
				'loaded'    => $loaded,
				'mtime'     => $mtime,
				'touchedAt' => $touched_at,
				'after'     => $after,
			)
		);

		$factory = ( new \ReflectionClass( \WP_Feed_Cache::class ) )->newInstanceWithoutConstructor();
		$created = $factory->create( 'wp_transient', $name . '_factory', 'spc' );
		self::collect_failure(
			$failures,
			$created instanceof \WP_Feed_Cache_Transient
				&& 'feed_' . $name . '_factory' === $created->name,
			'WP_Feed_Cache factory creates the transient-backed adapter',
			array(
				'class' => is_object( $created ) ? get_class( $created ) : gettype( $created ),
				'name'  => $created instanceof \WP_Feed_Cache_Transient ? $created->name : null,
			)
		);
		if ( $created instanceof \WP_Feed_Cache_Transient ) {
			$created->unlink();
		}

		$url       = $case['cacheUrl'];
		$rss_cache = new \RSSCache( self::temp_dir( 'magpie-cache-' . $ctx->identifier( 4, 8 ) ), $ctx->int( 60, 3600 ) );
		$key       = $rss_cache->set( $url, (object) array( 'items' => $case['items'] ) );
		$status    = $rss_cache->check_cache( $url );
		$value     = $rss_cache->get( $url );
		$file_name = $rss_cache->file_name( $url );
		\delete_transient( $key );

		self::collect_failure(
			$failures,
			'rss_' . md5( $url ) === $key
				&& 'HIT' === $status
				&& is_object( $value )
				&& $case['items'] === $value->items
				&& md5( $url ) === $file_name
				&& 1 === preg_match( '/^[a-f0-9]{32}$/', $file_name ),
			'Legacy RSSCache uses md5 cache keys and transient-backed get/check_cache behavior',
			array(
				'url'      => $url,
				'key'      => $key,
				'status'   => $status,
				'fileName' => $file_name,
			)
		);

		return self::result(
			$ctx,
			'feed-parsers.cache-adapters.transient-key-boundaries',
			$failures,
			array( 'cacheName' => $name )
		);
	}

	private static function check_legacy_helpers_and_file_boundaries( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$failures = array();
		$date     = $case['w3cDate'];
		$parsed_capture = self::capture_legacy_warnings(
			static function () use ( $date ) {
				return \parse_w3cdtf( $date );
			}
		);
		$invalid_capture = self::capture_legacy_warnings(
			static function () use ( $case ) {
				return \parse_w3cdtf( 'not-a-date-' . $case['token'] );
			}
		);
		$parsed          = $parsed_capture['value'];
		self::collect_failure(
			$failures,
			$case['w3cEpoch'] === $parsed
				&& -1 === $invalid_capture['value']
				&& self::only_known_w3cdtf_warnings( array_merge( $parsed_capture['warnings'], $invalid_capture['warnings'] ) ),
			'parse_w3cdtf agrees with the generated UTC epoch and rejects malformed dates',
			array(
				'date'     => $date,
				'expected' => $case['w3cEpoch'],
				'actual'   => $parsed,
				'warnings' => array_merge( $parsed_capture['warnings'], $invalid_capture['warnings'] ),
			)
		);

		$status_cases = array(
			99  => array( false, false, false, false, false, false ),
			100 => array( true, false, false, false, false, false ),
			204 => array( false, true, false, false, false, false ),
			302 => array( false, false, true, false, false, false ),
			404 => array( false, false, false, true, true, false ),
			503 => array( false, false, false, true, false, true ),
			600 => array( false, false, false, false, false, false ),
		);
		foreach ( $status_cases as $status => $expected ) {
			$actual = array(
				\is_info( $status ),
				\is_success( $status ),
				\is_redirect( $status ),
				\is_error( $status ),
				\is_client_error( $status ),
				\is_server_error( $status ),
			);
			self::collect_failure(
				$failures,
				$expected === $actual,
				'Legacy HTTP status classifier boundaries remain stable',
				array(
					'status'   => $status,
					'expected' => $expected,
					'actual'   => $actual,
				)
			);
		}

		$dir        = self::temp_dir( 'simplepie-file-' . $ctx->identifier( 4, 8 ) );
		$local     = $dir . DIRECTORY_SEPARATOR . '../' . basename( $dir ) . DIRECTORY_SEPARATOR . 'feed.xml';
		$canonical = realpath( $dir ) . DIRECTORY_SEPARATOR . 'feed.xml';
		file_put_contents( $canonical, $case['rssXml'] );

		$file = new \WP_SimplePie_File( $local, $ctx->int( 1, 3 ), $ctx->int( 0, 2 ), array( 'X-Fuzz' => $case['token'] ), 'ComponentFuzz' );
		self::collect_failure(
			$failures,
			false === $file->success
				&& '' === $file->error
				&& $local === $file->url
				&& array() === self::$http_requests,
			'WP_SimplePie_File rejects non-HTTP local paths without reading files or touching HTTP',
			array(
				'url'     => $file->url,
				'success' => $file->success,
				'error'   => $file->error,
				'method'  => $file->method,
			)
		);

		$xml = self::parse_xml( $case['rssXml'] );
		self::collect_failure(
			$failures,
			$xml['ok'] && count( $case['items'] ) === $xml['itemCount'],
			'Generated RSS fixtures stay parseable and bounded before parser-specific checks',
			array( 'xml' => $xml )
		);

		return self::result(
			$ctx,
			'feed-parsers.legacy-helpers.date-status-and-file-boundaries',
			$failures,
			array( 'date' => $date )
		);
	}

	private static function prepare_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token       = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $ctx->identifier( 5, 12 ) ) );
		$item_count  = $ctx->int( 2, 4 );
		$base_epoch  = gmmktime(
			$ctx->int( 0, 23 ),
			$ctx->int( 0, 59 ),
			$ctx->int( 0, 59 ),
			$ctx->int( 1, 12 ),
			$ctx->int( 1, 25 ),
			$ctx->int( 2020, 2028 )
		);
		$offset_hour = $ctx->int( -11, 13 );
		$offset_min  = $ctx->choice( array( 0, 15, 30, 45 ) );
		$offset_secs = ( abs( $offset_hour ) * HOUR_IN_SECONDS ) + ( $offset_min * MINUTE_IN_SECONDS );
		if ( $offset_hour < 0 ) {
			$offset_secs *= -1;
		}

		$w3c_epoch = $base_epoch + ( $ctx->int( 0, 48 ) * HOUR_IN_SECONDS );
		$w3c_date  = gmdate( 'Y-m-d\TH:i:s', $w3c_epoch + $offset_secs )
			. ( $offset_secs >= 0 ? '+' : '-' )
			. sprintf( '%02d:%02d', abs( $offset_hour ), $offset_min );

		$channel = array(
			'title'       => 'Feed ' . $token . ' & "Quoted"',
			'link'        => 'https://example.test/feeds/' . rawurlencode( $token ) . '/?a=1&b=two',
			'description' => 'Description ' . $token . ' & <tag> text',
		);
		$category = 'category-' . strtolower( $ctx->identifier( 3, 8 ) );
		$items    = array();

		for ( $i = 0; $i < $item_count; $i++ ) {
			$item_token = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $ctx->identifier( 4, 10 ) ) );
			$epoch      = $base_epoch + ( $i * HOUR_IN_SECONDS ) + $ctx->int( 0, 900 );
			$items[]    = array(
				'token'       => $item_token,
				'title'       => 'Item ' . $i . ' ' . $item_token . ' & <title> CDATA ]]> tail',
				'link'        => 'https://example.test/items/' . $item_token . '/?x=1&y=' . rawurlencode( $token ),
				'guid'        => 'urn:component-fuzz:' . $token . ':' . $item_token,
				'author'      => 'Author ' . $item_token . ' & Co',
				'description' => 'Summary ' . $item_token . ' & <b>escaped</b> entity',
				'content'     => '<p>Body ' . $item_token . '</p><script>alert(1)</script> CDATA ]]> close',
				'rssDate'     => gmdate( 'D, d M Y H:i:s +0000', $epoch ),
				'w3cDate'     => gmdate( 'Y-m-d\TH:i:s\Z', $epoch ),
				'epoch'       => $epoch,
			);
		}

		return array(
			'token'            => $token,
			'channel'          => $channel,
			'category'         => $category,
			'items'            => $items,
			'w3cDate'          => $w3c_date,
			'w3cEpoch'         => $w3c_epoch,
			'cacheUrl'         => 'https://cache.example.test/../feeds/' . rawurlencode( $token ) . '?q=%2e%2e%2f&v=' . rawurlencode( $ctx->identifier( 3, 8 ) ),
			'rssXml'           => self::build_rss_xml( $channel, $items, $category ),
			'malformedRssXml'  => self::build_malformed_rss_xml( $channel, $items[0] ),
			'magpieAtomXml'    => self::build_magpie_atom_xml( $channel, $items ),
			'atomlibXml'       => self::build_atomlib_xml( $channel, $items, $category ),
			'malformedAtomXml' => self::build_malformed_atom_xml( $channel, $items[0] ),
			'simplepieAtomXml' => self::build_simplepie_atom_xml( $channel, $items ),
		);
	}

	private static function build_rss_xml( array $channel, array $items, string $category ): string {
		$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<rss version=\"2.0\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n";
		$xml .= "<channel>\n";
		$xml .= '<title>' . self::xml_text( $channel['title'] ) . "</title>\n";
		$xml .= '<link>' . self::xml_text( $channel['link'] ) . "</link>\n";
		$xml .= '<description>' . self::xml_text( $channel['description'] ) . "</description>\n";
		foreach ( $items as $item ) {
			$xml .= "<item>\n";
			$xml .= '<title>' . self::xml_cdata( $item['title'] ) . "</title>\n";
			$xml .= '<link>' . self::xml_text( $item['link'] ) . "</link>\n";
			$xml .= '<guid isPermaLink="false">' . self::xml_text( $item['guid'] ) . "</guid>\n";
			$xml .= '<pubDate>' . self::xml_text( $item['rssDate'] ) . "</pubDate>\n";
			$xml .= '<dc:creator>' . self::xml_text( $item['author'] ) . "</dc:creator>\n";
			$xml .= '<description>' . self::xml_text( $item['description'] ) . "</description>\n";
			$xml .= '<content:encoded>' . self::xml_cdata( $item['content'] ) . "</content:encoded>\n";
			$xml .= '<category>' . self::xml_cdata( $category ) . "</category>\n";
			$xml .= "</item>\n";
		}
		$xml .= "</channel>\n</rss>\n";

		return $xml;
	}

	private static function build_malformed_rss_xml( array $channel, array $item ): string {
		return '<?xml version="1.0"?><rss version="2.0"><channel><title>'
			. self::xml_text( $channel['title'] )
			. '</title><item><title>'
			. self::xml_text( $item['title'] )
			. '</title><description><![CDATA['
			. substr( $item['content'], 0, 80 );
	}

	private static function build_magpie_atom_xml( array $channel, array $items ): string {
		$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<feed version=\"0.3\">\n";
		$xml .= '<title>' . self::xml_text( $channel['title'] ) . "</title>\n";
		$xml .= '<tagline>' . self::xml_text( $channel['description'] ) . "</tagline>\n";
		foreach ( $items as $item ) {
			$xml .= "<entry>\n";
			$xml .= '<title>' . self::xml_cdata( $item['title'] ) . "</title>\n";
			$xml .= '<link rel="alternate" href="' . self::xml_attr( $item['link'] ) . "\" />\n";
			$xml .= '<summary>' . self::xml_text( $item['description'] ) . "</summary>\n";
			$xml .= '<content>' . self::xml_cdata( $item['content'] ) . "</content>\n";
			$xml .= "</entry>\n";
		}
		$xml .= "</feed>\n";

		return $xml;
	}

	private static function build_atomlib_xml( array $channel, array $items, string $category ): string {
		$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<feed xmlns=\"http://www.w3.org/2005/Atom\">\n";
		$xml .= '<link rel="alternate" href="' . self::xml_attr( $channel['link'] ) . "\" />\n";
		$xml .= '<category term="' . self::xml_attr( $category ) . '" label="' . self::xml_attr( $channel['title'] ) . "\" />\n";
		foreach ( $items as $item ) {
			$xml .= "<entry>\n";
			$xml .= '<link rel="alternate" href="' . self::xml_attr( $item['link'] ) . "\" />\n";
			$xml .= '<category term="' . self::xml_attr( $category ) . '" label="' . self::xml_attr( $item['title'] ) . "\" />\n";
			$xml .= "</entry>\n";
		}
		$xml .= "</feed>\n";

		return $xml;
	}

	private static function build_malformed_atom_xml( array $channel, array $item ): string {
		return '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><link href="'
			. self::xml_attr( $item['link'] )
			. '"><category term="'
			. self::xml_attr( $channel['title'] )
			. '"></feed>';
	}

	private static function build_simplepie_atom_xml( array $channel, array $items ): string {
		$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<feed xmlns=\"http://www.w3.org/2005/Atom\">\n";
		$xml .= '<title>' . self::xml_text( $channel['title'] ) . "</title>\n";
		$xml .= '<link rel="alternate" href="' . self::xml_attr( $channel['link'] ) . "\" />\n";
		$xml .= '<updated>' . self::xml_text( $items[0]['w3cDate'] ) . "</updated>\n";
		foreach ( $items as $item ) {
			$xml .= "<entry>\n";
			$xml .= '<id>' . self::xml_text( $item['guid'] ) . "</id>\n";
			$xml .= '<title type="html">' . self::xml_text( '<b>' . $item['title'] . '</b><script>alert(1)</script>' ) . "</title>\n";
			$xml .= '<link rel="alternate" href="' . self::xml_attr( $item['link'] ) . "\" />\n";
			$xml .= '<updated>' . self::xml_text( $item['w3cDate'] ) . "</updated>\n";
			$xml .= '<summary type="html">' . self::xml_text( '<p>' . $item['description'] . '</p><script>alert(1)</script>' ) . "</summary>\n";
			$xml .= '<content type="html">' . self::xml_text( $item['content'] ) . "</content>\n";
			$xml .= '<author><name>' . self::xml_text( $item['author'] ) . "</name></author>\n";
			$xml .= "</entry>\n";
		}
		$xml .= "</feed>\n";

		return $xml;
	}

	private static function new_simplepie_for_raw_data( string $xml ): \SimplePie\SimplePie {
		$feed = new \SimplePie\SimplePie();
		$feed->enable_cache( false );
		$feed->get_registry()->register( \SimplePie\Sanitize::class, 'WP_SimplePie_Sanitize_KSES', true );
		$feed->sanitize = new \WP_SimplePie_Sanitize_KSES();
		$feed->set_raw_data( $xml );

		return $feed;
	}

	private static function blog_charset(): string {
		$charset = \get_bloginfo( 'charset' );
		return is_string( $charset ) && '' !== $charset ? $charset : 'UTF-8';
	}

	private static function contains_item_token( string $value, array $items ): bool {
		foreach ( $items as $item ) {
			if ( str_contains( $value, $item['token'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function install_no_network_guard(): void {
		self::$http_requests = array();
		\add_filter( 'pre_http_request', array( self::class, 'block_http_request' ), 10, 3 );
	}

	private static function with_preempted_http_request( callable $stub, callable $callback ) {
		$guard_removed = \remove_filter( 'pre_http_request', array( self::class, 'block_http_request' ), 10 );
		\add_filter( 'pre_http_request', $stub, 10, 3 );

		try {
			return $callback();
		} finally {
			\remove_filter( 'pre_http_request', $stub, 10 );
			if ( $guard_removed ) {
				\add_filter( 'pre_http_request', array( self::class, 'block_http_request' ), 10, 3 );
			}
		}
	}

	private static function reset_runtime(): void {
		self::$http_requests = array();
		self::$temp_dirs     = array();
	}

	private static function snapshot_state(): array {
		return array(
			'globals' => self::snapshot_globals(
				array(
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_object_cache',
					'wp_version',
				)
			),
			'server'  => array(
				'HTTP_HOST'   => $_SERVER['HTTP_HOST'] ?? null,
				'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			),
			'options' => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_get_options()
				: array(),
			'counts'  => isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub
				? $GLOBALS['wpdb']->component_fuzz_content_counts()
				: array(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		self::restore_globals( $snapshot['globals'] );
		foreach ( array( 'HTTP_HOST', 'REQUEST_URI' ) as $key ) {
			if ( null === $snapshot['server'][ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $snapshot['server'][ $key ];
			}
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options( $snapshot['options'] );
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			if ( $snapshot['options'] !== $GLOBALS['wpdb']->component_fuzz_get_options() ) {
				return false;
			}
			if ( $snapshot['counts'] !== $GLOBALS['wpdb']->component_fuzz_content_counts() ) {
				return false;
			}
		}

		foreach ( $snapshot['server'] as $name => $value ) {
			if ( null === $value ) {
				if ( array_key_exists( $name, $_SERVER ) ) {
					return false;
				}
			} elseif ( ! array_key_exists( $name, $_SERVER ) || $value !== $_SERVER[ $name ] ) {
				return false;
			}
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
		}

		return true;
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
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
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
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

	private static function capture_legacy_warnings( callable $callback ): array {
		$warnings = array();
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ) use ( &$warnings ): bool {
				if ( error_reporting() & $severity ) {
					$warnings[] = array(
						'severity' => $severity,
						'message'  => $message,
						'file'     => basename( $file ),
						'line'     => $line,
					);
				}
				return true;
			}
		);

		try {
			$value = $callback();
		} finally {
			restore_error_handler();
		}

		return array(
			'value'    => $value ?? null,
			'warnings' => $warnings,
		);
	}

	private static function only_known_magpie_warnings( array $warnings ): bool {
		if ( array() === $warnings ) {
			return true;
		}

		foreach ( $warnings as $warning ) {
			$message = $warning['message'] ?? '';
			if (
				! str_contains( $message, 'MagpieRSS::feed_start_element(): Argument #3 ($attrs) must be passed by reference' )
				&& ! str_contains( $message, 'Creation of dynamic property MagpieRSS::$etag is deprecated' )
				&& ! str_contains( $message, 'Creation of dynamic property MagpieRSS::$last_modified is deprecated' )
				&& ! str_contains( $message, 'Undefined array key "description"' )
			) {
				return false;
			}
		}

		return true;
	}

	private static function only_known_w3cdtf_warnings( array $warnings ): bool {
		foreach ( $warnings as $warning ) {
			if ( ! str_contains( $warning['message'] ?? '', 'Undefined array key 11' ) ) {
				return false;
			}
		}

		return true;
	}

	private static function temp_dir( string $suffix ): string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-feed-parsers-' . getmypid();
		if ( ! is_dir( $base ) && ! mkdir( $base, 0777, true ) && ! is_dir( $base ) ) {
			throw new \RuntimeException( 'Could not create feed parser temp base.' );
		}

		$dir = $base . DIRECTORY_SEPARATOR . preg_replace( '/[^a-zA-Z0-9_.-]/', '-', $suffix );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create feed parser temp directory.' );
		}

		self::$temp_dirs[] = $dir;
		return $dir;
	}

	private static function cleanup_temp_dirs(): void {
		foreach ( array_reverse( self::$temp_dirs ) as $dir ) {
			self::remove_dir( $dir );
		}
		self::$temp_dirs = array();

		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-feed-parsers-' . getmypid();
		self::remove_dir( $base );
	}

	private static function remove_dir( string $dir ): void {
		$prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-feed-parsers-';
		if ( ! str_starts_with( $dir, $prefix ) || ! file_exists( $dir ) ) {
			return;
		}

		if ( ! is_dir( $dir ) ) {
			@unlink( $dir );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	private static function parse_xml( string $xml ): array {
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$parsed = simplexml_load_string( $xml );
		$errors = array_map(
			static fn ( \LibXMLError $error ): string => trim( $error->message ) . ' @' . $error->line . ':' . $error->column,
			libxml_get_errors()
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'ok'        => $parsed instanceof \SimpleXMLElement,
			'errors'    => array_slice( $errors, 0, 4 ),
			'itemCount' => $parsed instanceof \SimpleXMLElement ? count( $parsed->channel->item ) : 0,
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failures'] = array_slice( $failures, 0, 8 );
		return $ctx->result( $invariant, array() === $failures, $data );
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $details = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'details' => $details,
			);
		}
	}

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES ),
		);
	}

	private static function xml_text( string $value ): string {
		return htmlspecialchars( $value, ENT_NOQUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function xml_attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function xml_cdata( string $value ): string {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $value ) . ']]>';
	}

	private static function describe_atom_feed( \AtomFeed $feed ): array {
		return array(
			'links'      => $feed->links,
			'categories' => $feed->categories,
			'entries'    => array_map( array( self::class, 'describe_atom_entry' ), $feed->entries ),
		);
	}

	private static function describe_atom_entry( \AtomEntry $entry ): array {
		return array(
			'links'      => $entry->links,
			'categories' => $entry->categories,
		);
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
