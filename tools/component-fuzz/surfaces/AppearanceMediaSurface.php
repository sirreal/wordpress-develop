<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-upload appearance media helpers for custom backgrounds, headers, and site icons.
 */
final class AppearanceMediaSurface {
	public const NAME = 'appearance-media';

	private const MAX_FAILURES = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_support();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'appearance-media.bootstrap-apis-available',
					'Required appearance media APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::prepare_runtime( $ctx );

			$rows[] = self::check_background_post_normalization( $ctx->fork( 'background' ) );
			$rows[] = self::check_header_defaults_and_selection( $ctx->fork( 'headers' ) );
			$rows[] = self::check_header_and_background_frontend_helpers( $ctx->fork( 'frontend' ) );
			$rows[] = self::check_head_callback_css( $ctx->fork( 'head-callback-css' ) );
			$rows[] = self::check_custom_header_markup_and_video( $ctx->fork( 'custom-header-video' ) );
			$rows[] = self::check_custom_header_markup_print_side_effects( $ctx->fork( 'custom-header-print' ) );
			$rows[] = self::check_custom_logo_helpers( $ctx->fork( 'custom-logo' ) );
			$rows[] = self::check_site_icon_helpers( $ctx->fork( 'site-icon' ) );
			$rows[] = self::check_site_icon_attachment_urls( $ctx->fork( 'site-icon-attachment-urls' ) );
			$rows[] = self::check_admin_action_guards( $ctx->fork( 'admin-action-guards' ) );
			$rows[] = self::check_background_remove_redirect( $ctx->fork( 'background-remove-redirect' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'appearance-media.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$state_diff = self::state_diff( $snapshot );
		$rows[]     = self::row(
			$ctx,
			'appearance-media.state-restored',
			array() === $state_diff,
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'trackedOptions' => array_keys( $snapshot['options'] ),
				'contentCounts'  => $snapshot['contentCounts'],
				'obLevel'        => ob_get_level(),
				'diff'           => $state_diff,
			)
		);

		return $rows;
	}

