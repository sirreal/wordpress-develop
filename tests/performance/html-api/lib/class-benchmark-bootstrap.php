<?php
/**
 * Loads the HTML API from a WordPress checkout without loading WordPress.
 *
 * Part of the HTML API parsing benchmark. Dev tool only; not shipped.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

/**
 * Loads the HTML API files from a checkout root plus the WordPress function
 * stubs they call at runtime.
 */
class Benchmark_Bootstrap {
	/**
	 * Files required from the checkout root, in load order.
	 *
	 * Entries flagged optional are skipped when absent: older trunks lack them.
	 *
	 * @var array<string, bool> Path relative to the checkout root => whether optional.
	 */
	const FILES = array(
		'src/wp-includes/compat.php'                       => false,
		'src/wp-includes/compat-utf8.php'                  => true,
		'src/wp-includes/utf8.php'                         => true,
		'src/wp-includes/class-wp-token-map.php'           => false,
		'src/wp-includes/html-api/html5-named-character-references.php' => false,
		'src/wp-includes/html-api/class-wp-html-attribute-token.php' => false,
		'src/wp-includes/html-api/class-wp-html-span.php'  => false,
		'src/wp-includes/html-api/class-wp-html-doctype-info.php' => false,
		'src/wp-includes/html-api/class-wp-html-text-replacement.php' => false,
		'src/wp-includes/html-api/class-wp-html-decoder.php' => false,
		'src/wp-includes/html-api/class-wp-html-tag-processor.php' => false,
		'src/wp-includes/html-api/class-wp-html-unsupported-exception.php' => false,
		'src/wp-includes/html-api/class-wp-html-active-formatting-elements.php' => false,
		'src/wp-includes/html-api/class-wp-html-open-elements.php' => false,
		'src/wp-includes/html-api/class-wp-html-token.php' => false,
		'src/wp-includes/html-api/class-wp-html-stack-event.php' => false,
		'src/wp-includes/html-api/class-wp-html-processor-state.php' => false,
		'src/wp-includes/html-api/class-wp-html-processor.php' => false,
	);

	/**
	 * Loads the HTML API from the given checkout.
	 *
	 * @throws RuntimeException When a required file is missing.
	 *
	 * @param string $checkout_root Directory containing `src/wp-includes/html-api`.
	 */
	public static function load( string $checkout_root ): void {
		$checkout_root = rtrim( $checkout_root, '/' );

		if ( ! is_dir( "{$checkout_root}/src/wp-includes/html-api" ) ) {
			throw new RuntimeException( "Not a WordPress checkout (no src/wp-includes/html-api): {$checkout_root}" );
		}

		self::define_stubs();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', "{$checkout_root}/src/" );
		}
		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}

		foreach ( self::FILES as $relative_path => $is_optional ) {
			$path = "{$checkout_root}/{$relative_path}";
			if ( ! file_exists( $path ) ) {
				if ( $is_optional ) {
					continue;
				}
				throw new RuntimeException( "Required file missing from checkout: {$path}" );
			}
			require_once $path;
		}

		self::define_late_stubs();
	}

	/**
	 * Defines the WordPress functions the HTML API calls, when not already defined.
	 *
	 * Mirrors the fuzz harness stubs in tools/html-api-fuzz/lib/wp-stubs.php.
	 */
	private static function define_stubs(): void {
		if ( ! function_exists( '__' ) ) {
			/**
			 * Identity translation stub.
			 *
			 * @param string $text   Text.
			 * @param string $domain Unused.
			 * @return string Text.
			 */
			function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				return $text;
			}
		}

		if ( ! function_exists( '_doing_it_wrong' ) ) {
			/**
			 * No-op stub.
			 *
			 * @param string $function_name Unused.
			 * @param string $message       Unused.
			 * @param string $version       Unused.
			 */
			function _doing_it_wrong( $function_name, $message, $version ) {}
		}

		if ( ! function_exists( '_deprecated_argument' ) ) {
			/**
			 * No-op stub.
			 *
			 * @param string $function_name Unused.
			 * @param string $version       Unused.
			 * @param string $message       Unused.
			 */
			function _deprecated_argument( $function_name, $version, $message = '' ) {}
		}

		if ( ! function_exists( '_deprecated_function' ) ) {
			/**
			 * No-op stub.
			 *
			 * @param string $function_name Unused.
			 * @param string $version       Unused.
			 * @param string $replacement   Unused.
			 */
			function _deprecated_function( $function_name, $version, $replacement = '' ) {}
		}

		if ( ! function_exists( 'wp_trigger_error' ) ) {
			/**
			 * No-op stub.
			 *
			 * @param string $function_name Unused.
			 * @param string $message       Unused.
			 * @param int    $error_level   Unused.
			 */
			function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) {}
		}

		if ( ! function_exists( 'esc_url' ) ) {
			/**
			 * Identity stub.
			 *
			 * @param string $url       URL.
			 * @param array  $protocols Unused.
			 * @param string $_context  Unused.
			 * @return string URL unchanged.
			 */
			function esc_url( $url, $protocols = null, $_context = 'display' ) {
				return (string) $url;
			}
		}

		if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
			/**
			 * The core list of URI attributes.
			 *
			 * @return string[] Attribute names.
			 */
			function wp_kses_uri_attributes() {
				return array(
					'action',
					'archive',
					'background',
					'cite',
					'classid',
					'codebase',
					'data',
					'formaction',
					'href',
					'icon',
					'longdesc',
					'manifest',
					'poster',
					'profile',
					'src',
					'usemap',
					'xmlns',
				);
			}
		}
	}

	/**
	 * Defines stubs for functions newer checkouts define in utf8.php.
	 *
	 * Runs after the checkout's files load so a real definition wins.
	 */
	private static function define_late_stubs(): void {
		if ( ! function_exists( 'wp_scrub_utf8' ) ) {
			/**
			 * Identity stub for checkouts without utf8.php.
			 *
			 * @param string $text Text.
			 * @return string Text unchanged.
			 */
			function wp_scrub_utf8( $text ) {
				return $text;
			}
		}

		if ( ! function_exists( 'wp_has_noncharacters' ) ) {
			/**
			 * Stub for checkouts without utf8.php: reports no noncharacters.
			 *
			 * @param string $text Unused.
			 * @return bool False.
			 */
			function wp_has_noncharacters( string $text ): bool {
				return false;
			}
		}
	}
}
