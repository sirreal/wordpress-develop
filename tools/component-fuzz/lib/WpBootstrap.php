<?php
namespace ComponentFuzz;

final class WpBootstrap {
	private static bool $loaded = false;

	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}

		$root = repo_root();
		$src  = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $src );
		}
		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
		}
		if ( ! defined( 'WP_LANG_DIR' ) ) {
			define( 'WP_LANG_DIR', WP_CONTENT_DIR . '/languages' );
		}
		if ( ! defined( 'WP_CONTENT_URL' ) ) {
			define( 'WP_CONTENT_URL', 'http://example.test/wp-content' );
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
		}
		if ( ! defined( 'WP_PLUGIN_URL' ) ) {
			define( 'WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins' );
		}
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
		}
		if ( ! defined( 'WPMU_PLUGIN_URL' ) ) {
			define( 'WPMU_PLUGIN_URL', WP_CONTENT_URL . '/mu-plugins' );
		}
		if ( ! defined( 'COOKIEHASH' ) ) {
			define( 'COOKIEHASH', 'component_fuzz' );
		}
		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}
		if ( ! defined( 'OBJECT_K' ) ) {
			define( 'OBJECT_K', 'OBJECT_K' );
		}
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		if ( ! defined( 'ARRAY_N' ) ) {
			define( 'ARRAY_N', 'ARRAY_N' );
		}
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'component-fuzz-auth-key' );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'component-fuzz-secure-auth-key' );
		}
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', 'component-fuzz-logged-in-key' );
		}
		if ( ! defined( 'NONCE_KEY' ) ) {
			define( 'NONCE_KEY', 'component-fuzz-nonce-key' );
		}
		if ( ! defined( 'AUTH_SALT' ) ) {
			define( 'AUTH_SALT', 'component-fuzz-auth-salt' );
		}
		if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
			define( 'SECURE_AUTH_SALT', 'component-fuzz-secure-auth-salt' );
		}
		if ( ! defined( 'LOGGED_IN_SALT' ) ) {
			define( 'LOGGED_IN_SALT', 'component-fuzz-logged-in-salt' );
		}
		if ( ! defined( 'NONCE_SALT' ) ) {
			define( 'NONCE_SALT', 'component-fuzz-nonce-salt' );
		}
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', false );
		}
		if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
			define( 'WP_DEBUG_DISPLAY', false );
		}
		if ( ! defined( 'WP_DEBUG_LOG' ) ) {
			define( 'WP_DEBUG_LOG', false );
		}
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
		}
		if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
			define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
		}
		if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
			define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
		}
		if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
			define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
		}
		if ( ! defined( 'WP_CRON_LOCK_TIMEOUT' ) ) {
			define( 'WP_CRON_LOCK_TIMEOUT', MINUTE_IN_SECONDS );
		}

		$files = array(
			'wp-includes/compat.php',
			'wp-includes/compat-utf8.php',
			'wp-includes/utf8.php',
			'wp-includes/class-wp-email-address.php',
			'wp-includes/class-wp-error.php',
			'wp-includes/class-wp-http-response.php',
			'wp-includes/plugin.php',
			'wp-includes/load.php',
			'wp-includes/functions.php',
			'wp-includes/cache.php',
			'wp-includes/option.php',
			'wp-includes/cron.php',
			'wp-includes/class-wp-list-util.php',
			'wp-includes/script-loader.php',
			'wp-includes/class-wp-script-modules.php',
			'wp-includes/script-modules.php',
			'wp-includes/class-wp-http.php',
			'wp-includes/formatting.php',
			'wp-includes/pomo/mo.php',
			'wp-includes/l10n/class-wp-translation-controller.php',
			'wp-includes/l10n/class-wp-translations.php',
			'wp-includes/l10n/class-wp-translation-file.php',
			'wp-includes/l10n/class-wp-translation-file-mo.php',
			'wp-includes/l10n/class-wp-translation-file-php.php',
			'wp-includes/l10n.php',
			'wp-includes/class-wp-textdomain-registry.php',
			'wp-includes/class-wp-locale.php',
			'wp-includes/class-wp-locale-switcher.php',
			'wp-includes/http.php',
			'wp-includes/class-wp-token-map.php',
			'wp-includes/html-api/html5-named-character-references.php',
			'wp-includes/html-api/class-wp-html-attribute-token.php',
			'wp-includes/html-api/class-wp-html-span.php',
			'wp-includes/html-api/class-wp-html-text-replacement.php',
			'wp-includes/html-api/class-wp-html-decoder.php',
			'wp-includes/html-api/class-wp-html-tag-processor.php',
			'wp-includes/kses.php',
			'wp-includes/shortcodes.php',
			'wp-includes/media.php',
			'wp-includes/class-wp-block-type.php',
			'wp-includes/class-wp-block-type-registry.php',
			'wp-includes/class-wp-block-styles-registry.php',
			'wp-includes/class-wp-block-patterns-registry.php',
			'wp-includes/class-wp-block-metadata-registry.php',
			'wp-includes/class-wp-block-bindings-source.php',
			'wp-includes/class-wp-block-bindings-registry.php',
			'wp-includes/block-bindings.php',
			'wp-includes/class-wp-block-supports.php',
			'wp-includes/class-wp-block-list.php',
			'wp-includes/class-wp-block.php',
			'wp-includes/class-wp-block-parser-block.php',
			'wp-includes/class-wp-block-parser-frame.php',
			'wp-includes/class-wp-block-parser.php',
			'wp-includes/blocks.php',
			'wp-includes/taxonomy.php',
			'wp-includes/class-wp-post.php',
			'wp-includes/class-wp-user.php',
			'wp-includes/class-wp-comment.php',
			'wp-includes/post.php',
			'wp-includes/post-template.php',
			'wp-includes/user.php',
			'wp-includes/comment.php',
			'wp-includes/comment-template.php',
			'wp-includes/class-wp-date-query.php',
			'wp-includes/rest-api/class-wp-rest-request.php',
			'wp-includes/rest-api/class-wp-rest-response.php',
			'wp-includes/rest-api/class-wp-rest-server.php',
			'wp-includes/rest-api.php',
			'wp-includes/class-phpass.php',
			'wp-includes/pluggable.php',
			'wp-admin/includes/file.php',
			'wp-admin/includes/class-wp-filesystem-base.php',
			'wp-admin/includes/class-wp-filesystem-direct.php',
		);

		foreach ( $files as $file ) {
			$path = $src . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		require_once __DIR__ . '/wp-stubs.php';

		if ( function_exists( 'wp_cache_init' ) && ! isset( $GLOBALS['wp_object_cache'] ) ) {
			wp_cache_init();
		}

		if ( class_exists( 'WP_Textdomain_Registry' ) && ! isset( $GLOBALS['wp_textdomain_registry'] ) ) {
			$GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();
			$GLOBALS['wp_textdomain_registry']->init();
		}

		if ( class_exists( 'WP_Locale' ) && ! isset( $GLOBALS['wp_locale'] ) ) {
			$GLOBALS['wp_locale'] = new \WP_Locale();
		}

		self::$loaded = true;
	}
}
