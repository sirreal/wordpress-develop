<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes public discovery helpers: robots directives and XML sitemap plumbing.
 */
final class DiscoverySurface {
	public const NAME = 'discovery';

	private const PREVIEW_BYTES = 160;
	private const URL_CASES     = 8;

	/** @var array<string,array<string,mixed>> */
	private static array $provider_data = array();

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				self::skip(
					$ctx,
					'discovery.bootstrap-apis-available',
					'Required WordPress discovery APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();

		try {
			self::reset_runtime();
			self::install_url_filters();

			$rows[] = self::check_robots_directives( $ctx );
			$rows[] = self::check_robots_public_private_option_matrix( $ctx );
			$rows[] = self::check_robots_txt_front_controller_output( $ctx->fork( 'robots-txt-front-controller' ) );
			$rows[] = self::check_favicon_front_controller_redirect( $ctx->fork( 'favicon-front-controller' ) );
			$rows[] = self::check_sitemap_registry_and_urls( $ctx );
			$rows[] = self::check_sitemap_enablement_robots_and_provider_filters( $ctx );
			$rows[] = self::check_sitemap_provider_url_modes( $ctx );
			$rows[] = self::check_sitemap_renderer_xml( $ctx );
			$rows[] = self::check_sitemap_renderer_field_boundaries( $ctx );
			$rows[] = self::check_sitemap_renderer_stylesheet_filters( $ctx );
			$rows[] = self::check_sitemap_stylesheet_output( $ctx->fork( 'sitemap-stylesheet-output' ) );
			$rows[] = self::check_sitemap_max_url_filter( $ctx );
			$rows[] = self::check_sitemap_posts_provider( $ctx->fork( 'sitemap-posts-provider' ) );
			$rows[] = self::check_sitemap_taxonomies_provider( $ctx->fork( 'sitemap-taxonomies-provider' ) );
			$rows[] = self::check_sitemap_users_provider( $ctx->fork( 'sitemap-users-provider' ) );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'discovery.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_globals( $snapshot );
		}

		return $rows;
	}

	public static function filter_home_option( $pre_option, string $option = '', $default_value = false ): string {
		unset( $pre_option, $option, $default_value );
		return 'https://example.test';
	}

	public static function filter_blog_public( $pre_option, string $option = '', $default_value = false ): int {
		unset( $pre_option, $option, $default_value );
		return 1;
	}

