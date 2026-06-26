<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes safe no-browser admin media chrome and attachment helper output.
 */
final class AdminMediaChromeSurface {
	public const NAME = 'admin-media-chrome';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'admin-media-chrome.bootstrap-apis-available',
					'Required admin media APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::prepare_runtime();

			$rows[] = self::check_attachment_fields_and_media_item( $ctx->fork( 'fields-item' ) );
			$rows[] = self::check_attachment_submitbox_metadata( $ctx->fork( 'submitbox-metadata' ) );
			$rows[] = self::check_compat_media_markup( $ctx->fork( 'compat-markup' ) );
			$rows[] = self::check_image_form_controls( $ctx->fork( 'image-controls' ) );
			$rows[] = self::check_image_editor_chrome( $ctx->fork( 'image-editor-chrome' ) );
			$rows[] = self::check_edit_form_image_details_and_compat( $ctx->fork( 'edit-form-details' ) );
			$rows[] = self::check_thumbnail_icon_and_image_helpers( $ctx->fork( 'thumb-icons' ) );
			$rows[] = self::check_image_caption_editor_output( $ctx->fork( 'image-caption-editor' ) );
			$rows[] = self::check_media_button_and_bypass_output( $ctx->fork( 'media-buttons' ) );
			$rows[] = self::skipped_exiting_upload_helpers( $ctx );
			$rows[] = self::skipped_modal_runtime_helpers( $ctx );
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'admin-media-chrome.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::cleanup_runtime();
			self::restore_state( $snapshot );
		}

		$rows[] = self::row(
			$ctx,
			'admin-media-chrome.cleanup.globals-filters-cache',
			true,
			array(
				'restoredGlobals' => true,
				'resetStubRows'   => true,
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'attachment_submitbox_metadata',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'edit_form_image_editor',
				'get_attachment_link',
				'get_attachment_fields_to_edit',
				'get_compat_media_markup',
				'get_image_send_to_editor',
				'get_image_tag',
				'get_media_item',
				'image_align_input_fields',
				'image_add_caption',
				'image_edit_apply_changes',
				'image_link_input_fields',
				'image_media_send_to_editor',
				'image_size_input_fields',
				'media_buttons',
				'media_upload_flash_bypass',
				'media_upload_html_bypass',
				'wp_get_attachment_image',
				'wp_get_attachment_image_src',
				'wp_get_attachment_metadata',
				'wp_get_attachment_thumb_url',
				'wp_image_editor',
				'wp_insert_post',
				'wp_mime_type_icon',
				'wp_editor',
				'wp_set_current_user',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		foreach ( array( 'WP_Error', 'WP_Post', 'WP_Image_Editor' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		return $missing;
	}

	private static function check_attachment_fields_and_media_item( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$attachment   = self::seed_attachment(
			$ctx,
			'image/jpeg',
			array(
				'post_title'   => 'Unsafe title <script>alert(1)</script> "' . $ctx->identifier( 3, 8 ),
				'post_excerpt' => 'Caption <script>alert(1)</script> & "' . $ctx->identifier( 3, 8 ),
				'post_content' => 'Description <script>alert(1)</script> ' . $ctx->text( 0, 18 ),
			)
		);
		$custom_value = 'custom value " <script>alert(1)</script> &' . $ctx->identifier( 3, 8 );
		$hidden_value = "hidden <script>alert(1)</script> '" . $ctx->identifier( 3, 8 );
		$events       = array(
			'fields' => array(),
			'args'   => array(),
			'meta'   => array(),
		);

		$fields_filter = static function ( array $fields, \WP_Post $post ) use ( &$events, $attachment, $custom_value, $hidden_value ): array {
			$events['fields'][] = $post->ID;
			if ( (int) $post->ID !== (int) $attachment->ID ) {
				return $fields;
			}

			$fields['cfz_text'] = array(
				'label' => 'Component fuzz text',
				'value' => $custom_value,
			);
			$fields['cfz_hidden'] = array(
				'input' => 'hidden',
				'value' => $hidden_value,
			);

			return $fields;
		};

		$args_filter = static function ( array $args ) use ( &$events ): array {
			$events['args'][] = array(
				'send'   => $args['send'] ?? null,
				'delete' => $args['delete'] ?? null,
				'toggle' => $args['toggle'] ?? null,
			);

			$args['send']   = false;
			$args['delete'] = false;
			return $args;
		};

		$meta_filter = static function ( string $media_dims, \WP_Post $post ) use ( &$events ): string {
			$events['meta'][] = array(
				'id'   => $post->ID,
				'dims' => $media_dims,
			);

			return $media_dims . '<span class="cfz-media-meta">filtered</span>';
		};

		\add_filter( 'attachment_fields_to_edit', $fields_filter, 10, 2 );
		\add_filter( 'get_media_item_args', $args_filter );
		\add_filter( 'media_meta', $meta_filter, 10, 2 );

		try {
			$fields = \get_attachment_fields_to_edit( $attachment->ID );
			$html   = \get_media_item(
				$attachment->ID,
				array(
					'delete'     => false,
					'send'       => false,
					'show_title' => true,
					'toggle'     => $ctx->bool(),
				)
			);
		} finally {
			\remove_filter( 'attachment_fields_to_edit', $fields_filter, 10 );
			\remove_filter( 'get_media_item_args', $args_filter );
			\remove_filter( 'media_meta', $meta_filter, 10 );
		}

		$required_field_keys = array( 'post_title', 'image_alt', 'post_excerpt', 'post_content', 'url', 'menu_order', 'image_url', 'align', 'image-size', 'cfz_text', 'cfz_hidden' );
		self::collect_failure(
			$failures,
			array() === array_diff( $required_field_keys, array_keys( $fields ) )
				&& true === ( $fields['post_title']['required'] ?? false )
				&& 'Alternative Text' === ( $fields['image_alt']['label'] ?? null )
				&& isset( $fields['post_excerpt']['html'], $fields['image-size']['html'] )
				&& self::html_has_no_raw_script( (string) $fields['post_excerpt']['html'] )
				&& self::html_has_no_raw_script( (string) $fields['image-size']['html'] ),
			'attachment edit fields include image-specific controls with escaped generated HTML',
			array(
				'keys'        => array_keys( $fields ),
				'postExcerpt' => $fields['post_excerpt']['html'] ?? null,
				'imageSize'   => $fields['image-size']['html'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $html )
				&& str_contains( $html, 'media-head-' . $attachment->ID )
				&& str_contains( $html, 'cfz-media-meta' )
				&& str_contains( $html, \esc_attr( $custom_value ) )
				&& str_contains( $html, \esc_attr( $hidden_value ) )
				&& self::html_has_no_raw_script( $html )
				&& ! str_contains( $html, $custom_value )
				&& ! str_contains( $html, $hidden_value ),
			'get_media_item() escapes core text and hidden field values while preserving safe media metadata HTML',
			array(
				'events' => $events,
				'html'   => self::describe_string( $html ),
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'attachment_fields_to_edit', $fields_filter )
				&& false === \has_filter( 'get_media_item_args', $args_filter )
				&& false === \has_filter( 'media_meta', $meta_filter )
				&& count( $events['fields'] ) >= 2
				&& 1 === count( $events['args'] )
				&& 1 === count( $events['meta'] ),
			'media item filters are invoked for the target attachment and removed after the check',
			array( 'events' => $events )
		);

		return self::row(
			$ctx,
			'admin-media-chrome.attachment-fields-media-item-html',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_compat_media_markup( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$attachment   = self::seed_attachment(
			$ctx,
			'application/pdf',
			array(
				'extension'    => 'pdf',
				'post_title'   => 'PDF <script>alert(1)</script> ' . $ctx->identifier( 3, 8 ),
				'post_excerpt' => 'PDF caption ' . $ctx->text( 0, 16 ),
				'width'        => 0,
				'height'       => 0,
			)
		);
		$text_value   = 'compat " <script>alert(1)</script> ' . $ctx->identifier( 3, 8 );
		$hidden_value = 'compat hidden <script>alert(1)</script> ' . $ctx->identifier( 3, 8 );
		$events       = array(
			'fields' => array(),
			'meta'   => array(),
		);

		$fields_filter = static function ( array $fields, \WP_Post $post ) use ( &$events, $attachment, $text_value, $hidden_value ): array {
			$events['fields'][] = $post->ID;
			if ( (int) $post->ID !== (int) $attachment->ID ) {
				return $fields;
			}

			$fields['cfz_compat_text'] = array(
				'label'         => 'Compat text',
				'value'         => $text_value,
				'show_in_edit'  => true,
				'show_in_modal' => true,
			);
			$fields['cfz_compat_hidden'] = array(
				'input'         => 'hidden',
				'value'         => $hidden_value,
				'show_in_edit'  => true,
				'show_in_modal' => true,
			);
			$fields['cfz_edit_only'] = array(
				'label'         => 'Edit only',
				'value'         => 'edit only',
				'show_in_modal' => false,
			);

			return $fields;
		};

		$meta_filter = static function ( string $media_meta, \WP_Post $post ) use ( &$events ): string {
			$events['meta'][] = $post->ID;
			return $media_meta . '<span class="cfz-compat-meta">compat</span>';
		};

		\add_filter( 'attachment_fields_to_edit', $fields_filter, 10, 2 );
		\add_filter( 'media_meta', $meta_filter, 10, 2 );
		try {
			$markup = \get_compat_media_markup(
				$attachment->ID,
				array(
					'in_modal' => true,
				)
			);
		} finally {
			\remove_filter( 'attachment_fields_to_edit', $fields_filter, 10 );
			\remove_filter( 'media_meta', $meta_filter, 10 );
		}

		$item = (string) ( $markup['item'] ?? '' );
		$meta = (string) ( $markup['meta'] ?? '' );

		self::collect_failure(
			$failures,
			str_contains( $item, 'compat-attachment-fields' )
				&& str_contains( $item, 'compat-field-cfz_compat_text' )
				&& str_contains( $item, \esc_attr( $text_value ) )
				&& str_contains( $item, \esc_attr( $hidden_value ) )
				&& ! str_contains( $item, 'compat-field-cfz_edit_only' )
				&& ! str_contains( $item, $text_value )
				&& ! str_contains( $item, $hidden_value )
				&& self::html_has_no_raw_script( $item )
				&& '<span class="cfz-compat-meta">compat</span>' === $meta,
			'get_compat_media_markup() respects modal visibility and escapes text and hidden values',
			array(
				'item' => self::describe_string( $item ),
				'meta' => $meta,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'attachment_fields_to_edit', $fields_filter )
				&& false === \has_filter( 'media_meta', $meta_filter )
				&& array( $attachment->ID ) === $events['fields']
				&& array( $attachment->ID ) === $events['meta'],
			'compat markup filters are local to the check',
			array( 'events' => $events )
		);

		return self::row(
			$ctx,
			'admin-media-chrome.compat-media-markup',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_attachment_submitbox_metadata( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$attachment = self::seed_attachment(
			$ctx,
			'image/jpeg',
			array(
				'unsafe_file' => true,
			)
		);
		$events     = array();

		$meta_filter = static function ( string $media_dims, \WP_Post $post ) use ( &$events ): string {
			$events[] = array(
				'id'   => $post->ID,
				'dims' => $media_dims,
			);

			return $media_dims . '<span class="cfz-submitbox-meta">submitbox</span>';
		};

		$previous_post = array_key_exists( 'post', $GLOBALS ) ? $GLOBALS['post'] : null;
		$had_post      = array_key_exists( 'post', $GLOBALS );

		\add_filter( 'media_meta', $meta_filter, 10, 2 );
		try {
			$GLOBALS['post'] = $attachment;
			$html            = self::capture_output(
				static function (): void {
					\attachment_submitbox_metadata();
				}
			);
		} finally {
			\remove_filter( 'media_meta', $meta_filter, 10 );
			if ( $had_post ) {
				$GLOBALS['post'] = $previous_post;
			} else {
				unset( $GLOBALS['post'] );
			}
		}

		$attachment_url = \wp_get_attachment_url( $attachment->ID );

		self::collect_failure(
			$failures,
			is_string( $html )
				&& is_string( $attachment_url )
				&& str_contains( $html, 'misc-pub-attachment' )
				&& str_contains( $html, 'id="attachment_url"' )
				&& str_contains( $html, \esc_attr( $attachment_url ) )
				&& str_contains( $html, 'Download file' )
				&& str_contains( $html, 'cfz-submitbox-meta' )
				&& self::html_has_no_raw_script( $html ),
			'attachment_submitbox_metadata() escapes file URL and filename chrome from seeded metadata',
			array(
				'events' => $events,
				'html'   => self::describe_string( $html ),
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'media_meta', $meta_filter )
				&& 1 === count( $events )
				&& (int) $attachment->ID === (int) ( $events[0]['id'] ?? 0 )
				&& ( $had_post ? $GLOBALS['post'] === $previous_post : ! array_key_exists( 'post', $GLOBALS ) ),
			'attachment submitbox metadata filter and global post are restored',
			array( 'events' => $events )
		);

		return self::row(
			$ctx,
			'admin-media-chrome.attachment-submitbox-metadata',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_image_form_controls( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$attachment = self::seed_attachment(
			$ctx,
			'image/jpeg',
			array(
				'unsafe_file' => true,
			)
		);
		$events     = array(
			'sizes'    => array(),
			'downsize' => array(),
		);

		$size_names_filter = static function ( array $size_names ) use ( &$events ): array {
			$events['sizes'][]       = array_keys( $size_names );
			$size_names['cfzwide'] = 'Component fuzz wide';
			return $size_names;
		};

		$downsize_filter = static function ( $downsize, int $id, $size ) use ( &$events, $attachment ) {
			$events['downsize'][] = array(
				'id'   => $id,
				'size' => $size,
			);

			if ( (int) $attachment->ID === $id && 'cfzwide' === $size ) {
				return array( 'http://example.test/component-fuzz/cfzwide.jpg', 321, 123, true );
			}

			return $downsize;
		};

		\add_filter( 'image_size_names_choose', $size_names_filter );
		\add_filter( 'image_downsize', $downsize_filter, 10, 3 );
		try {
			$align_html = \image_align_input_fields( $attachment, 'not-a-valid-alignment' );
			$link_html  = \image_link_input_fields( $attachment, 'file' );
			$size_field = \image_size_input_fields( $attachment, 'cfzwide' );
		} finally {
			\remove_filter( 'image_size_names_choose', $size_names_filter );
			\remove_filter( 'image_downsize', $downsize_filter, 10 );
		}

		$size_html = (string) ( $size_field['html'] ?? '' );

		self::collect_failure(
			$failures,
			4 === substr_count( $align_html, "name='attachments[{$attachment->ID}][align]'" )
				&& str_contains( $align_html, "value='none' checked='checked'" )
				&& self::html_has_no_raw_script( $align_html ),
			'image_align_input_fields() normalizes invalid defaults and emits stable radio controls',
			array( 'alignHtml' => self::describe_string( $align_html ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $link_html, "name='attachments[{$attachment->ID}][url]'" )
				&& str_contains( $link_html, 'data-link-url=' )
				&& str_contains( $link_html, \esc_attr( \wp_get_attachment_url( $attachment->ID ) ) )
				&& self::html_has_no_raw_script( $link_html ),
			'image_link_input_fields() escapes URL presets and names controls by attachment ID',
			array( 'linkHtml' => self::describe_string( $link_html ) )
		);

		self::collect_failure(
			$failures,
			'Size' === ( $size_field['label'] ?? null )
				&& str_contains( $size_html, "value='cfzwide' checked='checked'" )
				&& str_contains( $size_html, '(321&nbsp;&times;&nbsp;123)' )
				&& self::html_has_no_raw_script( $size_html ),
			'image_size_input_fields() honors filtered size choices and downsize dimensions',
			array(
				'sizeField' => $size_field,
				'events'    => $events,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'image_size_names_choose', $size_names_filter )
				&& false === \has_filter( 'image_downsize', $downsize_filter )
				&& 1 === count( $events['sizes'] )
				&& in_array( 'cfzwide', array_column( $events['downsize'], 'size' ), true ),
			'image form control filters are invoked and removed',
			array( 'events' => $events )
		);

		return self::row(
			$ctx,
			'admin-media-chrome.image-form-controls',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_image_editor_chrome( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$attachment = self::seed_attachment(
			$ctx,
			'image/jpeg',
			array(
				'width'  => $ctx->int( 640, 1800 ),
				'height' => $ctx->int( 480, 1400 ),
			)
		);
		$events     = array();

		$thumbnail_filter = static function ( bool $show ) use ( &$events ): bool {
			$events[] = $show;
			return true;
		};

		\add_filter( 'image_edit_thumbnails_separately', $thumbnail_filter );
		try {
			$msg        = (object) array( 'msg' => 'Component fuzz image editor saved.' );
			$editor_html = self::capture_output(
				static function () use ( $attachment, $msg ): void {
					\wp_image_editor( $attachment->ID, $msg );
				}
			);
		} finally {
			\remove_filter( 'image_edit_thumbnails_separately', $thumbnail_filter );
		}

		self::collect_failure(
			$failures,
			str_contains( $editor_html, 'imgedit-panel-' . $attachment->ID )
				&& str_contains( $editor_html, 'imgedit-nonce-' . $attachment->ID )
				&& str_contains( $editor_html, 'image-preview-' . $attachment->ID )
				&& str_contains( $editor_html, 'admin-ajax.php?action=imgedit-preview' )
				&& str_contains( $editor_html, 'Thumbnail Settings' )
				&& str_contains( $editor_html, 'Component fuzz image editor saved.' )
				&& self::html_has_no_raw_script( $editor_html ),
			'wp_image_editor() renders bounded chrome from seeded metadata without loading an editor backend',
			array(
				'events' => $events,
				'html'   => self::describe_string( $editor_html ),
			)
		);

		self::collect_failure(
			$failures,
			array( false ) === $events
				&& false === \has_filter( 'image_edit_thumbnails_separately', $thumbnail_filter ),
			'image editor thumbnail-settings filter is local to the check',
			array( 'events' => $events )
		);

		return self::row(
			$ctx,
			'admin-media-chrome.image-editor-chrome',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_edit_form_image_details_and_compat( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures     = array();
		$alt_value    = 'Edit alt " <script>alert(1)</script> &' . $ctx->identifier( 3, 8 );
		$attachment   = self::seed_attachment(
			$ctx,
			'image/jpeg',
			array(
				'alt'          => $alt_value,
				'post_title'   => 'Edit form title <script>alert(1)</script> ' . $ctx->identifier( 3, 8 ),
				'post_excerpt' => 'Edit caption <script>alert(1)</script> &' . $ctx->text( 0, 16 ),
				'post_content' => 'Edit description <script>alert(1)</script> ' . $ctx->text( 0, 16 ),
				'width'        => $ctx->int( 320, 1600 ),
				'height'       => $ctx->int( 240, 1200 ),
			)
		);
		$edit_value   = 'edit compat " <script>alert(1)</script> &' . $ctx->identifier( 3, 8 );
		$hidden_value = "edit hidden <script>alert(1)</script> '" . $ctx->identifier( 3, 8 );
		$modal_value  = 'modal only ' . $ctx->identifier( 3, 8 );
		$events       = array(
			'fields'   => array(),
			'tinymce'  => array(),
			'settings' => array(),
		);

		$fields_filter = static function ( array $fields, \WP_Post $post ) use ( &$events, $attachment, $edit_value, $hidden_value, $modal_value ): array {
			$events['fields'][] = $post->ID;
			if ( (int) $post->ID !== (int) $attachment->ID ) {
				return $fields;
			}

			$fields['cfz_edit_detail'] = array(
				'label'         => 'Edit detail',
				'value'         => $edit_value,
				'required'      => true,
				'show_in_edit'  => true,
				'show_in_modal' => false,
			);
			$fields['cfz_edit_hidden'] = array(
				'input'         => 'hidden',
				'value'         => $hidden_value,
				'show_in_edit'  => true,
				'show_in_modal' => false,
			);
			$fields['cfz_modal_only']  = array(
				'label'         => 'Modal only',
				'value'         => $modal_value,
				'show_in_edit'  => false,
				'show_in_modal' => true,
			);

			return $fields;
		};

		$tinymce_filter = static function ( bool $enabled ) use ( &$events ): bool {
			$events['tinymce'][] = $enabled;
			return false;
		};

		$settings_filter = static function ( array $settings, string $editor_id ) use ( &$events ): array {
			if ( 'attachment_content' === $editor_id ) {
				$events['settings'][] = array(
					'editorId'      => $editor_id,
					'textareaName'  => $settings['textarea_name'] ?? null,
					'mediaButtons'  => $settings['media_buttons'] ?? null,
					'quicktagNames' => is_array( $settings['quicktags'] ?? null ) ? array_keys( $settings['quicktags'] ) : array(),
				);
			}

			return $settings;
		};

		$globals_snapshot = self::snapshot_globals( array( '_GET', '_POST', '_REQUEST' ) );
		self::load_editor_class();
		$editor_snapshot = self::snapshot_editor_statics();
		$editor_restored = false;

		\add_filter( 'attachment_fields_to_edit', $fields_filter, 10, 2 );
		\add_filter( 'activate_tinymce_for_media_description', $tinymce_filter );
		\add_filter( 'wp_editor_settings', $settings_filter, 10, 2 );
		try {
			unset( $_GET['image-editor'] );
			$edit_post = \sanitize_post( $attachment, 'edit' );
			$html      = self::capture_output(
				static function () use ( $edit_post ): void {
					\edit_form_image_editor( $edit_post );
				}
			);
		} finally {
			\remove_filter( 'attachment_fields_to_edit', $fields_filter, 10 );
			\remove_filter( 'activate_tinymce_for_media_description', $tinymce_filter );
			\remove_filter( 'wp_editor_settings', $settings_filter, 10 );
			self::restore_state( $globals_snapshot );
			self::restore_editor_statics( $editor_snapshot );
			$editor_restored = $editor_snapshot === self::snapshot_editor_statics();
		}

		self::collect_failure(
			$failures,
			is_string( $html )
				&& str_contains( $html, 'wp_attachment_holder' )
				&& str_contains( $html, 'media-head-' . $attachment->ID )
				&& str_contains( $html, 'id="attachment_alt"' )
				&& str_contains( $html, 'id="attachment_caption"' )
				&& str_contains( $html, 'id="attachment_content"' )
				&& str_contains( $html, 'id="image-edit-context" value="edit-attachment"' )
				&& str_contains( $html, \esc_attr( $alt_value ) )
				&& ! str_contains( $html, $alt_value )
				&& self::html_has_no_raw_script( $html ),
			'edit_form_image_editor() renders image details fields from sanitized attachment data without raw generated script text',
			array( 'html' => self::describe_string( $html ) )
		);

		self::collect_failure(
			$failures,
			str_contains( $html, 'compat-attachment-fields' )
				&& str_contains( $html, 'compat-field-cfz_edit_detail form-required' )
				&& str_contains( $html, \esc_attr( $edit_value ) )
				&& str_contains( $html, \esc_attr( $hidden_value ) )
				&& ! str_contains( $html, 'compat-field-cfz_modal_only' )
				&& ! str_contains( $html, $edit_value )
				&& ! str_contains( $html, $hidden_value ),
			'edit attachment compat markup includes edit-only required and hidden fields while escaping generated values',
			array(
				'events' => $events,
				'html'   => self::describe_string( $html ),
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'attachment_fields_to_edit', $fields_filter )
				&& false === \has_filter( 'activate_tinymce_for_media_description', $tinymce_filter )
				&& false === \has_filter( 'wp_editor_settings', $settings_filter )
				&& array( $attachment->ID ) === $events['fields']
				&& array( false ) === $events['tinymce']
				&& 1 === count( $events['settings'] )
				&& 'content' === ( $events['settings'][0]['textareaName'] ?? null )
				&& false === ( $events['settings'][0]['mediaButtons'] ?? null )
				&& $editor_restored,
			'edit form filters, request globals, and editor statics are restored after rendering',
			array(
				'events'         => $events,
				'editorRestored' => $editor_restored,
			)
		);

		return self::row(
			$ctx,
			'admin-media-chrome.edit-form-image-details-compat',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_thumbnail_icon_and_image_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$image      = self::seed_attachment(
			$ctx,
			'image/jpeg',
			array(
				'alt' => 'Alt " <script>alert(1)</script> &' . $ctx->identifier( 3, 8 ),
			)
		);
		$document   = self::seed_attachment(
			$ctx,
			'application/pdf',
			array(
				'extension' => 'pdf',
				'width'     => 0,
				'height'    => 0,
			)
		);
		$events     = array(
			'metadata' => array(),
			'thumb'    => array(),
			'icons'    => array(),
		);
		$thumb_file = 'component-fuzz-filtered-thumb-' . $ctx->identifier( 3, 8 ) . '.jpg';

		$metadata_filter = static function ( $metadata, int $attachment_id ) use ( &$events, $image, $thumb_file ) {
			$events['metadata'][] = $attachment_id;
			if ( (int) $image->ID !== $attachment_id || ! is_array( $metadata ) ) {
				return $metadata;
			}

			$metadata['sizes']['thumbnail']['file'] = $thumb_file;
			$metadata['sizes']['thumbnail']['width'] = 177;
			$metadata['sizes']['thumbnail']['height'] = 133;
			return $metadata;
		};

		$thumb_filter = static function ( string $url, int $post_id ) use ( &$events ): string {
			$events['thumb'][] = array(
				'id'  => $post_id,
				'url' => $url,
			);

			return \add_query_arg( 'cfzthumb', '1', $url );
		};

		$icon_filter = static function ( $icon, string $mime, int $post_id ) use ( &$events ) {
			$events['icons'][] = array(
				'icon' => $icon,
				'mime' => $mime,
				'id'   => $post_id,
			);

			return $icon;
		};

		\wp_cache_delete( 'icon_files' );
		\wp_cache_delete( 'mime_type_icon_application/pdf' );
		\add_filter( 'wp_get_attachment_metadata', $metadata_filter, 10, 2 );
		\add_filter( 'wp_get_attachment_thumb_url', $thumb_filter, 10, 2 );
		\add_filter( 'wp_mime_type_icon', $icon_filter, 10, 3 );
		try {
			$thumb_url = \wp_get_attachment_thumb_url( $image->ID );
			$img_html  = \wp_get_attachment_image(
				$image->ID,
				'thumbnail',
				false,
				array(
					'alt'      => 'Explicit alt <script>alert(1)</script> "' . $ctx->identifier( 3, 8 ),
					'class'    => 'cfz-class <script>alert(1)</script>',
					'loading'  => false,
					'decoding' => 'async',
				)
			);
			$icon_url  = \wp_mime_type_icon( $document->ID, '.svg' );
		} finally {
			\remove_filter( 'wp_get_attachment_metadata', $metadata_filter, 10 );
			\remove_filter( 'wp_get_attachment_thumb_url', $thumb_filter, 10 );
			\remove_filter( 'wp_mime_type_icon', $icon_filter, 10 );
			\wp_cache_delete( 'icon_files' );
			\wp_cache_delete( 'mime_type_icon_application/pdf' );
		}

		self::collect_failure(
			$failures,
			is_string( $thumb_url )
				&& str_contains( $thumb_url, $thumb_file )
				&& str_contains( $thumb_url, 'cfzthumb=1' )
				&& ! str_contains( $thumb_url, '<script' ),
			'wp_get_attachment_thumb_url() uses filtered attachment metadata and URL filters',
			array(
				'thumbUrl' => $thumb_url,
				'events'   => $events,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $img_html )
				&& str_contains( $img_html, '<img ' )
				&& str_contains( $img_html, 'width="' )
				&& str_contains( $img_html, 'height="' )
				&& str_contains( $img_html, $thumb_file )
				&& str_contains( $img_html, $thumb_file . ' 177w' )
				&& str_contains( $img_html, \esc_attr( 'Explicit alt <script>alert(1)</script> "' ) )
				&& self::html_has_no_raw_script( $img_html ),
			'wp_get_attachment_image() uses filtered metadata and escapes caller-provided attributes',
			array( 'imgHtml' => self::describe_string( $img_html ) )
		);

		self::collect_failure(
			$failures,
			is_string( $icon_url )
				&& str_contains( $icon_url, '/images/media/' )
				&& str_ends_with( $icon_url, '.svg' )
				&& array( $document->ID ) === array_column( $events['icons'], 'id' ),
			'wp_mime_type_icon() resolves attachment icons through cacheable icon discovery',
			array(
				'iconUrl' => $icon_url,
				'events'  => $events,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'wp_get_attachment_metadata', $metadata_filter )
				&& false === \has_filter( 'wp_get_attachment_thumb_url', $thumb_filter )
				&& false === \has_filter( 'wp_mime_type_icon', $icon_filter ),
			'thumbnail and icon filters are removed after helper checks',
			array( 'events' => $events )
		);

		return self::row(
			$ctx,
			'admin-media-chrome.thumbnail-icon-image-helpers',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_image_caption_editor_output( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures   = array();
		$attachment = self::seed_attachment(
			$ctx,
			'image/jpeg',
			array(
				'alt'          => 'Stored alt <script>alert(1)</script> "' . $ctx->identifier( 3, 8 ),
				'post_title'   => 'Editor title <script>alert(1)</script> "' . $ctx->identifier( 3, 8 ),
				'post_excerpt' => 'Editor caption <script>alert(1)</script> &' . $ctx->text( 0, 16 ),
				'width'        => 1024,
				'height'       => 768,
			)
		);
		$document   = self::seed_attachment(
			$ctx,
			'application/pdf',
			array(
				'extension' => 'pdf',
				'width'     => 0,
				'height'    => 0,
			)
		);

		$filter_snapshot = self::snapshot_globals( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter' ) );
		self::load_default_filters();

		$caption        = 'Send caption <script>alert(1)</script> javascript:alert(1) ' . $ctx->text( 0, 12 );
		$title          = 'Send title <script>alert(1)</script> "' . $ctx->identifier( 3, 8 );
		$align          = 'left<script>alert(1)</script>" onmouseover="bad';
		$size           = 'cfzsize<script>alert(1)</script>"';
		$alt            = 'Send alt " <script>alert(1)</script> &' . $ctx->identifier( 3, 8 );
		$url            = 'javascript:alert(1)" onclick="bad';
		$rel            = 'noopener" onclick="bad <script>alert(1)</script>';
		$downsize_url   = 'http://example.test/component-fuzz/direct <script>alert(1)</script> "' . $ctx->identifier( 3, 8 ) . '.jpg';
		$downsize_calls = array();
		$send_events    = array();
		$integrated_caption = "Integrated caption\nSecond line";
		$integrated_alt     = 'Integrated alt <script>alert(1)</script> "' . $ctx->identifier( 3, 8 );
		$no_rel_url         = 'http://example.test/component-fuzz/no-rel?raw=' . rawurlencode( '<script>alert(1)</script>' ) . '&safe=1';
		$no_rel_alt         = 'No rel alt <script>alert(1)</script> "' . $ctx->identifier( 3, 8 );

		$downsize_filter = static function ( $downsize, int $id, $requested_size ) use ( &$downsize_calls, $attachment, $size, $downsize_url ) {
			$downsize_calls[] = array(
				'id'   => $id,
				'size' => $requested_size,
			);

			if ( (int) $attachment->ID === $id && $requested_size === $size ) {
				return array( $downsize_url, 321, 123, true );
			}

			return $downsize;
		};

		$send_filter = static function ( string $html, int $id, string $captured_caption, string $captured_title, string $captured_align, string $captured_url, $captured_size, string $captured_alt, string $captured_rel ) use ( &$send_events ): string {
			$send_events[] = array(
				'html'    => $html,
				'id'      => $id,
				'caption' => $captured_caption,
				'title'   => $captured_title,
				'align'   => $captured_align,
				'url'     => $captured_url,
				'size'    => $captured_size,
				'alt'     => $captured_alt,
				'rel'     => $captured_rel,
			);

			return $html;
		};

		$disable_captions_filter = static function (): bool {
			return true;
		};

		$direct_filter_removed = false;
		\add_filter( 'image_downsize', $downsize_filter, 10, 3 );
		\add_filter( 'image_send_to_editor', $send_filter, 10, 9 );
		\add_filter( 'disable_captions', $disable_captions_filter );
		try {
			$direct_html = \get_image_send_to_editor( $attachment->ID, $caption, $title, $align, $url, $rel, $size, $alt );

			\remove_filter( 'image_send_to_editor', $send_filter, 10 );
			\remove_filter( 'disable_captions', $disable_captions_filter );
			$direct_filter_removed = false === \has_filter( 'image_send_to_editor', $send_filter )
				&& false === \has_filter( 'disable_captions', $disable_captions_filter );

			$default_rel_html = \get_image_send_to_editor(
				$attachment->ID,
				'',
				$title,
				'center',
				\wp_get_attachment_url( $attachment->ID ),
				true,
				$size,
				'Default rel alt <script>alert(1)</script>'
			);

			$no_rel_html = \get_image_send_to_editor(
				$attachment->ID,
				'',
				$title,
				'none',
				$no_rel_url,
				false,
				$size,
				$no_rel_alt
			);

			$integrated_caption_html = \get_image_send_to_editor(
				$attachment->ID,
				$integrated_caption,
				$title,
				'left',
				\wp_get_attachment_url( $attachment->ID ),
				true,
				$size,
				$integrated_alt
			);
		} finally {
			\remove_filter( 'image_downsize', $downsize_filter, 10 );
			\remove_filter( 'image_send_to_editor', $send_filter, 10 );
			\remove_filter( 'disable_captions', $disable_captions_filter );
		}

		self::collect_failure(
			$failures,
			is_string( $direct_html )
				&& str_contains( $direct_html, '<a href="' . \esc_url( $url ) . '"' )
				&& str_contains( $direct_html, ' rel="' . \esc_attr( $rel ) . '"' )
				&& str_contains( $direct_html, '<img ' )
				&& str_contains( $direct_html, 'src="' . \esc_url( $downsize_url ) . '"' )
				&& str_contains( $direct_html, 'alt="' . \esc_attr( $alt ) . '"' )
				&& str_contains( $direct_html, 'width="321" height="123"' )
				&& str_contains( $direct_html, 'align' . \esc_attr( $align ) )
				&& str_contains( $direct_html, 'size-' . \esc_attr( $size ) )
				&& self::html_has_no_raw_script( $direct_html )
				&& ! str_contains( strtolower( $direct_html ), 'javascript:' )
				&& ! str_contains( $direct_html, $alt )
				&& ! str_contains( $direct_html, $rel ),
			'get_image_send_to_editor() builds escaped linked image HTML from hostile generated attributes',
			array(
				'html'          => self::describe_string( $direct_html ),
				'downsizeCalls' => $downsize_calls,
			)
		);

		self::collect_failure(
			$failures,
			is_string( $default_rel_html )
				&& str_contains( $default_rel_html, 'rel="attachment wp-att-' . $attachment->ID . '"' )
				&& str_contains( $default_rel_html, 'alt="' . \esc_attr( 'Default rel alt <script>alert(1)</script>' ) . '"' )
				&& self::html_has_no_raw_script( $default_rel_html ),
			'get_image_send_to_editor() emits the default attachment rel when rel is true',
			array( 'html' => self::describe_string( $default_rel_html ) )
		);

		self::collect_failure(
			$failures,
			is_string( $no_rel_html )
				&& str_contains( $no_rel_html, 'href="' . \esc_url( $no_rel_url ) . '"' )
				&& str_contains( $no_rel_html, 'alt="' . \esc_attr( $no_rel_alt ) . '"' )
				&& ! str_contains( $no_rel_html, ' rel=' )
				&& ! str_contains( $no_rel_html, 'attachment wp-att-' )
				&& self::html_has_no_raw_script( $no_rel_html )
				&& ! str_contains( strtolower( $no_rel_html ), 'javascript:' ),
			'get_image_send_to_editor() omits rel for non-attachment URLs when rel is false',
			array( 'html' => self::describe_string( $no_rel_html ) )
		);

		self::collect_failure(
			$failures,
			20 === \has_filter( 'image_send_to_editor', 'image_add_caption' )
				&& is_string( $integrated_caption_html )
				&& str_starts_with( $integrated_caption_html, '[caption id="attachment_' . $attachment->ID . '" align="alignleft" width="321"]' )
				&& str_contains( $integrated_caption_html, 'href="' . \esc_url( \wp_get_attachment_url( $attachment->ID ) ) . '"' )
				&& str_contains( $integrated_caption_html, 'rel="attachment wp-att-' . $attachment->ID . '"' )
				&& str_contains( $integrated_caption_html, 'alt="' . \esc_attr( $integrated_alt ) . '"' )
				&& ! str_contains( $integrated_caption_html, 'class="alignleft' )
				&& str_contains( $integrated_caption_html, 'Integrated caption<br />Second line[/caption]' )
				&& self::html_has_no_raw_script( $integrated_caption_html ),
			'get_image_send_to_editor() runs through the default caption shortcode filter when captions are enabled',
			array( 'html' => self::describe_string( $integrated_caption_html ) )
		);

		self::collect_failure(
			$failures,
			1 === count( $send_events )
				&& $direct_filter_removed
				&& false === \has_filter( 'image_downsize', $downsize_filter )
				&& (int) $attachment->ID === (int) ( $send_events[0]['id'] ?? 0 )
				&& $caption === ( $send_events[0]['caption'] ?? null )
				&& $title === ( $send_events[0]['title'] ?? null )
				&& $align === ( $send_events[0]['align'] ?? null )
				&& $url === ( $send_events[0]['url'] ?? null )
				&& $size === ( $send_events[0]['size'] ?? null )
				&& $alt === ( $send_events[0]['alt'] ?? null )
				&& ' rel="' . \esc_attr( $rel ) . '"' === ( $send_events[0]['rel'] ?? null ),
			'image_send_to_editor filter captures the direct helper payload shape and is removed afterward',
			array(
				'events'              => $send_events,
				'directFilterRemoved' => $direct_filter_removed,
			)
		);

		$caption_html   = '<img src="' . \esc_url( $downsize_url ) . '" alt="' . \esc_attr( $alt ) . '" width="456" height="321" class="alignright size-medium wp-image-' . $attachment->ID . ' component-fuzz" />';
		$caption_text   = "First <em class=\"cfz\"\n data-x=\"1\">tag</em>\r\nSecond line\nThird <abbr title=\"component\"\n data-y=\"2\">abbr</abbr>";
		$caption_events = array();

		$caption_text_filter = static function ( string $filtered_caption, int $id ) use ( &$caption_events ): string {
			$caption_events[] = array(
				'filter'  => 'image_add_caption_text',
				'id'      => $id,
				'caption' => $filtered_caption,
			);

			return $filtered_caption . "\nFiltered <span class=\"cfz-caption\"\n data-filter=\"1\">tag</span>";
		};

		$caption_shortcode_filter = static function ( string $shortcode, string $html ) use ( &$caption_events ): string {
			$caption_events[] = array(
				'filter'    => 'image_add_caption_shortcode',
				'shortcode' => $shortcode,
				'html'      => $html,
			);

			return $shortcode . '<!--cfz-caption-shortcode-->';
		};

		\add_filter( 'image_add_caption_text', $caption_text_filter, 10, 2 );
		\add_filter( 'image_add_caption_shortcode', $caption_shortcode_filter, 10, 2 );
		try {
			$captioned_html = \image_add_caption( $caption_html, $attachment->ID, $caption_text, $title, 'right', $url, 'medium', $alt );
		} finally {
			\remove_filter( 'image_add_caption_text', $caption_text_filter, 10 );
			\remove_filter( 'image_add_caption_shortcode', $caption_shortcode_filter, 10 );
		}

		self::collect_failure(
			$failures,
			is_string( $captioned_html )
				&& str_starts_with( $captioned_html, '[caption id="attachment_' . $attachment->ID . '" align="alignright" width="456"]' )
				&& str_contains( $captioned_html, 'class="size-medium wp-image-' . $attachment->ID . ' component-fuzz"' )
				&& ! str_contains( $captioned_html, 'class="alignright' )
				&& str_contains( $captioned_html, '<em class="cfz"  data-x="1">tag</em><br />Second line<br />Third <abbr title="component"  data-y="2">abbr</abbr><br />Filtered <span class="cfz-caption"  data-filter="1">tag</span>' )
				&& str_ends_with( $captioned_html, '<!--cfz-caption-shortcode-->' )
				&& self::html_has_no_raw_script( $captioned_html ),
			'image_add_caption() extracts width, strips image align class, and normalizes caption tag and line breaks',
			array(
				'html'   => self::describe_string( $captioned_html ),
				'events' => $caption_events,
			)
		);

		self::collect_failure(
			$failures,
			array( 'image_add_caption_text', 'image_add_caption_shortcode' ) === array_column( $caption_events, 'filter' )
				&& (int) $attachment->ID === (int) ( $caption_events[0]['id'] ?? 0 )
				&& isset( $caption_events[1]['html'] )
				&& ! str_contains( (string) $caption_events[1]['html'], 'class="alignright' )
				&& false === \has_filter( 'image_add_caption_text', $caption_text_filter )
				&& false === \has_filter( 'image_add_caption_shortcode', $caption_shortcode_filter ),
			'image_add_caption_text and image_add_caption_shortcode filters fire in order and remain local',
			array( 'events' => $caption_events )
		);

		$early_shortcode_events = array();
		$early_shortcode_filter = static function ( string $shortcode, string $html ) use ( &$early_shortcode_events ): string {
			$early_shortcode_events[] = array(
				'shortcode' => $shortcode,
				'html'      => $html,
			);

			return $shortcode;
		};

		$disabled_events         = array();
		$disable_captions_filter = static function ( $disabled ) use ( &$disabled_events ): bool {
			$disabled_events[] = $disabled;
			return true;
		};

		\add_filter( 'disable_captions', $disable_captions_filter );
		\add_filter( 'image_add_caption_shortcode', $early_shortcode_filter, 10, 2 );
		try {
			$disabled_caption_html = \image_add_caption( $caption_html, $attachment->ID, 'Disabled caption', $title, 'right', $url, 'medium', $alt );
		} finally {
			\remove_filter( 'disable_captions', $disable_captions_filter );
			\remove_filter( 'image_add_caption_shortcode', $early_shortcode_filter, 10 );
		}

		$empty_text_events      = array();
		$empty_disable_events   = array();
		$empty_text_filter     = static function ( string $filtered_caption, int $id ) use ( &$empty_text_events ): string {
			$empty_text_events[] = array(
				'id'      => $id,
				'caption' => $filtered_caption,
			);

			return '';
		};
		$empty_disable_filter  = static function ( $disabled ) use ( &$empty_disable_events ): bool {
			$empty_disable_events[] = $disabled;
			return false;
		};

		\add_filter( 'image_add_caption_text', $empty_text_filter, 10, 2 );
		\add_filter( 'disable_captions', $empty_disable_filter );
		\add_filter( 'image_add_caption_shortcode', $early_shortcode_filter, 10, 2 );
		try {
			$empty_caption_html = \image_add_caption( $caption_html, $attachment->ID, 'Will be emptied', $title, 'right', $url, 'medium', $alt );
		} finally {
			\remove_filter( 'image_add_caption_text', $empty_text_filter, 10 );
			\remove_filter( 'disable_captions', $empty_disable_filter );
			\remove_filter( 'image_add_caption_shortcode', $early_shortcode_filter, 10 );
		}

		self::collect_failure(
			$failures,
			$caption_html === $disabled_caption_html
				&& $caption_html === $empty_caption_html
				&& array( '' ) === $disabled_events
				&& 1 === count( $empty_text_events )
				&& array() === $empty_disable_events
				&& array() === $early_shortcode_events
				&& false === \has_filter( 'disable_captions', $disable_captions_filter )
				&& false === \has_filter( 'image_add_caption_text', $empty_text_filter )
				&& false === \has_filter( 'disable_captions', $empty_disable_filter )
				&& false === \has_filter( 'image_add_caption_shortcode', $early_shortcode_filter ),
			'image_add_caption() returns original HTML for disabled or empty captions before shortcode filters',
			array(
				'disabledEvents'       => $disabled_events,
				'emptyTextEvents'      => $empty_text_events,
				'emptyDisableEvents'   => $empty_disable_events,
				'earlyShortcodeEvents' => $early_shortcode_events,
			)
		);

		$media_events       = array();
		$media_send_filter = static function ( string $html, int $id, string $captured_caption, string $captured_title, string $captured_align, string $captured_url, $captured_size, string $captured_alt, string $captured_rel ) use ( &$media_events ): string {
			$media_events[] = array(
				'html'    => $html,
				'id'      => $id,
				'caption' => $captured_caption,
				'title'   => $captured_title,
				'align'   => $captured_align,
				'url'     => $captured_url,
				'size'    => $captured_size,
				'alt'     => $captured_alt,
				'rel'     => $captured_rel,
			);

			return $html;
		};

		$media_permalink_url = \get_attachment_link( $attachment->ID );
		$media_query_url     = \add_query_arg( 'attachment_id', (string) $attachment->ID, 'http://example.test/component-fuzz/media-send' );
		$media_plain_url     = 'http://example.test/component-fuzz/media-send-plain?raw=' . rawurlencode( '<script>alert(1)</script>' );
		$media_alt           = 'Media alt <script>alert(1)</script> "' . $ctx->identifier( 3, 8 );
		$media_payload       = array(
			'url'          => $media_permalink_url,
			'align'        => 'left',
			'image-size'   => 'medium',
			'image_alt'    => $media_alt,
			'post_excerpt' => $attachment->post_excerpt,
			'post_title'   => $attachment->post_title,
		);
		$media_query_payload = array_merge(
			$media_payload,
			array(
				'url'        => $media_query_url,
				'align'      => 'center',
				'image-size' => 'thumbnail',
			)
		);
		$media_plain_payload = array_merge(
			$media_payload,
			array(
				'url'        => $media_plain_url,
				'align'      => 'none',
				'image-size' => 'medium',
			)
		);
		$unchanged_document_html = '<span class="component-fuzz-document">Document HTML</span>';
		$media_caption_payload  = array_merge(
			$media_payload,
			array(
				'align'        => 'right',
				'image-size'   => 'medium',
				'image_alt'    => 'Media caption alt <script>alert(1)</script> "' . $ctx->identifier( 3, 8 ),
				'post_excerpt' => "Media integrated caption\nSecond line",
				'post_title'   => 'Media integrated title',
				'url'          => $media_permalink_url,
			)
		);
		$media_caption_html     = \image_media_send_to_editor( '<span>input</span>', $attachment->ID, $media_caption_payload );

		\add_filter( 'image_send_to_editor', $media_send_filter, 10, 9 );
		\add_filter( 'disable_captions', $disable_captions_filter );
		try {
			$media_permalink_html = \image_media_send_to_editor( '<span>input</span>', $attachment->ID, $media_payload );
			$media_query_html     = \image_media_send_to_editor( '<span>input</span>', $attachment->ID, $media_query_payload );
			$media_plain_html     = \image_media_send_to_editor( '<span>input</span>', $attachment->ID, $media_plain_payload );
			$document_html        = \image_media_send_to_editor( $unchanged_document_html, $document->ID, array( 'url' => \wp_get_attachment_url( $document->ID ) ) );
		} finally {
			\remove_filter( 'image_send_to_editor', $media_send_filter, 10 );
			\remove_filter( 'disable_captions', $disable_captions_filter );
		}

		self::collect_failure(
			$failures,
			is_string( $media_permalink_html )
				&& is_string( $media_query_html )
				&& is_string( $media_plain_html )
				&& str_contains( $media_permalink_html, 'href="' . \esc_url( $media_permalink_url ) . '"' )
				&& str_contains( $media_query_html, 'href="' . \esc_url( $media_query_url ) . '"' )
				&& str_contains( $media_plain_html, 'href="' . \esc_url( $media_plain_url ) . '"' )
				&& str_contains( $media_permalink_html, 'rel="attachment wp-att-' . $attachment->ID . '"' )
				&& str_contains( $media_query_html, 'rel="attachment wp-att-' . $attachment->ID . '"' )
				&& ! str_contains( $media_plain_html, ' rel=' )
				&& str_contains( $media_permalink_html, 'alt="' . \esc_attr( $media_alt ) . '"' )
				&& str_contains( $media_query_html, 'alt="' . \esc_attr( $media_alt ) . '"' )
				&& str_contains( $media_plain_html, 'alt="' . \esc_attr( $media_alt ) . '"' )
				&& self::html_has_no_raw_script( $media_permalink_html . $media_query_html . $media_plain_html )
				&& $unchanged_document_html === $document_html,
			'image_media_send_to_editor() delegates image attachments and leaves non-image HTML unchanged',
			array(
				'permalinkHtml' => self::describe_string( $media_permalink_html ),
				'queryHtml'     => self::describe_string( $media_query_html ),
				'plainHtml'     => self::describe_string( $media_plain_html ),
				'documentHtml'  => self::describe_string( $document_html ),
			)
		);

		self::collect_failure(
			$failures,
			20 === \has_filter( 'image_send_to_editor', 'image_add_caption' )
				&& is_string( $media_caption_html )
				&& str_starts_with( $media_caption_html, '[caption id="attachment_' . $attachment->ID . '" align="alignright" width="300"]' )
				&& str_contains( $media_caption_html, 'href="' . \esc_url( $media_permalink_url ) . '"' )
				&& str_contains( $media_caption_html, 'rel="attachment wp-att-' . $attachment->ID . '"' )
				&& str_contains( $media_caption_html, 'alt="' . \esc_attr( $media_caption_payload['image_alt'] ) . '"' )
				&& str_contains( $media_caption_html, 'Media integrated caption<br />Second line[/caption]' )
				&& self::html_has_no_raw_script( $media_caption_html ),
			'image_media_send_to_editor() observes the default image_send_to_editor caption wrapping path',
			array( 'html' => self::describe_string( $media_caption_html ) )
		);

		self::collect_failure(
			$failures,
			3 === count( $media_events )
				&& (int) $attachment->ID === (int) ( $media_events[0]['id'] ?? 0 )
				&& (int) $attachment->ID === (int) ( $media_events[1]['id'] ?? 0 )
				&& (int) $attachment->ID === (int) ( $media_events[2]['id'] ?? 0 )
				&& $attachment->post_excerpt === ( $media_events[0]['caption'] ?? null )
				&& $attachment->post_title === ( $media_events[0]['title'] ?? null )
				&& 'left' === ( $media_events[0]['align'] ?? null )
				&& 'medium' === ( $media_events[0]['size'] ?? null )
				&& $media_permalink_url === ( $media_events[0]['url'] ?? null )
				&& $media_alt === ( $media_events[0]['alt'] ?? null )
				&& 'center' === ( $media_events[1]['align'] ?? null )
				&& 'thumbnail' === ( $media_events[1]['size'] ?? null )
				&& $media_query_url === ( $media_events[1]['url'] ?? null )
				&& 'none' === ( $media_events[2]['align'] ?? null )
				&& 'medium' === ( $media_events[2]['size'] ?? null )
				&& $media_plain_url === ( $media_events[2]['url'] ?? null )
				&& ' rel="attachment wp-att-' . $attachment->ID . '"' === ( $media_events[0]['rel'] ?? null )
				&& ' rel="attachment wp-att-' . $attachment->ID . '"' === ( $media_events[1]['rel'] ?? null )
				&& '' === ( $media_events[2]['rel'] ?? null )
				&& false === \has_filter( 'image_send_to_editor', $media_send_filter )
				&& false === \has_filter( 'disable_captions', $disable_captions_filter ),
			'image_media_send_to_editor() forwards attachment fields into get_image_send_to_editor() payloads with scoped filters',
			array( 'events' => $media_events )
		);

		self::restore_state( $filter_snapshot );

		return self::row(
			$ctx,
			'admin-media-chrome.image-caption-editor-output',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function check_media_button_and_bypass_output( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures  = array();
		$editor_id = 'content-" <script>alert(1)</script> ' . $ctx->identifier( 3, 8 );
		$events    = array();
		$months_filter = static function ( $months ) use ( &$events ): array {
			$events[] = $months;
			return array(
				(object) array(
					'month' => 6,
					'year'  => 2026,
				),
			);
		};

		\add_filter( 'media_library_months_with_files', $months_filter );
		try {
			$button = self::capture_output(
				static function () use ( $editor_id ): void {
					\media_buttons( $editor_id );
				}
			);
		} finally {
			\remove_filter( 'media_library_months_with_files', $months_filter );
		}

		$html_bypass = self::capture_output(
			static function (): void {
				\media_upload_html_bypass();
			}
		);
		$flash_bypass = self::capture_output(
			static function (): void {
				\media_upload_flash_bypass();
			}
		);

		self::collect_failure(
			$failures,
			str_contains( $button, 'class="button insert-media add_media"' )
				&& str_contains( $button, 'aria-controls="wp-media-modal"' )
				&& str_contains( $button, 'data-editor="' . \esc_attr( $editor_id ) . '"' )
				&& ! str_contains( $button, $editor_id )
				&& self::html_has_no_raw_script( $button )
				&& false === \has_filter( 'media_library_months_with_files', $months_filter ),
			'media_buttons() escapes the editor ID while rendering the Add Media button',
			array(
				'button' => self::describe_string( $button ),
				'events' => $events,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $html_bypass, 'upload-html-bypass' )
				&& str_contains( $html_bypass, 'type="button" class="button-link"' )
				&& str_contains( $flash_bypass, 'upload-flash-bypass' )
				&& str_contains( $flash_bypass, 'type="button" class="button-link"' )
				&& self::html_has_no_raw_script( $html_bypass . $flash_bypass ),
			'legacy uploader bypass helpers emit fixed button markup without upload dispatch',
			array(
				'htmlBypass'  => self::describe_string( $html_bypass ),
				'flashBypass' => self::describe_string( $flash_bypass ),
			)
		);

		return self::row(
			$ctx,
			'admin-media-chrome.media-buttons-bypass-output',
			array() === $failures,
			array( 'failures' => $failures )
		);
	}

	private static function skipped_exiting_upload_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		return $ctx->skip(
			'admin-media-chrome.upload-dispatch-exit-helpers-skipped',
			'Upload dispatch helpers and attach/detach actions are skipped in-process because selected branches call exit, wp_die(), redirects, or real upload handlers.',
			array(
				'skipped' => array(
					'media_upload_form_handler() send/insert-gallery branches',
					'media_upload_type_form() WP_Error branch',
					'wp_media_attach_action() redirect/exit branch',
					'media_handle_upload() and media_handle_sideload() real ingest paths',
				),
			)
		);
	}

	private static function skipped_modal_runtime_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		return $ctx->skip(
			'admin-media-chrome.browser-modal-runtime-skipped',
			'Media modal browser flows, AJAX dispatch wrappers, and script runtime behavior are skipped; this surface covers server-side helper output only.',
			array(
				'skipped' => array(
					'wp_enqueue_media() JavaScript runtime behavior beyond media_buttons() output',
					'admin-ajax.php image editor dispatch actions',
					'Thickbox iframe shell rendering through wp_iframe()',
					'full legacy gallery screen submission flows',
				),
			)
		);
	}

	private static function seed_attachment( \ComponentFuzz\FuzzContext $ctx, string $mime, array $args = array() ): \WP_Post {
		$extension = (string) ( $args['extension'] ?? self::extension_for_mime( $mime ) );
		$parent_id = isset( $args['parent_id'] ) ? (int) $args['parent_id'] : self::seed_parent_post( $ctx->fork( 'parent' ) );
		$title     = (string) ( $args['post_title'] ?? 'Component fuzz attachment ' . $ctx->identifier( 3, 8 ) );
		$excerpt   = (string) ( $args['post_excerpt'] ?? 'Component fuzz caption ' . $ctx->text( 0, 18 ) );
		$content   = (string) ( $args['post_content'] ?? 'Component fuzz description ' . $ctx->text( 0, 18 ) );

		$post_id = \wp_insert_post(
			array(
				'post_author'    => 0,
				'post_content'   => $content,
				'post_excerpt'   => $excerpt,
				'post_mime_type' => $mime,
				'post_name'      => 'component-fuzz-attachment-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ),
				'post_parent'    => $parent_id,
				'post_status'    => 'inherit',
				'post_title'     => $title,
				'post_type'      => 'attachment',
				'post_date'      => '2026-06-01 12:00:00',
				'post_date_gmt'  => '2026-06-01 12:00:00',
				'menu_order'     => $ctx->int( 0, 12 ),
				'guid'           => 'http://example.test/component-fuzz/' . rawurlencode( $title ) . '.' . $extension,
			),
			true
		);

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			throw new \RuntimeException( 'Could not seed attachment post.' );
		}

		$unsafe_suffix = ! empty( $args['unsafe_file'] ) ? '-unsafe-<script>alert(1)</script>' : '';
		$file_base     = 'component-fuzz-' . $post_id . $unsafe_suffix . '.' . $extension;
		$relative_file = '2026/06/' . $file_base;
		$width         = (int) ( $args['width'] ?? $ctx->int( 640, 1600 ) );
		$height        = (int) ( $args['height'] ?? $ctx->int( 480, 1200 ) );
		$metadata      = array(
			'width'     => max( 0, $width ),
			'height'    => max( 0, $height ),
			'file'      => $relative_file,
			'filesize'  => $ctx->int( 1024, 1048576 ),
			'sizes'     => array(),
			'image_meta' => array(
				'caption' => '',
				'credit'  => '',
				'created_timestamp' => 0,
				'copyright' => '',
				'title'   => '',
			),
		);

		if ( str_starts_with( $mime, 'image/' ) ) {
			$metadata['sizes'] = array(
				'thumbnail' => array(
					'file'      => 'component-fuzz-' . $post_id . '-150x150.' . $extension,
					'width'     => 150,
					'height'    => 150,
					'mime-type' => $mime,
					'filesize'  => 4096,
				),
				'medium'    => array(
					'file'      => 'component-fuzz-' . $post_id . '-300x225.' . $extension,
					'width'     => 300,
					'height'    => 225,
					'mime-type' => $mime,
					'filesize'  => 8192,
				),
			);
		}

		\update_post_meta( $post_id, '_wp_attached_file', $relative_file );
		\update_post_meta( $post_id, '_wp_attachment_metadata', $metadata );
		\update_post_meta(
			$post_id,
			'_wp_attachment_image_alt',
			(string) ( $args['alt'] ?? 'Alt text <script>alert(1)</script> "' . $ctx->identifier( 3, 8 ) )
		);

		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( 'Seeded attachment could not be read.' );
		}

		return $post;
	}

	private static function seed_parent_post( \ComponentFuzz\FuzzContext $ctx ): int {
		$post_id = \wp_insert_post(
			array(
				'post_content'  => 'Component fuzz parent content',
				'post_date'     => '2026-06-01 11:00:00',
				'post_date_gmt' => '2026-06-01 11:00:00',
				'post_name'     => 'component-fuzz-parent-' . substr( hash( 'crc32b', (string) $ctx->seed() ), 0, 8 ),
				'post_status'   => 'publish',
				'post_title'    => 'Component fuzz parent ' . $ctx->identifier( 3, 8 ),
				'post_type'     => 'post',
			),
			true
		);

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			throw new \RuntimeException( 'Could not seed parent post.' );
		}

		return $post_id;
	}

	private static function extension_for_mime( string $mime ): string {
		$map = array(
			'application/pdf' => 'pdf',
			'image/gif'       => 'gif',
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/webp'      => 'webp',
			'text/plain'      => 'txt',
		);

		return $map[ $mime ] ?? 'bin';
	}

	private static function prepare_runtime(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
			$GLOBALS['wpdb']->component_fuzz_reset_options(
				array(
					'admin_email'                    => 'admin@example.test',
					'blog_charset'                   => 'UTF-8',
					'blogname'                       => 'Component Fuzz',
					'default_category'               => 0,
					'default_comment_status'         => 'closed',
					'default_ping_status'            => 'closed',
					'gmt_offset'                     => 0,
					'home'                           => 'http://example.test',
					'image_default_align'            => 'none',
					'image_default_link_type'        => 'file',
					'image_default_size'             => 'medium',
					'large_size_h'                   => 1024,
					'large_size_w'                   => 1024,
					'medium_size_h'                  => 300,
					'medium_size_w'                  => 300,
					'permalink_structure'            => '',
					'siteurl'                        => 'http://example.test',
					'thumbnail_crop'                 => 1,
					'thumbnail_size_h'               => 150,
					'thumbnail_size_w'               => 150,
					'timezone_string'                => '',
					'upload_path'                    => '',
					'upload_url_path'                => '',
					'uploads_use_yearmonth_folders' => 1,
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		$GLOBALS['wp_post_types']   = array();
		$GLOBALS['wp_post_statuses'] = array();
		$GLOBALS['wp_taxonomies']   = array();
		$GLOBALS['post']            = null;
		$GLOBALS['post_ID']         = 0;
		$GLOBALS['pagenow']         = 'upload.php';
		$GLOBALS['wp_rewrite']      = new \WP_Rewrite();

		\create_initial_post_types();
		\create_initial_taxonomies();
		\wp_set_current_user( 0 );
	}

	private static function load_default_filters(): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) ) {
			$wpdb            = new \stdClass();
			$wpdb->charset   = 'utf8mb4';
			$GLOBALS['wpdb'] = $wpdb;
		}

		require \ComponentFuzz\repo_root() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR . 'default-filters.php';
	}

	private static function cleanup_runtime(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	private static function capture_output( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}

		return (string) ob_get_clean();
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details = array() ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => self::describe_value( $details ),
		);
	}

	private static function html_has_no_raw_script( string $html ): bool {
		return ! str_contains( strtolower( $html ), '<script' );
	}

	private static function row(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		bool $ok,
		array $data = array(),
		?string $status = null
	): array {
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

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
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

	private static function snapshot_state(): array {
		$snapshot = array();
		foreach (
			array(
				'_GET',
				'_POST',
				'_REQUEST',
				'pagenow',
				'post',
				'post_ID',
				'redir_tab',
				'tab',
				'type',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_post_statuses',
				'wp_post_types',
				'wp_rewrite',
				'wp_scripts',
				'wp_styles',
				'wp_taxonomies',
			) as $name
		) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
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

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function load_editor_class(): void {
		if ( ! class_exists( '_WP_Editors', false ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-editor.php';
		}
	}

	private static function snapshot_editor_statics(): array {
		$statics = array();
		foreach ( self::editor_static_property_names() as $property ) {
			$statics[ $property ] = self::clone_value( self::get_editor_static_property( $property ) );
		}

		return $statics;
	}

	private static function restore_editor_statics( array $snapshot ): void {
		foreach ( $snapshot as $property => $value ) {
			self::set_editor_static_property( (string) $property, $value );
		}
	}

	private static function editor_static_property_names(): array {
		return array(
			'mce_locale',
			'mce_settings',
			'qt_settings',
			'plugins',
			'qt_buttons',
			'ext_plugins',
			'baseurl',
			'first_init',
			'this_tinymce',
			'this_quicktags',
			'has_tinymce',
			'has_quicktags',
			'has_medialib',
			'editor_buttons_css',
			'drag_drop_upload',
			'translation',
			'tinymce_scripts_printed',
			'link_dialog_printed',
		);
	}

	private static function get_editor_static_property( string $property ) {
		$reflection = new \ReflectionProperty( '_WP_Editors', $property );
		return $reflection->getValue();
	}

	private static function set_editor_static_property( string $property, $value ): void {
		$reflection = new \ReflectionProperty( '_WP_Editors', $property );
		$reflection->setValue( null, $value );
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
