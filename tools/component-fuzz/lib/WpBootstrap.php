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
		$component_fuzz_content_dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-wp-content-' . getmypid();

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = '/component-fuzz/';
		}
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			$_SERVER['HTTP_HOST'] = 'example.test';
		}
		if ( ! isset( $_SERVER['PHP_SELF'] ) ) {
			$_SERVER['PHP_SELF'] = '/index.php';
		}
		if ( ! isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$_SERVER['SERVER_SOFTWARE'] = 'ComponentFuzz';
		}
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz';
		}

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $src );
		}
		if ( ! defined( 'WP_START_TIMESTAMP' ) ) {
			define( 'WP_START_TIMESTAMP', microtime( true ) );
		}
		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			self::reset_temp_content_dir( $component_fuzz_content_dir );
			define( 'WP_CONTENT_DIR', $component_fuzz_content_dir );
			register_shutdown_function(
				static function () use ( $component_fuzz_content_dir ): void {
					WpBootstrap::remove_temp_content_dir( $component_fuzz_content_dir );
				}
			);
		}
		if ( ! defined( 'WP_LANG_DIR' ) ) {
			define( 'WP_LANG_DIR', WP_CONTENT_DIR . '/languages' );
		}
		if ( ! defined( 'WP_CONTENT_URL' ) ) {
			define( 'WP_CONTENT_URL', 'http://example.test/wp-content' );
		}
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'component_fuzz' );
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
		if ( ! defined( 'USER_COOKIE' ) ) {
			define( 'USER_COOKIE', 'wordpressuser_' . COOKIEHASH );
		}
		if ( ! defined( 'PASS_COOKIE' ) ) {
			define( 'PASS_COOKIE', 'wordpresspass_' . COOKIEHASH );
		}
		if ( ! defined( 'AUTH_COOKIE' ) ) {
			define( 'AUTH_COOKIE', 'wordpress_' . COOKIEHASH );
		}
		if ( ! defined( 'SECURE_AUTH_COOKIE' ) ) {
			define( 'SECURE_AUTH_COOKIE', 'wordpress_sec_' . COOKIEHASH );
		}
		if ( ! defined( 'LOGGED_IN_COOKIE' ) ) {
			define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_' . COOKIEHASH );
		}
		if ( ! defined( 'TEST_COOKIE' ) ) {
			define( 'TEST_COOKIE', 'wordpress_test_cookie' );
		}
		if ( ! defined( 'COOKIEPATH' ) ) {
			define( 'COOKIEPATH', '/' );
		}
		if ( ! defined( 'SITECOOKIEPATH' ) ) {
			define( 'SITECOOKIEPATH', '/' );
		}
		if ( ! defined( 'ADMIN_COOKIE_PATH' ) ) {
			define( 'ADMIN_COOKIE_PATH', SITECOOKIEPATH . 'wp-admin' );
		}
		if ( ! defined( 'PLUGINS_COOKIE_PATH' ) ) {
			define( 'PLUGINS_COOKIE_PATH', '/wp-content/plugins' );
		}
		if ( ! defined( 'COOKIE_DOMAIN' ) ) {
			define( 'COOKIE_DOMAIN', '' );
		}
		if ( ! defined( 'RECOVERY_MODE_COOKIE' ) ) {
			define( 'RECOVERY_MODE_COOKIE', 'wordpress_rec_' . COOKIEHASH );
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
		if ( ! defined( 'SECRET_KEY' ) ) {
			define( 'SECRET_KEY', 'component-fuzz-secret-key' );
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
		if ( ! defined( 'SECRET_SALT' ) ) {
			define( 'SECRET_SALT', 'component-fuzz-secret-salt' );
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
		if ( ! defined( 'SCRIPT_DEBUG' ) ) {
			$wp_version  = '';
			$version_file = $src . 'wp-includes/version.php';
			if ( file_exists( $version_file ) ) {
				require $version_file;
			}
			define( 'SCRIPT_DEBUG', is_string( $wp_version ) && str_contains( $wp_version, '-src' ) );
		}
		if ( ! defined( 'WP_DEVELOPMENT_MODE' ) ) {
			define( 'WP_DEVELOPMENT_MODE', '' );
		}
		if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
			define( 'WP_MEMORY_LIMIT', '256M' );
		}
		if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) {
			define( 'WP_MAX_MEMORY_LIMIT', '256M' );
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
		if ( ! defined( 'KB_IN_BYTES' ) ) {
			define( 'KB_IN_BYTES', 1024 );
		}
		if ( ! defined( 'MB_IN_BYTES' ) ) {
			define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
		}
		if ( ! defined( 'GB_IN_BYTES' ) ) {
			define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
		}
		if ( ! defined( 'TB_IN_BYTES' ) ) {
			define( 'TB_IN_BYTES', 1024 * GB_IN_BYTES );
		}
		if ( ! defined( 'PB_IN_BYTES' ) ) {
			define( 'PB_IN_BYTES', 1024 * TB_IN_BYTES );
		}
		if ( ! defined( 'EB_IN_BYTES' ) ) {
			define( 'EB_IN_BYTES', 1024 * PB_IN_BYTES );
		}
		if ( ! defined( 'ZB_IN_BYTES' ) ) {
			define( 'ZB_IN_BYTES', 1024 * EB_IN_BYTES );
		}
		if ( ! defined( 'YB_IN_BYTES' ) ) {
			define( 'YB_IN_BYTES', 1024 * ZB_IN_BYTES );
		}
		if ( ! defined( 'WP_CRON_LOCK_TIMEOUT' ) ) {
			define( 'WP_CRON_LOCK_TIMEOUT', MINUTE_IN_SECONDS );
		}
		if ( ! defined( 'EMPTY_TRASH_DAYS' ) ) {
			define( 'EMPTY_TRASH_DAYS', 30 );
		}
		if ( ! defined( 'MEDIA_TRASH' ) ) {
			define( 'MEDIA_TRASH', false );
		}
		if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			define( 'WP_POST_REVISIONS', true );
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
			'wp-includes/vars.php',
			'wp-includes/functions.php',
			'wp-includes/cache.php',
			'wp-includes/cache-compat.php',
			'wp-includes/option.php',
			'wp-includes/cron.php',
			'wp-includes/class-wp-list-util.php',
			'wp-includes/script-loader.php',
			'wp-includes/class-wp-script-modules.php',
			'wp-includes/script-modules.php',
			'wp-includes/class-wp-http-cookie.php',
			'wp-includes/class-wp-http-proxy.php',
			'wp-includes/class-wp-http.php',
			'wp-includes/class-wp-http-requests-hooks.php',
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
			'wp-includes/https-detection.php',
			'wp-includes/https-migration.php',
			'wp-includes/class-wp-token-map.php',
			'wp-includes/html-api/html5-named-character-references.php',
			'wp-includes/html-api/class-wp-html-attribute-token.php',
			'wp-includes/html-api/class-wp-html-span.php',
			'wp-includes/html-api/class-wp-html-doctype-info.php',
			'wp-includes/html-api/class-wp-html-text-replacement.php',
			'wp-includes/html-api/class-wp-html-decoder.php',
			'wp-includes/html-api/class-wp-html-tag-processor.php',
			'wp-includes/html-api/class-wp-html-unsupported-exception.php',
			'wp-includes/html-api/class-wp-html-active-formatting-elements.php',
			'wp-includes/html-api/class-wp-html-open-elements.php',
			'wp-includes/html-api/class-wp-html-token.php',
			'wp-includes/html-api/class-wp-html-stack-event.php',
			'wp-includes/html-api/class-wp-html-processor-state.php',
			'wp-includes/html-api/class-wp-html-processor.php',
			'wp-includes/interactivity-api/class-wp-interactivity-api-directives-processor.php',
			'wp-includes/interactivity-api/class-wp-interactivity-api.php',
			'wp-includes/interactivity-api/interactivity-api.php',
			'wp-includes/kses.php',
			'wp-includes/php-ai-client/autoload.php',
			'wp-includes/ai-client/adapters/class-wp-ai-client-http-client.php',
			'wp-includes/ai-client/adapters/class-wp-ai-client-cache.php',
			'wp-includes/ai-client/adapters/class-wp-ai-client-discovery-strategy.php',
			'wp-includes/ai-client/adapters/class-wp-ai-client-event-dispatcher.php',
			'wp-includes/ai-client/class-wp-ai-client-ability-function-resolver.php',
			'wp-includes/ai-client/class-wp-ai-client-prompt-builder.php',
			'wp-includes/ai-client.php',
			'wp-includes/class-wp-connector-registry.php',
			'wp-includes/connectors.php',
			'wp-includes/class-wp-icons-registry.php',
			'wp-includes/class-wp-speculation-rules.php',
			'wp-includes/class-wp-url-pattern-prefixer.php',
			'wp-includes/speculative-loading.php',
			'wp-includes/view-transitions.php',
			'wp-includes/wp-diff.php',
			'wp-includes/shortcodes.php',
			'wp-includes/class-wp-block-template.php',
			'wp-includes/class-wp-block-templates-registry.php',
			'wp-includes/block-template-utils.php',
			'wp-includes/media.php',
			'wp-includes/class-wp-image-editor.php',
			'wp-includes/class-wp-image-editor-gd.php',
			'wp-includes/class-wp-image-editor-imagick.php',
			'wp-includes/class-avif-info.php',
			'wp-includes/class-wp-block-type.php',
			'wp-includes/class-wp-block-type-registry.php',
			'wp-includes/class-wp-block-pattern-categories-registry.php',
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
			'wp-includes/class-wp-block-editor-context.php',
			'wp-includes/block-editor.php',
			'wp-includes/class-wp-taxonomy.php',
			'wp-includes/class-wp-term.php',
			'wp-includes/class-wp-term-query.php',
			'wp-includes/class-wp-paused-extensions-storage.php',
			'wp-includes/class-wp-exception.php',
			'wp-includes/class-wp-fatal-error-handler.php',
			'wp-includes/class-wp-recovery-mode-cookie-service.php',
			'wp-includes/class-wp-recovery-mode-key-service.php',
			'wp-includes/class-wp-recovery-mode-link-service.php',
			'wp-includes/class-wp-recovery-mode-email-service.php',
			'wp-includes/class-wp-recovery-mode.php',
			'wp-includes/error-protection.php',
			'wp-includes/class-wp-theme.php',
			'wp-includes/theme.php',
			'wp-includes/class-wp-theme-json-schema.php',
			'wp-includes/class-wp-theme-json-data.php',
			'wp-includes/class-wp-theme-json.php',
			'wp-includes/class-wp-theme-json-resolver.php',
			'wp-includes/global-styles-and-settings.php',
			'wp-includes/class-wp-duotone.php',
			'wp-includes/block-template.php',
			'wp-includes/fonts/class-wp-font-utils.php',
			'wp-includes/fonts/class-wp-font-collection.php',
			'wp-includes/fonts/class-wp-font-library.php',
			'wp-includes/fonts/class-wp-font-face-resolver.php',
			'wp-includes/fonts/class-wp-font-face.php',
			'wp-includes/fonts.php',
			'wp-includes/style-engine.php',
			'wp-includes/style-engine/class-wp-style-engine.php',
			'wp-includes/style-engine/class-wp-style-engine-css-declarations.php',
			'wp-includes/style-engine/class-wp-style-engine-css-rule.php',
			'wp-includes/style-engine/class-wp-style-engine-css-rules-store.php',
			'wp-includes/style-engine/class-wp-style-engine-processor.php',
			'wp-includes/block-supports/utils.php',
			'wp-includes/block-supports/align.php',
			'wp-includes/block-supports/auto-register.php',
			'wp-includes/block-supports/custom-classname.php',
			'wp-includes/block-supports/generated-classname.php',
			'wp-includes/block-supports/settings.php',
			'wp-includes/block-supports/elements.php',
			'wp-includes/block-supports/colors.php',
			'wp-includes/block-supports/typography.php',
			'wp-includes/block-supports/border.php',
			'wp-includes/block-supports/layout.php',
			'wp-includes/block-supports/position.php',
			'wp-includes/block-supports/spacing.php',
			'wp-includes/block-supports/dimensions.php',
			'wp-includes/block-supports/shadow.php',
			'wp-includes/block-supports/background.php',
			'wp-includes/block-supports/block-style-variations.php',
			'wp-includes/block-supports/aria-label.php',
			'wp-includes/block-supports/anchor.php',
			'wp-includes/block-supports/block-visibility.php',
			'wp-includes/block-supports/custom-css.php',
			'wp-includes/block-supports/states.php',
			'wp-includes/block-supports/duotone.php',
			'wp-includes/taxonomy.php',
			'wp-includes/meta.php',
			'wp-includes/class-wp-post.php',
			'wp-includes/capabilities.php',
			'wp-includes/class-wp-roles.php',
			'wp-includes/class-wp-role.php',
			'wp-includes/class-wp-user.php',
			'wp-includes/class-wp-user-request.php',
			'wp-includes/class-wp-comment.php',
			'wp-includes/class-wp-post-type.php',
			'wp-includes/post.php',
			'wp-includes/revision.php',
			'wp-includes/author-template.php',
			'wp-includes/category.php',
			'wp-includes/category-template.php',
			'wp-includes/post-template.php',
			'wp-includes/post-thumbnail-template.php',
			'wp-includes/post-formats.php',
			'wp-includes/user.php',
			'wp-includes/comment.php',
			'wp-includes/comment-template.php',
			'wp-includes/class-wp-widget.php',
			'wp-includes/class-wp-widget-factory.php',
			'wp-includes/default-widgets.php',
			'wp-includes/widgets/class-wp-widget-block.php',
			'wp-includes/widgets.php',
			'wp-includes/class-wp-walker.php',
			'wp-includes/class-walker-page.php',
			'wp-includes/class-walker-page-dropdown.php',
			'wp-includes/class-walker-category.php',
			'wp-includes/class-walker-category-dropdown.php',
			'wp-includes/class-walker-nav-menu.php',
			'wp-includes/nav-menu-template.php',
			'wp-includes/nav-menu.php',
			'wp-includes/class-wp-meta-query.php',
			'wp-includes/class-wp-tax-query.php',
			'wp-includes/class-wp-date-query.php',
			'wp-includes/class-wp-site.php',
			'wp-includes/class-wp-network.php',
			'wp-includes/class-wp-site-query.php',
			'wp-includes/class-wp-network-query.php',
			'wp-includes/ms-load.php',
			'wp-includes/ms-site.php',
			'wp-includes/ms-network.php',
			'wp-includes/ms-blogs.php',
			'wp-includes/ms-functions.php',
			'wp-includes/class-wp-query.php',
			'wp-includes/query.php',
			'wp-includes/class-wp-user-query.php',
			'wp-includes/class-wp-comment-query.php',
			'wp-includes/class-wp.php',
			'wp-includes/class-wp-rewrite.php',
			'wp-includes/class-wp-matchesmapregex.php',
			'wp-includes/rewrite.php',
			'wp-includes/canonical.php',
			'wp-includes/template.php',
			'wp-includes/general-template.php',
			'wp-includes/link-template.php',
			'wp-includes/feed.php',
			'wp-includes/class-wp-embed.php',
			'wp-includes/class-wp-oembed.php',
			'wp-includes/embed.php',
			'wp-includes/bookmark.php',
			'wp-includes/bookmark-template.php',
			'wp-includes/robots-template.php',
			'wp-includes/sitemaps/class-wp-sitemaps-provider.php',
			'wp-includes/sitemaps/class-wp-sitemaps-registry.php',
			'wp-includes/sitemaps/class-wp-sitemaps-renderer.php',
			'wp-includes/sitemaps/class-wp-sitemaps-index.php',
			'wp-includes/sitemaps/class-wp-sitemaps.php',
			'wp-includes/sitemaps.php',
			'wp-includes/rest-api/class-wp-rest-request.php',
			'wp-includes/rest-api/class-wp-rest-response.php',
			'wp-includes/rest-api/class-wp-rest-server.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-controller.php',
			'wp-includes/rest-api.php',
			'wp-includes/rest-api/search/class-wp-rest-search-handler.php',
			'wp-includes/rest-api/search/class-wp-rest-post-search-handler.php',
			'wp-includes/rest-api/search/class-wp-rest-term-search-handler.php',
			'wp-includes/rest-api/search/class-wp-rest-post-format-search-handler.php',
			'wp-includes/rest-api/fields/class-wp-rest-meta-fields.php',
			'wp-includes/rest-api/fields/class-wp-rest-post-meta-fields.php',
			'wp-includes/rest-api/fields/class-wp-rest-term-meta-fields.php',
			'wp-includes/rest-api/fields/class-wp-rest-comment-meta-fields.php',
			'wp-includes/rest-api/fields/class-wp-rest-user-meta-fields.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-attachments-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-revisions-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-terms-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-menus-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-menu-items-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-menu-locations-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-comments-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-application-passwords-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-post-types-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-post-statuses-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-taxonomies-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-search-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-settings-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-block-types-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-block-directory-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-block-patterns-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-block-pattern-categories-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-pattern-directory-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-themes-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-sidebars-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-widget-types-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-widgets-controller.php',
			'wp-includes/rest-api/endpoints/class-wp-rest-url-details-controller.php',
			'wp-includes/abilities-api/class-wp-ability-category.php',
			'wp-includes/abilities-api/class-wp-ability-categories-registry.php',
			'wp-includes/abilities-api/class-wp-ability.php',
			'wp-includes/abilities-api/class-wp-abilities-registry.php',
			'wp-includes/abilities-api.php',
			'wp-includes/PHPMailer/Exception.php',
			'wp-includes/PHPMailer/PHPMailer.php',
			'wp-includes/PHPMailer/SMTP.php',
			'wp-includes/class-wp-phpmailer.php',
			'wp-includes/class-phpass.php',
			'wp-includes/class-wp-session-tokens.php',
			'wp-includes/class-wp-user-meta-session-tokens.php',
			'wp-includes/class-wp-plugin-dependencies.php',
			'wp-includes/pluggable.php',
			'wp-includes/class-wp-application-passwords.php',
			'wp-includes/update.php',
			'wp-includes/class-IXR.php',
			'wp-includes/class-wp-xmlrpc-server.php',
			'wp-includes/class-wp-admin-bar.php',
			'wp-includes/admin-bar.php',
			'wp-includes/class-wp-customize-setting.php',
			'wp-includes/class-wp-customize-panel.php',
			'wp-includes/class-wp-customize-section.php',
			'wp-includes/class-wp-customize-control.php',
			'wp-includes/class-wp-customize-manager.php',
			'wp-includes/customize/class-wp-customize-selective-refresh.php',
			'wp-includes/customize/class-wp-customize-partial.php',
			'wp-admin/includes/file.php',
			'wp-admin/includes/image.php',
			'wp-admin/includes/media.php',
			'wp-admin/includes/image-edit.php',
			'wp-admin/includes/import.php',
			'wp-admin/includes/class-wp-importer.php',
			'wp-admin/includes/plugin-install.php',
			'wp-admin/includes/plugin.php',
			'wp-admin/includes/theme.php',
			'wp-admin/includes/ms.php',
			'wp-admin/includes/class-wp-screen.php',
			'wp-admin/includes/class-wp-community-events.php',
			'wp-admin/includes/screen.php',
			'wp-admin/includes/class-wp-list-table.php',
			'wp-admin/includes/class-wp-list-table-compat.php',
			'wp-admin/includes/list-table.php',
			'wp-admin/includes/class-wp-comments-list-table.php',
			'wp-admin/includes/class-wp-post-comments-list-table.php',
			'wp-admin/includes/post.php',
			'wp-admin/includes/revision.php',
			'wp-admin/includes/taxonomy.php',
			'wp-admin/includes/template.php',
			'wp-admin/includes/meta-boxes.php',
			'wp-admin/includes/update.php',
			'wp-admin/includes/class-wp-site-health.php',
			'wp-admin/includes/class-wp-privacy-policy-content.php',
			'wp-admin/includes/privacy-tools.php',
			'wp-admin/includes/class-wp-filesystem-base.php',
			'wp-admin/includes/class-wp-filesystem-direct.php',
			'wp-admin/includes/class-wp-upgrader.php',
		);

		foreach ( $files as $file ) {
			$path = $src . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'init', 'wp_schedule_update_checks' );
		}

		require_once __DIR__ . '/wp-stubs.php';

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array_merge(
					array(
						'home'    => 'http://example.test',
						'siteurl' => 'http://example.test',
					),
					$GLOBALS['wpdb']->component_fuzz_get_options()
				)
			);
		}

		if ( function_exists( 'wp_cache_init' ) && ! isset( $GLOBALS['wp_object_cache'] ) ) {
			wp_cache_init();
		}

		if ( ! isset( $GLOBALS['blog_id'] ) ) {
			$GLOBALS['blog_id'] = 1;
		}
		if ( ! isset( $GLOBALS['table_prefix'] ) ) {
			$GLOBALS['table_prefix'] = 'wp_';
		}
		if ( ! isset( $GLOBALS['wp_plugin_paths'] ) || ! is_array( $GLOBALS['wp_plugin_paths'] ) ) {
			$GLOBALS['wp_plugin_paths'] = array();
		}
		if ( ! isset( $GLOBALS['_wp_switched_stack'] ) || ! is_array( $GLOBALS['_wp_switched_stack'] ) ) {
			$GLOBALS['_wp_switched_stack'] = array();
		}
		if ( ! isset( $GLOBALS['switched'] ) ) {
			$GLOBALS['switched'] = false;
		}

		if ( class_exists( 'WP_Site' ) && ! isset( $GLOBALS['current_blog'] ) ) {
			$default_site = (object) array(
				'blog_id'      => '1',
				'domain'       => 'example.test',
				'path'         => '/',
				'site_id'      => '1',
				'registered'   => '2026-06-22 00:00:00',
				'last_updated' => '2026-06-22 00:00:00',
				'public'       => '1',
				'archived'     => '0',
				'mature'       => '0',
				'spam'         => '0',
				'deleted'      => '0',
				'lang_id'      => '0',
			);

			$GLOBALS['current_blog'] = new \WP_Site( $default_site );

			if ( function_exists( 'wp_cache_set' ) ) {
				wp_cache_set( 1, $default_site, 'sites' );
			}
		}

		if ( class_exists( 'WP_Network' ) && ! isset( $GLOBALS['current_site'] ) ) {
			$default_network = (object) array(
				'id'            => '1',
				'domain'        => 'example.test',
				'path'          => '/',
				'blog_id'       => '1',
				'cookie_domain' => 'example.test',
				'site_name'     => 'Component Fuzz Network',
			);

			$GLOBALS['current_site'] = new \WP_Network( $default_network );

			if ( function_exists( 'wp_cache_set' ) ) {
				wp_cache_set( 1, $default_network, 'networks' );
			}
		}

		if ( class_exists( 'WP_Textdomain_Registry' ) && ! isset( $GLOBALS['wp_textdomain_registry'] ) ) {
			$GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();
			$GLOBALS['wp_textdomain_registry']->init();
		}

		if ( class_exists( 'WP_Locale' ) && ! isset( $GLOBALS['wp_locale'] ) ) {
			$GLOBALS['wp_locale'] = new \WP_Locale();
		}

		if ( class_exists( 'WP_Widget_Factory' ) && ! isset( $GLOBALS['wp_widget_factory'] ) ) {
			$GLOBALS['wp_widget_factory'] = new \WP_Widget_Factory();
		}

		if ( class_exists( 'WP_AI_Client_Discovery_Strategy' ) ) {
			\WP_AI_Client_Discovery_Strategy::init();
		}

		if ( class_exists( 'WordPress\AiClient\AiClient' ) ) {
			if ( class_exists( 'WP_AI_Client_Cache' ) ) {
				\WordPress\AiClient\AiClient::setCache( new \WP_AI_Client_Cache() );
			}
			if ( class_exists( 'WP_AI_Client_Event_Dispatcher' ) ) {
				\WordPress\AiClient\AiClient::setEventDispatcher( new \WP_AI_Client_Event_Dispatcher() );
			}
		}

		self::$loaded = true;
	}

	private static function reset_temp_content_dir( string $dir ): void {
		if ( file_exists( $dir ) ) {
			self::remove_temp_content_dir( $dir );
		}

		foreach ( array( $dir, $dir . '/plugins', $dir . '/mu-plugins', $dir . '/themes', $dir . '/languages' ) as $path ) {
			if ( ! is_dir( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
				throw new \RuntimeException( 'Could not create component fuzz content directory: ' . $path );
			}
		}
	}

	public static function remove_temp_content_dir( string $dir ): void {
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-wp-content-';
		if ( ! str_starts_with( $dir, $temp_prefix ) || ! file_exists( $dir ) ) {
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
}