	private static function load_support(): void {
		$files = array(
			'Custom_Background'    => defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/class-custom-background.php' : '',
			'Custom_Image_Header' => defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/class-custom-image-header.php' : '',
			'WP_Site_Icon'        => defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/class-wp-site-icon.php' : '',
		);

		foreach ( $files as $class => $path ) {
			if ( ! class_exists( $class, false ) && $path && file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach ( array( 'Custom_Background', 'Custom_Image_Header', 'WP_Site_Icon' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}
		if ( ! class_exists( 'WP_Scripts', false ) ) {
			$missing[] = 'class WP_Scripts';
		}
		if ( ! class_exists( 'Component_Fuzz_WPDB_Stub', false ) ) {
			$missing[] = 'class Component_Fuzz_WPDB_Stub';
		}

		foreach (
			array(
				'add_filter',
				'add_theme_support',
				'admin_url',
				'apply_filters',
				'checked',
				'check_admin_referer',
				'create_initial_post_types',
				'current_user_can',
				'current_theme_supports',
				'delete_option',
				'display_header_text',
				'esc_attr',
				'esc_url',
				'get_background_color',
				'get_background_image',
				'get_custom_logo',
				'get_custom_header',
				'get_custom_header_markup',
				'get_header_image',
				'get_header_image_tag',
				'get_header_textcolor',
				'get_header_video_settings',
				'get_header_video_url',
				'get_option',
				'get_post_meta',
				'get_site_icon_url',
				'get_stylesheet',
				'get_template_directory_uri',
				'get_theme_mod',
				'get_theme_support',
				'has_custom_logo',
				'has_custom_header',
				'has_filter',
				'has_header_image',
				'has_header_video',
				'has_site_icon',
				'home_url',
				'is_header_video_active',
				'is_random_header_image',
				'maybe_hash_hex_color',
				'remove_all_filters',
				'remove_filter',
				'remove_theme_support',
				'remove_theme_mod',
				'sanitize_html_class',
				'sanitize_url',
				'set_url_scheme',
				'set_theme_mod',
				'site_icon_url',
				'the_custom_logo',
				'the_custom_header_markup',
				'the_header_video_url',
				'_custom_background_cb',
				'_custom_logo_header_styles',
				'update_option',
				'update_post_meta',
				'wp_check_filetype',
				'wp_create_nonce',
				'wp_die',
				'wp_get_attachment_image_src',
				'wp_get_attachment_image_url',
				'wp_get_attachment_metadata',
				'wp_get_attachment_url',
				'wp_get_referer',
				'wp_get_mime_types',
				'wp_get_upload_dir',
				'wp_enqueue_script',
				'wp_localize_script',
				'wp_nonce_ays',
				'wp_nonce_tick',
				'wp_register_script',
				'wp_script_is',
				'wp_scripts',
				'wp_update_attachment_metadata',
				'wp_verify_nonce',
				'wp_cache_delete',
				'wp_json_encode',
				'wp_redirect',
				'wp_safe_redirect',
				'wp_sanitize_redirect',
				'wp_site_icon',
				'wp_validate_redirect',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$missing[] = 'global wpdb Component_Fuzz_WPDB_Stub';
		}

		return $missing;
	}

	private static function prepare_runtime( \ComponentFuzz\FuzzContext $ctx ): void {
		unset( $ctx );

		if ( ! get_post_type_object( 'post' ) || ! get_post_status_object( 'publish' ) ) {
			\create_initial_post_types();
		}

		\update_option( 'template', 'component-fuzz-theme' );
		\update_option( 'stylesheet', 'component-fuzz-theme' );
		\update_option( self::theme_mod_option_name(), array() );

		\add_theme_support(
			'custom-background',
			array(
				'default-color'      => 'f0f0f0',
				'default-image'      => 'http://example.test/default-background.png',
				'default-preset'     => 'fill',
				'default-position-x' => 'left',
				'default-position-y' => 'top',
				'default-size'       => 'auto',
				'default-repeat'     => 'repeat',
				'default-attachment' => 'scroll',
			)
		);
		\add_theme_support(
			'custom-header',
			array(
				'default-image'      => '%s/images/default-header.jpg',
				'default-text-color' => '123456',
				'header-text'        => true,
				'width'              => 1200,
				'height'             => 300,
				'random-default'     => true,
			)
		);

		$GLOBALS['_wp_default_headers'] = self::default_headers_case();
	}

	private static function check_background_post_normalization( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$case     = self::background_case( $ctx );
		$nonce    = \wp_create_nonce( 'custom-background' );
		$subject  = new \Custom_Background();

		$_POST = array(
			'_wpnonce'              => $nonce,
			'background-preset'     => $case['presetInput'],
			'background-position'   => $case['positionInput'],
			'background-size'       => $case['sizeInput'],
			'background-repeat'     => $case['repeatInput'],
			'background-attachment' => $case['attachmentInput'],
			'background-color'      => $case['colorInput'],
		);
		$_REQUEST = $_POST;

		$subject->take_action();

		$actual = array(
			'preset'     => \get_theme_mod( 'background_preset' ),
			'positionX'  => \get_theme_mod( 'background_position_x' ),
			'positionY'  => \get_theme_mod( 'background_position_y' ),
			'size'       => \get_theme_mod( 'background_size' ),
			'repeat'     => \get_theme_mod( 'background_repeat' ),
			'attachment' => \get_theme_mod( 'background_attachment' ),
			'color'      => \get_theme_mod( 'background_color' ),
		);

		self::collect_failure(
			$failures,
			$case['expected'] === $actual,
			'Custom_Background::take_action normalizes bounded display options into theme mods',
			array(
				'case'     => $case,
				'actual'   => $actual,
				'themeMod' => \get_option( self::theme_mod_option_name() ),
			)
		);

		return self::row(
			$ctx,
			'appearance-media.background.post-normalization',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_header_defaults_and_selection( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$subject  = new \Custom_Image_Header( static function (): void {} );
		$subject->process_default_headers();

		$template_uri   = \get_template_directory_uri();
		$stylesheet_uri = \get_stylesheet_directory_uri();
		$header_alpha   = $subject->default_headers['alpha'] ?? null;
		$header_beta    = $subject->default_headers['beta'] ?? null;

		self::collect_failure(
			$failures,
			is_array( $header_alpha )
				&& is_array( $header_beta )
				&& "{$template_uri}/images/alpha.jpg" === $header_alpha['url']
				&& "{$stylesheet_uri}/images/alpha-thumb.jpg" === $header_alpha['thumbnail_url']
				&& "{$stylesheet_uri}/images/beta.jpg" === $header_beta['url'],
			'process_default_headers expands template and stylesheet placeholders once',
			array(
				'alpha'        => $header_alpha,
				'beta'         => $header_beta,
				'templateUri'  => $template_uri,
				'stylesheetUri' => $stylesheet_uri,
			)
		);

		$subject->set_header_image( 'alpha' );
		$selected      = \get_theme_mod( 'header_image' );
		$selected_data = \get_theme_mod( 'header_image_data' );
		$output        = self::capture( static fn() => $subject->show_header_selector( 'default' ) );
		$subject->remove_header_image();
		$removed = \get_header_image();
		$subject->set_header_image(
			array(
				'attachment_id' => 991,
				'url'           => 'http://example.test/uploads/generated-header.jpg?unsafe=<tag>',
				'width'         => 1440,
				'height'        => 360,
			)
		);
		$array_data = \get_theme_mod( 'header_image_data' );
		$array_url  = \get_theme_mod( 'header_image' );
		$subject->set_header_image( 'random-default-image' );

		self::collect_failure(
			$failures,
			"{$template_uri}/images/alpha.jpg" === $selected
				&& is_array( $selected_data )
				&& 'Alpha <unsafe>' === ( $selected_data['alt_text'] ?? null )
				&& false === $removed
				&& $array_data instanceof \stdClass
				&& 991 === (int) $array_data->attachment_id
				&& 'http://example.test/uploads/generated-header.jpg?unsafe=tag' === $array_url
				&& \is_random_header_image( 'default' )
				&& str_contains( $output, 'Random:' )
				&& str_contains( $output, 'Alpha &lt;unsafe&gt;' )
				&& ! str_contains( $output, '<unsafe>' ),
			'set_header_image handles default, remove, array, and random choices with escaped selector output',
			array(
				'selected'     => $selected,
				'selectedData' => self::describe_value( $selected_data ),
				'removed'      => $removed,
				'arrayData'    => self::describe_value( $array_data ),
				'arrayUrl'     => $array_url,
				'currentImage' => \get_theme_mod( 'header_image' ),
				'random'       => \is_random_header_image( 'default' ),
				'selector'     => self::preview( $output ),
			)
		);
		$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->postmeta, array( 'post_id' => 991 ) );
		\wp_cache_delete( 991, 'post_meta' );

		return self::row(
			$ctx,
			'appearance-media.header.defaults-and-selection',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_header_and_background_frontend_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();

		\set_theme_mod( 'header_textcolor', 'abcdef' );
		\set_theme_mod( 'header_image', 'http://example.test/header-image.jpg?x=<tag>' );
		\set_theme_mod(
			'header_image_data',
			(object) array(
				'attachment_id' => 0,
				'url'           => 'http://example.test/header-image.jpg?x=<tag>',
				'thumbnail_url' => 'http://example.test/header-thumb.jpg',
				'width'         => 960,
				'height'        => 240,
			)
		);
		\set_theme_mod( 'background_image', 'http://example.test/bg.png?x=<tag>' );
		\set_theme_mod( 'background_color', '#12zz34' );

		$header_image = \get_header_image();
		$header_tag   = \get_header_image_tag(
			array(
				'alt'     => 'Header <alt>',
				'loading' => false,
				'decoding' => false,
			)
		);
		$bg_image     = \get_background_image();
		$bg_color     = \get_background_color();
		$text_color   = \get_header_textcolor();
		$display_text = \display_header_text();
		$expected_header_image = \set_url_scheme( 'http://example.test/header-image.jpg?x=tag' );

		self::collect_failure(
			$failures,
			$expected_header_image === $header_image
				&& str_contains( $header_tag, 'src="' . \esc_attr( $expected_header_image ) . '"' )
				&& str_contains( $header_tag, 'alt="Header &lt;alt&gt;"' )
				&& ! str_contains( $header_tag, 'loading=' )
				&& ! str_contains( $header_tag, 'decoding=' )
				&& 'http://example.test/bg.png?x=<tag>' === $bg_image
				&& '#12zz34' === $bg_color
				&& 'abcdef' === $text_color
				&& true === $display_text,
			'frontend header/background helpers sanitize URLs, escape markup attributes, and expose theme mods consistently',
			array(
				'headerImage' => self::preview( (string) $header_image ),
				'expectedHeaderImage' => self::preview( $expected_header_image ),
				'headerTag'   => self::preview( $header_tag ),
				'bgImage'     => self::preview( (string) $bg_image ),
				'bgColor'     => $bg_color,
				'textColor'   => $text_color,
				'displayText' => $display_text,
			)
		);

		\set_theme_mod( 'header_textcolor', 'blank' );
		self::collect_failure(
			$failures,
			false === \display_header_text(),
			'blank header text color hides header text',
			array( 'displayText' => \display_header_text() )
		);

		return self::row(
			$ctx,
			'appearance-media.frontend.helpers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_head_callback_css( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures              = array();
		$token                 = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $ctx->identifier( 4, 10 ) ) );
		$background_url        = 'http://example.test/component-fuzz/bg-' . rawurlencode( $token ) . '.png?unsafe=<tag>';
		$position_x            = $ctx->choice( array( 'left', 'center', 'right', 'bad-x' ) );
		$position_y            = $ctx->choice( array( 'top', 'center', 'bottom', 'bad-y' ) );
		$size                  = $ctx->choice( array( 'auto', 'contain', 'cover', 'stretch' ) );
		$repeat                = $ctx->choice( array( 'repeat-x', 'repeat-y', 'repeat', 'no-repeat', 'round' ) );
		$attachment            = $ctx->choice( array( 'scroll', 'fixed', 'local' ) );
		$color                 = $ctx->choice( array( 'abc123', '#abc', '112233' ) );
		$expected_position_x   = in_array( $position_x, array( 'left', 'center', 'right' ), true ) ? $position_x : 'left';
		$expected_position_y   = in_array( $position_y, array( 'top', 'center', 'bottom' ), true ) ? $position_y : 'top';
		$expected_size         = in_array( $size, array( 'auto', 'contain', 'cover' ), true ) ? $size : 'auto';
		$expected_repeat       = in_array( $repeat, array( 'repeat-x', 'repeat-y', 'repeat', 'no-repeat' ), true ) ? $repeat : 'repeat';
		$expected_attachment   = 'fixed' === $attachment ? 'fixed' : 'scroll';
		$expected_url          = \sanitize_url( \set_url_scheme( str_replace( '<tag>', 'tag', $background_url ) ) );
		$expected_color        = \maybe_hash_hex_color( $color );
		$had_theme_features    = array_key_exists( '_wp_theme_features', $GLOBALS );
		$theme_features_before = $GLOBALS['_wp_theme_features'] ?? null;
		$logo_css              = '';
		$logo_css_visible      = '';

		try {
			\set_theme_mod( 'background_image', $background_url );
			\set_theme_mod( 'background_color', $color );
			\set_theme_mod( 'background_position_x', $position_x );
			\set_theme_mod( 'background_position_y', $position_y );
			\set_theme_mod( 'background_size', $size );
			\set_theme_mod( 'background_repeat', $repeat );
			\set_theme_mod( 'background_attachment', $attachment );

			$background_css = self::capture( static fn() => \_custom_background_cb() );

			self::collect_failure(
				$failures,
				str_contains( $background_css, '<style id="custom-background-css">' )
					&& str_contains( $background_css, 'body.custom-background' )
					&& str_contains( $background_css, 'background-color: ' . $expected_color . ';' )
					&& str_contains( $background_css, 'background-image: url(' )
					&& str_contains( $background_css, $expected_url )
					&& str_contains( $background_css, "background-position: {$expected_position_x} {$expected_position_y};" )
					&& str_contains( $background_css, "background-size: {$expected_size};" )
					&& str_contains( $background_css, "background-repeat: {$expected_repeat};" )
					&& str_contains( $background_css, "background-attachment: {$expected_attachment};" )
					&& ! str_contains( $background_css, '<tag>' )
					&& ! str_contains( $background_css, 'background-position: bad-x' )
					&& ! str_contains( $background_css, ' bad-y;' )
					&& ! str_contains( $background_css, 'background-size: stretch;' )
					&& ! str_contains( $background_css, 'background-repeat: round;' )
					&& ! str_contains( $background_css, 'background-attachment: local;' ),
				'_custom_background_cb prints escaped CSS and normalizes bounded background style theme mods',
				array(
					'token'              => $token,
					'input'              => compact( 'background_url', 'position_x', 'position_y', 'size', 'repeat', 'attachment', 'color' ),
					'expectedUrl'        => $expected_url,
					'expectedColor'      => $expected_color,
					'expectedPositionX'  => $expected_position_x,
					'expectedPositionY'  => $expected_position_y,
					'expectedSize'       => $expected_size,
					'expectedRepeat'     => $expected_repeat,
					'expectedAttachment' => $expected_attachment,
					'css'                => self::preview( $background_css ),
				)
			);

			\remove_theme_mod( 'background_image' );
			if ( isset( $GLOBALS['_wp_theme_features']['custom-background'][0] ) ) {
				$GLOBALS['_wp_theme_features']['custom-background'][0]['default-image'] = '';
			}
			\set_theme_mod( 'background_color', \get_theme_support( 'custom-background', 'default-color' ) );
			$default_background_css = self::capture( static fn() => \_custom_background_cb() );

			self::collect_failure(
				$failures,
				'' === $default_background_css,
				'_custom_background_cb suppresses empty frontend CSS when only the theme default color is active',
				array( 'css' => self::preview( $default_background_css ) )
			);

			$GLOBALS['_wp_theme_features']['custom-logo'] = array(
				array(
					'header-text' => array( 'site-title<script>', 'site description', $token . '%3cunsafe' ),
				),
			);
			unset( $GLOBALS['_wp_theme_features']['custom-header'] );
			\set_theme_mod( 'header_text', false );

			$logo_css = self::capture( static fn() => \_custom_logo_header_styles() );
			\set_theme_mod( 'header_text', true );
			$logo_css_visible = self::capture( static fn() => \_custom_logo_header_styles() );
		} finally {
			if ( $had_theme_features ) {
				$GLOBALS['_wp_theme_features'] = $theme_features_before;
			} else {
				unset( $GLOBALS['_wp_theme_features'] );
			}
		}

		self::collect_failure(
			$failures,
			str_contains( $logo_css, '<style id="custom-logo-css">' )
				&& str_contains( $logo_css, '.site-titlescript' )
				&& str_contains( $logo_css, '.sitedescription' )
				&& str_contains( $logo_css, '.' . \sanitize_html_class( $token . '%3cunsafe' ) )
				&& str_contains( $logo_css, 'clip-path: inset(50%);' )
				&& '' === $logo_css_visible
				&& ! str_contains( $logo_css, '<script>' )
				&& ! str_contains( $logo_css, '%3c' ),
			'_custom_logo_header_styles prints sanitized hide-header-text CSS only when header text is disabled',
			array(
				'token'       => $token,
				'logoCss'     => self::preview( $logo_css ),
				'visibleCss'  => self::preview( $logo_css_visible ),
			)
		);

		return self::row(
			$ctx,
			'appearance-media.head-callback-css',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_custom_header_markup_and_video( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$token           = strtolower( $ctx->identifier( 4, 10 ) );
		$video_url       = $ctx->choice(
			array(
				'https://www.youtube.com/watch?v=' . rawurlencode( $token ) . '&unsafe=<tag>',
				'https://youtu.be/' . rawurlencode( $token ) . '?unsafe=<tag>',
				'https://example.test/media/' . rawurlencode( $token ) . '.mp4',
				'https://example.test/media/' . rawurlencode( $token ) . '.webm',
			)
		);
		$expected_video  = \set_url_scheme( str_replace( '<tag>', 'tag', $video_url ) );
		$expected_mime   = str_contains( $expected_video, 'youtube.com/watch' ) || str_contains( $expected_video, 'youtu.be/' )
			? 'video/x-youtube'
			: ( str_contains( $expected_video, '.webm' ) ? 'video/webm' : 'video/mp4' );
		$expected_header = \set_url_scheme( 'http://example.test/header-' . rawurlencode( $token ) . '.jpg?unsafe=tag' );
		$active_callback = static fn (): bool => true;
		$force_inactive  = static fn (): bool => false;

		\add_theme_support(
			'custom-header',
			array(
				'video'                 => true,
				'video-active-callback' => $active_callback,
			)
		);
		\set_theme_mod( 'header_image', 'http://example.test/header-' . rawurlencode( $token ) . '.jpg?unsafe=<tag>' );
		\set_theme_mod(
			'header_image_data',
			(object) array(
				'attachment_id' => 0,
				'url'           => 'http://example.test/header-' . rawurlencode( $token ) . '.jpg?unsafe=<tag>',
				'thumbnail_url' => 'http://example.test/header-thumb-' . rawurlencode( $token ) . '.jpg',
				'width'         => 1200,
				'height'        => 300,
			)
		);
		\set_theme_mod( 'external_header_video', $video_url );
		\remove_theme_mod( 'header_video' );

		$header          = \get_custom_header();
		$video           = \get_header_video_url();
		$has_video       = \has_header_video();
		$video_echo      = self::capture( static fn() => \the_header_video_url() );
		$settings        = \get_header_video_settings();
		$markup          = \get_custom_header_markup();
		$has_header      = \has_custom_header();
		$active_before   = \is_header_video_active();
		\add_filter( 'is_header_video_active', $force_inactive );
		try {
			$active_after_filter = \is_header_video_active();
			$has_header_filtered = \has_custom_header();
			$markup_filtered     = \get_custom_header_markup();
		} finally {
			\remove_filter( 'is_header_video_active', $force_inactive );
		}

		\remove_theme_mod( 'header_image' );
		\remove_theme_mod( 'header_image_data' );
		$fallback_markup = \get_custom_header_markup();
		$fallback_header = \has_custom_header();
		\set_theme_mod( 'header_image', 'remove-header' );
		\add_filter( 'is_header_video_active', $force_inactive );
		try {
			$video_only_inactive_header = \has_custom_header();
			$video_only_inactive_markup = \get_custom_header_markup();
		} finally {
			\remove_filter( 'is_header_video_active', $force_inactive );
		}
		\remove_theme_mod( 'external_header_video' );
		\remove_theme_support( 'custom-header' );
		$unsupported_active = \is_header_video_active();

		self::collect_failure(
			$failures,
			$header instanceof \stdClass
				&& 1200 === (int) $header->width
				&& 300 === (int) $header->height
				&& true === (bool) $header->video
				&& $expected_video === $video
				&& \esc_url( $expected_video ) === $video_echo
				&& true === $has_video
				&& true === $has_header
				&& true === $active_before
				&& false === $active_after_filter
				&& true === $has_header_filtered
				&& str_contains( $markup, 'id="wp-custom-header"' )
				&& str_contains( $markup, 'src="' . \esc_attr( $expected_header ) . '"' )
				&& str_contains( $markup, 'unsafe=tag' )
				&& ! str_contains( $markup, '<tag>' )
				&& str_contains( $markup_filtered, 'id="wp-custom-header"' )
				&& $expected_video === ( $settings['videoUrl'] ?? null )
				&& $expected_mime === ( $settings['mimeType'] ?? null )
				&& $expected_header === ( $settings['posterUrl'] ?? null )
				&& 1200 === (int) ( $settings['width'] ?? 0 )
				&& 300 === (int) ( $settings['height'] ?? 0 )
				&& 900 === (int) ( $settings['minWidth'] ?? 0 )
				&& 500 === (int) ( $settings['minHeight'] ?? 0 )
				&& is_array( $settings['l10n'] ?? null )
				&& str_contains( $fallback_markup, 'id="wp-custom-header"' )
				&& str_contains( $fallback_markup, '/images/default-header.jpg' )
				&& true === $fallback_header
				&& false === $video_only_inactive_header
				&& '' === $video_only_inactive_markup
				&& false === $unsupported_active,
			'custom header markup and video helpers sanitize URLs, classify video MIME, and respect active/support gates',
			array(
				'inputVideo'          => $video_url,
				'expectedVideo'       => $expected_video,
				'expectedMime'        => $expected_mime,
				'expectedHeader'      => $expected_header,
				'header'              => self::describe_value( $header ),
				'video'               => self::preview( (string) $video ),
				'videoEcho'           => self::preview( $video_echo ),
				'hasVideo'            => $has_video,
				'hasHeader'           => $has_header,
				'activeBefore'        => $active_before,
				'activeAfterFilter'   => $active_after_filter,
				'hasHeaderFiltered'   => $has_header_filtered,
				'markup'              => self::preview( $markup ),
				'markupFiltered'      => self::preview( $markup_filtered ),
				'settings'            => self::describe_value( $settings ),
				'fallbackMarkup'      => self::preview( $fallback_markup ),
				'fallbackHeader'      => $fallback_header,
				'videoOnlyInactiveHeader' => $video_only_inactive_header,
				'videoOnlyInactiveMarkup' => self::preview( $video_only_inactive_markup ),
				'unsupportedActive'   => $unsupported_active,
			)
		);

		return self::row(
			$ctx,
			'appearance-media.custom-header.markup-video-settings',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_custom_header_markup_print_side_effects( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures               = array();
		$token                  = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $ctx->identifier( 5, 12 ) ) );
		$video_url              = $ctx->choice(
			array(
				'https://www.youtube.com/watch?v=' . rawurlencode( $token ) . '&unsafe=<tag>',
				'https://youtu.be/' . rawurlencode( $token ) . '?unsafe=<tag>',
				'https://example.test/media/' . rawurlencode( $token ) . '.mp4',
				'https://example.test/media/' . rawurlencode( $token ) . '.webm',
			)
		);
		$expected_video         = \set_url_scheme( str_replace( '<tag>', 'tag', $video_url ) );
		$expected_mime          = str_contains( $expected_video, 'youtube.com/watch' ) || str_contains( $expected_video, 'youtu.be/' )
			? 'video/x-youtube'
			: ( str_contains( $expected_video, '.webm' ) ? 'video/webm' : 'video/mp4' );
		$expected_header        = \set_url_scheme( 'http://example.test/printed-header-' . rawurlencode( $token ) . '.jpg?unsafe=tag' );
		$active_callback        = static fn (): bool => true;
		$force_inactive         = static fn (): bool => false;
		$had_scripts            = array_key_exists( 'wp_scripts', $GLOBALS );
		$scripts_before         = $GLOBALS['wp_scripts'] ?? null;
		$register_header_script = static function (): void {
			$GLOBALS['wp_scripts'] = new \WP_Scripts();
			\wp_register_script( 'wp-custom-header', '/wp-includes/js/wp-custom-header.js', array(), false, true );
		};
		$localized_settings     = static function (): ?array {
			$data = \wp_scripts()->get_data( 'wp-custom-header', 'data' );
			if ( ! is_string( $data ) || ! preg_match( '/var _wpCustomHeaderSettings = (.*);\\s*$/s', trim( $data ), $matches ) ) {
				return null;
			}
			$decoded = json_decode( $matches[1], true );
			return is_array( $decoded ) ? $decoded : null;
		};

		try {
			\add_theme_support(
				'custom-header',
				array(
					'video'                 => true,
					'video-active-callback' => $active_callback,
					'width'                 => 1200,
					'height'                => 300,
				)
			);
			\set_theme_mod( 'header_image', 'http://example.test/printed-header-' . rawurlencode( $token ) . '.jpg?unsafe=<tag>' );
			\set_theme_mod(
				'header_image_data',
				(object) array(
					'attachment_id' => 0,
					'url'           => 'http://example.test/printed-header-' . rawurlencode( $token ) . '.jpg?unsafe=<tag>',
					'thumbnail_url' => 'http://example.test/printed-header-thumb-' . rawurlencode( $token ) . '.jpg',
					'width'         => 1200,
					'height'        => 300,
				)
			);
			\set_theme_mod( 'external_header_video', $video_url );
			\remove_theme_mod( 'header_video' );

			$register_header_script();
			$active_expected_markup = \get_custom_header_markup();
			$active_expected_settings = \get_header_video_settings();
			foreach ( $active_expected_settings as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$active_expected_settings[ $key ] = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
				}
			}
			$active_expected_data   = 'var _wpCustomHeaderSettings = ' . \wp_json_encode( $active_expected_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';';
			$active_registered      = \wp_script_is( 'wp-custom-header', 'registered' );
			$active_enqueued_before = \wp_script_is( 'wp-custom-header', 'enqueued' );
			$active_data_before     = \wp_scripts()->get_data( 'wp-custom-header', 'data' );
			$active_markup   = self::capture( static fn() => \the_custom_header_markup() );
			$active_enqueued = \wp_script_is( 'wp-custom-header', 'enqueued' );
			$active_data     = \wp_scripts()->get_data( 'wp-custom-header', 'data' );
			$active_settings = $localized_settings();

			$register_header_script();
			$inactive_expected_markup = \get_custom_header_markup();
			$inactive_registered      = \wp_script_is( 'wp-custom-header', 'registered' );
			$inactive_enqueued_before = \wp_script_is( 'wp-custom-header', 'enqueued' );
			$inactive_data_before     = \wp_scripts()->get_data( 'wp-custom-header', 'data' );
			\add_filter( 'is_header_video_active', $force_inactive );
			try {
				$inactive_markup   = self::capture( static fn() => \the_custom_header_markup() );
				$inactive_enqueued = \wp_script_is( 'wp-custom-header', 'enqueued' );
				$inactive_data     = \wp_scripts()->get_data( 'wp-custom-header', 'data' );
			} finally {
				\remove_filter( 'is_header_video_active', $force_inactive );
			}

			$register_header_script();
			\remove_theme_mod( 'external_header_video' );
			\remove_theme_mod( 'header_video' );
			$no_video_expected_markup = \get_custom_header_markup();
			$no_video_registered      = \wp_script_is( 'wp-custom-header', 'registered' );
			$no_video_enqueued_before = \wp_script_is( 'wp-custom-header', 'enqueued' );
			$no_video_data_before     = \wp_scripts()->get_data( 'wp-custom-header', 'data' );
			$no_video_has_video       = \has_header_video();
			$no_video_markup   = self::capture( static fn() => \the_custom_header_markup() );
			$no_video_enqueued = \wp_script_is( 'wp-custom-header', 'enqueued' );
			$no_video_data     = \wp_scripts()->get_data( 'wp-custom-header', 'data' );
		} finally {
			\remove_filter( 'is_header_video_active', $force_inactive );
			\remove_theme_mod( 'external_header_video' );
			\remove_theme_mod( 'header_video' );
			\remove_theme_mod( 'header_image' );
			\remove_theme_mod( 'header_image_data' );
			\remove_theme_support( 'custom-header' );
			if ( $had_scripts ) {
				$GLOBALS['wp_scripts'] = $scripts_before;
			} else {
				unset( $GLOBALS['wp_scripts'] );
			}
		}

		self::collect_failure(
			$failures,
			true === $active_registered
				&& false === $active_enqueued_before
				&& false === $active_data_before
				&& $active_expected_markup === $active_markup
				&& str_contains( $active_markup, 'id="wp-custom-header"' )
				&& str_contains( $active_markup, 'src="' . \esc_attr( $expected_header ) . '"' )
				&& ! str_contains( $active_markup, '<tag>' )
				&& true === $active_enqueued
				&& is_string( $active_data )
				&& $active_expected_data === $active_data
				&& is_array( $active_settings )
				&& $active_expected_settings === $active_settings
				&& $expected_video === ( $active_settings['videoUrl'] ?? null )
				&& $expected_mime === ( $active_settings['mimeType'] ?? null )
				&& $expected_header === ( $active_settings['posterUrl'] ?? null )
				&& 1200 === (int) ( $active_settings['width'] ?? 0 )
				&& 300 === (int) ( $active_settings['height'] ?? 0 )
				&& true === $inactive_registered
				&& false === $inactive_enqueued_before
				&& false === $inactive_data_before
				&& $inactive_expected_markup === $inactive_markup
				&& str_contains( $inactive_markup, 'id="wp-custom-header"' )
				&& false === $inactive_enqueued
				&& false === $inactive_data
				&& true === $no_video_registered
				&& false === $no_video_enqueued_before
				&& false === $no_video_data_before
				&& $no_video_expected_markup === $no_video_markup
				&& str_contains( $no_video_markup, 'id="wp-custom-header"' )
				&& false === $no_video_has_video
				&& false === $no_video_enqueued
				&& false === $no_video_data
				&& false === \has_filter( 'is_header_video_active', $force_inactive )
				&& ( $had_scripts ? ( ( $GLOBALS['wp_scripts'] ?? null ) === $scripts_before ) : ! array_key_exists( 'wp_scripts', $GLOBALS ) ),
			'the_custom_header_markup prints markup, enqueues and localizes video settings only for active video headers, and restores script state',
			array(
				'token'             => $token,
				'inputVideo'        => $video_url,
				'expectedVideo'     => $expected_video,
				'expectedMime'      => $expected_mime,
				'expectedHeader'    => $expected_header,
				'activeRegistered'  => $active_registered,
				'activeBefore'      => array(
					'enqueued' => $active_enqueued_before,
					'data'     => self::describe_value( $active_data_before ),
				),
				'activeMarkup'      => self::preview( $active_markup ),
				'activeEnqueued'    => $active_enqueued,
				'activeExpectedData' => self::preview( $active_expected_data ),
				'activeData'        => self::describe_value( $active_data ),
				'activeExpectedSettings' => self::describe_value( $active_expected_settings ),
				'activeSettings'    => self::describe_value( $active_settings ),
				'inactiveRegistered' => $inactive_registered,
				'inactiveBefore'    => array(
					'enqueued' => $inactive_enqueued_before,
					'data'     => self::describe_value( $inactive_data_before ),
				),
				'inactiveMarkup'    => self::preview( $inactive_markup ),
				'inactiveEnqueued'  => $inactive_enqueued,
				'inactiveData'      => self::describe_value( $inactive_data ),
				'noVideoRegistered' => $no_video_registered,
				'noVideoBefore'     => array(
					'enqueued' => $no_video_enqueued_before,
					'data'     => self::describe_value( $no_video_data_before ),
				),
				'noVideoMarkup'     => self::preview( $no_video_markup ),
				'noVideoHasVideo'   => $no_video_has_video,
				'noVideoEnqueued'   => $no_video_enqueued,
				'noVideoData'       => self::describe_value( $no_video_data ),
				'filterAfter'       => \has_filter( 'is_header_video_active', $force_inactive ),
				'scriptsRestored'   => $had_scripts ? ( ( $GLOBALS['wp_scripts'] ?? null ) === $scripts_before ) : ! array_key_exists( 'wp_scripts', $GLOBALS ),
			)
		);

		return self::row(
			$ctx,
			'appearance-media.custom-header.print-side-effects',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_custom_logo_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures              = array();
		$case                  = self::custom_logo_case( $ctx );
		$attachment_id         = null;
		$attr_payloads         = array();
		$output_payloads       = array();
		$content_counts_before = self::content_counts();
		$content_counts_after  = null;

		$file_filter = static function ( $file, int $attachment_id_arg ) use ( &$attachment_id, $case ) {
			return ( null !== $attachment_id && $attachment_id_arg === $attachment_id ) ? $case['file'] : $file;
		};
		$source_filter = static function ( $image, int $attachment_id_arg, $size, bool $icon ) use ( &$attachment_id, $case ) {
			unset( $size, $icon );
			return ( null !== $attachment_id && $attachment_id_arg === $attachment_id )
				? array( $case['src'], $case['width'], $case['height'], false )
				: $image;
		};
		$meta_filter = static function ( $value, int $object_id, string $meta_key, bool $single, string $meta_type ) use ( &$attachment_id, $case ) {
			if ( null === $attachment_id || $object_id !== $attachment_id || 'post' !== $meta_type ) {
				return $value;
			}
			if ( '_wp_attachment_image_alt' === $meta_key ) {
				return $single ? $case['altMeta'] : array( $case['altMeta'] );
			}
			return $value;
		};
		$attr_filter = static function ( array $attrs, int $custom_logo_id, int $blog_id ) use ( &$attachment_id, &$attr_payloads, $case ): array {
			$attr_payloads[] = array(
				'attrs' => $attrs,
				'id'    => $custom_logo_id,
				'blog'  => $blog_id,
			);
			if ( null !== $attachment_id && $custom_logo_id === $attachment_id ) {
				$attrs['class']                 .= ' component-fuzz-logo-' . $case['token'];
				$attrs['data-component-fuzz']    = 'logo <' . $case['token'] . '>';
				$attrs['decoding']               = $case['decoding'];
				$attrs['fetchpriority']          = $case['fetchpriority'];
			}
			return $attrs;
		};
		$output_filter = static function ( string $html, int $blog_id ) use ( &$output_payloads, $case ): string {
			$output_payloads[] = array(
				'html' => $html,
				'blog' => $blog_id,
			);
			return $html . '<span data-component-fuzz-logo="' . \esc_attr( $case['token'] ) . '"></span>';
		};

		\add_filter( 'get_attached_file', $file_filter, 10, 2 );
		\add_filter( 'wp_get_attachment_image_src', $source_filter, 10, 4 );
		\add_filter( 'get_post_metadata', $meta_filter, 10, 5 );
		\add_filter( 'get_custom_logo_image_attributes', $attr_filter, 10, 3 );
		\add_filter( 'get_custom_logo', $output_filter, 10, 2 );

		try {
			\add_theme_support( 'custom-logo', $case['support'] );
			$inserted = $GLOBALS['wpdb']->insert(
				$GLOBALS['wpdb']->posts,
				array(
					'post_author'    => 0,
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => $case['title'],
					'post_name'      => $case['slug'],
					'post_mime_type' => $case['mime'],
					'guid'           => $case['src'],
				)
			);
			if ( 1 === $inserted ) {
				$attachment_id = (int) $GLOBALS['wpdb']->insert_id;
			}

			if ( null === $attachment_id || $attachment_id <= 0 ) {
				self::collect_failure(
					$failures,
					false,
					'synthetic logo attachment inserts without live uploads',
					array( 'attachmentId' => self::describe_value( $attachment_id ) )
				);
				$attachment_id = null;
			}

			if ( null !== $attachment_id ) {
				\set_theme_mod( 'custom_logo', $attachment_id );
			}

			$has_logo    = \has_custom_logo();
			$html        = \get_custom_logo( $case['blogId'] );
			$echoed      = self::capture( static fn() => \the_custom_logo( $case['blogId'] ) );
			$support     = \get_theme_support( 'custom-logo' );
			$width       = \get_theme_support( 'custom-logo', 'width' );
			$height      = \get_theme_support( 'custom-logo', 'height' );
			$flex_width  = \current_theme_supports( 'custom-logo', 'flex-width' );
			$flex_height = \current_theme_supports( 'custom-logo', 'flex-height' );

			self::collect_failure(
				$failures,
				null !== $attachment_id
					&& true === $has_logo
					&& is_array( $support )
					&& $case['expectedSupport']['width'] === $width
					&& $case['expectedSupport']['height'] === $height
					&& $case['expectedSupport']['flexWidth'] === $flex_width
					&& $case['expectedSupport']['flexHeight'] === $flex_height
					&& str_contains( $html, 'class="custom-logo-link"' )
					&& str_contains( $html, 'rel="home"' )
					&& str_contains( $html, 'class="custom-logo component-fuzz-logo-' . $case['token'] . '"' )
					&& str_contains( $html, 'src="' . \esc_attr( $case['src'] ) . '"' )
					&& str_contains( $html, 'width="' . $case['width'] . '"' )
					&& str_contains( $html, 'height="' . $case['height'] . '"' )
					&& str_contains( $html, 'alt="' . \esc_attr( $case['expectedAlt'] ) . '"' )
					&& str_contains( $html, 'data-component-fuzz="logo &lt;' . $case['token'] . '&gt;"' )
					&& ( 'invalid' === $case['decoding'] ? ! str_contains( $html, 'decoding=' ) : str_contains( $html, 'decoding="' . $case['decoding'] . '"' ) )
					&& ( '' === $case['fetchpriority'] ? ! str_contains( $html, 'fetchpriority=' ) : str_contains( $html, 'fetchpriority="' . $case['fetchpriority'] . '"' ) )
					&& str_contains( $html, 'data-component-fuzz-logo="' . $case['token'] . '"' )
					&& $html === $echoed
					&& 2 === count( $attr_payloads )
					&& 2 === count( $output_payloads )
					&& ( $attr_payloads[0]['id'] ?? null ) === $attachment_id
					&& ( $attr_payloads[0]['blog'] ?? null ) === $case['blogId']
					&& ( $attr_payloads[1]['id'] ?? null ) === $attachment_id
					&& ( $attr_payloads[1]['blog'] ?? null ) === $case['blogId']
					&& ( $output_payloads[0]['blog'] ?? null ) === $case['blogId'],
				'custom-logo helpers use synthetic image attachments, normalize support args, escape attributes, and preserve filter payloads',
				array(
					'case'           => $case,
					'attachmentId'   => $attachment_id,
					'hasLogo'        => $has_logo,
					'support'        => self::describe_value( $support ),
					'width'          => $width,
					'height'         => $height,
					'flexWidth'      => $flex_width,
					'flexHeight'     => $flex_height,
					'html'           => self::preview( $html ),
					'echoed'         => self::preview( $echoed ),
					'attrPayloads'   => self::describe_value( $attr_payloads ),
					'outputPayloads' => self::describe_value( $output_payloads ),
				)
			);
		} finally {
			\remove_filter( 'get_custom_logo', $output_filter, 10 );
			\remove_filter( 'get_custom_logo_image_attributes', $attr_filter, 10 );
			\remove_filter( 'get_post_metadata', $meta_filter, 10 );
			\remove_filter( 'wp_get_attachment_image_src', $source_filter, 10 );
			\remove_filter( 'get_attached_file', $file_filter, 10 );
			\remove_theme_mod( 'custom_logo' );
			\remove_theme_support( 'custom-logo' );
			if ( null !== $attachment_id ) {
				$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->postmeta, array( 'post_id' => $attachment_id ) );
				$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->posts, array( 'ID' => $attachment_id ) );
				\wp_cache_delete( $attachment_id, 'posts' );
				\wp_cache_delete( $attachment_id, 'post_meta' );
			}
			$content_counts_after = self::content_counts();
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'get_custom_logo', $output_filter )
				&& false === \has_filter( 'get_custom_logo_image_attributes', $attr_filter )
				&& false === \has_filter( 'get_post_metadata', $meta_filter )
				&& false === \has_filter( 'wp_get_attachment_image_src', $source_filter )
				&& false === \has_filter( 'get_attached_file', $file_filter ),
			'custom logo helper filters are removed after the case',
			array(
				'getCustomLogo'         => \has_filter( 'get_custom_logo', $output_filter ),
				'customLogoAttributes'  => \has_filter( 'get_custom_logo_image_attributes', $attr_filter ),
				'getPostMetadata'       => \has_filter( 'get_post_metadata', $meta_filter ),
				'attachmentImageSource' => \has_filter( 'wp_get_attachment_image_src', $source_filter ),
				'getAttachedFile'       => \has_filter( 'get_attached_file', $file_filter ),
			)
		);
		self::collect_failure(
			$failures,
			$content_counts_before === $content_counts_after,
			'custom logo synthetic attachment rows are removed from the in-memory DB stub',
			array(
				'before' => $content_counts_before,
				'after'  => $content_counts_after,
			)
		);

		return self::row(
			$ctx,
			'appearance-media.custom-logo.helpers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_site_icon_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures    = array();
		$site_icon   = new \WP_Site_Icon();
		$size_filter = static function ( array $sizes ) use ( $ctx ): array {
			unset( $sizes );
			return array( 16, 270, $ctx->choice( array( 64, 128, 300 ) ), 640 );
		};
		$url_filter  = static function ( string $url, int $size, int $blog_id ): string {
			unset( $url, $blog_id );
			return 'http://example.test/icon-' . $size . '.png?x=<tag>';
		};
		$meta_filter = static function ( array $tags ): array {
			$tags[] = '<meta name="component-fuzz-site-icon" content="yes" />';
			$tags[] = '';
			return $tags;
		};

		\add_filter( 'site_icon_image_sizes', $size_filter );
		\add_filter( 'get_site_icon_url', $url_filter, 10, 3 );
		\add_filter( 'site_icon_meta_tags', $meta_filter );

		try {
			$additional = $site_icon->additional_sizes(
				array(
					'soft' => array(
						'width'  => 100,
						'height' => 100,
						'crop'   => false,
					),
					'hard' => array(
						'width'  => 90,
						'height' => 90,
						'crop'   => true,
					),
				)
			);
			$intermediate = $site_icon->intermediate_image_sizes( array( 'thumbnail' ) );

			\update_option( 'site_icon', 4242 );
			$metadata_value = $site_icon->get_post_metadata( null, 4242, '_wp_attachment_backup_sizes', true );
			$has_meta_hook  = false !== \has_filter( 'intermediate_image_sizes', array( $site_icon, 'intermediate_image_sizes' ) );
			$site_icon->delete_attachment_data( 4242 );
			$deleted = (int) \get_option( 'site_icon' );

			$icon_url = \get_site_icon_url( 32, '', 0 );
			$has_icon = \has_site_icon();
			$meta     = self::capture( static fn() => \wp_site_icon() );

			self::collect_failure(
				$failures,
				isset( $additional['soft'], $additional['hard'], $additional['site_icon-270'], $additional['site_icon-16'] )
					&& ! isset( $additional['site_icon-640'] )
					&& in_array( 'site_icon-270', $intermediate, true )
					&& in_array( 'site_icon-640', $intermediate, true )
					&& null === $metadata_value
					&& $has_meta_hook
					&& 0 === $deleted
					&& 'http://example.test/icon-32.png?x=<tag>' === $icon_url
					&& true === $has_icon
					&& str_contains( $meta, 'rel="icon"' )
					&& str_contains( $meta, 'sizes="32x32"' )
					&& str_contains( $meta, 'component-fuzz-site-icon' )
					&& str_contains( $meta, 'x=tag' ),
				'WP_Site_Icon sizes, metadata hook, deletion, URL filter, and meta tag output stay coherent',
				array(
					'additional'    => $additional,
					'intermediate'  => $intermediate,
					'metadataValue' => self::describe_value( $metadata_value ),
					'hasMetaHook'   => $has_meta_hook,
					'deleted'       => $deleted,
					'iconUrl'       => self::preview( $icon_url ),
					'hasIcon'       => $has_icon,
					'meta'          => self::preview( $meta ),
				)
			);
		} finally {
			\remove_filter( 'site_icon_image_sizes', $size_filter );
			\remove_filter( 'get_site_icon_url', $url_filter, 10 );
			\remove_filter( 'site_icon_meta_tags', $meta_filter );
			\remove_filter( 'intermediate_image_sizes', array( $site_icon, 'intermediate_image_sizes' ) );
			$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->postmeta, array( 'post_id' => 4242 ) );
			\wp_cache_delete( 4242, 'post_meta' );
		}

		return self::row(
			$ctx,
			'appearance-media.site-icon.helpers',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_site_icon_attachment_urls( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures              = array();
		$token                 = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $ctx->identifier( 5, 12 ) ) );
		$attachment_id         = null;
		$content_counts_before = self::content_counts();
		$content_counts_after  = null;
		$url_payloads          = array();
		$fallback              = 'http://example.test/component-fuzz/site-icon-fallback-' . rawurlencode( $token ) . '.ico?unsafe=tag';
		$uploads_basedir       = '/tmp/component-fuzz-site-icon-' . $token;
		$uploads_baseurl       = 'http://example.test/component-fuzz/site-icons-' . rawurlencode( $token );
		$relative_dir          = 'component-fuzz-site-icons/' . $token;
		$full_file             = 'site-icon-' . $token . '.png';
		$relative_file         = $relative_dir . '/' . $full_file;
		$full_width            = $ctx->int( 512, 1024 );
		$full_height           = $ctx->int( 512, 1024 );
		$full_src              = $uploads_baseurl . '/' . $relative_file;
		$size_file             = static fn( int $size ): string => 'site-icon-' . $token . '-' . $size . '.png';
		$size_src              = static fn( int $size ): string => $uploads_baseurl . '/' . $relative_dir . '/' . $size_file( $size );
		$metadata              = array(
			'width'  => $full_width,
			'height' => $full_height,
			'file'   => $relative_file,
			'sizes'  => array(),
		);
		foreach ( array( 32, 180, 192, 270 ) as $size ) {
			$metadata['sizes'][ 'site_icon-' . $size ] = array(
				'file'      => $size_file( $size ),
				'width'     => $size,
				'height'    => $size,
				'mime-type' => 'image/png',
			);
		}

		$upload_dir_filter = static function ( array $uploads ) use ( $uploads_basedir, $uploads_baseurl ): array {
			unset( $uploads );
			return array(
				'path'    => $uploads_basedir,
				'url'     => $uploads_baseurl,
				'subdir'  => '',
				'basedir' => $uploads_basedir,
				'baseurl' => $uploads_baseurl,
				'error'   => false,
			);
		};
		$url_filter = static function ( string $url, int $size, int $blog_id ) use ( &$url_payloads ): string {
			$url_payloads[] = array(
				'url'  => $url,
				'size' => $size,
				'blog' => $blog_id,
			);
			return $url;
		};

		\add_filter( 'upload_dir', $upload_dir_filter );
		\add_filter( 'get_site_icon_url', $url_filter, 10, 3 );

		try {
			$inserted = $GLOBALS['wpdb']->insert(
				$GLOBALS['wpdb']->posts,
				array(
					'post_author'    => 0,
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => 'Component Fuzz Site Icon <' . $token . '>',
					'post_name'      => 'component-fuzz-site-icon-' . $token,
					'post_mime_type' => 'image/png',
					'guid'           => $full_src,
				)
			);
			if ( 1 === $inserted ) {
				$attachment_id = (int) $GLOBALS['wpdb']->insert_id;
			}

			if ( null === $attachment_id || $attachment_id <= 0 ) {
				self::collect_failure(
					$failures,
					false,
					'synthetic site icon attachment inserts without live uploads',
					array( 'attachmentId' => self::describe_value( $attachment_id ) )
				);
				$attachment_id = null;
			}

			if ( null !== $attachment_id ) {
				\update_post_meta( $attachment_id, '_wp_attached_file', $relative_file );
				\wp_update_attachment_metadata( $attachment_id, $metadata );
				\update_option( 'site_icon', $attachment_id );
			}

			$direct_src        = null !== $attachment_id ? \wp_get_attachment_image_src( $attachment_id, array( 32, 32 ), false ) : false;
			$direct_full       = null !== $attachment_id ? \wp_get_attachment_image_src( $attachment_id, 'full', false ) : false;
			$attachment_url    = null !== $attachment_id ? \wp_get_attachment_url( $attachment_id ) : false;
			$attachment_meta   = null !== $attachment_id ? \wp_get_attachment_metadata( $attachment_id, true ) : false;
			$icon_32          = \get_site_icon_url( 32, $fallback, 0 );
			$icon_full        = \get_site_icon_url( 512, $fallback, 0 );
			$icon_echo        = self::capture( static fn() => \site_icon_url( 32, $fallback, 0 ) );
			$has_icon         = \has_site_icon();
			$meta             = self::capture( static fn() => \wp_site_icon() );

			$stale_id          = $attachment_id ? $attachment_id + 100000 : 100000;
			\update_option( 'site_icon', $stale_id );
			$fallback_url      = \get_site_icon_url( 32, $fallback, 0 );
			$fallback_echo     = self::capture( static fn() => \site_icon_url( 32, $fallback, 0 ) );
			$fallback_has_icon = \has_site_icon();
			$fallback_meta     = self::capture( static fn() => \wp_site_icon() );
		} finally {
			\remove_filter( 'get_site_icon_url', $url_filter, 10 );
			\remove_filter( 'upload_dir', $upload_dir_filter );
			\delete_option( 'site_icon' );
			if ( null !== $attachment_id ) {
				$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->postmeta, array( 'post_id' => $attachment_id ) );
				$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->posts, array( 'ID' => $attachment_id ) );
				\wp_cache_delete( $attachment_id, 'posts' );
				\wp_cache_delete( $attachment_id, 'post_meta' );
			}
			$content_counts_after = self::content_counts();
		}

		self::collect_failure(
			$failures,
			null !== $attachment_id
				&& $size_src( 32 ) === $icon_32
				&& $full_src === $icon_full
				&& \esc_url( $size_src( 32 ) ) === $icon_echo
				&& array( $size_src( 32 ), 32, 32, true ) === $direct_src
				&& array( $full_src, $full_width, $full_height, false ) === $direct_full
				&& $full_src === $attachment_url
				&& $metadata === $attachment_meta
				&& true === $has_icon
				&& str_contains( $meta, 'rel="icon"' )
				&& str_contains( $meta, 'sizes="32x32"' )
				&& str_contains( $meta, 'sizes="192x192"' )
				&& str_contains( $meta, 'rel="apple-touch-icon"' )
				&& str_contains( $meta, 'msapplication-TileImage' )
				&& str_contains( $meta, \esc_url( $size_src( 32 ) ) )
				&& str_contains( $meta, \esc_url( $size_src( 192 ) ) )
				&& str_contains( $meta, \esc_url( $size_src( 180 ) ) )
				&& str_contains( $meta, \esc_url( $size_src( 270 ) ) )
				&& ! str_contains( $meta, '<tag>' )
				&& $fallback === $fallback_url
				&& \esc_url( $fallback ) === $fallback_echo
				&& false === $fallback_has_icon
				&& '' === $fallback_meta
				&& false === \has_filter( 'get_site_icon_url', $url_filter )
				&& false === \has_filter( 'upload_dir', $upload_dir_filter )
				&& $content_counts_before === $content_counts_after,
			'site icon helpers resolve the site_icon option through attachment image URLs, escape meta output, and preserve fallback semantics',
			array(
				'token'               => $token,
				'attachmentId'        => $attachment_id,
				'directSrc'           => self::describe_value( $direct_src ),
				'directFull'          => self::describe_value( $direct_full ),
				'attachmentUrl'       => self::preview( (string) $attachment_url ),
				'attachmentMeta'      => self::describe_value( $attachment_meta ),
				'icon32'              => self::preview( (string) $icon_32 ),
				'iconFull'            => self::preview( (string) $icon_full ),
				'iconEcho'            => self::preview( $icon_echo ),
				'hasIcon'             => $has_icon,
				'meta'                => self::preview( $meta ),
				'fallbackUrl'         => self::preview( (string) $fallback_url ),
				'fallbackEcho'        => self::preview( $fallback_echo ),
				'fallbackHasIcon'     => $fallback_has_icon,
				'fallbackMeta'        => self::preview( $fallback_meta ),
				'urlPayloads'         => self::describe_value( $url_payloads ),
				'contentCountsBefore' => $content_counts_before,
				'contentCountsAfter'  => $content_counts_after,
			)
		);

		return self::row(
			$ctx,
			'appearance-media.site-icon.attachment-url-fallbacks',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function check_admin_action_guards( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$header     = new \Custom_Image_Header( static function (): void {} );
		$background = new \Custom_Background();
		\add_theme_support(
			'custom-header',
			array(
				'default-image'      => '%s/images/default-header.jpg',
				'default-text-color' => '123456',
				'header-text'        => true,
				'width'              => 1200,
				'height'             => 300,
				'random-default'     => true,
			)
		);
		$header->process_default_headers();

		$upload_nonce = \wp_create_nonce( 'custom-header-upload' );
		$crop_nonce   = \wp_create_nonce( 'custom-header-crop-image' );
		$step_cases   = array(
			array(
				'label'    => 'default',
				'get'      => array(),
				'request'  => array(),
				'expected' => 1,
			),
			array(
				'label'    => 'valid-upload-step',
				'get'      => array( 'step' => '2' ),
				'request'  => array( '_wpnonce-custom-header-upload' => $upload_nonce ),
				'expected' => 2,
			),
			array(
				'label'    => 'invalid-upload-step',
				'get'      => array( 'step' => '2' ),
				'request'  => array( '_wpnonce-custom-header-upload' => 'invalid-' . $ctx->identifier( 4, 8 ) ),
				'expected' => 1,
			),
			array(
				'label'    => 'valid-crop-step',
				'get'      => array( 'step' => '3' ),
				'request'  => array( '_wpnonce' => $crop_nonce ),
				'expected' => 3,
			),
			array(
				'label'    => 'out-of-range-step',
				'get'      => array( 'step' => '9' ),
				'request'  => array(
					'_wpnonce-custom-header-upload' => $upload_nonce,
					'_wpnonce'                      => $crop_nonce,
				),
				'expected' => 1,
			),
		);

		$step_actual = array();
		foreach ( $step_cases as $case ) {
			$_GET     = $case['get'];
			$_REQUEST = array_merge( $case['get'], $case['request'] );
			$step_actual[ $case['label'] ] = $header->step();
		}

		self::collect_failure(
			$failures,
			array_column( $step_cases, 'expected', 'label' ) === $step_actual,
			'Custom_Image_Header::step accepts only bounded valid nonce-backed admin steps',
			array(
				'expected' => array_column( $step_cases, 'expected', 'label' ),
				'actual'   => $step_actual,
			)
		);

		$options_nonce = \wp_create_nonce( 'custom-header-options' );
		\set_theme_mod( 'header_textcolor', '112233' );
		\set_theme_mod( 'header_image', 'http://example.test/header-before-denied.jpg' );
		\set_theme_mod(
			'header_image_data',
			(object) array(
				'attachment_id' => 0,
				'url'           => 'http://example.test/header-before-denied.jpg',
				'thumbnail_url' => 'http://example.test/header-before-denied-thumb.jpg',
				'width'         => 1200,
				'height'        => 300,
			)
		);

		$_POST    = array(
			'_wpnonce-custom-header-options' => $options_nonce,
			'text-color'                     => '#abcdef',
			'display-header-text'            => '1',
		);
		$_REQUEST = $_POST;

		$deny_filter          = self::install_cap_filter( array( 'edit_theme_options' ), false );
		$deny_filter_restored = false;
		try {
			$header->take_action();
			$denied_text_color = \get_theme_mod( 'header_textcolor' );
			$denied_image      = \get_theme_mod( 'header_image' );
		} finally {
			$deny_filter_restored = self::remove_cap_filter( $deny_filter );
		}

		$grant_filter          = self::install_cap_filter( array( 'edit_theme_options' ), true );
		$grant_filter_restored = false;
		try {
			$_POST    = array();
			$_REQUEST = array();
			$header->take_action();
			$empty_post_text_color = \get_theme_mod( 'header_textcolor' );

			$_POST    = array(
				'_wpnonce-custom-header-options' => $options_nonce,
				'text-color'                     => '#a1!b2?c3',
				'display-header-text'            => '1',
			);
			$_REQUEST = $_POST;
			$header->take_action();
			$visible_text_color = \get_theme_mod( 'header_textcolor' );

			$_POST    = array(
				'_wpnonce-custom-header-options' => $options_nonce,
				'text-color'                     => '#123456',
			);
			$_REQUEST = $_POST;
			$header->take_action();
			$hidden_text_color = \get_theme_mod( 'header_textcolor' );

			$_POST    = array(
				'_wpnonce-custom-header-options' => $options_nonce,
				'default-header'                 => 'beta',
			);
			$_REQUEST = $_POST;
			$header->take_action();
			$selected_header      = \get_theme_mod( 'header_image' );
			$selected_header_data = \get_theme_mod( 'header_image_data' );

			$_POST    = array(
				'_wpnonce-custom-header-options' => $options_nonce,
				'removeheader'                   => '1',
			);
			$_REQUEST = $_POST;
			$header->take_action();
			$removed_header      = \get_theme_mod( 'header_image' );
			$removed_header_data = \get_theme_mod( 'header_image_data', false );

			$_POST    = array(
				'_wpnonce-custom-header-options' => $options_nonce,
				'resetheader'                    => '1',
			);
			$_REQUEST = $_POST;
			$header->take_action();
			$reset_header      = \get_theme_mod( 'header_image' );
			$reset_header_data = \get_theme_mod( 'header_image_data' );
		} finally {
			$grant_filter_restored = self::remove_cap_filter( $grant_filter );
		}

		$template_uri   = \get_template_directory_uri();
		$stylesheet_uri = \get_stylesheet_directory_uri();
		$expected_beta  = "{$stylesheet_uri}/images/beta.jpg";
		$expected_reset = "{$template_uri}/images/default-header.jpg";

		self::collect_failure(
			$failures,
			'112233' === $denied_text_color
				&& 'http://example.test/header-before-denied.jpg' === $denied_image
				&& $deny_filter_restored
				&& '112233' === $empty_post_text_color
				&& 'a1b2c3' === $visible_text_color
				&& 'blank' === $hidden_text_color
				&& $expected_beta === $selected_header
				&& is_array( $selected_header_data )
				&& 'Beta & Header' === ( $selected_header_data['alt_text'] ?? null )
				&& 'remove-header' === $removed_header
				&& false === $removed_header_data
				&& $expected_reset === $reset_header
				&& $reset_header_data instanceof \stdClass
				&& $grant_filter_restored,
			'Custom_Image_Header::take_action no-upload branches honor caps, nonces, sanitization, default selection, and reset',
			array(
				'deniedTextColor'      => $denied_text_color,
				'deniedImage'          => $denied_image,
				'denyFilterRestored'   => $deny_filter_restored,
				'emptyPostTextColor'   => $empty_post_text_color,
				'visibleTextColor'     => $visible_text_color,
				'hiddenTextColor'      => $hidden_text_color,
				'selectedHeader'       => $selected_header,
				'selectedHeaderData'   => self::describe_value( $selected_header_data ),
				'removedHeader'        => $removed_header,
				'removedHeaderData'    => self::describe_value( $removed_header_data ),
				'resetHeader'          => $reset_header,
				'resetHeaderData'      => self::describe_value( $reset_header_data ),
				'grantFilterRestored'  => $grant_filter_restored,
			)
		);

		$fields = array(
			'component-fuzz' => array(
				'label' => 'Component <fuzz>',
				'input' => 'html',
			),
		);
		$tabs   = array(
			'type' => 'upload',
			'url'  => 'http://example.test/upload?x=<tag>',
		);

		$background_before = \get_theme_mod( 'background_image', false );
		$_FILES            = array();
		$background->handle_upload();
		$background_after = \get_theme_mod( 'background_image', false );

		\set_theme_mod( 'background_image', 'http://example.test/background-before-reset.jpg' );
		\set_theme_mod( 'background_image_thumb', 'http://example.test/background-before-reset-thumb.jpg' );
		$_POST    = array();
		$_REQUEST = array();
		$background->take_action();
		$background_empty_image = \get_theme_mod( 'background_image', false );
		$background_reset_nonce = \wp_create_nonce( 'custom-background-reset' );
		$_POST    = array(
			'_wpnonce-custom-background-reset' => $background_reset_nonce,
			'reset-background'                 => '1',
		);
		$_REQUEST = $_POST;
		$background->take_action();
		$background_reset_image = \get_theme_mod( 'background_image', false );
		$background_reset_thumb = \get_theme_mod( 'background_image_thumb', false );

		self::collect_failure(
			$failures,
			$fields === $header->attachment_fields_to_edit( $fields )
				&& $tabs === $header->filter_upload_tabs( $tabs )
				&& $fields === $background->attachment_fields_to_edit( $fields )
				&& $tabs === $background->filter_upload_tabs( $tabs )
				&& $background_before === $background_after
				&& 'http://example.test/background-before-reset.jpg' === $background_empty_image
				&& false === $background_reset_image
				&& false === $background_reset_thumb,
			'deprecated media field/tab callbacks are passthrough and empty/reset background admin branches are no-file no-ops',
			array(
				'fields'                 => self::describe_value( $fields ),
				'tabs'                   => self::describe_value( $tabs ),
				'backgroundBefore'       => self::describe_value( $background_before ),
				'backgroundAfter'        => self::describe_value( $background_after ),
				'backgroundEmptyImage'   => self::describe_value( $background_empty_image ),
				'backgroundResetImage'   => self::describe_value( $background_reset_image ),
				'backgroundResetThumb'   => self::describe_value( $background_reset_thumb ),
			)
		);

		return self::row(
			$ctx,
			'appearance-media.admin-action-guards.no-upload',
			array() === $failures,
			array(
				'failures'   => array_slice( $failures, 0, self::MAX_FAILURES ),
				'notCovered' => 'Real custom header/background uploads, crop image processing, AJAX JSON senders, and admin-page dispatch remain intentionally out of this no-exit surface.',
			)
		);
	}

	private static function check_background_remove_redirect( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$background = new \Custom_Background();
		$token      = $ctx->identifier( 6, 10 );
		$nonce      = \wp_create_nonce( 'custom-background-remove' );
		$events     = array();
		$recorder   = static function ( $action, $result ) use ( &$events ): void {
			$events[] = array(
				'action' => $action,
				'result' => $result,
			);
		};

		\add_filter( 'check_admin_referer', $recorder, 10, 2 );
		try {
			\set_theme_mod( 'background_image', 'http://example.test/background-before-remove-' . $token . '.jpg' );
			\set_theme_mod( 'background_image_thumb', 'http://example.test/background-before-remove-' . $token . '-thumb.jpg' );
			$safe_referer = \admin_url( 'themes.php?page=custom-background&updated=1&marker=' . rawurlencode( $token ) );
			$safe_capture = self::capture_background_action(
				$background,
				array(
					'_wp_http_referer'                 => $safe_referer,
					'_wpnonce-custom-background-remove' => $nonce,
					'remove-background'                => '1',
				)
			);
			$safe_image   = \get_theme_mod( 'background_image', null );
			$safe_thumb   = \get_theme_mod( 'background_image_thumb', null );

			$fallback_url    = \admin_url( 'themes.php?page=custom-background-fallback&marker=' . rawurlencode( $token ) );
			$fallback_events = array();
			$fallback_filter = static function ( string $fallback, int $status ) use ( &$fallback_events, $fallback_url ): string {
				$fallback_events[] = array(
					'fallback' => $fallback,
					'status'   => $status,
				);
				return $fallback_url;
			};

			\set_theme_mod( 'background_image', 'http://example.test/background-before-external-' . $token . '.jpg' );
			\set_theme_mod( 'background_image_thumb', 'http://example.test/background-before-external-' . $token . '-thumb.jpg' );
			\add_filter( 'wp_safe_redirect_fallback', $fallback_filter, 10, 2 );
			try {
				$external_capture = self::capture_background_action(
					$background,
					array(
						'_wp_http_referer'                 => 'https://not-example.invalid/custom-background?marker=' . rawurlencode( $token ),
						'_wpnonce-custom-background-remove' => $nonce,
						'remove-background'                => '1',
					)
				);
			} finally {
				\remove_filter( 'wp_safe_redirect_fallback', $fallback_filter, 10 );
			}
			$external_image = \get_theme_mod( 'background_image', null );
			$external_thumb = \get_theme_mod( 'background_image_thumb', null );

			\set_theme_mod( 'background_image', 'http://example.test/background-before-invalid-' . $token . '.jpg' );
			\set_theme_mod( 'background_image_thumb', 'http://example.test/background-before-invalid-' . $token . '-thumb.jpg' );
			$invalid_capture = self::capture_background_action(
				$background,
				array(
					'_wp_http_referer'                 => $safe_referer,
					'_wpnonce-custom-background-remove' => 'invalid-' . $token,
					'remove-background'                => '1',
				)
			);
			$invalid_image   = \get_theme_mod( 'background_image', null );
			$invalid_thumb   = \get_theme_mod( 'background_image_thumb', null );
		} finally {
			\remove_filter( 'check_admin_referer', $recorder, 10 );
		}

		$safe_redirect     = $safe_capture['redirects'][0] ?? array();
		$external_redirect = $external_capture['redirects'][0] ?? array();
		$invalid_die       = $invalid_capture['die'];
		$invalid_args      = is_array( $invalid_die['args'] ?? null ) ? $invalid_die['args'] : array();
		$events_ok         = 3 === count( $events )
			&& array( 'custom-background-remove', 'custom-background-remove', 'custom-background-remove' ) === array_column( $events, 'action' )
			&& 1 === (int) ( $events[0]['result'] ?? 0 )
			&& 1 === (int) ( $events[1]['result'] ?? 0 )
			&& false === ( $events[2]['result'] ?? true );
		$filters_clean     = false === \has_filter( 'check_admin_referer', $recorder );

		self::collect_failure(
			$failures,
			$safe_capture['returned']
				&& ! $safe_capture['captured']
				&& null === $safe_capture['throwable']
				&& '' === $safe_image
				&& '' === $safe_thumb
				&& $safe_referer === ( $safe_redirect['location'] ?? null )
				&& 302 === (int) ( $safe_redirect['status'] ?? 0 )
				&& $safe_capture['filtersRestored']
				&& $safe_capture['superglobalsRestored'],
			'valid remove-background request clears background mods and attempts a same-host safe redirect',
			array(
				'capture'  => $safe_capture,
				'image'    => $safe_image,
				'thumb'    => $safe_thumb,
				'referer'  => $safe_referer,
				'redirect' => $safe_redirect,
			)
		);

		self::collect_failure(
			$failures,
			$external_capture['returned']
				&& ! $external_capture['captured']
				&& null === $external_capture['throwable']
				&& '' === $external_image
				&& '' === $external_thumb
				&& $fallback_url === ( $external_redirect['location'] ?? null )
				&& 302 === (int) ( $external_redirect['status'] ?? 0 )
				&& 1 === count( $fallback_events )
				&& 302 === (int) ( $fallback_events[0]['status'] ?? 0 )
				&& $external_capture['filtersRestored']
				&& $external_capture['superglobalsRestored']
				&& false === \has_filter( 'wp_safe_redirect_fallback', $fallback_filter ),
			'remove-background redirects unsafe referers through the safe redirect fallback while still clearing background mods',
			array(
				'capture'        => $external_capture,
				'fallbackEvents' => $fallback_events,
				'fallbackUrl'    => $fallback_url,
				'image'          => $external_image,
				'thumb'          => $external_thumb,
				'redirect'       => $external_redirect,
			)
		);

		self::collect_failure(
			$failures,
			$invalid_capture['captured']
				&& ! $invalid_capture['returned']
				&& null === $invalid_capture['throwable']
				&& 'http://example.test/background-before-invalid-' . $token . '.jpg' === $invalid_image
				&& 'http://example.test/background-before-invalid-' . $token . '-thumb.jpg' === $invalid_thumb
				&& array() === $invalid_capture['redirects']
				&& 403 === (int) ( $invalid_args['response'] ?? 0 )
				&& is_string( $invalid_die['message'] ?? null )
				&& str_contains( $invalid_die['message'], 'expired' )
				&& $invalid_capture['filtersRestored']
				&& $invalid_capture['superglobalsRestored']
				&& $events_ok
				&& $filters_clean,
			'invalid remove-background nonce fails closed through wp_die before mutating background mods or redirecting',
			array(
				'capture'      => $invalid_capture,
				'events'       => $events,
				'eventsOk'     => $events_ok,
				'filtersClean' => $filters_clean,
				'image'        => $invalid_image,
				'thumb'        => $invalid_thumb,
			)
		);

		return self::row(
			$ctx,
			'appearance-media.background-remove.safe-redirect',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, self::MAX_FAILURES ) )
		);
	}

	private static function background_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$preset     = $ctx->choice( array( 'default', 'fill', 'fit', 'repeat', 'custom', 'invalid-preset' ) );
		$position_x = $ctx->choice( array( 'left', 'center', 'right', 'bad-x' ) );
		$position_y = $ctx->choice( array( 'top', 'center', 'bottom', 'bad-y' ) );
		$size       = $ctx->choice( array( 'auto', 'contain', 'cover', 'stretch' ) );
		$repeat     = $ctx->choice( array( 'repeat', 'no-repeat', 'round' ) );
		$attachment = $ctx->choice( array( 'scroll', 'fixed', 'local' ) );
		$color      = $ctx->choice( array( '#abc', 'a1b2c3', 'zzzzzz', '12-34-56', '1234567' ) );

		$normalized_color = preg_replace( '/[^0-9a-fA-F]/', '', $color );
		if ( 3 !== strlen( $normalized_color ) && 6 !== strlen( $normalized_color ) ) {
			$normalized_color = '';
		}

		return array(
			'presetInput'     => $preset,
			'positionInput'   => $position_x . ' ' . $position_y,
			'sizeInput'       => $size,
			'repeatInput'     => $repeat,
			'attachmentInput' => $attachment,
			'colorInput'      => $color,
			'expected'        => array(
				'preset'     => in_array( $preset, array( 'default', 'fill', 'fit', 'repeat', 'custom' ), true ) ? $preset : 'default',
				'positionX'  => in_array( $position_x, array( 'left', 'center', 'right' ), true ) ? $position_x : 'left',
				'positionY'  => in_array( $position_y, array( 'top', 'center', 'bottom' ), true ) ? $position_y : 'top',
				'size'       => in_array( $size, array( 'auto', 'contain', 'cover' ), true ) ? $size : 'auto',
				'repeat'     => 'no-repeat' === $repeat ? 'no-repeat' : 'repeat',
				'attachment' => 'fixed' === $attachment ? 'fixed' : 'scroll',
				'color'      => $normalized_color,
			),
		);
	}

	private static function default_headers_case(): array {
		return array(
			'alpha' => array(
				'url'           => '%s/images/alpha.jpg',
				'thumbnail_url' => '%2$s/images/alpha-thumb.jpg',
				'description'   => 'Alpha Header',
				'alt_text'      => 'Alpha <unsafe>',
			),
			'beta'  => array(
				'url'           => '%2$s/images/beta.jpg',
				'thumbnail_url' => '%s/images/beta-thumb.jpg',
				'description'   => 'Beta Header',
				'alt_text'      => 'Beta & Header',
			),
		);
	}

	private static function custom_logo_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token   = strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $ctx->identifier( 5, 12 ) ) );
		$ext     = $ctx->choice( array( 'png', 'jpg', 'webp', 'gif' ) );
		$mimes   = array(
			'gif'  => 'image/gif',
			'jpg'  => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
		$width   = $ctx->int( 48, 320 );
		$height  = $ctx->int( 48, 240 );
		$flex    = $ctx->bool();
		$support = $flex
			? array(
				'flex-width'           => true,
				'flex-height'          => true,
				'header-text'          => array( 'site-title', 'site-description' ),
				'unlink-homepage-logo' => false,
			)
			: array(
				'width'                => $width,
				'height'               => $height,
				'flex-width'           => false,
				'flex-height'          => false,
				'header-text'          => array( 'site-title' ),
				'unlink-homepage-logo' => false,
			);

		return array(
			'token'           => $token,
			'slug'            => 'component-fuzz-logo-' . $token,
			'title'           => 'Component Fuzz Logo <' . $token . '>',
			'file'            => '/tmp/component-fuzz-logo-' . $token . '.' . $ext,
			'src'             => 'http://example.test/component-fuzz/logo-' . rawurlencode( $token ) . '.' . $ext . '?unsafe=<tag>',
			'mime'            => $mimes[ $ext ],
			'width'           => $width,
			'height'          => $height,
			'altMeta'         => 'Logo <' . $token . '>',
			'expectedAlt'     => 'Logo',
			'blogId'          => 0,
			'decoding'        => $ctx->choice( array( 'async', 'sync', 'auto', 'invalid' ) ),
			'fetchpriority'   => $ctx->choice( array( 'low', 'auto', '' ) ),
			'support'         => $support,
			'expectedSupport' => array(
				'width'      => $flex ? false : $width,
				'height'     => $flex ? false : $height,
				'flexWidth'  => true === $flex,
				'flexHeight' => true === $flex,
			),
		);
	}

