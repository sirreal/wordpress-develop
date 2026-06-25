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
			$rows[] = self::check_custom_header_markup_and_video( $ctx->fork( 'custom-header-video' ) );
			$rows[] = self::check_custom_logo_helpers( $ctx->fork( 'custom-logo' ) );
			$rows[] = self::check_site_icon_helpers( $ctx->fork( 'site-icon' ) );
			$rows[] = $ctx->skip(
				'appearance-media.unsafe-upload-and-ajax-paths',
				'Custom header/background uploads, crops, AJAX handlers, and full admin page dispatch can redirect, exit, or require real uploaded files; safe helper paths are covered directly.'
			);
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

		foreach (
			array(
				'add_filter',
				'add_theme_support',
				'apply_filters',
				'checked',
				'create_initial_post_types',
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
				'is_header_video_active',
				'is_random_header_image',
				'remove_all_filters',
				'remove_filter',
				'remove_theme_support',
				'remove_theme_mod',
				'set_url_scheme',
				'set_theme_mod',
				'the_custom_logo',
				'the_header_video_url',
				'update_option',
				'wp_check_filetype',
				'wp_create_nonce',
				'wp_delete_post',
				'wp_get_mime_types',
				'wp_insert_post',
				'wp_nonce_tick',
				'wp_site_icon',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
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

	private static function check_custom_logo_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$case           = self::custom_logo_case( $ctx );
		$attachment_id  = null;
		$attr_payloads  = array();
		$output_payloads = array();

		$slug_filter = static function ( $override, string $slug, int $post_id, string $post_status, string $post_type, int $post_parent ) use ( $case ) {
			unset( $post_id, $post_status, $post_parent );
			return ( 'attachment' === $post_type && $case['slug'] === $slug ) ? $slug : $override;
		};
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

		\add_filter( 'pre_wp_unique_post_slug', $slug_filter, 10, 6 );
		\add_filter( 'get_attached_file', $file_filter, 10, 2 );
		\add_filter( 'wp_get_attachment_image_src', $source_filter, 10, 4 );
		\add_filter( 'get_post_metadata', $meta_filter, 10, 5 );
		\add_filter( 'get_custom_logo_image_attributes', $attr_filter, 10, 3 );
		\add_filter( 'get_custom_logo', $output_filter, 10, 2 );

		try {
			\add_theme_support( 'custom-logo', $case['support'] );
			$attachment_id = \wp_insert_post(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => $case['title'],
					'post_name'      => $case['slug'],
					'post_mime_type' => $case['mime'],
					'guid'           => $case['src'],
				),
				true,
				false
			);

			if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
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
			\remove_filter( 'pre_wp_unique_post_slug', $slug_filter, 10 );
			\remove_theme_mod( 'custom_logo' );
			\remove_theme_support( 'custom-logo' );
			if ( null !== $attachment_id ) {
				\wp_delete_post( $attachment_id, true );
			}
		}

		self::collect_failure(
			$failures,
			false === \has_filter( 'get_custom_logo', $output_filter )
				&& false === \has_filter( 'get_custom_logo_image_attributes', $attr_filter )
				&& false === \has_filter( 'get_post_metadata', $meta_filter )
				&& false === \has_filter( 'wp_get_attachment_image_src', $source_filter )
				&& false === \has_filter( 'get_attached_file', $file_filter )
				&& false === \has_filter( 'pre_wp_unique_post_slug', $slug_filter ),
			'custom logo helper filters are removed after the case',
			array(
				'getCustomLogo'           => \has_filter( 'get_custom_logo', $output_filter ),
				'customLogoAttributes'    => \has_filter( 'get_custom_logo_image_attributes', $attr_filter ),
				'getPostMetadata'         => \has_filter( 'get_post_metadata', $meta_filter ),
				'attachmentImageSource'   => \has_filter( 'wp_get_attachment_image_src', $source_filter ),
				'getAttachedFile'         => \has_filter( 'get_attached_file', $file_filter ),
				'preUniqueAttachmentSlug' => \has_filter( 'pre_wp_unique_post_slug', $slug_filter ),
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
		}

		return self::row(
			$ctx,
			'appearance-media.site-icon.helpers',
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
			'blogId'          => $ctx->int( 0, 3 ),
			'decoding'        => $ctx->choice( array( 'async', 'sync', 'auto', 'invalid' ) ),
			'fetchpriority'   => $ctx->choice( array( 'high', 'low', '' ) ),
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

	private static function snapshot_state(): array {
		$option_names = array(
			'stylesheet',
			'template',
			'site_icon',
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
					'wp_post_statuses',
					'wp_post_types',
				)
			),
			'options' => $options,
			'post'    => $_POST,
			'request' => $_REQUEST,
			'get'     => $_GET,
			'obLevel' => ob_get_level(),
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

		if ( $_POST !== $snapshot['post'] || $_REQUEST !== $snapshot['request'] || $_GET !== $snapshot['get'] ) {
			$diff['superglobals'] = array(
				'post'    => $_POST !== $snapshot['post'],
				'request' => $_REQUEST !== $snapshot['request'],
				'get'     => $_GET !== $snapshot['get'],
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

		return $diff;
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