	private static function missing_requirements(): array {
		$missing = array();

		self::load_sitemap_stylesheet_class();

		foreach ( array( 'SimpleXMLElement', 'WP_Locale', 'WP_Sitemaps', 'WP_Sitemaps_Provider', 'WP_Sitemaps_Registry', 'WP_Sitemaps_Renderer', 'WP_Sitemaps_Stylesheet' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_action',
				'add_filter',
				'apply_filters',
				'__',
				'do_action',
				'do_robots',
				'esc_attr',
				'esc_url',
				'esc_xml',
				'get_language_attributes',
				'get_option',
				'get_sitemap_url',
				'has_action',
				'has_filter',
				'is_rtl',
				'remove_action',
				'remove_filter',
				'site_url',
				'wp_get_sitemap_providers',
				'wp_register_sitemap_provider',
				'wp_robots',
				'wp_robots_max_image_preview_large',
				'wp_robots_no_robots',
				'wp_robots_noindex',
				'wp_robots_sensitive_page',
				'wp_sitemaps_get_max_urls',
				'wp_sitemaps_get_server',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_robots_directives( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = self::robots_cases( $ctx->fork( 'robots' ) );

		foreach ( $cases as $index => $case ) {
			$filter = static function () use ( $case ): array {
				return $case['directives'];
			};

			try {
				\add_filter( 'wp_robots', $filter, 10, 0 );
				ob_start();
				\wp_robots();
				$output = ob_get_clean();
			} finally {
				\remove_filter( 'wp_robots', $filter, 10 );
			}

			$expected_parts = array();
			foreach ( $case['directives'] as $directive => $value ) {
				if ( is_string( $value ) ) {
					$expected_parts[] = "{$directive}:{$value}";
				} elseif ( $value ) {
					$expected_parts[] = $directive;
				}
			}
			$expected = '' === implode( ', ', $expected_parts )
				? ''
				: "<meta name='robots' content='" . \esc_attr( implode( ', ', $expected_parts ) ) . "' />\n";

			self::collect_failure(
				$failures,
				$expected === $output
					&& false === strpos( $output, '<script' )
					&& false === strpos( $output, '"' )
					&& ( '' === $output || 1 === substr_count( $output, "<meta name='robots'" ) ),
				"wp_robots output shape case {$index}",
				array(
					'directives' => $case['directives'],
					'expected'   => self::describe_string( $expected ),
					'actual'     => self::describe_string( $output ),
				)
			);
		}

		$public_robots = \wp_robots_max_image_preview_large( array() );
		$no_robots     = \wp_robots_no_robots( array() );
		$sensitive     = \wp_robots_sensitive_page( array() );
		$noindex       = \wp_robots_noindex( array() );

		self::collect_failure(
			$failures,
			array( 'max-image-preview' => 'large' ) === $public_robots
				&& array( 'noindex' => true, 'follow' => true ) === $no_robots
				&& array( 'noindex' => true, 'noarchive' => true ) === $sensitive
				&& array() === $noindex,
			'robots helper directives match blog_public semantics',
			array(
				'maxImage'  => $public_robots,
				'noRobots'  => $no_robots,
				'sensitive' => $sensitive,
				'noindex'   => $noindex,
			)
		);

		return self::row(
			$ctx,
			'discovery.robots.directive-rendering-and-helpers',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_robots_public_private_option_matrix( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'          => 'public',
				'blogPublic'     => 1,
				'expectedNoindex' => array(),
				'expectedNoRobots' => array(
					'seed'    => true,
					'noindex' => true,
					'follow'  => true,
				),
				'expectedMaxImage' => array(
					'seed'              => true,
					'max-image-preview' => 'large',
				),
				'expectedOutput' => "<meta name='robots' content='max-image-preview:large' />\n",
			),
			array(
				'label'          => 'private',
				'blogPublic'     => 0,
				'expectedNoindex' => array(
					'noindex'  => true,
					'nofollow' => true,
				),
				'expectedNoRobots' => array(
					'seed'     => true,
					'noindex'  => true,
					'nofollow' => true,
				),
				'expectedMaxImage' => array( 'seed' => true ),
				'expectedOutput' => "<meta name='robots' content='noindex, nofollow' />\n",
			),
		);

		foreach ( $cases as $case ) {
			$blog_public_filter = static function () use ( $case ): int {
				return $case['blogPublic'];
			};
			$output             = '';

			\add_filter( 'pre_option_blog_public', $blog_public_filter, 11, 0 );
			\add_filter( 'wp_robots', 'wp_robots_noindex' );
			\add_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );
			try {
				$noindex  = \wp_robots_noindex( array() );
				$no_robots = \wp_robots_no_robots( array( 'seed' => true ) );
				$max_image = \wp_robots_max_image_preview_large( array( 'seed' => true ) );

				ob_start();
				\wp_robots();
				$output = ob_get_clean();
			} finally {
				\remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );
				\remove_filter( 'wp_robots', 'wp_robots_noindex' );
				\remove_filter( 'pre_option_blog_public', $blog_public_filter, 11 );
			}

			self::collect_failure(
				$failures,
				$case['expectedNoindex'] === $noindex
					&& $case['expectedNoRobots'] === $no_robots
					&& $case['expectedMaxImage'] === $max_image
					&& $case['expectedOutput'] === $output
					&& false === \has_filter( 'pre_option_blog_public', $blog_public_filter )
					&& false === \has_filter( 'wp_robots', 'wp_robots_noindex' )
					&& false === \has_filter( 'wp_robots', 'wp_robots_max_image_preview_large' ),
				'robots helpers and wp_robots output respect scoped blog_public option state',
				array(
					'label'            => $case['label'],
					'blogPublic'       => $case['blogPublic'],
					'noindex'          => $noindex,
					'noRobots'         => $no_robots,
					'maxImage'         => $max_image,
					'expectedOutput'   => self::describe_string( $case['expectedOutput'] ),
					'actualOutput'     => self::describe_string( $output ),
					'hasBlogFilter'    => \has_filter( 'pre_option_blog_public', $blog_public_filter ),
					'hasNoindexFilter' => \has_filter( 'wp_robots', 'wp_robots_noindex' ),
					'hasMaxImageFilter' => \has_filter( 'wp_robots', 'wp_robots_max_image_preview_large' ),
				)
			);
		}

		return self::row(
			$ctx,
			'discovery.robots.blog-public-helper-matrix',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_robots_txt_front_controller_output( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$cases    = array(
			array(
				'label'       => 'public-site-path',
				'blogPublic'  => 1,
				'siteUrl'     => 'https://example.test/site-base/wp',
				'path'        => '/site-base/wp',
				'marker'      => '# cfz-public-front-controller',
				'publicValue' => true,
			),
			array(
				'label'       => 'private-root',
				'blogPublic'  => 0,
				'siteUrl'     => 'https://example.test',
				'path'        => '',
				'marker'      => '# cfz-private-front-controller',
				'publicValue' => false,
			),
		);

		foreach ( $cases as $case ) {
			$events   = array();
			$observed = array();
			$output   = '';

			$blog_public_filter = static function () use ( $case ): int {
				return $case['blogPublic'];
			};
			$siteurl_filter     = static function () use ( $case ): string {
				return $case['siteUrl'];
			};
			$action             = static function () use ( &$events, $case ): void {
				$events[] = 'action:' . $case['label'];
			};
			$robots_filter      = static function ( string $robots, bool $public ) use ( &$events, &$observed, $case ): string {
				$events[]   = 'filter:' . $case['label'];
				$observed[] = array(
					'output' => $robots,
					'public' => $public,
				);

				return $robots . $case['marker'] . "\n";
			};

			$base_output     = "User-agent: *\n"
				. 'Disallow: ' . $case['path'] . "/wp-admin/\n"
				. 'Allow: ' . $case['path'] . "/wp-admin/admin-ajax.php\n";
			$expected_output = $base_output . $case['marker'] . "\n";
			$buffer_level    = ob_get_level();

			\add_filter( 'pre_option_blog_public', $blog_public_filter, 11, 0 );
			\add_filter( 'pre_option_siteurl', $siteurl_filter, 11, 0 );
			\add_action( 'do_robotstxt', $action, 10, 0 );
			\add_filter( 'robots_txt', $robots_filter, 10, 2 );

			try {
				ob_start();
				\do_robots();
				$output = ob_get_clean();
			} finally {
				while ( ob_get_level() > $buffer_level ) {
					ob_end_clean();
				}

				\remove_filter( 'robots_txt', $robots_filter, 10 );
				\remove_action( 'do_robotstxt', $action, 10 );
				\remove_filter( 'pre_option_siteurl', $siteurl_filter, 11 );
				\remove_filter( 'pre_option_blog_public', $blog_public_filter, 11 );
			}

			self::collect_failure(
				$failures,
				$expected_output === $output
					&& array( 'action:' . $case['label'], 'filter:' . $case['label'] ) === $events
					&& array(
						array(
							'output' => $base_output,
							'public' => $case['publicValue'],
						),
					) === $observed
					&& 1 === substr_count( $output, "User-agent: *\n" )
					&& 1 === substr_count( $output, 'Disallow: ' . $case['path'] . "/wp-admin/\n" )
					&& 1 === substr_count( $output, 'Allow: ' . $case['path'] . "/wp-admin/admin-ajax.php\n" )
					&& false === \has_filter( 'pre_option_blog_public', $blog_public_filter )
					&& false === \has_filter( 'pre_option_siteurl', $siteurl_filter )
					&& false === \has_action( 'do_robotstxt', $action )
					&& false === \has_filter( 'robots_txt', $robots_filter ),
				'do_robots front-controller output respects scoped options and hook order',
				array(
					'label'               => $case['label'],
					'expectedOutput'      => self::describe_string( $expected_output ),
					'actualOutput'        => self::describe_string( $output ),
					'events'              => $events,
					'observedFilterInput' => $observed,
					'hasBlogFilter'       => \has_filter( 'pre_option_blog_public', $blog_public_filter ),
					'hasSiteUrlFilter'    => \has_filter( 'pre_option_siteurl', $siteurl_filter ),
					'hasRobotsAction'     => \has_action( 'do_robotstxt', $action ),
					'hasRobotsFilter'     => \has_filter( 'robots_txt', $robots_filter ),
				)
			);
		}

		return self::row(
			$ctx,
			'discovery.robots.front-controller-output',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_favicon_front_controller_redirect( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::favicon_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::skip(
				$ctx,
				'discovery.favicon.front-controller-redirect',
				'Local PHP subprocess support is unavailable for do_favicon() exit-path coverage.',
				array( 'missing' => $missing )
			);
		}

		$token = substr( sha1( (string) $ctx->seed() ), 0, 10 );
		$cases = array(
			array(
				'label'        => 'fallback-site-path',
				'siteUrl'      => 'http://example.test/site-' . $token . '/wp',
				'customIcon'   => '',
				'expectedIcon' => 'http://example.test/site-' . $token . '/wp/wp-includes/images/w-logo-gray-white-bg.png',
			),
			array(
				'label'        => 'filtered-site-icon',
				'siteUrl'      => 'https://example.test',
				'customIcon'   => 'https://cdn.example.test/icons/favicon-' . $token . '.png?size=32',
				'expectedIcon' => 'https://cdn.example.test/icons/favicon-' . $token . '.png?size=32',
			),
		);

		$failures = array();
		$runs     = array();

		foreach ( $cases as $case ) {
			$run    = self::run_favicon_child( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();
			$runs[] = array(
				'label'    => $case['label'],
				'exitCode' => $run['exitCode'] ?? null,
				'ok'       => $run['ok'] ?? null,
				'result'   => $result,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
			);

			$redirects       = is_array( $result['redirects'] ?? null ) ? $result['redirects'] : array();
			$redirect_status = is_array( $result['redirectStatuses'] ?? null ) ? $result['redirectStatuses'] : array();
			$status_events   = is_array( $result['statusEvents'] ?? null ) ? $result['statusEvents'] : array();
			$site_icon_calls = is_array( $result['siteIconCalls'] ?? null ) ? $result['siteIconCalls'] : array();
			$headers         = is_array( $result['headers'] ?? null ) ? $result['headers'] : array();
			$events          = is_array( $result['events'] ?? null ) ? $result['events'] : array();
			$headers_ok      = array() === $headers || self::headers_include_location( $headers, $case['expectedIcon'] );

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? null )
					&& 0 === ( $run['exitCode'] ?? null )
					&& '' === (string) ( $run['stderr'] ?? '' )
					&& true === ( $result['started'] ?? null )
					&& true === ( $result['isFaviconBefore'] ?? null )
					&& false === ( $result['reachedAfterTemplateLoader'] ?? true )
					&& false === ( $result['returned'] ?? true )
					&& '' === (string) ( $result['preShutdownOutput'] ?? '' )
					&& array( 'template-redirect-before', 'template-redirect-after', 'do-favicon-before', 'do-faviconico', 'site-icon', 'redirect', 'redirect-status', 'status-header', 'x-redirect-by' ) === $events
					&& 1 === count( $redirects )
					&& $case['expectedIcon'] === ( $redirects[0]['location'] ?? null )
					&& 302 === ( $redirects[0]['status'] ?? null )
					&& 1 === count( $redirect_status )
					&& $case['expectedIcon'] === ( $redirect_status[0]['location'] ?? null )
					&& 302 === ( $redirect_status[0]['status'] ?? null )
					&& 1 === count( $status_events )
					&& 302 === ( $status_events[0]['code'] ?? null )
					&& 'HTTP/1.1' === ( $status_events[0]['protocol'] ?? null )
					&& 1 === count( $site_icon_calls )
					&& 32 === ( $site_icon_calls[0]['size'] ?? null )
					&& 0 === ( $site_icon_calls[0]['blogId'] ?? null )
					&& $case['expectedIcon'] === ( $site_icon_calls[0]['returnedUrl'] ?? null )
					&& str_ends_with( (string) ( $site_icon_calls[0]['fallbackUrl'] ?? '' ), '/wp-includes/images/w-logo-gray-white-bg.png' )
					&& 'WordPress' === ( $result['xRedirectBy'][0]['value'] ?? null )
					&& $headers_ok,
				'template-loader favicon branch fires default do_favicon redirect and exits without output',
				array(
					'case'             => $case,
					'run'              => $run,
					'headersOk'        => $headers_ok,
					'events'           => $events,
					'redirects'        => $redirects,
					'redirectStatuses' => $redirect_status,
					'statusEvents'     => $status_events,
					'siteIconCalls'    => $site_icon_calls,
				)
			);
		}

		return self::row(
			$ctx,
			'discovery.favicon.front-controller-redirect',
			array() === $failures,
			array(
				'cases'    => count( $cases ),
				'runs'     => $runs,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function favicon_child_missing_requirements(): array {
		$missing = array();

		foreach ( array( 'json_decode', 'json_encode', 'proc_close', 'proc_open', 'stream_get_contents' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'do_favicon', 'get_site_icon_url', 'includes_url', 'is_favicon', 'redirect_canonical', 'status_header', 'wp_redirect', 'wp_using_themes' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Query' ) ) {
			$missing[] = 'class WP_Query';
		}

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}

		return $missing;
	}

	private static function run_favicon_child( array $case ): array {
		$payload = json_encode(
			array( 'case' => $case ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( false === $payload ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'json_encode failed',
				'result'   => null,
			);
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates the favicon front-controller path, which exits.
		$process = proc_open( array( PHP_BINARY, '-r', self::favicon_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		fwrite( $pipes[0], $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		$result    = json_decode( (string) $stdout, true );

		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && true === ( $result['ok'] ?? null ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function headers_include_location( array $headers, string $location ): bool {
		foreach ( $headers as $header ) {
			if ( is_string( $header ) && 0 === stripos( $header, 'Location:' ) && trim( substr( $header, 9 ) ) === $location ) {
				return true;
			}
		}

		return false;
	}

	private static function favicon_child_program(): string {
		return <<<'PHP'
$component_fuzz_favicon_raw = stream_get_contents( STDIN );
$component_fuzz_favicon_payload = json_decode( $component_fuzz_favicon_raw, true );
$case = is_array( $component_fuzz_favicon_payload['case'] ?? null ) ? $component_fuzz_favicon_payload['case'] : array();

ini_set( 'display_errors', '0' );
ob_start();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

$result = array(
	'ok'                         => false,
	'started'                    => false,
	'returned'                   => false,
	'reachedAfterTemplateLoader' => false,
	'isFaviconBefore'            => null,
	'events'                     => array(),
	'redirects'                  => array(),
	'redirectStatuses'           => array(),
	'statusEvents'               => array(),
	'siteIconCalls'              => array(),
	'xRedirectBy'                => array(),
	'headers'                    => array(),
	'preShutdownOutput'          => '',
	'errors'                     => array(),
);

function component_fuzz_favicon_throwable( Throwable $e ): array {
	return array(
		'class'   => get_class( $e ),
		'message' => $e->getMessage(),
		'file'    => $e->getFile(),
		'line'    => $e->getLine(),
	);
}

register_shutdown_function(
	static function () use ( &$result ): void {
		$output = '';
		while ( ob_get_level() > 0 ) {
			$output .= (string) ob_get_clean();
		}

		$result['preShutdownOutput'] = $output;
		$result['headers']           = headers_list();
		$result['ok']                = true === ( $result['started'] ?? false )
			&& false === ( $result['returned'] ?? true )
			&& false === ( $result['reachedAfterTemplateLoader'] ?? true )
			&& true === ( $result['isFaviconBefore'] ?? false )
			&& array() === ( $result['errors'] ?? array() );

		echo json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	}
);

try {
	if ( array() === $case ) {
		throw new RuntimeException( 'Invalid favicon child payload.' );
	}

	$site_url    = (string) ( $case['siteUrl'] ?? 'https://example.test' );
	$custom_icon = (string) ( $case['customIcon'] ?? '' );

	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$_SERVER['HTTP_HOST']       = parse_url( $site_url, PHP_URL_HOST ) ?: 'example.test';
	$_SERVER['PHP_SELF']        = '/index.php';
	$_SERVER['REQUEST_METHOD']  = 'GET';
	$_SERVER['REQUEST_URI']     = '/favicon.ico';
	$_SERVER['PATH_INFO']       = '';
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	unset( $_SERVER['HTTPS'] );

	add_filter(
		'pre_option_home',
		static function () use ( $site_url ): string {
			return $site_url;
		},
		10,
		0
	);
	add_filter(
		'pre_option_siteurl',
		static function () use ( $site_url ): string {
			return $site_url;
		},
		10,
		0
	);
	add_filter(
		'pre_option_site_icon',
		static function (): int {
			return 0;
		},
		10,
		0
	);
	add_filter(
		'wp_using_themes',
		static function ( bool $using_themes ): bool {
			unset( $using_themes );
			return true;
		},
		10,
		1
	);
	add_action(
		'template_redirect',
		static function () use ( &$result ): void {
			$result['events'][] = 'template-redirect-before';
		},
		0,
		0
	);
	if ( function_exists( 'redirect_canonical' ) && false === has_action( 'template_redirect', 'redirect_canonical' ) ) {
		add_action( 'template_redirect', 'redirect_canonical' );
	}
	add_action(
		'template_redirect',
		static function () use ( &$result ): void {
			$result['events'][] = 'template-redirect-after';
		},
		PHP_INT_MAX,
		0
	);
	add_action(
		'do_favicon',
		static function () use ( &$result ): void {
			$result['events'][] = 'do-favicon-before';
		},
		0,
		0
	);
	if ( false === has_action( 'do_favicon', 'do_favicon' ) ) {
		add_action( 'do_favicon', 'do_favicon' );
	}
	add_action(
		'do_favicon',
		static function () use ( &$result ): void {
			$result['events'][] = 'do-favicon-after';
		},
		20,
		0
	);
	add_action(
		'do_faviconico',
		static function () use ( &$result ): void {
			$result['events'][] = 'do-faviconico';
		},
		10,
		0
	);
	add_filter(
		'get_site_icon_url',
		static function ( string $url, int $size, int $blog_id ) use ( &$result, $custom_icon ): string {
			$returned = '' === $custom_icon ? $url : $custom_icon;
			$result['events'][]        = 'site-icon';
			$result['siteIconCalls'][] = array(
				'fallbackUrl' => $url,
				'returnedUrl' => $returned,
				'size'        => $size,
				'blogId'      => $blog_id,
			);
			return $returned;
		},
		10,
		3
	);
	add_filter(
		'wp_redirect',
		static function ( string $location, int $status ) use ( &$result ): string {
			$result['events'][]    = 'redirect';
			$result['redirects'][] = array(
				'location' => $location,
				'status'   => $status,
			);
			return $location;
		},
		10,
		2
	);
	add_filter(
		'wp_redirect_status',
		static function ( int $status, string $location ) use ( &$result ): int {
			$result['events'][]             = 'redirect-status';
			$result['redirectStatuses'][]   = array(
				'location' => $location,
				'status'   => $status,
			);
			return $status;
		},
		10,
		2
	);
	add_filter(
		'status_header',
		static function ( string $status_header, int $code, string $description, string $protocol ) use ( &$result ): string {
			$result['events'][]       = 'status-header';
			$result['statusEvents'][] = array(
				'header'      => $status_header,
				'code'        => $code,
				'description' => $description,
				'protocol'    => $protocol,
			);
			return $status_header;
		},
		10,
		4
	);
	add_filter(
		'x_redirect_by',
		static function ( $x_redirect_by, int $status, string $location ) use ( &$result ) {
			$result['events'][]      = 'x-redirect-by';
			$result['xRedirectBy'][] = array(
				'value'    => is_string( $x_redirect_by ) ? $x_redirect_by : $x_redirect_by,
				'location' => $location,
				'status'   => $status,
			);
			return $x_redirect_by;
		},
		10,
		3
	);

	$GLOBALS['wp_query'] = new \WP_Query();
	$GLOBALS['wp_query']->parse_query( array( 'favicon' => 1 ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

	$result['isFaviconBefore'] = is_favicon();
	$result['started']         = true;
	require ABSPATH . WPINC . '/template-loader.php';
	$result['reachedAfterTemplateLoader'] = true;
	$result['returned']                   = true;
} catch ( Throwable $e ) {
	$result['errors'][] = component_fuzz_favicon_throwable( $e );
}
PHP;
	}

	private static function check_sitemap_registry_and_urls( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$server   = \wp_sitemaps_get_server();
		$name     = 'cfz-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 6 );
		$subtypes = array(
			'alpha' => array( 'label' => 'Alpha' ),
			'beta'  => array( 'label' => 'Beta' ),
		);
		$urls     = self::sitemap_url_cases( $ctx->fork( 'urls' ) );
		$provider = self::sitemap_provider( $name, 'component', $subtypes, $urls );

		$added     = \wp_register_sitemap_provider( $name, $provider );
		$duplicate = \wp_register_sitemap_provider( $name, self::sitemap_provider( $name, 'other', array(), array() ) );
		$found     = $server->registry->get_provider( $name );
		$missing   = $server->registry->get_provider( 'missing-' . $name );

		$url_alpha_0 = \get_sitemap_url( $name, 'alpha', 0 );
		$url_alpha_3 = \get_sitemap_url( $name, 'alpha', 3 );
		$url_bad     = \get_sitemap_url( $name, 'unknown', 1 );
		$url_missing = \get_sitemap_url( 'missing-' . $name, '', 1 );
		$index_url   = \get_sitemap_url( 'index' );

		self::collect_failure(
			$failures,
			true === $added
				&& false === $duplicate
				&& $provider === $found
				&& null === $missing
				&& is_string( $url_alpha_0 )
				&& is_string( $url_alpha_3 )
				&& str_contains( $url_alpha_0, 'sitemap=' . rawurlencode( $name ) )
				&& str_contains( $url_alpha_0, 'sitemap-subtype=alpha' )
				&& str_contains( $url_alpha_0, 'paged=1' )
				&& str_contains( $url_alpha_3, 'paged=3' )
				&& false === $url_bad
				&& false === $url_missing
				&& is_string( $index_url ),
			'sitemap provider registry and URL normalization',
			array(
				'name'       => $name,
				'added'      => $added,
				'duplicate'  => $duplicate,
				'found'      => self::describe_value( $found ),
				'urlAlpha0'  => $url_alpha_0,
				'urlAlpha3'  => $url_alpha_3,
				'urlBad'     => $url_bad,
				'urlMissing' => $url_missing,
				'indexUrl'   => $index_url,
			)
		);

		$entries = $provider->get_sitemap_entries();
		$list    = $server->index->get_sitemap_list();
		self::collect_failure(
			$failures,
			4 === count( $entries )
				&& 4 === count( $list )
				&& self::all_entries_are_sitemap_locs( $entries, $name )
				&& self::all_entries_are_sitemap_locs( $list, $name ),
			'sitemap entries expand subtype page counts into index list',
			array(
				'entries' => $entries,
				'list'    => $list,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.registry-urls-and-index-entries',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_sitemap_enablement_robots_and_provider_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$server   = \wp_sitemaps_get_server();

		$disabled_filter = static fn( bool $enabled ): bool => false;
		$enabled_filter  = static fn( bool $enabled ): bool => true;

		$default_enabled = $server->sitemaps_enabled();
		\add_filter( 'wp_sitemaps_enabled', $disabled_filter, 10, 1 );
		$disabled = $server->sitemaps_enabled();
		\remove_filter( 'wp_sitemaps_enabled', $disabled_filter, 10 );
		\add_filter( 'wp_sitemaps_enabled', $enabled_filter, 10, 1 );
		$enabled = $server->sitemaps_enabled();
		\remove_filter( 'wp_sitemaps_enabled', $enabled_filter, 10 );

		$robots_base      = "User-agent: *\n";
		$robots_public    = $server->add_robots( $robots_base, true );
		$robots_private   = $server->add_robots( $robots_base, false );
		$robots_filter    = array( $server, 'add_robots' );
		\add_filter( 'robots_txt', $robots_filter, 0, 2 );
		try {
			$filtered_robots = \apply_filters( 'robots_txt', $robots_base, true );
		} finally {
			\remove_filter( 'robots_txt', $robots_filter, 0 );
		}

		self::collect_failure(
			$failures,
			true === $default_enabled
				&& false === $disabled
				&& true === $enabled
				&& false === \has_filter( 'wp_sitemaps_enabled', $disabled_filter )
				&& false === \has_filter( 'wp_sitemaps_enabled', $enabled_filter )
				&& str_starts_with( $robots_public, $robots_base . "\nSitemap: https://example.test/" )
				&& str_ends_with( $robots_public, "\n" )
				&& $robots_base === $robots_private
				&& $robots_public === $filtered_robots
				&& false === \has_filter( 'robots_txt', $robots_filter ),
			'WP_Sitemaps enablement and robots.txt sitemap injection are filterable and removable',
			array(
				'defaultEnabled' => $default_enabled,
				'disabled'       => $disabled,
				'enabled'        => $enabled,
				'robotsPublic'   => self::describe_string( $robots_public ),
				'robotsPrivate'  => self::describe_string( $robots_private ),
			)
		);

		$registry = new \WP_Sitemaps_Registry();
		$base     = self::sitemap_provider( 'base', 'component', array(), array() );
		$rejected = self::sitemap_provider( 'rejected', 'component', array(), array() );
		$original = self::sitemap_provider( 'replaced', 'component', array(), array() );
		$swap     = self::sitemap_provider( 'replaced', 'replacement', array(), array() );
		$seen     = array();
		$filter   = static function ( $provider, string $name ) use ( &$seen, $swap ) {
			$seen[] = array(
				'name'  => $name,
				'class' => is_object( $provider ) ? get_class( $provider ) : gettype( $provider ),
			);

			if ( 'rejected' === $name ) {
				return false;
			}

			if ( 'replaced' === $name ) {
				return $swap;
			}

			return $provider;
		};

		\add_filter( 'wp_sitemaps_add_provider', $filter, 10, 2 );
		try {
			$base_added     = $registry->add_provider( 'base', $base );
			$duplicate     = $registry->add_provider( 'base', self::sitemap_provider( 'base', 'duplicate', array(), array() ) );
			$rejected_added = $registry->add_provider( 'rejected', $rejected );
			$replaced_added = $registry->add_provider( 'replaced', $original );
		} finally {
			\remove_filter( 'wp_sitemaps_add_provider', $filter, 10 );
		}

		self::collect_failure(
			$failures,
			true === $base_added
				&& false === $duplicate
				&& false === $rejected_added
				&& true === $replaced_added
				&& $base === $registry->get_provider( 'base' )
				&& null === $registry->get_provider( 'rejected' )
				&& $swap === $registry->get_provider( 'replaced' )
				&& array( 'base', 'rejected', 'replaced' ) === array_column( $seen, 'name' )
				&& false === \has_filter( 'wp_sitemaps_add_provider', $filter ),
			'sitemap registry add-provider filter can reject or replace providers without running for duplicates',
			array(
				'baseAdded'     => $base_added,
				'duplicate'     => $duplicate,
				'rejectedAdded' => $rejected_added,
				'replacedAdded' => $replaced_added,
				'seen'          => $seen,
				'providers'     => array_keys( $registry->get_providers() ),
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.enablement-robots-provider-filters',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_sitemap_provider_url_modes( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_rewrite;

		$failures = array();
		$name     = 'cfzurl' . $ctx->int( 10, 99 );
		$provider = self::sitemap_provider(
			$name,
			'component',
			array(
				'alpha_subtype' => array( 'label' => 'Alpha' ),
			),
			array()
		);

		$wp_rewrite = new \WP_Rewrite();
		$query_url  = $provider->get_sitemap_url( 'alpha_subtype', 2 );
		$wp_rewrite->permalink_structure = '/%postname%/';
		$permalink_url = $provider->get_sitemap_url( 'alpha_subtype', 2 );
		$root_url      = $provider->get_sitemap_url( '', 1 );
		$type_data     = $provider->get_sitemap_type_data();

		self::collect_failure(
			$failures,
			is_string( $query_url )
				&& str_contains( $query_url, '?sitemap=' )
				&& str_contains( $query_url, 'sitemap-subtype=alpha_subtype' )
				&& str_contains( $query_url, 'paged=2' )
				&& is_string( $permalink_url )
				&& str_ends_with( $permalink_url, '/wp-sitemap-' . $name . '-alpha_subtype-2.xml' )
				&& is_string( $root_url )
				&& str_ends_with( $root_url, '/wp-sitemap-' . $name . '-1.xml' )
				&& array( array( 'name' => 'alpha_subtype', 'pages' => 1 ) ) === $type_data,
			'WP_Sitemaps_Provider URL generation switches between query and permalink modes',
			array(
				'queryUrl'     => $query_url,
				'permalinkUrl' => $permalink_url,
				'rootUrl'      => $root_url,
				'typeData'     => $type_data,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.provider-url-modes',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_sitemap_renderer_xml( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$renderer = new \WP_Sitemaps_Renderer();
		$urls     = self::sitemap_url_cases( $ctx->fork( 'renderer' ) );
		$xml      = $renderer->get_sitemap_xml( $urls );
		$index    = $renderer->get_sitemap_index_xml(
			array(
				array(
					'loc'     => 'https://example.test/wp-sitemap-posts-post-1.xml?x=<unsafe>&ok=1',
					'lastmod' => '2026-06-21T12:00:00+00:00',
				),
			)
		);

		$parsed_xml   = is_string( $xml ) ? @simplexml_load_string( $xml ) : false;
		$parsed_index = is_string( $index ) ? @simplexml_load_string( $index ) : false;

		self::collect_failure(
			$failures,
			is_string( $xml )
				&& false !== $parsed_xml
				&& count( $parsed_xml->url ) === count( $urls )
				&& ! str_contains( $xml, '<unsafe>' )
				&& ! str_contains( $xml, '<script' )
				&& substr_count( $xml, '<loc>' ) === count( $urls ),
			'sitemap renderer emits well-formed escaped urlset XML',
			array(
				'xml'       => self::describe_string( is_string( $xml ) ? $xml : '' ),
				'urlCount'  => count( $urls ),
				'parsedXml' => false !== $parsed_xml,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $index )
				&& false !== $parsed_index
				&& 1 === count( $parsed_index->sitemap )
				&& ! str_contains( $index, '<unsafe>' )
				&& str_contains( $index, 'wp-sitemap-posts-post-1.xml' ),
			'sitemap renderer emits well-formed escaped index XML',
			array(
				'index'       => self::describe_string( is_string( $index ) ? $index : '' ),
				'parsedIndex' => false !== $parsed_index,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.renderer-escaped-xml',
			array() === $failures,
			array(
				'urlCount' => count( $urls ),
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_sitemap_renderer_field_boundaries( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures          = array();
		$renderer          = new \WP_Sitemaps_Renderer();
		$unsupported_url   = 'unsupported-url-' . self::field_token( $ctx->fork( 'unsupported-url' ) ) . '<script>alert(1)</script>';
		$unsupported_index = 'unsupported-index-' . self::field_token( $ctx->fork( 'unsupported-index' ) ) . '<script>alert(2)</script>';
		$lastmod           = '2026-06-' . sprintf( '%02d', 1 + $ctx->int( 0, 20 ) ) . 'T12:34:56+00:00';
		$url_xml           = $renderer->get_sitemap_xml(
			array(
				array(
					'loc'             => 'https://example.test/render-field-boundary?unsafe=<tag>&quote="',
					'lastmod'         => $lastmod,
					'changefreq'      => 'daily',
					'priority'        => '0.' . $ctx->int( 1, 9 ),
					'component:fuzz'  => $unsupported_url,
					'image:image'     => array( 'loc' => $unsupported_url ),
				),
			)
		);
		$index_xml         = $renderer->get_sitemap_index_xml(
			array(
				array(
					'loc'           => 'https://example.test/wp-sitemap-posts-post-1.xml?unsafe=<tag>&quote="',
					'lastmod'       => $lastmod,
					'changefreq'    => 'daily',
					'componentFuzz' => $unsupported_index,
				),
			)
		);

		$parsed_url   = is_string( $url_xml ) ? @simplexml_load_string( $url_xml ) : false;
		$parsed_index = is_string( $index_xml ) ? @simplexml_load_string( $index_xml ) : false;

		self::collect_failure(
			$failures,
			is_string( $url_xml )
				&& false !== $parsed_url
				&& 1 === count( $parsed_url->url )
				&& 1 === substr_count( $url_xml, '<loc>' )
				&& 1 === substr_count( $url_xml, '<lastmod>' )
				&& 1 === substr_count( $url_xml, '<changefreq>' )
				&& 1 === substr_count( $url_xml, '<priority>' )
				&& ! str_contains( $url_xml, 'component:fuzz' )
				&& ! str_contains( $url_xml, 'image:image' )
				&& ! str_contains( $url_xml, 'unsupported-url-' )
				&& ! str_contains( $url_xml, '<tag>' )
				&& ! str_contains( $url_xml, '<script' ),
			'sitemap renderer keeps supported URL fields and drops unsupported extension fields',
			array(
				'xml'         => self::describe_string( is_string( $url_xml ) ? $url_xml : '' ),
				'unsupported' => self::describe_string( $unsupported_url ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $index_xml )
				&& false !== $parsed_index
				&& 1 === count( $parsed_index->sitemap )
				&& 1 === substr_count( $index_xml, '<loc>' )
				&& 1 === substr_count( $index_xml, '<lastmod>' )
				&& ! str_contains( $index_xml, '<changefreq>' )
				&& ! str_contains( $index_xml, 'componentFuzz' )
				&& ! str_contains( $index_xml, 'unsupported-index-' )
				&& ! str_contains( $index_xml, '<tag>' )
				&& ! str_contains( $index_xml, '<script' ),
			'sitemap index renderer keeps index fields and drops unsupported sitemap-only fields',
			array(
				'xml'         => self::describe_string( is_string( $index_xml ) ? $index_xml : '' ),
				'unsupported' => self::describe_string( $unsupported_index ),
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.renderer-field-boundaries',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_sitemap_renderer_stylesheet_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures           = array();
		$token              = substr( hash( 'sha1', self::NAME . ':stylesheet:' . $ctx->seed() ), 0, 12 );
		$sitemap_style_base = 'https://example.test/styles/sitemap-' . $token . '.xsl';
		$index_style_base   = 'https://example.test/styles/index-' . $token . '.xsl';
		$sitemap_style_url  = $sitemap_style_base . '?unsafe=<tag>&quote="&q=' . rawurlencode( $ctx->text( 0, 20 ) );
		$index_style_url    = $index_style_base . '?unsafe=<tag>&quote="&q=' . rawurlencode( $ctx->fork( 'index-style' )->text( 0, 20 ) );
		$seen               = array();

		$sitemap_filter = static function ( string $stylesheet_url ) use ( $sitemap_style_url, &$seen ): string {
			$seen[] = array(
				'hook' => 'sitemap',
				'url'  => $stylesheet_url,
			);

			return $sitemap_style_url;
		};

		$index_filter = static function ( string $stylesheet_url ) use ( $index_style_url, &$seen ): string {
			$seen[] = array(
				'hook' => 'index',
				'url'  => $stylesheet_url,
			);

			return $index_style_url;
		};

		\remove_filter( 'wp_sitemaps_stylesheet_url', '__return_false', 0 );
		\remove_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false', 0 );

		try {
			\add_filter( 'wp_sitemaps_stylesheet_url', $sitemap_filter, 10, 1 );
			\add_filter( 'wp_sitemaps_stylesheet_index_url', $index_filter, 10, 1 );

			$renderer = new \WP_Sitemaps_Renderer();
			$xml      = $renderer->get_sitemap_xml(
				array(
					array(
						'loc'     => 'https://example.test/content/style?unsafe=<tag>&ok=1',
						'lastmod' => '2026-06-24T00:00:00+00:00',
					),
				)
			);
			$index    = $renderer->get_sitemap_index_xml(
				array(
					array(
						'loc'     => 'https://example.test/wp-sitemap-posts-post-1.xml',
						'lastmod' => '2026-06-24T00:00:00+00:00',
					),
				)
			);
		} finally {
			\remove_filter( 'wp_sitemaps_stylesheet_url', $sitemap_filter, 10 );
			\remove_filter( 'wp_sitemaps_stylesheet_index_url', $index_filter, 10 );
			\add_filter( 'wp_sitemaps_stylesheet_url', '__return_false', 0 );
			\add_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false', 0 );
		}

		$parsed_xml   = is_string( $xml ) ? @simplexml_load_string( $xml ) : false;
		$parsed_index = is_string( $index ) ? @simplexml_load_string( $index ) : false;

		self::collect_failure(
			$failures,
			array( 'sitemap', 'index' ) === array_column( $seen, 'hook' )
				&& is_string( $xml )
				&& false !== $parsed_xml
				&& 1 === substr_count( $xml, '<?xml-stylesheet' )
				&& str_contains( $xml, $sitemap_style_base )
				&& ! str_contains( $xml, $index_style_base )
				&& ! str_contains( $xml, '<tag>' )
				&& ! str_contains( $xml, 'quote="' ),
			'sitemap renderer includes filtered stylesheet processing instruction with escaped URL',
			array(
				'seen'      => $seen,
				'styleBase' => $sitemap_style_base,
				'xml'       => self::describe_string( is_string( $xml ) ? $xml : '' ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $index )
				&& false !== $parsed_index
				&& 1 === substr_count( $index, '<?xml-stylesheet' )
				&& str_contains( $index, $index_style_base )
				&& ! str_contains( $index, $sitemap_style_base )
				&& ! str_contains( $index, '<tag>' )
				&& ! str_contains( $index, 'quote="' )
				&& false === \has_filter( 'wp_sitemaps_stylesheet_url', $sitemap_filter )
				&& false === \has_filter( 'wp_sitemaps_stylesheet_index_url', $index_filter )
				&& false !== \has_filter( 'wp_sitemaps_stylesheet_url', '__return_false' )
				&& false !== \has_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false' ),
			'sitemap index renderer includes its own filtered stylesheet and restores no-stylesheet filters',
			array(
				'styleBase'      => $index_style_base,
				'index'          => self::describe_string( is_string( $index ) ? $index : '' ),
				'sitemapFilter'  => \has_filter( 'wp_sitemaps_stylesheet_url', '__return_false' ),
				'indexFilter'    => \has_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false' ),
				'customSitemap'  => \has_filter( 'wp_sitemaps_stylesheet_url', $sitemap_filter ),
				'customIndex'    => \has_filter( 'wp_sitemaps_stylesheet_index_url', $index_filter ),
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.renderer-stylesheet-filters',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_sitemap_stylesheet_output( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures            = array();
		$token               = substr( hash( 'sha1', self::NAME . ':stylesheet-output:' . $ctx->seed() ), 0, 12 );
		$css_marker          = 'cfz-css-' . $token;
		$sitemap_marker      = 'cfz-sitemap-xsl-' . $token;
		$index_marker        = 'cfz-index-xsl-' . $token;
		$css_seen            = array();
		$content_seen        = array();
		$had_wp_locale       = array_key_exists( 'wp_locale', $GLOBALS );
		$previous_wp_locale  = $GLOBALS['wp_locale'] ?? null;
		$stylesheet          = new \WP_Sitemaps_Stylesheet();

		$css_filter = static function ( string $css ) use ( &$css_seen, $css_marker ): string {
			$css_seen[] = array(
				'bytes'      => strlen( $css ),
				'alignLeft'  => str_contains( $css, 'text-align: left' ),
				'alignRight' => str_contains( $css, 'text-align: right' ),
			);

			return $css . "\n					/* {$css_marker} */\n					#sitemap__table .{$css_marker} { text-align: inherit; }\n";
		};

		$sitemap_content_filter = static function ( string $xsl ) use ( &$content_seen, $sitemap_marker ): string {
			$content_seen[] = array(
				'hook'        => 'sitemap',
				'hasUrlset'   => str_contains( $xsl, 'sitemap:urlset/sitemap:url' ),
				'hasIndexSet' => str_contains( $xsl, 'sitemap:sitemapindex/sitemap:sitemap' ),
			);

			return str_replace( '</xsl:stylesheet>', "\n<!-- {$sitemap_marker} -->\n</xsl:stylesheet>", $xsl );
		};

		$index_content_filter = static function ( string $xsl ) use ( &$content_seen, $index_marker ): string {
			$content_seen[] = array(
				'hook'        => 'index',
				'hasUrlset'   => str_contains( $xsl, 'sitemap:urlset/sitemap:url' ),
				'hasIndexSet' => str_contains( $xsl, 'sitemap:sitemapindex/sitemap:sitemap' ),
			);

			return str_replace( '</xsl:stylesheet>', "\n<!-- {$index_marker} -->\n</xsl:stylesheet>", $xsl );
		};

		try {
			\add_filter( 'wp_sitemaps_stylesheet_css', $css_filter, 10, 1 );
			\add_filter( 'wp_sitemaps_stylesheet_content', $sitemap_content_filter, 10, 1 );
			\add_filter( 'wp_sitemaps_stylesheet_index_content', $index_content_filter, 10, 1 );

			$GLOBALS['wp_locale'] = new \WP_Locale();
			$GLOBALS['wp_locale']->text_direction = 'ltr';
			$ltr_css     = $stylesheet->get_stylesheet_css();
			$sitemap_xsl = $stylesheet->get_sitemap_stylesheet();
			$index_xsl   = $stylesheet->get_sitemap_index_stylesheet();

			$GLOBALS['wp_locale']->text_direction = 'rtl';
			$rtl_css = $stylesheet->get_stylesheet_css();
		} finally {
			\remove_filter( 'wp_sitemaps_stylesheet_index_content', $index_content_filter, 10 );
			\remove_filter( 'wp_sitemaps_stylesheet_content', $sitemap_content_filter, 10 );
			\remove_filter( 'wp_sitemaps_stylesheet_css', $css_filter, 10 );

			if ( $had_wp_locale ) {
				$GLOBALS['wp_locale'] = $previous_wp_locale;
			} else {
				unset( $GLOBALS['wp_locale'] );
			}
		}

		$parsed_sitemap = is_string( $sitemap_xsl ) ? @simplexml_load_string( $sitemap_xsl ) : false;
		$parsed_index   = is_string( $index_xsl ) ? @simplexml_load_string( $index_xsl ) : false;

		self::collect_failure(
			$failures,
			is_string( $ltr_css )
				&& is_string( $rtl_css )
				&& str_contains( $ltr_css, 'text-align: left' )
				&& ! str_contains( $ltr_css, 'text-align: right' )
				&& str_contains( $rtl_css, 'text-align: right' )
				&& ! str_contains( $rtl_css, 'text-align: left' )
				&& str_contains( $ltr_css, $css_marker )
				&& str_contains( $rtl_css, $css_marker )
				&& 4 === count( $css_seen )
				&& array( true, true, true, false ) === array_column( $css_seen, 'alignLeft' )
				&& array( false, false, false, true ) === array_column( $css_seen, 'alignRight' ),
			'WP_Sitemaps_Stylesheet CSS honors LTR/RTL text alignment and scoped CSS filters',
			array(
				'cssSeen' => $css_seen,
				'ltrCss'  => self::describe_string( is_string( $ltr_css ) ? $ltr_css : '' ),
				'rtlCss'  => self::describe_string( is_string( $rtl_css ) ? $rtl_css : '' ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $sitemap_xsl )
				&& false !== $parsed_sitemap
				&& str_starts_with( $sitemap_xsl, '<?xml version="1.0" encoding="UTF-8"?>' )
				&& str_contains( $sitemap_xsl, 'sitemap:urlset/sitemap:url' )
				&& str_contains( $sitemap_xsl, 'count( sitemap:urlset/sitemap:url )' )
				&& str_contains( $sitemap_xsl, 'name="has-changefreq"' )
				&& str_contains( $sitemap_xsl, 'name="has-priority"' )
				&& str_contains( $sitemap_xsl, 'class="changefreq"' )
				&& str_contains( $sitemap_xsl, 'class="priority"' )
				&& str_contains( $sitemap_xsl, $css_marker )
				&& str_contains( $sitemap_xsl, $sitemap_marker )
				&& ! str_contains( $sitemap_xsl, $index_marker )
				&& 1 === substr_count( $sitemap_xsl, '<xsl:for-each' ),
			'WP_Sitemaps_Stylesheet sitemap XSL preserves URL table columns, count expression, CSS, and content filters',
			array(
				'contentSeen' => $content_seen,
				'sitemapXsl'  => self::describe_string( is_string( $sitemap_xsl ) ? $sitemap_xsl : '' ),
			)
		);

		self::collect_failure(
			$failures,
			is_string( $index_xsl )
				&& false !== $parsed_index
				&& str_starts_with( $index_xsl, '<?xml version="1.0" encoding="UTF-8"?>' )
				&& str_contains( $index_xsl, 'sitemap:sitemapindex/sitemap:sitemap' )
				&& str_contains( $index_xsl, 'count( sitemap:sitemapindex/sitemap:sitemap )' )
				&& str_contains( $index_xsl, 'name="has-lastmod"' )
				&& ! str_contains( $index_xsl, 'name="has-changefreq"' )
				&& ! str_contains( $index_xsl, 'name="has-priority"' )
				&& ! str_contains( $index_xsl, 'class="changefreq"' )
				&& ! str_contains( $index_xsl, 'class="priority"' )
				&& str_contains( $index_xsl, $css_marker )
				&& str_contains( $index_xsl, $index_marker )
				&& ! str_contains( $index_xsl, $sitemap_marker )
				&& 1 === substr_count( $index_xsl, '<xsl:for-each' ),
			'WP_Sitemaps_Stylesheet index XSL preserves sitemap-index columns without sitemap-only fields',
			array(
				'contentSeen' => $content_seen,
				'indexXsl'    => self::describe_string( is_string( $index_xsl ) ? $index_xsl : '' ),
			)
		);

		self::collect_failure(
			$failures,
			array(
				array(
					'hook'        => 'sitemap',
					'hasUrlset'   => true,
					'hasIndexSet' => false,
				),
				array(
					'hook'        => 'index',
					'hasUrlset'   => false,
					'hasIndexSet' => true,
				),
			) === $content_seen
				&& false === \has_filter( 'wp_sitemaps_stylesheet_css', $css_filter )
				&& false === \has_filter( 'wp_sitemaps_stylesheet_content', $sitemap_content_filter )
				&& false === \has_filter( 'wp_sitemaps_stylesheet_index_content', $index_content_filter )
				&& ( $had_wp_locale ? ( $GLOBALS['wp_locale'] ?? null ) === $previous_wp_locale : ! array_key_exists( 'wp_locale', $GLOBALS ) ),
			'WP_Sitemaps_Stylesheet content filters are type-specific and temporary locale/filter globals are restored',
			array(
				'contentSeen'       => $content_seen,
				'cssFilter'         => \has_filter( 'wp_sitemaps_stylesheet_css', $css_filter ),
				'sitemapFilter'     => \has_filter( 'wp_sitemaps_stylesheet_content', $sitemap_content_filter ),
				'indexFilter'       => \has_filter( 'wp_sitemaps_stylesheet_index_content', $index_content_filter ),
				'wpLocaleRestored'  => $had_wp_locale ? ( ( $GLOBALS['wp_locale'] ?? null ) === $previous_wp_locale ) : ! array_key_exists( 'wp_locale', $GLOBALS ),
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.stylesheet-output',
			array() === $failures,
			array(
				'failures' => array_slice( $failures, 0, 8 ),
			)
		);
	}

	private static function check_sitemap_max_url_filter( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$limit    = 10 + $ctx->int( 0, 50 );
		$seen     = array();
		$filter   = static function ( int $max_urls, string $object_type ) use ( $limit, &$seen ): int {
			$seen[] = array(
				'max'  => $max_urls,
				'type' => $object_type,
			);

			return 'component' === $object_type ? $limit : $max_urls;
		};

		try {
			\add_filter( 'wp_sitemaps_max_urls', $filter, 10, 2 );
			$component = \wp_sitemaps_get_max_urls( 'component' );
			$post      = \wp_sitemaps_get_max_urls( 'post' );
		} finally {
			\remove_filter( 'wp_sitemaps_max_urls', $filter, 10 );
		}

		$after = \wp_sitemaps_get_max_urls( 'component' );
		self::collect_failure(
			$failures,
			$limit === $component
				&& 2000 === $post
				&& 2000 === $after
				&& 2 === count( $seen )
				&& 'component' === $seen[0]['type']
				&& 'post' === $seen[1]['type'],
			'wp_sitemaps_get_max_urls filter is scoped by object type and removable',
			array(
				'limit'     => $limit,
				'component' => $component,
				'post'      => $post,
				'after'     => $after,
				'seen'      => $seen,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.max-url-filter-locality',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_sitemap_posts_provider( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::sitemap_provider_fixture_missing_requirements();
		if ( array() !== $missing ) {
			return self::skip(
				$ctx,
				'discovery.sitemaps.posts-provider-fixtures',
				'Built-in sitemap provider fixture APIs are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$failures     = array();
		$case         = self::prepare_sitemap_provider_runtime( $ctx->fork( 'posts-runtime' ) );
		$provider     = new \WP_Sitemaps_Posts();
		$limit        = 2 + $ctx->int( 0, 1 );
		$private_type = $case['postTypePrivate'];
		$public_type  = $case['postTypePublic'];
		$published    = array();

		\register_post_type(
			$public_type,
			array(
				'public'      => true,
				'label'       => 'Discovery Public',
				'has_archive' => true,
				'rewrite'     => false,
				'supports'    => array( 'title', 'editor', 'author' ),
			)
		);

		\register_post_type(
			$private_type,
			array(
				'public'      => false,
				'label'       => 'Discovery Private',
				'has_archive' => false,
				'rewrite'     => false,
				'supports'    => array( 'title', 'editor', 'author' ),
			)
		);

		for ( $i = 0; $i < ( $limit * 2 ) + 1; ++$i ) {
			$day          = 1 + $i;
			$modified_gmt = sprintf( '2026-06-%02d %02d:00:00', $day, 8 + ( $i % 10 ) );
			$modified     = sprintf( '2026-06-%02d %02d:30:00', $day, 11 + ( $i % 10 ) );
			$published[] = array(
				'id'           => self::insert_sitemap_post(
					array(
						'post_type'         => 'post',
						'post_status'       => 'publish',
						'post_title'        => 'Discovery sitemap post ' . $i,
						'post_name'         => 'discovery-sitemap-post-' . $case['token'] . '-' . $i,
						'post_date'         => $modified,
						'post_date_gmt'     => $modified_gmt,
						'post_modified'     => $modified,
						'post_modified_gmt' => $modified_gmt,
					)
				),
				'modified_gmt' => $modified_gmt,
			);
		}

		$custom_published = array();
		for ( $i = 0; $i < $limit + 1; ++$i ) {
			$day                = 10 + $i;
			$custom_gmt         = sprintf( '2026-06-%02d %02d:15:00', $day, 7 + ( $i % 10 ) );
			$custom_modified    = sprintf( '2026-06-%02d %02d:45:00', $day, 10 + ( $i % 10 ) );
			$custom_published[] = array(
				'id'           => self::insert_sitemap_post(
					array(
						'post_type'         => $public_type,
						'post_status'       => 'publish',
						'post_title'        => 'Discovery custom sitemap post ' . $i,
						'post_name'         => 'discovery-custom-sitemap-post-' . $case['token'] . '-' . $i,
						'post_date'         => $custom_modified,
						'post_date_gmt'     => $custom_gmt,
						'post_modified'     => $custom_modified,
						'post_modified_gmt' => $custom_gmt,
					)
				),
				'modified_gmt' => $custom_gmt,
			);
		}

		$page_gmt      = '2026-06-18 06:00:00';
		$page_modified = '2026-06-18 09:45:00';
		$page_fixture  = array(
			'id'           => self::insert_sitemap_post(
				array(
					'post_type'         => 'page',
					'post_status'       => 'publish',
					'post_title'        => 'Discovery sitemap page',
					'post_name'         => 'discovery-sitemap-page-' . $case['token'],
					'post_date'         => $page_modified,
					'post_date_gmt'     => $page_gmt,
					'post_modified'     => $page_modified,
					'post_modified_gmt' => $page_gmt,
				)
			),
			'modified_gmt' => $page_gmt,
		);

		$draft_id = self::insert_sitemap_post(
			array(
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Discovery draft sitemap post',
				'post_name'         => 'discovery-draft-' . $case['token'],
				'post_modified_gmt' => '2026-06-20 10:00:00',
			)
		);
		$private_id = self::insert_sitemap_post(
			array(
				'post_type'         => 'post',
				'post_status'       => 'private',
				'post_title'        => 'Discovery private sitemap post',
				'post_name'         => 'discovery-private-' . $case['token'],
				'post_modified_gmt' => '2026-06-21 10:00:00',
			)
		);
		$unsupported_private_type_id = self::insert_sitemap_post(
			array(
				'post_type'         => $private_type,
				'post_status'       => 'publish',
				'post_title'        => 'Discovery unsupported post type sitemap post',
				'post_name'         => 'discovery-unsupported-' . $case['token'],
				'post_modified_gmt' => '2026-06-22 10:00:00',
			)
		);
		$custom_draft_id = self::insert_sitemap_post(
			array(
				'post_type'         => $public_type,
				'post_status'       => 'draft',
				'post_title'        => 'Discovery custom draft sitemap post',
				'post_name'         => 'discovery-custom-draft-' . $case['token'],
				'post_modified_gmt' => '2026-07-20 10:00:00',
			)
		);

		$subtypes = $provider->get_object_subtypes();
		self::collect_failure(
			$failures,
			isset( $subtypes['post'] )
				&& isset( $subtypes['page'] )
				&& isset( $subtypes[ $public_type ] )
				&& ! isset( $subtypes['attachment'] )
				&& ! isset( $subtypes[ $private_type ] ),
			'WP_Sitemaps_Posts exposes viewable public post subtypes and excludes attachments/private types',
			array(
				'subtypes'    => array_keys( $subtypes ),
				'publicType'  => $public_type,
				'privateType' => $private_type,
			)
		);

		$query_seen = array();
		$entry_seen = array();
		$max_filter = static function ( int $max_urls, string $object_type ) use ( $limit ): int {
			return 'post' === $object_type ? $limit : $max_urls;
		};
		$query_filter = static function ( array $args, string $post_type ) use ( &$query_seen ): array {
			$query_seen[] = array(
				'postType'      => $post_type,
				'status'        => $args['post_status'] ?? null,
				'postsPerPage'  => $args['posts_per_page'] ?? null,
				'noFoundRows'   => $args['no_found_rows'] ?? null,
				'ignoreSticky'  => $args['ignore_sticky_posts'] ?? null,
			);

			return $args;
		};
		$entry_filter = static function ( array $entry, \WP_Post $post, string $post_type ) use ( &$entry_seen ): array {
			$entry_seen[] = array(
				'id'      => (int) $post->ID,
				'type'    => $post_type,
				'loc'     => $entry['loc'] ?? null,
				'lastmod' => $entry['lastmod'] ?? null,
			);

			$entry['component-fuzz-id'] = (string) $post->ID;
			return $entry;
		};

		\add_filter( 'wp_sitemaps_max_urls', $max_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_posts_query_args', $query_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_posts_entry', $entry_filter, 10, 3 );
		try {
			$max_pages        = $provider->get_max_num_pages( 'post' );
			$page_entries     = array();
			for ( $page = 1; $page <= $max_pages; ++$page ) {
				$page_entries[ $page ] = $provider->get_url_list( $page, 'post' );
			}
			$unsupported_list = $provider->get_url_list( 1, $private_type );
			$attachment_list  = $provider->get_url_list( 1, 'attachment' );
		} finally {
			\remove_filter( 'wp_sitemaps_posts_entry', $entry_filter, 10 );
			\remove_filter( 'wp_sitemaps_posts_query_args', $query_filter, 10 );
			\remove_filter( 'wp_sitemaps_max_urls', $max_filter, 10 );
		}

		$all_entries = array_merge( ...array_values( $page_entries ) );
		self::collect_failure(
			$failures,
			3 === $max_pages
				&& $limit === count( $page_entries[1] ?? array() )
				&& $limit === count( $page_entries[2] ?? array() )
				&& 1 === count( $page_entries[3] ?? array() )
				&& count( $published ) === count( $all_entries )
				&& self::sitemap_post_entries_match_fixtures( $all_entries, $published )
				&& ! self::sitemap_entries_contain_locs_for_ids( $all_entries, array( $draft_id, $private_id, $unsupported_private_type_id ) )
				&& array() === $unsupported_list
				&& array() === $attachment_list
				&& self::sitemap_post_query_args_local_to_type( $query_seen, 'post', $limit )
				&& array_column( $entry_seen, 'id' ) === array_column( $published, 'id' )
				&& false === \has_filter( 'wp_sitemaps_posts_entry', $entry_filter )
				&& false === \has_filter( 'wp_sitemaps_posts_query_args', $query_filter )
				&& false === \has_filter( 'wp_sitemaps_max_urls', $max_filter ),
			'WP_Sitemaps_Posts lists only published supported subtype entries with lastmod and max-page math',
			array(
				'limit'           => $limit,
				'maxPages'        => $max_pages,
				'pageCounts'      => array_map( 'count', $page_entries ),
				'entrySeen'       => $entry_seen,
				'querySeen'       => $query_seen,
				'unsupportedList' => $unsupported_list,
				'attachmentList'  => $attachment_list,
			)
		);

		$custom_query_seen = array();
		$custom_entry_seen = array();
		$custom_max_filter = static function ( int $max_urls, string $object_type ) use ( $limit ): int {
			return 'post' === $object_type ? $limit : $max_urls;
		};
		$custom_query_filter = static function ( array $args, string $post_type ) use ( &$custom_query_seen ): array {
			$custom_query_seen[] = array(
				'postType'      => $post_type,
				'status'        => $args['post_status'] ?? null,
				'postsPerPage'  => $args['posts_per_page'] ?? null,
				'noFoundRows'   => $args['no_found_rows'] ?? null,
				'ignoreSticky'  => $args['ignore_sticky_posts'] ?? null,
			);

			return $args;
		};
		$custom_entry_filter = static function ( array $entry, \WP_Post $post, string $post_type ) use ( &$custom_entry_seen ): array {
			$custom_entry_seen[] = array(
				'id'      => (int) $post->ID,
				'type'    => $post_type,
				'loc'     => $entry['loc'] ?? null,
				'lastmod' => $entry['lastmod'] ?? null,
			);

			$entry['component-fuzz-id'] = (string) $post->ID;
			return $entry;
		};

		\add_filter( 'wp_sitemaps_max_urls', $custom_max_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_posts_query_args', $custom_query_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_posts_entry', $custom_entry_filter, 10, 3 );
		try {
			$custom_max_pages    = $provider->get_max_num_pages( $public_type );
			$custom_page_entries = array();
			for ( $page = 1; $page <= $custom_max_pages; ++$page ) {
				$custom_page_entries[ $page ] = $provider->get_url_list( $page, $public_type );
			}
		} finally {
			\remove_filter( 'wp_sitemaps_posts_entry', $custom_entry_filter, 10 );
			\remove_filter( 'wp_sitemaps_posts_query_args', $custom_query_filter, 10 );
			\remove_filter( 'wp_sitemaps_max_urls', $custom_max_filter, 10 );
		}

		$custom_entries = array_merge( ...array_values( $custom_page_entries ) );
		self::collect_failure(
			$failures,
			2 === $custom_max_pages
				&& $limit === count( $custom_page_entries[1] ?? array() )
				&& 1 === count( $custom_page_entries[2] ?? array() )
				&& count( $custom_published ) === count( $custom_entries )
				&& self::sitemap_post_entries_match_fixtures( $custom_entries, $custom_published )
				&& ! self::sitemap_entries_contain_locs_for_ids( $custom_entries, array( $custom_draft_id ) )
				&& self::sitemap_post_query_args_local_to_type( $custom_query_seen, $public_type, $limit )
				&& array_column( $custom_entry_seen, 'id' ) === array_column( $custom_published, 'id' )
				&& false === \has_filter( 'wp_sitemaps_posts_entry', $custom_entry_filter )
				&& false === \has_filter( 'wp_sitemaps_posts_query_args', $custom_query_filter )
				&& false === \has_filter( 'wp_sitemaps_max_urls', $custom_max_filter ),
			'WP_Sitemaps_Posts includes viewable public custom post type entries with local filters and max-page math',
			array(
				'publicType'  => $public_type,
				'limit'       => $limit,
				'maxPages'    => $custom_max_pages,
				'pageCounts'  => array_map( 'count', $custom_page_entries ),
				'entrySeen'   => $custom_entry_seen,
				'querySeen'   => $custom_query_seen,
				'customDraft' => $custom_draft_id,
			)
		);

		$page_query_seen = array();
		$page_entry_seen = array();
		$home_seen       = array();
		$page_max_filter = static function ( int $max_urls, string $object_type ) use ( $limit ): int {
			return 'post' === $object_type ? $limit : $max_urls;
		};
		$page_query_filter = static function ( array $args, string $post_type ) use ( &$page_query_seen ): array {
			$page_query_seen[] = array(
				'postType'      => $post_type,
				'status'        => $args['post_status'] ?? null,
				'postsPerPage'  => $args['posts_per_page'] ?? null,
				'noFoundRows'   => $args['no_found_rows'] ?? null,
				'ignoreSticky'  => $args['ignore_sticky_posts'] ?? null,
			);

			return $args;
		};
		$page_entry_filter = static function ( array $entry, \WP_Post $post, string $post_type ) use ( &$page_entry_seen ): array {
			$page_entry_seen[] = array(
				'id'      => (int) $post->ID,
				'type'    => $post_type,
				'loc'     => $entry['loc'] ?? null,
				'lastmod' => $entry['lastmod'] ?? null,
			);

			$entry['component-fuzz-id'] = (string) $post->ID;
			return $entry;
		};
		$home_filter = static function ( array $entry ) use ( &$home_seen ): array {
			$home_seen[]                  = $entry;
			$entry['component-fuzz-home'] = '1';
			return $entry;
		};

		\add_filter( 'wp_sitemaps_max_urls', $page_max_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_posts_query_args', $page_query_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_posts_entry', $page_entry_filter, 10, 3 );
		\add_filter( 'wp_sitemaps_posts_show_on_front_entry', $home_filter, 10, 1 );
		try {
			$page_max_pages = $provider->get_max_num_pages( 'page' );
			$page_list      = $provider->get_url_list( 1, 'page' );
			$page_two_list  = $provider->get_url_list( 2, 'page' );
		} finally {
			\remove_filter( 'wp_sitemaps_posts_show_on_front_entry', $home_filter, 10 );
			\remove_filter( 'wp_sitemaps_posts_entry', $page_entry_filter, 10 );
			\remove_filter( 'wp_sitemaps_posts_query_args', $page_query_filter, 10 );
			\remove_filter( 'wp_sitemaps_max_urls', $page_max_filter, 10 );
		}

		$latest_post             = $published[ count( $published ) - 1 ];
		$expected_home_lastmod   = self::expected_sitemap_lastmod_from_gmt( (string) $latest_post['modified_gmt'] );
		$page_post_entries       = array_slice( $page_list, 1 );
		$page_home_entry         = $page_list[0] ?? array();
		$captured_home_entry     = $home_seen[0] ?? array();
		self::collect_failure(
			$failures,
			1 === $page_max_pages
				&& 2 === count( $page_list )
				&& array() === $page_two_list
				&& \home_url( '/' ) === ( $page_home_entry['loc'] ?? null )
				&& '1' === ( $page_home_entry['component-fuzz-home'] ?? null )
				&& $expected_home_lastmod === ( $page_home_entry['lastmod'] ?? null )
				&& \home_url( '/' ) === ( $captured_home_entry['loc'] ?? null )
				&& $expected_home_lastmod === ( $captured_home_entry['lastmod'] ?? null )
				&& self::sitemap_post_entries_match_fixtures( $page_post_entries, array( $page_fixture ) )
				&& self::sitemap_post_query_args_local_to_type( $page_query_seen, 'page', $limit )
				&& array_column( $page_entry_seen, 'id' ) === array( $page_fixture['id'] )
				&& false === \has_filter( 'wp_sitemaps_posts_show_on_front_entry', $home_filter )
				&& false === \has_filter( 'wp_sitemaps_posts_entry', $page_entry_filter )
				&& false === \has_filter( 'wp_sitemaps_posts_query_args', $page_query_filter )
				&& false === \has_filter( 'wp_sitemaps_max_urls', $page_max_filter ),
			'WP_Sitemaps_Posts page subtype includes the show-on-front home entry, page entries, and min-page math',
			array(
				'limit'                => $limit,
				'pageMaxPages'         => $page_max_pages,
				'pageList'             => $page_list,
				'pageTwoList'          => $page_two_list,
				'homeSeen'             => $home_seen,
				'expectedHomeLastmod'  => $expected_home_lastmod,
				'pageEntrySeen'        => $page_entry_seen,
				'pageQuerySeen'        => $page_query_seen,
			)
		);

		$pre_seen      = array();
		$pre_page_seen = array();
		$pre_sentinel  = array(
			array(
				'loc'     => 'https://example.test/pre-posts-' . $case['token'],
				'lastmod' => '2026-06-25T00:00:00+00:00',
			),
		);
		$pre_list_filter = static function ( $url_list, string $post_type, int $page_num ) use ( &$pre_seen, $pre_sentinel ) {
			$pre_seen[] = array(
				'type' => $post_type,
				'page' => $page_num,
				'null' => null === $url_list,
			);

			return 'post' === $post_type && 2 === $page_num ? $pre_sentinel : $url_list;
		};
		$pre_page_filter = static function ( $max_num_pages, string $post_type ) use ( &$pre_page_seen ): int {
			$pre_page_seen[] = array(
				'type' => $post_type,
				'null' => null === $max_num_pages,
			);

			return 'post' === $post_type ? 17 : (int) $max_num_pages;
		};

		\add_filter( 'wp_sitemaps_posts_pre_url_list', $pre_list_filter, 10, 3 );
		\add_filter( 'wp_sitemaps_posts_pre_max_num_pages', $pre_page_filter, 10, 2 );
		try {
			$pre_list        = $provider->get_url_list( 2, 'post' );
			$pre_unsupported = $provider->get_url_list( 2, $private_type );
			$pre_pages       = $provider->get_max_num_pages( 'post' );
		} finally {
			\remove_filter( 'wp_sitemaps_posts_pre_max_num_pages', $pre_page_filter, 10 );
			\remove_filter( 'wp_sitemaps_posts_pre_url_list', $pre_list_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$pre_sentinel === $pre_list
				&& array() === $pre_unsupported
				&& 17 === $pre_pages
				&& array( array( 'type' => 'post', 'page' => 2, 'null' => true ) ) === $pre_seen
				&& array( array( 'type' => 'post', 'null' => true ) ) === $pre_page_seen
				&& false === \has_filter( 'wp_sitemaps_posts_pre_url_list', $pre_list_filter )
				&& false === \has_filter( 'wp_sitemaps_posts_pre_max_num_pages', $pre_page_filter ),
			'WP_Sitemaps_Posts pre-list and pre-page filters short-circuit only matching subtype calls',
			array(
				'preList'        => $pre_list,
				'preUnsupported' => $pre_unsupported,
				'prePages'       => $pre_pages,
				'preSeen'        => $pre_seen,
				'prePageSeen'    => $pre_page_seen,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.posts-provider-fixtures',
			array() === $failures,
			array(
				'limit'    => $limit,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_sitemap_taxonomies_provider( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::sitemap_provider_fixture_missing_requirements();
		if ( array() !== $missing ) {
			return self::skip(
				$ctx,
				'discovery.sitemaps.taxonomies-provider-fixtures',
				'Built-in sitemap provider fixture APIs are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$failures    = array();
		$case        = self::prepare_sitemap_provider_runtime( $ctx->fork( 'taxonomies-runtime' ) );
		$provider    = new \WP_Sitemaps_Taxonomies();
		$limit       = 2 + $ctx->int( 0, 1 );
		$private_tax = $case['taxonomyPrivate'];
		$public_tax  = $case['taxonomyPublic'];
		$included    = array();
		$empty       = array();

		\register_taxonomy(
			$public_tax,
			array( 'post' ),
			array(
				'public'       => true,
				'hierarchical' => false,
				'label'        => 'Discovery Public Taxonomy',
				'rewrite'      => false,
			)
		);

		\register_taxonomy(
			$private_tax,
			array( 'post' ),
			array(
				'public'       => false,
				'hierarchical' => true,
				'label'        => 'Discovery Private Taxonomy',
				'rewrite'      => false,
			)
		);

		for ( $i = 0; $i < $limit + 1; ++$i ) {
			$included[] = self::insert_sitemap_term(
				'category',
				'Discovery Sitemap Category ' . $i,
				'discovery-sitemap-category-' . $case['token'] . '-' . $i,
				1 + $i
			);
		}

		for ( $i = 0; $i < 2; ++$i ) {
			$empty[] = self::insert_sitemap_term(
				'category',
				'Discovery Empty Category ' . $i,
				'discovery-empty-category-' . $case['token'] . '-' . $i,
				0
			);
		}

		$public_included = array();
		for ( $i = 0; $i < $limit + 1; ++$i ) {
			$public_included[] = self::insert_sitemap_term(
				$public_tax,
				'Discovery Public Taxonomy Term ' . $i,
				'discovery-public-taxonomy-term-' . $case['token'] . '-' . $i,
				2 + $i
			);
		}

		$public_empty = self::insert_sitemap_term(
			$public_tax,
			'Discovery Empty Public Taxonomy Term',
			'discovery-empty-public-taxonomy-term-' . $case['token'],
			0
		);

		$private_term = self::insert_sitemap_term(
			$private_tax,
			'Discovery Private Term',
			'discovery-private-term-' . $case['token'],
			3
		);

		$subtypes = $provider->get_object_subtypes();
		self::collect_failure(
			$failures,
			isset( $subtypes['category'] )
				&& isset( $subtypes['post_tag'] )
				&& isset( $subtypes[ $public_tax ] )
				&& ! isset( $subtypes[ $private_tax ] ),
			'WP_Sitemaps_Taxonomies exposes public taxonomies and excludes private taxonomy subtypes',
			array(
				'subtypes'   => array_keys( $subtypes ),
				'publicTax'  => $public_tax,
				'privateTax' => $private_tax,
			)
		);

		$query_seen = array();
		$entry_seen = array();
		$max_filter = static function ( int $max_urls, string $object_type ) use ( $limit ): int {
			return 'term' === $object_type ? $limit : $max_urls;
		};
		$query_filter = static function ( array $args, string $taxonomy ) use ( &$query_seen ): array {
			$query_seen[] = array(
				'taxonomy'   => $taxonomy,
				'number'     => $args['number'] ?? null,
				'hideEmpty'  => $args['hide_empty'] ?? null,
				'fields'     => $args['fields'] ?? null,
				'offset'     => $args['offset'] ?? null,
			);

			return $args;
		};
		$entry_filter = static function ( array $entry, int $term_id, string $taxonomy, \WP_Term $term ) use ( &$entry_seen ): array {
			$entry_seen[] = array(
				'id'       => $term_id,
				'taxonomy' => $taxonomy,
				'count'    => (int) $term->count,
				'loc'      => $entry['loc'] ?? null,
			);

			$entry['component-fuzz-term'] = (string) $term_id;
			return $entry;
		};

		\add_filter( 'wp_sitemaps_max_urls', $max_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_taxonomies_query_args', $query_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_taxonomies_entry', $entry_filter, 10, 4 );
		try {
			$max_pages        = $provider->get_max_num_pages( 'category' );
			$page_entries     = array();
			for ( $page = 1; $page <= $max_pages; ++$page ) {
				$page_entries[ $page ] = $provider->get_url_list( $page, 'category' );
			}
			$unsupported_list = $provider->get_url_list( 1, $private_tax );
		} finally {
			\remove_filter( 'wp_sitemaps_taxonomies_entry', $entry_filter, 10 );
			\remove_filter( 'wp_sitemaps_taxonomies_query_args', $query_filter, 10 );
			\remove_filter( 'wp_sitemaps_max_urls', $max_filter, 10 );
		}

		$all_entries = array_merge( ...array_values( $page_entries ) );
		self::collect_failure(
			$failures,
			2 === $max_pages
				&& $limit === count( $page_entries[1] ?? array() )
				&& 1 === count( $page_entries[2] ?? array() )
				&& count( $included ) === count( $all_entries )
				&& self::sitemap_term_entries_match_fixtures( $all_entries, $included, 'category' )
				&& array_column( $entry_seen, 'id' ) === array_column( $included, 'term_id' )
				&& ! array_intersect( array_column( $entry_seen, 'id' ), array_column( $empty, 'term_id' ) )
				&& ! in_array( $private_term['term_id'], array_column( $entry_seen, 'id' ), true )
				&& array() === $unsupported_list
				&& self::sitemap_taxonomy_query_args_local_to_taxonomy( $query_seen, 'category', $limit )
				&& false === \has_filter( 'wp_sitemaps_taxonomies_entry', $entry_filter )
				&& false === \has_filter( 'wp_sitemaps_taxonomies_query_args', $query_filter )
				&& false === \has_filter( 'wp_sitemaps_max_urls', $max_filter ),
			'WP_Sitemaps_Taxonomies honors public subtype gating, hide-empty terms, locs, and max-page math',
			array(
				'limit'           => $limit,
				'maxPages'        => $max_pages,
				'pageCounts'      => array_map( 'count', $page_entries ),
				'entrySeen'       => $entry_seen,
				'querySeen'       => $query_seen,
				'unsupportedList' => $unsupported_list,
				)
			);

		$public_tax_query_seen = array();
		$public_tax_entry_seen = array();
		$public_tax_max_filter = static function ( int $max_urls, string $object_type ) use ( $limit ): int {
			return 'term' === $object_type ? $limit : $max_urls;
		};
		$public_tax_query_filter = static function ( array $args, string $taxonomy ) use ( &$public_tax_query_seen ): array {
			$public_tax_query_seen[] = array(
				'taxonomy'   => $taxonomy,
				'number'     => $args['number'] ?? null,
				'hideEmpty'  => $args['hide_empty'] ?? null,
				'fields'     => $args['fields'] ?? null,
				'offset'     => $args['offset'] ?? null,
			);

			return $args;
		};
		$public_tax_entry_filter = static function ( array $entry, int $term_id, string $taxonomy, \WP_Term $term ) use ( &$public_tax_entry_seen ): array {
			$public_tax_entry_seen[] = array(
				'id'       => $term_id,
				'taxonomy' => $taxonomy,
				'count'    => (int) $term->count,
				'loc'      => $entry['loc'] ?? null,
			);

			$entry['component-fuzz-term'] = (string) $term_id;
			return $entry;
		};

		\add_filter( 'wp_sitemaps_max_urls', $public_tax_max_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_taxonomies_query_args', $public_tax_query_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_taxonomies_entry', $public_tax_entry_filter, 10, 4 );
		try {
			$public_tax_max_pages    = $provider->get_max_num_pages( $public_tax );
			$public_tax_page_entries = array();
			for ( $page = 1; $page <= $public_tax_max_pages; ++$page ) {
				$public_tax_page_entries[ $page ] = $provider->get_url_list( $page, $public_tax );
			}
		} finally {
			\remove_filter( 'wp_sitemaps_taxonomies_entry', $public_tax_entry_filter, 10 );
			\remove_filter( 'wp_sitemaps_taxonomies_query_args', $public_tax_query_filter, 10 );
			\remove_filter( 'wp_sitemaps_max_urls', $public_tax_max_filter, 10 );
		}

		$public_tax_entries = array_merge( ...array_values( $public_tax_page_entries ) );
		self::collect_failure(
			$failures,
			2 === $public_tax_max_pages
				&& $limit === count( $public_tax_page_entries[1] ?? array() )
				&& 1 === count( $public_tax_page_entries[2] ?? array() )
				&& count( $public_included ) === count( $public_tax_entries )
				&& self::sitemap_term_entries_match_fixtures( $public_tax_entries, $public_included, $public_tax )
				&& array_column( $public_tax_entry_seen, 'id' ) === array_column( $public_included, 'term_id' )
				&& ! in_array( $public_empty['term_id'], array_column( $public_tax_entry_seen, 'id' ), true )
				&& self::sitemap_taxonomy_query_args_local_to_taxonomy( $public_tax_query_seen, $public_tax, $limit )
				&& false === \has_filter( 'wp_sitemaps_taxonomies_entry', $public_tax_entry_filter )
				&& false === \has_filter( 'wp_sitemaps_taxonomies_query_args', $public_tax_query_filter )
				&& false === \has_filter( 'wp_sitemaps_max_urls', $public_tax_max_filter ),
			'WP_Sitemaps_Taxonomies includes public custom taxonomy terms with hide-empty and max-page math',
			array(
				'publicTax'   => $public_tax,
				'limit'       => $limit,
				'maxPages'    => $public_tax_max_pages,
				'pageCounts'  => array_map( 'count', $public_tax_page_entries ),
				'entrySeen'   => $public_tax_entry_seen,
				'querySeen'   => $public_tax_query_seen,
				'publicEmpty' => $public_empty,
			)
		);

		$pre_seen      = array();
		$pre_page_seen = array();
		$pre_sentinel  = array( array( 'loc' => 'https://example.test/pre-taxonomies-' . $case['token'] ) );
		$pre_list_filter = static function ( $url_list, string $taxonomy, int $page_num ) use ( &$pre_seen, $pre_sentinel ) {
			$pre_seen[] = array(
				'taxonomy' => $taxonomy,
				'page'     => $page_num,
				'null'     => null === $url_list,
			);

			return 'category' === $taxonomy && 2 === $page_num ? $pre_sentinel : $url_list;
		};
		$pre_page_filter = static function ( $max_num_pages, string $taxonomy ) use ( &$pre_page_seen ): int {
			$pre_page_seen[] = array(
				'taxonomy' => $taxonomy,
				'null'     => null === $max_num_pages,
			);

			return 'category' === $taxonomy ? 19 : (int) $max_num_pages;
		};

		\add_filter( 'wp_sitemaps_taxonomies_pre_url_list', $pre_list_filter, 10, 3 );
		\add_filter( 'wp_sitemaps_taxonomies_pre_max_num_pages', $pre_page_filter, 10, 2 );
		try {
			$pre_list        = $provider->get_url_list( 2, 'category' );
			$pre_unsupported = $provider->get_url_list( 2, $private_tax );
			$pre_pages       = $provider->get_max_num_pages( 'category' );
		} finally {
			\remove_filter( 'wp_sitemaps_taxonomies_pre_max_num_pages', $pre_page_filter, 10 );
			\remove_filter( 'wp_sitemaps_taxonomies_pre_url_list', $pre_list_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$pre_sentinel === $pre_list
				&& array() === $pre_unsupported
				&& 19 === $pre_pages
				&& array( array( 'taxonomy' => 'category', 'page' => 2, 'null' => true ) ) === $pre_seen
				&& array( array( 'taxonomy' => 'category', 'null' => true ) ) === $pre_page_seen
				&& false === \has_filter( 'wp_sitemaps_taxonomies_pre_url_list', $pre_list_filter )
				&& false === \has_filter( 'wp_sitemaps_taxonomies_pre_max_num_pages', $pre_page_filter ),
			'WP_Sitemaps_Taxonomies pre-list and pre-page filters short-circuit only matching taxonomy calls',
			array(
				'preList'        => $pre_list,
				'preUnsupported' => $pre_unsupported,
				'prePages'       => $pre_pages,
				'preSeen'        => $pre_seen,
				'prePageSeen'    => $pre_page_seen,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.taxonomies-provider-fixtures',
			array() === $failures,
			array(
				'limit'    => $limit,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function check_sitemap_users_provider( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::sitemap_provider_fixture_missing_requirements();
		if ( array() !== $missing ) {
			return self::skip(
				$ctx,
				'discovery.sitemaps.users-provider-fixtures',
				'Built-in sitemap provider fixture APIs are unavailable.',
				array( 'missing' => $missing )
			);
		}

		$failures        = array();
		$case            = self::prepare_sitemap_provider_runtime( $ctx->fork( 'users-runtime' ) );
		$provider        = new \WP_Sitemaps_Users();
		$limit           = 2 + $ctx->int( 0, 1 );
		$private_type    = $case['postTypePrivate'];
		$public_type     = $case['postTypePublic'];
		$included_users  = array();

		\register_post_type(
			$private_type,
			array(
				'public'      => false,
				'label'       => 'Discovery Private Author Type',
				'has_archive' => false,
				'rewrite'     => false,
				'supports'    => array( 'title', 'editor', 'author' ),
			)
		);

		\register_post_type(
			$public_type,
			array(
				'public'      => true,
				'label'       => 'Discovery Public Author Type',
				'has_archive' => true,
				'rewrite'     => false,
				'supports'    => array( 'title', 'editor', 'author' ),
			)
		);

		for ( $i = 0; $i < $limit + 1; ++$i ) {
			$user_id          = self::insert_sitemap_user( $case, 'public-' . $i );
			$included_users[] = $user_id;
			self::insert_sitemap_post(
				array(
					'post_type'         => 0 === $i % 2 ? 'post' : $public_type,
					'post_status'       => 'publish',
					'post_author'       => $user_id,
					'post_title'        => 'Discovery public author post ' . $i,
					'post_name'         => 'discovery-public-author-' . $case['token'] . '-' . $i,
					'post_modified_gmt' => sprintf( '2026-06-%02d 12:00:00', 1 + $i ),
				)
			);
		}

		$page_only_user = self::insert_sitemap_user( $case, 'page-only' );
		self::insert_sitemap_post(
			array(
				'post_type'         => 'page',
				'post_status'       => 'publish',
				'post_author'       => $page_only_user,
				'post_title'        => 'Discovery author page only',
				'post_name'         => 'discovery-author-page-only-' . $case['token'],
				'post_modified_gmt' => '2026-06-15 12:00:00',
			)
		);

		$draft_only_user = self::insert_sitemap_user( $case, 'draft-only' );
		self::insert_sitemap_post(
			array(
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_author'       => $draft_only_user,
				'post_title'        => 'Discovery author draft only',
				'post_name'         => 'discovery-author-draft-only-' . $case['token'],
				'post_modified_gmt' => '2026-06-16 12:00:00',
			)
		);

		$private_only_user = self::insert_sitemap_user( $case, 'private-only' );
		self::insert_sitemap_post(
			array(
				'post_type'         => $private_type,
				'post_status'       => 'publish',
				'post_author'       => $private_only_user,
				'post_title'        => 'Discovery author private type only',
				'post_name'         => 'discovery-author-private-only-' . $case['token'],
				'post_modified_gmt' => '2026-06-17 12:00:00',
			)
		);

		$empty_user = self::insert_sitemap_user( $case, 'empty' );

		$query_seen = array();
		$entry_seen = array();
		$max_filter = static function ( int $max_urls, string $object_type ) use ( $limit ): int {
			return 'user' === $object_type ? $limit : $max_urls;
		};
		$query_filter = static function ( array $args ) use ( &$query_seen ): array {
			$query_seen[] = array(
				'number'            => $args['number'] ?? null,
				'hasPublishedPosts' => array_values( (array) ( $args['has_published_posts'] ?? array() ) ),
			);

			return $args;
		};
		$entry_filter = static function ( array $entry, \WP_User $user ) use ( &$entry_seen ): array {
			$entry_seen[] = array(
				'id'  => (int) $user->ID,
				'loc' => $entry['loc'] ?? null,
			);

			$entry['component-fuzz-user'] = (string) $user->ID;
			return $entry;
		};

		\add_filter( 'wp_sitemaps_max_urls', $max_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_users_query_args', $query_filter, 10, 1 );
		\add_filter( 'wp_sitemaps_users_entry', $entry_filter, 10, 2 );
		try {
			$max_pages    = $provider->get_max_num_pages();
			$page_entries = array();
			for ( $page = 1; $page <= $max_pages; ++$page ) {
				$page_entries[ $page ] = $provider->get_url_list( $page );
			}
		} finally {
			\remove_filter( 'wp_sitemaps_users_entry', $entry_filter, 10 );
			\remove_filter( 'wp_sitemaps_users_query_args', $query_filter, 10 );
			\remove_filter( 'wp_sitemaps_max_urls', $max_filter, 10 );
		}

		$all_entries  = array_merge( ...array_values( $page_entries ) );
		$excluded_ids = array( $page_only_user, $draft_only_user, $private_only_user, $empty_user );
		self::collect_failure(
			$failures,
			2 === $max_pages
				&& $limit === count( $page_entries[1] ?? array() )
				&& 1 === count( $page_entries[2] ?? array() )
				&& count( $included_users ) === count( $all_entries )
				&& self::sitemap_user_entries_match_ids( $all_entries, $included_users )
				&& array_column( $entry_seen, 'id' ) === $included_users
				&& ! array_intersect( array_column( $entry_seen, 'id' ), $excluded_ids )
				&& self::sitemap_user_query_args_include_public_post_types( $query_seen, $limit, $public_type, $private_type )
				&& false === \has_filter( 'wp_sitemaps_users_entry', $entry_filter )
				&& false === \has_filter( 'wp_sitemaps_users_query_args', $query_filter )
				&& false === \has_filter( 'wp_sitemaps_max_urls', $max_filter ),
			'WP_Sitemaps_Users lists only authors with published public posts and computes max pages',
			array(
				'limit'      => $limit,
				'maxPages'   => $max_pages,
				'pageCounts' => array_map( 'count', $page_entries ),
				'entrySeen'  => $entry_seen,
				'querySeen'  => $query_seen,
				'excluded'   => $excluded_ids,
				'privateType' => $private_type,
			)
		);

		$pre_seen      = array();
		$pre_page_seen = array();
		$pre_sentinel  = array( array( 'loc' => 'https://example.test/pre-users-' . $case['token'] ) );
		$pre_list_filter = static function ( $url_list, int $page_num ) use ( &$pre_seen, $pre_sentinel ) {
			$pre_seen[] = array(
				'page' => $page_num,
				'null' => null === $url_list,
			);

			return 2 === $page_num ? $pre_sentinel : $url_list;
		};
		$pre_page_filter = static function ( $max_num_pages ) use ( &$pre_page_seen ): int {
			$pre_page_seen[] = array( 'null' => null === $max_num_pages );
			return 23;
		};

		\add_filter( 'wp_sitemaps_users_pre_url_list', $pre_list_filter, 10, 2 );
		\add_filter( 'wp_sitemaps_users_pre_max_num_pages', $pre_page_filter, 10, 1 );
		try {
			$pre_list  = $provider->get_url_list( 2 );
			$pre_pages = $provider->get_max_num_pages();
		} finally {
			\remove_filter( 'wp_sitemaps_users_pre_max_num_pages', $pre_page_filter, 10 );
			\remove_filter( 'wp_sitemaps_users_pre_url_list', $pre_list_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$pre_sentinel === $pre_list
				&& 23 === $pre_pages
				&& array( array( 'page' => 2, 'null' => true ) ) === $pre_seen
				&& array( array( 'null' => true ) ) === $pre_page_seen
				&& false === \has_filter( 'wp_sitemaps_users_pre_url_list', $pre_list_filter )
				&& false === \has_filter( 'wp_sitemaps_users_pre_max_num_pages', $pre_page_filter ),
			'WP_Sitemaps_Users pre-list and pre-page filters short-circuit provider calls',
			array(
				'preList'     => $pre_list,
				'prePages'    => $pre_pages,
				'preSeen'     => $pre_seen,
				'prePageSeen' => $pre_page_seen,
			)
		);

		return self::row(
			$ctx,
			'discovery.sitemaps.users-provider-fixtures',
			array() === $failures,
			array(
				'limit'    => $limit,
				'failures' => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function sitemap_provider_fixture_missing_requirements(): array {
		$missing = array();

		self::load_builtin_sitemap_provider_classes();

		foreach ( array( 'WP_Post', 'WP_Sitemaps_Posts', 'WP_Sitemaps_Taxonomies', 'WP_Sitemaps_Users', 'WP_Term', 'WP_User' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'create_initial_post_types',
				'create_initial_taxonomies',
				'get_author_posts_url',
				'get_permalink',
				'get_term_link',
				'home_url',
				'is_wp_error',
				'register_post_type',
				'register_taxonomy',
				'wp_cache_flush',
				'wp_insert_post',
				'wp_insert_term',
				'wp_insert_user',
				'wp_date',
				'wp_timezone',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) || ! method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_options' ) ) {
			$missing[] = 'component fuzz wpdb stub';
		}

		return $missing;
	}

	private static function load_builtin_sitemap_provider_classes(): void {
		if ( class_exists( 'WP_Sitemaps_Posts' ) && class_exists( 'WP_Sitemaps_Taxonomies' ) && class_exists( 'WP_Sitemaps_Users' ) ) {
			return;
		}

		if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		foreach (
			array(
				'WP_Sitemaps_Posts'      => ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-posts.php',
				'WP_Sitemaps_Taxonomies' => ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-taxonomies.php',
				'WP_Sitemaps_Users'      => ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-users.php',
			) as $class => $path
		) {
			if ( ! class_exists( $class ) && is_file( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function load_sitemap_stylesheet_class(): void {
		if ( class_exists( 'WP_Sitemaps_Stylesheet' ) ) {
			return;
		}

		if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return;
		}

		$path = ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-stylesheet.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}

	private static function prepare_sitemap_provider_runtime( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb, $wp_rewrite;

		$token = substr( hash( 'crc32b', 'discovery-sitemaps:' . $ctx->seed() ), 0, 7 );
		$wpdb->component_fuzz_reset_content();
		$wpdb->component_fuzz_reset_options(
			array(
				'admin_email'            => 'admin@example.test',
				'blog_charset'           => 'UTF-8',
				'blog_public'            => 1,
				'blogname'               => 'Component Fuzz',
				'default_category'       => 0,
				'default_comment_status' => 'closed',
				'default_ping_status'    => 'closed',
				'gmt_offset'             => 2,
				'home'                   => 'https://example.test',
				'permalink_structure'    => '',
				'show_on_front'          => 'posts',
				'siteurl'                => 'https://example.test',
				'timezone_string'        => 'Europe/Madrid',
			)
		);

		\wp_cache_flush();

		$GLOBALS['wp_post_types'] = array();
		$GLOBALS['wp_taxonomies'] = array();
		\create_initial_post_types();
		\create_initial_taxonomies();

		if ( class_exists( 'WP_Rewrite' ) ) {
			$wp_rewrite = new \WP_Rewrite();
		}

		return array(
			'token'           => $token,
			'postTypePrivate' => 'cfzdp' . substr( $token, 0, 7 ),
			'postTypePublic'  => 'cfzdu' . substr( $token, 0, 7 ),
			'taxonomyPrivate' => 'cfzdtax' . substr( $token, 0, 7 ),
			'taxonomyPublic'  => 'cfzdtp' . substr( $token, 0, 7 ),
		);
	}

	private static function insert_sitemap_post( array $fields ): int {
		$defaults = array(
			'post_author'       => 0,
			'post_content'      => 'Discovery sitemap fixture content',
			'post_date'         => '2026-06-01 00:00:00',
			'post_date_gmt'     => '2026-06-01 00:00:00',
			'post_excerpt'      => '',
			'post_modified'     => $fields['post_modified_gmt'] ?? '2026-06-01 00:00:00',
			'post_modified_gmt' => '2026-06-01 00:00:00',
			'post_name'         => '',
			'post_status'       => 'publish',
			'post_title'        => 'Discovery sitemap fixture',
			'post_type'         => 'post',
		);

		$post_id = \wp_insert_post( array_merge( $defaults, $fields ), true, false );
		if ( \is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Could not insert sitemap post fixture: ' . $post_id->get_error_code() );
		}

		return (int) $post_id;
	}

	private static function insert_sitemap_user( array $case, string $suffix ): int {
		$safe_suffix = preg_replace( '/[^a-z0-9_]+/', '-', strtolower( $suffix ) );
		$login       = 'cfz_' . $case['token'] . '_' . $safe_suffix;
		$user_id     = \wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => 'component-fuzz',
				'user_email'   => $login . '@example.test',
				'user_nicename' => str_replace( '_', '-', $login ),
				'display_name' => 'Discovery User ' . $suffix,
			)
		);

		if ( \is_wp_error( $user_id ) ) {
			throw new \RuntimeException( 'Could not insert sitemap user fixture: ' . $user_id->get_error_code() );
		}

		return (int) $user_id;
	}

	private static function insert_sitemap_term( string $taxonomy, string $name, string $slug, int $count ): array {
		global $wpdb;

		$result = \wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug' => $slug,
			)
		);

		if ( \is_wp_error( $result ) ) {
			throw new \RuntimeException( 'Could not insert sitemap term fixture: ' . $result->get_error_code() );
		}

		$wpdb->update(
			$wpdb->term_taxonomy,
			array( 'count' => $count ),
			array( 'term_taxonomy_id' => (int) $result['term_taxonomy_id'] )
		);

		return array(
			'term_id'          => (int) $result['term_id'],
			'term_taxonomy_id' => (int) $result['term_taxonomy_id'],
			'taxonomy'         => $taxonomy,
			'slug'             => $slug,
			'count'            => $count,
		);
	}

	private static function sitemap_post_entries_match_fixtures( array $entries, array $posts ): bool {
		if ( count( $entries ) !== count( $posts ) ) {
			return false;
		}

		foreach ( $posts as $index => $post ) {
			$entry    = $entries[ $index ] ?? array();
			$expected = self::expected_sitemap_lastmod_from_gmt( (string) $post['modified_gmt'] );
			if ( ( $entry['loc'] ?? null ) !== \get_permalink( $post['id'] ) ) {
				return false;
			}
			if ( ( $entry['lastmod'] ?? null ) !== $expected || ! self::is_w3c_datetime( (string) ( $entry['lastmod'] ?? '' ) ) ) {
				return false;
			}
			if ( (string) $post['id'] !== ( $entry['component-fuzz-id'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function expected_sitemap_lastmod_from_gmt( string $modified_gmt ): string {
		$datetime = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $modified_gmt, new \DateTimeZone( 'UTC' ) );
		if ( ! $datetime ) {
			return '';
		}

		$timezone = function_exists( 'wp_timezone' ) ? \wp_timezone() : new \DateTimeZone( 'UTC' );
		return $datetime->setTimezone( $timezone )->format( DATE_W3C );
	}

	private static function sitemap_entries_contain_locs_for_ids( array $entries, array $post_ids ): bool {
		$locs = array_column( $entries, 'loc' );
		foreach ( $post_ids as $post_id ) {
			if ( in_array( \get_permalink( $post_id ), $locs, true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function sitemap_post_query_args_local_to_type( array $query_seen, string $post_type, int $limit ): bool {
		if ( array() === $query_seen ) {
			return false;
		}

		foreach ( $query_seen as $seen ) {
			if ( $post_type !== ( $seen['postType'] ?? null ) ) {
				return false;
			}
			if ( $limit !== (int) ( $seen['postsPerPage'] ?? 0 ) ) {
				return false;
			}
			if ( array( 'publish' ) !== array_values( (array) ( $seen['status'] ?? array() ) ) ) {
				return false;
			}
			if ( true !== ( $seen['ignoreSticky'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function sitemap_term_entries_match_fixtures( array $entries, array $terms, string $taxonomy ): bool {
		if ( count( $entries ) !== count( $terms ) ) {
			return false;
		}

		foreach ( $terms as $index => $term ) {
			$entry = $entries[ $index ] ?? array();
			$link  = \get_term_link( $term['term_id'], $taxonomy );
			if ( \is_wp_error( $link ) || ( $entry['loc'] ?? null ) !== $link ) {
				return false;
			}
			if ( (string) $term['term_id'] !== ( $entry['component-fuzz-term'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function sitemap_taxonomy_query_args_local_to_taxonomy( array $query_seen, string $taxonomy, int $limit ): bool {
		if ( array() === $query_seen ) {
			return false;
		}

		foreach ( $query_seen as $seen ) {
			if ( $taxonomy !== ( $seen['taxonomy'] ?? null ) ) {
				return false;
			}
			if ( $limit !== (int) ( $seen['number'] ?? 0 ) ) {
				return false;
			}
			if ( true !== ( $seen['hideEmpty'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function sitemap_user_entries_match_ids( array $entries, array $user_ids ): bool {
		if ( count( $entries ) !== count( $user_ids ) ) {
			return false;
		}

		foreach ( $user_ids as $index => $user_id ) {
			$entry = $entries[ $index ] ?? array();
			if ( ( $entry['loc'] ?? null ) !== \get_author_posts_url( $user_id ) ) {
				return false;
			}
			if ( (string) $user_id !== ( $entry['component-fuzz-user'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function sitemap_user_query_args_include_public_post_types( array $query_seen, int $limit, string $public_type, string $private_type ): bool {
		if ( array() === $query_seen ) {
			return false;
		}

		$expected_post_types = array( 'post', $public_type );
		sort( $expected_post_types );

		foreach ( $query_seen as $seen ) {
			$post_types = array_values( (array) ( $seen['hasPublishedPosts'] ?? array() ) );
			sort( $post_types );
			if ( $limit !== (int) ( $seen['number'] ?? 0 ) ) {
				return false;
			}
			if ( $expected_post_types !== $post_types ) {
				return false;
			}
			if ( in_array( 'page', $post_types, true ) || in_array( 'attachment', $post_types, true ) || in_array( $private_type, $post_types, true ) ) {
				return false;
			}
		}

		return true;
	}

	private static function is_w3c_datetime( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value );
	}

	private static function robots_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'directives' => array() ),
			array( 'directives' => array( 'noindex' => true, 'nofollow' => false, 'max-image-preview' => 'large' ) ),
			array( 'directives' => array( 'index' => true, 'snippet' => 'max-snippet:' . $ctx->int( 1, 99 ) ) ),
			array( 'directives' => array( 'unsafe<tag' => 'a"b<c&d', 'archive' => true ) ),
		);

		for ( $i = count( $cases ); $i < 8; ++$i ) {
			$case       = $ctx->fork( 'case-' . $i );
			$directive  = 'cfz-' . substr( hash( 'crc32b', (string) $case->seed() ), 0, 8 );
			$value      = $case->choice( array( true, false, 'value-' . $case->int( 0, 999 ), "unsafe\"<>&" ) );
			$cases[]    = array(
				'directives' => array(
					$directive           => $value,
					'max-snippet'        => (string) $case->int( 1, 500 ),
					'max-image-preview'  => 'large',
				),
			);
		}

		return $cases;
	}

	private static function field_token( \ComponentFuzz\FuzzContext $ctx ): string {
		return substr( hash( 'sha1', self::NAME . ':field:' . $ctx->seed() ), 0, 10 );
	}

	private static function sitemap_url_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$urls = array();
		for ( $i = 0; $i < self::URL_CASES; ++$i ) {
			$case   = $ctx->fork( 'url-' . $i );
			$urls[] = array(
				'loc'        => 'https://example.test/content/' . $i . '?q=' . rawurlencode( $case->text( 0, 24 ) ),
				'lastmod'    => sprintf( '2026-06-%02dT%02d:00:00+00:00', 1 + $case->int( 0, 20 ), $case->int( 0, 23 ) ),
				'changefreq' => $case->choice( array( 'daily', 'weekly', 'monthly' ) ),
				'priority'   => sprintf( '0.%d', $case->int( 1, 9 ) ),
			);
		}

		$urls[] = array(
			'loc'     => 'https://example.test/unsafe?x=<tag>&quote="',
			'lastmod' => '2026-06-21T00:00:00+00:00',
		);

		return $urls;
	}

	private static function sitemap_provider( string $name, string $object_type, array $subtypes, array $urls ): \WP_Sitemaps_Provider {
		return new class( $name, $object_type, $subtypes, $urls ) extends \WP_Sitemaps_Provider {
			/** @var array<string,array<string,mixed>> */
			private array $subtypes;

			/** @var array<int,array<string,string>> */
			private array $urls;

			public function __construct( string $name, string $object_type, array $subtypes, array $urls ) {
				$this->name        = $name;
				$this->object_type = $object_type;
				$this->subtypes    = $subtypes;
				$this->urls        = $urls;
			}

			public function get_url_list( $page_num, $object_subtype = '' ) {
				unset( $object_subtype );
				$page_num = max( 1, (int) $page_num );
				return array_slice( $this->urls, ( $page_num - 1 ) * 4, 4 );
			}

			public function get_max_num_pages( $object_subtype = '' ) {
				return 'beta' === $object_subtype ? 3 : 1;
			}

			public function get_object_subtypes() {
				return $this->subtypes;
			}
		};
	}

	private static function all_entries_are_sitemap_locs( array $entries, string $name ): bool {
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['loc'] ) || ! is_string( $entry['loc'] ) || ! str_contains( $entry['loc'], 'sitemap=' . rawurlencode( $name ) ) ) {
				return false;
			}
		}

		return true;
	}

	private static function reset_runtime(): void {
		global $wp_rewrite;

		self::$provider_data     = array();
		if ( class_exists( 'WP_Rewrite' ) ) {
			$wp_rewrite = new \WP_Rewrite();
		} else {
			$wp_rewrite = new class() {
				public function using_permalinks(): bool {
					return false;
				}
			};
		}

		$GLOBALS['wp_sitemaps']           = new \WP_Sitemaps();
		$GLOBALS['wp_sitemaps']->registry = new \WP_Sitemaps_Registry();
		$GLOBALS['wp_sitemaps']->renderer = new \WP_Sitemaps_Renderer();
		$GLOBALS['wp_sitemaps']->index    = new \WP_Sitemaps_Index( $GLOBALS['wp_sitemaps']->registry );
	}

	private static function install_url_filters(): void {
		\add_filter( 'pre_option_home', array( __CLASS__, 'filter_home_option' ), 10, 3 );
		\add_filter( 'pre_option_siteurl', array( __CLASS__, 'filter_home_option' ), 10, 3 );
		\add_filter( 'pre_option_blog_public', array( __CLASS__, 'filter_blog_public' ), 10, 3 );
		\add_filter( 'wp_sitemaps_stylesheet_url', '__return_false', 0 );
		\add_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false', 0 );
	}

	private static function remove_url_filters(): void {
		\remove_filter( 'pre_option_home', array( __CLASS__, 'filter_home_option' ), 10 );
		\remove_filter( 'pre_option_siteurl', array( __CLASS__, 'filter_home_option' ), 10 );
		\remove_filter( 'pre_option_blog_public', array( __CLASS__, 'filter_blog_public' ), 10 );
		\remove_filter( 'wp_sitemaps_stylesheet_url', '__return_false', 0 );
		\remove_filter( 'wp_sitemaps_stylesheet_index_url', '__return_false', 0 );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array(), ?string $status = null ): array {
		return array(
			'ok'        => $ok,
			'status'    => $status ?? ( $ok ? 'passed' : 'failed' ),
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'data'      => self::describe_value( $data ),
		);
	}

	private static function skip( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		$data['reason'] = $reason;
		return self::row( $ctx, $invariant, true, $data, 'skipped' );
	}

	private static function describe_value( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_array( $value ) ) {
			if ( $depth >= 4 ) {
				return array(
					'type'  => 'array',
					'count' => count( $value ),
				);
			}

			$out = array();
			$i   = 0;
			foreach ( $value as $key => $item ) {
				if ( $i >= 16 ) {
					$out['...'] = count( $value ) - $i;
					break;
				}
				$out[ is_int( $key ) ? $key : self::escape_bytes( (string) $key ) ] = self::describe_value( $item, $depth + 1 );
				++$i;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \Throwable ) {
				return self::describe_throwable( $value );
			}

			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'type'    => 'string',
			'bytes'   => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => self::escape_bytes( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}

	private static function escape_bytes( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; ++$i ) {
			$byte = ord( $value[ $i ] );
			if ( 0x5C === $byte ) {
				$out .= '\\\\';
			} elseif ( $byte >= 0x20 && $byte <= 0x7E ) {
				$out .= chr( $byte );
			} elseif ( 0x0A === $byte ) {
				$out .= '\\n';
			} elseif ( 0x0D === $byte ) {
				$out .= '\\r';
			} elseif ( 0x09 === $byte ) {
				$out .= '\\t';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $shown ) {
			$out .= '...';
		}

		return $out;
	}

	private static function snapshot_globals(): array {
		$snapshot = array(
			'providerData' => self::$provider_data,
			'globals'      => array(),
		);

		foreach ( array( 'wpdb', 'wp_rewrite', 'wp_sitemaps', 'wp_filter', 'wp_filters', 'wp_actions', 'wp_current_filter', 'wp_object_cache', 'wp_post_types', 'wp_taxonomies', 'wp_locale' ) as $name ) {
			$snapshot['globals'][ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		self::remove_url_filters();
		self::$provider_data = $snapshot['providerData'];

		foreach ( $snapshot['globals'] as $name => $entry ) {
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