	private static function theme_mod_option_name(): string {
		return 'theme_mods_' . \get_stylesheet();
	}

	private static function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	private static function capture_background_action( \Custom_Background $background, array $post ): array {
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;
		$start_level      = ob_get_level();
		$redirects        = array();
		$statuses         = array();
		$die_call         = null;
		$captured         = false;
		$returned         = false;
		$throwable        = null;
		$output           = '';
		$redirect_filter  = static function ( $location, int $status ) use ( &$redirects ) {
			$redirects[] = array(
				'location' => $location,
				'status'   => $status,
			);
			return false;
		};
		$status_filter    = static function ( int $status, $location ) use ( &$statuses ): int {
			$statuses[] = array(
				'location' => $location,
				'status'   => $status,
			);
			return $status;
		};
		$die_filter       = static function ( $handler ) use ( &$die_call ) {
			unset( $handler );

			return static function ( $message = '', $title = '', $args = array() ) use ( &$die_call ): void {
				$die_call = array(
					'args'    => $args,
					'message' => $message,
					'title'   => $title,
				);
				throw new AppearanceMediaSurface_DieCaptured( 'Captured appearance-media wp_die.' );
			};
		};

		$_POST    = $post;
		$_REQUEST = $post;
		\add_filter( 'wp_redirect', $redirect_filter, 10, 2 );
		\add_filter( 'wp_redirect_status', $status_filter, 10, 2 );
		\add_filter( 'wp_die_handler', $die_filter, 1 );

		ob_start();
		try {
			$background->take_action();
			$returned = true;
		} catch ( AppearanceMediaSurface_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$throwable = self::describe_throwable( $e );
		} finally {
			while ( ob_get_level() > $start_level ) {
				$chunk  = ob_get_clean();
				$output = ( false === $chunk ? '' : $chunk ) . $output;
			}

			\remove_filter( 'wp_redirect', $redirect_filter, 10 );
			\remove_filter( 'wp_redirect_status', $status_filter, 10 );
			\remove_filter( 'wp_die_handler', $die_filter, 1 );
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}

		return array(
			'bufferBalanced'       => $start_level === ob_get_level(),
			'captured'             => $captured,
			'die'                  => $die_call,
			'filtersRestored'      => false === \has_filter( 'wp_redirect', $redirect_filter )
				&& false === \has_filter( 'wp_redirect_status', $status_filter )
				&& false === \has_filter( 'wp_die_handler', $die_filter ),
			'output'               => $output,
			'redirects'            => $redirects,
			'returned'             => $returned,
			'statuses'             => $statuses,
			'superglobalsRestored' => $previous_post === $_POST && $previous_request === $_REQUEST,
			'throwable'            => $throwable,
		);
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition || count( $failures ) >= self::MAX_FAILURES ) {
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

	private static function install_cap_filter( array $caps, bool $grant ): callable {
		$selected = array_fill_keys( $caps, $grant );
		$filter   = static function ( array $allcaps, array $requested = array() ) use ( $selected ): array {
			foreach ( $selected as $cap => $allowed ) {
				$allcaps[ $cap ] = $allowed;
			}
			foreach ( $requested as $cap ) {
				if ( array_key_exists( $cap, $selected ) ) {
					$allcaps[ $cap ] = $selected[ $cap ];
				}
			}
			return $allcaps;
		};

		\add_filter( 'user_has_cap', $filter, PHP_INT_MAX, 4 );

		return $filter;
	}

	private static function remove_cap_filter( callable $filter ): bool {
		return \remove_filter( 'user_has_cap', $filter, PHP_INT_MAX );
	}

	private static function snapshot_state(): array {
		$option_names = array(
			'stylesheet',
			'template',
			'site_icon',
			'site_logo',
			self::theme_mod_option_name(),
			'theme_mods_component-fuzz-theme',
		);

		$options = array();
		foreach ( $option_names as $name ) {
			$options[ $name ] = array(
				'exists' => false !== \get_option( $name, false ),
				'value'  => \get_option( $name, null ),
			);
		}

		return array(
			'globals' => self::snapshot_globals(
				array(
					'_wp_default_headers',
					'_wp_theme_features',
					'custom_background',
					'custom_image_header',
					'wp_current_filter',
					'wp_filter',
					'current_user',
					'userdata',
					'user_ID',
					'wp_post_statuses',
					'wp_post_types',
				)
			),
			'options' => $options,
			'post'          => $_POST,
			'request'       => $_REQUEST,
			'get'           => $_GET,
			'files'         => $_FILES,
			'obLevel'       => ob_get_level(),
			'contentCounts' => self::content_counts(),
		);
	}

	private static function restore_state( array $snapshot ): void {
		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}

		self::restore_globals( $snapshot['globals'] );
		foreach ( $snapshot['options'] as $name => $option ) {
			if ( $option['exists'] ) {
				\update_option( $name, $option['value'] );
			} else {
				\delete_option( $name );
			}
		}

		$_POST    = $snapshot['post'];
		$_REQUEST = $snapshot['request'];
		$_GET     = $snapshot['get'];
		$_FILES   = $snapshot['files'];
	}

	private static function state_diff( array $snapshot ): array {
		$diff = array();
		if ( ob_get_level() !== $snapshot['obLevel'] ) {
			$diff['obLevel'] = array( 'expected' => $snapshot['obLevel'], 'actual' => ob_get_level() );
		}

		foreach ( $snapshot['options'] as $name => $option ) {
			$current_exists = false !== \get_option( $name, false );
			$current_value  = \get_option( $name, null );
			if ( $current_exists !== $option['exists'] || $current_value !== $option['value'] ) {
				$diff['options'][ $name ] = array(
					'expectedExists' => $option['exists'],
					'actualExists'   => $current_exists,
					'expectedValue'  => self::describe_value( $option['value'] ),
					'actualValue'    => self::describe_value( $current_value ),
				);
			}
		}

		if ( $_POST !== $snapshot['post'] || $_REQUEST !== $snapshot['request'] || $_GET !== $snapshot['get'] || $_FILES !== $snapshot['files'] ) {
			$diff['superglobals'] = array(
				'post'    => $_POST !== $snapshot['post'],
				'request' => $_REQUEST !== $snapshot['request'],
				'get'     => $_GET !== $snapshot['get'],
				'files'   => $_FILES !== $snapshot['files'],
			);
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				$diff['globals'][ $name ] = array( 'expectedExists' => $entry['exists'], 'actualExists' => $exists );
				continue;
			}
			if ( $exists && $GLOBALS[ $name ] !== $entry['value'] ) {
				$diff['globals'][ $name ] = 'value changed';
			}
		}

		$content_counts = self::content_counts();
		if ( $content_counts !== $snapshot['contentCounts'] ) {
			$diff['contentCounts'] = array(
				'expected' => $snapshot['contentCounts'],
				'actual'   => $content_counts,
			);
		}

		return $diff;
	}

	private static function content_counts(): array {
		return $GLOBALS['wpdb']->component_fuzz_content_counts();
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => $GLOBALS[ $name ] ?? null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function describe_throwable( \Throwable $e ): string {
		return wp_json_encode(
			array(
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	private static function describe_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 12, true ) as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			if ( count( $value ) > 12 ) {
				$out['__truncated__'] = count( $value ) - 12;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => get_class( $value ),
				'props' => self::describe_value( get_object_vars( $value ) ),
			);
		}

		if ( is_string( $value ) ) {
			return self::preview( $value );
		}

		return $value;
	}

	private static function preview( string $value, int $limit = 220 ): string {
		$value = preg_replace( '/\s+/', ' ', $value );
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, $limit ) . '...';
	}
}

final class AppearanceMediaSurface_DieCaptured extends \RuntimeException {
}
